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
AI-CUE=/workspace（worktree=/workspace/.claude/worktrees/tasks/T074）、aigenba=/tmp/aigenba が読める。

---

あなたは経験豊富なコードレビュアーです。Laravel + Svelte の実装をレビューしてください。

【前提】
- PHP 8.4 / Laravel 12 / Svelte 5 runes / Inertia / PHPStan level 10 / Pest
  (RefreshDatabase グローバル・--parallel、個別 DatabaseTransactions 禁止、テストデータは Factory)
- 本 PR は決済ドメイン aigenba parity 設計の **P3 (T074 = Onboarding 最小導線)** の実装。
- **P1(T072) / P2(T073) はマージ済み**（PlanCode 5 case / PersonalPlanService::activate 完成 /
  plans.is_active 全 true seed / D28 = 全 tier monthly_ticket_grant=0 /
  OnboardingBillingState(5状態) + BillingAccess::state() + 移行 OR `|| plan_code === null`）。

【本件の最重要方針 (v2)】
**aigenba verbatim で移植する。「parity より良い設計」を持ち込まない**。
逸脱してよいのは **AGENTS.md の禁止事項・セキュリティ不変条件に抵触する場合のみ**（実装者の設計判断は根拠にしない）。
**後方互換の考慮は不要**（未リリース。ユーザー明示）。**オーバーエンジニアリング禁止**。
**撤回済みで使用禁止**: EffectivePlan / NoPlan / isDeclared() / debt / is_active=false seed。

【P3 の DoD（設計より）】
**導線を足すだけ**。`BillingAccess` / `RequireActiveSubscription` は一切触らない（ゲート反転は P4）。
Personal 有効化は P1 の `PersonalPlanService::activate()` を**呼ぶだけ**。**migration は無い**。
入口ガードは **aigenba verbatim**（`OnboardingController` = `hasActiveAccess()` / `BillingRequiredController` = `state()->grantsAccess()`）。

【レビュー観点】
1. **設計との一致性**（P3 セクション）。**設計に無いものを足していないか**。**aigenba verbatim から不必要に逸脱していないか**。
2. 正確性・null 安全 / 3. PHPStan level 10（ignore/baseline/widen を使っていないか）
4. テスト網羅性（**404 テストが 3 route すべてにあるか**、既存テストを削除していないか、Factory 生成か）
5. DTO / Inertia props / 6. **セキュリティ**（不変条件 #1 tenant キー不信 = `ProhibitsProtectedKeys` / **#2 子は親に属する = 認可より前に 404**）
7. 副作用・後退リスク / 8. **DESIGN.md 準拠**（token 経由・hex 直書き禁止）/ **Atomic Design 準拠**（T071 primitive・Lucide）

【特に見てほしい点】
- **route が route parameter を持たない current-org スコープ**か。**`require-active-subscription` group の外**か。
- **3 Controller すべてが `Request` のみの引数**で、**current org 不在 → 404 / 非所属 → 404**（認可より前）か。
- **入口ガードが aigenba verbatim**か（独自述語を発明していないか）。
- **D4（禁止事項 #8）準拠**: `personalEligibility.eligible=false` でも **CTA が押せる**か。押下後に**サーバ由来の理由**を表示するか。
  `declaration` 未チェックでも submit でき、validation error を表示するか。**disabled を使っていないか**。
- **D28**: 「月 N 枚」表記が無いか。
- 実装者が報告した**逸脱**が妥当か:
  1. `->map(...)->values()->all()` → `array_values(...->all())`（設計どおりだと larastan が `array<int,PlanDto>` に落ちて
     `list<PlanDto>` の return type で PHPStan level 10 が落ちる。aigenba は inline `@var` で上書きするが**禁止事項 #1** に抵触。
     同一リポジトリの既存 precedent `PricingService::listPublicPlans()` と同作法へ。意味論・集合は不変）
  2. `PlanFactory` を新設しなかった（Plan の真実源は `PlanSeeder` で `TestCase::$seed = true` により毎テスト実走。
     既存テストも seeded 行を読む規約。必要な 2 ケースは seed で揃うため**手組みデータは不使用**。
     使わない Factory の新設は**禁止事項 #7**）
  3. **有償プランの submit を P3 に含めた**（設計 L2413 = P8a が `Checkout.svelte` を「**P3 導出** + P8a の funding 2 択」の
     **改修**として記述しており P3 に存在する前提。無いと Starter/Standard 選択後の行き先が無く**詰み** = P3 の存在理由と矛盾。
     body は既存 `Billing/Index.svelte` と同一の `{plan_code}` のみ）

【出力形式】
- ファイルごとに判定: APPROVE / REQUEST_CHANGES
- 指摘は [Critical] [Warning] [Suggestion] で分類 / Critical・Warning には修正案を必ず添える
- 全体判定: APPROVED / CHANGES_REQUESTED
- 日本語で出力

---

## 詳細設計書（横断決定 v2 + P3 セクション）

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
### P3 Onboarding 最小導線（ゲート反転より前に導線を実在させる = F-07 再発防止の条件 A）

前提: P1（`App\Enums\PlanCode` **5 case** / `plans.is_active`（**全プラン `true` seed 済み**）/ `organizations.{free_plan_code, free_plan_activated_at, personal_declared_at, personal_declared_by_user_id, signup_tickets_granted_at}` + partial unique index / `PersonalPlanService::activate()` **完成済み** / `PersonalPlanEligibilityDto` / `PersonalPlanIneligibleReason` / `PersonalPlanNotEligibleException` / **D28: 全 tier `monthly_ticket_grant=0`**）と P2（`App\Enums\Billing\OnboardingBillingState` **5 状態** + `BillingAccess::state()` **verbatim** / `hasActiveAccess() = state()->grantsAccess() || $org->plan_code === null`（**移行 OR。P4 で削除**））がマージ済み。

**DoD**: **導線を足すだけ**。`BillingAccess` / `RequireActiveSubscription` は一切触らない（ゲート反転は P4）。Personal 有効化は P1 の `PersonalPlanService::activate()` を**呼ぶだけ**（付与ロジックを再実装しない = 二重付与源を作らない）。**migration は無い**（`plans.is_active` は P1 で `true` seed 済み = Personal / Starter / Standard は本フェーズ開始時点で既に公開済み）。**入口ガードは aigenba verbatim**（`OnboardingController` = `hasActiveAccess()` / `BillingRequiredController` = `state()->grantsAccess()`）。

**P3〜P4 の窓で生じる既知の非対称（新しい述語を作らずに受け入れる）**: aigenba では `hasActiveAccess() ≡ state()->grantsAccess()` だが、AI-CUE は P4 まで `hasActiveAccess()` にのみ移行 OR（`plan_code === null` を通す現行の意図的実装）が乗る。帰結は 2 つ。

1. `onboarding.checkout` は **`plan_code IS NULL` の org（= P3 時点の未契約 org の大半）では `billing.index` へ redirect され、画面としては到達しない**。到達するのは `plan_code` 非 null かつ entitlement 不成立（canceled / unpaid / incomplete / paused = `ExpiredCheckout`）の org のみ。
2. `onboarding.billing-required` は `state()` を直に読むため移行 OR の影響を受けず、`plan_code IS NULL` の非 manage-billing member が**直 URL で 200 render できる**（まだ遮断されていないのに説明画面が見える）。**P3 では billing-required への UI リンクを一切張らない**ため、通常導線からは到達しない。

**P4 で移行 OR の 1 行を消すと両者は自動的に aigenba と同値へ収束する**（`plan_code IS NULL` の org は grandfathering backfill で `ActiveFreePlan`、それ以外は `NoSubscription` → checkout が到達可能・billing-required が正しい対象にだけ出る）。**したがって条件 A は「P3 で route / Controller / 画面 / テストが実在すること」で満たし、到達可能性の反転は P4 の OR 削除と同一コミットで起きる**（P3 に述語を発明して先取りしない）。

#### 変更箇所

| AI-CUE（新規/変更） | 移植元 aigenba | 何をするか |
|---|---|---|
| `routes/web.php`（`auth` + `verified` group 内（L153）、`/billing` 群（L306-311）の直後 = **`require-active-subscription` group（L349）の外**） | `/tmp/aigenba/routes/web.php:441-450` | **D6/D21（route parameter なしの current-org スコープ）**: `GET /onboarding/checkout` → `onboarding.checkout` / `POST /onboarding/activate-personal`（**`->middleware('throttle:10,1')` verbatim**）→ `onboarding.activate-personal` / `GET /billing-required` → `onboarding.billing-required`。aigenba の `prefix('organizations/{organization:slug}')` は移植せず、既存 `billing.index` / `billing.tickets.show` と同一の組織解決にする。route name は既存 `organizations.onboarding.{mcp,cli}`（L293-296。MCP/CLI 導入ガイド = 別責務）と非衝突 |
| `app/Http/Concerns/ResolvesCurrentOrganization.php`（**additive**。既存メソッド無改変） | — | `resolveMemberCurrentOrganization(Request): Organization` を追加。`resolveCurrentOrganization()`（current org 不在 → 404）に続けて **非所属 → 404**（`current_organization_id` が退会後も残存する不整合を**認可より前に 404**。不変条件 #2 = 403 で存在を漏らさない）。aigenba は route binding（`MembershipScopedOrganizationBinder` 相当）が担う層を、current-org スコープに写す際の受け皿 |
| `app/Http/Controllers/Onboarding/OnboardingController.php`（新規） | `app/Http/Controllers/Onboarding/OnboardingController.php` | プラン選択 + Personal 自己申告画面。`show(Request): Response\|RedirectResponse`。**ガード式は verbatim**（`hasActiveAccess()` → `Gate::allows('manage-billing')` の順）。`?plan=` / `IntendedPlanResolver` / `preselectFunding` は移植しない（**P7**） |
| `app/Http/Controllers/Onboarding/ActivatePersonalController.php`（新規） | 同名 | `__invoke(ActivatePersonalRequest): RedirectResponse`。`Gate::authorize('manageBilling')` → `activate()` → `PersonalPlanNotEligibleException` を `ValidationException::withMessages(['plan_code' => $e->userMessage()])` = **422** へ（verbatim）。着地は **`dashboard` 固定**（= aigenba の `$continue === null` 経路と同一。`OnboardingReturnResolver` は **P7**）。`funding_choice` / `consent_version` / `startSetupCheckout` / `setupAttemptToken` は移植しない（**P8a**） |
| `app/Http/Controllers/Onboarding/BillingRequiredController.php`（新規） | 同名 | 未契約 + manage-billing なし member 向け説明画面。`show(Request): Response\|RedirectResponse`。**離脱ガードは verbatim**（`state()->grantsAccess()` → 組織ダッシュボード / `Gate::allows('manage-billing')` → checkout） |
| `app/Http/Requests/Onboarding/ActivatePersonalRequest.php`（新規） | 同名 | `declaration` = `['required', 'accepted']` + `messages()` の 2 文言を verbatim。`funding_choice` / `consent_version` は P8a の additive 追加。`ProhibitsProtectedKeys`（`app/Http/Requests/Concerns/`）を配線し `protectedKeyMissingRules()` を `array_replace` で merge（verbatim。`FormRequestProhibitedKeyTest` 対応）。`authorize(): true`（認可は Controller の `Gate::authorize`） |
| `app/DataTransferObjects/Onboarding/OnboardingCheckoutDto.php`（新規） | 同名 | 下記 shape。**フィールド名は aigenba と同一**にし、P7/P8a/P9 は additive に足すだけにする |
| `app/DataTransferObjects/Onboarding/BillingRequiredDto.php`（新規） | 同名 | `ownerName` / `ownerEmail` / `contactUrl` + `@phpstan-type BillingRequiredShape`（**verbatim**） |
| `app/DataTransferObjects/Billing/PlanDto.php`（新規。AI-CUE に PlanDto は不在） | 同名 | `fromModel(Plan)`。**AI-CUE の実列にのみマップ**（下記） |
| `app/Enums/Inquiry/InquirySource.php`（**additive**） | `InquirySource::Onboarding` | `case Onboarding = 'onboarding';` + `label()` に `'オンボーディング'` を追加（`match ($this)` は case 追加で網羅維持。`normalize()` は `tryFrom` のため allowlist に自動追随） |
| `lang/ja/validation.php` | — | `attributes` に `'declaration' => '個人利用の確認'` を追加（ja 文言規約。`plan_code` は既存） |
| `resources/js/pages/Onboarding/Checkout.svelte`（新規） | 同名（643 行） | **P3 部分のみ移植** = plan grid（`PricingPlanCard` 再利用）+ Personal 自己申告 step。funding 2 択 / 同意 UI（P8a）、intended バッジ / `?choose`（P7）、`attemptToken` 同梱（P9）は移植しない |
| `resources/js/pages/Onboarding/BillingRequired.svelte`（新規） | 同名（53 行） | Owner 連絡先 + 問い合わせ導線。403 ではなく専用ページで「行き先のない詰み」を回避する（本文・文言は verbatim） |
| `resources/js/types/onboarding.ts`（新規） | — | PHP の `@phpstan-type` と exact 対（`types/billing.ts` の既存規約） |

**名前空間の分離（aigenba と同一理由）**: 既存 `App\Http\Controllers\Organizations\OrganizationOnboardingController`（MCP/CLI 手順）と `resources/js/pages/Organizations/Onboarding/*` は**触らない**。課金オンボーディングは `App\Http\Controllers\Onboarding\*` / `resources/js/pages/Onboarding/*` に分離する。

**移植時の adaptation（意味論不変。列・API の所在差の吸収のみ）**

