<?php

namespace App\Services\Plaza;

/**
 * 광장 접속자 관리. DB가 아니라 Redis에 둔다 (자주 바뀌고 잃어도 되는 값).
 * 공간(space) 키: 일반 광장은 광장 id, 상시 광장은 "{광장id}:{구역}:{채널}" (구역·채널별로 따로 셈).
 * 앱은 광장 안에 있는 동안 30초마다 heartbeat → 60초 이상 소식 없으면 이탈로 간주.
 */
interface PlazaPresence
{
    public function touch(int|string $plazaId, int $userId): void;

    public function leave(int|string $plazaId, int $userId): void;

    public function has(int|string $plazaId, int $userId): bool;

    public function count(int|string $plazaId): int;

    /** @return array<int, int> plazaId => 접속자 수 */
    public function counts(array $plazaIds): array;

    /** 최근 접속 순 사용자 ID @return array<int> */
    public function members(int|string $plazaId, int $limit): array;

    /** 사용자들이 지금 있는 광장 @return array<int, int> userId => plazaId (상시 광장 구역 공간도 광장 id로) */
    public function whereAre(array $userIds): array;

    /** 사용자가 지금 있는 공간 키 그대로 (예: "12" 또는 상시 광장 구역 "12:fountain:1"), 없으면 null */
    public function current(int $userId): ?string;
}
