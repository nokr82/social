<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\ChatMessageResource;
use App\Models\Plaza;
use App\Services\Plaza\PlazaService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class PlazaMessageController extends Controller
{
    public function __construct(private readonly PlazaService $plazas) {}

    /** GET /api/plazas/{plaza}/messages?before_id — 최신 50개, 위로 스크롤 시 before_id */
    public function index(Request $request, Plaza $plaza): AnonymousResourceCollection
    {
        $data = $request->validate(['before_id' => ['nullable', 'integer', 'min:1']]);

        return ChatMessageResource::collection(
            $this->plazas->messages($request->user(), $plaza, $data['before_id'] ?? null)
        );
    }

    /** POST /api/plazas/{plaza}/messages  {body} */
    public function store(Request $request, Plaza $plaza): JsonResponse
    {
        $data = $request->validate(['body' => ['required', 'string', 'max:500']]);
        $body = trim($data['body']);
        abort_if($body === '', 422, '빈 메시지는 보낼 수 없어요');

        $message = $this->plazas->sendMessage($request->user(), $plaza, $body);

        return (new ChatMessageResource($message))->response()->setStatusCode(201);
    }
}
