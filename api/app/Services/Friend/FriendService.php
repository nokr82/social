<?php

namespace App\Services\Friend;

use App\Notifications\Notify;
use App\Exceptions\DomainException;
use App\Models\FriendRequest;
use App\Models\Friendship;
use App\Models\User;
use App\Services\Location\LocationShareService;
use Illuminate\Support\Facades\DB;

/**
 * 친구 요청 규칙 (디자인 메모 기준)
 * - 수락 → 서로의 비공개 사진·성별·나이 공개 (friendships 2행 생성)
 * - 거절 → 상대에게 알리지 않음: 보낸 사람 목록에선 만료 전까지 계속 '대기 중'
 * - 되돌리기 → 거절을 다시 대기로
 * - 보낸 요청은 7일 뒤 자동 만료
 */
class FriendService
{
    private const DAILY_LIMIT = 30; // 하루 보낼 수 있는 요청 수 (무차별 요청 방지)

    /**
     * 관계 상태: none | sent | received | friends
     */
    public function status(User $viewer, int $targetId): string
    {
        if ($viewer->isFriendWith($targetId)) {
            return 'friends';
        }
        $alive = FriendRequest::alive()->whereIn('status', [FriendRequest::PENDING, FriendRequest::REJECTED]);

        // 내가 보낸 요청: 거절됐어도 나에겐 '보냄'으로 보임
        if ((clone $alive)->where('from_id', $viewer->id)->where('to_id', $targetId)->exists()) {
            return 'sent';
        }
        // 받은 요청: 대기 중인 것만
        if ((clone $alive)->where('from_id', $targetId)->where('to_id', $viewer->id)->where('status', FriendRequest::PENDING)->exists()) {
            return 'received';
        }

        return 'none';
    }

    public function send(User $from, int $toId, ?string $message, ?int $plazaId): FriendRequest
    {
        if ($toId === $from->id) {
            throw new DomainException('SELF_REQUEST', '자기 자신에게는 요청할 수 없어요');
        }
        if ($from->isBlockedWith($toId) || ! User::whereKey($toId)->where('status', User::STATUS_ACTIVE)->exists()) {
            // 차단 사실을 드러내지 않도록 '없는 사용자'와 같은 응답
            throw new DomainException('NOT_FOUND', '사용자를 찾을 수 없어요', 404);
        }

        $status = $this->status($from, $toId);
        if ($status === 'friends') {
            throw new DomainException('ALREADY_FRIENDS', '이미 친구예요', 409);
        }
        if ($status === 'sent') {
            throw new DomainException('ALREADY_SENT', '이미 요청을 보냈어요', 409);
        }
        if ($status === 'received') {
            throw new DomainException('INCOMING_EXISTS', '상대가 먼저 친구 요청을 보냈어요. 받은 요청에서 수락해 주세요', 409);
        }

        $today = FriendRequest::where('from_id', $from->id)->where('created_at', '>=', now()->startOfDay())->count();
        if ($today >= self::DAILY_LIMIT) {
            throw new DomainException('DAILY_LIMIT', '오늘은 친구 요청을 더 보낼 수 없어요', 429);
        }

        $req = FriendRequest::create([
            'from_id' => $from->id,
            'to_id' => $toId,
            'message' => $message,
            'plaza_id' => $plazaId,
            'expires_at' => now()->addDays(FriendRequest::TTL_DAYS),
        ]);
        Notify::friendRequested($from, User::findOrFail($toId), $message);

        return $req;
    }

    public function accept(User $me, FriendRequest $req): void
    {
        $this->assertReceiver($me, $req, FriendRequest::PENDING);

        DB::transaction(function () use ($req) {
            $req->update(['status' => FriendRequest::ACCEPTED, 'responded_at' => now()]);
            // 양방향 2행. insertOrIgnore: 동시에 서로 수락해도 중복 행이 생기지 않음
            Friendship::query()->insertOrIgnore([
                ['user_id' => $req->from_id, 'friend_id' => $req->to_id, 'plaza_id' => $req->plaza_id, 'created_at' => now()],
                ['user_id' => $req->to_id, 'friend_id' => $req->from_id, 'plaza_id' => $req->plaza_id, 'created_at' => now()],
            ]);
        });
        // 요청 보낸 사람에게 '친구가 됐어요'
        if ($from = User::find($req->from_id)) {
            Notify::friendAccepted($me, $from);
        }
    }

    public function reject(User $me, FriendRequest $req): void
    {
        $this->assertReceiver($me, $req, FriendRequest::PENDING);
        $req->update(['status' => FriendRequest::REJECTED, 'responded_at' => now()]);
    }

    /** 거절 되돌리기 */
    public function undoReject(User $me, FriendRequest $req): void
    {
        $this->assertReceiver($me, $req, FriendRequest::REJECTED);
        $req->update(['status' => FriendRequest::PENDING, 'responded_at' => null]);
    }

    /** 보낸 사람이 취소 */
    public function cancel(User $me, FriendRequest $req): void
    {
        if ($req->from_id !== $me->id || $req->status === FriendRequest::ACCEPTED) {
            throw new DomainException('NOT_FOUND', '요청을 찾을 수 없어요', 404);
        }
        $req->delete();
    }

    /** 차단 시: 친구 관계·오가는 요청·위치 공유 모두 정리 */
    public function severAll(int $a, int $b): void
    {
        Friendship::where(fn ($q) => $q->where('user_id', $a)->where('friend_id', $b))
            ->orWhere(fn ($q) => $q->where('user_id', $b)->where('friend_id', $a))
            ->delete();
        FriendRequest::where(fn ($q) => $q->where('from_id', $a)->where('to_id', $b))
            ->orWhere(fn ($q) => $q->where('from_id', $b)->where('to_id', $a))
            ->delete();
        // 위치 공유도 양방향 즉시 해제 (보던 지도에서도 바로 사라짐)
        app(LocationShareService::class)->severBetween($a, $b);
    }

    private function assertReceiver(User $me, FriendRequest $req, string $expected): void
    {
        if ($req->to_id !== $me->id || $req->status !== $expected || $req->expires_at->isPast()) {
            throw new DomainException('NOT_FOUND', '요청을 찾을 수 없어요', 404);
        }
    }
}
