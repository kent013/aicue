# bug-hunt report shard-0 (2026-07-12, run 20260712-191243, 再走行2回目)

- 対象: http://127.0.0.1:8010 (DB bug_hunt, users=8 起動時)
- 目的: 前回走行 (devnotes/20260712-075854-bug-hunt/) で F-05 (Stripe fake 未配線) / F-07 (Free 課金ゲート) / F-13
  (Filament アセット) によりブロックされていた S3 中核ジャーニー・S7 IDOR・S5 課金を深掘りする。T010/T015 で修正済みとの前提。
- 実行ストーリー: S3(中核ジャーニー、深掘り。analyze/preview/render は harness gap で完走不能だが手動シナリオ編集・撮影導線・保護キー等は完走)、S5(billing.index/purchase-tickets/checkout/portal、深掘り)、S7(cross-org IDOR・役割別403・保護キー422、深掘り)、S1(home/register/login/dashboard/email-verify、スモーク)、S6(confirm-password 回帰確認のみ)。S2/S4 は前回走行(20260712-075854)で完走済みのため今回は薄いスモークのみ(回帰確認は限定的)。
- skip したステップ:
  - S3 手順5(AI 解析結果のポーリング完了)・手順8後半(プレビュー/レンダの実完了・ダウンロード・再生の内容確認): 理由 F-01(queue worker 未起動で analyze/preview/render が `queued` のまま無限に停止)。ワークアラウンドとして手動シナリオ編集経由で `ready` 状態を作り、preview トリガーまでは実行し「詰みの先」を確認した。
  - S3 手順7 の実カメラ録画(録画→アップロード完了までの実動作): 理由 F-03(ヘッドレス環境ではカメラ権限が Permissions-Policy で拒否され、かつフォールバックが実行時に機能しない)。upload-url API を直接 fetch し形式要件(ULID/base64 checksum 等)は確認したが、実ファイルアップロード→テイク一覧反映までは未検証。
  - S5 手順3 後半(checkout 後の残高/プラン更新確認): 理由(環境ハザードでなく明示的な harness 設計)。`FakeTicketCheckoutGateway`/`FakeSubscriptionCheckoutGateway` は Stripe webhook 完了を一切シミュレートしない仕様(コードコメントで明記)のため、bughunt 環境では「checkout 開始・正しくリダイレクトされる」ところまでしか検証できない。
  - S2(招待受諾・ロール変更等)・S4(組織/プロジェクト/カテゴリ CRUD の UI操作)・S6(2FA有効化/プロフィール編集等の完全な操作)は今回は深掘りせず。前回走行(20260712-075854-bug-hunt)で完走・記録済みのため、今回は F-11(confirm-password 500)の回帰確認のみ実施(解消を確認)。

## 画面カバレッジ
- 走行(今回のみ、前回分と合算しない単独カウント): 16 / screens.md 総画面 55
- 走行した画面: home, login, register, dashboard, email/verify, email/verify/{id}/{hash}, projects.index, projects.create, projects.show, projects.manuals.create, projects.manuals.show, projects.manuals.edit, projects.manuals.render-jobs.playback(404確認), projects.manuals.download(404確認), billing.index, settings(smoke), recent-auth.confirm(旧confirm-password導線の回帰確認)
- 追加観察: `projects.manuals.jobs.show` と `projects.manuals.render-jobs.show` は実際には JsonResource 応答(XHRポーリング専用)であり、ブラウザ直navでも Inertia ページとして描画されない(生JSON表示)。screens.md 上は「画面」として掲載されているが、実態は非Inertia API に近い。インベントリ修正提案参照。
- 未走行(今回): capture.home, capture.csrf-cookie, capture.manuals.index(一覧へ戻るリンクの遷移先。導線確認のみで未クリック), pricing, purchase-tickets(screens.md未掲載), organizations.*系, manage.users.index, projects.categories.index, settings.security, password.confirm, recent-auth.status(前回走行でカバー済みのため今回は優先度を下げた)

