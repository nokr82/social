<?php

namespace App\Services\Photo;

use App\Exceptions\DomainException;
use Illuminate\Http\UploadedFile;

/**
 * 업로드 이미지 정규화.
 *
 * 왜 다시 저장하나: 폰 사진의 EXIF에는 촬영 위치(GPS)·기기 정보가 들어 있다.
 * 원본을 그대로 두면 "정확한 위치는 저장·노출하지 않는다"는 설계 원칙이 사진으로 깨진다.
 * GD로 픽셀만 다시 그려 JPEG로 저장하면 메타데이터는 따라오지 않는다.
 *
 * 처리 순서: 디코딩 → EXIF 회전 반영(세로 사진이 눕지 않게) → 긴 변 1280px로 축소 → JPEG 85%
 */
class ImageProcessor
{
    private const MAX_SIDE = 1280;
    private const QUALITY = 85;

    /** @return string JPEG 바이너리 (메타데이터 없음) */
    public function normalize(UploadedFile $file): string
    {
        $bytes = file_get_contents($file->getRealPath());
        $image = @imagecreatefromstring($bytes);
        if ($image === false) {
            throw new DomainException('INVALID_IMAGE', '이미지를 읽을 수 없어요. 다른 사진을 골라주세요');
        }

        $image = $this->applyOrientation($image, $file);
        $image = $this->downscale($image);

        ob_start();
        imagejpeg($image, null, self::QUALITY);
        imagedestroy($image);

        return ob_get_clean();
    }

    /** EXIF Orientation 값대로 회전 (폰 카메라는 픽셀 대신 이 값으로 방향을 기록) */
    private function applyOrientation(\GdImage $image, UploadedFile $file): \GdImage
    {
        if (! function_exists('exif_read_data') || ! in_array($file->getMimeType(), ['image/jpeg', 'image/jpg'], true)) {
            return $image;
        }

        $orientation = @exif_read_data($file->getRealPath())['Orientation'] ?? 1;
        $angle = [3 => 180, 6 => -90, 8 => 90][$orientation] ?? 0;

        return $angle ? imagerotate($image, $angle, 0) : $image;
    }

    private function downscale(\GdImage $image): \GdImage
    {
        $w = imagesx($image);
        $h = imagesy($image);
        $scale = self::MAX_SIDE / max($w, $h);
        if ($scale >= 1) {
            return $image;
        }

        $resized = imagescale($image, (int) round($w * $scale), (int) round($h * $scale), IMG_BICUBIC);
        imagedestroy($image);

        return $resized;
    }
}
