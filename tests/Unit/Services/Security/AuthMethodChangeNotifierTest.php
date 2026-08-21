<?php

declare(strict_types=1);

use App\Enums\Auth\AuthMethodChangeEvent;
use App\Models\User;
use App\Services\Security\AuthMethodChangeNotifier;
use Illuminate\Contracts\Notifications\Dispatcher;
use Illuminate\Support\Facades\Exceptions;

test('notify() は通知送信で例外が起きても吸収し呼び出し元へ伝播しない', function (): void {
    $dispatcher = Mockery::mock(Dispatcher::class);
    $dispatcher->shouldReceive('send')->once()->andThrow(new RuntimeException('boom'));
    app()->instance(Dispatcher::class, $dispatcher);

    $user = User::factory()->create();
    $notifier = new AuthMethodChangeNotifier;

    // 例外が呼び出し元へ伝播しないこと自体が主張 (伝播すればこのテストは fail する)
    $notifier->notify($user, AuthMethodChangeEvent::PasswordChanged);

    expect(true)->toBeTrue();
});

test('notify() は吸収した例外を report() する (Codex 実装レビュー Round 1 [Warning] への対応)', function (): void {
    Exceptions::fake();

    $dispatcher = Mockery::mock(Dispatcher::class);
    $dispatcher->shouldReceive('send')->once()->andThrow(new RuntimeException('boom'));
    app()->instance(Dispatcher::class, $dispatcher);

    $user = User::factory()->create();
    $notifier = new AuthMethodChangeNotifier;

    $notifier->notify($user, AuthMethodChangeEvent::PasswordChanged);

    Exceptions::assertReported(RuntimeException::class);
});
