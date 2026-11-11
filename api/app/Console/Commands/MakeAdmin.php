<?php

namespace App\Console\Commands;

use App\Models\Admin;
use Illuminate\Console\Command;

/**
 * 관리자 계정 만들기: php artisan social:make-admin you@example.com --name=똥우
 * 비밀번호는 화면에 보이지 않게 입력받는다 (쉘 기록에 남지 않도록 인자로 받지 않음).
 */
class MakeAdmin extends Command
{
    protected $signature = 'social:make-admin {email} {--name=관리자}';

    protected $description = '관리자 계정을 만들거나 비밀번호를 바꿉니다';

    public function handle(): int
    {
        $password = $this->secret('비밀번호 (12자 이상)');
        if (strlen((string) $password) < 12) {
            $this->error('비밀번호는 12자 이상이어야 해요.');

            return self::FAILURE;
        }
        if ($password !== $this->secret('비밀번호 확인')) {
            $this->error('비밀번호가 서로 달라요.');

            return self::FAILURE;
        }

        $admin = Admin::updateOrCreate(
            ['email' => $this->argument('email')],
            ['name' => $this->option('name'), 'password' => $password], // casts의 hashed가 해시 처리
        );
        $this->info("관리자 계정 준비 완료: {$admin->email}");

        return self::SUCCESS;
    }
}
