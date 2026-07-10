# 対応マトリクス: design-review Round 3

## [Warning] 施策 6: timeout=600 が有界リトライの worst-case (1,080 秒) と不整合・retry_after 未考慮
- 判断: 対応する
- 対応内容: 時間 budget 表を新設し worst-case から再算定。
  LLM worst-case 1,080 秒（3 段 × 3 試行 × client timeout 120 秒）+ 抽出/解析/ロック待ち余裕
  180 秒 → **job `$timeout = 1380`**。既定 database 接続の retry_after (90 秒) では不足のため
  **専用 connection `database-analysis`（retry_after = 1560）** を config/queue.php に追加し
  job の `$connection` で指定。連鎖 `timeout (1380) < retry_after (1560) < 予約 TTL (1800)
  ≤ stale 閾値 (1800)` を新設の `AnalysisTimeBudgetInvariantTest` で CI 固定
  （worst-case 算術のテストも追加）。概念設計の timeout 記述も同期更新。

## [Suggestion] timeout 算定に抽出・DB ロック待ち・解析余裕を含める
- 判断: 採用する（上記 budget 表の「抽出 + 解析/DB 余裕 180 秒」に織り込み済み）

## [Warning] 施策 7: 推測変換がバイナリを無意味な日本語へ化けさせる
- 判断: 対応する（Codex 修正案どおり）
- 対応内容: ensureUtf8 を strict 手順に変更:
  (1) mb_check_encoding OK → そのまま
  (2) NG → `mb_detect_encoding($text, ['UTF-8','SJIS-win','EUC-JP'], strict: true)`、
      判定不能 (false) → unextractable（バイナリ扱い。変換しない）
  (3) 変換後に再度 mb_check_encoding 検証、不合格 → unextractable
  (4) mb_scrub は検証合格後の残存破損の限定補修のみ（救済変換に使わない）
  テストに「判定不能バイナリ fixture → unextractable」「変換後再検証 NG → unextractable」を追加。

## [Warning] 施策 9: ファイル単位 allowlist では ScenarioService 内の新規呼び出しを検出できない・自己検証欠落
- 判断: 対応する（Codex 修正案どおり）
- 対応内容: token_get_all ベース走査（PrismDirectDispatchScanner と同流儀）で
  「`->materializeIntoLockedManual(` = 呼び出し」と「`function materializeIntoLockedManual` = 宣言」を
  token 列で区別。宣言は ScenarioService.php のみ、**呼び出しは AnalysisPipeline.php のみ**許可
  （ScenarioService 自身の中の新規呼び出しも fail = ファイル単位の抜け穴を塞ぐ）。
  scanner の自己検証（コメント内出現の無視 / 呼び出し検出 / 宣言検出 / degenerate PASS 防止）を
  テスト計画に追加。
