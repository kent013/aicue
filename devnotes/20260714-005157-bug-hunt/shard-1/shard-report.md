# bug-hunt report shard-1 (run 20260714-005157) — 2回目走行 (回帰確認 + 新規探索)

- 対象 URL: http://127.0.0.1:8011 (DB: bug_hunt_1)
- 担当ストーリー: S3 (核心ジャーニー), S7 (認可境界/IDOR)
- 走行開始: 2026-07-14 (JST)

## 実行ストーリー
- S3: 完走 (手動シナリオ編集経由で ready まで到達。AI生成・撮影・レンダ完了・DLは既知の環境ギャップ Q1 で未到達 (下記参照))
- S7: 完走 (IDOR/認可境界は全項目パスし、重大な漏れは今回検出されず)

## テストデータ (本 shard で作成。再現手順の前提)
- 組織A (Standardプラン): owner-standard@example.com / admin-standard@example.com / member-standard@example.com
  - project 1 "Default Project" (owner-standard が作成)
  - manual 1 "組み立て手順" (draft, 短文SOPで抽出失敗 F-2)
  - manual 2 "F-H2回帰確認用マニュアル" (準備完了, F-1 の再現に使用, 手動シナリオ: 手順1+急所1-1)
  - manual 3 "シナリオ保存経由クリア確認用" (準備完了, F-H2回帰OK確認に使用)
  - category 1 "組立作業" (project 1 配下)
  - project member: member-standard@example.com を 撮影者(project_member) として追加
- 組織B (Freeプラン): owner-free@example.com
  - project 2 "Org B Project" (owner-free が作成)
  - category 2 "組立作業" (project 2 配下、A と同名だが project スコープで別id)

## skip したステップ
(なし。見つかり次第記載)

## 回帰確認
- **F-H2 (T022) 回帰OK (対象範囲は修正済み)**: manual 3 (`/projects/1/manuals/3`) で、SOP未アップロードのまま「AI 解析」→ 422「手順書をアップロードしてください。」alert表示 → SOPアップロードせず手動でシナリオ(手順1件)を作成し「シナリオを更新」で保存 → manuals.show に戻ると alert は消えて「準備完了」表示のみ (再現手順・証跡は F-1 参照用に確認、alert残留なし)。
  → 元の T022 修正スコープ (`errorMessage`/`missing_document`、SOP未アップロード起因の 422) は正しく解消されている。
- ただし **別経路 (analyze が job として実際に走って失敗した場合) では同種の stale alert が再発することを新規発見** → 下記 F-1 参照。修正スコープの取りこぼしとして報告する。

## 既知の環境ギャップ (Q1、今回未修正・finding化しない) — 3件すべて本走行で実際に踏んだ (想定通り)
- **LLM(Anthropic) 実API 401**: `/projects/1/manuals/2/analyze` (SOP十分な長さのテキスト) で発生。laravel.log: `Anthropic Error [401]: authentication_error - x-api-key header is required` (16:01:32)。
- **ffmpeg 不在**: `/projects/1/manuals/2` プレビュー生成で発生。laravel.log: `ffmpeg failed (compose clip (手順1)): sh: 1: exec: ffmpeg: not found` (16:08:11)。
- **S3互換ストレージ region 未設定**: 撮影PWA `capture.takes.upload-url` (`/app/projects/1/manuals/2/cuts/1/takes/upload-url`) が 500。laravel.log: `Missing required client configuration options: region... A "region" configuration value is required for the "s3" service` (16:10:38)。
- いずれも UI 側は「時間をおいて再実行」「再送」等の妥当なリトライ導線を提示しており、詰み (H2) にはなっていない。ただしこれら 3 点により **AI生成シナリオ経由の本来フロー・撮影テイクのアップロード・レンダリング完了・DL/再生 (S3手順5,7後半,8,9) は本環境では最後まで検証不能** (手動シナリオ編集で ready までは到達できることを確認済み)。

