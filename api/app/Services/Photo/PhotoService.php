<?php

namespace App\Services\Photo;

use App\Exceptions\DomainException;
use App\Models\PhotoLike;
use App\Models\ProfilePhoto;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;

/**
 * 프로필 사진: 업로드·공개 전환·삭제·하트·보기 권한·URL 발급.
 *
 * 보기 권한 (canView)
 *   본인         → 전부
 *   친구         → 공개 + 비공개
 *   그 외        → 공개만
 *   차단 관계    → 없음
 */
class PhotoService
{
    public function __construct(private readonly ImageProcessor $images) {}

    private function disk()
    {
        return Storage::disk(config('social.photos.disk'));
    }

    public function upload(User $user, UploadedFile $file): ProfilePhoto
    {
        $count = $user->photos()->count();
        if ($count >= ProfilePhoto::MAX_PER_USER) {
            throw new DomainException('PHOTO_LIMIT', '사진은 최대 '.ProfilePhoto::MAX_PER_USER.'장까지 올릴 수 있어요', 409);
        }

        // 파일 이름은 추측할 수 없게 무작위 (사용자 ID 폴더로 묶어 탈퇴 시 일괄 삭제 쉽게)
        $path = "photos/{$user->id}/".Str::uuid().'.jpg';
        $this->disk()->put($path, $this->images->normalize($file));

        return ProfilePhoto::create([
            'user_id' => $user->id,
            'path' => $path,
            'visibility' => ProfilePhoto::PUBLIC,      // 기본 공개, MY에서 비공개로 전환
            'sort_order' => ($user->photos()->max('sort_order') ?? -1) + 1,
        ]);
    }

    public function setVisibility(ProfilePhoto $photo, string $visibility): ProfilePhoto
    {
        if ($visibility === ProfilePhoto::PRIVATE && $this->isMain($photo)) {
            throw new DomainException('MAIN_PHOTO_PUBLIC', '대표사진은 항상 공개돼요');
        }
        $photo->update(['visibility' => $visibility]); // like_count는 그대로 (하트 유지)

        return $photo;
    }

    /** 삭제 후 순서를 당기고, 새 대표사진이 비공개였다면 공개로 바꾼다 */
    public function delete(ProfilePhoto $photo): void
    {
        $user = $photo->user;
        if ($user->photos()->count() === 1) {
            throw new DomainException('MAIN_PHOTO_REQUIRED', '대표사진은 최소 1장 있어야 해요', 409);
        }

        DB::transaction(function () use ($photo, $user) {
            $photo->delete();
            $user->photos()->get()->values()->each(function (ProfilePhoto $p, int $i) {
                $p->update([
                    'sort_order' => $i,
                    'visibility' => $i === 0 ? ProfilePhoto::PUBLIC : $p->visibility,
                ]);
            });
        });
        $this->disk()->delete($photo->path);
    }

    /**
     * 하트 토글: 없으면 추가(+1), 있으면 취소(-1). 결과 상태와 개수를 돌려준다.
     *
     * @return array{liked: bool, like_count: int}
     */
    public function toggleLike(User $user, ProfilePhoto $photo): array
    {
        if ($photo->user_id === $user->id) {
            throw new DomainException('OWN_PHOTO', '내 사진에는 하트를 누를 수 없어요');
        }
        if (! $this->canView($user, $photo)) {
            throw new DomainException('NOT_FOUND', '사진을 찾을 수 없어요', 404);
        }

        $liked = DB::transaction(function () use ($user, $photo) {
            $deleted = PhotoLike::where('photo_id', $photo->id)->where('user_id', $user->id)->delete();
            if ($deleted) {
                ProfilePhoto::whereKey($photo->id)->where('like_count', '>', 0)->decrement('like_count');

                return false;
            }
            // 동시에 두 번 눌려도 PK(photo_id, user_id)가 중복을 막음
            PhotoLike::query()->insertOrIgnore(['photo_id' => $photo->id, 'user_id' => $user->id, 'created_at' => now()]);
            ProfilePhoto::whereKey($photo->id)->increment('like_count');

            return true;
        });

        return ['liked' => $liked, 'like_count' => (int) $photo->fresh()->like_count];
    }

    public function canView(User $viewer, ProfilePhoto $photo): bool
    {
        if ($photo->user_id === $viewer->id) {
            return true;
        }
        if ($viewer->isBlockedWith($photo->user_id)) {
            return false;
        }

        return $photo->isPublic() || $viewer->isFriendWith($photo->user_id);
    }

    public function isMain(ProfilePhoto $photo): bool
    {
        return (int) $photo->sort_order === 0;
    }

    /**
     * 사진 URL (30분 유효).
     * - S3/R2: 저장소가 직접 서명한 임시 URL
     * - 로컬: 우리 서버의 서명 라우트 (/api/photos/{id}/file?signature=...)
     * 공개 URL을 만들지 않으므로, URL을 받은 사람(=권한 확인을 통과한 사람)만 볼 수 있다.
     */
    public function url(ProfilePhoto $photo): string
    {
        $expires = now()->addMinutes(30);

        if (config('filesystems.disks.'.config('social.photos.disk').'.driver') === 's3') {
            return $this->disk()->temporaryUrl($photo->path, $expires);
        }

        // absolute=false: 접속 도메인(social.local 등)이 APP_URL과 달라도 서명이 맞도록 경로만 서명
        return URL::temporarySignedRoute('photos.file', $expires, ['photo' => $photo->id], absolute: false);
    }

    /** 사진 목록 → 응답용 배열 (URL·하트·내가 눌렀는지) */
    public function present(iterable $photos, User $viewer): array
    {
        $photos = collect($photos);
        $likedIds = PhotoLike::where('user_id', $viewer->id)
            ->whereIn('photo_id', $photos->pluck('id'))->pluck('photo_id')->all();

        return $photos->values()->map(fn (ProfilePhoto $p, int $i) => [
            'id' => $p->id,
            'url' => $this->url($p),
            'visibility' => $p->visibility,
            // 순서는 업로드·삭제 때 항상 0부터 빈틈없이 유지 → 0번이 대표사진
            'is_main' => (int) $p->sort_order === 0,
            'like_count' => $p->like_count,
            'liked' => in_array($p->id, $likedIds, false),
        ])->all();
    }
}
