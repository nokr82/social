<?php

namespace App\Services\Plaza;

use Illuminate\Support\Facades\Redis;

/**
 * Redis ZSET 구현: key=plaza:{id}:presence, member=user_id, score=마지막 heartbeat 시각.
 * 조회 시 score가 (현재-TTL)보다 오래된 멤버를 지워 '유령 접속자'를 정리한다.
 * 시각은 now() 기준 (host_seen_at 등 DB 시각과 같은 시계를 쓰도록).
 */
class RedisPlazaPresence implements PlazaPresence
{
    private function key(int|string $plazaId): string
    {
        return "plaza:{$plazaId}:presence";
    }

    private function cutoff(): int
    {
        return now()->getTimestamp() - (int) config('social.plaza.presence_ttl');
    }

    public function touch(int|string $plazaId, int $userId): void
    {
        Redis::zadd($this->key($plazaId), now()->getTimestamp(), $userId);
        // 역방향: 이 사용자가 지금 있는 광장 (heartbeat가 끊기면 TTL로 자연 소멸)
        Redis::setex("user:{$userId}:plaza", (int) config('social.plaza.presence_ttl'), $plazaId);
        // 광장이 비어도 키가 영원히 남지 않도록 키 자체에도 만료 설정
        Redis::expire($this->key($plazaId), (int) config('social.plaza.presence_ttl') * 2);
    }

    public function leave(int|string $plazaId, int $userId): void
    {
        Redis::zrem($this->key($plazaId), $userId);
        Redis::del("user:{$userId}:plaza");
    }

    public function has(int|string $plazaId, int $userId): bool
    {
        $score = Redis::zscore($this->key($plazaId), $userId);

        return $score !== null && $score !== false && (int) $score >= $this->cutoff();
    }

    public function count(int|string $plazaId): int
    {
        Redis::zremrangebyscore($this->key($plazaId), '-inf', $this->cutoff() - 1);

        return (int) Redis::zcard($this->key($plazaId));
    }

    public function counts(array $plazaIds): array
    {
        if (! $plazaIds) {
            return [];
        }
        $cutoff = $this->cutoff();

        // 광장 수만큼 왕복하지 않도록 파이프라인으로 한 번에 조회
        $results = Redis::pipeline(function ($pipe) use ($plazaIds, $cutoff) {
            foreach ($plazaIds as $id) {
                $pipe->zcount($this->key($id), $cutoff, '+inf');
            }
        });

        return array_combine($plazaIds, array_map('intval', $results));
    }

    public function whereAre(array $userIds): array
    {
        if (! $userIds) {
            return [];
        }
        $values = Redis::mget(array_map(fn ($id) => "user:{$id}:plaza", $userIds));

        return array_filter(array_combine($userIds, array_map(fn ($v) => $v ? (int) $v : null, $values)));
    }

    public function current(int $userId): ?string
    {
        $v = Redis::get("user:{$userId}:plaza");

        return $v ? (string) $v : null;
    }

    public function members(int|string $plazaId, int $limit): array
    {
        $ids = Redis::zrevrangebyscore($this->key($plazaId), '+inf', $this->cutoff(), ['limit' => [0, $limit]]);

        return array_map('intval', $ids);
    }
}
