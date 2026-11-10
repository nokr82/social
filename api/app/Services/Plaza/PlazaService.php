<?php

namespace App\Services\Plaza;

use App\Events\PlazaMessageSent;
use App\Exceptions\DomainException;
use App\Models\Avatar;
use App\Models\ChatMessage;
use App\Models\ChatRoom;
use App\Models\Plaza;
use App\Models\User;
use App\Support\Geo;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class PlazaService
{
    public function __construct(private readonly PlazaPresence $presence) {}

    /**
     * 내 주변 광장 (가까운 순).
     * 1) 바운딩 박스로 DB에서 후보를 좁히고 (인덱스 사용)
     * 2) PHP에서 정확한 거리로 반경 밖을 버리고 정렬
     * 3) 차단 관계인 방장의 광장은 제외
     *
     * 규모가 커지면(반경 안 광장 수천 개) Redis GEO(GEOSEARCH)로 교체 — 인터페이스는 그대로.
     *
     * @param  string  $sort  near(가까운 순) | busy(북적이는 순) | new(새로 생긴)
     * @return Collection<int, Plaza> distance_m, live_count 속성이 붙은 광장들
     */
    public function nearby(User $viewer, float $lat, float $lng, ?string $category, ?string $q, string $sort = 'near'): Collection
    {
        $radius = (float) config('social.plaza.search_radius_m');
        [$minLat, $maxLat, $minLng, $maxLng] = Geo::boundingBox($lat, $lng, $radius);

        $query = Plaza::open()
            ->whereBetween('lat', [$minLat, $maxLat])
            ->whereBetween('lng', [$minLng, $maxLng])
            ->whereNotIn('host_id', $viewer->blockedUserIds())
            ->limit(500); // 안전장치: 한 번에 너무 많이 읽지 않기

        if ($category) {
            $query->where('category', $category);
        }
        if ($q !== null && $q !== '') {
            // '관심사로 찾기': 검색어가 카테고리 라벨과 같으면 카테고리로도 매칭
            $matchedCategory = array_search($q, config('social.categories'), true);
            $query->where(function ($w) use ($q, $matchedCategory) {
                $like = '%'.addcslashes($q, '%_\\').'%';
                $w->where('name', 'like', $like)->orWhere('description', 'like', $like);
                if ($matchedCategory !== false) {
                    $w->orWhere('category', $matchedCategory);
                }
            });
        }

        $plazas = $query->get()
            ->each(fn (Plaza $p) => $p->distance_m = Geo::distanceM($lat, $lng, $p->lat, $p->lng))
            ->filter(fn (Plaza $p) => $p->distance_m <= $radius)
            ->sortBy('distance_m')
            ->values();

        $plazas = $this->attachLive($plazas);

        // 정렬 기준이 같으면 가까운 순으로 (sortBy는 안정 정렬이라 앞의 거리순이 유지됨)
        return match ($sort) {
            'busy' => $plazas->sortByDesc('live_count')->values(),
            'new' => $plazas->sortByDesc(fn (Plaza $p) => $p->created_at?->getTimestamp())->values(),
            default => $plazas,
        };
    }

    /** '지금 제일 북적여요': 주변에서 접속자가 가장 많은 광장 (아무도 없으면 null) */
    public function hottest(Collection $nearby): ?Plaza
    {
        $top = $nearby->sortByDesc('live_count')->first();

        return $top && $top->live_count > 0 ? $top : null;
    }

    public function create(User $host, array $data, float $lat, float $lng): Plaza
    {
        if (($host->trustScore?->score ?? 0) < config('social.trust.min_score_to_host')) {
            throw new DomainException('LOW_TRUST', '매너점수가 낮아 지금은 광장을 열 수 없어요', 403);
        }
        $openCount = Plaza::open()->where('host_id', $host->id)->count();
        if ($openCount >= config('social.plaza.max_open_per_host')) {
            throw new DomainException('TOO_MANY_PLAZAS', '광장은 동시에 '.config('social.plaza.max_open_per_host').'개까지 열 수 있어요', 409);
        }

        // 실제 좌표 대신 geohash 7자리 칸의 중심값을 저장 → 방장 위치 노출 방지
        $cell = Geo::encode($lat, $lng, 7);
        [$cLat, $cLng] = Geo::center($cell);

        return DB::transaction(function () use ($host, $data, $cell, $cLat, $cLng) {
            $plaza = Plaza::create([
                'host_id' => $host->id,
                'name' => $data['name'],
                'description' => $data['description'] ?? null,
                'category' => $data['category'],
                'capacity' => $data['capacity'] ?? config('social.plaza.default_capacity'),
                'region_cell' => $cell,
                'lat' => $cLat,
                'lng' => $cLng,
            ]);
            ChatRoom::create(['type' => ChatRoom::TYPE_PLAZA, 'ref_id' => $plaza->id]);

            return $plaza;
        });
    }

    /** 입장: 정원·차단 검사 후 접속자 등록. 이미 들어와 있으면 heartbeat와 같음 */
    public function enter(User $user, Plaza $plaza): void
    {
        $this->assertOpen($plaza);

        if (in_array((int) $plaza->host_id, $user->blockedUserIds(), true)) {
            throw new DomainException('BLOCKED', '입장할 수 없는 광장이에요', 403);
        }
        if (! $this->presence->has($plaza->id, $user->id) && $this->presence->count($plaza->id) >= $plaza->capacity) {
            throw new DomainException('PLAZA_FULL', '광장 정원이 가득 찼어요', 409);
        }

        $this->presence->touch($plaza->id, $user->id);
    }

    public function heartbeat(User $user, Plaza $plaza): void
    {
        if (! $this->presence->has($plaza->id, $user->id)) {
            throw new DomainException('NOT_IN_PLAZA', '광장에 다시 입장해 주세요', 409);
        }
        $this->presence->touch($plaza->id, $user->id);
    }

    public function leave(User $user, Plaza $plaza): void
    {
        $this->presence->leave($plaza->id, $user->id);
    }

    /**
     * 메시지 전송: DB 저장이 먼저, 그다음 브로드캐스트.
     * 저장이 먼저라 웹소켓이 끊겨도 대화는 남고, 재접속 시 목록 API로 복구된다.
     */
    public function sendMessage(User $sender, Plaza $plaza, string $body): ChatMessage
    {
        $this->assertOpen($plaza);
        if (! $this->presence->has($plaza->id, $sender->id)) {
            throw new DomainException('NOT_IN_PLAZA', '광장에 입장한 뒤 대화할 수 있어요', 403);
        }

        $room = $plaza->chatRoom()->firstOrFail();
        $message = $room->messages()->create([
            'sender_id' => $sender->id,
            'body' => $body,
        ]);
        $plaza->forceFill([
            'last_message_at' => now(),
            'last_message_body' => mb_strimwidth($body, 0, 40, '…'), // 타일 말풍선용 미리보기
        ])->saveQuietly();
        $this->presence->touch($plaza->id, $sender->id); // 말하는 사람은 살아있는 것

        // toOthers: 보낸 사람 소켓에는 중복 전송하지 않음 (앱은 API 응답으로 즉시 표시)
        broadcast(new PlazaMessageSent($plaza->id, $message->load('sender.avatar')))->toOthers();

        return $message;
    }

    /**
     * 메시지 목록 (최신순, id 커서). 나와 차단 관계인 사람의 메시지는 숨김.
     *
     * @return Collection<int, ChatMessage>
     */
    public function messages(User $viewer, Plaza $plaza, ?int $beforeId, int $limit = 50): Collection
    {
        $room = $plaza->chatRoom()->firstOrFail();

        return $room->messages()
            ->with('sender.avatar')
            ->whereNotIn('sender_id', $viewer->blockedUserIds())
            ->when($beforeId, fn ($q) => $q->where('id', '<', $beforeId))
            ->orderByDesc('id')
            ->limit($limit)
            ->get();
    }

    /**
     * 목록 카드용: 접속자 수 + 미리보기 아바타를 붙인다.
     * 접속자 수는 파이프라인 1회, 아바타는 쿼리 1회로 N+1 없이 처리.
     */
    public function attachLive(Collection $plazas): Collection
    {
        $counts = $this->presence->counts($plazas->pluck('id')->all());
        $limit = (int) config('social.plaza.preview_avatars');

        $memberIds = [];
        foreach ($plazas as $p) {
            $p->live_count = $counts[$p->id] ?? 0;
            $memberIds[$p->id] = $p->live_count > 0 ? $this->presence->members($p->id, $limit) : [];
        }

        $avatars = Avatar::whereIn('user_id', array_merge([], ...array_values($memberIds)))->get()->keyBy('user_id');
        foreach ($plazas as $p) {
            $p->preview_avatars = collect($memberIds[$p->id])
                ->map(fn ($uid) => $avatars->get($uid)?->parts)
                ->filter()->values()->all();
        }

        return $plazas;
    }

    private function assertOpen(Plaza $plaza): void
    {
        if ($plaza->status !== Plaza::STATUS_OPEN) {
            throw new DomainException('PLAZA_CLOSED', '닫힌 광장이에요', 410);
        }
    }
}
