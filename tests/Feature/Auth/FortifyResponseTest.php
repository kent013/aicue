<?php

declare(strict_types=1);

use App\Models\User;
use Illuminate\Support\Facades\Notification;

/*
 * Fortify Response contract bind (app/Http/Responses/Fortify/) の応答契約。
 * Login / Register の redirect 契約は AuthenticationTest / RegistrationTest が担う。
 */

test('forgot-password は user 在/不在で同一応答 (enumeration 抑止)', function (): void {
    Notification::fake();
    User::factory()->create(['email' => 'exists@example.com']);

    $existing = $this->from('/forgot-password')->post('/forgot-password', [
        'email' => 'exists@example.com',
    ]);
    $missing = $this->from('/forgot-password')->post('/forgot-password', [
        'email' => 'missing@example.com',
    ]);

    // どちらも同一の status flash + redirect back (成功/失敗を区別させない)
    $existing->assertRedirect('/forgot-password');
    $missing->assertRedirect('/forgot-password');
    $existing->assertSessionHas('status', 'パスワードリセット用のリンクをメールで送信しました。');
    $missing->assertSessionHas('status', 'パスワードリセット用のリンクをメールで送信しました。');
    $missing->assertSessionDoesntHaveErrors();
});