## 操作カバレッジ
- 走行(今回のみ): 14 / operations.md 対象 60
- 実行した操作: register.store, projects.store(×2、owner-standard組織/新規登録組織), projects.manuals.store(×2)、projects.manuals.scenario.update(成功×2、409失敗×2=解析中ロック・楽観ロック競合)、projects.manuals.analyze(トリガーのみ、完了は harness gap で未確認)、projects.manuals.preview(トリガーのみ、同上)、billing.checkout系(purchase-tickets/checkout、billing/portal。いずれも正しくリダイレクトまで確認、完了はharness gapで未確認)、capture.takes.upload-url(直API、バリデーション形式確認)
- cross-org IDOR で確認した「越境拒否」操作(S7、直APIで確認、いずれも期待通り拒否): projects.manuals.update/destroy/analyze/render/scenario.update(cross-org 404 ×6)、projects.categories.store(cross-org 404)。role-based 403(member-standardロールで確認): projects.manuals.store/update/destroy/analyze/render, projects.categories.store, manage.users.index(GET)の計7操作すべて403。protected-key注入422確認: projects.categories.store(created_by混入)、projects.store(created_by混入)、projects.manuals.store(project_id混入、category_id直送)の計4パターン。
- 未実行/ブロック確認済み: capture.takes.store/update/destroy/adopt/downloaded, capture.manuals.sync(いずれもF-03のカメラフォールバック欠落によりUI経由のテイク作成ができず、cross-cut adopt IDOR等の深掘りが未達)。organizations.*、projects.categories.update/destroy/reorder、projects.items.*、projects.members.*は前回走行でカバー済みのため今回は未実行(回帰確認省略)。

## UI/UX 検証所見
- H11(視覚破綻): 目立った崩れなし。前回 F-01(`${APP_NAME}`未展開)は解消確認(タイトル/ロゴとも "AI-CUE" 正常表示)。
- H12(アフォーダンス/状態表現): member-standard(撮影者ロール)でプロジェクト詳細を開いた際、「新規作成」リンクや「管理メニュー」セクションが正しく非表示になっており、ロールに応じたUI出し分けは適切。一方 F-02(シナリオ保存409時)・F-01(analyze/preview実行中)はいずれも「進行中」or「未保存」の表示のまま更新が止まり、成功/失敗の区別がつかない状態がある。
- H13(レスポンシブ): mobile375(dashboard・purchase-tickets)/tablet768(projects.index空状態)で確認、いずれも横スクロール・要素はみ出しなし(desktop 1280に復帰済み)。
- H14(a11y基礎): 動画マニュアル作成画面の「手順書 (SOP・任意)」アップロードボタンが英語の "Choose File"(ブラウザネイティブ)のまま表示され、周囲が日本語UIの中で浮いている(Low、native file inputのラベルは通常CSS/JSで完全には置換できないため許容範囲だが横断的に確認推奨)。

## インベントリ修正提案
- `projects.manuals.jobs.show`・`projects.manuals.render-jobs.show` は実態が JsonResource 応答の XHR ポーリング専用エンドポイントであり、Inertia の「画面」として扱うのは screens.md 上ミスリーディング。`区分` 列などで「API(XHR専用)」と明記するか、operations.md 側(GETだが状態変化を伴わない特殊面)への注記を推奨。
- `purchase-tickets`(画面)・`purchase-tickets/checkout`(操作)が screens.md/operations.md 未掲載(前回走行でも同一指摘。再掲)。

## 前回 finding の再確認
- **F-01(${APP_NAME}未展開)**: 解消を確認。タイトル/ロゴとも "AI-CUE" 正常表示。
- **F-05(Stripe課金全操作500)**: 解消を確認。`purchase-tickets/checkout`・`billing/portal` とも 500 は再現せず、正しく `Inertia::location()`(409 + X-Inertia-Location、Inertia公式の外部リダイレクト規約)でfakeの中立返却URLへ遷移する。ただし fake gateway は webhook 完了を一切シミュレートしない設計のため、「チェックアウト完了→残高/プラン更新」のループ自体は本 harness では検証不能(製品バグではなく意図的な harness 設計、下記 F-06 参照)。
- **F-07(全アカウントでプロジェクト作成に到達不能)**: Standard プラン(有償・active subscription あり)および実際に新規登録したアカウント(plan_code=null の実 free tier)では解消を確認。ただし bughunt 専用テストアカウント `owner-free@example.com` 等(`ManualTestSeeder` が `plan_code='free'` を強制設定)に限り、別原因(F-04参照)で類似症状が残存。
- **F-11(confirm-password 500)**: 解消を確認。`/user/confirm-password` へ直接アクセスすると `/recent-auth/confirm` に正しくリダイレクトされ、500ではなく正常な本人確認フォームが表示される。
- **F-13(管理画面アセット全滅+ログイン失敗)**: 未確認(今回は S3/S5/S7 優先のため /admin を再訪していない。統合レポートで別途確認推奨)。
- **F-03/F-06(成功時フィードバック欠落)・F-08(ヘッダーナビ不統一)・F-10(リカバリコード再生成UIなし)・F-12(オーナー移譲UIなし)・F-14(manage.usersモバイル横スクロール)**: 今回未再訪(前回記録のまま、回帰確認せず)。

