**レビュー結果**

- `app/Services/Manual/SopTextExtractor.php:56` — **CHANGES_REQUESTED**
  - [Warning] `repairSjisMojibake()` が `pdf` 以外（`plain` / `spreadsheet`）にも適用されています。今回の原因は `smalot/pdfparser` の CMap 非対応に起因するため、現状実装だと正当な CP1252 文字（例: `é`）を半角カナ等へ誤変換する余地があります。`$kind === 'pdf'` のときだけ復元段を通すか、復元成立条件をさらに厳格化すべきです。
  - [Suggestion] 観測ログの `japanese_ratio_before/after` は `normalize()` 前、最終ゲートは `normalize()` 後で計算されており、運用分析時に比較がズレます。比較用途なら同一フェーズ基準に揃えると解釈しやすいです。

- `tests/Unit/Manual/SopTextExtractorTest.php:149` — **CHANGES_REQUESTED**
  - [Warning] 「正当な日本語 + CP1252拡張文字（例: `Café`）が混在する非PDF入力で文字化け復元が発火しない」回帰テストが不足しています。上記実装リスクを固定するテスト追加が必要です。
  - [Suggestion] `file_get_contents($tmp)` の `(string)` キャスト（`tests/Unit/Manual/SopTextExtractorTest.php:257`）は失敗を空文字へ潰すため、`is_string` 検証で明示失敗にした方が型・診断の両面で安全です。

- `app/Exceptions/Manual/AnalysisFailedException.php:15` — **APPROVE**
  - 指摘なし（文言体系の整理・次アクション追記は設計意図に整合）。

- `config/manual.php:34` — **APPROVE**
  - 指摘なし（`analysis_min_japanese_ratio` の導入とコメントは妥当）。

- `docs/architecture.md:119` — **APPROVE**
  - 指摘なし（実装方針の明文化として適切）。

**全体判定: CHANGES_REQUESTED**