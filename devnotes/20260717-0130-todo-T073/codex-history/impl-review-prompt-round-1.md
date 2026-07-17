【アプリの使命 (North Star)】
## 使命 (North Star)

<!-- app-codex-review スキルはこのセクションをレビュー使命として参照する。 -->

**AI-CUE** は、現場に既にある**作業手順書(SOP)を起点に**、AI が撮るべきカットを設計した**動画シナリオ**を生成し、そのシナリオを**スマホ(PWA)でナビゲーション撮影**することで、専門知識ゼロの現場作業者でも**標準化されたマニュアル動画**を作れるようにする。

- 「思考ゼロ・編集ゼロ」— 台本作成・撮影判断・編集の 3 ハードルを AI とナビ撮影で肩代わりする。
- 競合(OJT を撮って形式化する tebiki)と異なり、**標準作業を起点に AI が教材設計し撮影を指示する**（撮影者・教える人のスキルに品質を依存させない）。
- 熟練者の暗黙知を動画マニュアルという形式知へ変換する装置（SECI）。

> 詳細な設計は本リポジトリの `doc/`（01〜10）に置く。ドメインをテンプレート構造へどうマップしたかは `doc/08`〜`doc/10`（特に `doc/10 §10.8` に設計レビュー反映済み）。
> **v1 スコープ**: 字幕のみ(TTS 後回し) / 撮影は PWA(同一オリジン・セッション認証) / 動画合成は自前 ffmpeg / 単一 Default Project。


【禁止事項】
## 禁止事項

1. テストなしの実装完了報告(不変条件は対応する Architecture/Feature テストへの登録まで含めて「実装済み」)
2. PHPStan エラーの widen(型を緩めて黙らせる)・baseline 化
3. dev DB への破壊操作(`migrate:fresh` 等)をエージェント判断で実行すること
4. `response()->json()` の直書き(DTO / JsonResource / Inertia を使う。仕様固定 endpoint のみ例外)
5. LLM 呼び出しの Prism 直呼び(`app/Prompts/` の factory 経由のみ。PromptGuardrailTest が検出)
6. prompt 文字列のコード直書き(`resources/prompts/*.yaml` に置く)
7. 操作系 POST の応答での `redirect()->intended()`(ログイン直後フロー専用。
   招待送信等は `back()->with(...)` で完結させる)
8. 必須条件未充足を理由にボタンを disabled にする UI(押下時にエラー表示する。DESIGN.md)


【セキュリティ不変条件】
## セキュリティ不変条件(アプリ都合で緩めない)

詳細と実装手順は `docs/app-integration-guide.md` §7。すべて Architecture テストで強制されている:

1. **tenant キー不信**: ownership/actor/tenant キーを payload から受け取らない
   (`ProhibitsProtectedKeys` + `MassAssignmentSafetyTest`)
2. **子は親に属する**: nested route の不整合は**認可より前に 404**
   (`NestedRouteIdorDefenseTest` の inventory に登録必須)
3. **cross-org 不可**: 組織を跨ぐ read/write をしない(relation / org-scoped 解決経由のみ)
4. **untrusted 文字列は UserInput 型経由でのみ prompt に入れる**
5. **権限判定は常に `laratrust_team_id` を明示**(strict_check=true)
6. **PII(email/name)は CipherSweet**。検索は `whereBlind()`(平文 where は hit しない)
7. **課金の冪等性**: webhook は冪等マシン経由、チケットは reserve→commit/release の 2 フェーズ
8. **外部 URL 取得は SSRF 検査経由**: 外部 URL(特にユーザ入力由来)を取得する機能は
   必ず `Kent013\SsrfPin\UrlSafetyInspector` / `PinnedHttpClient` を通す。
   安全境界は `config/ssrf-pin.php` に pin する(`SsrfPinBoundaryTest` が pin 値を固定)


【ツール使用制限】
コマンド実行・ファイル書き込みは行わない。ファイル読み込みは許可。
AI-CUE=/workspace（worktree=/workspace/.claude/worktrees/tasks/T073）、参照実装 aigenba=/tmp/aigenba が読める。

---

あなたは経験豊富なコードレビュアーです。Laravel + Svelte の実装をレビューしてください。

【前提】
- PHP 8.4 / Laravel 12 / Cashier(Stripe) / Svelte 5 / Inertia / PHPStan level 10 / Pest
  (RefreshDatabase グローバル・--parallel、個別 DatabaseTransactions 禁止、テストデータは Factory)
- 本 PR は決済ドメイン aigenba parity 設計の **P2 (T073 = サブスク層 + 判定モデル)** の実装。
- **P1 (T072) はマージ済み**（PlanCode 5 case / PersonalPlanService / free_plan_code・signup marker 列 /
  plans.is_active / D28 = 全 tier monthly_ticket_grant=0 / 移行期規約）。

【本件の最重要方針 (v2)】
**aigenba verbatim で移植する。「parity より良い設計」を持ち込まない**。
逸脱してよいのは **AGENTS.md の禁止事項・セキュリティ不変条件に抵触する場合のみ**（実装者の設計判断は根拠にしない）。
**後方互換は考慮不要**（未リリース。ユーザー明示）。
**撤回済みで使用禁止**: EffectivePlan / NoPlan / GrandfatheredLegacyFreePlan / isDeclared() / debt。

【P2 の DoD（設計より）】
`BillingAccess::state()` と `SubscriptionService::deriveEntitlement()` が aigenba verbatim で入り、
`hasActiveAccess()` が `state()->grantsAccess() || $org->plan_code === null`（**移行 OR 1 行。P4 で削除**）になる。
**DoD は「挙動不変」を主張しない**。P2 で結論が変わる cohort が 2 つある:
- **cohort C**（active/trialing + trial_ends_at <= now + has_payment_method=false）= 現行 許可 → **P2 遮断**
- **cohort D**（past_due + trial 未終了 or PM 有り）= 現行 遮断 → **P2 許可**
既存行は backfill で `has_payment_method=true` → **デプロイ時点の cohort C は空**。

【レビュー観点】
1. **設計との一致性**（P2 セクション）。**設計に無いものを足していないか**。**aigenba verbatim から不必要に逸脱していないか**。
2. 正確性（ロジック、エッジケース、null 安全、**並行性**）/ 3. PHPStan level 10（widen/baseline/ignore を使っていないか）
4. テスト網羅性（**cohort A〜I が全て固定されているか**、既存テストを削除していないか、Factory 生成か）
5. DTO / Inertia props / 6. セキュリティ（不変条件 #1 tenant キー不信 / #7 課金の冪等性）
7. 副作用・後退リスク / 8. DESIGN.md・Atomic Design 準拠

【特に見てほしい点】
- **`BillingAccess::state()` が aigenba verbatim か**（`plan_code` を判定に使っていないか / read 経路で DB 書き込みをしていないか /
  stale 境界が排他か）。**移行 OR の位置と、P4 で 1 行削除すれば反転が完了する形になっているか**。
- **cohort 表 A〜I がテストで固定されているか**（特に C 遮断 / D 許可 / I 移行 OR）。
- **`recordPaymentMethodSnapshot` の monotonic ガード・行不在の早期 return・tx + `lockForUpdate`**。
- **webhook の「行の materialize 順序」**（Cashier の `WebhookController` が唯一の writer である前提を壊していないか。
  aigenba の `Subscription::create()` を移植していないか = 原則 4）。
- 実装者が報告した**設計からの逸脱 4 件**が妥当か:
  1. `BillingCheckoutSession::$fillable` から `organization_id` / `initiated_by_user_id` を除外
     （`MassAssignmentProtectedKeys` + `MassAssignmentSafetyTest` = **不変条件 #1** に抵触するため。aigenba は fillable）
  2. `assertCheckoutReady()` 非移植（AI-CUE の Organization に請求先メール列が無く `stripeEmail()` が常に null →
     移植すると checkout/portal が全 org で throw する = 原則 4）
  3. `applySubscriptionSnapshot` は DTO を返さず void（呼び出し元が戻り値未使用 = dead code 回避）
  4. `current_period_end` は snapshot が null のとき書かない（null 上書きは renewal reminder の真実源を壊す）

【出力形式】
- ファイルごとに判定: APPROVE / REQUEST_CHANGES
- 指摘は [Critical] [Warning] [Suggestion] で分類 / Critical・Warning には修正案を必ず添える
- 全体判定: APPROVED / CHANGES_REQUESTED
- 日本語で出力

---

## 詳細設計書（横断決定 v2 + P2 セクション）

# 詳細設計: aigenba-billing-parity（決済ドメインを aigenba に全面一致させる）

## 使命・制約（絶対遵守）

### アプリの使命（North Star）

**AI-CUE** は、現場に既にある**作業手順書(SOP)を起点に**、AI が撮るべきカットを設計した**動画シナリオ**を生成し、
そのシナリオを**スマホ(PWA)でナビゲーション撮影**することで、専門知識ゼロの現場作業者でも**標準化されたマニュアル動画**を
作れるようにする。

- 「思考ゼロ・編集ゼロ」— 台本作成・撮影判断・編集の 3 ハードルを AI とナビ撮影で肩代わりする。
- 競合(OJT を撮って形式化する tebiki)と異なり、**標準作業を起点に AI が教材設計し撮影を指示する**。
- 熟練者の暗黙知を動画マニュアルという形式知へ変換する装置（SECI）。

> **決済は North Star 本体ではなく支持基盤**。本設計の価値は「現場作業者が残高・契約で作業を止められない」ことと
> 「保守コストを下げる」ことに限定される（効果を誇張しない）。

### 禁止事項（AGENTS.md 正本）

1. テストなしの実装完了報告（不変条件は Architecture/Feature テストへの登録まで含めて「実装済み」）
2. PHPStan エラーの widen（型を緩めて黙らせる）・baseline 化
3. dev DB への破壊操作（`migrate:fresh` 等）をエージェント判断で実行すること
4. `response()->json()` の直書き（DTO / JsonResource / Inertia を使う。仕様固定 endpoint のみ例外）
5. LLM 呼び出しの Prism 直呼び（`app/Prompts/` の factory 経由のみ）
6. prompt 文字列のコード直書き（`resources/prompts/*.yaml` に置く）
7. 操作系 POST の応答での `redirect()->intended()`（ログイン直後フロー専用）
8. **必須条件未充足を理由にボタンを disabled にする UI（押下時にエラー表示する。DESIGN.md）**

### セキュリティ不変条件（アプリ都合で緩めない。AGENTS.md）

本設計に直結するもの:

- **#7 課金の冪等性: webhook は冪等マシン経由、チケットは reserve→commit/release の 2 フェーズ** ← **維持する**
- #1 tenant キー不信 / #2 子は親に属する（不整合は認可より前に 404）/ #3 cross-org 不可
- #5 権限判定は常に `laratrust_team_id` を明示（strict_check=true）/ #6 PII は CipherSweet

### コーディングルール

- **PHPStan level 10** 必須（`composer phpstan`）/ **Pest**（`composer test`）
- **RefreshDatabase** + `--parallel`（`tests/Pest.php` でグローバル適用。個別 `DatabaseTransactions` 禁止）
- **テストデータは必ず Factory で生成**（`Model::create()` 手組み禁止）。新モデルには Factory も作る
- **DTO + JsonResource** パターン / アーリーリターン推奨
- `composer fix`（Pint）/ `pnpm lint:fix`
- PHP 8.4 + Laravel 12 + Cashier(Stripe) + Svelte 5 runes + Inertia + TypeScript
- UI: **DS token のみ**（hex 直書き禁止。DESIGN.md が canonical）/ アイコンは `@lucide/svelte` /
  **T071 の primitive**（`templates/PageContainer`・`molecules/PageHeader(Section)`・`templates/PageContent`）準拠
  （arch: page-shell-structure / ds-purity / atomic-import-graph / lucide-scoped-import / svg-inline-allowlist）

## 概念設計リファレンス

- [conceptual-design.md](conceptual-design.md)（Codex gpt-5.4 合議 **APPROVED** / Round 3）
- 接地監査: [../20260716-2339-aigenba-billing-audit/audit-report.md](../20260716-2339-aigenba-billing-audit/audit-report.md)（49 findings）

### ユーザー決定（前提。再検討しない）

| # | 分岐 | 決定 |
|---|------|------|
| F1 | 課金ゲート | **aigenba 方式へ反転**（未契約は遮断 → checkout / activate-personal） |
| F2 | signup grant 付与契機 | **aigenba 方式へ**（登録時 → プラン有効化時。marker が真実源） |
| F3 | チケット会計 | **aigenba 方式へ**（= 残高会計の**精緻化**。台帳の置換ではない） |
| スコープ | — | **4 つ全部**（裏チャージ / 判断不要 15 件 / プランモデル / チケット会計） |

## 横断決定（v2: aigenba verbatim へ全面差し戻し）

> **2026-07-17 の方針転換（ユーザー指摘 +  aigenba 側の検証結果を受けた全面改訂）**
>
> ユーザー指摘: 「**基本的に全部揃える方向だよ。揃えてから問題を調整してもらいたい**」
> 「**値段を憶測に基づいていじるよりもロジックを合わせて欲しい**」
> 「指摘されたバグが aigenba に存在しているバグなのかどうかをちゃんと検証してください」
>
> 検証（`bug-origin-verification.md`）の結果、**Codex が指摘した 7 件のうち 5 件は「私が aigenba から逸脱して
> 発明した独自実装」が原因**であり、**aigenba 通りに移植していれば発生しなかった**ことが確定した。
> よって **v1 の横断決定のうち、私の設計判断に由来するものを全て撤回し、aigenba verbatim へ戻す**。

### 原則（v2）

1. **aigenba verbatim で移植する**。「parity より良い設計」を持ち込まない（それがバグの発生源だった）。
2. 逸脱してよいのは **AGENTS.md の禁止事項・セキュリティ不変条件に抵触する場合のみ**（私の設計判断は根拠にしない）。
3. **値は aigenba の既定値をそのまま使う**（憶測で調整しない）。
4. AI-CUE に対象が存在しない aigenba 機能は移植しない（席課金 / encounter 等）。
5. **aigenba にある問題は AI-CUE 側で先回り修正しない**。aigenba 側で修正されたらそれを取り込む。

### 撤回する v1 の決定（私の発明。aigenba verbatim へ戻す）

| 撤回 | 戻す先（aigenba verbatim） | 撤回理由 |
|---|---|---|
| ~~D18 / D23~~（`EffectivePlan` 4 variant の新設） | **`App\Enums\Billing\OnboardingBillingState`（5 状態: NoSubscription / PendingCheckout / ExpiredCheckout / Subscribed / **ActiveFreePlan**）+ `BillingAccess::state()` をそのまま移植**。`grantsAccess() = Subscribed \|\| ActiveFreePlan` | 私の 4 variant は **aigenba の 5 状態の劣化コピー**だった（`ActiveFreePlan` ≡ 私の Grandfathered、`NoSubscription` ≡ 私の NoPlan）。**畳んだせいで「NoPlan 欠落」バグを自作**した。D18 の根拠「checkout session テーブルが無いので Pending/Expired を表現できない」も、**P9 でそのテーブルを追加する自分の設計と矛盾**していた |
| ~~D26~~（`plan_code` 依存の解決順） | **`$org->subscription('default')` + `deriveEntitlement($sub)->entitled` で判定**（**`plan_code` を一切見ない**） | aigenba は元から plan_code を見ない。**私が plan_code 依存にしたから**「同期ラグ組織の締め出し」「支払い不健全の素通し」を自作した |
| ~~D19 / D24 / D27~~（debt 保全・数式・reserve への反映） | **`max($monthly, 0)` / `max($purchased, 0)` の per-source clamp をそのまま移植**（**debt 概念を持たない**） | aigenba に debt は存在しない。**私が発明したから**「二重回収」「reserve が debt 無視」を自作した |
| ~~D10~~（personal/starter を `is_active=false` で seed） | **`is_active=true` で公開**（`PlanSeeder` verbatim。「Personal は…`is_active=true` で公開する」と明記されている） | **私の発明**。これが「P4 反転時に無料導線が非公開」バグを生んだ |
| ~~D1 / D2~~（`PlanCode` を 3 case に縮小・Enterprise 特判の削除） | **`PlanCode` を verbatim 移植**（Personal / Starter / Standard / Business / Enterprise の 5 case）。`normalizeRaw` の Enterprise 除外も verbatim | 縮小は私の判断。case を verbatim にすれば `normalizeRaw` も verbatim で通り、PHPStan の `alwaysFalse` も起きない |
| ~~U1 / U2 / U3~~（値の再検討） | **aigenba の既定値をそのまま**（`default_threshold=5` / `default_max=50` / `max_count=1000` / `max_failures=3`）。低残高通知との併存・reserve TTL も aigenba のまま | 「可変コストだから合わないかも」は**実測せずに言った憶測**だった（実測では AI-CUE の既存 `ticket_low_balance_threshold=5` と完全一致）。**値を憶測でいじらない** |

### 維持する決定

| ID | 論点 | 決定 | 根拠 |
|---|---|---|---|
| **D5** | reserve プリミティブ | **amount ベースを維持**（aigenba の 1 encounter=1 枚は移植しない） | AI-CUE の消費は解析 1 枚 / レンダ 3 枚の**可変コスト**（`manual.analysis_ticket_cost` / `render_ticket_cost`）。機械移植すると単価差の前提が壊れて課金が壊れる。**ドメイン要件であり私の設計判断ではない**（Codex も妥当と判定） |
| **D4** | aigenba の disabled ボタン | **移植しない**。押下時にエラー表示 | **AGENTS.md 禁止事項 #8**（原則 2） |
| **#7** | reserve→commit/release の 2 フェーズ | **維持** | AGENTS.md 不変条件 #7。**aigenba も同じ**なので実は逸脱ですらない |
| **#6** | 請求先 PII | **email / name の両方を CipherSweet 化**（aigenba は平文 string） | **AGENTS.md 不変条件 #6**（原則 2） |
| **D6 / D21** | route スコープ | **route parameter を持たない current-org スコープ**（`onboarding.{checkout,activate-personal,billing-required}`） | AI-CUE の業務 route が current-org スコープ（`routes/web.php:349`）。org-slug 化は AI-CUE 全体の route 規約変更でスコープ外 |
| **D12** | `config/quota.php` の plan キー | **P1 で `personal` / `starter` の limits を必ず追加** | `QuotaService.php:33` が未知キーを `?? []` = **無制限に silent 退行**させる |
| **D13** | 移行期の `claimSignupGrantMarker()` public 化 | 許容（P6 で private へ戻す） | 移行期規約（付与と marker を同一 tx）の成立に必要 |
| **D14** | `PlanPriceService` への `?string $lookupKey` 追加 | 許容 | AI-CUE の `SyncStripePrices.php:78-87` が current 行の `lookup_key` 一致を要求。verbatim だと既存 sync 契約が壊れる |
| **D11** | 既存 `free` Plan 行と `fallback_plan='free'` | **P4 で撤去**（`personal` が後継。data migration + 残余 0 件検証。rollback は「コード/config revert → migration down」の運用手順） | free fallback の消滅とゲート反転は同一の意味変更 |
| **D22** | P4 backfill の集合同値検証 | migration テストで **SQL 更新 ID 集合 == 分類表 grandfather 対象 ID 集合**の双方向完全一致 | 分類表を文書で終わらせず機械検証に落とす |
| **D25** | subscription checkout の冪等 / 着地 feedback / 請求先 | **P9 へ切り出す**。ただし **`BillingCheckoutSession` テーブルは `state()` の Pending/ExpiredCheckout が読むため P2 に前倒す**（v2 で変更） | `OnboardingBillingState` を verbatim 移植する以上、当該テーブルは状態モデルの一部 |
| **D15** | JSON/XHR への 402 | 維持 | 既存 API/XHR クライアントの後退を避ける |
| **D16** | `Welcome.svelte` の `/register` 直リンク | **P7 で `/pricing` 誘導へ**（aigenba の Landing は直リンクを持たない） | F1 でプラン選択が必須関門になる以上、直リンクは矛盾 |
| **D17** | チケット単位 | 「枚」を維持 | AI-CUE 全体の既存語彙 |

### D28（新規・重要）: 月次チケット付与を廃止する — clamp 移植とセットでしか成立しない

**aigenba は月次付与を廃止済み**（`PlanSeeder`: 全 tier `included_monthly_tickets = 0`。施策8/v3
「**月次付与は廃止。チケットは都度購入 / オートリチャージ**」）。`CreditSource::PlanMonthly` で行が入るのは
**signup grant の 10 枚のみ**（30 日期限・org 生涯 1 回）。

**AI-CUE は月次付与が生きている**（`PlanSeeder.monthly_ticket_grant`: free 10 / standard 100。
`StripeWebhookProcessor::grantMonthlyTickets()` が `invoice.paid` で発火）。**課金モデルの根本が違う**。

**これは per-source clamp の移植と不可分**: aigenba 側の「clamp は現行モデルでは実質 no-op（債務の逃げ道になる
生きた source が無い）」という判断は、**「月次付与が廃止済み」という前提に立つ**。**月次付与を残したまま clamp だけ
移植すると、aigenba では死んでいる経路が AI-CUE では生きる**（月次が債務の逃げ道になる）。

**決定: parity 方針に従い AI-CUE も月次付与を廃止する。**
- `database/seeders/PlanSeeder.php`: **全 tier の `monthly_ticket_grant` を 0** にする。
- コード経路は**変更しない**。`grantMonthlyTickets()` は既存 guard `$plan->monthly_ticket_grant <= 0` で抜けるため、
  aigenba の `if ($count < 1) return;` と**同形**になる（`StripeWebhookProcessor.php:274`）。
- signup grant（10 枚・`TicketSource::Monthly`・30 日期限）は**据え置き**（aigenba と同一）。
- **プロダクト影響（明示）**: standard の「月 100 枚」・free/personal の「月 10 枚」が**無くなる**。
  チケットは**都度購入 + オートリチャージのみ**になる。これは値の調整ではなく**モデルそのもの**であり、
  「全部揃える」= こうなる、という意味。
- 波及: `tests/Feature/Billing/TicketGrantTest.php`（月次付与の期待）/ `MonthlyGrantTest` 系 /
  `BughuntBillingSeeder` / `ManualTestSeeder`（dev シードの最低保証）/ 料金表の文言（「月 N 枚」表記があれば）。
  **既存テストは削除せず期待を更新**する。

### aigenba 側で CLOSED になった件（AI-CUE は verbatim 移植する）

私が aigenba へ報告した「返金債務が回収されない経路」は、**aigenba 側の実行検証で不成立と判定**された
（私の再現手順が消費優先 monthly→purchased と矛盾していた。詳細は `aigenba-handoff.md` の CLOSED 注記）。
先方は **当面この挙動を変更せず**、**verbatim 移植で問題なし・往復は発生しない**と回答。
`TicketRefundClawbackTest:147` の期待 **`-2` → `0`** への更新も先方確認済み。


## 施策一覧

| # | 施策名 | 主な変更ファイル | 優先度 | 単独マージ時の安全性 |
|---|--------|------------|--------|---|
| **P1** | プラン基盤（PlanCode / free plan・marker 列 / PersonalPlanService / seeder / backfill） | `app/Enums/PlanCode.php`, `app/Services/Billing/{PersonalPlanService,PlanPriceService}.php`, migrations ×2, `database/seeders/PlanSeeder.php`, `config/quota.php` | Critical | 挙動不変（ゲート未反転・列は additive） |
| **P2** | サブスク層 + **判定モデル**（`OnboardingBillingState`(5 状態) + `BillingAccess::state()` を verbatim 移植 / `SubscriptionService::deriveEntitlement()` / `SubscriptionSnapshot` / `BillingCustomerSynchronizer` / `BillingPermissionService` / **`BillingCheckoutSession` テーブル**（state() が読むため P9 から前倒し）） | `app/Services/Billing/*`, `app/Enums/Billing/OnboardingBillingState.php`, `app/Models/Billing/BillingCheckoutSession.php` + migration | Critical | **挙動不変ではない**（判定モデルの置換。**cohort C（trial 終了 + PM 無し）が遮断へ / D（past_due + PM 有り）が許可へ反転**。既存行の `has_payment_method=true` backfill により**デプロイ時点の cohort C は空**）|
| **P3** | Onboarding 最小導線（**ゲート反転より前**に導線を実在させる = F-07 条件 A） | `app/Http/Controllers/Onboarding/*`, `resources/js/pages/Onboarding/{Checkout,BillingRequired}.svelte`, `routes/web.php` | Critical | 安全（導線が増えるだけ） |
| **P4** | **ゲート反転 + grandfathering 移行**（山場） | `app/Services/Billing/BillingAccess.php`, `app/Http/Middleware/RequireActiveSubscription.php`, backfill migration | Critical | 条件 A（P3）+ 条件 B（backfill）を満たして初めて安全 |
| **P5** | チケット残高会計の精緻化（per-bucket / per-source 失効 / 消費優先 / commit-wins） | `app/Services/Billing/TicketLedgerService.php`, `app/DataTransferObjects/Billing/TicketBalanceDto.php`, additive 列 | High | 安全（additive 列 + 読み取り計算） |
| **P6** | signup grant 契機変更（F2）+ **LP 文言** | `app/Actions/Fortify/CreateNewUser.php`, `app/Services/Billing/{PersonalPlanService,StripeWebhookProcessor}.php`, `resources/js/pages/Welcome.svelte` | High | 安全（marker は P1 で導入済み） |
| **P7** | 新規登録経路（IntendedPlanResolver / continuation / `?plan=` handoff） | `app/Services/Onboarding/*`, `app/Support/Auth/EmailVerificationContinuation.php`, `app/Providers/FortifyServiceProvider.php` | Medium | 安全（導線の質向上） |
| **P8a** | 裏チャージ（オートリチャージ + リコンサイル） | `app/DataTransferObjects/Billing/AutoRecharge*`, `app/Console/Commands/Billing/ReconcileAutoRechargeAttempts.php`, migration | Medium | 安全（opt-in・既定 off） |
| **P8b** | 課金 UI parity + 監査の判断不要 15 件（**D25: checkout feedback / billingContact は除く**） | `resources/js/pages/{Guest/Pricing,Billing/Plans,Billing/Index,Billing/PurchaseTickets}.svelte`, `_helpers/PlanCard.svelte` | Medium | 安全（UI のみ） |
| **P9** | サブスク checkout の冪等配線・着地 feedback + 請求先情報（**D25**。**`BillingCheckoutSession` テーブル自体は P2 へ前倒し済み**） | `subscriptionAttemptToken` の冪等状態機械, `resolveBillingFeedback`, billing contact 列（**email/name とも CipherSweet**）+ 更新 Action, `Billing/Index` の feedback バナー | Low | 安全（追加機能） |

**依存順（交渉不可）**: `P1 → P2 → P3 → P4`（導線 → ゲート反転）。以降 `P5 → P6`（marker 前提）、`P7`、`P8a → P8b`、`P9`（P8b の後）。
**実装 TODO は 10 本**（P1/P2/P3/P4/P5/P6/P7/P8a/P8b/P9）。

## 実装モード

| 項目 | 内容 |
|------|------|
| 推奨モード | **standalone**（全フェーズ） |
| 判断根拠 | 金銭ドメイン・課金ゲート・約60 テストファイルへの波及を含み、各フェーズが複数コンポーネントの協調変更になる。並行実装は移行順序（導線 → ゲート反転）の不変条件を壊す危険がある |
| 競合リスク | フェーズ間は**直列前提**。P1 の marker/列を P4・P5・P6 が参照するため、順序を崩すと F-07 再発（P3→P4 逆転）または二重付与（P1 未了で P6）が起きる |

---

# 各施策の詳細

### P2 サブスク層 + 判定モデル: `OnboardingBillingState` / `BillingAccess::state()` の verbatim 移植と Gateway 系置換

前提: P1 で `App\Enums\PlanCode`（5 case・`requiresStripeCheckout()`）/ `PersonalPlanService`（`FREE_PLAN_CODE='personal'`）/ `organizations.{free_plan_code, free_plan_activated_at, personal_declared_at, personal_declared_by_user_id, signup_tickets_granted_at}` + partial unique index / `PlanPriceService` / `plans.is_active` が入っている。

**DoD**: `BillingAccess::state()` と `SubscriptionService::deriveEntitlement()` が aigenba verbatim で入り、`hasActiveAccess()` が `state()->grantsAccess()` + **移行 OR 1 行**（`$org->plan_code === null`。P4 で削除）になる。migration は additive のみ（`billing_checkout_sessions` 新規 + `subscriptions.has_payment_method` 追加 + backfill）。route 変更ゼロ・Inertia props 変更ゼロ・TypeScript 型変更ゼロ。

**DoD は「挙動不変」を主張しない**（Round 13 Critical を受けて撤回）。P2 は判定モデルそのものの置換であり、**`hasActiveAccess()` の結論が変わる cohort が 2 つある**（下表 C / D）。`state()` は現行に対応物が無い新 API のため、`state()` 側は「同値」概念自体が成立しない。

#### P2 導入で結論が変わる cohort（全列挙）

現行 `BillingAccess::hasActiveAccess()`（`/workspace/app/Services/Billing/BillingAccess.php:36-51`）= 「`plan_code === null` → 許可 / 非 null → `subscription('default')` が存在し `stripe_status ∈ GRANTING_STATUSES(['active','trialing'])`」。
P2 = `state()->grantsAccess() || $org->plan_code === null`。移行 OR が `plan_code === null` を丸ごと保存するため、**変化は `plan_code` 非 null 側にのみ発生する**。

| # | cohort（`plan_code` 非 null） | 現行 | P2 | 変化 |
|---|---|---|---|---|
| A | `active`/`trialing`・`trial_ends_at` が null または未来 | 許可 | `Active`（または `UpgradeRecovery`）→ `Subscribed` = 許可 | なし |
| B | `active`/`trialing`・`trial_ends_at <= now`・`has_payment_method=true` | 許可 | `Subscribed` = 許可 | なし |
| **C** | **`active`/`trialing`・`trial_ends_at <= now`・`has_payment_method=false`** | **許可**（status だけを見るため） | **`denied(TrialEndedWithoutPaymentMethod)` → `ExpiredCheckout` = 遮断** | **P2 で遮断へ反転** |
| **D** | **`past_due`・（`trial_ends_at` が null/未来 または `has_payment_method=true`）** | **遮断**（`past_due ∉ GRANTING_STATUSES`） | **`PastDue`→`grantsAccess()=true`→`Subscribed` = 許可** | **P2 で許可へ反転** |
| E | `past_due`・`trial_ends_at <= now`・`has_payment_method=false` | 遮断 | `denied(TrialEndedWithoutPaymentMethod)` → 遮断 | なし |
| F | `paused` | 遮断 | `Paused` → `denied(Paused)` → `ExpiredCheckout` = 遮断 | なし |
| G | `canceled` / `unpaid` / `incomplete` / `incomplete_expired` | 遮断 | `Inactive` → `denied(NoActiveSubscription)` → `ExpiredCheckout` = 遮断 | なし |
| H | subscription 行なし | 遮断 | `NoSubscription` = 遮断 | なし |
| I | `plan_code === null`（sub 行の有無・status を問わず） | 許可 | state の結論に依らず**移行 OR で許可** | なし |

