# 対応マトリクス: impl-review Round 1

## [Warning] ScenarioReportPanel.svelte: `{#each verdict.works as work (work)}` の key が一意でない

- 判断: **対応する**
- 根拠: 指摘のとおり。`SopValidationData` は `works` の重複を禁止していない (禁止する根拠が無い —
  LLM が同名の作業を 2 件返すことは「所見」としてはあり得る)。DTO 側で重複を弾く方向は
  「表示のために保存値の受理条件を狭める」ことになり、所見を表示専用に留める設計と噛み合わない。
  したがって表示側を直す。
- 対応内容: keyed each をやめて unkeyed each にし、「works は LLM 由来で重複を禁止していないため
  一意 key にできない」理由をコメントで残した。

## [Warning] M9 (ドキュメント更新) が diff に無い

- 判断: **反論する (実装済み。diff の切り出し範囲の問題)**
- 根拠: M9 は実装済みである。Round 1 の diff を
  `git diff HEAD -- app/ resources/ tests/ routes/ database/` で作ったため、`docs/` と `doc/` が
  範囲外になっていた (SKILL.md の差分取得例をそのまま使った副作用)。
- 対応内容: Round 2 のプロンプトに `docs/architecture.md` と `doc/03_AI解析とシナリオ生成.md` の
  diff を添付して確認してもらう。コードは変更しない。

## [Suggestion] 3 verdict の tone も固定した方がよい

- 判断: **対応する**
- 根拠: 設計のテスト計画は「3 verdict のラベル/tone」であり、tone の固定が抜けていた。
  Badge atom は `TONE_CLASSES` を class に出すため DOM から観測できる (実装詳細への依存は
  atom の公開仕様の範囲内)。
- 対応内容: `ScenarioReportPanel.test.ts` の it.each に期待 tone class
  (`text-success` / `text-warning` / `text-danger`) を足し、`toHaveClass` で固定した。

## [Suggestion] AnalysisPipelineTest のテスト名と fixture の不一致 (「欠落」と言いつつ値が不正)

- 判断: **対応する**
- 根拠: 指摘のとおり読み違いを招く。加えて「キーの欠落」は旧プロンプト時代の応答形が
  返ってきた場合に実際に起こりうる経路であり、固定する価値がある。
- 対応内容: 既存テストを「validation 不正は…」に改名し、**キー欠落そのもの**を踏む
  テストを 1 件追加した (`failure_path` が `validation` ちょうどになることを固定)。
