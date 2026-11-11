<?php

namespace Tests\Feature;

use App\Models\Friendship;
use App\Models\Meetup;
use App\Models\User;
use App\Notifications\ChatMessagePushed;
use App\Notifications\MeetupCanceled;
use App\Notifications\MeetupJoined;
use App\Notifications\MeetupReminder;
use App\Services\Plaza\PlazaService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use NotificationChannels\WebPush\WebPushChannel;
use Tests\TestCase;

class NotificationTest extends TestCase
{
    use RefreshDatabase;

    private function subscribe(User $u): void
    {
        $this->actingAs($u)->postJson('/api/push/subscriptions', [
            'endpoint' => 'https://fcm.googleapis.com/fcm/send/test-'.$u->id,
            'keys' => ['p256dh' => 'BPublicKeyForTest', 'auth' => 'authSecret'],
        ])->assertCreated();
    }

    private function meetup(User $host): int
    {
        $plaza = app(PlazaService::class)->create($host, ['name' => '역삼 수다방', 'category' => 'talk'], 37.5006, 127.0364);

        return $this->actingAs($host)->postJson("/api/plazas/{$plaza->id}/meetups", [
            'title' => '퇴근하고 한잔', 'area_label' => '역삼', 'place_detail' => 'OO호프',
            'starts_at' => now()->addHours(3)->toIso8601String(), 'capacity' => 4, 'age_rule' => 'any',
        ])->assertCreated()->json('data.id');
    }

    /** 친구 요청 → 알림센터에 쌓이고, 읽음 처리 */
    public function test_friend_request_lands_in_center(): void
    {
        [$a, $b] = User::factory()->count(2)->complete()->create();

        $this->actingAs($a)->postJson('/api/friend-requests', ['user_id' => $b->id, 'message' => '반가워요'])->assertCreated();

        $this->actingAs($b)->getJson('/api/me')->assertJsonPath('data.stats.unread_notifications', 1);
        $res = $this->actingAs($b)->getJson('/api/notifications')
            ->assertJsonPath('unread', 1)
            ->assertJsonPath('data.0.kind', 'friend_request')
            ->assertJsonPath('data.0.url', '/friends/requests')
            ->assertJsonPath('data.0.actor.id', $a->id)
            ->assertJsonPath('data.0.read', false);

        $this->actingAs($b)->postJson('/api/notifications/'.$res->json('data.0.id').'/read')->assertJson(['unread' => 0]);

        // 수락 → 보낸 사람에게 '친구가 됐어요'
        $id = $this->actingAs($b)->getJson('/api/friend-requests')->json('data.0.id');
        $this->actingAs($b)->postJson("/api/friend-requests/{$id}/accept")->assertOk();
        $this->actingAs($a)->getJson('/api/notifications')->assertJsonPath('data.0.kind', 'friend_accepted');

        // 남의 알림은 읽음 처리 안 됨
        $this->actingAs($a)->postJson('/api/notifications/read-all')->assertJson(['unread' => 0]);
        $this->assertSame(0, $b->unreadNotifications()->count());
    }

    /** DM은 푸시만: 알림센터엔 안 남고, 구독한 기기가 있어야 푸시 채널이 붙음 */
    public function test_dm_is_push_only_and_needs_subscription(): void
    {
        Notification::fake();
        [$a, $b] = User::factory()->count(2)->complete()->create();
        Friendship::insert([
            ['user_id' => $a->id, 'friend_id' => $b->id, 'created_at' => now()],
            ['user_id' => $b->id, 'friend_id' => $a->id, 'created_at' => now()],
        ]);
        $room = $this->actingAs($a)->postJson("/api/dm/{$b->id}")->json('room_id');

        $this->actingAs($a)->postJson("/api/chats/{$room}/messages", ['body' => '안녕'])->assertCreated();
        // 구독이 없으면 채널이 0개 → 아예 발송되지 않음 (알림센터에도 안 남음)
        Notification::assertNothingSentTo($b);

        $this->subscribe($b);
        $this->actingAs($a)->postJson("/api/chats/{$room}/messages", ['body' => '자니?'])->assertCreated();
        Notification::assertSentTo($b, ChatMessagePushed::class, fn ($n, $channels) => $channels === [WebPushChannel::class]);
        Notification::assertNotSentTo($a, ChatMessagePushed::class); // 보낸 사람에겐 안 감
    }

