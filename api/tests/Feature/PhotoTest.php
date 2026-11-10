<?php

namespace Tests\Feature;

use App\Models\Friendship;
use App\Models\ProfilePhoto;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class PhotoTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local'); // 실제 디스크에 쓰지 않음
    }

    private function upload(User $user, int $n = 1): array
    {
        $ids = [];
        for ($i = 0; $i < $n; $i++) {
            $ids[] = $this->actingAs($user)
                ->post('/api/me/photos', ['photo' => UploadedFile::fake()->image("p{$i}.jpg", 2000, 1500)], ['Accept' => 'application/json'])
                ->assertCreated()->json('data.id');
        }

        return $ids;
    }

    private function befriend(User $a, User $b): void
    {
        Friendship::insert([
            ['user_id' => $a->id, 'friend_id' => $b->id, 'created_at' => now()],
            ['user_id' => $b->id, 'friend_id' => $a->id, 'created_at' => now()],
        ]);
    }

    public function test_upload_resizes_and_first_is_main(): void
    {
        $me = User::factory()->complete()->create();
        [$first, $second] = $this->upload($me, 2);

        $photo = ProfilePhoto::find($first);
        $this->assertSame(0, $photo->sort_order);
        // 긴 변 1280px로 축소된 JPEG로 저장
        [$w] = getimagesizefromstring(Storage::disk('local')->get($photo->path));
        $this->assertSame(1280, $w);

        $this->actingAs($me)->getJson('/api/me/photos')
            ->assertJsonPath('data.0.is_main', true)
            ->assertJsonPath('data.1.is_main', false);
    }

    public function test_max_nine_photos(): void
    {
        $me = User::factory()->complete()->create();
        $this->upload($me, 9);

        $this->actingAs($me)
            ->post('/api/me/photos', ['photo' => UploadedFile::fake()->image('x.jpg')], ['Accept' => 'application/json'])
            ->assertStatus(409)->assertJson(['code' => 'PHOTO_LIMIT']);
    }

    public function test_main_photo_cannot_be_private_and_delete_promotes_next(): void
    {
        $me = User::factory()->complete()->create();
        [$main, $second] = $this->upload($me, 2);

        $this->actingAs($me)->patchJson("/api/me/photos/{$main}", ['visibility' => 'private'])
            ->assertUnprocessable()->assertJson(['code' => 'MAIN_PHOTO_PUBLIC']);

        $this->actingAs($me)->patchJson("/api/me/photos/{$second}", ['visibility' => 'private'])->assertOk();
        $this->actingAs($me)->deleteJson("/api/me/photos/{$main}")->assertOk();

        // 남은 사진이 대표가 되면서 자동으로 공개
        $this->assertSame(ProfilePhoto::PUBLIC, ProfilePhoto::find($second)->visibility);
        $this->assertSame(0, ProfilePhoto::find($second)->sort_order);
    }

    /** 친구가 아니면 비공개 사진·성별·나이가 응답에 없다 */
    public function test_private_photos_only_for_friends(): void
    {
        $owner = User::factory()->complete('F', '1995-05-05')->create();
        [, $private] = $this->upload($owner, 2);
        $this->actingAs($owner)->patchJson("/api/me/photos/{$private}", ['visibility' => 'private']);
        $stranger = User::factory()->complete()->create();
        $friend = User::factory()->complete()->create();
        $this->befriend($owner, $friend);

        $this->actingAs($stranger)->getJson("/api/users/{$owner->id}")
            ->assertJsonPath('data.relation', 'none')
            ->assertJsonCount(1, 'data.photos')
            ->assertJsonPath('data.private_count', 1)
            ->assertJsonPath('data.private_photos', null)
            ->assertJsonPath('data.gender', null)
            ->assertJsonPath('data.age', null);

        $this->actingAs($friend)->getJson("/api/users/{$owner->id}")
            ->assertJsonPath('data.relation', 'friends')
            ->assertJsonCount(1, 'data.private_photos')
            ->assertJsonPath('data.gender', 'F');

        // 비공개 사진에 하트: 남은 불가, 친구는 가능
        $this->actingAs($stranger)->postJson("/api/photos/{$private}/like")->assertNotFound();
        $this->actingAs($friend)->postJson("/api/photos/{$private}/like")->assertOk();
    }

    public function test_like_toggle_and_hearts_kept_when_private(): void
    {
        $owner = User::factory()->complete()->create();
        [, $photo] = $this->upload($owner, 2);
        $fan = User::factory()->complete()->create();

        $this->actingAs($fan)->postJson("/api/photos/{$photo}/like")->assertJson(['liked' => true, 'like_count' => 1]);
        $this->actingAs($fan)->postJson("/api/photos/{$photo}/like")->assertJson(['liked' => false, 'like_count' => 0]);
        $this->actingAs($fan)->postJson("/api/photos/{$photo}/like")->assertJson(['liked' => true, 'like_count' => 1]);

        $this->actingAs($owner)->postJson("/api/photos/{$photo}/like")->assertUnprocessable(); // 내 사진엔 불가

        // 비공개로 바꿔도 하트 유지 (MY 받은 하트에 포함)
        $this->actingAs($owner)->patchJson("/api/me/photos/{$photo}", ['visibility' => 'private'])->assertJsonPath('data.like_count', 1);
        $this->actingAs($owner)->getJson('/api/me')->assertJsonPath('data.stats.hearts', 1);
    }

    /** 사진 파일은 서명 URL로만 열린다 */
    public function test_file_requires_signature(): void
    {
        $owner = User::factory()->complete()->create();
        [$id] = $this->upload($owner);
        $url = $this->actingAs($owner)->getJson('/api/me/photos')->json('data.0.url');

        $this->get($url)->assertOk();
        $this->get("/api/photos/{$id}/file")->assertForbidden(); // 서명 없으면 거부
    }

    public function test_blocked_user_cannot_see_profile(): void
    {
        [$a, $b] = User::factory()->count(2)->complete()->create();
        $this->actingAs($a)->postJson('/api/blocks', ['user_id' => $b->id]);

        $this->actingAs($b)->getJson("/api/users/{$a->id}")->assertNotFound();
    }
}
