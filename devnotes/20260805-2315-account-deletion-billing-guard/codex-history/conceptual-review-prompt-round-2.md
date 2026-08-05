# Round 2: Round 1 指摘への対応と再レビュー依頼

Round 1 の指摘に対する対応です。実装コードを実査した結果に基づいて、一部は反論しています。
更新後の概念設計 (全文) を末尾に添付します。**全体判定 (APPROVED / CHANGES_REQUESTED) を出してください。**

## 対応サマリー

| 指摘 | 対応 |
|------|------|
| [Critical] `trialing` の写像が未確定 | **対応**。実装実査: `SubscriptionState::fromSubscription()` は `$activeStatuses = ['active','trialing']` を `Active` に写像 → `trialing` かつ `ends_at=null` は**ブロック対象**。写像表を §4.1 に追加、検証 V10 を追加 |
| [Warning] `ends_at !== null` を通す前提が強すぎる | **対応**。実査で前提を明記: aicue の subscription は base price + quantity のみで metered/usage price は存在しない (`grep -rn "metered\|usage_type" app/Services/Billing` = 0 件)。従量はチケット都度購入 (別 Checkout)。pending proration の唯一の源はプラン変更 (`create_prorations`) で、既に消費した差額 + 決済手段は Stripe 顧客側に残る |
| [Warning] `incomplete` の通過根拠 | **対応 (ただし通過のまま)**。完了操作の主体が退会後に存在せず、Stripe が 23 時間で `incomplete_expired` に落とす。ブロックすると**自力解消できないまま最長 23 時間退会できない詰み**を新設する。害が逆転するため通過させ、残存リスクとして明記 |
| [Critical] TOCTOU / subscription 行の錠 | **一部反論**。webhook 側 `applySubscriptionSnapshot()` は `subscriptions.lockForUpdate → organizations UPDATE` の順。退会側は `users → organizations` の canonical 順。ここに subscriptions を足すと逆順になり cross-order deadlock (40P01) を**新設**する。代わりに「組織行ロック取得後に subscription を読む」を設計に固定し、残存窓 (支払い完了〜webhook INSERT の秒〜分) を明示して運用検知の後続 TODO に送る。§4.4 を新設 |
| [Warning] `/billing` は current org スコープ | **対応**。実査で確認 (`billing.*` に route parameter なし)。blocker DTO に `isCurrent` を持たせ、非 current org では「組織切替 (既存 `POST /organizations/{slug}/switch`) → クライアントで `/billing` へ」の導線に切替。新 endpoint も redirect パラメータも作らない |
| [Warning] props 形状の drift | **一部対応**。詰みを生むのは「未知の理由値で UI が何も描けない」ケースだけ。理由 enum の PHP⇔TS 同期 (既存 `Tests\Support\TsUnionValues` 再利用) + props 形状の Feature 検証の 2 枚で担保。生成型の導入は射程外 |
| [Warning] 保持期間の TODO 登録条件 | **対応**。§6.2 の後続 TODO 候補にタイトルと前提条件を明記 (設計フェーズでは TODO.md を触らない規約のため、登録自体は後続工程) |
| [Warning] DTO / ValidationException の責務分離 | **対応**。§4.2 に「blocker DTO 1 本、文言・導線の対応表は 1 か所」を明記 |

## 特に判定してほしい点

1. `incomplete` を通過させる判断 (23 時間の詰み回避) は妥当か。
2. subscription 行を `lockForUpdate()` しない判断 (deadlock 新設の回避) と、その残存窓の受容は妥当か。
3. live pending checkout をブロック条件にしない判断 (live 閾値が 1 日 = `subDay` のため最長 24 時間の詰みを作る) は妥当か。
4. スコープから外した 3 点 (猶予期間つき削除 / 保持期間 / redaction 自動化) の理由は十分か。

---

## 更新後の概念設計 (全文)

# 概念設計: 退会時の課金ガード (account-deletion-billing-guard)

