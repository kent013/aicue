# bug-hunt report shard-1 (run 20260803-203721)

- 対象 URL: http://127.0.0.1:8011 (DB: bug_hunt_1, users at start: 11)
- 実行ストーリー: S3 (アプリ中核ジャーニー) → S7 (認可境界 IDOR、S3 後の状態を意図的に再利用・reseed なし)
- モード: real-llm / fake storage / fake payment・captcha・sso / mail=log
- 開始時刻: 2026-08-03 (JST)

## 画面カバレッジ (S3 screens)
- [x] projects.show
- [x] projects.manuals.create
- [x] projects.manuals.show
- [x] projects.manuals.edit
- [x] projects.manuals.jobs.show (※ Inertia 画面ではなく JSON ポーリング専用 API と判明。インベントリ修正提案参照)
- [x] projects.manuals.render-jobs.show (同上)
- [x] projects.manuals.render-jobs.playback
- [x] projects.manuals.download (準備完了/published でない状態では 404 になることを確認。published 状態での正常系は未検証 = skip 理由: 時間予算超過)
- [x] capture.home (/app → capture.manuals.index へフォワード)
- [ ] capture.csrf-cookie (裏で自動発行されるのみ、直接確認はしていない。skip 理由: 明示的な画面遷移が無く時間予算内で直接検証する価値が低いと判断)
- [x] capture.manuals.index
- [x] capture.manuals.show
- [x] capture.takes.playback (プレビューモーダル内で確認)

## 操作カバレッジ (S3 operations)
- [x] projects.manuals.store (バリデーションエラー→即時クリアも確認)
- [ ] projects.manuals.update (基本情報の「タイトル/カテゴリ」フォームは編集画面に存在を確認したが実際の保存クリックは未実施。skip 理由: 時間予算、scenario.update で同種の保存パターンを確認済みのため優先度を下げた)
- [x] projects.manuals.destroy (F-2: flash 欠落)
- [x] projects.manuals.duplicate
- [x] projects.manuals.source-documents.store
- [x] projects.manuals.analyze (F-1: 現実的サイズの PDF でタイムアウト再現。解析中の保存ブロック(409/scenario_conflict)は正しく機能することも確認)
- [x] projects.manuals.scenario.update (楽観ロック 409 → 差分再取得の一連 UX、Undo/Redo も確認)
- [x] projects.manuals.preview
- [x] projects.manuals.render (チケット消費確認ダイアログ、採用テイク未設定時の事前ブロックとカット名明示を確認)
- [x] capture.takes.upload-url
- [x] capture.takes.store
- [x] capture.takes.update (コメント編集)
- [x] capture.takes.destroy (確認ダイアログあり)
- [x] capture.takes.adopt
- [ ] capture.takes.downloaded (自動DL+ACK の実挙動は未検証。skip 理由: 時間予算。capture.manuals.show 入室時の自動DLは目視上は取得済みテイクが無かったため発火条件に当たらず)

## S7 IDOR チェックリスト
- [x] B (owner-personal@example.com, Personal 組織) から A (owner-standard@example.com, Standard 組織) の projects.show/manuals.show/manuals.edit/jobs.show/render-jobs.show/render-jobs.playback/manuals.download/capture.manuals.show/categories.index 直叩き → **全て 404** (200/302 も 403 も無し、存在オラクル無し)
- [x] B から A の manual write (update/destroy/scenario.update/analyze/render/preview/duplicate/source-documents.store) → **全て 404**
- [x] B から A の category (update/destroy/reorder) → **全て 404**
- [x] B から A の capture.takes.* (store/adopt/update/destroy/upload-url/downloaded/playback) → **全て 404**
- [x] cross-cut adopt (A 内 cut 17 の take 10 を cut 18 の adopt に渡す、同一組織内テスト) → **404** (`No query results for model [App\Models\Take] 10`。cut->takes() relation 経由の解決を確認)
- [x] project_member (撮影者、member-standard@example.com を project 1 に撮影者として追加) で編集者専用操作 (manuals.store/update/destroy, categories.store, analyze, render, manage.users) → **全て 403** (200/404 ではない)。同ユーザーで capture.manuals.show への到達は 200 (撮影者権限は機能している = 単純な org 全面ブロックではないことを確認)
- [x] protected keys (project_id, category_id) を manuals.update の payload に混入 → **422** (ProhibitsProtectedKeys)。`category` (id サフィックス無し) は許容されることを確認 (実際に更新が通った)
- [x] 存在オラクル: 実在する A の manual (id=2) と非実在 id (99999) への B からのアクセスで status (404/404) が同一であることを確認 (応答時間にも際立った差は無し、複数回計測はしていないため厳密なタイミング攻撃耐性までは未検証)

