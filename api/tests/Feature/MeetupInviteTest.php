<?php

namespace Tests\Feature;

use App\Models\ChatMessage;
use App\Models\Friendship;
use App\Models\Plaza;
use App\Models\User;
use App\Services\Plaza\PlazaService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** 번개 친구 초대: 1:1 대화방 카드, 같은 친구 1번, 초대는 입장권 아님(연령·정원), 제한 */
class MeetupInviteTest extends TestCase
{
    use RefreshDatabase;

    private User $host;
    private User $friend;
    private Plaza $plaza;

    protected function setUp(): void
    {
        parent::setUp();
        $this->host = User::factory()->complete('F', now()->subYears(30)->subDay()->toDateString())->create(); // 만 30세
        $this->friend = User::factory()->complete('M', now()->subYears(31)->toDateString())->create();
        $this->befriend($this->host, $this->friend);
        $this->plaza = app(PlazaService::class)->create($this->host, ['name' => '역삼 수다방', 'category' => 'talk'], 37.5006, 127.0364);
    }

    private function befriend(User $a, User $b): void
    {
        Friendship::query()->insert([
            ['user_id' => $a->id, 'friend_id' => $b->id, 'created_at' => now()],
            ['user_id' => $b->id, 'friend_id' => $a->id, 'created_at' => now()],
        ]);
    }

    private function makeMeetup(array $over = []): int
    {
        return $this->actingAs($this->host)->postJson("/api/plazas/{$this->plaza->id}/meetups", $over + [
            'title' => '퇴근하고 한잔', 'area_label' => '역삼역 근처', 'place_detail' => '3번 출구',
            'starts_at' => now()->addHours(3)->toIso8601String(), 'capacity' => 4, 'age_rule' => 'any',
        ])->assertCreated()->json('data.id');
    }

    private function statusOf(array $data, User $u): ?string
    {
        return collect($data['friends'])->firstWhere('user.id', $u->id)['status'] ?? null;
    }

    /** 초대 → 1:1 대화방에 초대 카드, 상태 '초대함', 같은 친구 두 번째는 안 보냄 */
    public function test_invite_sends_dm_card_once(): void
    {
        $id = $this->makeMeetup();

        $data = $this->actingAs($this->host)->getJson("/api/meetups/{$id}/invites")->assertOk()->json('data');
        $this->assertSame('available', $this->statusOf($data, $this->friend));
        $this->assertSame(20, $data['remaining']);

        $res = $this->actingAs($this->host)->postJson("/api/meetups/{$id}/invites", ['user_ids' => [$this->friend->id]])
            ->assertOk()->assertJsonPath('data.sent', 1)->json('data');
        $this->assertSame('invited', $this->statusOf($res, $this->friend));
        $this->assertSame(19, $res['remaining']);

        $card = ChatMessage::where('type', ChatMessage::MEETUP)->sole();
        $this->assertSame($id, $card->meta['meetup_id']);
        $this->assertTrue($card->meta['invite']);

        // 친구 쪽 대화방에서 초대 카드로 보임
        $this->actingAs($this->friend)->getJson("/api/chats/{$card->room_id}/messages")
            ->assertJsonPath('data.0.invite', true)
            ->assertJsonPath('data.0.meetup.id', $id);

        $this->actingAs($this->host)->postJson("/api/meetups/{$id}/invites", ['user_ids' => [$this->friend->id]])
            ->assertJsonPath('data.sent', 0);
        $this->assertSame(1, ChatMessage::where('type', ChatMessage::MEETUP)->count());
    }

    /** 다른 참여자가 같은 친구를 불러도 카드는 1장 · 참여한 친구는 '참여 중' */
    public function test_one_invite_per_friend_across_members(): void
    {
        $id = $this->makeMeetup();
        $member = User::factory()->complete()->create();
        $this->befriend($member, $this->friend);
        $this->actingAs($member)->postJson("/api/meetups/{$id}/join")->assertOk();

        $this->actingAs($this->host)->postJson("/api/meetups/{$id}/invites", ['user_ids' => [$this->friend->id]])->assertJsonPath('data.sent', 1);
        $this->actingAs($member)->postJson("/api/meetups/{$id}/invites", ['user_ids' => [$this->friend->id]])->assertJsonPath('data.sent', 0);

        $this->actingAs($this->friend)->postJson("/api/meetups/{$id}/join")->assertOk();
        $data = $this->actingAs($this->host)->getJson("/api/meetups/{$id}/invites")->json('data');
        $this->assertSame('joined', $this->statusOf($data, $this->friend));
    }

