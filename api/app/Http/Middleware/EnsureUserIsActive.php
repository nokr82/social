<?php

namespace App\Http\Middleware;

use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * 정지·탈퇴 상태면 차단. 토큰이 살아 있어도 상태를 매 요청 확인한다.
 */
class EnsureUserIsActive
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        // 기간 정지가 끝났으면 자동 해제 (웹소켓 인증처럼 'seen'을 거치지 않는 경로 대비)
        if ($user) {
            app(\App\Services\Moderation\ModerationService::class)->releaseIfExpired($user);
        }

        if ($user && $user->status !== User::STATUS_ACTIVE) {
            return response()->json([
                'code' => 'ACCOUNT_'.strtoupper($user->status),
                'message' => $user->suspended_until
                    ? '이용이 제한된 계정이에요 ('.$user->suspended_until->format('n월 j일 H:i').'까지)'
                    : '이용이 제한된 계정이에요',
                'until' => $user->suspended_until?->toIso8601String(),
            ], 403);
        }

        return $next($request);
    }
}
