<?php

namespace App\Services\Meetup;

use App\Events\MeetupLocationStopped;
use App\Events\MeetupLocationUpdated;
use App\Exceptions\DomainException;
use App\Http\Resources\AvatarSummary;
use App\Models\LocationAccessLog;
use App\Models\Meetup;
use App\Models\MeetupLocationShare;
use App\Models\MeetupMember;
use App\Models\User;
use App\Notifications\Notify;
use App\Services\Location\LocationShareService;
use App\Support\Geo;
use Illuminate\Support\Facades\Cache;

/**
 * 번개 '모이는 중' 지도: 목적지 + 위치 공유를 켠 참여자.
 * - 참여 확정자 누구나 켤 수 있음(친구 아니어도), 기본은 꺼짐. 켠 사람의 위치만 같은 번개 참여 확정자에게 보임
 * - 기간 없음: 사용자가 끄거나, 참여 취소·번개 취소·채팅방 닫힘(약속 24시간 뒤)·차단 때 끝
 * - 좌표는 DB에 안 남김: 친구 공유와 같은 캐시(마지막 1개, 15분)를 씀
 * - 위치가 넘어갈 때(지도 조회) 확인자료 + 공유자에게 통보 (친구 공유와 같은 법적 요건, 같은 사람은 10분에 1번)
 * - 차단 관계면 서로 목록·지도·실시간 모두에서 빠짐 (차단 여부는 드러내지 않음)
 */
class MeetupLocationService
{
    private const NOTICE_EVERY_MINUTES = 10;

    public function __construct(
        private readonly MeetupService $meetups,
        private readonly LocationShareService $locations,
    ) {}

    public function start(User $me, Meetup $meetup): void
    {
        $this->meetups->assertMember($me, $meetup);
        if (! $meetup->isRoomOpen()) {
            throw new DomainException('MEETUP_CLOSED', '끝났거나 취소된 번개예요', 410);
        }
        MeetupLocationShare::query()->insertOrIgnore([
            'meetup_id' => $meetup->id, 'user_id' => $me->id, 'created_at' => now(),
        ]);
    }

    public function stop(User $me, Meetup $meetup): void
    {
        $this->stopFor($meetup, $me->id);
    }

    /** 한 사람 끄기 (본인 끄기·참여 취소) → 보던 참여자 지도에서 바로 지움 */
    public function stopFor(Meetup $meetup, int $userId): void
    {
        $deleted = MeetupLocationShare::where('meetup_id', $meetup->id)->where('user_id', $userId)->delete();
        if ($deleted) {
            $this->broadcast(new MeetupLocationStopped($meetup->id, $userId, $this->coMemberIds($meetup, $userId)));
        }
    }

    /** 번개 전체 끄기 (호스트 취소) */
    public function stopAll(Meetup $meetup): void
    {
        $sharers = MeetupLocationShare::where('meetup_id', $meetup->id)->pluck('user_id')->map(fn ($id) => (int) $id);
        MeetupLocationShare::where('meetup_id', $meetup->id)->delete();
        foreach ($sharers as $id) {
            $this->broadcast(new MeetupLocationStopped($meetup->id, $id, $this->coMemberIds($meetup, $id)));
        }
    }

    /** 차단: 두 사람이 함께 있는 번개에서 서로의 핀을 바로 지움 (공유 자체는 다른 참여자에게 계속) */
    public function severBetween(int $a, int $b): void
    {
        $rows = MeetupLocationShare::whereIn('user_id', [$a, $b])->get(['meetup_id', 'user_id']);
        foreach ($rows as $r) {
            $other = (int) $r->user_id === $a ? $b : $a;
            $together = MeetupMember::where('meetup_id', $r->meetup_id)->where('user_id', $other)
                ->where('status', MeetupMember::JOINED)->exists();
            if ($together) {
                $this->broadcast(new MeetupLocationStopped((int) $r->meetup_id, (int) $r->user_id, [$other]));
            }
        }
    }

    /**
     * 내 위치를 보내야 할 곳: [meetup_id => [보는 사람 id...]].
     * 지금도 참여 확정 + 채팅방 열림 + 상대가 정상 계정 + 차단 관계 아님인 경우만 (볼 때마다 다시 확인)
     *
     * @return array<int, array<int>>
     */
    public function targets(User $me): array
    {
        $blocked = $me->blockedUserIds();
        $out = [];
        foreach ($this->activeMeetups($me) as $meetup) {
            $viewers = array_values(array_diff($this->coMemberIds($meetup, $me->id), $blocked));
            if ($viewers) {
                $out[$meetup->id] = $viewers;
            }
        }

        return $out;
    }

    /** 위치 갱신을 번개 참여자에게 실시간 전송 (LocationShareService::update에서 호출) */
    public function broadcastPosition(User $me, array $position, array $targets): void
    {
        foreach ($targets as $meetupId => $viewerIds) {
            $this->broadcast(new MeetupLocationUpdated($meetupId, $viewerIds, $position));
        }
    }

