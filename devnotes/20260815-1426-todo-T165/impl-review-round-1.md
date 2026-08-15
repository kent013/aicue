.github/workflows/ci.yml

[Warning] `actions/cache@v6` / `actions/upload-artifact@v7` は、ローカル gate が版を無視するため実 CI まで検出されません。設計上「実在確認済み」とありますが、差分内には機械的な担保がありません。ここが誤っていると `browser-tests` job が setup 段階で即失敗します。少なくともレビュー対象としては、CI 実行結果でこの 2 action の解決成功を確認する必要があります。

scripts/setup-browser-testing.sh

[Suggestion] 設計どおりで、`install-deps --dry-run` の終了コードと marker を両方見る fail-closed、`sudo -n` 限定、権限観測を missing 時だけに絞る点は妥当です。`flock` の保証範囲も誇張していません。

scripts/run-browser-test.sh

[Suggestion] 事前確認をグローバルテストロック前に置き、証跡初期化をロック後・レーン前に置く設計と一致しています。`collect_lane_artifacts` が `mkdir` / `cp` 失敗を warning にして終了コードを保持する点も正しいです。

scripts/run-browser-test.contract.test.ts

[Warning] C14b の `cp` スタブは PATH 先頭に置かれるため、退避処理以外で `cp` を使う将来変更にも反応します。現状は問題ありませんが、「退避の複製だけ非ゼロ」という設計記述よりは広いスタブです。

scripts/setup-browser-testing.contract.test.ts

[Suggestion] self-test のケース数下限、sandbox 実走、実 Playwright smoke が揃っており、負のコントロールも十分です。`status !== 0` なら missing marker を要求する実 CLI smoke は fail-closed 方針と整合しています。

tests/Architecture/BrowserProvisioningEntrypointTest.php

[Warning] `browserProvisioningJsonScriptCommands()` は `json_decode($contents, true)` の戻りを `is_array` でしか narrow しておらず、設計書の「JSON は `Assert::isArray()` → 要素ごとに `Assert::string()`」とは厳密には一致していません。PHPStan は通っているとのことなので実害は薄いですが、設計適合性の観点ではずれています。

tests/js/architecture/ci-workflow-inventory.test.ts

[Warning] W18/W19 の「負のコントロール」は fixture を既存検査関数に通して違反を確認しておらず、単に `expect(step.with?.["restore-keys"]).toBeDefined()` などを見ています。検出器が空振りしていないことの証明としては弱いです。W20 は実際に検出パターンを通しており良いです。

tests/js/support/shell-contract.ts / shell-contract.test.ts

[Suggestion] 既存 helper の移設として妥当です。`lineIndexOf` の追加も順序契約に必要な範囲です。

docs/testing-browser.md / scripts/README.md / .gitignore

[Suggestion] 記述は実装と整合しています。保証しない範囲も過剰に広げていません。

CHANGES_REQUESTED