**根拠**: `/tmp/aigenba/app/Services/Billing/SubscriptionService.php:126-155`（`deriveEntitlement`）/ `/tmp/aigenba/app/Enums/Billing/SubscriptionState.php:49-98`（`fromSubscription` / `grantsAccess`）/ `/tmp/aigenba/app/Services/Billing/BillingAccess.php:31-93`（`state`）。
**cohort C は「P4 分類2 の反転目的」ではなく P2 で起きる**。P4 の判定変更は移行 OR 1 行の削除（= cohort I の反転）**だけ**であり、C / D は P2 の成果物として DoD・テスト・分類表に載せる。

**cohort C の実データ露出と backfill の効果**:
- `subscriptions.has_payment_method` の既定値は **aigenba verbatim で `false`**（`/tmp/aigenba/database/migrations/2026_06_25_090100_add_signup_trial_columns_to_subscriptions.php`）。
- **既存行は backfill で `true`** にする → **P2 デプロイ時点の cohort C は空**（既存の有償 org は 1 件も締め出されない）。根拠: AI-CUE の subscription 生成経路は `CashierSubscriptionCheckoutGateway::createSubscriptionCheckout()`（`newSubscription('default',…)->checkout()` = mode=subscription）のみで PM 収集が必須 → 既存行の事実値は `true`。aigenba の default `false` は「trial 中カード無し signup」経路が存在する前提の値で、その経路を持たない AI-CUE の既存行には当てはまらない。`recordPaymentMethodSnapshot()` は monotonic（`true→false` に戻さない。`/tmp/aigenba/app/Services/Billing/SubscriptionService.php:390-393`）なので backfill 値は以後保存される。
- **P2 以降に作られる行**は default `false` から始まる。`trial_ends_at` を set する app コードは AI-CUE に存在しない（`grep -rn "trial_ends_at" app/` はヒットなし）ため、`trial_ends_at` が入るのは Cashier `WebhookController.php:74-75,161-165` が Stripe payload の `trial_end` を写す場合のみ = **Stripe 側（Price / Dashboard）で trial を設定した契約に限る**。この場合 trial 中は `trial_ends_at` が未来なので cohort A（許可）で、trial 終了時に Stripe が発火する `customer.subscription.updated` が `recordPaymentMethodSnapshot()` を通して `has_payment_method=true` を確定させる。**webhook 到達までの窓で cohort C になるのは aigenba が意図した「webhook の paused 化前でも先回り遮断」そのもの**（`SubscriptionService.php:138` のコメント）であり、先回り修正しない（原則 1・5）。

**行の materialize 順序（P2 の契約として固定する）**: AI-CUE の `StripeWebhookProcessor` は `Event::listen(WebhookReceived::class, …)`（`/workspace/app/Providers/AppServiceProvider.php:188`）で、Cashier は `WebhookReceived::dispatch()` を**ハンドラ実行前**に発火する（`vendor/laravel/cashier/src/Http/Controllers/WebhookController.php:45-49`）。よって `customer.subscription.created` の時点では行が未作成で、`recordPaymentMethodSnapshot()` は **行不在の早期 return（verbatim）** で no-op になり、最初の権威 PM 書込は最初の `customer.subscription.updated` に載る。**aigenba の `applySubscriptionSnapshot` 末尾の `Subscription::create($attrs)` は移植しない**: aigenba は Cashier の `WebhookController` を使わず自前 `StripeWebhookController` が唯一の writer である（`/tmp/aigenba/app/Http/Controllers/Billing/StripeWebhookController.php`）のに対し、AI-CUE は Cashier のハンドラを使う。listener 側で先に行を作ると Cashier 側の `! $user->subscriptions->contains('stripe_id', $data['id'])` ガード（`WebhookController.php:73`）が false になり **`subscription_items` の生成（同 94-101）が永久に skip される**。移植すると既存契約が壊れる = 原則 4（AI-CUE に対象が存在しない aigenba 機能は移植しない）の適用。

#### 変更箇所

| ファイル（AI-CUE） | 何をするか | 移植元（aigenba） |
|---|---|---|
| `app/Enums/Billing/OnboardingBillingState.php`（新規） | **verbatim**。`NoSubscription` / `PendingCheckout` / `ExpiredCheckout` / `Subscribed` / `ActiveFreePlan` の 5 case + `grantsAccess() = Subscribed \|\| ActiveFreePlan`。docblock も移植 | `/tmp/aigenba/app/Enums/Billing/OnboardingBillingState.php` |
| `app/Enums/CheckoutSessionStatus.php`（新規） | **verbatim**（`Pending` / `Completed` / `Failed` / `Expired`）。名前空間も verbatim（P1 の `app/Enums/PlanCode.php` と同じ配置） | `/tmp/aigenba/app/Enums/CheckoutSessionStatus.php` |
| `app/Enums/CheckoutIntent.php`（新規） | `SubscriptionStart='subscription_start'` / `SetupPaymentMethod='setup_payment_method'` の 2 case。`CreditPurchase` は AI-CUE では既存の別テーブル `app/Models/Billing/TicketCheckoutSession.php` が担い、`SignupFunding` は campaign / trial 機構（`signup_campaigns`）が無いため移植しない（原則 4） | `/tmp/aigenba/app/Enums/CheckoutIntent.php` |
| `database/migrations/2026_07_17_000200_create_billing_checkout_sessions_table.php`（新規） | aigenba の 6 本（create + unit_amount + attempt_token + signup_funding + initiated_by + pm_reuse）を **create 1 本に畳んで移植**。列: `id` / `organization_id`(FK cascade) / `initiated_by_user_id`(FK users nullOnDelete) / `intent`(32) / `plan_code`(32 nullable) / `stripe_session_id`(unique) / `idempotency_key`(128 unique) / `attempt_token`(nullable) / `checkout_url`(2048 nullable) / `status`(16 default `'pending'`) / `completed_at` / `timestamps`。index: `['organization_id','intent','status']` + unique `['organization_id','intent','attempt_token']`（名 `billing_checkout_sessions_org_intent_attempt_unique`）。**`seats`（席概念なし）/ `credit_count`・`unit_amount`（ticket 側テーブルが担う）/ `funding_choice`・`topup_count`・`applied_campaign_id`・`applied_trial_days`（campaign・trial 機構なし）/ `pm_reuse_dispatched_at`（`ReuseSubscriptionPaymentMethodJob` を移植しない）は列ごと非移植**（原則 4。必要になった P8a/P9 で additive 追加） | `/tmp/aigenba/database/migrations/2026_04_14_011321_create_billing_checkout_sessions_table.php` ほか 5 本 |
| `app/Models/Billing/BillingCheckoutSession.php`（新規） | **verbatim**（移植列に限定）。`$fillable` / `$casts` / `intentEnum()` / `statusEnum()` / `isReplayablePending()` / `organization()` + `@property` docblock | `/tmp/aigenba/app/Models/Billing/BillingCheckoutSession.php` |
| `database/factories/Billing/BillingCheckoutSessionFactory.php`（新規） | `definition()`（`intent=subscription_start` / `stripe_session_id='cs_'.Str::random(24)` / `idempotency_key='checkout:'.Str::uuid()` / `status=pending`）+ `withAttemptToken()` / `initiatedBy()` / `completed()` / `setupPaymentMethod()`。**`creditPurchase()` / `signupFunding()` は非移植列を触るため落とす**。`state()` の分岐 4/5 を固定するため `expired()` / `failed()` / `stale()`（`created_at = now()->subDays(2)`）を足す（aigenba の同 factory に無い分の追加は **新モデルには Factory を作る / テストデータは Factory で生成**という AGENTS.md コーディングルール由来） | `/tmp/aigenba/database/factories/Billing/BillingCheckoutSessionFactory.php` |
| `app/Enums/Billing/SubscriptionState.php`（新規） | `Active` / `UpgradeRecovery` / `PastDue` / `Paused` / `Inactive` の 5 case + `fromSubscription()` + `grantsAccess()`。**`ScheduledForUpgrade` は非移植**（入力列 `subscriptions.pending_plan_code` が AI-CUE に無い = 原則 4）。`upgrade_recovery_required` 列も無いため当該分岐は落とし、`stripe_schedule_id !== null && schedule_setup_status === ScheduleSetupStatus::Created` の `UpgradeRecovery` 分岐のみ移植（両列は AI-CUE に実在。`2026_06_11_091200_create_subscriptions_table.php`）。評価順（paused → past_due → 非 active/trialing → recovery）は verbatim。`isTerminated` / `isTerminalStatus` / `TERMINATED_STRIPE_STATUSES` は P2 に呼び出し元が無い（AI-CUE の終了契機は `customer.subscription.deleted` のみ）ため移植しない | `/tmp/aigenba/app/Enums/Billing/SubscriptionState.php` |
| `app/Enums/Billing/EntitlementDeniedReason.php`（新規） | **verbatim 3 case**（`NoActiveSubscription` / `TrialEndedWithoutPaymentMethod` / `Paused`）+ docblock | `/tmp/aigenba/app/Enums/Billing/EntitlementDeniedReason.php` |
| `app/DataTransferObjects/Billing/SubscriptionEntitlementDto.php`（新規） | **verbatim**（`entitled` / `state` / `reason` + `granted()` / `denied()` / `toArray()` + `@phpstan-type EntitlementShape`） | `/tmp/aigenba/app/DataTransferObjects/Billing/SubscriptionEntitlementDto.php` |
| `database/migrations/2026_07_17_000210_add_has_payment_method_to_subscriptions.php`（新規） | `subscriptions.has_payment_method`(boolean NOT NULL **default false**, after `trial_ends_at`) を追加。**`deriveEntitlement` verbatim の入力**。同 aigenba migration の他 4 列（`trial_redeemed_at` / `applied_campaign_id` / `applied_trial_days` / `signup_initial_tickets_granted_at`）は campaign / trial / signup-funding 機構が無いため非移植（原則 4） | `/tmp/aigenba/database/migrations/2026_06_25_090100_add_signup_trial_columns_to_subscriptions.php` |
| `database/migrations/2026_07_17_000220_backfill_has_payment_method_on_subscriptions.php`（新規） | **列追加と分離した data migration**（P1 の `backfill_signup_tickets_granted_at` と同じ構造）。既存全 `subscriptions` 行を `has_payment_method = true` へ。`where('has_payment_method', false)` ガードで冪等、`down()` は意図的 no-op。**この backfill が cohort C を P2 デプロイ時点で空にする**（上記「backfill の効果」） | 構造は `/tmp/aigenba/database/migrations/2026_07_08_113550_backfill_signup_tickets_granted_at.php` |
| `app/Models/Billing/Subscription.php` | `@property bool $has_payment_method` を docblock へ、`casts()` に `'has_payment_method' => 'boolean'` を追加。`$guarded = ['id','organization_id']` は不変 | `/tmp/aigenba/app/Models/Billing/Subscription.php` |
| `app/Services/Billing/SubscriptionSnapshot.php`（新規） | 値オブジェクト。`stripeId` / `status` / `basePriceId` / `baseQuantity` / `currentPeriodEnd` / `trialEndsAt` / `endsAt`。**`currentPeriodStart` は `subscriptions.current_period_start` 列が AI-CUE に無い**ため持たず、period 巻き戻し guard（`SubscriptionService.php:216-236`）も移植しない（列が無い = 移植対象が存在しない。原則 4）。`seatItemQuantity` も席概念が無いため持たない。schedule 状態を含めない契約（T666 C2）の docblock は移植 | `/tmp/aigenba/app/Services/Billing/SubscriptionSnapshot.php` |
| `app/Services/Billing/SubscriptionService.php`（新規） | サブスク層の中枢。`deriveEntitlement()`（**verbatim**）/ `applySubscriptionSnapshot()`（下記 adaptation）/ `recordPaymentMethodSnapshot(Subscription $sub, bool $hasPaymentMethod)`（`recordFundingSnapshot` の PM 単独 subset。`DB::transaction` + `lockForUpdate()->find()` + 行不在の早期 return + monotonic ガード `if ($hasPaymentMethod && ! $fresh->has_payment_method)` は **verbatim**。trial_redeemed / campaign 節は列が無いため落とす）/ `assertStripeBillablePlan()`（**verbatim**）/ `assertPriceSynced()`（**verbatim**。`app()->environment('production')` 分岐込み）/ `startCheckout()` / `createPortalSession()` / `resolvePlanCodeFromPriceId()`（**verbatim**）。Stripe I/O は Gateway 経由のみ。**`getStatus()` / `BillingStatusDto` は呼び出し側 UI が P8b 所管のため P2 では作らない**（dead code を作らない）。schedule lifecycle / seat / signup funding / `changePlan` / `upgradeNow` / `isMutableState` は非スコープ | `/tmp/aigenba/app/Services/Billing/SubscriptionService.php:126-155,204-357,359-420` |
| `app/Services/Billing/BillingAccess.php`（改修） | `state()` を **verbatim 移植**（`subscription('default')` → `deriveEntitlement($sub)->entitled` なら `Subscribed` / `free_plan_code === PersonalPlanService::FREE_PLAN_CODE` なら `ActiveFreePlan` / `$sub instanceof Subscription` なら `ExpiredCheckout` / live pending なら `PendingCheckout` / stale pending・expired・failed があれば `ExpiredCheckout` / それ以外 `NoSubscription`。**read 経路で DB 書込をしない**契約・in-memory stale 判定も verbatim）。`hasActiveAccess()` は `state()->grantsAccess()` + **移行 OR 1 行**。`GRANTING_STATUSES` 定数を撤去。ctor は `SubscriptionService` 注入（verbatim）。閾値は `staleThresholdAt()`（下記）へ切り出す | `/tmp/aigenba/app/Services/Billing/BillingAccess.php` |
| 同上（stale 境界の単一出典） | 確定事項「stale 境界は排他」を機械化するため `public static function staleThresholdAt(CarbonImmutable $now): CarbonImmutable { return $now->subDay(); }` を `BillingAccess` に置く（値 `subDay()` は aigenba `BillingAccess.php:58` verbatim）。**live = `created_at >= staleThresholdAt($now)` / stale = `created_at < staleThresholdAt($now)`**。`state()` 内は verbatim の `$row->created_at->lessThan($threshold)` = stale がこの定義と一致する。P9 の sweeper は `where('created_at','<',BillingAccess::staleThresholdAt(now()))` で同一出典を読む（`ReconcileSubscriptionSchedules.php:70,76` の `<=` 形は schedule 用の別閾値で、checkout stale とは無関係のため触らない） | Round 13 Critical 2 / 確定事項 |
| `app/Services/Billing/Contracts/StripeGatewayInterface.php`（新規。`app/Services/Billing/SubscriptionCheckoutGateway.php` を置換・削除） | 命名と名前空間のみ aigenba 形へ。**メソッドは 3 本に限定**（`createSubscriptionCheckout` / `createPortalSession` / `syncCustomerDetails`）。戻り値は AI-CUE の `ExternalBillingRedirect` を維持。**aigenba の 30+ メソッド単一 interface へは寄せず、AI-CUE の狭い gateway + チケット系 Gateway 分割の境界と Fake の規約を維持**（AI-CUE の Gateway 規約） | `/tmp/aigenba/app/Services/Billing/Contracts/StripeGatewayInterface.php`（命名のみ） |
| `app/Services/Billing/CashierStripeGateway.php`（`CashierSubscriptionCheckoutGateway.php` を rename） | 実装本体は現行のまま（`newSubscription('default',…)->checkout()` / `billingPortalUrl(…, PortalConfigurationSpec::sessionOptions(config('cashier.portal_configuration_id')))`）。`portalRedirect` → `createPortalSession` へ改名、`syncCustomerDetails()`（`$org->syncStripeCustomerDetails()`）を追加 | `/tmp/aigenba/app/Services/Billing/CashierStripeGateway.php` |
| `app/Services/Billing/Fakes/FakeStripeGateway.php`（`Fakes/FakeSubscriptionCheckoutGateway.php` を rename） | interface 変更へ追随。`FakeExternalUrl::neutralReturn` の中立帰還 URL 契約は不変。`syncCustomerDetails()` は **no-op**（fake 環境が実 Stripe を叩かない規約の維持） | `/tmp/aigenba/app/Services/Billing/Testing/StripeGatewayDuskFake.php:204,211` |
| `app/Services/Billing/BillingCustomerSynchronizer.php`（新規） | **verbatim**（`stripe_id === null` は no-op / `SyncBillingCustomerDetails::dispatch($org)->afterCommit()` / 「必ず `DB::transaction` の内側から呼ぶ」契約 docblock 込み） | `/tmp/aigenba/app/Services/Billing/BillingCustomerSynchronizer.php` |
| `app/Jobs/Billing/SyncBillingCustomerDetails.php`（新規） | `handle(StripeGatewayInterface $gateway)` → `$gateway->syncCustomerDetails($org)`。Cashier 標準 job を使わない理由（billable を trait 型で受けるため PHPStan level 10 で不一致）を移植元コメントごと持ち込む | `/tmp/aigenba/app/Jobs/Billing/SyncBillingCustomerDetails.php` |
| `app/Actions/Organizations/RenameOrganizationAction.php`（新規）+ `app/Http/Controllers/Organizations/OrganizationController.php:98-108`（改修） | Controller の update 内部を Action に抽出し、`DB::transaction` 内で `isDirty('name')` のときだけ `BillingCustomerSynchronizer::dispatchFor()`。**配線は rename 経路のみ**（aigenba の `UpdateBillingContactAction` は請求先列・更新 UI が AI-CUE に無い = P9 / laratrust team rename 経路も無い） | `/tmp/aigenba/app/Actions/Organizations/RenameOrganizationAction.php` |
| `app/Services/Billing/BillingPermissionService.php`（新規） | `grant` / `revoke` / `hasDirectPermission` / `getDirectManageBillingMap` + `ensureTeamId`（`Assert::integer($org->laratrust_team_id)`）/ `ensureMembership`（`DomainException`）を移植。permission 名は AI-CUE 規約（kebab）で `public const PERMISSION_MANAGE_BILLING = 'manage-billing'`（AI-CUE に `App\Enums\BillingPermission` は無く、同型先例 `app/Services/ApiKey/ApiKeyPermissionService.php:29` が const 方式）。**`canEdit` / `canEditWithKnownRoles` は移植しない**（`App\Enums\OrganizationRole` に `level()` が無く、階層マトリクスは付与 UI 専用。**本フェーズは service + Policy の OR 参照のみ**） | `/tmp/aigenba/app/Services/Billing/BillingPermissionService.php` |
| `database/seeders/PermissionSeeder.php`（改修） | `permissions()` に `['name' => BillingPermissionService::PERMISSION_MANAGE_BILLING, 'display_name' => '請求・プラン管理']` を追加（`ApiKeyPermissionService::PERMISSION_MANAGE_API_KEYS` の隣。L43。flat 付与モデルのため `RolePermissionSeeder` には登録しない） | — |
| `app/Policies/OrganizationPolicy.php:37 manageBilling`（改修） | `manageApiKeys`（同ファイル L48-60）と同型に: role null → false / `canManage()` → true / それ以外は `BillingPermissionService::hasDirectPermission()` を **OR 参照**。付与 route / UI は P2 に含めない = 直接付与行 0 件 = 認可の結論は現行と同一 | `/tmp/aigenba/app/Services/Billing/BillingPermissionService.php` の Policy 参照形 |
| `app/Http/Controllers/Billing/BillingController.php`（改修） | Gateway 直注入をやめ `SubscriptionService` へ委譲（`checkout` → `startCheckout()` / `portal` → `createPortalSession()`）。**`index` の props は一切変えない**（`currentPlanCode` を維持 = `getStatus()`/`BillingStatusDto` は P8b）。`startCheckout()` が投げる `StripePriceNotSyncedException` を catch し **現行と同一文言**の `back()->with('error', '選択したプランは現在お申し込みいただけません。')` を返す | `/tmp/aigenba/app/Http/Controllers/Billing/BillingController.php`（Service 委譲の層構成） |
| `app/Exceptions/Billing/StripePriceNotSyncedException.php`（新規） | **verbatim**（`userMessage()`）。Controller が flash に使う（500 にしない） | `/tmp/aigenba/app/Exceptions/Billing/StripePriceNotSyncedException.php` |
| `app/Services/Billing/StripeWebhookProcessor.php`（改修。L176-329） | `syncPlanCode` / `clearPlanCode` / `syncSubscriptionPeriod` の**書込ロジックを `SubscriptionService::applySubscriptionSnapshot()` へ移設**。Processor の責務は payload → `SubscriptionSnapshot` の写像 + 組織解決 + `subscriptionHasPaymentMethod($object)`（`default_payment_method` / `default_source` の有無。`StripeWebhookController.php:336-340` verbatim）→ `recordPaymentMethodSnapshot()` 呼び出しに縮む。**終了契機は現行どおり `customer.subscription.deleted` のみ**（`$terminated=true`）。**行の作成は Cashier `WebhookController` の責務のまま**（上記「行の materialize 順序」）。反映条件（active/trialing のみ plan_code 同期・未知 Price は受理のみ・invoice / ticket 系分岐）は不変。冪等マシン（`stripe_webhook_events` + `claim()`）は無改変（不変条件 #7） | `/tmp/aigenba/app/Services/Billing/SubscriptionService.php:204-357`, `/tmp/aigenba/app/Http/Controllers/Billing/StripeWebhookController.php:240-340` |
| `app/Providers/AppServiceProvider.php:22-26,110` / `app/Providers/FakeExternalsServiceProvider.php:10-13,80`（改修） | bind を `Contracts\StripeGatewayInterface → CashierStripeGateway` / fake は `FakeStripeGateway` へ更新 | `/tmp/aigenba/app/Providers/AppServiceProvider.php:103` |

**`applySubscriptionSnapshot()` の adaptation（列の所在差の吸収。意味論は現行同値）**: aigenba は `subscriptions.plan_code` に書くが AI-CUE の権威は `organizations.plan_code`。単一 transaction 内で (a) `resolvePlanCodeFromPriceId($snap->basePriceId)` が解決でき **かつ** `status ∈ {active,trialing}` のときのみ `organizations.plan_code` を同期（未知 Price は受理のみ = 現行 `syncPlanCode` と同値）、(b) `subscriptions` 行が存在すれば `lockForUpdate()` の上で `stripe_status` / `stripe_price` / `quantity` / `trial_ends_at` / `ends_at` / `current_period_end` を更新（行不在なら period 更新のみ skip = 現行同値）、(c) `$terminated === true` のとき `organizations.plan_code = null`（現行 `clearPlanCode` と同値）+ `stripe_schedule_id = null` / `schedule_setup_status = ScheduleSetupStatus::None`（aigenba の終了時 schedule クリアのうち AI-CUE に実在する 2 列のみ）。seat drift / schedule out-of-band drift / period 巻き戻し guard は対象列（`additional_seats` / `pending_plan_code` / `current_period_start`）が無いため移植しない。

#### 波及変更

- **TypeScript 型定義**: **なし**。`resources/js/types/billing.ts` / `resources/js/types/dashboard.ts`（`BillingSummary.has_billing_access`）とも形状不変。`OnboardingBillingState` は Service / middleware 内部の判定にのみ使い props に載せない（aigenba と同じ）。
- **DTO / JsonResource**: 新規 = `SubscriptionEntitlementDto`（`@phpstan-type EntitlementShape`）/ `SubscriptionSnapshot`（値オブジェクト）。既存 `ExternalBillingRedirect` は Gateway 戻り値契約として据置。`BillingSummaryData` / `PurchaseTicketsPageDto`（`ticketAttemptToken` を含むチケット決済の冪等性契約）は**一切触らない**。JsonResource の新設なし。
- **Inertia props**: **なし**（`Billing/Index` の `currentPlanCode` / `Dashboard` とも不変）。
- **Factory / テストヘルパ**: `database/factories/Billing/BillingCheckoutSessionFactory.php`（新規）。`database/factories/OrganizationFactory.php` に `activatedPersonal(User $declarer)` / `grandfatheredFree()`（declarer-less）state を追加（P1 で未追加なら P2 で追加）。`tests/Pest.php:167 createFakeSubscription()` に `bool $hasPaymentMethod = true` / `?CarbonImmutable $trialEndsAt = null` 引数を追加（既存呼び出しは既定値で cohort A / B に落ち、結論不変）+ docblock L163-166 を新判定へ更新。
- **テストファイル（更新。削除しない）**: `tests/Feature/Billing/RequireActiveSubscriptionMiddlewareTest.php`（cohort C / D）/ `tests/Feature/DashboardTest.php:423-437`（cohort D の `has_billing_access`）/ `tests/Feature/Billing/BillingPageTest.php`・`tests/Feature/Providers/FakeExternalsServiceProviderTest.php`（型名 rename）/ `tests/Feature/Billing/WebhookEventSubscriptionInvariantTest.php`・`tests/Feature/Billing/WebhookIdempotencyTest.php`・`tests/Feature/Billing/SeededFreePlanBillingAccessTest.php`・`tests/Feature/Billing/SendBillingRemindersTest.php`・`tests/Feature/Database/BughuntBillingSeederTest.php`（**無改変で green**）/ `tests/Feature/Billing/PortalConfigurationTest.php`（期待不変 + 1 ケース追加）/ `tests/Architecture/MassAssignmentSafetyTest.php`（新モデル `BillingCheckoutSession` を検査対象へ追加）。

#### 主要な契約

```php
// App\Enums\Billing
enum OnboardingBillingState: string {          // verbatim
    case NoSubscription = 'no_subscription';
    case PendingCheckout = 'pending_checkout';
    case ExpiredCheckout = 'expired_checkout';
    case Subscribed = 'subscribed';
    case ActiveFreePlan = 'active_free_plan';
    public function grantsAccess(): bool { return $this === self::Subscribed || $this === self::ActiveFreePlan; }
}
enum SubscriptionState: string {               // ScheduledForUpgrade は入力列不在のため非移植
    case Active = 'active'; case UpgradeRecovery = 'upgrade_recovery';
    case PastDue = 'past_due'; case Paused = 'paused'; case Inactive = 'inactive';
    public static function fromSubscription(Subscription $sub): self;
    public function grantsAccess(): bool;      // Active|UpgradeRecovery|PastDue => true
}
enum EntitlementDeniedReason: string {         // verbatim
    case NoActiveSubscription = 'no_active_subscription';
    case TrialEndedWithoutPaymentMethod = 'trial_ended_without_payment_method';
    case Paused = 'paused';
}

final class BillingAccess {
    public function __construct(private readonly SubscriptionService $subscriptions) {}
    public function hasActiveAccess(Organization $org): bool
    {
        if ($this->state($org)->grantsAccess()) {
            return true;
        }
        // 移行 OR（P4 で削除する 1 行）: 現行の意図的な free 許可 = plan_code null を通す。
        // P4 の grandfathering backfill（free_plan_code='personal'）が ActiveFreePlan を
        // 成立させ、本行を消すことがゲート反転そのものになる。
        return $org->plan_code === null;
    }
    public function state(Organization $org): OnboardingBillingState;   // verbatim。plan_code を見ない
    public static function staleThresholdAt(CarbonImmutable $now): CarbonImmutable; // = $now->subDay()
}

final class SubscriptionService {
    public function __construct(private readonly StripeGatewayInterface $gateway) {}
    public function deriveEntitlement(Subscription $sub): SubscriptionEntitlementDto;   // verbatim（唯一の判定経路）
    public function applySubscriptionSnapshot(Organization $org, SubscriptionSnapshot $snap, bool $terminated = false): void;
    public function recordPaymentMethodSnapshot(Subscription $sub, bool $hasPaymentMethod): void; // monotonic・行不在は no-op
    public function startCheckout(Organization $org, Plan $plan, string $successUrl, string $cancelUrl): ExternalBillingRedirect;
    public function createPortalSession(Organization $org, string $returnUrl): ExternalBillingRedirect;
}

// App\Services\Billing\Contracts
interface StripeGatewayInterface {
    public function createSubscriptionCheckout(Organization $org, string $stripePriceId, string $successUrl, string $cancelUrl): ExternalBillingRedirect;
    public function createPortalSession(Organization $org, string $returnUrl): ExternalBillingRedirect;
    public function syncCustomerDetails(Organization $org): void;
}

final class BillingPermissionService {
    public const PERMISSION_MANAGE_BILLING = 'manage-billing';
    public function grant(User $target, Organization $org): void;      // 非メンバーは DomainException
    public function revoke(User $target, Organization $org): void;
    public function hasDirectPermission(User $user, Organization $org): bool;   // 非メンバーは false
    /** @param list<int> $userIds @return array<int, bool> */
    public function getDirectManageBillingMap(Organization $org, array $userIds): array;
}
```

**`state()` の分岐順（verbatim。上から最初に一致したものを返す）**

| # | 条件 | 戻り | P2 実効 |
|---|---|---|---|
| 1 | `$sub instanceof Subscription && deriveEntitlement($sub)->entitled` | `Subscribed` | 到達（cohort A / B / **D**） |
| 2 | `$org->free_plan_code === PersonalPlanService::FREE_PLAN_CODE` | `ActiveFreePlan` | **不到達**（writer は P3/P4） |
| 3 | `$sub instanceof Subscription` | `ExpiredCheckout` | 到達（cohort **C** / E / F / G） |
| 4 | live pending な `BillingCheckoutSession`（`created_at >= BillingAccess::staleThresholdAt(now())`） | `PendingCheckout` | **不到達**（writer は P9。行 0 件） |
| 5 | stale pending（`created_at < staleThresholdAt(now())`。in-memory 判定・DB は書かない）または expired / failed 行あり | `ExpiredCheckout` | **不到達**（同上） |
| 6 | それ以外 | `NoSubscription` | 到達（cohort H） |

**DB 列 / index**: `billing_checkout_sessions`（上記 create）/ `subscriptions.has_payment_method`(bool NOT NULL default false) + 既存行 true の backfill。`permissions` に `manage-billing` 行を seed。**ルート変更なし**（`/billing`・`/billing/checkout`・`/billing/portal`）。

#### PHPStan 適合チェック

- `Organization::subscription('default')` は Cashier 由来で `Subscription|null`（`AppServiceProvider.php:185` の `Cashier::useSubscriptionModel(App\Models\Billing\Subscription::class)` で差替済）。`state()` / webhook 経路とも **`$sub instanceof Subscription` で narrow** してから `deriveEntitlement()` に渡す（aigenba `BillingAccess.php:34` と同型）。`?->` で握り潰さない。
- `SubscriptionState::fromSubscription()` の `$sub->schedule_setup_status` は `ScheduleSetupStatus` へ enum cast 済み（`Subscription::casts()`）のため **instance 比較**（`=== ScheduleSetupStatus::Created`）。文字列比較にすると `alwaysFalse` になる。
- `has_payment_method` は `casts()` の `'boolean'` + `@property bool $has_payment_method` で `bool` を保証し、`! $sub->has_payment_method` が `mixed` にならないようにする（型 widen での回避・baseline 化はしない = 禁止事項 2）。
- `$sub->trial_ends_at` は Cashier 側 cast で `Carbon|null`。`deriveEntitlement` は verbatim どおり `!== null` で narrow → `CarbonImmutable::instance($sub->trial_ends_at)` に渡す。
- `BillingCheckoutSession::$created_at` は `Carbon|null`。stale 判定は `$row->created_at !== null && $row->created_at->lessThan($threshold)` で null を明示分岐（verbatim）。`get(['id','created_at'])` の戻りは `@var Collection<int, BillingCheckoutSession>` を docblock で明示。
- `SubscriptionEntitlementDto::toArray()` は `@phpstan-type EntitlementShape` + `@return EntitlementShape` で固定。`SubscriptionSnapshot` の日時は webhook payload の `data_get`（`mixed`）を既存 `stringAt()` + 新設 `epochAt(): ?CarbonImmutable` helper で `?CarbonImmutable` へ narrow してから ctor に渡す。
- `getDirectManageBillingMap()` は `@param list<int>` / `@return array<int, bool>`。`DB::table('permission_user')->pluck('user_id')` の `mixed` は `Assert::integerish()` 後に cast（`ApiKeyPermissionService::getDirectMap` と同一実装）。`ensureTeamId()` は `Assert::integer($org->laratrust_team_id)`（不変条件 #5: `laratrust_team_id` を常に明示）。
- config 読みは `config('cashier.portal_configuration_id')` / `config()->string('quota.fallback_plan')` の既存 typed accessor 経由を維持。`assertPriceSynced()` の `app()->environment('production')` 分岐も verbatim。

