# 対応マトリクス: impl-review (harness) Round 2

Warning 1 件（`SetupWorktreeRuntimeFilesContractTest.php`）。**対応した**（反論なし）。

## [Warning] コピー失敗を成功扱いしない契約の回帰テストが無い
- 判断: **対応する**
- 根拠: 指摘のとおり。実装は Round 1 で直したが、**将来また `if` 条件へ戻されても既存 5 件は全部通る**。
  「直したがテストで固定していない」状態は、次の人に静かに戻される。
- 対応内容: テストを 2 本追加した（計 7 本）。
  1. **「コピーに失敗したら非ゼロで落ちる」**（実挙動）:
     コピー元を用意したうえで**コピー先ディレクトリを `0500`（書き込み不可）**にして
     `install` を失敗させ、終了コードが非ゼロになることと、コピー先にファイルが残らないことを見る。
     `root` 実行時は書き込み不可でも `install` が成功してしまうため `posix_geteuid() === 0` で skip する
     （理由付き skip。無言では飛ばさない）。
  2. **「本体の呼び出しが `if` の条件式に置かれていないこと」**（静的）:
     関数単体テストでは **top-level の `set -e` 配線までは固定できない**ため、
     `\b(if|while|until|&&|\|\|)\s+provision_bughunt_env_file` に一致しないことと、
     本体から素の文として呼ばれていることを検査する。

### mutation による実効性の確認
本体の呼び出しを Round 1 以前の `if provision_bughunt_env_file ... && [[ -f ... ]]` へ戻して実行:
- **7 件中 1 件が赤**になる（静的検査が回帰を検出）。戻した状態を素通りさせない。

## 検証（対応後）
- `scripts/bug-hunt-shard.sh self-test`: all passed
- `composer test`: 4116 tests / 4114 passed / 2 skipped / 17713 assertions
- `composer test:browser` chromium / webkit: 各 22 tests / 19 passed / 3 skipped / 149 assertions
- `composer phpstan`: No errors / `vendor/bin/pint --test`: passed
- `pnpm lint` / `typecheck` / `test` (1292) / `build`: passed
- `pnpm typecheck:packages` / `build:packages` / `test:packages` (106): passed
