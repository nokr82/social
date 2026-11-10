<?php

namespace App\Services\Plaza;

/**
 * 광장 접속자 관리. DB가 아니라 Redis에 둔다 (자주 바뀌고 잃어도 되는 값).
 * 앱은 광장 안에 있는 동안 30초마다 heartbeat → 60초 이상 소식 없으면 이탈로 간주.
 */
interface PlazaPresence
{
    public function touch(int $plazaId, int $userId): void;

    public function leave(int $plazaId, int $userId): void;

    public function has(int $plazaId, int $userId): bool;

    public function count(int $plazaId): int;

    /** @return array<int, int> plazaId => 접속자 수 */
    public function counts(array $plazaIds): array;

    /** 최근 접속 순 사용자 ID @return array<int> */
    public function members(int $plazaId, int $limit): array;
}
