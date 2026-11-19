<?php

namespace App\Services\Share;

use App\Exceptions\DomainException;
use App\Models\Meetup;
use App\Models\Plaza;
use App\Models\PlazaMember;
use App\Models\User;
use App\Services\Plaza\PlazaPresence;
use App\Services\Region\RegionResolver;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\UniqueConstraintViolationException;
use RuntimeException;
use Throwable;

/**
 * 광장·번개 공유 링크와 공개 미리보기 내용.
 *
 * 미리보기는 로그인 없이 보이는 페이지라 '밖에 보여도 되는 것'만 고른다:
 * - 광장: 이름·카테고리·설명·시군구·참여/접속 인원. 대화 내용·좌표는 넣지 않음 (대화는 참여자만 읽는 원칙)
 * - 번개: 제목·공개 장소 이름·시간·인원·조건 문구. 정확한 장소(place_detail)·호스트 나이는 넣지 않음
 */
class ShareService
{
    // 헷갈리는 글자(0·o·1·l·i)를 뺀 소문자+숫자 → 말로 불러주거나 옮겨 적어도 덜 틀림
    private const ALPHABET = 'abcdefghjkmnpqrstuvwxyz23456789';
    private const LENGTH = 8;

    private const CARD_VERSION = 1;          // 카드 디자인을 바꾸면 올림 → 모든 이미지 주소가 바뀌어 카톡도 새로 받음
    private const CARD_DIR = 'app/share-og'; // storage 아래 (공개 폴더 아님 — 컨트롤러가 내보냄)

    public function __construct(
        private readonly PlazaPresence $presence,
        private readonly RegionResolver $regions,
    ) {}

    /**
     * 광장 공유 링크. 참여자·방장만 만들 수 있다
     * (안에 있는 사람이 퍼뜨리는 기능 — 강퇴된 사람이 링크를 뿌리는 것도 막힘)
     */
    public function plazaLink(User $user, Plaza $plaza): array
    {
        if ($plaza->isTown()) {
            throw new DomainException('NOT_SHAREABLE', '동네 광장은 공유할 수 없어요', 409);
        }
        if ($plaza->status !== Plaza::STATUS_OPEN) {
            throw new DomainException('PLAZA_CLOSED', '닫힌 광장이에요', 410);
        }
        $isMember = PlazaMember::where('plaza_id', $plaza->id)->where('user_id', $user->id)->exists();
        if ((int) $plaza->host_id !== (int) $user->id && ! $isMember) {
            throw new DomainException('NOT_IN_PLAZA', '광장에 참여한 사람만 공유할 수 있어요', 403);
        }

        return [
            'url' => url('/s/p/'.$this->ensureCode($plaza)),
            'title' => $plaza->name,
            'text' => "소셜링 광장 '{$plaza->name}'에 놀러 와요",
        ];
    }

    /** 번개 공유 링크. 번개 상세를 볼 수 있는 사람(호스트와 차단 관계 아님)이면 누구나, 시작 전 번개만 */
    public function meetupLink(User $user, Meetup $meetup): array
    {
        if ($user->isBlockedWith($meetup->host_id)) {
            throw new DomainException('NOT_FOUND', '없는 번개예요', 404); // 차단 여부를 드러내지 않음
        }
        if (! $meetup->isUpcoming()) {
            throw new DomainException('MEETUP_ENDED', '끝났거나 취소된 번개예요', 410);
        }

        return [
            'url' => url('/s/m/'.$this->ensureCode($meetup)),
            'title' => $meetup->title,
            'text' => "소셜링 번개 '{$meetup->title}' 같이 가요",
        ];
    }

    /** 미리보기 페이지에 넣을 광장 정보 (밖에 보여도 되는 것만) */
    public function plazaPreview(Plaza $plaza): array
    {
        $open = $plaza->status === Plaza::STATUS_OPEN && ! $plaza->isTown();
        $region = $this->regionName($plaza);
        $members = PlazaMember::where('plaza_id', $plaza->id)->count();
        $live = $open ? $this->presence->count($plaza->id) : 0;
        $category = config('social.categories')[$plaza->category] ?? null;

        return [
            'kind' => '광장',
            'ended' => ! $open,
            'title' => $plaza->name,
            'badge' => $category,
            'lines' => array_values(array_filter([
                $region,
                $open ? "참여 {$members}명 · 지금 {$live}명 접속" : null,
            ])),
            'description' => $open ? $plaza->description : null,
            // 카톡 카드 이미지·페이지 칩: 자주 바뀌는 '지금 접속 수'는 빼서 이미지가 계속 새로 만들어지지 않게
            'chips' => $open ? array_values(array_filter([$category, $region, "참여 {$members}명"])) : [],
            'progress' => null,
            'og_description' => $open
                ? implode(' · ', array_filter([$category, $region, "참여 {$members}명"]))
                    .($plaza->description ? ' — '.mb_strimwidth($plaza->description, 0, 60, '…') : '')
                : '닫힌 광장이에요',
            'app_path' => "/plaza/{$plaza->id}",
            'note' => '광장에는 이 광장에서 5km 안에 있을 때 처음 들어갈 수 있어요',
        ];
    }

