<?php

declare(strict_types=1);

use App\Enums\Auth\AuthMethodChangeEvent;

test('全 case が空文字列でない headline() を返す', function (): void {
    foreach (AuthMethodChangeEvent::cases() as $case) {
        expect($case->headline())->not->toBe('');
    }
});
