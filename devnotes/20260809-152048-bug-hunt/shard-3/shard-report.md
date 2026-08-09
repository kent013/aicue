# bug-hunt report shard-3 (S4, S5) 20260809-152048

- 対象 URL: http://127.0.0.1:8013 (DB: bug_hunt_3, users=11)
- 実行ストーリー: S4 (組織/プロジェクト/カテゴリ/ユーザー管理), S5 (課金)
- --deviate: 有効 / --real-llm: 有効(ただし本ストーリーは LLM 生成を伴わない見込み)

## 画面カバレッジ
走行中 (逐次更新)

- S4 screens: [x] organizations.create, [x] organizations.settings, [x] organizations.api-keys.index, [x] organizations.api-keys.sessions.index, [x] organizations.onboarding.cli, [x] organizations.onboarding.mcp, [x] manage.users.index, [x] projects.index, [x] projects.create, [x] projects.edit, [x] projects.categories.index → 全11画面 走行済み
- S5 screens: [x] pricing (未ログイン/ログイン済み両方), [x] billing.index (契約済み/未契約 両方), [x] billing.plans (契約済み/未契約 両方), [x] billing.tickets.show(`/purchase-tickets`) → 全4画面 走行済み

## 操作カバレッジ

- S4 operations 実行済み: organizations.store(◯), organizations.update(◯ 空値バリデーション+正常系), organizations.switch(◯), organizations.transfer-ownership(◯ T044 stale-invalid解消確認済み), organizations.two-factor-requirement.update(◯ 自分の2FA未設定でガードされるエラー確認), organizations.api-keys.store(◯ 空値バリデーション+正常系+平文キー1回表示), organizations.api-keys.revoke(◯), projects.store(◯ 空値バリデーション+正常系), projects.update(◯), projects.destroy(◯ 確認ダイアログ+反映確認), projects.categories.store(◯ 空値+重複名バリデーション+正常系), projects.categories.update(◯), projects.categories.destroy(◯ 確認ダイアログ), projects.categories.reorder(◯ ▲▼で順序入替+反映確認), projects.members.store(未実施: 割当可能な非管理者メンバーが手元シードに残っていない。理由: 検証中に唯一のmemberロールをadminに変更したため), projects.members.destroy(◯ Multi Org Userを削除), projects.items.store(◯ 空値バリデーション+正常系), projects.items.update(◯), projects.items.destroy(◯ 確認ダイアログ)
- S4 未実行/skip: organizations.api-keys.sessions.revoke — 理由: OAuth接続セッションが本走行では発生しない(CLI/MCP実接続はスコープ外)ため一覧が常に空で対象が作れない。debug.login-as — `/debug/login`(GET)へ直アクセスすると404。ルート定義(routes/web.php)が`app()->isLocal() || runningUnitTests()`でガードされており、bughunt環境(APP_ENV=bughunt.local)では意図的に未登録(fail-safe設計)。バグではない。
- S5 operations 実行済み: billing.checkout(◯ 未契約組織でStarterへ新規契約試行、確認モーダル→fake Stripeへ遷移→中立帰還を確認。二重送信で「既に進行中のCheckoutがあります」警告を確認=冪等), billing.plan.change(◯ 契約中組織でStandard→Starterのin-appプラン変更、確認モーダル文言の違いを確認、変更不可プラン押下でdisabledでなくエラーメッセージ表示=禁止事項8非該当), billing.portal(◯ 契約中/未契約の両方で確認。未契約はerror flash、契約中はfake外部遷移→P9着地バナー「お支払い管理画面から戻りました。」を確認しリロードでone-shot消滅を確認), billing.tickets.checkout(◯ 空値/上限超過バリデーション+T041 stale-invalid即時解消+単価再計算を確認。fake Stripe遷移→「決済手続きが進行中です」resumeバナー→「新しく購入し直す」で復帰できることを確認), billing.contact.update(◯ 空値/不正メールバリデーション+正常系保存+リロード後反映+manageBillingなしmemberでUI読み取り専用+直PATCHで403を確認), billing.auto-recharge.update(◯ 閾値>上限のrange-error+即時解消+ドラフト保存(カード未登録でも保存可)+manageBillingなしmemberで直POST 403を確認), billing.auto-recharge.setup(◯ カード未登録時のCTA→fake Stripe setup checkout→cancel相当帰還後もCTAが残り詰まないことを確認)
- S5 未実行/skip: 「サブスク契約の実際の完了(webhook経由のplan_code反映)」「チケット購入の実残高増加」「P9 purchase_receivedバナーの実表示」は、bughunt環境のFakeStripeGateway/FakeTicketCheckoutGatewayが設計上webhookを一切発火させず状態変更もしない(コード注釈で明記)ため、この環境では原理的に検証不能。P9バナーのうちportal_returnedのみ観測できた。他組織/他ユーザーのattempt_tokenを使った404確認(P9冪等の一部)はコードレビューで実装を確認したが実機シナリオはトークン入手の都合でskip。

