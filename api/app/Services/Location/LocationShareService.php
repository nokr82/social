<?php

namespace App\Services\Location;

use App\Events\FriendLocationStopped;
use App\Events\FriendLocationUpdated;
use App\Exceptions\DomainException;
use App\Http\Resources\AvatarSummary;
use App\Models\LocationAccessLog;
use App\Models\LocationShare;
use App\Models\User;
use App\Notifications\Notify;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;

/**
 * 친구 위치 공유 규칙
 * - 공유는 '보여주는 사람'이 친구별로 켠다: 1시간 / 오늘 자정까지 / 끌 때까지
 * - 좌표는 DB에 남기지 않는다: 캐시에 사람당 마지막 1개만 LAST_TTL 동안 (이동 기록 없음)
 * - 친구 관계·차단·계정 상태를 '볼 때마다' 다시 확인한다 (공유 행이 남아 있어도 조건이 깨지면 안 보임)
 * - 위치가 실제로 넘어갈 때 확인자료를 남기고 보여준 사람에게 알린다 (같은 친구는 NOTICE_EVERY에 1번)
 */
class LocationShareService
{
    public const DURATIONS = ['1h', 'today', 'always'];

    private const LAST_TTL_MINUTES = 15;   // 마지막 위치 보관 시간 (앱을 닫은 친구는 15분 뒤 지도에서 사라짐)

    private const NOTICE_EVERY_MINUTES = 10;

    /** 공유 켜기(또는 기간 변경) */
    public function share(User $me, int $friendId, string $duration): LocationShare
    {
        if (! in_array($duration, self::DURATIONS, true)) {
            throw new DomainException('INVALID_DURATION', '공유 기간을 다시 선택해 주세요');
        }
        $this->assertCanShareWith($me, $friendId);

        $existing = LocationShare::where('sharer_id', $me->id)->where('viewer_id', $friendId)->first();
        // 새로 켰거나, 만료됐던 공유를 다시 켠 경우에만 알림 (켜 둔 상태에서 기간만 바꾸면 조용히)
        $isNew = ! $existing || ($existing->expires_at && $existing->expires_at->isPast());

        $share = $existing ?? new LocationShare(['sharer_id' => $me->id, 'viewer_id' => $friendId]);
        $share->expires_at = $this->expiresAt($duration);
        $share->save();

        if ($isNew) {
            Notify::locationShareStarted($me, User::findOrFail($friendId), $share->expires_at);
        }

        return $share;
    }

    public function stop(User $me, int $friendId): void
    {
        $deleted = LocationShare::where('sharer_id', $me->id)->where('viewer_id', $friendId)->delete();
        if ($deleted) {
            $this->broadcast(new FriendLocationStopped($me->id, [$friendId]));
        }
    }

    public function stopAll(User $me): void
    {
        $viewerIds = LocationShare::where('sharer_id', $me->id)->pluck('viewer_id')->map(fn ($id) => (int) $id)->all();
        LocationShare::where('sharer_id', $me->id)->delete();
        if ($viewerIds) {
            $this->broadcast(new FriendLocationStopped($me->id, $viewerIds));
        }
    }

    /** 차단 등으로 관계가 끊길 때: 두 사람 사이 공유를 양방향 모두 해제 */
    public function severBetween(int $a, int $b): void
    {
        $rows = LocationShare::between($a, $b)->get(['sharer_id', 'viewer_id']);
        LocationShare::between($a, $b)->delete();
        foreach ($rows as $r) {
            $this->broadcast(new FriendLocationStopped((int) $r->sharer_id, [(int) $r->viewer_id]));
        }
    }

    /**
     * 내가 공유 중인 친구 목록 (설정 시트용)
     *
     * @return Collection<int, LocationShare>
     */
    public function outgoing(User $me): Collection
    {
        return LocationShare::active()->where('sharer_id', $me->id)
            ->whereIn('viewer_id', $this->validFriendIds($me))
            ->with('viewer.avatar')->get();
    }

