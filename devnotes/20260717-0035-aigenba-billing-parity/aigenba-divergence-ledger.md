# aigenba 乖離台帳（決済ドメイン parity）

> **目的**: AI-CUE へ aigenba の実装を移植する過程で生じた**全ての乖離**を記録し、
> **aigenba 側へ取り込むべきものを特定して返す**ための台帳。
> ユーザー方針: 「**aigenba の実装と可能な限り揃える。乖離が起きるなら aigenba に取り込まないといけない**」。
>
> **運用**: 各 TODO（T072〜T081）の実装で乖離が出たら**必ずここへ追記する**。
> 実装エージェントには「乖離を報告せよ」を指示に含め、親（Claude）が統合時にここへ集約する。
> **カテゴリ B（aigenba へ返すべき）は、実装完了後に aigenba へ引き継ぎを出す**。

## 分類

| カテゴリ | 意味 | aigenba へ返すか |
|---|---|---|
| **A: AI-CUE に対象が存在しない** | aigenba の機能に対応する概念・列・ドメインが AI-CUE に無い（席課金 / encounter 等） | **返さない**（aigenba 側は正しい） |
| **B: AI-CUE 側が優れている / 安全** | AGENTS.md の不変条件・禁止事項に由来し、**aigenba にも同じ問題がある**（= aigenba のバグ or 弱点） | **返す（要検討）** |
| **C: 既存契約への適合** | AI-CUE 側の既存スキーマ・規約に合わせるための最小 adaptation。意味論は不変 | **返さない**（AI-CUE 固有） |
| **D: ドメイン要件の差** | プロダクトの仕様そのものが違う（可変コスト消費 等） | **返さない** |
| **E: 一時的な移行措置** | 移行フェーズ中のみ存在し、後続フェーズで退役する | **返さない** |

---

## B: aigenba へ返すべき乖離（**要対応**）

### B-1. `BillingCheckoutSession` の `$fillable` に tenant / actor キーが含まれている

| | |
|---|---|
| **aigenba** | `app/Models/Billing/BillingCheckoutSession.php` の `$fillable` に **`organization_id` / `initiated_by_user_id` が含まれる** |
| **AI-CUE** | 両方を `$fillable` から**除外**（書き込みは Service の明示代入のみ） |
| **理由** | AGENTS.md セキュリティ不変条件 **#1「tenant キー不信: ownership/actor/tenant キーを payload から受け取らない」**。AI-CUE は `MassAssignmentProtectedKeys` + `tests/Architecture/MassAssignmentSafetyTest` で機械強制しており、fillable に載せると **arch テストが落ちる** |
| **aigenba への提案** | `organization_id` / `initiated_by_user_id` を `$fillable` から外し、Service 側で明示代入する。**mass assignment 経由で他組織の checkout session を作られる余地を構造的に消せる**（現状 aigenba では Request payload に `organization_id` が混入した場合に防げるのは上位バリデーションのみ） |
| **確度** | 中（aigenba 側で実際に payload 由来の代入経路があるかは未確認。**aigenba 側で要検証**） |
| **検出元** | T073 実装（Codex impl-review が「不変条件 #1 に適合。逸脱は妥当」と判定） |

### B-2. 請求先 PII（`billing_contact_email` / `billing_contact_name`）が平文保存

| | |
|---|---|
| **aigenba** | `organizations.billing_contact_email` / `billing_contact_name` を **平文 `string`** で保存（`2026_04_14_011301_add_cashier_columns_to_organizations_table.php:16-17`） |
| **AI-CUE** | **両方を CipherSweet で暗号化**（列型は ciphertext のため `text()`）。検索が必要な項目のみ `whereBlind()` |
| **理由** | AGENTS.md セキュリティ不変条件 **#6「PII(email/name)は CipherSweet。検索は `whereBlind()`(平文 where は hit しない)」** |
| **aigenba への提案** | **請求先 email / name は PII**。平文 DB 保存は、DB ダンプ・バックアップ・ログ流出時にそのまま漏れる。CipherSweet 化（+ blind index）を検討してほしい |
| **確度** | 高（aigenba の実コードで平文 `string` を確認済み）。ただし **aigenba 側の PII 方針が AI-CUE と異なる可能性はある**（aigenba に CipherSweet 相当の規約があるかは未確認） |
| **状態** | **P9（T081）で実装予定**。実装後に具体的な移植手順を添えて返す |
| **検出元** | 設計 v2（横断決定「維持する決定」#6） |

