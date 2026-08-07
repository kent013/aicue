# 実査ブリーフ: auth-invitation-in-app-discovery

> lctl 台帳 (feature id: `auth-invitation-in-app-discovery`) の正典設計と aicue の実コードを突き合わせた調査結果。
> 2026-08-07 の実査。設計フェーズの入力であり、設計そのものではない。

## 序列 (候補 8 件中)

- 順位: #4
- 想定 TODO タイトル: アプリ内からの招待受諾経路を追加
- テーマ / 優先度 / モード: backend / Medium / standalone
- value=7 effort=6 self_contained=True
- 前回セッションで見送った理由: 「通知は届くが受諾できない」= 発見面が行き止まりという明確な UX の詰み (基準 2) で、存在秘匿の 404 畳み込みも設計として正しい。ただし現状に認可漏れや存在オラクルが在るわけではなく (新入口を作るときに初めて生じるリスク)、基準 1 での寄与は上位 3 件に劣る。加えて同じ AG-079 が要求する「役割付き招待 (project_role) の撤去」が未着手で app/Models/OrganizationInvitation.php と joinOrganization() を共有するため、同一 worktree でまとめて扱うのが安全 = 今回の 3 並行バッチには入れづらい (基準 4)。gate 登録先が 4 つ (NestedRouteDefenseInventory / RouteBindingTypes / ControllerAuthorizationGateTest / MembershipWriteLockInventoryTest) あり effort 6 も重い。

## 設計で最初に決めるべき論点

通知 payload に招待 id を持たせるか否かを先に決める。持たせると InAppNotificationTypeInvariantTest / NotificationTypeTsSyncInvariantTest / NotificationSchemaTest と resources/js/types/notification.ts の同期が連鎖し、取消・期限切れ後の stale な通知から 404 へ畳む設計も要る。持たせない場合は「認証ユーザー宛の有効 pending 集合」を返す scopeActivePendingForEmail 単独を解決口にして一覧から受諾する形になる。どちらでも受諾解決と件数/一覧算出が同一 scope を再利用する構造だけは崩さない。

## 台帳が確定させた標準形

標準形 v1 は、受諾の根拠を「署名 URL の所持」から「auth 済み ∧ email 確認済み ∧ ログイン者 email = 招待宛先」へ移した第 2 の受諾入口を、既存 token 経路と置き換えずに並べて持つこと。必須 3 点は委ねない — (a) 存在秘匿: 宛先不一致・不在 id・期限切れ・削除済み組織宛は一律 404 (403 を返さない)、受諾中の例外も席満杯/組織削除のような回復可能な業務エラー以外は既定で 404 へ畳む、(b) 受諾の解決と件数/一覧の算出で同一の絞り込みを再利用、(c) 未ログイン・未 verified・email 空は DB を引かない。招待行→membership の lock 付き変換本体は署名経路と 1 本を共有する。発見面の見せ方 (バナー/アプリ内通知) は各リポジトリに委ねる。

## aicue の現状 (実在確認済み)

