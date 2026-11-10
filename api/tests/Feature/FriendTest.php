<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class FriendTest extends TestCase
{
    use RefreshDatabase;

    public function test_request_and_accept_reveals_identity(): void
    {
        $alice = User::factory()->complete('F', '1995-05-05')->create();
        $bob = User::factory()->complete('M', '1993-01-01')->create();

        $this->actingAs($bob)->postJson('/api/friend-requests', ['user_id' => $alice->id, 'message' => '아까 재밌었어요'])->assertCreated();
        $this->actingAs($bob)->getJson("/api/users/{$alice->id}")->assertJsonPath('data.relation', 'sent');

        $req = $this->actingAs($alice)->getJson('/api/friend-requests?type=received')
            ->assertJsonPath('counts.received', 1)
            ->assertJsonPath('data.0.message', '아까 재밌었어요')
            ->json('data.0.id');

        // 수락 응답에 상대 성별·나이가 바로 담김 (정보 공개 카드용)
        $this->actingAs($alice)->postJson("/api/friend-requests/{$req}/accept")
            ->assertJsonPath('data.gender', 'M')
            ->assertJsonPath('data.age', \Carbon\Carbon::parse('1993-01-01')->age);

        $this->actingAs($bob)->getJson("/api/users/{$alice->id}")
            ->assertJsonPath('data.relation', 'friends')
            ->assertJsonPath('data.gender', 'F');
        $this->actingAs($alice)->getJson('/api/me')->assertJsonPath('data.stats.friends', 1);
    }

    /** 거절은 보낸 사람에게 알리지 않는다: 계속 '대기 중' */
    public function test_reject_is_silent_and_undoable(): void
    {
        [$a, $b] = User::factory()->count(2)->complete()->create();
        $this->actingAs($a)->postJson('/api/friend-requests', ['user_id' => $b->id]);
        $id = $this->actingAs($b)->getJson('/api/friend-requests')->json('data.0.id');

        $this->actingAs($b)->postJson("/api/friend-requests/{$id}/reject")->assertOk();

        $this->actingAs($a)->getJson('/api/friend-requests?type=sent')->assertJsonPath('data.0.status', 'pending');
        $this->actingAs($a)->getJson("/api/users/{$b->id}")->assertJsonPath('data.relation', 'sent');
        $this->actingAs($b)->getJson('/api/friend-requests')->assertJsonCount(0, 'data');

        // 되돌리기 → 다시 받은 요청에
        $this->actingAs($b)->postJson("/api/friend-requests/{$id}/undo")->assertOk();
        $this->actingAs($b)->getJson('/api/friend-requests')->assertJsonCount(1, 'data');
    }

    public function test_requests_expire_after_seven_days(): void
    {
        [$a, $b] = User::factory()->count(2)->complete()->create();
        $this->actingAs($a)->postJson('/api/friend-requests', ['user_id' => $b->id]);

        $this->travel(8)->days();

        $this->actingAs($b)->getJson('/api/friend-requests')->assertJsonCount(0, 'data');
        // 만료 후 다시 보낼 수 있음
        $this->actingAs($a)->postJson('/api/friend-requests', ['user_id' => $b->id])->assertCreated();
    }

    public function test_duplicate_and_reverse_requests(): void
    {
        [$a, $b] = User::factory()->count(2)->complete()->create();
        $this->actingAs($a)->postJson('/api/friend-requests', ['user_id' => $b->id])->assertCreated();

        $this->actingAs($a)->postJson('/api/friend-requests', ['user_id' => $b->id])->assertStatus(409)->assertJson(['code' => 'ALREADY_SENT']);
        $this->actingAs($b)->postJson('/api/friend-requests', ['user_id' => $a->id])->assertStatus(409)->assertJson(['code' => 'INCOMING_EXISTS']);
        $this->actingAs($a)->postJson('/api/friend-requests', ['user_id' => $a->id])->assertUnprocessable();
    }

    public function test_cancel_sent_request(): void
    {
        [$a, $b] = User::factory()->count(2)->complete()->create();
        $id = $this->actingAs($a)->postJson('/api/friend-requests', ['user_id' => $b->id])->json('data.id');

        $this->actingAs($b)->deleteJson("/api/friend-requests/{$id}")->assertNotFound(); // 받은 사람은 취소 불가
        $this->actingAs($a)->deleteJson("/api/friend-requests/{$id}")->assertOk();
        $this->actingAs($b)->getJson('/api/friend-requests')->assertJsonCount(0, 'data');
    }

    /** 차단하면 친구 관계와 요청이 함께 사라진다 */
    public function test_block_severs_friendship(): void
    {
        [$a, $b, $c] = User::factory()->count(3)->complete()->create();
        $id = $this->actingAs($a)->postJson('/api/friend-requests', ['user_id' => $b->id])->json('data.id');
        $this->actingAs($b)->postJson("/api/friend-requests/{$id}/accept")->assertOk();
        $this->actingAs($c)->postJson('/api/friend-requests', ['user_id' => $a->id]);

        $this->actingAs($a)->postJson('/api/blocks', ['user_id' => $b->id]);
        $this->actingAs($a)->postJson('/api/reports', ['user_id' => $c->id, 'target_type' => 'user', 'reason' => 'abuse']);

        $this->actingAs($a)->getJson('/api/me')->assertJsonPath('data.stats.friends', 0);
        $this->actingAs($a)->getJson('/api/friend-requests')->assertJsonCount(0, 'data');
        $this->actingAs($b)->postJson('/api/friend-requests', ['user_id' => $a->id])->assertNotFound();
    }
}
