<?php

namespace App\Services\Town;

use App\Events\TownMessageSent;
use App\Exceptions\DomainException;
use App\Models\Avatar;
use App\Models\ChatMessage;
use App\Models\ChatRoom;
use App\Models\Friendship;
use App\Models\Plaza;
use App\Models\User;
use App\Services\Plaza\PlazaPresence;
use App\Services\Region\Region;
use App\Services\Region\RegionResolver;
use App\Support\Geo;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * 동네 상시 광장 (디자인: MainGrid 상단 카드 · TownPlaza.dc.html).
 *
 * 구조 — 큰 광장 하나를 '구역(관심사) × 채널(30명)' 방들로 나눈 것:
 *   역삼동 광장 ─┬─ 분수대 ─ 1채널(30명) · 2채널 …
 *               ├─ 벤치   ─ 1채널 …
 *               └─ …
 * - 같은 구역·같은 채널 사람끼리만 서로 보이고 대화한다 (presence 공간 "{광장id}:{구역}:{채널}")
 * - 채널이 차면 다음 채널로 자동 배정 → 128명이 와도 한 화면은 최대 30명이라 대화가 묻히지 않음
 * - 방장이 없고 닫히지 않는다. 문제 사용자는 신고·차단으로 대응
 */
class TownService
{
    public function __construct(
        private readonly PlazaPresence $presence,
        private readonly RegionResolver $regions,
    ) {}

    /* ---------------- 동네 광장 찾기 ---------------- */

    /**
     * 좌표가 속한 동네의 상시 광장. 처음 들어오는 사람이 있으면 그때 만든다.
     *
     * @return array{0: Plaza, 1: Region}|null 동네를 못 찾으면(바다 위 등) null
     */
    public function forLocation(float $lat, float $lng): ?array
    {
        $region = $this->regions->resolve($lat, $lng);
        if (! $region) {
            return null;
        }

        $town = Plaza::where('region_code', $region->code)->first();
        if (! $town) {
            try {
                $town = DB::transaction(function () use ($region, $lat, $lng) {
                    // 좌표는 geohash 5자리(약 5km) 칸 중심값 → 처음 들어온 사람의 위치가 남지 않음
                    $cell = Geo::encode($lat, $lng, 5);
                    [$cLat, $cLng] = Geo::center($cell);
                    $plaza = Plaza::create([
                        'type' => Plaza::TYPE_TOWN,
                        'region_code' => $region->code,
                        'host_id' => null,
                        'name' => "{$region->name} 광장",
                        'description' => '동네 사람 누구나 24시간 들어올 수 있어요',
                        'category' => 'talk',
                        'region_cell' => Geo::encode($cLat, $cLng, 7),
                        'lat' => $cLat,
                        'lng' => $cLng,
                        'capacity' => $this->channelCapacity() * $this->maxChannels() * count($this->zones()),
                    ]);
                    ChatRoom::create(['type' => ChatRoom::TYPE_PLAZA, 'ref_id' => $plaza->id]);

                    return $plaza;
                });
            } catch (UniqueConstraintViolationException) {
                // 같은 동네 첫 입장이 동시에 일어난 경우: 먼저 만든 쪽을 쓴다 (region_code UNIQUE)
                $town = Plaza::where('region_code', $region->code)->firstOrFail();
            }
        }

        return [$town, $region];
    }

    /**
     * 자유광장: 위치 권한이 없어도 들어올 수 있는 전국 하나뿐인 상시 광장. 처음 부를 때 만든다.
     * 좌표는 의미가 없어 (0,0) — 상시 광장은 주변 목록·검색에 안 나오므로 영향 없음.
     */
    public function free(): Plaza
    {
        $free = Plaza::where('region_code', Plaza::FREE_REGION)->first();
        if ($free) {
            return $free;
        }
        try {
            return DB::transaction(function () {
                $plaza = Plaza::create([
                    'type' => Plaza::TYPE_TOWN,
                    'region_code' => Plaza::FREE_REGION,
                    'host_id' => null,
                    'name' => '자유광장',
                    'description' => '위치와 상관없이 전국 누구나 들어올 수 있어요',
                    'category' => 'talk',
                    'region_cell' => Geo::encode(0, 0, 7),
                    'lat' => 0,
                    'lng' => 0,
                    'capacity' => $this->channelCapacity() * $this->maxChannels() * count($this->zones()),
                ]);
                ChatRoom::create(['type' => ChatRoom::TYPE_PLAZA, 'ref_id' => $plaza->id]);

                return $plaza;
            });
        } catch (UniqueConstraintViolationException) {
            // 첫 조회가 동시에 일어난 경우: 먼저 만든 쪽 (region_code UNIQUE)
            return Plaza::where('region_code', Plaza::FREE_REGION)->firstOrFail();
        }
    }

