# bug-hunt report shard-3 (run 20260812-100645)
- 対象 URL: http://127.0.0.1:8013
- 担当ストーリー: S4 (org-project-management) → S5 (billing)
- 実行ストーリー: S4 完走 / S5 完走 (--deviate 含む)
- skip したステップ:
  - `debug.login-as`: bughunt env (`APP_ENV=bughunt.local`) では `app()->isLocal()` が false のため
    route 自体が未登録 (`GET /debug/login` → 404 実測)。意図的な fail-safe (LocalOnly + env boot 判定)
    による構造的検証不能。コード参照: routes/web.php:675-679
  - `organizations.members.two-factor.reset`: ManualTestSeeder のテストユーザーは誰も 2FA 未設定
    (twoFactorStatus !== "enabled") のため、UI 上のボタン自体が出現しない
    (resources/js/pages/Admin/Users.svelte:211 の条件レンダー)。構造的検証不能。
  - `organizations.api-keys.sessions.revoke`: OAuth 接続セッションは CLI/MCP からの実 OAuth
    round-trip でのみ作成され、bug-hunt からは作れない (空状態を確認しただけ)。構造的検証不能。

## 画面カバレッジ
S4 screens (11/11 走行済み): organizations.create ✓, organizations.settings ✓,
organizations.api-keys.index ✓, organizations.api-keys.sessions.index ✓, organizations.onboarding.cli ✓,
organizations.onboarding.mcp ✓, manage.users.index ✓, projects.index ✓, projects.create ✓,
projects.edit ✓, projects.categories.index ✓
S5 screens (4/4 走行済み): pricing ✓ (未ログイン/ログイン済み両方、D28 月次付与文言の非再発を確認)、
billing.index ✓ (owner/admin/member 各ロールで確認)、billing.plans ✓、billing.tickets.show ✓

## 操作カバレッジ
S4 operations (19/20 実行、1 skip):
organizations.store ✓, organizations.update ✓, organizations.switch ✓,
organizations.transfer-ownership ✓ (T044 stale-invalid 解消も確認), organizations.two-factor-requirement.update ✓
(前提未達で拒否パスのみ確認 = 自身の2FA未設定時は必須化不可という正しい前提チェック),
organizations.api-keys.store ✓, organizations.api-keys.revoke ✓,
organizations.api-keys.sessions.revoke = skip (構造的検証不能、上記参照),
projects.store ✓, projects.update ✓, projects.destroy ✓, projects.categories.store ✓,
projects.categories.update ✓, projects.categories.destroy ✓, projects.categories.reorder ✓,
projects.members.store ✓, projects.members.destroy ✓, projects.items.store ✓, projects.items.update ✓,
projects.items.destroy ✓, debug.login-as = skip (構造的検証不能、上記参照)
参考 (S2 帰属だが manage.users.index 画面上で実施): organizations.members.destroy ✓,
organizations.invitations.store ✓ (バリデーション込み)。organizations.members.two-factor.reset は skip (上記)。
S5 operations (6/6 初期化まで実行、うち2件は fake gateway 由来で完了確認は構造的検証不能):
billing.checkout ✓ (初期化は確認。新規サブスク完了状態は fake gateway が「中立帰還」設計のため
  未検証 = 構造的検証不能、下記参照)、
billing.portal ✓ (成功 + 未契約組織での graceful error flash の両方確認)、
billing.tickets.checkout ✓ (初期化 + T041 stale-invalid 解消を確認。購入完了の残高反映は同様に
  構造的検証不能)、
billing.contact.update ✓ (バリデーション・保存・member への 403 スコープ確認。**F-3-02 stale-invalid finding あり**)、
billing.auto-recharge.update ✓ (member による直 POST が 403 で拒否されることを確認)、
billing.auto-recharge.setup ✓ (カード登録 Checkout 初期化 + cancel 後も CTA が残ることを確認)

## billing 決済 fake 由来の構造的検証不能 (skip)
- 新規サブスク checkout (`billing.checkout` の未契約組織) の購入完了後の反映: `FakeStripeGateway::createSubscriptionCheckout`
  が `FakeExternalUrl::neutralReturn($cancelUrl)` で常に「中立帰還」する設計 (webhook 由来の状態反映は
  fake 環境では発火しない、と docblock に明記)。実際に owner-personal で Starter への新規契約を試行したが
  プランは Personal のまま変化せず、成功/失敗いずれのフィードバックも出なかった (H3 的に見えたが、
  コード調査の結果これは意図的な fake gateway の限界と判断し finding にしない)。
  既存サブスクからの**プラン変更** (billing.plan、Stripe Checkout を経由せず即時 swap) は
  正しく "プラン変更を受け付けました" のトースト付きで機能する (これは別経路のため正常に検証できた)。
- チケット購入完了後の残高反映: 同じ neutral-return 設計のため、購入完了トースト
  (`billing-feedback-purchase_received` 等) の発火は今回確認できず。初期化 (バリデーション・
  Stripe への遷移トリガー) のみ確認済み。