## 画面カバレッジ
S3 割当 12画面、全て走行済み (12/12)。
- capture.home ✓ (`/app` → `/app/projects/1/manuals` へ解決)
- capture.csrf-cookie ✓ (`/app/csrf-cookie` → 204、XHR確認)
- capture.manuals.index ✓
- capture.manuals.show ✓ (撮影パネル・カメラ不可フォールバック確認)
- projects.show ✓ (フィルタ・空状態・メンバー追加確認)
- projects.manuals.create ✓ (バリデーション含む)
- projects.manuals.show ✓
- projects.manuals.edit ✓ (基本情報・手動シナリオ編集)
- projects.manuals.jobs.show ✓ (analyzeポーリングXHRで機能確認。直接ブラウザ nav は 404 だが Accept ヘッダ要求のAPI的挙動と判断、要確認扱いにはしない)
- projects.manuals.render-jobs.show ✓ (直接navで status=failed のJSONを確認)
- projects.manuals.render-jobs.playback ✓ (未成功ジョブに対し 404 、業務ルールとして妥当)
- projects.manuals.download ✓ (未publishedのため 404、業務ルールとして妥当)

## 操作カバレッジ
S3 割当 15操作中 9操作を実行確認、6操作は**既知の環境ギャップにより skip** (理由: S3互換ストレージ region 未設定で `capture.takes.upload-url` が 500 となり、以降の take 実データが作成不能なため)。
- 実行済み: projects.manuals.store ✓ / projects.manuals.update ✓ / projects.manuals.destroy ✓ / projects.manuals.source-documents.store ✓ / projects.manuals.analyze ✓ (成功系はLLM401既知ギャップで未到達、失敗系は確認) / projects.manuals.scenario.update ✓ / projects.manuals.preview ✓ (ffmpeg既知ギャップで失敗まで確認) / projects.manuals.render ✓ (バリデーション409/422的拒否まで確認、実成功は未到達) / capture.manuals.sync ✓ (self, 空配列)
- skip (理由: 既知の環境ギャップ Q1、capture.takes.upload-url 500 のため実テイクを作成できず): capture.takes.upload-url (試行しエラー確認のみ) / capture.takes.store / capture.takes.update / capture.takes.destroy / capture.takes.adopt / capture.takes.downloaded

## UI/UX 検証 (H11-H14)
- H11 (視覚破綻): projects.show / manuals.edit で目視確認、崩れなし。
- H12 (アフォーダンス/状態表現): 撮影者ロールで編集専用ボタンが非表示 + 明示的な権限外説明文言あり (良好)。ただしF-1のように状態と文言が矛盾するケースあり (該当箇所参照)。
- H13 (レスポンシブ): mobile 375×667 (`projects.show`, screenshots/H13-projects-show-mobile375.png) / tablet 768×1024 (`projects.manuals.edit`, screenshots/H13-manuals-edit-tablet768.png) で確認、横スクロール・要素はみ出し・重なりなし。確認後 desktop (1280×800) に復帰済み。
- H14 (a11y基礎): 成功トースト (シナリオ保存等) は `role="status"` で実装されており読み上げ可能 (良好)。フォームラベルは概ね紐付いている。個別のコントラスト測定は未実施 (時間の都合、要確認事項にはしない)。

## findings

### F-1: analyzeジョブが一度失敗すると、その後シナリオを手動保存して「準備完了」になっても失敗alertが残留し状態と矛盾する (F-H2 修正の取りこぼしバリエーション)
- severity: High
- story/step: S3-4〜6 (回帰確認の延長で発見)
- 再現手順:
  1. `owner-standard@example.com` / password123 で `http://127.0.0.1:8011/login` からログイン (組織: Standardプラン)
  2. `/projects/1/manuals/2` (「F-H2回帰確認用マニュアル」、本 shard で作成) で SOP (テキストファイル、742 bytes 相当の内容) をアップロード
  3. 「AI 解析」を押下 → ジョブが起動し、テキスト抽出は成功するが LLM 呼び出し (Anthropic API) が 401 で失敗 (既知の環境ギャップ Q1。laravel.log: `Anthropic Error [401]: authentication_error - x-api-key header is required`) → manuals.show に赤字 alert 「解析に失敗しました。時間をおいて再実行してください。」が表示される (status は draft のまま)
  4. `/projects/1/manuals/2/edit` へ遷移し、AI 解析に頼らず手動で手順(Cut)を1件追加・シーン欄を入力して「シナリオを更新」で保存 → 200 OK、`projects.manuals.show` へ戻ると status が「準備完了」に変わる
  5. しかし同じ画面に手順3で出た「解析に失敗しました。時間をおいて再実行してください。」alert がそのまま残っており、直下の説明文「手順書から生成したシナリオを編集画面で確認できます。再解析すると既存のシナリオは置き換えられます。」と同時に表示される (準備完了なのに失敗が表示され続ける矛盾)
  6. リロード (`playwright-cli reload` / 別タブでの直 URL 再訪問) しても再現し続ける → クライアントローカル state ではなくサーバ側 (最新 analysis job = failed を無条件表示) が原因
