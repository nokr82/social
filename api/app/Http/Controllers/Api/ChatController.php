<?php

namespace App\Http\Controllers\Api;

use App\Models\ChatMessage;
use App\Services\Chat\ChatAttachmentService;
use App\Http\Controllers\Controller;
use App\Http\Resources\AvatarSummary;
use App\Http\Resources\ChatMessageResource;
use App\Models\ChatRoom;
use App\Models\Friendship;
use App\Models\Plaza;
use App\Models\User;
use App\Services\Chat\DmService;
use App\Services\Plaza\PlazaPresence;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * 채팅 탭 (대화·친구) + 1:1 대화방
 */
class ChatController extends Controller
{
    private const ONLINE_MINUTES = 5;

    public function __construct(private readonly DmService $dm) {}

    /** GET /api/chats — 친구와의 대화 목록 */
    public function index(Request $request): JsonResponse
    {
        $data = $this->dm->inbox($request->user())->map(fn ($r) => [
            'room_id' => $r['room_id'],
            'user' => AvatarSummary::of($r['user']),
            'last_message' => [
                'body' => $r['last_message']->preview(),
                'type' => $r['last_message']->type,
                'mine' => $r['last_message']->sender_id === $request->user()->id,
                'created_at' => $r['last_message']->created_at->toIso8601String(),
            ],
            'unread' => $r['unread'],
        ]);

        return response()->json(['data' => $data, 'unread_total' => $data->sum('unread')]);
    }

    /**
     * GET /api/friends — 친구 목록 (친구라서 성별·나이 보임)
     * 상태 문구: 광장에 있으면 '○○에 있어요', 아니면 접속 시각
     */
    public function friends(Request $request, PlazaPresence $presence): JsonResponse
    {
        $me = $request->user();
        $friends = User::with('avatar', 'identity')
            ->whereIn('id', $me->friendIds())
            ->whereNotIn('id', $me->blockedUserIds())
            ->get();

        $where = $presence->whereAre($friends->pluck('id')->all());
        $plazaNames = Plaza::whereIn('id', array_values($where))->pluck('name', 'id');

        $data = $friends->map(fn (User $f) => AvatarSummary::of($f) + [
            'gender' => $f->identity?->gender,
            'age' => $f->identity?->age(),
            'online' => $this->isOnline($f) || isset($where[$f->id]),
            'plaza_name' => isset($where[$f->id]) ? ($plazaNames[$where[$f->id]] ?? null) : null,
            'last_seen_at' => $f->last_seen_at?->toIso8601String(),
        ])->sortByDesc(fn ($f) => [$f['online'], $f['last_seen_at']])->values();

        return response()->json(['data' => $data]);
    }

    /** POST /api/dm/{user} — 친구와의 방 열기 (없으면 만듦) */
    public function open(Request $request, User $user): JsonResponse
    {
        return response()->json(['room_id' => $this->dm->openWith($request->user(), $user->id)->id]);
    }

    /** GET /api/chats/{room} — 방 정보 (헤더: 상대·성별·나이·접속 상태, 어디서 만났는지) */
    public function show(Request $request, ChatRoom $room): JsonResponse
    {
        $me = $request->user();
        $otherId = $this->dm->assertMember($me, $room);
        $other = User::with('avatar', 'identity')->findOrFail($otherId);
        $plazaId = Friendship::where('user_id', $me->id)->where('friend_id', $otherId)->value('plaza_id');

        return response()->json(['data' => [
            'room_id' => $room->id,
            'user' => AvatarSummary::of($other) + [
                'gender' => $other->identity?->gender,
                'age' => $other->identity?->age(),
                'online' => $this->isOnline($other),
                'last_seen_at' => $other->last_seen_at?->toIso8601String(),
            ],
            'met_at' => $plazaId ? Plaza::withTrashed()->find($plazaId)?->name : null,
            'other_last_read_id' => $this->dm->otherLastRead($me, $room),
        ]]);
    }

    /** GET /api/chats/{room}/messages?before_id */
    public function messages(Request $request, ChatRoom $room): JsonResponse
    {
        $before = $request->validate(['before_id' => ['nullable', 'integer', 'min:1']])['before_id'] ?? null;

        return response()->json(['data' => ChatMessageResource::collection($this->dm->messages($request->user(), $room, $before))]);
    }

    /** POST /api/chats/{room}/messages  {body} */
    public function send(Request $request, ChatRoom $room): JsonResponse
    {
        $body = trim($request->validate(['body' => ['required', 'string', 'max:1000']])['body']);
        abort_if($body === '', 422, '빈 메시지는 보낼 수 없어요');

        return (new ChatMessageResource($this->dm->send($request->user(), $room, $body)))->response()->setStatusCode(201);
    }

    /** POST /api/chats/{room}/images  (multipart: image) — 친구에게 사진 */
    public function sendImage(Request $request, ChatRoom $room, ChatAttachmentService $attachments): JsonResponse
    {
        $file = $request->validate(['image' => self::IMAGE_RULES])['image'];
        $this->dm->assertMember($request->user(), $room); // 권한 확인 후에 저장
        [$body, $meta] = $attachments->storeImage($room, $file);

        return (new ChatMessageResource($this->dm->send($request->user(), $room, $body, ChatMessage::IMAGE, $meta)))
            ->response()->setStatusCode(201);
    }

    /** POST /api/chats/{room}/meetups  {meetup_id} — 내가 참여 중인 번개를 카드로 공유 */
    public function shareMeetup(Request $request, ChatRoom $room, ChatAttachmentService $attachments): JsonResponse
    {
        $id = (int) $request->validate(['meetup_id' => ['required', 'integer', 'min:1']])['meetup_id'];
        $this->dm->assertMember($request->user(), $room);
        [$body, $meta] = $attachments->meetupCard($request->user(), $id);

        return (new ChatMessageResource($this->dm->send($request->user(), $room, $body, ChatMessage::MEETUP, $meta)))
            ->response()->setStatusCode(201);
    }

    /**
     * GET /api/chat-images/{message}/file?signature=... (로컬 저장소일 때만 사용)
     * 서명이 곧 권한: 메시지를 볼 수 있는 사람에게만 서명 URL이 발급된다.
     */
    public function imageFile(ChatMessage $message): \Symfony\Component\HttpFoundation\Response
    {
        abort_unless($message->type === ChatMessage::IMAGE && isset($message->meta['path']), 404);

        return \Illuminate\Support\Facades\Storage::disk(config('social.photos.disk'))->response($message->meta['path'], null, [
            'Cache-Control' => 'private, max-age=1800',
        ]);
    }

    /** 업로드 규칙 (프로필 사진과 동일: 10MB, jpg·png·webp) */
    public const IMAGE_RULES = ['required', 'file', 'image', 'mimes:jpeg,jpg,png,webp', 'max:10240'];

    /** POST /api/chats/{room}/read  {last_id} */
    public function read(Request $request, ChatRoom $room): JsonResponse
    {
        $lastId = (int) $request->validate(['last_id' => ['required', 'integer', 'min:1']])['last_id'];
        $this->dm->assertMember($request->user(), $room);
        // 이 방에 실제로 있는 메시지 id까지만 (임의의 큰 값으로 읽음 처리 방지)
        $lastId = min($lastId, (int) $room->messages()->max('id'));
        $this->dm->markRead($request->user(), $room, $lastId);

        return response()->json(['status' => 'ok']);
    }

    private function isOnline(User $u): bool
    {
        return $u->last_seen_at && $u->last_seen_at->gt(now()->subMinutes(self::ONLINE_MINUTES));
    }
}