- オートリチャージのカード登録完了: 同上 (`billing.auto-recharge.setup` の Checkout も neutral-return)。
  カード未登録のままの状態遷移 (CTA が残ること) のみ確認。

## UI/UX 検証
- H13 (レスポンシブ): `/manage/users` を mobile 375×667 / tablet 768×1024 で確認。
  T042 の「タブレット幅で名前が過剰 truncate」は再現せず、崩れ・重なりも無し
  (screenshots/S4-manage-users-tablet.png, S4-manage-users-mobile.png)。desktop (1280x900) に復帰済み。
- H11/H12/H14: S4 全画面で snapshot ベースの role/name/state 取得は問題なし。ボタン disabled 状態や
  フォームラベルの欠落は見られなかった。目立った視覚破綻・アフォーダンス不明瞭は無し。
- H13 (レスポンシブ): `/billing` (member 読み取り専用ビュー) を mobile 375×667 / tablet 768×1024 で確認。
  カード積み重ねが崩れず横スクロールも無し (screenshots/S5-billing-mobile.png, S5-billing-tablet.png)。
  desktop (1280x900) に復帰済み。

## verified: 誤検知回避 (5分調査で解消したもの)
- **招待リンクのメールアドレス不一致許容**: 招待は `shard3-invitee@example.com` 宛だが、
  別ユーザー (`owner-personal@example.com`, 既ログイン中) が同じ招待トークンで組織に参加できた。
  最初は IDOR/認可漏れを疑ったが、`app/Http/Controllers/Organizations/InvitationAcceptanceController.php:21-23`
  に「招待 email とログインユーザーの email の一致はログイン後経路では要求しない仕様」と明記されており、
  意図的な設計 (裁定 AG-113 は in-app 経由の受諾のみ email 一致を要求。token 経由はトークン所持自体が
  認可根拠)。**finding にしない**。
- **オーナー移譲でパスワード再確認 UI が出ない**: 文言は「この操作にはパスワードの再確認が必要です」だが、
  実際には確認ダイアログのみで password 入力欄は出ずそのまま移譲成功した。
  `GET /recent-auth/status` が事前に呼ばれており、ログイン直後で recent-auth window 内だったため
  step-up がスキップされたと判断 (`app/Security/RecentAuthState.php` 系)。時間経過後の再検証は
  today's run では未実施 (recent-auth window 満了を人為的に作れないため) — **要確認ではなく設計内**と
  判断したが、window 満了後に本当に password 入力が出るかは今回未検証。
- **新規サブスク checkout 後にプランが変化せずフィードバックも無い**: 「billing 決済 fake 由来の
  構造的検証不能」節を参照。`FakeStripeGateway`/`FakeTicketCheckoutGateway` が意図的に
  「中立帰還 (webhook 非発火)」設計であることをコードで確認したため finding にしない。
- **billing.auto-recharge.update への直 POST が最初 422 で返り 403 に見えなかった**: 送信した
  payload のフィールド名 (`threshold`/`target`) が実際のフォームの `threshold_count`/`max_count` と
  異なっていたための誤検知。正しいフィールド名で再送すると期待どおり 403 (member は権限無し) だった。

## findings
Critical: 0 / High: 0 / Medium: 1 / Low: 0 / 要確認: 1 (下記)

### F-3-02: 請求先情報フォームのメールアドレス invalid 表示が、値を修正しても再送信するまで消えない (stale invalid)
- severity: Medium
- story/step: S5-8 (billing.contact.update)
- 再現手順:
  1. `owner-standard@example.com` でログインし `http://127.0.0.1:8013/billing` を開く
  2. 「請求先情報」フォームのメールアドレス欄に不正な値 (例: `not-valid-email`) を入力し「請求先情報を保存」を押す
     → 「請求先メールアドレスは、有効なメールアドレス形式で指定してください。」エラーが表示される (ここまでは正しい)
  3. 保存ボタンを押さずに、メールアドレス欄を有効な値 (例: `another-valid-fix@example.com`) に書き換える
  4. blur / 他フィールドへフォーカス移動しても、[invalid] 属性とエラー文言が消えない
     (screenshots/F-3-02-billing-contact-stale-invalid.png)
- 期待: 同ストーリーの他操作 (organizations.transfer-ownership の移譲先選択、purchase-tickets の枚数入力
  = T041/T044 で明示的に要求されているパターン) と同様、**修正した時点で invalid 表示が即座に解消**される
- 実際: 値を有効な内容に修正しても表示は invalid のままで、実際に「請求先情報を保存」を再度押して
  サーバから新しい応答が返るまでエラーが残り続ける (見た目上「まだ無効な値のまま」に見え、
  ユーザーは既に直したことに気付きにくい)
- 阻害されたユーザージョブ: 請求先メールアドレスを訂正した後もエラー表示が残るため、ユーザーが
  「まだ直っていない」と誤解し、再度余計な確認や無駄な再入力をしてしまう可能性がある
  (実害は無く、実際に保存ボタンを押せば正しく保存されて解消するため実質的な詰みではないが、
  T041/T044 で確立された「stale invalid はその場で消す」という同一アプリ内の期待と矛盾する = H10 寄りの UX 不整合)
