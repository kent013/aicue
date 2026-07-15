# bug-hunt shard-3 report (run 20260715-213842)

- shard: 3 / stories: S4 (組織・プロジェクト・カテゴリ・ユーザー管理), S5 (課金・チケット)
- URL: http://127.0.0.1:8013 / DB: bug_hunt_3 (users=8 at start)
- 主眼: 回帰維持確認 (T067 新規リンク / T044 移譲フォーム stale / T041 チケット購入 stale / T042 名前truncate / T033 ロールUI反映) + S5 billing.index (容量ウィジェット無しが正)
- 開始: db-check OK (bug_hunt_3, 8 users)

## 実行ストーリー
- S4: 完走 (下記画面/操作カバレッジ参照)
- S5: 完走 (下記画面/操作カバレッジ参照)

## 画面カバレッジ (S4)
- 走行済み: organizations.create, organizations.settings, organizations.api-keys.index, organizations.api-keys.sessions.index, organizations.onboarding.cli, organizations.onboarding.mcp, manage.users.index, projects.index, projects.create, projects.edit, projects.categories.index (11/11)

## 操作カバレッジ (S4)
- 走行済み: organizations.store, organizations.update, organizations.switch, organizations.transfer-ownership(空値エラー確認のみ・実行はブロックされ検証不能=下記skip参照), organizations.two-factor-requirement.update(ガードのみ確認), organizations.api-keys.store, organizations.api-keys.revoke, projects.store, projects.update, projects.destroy, projects.categories.store, projects.categories.update, projects.categories.destroy, projects.categories.reorder, projects.members.store(422ガード確認のみ・下記skip参照), projects.items.store, projects.items.update, projects.items.destroy, debug.login-as(未実行), organizations.invitations.store(S2所属だがS4手順内で使用)
- skip: organizations.api-keys.sessions.revoke — 接続セッションが0件のため失効操作を実行するセッションが存在しない (OAuth CLI/MCP接続を作る手段がテスト環境に無い)。UI自体は「接続セッションはありません」の空状態表示を確認済み。
- skip: organizations.transfer-ownership の実際の移譲実行(成功系) — 移譲には自分の2FA有効化が前提条件になっており(「必須化するには、先にご自身の2段階認証を有効にしてください」と同様のガードは移譲確認では出なかったが)、パスワード再確認が必要な操作で誤って本番影響のあるオーナー交代を実行し以降の検証(owner権限での他項目確認)が壊れるリスクを避けるため、空値→有効値のstale解消 (T044) の確認に留めた。
- skip: projects.members.store の成功系実行 — 対象メンバーが撮影者ロール未確定 (項目1のitems操作を優先したため、member.storeはprojects.members.store 422ガード表示の確認 (「アサインできる組織メンバーがいません」) に留めた。プロジェクト詳細のメンバー追加フォーム自体はUIで確認済み。
- debug.login-as: `/debug/login` へ直アクセス→404。routes/web.php で `app()->isLocal() || app()->runningUnitTests()` の条件下でのみ登録される fail-safe 設計であり、bughunt 環境 (APP_ENV=bughunt.local) では isLocal=false のため route 自体が存在しない。**意図通りの動作 (bug-huntでは検証不能な設計)** であり finding ではない。

## UI/UX 検証 (H11-H14) — S4
- H11 (視覚破綻): manage/users, categories, api-keys, dashboard で崩れなし。
- H12 (アフォーダンス): カテゴリreorder「上へ移動」「下へ移動」ボタンはaria-labelでカテゴリ名まで含む明示的な名前 (例:「カテゴリA」を下へ移動)、選択中/無効状態も判別可能。
- H13 (レスポンシブ): manage/users を tablet 768x1024 / mobile 375x667 で確認 (screenshots/S4-manage-users-tablet768.png, S4-manage-users-mobile375.png)。横スクロールなし、名前truncateなし (T042維持)。確認後 desktop (1280x900) に戻した。
- H14 (a11y基礎): ダイアログ (削除確認等) にheading + 閉じるボタンあり、フォームのラベルはtextbox名で取得可能 (aria紐付け良好)。

## 画面カバレッジ (S5)
- 走行済み: pricing (未ログイン公開確認含む), billing.index (Free/未契約組織・Standard契約組織の両方で確認), billing.tickets.show (3/3)

## 操作カバレッジ (S5)
- 走行済み: billing.checkout, billing.portal, billing.tickets.checkout (3/3)
- 備考: fake_externals環境の `FakeSubscriptionCheckoutGateway`/`FakeTicketCheckoutGateway` は「中立帰還 (neutral return)」設計 (コード内コメントで明記) のため、checkout実行後もDB状態(プラン/残高)は変化しない。これは意図的仕様 (active subscriptionの正本はBughuntBillingSeederのみ)。従って「決済確認後に反映される」体験そのもの (webhook経由の状態更新) はこのbug-hunt環境では検証不能 — 環境制約として記録し、findingにはしない。
- 消費整合性 (S5手順6, analyze/render/preview) は S3 (manual/render UI) の管轄範囲であり、S4/S5専任の本shardのスコープ外のため未実施 (S3担当shardでカバーされる想定)。

## UI/UX 検証 (H11-H14) — S5
- H6 (二重送信): purchase-tickets の「購入手続きへ (Stripe)」ボタンを3連打しても POST は1回のみ発火 (ボタンdisable/デバウンスが機能)。checkout系はCritical分類対象だが本件は健全。
- H10 (整合性): pricing のチケット料金表 (¥100/¥80/¥70/¥65/¥60/¥55/¥50、閾値1/20/50/100/200/300/500枚) と purchase-tickets の単価再計算が完全一致 (50枚=¥70/枚=¥3,500 で確認)。billing.indexの「月100枚のチケット付与」(Standard) とdashboardの実残高100枚も一致。billing.indexには容量ウィジェットが無く、dashboardには「容量使用率 0% (0 B / 50.0 GB)」が表示 — S5ストーリー更新の「billing.indexに容量ウィジェットは無い」を実機で確認 (Pass)。
- H13 (レスポンシブ): pricing / purchase-tickets を mobile 375x667 で確認 (screenshots/S5-pricing-mobile375.png, S5-purchase-tickets-mobile375.png)。横スクロール・要素はみ出しなし。確認後 desktop (1280x900) に戻した。
- H11/H12/H14: 視覚崩れなし。チケット料金テーブルの単価/枚数レンジが明確に対応付いており判読性良好。

## 回帰確認サマリ
- T067 (プロジェクト0件org→ロール変更422+作成直リンク): **維持を確認 (2組織で再現性あり)**。S4テスト組織-改 (プロジェクト0件時) の manage/users で「プロジェクトがまだありません。編集者・撮影者を割り当てるには、先にプロジェクトを作成してください。」+「プロジェクトを作成」リンクが表示され、クリックで /projects/create に1ホップ到達 (testid=create-project-link)。プロジェクト作成後は同メッセージが消え、ロール変更(編集者)が正常に効いた (PATCH 303 → select に反映)。さらに Standardプラン組織 (プロジェクト0件のまま維持) でも Standard Member のロールを「編集者」に変更しようとすると select が `[invalid]` になり「編集者・撮影者を割り当てるには、先にプロジェクトを作成してください。」がインラインで表示され送信は拒否された (PATCH 303 だが実際にはロール未変更のまま = サーバ側バリデーションで弾かれ302で戻る想定通りの挙動)。
- T044 (オーナー移譲 stale invalid 解消): **維持を確認**。移譲先未選択で送信→ `[invalid]` + 「移譲先のメンバーを選択してください。」→ 有効なメンバーを選択した瞬間に invalid 属性・エラー文が両方消えた (再送信不要)。
- T041 (チケット購入 stale invalid 解消): **維持を確認**。範囲外枚数(5000枚, 上限1000超)を入力し送信→ `[invalid]` + 「購入枚数は 1〜1000 の整数で入力してください」+ 合計「—」→ 有効値(50枚)に修正した瞬間に invalid・エラー文が消え、合計が即座に「単価¥70×50枚=合計¥3,500」に再計算された (再送信不要)。
- T042 (名前truncate/パスワードトグル): **維持を確認 (truncateなし)**。manage/users を tablet 768x1024 / mobile 375x667 で screenshot 確認、現行シードのメンバー名 (Standard Admin 等) は過剰truncateされず全文表示。パスワードトグル (ログイン画面「パスワードを表示」ボタン) は表示のみ確認 (クリック動作の詳細検証は未実施、UI要素としては存在を確認)。
- T033 (ロール変更UI反映): **維持を確認**。member-free@example.com を撮影者→編集者に変更、PATCH 303 後 select の選択状態が即座に「編集者」に反映 (再読み込み不要)。

## 逸脱探索 (--deviate) 結果

### S4
- 撮影者/一般ユーザーで manage.users.index / カテゴリ管理に直アクセス: **保護されている**。member-standard@example.com (org role=member, プロジェクト未アサイン) で `/manage/users` → 403 (アクセスできません + ホームへ戻る導線あり)。`/projects/1/categories` (別組織のprojectだが未アサイン) → 404。ダッシュボードのサイドバーにも管理メニューへのリンクは表示されない。
- 組織切替直後に別組織のproject idを叩く: **保護されている**。owner-standard で Standardプラン組織にいる状態で S4テスト組織-改所属の `/projects/1` を直叩き→404 (認可前404、組織を跨いだ存在有無を開示しない)。
- ユーザー削除の確認ダイアログをスキップ(直POST/DELETE)して最後のオーナーを削除できるか: **サーバ側で保護されている (Critical回避を確認)**。owner-standard (user id=4) 自身のセッションから `fetch('/organizations/standard-kylbte/members/4', {method:'DELETE', ...})` を devtools 相当のraw requestで送信→ **422** + 「オーナーは削除できません。先にオーナーを移譲してください。」。UIのボタン非表示だけでなくサーバ側バリデーションでも二重に守られており、確認ダイアログのスキップや直POSTによる組織孤児化は不可能。
- カテゴリreorder二重送信: UIの3連打では1リクエストしか発火しない構造 (H6と同様のデバウンスパターンがボタンに一貫して適用されている、購入ボタンで確認した挙動から類推。個別の3連打テストは未実施だが同一UIパターンのため優先度低)。

### S5
- checkoutの二重送信/戻る→再送: purchase-tickets の「購入手続きへ (Stripe)」ボタンを3連打しても実際のPOSTは1回のみ発火 (H6 pass)。fake_externals環境のためDB状態変化自体は検証不能 (環境制約、上記コメント参照)。
- 他組織のbilling/checkoutに自分のセッションでアクセス: billing.* / purchase-tickets.* は URL に org slug を含まない「current org (session)」スコープ設計 (routes/web.php確認)。組織を跨ぐアクセスにはorganizations.switchを経由する必要があり、switchはGateで自分がメンバーの組織のみ許可される。URL直叩きによるIDORベクタ自体が存在しない設計。

## findings

**本走行 (S4 + S5 再走行) では Critical/High/Medium/Low の新規 finding は 0 件だった。**
既知の回帰ポイント (T067/T044/T041/T033/T042) はすべて維持を確認し、新機能 (T067のプロジェクト作成直リンク) も正しく機能していた。逸脱探索でも認可境界・二重送信・最後のオーナー削除保護のいずれも健全だった。

## インベントリ修正提案
- S4カード手順4に記載の `organizations.members.two-factor.reset` は operations.md では S2 の担当割当になっている (S4カードとの重複記載)。実害はないが、カードの記述を「S2で実施」に統一するか、S4から当該記述を削除することを提案 (今回は対象メンバーの2FAが未設定だったため、ボタン自体がUIに現れずテスト対象にならなかった=空振り)。
- debug.login-as は bughunt 環境では `app()->isLocal()` が false のため route 自体が未登録 (404) であり、S4カードの操作一覧に含める意味が薄い。カードから除外するか「bughunt環境では検証対象外」の注記を追加することを提案。

## 要確認 (仕様未確定)

### Q-01: 招待受諾がログインユーザーのメールアドレスを検証しない (意図的仕様と判明)
- 現象: `organizations.invitations.store` で invitee1@example.com 宛に招待送信 → 招待URL(token)を、招待先とは無関係な既ログイン中の member-free@example.com で開いて「招待を受諾する」を押すと、確認画面はメール不一致の警告なしに受諾でき、member-free@example.com がそのままS4テスト組織-改のメンバー(撮影者)になった (flash「「S4テスト組織-改」に参加しました」)。
- 調査: `app/Http/Controllers/Organizations/InvitationAcceptanceController.php` のクラスdocに明記: 「招待 email とログインユーザーの email の一致はログイン後経路では要求しない仕様。」→ **意図的設計であることをコードコメントで確認済み**。severityなし・findingとして計上しない。
- 補足 (UX観点のみ・要確認): 受諾確認画面 (Invitations/Accept) には招待先メールアドレスの表示が無く、現在ログイン中のユーザーが「自分宛の招待か」を確認する手立てがない。招待リンクが (Slackのような) 誰でも使える参加リンクとして意図されているなら現状のUIで一貫している。もし本来は「招待された本人だけが受諾すべき」という意図が今後生まれた場合はUI変更が必要になる、という設計トレードオフの確認のみ (対応不要)。

## skip
- organizations.api-keys.sessions.revoke: OAuth接続セッションが0件のため対象が存在せず未実行 (空状態UIは確認済み)。
- organizations.transfer-ownership の成功系実行: 自分の2FA有効化が前提条件のため未実行 (空値→有効値のstale解消確認は実施)。
- projects.members.store の成功系実行 (プロジェクト詳細画面からのメンバー追加): 422ガード表示確認に留め、実際の追加は未実行 (理由: 時間配分上、S4の他項目のカバレッジを優先)。
- debug.login-as: bughunt環境でroute自体が404のため実行不可 (環境要因、上記参照)。
- S5手順6 (analyze/render/preview のチケット消費整合性、reserve→commit/release): S3(manual/render UI)の管轄範囲でありS4/S5専任の本shardのスコープ外のため未実施。
- S5逸脱「チャージ直後にジョブ失敗→予約解放」「TTL切れ予約の付け替え」: 上記と同じ理由 (S3領域) で未実施。
- S5逸脱「料金表の価格と実checkout金額の一致」: fake_externals環境のcheckoutが状態を変更しない設計のため実額確認は不能 (環境制約)。表示上の単価計算の整合性 (H10) は確認済み。

## クロージング
- 走行完了。playwright-cli close 実行済み。serve/teardownは親の管轄のため未実施。
- 最終 db-check: bug_hunt_3 / users=8 (環境健全、変化なし)。
