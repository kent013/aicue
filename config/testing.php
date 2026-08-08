<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | 外部サービス fake 化の capability flag
    |--------------------------------------------------------------------------
    |
    | fake_externals: **外部サービス fake の capability flag** (既定 false = no-op)。
    | true のとき FakeExternalsServiceProvider::register() が以下を fake 実装へ bind する:
    |   - Stripe 課金 gateway (checkout / portal / auto-recharge)
    |   - captcha 検証器 (RecaptchaVerifier → RecaptchaVerifierTestFake)
    | **SSO (Socialite) は fake しない** (差し替え先を作っていない。
    |  bug-hunt のブラウザは SSO ボタンから実 IdP へ遷移する。
    |  docs/architecture.md §外部到達点の目録 (標準形 v1) を参照)。
    | 有効化は allowlist 環境 (local / testing / bughunt.local) に限定され、
    | production では ProductionEnvGuard が true を deploy 時 fail-fast で拒否する。
    | 既定 false = 本 flag 未設定の環境では完全 no-op。
    |
    | ※ LLM (Prism) fake はこの flag から分離され fake_llm が capability flag。
    |
    */

    'fake_externals' => (bool) env('TESTING_FAKE_EXTERNALS', false),

    /*
    |--------------------------------------------------------------------------
    | LLM (Prism) fake 化の capability flag
    |--------------------------------------------------------------------------
    |
    | fake_llm: LLM (Prism) fake を install するか。config 既定 false = real LLM。
    | bughunt は既定 real-llm (scripts/bug-hunt-shard.sh が TESTING_FAKE_LLM=false を明示注入)。
    | --fake-llm 指定時のみ true 注入 → FakeExternalsServiceProvider::boot が
    | CannedPromptFakeRegistrar を install (env allowlist bughunt.local のみ)。
    | production では ProductionEnvGuard が true を fail-fast で拒否する。
    |
    */

    'fake_llm' => (bool) env('TESTING_FAKE_LLM', false),

    /*
    |--------------------------------------------------------------------------
    | S3 ストレージ fake 化のトグル (骨子)
    |--------------------------------------------------------------------------
    |
    | fake_storage: S3 ストレージ fake トグル (骨子)。config 既定 false = 本番安全側。
    | bughunt は既定 fake (scripts/bug-hunt-shard.sh が TESTING_FAKE_STORAGE=true を明示注入)。
    | --real-storage 指定時のみ false 注入。
    | ※ 実 S3 接続の実配線は本 item スコープ外 (consumer 未実装 = inert)。
    | production では ProductionEnvGuard が true を fail-fast で拒否する。
    |
    */

    'fake_storage' => (bool) env('TESTING_FAKE_STORAGE', false),

];