- c2c feature id: `account-deletion-billing-guard` / canonical v1 (origin: aigenba)
- オーナー裁定 AG-033 (2026-08-05) 済み。**裁定は与件**であり本設計では蒸し返さない。
  設計対象は「aicue でどう実装するか」だけ。
- ブリーフ: c2c `get_feature` 出力の要約 (feature_revision `5-1fec4bf47c79`)

---

## 1. 仮説

**仮説**: 現在の退会 (アカウント削除) ガードは「組織にメンバーが孤児化するか」だけを見ており、
「その組織に生きた課金責務 (Stripe subscription) が残るか」を見ていない。そのため
**課金中の個人組織 (自分だけがメンバー) のオーナーが退会すると、オーナー不在の課金中組織と、
請求先が消えた Stripe subscription が残り、誰も解約できない**。

**検証したいこと**: この経路が aicue に実在するか (コードとテストで裏を取る)。

**成功と判断する条件**:
1. 「生きた課金責務が残る組織の唯一オーナー」は退会がブロックされる。
2. ブロックされたユーザーが**何をすれば退会できるか**を画面とサーバ応答の両方で提示される
   (行き先のない詰みを作らない = AGENTS.md 課金ゲート規約と同じ思想)。
3. 上記が Feature テストで固定され、退会経路が **Stripe API を一切呼ばない**ことも
   テストで固定される。

---

## 2. 現状 (実査結果 / 2026-08-05)

ブリーフの「AccountController は 38 行、課金の言及ゼロ」は事実だが、
**ガード本体は Controller ではなく Service にある**。台帳の記述だけでは穴の形を誤認するため、
実査で確定した現状を以下に記す。

### 2.1 退会経路

| 層 | 実装 | 事実 |
|----|------|------|
| route | `routes/web.php` L199-202 | `DELETE /settings/account` + `recent-auth` (step-up) 必須 |
| Controller | `app/Http/Controllers/Settings/AccountController.php` (38 行) | 薄い。`OrganizationMembershipService::deleteAccount()` へ委譲。課金の言及ゼロ |
| Service | `app/Services/Organization/OrganizationMembershipService::deleteAccount()` (L607-651) | users→organizations の canonical 行ロック下で述語を再評価し、`ValidationException(['account' => ...])` で中断 |
| 述語 | 同 `organizationsBlockingDeletion()` (L532-547) | **唯一 Owner かつ 他メンバーが 1 人以上残る**組織のみを blocker とする |
| 表示 | `app/Http/Controllers/Settings/ProfileController.php` L30 → `resources/js/pages/Settings/Index.svelte` L315-329 | `soleOwnedOrganizations: {name, slug}[]` を warning Alert で表示。ボタンは disabled にしない (禁止事項 8 準拠) |

`organizationsBlockingDeletion()` の docblock が明示している:

> 個人組織のように $user が唯一メンバーの組織は「孤児化するメンバーが居ない」ため対象外。

そして `tests/Feature/Auth/AccountDeletionTest.php` に
**「唯一オーナーだが自分のみメンバー (個人組織) なら削除できる」というテストが存在する**
(= 現状の仕様として固定されている)。ここが穴の入口。

### 2.2 課金側の現状

- 課金主体 (billable) は **Organization** (`Cashier::useCustomerModel`)。
  `organizations.stripe_id` / `subscriptions.organization_id` (cascadeOnDelete)。
- **User を削除しても Organization 行は消えない**。`organization_user` の pivot だけが
  `cascadeOnDelete` で消える (`2026_06_11_074000_create_organizations_tables.php`)。
  → 退会後、組織はメンバー 0 人・Owner 不在で存続する。
- **組織削除の機能はアプリに存在しない** (`grep -rn "deleteOrganization\|DeleteOrganization" app tests` = 0 件)。
  つまりオーナー不在になった課金中組織は、アプリ操作では**永久に回収も解約もできない**。
- 課金状態の判定は `App\Services\Billing\BillingAccess` が唯一の窓口
  (「middleware / controller / service での subscription 直参照は禁止」と docblock が宣言)。
  利用可否の最終確定は `SubscriptionService::deriveEntitlement()`。
