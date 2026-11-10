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

        if ($user && $user->status !== User::STATUS_ACTIVE) {
            return response()->json([
                'code' => 'ACCOUNT_'.strtoupper($user->status),
                'message' => '이용이 제한된 계정이에요',
            ], 403);
        }

        return $next($request);
    }
}