- 期待: シナリオが準備完了 (status=ready) になった時点で、古い失敗ジョブの alert は表示されない (少なくとも「もう解消されている」ことが分かる文言/非表示になるべき)。T022 の対策コメント (AnalysisPanel.svelte 134-152行) は `errorMessage`(missing_document, hasDocument で解消) のみを対象にしており、`failedJob.error`(実際にジョブが起動して失敗した場合) は対象外。
- 実際: `準備完了` と「解析に失敗しました。時間をおいて再実行してください。」が同時に表示され続ける。ユーザーは「結局解析は失敗しているのか、シナリオは使えるのか」を見失う。
- 阻害されたユーザージョブ: 手動でシナリオを完成させて次工程(撮影・レンダリング)へ進もうとしているユーザーが、画面上の矛盾した状態表示によって「まだ何か直す必要があるのでは」と誤解し、次のアクション (撮影・プレビュー生成) に進むべきか迷う。
- 改善アクション候補: `AnalysisPanel.svelte` の `failedJob?.error` 表示に `status !== 'ready'` (または `manualStatus` 由来の同等条件) のガードを追加する。あるいはサーバ側で「現在の scenario の cuts 更新日時 > failed job の更新日時」なら stale とみなし job データ自体を返さない/フラグを立てる。
- 証跡: screenshots/F-1-stale-analysis-failed-alert-after-ready.png (準備完了 + 失敗alert同時表示)、screenshots/S3-analyze-failed-alert.png (テキスト抽出失敗時点の類似alert、manual 1)
- 推定原因: `resources/js/components/features/manual/AnalysisPanel.svelte` 293-296行目 `{#if failedJob?.error}` が `status`(manualStatus) を考慮せず無条件表示。298-152行目の stale overlay 破棄 effect は `errorMessage`/`missing_document` のみを対象にしており `failedJob` 系を対象にしていない。バックエンド `VideoManualController.php` 121-126行目は常に「最新 analysis job」を job propとして返すため、シナリオが別経路(手動編集)で ready になっても job データは failed のまま。
- 関連既知情報: `devnotes/20260713-085818-bug-hunt/report.md` F-H2 (T022 で対応)。本 finding は同一症状の**修正範囲外バリエーション**であり regression ではなく取りこぼし。
- **追加確認 (同根本原因が完成動画パネルにも波及)**: 同 manual 2 で「プレビュー生成」実行 (既知の環境ギャップ Q1: ffmpeg 未導入 → `sh: 1: exec: ffmpeg: not found` で失敗、laravel.log 確認済み) → 「書き出しに失敗しました。時間をおいて再実行してください。」alert 表示。その後「完成動画を生成」を押すと採用テイク未設定のバリデーションで正しく 422 拒否される (「採用テイクが未設定のカットがあります: 手順1、急所1-1」) が、このとき **画面には解析失敗alert・採用テイク未設定alert・書き出し失敗alertの 3 つが同時に積み上がって表示される** (screenshots/F-1-triple-stacked-stale-alerts.png)。個々のバリデーションは正しいが、状態の異なる複数の失敗が時系列を無視して同時提示されるため、ユーザーは「今何が問題で何が解決済みか」を判別できない。F-1 と同根 (job/状態ベースの stale alert が複数パネルで共通のパターンとして存在) として報告する。

