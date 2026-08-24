# 赤→緑の実測記録 (実装フェーズ T255)

AGENTS.md「走査器・gate を新設・変更するときに同じ PR で揃える 4 点」の (1) は
「テストファーストで先に赤くしてから本体を書く」であり、
**赤を見たことの実測を残すまでが完了条件**である。

記録は詳細設計 §テストファースト手順 の段 1 / 3 / 4 / 6 に対応する。
実行環境: worktree `.claude/worktrees/tasks/T255` (branch `todo/T255`)、2026-08-24。
すべて `composer test -- <path>` (pgsql・グローバルテストロック配下) で実行した。

## 段 1: 自己テストが先に赤い (読み取り器が無い状態)

- コマンド: `composer test -- tests/Unit/Architecture/PromptWaitBudgetTest.php`
  (`tests/Support/PromptWaitBudget.php` を一時退避した状態)
- 結果: **赤** — 5 tests / 0 passed / errors 4 + failed 1。
  4 本は `Class "Tests\Support\PromptWaitBudget" not found`、
  1 本は `Failed asserting that an instance of class Error is an instance of class RuntimeException`
  (`toThrow(RuntimeException::class)` が Error を受けた)。

## 段 2: 読み取り器の実装後に自己テストが緑

- コマンド: `composer test -- tests/Unit/Architecture/PromptWaitBudgetTest.php`
- 結果: **緑** — 5 tests / 5 passed。

## 段 3-a: `<= 0` 分岐を一時削除 → 赤

- 期待: `zero.yaml` / `negative.yaml` のラベルが集合から落ちて 1 本目の test が赤
- コマンド: `composer test -- tests/Unit/Architecture/PromptWaitBudgetTest.php`
  (`PromptWaitBudget::evaluate()` の `if ($timeout <= 0) { … }` を一時削除)
- 結果: **赤** — 5 tests / 3 passed / 2 failed。
  1 本目 (ラベル集合) の差分は `negative.yaml` と `zero.yaml` の 2 件が欠落。
  併せて `requirePositive は違反があれば例外にする` も
  `Exception "RuntimeException" not thrown.` で赤くなり、
  **公開 2 口が同じ private 判定を通っている**ことが実測で裏取りされた。
- 確認後に元へ戻した (バックアップとの `diff` が空であることを確認済み)。

## 段 3-b: `is_int()` → `is_numeric()` へ緩める → 赤

- 期待: **`numeric-string` と `float` の 2 本**が集合から落ちる
  (`is_numeric(true)` / `is_numeric(null)` はどちらも false なので bool / null は違反のまま)
- コマンド: `composer test -- tests/Unit/Architecture/PromptWaitBudgetTest.php`
- 結果: **赤** — 5 tests / 4 passed / 1 failed。欠落したのは
  `float.yaml` と `numeric-string.yaml` の**ちょうど 2 件**で、
  `bool.yaml` / `null.yaml` は違反として上がったまま (設計の予測と一致)。
- 確認後に元へ戻した。

## 段 4: 到達証明に架空の名前を足す → 赤

- 期待: `PROMPT_WAIT_BUDGET_REQUIRED_LABELS` へ `sop-extract-v9.yaml` を足すと
  「走査の列挙結果に既知の prompt YAML が含まれていません」で赤
- コマンド: `composer test -- tests/Architecture/PromptClientTimeoutInvariantTest.php`
- 結果: **赤** — 2 tests / 1 passed / 1 failed。
  メッセージは `走査の列挙結果に既知の prompt YAML が含まれていません … 不足: sop-extract-v9.yaml`。
- 戻した後の再実行: **緑** — 2 tests / 2 passed。

## 段 6: 乖離登録の前に突合 gate が赤い

- 期待: `TemplateDivergenceFingerprintTest` が
  「一致していた状態から新たに不一致になった、未登録かつ非債務のパス」で赤
- コマンド: `composer test -- tests/Architecture/TemplateDivergenceFingerprintTest.php`
  (D50 登録 + 件数 pin を当てる**前**)
- 結果: **赤** — 15 tests / 14 passed / 1 failed。
  `テンプレートと共有するファイルを変えたのに登録が無いパスがあります (1 件):
   - tests/Architecture/PromptClientTimeoutInvariantTest.php`
  → これが施策 6 (D50 登録 + `LedgerPins::DIVERGENCE_ENTRY_COUNT` 46 → 47) の必要性の実測である。

## 設計からの逸脱 (実装時に判明した 1 点)

詳細設計 §施策 1 のコード片は解決不能形の分類 pin を
`expect($joined)->toContain($expectedFragment, "{$kind} の分類が変わっています");` と書いていたが、
**Pest の `toContain()` は needle の可変長引数**であり第 2 引数はメッセージではなく
**別の needle として照合される**。そのため段 2 の初回実行が
「`ファイル不在 の分類が変わっています` を含まない」で赤くなった (実測)。
分類の pin は落とさずメッセージだけを残す形へ直した:

```php
expect(str_contains($joined, $expectedFragment))
    ->toBeTrue("{$kind} の分類が変わっています ({$expectedFragment} を含まない): {$joined}");
```

不変条件 (3 種の解決不能形をそれぞれ**分類まで**固定する) は変えていない。

## 最終の全数 (worktree `todo/T255` / 2026-08-24。Codex 実装レビュー反映後)

- `composer phpstan`: level 10 / 1114 files / **No errors**
- `vendor/bin/pint --test`: **passed**
- `composer test`: **7377 tests / 7375 passed / 0 failed / 2 skipped (5 risky) / 34675 assertions**
  (risky 5 件は main の直前クローズ T254 の記録と同数 = 本 PR 由来ではない)
- 自己テスト単独 (`tests/Unit/Architecture/PromptWaitBudgetTest.php`): **6 tests / 6 passed / 26 assertions**
- `pnpm lint` / `pnpm typecheck` / `pnpm build` / `pnpm typecheck:packages` /
  `pnpm build:packages`: いずれも **exit 0**
- `pnpm test`: **179 files / 2398 tests passed**
- `pnpm test:packages`: **10 files / 106 tests passed**
  (JS レーンは Codex レビュー反映前の実測。以後の修正は `tests/**.php` と `docs/*.md` のみで
   JS の入力に触れていない)

## 待ち予算を読む実装が 1 本になったことの確認 (詳細設計 段 11)

- `grep -rn "client_options" tests/ --include=*.php | grep -v PromptWaitBudget.php` の結果は
  **メッセージ文字列・コメント・test 名だけ** (読み取り実装は 0 件)。

## Codex 実装レビュー (gpt-5.6-sol / high)

- Round 1: **CHANGES_REQUESTED** — Warning 2 件 (D50 と gate 冒頭コメントの「実効値 30 秒」断定が
  条件付き運用契約と矛盾 / ワーカー閉塞の未裏取り主張) + Suggestion 1 件 (公開 2 口の非対称)。
- Round 2: **CHANGES_REQUESTED** — Round 1 の Warning 2 件は解消。ただし Suggestion 対応で足した
  `$parsed === null && $parseErrors === []` の guard が**発火しない防御コード**であり
  負例で裏取りできないという指摘。
- Round 3: **APPROVED** — 到達不能 guard を撤去し、段 2 が依存する共有ヘルパの契約
  (「null を返すときは必ず理由を積む」) を実行可能な検査として固定する形へ差し替えた。
  自己テストは 5 本 → 6 本。
- 各ラウンドのプロンプト・対応マトリクスは `codex-history/` に、返答は同ディレクトリ直下の
  `impl-review-round-{1,2,3}.md` に保存済み。