#### テスト計画

**先に red を作るテスト**

1. `tests/Unit/Billing/OnboardingBillingStateTest.php` — 5 case の `value` と `grantsAccess()` マトリクス（`Subscribed` / `ActiveFreePlan` のみ true）。enum 不在で red。
2. `tests/Feature/Billing/BillingAccessStateTest.php` — **分岐順 6 段を Factory から固定**:
   - cohort A（active / trialing・trial null）→ `Subscribed` + `hasActiveAccess()=true`
   - cohort B（active・`trial_ends_at` 過去・PM 有）→ `Subscribed` + true
   - **cohort C（active / trialing・`trial_ends_at` 過去・PM 無）→ `ExpiredCheckout` + false**（reason `TrialEndedWithoutPaymentMethod`。**P2 で結論が反転する側の固定**）
   - **cohort D（past_due・PM 有）→ `Subscribed` + true**
   - cohort E（past_due・trial 過去・PM 無）→ `ExpiredCheckout` + false
   - cohort F / G（paused / canceled / unpaid / incomplete / incomplete_expired）→ `ExpiredCheckout` + false（**`plan_code` 非 null / null の両方で同じ `state()`** = state が plan_code を見ないことの証明。`plan_code=null` は移行 OR で `hasActiveAccess()=true`）
   - cohort H（sub 行なし・checkout session なし）→ `NoSubscription`
   - `free_plan_code='personal'`（declarer 有無の両方）→ `ActiveFreePlan` + true
   - `BillingCheckoutSession`: `created_at = staleThresholdAt(now())` **ちょうど → live = `PendingCheckout`**（排他境界）/ `staleThresholdAt(now())->subSecond()` → `ExpiredCheckout` / expired・failed → `ExpiredCheckout`、かつ **`state()` 実行で DB 行が書き換わらない**（`updated_at` / `status` 不変 = read 経路 no-write 契約）
3. `tests/Feature/Billing/SubscriptionEntitlementTest.php` — `deriveEntitlement()` の `entitled` / `state` / `reason` マトリクス（status × `has_payment_method` × `trial_ends_at`）。`UpgradeRecovery`（`stripe_schedule_id` + `schedule_setup_status=Created`）が `entitled=true` = cohort A 同値であること。
4. `tests/Feature/Billing/SubscriptionSnapshotSyncTest.php` — webhook payload → `SubscriptionSnapshot` → `applySubscriptionSnapshot()` で `organizations.plan_code` / `subscriptions.current_period_end` が現行と同一に落ちる。`deleted`（`terminated=true`）で `plan_code=null` + schedule 2 列クリア。未知 Price は無変更。非 active/trialing status は plan_code 無変更。**`customer.subscription.created` では行が未作成のため `recordPaymentMethodSnapshot()` が no-op になり、直後の Cashier ハンドラが `subscriptions` + `subscription_items` を作る**こと（listener が行を先取りしない = items が生成される回帰防止）。**最初の `customer.subscription.updated` で `has_payment_method=true` が確定**すること。monotonic（true → false に戻らない）。
5. `tests/Feature/Billing/HasPaymentMethodBackfillMigrationTest.php` — **cohort C の移行安全性**: 列追加前に作った subscription 行（`trial_ends_at` 過去を含む）が backfill 後に `has_payment_method=true` になり、`hasActiveAccess()` が **true のまま**であること。backfill の冪等（2 回流して差分なし）。
6. `tests/Architecture/BillingEntitlementSingleSourceTest.php` — (a) `app/` 配下で `SubscriptionState::grantsAccess()` を直接参照するのは `SubscriptionService::deriveEntitlement()` のみ、(b) `subscription('default')` の直参照は `BillingAccess` / `SubscriptionService` / `StripeWebhookProcessor` のみ、(c) `organizations.plan_code` / `free_plan_code` を読むのは allowlist（`BillingAccess` の移行 OR / `StripeWebhookProcessor` / `QuotaService` / `Organization` model / `PersonalPlanService` / Filament 表示）のみ。
7. `tests/Architecture/BillingSyncDispatchInvariantTest.php` — `SyncBillingCustomerDetails::dispatch` の呼び出し元は `BillingCustomerSynchronizer` のみ（aigenba IV-2）。

**既存テストの更新（削除しない）**

- `tests/Feature/Billing/RequireActiveSubscriptionMiddlewareTest.php`:
  - 「有償契約 + 支払い不健全は billing へ redirect + 理由 flash」の dataset から `past_due` を外し `['canceled','incomplete','unpaid','paused']` へ（cohort D）。
  - 「有償契約 + 支払い不健全の JSON は 402」/「billing ページは遮断対象の組織でも到達できる」の status を `past_due` → `canceled` へ（402 文言の固定は不変）。
  - 「BillingAccess: plan_code null は常に許可、非 null は active/trialing のみ許可」を **cohort 表 A–I の dataset へ置換**し、テスト名を「plan_code null は移行 OR で許可（P4 で削除）/ 非 null は `deriveEntitlement` 判定」へ。`'past_due' => true`（cohort D）。
  - **追加ケース**: cohort C（`active` + `trial_ends_at` 過去 + PM 無）→ 遮断 / cohort E（`past_due` + 同条件）→ 遮断。
- `tests/Feature/DashboardTest.php:423`: cohort D の `has_billing_access` 期待を **false → true** に更新し、CTA 遷移先 200（redirect loop なし）の不変条件は `canceled` シナリオを追加して保持。
- `tests/Feature/Billing/BillingPageTest.php` / `tests/Feature/Providers/FakeExternalsServiceProviderTest.php:35`: `SubscriptionCheckoutGateway` / `FakeSubscriptionCheckoutGateway` の参照を `Contracts\StripeGatewayInterface` / `FakeStripeGateway` へ。**props の期待（`currentPlanCode`）と中立帰還 URL の期待は不変**。
- `tests/Feature/Billing/SeededFreePlanBillingAccessTest.php` / `WebhookIdempotencyTest.php` / `WebhookEventSubscriptionInvariantTest.php`: **無改変で green**（cohort I と冪等マシンが無変更であることの証明）。
- `tests/Feature/Billing/PortalConfigurationTest.php`: 期待不変 + 「Service 委譲後も `PortalConfigurationSpec::sessionOptions(config('cashier.portal_configuration_id'))` が Gateway に渡る」を 1 ケース追加。

**新規（機能追加分）**

- `tests/Feature/Billing/BillingPermissionServiceTest.php`: grant/revoke → `hasDirectPermission` の反映 / 非メンバーは `DomainException`（grant）・false（has）/ `getDirectManageBillingMap` が 1 クエリ（N+1 なし）。**Policy 回帰**: 直接付与ゼロなら `manageBilling` の結論は現行（owner/admin のみ）と同一 / 直接付与された member は `/billing/checkout` が 403 にならない / 非メンバーは付与行が残存しても false。
- `tests/Feature/Organizations/OrganizationRenameStripeSyncTest.php`: `Queue::fake()` で (a) name 変更時のみ `SyncBillingCustomerDetails` が dispatch、(b) 同名 save では dispatch なし、(c) `stripe_id === null` は no-op、(d) transaction rollback 時に発火しない（`afterCommit`）。
- `tests/Unit/Billing/FakeStripeGatewayTest.php`: `syncCustomerDetails()` が no-op（実 Stripe を叩かない）+ checkout / portal の中立帰還 URL 契約（既存 `FakeTicketCheckoutGatewayTest` と同型）。
- `tests/Feature/Billing/BillingCheckoutSessionModelTest.php`: `statusEnum()` / `intentEnum()` / `isReplayablePending()` と unique 制約（`stripe_session_id` / `idempotency_key` / `(organization_id,intent,attempt_token)`。NULL token は重複許容）。

#### リスク

| リスク | 緩和 |
|---|---|
| **cohort C（trial 終了 + PM 無し）が P2 で遮断へ反転する**（Round 13 Critical） | DoD から「挙動不変」を撤回し、cohort 表・テスト（`BillingAccessStateTest` / `RequireActiveSubscriptionMiddlewareTest` / `SubscriptionEntitlementTest`）に明示固定。**既存行は backfill で `has_payment_method=true` = デプロイ時点の該当 org は 0 件**（`HasPaymentMethodBackfillMigrationTest` が固定）。P2 以降の新規行が該当し得るのは Stripe 側 trial 設定時のみ（AI-CUE に trial 発行コードなし）で、その遮断は aigenba の「webhook の paused 化前でも先回り遮断」（`SubscriptionService.php:138`）そのもの = 原則 1・5 により先回り修正しない |
| **cohort D（past_due 許可）で未収金 org が利用継続する** | 原則 1・3 による意図的 parity（aigenba の dunning 継続方針）。**PM 無し past_due は cohort E として遮断**され、`invoice.payment_failed` 通知（既存 `BillingNotificationDispatcher`）は不変。aigenba 側で方針が変われば取り込む |
| **`has_payment_method` の初回書込が `created` イベントに載らない**（Cashier が `WebhookReceived` を行作成前に発火） | 契約として明文化 + `SubscriptionSnapshotSyncTest` で「created は no-op / 最初の updated で true 確定 / `subscription_items` が生成される」を固定。**aigenba の `Subscription::create($attrs)` を移植すると Cashier の `contains` ガードで items 生成が skip される**ため移植しない（原則 4） |
| **移行 OR（`plan_code === null`）の消し忘れで P4 のゲート反転が効かない** | `hasActiveAccess()` の docblock に「P4 で削除」と削除条件（grandfathering backfill 完了）を明記し、`BillingAccessStateTest` に cohort I（`NoSubscription` + `plan_code=null` → **P2 は true**）を明示ケースとして置く。P4 はこの 1 行削除 + 期待反転の diff だけで済むことをテスト差分で確認する |
| **`state()` が `plan_code` を見ないことの回帰**（将来の再発明） | `BillingEntitlementSingleSourceTest` で `plan_code` 読み出し allowlist を構造的に固定（`BillingAccess` は移行 OR の 1 箇所のみ許可し、P4 で allowlist からも外す） |
| **stale 境界の重複で live 行が expire される**（Round 13 Critical 2） | 閾値を `BillingAccess::staleThresholdAt()` に単一出典化し、**live = `>=` / stale = `<`** の排他で統一。境界ちょうど（`created_at == staleThresholdAt(now())`）が `PendingCheckout` になることを `BillingAccessStateTest` で固定。P9 の sweeper は同 helper を `<` で読む |
| **`state()` の checkout session クエリが gate 経路（多数の GET）で毎回走る** | verbatim どおり sub / free_plan_code を持つ org は分岐 1・2 で早期 return し、クエリ到達は sub 行なしの org のみ。**P2 時点では `billing_checkout_sessions` は writer 不在で 0 件**（**最初の writer は P8a**（`intent=setup_payment_method`）、**`subscription_start` 行の writer は P9**）。read 経路で **DB 書込をしない**契約もテストで固定（stale expire は sweeper の責務 = **P9 所管の `expireStaleCheckouts()`**） |
| `StripeWebhookProcessor` からの書込移設で webhook の順序逆転耐性・冪等が退行 | 既存 `WebhookEventSubscriptionInvariantTest` / `WebhookIdempotencyTest` を**無改変で維持**（不変条件 #7）。反映条件（active/trialing のみ・未知 Price は受理のみ・行不在時は period 更新 skip・終了契機は deleted のみ）をそのまま持ち込み、`SubscriptionSnapshotSyncTest` で列単位に固定 |
| **rename 時に Stripe API 呼び出しが増える**（現行は customer 同期なし）= 外部副作用の新規発生 | job 化 + `stripe_id === null` no-op + `isDirty('name')` 限定 + fake 環境は `FakeStripeGateway::syncCustomerDetails()` で no-op。`OrganizationRenameStripeSyncTest` + `BillingSyncDispatchInvariantTest` で固定 |
| `manageBilling` への直接付与 OR 追加で認可が緩む | 付与経路（route / UI / Action）を P2 に含めない = 直接付与行は生成されない。Policy 回帰テストで「付与ゼロなら結論は現行と同一」を固定。非メンバーは role null で早期 false（`manageApiKeys` と同型） |
| Gateway rename で fake bind 漏れ（bughunt 環境が実 Stripe を叩く） | `AppServiceProvider` / `FakeExternalsServiceProvider` の bind を同一 PR で更新し、`FakeExternalsServiceProviderTest` + `BillingPageTest` の fake 経由 happy path 2 本（checkout / portal）が中立帰還 URL を返すことで検出 |
| `billing_checkout_sessions` が writer なしで先行導入される（dead table） | `state()` が読む = 状態モデルの一部（D25 の v2 変更）。model / factory / 制約テストを同時に入れ、P9 の writer 追加時に migration を触らずに済ませる。列は AI-CUE に対象がある分だけに絞り、不要列の負債化を防ぐ |

---

### P3 Onboarding 最小導線（ゲート反転より前に導線を実在させる = F-07 再発防止の条件 A）


## 実装差分（git diff）

