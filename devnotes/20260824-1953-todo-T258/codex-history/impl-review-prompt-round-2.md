## Round 1 の指摘への対応

3 件すべて対応しました。対応マトリクスの全文は
`devnotes/20260824-1953-todo-T258/codex-history/impl-review-decisions-round-1.md` にあります。

### 1. [Warning] D18 の「`grep` / `printf` などの外部コマンド」— `printf` は bash の組み込み

**対応する**。事実誤認は保証範囲の誇張になるため `printf` を落としました。

```diff
-| **検問 (bug-hunt ガード) の判定** | `grep` / `printf` などの外部コマンドに依存する | bash の組み込みだけで完結し、外部コマンドを 1 つも起こさない |
+| **検問 (bug-hunt ガード) の判定** | `grep` などの外部コマンドに依存する | bash の組み込みだけで完結し、外部コマンドを 1 つも起こさない |
```

D18 の結論 (外部コマンド依存であること) は `grep` があるので変わりません。

### 2. [Suggestion] AGENTS.md の「3 値をスクリプト本文と設定の両方から取り出す」が実装より強い

**対応する**。取得元を分けて書き直しました。

```diff
-  台帳テストは 3 値(標準入力待ち / 更新本体の上限 / KILL までの猶予)を**スクリプト本文と
-  設定の両方から数値で取り出して比較**する(文字列一致では数値の関係が崩れたことを検出できない)。
+  台帳テストは 3 値(標準入力待ち / 更新本体の上限 / KILL までの猶予)を**スクリプト本文から**、
+  **配線の時間切れを設定から**数値で取り出して比較する
+  (文字列一致では数値の関係が崩れたことを検出できない)。
```

検査側の docblock (`claudeHooksInnerLimits()` / S13) は元から取得元を分けて書いてあるので、
これで 2 か所の言い方が揃います。

### 3. [Warning] `composer test` が 1 件失敗していて「全 green でコミット」を満たしていない

**対応する (再実行で green を確認)**。

- `BughuntSelfTestExecutionTest` を単独で 2 回再実行 → **2 回とも passed**。
- その後の**全数実行で green**: `composer test` = **7715 tests / 7713 passed / 0 failed / 2 skipped
  / 35635 assertions**。
- 落ちた回のメッセージは「合成した pid が実行ホストに live で存在するため所有確認に落ちる」
  という内容で、**本変更を 1 行も当てていない main のチェックアウトでも同じ内容で再現**しました
  (このホストでは複数の実装エージェントが並走しており live pid が多い)。
- 途中の 1 回だけ `EmailPromotionTest` が Livewire の styles/scripts 注入差で落ちましたが、
  同ファイル単独実行は 43 件 passed で、本変更は `app/` / `resources/` を 1 行も触っていないため
  因果がありません。
- いずれも本 TODO と独立した実行環境由来の flake と判断し、最終の全数実行の結果を採用しています。

## 現在の検証結果 (すべて green)

- `composer test`: 7715 tests / **7713 passed / 0 failed** / 2 skipped / 35635 assertions
- `composer phpstan` (level 10): No errors
- `vendor/bin/pint --test`: passed
- `pnpm lint` / `pnpm typecheck` / `pnpm test` (179 files / 2398 tests) / `pnpm build` /
  `pnpm typecheck:packages` / `pnpm build:packages` / `pnpm test:packages` (10 files / 106 tests): 全 green

(Round 1 以降のコード変更は上記 2 つの**文書の文言だけ**で、PHP / shell / JSON は 1 行も変えていません。)

再レビューをお願いします。全体判定を **APPROVED** または **CHANGES_REQUESTED** で明記してください。
