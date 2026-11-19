<?php

namespace Tests\Feature;

use App\Events\MeetupLocationStopped;
use App\Events\MeetupLocationUpdated;
use App\Models\LocationAccessLog;
use App\Models\MeetupLocationShare;
use App\Models\Plaza;
use App\Models\User;
use App\Notifications\MeetupLocationViewed;
use App\Services\Plaza\PlazaService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

/**
 * 번개 '모이는 중' 지도: 참여 확정자만, 켠 사람만 보임, 기간 없음(채팅방 닫힐 때까지),
 * 확인자료·통보, 차단·참여 취소·번개 취소 시 끝
 */
class MeetupLocationTest extends TestCase
{
    use RefreshDatabase;

    private User $host;
    private User $a;
    private User $b;
    private int $meetupId;

    protected function setUp(): void
    {
        parent::setUp();
        [$this->host, $this->a, $this->b] = User::factory()->count(3)->complete()->create();
        $plaza = app(PlazaService::class)->create($this->host, ['name' => '역삼 수다방', 'category' => 'talk'], 37.5006, 127.0364);

        $this->meetupId = $this->actingAs($this->host)->postJson("/api/plazas/{$plaza->id}/meetups", [
            'title' => '커피 한잔', 'area_label' => '역삼동 근처', 'capacity' => 4,
            'place' => ['name' => '스타벅스 역삼역점', 'address' => '서울 강남구 테헤란로 211', 'lat' => 37.5010, 'lng' => 127.0370, 'kakao_id' => '27560699'],
            'starts_at' => now()->addHours(3)->toIso8601String(),
        ])->assertCreated()->json('data.id');

        $this->actingAs($this->a)->postJson("/api/meetups/{$this->meetupId}/join")->assertOk();
        $this->actingAs($this->b)->postJson("/api/meetups/{$this->meetupId}/join")->assertOk();
    }

    private function sendLocation(User $u, float $lat = 37.5006, float $lng = 127.0364)
    {
        return $this->actingAs($u)->postJson('/api/location', ['lat' => $lat, 'lng' => $lng, 'accuracy' => 10]);
    }

    private function member(array $board, User $u): ?array
    {
        return collect($board['members'])->firstWhere('user.id', $u->id);
    }

    /** 켠 사람만 위치가 보이고, 목적지까지 거리 · 확인자료 · 공유자 통보 */
    public function test_only_sharing_members_visible_with_access_log(): void
    {
        Notification::fake();

        $this->actingAs($this->a)->putJson("/api/meetups/{$this->meetupId}/location-share")
            ->assertOk()->assertJsonPath('data.me_sharing', true);
        $this->sendLocation($this->a)->assertJsonPath('sharing', true);
        $this->sendLocation($this->b); // b는 안 켬 → 저장·전송 안 됨

        $board = $this->actingAs($this->host)->getJson("/api/meetups/{$this->meetupId}/locations")
            ->assertOk()
            ->assertJsonPath('data.destination.name', '스타벅스 역삼역점')
            ->json('data');

        $a = $this->member($board, $this->a);
        $this->assertTrue($a['sharing']);
        $this->assertEqualsWithDelta(37.5006, $a['position']['lat'], 0.00001);
        $this->assertGreaterThan(0, $a['distance_m']);

        $b = $this->member($board, $this->b);
        $this->assertFalse($b['sharing']);
        $this->assertNull($b['position']);

        $this->assertDatabaseHas('location_access_logs', ['sharer_id' => $this->a->id, 'viewer_id' => $this->host->id]);
        Notification::assertSentTo($this->a, MeetupLocationViewed::class);

        // 10분 안에 다시 봐도 기록·통보는 1번
        $this->actingAs($this->host)->getJson("/api/meetups/{$this->meetupId}/locations")->assertOk();
        $this->assertSame(1, LocationAccessLog::where('sharer_id', $this->a->id)->count());
    }

    /** 참여 확정자가 아니면 지도도 못 보고 켜지도 못함 */
    public function test_non_member_cannot_view_or_share(): void
    {
        $outsider = User::factory()->complete()->create();
        $this->actingAs($outsider)->getJson("/api/meetups/{$this->meetupId}/locations")->assertForbidden();
        $this->actingAs($outsider)->putJson("/api/meetups/{$this->meetupId}/location-share")->assertForbidden();
    }

