<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\AvatarSummary;
use App\Models\FriendRequest;
use App\Models\ProfilePhoto;
use App\Models\User;
use App\Services\Friend\FriendService;
use App\Services\Photo\PhotoService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class FriendRequestController extends Controller
{
    public function __construct(private readonly FriendService $friends, private readonly PhotoService $photos) {}

    /**
     * GET /api/friend-requests?type=received|sent
     * - received: 대기 중인 받은 요청 (공개 사진 3장 + 비공개 수 + 한마디 + 만난 곳)
     * - sent: 보낸 요청. 거절된 것도 '대기 중'으로 보인다 (거절 사실을 알리지 않음)
     */
    public function index(Request $request): JsonResponse
    {
        $type = $request->validate(['type' => ['nullable', Rule::in(['received', 'sent'])]])['type'] ?? 'received';
        $me = $request->user();

        $query = FriendRequest::alive()->with(['from.avatar', 'to.avatar', 'plaza'])->latest();
        $query = $type === 'received'
            ? $query->where('to_id', $me->id)->where('status', FriendRequest::PENDING)
            : $query->where('from_id', $me->id)->whereIn('status', [FriendRequest::PENDING, FriendRequest::REJECTED]);

        $items = $query->get()
            ->reject(fn (FriendRequest $r) => $me->isBlockedWith($type === 'received' ? $r->from_id : $r->to_id))
            ->map(fn (FriendRequest $r) => $this->present($r, $type, $me))
            ->values();

        return response()->json([
            'data' => $items,
            // 탭 숫자용
            'counts' => [
                'received' => FriendRequest::alive()->where('to_id', $me->id)->where('status', FriendRequest::PENDING)->count(),
                'sent' => FriendRequest::alive()->where('from_id', $me->id)->whereIn('status', [FriendRequest::PENDING, FriendRequest::REJECTED])->count(),
            ],
        ]);
    }

    /** POST /api/friend-requests  {user_id, message?, plaza_id?} */
    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'user_id' => ['required', 'integer'],
            'message' => ['nullable', 'string', 'max:100'],
            'plaza_id' => ['nullable', 'integer', 'exists:plazas,id'],
        ]);

        $req = $this->friends->send($request->user(), (int) $data['user_id'], $data['message'] ?? null, $data['plaza_id'] ?? null);

        return response()->json(['data' => ['id' => $req->id, 'status' => 'sent']], 201);
    }

    /**
     * POST /api/friend-requests/{req}/accept
     * 응답에 상대의 성별·나이·비공개 사진을 담아 '정보가 공개되는 순간' 카드를 바로 그릴 수 있게 한다.
     */
    public function accept(Request $request, FriendRequest $friendRequest): JsonResponse
    {
        $me = $request->user();
        $this->friends->accept($me, $friendRequest);
        $friend = $friendRequest->from->loadMissing('avatar', 'identity');
        $private = $friend->photos()->where('visibility', ProfilePhoto::PRIVATE)->get();

        return response()->json(['data' => AvatarSummary::of($friend) + [
            'gender' => $friend->identity?->gender,
            'age' => $friend->identity?->age(),
            'private_photos' => $this->photos->present($private, $me),
        ]]);
    }

    /** POST /api/friend-requests/{req}/reject — 상대에게 알리지 않음 */
    public function reject(Request $request, FriendRequest $friendRequest): JsonResponse
    {
        $this->friends->reject($request->user(), $friendRequest);

        return response()->json(['status' => 'ok']);
    }

    /** POST /api/friend-requests/{req}/undo — 거절 되돌리기 */
    public function undo(Request $request, FriendRequest $friendRequest): JsonResponse
    {
        $this->friends->undoReject($request->user(), $friendRequest);

        return response()->json(['status' => 'ok']);
    }

    /** DELETE /api/friend-requests/{req} — 보낸 요청 취소 */
    public function destroy(Request $request, FriendRequest $friendRequest): JsonResponse
    {
        $this->friends->cancel($request->user(), $friendRequest);

        return response()->json(['status' => 'ok']);
    }

    private function present(FriendRequest $r, string $type, User $me): array
    {
        $other = $type === 'received' ? $r->from : $r->to;
        $base = [
            'id' => $r->id,
            'user' => AvatarSummary::of($other),
            'message' => $r->message,
            'plaza_name' => $r->plaza?->name,
            'created_at' => $r->created_at->toIso8601String(),
            'expires_at' => $r->expires_at->toIso8601String(),
        ];
        if ($type === 'sent') {
            return $base + ['status' => 'pending']; // 거절이어도 pending으로 (거절 사실 비공개)
        }

        $photos = $other->photos()->get();
        $public = $photos->where('visibility', ProfilePhoto::PUBLIC);

        return $base + [
            'public_hearts' => (int) $public->sum('like_count'),
            'photos' => $this->photos->present($public->take(3), $me),
            'private_count' => $photos->where('visibility', ProfilePhoto::PRIVATE)->count(),
        ];
    }
}