## findings サマリ(今回新規、severity降順)
- Critical: F-01(analyze/preview/render が harness gap で永久停止)、F-03(カメラ実行時失敗でファイル選択フォールバックに切り替わらず撮影が完全に詰む)、F-04(bughunt fixtureのplan_code不整合でFreeテストアカウントが締め出される。製品バグでなくharness fixtureバグと判定)
- High: F-02(シナリオ保存409時のフィードバック完全欠落。H7)
- Low: F-05(動画マニュアル関連画面のタイトル未設定。H14寄り)
- 要確認: なし(今回新規分)
- 前回finding再確認: F-01/F-05/F-07(標準プラン分)/F-11 は解消。F-07(Freeテストアカウント分)は別原因(F-04)で残存。F-13は未再確認。

---

## finding 詳細 (severity 降順、見つけ次第追記)

## F-01: AI 解析(analyze)・プレビュー生成・レンダの queue job が bughunt 環境で永久に `queued` のまま進まず、S3 中核ジャーニーの後半(シナリオ自動生成/プレビュー/完成動画)が検証不能
- severity: Critical (H2: 詰み。ただし根本原因は製品コードでなく bughunt harness のキューワーカー未起動の疑いが強いため、severity は「この bughunt 環境での検証結果」として記録し、製品バグと即断しない)
- story/step: S3-4/5 (projects.manuals.analyze → jobs.show ポーリング), S3-8 (projects.manuals.preview → render-jobs.show ポーリング)
- 再現手順:
  1. `owner-standard@example.com` / `password123` でログイン(チケット残高100)。プロジェクト作成→動画マニュアル作成(SOP=テキストファイル添付)→ `POST /projects/1/manuals/1/analyze` を実行(201 Created, 即座に返る)。
  2. `GET /projects/1/manuals/1/jobs/1` をポーリング → `{"status":"queued","manual_status":"analyzing"}` のまま **100 秒以上経過しても進捗が一切変化しない**(`step`/`progress` は常に `null`)。画面上も「解析を待機中」の進捗バーが回り続けるのみ。
  3. 別マニュアル(手動でシナリオを1手順追加し `ready` にしたもの)で `POST /projects/1/manuals/2/preview` を実行 → 同様に `render-jobs/1` が `{"status":"queued"}` のまま **40 秒以上**進捗なし。
- 期待: `TESTING_FAKE_EXTERNALS=true` の下で AI 解析/レンダが(fake 経由か実キュー処理かは問わず)有限時間内に完了または失敗する。
- 実際: ジョブが `queued` のまま無期限に停止する。
- 阻害されたユーザージョブ: AI 解析結果の確認・シナリオのプレビュー・完成動画のレンダ・ダウンロード・再生 (S3 手順5・8・9 全て) が本走行で検証不能。North Star フロー後半が今回も未到達。
- 改善アクション候補: (1) bughunt 環境の provisioning (`scripts/bug-hunt-shard.sh` serve 起動処理) に `php artisan queue:work database-analysis` / `database-render` / (`database-media` も使われていれば) の起動を追加する。(2) あるいは該当 Job クラスの `onConnection()` 指定を bughunt.local では無効化 (QUEUE_CONNECTION=sync に従わせる) する分岐を検討する。
- 証跡: screenshots/F-01-preview-stuck-queued.png、network: `POST /projects/1/manuals/1/analyze => 201`→`GET .../jobs/1 => 200` ×十数回(status不変)、`POST /projects/1/manuals/2/preview => 201`→`GET .../render-jobs/1 => 200` ×4回(status不変)
- 推定原因: 確認済み(コードリーディングで特定)。`app/Jobs/Manual/RunManualAnalysis.php` (`$this->onConnection('database-analysis')`) と `app/Jobs/Manual/RunManualRender.php` (`$this->onConnection('database-render')`) が明示的に専用 queue connection を指定しており、`.env.bughunt.local` の `QUEUE_CONNECTION=sync`(コメント曰く「非同期ジョブを同期実行(探索の決定論性)」という設計意図)を bypass する。`config/queue.php` のコメントには「運用契約: worker は `php artisan queue:work database-analysis` を必須登録」とあるが、`scripts/bug-hunt-shard.sh` の serve 起動処理には `queue:work` の起動が見当たらない(`grep -n "queue:work" scripts/bug-hunt-shard.sh` で該当なし)。
- 関連既知情報: 前回走行 (devnotes/20260712-075854-bug-hunt) の F-05/F-07 (Stripe 課金ゲート) はいずれも解消を確認(本レポート下部「前回 finding の再確認」参照)。**新たな別原因でS3後半が再びブロックされている**点に注意。dev 環境(:8000系)や本番/ステージングでも同じ運用契約の worker 登録漏れがあれば、ユーザー向けに全く同じ「進捗0%のまま無限に回り続ける」体験になりうる(30分の recoverStale cron まで気づけない)ため、harness 固有の懸念に留めず本番運用のチェックリストとしても要確認。

