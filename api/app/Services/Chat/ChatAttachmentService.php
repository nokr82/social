<?php

namespace App\Services\Chat;

use App\Exceptions\DomainException;
use App\Models\ChatMessage;
use App\Models\ChatRoom;
use App\Models\Meetup;
use App\Models\User;
use App\Services\Photo\ImageProcessor;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;

/**
 * 채팅 첨부 (사진 · 번개 카드).
 * 사진은 프로필 사진과 같은 원칙:
 * - GD로 다시 그려 EXIF(촬영 위치 등) 제거 + 긴 변 ImageProcessor::MAX_SIDE(1280px)로 축소
 * - 추측 불가능한 파일명, 30분짜리 서명 URL로만 접근 (공개 URL 없음)
 */
class ChatAttachmentService
{
    public function __construct(private readonly ImageProcessor $images) {}

    private function disk()
    {
        return Storage::disk(config('social.photos.disk'));
    }

    /** @return array{0:string, 1:array} [body, meta] */
    public function storeImage(ChatRoom $room, UploadedFile $file): array
    {
        $jpeg = $this->images->normalize($file);
        [$w, $h] = getimagesizefromstring($jpeg) ?: [0, 0];

        // 방 ID 폴더로 묶어, 방 정리 시 폴더째 지우기 쉽게
        $path = "chat/{$room->id}/".Str::uuid().'.jpg';
        $this->disk()->put($path, $jpeg);

        return ['사진', ['path' => $path, 'w' => $w, 'h' => $h]];
    }

    /**
     * 공유할 번개 검증: 시작 전 + 내가 참여 중 + 호스트와 차단 관계 아님
     * @return array{0:string, 1:array} [body, meta]
     */
    public function meetupCard(User $me, int $meetupId): array
    {
        $meetup = Meetup::upcoming()->find($meetupId);
        if (! $meetup || $me->isBlockedWith($meetup->host_id)) {
            throw new DomainException('MEETUP_NOT_FOUND', '공유할 수 없는 번개예요', 404);
        }
        if (! $meetup->joined()->where('user_id', $me->id)->exists()) {
            throw new DomainException('NOT_MEMBER', '내가 참여 중인 번개만 공유할 수 있어요', 403);
        }

        return [$meetup->title, ['meetup_id' => $meetup->id]];
    }

    /** 사진 URL (30분). S3/R2는 저장소 서명, 로컬은 우리 서버 서명 라우트 */
    public function imageUrl(ChatMessage $message): ?string
    {
        $path = $message->meta['path'] ?? null;
        if (! $path) {
            return null;
        }
        $expires = now()->addMinutes(30);
        if (config('filesystems.disks.'.config('social.photos.disk').'.driver') === 's3') {
            return $this->disk()->temporaryUrl($path, $expires);
        }

        return URL::temporarySignedRoute('chat-images.file', $expires, ['message' => $message->id], absolute: false);
    }

    /** 번개 카드 내용 (볼 때마다 최신: 인원·상태가 바뀌어도 맞게). 지워졌으면 null */
    public function meetupSummary(ChatMessage $message): ?array
    {
        $meetup = Meetup::with('plaza')->withCount('joined')->find($message->meta['meetup_id'] ?? 0);

        return $meetup ? [
            'id' => $meetup->id,
            'title' => $meetup->title,
            'starts_at' => $meetup->starts_at->toIso8601String(),
            'area_label' => $meetup->area_label,
            'plaza_name' => $meetup->plaza?->name,
            'capacity' => $meetup->capacity,
            'joined_count' => $meetup->joined_count,
            'status' => $meetup->status,
            'is_upcoming' => $meetup->isUpcoming(),
        ] : null;
    }
}
