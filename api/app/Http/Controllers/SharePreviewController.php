<?php

namespace App\Http\Controllers;

use App\Models\Meetup;
use App\Models\Plaza;
use App\Services\Share\ShareService;
use Illuminate\Http\Response;

/**
 * 공유 링크 미리보기 (로그인 없이 보는 공개 페이지).
 * Vue 앱이 아니라 서버가 HTML을 직접 그린다: 카카오톡 등은 링크 미리보기를 만들 때 og 태그만 읽고
 * 스크립트는 실행하지 않으므로, 앱이 나중에 넣는 태그는 보지 못한다.
 * 코드가 틀리면 404 (있는 광장인지 여부를 따로 알려 주지 않음).
 */
class SharePreviewController extends Controller
{
    public function __construct(private readonly ShareService $share) {}

    /** GET /s/p/{code} */
    public function plaza(string $code): Response
    {
        $plaza = Plaza::where('share_code', $code)->firstOrFail();

        return $this->render($this->share->plazaPreview($plaza));
    }

    /** GET /s/m/{code} */
    public function meetup(string $code): Response
    {
        $meetup = Meetup::where('share_code', $code)->firstOrFail();

        return $this->render($this->share->meetupPreview($meetup));
    }

    private function render(array $p): Response
    {
        return response()->view('share', ['p' => $p])
            // 인원이 바뀌므로 길게 캐시하지 않음 (카카오 쪽 미리보기 캐시는 별개로 남을 수 있음)
            ->header('Cache-Control', 'public, max-age=60');
    }
}