## F-02: シナリオ保存(scenario.update)が 409 (楽観ロック競合/解析中ロック)で失敗しても、画面上に一切のエラーフィードバックが表示されない
- severity: High (H7: 結果フィードバックの完全欠落 + データロスリスク。story の明示的期待「409 で差分再取得を促される」に反する)
- story/step: S3-6 (projects.manuals.scenario.update)
- 再現手順 (パターンA: 解析中ロック):
  1. `owner-standard@example.com` でログイン。マニュアルで AI 解析をトリガー(status=analyzing、F-01 の通りジョブは進まない)。
  2. `/projects/1/manuals/1/edit` でシナリオに手順を1つ追加し「シナリオを更新」を押す。
  3. `PUT /projects/1/manuals/1/scenario => 409 Conflict`。console に `Failed to load resource: 409` のみ出るが、画面には何も表示されず「未保存の変更があります」の文言がそのまま残る(保存できていないことに気づく手段が console 以外にない)。
  - 再現手順 (パターンB: 楽観ロック競合、2 タブ):
  1. 同一マニュアル編集画面を 2 タブで開く(tab0/tab1、いずれも version=旧)。
  2. tab1 で手順のシーンを編集し保存 → `200 OK`(version 進む)。
  3. tab0(古い version のまま)で別内容に編集し保存 → `PUT .../scenario => 409 Conflict`。console にエラーが出るのみで、画面上は「未保存の変更があります」のまま変化なし。差分再取得を促すダイアログ/バナー等は一切表示されない。
- 期待 (S3-6 カード記載): 「別タブで先に保存すると 409 で差分再取得を促される」。
- 実際: 409 発生時、ユーザーに何の説明もない。ユーザーは保存が成功したと誤認したまま画面を離れる可能性が高く、編集内容が失われるリスクがある。
- 阻害されたユーザージョブ: 複数人での同時編集、または解析中の誤操作から、ユーザーが自分の編集が失われたことに気づけず、シナリオ編集作業が予告なく失われる。
- 改善アクション候補: `PUT scenario` の 409 レスポンスをフロントで捕捉し、(1) エラーバナー/トーストで理由を明示、(2) 楽観ロック競合の場合は最新版を再取得して差分をマージ/警告する UI を実装する。
- 証跡: screenshots/F-02-scenario-409-optimistic-lock-no-feedback.png、network: `PUT /projects/1/manuals/1/scenario => 409`(解析中ロック)、`PUT /projects/1/manuals/2/scenario => 200` → `PUT /projects/1/manuals/2/scenario => 409`(楽観ロック競合、2タブ)、console: `Failed to load resource: 409 (Conflict)` ×2
- 推定原因: 未調査(5分調査枠内ではフロント側の 409 ハンドラの有無を特定できず。おそらく catch 節でエラー表示処理が実装されていない)
- 関連既知情報: 前回走行の F-03/F-06 (成功時のフィードバック欠落) と同系統のパターン(本アプリで「操作結果のフィードバックが表示されない」という systemic な傾向がある可能性。統合レポートで横断的に確認を推奨)。

## F-03: カメラ取得が実行時に失敗(権限拒否・デバイスなし)しても、実装済みのファイル選択フォールバックに切り替わらず撮影が完全に詰む
- severity: Critical (H2: 詰み。撮影(テイクアップロード)ができないと動画マニュアル制作が完成しない。North Star フローの根幹)
- story/step: S3-7 (capture.manuals.show → capture.takes.upload-url/store)、逸脱アイデア「カメラ不可環境ではファイル選択にフォールバック」に対応
- 再現手順:
  1. `owner-standard@example.com` で `/app/projects/1/manuals/2` (撮影 PWA) を開き、カットをタップして撮影パネルを開く。
  2. 「録画開始」ボタンを押す → console に `Permissions policy violation: microphone/camera is not allowed` が出て、画面には赤字で「カメラを利用できません。ブラウザのカメラ許可を確認してください。」と表示される。
  3. この状態でファイル選択(テイクを動画ファイルからアップロードする)手段が画面上に一切存在しない(`input[type=file]` が DOM に 0 件、"アップロード"/"ファイル" のテキストも一切なし)。「テイクはまだありません。撮影してください。」のまま完全に詰む。
