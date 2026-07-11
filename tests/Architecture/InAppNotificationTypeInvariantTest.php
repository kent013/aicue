<?php

declare(strict_types=1);

use App\Enums\Notification\NotificationType;
use App\Notifications\InApp\AppNotification;

/*
 * 「このアプリの database 通知は type = NotificationType enum 値」規約の
 * deny-by-default 固定 (詳細設計 施策8)。
 *
 * app/Notifications/InApp 配下の全 Notification クラス (基底 AppNotification を除く) を
 * ファイル走査で列挙し、以下を強制する:
 * 1. AppNotification 派生であること (via = database のみ / organizationId 契約を継承)
 * 2. type() が NotificationType を返し、databaseType() = type()->value であること
 *    (クラス名を DB に置かない。クラス名前提の読取ロジックをアプリ内に作らせない)
 * 3. type() 値がクラス間で重複しないこと (読み出し側の payload 復元分岐が一意)
 *
 * DB 実発火の round-trip (organization_id 列・type 列) は Feature の
 * NotificationSchemaTest が担保する (Architecture lane は DB を使わない)。
 */

/**
 * app/Notifications/InApp 配下の具象 Notification クラスを列挙する (deny-by-default 走査)。
 *
 * @return list<class-string>
 */
function inAppNotificationConcreteClasses(): array
{
    $files = glob(app_path('Notifications/InApp/*.php'));
    expect($files)->not->toBeFalse();
    assert(is_array($files));

    $classes = [];
    foreach ($files as $file) {
        $class = 'App\\Notifications\\InApp\\'.basename($file, '.php');
        expect(class_exists($class))->toBeTrue("クラスが存在しません: {$class}");
        $reflection = new ReflectionClass($class);
        if ($reflection->isAbstract()) {
            continue; // 基底 (AppNotification)
        }
        $classes[] = $class;
    }

    return $classes;
}

test('InApp 配下の全具象クラスは AppNotification 派生である (deny-by-default)', function (): void {
    $classes = inAppNotificationConcreteClasses();
    expect($classes)->not->toBeEmpty();

    foreach ($classes as $class) {
        expect(is_subclass_of($class, AppNotification::class))
            ->toBeTrue("{$class} は AppNotification を継承していません");
    }
});

test('全具象クラスの type() は NotificationType を返し databaseType() = enum 値である', function (): void {
    foreach (inAppNotificationConcreteClasses() as $class) {
        $reflection = new ReflectionClass($class);
        $instance = $reflection->newInstanceWithoutConstructor();
        assert($instance instanceof AppNotification);

        $type = $instance->type();
        expect($type)->toBeInstanceOf(NotificationType::class);
        expect($instance->databaseType(new stdClass))->toBe($type->value);
        expect($instance->via(new stdClass))->toBe(['database']);
    }
});

test('type() 値はクラス間で重複しない (payload 復元分岐の一意性)', function (): void {
    $values = [];
    foreach (inAppNotificationConcreteClasses() as $class) {
        $reflection = new ReflectionClass($class);
        $instance = $reflection->newInstanceWithoutConstructor();
        assert($instance instanceof AppNotification);
        $values[] = $instance->type()->value;
    }

    expect($values)->toBe(array_values(array_unique($values)));
});
