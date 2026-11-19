<?php

namespace Tests\Feature;

use App\Models\Block;
use App\Models\Meetup;
use App\Models\Plaza;
use App\Models\User;
use App\Services\Plaza\PlazaService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** 광장·번개 공유 링크와 공개 미리보기 페이지 */
class ShareTest extends TestCase
{
    use RefreshDatabase;

    private const LAT = 37.5006;
    private const LNG = 127.0364;

    private User $host;
    private Plaza $plaza;

    protected function setUp(): void
    {
        parent::setUp();
        $this->host = User::factory()->complete()->create();
        $this->plaza = app(PlazaService::class)->create(
            $this->host, ['name' => '역삼 퇴근길 수다방', 'category' => 'talk', 'description' => '퇴근하고 수다 떨어요'], self::LAT, self::LNG,
        );
        $this->actingAs($this->host)->postJson("/api/plazas/{$this->plaza->id}/enter", ['lat' => self::LAT, 'lng' => self::LNG])->assertOk();
    }

    private function codeOf(string $url): string
    {
        return basename(parse_url($url, PHP_URL_PATH));
    }

    public function test_plaza_link_only_for_members_and_stable(): void
    {
        $outsider = User::factory()->complete()->create();
        $this->actingAs($outsider)->postJson("/api/plazas/{$this->plaza->id}/share")
            ->assertForbidden()->assertJsonPath('code', 'NOT_IN_PLAZA');

        $first = $this->actingAs($this->host)->postJson("/api/plazas/{$this->plaza->id}/share")->assertOk()->json('data.url');
        $this->assertMatchesRegularExpression('#/s/p/[a-z0-9]{8}$#', $first);

        // 다른 참여자가 눌러도 같은 링크 (코드는 광장당 하나)
        $member = User::factory()->complete()->create();
        $this->actingAs($member)->postJson("/api/plazas/{$this->plaza->id}/enter", ['lat' => self::LAT, 'lng' => self::LNG])->assertOk();
        $this->assertSame($first, $this->actingAs($member)->postJson("/api/plazas/{$this->plaza->id}/share")->json('data.url'));
    }

    /** 미리보기: 로그인 없이, og 태그 포함, 대화 내용·좌표는 없음 */
    public function test_plaza_preview_is_public_and_hides_chat(): void
    {
        $this->actingAs($this->host)->postJson("/api/plazas/{$this->plaza->id}/messages", ['body' => '비밀 대화 내용'])->assertCreated();
        $code = $this->codeOf($this->actingAs($this->host)->postJson("/api/plazas/{$this->plaza->id}/share")->json('data.url'));

        $this->app['auth']->forgetGuards(); // 로그인 안 한 사람(카카오 미리보기 봇 포함)
        $res = $this->get("/s/p/{$code}")->assertOk();
        $res->assertSee('<meta property="og:title" content="[광장] 역삼 퇴근길 수다방">', false);
        $res->assertSee('property="og:image"', false);
        $res->assertSee('퇴근하고 수다 떨어요');
        $res->assertSee("/plaza/{$this->plaza->id}", false);   // 앱에서 보기
        $res->assertDontSee('비밀 대화 내용');
        $res->assertDontSee((string) $this->plaza->lat);
    }

    public function test_closed_plaza_preview_and_unknown_code(): void
    {
        $code = $this->codeOf($this->actingAs($this->host)->postJson("/api/plazas/{$this->plaza->id}/share")->json('data.url'));
        app(PlazaService::class)->close($this->plaza->fresh(), 'host_closed');

        $this->get("/s/p/{$code}")->assertOk()->assertSee('이미 끝났거나 닫혔어요')->assertDontSee('퇴근하고 수다 떨어요');
        $this->actingAs($this->host)->postJson("/api/plazas/{$this->plaza->id}/share")->assertStatus(410);

        // 없는 코드·형식이 틀린 코드는 404 (Vue 앱으로 넘어가지 않음)
        $this->get('/s/p/zzzzzzzz')->assertNotFound();
        $this->get('/s/p/123')->assertNotFound();
    }