    /** 내가 켜 둔 번개 (보내는 쪽 화면: 위치 감시를 계속할지·'공유 중' 띠) */
    public function mine(User $me): array
    {
        return $this->activeMeetups($me)->map(fn (Meetup $m) => [
            'meetup_id' => $m->id,
            'title' => $m->title,
            'closes_at' => $m->closesAt()->toIso8601String(),
        ])->values()->all();
    }

    /**
     * '모이는 중' 지도 데이터 (참여 확정자만).
     * 다른 사람 위치가 실제로 넘어가면 확인자료 + 통보.
     */
    public function board(User $viewer, Meetup $meetup): array
    {
        $this->meetups->assertMember($viewer, $meetup);

        $blocked = $viewer->blockedUserIds();
        $sharing = MeetupLocationShare::where('meetup_id', $meetup->id)->pluck('user_id')->map(fn ($id) => (int) $id)->all();
        $place = $meetup->placeInfo();
        $open = $meetup->isRoomOpen();

        $members = MeetupMember::where('meetup_id', $meetup->id)->where('status', MeetupMember::JOINED)
            ->whereNotIn('user_id', $blocked)
            ->with('user.avatar')->get()
            ->filter(fn (MeetupMember $mm) => $mm->user && ($mm->user->isActive() || $mm->user_id === $viewer->id))
            ->map(function (MeetupMember $mm) use ($viewer, $sharing, $place, $open, $meetup) {
                $isMe = $mm->user_id === $viewer->id;
                $on = $open && in_array((int) $mm->user_id, $sharing, true);
                $position = $on ? $this->locations->lastPosition((int) $mm->user_id) : null;
                if ($position && ! $isMe) {
                    $this->recordAccess($viewer, $mm->user, $meetup);
                }

                return [
                    'user' => AvatarSummary::of($mm->user),
                    'me' => $isMe,
                    'host' => $mm->user_id === $meetup->host_id,
                    'arrived' => $mm->arrived_at !== null,
                    'sharing' => $on,
                    'position' => $position,   // null = 공유 안 함 또는 최근 15분 안에 앱을 안 엶
                    // 목적지까지 거리: 사람 위치를 이미 정확히 보여주는 화면이라 정확한 m (범위 라벨 아님)
                    'distance_m' => $position && $place
                        ? (int) round(Geo::distanceM($position['lat'], $position['lng'], $place['lat'], $place['lng']))
                        : null,
                ];
            })
            ->sortBy(fn ($m) => [$m['me'] ? 0 : 1, $m['sharing'] ? 0 : 1])
            ->values()->all();

        return [
            'meetup' => ['id' => $meetup->id, 'title' => $meetup->title, 'starts_at' => $meetup->starts_at->toIso8601String(), 'room_open' => $open],
            'destination' => $place,                       // null = 글로만 적은 장소 (지도에 목적지 핀 없음)
            'place_detail' => $meetup->place_detail,
            'me_sharing' => $open && in_array($viewer->id, $sharing, true),
            'members' => $members,
        ];
    }

    /** 확인자료 + 공유자에게 통보. 지도를 열어 두는 동안 쌓이지 않게 같은 사람은 10분에 1번 (친구 공유와 같은 기준) */
    private function recordAccess(User $viewer, User $sharer, Meetup $meetup): void
    {
        if (! Cache::add("loc:mnotice:{$meetup->id}:{$sharer->id}:{$viewer->id}", 1, now()->addMinutes(self::NOTICE_EVERY_MINUTES))) {
            return;
        }
        LocationAccessLog::create(['sharer_id' => $sharer->id, 'viewer_id' => $viewer->id]);
        Notify::meetupLocationViewed($viewer, $sharer, $meetup);
    }

    /** 내가 켜 뒀고, 아직 참여 확정이고, 채팅방이 열린 번개 */
    private function activeMeetups(User $me)
    {
        $ids = MeetupLocationShare::where('user_id', $me->id)->pluck('meetup_id');
        if ($ids->isEmpty()) {
            return collect();
        }

        return Meetup::whereIn('id', $ids)
            ->where('status', Meetup::OPEN)
            ->where('starts_at', '>', now()->subHours(Meetup::ROOM_HOURS))
            ->whereIn('id', MeetupMember::where('user_id', $me->id)->where('status', MeetupMember::JOINED)->select('meetup_id'))
            ->get();
    }

    /** 같은 번개의 다른 참여 확정자 (정상 계정만) */
    private function coMemberIds(Meetup $meetup, int $exceptUserId): array
    {
        return User::whereIn('id', MeetupMember::where('meetup_id', $meetup->id)->where('status', MeetupMember::JOINED)->select('user_id'))
            ->whereKeyNot($exceptUserId)
            ->where('status', User::STATUS_ACTIVE)
            ->pluck('id')->map(fn ($id) => (int) $id)->all();
    }

    /** 실시간 전송 실패가 본 동작(끄기·취소·차단)을 막지 않게 */
    private function broadcast(object $event): void
    {
        try {
            event($event);
        } catch (\Throwable $e) {
            report($e);
        }
    }
}
