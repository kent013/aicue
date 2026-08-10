# bug-hunt report shard-2 (S1, S2) 2026-08-11

- run_id: 20260811-003230
- shard: 2 (port 8012, db bug_hunt_2)
- 実行ストーリー: S1 (guest-registration-funnel), S2 (invitation-flow)
- skip したステップ:
  1. `debug.login-as` — bughunt 環境では `isLocal()` が false のため route 自体が 404 (環境上 exercise 不能。fail-safe 設計)。
  2. S2 逸脱「撮影者ロールで受諾後、編集者専用操作 (manuals.store 等) を試す→403か」— プロジェクト作成等の追加セットアップが必要で時間都合により未実施 (S7 の認可境界検証と領域重複)。
  3. S2 逸脱「招待受諾の二重送信」— 未実測 (推測のみ記載)。
  4. S2 逸脱「admin ロール招待での email-mismatch 受諾」の実ブラウザ再現 — コード確認のみで時間都合により未実施。
- 画面カバレッジ: 走行 16 / 16 (S1: home, pricing, terms, privacy, commerce-disclosure, contact,
  contact.thanks, register, verification.notice, verification.verify, onboarding.checkout, dashboard,
  login, password.request, password.reset, two-factor.login / S2: invitations.accept,
  onboarding.billing-required は S1 の 16 画面と別枠で完走)。実質 S1=16画面 + S2=2画面 = 18 画面完走。
  passkey.login-options は screen というより API だが操作込みで検証済み。未走行なし。
- 操作カバレッジ: S1 11/11 (register.store, login.store, logout, password.email, password.update,
  verification.send, two-factor.login.store, contact.store, onboarding.activate-personal, passkey.login,
  debug.login-as=skip理由あり) / S2 6/6 (invitations.accept.store, organizations.invitations.store,
  organizations.invitations.revoke, organizations.members.update, organizations.members.destroy,
  organizations.members.two-factor.reset)。未実行は debug.login-as のみ (理由: 環境非対応)。
- UI/UX 検証: H11 (視覚破綻) 所見なし。H12 (状態表現) 所見なし (disabled ボタン・toast・confirm dialog は
  一貫)。H13 (レスポンシブ) dashboard・manage/users を mobile 375x667 / tablet 768x1024 で確認、崩れなし、
  ハンバーガーnav・組織ポップアップとも問題なし。H14 (a11y 基礎) 個別の深掘りはしていないが、
  snapshot 上ボタン/リンクに name が一貫して取れており明らかな欠落は見当たらず。
- findings: Critical 0 / High 0 / Medium 1 (F-2-01) / Low 1 (F-2-02) / 要確認 1
  (招待 token 経路の email 非照合。admin 招待でのリスク増幅を app-design へ要検討として引き渡し)
- H7 未検証: 0 件 (全書き込み操作で feedback probe の肯定/陰性いずれかを確定できた)

## 対象 screens/operations (ストーリーカードより)
- S1 screens: home, register, login, dashboard, onboarding.checkout, verification.notice, verification.verify, password.request, password.reset, two-factor.login, contact, contact.thanks, legal.commerce-disclosure, legal.privacy, legal.terms, passkey.login-options
- S1 operations: register.store, login.store, logout, password.email, password.update, verification.send, two-factor.login.store, contact.store, debug.login-as, onboarding.activate-personal, passkey.login
- S2 screens: invitations.accept, onboarding.billing-required
- S2 operations: invitations.accept.store, organizations.invitations.store, organizations.invitations.revoke, organizations.members.update, organizations.members.destroy, organizations.members.two-factor.reset

## 走行ログ (進行中)
- 開始: db-check OK (db=bug_hunt_2, users=11)
- S1 完了分 (screens): home, pricing, terms, privacy, commerce-disclosure, contact, contact.thanks,
  register, verification.notice, onboarding.checkout, dashboard, login, password.request(forgot-password),
  password.reset
- S1 完了分 (operations): contact.store (空入力422確認→正常送信), register.store (空入力422→パスワード強度422→正常),
  verification.send (再送。toast 確認), onboarding.activate-personal (自己申告チェック必須422→正常、後で決める選択),
  login.store (誤パス→汎用エラー422確認→正常), logout (ボタン正しく POST。GET直叩きは405挙動を確認、下記逸脱ログ参照),
  password.email (toast確認), password.update (成功→再ログイン確認→旧トークン再利用は422で拒否確認)