- 期待 (S3 逸脱アイデア): 「カメラ不可環境ではファイル選択にフォールバック」。
- 実際: フォールバック用の `CaptureFileFallback.svelte` コンポーネント自体はコードベースに実装されているが、表示条件 `canRecord`(`resources/js/pages/Capture/Show.svelte`)が `supportsMediaRecorder()`(`resources/js/lib/capture/camera.ts`)という **API の"存在"のみをチェックする静的フラグ**で決まっており、実際に `getUserMedia()` を呼び出した結果(権限拒否・デバイスなし等の実行時エラー)を見ていない。そのため、ブラウザが MediaRecorder/getUserMedia の API 自体は持っているが実際にはカメラを使えない環境(権限ポリシーで拒否・カメラハードウェアなし・ユーザーが権限を拒否 等)では `canRecord=true` のまま `CameraRecorder` 側が選ばれ続け、ファイル選択フォールバックへ実行時に切り替わる経路が存在しない。
- 阻害されたユーザージョブ: カメラ権限を拒否した・カメラのないデバイスを使う・企業ポリシー等でカメラ権限ポリシーが制限されている撮影者は、テイクを一切アップロードできず動画マニュアルの制作が完全に止まる。
- 改善アクション候補: `CameraRecorder` 内で `getUserMedia()` 呼び出しが失敗(`NotAllowedError`/`NotFoundError`等)した場合に、親コンポーネント (`Show.svelte`) の状態を更新して `CaptureFileFallback` に動的に切り替える実装に変更する(`canRecord` を静的な起動時フラグでなく、実行時エラーで上書き可能な reactive state にする)。
- 証跡: screenshots/capture-camera-denied.png、console: `Permissions policy violation: microphone is not allowed`, `Permissions policy violation: camera is not allowed`。コード: `resources/js/pages/Capture/Show.svelte:36`(`const canRecord = ... supportsMediaRecorder()`)、`resources/js/lib/capture/camera.ts:5-13`(API存在チェックのみ)、`resources/js/components/features/capture/CaptureFileFallback.svelte`(未到達なフォールバック実装)
- 推定原因: 確認済み(コードリーディング)。上記参照。
- 関連既知情報: なし。bughunt 環境のヘッドレスブラウザ固有の制約(Permissions-Policy でカメラ拒否)で顕在化したが、コードロジック自体の欠陥(API存在チェックと実行時許可チェックの混同)であるため、実ブラウザでカメラ権限を拒否した一般ユーザーでも同様に再現すると判断し、環境ハザードでなく製品 finding として記録する。

## F-04: bughunt 環境の `ManualTestSeeder` が Free プラン組織の `plan_code` を `'free'`(非 null)で強制設定しており、`BillingAccess` の「plan_code=null は支払い不要」契約に反して Free プランテストアカウント(owner-free 等)が `/projects` 等から締め出される(前回 F-07 と同一症状が別原因で再発)
- severity: Critical、ただし**製品バグではなく bughunt harness のテスト fixture バグ**と判定(severity は「この bughunt 環境でのテスト実行可能性への影響」として記録)
- story/step: S3 前提(Free アカウントでの中核ジャーニー検証)、S7 前提(cross-org IDOR 用の2組織目の確保)
- 再現手順:
  1. `owner-free@example.com` / `password123` でログイン(組織: 「Free プラン組織」、チケット残高0)。
  2. `/projects`・`/projects/create`・`/app/*` など `require-active-subscription` ミドルウェア配下のルートに直アクセス → いずれも `/billing` へリダイレクトされる。ただし今回は(前回 F-07 と異なり)`/billing` に **「サブスクリプションのお支払いが確認できないため、ご利用を一時停止しています。お支払い方法をご確認ください。」というアラートが表示される**ようになっていた(改善を確認)。しかしこのメッセージ自体が実態と食い違う(後述)。
  3. 一方、**このセッションのまま新規登録 (`/register`) で全く新しいアカウントを作成**すると、そのアカウント(plan_code=null の実 free tier)は `/projects` に即座にアクセスでき、プロジェクト作成も正常に完了する(F-07 の根本原因である `BillingAccess::hasActiveAccess()` 自体の実装は既に修正済みであることを確認。`app/Services/Billing/BillingAccess.php` のコメントに `devnotes/20260712-0927-bugfix-billing-free-access` への参照あり)。
