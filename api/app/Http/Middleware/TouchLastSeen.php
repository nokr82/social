<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Symfony\Component\HttpFoundation\Response;

/**
 * '지금 접속 중 / 3시간 전 접속' 표시용 마지막 활동 시각.
 * 요청마다 DB에 쓰면 낭비라 사용자당 1분에 한 번만 갱신한다 (캐시 add = 없을 때만 성공).
 */
class TouchLastSeen
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        if ($user && Cache::add("last_seen:{$user->id}", 1, 60)) {
            $user->forceFill(['last_seen_at' => now()])->saveQuietly();
        }

        return $next($request);
    }
}
