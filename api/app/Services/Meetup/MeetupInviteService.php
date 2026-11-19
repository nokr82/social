<?php

namespace App\Services\Meetup;

use App\Exceptions\DomainException;
use App\Http\Resources\AvatarSummary;
use App\Models\ChatMessage;
use App\Models\Meetup;
use App\Models\MeetupInvite;
use App\Models\User;
use App\Services\Chat\DmService;

/**
 * 번개 친구 초대: 친구를 골라 각자의 1:1 대화방에 번개 카드(초대 표시)를 보낸다.
 * - 참여 확정자 누구나, 시작 전 번개만
 * - 같은 친구는 번개 하나당 1번(누가 초대했든), 한 사람이 번개 하나에서 최대 INVITE_LIMIT명
 * - 초대는 입장권이 아님: 참여 때 연령·정원·차단 검사는 MeetupService::join이 평소대로 함
 *   (목록에서 미리 '조건이 안 맞아요'로 막는 건 안내용 — 이유(나이·차단)는 구분해 보여주지 않음)
 * - 알림은 따로 안 보냄: 대화방 메시지 푸시가 이미 가므로 두 번 알리지 않게
 */
class MeetupInviteService
{
    public const INVITE_LIMIT = 20;

    public function __construct(
        private readonly MeetupService $meetups,
        private readonly DmService $dms,
    ) {}

    /** 초대 시트: 내 친구 + 상태(available/joined/invited/ineligible) + 정원·남은 초대 수 */
    public function candidates(User $me, Meetup $meetup): array
    {
        $this->assertCanInvite($me, $meetup);

        $joinedIds = $meetup->joined()->pluck('user_id')->map(fn ($id) => (int) $id)->all();
        $invitedIds = MeetupInvite::where('meetup_id', $meetup->id)->pluck('invitee_id')->map(fn ($id) => (int) $id)->all();

        $friends = $this->friends($me)->map(fn (User $f) => [
            'user' => AvatarSummary::of($f),
            'status' => match (true) {
                in_array($f->id, $joinedIds, true) => 'joined',
                in_array($f->id, $invitedIds, true) => 'invited',
                ! $this->eligible($f, $meetup, $joinedIds) => 'ineligible',
                default => 'available',
            },
        ])->sortBy(fn ($r) => ['available' => 0, 'invited' => 1, 'joined' => 2, 'ineligible' => 3][$r['status']])
            ->values()->all();

        return [
            'full' => count($joinedIds) >= $meetup->capacity,
            'remaining' => max(0, self::INVITE_LIMIT - $this->sentBy($me, $meetup)),
            'friends' => $friends,
        ];
    }

    /**
     * 고른 친구에게 초대 카드 보내기. 이미 참여·초대됨·조건 안 맞음은 조용히 건너뜀.
     *
     * @param  array<int>  $friendIds
     * @return array{sent: int}
     */
    public function invite(User $me, Meetup $meetup, array $friendIds): array
    {
        $this->assertCanInvite($me, $meetup);

        $joinedIds = $meetup->joined()->pluck('user_id')->map(fn ($id) => (int) $id)->all();
        if (count($joinedIds) >= $meetup->capacity) {
            throw new DomainException('MEETUP_FULL', '정원이 가득 차서 초대할 수 없어요', 409);
        }

        $targets = $this->friends($me)->whereIn('id', array_map('intval', $friendIds))
            ->filter(fn (User $f) => ! in_array($f->id, $joinedIds, true) && $this->eligible($f, $meetup, $joinedIds));

        if ($this->sentBy($me, $meetup) + $targets->count() > self::INVITE_LIMIT) {
            throw new DomainException('INVITE_LIMIT', '번개 하나에서 친구는 '.self::INVITE_LIMIT.'명까지 초대할 수 있어요', 422);
        }

        $sent = 0;
        foreach ($targets as $friend) {
            // 같은 친구 중복 초대 방지: UNIQUE에 먼저 넣은 쪽만 카드를 보냄 (동시에 두 참여자가 눌러도 1장)
            $claimed = MeetupInvite::query()->insertOrIgnore([
                'meetup_id' => $meetup->id, 'inviter_id' => $me->id, 'invitee_id' => $friend->id, 'created_at' => now(),
            ]);
            if (! $claimed) {
                continue;
            }
            $room = $this->dms->openWith($me, $friend->id);
            $this->dms->send($me, $room, $meetup->title, ChatMessage::MEETUP, ['meetup_id' => $meetup->id, 'invite' => true]);
            $sent++;
        }

        return ['sent' => $sent];
    }

    private function assertCanInvite(User $me, Meetup $meetup): void
    {
        $this->meetups->assertMember($me, $meetup);
        if (! $meetup->isUpcoming()) {
            throw new DomainException('MEETUP_CLOSED', '이미 시작했거나 취소된 번개예요', 410);
        }
    }

    /** 지금도 친구 + 차단 관계 아님 + 정상 계정 (차단한 사람은 목록에 안 나옴 — 차단 사실을 드러내지 않음) */
    private function friends(User $me)
    {
        return User::whereIn('id', $me->friendIds())
            ->whereNotIn('id', $me->blockedUserIds())
            ->where('status', User::STATUS_ACTIVE)
            ->with(['avatar', 'identity'])
            ->orderBy('nickname')
            ->get();
    }

    /** join()과 같은 기준을 미리 확인 (연령 조건 · 참여자와 차단 관계) */
    private function eligible(User $friend, Meetup $meetup, array $joinedIds): bool
    {
        $age = $friend->identity?->age();
        if ($meetup->age_min !== null && ($age === null || $age < $meetup->age_min || $age > $meetup->age_max)) {
            return false;
        }

        return ! array_intersect($joinedIds, $friend->blockedUserIds());
    }

    private function sentBy(User $me, Meetup $meetup): int
    {
        return MeetupInvite::where('meetup_id', $meetup->id)->where('inviter_id', $me->id)->count();
    }
}