- `ContactUrl::forSource($s)->url` → **`ContactUrl::resolveForSource(InquirySource $s): string`**（AI-CUE の既存 API。`app/Services/Marketing/ContactUrl.php:52`）。
- `TicketService::signupGrantTicketCount()` → **`TicketPricingService::signupGrantTickets(): int`**（`app/Services/Billing/TicketPricingService.php:61`。`config()` 直読みを増やさない）。
- `Role::OrganizationOwner` → **`App\Enums\OrganizationRole::Owner`**、`$u->getOrganizationRole($org)` → **`$u->organizationRole($org)`**。Owner 解決は `Organization::routeNotificationForMail()`（`app/Models/Organization.php:164-172`）と**同一パターン**。
- Gate ability 名: aigenba `'manage-billing'` → **AI-CUE `'manageBilling'`**（`OrganizationPolicy::manageBilling`。既存 `BillingController.php:75,101` と同一）。permission 文字列（`BillingPermissionService::PERMISSION_MANAGE_BILLING = 'manage-billing'`）は P2 の成果物でありここでは触らない。
- redirect 先: `organizations.billing.index` → **`billing.index`** / `organizations.show`（組織ダッシュボード）→ **`dashboard`**（AI-CUE に `organizations.show` は存在せず、組織ダッシュボードは current-org スコープの `/dashboard`）/ `organizations.onboarding.{checkout,billing-required}` → **`onboarding.{checkout,billing-required}`**。
- `Inertia::location(route('organizations.billing.index'))` → **素の `RedirectResponse`**（AI-CUE では `Inertia::location()` は Stripe への外部 full page redirect 専用。内部遷移の意味論は同一）。
- `orderBy('id')` → **`orderBy('sort_order')`**（AI-CUE の表示順の権威列。既存 `BillingController::index`(L43) / `PricingService::listPublicPlans()`(L41) と同一。集合は不変）。
- `->with('currentPrices')` は移植しない（AI-CUE の `Plan::currentPrice(PlanPriceKind)` は relation query を都度発行する実装で eager load が効かない。対象は 3 行のため N+1 の実害なし）。
- `OrganizationDto` は AI-CUE に不在 → **新設しない**。organization props は既存 `OrganizationOnboardingController::organizationProps()` と同形（`{id, name, slug}`）。
- `GuestLayout` → **`AppLayout` + T071 primitive**（`PageContainer` / `PageHeader(Section)` / `PageContent`）。両ページとも `auth` group 内のログイン後ページであり、AI-CUE の外枠規約（arch: page-shell-structure）が parity に優先する（原則 2）。

#### 波及変更

- **TypeScript 型定義**: `resources/js/types/onboarding.ts` 新規（`OnboardingCheckoutShape` / `BillingRequiredShape` / `PlanShape` / `PersonalPlanEligibilityShape`）。`types/billing.ts` / `types/marketing.ts` は**変更なし**（`PurchaseTicketsPageDto` の `ticketAttemptToken` = チケット決済の冪等性契約には**一切触らない**。subscription checkout 用の `subscriptionAttemptToken` は P9 の別型）。
- **DTO**: 新規 `OnboardingCheckoutDto` / `BillingRequiredDto` / `PlanDto`。P1 産出の `PersonalPlanEligibilityDto` を**再利用**（新規作成しない）。**JsonResource は使わない**（Inertia ページ = DTO→`toArray()`。`response()->json()` 直書きなし）。
- **Inertia props**: `Onboarding/Checkout` = `{ organization: {id,name,slug}, pageData: OnboardingCheckoutShape }` / `Onboarding/BillingRequired` = `{ organization, pageData: BillingRequiredShape }`。**既存ページの props 変更なし**。
- **Enum / lang**: `InquirySource::Onboarding` 追加（公開フォームの `source` allowlist が 1 件増える）/ `lang/ja/validation.php` の `attributes.declaration` 追加。
- **Factory / seeder**: `database/factories/OrganizationFactory.php` の `activatedPersonal(User)` / `grandfatheredFree()` state（P1/P2 産出）を再利用。Plan は `PlanSeeder` が真実源（P1 で 4 行すべて `is_active=true`）。`database/factories/Billing/PlanFactory.php` が未作成なら P3 で新設（テストデータ手組み禁止）。
- **DB / migration**: **なし**（P1 の列・index を読むだけ）。
- **テストファイル（新規）**: `tests/Feature/Onboarding/{OnboardingCheckoutTest,ActivatePersonalTest,BillingRequiredTest}.php` / `tests/Unit/DataTransferObjects/Billing/PlanDtoTest.php` / `tests/js/pages/{OnboardingCheckout,OnboardingBillingRequired}.test.ts`。
- **テストファイル（更新）**: **なし**（`RequireActiveSubscriptionMiddlewareTest` / `SeededFreePlanBillingAccessTest` は P4 の更新対象。P3 は `BillingAccess` を読むだけで書き換えないため期待不変）。arch テストは allowlist 追加なしで green: `NestedRouteIdorDefenseTest`（route param 2 個以上が対象 / 本 route は **0 個**）/ `OrganizationRouteParamWebOnlyInvariantTest`（`{organization}` param を持たない）/ `ManageRouteAuthGuardTest`（`/manage/` 配下ではない）/ `FormRequestProhibitedKeyTest`（`ProhibitsProtectedKeys` 配線）/ `MassAssignmentSafetyTest`（新 model なし）/ page-shell-structure・ds-purity・atomic-import-graph・lucide-scoped-import。

#### 主要な契約

```php
// App\Http\Concerns\ResolvesCurrentOrganization （additive。既存メソッドは無改変）
private function resolveMemberCurrentOrganization(Request $request): Organization;
//  current org 不在 → 404 / current org にユーザーが非所属 → 404（いずれも認可より前 = 不変条件 #2）

// App\Http\Controllers\Onboarding\OnboardingController      （Request のみ。Organization 引数なし）
public function show(Request $request): Response|RedirectResponse
{
    $organization = $this->resolveMemberCurrentOrganization($request);   // 404 / 404
    Gate::authorize('view', $organization);                              // verbatim（IDOR 二重防御）

    // verbatim: 判定順序は hasActiveAccess → manageBilling
    // （契約済み non-manager が誤って billing-required に飛ばないよう、先に契約状態を判定する）
    if ($this->access->hasActiveAccess($organization)) {
        return new RedirectResponse(route('billing.index'));
    }
    if (! Gate::allows('manageBilling', $organization)) {
        return new RedirectResponse(route('onboarding.billing-required'));
    }

    /** @var list<PlanDto> $plans */
    $plans = Plan::query()
        ->where('is_active', true)                                       // verbatim（P1 で全 true seed）
        ->whereIn('code', [PlanCode::Personal->value, PlanCode::Starter->value,
                           PlanCode::Standard->value, PlanCode::Business->value])   // verbatim（Enterprise 除外）
        ->orderBy('sort_order')                                          // AI-CUE の表示順列（aigenba: id）
        ->get()->map(static fn (Plan $p): PlanDto => PlanDto::fromModel($p))->values()->all();

    $dto = new OnboardingCheckoutDto(
        plans: $plans,
        recommendedPlanCode: PlanCode::Standard->value,                  // verbatim
        defaultPlanCode: PlanCode::Starter->value,                       // verbatim
        contactUrl: $this->contactUrl->resolveForSource(InquirySource::Onboarding),
        personalEligibility: $this->personalPlan->eligibility($organization, $user),
        signupGrantTickets: $this->ticketPricing->signupGrantTickets(),
    );

    return Inertia::render('Onboarding/Checkout', [
        'organization' => $this->organizationProps($organization),
        'pageData' => $dto->toArray(),
    ]);
}

// App\Http\Controllers\Onboarding\ActivatePersonalController （Request のみ。Organization 引数なし）
public function __invoke(ActivatePersonalRequest $request): RedirectResponse
{
    $organization = $this->resolveMemberCurrentOrganization($request);   // 404 / 404
    Gate::authorize('manageBilling', $organization);                     // 403
    $user = $request->user(); Assert::isInstanceOf($user, User::class);

    try {
        $result = $this->personalPlan->activate($organization, $user);   // P1 完成済み。呼ぶだけ
    } catch (PersonalPlanNotEligibleException $e) {
        throw ValidationException::withMessages(['plan_code' => $e->userMessage()]);   // 422（500 にしない）
    }

    $message = $result->granted
        ? sprintf('パーソナルプラン（無料）を開始しました。無料チケット %d 枚をお付けしました。',
            $this->ticketPricing->signupGrantTickets())
        : 'パーソナルプラン（無料）を開始しました。';

    return redirect()->route('dashboard')->with('success', $message);    // P7 まで dashboard 固定
}

// App\Http\Controllers\Onboarding\BillingRequiredController  （Request のみ。Organization 引数なし）
public function show(Request $request): Response|RedirectResponse
{
    $organization = $this->resolveMemberCurrentOrganization($request);   // 404 / 404
    Gate::authorize('view', $organization);

    // verbatim: 離脱ガード（行き先のない詰みの回避）
    if ($this->access->state($organization)->grantsAccess()) {
        return redirect()->route('dashboard');                           // aigenba: organizations.show
    }
    if (Gate::allows('manageBilling', $organization)) {
        return redirect()->route('onboarding.checkout');
    }

    $owner = $organization->users()->get()
        ->first(static fn (User $u): bool => $u->organizationRole($organization) === OrganizationRole::Owner);

    $dto = new BillingRequiredDto(
        ownerName: $owner instanceof User ? $owner->name : null,
        ownerEmail: $owner instanceof User ? $owner->email : null,
        contactUrl: $this->contactUrl->resolveForSource(InquirySource::Onboarding),
    );

    return Inertia::render('Onboarding/BillingRequired', [...]);
}
```

**入口ガードの判定源（発明しない）**: P3 は `BillingAccess`（`hasActiveAccess()` / `state()`）**のみ**を読む。`isDeclared()` 等の述語は作らない。`OnboardingBillingState` の各状態に対する挙動は下表（`grantsAccess() = Subscribed || ActiveFreePlan`。`plan_code` は判定に使わない）。

| org の状態 | `state()` | `hasActiveAccess()`（P3 = state + 移行 OR） | `onboarding.checkout` | `onboarding.billing-required` |
|---|---|---|---|---|
| active / trialing / past_due の sub | `Subscribed` | true | → `billing.index` | → `dashboard` |
| `free_plan_code='personal'`（P3 の activate 成功後） | `ActiveFreePlan` | true | → `billing.index` | → `dashboard` |
| canceled / unpaid / incomplete / paused の sub | `ExpiredCheckout` | **false** | **200 render**（manage-billing 保持者）/ → `billing-required`（member） | **200 render**（member）/ → `checkout`（manage-billing 保持者） |
| sub 行なし・`plan_code IS NULL`（P3 時点の未契約 org） | `NoSubscription` | **true**（移行 OR） | → `billing.index`（**P4 の OR 削除で 200 へ**） | **200 render**（member。P3 では UI リンクを張らない） |

**DTO 形状（P3 スコープ。フィールド名は aigenba と同一）**

```
OnboardingCheckoutShape = {
  plans: PlanShape[],                   // is_active=true ∧ code ∈ {personal,starter,standard,business}。sort_order 昇順
  recommendedPlanCode: string,          // 'standard' （verbatim）
  defaultPlanCode: string,              // 'starter'  （verbatim）
  contactUrl: string,                   // ContactUrl::resolveForSource(InquirySource::Onboarding)
  personalEligibility: { eligible: boolean; reason: string | null; reasonLabel: string | null } | null,
  signupGrantTickets: number,           // TicketPricingService::signupGrantTickets()
}
PlanShape = { code: string; name: string; currentBaseAmount: number | null; isActive: boolean }
BillingRequiredShape = { ownerName: string | null; ownerEmail: string | null; contactUrl: string }
```

- **`PlanDto` は AI-CUE の実列にのみマップする**: `code` / `name` / `currentBaseAmount`（`Plan::currentPrice(PlanPriceKind::Base)?->amount`）/ `isActive`。aigenba の `includedSeats` / `currentSeatAmount`（席課金）・`scenarioLimit` / `courseLimit`（能力は `config/quota.php` の「値」で表現するのが AI-CUE 規約）は**移植しない**（原則 4）。`includedMonthlyTickets` も**持たない**（**D28 で月次付与は廃止 = 全 tier 0**。P1 で `PricingPlanShape` からも削除済みで整合）。**通貨フィールドは持たない**（AI-CUE の金額契約は `PricingPlanDto::baseAmountJpy` と同じく JPY 固定）。`currentBaseAmount === null` = base price 不在 = **無料表示契約**（`PricingPlanDto` の docblock と同一意味論）。
- **`currentSeatCount` / `starterAutoMigrationDays` は持たない**（席概念なし / Starter 自動移行機構が AI-CUE に無い）。
- **`attemptToken` は持たない**（subscription checkout 用 = **P9**。`ticketAttemptToken` は既存機構で無関係）。`intendedPlanCode` / `preselectFunding` は **P7**、`autoRechargeTerms` は **P8a** の additive 追加。
- **Plan 集合の露出規則は `is_active=true` の単一規則のみ**（P1 で 4 行すべて true = personal / starter / standard が P3 完了時点で並ぶ。business は Plan 行が無いため結果に出ない）。legacy `free` 行は `whereIn` の code 集合外のため出ない（撤去は P4）。`personalEligibility` は常に非 null。
- `defaultPlanCode` / `recommendedPlanCode` は**コード値**であり `plans` への包含を保証しない。フロントは `plans` に該当 code があるときのみ preselect し、無ければ先頭 plan を選択する（`computeInitialPlan` と同型の決定的挙動）。

**route 構造上の帰結**: onboarding route は **route parameter を持たない current-org スコープ**（D6/D21）で、既存 `billing.*` と**同一の組織解決**。「URL の org ≠ current org」が構造的に発生せず cross-org 課金の余地がない。`isCurrentOrganization` prop・組織切替 CTA・org-slug 非対称は存在しない。

**UI 契約**: 両ページとも `AppLayout` + `PageContainer` + `PageHeader(Section)` + `PageContent`（T071 primitive / arch: page-shell-structure）。plan grid は既存 `resources/js/components/molecules/PricingPlanCard.svelte`（+ `PricingPlanCard.types.ts` の `PricingFeature`）を再利用し**新規 molecule を作らない**。アイコンは `@lucide/svelte` の named import のみ、色は DS token のみ（hex 直書き禁止）、import 方向は pages → templates/molecules/atoms。
**D4（AGENTS.md 禁止事項 #8）**: Personal 有効化 CTA は `personalEligibility.eligible=false` でも `declaration` 未チェックでも **disabled にしない**（aigenba の `if (submitting || !declarationChecked) return;` は移植しない）。押下で submit し、サーバ由来の `errors.plan_code` / `errors.declaration` を表示する。`reasonLabel` は理由 caption として常時可視。**文言はすべてサーバ確定**（`PersonalPlanIneligibleReason::label()` / `ActivatePersonalRequest::messages()`）でフロントは組み立てない。eligibility は render 後に変化しうるため**サーバ判定が唯一の権威**。

#### PHPStan 適合チェック

