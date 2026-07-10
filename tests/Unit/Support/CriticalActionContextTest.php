<?php

declare(strict_types=1);

use App\Support\CriticalActionContext;

test('初期状態は inactive で全フィールド null', function (): void {
    $ctx = new CriticalActionContext;

    expect($ctx->isActive())->toBeFalse();
    expect($ctx->actionName())->toBeNull();
    expect($ctx->reason())->toBeNull();
    expect($ctx->ticketId())->toBeNull();
    expect($ctx->adminActionId())->toBeNull();
});

test('activate で全フィールドがセットされ isActive が true になる', function (): void {
    $ctx = new CriticalActionContext;

    $ctx->activate(
        actionName: 'foo.bar',
        reason: 'because',
        adminActionId: 'uuid-1',
        ticketId: 'TICKET-1',
    );

    expect($ctx->isActive())->toBeTrue();
    expect($ctx->actionName())->toBe('foo.bar');
    expect($ctx->reason())->toBe('because');
    expect($ctx->adminActionId())->toBe('uuid-1');
    expect($ctx->ticketId())->toBe('TICKET-1');
});

test('deactivate で全フィールドがクリアされる', function (): void {
    $ctx = new CriticalActionContext;
    $ctx->activate('a', 'b', 'c', 'd');

    $ctx->deactivate();

    expect($ctx->isActive())->toBeFalse();
    expect($ctx->actionName())->toBeNull();
    expect($ctx->reason())->toBeNull();
    expect($ctx->ticketId())->toBeNull();
    expect($ctx->adminActionId())->toBeNull();
});

test('ネスト activate は LogicException で fail-fast する (単一スロット型)', function (): void {
    $ctx = new CriticalActionContext;
    $ctx->activate('first', 'r1', 'id1', 't1');

    expect(fn () => $ctx->activate('second', 'r2'))
        ->toThrow(LogicException::class, 'CriticalActionContext::activate() called while already active');
});

test('deactivate 後は再度 activate できる', function (): void {
    $ctx = new CriticalActionContext;
    $ctx->activate('first', 'r1');
    $ctx->deactivate();

    $ctx->activate('second', 'r2');

    expect($ctx->actionName())->toBe('second');
    expect($ctx->reason())->toBe('r2');
});

test('run() scope API は callback の前後で activate / deactivate をペアリングする', function (): void {
    $ctx = new CriticalActionContext;
    $captured = null;

    $result = $ctx->run(
        actionName: 'scope.test',
        reason: 'r',
        callback: function () use ($ctx, &$captured): string {
            $captured = [$ctx->isActive(), $ctx->actionName(), $ctx->reason()];

            return 'returned-value';
        },
    );

    expect($result)->toBe('returned-value');
    expect($captured)->toBe([true, 'scope.test', 'r']);
    // callback 終了後に deactivate される
    expect($ctx->isActive())->toBeFalse();
});

test('run() は callback が throw しても deactivate する', function (): void {
    $ctx = new CriticalActionContext;

    expect(fn () => $ctx->run(
        actionName: 'fail.test',
        reason: 'r',
        callback: function (): never {
            throw new RuntimeException('boom');
        },
    ))->toThrow(RuntimeException::class, 'boom');

    // callback 例外でも deactivate 必達
    expect($ctx->isActive())->toBeFalse();
});

test('run() のネスト呼び出し: 内側の LogicException でも外側 context は維持される', function (): void {
    $ctx = new CriticalActionContext;
    $outerStateAfterInnerFailure = null;

    $ctx->run(
        actionName: 'outer.action',
        reason: 'outer reason',
        callback: function () use ($ctx, &$outerStateAfterInnerFailure): void {
            // ネスト activate 試行 → LogicException
            try {
                $ctx->run(
                    actionName: 'inner.action',
                    reason: 'inner reason',
                    callback: fn () => null,
                );
            } catch (LogicException) {
                // 外側 context が無事に維持されていることを confirm
                $outerStateAfterInnerFailure = [
                    'isActive' => $ctx->isActive(),
                    'actionName' => $ctx->actionName(),
                    'reason' => $ctx->reason(),
                ];
            }
        },
    );

    expect($outerStateAfterInnerFailure)->toBe([
        'isActive' => true,
        'actionName' => 'outer.action',
        'reason' => 'outer reason',
    ]);
    // outer 終了で deactivate
    expect($ctx->isActive())->toBeFalse();
});

test('省略可能な adminActionId / ticketId は未指定なら null のまま', function (): void {
    $ctx = new CriticalActionContext;
    $ctx->activate('foo', 'reason');

    expect($ctx->isActive())->toBeTrue();
    expect($ctx->adminActionId())->toBeNull();
    expect($ctx->ticketId())->toBeNull();
});

test('container からは request scope の同一インスタンスが解決される', function (): void {
    $a = app(CriticalActionContext::class);
    $b = app(CriticalActionContext::class);

    expect($a)->toBe($b);
});
