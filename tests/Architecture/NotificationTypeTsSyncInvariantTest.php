<?php

declare(strict_types=1);

use App\Enums\Notification\NotificationType;
use Tests\Support\TsUnionValues;

/*
 * NotificationType (PHP enum) ⇔ resources/js/types/notification.ts (TS literal union) の
 * 値集合同期 invariant。フロントの type 駆動描画 (アイコン/文言分岐) が enum 追加で
 * silent に壊れるのを防ぐ (抽出は共有 helper TsUnionValues。抽出不能 = fail)。
 */

test('NotificationType の PHP enum ⇔ TS union 値集合が一致する', function (): void {
    expect(TsUnionValues::extract('resources/js/types/notification.ts', 'NotificationType'))
        ->toBe(TsUnionValues::enumStringValues(NotificationType::cases()));
});

test('notification.ts の抽出不能な union 名は fail する (degenerate PASS 防止の自己検証)', function (): void {
    expect(fn (): array => TsUnionValues::extract('resources/js/types/notification.ts', 'NoSuchUnionName'))
        ->toThrow(RuntimeException::class, 'degenerate PASS');
});