- 派生状態は `App\Enums\Billing\SubscriptionState`
  (`Active` / `UpgradeRecovery` / `PastDue` = 課金継続、`Paused` / `Inactive` = 非課金)。
- オートリチャージ (`TicketAutoRecharge`) は**消費起点**でしか課金しない
  (`TicketLedgerService` L442 → `AutoRechargeTriggerJob` → `maybeCreateAttempt`)。
  メンバー 0 人の組織では消費が発生しない = 自動課金は起きない。

### 2.3 その他の実査結果 (裁定の 3 点への影響)

- `users` テーブルに **softDeletes なし**。退会は物理削除 (関連は FK cascade / nullOnDelete)。
  猶予期間つき削除の土台は現時点で存在しない。
- `/terms` は `resources/views/legal/terms.blade.php` の**プレースホルダ**
  (「アプリ公開前に正式な文面へ差し替えてください」/ noindex)。
  **保持期間 (年数) を宣言する規約文言がまだ存在しない**。
- Stripe 呼び出しは `App\Services\Billing\Contracts\StripeGatewayInterface` に閉じており、
  テストでは `FakeStripeGateway` / mock に差し替えられる (= 「呼ばないこと」を検証できる)。

---

## 3. 課題 (穴の定式化)

現行述語:

```
blocked(user, org) := isOwner(user, org) ∧ ¬hasAnotherOwner(org) ∧ membersCount(org) > 1
```

守っている不変条件は「**Owner 不在の組織にメンバーを取り残さない**」だけ。
一方で守れていない不変条件が 2 つある:

- **(I1) Owner 不在の組織に生きた課金責務を残さない**
  課金中の個人組織 (membersCount = 1) は述語をすり抜ける。
  結果: Stripe が次期以降も請求を継続し、アプリ側には解約する主体が存在しない。
  ユーザー側の実害 = 退会したのに課金が続く。運営側の実害 = 返金・手動解約の個別対応。
- **(I2) ブロック時に「何をすれば退会できるか」を提示する**
  現行メッセージは「オーナーを移譲してください」の 1 種類のみ。課金起因でブロックした場合、
  個人組織には移譲先のメンバーが**存在しない**ため、この文言のままだと**行き先のない詰み**になる
  (AGENTS.md 課金ゲート規約「403 で突き放さず専用画面で受ける」と同じ失敗様式)。

---

## 4. 方針

### 4.1 述語の拡張 (I1)

blocker 判定を「唯一 Owner である組織」に広げ、その組織ごとに**理由の集合**を持たせる:

```
soleOwned(user, org) := isOwner(user, org) ∧ ¬hasAnotherOwner(org)

reasons(user, org) ⊆ {
  OwnerlessMembers : membersCount(org) > 1              // 既存
  ActiveBilling    : hasLiveBillingObligation(org)       // 新規
}
blocked(user, org) := soleOwned(user, org) ∧ reasons(user, org) ≠ ∅
```

**`hasLiveBillingObligation(org)` の定義 (本設計の核)**:

> 組織に、**将来の請求を発生させうる subscription 行が残っている**か。
> `SubscriptionState::fromSubscription($sub)->grantsAccess()`
> (= `Active` / `UpgradeRecovery` / `PastDue`) **かつ** `ends_at === null` を満たす行が
> 1 つでもあれば true。

**`stripe_status` → `SubscriptionState` → 本述語の写像** (実装 `SubscriptionState::fromSubscription()` を実査して作成):

| `stripe_status` | `SubscriptionState` | `ends_at === null` のとき本述語 | 根拠 |
|---|---|---|---|
| `active` | `Active` (schedule 部分完了なら `UpgradeRecovery`) | **ブロック** | 自動更新が続く |
| `trialing` | `Active` (`$activeStatuses = ['active','trialing']`) | **ブロック** | trial 明けに課金へ進む |
| `past_due` | `PastDue` | **ブロック** | dunning 中。回復すれば課金が続く |
| `paused` | `Paused` | 通過 | trial 終了・カード未登録の read-only。請求は発生しない |
| `canceled` / `unpaid` | `Inactive` | 通過 | 終端 |
| `incomplete` / `incomplete_expired` | `Inactive` | 通過 | 下記参照 |

