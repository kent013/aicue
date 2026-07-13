# bug-hunt report shard-1 (run 20260713-085818)
- 対象 URL: http://127.0.0.1:8011 (DB: bug_hunt_1)
- 担当ストーリー: S3 (アプリ中核ジャーニー), S7 (認可境界 IDOR)
- 実行ストーリー: S3 (中核ジャーニー、手順1-6は完走。手順7-9は環境ギャップで一部 skip)、
  S7 (認可境界、完走。ただし take/render 系は前提データ欠如で一部 skip)
- skip したステップ: S3 手順7 (撮影テイクの実成功アップロード。upload-url が env 起因 500)、
  S3 手順8-9 (プレビュー/レンダー成功、DL・再生。ffmpeg 不在で必ず失敗)、
  S7 の take/render-job 越境検証 (前提データが作れないため)。理由はいずれも F-00/F-00b/F-00c 参照

## 画面カバレッジ (S3)
- 走行: projects.show, projects.manuals.create, projects.manuals.show, projects.manuals.edit,
  projects.manuals.jobs.show(JSON poll), capture.home(→リダイレクト先で確認), capture.manuals.index,
  capture.manuals.show
- 未走行 (環境ギャップでブロック): projects.manuals.render-jobs.show,
  projects.manuals.render-jobs.playback, projects.manuals.download (render が env 起因で成功しないため
  到達データが作れない。download は直 URL で 404 のみ確認 = 未公開時の期待挙動)
- capture.csrf-cookie: 明示未確認 (SPA 内部で自動発行される種類のため個別操作なし、skip)

## 操作カバレッジ (S3)
- 実行 (成功): projects.manuals.store, projects.manuals.update(基本情報保存),
  projects.manuals.destroy, projects.manuals.source-documents.store,
  projects.manuals.analyze(実行はしたがサーバ側で env 起因の失敗), projects.manuals.scenario.update,
  projects.manuals.preview(実行はしたが env 起因の失敗), capture.takes.upload-url(実行はしたが
  env 起因の 500)
- skip (理由: bug-hunt 環境の外部依存が未提供のため到達不能):
  projects.manuals.render (preview 同様 ffmpeg 不在で必ず失敗するため実施しても新情報が得られない、
  F-00b で代表確認済み), capture.takes.store/update/destroy/adopt/downloaded,
  capture.manuals.sync (いずれも upload-url が 500 のため有効な take レコードを作成できず、
  前提データが用意できない)

## 環境ギャップ (S3 完走を阻害。F-00/F-00b/F-00c 参照)
1. LLM (Prism/Anthropic) 呼び出しが fake されておらず実 API 401 → AI 解析が必ず失敗 (F-00)
2. ffmpeg バイナリ未導入 → preview/render (動画合成) が必ず失敗 (F-00b)
3. S3 (object storage) disk の region 未設定 → capture.takes.upload-url が 500 (F-00c)
   これにより撮影テイクの作成自体ができず、S3 の後半 (撮影〜完成動画) と、それに依存する
   S7 の take/render-job 越境チェックの前提データが用意できなかった

## UI/UX 検証 (H11-H14)
- H13 (レスポンシブ): projects.show を mobile 375x667、projects.manuals.edit を tablet 768x1024 で
  確認。いずれも横スクロール・要素はみ出し・重なりなし (screenshots/H13-mobile-projects-show.png,
  screenshots/H13-tablet-manuals-edit.png)。desktop (1280x900) に復帰済み
- H12: manual 削除は確認ダイアログ (「削除する」/「キャンセル」) を経由し destructive 操作として
  適切。scenario 編集の「未保存の変更があります」ラベルで保存状態を可視化 (良好)
- H10 の逸脱: F-01 (stale alert 残留) で確認。それ以外の一覧反映 (作成→一覧表示、削除→一覧から消える)
  は正しく同期していた
- H11/H14: 目立った視覚破綻・a11y 欠落は未検出 (snapshot 上 role/name は概ね取得可能)

## S7: 認可境界 (IDOR) — 走行結果サマリ

### テスト体制
- 前提の ManualTestSeeder の free 組織が billing gate でブロックされる (F-00d) ため、
  seed 済みアカウントだけでは cross-org (org A/B) 検証の第二組織が用意できなかった。
  そのため「/register」から新規アカウント (org B: `orgb-bughunt1@example.com`, project 2) を作成し、
  実運用と同じ経路 (Stripe 未契約 = plan_code null) で billing gate を回避して代替した
  (この代替自体は正しく機能したので S7 の本体は完走できている)。
  - 組織 A = Standardプラン組織 (owner-standard@example.com, project 1, manual 1, cut 1, category 1)
  - 組織 B = 新規登録組織 (orgb-bughunt1@example.com, project 2, manual 3, category 2/3)
  - 組織 B の project_member (撮影者) ロール検証用に招待フローで
    `orgb-member-bughunt1@example.com` を作成 (招待受諾は register→email 認証後に自動消化される
    UX で、正しく完了した)

