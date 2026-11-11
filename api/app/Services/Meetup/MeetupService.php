<?php

namespace App\Services\Meetup;

use App\Notifications\Notify;
use App\Events\MeetupMessageSent;
use App\Exceptions\DomainException;
use App\Models\ChatMessage;
use App\Models\ChatRoom;
use App\Models\ChatRoomMember;
use App\Models\Meetup;
use App\Models\MeetupMember;
use App\Models\NoshowVote;
use App\Models\Plaza;
use App\Models\TrustScore;
use App\Models\User;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * 번개 규칙 (디자인 메모 + 확정된 결정)
 * - 48시간 이내, 2~10명, 광장에 연결
 * - 참여 = 즉시 확정 → 정확한 장소 공개
 * - 나이 조건은 서버가 본인인증 나이로 확인만 (나이는 누구에게도 공개 안 함)
 * - 취소: 시작 1시간 전까지 자유, 이후는 '늦은 취소'로 기록
 * - 노쇼: 약속 시간 ~ 채팅방 종료 사이, 참여자끼리 '안 왔어요' 상호 확인
 */
class MeetupService
{
    /** 나이 조건 → 실제 범위. near는 호스트 나이 ±5 */
    public function ageRange(string $rule, int $hostAge): array
    {
        return match ($rule) {
            '20s' => [20, 29],
            '30s' => [30, 39],
            'near' => [max(19, $hostAge - 5), $hostAge + 5],
            default => [null, null],
        };
    }

    public function create(User $host, Plaza $plaza, array $data, CarbonInterface $startsAt): Meetup
    {
        if (($host->trustScore?->score ?? 0) < config('social.trust.min_score_to_host')) {
            throw new DomainException('LOW_TRUST', '매너점수가 낮아 지금은 번개를 열 수 없어요', 403);
        }
        if ($startsAt->lte(now()->addMinutes(10)) || $startsAt->gt(now()->addHours(Meetup::MAX_AHEAD_HOURS))) {
            throw new DomainException('INVALID_TIME', '번개는 지금부터 48시간 이내 약속만 열 수 있어요');
        }
        $openCount = Meetup::upcoming()->where('host_id', $host->id)->count();
        if ($openCount >= config('social.meetup.max_open_per_host')) {
            throw new DomainException('TOO_MANY_MEETUPS', '번개는 동시에 '.config('social.meetup.max_open_per_host').'개까지 열 수 있어요', 409);
        }

        [$min, $max] = $this->ageRange($data['age_rule'] ?? 'any', $host->identity->age());

        return DB::transaction(function () use ($host, $plaza, $data, $startsAt, $min, $max) {
            $meetup = Meetup::create([
                'plaza_id' => $plaza->id,
                'host_id' => $host->id,
                'title' => $data['title'],
                'description' => $data['description'] ?? null,
                'area_label' => $data['area_label'],
                'place_detail' => $data['place_detail'],
                'starts_at' => $startsAt,
                'capacity' => $data['capacity'],
                'age_rule' => $data['age_rule'] ?? 'any',
                'age_min' => $min,
                'age_max' => $max,
            ]);
            MeetupMember::create(['meetup_id' => $meetup->id, 'user_id' => $host->id, 'joined_at' => now()]);
            $room = ChatRoom::create(['type' => ChatRoom::TYPE_MEETUP, 'ref_id' => $meetup->id]);
            ChatRoomMember::create(['room_id' => $room->id, 'user_id' => $host->id]);

            return $meetup;
        });
    }