    /** 실시간: 같은 번개의 다른 참여자 각자에게 (본인 제외) */
    public function test_update_broadcasts_to_co_members(): void
    {
        $this->actingAs($this->a)->putJson("/api/meetups/{$this->meetupId}/location-share")->assertOk();
        Event::fake([MeetupLocationUpdated::class]);

        $this->sendLocation($this->a)->assertJsonPath('viewers', 2);

        Event::assertDispatched(MeetupLocationUpdated::class, function (MeetupLocationUpdated $e) {
            sort($e->viewerIds);
            $expected = [$this->host->id, $this->b->id];
            sort($expected);

            return $e->meetupId === $this->meetupId && $e->viewerIds === $expected;
        });
    }

    /** 차단: 서로 지도·실시간에서 빠짐 (차단 사실은 드러내지 않음) */
    public function test_blocked_members_hidden_both_ways(): void
    {
        $this->actingAs($this->a)->putJson("/api/meetups/{$this->meetupId}/location-share")->assertOk();
        $this->actingAs($this->b)->postJson('/api/blocks', ['user_id' => $this->a->id])->assertSuccessful();

        Event::fake([MeetupLocationUpdated::class]);
        $this->sendLocation($this->a)->assertJsonPath('viewers', 1); // 호스트에게만

        $board = $this->actingAs($this->b)->getJson("/api/meetups/{$this->meetupId}/locations")->json('data');
        $this->assertNull($this->member($board, $this->a));
    }

    /** 참여 취소·번개 취소 → 공유 끝 + 보던 지도에서 지움 */
    public function test_cancel_stops_sharing(): void
    {
        $this->actingAs($this->a)->putJson("/api/meetups/{$this->meetupId}/location-share")->assertOk();
        $this->actingAs($this->b)->putJson("/api/meetups/{$this->meetupId}/location-share")->assertOk();
        Event::fake([MeetupLocationStopped::class]);

        $this->actingAs($this->a)->postJson("/api/meetups/{$this->meetupId}/cancel")->assertOk();
        $this->assertDatabaseMissing('meetup_location_shares', ['meetup_id' => $this->meetupId, 'user_id' => $this->a->id]);
        Event::assertDispatched(MeetupLocationStopped::class, fn ($e) => $e->sharerId === $this->a->id);

        $this->actingAs($this->host)->deleteJson("/api/meetups/{$this->meetupId}")->assertSuccessful();
        $this->assertSame(0, MeetupLocationShare::where('meetup_id', $this->meetupId)->count());
    }

    /** 기간 없음: 약속 시간이 지나도 채팅방이 열려 있으면 계속, 닫히면(24시간 뒤) 끝 */
    public function test_no_time_limit_until_room_closes(): void
    {
        $this->actingAs($this->a)->putJson("/api/meetups/{$this->meetupId}/location-share")->assertOk();

        $this->travel(5)->hours(); // 약속 2시간 지남
        $this->sendLocation($this->a)->assertJsonPath('sharing', true);
        $this->actingAs($this->a)->getJson('/api/me/meetup-location-shares')->assertJsonPath('data.0.meetup_id', $this->meetupId);

        $this->travel(23)->hours(); // 약속 25시간 뒤 → 채팅방 닫힘
        $this->sendLocation($this->a)->assertJsonPath('sharing', false);
        $this->actingAs($this->a)->getJson('/api/me/meetup-location-shares')->assertJsonCount(0, 'data');
        $this->actingAs($this->a)->putJson("/api/meetups/{$this->meetupId}/location-share")->assertStatus(410);
    }

    public function test_stop_sharing(): void
    {
        $this->actingAs($this->a)->putJson("/api/meetups/{$this->meetupId}/location-share")->assertOk();
        $this->actingAs($this->a)->deleteJson("/api/meetups/{$this->meetupId}/location-share")
            ->assertOk()->assertJsonPath('data.me_sharing', false);
        $this->sendLocation($this->a)->assertJsonPath('sharing', false);
    }
}
