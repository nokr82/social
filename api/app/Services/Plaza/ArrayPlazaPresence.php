<?php

namespace App\Services\Plaza;

/**
 * 테스트용 메모리 구현 (Redis 없이 동작). 요청 하나 안에서만 유지된다.
 * 시각은 time()이 아니라 now() 기준 → 테스트의 travel()로 접속 만료(TTL)를 검증할 수 있다.
 */
class ArrayPlazaPresence implements PlazaPresence
{
    /** @var array<int|string, array<int, int>> space => [userId => timestamp] */
    private array $data = [];

    /** @var array<int, string> userId => 지금 있는 space (Redis의 user:{id}:plaza 역할) */
    private array $where = [];

    public function touch(int|string $plazaId, int $userId): void
    {
        $this->data[$plazaId][$userId] = now()->getTimestamp();
        $this->where[$userId] = (string) $plazaId;
    }

    public function leave(int|string $plazaId, int $userId): void
    {
        unset($this->data[$plazaId][$userId]);
        if (($this->where[$userId] ?? null) === (string) $plazaId) {
            unset($this->where[$userId]);
        }
    }

    public function current(int $userId): ?string
    {
        $space = $this->where[$userId] ?? null;

        return $space !== null && $this->has($space, $userId) ? $space : null;
    }

    public function has(int|string $plazaId, int $userId): bool
    {
        return in_array($userId, $this->members($plazaId, PHP_INT_MAX), true);
    }

    public function count(int|string $plazaId): int
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

    public function whereAre(array $userIds): array
    {
        $out = [];
        foreach ($this->data as $plazaId => $users) {
            foreach ($userIds as $uid) {
                if (in_array($uid, $this->members($plazaId, PHP_INT_MAX), true)) {
                    $out[$uid] = (int) $plazaId; // "12:fountain:1" → 12
                }
            }
        }

        return $out;
    }

    public function members(int|string $plazaId, int $limit): array
    {
        $cutoff = now()->getTimestamp() - (int) config('social.plaza.presence_ttl');
        $alive = array_filter($this->data[$plazaId] ?? [], fn ($t) => $t >= $cutoff);
        arsort($alive);

        return array_slice(array_keys($alive), 0, $limit);
    }
}
