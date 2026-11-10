<?php

namespace App\Services\Identity;

use Carbon\CarbonImmutable;

/**
 * 대행사 계약 전 개발용. 거래 ID 형식: "fake:{CI}:{M|F}:{YYYYMMDD}"
 * 예) fake:ci-abc:F:19980312
 */
class FakeIdentityVerifier implements IdentityVerifier
{
    public function fetch(string $transactionId): ?IdentityResult
    {
        if (! preg_match('/^fake:([^:]+):([MF]):(\d{8})$/', $transactionId, $m)) {
            return null;
        }

        return new IdentityResult($m[1], $m[2], CarbonImmutable::createFromFormat('Ymd', $m[3])->startOfDay(), 'fake');
    }
}