    /* ---------------- 메인 카드 · 구역 현황 ---------------- */

    /**
     * 메인 상단 카드: 접속자 수, 풍경 미리보기 아바타, 최근 말풍선 2개, 여기 있는 친구.
     */
    public function summary(User $viewer, Plaza $town): array
    {
        $occupancy = $this->occupancy($town);
        $blocked = $viewer->blockedUserIds();

        // 미리보기 아바타: 사람 많은 방부터 최대 preview_avatars명
        $want = (int) config('social.town.preview_avatars');
        $ids = [];
        foreach (collect($occupancy)->flatMap(fn ($chs, $zone) => collect($chs)->mapWithKeys(fn ($n, $ch) => ["{$zone}:{$ch}" => $n]))
            ->filter()->sortDesc() as $key => $n) {
            [$zone, $ch] = explode(':', $key);
            foreach ($this->presence->members($this->space($town, $zone, (int) $ch), $want) as $uid) {
                if (! in_array($uid, $blocked, true) && ! in_array($uid, $ids, true)) {
                    $ids[] = $uid;
                }
            }
            if (count($ids) >= $want) {
                break;
            }
        }
        $ids = array_slice($ids, 0, $want);
        $avatars = Avatar::whereIn('user_id', $ids)->get()->keyBy('user_id');

        // 최근 10분 대화가 있었는지만 (내용은 보내지 않음 — 구역·채널 안 사람만 대화를 읽는 원칙, 2026-11-19)
        $chatting = (bool) $town->last_message_at?->gt(now()->subMinutes(10));

        // 여기 있는 친구 (차단 관계 제외)
        $friendIds = Friendship::where('user_id', $viewer->id)->pluck('friend_id')
            ->map(fn ($id) => (int) $id)->diff($blocked)->values()->all();
        $friendsHere = collect($this->presence->whereAre($friendIds))
            ->filter(fn ($plazaId) => $plazaId === (int) $town->id)->keys()->all();
        $friendAvatars = Avatar::whereIn('user_id', array_slice($friendsHere, 0, 3))->pluck('parts')->all();

        return [
            'id' => $town->id,
            'is_free' => $town->isFree(),
            'name' => $town->name,
            'description' => $town->description,
            'live_count' => array_sum(array_map('array_sum', $occupancy)),
            'preview_avatars' => collect($ids)->map(fn ($id) => $avatars->get($id)?->parts)->filter()->values()->all(),
            'chatting' => $chatting,
            'friends' => ['count' => count($friendsHere), 'avatars' => $friendAvatars],
        ];
    }

    /**
     * 상시 광장 화면의 구역 지도·채널 목록.
     * 채널 목록 = 사람이 있는 채널 + 들어갈 수 있는 가장 앞 채널 (빈 채널을 다 보여주지 않음)
     */
    public function state(Plaza $town): array
    {
        $this->assertTown($town);
        $occupancy = $this->occupancy($town);
        $cap = $this->channelCapacity();

        $zones = [];
        foreach ($this->zones() as $key => $z) {
            $chs = $occupancy[$key];
            $open = $this->firstOpenChannel($chs);
            $zones[] = [
                'key' => $key,
                'name' => $z['name'],
                'topic' => $z['topic'],
                'count' => array_sum($chs),
                'channels' => collect($chs)
                    ->filter(fn ($n, $ch) => $n > 0 || $ch === $open)
                    ->map(fn ($n, $ch) => ['no' => $ch, 'count' => $n, 'full' => $n >= $cap])
                    ->values()->all(),
            ];
        }

        return [
            'id' => $town->id,
            'name' => $town->name,
            'live_count' => array_sum(array_column($zones, 'count')),
            'channel_capacity' => $cap,
            'zones' => $zones,
        ];
    }

    /* ---------------- 입장 · 이동 · heartbeat ---------------- */

