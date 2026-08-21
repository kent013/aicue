# bug-hunt 統合レポート (run 20260821-095643)

- 実行日時: 2026-08-21 (JST)
- モード: `--all --coverage --parallel=4 --deviate --real-llm` (既定)
- 環境: bughunt 並列 shard (:8011..8014 / DB bug_hunt_1..4)、LLM=実 Anthropic API、その他外部 (決済/Captcha/SSO/mail/S3)=fake
- 走行方式: `bughunt-shard` subagent 4 体を本セッションから fan-out。各 shard は隔離ブラウザ (`-s=bughunt{i}`) で実走。
- verify-run: **exit 0 (全 shard 完遂)**
- カバレッジ計装 (`--coverage`): pcov 拡張が環境に無いため **code-reach は収集できず** (middleware は no-op で安全続行)。operation-reach は後述。

## shard / ストーリー割当

| shard | URL | ストーリー | findings |
|---|---|---|---|
| 1 | :8011 | S3 中核ジャーニー / S7 認可境界・IDOR | High 1 / Medium 1 |
| 2 | :8012 | S1 登録・ログイン / S2 招待フロー | **Critical 2** / Medium 1 / 要確認 1 |
| 3 | :8013 | S4 組織・プロジェクト管理 / S5 課金・チケット | Low 1 / 要確認 1 |
| 4 | :8014 | S6 セキュリティ・2FA・プロフィール | Medium 1 / 要確認 2 |

## 集計 (dedupe 後、全 shard 和集合)

- **Critical: 2** (F-2-02, F-2-03)
- **High: 1** (F-1-02)
- **Medium: 4** (F-1-01 [H12], F-1-03 [S7 保護キー], F-2-01 [H12], F-4-01 [H7])
- **Low: 1** (F-3-01 [H12/a11y])
- **要確認: 3** (Q-2-01, F-3-02, S6 要確認×2 を含む)

同一 route×症状の重複は検出されなかった (各 shard が別ストーリー分担のため自然に非重複)。

> **運用メモ (要 orchestrator 確認)**: shard-1 は本 run 中に走行プロセスが二重に動いた形跡があった
> (browser-type の無操作変化、close 後も list に残存、自分が発行していない画面遷移、レポート骨子の第三者上書き)。
> DB (`bug_hunt_1`) は終始健全で serve/DB 障害ではない。両走行とも F-1-01/F-1-02 を一致して報告し、
> F-1-02 は独立に裏付けられたため findings 自体は信頼できる。2 回目の走行が追加で F-1-03 を発見した。
> harness 側で同一 shard への subagent 二重 fan-out が起きた可能性があり、次回 run では要注意。

## adjudication registry 突合 (親のみ consult 済み)

