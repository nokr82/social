<?php

namespace Tests\Feature;

use App\Models\Report;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SafetyTest extends TestCase
{
    use RefreshDatabase;

    public function test_reports_from_distinct_users_auto_suspend(): void
    {
        $target = User::factory()->complete()->create();
        $reporters = User::factory()->count(config('social.trust.auto_suspend_reports'))->complete()->create();

        foreach ($reporters as $i => $r) {
            // 같은 사람이 두 번 신고해도 1건
            foreach ([1, 2] as $_) {
                $this->actingAs($r)->postJson('/api/reports', [
                    'user_id' => $target->id, 'target_type' => 'user', 'reason' => 'abuse',
                ])->assertCreated();
            }
            $expected = $i + 1 >= config('social.trust.auto_suspend_reports') ? 'suspended' : 'active';
            $this->assertSame($expected, $target->fresh()->status);
        }

        $this->assertSame($reporters->count(), Report::count());
    }

    public function test_report_also_blocks(): void
    {
        [$me, $target] = User::factory()->count(2)->complete()->create();

        $this->actingAs($me)->postJson('/api/reports', ['user_id' => $target->id, 'target_type' => 'user', 'reason' => 'scam']);

        $this->actingAs($me)->getJson('/api/blocks')->assertJson(['user_ids' => [$target->id]]);
        $this->actingAs($target)->getJson('/api/blocks')->assertJson(['user_ids' => [$me->id]]); // 양방향
    }

    /** '차단도 함께'를 끄면 신고만 접수 */
    public function test_report_without_block(): void
    {
        [$me, $target] = User::factory()->count(2)->complete()->create();

        $this->actingAs($me)->postJson('/api/reports', [
            'user_id' => $target->id, 'target_type' => 'user', 'reason' => 'minor', 'block' => false,
        ])->assertCreated();

        $this->actingAs($me)->getJson('/api/blocks')->assertJson(['user_ids' => []]);
    }

    public function test_unknown_reason_is_rejected(): void
    {
        [$me, $target] = User::factory()->count(2)->complete()->create();

        $this->actingAs($me)->postJson('/api/reports', ['user_id' => $target->id, 'target_type' => 'user', 'reason' => 'spam'])
            ->assertUnprocessable();
    }
}