- 改善アクション候補: `resources/js/components/features/billing/BillingContactForm.svelte` の
  `emailError` / `nameError` の `$derived` が `inertiaPage.props.errors`(サーバ由来) だけを見ており、
  ローカルの入力変更で invalid を追従してクリアする仕組みが無い。値を変更した時点で該当フィールドの
  エラーをローカルにクリアする (例: `bind:value` の変更をトリガに emailError を null 化する派生ロジックを足す)
- 証跡: screenshots/F-3-02-billing-contact-stale-invalid.png、
  feedback-probe は無関係 (インライン validation のため probe 対象外)
- 推定原因: `resources/js/components/features/billing/BillingContactForm.svelte:35-36`
  (`emailError`/`nameError` が `!submitting` の間だけ server errors をそのまま表示し、
  再送信サイクルを経ないと消えない)
- 関連既知情報: T041 (チケット購入枚数)・T044 (オーナー移譲) は同種の stale-invalid 解消が
  ストーリーカードで明示要求され実装済み (今回 T044 は実機で解消を確認済み)。本フォームだけ
  この設計原則から外れている可能性がある。

### F-3-01: 組織メンバー DELETE エンドポイントが権限不足時に 403 (存在確認済み) を返す (要確認)
- severity: 要確認 (仕様未確認のため severity 未確定)
- story/step: S4-4 (逸脱: 確認ダイアログのスキップ / 直 POST)
- 再現手順:
  1. `owner-standard@example.com` でログインし Standardプラン組織にいくつかの他メンバーがいる状態にする
  2. `member-standard@example.com` (member ロール、`manage.users.index` に到達不能) でログイン
  3. ブラウザ console で `fetch('/organizations/standard-4rq9i4/members/{id}', {method:'DELETE', headers:{'X-CSRF-TOKEN':<token>}})` を id=1..10 で連番実行
- 期待: 対象 org に存在しない id / 存在するが権限外の id のどちらも同一の応答 (404 or 一律 403) で
  差が出ない (存在オラクルを漏らさない、既存の招待トークン設計と同じ思想)
- 実際: 存在しない id (1-6) は 404、org に実在するが自分に操作権限が無い id (7-10) は 403 と、
  応答コードが分かれる → member ロールでも「どの user id が同一組織に実在するか」を推測できる
  (member は本来 `manage.users.index` に到達できず一覧を見られないはずなので、この差分は弱い情報漏洩)
- 阻害されたユーザージョブ: 直接の実害は無い (削除自体は 403 で確実にブロックされている)。
  info-disclosure としての深刻度は低い (同一組織内の user id の存在有無のみ、対象は同一組織メンバーに限定)
- 改善アクション候補: 招待トークンと同様に、権限判定を先に行い一律 403 (または一律 404) に畳む設計を
  検討 (Policy を先に評価してから scoped binding するか、binding 失敗と Policy 失敗を同じ HTTP status に畳む)
- 証跡: console 実行結果 (このレポート本文中に記録。screenshot 無し、fetch ベースのため)
- 推定原因: `{organization:slug}/members/{user}` の scoped route model binding が先に解決され
  (`whereKey` で 404 化)、Policy 認可はその後に評価されるため。多くの Laravel アプリで一般的なパターン。
- 関連既知情報: 招待トークン系 (`AcceptInvitationInAppController`) は明示的に「404 に畳んで存在オラクルを
  防ぐ」設計思想が docblock にあり、本エンドポイントは同じ思想を採用していない (一貫性の観点で要確認)。

## H7 未検証
0 件 (すべての書き込み操作で probe による陽性/陰性判定を取得できた)

## インベントリ修正提案
- 特になし (screens.md / operations.md と実際の route/画面の乖離は見つからなかった)。
- 参考: `organizations.members.destroy` / `organizations.members.two-factor.reset` /
  `organizations.invitations.store` は operations.md 上 S2 帰属だが、S4 ストーリーカードの手順 4 が
  `manage.users.index` 画面上でこれらの操作も試すよう明記している。今回は両方実施したが、
  カバレッジ表記の二重帰属について将来的に整理してもよいかもしれない (バグではなく整理の提案)。

## Critical/High サマリ (TODO 候補)
今回の走行で Critical/High の finding は 0 件だった。

## 要確認サマリ (仕様確認の質問リスト)
- F-3-01 (組織メンバー DELETE の 403/404 応答差): member ロールでも同一組織内の user id 存在有無が
  弱く推測できる。招待トークン系と同じ「存在オラクルを漏らさない」思想を members エンドポイントにも
  適用すべきか、それとも同一組織内では許容範囲という判断か、設計者の確認を推奨。

## 走行完了
- S4・S5 とも screens/operations は完走 (skip は構造的検証不能の 3 件のみ、理由明記済み)。
- --deviate: S4 (非管理者の直アクセス拒否・直 POST での認可バイパス試行) / S5 (fake gateway 限界の
  切り分け含む) を実施。IDOR/認可漏れの実害は見つからなかった。
- playwright-cli セッション (bughunt3) は close 済み。

---
(以下 finding 詳細を severity 降順で逐次追記)