    /** 설정: 채팅 끔 → 푸시 없음, 야간 무음 → 밤에만 푸시 없음 (알림센터는 항상) */
    public function test_settings_and_quiet_hours(): void
    {
        $u = User::factory()->complete()->create();
        $this->subscribe($u);
        $n = new MeetupReminder(['kind' => 'meetup_reminder', 'title' => 't', 'body' => 'b', 'url' => '/']);

        $this->assertContains(WebPushChannel::class, $n->via($u->fresh()));

        $this->actingAs($u)->putJson('/api/me/notification-settings', ['meetup' => false])
            ->assertJsonPath('data.meetup', false)->assertJsonPath('data.chat', true)->assertJsonPath('data.push_devices', 1);
        $this->assertSame(['database', 'broadcast'], $n->via($u->fresh()));

        $this->actingAs($u)->putJson('/api/me/notification-settings', ['meetup' => true, 'night_quiet' => true]);
        $this->travelTo(now('Asia/Seoul')->setTime(23, 0));
        $this->assertNotContains(WebPushChannel::class, $n->via($u->fresh()));
        $this->travelTo(now('Asia/Seoul')->setTime(12, 0));
        $this->assertContains(WebPushChannel::class, $n->via($u->fresh()));
    }

    public function test_subscription_must_be_https_and_can_be_removed(): void
    {
        $u = User::factory()->complete()->create();
        $this->actingAs($u)->postJson('/api/push/subscriptions', [
            'endpoint' => 'http://evil.local/x', 'keys' => ['p256dh' => 'a', 'auth' => 'b'],
        ])->assertUnprocessable();

        $this->subscribe($u);
        $this->actingAs($u)->deleteJson('/api/push/subscriptions', ['endpoint' => 'https://fcm.googleapis.com/fcm/send/test-'.$u->id])->assertOk();
        $this->assertSame(0, $u->pushSubscriptions()->count());
    }

    /** 번개: 참여 → 호스트, 호스트 취소 → 참여자 */
    public function test_meetup_join_and_cancel(): void
    {
        Notification::fake();
        $host = User::factory()->complete()->create();
        $guest = User::factory()->complete()->create();
        $id = $this->meetup($host);

        $this->actingAs($guest)->postJson("/api/meetups/{$id}/join")->assertOk();
        Notification::assertSentTo($host, MeetupJoined::class);
        Notification::assertNotSentTo($guest, MeetupJoined::class);

        $this->actingAs($host)->deleteJson("/api/meetups/{$id}")->assertOk();
        Notification::assertSentTo($guest, MeetupCanceled::class);
        Notification::assertNotSentTo($host, MeetupCanceled::class);
    }

    /** 1시간 전 알림: 시간이 됐을 때 한 번만 */
    public function test_meetup_reminder_once(): void
    {
        Notification::fake();
        $host = User::factory()->complete()->create();
        $guest = User::factory()->complete()->create();
        $id = $this->meetup($host);
        $this->actingAs($guest)->postJson("/api/meetups/{$id}/join")->assertOk();

        $this->artisan('meetups:remind')->assertSuccessful();
        Notification::assertNotSentTo($guest, MeetupReminder::class); // 아직 3시간 전

        $this->travelTo(now()->addHours(2)->addMinutes(5)); // 55분 전
        $this->artisan('meetups:remind')->assertSuccessful();
        $this->artisan('meetups:remind')->assertSuccessful();
        Notification::assertSentToTimes($guest, MeetupReminder::class, 1);
        Notification::assertSentToTimes($host, MeetupReminder::class, 1);
        $this->assertNotNull(Meetup::find($id)->reminded_at);
    }
}