## UI/UX 検証 (H11-H14)
- 視覚破綻(H11): S3 走行中に視認できる破綻は無し (desktop/mobile/tablet とも)。
- アフォーダンス/状態(H12): ボタンの有効/無効・採用中バッジ・下書き/解析中/準備完了等のステータスバッジが判別可能。Undo/Redo ボタンの有効/無効切替も適切。
- レスポンシブ(H13): `projects.show`(mobile 375x667) / `projects.manuals.edit`(mobile 375x667, tablet 768x1024) / `capture.manuals.show`(mobile 375x667) を確認。いずれも横スクロール無し (`document.documentElement.scrollWidth === clientWidth` を実測)、要素のはみ出し・重なりも無し。証跡: screenshots/H13-mobile-projects-show.png, H13-mobile-manual-edit.png, H13-mobile-capture.png, H13-tablet-manual-edit.png。確認後 desktop (1280x800) に復帰。
- a11y基礎(H14): 目立った欠落は無し。補足: `<video>` (完成動画プレビュー) が playwright-cli の accessibility snapshot に現れない (ツールの制約の可能性が高く、実際は表示・機能とも正常。DOM/eval で確認済み。finding としては計上しない)。

## findings
(Critical/High/Medium/Low/要確認 件数は末尾サマリに記載。以下 finding 詳細を随時追記)

