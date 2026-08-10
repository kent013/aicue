# 対応マトリクス: conceptual-review Round 1

## [Warning] 2. SOP ダミーテキストのコード直書き (prompt 直書き禁止との境界)
- 判断: **対応する** (ただし理由は Codex の主張とは一部異なる)
- 根拠: 禁止事項 6 は **prompt template** の直書き禁止であり、LLM への一次入力データ (fixture) は
  対象外である。ただし「LLM に渡る文字列がコードに埋まっている」形は将来の誤読を生むという
  指摘は妥当。加えて fixture はそれ自体が機械的な受入条件 (100 バイト以上・日本語比率 0.10 以上) を
  満たす必要があり、**ファイルにすれば単体テストで直接固定できる**という実利がある。
- 対応内容: `resources/fixtures/pipeline-smoke-sop.txt` に外出しし、コマンドは読むだけにする。
  「prompt は `resources/prompts/*.yaml` の既存経路のまま」と明記。
  fixture が `manual.analysis_min_text_bytes` / `manual.analysis_min_japanese_ratio` を満たすことを
  検査する単体テストを施策に追加する。

## [Warning] 3-a この実行分の LLM ログの切り出しが弱い
- 判断: **対応する** (指摘どおり)
- 対応内容: 「実行分」の定義を `id > (開始前の MAX(id)) ∧ created_at >= 開始時刻` と明文化する。
  `--run-id` を metadata に載せる案は**別件へ分離**する (`withMetadata()` の導入は
  AnalysisPipeline 側の変更であり、本件のスコープ外。思考原則 2)。

## [Warning] 3-b worker 待ちの成功条件が抽象的
- 判断: **対応する**
- 対応内容: 段ごとに (polling 対象 / 成功状態 / 失敗状態 / 待機上限 / timeout 時の診断出力) を
  表で確定させる。診断出力には job status・manual status・`error` 列・`step`・`progress`・
  `jobs` 表の残件数・`llm_call_logs` の当該実行分件数を含める。

## [Warning] 5. bug-hunt 外での誤実行防壁が薄い
- 判断: **対応する** (指摘どおり。shell 導線だけに安全性を寄せない)
- 対応内容: コマンド本体に fail-secure 4 条件を置き、**`--force` でも迂回できない**ものとする:
  (1) `app()->environment('bughunt.local')`、(2) DB 名が bug-hunt regex に一致、
  (3) `FakeStorageGate::enabled()`、(4) `config('testing.fake_llm') === false`。
  DB 名 regex は二重管理しない — SSOT を `App\Support\BughuntDatabaseGuard` へ昇格させ、
  既存の `Database\Seeders\Concerns\DetectsBughuntDatabase` はそこへ委譲する
  (依存の向きが app ← seeders になり正しい。3 seeder の呼び出し側は不変)。

## [Warning] 7. コストレポート DTO の型が曖昧
- 判断: **対応する**
- 対応内容: `--group-by` は enum 化 (`App\Enums\LlmCostReportGroupBy`)。金額は
  `numeric-string|null` で持ち、独自 Money 抽象は作らない (過剰抽象を避ける)。
  JPY は `totalCostJpy` (非 null 行の合計) と `jpyUnresolvedCalls` (null 行数) を**別フィールド**に
  分けて null 混在を隠さない。USD 側も `usdUnresolvedCalls` (pricing 解決失敗行数) を持つ。

## [Suggestion] 1 / 4 / 6
- 判断: 見送る (肯定的評価であり変更を要さない)
