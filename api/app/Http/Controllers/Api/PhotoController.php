<?php

namespace App\Http\Controllers\Api;

use App\Exceptions\DomainException;
use App\Http\Controllers\Controller;
use App\Models\ProfilePhoto;
use App\Services\Photo\PhotoService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\Response;

class PhotoController extends Controller
{
    public function __construct(private readonly PhotoService $photos) {}

    /** GET /api/me/photos — 내 사진 전부 (공개·비공개) */
    public function index(Request $request): JsonResponse
    {
        $me = $request->user();

        return response()->json(['data' => $this->photos->present($me->photos()->get(), $me)]);
    }

    /** POST /api/me/photos  (multipart: photo) */
    public function store(Request $request): JsonResponse
    {
        $request->validate([
            // 10MB, 흔한 폰 사진 형식. HEIC는 브라우저가 업로드 전 JPEG로 바꿔 보냄
            'photo' => ['required', 'file', 'image', 'mimes:jpeg,jpg,png,webp', 'max:10240'],
        ]);

        $photo = $this->photos->upload($request->user(), $request->file('photo'));

        return response()->json(['data' => $this->photos->present([$photo], $request->user())[0]], 201);
    }

    /** PATCH /api/me/photos/{photo}  {visibility} */
    public function update(Request $request, ProfilePhoto $photo): JsonResponse
    {
        $this->assertOwner($request, $photo);
        $data = $request->validate(['visibility' => ['required', Rule::in([ProfilePhoto::PUBLIC, ProfilePhoto::PRIVATE])]]);

        $photo = $this->photos->setVisibility($photo, $data['visibility']);

        return response()->json(['data' => $this->photos->present([$photo], $request->user())[0]]);
    }

    /** DELETE /api/me/photos/{photo} */
    public function destroy(Request $request, ProfilePhoto $photo): JsonResponse
    {
        $this->assertOwner($request, $photo);
        $this->photos->delete($photo);

        return response()->json(['status' => 'ok']);
    }

    /** POST /api/photos/{photo}/like — 하트 토글 */
    public function like(Request $request, ProfilePhoto $photo): JsonResponse
    {
        return response()->json($this->photos->toggleLike($request->user(), $photo));
    }

    /**
     * GET /api/photos/{photo}/file?expires&signature — 로컬 저장소용 서명 URL.
     * 서명 자체가 '권한 확인을 통과해 발급된 URL'이라는 증거라 토큰 없이 <img>로 열 수 있다.
     */
    public function file(ProfilePhoto $photo): Response
    {
        return Storage::disk(config('social.photos.disk'))->response($photo->path, null, [
            'Cache-Control' => 'private, max-age=1800', // URL 유효시간(30분)만큼만 브라우저 캐시
        ]);
    }

    private function assertOwner(Request $request, ProfilePhoto $photo): void
    {
        if ($photo->user_id !== $request->user()->id) {
            throw new DomainException('NOT_FOUND', '사진을 찾을 수 없어요', 404);
        }
    }
}
