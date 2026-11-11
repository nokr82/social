<?php

namespace App\Http\Controllers\Api;

use App\Services\Chat\ChatAttachmentService;
use App\Http\Controllers\Controller;
use App\Http\Resources\AvatarSummary;
use App\Http\Resources\ChatMessageResource;
use App\Models\ChatMessage;
use App\Models\ChatRoomMember;
use App\Models\Meetup;
use App\Models\MeetupMember;
use App\Models\NoshowVote;
use App\Models\Plaza;
use App\Models\User;
use App\Services\Meetup\MeetupService;
use App\Support\Geo;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Validation\Rule;

class MeetupController extends Controller
{
    public function __construct(private readonly MeetupService $meetups) {}

    /** POST /api/plazas/{plaza}/meetups — 번개 만들기 (광장에 올라감) */
    public function store(Request $request, Plaza $plaza): JsonResponse
    {
        $data = $request->validate([
            'title' => ['required', 'string', 'min:2', 'max:60'],
            'description' => ['nullable', 'string', 'max:500'],
            'area_label' => ['required', 'string', 'max:40'],
            'place_detail' => ['required', 'string', 'max:100'],
            'starts_at' => ['required', 'date'],   // ISO 8601 (앱이 기기 시간대 포함해서 보냄)
            'capacity' => ['required', 'integer', 'between:2,10'],
            'age_rule' => ['nullable', Rule::in(['any', '20s', '30s', 'near'])],
        ]);

        $meetup = $this->meetups->create($request->user(), $plaza, $data, CarbonImmutable::parse($data['starts_at']));

        return response()->json(['data' => $this->present($meetup, $request->user())], 201);
    }

    /** GET /api/meetups?lat&lng — 번개 탭 '내 주변' (시작 전, 광장 위치 기준 반경) */
    public function index(Request $request): JsonResponse
    {
        $data = $request->validate([
            'lat' => ['required', 'numeric', 'between:-90,90'],
            'lng' => ['required', 'numeric', 'between:-180,180'],
        ]);
        $me = $request->user();
        $radius = (float) config('social.meetup.search_radius_m');
        [$minLat, $maxLat, $minLng, $maxLng] = Geo::boundingBox((float) $data['lat'], (float) $data['lng'], $radius);

        $list = Meetup::upcoming()
            ->whereNotIn('host_id', $me->blockedUserIds())
            ->whereHas('plaza', fn ($q) => $q->whereBetween('lat', [$minLat, $maxLat])->whereBetween('lng', [$minLng, $maxLng]))
            ->with(['plaza', 'host.avatar', 'joined.user.avatar'])
            ->orderBy('starts_at')->limit(50)->get()
            ->each(fn (Meetup $m) => $m->distance_m = Geo::distanceM((float) $data['lat'], (float) $data['lng'], $m->plaza->lat, $m->plaza->lng))
            ->filter(fn (Meetup $m) => $m->distance_m <= $radius)
            ->values();

        return response()->json(['data' => $list->map(fn ($m) => $this->present($m, $me))]);
    }

    /**
     * GET /api/meetups/mine — 내가 참여(호스트 포함)한, 채팅방이 열려 있는 번개.
     * 번개 탭 '내 번개'와 채팅 탭 '번개 채팅방'에서 함께 사용 (마지막 메시지·안 읽은 수 포함)
     */
    public function mine(Request $request): JsonResponse
    {
        $me = $request->user();
        $list = Meetup::where('status', Meetup::OPEN)
            ->where('starts_at', '>', now()->subHours(Meetup::ROOM_HOURS))
            ->whereHas('joined', fn ($q) => $q->where('user_id', $me->id))
            ->with(['plaza', 'host.avatar', 'joined.user.avatar', 'chatRoom'])
            ->orderBy('starts_at')->get();

        $roomIds = $list->pluck('chatRoom.id')->filter();
        $last = ChatMessage::whereIn('id', ChatMessage::selectRaw('MAX(id)')->whereIn('room_id', $roomIds)->groupBy('room_id'))
            ->with('sender')->get()->keyBy('room_id');
        $unread = ChatMessage::query()
            ->join('chat_room_members as m', fn ($j) => $j->on('m.room_id', '=', 'chat_messages.room_id')->where('m.user_id', $me->id))
            ->whereIn('chat_messages.room_id', $roomIds)
            ->whereColumn('chat_messages.id', '>', 'm.last_read_id')
            ->where('chat_messages.sender_id', '!=', $me->id)
            ->groupBy('chat_messages.room_id')->selectRaw('chat_messages.room_id, COUNT(*) as n')->pluck('n', 'room_id');

        return response()->json(['data' => $list->map(function (Meetup $m) use ($me, $last, $unread) {
            $msg = $last->get($m->chatRoom?->id);

            return $this->present($m, $me) + [
                'last_message' => $msg ? ['body' => $msg->preview(), 'type' => $msg->type, 'sender' => $msg->type === 'system' ? null : $msg->sender?->nickname, 'created_at' => $msg->created_at->toIso8601String()] : null,
                'unread' => (int) ($unread[$m->chatRoom?->id] ?? 0),
            ];
        })]);
    }