- 期待: `BillingAccess::hasActiveAccess()` の契約(`app/Services/Billing/BillingAccess.php` コメント)により「`organizations.plan_code === null` = 未契約 = 支払い不要 tier としてアクセス許可」。Free プランは Stripe Price を持たない(`PlanSeeder` コメント)ため `plan_code` は null のまま維持されるべき。
- 実際: `database/seeders/ManualTestSeeder.php` の `createOrganization()` が全プラン(Free 含む)の組織に対し無条件で `$organization->forceFill(['plan_code' => $plan->code])->save();` を実行しており、Free 組織にも文字列 `'free'`(非 null)が書き込まれる。これにより `BillingAccess::hasActiveAccess()` は「有償プラン契約状態」の分岐に落ち、`subscription('default')` の存在(active/trialing)を要求する。`BughuntBillingSeeder` は意図的に Free 組織へは何も付与しない設計(「★ free 組織には何も付与しない」とコメントに明記、課金なし経路を bug-hunt 内に温存する目的)ため、Free 組織の `hasActiveAccess()` は常に false となり、`owner-free`/`admin-free`/`member-free` の全アカウントが `/projects`・`/app` 等に到達できない。
- 阻害されたユーザージョブ: (1) `owner-free@example.com` 系の全アカウントを使った Free プラン体験ジャーニー(S3)の bug-hunt 探索が今回も実質不能。(2) S7 の cross-org IDOR 検証で「もう1つの動く組織」として Free 組織アカウントを使う設計だった場合、それも不能(本走行では代わりに新規登録アカウントを作成して代替した)。
- 改善アクション候補: `ManualTestSeeder::createOrganization()` で `plan.code === 'free'`(または `currentPrice(Base) === null` の無償プラン)の場合は `plan_code` の forceFill をスキップし null のまま残す(`BillingAccess` の契約と整合させる)。
- 証跡: なし(screenshot省略、コードリーディングで確定)。再現は上記手順で誰でも可能。network: `GET /projects => 302 => /billing`(owner-free)、billing page に新規アラート文言確認。
- 推定原因: 確認済み(コードリーディング)。`database/seeders/ManualTestSeeder.php:122-128`(`createOrganization`)。
- 関連既知情報: 前回走行の F-07(Critical: 全アカウントでプロジェクト作成に到達できない)は**解消を確認**(下記「前回 finding の再確認」参照)。ただし Free プラン専用テストアカウントに限り、別原因(本 finding)で類似症状が残存している。統合レポートでは F-07 を「解消」、本 F-04 を「新規・別原因(harnessのみ)」として区別して記録することを推奨。
- 副次観察: `/billing` の警告文言「サブスクリプションのお支払いが確認できないため、ご利用を一時停止しています」は、一度も Stripe 契約したことのない Free ユーザーに対しては意味的に不正確(「支払いが確認できない」は失敗した決済を想起させるが、実際は「そもそも契約自体が無い」状態)。本 finding の再現手順(fixture 修正)で解消される想定だが、仮に製品として「Free プランでも `plan_code` が非 null になるケースがある」設計になった場合は、このメッセージ自体も要見直し(要確認扱いとし、severity なし)。

## F-05: 新規動画マニュアル作成画面(`projects.manuals.create`)の `<title>` に画面固有のタイトルが付与されない(他の作成/一覧画面は「〜| AI-CUE」形式なのに対し、ここだけ "AI-CUE" のみ)
- severity: Low (H14寄り: ブラウザタブ/履歴での判別性低下。操作阻害はない)
- story/step: S3-2 (projects.manuals.create)
- 再現手順: `owner-standard@example.com` で `/projects/1/manuals/create` を開く。ブラウザタブタイトルが `AI-CUE` のみで、他の画面(例: `/projects/create` → 「プロジェクトの作成 | AI-CUE」、`/projects/1` → 「(プロジェクト名) | AI-CUE」)のような画面固有のタイトルが付いていない。`/projects/1/manuals/1`(show)・`/projects/1/manuals/1/edit`・`/app/projects/1/manuals/2` も同様に "AI-CUE" のみ。
- 期待: 他画面と同様に「動画マニュアルの作成 | AI-CUE」等、画面固有のタイトルが表示される。
- 実際: 動画マニュアル関連画面(create/show/edit/capture.show)のタイトルが軒並み `AI-CUE` のみで統一感がない。
- 阻害されたユーザージョブ: 複数タブ/ブックマークでの識別性が下がる程度で、致命的ではない。
- 改善アクション候補: Inertia の `<Head title="...">`(または相当)がマニュアル関連ページのみ設定漏れの可能性。横断的に確認。
- 証跡: playwright snapshot の `Page Title: AI-CUE` (複数画面で確認)
- 推定原因: 未調査
- 関連既知情報: なし

