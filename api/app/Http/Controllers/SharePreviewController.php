<?php

namespace App\Http\Controllers;

use App\Models\Meetup;
use App\Models\Plaza;
use App\Services\Share\ShareService;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

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

        return $this->render($this->share->plazaPreview($plaza), "/s/p/{$code}/og.png");
    }

    /** GET /s/p/{code}/og.png — 카톡 카드 이미지 */
    public function plazaImage(Request $request, string $code): BinaryFileResponse
    {
        $plaza = Plaza::where('share_code', $code)->firstOrFail();

        return $this->image($request, $this->share->plazaPreview($plaza));
    }

    /** GET /s/m/{code} */
    public function meetup(string $code): Response
    {
        $meetup = Meetup::where('share_code', $code)->firstOrFail();

        return $this->render($this->share->meetupPreview($meetup), "/s/m/{$code}/og.png");
    }

    /** GET /s/m/{code}/og.png — 카톡 카드 이미지 */
    public function meetupImage(Request $request, string $code): BinaryFileResponse
    {
        $meetup = Meetup::where('share_code', $code)->firstOrFail();

        return $this->image($request, $this->share->meetupPreview($meetup));
    }

    private function render(array $p, string $imagePath): Response
    {
        [$hash] = $this->share->cardImage($p);

        return response()->view('share', ['p' => $p, 'ogImage' => url($imagePath).'?v='.$hash])
            // 인원이 바뀌므로 길게 캐시하지 않음 (카카오 쪽 미리보기 캐시는 별개로 남을 수 있음)
            ->header('Cache-Control', 'public, max-age=60');
    }

    /**
     * 이미지 응답. 주소의 v가 지금 내용과 같으면 오래 캐시(내용이 바뀌면 주소가 바뀌므로 안전),
     * 다르거나 없으면(옛 주소) 지금 그림을 짧게 캐시해서 내보냄
     */
    private function image(Request $request, array $p): BinaryFileResponse
    {
        [$hash, $path] = $this->share->cardImage($p);
        $fresh = $request->query('v') === $hash;

        return response()->file($path, [
            'Content-Type' => 'image/png',
            'Cache-Control' => $fresh ? 'public, max-age=604800, immutable' : 'public, max-age=300',
        ]);
    }
}