    public function join(User $user, Meetup $meetup): void
    {
        if (! $meetup->isUpcoming()) {
            throw new DomainException('MEETUP_CLOSED', '이미 시작했거나 취소된 번개예요', 410);
        }
        if ($this->isJoined($user, $meetup)) {
            return; // 이미 참여 중이면 그대로
        }

        // 나이 조건: 이유(나이)는 말하지 않고 결과만
        $age = $user->identity?->age();
        if ($meetup->age_min !== null && ($age === null || $age < $meetup->age_min || $age > $meetup->age_max)) {
            throw new DomainException('AGE_MISMATCH', '이 번개의 참여 조건에 맞지 않아요', 403);
        }

        // 차단 관계인 사람이 있는 번개는 참여 불가 (디자인: '같은 번개에 참여할 수 없어요')
        $memberIds = $meetup->joined()->pluck('user_id')->map(fn ($id) => (int) $id)->all();
        if (array_intersect($memberIds, $user->blockedUserIds())) {
            throw new DomainException('NOT_AVAILABLE', '참여할 수 없는 번개예요', 403);
        }

        DB::transaction(function () use ($user, $meetup) {
            // 정원 확인은 잠금 후 다시 (동시에 마지막 자리를 두 명이 누르는 경우)
            $locked = Meetup::whereKey($meetup->id)->lockForUpdate()->first();
            if ($locked->joined()->count() >= $locked->capacity) {
                throw new DomainException('MEETUP_FULL', '정원이 가득 찼어요', 409);
            }

            // 취소했다가 다시 참여하는 경우를 위해 upsert
            MeetupMember::query()->upsert(
                [['meetup_id' => $meetup->id, 'user_id' => $user->id, 'status' => MeetupMember::JOINED, 'joined_at' => now(), 'canceled_at' => null]],
                ['meetup_id', 'user_id'],
                ['status', 'joined_at', 'canceled_at'],
            );
            $room = $meetup->chatRoom()->firstOrFail();
            ChatRoomMember::query()->insertOrIgnore(['room_id' => $room->id, 'user_id' => $user->id, 'last_read_id' => 0, 'created_at' => now()]);
        });

        $this->system($meetup, $user, "{$user->nickname}님이 참여했어요");
        Notify::meetupJoined($meetup, $user);
    }

    /** 참여자 취소: 1시간 전까지는 기록 없이, 이후는 '늦은 취소' */
    public function cancel(User $user, Meetup $meetup): string
    {
        if ($meetup->host_id === $user->id) {
            throw new DomainException('HOST_CANNOT_LEAVE', '호스트는 번개 자체를 취소해야 해요');
        }
        if (! $this->isJoined($user, $meetup) || ! $meetup->isUpcoming()) {
            throw new DomainException('NOT_JOINED', '참여 중인 번개가 아니에요', 409);
        }

        $late = now()->gt($meetup->starts_at->copy()->subHours(Meetup::FREE_CANCEL_HOURS));
        $status = $late ? MeetupMember::LATE_CANCELED : MeetupMember::CANCELED;

        MeetupMember::where('meetup_id', $meetup->id)->where('user_id', $user->id)
            ->update(['status' => $status, 'canceled_at' => now(), 'arrived_at' => null]);
        ChatRoomMember::where('room_id', $meetup->chatRoom()->value('id'))->where('user_id', $user->id)->delete();

        $this->system($meetup, $user, "{$user->nickname}님이 참여를 취소했어요");

        return $status;
    }

    /** 호스트 취소 → 번개 종료, 채팅방에 안내 */
    public function cancelByHost(User $host, Meetup $meetup): void
    {
        if ($meetup->host_id !== $host->id) {
            throw new DomainException('NOT_FOUND', '번개를 찾을 수 없어요', 404);
        }
        if (! $meetup->isUpcoming()) {
            throw new DomainException('MEETUP_CLOSED', '이미 시작했거나 취소된 번개예요', 410);
        }
        $meetup->update(['status' => Meetup::CANCELED, 'canceled_at' => now()]);
        $this->system($meetup, $host, '호스트가 번개를 취소했어요');
        Notify::meetupCanceled($meetup, $this->joinedUsers($meetup, except: $host->id));
    }

    /** '도착했어요' 토글 (자기 신고, 표시용) */
    public function toggleArrived(User $user, Meetup $meetup): bool
    {
        $this->assertMember($user, $meetup);
        if (now()->lt($meetup->starts_at->copy()->subHours(2))) {
            throw new DomainException('TOO_EARLY', '약속 2시간 전부터 도착 체크를 할 수 있어요');
        }
        $member = MeetupMember::where('meetup_id', $meetup->id)->where('user_id', $user->id)->first();
        $arrived = $member->arrived_at === null;

        MeetupMember::where('meetup_id', $meetup->id)->where('user_id', $user->id)
            ->update(['arrived_at' => $arrived ? now() : null]);
        if ($arrived) {
            $this->system($meetup, $user, "{$user->nickname}님이 도착했어요");
        }

        return $arrived;
    }

