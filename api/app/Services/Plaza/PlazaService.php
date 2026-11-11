<?php

namespace App\Services\Plaza;

use App\Events\PlazaClosed;
use App\Events\PlazaHostChanged;
use App\Events\PlazaMemberKicked;
use App\Events\PlazaMessageSent;
use App\Exceptions\DomainException;
use App\Http\Resources\AvatarSummary;
use App\Models\Avatar;
use App\Models\ChatMessage;
use App\Models\ChatRoom;
use App\Models\Plaza;
use App\Models\PlazaKick;
use App\Models\User;
use App\Support\Geo;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
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

        $query = Plaza::open()->userMade() // 상시 광장은 메인 상단 카드로 따로 보여줌
            ->whereBetween('lat', [$minLat, $maxLat])
            ->whereBetween('lng', [$minLng, $maxLng])
            ->whereNotIn('host_id', $viewer->blockedUserIds())
            // 내가 강퇴당한 광장은 기간 동안 목록에서도 숨김
            ->whereNotIn('id', PlazaKick::active()->where('user_id', $viewer->id)->select('plaza_id'))
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

        // 방장이 떠났고 아무도 없는 광장은 곧 닫힐 방 → 정리 명령(1분 주기)을 기다리지 않고 바로 숨김.
        // 사람이 남아 있으면 위임될 수 있으니 그대로 보여준다.
        $plazas = $plazas->reject(fn (Plaza $p) => $p->live_count === 0 && $p->isHostGone())->values();

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
        $openCount = Plaza::alive()->where('host_id', $host->id)->count();
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
                'host_seen_at' => now(),
            ]);
            ChatRoom::create(['type' => ChatRoom::TYPE_PLAZA, 'ref_id' => $plaza->id]);

            return $plaza;
        });
    }

    /** 입장: 정원·차단 검사 후 접속자 등록. 이미 들어와 있으면 heartbeat와 같음 */
    public function enter(User $user, Plaza $plaza): void
    {
        $this->assertOpen($plaza, $user);

        if (in_array((int) $plaza->host_id, $user->blockedUserIds(), true)) {
            throw new DomainException('BLOCKED', '입장할 수 없는 광장이에요', 403);
        }
        if (PlazaKick::active()->where('plaza_id', $plaza->id)->where('user_id', $user->id)->exists()) {
            throw new DomainException('KICKED', '방장이 내보낸 광장이에요. 방장이 해제하면 다시 들어올 수 있어요', 403);
        }
        if (! $this->presence->has($plaza->id, $user->id) && $this->presence->count($plaza->id) >= $plaza->capacity) {
            throw new DomainException('PLAZA_FULL', '광장 정원이 가득 찼어요', 409);
        }

        $this->presence->touch($plaza->id, $user->id);
        $this->touchHost($user, $plaza);
    }

    public function heartbeat(User $user, Plaza $plaza): void
    {
        $this->assertOpen($plaza, $user); // 닫힌 광장이면 410 → 앱이 목록으로 나감
        if (! $this->presence->has($plaza->id, $user->id)) {
            throw new DomainException('NOT_IN_PLAZA', '광장에 다시 입장해 주세요', 409);
        }
        $this->presence->touch($plaza->id, $user->id);
        $this->touchHost($user, $plaza);
    }

    /**
     * 퇴장. 방장이 나가도 바로 처리하지 않는다:
     * 앱은 프로필·번개 화면으로 이동할 때도 leave를 보내므로, host_grace 안에 돌아오면 그대로 유지.
     * 안 돌아오면 handleHostGone()이 자격 있는 접속자에게 넘기거나, 없으면 닫는다.
     */
    public function leave(User $user, Plaza $plaza): void
    {
        $this->presence->leave($plaza->id, $user->id);
        $this->touchHost($user, $plaza); // 나간 시각부터 유예시간을 센다
    }

    /**
     * 방장이 host_grace 넘게 없을 때: 자격 있는 접속자에게 방장을 넘기고, 없으면 닫는다.
     *
     * 동시성: 정리 명령과 손님 요청(입장·heartbeat)이 같은 광장을 동시에 처리할 수 있다.
     * 두 UPDATE 모두 "아직 열려 있고, 방장이 그대로이고, 여전히 부재 중"일 때만 적용되도록
     * 조건부 UPDATE로 선점한다 → 위임·닫기·알림이 한 번만 일어난다.
     *
     * @return string transferred | closed | kept(이미 다른 쪽이 처리했거나 처리할 필요 없음)
     */
    public function handleHostGone(Plaza $plaza): string
    {
        if ($plaza->status !== Plaza::STATUS_OPEN || ! $plaza->isHostGone()) {
            return 'kept';
        }
        $oldHostId = (int) $plaza->host_id;
        $guard = fn () => Plaza::whereKey($plaza->id)->hostGone()->where('host_id', $oldHostId);

        $next = $this->pickNextHost($plaza);
        if ($next) {
            if (! $guard()->update(['host_id' => $next->id, 'host_seen_at' => now(), 'updated_at' => now()])) {
                $plaza->refresh(); // 다른 쪽이 먼저 처리함

                return 'kept';
            }
            $plaza->forceFill(['host_id' => $next->id, 'host_seen_at' => now()])->syncOriginal();
            broadcast(new PlazaHostChanged($plaza->id, AvatarSummary::of($next->loadMissing('avatar'))));

            return 'transferred';
        }

        if (! $guard()->update($this->closedColumns('host_left'))) {
            $plaza->refresh();

            return 'kept';
        }
        $plaza->forceFill($this->closedColumns('host_left'))->syncOriginal();
        broadcast(new PlazaClosed($plaza->id, 'host_left'));

        return 'closed';
    }

    /* ---------------- 방장 권한 · 관리자 ---------------- */

    /**
     * 광장 닫기 (방장 직접 닫기 host_closed · 관리자 admin).
     * 열려 있을 때만 닫는 조건부 UPDATE → 여러 번 눌러도 한 번만 닫고 한 번만 알림.
     *
     * @return bool 이번 호출이 닫았으면 true
     */
    public function close(Plaza $plaza, string $reason): bool
    {
        if ($plaza->isTown()) {
            return false; // 상시 광장은 닫지 않음 (동네당 1개라 닫으면 그 동네 광장이 사라짐)
        }
        $closed = Plaza::whereKey($plaza->id)->where('status', Plaza::STATUS_OPEN)->update($this->closedColumns($reason));
        $plaza->refresh();
        if ($closed) {
            broadcast(new PlazaClosed($plaza->id, $reason));
        }

        return (bool) $closed;
    }

    /** POST /plazas/{id}/close — 방장만 */
    public function closeByHost(User $host, Plaza $plaza): void
    {
        $this->assertOpen($plaza, $host);
        $this->assertHost($host, $plaza);
        $this->close($plaza, 'host_closed');
    }

    /**
     * 방장 직접 넘기기. 받는 사람은 지금 광장에 있고 자동 위임과 같은 자격을 갖춰야 한다.
     * 조건부 UPDATE(host_id가 아직 나일 때만)로 동시에 두 번 넘기는 것을 막는다.
     */
    public function transferHost(User $host, Plaza $plaza, User $target): void
    {
        $this->assertOpen($plaza, $host);
        $this->assertHost($host, $plaza);
        if ($target->id === $host->id) {
            throw new DomainException('INVALID_TARGET', '이미 방장이에요', 422);
        }
        if (! $this->presence->has($plaza->id, $target->id)) {
            throw new DomainException('NOT_IN_PLAZA', '광장에 있는 사람에게만 넘길 수 있어요', 409);
        }
        if (! $this->eligibleHosts(collect([$target]))->first()) {
            throw new DomainException('NOT_ELIGIBLE', '매너점수가 기준에 못 미치거나 이미 광장을 여러 개 운영 중이라 넘길 수 없어요', 409);
        }

        $moved = Plaza::whereKey($plaza->id)->where('status', Plaza::STATUS_OPEN)->where('host_id', $host->id)
            ->update(['host_id' => $target->id, 'host_seen_at' => now(), 'updated_at' => now()]);
        if (! $moved) {
            throw new DomainException('NOT_HOST', '방장만 할 수 있어요', 403);
        }
        $plaza->forceFill(['host_id' => $target->id, 'host_seen_at' => now()])->syncOriginal();
        broadcast(new PlazaHostChanged($plaza->id, AvatarSummary::of($target->loadMissing('avatar'))));
    }

    /**
     * 강퇴: 방장이 해제하기 전까지 재입장 불가 + 지금 접속에서 내보냄.
     * 실수로 눌렀으면 강퇴 목록(kicks)에서 revokeKick으로 해제.
     * 남용 방지로 광장당 24시간 kick_daily_limit명까지 (해제한 기록도 셈 → 넣었다 뺐다 반복 방지).
     */
    public function kick(User $host, Plaza $plaza, User $target, string $reason): PlazaKick
    {
        $this->assertOpen($plaza, $host);
        $this->assertHost($host, $plaza);
        if ($target->id === $host->id) {
            throw new DomainException('INVALID_TARGET', '나 자신은 내보낼 수 없어요', 422);
        }
        if (PlazaKick::active()->where('plaza_id', $plaza->id)->where('user_id', $target->id)->exists()) {
            throw new DomainException('ALREADY_KICKED', '이미 내보낸 사람이에요', 409);
        }
        $limit = (int) config('social.plaza.kick_daily_limit');
        $recent = PlazaKick::where('plaza_id', $plaza->id)->where('created_at', '>', now()->subDay())->count();
        if ($recent >= $limit) {
            throw new DomainException('KICK_LIMIT', "광장 하나에서 하루 {$limit}명까지 내보낼 수 있어요. 심한 경우 신고해 주세요", 429);
        }

        $kick = PlazaKick::create([
            'plaza_id' => $plaza->id,
            'user_id' => $target->id,
            'kicked_by' => $host->id,
            'reason' => $reason,
        ]);
        $this->presence->leave($plaza->id, $target->id); // 다음 heartbeat부터 NOT_IN_PLAZA → 재입장은 KICKED
        broadcast(new PlazaMemberKicked($plaza->id, $target->id));

        return $kick;
    }

    /**
     * 강퇴 목록 (해제 안 된 것, 최근 순). 지금 방장만 볼 수 있고, 방장이 바뀌면 새 방장이 이어서 관리.
     *
     * @return Collection<int, PlazaKick>
     */
    public function kicks(User $host, Plaza $plaza): Collection
    {
        $this->assertOpen($plaza, $host);
        $this->assertHost($host, $plaza);

        return PlazaKick::active()->where('plaza_id', $plaza->id)
            ->with('user.avatar')->orderByDesc('id')->get();
    }

    /** 강퇴 해제 → 다시 입장 가능. 기록은 남기고 해제 시각·해제한 방장만 기록 */
    public function revokeKick(User $host, Plaza $plaza, PlazaKick $kick): void
    {
        $this->assertOpen($plaza, $host);
        $this->assertHost($host, $plaza);
        if ((int) $kick->plaza_id !== (int) $plaza->id) {
            throw new DomainException('NOT_FOUND', '이 광장의 강퇴 기록이 아니에요', 404);
        }
        // 조건부 UPDATE: 두 번 눌러도 한 번만 해제
        $revoked = PlazaKick::whereKey($kick->id)->active()->update(['revoked_at' => now(), 'revoked_by' => $host->id]);
        if (! $revoked) {
            throw new DomainException('ALREADY_REVOKED', '이미 해제했어요', 409);
        }
    }

    /** 상시 광장은 구역·채널 구조라 TownService API로만 들어간다 */
    private function assertUserMade(Plaza $plaza): void
    {
        if ($plaza->isTown()) {
            throw new DomainException('TOWN_PLAZA', '상시 광장은 메인의 동네 광장에서 들어갈 수 있어요', 409);
        }
    }

    private function assertHost(User $user, Plaza $plaza): void
    {
        if ((int) $user->id !== (int) $plaza->host_id) {
            throw new DomainException('NOT_HOST', '방장만 할 수 있어요', 403);
        }
    }

    /** @return array<string, mixed> 닫힘 상태로 바꿀 컬럼 */
    private function closedColumns(string $reason): array
    {
        return ['status' => Plaza::STATUS_CLOSED, 'closed_at' => now(), 'closed_reason' => $reason, 'updated_at' => now()];
    }

    /**
     * 새 방장 후보: 지금 광장에 있는 사람 중
     *  - 정상 계정 (정지·탈퇴 아님)
     *  - 매너점수 ≥ min_score_to_host (광장을 새로 열 수 있는 사람과 같은 기준)
     *  - 이미 max_open_per_host개를 운영 중이 아님
     * 중 매너점수가 가장 높은 사람 (같으면 가입이 빠른 사람 = id 작은 순).
     */
    private function pickNextHost(Plaza $plaza): ?User
    {
        $ids = array_values(array_diff(
            $this->presence->members($plaza->id, (int) $plaza->capacity),
            [(int) $plaza->host_id],
        ));

        return $ids ? $this->eligibleHosts(User::whereIn('id', $ids)->get())->first() : null;
    }

    /**
     * 방장 자격이 있는 사람만 남겨 매너점수 높은 순(같으면 id 작은 순)으로 정렬.
     * 자동 위임과 직접 넘기기가 같은 기준을 쓰도록 한 곳에 둔다.
     *
     * @param  Collection<int, User>  $users
     * @return Collection<int, User>
     */
    private function eligibleHosts(Collection $users): Collection
    {
        $min = (int) config('social.trust.min_score_to_host');
        // 일반 Collection으로 들어와도 매너점수를 한 번에 불러오도록 Eloquent Collection으로 감쌈
        $candidates = EloquentCollection::make($users->all())->loadMissing('trustScore')
            ->filter(fn (User $u) => $u->isActive() && ($u->trustScore?->score ?? 0) >= $min)
            ->sort(fn (User $a, User $b) => [$b->trustScore->score, $a->id] <=> [$a->trustScore->score, $b->id])
            ->values();
        if ($candidates->isEmpty()) {
            return $candidates;
        }

        // 이미 광장을 최대로 운영 중인 사람은 제외 (쿼리 1번으로 개수 조회)
        $hosting = Plaza::alive()->whereIn('host_id', $candidates->pluck('id'))
            ->selectRaw('host_id, count(*) as cnt')->groupBy('host_id')->pluck('cnt', 'host_id');
        $max = (int) config('social.plaza.max_open_per_host');

        return $candidates->filter(fn (User $u) => (int) ($hosting[$u->id] ?? 0) < $max)->values();
    }

    /**
     * 메시지 전송: DB 저장이 먼저, 그다음 브로드캐스트.
     * 저장이 먼저라 웹소켓이 끊겨도 대화는 남고, 재접속 시 목록 API로 복구된다.
     */
    public function sendMessage(User $sender, Plaza $plaza, string $body): ChatMessage
    {
        $this->assertOpen($plaza, $sender);
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
        $this->touchHost($sender, $plaza);

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
        $this->assertUserMade($plaza);
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

    /**
     * 열린 광장인지 확인. 방장이 유예시간 넘게 없으면 정리 명령을 기다리지 않고 여기서 위임·닫기를 처리한다.
     * 단, 방장 본인이 돌아온 경우는 그대로 둔다 (유예시간 직후 재입장도 살려 줌).
     */
    private function assertOpen(Plaza $plaza, ?User $actor = null): void
    {
        $this->assertUserMade($plaza);
        $isHost = $actor && (int) $actor->id === (int) $plaza->host_id;

        if ($plaza->status === Plaza::STATUS_OPEN && ! $isHost && $plaza->isHostGone()) {
            $this->handleHostGone($plaza);
        }
        if ($plaza->status !== Plaza::STATUS_OPEN) {
            throw new DomainException('PLAZA_CLOSED', '닫힌 광장이에요', 410);
        }
    }

    /**
     * 방장이 광장에 있다는 표시. heartbeat(30초)마다 DB를 쓰지 않도록 20초 이내 갱신은 건너뜀.
     * (방장 1명당 최대 max_open_per_host개라 부하는 작다)
     */
    private function touchHost(User $user, Plaza $plaza): void
    {
        if ((int) $user->id !== (int) $plaza->host_id) {
            return;
        }
        if ($plaza->host_seen_at && $plaza->host_seen_at->gt(now()->subSeconds(20))) {
            return;
        }
        $plaza->forceFill(['host_seen_at' => now()])->saveQuietly();
    }
}
