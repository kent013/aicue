# Round 3: 指摘への対応と再レビュー依頼

Round 2 の [Critical] 1 件と [Warning] 6 件、[Suggestion] 1 件をすべて捌きました。
対応マトリクスと修正後の概念設計 (全文) を送ります。
[Critical] が解消できているか、対応で新たな不整合が生まれていないかを見てください。

## 対応マトリクス

# 対応マトリクス: conceptual-review Round 2

## [Critical] `joinOrganization(): bool` を既存呼び出し元が無視してよい、という整理は成立していない
- 判断: **対応する** (指摘どおり。「互換」の記述は誤り)
- 根拠: PHP の呼び出し互換と業務上の互換は別。競合で `false` になった既存 token 経路が
  成功 flash を返しうる、という指摘は正しい。実際 `acceptInvitationIfValid` は現行でも
  join 失敗時に `current_organization_id` を招待組織へ書いてしまう潜在バグを持っている。
- 対応内容: 「戻り値を無視するため互換」という記述を削除し、**3 経路すべてが `false` を消費する**
  表を追加した。
  - `acceptInvitation` → `ValidationException`「この招待は無効です。」(既存の中立メッセージと同一文言)
  - `acceptInvitationIfValid` → `null` を返し、現在組織の確定も行わない (潜在バグも同時に閉じる)
  - `acceptPendingInvitation` → `null` → controller が 404
  さらに Codex 案「全呼び出し元が結果を消費することを Architecture テストで固定する」を採用し、
  既存 `MembershipWriteLockInventoryTest` の `delegatedToLocked` 検査を
  「本文に `joinOrganization(` を含む」→「本文に `if (! $this->joinOrganization(` を含む」へ強めた
  (PHP 8.4 に `#[\NoDiscard]` が無いための静的代替)。
  新規 gate を増やさず既存 gate を強める形にしたのは、同じ不変条件を守る目録が既にそこにあるため。

## [Warning] `acceptPendingInvitation()` の例外契約が言葉として矛盾している
- 判断: **対応する**
- 根拠: 「例外を投げない」と「インフラ障害を 404 に化けさせない」は無限定では両立しない。
- 対応内容: 「例外を投げない」を**業務上の受諾不能に限る**と限定した。
  業務上の受諾不能 (宛先不一致・不在・期限切れ・取消済・受諾済・組織削除済・ロック下再検証の敗北) は
  `null`、DB/インフラ/プログラム不整合の例外は捕捉せず伝播 (500) と明記。

## [Warning] ロック下再解決が取消経路とのロック契約まで固定できていない
- 判断: **対応する** (ただし `revokeInvitation` にロックを足す案は採らない)
- 根拠: `revokeInvitation()` は membership/role を変えないため `MembershipWriteLockInventoryTest` の
  `exempt` に登録済みで、membership ロックを取らない。したがって
  「ロック下再解決 → 招待行ロック取得」の間に取り消しが割り込む窓は実在する。
  ただしその窓は `joinOrganization()` の招待行 `lockForUpdate()` 下の再検証が閉じる
  (取り消し側の UPDATE も同じ行を取るため直列化される)。
  revoke 側に membership ロックを足すと、招待から user を知らないまま
  users → organizations の canonical 順序を組むことになり、順序契約の方を壊す。
- 対応内容: 「最終判定の権威がどこにあるか」を事象 × 行ロック × 判定場所の表で固定した。
  組織 soft-delete は organizations 行ロック (soft-delete も同じ行の UPDATE) →
  ロック下再解決が権威。取消 / 期限 / 並行受諾は招待行ロック → `joinOrganization()` の
  再検証が権威。並行 join は `insertOrIgnore`。
  この関係を `revokeInvitation` の exempt 理由にも書き、目録から読めるようにする旨を追記。
  ロック順序は canonical (users 昇順 → organizations → 招待行) のままで**新順序を作らない**
  = デッドロック非導入の根拠として明記した。

## [Warning] 背景 (2) に「3 つに強い」という過大表現が残っている
- 判断: **対応する**
- 根拠: 列挙 3 点に期限切れが含まれるが、新経路は `scopeActive` 前提で期限切れを受諾できない。
- 対応内容: 「前 2 つ (転送による第三者受諾 / 本人がメールを見つけられない) に強い」と限定し、
  期限切れは「受諾可能性の改善ではなく**判断可能性の改善**」として分離した。