`incomplete` を**ブロックしない**根拠: `incomplete` は初回支払いの追加認証待ちで、完了操作ができるのは
本人だけ。退会後は完了させる主体がおらず、Stripe は 23 時間で `incomplete_expired` に落とす。
逆にブロック対象にすると、ユーザーが自力で解消できないまま最長 23 時間退会できない
= **行き先のない詰み**を新設してしまう。害の大きさが逆転するため通過させ、残存リスクとして §4.4 に記す。

**前提 (aicue の課金構成)**: subscription は **base price + quantity のみ**で、
metered / usage-based price は存在しない (`grep -rn "metered\|usage_type" app/Services/Billing` = 0 件)。
従量課金はチケットの**都度購入** (`TicketCheckoutGateway` = 別 Checkout) で表現され subscription に載らない。
pending proration の唯一の発生源はプラン変更 (`CashierStripeGateway` の
`proration_behavior => 'create_prorations'`) で、これは**既に消費した差額**であり決済手段は
Stripe 顧客側に残る。したがって「`ends_at !== null` なら継続請求責務なし」と扱ってよい。

この定義を採る理由:

- **`Paused` / `Inactive` を除く**: `Paused` は trial 終了後カード未登録の read-only
  (課金は発生しない)。`Inactive` は `canceled` / `unpaid` / `incomplete*` (終端)。
  どちらも「請求先が消えたまま課金が続く」害を生まない。
- **`ends_at !== null` を除く (重要)**: Customer Portal での解約は
  `cancel_at_period_end` = 期末解約予約であり、`stripe_status` は当面 `active` のまま
  `ends_at` が入る。ここを除外しないと「**解約したのに退会できない**」期間 (最長 1 課金周期) が生まれ、
  I2 の趣旨に真っ向から反する。解約予約済みなら Stripe が自動終了させるため追加請求は発生せず、
  ブロックする理由がない。
- **`deriveEntitlement()` を使わない**: あれは「利用可否 (entitlement)」の判定であり、
  本述語は「**Stripe 側に残る請求責務**」の判定で問いが違う。特に `PastDue` かつ PM 無しは
  entitlement 上 denied だが請求責務は残りうる。既存 docblock の禁止 (subscription 直参照禁止 /
  `grantsAccess` を可否判定に使うな) を守るため、**述語の実装は `App\Services\Billing` 配下に置き**、
  「これは entitlement 判定ではない」ことを docblock で明示する。組織側 service からは
  billing service を注入して呼ぶ (subscription 行を Organization 配下の service が直接読まない)。

### 4.2 出口の提示 (I2)

理由ごとに「次の一手」を対にする。**サーバ応答 (ValidationException) と画面 (Inertia props) の
両方**で提示する (画面は削除前の予告、応答はブロック後の確定説明)。

| 理由 | 次の一手 | 導線 |
|------|----------|------|
| `OwnerlessMembers` | 別のメンバーへオーナーを移譲する | `/organizations/{slug}/settings` (既存。org スコープ route) |
| `ActiveBilling` | サブスクリプションを解約する (または移譲する) | `/billing` (既存。課金ゲート allowlist 内。**current org スコープ**) |

- **ボタンは disabled にしない** (禁止事項 8)。押下 → サーバが再判定 → 理由付きエラー表示、という
  現行の作法を維持する。
- 課金起因ブロックのとき `/billing` へ到達できることは構造的に保証済み
  (`billing.*` は `require-active-subscription` group の外の allowlist)。
  唯一 Owner は `manageBilling` を必ず持つ (OrganizationPolicy の Owner/Admin 既定境界)。
- **`billing.*` は route parameter を持たず current org を解決する** (`ResolvesCurrentOrganization`)。
  blocker が current org でない場合、素の `/billing` リンクは**別組織の課金画面**を開いてしまう。
  そのため blocker DTO に `isCurrent` を持たせ、current org でないときは
  「組織を切り替えて請求設定を開く」導線 (既存 `POST /organizations/{slug}/switch` の成功後に
  クライアントで `/billing` へ遷移) に切り替える。**新 endpoint も redirect パラメータも作らない**
  (open redirect 面を増やさない)。