    /** 미리보기 페이지에 넣을 번개 정보 (정확한 장소·호스트 나이 제외) */
    public function meetupPreview(Meetup $meetup): array
    {
        $open = $meetup->isUpcoming();
        $joined = $meetup->joined()->count();
        $when = $this->koreanWhen($meetup);

        return [
            'kind' => '번개',
            'ended' => ! $open,
            'title' => $meetup->title,
            'badge' => $open ? "{$joined}/{$meetup->capacity}명" : null,
            'lines' => $open ? array_values(array_filter([$when, $meetup->area_label, $meetup->conditionLabel()])) : [],
            'description' => $open ? $meetup->description : null,
            'chips' => $open ? array_values(array_filter([$when, $meetup->area_label, "{$joined}/{$meetup->capacity}명"])) : [],
            'progress' => $open ? ['joined' => $joined, 'capacity' => $meetup->capacity] : null, // 페이지의 인원 막대
            'og_description' => $open
                ? implode(' · ', [$when, $meetup->area_label, "{$joined}/{$meetup->capacity}명"])
                : '끝났거나 취소된 번개예요',
            'app_path' => "/meetups/{$meetup->id}",
            'note' => '정확한 장소는 참여하면 보여요',
        ];
    }

    /**
     * 코드가 없으면 만든다. "아직 코드가 없을 때만" 조건부 UPDATE → 동시에 두 번 눌러도 코드는 하나.
     * 다른 행과 겹치면(UNIQUE) 새로 뽑아 다시 시도.
     */
    private function ensureCode(Model $model): string
    {
        if ($model->share_code) {
            return $model->share_code;
        }
        for ($i = 0; $i < 5; $i++) {
            try {
                $model->newQuery()->whereKey($model->getKey())->whereNull('share_code')
                    ->update(['share_code' => $this->randomCode()]);
            } catch (UniqueConstraintViolationException) {
                continue;
            }
            $code = $model->newQuery()->whereKey($model->getKey())->value('share_code');
            if ($code) {
                $model->share_code = $code;

                return $code;
            }
        }
        throw new RuntimeException('공유 코드를 만들지 못했습니다');
    }

    private function randomCode(): string
    {
        $max = strlen(self::ALPHABET) - 1;
        $code = '';
        for ($i = 0; $i < self::LENGTH; $i++) {
            $code .= self::ALPHABET[random_int(0, $max)];
        }

        return $code;
    }

    /** 시·군·구 이름. 경계 데이터가 없거나 실패해도 미리보기는 떠야 하므로 조용히 생략 */
    private function regionName(Plaza $plaza): ?string
    {
        try {
            return $this->regions->resolve((float) $plaza->lat, (float) $plaza->lng)?->name;
        } catch (Throwable) {
            return null;
        }
    }

    /** '11월 18일 (화) 19:30' — 미리보기는 서버에서 그리므로 한국 시간으로 직접 */
    private function koreanWhen(Meetup $meetup): string
    {
        $t = $meetup->starts_at->copy()->timezone('Asia/Seoul');
        $days = ['일', '월', '화', '수', '목', '금', '토'];

        return $t->format('n월 j일').' ('.$days[$t->dayOfWeek].') '.$t->format('H:i');
    }

    /**
     * 카톡 카드 이미지 (1200×630 PNG). 미리보기에서 그림에 들어가는 항목만 뽑아 해시 → 파일 이름.
     * - 내용이 같으면 같은 파일을 다시 씀(링크를 열 때마다 그리지 않음)
     * - 내용이 바뀌면(인원 3→4명, 끝남) 해시가 바뀌어 og:image 주소도 바뀜 → 카톡이 예전 그림을 계속 보여주지 않음
     *
     * @return array{0: string, 1: string} [해시, 파일 경로]
     */
    public function cardImage(array $preview): array
    {
        $card = [
            'kind' => $preview['kind'],
            'title' => $preview['title'],
            'chips' => $preview['chips'],
            'ended' => $preview['ended'],
        ];
        $hash = substr(sha1(json_encode($card + ['v' => self::CARD_VERSION], JSON_UNESCAPED_UNICODE)), 0, 16);
        $dir = storage_path(self::CARD_DIR);
        $path = "{$dir}/{$hash}.png";

        if (! is_file($path)) {
            if (! is_dir($dir)) {
                @mkdir($dir, 0775, true);
            }
            $png = (new ShareImage(resource_path('fonts'), public_path('icons/app-icon-192.png')))->render($card);
            // 임시 파일에 쓰고 이름 바꾸기: 동시에 두 요청이 같은 그림을 만들어도 반쯤 쓴 파일을 내보내지 않게
            $tmp = "{$path}.".bin2hex(random_bytes(4));
            file_put_contents($tmp, $png);
            rename($tmp, $path);
            $this->pruneCards($dir);
        }

        return [$hash, $path];
    }

    /** 오래된 카드 이미지 정리: 새로 만들 때 가끔(1%) 7일 지난 파일 삭제 (인원이 바뀔 때마다 파일이 생기므로) */
    private function pruneCards(string $dir): void
    {
        if (random_int(1, 100) !== 1) {
            return;
        }
        foreach (glob("{$dir}/*.png") ?: [] as $file) {
            if (filemtime($file) < now()->subDays(7)->getTimestamp()) {
                @unlink($file);
            }
        }
    }
}
