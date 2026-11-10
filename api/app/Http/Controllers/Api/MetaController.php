<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;

class MetaController extends Controller
{
    /** GET /api/meta/categories — 메인 화면 카테고리 탭 */
    public function categories(): JsonResponse
    {
        $data = collect(config('social.categories'))
            ->map(fn ($label, $code) => ['code' => $code, 'label' => $label])
            ->values();

        return response()->json(['data' => $data]);
    }

    /** GET /api/meta/report-reasons — 신고 사유 목록 */
    public function reportReasons(): JsonResponse
    {
        $data = collect(config('social.report_reasons'))
            ->map(fn ($label, $code) => ['code' => $code, 'label' => $label])
            ->values();

        return response()->json(['data' => $data]);
    }
}