- Controller 戻り値は `show(): Response|RedirectResponse` / `__invoke(): RedirectResponse`。内部遷移に `Inertia::location()` を使わないため `SymfonyResponse` は union に不要。**`response()->json()` 不使用**。
- `$request->user()` は `Assert::isInstanceOf($user, User::class)` で narrowing（aigenba `requestUser()` と同型 / 既存 `ResolvesCurrentOrganization` と同作法）。`abort_if` に型絞りを依存させない。
- `resolveMemberCurrentOrganization()` は `Organization` を返す（`$user->currentOrganization` の `Organization|null` は `abort_if(... === null, 404)` で解決済み）。
- `list<PlanDto>` を保つため `->map(...)->values()->all()`（`values()` を省くと `array<int, PlanDto>` に落ちて level 10 が落ちる）。`whereIn` に渡す `PlanCode::…->value` の配列は `list<string>`。
- `Plan::query()->where('is_active', true)` は **P1 で `is_active` を列 + `casts()` + `@property bool $is_active` に追加済み**のため larastan の property 解決が通る。
- DTO はすべて `final readonly` + `@phpstan-type ...Shape` + `toArray(): ...Shape`。`OnboardingCheckoutDto` は `@phpstan-import-type PlanDtoShape from PlanDto` / `PersonalPlanEligibilityShape from PersonalPlanEligibilityDto` を import（aigenba verbatim の作法）。
- `Plan::currentPrice(PlanPriceKind::Base)` は `?PlanPrice` → `$plan->currentPrice(PlanPriceKind::Base)?->amount` で `int|null` に落とす（DTO 側が nullable を型で表明）。
- Owner 解決 `->first(static fn (User $u): bool => $u->organizationRole($organization) === OrganizationRole::Owner)` は `?User` → `$owner instanceof User ? $owner->name : null`（`routeNotificationForMail()` と同形。`?->` で握り潰さない）。
- `TicketPricingService::signupGrantTickets(): int` / `PersonalPlanNotEligibleException::userMessage(): string`（P1 産出）を使い、`config()` の `mixed` 直読みを増やさない。
- `InquirySource::label()` の `match ($this)` は case 追加で網羅維持（`identical.alwaysFalse` は発生しない）。`PlanCode` は 5 case で静的に閉じるため `whereIn` の列挙も定数解決される。
- `BillingAccess::state()` は `OnboardingBillingState` を返し `grantsAccess()` は `bool`（P2 契約）。P3 は enum を分岐に使わず `bool` としてのみ消費するため `match` 網羅義務は生じない。
- **baseline 化 / 型 widen は行わない**（禁止事項 #2）。

#### テスト計画

**先に red を作る（新規 Feature）**

1. `tests/Feature/Onboarding/OnboardingCheckoutTest.php`
   - **current org 不在 → 404** / **current org 非所属（`current_organization_id` 残存の退会ユーザー）→ 404**（403 にしない = 存在秘匿）。**同一の 404 テストを 3 route すべてに置く**（不変条件 #2 の網羅）。
   - **`ExpiredCheckout` の org（`plan_code` 非 null + canceled sub）+ manageBilling → 200 render**: `pageData.plans` は `is_active=true` ∧ code ∈ {personal,starter,standard,business} のみで **legacy `free` 行を含まない** / `sort_order` 昇順 / `recommendedPlanCode === 'standard'` / `defaultPlanCode === 'starter'` / `signupGrantTickets === TicketPricingService::signupGrantTickets()` / `contactUrl` が `source=onboarding` 付き / `personalEligibility` が**非 null**（P1 で personal は `is_active=true`）。
   - 同条件 + 非 manageBilling member → `onboarding.billing-required` へ redirect（**判定順序 verbatim の固定**）。
   - `Subscribed`（active sub）→ `billing.index` / `ActiveFreePlan`（`free_plan_code='personal'`）→ `billing.index`。
   - **移行 OR の窓の固定（P4 で期待が反転する箇所を明示）**: `plan_code IS NULL` ∧ `free_plan_code IS NULL` ∧ sub なしの org → **`billing.index` へ redirect**（= P3 の事実）。テスト名に「**P4 の移行 OR 削除で 200 render へ変わる**」と明記し、P4 のテスト計画で期待を更新する（削除しない）。
   - `is_active=false` に落とした Plan は `pageData.plans` に出ない（露出規則の固定）。
2. `tests/Feature/Onboarding/ActivatePersonalTest.php`
   - current org 不在 → 404 / 非所属 → 404 / manageBilling なし member → **403**。
   - `declaration` 未チェック → redirect-back + `errors.declaration`（XHR は 422）。
   - 成功 → `free_plan_code='personal'` / `personal_declared_by_user_id` = declarer / `signup_tickets_granted_at` 設定 / signup grant 付与（P1 の marker 経路）/ **`dashboard` へ redirect** + success flash（枚数入り文言）/ 直後の `state()` が `ActiveFreePlan`。
   - **二重 POST 冪等**: 2 回目は `granted=false` 側の文言で `ticket_ledger_entries` の `signup_grant:%` は **1 行のまま**（P1 marker の回帰）。
   - eligibility 不成立（別 free personal org 保有 / メンバー 4 名 / 有効 subscription あり）→ redirect-back + `errors.plan_code` に**サーバ確定文言**（`PersonalPlanNotEligibleException` が **500 にならない** = 422 相当）。
   - `throttle:10,1` が効く（11 回目 429）。
3. `tests/Feature/Onboarding/BillingRequiredTest.php`
   - current org 不在 → 404 / 非所属 → 404。
   - `grantsAccess()`（active sub / `free_plan_code='personal'`）→ `dashboard` / manageBilling 保持者 → `onboarding.checkout`（**離脱ガード = 行き先のない詰みの回避**）。
   - `ExpiredCheckout` の一般 member → 200 render + `ownerName` / `ownerEmail` / `contactUrl`。
   - **非対称の窓の固定**: `plan_code IS NULL` の一般 member も **200 render**（`state()` は移行 OR を持たない）。テスト名に「P4 で grandfathering backfill 後は `ActiveFreePlan` → `dashboard` へ変わる」と明記（P4 で期待更新）。
   - Owner 不在 org でも 200 で `ownerName` / `ownerEmail` が null（`routeNotificationForMail` と同じ null 許容）。
4. `tests/Unit/DataTransferObjects/Billing/PlanDtoTest.php`

## 実装差分（git diff）