    /** GET /api/plazas/{plaza}/meetups — 광장 배너용 (시작 전 번개, 가까운 시간 순) */
    public function forPlaza(Request $request, Plaza $plaza): JsonResponse
    {
        $me = $request->user();
        $list = Meetup::upcoming()->where('plaza_id', $plaza->id)
            ->whereNotIn('host_id', $me->blockedUserIds())
            ->with(['plaza', 'host.avatar', 'joined.user.avatar'])
            ->orderBy('starts_at')->limit(5)->get();

        return response()->json(['data' => $list->map(fn ($m) => $this->present($m, $me))]);
    }

    /** GET /api/meetups/{meetup} — 번개 상세 */
    public function show(Request $request, Meetup $meetup): JsonResponse
    {
        if ($request->user()->isBlockedWith($meetup->host_id)) {
            abort(404);
        }
        $meetup->load(['plaza', 'host.avatar', 'joined.user.avatar']);

        return response()->json(['data' => $this->present($meetup, $request->user())]);
    }

    /** POST /api/meetups/{meetup}/join */
    public function join(Request $request, Meetup $meetup): JsonResponse
    {
        $this->meetups->join($request->user(), $meetup);

        return $this->show($request, $meetup->fresh());
    }

    /** POST /api/meetups/{meetup}/cancel — 참여자 취소 */
    public function cancel(Request $request, Meetup $meetup): JsonResponse
    {
        $status = $this->meetups->cancel($request->user(), $meetup);

        return response()->json(['status' => $status]); // canceled | late_canceled
    }

    /** DELETE /api/meetups/{meetup} — 호스트가 번개 취소 */
    public function destroy(Request $request, Meetup $meetup): JsonResponse
    {
        $this->meetups->cancelByHost($request->user(), $meetup);

        return response()->json(['status' => 'canceled']);
    }

    /** POST /api/meetups/{meetup}/arrive — '도착했어요' 토글 */
    public function arrive(Request $request, Meetup $meetup): JsonResponse
    {
        return response()->json(['arrived' => $this->meetups->toggleArrived($request->user(), $meetup)]);
    }

    /** POST /api/meetups/{meetup}/noshow  {user_id} — '안 왔어요' 체크 */
    public function noshow(Request $request, Meetup $meetup): JsonResponse
    {
        $target = (int) $request->validate(['user_id' => ['required', 'integer']])['user_id'];

        return response()->json($this->meetups->voteNoshow($request->user(), $meetup, $target));
    }

    /**
     * GET /api/meetups/{meetup}/room — 채팅방 상단 카드 (정확한 장소·도착 현황·노쇼 체크 상태)
     */
    public function room(Request $request, Meetup $meetup): JsonResponse
    {
        $me = $request->user();
        $this->meetups->assertMember($me, $meetup);
        $meetup->load(['plaza', 'host.avatar', 'joined.user.avatar']);
        $myVotes = NoshowVote::where('meetup_id', $meetup->id)->where('voter_id', $me->id)->pluck('target_id')->map(fn ($id) => (int) $id)->all();

        return response()->json(['data' => $this->present($meetup, $me) + [
            'room_open' => $meetup->isRoomOpen(),
            'closes_at' => $meetup->closesAt()->toIso8601String(),
            // 노쇼 체크는 약속 시간부터 채팅방이 닫힐 때까지
            'can_vote_noshow' => now()->gte($meetup->starts_at) && $meetup->isRoomOpen(),
            'my_noshow_votes' => $myVotes,
        ]]);
    }