    /** 번개 미리보기: 정확한 장소는 절대 안 보임, 조건 문구는 앱과 같음 */
    public function test_meetup_preview_hides_exact_place(): void
    {
        $id = $this->actingAs($this->host)->postJson("/api/plazas/{$this->plaza->id}/meetups", [
            'title' => '퇴근하고 한잔해요', 'area_label' => '역삼역 근처', 'place_detail' => '역삼역 3번 출구 앞 OO호프',
            'starts_at' => now()->addHours(3)->toIso8601String(), 'capacity' => 4, 'age_rule' => 'near',
        ])->assertCreated()->json('data.id');

        $viewer = User::factory()->complete()->create();
        $code = $this->codeOf($this->actingAs($viewer)->postJson("/api/meetups/{$id}/share")->assertOk()->json('data.url'));

        $this->app['auth']->forgetGuards();
        $this->get("/s/m/{$code}")->assertOk()
            ->assertSee('[번개] 퇴근하고 한잔해요', false)
            ->assertSee('역삼역 근처')
            ->assertSee('호스트와 비슷한 나이')
            ->assertDontSee('3번 출구')
            ->assertDontSee('OO호프');

        // 차단 관계면 없는 번개처럼 404, 끝난 번개는 공유 불가
        $blocked = User::factory()->complete()->create();
        Block::create(['blocker_id' => $this->host->id, 'blocked_id' => $blocked->id]);
        $this->actingAs($blocked)->postJson("/api/meetups/{$id}/share")->assertNotFound();
        $this->travel(4)->hours();
        $this->actingAs($viewer)->postJson("/api/meetups/{$id}/share")->assertStatus(410);
        $this->get("/s/m/{$code}")->assertOk()->assertSee('이미 끝났거나 닫혔어요');
    }

    public function test_town_plaza_is_not_shareable(): void
    {
        $town = $this->actingAs($this->host)->getJson('/api/free-plaza')->json('data.id');
        $this->actingAs($this->host)->postJson("/api/plazas/{$town}/share")->assertStatus(409)->assertJsonPath('code', 'NOT_SHAREABLE');
    }

    /** og:image 주소에서 경로·v 뽑기 */
    private function ogImageOf(string $html): string
    {
        preg_match('/<meta property="og:image" content="([^"]+)"/', $html, $m);
        $this->assertNotEmpty($m, 'og:image 태그가 있어야 함');

        return parse_url(html_entity_decode($m[1]), PHP_URL_PATH).'?'.parse_url(html_entity_decode($m[1]), PHP_URL_QUERY);
    }

    /** 카톡 카드 이미지: 1200×630 PNG · 인원이 바뀌면 주소(v)도 바뀜 · 끝난 번개도 이미지 있음 */
    public function test_card_image_is_large_png_and_versioned(): void
    {
        $id = $this->actingAs($this->host)->postJson("/api/plazas/{$this->plaza->id}/meetups", [
            'title' => '퇴근하고 한잔해요', 'area_label' => '역삼역 근처', 'place_detail' => '3번 출구',
            'starts_at' => now()->addHours(3)->toIso8601String(), 'capacity' => 4, 'age_rule' => 'any',
        ])->assertCreated()->json('data.id');
        $code = $this->codeOf($this->actingAs($this->host)->postJson("/api/meetups/{$id}/share")->json('data.url'));
        $this->app['auth']->forgetGuards();

        $page = $this->get("/s/m/{$code}")->assertOk()
            ->assertSee('<meta property="og:image:width" content="1200">', false)
            ->assertSee('summary_large_image', false);
        $img = $this->ogImageOf($page->getContent());
        $this->assertStringStartsWith("/s/m/{$code}/og.png?v=", $img);

        $res = $this->get($img)->assertOk()->assertHeader('Content-Type', 'image/png');
        $this->assertStringContainsString('immutable', $res->headers->get('Cache-Control'));
        [$w, $h] = getimagesize($res->baseResponse->getFile()->getPathname());
        $this->assertSame([1200, 630], [$w, $h]);

        // 옛 주소(v 다름)로 와도 지금 그림을 주되 짧게 캐시
        $this->assertStringNotContainsString('immutable', $this->get("/s/m/{$code}/og.png?v=old")->headers->get('Cache-Control'));

        // 인원이 바뀌면 이미지 주소도 바뀜 (카톡이 옛 그림을 계속 보여주지 않게)
        $this->actingAs(User::factory()->complete()->create())->postJson("/api/meetups/{$id}/join")->assertOk();
        $this->app['auth']->forgetGuards();
        $img2 = $this->ogImageOf($this->get("/s/m/{$code}")->getContent());
        $this->assertNotSame($img, $img2);

        // 끝난 번개도 이미지(끝났어요)
        $this->travel(4)->hours();
        $this->get($this->ogImageOf($this->get("/s/m/{$code}")->getContent()))->assertOk();

        $this->get('/s/m/zzzzzzzz/og.png')->assertNotFound();
    }
}
