<?php

namespace App\Services\Plaza;

/**
 * 테스트용 메모리 구현 (Redis 없이 동작). 요청 하나 안에서만 유지된다.
 */
class ArrayPlazaPresence implements PlazaPresence
{
    /** @var array<int, array<int, int>> plazaId => [userId => timestamp] */
    private array $data = [];

    public function touch(int $plazaId, int $userId): void
    {
        $this->data[$plazaId][$userId] = time();
    }

    public function leave(int $plazaId, int $userId): void
    {
        unset($this->data[$plazaId][$userId]);
    }

    public function has(int $plazaId, int $userId): bool
    {
        return in_array($userId, $this->members($plazaId, PHP_INT_MAX), true);
    }

    public function count(int $plazaId): int
    {
        return count($this->members($plazaId, PHP_INT_MAX));
    }

    public function counts(array $plazaIds): array
    {
        $out = [];
        foreach ($plazaIds as $id) {
            $out[$id] = $this->count($id);
        }

        return $out;
    }

    public function members(int $plazaId, int $limit): array
    {
        $cutoff = time() - (int) config('social.plaza.presence_ttl');
        $alive = array_filter($this->data[$plazaId] ?? [], fn ($t) => $t >= $cutoff);
        arsort($alive);

        return array_slice(array_keys($alive), 0, $limit);
    }
}
