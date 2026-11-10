<?php

namespace App\Http\Controllers\Api;

use App\Exceptions\DomainException;
use App\Http\Controllers\Controller;
use App\Models\Block;
use App\Models\Report;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/**
 * 차단·신고. 신뢰·안전은 1단계부터 포함한다는 설계 원칙.
 */
class SafetyController extends Controller
{
    /** POST /api/blocks  {user_id} */
    public function block(Request $request): JsonResponse
    {
        $data = $request->validate(['user_id' => ['required', 'integer', 'exists:users,id']]);
        $me = $request->user();

        if ((int) $data['user_id'] === $me->id) {
            throw new DomainException('SELF_BLOCK', '자기 자신은 차단할 수 없어요');
        }

        Block::query()->insertOrIgnore(['blocker_id' => $me->id, 'blocked_id' => $data['user_id'], 'created_at' => now()]);

        return response()->json(['status' => 'ok']);
    }

    /** DELETE /api/blocks/{userId} */
    public function unblock(Request $request, int $userId): JsonResponse
    {
        Block::where('blocker_id', $request->user()->id)->where('blocked_id', $userId)->delete();

        return response()->json(['status' => 'ok']);
    }

    /** GET /api/blocks — 앱이 실시간 메시지를 걸러낼 때 사용 (양방향 ID 목록) */
    public function blocks(Request $request): JsonResponse
    {
        return response()->json(['user_ids' => $request->user()->blockedUserIds()]);
    }

    /**
     * POST /api/reports  {user_id, target_type, target_ref_id?, reason, detail?, block?}
     * 서로 다른 신고자가 일정 수 이상이면 자동 임시정지 → 관리자 검토.
     */
    public function report(Request $request): JsonResponse
    {
        $data = $request->validate([
            'user_id' => ['required', 'integer', 'exists:users,id'],
            'target_type' => ['required', Rule::in(['user', 'message', 'plaza'])],
            'target_ref_id' => ['nullable', 'integer', 'required_unless:target_type,user'],
            'reason' => ['required', Rule::in(array_keys(config('social.report_reasons')))],
            'detail' => ['nullable', 'string', 'max:500'],
            'block' => ['nullable', 'boolean'],   // 디자인: '차단도 함께하기' (기본 ON)
        ]);
        $me = $request->user();

        if ((int) $data['user_id'] === $me->id) {
            throw new DomainException('SELF_REPORT', '자기 자신은 신고할 수 없어요');
        }

        DB::transaction(function () use ($data, $me) {
            // 같은 대상 중복 신고는 1건으로 (NULL은 UNIQUE에서 걸러지지 않으므로 코드로 처리)
            Report::firstOrCreate(
                [
                    'reporter_id' => $me->id,
                    'target_id' => $data['user_id'],
                    'target_type' => $data['target_type'],
                    'target_ref_id' => $data['target_ref_id'] ?? null,
                ],
                ['reason' => $data['reason'], 'detail' => $data['detail'] ?? null],
            );

            $distinctReporters = Report::where('target_id', $data['user_id'])
                ->where('status', '!=', Report::STATUS_REJECTED)
                ->distinct()->count('reporter_id');

            if ($distinctReporters >= config('social.trust.auto_suspend_reports')) {
                User::whereKey($data['user_id'])
                    ->where('status', User::STATUS_ACTIVE)
                    ->update(['status' => User::STATUS_SUSPENDED]);
            }
        });

        // '차단도 함께'가 켜져 있으면(기본값) 차단까지
        if ($data['block'] ?? true) {
            Block::query()->insertOrIgnore(['blocker_id' => $me->id, 'blocked_id' => $data['user_id'], 'created_at' => now()]);
        }

        return response()->json(['status' => 'ok'], 201);
    }
}
