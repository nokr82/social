<?php

namespace App\Services\Share;

/**
 * 공유 링크 카톡 카드 이미지 (1200×630 PNG, GD로 직접 그림).
 * - 카톡은 가로형(1.91:1) og:image를 큰 카드로 보여줌 → 링크마다 제목·시간·동네가 그림으로 보이게
 * - 들어가는 정보는 미리보기 페이지와 같은 허용 목록(ShareService::*Preview의 kind·title·chips·ended)만.
 *   대화·좌표·정확한 장소·참여자 닉네임은 이 클래스에 넘어오지도 않음
 * - 2배 크기(2400×1260)로 그린 뒤 줄임: GD 도형은 계단 현상(안티앨리어싱 없음)이 있어서
 * - 한글 폰트: resources/fonts/Pretendard (SIL OFL 1.1, 상업적 사용 가능 · 라이선스 파일 함께 둠)
 * - 이모지는 폰트에 없으므로 번개 아이콘 등은 도형으로 그림
 */
class ShareImage
{
    private const W = 1200;
    private const H = 630;
    private const S = 2; // 그리는 배율

    private const PRIMARY = [0x6C, 0x5C, 0xE7];
    private const PRIMARY_LIGHT = [0x7D, 0x6E, 0xEE];
    private const PRIMARY_DEEP = [0x4B, 0x3C, 0xC4];
    private const ENDED = [0x8E, 0x8A, 0xA3];
    private const ENDED_LIGHT = [0x9C, 0x98, 0xB0];
    private const INK = [0x1F, 0x1B, 0x3A];

    public function __construct(
        private readonly string $fontDir,
        private readonly ?string $iconPath = null,
    ) {}

    /**
     * @param  array{kind: string, title: string, chips: array<string>, ended: bool}  $card
     * @return string PNG 바이트
     */
    public function render(array $card): string
    {
        $s = self::S;
        $im = imagecreatetruecolor(self::W * $s, self::H * $s);
        imagealphablending($im, true);
        imagesavealpha($im, true);

        $ended = (bool) $card['ended'];
        $bg = $this->color($im, $ended ? self::ENDED : self::PRIMARY);
        imagefilledrectangle($im, 0, 0, self::W * $s, self::H * $s, $bg);

        // 오른쪽 위 큰 원 2개: 단색 배경이 밋밋하지 않게 (그라데이션 없이 평면 장식)
        $deco = $this->color($im, $ended ? self::ENDED_LIGHT : self::PRIMARY_LIGHT);
        imagefilledellipse($im, 1080 * $s, 40 * $s, 520 * $s, 520 * $s, $deco);
        imagefilledellipse($im, 1180 * $s, 420 * $s, 300 * $s, 300 * $s, $deco);

        $white = $this->color($im, [255, 255, 255]);
        $bold = $this->font('Pretendard-ExtraBold.ttf');
        $semi = $this->font('Pretendard-SemiBold.ttf');

        // ── 왼쪽 위: 로고 + 소셜링
        $x = 72;
        if ($this->iconPath && is_file($this->iconPath) && ($icon = @imagecreatefrompng($this->iconPath))) {
            $this->roundedRect($im, $x, 64, $x + 64, 128, 16, $white);
            imagecopyresampled($im, $icon, ($x + 4) * $s, 68 * $s, 0, 0, 56 * $s, 56 * $s, imagesx($icon), imagesy($icon));
            imagedestroy($icon);
            $x += 84;
        }
        $this->text($im, $semi, 34, $x, 110, $white, '소셜링');

        // ── 종류 알약 (흰 바탕 + 보라 글자): ⚡ 번개 / 광장
        $kind = $card['kind'];
        $pillX = 72;
        $pillY = 176;
        $pillText = $this->color($im, $ended ? self::INK : self::PRIMARY_DEEP);
        $tw = $this->textWidth($semi, 30, $kind);
        $iconW = $kind === '번개' ? 34 : 0;
        $this->roundedRect($im, $pillX, $pillY, $pillX + 48 + $iconW + $tw, $pillY + 60, 30, $white);
        if ($kind === '번개') {
            $this->bolt($im, $pillX + 24, $pillY + 12, $pillText);
        }
        $this->text($im, $semi, 30, $pillX + 24 + $iconW, $pillY + 42, $pillText, $kind);

        // 끝난 것: 종류 알약 옆에 '끝났어요'
        if ($ended) {
            $ex = $pillX + 48 + $iconW + $tw + 16;
            $dark = $this->color($im, self::INK);
            $ew = $this->textWidth($semi, 30, '끝났어요');
            $this->roundedRect($im, $ex, $pillY, $ex + 48 + $ew, $pillY + 60, 30, $dark);
            $this->text($im, $semi, 30, $ex + 24, $pillY + 42, $white, '끝났어요');
        }

        // ── 제목: 최대 2줄, 넘치면 …
        $lines = $this->wrap($bold, 66, $card['title'], 1000, 2);
        $ty = 330;
        foreach ($lines as $line) {
            $this->text($im, $bold, 66, 72, $ty, $white, $line);
            $ty += 88;
        }

        // ── 아래 정보 칩 (반투명 흰 알약)
        // 반투명 대신 '배경에 흰색 22%를 섞은' 불투명 색: 반투명 도형을 나눠 칠하면 겹친 이음매가 줄로 보임
        $base = $ended ? self::ENDED : self::PRIMARY;
        $chipBg = $this->color($im, array_map(fn ($c) => (int) round($c + (255 - $c) * 0.22), $base));
        $cx = 72;
        $cy = 516;
        foreach (array_slice($card['chips'], 0, 3) as $chip) {
            $chip = $this->ellipsize($semi, 28, $chip, 420);
            $w = $this->textWidth($semi, 28, $chip) + 44;
            if ($cx + $w > self::W - 72) {
                break; // 한 줄에 다 안 들어가면 뒤 칩은 생략
            }
            $this->roundedRect($im, $cx, $cy, $cx + $w, $cy + 56, 28, $chipBg);
            $this->text($im, $semi, 28, $cx + 22, $cy + 38, $white, $chip);
            $cx += $w + 12;
        }

        // 2배로 그린 것을 실제 크기로 줄임 (부드러운 가장자리)
        $out = imagecreatetruecolor(self::W, self::H);
        imagecopyresampled($out, $im, 0, 0, 0, 0, self::W, self::H, self::W * $s, self::H * $s);
        imagedestroy($im);

        ob_start();
        imagepng($out, null, 6);
        imagedestroy($out);

        return (string) ob_get_clean();
    }

