<?php

namespace Tests\Feature;

use App\Events\FriendLocationStopped;
use App\Events\FriendLocationUpdated;
use App\Models\Friendship;
use App\Models\LocationAccessLog;
use App\Models\User;
use App\Notifications\LocationShareStarted;
use App\Notifications\LocationViewed;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Broadcast;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

/** 친구 위치 공유: 친구만, 기간 만료, 실시간 전달 대상, 확인자료·통보, 차단 시 해제 */
class LocationShareTest extends TestCase
{
    use RefreshDatabase;

    private User $me;

    private User $friend;

    protected function setUp(): void
    {
        parent::setUp();
        [$this->me, $this->friend] = User::factory()->count(2)->complete()->create();
        $this->befriend($this->me, $this->friend);
    }

    private function befriend(User $a, User $b): void
    {
        Friendship::query()->insert([
            ['user_id' => $a->id, 'friend_id' => $b->id, 'created_at' => now()],
            ['user_id' => $b->id, 'friend_id' => $a->id, 'created_at' => now()],
        ]);
    }

    private function sendLocation(User $u, float $lat = 37.5006, float $lng = 127.0364)
    {
        return $this->actingAs($u)->postJson('/api/location', ['lat' => $lat, 'lng' => $lng, 'accuracy' => 12.4]);
    }

    public function test_share_with_friend_notifies_and_lists(): void
    {
        Notification::fake();

        $this->actingAs($this->me)->putJson("/api/location/shares/{$this->friend->id}", ['duration' => '1h'])
            ->assertOk()->assertJsonPath('data.0.user.id', $this->friend->id);

        Notification::assertSentTo($this->friend, LocationShareStarted::class);

        // 기간만 바꾸면 다시 알리지 않음
        $this->actingAs($this->me)->putJson("/api/location/shares/{$this->friend->id}", ['duration' => 'always'])
            ->assertOk()->assertJsonPath('data.0.until', null);
        Notification::assertSentToTimes($this->friend, LocationShareStarted::class, 1);
    }

    public function test_cannot_share_with_non_friend_or_blocked(): void
    {
        $stranger = User::factory()->complete()->create();
        $this->actingAs($this->me)->putJson("/api/location/shares/{$stranger->id}", ['duration' => '1h'])
            ->assertStatus(403)->assertJsonPath('code', 'NOT_FRIEND');

        $this->actingAs($this->me)->putJson("/api/location/shares/{$this->friend->id}", ['duration' => 'forever'])
            ->assertStatus(422);
    }

    public function test_update_is_sent_only_to_active_viewers(): void
    {
        Event::fake([FriendLocationUpdated::class]);

        // 공유 전: 저장·전송 없음 → 앱은 위치 보내기를 멈춘다
        $this->sendLocation($this->me)->assertOk()->assertJson(['sharing' => false]);
        Event::assertNotDispatched(FriendLocationUpdated::class);

        $this->actingAs($this->me)->putJson("/api/location/shares/{$this->friend->id}", ['duration' => '1h']);
        $this->sendLocation($this->me)->assertOk()->assertJson(['sharing' => true, 'viewers' => 1]);

        Event::assertDispatched(FriendLocationUpdated::class, fn ($e) => $e->viewerIds === [$this->friend->id]
            && $e->position['lat'] === 37.5006 && $e->position['accuracy'] === 12
            && $e->broadcastOn()[0]->name === "private-location.{$this->friend->id}");
    }

    public function test_viewer_sees_position_and_sharer_is_notified_once(): void
    {
        Notification::fake();
        $this->actingAs($this->me)->putJson("/api/location/shares/{$this->friend->id}", ['duration' => 'today']);
        $this->sendLocation($this->me);

        $this->actingAs($this->friend)->getJson('/api/location/friends')->assertOk()
            ->assertJsonPath('data.0.user.id', $this->me->id)
            ->assertJsonPath('data.0.position.lat', 37.5006);
        // 반대 방향은 공유 안 함 → 나는 친구 위치를 못 봄
        $this->actingAs($this->me)->getJson('/api/location/friends')->assertJsonCount(0, 'data');

        // 지도를 계속 열어 둬도(재조회) 통보·확인자료는 10분에 1번
        $this->actingAs($this->friend)->getJson('/api/location/friends');
        Notification::assertSentToTimes($this->me, LocationViewed::class, 1);
        $this->assertSame(1, LocationAccessLog::count());

        $this->travel(11)->minutes();
        $this->sendLocation($this->me);
        $this->actingAs($this->friend)->getJson('/api/location/friends');
        Notification::assertSentToTimes($this->me, LocationViewed::class, 2);
    }

    public function test_expired_share_hides_location(): void
    {
        $this->actingAs($this->me)->putJson("/api/location/shares/{$this->friend->id}", ['duration' => '1h']);
        $this->sendLocation($this->me);

        $this->travel(61)->minutes();

        $this->actingAs($this->friend)->getJson('/api/location/friends')->assertJsonCount(0, 'data');
        $this->sendLocation($this->me)->assertJson(['sharing' => false]);
        $this->actingAs($this->me)->getJson('/api/location/shares')->assertJsonCount(0, 'data');
    }

    public function test_stop_and_block_end_sharing_immediately(): void
    {
        Event::fake([FriendLocationStopped::class]);
        $this->actingAs($this->me)->putJson("/api/location/shares/{$this->friend->id}", ['duration' => 'always']);
        $this->actingAs($this->friend)->putJson("/api/location/shares/{$this->me->id}", ['duration' => 'always']);

        $this->actingAs($this->me)->deleteJson("/api/location/shares/{$this->friend->id}")->assertOk()->assertJsonCount(0, 'data');
        Event::assertDispatched(FriendLocationStopped::class, fn ($e) => $e->sharerId === $this->me->id);

        // 차단 → 반대 방향 공유도 해제
        $this->actingAs($this->me)->postJson('/api/blocks', ['user_id' => $this->friend->id])->assertSuccessful();
        $this->actingAs($this->friend)->getJson('/api/location/shares')->assertJsonCount(0, 'data');
        Event::assertDispatched(FriendLocationStopped::class, fn ($e) => $e->sharerId === $this->friend->id);
    }

    public function test_suspended_sharer_is_hidden(): void
    {
        $this->actingAs($this->me)->putJson("/api/location/shares/{$this->friend->id}", ['duration' => 'always']);
        $this->sendLocation($this->me);

        $this->me->forceFill(['status' => User::STATUS_SUSPENDED])->save();

        $this->actingAs($this->friend)->getJson('/api/location/friends')->assertJsonCount(0, 'data');
    }

    public function test_location_channel_is_private_to_owner(): void
    {
        $this->actingAs($this->friend);
        $callback = Broadcast::driver()->getChannels()['location.{viewer}'] ?? null;
        $this->assertNotNull($callback);
        $this->assertTrue($callback($this->friend, (string) $this->friend->id));
        $this->assertFalse($callback($this->friend, (string) $this->me->id));
    }
}
