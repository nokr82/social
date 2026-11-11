<?php

namespace Tests\Feature;

use App\Models\Meetup;
use App\Models\Plaza;
use App\Models\User;
use App\Services\Plaza\PlazaService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MeetupTest extends TestCase
{
    use RefreshDatabase;

    private Plaza $plaza;
    private User $host;

    protected function setUp(): void
    {
        parent::setUp();
        $this->host = User::factory()->complete('F', now()->subYears(30)->subDay()->toDateString())->create(); // 만 30세
        $this->plaza = app(PlazaService::class)->create($this->host, ['name' => '역삼 수다방', 'category' => 'talk'], 37.5006, 127.0364);
    }

    private function makeMeetup(array $over = []): int
    {
        return $this->actingAs($this->host)->postJson("/api/plazas/{$this->plaza->id}/meetups", $over + [
            'title' => '퇴근하고 한잔해요',
            'area_label' => '역삼역 근처',
            'place_detail' => '역삼역 3번 출구 앞 OO호프',
            'starts_at' => now()->addHours(3)->toIso8601String(),
            'capacity' => 4,
            'age_rule' => 'any',
        ])->assertCreated()->json('data.id');
    }

    public function test_only_within_48_hours(): void
    {
        $this->actingAs($this->host)->postJson("/api/plazas/{$this->plaza->id}/meetups", [
            'title' => '먼 약속', 'area_label' => '역삼', 'place_detail' => 'x', 'capacity' => 4,
            'starts_at' => now()->addHours(49)->toIso8601String(),
        ])->assertUnprocessable()->assertJson(['code' => 'INVALID_TIME']);
    }

    /** 정확한 장소는 참여 확정자에게만 */
    public function test_place_revealed_only_after_join(): void
    {
        $id = $this->makeMeetup();
        $guest = User::factory()->complete()->create();

        $this->actingAs($guest)->getJson("/api/meetups/{$id}")
            ->assertJsonPath('data.place_detail', null)
            ->assertJsonPath('data.area_label', '역삼역 근처')
            ->assertJsonPath('data.my_status', 'none');

        $this->actingAs($guest)->postJson("/api/meetups/{$id}/join")
            ->assertJsonPath('data.place_detail', '역삼역 3번 출구 앞 OO호프')
            ->assertJsonPath('data.my_status', 'joined')
            ->assertJsonPath('data.joined_count', 2);
    }

    public function test_capacity(): void
    {
        $id = $this->makeMeetup(['capacity' => 2]);
        [$a, $b] = User::factory()->count(2)->complete()->create();

        $this->actingAs($a)->postJson("/api/meetups/{$id}/join")->assertOk();
        $this->actingAs($b)->postJson("/api/meetups/{$id}/join")->assertStatus(409)->assertJson(['code' => 'MEETUP_FULL']);
    }

    /** '내 나이 ±5세': 서버가 확인만, 조건 문구엔 호스트 나이를 드러내지 않음 */
    public function test_age_rule_near_hides_host_age(): void
    {
        $id = $this->makeMeetup(['age_rule' => 'near']); // 호스트 30세 → 25~35
        $ok = User::factory()->complete('M', now()->subYears(33)->toDateString())->create();
        $no = User::factory()->complete('M', now()->subYears(40)->toDateString())->create();

        $res = $this->actingAs($ok)->getJson("/api/meetups/{$id}");
        $this->assertStringNotContainsString('25', $res->json('data.condition'));
        $this->assertStringNotContainsString('35', $res->json('data.condition'));

        $this->actingAs($ok)->postJson("/api/meetups/{$id}/join")->assertOk();
        $this->actingAs($no)->postJson("/api/meetups/{$id}/join")->assertForbidden()->assertJson(['code' => 'AGE_MISMATCH']);
    }

    public function test_cannot_join_with_blocked_member(): void
    {
        $id = $this->makeMeetup();
        $troll = User::factory()->complete()->create();
        $this->actingAs($this->host)->postJson('/api/blocks', ['user_id' => $troll->id]);

        $this->actingAs($troll)->getJson("/api/meetups/{$id}")->assertNotFound(); // 차단한 호스트의 번개는 안 보임
        $this->actingAs($troll)->postJson("/api/meetups/{$id}/join")->assertForbidden();
    }

    /** 1시간 전까지는 자유 취소, 이후는 늦은 취소 */
    public function test_cancel_free_then_late(): void
    {
        $id = $this->makeMeetup(); // 3시간 후
        [$a, $b] = User::factory()->count(2)->complete()->create();
        $this->actingAs($a)->postJson("/api/meetups/{$id}/join");
        $this->actingAs($b)->postJson("/api/meetups/{$id}/join");

        $this->actingAs($a)->postJson("/api/meetups/{$id}/cancel")->assertJson(['status' => 'canceled']);

        $this->travelTo(now()->addHours(2)->addMinutes(30)); // 시작 30분 전
        $this->actingAs($b)->postJson("/api/meetups/{$id}/cancel")->assertJson(['status' => 'late_canceled']);

        $this->actingAs($this->host)->postJson("/api/meetups/{$id}/cancel")->assertUnprocessable(); // 호스트는 '번개 취소'로
    }

    public function test_host_cancel_closes_meetup(): void
    {
        $id = $this->makeMeetup();
        $guest = User::factory()->complete()->create();
        $this->actingAs($guest)->postJson("/api/meetups/{$id}/join");

        $this->actingAs($this->host)->deleteJson("/api/meetups/{$id}")->assertOk();

        $this->assertSame(Meetup::CANCELED, Meetup::find($id)->status);
        $this->actingAs($guest)->postJson("/api/meetups/{$id}/messages", ['body' => '?'])->assertStatus(410);
    }

    /** 노쇼: 약속 시간 전엔 체크 불가, 2표에 확정, 감점은 1번만 */
    public function test_noshow_mutual_confirmation(): void
    {
        $id = $this->makeMeetup(['capacity' => 5]);
        [$a, $b, $c] = User::factory()->count(3)->complete()->create();
        foreach ([$a, $b, $c] as $u) {
            $this->actingAs($u)->postJson("/api/meetups/{$id}/join")->assertOk();
        }

        $this->actingAs($a)->postJson("/api/meetups/{$id}/noshow", ['user_id' => $c->id])->assertUnprocessable(); // 아직 전

        $this->travelTo(now()->addHours(3)->addMinutes(10)); // 약속 시간 지남
        $this->actingAs($a)->postJson("/api/meetups/{$id}/noshow", ['user_id' => $c->id])->assertJson(['votes' => 1, 'needed' => 2, 'confirmed' => false]);
        $this->actingAs($a)->postJson("/api/meetups/{$id}/noshow", ['user_id' => $c->id])->assertJson(['votes' => 1]); // 같은 사람 중복 1표
        $this->actingAs($b)->postJson("/api/meetups/{$id}/noshow", ['user_id' => $c->id])->assertJson(['votes' => 2, 'confirmed' => true]);
        $this->actingAs($this->host)->postJson("/api/meetups/{$id}/noshow", ['user_id' => $c->id])->assertJson(['votes' => 3]);

        $score = $c->trustScore()->first();
        $this->assertSame(1, (int) $score->noshow_count);                                  // 감점은 1번만
        $this->assertSame(500 - config('social.trust.noshow_penalty'), (int) $score->score);

        $this->actingAs($a)->postJson("/api/meetups/{$id}/noshow", ['user_id' => $a->id])->assertUnprocessable(); // 자기 자신 불가
    }

    /** 2인 번개는 상대 1표로 확정 */
    public function test_noshow_two_person_meetup(): void
    {
        $id = $this->makeMeetup(['capacity' => 2]);
        $guest = User::factory()->complete()->create();
        $this->actingAs($guest)->postJson("/api/meetups/{$id}/join");

        $this->travel(4)->hours();
        $this->actingAs($this->host)->postJson("/api/meetups/{$id}/noshow", ['user_id' => $guest->id])
            ->assertJson(['needed' => 1, 'confirmed' => true]);
    }

    public function test_chat_members_only_and_system_messages(): void
    {
        $id = $this->makeMeetup();
        $guest = User::factory()->complete()->create();
        $stranger = User::factory()->complete()->create();

        $this->actingAs($stranger)->getJson("/api/meetups/{$id}/messages")->assertForbidden();

        $this->actingAs($guest)->postJson("/api/meetups/{$id}/join");
        $this->actingAs($guest)->postJson("/api/meetups/{$id}/messages", ['body' => '반가워요'])->assertCreated();

        $msgs = $this->actingAs($this->host)->getJson("/api/meetups/{$id}/messages")->json('data');
        $this->assertSame('반가워요', $msgs[0]['body']);
        $this->assertSame('system', $msgs[1]['type']); // '○○님이 참여했어요'

        // 호스트의 '내 번개'에 안 읽은 1개 (안내 메시지는 내가 아닌 참여자가 보냈으므로 포함)
        $this->actingAs($this->host)->getJson('/api/meetups/mine')->assertJsonPath('data.0.unread', 2);
    }

    public function test_arrive_toggle_window(): void
    {
        $id = $this->makeMeetup(); // 3시간 후
        $this->actingAs($this->host)->postJson("/api/meetups/{$id}/arrive")->assertUnprocessable(); // 2시간 전부터

        $this->travel(2)->hours();
        $this->actingAs($this->host)->postJson("/api/meetups/{$id}/arrive")->assertJson(['arrived' => true]);
        $this->actingAs($this->host)->getJson("/api/meetups/{$id}/room")->assertJsonPath('data.members.0.arrived', true);
    }

    public function test_nearby_list(): void
    {
        $this->makeMeetup();
        $viewer = User::factory()->complete()->create();

        $this->actingAs($viewer)->getJson('/api/meetups?lat=37.5010&lng=127.0370')
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.plaza.name', '역삼 수다방')
            ->assertJsonPath('data.0.distance_label', '500m 이내');
        $this->actingAs($viewer)->getJson('/api/meetups?lat=35.1&lng=129.0')->assertJsonCount(0, 'data');
    }
}