## F-06(harness gap、severityなし): bughunt 環境の Stripe fake gateway は checkout 完了(webhook)を一切シミュレートしないため、S5 手順3後半・S3 のチケット消費整合(手順5)を UI 経由で完走できない
- severity: なし(製品バグでなく、意図的な harness 設計。コード上に明記されたコメントで確認)
- story/step: S5-3(billing.checkout の「戻ると残高/プランが更新され」の部分)
- 再現手順: `owner-standard@example.com` で `/purchase-tickets` から「購入手続きへ (Stripe)」を押す → `POST /purchase-tickets/checkout` が 302(Inertia の `Inertia::location()` 規約により実際には 409+`X-Inertia-Location` ヘッダとして Inertia クライアントに解釈される。本物のエラーではない)で `fake_external=stripe` マーカー付きの中立URLへ正しくリダイレクトされる。しかしチケット残高は購入前後で変化しない(100枚のまま)。
- 期待(S5カード): 「Stripe fake の checkout へ → 戻ると残高/プランが更新され」。
- 実際: `app/Services/Billing/Fakes/FakeTicketCheckoutGateway.php` のコード コメントに「決済・チケット付与・状態変更は一切行わない(課金状態の正本は BughuntBillingSeeder)」「アプリはこの query を一切解釈しない(purchased 偽装なし)」と明記されている通り、fake gateway は checkout の「開始・リダイレクト」までしか模倣せず、Stripe の実 webhook(`checkout.session.completed` 等)による完了処理は一切トリガーされない。本番/実 Stripe 環境なら webhook 経由でチケットが付与されるはずだが、bughunt 環境にはその webhook 配信経路(や手動トリガー手段)が存在しない。
- 阻害されたユーザージョブ: bug-hunt 走行者が「購入→残高反映→AI解析実行」という S3⇔S5 連動ジャーニーを UI 操作だけで完走できない(製品のユーザー体験には影響しない。あくまで探索テストの到達性の問題)。
- 改善アクション候補: (1) bughunt 環境向けに Stripe webhook を模擬的に発火させる CLI コマンド or wrapper サブコマンド(例: `tmp/bug-hunt/shard-0-cmd.sh` に `simulate-webhook` 等)を用意し、bug-hunt shard worker が届出済み wrapper 経由で「支払い完了」を模擬できるようにする。(2) もしくは `FakeTicketCheckoutGateway`/`FakeSubscriptionCheckoutGateway` 自体を「即時完了」型(帰還時に `purchased=1`+`session_id` を付与し、対応する `TicketCheckoutSession` を `Completed` にした上でチケット付与まで行う)に変更する設計変更を検討(ただし「Stripe webhook 経由が正本」という設計思想と矛盾しないよう要検討)。
- 証跡: コードリーディングで確認(`app/Services/Billing/Fakes/FakeTicketCheckoutGateway.php` の docblock)。screenshots/purchase-checkout-409.png(残高100枚のまま変化なしの状態)。
- 推定原因: 確認済み(意図的な設計)。
- 関連既知情報: F-01(analyze/renderのqueue worker gap)と同種の「bughunt fake基盤の適用範囲ギャップ」。統合レポートでは両者を「harness fake基盤の拡張候補」として一括りにまとめることを推奨。

---

## Critical/High TODO 候補(app-todo-add 向け要約)

### TODO-1(Critical, F-01): bughunt harness に AI解析/レンダ用の queue worker が登録されておらず、S3後半(解析結果反映・プレビュー・完成動画レンダ・DL・再生)が今回も検証不能
- 一行サマリ: `RunManualAnalysis`/`RunManualRender` が専用 queue connection(`database-analysis`/`database-render`)を明示指定しているが、bughunt の serve 起動処理にその worker(`php artisan queue:work database-analysis` 等)が登録されておらず、ジョブが `queued` のまま無期限に停止する。
- 再現手順: F-01参照。任意アカウントで analyze/preview/render をトリガー→ `jobs/{id}`・`render-jobs/{id}` を100秒以上ポーリングしても進捗ゼロ。
- 阻害されたユーザージョブ: AI解析結果の確認・シナリオプレビュー・完成動画のレンダ/DL/再生。North Starフロー後半の探索的テストが2回連続で未達。
- 改善アクション候補: `scripts/bug-hunt-shard.sh` の serve 起動処理に `queue:work database-analysis`/`database-render`(`database-media`があれば同様)の起動を追加する。
- 関連ファイル: `scripts/bug-hunt-shard.sh`、`app/Jobs/Manual/RunManualAnalysis.php`、`app/Jobs/Manual/RunManualRender.php`、`config/queue.php`