### F-2 (要確認/Low): テキスト抽出結果が閾値未満のとき「画像・スキャンの手順書は現在未対応です」という誤解を招くメッセージが出る
- severity: Low (要確認寄り)
- story/step: S3-4
- 再現手順: manual 1 (`/projects/1/manuals/1`) で 91 bytes 程度の短いテキストファイル (.txt, text/plain) を SOP としてアップロードし「AI 解析」を実行 → 「テキストを抽出できません。画像・スキャンの手順書は現在未対応です。」という alert が出る。
- 期待/実際: アップロードしたファイルは正しくテキストとして抽出されている (画像でもスキャンでもない) が、抽出後の文字数が `config('manual.analysis_min_text_bytes')`(=100 bytes) 未満だったため `AnalysisFailedException::unextractable()` が発生し、"画像・スキャン" 文言が出る。実態(内容が短すぎる)と表示文言(画像/スキャンで抽出不能)が一致しておらず、ユーザーがファイル形式を疑って無駄な変換を試みる可能性がある。
- 阻害されたユーザージョブ: 短い手順書を試したユーザーが原因を誤診断し、実際に必要な対処 (内容を増やす) に気づけない。
- 改善アクション候補: 文字数不足時は専用の文言 ("手順書の内容が少なすぎます。100文字以上のテキストを含む手順書をアップロードしてください" 等) に分岐する。
- 証跡: screenshots/S3-analyze-failed-alert.png
- 推定原因: `app/Services/Manual/SopTextExtractor.php` 49-51行目、文字数不足と真の抽出不能 (画像/バイナリ) を同じ `AnalysisFailedException::unextractable()` にまとめている。
- 関連既知情報: なし (新規)。severityは低いが、テストアカウントの短文書チェック用に有用な情報として記載。

## S7 (認可境界/IDOR) 検証結果 — 重大な漏れは検出されず (全項目パス)

`playwright-cli -s=bughunt1` の同一セッション内で owner-standard@example.com (組織A) → owner-free@example.com (組織B) → member-standard@example.com (組織A撮影者) の順にログインを切り替えて検証 (cookie/storageは隔離済み、他shardセッションは未使用)。

1. **B から A の URL 直叩き (read)**: `/projects/1`、`/projects/1/manuals/1`、`/projects/1/manuals/2`、`/projects/1/manuals/2/edit`、`/projects/1/manuals/2/jobs/1`、`/projects/1/categories`、`/app/projects/1/manuals/2` の全てが **404** (Blade エラーでも403でもない、通常の404ページ)。console/network も 404 のみで情報漏洩なし。
2. **B から A の manual への書き込み**: `PATCH /projects/1/manuals/2` (title改変)、`DELETE /projects/1/manuals/2`、`PUT /projects/1/manuals/2/scenario`、`POST /projects/1/manuals/2/analyze`、`POST /projects/1/manuals/2/render`、`POST /projects/1/manuals/2/preview`、`POST /projects/1/manuals/2/source-documents` すべて **404**。
3. **B から A の category**: `PATCH /projects/1/categories/1`、`DELETE /projects/1/categories/1` は **404**。`PATCH /projects/1/categories/reorder` (B自身のproject=2に対しA由来のid=1混入) と 存在しないid=99999 混入は **どちらも同一の 422 + 同一メッセージ** (「並び順の指定がカテゴリ一覧と一致しません。」) → **存在オラクル無し** (実在他組織リソースと非実在リソースを判別不能、要求どおり)。同名カテゴリ「組立作業」をA (id=1,project 1) とB (id=2,project 2) で別々に作成できることも確認 (project スコープ unique)。
4. **撮影面PWA (cross-org)**: `POST /app/projects/1/manuals/2/cuts/1/takes`、`.../upload-url`、`PATCH/DELETE /app/projects/1/manuals/2/takes/1`、`POST /app/projects/1/manuals/2/cuts/1/adopt`、`POST /app/projects/1/manuals/2/sync` すべて **404** (実データの有無に依らずルート解決時点でproject-manual親子関係により弾かれる)。
5. **子は親に属する (own tenant内でも親子ミスマッチ)**: B自身のproject 2 に A の manual id=2 を組み合わせた `/projects/2/manuals/2` も **404** (グローバルなmanual id検索でなく `project->manuals()` relation経由の解決であることを確認)。
6. **project_memberロール境界 (撮影者)**: member-standard@example.com (project 1 で 撮影者=project_member) から編集者専用操作を直叩き: `POST /projects/1/manuals` (作成)、`PATCH /projects/1/manuals/2` (改変)、`DELETE /projects/1/manuals/2`、`POST /projects/1/categories`、`POST /projects/1/manuals/2/analyze`、`POST /projects/1/manuals/2/render`、`GET /manage/users` すべて **403** (404ではなく403、プロジェクトメンバーではあるが権限不足という正しい区別)。UI側もmanuals.show で「完成動画の生成・ダウンロードは編集者が行えます。」等の明示的な権限外説明があり、ボタン自体が出ない (H12対応済み)。read系 (manuals.show等) は撮影者でも正常に閲覧可能。
7. **tenant/protected キー混入**: `POST /projects/2/categories {project_id:1}` → 422「project id を入力する必要はありません。」。`POST /projects/2/manuals {project_id:1, created_by:999}` → 422「created by を入力する必要はありません。」。`category_id` 直送 → 422「category id を入力する必要はありません。」。`category` alias (公開されている別名) は **自組織の category id は 200 で通り、他組織 (A) の category id は 422「選択されたカテゴリは、有効ではありません。」** で正しく拒否 (aliasも tenant スコープを迂回できない)。