    /**
     * 입장 또는 구역·채널 이동.
     * - 구역을 안 고르면: 방금까지 있던 자리(재접속) → 없으면 가장 북적이는 구역
     * - 채널을 안 고르면: 그 구역에서 자리가 남은 가장 앞 채널 (1채널이 차면 2채널)
     * - 다른 자리에 있었으면 거기서 빠진다 (한 사람은 한 곳에만)
     *
     * @return array{zone: string, channel: int}
     */
    /**
     * @param  float|null  $lat,$lng  처음 들어올 때 내 동네인지 확인용 (저장 안 함). 자유광장·구역 이동엔 불필요
     */
    public function enter(User $user, Plaza $town, ?string $zone = null, ?int $channel = null, ?float $lat = null, ?float $lng = null): array
    {
        $this->assertTown($town);

        $prev = $this->presence->current($user->id);
        $prevHere = $prev && str_starts_with($prev, "{$town->id}:") ? $prev : null;
        if (! $prevHere) {
            $this->assertMyTown($town, $lat, $lng); // 이미 안에서 구역·채널만 옮기는 경우는 검사 생략
        }
        $occupancy = $this->occupancy($town);
        [, $prevZone, $prevCh] = $prevHere ? explode(':', $prevHere) + [null, null, null] : [null, null, null];

        if ($zone === null) {
            $zone = $prevZone ?? $this->busiestZone($occupancy);
        }
        $this->assertZone($zone);

        if ($channel !== null) {
            $this->assertChannel($channel);
            $mine = $prevHere === $this->space($town, $zone, $channel);
            if (! $mine && $occupancy[$zone][$channel] >= $this->channelCapacity()) {
                throw new DomainException('CHANNEL_FULL', '이 채널은 가득 찼어요. 다른 채널을 골라 주세요', 409);
            }
        } elseif ($prevZone === $zone && $prevCh !== null) {
            $channel = (int) $prevCh; // 같은 구역 재입장이면 그 채널 유지
        } else {
            $channel = $this->firstOpenChannel($occupancy[$zone]);
            if ($channel === null) {
                throw new DomainException('TOWN_FULL', '이 구역이 가득 찼어요. 다른 구역에 들어가 보세요', 409);
            }
        }

        $space = $this->space($town, $zone, $channel);
        if ($prevHere && $prevHere !== $space) {
            $this->presence->leave($prevHere, $user->id);
        }
        $this->presence->touch($space, $user->id);

        return ['zone' => $zone, 'channel' => $channel];
    }

    public function heartbeat(User $user, Plaza $town, string $zone, int $channel): void
    {
        $this->assertTown($town);
        $space = $this->validSpace($town, $zone, $channel);
        if (! $this->presence->has($space, $user->id)) {
            throw new DomainException('NOT_IN_PLAZA', '광장에 다시 입장해 주세요', 409);
        }
        $this->presence->touch($space, $user->id);
    }

    public function leave(User $user, Plaza $town, string $zone, int $channel): void
    {
        $this->presence->leave($this->validSpace($town, $zone, $channel), $user->id);
    }

    /* ---------------- 구역 채팅 ---------------- */

    /** 저장 먼저, 그다음 같은 구역·채널에만 브로드캐스트 (PlazaService::sendMessage와 같은 원칙) */
    public function sendMessage(User $sender, Plaza $town, string $zone, int $channel, string $body): ChatMessage
    {
        $this->assertTown($town);
        $space = $this->validSpace($town, $zone, $channel);
        if (! $this->presence->has($space, $sender->id)) {
            throw new DomainException('NOT_IN_PLAZA', '광장에 입장한 뒤 대화할 수 있어요', 403);
        }

        $message = $town->chatRoom()->firstOrFail()->messages()->create([
            'channel' => "{$zone}:{$channel}",
            'sender_id' => $sender->id,
            'body' => $body,
        ]);
        $town->forceFill(['last_message_at' => now()])->saveQuietly(); // '대화 중' 표시용 시각만 (내용은 저장 안 함)
        $this->presence->touch($space, $sender->id);

        broadcast(new TownMessageSent($town->id, $zone, $channel, $message->load('sender.avatar')))->toOthers();

        return $message;
    }