### TODO-2(Critical, F-03): カメラ取得の実行時失敗(権限拒否・デバイスなし)でファイル選択フォールバックに切り替わらず撮影が完全に詰む
- 一行サマリ: `resources/js/pages/Capture/Show.svelte` の `canRecord` 判定が `MediaRecorder`/`getUserMedia` API の「存在」のみをチェックする静的フラグであり、実際の `getUserMedia()` 呼び出し失敗(権限拒否・デバイスなし等)を検知して実装済みの `CaptureFileFallback.svelte` に切り替える経路が存在しない。
- 再現手順: F-03参照。撮影PWAでカメラ権限を拒否した状態でカットを開き「録画開始」→「カメラを利用できません」エラー表示後、ファイル選択の代替手段が一切ない。
- 阻害されたユーザージョブ: カメラ権限を拒否した/カメラのないデバイスの撮影者はテイクを一切アップロードできず、動画マニュアル制作が完全に止まる。
- 改善アクション候補: `CameraRecorder` の `getUserMedia()` 失敗を親コンポーネントに伝播し、`canRecord` を実行時に上書きできる reactive state にして `CaptureFileFallback` へ動的フォールバックする。
- 関連ファイル: `resources/js/pages/Capture/Show.svelte`、`resources/js/lib/capture/camera.ts`、`resources/js/components/features/capture/CameraRecorder.svelte`、`resources/js/components/features/capture/CaptureFileFallback.svelte`

### TODO-3(Critical、harness fixtureバグ、F-04): `ManualTestSeeder` が Free プラン組織にも `plan_code`(非null)を強制設定し、`BillingAccess` の契約(plan_code=null=支払い不要tier)に反して bughunt の Free テストアカウントが `/projects` から締め出される
- 一行サマリ: `database/seeders/ManualTestSeeder.php` の `createOrganization()` が全プラン(Freeを含む)に無条件で `plan_code` を forceFill しており、Free組織は本来 `plan_code=null` であるべき契約(`app/Services/Billing/BillingAccess.php`)に反する。実際の新規登録(自然な `plan_code=null`)では問題なし=製品バグではない。
- 再現手順: F-04参照。`owner-free@example.com` でログイン→ `/projects` が `/billing` へリダイレクト。新規登録アカウントでは問題なし。
- 阻害されたユーザージョブ: bug-hunt探索において Free プラン専用テストアカウント群(owner-free/admin-free/member-free)を使った検証、および cross-org IDOR 用の「2つ目の動く組織」としての利用が今回も不能。
- 改善アクション候補: `ManualTestSeeder::createOrganization()` で無償プラン(`currentPrice(Base) === null`)の場合は `plan_code` の forceFill をスキップする。
- 関連ファイル: `database/seeders/ManualTestSeeder.php`(122-128行目)、`app/Services/Billing/BillingAccess.php`

### TODO-4(High, F-02): シナリオ保存(scenario.update)が409(楽観ロック競合/解析中ロック)で失敗してもUIに一切のフィードバックがなく、データロスリスクがある
- 一行サマリ: `PUT .../scenario` が409を返した際、フロントに捕捉処理がなく「未保存の変更があります」の表示のまま何も起きず、ユーザーは保存失敗に気づけない(story記載の「409で差分再取得を促される」という期待に反する)。
- 再現手順: F-02参照。解析中ロック/2タブでの楽観ロック競合、いずれも同一症状。
- 阻害されたユーザージョブ: 複数人での同時編集や解析中の誤操作で編集内容が予告なく失われる。
- 改善アクション候補: 409レスポンスをフロントで捕捉しエラーバナー表示、楽観ロック競合時は最新版の再取得・差分表示を実装。
- 関連ファイル: シナリオ編集フロントコンポーネント(未特定。`resources/js/pages` 配下のマニュアル編集画面)、`app/Http/Controllers` のscenario更新コントローラ

---

## 生成レポートの実パス
`/workspace/devnotes/20260712-191243-bug-hunt/shard-0/shard-report.md`
