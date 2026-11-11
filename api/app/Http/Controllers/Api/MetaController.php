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

    /** GET /api/meta/kick-reasons — 방장 강퇴 사유 목록 */
    public function kickReasons(): JsonResponse
    {
        $data = collect(config('social.plaza.kick_reasons'))
            ->map(fn ($label, $code) => ['code' => $code, 'label' => $label])
            ->values();

        return response()->json(['data' => $data]);
    }

        /** GET /api/meta/push — 브라우저 푸시 구독에 필요한 공개키 (비공개키는 서버에만) */
    public function push(): JsonResponse
    {
        return response()->json(['public_key' => config('webpush.vapid.public_key')]);
    }
}
