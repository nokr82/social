<?php

namespace Tests\Feature;

use App\Models\Admin;
use App\Models\Report;
use App\Models\User;
use App\Models\UserSanction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * 관리자 화면(Filament) 연결 확인.
 * 화면 클래스의 메서드 시그니처가 설치된 Filament 버전과 안 맞으면 여기서 바로 500으로 드러난다.
 */
class AdminPanelTest extends TestCase
{
    use RefreshDatabase;

    public function test_guest_is_sent_to_admin_login(): void
    {
        $this->get('/admin/reports')->assertRedirect('/admin/login');
        $this->get('/admin/login')->assertOk(); // SPA 캐치올에 먹히지 않음
    }

    /** 앱 사용자 세션으로는 관리자 화면에 못 들어감 (가드 분리) */
    public function test_app_user_cannot_enter(): void
    {
        $user = User::factory()->complete()->create();
        $this->actingAs($user)->get('/admin/reports')->assertRedirect('/admin/login');
    }

    public function test_admin_pages_render(): void
    {
        $admin = Admin::create(['name' => '운영자', 'email' => 'ops@test.local', 'password' => 'password-123456']);
        [$a, $b] = User::factory()->count(2)->complete()->create();
        Report::create(['reporter_id' => $a->id, 'target_id' => $b->id, 'target_type' => 'user', 'reason' => 'abuse']);
        UserSanction::create(['user_id' => $b->id, 'admin_id' => null, 'type' => 'suspend', 'reason' => '테스트']);

        foreach (['/admin', '/admin/reports', '/admin/users', '/admin/user-sanctions'] as $url) {
            $this->actingAs($admin, 'admin')->get($url)->assertOk();
        }
        $this->actingAs($admin, 'admin')->get('/admin/users')->assertSee($b->nickname);
    }

    /** ADMIN_ALLOWED_IPS가 있으면 목록 밖 IP에는 관리자 화면 자체가 없는 것처럼 404 */
    public function test_admin_ip_allowlist(): void
    {
        config(['social.admin_allowed_ips' => '10.0.0.1, 10.0.0.2']);
        $this->get('/admin/login')->assertNotFound();                                   // 테스트 IP 127.0.0.1
        $this->withServerVariables(['REMOTE_ADDR' => '10.0.0.2'])->get('/admin/login')->assertOk();
    }
}
