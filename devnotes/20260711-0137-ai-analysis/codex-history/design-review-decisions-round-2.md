# 対応マトリクス: design-review Round 2

（Codex 側の読み取り環境制約でファイル本文を参照できなかったため、Round 3 プロンプトには
該当施策の改訂全文をインラインで添付する）

## [Warning] 施策 6/9: transactionLevel は manual 行ロックを保証しない
- 判断: 対応する
- 対応内容: 前提の担保を 2 層化。
  (1) **呼び出し経路の構造的限定**: ScenarioWritePathInventoryTest に検出 3
  「`materializeIntoLockedManual(` の呼び出しは定義 (ScenarioService) と AnalysisPipeline
  以外に現れたら fail」を追加（deny-by-default の機械検証）。
  (2) runtime 検査（transactionLevel / analyzing guard）は defensive として残す。
  private 化は「別 Service（ScenarioService）にロジックを置く」規約上不可能なため、
  Architecture テストでの経路限定を採用（Codex 修正案の後半を採用）。

## [Warning] 施策 6: 宣言ロック順と TicketLedgerService 内部順の整合
- 判断: 対応する（実装から転記して検証済み）
- 検証結果: TicketLedgerService の実取得順は reserve/grant = organizations のみ
  (L42/L243)、commit/release = ticket_reservations (lockReservationRow) → organizations
  (lockOrganizationRow) (L266-269/L290-293)。
- 対応内容: グローバルロック順を
  `analysis_jobs → video_manuals → ticket_reservations → organizations` と定義し、
  全経路（trigger / startJob / finalize / failJob / releaseStale cron / ScenarioService::save）の
  取得列がこの順の部分列であることを finalize の docblock に転記。循環待ちは構成できない。
  競合（インターリーブ）テストは施策 6 のテスト計画に既載。

## [Warning] 施策 7: 抽出結果の UTF-8 妥当性
- 判断: 対応する
- 対応内容: normalize 前に `ensureUtf8()`（mb_check_encoding → NG なら mb_convert_encoding
  (SJIS-win/EUC-JP 等の検出変換) → 残る不正バイトは mb_scrub）。scrub 後に min_text_bytes
  未満なら `AnalysisFailedException::unextractable()`。不正 UTF-8 fixture のテストを追加。

## [Suggestion] reason を backed enum に
- 判断: 採用する
- 対応内容: `App\Enums\Manual\LlmOutputInvalidReason`（InvalidJson / SchemaViolation）を新設し
  LlmOutputInvalidException が保持。施策 8 の変更ファイル一覧に enum と LlmJson ヘルパを追加。
