<?php

declare(strict_types=1);

use App\Models\SecurityAuditEvent;
use App\Models\User;

test('暗号化 email でログインできる (blind index 経由の解決)', function (): void {
    $user = User::factory()->create(['email' => 'login@example.com']);

    $response = $this->post('/login', [
        'email' => 'login@example.com',
        'password' => 'password',
    ]);

    $response->assertRedirect('/dashboard');
    $this->assertAuthenticatedAs($user);
});

test('誤ったパスワードではログインできない', function (): void {
    User::factory()->create(['email' => 'login@example.com']);

    $this->from('/login')->post('/login', [
        'email' => 'login@example.com',
        'password' => 'wrong-password',
    ])->assertSessionHasErrors();

    $this->assertGuest();
});

test('ログインが security_audit_events に記録される', function (): void {
    $user = User::factory()->create(['email' => 'login@example.com']);

    $this->post('/login', [
        'email' => 'login@example.com',
        'password' => 'password',
    ]);

    expect(
        SecurityAuditEvent::query()
            ->where('user_id', $user->id)
            ->where('event_type', 'login')
            ->exists(),
    )->toBeTrue();
});

test('ログイン画面が表示される', function (): void {
    $this->get('/login')->assertStatus(200);
});

test('登録画面が表示される', function (): void {
    $this->get('/register')->assertStatus(200);
});
