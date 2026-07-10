# 対応マトリクス: design-review Round 1

## [Critical] 施策 2: token budget 算術の不整合・「byte>=token」の上界性
- 判断: 不整合は対応する / 上界性の否定には根拠を添えて反論する
- 根拠（反論部分）: tokenizer は入力バイト列を「空でない区間」へ分割（partition）するため、
  いかなる入力・言語でも token 数 ≤ バイト数。これは言語非依存の数学的上界
  （1 token が 0 byte を消費することはない）。
- 対応内容: テストを `max_text_bytes ≤ INPUT_BUDGET_TOKENS (= context − 出力予約 − 固定分 = 180,000)`
  の形に改め、定数・式・説明（budget 180,000 / config 150,000 = マージン込み）を一致させた。
  テスト名・コメントに分割上界の根拠と「モデル/tokenizer 変更時の再確認」運用条件を明記。
  doc/10 への同一値・式の転記を施策 13 に追加。

## [Critical] 施策 4/5/6: status 遷移責務の分散
- 判断: 遷移表・guard・テストの固定で対応する / 1 クラス集約には根拠を添えて反論する
- 根拠（反論部分）: 共有ロック規約（AGENTS.md ドメイン固有規約 1）は「cuts / scenario_version /
  status を同一の行ロック tx 内で書く」ことを要求する。analyzing→ready は cuts を書く
  materialize と同一メソッドに置くのが規約準拠（`ScenarioService::save()` が status を書くのと
  同じ配置原理）。AnalysisJobService へ集約すると cuts と status の書き込みが 2 サービスに割れ、
  規約の方が壊れる。
- 対応内容: 施策 5 冒頭に **VideoManualStatus 遷移表**（遷移 × 唯一の書き込み経路 ×
  from-state guard）を明記。全経路が行ロック + from-state guard を持ち「後勝ち」が構造的に
  発生しないことを明文化。遷移表は doc/10 §10.2 へ転記（施策 13）し、状態遷移 Feature テスト +
  ScenarioWritePathInventoryTest で固定。

## [Critical] 施策 6/9: materialize の内側 transaction とロック順逆転リスク
- 判断: 対応する（Codex 提案どおり）
- 対応内容: `materializeFromAnalysis(Project, VideoManual, steps)` を廃し、**ロック済み前提
  メソッド `materializeIntoLockedManual(VideoManual $lockedManual, steps)`** に変更。
  transaction / lockForUpdate は AnalysisPipeline::finalize（最外層）だけが張り、ロック順を
  **job → manual → reservation/org** に固定（failJob と同順）。前提違反は即例外
  （`DB::transactionLevel() === 0` / status !== analyzing → LogicException）。

## [Warning] 施策 5: show の {analysisJob} ∈ {manual} 明示再検証
- 判断: 対応する
- 対応内容: `$analysisJob->video_manual_id !== $manual->id → abort(404)` の inline 再検査を追加
  （oauthSessions controller の二重防御と同じ位置づけ）。

## [Warning] 施策 4: 孤児ファイルの運用負債
- 判断: 対応する（最小限）
- 対応内容: 行 insert 失敗時に catch で Storage::delete（best-effort 即時削除）。
  afterCommit 書き込みは「行があるのにファイルが無い」壊れ方を作るため不採用。
  外側 tx rollback 経路の残渣のみ許容（掃除はストレージ Quota フェーズ = 概念設計で明示済み）。

## [Warning] 施策 7: MIME/拡張子判定の曖昧さ（polyglot）
- 判断: 対応する
- 対応内容: アップロード時にサーバ側で内容 sniff（finfo = getMimeType）し許可 MIME 集合と照合、
  不一致は 422。DB の mime 列には sniff 値を保存し、抽出器の分岐はこの sniff 済み mime を使う
  （クライアント拡張子を信頼しない）。parser 内部例外は report + ユーザー向け文言へ正規化。

## [Warning] 施策 11: バックグラウンドタブのポーリング
- 判断: 対応する
- 対応内容: visibilitychange 連動（hidden で停止、再表示で即時 1 回 fetch → 再開）を設計に追加。

## [Warning] 施策 12: 並列実行下の queue/clock 競合
- 判断: 対応する
- 対応内容: キューのモードをテストごとに明示（Queue::fake / sync）、時刻は travelTo で固定、
  fixture は Factory 生成（id 直書きなし）、Storage/Prompt fake はテスト内完結、を明記。

## [Suggestion] 施策 1: status に DB check constraint
- 判断: 見送る
- 根拠: 既存テンプレートの enum 列は全て「string + アプリ層 cast」（categories/cuts/takes 等）で
  check constraint を持たない。本テーブルだけ導入すると規約が割れる。事故耐性はアプリ層 enum
  cast + 状態遷移テスト + 書き込み経路 inventory で担保。

## [Suggestion] 施策 8: LlmJson の共通エラーコード
- 判断: 採用する
- 対応内容: LlmOutputInvalidException に reason（invalid_json | schema_violation）を持たせ
  report ログで失敗分類を集計可能にする、と明記。

## [Suggestion] 施策 13: IDOR inventory 更新の明記
- 判断: 採用する
- 対応内容: 施策 13 に「NestedRouteIdorDefenseTest inventory 更新（施策 3）を実装完了条件として
  明記」を追加。
