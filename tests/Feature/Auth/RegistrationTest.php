<?php

declare(strict_types=1);

use App\Models\User;

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
});

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