    /** GET /api/meetups/{meetup}/messages?before_id */
    public function messages(Request $request, Meetup $meetup): JsonResponse
    {
        $before = $request->validate(['before_id' => ['nullable', 'integer', 'min:1']])['before_id'] ?? null;

        return response()->json(['data' => ChatMessageResource::collection($this->meetups->messages($request->user(), $meetup, $before))]);
    }

    /** POST /api/meetups/{meetup}/messages  {body} */
    public function send(Request $request, Meetup $meetup): JsonResponse
    {
        $body = trim($request->validate(['body' => ['required', 'string', 'max:500']])['body']);
        abort_if($body === '', 422, '빈 메시지는 보낼 수 없어요');

        return (new ChatMessageResource($this->meetups->sendMessage($request->user(), $meetup, $body)))->response()->setStatusCode(201);
    }

    /** POST /api/meetups/{meetup}/images  (multipart: image) — 번개방에 사진 */
    public function sendImage(Request $request, Meetup $meetup, ChatAttachmentService $attachments): JsonResponse
    {
        $file = $request->validate(['image' => ChatController::IMAGE_RULES])['image'];
        $this->meetups->assertCanPost($request->user(), $meetup); // 권한 확인 후에 저장
        [$body, $meta] = $attachments->storeImage($meetup->chatRoom()->firstOrFail(), $file);

        return (new ChatMessageResource($this->meetups->sendMessage($request->user(), $meetup, $body, ChatMessage::IMAGE, $meta)))
            ->response()->setStatusCode(201);
    }

    /** POST /api/meetups/{meetup}/read  {last_id} */
    public function read(Request $request, Meetup $meetup): JsonResponse
    {
        $lastId = (int) $request->validate(['last_id' => ['required', 'integer', 'min:1']])['last_id'];
        $this->meetups->assertMember($request->user(), $meetup);
        $roomId = $meetup->chatRoom()->value('id');
        $this->meetups->markRead($request->user(), $meetup, min($lastId, (int) ChatMessage::where('room_id', $roomId)->max('id')));

        return response()->json(['status' => 'ok']);
    }

    /**
     * 번개 응답. 정확한 장소는 참여자에게만, 참여자 나이는 누구에게도 보내지 않는다.
     */
    private function present(Meetup $m, User $me): array
    {
        $m->loadMissing(['plaza', 'host.avatar', 'joined.user.avatar']);
        $joined = $m->joined;
        $isMember = $joined->contains('user_id', $me->id);

        return [
            'id' => $m->id,
            'title' => $m->title,
            'description' => $m->description,
            'plaza' => ['id' => $m->plaza_id, 'name' => $m->plaza?->name],
            'host' => AvatarSummary::of($m->host),
            'starts_at' => $m->starts_at->toIso8601String(),
            'area_label' => $m->area_label,
            'place_detail' => $isMember ? $m->place_detail : null,
            'capacity' => $m->capacity,
            'joined_count' => $joined->count(),
            'members' => $this->members($joined, $m, $me),
            'condition' => $this->conditionLabel($m),
            'status' => $m->status,
            'my_status' => $m->host_id === $me->id ? 'host' : ($isMember ? 'joined' : 'none'),
            'is_upcoming' => $m->isUpcoming(),
            'distance_label' => isset($m->distance_m) ? Geo::distanceLabel($m->distance_m) : null,
        ];
    }

    private function members(Collection $joined, Meetup $m, User $me): array
    {
        return $joined->sortBy(fn (MeetupMember $x) => [$x->user_id !== $m->host_id, $x->joined_at])
            ->values()->map(fn (MeetupMember $x) => AvatarSummary::of($x->user) + [
                'is_host' => $x->user_id === $m->host_id,
                'is_me' => $x->user_id === $me->id,
                'arrived' => $x->arrived_at !== null,
                'noshow' => $x->noshow_at !== null,
            ])->all();
    }

    /**
     * 조건 문구. '내 나이 ±5세'를 실제 범위로 보여주면 가운데 값이 곧 호스트 나이라
     * (예: 25~35세 → 30세) 나이 비공개 원칙이 깨진다. 그래서 범위 대신 설명으로.
     */
    private function conditionLabel(Meetup $m): string
    {
        return match ($m->age_rule) {
            '20s' => '본인인증 · 20대',
            '30s' => '본인인증 · 30대',
            'near' => '본인인증 · 호스트와 비슷한 나이 (±5세)',
            default => '본인인증 회원 누구나',
        };
    }
}
