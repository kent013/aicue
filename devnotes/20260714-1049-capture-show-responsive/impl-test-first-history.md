# T037 テストファースト履歴 (red → green)

撮影画面 `capture.manuals.show` の横 overflow 修正（施策1〜4）。
jsdom はレイアウト計算をしないため overflow 自体は vitest で証明できず、**構造回帰テスト**で
grid の単一列化・`min-w-0`・shooting_point の truncate 構造を固定する（詳細設計 受け入れ条件 5）。

## RED（施策1/2 の class 変更を stash して先に確認）

`pnpm exec vitest run CaptureShow CutNavigator`

- FAIL `tests/js/pages/CaptureShow.test.ts` … 「グリッドは mobile 単一列 (grid-cols-1)、左右 pane が min-w-0」
  - 理由: `capture-grid` testid が存在しない（施策1 未適用）。
- FAIL `tests/js/components/features/capture/CutNavigator.test.ts` … 「shooting_point 行は <p>min-w-0 + <span>truncate」
  - 理由: `expected 'P' to be 'SPAN'`（施策2 未適用で shooting_point がテキストノードのまま）。
- 集計: **2 failed | 6 passed (8)**

## GREEN（施策1〜4 適用後）

`pnpm exec vitest run CaptureShow CutNavigator`

- 集計: **2 files passed / 8 tests passed**

## フルスイート / 品質ゲート（worktree 内）

- `composer test` = pest **1716 passed / 2 skipped**（PHP 変更なし・回帰なし）
- `composer phpstan` = No errors（level 10）
- `vendor/bin/pint --test` = passed
- `pnpm lint` = pass / `pnpm typecheck` = pass / `pnpm build` = 成功
- `pnpm test`（全 vitest）= **72 files / 538 tests 全 pass**
  - 注: デフォルト `testTimeout=5000ms` ではマシン CPU 高負荷時に「Test timed out in 5000ms」の
    flake が数件出るが、失敗集合は run 毎に変わり（Welcome→AdminUsers/Contact/… と非決定的）、
    本変更ファイル（CaptureShow/CutNavigator）は一切含まれない。`--testTimeout=30000` で **538/538 green** を確定。
    変更したテストは毎回安定 pass。

## Codex 実装レビュー

- `gpt-5.3-codex` reasoning=high、Round 1 で **APPROVED**（Critical/Warning/Suggestion いずれも 0 件）。
- 履歴: `impl-review-round-1.md` / `codex-history/impl-review-prompt-round-1.md` / `codex-history/impl-review-decisions-round-1.md`

## 最終確認（受け入れ条件 5）の残タスク

vitest は構造回帰までしか証明しないため、375px / 768px の実 overflow 消失は bug-hunt / Playwright
実走で最終確認する（本実装 PR のスコープ外・運用フェーズで実施）。
