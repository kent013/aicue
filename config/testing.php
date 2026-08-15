<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| 偽の外部サービスの capability flag
|--------------------------------------------------------------------------
|
| **何をどの偽物へ差し替えるか、どの環境で許すかの正本は
| App\Support\ExternalFakes\ExternalFakeDeclaration である**。
| 本ファイルが持つのは capability ごとの真偽値 3 本だけで、対象の列挙は持たない
| (列挙をここへ写すと必ず宣言とずれる)。
|
| 3 本とも既定 false = 未設定の環境では完全 no-op。production では
| ProductionEnvGuard が true を起動時 fail-fast で拒否する (設定値とプロセスの
| 実環境変数の両方を見る)。
|
*/

return [

    /*
    | fake_externals: 決済 gateway / 人間性確認 / 外部ログインの解決点を偽物へ差し替える。
    | 許可環境は ExternalFakeDeclaration::EXTERNAL_ENVIRONMENTS。
    | **外部ログインだけ許可環境が狭い** (SSO_ENVIRONMENTS。local を除く) —
    | 未認証 GET 2 本で canned アカウントに入れる = 認証バイパスであり、かつ local は
    | 実 IdP 連携を確かめる唯一の環境であるため。
    */

    'fake_externals' => (bool) env('TESTING_FAKE_EXTERNALS', false),

    /*
    | fake_llm: LLM (Prism) の応答を偽物へ差し替える。
    | 許可環境は ExternalFakeDeclaration::LLM_ENVIRONMENTS (bughunt.local のみ) —
    | Prompt::$fake はプロセス大域の static のため testing / local を外す。
    | bug-hunt の既定は実 LLM で、--fake-llm 指定時のみ true が注入される。
    */

    'fake_llm' => (bool) env('TESTING_FAKE_LLM', false),

    /*
    | fake_storage: S3 の保存先を偽物へ差し替える。
    | 許可環境は ExternalFakeDeclaration::STORAGE_ENVIRONMENTS。
    | testing は自動テスト実行中に限る (追加条件は FakeStorageGate が持つ)。
    | bug-hunt の既定は偽物で、--real-storage 指定時のみ false が注入される。
    */

    'fake_storage' => (bool) env('TESTING_FAKE_STORAGE', false),

];
