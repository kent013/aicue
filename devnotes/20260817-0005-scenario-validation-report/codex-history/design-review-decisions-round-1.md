# 対応マトリクス: design-review Round 1

Codex 判定: CHANGES_REQUESTED (M2 / M5 / M7 が REQUEST_CHANGES、他 6 施策は APPROVE)。
Critical 0 / Warning 6 / Suggestion 7。**全件を対応** (反論なし)。

## [Warning] M2: `fromStorage(?array $stored)` は保存値が scalar のとき TypeError で画面を落とす
- 判断: **対応する**
- 根拠: 指摘が正しい。JSON カラムの cast 結果が array である保証は無く、
  「壊れても詳細画面を落とさない」という本メソッドの目的と型宣言が矛盾していた。
- 対応内容: シグネチャを `fromStorage(mixed $stored, int $analysisJobId): ?self` に変更し、
  `null` は正常 (未生成)、**array 以外は復元失敗として `Log::warning` + null** に畳む。
  理由をコード注釈に書いた。テスト計画にも scalar 保存値のケースを含める。

## [Suggestion] M2: `tryFrom()` の結果を変数に保持する (`from()` で二度引かない)
- 判断: **対応する**
- 対応内容: `$verdict = is_string($rawVerdict) ? ScenarioVerdict::tryFrom($rawVerdict) : null;` とし、
  `null` 判定後はそのまま `new self($verdict, ...)` に渡す形へ書き換えた。

## [Suggestion] M3: migration の timestamp が未来日 / 既存順との整合
- 判断: **対応する**
- 根拠: 設計書に日付を焼き付けると実装日とずれる。
- 対応内容: ファイル名を `{実装日}_000100_add_validation_json_to_analysis_jobs_table.php` と表記し、
  「現在の最終 migration は `2026_08_16_220000_add_material_type_to_takes_table.php` なので、
  それより後で未来日にならない値を採番する」と明記した。

## [Suggestion] M4: `steps.*` 側の違反と識別できることもテストで固定する
- 判断: **対応する**
- 根拠: 観測条件 (validation 起因の再試行を数える) が実際に成立することの担保になる。
- 対応内容: テスト計画に「`steps.0.action` を壊した応答では `failure_path` が `steps.` で始まり、
  `validation` を壊した応答では `validation.` で始まる」を追加した。

## [Warning] M5: `rtrim($s, "。.!！")` はバイト単位で UTF-8 を壊す
- 判断: **対応する**
- 根拠: 指摘が正しい。`rtrim` の charlist はバイト集合として解釈されるため、
  マルチバイト文字を渡すと構成バイトが個別に剥がれて文字列を壊しうる。
- 対応内容: 定数を `TRAILING_MARKS_PATTERN = '/[\s。．.!！]+$/u'` に変え、
  `preg_replace(...) ?? $narration` で除去する形にした。危険性の理由もコード注釈に残した。

## [Warning] M5: top-level / child / 孤児 cut の扱いが未定義
- 判断: **対応する**
- 対応内容: 「数え方と異常データの扱い」節を追加。
  `stepCount` = `parent_cut_id === null` (導入/総括カットも含む)、
  `pointCount` = 親を解決できた子のみ、**孤児 cut は数えず検査対象にもしない**
  (位置を「手順 N-M」で表記できない = 表示できない指摘を出さない)。
  併せて取得順を `orderBy('sort_order')->orderBy('id')` に確定し (同値 sort_order で位置が揺れない)、
  テスト計画に孤児 cut と導入/総括カットのケースを追加した。

## [Suggestion] M6: 鮮度を id で見る前提 (source document が追記型か) を docs に明記
- 判断: **対応する**
- 根拠: 実装を確認した: `SourceDocumentService::appendDocument()` は毎回新しい行を INSERT し、
  `file_path` を上書き更新する経路は無い。解析対象は行ロック下の `latest('id')`。
  よって id 比較で正しいが、前提であることを書き残す必要がある。
- 対応内容: `ScenarioReportBuilder` の注釈に前提を明記し、M9 の docs 更新項目にも追加した
  (「in-place 更新の経路を作るときは比較方法を見直す」)。

## [Warning] M7: `formatPositions(finding.positions)` では「ほか」を判定できない
- 判断: **対応する**
- 根拠: 指摘が正しい。positions は先頭 5 件で打ち切られるため総件数が別途要る。
- 対応内容: `formatPositions(positions, count)` に変更し、`count > positions.length` のときだけ
  「ほか」を付ける実装を helper に書き下ろした。テスト計画にも「ほか」のケースを含めた。

## [Warning] M7: `types/manual.ts` に `BadgeTone` を持ち込むとドメイン型が UI atom に依存する
- 判断: **対応する**
- 根拠: 指摘が正しい。既存の `STATUS_TONES` が types 側にあるのは先行実装の事情であり、
  それを理由に増やすべきではない。
- 対応内容: `types/manual.ts` には **union 2 つと props 型だけ**を置き、
  ラベル・tone・位置整形は新規 `resources/js/components/features/manual/scenario-report.ts`
  (同階層の `insufficient-tickets.ts` が先例) に移した。理由も設計書に明記した。

## [Warning] 全体: 検証コマンドが AGENTS.md の全量と一致していない
- 判断: **対応する**
- 対応内容: `pnpm typecheck:packages` / `pnpm build:packages` / `pnpm test:packages` を追加し、
  「本変更は `packages/` を触らないが規約上は実行対象なので省略しない」と明記した。

## [Suggestion] M1 / M9: 「制御フローに使わない」を YAML と docs で同じ表現に固定 / UI 文言と揃える
- 判断: **対応する**
- 対応内容: M1 の YAML 先頭コメントに「表示専用で制御フローには使わない」を追記し、
  M9 で同じ表現を `docs/architecture.md` に置くこと、UI 文言 (「生成結果の確認」/
  「解析時の手順書に対するものです」) と揃えることを明記した。

## [Suggestion] M7: Button は既存デザインに合わせ、必要なら Lucide アイコン付きに
- 判断: **見送る (現状維持)**
- 根拠: 既存 `AnalysisPanel` / `RenderPanel` の副次導線も `variant="ghost"` のテキストボタンで、
  アイコンは主要 CTA (AI 解析 = Sparkles / 撮影 = Camera) に限っている。ここで足すと
  主要 CTA との視覚的な優劣が崩れる。DS token 準拠は維持。
