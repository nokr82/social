<?php

namespace App\Services\Identity;

interface IdentityVerifier
{
    /** 앱이 인증을 마치고 받은 거래 ID로 대행사에 결과를 조회. 실패 시 null */
    public function fetch(string $transactionId): ?IdentityResult;
}
