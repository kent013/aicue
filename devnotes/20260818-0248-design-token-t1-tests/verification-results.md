# 検証コマンドの結果 (T221)

worktree `.claude/worktrees/tasks/T221` (ブランチ `todo/T221`) で、
Codex 実装レビュー Round 1〜3 の修正をすべて入れた**最終コード**に対して実行した結果。

| コマンド | 結果 |
|---|---|
| `composer test` | passed / tests 5770 (passed 5768 / skipped 2 / risky 5) / assertions 25293 |
| `composer phpstan` | No errors |
| `vendor/bin/pint --test` | passed |
| `pnpm lint` | passed (出力なし) |
| `pnpm typecheck` | passed (出力なし) |
| `pnpm test` | Test Files 162 passed / Tests 2102 passed |
| `pnpm build` | built in 9.64s |
| `pnpm typecheck:packages` | passed |
| `pnpm build:packages` | passed |
| `pnpm test:packages` | Test Files 10 passed / Tests 106 passed |
| `pnpm exec eslint tests/js/styles/*.ts` (新設・改修した検査 5 ファイル) | passed |

- `pnpm test` の件数は本バッチ前から **+130 件** (新設 2 本 + 既存 1 本への追記)。
  上表の実行後に Round 4 の表現修正 (コードの挙動は不変) と負の fixture 1 件を反映したため、
  対象 4 ファイル (`tests/js/styles/` の 3 本 + `contrast-invariant.test.ts`) を
  最終コードで流し直して **131 件すべて緑**であることを確認した (`final-check`)。
- 感度確認 (故障注入 R1〜R6) の結果は `red-verification.md` / `red-verification-raw.txt`。

## 先行して確認したもの

`docs/template-divergence.md` の D27 追記が登録簿の形式規則を満たすことは、本番と同じ純関数
(`DivergenceLedgerRules::violations()`) を直接呼ぶ一時スクリプト
(`devnotes/20260818-0248-design-token-t1-tests/check-ledger.php`) で先行確認した (`ledger OK`)。
件数の pin は `docs/template-divergence.md` の見出し行と
`tests/Architecture/TemplateDivergenceLedgerFormatTest.php` の
`TEMPLATE_DIVERGENCE_ENTRY_COUNT` の **2 か所**にあり、両方を 26 へ直してある
(詳細設計は前者しか挙げていなかった)。
