<?php

namespace App\Services\Auth;

use App\Exceptions\DomainException;
use App\Models\BannedIdentity;
use App\Models\SocialAccount;
use App\Models\User;
use App\Models\UserIdentity;
use App\Services\Identity\IdentityResult;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * 가입 흐름 (3단계, 단계 사이 상태는 캐시에 signup_token으로 보관)
 *
 *   1) 소셜 로그인 → 기존 회원이면 바로 토큰 발급, 신규면 signup_token 발급
 *   2) PASS 인증   → 나이·영구정지·중복가입 검사 후 인증 결과를 signup_token에 붙임
 *   3) 닉네임 입력 → 트랜잭션으로 users + 부속 테이블 생성, 토큰 발급
 *
 * 비유: 놀이공원 입장 — 1) 예매 확인 2) 신분증 검사 3) 팔찌 발급.
 *       신분증 검사를 통과하지 않으면 팔찌(계정)는 만들어지지 않는다.
 */
class SignupService
{
    private const KEY = 'signup:';

    /** 1단계: 신규 가입자의 소셜 정보를 담은 진행 토큰 발급 */
    public function start(string $provider, string $providerUserId): string
    {
        $token = Str::random(48);
        Cache::put(self::KEY.$token, compact('provider', 'providerUserId'), config('social.signup.token_ttl'));

        return $token;
    }

    /** 2단계: 본인인증 결과 검증 후 진행 토큰에 저장 */
    public function attachIdentity(string $token, IdentityResult $identity): void
    {
        $state = $this->state($token);

        if ($identity->birthDate->age < config('social.signup.min_age')) {
            throw new DomainException('UNDER_AGE', '만 '.config('social.signup.min_age').'세 이상만 가입할 수 있어요', 403);
        }

        $ciHash = self::hashCi($identity->ci);

        if (BannedIdentity::whereKey($ciHash)->exists()) {
            throw new DomainException('BANNED', '이용이 제한된 사용자예요', 403);
        }
        if (UserIdentity::where('ci_hash', $ciHash)->exists()) {
            // 같은 사람이 다른 소셜 계정으로 또 가입하는 것 차단 (1인 1계정)
            throw new DomainException('ALREADY_REGISTERED', '이미 가입된 사용자예요. 기존 계정으로 로그인해 주세요', 409);
        }

        $state['identity'] = [
            'ci_hash' => $ciHash,
            'gender' => $identity->gender,
            'birth_date' => $identity->birthDate->toDateString(),
            'provider' => $identity->provider,
        ];
        Cache::put(self::KEY.$token, $state, config('social.signup.token_ttl'));
    }

    /**
     * 2단계(베타): 직접 입력. 진짜 CI가 없으므로 '카카오 계정'을 CI 자리에 넣는다.
     * → 같은 카카오 계정 = 같은 사람으로 취급: 영구정지·중복가입 검사가 카카오 계정 단위로 동작
     *   (다른 카카오 계정으로 오면 막지 못함 → 비공개 베타에서만 사용)
     */
    public function attachSelfDeclared(string $token, string $gender, CarbonImmutable $birthDate): void
    {
        $state = $this->state($token);
        $ci = "self:{$state['provider']}:{$state['providerUserId']}";

        $this->attachIdentity($token, new IdentityResult($ci, $gender, $birthDate, 'self'));
    }

    /** 3단계: 계정 생성 */
    public function complete(string $token, string $nickname, ?array $avatar = null): User
    {
        $state = $this->state($token);

        if (empty($state['identity'])) {
            throw new DomainException('IDENTITY_REQUIRED', '본인인증을 먼저 완료해 주세요', 422);
        }

        $user = DB::transaction(function () use ($state, $nickname, $avatar) {
            $user = User::create(['nickname' => $nickname]);

            SocialAccount::create([
                'user_id' => $user->id,
                'provider' => $state['provider'],
                'provider_user_id' => $state['providerUserId'],
            ]);
            UserIdentity::create($state['identity'] + ['user_id' => $user->id, 'verified_at' => now()]);
            $user->profile()->create([]);
            $user->trustScore()->create([]);
            $user->avatar()->create(['parts' => $avatar ?: self::defaultAvatar()]);

            return $user;
        });
        // 동시 요청으로 같은 CI가 두 번 들어오면 ci_hash UNIQUE 제약이 최종 방어선 역할

        Cache::forget(self::KEY.$token);

        return $user;
    }

    /**
     * CI는 원문 대신 HMAC으로 저장. 단순 SHA-256과 달리 비밀값(pepper) 없이는
     * 같은 CI를 넣어 보는 방식으로 대조할 수 없다.
     */
    public static function hashCi(string $ci): string
    {
        $pepper = config('social.signup.ci_pepper');
        if (empty($pepper)) {
            throw new RuntimeException('CI_PEPPER 환경변수가 비어 있습니다.');
        }

        return hash_hmac('sha256', $ci, $pepper);
    }

    public static function defaultAvatar(): array
    {
        return ['face' => 'smile', 'color' => 'apricot'];
    }

    private function state(string $token): array
    {
        return Cache::get(self::KEY.$token)
            ?? throw new DomainException('SIGNUP_EXPIRED', '가입 시간이 만료됐어요. 처음부터 다시 시도해 주세요', 410);
    }
}
