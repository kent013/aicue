# Round 3: Round 2 指摘への対応と最終判定依頼

Round 2 の [Critical] を含む全指摘に対応しました。更新後の概念設計 (全文) を末尾に添付します。
**全体判定 (APPROVED / CHANGES_REQUESTED) を出してください。**

## 対応サマリー

| 指摘 | 対応 |
|------|------|
| [Critical] §4.4 のロック保証が不正確 | **全面対応**。指摘の 5 ステップ順序をそのまま §4.4 に記載し、「残存窓は webhook トランザクション実行中そのものを含む」と訂正。「新規発生を止める deny-by-default」という誇張表現を削除。加えて、共通排他 (advisory lock) を採らない理由を追記: **subscription 行を新規作成するのは Cashier の `WebhookController` (vendor)** であり自前の `StripeWebhookProcessor` の外側にいる。自前経路にだけ排他を足しても作成経路は覆えず、完全防止には vendor webhook 受信の差し替え = セキュリティ不変条件「課金の冪等性」の中心を触る大改修が要る。よって「排他による完全防止は行わない」と明言し、本機能の主張を「構造的に起きていた確定的な穴を塞ぐ」に限定した |
| [Critical]/[Warning] 検知を完了条件へ昇格せよ | **対応**。§6.1-5 に昇格 (スコープ内)。Owner 不在かつ生きた課金責務のある組織を daily で検知して `report()` する artisan コマンド (本アプリの運用アラート経路は `report()` のみ)。改修前から存在する孤児組織も拾える。後続 TODO からは削除し、代わりに「検知された孤児組織の**回収手順** (運用 runbook)」を後続に置いた |
| [Warning] 検証コマンド不足 | **対応**。AGENTS.md `VERIFICATION_COMMANDS` と完全同期 (`pnpm build` / `typecheck:packages` / `build:packages` / `test:packages` を追加) |
| [Warning] 組織切替の失敗時挙動・認可 | **対応**。`MembershipScopedOrganizationBinder` が membership スコープで解決し非所属・不在は等しく 404 (存在秘匿)。成功時のみ `/billing` へ進む。失敗時は遷移しない。V13 を追加 |
| [Warning] `incomplete` の根拠が強すぎる / 決済手段の前提 | **対応**。根拠を保証から比較衡量へ弱め、「退会後に決済が完了する低確率の残存リスクは残る」と明記。実査した前提 (`buildSubscriptionSessionPayload()` は `payment_method_types` を指定せず Stripe ダッシュボード設定に委ねている) を書き、**非同期決済を有効化するなら本判断を再確認する**条件を docs 追記対象に入れた |
| [Warning] `isCurrent` は派生値。判別共用体にせよ | **対応**。wire に載せるのを理由 enum から **action enum** (`transfer_ownership` / `open_billing` / `switch_organization_then_open_billing`) に変更。理由 enum はサーバ内部語彙として `ValidationException` 文言生成に使う。TS 同期対象も action 1 本に統一 |

## 判定してほしい点

1. 「排他による完全防止は行わず、予防 (ガード) + 検知 (daily バッチ) の 2 枚で受ける」という結論は、
   vendor (Cashier WebhookController) が subscription 作成経路を持つという制約のもとで妥当か。
2. 本機能の主張範囲を「構造的に起きていた確定的な穴を塞ぐ」に限定した書き方で、裁定 AG-033 の
   一次目的 (今開いている穴を塞ぐ) を満たしていると言えるか。

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

`incomplete` を**ブロックしない**根拠 (保証ではなく比較衡量として書く):
通常のカード Checkout では、追加操作が完了しない `incomplete` は 23 時間以内に `incomplete_expired` へ失効する。
**退会後に決済が完了して `active` 化する低確率の残存リスクは残る** (下記の決済手段の前提に依存)。
それでも通過させるのは、ブロックした場合にユーザー側の**確実な解消導線が存在しない**ため
(Customer Portal は incomplete の subscription を扱わない) で、
最長 23 時間「自力で解消できないまま退会できない」= 行き先のない詰みを新設する害の方が大きいと判断したから。
残存リスクは §4.4 の検知で受ける。

