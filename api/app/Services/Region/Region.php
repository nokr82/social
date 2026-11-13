<?php

namespace App\Services\Region;

/**
 * 동네 하나 = 동네 광장 하나.
 * - boundary 드라이버: code = 'SGG-11680'(시군구), name = '강남구'
 * - kakao 드라이버  : code = 법정동 코드 10자리, name = '역삼동'
 */
final class Region
{
    public function __construct(
        public readonly string $code,
        public readonly string $name,
    ) {}
}