**責務分離 (文言の二重管理を作らない)**: blocker の算出は 1 本の service / DTO に集約し、
「削除前の予告 (Inertia props)」と「ブロック時の応答 (`ValidationException`)」の**両方が同じ DTO を入力**にする。
理由 → 文言・導線の対応表はサーバ側 enum とフロント側 1 か所のマップだけに置く
(`response()->json()` は使わない = 禁止事項 4)。

### 4.3 「決済事業者 API を呼ばない」原則の明文化 (裁定 1 の一部)

裁定が標準形の原則として採った「**退会処理から決済事業者 API を呼ばない (ガードで止める)**」を
aicue でも採り、**Feature テストで固定**する (退会成功時に `StripeGatewayInterface` が
1 度も呼ばれないこと)。自 DB と外部サービスの二重書き込みを構造的に避けるため。

顧客データの事業者側の扱い (非表示化は作成から 90 日後のみ・処理に最大 30 日) は
**運用手順の話**であり、コードではなく `docs/architecture.md` に運用注記として残す。

### 4.4 競合 (TOCTOU) と錠の順序 — 何を守り、何を守らないか

**採る方針: 課金判定は「組織行ロック取得後」に読む。subscription 行は `lockForUpdate()` しない。**

理由 (錠の順序を実査した結果):

- `deleteAccount()` の canonical 順序は **users → organizations** (`lockForMembershipWrite`)。
- webhook 側 `SubscriptionService::applySubscriptionSnapshot()` は
  **subscriptions を `lockForUpdate` → organizations を UPDATE (`$org->save()`)** の順で錠を取る。
- ここで退会側に subscriptions の行ロックを足すと `organizations → subscriptions` となり、
  webhook の `subscriptions → organizations` と**逆順**になる = cross-order deadlock (PostgreSQL 40P01) を
  **新設**することになる。webhook 側の順序を変えるのは冪等マシン (セキュリティ不変条件「課金の冪等性」) の
  中心を触る話で、本件の射程に対して過大。

守れること:

- PostgreSQL の READ COMMITTED では各文が最新のコミット済みデータを見るため、
  「組織行をロックした時点までにコミット済みの課金状態」で判定できる。
- 逆に webhook 側は organizations を UPDATE するために**我々が保持する組織行ロックを待つ**ので、
  判定〜削除の間に `plan_code` 同期を伴う webhook が割り込むことはない。

守れないこと (残存リスクとして明示する):

| 残存窓 | 内容 | 判断 |
|--------|------|------|
| 支払い完了 → Cashier の WebhookController が subscription 行を INSERT するまで (秒〜分) | この間に退会すると、直後にオーナー不在の課金中組織が成立しうる | 受容。塞ぐには「live pending checkout があれば退会をブロック」が必要だが、`BillingCheckoutSession::staleThresholdAt()` は **1 日** (`subDay`) であり、放置された checkout で**最長 24 時間退会できない詰み**を作る。秒オーダーの窓のために 24 時間の詰みを新設するのは害の逆転。閾値をこの用途に再発明するのも禁止 (`CheckoutLiveThresholdSingleSourceTest`) |
| `incomplete` が退会後に `active` 化 | 追加認証を完了できる主体が残っていないため事実上起きない (23 時間で `incomplete_expired`) | 受容 |

受け皿は**後続 TODO 候補「オーナー不在の課金中組織の検知」** (運用側の検知バッチ)。
本改修は新規発生を止める deny-by-default であり、残存窓の回収は運用で受ける。

---

## 5. 代替案と却下理由