## [Warning] 通知 flash の旧表現が制約節に残っている
- 判断: **対応する** (Round 1 の置換が該当箇所に当たっていなかった)
- 対応内容: 論点節の末尾を集合表現へ統一。件数 0 →
  「現在有効な招待はありません (取り消し・期限切れ・参加済みの可能性があります)。」、
  1 件以上 → flash なし。

## [Warning] throttle の方式が未確定
- 判断: **対応する**
- 根拠: inline throttle は同一 actor の全 inline route と 1 bucket を共有し、
  最小 max (`recent-auth.password` = 6) を巻き添えにする (AGENTS.md ドメイン規約 5)。
- 対応内容: 「throttle の方式」節を新設し、**named limiter `invitation-accept-in-app` を新設**と決めた。
  閾値 10/min (姉妹 `invitations.accept.store` / `invitation-accept` と同値。既存値を変えない)、
  キー `invitation-accept-in-app:user:{id}` (未認証は `guest` に落とす。`render-trigger` と同 idiom)、
  route parameter をキーに混ぜない、`RateLimiterKeyConventionTest` の inventory 登録が必要
  (gate 登録先は合計 5 箇所)、429 / 正常受諾の Feature テストを追加、を明記した。

## [Warning] 操作系 POST の成功・失敗応答が未明記 (禁止事項 7)
- 判断: **対応する**
- 対応内容: 「応答契約 (禁止事項 7 / 禁止事項 8)」節を新設。
  成功は `redirect()->route('dashboard')->with('success', ...)` (既存 POST 受諾と同形。
  現在組織を切り替えない契約のため参加先画面へは飛ばさない)、
  `redirect()->intended()` を使わない、解決不能時は `abort(404)` のみで `back()` も flash も出さない
  (文脈依存の戻り先が手掛かりになるため)、受諾ボタンを `disabled` にせず in-flight 送信ガードで
  二重送信を抑止する (禁止事項 8)、成功 flash と着地先を Feature テストで固定する、を明記。

## [Suggestion] `expiresAt` の文字列化責務を 1 箇所へ / `Builder<OrganizationInvitation>|null` の generics 明記
- 判断: **対応する**
- 対応内容: `expiresAt` の文字列化は DTO の static factory `fromInvitation()` に閉じ、
  `Assert::isInstanceOf($expiresAt, Carbon::class)` で非 null を型と実データの両方で保証する旨を追記
  (既存 `InvitationRowData` と同じ流儀)。`pendingInvitationsQuery()` の戻り値に
  `@return Builder<OrganizationInvitation>|null` を明記した。

## [Suggestion] 使命との整合性 / スコープの適切さ
- 判断: **見送る** (肯定的コメントのため対応不要)

---

## 修正後の概念設計 (全文)

# 概念設計: invitation-in-app-acceptance

> lctl 台帳 feature: `auth-invitation-in-app-discovery` (裁定 AG-113 / 標準形 v1) +
> `auth-invitation-flow` (裁定 AG-079 の aicue セル: 役割付き招待の撤去)。
> 実査ブリーフ: `devnotes/20260807-2032-invitation-in-app-acceptance/recon-brief.md`

## 背景・課題

### (1) 発見面が行き止まりになっている

aicue には既に「招待が届いたことをアプリ内通知で知らせる」発見面がある
(`app/Notifications/InApp/InvitationReceivedNotification.php` +
`app/Services/Notification/NotificationCenterService::notifyInvitationReceived()`)。
しかし受諾の入口は**署名なし token URL 経路 1 本だけ**で、
`InvitationAcceptanceController` のクラス docblock も
「招待 email とログインユーザーの email の一致はログイン後経路では要求しない仕様」と書いている。

その結果、通知をクリックしたユーザーは
`NotificationController::open()` の `NotificationType::InvitationReceived` 分岐によって
`notifications.index` へ 303 で戻され、
`info` flash「招待はメールの受諾リンクから参加してください。」を見せられる。
**アプリ内で招待の存在を知ったのに、アプリ内では 1 歩も進めない**。
ユーザーはここでメールクライアントを開き、迷惑メールフォルダを探し、
期限切れなら再送を依頼する — という「アプリの外での作業」を強制される。

台帳の裁定 (AG-113) はこの切れ目を家系の標準形として埋めることを決めており、
aicue に対しては「**既存のアプリ内通知は捨てない。足りないのは受諾の入口だけ**」と明示している。

