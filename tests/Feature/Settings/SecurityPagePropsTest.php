<?php

declare(strict_types=1);

use App\Models\Passkey;
use App\Models\User;
use Inertia\Testing\AssertableInertia;
use Laravel\Fortify\Features;

/*
 * Settings/Security の Inertia prop 契約 (passkeys 一覧 / passkeyLoginAvailable)。
 *
 * prop の shape は resources/js/lib/passkeys.ts の PasskeyListItem と 1:1。
 * credential 本体 (公開鍵 / signature counter) を露出しないことも固定する。
 */

test('passkey 未登録なら passkeys は空配列', function (): void {
    $user = User::factory()->create();

    $this->actingAs($user)->get(route('settings.security'))
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('Settings/Security')
            ->where('passkeys', [])
            ->where('passkeyLoginAvailable', true));
});

test('登録済み passkey が一覧 prop に載る (credential 本体は載せない)', function (): void {
    $user = User::factory()->create();
    Passkey::factory()->for($user)->create(['name' => '現場用スマホ']);

    $this->actingAs($user)->get(route('settings.security'))
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('Settings/Security')
            ->has('passkeys', 1, fn (AssertableInertia $item) => $item
                ->has('id')
                ->where('name', '現場用スマホ')
                ->where('authenticator', null)
                ->where('lastUsedAt', null)
                ->has('createdAt')
                ->missing('credential')
                ->missing('credential_id')
                ->missing('user_id')));
});

test('TOTP 有効ユーザーは passkeyLoginAvailable が false (再認証には使える)', function (): void {
    $user = User::factory()->withTwoFactor()->create();

    $this->actingAs($user)->get(route('settings.security'))
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('passkeyLoginAvailable', false));
});

test('feature off では passkeyLoginAvailable が false (キルスイッチ)', function (): void {
    $user = User::factory()->create();

    config()->set(
        'fortify.features',
        array_values(array_filter(
            config()->array('fortify.features'),
            static fn (mixed $feature): bool => $feature !== Features::passkeys(),
        )),
    );

    $this->actingAs($user)->get(route('settings.security'))
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('passkeyLoginAvailable', false));
});

test('他人の passkey は一覧に載らない', function (): void {
    $user = User::factory()->create();
    $other = User::factory()->create();
    Passkey::factory()->for($other)->create();

    $this->actingAs($user)->get(route('settings.security'))
        ->assertInertia(fn (AssertableInertia $page) => $page->where('passkeys', []));
});