- H13: dashboard を mobile 375x667 / tablet 768x1024 で確認 (ハンバーガーnav含む)。崩れなし。desktop(1280)に復帰済み。
- 逸脱ログ: `GET /logout` 直叩き (goto) はログアウトされない (logout は POST 専用ルートで意図的にGET未対応。
  guest 状態への遷移失敗ではなくアプリ仕様どおり)。/forgot-password への goto がログイン中のため /dashboard へ
  差し戻された (guest-only ガード正常)。finding にしない。
- 逸脱ログ: password.reset トークン再利用 → 422 「このパスワード再設定トークンは無効です。」で正しく拒否。finding にしない。
- S1 追加: two-factor.login / two-factor.login.store を実走 (設定で自ユーザーに 2FA を有効化 → ログアウト →
  再ログインで two-factor-challenge 到達 → 誤コード 422 確認 → TOTP secret から算出した正コードで dashboard 到達)。
  リカバリコードタブの存在も確認 (未使用)。
- S1 追加: passkey.login-options の存在オラクル検証 (逸脱): 実在ユーザー / 非実在メールアドレスとも
  `GET /passkeys/login/options` は同一形状 (200, allowCredentials:[], challenge のみ相違) で応答。
  差が出ないため existence oracle 漏れ無し (finding にしない)。
- S1 追加: `throttle:passkeys` 連打で 429 発生を確認 (X-RateLimit-Limit:10)。→ F-2-02 (Low)。
- S1 追加: `?plan=` handoff は `/pricing?plan=` ではなく `/onboarding/checkout?plan=` が実装対象と判明
  (`PricingController` は query 未読・`OnboardingController::show` が `IntendedPlanResolver` で処理)。
  starter 指定→303・query 消滅・reload後も peek 保持を確認。`enterprise`(未知値)・5000文字巨大値のいずれも
  500/存在オラクルにならず正規化 (finding にしない)。ストーリーカードの URL 表記 (`/pricing` から) が
  実装と不一致な可能性 → インベントリ修正提案に記録。
- S1 追加: 契約済みユーザーで `/onboarding/checkout` 直叩き → `billing.index` へ正しく逃がされる。
  `/billing-required` 直叩き (利用可状態) → `dashboard` へ正しく逃がされる。ループ・空画面なし。
- S1 追加: `debug.login-as` (`/debug/login`, `/debug/login/{userId}`) は bughunt 環境 (APP_ENV=bughunt.local)
  では `isLocal()` が false のため route 自体が 404 (LocalOnly 二重防御より前に route 非登録)。
  **skip (理由: 環境上 exercise 不能。意図的な fail-safe 設計で bug ではない)**。
- S1 追加: register / contact フォームの二重クリック連打 → 送信ボタンが最初のクリックで disabled 化し、
  2 回目クリックは失敗 (要素 detached)。POST は 1 回のみ発生。二重送信保護あり (finding にしない)。
- S1 H13: 追加で login / register / onboarding.checkout は目視のみ (resize 未実施、dashboard で代表確認済みのため省略)。

## S2 走行ログ
- organizations.invitations.store: 空入力422確認→正常送信 (メンバー役割) → 一覧に反映 → toast確認。
- invitations.accept (GET, 未ログイン): 招待メールリンクを未ログインで開くと register へ誘導され、
  メール欄が招待先アドレスで自動入力・「招待されたメールアドレスで登録します。」表示 (T055 確認)。
  登録→メール認証完了後、招待先組織 (シャード三郎の組織) にのみ所属し、個人組織は作られない
  (T030 確認: ヘッダー組織ポップアップに 1 組織のみ表示)。
- onboarding.billing-required (未契約組織の非 manageBilling member 着地): 花子が着地。
  オーナー名・オーナーの mailto リンク・お問い合わせ導線が表示。403/空画面にならず、
  左サイドバー (ダッシュボード/プロジェクト/請求のみ、メンバー/APIキーは非表示) から他画面へ移動でき
  詰みにならない。dashboard には F-2-01 と同じ「支払いが確認できない」誤文言が member 視点でも出る
  (F-2-01 と同一原因、二重計上しない)。/billing・/billing/plans は非 manageBilling でも閲覧可能で
  「プランの変更には組織の管理者権限が必要です」等の権限説明が出て操作ボタンは disabled
  (クリックしても POST は発火しない。認可の多層防御を確認)。