### (2) 受諾の根拠が「URL の所持」だけであること

現行の受諾根拠は「署名なし token URL を持っていること」である。
token は sha256 hash-only 保存で URL 推測は現実的でないが、根拠が所持である以上

- 招待メールが転送されれば **別人が踏める** (ログイン後経路は email 一致を要求しない仕様)
- 迷惑メールに振り分けられれば **本人が踏めない**
- 期限切れなら **誰も踏めない**

標準形 v1 は、このうち**前 2 つ (転送による第三者受諾 / 本人がメールを見つけられない)** に強い
第 2 の入口 (根拠 = `auth 済み ∧ email 確認済み ∧ ログイン者 email = 招待宛先`) を
**既存 token 経路と置き換えずに並べて持つ**ことを求めている
(未登録の人にはメールが唯一の入口であり続けるため)。
**3 つめ (期限切れ) は新経路でも受諾できない** — 新経路も `scopeActive` 前提である。
新経路が期限切れに対してできるのは「一覧に出さず、状態を明示して再招待の判断へ導く」ことだけで、
これは受諾可能性の改善ではなく**判断可能性の改善**である (混同しない)。

### (3) 役割付き招待 (`project_role`) が撤去待ちのまま同じファイルを占めている

同じ裁定系統の AG-079 が
「aicue の役割付き招待 (`organization_invitations.project_role` と
Default Project への紐付け) を実装ごと撤去する」
(オーナー判断: **Default Project という概念自体が不要**) を確定させており、aicue は未着手。
撤去対象は `app/Models/OrganizationInvitation.php` の cast と
`OrganizationMembershipService::joinOrganization()` の pivot attach で、
**(1)(2) の受諾入口が触るファイルとまるごと重なる**。
入口だけ先に足すと、直後に同じ 2 ファイルを撤去側で書き換えることになる
(前回セッションの handover が台帳へ申し送り済み)。

## 改善アイデア

**A. アプリ内からの受諾経路を 1 本足す (標準形 v1)**

- `POST /invitations/{invitation}/accept-in-app` (`invitations.accept-in-app`)。
  middleware は `auth` + `verified` + throttle。`{invitation}` は **implicit binding させず**
  controller が「認証ユーザー宛の有効 pending 集合」から手動解決する。
- 解決口は `OrganizationInvitation::scopeActivePendingForEmail()` **1 つだけ**。
  `scopeActive()` (未受諾・未失効・期限内) + `whereBlind('email','email_index',$email)` +
  `whereHas('organization')` (Organization は SoftDeletes のため削除済み組織宛が自動で落ちる)。
- 宛先不一致 / 不在 id / 期限切れ / 取消済 / 受諾済 / 削除済み組織宛は**一律 404**。403 を返さない。
- 招待行 → membership の変換は既存 `joinOrganization()` (lock 付き) を**そのまま共有**する
  (署名経路との振る舞いのすれを作らない)。

**B. 既存のアプリ内通知を「発見面」として活かし、受諾へ到達させる**


- 通知 payload には**招待 id を持たせない** (論点の結論。根拠は後述「制約・前提」)。
- `NotificationController::open()` の `InvitationReceived` 分岐は
  引き続き `notifications.index` へ 303 するが、
  その `Notifications/Index` に「あなた宛に届いている招待」セクション (受諾ボタン付き) を出す。
  一覧の算出は A と**同一 scope** を再利用する。
- 全画面横断の気づきは共有 prop `invitations.pendingCount` (件数のみ) と
  最小の誘導 notice で担う。未ログイン・未 verified・email 空は**DB を引かない**。

**C. 役割付き招待 (`project_role`) を実装ごと撤去する (AG-079)**

- `organization_invitations.project_role` 列と check 制約を drop。
- 招待のロール語彙を **org ロール 2 値 (管理者 / メンバー)** に落とす
  (`AdminConsoleRole` の 3 値コマンドは**既存メンバーのロール変更**の語彙として存続)。
- 受諾時の Default Project pivot attach を削除。参加後のロール割当は
  既存の `applyConsoleRole` (ユーザー管理画面の「未割当」修復導線) が担う。
- 「編集者を招待するには先にプロジェクトを作れ」という招待時の前提検査も消える。

**D. 不変条件を目録型 gate で固定する**