### B-3. `current_period_end` を snapshot が null のときも無条件に上書きする

| | |
|---|---|
| **aigenba** | `applySubscriptionSnapshot` が `current_period_end` を**無条件に save** |
| **AI-CUE** | snapshot が null のときは**書かない**（現行 `syncSubscriptionPeriod` は period 欠落 payload で早期 return する挙動） |
| **理由** | **null 上書きは renewal reminder の真実源を壊す**（period 欠落の webhook payload が来たときに、既に持っている正しい期限が消える） |
| **aigenba への提案** | period 欠落 payload（Stripe の一部イベントは `current_period_end` を含まない）で **既存の期限を null 上書きしていないか確認**してほしい。上書きしていると更新通知・期限表示が壊れる可能性がある |
| **確度** | 中（aigenba 側で period 欠落 payload が実際に届くか、届いた時に何が起きるかは**未検証**。AI-CUE では現行実装が明示的に早期 return しており、その挙動を保存した） |
| **検出元** | T073 実装（Codex impl-review が「妥当」と判定） |

### B-4. 必須条件未充足を理由にボタンを `disabled` にする UI

| | |
|---|---|
| **aigenba** | `PlanCard` の「変更不可」/ `PurchaseTickets` の submit を **`disabled`** にする |
| **AI-CUE** | **移植しない**。**押せる状態を維持し、押下後に理由・validation error を表示**する |
| **理由** | AGENTS.md 禁止事項 **#8「必須条件未充足を理由にボタンを disabled にする UI（押下時にエラー表示する。DESIGN.md）」** |
| **aigenba への提案** | disabled は**理由が伝わらない**（なぜ押せないかを利用者が推測するしかない）。押下 → 理由表示にすると、同じ状態を保ちつつ理由を伝えられる。**UX 方針の差**なので aigenba 側の判断次第 |
| **確度** | 高（挙動の差は明確）。ただし **UX ポリシーの違いであり「aigenba のバグ」ではない** |
| **状態** | **P8b（T080）/ P3（T074）で実装予定** |
| **検出元** | 設計 v2（横断決定 D4） |

---

## A: AI-CUE に対象が存在しない（返さない）

| # | 乖離 | 詳細 |
|---|---|---|
| A-1 | `PlanCode::isSeatFixed()` 非移植 | AI-CUE に**席概念・`plans.included_seats` が無い**（席課金は設計スコープ外） |
| A-2 | `BillingCheckoutSession` の `seats` / `credit_count` / `unit_amount` / `funding_choice` / `topup_count` / `applied_campaign_id` / `applied_trial_days` / `pm_reuse_dispatched_at` 列を非移植 | 席・campaign・trial 機構が AI-CUE に無い（`funding_choice` / `pm_reuse_dispatched_at` は **P9 で additive 追加**予定） |
| A-3 | `CheckoutIntent` の `CreditPurchase` / `SignupFunding` case を非移植 | チケット決済は AI-CUE では**別テーブル**（`ticket_checkout_sessions`）が担う。campaign 機構が無い |
| A-4 | `SubscriptionSnapshot` に `currentPeriodStart` を持たない + period 巻き戻し guard 非移植 | **`subscriptions.current_period_start` 列が AI-CUE に無い** |
| A-5 | `assertCheckoutReady()` 非移植 | AI-CUE の `Organization` に**請求先メール列が無く** Cashier 既定の `stripeEmail()` が常に null → 移植すると checkout/portal が**全 org で throw** する。**P9 で請求先列が入った後に再検討する** |
| A-6 | `SubscriptionService` の schedule lifecycle / seat / signup funding / `changePlan` / `upgradeNow` / `isMutableState` を非移植 | 設計スコープ外（席・schedule 機構が無い） |
| A-7 | `getStatus()` / `BillingStatusDto` を P2 で作らない | 呼び出し側 UI が P8b 所管 = **dead code を作らない** |
| A-8 | aigenba の fallback 文言「現在パーソナルプランは選択できません」を非移植 | **D4（禁止事項 #8）とセット**。AI-CUE は disabled にせず**サーバ由来の `reasonLabel` を常時 caption 表示**するため、クライアント側の fallback 文言は不要（文言をフロントで組み立てない） |
| A-9 | `Onboarding/Checkout.svelte` の `showAllPlans` / 折りたたみ確認画面を非移植 | `preselectFunding` が無い P3 では `showAllPlans` が常に true = **dead code**（intended バッジ・`?choose` は P7 所管） |