全 10 件を `ledger/validate_findings.py --adjudications ... --annotate` に通した。
**全件 `adjudication_status: none`** = 既知の裁定 (誤検知/意図的仕様/won't-fix) に一致するものは無し。
つまり全件が **未知/actionable**。ambiguous (要人手) 判定も無し。findings.jsonl はスキーマ検証も通過。

---

# Critical

## F-2-02: 招待受諾が宛先メールと受諾者を照合せず、無関係な第三者が他人宛の招待で組織参加できる (認可バイパス / H9)

- **species**: `authz_bypass:invitation:create:cross_tenant`
- **再現**: (shard-2 :8012) 組織オーナーが `shard2-reuse-test@example.com` 宛に招待送信 → `mail-urls` で受諾 URL 取得 → **全く別のメールアドレスの既ログインユーザー** (別組織オーナー) のまま受諾 URL を開く → 警告なく「招待を受諾する」が押せ、成功トースト「…に参加しました」で `/dashboard` へ。実際にメンバー参加が成立。
- **実際**: 招待トークンの検証が「有効な(未失効・未使用)トークンか」だけで、「受諾ユーザーの email が宛先 email と一致するか」を検証していない。
- **阻害されたジョブ**: 組織のメンバー境界 (誰が参加できるか) が、メールアドレスという意図した認可境界どおりに機能しない。招待リンクを (メール転送・URL 共有・履歴・ログで) 知った第三者が自アカウントで組織参加できる。
- **改善案**: `invitations.accept.store` (および受諾確認画面表示時) で `$request->user()->email === $invitation->email` を検証し、不一致は拒否 + 「招待先のメールアドレスでログインし直してください」を表示。未ログイン時フロー (register 誘導・メール自動入力 T055) は問題なし。
- **証跡**: shard-2/screenshots/F-2-02-wrong-user-invite-screen.png, F-2-02-joined-org-toast.png
- **推定原因**: 未調査 (`InvitationAcceptController`/`OrganizationInvitation` 受諾処理が token→invitation 解決のみで email 一致検証を欠く可能性)

## F-2-03: メンバー削除が「削除しました」と表示するのに実際は解除されず、削除済みユーザーが組織データにアクセスし続ける (H10 矛盾 / 実質的認可漏れ)

- **species**: `authz_bypass:member:delete:same_tenant`
- **再現**: (shard-2 :8012) オーナーが `/manage/users` でメンバー(編集者)を「削除」→ トースト「メンバーを削除しました」+ 「この操作は取り消せません」表示、一覧から行が消える → しかし当該ユーザーで別セッションログインすると `/dashboard`・`/projects`・`/billing` に引き続きアクセスでき、組織もアクティブなまま → オーナー側で `/manage/users` を再確認すると削除したはずのユーザーが「未割当」ロールで再出現。
- **実際**: 削除操作はロール剥奪 (Laratrust role detach) のみで、組織↔ユーザーの pivot (メンバーシップ本体) を解除していない。一覧から消えたのは表示上の一時反映のみ。
- **阻害されたジョブ**: 問題メンバー・退職者・誤招待相手を組織から排除する最重要のセキュリティ操作が実質機能しない。オーナーは「削除した」と信じるが対象は組織データへの閲覧アクセスを保持し続ける。
- **改善案**: `organizations.members.destroy` で role detach だけでなく `$organization->users()->detach($user)` (pivot 解除) を確実に実行。合わせて「ロール未割当だが組織に紐づいたまま」の異常行を許容する設計 (`Admin/UserManagementController` のコメントが言及) を根本的に見直すか要判断。
- **証跡**: shard-2/screenshots/F-2-03-removed-member-still-has-access.png + `/manage/users` 再確認 snapshot
- **推定原因**: 未調査 (`OrganizationMemberController::destroy` が `detachRole`/`syncRoles` のみで pivot detach を呼ばない可能性)

---

# High

## F-1-02: 撮影 PWA でカット選択/アップロード直後に説明なく撮影画面から離脱し、デスクトップ画面へフルページ遷移する (H1)

- **species**: `broken_flow:take:create:self`
- **再現**: (shard-1 :8011) owner-personal でログイン、6 カット構成マニュアルを AI 解析済みにする → `/app/projects/1/manuals/1` (撮影 PWA) でカット選択/ファイル選択/アップロードのいずれかの**直後 1〜3 秒以内**に、ユーザー操作なしで `/projects/1/manuals/1` または `/edit` へ**フルドキュメント遷移** (Inertia SPA 遷移ではない完全ページロードをネットワークログで確認)。1 セッション内で最低 4 回再現、発生条件は非決定的。
- **実際**: アップロード済みテイクはサーバに残るが、**採用前に離脱するとテイクは未採用のまま撮影 PWA から追い出される**。説明・確認は一切なし。
- **阻害されたジョブ**: 撮影者の連続撮影・アップロード・採用の一連作業が予告なく中断。特に「アップロード後・採用前」の離脱は採用忘れ→撮影完了漏れに直結。
- **改善案**: 撮影 PWA 内の操作は `/app/projects/{project}/manuals/{manual}` に留め、離脱は明示操作のみを契機にする。遷移の発生元コード経路 (バックグラウンドの `reloadManual()`/`router.reload` や `ThumbnailRefreshScheduler` のポーリングがフルリロードにフォールバックしている可能性) を特定する。
- **証跡**: shard-1/screenshots/pre-click-cut4.png + requests --static の遷移先フルドキュメント GET ログ
- **推定原因**: 未特定 (`resources/js/pages/Capture/Show.svelte` / `resources/js/lib/capture/*` に明示的 location 代入は見当たらず)

---

# Medium

## F-1-01: SOP ファイルを添付しても、添付済みか画面上で確認する手段が一切ない (H12)

- **species**: `ux_dead_end:source_document:create:self`
- (shard-1 :8011) manuals.create で SOP ファイルを選択しても選択後にファイル名・件数の視覚表示が出ない。manuals.show の手順書パネルも「差し替える」ボタンのみで現在の添付ファイル名・件数・日時が表示されない。サーバ側には保存されている (AI 解析が走ることで間接確認)。「差し替える」という文言が既存ファイルの存在を前提にしているのに、その情報が一切出ないのは矛盾。
- **改善案**: create フォームでファイル選択後にファイル名を表示 / show 画面の手順書パネルに現在のドキュメント一覧 (名・サイズ・日時) を表示。
- 証跡: shard-1/screenshots/F-1-01-no-filename-shown.png

## F-1-03: `capture.takes.adopt` が保護キー `adopted_take_id` を payload に含めても拒否しない (S7 手順7 想定 422 に対し実際 200)

- **species**: `validation_gap:take:adopt:self`
- (shard-1 :8011) owner-personal で `POST /app/projects/1/manuals/1/cuts/2/takes/1/adopt` に body `{"adopted_take_id": 999}` を送ると 422 でなく 200。`project_id`/`created_by`/`category_id` を `projects.manuals.update` に混入した場合は正しく 422 (ProhibitsProtectedKeys) だが、`capture.takes.adopt` の `adopted_take_id` は検査対象から漏れている。**実害は限定的** (採用されるのは URL の take id で body の値は無視される) だが、将来 body 優先の実装変更で無警告に cross-cut/cross-tenant 採用を許すリスク (defense-in-depth 欠落)。
- **改善案**: `ProhibitsProtectedKeys` 相当を `capture.takes.adopt` の Request クラスにも適用し、保護キー混入を 422 で拒否。

## F-2-01: プロジェクト未作成の組織で編集者/撮影者ロールが選択可能に見えるが送信後にエラーになる (H12)

- **species**: `validation_gap:member:update:self`
- (shard-2 :8012) `/manage/users` のロール combobox で「編集者」「撮影者」が選択可能な option として表示され、選択・送信後に「編集者・撮影者を割り当てるには、先にプロジェクトを作成してください。」の validation エラーが事後表示。1 往復の手戻り。
- **改善案**: プロジェクト 0 件の組織では両 option を disabled にし「プロジェクトを作成すると選べます」を事前表示。

## F-4-01: recent-auth 経由のメール変更成功後、成功フィードバックが出ない (H7)

- **species**: `other:profile:update:self`。**feedback-probe 陰性確定** (`installed_now:false seen:[] present_new:[] pending:0 errors:0`、再認証モーダル送信直後と /email/verify 到達後の 2 回とも同一)。
- (shard-4 :8014) stale remember-me セッション → メール変更 → 本人確認モーダルで再認証 → `/email/verify` へ遷移。この間「メールアドレスを変更しました」等の成功トースト/flash が一度も出ない。操作自体は成功している (変更は効いている)。通常のプロフィール更新では「プロフィールを更新しました。」トーストが出ることは確認済みで、本件はメール変更 + step-up + email/verify 遷移が絡む場合固有。
- **改善案**: 再認証モーダル通過後の元操作再送時、成功なら email/verify 遷移前に一瞬トーストを出すか、email/verify 画面に「メールアドレスを xxx へ変更しました」を表示。

---

# Low

## F-3-01: オートリチャージの範囲エラーで対象 spinbutton に aria-invalid が付かない可能性 (H12/a11y)

- **species**: `other:billing_auto_recharge:update:self`
- (shard-3 :8013) `/billing` オートリチャージで開始残高≧補充後残高の不正入力 → エラー文言は正しく表示されるが対象 spinbutton に `[invalid]` が確認できなかった (他フォームは一貫して付く)。**1 回のみの観測で断定はしていない** (severity Low の理由)。
- **改善案**: `max_count`/`threshold_count` の spinbutton に validation エラー時 `aria-invalid="true"` を付与。

---

# 要確認 (仕様確定が必要 / バグと断定しない)

- **Q-2-01** (shard-2): 招待経由参加メンバーの登録直後の着地が `/dashboard` ではなく `/billing`。screens.md「課金ゲート着地」節 (docs/billing-gate-inversion-runbook.md) と整合し機能破綻はないが、非管理メンバーの初回着地として自分で変更できない請求画面が直感的か要確認。
- **F-3-02** (shard-3): 課金 checkout の `subscription_attempt_token` idempotency (同一 token・別プラン)。**実装コードにはガードが存在** (`SubscriptionService::startCheckoutLocked()` → `SubscriptionAttemptPlanMismatchException` → 422、読了確認済み) が、FakeStripeGateway の「中立帰還」設計と Inertia クライアント前提により browser-only の raw fetch では 422 を再現できず。Feature test でこの経路がカバーされているか確認を推奨 (実装変更は不要と見られる)。
- **S6 要確認-1** (shard-4): メール変更時の旧アドレスへの通知有無。`mail-urls` は署名 URL 抽出のみで本文/宛先を確認できず判定不能。
- **S6 要確認-2** (shard-4): `settings.password.store` (パスワード未設定ユーザーの初回設定) の正常系。該当ユーザーが seed に存在せず新規作成手段も塞がれ未検証 (迂回不可側 = 既設定ユーザーへの直 POST は 422 fail-closed を確認済み)。

---

# 正常動作として確認できたもの (肯定的検証)

- **S7 認可境界 (shard-1)**: 組織 B→A の越境アクセスは screen/write/capture すべて **404** (Blade 例外なし、存在オラクル漏れなし)。ロール境界 (編集者専用操作は撮影者に 403)、protected keys 直送 (422 ProhibitsProtectedKeys) すべて正常。`categories.reorder` の一時的な差分は自前ハーネスの CSRF トークン欠落が原因で誤検知と判断し不起票。
- **S4/S5 認可 (shard-3)**: 非管理メンバーの `/manage/users`・api-keys は 403 (UI 非表示 + サーバ強制)。越境プロジェクト ID は 404 (存在オラクル漏れなし)。billing 系 write を非 manageBilling メンバーが直 fetch → すべて 403。one-shot 課金バナーは reload で再表示されず、偽 session_id でも発火しない (fail-closed)。
- **S6 セキュリティ (shard-4)**: アカウント削除保留時の凍結/リダイレクト、単独オーナーの削除サーバ側再チェック、passkey.destroy の IDOR 防御 (他人/不正/巨大 id すべて 404)、stale remember-me への recent_auth 409 step-up ゲート、パスワード初期設定の bypass 拒否 (422)、bfcache ガード、通知空状態、H11-H14 UI/UX (desktop/mobile 375/tablet 768) 破綻なし。

---

# カバレッジ

- **画面カバレッジ**: screens.md 総 71 画面のうち各 shard 担当ストーリー分を走行。S1(15)/S2(2)/S3(13)/S4(11)/S5(4)/S6 系 + S7 は S3 の nested screen を B 視点で再走査。管理画面 (Filament)・機械向け API・MCP は `web` 非宣言のため探索対象外 (目録の設計どおり)。
- **操作カバレッジ**: operations.md 総 79 操作のうち各 shard がストーリー割当分を実行。skip は全て理由付きで記録:
  - `manuals.destroy` (shard-1): published データ保全のため意図的 skip
  - `passkey.login`/`passkey.store` 系 (shard-2/4): WebAuthn が IP リテラル RP ID を許さず環境的に実行不能
  - `debug.login-as` (shard-2): `APP_ENV=bughunt.local` では `app()->isLocal()` が false で 404 (下記インベントリ提案)
- **operation-reach 突合 (成功)**: `coverage-operation-reach.md` に出力。in_scope 分母 78 機構中 **19 件が未実行 (記録上)**。
  - **★ cross (未実行 ∧ finding ≥2) = 6 件 [優先度最高]**: `invitations.accept-in-app` (Critical×2 が絡む代替導線)、`capture.takes.destroy`/`capture.takes.update`/`projects.manuals.destroy`/`projects.manuals.source-documents.store`/`projects.manuals.update` (S3 撮影/SOP 系)。
  - 注: この「未実行」は BughuntExecutedRouteMiddleware の記録上の話で、一部は shard が UI で操作したが記録粒度で漏れた可能性がある (例: `projects.manuals.update` は shard-1 が title 編集で実行済みと報告、`projects.manuals.source-documents.store` は作成時同時アップロードで実行済みと報告)。断定はしない。`invitations.accept-in-app` (アプリ内受諾の別導線) と passkey/組織移譲/2FA要件系は実際に未走行で、再走行候補。
- **code-reach**: pcov 未導入のため未収集 (middleware は no-op)。

---

# インベントリ修正提案 (shard から集約)

1. **debug.login-as (S1, 通常)**: `app()->isLocal() || runningUnitTests()` ガードにより `APP_ENV=bughunt.local` では常に 404。区分「外」にして分母から外すか、bughunt 環境でも到達可能にするか要判断。
2. **S2 カード本文のロール表現**: 「メールとロール(編集者/撮影者)を指定して招待」とあるが実際の招待フォームのロールは「管理者・メンバー」の 2 値のみ (編集者/撮影者は参加後に members.update で割当)。カード本文を実装と一致させる。
3. **password.confirm (screens.md)**: 実装は独自ページを描かず `recent-auth.confirm` へ即時リダイレクト (config/fortify.php の意図的統合)。別行に残す設計が正しいか確認。
4. **shard URL のホスト名 (親検討)**: WebAuthn を構造的にテスト可能にするため、`127.0.0.1:801{i}` ではなく `bughunt{i}.localhost:801{i}` 等のドット付き DNS 名の割当を検討 (APP_URL/session cookie domain/CORS の追従が必要)。
5. **パスワード未設定テストユーザー (親検討)**: `settings.password.store` 正常系検証のため SSO/passkey のみのユーザーを seed に追加検討。

---

# Critical/High TODO 候補要約 (app-design → app-todo-add に渡せる粒度)

1. **[Critical] 招待受諾の本人メール照合欠如** (F-2-02): 受諾時に user.email == invitation.email を検証。関連: 招待受諾コントローラ。
2. **[Critical] メンバー削除で pivot が解除されない** (F-2-03): destroy で組織↔ユーザー pivot detach を確実実行 + 「未割当のまま組織紐付き」異常行の設計方針確定。関連: `OrganizationMemberController::destroy`。
3. **[High] 撮影 PWA からの説明なしフルページ遷移** (F-1-02): 撮影中の意図しない離脱の発生元経路を特定し、PWA 内に留める。関連: `resources/js/pages/Capture/Show.svelte`、`resources/js/lib/capture/*`。