受諾は署名 token 経路のみ。`/workspace/app/Http/Controllers/Organizations/InvitationAcceptanceController.php` (GET `invitations.accept` は guest 可・POST `invitations.accept.store` は auth のみ、いずれも `routes/web.php` 609-616 行) が `OrganizationMembershipService::acceptInvitation($plainToken, $user)` を呼ぶ形で、クラス docblock に「招待 email とログインユーザーの email の一致はログイン後経路では要求しない仕様」と明記されている。発見面は既存: `app/Notifications/InApp/InvitationReceivedNotification.php` + `app/DataTransferObjects/Notification/InvitationReceivedPayload.php` (payload は organization_name のみ、招待 id も平文 token も含まない)、発火は `app/Services/Notification/NotificationCenterService.php::notifyInvitationReceived()` (`User::whereBlind('email','email_index',$invitation->email)` で既存ユーザーのみ)。UI は `resources/js/components/features/notifications/NotificationListItem.svelte` が「メールの受諾リンクから参加してください」と本文表示し、`app/Http/Controllers/NotificationController.php` の open() は `NotificationType::InvitationReceived` を 303 で notifications.index へ戻し `info` flash「招待はメールの受諾リンクから参加してください。」を出す = 発見しても受諾できない行き止まり。`app/Models/OrganizationInvitation.php` は CipherSweet の `email` + blind index `email_index` と `scopeActive()` (accepted_at null / revoked_at null / expires_at > now) を持つが、email で絞る scope は無い。共有 lock 付き変換 `OrganizationMembershipService::joinOrganization()` は既に acceptInvitation / acceptInvitationIfValid の 2 経路が共有し、`MembershipWriteLockInventoryTest` の delegatedToLocked に登録済み。`rg` 実査で accept-in-app / AcceptInvitationInApp / pendingInvitationCount / PendingInvitationsBanner / scopeActivePendingForEmail のヒットは 0 件、`app/Support/Auth/` は EmailVerificationContinuation.php のみ。

## ギャップ

1. メール URL を根拠にしない受諾 route / Controller (aigenba の POST /invitations/{invitation}/accept-in-app 相当) が存在せず、ログイン済みユーザーがアプリ内から受諾する経路が 1 本も無い。
2. OrganizationInvitation に「認証ユーザー宛の有効 pending 集合」を返す scope (scopeActivePendingForEmail 相当 = whereBlind(email_index) + scopeActive + 組織実在) が無く、存在秘匿判定の単一解決口がない。
3. 発見面から受諾へ到達する導線が無い (NotificationController::open が InvitationReceived をメール誘導 flash で notifications.index へ戻すだけ、payload に招待 id も無い)。
4. 受信者視点の DTO (PendingInvitationForUserDto 相当) が無く、開示項目の契約が未定義 (既存 InvitationRowData は管理者視点)。
5. 件数/一覧の算出と受諾の解決が同一 scope を再利用する構造が無い (HandleInertiaRequests の共有 prop は notifications.unreadCount のみ)。
6. 存在秘匿 (宛先不一致・不在・期限切れ・削除済み組織宛をすべて 404 に畳む) を固定する Feature テストと、新 route の Architecture gate 登録が無い。

## 想定スコープ

新規: app/Http/Controllers/Organizations/AcceptInvitationInAppController.php、app/DataTransferObjects/Invitations/PendingInvitationForUserDto.php、tests/Feature/Invitations/AcceptInvitationInAppTest.php (存在秘匿の 404 網羅)、受諾ボタンを持つ Svelte 面 (features/invitations/ 配下の新 component、または Notifications/Index への行内アクション) + tests/js の component テスト。変更: routes/web.php (POST invitations.accept-in-app を追加。名前が `invitations.` 始まりのため ThrottleCoverageInventoryTest の S3 に入り throttle 1 本必須。既存 invitations.* と同じく require-active-subscription group の外へ置く)、app/Models/OrganizationInvitation.php (scopeActivePendingForEmail 追加)、app/Services/Organization/OrganizationMembershipService.php (joinOrganization を再利用する新 public メソッド)、app/Http/Controllers/NotificationController.php (InvitationReceived の open 先を受諾可能面へ)、resources/js/components/features/notifications/NotificationListItem.svelte (「メールの受諾リンクから」文言)。gate 登録 (deny-by-default のため必須): tests/Support/Routing/NestedRouteDefenseInventory.php に `{invitation}` => ManualOwnerScopedResolution を追加 (母集団は 1 param 以上の named route)、app/Http/Routing/RouteBindingTypes.php の MANUALLY_RESOLVED に route 名を追加 (TenantBoundaryOrderingTest 検査3a の条件 2)、tests/Architecture/ControllerAuthorizationGateTest.php の controllerAuthorizationExemptions() に SelfScopedResource + 30 字以上の理由で追加、tests/Architecture/MembershipWriteLockInventoryTest.php の delegatedToLocked に新メソッド追加。Inertia の GET 面を新設するなら config/seo.php の app_titles にも追加 (DocumentTitleCoverageTest)。参考にした gate の書き方: tests/Architecture/ControllerAuthorizationGateTest.php (route 全走査 → 認可マーカー無しは inventory 必須 / stale key 検出 / 理由 30 字以上 / 空振り floor) と tests/Support/Routing/NestedRouteDefenseInventory.php (param 単位分類 + 非テナント宣言は理由必須)。

