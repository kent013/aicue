<?php

declare(strict_types=1);

use App\Filament\Resources\OrganizationResource;
use App\Filament\Resources\Organizations\Pages\ViewOrganization;
use App\Filament\Resources\Organizations\RelationManagers\UsersRelationManager;
use App\Filament\Resources\UserResource;
use App\Filament\Resources\Users\Pages\ListUsers;
use App\Models\AdminUser;
use App\Models\Organization;
use App\Models\User;
use Livewire\Livewire;

beforeEach(function (): void {
    $this->actingAs(AdminUser::factory()->withMfa()->create(), 'admin');
});

// --- モデル層: name blind index (whereBlind) ---

test('whereBlind で暗号化 name を完全一致検索できる', function (): void {
    $name = 'Blind '.uniqid();
    $user = User::factory()->create(['name' => $name]);

    $found = User::whereBlind('name', 'name_index', $name)->first();

    expect($found)->not->toBeNull();
    expect($found->id)->toBe($user->id);
});

test('whereBlind の name 検索は不一致値では 0 件になる', function (): void {
    User::factory()->create(['name' => 'Existing '.uniqid()]);

    expect(User::whereBlind('name', 'name_index', 'Nobody '.uniqid())->count())->toBe(0);
});

test('name blind index は Lowercase transformer により大文字小文字を無視して一致する', function (): void {
    $user = User::factory()->create(['name' => 'Taro']);

    $upper = User::whereBlind('name', 'name_index', 'TARO')->first();
    $lower = User::whereBlind('name', 'name_index', 'taro')->first();

    expect($upper?->id)->toBe($user->id);
    expect($lower?->id)->toBe($user->id);
});

test('同姓同名の 2 ユーザーが共存できる (partial unique は email_index 限定)', function (): void {
    // blind_indexes の unique index は name = 'email_index' に限定した partial unique の
    // ため、非ユニークな name_index を追加しても 2 人目の save が制約違反にならない。
    $name = 'Shared Name '.uniqid();
    $a = User::factory()->create(['name' => $name, 'email' => 'a-'.uniqid().'@example.com']);
    $b = User::factory()->create(['name' => $name, 'email' => 'b-'.uniqid().'@example.com']);

    $ids = User::whereBlind('name', 'name_index', $name)->pluck('id')->all();

    expect($ids)->toContain($a->id);
    expect($ids)->toContain($b->id);
});

// --- Filament: ユーザー一覧の blind index 検索 ---

test('一覧 / 詳細ページが表示できる', function (): void {
    $user = User::factory()->create();

    $this->get(UserResource::getUrl('index', panel: 'admin'))->assertOk();
    $this->get(UserResource::getUrl('view', ['record' => $user], panel: 'admin'))->assertOk();
});

test('ユーザー一覧を name の完全一致で検索できる', function (): void {
    $name = 'Searchable '.uniqid();
    $target = User::factory()->create(['name' => $name]);
    $other = User::factory()->create(['name' => 'Other '.uniqid()]);

    Livewire::test(ListUsers::class)
        ->searchTable($name)
        ->assertCanSeeTableRecords([$target])
        ->assertCanNotSeeTableRecords([$other]);
});

test('多語 name も分割されず 1 つの完全一致として検索される (splitSearchTerms 無効)', function (): void {
    // splitSearchTerms(false) が無いと空白区切りでトークン分割され、部分文字列が
    // blind index に一致せず取りこぼす。多語氏名の完全一致で回帰を検出する。
    $target = User::factory()->create(['name' => '山田 太郎']);
    $other = User::factory()->create(['name' => '山田 次郎']);

    Livewire::test(ListUsers::class)
        ->searchTable('山田 太郎')
        ->assertCanSeeTableRecords([$target])
        ->assertCanNotSeeTableRecords([$other]);
});

test('ユーザー一覧を email の完全一致で検索できる', function (): void {
    $email = 'findme-'.uniqid().'@example.com';
    $target = User::factory()->create(['email' => $email]);
    $other = User::factory()->create(['email' => 'nope-'.uniqid().'@example.com']);

    Livewire::test(ListUsers::class)
        ->searchTable($email)
        ->assertCanSeeTableRecords([$target])
        ->assertCanNotSeeTableRecords([$other]);
});

// --- Filament: 組織詳細の UsersRelationManager ---

test('組織のユーザー RelationManager が登録されている', function (): void {
    expect(OrganizationResource::getRelations())->toContain(UsersRelationManager::class);
});

test('組織のユーザー RelationManager をメンバー name の完全一致で検索できる', function (): void {
    $organization = Organization::factory()->create();
    $name = 'Member '.uniqid();
    $target = User::factory()->create(['name' => $name]);
    $other = User::factory()->create(['name' => 'Stranger '.uniqid()]);
    $organization->users()->attach([$target->id, $other->id]);

    Livewire::test(UsersRelationManager::class, [
        'ownerRecord' => $organization,
        'pageClass' => ViewOrganization::class,
    ])
        ->searchTable($name)
        ->assertCanSeeTableRecords([$target])
        ->assertCanNotSeeTableRecords([$other]);
});

test('組織のユーザー RelationManager には他組織のメンバーが混ざらない', function (): void {
    $organization = Organization::factory()->create();
    $otherOrganization = Organization::factory()->create();
    $member = User::factory()->create();
    $outsider = User::factory()->create();
    $organization->users()->attach($member);
    $otherOrganization->users()->attach($outsider);

    Livewire::test(UsersRelationManager::class, [
        'ownerRecord' => $organization,
        'pageClass' => ViewOrganization::class,
    ])
        ->assertCanSeeTableRecords([$member])
        ->assertCanNotSeeTableRecords([$outsider]);
});