## C: 既存契約への適合（返さない。意味論は不変）

| # | 乖離 | 詳細 |
|---|---|---|
| C-1 | `PlanPriceService::replaceCurrent()` に `?string $lookupKey = null` を追加（**D14**） | AI-CUE の `SyncStripePrices.php:78-87` が「kind + is_current + **lookup_key 一致**」の current 行を要求する。verbatim だと**既存 sync 契約が壊れる** |
| C-2 | `CreditSource`（`plan_monthly`/`purchased`）を移植せず既存 `TicketSource`（`monthly`/`purchased`）を使う | 意味論は同一。改名は `ticket_ledger_entries.source` **全行の書き換え** = additive 原則違反 |
| C-3 | route を **route parameter 無しの current-org スコープ**へ（**D6/D21**） | aigenba は `{organization:slug}` バインド。AI-CUE の業務 route は current-org スコープ（`routes/web.php:349`）で、org-slug 化は**アプリ全体の route 規約変更**になりスコープ外 |
| C-4 | Gate ability 名 `'manage-billing'` → **`'manageBilling'`** / `Role::OrganizationOwner` → `OrganizationRole::Owner` / `ContactUrl::forSource()->url` → `ContactUrl::resolveForSource()` / `TicketService` → `TicketLedgerService` 等 | **名前解決のみ**。AI-CUE の既存 API 名に合わせる |
| C-5 | `Subscription` 行の materialize は **Cashier の `WebhookController` が唯一の writer**（aigenba の `applySubscriptionSnapshot` 末尾の `Subscription::create($attrs)` を非移植） | aigenba は Cashier の `WebhookController` を使わず**自前 `StripeWebhookController` が唯一の writer**。AI-CUE は Cashier のハンドラを使うため、listener 側で先に行を作ると Cashier の `! $user->subscriptions->contains(...)` ガードが false になり **`subscription_items` の生成が永久に skip される** |
| C-6 | `applySubscriptionSnapshot` が DTO を返さず `void` | 呼び出し元が戻り値未使用 = **dead code を作らない** |
| C-7 | `tests/Architecture/MembershipWriteLockInventoryTest` に read 専用許可リストを追加 | AI-CUE 固有の arch guard。元は `role_user` への**言及自体**を read 含めて禁止していたが、`PersonalPlanService::eligibility()` の owner 在籍判定（**aigenba verbatim の read**）が抵触。不変条件（書き込みは必ずロック下）は維持し、**書き込み API 不含を別途強制**（負のコントロールで検証済み） |
| C-8 | `Inertia::location()` を使わず素の `RedirectResponse` | AI-CUE では `Inertia::location()` は **Stripe への外部 full page redirect 専用**。内部遷移の意味論は同一 |
| C-9 | `->map(...)->values()->all()` → **`array_values(...->all())`**（`OnboardingController::selectablePlans()`） | 設計の記述どおり `values()->all()` にすると larastan が `array<int, PlanDto>` に落とし `list<PlanDto>` の return type で **PHPStan level 10 が落ちる**。aigenba は inline `/** @var list<PlanDto> */` で上書きするが、**AGENTS.md 禁止事項 #1（型の上書き禁止）**に抵触。同一リポジトリの既存 precedent（`PricingService::listPublicPlans()`）と同作法へ。**意味論・集合は完全に不変** |
| C-10 | `resources/js/pages/Onboarding/*` は `GuestLayout` ではなく **`AppLayout` + T071 primitive**（`PageContainer`/`PageHeader`/`PageContent`） | 両ページとも auth group 内のログイン後ページ。AI-CUE の外枠規約（arch テスト `page-shell-structure`）が parity に優先する |
| C-11 | `PlanDto` は AI-CUE の実列のみ（`code`/`name`/`currentBaseAmount`/`isActive`） | 席・scenario/course limit・通貨は列が無い。`includedMonthlyTickets` は **D28 で廃止**。`toArray()` の 4 キー厳密一致をテストで固定し、後続フェーズの additive 追加に気づける形にした |

