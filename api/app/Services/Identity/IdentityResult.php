<?php

namespace App\Services\Identity;

use Carbon\CarbonImmutable;

/** 본인인증 대행사가 돌려준 결과 (CI는 해시 전 원문) */
final class IdentityResult
{
    public function __construct(
        public readonly string $ci,
        public readonly string $gender,          // M | F
        public readonly CarbonImmutable $birthDate,
        public readonly string $provider,
    ) {}
}