`OrganizationInvitation` のクエリ起点を deny-by-default で分類させる Architecture テストを新設し、
「**受信者視点の解決は必ず `activePendingForEmail` 経由**」を機械強制する
(標準形 (b)「受諾の解決と件数/一覧の算出で同一の絞り込みを再利用」の drift 封じ)。

## 期待効果

- **使命への貢献**: AI-CUE の使命は「現場作業者が標準化されたマニュアル動画を作れる」ことにあり、
  その前提は**現場の人が組織に入れている**こと。招待受諾はオンボーディングの最初の関門で、
  現状はそこがアプリの外 (メール探し) に落ちている。
  スマホ (PWA) 中心の現場ユーザーにとって「メールを探して URL を踏む」は特に高い障壁であり、
  アプリ内で完結させる価値は大きい。
- **明確な UX の詰みの解消**: 「通知は届くが受諾できない」= 発見面が行き止まり、を消す。
  効くのは「**メールが見つからない / 開きにくい**」場合であって、
  **期限切れの招待が受諾できるようになるわけではない** (新経路も `scopeActive` 前提)。
  期限切れ・取消済みの招待は一覧から消え、通知を開いたときに
  「現在有効な招待はありません」と明示できる = 再招待を依頼すべきだと**分かる**ようになる
  (今は「メールを探せ」と言われて探し続けることになる)。
- **セキュリティの前進**: 新経路は「宛先本人であること」を根拠にするため、
  転送された URL を第三者が踏む既存経路より根拠が強い。
  存在秘匿 (一律 404) を最初から設計に入れるので、新しい存在オラクルを作らない。
- **不要実装の撤去**: オーナーが不要と裁定した `project_role` を注記付きで残さず消す
  (思考原則 3「後方互換の並走を残さない」)。招待の意味が
  「組織に入れる」だけに収斂し、Default Project の存在有無に招待が依存しなくなる。

## 実装方針（概要）

| # | 施策 | 主な変更 |
|---|------|---------|
| 1 | 受信者視点の単一解決口 | `OrganizationInvitation::scopeActivePendingForEmail()` 新設 |
| 2 | 受信者視点 DTO | `PendingInvitationForUserDto` 新設。**開示するのは `id` / `organizationName` / `roleLabel` / `expiresAt` の 4 つだけ** (下記「開示項目の契約」) |
| 3 | 受諾サービス | `OrganizationMembershipService` に read 系 3 本 + 受諾 1 本 (`acceptPendingInvitation(User $user, string $invitationId): ?Organization`)。共有コア `joinOrganization()` は戻り値 `void` → **`bool`** (ロック下再検証を通ったか) に変更 |
| 4 | 受諾 route / Controller | `AcceptInvitationInAppController` + route + gate 4 箇所登録 + throttle |
| 5 | 発見面 → 受諾の導線 | `NotificationController` (index prop / open 分岐)、`Notifications/Index.svelte`、`NotificationListItem.svelte` 文言、`features/invitations/` の新 component |
| 6 | 横断の気づき | 共有 prop `invitations.pendingCount` + `PendingInvitationsNotice` molecule + `AppLayout` |
| 7 | `project_role` 撤去 | migration / Model / Service / FormRequest / `InvitationRowData` / Factory / `Admin/Users.svelte` / `types/admin.ts` / docs |
| 8 | 目録型 gate | `InvitationResolutionInventoryTest` + `InvitationResolutionScope` enum |

### 存在秘匿の作り方 (核心)

1. **解決を 1 本に絞る**: 受信者視点で招待に触るコードは
   `activePendingForEmail` 経由の query しか作らない。
   したがって「他人宛」「不在」「期限切れ」「取消」「受諾済」「削除済み組織宛」は
   **すべて同じ「0 件」** に collapse し、controller は `abort(404)` 1 行で畳める
   (分岐が無いので、将来理由を足しても情報が漏れる余地が構造的に無い)。
2. **binding 段で解決させない**: `{invitation}` を implicit binding させると
   不在 id だけが binding 段で 404 になり、実在の他人宛 id は後段へ進んで別応答になる
   = 1 bit の存在オラクル。controller の action 引数を `string` にして
   `NestedRouteDefenseMode::ManualOwnerScopedResolution` として登録する
   (`TenantBoundaryOrderingTest` 検査 3a が action 引数型・`MANUALLY_RESOLVED` 登録・
   explicit binder 不在を機械検証する)。