### 結果: 検出した認可バグは無し (良好)
以下すべて期待どおり (H9 IDOR は未検出):
- 読み取り (screens) の越境: `projects.show` / `projects.manuals.show` / `.edit` / `.jobs.show`
  (JSON) / `capture.manuals.show` / `projects.categories.index` を組織 B から組織 A の id で直叩き
  → 全て 404 (Blade エラーでも 403 でもない)
- 書き込み (operations) の越境: `manuals.update/destroy/scenario.update/analyze/render/preview/
  source-documents.store`、`categories.update/destroy/reorder`、`capture.takes.upload-url`、
  `capture.manuals.sync` を組織 B から組織 A の id に対して fetch → 全て 404
- 存在オラクル: `categories.reorder` に自組織 id + 他組織 id を混在させた payload → 422 で
  「並び順の指定がカテゴリ一覧と一致しません」(自組織のみの id 数不一致と同一メッセージ・同一
  ステータスで、他組織 id の存在有無による応答差分なし)
- protected keys (ProhibitsProtectedKeys): `manuals.store` に `project_id` / `created_by` /
  `category_id` を混入 → いずれも 422 (該当キー名を含む単一責務のエラー文言)。
  一方 `category` (エイリアス) は許容され 200 で正常に作成された (仕様どおり)
- ロール境界: project_member (撮影者) で `manuals.store/update/destroy/analyze/render`,
  `categories.store`, `manage/users` (画面直 URL) → 全て 403 (画面は「アクセスできません」の
  適切な 403 ページ、生エラーなし)。同ロールで撮影面 (`app/projects/2/manuals` 一覧) の閲覧は
  正常に許可された (編集者専用でない操作は可、の期待どおり)
- 隣接 ID 総当り (逸脱アイデア): `projects/{0..4,999}/manuals/{0..4,999}` の総当りで組織 B から
  200/403 に化ける組み合わせは無し (全 404)

### skip (理由必須: F-00/F-00b/F-00c の環境ギャップにより前提データが作れなかった)
- take (テイク) の越境: `capture.takes.update/destroy/adopt/downloaded` は 404 になることは
  upload-url の 404 (存在しない take への操作として) で代表確認したが、**実在する take を
  他組織に対して作れなかった**ため「実在するリソースへの越境」の厳密な検証はできていない
  (upload-url 自体が env 起因で 500 になるため take レコードが作成不能。F-00c 参照)
- cross-cut 採用 (cut X の take を cut Y の adopt に渡す) 検証: 同上の理由で take が無く未実施
- render-job (render-jobs.show/playback) の越境、署名 URL (download/playback) の manual/lang
  差し替え: render が成功しないため render_job レコードが作れず未実施 (F-00b 参照)
- これらは「アプリの認可ロジックが疑わしい」からではなく、**bug-hunt 環境の外部依存欠如により
  前提データを作れなかった**ことによる skip である点に注意 (F-00/F-00b/F-00c が解消されれば
  他シャード/次回走行で再検証可能)

### 総評
今回実走行できた範囲において、nested route の 404-first 解決・protected keys 拒否・
project スコープロールの 403 境界は一貫して堅牢だった。重大な IDOR は未検出。
ただし take/render 系統は env 制約により未検証のまま残っている点を次回走行の優先課題として
明記する。

## findings サマリ
- Critical 0 / High 2 (F-01, F-00d) / Medium 0 / Low 0 / 要確認 3 (F-00/F-00b/F-00c は環境ギャップ)
- S7 (認可境界): 実走行範囲で IDOR/authz バグは未検出 (良好)。take/render 系は環境ギャップで未検証

---

## F-00: [環境ギャップ・要確認] bug-hunt 環境で AI 解析 (analyze) が常に失敗する — LLM 呼び出しが fake されておらず実 Anthropic API に到達し 401 で落ちる
- severity: 要確認 (test_env。アプリバグではなく bug-hunt 環境の fake 基盤の適用範囲ギャップの可能性が高い)
- story/step: S3-4,5 (AI 解析トリガー以降、シナリオ生成〜撮影〜レンダーの全チェーンをブロック)
- 再現手順:
  1. http://127.0.0.1:8011 に owner-standard@example.com / password123 でログイン
  2. プロジェクト作成 → 動画マニュアル作成 → SOP (PDF) アップロード → 「AI 解析」実行
  3. 「解析を待機中」表示後、約 15 秒で失敗 → 通知「AI 解析に失敗しました バルブ閉止作業マニュアル:
     解析に失敗しました。時間をおいて再実行してください。」。manuals.show は status=下書き に戻る
     (この失敗時のフォールバック自体は S3 カードの期待どおり正しく動く)