    /** @return Collection<int, ChatMessage> 같은 구역·채널 메시지 (최신순, 차단 관계 제외) */
    public function messages(User $viewer, Plaza $town, string $zone, int $channel, ?int $beforeId, int $limit = 50): Collection
    {
        $this->assertTown($town);
        $space = $this->validSpace($town, $zone, $channel);
        // 읽기도 말하기와 같은 기준: 그 구역·채널에 들어와 있는 사람만.
        // 전에는 번호만 알면 다른 구 광장·다른 채널 대화까지 읽을 수 있었다 (입장 제한 우회)
        if (! $this->presence->has($space, $viewer->id)) {
            throw new DomainException('NOT_IN_PLAZA', '광장에 들어온 뒤 대화를 볼 수 있어요', 403);
        }

        return $town->chatRoom()->firstOrFail()->messages()
            ->where('channel', "{$zone}:{$channel}")
            ->with('sender.avatar')
            ->whereNotIn('sender_id', $viewer->blockedUserIds())
            ->when($beforeId, fn ($q) => $q->where('id', '<', $beforeId))
            ->orderByDesc('id')
            ->limit($limit)
            ->get();
    }

    /* ---------------- 내부 ---------------- */

    /** @return array<string, array<int, int>> 구역 => [채널 => 접속자 수] (Redis 파이프라인 1번) */
    private function occupancy(Plaza $town): array
    {
        $spaces = [];
        foreach (array_keys($this->zones()) as $zone) {
            for ($ch = 1; $ch <= $this->maxChannels(); $ch++) {
                $spaces[] = $this->space($town, $zone, $ch);
            }
        }
        $counts = $this->presence->counts($spaces);

        $out = [];
        foreach ($spaces as $space) {
            [, $zone, $ch] = explode(':', $space);
            $out[$zone][(int) $ch] = (int) ($counts[$space] ?? 0);
        }

        return $out;
    }

    /** 가장 북적이는 구역 (같으면 지도 순서상 앞, 아무도 없으면 분수대) */
    private function busiestZone(array $occupancy): string
    {
        $best = array_key_first($occupancy);
        foreach ($occupancy as $zone => $chs) {
            if (array_sum($chs) > array_sum($occupancy[$best])) {
                $best = $zone;
            }
        }

        return $best;
    }

    private function firstOpenChannel(array $channels): ?int
    {
        foreach ($channels as $ch => $n) {
            if ($n < $this->channelCapacity()) {
                return (int) $ch;
            }
        }

        return null;
    }

    private function space(Plaza $town, string $zone, int $channel): string
    {
        return "{$town->id}:{$zone}:{$channel}";
    }

    private function validSpace(Plaza $town, string $zone, int $channel): string
    {
        $this->assertZone($zone);
        $this->assertChannel($channel);

        return $this->space($town, $zone, $channel);
    }

    /**
     * 동네 광장은 그 동네 사람만. 번호만 알면 다른 구 광장에 들어가던 빈틈을 막는다.
     * 자유광장은 누구나. 위치 권한이 없으면(좌표 없음) 동네 광장엔 못 들어옴 → 자유광장 안내
     */
    private function assertMyTown(Plaza $town, ?float $lat, ?float $lng): void
    {
        if ($town->isFree()) {
            return;
        }
        if ($lat === null || $lng === null) {
            throw new DomainException('LOCATION_REQUIRED', '위치 권한을 켜야 동네 광장에 들어갈 수 있어요. 자유광장은 누구나 들어올 수 있어요', 403);
        }
        if ($this->regions->resolve($lat, $lng)?->code !== $town->region_code) {
            throw new DomainException('NOT_MY_TOWN', '우리 동네 광장에만 들어갈 수 있어요', 403);
        }
    }

    private function assertTown(Plaza $plaza): void
    {
        if (! $plaza->isTown()) {
            throw new DomainException('NOT_TOWN', '동네 상시 광장이 아니에요', 404);
        }
        if ($plaza->status !== Plaza::STATUS_OPEN) {
            throw new DomainException('PLAZA_CLOSED', '닫힌 광장이에요', 410);
        }
    }

    private function assertZone(string $zone): void
    {
        if (! array_key_exists($zone, $this->zones())) {
            throw new DomainException('INVALID_ZONE', '없는 구역이에요', 422);
        }
    }

    private function assertChannel(int $channel): void
    {
        if ($channel < 1 || $channel > $this->maxChannels()) {
            throw new DomainException('INVALID_CHANNEL', '없는 채널이에요', 422);
        }
    }

    /** @return array<string, array{name: string, topic: string}> */
    private function zones(): array
    {
        return config('social.town.zones');
    }

    private function channelCapacity(): int
    {
        return (int) config('social.town.channel_capacity');
    }

    private function maxChannels(): int
    {
        return (int) config('social.town.max_channels');
    }
}
