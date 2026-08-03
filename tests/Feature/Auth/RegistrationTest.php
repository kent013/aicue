<?php

declare(strict_types=1);

use App\Models\User;
use App\Services\Billing\TicketLedgerService;
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

    // LP が約束する「新規登録で無償チケット」を個人組織へ付与する。
    // 固定値ではなく config 由来値を期待に使う (設定変更後も意味が一貫する)。
    $personalOrg = $user->organizations()->where('is_personal', true)->firstOrFail();
    expect(app(TicketLedgerService::class)->balance($personalOrg)->totalAvailable())
        ->toBe(config()->integer('billing.signup_grant_tickets'));

    // [分岐 B 固定] 通常登録では現在組織が個人組織に確定する (招待成立分岐と排他)
    expect($user->current_organization_id)->toBe($personalOrg->id);
});

test('登録 POST は非本番で api.pwnedpasswords.com を呼ばない (F-4-01 非退行)', function (): void {
    // HIBP エンドポイントのみ intercept して実ネットワークを遮断する
    // (preventStrayRequests は合法な他 HTTP まで例外化するため使わない = 過検出回避)。
    // uncompromised は NotPwnedVerifier (Http client factory 経由) のため Http::fake で捕捉できる。
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