    // ── 그리기 도우미 (좌표·크기는 모두 1배 기준, 안에서 배율 적용)

    private function color(\GdImage $im, array $rgb): int
    {
        return imagecolorallocate($im, ...$rgb);
    }

    private function font(string $file): string
    {
        return rtrim($this->fontDir, '/\\').DIRECTORY_SEPARATOR.$file;
    }

    private function text(\GdImage $im, string $font, int $size, int $x, int $baseline, int $color, string $text): void
    {
        // GD 글자 크기는 포인트(pt) → px로 맞추려 0.75 곱함
        imagettftext($im, $size * 0.75 * self::S, 0, $x * self::S, $baseline * self::S, $color, $font, $text);
    }

    private function textWidth(string $font, int $size, string $text): int
    {
        $b = imagettfbbox($size * 0.75, 0, $font, $text);

        return (int) ceil(abs($b[2] - $b[0]));
    }

    private function roundedRect(\GdImage $im, int $x1, int $y1, int $x2, int $y2, int $r, int $color): void
    {
        $s = self::S;
        [$x1, $y1, $x2, $y2, $r] = [$x1 * $s, $y1 * $s, $x2 * $s, $y2 * $s, $r * $s];
        // 가운데 십자 + 네 귀퉁이 1/4원으로 나눠 칠함
        imagefilledrectangle($im, $x1 + $r, $y1, $x2 - $r - 1, $y2 - 1, $color);
        imagefilledrectangle($im, $x1, $y1 + $r, $x1 + $r - 1, $y2 - $r - 1, $color);
        imagefilledrectangle($im, $x2 - $r, $y1 + $r, $x2 - 1, $y2 - $r - 1, $color);
        imagefilledarc($im, $x1 + $r, $y1 + $r, $r * 2, $r * 2, 180, 270, $color, IMG_ARC_PIE);
        imagefilledarc($im, $x2 - $r - 1, $y1 + $r, $r * 2, $r * 2, 270, 360, $color, IMG_ARC_PIE);
        imagefilledarc($im, $x1 + $r, $y2 - $r - 1, $r * 2, $r * 2, 90, 180, $color, IMG_ARC_PIE);
        imagefilledarc($im, $x2 - $r - 1, $y2 - $r - 1, $r * 2, $r * 2, 0, 90, $color, IMG_ARC_PIE);
    }

    /** 번개 모양 (폰트에 ⚡ 이모지가 없어 도형으로) — 왼쪽 위 기준 약 24×36 */
    private function bolt(\GdImage $im, int $x, int $y, int $color): void
    {
        $pts = [14, 0, 2, 20, 11, 20, 8, 36, 22, 14, 13, 14, 16, 0];
        $scaled = [];
        foreach ($pts as $i => $v) {
            $scaled[] = (($i % 2 === 0 ? $x : $y) + $v) * self::S;
        }
        imagefilledpolygon($im, $scaled, $color);
    }

    /** 폭에 맞춰 줄바꿈 (띄어쓰기 우선, 긴 단어는 글자 단위), 최대 줄 수 넘으면 마지막 줄 … */
    private function wrap(string $font, int $size, string $text, int $maxW, int $maxLines): array
    {
        $words = preg_split('/\s+/u', trim($text)) ?: [''];
        $lines = [];
        $cur = '';
        foreach ($words as $word) {
            $try = $cur === '' ? $word : "{$cur} {$word}";
            if ($this->textWidth($font, $size, $try) <= $maxW) {
                $cur = $try;
                continue;
            }
            if ($cur !== '') {
                $lines[] = $cur;
                $cur = '';
            }
            // 단어 자체가 한 줄보다 길면 글자 단위로 자름 (한글 제목은 띄어쓰기가 적을 수 있음)
            foreach (mb_str_split($word) as $ch) {
                if ($this->textWidth($font, $size, $cur.$ch) > $maxW) {
                    $lines[] = $cur;
                    $cur = '';
                }
                $cur .= $ch;
            }
        }
        if ($cur !== '') {
            $lines[] = $cur;
        }
        if (count($lines) > $maxLines) {
            $lines = array_slice($lines, 0, $maxLines);
            $lines[$maxLines - 1] = $this->ellipsize($font, $size, $lines[$maxLines - 1].' …', $maxW, force: true);
        }

        return $lines;
    }

    private function ellipsize(string $font, int $size, string $text, int $maxW, bool $force = false): string
    {
        if (! $force && $this->textWidth($font, $size, $text) <= $maxW) {
            return $text;
        }
        $base = rtrim(preg_replace('/\s*…$/u', '', $text));
        while ($base !== '' && $this->textWidth($font, $size, $base.'…') > $maxW) {
            $base = mb_substr($base, 0, -1);
        }

        return rtrim($base).'…';
    }
}
