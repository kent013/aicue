<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | 外部サービス fake 化の capability flag
    |--------------------------------------------------------------------------
    |
    | true のとき FakeExternalsServiceProvider が外部サービス (Stripe) の
    | gateway を fake 実装に bind する (bughunt / local 検証用)。
    | 有効化は allowlist 環境 (local / testing / bughunt.local) に限定され、
    | production では ProductionEnvGuard が true を deploy 時 fail-fast で拒否する。
    | 既定 false = 本 flag 未設定の環境では完全 no-op。
    |
    */

    'fake_externals' => (bool) env('TESTING_FAKE_EXTERNALS', false),

];
