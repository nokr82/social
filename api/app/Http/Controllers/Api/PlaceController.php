<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Plaza;
use App\Services\Place\PlaceService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PlaceController extends Controller
{
    public function __construct(private readonly PlaceService $places) {}

    /**
     * GET /api/places/search?q&plaza_id&lat?&lng? — 번개 장소 검색 (카카오 키워드 검색)
     * 결과는 그 광장 번개로 열 수 있는 장소만. 좌표(lat,lng)는 검색 중심에만 쓰고 저장 안 함
     */
    public function search(Request $request): JsonResponse
    {
        $data = $request->validate([
            'q' => ['required', 'string', 'min:1', 'max:40'],
            'plaza_id' => ['required', 'integer'],
            'lat' => ['nullable', 'numeric', 'between:-90,90', 'required_with:lng'],
            'lng' => ['nullable', 'numeric', 'between:-180,180', 'required_with:lat'],
        ]);
        $plaza = Plaza::findOrFail($data['plaza_id']);

        $results = $this->places->search(
            $plaza,
            trim($data['q']),
            isset($data['lat']) ? (float) $data['lat'] : null,
            isset($data['lng']) ? (float) $data['lng'] : null,
        );

        return response()->json(['data' => $results]);
    }
}
