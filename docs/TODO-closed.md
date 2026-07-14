# TODO — Closed / Obsoleted

<!--
運用規約:
- Open / Conditional の TODO は TODO.md を参照
- このファイルへの行追加は /app-todo-close スキル経由で行う
  - close:    TODO.md Open → Closed (完了日を記入。タイトル列に実装サマリーを追記してよい)
  - obsolete: TODO.md Open/Conditional → Obsoleted (廃止日と理由を記入)
- ID は再利用しない (採番は全テーブルを通した最大 ID + 1)
- テーブルが空になってもヘッダー行は残す
-->

Open リストは [TODO.md](TODO.md) を参照。

## Closed

| ID | タイトル | テーマ | 完了日 |
|---|---|---|---|
| T001 | AI-CUE ドメイン基盤(Category/VideoManual)。Category/VideoManual CRUD + Tier B スキーマ先取り (Enum/migration/Model/Factory/Service/Policy/route/UI/テスト一式)。cross-org {project} の FormRequest DB ルール存在オラクルを project.in-current-org middleware で封じる修正込み | backend | 2026-07-10 23:46 |
| T002 | シナリオ編集(document一括保存・楽観ロック)。scenario document 一括 PUT (expected_version 楽観ロック 409 + 行別 422 + protected キー拒否)、ScenarioService reconcile (id 保全・並べ替え・削除 cascade)、ScenarioEditor (Svelte 5 作業コピー編集・409 復帰は onSuccess で reseed・419 自動リトライ)。Codex impl-review Critical なし | backend | 2026-07-11 01:32 |
| T003 | AI解析(SOP→作業分解→シナリオ生成→Cut materialize)。SOP アップロード (内容 sniff + 追記型 immutable)・解析トリガー (analyze 冪等 409 / 残高 402)・RunManualAnalysis + AnalysisPipeline (extract→decompose→generate→materialize、チケット reserve→terminal tx で materialize+commit+succeeded 原子化)・LLM 3 プロンプト YAML + 有界リトライ・stale 回復 cron・AnalysisPanel ポーリング UI。Codex impl-review Critical なし (C1 は前提誤認で反論、W1-W3 修正済み) | backend | 2026-07-11 03:37 |
| T004 | 撮影PWA(presignedアップロード+テイク+容量Quota)。presigned PUT + 署名チケット (reserve→verifying→completed の 2 フェーズ、Organization 行ロックで TOCTOU 防止)・テイク登録 (client_take_id 冪等 + CAS + 重複解決)・容量 Quota (used+pending 集計、pending→used 読み取り順で競合を安全側=拒否側に固定)・stale 予約 sweeper cron・撮影 PWA UI。Codex impl-review Critical なし (occupiedBytes 読み取り順 Warning 修正済み) | backend | 2026-07-11 05:41 |
| T005 | レンダ(採用テイク合成→完成mp4・ffmpeg+チケット)。RenderJob + trigger/preview (in-flight 冪等 409・尺上限 422・残高 402・org preview 上限直列化)・RunManualRender + RenderPipeline (startJob reserve→buildManifest version 固定→ffmpeg 合成→terminal tx で complete+commit+succeeded 原子化)・FfmpegVideoComposer (TakeVideo/TakeStill/Placeholder、字幕は ASS ファイル経由で filtergraph 非注入)・stale 回復/出力 reconcile cron・世代交代削除・RenderPanel ポーリング UI。Codex impl-review Critical なし (Still 経路テスト網羅 Warning 修正済み) | backend | 2026-07-11 07:42 |
| T006 | 管理メニュー(管理者ユーザー管理+カテゴリ管理画面)。/manage 配下 (Users/Categories) + AdminMenuNav・AdminConsoleRole 3 値遷移コマンド (admin/editor/shooter) + MemberRoleState 遷移規約 (最後の owner 保護含む)・招待への project_role 追加 (Default Project 自動割当・受諾時 project 消失は未割当へ縮退)・ManageRouteAuthGuardTest / ProjectMemberPivotWritePathTest の deny-by-default Architecture テスト・Organizations/Settings と Projects/Show からの機能移設。Codex impl-review Critical なし (Enum メッセージキー Warning は反証+回帰テスト固定) | frontend | 2026-07-11 11:46 |
| T007 | LP(トップ)+料金表+チケットリチャージ(aigenba移植)。ゲスト向け LP (Welcome) + 公開料金表 (/pricing、Plan/PlanPrice 真実源 + quota 能力値) + チケットスポット購入 (Stripe Checkout、attempt_token 冪等 / live pending dedup / webhook 冪等付与 / 金額・通貨・customer・org_ref fail-closed 照合) + Standard 価格改定 ¥4,980。Codex impl-review Critical なし (success_url 帰還は session_id 照合の fail-closed バナーに修正、dedup (org,user) 粒度は設計意図として反証) | frontend | 2026-07-11 21:44 |
| T008 | アプリ内通知センター(ジョブ完了/招待/残高)。database チャネル通知 (uuid PK + organization_id 列 + payload DTO 4 種: 解析完了/レンダ完了/招待受信/残高低下)・ベル (shared props 未読数 + ヘッダー dropdown)・一覧 (Inertia ページネーション)・既読/全既読/open 遷移解決 (manual 削除・org 不一致は案内に縮退)。存在オラクル封じ: 自分宛以外 404 + route whereUuid + service 層 Str::isUuid ガード (非UUID の pgsql 22P02 → 500 を封止)。Codex impl-review Critical なし (organization_id index は YAGNI で見送り記録) | backend | 2026-07-12 00:21 |
| T009 | ダッシュボード(進行中ジョブ/最近のマニュアル/残高)。ログイン後着地点の Dashboard (DashboardService 3 クエリ固定集計 + DTO 5 種): 進行中ジョブ (analyzing/rendering manual × queued/running job、progress 0-100 clamp)・最近のマニュアル 5 件・撮影対象 (残カット数 + PWA deep link)・残高/容量カード (storage_usage_percent 0-100 clamp)・ロール別導線 (owner/admin/editor/shooter)・CurrentOrganizationResolver 自己修復。Codex impl-review Critical なし (PWA リンク prefix 混在は仕様として反証、percent 下限 clamp / failed job 契約テスト / 空 manuals 早期 return を修正) | frontend | 2026-07-12 01:44 |
| T010 | Free課金ゲート矛盾の解消(F-07: サイレントリダイレクト)。BillingAccess を billing entitlement 判定へ書き換え (Free=plan_code null は許可・有償契約中のみ active/trialing を要求、fail-closed 維持)・遮断理由の明示 (billing redirect に error flash + JSON 402 文言統一)・ダッシュボード callout 整合 (has_billing_access リネーム + 文言/CTA 更新)・plan_code 不変条件の明文化 (StripeWebhookProcessor / PlanSeeder / template-divergence D9)・F-07 再現テストファースト (createOrganizationWithOwner 既定 Free 化 + contractPaidPlan ヘルパ)。Codex impl-review Round 1 APPROVED (Critical なし、Warning 2 件は見送り理由記録) | backend | 2026-07-12 13:27 |
| T011 | confirm-password 直アクセス500の修正(F-11)。GET /user/confirm-password が ConfirmPasswordViewResponse 未 bind で BindingResolutionException 500 になる問題を、Fortify::confirmPasswordView の recent-auth.confirm への 302 誘導で解消 (救済 redirect のみで password.confirm middleware 互換は未提供と明記、config/fortify.php に注意書き)・RecentAuthTest に再現/回帰テスト追加。Codex 実装レビュー Suggestion 対応済み | backend | 2026-07-12 13:32 |
| T012 | コピー崩れの修正(F-01 APP_NAME未展開 / F-02 未翻訳キー)。bughunt env の APP_NAME 自己参照を実値 "AI-CUE" に解消 + env example 自己参照/前方参照禁止 invariant (EnvExampleInvariantTest)・lang/ja/validation.php attributes の全域補完 (Svelte フォーム label 準拠) + ValidationAttributeCoverageTest (全 FormRequest rules() キー + inline validation の attributes 登録を deny-by-default で強制)・組織作成 validate の StoreOrganizationRequest 切り出し + Store/UpdateProjectRequest attributes() で語彙ズレ解消・表示文言の再現 Feature テスト 4 本。Codex impl-review Round 1 Critical/Warning なし | frontend | 2026-07-12 13:37 |
| T013 | UX整備(F-03/F-06 feedback欠落・F-08 ナビ不統一・F-14 モバイル横スクロール)。F-03 認証メール再送の success flash (VerificationNotificationSentResponse contract bind + wantsJson/202 の Fortify 既定互換契約テスト)・F-06 パスワードリセットリンク送信の flash キー status→success 統一・F-08 ヘッダーナビ統一 (設定/ログアウトを AppLayout 常設化、Dashboard の page-local 実装を削除)・F-14 manage.users メンバー/招待行のモバイル縦積み + flex-wrap レスポンシブ対応。Codex impl-review Round 1 APPROVED (Suggestion 対応済み) | frontend | 2026-07-12 13:42 |
| T014 | 欠落UIの追加(F-10 リカバリコード再生成 / F-12 オーナー移譲)。F-10 Settings/Security にリカバリコード表示/再生成 UI (recovery-codes 系 route へ recent-auth step-up 配線 + RecentAuthRouteTest inventory 登録 + TwoFactorRecoveryCodesStepUpTest)・F-12 Organizations/Settings にオーナー移譲 UI (FortifyServiceProvider 拡張含む)・Svelte コンポーネントテスト (SettingsSecurity / OrganizationsSettings) 追加。レビュー指摘対応済み | frontend | 2026-07-12 13:47 |
| T015 | bug-hunt基盤整備(F-05 Stripe fake配線・F-13 Filamentアセット・seeder subscription)。fake externals capability flag (config/testing.php + ProductionEnvGuard の本番拒否)・サブスク checkout/portal の gateway 抽象化 (SubscriptionCheckoutGateway + ExternalBillingRedirect DTO)・FakeExternalsServiceProvider (flag + 環境 allowlist の fail-secure 二軸 bind)・BughuntBillingSeeder (有料プラン組織のみ active subscription + 初期チケット 100、DB 名 regex を DetectsBughuntDatabase trait に SSOT 集約)・AdminUserSeeder の bughunt.local 対応・provision への Filament アセット publish (composer.lock version marker で冪等)。Codex impl-review Round 1 Critical/Warning なし | test | 2026-07-12 13:53 |
| T016 | シナリオ保存失敗フィードバックの知覚性改善 + 動画マニュアルtitle供給 (bug-hunt F-02/F-05)。保存失敗を SaveFailure union へ再構成し、失敗アラートを操作点(シナリオを更新ボタン)直上へ移設 + focus(preventScroll)→scrollIntoView で知覚可能化。403 分岐追加で権限エラーを固定文言明示(内部状態を漏らさない)。保存ロジック・409応答契約は無変更。動画マニュアル show/edit/撮影show に setPrivateTitle で固有 title 供給、create は config/seo.php 静的登録。Vitest 新規5ケース + ManualPageTitleTest。Codex impl-review Round 1 APPROVED (Critical/Warning なし、Suggestion 2件は見送り記録) | frontend | 2026-07-12 22:16 |
| T017 | 撮影カメラ実行時失敗のファイルフォールバック到達 (bug-hunt F-03)。camera.ts に CameraUnavailableReason union + classifyGetUserMediaError(恒久/一時/unknown分類)。CameraRecorder に onCameraUnavailable 必須 prop、恒久失敗は親へ委譲・一時失敗のみローカル表示、開始処理再入ガード、MediaRecorder 構築/start() 失敗時は stream 解放してフォールバックへ倒す(詰みを作らない §10.8-3)。Show.svelte で実行時 file fallback + reason別 notice 切替。Vitest 3ファイル(分類/親通知/分岐表示/enqueue引き渡し/成功契約/再入)。Codex impl-review Round 2 APPROVED (Round1 Warning 2件対応済み) | frontend | 2026-07-12 22:26 |
| T018 | bug-hunt環境の専用queue worker起動/停止 (bug-hunt F-01)。専用 connection(database-analysis/render/media)のジョブが QUEUE_CONNECTION=sync をバイパスして jobs テーブルに積まれるのに provision が worker を起動せず永久 queued 滞留する問題を解消。worker 共通ヘルパ(worker_alive の /proc cmdline 照合・start_shard_workers = setsid + queue:listen + 起動時 pid==pgid 検証 + 失敗ロールバック・stop_shard_workers = TERM→group消滅待ち→KILL→再確認、所有確認不能は pidfile 保持+rc=1)、provision 起動配線、teardown 再構成(worker停止をserve前・停止失敗shardのdropdb抑止・非ゼロ終了)、keepdb-check の worker 生存確認、self-test [y] (drift PHP実評価/構造/stop機能 y6a-d) を追加。Codex impl-review Round 1 APPROVED、self-test 全pass。実機 provisioning は未実施(impl-notes.md に運用手順明記) | test | 2026-07-12 22:36 |
| T019 | 組織ナビ&組織スイッチャー導線の追加。組織設定/請求/招待の恒常ナビ+組織切替UI | frontend | 2026-07-13 19:21 |
| T020 | Freeプラン組織の課金ゲート誤締め出し修正。Seederのplan_code=free是正でfree無償許可(誤締め出し解消)。PlanSeederPriceInvariant/SeededFreePlanBillingAccess/ManualTestSeeder テスト追加 | infrastructure | 2026-07-13 19:26 |
| T021 | 新規登録時のチケット10枚付与。登録完了(CreateNewUser)で無料チケット10枚を付与。TicketLedgerService に signup grant + ticket_ledger_entries への unique index migration で二重付与防止、StripeWebhookProcessor 冪等性連携。RegistrationTest/TicketGrantTest/SignupGrantUniqueIndexInvariantTest 追加 | backend | 2026-07-13 19:30 |
| T022 | manuals画面の残留エラーalert解消。成功操作後にstaleなエラーalertをクリア | frontend | 2026-07-13 19:32 |
| T023 | 2FA無効化/再生成に再認証(recent-auth)必須化。2FA disable/recovery-codes再生成にrecent-auth(パスワード再確認)を要求 | backend | 2026-07-13 19:35 |
| T024 | パスワード変更時に他デバイスのセッション失効 (bug-hunt F-H4)。パスワード変更成功時に現在セッション以外を失効させる | backend | 2026-07-13 19:37 |
| T025 | 唯一オーナーのアカウント削除ガード (bug-hunt F-H5)。唯一オーナー削除を警告/ブロックしオーナー移譲要求 | backend | 2026-07-13 19:40 |
| T026 | 保存成功フィードバック統一と二重トースト解消。profile/password保存に成功トースト+二重発火解消 | frontend | 2026-07-13 19:41 |
| T027 | homeヘッダーのモバイルレスポンシブ化。375px幅でハンバーガーメニュー化 | frontend | 2026-07-13 19:44 |
| T028 | プロジェクト個別メンバー管理UIの追加。members.store/destroyを呼ぶUI追加 | frontend | 2026-07-13 19:46 |
| T029 | 未設定画面のブラウザタブtitle追加。config/seo.php app_titlesに6ルート追加 | backend | 2026-07-13 19:49 |
| T030 | 招待経由登録の組織未所属+特典誤付与修正。招待token登録で参加のみ・grant無しを保証 | backend | 2026-07-14 03:40 |
| T031 | メールアドレス変更のrecent-auth保護+旧アドレス通知。profile更新にrecent-auth+旧メール変更通知 | backend | 2026-07-14 03:41 |
| T032 | manuals画面のstale alert/メッセージ/バリデーション整理。alertのstate整合+SOP文言+タイトル検証クリア | frontend | 2026-07-14 03:43 |
| T033 | メンバーのロール変更が無言で破棄される問題の修正。ロール変更拒否をUIに正しく反映 | frontend | 2026-07-14 03:44 |
| T034 | notifications画面のブラウザタブtitle追加。app_titlesにnotifications追加 | backend | 2026-07-14 03:45 |
| T035 | bughunt実行時環境へのLLM(Prism)応答fake配線。FakeにPrism応答配線しLLM401解消 | test | 2026-07-14 03:47 |
| T036 | bug-hunt real-llmモード(既定)とfake-llm/real-storageオプション。LLMを既定でreal接続しfake-llmをopt-in化 | infrastructure | 2026-07-14 04:46 |
| T038 | bug-hunt環境のテイク動画storageのfake配線(実S3非依存化)。take/render動画storageをfake配線し500解消 | infrastructure | 2026-07-14 12:51 |
| T039 | dev/bughunt/CI環境へのffmpeg導入(動画レンダー疎通)。Dockerfileにffmpeg追加しrender疎通 | infrastructure | 2026-07-14 12:54 |
| T037 | 撮影画面(capture.manuals.show)のモバイル/タブレット横overflow修正。grid-cols-1化+min-w-0+shooting_point truncate構造化 | frontend | 2026-07-14 13:36 |
| T041 | purchase-tickets の入力エラーが有効値修正後も残る問題の修正。有効値入力でclientErrorをクリアしstale invalid解消 | frontend | 2026-07-14 14:16 |
| T042 | 軽微UI: manage/usersのタブレット名切れとsettingsのパスワード表示トグル。S1=メンバー/招待行にsm:flex-wrap+名前/メール列sm:min-w-40の床+操作sm:ml-auto(sm:justify-between除去)、S2=パスワード変更2入力をPasswordInput moleculeへ差し替え表示トグル付与。Codex(gpt-5.3-codex) impl-review R1でAPPROVED。 | frontend | 2026-07-14 14:32 |
| T040 | manual画面のシナリオ保存トースト帰属確認とrender/preview失敗alertの発生源明示。S1=ScenarioEditorに保存成功のその場残留インジケータjustSaved追加(toast 4s自動消去非依存・保存成功パスのみtrue・dirtyと排他)、S2=RenderPanelのrender/preview起動失敗stateをsource別に分離+全danger Alertにphase-aware title付与し発生源×局面で帰属明示(preview-start-error/preview-purchase-link新設)。frontendのみ。Codex(gpt-5.3-codex) impl-review R1でAPPROVED。 | frontend | 2026-07-14 14:47 |
| T043 | テイク削除(capture.takes.destroy)に確認ダイアログを追加。削除前に確認ダイアログを挟む | frontend | 2026-07-14 17:53 |
| T044 | client-side stale validation の横展開修正(移譲フォーム他)。有効値復帰でclientErrorをクリア(移譲フォーム他) | frontend | 2026-07-14 17:55 |
| T045 | 特定商取引法ページ(commerce-disclosure)へのサイト内リンク追加。フッターに特定商取引法リンクを追加しreachability回復 | frontend | 2026-07-14 17:57 |
| T046 | AIシナリオ生成の導入カット/総括カット自動挿入。AI生成シナリオに導入/総括カットを自動付与 | backend | 2026-07-14 23:16 |
| T047 | 撮影中カメラプレビューへの字幕オーバーレイ表示。撮影プレビューに字幕を重畳表示（焼込でない/トグル可） | frontend | 2026-07-14 23:17 |
| T048 | シナリオ編集のUndo/Redo(一つ戻る/進む)。シナリオ編集の保存前ローカル編集に対する Undo/Redo（一つ戻る/進む） | frontend | 2026-07-14 23:19 |
| T049 | マニュアル(シナリオ)の別名保存/複製。保存済みシナリオ(cuts)を雛形に新タイトル・カテゴリで別 manual を複製(status=draft/scenario_version=0 リセット、takes/adopted_take_id/render成果物/source_documents/analysis_jobs は非複製)。複製route(scopeBindings群・cross-manual/project は404)+DuplicateVideoManualRequest(保護キー不信+category project スコープ)+VideoManualPolicy::duplicate(撮影者403)+VideoManualService::duplicate(共有ロック規約: 元/新manualをlockForUpdateした同一tx内でcuts作成・point の parent_cut_id を新step idへ張替・孤児pointはskip+warning)+DuplicateManualDialog導線。NestedRouteIdorDefenseTest/ScenarioWritePathInventoryTest inventory登録+ManualDuplicateTest。Codex impl-review APPROVED (Round 2) | backend | 2026-07-15 00:02 |
| T050 | テイクのインラインプレビュー再生+ナレ/字幕トグル。テイクをインライン再生（字幕トグル+採用同居） | frontend | 2026-07-15 00:04 |
| T051 | 撮影詳細入室時の採用済みテイク自動ダウンロード。入室時に採用テイクを自動DL同期(サーバ変更なし) | frontend | 2026-07-15 03:01 |
| T052 | capture.manuals.sync のフロント配線 or 廃止判断。sync endpoint をフロント配線せず廃止(削除)・inventory/doc 整合 | general | 2026-07-15 03:04 |

## Obsoleted

| ID | タイトル | テーマ | 廃止日 | 理由 |
|---|---|---|---|---|