## UI/UX 検証
- H11(視覚破綻): 特に無し。desktop/tablet/mobileいずれもoverflow・重なりは未観測。
- H12(アフォーダンス): billing.plansの変更不可/現在契約中プランのCTAはdisabledにせず押下時にエラーメッセージを表示する設計(禁止事項8準拠、AGENTS.md非違反)を全パターンで確認。
- H13(レスポンシブ): manage/users(tablet 768x1024、screenshots/S4-manage-users-tablet.png)、billing.index(mobile 375x667、screenshots/S5-billing-mobile.png)、pricing(mobile、screenshots/S5-pricing-mobile.png)、billing.plans(tablet、screenshots/S5-billing-plans-tablet.png)で確認。いずれも横スクロール・要素はみ出し無し。T042(タブレット幅での名前truncate)はシードデータの氏名が短く再現条件を満たせなかった(要確認欄参照)。
- H14(a11y基礎): フォーム要素はrole/name/invalid状態がsnapshotから正しく取得でき、aria-invalid+エラーpの関連付けが機能。目立った欠落は無し。

## H7 未検証
0件。書き込み操作は全てfeedback probeで`installed_now:false`かつ`pending:0`かつ`errors:0`の状態で肯定的フィードバック(toast/alert)を確認できた。

## findings
Critical/High/Medium の確定findingsは0件。要確認2件のみ(下記)。

### 要確認-1: オーナー移譲の「パスワードの再確認が必要」表記と実際の挙動
- story/step: S4 (organizations.transfer-ownership)
- 再現手順: http://127.0.0.1:8013 に owner-standard@example.com / password123 でログイン直後、組織設定画面のオーナー移譲を実行する。
- 期待(UI文言): 「この操作にはパスワードの再確認が必要です。」
- 実際: パスワード再入力プロンプトは表示されず、確認ダイアログ(Yes/No)のみで即座に移譲が完了した。
- 調査: `app/Security/RecentAuthState.php` に recent-auth (step-up) の仕組みがあり、直近ログイン済みセッションでは再確認を省略する設計と読める(ログイン直後だったため grace window 内だった可能性が高い)。
- 判定: 設計通りの可能性が高く、バグと断定しない。ただし「本当に一定時間経過後は再確認を要求するか」は未検証(再現には長時間セッション維持か session の recent_auth_at 操作が必要で、wrapper 経由のDB操作しか許されない本走行では検証不能)。仕様意図の確認を推奨。
- severity: 要確認 (severityなし)

### 要確認-2: T042(タブレット幅での氏名truncate)がシードデータでは再現不能
- story/step: S4-4 (manage.users.index のタブレット幅表示)
- 再現手順: tablet 768x1024 で `/manage/users` を表示。
- 実際: シードされた氏名("Standard Owner"等)が短く、truncateが発生する条件(長い氏名)を作れなかった。目視ではレイアウト崩れは無い。
- 判定: バグではなく検証条件不足。長い氏名でのユーザー作成手段がbughunt環境の招待フロー(メール受諾)を要し、本shardの時間内では未実施。skipとして記録。
- severity: 要確認 (severityなし)

## skip リスト
- projects.members.store: 割当可能な非管理者メンバーが手元シードに残っていない(検証中にStandard Memberをadminへ変更したため)。理由: role変更バリデーションテストの副作用。
- organizations.api-keys.sessions.revoke: OAuthセッションが本走行では発生せず対象が作れない。
- debug.login-as: bughunt環境では意図的に未登録(routes/web.phpのisLocal()ガード)。バグではない。
- S5: サブスク/チケット購入の「完了」を要する検証全て: FakeStripeGateway/FakeTicketCheckoutGatewayが状態変更・webhook発火を一切行わない設計(コード注釈で明記)のため、この環境では原理的に検証不能。
- P9冪等の他組織/他ユーザーtoken 404確認: 実機シナリオ用のトークン入手手段が無く、コードレビュー(`abort_if($subscriptions->attemptTokenIsForeign(...), 404)`)で存在確認のみに留めた。
- organizations.members.destroy のオーナー保護のbackend直POST検証: UIでは自分自身/オーナー行に削除ボタンが表示されないことを確認したが、対象ユーザーIDをDOMから取得できず直POSTでの検証は未実施(billing.contact.update / billing.auto-recharge.update では同種の直POST検証を実施し403を確認済み)。

## インベントリ修正提案
特に無し。screens.md / operations.md は本走行の実機挙動と一致していた。
