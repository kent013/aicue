Round 1 の指摘に対する対応を報告します。全 Critical / Warning に対応しました。再レビューをお願いします。

## 対応マトリクス（要約）

### [Critical] ready からの再解析ができない → 対応
- analyze 実行可能状態を `status ∈ {draft, ready}` に拡張（analyzing/rendering/published は 409）。
  **ready→analyzing を v1 の正式な状態遷移として定義**（§10.2 への設計上の追加。本設計ドキュメントが決定の記録）。
- 既存 cuts は materialize で全置換。フロントは ready からの実行時に「既存シナリオが置き換えられます」確認ダイアログ。
- 失敗時の復帰を一般化: 「manual が analyzing のときのみ復帰。cuts ≥1 なら ready、無ければ draft」
  （§10.2 の「失敗は draft へ」は cuts 無し初回解析ケースとして包含）。
- SOP 差し替え（source-documents POST）の許可状態も `{draft, ready}` に明示。

### [Critical] dispatch 失敗で queued+analyzing 残留 → 対応
- `analysis:recover-stale-jobs` console command を新設し 5 分毎 schedule（billing:release-stale-reservations と同型の運用プリミティブ）。
- `queued` かつ created_at 30 分超 → failJob（release + manual 復帰）。遅延配送が後から届いても
  `run()` 冒頭の「status !== queued なら no-op」guard で二重実行にならない。
- キュー driver（database/SQS）非依存の回復ネットとして設計（トランザクション同梱 dispatch は database driver への暗黙結合になるため不採用）。

### [Critical] running 孤児（worker 異常終了）→ 対応
- 同じ回復 cron が `running` かつ updated_at 30 分超を failJob。
- pipeline は各 step 遷移（extract→decompose→generate）で progress を更新するため **updated_at がハートビート**として機能（専用カラム追加なし = 最小変更）。閾値 30 分 ≫ job timeout 600 秒で誤検知しない。
- failJob は行ロック + status guard で冪等（cron / Job::failed() フックの競合も安全）。

### [Critical] LLM 入力長の上限戦略なし → 対応
- config `analysis_max_text_chars`（初期 100,000 字）を新設。SopTextExtractor が超過検知 →
  「手順書が大きすぎます（上限 N 文字）。分割してアップロードしてください」で failJob。
- アップロード時の `source_document_max_bytes` で一次防衛（二段構え）。
- LLM 出力側も 3 YAML に `max_tokens` を明示（生成シナリオ JSON の切り詰め防止。16,000 目安、詳細設計で確定）。
- 分割・要約前処理は明示的にスコープ外（後続フェーズ）。

### [Warning] 402 の response()->json() 直書きリスク → 対応
- `ScenarioConflictException::render()` と同型の「専用 JsonResource + `->response()->setStatusCode(402)`」パターンに概念設計の段階で固定。

### [Warning] parser 品質・診断情報 → 対応（最小限）
- SopTextExtractor の戻り値を ExtractedText 値オブジェクト（text / charCount / sourceKind）とし、実質空（最小文字数未満）を早期失敗にする。

### [Warning] 差し替えによる監査性低下 → 対応
- SourceDocument を**追記型 immutable** に変更: 差し替え = 新規行追加、既存行・ファイル・extracted_json は削除も上書きもしない。解析は latest の 1 件を使用。過去 analysis_jobs の参照が保たれる。旧ファイルの物理掃除はストレージ Quota フェーズへスコープ外化。

### [Warning] commit と succeeded の原子性 → 対応
- finalize を単一 tx にし、TicketLedgerService::commit の内部 DB::transaction は同一接続のネスト（savepoint）として原子的にコミットされることを明記。テスト観点「succeeded ⇔ committed」を追加。

### [Warning] 経路 inventory がクラス粒度で粗い → 対応
- メソッド粒度の inventory 表を明記:
  - `ScenarioService::save()`: cuts / scenario_version / status（rendering·analyzing guard 付き）
  - `ScenarioService::materializeFromAnalysis()`: cuts / scenario_version / status（analyzing→ready のみ）
  - `AnalysisJobService::trigger()`: status（draft·ready→analyzing のみ）
  - `AnalysisJobService::failJob()`: status（analyzing→ready·draft のみ）
- AnalysisPipeline / job / cron は直接書かず必ず上記 Service メソッド経由。deny-by-default の静的走査テスト。

### [Warning] 「二重課金/二重実行が起きない」の言い切り → 対応
- 「各失敗モードで二重課金しない / analyzing で詰まない方向へ収束する設計（テストで固定）」に弱めた。

### [Warning] 実装単位が広い → 対応
- 1 チケット内を (1) 状態機械+課金の閉塞 → (2) 抽出+LLM 3 段+materialize → (3) UI の 3 層に段階化。

### [Warning] result_json / extracted_json の array 汚染 → 対応
- DTO は in-memory で次段へ受け渡し、json 列は write-only の監査スナップショット（v1 のアプリコードは DB から再読込しない）。再読込が必要になった時点で custom cast による DTO 復元を固定する、と明記。

---

改訂後の概念設計全文は /workspace/devnotes/20260711-0137-ai-analysis/conceptual-design.md にあります（ファイル読み込み可）。上記対応を織り込み済みです。

【出力形式】
- 全体判定: APPROVED / CHANGES_REQUESTED
- 残る指摘は [Critical] [Warning] [Suggestion] で分類し、Critical/Warning には修正提案を添える
- 日本語で出力