- 期待: `TESTING_FAKE_EXTERNALS=true` (bughunt env) では LLM 呼び出しも fake され、解析が成功して
  シナリオ (Cut ツリー) が生成される想定 (でなければ S3 の核心である「AI がカット設計」以降が
  bug-hunt 環境で一切検証できない)
- 実際: `storage/logs/laravel.log` に Anthropic API への実リクエストが 401 (`x-api-key header` 認証エラー)
  で失敗した例外スタックが記録されている
  (`vendor/echolabsdev/prism/.../Anthropic.php` → `AnalysisPipeline.php:130` → `RunManualAnalysis` job)。
  コード確認: `app/Providers/FakeExternalsServiceProvider.php` は `TicketCheckoutGateway` /
  `SubscriptionCheckoutGateway` (課金) のみを fake 差し替えしており、LLM (Prism/Anthropic) の
  fake bind は存在しない。SKILL.md にも「fake 基盤の適用範囲はアプリ依存。未導入なら該当機能は
  fake されない」と明記されておりこれは既知の制約と推測されるが、AI-CUE の中核機能である AI 解析が
  bug-hunt 環境で構造的に検証不能というのは影響が大きいため要確認として記録する
- 阻害されたユーザージョブ: S3 (SOP → AI カット設計 → 撮影 → 完成動画) の中核ジャーニーのうち、
  ステップ 5 以降 (シナリオ編集・撮影・プレビュー・レンダー) が bug-hunt 環境では一切実走行できない
  (このシャードで S3 の後半を検証できず、S7 の「S3 で作った manual/cut/take」の前提データも作れない)
- 改善アクション候補: LLM (Prism/Anthropic) 呼び出し用の fake プロバイダを
  `FakeExternalsServiceProvider` に追加する (決め打ちシナリオ JSON を返す fake Prism response 等)。
  もしくは bug-hunt 環境限定で `ANTHROPIC_API_KEY` に有効なテスト用キーを注入する運用に変更する
- 証跡: screenshots/F-01-stale-error-after-upload.png (失敗後の画面), storage/logs/laravel.log
  (Anthropic 401 例外スタック, `AnalysisPipeline.php(130)` 経由)
- 推定原因: `app/Providers/FakeExternalsServiceProvider.php` に Prism/Anthropic の fake bind が無い
- 関連既知情報: 未確認。同種の記録が devnotes/TODO.md にあるか要確認

## F-00b: [環境ギャップ・要確認] bug-hunt 環境に ffmpeg バイナリが無く preview/render (動画合成) が必ず失敗する
- severity: 要確認 (test_env)
- story/step: S3-8 (プレビュー生成・完成動画レンダー)
- 再現手順:
  1. (F-00 と同じ manual、手動でシナリオに手順を 1 件追加して保存済み。撮影 (テイクアップロード) は未実施)
  2. manuals.show で「プレビュー生成」をクリック → 「プレビューを生成中 (0%)」表示 → 数秒後リロードすると
     「書き出しに失敗しました。時間をおいて再実行してください。」
- 期待: bug-hunt 環境で動画合成 (video_render チケット消費機能) が検証できる
- 実際: `storage/logs/laravel.log` に
  `ffmpeg failed (compose clip (手順1)): sh: 1: exec: ffmpeg: not found`
  (`App\Services\Render\FfmpegVideoComposer.php:208`)。ホスト shell で `which ffmpeg` も未検出
  (ffmpeg バイナリ自体が bug-hunt 実行環境に未インストール)
- 阻害されたユーザージョブ: S3 のステップ 8-9 (プレビュー・完成動画レンダー・再生・DL) が
  bug-hunt 環境で一切検証できない (F-00 の LLM 401 と合わせ、S3 後半の撮影後フローが丸ごとブロックされる)
- 改善アクション候補: bug-hunt 環境の provision (serve 起動スクリプト / コンテナイメージ) に
  ffmpeg のインストールを追加する。もしくは preview/render を fake/skip できる
  test-only レンダラを用意する