| # | 代替案 | 却下理由 |
|---|--------|---------|
| A | 退会時にアプリから Stripe subscription を自動解約する | 裁定が採った原則に反する。自 DB 削除と外部 API の二重書き込みで、API 失敗時に「ユーザーは消えたが解約は失敗」という回復不能な不整合が残る。冪等性不変条件 (webhook 経由) の外で課金状態を書き換えることにもなる |
| B | 退会時に組織ごと削除する | boundary 外 (台帳が「組織削除そのもの (tenancy)」を含まないと定義)。かつ組織削除機能自体が未実装で、影響範囲 (プロジェクト・動画・チケット台帳・監査) が桁違いに広い。思考原則 2 に反する |
| C | `BillingAccess::hasActiveAccess()` を流用して判定する | 問いが違う (利用可否 vs 請求責務)。`Paused` を「課金中」と誤判定し、`cancel_at_period_end` を「解約済み」と扱えず**解約したのに退会できない**詰みを生む |
| D | 述語を `Organization` モデルや `OrganizationMembershipService` に直書きする | 「課金判定は Billing 配下を経由する」既存規約を崩す。subscription 行の解釈が 2 か所に散る |
| E | ブロックせず警告だけ出して退会させる | I1 が塞がらない。オーナー不在の課金中組織は**アプリ操作では回収不能** (組織削除機能が無い) ため、警告では実害を止められない |
| F | 猶予期間つき削除 (soft delete + 復元) を同時に入れる | §6 参照。今回の穴 (課金の宙づり) を塞ぐのに不要で、`users` の物理削除前提 (FK cascade / nullOnDelete / CipherSweet PII) を全面的に作り替える大工事になる |
| G | live pending checkout があれば退会をブロックする | §4.4 参照。live 判定の閾値は 1 日 (`BillingCheckoutSession::staleThresholdAt` = `subDay`) で、放置 checkout により**最長 24 時間退会できない詰み**を作る。塞ぎたい窓は秒〜分オーダーであり、害が逆転する |
| H | 退会時に subscription 行も `lockForUpdate()` する | §4.4 参照。webhook 側が `subscriptions → organizations` の順で錠を取るため、退会側で `organizations → subscriptions` を取ると cross-order deadlock を新設する |

---

## 6. スコープ境界

### 6.1 今回やる (必須)

1. 生きた課金責務の判定 (`App\Services\Billing` 配下の新 service)。
2. `organizationsBlockingDeletion()` の述語拡張と **理由付き DTO 化**。
3. ブロック時サーバ応答の理由別メッセージ (何をすれば退会できるか)。
4. `/settings` の削除前警告を理由別表示に (移譲導線 / 課金導線 + 組織切替導線)。
5. テスト:
   - Feature: ブロック / 通過 / 解約予約済みは通過 / `trialing` はブロック / Stripe 未呼び出し /
     **props 形状** (理由・組織名・slug・isCurrent が返ること)
   - Architecture: ロック inventory (`MembershipWriteLockInventoryTest`) の更新、
     理由 enum の PHP⇔TS 同期 (既存 `Tests\Support\TsUnionValues` を再利用)
6. `docs/architecture.md` に退会ガードの不変条件と事業者側データの運用注記を追記。

### 6.2 今回やらない (裁定の 3 点のうち 2 点を含む) — **やらない理由**