- 逆方向離脱ガード: 利用可 (契約済み) 状態で `/billing-required` 直叩き → `dashboard` へ正しく逃がされる
  (ループ・空画面なし)。
- organizations.members.update: 「未割当」→「管理者」への変更で toast 確認・一覧に反映。
  ロール選択肢は 管理者/編集者/撮影者 の 3 値 (`applyConsoleRole`)。招待時ロール (管理者/メンバー、
  org 参加可否のみ) とは別概念で、"招待時 project_role 選択の撤去" (AG-079) はこの UI ではなく
  招待フォーム側の変更と判明 (`docs/architecture.md` 993-1001行で確認)。**撤去漏れ無し**。
- organizations.members.two-factor.reset: 花子に一時的に 2FA を有効化 (settings/security から
  TOTP secret を算出して確認) → owner から「2FA 解除」→ 理由必須 (空欄422・10文字未満422) →
  正当な理由で成功・toast 確認・一覧から 2FA バッジと解除ボタンが消える (H10 一貫性確認)。
- organizations.members.destroy: 確認ダイアログあり (H7 満たす) → 削除 → toast 確認 →
  一覧から即消える。**削除された花子は次回ログイン時 500/エラーにならず**「組織未選択」+
  「組織を作成しましょう」という空状態に正しく着地 (H8 説明あり、詰みなし)。
- organizations.invitations.revoke: 確認ダイアログあり → 取消 → toast確認 → 一覧から消える →
  取消済みトークンで `/invitations/accept?token=...` を開くと「この招待リンクは使用できません」
  (HTTP 200、理由非開示の専用ページ)。**存在しないトークンでも同一の 200 + 同一文言**
  (`curl` で実測、byte-identical) — revoked/expired/不在の区別が付かず存在秘匿契約を満たす
  (実装は「一律404」ではなく「一律200 + 専用ページ」だが、狙いである no-oracle は達成されている。
  ストーリーカード/口頭指示の「404」という書き方と実装が食い違う点はインベントリ修正提案に記録)。
- 逸脱: 別ユーザー (シャード二郎, 別 email) がログイン中に他人 (cross-tenant-target@example.com) 宛の
  招待 token を直接開いて受諾 → **成功してしまう** (email 不一致でも参加できる)。詳細と
  「finding にしない」の根拠は上記「検証済み・finding にしないもの」節を参照 (token 経路は
  email 照合を要求しない仕様と `docs/architecture.md` に明記済み)。
- 逸脱: 招待受諾の二重送信は個別に確認していない (時間都合で skip。理由: token 経路の受諾は
  1 回目でトークンが `accepted_at` 済みになり 2 回目は同一の「無効」ページに落ちると推測されるが
  未実測)。
- skip: 「撮影者ロールで受諾後、編集者専用操作 (manuals.store 等) を試す→403か」(S2 逸脱最終項) は
  プロジェクト作成・撮影者ロール割当・manuals.store の実行という追加ステップが必要で、
  残り時間の都合で **未実施 (skip)**。S7 (認可境界、shard-1 が担当) と重複領域のため、
  未実施でも致命的な穴にはならないと判断。

## インベントリ修正提案
1. `S1-guest-registration-funnel.md` 手順5 の「`/pricing` から `?plan=starter` 等を付けて入ると、
   canonical URL (`/onboarding/checkout`) へ 303 され」という記述は実装と不一致。実際に `?plan=`
   を処理するのは `/onboarding/checkout` 自身 (`OnboardingController::show`) であり、`PricingController`
   は query を一切読まない (`/pricing?plan=` はただの marketing page として静的表示されるだけ)。
   ストーリーカードの記述を「`/onboarding/checkout?plan=starter` 等を直接開くと」に修正することを提案。
2. `S2-invitation-flow.md` 逸脱アイデア「取り消し済み/期限切れ/受諾済みの招待リンクを再利用 → 弾かれるか」
   の期待は満たされているが、`docs/architecture.md` の実装は「一律 404」ではなく「一律 200 (専用の
   Invitations/Invalid ページ)」である (`InvitationAcceptanceController::show` を実測)。
   狙い (no existence oracle) は達成されているため finding にはしていないが、口頭指示・スキル文書で
   「404」と書かれている箇所があれば実態 (200) に合わせて表現を修正することを提案 (機能上の問題ではない)。

---