## D: ドメイン要件の差（返さない）

| # | 乖離 | 詳細 |
|---|---|---|
| D-1 | **`reserve` は amount ベースを維持**（aigenba の `reserve(encounterId)` = 1 encounter 1 枚は移植しない） | AI-CUE の消費は**解析 1 枚 / レンダ 3 枚の可変コスト**（`manual.analysis_ticket_cost` / `render_ticket_cost`）。機械移植すると**単価差の前提が壊れて課金が壊れる**。Codex も「parity はドメイン上の意味・不変条件を揃えることであり、異なる課金単位の API 形状まで機械的に一致させることではない」と判定 |

## E: 一時的な移行措置（返さない。後続フェーズで退役）

| # | 乖離 | 退役予定 |
|---|---|---|
| E-1 | `hasActiveAccess()` の移行 OR（`\|\| $org->plan_code === null`） | **P4（T075）で 1 行削除** |
| E-2 | `claimSignupGrantMarker()` を移行期は **public**（aigenba は `activate()` 内 private）（**D13**） | **P6（T077）で private へ戻す** |
| E-3 | `invoice.paid`（`billing_reason=subscription_create`）経路の marker claim + grant | **P6（T077）で付与契機が `customer.subscription.created` へ移る（D29）ため退役** |
| E-4 | `PlanSeeder` に legacy `free` 行を残置 | **P4（T075）で撤去（D11）** |

---

## 乖離ではないと判定したもの（記録）

| # | 事項 | 判定 |
|---|---|---|
| — | **有償プランの submit を P3 に含めた** | **乖離ではない**（設計の読み）。設計 L2413（P8a）が `Checkout.svelte` を「**P3 導出** + P8a の funding 2 択」の**改修**として記述しており、有償 submit が P3 に存在する前提。無ければ Starter/Standard 選択後の行き先が無く**詰み** = P3 の存在理由（導線を実在させる）と矛盾する。body は既存 `Billing/Index.svelte` と同一の `{plan_code}` のみで、token/funding は P8a/P9 が additive に足す |
| — | **`PlanFactory` を新設しなかった** | **乖離ではない**。Plan の真実源は `PlanSeeder`（`TestCase::$seed = true` で毎テスト実走）で、既存テストも seeded 行を読む規約。必要な 2 ケース（base price あり=starter / なし=personal）は seed で揃うため**手組みデータは使っていない**。使わない Factory の新設は禁止事項 #7（不必要な複雑化） |

## 保留 / 判断待ち

| # | 事項 | 状態 |
|---|---|---|
| ? | **月次チケット付与の廃止（D28）** | **乖離ではない**（aigenba に**揃える**変更）。aigenba は施策8/v3 で全 tier `included_monthly_tickets = 0` = 廃止済み。AI-CUE も追随した |
| ? | **返金債務が回収されない経路** | **CLOSED**。aigenba 側の実行検証で**指摘は不成立**と判定（私の再現手順が消費優先 monthly→purchased と矛盾していた）。`aigenba-handoff.md` 参照 |