    /** 초대는 입장권이 아님: 연령 조건이 안 맞으면 목록에서 '조건이 안 맞아요', 보내도 건너뜀, 직접 참여도 거절 */
    public function test_age_rule_still_applies(): void
    {
        $id = $this->makeMeetup(['age_rule' => '30s']);
        $older = User::factory()->complete('M', now()->subYears(45)->toDateString())->create();
        $this->befriend($this->host, $older);

        $data = $this->actingAs($this->host)->getJson("/api/meetups/{$id}/invites")->json('data');
        $this->assertSame('ineligible', $this->statusOf($data, $older));

        $this->actingAs($this->host)->postJson("/api/meetups/{$id}/invites", ['user_ids' => [$older->id]])->assertJsonPath('data.sent', 0);
        $this->actingAs($older)->postJson("/api/meetups/{$id}/join")->assertForbidden()->assertJson(['code' => 'AGE_MISMATCH']);
    }

    /** 정원이 찼으면 초대 불가 */
    public function test_full_meetup_cannot_invite(): void
    {
        $id = $this->makeMeetup(['capacity' => 2]);
        $this->actingAs(User::factory()->complete()->create())->postJson("/api/meetups/{$id}/join")->assertOk();

        $this->actingAs($this->host)->getJson("/api/meetups/{$id}/invites")->assertJsonPath('data.full', true);
        $this->actingAs($this->host)->postJson("/api/meetups/{$id}/invites", ['user_ids' => [$this->friend->id]])
            ->assertStatus(409)->assertJson(['code' => 'MEETUP_FULL']);
    }

    /** 참여자가 아니면 초대 불가 · 친구 아닌 id·차단한 친구는 목록에 없고 보내지지 않음 */
    public function test_permission_boundaries(): void
    {
        $id = $this->makeMeetup();
        $outsider = User::factory()->complete()->create();
        $this->befriend($outsider, $this->friend);
        $this->actingAs($outsider)->getJson("/api/meetups/{$id}/invites")->assertForbidden();
        $this->actingAs($outsider)->postJson("/api/meetups/{$id}/invites", ['user_ids' => [$this->friend->id]])->assertForbidden();

        $stranger = User::factory()->complete()->create();
        $this->actingAs($this->host)->postJson("/api/meetups/{$id}/invites", ['user_ids' => [$stranger->id]])->assertJsonPath('data.sent', 0);

        $this->actingAs($this->host)->postJson('/api/blocks', ['user_id' => $this->friend->id])->assertSuccessful();
        $data = $this->actingAs($this->host)->getJson("/api/meetups/{$id}/invites")->json('data');
        $this->assertNull($this->statusOf($data, $this->friend));
    }

    /** 끝난 번개는 초대 불가 */
    public function test_started_meetup_cannot_invite(): void
    {
        $id = $this->makeMeetup();
        $this->travel(4)->hours();
        $this->actingAs($this->host)->getJson("/api/meetups/{$id}/invites")->assertStatus(410);
    }

    /** 한 사람이 번개 하나에서 20명까지 */
    public function test_invite_limit(): void
    {
        $id = $this->makeMeetup();
        $friends = User::factory()->count(21)->complete()->create();
        foreach ($friends as $f) {
            $this->befriend($this->host, $f);
        }

        $this->actingAs($this->host)->postJson("/api/meetups/{$id}/invites", ['user_ids' => $friends->take(20)->pluck('id')->all()])
            ->assertOk()->assertJsonPath('data.sent', 20)->assertJsonPath('data.remaining', 0);
        $this->actingAs($this->host)->postJson("/api/meetups/{$id}/invites", ['user_ids' => [$friends->last()->id]])
            ->assertStatus(422)->assertJson(['code' => 'INVITE_LIMIT']);
    }
}
