<?php

declare(strict_types=1);

use App\Models\User;
use App\Services\Billing\TicketLedgerService;
use App\Services\Onboarding\IntendedPlanResolver;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

test('登録できる (同意の証跡が記録される)', function (): void {
    $response = $this->post('/register', [
        'name' => '山田 太郎',
        'email' => 'taro@example.com',
        'password' => 'SecurePass1234',
        'terms_accepted' => '1',
    ]);

    // RegisterResponse (Fortify contract bind) がメール認証導線 (verification.notice) へ誘導する
    $response->assertRedirect(route('verification.notice'));
    $this->assertAuthenticated();

    $user = User::whereBlind('email', 'email_index', 'taro@example.com')->firstOrFail();
    expect($user->terms_accepted_at)->not->toBeNull();
    expect($user->consent_version)->toBe(config()->string('legal.consent_version'));

    // P6/F2: 登録では初回無償チケットを付与しない (付与契機はプラン有効化時 =
    // free は PersonalPlanService::activate / paid は customer.subscription.created)。
    // marker も立てない (marker だけ立つと永久に付与されない org になる)。
    $personalOrg = $user->organizations()->where('is_personal', true)->firstOrFail();
    expect(app(TicketLedgerService::class)->balance($personalOrg)->totalAvailable())->toBe(0);
    expect($personalOrg->signup_tickets_granted_at)->toBeNull();

    // [分岐 B 固定] 通常登録では初期組織だけに所属する (招待成立分岐と排他)
    expect($user->organizations()->pluck('organizations.id')->all())->toBe([$personalOrg->id]);

    // P7: plan 意図なしの登録では org-scoped key を作らない。verify 継続導線 (組織 id) は張る。
    expect(session(IntendedPlanResolver::PENDING_KEY))->toBeNull();
    expect(session(IntendedPlanResolver::orgKey($personalOrg)))->toBeNull();
    expect(session('verify_continue_organization_id'))->toBe($personalOrg->id);
});

test('登録 POST は非本番で api.pwnedpasswords.com を呼ばない (F-4-01 非退行)', function (): void {
    // HIBP エンドポイントのみ intercept して「呼ばれないこと」を assert 可能にする。
    // uncompromised は NotPwnedVerifier (Http client factory 経由) のため Http::fake で捕捉できる。
    //
    // ★旧コメントの棄却理由「preventStrayRequests は合法な他 HTTP まで例外化するため
    //   使わない (過検出回避)」は**前提そのものが成立していない**ので撤回した (裁定 AG-105):
    //   (1) 想定されていた「合法な他 HTTP」= HIBP は、app/Support/PasswordPolicy.php の
    //       PWNED_CHECK_DISABLED_APP_ENVS に 'testing' が含まれるため testing env では
    //       uncompromised 自体が付かず、そもそも通信が発生しない
    //       (下の Http::fake は「万一 rule が復活したら捕捉する」保険であって no-op が正常)。
    //   (2) 実際に既定拒否へ掛かるのは api.frankfurter.dev (FxRateService) と reCAPTCHA で、
    //       いずれも**外部宛て = 通してはいけない通信**。過検出ではなく検出である。
    //   (3) 自機宛て loopback は StrayHttpRequestGuard::ALLOWED_URL_PATTERNS で明示許可済み。
    //   現在は tests/Pest.php のレーン既定として preventStrayRequests が常時 ON になっている。
    Http::fake([
        'api.pwnedpasswords.com/*' => Http::response('', 200),
    ]);

    $response = $this->post('/register', [
        'name' => 'Test User',
        'email' => 'newuser@example.com',
        'password' => 'SecurePass1234',
        'terms_accepted' => '1', // 既存 RegistrationTest と同じ表現 (Fortify 契約)
    ]);

    // シナリオ成立を固定 (別要因の早期失敗で「未送信」だけ通るのを防ぐ)。
    // 既存「登録できる」テストと同じく verification.notice へ誘導される。
    $response->assertSessionHasNoErrors();
    $response->assertRedirect(route('verification.notice'));

    // 主アサーション: HIBP エンドポイントへの送出 0 回に限定 (合法な他 HTTP の偽陽性を避ける)。
    Http::assertNotSent(fn (Request $request): bool => str_contains($request->url(), 'api.pwnedpasswords.com'));
})->group('auth');

test('利用規約に同意しないと登録できない', function (): void {
    $response = $this->from('/register')->post('/register', [
        'name' => '山田 太郎',
        'email' => 'taro@example.com',
        'password' => 'SecurePass1234',
    ]);

    $response->assertSessionHasErrors('terms_accepted');
    $this->assertGuest();
});

test('登録済み email では中立メッセージで拒否される (列挙対策)', function (): void {
    User::factory()->create(['email' => 'taken@example.com']);

    $response = $this->from('/register')->post('/register', [
        'name' => '山田 太郎',
        'email' => 'taken@example.com',
        'password' => 'SecurePass1234',
        'terms_accepted' => '1',
    ]);

    $response->assertSessionHasErrors([
        'email' => 'このメールアドレスではアカウントを作成できません。',
    ]);
    $this->assertGuest();
});

test('短いパスワードは拒否される (12 文字ポリシー)', function (): void {
    $response = $this->from('/register')->post('/register', [
        'name' => '山田 太郎',
        'email' => 'taro@example.com',
        'password' => 'Short1',
        'terms_accepted' => '1',
    ]);

    $response->assertSessionHasErrors('password');
    $this->assertGuest();
});
