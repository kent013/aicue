# 対応マトリクス: conceptual-review Round 1

## [Critical] ready からの再解析ができない（SOP 差し替えと状態遷移の不整合）
- 判断: 対応する
- 根拠: 「SOP を起点に標準化する」使命に照らし、SOP 更新→再解析は正規フロー。§10.2 の
  draft→analyzing のみでは閉じない。§10.8-8 の冪等制約は「同時 in-flight 1 つ」の並行制御であり
  succeeded 後の明示的再解析を禁じる趣旨ではないと解釈。
- 対応内容: analyze 実行可能状態を `status ∈ {draft, ready}` に拡張（analyzing/rendering/
  published は 409）。ready→analyzing を v1 の正式遷移として設計に明記。既存 cuts は
  materialize で全置換（フロントは ready からの実行時に確認ダイアログ）。失敗時の復帰は
  「cuts が 1 件以上あれば ready、無ければ draft」に一般化。

## [Critical] dispatch 失敗で queued + analyzing が残留する
- 判断: 対応する
- 根拠: afterCommit dispatch はキュー投入喪失の窓がある。トランザクション同梱は
  database driver 前提の暗黙結合になるため、driver 非依存の回復ネットを選択。
- 対応内容: `analysis:recover-stale-jobs` console command（5 分毎 schedule、
  billing:release-stale-reservations と同型）を新設。queued が 30 分超 → failJob。
  遅延配送が後から届いても run() 冒頭の queued guard で no-op（二重実行なし）。

## [Critical] running 中の worker 異常終了で予約 + analyzing が孤児化する
- 判断: 対応する
- 根拠: tries=1 と queued-only guard の組み合わせで自己回復経路が無いのは指摘どおり。
- 対応内容: 同じ回復 cron が `running かつ updated_at 30 分超` を failJob（release +
  manual 復帰）。pipeline は各 step 遷移で progress を更新するため updated_at が
  ハートビートとして機能（専用カラム追加なし）。閾値 30 分 ≫ job timeout 600 秒で誤検知なし。
  failJob は行ロック + status guard で冪等（cron / failed() フック競合も安全）。

## [Critical] LLM 入力長の上限戦略がない
- 判断: 対応する
- 根拠: 長文 SOP で文脈長超過・コスト暴走の指摘は妥当。v1 は分割/要約はスコープ外とし
  明示エラーで返すのが最小で誠実。
- 対応内容: config `analysis_max_text_chars`（初期 100,000 字）を追加し、SopTextExtractor が
  超過時に「手順書が大きすぎます」で failJob。アップロード時も size_bytes 上限で一次防衛。
  加えて LLM 出力側も YAML に max_tokens を明示（生成 JSON の切り詰め防止、16,000 目安）。

## [Warning] 402 応答が response()->json() 直書きに流れやすい
- 判断: 対応する
- 対応内容: ScenarioConflictException::render() と同型の「専用 JsonResource +
  ->response()->setStatusCode(402)」パターンを概念設計に固定した。

## [Warning] parser 品質依存・失敗判定の診断情報
- 判断: 対応する（最小限）
- 対応内容: SopTextExtractor の戻り値を ExtractedText 値オブジェクト（text / charCount /
  sourceKind）にし、実質空（最小文字数未満）を早期失敗にする。詳細な品質メトリクスは
  過剰設計のため見送り。

## [Warning] 差し替えで SourceDocument を削除すると監査性が落ちる
- 判断: 対応する
- 対応内容: SourceDocument を追記型 immutable に変更（差し替え = 新規行追加。削除・上書きなし。
  解析は latest を使用）。過去 analysis_jobs の source_document_id / extracted_json が保たれる。
  旧ファイルの物理掃除はストレージ Quota フェーズへ明示的にスコープ外化。

## [Warning] commit と succeeded の原子性が曖昧
- 判断: 対応する
- 対応内容: finalize を単一 tx にし、TicketLedgerService::commit の内部 DB::transaction は
  同一接続のネスト（savepoint）として原子性を確保することを明記。テスト観点
  「succeeded ⇔ committed」を追加。

## [Warning] 書き込み経路 inventory がクラス粒度で粗い
- 判断: 対応する
- 対応内容: メソッド粒度の inventory 表（save / materializeFromAnalysis / trigger / failJob ×
  書いてよい遷移）を概念設計に明記。AnalysisPipeline / job / cron は直接書かず必ず
  Service メソッド経由。

## [Warning] 「二重課金/二重実行が起きない」の言い切りが強すぎる
- 判断: 対応する
- 対応内容: 期待効果を「各失敗モードで二重課金しない / analyzing で詰まない方向へ収束する
  設計（テストで固定）」に弱めた。

## [Warning] 実装単位が広い
- 判断: 対応する
- 対応内容: 1 チケット内を (1) 状態機械+課金 → (2) 抽出+LLM+materialize → (3) UI の
  3 層に段階化する方針を実装方針に追記。

## [Warning] result_json / extracted_json の array 汚染（PHPStan lv10）
- 判断: 対応する
- 対応内容: DTO を in-memory で次段へ渡し、json 列は write-only 監査スナップショットと
  位置づける（v1 のアプリコードは DB から再読込しない）。再読込が必要になった時点で
  custom cast による DTO 復元を固定、と明記。

## [Suggestion] ScenarioLimits 昇格・段階化の評価等
- 判断: 採用済み（設計に反映済みのため変更なし）