3. **受諾側を total にする**: 公開メソッド
   `acceptPendingInvitation(User $user, string $invitationId): ?Organization` は
   **例外を投げず `?Organization` を返す** (`null` = 受諾できなかった = 404)。
   `catch (Throwable)` で握り潰す形は採らない (インフラ障害を 404 に化けさせない)。
   ここでの「例外を投げない」は**業務上の受諾不能に限る**。
   業務上の受諾不能 (宛先不一致・不在・期限切れ・取消済・受諾済・組織削除済・ロック下再検証で敗北) は
   例外にせず `null` を返す。DB 障害・インフラ障害・プログラム不整合による例外は
   **捕捉せず伝播させる** (500 になる。404 に化けさせない)。
   共有コア `joinOrganization()` は `void` → **`bool`** に変える
   (`true` = ロック下再検証を通り変換が完了した / `false` = ロック下で受諾不能だった)。
   **2 つの戻り値型は層が違う** — `bool` は内部コアの結果、`?Organization` は公開 API の結果。

   **`false` は 3 経路すべてが必ず消費する** (「既存は無視するので互換」という整理は採らない。
   PHP の呼び出し互換があっても業務上の互換は保証されないため):

   | 呼び出し元 | `false` のときの扱い | 根拠 |
   |---|---|---|
   | `acceptInvitation` (POST token 経路) | `ValidationException`「この招待は無効です。」 | 既存の失敗契約と同一文言 (取消/不在を区別しない中立メッセージ) |
   | `acceptInvitationIfValid` (register 経路) | `null` を返す (現在組織の確定も**しない**) | 既存契約「受諾不能なら null」と一致。現行は join 失敗でも `current_organization_id` を招待組織に書いてしまう潜在バグがあり、これも同時に閉じる |
   | `acceptPendingInvitation` (新経路) | `null` を返す → controller が 404 | 存在秘匿の既定 |

   消費を人の注意力に委ねないため、`MembershipWriteLockInventoryTest` の
   `delegatedToLocked` 検査を「本文に `joinOrganization(` を含む」から
   「本文に **`if (! $this->joinOrganization(`** を含む」へ強める
   (戻り値を捨てた呼び出しを機械的に fail させる。PHP 8.4 には `#[\NoDiscard]` が無いため
   静的検査で代替する)。
4. **ロック確立後に同一 scope で再解決する**: 一覧で見えた招待をそのまま信用しない。
   ```
   DB::transaction(function () {
       $preliminary = pendingInvitationsQuery($user)?->whereKey($id)->first();   // ロック前の下見
       if ($preliminary === null) return null;
       lockForMembershipWrite([$user->id], [$preliminary->organization_id]);      // canonical 順序 (users → organizations)
       $invitation = pendingInvitationsQuery($user)?->whereKey($id)->first();     // ★ロック下で同一 scope を再評価
       if ($invitation === null) return null;                                     // 取消/期限/組織 soft-delete の race を吸収
       if (! joinOrganization(...)) return null;                                  // 招待行ロック下の最終再検証
       return $organization;
   });
   ```
   ロックは**下見のあと・再解決の前**に canonical 順序 (users 昇順 → organizations) で取る。
   `joinOrganization()` は内部で同じ行に対して `lockForMembershipWrite` を再取得するが、
   同一トランザクション内の再取得は no-op で順序も変わらない。
   **最終判定の権威がどこにあるかを層ごとに固定する**:

   | 事象 | 直列化する行ロック | 最終判定を下す場所 |
   |---|---|---|
   | 組織の soft-delete | `lockForMembershipWrite` が取る **organizations 行**の `FOR UPDATE` (soft-delete は同じ行の UPDATE なのでブロックされる) | ロック下の再解決 (`whereHas('organization')` が SoftDeletes の default scope 越しに効く) |
   | 招待の取り消し / 期限到来 / 並行受諾 | `joinOrganization()` が取る **招待行**の `lockForUpdate()` | `joinOrganization()` 内のロック下再検証 (`isAccepted()` / `isRevoked()` / `isExpired()`) → `false` |
   | 同一 user × 同一 org への並行 join | `organization_user` の `insertOrIgnore` (原子的 INSERT) | 既存どおり (affected rows = 0 なら role/pivot を触らない) |

   **`revokeInvitation()` は membership ロックを取らない** (招待の論理失効は membership/role を
   変えないため `MembershipWriteLockInventoryTest` の `exempt` に登録済み)。
   したがってロック下再解決と招待行ロックの間に取り消しが割り込む窓は残るが、
   その窓は `joinOrganization()` の招待行ロック下再検証が閉じる
   (取り消し側の UPDATE も同じ行を取るため直列化される)。
   この「最終権威は招待行ロック」という関係を `revokeInvitation` の exempt 理由に明記し、
   `MembershipWriteLockInventoryTest` から読み取れるようにする。
   ロック順序は全経路で canonical (users 昇順 → organizations → 招待行) を維持する
   = 新経路がロック順序を新設しないことがデッドロック非導入の根拠である。

