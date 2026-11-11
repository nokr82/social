<?php

namespace App\Services\Moderation;

use App\Exceptions\DomainException;
use App\Models\Admin;
use App\Models\BannedIdentity;
use App\Models\Report;
use App\Models\TrustScore;
use App\Models\User;
use App\Models\UserSanction;
use Illuminate\Support\Facades\DB;

/**
 * 신고 처리와 이용 제한. 관리자 화면(Filament)과 자동 처리(신고 누적)가 모두 이 서비스를 쓴다.
 *
 * 원칙
 * - 현재 상태는 users.status, 이력은 user_sanctions (누가·언제·왜)
 * - 정지·영구정지 즉시 로그인 토큰 전부 폐기 → 앱에서 바로 튕겨 나감
 * - 영구정지는 CI(본인인증)를 banned_identities에 올려 재가입까지 차단
 */
class ModerationService
{
    public const ACTIONS = ['none', 'warn', 'suspend', 'ban'];

    /**
     * 신고 처리.
     * @param  string  $action  승인 시 조치: none(기록만) | warn(감점만) | suspend(기간 정지) | ban(영구정지)
     */
    public function resolve(Admin $admin, Report $report, bool $accept, string $action = 'warn', ?int $days = 7, ?string $note = null): void
    {
        if ($report->status !== Report::STATUS_PENDING) {
            throw new DomainException('ALREADY_HANDLED', '이미 처리된 신고예요', 409);
        }
        $target = $report->target;

        DB::transaction(function () use ($admin, $report, $accept, $action, $days, $note, $target) {
            $report->update([
                'status' => $accept ? Report::STATUS_ACCEPTED : Report::STATUS_REJECTED,
                'handled_by' => $admin->id,
                'handled_at' => now(),
                'action' => $accept ? $action : 'none',
                'admin_note' => $note,
            ]);

            if (! $accept) {
                $this->releaseIfAutoSuspendedWithoutGround($target, $admin);

                return;
            }

            if ($action !== 'none') {
                $penalty = (int) config('social.trust.report_penalty');
                TrustScore::where('user_id', $target->id)->update([
                    'report_count' => DB::raw('report_count + 1'),
                    'score' => DB::raw("CASE WHEN score > {$penalty} THEN score - {$penalty} ELSE 0 END"),
                    'updated_at' => now(),
                ]);
            }
            match ($action) {
                'suspend' => $this->suspend($target, $admin, $days, "신고 #{$report->id} 승인"),
                'ban' => $this->ban($target, $admin, "신고 #{$report->id} 승인"),
                default => null,
            };
        });
    }

    /** 기간 정지 ($days null = 무기한). $admin null = 시스템 자동 정지 */
    public function suspend(User $user, ?Admin $admin, ?int $days, ?string $reason = null): void
    {
        if ($user->status === User::STATUS_BANNED) {
            return; // 영구정지가 더 강한 제재
        }
        $until = $days ? now()->addDays($days) : null;

        $user->forceFill(['status' => User::STATUS_SUSPENDED, 'suspended_until' => $until])->save();
        $user->tokens()->delete();
        UserSanction::create(['user_id' => $user->id, 'admin_id' => $admin?->id, 'type' => 'suspend', 'until' => $until, 'reason' => $reason]);
    }

    public function unsuspend(User $user, ?Admin $admin, ?string $reason = null): void
    {
        if ($user->status !== User::STATUS_SUSPENDED) {
            return;
        }
        $user->forceFill(['status' => User::STATUS_ACTIVE, 'suspended_until' => null])->save();
        UserSanction::create(['user_id' => $user->id, 'admin_id' => $admin?->id, 'type' => 'unsuspend', 'reason' => $reason]);
    }

    /** 기간 정지가 끝났으면 해제. 해제했으면 true */
    public function releaseIfExpired(User $user): bool
    {
        if ($user->status !== User::STATUS_SUSPENDED || ! $user->suspended_until?->isPast()) {
            return false;
        }
        $this->unsuspend($user, null, '정지 기간 만료');

        return true;
    }

    /** 영구정지 + CI 차단 명단 등록 (탈퇴 후 재가입 차단) */
    public function ban(User $user, Admin $admin, ?string $reason = null): void
    {
        $user->forceFill(['status' => User::STATUS_BANNED, 'suspended_until' => null])->save();
        $user->tokens()->delete();

        $ciHash = $user->identity?->getRawOriginal('ci_hash');
        if ($ciHash) {
            BannedIdentity::query()->insertOrIgnore(['ci_hash' => $ciHash, 'reason' => $reason, 'created_at' => now()]);
        }
        UserSanction::create(['user_id' => $user->id, 'admin_id' => $admin->id, 'type' => 'ban', 'reason' => $reason]);
    }

    /** 신고 누적 자동 정지 (SafetyController에서 호출) */
    public function autoSuspendIfNeeded(User $target): void
    {
        if ($target->status === User::STATUS_ACTIVE && $this->validReporterCount($target) >= config('social.trust.auto_suspend_reports')) {
            $this->suspend($target, null, null, '신고 누적 자동 정지 (관리자 검토 대기)');
        }
    }

    /**
     * 반려 후: 시스템이 자동 정지한 사용자인데 유효 신고자가 기준 아래로 내려가면 해제.
     * (허위 신고 몰이로 정지된 사람을 관리자가 일일이 풀지 않아도 되게)
     */
    private function releaseIfAutoSuspendedWithoutGround(User $target, Admin $admin): void
    {
        if ($target->status !== User::STATUS_SUSPENDED) {
            return;
        }
        $last = UserSanction::where('user_id', $target->id)->where('type', 'suspend')->latest('id')->first();
        $bySystem = $last && $last->admin_id === null;

        if ($bySystem && $this->validReporterCount($target) < config('social.trust.auto_suspend_reports')) {
            $this->unsuspend($target, $admin, '신고 반려로 자동 정지 해제');
        }
    }

    private function validReporterCount(User $target): int
    {
        return Report::where('target_id', $target->id)
            ->where('status', '!=', Report::STATUS_REJECTED)
            ->distinct()->count('reporter_id');
    }
}
