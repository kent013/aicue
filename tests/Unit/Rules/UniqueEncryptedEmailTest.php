<?php

declare(strict_types=1);

use App\Models\User;
use App\Rules\UniqueEncryptedEmail;
use Illuminate\Support\Facades\Validator;

test('既存 email と衝突すると中立既定メッセージで fail する', function (): void {
    User::factory()->create(['email' => 'taken@example.com']);

    $validator = Validator::make(
        ['email' => 'taken@example.com'],
        ['email' => [new UniqueEncryptedEmail]],
    );

    expect($validator->fails())->toBeTrue()
        // 既定は登録有無を露呈しない中立文言 (account enumeration 対策)
        ->and($validator->errors()->first('email'))->toBe('このメールアドレスは使用できません。');
});

test('未登録 email は pass する', function (): void {
    User::factory()->create(['email' => 'taken@example.com']);

    $validator = Validator::make(
        ['email' => 'fresh@example.com'],
        ['email' => [new UniqueEncryptedEmail]],
    );

    expect($validator->passes())->toBeTrue();
});

test('ignoreId 指定で自分自身の email は衝突扱いしない (更新経路)', function (): void {
    $user = User::factory()->create(['email' => 'self@example.com']);

    $validator = Validator::make(
        ['email' => 'self@example.com'],
        ['email' => [new UniqueEncryptedEmail(ignoreId: $user->id)]],
    );

    expect($validator->passes())->toBeTrue();
});

test('ignoreId 指定でも他人の email とは衝突する', function (): void {
    User::factory()->create(['email' => 'taken@example.com']);
    $self = User::factory()->create(['email' => 'self@example.com']);

    $validator = Validator::make(
        ['email' => 'taken@example.com'],
        ['email' => [new UniqueEncryptedEmail(ignoreId: $self->id)]],
    );

    expect($validator->fails())->toBeTrue();
});

test('メッセージを明示 opt-in で上書きできる', function (): void {
    User::factory()->create(['email' => 'taken@example.com']);

    $validator = Validator::make(
        ['email' => 'taken@example.com'],
        ['email' => [new UniqueEncryptedEmail(message: 'このメールアドレスではアカウントを作成できません。')]],
    );

    expect($validator->errors()->first('email'))
        ->toBe('このメールアドレスではアカウントを作成できません。');
});

test('string 以外の値は判定しない (型検証は前段の string rule に委ねる)', function (): void {
    $validator = Validator::make(
        ['email' => ['not' => 'a-string']],
        ['email' => [new UniqueEncryptedEmail]],
    );

    expect($validator->passes())->toBeTrue();
});