### 受信者視点 DTO の開示項目の契約

`OrganizationInvitation` モデルを Inertia props へ直接流さない。
`PendingInvitationForUserDto` (`final readonly`) が開示面を固定する:

| 開示する | 型 | 理由 |
|---|---|---|
| `id` | `int` | 受諾 POST の宛先。自分宛の招待に限って露出する |
| `organizationName` | `string` | どこへ参加するのかを示す最小情報 |
| `roleLabel` | `string` | 参加後に何ができるのかを示す (`OrganizationRole::label()`) |
| `expiresAt` | `string` (Y-m-d) | いつまでに参加すべきか |

`expiresAt` の文字列化は **DTO の static factory `fromInvitation()` 1 箇所**に閉じる
(呼び出し側で `->format()` を書かない)。`expires_at` は
`create_organization_invitations_table` で `nullable()` ではなく、
`scopeActive()` が `expires_at > now()` で必ず非 null を要求するため、
factory 内で `Assert::isInstanceOf($expiresAt, Carbon::class)` により
**型と実データの両方で非 null を保証**する (既存 `InvitationRowData` と同じ流儀)。

**開示しない**: `email` (CipherSweet 復号値。自分の値だが載せる必要がない) /
`token_hash` (`$hidden` 済みだが DTO でも構造的に出さない) /
`accepted_at` `revoked_at` `expires_at` の生値 / `invited_by_user_id` / `organization_id`。
受諾 URL は front 側が route 名から組む (`acceptUrl` を DTO に持たせない = 署名も token も無い経路なので、
サーバが URL を配る意味が無く、開示面だけ増える)。
管理者視点の `InvitationRowData` とは**別クラスのまま**にする (契約を混ぜない)。

### 「未ログイン・未 verified・email 空は DB を引かない」の担保

サービス内の private な query builder factory 1 箇所に guard を置き、
一覧・件数・受諾解決の 3 経路すべてがそこを通る:

```
/** @return Builder<OrganizationInvitation>|null */
private function pendingInvitationsQuery(?User $user): ?Builder
    → $user === null / ! hasVerifiedEmail() / email === '' なら null を返す (クエリを作らない)
```

共有 prop は closure で遅延評価し、`$user` が無ければ即 `0` を返す。

## 制約・前提

### 最初に決める論点への結論: 通知 payload に招待 id を**持たせない**

| | 持たせる | 持たせない (採用) |
|---|---|---|
| 波及 | `InAppNotificationTypeInvariantTest` / `NotificationTypeTsSyncInvariantTest` / `NotificationSchemaTest` / `resources/js/types/notification.ts` の同期が連鎖 | 通知の型定義は無改造 (本文の文言のみ) |
| stale 通知 | 取消・期限切れ・受諾済みの後の通知から deep link すると必ず 404 = **また行き止まり** | 通知は「有効な招待の live な一覧」へ運ぶだけなので、無効化されていれば一覧が空 = 状態がそのまま見える |
| 標準形 (b) | 発見の入口が scope を迂回するため、drift の余地が増える | 一覧・件数・受諾のすべてが同一 scope に載る |

決定根拠は AGENTS.md 思考原則 2「今必要なものだけ作る」。
招待 id を payload に載せても**得られる能力は「1 クリック短縮」だけ**で、
代わりに 4 つの型同期 gate と stale 畳み込み設計を抱える。
しかも stale 通知からの deep link は 404 に落ちるため、
「発見したのに進めない」という本件の課題そのものを再生産する。
一覧へ運ぶ形なら、無効化された招待は「一覧に無い」という形で自然に説明でき、
`open()` は同じ scope の**件数**を見て flash を出すところまで一貫させられる。
**flash は集合表現にする** (通知と招待は 1:1 で紐づいていないため、
特定の招待について「この招待は無効です」とは断定できない):
件数 0 なら `info`「現在有効な招待はありません (取り消し・期限切れ・参加済みの可能性があります)。」、
1 件以上なら flash なし (受諾できる一覧がその場に出るため説明が不要)。