## F-2-01: 未契約 (プラン未選択) の新規ユーザーに「サブスクリプションの支払いが確認できない」と誤った理由が表示される
- severity: Medium (H10: 文言が実際の状態と矛盾)
- story/step: S1-5 (登録直後の課金オンボーディング着地)
- 再現手順:
  1. `http://127.0.0.1:8012/register` で新規登録 (例 shard2-newuser@example.com / Password1234)。
  2. `mail-urls` で取得した認証リンクを開く → `/onboarding/checkout` (プラン選択) に自動着地。
     ここではまだ**一度もプランを選択・Personal 有効化していない**。
  3. `http://127.0.0.1:8012/dashboard` を直接開く (dashboard は課金ゲート allowlist で
     `require-active-subscription` の対象外 = 常時閲覧可、`routes/web.php:191` は
     `require-active-subscription` group (453 行目〜) の外)。
  4. dashboard 本文に赤字で「サブスクリプションのお支払いが確認できないため、一部機能を
     一時停止しています。お支払い方法をご確認ください。」というカードが出る
     (`resources/js/pages/Dashboard.svelte:208-217`、`billing.has_billing_access` が false のとき表示)。
- 期待: このユーザーは**一度も課金手続きをしておらず支払いは失敗していない** (単に
  プラン未選択の `NoSubscription` 状態)。文言は「支払いが確認できない (=過去に失敗した)」ではなく
  「まだプランが選択されていません」等、状態に即した案内であるべき。
- 実際: `hasBillingAccess` は `BillingAccess::hasActiveAccess()` の単一 boolean で、
  `NoSubscription` (一度も契約していない) と `PastDue` (契約済みだが支払い失敗) を
  区別せず**同じ「支払いが確認できない」文言**を出す
  (`app/DataTransferObjects/Dashboard/BillingSummaryData.php:19` のコメントでも
  `hasActiveAccess` は単純な entitlement boolean と明記されている。
  `app/Http/Middleware/RequireActiveSubscription.php:52` の `BLOCKED_MESSAGE` も同一文言を
  他所で使っており、状態の切り分けが元々存在しない)。
- 阻害されたユーザージョブ: 登録直後の全ユーザー (S1 のメインファネル) が dashboard を見た瞬間、
  実際には発生していない「支払いエラー」に直面したと誤解し、不要な不安や問い合わせを招く。
  正しい次アクション (プランを選ぶ) ではなく「支払い方法を直す」という誤った行動に誘導されうる
  (リンク先は `/billing` で、そこがどう案内するかは未確認/別 finding 候補)。
- 改善アクション候補: `has_billing_access` を boolean のまま使うのではなく、
  `BillingAccess::state()` の enum (`NoSubscription` / `ExpiredCheckout` / `PastDue` 等) を
  DTO に含め、`Dashboard.svelte` 側で状態別の文言 (未契約なら「プランを選択してください」+
  `/onboarding/checkout` への導線、支払い失敗なら現行文言) に出し分ける。
- 証跡: screenshots/F-2-01-dashboard-billing-callout.png
- 推定原因: `app/Services/Dashboard/DashboardService.php:234` が
  `hasBillingAccess: $this->billingAccess->hasActiveAccess($organization)` と boolean のみを
  渡しており、状態種別 (state enum) を DTO に落としていない。
- 関連既知情報: 未調査 (devnotes 検索は未実施、走行継続中)

## F-2-02: passkey.login-options の 429 (throttle:passkeys) が汎用エラー文言で理由 (再試行待ち) を伝えない
- severity: Low
- story/step: S1-4b 逸脱 (`throttle:passkeys` の 429 が無反応でなく説明付きで出るか)
- 再現手順:
  1. `http://127.0.0.1:8012/login` を開く。
  2. メールアドレス欄に任意の値を入れ「パスキーでログイン」ボタンを 11 回以上連打する
     (`throttle:passkeys` の上限は `X-RateLimit-Limit: 10`、10 req/分)。
  3. 11 回目以降 `GET /passkeys/login/options` が `429 Too Many Requests` を返す。
  4. 画面には alert が出る:「パスキーの認証を開始できませんでした。」
- 期待: 詰み (無反応) にはなっていない点は満たしている。ただし「429 = 少し待てば再試行できる」
  ことが伝わる文言 (例:「しばらく待ってから再試行してください」) が望ましい。
- 実際: あらゆる失敗理由 (429 もネットワークエラーも) を同一の汎用文言
  「パスキーの認証を開始できませんでした。」で表示しており、ユーザーは
  再試行すべきか・別の手段 (パスワードログイン) に切り替えるべきかの判断材料がない。