    /**
     * 노쇼 상호 확인: 참여자 A가 B를 '안 왔어요'로 체크.
     * 표가 기준(기본 2, 다른 참여자가 1명뿐이면 1)에 닿으면 노쇼 확정 → 매너점수 감점 1회.
     *
     * @return array{votes: int, needed: int, confirmed: bool}
     */
    public function voteNoshow(User $voter, Meetup $meetup, int $targetId): array
    {
        $this->assertMember($voter, $meetup);
        if (now()->lt($meetup->starts_at) || now()->gte($meetup->closesAt())) {
            throw new DomainException('NOT_VOTABLE', '약속 시간부터 채팅방이 닫히기 전까지만 체크할 수 있어요');
        }
        if ($targetId === $voter->id || ! $this->isJoinedId($targetId, $meetup)) {
            throw new DomainException('INVALID_TARGET', '체크할 수 없는 참여자예요');
        }

        NoshowVote::query()->insertOrIgnore(['meetup_id' => $meetup->id, 'voter_id' => $voter->id, 'target_id' => $targetId, 'created_at' => now()]);

        $votes = NoshowVote::where('meetup_id', $meetup->id)->where('target_id', $targetId)->count();
        $others = $meetup->joined()->count() - 1; // 대상 본인을 뺀 투표 가능 인원
        $needed = max(1, min((int) config('social.trust.noshow_votes'), $others));
        $confirmed = false;

        if ($votes >= $needed) {
            // noshow_at이 비어 있을 때만 갱신 → 감점은 정확히 한 번
            $first = MeetupMember::where('meetup_id', $meetup->id)->where('user_id', $targetId)
                ->whereNull('noshow_at')->update(['noshow_at' => now()]);
            if ($first) {
                $penalty = (int) config('social.trust.noshow_penalty');
                TrustScore::where('user_id', $targetId)->update([
                    'noshow_count' => DB::raw('noshow_count + 1'),
                    'score' => DB::raw("CASE WHEN score > {$penalty} THEN score - {$penalty} ELSE 0 END"),
                    'updated_at' => now(),
                ]);
            }
            $confirmed = true;
        }

        return ['votes' => $votes, 'needed' => $needed, 'confirmed' => $confirmed];
    }

    public function sendMessage(User $user, Meetup $meetup, string $body): ChatMessage
    {
        $this->assertMember($user, $meetup);
        if (! $meetup->isRoomOpen()) {
            throw new DomainException('ROOM_CLOSED', '닫힌 채팅방이에요', 410);
        }
        $room = $meetup->chatRoom()->firstOrFail();
        $message = $room->messages()->create(['sender_id' => $user->id, 'body' => $body]);
        $this->markRead($user, $meetup, $message->id);

        broadcast(new MeetupMessageSent($meetup->id, $message->load('sender.avatar')))->toOthers();
        Notify::meetupMessage($meetup, $user, $this->joinedUsers($meetup, except: $user->id), $body);

        return $message;
    }

    /** @return Collection<int, ChatMessage> 최신순 */
    public function messages(User $user, Meetup $meetup, ?int $beforeId): Collection
    {
        $this->assertMember($user, $meetup);

        return $meetup->chatRoom()->firstOrFail()->messages()->with('sender.avatar')
            ->whereNotIn('sender_id', $user->blockedUserIds())
            ->when($beforeId, fn ($q) => $q->where('id', '<', $beforeId))
            ->orderByDesc('id')->limit(50)->get();
    }

    public function markRead(User $user, Meetup $meetup, int $lastId): void
    {
        ChatRoomMember::where('room_id', $meetup->chatRoom()->value('id'))->where('user_id', $user->id)
            ->where('last_read_id', '<', $lastId)->update(['last_read_id' => $lastId]);
    }

    /** 참여 확정자 (알림 대상) */
    public function joinedUsers(Meetup $meetup, ?int $except = null): \Illuminate\Database\Eloquent\Collection
    {
        return User::whereIn('id', $meetup->joined()->pluck('user_id'))
            ->when($except, fn ($q) => $q->whereKeyNot($except))
            ->where('status', User::STATUS_ACTIVE)
            ->get();
    }

    public function isJoined(User $user, Meetup $meetup): bool
    {
        return $this->isJoinedId($user->id, $meetup);
    }

    public function assertMember(User $user, Meetup $meetup): void
    {
        if (! $this->isJoined($user, $meetup)) {
            throw new DomainException('NOT_JOINED', '참여한 사람만 볼 수 있어요', 403);
        }
    }

    private function isJoinedId(int $userId, Meetup $meetup): bool
    {
        return MeetupMember::where('meetup_id', $meetup->id)->where('user_id', $userId)
            ->where('status', MeetupMember::JOINED)->exists();
    }

    /** 채팅방 안내 메시지 (참여·취소·도착) */
    private function system(Meetup $meetup, User $actor, string $text): void
    {
        $room = $meetup->chatRoom()->first();
        if (! $room) {
            return;
        }
        $message = $room->messages()->create(['sender_id' => $actor->id, 'type' => 'system', 'body' => $text]);
        broadcast(new MeetupMessageSent($meetup->id, $message->load('sender.avatar')));
    }
}