### 既存を捨てない / 並べて持つ

- 署名 token 経路 (`invitations.accept` / `invitations.accept.store`) は**変更しない**。
  未登録者にはメールが唯一の入口であり続ける。
- アプリ内通知 (`InvitationReceivedNotification`) は発見面として維持する。裁定どおり。
- 変換本体 `joinOrganization()` は 1 本を共有する
  (戻り値の型だけ広げ、既存 2 経路も `false` を消費するよう合わせる)。

### 応答契約 (禁止事項 7 / 禁止事項 8)

- **成功時**: `redirect()->route('dashboard')->with('success', "「{組織名}」に参加しました")`。
  既存 `invitations.accept.store` の成功応答と同一形にする
  (参加直後は「現在組織」が切り替わっていないため、参加先の画面ではなく dashboard へ着地させる。
  現在組織を勝手に切り替えないのは既存 POST 受諾の契約)。
- **`redirect()->intended()` は使わない** (禁止事項 7。ログイン直後フロー専用)。
- **解決不能時**: `abort(404)` のみ。`back()` も flash も出さない
  (「404 なのに文脈依存の戻り先がある」= 存在の手掛かりになるため)。
- **UI**: 受諾ボタンを事前条件で `disabled` にしない (禁止事項 8)。
  一覧に出ている = 押せる、押せなくなった招待は次の描画で一覧から消える。
  二重送信は in-flight 送信ガード (`disabled` 属性ではない) で抑止する。
- 成功 flash の文言と着地先は Feature テストで固定する。

### throttle の方式 (inline ではなく named limiter を新設する)

route 名が `invitations.` で始まるため `ThrottleCoverageInventoryTest` の S3 に入り、
throttle をちょうど 1 本持つことが必須 (exemption 枠は exact-fit `cap=25` のため実質選べない)。

**inline `throttle:10,1` は採らない**。AGENTS.md ドメイン規約 5 のとおり
inline throttle のキーは actor id だけで route 名も limiter 名も入らず、
**同一 actor の inline throttle route はすべて 1 bucket を共有する**。
現状その bucket の最小 max は `recent-auth.password` の 6 であり、
受諾の連打が**再認証を巻き添えで 429 にする**。

したがって **named limiter `invitation-accept-in-app` を新設する**:

- 閾値 **10/min** — 姉妹操作 `invitations.accept.store` (`throttle:10,1`) と
  `invitation-accept` (10/min) に**合わせる**。既存値を変えない (ドメイン規約 5)。
- キー **`invitation-accept-in-app:user:{id}`** (`{レーン}:{種別}:{値}` 規約)。
  throttle は `auth` より前に走るため未認証でも評価される。その場合は
  `render-trigger` と同じ idiom で `guest` に落とす
  (未認証は後段の `auth` で 302 になり受諾に到達できないため、
  guest 同士の相互 429 に実害が無い)。
- **route parameter (`{invitation}`) をキーに混ぜない**
  (混ぜると bucket が分かれ「429 になるまでの回数」が存在オラクルになる。
  `RateLimiterKeyConventionTest` の既存注意点)。
- **登録先が 1 つ増える**: `RateLimiterKeyConventionTest` の limiter inventory
  (登録の網羅が deny-by-default)。gate 登録先は合計 5 箇所になる。
- Feature テストで「10 回目まで受諾試行が通り 11 回目が 429」「正常受諾は 1 回で成功」を固定する。

### 新経路だけ `verified` を要求する非対称

既存 `invitations.accept.store` は「招待された直後の未検証ユーザーも受諾できる」ことを意図して
`verified` を要求していない。新経路は受諾根拠そのものが「email 確認済み」なので `verified` 必須。
**この非対称は仕様である**ため、`docs/architecture.md` に理由を明記する
(片方だけ見て「不整合」と直されるのを防ぐ)。

### aicue 固有の前提 (標準形との差分)

- **席上限が無い**。`config/quota.php` の `max_members` は「現在強制されていない」と
  config 自身がコメントしており `QuotaService::check` の呼び出し元も無い。
  したがって標準形が言う「席満杯は flash 付き redirect」分岐は aicue では発生せず、
  **既定の 404 畳み込みだけを実装する** (使わない分岐を先回りで作らない)。