    /**
     * 내 위치 갱신. 볼 수 있는 친구가 없으면 저장도 전송도 하지 않는다.
     *
     * @return array{sharing: bool, viewers: int}
     */
    public function update(User $me, float $lat, float $lng, ?float $accuracy): array
    {
        $viewerIds = $this->outgoing($me)->pluck('viewer_id')->map(fn ($id) => (int) $id)->all();
        if (! $viewerIds) {
            Cache::forget($this->key($me->id));

            return ['sharing' => false, 'viewers' => 0];
        }

        $position = [
            'user_id' => $me->id,
            'lat' => round($lat, 5),          // 소수 5자리 ≈ 1m (그 이상은 GPS 오차보다 작아 의미 없음)
            'lng' => round($lng, 5),
            'accuracy' => $accuracy !== null ? (int) round($accuracy) : null,
            'at' => now()->toIso8601String(),
        ];
        Cache::put($this->key($me->id), $position, now()->addMinutes(self::LAST_TTL_MINUTES));
        $this->broadcast(new FriendLocationUpdated($viewerIds, $position));

        return ['sharing' => true, 'viewers' => count($viewerIds)];
    }

    /**
     * 나에게 위치를 공유 중인 친구들 + 마지막 위치 (지도 화면).
     * 위치가 있는 친구에 대해서는 확인자료를 남기고 그 친구에게 알린다.
     *
     * @return array<int, array>
     */
    public function friendsFor(User $viewer): array
    {
        $shares = LocationShare::active()->where('viewer_id', $viewer->id)
            ->whereIn('sharer_id', $this->validFriendIds($viewer))
            ->with('sharer.avatar')->get();

        return $shares->map(function (LocationShare $s) use ($viewer) {
            $position = Cache::get($this->key($s->sharer_id));
            if ($position) {
                $this->recordAccess($viewer, $s->sharer);
            }

            return [
                'user' => AvatarSummary::of($s->sharer),
                'position' => $position,                          // null = 최근 15분 안에 앱을 안 연 친구
                'until' => $s->expires_at?->toIso8601String(),    // null = 끌 때까지
            ];
        })->values()->all();
    }

    /** 확인자료 + 보여준 사람에게 통보. 지도를 열어 두는 동안 반복 알림이 쌓이지 않게 NOTICE_EVERY에 1번 */
    private function recordAccess(User $viewer, User $sharer): void
    {
        $key = "loc:notice:{$sharer->id}:{$viewer->id}";
        if (! Cache::add($key, 1, now()->addMinutes(self::NOTICE_EVERY_MINUTES))) {
            return;
        }
        LocationAccessLog::create(['sharer_id' => $sharer->id, 'viewer_id' => $viewer->id]);
        Notify::locationViewed($viewer, $sharer);
    }

    /** 지금도 친구이고, 차단 관계가 아니고, 정상 계정인 친구 ID */
    private function validFriendIds(User $me): array
    {
        return User::whereIn('id', $me->friendIds())
            ->whereNotIn('id', $me->blockedUserIds())
            ->where('status', User::STATUS_ACTIVE)
            ->pluck('id')->map(fn ($id) => (int) $id)->all();
    }

    private function assertCanShareWith(User $me, int $friendId): void
    {
        if (! in_array($friendId, $this->validFriendIds($me), true)) {
            // 차단 여부를 드러내지 않도록 '친구 아님'과 같은 응답
            throw new DomainException('NOT_FRIEND', '친구에게만 위치를 공유할 수 있어요', 403);
        }
    }

    private function expiresAt(string $duration): ?CarbonInterface
    {
        return match ($duration) {
            '1h' => now()->addHour(),
            'today' => now('Asia/Seoul')->endOfDay()->utc(), // 한국 시간 자정
            'always' => null,
        };
    }

    /**
     * 실시간 전송은 '있으면 좋은 것': 웹소켓 서버(Reverb)가 잠깐 죽어도
     * 위치 저장·공유 끄기·차단 같은 본 동작은 실패하면 안 되므로 오류는 기록만 한다.
     */
    private function broadcast(object $event): void
    {
        try {
            event($event);
        } catch (\Throwable $e) {
            report($e);
        }
    }

    private function key(int $userId): string
    {
        return "loc:last:{$userId}";
    }
}
