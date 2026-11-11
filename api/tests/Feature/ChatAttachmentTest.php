<?php

namespace Tests\Feature;

use App\Models\Friendship;
use App\Models\User;
use App\Services\Photo\ImageProcessor;
use App\Services\Plaza\PlazaService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/** 채팅 첨부: DM·번개방 사진, DM 번개 카드 공유 */
class ChatAttachmentTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake(config('social.photos.disk')); // 실제 디스크에 쓰지 않음
    }

    private function friends(User $a, User $b): int
    {
        Friendship::insert([
            ['user_id' => $a->id, 'friend_id' => $b->id, 'created_at' => now()],
            ['user_id' => $b->id, 'friend_id' => $a->id, 'created_at' => now()],
        ]);

        return $this->actingAs($a)->postJson("/api/dm/{$b->id}")->json('room_id');
    }

    private function upload(User $u, string $url)
    {
        return $this->actingAs($u)->post($url, ['image' => UploadedFile::fake()->image('a.jpg', 2000, 1500)], ['Accept' => 'application/json']);
    }

    private function meetup(User $host): int
    {
        $plaza = app(PlazaService::class)->create($host, ['name' => '역삼 수다방', 'category' => 'talk'], 37.5006, 127.0364);

        return $this->actingAs($host)->postJson("/api/plazas/{$plaza->id}/meetups", [
            'title' => '퇴근하고 한잔', 'area_label' => '역삼', 'place_detail' => 'OO호프',
            'starts_at' => now()->addHours(3)->toIso8601String(), 'capacity' => 4, 'age_rule' => 'any',
        ])->assertCreated()->json('data.id');
    }

    public function test_dm_image_is_resized_and_served_by_signed_url(): void
    {
        [$a, $b] = User::factory()->count(2)->complete()->create();
        $room = $this->friends($a, $b);

        $res = $this->upload($a, "/api/chats/{$room}/images")->assertCreated()
            ->assertJsonPath('data.type', 'image')
            // 2000x1500 → 긴 변이 서버 기준(ImageProcessor::MAX_SIDE)으로, 비율 유지
            ->assertJsonPath('data.image.width', ImageProcessor::MAX_SIDE)
            ->assertJsonPath('data.image.height', (int) round(ImageProcessor::MAX_SIDE * 1500 / 2000));

        $url = $res->json('data.image.url');
        $this->get($url)->assertOk();                                  // 서명 URL로만
        $this->get(preg_replace('/signature=\w+/', 'signature=x', $url))->assertForbidden();

        // 채팅 목록 미리보기
        $this->actingAs($b)->getJson('/api/chats')
            ->assertJsonPath('data.0.last_message.type', 'image')
            ->assertJsonPath('data.0.last_message.body', '사진을 보냈어요');
    }

    /** 방 멤버가 아니면 거절되고, 파일도 저장되지 않음 */
    public function test_outsider_upload_is_rejected_before_storing(): void
    {
        [$a, $b, $c] = User::factory()->count(3)->complete()->create();
        $room = $this->friends($a, $b);

        $this->upload($c, "/api/chats/{$room}/images")->assertNotFound();
        $this->assertSame([], Storage::disk(config('social.photos.disk'))->allFiles());
    }

    public function test_share_meetup_card_in_dm(): void
    {
        [$host, $a, $b] = User::factory()->count(3)->complete()->create();
        $id = $this->meetup($host);
        $room = $this->friends($a, $b);

        // 참여 안 한 번개는 공유 불가
        $this->actingAs($a)->postJson("/api/chats/{$room}/meetups", ['meetup_id' => $id])->assertForbidden();

        $this->actingAs($a)->postJson("/api/meetups/{$id}/join")->assertOk();
        $this->actingAs($a)->postJson("/api/chats/{$room}/meetups", ['meetup_id' => $id])->assertCreated()
            ->assertJsonPath('data.type', 'meetup')
            ->assertJsonPath('data.meetup.title', '퇴근하고 한잔')
            ->assertJsonPath('data.meetup.joined_count', 2);

        // 받은 사람이 볼 때도 최신 상태 (여기선 B가 아직 참여 전이므로 2명)
        $this->actingAs($b)->getJson("/api/chats/{$room}/messages")
            ->assertJsonPath('data.0.meetup.id', $id)
            ->assertJsonPath('data.0.meetup.is_upcoming', true);

        // 없는 번개
        $this->actingAs($a)->postJson("/api/chats/{$room}/meetups", ['meetup_id' => 999999])->assertNotFound();
    }

    public function test_meetup_room_image_members_only(): void
    {
        [$host, $guest, $outsider] = User::factory()->count(3)->complete()->create();
        $id = $this->meetup($host);
        $this->actingAs($guest)->postJson("/api/meetups/{$id}/join")->assertOk();

        $this->upload($guest, "/api/meetups/{$id}/images")->assertCreated()->assertJsonPath('data.type', 'image');
        $this->assertContains($this->upload($outsider, "/api/meetups/{$id}/images")->status(), [403, 404]);
        $this->assertCount(1, Storage::disk(config('social.photos.disk'))->allFiles());
    }
}
