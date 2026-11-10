<?php

namespace Database\Factories;

use App\Models\User;
use App\Services\Auth\SignupService;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<User>
 */
class UserFactory extends Factory
{
    public function definition(): array
    {
        return [
            'nickname' => 'u'.Str::lower(Str::random(8)),
        ];
    }

    /**
     * 가입을 마친 사용자처럼 부속 데이터(본인인증·프로필·아바타·매너점수)까지 생성.
     * 사용: User::factory()->complete()->create()
     */
    public function complete(string $gender = 'F', string $birth = '1995-05-05'): static
    {
        return $this->afterCreating(function (User $user) use ($gender, $birth) {
            $user->identity()->create([
                'ci_hash' => SignupService::hashCi('ci-'.$user->id.'-'.Str::random(6)),
                'gender' => $gender,
                'birth_date' => $birth,
                'provider' => 'fake',
                'verified_at' => now(),
            ]);
            $user->profile()->create([]);
            $user->trustScore()->create([]);
            $user->avatar()->create(['parts' => SignupService::defaultAvatar()]);
        });
    }

    public function suspended(): static
    {
        return $this->state(fn () => ['status' => User::STATUS_SUSPENDED]);
    }
}