**前提 (決済手段)**: subscription Checkout の payload (`CashierStripeGateway::buildSubscriptionSessionPayload()`) は
`payment_method_types` を指定しておらず、有効な決済手段は **Stripe ダッシュボード設定に委ねている**。
本設計は「カード等の同期決済」を前提にしている。**コンビニ払い / 銀行振込などの非同期決済を
有効化する場合、`incomplete` の滞留時間が伸びるため本判断 (通過) を再確認すること**
(この前提を `docs/architecture.md` の運用注記に書く)。

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
  そのため課金導線は 2 種に分岐させ、current org でないときは
  「組織を切り替えてから請求設定を開く」(既存 `POST /organizations/{slug}/switch` の**成功時のみ**
  クライアントで `/billing` へ遷移) にする。**新 endpoint も redirect パラメータも作らない**
  (open redirect 面を増やさない)。切替の認可・所属検査はサーバ側が権威
  (`MembershipScopedOrganizationBinder` が非所属を 404)。失敗時は遷移せずその場に留まる。

**画面へ渡すのは「理由」ではなく「次の一手 (action)」**:

```
AccountDeletionBlockerAction =
  | transfer_ownership                     // /organizations/{slug}/settings へ
  | open_billing                           // /billing へ (blocker が current org)
  | switch_organization_then_open_billing  // 切替 → 成功時のみ /billing へ
```

- 理由 enum (`OwnerlessMembers` / `ActiveBilling`) は**サーバ内部の語彙**として保持し、
  `ValidationException` の文言生成に使う。**wire に載せるのは action 列**にする。
  こうすると (a) フロントの分岐が判別共用体で網羅検査でき、(b) 時間で変わる派生値
  (`isCurrent` のような boolean) をドメイン事実のように DTO に置かずに済む。
- action は**表示時点のヒント**であり、権威判定は削除時にサーバがロック下で再評価する
  (現行の契約と同じ)。この性質を DTO の docblock に明記する。

**責務分離 (文言の二重管理を作らない)**: blocker の算出は 1 本の service / DTO に集約し、
「削除前の予告 (Inertia props)」と「ブロック時の応答 (`ValidationException`)」の**両方が同じ DTO を入力**にする。
action → 表示文言・導線の対応表はフロント 1 か所、理由 → エラー文言の対応表はサーバ 1 か所に置く
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

守れること (誇張しない):

- PostgreSQL の READ COMMITTED では各文が最新のコミット済みデータを見るため、
  「組織行をロックした時点までに**コミット済み**の課金状態」で判定できる。

**守れないこと (Codex Round 2 の指摘で修正)**: 組織行ロックは、webhook が
**subscription を書き始めること自体は妨げない**。以下の順序が成立する:

1. 退会 tx が organizations 行をロックする
2. webhook tx が subscription を INSERT / UPDATE する (organizations 行ロックはまだ要らない)
3. webhook tx が organizations の更新で待機する
4. 退会 tx は webhook の**未コミット** subscription を読めないまま判定を通し、user を削除して commit
5. webhook tx が再開し、subscription と organizations を commit する

したがって残存窓は「支払い完了 → webhook INSERT まで」だけでなく
**webhook トランザクションの実行中そのもの**を含む。

**共通排他を今回作らない理由** (「作れないから諦める」ではなく、コストと有効性の両方で否):

- 案: organization id を鍵にした transaction-scoped advisory lock を両経路で取る。
  順序は `users → advisory(org) → organizations 行` に揃えられるので理屈上は循環しない。
- しかし **subscription 行を作るのは自前コードではなく Cashier の `WebhookController`** (vendor) であり、
  `StripeWebhookProcessor` (WebhookReceived 購読) の外側にいる。自前経路だけに advisory lock を入れても
  **行の新規作成経路は塞がらない** = 一次目的 (新規の課金孤児を防ぐ) の穴は閉じない。
  完全に閉じるには vendor の webhook 受信を差し替える必要があり、
  セキュリティ不変条件「課金の冪等性 (webhook は冪等マシン経由)」の中心を触る大改修になる。
- したがって本設計は「**排他による完全防止は行わない**」と明言する。
  この機能は「**構造的に起きていた確定的な穴 (課金中の個人組織オーナーの退会) を塞ぐ**」ものであり、
  「webhook と同時刻に退会が commit される競合まで含めた完全防止」ではない。

**代わりに検知を本機能の完了条件へ昇格する** (Codex Round 2 の提案を採用):

- `App\Services\Billing` の guard service に「**Owner 不在かつ生きた課金責務がある組織**」を
  列挙する読み取り専用メソッドを持たせ、daily の artisan コマンドで検知して `report()` する
  (本アプリの運用アラート経路は `report()` のみ = `routes/console.php` の既存注記)。
- これは競合由来の残存窓だけでなく、**本改修より前に既に発生している孤児組織**も拾える。
- 予防 (ガード) と検知 (バッチ) の 2 枚で受ける、という設計であることを `docs/architecture.md` に書く。

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
4. `/settings` の削除前警告を action 別表示に (移譲導線 / 課金導線 / 組織切替 → 課金導線)。
5. **検知バッチ** (§4.4): Owner 不在かつ生きた課金責務がある組織を daily で検知して `report()` する
   artisan コマンド。予防 (ガード) だけでは閉じない残存窓と、改修前から存在する孤児組織を拾う。
