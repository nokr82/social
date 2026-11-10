<?php

namespace App\Services\Identity;

use RuntimeException;

/**
 * PASS 대행사 연동 자리. 대행사(드림시큐리티·KG이니시스 등)가 정해지면
 * 해당 SDK/API로 거래 결과를 조회해 IdentityResult로 변환한다.
 */
class PassIdentityVerifier implements IdentityVerifier
{
    public function fetch(string $transactionId): ?IdentityResult
    {
        throw new RuntimeException('PASS 대행사 연동이 아직 구현되지 않았습니다. IDENTITY_DRIVER=fake로 개발하세요.');
    }
}
