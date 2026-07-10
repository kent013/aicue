Round 1 の指摘に対応しました。再レビューをお願いします。改訂後の詳細設計全文は /workspace/devnotes/20260711-0137-ai-analysis/detailed-design.md（ファイル読み込み可）。

## 対応マトリクス

### [Critical] 施策 2: token budget 算術の不整合・「byte>=token」上界性 → 不整合は修正 / 上界性は根拠を添えて反論
- **反論（上界性）**: tokenizer は入力バイト列を「空でない区間」へ分割（partition）するため、いかなる入力・言語でも token 数 ≤ バイト数（1 token が 0 byte を消費することはない）。これは言語非依存の数学的上界であり、「言語依存で崩れる」ことはありません。崩れるのは「1 文字 = k token」のような文字ベース係数であり、Round 3（概念レビュー）でバイト基準へ移行済みです。
- **修正（不整合）**: テストを `config('manual.analysis_max_text_bytes') ≤ INPUT_BUDGET_TOKENS`（`INPUT_BUDGET_TOKENS = 200,000 − 16,000 − 4,000 = 180,000` を定数式で宣言）へ変更し、定数・式・説明を一致させました（config 150,000 は budget 180,000 へのマージン込み、と明記）。テスト名は「分割上界: token数<=バイト数」の保守的不変条件であることを明示。doc/10 §10.5 へ同一の値・式を転記（施策 13）。モデル/tokenizer 変更時の再確認は概念設計の運用条件として明記済み。

### [Critical] 施策 4/5/6: status 遷移責務の分散 → 遷移表 + guard + テスト固定で対応 / 1 クラス集約は反論
- **反論（集約）**: 本リポジトリの共有ロック規約（AGENTS.md ドメイン固有規約 1・docs/architecture.md）は「cuts / scenario_version / status を書く全経路は VideoManual 行を lockForUpdate した同一 tx 内で反映」を要求します。analyzing→ready は cuts を書く materialize と同一メソッドに置くのが規約準拠で、`ScenarioService::save()` が status を書く既存配置と同じ原理です。`markSucceeded` 等を AnalysisJobService へ集約すると cuts と status の書き込みが 2 サービスへ割れ、規約側が壊れます。
- **対応**: 施策 5 冒頭に **VideoManualStatus 遷移表**（遷移 × 唯一の書き込み経路 × from-state guard）を追加。全遷移が「行ロック + from-state guard（guard 不成立は no-op か例外）」を持つため後勝ちは構造的に発生しないことを明文化。遷移表は doc/10 §10.2 へ転記し、状態遷移 Feature テストと ScenarioWritePathInventoryTest（メソッド粒度 allowlist）で固定します。

### [Critical] 施策 6/9: materialize の内側 transaction / ロック順逆転リスク → 対応（提案どおり）
- `materializeIntoLockedManual(VideoManual $lockedManual, array $steps)` の**ロック済み前提メソッド**へ変更。transaction / lockForUpdate は `AnalysisPipeline::finalize`（最外層）だけが張り、ロック順を **job → manual → reservation/org** に固定（failJob と同順と明記）。
- 前提違反は即例外: `DB::transactionLevel() === 0` → LogicException（tx 外呼び出し検出）、`status !== analyzing` → LogicException（terminal tx ごと rollback → failJob）。

### [Warning] show の {analysisJob} ∈ {manual} 再検証 → 対応
- `if ($analysisJob->video_manual_id !== $manual->id) abort(404);` の inline 再検査を追加（scopeBindings + 二重防御。oauthSessions controller の再検査と同じ位置づけ）。

### [Warning] 孤児ファイル → 対応（最小限）
- 行 insert 失敗時に catch で `Storage::delete($path)`（best-effort）を追加。afterCommit 書き込みは「行はあるがファイルが無い」壊れ方（読み経路の即時破綻）を作るため不採用。外側 tx rollback 経路の残渣のみ許容し、掃除はストレージ Quota フェーズ（概念設計で明示済み）。

### [Warning] MIME/拡張子判定（polyglot）→ 対応
- アップロード時にサーバ側内容 sniff（finfo = `getMimeType()`）を許可 MIME 集合と照合、不一致は 422。DB の mime 列は sniff 値を保存し、SopTextExtractor の分岐は sniff 済み mime を使用（クライアント拡張子を信頼しない）。parser 内部例外は report + ユーザー向け文言へ正規化。テスト計画に拡張子偽装ケースを追加。

### [Warning] バックグラウンドタブのポーリング → 対応
- visibilitychange 連動（hidden で停止、再表示で即時 1 回 fetch → 再開）を AnalysisPanel 設計へ追加。

### [Warning] 並列テストの queue/clock 競合 → 対応
- キューのモードをテストごとに明示（トリガー検証 = Queue::fake / パイプライン実走 = sync）、時刻依存は `travelTo` で固定時刻へピン留め、fixture は全て Factory 生成（id 直書きなし）、fake はテスト内完結（StrayLlmCallGuard が漏れを検出）を施策 12 に明記。

### [Suggestion] status の DB check constraint → 見送り
- 既存テンプレートの enum 列は全て「string + アプリ層 cast」で check constraint を持たない（categories/cuts/takes 等）。本テーブルだけ導入すると規約が割れるため見送り。事故耐性はアプリ層 enum cast + 状態遷移テスト + 書き込み経路 inventory で担保。

### [Suggestion] LlmJson のエラーコード体系 → 採用
- LlmOutputInvalidException に reason（invalid_json | schema_violation）を持たせ、report ログで失敗分類を集計可能にすると明記。

### [Suggestion] 施策 13 に IDOR inventory 更新を明記 → 採用
- 「NestedRouteIdorDefenseTest inventory 更新（施策 3）を実装完了条件として明記」を追加。

【出力形式】
- 各施策ごとに判定: APPROVE / REQUEST_CHANGES
- 指摘は [Critical] [Warning] [Suggestion] で分類し、Critical/Warning には修正案を添える
- 全体判定: APPROVED / CHANGES_REQUESTED
- 日本語で出力