## F-1: AI 解析が現実的なサイズの SOP (PDF 290KB 程度) で「generate」段のタイムアウトにより再現性高く失敗し、リトライも行われない (North Star フロー破綻)
- severity: High
- story/step: S3-4/S3-5 (`projects.manuals.analyze`)
- 再現手順:
  1. `owner-standard@example.com` / password123 でログイン (http://127.0.0.1:8011/login)
  2. プロジェクト作成 → 動画マニュアル作成 (タイトル任意)
  3. 手順書として `doc/reference/sample-sop/AS_作業手順書.pdf` (290,498 bytes、リポジトリ同梱のサンプル SOP) をアップロード
  4. 「AI 解析」を押す → 「手順書を読み取り中」→「作業を分解中」→「シナリオを生成中」と進むが、約 2 分 (120,002ms) で `ConnectionException` (`cURL error 28: Operation timed out ... for https://api.anthropic.com/v1/messages`) が発生し job が failed、manual は status=draft に自動復帰し「解析に失敗しました。時間をおいて再実行してください。」の alert が出る
  5. 同じ手順書のまま「AI 解析」を再実行 → **2 回連続で同じ「generate」段のタイムアウトで失敗** (11:48:56 と 11:54:25、いずれも 120,002ms、`storage/logs/laravel.log` に記録)
  6. 切り分けとして、194 バイトの極小テキスト SOP (`簡易組立手順書` 4 手順) で新規マニュアルを作り同様に解析 → 約 50 秒で status=準備完了 (成功)。**入力サイズに比例して generate 段が時間超過し、実運用でユーザーが使う現実的なサイズの PDF (リポジトリの reference サンプルそのもの) では確実に失敗する**ことを確認
- 期待: 実際の業務で使われる程度のサイズの SOP (数百 KB の PDF) が AI 解析を安定して完走できる。少なくとも generate 段のプロバイダ例外 (timeout 含む) に対して有界リトライが効くか、またはユーザーに「文書が大きすぎる可能性」等の具体的な次アクションが示される
- 実際: `app/Services/Manual/AnalysisPipeline::withBoundedRetry` は `LlmOutputInvalidException` (JSON 検証失敗) のみリトライし、`ConnectionException` 等のプロバイダ/接続例外は**一切リトライされずに即 failJob**する。一方 `app/Jobs/Manual/RunManualAnalysis.php` のコメントは「worst-case (LLM 3段 × 3試行 × client timeout 120s = 1,080s)」と、あたかも 3 試行のリトライ予算があるかのように書かれており、**実装とコメントの想定が矛盾**している。結果、現実的なサイズの手順書では「AI 解析」がユーザー操作では実質的に使えない (2/2 で失敗)
- 阻害されたユーザージョブ: North Star の起点である「手順書 (SOP) から AI がカット設計を行う」が、実際のサンプル SOP 相当のサイズで機能しない。ユーザーは何度再実行しても同じ理由で失敗し続け、手動でシナリオを作る以外の回避策が無い (詰みに近い)
- 改善アクション候補: (1) `withBoundedRetry` で provider/connection 例外 (特にタイムアウト) も有界リトライの対象に含める、または (2) generate 段の実効タイムアウトを引き上げる/ストリーミングに変更する、(3) 失敗理由をタイムアウトかどうかで分岐し「文書が大きい場合は分割してください」等ユーザーが取れる具体的な次アクションを alert に出す
- 証跡: screenshots 未取得 (alert 文言は snapshot テキストで確認)。ログ: `storage/logs/laravel.log` の 2026-08-03 11:48:56 および 11:54:25 の `cURL error 28: Operation timed out after 120002 milliseconds ... for https://api.anthropic.com/v1/messages`
- 推定原因: `app/Services/Manual/AnalysisPipeline.php` の `withBoundedRetry()` (LlmOutputInvalidException 以外はリトライしない設計) と `app/Jobs/Manual/RunManualAnalysis.php` のタイムアウト予算コメントの不整合。実効タイムアウト値 (120s) の出所は `config/prism.php` の `request_timeout` (既定 30s) では説明が付かず、`vendor/kent013/laravel-ssrf-pin` (SSRF pinning transport) 側の deadline 機構が絡んでいる可能性があるが、5 分の調査では特定に至らず未確定
- 関連既知情報: なし (bug-hunt 初見)



## F-2: 動画マニュアル削除後のリダイレクト先 (projects.show) に成功フィードバック (flash) が出ない
- severity: Low
- story/step: S3-手順9相当 (`projects.manuals.destroy`)
- 再現手順:
  1. `owner-standard@example.com` でログイン → 任意のプロジェクトの動画マニュアル詳細 (`projects.manuals.show`) を開く
  2. 「動画マニュアルを削除」→ 確認ダイアログで「削除する」
  3. `projects.show` にリダイレクトされる。一覧からは正しく消えている (H10 は満たす) が、削除成功を示す flash/toast (他操作で見られる「〜しました」表示) が一切出ない
- 期待: 他の操作 (作成・複製・SOP アップロード・シナリオ保存等) と同様に「動画マニュアルを削除しました」等の成功フィードバックが表示される
- 実際: 一覧からの消失のみが唯一のフィードバックで、明示的な flash が無い
- 阻害されたユーザージョブ: 致命的ではないが、削除操作の成否がひと目で分かりにくく、意図した操作が実行されたか不安になりうる (一覧を見比べて初めて確認できる)
- 改善アクション候補: 他の破壊的操作 (`capture.takes.destroy` は削除後も一覧が残るリストなので確認しやすいが、`manuals.destroy` はリダイレクト先が別画面のため) と同様にリダイレクト後の flash を出す
- 証跡: snapshot 確認 (`devnotes/20260803-203721-bug-hunt/shard-1` 走行ログ。screenshot 未取得)
- 推定原因: 未調査 (コントローラ側で flash session を積んでいない可能性。5分調査の対象外としスキップ)
- 関連既知情報: なし

## skip した項目
(理由必須で随時追記)

## インベントリ修正提案
- `projects.manuals.jobs.show` / `projects.manuals.render-jobs.show` はブラウザで直接フル GET すると Inertia ページ (HTML) ではなく生 JSON (`content-type: application/json`) が返る。実際は `projects.manuals.show` 画面内の JS が非同期ポーリングに使う API エンドポイントであり、ユーザーが直接遷移する「画面」ではないと考えられる。screens.md 上は「画面」として計上されているが、実態は operations.md 側の「読み取り専用 API」に近い。次回インベントリ更新時に区分の見直しを検討 (誤りと断定はしないため「要確認」)。

## 環境ハザード (EH-n)

### EH-1 (非停止・記録のみ): Anthropic API タイムアウトによる解析失敗 2 回連続 (manual id=1)
- 発生: 2026-08-03 11:48:56 JST および 11:54:25 JST、manual id=1 (組立手順書, project 1) の `projects.manuals.analyze` (1 回目・再実行 1 回目)
- 内容: `storage/logs/laravel.log` に `cURL error 28: Operation timed out after 120002 milliseconds with 0 bytes received ... for https://api.anthropic.com/v1/messages` (ConnectionException)。job step=generate, progress=65% で failed (2 回とも同一パターン)。
- **この後さらに広く再現性を確認し F-1 (High) として finding 化した** (詳細は findings 節)。EH-1 自体は「単発の外部 API 事象の記録」として残す (F-1 と重複計上しない)。
- HTTP status: (接続タイムアウト、HTTP status 応答なし) / 再試行回数: 0 (アプリ側リトライなし) / 待機秒: 120.002s / 発生 route: `projects.manuals.analyze` → 背景ジョブ (generate ステップ、`AnalysisJob`)
- アプリ側の挙動は仕様通り: status は draft に自動復帰し、UI に「解析に失敗しました。時間をおいて再実行してください。」という分かりやすい alert が出た (H4/H10 的には問題なし、生スタックトレース非露出)。**これは環境ハザードであり UX バグとして計上しない**。
- 対応: 走行は継続 (シャード停止条件である「serve が落ちた/全 endpoint 500/DB 不通/worktree 消失」には該当しない、単発の外部 API タイムアウト)。再実行して S3 を続行する。

---

## 最終サマリ

- 実行ストーリー: S3 (アプリ中核ジャーニー) → S7 (認可境界 IDOR)。S3→S7 間は reseed していない (指示通り)。
- 画面カバレッジ: 走行 12 / 13 (capture.csrf-cookie のみ明示未確認、理由は上記)
- 操作カバレッジ: 実行 13 / 15 (projects.manuals.update の明示クリック実行と capture.takes.downloaded の自動DL挙動は未検証、理由は上記)
- findings: **Critical 0 / High 1 / Medium 0 / Low 1 / 要確認 0**
  - F-1 (High): AI 解析が現実的なサイズの SOP でタイムアウトし再現性高く失敗、provider 例外がリトライされない
  - F-2 (Low): 動画マニュアル削除後の flash が出ない
- S7 (IDOR/認可境界) は **finding ゼロ**: cross-org 404 (存在オラクル無し)、cross-cut adopt 404、project_member ロールの 403、protected keys の 422 (category 別名許容/category_id 直送拒否) を全て確認し、想定通り正しく機能していた。認可レイヤーは高品質。
- 環境ハザード: EH-1 (非停止、Anthropic API 接続タイムアウト 2 回、F-1 の根拠として finding 化済み)

## TODO 候補 (Critical/High のみ、app-todo-add 想定粒度)

### T-CAND-1: AI 解析 (analyze) の generate 段が現実的サイズの SOP で確実にタイムアウト失敗する (provider 例外の有界リトライが無い)
- 一行サマリ: `AnalysisPipeline::withBoundedRetry` は LLM 出力検証失敗のみリトライし、Anthropic API のタイムアウト等 provider/connection 例外は一切リトライしないため、数百 KB 程度の現実的な SOP PDF (リポジトリ同梱の reference サンプルそのもの) で AI 解析が再現性高く (2/2) 失敗する。
- 再現手順参照: shard-report.md F-1
- 阻害されたユーザージョブ: North Star の起点「SOP から AI がカット設計する」が実運用サイズの文書で機能しない。
- 改善アクション候補: provider/connection 例外 (特に timeout) も有界リトライ対象に含める / generate 段の実効タイムアウト延長 / 失敗理由の具体的なユーザー向け次アクション提示。
- 関連ファイル: `app/Services/Manual/AnalysisPipeline.php`, `app/Jobs/Manual/RunManualAnalysis.php`, `config/prism.php`

## 走行終了
- `playwright-cli close` 実施予定 (本レポート最終化後)。
- serve 停止・teardown は行わない (親の責務)。