| 項目 | やらない理由 | 後続 TODO 候補 |
|------|--------------|----------------|
| **猶予期間つき削除 (soft delete + 復元導線 + 即時削除の併存)** | 今日開いている穴は「課金の宙づり」であって「誤操作の救済」ではない。`users` は物理削除前提で FK (cascade / nullOnDelete)・CipherSweet PII・監査イベントの `user_id` null 化まで設計が噛み合っており、soft delete 化は退会以外の全経路 (ログイン・招待・blind index の一意性・組織メンバー一覧) に波及する。思考原則 2「今必要なものだけ作る」に倒す | あり: 「退会の猶予期間つき削除 (誤操作救済) を設計する」 |
| **保持期間の実装 (規約が宣言する年数と匿名化処理の対応)** | **対応させるべき規約文言がまだ存在しない** (`/terms` はプレースホルダ・noindex・保持年数の記述ゼロ)。宣言のない年数を先にコードへ焼き込むと、正式文面確定時に必ず作り直しになる。実装の前提 (規約確定) が未成立 | あり (前提: 利用規約の正式文面確定): 「規約の保持期間宣言に対応する匿名化処理を設計する」 |
| **オーナー不在の課金中組織の検知 (残存窓の受け皿)** | §4.4 の残存窓 (秒〜分) は本改修では塞がない。検知は運用側のバッチ/管理画面の話でアプリの退会経路の責務ではない | あり: 「オーナー不在かつ課金中の組織を検知する運用手段を用意する」 |
| **決済事業者側データの非表示化 (redaction) の自動化** | 90 日制約・最大 30 日処理という事業者側の制約は**運用手順**で受けるのが標準形の趣旨。退会処理から API を呼ばない原則とも整合する。今回は `docs/architecture.md` に運用注記として書き、自動化はしない | あり (低優先) |
| **オートリチャージ設定を blocker に含める** | 実査の結果、オートリチャージは**チケット消費起点**でしか課金しない (`TicketLedgerService` → `AutoRechargeTriggerJob`)。メンバー 0 人の組織では消費が起きないため自動課金は発生しない。blocker に足すと、害の無い状態で退会を止める過剰ガードになる | なし (不要と判断) |
| **チケット残高の失効警告** | 退会を止める理由にならない (課金は発生しない)。表示を足すと削除前 UI が肥大する | あり (UX 改善として低優先) |
| **組織削除 (tenancy) / サブスク解約 API** | 台帳 boundary の対象外と明記されている | 別 feature |
| **既にオーナー不在で残っている組織の回収 (バックフィル)** | 本改修は**新規発生を止める** deny-by-default。既存孤児組織の有無は本番データの運用調査であり、設計フェーズでは判断材料がない | 上記「検知」TODO に含める |

---

## 7. 検証方法

| # | 検証 | 期待結果 |
|---|------|----------|
| V1 | 課金中 (`active`, `ends_at=null`) の個人組織の唯一 Owner が退会 | ブロック。`errors.account` に「サブスクリプションを解約してください」と `/billing` の案内。user 行は残る |
| V2 | 解約予約済み (`ends_at` セット) の個人組織の唯一 Owner が退会 | **成功** (追加請求が発生しないため止めない) |
| V3 | `canceled` / `paused` の個人組織の唯一 Owner が退会 | 成功 |
| V4 | 課金中組織で 2 人目 Owner がいる場合 | 成功 (課金責務の引受先が残るため) |
| V5 | 課金中組織 + 他メンバー有りの唯一 Owner | ブロック。理由 2 つ (移譲 + 解約) が両方提示される |
| V6 | 無料枠 (`free_plan_code='personal'`, subscription 行なし) の個人組織 | 成功 (現行挙動の維持 = 退行なし) |
| V7 | 退会成功経路で `StripeGatewayInterface` を mock | 1 度も呼ばれない |
| V8 | `/settings` の props | 理由付き blocker が返り (理由 / 組織名 / slug / isCurrent)、理由別の導線が描画される |
| V9 | 既存テスト `tests/Feature/Auth/AccountDeletionTest.php` | 「個人組織なら削除できる」は**課金なし前提**として残す (削除しない。禁止事項 3) |
| V10 | `trialing` かつ `ends_at=null` の個人組織の唯一 Owner | ブロック (trial 明けに課金へ進むため) |
| V11 | `incomplete` の個人組織の唯一 Owner | 成功 (23 時間で自動失効。ブロックすると自力解消不能な詰みになる) |
| V12 | 課金 blocker が current org でない | props の `isCurrent=false` で組織切替導線が描画される |

検証コマンド: `composer test` / `composer phpstan` / `vendor/bin/pint --test` /
`pnpm lint` / `pnpm typecheck` / `pnpm test`。

---

## 8. 使命との関係

AI-CUE の使命は「現場作業者が標準化されたマニュアル動画を作れること」であり、退会は周辺機能である。
ただし**課金の宙づりは信頼を直接毀損する**タイプの欠陥で、しかもアプリ操作では回復できない
(組織削除機能が無い) ため、放置コストが構造的に高い。最小限のガードで塞ぐのが妥当。