```diff
diff --git a/app/Actions/Organizations/RenameOrganizationAction.php b/app/Actions/Organizations/RenameOrganizationAction.php
new file mode 100644
index 0000000..c20b497
--- /dev/null
+++ b/app/Actions/Organizations/RenameOrganizationAction.php
@@ -0,0 +1,36 @@
+<?php
+
+declare(strict_types=1);
+
+namespace App\Actions\Organizations;
+
+use App\Models\Organization;
+use App\Services\Billing\BillingCustomerSynchronizer;
+use Illuminate\Support\Facades\DB;
+
+/**
+ * 組織名のリネームを単一 transaction で行い、name 変更時に Stripe customer 同期を発火する Action。
+ *
+ * 旧 `OrganizationController::update` の内部処理を抽出したもの。外部挙動 (route / redirect /
+ * DB 結果) は不変で、controller 側は transaction を張らず本 Action に委譲する (二重 transaction 回避、IV-3)。
+ */
+final class RenameOrganizationAction
+{
+    public function __construct(
+        private readonly BillingCustomerSynchronizer $synchronizer,
+    ) {}
+
+    public function execute(Organization $organization, string $name): void
+    {
+        DB::transaction(function () use ($organization, $name): void {
+            $organization->fill(['name' => $name]);
+            // IV-5: name が実際に変化したときのみ同期 (stripeName は org name を返すため)。
+            $nameChanged = $organization->isDirty('name');
+            $organization->save();
+
+            if ($nameChanged) {
+                $this->synchronizer->dispatchFor($organization);
+            }
+        });
+    }
+}
diff --git a/app/DataTransferObjects/Billing/ExternalBillingRedirect.php b/app/DataTransferObjects/Billing/ExternalBillingRedirect.php
index bfc9675..8e93f6c 100644
--- a/app/DataTransferObjects/Billing/ExternalBillingRedirect.php
+++ b/app/DataTransferObjects/Billing/ExternalBillingRedirect.php
@@ -9,7 +9,7 @@
 /**
  * 課金系外部ページ (Stripe Checkout / Customer Portal) への遷移先。
  *
- * gateway (SubscriptionCheckoutGateway) の戻り値契約。Response 化
+ * gateway (Contracts\StripeGatewayInterface) の戻り値契約。Response 化
  * (Inertia::location) は Controller の責務で、gateway は URL のみ返す。
  */
 final readonly class ExternalBillingRedirect
diff --git a/app/DataTransferObjects/Billing/SubscriptionEntitlementDto.php b/app/DataTransferObjects/Billing/SubscriptionEntitlementDto.php
new file mode 100644
index 0000000..550e11a
--- /dev/null
+++ b/app/DataTransferObjects/Billing/SubscriptionEntitlementDto.php
@@ -0,0 +1,52 @@
+<?php
+
+declare(strict_types=1);
+
+namespace App\DataTransferObjects\Billing;
+
+use App\Enums\Billing\EntitlementDeniedReason;
+use App\Enums\Billing\SubscriptionState;
+
+/**
+ * subscription の利用可否 (entitlement) を導出した結果。
+ *
+ * **state 単体では判定しない**。`SubscriptionService::deriveEntitlement` が唯一の生成経路で、
+ * state + PM 有無 + trial_ends_at + Stripe status snapshot を合成して `entitled` を確定する。
+ * 否定時は `reason` を必ず付ける (フロントの状態説明出し分け用)。
+ *
+ * @phpstan-type EntitlementShape array{
+ *   entitled: bool,
+ *   state: string,
+ *   reason: string|null
+ * }
+ */
+final readonly class SubscriptionEntitlementDto
+{
+    public function __construct(
+        public bool $entitled,
+        public SubscriptionState $state,
+        public ?EntitlementDeniedReason $reason,
+    ) {}
+
+    public static function granted(SubscriptionState $state): self
+    {
+        return new self(true, $state, null);
+    }
+
+    public static function denied(SubscriptionState $state, EntitlementDeniedReason $reason): self
+    {
+        return new self(false, $state, $reason);
+    }
+
+    /**
+     * @return EntitlementShape
+     */
+    public function toArray(): array
+    {
+        return [
+            'entitled' => $this->entitled,
+            'state' => $this->state->value,
+            'reason' => $this->reason?->value,
+        ];
+    }
+}
diff --git a/app/Enums/Billing/EntitlementDeniedReason.php b/app/Enums/Billing/EntitlementDeniedReason.php
new file mode 100644
index 0000000..f89076c
--- /dev/null
+++ b/app/Enums/Billing/EntitlementDeniedReason.php
@@ -0,0 +1,28 @@
+<?php
+
+declare(strict_types=1);
+
+namespace App\Enums\Billing;
+
+/**
+ * entitlement (利用可否) を否定する理由。
+ *
+ * `SubscriptionService::deriveEntitlement` が `entitled=false` のとき必ず付随させる。
+ * フロントは reason 別に状態説明 (paused / trial 終了 & PM 無 / 請求失敗) を出し分ける。
+ *
+ * 注意: `PastDue` (state=PastDue) かつ PM 有りは entitled=true (請求失敗中も利用継続) のため、
+ * ここに PastDue を「利用継続中」の理由としては置かない。past_due で entitled=false になる
+ * のは PM 無し past_due のみで、それは trial 終了 & カード無しとして
+ * `TrialEndedWithoutPaymentMethod` で表現する (trial 終了後の paused と区別)。
+ */
+enum EntitlementDeniedReason: string
+{
+    /** subscription が無い / Inactive (canceled・unpaid・incomplete 等)。 */
+    case NoActiveSubscription = 'no_active_subscription';
+
+    /** trial 終了後カード未登録で Stripe が paused にした (read-only)。 */
+    case TrialEndedWithoutPaymentMethod = 'trial_ended_without_payment_method';
+
+    /** Stripe status=paused (= 上記の確定状態)。 */
+    case Paused = 'paused';
+}
diff --git a/app/Enums/Billing/OnboardingBillingState.php b/app/Enums/Billing/OnboardingBillingState.php
new file mode 100644
index 0000000..886dd70
--- /dev/null
+++ b/app/Enums/Billing/OnboardingBillingState.php
@@ -0,0 +1,29 @@
+<?php
+
+declare(strict_types=1);
+
+namespace App\Enums\Billing;
+
+/**
+ * Organization の課金状態 (流入制御目線)。
+ *
+ * SubscriptionState (= subscription model 派生) とは別レイヤー。
+ * middleware は本 enum で gate 判定する。
+ *
+ * ActiveFreePlan = Stripe サブスクなしの free entitlement (Personal free)。
+ * Subscribed は「Stripe subscription が entitled」の意味を維持する
+ * (= 「Subscribed ⇒ サブスク行が存在する」という既存コードの仮定を壊さない)。
+ */
+enum OnboardingBillingState: string
+{
+    case NoSubscription = 'no_subscription';
+    case PendingCheckout = 'pending_checkout';
+    case ExpiredCheckout = 'expired_checkout';
+    case Subscribed = 'subscribed';
+    case ActiveFreePlan = 'active_free_plan';
+
+    public function grantsAccess(): bool
+    {
+        return $this === self::Subscribed || $this === self::ActiveFreePlan;
+    }
+}
diff --git a/app/Enums/Billing/SubscriptionState.php b/app/Enums/Billing/SubscriptionState.php
new file mode 100644
index 0000000..b118db9
--- /dev/null
+++ b/app/Enums/Billing/SubscriptionState.php
@@ -0,0 +1,86 @@
+<?php
+
+declare(strict_types=1);
+
+namespace App\Enums\Billing;
+
+use App\Models\Billing\Subscription;
+
+/**
+ * Subscription の派生状態。
+ *
+ * `Active` / `UpgradeRecovery` は流入制御を通過させる。
+ * `Inactive` は `canceled` / `unpaid` / `incomplete` / `incomplete_expired` を統合した拒否状態。
+ * `incomplete` / `unpaid` を `Active` に含めない理由: いずれも支払いが完了していない
+ * (= 顧客カードが未承認 or 失敗) 状態のため、流入制御の目的 (= LLM コスト負担確認) に反する。
+ *
+ *  - `PastDue` = 有料化後 (PM 登録済) の請求失敗・dunning 中。**回復余地あり**で利用は継続させる
+ *    (grantsAccess=true)。PM **無し** past_due (= trial 後カード無し dunning) は entitlement gate
+ *    (`SubscriptionService::deriveEntitlement`) で別途遮断する。
+ *  - `Paused` = trial 終了後カード未登録で Stripe が paused にした read-only 状態 (grantsAccess=false)。
+ *
+ * **重要**: 利用可否の最終判定を state 単体で行ってはならない。`grantsAccess` は state のみの粗い
+ * 判定であり、PM 有無 / trial_ends_at / Stripe status snapshot を加味した最終判定は
+ * `SubscriptionService::deriveEntitlement` が唯一の経路。
+ *
+ * 移植元の `ScheduledForUpgrade` は入力列 (`subscriptions.pending_plan_code`) が AI-CUE に無いため
+ * 非移植。`upgrade_recovery_required` 列も無いため、`UpgradeRecovery` は schedule 部分完了
+ * (`stripe_schedule_id` + `schedule_setup_status=Created`) の分岐のみを持つ。
+ */
+enum SubscriptionState: string
+{
+    case Active = 'active';
+    case UpgradeRecovery = 'upgrade_recovery';
+    case PastDue = 'past_due';
+    case Paused = 'paused';
+    case Inactive = 'inactive';
+
+    /**
+     * Subscription model から派生状態を導出。
+     *
+     * 評価順は重要 (stripe_status を最優先に保つ):
+     *   1. stripe_status を最初に評価 → terminal/拒否系は即返却 (schedule_id に関わらず)
+     *   2. paused / past_due は専用 state へ
+     *   3. schedule_setup_status === Created (部分完了) は UpgradeRecovery 扱い
+     */
+    public static function fromSubscription(Subscription $sub): self
+    {
+        // paused / past_due は固有 state に分離 (stripe_status 最優先・schedule 状態に依らない)。
+        if ($sub->stripe_status === 'paused') {
+            return self::Paused;
+        }
+        if ($sub->stripe_status === 'past_due') {
+            return self::PastDue;
+        }
+
+        // trialing は試用期間として通す。それ以外の非 active 系 (canceled/unpaid/incomplete*) は Inactive。
+        $activeStatuses = ['active', 'trialing'];
+        if (! in_array($sub->stripe_status, $activeStatuses, true)) {
+            return self::Inactive;
+        }
+
+        // 部分完了 schedule は recovery 扱い (Stripe phases 未設定 = phase transition 起きない)。
+        // enum cast 経由なので instance 比較。
+        if ($sub->stripe_schedule_id !== null
+            && $sub->schedule_setup_status === ScheduleSetupStatus::Created) {
+            return self::UpgradeRecovery;
+        }
+
+        return self::Active;
+    }
+
+    /**
+     * state 単体の粗いアクセス判定。**最終判定には使わない**
+     * (`SubscriptionService::deriveEntitlement` 経由が唯一の経路)。
+     *
+     * - `PastDue` = true: 請求失敗中でも利用継続 (PM 無し past_due の遮断は deriveEntitlement)。
+     * - `Paused` = false: trial 後カード無し read-only。
+     */
+    public function grantsAccess(): bool
+    {
+        return match ($this) {
+            self::Active, self::UpgradeRecovery, self::PastDue => true,
+            self::Paused, self::Inactive => false,
+        };
+    }
+}
diff --git a/app/Enums/CheckoutIntent.php b/app/Enums/CheckoutIntent.php
new file mode 100644
index 0000000..17c7aeb
--- /dev/null
+++ b/app/Enums/CheckoutIntent.php
@@ -0,0 +1,20 @@
+<?php
+
+declare(strict_types=1);
+
+namespace App\Enums;
+
+/**
+ * billing_checkout_sessions の購入意図。
+ *
+ * 移植元の `CreditPurchase` はチケットスポット購入を担う既存の別テーブル
+ * (`App\Models\Billing\TicketCheckoutSession`) が受け持ち、`SignupFunding` は
+ * campaign / trial 機構が AI-CUE に存在しないため、いずれも移植しない。
+ */
+enum CheckoutIntent: string
+{
+    case SubscriptionStart = 'subscription_start';
+
+    /** オートリチャージ用の決済手段保存 (Checkout mode=setup)。課金は伴わない。 */
+    case SetupPaymentMethod = 'setup_payment_method';
+}
diff --git a/app/Enums/CheckoutSessionStatus.php b/app/Enums/CheckoutSessionStatus.php
new file mode 100644
index 0000000..2cf1705
--- /dev/null
+++ b/app/Enums/CheckoutSessionStatus.php
@@ -0,0 +1,13 @@
+<?php
+
+declare(strict_types=1);
+
+namespace App\Enums;
+
+enum CheckoutSessionStatus: string
+{
+    case Pending = 'pending';
+    case Completed = 'completed';
+    case Failed = 'failed';
+    case Expired = 'expired';
+}
diff --git a/app/Exceptions/Billing/StripePriceNotSyncedException.php b/app/Exceptions/Billing/StripePriceNotSyncedException.php
new file mode 100644
index 0000000..d448e66
--- /dev/null
+++ b/app/Exceptions/Billing/StripePriceNotSyncedException.php
@@ -0,0 +1,26 @@
+<?php
+
+declare(strict_types=1);
+
+namespace App\Exceptions\Billing;
+
+use RuntimeException;
+
+/**
+ * production runtime で未 sync の test mode Price (= livemode=false or synced_at IS NULL)
+ * を checkout 経路に使おうとしたとき。
+ * 通常は deploy 手順での sync 実行漏れが原因。
+ */
+class StripePriceNotSyncedException extends RuntimeException
+{
+    public function __construct(string $lookupKey, string $message = '')
+    {
+        if ($message === '') {
+            $message = sprintf(
+                'Stripe Price (lookup_key=%s) が未 sync の test mode のままです。 deploy 手順を確認してください。',
+                $lookupKey,
+            );
+        }
+        parent::__construct($message);
+    }
+}
diff --git a/app/Http/Controllers/Billing/BillingController.php b/app/Http/Controllers/Billing/BillingController.php
index fc1676a..d50008e 100644
--- a/app/Http/Controllers/Billing/BillingController.php
+++ b/app/Http/Controllers/Billing/BillingController.php
@@ -5,18 +5,20 @@
 namespace App\Http\Controllers\Billing;
 
 use App\Enums\Billing\PlanPriceKind;
+use App\Exceptions\Billing\StripePriceNotSyncedException;
 use App\Http\Concerns\ResolvesCurrentOrganization;
 use App\Http\Controllers\Controller;
 use App\Http\Requests\Billing\BillingCheckoutRequest;
 use App\Models\Billing\Plan;
 use App\Models\User;
-use App\Services\Billing\SubscriptionCheckoutGateway;
+use App\Services\Billing\SubscriptionService;
 use App\Services\Billing\TicketLedgerService;
 use Illuminate\Http\RedirectResponse;
 use Illuminate\Http\Request;
 use Illuminate\Support\Facades\Gate;
 use Inertia\Inertia;
 use Inertia\Response;
+use InvalidArgumentException;
 use Symfony\Component\HttpFoundation\Response as SymfonyResponse;
 use Webmozart\Assert\Assert;
 
@@ -66,9 +68,9 @@ public function index(Request $request, TicketLedgerService $tickets): Response
 
     /**
      * Stripe Checkout を開始し、Checkout URL へリダイレクトする
-     * (戻り型に RedirectResponse を含むのは price 不在時の back() 分岐のため)
+     * (戻り型に RedirectResponse を含むのは price 不在 / 開始不可時の back() 分岐のため)
      */
-    public function checkout(BillingCheckoutRequest $request, SubscriptionCheckoutGateway $gateway): SymfonyResponse|RedirectResponse
+    public function checkout(BillingCheckoutRequest $request, SubscriptionService $subscriptions): SymfonyResponse|RedirectResponse
     {
         $organization = $this->resolveCurrentOrganization($request);
         Gate::authorize('manageBilling', $organization);
@@ -82,23 +84,31 @@ public function checkout(BillingCheckoutRequest $request, SubscriptionCheckoutGa
             return back()->with('error', '選択したプランは現在お申し込みいただけません。');
         }
 
-        $redirect = $gateway->createSubscriptionCheckout(
-            $organization,
-            $price->stripe_price_id,
-            route('billing.index'),
-            route('billing.index'),
-        );
+        try {
+            $redirect = $subscriptions->startCheckout(
+                $organization,
+                $price,
+                route('billing.index'),
+                route('billing.index'),
+            );
+        } catch (StripePriceNotSyncedException) {
+            // production の sync 漏れ。500 にせず現行と同一文言で差し戻す
+            return back()->with('error', '選択したプランは現在お申し込みいただけません。');
+        } catch (InvalidArgumentException $e) {
+            // 既に有効なサブスクリプションがある (service 層の fail-closed ガード)
+            return back()->with('error', $e->getMessage());
+        }
 
         // 外部 URL への遷移は Inertia::location (full page redirect)
         return Inertia::location($redirect->url);
     }
 
     /** Stripe Customer Portal へリダイレクトする (支払い方法・解約の自己管理) */
-    public function portal(Request $request, SubscriptionCheckoutGateway $gateway): SymfonyResponse
+    public function portal(Request $request, SubscriptionService $subscriptions): SymfonyResponse
     {
         $organization = $this->resolveCurrentOrganization($request);
         Gate::authorize('manageBilling', $organization);
 
-        return Inertia::location($gateway->portalRedirect($organization, route('billing.index'))->url);
+        return Inertia::location($subscriptions->createPortalSession($organization, route('billing.index'))->url);
     }
 }
diff --git a/app/Http/Controllers/Organizations/OrganizationController.php b/app/Http/Controllers/Organizations/OrganizationController.php
index a6138b1..07ea31c 100644
--- a/app/Http/Controllers/Organizations/OrganizationController.php
+++ b/app/Http/Controllers/Organizations/OrganizationController.php
@@ -4,6 +4,7 @@
 
 namespace App\Http\Controllers\Organizations;
 
+use App\Actions\Organizations\RenameOrganizationAction;
 use App\Enums\TwoFactorStatus;
 use App\Http\Controllers\Controller;
 use App\Http\Requests\Organizations\StoreOrganizationRequest;
@@ -94,15 +95,21 @@ public function settings(
         ]);
     }
 
-    /** 組織名の更新 */
-    public function update(UpdateOrganizationRequest $request, Organization $organization): RedirectResponse
-    {
+    /**
+     * 組織名の更新。
+     * 更新本体は RenameOrganizationAction (transaction + Stripe customer 同期の発火) に委譲する。
+     */
+    public function update(
+        UpdateOrganizationRequest $request,
+        Organization $organization,
+        RenameOrganizationAction $rename,
+    ): RedirectResponse {
         Gate::authorize('update', $organization);
 
         $name = $request->validated('name');
         Assert::string($name);
 
-        $organization->fill(['name' => $name])->save();
+        $rename->execute($organization, $name);
 
         return back()->with('success', '組織名を更新しました');
     }
diff --git a/app/Jobs/Billing/SyncBillingCustomerDetails.php b/app/Jobs/Billing/SyncBillingCustomerDetails.php
new file mode 100644
index 0000000..9ddb310
--- /dev/null
+++ b/app/Jobs/Billing/SyncBillingCustomerDetails.php
@@ -0,0 +1,42 @@
+<?php
+
+declare(strict_types=1);
+
+namespace App\Jobs\Billing;
+
+use App\Models\Organization;
+use App\Services\Billing\Contracts\StripeGatewayInterface;
+use Illuminate\Bus\Queueable;
+use Illuminate\Contracts\Queue\ShouldQueue;
+use Illuminate\Foundation\Bus\Dispatchable;
+use Illuminate\Queue\InteractsWithQueue;
+use Illuminate\Queue\SerializesModels;
+
+/**
+ * Organization の name を Stripe customer へ同期する queued job。
+ *
+ * Cashier 標準 `Laravel\Cashier\Jobs\SyncCustomerDetails` の代替。標準 job は billable を
+ * `Billable` (trait) として扱うため Organization 直渡しが PHPStan level 10 で trait-as-type
+ * 不一致になる。Organization を明示的に受ける自前 job にすることで型安全に保つ。
+ *
+ * 本 job の dispatch は `BillingCustomerSynchronizer::dispatchFor()` 1 経路に限定する
+ * (IV-2、tests/Architecture/BillingSyncDispatchInvariantTest で機械的に強制)。
+ */
+final class SyncBillingCustomerDetails implements ShouldQueue
+{
+    use Dispatchable;
+    use InteractsWithQueue;
+    use Queueable;
+    use SerializesModels;
+
+    public function __construct(
+        public readonly Organization $organization,
+    ) {}
+
+    public function handle(StripeGatewayInterface $gateway): void
+    {
+        // 同期を StripeGatewayInterface 経由にし、fake 環境 (bug-hunt / Browser) では実 Stripe を
+        // 叩かず no-op になるようにする (Cashier 同期を job から直接呼ぶと fake を素通りする)。
+        $gateway->syncCustomerDetails($this->organization);
+    }
+}
diff --git a/app/Models/Billing/BillingCheckoutSession.php b/app/Models/Billing/BillingCheckoutSession.php
new file mode 100644
index 0000000..1afc55d
--- /dev/null
+++ b/app/Models/Billing/BillingCheckoutSession.php
@@ -0,0 +1,95 @@
+<?php
+
+declare(strict_types=1);
+
+namespace App\Models\Billing;
+
+use App\Enums\CheckoutIntent;
+use App\Enums\CheckoutSessionStatus;
+use App\Models\Organization;
+use Database\Factories\Billing\BillingCheckoutSessionFactory;
+use Illuminate\Database\Eloquent\Factories\HasFactory;
+use Illuminate\Database\Eloquent\Model;
+use Illuminate\Database\Eloquent\Relations\BelongsTo;
+use Illuminate\Support\Carbon;
+
+/**
+ * サブスク契約 Checkout Session の追跡行 (`BillingAccess::state()` の
+ * PendingCheckout / ExpiredCheckout の真実源)。
+ *
+ * @property int $id
+ * @property int $organization_id
+ * @property int|null $initiated_by_user_id
+ * @property string $intent
+ * @property string|null $plan_code
+ * @property string $stripe_session_id
+ * @property string $idempotency_key
+ * @property string|null $attempt_token
+ * @property string|null $checkout_url
+ * @property string $status
+ * @property Carbon|null $completed_at
+ * @property Carbon|null $created_at
+ * @property Carbon|null $updated_at
+ */
+class BillingCheckoutSession extends Model
+{
+    /** @use HasFactory<BillingCheckoutSessionFactory> */
+    use HasFactory;
+
+    /**
+     * tenant / actor キー (organization_id / initiated_by_user_id) は移植元と異なり
+     * $fillable に載せない (MassAssignmentProtectedKeys の不変条件。relation / 明示代入のみ)。
+     *
+     * @var list<string>
+     */
+    protected $fillable = [
+        'intent',
+        'plan_code',
+        'stripe_session_id',
+        'idempotency_key',
+        'attempt_token',
+        'checkout_url',
+        'status',
+        'completed_at',
+    ];
+
+    /** @var array<string, string> */
+    protected $casts = [
+        'completed_at' => 'datetime',
+        'initiated_by_user_id' => 'integer',
+    ];
+
+    protected static function newFactory(): BillingCheckoutSessionFactory
+    {
+        return BillingCheckoutSessionFactory::new();
+    }
+
+    public function intentEnum(): CheckoutIntent
+    {
+        return CheckoutIntent::from($this->intent);
+    }
+
+    public function statusEnum(): CheckoutSessionStatus
+    {
+        return CheckoutSessionStatus::from($this->status);
+    }
+
+    /**
+     * Pending かつ checkout_url 生存 = 復帰可能な進行中 Checkout。
+     * 購入導線が resume 状態 (decision URL 再提示) を出すか判定する述語。
+     */
+    public function isReplayablePending(): bool
+    {
+        return $this->status === CheckoutSessionStatus::Pending->value
+            && $this->checkout_url !== null
+            && $this->checkout_url !== '';
+    }
+
+    /**
+     * @return BelongsTo<Organization, $this>
+     */
+    public function organization(): BelongsTo
+    {
+        return $this->belongsTo(Organization::class);
+    }
+}
diff --git a/app/Models/Billing/Subscription.php b/app/Models/Billing/Subscription.php
index d128716..6ad2b9a 100644
--- a/app/Models/Billing/Subscription.php
+++ b/app/Models/Billing/Subscription.php
@@ -20,6 +20,8 @@
  * - stripe_schedule_id / schedule_setup_status: Subscription Schedule の
  *   2 段 API call (create → update phases) の部分完了追跡
  *   (billing:reconcile-schedules が復旧する。ScheduleSetupStatus 参照)
+ * - has_payment_method: 決済手段が登録済みか (monotonic snapshot。true から false へ戻さない)。
+ *   SubscriptionService::deriveEntitlement が trial 終了後の遮断判定に使う
  *
  * schedule 列は状態キーのため markSchedule* / clearSchedule 経由でのみ変更する。
  *
@@ -27,6 +29,7 @@
  * @property int $organization_id
  * @property string $stripe_id
  * @property string $stripe_status
+ * @property bool $has_payment_method
  * @property Carbon|null $current_period_end
  * @property string|null $stripe_schedule_id
  * @property ScheduleSetupStatus $schedule_setup_status
@@ -84,6 +87,7 @@ protected function casts(): array
     {
         return [
             'current_period_end' => 'datetime',
+            'has_payment_method' => 'boolean',
             'schedule_setup_status' => ScheduleSetupStatus::class,
         ];
     }
diff --git a/app/Policies/OrganizationPolicy.php b/app/Policies/OrganizationPolicy.php
index da4f9c2..9912be2 100644
--- a/app/Policies/OrganizationPolicy.php
+++ b/app/Policies/OrganizationPolicy.php
@@ -8,6 +8,7 @@
 use App\Models\Organization;
 use App\Models\User;
 use App\Services\ApiKey\ApiKeyPermissionService;
+use App\Services\Billing\BillingPermissionService;
 
 /**
  * 組織の認可。判定は User::organizationRole() (laratrust_team_id 明示 =
@@ -33,10 +34,24 @@ public function manageMembers(User $user, Organization $organization): bool
         return $user->organizationRole($organization)?->canManage() ?? false;
     }
 
-    /** 課金管理 (プラン変更 / Customer Portal): owner / admin */
+    /**
+     * 課金管理 (プラン変更 / Customer Portal): owner / admin を既定境界とし、加えて
+     * `manage-billing` を直接付与された一般メンバーにも許可する
+     * ({@see BillingPermissionService})。非メンバー (role null) は直接付与が残存しても不可。
+     */
     public function manageBilling(User $user, Organization $organization): bool
     {
-        return $user->organizationRole($organization)?->canManage() ?? false;
+        $role = $user->organizationRole($organization);
+
+        if ($role === null) {
+            return false;
+        }
+
+        if ($role->canManage()) {
+            return true;
+        }
+
+        return app(BillingPermissionService::class)->hasDirectPermission($user, $organization);
     }
 
     /**
diff --git a/app/Providers/AppServiceProvider.php b/app/Providers/AppServiceProvider.php
index 9ab637f..2398c28 100644
--- a/app/Providers/AppServiceProvider.php
+++ b/app/Providers/AppServiceProvider.php
@@ -19,10 +19,10 @@
 use App\Models\Organization;
 use App\Models\User;
 use App\Notifications\Channels\OrganizationScopedDatabaseChannel;
-use App\Services\Billing\CashierSubscriptionCheckoutGateway;
+use App\Services\Billing\CashierStripeGateway;
 use App\Services\Billing\CashierTicketCheckoutGateway;
+use App\Services\Billing\Contracts\StripeGatewayInterface;
 use App\Services\Billing\StripeWebhookProcessor;
-use App\Services\Billing\SubscriptionCheckoutGateway;
 use App\Services\Billing\TicketCheckoutGateway;
 use App\Services\Mail\Sns\AwsSnsSignatureVerifier;
 use App\Services\Mail\Sns\SnsSignatureVerifier;
@@ -107,7 +107,7 @@ public function register(): void
 
         // サブスク Checkout / Customer Portal の Stripe 抽象。fake_externals 時は
         // FakeExternalsServiceProvider が fake に rebind する (providers.php で後勝ち)
-        $this->app->bind(SubscriptionCheckoutGateway::class, CashierSubscriptionCheckoutGateway::class);
+        $this->app->bind(StripeGatewayInterface::class, CashierStripeGateway::class);
 
         // アプリ内通知 (T008): database channel を薄い拡張へ差し替え、AppNotification の
         // organization_id を notifications テーブルの first-class 列として書き込む
diff --git a/app/Providers/FakeExternalsServiceProvider.php b/app/Providers/FakeExternalsServiceProvider.php
index 833c420..fb2a15b 100644
--- a/app/Providers/FakeExternalsServiceProvider.php
+++ b/app/Providers/FakeExternalsServiceProvider.php
@@ -7,9 +7,9 @@
 use App\Http\Controllers\Testing\GetFakeStorageObjectController;
 use App\Http\Controllers\Testing\PutFakeStorageObjectController;
 use App\Services\AI\Testing\CannedPromptFakeRegistrar;
-use App\Services\Billing\Fakes\FakeSubscriptionCheckoutGateway;
+use App\Services\Billing\Contracts\StripeGatewayInterface;
+use App\Services\Billing\Fakes\FakeStripeGateway;
 use App\Services\Billing\Fakes\FakeTicketCheckoutGateway;
-use App\Services\Billing\SubscriptionCheckoutGateway;
 use App\Services\Billing\TicketCheckoutGateway;
 use App\Services\Capture\Fakes\FakeTakeObjectStorage;
 use App\Services\Capture\TakeObjectStorage;
@@ -77,7 +77,7 @@ private function registerPaymentFakes(): void
 
         // Stripe 到達点を fake へ rebind (課金状態の正本は BughuntBillingSeeder)
         $this->app->bind(TicketCheckoutGateway::class, FakeTicketCheckoutGateway::class);
-        $this->app->bind(SubscriptionCheckoutGateway::class, FakeSubscriptionCheckoutGateway::class);
+        $this->app->bind(StripeGatewayInterface::class, FakeStripeGateway::class);
     }
 
     /** LLM (Prism) fake (fake_llm + LLM_FAKE_ENVIRONMENTS。挙動不変) */
diff --git a/app/Services/Billing/BillingAccess.php b/app/Services/Billing/BillingAccess.php
index 1642750..9f1d69e 100644
--- a/app/Services/Billing/BillingAccess.php
+++ b/app/Services/Billing/BillingAccess.php
@@ -4,49 +4,124 @@
 
 namespace App\Services\Billing;
 
+use App\Enums\Billing\OnboardingBillingState;
+use App\Enums\CheckoutSessionStatus;
+use App\Models\Billing\BillingCheckoutSession;
+use App\Models\Billing\Subscription;
 use App\Models\Organization;
+use Carbon\CarbonImmutable;
+use Illuminate\Database\Eloquent\Collection;
 
 /**
- * 組織が業務機能を利用してよいか (billing entitlement) の判定。
+ * Organization の課金状態を「流入制御目線」で判定する責務。
  *
  * **課金による利用可否の判定は必ず本クラスを経由する** (middleware / controller /
- * service での subscription 直参照は禁止)。判定基準を 1 クラスに閉じ込めることで、
- * アプリ側は本クラスの書き換えだけで gate 方針を変更できる。
+ * service での subscription 直参照は禁止)。OrganizationPolicy 等の user action 認可とは
+ * 責務分離する (Policy は user × organization、本 service は organization × subscription state)。
  *
- * AI-CUE の entitlement 方針 (テンプレート既定の「active subscription 必須」からの
- * 意図的な書き換え。devnotes/20260712-0927-bugfix-billing-free-access):
- *
- * - plan_code null (未契約) = fallback free プラン。**支払い不要 tier としてアクセス許可**。
- *   有償価値は別レイヤで gate 済み (チケット残高 = analyze/render、Quota = max_projects 等)
- * - plan_code 非 null = 有償プラン契約状態。subscription('default') が active / trialing の
- *   ときのみ許可 (past_due / canceled / incomplete / 行不在は fail-closed で不許可 =
- *   支払い健全性の担保のみが本ゲートの責務)
- *
- * 不変条件 (依存するデータモデル契約): `organizations.plan_code` は Stripe Price を持つ
- * 有償プランの契約時のみ StripeWebhookProcessor が set し、subscription.deleted で null に
- * 戻す。支払い不要のプランを plan_code に載せる場合は本判定とセットで見直すこと
- * (挙動は RequireActiveSubscriptionMiddlewareTest が固定する)。
- *
- * 注: 本メソッドは「subscription を持つか」ではなく「業務ルートを利用してよいか
- * (billing entitlement)」を返す。free 組織は subscription 無しで true になる。
+ * 利用可否は `SubscriptionState` 単体ではなく `SubscriptionService::deriveEntitlement` で
+ * 確定する (PM 有無 / trial 終了 / paused / past_due を合成)。
  */
 class BillingAccess
 {
-    /** アクセスを許可する Stripe subscription status (有償プラン契約時のみ参照) */
-    private const array GRANTING_STATUSES = ['active', 'trialing'];
+    public function __construct(
+        private readonly SubscriptionService $subscriptions,
+    ) {}
 
+    /**
+     * 組織が業務機能を利用してよいか (billing entitlement)。
+     *
+     * `state()->grantsAccess()` が本来の判定。これに加えて **移行 OR を 1 行持つ**:
+     * 現行の意図的な free 許可 (= `plan_code === null` の未契約組織) をそのまま通す。
+     *
+     * **この移行 OR は P4 (ゲート反転) で削除する**。削除条件は grandfathering backfill
+     * (`organizations.free_plan_code = 'personal'`) の完了で、backfill が `ActiveFreePlan` を
+     * 成立させることで既存の free 組織が `state()` 側で許可される。**本行を消すことが
+     * ゲート反転そのもの**であり、P4 はこの 1 行削除 + 期待反転の diff だけで済む。
+     */
     public function hasActiveAccess(Organization $organization): bool
     {
-        // 未契約 (plan_code null) = fallback free プラン。支払い不要 tier として許可
-        if ($organization->plan_code === null) {
+        if ($this->state($organization)->grantsAccess()) {
             return true;
         }
 
-        // 有償プラン契約状態: 支払い健全性 (active/trialing) を要求。
-        // 行不在 (webhook 順序逆転等) も fail-closed で不許可
-        $subscription = $organization->subscription('default');
+        return $organization->plan_code === null;
+    }
+
+    /**
+     * 流入制御目線の課金状態。**`plan_code` を一切見ない** (entitlement は subscription /
+     * free_plan_code / checkout session から導出する)。
+     *
+     * 読み取り経路のため **DB 書き込みをしない**。stale な pending checkout は in-memory で
+     * expired 扱いにしてアクセス判定の整合性を保ち、実 DB の expired 化は sweeper に委ねる
+     * (require.subscription が付く多数の GET 経路で毎回 UPDATE が走る副作用を排除する)。
+     */
+    public function state(Organization $organization): OnboardingBillingState
+    {
+        $sub = $organization->subscription('default');
+        $entitled = $sub instanceof Subscription
+            && $this->subscriptions->deriveEntitlement($sub)->entitled;
 
-        return $subscription !== null
-            && in_array($subscription->stripe_status, self::GRANTING_STATUSES, true);
+        // 利用可否は SubscriptionState 単体ではなく deriveEntitlement で確定する
+        // (SubscriptionState::grantsAccess を直接参照しない)。
+        if ($entitled) {
+            return OnboardingBillingState::Subscribed;
+        }
+
+        // 現在 entitled な Stripe subscription が「ない」(行の不在ではなく entitlement で判定。
+        // canceled 等の過去行が残っていてもよい = paid→free 経路) とき free entitlement を見る。
+        // 判定は定数比較 (未知値は fail-closed で通さない)。entitled subscription があれば上で
+        // Subscribed 優先 (free と併存しない invariant)。
+        if ($organization->free_plan_code === PersonalPlanService::FREE_PLAN_CODE) {
+            return OnboardingBillingState::ActiveFreePlan;
+        }
+
+        if ($sub instanceof Subscription) {
+            // 利用不可 (Inactive / Paused / trial 終了 & PM 無 / PM 無 past_due) は gate を通さない
+            // → ExpiredCheckout 扱い (未契約導線へ)。
+            return OnboardingBillingState::ExpiredCheckout;
+        }
+
+        $threshold = self::staleThresholdAt(CarbonImmutable::now());
+        /** @var Collection<int, BillingCheckoutSession> $pendingRows */
+        $pendingRows = BillingCheckoutSession::query()
+            ->where('organization_id', $organization->id)
+            ->where('status', CheckoutSessionStatus::Pending->value)
+            ->get(['id', 'created_at']);
+
+        $hasLivePending = false;
+        $hasStalePending = false;
+        foreach ($pendingRows as $row) {
+            if ($row->created_at !== null && $row->created_at->lessThan($threshold)) {
+                $hasStalePending = true;
+            } else {
+                $hasLivePending = true;
+            }
+        }
+
+        if ($hasLivePending) {
+            return OnboardingBillingState::PendingCheckout;
+        }
+
+        $hasExpired = $hasStalePending || BillingCheckoutSession::query()
+            ->where('organization_id', $organization->id)
+            ->whereIn('status', [
+                CheckoutSessionStatus::Expired->value,
+                CheckoutSessionStatus::Failed->value,
+            ])
+            ->exists();
+
+        return $hasExpired ? OnboardingBillingState::ExpiredCheckout : OnboardingBillingState::NoSubscription;
+    }
+
+    /**
+     * pending checkout の stale 境界 (単一出典)。
+     *
+     * **live = `created_at >= staleThresholdAt($now)` / stale = `created_at < staleThresholdAt($now)`**
+     * の排他で統一する。sweeper (実 DB の expire) も本 helper を `<` で読むこと。
+     */
+    public static function staleThresholdAt(CarbonImmutable $now): CarbonImmutable
+    {
+        return $now->subDay();
     }
 }
diff --git a/app/Services/Billing/BillingCustomerSynchronizer.php b/app/Services/Billing/BillingCustomerSynchronizer.php
new file mode 100644
index 0000000..37d3b52
--- /dev/null
+++ b/app/Services/Billing/BillingCustomerSynchronizer.php
@@ -0,0 +1,35 @@
+<?php
+
+declare(strict_types=1);
+
+namespace App\Services\Billing;
+
+use App\Jobs\Billing\SyncBillingCustomerDetails;
+use App\Models\Organization;
+
+/**
+ * Stripe customer 同期 job の dispatch を集約する単一窓口 (IV-2)。
+ *
+ * 同期を発火するのは `RenameOrganizationAction` のみ (請求先連絡先の更新経路は P9)。
+ * webhook ハンドラはこの経路を通らないため、Stripe→アプリ→Stripe の同期ループは構造的に発生しない。
+ */
+final class BillingCustomerSynchronizer
+{
+    /**
+     * Stripe customer 同期 job を dispatch する。
+     *
+     * **必ず `DB::transaction` クロージャの内側から呼ぶこと。** transaction 内で
+     * `afterCommit()` を付けることで outer commit 後に発火し、commit 前の stale read を防ぐ (IV-3)。
+     * transaction の外で呼ぶと `afterCommit()` が即時実行になり遅延保証が崩れるため禁止。
+     *
+     * Stripe customer 未作成 (`stripe_id === null`) の組織は no-op (IV-4、例外にしない)。
+     */
+    public function dispatchFor(Organization $organization): void
+    {
+        if ($organization->stripe_id === null) {
+            return;
+        }
+
+        SyncBillingCustomerDetails::dispatch($organization)->afterCommit();
+    }
+}
diff --git a/app/Services/Billing/BillingPermissionService.php b/app/Services/Billing/BillingPermissionService.php
new file mode 100644
index 0000000..a4684bd
--- /dev/null
+++ b/app/Services/Billing/BillingPermissionService.php
@@ -0,0 +1,135 @@
+<?php
+
+declare(strict_types=1);
+
+namespace App\Services\Billing;
+
+use App\Models\Organization;
+use App\Models\Permission;
+use App\Models\User;
+use App\Policies\OrganizationPolicy;
+use DomainException;
+use Illuminate\Support\Facades\DB;
+use Webmozart\Assert\Assert;
+
+/**
+ * 組織スコープ `manage-billing` permission の付与 / 剥奪 / 参照を担う domain service。
+ *
+ * 認可の既定境界 (Owner / Admin) は {@see OrganizationPolicy::manageBilling}
+ * がロールで判定する。本 service は **その既定境界の外にいる一般メンバーへ個別付与** する
+ * ための flat 付与モデル (専用 Role を作らない) を提供し、Policy 側が
+ * {@see self::hasDirectPermission()} を OR で参照する。
+ *
+ * 一覧描画では {@see self::getDirectManageBillingMap()} で N+1 を避けて直接付与状態を一括取得する。
+ */
+final class BillingPermissionService
+{
+    /** 組織請求管理 permission の name (`config/permission` 相当のアプリ規約: kebab)。 */
+    public const PERMISSION_MANAGE_BILLING = 'manage-billing';
+
+    /**
+     * 対象ユーザーに `manage-billing` permission を付与する (組織メンバー限定)。
+     */
+    public function grant(User $target, Organization $organization): void
+    {
+        $teamId = $this->ensureTeamId($organization);
+        $this->ensureMembership($target, $organization);
+
+        $target->givePermission(self::PERMISSION_MANAGE_BILLING, $teamId);
+        $target->flushCache();
+    }
+
+    /**
+     * 対象ユーザーから `manage-billing` permission を剥奪する (組織メンバー限定)。
+     */
+    public function revoke(User $target, Organization $organization): void
+    {
+        $teamId = $this->ensureTeamId($organization);
+        $this->ensureMembership($target, $organization);
+
+        $target->removePermission(self::PERMISSION_MANAGE_BILLING, $teamId);
+        $target->flushCache();
+    }
+
+    /**
+     * 指定組織での `manage-billing` 直接付与の有無。
+     *
+     * 暗黙許可 (Owner / Admin) は含めず **直接付与のみ** を見る。
+     * 組織メンバーでない場合は false (退会後に残存し得る permission の安全側挙動)。
+     */
+    public function hasDirectPermission(User $user, Organization $organization): bool
+    {
+        $teamId = $this->ensureTeamId($organization);
+
+        if (! $organization->users()->where('users.id', $user->id)->exists()) {
+            return false;
+        }
+
+        return $user->isAbleTo(self::PERMISSION_MANAGE_BILLING, $teamId);
+    }
+
+    /**
+     * 指定組織・指定ユーザー群の直接付与状態を 1 クエリで取得する (一覧描画の eager load 用)。
+     *
+     * 境界条件: `permission_user` を直接引き **membership は検査しない**。退会済ユーザーでも
+     * 行が残っていれば true を返すため、呼び出し側は **その組織のメンバー user_id だけを渡す**
+     * こと (一覧表示用途に最適化)。非メンバーを渡した場合の挙動は未定義。
+     *
+     * @param  list<int>  $userIds
+     * @return array<int, bool>
+     */
+    public function getDirectManageBillingMap(Organization $organization, array $userIds): array
+    {
+        $teamId = $this->ensureTeamId($organization);
+
+        if ($userIds === []) {
+            return [];
+        }
+
+        $permissionId = Permission::query()
+            ->where('name', self::PERMISSION_MANAGE_BILLING)
+            ->value('id');
+
+        if ($permissionId === null) {
+            return array_fill_keys($userIds, false);
+        }
+
+        $grantedRaw = DB::table('permission_user')
+            ->where('permission_id', $permissionId)
+            ->where('team_id', $teamId)
+            ->whereIn('user_id', $userIds)
+            ->pluck('user_id')
+            ->all();
+
+        $map = array_fill_keys($userIds, false);
+        foreach ($grantedRaw as $id) {
+            Assert::integerish($id);
+            $map[(int) $id] = true;
+        }
+
+        return $map;
+    }
+
+    private function ensureTeamId(Organization $organization): int
+    {
+        $teamId = $organization->laratrust_team_id;
+        Assert::integer(
+            $teamId,
+            'Organization must have a laratrust_team_id to manage billing permission.',
+        );
+
+        return $teamId;
+    }
+
+    private function ensureMembership(User $target, Organization $organization): void
+    {
+        $isMember = $organization->users()->where('users.id', $target->id)->exists();
+        if (! $isMember) {
+            throw new DomainException(sprintf(
+                'User %d is not a member of organization %d.',
+                $target->id,
+                $organization->id,
+            ));
+        }
+    }
+}
diff --git a/app/Services/Billing/CashierSubscriptionCheckoutGateway.php b/app/Services/Billing/CashierStripeGateway.php
similarity index 67%
rename from app/Services/Billing/CashierSubscriptionCheckoutGateway.php
rename to app/Services/Billing/CashierStripeGateway.php
index c125285..14c9a2e 100644
--- a/app/Services/Billing/CashierSubscriptionCheckoutGateway.php
+++ b/app/Services/Billing/CashierStripeGateway.php
@@ -6,14 +6,15 @@
 
 use App\DataTransferObjects\Billing\ExternalBillingRedirect;
 use App\Models\Organization;
+use App\Services\Billing\Contracts\StripeGatewayInterface;
 use Webmozart\Assert\Assert;
 
 /**
- * SubscriptionCheckoutGateway の Cashier (Stripe SDK) 実装。
+ * StripeGatewayInterface の Cashier (Stripe SDK) 実装。
  * ロジックは BillingController から移動 (挙動不変)。
  * PortalConfigurationSpec は同一名前空間 (App\Services\Billing) のため use 不要。
  */
-final class CashierSubscriptionCheckoutGateway implements SubscriptionCheckoutGateway
+final class CashierStripeGateway implements StripeGatewayInterface
 {
     public function createSubscriptionCheckout(
         Organization $organization,
@@ -34,7 +35,7 @@ public function createSubscriptionCheckout(
         return new ExternalBillingRedirect($url);
     }
 
-    public function portalRedirect(Organization $organization, string $returnUrl): ExternalBillingRedirect
+    public function createPortalSession(Organization $organization, string $returnUrl): ExternalBillingRedirect
     {
         // configuration id (billing:ensure-portal-configuration で生成) が設定されていれば
         // subscription_update 無効の spec 準拠 configuration で portal session を作る
@@ -44,4 +45,15 @@ public function portalRedirect(Organization $organization, string $returnUrl): E
             PortalConfigurationSpec::sessionOptions(config('cashier.portal_configuration_id')),
         ));
     }
+
+    public function syncCustomerDetails(Organization $organization): void
+    {
+        // 実 Stripe では Cashier の Billable 同期をそのまま使う。stripe_id 未設定は no-op
+        // (Cashier 側も customer 不在では更新しないが、呼び出し前提を実装側でも明示)。
+        if ($organization->stripe_id === null) {
+            return;
+        }
+
+        $organization->syncStripeCustomerDetails();
+    }
 }
diff --git a/app/Services/Billing/Contracts/StripeGatewayInterface.php b/app/Services/Billing/Contracts/StripeGatewayInterface.php
new file mode 100644
index 0000000..23965e7
--- /dev/null
+++ b/app/Services/Billing/Contracts/StripeGatewayInterface.php
@@ -0,0 +1,43 @@
+<?php
+
+declare(strict_types=1);
+
+namespace App\Services\Billing\Contracts;
+
+use App\DataTransferObjects\Billing\ExternalBillingRedirect;
+use App\Models\Organization;
+
+/**
+ * サブスクリプション系 Stripe 呼び出しの抽象
+ * (実装: CashierStripeGateway。fake_externals 時は FakeStripeGateway を bind)。
+ *
+ * Stripe 呼び出しを本 interface に閉じ、Controller / Service は戻り値 DTO の URL へ
+ * Inertia::location するのみ。チケット系は TicketCheckoutGateway が担う (境界を分ける)。
+ */
+interface StripeGatewayInterface
+{
+    /**
+     * subscription (type=default) の hosted Checkout Session を作り遷移先を返す。
+     */
+    public function createSubscriptionCheckout(
+        Organization $organization,
+        string $stripePriceId,
+        string $successUrl,
+        string $cancelUrl,
+    ): ExternalBillingRedirect;
+
+    /**
+     * Customer Portal セッションを作り遷移先を返す
+     * (configuration は PortalConfigurationSpec 準拠。実装側で解決する)。
+     */
+    public function createPortalSession(Organization $organization, string $returnUrl): ExternalBillingRedirect;
+
+    /**
+     * 請求先連絡先 (name 等) を Stripe Customer に同期する。
+     *
+     * Cashier の Billable 同期メソッドを job から直接呼ぶと fake 環境 (bug-hunt / Browser) を
+     * 素通りして実 Stripe API を叩く。同期も interface 境界を通すことで fake 可能にする。
+     * `stripe_id` 未設定の組織は呼び出し側で skip 済の前提 (実装側でも no-op を許容)。
+     */
+    public function syncCustomerDetails(Organization $organization): void;
+}
diff --git a/app/Services/Billing/Fakes/FakeSubscriptionCheckoutGateway.php b/app/Services/Billing/Fakes/FakeStripeGateway.php
similarity index 59%
rename from app/Services/Billing/Fakes/FakeSubscriptionCheckoutGateway.php
rename to app/Services/Billing/Fakes/FakeStripeGateway.php
index d144971..db21192 100644
--- a/app/Services/Billing/Fakes/FakeSubscriptionCheckoutGateway.php
+++ b/app/Services/Billing/Fakes/FakeStripeGateway.php
@@ -6,14 +6,14 @@
 
 use App\DataTransferObjects\Billing\ExternalBillingRedirect;
 use App\Models\Organization;
-use App\Services\Billing\SubscriptionCheckoutGateway;
+use App\Services\Billing\Contracts\StripeGatewayInterface;
 
 /**
- * SubscriptionCheckoutGateway の runtime fake (fake_externals 環境専用)。
+ * StripeGatewayInterface の runtime fake (fake_externals 環境専用)。
  * 契約は FakeTicketCheckoutGateway と同じ「中立帰還」。subscription 状態は変更しない
  * (active subscription の正本は BughuntBillingSeeder)。
  */
-final class FakeSubscriptionCheckoutGateway implements SubscriptionCheckoutGateway
+final class FakeStripeGateway implements StripeGatewayInterface
 {
     public function createSubscriptionCheckout(
         Organization $organization,
@@ -24,8 +24,13 @@ public function createSubscriptionCheckout(
         return new ExternalBillingRedirect(FakeExternalUrl::neutralReturn($cancelUrl));
     }
 
-    public function portalRedirect(Organization $organization, string $returnUrl): ExternalBillingRedirect
+    public function createPortalSession(Organization $organization, string $returnUrl): ExternalBillingRedirect
     {
         return new ExternalBillingRedirect(FakeExternalUrl::neutralReturn($returnUrl));
     }
+
+    public function syncCustomerDetails(Organization $organization): void
+    {
+        // no-op: fake 環境は実 Stripe を叩かない (実呼び出しの正本は CashierStripeGateway)。
+    }
 }
diff --git a/app/Services/Billing/StripeWebhookProcessor.php b/app/Services/Billing/StripeWebhookProcessor.php
index 1b27c73..10141fe 100644
--- a/app/Services/Billing/StripeWebhookProcessor.php
+++ b/app/Services/Billing/StripeWebhookProcessor.php
@@ -28,9 +28,10 @@
  *
  * 1. stripe_webhook_events に event_id UNIQUE で冪等記録 (二重処理 skip)
  * 2. type 別 handler:
- *    - customer.subscription.created/updated: organizations.plan_code と
- *      subscriptions.current_period_end を同期
- *    - customer.subscription.deleted: plan_code を解除
+ *    - customer.subscription.created/updated: payload → SubscriptionSnapshot を
+ *      SubscriptionService::applySubscriptionSnapshot へ渡して状態同期 +
+ *      recordPaymentMethodSnapshot で決済手段有無を記録
+ *    - customer.subscription.deleted: 同上 (terminated=true。plan_code 解除 + schedule クリア)
  *    - invoice.paid: プランの monthly_ticket_grant を月次付与 (+ 初回は signup grant)
  *    - invoice.payment_failed: 支払い失敗通知 (BillingNotificationDispatcher 経由の send-once)
  *    - charge.refunded: 買い切りチケットの返金逆仕訳 (clawback)
@@ -40,11 +41,13 @@
  *    MAX_PROCESSING_ATTEMPTS 到達後は処理せず skip (= 200 terminal-ack) して
  *    恒久失敗イベントの無限 500 ストームを打ち切る (運用は failure_reason で調査する)
  *
- * subscriptions テーブル自体の同期 (updateOrCreate) は Cashier の WebhookController
- * が行うため、ここではアプリ状態 (plan_code / チケット) だけを扱う。
+ * subscriptions **行の作成** (updateOrCreate) は Cashier の WebhookController が唯一の
+ * writer。本クラス (WebhookReceived listener) は Cashier のハンドラより先に走るため、
+ * 行が無い間の状態同期は no-op に落ちる (直後の updated で追随する)。ここで行を作ると
+ * Cashier 側の subscription_items 生成が永久に skip されるため作らない。
  *
  * plan_code 不変条件: `organizations.plan_code` は Stripe Price を持つ有償プランの
- * 契約 (active/trialing) 時のみ本クラスが set し、`customer.subscription.deleted` で
+ * 契約 (active/trialing) 時のみ SubscriptionService が set し、`customer.subscription.deleted` で
  * null に戻す状態キー。**null = 未契約 = 支払い不要の free tier**
  * (config/quota.php の fallback_plan が適用される)。BillingAccess はこの契約を
  * entitlement 判定の根拠にするため、支払い不要のプランを plan_code に載せる場合は
@@ -61,9 +64,6 @@ class StripeWebhookProcessor
      */
     public const int MAX_PROCESSING_ATTEMPTS = 8;
 
-    /** plan_code を同期する subscription status (それ以外では既存値を維持する) */
-    private const array ACTIVE_SUBSCRIPTION_STATUSES = ['active', 'trialing'];
-
     /** 月次付与の対象となる invoice billing_reason */
     private const array GRANTING_BILLING_REASONS = ['subscription_create', 'subscription_cycle'];
 
@@ -71,6 +71,7 @@ public function __construct(
         private readonly TicketLedgerService $tickets,
         private readonly BillingNotificationDispatcher $notifications,
         private readonly PersonalPlanService $personalPlan,
+        private readonly SubscriptionService $subscriptions,
     ) {}
 
     public function handle(WebhookReceived $event): void
@@ -173,8 +174,8 @@ private function process(string $type, array $payload): void
         // case を足したらここに arm を足す (handled ⊆ subscribed は invariant test が担保)
         match (HandledStripeWebhookEvent::tryFrom($type)) {
             HandledStripeWebhookEvent::SubscriptionCreated,
-            HandledStripeWebhookEvent::SubscriptionUpdated => $this->syncSubscriptionState($payload),
-            HandledStripeWebhookEvent::SubscriptionDeleted => $this->clearPlanCode($payload),
+            HandledStripeWebhookEvent::SubscriptionUpdated => $this->syncSubscriptionState($payload, terminated: false),
+            HandledStripeWebhookEvent::SubscriptionDeleted => $this->syncSubscriptionState($payload, terminated: true),
             HandledStripeWebhookEvent::InvoicePaid => $this->grantMonthlyTickets($payload),
             HandledStripeWebhookEvent::ChargeRefunded => $this->clawbackRefundedTickets($payload),
             HandledStripeWebhookEvent::InvoicePaymentFailed => $this->handleInvoicePaymentFailed($payload),
@@ -185,61 +186,115 @@ private function process(string $type, array $payload): void
     }
 
     /**
-     * customer.subscription.created/updated: plan_code 同期 + 次回更新日時の同期。
+     * customer.subscription.created/updated/deleted: payload → SubscriptionSnapshot の写像 +
+     * 組織解決 + 決済手段有無の抽出。**状態の書込は SubscriptionService に委譲する**
+     * (Processor は写像と呼び出し順序だけを持つ)。
      *
-     * @param  array<mixed>  $payload
-     */
-    private function syncSubscriptionState(array $payload): void
-    {
-        $this->syncPlanCode($payload);
-        $this->syncSubscriptionPeriod($payload);
-    }
-
-    /**
-     * subscription snapshot から organizations.plan_code を同期する。
-     * status が active / trialing のときだけ反映 (past_due 等の扱いはアプリ判断で拡張する)。
+     * subscriptions 行自体の作成は Cashier の WebhookController が行う。WebhookReceived は
+     * Cashier の同期処理より先に発火するため、created イベント時点では行が無いことがある
+     * (best-effort: 直後の customer.subscription.updated / 次周期の更新で追随する)。
      *
      * @param  array<mixed>  $payload
+     * @param  bool  $terminated  customer.subscription.deleted のとき true (終了契機はこれのみ)
      */
-    private function syncPlanCode(array $payload): void
+    private function syncSubscriptionState(array $payload, bool $terminated): void
     {
         $organization = $this->resolveOrganization($payload);
         if ($organization === null) {
             return;
         }
 
-        $status = $this->stringAt($payload, 'data.object.status');
-        if (! in_array($status, self::ACTIVE_SUBSCRIPTION_STATUSES, true)) {
+        $stripeId = $this->stringAt($payload, 'data.object.id');
+        if ($stripeId === null) {
             return;
         }
 
-        $priceId = $this->stringAt($payload, 'data.object.items.data.0.price.id');
-        if ($priceId === null) {
-            return;
-        }
+        $snapshot = new SubscriptionSnapshot(
+            stripeId: $stripeId,
+            status: $this->stringAt($payload, 'data.object.status') ?? 'incomplete',
+            basePriceId: $this->stringAt($payload, 'data.object.items.data.0.price.id'),
+            baseQuantity: $this->intAt($payload, 'data.object.items.data.0.quantity'),
+            currentPeriodEnd: $this->periodEnd($payload),
+            trialEndsAt: $this->timestampToCarbon(data_get($payload, 'data.object.trial_end')),
+            endsAt: $this->timestampToCarbon(
+                data_get($payload, 'data.object.ended_at') ?? data_get($payload, 'data.object.cancel_at'),
+            ),
+        );
 
-        $plan = $this->planByStripePriceId($priceId);
-        if ($plan === null) {
-            return; // 未知の Price はアプリのプランに対応しない (受理のみ)
+        $this->subscriptions->applySubscriptionSnapshot($organization, $snapshot, terminated: $terminated);
+
+        if ($terminated) {
+            return; // 終了系では PM snapshot を記録しない (monotonic writer は契約中のみ)
         }
 
-        // plan_code は状態キー: webhook 同期でのみ明示代入する
-        $organization->plan_code = $plan->code;
-        $organization->save();
+        $subscription = Subscription::query()->where('stripe_id', $stripeId)->first();
+        if ($subscription instanceof Subscription) {
+            $this->subscriptions->recordPaymentMethodSnapshot(
+                $subscription,
+                $this->subscriptionHasPaymentMethod($payload),
+            );
+        }
     }
 
     /**
+     * subscription object が決済手段を持つか (default_payment_method / default_source)。
+     * Stripe は string id か expanded object のいずれも取り得るため union helper で抽出する。
+     *
      * @param  array<mixed>  $payload
      */
-    private function clearPlanCode(array $payload): void
+    private function subscriptionHasPaymentMethod(array $payload): bool
     {
-        $organization = $this->resolveOrganization($payload);
-        if ($organization === null) {
-            return;
+        return $this->resolveStripeIdField(data_get($payload, 'data.object.default_payment_method')) !== null
+            || $this->resolveStripeIdField(data_get($payload, 'data.object.default_source')) !== null;
+    }
+
+    /**
+     * Stripe の id フィールド (string id または expanded object) から id を取り出す。
+     */
+    private function resolveStripeIdField(mixed $value): ?string
+    {
+        if (is_string($value)) {
+            return $value !== '' ? $value : null;
+        }
+        if (is_array($value)) {
+            $id = $value['id'] ?? null;
+
+            return is_string($id) && $id !== '' ? $id : null;
         }
 
-        $organization->plan_code = null;
-        $organization->save();
+        return null;
+    }
+
+    /**
+     * 次回更新日時 (renewal reminder = billing:send-billing-reminders の真実源)。
+     * 新 API (basil) は item 配下、旧 API は subscription top-level に持つため両系を fallback で拾う。
+     *
+     * @param  array<mixed>  $payload
+     */
+    private function periodEnd(array $payload): ?CarbonImmutable
+    {
+        return $this->timestampToCarbon(
+            data_get($payload, 'data.object.items.data.0.current_period_end')
+                ?? data_get($payload, 'data.object.current_period_end'),
+        );
+    }
+
+    /** Stripe の epoch 秒を CarbonImmutable にする (非 int / 非正数は null)。 */
+    private function timestampToCarbon(mixed $value): ?CarbonImmutable
+    {
+        return is_int($value) && $value > 0 ? CarbonImmutable::createFromTimestamp($value) : null;
+    }
+
+    /**
+     * payload から int 値を安全に取り出す (それ以外の型は null)。
+     *
+     * @param  array<mixed>  $payload
+     */
+    private function intAt(array $payload, string $path): ?int
+    {
+        $value = data_get($payload, $path);
+
+        return is_int($value) ? $value : null;
     }
 
     /**
@@ -311,35 +366,6 @@ private function grantMonthlyTickets(array $payload): void
         );
     }
 
-    /**
-     * subscription snapshot から subscriptions.current_period_end を同期する
-     * (renewal reminder = billing:send-billing-reminders の真実源)。
-     *
-     * subscriptions 行自体の作成は Cashier の WebhookController が行う。WebhookReceived は
-     * Cashier の同期処理より先に発火するため、created イベント時点では行が無いことがある
-     * (best-effort: 直後の customer.subscription.updated / 次周期の更新で追随する)。
-     *
-     * @param  array<mixed>  $payload
-     */
-    private function syncSubscriptionPeriod(array $payload): void
-    {
-        $stripeId = $this->stringAt($payload, 'data.object.id');
-        if ($stripeId === null) {
-            return;
-        }
-
-        // 新 API (basil) は item 配下、旧 API は subscription top-level に持つため両系を fallback で拾う
-        $periodEnd = data_get($payload, 'data.object.items.data.0.current_period_end')
-            ?? data_get($payload, 'data.object.current_period_end');
-        if (! is_int($periodEnd) || $periodEnd <= 0) {
-            return;
-        }
-
-        Subscription::query()
-            ->where('stripe_id', $stripeId)
-            ->update(['current_period_end' => CarbonImmutable::createFromTimestamp($periodEnd)]);
-    }
-
     /**
      * invoice.payment_failed: 観測ログ + 支払い失敗通知 (dedup は通知台帳の (type, invoice_id))。
      * past_due 状態遷移・督促回数管理は派生アプリの拡張点。
diff --git a/app/Services/Billing/SubscriptionCheckoutGateway.php b/app/Services/Billing/SubscriptionCheckoutGateway.php
deleted file mode 100644
index 866a415..0000000
--- a/app/Services/Billing/SubscriptionCheckoutGateway.php
+++ /dev/null
@@ -1,33 +0,0 @@
-<?php
-
-declare(strict_types=1);
-
-namespace App\Services\Billing;
-
-use App\DataTransferObjects\Billing\ExternalBillingRedirect;
-use App\Models\Organization;
-
-/**
- * サブスクリプションの Stripe Checkout / Customer Portal 抽象
- * (実装: CashierSubscriptionCheckoutGateway。fake_externals 時は fake を bind)。
- * Stripe 呼び出しを本 interface に閉じ、Controller は戻り値 DTO の URL へ
- * Inertia::location するのみ。
- */
-interface SubscriptionCheckoutGateway
-{
-    /**
-     * subscription (type=default) の hosted Checkout Session を作り遷移先を返す。
-     */
-    public function createSubscriptionCheckout(
-        Organization $organization,
-        string $stripePriceId,
-        string $successUrl,
-        string $cancelUrl,
-    ): ExternalBillingRedirect;
-
-    /**
-     * Customer Portal セッションを作り遷移先を返す
-     * (configuration は PortalConfigurationSpec 準拠。実装側で解決する)。
-     */
-    public function portalRedirect(Organization $organization, string $returnUrl): ExternalBillingRedirect;
-}
diff --git a/app/Services/Billing/SubscriptionService.php b/app/Services/Billing/SubscriptionService.php
new file mode 100644
index 0000000..cc1341a
--- /dev/null
+++ b/app/Services/Billing/SubscriptionService.php
@@ -0,0 +1,281 @@
+<?php
+
+declare(strict_types=1);
+
+namespace App\Services\Billing;
+
+use App\DataTransferObjects\Billing\ExternalBillingRedirect;
+use App\DataTransferObjects\Billing\SubscriptionEntitlementDto;
+use App\Enums\Billing\EntitlementDeniedReason;
+use App\Enums\Billing\PlanPriceKind;
+use App\Enums\Billing\ScheduleSetupStatus;
+use App\Enums\Billing\SubscriptionState;
+use App\Enums\PlanCode;
+use App\Exceptions\Billing\StripePriceNotSyncedException;
+use App\Models\Billing\Plan;
+use App\Models\Billing\PlanPrice;
+use App\Models\Billing\Subscription;
+use App\Models\Organization;
+use App\Services\Billing\Contracts\StripeGatewayInterface;
+use Carbon\CarbonImmutable;
+use Illuminate\Support\Facades\DB;
+use Illuminate\Validation\ValidationException;
+use Webmozart\Assert\Assert;
+
+/**
+ * Subscription (契約) の状態管理サービス。
+ *
+ * Stripe への I/O は Gateway 経由のみで、本クラスは entitlement の導出・webhook 受信時の
+ * 状態同期・checkout の前処理に責務を絞る。
+ */
+class SubscriptionService
+{
+    /** organizations.plan_code を同期する subscription status (それ以外では既存値を維持する) */
+    private const array ACTIVE_SUBSCRIPTION_STATUSES = ['active', 'trialing'];
+
+    public function __construct(
+        private readonly StripeGatewayInterface $gateway,
+    ) {}
+
+    /**
+     * subscription の利用可否 (entitlement) を確定する **唯一の経路**。
+     *
+     * `SubscriptionState::fromSubscription`/`grantsAccess` を直接参照して可否を決めてはならない。
+     * 本メソッドが state + PM 有無 + trial_ends_at + Stripe status snapshot を合成して最終確定する。
+     *
+     *   entitled = state.grantsAccess()
+     *              AND NOT (trial_ends_at <= now AND !has_payment_method)   // trial 終了 & カード無し
+     *              AND status != paused                                     // Stripe 確定の read-only
+     *
+     * - Paused: grantsAccess=false で否定 (reason=Paused)。
+     * - trial 終了 & PM 無し: webhook 前 (Stripe がまだ paused 化していない) でも先回りで否定する
+     *   (reason=TrialEndedWithoutPaymentMethod)。
+     * - PastDue (PM 有): grantsAccess=true かつ trial 条件に該当しないため entitled=true (請求失敗中も利用継続)。
+     * - PM 無し past_due (= trial 後カード無し dunning): trial_ends_at<=now & !has_payment_method で否定。
+     */
+    public function deriveEntitlement(Subscription $sub): SubscriptionEntitlementDto
+    {
+        $state = SubscriptionState::fromSubscription($sub);
+
+        if (! $state->grantsAccess()) {
+            $reason = $state === SubscriptionState::Paused
+                ? EntitlementDeniedReason::Paused
+                : EntitlementDeniedReason::NoActiveSubscription;
+
+            return SubscriptionEntitlementDto::denied($state, $reason);
+        }
+
+        // trial 終了後カード未登録 → 利用不可 (webhook の paused 化前でも先回り遮断)。
+        $now = CarbonImmutable::now();
+        $trialEnded = $sub->trial_ends_at !== null
+            && CarbonImmutable::instance($sub->trial_ends_at)->lessThanOrEqualTo($now);
+        if ($trialEnded && ! $sub->has_payment_method) {
+            return SubscriptionEntitlementDto::denied(
+                $state,
+                EntitlementDeniedReason::TrialEndedWithoutPaymentMethod,
+            );
+        }
+
+        // status=paused は grantsAccess で既に弾かれているが、防御的に二重で確認する。
+        if ($sub->stripe_status === 'paused') {
+            return SubscriptionEntitlementDto::denied($state, EntitlementDeniedReason::Paused);
+        }
+
+        return SubscriptionEntitlementDto::granted($state);
+    }
+
+    /**
+     * Webhook (customer.subscription.created/updated/deleted) 受信時、Stripe サブスクの
+     * 最新スナップショットをローカル状態へ反映する **唯一の書込経路**。
+     *
+     * 列の所在差の吸収 (aigenba は subscriptions.plan_code に書くが、本アプリの権威は
+     * organizations.plan_code):
+     * - (a) base Price から plan が解決でき **かつ** status が active/trialing のときだけ
+     *   `organizations.plan_code` を同期する (未知 Price は受理のみ)。
+     * - (b) `subscriptions` 行が存在すれば lockForUpdate の上で Stripe 由来の列を更新する。
+     *   **行の作成は行わない** (作成の権威は Cashier の WebhookController。WebhookReceived は
+     *   Cashier のハンドラより先に発火するため created 時点では行が無いことがあり、ここで
+     *   先に作ると Cashier 側の subscription_items 生成が永久に skip される)。
+     * - (c) `$terminated` (customer.subscription.deleted) では `organizations.plan_code` を
+     *   null に戻し、schedule ライフサイクル列を同一トランザクションで明示クリアする
+     *   (「移行」ではなく「終了」。status だけ更新・schedule 残存の一時不整合を防ぐ)。
+     *
+     * seat drift / schedule out-of-band drift / period 巻き戻し guard は対象列
+     * (additional_seats / pending_plan_code / current_period_start) が無いため移植しない。
+     *
+     * @param  bool  $terminated  終了系 (deleted) のとき true。
+     */
+    public function applySubscriptionSnapshot(
+        Organization $org,
+        SubscriptionSnapshot $snap,
+        bool $terminated = false,
+    ): void {
+        DB::transaction(function () use ($org, $snap, $terminated): void {
+            $sub = Subscription::query()
+                ->where('stripe_id', $snap->stripeId)
+                ->lockForUpdate()
+                ->first();
+
+            if ($sub instanceof Subscription) {
+                $attrs = [
+                    'stripe_status' => $snap->status,
+                    'stripe_price' => $snap->basePriceId,
+                    'quantity' => $snap->baseQuantity,
+                    'trial_ends_at' => $snap->trialEndsAt,
+                    'ends_at' => $snap->endsAt,
+                ];
+
+                // period 欠落 payload では既存の current_period_end を維持する (renewal reminder の
+                // 真実源を null で塗り潰さない = 現行 syncSubscriptionPeriod の早期 return と同値)。
+                if ($snap->currentPeriodEnd !== null) {
+                    $attrs['current_period_end'] = $snap->currentPeriodEnd;
+                }
+
+                if ($terminated) {
+                    $attrs['stripe_schedule_id'] = null;
+                    $attrs['schedule_setup_status'] = ScheduleSetupStatus::None;
+                }
+
+                $sub->forceFill($attrs)->save();
+            }
+
+            if ($terminated) {
+                // plan_code は状態キー: webhook 同期でのみ明示代入する
+                $org->plan_code = null;
+                $org->save();
+
+                return;
+            }
+
+            $planCode = $this->resolvePlanCodeFromPriceId($snap->basePriceId);
+            if ($planCode === null || ! in_array($snap->status, self::ACTIVE_SUBSCRIPTION_STATUSES, true)) {
+                return; // 未知 Price / 非 active 系は受理のみ (既存 plan_code を維持)
+            }
+
+            $org->plan_code = $planCode->value;
+            $org->save();
+        });
+    }
+
+    /**
+     * has_payment_method を subscription に記録する **独立 monotonic writer**。
+     *
+     * `applySubscriptionSnapshot` の中に置かない理由: 早期 return 経路 (行不在等) と無関係に
+     * 「決済手段の有無」だけを独立した契約として書くため。
+     *
+     * - has_payment_method: monotonic (true から false に戻さない)。Stripe の payload は
+     *   default_payment_method を expand しない周期があり、false 側を信じると trial 終了後の
+     *   遮断判定 (deriveEntitlement) が誤発火するため。
+     * - 行不在 (Cashier の WebhookController が行を作る前の customer.subscription.created 等) は
+     *   早期 return で no-op。最初の権威 PM 書込は最初の customer.subscription.updated に載る。
+     */
+    public function recordPaymentMethodSnapshot(Subscription $sub, bool $hasPaymentMethod): void
+    {
+        DB::transaction(function () use ($sub, $hasPaymentMethod): void {
+            $fresh = Subscription::query()->lockForUpdate()->find($sub->id);
+            if (! $fresh instanceof Subscription) {
+                return;
+            }
+
+            // PM 有無 (monotonic: 一度 true になったら下げない)。
+            if ($hasPaymentMethod && ! $fresh->has_payment_method) {
+                $fresh->forceFill(['has_payment_method' => true])->save();
+            }
+        });
+    }
+
+    /**
+     * Stripe Checkout (サブスク契約) を開始し、遷移先 (hosted Checkout URL) を返す。
+     *
+     * checkout session の冪等状態機械 (attempt token / billing_checkout_sessions) は
+     * 本フェーズのスコープ外 (後続フェーズで本メソッドに配線する)。
+     *
+     * @throws StripePriceNotSyncedException production runtime で未 sync の Price のとき
+     * @throws ValidationException Stripe 決済対象外のプランのとき (422)
+     * @throws \InvalidArgumentException 既に有効なサブスクリプションがあるとき
+     */
+    public function startCheckout(
+        Organization $org,
+        PlanPrice $basePrice,
+        string $successUrl,
+        string $cancelUrl,
+    ): ExternalBillingRedirect {
+        // production runtime guard
+        $this->assertPriceSynced($basePrice);
+
+        $plan = $basePrice->plan;
+        Assert::isInstanceOf($plan, Plan::class);
+        $this->assertStripeBillablePlan($plan);
+
+        $existing = $org->subscription('default');
+        Assert::true(
+            ! $existing instanceof Subscription || ! $existing->valid(),
+            '既に有効なサブスクリプションがあります。プラン変更をご利用ください。'
+        );
+
+        return $this->gateway->createSubscriptionCheckout(
+            $org,
+            $basePrice->stripe_price_id,
+            $successUrl,
+            $cancelUrl,
+        );
+    }
+
+    /** Stripe Customer Portal セッション (支払い方法・解約の自己管理) の遷移先を返す。 */
+    public function createPortalSession(Organization $org, string $returnUrl): ExternalBillingRedirect
+    {
+        return $this->gateway->createPortalSession($org, $returnUrl);
+    }
+
+    /**
+     * Stripe Checkout の対象プランかを service 層で明示拒否する (validation 迂回対策)。
+     * Personal (free) / Enterprise / 未知 code は fail-closed で 422。
+     */
+    private function assertStripeBillablePlan(Plan $plan): void
+    {
+        $planCode = PlanCode::tryFrom($plan->code);
+        if ($planCode === null || ! $planCode->requiresStripeCheckout()) {
+            throw ValidationException::withMessages([
+                'plan_code' => 'このプランは Stripe 決済の対象外です。',
+            ]);
+        }
+    }
+
+    /**
+     * production runtime で未 sync の test mode Price を checkout に使う事故を防ぐ DB レベル guard。
+     */
+    private function assertPriceSynced(PlanPrice $price): void
+    {
+        if (! app()->environment('production')) {
+            return;
+        }
+        if (! $price->livemode || $price->synced_at === null) {
+            $lookupKey = $price->lookup_key ?? "plan_id={$price->plan_id}:kind={$price->kind}";
+            throw new StripePriceNotSyncedException($lookupKey);
+        }
+    }
+
+    /** base Price ID からプラン (PlanCode) を逆引きする。未知 Price は null。 */
+    private function resolvePlanCodeFromPriceId(?string $priceId): ?PlanCode
+    {
+        if ($priceId === null || $priceId === '') {
+            return null;
+        }
+
+        $row = PlanPrice::query()
+            ->where('stripe_price_id', $priceId)
+            ->where('kind', PlanPriceKind::Base->value)
+            ->first();
+
+        if (! $row instanceof PlanPrice) {
+            return null;
+        }
+
+        $plan = $row->plan;
+        if (! $plan instanceof Plan) {
+            return null;
+        }
+
+        return PlanCode::tryFrom($plan->code);
+    }
+}
diff --git a/app/Services/Billing/SubscriptionSnapshot.php b/app/Services/Billing/SubscriptionSnapshot.php
new file mode 100644
index 0000000..07d496d
--- /dev/null
+++ b/app/Services/Billing/SubscriptionSnapshot.php
@@ -0,0 +1,29 @@
+<?php
+
+declare(strict_types=1);
+
+namespace App\Services\Billing;
+
+use Carbon\CarbonImmutable;
+
+/**
+ * Stripe サブスクリプションの値オブジェクト。Webhook ハンドラから SubscriptionService に渡す。
+ *
+ * T666 (C2): schedule ライフサイクル状態 (`stripe_schedule_id` / `schedule_setup_status`) は
+ * ここに含めない。これらは Stripe subscription object に存在しない / 順序逆転 webhook で
+ * 破壊的なドメインローカル状態であり、書込権威は SubscriptionService の schedule lifecycle
+ * メソッド + ReconcileSubscriptionSchedules に限定する。汎用 webhook 同期
+ * (`applySubscriptionSnapshot`) はこれらを触らない。
+ */
+final readonly class SubscriptionSnapshot
+{
+    public function __construct(
+        public string $stripeId,
+        public string $status,
+        public ?string $basePriceId,
+        public ?int $baseQuantity,
+        public ?CarbonImmutable $currentPeriodEnd,
+        public ?CarbonImmutable $trialEndsAt,
+        public ?CarbonImmutable $endsAt,
+    ) {}
+}
diff --git a/database/factories/Billing/BillingCheckoutSessionFactory.php b/database/factories/Billing/BillingCheckoutSessionFactory.php
new file mode 100644
index 0000000..db8a93e
--- /dev/null
+++ b/database/factories/Billing/BillingCheckoutSessionFactory.php
@@ -0,0 +1,106 @@
+<?php
+
+declare(strict_types=1);
+
+namespace Database\Factories\Billing;
+
+use App\Enums\CheckoutIntent;
+use App\Enums\CheckoutSessionStatus;
+use App\Models\Billing\BillingCheckoutSession;
+use App\Models\Organization;
+use Carbon\CarbonImmutable;
+use Illuminate\Database\Eloquent\Factories\Factory;
+use Illuminate\Support\Str;
+
+/**
+ * 既定は live pending (契約待ち) のサブスク Checkout 追跡行。
+ *
+ * @extends Factory<BillingCheckoutSession>
+ */
+class BillingCheckoutSessionFactory extends Factory
+{
+    protected $model = BillingCheckoutSession::class;
+
+    /**
+     * @return array<string, mixed>
+     */
+    public function definition(): array
+    {
+        return [
+            'organization_id' => Organization::factory(),
+            // 既定は null (= 旧行相当)。resume/replay の user スコープを検証するテストは
+            // ->initiatedBy($userId) で明示する。
+            'initiated_by_user_id' => null,
+            'intent' => CheckoutIntent::SubscriptionStart->value,
+            'plan_code' => 'starter',
+            'stripe_session_id' => 'cs_'.Str::random(24),
+            'idempotency_key' => 'checkout:'.Str::uuid()->toString(),
+            'attempt_token' => null,
+            'checkout_url' => null,
+            'status' => CheckoutSessionStatus::Pending->value,
+            'completed_at' => null,
+        ];
+    }
+
+    /**
+     * attempt_token (契約 attempt 単位の冪等キー) を固定する。
+     * checkout_url が未指定なら Pending 再生用のダミー URL を併せて設定する。
+     */
+    public function withAttemptToken(string $token, ?string $checkoutUrl = 'https://checkout.stripe.com/dummy'): static
+    {
+        return $this->state(fn (): array => [
+            'attempt_token' => $token,
+            'checkout_url' => $checkoutUrl,
+        ]);
+    }
+
+    /** 購入意図を起こした user を固定する (resume/replay の user スコープ検証用)。 */
+    public function initiatedBy(int $userId): static
+    {
+        return $this->state(fn (): array => [
+            'initiated_by_user_id' => $userId,
+        ]);
+    }
+
+    public function completed(): static
+    {
+        return $this->state(fn (): array => [
+            'status' => CheckoutSessionStatus::Completed->value,
+            'completed_at' => CarbonImmutable::now(),
+        ]);
+    }
+
+    /** オートリチャージ用カード登録 (Checkout mode=setup) セッション。 */
+    public function setupPaymentMethod(): static
+    {
+        return $this->state(fn (): array => [
+            'intent' => CheckoutIntent::SetupPaymentMethod->value,
+            'plan_code' => null,
+        ]);
+    }
+
+    /** 明示 expire 済みの行。 */
+    public function expired(): static
+    {
+        return $this->state(fn (): array => [
+            'status' => CheckoutSessionStatus::Expired->value,
+        ]);
+    }
+
+    /** 決済失敗で終わった行。 */
+    public function failed(): static
+    {
+        return $this->state(fn (): array => [
+            'status' => CheckoutSessionStatus::Failed->value,
+        ]);
+    }
+
+    /** stale な pending (status は pending のまま created_at が stale 境界より過去) の行。 */
+    public function stale(): static
+    {
+        return $this->state(fn (): array => [
+            'status' => CheckoutSessionStatus::Pending->value,
+            'created_at' => CarbonImmutable::now()->subDays(2),
+        ]);
+    }
+}
diff --git a/database/migrations/2026_07_17_000200_create_billing_checkout_sessions_table.php b/database/migrations/2026_07_17_000200_create_billing_checkout_sessions_table.php
new file mode 100644
index 0000000..d0089e8
--- /dev/null
+++ b/database/migrations/2026_07_17_000200_create_billing_checkout_sessions_table.php
@@ -0,0 +1,53 @@
+<?php
+
+declare(strict_types=1);
+
+use Illuminate\Database\Migrations\Migration;
+use Illuminate\Database\Schema\Blueprint;
+use Illuminate\Support\Facades\Schema;
+
+/**
+ * サブスク契約 Checkout の追跡行 (`BillingAccess::state()` が PendingCheckout /
+ * ExpiredCheckout を読む状態モデルの一部)。
+ *
+ * - attempt_token: 画面 render ごとに固定される ULID。browser-back / 二重 submit で
+ *   同じ token が再送されても新規 Checkout を発行しない (= 二重課金防止)。
+ * - checkout_url: Pending 行の再生 (同じ Checkout に戻す) のために URL を保持する。
+ * - unique(organization_id, intent, attempt_token): 契約 attempt 単位の冪等を DB invariant で
+ *   最終保証する。複合 unique 内の NULL は重複許容のため、token を持たない行は抵触しない。
+ * - initiated_by_user_id: 購入意図を起こした user (nullable FK→users, nullOnDelete)。
+ *
+ * 席 (seats) / チケット枚数・単価 (credit_count・unit_amount。`ticket_checkout_sessions` が担う) /
+ * signup funding・campaign・trial 列は AI-CUE に対象機構が無いため列ごと非移植。
+ */
+return new class extends Migration
+{
+    public function up(): void
+    {
+        Schema::create('billing_checkout_sessions', function (Blueprint $table): void {
+            $table->id();
+            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
+            $table->foreignId('initiated_by_user_id')->nullable()->constrained('users')->nullOnDelete();
+            $table->string('intent', 32); // subscription_start|setup_payment_method
+            $table->string('plan_code', 32)->nullable();
+            $table->string('stripe_session_id')->unique();
+            $table->string('idempotency_key', 128)->unique();
+            $table->string('attempt_token')->nullable();
+            $table->string('checkout_url', 2048)->nullable();
+            $table->string('status', 16)->default('pending'); // pending|completed|failed|expired
+            $table->timestamp('completed_at')->nullable();
+            $table->timestamps();
+
+            $table->index(['organization_id', 'intent', 'status']);
+            $table->unique(
+                ['organization_id', 'intent', 'attempt_token'],
+                'billing_checkout_sessions_org_intent_attempt_unique',
+            );
+        });
+    }
+
+    public function down(): void
+    {
+        Schema::dropIfExists('billing_checkout_sessions');
+    }
+};
diff --git a/database/migrations/2026_07_17_000210_add_has_payment_method_to_subscriptions.php b/database/migrations/2026_07_17_000210_add_has_payment_method_to_subscriptions.php
new file mode 100644
index 0000000..70c9b09
--- /dev/null
+++ b/database/migrations/2026_07_17_000210_add_has_payment_method_to_subscriptions.php
@@ -0,0 +1,31 @@
+<?php
+
+declare(strict_types=1);
+
+use Illuminate\Database\Migrations\Migration;
+use Illuminate\Database\Schema\Blueprint;
+use Illuminate\Support\Facades\Schema;
+
+/**
+ * has_payment_method: PM 登録済みか (monotonic snapshot・true から false へ戻さない)。
+ *
+ * `SubscriptionService::deriveEntitlement` の入力。既定は false (移植元と同値) で、
+ * 既存行は分離した data migration (backfill_has_payment_method_on_subscriptions) が
+ * true へ倒す。
+ */
+return new class extends Migration
+{
+    public function up(): void
+    {
+        Schema::table('subscriptions', function (Blueprint $table): void {
+            $table->boolean('has_payment_method')->default(false)->after('trial_ends_at');
+        });
+    }
+
+    public function down(): void
+    {
+        Schema::table('subscriptions', function (Blueprint $table): void {
+            $table->dropColumn('has_payment_method');
+        });
+    }
+};
diff --git a/database/migrations/2026_07_17_000220_backfill_has_payment_method_on_subscriptions.php b/database/migrations/2026_07_17_000220_backfill_has_payment_method_on_subscriptions.php
new file mode 100644
index 0000000..acb04de
--- /dev/null
+++ b/database/migrations/2026_07_17_000220_backfill_has_payment_method_on_subscriptions.php
@@ -0,0 +1,33 @@
+<?php
+
+declare(strict_types=1);
+
+use Illuminate\Database\Migrations\Migration;
+use Illuminate\Support\Facades\DB;
+
+/**
+ * 既存 subscription 行の `has_payment_method` を true へ backfill する。
+ *
+ * 列既定の false は「trial 中カード無し signup」経路が存在する前提の値 (移植元の既定)。
+ * AI-CUE の subscription 生成経路は Checkout (mode=subscription) のみで PM 収集が必須のため、
+ * 既存行の事実値は true。これにより判定モデル置換 (deriveEntitlement) の
+ * 「trial 終了 & PM 無し = 遮断」に既存の有償組織が該当しない (デプロイ時点で該当 0 件)。
+ *
+ * `recordPaymentMethodSnapshot()` は monotonic (true→false に戻さない) のため backfill 値は
+ * 以後保存される。冪等 (where ガード)。down() は「どの行が migration 起因か」を識別できないため
+ * 意図的に no-op。
+ */
+return new class extends Migration
+{
+    public function up(): void
+    {
+        DB::table('subscriptions')
+            ->where('has_payment_method', false)
+            ->update(['has_payment_method' => true]);
+    }
+
+    public function down(): void
+    {
+        // backfill の巻き戻しは「どの行が migration 起因か」を識別できないため意図的に no-op。
+    }
+};
diff --git a/database/seeders/PermissionSeeder.php b/database/seeders/PermissionSeeder.php
index ee0cea6..3260b3c 100644
--- a/database/seeders/PermissionSeeder.php
+++ b/database/seeders/PermissionSeeder.php
@@ -6,6 +6,7 @@
 
 use App\Models\Permission;
 use App\Services\ApiKey\ApiKeyPermissionService;
+use App\Services\Billing\BillingPermissionService;
 use Illuminate\Database\Seeder;
 
 /**
@@ -31,9 +32,9 @@ public function run(): void
      * <!-- TEMPLATE-MARKER: アプリ固有の permission をここに追加する。
      *      例: ['name' => 'billing-manage', 'display_name' => '請求・プラン管理'] -->
      *
-     * `manage-api-keys` は Owner/Admin の既定境界の外にいる一般メンバーへ
-     * 個別付与するための permission ({@see ApiKeyPermissionService})。専用 Role には
-     * 紐付けない (flat 付与モデル) ため RolePermissionSeeder には登録しない。
+     * `manage-api-keys` / `manage-billing` は Owner/Admin の既定境界の外にいる一般メンバーへ
+     * 個別付与するための permission ({@see ApiKeyPermissionService} / {@see BillingPermissionService})。
+     * 専用 Role には紐付けない (flat 付与モデル) ため RolePermissionSeeder には登録しない。
      *
      * @return list<array{name: string, display_name: string}>
      */
@@ -41,6 +42,7 @@ protected function permissions(): array
     {
         return [
             ['name' => ApiKeyPermissionService::PERMISSION_MANAGE_API_KEYS, 'display_name' => 'API キー管理'],
+            ['name' => BillingPermissionService::PERMISSION_MANAGE_BILLING, 'display_name' => '請求・プラン管理'],
         ];
     }
 }
diff --git a/tests/Architecture/BillingSyncDispatchInvariantTest.php b/tests/Architecture/BillingSyncDispatchInvariantTest.php
new file mode 100644
index 0000000..38ddc0b
--- /dev/null
+++ b/tests/Architecture/BillingSyncDispatchInvariantTest.php
@@ -0,0 +1,38 @@
+<?php
+
+declare(strict_types=1);
+
+use Symfony\Component\Finder\Finder;
+
+/*
+|--------------------------------------------------------------------------
+| Stripe customer 同期 job の dispatch 経路 invariant
+|--------------------------------------------------------------------------
+|
+| SyncBillingCustomerDetails の dispatch は BillingCustomerSynchronizer 1 経路に閉じる (IV-2)。
+| 窓口を単一化することで「必ず transaction 内から afterCommit で発火する」(IV-3) /
+| 「stripe_id 未作成は no-op」(IV-4) の契約が構造的に守られる。webhook ハンドラがこの経路を
+| 通らないことが Stripe→アプリ→Stripe の同期ループを構造的に防いでいる。
+*/
+
+test('app/ 内の SyncBillingCustomerDetails::dispatch は BillingCustomerSynchronizer に閉じる', function (): void {
+    $allowlist = [
+        'app/Services/Billing/BillingCustomerSynchronizer.php',
+    ];
+
+    $finder = Finder::create()
+        ->in(base_path('app'))
+        ->files()
+        ->name('*.php')
+        ->contains('/SyncBillingCustomerDetails::dispatch/');
+
+    $violations = [];
+    foreach ($finder as $file) {
+        $relative = str_replace(base_path().'/', '', (string) $file->getRealPath());
+        if (! in_array($relative, $allowlist, true)) {
+            $violations[] = $relative;
+        }
+    }
+
+    expect($violations)->toBe([], 'SyncBillingCustomerDetails の dispatch は BillingCustomerSynchronizer 経由に限定してください: '.implode(', ', $violations));
+});
diff --git a/tests/Feature/Billing/BillingAccessStateTest.php b/tests/Feature/Billing/BillingAccessStateTest.php
new file mode 100644
index 0000000..a379015
--- /dev/null
+++ b/tests/Feature/Billing/BillingAccessStateTest.php
@@ -0,0 +1,289 @@
+<?php
+
+declare(strict_types=1);
+
+use App\Enums\Billing\OnboardingBillingState;
+use App\Models\Billing\BillingCheckoutSession;
+use App\Models\Billing\Subscription;
+use App\Models\Organization;
+use App\Models\User;
+use App\Services\Billing\BillingAccess;
+use Carbon\CarbonImmutable;
+
+/*
+ * P2 判定モデル (aigenba verbatim 移植) の cohort 表 A〜I を固定する。
+ *
+ * state() は plan_code を一切見ない。hasActiveAccess() は state()->grantsAccess() に
+ * 移行 OR (plan_code === null。P4 で削除) を足したもの。
+ *
+ * 現行からの反転 (P2 の成果物・挙動不変を主張しない):
+ * - cohort C (active/trialing + trial 終了 + PM 無): 許可 → **遮断**
+ * - cohort D (past_due + PM 有):                    遮断 → **許可**
+ */
+
+/**
+ * cohort 固定用の subscription 行 (Stripe には到達しない)。
+ * `has_payment_method` / `trial_ends_at` は列既定を上書きして事実値を明示する。
+ */
+function cohortSubscription(
+    Organization $organization,
+    string $status = 'active',
+    bool $hasPaymentMethod = true,
+    ?CarbonImmutable $trialEndsAt = null,
+): Subscription {
+    $subscription = createFakeSubscription($organization, status: $status);
+    $subscription->forceFill([
+        'has_payment_method' => $hasPaymentMethod,
+        'trial_ends_at' => $trialEndsAt,
+    ])->save();
+
+    return $subscription;
+}
+
+/** 有償プラン契約中 (plan_code 非 null) の組織。 */
+function cohortPaidOrganization(): Organization
+{
+    $organization = Organization::factory()->create();
+    $organization->forceFill(['plan_code' => 'standard'])->save();
+
+    return $organization;
+}
+
+function cohortBillingAccess(): BillingAccess
+{
+    return app(BillingAccess::class);
+}
+
+test('cohort A: active/trialing で trial 未設定なら Subscribed + 許可', function (string $status): void {
+    $organization = cohortPaidOrganization();
+    cohortSubscription($organization, status: $status, hasPaymentMethod: false, trialEndsAt: null);
+
+    expect(cohortBillingAccess()->state($organization))->toBe(OnboardingBillingState::Subscribed)
+        ->and(cohortBillingAccess()->hasActiveAccess($organization))->toBeTrue();
+})->with(['active', 'trialing']);
+
+test('cohort A: trial_ends_at が未来なら PM 無しでも Subscribed + 許可', function (): void {
+    $organization = cohortPaidOrganization();
+    cohortSubscription(
+        $organization,
+        status: 'trialing',
+        hasPaymentMethod: false,
+        trialEndsAt: CarbonImmutable::now()->addDay(),
+    );
+
+    expect(cohortBillingAccess()->state($organization))->toBe(OnboardingBillingState::Subscribed)
+        ->and(cohortBillingAccess()->hasActiveAccess($organization))->toBeTrue();
+});
+
+test('cohort B: trial 終了 + PM 有りは Subscribed + 許可', function (string $status): void {
+    $organization = cohortPaidOrganization();
+    cohortSubscription(
+        $organization,
+        status: $status,
+        hasPaymentMethod: true,
+        trialEndsAt: CarbonImmutable::now()->subDay(),
+    );
+
+    expect(cohortBillingAccess()->state($organization))->toBe(OnboardingBillingState::Subscribed)
+        ->and(cohortBillingAccess()->hasActiveAccess($organization))->toBeTrue();
+})->with(['active', 'trialing']);
+
+test('cohort C: trial 終了 + PM 無しは ExpiredCheckout + 遮断 (P2 で反転する側)', function (string $status): void {
+    $organization = cohortPaidOrganization();
+    cohortSubscription(
+        $organization,
+        status: $status,
+        hasPaymentMethod: false,
+        trialEndsAt: CarbonImmutable::now()->subDay(),
+    );
+
+    expect(cohortBillingAccess()->state($organization))->toBe(OnboardingBillingState::ExpiredCheckout)
+        ->and(cohortBillingAccess()->hasActiveAccess($organization))->toBeFalse();
+})->with(['active', 'trialing']);
+
+test('cohort C 境界: trial_ends_at ちょうど now + PM 無しは遮断 (<= 判定)', function (): void {
+    $this->travelTo(CarbonImmutable::parse('2026-07-17 00:00:00'));
+    $organization = cohortPaidOrganization();
+    cohortSubscription(
+        $organization,
+        status: 'active',
+        hasPaymentMethod: false,
+        trialEndsAt: CarbonImmutable::now(),
+    );
+
+    expect(cohortBillingAccess()->state($organization))->toBe(OnboardingBillingState::ExpiredCheckout)
+        ->and(cohortBillingAccess()->hasActiveAccess($organization))->toBeFalse();
+});
+
+test('cohort D: past_due + PM 有りは Subscribed + 許可 (P2 で反転する側)', function (): void {
+    $organization = cohortPaidOrganization();
+    cohortSubscription(
+        $organization,
+        status: 'past_due',
+        hasPaymentMethod: true,
+        trialEndsAt: CarbonImmutable::now()->subDay(),
+    );
+
+    expect(cohortBillingAccess()->state($organization))->toBe(OnboardingBillingState::Subscribed)
+        ->and(cohortBillingAccess()->hasActiveAccess($organization))->toBeTrue();
+});
+
+test('cohort D: past_due + trial 未終了は PM 無しでも Subscribed + 許可', function (): void {
+    $organization = cohortPaidOrganization();
+    cohortSubscription($organization, status: 'past_due', hasPaymentMethod: false, trialEndsAt: null);
+
+    expect(cohortBillingAccess()->state($organization))->toBe(OnboardingBillingState::Subscribed)
+        ->and(cohortBillingAccess()->hasActiveAccess($organization))->toBeTrue();
+});
+
+test('cohort E: past_due + trial 終了 + PM 無しは ExpiredCheckout + 遮断', function (): void {
+    $organization = cohortPaidOrganization();
+    cohortSubscription(
+        $organization,
+        status: 'past_due',
+        hasPaymentMethod: false,
+        trialEndsAt: CarbonImmutable::now()->subDay(),
+    );
+
+    expect(cohortBillingAccess()->state($organization))->toBe(OnboardingBillingState::ExpiredCheckout)
+        ->and(cohortBillingAccess()->hasActiveAccess($organization))->toBeFalse();
+});
+
+test('cohort F: paused は PM 有りでも ExpiredCheckout + 遮断', function (): void {
+    $organization = cohortPaidOrganization();
+    cohortSubscription($organization, status: 'paused', hasPaymentMethod: true, trialEndsAt: null);
+
+    expect(cohortBillingAccess()->state($organization))->toBe(OnboardingBillingState::ExpiredCheckout)
+        ->and(cohortBillingAccess()->hasActiveAccess($organization))->toBeFalse();
+});
+
+test('cohort G: 非 active 系 status は ExpiredCheckout + 遮断', function (string $status): void {
+    $organization = cohortPaidOrganization();
+    cohortSubscription($organization, status: $status, hasPaymentMethod: true, trialEndsAt: null);
+
+    expect(cohortBillingAccess()->state($organization))->toBe(OnboardingBillingState::ExpiredCheckout)
+        ->and(cohortBillingAccess()->hasActiveAccess($organization))->toBeFalse();
+})->with(['canceled', 'unpaid', 'incomplete', 'incomplete_expired']);
+
+test('cohort F/G: state() は plan_code を見ない (null でも同じ state。許可は移行 OR 由来)', function (string $status): void {
+    $organization = Organization::factory()->create(); // plan_code null
+    cohortSubscription($organization, status: $status, hasPaymentMethod: true, trialEndsAt: null);
+
+    expect($organization->plan_code)->toBeNull()
+        ->and(cohortBillingAccess()->state($organization))->toBe(OnboardingBillingState::ExpiredCheckout)
+        // 移行 OR (P4 で削除) が cohort I として許可する
+        ->and(cohortBillingAccess()->hasActiveAccess($organization))->toBeTrue();
+})->with(['paused', 'canceled', 'unpaid', 'incomplete', 'incomplete_expired']);
+
+test('cohort H: subscription 行なし + checkout session なしは NoSubscription + 遮断', function (): void {
+    $organization = cohortPaidOrganization();
+
+    expect(cohortBillingAccess()->state($organization))->toBe(OnboardingBillingState::NoSubscription)
+        ->and(cohortBillingAccess()->hasActiveAccess($organization))->toBeFalse();
+});
+
+test('cohort I: plan_code null は state が遮断側でも移行 OR で許可される', function (): void {
+    $organization = Organization::factory()->create();
+
+    expect(cohortBillingAccess()->state($organization))->toBe(OnboardingBillingState::NoSubscription)
+        ->and(cohortBillingAccess()->hasActiveAccess($organization))->toBeTrue();
+});
+
+test('free_plan_code=personal は ActiveFreePlan + 許可 (declarer 有り)', function (): void {
+    $declarer = User::factory()->create();
+    $organization = Organization::factory()->freePersonal($declarer)->create();
+
+    expect(cohortBillingAccess()->state($organization))->toBe(OnboardingBillingState::ActiveFreePlan)
+        ->and(cohortBillingAccess()->hasActiveAccess($organization))->toBeTrue();
+});
+
+test('free_plan_code=personal は ActiveFreePlan + 許可 (declarer 無し)', function (): void {
+    $organization = Organization::factory()->grandfathered()->create();
+
+    expect(cohortBillingAccess()->state($organization))->toBe(OnboardingBillingState::ActiveFreePlan)
+        ->and(cohortBillingAccess()->hasActiveAccess($organization))->toBeTrue();
+});
+
+test('entitled な subscription は free_plan_code より優先される (Subscribed)', function (): void {
+    $organization = Organization::factory()->grandfathered()->create();
+    cohortSubscription($organization, status: 'active');
+
+    expect(cohortBillingAccess()->state($organization))->toBe(OnboardingBillingState::Subscribed);
+});
+
+test('entitled でない subscription があると free_plan_code が ActiveFreePlan を成立させる (paid→free)', function (): void {
+    $organization = Organization::factory()->grandfathered()->create();
+    cohortSubscription($organization, status: 'canceled');
+
+    expect(cohortBillingAccess()->state($organization))->toBe(OnboardingBillingState::ActiveFreePlan)
+        ->and(cohortBillingAccess()->hasActiveAccess($organization))->toBeTrue();
+});
+
+test('live pending checkout (created_at が stale 境界ちょうど) は PendingCheckout', function (): void {
+    $this->travelTo(CarbonImmutable::parse('2026-07-17 00:00:00'));
+    $organization = cohortPaidOrganization();
+    BillingCheckoutSession::factory()->create([
+        'organization_id' => $organization->getKey(),
+        'created_at' => BillingAccess::staleThresholdAt(CarbonImmutable::now()),
+    ]);
+
+    expect(cohortBillingAccess()->state($organization))->toBe(OnboardingBillingState::PendingCheckout)
+        ->and(cohortBillingAccess()->hasActiveAccess($organization))->toBeFalse();
+});
+
+test('stale pending checkout (境界の 1 秒前) は ExpiredCheckout', function (): void {
+    $this->travelTo(CarbonImmutable::parse('2026-07-17 00:00:00'));
+    $organization = cohortPaidOrganization();
+    BillingCheckoutSession::factory()->create([
+        'organization_id' => $organization->getKey(),
+        'created_at' => BillingAccess::staleThresholdAt(CarbonImmutable::now())->subSecond(),
+    ]);
+
+    expect(cohortBillingAccess()->state($organization))->toBe(OnboardingBillingState::ExpiredCheckout);
+});
+
+test('live pending が 1 件でもあれば stale pending があっても PendingCheckout', function (): void {
+    $this->travelTo(CarbonImmutable::parse('2026-07-17 00:00:00'));
+    $organization = cohortPaidOrganization();
+    BillingCheckoutSession::factory()->stale()->create(['organization_id' => $organization->getKey()]);
+    BillingCheckoutSession::factory()->create(['organization_id' => $organization->getKey()]);
+
+    expect(cohortBillingAccess()->state($organization))->toBe(OnboardingBillingState::PendingCheckout);
+});
+
+test('expired / failed の checkout session は ExpiredCheckout', function (string $state): void {
+    $organization = cohortPaidOrganization();
+    BillingCheckoutSession::factory()->{$state}()->create(['organization_id' => $organization->getKey()]);
+
+    expect(cohortBillingAccess()->state($organization))->toBe(OnboardingBillingState::ExpiredCheckout);
+})->with(['expired', 'failed']);
+
+test('completed のみの checkout session は NoSubscription (expired 扱いにしない)', function (): void {
+    $organization = cohortPaidOrganization();
+    BillingCheckoutSession::factory()->completed()->create(['organization_id' => $organization->getKey()]);
+
+    expect(cohortBillingAccess()->state($organization))->toBe(OnboardingBillingState::NoSubscription);
+});
+
+test('checkout session は他組織の行を読まない', function (): void {
+    $organization = cohortPaidOrganization();
+    BillingCheckoutSession::factory()->create(); // 別組織の live pending
+
+    expect(cohortBillingAccess()->state($organization))->toBe(OnboardingBillingState::NoSubscription);
+});
+
+test('state() は読み取り経路で DB を書き換えない (stale pending の expire は sweeper 責務)', function (): void {
+    $organization = cohortPaidOrganization();
+    $session = BillingCheckoutSession::factory()->stale()->create([
+        'organization_id' => $organization->getKey(),
+    ]);
+    $before = $session->fresh();
+    expect($before)->not->toBeNull();
+
+    expect(cohortBillingAccess()->state($organization))->toBe(OnboardingBillingState::ExpiredCheckout);
+
+    $after = $session->fresh();
+    expect($after)->not->toBeNull()
+        ->and($after->status)->toBe($before->status)
+        ->and($after->updated_at?->toIso8601String())->toBe($before->updated_at?->toIso8601String());
+});
diff --git a/tests/Feature/Billing/BillingCheckoutSessionModelTest.php b/tests/Feature/Billing/BillingCheckoutSessionModelTest.php
new file mode 100644
index 0000000..cc3444f
--- /dev/null
+++ b/tests/Feature/Billing/BillingCheckoutSessionModelTest.php
@@ -0,0 +1,131 @@
+<?php
+
+declare(strict_types=1);
+
+use App\Enums\CheckoutIntent;
+use App\Enums\CheckoutSessionStatus;
+use App\Models\Billing\BillingCheckoutSession;
+use App\Models\Organization;
+use App\Models\User;
+use Illuminate\Database\QueryException;
+
+/*
+ * BillingCheckoutSession (state() の PendingCheckout / ExpiredCheckout の真実源) の
+ * 述語と DB 制約を固定する。
+ */
+
+test('factory 既定は subscription_start の live pending 行', function (): void {
+    $session = BillingCheckoutSession::factory()->create();
+
+    expect($session->intentEnum())->toBe(CheckoutIntent::SubscriptionStart)
+        ->and($session->statusEnum())->toBe(CheckoutSessionStatus::Pending)
+        ->and($session->plan_code)->toBe('starter')
+        ->and($session->completed_at)->toBeNull();
+});
+
+test('setupPaymentMethod state は intent=setup_payment_method / plan_code なし', function (): void {
+    $session = BillingCheckoutSession::factory()->setupPaymentMethod()->create();
+
+    expect($session->intentEnum())->toBe(CheckoutIntent::SetupPaymentMethod)
+        ->and($session->plan_code)->toBeNull();
+});
+
+test('completed / expired / failed state が statusEnum に反映される', function (string $state, CheckoutSessionStatus $expected): void {
+    $session = BillingCheckoutSession::factory()->{$state}()->create();
+
+    expect($session->statusEnum())->toBe($expected);
+})->with([
+    ['completed', CheckoutSessionStatus::Completed],
+    ['expired', CheckoutSessionStatus::Expired],
+    ['failed', CheckoutSessionStatus::Failed],
+]);
+
+test('isReplayablePending は pending かつ checkout_url が生存しているときだけ true', function (): void {
+    $replayable = BillingCheckoutSession::factory()->withAttemptToken('token-live')->create();
+
+    expect($replayable->isReplayablePending())->toBeTrue();
+});
+
+test('isReplayablePending は checkout_url が null / 空なら false', function (?string $url): void {
+    $session = BillingCheckoutSession::factory()->create(['checkout_url' => $url]);
+
+    expect($session->isReplayablePending())->toBeFalse();
+})->with([null, '']);
+
+test('isReplayablePending は pending 以外なら checkout_url があっても false', function (string $state): void {
+    $session = BillingCheckoutSession::factory()
+        ->withAttemptToken('token-'.$state)
+        ->{$state}()
+        ->create();
+
+    expect($session->isReplayablePending())->toBeFalse();
+})->with(['completed', 'expired', 'failed']);
+
+test('initiatedBy / organization の関連が引ける', function (): void {
+    $user = User::factory()->create();
+    $organization = Organization::factory()->create();
+    $session = BillingCheckoutSession::factory()
+        ->initiatedBy($user->getKey())
+        ->create(['organization_id' => $organization->getKey()]);
+
+    expect($session->initiated_by_user_id)->toBe($user->getKey())
+        ->and($session->organization->getKey())->toBe($organization->getKey());
+});
+
+test('stripe_session_id は unique', function (): void {
+    BillingCheckoutSession::factory()->create(['stripe_session_id' => 'cs_dup']);
+
+    expect(fn () => BillingCheckoutSession::factory()->create(['stripe_session_id' => 'cs_dup']))
+        ->toThrow(QueryException::class);
+});
+
+test('idempotency_key は unique', function (): void {
+    BillingCheckoutSession::factory()->create(['idempotency_key' => 'checkout:dup']);
+
+    expect(fn () => BillingCheckoutSession::factory()->create(['idempotency_key' => 'checkout:dup']))
+        ->toThrow(QueryException::class);
+});
+
+test('(organization_id, intent, attempt_token) は unique', function (): void {
+    $organization = Organization::factory()->create();
+    BillingCheckoutSession::factory()
+        ->withAttemptToken('attempt-1')
+        ->create(['organization_id' => $organization->getKey()]);
+
+    expect(fn () => BillingCheckoutSession::factory()
+        ->withAttemptToken('attempt-1')
+        ->create(['organization_id' => $organization->getKey()]))
+        ->toThrow(QueryException::class);
+});
+
+test('attempt_token が同値でも intent が違えば衝突しない', function (): void {
+    $organization = Organization::factory()->create();
+    BillingCheckoutSession::factory()
+        ->withAttemptToken('attempt-1')
+        ->create(['organization_id' => $organization->getKey()]);
+
+    $other = BillingCheckoutSession::factory()
+        ->setupPaymentMethod()
+        ->withAttemptToken('attempt-1')
+        ->create(['organization_id' => $organization->getKey()]);
+
+    expect($other->intentEnum())->toBe(CheckoutIntent::SetupPaymentMethod);
+});
+
+test('attempt_token が NULL の行は複数あってもよい (複合 unique の NULL 重複許容)', function (): void {
+    $organization = Organization::factory()->create();
+    BillingCheckoutSession::factory()->count(2)->create([
+        'organization_id' => $organization->getKey(),
+    ]);
+
+    expect(BillingCheckoutSession::query()->where('organization_id', $organization->getKey())->count())
+        ->toBe(2);
+});
+
+test('tenant / actor キーは mass-assign できない (明示代入のみ)', function (): void {
+    $session = new BillingCheckoutSession;
+
+    expect($session->getFillable())
+        ->not->toContain('organization_id')
+        ->not->toContain('initiated_by_user_id');
+});
diff --git a/tests/Feature/Billing/BillingCustomerSynchronizerTest.php b/tests/Feature/Billing/BillingCustomerSynchronizerTest.php
new file mode 100644
index 0000000..6ac59a1
--- /dev/null
+++ b/tests/Feature/Billing/BillingCustomerSynchronizerTest.php
@@ -0,0 +1,72 @@
+<?php
+
+declare(strict_types=1);
+
+use App\Jobs\Billing\SyncBillingCustomerDetails;
+use App\Services\Billing\BillingCustomerSynchronizer;
+use App\Services\Billing\Contracts\StripeGatewayInterface;
+use App\Services\Billing\Fakes\FakeStripeGateway;
+use Illuminate\Support\Facades\DB;
+use Illuminate\Support\Facades\Queue;
+
+/*
+ * BillingCustomerSynchronizer: Stripe customer 同期 job の dispatch を集約する単一窓口。
+ * - Stripe customer 未作成 (stripe_id === null) は no-op (例外にしない)
+ * - dispatch は afterCommit (transaction rollback では発火しない)
+ */
+
+function synchronizer(): BillingCustomerSynchronizer
+{
+    return app(BillingCustomerSynchronizer::class);
+}
+
+test('stripe_id が null の組織では job を dispatch しない (no-op)', function (): void {
+    Queue::fake();
+    [$organization] = createOrganizationWithOwner();
+    expect($organization->stripe_id)->toBeNull();
+
+    DB::transaction(fn () => synchronizer()->dispatchFor($organization));
+
+    Queue::assertNothingPushed();
+});
+
+test('stripe_id を持つ組織では SyncBillingCustomerDetails を対象組織付きで dispatch する', function (): void {
+    Queue::fake();
+    [$organization] = createOrganizationWithOwner();
+    $organization->forceFill(['stripe_id' => 'cus_test_1'])->save();
+
+    DB::transaction(fn () => synchronizer()->dispatchFor($organization));
+
+    Queue::assertPushed(
+        SyncBillingCustomerDetails::class,
+        fn (SyncBillingCustomerDetails $job): bool => $job->organization->is($organization),
+    );
+});
+
+/*
+ * IV-3 (commit 前の stale read を防ぐ) の固定。job が afterCommit フラグを立てて積まれることを
+ * 検証する。「rollback では発火しない」という実挙動そのものは Queue::fake では観測できない
+ * (QueueFake は afterCommit を解決する Queue::enqueueUsing を経由せず即時記録するため)。
+ * afterCommit フラグ = 実 queue driver における「outer commit 後に発火」の唯一の入力。
+ */
+test('dispatch した job は afterCommit フラグを持つ (outer commit 後に発火する)', function (): void {
+    Queue::fake();
+    [$organization] = createOrganizationWithOwner();
+    $organization->forceFill(['stripe_id' => 'cus_test_2'])->save();
+
+    DB::transaction(fn () => synchronizer()->dispatchFor($organization));
+
+    Queue::assertPushed(
+        SyncBillingCustomerDetails::class,
+        fn (SyncBillingCustomerDetails $job): bool => $job->afterCommit === true,
+    );
+});
+
+test('job は StripeGatewayInterface へ委譲する (fake bind 時は実 Stripe を叩かない)', function (): void {
+    [$organization] = createOrganizationWithOwner();
+    $organization->forceFill(['stripe_id' => 'cus_test_3'])->save();
+    $this->app->bind(StripeGatewayInterface::class, FakeStripeGateway::class);
+
+    // fake gateway の syncCustomerDetails は no-op。例外なく完走することを固定する
+    (new SyncBillingCustomerDetails($organization))->handle(app(StripeGatewayInterface::class));
+})->throwsNoExceptions();
diff --git a/tests/Feature/Billing/BillingPageTest.php b/tests/Feature/Billing/BillingPageTest.php
index e8b04b0..0cf5b8f 100644
--- a/tests/Feature/Billing/BillingPageTest.php
+++ b/tests/Feature/Billing/BillingPageTest.php
@@ -3,8 +3,8 @@
 declare(strict_types=1);
 
 use App\Models\User;
-use App\Services\Billing\Fakes\FakeSubscriptionCheckoutGateway;
-use App\Services\Billing\SubscriptionCheckoutGateway;
+use App\Services\Billing\Contracts\StripeGatewayInterface;
+use App\Services\Billing\Fakes\FakeStripeGateway;
 use App\Services\Billing\TicketLedgerService;
 use Inertia\Testing\AssertableInertia as Assert;
 
@@ -98,7 +98,7 @@
 
 test('owner の checkout は fake gateway 経由で中立帰還 URL へ遷移する (happy path)', function (): void {
     [, $owner] = createOrganizationWithOwner();
-    $this->app->bind(SubscriptionCheckoutGateway::class, FakeSubscriptionCheckoutGateway::class);
+    $this->app->bind(StripeGatewayInterface::class, FakeStripeGateway::class);
 
     $response = $this->actingAs($owner)->post('/billing/checkout', ['plan_code' => 'standard']);
 
@@ -111,7 +111,7 @@
 
 test('owner の portal は fake gateway 経由で中立帰還 URL へ遷移する (happy path)', function (): void {
     [, $owner] = createOrganizationWithOwner();
-    $this->app->bind(SubscriptionCheckoutGateway::class, FakeSubscriptionCheckoutGateway::class);
+    $this->app->bind(StripeGatewayInterface::class, FakeStripeGateway::class);
 
     $response = $this->actingAs($owner)->post('/billing/portal');
 
diff --git a/tests/Feature/Billing/BillingPermissionServiceTest.php b/tests/Feature/Billing/BillingPermissionServiceTest.php
new file mode 100644
index 0000000..32be20d
--- /dev/null
+++ b/tests/Feature/Billing/BillingPermissionServiceTest.php
@@ -0,0 +1,115 @@
+<?php
+
+declare(strict_types=1);
+
+use App\Enums\OrganizationRole;
+use App\Models\User;
+use App\Services\Billing\BillingPermissionService;
+use Illuminate\Support\Facades\Gate;
+
+/*
+ * BillingPermissionService: Owner/Admin の既定境界の外にいる一般メンバーへ
+ * `manage-billing` を個別付与できること、および OrganizationPolicy::manageBilling が
+ * 直接付与を OR で認めることを固定する (付与 UI / route は本フェーズのスコープ外)。
+ */
+
+function billingPermissionService(): BillingPermissionService
+{
+    return app(BillingPermissionService::class);
+}
+
+test('一般メンバーへ付与すると hasDirectPermission が true になり policy も許可する', function (): void {
+    [$organization] = createOrganizationWithOwner();
+    $member = attachOrganizationMember($organization);
+    $service = billingPermissionService();
+
+    expect($service->hasDirectPermission($member, $organization))->toBeFalse();
+    expect(Gate::forUser($member)->allows('manageBilling', $organization))->toBeFalse();
+
+    $service->grant($member, $organization);
+
+    expect($service->hasDirectPermission($member->fresh(), $organization))->toBeTrue();
+    expect(Gate::forUser($member->fresh())->allows('manageBilling', $organization))->toBeTrue();
+});
+
+test('revoke で直接付与を剥奪でき、policy も再び拒否する', function (): void {
+    [$organization] = createOrganizationWithOwner();
+    $member = attachOrganizationMember($organization);
+    $service = billingPermissionService();
+
+    $service->grant($member, $organization);
+    expect($service->hasDirectPermission($member->fresh(), $organization))->toBeTrue();
+
+    $service->revoke($member, $organization);
+
+    expect($service->hasDirectPermission($member->fresh(), $organization))->toBeFalse();
+    expect(Gate::forUser($member->fresh())->allows('manageBilling', $organization))->toBeFalse();
+});
+
+test('非メンバーへの付与は DomainException', function (): void {
+    [$organization] = createOrganizationWithOwner();
+    $stranger = User::factory()->create();
+
+    billingPermissionService()->grant($stranger, $organization);
+})->throws(DomainException::class);
+
+test('hasDirectPermission は非メンバー (退会後の残存) を安全側 false にする', function (): void {
+    [$organization] = createOrganizationWithOwner();
+    $member = attachOrganizationMember($organization);
+    $service = billingPermissionService();
+    $service->grant($member, $organization);
+
+    // 退会 (membership 剥奪) 後は permission 行が残っていても false
+    $organization->users()->detach($member->id);
+
+    expect($service->hasDirectPermission($member->fresh(), $organization))->toBeFalse();
+    expect(Gate::forUser($member->fresh())->allows('manageBilling', $organization))->toBeFalse();
+});
+
+test('直接付与ゼロなら manageBilling の結論は現行 (owner / admin のみ) と同一', function (): void {
+    [$organization, $owner] = createOrganizationWithOwner();
+    $admin = attachOrganizationMember($organization, OrganizationRole::Admin);
+    $member = attachOrganizationMember($organization);
+    $service = billingPermissionService();
+
+    expect(Gate::forUser($owner)->allows('manageBilling', $organization))->toBeTrue();
+    expect(Gate::forUser($admin)->allows('manageBilling', $organization))->toBeTrue();
+    expect(Gate::forUser($member)->allows('manageBilling', $organization))->toBeFalse();
+    // 既定境界はロール由来であり「直接付与」ではない
+    expect($service->hasDirectPermission($owner, $organization))->toBeFalse();
+    expect($service->hasDirectPermission($admin, $organization))->toBeFalse();
+});
+
+test('直接付与された一般メンバーは /billing/portal が 403 にならない', function (): void {
+    [$organization] = createOrganizationWithOwner();
+    $member = attachOrganizationMember($organization);
+    billingPermissionService()->grant($member, $organization);
+
+    $fresh = $member->fresh();
+    $fresh->forceFill(['current_organization_id' => $organization->id])->save();
+
+    // Gate 境界の検証 (Stripe は叩かない)。付与前は 403 になる route。
+    expect(Gate::forUser($fresh)->allows('manageBilling', $organization))->toBeTrue();
+});
+
+test('getDirectManageBillingMap は指定メンバーの直接付与状態を 1 マップで返す', function (): void {
+    [$organization] = createOrganizationWithOwner();
+    $granted = attachOrganizationMember($organization);
+    $plain = attachOrganizationMember($organization);
+    $service = billingPermissionService();
+
+    $service->grant($granted, $organization);
+
+    $map = $service->getDirectManageBillingMap($organization, [$granted->id, $plain->id]);
+
+    expect($map)->toBe([
+        $granted->id => true,
+        $plain->id => false,
+    ]);
+});
+
+test('getDirectManageBillingMap は空配列を渡すと空を返す (クエリを撃たない)', function (): void {
+    [$organization] = createOrganizationWithOwner();
+
+    expect(billingPermissionService()->getDirectManageBillingMap($organization, []))->toBe([]);
+});
diff --git a/tests/Feature/Billing/HasPaymentMethodBackfillMigrationTest.php b/tests/Feature/Billing/HasPaymentMethodBackfillMigrationTest.php
new file mode 100644
index 0000000..a635177
--- /dev/null
+++ b/tests/Feature/Billing/HasPaymentMethodBackfillMigrationTest.php
@@ -0,0 +1,78 @@
+<?php
+
+declare(strict_types=1);
+
+use App\Models\Organization;
+use App\Services\Billing\BillingAccess;
+use Carbon\CarbonImmutable;
+use Illuminate\Database\Migrations\Migration;
+use Illuminate\Support\Facades\DB;
+
+/*
+ * cohort C の移行安全性: 列既定 false のまま既存行を残すと「trial 終了 + PM 無し」で
+ * 締め出される。列追加と分離した data migration が既存行を true へ倒すことで、
+ * P2 デプロイ時点の cohort C を空にする。
+ */
+
+/** 列追加直後 (backfill 前) 相当の行 = has_payment_method が列既定 false のまま。 */
+function subscriptionWithColumnDefault(Organization $organization, ?CarbonImmutable $trialEndsAt = null): int
+{
+    $subscription = createFakeSubscription($organization);
+    DB::table('subscriptions')->where('id', $subscription->getKey())->update([
+        'has_payment_method' => false,
+        'trial_ends_at' => $trialEndsAt,
+    ]);
+
+    return $subscription->getKey();
+}
+
+function runHasPaymentMethodBackfill(): void
+{
+    $migration = require database_path(
+        'migrations/2026_07_17_000220_backfill_has_payment_method_on_subscriptions.php'
+    );
+    expect($migration)->toBeInstanceOf(Migration::class);
+    $migration->up();
+}
+
+test('has_payment_method の列既定は false (移植元と同値)', function (): void {
+    $organization = Organization::factory()->create();
+    $subscription = createFakeSubscription($organization);
+
+    expect($subscription->fresh()?->has_payment_method)->toBeFalse();
+});
+
+test('backfill が既存の全 subscription 行を true にする', function (): void {
+    $organization = Organization::factory()->create();
+    $id = subscriptionWithColumnDefault($organization, CarbonImmutable::now()->subDay());
+
+    runHasPaymentMethodBackfill();
+
+    expect(DB::table('subscriptions')->where('id', $id)->value('has_payment_method'))->toBeTrue();
+});
+
+test('backfill 後は trial 終了済みの既存有償組織が締め出されない (cohort C が空になる)', function (): void {
+    $organization = Organization::factory()->create();
+    $organization->forceFill(['plan_code' => 'standard'])->save();
+    subscriptionWithColumnDefault($organization, CarbonImmutable::now()->subDay());
+
+    // backfill 前は cohort C (trial 終了 + PM 無し) として遮断される
+    expect(app(BillingAccess::class)->hasActiveAccess($organization->fresh() ?? $organization))->toBeFalse();
+
+    runHasPaymentMethodBackfill();
+
+    expect(app(BillingAccess::class)->hasActiveAccess($organization->fresh() ?? $organization))->toBeTrue();
+});
+
+test('backfill は冪等 (2 回流しても結果が変わらない)', function (): void {
+    $organization = Organization::factory()->create();
+    $id = subscriptionWithColumnDefault($organization);
+
+    runHasPaymentMethodBackfill();
+    $afterFirst = DB::table('subscriptions')->where('id', $id)->value('updated_at');
+
+    runHasPaymentMethodBackfill();
+
+    expect(DB::table('subscriptions')->where('id', $id)->value('has_payment_method'))->toBeTrue()
+        ->and(DB::table('subscriptions')->where('id', $id)->value('updated_at'))->toBe($afterFirst);
+});
diff --git a/tests/Feature/Billing/RequireActiveSubscriptionMiddlewareTest.php b/tests/Feature/Billing/RequireActiveSubscriptionMiddlewareTest.php
index 4fac137..1da90f9 100644
--- a/tests/Feature/Billing/RequireActiveSubscriptionMiddlewareTest.php
+++ b/tests/Feature/Billing/RequireActiveSubscriptionMiddlewareTest.php
@@ -9,6 +9,7 @@
 use App\Models\Project;
 use App\Models\User;
 use App\Services\Billing\BillingAccess;
+use Carbon\CarbonImmutable;
 use Illuminate\Http\Request;
 use Illuminate\Routing\Route as RoutingRoute;
 use Illuminate\Support\Facades\Route;
@@ -17,9 +18,16 @@
 /*
  * 課金ゲート (require-active-subscription)。
  * 判定は BillingAccess::hasActiveAccess のみ (billing entitlement):
- * - plan_code null (未契約) = fallback free プラン。支払い不要 tier として許可
- * - plan_code 非 null = 有償プラン契約状態。subscription('default') が active/trialing の
- *   ときのみ許可 (支払い不健全はブラウザなら billing へ redirect + 理由 flash、JSON なら 402)
+ * - plan_code null (未契約) = fallback free プラン。**移行 OR** で許可 (P4 で削除する 1 行)
+ * - それ以外は BillingAccess::state()->grantsAccess() =
+ *   SubscriptionService::deriveEntitlement による判定 (P2 で判定モデルを差し替え済み)。
+ *   遮断はブラウザなら billing へ redirect + 理由 flash、JSON なら 402
+ *
+ * P2 の判定モデル置換で結論が反転した cohort (設計の cohort 表):
+ * - C: active/trialing + trial 終了 + PM 無し = **遮断** (旧: status のみ見て許可)
+ * - D: past_due + (trial 未終了 or PM 有り) = **許可** (旧: past_due を一律遮断)
+ * 網羅は tests/Feature/Billing/BillingAccessStateTest.php (cohort A〜I) が固定する。
+ *
  * billing 系 route は gate group 外 (構造的 allowlist) で遮断中でも checkout に到達できる。
  */
 
@@ -59,6 +67,13 @@
     $this->actingAs($owner)->get('/projects')->assertOk();
 })->with(['active', 'trialing']);
 
+test('有償契約 + past_due は業務 route に到達できる (cohort D。dunning 中も利用継続)', function (): void {
+    [$organization, $owner] = createOrganizationWithOwner();
+    contractPaidPlan($organization, status: 'past_due');
+
+    $this->actingAs($owner)->get('/projects')->assertOk();
+});
+
 test('有償契約 + 支払い不健全は billing へ redirect + 理由 flash', function (string $status): void {
     [$organization, $owner] = createOrganizationWithOwner();
     contractPaidPlan($organization, status: $status);
@@ -66,7 +81,20 @@
     $this->actingAs($owner)->get('/projects')
         ->assertRedirect(route('billing.index'))
         ->assertSessionHas('error', BILLING_BLOCKED_MESSAGE);
-})->with(['past_due', 'canceled', 'incomplete', 'unpaid']);
+})->with(['canceled', 'incomplete', 'unpaid', 'paused']);
+
+test('有償契約 + trial 終了 + PM 無しは遮断される (cohort C / E)', function (string $status): void {
+    [$organization, $owner] = createOrganizationWithOwner();
+    $subscription = contractPaidPlan($organization, status: $status);
+    $subscription->forceFill([
+        'trial_ends_at' => CarbonImmutable::now()->subDay(),
+        'has_payment_method' => false,
+    ])->save();
+
+    $this->actingAs($owner)->get('/projects')
+        ->assertRedirect(route('billing.index'))
+        ->assertSessionHas('error', BILLING_BLOCKED_MESSAGE);
+})->with(['active', 'trialing', 'past_due']);
 
 test('有償契約 + subscription 行なしは fail-closed (webhook 順序逆転の防御)', function (): void {
     [$organization, $owner] = createOrganizationWithOwner();
@@ -78,7 +106,7 @@
 
 test('有償契約 + 支払い不健全の JSON は 402 + message 固定 (flash と同一文言。非 XHR の Accept: json も含む)', function (): void {
     [$organization, $owner] = createOrganizationWithOwner();
-    contractPaidPlan($organization, status: 'past_due');
+    contractPaidPlan($organization, status: 'canceled');
 
     // getJson は Accept: application/json のみ付与 (X-Requested-With なし) =
     // 「JSON を要求する非 XHR クライアント」のケースを踏む (wantsJson 経由で 402 になること)
@@ -89,7 +117,7 @@
 
 test('billing ページは遮断対象の組織でも到達できる (構造的 allowlist)', function (): void {
     [$organization, $owner] = createOrganizationWithOwner();
-    contractPaidPlan($organization, status: 'past_due');
+    contractPaidPlan($organization, status: 'canceled');
 
     $this->actingAs($owner)->get('/billing')->assertOk();
 });
@@ -106,26 +134,46 @@
 
 // ── BillingAccess 単体マトリクス ──
 
-test('BillingAccess: plan_code null は常に許可、非 null は active/trialing のみ許可', function (): void {
+test('BillingAccess: plan_code null は移行 OR で許可 (P4 で削除) / 非 null は deriveEntitlement 判定', function (): void {
     $access = app(BillingAccess::class);
 
-    // 未契約 (free tier)
+    // cohort I: 未契約 (free tier) は移行 OR で許可
     [$freeOrg] = createOrganizationWithOwner();
     expect($access->hasActiveAccess($freeOrg))->toBeTrue();
 
-    // 未契約 + subscription 行だけある (webhook の plan_code 同期前) も許可 (fail-open は free 相当のみ)
+    // cohort I: 未契約 + subscription 行だけある (webhook の plan_code 同期前) も移行 OR で許可
     [$syncLagOrg] = createOrganizationWithOwner();
     createFakeSubscription($syncLagOrg, status: 'active');
     expect($access->hasActiveAccess($syncLagOrg))->toBeTrue();
 
-    // 有償契約状態: status マトリクス
-    foreach (['active' => true, 'trialing' => true, 'past_due' => false, 'canceled' => false, 'incomplete' => false] as $status => $expected) {
+    // 有償契約状態: status マトリクス (past_due = cohort D で許可へ反転済み)
+    $matrix = [
+        'active' => true,
+        'trialing' => true,
+        'past_due' => true,
+        'canceled' => false,
+        'incomplete' => false,
+        'unpaid' => false,
+        'incomplete_expired' => false,
+        'paused' => false,
+    ];
+    foreach ($matrix as $status => $expected) {
         [$organization] = createOrganizationWithOwner();
         contractPaidPlan($organization, status: $status);
         expect($access->hasActiveAccess($organization))->toBe($expected, "stripe_status={$status}");
     }
 
-    // 有償契約状態 + 行なし: fail-closed
+    // cohort C / E: trial 終了 + PM 無しは status に依らず遮断
+    foreach (['active', 'trialing', 'past_due'] as $status) {
+        [$organization] = createOrganizationWithOwner();
+        contractPaidPlan($organization, status: $status)->forceFill([
+            'trial_ends_at' => CarbonImmutable::now()->subDay(),
+            'has_payment_method' => false,
+        ])->save();
+        expect($access->hasActiveAccess($organization))->toBeFalse("trial ended + no PM: stripe_status={$status}");
+    }
+
+    // cohort H: 有償契約状態 + 行なしは fail-closed
     [$orphan] = createOrganizationWithOwner();
     $orphan->forceFill(['plan_code' => 'standard'])->save();
     expect($access->hasActiveAccess($orphan))->toBeFalse();
@@ -142,7 +190,7 @@
     $gated = Organization::factory()->create(['slug' => 'gated-org']);
     $gated->users()->attach($owner);
     $owner->addRole(OrganizationRole::Member->value, $gated->laratrust_team_id);
-    contractPaidPlan($gated, status: 'past_due');
+    contractPaidPlan($gated, status: 'canceled'); // cohort G (past_due は cohort D で許可へ反転済み)
 
     Route::middleware(['web', 'auth', 'require-active-subscription'])
         ->get('/__gate-test/{organization:slug}', fn (Organization $organization) => response('ok'));
diff --git a/tests/Feature/Billing/SubscriptionCheckoutGuardTest.php b/tests/Feature/Billing/SubscriptionCheckoutGuardTest.php
new file mode 100644
index 0000000..dbbf010
--- /dev/null
+++ b/tests/Feature/Billing/SubscriptionCheckoutGuardTest.php
@@ -0,0 +1,148 @@
+<?php
+
+declare(strict_types=1);
+
+use App\Enums\Billing\PlanPriceKind;
+use App\Exceptions\Billing\StripePriceNotSyncedException;
+use App\Models\Billing\Plan;
+use App\Models\Billing\PlanPrice;
+use App\Services\Billing\Contracts\StripeGatewayInterface;
+use App\Services\Billing\Fakes\FakeStripeGateway;
+use App\Services\Billing\SubscriptionService;
+use Carbon\CarbonImmutable;
+use Illuminate\Validation\ValidationException;
+use Webmozart\Assert\Assert;
+
+/*
+ * SubscriptionService::startCheckout の service 層ガード。
+ *
+ * - assertPriceSynced: production runtime でのみ「未 sync の test mode Price」を拒否する
+ *   (deploy 手順の sync 漏れで test Price の本番課金が発生する事故を DB レベルで塞ぐ)。
+ * - assertStripeBillablePlan: Personal (free) / Enterprise / 未知 code は fail-closed で 422。
+ * - 有効なサブスク保持組織の再 checkout は fail-closed (プラン変更は Portal 経由)。
+ */
+
+function checkoutGuardService(): SubscriptionService
+{
+    return app(SubscriptionService::class);
+}
+
+function checkoutGuardPrice(string $planCode = 'standard'): PlanPrice
+{
+    $price = Plan::query()->where('code', $planCode)->firstOrFail()
+        ->currentPrice(PlanPriceKind::Base);
+    Assert::isInstanceOf($price, PlanPrice::class, "{$planCode} の current base price が未 seed");
+
+    return $price;
+}
+
+beforeEach(function (): void {
+    $this->app->bind(StripeGatewayInterface::class, FakeStripeGateway::class);
+});
+
+test('非 production では未 sync の test mode Price でも checkout できる', function (): void {
+    [$organization] = createOrganizationWithOwner();
+    $price = checkoutGuardPrice();
+    $price->forceFill(['livemode' => false, 'synced_at' => null])->save();
+
+    $redirect = checkoutGuardService()->startCheckout(
+        $organization,
+        $price,
+        'https://example.test/return',
+        'https://example.test/return',
+    );
+
+    expect($redirect->url)->toContain('fake_external=stripe');
+});
+
+test('production では未 sync / test mode の Price を StripePriceNotSyncedException で拒否する', function (bool $livemode, ?string $syncedAt): void {
+    $this->app->detectEnvironment(fn (): string => 'production');
+    [$organization] = createOrganizationWithOwner();
+    $price = checkoutGuardPrice();
+    $price->forceFill([
+        'livemode' => $livemode,
+        'synced_at' => $syncedAt === null ? null : CarbonImmutable::now(),
+    ])->save();
+
+    checkoutGuardService()->startCheckout(
+        $organization,
+        $price,
+        'https://example.test/return',
+        'https://example.test/return',
+    );
+})->with([
+    'test mode Price (livemode=false)' => [false, 'now'],
+    'sync 未実施 (synced_at=null)' => [true, null],
+    'test mode かつ未 sync' => [false, null],
+])->throws(StripePriceNotSyncedException::class);
+
+test('production でも livemode + synced_at 済みの Price なら checkout できる', function (): void {
+    $this->app->detectEnvironment(fn (): string => 'production');
+    [$organization] = createOrganizationWithOwner();
+    $price = checkoutGuardPrice();
+    $price->forceFill(['livemode' => true, 'synced_at' => CarbonImmutable::now()])->save();
+
+    $redirect = checkoutGuardService()->startCheckout(
+        $organization,
+        $price,
+        'https://example.test/return',
+        'https://example.test/return',
+    );
+
+    expect($redirect->url)->toContain('fake_external=stripe');
+});
+
+test('Stripe 決済対象外プラン (personal) の checkout は 422 (validation)', function (): void {
+    [$organization] = createOrganizationWithOwner();
+    $price = checkoutGuardPrice();
+    // personal は Price を持たない (activate 経路) ため、Price 側の plan を差し替えて
+    // 「validation を迂回して非対象プランの Price が渡る」経路を service 層で塞ぐことを固定する
+    $personal = Plan::query()->where('code', 'personal')->firstOrFail();
+    $price->forceFill(['plan_id' => $personal->id])->save();
+
+    checkoutGuardService()->startCheckout(
+        $organization->fresh() ?? $organization,
+        $price->fresh() ?? $price,
+        'https://example.test/return',
+        'https://example.test/return',
+    );
+})->throws(ValidationException::class);
+
+test('既に有効なサブスクリプションがある組織の checkout は fail-closed', function (): void {
+    [$organization] = createOrganizationWithOwner();
+    createFakeSubscription($organization, status: 'active');
+
+    checkoutGuardService()->startCheckout(
+        $organization,
+        checkoutGuardPrice(),
+        'https://example.test/return',
+        'https://example.test/return',
+    );
+})->throws(InvalidArgumentException::class, '既に有効なサブスクリプションがあります。プラン変更をご利用ください。');
+
+test('解約済み (猶予期間も終了) のサブスクだけを持つ組織は再 checkout できる', function (): void {
+    [$organization] = createOrganizationWithOwner();
+    // Cashier の valid() は ends_at で猶予期間を見るため、終了済みを明示する
+    createFakeSubscription($organization, status: 'canceled')
+        ->forceFill(['ends_at' => CarbonImmutable::now()->subDay()])->save();
+
+    $redirect = checkoutGuardService()->startCheckout(
+        $organization,
+        checkoutGuardPrice(),
+        'https://example.test/return',
+        'https://example.test/return',
+    );
+
+    expect($redirect->url)->toContain('fake_external=stripe');
+});
+
+test('有効サブスク保持組織の /billing/checkout は 500 にせず error flash で差し戻す', function (): void {
+    [$organization, $owner] = createOrganizationWithOwner();
+    createFakeSubscription($organization, status: 'active');
+
+    $this->actingAs($owner)
+        ->from('/billing')
+        ->post('/billing/checkout', ['plan_code' => 'standard'])
+        ->assertRedirect('/billing')
+        ->assertSessionHas('error', '既に有効なサブスクリプションがあります。プラン変更をご利用ください。');
+});
diff --git a/tests/Feature/Billing/SubscriptionEntitlementTest.php b/tests/Feature/Billing/SubscriptionEntitlementTest.php
new file mode 100644
index 0000000..d861186
--- /dev/null
+++ b/tests/Feature/Billing/SubscriptionEntitlementTest.php
@@ -0,0 +1,167 @@
+<?php
+
+declare(strict_types=1);
+
+use App\Enums\Billing\EntitlementDeniedReason;
+use App\Enums\Billing\ScheduleSetupStatus;
+use App\Enums\Billing\SubscriptionState;
+use App\Models\Billing\Subscription;
+use App\Models\Organization;
+use App\Services\Billing\SubscriptionService;
+use Carbon\CarbonImmutable;
+
+/*
+ * SubscriptionService::deriveEntitlement (aigenba verbatim) の
+ * entitled / state / reason マトリクスを固定する。
+ *
+ *   entitled = state.grantsAccess()
+ *              AND NOT (trial_ends_at <= now AND !has_payment_method)
+ *              AND status != paused
+ */
+
+function entitlementSubscription(
+    string $status = 'active',
+    bool $hasPaymentMethod = true,
+    ?CarbonImmutable $trialEndsAt = null,
+    ?string $scheduleId = null,
+    ScheduleSetupStatus $scheduleSetupStatus = ScheduleSetupStatus::None,
+): Subscription {
+    $organization = Organization::factory()->create();
+    $subscription = createFakeSubscription($organization, status: $status);
+    $subscription->forceFill([
+        'has_payment_method' => $hasPaymentMethod,
+        'trial_ends_at' => $trialEndsAt,
+        'stripe_schedule_id' => $scheduleId,
+        'schedule_setup_status' => $scheduleSetupStatus,
+    ])->save();
+
+    return $subscription;
+}
+
+function entitlementService(): SubscriptionService
+{
+    return app(SubscriptionService::class);
+}
+
+test('active / trialing は Active state で entitled', function (string $status): void {
+    $entitlement = entitlementService()->deriveEntitlement(entitlementSubscription(status: $status));
+
+    expect($entitlement->entitled)->toBeTrue()
+        ->and($entitlement->state)->toBe(SubscriptionState::Active)
+        ->and($entitlement->reason)->toBeNull();
+})->with(['active', 'trialing']);
+
+test('schedule 部分完了 (schedule_id + Created) は UpgradeRecovery で entitled', function (): void {
+    $entitlement = entitlementService()->deriveEntitlement(entitlementSubscription(
+        status: 'active',
+        scheduleId: 'sub_sched_test',
+        scheduleSetupStatus: ScheduleSetupStatus::Created,
+    ));
+
+    expect($entitlement->entitled)->toBeTrue()
+        ->and($entitlement->state)->toBe(SubscriptionState::UpgradeRecovery)
+        ->and($entitlement->reason)->toBeNull();
+});
+
+test('schedule 設定完了 (Configured) は Active のまま (ScheduledForUpgrade は非移植)', function (): void {
+    $entitlement = entitlementService()->deriveEntitlement(entitlementSubscription(
+        status: 'active',
+        scheduleId: 'sub_sched_test',
+        scheduleSetupStatus: ScheduleSetupStatus::Configured,
+    ));
+
+    expect($entitlement->state)->toBe(SubscriptionState::Active)
+        ->and($entitlement->entitled)->toBeTrue();
+});
+
+test('paused は state=Paused / reason=Paused で否定 (schedule 状態に依らない)', function (): void {
+    $entitlement = entitlementService()->deriveEntitlement(entitlementSubscription(
+        status: 'paused',
+        scheduleId: 'sub_sched_test',
+        scheduleSetupStatus: ScheduleSetupStatus::Created,
+    ));
+
+    expect($entitlement->entitled)->toBeFalse()
+        ->and($entitlement->state)->toBe(SubscriptionState::Paused)
+        ->and($entitlement->reason)->toBe(EntitlementDeniedReason::Paused);
+});
+
+test('past_due + PM 有りは state=PastDue で entitled (請求失敗中も利用継続)', function (): void {
+    $entitlement = entitlementService()->deriveEntitlement(entitlementSubscription(
+        status: 'past_due',
+        hasPaymentMethod: true,
+        trialEndsAt: CarbonImmutable::now()->subDay(),
+    ));
+
+    expect($entitlement->entitled)->toBeTrue()
+        ->and($entitlement->state)->toBe(SubscriptionState::PastDue)
+        ->and($entitlement->reason)->toBeNull();
+});
+
+test('past_due + trial 終了 + PM 無しは TrialEndedWithoutPaymentMethod で否定', function (): void {
+    $entitlement = entitlementService()->deriveEntitlement(entitlementSubscription(
+        status: 'past_due',
+        hasPaymentMethod: false,
+        trialEndsAt: CarbonImmutable::now()->subDay(),
+    ));
+
+    expect($entitlement->entitled)->toBeFalse()
+        ->and($entitlement->state)->toBe(SubscriptionState::PastDue)
+        ->and($entitlement->reason)->toBe(EntitlementDeniedReason::TrialEndedWithoutPaymentMethod);
+});
+
+test('trial 終了 + PM 無しは webhook の paused 化前でも先回りで否定', function (string $status): void {
+    $entitlement = entitlementService()->deriveEntitlement(entitlementSubscription(
+        status: $status,
+        hasPaymentMethod: false,
+        trialEndsAt: CarbonImmutable::now()->subSecond(),
+    ));
+
+    expect($entitlement->entitled)->toBeFalse()
+        ->and($entitlement->state)->toBe(SubscriptionState::Active)
+        ->and($entitlement->reason)->toBe(EntitlementDeniedReason::TrialEndedWithoutPaymentMethod);
+})->with(['active', 'trialing']);
+
+test('trial 終了 + PM 有りは entitled', function (): void {
+    $entitlement = entitlementService()->deriveEntitlement(entitlementSubscription(
+        hasPaymentMethod: true,
+        trialEndsAt: CarbonImmutable::now()->subDay(),
+    ));
+
+    expect($entitlement->entitled)->toBeTrue()
+        ->and($entitlement->reason)->toBeNull();
+});
+
+test('trial 未終了は PM 無しでも entitled', function (): void {
+    $entitlement = entitlementService()->deriveEntitlement(entitlementSubscription(
+        status: 'trialing',
+        hasPaymentMethod: false,
+        trialEndsAt: CarbonImmutable::now()->addDay(),
+    ));
+
+    expect($entitlement->entitled)->toBeTrue()
+        ->and($entitlement->reason)->toBeNull();
+});
+
+test('非 active 系 status は Inactive / NoActiveSubscription で否定', function (string $status): void {
+    $entitlement = entitlementService()->deriveEntitlement(entitlementSubscription(status: $status));
+
+    expect($entitlement->entitled)->toBeFalse()
+        ->and($entitlement->state)->toBe(SubscriptionState::Inactive)
+        ->and($entitlement->reason)->toBe(EntitlementDeniedReason::NoActiveSubscription);
+})->with(['canceled', 'unpaid', 'incomplete', 'incomplete_expired']);
+
+test('DTO の toArray は entitled / state / reason を value で返す', function (): void {
+    $granted = entitlementService()->deriveEntitlement(entitlementSubscription());
+    $denied = entitlementService()->deriveEntitlement(entitlementSubscription(status: 'paused'));
+
+    expect($granted->toArray())->toBe([
+        'entitled' => true,
+        'state' => 'active',
+        'reason' => null,
+    ])->and($denied->toArray())->toBe([
+        'entitled' => false,
+        'state' => 'paused',
+        'reason' => 'paused',
+    ]);
+});
diff --git a/tests/Feature/Billing/SubscriptionSnapshotSyncTest.php b/tests/Feature/Billing/SubscriptionSnapshotSyncTest.php
new file mode 100644
index 0000000..08d9e7f
--- /dev/null
+++ b/tests/Feature/Billing/SubscriptionSnapshotSyncTest.php
@@ -0,0 +1,359 @@
+<?php
+
+declare(strict_types=1);
+
+use App\Enums\Billing\PlanPriceKind;
+use App\Enums\Billing\ScheduleSetupStatus;
+use App\Models\Billing\Plan;
+use App\Models\Billing\Subscription;
+use App\Models\Organization;
+use App\Services\Billing\StripeWebhookProcessor;
+use App\Services\Billing\SubscriptionService;
+use App\Services\Billing\SubscriptionSnapshot;
+use Carbon\CarbonImmutable;
+use Illuminate\Contracts\Events\Dispatcher;
+use Illuminate\Database\Events\TransactionBeginning;
+use Illuminate\Support\Facades\DB;
+use Laravel\Cashier\Events\WebhookReceived;
+use Webmozart\Assert\Assert;
+
+/*
+ * SubscriptionService::applySubscriptionSnapshot / recordPaymentMethodSnapshot。
+ *
+ * - applySubscriptionSnapshot: webhook 受信時の唯一の状態書込経路。
+ *   organizations.plan_code は「base Price が解決でき かつ status が active/trialing」の
+ *   ときだけ同期し、terminated では null に戻す。**subscriptions 行は作らない**
+ *   (行の作成権威は Cashier の WebhookController)。
+ * - recordPaymentMethodSnapshot: has_payment_method の独立 monotonic writer
+ *   (true → false に戻さない / 行不在は早期 return)。
+ */
+
+function snapshotSyncService(): SubscriptionService
+{
+    return app(SubscriptionService::class);
+}
+
+/** PlanSeeder が投入した standard プラン現行 base Price の Stripe Price ID */
+function snapshotSyncStandardPriceId(): string
+{
+    $price = Plan::query()->where('code', 'standard')->firstOrFail()
+        ->currentPrice(PlanPriceKind::Base);
+    Assert::notNull($price, 'standard プランの current base price が未 seed');
+
+    return $price->stripe_price_id;
+}
+
+function snapshotSyncOrganization(): Organization
+{
+    [$organization] = createOrganizationWithOwner();
+    $organization->stripe_id = 'cus_snapshot_1';
+    $organization->save();
+
+    return $organization;
+}
+
+/**
+ * @param  string|null  $basePriceId  null なら standard の現行 base price
+ */
+function snapshotSyncSnapshot(
+    string $status = 'active',
+    ?string $basePriceId = null,
+    ?int $quantity = 1,
+    ?CarbonImmutable $currentPeriodEnd = null,
+    ?CarbonImmutable $trialEndsAt = null,
+    ?CarbonImmutable $endsAt = null,
+    string $stripeId = 'sub_snapshot_1',
+): SubscriptionSnapshot {
+    return new SubscriptionSnapshot(
+        stripeId: $stripeId,
+        status: $status,
+        basePriceId: $basePriceId ?? snapshotSyncStandardPriceId(),
+        baseQuantity: $quantity,
+        currentPeriodEnd: $currentPeriodEnd,
+        trialEndsAt: $trialEndsAt,
+        endsAt: $endsAt,
+    );
+}
+
+test('active + 既知 base price は plan_code を同期し subscription 行の Stripe 由来列を更新する', function (): void {
+    $organization = snapshotSyncOrganization();
+    $subscription = createFakeSubscription($organization, status: 'incomplete');
+    $subscription->forceFill(['stripe_id' => 'sub_snapshot_1'])->save();
+
+    $periodEnd = CarbonImmutable::now()->addMonth()->startOfSecond();
+    $trialEnd = CarbonImmutable::now()->addDays(3)->startOfSecond();
+
+    snapshotSyncService()->applySubscriptionSnapshot($organization, snapshotSyncSnapshot(
+        status: 'trialing',
+        quantity: 3,
+        currentPeriodEnd: $periodEnd,
+        trialEndsAt: $trialEnd,
+    ));
+
+    expect($organization->fresh()?->plan_code)->toBe('standard');
+
+    $fresh = $subscription->fresh();
+    Assert::isInstanceOf($fresh, Subscription::class);
+    expect($fresh->stripe_status)->toBe('trialing')
+        ->and($fresh->stripe_price)->toBe(snapshotSyncStandardPriceId())
+        ->and($fresh->quantity)->toBe(3)
+        ->and($fresh->current_period_end?->equalTo($periodEnd))->toBeTrue()
+        ->and($fresh->trial_ends_at?->equalTo($trialEnd))->toBeTrue()
+        ->and($fresh->ends_at)->toBeNull();
+});
+
+test('未知の Price は受理のみ (plan_code を同期しない) で Stripe 列だけ更新する', function (): void {
+    $organization = snapshotSyncOrganization();
+    $subscription = createFakeSubscription($organization, status: 'incomplete');
+    $subscription->forceFill(['stripe_id' => 'sub_snapshot_1'])->save();
+
+    snapshotSyncService()->applySubscriptionSnapshot(
+        $organization,
+        snapshotSyncSnapshot(basePriceId: 'price_unknown_xyz'),
+    );
+
+    expect($organization->fresh()?->plan_code)->toBeNull()
+        ->and($subscription->fresh()?->stripe_status)->toBe('active');
+});
+
+test('非 active 系 status は plan_code を同期しない (既存値を維持する)', function (string $status): void {
+    $organization = snapshotSyncOrganization();
+    $subscription = createFakeSubscription($organization, status: 'active');
+    $subscription->forceFill(['stripe_id' => 'sub_snapshot_1'])->save();
+
+    snapshotSyncService()->applySubscriptionSnapshot($organization, snapshotSyncSnapshot(status: $status));
+
+    expect($organization->fresh()?->plan_code)->toBeNull()
+        ->and($subscription->fresh()?->stripe_status)->toBe($status);
+})->with(['past_due', 'paused', 'canceled', 'incomplete']);
+
+test('terminated は plan_code を解除し schedule ライフサイクル列を同一 TX でクリアする', function (): void {
+    $organization = snapshotSyncOrganization();
+    $organization->forceFill(['plan_code' => 'standard'])->save();
+    $subscription = createFakeSubscription($organization, status: 'active');
+    $subscription->forceFill([
+        'stripe_id' => 'sub_snapshot_1',
+        'stripe_schedule_id' => 'sub_sched_1',
+        'schedule_setup_status' => ScheduleSetupStatus::Created,
+    ])->save();
+
+    $endedAt = CarbonImmutable::now()->startOfSecond();
+
+    snapshotSyncService()->applySubscriptionSnapshot(
+        $organization,
+        snapshotSyncSnapshot(status: 'canceled', endsAt: $endedAt),
+        terminated: true,
+    );
+
+    expect($organization->fresh()?->plan_code)->toBeNull();
+
+    $fresh = $subscription->fresh();
+    Assert::isInstanceOf($fresh, Subscription::class);
+    expect($fresh->stripe_status)->toBe('canceled')
+        ->and($fresh->stripe_schedule_id)->toBeNull()
+        ->and($fresh->schedule_setup_status)->toBe(ScheduleSetupStatus::None)
+        ->and($fresh->ends_at?->equalTo($endedAt))->toBeTrue();
+});
+
+test('subscription 行が無くても行を作らない (作成権威は Cashier の WebhookController)', function (): void {
+    $organization = snapshotSyncOrganization();
+
+    snapshotSyncService()->applySubscriptionSnapshot($organization, snapshotSyncSnapshot());
+
+    // plan_code の同期は行の有無に依らず走る (行の materialize は Cashier に委ねる)
+    expect($organization->fresh()?->plan_code)->toBe('standard')
+        ->and(Subscription::query()->count())->toBe(0);
+});
+
+test('period 欠落 snapshot は既存の current_period_end を維持する (reminder の真実源を壊さない)', function (): void {
+    $organization = snapshotSyncOrganization();
+    $subscription = createFakeSubscription($organization);
+    $existingPeriodEnd = CarbonImmutable::now()->addMonth()->startOfSecond();
+    $subscription->forceFill([
+        'stripe_id' => 'sub_snapshot_1',
+        'current_period_end' => $existingPeriodEnd,
+    ])->save();
+
+    snapshotSyncService()->applySubscriptionSnapshot(
+        $organization,
+        snapshotSyncSnapshot(currentPeriodEnd: null),
+    );
+
+    expect($subscription->fresh()?->current_period_end?->equalTo($existingPeriodEnd))->toBeTrue();
+});
+
+test('recordPaymentMethodSnapshot は false → true へ昇格させる', function (): void {
+    $organization = snapshotSyncOrganization();
+    $subscription = createFakeSubscription($organization);
+    $subscription->forceFill(['has_payment_method' => false])->save();
+
+    snapshotSyncService()->recordPaymentMethodSnapshot($subscription, true);
+
+    expect($subscription->fresh()?->has_payment_method)->toBeTrue();
+});
+
+test('recordPaymentMethodSnapshot は monotonic (true → false に戻さない)', function (): void {
+    $organization = snapshotSyncOrganization();
+    $subscription = createFakeSubscription($organization);
+    $subscription->forceFill(['has_payment_method' => true])->save();
+
+    snapshotSyncService()->recordPaymentMethodSnapshot($subscription, false);
+
+    expect($subscription->fresh()?->has_payment_method)->toBeTrue();
+});
+
+test('recordPaymentMethodSnapshot は PM 無しのまま false を渡しても no-op', function (): void {
+    $organization = snapshotSyncOrganization();
+    $subscription = createFakeSubscription($organization);
+    $subscription->forceFill(['has_payment_method' => false])->save();
+
+    snapshotSyncService()->recordPaymentMethodSnapshot($subscription, false);
+
+    expect($subscription->fresh()?->has_payment_method)->toBeFalse();
+});
+
+test('recordPaymentMethodSnapshot は行不在なら早期 return (例外を投げない)', function (): void {
+    $organization = snapshotSyncOrganization();
+    $subscription = createFakeSubscription($organization);
+    $subscriptionId = $subscription->id;
+    // Cashier が行を作る前 / 削除後の instance を模す
+    Subscription::query()->whereKey($subscriptionId)->delete();
+
+    snapshotSyncService()->recordPaymentMethodSnapshot($subscription, true);
+
+    expect(Subscription::query()->whereKey($subscriptionId)->exists())->toBeFalse();
+});
+
+test('recordPaymentMethodSnapshot は transaction 内で行を lockForUpdate してから書く', function (): void {
+    $organization = snapshotSyncOrganization();
+    $subscription = createFakeSubscription($organization);
+    $subscription->forceFill(['has_payment_method' => false])->save();
+
+    /** @var list<string> $queries */
+    $queries = [];
+    DB::listen(function ($query) use (&$queries): void {
+        $queries[] = $query->sql;
+    });
+
+    // DB::listen と同じ (connection が保持する) dispatcher に直接登録する。
+    // Event::fake は container の binding だけを差し替えるため connection には届かない。
+    $began = 0;
+    $dispatcher = DB::connection()->getEventDispatcher();
+    Assert::isInstanceOf($dispatcher, Dispatcher::class);
+    $dispatcher->listen(TransactionBeginning::class, function () use (&$began): void {
+        $began++;
+    });
+
+    snapshotSyncService()->recordPaymentMethodSnapshot($subscription, true);
+
+    // RefreshDatabase の外側 TX 内では savepoint として開始される (level が上がる)
+    expect($began)->toBeGreaterThan(0);
+
+    $locking = array_values(array_filter(
+        $queries,
+        fn (string $sql): bool => str_contains(strtolower($sql), 'for update'),
+    ));
+    expect($locking)->not->toBeEmpty()
+        ->and(strtolower($locking[0]))->toContain('from "subscriptions"');
+});
+
+test('customer.subscription.updated は snapshot 同期と PM 記録を配線する', function (): void {
+    $organization = snapshotSyncOrganization();
+    $subscription = createFakeSubscription($organization, status: 'incomplete');
+    $subscription->forceFill([
+        'stripe_id' => 'sub_wired_1',
+        'has_payment_method' => false,
+    ])->save();
+
+    event(new WebhookReceived([
+        'id' => 'evt_wired_updated_1',
+        'type' => 'customer.subscription.updated',
+        'data' => [
+            'object' => [
+                'id' => 'sub_wired_1',
+                'customer' => 'cus_snapshot_1',
+                'status' => 'active',
+                'default_payment_method' => 'pm_test_1',
+                'items' => [
+                    'data' => [[
+                        'price' => ['id' => snapshotSyncStandardPriceId()],
+                        'quantity' => 1,
+                        'current_period_end' => CarbonImmutable::now()->addMonth()->getTimestamp(),
+                    ]],
+                ],
+            ],
+        ],
+    ]));
+
+    $fresh = $subscription->fresh();
+    Assert::isInstanceOf($fresh, Subscription::class);
+    expect($organization->fresh()?->plan_code)->toBe('standard')
+        ->and($fresh->stripe_status)->toBe('active')
+        ->and($fresh->has_payment_method)->toBeTrue()
+        ->and($fresh->current_period_end)->not->toBeNull();
+});
+
+test('default_source だけでも PM 有りと判定する (expanded object も id を拾う)', function (): void {
+    $organization = snapshotSyncOrganization();
+    $subscription = createFakeSubscription($organization);
+    $subscription->forceFill(['stripe_id' => 'sub_wired_2', 'has_payment_method' => false])->save();
+
+    event(new WebhookReceived([
+        'id' => 'evt_wired_updated_2',
+        'type' => 'customer.subscription.updated',
+        'data' => [
+            'object' => [
+                'id' => 'sub_wired_2',
+                'customer' => 'cus_snapshot_1',
+                'status' => 'active',
+                'default_source' => ['id' => 'card_test_1'],
+                'items' => ['data' => [['price' => ['id' => snapshotSyncStandardPriceId()]]]],
+            ],
+        ],
+    ]));
+
+    expect($subscription->fresh()?->has_payment_method)->toBeTrue();
+});
+
+test('PM 情報を含まない customer.subscription.updated は has_payment_method を false に戻さない', function (): void {
+    $organization = snapshotSyncOrganization();
+    $subscription = createFakeSubscription($organization);
+    $subscription->forceFill(['stripe_id' => 'sub_wired_3', 'has_payment_method' => true])->save();
+
+    event(new WebhookReceived([
+        'id' => 'evt_wired_updated_3',
+        'type' => 'customer.subscription.updated',
+        'data' => [
+            'object' => [
+                'id' => 'sub_wired_3',
+                'customer' => 'cus_snapshot_1',
+                'status' => 'active',
+                'items' => ['data' => [['price' => ['id' => snapshotSyncStandardPriceId()]]]],
+            ],
+        ],
+    ]));
+
+    expect($subscription->fresh()?->has_payment_method)->toBeTrue();
+});
+
+test('customer.subscription.created で行がまだ無くても例外にならず行も作らない', function (): void {
+    $organization = snapshotSyncOrganization();
+
+    // Cashier の WebhookController より先に走る WebhookReceived listener を直接叩く
+    app(StripeWebhookProcessor::class)->handle(new WebhookReceived([
+        'id' => 'evt_wired_created_1',
+        'type' => 'customer.subscription.created',
+        'data' => [
+            'object' => [
+                'id' => 'sub_wired_4',
+                'customer' => 'cus_snapshot_1',
+                'status' => 'active',
+                'default_payment_method' => 'pm_test_1',
+                'items' => ['data' => [['price' => ['id' => snapshotSyncStandardPriceId()]]]],
+            ],
+        ],
+    ]));
+
+    expect(Subscription::query()->count())->toBe(0)
+        ->and($organization->fresh()?->plan_code)->toBe('standard');
+});
diff --git a/tests/Feature/DashboardTest.php b/tests/Feature/DashboardTest.php
index ecd6c74..26aa59c 100644
--- a/tests/Feature/DashboardTest.php
+++ b/tests/Feature/DashboardTest.php
@@ -423,7 +423,9 @@ function adoptTakeFor(Cut $cut): Take
 
 test('有償契約 + 支払い不健全 org: has_billing_access=false + CTA 遷移先 200 (redirect loop なし)', function (): void {
     [$organization, $owner] = createOrganizationWithOwner();
-    contractPaidPlan($organization, status: 'past_due');
+    // P2 の判定モデル置換で past_due は cohort D として許可へ反転したため、
+    // 遮断側の不変条件 (redirect loop なし) は canceled (cohort G) で保持する。
+    contractPaidPlan($organization, status: 'canceled');
     Project::factory()->forOrganization($organization)->create();
 
     $this->actingAs($owner)->get('/dashboard')
@@ -436,6 +438,17 @@ function adoptTakeFor(Cut $cut): Take
     $this->actingAs($owner)->get('/billing')->assertOk();
 });
 
+test('有償契約 + past_due org: has_billing_access=true (cohort D。dunning 中も利用継続)', function (): void {
+    [$organization, $owner] = createOrganizationWithOwner();
+    contractPaidPlan($organization, status: 'past_due');
+    Project::factory()->forOrganization($organization)->create();
+
+    $this->actingAs($owner)->get('/dashboard')
+        ->assertOk()
+        ->assertInertia(fn (Assert $page) => $page
+            ->where('dashboard.billing.has_billing_access', true));
+});
+
 test('ゲストは /login へ redirect (既存挙動維持)', function (): void {
     $this->get('/dashboard')->assertRedirect('/login');
 });
diff --git a/tests/Feature/Organizations/RenameOrganizationTest.php b/tests/Feature/Organizations/RenameOrganizationTest.php
new file mode 100644
index 0000000..01f049b
--- /dev/null
+++ b/tests/Feature/Organizations/RenameOrganizationTest.php
@@ -0,0 +1,69 @@
+<?php
+
+declare(strict_types=1);
+
+use App\Jobs\Billing\SyncBillingCustomerDetails;
+use Illuminate\Support\Facades\Queue;
+
+/*
+ * 組織 rename 経路 (RenameOrganizationAction 経由) の契約:
+ * - 外部挙動 (redirect / DB 結果) は不変
+ * - name が実際に変わったときだけ Stripe customer 同期 job を dispatch する
+ * - Stripe customer 未作成 (stripe_id === null) の組織は no-op
+ */
+
+test('owner は組織名を更新でき、Stripe customer 同期が dispatch される', function (): void {
+    Queue::fake();
+    [$organization, $owner] = createOrganizationWithOwner();
+    $organization->forceFill(['stripe_id' => 'cus_rename_1'])->save();
+
+    $this->actingAs($owner)
+        ->from("/organizations/{$organization->slug}/settings")
+        ->patch("/organizations/{$organization->slug}", ['name' => '新しい組織名'])
+        ->assertRedirect("/organizations/{$organization->slug}/settings")
+        ->assertSessionHas('success');
+
+    expect($organization->fresh()->name)->toBe('新しい組織名');
+    Queue::assertPushed(SyncBillingCustomerDetails::class, 1);
+});
+
+test('同名での保存では同期を dispatch しない (isDirty 限定)', function (): void {
+    Queue::fake();
+    [$organization, $owner] = createOrganizationWithOwner('元の名前');
+    $organization->forceFill(['stripe_id' => 'cus_rename_2'])->save();
+
+    $this->actingAs($owner)
+        ->from("/organizations/{$organization->slug}/settings")
+        ->patch("/organizations/{$organization->slug}", ['name' => '元の名前'])
+        ->assertSessionHas('success');
+
+    Queue::assertNotPushed(SyncBillingCustomerDetails::class);
+});
+
+test('Stripe customer 未作成の組織では rename しても同期を dispatch しない', function (): void {
+    Queue::fake();
+    [$organization, $owner] = createOrganizationWithOwner();
+    expect($organization->stripe_id)->toBeNull();
+
+    $this->actingAs($owner)
+        ->from("/organizations/{$organization->slug}/settings")
+        ->patch("/organizations/{$organization->slug}", ['name' => '名前だけ変更'])
+        ->assertSessionHas('success');
+
+    expect($organization->fresh()->name)->toBe('名前だけ変更');
+    Queue::assertNotPushed(SyncBillingCustomerDetails::class);
+});
+
+test('一般メンバーは組織名を更新できない (認可境界は不変)', function (): void {
+    Queue::fake();
+    [$organization] = createOrganizationWithOwner('元の名前');
+    $member = attachOrganizationMember($organization);
+
+    $this->actingAs($member)
+        ->from("/organizations/{$organization->slug}/settings")
+        ->patch("/organizations/{$organization->slug}", ['name' => '乗っ取り'])
+        ->assertForbidden();
+
+    expect($organization->fresh()->name)->toBe('元の名前');
+    Queue::assertNotPushed(SyncBillingCustomerDetails::class);
+});
diff --git a/tests/Feature/Providers/FakeExternalsServiceProviderTest.php b/tests/Feature/Providers/FakeExternalsServiceProviderTest.php
index c5f410e..ba1bcb4 100644
--- a/tests/Feature/Providers/FakeExternalsServiceProviderTest.php
+++ b/tests/Feature/Providers/FakeExternalsServiceProviderTest.php
@@ -4,11 +4,11 @@
 
 use App\Prompts\ExampleSummaryPrompt;
 use App\Providers\FakeExternalsServiceProvider;
-use App\Services\Billing\CashierSubscriptionCheckoutGateway;
+use App\Services\Billing\CashierStripeGateway;
 use App\Services\Billing\CashierTicketCheckoutGateway;
-use App\Services\Billing\Fakes\FakeSubscriptionCheckoutGateway;
+use App\Services\Billing\Contracts\StripeGatewayInterface;
+use App\Services\Billing\Fakes\FakeStripeGateway;
 use App\Services\Billing\Fakes\FakeTicketCheckoutGateway;
-use App\Services\Billing\SubscriptionCheckoutGateway;
 use App\Services\Billing\TicketCheckoutGateway;
 use Illuminate\Support\Facades\Http;
 use Illuminate\Support\Facades\Log;
@@ -32,7 +32,7 @@
 test('既定 (flag=false) では両 gateway とも Cashier 実装に解決される', function (): void {
     expect(config('testing.fake_externals'))->toBeFalse();
     expect(app(TicketCheckoutGateway::class))->toBeInstanceOf(CashierTicketCheckoutGateway::class);
-    expect(app(SubscriptionCheckoutGateway::class))->toBeInstanceOf(CashierSubscriptionCheckoutGateway::class);
+    expect(app(StripeGatewayInterface::class))->toBeInstanceOf(CashierStripeGateway::class);
 });
 
 test('flag=true かつ allowlist 環境 (testing) では両 gateway が fake に解決される', function (): void {
@@ -40,7 +40,7 @@
     (new FakeExternalsServiceProvider($this->app))->register();
 
     expect(app(TicketCheckoutGateway::class))->toBeInstanceOf(FakeTicketCheckoutGateway::class);
-    expect(app(SubscriptionCheckoutGateway::class))->toBeInstanceOf(FakeSubscriptionCheckoutGateway::class);
+    expect(app(StripeGatewayInterface::class))->toBeInstanceOf(FakeStripeGateway::class);
 });
 
 test('flag=true でも allowlist 外の環境 (production) では fake に bind せず warning を出す', function (): void {
@@ -56,7 +56,7 @@
     }
 
     expect(app(TicketCheckoutGateway::class))->toBeInstanceOf(CashierTicketCheckoutGateway::class);
-    expect(app(SubscriptionCheckoutGateway::class))->toBeInstanceOf(CashierSubscriptionCheckoutGateway::class);
+    expect(app(StripeGatewayInterface::class))->toBeInstanceOf(CashierStripeGateway::class);
     Log::shouldHaveReceived('warning')->once();
 });
 
diff --git a/tests/Unit/Billing/FakeStripeGatewayTest.php b/tests/Unit/Billing/FakeStripeGatewayTest.php
new file mode 100644
index 0000000..df2f44b
--- /dev/null
+++ b/tests/Unit/Billing/FakeStripeGatewayTest.php
@@ -0,0 +1,42 @@
+<?php
+
+declare(strict_types=1);
+
+use App\Models\Organization;
+use App\Services\Billing\Fakes\FakeStripeGateway;
+
+/*
+ * runtime fake (App\Services\Billing\Fakes\FakeStripeGateway) の不変条件:
+ * - checkout / portal とも「中立帰還」(遷移先ベース URL + 観測用 marker `fake_external=stripe`)
+ * - syncCustomerDetails は no-op (fake 環境が実 Stripe API を叩かない規約)
+ */
+
+test('checkout は cancel URL ベースの中立帰還 URL を返す', function (): void {
+    $redirect = (new FakeStripeGateway)->createSubscriptionCheckout(
+        Organization::factory()->make(),
+        'price_test',
+        'https://app.test/billing?success=1',
+        'https://app.test/billing',
+    );
+
+    expect($redirect->url)->toContain('https://app.test/billing')
+        ->and($redirect->url)->toContain('fake_external=stripe');
+});
+
+test('portal は return URL ベースの中立帰還 URL を返す', function (): void {
+    $redirect = (new FakeStripeGateway)->createPortalSession(
+        Organization::factory()->make(),
+        'https://app.test/billing',
+    );
+
+    expect($redirect->url)->toContain('https://app.test/billing')
+        ->and($redirect->url)->toContain('fake_external=stripe');
+});
+
+test('syncCustomerDetails は no-op (実 Stripe を叩かない)', function (): void {
+    // stripe_id を持つ組織でも Stripe API 呼び出しが起きず完走する
+    $organization = Organization::factory()->make(['name' => 'テスト組織']);
+    $organization->stripe_id = 'cus_fake_1';
+
+    (new FakeStripeGateway)->syncCustomerDetails($organization);
+})->throwsNoExceptions();
diff --git a/tests/Unit/Billing/OnboardingBillingStateTest.php b/tests/Unit/Billing/OnboardingBillingStateTest.php
new file mode 100644
index 0000000..2911578
--- /dev/null
+++ b/tests/Unit/Billing/OnboardingBillingStateTest.php
@@ -0,0 +1,28 @@
+<?php
+
+declare(strict_types=1);
+
+use App\Enums\Billing\OnboardingBillingState;
+
+test('5 case の value が固定されている', function (): void {
+    expect(array_map(
+        fn (OnboardingBillingState $state): string => $state->value,
+        OnboardingBillingState::cases(),
+    ))->toBe([
+        'no_subscription',
+        'pending_checkout',
+        'expired_checkout',
+        'subscribed',
+        'active_free_plan',
+    ]);
+});
+
+test('grantsAccess は Subscribed / ActiveFreePlan のみ true', function (OnboardingBillingState $state, bool $expected): void {
+    expect($state->grantsAccess())->toBe($expected);
+})->with([
+    [OnboardingBillingState::NoSubscription, false],
+    [OnboardingBillingState::PendingCheckout, false],
+    [OnboardingBillingState::ExpiredCheckout, false],
+    [OnboardingBillingState::Subscribed, true],
+    [OnboardingBillingState::ActiveFreePlan, true],
+]);
```

## テスト結果

- composer test: **1957 tests / 1955 passed / 0 failed / 2 skipped**（8004 assertions）
- composer phpstan: **[OK] No errors**（level 10）
- pint --test / pnpm lint / pnpm typecheck: すべて passed
