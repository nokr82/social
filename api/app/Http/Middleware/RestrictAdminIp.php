<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * 관리자 화면(/admin) IP 제한. ADMIN_ALLOWED_IPS="1.2.3.4,5.6.7.8"
 * 비어 있으면 제한하지 않음(로그인만으로 보호). 허용 목록 밖이면 화면이 있다는 것도 숨기려고 404.
 */
class RestrictAdminIp
{
    public function handle(Request $request, Closure $next): Response
    {
        $allowed = array_filter(array_map('trim', explode(',', (string) config('social.admin_allowed_ips'))));

        if ($allowed && ! in_array($request->ip(), $allowed, true)) {
            abort(404);
        }

        return $next($request);
    }
}
