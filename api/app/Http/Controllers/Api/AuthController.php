<?php

namespace App\Http\Controllers\Api;

use App\Exceptions\DomainException;
use App\Http\Controllers\Controller;
use App\Http\Resources\MeResource;
use App\Models\SocialAccount;
use App\Models\User;
use App\Services\Auth\SignupService;
use App\Services\Auth\SocialProviderRegistry;
use App\Services\Identity\IdentityVerifier;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class AuthController extends Controller
{
    public function __construct(private readonly SignupService $signup) {}

    /**
     * POST /api/auth/social  {provider, token}
     * 기존 회원 → {status:"login", token, user} / 신규 → {status:"signup", signup_token}
     */
    public function social(Request $request, SocialProviderRegistry $providers): JsonResponse
    {
        $data = $request->validate([
            'provider' => ['required', Rule::in(['kakao', 'apple'])],
            'token' => ['required', 'string', 'max:4096'],
        ]);

        $providerUserId = $providers->get($data['provider'])->verify($data['token'])
            ?? throw new DomainException('INVALID_SOCIAL_TOKEN', '로그인 정보를 확인할 수 없어요', 401);

        $account = SocialAccount::with('user')
            ->where('provider', $data['provider'])
            ->where('provider_user_id', $providerUserId)
            ->first();

        if ($account?->user) {
            $user = $account->user;
            if (in_array($user->status, [User::STATUS_BANNED, User::STATUS_WITHDRAWN], true)) {
                throw new DomainException('ACCOUNT_'.strtoupper($user->status), '이용할 수 없는 계정이에요', 403);
            }

            return response()->json([
                'status' => 'login',
                'token' => $user->createToken('app')->plainTextToken,
                'user' => new MeResource($user->load('identity', 'profile', 'avatar')),
            ]);
        }

        $token = $this->signup->start($data['provider'], $providerUserId);

        return response()->json([
            'status' => 'signup',
            'signup_token' => $token,
            // 이전에 인증을 마쳤으면 {gender, age, verified} → 앱은 인증 단계를 건너뜀. 아니면 null
            'identity' => $this->signup->verifiedIdentity($token),
        ]);
    }

    /** POST /api/auth/identity  {signup_token, transaction_id} — PASS 인증 결과 연결 */
    public function identity(Request $request, IdentityVerifier $verifier): JsonResponse
    {
        // 비공개 베타: 대행사 없이 직접 입력 (IDENTITY_DRIVER=self)
        if (config('social.drivers.identity') === 'self') {
            return $this->selfDeclared($request);
        }

        $data = $request->validate([
            'signup_token' => ['required', 'string'],
            'transaction_id' => ['required', 'string', 'max:200'],
        ]);

        $result = $verifier->fetch($data['transaction_id'])
            ?? throw new DomainException('IDENTITY_FAILED', '본인인증 결과를 확인할 수 없어요', 422);

        $this->signup->attachIdentity($data['signup_token'], $result);

        // 본인에게만 보여주는 값 (디자인: 인증 완료 카드의 성별·나이)
        return response()->json([
            'status' => 'verified',
            'gender' => $result->gender,
            'age' => $result->birthDate->age,
        ]);
    }

    /**
     * 직접 입력 본인인증  {signup_token, gender: M|F, birth_date: YYYY-MM-DD, adult: true}
     * 나이 제한(만 19세)은 attachIdentity에서 PASS와 똑같이 검사한다.
     */
    private function selfDeclared(Request $request): JsonResponse
    {
        $data = $request->validate([
            'signup_token' => ['required', 'string'],
            'gender' => ['required', Rule::in(['M', 'F'])],
            'birth_date' => ['required', 'date_format:Y-m-d', 'after:1920-01-01', 'before:today'],
            'adult' => ['accepted'], // '만 19세 이상입니다' 체크 (허위 입력 책임 고지)
        ], [
            'adult.accepted' => '만 19세 이상인지 확인해 주세요',
        ]);

        $birth = \Carbon\CarbonImmutable::createFromFormat('Y-m-d', $data['birth_date'])->startOfDay();
        $this->signup->attachSelfDeclared($data['signup_token'], $data['gender'], $birth);

        return response()->json(['status' => 'verified', 'gender' => $data['gender'], 'age' => $birth->age, 'verified' => false]);
    }

    /** POST /api/auth/register  {signup_token, nickname} — 계정 생성 + 토큰 발급 */
    public function register(Request $request): JsonResponse
    {
        $data = $request->validate([
            'signup_token' => ['required', 'string'],
            // 한글·영문·숫자 2~10자 (디자인 기준)
            'nickname' => ['required', 'string', 'min:2', 'max:10', 'regex:/^[가-힣a-zA-Z0-9]+$/u', 'unique:users,nickname'],
            'avatar' => ['nullable', 'array:color,face'],
            'avatar.color' => ['required_with:avatar', Rule::in(config('social.avatar.colors'))],
            'avatar.face' => ['required_with:avatar', Rule::in(config('social.avatar.faces'))],
        ]);

        $user = $this->signup->complete($data['signup_token'], $data['nickname'], $data['avatar'] ?? null);

        return response()->json([
            'token' => $user->createToken('app')->plainTextToken,
            'user' => new MeResource($user->load('identity', 'profile', 'avatar')),
        ], 201);
    }

    /** POST /api/auth/logout — 현재 기기 토큰만 폐기 */
    public function logout(Request $request): JsonResponse
    {
        $request->user()->currentAccessToken()->delete();

        return response()->json(['status' => 'ok']);
    }
}