6. テスト:
   - Feature: ブロック / 通過 / 解約予約済みは通過 / `trialing` はブロック / Stripe 未呼び出し /
     **props 形状** (組織名・slug・action 列) / 非 current org の切替導線 (成功時のみ遷移、
     非所属は 404) / 検知コマンドが孤児組織を報告すること
   - Architecture: ロック inventory (`MembershipWriteLockInventoryTest`) の更新、
     action enum の PHP⇔TS 同期 (既存 `Tests\Support\TsUnionValues` を再利用)
7. `docs/architecture.md` に退会ガードの不変条件・予防と検知の 2 枚構成・
   事業者側データの運用注記 (非表示化は作成から 90 日後のみ / 処理に最大 30 日) ・
   決済手段の前提 (非同期決済を有効化するなら `incomplete` の判断を再確認) を追記。

### 6.2 今回やらない (裁定の 3 点のうち 2 点を含む) — **やらない理由**

| 項目 | やらない理由 | 後続 TODO 候補 |
|------|--------------|----------------|
| **猶予期間つき削除 (soft delete + 復元導線 + 即時削除の併存)** | 今日開いている穴は「課金の宙づり」であって「誤操作の救済」ではない。`users` は物理削除前提で FK (cascade / nullOnDelete)・CipherSweet PII・監査イベントの `user_id` null 化まで設計が噛み合っており、soft delete 化は退会以外の全経路 (ログイン・招待・blind index の一意性・組織メンバー一覧) に波及する。思考原則 2「今必要なものだけ作る」に倒す | あり: 「退会の猶予期間つき削除 (誤操作救済) を設計する」 |
| **保持期間の実装 (規約が宣言する年数と匿名化処理の対応)** | **対応させるべき規約文言がまだ存在しない** (`/terms` はプレースホルダ・noindex・保持年数の記述ゼロ)。宣言のない年数を先にコードへ焼き込むと、正式文面確定時に必ず作り直しになる。実装の前提 (規約確定) が未成立 | あり (前提: 利用規約の正式文面確定): 「規約の保持期間宣言に対応する匿名化処理を設計する」 |
| ~~オーナー不在の課金中組織の検知~~ | **§6.1-5 に昇格した** (スコープ内)。競合を排他で完全に閉じない以上、検知を後続送りにすると一次目的の受け皿が無くなるため | — |
| **孤児組織の自動回収 (自動解約 / 自動削除)** | 検知した組織をどう始末するかは組織削除 (boundary 外) と決済事業者 API 呼び出し (原則で禁止) の話。検知して人が判断する所までが今回の射程 | あり: 「検知された孤児組織の回収手順を定める (運用 runbook)」 |
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
| V8 | `/settings` の props | blocker が返り (組織名 / slug / action 列)、action 別の導線が描画される |
| V9 | 既存テスト `tests/Feature/Auth/AccountDeletionTest.php` | 「個人組織なら削除できる」は**課金なし前提**として残す (削除しない。禁止事項 3) |
| V10 | `trialing` かつ `ends_at=null` の個人組織の唯一 Owner | ブロック (trial 明けに課金へ進むため) |
| V11 | `incomplete` の個人組織の唯一 Owner | 成功 (23 時間で自動失効。ブロックすると自力解消不能な詰みになる) |
| V12 | 課金 blocker が current org でない | action が `switch_organization_then_open_billing` になり、組織切替導線が描画される |
| V13 | 非所属組織 slug で `POST /organizations/{slug}/switch` | 404 (binder が membership スコープで解決)。遷移しない |
| V14 | 検知コマンド | Owner 不在かつ生きた課金責務がある組織を検出して報告する。健全な組織は報告しない |

検証コマンド (AGENTS.md `VERIFICATION_COMMANDS` と同期):
`composer test` / `composer phpstan` / `vendor/bin/pint --test` /
`pnpm lint` / `pnpm typecheck` / `pnpm test` / `pnpm build` /
`pnpm typecheck:packages` / `pnpm build:packages` / `pnpm test:packages`。

---

## 8. 使命との関係

AI-CUE の使命は「現場作業者が標準化されたマニュアル動画を作れること」であり、退会は周辺機能である。
ただし**課金の宙づりは信頼を直接毀損する**タイプの欠陥で、しかもアプリ操作では回復できない
(組織削除機能が無い) ため、放置コストが構造的に高い。最小限のガードで塞ぐのが妥当。
