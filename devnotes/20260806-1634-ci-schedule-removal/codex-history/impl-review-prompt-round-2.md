# impl-review Round 2

Round 1 の指摘 (Warning 2 / Suggestion 1 / N1〜N4 未報告) への対応と、実測結果を提示する。

## 対応マトリクス

### [Warning] ci.yml L6 のコメントに `nightly` が残っている → **対応済み**

設計は「#7 = nightly の 0 hit」を期待値としながら、施策 1 の変更後コード本文に `nightly` を
含めており設計自身が内部矛盾していた。機械的な期待値 (0 hit) 側に倒し、経緯の意味は
「定期実行」への言い換えで完全に保った。

```diff
-#    (技術的に妥当な nightly が一度入り、巻き戻された経緯がある)。
+#    (技術的に妥当な定期実行が一度入り、巻き戻された経緯がある)。
```

### [Warning] review-checklist.md L62 にも `nightly` が残っている → **対応済み**

```diff
-  定期実行する」技術的に妥当な nightly が一度入り、それでも巻き戻された経緯がある。
+  定期実行する」技術的に妥当な実装が一度入り、それでも巻き戻された経緯がある。
```

(直前に「定期実行する」があるため重複を避けて「実装」とした)

### [Suggestion] 「代替を作らない」というスコープ固定が落ちている → **対応済み (文言調整)**

設計原文の「**本タスクでは**代替を作らない」は恒久文書に載せると陳腐化する
(読んだ時点の「本タスク」が何か分からない) ため、主語をリポジトリに変えた。

```diff
-どういう形で用意するかはオーナーの裁量である。
+どういう形で用意するかはオーナーの裁量であり、**リポジトリ側で代替の定期実行を作らない**。
```

### [指摘] 実ファイル改変の負のコントロール N1〜N4 が未報告 → **実測して下記に報告**

詳細設計は「`git checkout --` が未コミットの実装ごと消すため、**本実装をコミットしてから**
実測する」と定めており、Round 1 の時点では実施できない段階だった。実装をコミット
(`da68634`) したうえで 1 改変ずつ実測した。

## 修正後の再検証

- 残置検査 #6 `rg -n '(^\s*schedule:|github\.event_name)' .github/workflows/ci.yml`: **0 hit**
- 残置検査 #7 `rg -n -i 'nightly' AGENTS.md docs/ tests/ scripts/ .github/ --glob '!TODO-closed.md'`: **0 hit**
- `pnpm exec vitest run tests/js/architecture/`: **19 files / 129 tests passed, 0 failed**
- `pnpm test` (全体): **124 files / 1224 tests passed, 0 failed**
- `pnpm typecheck` / `pnpm lint`: OK
- `composer phpstan`: No errors
- `pnpm run audit:gate`: Gate passed (Total advisories: 0)
- #8a (コミット内容の allowlist 検査): **0 hit** (5 ファイルのみ)。
  検出器自身の空振り確認として `composer.json` を混ぜた入力を与えると 1 hit することを確認済み
- #8b (作業ツリー diff + untracked): **0 hit** (`git status --porcelain` が空)

## 負のコントロールの実測結果 (実ファイルを 1 改変ずつ壊して gate が落ちることを確認)

いずれも実装コミット後の worktree で実施し、各改変のあと
tracked は `git checkout --`、untracked は `rm` で復元し、
`git status --porcelain` が空に戻ったことを毎回確認している。
gate 実行は `pnpm exec vitest run tests/js/architecture/ci-workflow-inventory.test.ts` (全 35 本)。

| # | 一時改変 | 実測結果 |
|---|---|---|
| N1 | `ci.yml` の `on:` に `schedule: - cron: "0 20 * * *"` を戻した | **2 failed | 33 passed**。落ちたのは **W12** (トリガー集合不一致: `["pull_request","push","schedule"]`) と **W17** (`["ci.yml"]` が schedule を持つ) の 2 本。あわせて #6 の rg が `19: schedule:` を 1 hit し、**検証コマンド自身が空振りしていない**ことも確認 |
| N2 | `php` job に `if: github.event_name != 'schedule'` を戻した | **1 failed | 34 passed**。落ちたのは **W15** のみ (`jobsWithCondition` が `["php"]` を返す)。W12 / W17 は ci.yml の `on:` を触っていないので緑のまま = 検査の分離が効いている |
| N3 | `php` job に `if: "!contains(github.event_name, 'schedule')"` (言い換え) を入れた | **1 failed | 34 passed**。落ちたのは **W15** のみ。値ではなく `if` の**有無**を見ているため、条件式を言い換えても逃げられないことを実測で確認 |
| N4 | `.github/workflows/zz-n4-temp.yml` に `schedule` だけを持つ workflow を 1 本新設 (untracked) | **1 failed | 34 passed**。落ちたのは **W17** のみ。**W12 は ci.yml しか見ないので緑のまま**、#6 の rg も ci.yml 無傷のため 0 hit = **W17 が無ければこの経路は完全に素通りしていた**ことの証明。後片付けは `rm` で明示削除し `git status --porcelain` が空に戻ることを確認 |

復元後の再実行は **35 passed** で、負のコントロールが後遺症を残していないことも確認済み。

## 質問

上記の対応と実測をもって、全体判定を再度示してほしい。
新たな [Critical] / [Warning] があれば指摘すること。無ければ **APPROVED** と明示してほしい。