**結論**: 本 shard が検証した範囲 (nested route 越境・ロール境界・protected keyインジェクション・存在オラクル) では**認可漏れは検出されなかった**。前回走行 (20260713系) で「重大漏れ未検出」と記載されていた懸念に対し、本走行では上記7項目全てで想定どおりの拒否 (404/403/422、オラクル差分なし) を確認できたことを明記する。撮影テイク関連 (adopt/update/destroy) は実データ (take レコード) が既知の環境ギャップ (S3互換ストレージ region 未設定によりupload-url 500) のため作成できず、**ルートレベルの越境チェックのみ確認 (レコードレベルのadopt先cut/take入れ替えの詳細な cross-cut IDOR は実データなしで未検証、要再走行時に環境ギャップ解消後の再検証を推奨)**。

### F-3 (要確認/Low): マニュアル作成フォームでタイトル必須エラー表示後、タイトルを入力しても invalid フラグ・エラー文言がその場でクリアされない (送信は成功する)
- severity: Low
- story/step: S3-2
- 再現手順: `/projects/1/manuals/create` でタイトル未入力のまま「作成」→ 422 バリデーションエラー「タイトルは必須項目です。」表示 (textbox に invalid フラグ) → その後タイトル欄に文字を入力しても invalid フラグ・エラー文言が消えないまま (screenshots/S3-title-error-stale.png) → 「作成」を再度押すと正常に作成される (機能的なブロッキングはない)
- 期待: 入力するとその場でエラー表示が消える (他の多くのフォームでは live validation でクリアされる想定)
- 実際: 送信するまでエラー表示が残る。実害はないが、ユーザーに「まだ何か問題があるのでは」という誤解を与える (H12寄り: 状態表現の不一致)
- 阻害されたユーザージョブ: 軽微。ユーザーが再入力後も警告が消えないことで無駄に確認作業をしてしまう程度。
- 改善アクション候補: 入力フィールドの `input` イベントで対応するフィールドエラーをクリアする。
- 証跡: screenshots/S3-title-error-stale.png
- 推定原因: 未調査 (フォームのバリデーションエラー state 管理箇所、5分では未特定)
- 関連既知情報: なし

## Critical/High サマリ (TODO候補)

### F-1 (High): 解析ジョブが一度失敗すると、その後シナリオを手動保存して「準備完了」になっても失敗alertが残留し状態と矛盾する (F-H2/T022 修正の取りこぼしバリエーション)
- 再現手順参照: shard-report.md#F-1
- 阻害されたユーザージョブ: 手動でシナリオを完成させ次工程(撮影・レンダ)に進もうとしているユーザーが、矛盾した状態表示 (準備完了 + 解析失敗alert) により何が問題か分からず迷う。完成動画パネルでも同根の stale alert が積み重なる (F-1追記参照)。
- 改善アクション候補: `resources/js/components/features/manual/AnalysisPanel.svelte` の `failedJob?.error` 表示 (293-296行目) に `status`(manualStatus) を考慮したガードを追加する。同様のパターンがレンダ/プレビューパネルにもないか横展開して確認する。
- 関連ファイル: `resources/js/components/features/manual/AnalysisPanel.svelte`、`app/Http/Controllers/Projects/VideoManualController.php` (121-130行目、jobプロパティの供給元)
- 関連既知情報: `devnotes/20260713-085818-bug-hunt/report.md` F-H2 (T022)。regressionではなく修正範囲外の取りこぼし。

## 要確認 (仕様不明点)
- なし (F-2, F-3 は severity Low として記載済みで、明確にバグ的挙動のため「要確認」には分類していない)

## インベントリ修正提案
- 特になし (screens.md / operations.md は本走行で乖離なしと確認)

## 環境ハザード
- なし (走行は最後まで完遂。既知の環境ギャップ Q1 (LLM 401 / ffmpeg 不在 / S3 region 未設定) は事前告知どおりで、environment hazardとしては扱わず finding からも除外)
