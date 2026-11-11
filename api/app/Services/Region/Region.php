<?php

namespace App\Services\Region;

/** 동네(법정동) 하나. code = 법정동 코드 10자리, name = '역삼동' */
final class Region
{
    public function __construct(
        public readonly string $code,
        public readonly string $name,
    ) {}
}