- 証跡: storage/logs/laravel.log ("ffmpeg: not found" 例外)
- 推定原因: bug-hunt 実行環境 (worktree/コンテナ) に ffmpeg 未導入
- 関連既知情報: 未確認

## F-00d: [test_env バグ・ManualTestSeeder] free プラン組織のテストアカウントが billing gate に全業務ルートをブロックされ、ログイン後 dashboard 以外ほぼ何もできない
- severity: High (test_env — ただし seeder のコード契約違反であり app 本体のバグではない可能性が高い。
  一方でこの契約 (`plan_code` セマンティクス) は本番の実装判断そのものであり検証の意義は高いため
  重めの severity で記録する)
- story/step: S7 (cross-org 検証の前提として owner-free@example.com を org B に使おうとして発覚。
  実際は S3/S1 等 free 系アカウントを使う全ストーリーに影響しうる)
- 再現手順:
  1. owner-free@example.com / password123 でログイン → dashboard は表示されるが
    「サブスクリプションのお支払いが確認できないため、一部機能を一時停止しています。」の
     callout が出る (F-00 発覚時に確認)
  2. 任意の業務 URL (例 `/projects/1`) に直 URL アクセス → `/billing` へ 302 redirect され
     「サブスクリプションのお支払いが確認できないため、ご利用を一時停止しています。」
  3. `/billing` を見ると「現在のプラン: Free」「チケット残高: 0 枚」
- 期待 (コードの docblock を仕様の正として採用):
  `app/Services/Billing/BillingAccess.php` のコメントに明記の契約 —
  「plan_code null (未契約) = fallback free プラン。支払い不要 tier としてアクセス許可」
  「plan_code は Stripe Price を持つ有償プランの契約時のみ StripeWebhookProcessor が set」。
  つまり free プランの組織は `plan_code = null` であるべきで、業務ルートを塞がれてはならない
- 実際: `database/seeders/ManualTestSeeder.php:124-129` の `createOrganization()` が
  **全プラン (free 含む) に対して無条件で** `$organization->forceFill(['plan_code' => $plan->code])`
  している。結果、free プラン組織も `plan_code = 'free'` (non-null) になり、
  `BillingAccess::hasActiveAccess()` は「有償プラン契約状態」の分岐に入り、
  対応する Stripe subscription が無い/inactive なので fail-closed で `false` を返す。
  → free プランの全テストアカウント (owner-free / admin-free / member-free@example.com) が
  実質使い物にならない (dashboard 以外ほぼ全業務ルートが `/billing` にリダイレクトされる)
- 阻害されたユーザージョブ: (1) bug-hunt で free 層の UX を検証できない。(2) このシャードでは
  S7 の「組織 A/B」に使える実在の有償組織が Standardプラン組織 1 つしか無いため
  (free 組織は使用不能)、**seed データだけでは cross-org (tenant) IDOR 検証の第二組織が作れない**
  (このシャードでは register 経由で新規組織を作って代替した。後述)
- 改善アクション候補: `ManualTestSeeder::createOrganization()` で free プランのみ
  `plan_code` を forceFill せず null のままにする (もしくは `BughuntBillingSeeder` 側で
  free 組織にも grantingStatus の a dummy subscription を用意する道もあるが、
  BillingAccess の契約上は前者が正しい)
- 証跡: 上記手順 2 のリダイレクト実測。コード: ManualTestSeeder.php L124-129, BillingAccess.php L19-31
- 推定原因: `ManualTestSeeder::createOrganization()` (forceFill plan_code を free にも適用)
- 関連既知情報: devnotes/20260712-0927-bugfix-billing-free-access (BillingAccess のコメントが
  参照している修正 devnote) が free 組織の契約を定めた回。ManualTestSeeder がこの回に追随できて
  いない可能性がある (要確認)

## F-00c: [環境ギャップ・要確認] bug-hunt 環境の S3 (object storage) disk 設定に region が無く capture.takes.upload-url が 500 になる
- severity: 要確認 (test_env。ただし S3 の「撮影」ジャーニー全体をブロックする重大な環境ギャップ)
- story/step: S3-7 (撮影面 capture.takes.upload-url)
- 再現手順:
  1. owner-standard@example.com でログイン、S3-1〜6 のとおり手動シナリオ作成済みの manual を用意
  2. `/app/projects/1/manuals/1` (capture.manuals.show) でカットをタップ → 録画不可環境のため
     ファイル選択にフォールバック → 動画ファイルを選択してアップロード
  3. `POST /app/projects/1/manuals/1/cuts/1/takes/upload-url` が 500 (console/network で検知)
  4. フロント側は握りつぶさず「未送信テイク 1 件 (2 KB) を端末に保持中」+「再送」ボタンで
     オフライン風リトライ UI に自動フォールバックする (ここは良い UX 耐性)
