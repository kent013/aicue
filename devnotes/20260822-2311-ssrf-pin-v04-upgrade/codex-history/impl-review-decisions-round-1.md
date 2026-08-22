# 対応マトリクス: impl-review Round 1

## [Warning] 完了条件で必須とされた検証結果が不足している (`pnpm test` / `pnpm test:packages` / `composer validate` / `composer install`)

- 判断: **対応する**
- 根拠: 詳細設計の受け入れ基準 6 が「AGENTS.md の VERIFICATION_COMMANDS 節の 10 コマンドを
  frontend 無改修でも省略しない」と定めており、Codex の指摘はそのとおりである。
  Round 1 の時点では 4 レーン (`pnpm test` / `pnpm test:packages`) が
  **ホスト全体のグローバルテストロック待ち**でまだ結果が出ていなかったため、
  プロンプトに実測値を載せられなかった。指摘は「コード修正要求ではなく証跡の不足」である。
- 対応内容: ロック待ちの 2 レーンの完走を待ち、`composer validate` / `composer install`
  と併せて実測値を取得した。**コードの変更は 1 行も行っていない** (差分は Round 1 と同一)。

  | コマンド | 実測結果 |
  |---|---|
  | `composer test` | 6445 tests / 6443 passed / 2 skipped / 5 risky / exit 0 |
  | `composer phpstan` (level 10) | No errors |
  | `vendor/bin/pint --test` | passed |
  | `pnpm lint` | clean (eslint resources/js) |
  | `pnpm typecheck` | clean (tsc --noEmit) |
  | `pnpm test` | **Test Files 173 passed (173) / Tests 2366 passed (2366) / rc=0** |
  | `pnpm build` | ✓ built in 6.37s |
  | `pnpm typecheck:packages` | clean |
  | `pnpm build:packages` | clean |
  | `pnpm test:packages` | **Test Files 10 passed (10) / Tests 106 passed (106) / rc=0** |
  | `composer validate --no-check-publish` | `./composer.json is valid` |
  | `composer install --dry-run` | `Nothing to install, update or remove` (lock と json が整合) |

## 補足: 差分は Round 1 から不変である

Round 1 で「提示差分について追加のコード修正要求はありません」と明言されているため、
本ラウンドは**証跡の追加のみ**で、実装差分・テスト・ドキュメントいずれも変更していない。
