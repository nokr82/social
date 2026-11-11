<?php

namespace Tests\Feature;

use App\Models\ChatRoom;
use App\Models\Friendship;
use App\Models\User;
use App\Services\Plaza\PlazaService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DmTest extends TestCase
{
    use RefreshDatabase;

    private function befriend(User $a, User $b, ?int $plazaId = null): void
    {
        Friendship::insert([
            ['user_id' => $a->id, 'friend_id' => $b->id, 'plaza_id' => $plazaId, 'created_at' => now()],
            ['user_id' => $b->id, 'friend_id' => $a->id, 'plaza_id' => $plazaId, 'created_at' => now()],
        ]);
    }

    public function test_dm_key_is_order_independent(): void
    {
        $this->assertSame(ChatRoom::dmKey(3, 10), ChatRoom::dmKey(10, 3));
        $room = new ChatRoom(['ref_id' => ChatRoom::dmKey(10, 3)]);
        $this->assertSame([3, 10], $room->dmPair());
        $this->assertSame(10, $room->otherMemberId(3));
    }

    public function test_only_friends_can_open(): void
    {
        [$a, $b] = User::factory()->count(2)->complete()->create();

        $this->actingAs($a)->postJson("/api/dm/{$b->id}")->assertForbidden()->assertJson(['code' => 'NOT_FRIENDS']);

        $this->befriend($a, $b);
        $r1 = $this->actingAs($a)->postJson("/api/dm/{$b->id}")->assertOk()->json('room_id');
        $r2 = $this->actingAs($b)->postJson("/api/dm/{$a->id}")->assertOk()->json('room_id');
        $this->assertSame($r1, $r2); // 같은 방
    }

    public function test_send_unread_and_read_receipt(): void
    {
        [$a, $b] = User::factory()->count(2)->complete()->create();
        $this->befriend($a, $b);
        $room = $this->actingAs($a)->postJson("/api/dm/{$b->id}")->json('room_id');

        $this->actingAs($a)->postJson("/api/chats/{$room}/messages", ['body' => '안녕'])->assertCreated();
        $last = $this->actingAs($a)->postJson("/api/chats/{$room}/messages", ['body' => '오늘 번개 와요?'])->json('data.id');

        // b의 목록: 안 읽은 2개
        $this->actingAs($b)->getJson('/api/chats')
            ->assertJsonPath('data.0.unread', 2)
            ->assertJsonPath('data.0.last_message.body', '오늘 번개 와요?')
            ->assertJsonPath('unread_total', 2);
        // a에겐 아직 안 읽음
        $this->actingAs($a)->getJson("/api/chats/{$room}")->assertJsonPath('data.other_last_read_id', 0);

        $this->actingAs($b)->postJson("/api/chats/{$room}/read", ['last_id' => $last])->assertOk();

        $this->actingAs($b)->getJson('/api/chats')->assertJsonPath('data.0.unread', 0);
        $this->actingAs($a)->getJson("/api/chats/{$room}")->assertJsonPath('data.other_last_read_id', $last);
    }

    public function test_read_cannot_jump_past_real_messages(): void
    {
        [$a, $b] = User::factory()->count(2)->complete()->create();
        $this->befriend($a, $b);
        $room = $this->actingAs($a)->postJson("/api/dm/{$b->id}")->json('room_id');
        $id = $this->actingAs($a)->postJson("/api/chats/{$room}/messages", ['body' => 'hi'])->json('data.id');

        $this->actingAs($b)->postJson("/api/chats/{$room}/read", ['last_id' => 999999]);
        $this->actingAs($a)->getJson("/api/chats/{$room}")->assertJsonPath('data.other_last_read_id', $id);
    }

    public function test_stranger_and_blocked_cannot_access(): void
    {
        [$a, $b, $c] = User::factory()->count(3)->complete()->create();
        $this->befriend($a, $b);
        $room = $this->actingAs($a)->postJson("/api/dm/{$b->id}")->json('room_id');
        $this->actingAs($a)->postJson("/api/chats/{$room}/messages", ['body' => 'hi']);

        $this->actingAs($c)->getJson("/api/chats/{$room}/messages")->assertNotFound(); // 남의 방

        $this->actingAs($b)->postJson('/api/blocks', ['user_id' => $a->id]); // 차단 → 친구 관계도 끊김
        $this->actingAs($a)->postJson("/api/chats/{$room}/messages", ['body' => '?'])->assertNotFound();
        $this->actingAs($a)->getJson('/api/chats')->assertJsonCount(0, 'data');
    }

    public function test_friends_list_shows_identity_and_plaza(): void
    {
        $me = User::factory()->complete()->create();
        $friend = User::factory()->complete('M', '1993-01-01')->create();
        $this->befriend($me, $friend);
        $plaza = app(PlazaService::class)->create($friend, ['name' => '역삼 수다방', 'category' => 'talk'], 37.5, 127.03);
        app(PlazaService::class)->enter($friend, $plaza);

        $this->actingAs($me)->getJson('/api/friends')
            ->assertJsonPath('data.0.gender', 'M')
            ->assertJsonPath('data.0.online', true)
            ->assertJsonPath('data.0.plaza_name', '역삼 수다방');
    }

    public function test_room_header_shows_where_we_met(): void
    {
        [$a, $b] = User::factory()->count(2)->complete()->create();
        $plaza = app(PlazaService::class)->create($a, ['name' => '퇴근길 수다방', 'category' => 'talk'], 37.5, 127.03);
        $this->befriend($a, $b, $plaza->id);
        $room = $this->actingAs($b)->postJson("/api/dm/{$a->id}")->json('room_id');

        $this->actingAs($b)->getJson("/api/chats/{$room}")->assertJsonPath('data.met_at', '퇴근길 수다방');
    }

    public function test_last_seen_is_updated(): void
    {
        $me = User::factory()->complete()->create();
        $this->assertNull($me->last_seen_at);

        $this->actingAs($me)->getJson('/api/me')->assertOk();
        $this->assertNotNull($me->fresh()->last_seen_at);
    }
}