- 期待: 撮影テイクのアップロードが成功し、シナリオ画面にテイクが反映される
- 実際: `storage/logs/laravel.log`:
  `Missing required client configuration options: region: (string) ... "s3" service`
  (`Aws\ClientResolver` → `FilesystemManager::createS3Driver` → `TakeObjectStorage::client()` →
  `TakeUploadService::issue()` → `TakeUploadUrlController::store()`)。bug-hunt 環境の `s3` filesystem
  disk 設定に `region` (および恐らく endpoint/credentials) が正しく渡っていない
- 阻害されたユーザージョブ: 撮影 (テイクアップロード・採用・同期) が bug-hunt 環境で一切検証できない。
  F-00 (LLM) / F-00b (ffmpeg) と合わせ、S3 の「AI 解析 → シナリオ編集」より先 (撮影〜完成動画) が
  ほぼ全域ブロックされる。同じ前提を使う S7 (撮影者ロールの capture.takes.* 越境検証) にも影響し、
  実データでの検証ができない
- 改善アクション候補: bug-hunt 環境の provision (`.env.bughunt.local` / `scripts/bug-hunt-shard.sh`)
  に `AWS_DEFAULT_REGION` (または minio 等ローカル S3 互換サービスの region/endpoint) を設定する。
  F-00 (LLM fake) と合わせて bug-hunt 環境の provisioning チェックリストに追加することを推奨
- 証跡: storage/logs/laravel.log ("Missing required client configuration options: region")
- 推定原因: `.env.bughunt.local` の S3 disk 関連設定不足 (bug-hunt 環境 provisioning のギャップ)
- 関連既知情報: 未確認

## F-01: SOP アップロード成功後も「手順書をアップロードしてください。」エラーが残留表示される (H10)
- severity: High
- story/step: S3-3,4 (source-documents.store → analyze 直前)
- 再現手順:
  1. http://127.0.0.1:8011/login に owner-standard@example.com / password123 でログイン
  2. プロジェクト作成 → 動画マニュアル作成 (SOP 未添付) → manuals.show へ遷移
  3. 「AI 解析」ボタンをクリック (SOP 未添付のため 422) → シナリオ欄に赤字 alert
     「手順書をアップロードしてください。」が表示される (ここまでは正しい挙動)
  4. 「手順書 (SOP) をアップロード」から PDF を選択 → 「アップロード」ボタンをクリック
  5. トースト「手順書をアップロードしました」が出て、SOP セクションの案内文は
     「アップロード済みの手順書から AI がシナリオを生成できます。」に更新される (正しい)
  6. しかしシナリオ欄の alert「手順書をアップロードしてください。」がそのまま画面に残り続ける
     (ユーザーは SOP をアップロードしたのに、まだアップロードが必要だと誤認する)
- 期待: SOP アップロード成功時に、直前の analyze 失敗由来の alert は消える (または「解析できます」に更新される)
- 実際: アップロード成功後も stale な失敗メッセージが表示され続ける (F1e262 のまま)
- 阻害されたユーザージョブ: 「SOP をアップロードして AI 解析へ進む」という中核ジャーニーで、
  ユーザーは自分の操作が本当に成功したのか判断できず、再アップロードを試みたり離脱したりする恐れがある
- 改善アクション候補: source-documents.store 成功時にシナリオ欄の analyze エラー state を
  クリアする (楽観的リセット、またはページ全体を再フェッチして最新のバリデーション state を反映する)
- 証跡: screenshots/F-01-stale-error-after-upload.png, screenshots/s3-sop-file-selected.png
- 推定原因: 未調査。ただし手動リロード (F5) すると alert は消えるため、サーバー側の状態は
  正しく (SOP あり) 反映されている。原因はクライアント (Svelte/Inertia) 側の analyze エラー
  local state が source-documents.store 成功後もクリアされていないことと確定的 (SSR props には
  残っていない)
- 関連既知情報: 未確認 (devnotes/TODO.md 未参照)
- **追記**: 手動でシナリオ (手順) を追加して「シナリオを更新」を保存した後も同じ stale alert が
  再度表示されることを確認 (manuals.show へ戻ると「解析に失敗しました。時間をおいて再実行してください。」
  が再度出る)。scenario.update 成功後も同様に stale。source-documents.store 固有ではなく、
  manuals.show の analyze エラー表示が「関連する何らかの成功操作の後にクリアされない」という
  より広いパターンの可能性が高い