- **email の blind index に Lowercase transformer が無い** (`User` の `name` にはある)。
  よって宛先照合は**大小文字を区別する完全一致**である。
  これは既存 `acceptInvitationIfValid` の `$invitation->email !== $user->email` および
  `MatchesInvitationEmail` と同じ意味論で、**過剰一致は起きない** (fail-secure 側に倒れる)。
  大小差のある宛先は 404 / 空一覧になり、**既存のメール token 経路 (大小差の影響を受けない
  token_hash 照合) で従来どおり受諾できる**。本件では変えないが、
  この非対称は `docs/architecture.md` に明記する
  (「アプリ内受諾は大小文字完全一致 / メール経路は token 照合」)。
- 招待継続 (未ログインで招待リンクを踏んだときの session 保持) は
  台帳では「aicue に不在」とされているが**実在する** (`SessionInvitationToken` クラスが無いだけで、
  `InvitationAcceptanceController::show()` → session `invitation_token` →
  `CreateNewUser` / `MatchesInvitationEmail` / `resolveRegisterPrefillEmail` が fail-secure に消費)。
  本件では触らず、台帳側の観測を是正する申し送りを残す。

### gate の登録先 (deny-by-default のため必須)

| gate | 登録内容 |
|---|---|
| `tests/Support/Routing/NestedRouteDefenseInventory.php` | `invitations.accept-in-app` => `['invitation' => ManualOwnerScopedResolution]` |
| `app/Http/Routing/RouteBindingTypes.php` | `MANUALLY_RESOLVED['invitation']` に route 名 + 理由 |
| `tests/Architecture/ControllerAuthorizationGateTest.php` | `SelfScopedResource` + 30 字以上の理由。理由には「`Gate::authorize` を通さない代わりに、`auth` + `verified` + `activePendingForEmail($user->email)` による自己スコープ解決に閉じており、宛先不一致・不在は一律 404 になる (403 を返さないため Policy を挟むと存在秘匿が壊れる)」ことまで書く |
| `tests/Architecture/MembershipWriteLockInventoryTest.php` | 新 public メソッドを `delegatedToLocked` / `exempt` へ |
| `tests/Architecture/ThrottleCoverageInventoryTest.php` | route 名が `invitations.` 始まり = S3。exemption 枠は exact-fit (cap=25) のため**throttle を貼る**以外の選択肢は無い |

### `project_role` 撤去に伴う仕様変更 (受け入れる後退)

招待時に「編集者 / 撮影者」を指定できなくなり、招待は「管理者 / メンバー」の 2 値になる。
参加後に管理画面 (`/manage/users`) で「未割当」として可視化され、
既存のロール割当コマンドで編集者 / 撮影者へ遷移させる **2 段の運用**になる。
これはオーナー裁定 (Default Project 概念そのものが不要) の帰結であり、
3 値のまま「選べるが効かない」UI を残す方が有害 (思考原則 3)。

## スコープ外

- **Default Project 概念そのものの撤去**。`DefaultProjectResolver` は
  dashboard / 撮影 PWA の `capture.home` / ユーザー管理画面が現に使っており、
  `applyConsoleRole` の pivot 経路も生きている。AG-079 の最終形ではあるが、
  本作業単位は「招待が `project_role` を持つのをやめる」までに限る
  (招待と結合している部分だけを外し、残りは別 TODO)。
- **署名 token 経路の変更**。GET/POST `invitations.accept*` と
  `Invitations/Accept` / `Invitations/Invalid` 画面は一切触らない。
- **招待の管理者視点の面** (`Admin/Users` の招待一覧・取消)。
  `project_role` 撤去に伴う表示語彙の変更のみで、機能は変えない。
- **席上限 (`SeatAvailabilityService` 相当) の導入**。aicue に存在せず、本件で作らない。
- **通知 payload の拡張** (招待 id / 平文 token)。上記の論点で不採用。
- **Browser (Playwright) テストの新規追加**。受諾は Feature テストで固定でき、
  bfcache/履歴復元のような実機依存の不変条件を新たに導入しないため。
- **email blind index の Lowercase transformer 化**。既存の全 email 照合経路
  (`whereBlind` の呼び出し元すべて) と blind index の再計算を伴う別作業。
