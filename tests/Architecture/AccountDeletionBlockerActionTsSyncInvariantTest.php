<?php

declare(strict_types=1);

use App\Enums\AccountDeletionBlockerAction;
use Tests\Support\TsUnionValues;

/*
 * AccountDeletionBlockerAction (PHP enum) ⇔ resources/js/types/account.ts (TS literal union) の
 * 値集合同期 invariant。退会ガードの「次の一手」は wire に載る語彙で、フロントが action 値で
 * 導線を分岐するため、enum 追加が silent に描画漏れへ落ちるのを防ぐ
 * (抽出は共有 helper TsUnionValues。抽出不能 = fail)。
 */

test('AccountDeletionBlockerAction の PHP enum ⇔ TS union 値集合が一致する', function (): void {
    expect(TsUnionValues::extract('resources/js/types/account.ts', 'AccountDeletionBlockerAction'))
        ->toBe(TsUnionValues::enumStringValues(AccountDeletionBlockerAction::cases()));
});

test('account.ts の抽出不能な union 名は fail する (degenerate PASS 防止の自己検証)', function (): void {
    expect(fn (): array => TsUnionValues::extract('resources/js/types/account.ts', 'NoSuchUnionName'))
        ->toThrow(RuntimeException::class, 'degenerate PASS');
});