## リスク

(1) NotificationController::open の InvitationReceived 分岐を変えると tests/Feature/Notifications/NotificationCenterTest.php 系の既存アサーションが割れる。(2) 通知 payload に招待 id を足す設計にすると InAppNotificationTypeInvariantTest / NotificationTypeTsSyncInvariantTest / NotificationSchemaTest と resources/js/types/notification.ts の同期が必要になり、かつ stale な通知 (取消・期限切れ後) から 404 へ畳む設計が要る。(3) 新 route は `{invitation}` を implicit binding させると不在 id だけ binding 段 404 になり「実在の他人宛 = 後段短絡」という 1 bit の存在オラクルになる (TenantBoundaryOrderingTest 検査3a が検出) ため、必ず手動解決にする。(4) 新 route を verified 必須にすると、既存 invitations.accept.store が意図的に verified を要求しない設計と非対称になる (並存が裁定なので docs/architecture.md 側の説明追記が要る)。(5) 名前が `invitations.` 始まりなので throttle 未付与だと ThrottleCoverageInventoryTest が fail し、かつ exemption 枠は exact fit (cap=25) のため throttle を貼る以外の逃げ道が実質無い。(6) 実行時間の増加は Feature テスト数本分で軽微。

## 実装者への申し送り (台帳と実コードの食い違いを含む)

台帳と実コードの食い違い 2 点。(1) auth-invitation-flow の aicue セル (2026-08-06 再確認) が「未ログイン時に招待を継続する仕組みが不在 (実査で該当ディレクトリ自体がない)」としているが、aicue には SessionInvitationToken クラスが無いだけで継続機構そのものは実在する — InvitationAcceptanceController::show() が session に `invitation_token` を put し、app/Actions/Fortify/CreateNewUser.php (130/146/153 行) と app/Rules/MatchesInvitationEmail.php と OrganizationMembershipService::resolveRegisterPrefillEmail() が fail-secure に消費する。無いのは標準形のクラス配置であって能力ではない。(2) app/Http/Controllers/NotificationController.php の class docblock が「1 param ルートのため NestedRouteIdorDefenseTest の inventory 対象外」と書いているが、これは stale — NestedRouteDefenseInventory は母集団を 1 param 以上へ拡大済みで、notifications.open / notifications.read は 'notification' => ManualOwnerScopedResolution として実際に登録されている (tests/Support/Routing/NestedRouteDefenseInventory.php 129-130 行)。新 route を書くときはこの docblock を信じないこと。あわせて: aicue に席上限 (SeatAvailabilityService 相当) は無く、config/quota.php の max_storage/max_members のうち max_members は「現在強制されていない (QuotaService::check の呼び出し元が無い)」と config 自身がコメントしているので、標準形が言う「席満杯は flash 付き redirect」分岐は aicue では現時点で発生せず、既定の 404 畳み込みだけを実装すればよい。並行作業の注意: 同じ AG-079 が aicue に対して「役割付き招待 (organization_invitations.project_role とデフォルトプロジェクトへの紐付け) を実装ごと撤去」も求めており未着手 (project_role は app/Models/OrganizationInvitation.php の casts と joinOrganization() に現存)。本機能とファイルが重なるため、同時に走らせるなら同一 worktree でやること。実装者はまず tests/Feature/Invitations/AcceptInvitationInAppTest.php を fail させてから入る (AGENTS.md 思考原則 5)。