- 阻害されたユーザージョブ: パスキーログインを試そうとした際に一時的な混雑で弾かれても、
  ユーザーは「なぜ失敗したか」「いつ再試行できるか」が分からず、パスワードログインへの
  切替も再試行も自信を持ってできない。
- 改善アクション候補: フロントで 429 レスポンスを判別し「しばらく時間をおいて再試行するか、
  パスワードでログインしてください」等、状態別の文言に出し分ける。
- 証跡: screenshots/F-2-02-passkey-429.png、console: `[ERROR] Failed to load resource:
  the server responded with a status of 429 (Too Many Requests) @ .../passkeys/login/options`
  (2件)、network: 31,32 番目のリクエストが 429
- 推定原因: 未調査 (フロント側の passkey login エラーハンドラが status 別分岐をしていない可能性。5分調査では特定箇所未発見)
- 関連既知情報: 未調査

## 検証済み・finding にしないもの (要注意な挙動だが仕様確認済み)

### 「別 email の招待トークンをログイン中の別ユーザーが受諾できる」(token 経路)
- story/step: S2 逸脱 (別組織の招待トークンを自分のセッションで受諾)
- 検証内容: シャード三郎 の組織オーナーが `cross-tenant-target@example.com` 宛に招待を送信。
  この email を持たない **別の既存ユーザー (シャード二郎, email=shard2-newuser@example.com,
  自分の組織を別に保有)** がログイン中に招待リンク `/invitations/accept?token=...` を直接開き
  「招待を受諾する」を押すと、**email 不一致にもかかわらず受諾が成功し** シャード三郎の組織に
  「メンバー」として参加できてしまった (組織切替メニューに新規追加、`/manage/users` の
  メンバー一覧に「シャード二郎 / shard2-newuser@example.com」として掲載を確認)。
- 一次反応として Critical の認可バイパスに見えたが、`app/Http/Controllers/Organizations/
  InvitationAcceptanceController.php` のクラス doc コメントに
  「招待 email とログインユーザーの email の一致はログイン後経路では要求しない仕様。」と明記され、
  `docs/architecture.md` §招待受諾の2経路 (1003-1021行) に **token URL 経路の受諾根拠は
  「有効な招待 token の保持」のみ (email 照合はアプリ内経路だけの根拠)** という設計非対称が
  明文化されている (意図的な bearer-token 的挙動)。よって **finding にしない**。
- 証跡 (要確認用に保存): screenshots/F-2-03-cross-email-invite-preview.png,
  screenshots/F-2-03-cross-email-member-list.png
- 要確認として記録 (コード確認、ブラウザでは未実施): `OrganizationMembershipService::joinOrganization()`
  (同ファイル 402-432 行) は `$invitation->role` (招待作成時に指定された 2 値:
  `organization_admin` / `organization_member`) をそのまま `addRole` する。email 照合が無い
  token 経路と組み合わせると、**招待ロールが「管理者」だった場合、リンクを入手した任意のログイン中
  ユーザーがそのまま組織管理者になれる** (コード上そう読める。実ブラウザでの admin ロール招待の
  再現検証は時間の都合で未実施)。仕様として email 照合を要求しない判断そのものは
  `docs/architecture.md` に明記済みだが、admin 招待でのリスク増幅は明文化が見当たらない。
  severity は付けない (仕様確認済みの拡張のため)。app-design へ「admin 招待だけは token 経路でも
  email 照合を要求すべきでは」という再検討依頼として引き渡すのが妥当。

---

## Critical/High TODO 候補
なし (本 shard の走行では Critical/High の finding は検出しなかった。F-2-01 は Medium、F-2-02 は Low)。

## 要確認まとめ (仕様質問リスト。バグと混ぜない)
1. **招待受諾 token 経路の admin ロール email 非照合**: 招待ロールが「管理者」の場合でも token 経路は
   ログインユーザーの email 照合をしない設計 (`docs/architecture.md` 明記)。招待リンクが誤送信・転送
   された場合、第三者がそのまま組織管理者権限を取得できる。仕様として意図されているが、
   admin 招待に限り email 照合を要求する方向で再検討すべきでないか、app-design へ確認依頼。
   (詳細は上記「検証済み・finding にしないもの」節)

（以下 finding 詳細を見つけ次第追記。severity 降順で最終化する）