```diff
diff --git a/app/DataTransferObjects/Billing/PlanDto.php b/app/DataTransferObjects/Billing/PlanDto.php
new file mode 100644
index 0000000..08ae8f6
--- /dev/null
+++ b/app/DataTransferObjects/Billing/PlanDto.php
@@ -0,0 +1,60 @@
+<?php
+
+declare(strict_types=1);
+
+namespace App\DataTransferObjects\Billing;
+
+use App\DataTransferObjects\Marketing\PricingPlanDto;
+use App\Enums\Billing\PlanPriceKind;
+use App\Models\Billing\Plan;
+
+/**
+ * プラン 1 件分 (Billing 内部 DTO)。料金表専用の {@see PricingPlanDto}
+ * とは責務分離する (こちらはログイン後のオンボーディング / 課金画面用)。
+ *
+ * currentBaseAmount の契約: null = plan_prices (base) を持たない = Checkout 対象外の
+ * **無料表示** (PricingPlanDto::baseAmountJpy と同一意味論)。通貨は JPY 固定のため
+ * 通貨フィールドを持たない (AI-CUE の金額契約)。
+ *
+ * 月次付与枚数は持たない (D28: 月次付与は廃止 = 全 tier 0)。能力値 (プロジェクト数等) は
+ * config/quota.php の「値」で表現する規約のため DTO には載せない。
+ *
+ * @phpstan-type PlanDtoShape array{
+ *   code: string,
+ *   name: string,
+ *   currentBaseAmount: int|null,
+ *   isActive: bool
+ * }
+ */
+final readonly class PlanDto
+{
+    public function __construct(
+        public string $code,
+        public string $name,
+        public ?int $currentBaseAmount,
+        public bool $isActive,
+    ) {}
+
+    public static function fromModel(Plan $plan): self
+    {
+        return new self(
+            code: $plan->code,
+            name: $plan->name,
+            currentBaseAmount: $plan->currentPrice(PlanPriceKind::Base)?->amount,
+            isActive: $plan->is_active,
+        );
+    }
+
+    /**
+     * @return PlanDtoShape
+     */
+    public function toArray(): array
+    {
+        return [
+            'code' => $this->code,
+            'name' => $this->name,
+            'currentBaseAmount' => $this->currentBaseAmount,
+            'isActive' => $this->isActive,
+        ];
+    }
+}
diff --git a/app/DataTransferObjects/Onboarding/BillingRequiredDto.php b/app/DataTransferObjects/Onboarding/BillingRequiredDto.php
new file mode 100644
index 0000000..5788ce5
--- /dev/null
+++ b/app/DataTransferObjects/Onboarding/BillingRequiredDto.php
@@ -0,0 +1,38 @@
+<?php
+
+declare(strict_types=1);
+
+namespace App\DataTransferObjects\Onboarding;
+
+/**
+ * 未契約 + manageBilling 権限なし member 向け説明画面の props。
+ *
+ * ownerName / ownerEmail は Owner 不在 org でも描画が壊れないよう null 許容
+ * (Organization::routeNotificationForMail() と同じ null 許容)。
+ *
+ * @phpstan-type BillingRequiredShape array{
+ *   ownerName: string|null,
+ *   ownerEmail: string|null,
+ *   contactUrl: string
+ * }
+ */
+final readonly class BillingRequiredDto
+{
+    public function __construct(
+        public ?string $ownerName,
+        public ?string $ownerEmail,
+        public string $contactUrl,
+    ) {}
+
+    /**
+     * @return BillingRequiredShape
+     */
+    public function toArray(): array
+    {
+        return [
+            'ownerName' => $this->ownerName,
+            'ownerEmail' => $this->ownerEmail,
+            'contactUrl' => $this->contactUrl,
+        ];
+    }
+}
diff --git a/app/DataTransferObjects/Onboarding/OnboardingCheckoutDto.php b/app/DataTransferObjects/Onboarding/OnboardingCheckoutDto.php
new file mode 100644
index 0000000..ce103db
--- /dev/null
+++ b/app/DataTransferObjects/Onboarding/OnboardingCheckoutDto.php
@@ -0,0 +1,62 @@
+<?php
+
+declare(strict_types=1);
+
+namespace App\DataTransferObjects\Onboarding;
+
+use App\DataTransferObjects\Billing\PersonalPlanEligibilityDto;
+use App\DataTransferObjects\Billing\PlanDto;
+
+/**
+ * 登録直後の Plan 選択 + Personal (free) 自己申告画面の props。
+ *
+ * recommendedPlanCode / defaultPlanCode は**コード値**であり `plans` への包含を保証しない
+ * (フロントは該当 code があるときのみ preselect し、無ければ先頭 plan を選ぶ)。
+ * personalEligibility の表示文言はサーバー側 enum で確定する (frontend に文言を散らさない)。
+ *
+ * @phpstan-import-type PlanDtoShape from PlanDto
+ * @phpstan-import-type PersonalPlanEligibilityShape from PersonalPlanEligibilityDto
+ *
+ * @phpstan-type OnboardingCheckoutShape array{
+ *   plans: list<PlanDtoShape>,
+ *   recommendedPlanCode: string,
+ *   defaultPlanCode: string,
+ *   contactUrl: string,
+ *   personalEligibility: PersonalPlanEligibilityShape|null,
+ *   signupGrantTickets: int
+ * }
+ */
+final readonly class OnboardingCheckoutDto
+{
+    /**
+     * @param  list<PlanDto>  $plans  is_active=true ∧ Checkout 対象 code のみ。sort_order 昇順
+     * @param  PersonalPlanEligibilityDto|null  $personalEligibility  Personal (free) の選択可否 + 不可理由
+     * @param  int  $signupGrantTickets  無料開始 callout 用 (初回無償チケット枚数)
+     */
+    public function __construct(
+        public array $plans,
+        public string $recommendedPlanCode,
+        public string $defaultPlanCode,
+        public string $contactUrl,
+        public ?PersonalPlanEligibilityDto $personalEligibility = null,
+        public int $signupGrantTickets = 10,
+    ) {}
+
+    /**
+     * @return OnboardingCheckoutShape
+     */
+    public function toArray(): array
+    {
+        return [
+            'plans' => array_map(
+                static fn (PlanDto $p): array => $p->toArray(),
+                $this->plans,
+            ),
+            'recommendedPlanCode' => $this->recommendedPlanCode,
+            'defaultPlanCode' => $this->defaultPlanCode,
+            'contactUrl' => $this->contactUrl,
+            'personalEligibility' => $this->personalEligibility?->toArray(),
+            'signupGrantTickets' => $this->signupGrantTickets,
+        ];
+    }
+}
diff --git a/app/Enums/Inquiry/InquirySource.php b/app/Enums/Inquiry/InquirySource.php
index 00ff2b4..9bf3444 100644
--- a/app/Enums/Inquiry/InquirySource.php
+++ b/app/Enums/Inquiry/InquirySource.php
@@ -16,6 +16,7 @@ enum InquirySource: string
     case Landing = 'landing';
     case Billing = 'billing';
     case Pricing = 'pricing';
+    case Onboarding = 'onboarding';
 
     public function label(): string
     {
@@ -23,6 +24,7 @@ public function label(): string
             self::Landing => 'トップページ',
             self::Billing => '請求画面',
             self::Pricing => '料金プラン',
+            self::Onboarding => 'オンボーディング',
         };
     }
 
diff --git a/app/Http/Concerns/ResolvesCurrentOrganization.php b/app/Http/Concerns/ResolvesCurrentOrganization.php
index b5e9b9e..a201e9d 100644
--- a/app/Http/Concerns/ResolvesCurrentOrganization.php
+++ b/app/Http/Concerns/ResolvesCurrentOrganization.php
@@ -32,6 +32,31 @@ private function resolveCurrentOrganization(Request $request): Organization
         return $organization;
     }
 
+    /**
+     * current org 解決 + 在籍 guard。current org が未設定なら 404、解決できても
+     * **ユーザーがその org に非所属なら 404** (`current_organization_id` が退会後も
+     * 残存する不整合を、**認可より前に** 存在しないリソースとして落とす = 不変条件 #2。
+     * 403 で org の存在を漏らさない)。
+     *
+     * 組織 route (`/organizations/{organization:slug}/...`) では
+     * MembershipScopedOrganizationBinder の route binding がこの層を担う。本メソッドは
+     * その責務を current-org スコープ (URL に org セグメントを持たない route) へ写した受け皿。
+     */
+    private function resolveMemberCurrentOrganization(Request $request): Organization
+    {
+        $organization = $this->resolveCurrentOrganization($request);
+
+        $user = $request->user();
+        Assert::isInstanceOf($user, User::class);
+
+        abort_unless(
+            $organization->users()->whereKey($user->getKey())->exists(),
+            404,
+        );
+
+        return $organization;
+    }
+
     /**
      * URL 整合 guard (D2 不変条件): URL 上の {project} が current org に属さなければ
      * **認可より前に 404** (403 で存在を漏らさない / cross-org は 404)。
diff --git a/app/Http/Controllers/Onboarding/ActivatePersonalController.php b/app/Http/Controllers/Onboarding/ActivatePersonalController.php
new file mode 100644
index 0000000..8741ba6
--- /dev/null
+++ b/app/Http/Controllers/Onboarding/ActivatePersonalController.php
@@ -0,0 +1,59 @@
+<?php
+
+declare(strict_types=1);
+
+namespace App\Http\Controllers\Onboarding;
+
+use App\Exceptions\Billing\PersonalPlanNotEligibleException;
+use App\Http\Concerns\ResolvesCurrentOrganization;
+use App\Http\Controllers\Controller;
+use App\Http\Requests\Onboarding\ActivatePersonalRequest;
+use App\Models\User;
+use App\Services\Billing\PersonalPlanService;
+use App\Services\Billing\TicketPricingService;
+use Illuminate\Http\RedirectResponse;
+use Illuminate\Support\Facades\Gate;
+use Illuminate\Validation\ValidationException;
+use Webmozart\Assert\Assert;
+
+/**
+ * Personal (free) プランの有効化エンドポイント (current org スコープ)。
+ *
+ * Stripe checkout を通らず、自己申告チェック + business invariant (PersonalPlanService) で
+ * 即時に利用開始する。付与ロジックは PersonalPlanService::activate() が単一の真実源で、
+ * 本 Controller は呼ぶだけ (二重付与源を作らない)。
+ */
+final class ActivatePersonalController extends Controller
+{
+    use ResolvesCurrentOrganization;
+
+    public function __construct(
+        private readonly PersonalPlanService $personalPlan,
+        private readonly TicketPricingService $ticketPricing,
+    ) {}
+
+    public function __invoke(ActivatePersonalRequest $request): RedirectResponse
+    {
+        $organization = $this->resolveMemberCurrentOrganization($request);
+        Gate::authorize('manageBilling', $organization);
+
+        $user = $request->user();
+        Assert::isInstanceOf($user, User::class);
+
+        try {
+            $result = $this->personalPlan->activate($organization, $user);
+        } catch (PersonalPlanNotEligibleException $e) {
+            // 条件不成立は 500 にせず 422 (文言はサーバー側 enum が確定)
+            throw ValidationException::withMessages(['plan_code' => $e->userMessage()]);
+        }
+
+        $message = $result->granted
+            ? sprintf(
+                'パーソナルプラン（無料）を開始しました。無料チケット %d 枚をお付けしました。',
+                $this->ticketPricing->signupGrantTickets(),
+            )
+            : 'パーソナルプラン（無料）を開始しました。';
+
+        return redirect()->route('dashboard')->with('success', $message);
+    }
+}
diff --git a/app/Http/Controllers/Onboarding/BillingRequiredController.php b/app/Http/Controllers/Onboarding/BillingRequiredController.php
new file mode 100644
index 0000000..ede29a1
--- /dev/null
+++ b/app/Http/Controllers/Onboarding/BillingRequiredController.php
@@ -0,0 +1,82 @@
+<?php
+
+declare(strict_types=1);
+
+namespace App\Http\Controllers\Onboarding;
+
+use App\DataTransferObjects\Onboarding\BillingRequiredDto;
+use App\Enums\Inquiry\InquirySource;
+use App\Enums\OrganizationRole;
+use App\Http\Concerns\ResolvesCurrentOrganization;
+use App\Http\Controllers\Controller;
+use App\Models\Organization;
+use App\Models\User;
+use App\Services\Billing\BillingAccess;
+use App\Services\Marketing\ContactUrl;
+use Illuminate\Http\RedirectResponse;
+use Illuminate\Http\Request;
+use Illuminate\Support\Facades\Gate;
+use Inertia\Inertia;
+use Inertia\Response;
+
+/**
+ * 未契約 + manageBilling 権限なしの member 向け説明画面 (current org スコープ)。
+ *
+ * 「組織管理者が課金手続きを完了するのをお待ちください」と Owner 連絡先を表示する。
+ * 403 ではなく専用 Inertia ページで「行き先のない無限ループ」を回避する。
+ */
+final class BillingRequiredController extends Controller
+{
+    use ResolvesCurrentOrganization;
+
+    public function __construct(
+        private readonly BillingAccess $access,
+        private readonly ContactUrl $contactUrl,
+    ) {}
+
+    public function show(Request $request): Response|RedirectResponse
+    {
+        $organization = $this->resolveMemberCurrentOrganization($request);
+        // IDOR 二重防御
+        Gate::authorize('view', $organization);
+
+        // 離脱ガード。billing-required は「未契約 かつ manageBilling なし member」専用の
+        // 説明画面。それ以外がここに来たら本来の行き先へ逃がし「行き先のない詰み」を回避する。
+        //   - 既に利用可 (有効 subscription / free personal) → 見せる理由なし → ダッシュボードへ
+        //   - manageBilling 保持者 (owner / admin / 個別付与 member) → 自分で手続き可 → checkout へ
+        if ($this->access->state($organization)->grantsAccess()) {
+            return redirect()->route('dashboard');
+        }
+        if (Gate::allows('manageBilling', $organization)) {
+            return redirect()->route('onboarding.checkout');
+        }
+
+        // Owner をロール経由で解決 (組織のメンバー数は通常 数〜数十なので filter で十分。
+        // Organization::routeNotificationForMail() と同一パターン)。
+        $owner = $organization->users()->get()
+            ->first(static fn (User $u): bool => $u->organizationRole($organization) === OrganizationRole::Owner);
+
+        $dto = new BillingRequiredDto(
+            ownerName: $owner instanceof User ? $owner->name : null,
+            ownerEmail: $owner instanceof User ? $owner->email : null,
+            contactUrl: $this->contactUrl->resolveForSource(InquirySource::Onboarding),
+        );
+
+        return Inertia::render('Onboarding/BillingRequired', [
+            'organization' => $this->organizationProps($organization),
+            'pageData' => $dto->toArray(),
+        ]);
+    }
+
+    /**
+     * @return array{id: int, name: string, slug: string}
+     */
+    private function organizationProps(Organization $organization): array
+    {
+        return [
+            'id' => $organization->id,
+            'name' => $organization->name,
+            'slug' => $organization->slug,
+        ];
+    }
+}
diff --git a/app/Http/Controllers/Onboarding/OnboardingController.php b/app/Http/Controllers/Onboarding/OnboardingController.php
new file mode 100644
index 0000000..67b7fa1
--- /dev/null
+++ b/app/Http/Controllers/Onboarding/OnboardingController.php
@@ -0,0 +1,113 @@
+<?php
+
+declare(strict_types=1);
+
+namespace App\Http\Controllers\Onboarding;
+
+use App\DataTransferObjects\Billing\PlanDto;
+use App\DataTransferObjects\Onboarding\OnboardingCheckoutDto;
+use App\Enums\Inquiry\InquirySource;
+use App\Enums\PlanCode;
+use App\Http\Concerns\ResolvesCurrentOrganization;
+use App\Http\Controllers\Controller;
+use App\Models\Billing\Plan;
+use App\Models\Organization;
+use App\Models\User;
+use App\Services\Billing\BillingAccess;
+use App\Services\Billing\PersonalPlanService;
+use App\Services\Billing\TicketPricingService;
+use App\Services\Marketing\ContactUrl;
+use Illuminate\Http\RedirectResponse;
+use Illuminate\Http\Request;
+use Illuminate\Support\Facades\Gate;
+use Inertia\Inertia;
+use Inertia\Response;
+use Webmozart\Assert\Assert;
+
+/**
+ * 登録直後 → Plan 選択 + Personal (free) 自己申告画面 (current org スコープ)。
+ *
+ * `Onboarding` 配下に置く理由: 既存 `Organizations\OrganizationOnboardingController` は
+ * MCP/CLI 導入ガイド用の別責務。命名衝突を避けるため階層を分けた。
+ */
+final class OnboardingController extends Controller
+{
+    use ResolvesCurrentOrganization;
+
+    public function __construct(
+        private readonly BillingAccess $access,
+        private readonly PersonalPlanService $personalPlan,
+        private readonly TicketPricingService $ticketPricing,
+        private readonly ContactUrl $contactUrl,
+    ) {}
+
+    public function show(Request $request): Response|RedirectResponse
+    {
+        $organization = $this->resolveMemberCurrentOrganization($request);
+        // IDOR 二重防御 (member 認可を最優先)
+        Gate::authorize('view', $organization);
+
+        $user = $request->user();
+        Assert::isInstanceOf($user, User::class);
+
+        // 判定順序は hasActiveAccess → manageBilling。契約済み non-manager が誤って
+        // billing-required に飛ばないよう、先に契約状態を判定する。
+        if ($this->access->hasActiveAccess($organization)) {
+            return new RedirectResponse(route('billing.index'));
+        }
+
+        // 未契約 + manageBilling 権限なし → billing-required へ
+        if (! Gate::allows('manageBilling', $organization)) {
+            return new RedirectResponse(route('onboarding.billing-required'));
+        }
+
+        $dto = new OnboardingCheckoutDto(
+            plans: $this->selectablePlans(),
+            recommendedPlanCode: PlanCode::Standard->value,
+            defaultPlanCode: PlanCode::Starter->value,
+            contactUrl: $this->contactUrl->resolveForSource(InquirySource::Onboarding),
+            personalEligibility: $this->personalPlan->eligibility($organization, $user),
+            signupGrantTickets: $this->ticketPricing->signupGrantTickets(),
+        );
+
+        return Inertia::render('Onboarding/Checkout', [
+            'organization' => $this->organizationProps($organization),
+            'pageData' => $dto->toArray(),
+        ]);
+    }
+
+    /**
+     * 選択可能なプラン。公開規則は `is_active=true` の単一規則 (PricingService と同一)。
+     * Enterprise はお問い合わせ営業のため除外する (Checkout を通らない)。
+     *
+     * @return list<PlanDto>
+     */
+    private function selectablePlans(): array
+    {
+        // list<PlanDto> は array_values で確定する (PricingService::listPublicPlans と同作法)
+        return array_values(Plan::query()
+            ->where('is_active', true)
+            ->whereIn('code', [
+                PlanCode::Personal->value,
+                PlanCode::Starter->value,
+                PlanCode::Standard->value,
+                PlanCode::Business->value,
+            ])
+            ->orderBy('sort_order')
+            ->get()
+            ->map(static fn (Plan $p): PlanDto => PlanDto::fromModel($p))
+            ->all());
+    }
+
+    /**
+     * @return array{id: int, name: string, slug: string}
+     */
+    private function organizationProps(Organization $organization): array
+    {
+        return [
+            'id' => $organization->id,
+            'name' => $organization->name,
+            'slug' => $organization->slug,
+        ];
+    }
+}
diff --git a/app/Http/Requests/Onboarding/ActivatePersonalRequest.php b/app/Http/Requests/Onboarding/ActivatePersonalRequest.php
new file mode 100644
index 0000000..6b72e65
--- /dev/null
+++ b/app/Http/Requests/Onboarding/ActivatePersonalRequest.php
@@ -0,0 +1,47 @@
+<?php
+
+declare(strict_types=1);
+
+namespace App\Http\Requests\Onboarding;
+
+use App\Http\Requests\Concerns\ProhibitsProtectedKeys;
+use Illuminate\Foundation\Http\FormRequest;
+
+/**
+ * Personal (free) プラン有効化のリクエスト。
+ *
+ * declaration = 「個人利用であり、法人・チームでの利用ではない」自己申告チェック (必須)。
+ * 認可 (manageBilling) は Controller 冒頭の Gate::authorize が担う。
+ * mutating (organizations の free entitlement を書く) ため ProhibitsProtectedKeys を配線する
+ * (所有権キー = personal_declared_by_user_id 等は Auth から導出し payload 非受理)。
+ */
+final class ActivatePersonalRequest extends FormRequest
+{
+    use ProhibitsProtectedKeys;
+
+    public function authorize(): bool
+    {
+        return true;
+    }
+
+    /**
+     * @return array<string, mixed>
+     */
+    public function rules(): array
+    {
+        return array_replace([
+            'declaration' => ['required', 'accepted'],
+        ], $this->protectedKeyMissingRules());
+    }
+
+    /**
+     * @return array<string, string>
+     */
+    public function messages(): array
+    {
+        return [
+            'declaration.required' => '個人利用であることの確認が必要です。',
+            'declaration.accepted' => '個人利用であることの確認が必要です。',
+        ];
+    }
+}
diff --git a/lang/ja/validation.php b/lang/ja/validation.php
index 348d343..731e771 100644
--- a/lang/ja/validation.php
+++ b/lang/ja/validation.php
@@ -209,6 +209,7 @@
         'abilities.*' => '権限',
         // --- 課金 ---
         'plan_code' => 'プラン',
+        'declaration' => '個人利用の確認',
         'count' => '購入枚数',
         'attempt_token' => '操作トークン',
         // --- プロジェクト・マニュアル ---
diff --git a/resources/js/pages/Onboarding/BillingRequired.svelte b/resources/js/pages/Onboarding/BillingRequired.svelte
new file mode 100644
index 0000000..d1a4b92
--- /dev/null
+++ b/resources/js/pages/Onboarding/BillingRequired.svelte
@@ -0,0 +1,73 @@
+<script lang="ts">
+    import { page as inertiaPage } from "@inertiajs/svelte";
+    import { Clock } from "@lucide/svelte";
+    import Card from "@/components/atoms/Card.svelte";
+    import PageHeader from "@/components/molecules/PageHeader.svelte";
+    import AppLayout from "@/components/templates/AppLayout.svelte";
+    import PageContainer from "@/components/templates/PageContainer.svelte";
+    import PageContent from "@/components/templates/PageContent.svelte";
+    import type { SharedProps } from "@/lib/shared-props";
+    import type { BillingRequiredShape, OnboardingOrganizationShape } from "@/types/onboarding";
+
+    /**
+     * 課金手続き待ちの説明画面 (課金権限を持たないメンバーの着地先)。
+     *
+     * 403 で突き放さず、組織管理者の連絡先と問い合わせ導線を提示して
+     * 「行き先のない詰み」を回避する (owner 不在 org では連絡先が null になりうる)。
+     */
+    interface Props {
+        organization: OnboardingOrganizationShape;
+        pageData: BillingRequiredShape;
+    }
+
+    let { organization, pageData }: Props = $props();
+
+    const shared = $derived(inertiaPage.props as unknown as SharedProps);
+    const appName = $derived(shared.appName ?? "");
+</script>
+
+<AppLayout {appName}>
+    <PageContainer>
+        <PageHeader
+            title="課金手続き中です"
+            icon={Clock}
+            testId="billing-required-heading"
+        />
+        <PageContent>
+            <div class="flex flex-col gap-6" data-testid="onboarding-billing-required">
+                <p class="text-body text-text-secondary" data-testid="billing-required-message">
+                    <strong class="text-text">{organization.name}</strong>
+                    はまだ有料プランの契約が完了していません。 組織管理者が課金手続きを完了するのをお待ちください。
+                </p>
+
+                {#if pageData.ownerName !== null}
+                    <Card padding="lg" testId="billing-required-owner">
+                        <p class="text-caption text-text-secondary">組織管理者</p>
+                        <p class="mt-1 text-body text-text">{pageData.ownerName}</p>
+                        {#if pageData.ownerEmail !== null}
+                            <p class="text-caption text-text-secondary">
+                                <a
+                                    href={`mailto:${pageData.ownerEmail}`}
+                                    class="text-primary underline"
+                                    data-testid="billing-required-owner-email"
+                                >
+                                    {pageData.ownerEmail}
+                                </a>
+                            </p>
+                        {/if}
+                    </Card>
+                {/if}
+
+                <p class="text-caption text-text-secondary">
+                    <!-- contactUrl は内部 path / 外部 URL / mailto のいずれにもなりうる (ContactUrl が
+                         解決する) ため、素の <a> で全パターンを同じ扱いにする (Welcome と同規約)。 -->
+                    ご不明点は <a
+                        href={pageData.contactUrl}
+                        class="text-primary underline"
+                        data-testid="billing-required-contact-link">お問い合わせ</a
+                    > ください。
+                </p>
+            </div>
+        </PageContent>
+    </PageContainer>
+</AppLayout>
diff --git a/resources/js/pages/Onboarding/Checkout.svelte b/resources/js/pages/Onboarding/Checkout.svelte
new file mode 100644
index 0000000..d782b0e
--- /dev/null
+++ b/resources/js/pages/Onboarding/Checkout.svelte
@@ -0,0 +1,262 @@
+<script lang="ts">
+    import { page as inertiaPage, router } from "@inertiajs/svelte";
+    import { CreditCard } from "@lucide/svelte";
+    import Alert from "@/components/atoms/Alert.svelte";
+    import Button from "@/components/atoms/Button.svelte";
+    import Checkbox from "@/components/atoms/Checkbox.svelte";
+    import PricingPlanCard from "@/components/molecules/PricingPlanCard.svelte";
+    import type { PricingFeature } from "@/components/molecules/PricingPlanCard.types";
+    import PageHeader from "@/components/molecules/PageHeader.svelte";
+    import AppLayout from "@/components/templates/AppLayout.svelte";
+    import PageContainer from "@/components/templates/PageContainer.svelte";
+    import PageContent from "@/components/templates/PageContent.svelte";
+    import type { SharedProps } from "@/lib/shared-props";
+    import type {
+        OnboardingCheckoutShape,
+        OnboardingOrganizationShape,
+        PlanShape,
+    } from "@/types/onboarding";
+
+    /**
+     * 課金オンボーディング: プラン選択 (current org スコープ)。
+     *
+     * - plan grid は PricingPlanCard を再利用する (プラン名・基本料金はサーバ確定値)。
+     * - Personal (無料) は Stripe checkout を通らず activate-personal へ POST する
+     *   (自己申告チェック = declaration が必須。サーバ FormRequest が権威)。
+     * - 有償プランは既存の billing.checkout へ POST する (Stripe Checkout へ full page redirect)。
+     * - ボタンは disabled にしない (DESIGN.md / AGENTS.md 禁止事項 #8)。eligibility 不成立・
+     *   declaration 未チェックでも押下でき、サーバが返した文言をそのまま表示する
+     *   (eligibility は render 後に変化しうるため サーバ判定が唯一の権威)。
+     * - 文言はすべてサーバ確定 (reasonLabel / errors) で frontend では組み立てない。
+     */
+    interface Props {
+        organization: OnboardingOrganizationShape;
+        pageData: OnboardingCheckoutShape;
+    }
+
+    let { organization, pageData }: Props = $props();
+
+    const shared = $derived(inertiaPage.props as unknown as SharedProps);
+    const appName = $derived(shared.appName ?? "");
+    const serverErrors = $derived((inertiaPage.props.errors ?? {}) as Record<string, string>);
+
+    // defaultPlanCode は plans への包含を保証しない (コード値) ため、plans にある場合のみ
+    // preselect し、無ければ先頭 plan を強調する (決定的挙動)。
+    const computeInitialPlan = (data: OnboardingCheckoutShape): string | null =>
+        data.plans.some((p) => p.code === data.defaultPlanCode)
+            ? data.defaultPlanCode
+            : (data.plans[0]?.code ?? null);
+
+    // writable $derived: props から初期強調を導出しつつ、ユーザーのカード選択で上書きできる。
+    let selectedPlanCode = $derived(computeInitialPlan(pageData));
+    let chosenPlanCode = $state<string | null>(null);
+    let submitting = $state(false);
+    let declarationChecked = $state(false);
+
+    // サーバ由来エラーを「発生したプラン」にキー付けし、別プランへ切替えると旧エラーが消える。
+    let lastSubmittedPlanCode = $state<string | null>(null);
+
+    const isPersonal = (plan: PlanShape): boolean => plan.code === "personal";
+
+    // 表示寿命を「現在選択中プラン」に結合する: in-flight 中は非表示 (再 submit 時の旧エラー
+    // フラッシュ防止) + submit したプランを選択中のときだけ表示。
+    const planCodeError = $derived(
+        !submitting && chosenPlanCode !== null && chosenPlanCode === lastSubmittedPlanCode
+            ? (serverErrors.plan_code ?? null)
+            : null,
+    );
+    const declarationError = $derived(!submitting ? (serverErrors.declaration ?? null) : null);
+
+    // Personal が選べない理由 (サーバー確定文言)。押下は妨げず、理由を常時提示する。
+    const personalReasonLabel = $derived(
+        pageData.personalEligibility !== null && !pageData.personalEligibility.eligible
+            ? pageData.personalEligibility.reasonLabel
+            : null,
+    );
+
+    const buildFeatures = (plan: PlanShape): PricingFeature[] => {
+        if (!isPersonal(plan)) {
+            return [];
+        }
+
+        // 月次のチケット付与は廃止済 (常に 0 枚) のため表記しない (料金ページと同一方針)。
+        return [
+            { text: "基本料金なし（トレーニングに使うチケット代のみ）" },
+            {
+                text: "個人利用専用です。法人・チームでのご利用は Starter プラン以上をお選びください",
+            },
+        ];
+    };
+
+    const choosePlan = (plan: PlanShape): void => {
+        chosenPlanCode = plan.code;
+        selectedPlanCode = plan.code;
+    };
+
+    // Personal (無料) の有効化。declaration 未チェックでも送信し、サーバの文言を表示する
+    // (押下時にエラー表示 = 禁止事項 #8)。
+    const submitPersonalFree = (): void => {
+        if (submitting) return; // 多重送信ガード (disabled にはしない)
+        lastSubmittedPlanCode = "personal";
+        router.post(
+            "/onboarding/activate-personal",
+            { declaration: declarationChecked ? "1" : "0" },
+            {
+                onStart: () => {
+                    submitting = true;
+                },
+                onFinish: () => {
+                    submitting = false;
+                },
+            },
+        );
+    };
+
+    // 有償プランの契約開始。既存の課金 checkout (Stripe Checkout へ full page redirect) に載せる。
+    const submitPaidPlan = (): void => {
+        if (submitting || chosenPlanCode === null) return;
+        lastSubmittedPlanCode = chosenPlanCode;
+        router.post(
+            "/billing/checkout",
+            { plan_code: chosenPlanCode },
+            {
+                onStart: () => {
+                    submitting = true;
+                },
+                onFinish: () => {
+                    submitting = false;
+                },
+            },
+        );
+    };
+
+    const showRecommendedBadge = (planCode: string): boolean =>
+        planCode === pageData.recommendedPlanCode;
+</script>
+
+<AppLayout {appName}>
+    <PageContainer>
+        <PageHeader
+            title={`ようこそ、${organization.name}`}
+            description="利用を開始するにはプランを選択してください。"
+            icon={CreditCard}
+            testId="onboarding-checkout-heading"
+        />
+        <PageContent>
+            <div class="flex flex-col gap-6" data-testid="onboarding-checkout">
+                <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-3" data-testid="plan-grid">
+                    {#each pageData.plans as plan (plan.code)}
+                        <PricingPlanCard
+                            name={plan.name}
+                            priceAmount={isPersonal(plan) ? 0 : plan.currentBaseAmount}
+                            features={buildFeatures(plan)}
+                            isHighlighted={selectedPlanCode === plan.code}
+                            testId={`plan-card-${plan.code}`}
+                        >
+                            {#snippet footerCta()}
+                                <div class="flex flex-col gap-2">
+                                    {#if showRecommendedBadge(plan.code)}
+                                        <span
+                                            class="self-start rounded-sm bg-primary/10 px-2 py-0.5 text-caption text-primary"
+                                            data-testid={`recommended-badge-${plan.code}`}
+                                        >
+                                            おすすめ
+                                        </span>
+                                    {/if}
+                                    <Button
+                                        onclick={() => choosePlan(plan)}
+                                        testId={`select-plan-${plan.code}`}
+                                    >
+                                        {chosenPlanCode === plan.code ? "選択中" : "選択"}
+                                    </Button>
+                                    {#if isPersonal(plan) && personalReasonLabel !== null}
+                                        <!-- 選択自体は妨げない (サーバが最終判定)。理由を常時提示する。 -->
+                                        <p
+                                            class="text-caption text-text-secondary"
+                                            data-testid="personal-eligibility-reason"
+                                        >
+                                            {personalReasonLabel}
+                                        </p>
+                                    {/if}
+                                </div>
+                            {/snippet}
+                        </PricingPlanCard>
+                    {/each}
+                </div>
+
+                {#if chosenPlanCode === "personal"}
+                    <!-- Personal (無料) は Stripe checkout を通らない。自己申告チェック + 無料開始 CTA。 -->
+                    <div class="flex flex-col gap-4" data-testid="personal-free-step">
+                        {#if planCodeError !== null}
+                            <Alert type="danger" testId="checkout-plan-error">
+                                {planCodeError}
+                            </Alert>
+                        {/if}
+
+                        <div>
+                            <p class="text-body font-medium text-text">
+                                パーソナルプラン（無料）で始める
+                            </p>
+                            <p class="mt-1 text-caption text-text-secondary">
+                                基本料金はかかりません。トレーニングの実行に使うチケットのみ購入制です（新規登録特典として
+                                チケット {pageData.signupGrantTickets} 枚を無償でお付けします）。カード登録なしでも始められます。
+                            </p>
+                        </div>
+
+                        <Checkbox
+                            id="personal-declaration"
+                            bind:checked={declarationChecked}
+                            label="個人での利用であり、法人・チームでの利用ではないことを確認しました"
+                            error={declarationError}
+                            testId="personal-declaration"
+                        />
+
+                        <div>
+                            <Button
+                                onclick={submitPersonalFree}
+                                loading={submitting}
+                                testId="personal-free-submit"
+                            >
+                                無料プランを開始する
+                            </Button>
+                            <p class="mt-2 text-caption text-text-secondary">
+                                決済画面には進みません。すぐに利用を開始できます。
+                            </p>
+                        </div>
+                    </div>
+                {:else if chosenPlanCode !== null}
+                    <div class="flex flex-col gap-4" data-testid="paid-plan-step">
+                        {#if planCodeError !== null}
+                            <Alert type="danger" testId="checkout-plan-error">
+                                {planCodeError}
+                            </Alert>
+                        {/if}
+
+                        <div>
+                            <Button
+                                onclick={submitPaidPlan}
+                                loading={submitting}
+                                testId="paid-plan-submit"
+                            >
+                                この内容で契約を進める
+                            </Button>
+                            <p class="mt-2 text-caption text-text-secondary">
+                                次の画面で決済に進みます。
+                            </p>
+                        </div>
+                    </div>
+                {/if}
+
+                <p class="text-caption text-text-secondary">
+                    <!-- contactUrl は内部 path / 外部 URL / mailto のいずれにもなりうる (ContactUrl が
+                         解決する) ため、素の <a> で全パターンを同じ扱いにする (Welcome と同規約)。 -->
+                    Enterprise プランをご検討の場合は <a
+                        href={pageData.contactUrl}
+                        class="text-primary underline"
+                        data-testid="onboarding-contact-link">お問い合わせ</a
+                    > ください。
+                </p>
+            </div>
+        </PageContent>
+    </PageContainer>
+</AppLayout>
diff --git a/resources/js/types/onboarding.ts b/resources/js/types/onboarding.ts
new file mode 100644
index 0000000..fc4c3b6
--- /dev/null
+++ b/resources/js/types/onboarding.ts
@@ -0,0 +1,52 @@
+/**
+ * 課金オンボーディング (Onboarding/Checkout・Onboarding/BillingRequired) の Inertia props。
+ * PHP 側 DTO (App\DataTransferObjects\Onboarding\* / App\DataTransferObjects\Billing\PlanDto) の
+ * @phpstan-type shape と exact 対。全プロパティ readonly で accidental widening を防ぐ。
+ *
+ * フィールド名は移植元 (aigenba) と同一に保ち、後続フェーズ (P7 intendedPlanCode /
+ * P8a funding・同意 / P9 attempt token) は additive に足すだけにする。
+ */
+
+/** PHP: PlanDto (PlanShape) と対 (currentBaseAmount null = 基本料金なし = 無料表示契約) */
+export interface PlanShape {
+    readonly code: string;
+    readonly name: string;
+    readonly currentBaseAmount: number | null;
+    readonly isActive: boolean;
+}
+
+/** PHP: PersonalPlanEligibilityDto (PersonalPlanEligibilityShape) と対 */
+export interface PersonalPlanEligibilityShape {
+    /** Personal (無料) を有効化できるか。サーバー判定が唯一の権威 (client で組み立てない) */
+    readonly eligible: boolean;
+    /** PersonalPlanIneligibleReason の値 (eligible=true なら null) */
+    readonly reason: string | null;
+    /** 表示文言。サーバー側 enum label で確定済み (frontend に文言マッピングを散らさない) */
+    readonly reasonLabel: string | null;
+}
+
+/** PHP: OnboardingCheckoutDto (OnboardingCheckoutShape) と対 */
+export interface OnboardingCheckoutShape {
+    /** is_active=true ∧ code ∈ {personal,starter,standard,business} を sort_order 昇順で */
+    readonly plans: readonly PlanShape[];
+    readonly recommendedPlanCode: string;
+    readonly defaultPlanCode: string;
+    readonly contactUrl: string;
+    readonly personalEligibility: PersonalPlanEligibilityShape | null;
+    /** 新規登録特典の無償チケット枚数 (無料開始 callout 用) */
+    readonly signupGrantTickets: number;
+}
+
+/** PHP: BillingRequiredDto (BillingRequiredShape) と対 */
+export interface BillingRequiredShape {
+    readonly ownerName: string | null;
+    readonly ownerEmail: string | null;
+    readonly contactUrl: string;
+}
+
+/** 両ページ共通の organization props (Controller の organizationProps() と対) */
+export interface OnboardingOrganizationShape {
+    readonly id: number;
+    readonly name: string;
+    readonly slug: string;
+}
diff --git a/routes/web.php b/routes/web.php
index 2d52ee8..8fa25ae 100644
--- a/routes/web.php
+++ b/routes/web.php
@@ -16,6 +16,9 @@
 use App\Http\Controllers\HomeController;
 use App\Http\Controllers\Marketing\PricingController;
 use App\Http\Controllers\NotificationController;
+use App\Http\Controllers\Onboarding\ActivatePersonalController;
+use App\Http\Controllers\Onboarding\BillingRequiredController;
+use App\Http\Controllers\Onboarding\OnboardingController;
 use App\Http\Controllers\Organizations\InvitationAcceptanceController;
 use App\Http\Controllers\Organizations\OrganizationApiKeyController;
 use App\Http\Controllers\Organizations\OrganizationController;
@@ -310,6 +313,24 @@
     Route::post('/billing/portal', [BillingController::class, 'portal'])
         ->name('billing.portal');
 
+    /*
+    | 課金オンボーディング (current org スコープ)。登録直後の Plan 選択 +
+    | 未契約 manageBilling なし member 向け説明画面。billing.* と同じく課金ゲート
+    | (require-active-subscription) の外に置く = 未契約組織が導線に到達できることを保証する
+    | (ゲート内に入れると「契約するための画面が契約してないと見られない」詰みになる)。
+    | 組織解決は billing.* と同一 (route parameter なし = URL の org ≠ current org が
+    | 構造的に発生しない)。認可は Controller 冒頭の Gate::authorize が担う。
+    | MCP/CLI 導入ガイド (organizations.onboarding.{mcp,cli}) とは別責務・別 name。
+    */
+    Route::get('/onboarding/checkout', [OnboardingController::class, 'show'])
+        ->name('onboarding.checkout');
+    // Personal (free) の有効化 (Stripe checkout を通らない。自己申告チェック必須)
+    Route::post('/onboarding/activate-personal', ActivatePersonalController::class)
+        ->middleware('throttle:10,1')
+        ->name('onboarding.activate-personal');
+    Route::get('/billing-required', [BillingRequiredController::class, 'show'])
+        ->name('onboarding.billing-required');
+
     /*
     | チケットスポット購入 (current org スコープ)。billing.* と同じく課金ゲート
     | (require-active-subscription) の対象外 = 支払い不健全で遮断中の組織でも購入できる
diff --git a/tests/Feature/Onboarding/ActivatePersonalTest.php b/tests/Feature/Onboarding/ActivatePersonalTest.php
new file mode 100644
index 0000000..b5abcd0
--- /dev/null
+++ b/tests/Feature/Onboarding/ActivatePersonalTest.php
@@ -0,0 +1,205 @@
+<?php
+
+declare(strict_types=1);
+
+use App\Enums\Billing\OnboardingBillingState;
+use App\Models\Organization;
+use App\Models\User;
+use App\Services\Billing\BillingAccess;
+use App\Services\Billing\PersonalPlanService;
+use App\Services\Billing\TicketPricingService;
+use Illuminate\Support\Facades\DB;
+
+/*
+ * Personal (free) プランの有効化 (POST /onboarding/activate-personal。current org スコープ)。
+ *
+ * Controller は PersonalPlanService::activate() を呼ぶだけ (付与ロジックを再実装しない =
+ * 二重付与源を作らない)。条件不成立は 500 ではなく 422 (errors.plan_code) へ落とす。
+ */
+
+function activatePersonalPayload(): array
+{
+    return ['declaration' => true];
+}
+
+test('current org 不在なら 404', function (): void {
+    $user = User::factory()->create();
+
+    $this->actingAs($user)
+        ->post('/onboarding/activate-personal', activatePersonalPayload())
+        ->assertNotFound();
+});
+
+test('current org に非所属なら 404 (認可より前 = 403 で存在を漏らさない)', function (): void {
+    [$organization] = createOrganizationWithOwner();
+    $outsider = User::factory()->create();
+    $outsider->forceFill(['current_organization_id' => $organization->id])->save();
+
+    $this->actingAs($outsider)
+        ->post('/onboarding/activate-personal', activatePersonalPayload())
+        ->assertNotFound();
+});
+
+test('manageBilling なし member は 403', function (): void {
+    [$organization] = createOrganizationWithOwner();
+    $member = attachOrganizationMember($organization);
+    $member->forceFill(['current_organization_id' => $organization->id])->save();
+
+    $this->actingAs($member)
+        ->post('/onboarding/activate-personal', activatePersonalPayload())
+        ->assertForbidden();
+});
+
+test('declaration 未チェックは redirect-back + errors.declaration (有効化されない)', function (): void {
+    [$organization, $owner] = createOrganizationWithOwner();
+
+    $this->actingAs($owner)
+        ->post('/onboarding/activate-personal', ['declaration' => false])
+        ->assertSessionHasErrors(['declaration' => '個人利用であることの確認が必要です。']);
+
+    expect($organization->fresh()?->free_plan_code)->toBeNull();
+});
+
+test('declaration 欠落の XHR は 422', function (): void {
+    [, $owner] = createOrganizationWithOwner();
+
+    $this->actingAs($owner)
+        ->postJson('/onboarding/activate-personal', [])
+        ->assertStatus(422)
+        ->assertJsonValidationErrors(['declaration' => '個人利用であることの確認が必要です。']);
+});
+
+test('保護キーを payload に混ぜると 422 (mass-assignment 入口防御)', function (): void {
+    [, $owner] = createOrganizationWithOwner();
+
+    $this->actingAs($owner)
+        ->postJson('/onboarding/activate-personal', [
+            'declaration' => true,
+            'personal_declared_by_user_id' => 999,
+        ])
+        ->assertStatus(422)
+        ->assertJsonValidationErrors(['personal_declared_by_user_id']);
+});
+
+test('成功すると free entitlement が確定し dashboard へ redirect + 枚数入り flash', function (): void {
+    [$organization, $owner] = createOrganizationWithOwner();
+    $tickets = app(TicketPricingService::class)->signupGrantTickets();
+
+    $this->actingAs($owner)
+        ->post('/onboarding/activate-personal', activatePersonalPayload())
+        ->assertRedirect(route('dashboard'))
+        ->assertSessionHas('success', sprintf(
+            'パーソナルプラン（無料）を開始しました。無料チケット %d 枚をお付けしました。',
+            $tickets,
+        ));
+
+    $fresh = $organization->fresh();
+    expect($fresh)->not->toBeNull()
+        ->and($fresh->free_plan_code)->toBe(PersonalPlanService::FREE_PLAN_CODE)
+        ->and($fresh->personal_declared_by_user_id)->toBe($owner->getKey())
+        ->and($fresh->personal_declared_at)->not->toBeNull()
+        ->and($fresh->free_plan_activated_at)->not->toBeNull()
+        ->and($fresh->signup_tickets_granted_at)->not->toBeNull()
+        // 有効化直後は ActiveFreePlan (= 以後 checkout は billing.index へ逃がす)
+        ->and(app(BillingAccess::class)->state($fresh))->toBe(OnboardingBillingState::ActiveFreePlan);
+
+    expect(DB::table('ticket_ledger_entries')
+        ->where('organization_id', $organization->getKey())
+        ->where('idempotency_key', 'like', 'signup_grant:%')
+        ->count())->toBe(1);
+});
+
+test('二重 POST は冪等 (2 回目は付与なしの文言 + signup_grant は 1 行のまま)', function (): void {
+    [$organization, $owner] = createOrganizationWithOwner();
+
+    $this->actingAs($owner)->post('/onboarding/activate-personal', activatePersonalPayload());
+    $this->actingAs($owner)
+        ->post('/onboarding/activate-personal', activatePersonalPayload())
+        ->assertRedirect(route('dashboard'))
+        ->assertSessionHas('success', 'パーソナルプラン（無料）を開始しました。');
+
+    expect(DB::table('ticket_ledger_entries')
+        ->where('organization_id', $organization->getKey())
+        ->where('idempotency_key', 'like', 'signup_grant:%')
+        ->count())->toBe(1);
+});
+
+test('付与マーカー済みの org は granted=false の文言で有効化される', function (): void {
+    [$organization, $owner] = createOrganizationWithOwner();
+    $organization->forceFill(['signup_tickets_granted_at' => now()])->save();
+
+    $this->actingAs($owner)
+        ->post('/onboarding/activate-personal', activatePersonalPayload())
+        ->assertRedirect(route('dashboard'))
+        ->assertSessionHas('success', 'パーソナルプラン（無料）を開始しました。');
+
+    expect(DB::table('ticket_ledger_entries')
+        ->where('organization_id', $organization->getKey())
+        ->where('idempotency_key', 'like', 'signup_grant:%')
+        ->count())->toBe(0);
+});
+
+test('既に free personal org を持つ user は errors.plan_code (500 にしない)', function (): void {
+    [$organization, $owner] = createOrganizationWithOwner();
+    Organization::factory()->freePersonal($owner)->create();
+
+    $this->actingAs($owner)
+        ->post('/onboarding/activate-personal', activatePersonalPayload())
+        ->assertSessionHasErrors([
+            'plan_code' => '既にパーソナルプラン（無料）の組織をお持ちのため、この組織では選択できません。',
+        ]);
+
+    expect($organization->fresh()?->free_plan_code)->toBeNull();
+});
+
+test('メンバー超過の org は errors.plan_code (XHR は 422)', function (): void {
+    [$organization, $owner] = createOrganizationWithOwner();
+    // MAX_MEMBERS = 3 を超える (owner + 3 名 = 4 名)
+    for ($i = 0; $i < 3; $i++) {
+        attachOrganizationMember($organization);
+    }
+
+    $this->actingAs($owner)
+        ->postJson('/onboarding/activate-personal', activatePersonalPayload())
+        ->assertStatus(422)
+        ->assertJsonValidationErrors([
+            'plan_code' => sprintf(
+                'メンバーが %d 名を超えているためパーソナルプランは選択できません。',
+                PersonalPlanService::MAX_MEMBERS,
+            ),
+        ]);
+
+    expect($organization->fresh()?->free_plan_code)->toBeNull();
+});
+
+test('有効な有償契約がある org は errors.plan_code', function (): void {
+    [$organization, $owner] = createOrganizationWithOwner();
+    contractPaidPlan($organization, status: 'active');
+
+    $this->actingAs($owner)
+        ->post('/onboarding/activate-personal', activatePersonalPayload())
+        ->assertSessionHasErrors([
+            'plan_code' => '有効な有償契約があるためパーソナルプランは選択できません。',
+        ]);
+
+    expect($organization->fresh()?->free_plan_code)->toBeNull();
+});
+
+test('throttle:10,1 が効く (11 回目は 429)', function (): void {
+    [, $owner] = createOrganizationWithOwner();
+
+    for ($i = 0; $i < 10; $i++) {
+        $this->actingAs($owner)
+            ->post('/onboarding/activate-personal', activatePersonalPayload())
+            ->assertStatus(302);
+    }
+
+    $this->actingAs($owner)
+        ->post('/onboarding/activate-personal', activatePersonalPayload())
+        ->assertStatus(429);
+});
+
+test('未認証は login へ', function (): void {
+    $this->post('/onboarding/activate-personal', activatePersonalPayload())
+        ->assertRedirect('/login');
+});
diff --git a/tests/Feature/Onboarding/BillingRequiredTest.php b/tests/Feature/Onboarding/BillingRequiredTest.php
new file mode 100644
index 0000000..58c406c
--- /dev/null
+++ b/tests/Feature/Onboarding/BillingRequiredTest.php
@@ -0,0 +1,127 @@
+<?php
+
+declare(strict_types=1);
+
+use App\Enums\OrganizationRole;
+use App\Models\Organization;
+use App\Models\User;
+use Inertia\Testing\AssertableInertia as Assert;
+
+/*
+ * 未契約 + manageBilling なし member 向け説明画面 (/billing-required。current org スコープ)。
+ *
+ * 403 ではなく専用ページを返すことで「行き先のない詰み」を回避する。逆に、この画面を
+ * 見せる理由がない者 (利用可 / manageBilling 保持者) は離脱ガードで本来の行き先へ逃がす。
+ */
+
+/** ExpiredCheckout (plan_code 非 null + entitled でない sub) の組織 + owner。 */
+function billingRequiredExpiredOrganization(): array
+{
+    [$organization, $owner] = createOrganizationWithOwner();
+    contractPaidPlan($organization, status: 'canceled');
+
+    return [$organization->fresh(), $owner];
+}
+
+test('current org 不在なら 404', function (): void {
+    $user = User::factory()->create();
+
+    $this->actingAs($user)->get('/billing-required')->assertNotFound();
+});
+
+test('current org に非所属なら 404 (403 で存在を漏らさない)', function (): void {
+    [$organization] = createOrganizationWithOwner();
+    $outsider = User::factory()->create();
+    $outsider->forceFill(['current_organization_id' => $organization->id])->save();
+
+    $this->actingAs($outsider)->get('/billing-required')->assertNotFound();
+});
+
+test('ExpiredCheckout の一般 member には Owner 連絡先付きで 200 render される', function (): void {
+    [$organization, $owner] = billingRequiredExpiredOrganization();
+    $member = attachOrganizationMember($organization);
+    $member->forceFill(['current_organization_id' => $organization->id])->save();
+
+    $this->actingAs($member)->get('/billing-required')
+        ->assertOk()
+        ->assertInertia(fn (Assert $page) => $page
+            ->component('Onboarding/BillingRequired')
+            ->where('organization.name', 'テスト組織')
+            ->where('pageData.ownerName', $owner->name)
+            ->where('pageData.ownerEmail', $owner->email)
+            ->where('pageData.contactUrl', '/contact?source=onboarding'));
+});
+
+test('離脱ガード: 有効 subscription を持つ member は dashboard へ', function (): void {
+    [$organization] = createOrganizationWithOwner();
+    contractPaidPlan($organization, status: 'active');
+    $member = attachOrganizationMember($organization);
+    $member->forceFill(['current_organization_id' => $organization->id])->save();
+
+    $this->actingAs($member)->get('/billing-required')
+        ->assertRedirect(route('dashboard'));
+});
+
+test('離脱ガード: ActiveFreePlan (free_plan_code=personal) の member は dashboard へ', function (): void {
+    [$organization, $owner] = createOrganizationWithOwner();
+    $organization->forceFill([
+        'free_plan_code' => 'personal',
+        'free_plan_activated_at' => now(),
+        'personal_declared_at' => now(),
+        'personal_declared_by_user_id' => $owner->getKey(),
+    ])->save();
+    $member = attachOrganizationMember($organization);
+    $member->forceFill(['current_organization_id' => $organization->id])->save();
+
+    $this->actingAs($member)->get('/billing-required')
+        ->assertRedirect(route('dashboard'));
+});
+
+test('離脱ガード: manageBilling 保持者は checkout へ (自分で手続きできる)', function (): void {
+    [, $owner] = billingRequiredExpiredOrganization();
+
+    $this->actingAs($owner)->get('/billing-required')
+        ->assertRedirect(route('onboarding.checkout'));
+});
+
+test('未契約 org (plan_code IS NULL) の一般 member も 200 render される — P4 の grandfathering backfill 後は ActiveFreePlan → dashboard へ変わる', function (): void {
+    [$organization] = createOrganizationWithOwner();
+    $member = attachOrganizationMember($organization);
+    $member->forceFill(['current_organization_id' => $organization->id])->save();
+
+    // state() は移行 OR を持たないため NoSubscription = 遮断側。まだ遮断されていない member に
+    // 説明画面が見える (P3 の既知の非対称)。P3 では UI からこの画面へリンクを張らないため
+    // 通常導線からは到達しない。P4 の backfill で ActiveFreePlan になり離脱ガードが dashboard
+    // へ逃がす = 自然解消 (期待の更新は P4。本テストは削除せず更新する)。
+    expect($organization->plan_code)->toBeNull();
+
+    $this->actingAs($member)->get('/billing-required')
+        ->assertOk()
+        ->assertInertia(fn (Assert $page) => $page->component('Onboarding/BillingRequired'));
+});
+
+test('Owner 不在 org でも 200 で ownerName / ownerEmail は null', function (): void {
+    $organization = Organization::factory()->create();
+    $organization->forceFill(['plan_code' => 'standard'])->save();
+    createFakeSubscription($organization, status: 'canceled');
+
+    $member = attachOrganizationMember($organization);
+    $member->forceFill(['current_organization_id' => $organization->id])->save();
+
+    expect($organization->users()->get()
+        ->first(fn (User $u): bool => $u->organizationRole($organization) === OrganizationRole::Owner))
+        ->toBeNull();
+
+    $this->actingAs($member)->get('/billing-required')
+        ->assertOk()
+        ->assertInertia(fn (Assert $page) => $page
+            ->component('Onboarding/BillingRequired')
+            ->where('pageData.ownerName', null)
+            ->where('pageData.ownerEmail', null)
+            // 詰みを避けるため問い合わせ導線は常に出す
+            ->where('pageData.contactUrl', '/contact?source=onboarding'));
+});
+
+test('未認証は login へ', function (): void {
+    $this->get('/billing-required')->assertRedirect('/login');
+});
diff --git a/tests/Feature/Onboarding/OnboardingCheckoutTest.php b/tests/Feature/Onboarding/OnboardingCheckoutTest.php
new file mode 100644
index 0000000..4702348
--- /dev/null
+++ b/tests/Feature/Onboarding/OnboardingCheckoutTest.php
@@ -0,0 +1,155 @@
+<?php
+
+declare(strict_types=1);
+
+use App\Enums\OrganizationRole;
+use App\Models\Billing\Plan;
+use App\Models\Organization;
+use App\Models\User;
+use App\Services\Billing\TicketPricingService;
+use Inertia\Testing\AssertableInertia as Assert;
+
+/*
+ * 課金オンボーディングの Plan 選択画面 (/onboarding/checkout。current org スコープ)。
+ *
+ * 入口ガードは 2 式のみを読む (新しい述語を発明しない):
+ *   1. BillingAccess::hasActiveAccess() → billing.index へ
+ *   2. Gate::allows('manageBilling')    → 不成立なら onboarding.billing-required へ
+ * 判定順序 (hasActiveAccess → manageBilling) は「契約済み non-manager が誤って
+ * billing-required に飛ばない」ための load-bearing な順序。
+ */
+
+/** ExpiredCheckout (plan_code 非 null + entitled でない sub) の組織 + owner。 */
+function expiredCheckoutOrganizationWithOwner(): array
+{
+    [$organization, $owner] = createOrganizationWithOwner();
+    contractPaidPlan($organization, status: 'canceled');
+
+    return [$organization->fresh(), $owner];
+}
+
+test('current org 不在なら 404 (組織の有無を露出しない)', function (): void {
+    $user = User::factory()->create();
+
+    $this->actingAs($user)->get('/onboarding/checkout')->assertNotFound();
+});
+
+test('current org に非所属なら 404 (403 で存在を漏らさない)', function (): void {
+    [$organization] = createOrganizationWithOwner();
+    // current_organization_id が退会後も残存する不整合を再現する
+    $outsider = User::factory()->create();
+    $outsider->forceFill(['current_organization_id' => $organization->id])->save();
+
+    $this->actingAs($outsider)->get('/onboarding/checkout')->assertNotFound();
+});
+
+test('ExpiredCheckout + manageBilling は Plan 選択画面を 200 で描画する', function (): void {
+    [, $owner] = expiredCheckoutOrganizationWithOwner();
+
+    $this->actingAs($owner)->get('/onboarding/checkout')
+        ->assertOk()
+        ->assertInertia(fn (Assert $page) => $page
+            ->component('Onboarding/Checkout')
+            ->where('organization.name', 'テスト組織')
+            // is_active=true ∧ code ∈ {personal,starter,standard,business} のみ。
+            // legacy free 行は code 集合外 / business は Plan 行が無いため出ない。
+            ->has('pageData.plans', 3)
+            ->where('pageData.plans.0.code', 'personal')   // sort_order 昇順
+            ->where('pageData.plans.0.currentBaseAmount', null) // base price 不在 = 無料表示契約
+            ->where('pageData.plans.1.code', 'starter')
+            ->where('pageData.plans.1.currentBaseAmount', 980)
+            ->where('pageData.plans.2.code', 'standard')
+            ->where('pageData.plans.2.currentBaseAmount', 4980)
+            ->where('pageData.recommendedPlanCode', 'standard')
+            ->where('pageData.defaultPlanCode', 'starter')
+            ->where('pageData.contactUrl', '/contact?source=onboarding')
+            ->where('pageData.signupGrantTickets', app(TicketPricingService::class)->signupGrantTickets())
+            // personal は is_active=true のため eligibility は常に非 null
+            ->where('pageData.personalEligibility.eligible', true)
+            ->where('pageData.personalEligibility.reason', null));
+});
+
+test('ExpiredCheckout + manageBilling なし member は billing-required へ redirect (判定順序の固定)', function (): void {
+    [$organization] = expiredCheckoutOrganizationWithOwner();
+    $member = attachOrganizationMember($organization);
+    $member->forceFill(['current_organization_id' => $organization->id])->save();
+
+    $this->actingAs($member)->get('/onboarding/checkout')
+        ->assertRedirect(route('onboarding.billing-required'));
+});
+
+test('Subscribed は manageBilling でも billing.index へ redirect', function (): void {
+    [$organization, $owner] = createOrganizationWithOwner();
+    contractPaidPlan($organization, status: 'active');
+
+    $this->actingAs($owner)->get('/onboarding/checkout')
+        ->assertRedirect(route('billing.index'));
+});
+
+test('Subscribed の non-manager member は billing-required ではなく billing.index へ (判定順序の固定)', function (): void {
+    [$organization] = createOrganizationWithOwner();
+    contractPaidPlan($organization, status: 'active');
+    $member = attachOrganizationMember($organization);
+    $member->forceFill(['current_organization_id' => $organization->id])->save();
+
+    $this->actingAs($member)->get('/onboarding/checkout')
+        ->assertRedirect(route('billing.index'));
+});
+
+test('ActiveFreePlan (free_plan_code=personal) は billing.index へ redirect', function (): void {
+    [$organization, $owner] = createOrganizationWithOwner();
+    $organization->forceFill(['plan_code' => 'standard'])->save(); // 移行 OR を経由しないことの明示
+    contractPaidPlan($organization, status: 'canceled');
+    $organization->forceFill([
+        'free_plan_code' => 'personal',
+        'free_plan_activated_at' => now(),
+        'personal_declared_at' => now(),
+        'personal_declared_by_user_id' => $owner->getKey(),
+    ])->save();
+
+    $this->actingAs($owner)->get('/onboarding/checkout')
+        ->assertRedirect(route('billing.index'));
+});
+
+test('未契約 org (plan_code IS NULL) は移行 OR により billing.index へ redirect する — P4 の移行 OR 削除で 200 render へ変わる', function (): void {
+    [$organization, $owner] = createOrganizationWithOwner();
+
+    expect($organization->plan_code)->toBeNull()
+        ->and($organization->free_plan_code)->toBeNull();
+
+    // P3 の事実: hasActiveAccess() の移行 OR (plan_code === null) が true を返すため
+    // checkout は画面として到達しない。P4 で OR の 1 行を消すと 200 render へ反転する
+    // (期待の更新は P4 のテスト計画。本テストは削除せず更新する)。
+    $this->actingAs($owner)->get('/onboarding/checkout')
+        ->assertRedirect(route('billing.index'));
+});
+
+test('is_active=false に落とした Plan は pageData.plans に出ない (露出規則の固定)', function (): void {
+    [, $owner] = expiredCheckoutOrganizationWithOwner();
+    Plan::query()->where('code', 'standard')->update(['is_active' => false]);
+
+    $this->actingAs($owner)->get('/onboarding/checkout')
+        ->assertOk()
+        ->assertInertia(fn (Assert $page) => $page
+            ->has('pageData.plans', 2)
+            ->where('pageData.plans.0.code', 'personal')
+            ->where('pageData.plans.1.code', 'starter'));
+});
+
+test('personal 選択不可の理由はサーバー確定文言で props に載る', function (): void {
+    [$organization, $owner] = expiredCheckoutOrganizationWithOwner();
+    // 同一 declarer の別 free personal org を作り AlreadyHasFreePersonalOrg を成立させる
+    Organization::factory()->freePersonal($owner)->create();
+    $owner->addRole(OrganizationRole::Owner->value, $organization->laratrust_team_id);
+
+    $this->actingAs($owner)->get('/onboarding/checkout')
+        ->assertOk()
+        ->assertInertia(fn (Assert $page) => $page
+            ->where('pageData.personalEligibility.eligible', false)
+            ->where('pageData.personalEligibility.reason', 'already_has_free_personal_org')
+            ->where('pageData.personalEligibility.reasonLabel', '既にパーソナルプラン（無料）の組織をお持ちのため、この組織では選択できません。'));
+});
+
+test('未認証は login へ', function (): void {
+    $this->get('/onboarding/checkout')->assertRedirect('/login');
+});
diff --git a/tests/Unit/DataTransferObjects/Billing/PlanDtoTest.php b/tests/Unit/DataTransferObjects/Billing/PlanDtoTest.php
new file mode 100644
index 0000000..83c507e
--- /dev/null
+++ b/tests/Unit/DataTransferObjects/Billing/PlanDtoTest.php
@@ -0,0 +1,64 @@
+<?php
+
+declare(strict_types=1);
+
+use App\DataTransferObjects\Billing\PlanDto;
+use App\Enums\Billing\PlanPriceKind;
+use App\Models\Billing\Plan;
+
+/*
+ * PlanDto::fromModel()。Plan の真実源は PlanSeeder (テストでも $seed=true で毎回走る)。
+ *
+ * currentBaseAmount の契約: 現行 (is_current=true) の base price の金額。base price を
+ * 持たないプランは null = 無料表示契約 (PricingPlanDto::baseAmountJpy と同一意味論)。
+ */
+
+function seededPlan(string $code): Plan
+{
+    return Plan::query()->where('code', $code)->firstOrFail();
+}
+
+test('fromModel は code / name / is_active と現行 base price をマップする', function (): void {
+    $plan = seededPlan('starter');
+
+    $dto = PlanDto::fromModel($plan);
+
+    expect($dto->code)->toBe('starter')
+        ->and($dto->name)->toBe('Starter')
+        ->and($dto->currentBaseAmount)->toBe(980)
+        ->and($dto->isActive)->toBeTrue();
+});
+
+test('base price を持たないプランは currentBaseAmount が null (無料表示契約)', function (): void {
+    $plan = seededPlan('personal');
+
+    expect($plan->currentPrice(PlanPriceKind::Base))->toBeNull()
+        ->and(PlanDto::fromModel($plan)->currentBaseAmount)->toBeNull();
+});
+
+test('is_active=false は isActive=false としてマップされる', function (): void {
+    $plan = seededPlan('standard');
+    $plan->forceFill(['is_active' => false])->save();
+
+    expect(PlanDto::fromModel($plan->fresh())->isActive)->toBeFalse();
+});
+
+test('is_current でない base price は currentBaseAmount に載らない', function (): void {
+    $plan = seededPlan('starter');
+    // 現行 price を退役させる (is_current=true ⇔ active_to IS NULL の invariant を守る)
+    $plan->prices()->where('kind', PlanPriceKind::Base->value)->where('is_current', true)
+        ->update(['is_current' => false, 'active_to' => now()]);
+
+    expect(PlanDto::fromModel($plan->fresh())->currentBaseAmount)->toBeNull();
+});
+
+test('toArray は Shape どおりのキーのみを返す (席 / limit 系は非移植)', function (): void {
+    $dto = PlanDto::fromModel(seededPlan('standard'));
+
+    expect($dto->toArray())->toBe([
+        'code' => 'standard',
+        'name' => 'Standard',
+        'currentBaseAmount' => 4980,
+        'isActive' => true,
+    ]);
+});
diff --git a/tests/js/pages/OnboardingBillingRequired.test.ts b/tests/js/pages/OnboardingBillingRequired.test.ts
new file mode 100644
index 0000000..01f7f2f
--- /dev/null
+++ b/tests/js/pages/OnboardingBillingRequired.test.ts
@@ -0,0 +1,74 @@
+import { afterEach, describe, expect, it, vi } from "vitest";
+import { cleanup, render, screen } from "@testing-library/svelte";
+import BillingRequired from "@/pages/Onboarding/BillingRequired.svelte";
+import type { BillingRequiredShape } from "@/types/onboarding";
+
+const { pageState } = vi.hoisted(() => ({
+    pageState: { props: {} as Record<string, unknown> },
+}));
+
+vi.mock("@inertiajs/svelte", async (importOriginal) => ({
+    ...(await importOriginal<typeof import("@inertiajs/svelte")>()),
+    page: pageState,
+}));
+
+/*
+ * 課金手続き待ちの説明画面 (課金権限を持たないメンバーの着地先)。
+ * 403 で突き放さず、組織管理者の連絡先 + 問い合わせ導線を出して「行き先のない詰み」を回避する。
+ * owner 不在 org では ownerName / ownerEmail が null になりうる (描画が壊れないこと)。
+ */
+
+const organization = { id: 1, name: "テスト組織", slug: "test-org" };
+
+const basePageData: BillingRequiredShape = {
+    ownerName: "山田 太郎",
+    ownerEmail: "owner@example.com",
+    contactUrl: "/contact?source=onboarding",
+};
+
+afterEach(() => {
+    cleanup();
+    pageState.props = {};
+});
+
+function renderPage(overrides: Partial<BillingRequiredShape> = {}): void {
+    render(BillingRequired, {
+        props: { organization, pageData: { ...basePageData, ...overrides } },
+    });
+}
+
+describe("Onboarding/BillingRequired", () => {
+    it("組織名と待機の説明・管理者の連絡先・問い合わせ導線を出す", () => {
+        renderPage();
+
+        expect(screen.getByTestId("billing-required-heading")).toHaveTextContent("課金手続き中です");
+        expect(screen.getByTestId("billing-required-message")).toHaveTextContent("テスト組織");
+        expect(screen.getByTestId("billing-required-owner")).toHaveTextContent("山田 太郎");
+        expect(screen.getByTestId("billing-required-owner-email")).toHaveAttribute(
+            "href",
+            "mailto:owner@example.com",
+        );
+        expect(screen.getByTestId("billing-required-contact-link")).toHaveAttribute(
+            "href",
+            "/contact?source=onboarding",
+        );
+    });
+
+    it("ownerEmail が null でも管理者名の表示は壊れない", () => {
+        renderPage({ ownerEmail: null });
+
+        expect(screen.getByTestId("billing-required-owner")).toHaveTextContent("山田 太郎");
+        expect(screen.queryByTestId("billing-required-owner-email")).not.toBeInTheDocument();
+    });
+
+    it("owner 不在 (ownerName / ownerEmail が null) でも問い合わせ導線は出る", () => {
+        renderPage({ ownerName: null, ownerEmail: null });
+
+        expect(screen.queryByTestId("billing-required-owner")).not.toBeInTheDocument();
+        expect(screen.getByTestId("billing-required-message")).toHaveTextContent("テスト組織");
+        expect(screen.getByTestId("billing-required-contact-link")).toHaveAttribute(
+            "href",
+            "/contact?source=onboarding",
+        );
+    });
+});
diff --git a/tests/js/pages/OnboardingCheckout.test.ts b/tests/js/pages/OnboardingCheckout.test.ts
new file mode 100644
index 0000000..4a759ae
--- /dev/null
+++ b/tests/js/pages/OnboardingCheckout.test.ts
@@ -0,0 +1,222 @@
+import { afterEach, describe, expect, it, vi } from "vitest";
+import { cleanup, fireEvent, render, screen } from "@testing-library/svelte";
+import Checkout from "@/pages/Onboarding/Checkout.svelte";
+import type { OnboardingCheckoutShape } from "@/types/onboarding";
+
+// router.post をモックする。page (Inertia store) も hoisted fake でモックし、props.errors を
+// 注入して「押下 → サーバが redirect-back で返した文言を表示する」経路 (D4) を検証する。
+const { routerPostMock, pageState } = vi.hoisted(() => ({
+    routerPostMock: vi.fn(),
+    pageState: { props: {} as Record<string, unknown> },
+}));
+
+vi.mock("@inertiajs/svelte", async (importOriginal) => ({
+    ...(await importOriginal<typeof import("@inertiajs/svelte")>()),
+    router: {
+        post: routerPostMock,
+    },
+    page: pageState,
+}));
+
+/*
+ * 課金オンボーディングのプラン選択画面。
+ * - plan grid はサーバが露出したプラン (is_active=true ∧ PlanCode 集合) のみを出す
+ * - D4 (AGENTS.md 禁止事項 #8): 必須条件未充足でも CTA は押せ、押下後にサーバ文言を表示する
+ *   (文言はすべてサーバ確定 = frontend で組み立てない)
+ * - D28: 月次チケット付与は廃止済 = 「月 N 枚」表記を出さない
+ */
+
+const organization = { id: 1, name: "テスト組織", slug: "test-org" };
+
+const basePageData: OnboardingCheckoutShape = {
+    plans: [
+        { code: "personal", name: "Personal", currentBaseAmount: null, isActive: true },
+        { code: "starter", name: "Starter", currentBaseAmount: 4980, isActive: true },
+        { code: "standard", name: "Standard", currentBaseAmount: 19800, isActive: true },
+    ],
+    recommendedPlanCode: "standard",
+    defaultPlanCode: "starter",
+    contactUrl: "/contact?source=onboarding",
+    personalEligibility: { eligible: true, reason: null, reasonLabel: null },
+    signupGrantTickets: 10,
+};
+
+afterEach(() => {
+    cleanup();
+    routerPostMock.mockReset();
+    pageState.props = {}; // errors 注入をリセット (テスト間の汚染防止)
+});
+
+function renderPage(overrides: Partial<OnboardingCheckoutShape> = {}): void {
+    render(Checkout, {
+        props: { organization, pageData: { ...basePageData, ...overrides } },
+    });
+}
+
+async function choosePersonal(): Promise<void> {
+    await fireEvent.click(screen.getByTestId("select-plan-personal"));
+}
+
+describe("Onboarding/Checkout", () => {
+    it("サーバが露出したプランのみを plan grid に出す (未露出 code は出ない)", () => {
+        renderPage();
+
+        expect(screen.getByTestId("plan-card-personal")).toBeInTheDocument();
+        expect(screen.getByTestId("plan-card-starter")).toBeInTheDocument();
+        expect(screen.getByTestId("plan-card-standard")).toBeInTheDocument();
+        // business / enterprise / legacy free はサーバの露出規則 (is_active=true ∧ PlanCode 集合)
+        // から外れるため props に来ない = 描画されない
+        expect(screen.queryByTestId("plan-card-business")).not.toBeInTheDocument();
+        expect(screen.queryByTestId("plan-card-free")).not.toBeInTheDocument();
+        expect(screen.getByTestId("recommended-badge-standard")).toBeInTheDocument();
+        // Personal は基本料金なし = 無料表示契約
+        expect(screen.getByTestId("plan-card-personal")).toHaveTextContent("無料");
+    });
+
+    it("defaultPlanCode が plans にあるときはそれを強調する", () => {
+        renderPage();
+
+        expect(screen.getByTestId("plan-card-starter")).toHaveClass("border-primary");
+        expect(screen.getByTestId("plan-card-personal")).not.toHaveClass("border-primary");
+    });
+
+    it("defaultPlanCode が plans に無いときは先頭 plan を強調する", () => {
+        renderPage({ defaultPlanCode: "business" });
+
+        expect(screen.getByTestId("plan-card-personal")).toHaveClass("border-primary");
+        expect(screen.getByTestId("plan-card-starter")).not.toHaveClass("border-primary");
+    });
+
+    it("月次付与は廃止済のため「月 N 枚」表記を出さない (D28)", async () => {
+        renderPage();
+        await choosePersonal();
+
+        expect(screen.getByTestId("onboarding-checkout").textContent ?? "").not.toMatch(
+            /月\s*\d+\s*枚/,
+        );
+    });
+
+    it("declaration 未チェックでも Personal CTA は押せ、declaration=0 で送信する", async () => {
+        renderPage();
+        await choosePersonal();
+
+        const submit = screen.getByTestId("personal-free-submit");
+        expect(submit).not.toBeDisabled();
+
+        await fireEvent.click(submit);
+        expect(routerPostMock).toHaveBeenCalledWith(
+            "/onboarding/activate-personal",
+            { declaration: "0" },
+            expect.anything(),
+        );
+    });
+
+    it("declaration 未チェックで押下した後、サーバが返した validation error を表示する", async () => {
+        // redirect-back 着地 (errors 付き props) を再現する
+        pageState.props = { errors: { declaration: "個人利用であることの確認が必要です。" } };
+        renderPage();
+        await choosePersonal();
+
+        expect(screen.getByText("個人利用であることの確認が必要です。")).toBeInTheDocument();
+    });
+
+    it("declaration チェック時は declaration=1 で送信する", async () => {
+        renderPage();
+        await choosePersonal();
+
+        await fireEvent.click(screen.getByTestId("personal-declaration"));
+        await fireEvent.click(screen.getByTestId("personal-free-submit"));
+
+        expect(routerPostMock).toHaveBeenCalledWith(
+            "/onboarding/activate-personal",
+            { declaration: "1" },
+            expect.anything(),
+        );
+    });
+
+    it("eligible=false でも Personal は選択でき CTA も押せる (理由はサーバ由来文言を常時提示)", async () => {
+        renderPage({
+            personalEligibility: {
+                eligible: false,
+                reason: "organization_has_multiple_members",
+                reasonLabel: "メンバーが 2 名以上の組織では選択できません",
+            },
+        });
+
+        expect(screen.getByTestId("personal-eligibility-reason")).toHaveTextContent(
+            "メンバーが 2 名以上の組織では選択できません",
+        );
+
+        const selectPersonal = screen.getByTestId("select-plan-personal");
+        expect(selectPersonal).not.toBeDisabled();
+        await fireEvent.click(selectPersonal);
+
+        const submit = screen.getByTestId("personal-free-submit");
+        expect(submit).not.toBeDisabled();
+        await fireEvent.click(submit);
+        expect(routerPostMock).toHaveBeenCalledTimes(1);
+    });
+
+    it("eligible=false の押下後にサーバ確定文言 (errors.plan_code) を表示する", async () => {
+        pageState.props = { errors: { plan_code: "有効な契約がある組織では選択できません" } };
+        renderPage({
+            personalEligibility: {
+                eligible: false,
+                reason: "organization_has_active_subscription",
+                reasonLabel: "有効な契約がある組織では選択できません",
+            },
+        });
+        await choosePersonal();
+
+        // 押下前は plan エラーを出さない (押下したプランに紐づけて表示する)
+        expect(screen.queryByTestId("checkout-plan-error")).not.toBeInTheDocument();
+
+        await fireEvent.click(screen.getByTestId("personal-free-submit"));
+        expect(screen.getByTestId("checkout-plan-error")).toHaveTextContent(
+            "有効な契約がある組織では選択できません",
+        );
+    });
+
+    it("eligibility が null でも描画が壊れず CTA は押せる", async () => {
+        renderPage({ personalEligibility: null });
+        await choosePersonal();
+
+        expect(screen.queryByTestId("personal-eligibility-reason")).not.toBeInTheDocument();
+        await fireEvent.click(screen.getByTestId("personal-free-submit"));
+        expect(routerPostMock).toHaveBeenCalledTimes(1);
+    });
+
+    it("有償プランは plan_code のみを課金 checkout に送る", async () => {
+        renderPage();
+
+        await fireEvent.click(screen.getByTestId("select-plan-starter"));
+        expect(screen.queryByTestId("personal-free-step")).not.toBeInTheDocument();
+
+        await fireEvent.click(screen.getByTestId("paid-plan-submit"));
+        expect(routerPostMock).toHaveBeenCalledWith(
+            "/billing/checkout",
+            { plan_code: "starter" },
+            expect.anything(),
+        );
+    });
+
+    it("プランを切り替えると前のプランで出たエラーが消える", async () => {
+        pageState.props = { errors: { plan_code: "パーソナルプランは選択できません" } };
+        renderPage();
+        await choosePersonal();
+        await fireEvent.click(screen.getByTestId("personal-free-submit"));
+        expect(screen.getByTestId("checkout-plan-error")).toBeInTheDocument();
+
+        await fireEvent.click(screen.getByTestId("select-plan-standard"));
+        expect(screen.queryByTestId("checkout-plan-error")).not.toBeInTheDocument();
+    });
+
+    it("問い合わせ導線 (Enterprise) をサーバ由来 URL で出す", () => {
+        renderPage();
+
+        expect(screen.getByTestId("onboarding-contact-link")).toHaveAttribute(
+            "href",
+            "/contact?source=onboarding",
+        );
+    });
+});
```

## テスト結果

- composer test: **1996 tests / 1994 passed / 0 failed / 2 skipped**（8203 assertions）
- composer phpstan: **[OK] No errors**（level 10）
- pint --test / pnpm lint / pnpm typecheck: すべて passed
- Architecture suite: 93 tests 緑（**allowlist 追加ゼロ** = 設計の DoD どおり）
- JS arch (page-shell-structure / ds-purity / atomic-import-graph / lucide-scoped-import): 30 passed（allowlist 追加なし）
