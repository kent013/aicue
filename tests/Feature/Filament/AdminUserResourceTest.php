<?php

declare(strict_types=1);

use App\Filament\Resources\AdminUsers\Pages\CreateAdminUser;
use App\Filament\Resources\AdminUsers\Pages\EditAdminUser;
use App\Models\AdminUser;
use Livewire\Livewire;

/*
|--------------------------------------------------------------------------
| AdminUserResource (WP45: email 暗号化対応)
|--------------------------------------------------------------------------
|
| email は CipherSweet 暗号化カラムのため標準 unique rule では検証できない。
| create 時のみ編集可 + whereBlind カスタム重複検証、edit では disabled (据え置き)。
|
*/

beforeEach(function (): void {
    $this->actingAs(AdminUser::factory()->withMfa()->create(), 'admin');
});

test('管理画面から AdminUser を作成できる (email は暗号化保存)', function (): void {
    Livewire::test(CreateAdminUser::class)
        ->fillForm([
            'name' => '新規管理者',
            'email' => 'new-admin@example.com',
            'password' => 'ValidPassword123',
            'password_confirmation' => 'ValidPassword123',
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    $created = AdminUser::whereBlind('email', 'email_index', 'new-admin@example.com')->first();
    expect($created)->not->toBeNull();
    expect($created?->name)->toBe('新規管理者');
});

test('既存 email と重複する作成は whereBlind カスタム検証で弾かれる', function (): void {
    AdminUser::factory()->create(['email' => 'taken@example.com']);

    Livewire::test(CreateAdminUser::class)
        ->fillForm([
            'name' => '重複管理者',
            'email' => 'taken@example.com',
            'password' => 'ValidPassword123',
            'password_confirmation' => 'ValidPassword123',
        ])
        ->call('create')
        ->assertHasFormErrors(['email']);

    expect(AdminUser::whereBlind('email', 'email_index', 'taken@example.com')->count())->toBe(1);
});

test('編集では email を変更できない (disabled + dehydrated false で据え置き)', function (): void {
    $target = AdminUser::factory()->create(['email' => 'immutable@example.com', 'name' => '編集前']);

    Livewire::test(EditAdminUser::class, ['record' => $target->getRouteKey()])
        ->fillForm(['name' => '編集後'])
        ->call('save')
        ->assertHasNoFormErrors();

    $target->refresh();
    expect($target->name)->toBe('編集後');
    expect($target->email)->toBe('immutable@example.com');
});

test('編集で password 空欄なら password は据え置きされる', function (): void {
    $target = AdminUser::factory()->create(['email' => 'keep-password@example.com']);
    $originalPassword = $target->password;

    Livewire::test(EditAdminUser::class, ['record' => $target->getRouteKey()])
        ->fillForm(['name' => '名前だけ変更'])
        ->call('save')
        ->assertHasNoFormErrors();

    expect($target->refresh()->password)->toBe($originalPassword);
});
