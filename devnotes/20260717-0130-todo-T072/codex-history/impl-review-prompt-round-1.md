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
コマンド実行・ファイル書き込みは行わず、提供されたテキストの分析に集中すること。ファイル読み込みは許可。
AI-CUE=/workspace（worktree=/workspace/.claude/worktrees/tasks/T072）、参照実装 aigenba=/tmp/aigenba が読める。

---

あなたは経験豊富なコードレビュアーです。Laravel + Svelte の実装をレビューしてください。

【前提】
- PHP 8.4 / Laravel 12 / Cashier(Stripe) / Svelte 5 runes / Inertia / PHPStan level 10 / Pest
  (RefreshDatabase グローバル・--parallel、個別 DatabaseTransactions 禁止、テストデータは Factory)
- 本 PR は決済ドメイン aigenba parity 設計の **P1 (T072 = プラン基盤)** の実装。

【本件の最重要方針 (v2)】
**aigenba verbatim で移植する。「parity より良い設計」を持ち込まない**。
逸脱してよいのは **AGENTS.md の禁止事項・セキュリティ不変条件に抵触する場合のみ**（実装者の設計判断は根拠にしない）。
値は aigenba 既定値のまま。この方針は、過去に「parity より良い設計」を持ち込んだ結果、レビューで指摘されたバグの
5/7 件がその独自実装に由来していたことが検証で判明したため確定した。

【レビュー観点】
1. **設計との一致性**: 詳細設計 P1 セクションのとおりか。**設計に無いものを足していないか**。
   **aigenba verbatim から不必要に逸脱していないか**（逸脱は AGENTS.md 由来のみ許容）。
2. コードの正確性（ロジックエラー、エッジケース、null 安全性、並行性）
3. PHPStan level 10 適合性（widen / baseline / @phpstan-ignore を使っていないか）
4. テスト網羅性（各施策にテスト、既存テストを削除していないか＝期待の更新に留めているか、Factory 生成か）
5. DTO / JsonResource パターン / Inertia props
6. セキュリティ（AGENTS.md の不変条件。特に #1 tenant キー不信 / #7 課金の冪等性）
7. 副作用・後退リスク（**特に D28 = 月次付与廃止の波及**、`grantSignupGrant` のシグネチャ変更の call site 網羅）
8. DESIGN.md 準拠（token 経由・hex 直書きを増やしていないか）/ Atomic Design 準拠

【特に見てほしい点】
- **`PersonalPlanService::activate()` の並行安全性**（org 行ロック → eligibility 再検証 → marker 条件付き先取 →
  先取できた場合のみ grant、が同一 tx で成立しているか。**後着が 500 にならず AlreadyHasFreePersonalOrg になるか**）。
- **移行期規約**（`CreateNewUser` が marker を同時設定しつつ、付与契機・枚数・「招待経由は非付与」の現行挙動を変えていないか）。
- **`tests/Architecture/MembershipWriteLockInventoryTest.php` の変更が妥当か**（元 guard は role_user への言及自体を
  read 含めて禁止していた。`PersonalPlanService::eligibility()` の owner 在籍判定（aigenba verbatim の read）が抵触したため、
  read 専用許可リスト + 「許可ファイルが Laratrust 書き込み API を含まないこと」の強制に置き換えた。
  **guard を弱めていないか**。負のコントロール（書き込み注入で fail / 除去で pass / probe 残留なし）は実行検証済み）。
- **backfill data migration** が既存 `signup_grant:%` 履歴と正しく対応するか（冪等・down() no-op）。
- **D28** の波及漏れ（seeder / 既存テストの期待更新 / 料金表・課金ページの「月 N 枚」表示撤去）。

【出力形式】
- ファイルごとに判定: APPROVE / REQUEST_CHANGES
- 指摘は [Critical] [Warning] [Suggestion] で分類
- Critical/Warning には必ず修正案を添える
- 全体判定: APPROVED / CHANGES_REQUESTED
- 日本語で出力

---

## 詳細設計書（P1 セクション + 冒頭の横断決定 v2）

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

### P1 プラン基盤（PlanCode 5 case / plans.is_active / D28 月次付与廃止 / free plan・signup marker 列 / PersonalPlanService / marker backfill）

**DoD**: **ゲートは反転しない**。`BillingAccess::hasActiveAccess()` は無改変で、既存の業務ルート到達性・`RequireActiveSubscriptionMiddlewareTest` / `SeededFreePlanBillingAccessTest` の期待は変わらない。`organizations` への列追加はすべて additive（既存行を書き換えない）。`PersonalPlanService::activate()` は **P1 で完成**させる（marker claim + grant を含む）が、まだどの route からも呼ばれない（配線は P3）。signup grant の付与契機は移行期規約に従い **P6 まで登録時を維持**する。
**P1 で意図的に変わる挙動（v2 で明示）**: (a) `PlanSeeder` に personal / starter が `is_active=true` で加わり `/pricing`・`/billing` のプラン件数が 2 → 4 になる、(b) **D28** により全 tier の `monthly_ticket_grant` が 0 になり `invoice.paid` の月次付与行が seed 既定では生成されなくなる（コード経路は不変）、(c) 料金表・課金ページの「月 N 枚のチケット付与」表示が廃止される。

#### 変更箇所（ファイルパス + 何をするか。移植元 aigenba のパスを併記）

| AI-CUE | 内容 | 移植元 |
|---|---|---|
| `app/Enums/PlanCode.php`（新規） | **verbatim 5 case**: `Personal='personal' / Starter='starter' / Standard='standard' / Business='business' / Enterprise='enterprise'`。`requiresStripeCheckout()` も verbatim（Starter/Standard/Business=true、Personal/Enterprise=false）。**`isSeatFixed()` は移植しない**（AI-CUE に席概念・`plans.included_seats` が無い = 原則 4）。Business / Enterprise は Plan 行を持たないが enum の case は縮小しない | `/tmp/aigenba/app/Enums/PlanCode.php` |
| `database/migrations/2026_07_17_000100_add_free_plan_and_signup_grant_marker_to_organizations.php`（新規） | **verbatim**: `free_plan_code`(string 32 nullable) / `free_plan_activated_at` / `personal_declared_at` / `personal_declared_by_user_id`(FK users nullOnDelete) / `signup_tickets_granted_at` + `index('free_plan_code')` + raw partial unique index。`down()` も verbatim | `/tmp/aigenba/database/migrations/2026_07_08_113500_add_free_plan_and_signup_grant_marker_to_organizations.php` |
| `database/migrations/2026_07_17_000110_backfill_signup_tickets_granted_at.php`（新規） | **verbatim の data migration（列追加とは別 migration に分離）**。`ticket_ledger_entries.idempotency_key LIKE 'signup_grant:%'` を持つ org に `min(granted_at)` で marker を立てる。`whereNull` ガードで冪等、`down()` は意図的 no-op。AI-CUE の実スキーマ（`ticket_ledger_entries` / `organization_id` / `idempotency_key` / `granted_at`。`2026_06_11_091400_create_ticket_tables.php:37-61`）と完全一致 | `/tmp/aigenba/database/migrations/2026_07_08_113550_backfill_signup_tickets_granted_at.php` |
| `database/migrations/2026_07_17_000120_add_is_active_to_plans.php`（新規） | `plans.is_active`(boolean NOT NULL default **true**) を追加。既存 `free` / `standard` 行は default で true になり公開状態は変わらない | aigenba `create_plans_table.php` の `is_active` |
| `app/Models/Billing/Plan.php` | `is_active` を `$fillable` と `casts()`（`boolean`）へ追加、`@property bool $is_active` を docblock へ | `/tmp/aigenba/app/Models/Billing/Plan.php` |
| `app/Services/Marketing/PricingService.php` | `listPublicPlans()` に `->where('is_active', true)` を追加（公開制御の唯一の場所）。`PricingPlanDto` から `monthlyTicketGrant` を落とす（D28。L51） | aigenba の `plans.is_active` 露出制御 |
| `database/seeders/PlanSeeder.php` | **personal**（Price 無し・`sort_order=1`）と **starter**（base ¥980・`sort_order=2`）を `updateOrCreate` で追加し、`standard` の `sort_order` を 3 へ（aigenba SPECS の tier 順 personal → starter → standard に一致させる。`free` は `sort_order=0` のまま P4 まで残置）。**D28: 全 tier の `monthly_ticket_grant` を 0**（free 10→0 / standard 100→0 / personal 0 / starter 0）。`is_active` は属性配列に入れず **`wasRecentlyCreated` のときのみ `true` を確定**（aigenba verbatim。運用者の手動変更を seed 再実行で踏み潰さない。既存 free / standard 行は migration default の true が残る）。`PlanCode::from($code)` の membership assert は **P1 では入れない**（`free` 行が P4 まで残り ValueError になるため。P4 の free 撤去と同時に導入する） | `/tmp/aigenba/database/seeders/PlanSeeder.php`（SPECS の personal / starter・`included_monthly_tickets=0`・`wasRecentlyCreated` の is_active 確定・Personal の Price skip） |
| `app/Support/Billing/StripePriceLookupKeys.php` + `stripe/fixtures/plan_starter.json`（新規） | `CATALOG` に `'starter' => [PlanPriceKind::Base]` を追加し、fixture（product + `unit_amount=980` / `lookup_key='app_starter_base'` / `recurring.interval=month`）を**同一 PR で**追加（`StripePriceCatalogFixtureInvariantTest` が lookup_key の集合一致を強制）。**personal は Price を投入しない**（`requiresStripeCheckout()=false` = aigenba の Personal skip 規約と同値） | `/tmp/aigenba/database/seeders/PlanSeeder.php` の `in_array($code, ['enterprise','personal'], true)` skip |
| `config/quota.php` | `plans` に **`personal`**（`max_projects=1` / `max_members=3` / `max_storage_bytes=1GiB`）と **`starter`**（personal と同値）を追加。値の根拠: aigenba では personal と starter の能力値が同一（`included_seats=3` / `scenario_limit=10` / `course_limit=5`）で、AI-CUE の該当 tier は現行 `free`（1 / 3 / 1GiB）。`max_members=3` は aigenba `included_seats=3` と一致。`fallback_plan` は **`'free'` のまま**（撤去は P4）。**未追加だと `QuotaService.php:33` の `?? []` で無制限へ silent 退行する** | 原則 3（値を憶測でいじらない） |
| `app/Services/Billing/PersonalPlanService.php`（新規） | `eligibility()` / `activate()` / `retireForPaidSubscription()` / `hasOtherActiveFreePersonalOrg()` / `isDeclarerUniqueViolation()` を verbatim 移植。**`activate()` は P1 で完成**（org 行 `lockForUpdate()` → eligibility 再検証 → `forceFill` → marker の条件付き先取 → 先取できた場合のみ `grantSignupGrant` を同一 transaction で）。`QueryException` の declarer unique 違反は `PersonalPlanNotEligibleException(AlreadyHasFreePersonalOrg)` へ変換し **並行 activate の後着を 500 にしない** | `/tmp/aigenba/app/Services/Billing/PersonalPlanService.php` |
| `app/DataTransferObjects/Billing/PersonalPlanActivationResultDto.php` / `PersonalPlanEligibilityDto.php`（新規） | verbatim（`@phpstan-type PersonalPlanEligibilityShape` 込み） | 同名 aigenba ファイル |
| `app/Enums/Billing/PersonalPlanIneligibleReason.php`（新規） | verbatim（`HasEntitledSubscription` / `TooManyMembers` / `AlreadyHasFreePersonalOrg` + `label()` の日本語文言） | `/tmp/aigenba/app/Enums/Billing/PersonalPlanIneligibleReason.php` |
| `app/Exceptions/Billing/PersonalPlanNotEligibleException.php`（新規） | verbatim（`userMessage()`）。422 への変換は P3（Controller 層） | 同名 aigenba ファイル |
| `app/Services/Billing/PlanPriceService.php`（新規） | `replaceCurrent()` を移植。AI-CUE の `plan_prices` は `lookup_key` を持ち `SyncStripePrices.php:78-87` が「kind + is_current + lookup_key 一致」の current 行を要求するため **`?string $lookupKey = null` を追加**（D14。それ以外は verbatim。`is_current ⇔ active_to IS NULL` の CHECK は「旧 current 無効化 → 新規作成」の順序で満たされる） | `/tmp/aigenba/app/Services/Billing/PlanPriceService.php` |
| `app/Models/Organization.php` | 新 5 列を `casts()` に追加（timestamp 4 本は `immutable_datetime` / `personal_declared_by_user_id` は `integer`）。`$fillable` は不変（書き込みは `PersonalPlanService` の `forceFill` 経由のみ）。docblock に「free entitlement は `free_plan_code` 側で表現する」を追記。**`plan_code=null=free tier` の既存記述は P4 まで有効なので消さない** | aigenba `Organization` |
| `app/Support/Security/MassAssignmentProtectedKeys.php` | actor キーとして `'personal_declared_by_user_id'` を追加（`MassAssignmentSafetyTest` が `$fillable` 不含を検証する） | 不変条件 #1 |
| `app/Services/Billing/TicketLedgerService.php` | `grantSignupGrant(Organization $organization, string $idempotencyKey): void` へ**シグネチャ変更**（内部生成キーをやめ、呼び出し側が `signup_grant:org:{orgId}` / `signup_grant:personal:{orgId}` を渡す）。枚数・期限・`insertOrIgnore` の冪等は不変。`grantMonthly()` は無改変 | `/tmp/aigenba/app/Services/Billing/TicketService.php:261` |
| `app/Actions/Fortify/CreateNewUser.php`（L106） | **移行期規約**: 既存の登録 tx 内で `PersonalPlanService::claimSignupGrantMarker($org)` を呼び、**先取できたときのみ** `grantSignupGrant($org, "signup_grant:org:{$id}")`。org 行 `lockForUpdate()` 下・同一 tx。**付与契機・枚数・「招待経由は非付与」の現行挙動は不変**（marker を同時に立てるだけ） | 概念設計 §signup grant の冪等移行 規約 |
| `app/Services/Billing/StripeWebhookProcessor.php`（L270） | `grantSignupGrant($organization)` → `grantSignupGrant($organization, "signup_grant:org:{$organizationId}")` の**引数適合のみ**。`grantMonthlyTickets()` の既存 guard `$plan->monthly_ticket_grant <= 0`（L274）が **D28 で常に成立** = aigenba の `if ($count < 1) return;` と同形になる。**コードは変更しない**。paid 経路の marker claim ブロック追加は P6 | D28 |
| `resources/js/pages/Pricing.svelte`（L29） / `resources/js/pages/Billing/Index.svelte`（L154） | **D28 波及**: 「月 {N} 枚のチケット付与」の feature 行 / 表示を削除（`monthly_ticket_grant=0` で「月 0 枚」と表示されるのは虚偽。後方互換の並走を残さない = 思考原則 3）。signup grant 10 枚・チケット都度購入の説明は既存 FAQ / `signupGrantTickets` props がそのまま担う | D28 |

**移植時の adaptation（名前解決と既存契約への適合のみ。意味論は不変）**

- `TicketService` → `TicketLedgerService`。
- `Role::OrganizationOwner` → `App\Enums\OrganizationRole::Owner`（値 `organization_owner`）。laratrust pivot は AI-CUE も `role_user` + `role_user.team_id`（`config/laratrust.php:151`）のため `hasOtherActiveFreePersonalOrg()` の `whereColumn('role_user.team_id', 'organizations.laratrust_team_id')` はそのまま成立する。
- `hasEntitledSubscription()`: P2 の `SubscriptionService::deriveEntitlement()` が未着のため、P1 は `subscription('default')?->stripe_status ∈ {active, trialing}`（= 現行 `BillingAccess::GRANTING_STATUSES` と同値）で実装し、docblock に **P2 で `deriveEntitlement()` へ差し替える seam** と明記する。
- `MAX_MEMBERS = 3`（aigenba verbatim。`config/quota.php` の `personal.max_members=3` と一致。invariant テストで固定）。
- `claimSignupGrantMarker(Organization $org, ?CarbonImmutable $now = null): bool` を **移行期は public**（aigenba では `activate()` 内 private）。移行専用 API である旨を docblock に明記し、**P6 で private へ戻す**（D13）。

#### 波及変更

- **TypeScript 型定義**:
  - `resources/js/types/marketing.ts`（L26） — `PricingPlanShape.monthlyTicketGrant` を削除（D28）。
  - `resources/js/pages/Billing/Index.svelte`（L28） — ローカル props 型から `monthlyTicketGrant: number` を削除。
- **DTO / JsonResource**:
  - `app/DataTransferObjects/Marketing/PricingPlanDto.php`（L22 の `@phpstan-type` / L34 の promoted property / L49 の `toArray()`）— `monthlyTicketGrant` を削除。
  - 新規 `PersonalPlanActivationResultDto` / `PersonalPlanEligibilityDto`（P1 時点では Service 戻り値のみ。Controller からは返さない）。
- **Inertia props**:
  - `/pricing`（`page.plans`）— 件数 2 → **4**（free / personal / starter / standard の sort_order 昇順）、各要素から `monthlyTicketGrant` が消える。
  - `/billing`（`plans`）— 件数 2 → **4**、`monthlyTicketGrant` が消える（`BillingController.php:50`）。`currentPlanCode` / `ticketBalance` / `canManageBilling` は不変（`plan_code` の raw 読みの解消は P2）。
- **Factory**: `database/factories/OrganizationFactory.php` に `freePersonal(User $declarer)`（`free_plan_code='personal'` + declared_*）/ `grandfathered()`（declarer NULL）/ `signupGranted()` state を追加（テストデータ手組み禁止のため）。
- **Filament**: `app/Filament/Resources/PlanResource.php` / `Plans/Pages/EditPlan.php` — **変更なし**（`monthly_ticket_grant` 列とコード経路は D28 でも残すため、管理画面の編集口もそのまま）。
- **Seeder**:
  - `database/seeders/BughuntBillingSeeder.php` — **変更不要**（`plan.monthly_ticket_grant` を読まず `grantMonthly($org, 100, null, "bughunt:initial-grant:{id}", …)` で直接付与しているため、D28 でも探索用残高は維持される）。ただし `paidPlanCodes()`（base Price を持つプラン）が starter を含むようになり、対象組織が増える（意図どおり）。
  - `database/seeders/ManualTestSeeder.php` — **変更不要**（`TEST_TICKETS` を `TicketLedgerService::grant()` で直接付与するため dev シードの最低保証は D28 の影響を受けない）。プラン行増加により「パーソナルプラン組織」「スタータープラン組織」が生成される（starter は base Price を持つため plan_code + fake active subscription が付く = 既存不変条件どおり）。
- **テストファイル（更新。削除しない）**:
  - `tests/Feature/Billing/TicketGrantTest.php`（L82-137） — `grantSignupGrant` 呼び出しに `$idempotencyKey` 引数を追加（枚数・期限・冪等の期待は不変）。
  - `tests/Feature/Billing/WebhookIdempotencyTest.php`（L93-100） — **D28**: seed 既定では月次付与が 0 になるため、arrange で `standard` の `monthly_ticket_grant` を 100 に設定してから「monthly:{invoiceId} の冪等」を検証する形へ更新（コード経路の検証を維持）。付与 1 回の期待値・entries 数を更新。
  - `tests/Feature/Billing/InvoiceLinePricingShapeTest.php`（L79-95） — **D28**: 同様に arrange で `monthly_ticket_grant=100` を設定。`pricing.price_details.price` / 旧 `price.id` の形状解決という関心は不変。
  - `tests/Feature/Marketing/PricingPageTest.php`（L29-47） — plans 件数 2 → 4、index を sort_order（free / personal / starter / standard）へ、`monthlyTicketGrant` の期待（10 / 100）を削除、personal（`baseAmountJpy=null`・1/3/1）と starter（`baseAmountJpy=980`・1/3/1）の期待を追加。
  - `tests/Feature/Billing/BillingPageTest.php`（L26-33） — `has('plans', 2)` → 4、index 更新、`plans.*.monthlyTicketGrant` の期待を削除。
  - `tests/js/pages/Pricing.test.ts`（L17-28） — fixture から `monthlyTicketGrant` を削除、「月 N 枚」行の期待を削除。
  - `tests/Feature/Billing/SeededFreePlanBillingAccessTest.php`（L21-26 の `seededFreePlan()`） — 「current base Price を持たない最初の Plan」を拾う実装が personal 追加で非決定になるため `code='free'` 固定へ。**ゲート期待値は変えない**（未反転の証明）。
  - `tests/Feature/Billing/PlanSeederPriceInvariantTest.php` — 「有償プラン starter は current base Price を持つ」「personal は Price を持たない（`prices()->count()===0`）」を追加。free / standard の既存期待は維持。
  - `tests/Feature/Billing/SyncStripePricesCommandTest.php` / `VerifyStripePricesCommandTest.php` — starter の fixture + lookup_key 追加を反映。
  - `tests/Feature/Auth/RegistrationTest.php`（L30） — 付与枚数の期待は不変。`signup_tickets_granted_at` が**同一 tx で立つ**期待を追加。
  - `tests/Architecture/MassAssignmentSafetyTest.php` / `StripePriceCatalogFixtureInvariantTest.php` / `QuotaKeyConfigInvariantTest.php` — コード変更不要（キー・fixture・config 追加で自動的に集合一致を検証）。
- **テストファイル（新規）**: `tests/Feature/Billing/PersonalPlanServiceTest.php` / `tests/Feature/Billing/SignupGrantOncePerOrgTest.php` / `tests/Feature/Billing/PlanActiveFilterTest.php` / `tests/Feature/Billing/PlanPriceServiceTest.php` / `tests/Feature/Billing/PlanQuotaCoverageInvariantTest.php` / `tests/Architecture/FreePlanCodeWriteInvariantTest.php`。

#### 主要な契約

```php
enum PlanCode: string {                                  // aigenba verbatim (5 case)
    case Personal = 'personal'; case Starter = 'starter'; case Standard = 'standard';
    case Business = 'business'; case Enterprise = 'enterprise';
    /** Personal は free (activate 経由)、Enterprise は営業導線のため checkout を通らない */
    public function requiresStripeCheckout(): bool;      // Starter|Standard|Business => true
}

final class PersonalPlanService {
    public const FREE_PLAN_CODE = 'personal';
    public const MAX_MEMBERS = 3;
    public function __construct(private readonly TicketLedgerService $tickets) {}
    public function eligibility(Organization $org, User $user): PersonalPlanEligibilityDto;
    /** org 行 lockForUpdate → eligibility 再検証 → forceFill → marker 先取 → 先取時のみ grant。全て同一 tx
     *  @throws PersonalPlanNotEligibleException 並行 activate の後着 (declarer unique 違反) を含む */
    public function activate(Organization $org, User $declarer): PersonalPlanActivationResultDto;
    public function retireForPaidSubscription(Organization $org): void;
    /** 移行期専用 public API (P6 で private 化)。org 行 lockForUpdate 下で marker を先取できたら true */
    public function claimSignupGrantMarker(Organization $org, ?CarbonImmutable $now = null): bool;
}

final readonly class PersonalPlanActivationResultDto { public function __construct(public bool $granted) {} }
final readonly class PersonalPlanEligibilityDto {  // eligible() / ineligible(reason) / toArray()
    public bool $eligible; public ?PersonalPlanIneligibleReason $reason; }
enum PersonalPlanIneligibleReason: string { HasEntitledSubscription | TooManyMembers | AlreadyHasFreePersonalOrg; }

class TicketLedgerService { public function grantSignupGrant(Organization $o, string $idempotencyKey): void; }

class PlanPriceService {   // D14: ?string $lookupKey のみ adaptation
    public function replaceCurrent(Plan $plan, PlanPriceKind $kind, string $stripePriceId, int $amount,
        ?string $lookupKey = null, string $currency = 'jpy', ?CarbonImmutable $activeFrom = null): PlanPrice;
}
```

`activate()` の中核（marker claim + grant を含む完成形。aigenba verbatim）:

```php
$fresh = Organization::query()->lockForUpdate()->findOrFail($org->id);
// … eligibility 再検証 → forceFill(free_plan_code / free_plan_activated_at / personal_declared_at / personal_declared_by_user_id)
$claimed = DB::table('organizations')->where('id', $fresh->id)
    ->whereNull('signup_tickets_granted_at')->update(['signup_tickets_granted_at' => $now]);
if ($claimed === 1) { $this->tickets->grantSignupGrant($fresh, 'signup_grant:personal:'.$fresh->id); }
return new PersonalPlanActivationResultDto(granted: $claimed === 1);
```

**DB（`organizations`。全て nullable / additive）**: `free_plan_code varchar(32)` + `index`、`free_plan_activated_at ts`、`personal_declared_at ts`、`personal_declared_by_user_id → users.id (nullOnDelete)`、`signup_tickets_granted_at ts`。
**partial unique index（aigenba verbatim・改変禁止）**:
```sql
CREATE UNIQUE INDEX organizations_personal_free_declarer_unique
ON organizations (personal_declared_by_user_id)
WHERE free_plan_code = 'personal' AND personal_declared_by_user_id IS NOT NULL
```
→ declarer NULL 行（P4 の grandfathered backfill）は **index 対象外**。
**DB（`plans`）**: `is_active boolean NOT NULL DEFAULT true`。
**seed 後の plans（P1 時点）**: `free`(0, grant 0, Price 無, is_active=true) / `personal`(1, grant 0, Price 無, true) / `starter`(2, grant 0, base ¥980 `app_starter_base`, true) / `standard`(3, grant 0, base ¥4,980, true)。
**冪等キー**: `signup_grant:org:{orgId}`（登録経路 = 移行期）/ `signup_grant:personal:{orgId}`（activate）。既存の partial unique `ticket_ledger_entries_signup_grant_unique ON (organization_id) WHERE idempotency_key LIKE 'signup_grant:%'`（`2026_07_13_180622`）が**キー種別を跨いで org 生涯 1 回を DB 強制済み** → marker と 1:1 の二重防御。
**ルート**: 追加・変更なし（P3）。

#### PHPStan 適合チェック（level 10）

- `PlanCode` は 5 case で閉じ、`requiresStripeCheckout()` の `match` は網羅（default 不要）。Plan 行が無い Business / Enterprise も enum としては静的に閉じるため `alwaysFalse` は生じない（v1 の 3 case 縮小で発生した `identical` 常偽比較の懸念はここでは起きない）。
- `Organization::query()->lockForUpdate()->findOrFail($org->id)` は `Builder<Organization>` generics から `Organization` を返す（`@var` 不要）。`$org->id` は `Assert::integer()` で絞る（現行 `TicketLedgerService::grantSignupGrant` と同じ作法）。
- `DB::table('organizations')->…->update([...])` は `int` 戻り → `$claimed === 1` は型安全。
- `QueryException::getCode()` は `Throwable::getCode()` の宣言上 `int` だが PDO 由来は string を返すため、`in_array((string) $e->getCode(), ['23000','23505'], true)` とする（キャストによる正規化。禁止事項 2 の widen ではない）。
- `PersonalPlanEligibilityDto::toArray()` は `@phpstan-type PersonalPlanEligibilityShape array{eligible: bool, reason: string|null, reasonLabel: string|null}` で形状固定（aigenba verbatim）。
- `hasOtherActiveFreePersonalOrg()` のクロージャ引数は `Illuminate\Database\Eloquent\Builder $q` を型注釈。戻りは `exists()` の `bool`。
- `Plan::updateOrCreate()` は `Plan` を返し `$plan->wasRecentlyCreated` は `bool`、`$plan->is_active = true` は `@property bool $is_active` で型解決される。
- `PricingPlanDto` の `@phpstan-type PricingPlanShape` から `monthlyTicketGrant: int` を削除し、`PricingPageDto` 側の配列形状と一致させる（片方だけ消すと `array{...}` 不一致で level 10 が落ちる）。
- 新 casts は `protected function casts(): array` の `array<string, string>` 契約内（`immutable_datetime` / `integer` / `boolean`）。
- `config()` 参照は `config()->string()` / `config()->array()` か `Assert::integer()` 経由（既存 `grantSignupGrant` / `PricingService` の作法を踏襲）。
- `response()->json()` の直書きは無し（P1 は Controller のロジックを増やさない）。

#### テスト計画（テストファースト）

**先に red を作る（新規）**

1. `tests/Feature/Billing/PersonalPlanServiceTest.php`
   - `activate()` が `free_plan_code='personal'` / `free_plan_activated_at` / `personal_declared_at` / `personal_declared_by_user_id` を埋め、`signup_tickets_granted_at` を立て、`config('billing.signup_grant_tickets')` 枚を **1 回だけ**付与し `granted=true` を返す（**activate が P1 で完成している**ことの固定）。
   - 同一 org の再 `activate()` は `granted=false` かつ**残高不変**（marker 先取が 0 件）。
   - `eligibility()` の 3 理由: 有効 subscription あり（active/trialing）/ メンバー 4 名 / 同一 declarer の別 free personal org。
   - **並行 activate の後着**: 同一 declarer で別 org を `activate()` → partial unique 違反が `PersonalPlanNotEligibleException(AlreadyHasFreePersonalOrg)` に変換され **`QueryException` が漏れない（= 500 にしない）**。
   - `retireForPaidSubscription()` の冪等（2 回目 no-op。`personal_declared_*` は監査証跡として残る）。
2. `tests/Feature/Billing/SignupGrantOncePerOrgTest.php`（**P1 の要**）
   - **free activate ↔ paid webhook の競合で二重付与しない**: activate 済み org に `invoice.paid (billing_reason=subscription_create)` → 付与 0（signup ledger 行は 1 のまま）。逆順（paid 成立済み org を activate）は `eligibility()` の `HasEntitledSubscription` で弾かれ付与 0。
   - **移行期回帰（必須）**: 移行期に `CreateNewUser` 経由で登録された org（marker 済み）を `activate()` に掛けても再付与されない（`granted=false`・残高不変）。
   - **backfill migration**: 既存 `signup_grant:org:{id}` 履歴のある org は marker が `min(granted_at)` で立ち、履歴の無い org は null のまま。再実行しても値が動かない（冪等）。
3. `tests/Feature/Billing/PlanActiveFilterTest.php` — `is_active=false` の Plan は `/pricing` の props に出ない / `is_active=true` の Plan は出る。あわせて **seed 直後は 4 プランすべて `is_active=true`**（aigenba verbatim の公開方針）を固定。
4. `tests/Feature/Billing/PlanQuotaCoverageInvariantTest.php` — **全 `Plan.code` が `config('quota.plans')` に存在する**（`QuotaService.php:33` の `?? []` による無制限 silent 退行の機械検知）+ `PersonalPlanService::MAX_MEMBERS === config('quota.plans.personal.max_members')`。
5. `tests/Feature/Billing/PlanPriceServiceTest.php` — `replaceCurrent()` が旧 current を `is_current=false` + `active_to` 設定で閉じ、新 current を `lookup_key` 付きで作る（CHECK 制約と `SyncStripePrices` の「kind + is_current + lookup_key 一致」検索が成立することを固定）。
6. `tests/Architecture/FreePlanCodeWriteInvariantTest.php` — `app/` 内の `free_plan_code` 書き込みは `PersonalPlanService` に限定（aigenba 同名を移植）。
7. **D28 の固定（新規テスト）**: `WebhookIdempotencyTest` に「seed 既定（`monthly_ticket_grant=0`）では `invoice.paid` で `monthly:%` 行が 1 件も作られない（signup grant のみ）」を追加。`PlanSeeder` の全 tier `monthly_ticket_grant === 0` を `PlanSeederPriceInvariantTest` に追加。

**既存テストの更新（削除しない）**: `tests/Feature/Billing/TicketGrantTest.php` / `WebhookIdempotencyTest.php` / `InvoiceLinePricingShapeTest.php` / `SeededFreePlanBillingAccessTest.php` / `PlanSeederPriceInvariantTest.php` / `BillingPageTest.php` / `SyncStripePricesCommandTest.php` / `VerifyStripePricesCommandTest.php` / `tests/Feature/Marketing/PricingPageTest.php` / `tests/Feature/Auth/RegistrationTest.php` / `tests/js/pages/Pricing.test.ts`。

**挙動不変の固定（回帰。期待値を変えない）**: `tests/Feature/Billing/RequireActiveSubscriptionMiddlewareTest.php` / `SeededFreePlanBillingAccessTest.php`（ゲート未反転の証明）/ `tests/Feature/Billing/QuotaTest.php` / `QuotaCheckAdditionTest.php`（既存 free / standard の limits 不変）/ `tests/Feature/Auth/RegistrationInvitationPrefillTest.php`（招待経由は非付与）/ `tests/js/pages/Welcome.test.ts`（`Welcome.svelte` の文言は P1 では触らない。付与契機は登録時のままで文言は依然事実。修正は P6）。

#### リスク

| リスク | 緩和 |
|---|---|
| **D28 で月次付与が消え、既存 standard 契約組織の毎月 100 枚が無くなる**（プロダクト影響そのもの） | 「値の調整」ではなく **aigenba の課金モデルへの一致**（都度購入 + オートリチャージ）という決定であることを D28 に明記済み。チケット都度購入（`/billing/tickets`）と signup grant 10 枚は据置で、残高ゼロ詰みは P8a のオートリチャージが引き取る。料金表・課金ページの「月 N 枚」表示を同一 PR で撤去し、虚偽表示の期間を作らない |
| `config/quota.php` に personal / starter を入れ忘れると `QuotaService.php:33` の `?? []` で**無制限に silent 退行**する | 同 PR で limits を追加し、`PlanQuotaCoverageInvariantTest`（全 `Plan.code` ⊆ `quota.plans`）で機械検証（`QuotaKeyConfigInvariantTest` と同格） |
| **personal が `is_active=true` で `/pricing` に出るのに activate 導線は P3 まで無い** | `/pricing` の CTA は現行どおり `/register`（P1 では変更しない）で、personal カードも同じ導線に着地する = 詰みは発生しない。activate-personal への誘導は P3、`?plan=` handoff は P7。P1 → P3 は直列前提（依存順は交渉不可） |
| `starter` を公開しても Stripe 実 Price が未 sync だと checkout が失敗する | fixture（`plan_starter.json`）+ `StripePriceLookupKeys` を同一 PR で追加し `StripePriceCatalogFixtureInvariantTest` が集合一致を強制。実 Price 反映は既存運用の `billing:sync-stripe-prices`（bootstrap 行 `price_test_app_starter_base` は `livemode=false` / `synced_at=null` のまま）。checkout 時の未 sync は既存の `back()->with('error', …)` 経路で 500 にならない |
| `grantSignupGrant` のシグネチャ変更で呼び出し漏れ | 呼び出し元は 2 箇所のみ（`CreateNewUser.php:106` / `StripeWebhookProcessor.php:270`）。引数を必須にすることで PHPStan level 10 が漏れを静的検出する |
| 移行期の marker claim を入れ忘れた org が P6 後に再付与される | `SignupGrantOncePerOrgTest` の移行期回帰 + `ticket_ledger_entries_signup_grant_unique` の DB 二重防御（キー種別を跨いで org 生涯 1 回） |
| P1〜P5 の paid webhook 経路は marker を立てないため、当該経路のみで付与された org が marker 無しで残る | 二重付与は `ticket_ledger_entries_signup_grant_unique` が DB レベルで阻止するため**金銭的影響は無い**（残高不変）。当該経路は **P6 (b) の claim+grant ブロック追加**で閉じる |
| backfill 対象 org に `signup_grant:%` が複数行あると `min(granted_at)` が曖昧 | `2026_07_13_180622` が「重複あれば fail-closed」で既に導入済みのため、本番に重複行は存在し得ない |
| `PlanPriceService` が P1 時点で呼び出し元なし | 移植方針上は許容（P2 / 価格改定運用で使用）。`PlanPriceServiceTest` で生存を確保。`?string $lookupKey` を落とすと `SyncStripePrices.php:78-87` が current 行を見失う |
| `free` と `personal` が並存し、`ManualTestSeeder` 由来の組織が 2 つ増える（`seededFreePlan()` の対象が非決定になる） | 手動テスト用途のみで本番影響なし。`SeededFreePlanBillingAccessTest` は `code='free'` 固定で解消。`free` 行と `fallback_plan='free'` の撤去は P4 |
| `PlanCode` に Plan 行を持たない Business / Enterprise が残る | verbatim 移植の帰結（原則 1）。`PlanCode::from()` の membership assert は free 撤去後（P4）に導入するため、P1 で ValueError にはならない。逆向き（全 `Plan.code` ⊆ `PlanCode`）の invariant も P4 で入れる |
| partial unique index は pgsql / sqlite 前提（MySQL 非対応） | 既存 `2026_07_13_180622` が同じ前提を driver チェック + fail-closed で明示済み。本番 / CI とも該当ドライバのみ |

---


## 実装差分（git diff）

```diff
diff --git a/app/Actions/Fortify/CreateNewUser.php b/app/Actions/Fortify/CreateNewUser.php
index e330833..f82c4a5 100644
--- a/app/Actions/Fortify/CreateNewUser.php
+++ b/app/Actions/Fortify/CreateNewUser.php
@@ -7,6 +7,7 @@
 use App\Models\User;
 use App\Rules\MatchesInvitationEmail;
 use App\Rules\UniqueEncryptedEmail;
+use App\Services\Billing\PersonalPlanService;
 use App\Services\Billing\TicketLedgerService;
 use App\Services\Organization\OrganizationMembershipService;
 use App\Services\Organization\OrganizationProvisioningService;
@@ -39,6 +40,7 @@ public function __construct(
         private readonly OrganizationProvisioningService $provisioning,
         private readonly OrganizationMembershipService $membership,
         private readonly TicketLedgerService $tickets,
+        private readonly PersonalPlanService $personalPlan,
     ) {}
 
     /**
@@ -103,7 +105,17 @@ public function create(array $input): User
                     // 冪等性は idempotency_key + 部分 UNIQUE index が DB レベルで保証する。
                     // 招待経由 (join) は個人組織を作らず所属組織の残高を共有するため、ここでは付与しない
                     // (招待 N 人 = N×10 の増幅を避ける)。
-                    $this->tickets->grantSignupGrant($organization);
+                    //
+                    // 移行期規約: 付与契機は登録時のまま維持しつつ、org 単位 1 回マーカー
+                    // (organizations.signup_tickets_granted_at) を同一 tx で先取する。マーカーを
+                    // 先取できたときのみ付与することで、free 有効化 (PersonalPlanService::activate)
+                    // 経路との二重付与を防ぐ (マーカー先取と付与が同一 tx = 原子的)。
+                    $organizationId = $organization->getKey();
+                    Assert::integer($organizationId, 'Organization の主キーは整数を想定しています');
+
+                    if ($this->personalPlan->claimSignupGrantMarker($organization)) {
+                        $this->tickets->grantSignupGrant($organization, "signup_grant:org:{$organizationId}");
+                    }
                 }
 
                 return $user;
diff --git a/app/DataTransferObjects/Billing/PersonalPlanActivationResultDto.php b/app/DataTransferObjects/Billing/PersonalPlanActivationResultDto.php
new file mode 100644
index 0000000..69e853f
--- /dev/null
+++ b/app/DataTransferObjects/Billing/PersonalPlanActivationResultDto.php
@@ -0,0 +1,18 @@
+<?php
+
+declare(strict_types=1);
+
+namespace App\DataTransferObjects\Billing;
+
+/**
+ * Personal (free) プラン有効化の結果。
+ *
+ * granted = 初回無償チケットをこの有効化で付与したか (org 単位 1 回マーカーを先取した場合のみ
+ * true。付与済み org の有効化では false になり、flash 文言の分岐に使う)。
+ */
+final readonly class PersonalPlanActivationResultDto
+{
+    public function __construct(
+        public bool $granted,
+    ) {}
+}
diff --git a/app/DataTransferObjects/Billing/PersonalPlanEligibilityDto.php b/app/DataTransferObjects/Billing/PersonalPlanEligibilityDto.php
new file mode 100644
index 0000000..5c8f221
--- /dev/null
+++ b/app/DataTransferObjects/Billing/PersonalPlanEligibilityDto.php
@@ -0,0 +1,48 @@
+<?php
+
+declare(strict_types=1);
+
+namespace App\DataTransferObjects\Billing;
+
+use App\Enums\Billing\PersonalPlanIneligibleReason;
+
+/**
+ * Personal (free) プランの選択可否 (UI 表示用)。
+ *
+ * reasonLabel はサーバー側 enum で確定した表示文言 (frontend に文言マッピングを散らさない)。
+ *
+ * @phpstan-type PersonalPlanEligibilityShape array{
+ *   eligible: bool,
+ *   reason: string|null,
+ *   reasonLabel: string|null
+ * }
+ */
+final readonly class PersonalPlanEligibilityDto
+{
+    private function __construct(
+        public bool $eligible,
+        public ?PersonalPlanIneligibleReason $reason,
+    ) {}
+
+    public static function eligible(): self
+    {
+        return new self(eligible: true, reason: null);
+    }
+
+    public static function ineligible(PersonalPlanIneligibleReason $reason): self
+    {
+        return new self(eligible: false, reason: $reason);
+    }
+
+    /**
+     * @return PersonalPlanEligibilityShape
+     */
+    public function toArray(): array
+    {
+        return [
+            'eligible' => $this->eligible,
+            'reason' => $this->reason?->value,
+            'reasonLabel' => $this->reason?->label(),
+        ];
+    }
+}
diff --git a/app/DataTransferObjects/Marketing/PricingPlanDto.php b/app/DataTransferObjects/Marketing/PricingPlanDto.php
index 93be2c9..9a44b63 100644
--- a/app/DataTransferObjects/Marketing/PricingPlanDto.php
+++ b/app/DataTransferObjects/Marketing/PricingPlanDto.php
@@ -19,7 +19,6 @@
  *   code: string,
  *   name: string,
  *   baseAmountJpy: int|null,
- *   monthlyTicketGrant: int,
  *   maxProjects: int|null,
  *   maxMembers: int|null,
  *   maxStorageGb: int|null
@@ -31,7 +30,6 @@ public function __construct(
         public string $code,
         public string $name,
         public ?int $baseAmountJpy,
-        public int $monthlyTicketGrant,
         public ?int $maxProjects,
         public ?int $maxMembers,
         public ?int $maxStorageGb,
@@ -46,7 +44,6 @@ public function toArray(): array
             'code' => $this->code,
             'name' => $this->name,
             'baseAmountJpy' => $this->baseAmountJpy,
-            'monthlyTicketGrant' => $this->monthlyTicketGrant,
             'maxProjects' => $this->maxProjects,
             'maxMembers' => $this->maxMembers,
             'maxStorageGb' => $this->maxStorageGb,
diff --git a/app/Enums/Billing/PersonalPlanIneligibleReason.php b/app/Enums/Billing/PersonalPlanIneligibleReason.php
new file mode 100644
index 0000000..5692567
--- /dev/null
+++ b/app/Enums/Billing/PersonalPlanIneligibleReason.php
@@ -0,0 +1,28 @@
+<?php
+
+declare(strict_types=1);
+
+namespace App\Enums\Billing;
+
+use App\Services\Billing\PersonalPlanService;
+
+/**
+ * Personal (free) プランを有効化できない理由。
+ *
+ * 表示文言 (label) はサーバー側で確定し、frontend に文言マッピングを散らさない。
+ */
+enum PersonalPlanIneligibleReason: string
+{
+    case HasEntitledSubscription = 'has_entitled_subscription';
+    case TooManyMembers = 'too_many_members';
+    case AlreadyHasFreePersonalOrg = 'already_has_free_personal_org';
+
+    public function label(): string
+    {
+        return match ($this) {
+            self::HasEntitledSubscription => '有効な有償契約があるためパーソナルプランは選択できません。',
+            self::TooManyMembers => sprintf('メンバーが %d 名を超えているためパーソナルプランは選択できません。', PersonalPlanService::MAX_MEMBERS),
+            self::AlreadyHasFreePersonalOrg => '既にパーソナルプラン（無料）の組織をお持ちのため、この組織では選択できません。',
+        };
+    }
+}
diff --git a/app/Enums/PlanCode.php b/app/Enums/PlanCode.php
new file mode 100644
index 0000000..2217209
--- /dev/null
+++ b/app/Enums/PlanCode.php
@@ -0,0 +1,27 @@
+<?php
+
+declare(strict_types=1);
+
+namespace App\Enums;
+
+enum PlanCode: string
+{
+    case Personal = 'personal';
+    case Starter = 'starter';
+    case Standard = 'standard';
+    case Business = 'business';
+    case Enterprise = 'enterprise';
+
+    /**
+     * Stripe Checkout (サブスク契約) の対象プランか。
+     * Personal は free (サブスクなし・PersonalPlanService::activate で有効化)、
+     * Enterprise はお問い合わせ営業のため、どちらも Stripe checkout を通らない。
+     */
+    public function requiresStripeCheckout(): bool
+    {
+        return match ($this) {
+            self::Starter, self::Standard, self::Business => true,
+            self::Personal, self::Enterprise => false,
+        };
+    }
+}
diff --git a/app/Exceptions/Billing/PersonalPlanNotEligibleException.php b/app/Exceptions/Billing/PersonalPlanNotEligibleException.php
new file mode 100644
index 0000000..2f647cf
--- /dev/null
+++ b/app/Exceptions/Billing/PersonalPlanNotEligibleException.php
@@ -0,0 +1,29 @@
+<?php
+
+declare(strict_types=1);
+
+namespace App\Exceptions\Billing;
+
+use App\Enums\Billing\PersonalPlanIneligibleReason;
+use RuntimeException;
+use Throwable;
+
+/**
+ * Personal (free) プランの有効化条件を満たさない。
+ *
+ * Controller 層で ValidationException (422) に変換する (500 にしない)。
+ */
+final class PersonalPlanNotEligibleException extends RuntimeException
+{
+    public function __construct(
+        public readonly PersonalPlanIneligibleReason $reason,
+        ?Throwable $previous = null,
+    ) {
+        parent::__construct('personal plan not eligible: '.$reason->value, 0, $previous);
+    }
+
+    public function userMessage(): string
+    {
+        return $this->reason->label();
+    }
+}
diff --git a/app/Http/Controllers/Billing/BillingController.php b/app/Http/Controllers/Billing/BillingController.php
index 66a4405..fc1676a 100644
--- a/app/Http/Controllers/Billing/BillingController.php
+++ b/app/Http/Controllers/Billing/BillingController.php
@@ -47,7 +47,6 @@ public function index(Request $request, TicketLedgerService $tickets): Response
                 return [
                     'code' => $plan->code,
                     'name' => $plan->name,
-                    'monthlyTicketGrant' => $plan->monthly_ticket_grant,
                     'price' => $price === null ? null : [
                         'unitAmount' => $price->amount,
                         'currency' => $price->currency,
diff --git a/app/Models/Billing/Plan.php b/app/Models/Billing/Plan.php
index 1d4b6e3..c550405 100644
--- a/app/Models/Billing/Plan.php
+++ b/app/Models/Billing/Plan.php
@@ -19,6 +19,7 @@
  * @property string $name
  * @property int $monthly_ticket_grant
  * @property int $sort_order
+ * @property bool $is_active
  */
 class Plan extends Model
 {
@@ -28,6 +29,7 @@ class Plan extends Model
         'name',
         'monthly_ticket_grant',
         'sort_order',
+        'is_active',
     ];
 
     /**
@@ -55,6 +57,7 @@ protected function casts(): array
         return [
             'monthly_ticket_grant' => 'integer',
             'sort_order' => 'integer',
+            'is_active' => 'boolean',
         ];
     }
 }
diff --git a/app/Models/Organization.php b/app/Models/Organization.php
index b9e4f01..b354f9d 100644
--- a/app/Models/Organization.php
+++ b/app/Models/Organization.php
@@ -35,6 +35,11 @@
  * plan_code は Stripe Price を持つ有償プランの契約 (active/trialing) 時のみ set され、
  * subscription.deleted で null に戻る。**null = 未契約 = 支払い不要の free tier**
  * (config/quota.php の fallback_plan が適用され、BillingAccess は業務 route を許可する)。
+ *
+ * free entitlement (パーソナルプラン) は plan_code ではなく free_plan_code 側で表現する
+ * (`subscriptions` テーブルは Stripe 実体のみを保持する invariant を守るため)。
+ * free_plan_code / free_plan_activated_at / personal_declared_* / signup_tickets_granted_at は
+ * いずれも状態キーのため $fillable 外 (PersonalPlanService の forceFill 経由でのみ書き込む)。
  */
 class Organization extends Model
 {
@@ -181,6 +186,12 @@ protected function casts(): array
             // 2FA 必須方針。セキュリティ方針キーのため $fillable 外
             // (OrganizationController::updateTwoFactorRequirement が forceFill で明示代入する)
             'two_factor_required' => 'boolean',
+            // free entitlement (パーソナルプラン) と初回付与マーカー。いずれも状態キーのため
+            // $fillable 外 (PersonalPlanService が forceFill で明示代入する)
+            'free_plan_activated_at' => 'immutable_datetime',
+            'personal_declared_at' => 'immutable_datetime',
+            'personal_declared_by_user_id' => 'integer',
+            'signup_tickets_granted_at' => 'immutable_datetime',
         ];
     }
 }
diff --git a/app/Services/Billing/PersonalPlanService.php b/app/Services/Billing/PersonalPlanService.php
new file mode 100644
index 0000000..d4f8d71
--- /dev/null
+++ b/app/Services/Billing/PersonalPlanService.php
@@ -0,0 +1,225 @@
+<?php
+
+declare(strict_types=1);
+
+namespace App\Services\Billing;
+
+use App\DataTransferObjects\Billing\PersonalPlanActivationResultDto;
+use App\DataTransferObjects\Billing\PersonalPlanEligibilityDto;
+use App\Enums\Billing\PersonalPlanIneligibleReason;
+use App\Enums\OrganizationRole;
+use App\Exceptions\Billing\PersonalPlanNotEligibleException;
+use App\Models\Organization;
+use App\Models\User;
+use Carbon\CarbonImmutable;
+use Illuminate\Database\Eloquent\Builder;
+use Illuminate\Database\QueryException;
+use Illuminate\Support\Facades\DB;
+use Webmozart\Assert\Assert;
+
+/**
+ * Personal (free) プランの有効化・退役・選択可否判定を集約する。
+ *
+ * Personal 前提で閉じる (汎用 free plan framework にはしない)。`subscriptions` テーブルは
+ * Stripe 実体のみを保持する invariant を守り、free entitlement は organizations 側の
+ * `free_plan_code` で表現する。
+ *
+ * farming 防止の層別:
+ * - hard invariant (DB): 「1 user が declarer である active free personal org は 1 つ」
+ *   (partial unique index `organizations_personal_free_declarer_unique`)
+ * - best-effort (UX/abuse 抑止): owner 条件 (declarer でない owner の別 org) は eligibility の
+ *   事前判定のみ。残余があっても初回付与は org 単位 1 回マーカーで有限
+ */
+final class PersonalPlanService
+{
+    public const string FREE_PLAN_CODE = 'personal';
+
+    /**
+     * 在籍数ハードキャップ (= config('quota.plans.personal.max_members') と一致。
+     * invariant test で固定する)。
+     */
+    public const int MAX_MEMBERS = 3;
+
+    /** アクセスを許可する Stripe subscription status (BillingAccess::GRANTING_STATUSES と同値) */
+    private const array GRANTING_STATUSES = ['active', 'trialing'];
+
+    public function __construct(
+        private readonly TicketLedgerService $tickets,
+    ) {}
+
+    /**
+     * UI 表示用: この org/user で Personal (free) を選択できるか + 不可理由。
+     *
+     * activate() の事前判定 (親切なエラー) であり真実源ではない (真実源は DB 制約 +
+     * activate() 内の再検証)。
+     */
+    public function eligibility(Organization $org, User $user): PersonalPlanEligibilityDto
+    {
+        if ($this->hasEntitledSubscription($org)) {
+            return PersonalPlanEligibilityDto::ineligible(PersonalPlanIneligibleReason::HasEntitledSubscription);
+        }
+
+        if ($org->users()->count() > self::MAX_MEMBERS) {
+            return PersonalPlanEligibilityDto::ineligible(PersonalPlanIneligibleReason::TooManyMembers);
+        }
+
+        if ($this->hasOtherActiveFreePersonalOrg($org, $user)) {
+            return PersonalPlanEligibilityDto::ineligible(PersonalPlanIneligibleReason::AlreadyHasFreePersonalOrg);
+        }
+
+        return PersonalPlanEligibilityDto::eligible();
+    }
+
+    /**
+     * Personal (free) を有効化する。自己申告 (declaration) の入力検証は FormRequest の責務で、
+     * 本メソッドは business invariant を transaction 内で再検証して確定する。
+     *
+     * 初回無償チケットは「org 単位で生涯 1 回」: `signup_tickets_granted_at` を
+     * 条件付き UPDATE で先取した経路のみ付与する (paid webhook 経路と対称、同一 TX 内なので
+     * 付与失敗時はマーカーごと rollback される)。
+     *
+     * @throws PersonalPlanNotEligibleException 並行 activate の後着 (declarer unique 違反) を含む
+     */
+    public function activate(Organization $org, User $declarer): PersonalPlanActivationResultDto
+    {
+        try {
+            return $this->activateWithinTransaction($org, $declarer);
+        } catch (QueryException $e) {
+            // partial unique index (declarer 単位) 違反 = 並行 activate の後着。500 にしない。
+            if ($this->isDeclarerUniqueViolation($e)) {
+                throw new PersonalPlanNotEligibleException(
+                    PersonalPlanIneligibleReason::AlreadyHasFreePersonalOrg,
+                    previous: $e,
+                );
+            }
+
+            throw $e;
+        }
+    }
+
+    /**
+     * paid サブスク成立時の free 退役 (webhook 経由。冪等)。
+     *
+     * declared_at / declared_by / activated_at は監査証跡として残す。free_plan_code を null に
+     * することで partial unique index の declarer 枠も解放される。
+     */
+    public function retireForPaidSubscription(Organization $org): void
+    {
+        if ($org->free_plan_code === null) {
+            return;
+        }
+
+        $org->forceFill(['free_plan_code' => null])->save();
+    }
+
+    /**
+     * 初回付与マーカー (`signup_tickets_granted_at`) を条件付き UPDATE で先取する。
+     * 先取できた (= この呼び出しが org 生涯で初回) ときのみ true。
+     *
+     * **移行期専用の public API**: signup grant の付与契機が登録時 (CreateNewUser) のままの間、
+     * 登録経路からも marker を立てる必要があるため public にしている。付与契機を activate へ
+     * 移す P6 で private へ戻す (詳細設計 D13)。呼び出し側は org 行 lockForUpdate 下・付与と
+     * 同一 transaction で使うこと (先取と付与が原子的でないと二重付与の窓ができる)。
+     */
+    public function claimSignupGrantMarker(Organization $org, ?CarbonImmutable $now = null): bool
+    {
+        $claimed = DB::table('organizations')
+            ->where('id', $org->getKey())
+            ->whereNull('signup_tickets_granted_at')
+            ->update(['signup_tickets_granted_at' => $now ?? CarbonImmutable::now()]);
+
+        return $claimed === 1;
+    }
+
+    private function activateWithinTransaction(Organization $org, User $declarer): PersonalPlanActivationResultDto
+    {
+        $organizationId = $org->getKey();
+        Assert::integer($organizationId, 'Organization の主キーは整数を想定しています');
+
+        return DB::transaction(function () use ($organizationId, $declarer): PersonalPlanActivationResultDto {
+            // org 行 lock で同一 org への並行 activate / paid webhook の付与競合を直列化する
+            // (TicketLedgerService::reserve と同じパターン)。
+            $fresh = Organization::query()->lockForUpdate()->findOrFail($organizationId);
+
+            $eligibility = $this->eligibility($fresh, $declarer);
+            if (! $eligibility->eligible) {
+                $reason = $eligibility->reason ?? PersonalPlanIneligibleReason::HasEntitledSubscription;
+
+                throw new PersonalPlanNotEligibleException($reason);
+            }
+
+            $now = CarbonImmutable::now();
+            $fresh->forceFill([
+                'free_plan_code' => self::FREE_PLAN_CODE,
+                'free_plan_activated_at' => $now,
+                'personal_declared_at' => $now,
+                'personal_declared_by_user_id' => $declarer->getKey(),
+            ])->save();
+
+            $granted = $this->claimSignupGrantMarker($fresh, $now);
+            if ($granted) {
+                $this->tickets->grantSignupGrant($fresh, "signup_grant:personal:{$organizationId}");
+            }
+
+            return new PersonalPlanActivationResultDto(granted: $granted);
+        });
+    }
+
+    /**
+     * 有効な (entitled) 有償サブスクを持つか。
+     *
+     * **P2 の seam**: 現状は `subscription('default')` の stripe_status が active / trialing か
+     * で判定する (= 現行 `BillingAccess::GRANTING_STATUSES` と同値)。P2 で
+     * `SubscriptionService::deriveEntitlement($sub)->entitled` へ差し替える
+     * (支払い手段・trial 有無を含む aigenba の entitlement 判定に一致させる)。
+     */
+    private function hasEntitledSubscription(Organization $org): bool
+    {
+        $subscription = $org->subscription('default');
+
+        return $subscription !== null
+            && in_array($subscription->stripe_status, self::GRANTING_STATUSES, true);
+    }
+
+    /**
+     * 同一 user が declarer または owner (OrganizationRole::Owner) である別の active free
+     * personal org が存在するか。
+     */
+    private function hasOtherActiveFreePersonalOrg(Organization $org, User $user): bool
+    {
+        return Organization::query()
+            ->where('free_plan_code', self::FREE_PLAN_CODE)
+            ->whereKeyNot($org->getKey())
+            ->where(function (Builder $q) use ($user): void {
+                $q->where('personal_declared_by_user_id', $user->getKey())
+                    ->orWhereHas('users', function (Builder $uq) use ($user): void {
+                        $uq->whereKey($user->getKey())
+                            ->whereHas('roles', function (Builder $rq): void {
+                                $rq->where('name', OrganizationRole::Owner->value)
+                                    ->whereColumn('role_user.team_id', 'organizations.laratrust_team_id');
+                            });
+                    });
+            })
+            ->exists();
+    }
+
+    /**
+     * partial unique index `organizations_personal_free_declarer_unique` の違反か
+     * (driver 差吸収: MySQL/SQLite=23000, PostgreSQL=23505。SQLite は index 名を含まない
+     * 列名形式のため両方を見る)。
+     *
+     * getCode() の宣言型は int だが PDO 由来は SQLSTATE 文字列を返すため、比較前に string へ
+     * 正規化する。
+     */
+    private function isDeclarerUniqueViolation(QueryException $e): bool
+    {
+        if (! in_array((string) $e->getCode(), ['23000', '23505'], true)) {
+            return false;
+        }
+
+        $message = $e->getMessage();
+
+        return str_contains($message, 'organizations_personal_free_declarer_unique')
+            || str_contains($message, 'organizations.personal_declared_by_user_id');
+    }
+}
diff --git a/app/Services/Billing/PlanPriceService.php b/app/Services/Billing/PlanPriceService.php
new file mode 100644
index 0000000..566d56d
--- /dev/null
+++ b/app/Services/Billing/PlanPriceService.php
@@ -0,0 +1,69 @@
+<?php
+
+declare(strict_types=1);
+
+namespace App\Services\Billing;
+
+use App\Enums\Billing\PlanPriceKind;
+use App\Models\Billing\Plan;
+use App\Models\Billing\PlanPrice;
+use Carbon\CarbonImmutable;
+use Illuminate\Support\Facades\DB;
+use Webmozart\Assert\Assert;
+
+/**
+ * プラン価格のバージョニング管理。
+ *
+ * 旧 current 行を無効化しつつ新 current を差し込む一連の処理を、
+ * 単一DBトランザクションで実行することで二重 current を物理的に防ぐ。
+ * 部分ユニーク（生成列 `current_unique_key`）がラスト・リゾートのガード。
+ */
+class PlanPriceService
+{
+    /**
+     * 指定 kind の current Price を差し替える (旧 current を閉じてから新 current を作る)。
+     *
+     * $lookupKey は Stripe Price の lookup_key の DB snapshot。`billing:sync-stripe-prices` は
+     * 「kind + is_current + lookup_key 一致」で current 行を引くため、null で作ると同期対象を
+     * 見失う (詳細設計 D14)。
+     */
+    public function replaceCurrent(
+        Plan $plan,
+        PlanPriceKind $kind,
+        string $stripePriceId,
+        int $amount,
+        ?string $lookupKey = null,
+        string $currency = 'jpy',
+        ?CarbonImmutable $activeFrom = null,
+    ): PlanPrice {
+        Assert::notEmpty($stripePriceId);
+        Assert::greaterThan($amount, 0);
+
+        $from = $activeFrom ?? CarbonImmutable::now();
+
+        return DB::transaction(function () use ($plan, $kind, $stripePriceId, $amount, $lookupKey, $currency, $from): PlanPrice {
+            // 先に旧 current を無効化。部分ユニーク制約のため必ず先行実行する。
+            $plan->prices()
+                ->where('kind', $kind->value)
+                ->where('is_current', true)
+                ->update([
+                    'is_current' => false,
+                    'active_to' => $from,
+                ]);
+
+            /** @var PlanPrice $new */
+            $new = $plan->prices()->create([
+                'kind' => $kind->value,
+                'stripe_price_id' => $stripePriceId,
+                'lookup_key' => $lookupKey,
+                'amount' => $amount,
+                'currency' => $currency,
+                'active_from' => $from,
+                'active_to' => null,
+                'is_current' => true,
+            ]);
+
+            return $new;
+        });
+    }
+}
diff --git a/app/Services/Billing/StripeWebhookProcessor.php b/app/Services/Billing/StripeWebhookProcessor.php
index 65e4e13..d058dc5 100644
--- a/app/Services/Billing/StripeWebhookProcessor.php
+++ b/app/Services/Billing/StripeWebhookProcessor.php
@@ -263,11 +263,14 @@ private function grantMonthlyTickets(array $payload): void
             return; // サブスク以外の請求 (one-time 等) では付与しない
         }
 
-        // 初回 signup grant (「まず触れる」導線)。冪等キーは org スコープ (grantSignupGrant 内部で生成) のため
-        // subscription id は不要。1 組織 1 回の不変条件は idempotency_key + 部分 UNIQUE index が保証する。
+        // 初回 signup grant (「まず触れる」導線)。冪等キーは org スコープのため subscription id は不要。
+        // 1 組織 1 回の不変条件は idempotency_key + 部分 UNIQUE index が保証する。
         // (通常は登録時に付与済のため、ここは非個人組織のサブスク等に対する no-op ないし 1 回付与の安全網)
         if ($billingReason === 'subscription_create') {
-            $this->tickets->grantSignupGrant($organization);
+            $organizationId = $organization->getKey();
+            Assert::integer($organizationId, 'Organization の主キーは整数を想定しています');
+
+            $this->tickets->grantSignupGrant($organization, "signup_grant:org:{$organizationId}");
         }
 
         $plan = $this->resolveInvoicePlan($payload, $organization);
diff --git a/app/Services/Billing/TicketLedgerService.php b/app/Services/Billing/TicketLedgerService.php
index 96b9122..f1fd583 100644
--- a/app/Services/Billing/TicketLedgerService.php
+++ b/app/Services/Billing/TicketLedgerService.php
@@ -83,12 +83,17 @@ public function grantMonthly(
      * (invoice.paid, billing_reason=subscription_create) の双方から呼ばれる。
      * 枚数は config('billing.signup_grant_tickets')、期限は now + config('billing.signup_grant_expiry_days') 日。
      *
-     * **1 組織につき高々 1 回**の不変条件は、冪等キー `signup_grant:org:{orgId}` の UNIQUE と、
+     * **1 組織につき高々 1 回**の不変条件は、冪等キー ($idempotencyKey) の UNIQUE と、
      * ticket_ledger_entries の部分 UNIQUE index (organization_id WHERE idempotency_key LIKE 'signup_grant:%')
      * が DB レベルで原子的に保証する。旧キー (signup_grant:{subId}) 行が既にある組織でも、部分 index が
      * 同一述語でカバーするため insertOrIgnore が二重付与を弾く (アプリ層の存在チェックは不要)。
+     *
+     * $idempotencyKey は経路を表す `signup_grant:` 接頭辞付きのキーを呼び出し側が渡す
+     * (登録経路 = `signup_grant:org:{orgId}` / free 有効化 = `signup_grant:personal:{orgId}`)。
+     * 部分 UNIQUE index が述語 `LIKE 'signup_grant:%'` で経路を跨いで org 生涯 1 回に閉じるため、
+     * キーの違いは監査上の由来表現であって二重付与の窓にはならない。
      */
-    public function grantSignupGrant(Organization $organization): void
+    public function grantSignupGrant(Organization $organization, string $idempotencyKey): void
     {
         $count = config('billing.signup_grant_tickets');
         Assert::integer($count, 'config billing.signup_grant_tickets は整数で設定してください');
@@ -98,14 +103,11 @@ public function grantSignupGrant(Organization $organization): void
         Assert::integer($expiryDays, 'config billing.signup_grant_expiry_days は整数で設定してください');
         Assert::greaterThan($expiryDays, 0, 'signup_grant_expiry_days は 1 以上で設定してください');
 
-        $organizationId = $organization->getKey();
-        Assert::integer($organizationId, 'Organization の主キーは整数を想定しています');
-
         $this->grantMonthly(
             $organization,
             $count,
             CarbonImmutable::now()->addDays($expiryDays),
-            "signup_grant:org:{$organizationId}",
+            $idempotencyKey,
             '初回 signup grant',
         );
     }
diff --git a/app/Services/Marketing/PricingService.php b/app/Services/Marketing/PricingService.php
index dd13bee..38a7529 100644
--- a/app/Services/Marketing/PricingService.php
+++ b/app/Services/Marketing/PricingService.php
@@ -25,7 +25,11 @@ final class PricingService
     private ?array $memoizedPlans = null;
 
     /**
-     * 公開プラン一覧 (sort_order 昇順)。価格は plan_prices current (kind=base)。
+     * 公開プラン一覧 (is_active=true のみ・sort_order 昇順)。
+     * 価格は plan_prices current (kind=base)。
+     *
+     * is_active フィルタはプラン公開制御の唯一の場所 (管理画面での非公開化が
+     * そのまま /pricing に効く)。
      *
      * @return list<PricingPlanDto>
      */
@@ -38,7 +42,9 @@ public function listPublicPlans(): array
         $quotaPlans = config('quota.plans');
         Assert::isArray($quotaPlans);
 
-        return $this->memoizedPlans = array_values(Plan::query()->orderBy('sort_order')->get()
+        return $this->memoizedPlans = array_values(Plan::query()
+            ->where('is_active', true)
+            ->orderBy('sort_order')->get()
             ->map(function (Plan $plan) use ($quotaPlans): PricingPlanDto {
                 $limits = $quotaPlans[$plan->code] ?? [];
                 Assert::isArray($limits);
@@ -48,7 +54,6 @@ public function listPublicPlans(): array
                     code: $plan->code,
                     name: $plan->name,
                     baseAmountJpy: $price?->amount,
-                    monthlyTicketGrant: $plan->monthly_ticket_grant,
                     maxProjects: self::intOrNull($limits, 'max_projects'),
                     maxMembers: self::intOrNull($limits, 'max_members'),
                     maxStorageGb: self::storageGb($limits),
diff --git a/app/Support/Billing/StripePriceLookupKeys.php b/app/Support/Billing/StripePriceLookupKeys.php
index 674640c..0625b4f 100644
--- a/app/Support/Billing/StripePriceLookupKeys.php
+++ b/app/Support/Billing/StripePriceLookupKeys.php
@@ -22,11 +22,13 @@ final class StripePriceLookupKeys
 {
     /**
      * Checkout 経路を持つプラン → 価格 kind の宣言。
-     * free (未契約の既定) は Checkout を持たないため含めない。
+     * free (未契約の既定) と personal (activate 経由の無料プラン = Checkout を
+     * 通らない) は Price を持たないため含めない。
      *
      * @var array<string, list<PlanPriceKind>>
      */
     private const CATALOG = [
+        'starter' => [PlanPriceKind::Base],
         'standard' => [PlanPriceKind::Base],
     ];
 
diff --git a/app/Support/Security/MassAssignmentProtectedKeys.php b/app/Support/Security/MassAssignmentProtectedKeys.php
index 31dc564..e066229 100644
--- a/app/Support/Security/MassAssignmentProtectedKeys.php
+++ b/app/Support/Security/MassAssignmentProtectedKeys.php
@@ -29,6 +29,7 @@ public static function all(): array
             'created_by', // AI-CUE ドメイン (video_manuals) の actor キー (doc/10 §10.1 準拠の命名)
             'triggered_by', // AI-CUE: analysis_jobs / render_jobs のジョブ実行者 (通知宛先導出。Auth 導出のみ)
             'invited_by_user_id',
+            'personal_declared_by_user_id', // organizations の free plan 自己申告 actor (PersonalPlanService が Auth から導出)
             // tenant / ownership (route・コンテキストから導出する)
             'organization_id',
             'custom_team_id',
diff --git a/config/quota.php b/config/quota.php
index cd8adb6..d98565a 100644
--- a/config/quota.php
+++ b/config/quota.php
@@ -36,6 +36,16 @@
             'max_members' => 3,
             'max_storage_bytes' => 1 * 1024 * 1024 * 1024,      // 1 GiB (初期値。プラン設計で調整可能)
         ],
+        'personal' => [
+            'max_projects' => 1,
+            'max_members' => 3,                                 // PersonalPlanService::MAX_MEMBERS と一致させる
+            'max_storage_bytes' => 1 * 1024 * 1024 * 1024,      // 1 GiB (free の後継 = 実効 limits 不変)
+        ],
+        'starter' => [
+            'max_projects' => 1,
+            'max_members' => 3,
+            'max_storage_bytes' => 1 * 1024 * 1024 * 1024,      // 1 GiB (personal と同能力。差は基本料のみ)
+        ],
         'standard' => [
             'max_projects' => 10,
             'max_members' => 10,
diff --git a/database/factories/OrganizationFactory.php b/database/factories/OrganizationFactory.php
index 0974d2b..2a201ee 100644
--- a/database/factories/OrganizationFactory.php
+++ b/database/factories/OrganizationFactory.php
@@ -7,6 +7,9 @@
 use App\Models\CustomTeam;
 use App\Models\Organization;
 use App\Models\Team;
+use App\Models\User;
+use App\Services\Billing\PersonalPlanService;
+use Carbon\CarbonImmutable;
 use Illuminate\Database\Eloquent\Factories\Factory;
 use Illuminate\Support\Str;
 
@@ -53,4 +56,41 @@ public function personal(): static
     {
         return $this->state(fn () => ['is_personal' => true]);
     }
+
+    /**
+     * パーソナルプラン (free) 有効化済みの組織 (declarer は自己申告した user)。
+     * PersonalPlanService::activate() の結果状態を Factory で再現する
+     * (partial unique index `organizations_personal_free_declarer_unique` の対象になる)。
+     */
+    public function freePersonal(User $declarer): static
+    {
+        return $this->state(fn (): array => [
+            'free_plan_code' => PersonalPlanService::FREE_PLAN_CODE,
+            'free_plan_activated_at' => CarbonImmutable::now(),
+            'personal_declared_at' => CarbonImmutable::now(),
+            'personal_declared_by_user_id' => $declarer->getKey(),
+        ]);
+    }
+
+    /**
+     * declarer 不在の free personal 組織 (自己申告の記録より前から free だった既存組織)。
+     * personal_declared_by_user_id が NULL のため partial unique index の対象外になる。
+     */
+    public function grandfathered(): static
+    {
+        return $this->state(fn (): array => [
+            'free_plan_code' => PersonalPlanService::FREE_PLAN_CODE,
+            'free_plan_activated_at' => CarbonImmutable::now(),
+            'personal_declared_at' => null,
+            'personal_declared_by_user_id' => null,
+        ]);
+    }
+
+    /** 初回無償チケット付与済み (org 単位 1 回マーカーが立っている) 組織 */
+    public function signupGranted(): static
+    {
+        return $this->state(fn (): array => [
+            'signup_tickets_granted_at' => CarbonImmutable::now(),
+        ]);
+    }
 }
diff --git a/database/migrations/2026_07_17_000100_add_free_plan_and_signup_grant_marker_to_organizations.php b/database/migrations/2026_07_17_000100_add_free_plan_and_signup_grant_marker_to_organizations.php
new file mode 100644
index 0000000..42923b2
--- /dev/null
+++ b/database/migrations/2026_07_17_000100_add_free_plan_and_signup_grant_marker_to_organizations.php
@@ -0,0 +1,61 @@
+<?php
+
+declare(strict_types=1);
+
+use Illuminate\Database\Migrations\Migration;
+use Illuminate\Database\Schema\Blueprint;
+use Illuminate\Support\Facades\DB;
+use Illuminate\Support\Facades\Schema;
+
+/**
+ * personal-free-plan: org レベル free entitlement と初回付与マーカーを導入する。
+ *
+ * - free_plan_code: 現在有効な free プラン code ('personal' のみ想定。汎用 framework 化はしない。
+ *   値域はアプリ側で PersonalPlanService::FREE_PLAN_CODE 定数経由の書き込みに閉じる)
+ * - personal_declared_at / personal_declared_by_user_id: 個人利用の自己申告 (監査証跡)
+ * - signup_tickets_granted_at: 初回無償チケット付与の「org 単位で生涯 1 回」マーカー
+ *   (free 有効化・paid サブスク成立の両経路で共用する真実源)
+ * - partial unique index: 「1 user が declarer である active free personal org は 1 つまで」を
+ *   DB で強制する (farming 防止の hard invariant。PostgreSQL / SQLite とも partial index 対応)
+ */
+return new class extends Migration
+{
+    public function up(): void
+    {
+        Schema::table('organizations', function (Blueprint $table): void {
+            $table->string('free_plan_code', 32)->nullable();
+            $table->timestamp('free_plan_activated_at')->nullable();
+            $table->timestamp('personal_declared_at')->nullable();
+            $table->foreignId('personal_declared_by_user_id')->nullable()
+                ->constrained('users')->nullOnDelete();
+            $table->timestamp('signup_tickets_granted_at')->nullable();
+
+            $table->index('free_plan_code');
+        });
+
+        DB::statement(<<<'SQL'
+            CREATE UNIQUE INDEX organizations_personal_free_declarer_unique
+            ON organizations (personal_declared_by_user_id)
+            WHERE free_plan_code = 'personal' AND personal_declared_by_user_id IS NOT NULL
+        SQL);
+
+        // backfill (既存付与済 org のマーカー立て) は続く data migration
+        // `2026_07_17_000110_backfill_signup_tickets_granted_at` に分離 (単体テスト可能にするため)。
+    }
+
+    public function down(): void
+    {
+        DB::statement('DROP INDEX IF EXISTS organizations_personal_free_declarer_unique');
+
+        Schema::table('organizations', function (Blueprint $table): void {
+            $table->dropConstrainedForeignId('personal_declared_by_user_id');
+            $table->dropIndex(['free_plan_code']);
+            $table->dropColumn([
+                'free_plan_code',
+                'free_plan_activated_at',
+                'personal_declared_at',
+                'signup_tickets_granted_at',
+            ]);
+        });
+    }
+};
diff --git a/database/migrations/2026_07_17_000110_backfill_signup_tickets_granted_at.php b/database/migrations/2026_07_17_000110_backfill_signup_tickets_granted_at.php
new file mode 100644
index 0000000..2249652
--- /dev/null
+++ b/database/migrations/2026_07_17_000110_backfill_signup_tickets_granted_at.php
@@ -0,0 +1,39 @@
+<?php
+
+declare(strict_types=1);
+
+use Illuminate\Database\Migrations\Migration;
+use Illuminate\Support\Facades\DB;
+
+/**
+ * personal-free-plan: 初回付与マーカーの backfill。
+ *
+ * 既に signup grant (`signup_grant:%` の ledger 行) を受けた org は
+ * `signup_tickets_granted_at` を最初の付与日時で立てる。マーカー導入前に付与済みの org を
+ * 塞ぎ、free 有効化経路での再付与を防ぐ。
+ *
+ * 冪等 (whereNull ガード)。相関サブクエリの UPDATE は PostgreSQL / SQLite 双方で有効。
+ */
+return new class extends Migration
+{
+    public function up(): void
+    {
+        DB::table('organizations')
+            ->whereNull('signup_tickets_granted_at')
+            ->whereIn('id', DB::table('ticket_ledger_entries')
+                ->where('idempotency_key', 'like', 'signup_grant:%')
+                ->select('organization_id'))
+            ->update([
+                'signup_tickets_granted_at' => DB::raw(
+                    '(select min(granted_at) from ticket_ledger_entries'
+                    .' where ticket_ledger_entries.organization_id = organizations.id'
+                    ." and ticket_ledger_entries.idempotency_key like 'signup_grant:%')"
+                ),
+            ]);
+    }
+
+    public function down(): void
+    {
+        // backfill の巻き戻しは「どの org が migration 起因か」を識別できないため意図的に no-op。
+    }
+};
diff --git a/database/migrations/2026_07_17_000120_add_is_active_to_plans.php b/database/migrations/2026_07_17_000120_add_is_active_to_plans.php
new file mode 100644
index 0000000..b3bae82
--- /dev/null
+++ b/database/migrations/2026_07_17_000120_add_is_active_to_plans.php
@@ -0,0 +1,29 @@
+<?php
+
+declare(strict_types=1);
+
+use Illuminate\Database\Migrations\Migration;
+use Illuminate\Database\Schema\Blueprint;
+use Illuminate\Support\Facades\Schema;
+
+/**
+ * plans.is_active: プランの公開制御 (料金表・課金ページへの露出可否の唯一の場所)。
+ *
+ * 既定 true のため既存 free / standard 行の公開状態は変わらない (additive)。
+ */
+return new class extends Migration
+{
+    public function up(): void
+    {
+        Schema::table('plans', function (Blueprint $table): void {
+            $table->boolean('is_active')->default(true);
+        });
+    }
+
+    public function down(): void
+    {
+        Schema::table('plans', function (Blueprint $table): void {
+            $table->dropColumn('is_active');
+        });
+    }
+};
diff --git a/database/seeders/PlanSeeder.php b/database/seeders/PlanSeeder.php
index 4be24d5..f4b736c 100644
--- a/database/seeders/PlanSeeder.php
+++ b/database/seeders/PlanSeeder.php
@@ -15,10 +15,13 @@
  *
  * - 能力はチケット付与数 (monthly_ticket_grant) と config/quota.php の limits の
  *   「値」で表現する (プラン名でのコード分岐は禁止 = docs 07 ガイド §4)
+ * - 月次付与は廃止 (D28)。全 tier の monthly_ticket_grant は 0 で、チケットは
+ *   signup grant と都度購入で供給する (列とコード経路は残すため運用上の再開は可能)
  * - 価格の真実源は plan_prices (DB snapshot)。ここでは bootstrap 行
  *   (stripe_price_id=price_test_* / livemode=false / synced_at=null) を投入し、
  *   実運用では `billing:sync-stripe-prices` が Stripe Catalog の実 Price ID へ上書きする
- * - free プランは Stripe Price を持たない (Checkout 対象外。未契約の既定)。
+ * - free / personal プランは Stripe Price を持たない (Checkout 対象外。
+ *   personal は activate 経由の無料プランで requiresStripeCheckout()=false)。
  *   これは BillingAccess の entitlement 判定の前提でもある: plan_code は Stripe Price →
  *   Plan 解決 (StripeWebhookProcessor) でのみ set されるため、Price を持たない free が
  *   plan_code に載る経路はない (null = 未契約 = 支払い不要の free tier)。free に Price を
@@ -35,31 +38,44 @@ class PlanSeeder extends Seeder
      * @var array<string, array<string, int>>
      */
     private const PRICE_AMOUNTS = [
+        'starter' => ['base' => 980],
         'standard' => ['base' => 4980],
     ];
 
     public function run(): void
     {
-        // free は Checkout を持たないため plan_prices は作らない
-        Plan::query()->updateOrCreate(
-            ['code' => 'free'],
-            [
-                'name' => 'Free',
-                'monthly_ticket_grant' => 10,
-                'sort_order' => 0,
-            ],
-        );
+        // free / personal は Checkout を持たないため plan_prices は作らない
+        // (free は後継 personal への移行が完了するまでの残置)
+        $this->upsertPlan('free', 'Free', 0);
+        $this->upsertPlan('personal', 'Personal', 1);
+        $this->upsertPlan('starter', 'Starter', 2);
+        $this->upsertPlan('standard', 'Standard', 3);
+
+        $this->seedPlanPrices();
+    }
 
-        Plan::query()->updateOrCreate(
-            ['code' => 'standard'],
+    /**
+     * プラン行を投入する (D28: monthly_ticket_grant は全 tier 0)。
+     *
+     * is_active は属性配列に入れず新規作成時のみ true を確定する。運用者が管理画面で
+     * 変更した公開状態を seed 再実行で踏み潰さないため (公開制御の唯一の場所は
+     * PricingService::listPublicPlans() の is_active フィルタ)。
+     */
+    private function upsertPlan(string $code, string $name, int $sortOrder): void
+    {
+        $plan = Plan::query()->updateOrCreate(
+            ['code' => $code],
             [
-                'name' => 'Standard',
-                'monthly_ticket_grant' => 100,
-                'sort_order' => 1,
+                'name' => $name,
+                'monthly_ticket_grant' => 0,
+                'sort_order' => $sortOrder,
             ],
         );
 
-        $this->seedPlanPrices();
+        if ($plan->wasRecentlyCreated) {
+            $plan->is_active = true;
+            $plan->save();
+        }
     }
 
     /**
diff --git a/resources/js/pages/Billing/Index.svelte b/resources/js/pages/Billing/Index.svelte
index e44fb6b..cafc081 100644
--- a/resources/js/pages/Billing/Index.svelte
+++ b/resources/js/pages/Billing/Index.svelte
@@ -25,7 +25,6 @@
     interface Plan {
         code: string;
         name: string;
-        monthlyTicketGrant: number;
         price: PlanPrice | null;
     }
 
@@ -151,7 +150,7 @@
                                             {/if}
                                         </div>
                                         <p class="mt-1 text-caption text-text-secondary">
-                                            {formatPrice(plan.price)} ・ 月 {plan.monthlyTicketGrant} 枚のチケット付与
+                                            {formatPrice(plan.price)}
                                         </p>
                                     </div>
                                     {#if canManageBilling && plan.price !== null && plan.code !== currentPlanCode}
diff --git a/resources/js/pages/Pricing.svelte b/resources/js/pages/Pricing.svelte
index ed84105..c02e822 100644
--- a/resources/js/pages/Pricing.svelte
+++ b/resources/js/pages/Pricing.svelte
@@ -9,7 +9,7 @@
     import type { PricingPageProps, PricingPlanShape } from "@/types/marketing";
 
     /**
-     * 公開料金表 (/pricing)。プラン基本料 (free / standard) + 共通チケット制の説明 +
+     * 公開料金表 (/pricing)。プラン基本料 (公開プランは props で供給) + 共通チケット制の説明 +
      * チケット傾斜料金表 + FAQ。title / description はサーバ SEO が正本のため
      * svelte:head は付けない。
      */
@@ -26,7 +26,6 @@
     const formatLimit = (v: number | null): string => (v === null ? "無制限" : String(v));
 
     const buildFeatures = (plan: PricingPlanShape): PricingFeature[] => [
-        { text: `月 ${plan.monthlyTicketGrant} 枚のチケット付与` },
         { text: `プロジェクト ${formatLimit(plan.maxProjects)}` },
         { text: `メンバー ${formatLimit(plan.maxMembers)} 名` },
         {
diff --git a/resources/js/types/marketing.ts b/resources/js/types/marketing.ts
index e852d19..d7d4c89 100644
--- a/resources/js/types/marketing.ts
+++ b/resources/js/types/marketing.ts
@@ -23,7 +23,6 @@ export interface PricingPlanShape {
     readonly code: string;
     readonly name: string;
     readonly baseAmountJpy: number | null;
-    readonly monthlyTicketGrant: number;
     readonly maxProjects: number | null;
     readonly maxMembers: number | null;
     readonly maxStorageGb: number | null;
diff --git a/stripe/fixtures/plan_starter.json b/stripe/fixtures/plan_starter.json
new file mode 100644
index 0000000..9c39a51
--- /dev/null
+++ b/stripe/fixtures/plan_starter.json
@@ -0,0 +1,41 @@
+{
+  "_meta": {
+    "template_version": 0
+  },
+  "fixtures": [
+    {
+      "name": "starter_product",
+      "path": "/v1/products",
+      "method": "post",
+      "params": {
+        "name": "Starter プラン",
+        "description": "Starter プラン (月額)",
+        "metadata": {
+          "plan_code": "starter",
+          "managed_by": "app"
+        }
+      }
+    },
+    {
+      "name": "starter_base_price",
+      "path": "/v1/prices",
+      "method": "post",
+      "params": {
+        "currency": "jpy",
+        "unit_amount": 980,
+        "product": "${starter_product:id}",
+        "lookup_key": "app_starter_base",
+        "nickname": "Starter 基本料 (月額)",
+        "recurring": {
+          "interval": "month",
+          "interval_count": 1
+        },
+        "metadata": {
+          "plan_code": "starter",
+          "kind": "base",
+          "managed_by": "app"
+        }
+      }
+    }
+  ]
+}
diff --git a/tests/Architecture/FreePlanCodeWriteInvariantTest.php b/tests/Architecture/FreePlanCodeWriteInvariantTest.php
new file mode 100644
index 0000000..4d0f5ff
--- /dev/null
+++ b/tests/Architecture/FreePlanCodeWriteInvariantTest.php
@@ -0,0 +1,41 @@
+<?php
+
+declare(strict_types=1);
+
+use Symfony\Component\Finder\Finder;
+
+/*
+|--------------------------------------------------------------------------
+| free_plan_code 書き込み経路の invariant
+|--------------------------------------------------------------------------
+|
+| `organizations.free_plan_code` は課金状態 (free entitlement) を確定させる状態キーのため、
+| 書き込み (array key 代入 / プロパティ代入) は PersonalPlanService に閉じる。値域
+| ('personal' のみ) を DB check constraint ではなくアプリ側定数
+| (PersonalPlanService::FREE_PLAN_CODE) で守る前提の機械的補助。
+| 読み取り (`->free_plan_code` の比較) は対象外。
+*/
+
+test('app/ 内の free_plan_code 書き込みは PersonalPlanService に閉じる', function (): void {
+    $allowlist = [
+        'app/Services/Billing/PersonalPlanService.php',
+    ];
+
+    // 書き込みパターン: array key 代入 ('free_plan_code' => / "free_plan_code" =>) と
+    // プロパティ代入 (->free_plan_code = 値。=== / !== 比較は除外)。
+    $finder = Finder::create()
+        ->in(base_path('app'))
+        ->files()
+        ->name('*.php')
+        ->contains('/([\'"])free_plan_code\1\s*=>|->free_plan_code\s*=[^=]/');
+
+    $violations = [];
+    foreach ($finder as $file) {
+        $relative = str_replace(base_path().'/', '', (string) $file->getRealPath());
+        if (! in_array($relative, $allowlist, true)) {
+            $violations[] = $relative;
+        }
+    }
+
+    expect($violations)->toBe([], 'free_plan_code の書き込みは PersonalPlanService 経由に限定してください: '.implode(', ', $violations));
+});
diff --git a/tests/Architecture/MembershipWriteLockInventoryTest.php b/tests/Architecture/MembershipWriteLockInventoryTest.php
index 01084ee..46b61ee 100644
--- a/tests/Architecture/MembershipWriteLockInventoryTest.php
+++ b/tests/Architecture/MembershipWriteLockInventoryTest.php
@@ -85,8 +85,11 @@
  * が OrganizationMembershipService (全経路ロック済み) と OrganizationProvisioningService
  * (新規組織生成時の creator への Owner 付与のみ = 既存組織の owner 集合は変えない bootstrap 例外)
  * 以外に現れないことを静的に強制し、未ロック経路の混入 (直列化の破れ) を検出する。
- * 現状 role_user を参照するアプリコードは上記サービスのみ (grep 済み) のため、
- * role_user への言及自体を許可リスト外で禁止する広め (read 含む) の guard で足りる。
+ *
+ * role_user を **読み取るだけ** のコード (判定クエリ) は owner 集合を変えないため直列化前提を
+ * 破らない。読み取り専用の参照は $readOnly に登録して許可し、そのファイルが Laratrust の
+ * 書き込み API (addRole/removeRole/syncRoles) を含まないことを別途強制する
+ * (= 読み取り許可が書き込みの抜け穴にならないようにする)。
  */
 test('org ロール割当 (role_user) の書き込みは既知のロック済みサービス経由のみ (owner 変更の直列化前提を守る)', function (): void {
     $appDir = dirname(__DIR__, 2).'/app';
@@ -94,11 +97,18 @@
         'Services/Organization/OrganizationMembershipService.php', // 全経路 lockForMembershipWrite 済み
         'Services/Organization/OrganizationProvisioningService.php', // 新規組織の creator への Owner 付与のみ
     ];
+    // role_user を読み取るだけ (owner 集合を変えない) のため許可するファイル。
+    // 下で「Laratrust 書き込み API を含まないこと」を強制する。
+    $readOnly = [
+        // eligibility(): 同一 user が owner として在籍する別 free personal org の存在判定
+        'Services/Billing/PersonalPlanService.php',
+    ];
 
     $iterator = new RecursiveIteratorIterator(
         new RecursiveDirectoryIterator($appDir, FilesystemIterator::SKIP_DOTS),
     );
     $offenders = [];
+    $readOnlyViolations = [];
     /** @var SplFileInfo $file */
     foreach ($iterator as $file) {
         if ($file->getExtension() !== 'php') {
@@ -109,12 +119,29 @@
             continue;
         }
         $contents = file_get_contents($file->getPathname()) ?: '';
+
+        // 読み取り専用許可: Laratrust の書き込み API を含まないことだけを強制する
+        // (含んでいたら「読み取りのみ」の前提が崩れているので違反として報告する)。
+        if (in_array($relative, $readOnly, true)) {
+            if (preg_match('/->(addRole|removeRole|syncRoles)\(/', $contents) === 1) {
+                $readOnlyViolations[] = $relative;
+            }
+
+            continue;
+        }
+
         // Laratrust API 経路 + role_user pivot への直接アクセスの双方を検出する。
         if (preg_match('/->(addRole|removeRole|syncRoles)\(|role_user/', $contents) === 1) {
             $offenders[] = $relative;
         }
     }
 
+    expect($readOnlyViolations)->toBe(
+        [],
+        '読み取り専用として許可した role_user 参照が Laratrust 書き込み API を含んでいます '
+        .'($readOnly から外し、ロック済みサービス経由へ移すこと)。',
+    );
+
     expect($offenders)->toBe(
         [],
         'Laratrust ロール書き込みは lockForMembershipWrite 済みのサービス経由のみに限定すること '
diff --git a/tests/Feature/Billing/BillingPageTest.php b/tests/Feature/Billing/BillingPageTest.php
index 9e199de..e8b04b0 100644
--- a/tests/Feature/Billing/BillingPageTest.php
+++ b/tests/Feature/Billing/BillingPageTest.php
@@ -22,12 +22,18 @@
         ->assertOk()
         ->assertInertia(fn (Assert $page) => $page
             ->component('Billing/Index')
-            ->has('plans', 2)
+            // sort_order 昇順 (free 0 / personal 1 / starter 2 / standard 3)
+            ->has('plans', 4)
             ->where('plans.0.code', 'free')
             ->where('plans.0.price', null)
-            ->where('plans.1.code', 'standard')
-            ->where('plans.1.monthlyTicketGrant', 100)
-            ->has('plans.1.price', fn (Assert $price) => $price
+            ->where('plans.1.code', 'personal')
+            ->where('plans.1.price', null) // activate 経由の無料プラン = Price 無し
+            ->where('plans.2.code', 'starter')
+            ->has('plans.2.price', fn (Assert $price) => $price
+                ->where('unitAmount', 980)
+                ->where('currency', 'jpy'))
+            ->where('plans.3.code', 'standard')
+            ->has('plans.3.price', fn (Assert $price) => $price
                 ->where('unitAmount', 4980)
                 ->where('currency', 'jpy'))
             ->where('currentPlanCode', null)
diff --git a/tests/Feature/Billing/InvoiceLinePricingShapeTest.php b/tests/Feature/Billing/InvoiceLinePricingShapeTest.php
index befba65..26564c1 100644
--- a/tests/Feature/Billing/InvoiceLinePricingShapeTest.php
+++ b/tests/Feature/Billing/InvoiceLinePricingShapeTest.php
@@ -30,6 +30,18 @@ function pricingShapeStandardBasePriceId(): string
     return $price->stripe_price_id;
 }
 
+/**
+ * standard プランの月次付与を有効化する (arrange)。
+ *
+ * D28 で月次付与は廃止され seed 既定の monthly_ticket_grant は全 tier 0 になった。
+ * 本テストの関心 (invoice line の price 形状解決) は月次付与の発火で観測するため、
+ * arrange で明示的に枚数を設定する。
+ */
+function pricingShapeEnableMonthlyGrant(): void
+{
+    Plan::query()->where('code', 'standard')->update(['monthly_ticket_grant' => 100]);
+}
+
 /** stripe customer を持つ組織を作る (WebhookIdempotencyTest とは独立の helper) */
 function pricingShapeStripeCustomer(string $stripeId): Organization
 {
@@ -63,6 +75,7 @@ function invoicePaidPayloadWithLine(string $eventId, string $customerId, array $
 
 test('新形状 (pricing.price_details.price) の invoice.paid で月次付与される', function (): void {
     $organization = pricingShapeStripeCustomer('cus_clover_create');
+    pricingShapeEnableMonthlyGrant();
 
     // 実イベントと同形状: price キーは null で届き pricing のみが price を持つ
     event(new WebhookReceived(invoicePaidPayloadWithLine('evt_clover_create', 'cus_clover_create', [
@@ -85,6 +98,7 @@ function invoicePaidPayloadWithLine(string $eventId, string $customerId, array $
 
 test('旧形状 (price.id) の invoice.paid でも月次付与される (後方互換)', function (): void {
     $organization = pricingShapeStripeCustomer('cus_legacy_create');
+    pricingShapeEnableMonthlyGrant();
 
     event(new WebhookReceived(invoicePaidPayloadWithLine('evt_legacy_create', 'cus_legacy_create', [
         'price' => ['id' => pricingShapeStandardBasePriceId()],
@@ -96,6 +110,7 @@ function invoicePaidPayloadWithLine(string $eventId, string $customerId, array $
 
 test('新形状の price が plan_prices に無ければ月次付与しない', function (): void {
     $organization = pricingShapeStripeCustomer('cus_clover_unknown');
+    pricingShapeEnableMonthlyGrant();
 
     event(new WebhookReceived(invoicePaidPayloadWithLine('evt_clover_unknown', 'cus_clover_unknown', [
         'price' => null,
diff --git a/tests/Feature/Billing/PersonalPlanServiceTest.php b/tests/Feature/Billing/PersonalPlanServiceTest.php
new file mode 100644
index 0000000..31fc0d1
--- /dev/null
+++ b/tests/Feature/Billing/PersonalPlanServiceTest.php
@@ -0,0 +1,230 @@
+<?php
+
+declare(strict_types=1);
+
+use App\Enums\Billing\PersonalPlanIneligibleReason;
+use App\Enums\OrganizationRole;
+use App\Exceptions\Billing\PersonalPlanNotEligibleException;
+use App\Models\Organization;
+use App\Models\User;
+use App\Services\Billing\PersonalPlanService;
+use App\Services\Billing\TicketLedgerService;
+use App\Services\Organization\OrganizationProvisioningService;
+use Illuminate\Database\QueryException;
+
+/*
+|--------------------------------------------------------------------------
+| PersonalPlanService (パーソナルプラン = free entitlement)
+|--------------------------------------------------------------------------
+|
+| free entitlement は organizations.free_plan_code で表現する (subscriptions は Stripe 実体
+| のみを保持する invariant を守る)。初回無償チケットは organizations.signup_tickets_granted_at
+| マーカーの条件付き先取で「org 単位で生涯 1 回」に閉じる。
+*/
+
+function personalPlanService(): PersonalPlanService
+{
+    return app(PersonalPlanService::class);
+}
+
+function personalPlanBalance(Organization $organization): int
+{
+    return app(TicketLedgerService::class)->balance($organization);
+}
+
+function signupGrantEntryCount(Organization $organization): int
+{
+    return $organization->ticketLedgerEntries()
+        ->where('idempotency_key', 'like', 'signup_grant:%')
+        ->count();
+}
+
+describe('eligibility', function (): void {
+    test('有効な有償契約 (active/trialing) がある組織は HasEntitledSubscription で不可', function (): void {
+        [$organization, $owner] = createOrganizationWithOwner();
+        createFakeSubscription($organization, status: 'active');
+
+        $eligibility = personalPlanService()->eligibility($organization, $owner);
+
+        expect($eligibility->eligible)->toBeFalse();
+        expect($eligibility->reason)->toBe(PersonalPlanIneligibleReason::HasEntitledSubscription);
+    });
+
+    test('canceled サブスク行が残る組織は選択できる (paid → free 経路)', function (): void {
+        [$organization, $owner] = createOrganizationWithOwner();
+        createFakeSubscription($organization, status: 'canceled');
+
+        expect(personalPlanService()->eligibility($organization, $owner)->eligible)->toBeTrue();
+    });
+
+    test('在籍 4 名の組織は TooManyMembers で不可 (キャップ 3 名)', function (): void {
+        [$organization, $owner] = createOrganizationWithOwner();
+        attachOrganizationMember($organization, OrganizationRole::Member);
+        attachOrganizationMember($organization, OrganizationRole::Member);
+
+        // owner + member 2 = 3 名: 許可
+        expect(personalPlanService()->eligibility($organization, $owner)->eligible)->toBeTrue();
+
+        // 4 名目で不可
+        attachOrganizationMember($organization, OrganizationRole::Member);
+        $eligibility = personalPlanService()->eligibility($organization, $owner);
+
+        expect($eligibility->eligible)->toBeFalse();
+        expect($eligibility->reason)->toBe(PersonalPlanIneligibleReason::TooManyMembers);
+    });
+
+    test('同一 user が declarer の free personal 組織を既に持つ場合は AlreadyHasFreePersonalOrg で不可', function (): void {
+        [$first, $owner] = createOrganizationWithOwner('1 つ目の組織');
+        personalPlanService()->activate($first, $owner);
+
+        $second = app(OrganizationProvisioningService::class)->provision($owner, '2 つ目の組織');
+        $eligibility = personalPlanService()->eligibility($second, $owner);
+
+        expect($eligibility->eligible)->toBeFalse();
+        expect($eligibility->reason)->toBe(PersonalPlanIneligibleReason::AlreadyHasFreePersonalOrg);
+    });
+
+    test('declarer ではないが owner として在籍する free personal 組織があれば不可', function (): void {
+        // 既存 free 組織: declarer は別 user、対象 user は owner として在籍する
+        [$freeOrg, $declarer] = createOrganizationWithOwner('既存 free 組織');
+        personalPlanService()->activate($freeOrg, $declarer);
+
+        $otherOwner = attachOrganizationMember($freeOrg, OrganizationRole::Owner);
+
+        $second = app(OrganizationProvisioningService::class)->provision($otherOwner, '別組織');
+        $eligibility = personalPlanService()->eligibility($second, $otherOwner);
+
+        expect($eligibility->eligible)->toBeFalse();
+        expect($eligibility->reason)->toBe(PersonalPlanIneligibleReason::AlreadyHasFreePersonalOrg);
+    });
+
+    test('declarer NULL の grandfathered free 組織は declarer 枠を占有しない', function (): void {
+        // 自己申告の記録より前から free だった組織 (partial unique index の対象外)
+        $user = User::factory()->create();
+        Organization::factory()->grandfathered()->create();
+
+        $organization = app(OrganizationProvisioningService::class)->provision($user, '新しい組織');
+
+        expect(personalPlanService()->eligibility($organization, $user)->eligible)->toBeTrue();
+    });
+});
+
+describe('activate', function (): void {
+    test('free_plan_code / 自己申告の監査列 / マーカーが立ち、初回チケットが 1 回だけ付与される', function (): void {
+        [$organization, $owner] = createOrganizationWithOwner();
+        $expected = config()->integer('billing.signup_grant_tickets');
+
+        $result = personalPlanService()->activate($organization, $owner);
+
+        expect($result->granted)->toBeTrue();
+
+        $organization->refresh();
+        expect($organization->free_plan_code)->toBe(PersonalPlanService::FREE_PLAN_CODE);
+        expect($organization->free_plan_activated_at)->not->toBeNull();
+        expect($organization->personal_declared_at)->not->toBeNull();
+        expect($organization->personal_declared_by_user_id)->toBe($owner->id);
+        expect($organization->signup_tickets_granted_at)->not->toBeNull();
+
+        expect(personalPlanBalance($organization))->toBe($expected);
+        $entry = $organization->ticketLedgerEntries()->firstOrFail();
+        expect($entry->idempotency_key)->toBe("signup_grant:personal:{$organization->id}");
+        expect($entry->expires_at)->not->toBeNull();
+    });
+
+    test('同一組織の再 activate は granted=false で残高不変 (マーカー先取が 0 件)', function (): void {
+        [$organization, $owner] = createOrganizationWithOwner();
+        $expected = config()->integer('billing.signup_grant_tickets');
+
+        personalPlanService()->activate($organization, $owner);
+        $second = personalPlanService()->activate($organization, $owner);
+
+        expect($second->granted)->toBeFalse();
+        expect(personalPlanBalance($organization))->toBe($expected);
+        expect(signupGrantEntryCount($organization))->toBe(1);
+    });
+
+    test('マーカー済み (backfill / paid 経験) の組織は付与なしで有効化のみ', function (): void {
+        $owner = User::factory()->create();
+        $organization = app(OrganizationProvisioningService::class)->provision($owner, 'マーカー済み組織');
+        $organization->forceFill(['signup_tickets_granted_at' => now()->subYear()])->save();
+
+        $result = personalPlanService()->activate($organization, $owner);
+
+        expect($result->granted)->toBeFalse();
+        expect($organization->refresh()->free_plan_code)->toBe(PersonalPlanService::FREE_PLAN_CODE);
+        expect($organization->ticketLedgerEntries()->count())->toBe(0);
+    });
+
+    test('eligibility 不可の組織は PersonalPlanNotEligibleException で拒否され、付与されない', function (): void {
+        [$organization, $owner] = createOrganizationWithOwner();
+        createFakeSubscription($organization, status: 'active');
+
+        expect(fn () => personalPlanService()->activate($organization, $owner))
+            ->toThrow(PersonalPlanNotEligibleException::class);
+
+        $organization->refresh();
+        expect($organization->free_plan_code)->toBeNull();
+        expect($organization->signup_tickets_granted_at)->toBeNull();
+        expect($organization->ticketLedgerEntries()->count())->toBe(0);
+    });
+
+    test('並行 activate の後着は QueryException を漏らさず AlreadyHasFreePersonalOrg になる (500 にしない)', function (): void {
+        // 並行 activate の窓 = 「eligibility は通ったが DB の partial unique index が拒否する」状態。
+        // 先着 org を soft delete することで、eligibility の Organization::query() からは
+        // 見えない (default scope) が index は declarer 枠を握ったままの状態を決定論的に作る。
+        [$first, $owner] = createOrganizationWithOwner('先着の組織');
+        personalPlanService()->activate($first, $owner);
+        $first->delete();
+
+        $second = app(OrganizationProvisioningService::class)->provision($owner, '後着の組織');
+
+        // 前提: eligibility は通る (= DB へ到達して初めて弾かれる経路であることの固定)
+        expect(personalPlanService()->eligibility($second, $owner)->eligible)->toBeTrue();
+
+        try {
+            personalPlanService()->activate($second, $owner);
+            $this->fail('PersonalPlanNotEligibleException が投げられませんでした');
+        } catch (QueryException $e) {
+            $this->fail('QueryException が漏れています (500 になる): '.$e->getMessage());
+        } catch (PersonalPlanNotEligibleException $e) {
+            expect($e->reason)->toBe(PersonalPlanIneligibleReason::AlreadyHasFreePersonalOrg);
+            expect($e->userMessage())->toBe(PersonalPlanIneligibleReason::AlreadyHasFreePersonalOrg->label());
+        }
+
+        // 後着はマーカーも付与も残さない (transaction ごと rollback される)
+        $second->refresh();
+        expect($second->free_plan_code)->toBeNull();
+        expect($second->signup_tickets_granted_at)->toBeNull();
+        expect($second->ticketLedgerEntries()->count())->toBe(0);
+    });
+});
+
+describe('retireForPaidSubscription', function (): void {
+    test('free_plan_code を null 化し、自己申告の監査列は残す (冪等)', function (): void {
+        [$organization, $owner] = createOrganizationWithOwner();
+        personalPlanService()->activate($organization, $owner);
+
+        personalPlanService()->retireForPaidSubscription($organization->refresh());
+
+        $organization->refresh();
+        expect($organization->free_plan_code)->toBeNull();
+        expect($organization->free_plan_activated_at)->not->toBeNull();
+        expect($organization->personal_declared_at)->not->toBeNull();
+        expect($organization->personal_declared_by_user_id)->toBe($owner->id);
+
+        // 2 回目は no-op
+        personalPlanService()->retireForPaidSubscription($organization);
+        expect($organization->refresh()->free_plan_code)->toBeNull();
+    });
+
+    test('退役で declarer 枠が解放され、同一 user が別組織を free 化できる', function (): void {
+        [$first, $owner] = createOrganizationWithOwner('1 つ目の組織');
+        personalPlanService()->activate($first, $owner);
+        personalPlanService()->retireForPaidSubscription($first->refresh());
+
+        $second = app(OrganizationProvisioningService::class)->provision($owner, '2 つ目の組織');
+
+        // 付与マーカーは org ごとに閉じるため 2 つ目は初回付与あり
+        expect(personalPlanService()->activate($second, $owner)->granted)->toBeTrue();
+    });
+});
diff --git a/tests/Feature/Billing/PlanActiveFilterTest.php b/tests/Feature/Billing/PlanActiveFilterTest.php
new file mode 100644
index 0000000..63dcf46
--- /dev/null
+++ b/tests/Feature/Billing/PlanActiveFilterTest.php
@@ -0,0 +1,42 @@
+<?php
+
+declare(strict_types=1);
+
+use App\Models\Billing\Plan;
+use Inertia\Testing\AssertableInertia as Assert;
+
+/*
+ * plans.is_active による公開制御。PricingService::listPublicPlans() の is_active
+ * フィルタが「プランを料金表に出すか」の唯一の場所であることを固定する
+ * (PlanSeeder は新規作成時のみ is_active=true を確定するため、運用者が管理画面で
+ * 非公開にしたプランは seed 再実行後も非公開のまま留まる)。
+ */
+
+test('is_active=false の Plan は /pricing の props に出ない', function (): void {
+    Plan::query()->where('code', 'standard')->update(['is_active' => false]);
+
+    $this->get('/pricing')
+        ->assertOk()
+        ->assertInertia(fn (Assert $page) => $page
+            ->has('page.plans', 3)
+            ->where('page.plans.0.code', 'free')
+            ->where('page.plans.1.code', 'personal')
+            ->where('page.plans.2.code', 'starter'));
+});
+
+test('is_active=true に戻した Plan は /pricing の props に出る', function (): void {
+    Plan::query()->where('code', 'standard')->update(['is_active' => false]);
+    Plan::query()->where('code', 'standard')->update(['is_active' => true]);
+
+    $this->get('/pricing')
+        ->assertOk()
+        ->assertInertia(fn (Assert $page) => $page
+            ->has('page.plans', 4)
+            ->where('page.plans.3.code', 'standard'));
+});
+
+test('seed 直後は全プランが is_active=true (公開方針)', function (): void {
+    expect(Plan::query()->where('is_active', false)->count())->toBe(0);
+    expect(Plan::query()->where('is_active', true)->pluck('code')->all())
+        ->toEqualCanonicalizing(['free', 'personal', 'starter', 'standard']);
+});
diff --git a/tests/Feature/Billing/PlanSeederPriceInvariantTest.php b/tests/Feature/Billing/PlanSeederPriceInvariantTest.php
index 2bd888e..edbfeed 100644
--- a/tests/Feature/Billing/PlanSeederPriceInvariantTest.php
+++ b/tests/Feature/Billing/PlanSeederPriceInvariantTest.php
@@ -19,6 +19,24 @@
     expect($standard->currentPrice(PlanPriceKind::Base))->not->toBeNull();
 });
 
+test('有償プラン starter は current base Price を持つ (seed 不変条件)', function (): void {
+    $starter = Plan::query()->where('code', 'starter')->firstOrFail();
+
+    expect($starter->currentPrice(PlanPriceKind::Base))->not->toBeNull();
+});
+
+test('personal プランは Stripe Price を持たない (activate 経由の無料プラン)', function (): void {
+    $personal = Plan::query()->where('code', 'personal')->firstOrFail();
+
+    expect($personal->currentPrice(PlanPriceKind::Base))->toBeNull();
+    expect($personal->prices()->count())->toBe(0);
+});
+
+test('全プランの monthly_ticket_grant が 0 (D28: 月次付与は廃止)', function (): void {
+    expect(Plan::query()->pluck('monthly_ticket_grant', 'code')->all())
+        ->toEqual(['free' => 0, 'personal' => 0, 'starter' => 0, 'standard' => 0]);
+});
+
 test('free プランは Stripe Price を持たない (Checkout 対象外の未契約既定)', function (): void {
     $free = Plan::query()->where('code', 'free')->firstOrFail();
 
diff --git a/tests/Feature/Billing/SeededFreePlanBillingAccessTest.php b/tests/Feature/Billing/SeededFreePlanBillingAccessTest.php
index 68cff4d..f8a106a 100644
--- a/tests/Feature/Billing/SeededFreePlanBillingAccessTest.php
+++ b/tests/Feature/Billing/SeededFreePlanBillingAccessTest.php
@@ -17,12 +17,20 @@
  * 要求して締め出していたこと (devnotes/20260713-1633-seeder-free-plan-billing)。
  */
 
-/** current base Price を持たない Free プランを 1 つ取得する */
+/**
+ * Free プラン (current base Price を持たない) を取得する。
+ *
+ * personal も Price を持たないため「Price 無しの最初の Plan」では対象が非決定になる。
+ * 本テストの関心は Free プラン組織のゲート素通りなので code で固定する。
+ */
 function seededFreePlan(): Plan
 {
-    return Plan::query()->get()
-        ->first(fn (Plan $p): bool => $p->currentPrice(PlanPriceKind::Base) === null)
-        ?? throw new RuntimeException('Free プラン (Price 無し) が seed されていない');
+    $plan = Plan::query()->where('code', 'free')->firstOrFail();
+    if ($plan->currentPrice(PlanPriceKind::Base) !== null) {
+        throw new RuntimeException('Free プランに Price が付いている (seed 不変条件の破れ)');
+    }
+
+    return $plan;
 }
 
 test('seeded Free 組織の全ロールが /projects に到達できる (F-C3 回帰)', function (OrganizationRole $role): void {
diff --git a/tests/Feature/Billing/SignupGrantOncePerOrgTest.php b/tests/Feature/Billing/SignupGrantOncePerOrgTest.php
new file mode 100644
index 0000000..294d5a9
--- /dev/null
+++ b/tests/Feature/Billing/SignupGrantOncePerOrgTest.php
@@ -0,0 +1,171 @@
+<?php
+
+declare(strict_types=1);
+
+use App\Models\Organization;
+use App\Models\User;
+use App\Services\Billing\PersonalPlanService;
+use App\Services\Billing\TicketLedgerService;
+use App\Services\Organization\OrganizationProvisioningService;
+use Carbon\CarbonImmutable;
+use Laravel\Cashier\Events\WebhookReceived;
+
+/*
+|--------------------------------------------------------------------------
+| 初回無償チケット付与の「org 単位で生涯 1 回」
+|--------------------------------------------------------------------------
+|
+| 真実源は organizations.signup_tickets_granted_at マーカー (条件付き UPDATE の先取)。
+| 二重防御として ticket_ledger_entries の部分 UNIQUE index
+| (organization_id WHERE idempotency_key LIKE 'signup_grant:%') が経路・キー種別を跨いで
+| org 生涯 1 行に閉じる。
+|
+| **移行期規約 (P6 まで)**: 付与契機は登録時 (CreateNewUser) のまま維持し、同一 tx で
+| マーカーを先取する。free 有効化 (PersonalPlanService::activate) は先取できたときのみ付与する。
+*/
+
+function grantOnceCustomer(string $stripeId = 'cus_grant_once'): Organization
+{
+    [$organization] = createOrganizationWithOwner();
+    // stripe_id は Cashier customer column (状態キー)。テストでは明示代入する
+    $organization->stripe_id = $stripeId;
+    $organization->save();
+
+    return $organization;
+}
+
+/**
+ * 初回契約の invoice.paid (billing_reason=subscription_create)。
+ * signup grant は plan 解決より前に走るため lines は不要 (月次付与は plan なしで no-op)。
+ *
+ * @return array<string, mixed>
+ */
+function grantOnceInvoicePaidPayload(string $eventId = 'evt_grant_once', string $stripeId = 'cus_grant_once'): array
+{
+    return [
+        'id' => $eventId,
+        'type' => 'invoice.paid',
+        'data' => [
+            'object' => [
+                'id' => 'in_grant_once',
+                'customer' => $stripeId,
+                'billing_reason' => 'subscription_create',
+            ],
+        ],
+    ];
+}
+
+function grantOnceSignupEntryCount(Organization $organization): int
+{
+    return $organization->ticketLedgerEntries()
+        ->where('idempotency_key', 'like', 'signup_grant:%')
+        ->count();
+}
+
+test('移行期: 登録時に付与され、同一 tx でマーカーも立つ', function (): void {
+    $this->post('/register', [
+        'name' => '山田 太郎',
+        'email' => 'grant-once@example.com',
+        'password' => 'SecurePass1234',
+        'terms_accepted' => '1',
+    ])->assertRedirect(route('verification.notice'));
+
+    $user = User::whereBlind('email', 'email_index', 'grant-once@example.com')->firstOrFail();
+    $organization = $user->organizations()->where('is_personal', true)->firstOrFail();
+
+    // 付与契機・枚数は不変 (現行挙動)
+    expect(app(TicketLedgerService::class)->balance($organization))
+        ->toBe(config()->integer('billing.signup_grant_tickets'));
+    expect($organization->ticketLedgerEntries()->firstOrFail()->idempotency_key)
+        ->toBe("signup_grant:org:{$organization->id}");
+
+    // 移行期に追加される唯一の効果: マーカーが同時に立つ
+    expect($organization->signup_tickets_granted_at)->not->toBeNull();
+});
+
+test('移行期: 登録済み (マーカー済み) の組織を activate しても再付与されない', function (): void {
+    $this->post('/register', [
+        'name' => '鈴木 花子',
+        'email' => 'grant-once-2@example.com',
+        'password' => 'SecurePass1234',
+        'terms_accepted' => '1',
+    ])->assertRedirect(route('verification.notice'));
+
+    $user = User::whereBlind('email', 'email_index', 'grant-once-2@example.com')->firstOrFail();
+    $organization = $user->organizations()->where('is_personal', true)->firstOrFail();
+    $balanceBefore = app(TicketLedgerService::class)->balance($organization);
+
+    $result = app(PersonalPlanService::class)->activate($organization, $user);
+
+    expect($result->granted)->toBeFalse();
+    expect(app(TicketLedgerService::class)->balance($organization))->toBe($balanceBefore);
+    expect(grantOnceSignupEntryCount($organization))->toBe(1);
+});
+
+test('マーカー済み組織への直接 claim は先取できない (条件付き UPDATE の 0 件)', function (): void {
+    $owner = User::factory()->create();
+    $organization = app(OrganizationProvisioningService::class)->provision($owner, 'マーカー済み組織');
+
+    expect(app(PersonalPlanService::class)->claimSignupGrantMarker($organization))->toBeTrue();
+    // 2 回目は既にマーカーが立っているため先取できない (= 付与しない)
+    expect(app(PersonalPlanService::class)->claimSignupGrantMarker($organization))->toBeFalse();
+});
+
+test('free 有効化済みの組織に paid webhook (subscription_create) が来ても二重付与しない', function (): void {
+    $organization = grantOnceCustomer();
+    $owner = $organization->users()->firstOrFail();
+
+    app(PersonalPlanService::class)->activate($organization, $owner);
+    expect(grantOnceSignupEntryCount($organization))->toBe(1);
+    $balanceBefore = app(TicketLedgerService::class)->balance($organization);
+
+    event(new WebhookReceived(grantOnceInvoicePaidPayload()));
+
+    // 部分 UNIQUE index が経路 (signup_grant:personal:% ↔ signup_grant:org:%) を跨いで弾く
+    expect(grantOnceSignupEntryCount($organization))->toBe(1);
+    expect(app(TicketLedgerService::class)->balance($organization))->toBe($balanceBefore);
+});
+
+test('paid webhook で付与済みの組織を free 有効化しても二重付与しない (逆順)', function (): void {
+    $organization = grantOnceCustomer();
+    $owner = $organization->users()->firstOrFail();
+
+    event(new WebhookReceived(grantOnceInvoicePaidPayload()));
+    expect(grantOnceSignupEntryCount($organization))->toBe(1);
+    $balanceBefore = app(TicketLedgerService::class)->balance($organization);
+
+    // P1〜P5 の paid webhook 経路はマーカーを立てない (claim ブロック追加は P6) ため、
+    // activate はマーカーを先取して付与を試みる。二重付与は部分 UNIQUE index が DB レベルで防ぐ
+    // (残高不変 = 金銭的影響なし)。
+    $result = app(PersonalPlanService::class)->activate($organization->refresh(), $owner);
+
+    expect($result->granted)->toBeTrue(); // マーカーは先取できる (P6 で閉じる想定の残余)
+    expect(grantOnceSignupEntryCount($organization))->toBe(1);
+    expect(app(TicketLedgerService::class)->balance($organization))->toBe($balanceBefore);
+});
+
+test('backfill migration: 付与履歴のある組織はマーカーが立ち、無い組織は null のまま (冪等)', function (): void {
+    $granted = grantOnceCustomer('cus_backfill_granted');
+    $notGranted = Organization::factory()->create();
+
+    // 既存の付与履歴を作る (サービス経由。台帳は append-only)
+    $grantedAt = CarbonImmutable::parse('2026-05-01 09:00:00');
+    $this->travelTo($grantedAt);
+    app(TicketLedgerService::class)->grantSignupGrant($granted, "signup_grant:org:{$granted->id}");
+    $this->travelBack();
+
+    // migration 適用前の既存データ相当へ戻す (マーカー未設定 + 付与済み)
+    $granted->forceFill(['signup_tickets_granted_at' => null])->save();
+
+    $migration = require database_path('migrations/2026_07_17_000110_backfill_signup_tickets_granted_at.php');
+    $migration->up();
+
+    expect($granted->refresh()->signup_tickets_granted_at?->toDateTimeString())
+        ->toBe('2026-05-01 09:00:00');
+    expect($notGranted->refresh()->signup_tickets_granted_at)->toBeNull();
+
+    // 冪等: 再実行しても値は動かない
+    $migration->up();
+    expect($granted->refresh()->signup_tickets_granted_at?->toDateTimeString())
+        ->toBe('2026-05-01 09:00:00');
+});
diff --git a/tests/Feature/Billing/SyncStripePricesCommandTest.php b/tests/Feature/Billing/SyncStripePricesCommandTest.php
index 4404ab7..3b63017 100644
--- a/tests/Feature/Billing/SyncStripePricesCommandTest.php
+++ b/tests/Feature/Billing/SyncStripePricesCommandTest.php
@@ -18,6 +18,11 @@ function syncStandardBaseLookupKey(): string
     return StripePriceLookupKeys::key('standard', PlanPriceKind::Base);
 }
 
+function syncStarterBaseLookupKey(): string
+{
+    return StripePriceLookupKeys::key('starter', PlanPriceKind::Base);
+}
+
 /**
  * @param  array<string, int|string>  $overrides
  */
@@ -40,14 +45,20 @@ function syncEntry(string $lookupKey, string $stripePriceId, array $overrides =
 
 /**
  * 宣言済み全 lookup_key の live エントリ map。
+ * コマンドは宣言済み lookup_key が 1 つでも欠けると fail-fast するため、
+ * 全宣言 (starter / standard) を揃える。
  *
  * @return array<string, StripePriceCatalogEntry>
  */
 function allSyncEntries(): array
 {
-    $lookupKey = syncStandardBaseLookupKey();
+    $starterKey = syncStarterBaseLookupKey();
+    $standardKey = syncStandardBaseLookupKey();
 
-    return [$lookupKey => syncEntry($lookupKey, 'price_live_standard_base')];
+    return [
+        $starterKey => syncEntry($starterKey, 'price_live_starter_base', ['unitAmount' => 980]),
+        $standardKey => syncEntry($standardKey, 'price_live_standard_base'),
+    ];
 }
 
 beforeEach(function (): void {
@@ -88,7 +99,8 @@ function allSyncEntries(): array
 
 test('通貨が CASHIER_CURRENCY と不一致なら失敗する', function (): void {
     $lookupKey = syncStandardBaseLookupKey();
-    $entries = [$lookupKey => syncEntry($lookupKey, 'price_live_standard_base', ['currency' => 'usd'])];
+    $entries = allSyncEntries();
+    $entries[$lookupKey] = syncEntry($lookupKey, 'price_live_standard_base', ['currency' => 'usd']);
 
     $this->mock(StripePriceCatalogClient::class, function ($mock) use ($entries): void {
         $mock->shouldReceive('fetchByLookupKeys')->once()->andReturn($entries);
diff --git a/tests/Feature/Billing/TicketGrantTest.php b/tests/Feature/Billing/TicketGrantTest.php
index 146a5b5..d1b3536 100644
--- a/tests/Feature/Billing/TicketGrantTest.php
+++ b/tests/Feature/Billing/TicketGrantTest.php
@@ -84,9 +84,9 @@ function grantService(): TicketLedgerService
     config()->set('billing.signup_grant_tickets', 10);
     config()->set('billing.signup_grant_expiry_days', 30);
 
-    // 冪等キーは org スコープ (signup_grant:org:{id}) を内部生成する。二重呼び出しでも 1 行のみ。
-    grantService()->grantSignupGrant($organization);
-    grantService()->grantSignupGrant($organization);
+    // 冪等キーは呼び出し側が渡す (org スコープ = signup_grant:org:{id})。二重呼び出しでも 1 行のみ。
+    grantService()->grantSignupGrant($organization, "signup_grant:org:{$organization->id}");
+    grantService()->grantSignupGrant($organization, "signup_grant:org:{$organization->id}");
 
     expect(grantService()->balance($organization))->toBe(10);
     expect($organization->ticketLedgerEntries()->count())->toBe(1);
@@ -105,7 +105,7 @@ function grantService(): TicketLedgerService
     [$organization] = createOrganizationWithOwner();
     config()->set('billing.signup_grant_tickets', 0);
 
-    expect(fn () => grantService()->grantSignupGrant($organization))
+    expect(fn () => grantService()->grantSignupGrant($organization, "signup_grant:org:{$organization->id}"))
         ->toThrow(InvalidArgumentException::class);
 });
 
@@ -114,7 +114,7 @@ function grantService(): TicketLedgerService
     $svc = grantService();
 
     // 1 回目: 公開ユースケース経由 (org スコープキー signup_grant:org:{id})
-    $svc->grantSignupGrant($organization);
+    $svc->grantSignupGrant($organization, "signup_grant:org:{$organization->id}");
 
     // 2 回目: 旧キー形式を直接投入 → 部分 UNIQUE index (organization_id WHERE idempotency_key
     // LIKE 'signup_grant:%') が別キーでも弾く (ON CONFLICT DO NOTHING)。
diff --git a/tests/Feature/Billing/VerifyStripePricesCommandTest.php b/tests/Feature/Billing/VerifyStripePricesCommandTest.php
index 8e9117a..1fd79d5 100644
--- a/tests/Feature/Billing/VerifyStripePricesCommandTest.php
+++ b/tests/Feature/Billing/VerifyStripePricesCommandTest.php
@@ -12,7 +12,7 @@
 /*
  * billing:verify-stripe-prices (fixture / Stripe Catalog / plan_prices の整合検証)。
  * Stripe API は呼ばない: StripePriceCatalogClient をモックして検証する。
- * fixture 側の期待値は stripe/fixtures/plan_standard.json (unit_amount=4980) に一致させる。
+ * fixture 側の期待値は stripe/fixtures/plan_{starter,standard}.json (unit_amount=980 / 4980) に一致させる。
  */
 
 function verifyStandardBaseLookupKey(): string
@@ -20,6 +20,11 @@ function verifyStandardBaseLookupKey(): string
     return StripePriceLookupKeys::key('standard', PlanPriceKind::Base);
 }
 
+function verifyStarterBaseLookupKey(): string
+{
+    return StripePriceLookupKeys::key('starter', PlanPriceKind::Base);
+}
+
 function verifyEntry(string $lookupKey, string $stripePriceId, int $unitAmount, string $currency = 'jpy'): StripePriceCatalogEntry
 {
     return new StripePriceCatalogEntry(
@@ -32,9 +37,14 @@ function verifyEntry(string $lookupKey, string $stripePriceId, int $unitAmount,
     );
 }
 
-/** plan_prices (current) を Stripe live id と fixture の金額に揃える */
+/** plan_prices (current) を Stripe live id と fixture の金額に揃える (宣言済み全 lookup_key) */
 function alignPlanPricesToStripe(): void
 {
+    PlanPrice::query()
+        ->where('lookup_key', verifyStarterBaseLookupKey())
+        ->where('is_current', true)
+        ->update(['stripe_price_id' => 'price_live_starter_base', 'amount' => 980]);
+
     PlanPrice::query()
         ->where('lookup_key', verifyStandardBaseLookupKey())
         ->where('is_current', true)
@@ -42,13 +52,19 @@ function alignPlanPricesToStripe(): void
 }
 
 /**
+ * 宣言済み全 lookup_key が fixture (starter 980 / standard 4980) と一致する map。
+ *
  * @return array<string, StripePriceCatalogEntry>
  */
 function happyStripeEntries(): array
 {
-    $lookupKey = verifyStandardBaseLookupKey();
+    $starterKey = verifyStarterBaseLookupKey();
+    $standardKey = verifyStandardBaseLookupKey();
 
-    return [$lookupKey => verifyEntry($lookupKey, 'price_live_standard_base', 4980)];
+    return [
+        $starterKey => verifyEntry($starterKey, 'price_live_starter_base', 980),
+        $standardKey => verifyEntry($standardKey, 'price_live_standard_base', 4980),
+    ];
 }
 
 beforeEach(function (): void {
@@ -114,7 +130,8 @@ function happyStripeEntries(): array
     $lookupKey = verifyStandardBaseLookupKey();
     // fixture は 4980 だが Stripe が 30000 = matchesSpec 不一致。
     // plan_prices.amount も合わせて (d) でなく (a) を単独発火させる
-    $entries = [$lookupKey => verifyEntry($lookupKey, 'price_live_standard_base', 30000)];
+    $entries = happyStripeEntries();
+    $entries[$lookupKey] = verifyEntry($lookupKey, 'price_live_standard_base', 30000);
     PlanPrice::query()->where('lookup_key', $lookupKey)->where('is_current', true)->update(['amount' => 30000]);
     $this->mock(StripePriceCatalogClient::class, function ($mock) use ($entries): void {
         $mock->shouldReceive('fetchByLookupKeys')->once()->andReturn($entries);
diff --git a/tests/Feature/Billing/WebhookIdempotencyTest.php b/tests/Feature/Billing/WebhookIdempotencyTest.php
index 1ad5015..6962c08 100644
--- a/tests/Feature/Billing/WebhookIdempotencyTest.php
+++ b/tests/Feature/Billing/WebhookIdempotencyTest.php
@@ -37,6 +37,18 @@ function billingStandardBasePriceId(): string
     return $price->stripe_price_id;
 }
 
+/**
+ * standard プランの月次付与を有効化する (arrange)。
+ *
+ * D28 で月次付与は廃止され seed 既定の monthly_ticket_grant は全 tier 0 になった。
+ * 列とコード経路 (StripeWebhookProcessor::grantMonthlyTickets) は運用上の再開のため
+ * 残しているので、その経路を検証する test は arrange で明示的に枚数を設定する。
+ */
+function enableStandardMonthlyGrant(int $count = 100): void
+{
+    Plan::query()->where('code', 'standard')->update(['monthly_ticket_grant' => $count]);
+}
+
 /**
  * @return array<string, mixed>
  */
@@ -85,6 +97,7 @@ function subscriptionPayload(string $type, string $status, string $eventId): arr
 
 test('同一 event_id の invoice.paid を 2 回発火しても付与は 1 回だけ', function (): void {
     $organization = billingStripeCustomer();
+    enableStandardMonthlyGrant();
 
     // listener 配線 (AppServiceProvider) ごと検証するため event() で発火する
     event(new WebhookReceived(invoicePaidPayload()));
@@ -101,6 +114,7 @@ function subscriptionPayload(string $type, string $status, string $eventId): arr
 
 test('event_id が異なれば別イベントとして処理される (invoice id が違えば別付与)', function (): void {
     $organization = billingStripeCustomer();
+    enableStandardMonthlyGrant();
 
     $first = invoicePaidPayload('evt_1');
     $second = invoicePaidPayload('evt_2');
@@ -115,6 +129,7 @@ function subscriptionPayload(string $type, string $status, string $eventId): arr
 
 test('event_id が異なっても同一 invoice の再通知は idempotency_key で二重付与しない', function (): void {
     $organization = billingStripeCustomer();
+    enableStandardMonthlyGrant();
 
     // Stripe が同一 invoice を別 event_id で再通知するケース (event_id 冪等では防げない)
     event(new WebhookReceived(invoicePaidPayload('evt_dup_a')));
@@ -127,6 +142,7 @@ function subscriptionPayload(string $type, string $status, string $eventId): arr
 
 test('billing_reason=subscription_create の invoice.paid は月次付与に加えて signup grant を冪等付与する', function (): void {
     $organization = billingStripeCustomer();
+    enableStandardMonthlyGrant();
 
     $payload = invoicePaidPayload('evt_signup_1');
     $payload['data']['object']['billing_reason'] = 'subscription_create';
@@ -135,7 +151,7 @@ function subscriptionPayload(string $type, string $status, string $eventId): arr
 
     // 月次 100 + signup grant (config billing.signup_grant_tickets = 10)
     expect(app(TicketLedgerService::class)->balance($organization))->toBe(110);
-    // 冪等キーは org スコープ (grantSignupGrant 内部生成)。subscription id には依存しない。
+    // 冪等キーは org スコープ (呼び出し側が渡す)。subscription id には依存しない。
     $signup = $organization->ticketLedgerEntries()
         ->where('idempotency_key', "signup_grant:org:{$organization->id}")
         ->firstOrFail();
@@ -152,6 +168,7 @@ function subscriptionPayload(string $type, string $status, string $eventId): arr
 
 test('subscription id が無くても org スコープキーで signup grant を付与する', function (): void {
     $organization = billingStripeCustomer();
+    enableStandardMonthlyGrant();
 
     // subscription id を含まない subscription_create の invoice.paid。
     // org スコープキー (signup_grant:org:{id}) は subscription id に依存しないため付与される。
@@ -169,6 +186,23 @@ function subscriptionPayload(string $type, string $status, string $eventId): arr
     )->toBe(1);
 });
 
+test('seed 既定 (D28: monthly_ticket_grant=0) では invoice.paid で月次付与行が作られない', function (): void {
+    $organization = billingStripeCustomer();
+    // arrange 無し = seed 既定 (全 tier 0)。grantMonthlyTickets の guard で付与が走らない。
+
+    $payload = invoicePaidPayload('evt_d28_no_monthly');
+    $payload['data']['object']['billing_reason'] = 'subscription_create';
+
+    event(new WebhookReceived($payload));
+
+    expect($organization->ticketLedgerEntries()
+        ->where('idempotency_key', 'like', 'monthly:%')->count())->toBe(0);
+    // signup grant のみが計上される (残高は config の付与枚数と一致)
+    expect(app(TicketLedgerService::class)->balance($organization))
+        ->toBe(config('billing.signup_grant_tickets'));
+    expect($organization->ticketLedgerEntries()->count())->toBe(1);
+});
+
 test('customer.subscription.updated で organizations.plan_code が同期される', function (): void {
     $organization = billingStripeCustomer();
     expect($organization->plan_code)->toBeNull();
@@ -206,6 +240,7 @@ function subscriptionPayload(string $type, string $status, string $eventId): arr
 
 test('billing_reason がサブスク以外の invoice.paid では付与しない', function (): void {
     $organization = billingStripeCustomer();
+    enableStandardMonthlyGrant();
     $payload = invoicePaidPayload('evt_manual_invoice');
     $payload['data']['object']['billing_reason'] = 'manual';
 
@@ -219,6 +254,7 @@ function subscriptionPayload(string $type, string $status, string $eventId): arr
 
 test('未知の customer のイベントは受理のみで何も変更しない', function (): void {
     $organization = billingStripeCustomer();
+    enableStandardMonthlyGrant();
     $payload = invoicePaidPayload('evt_unknown_customer');
     $payload['data']['object']['customer'] = 'cus_other';
 
@@ -253,6 +289,7 @@ function failedWebhookRecord(string $eventId, int $attempts): StripeWebhookEvent
 
 test('処理失敗時は failed + failure_reason を記録して再 throw する (Stripe 再送を促す)', function (): void {
     billingStripeCustomer();
+    enableStandardMonthlyGrant();
     $this->mock(TicketLedgerService::class)
         ->shouldReceive('grantMonthly')
         ->andThrow(new RuntimeException('付与処理の一時故障'));
@@ -268,6 +305,7 @@ function failedWebhookRecord(string $eventId, int $attempts): StripeWebhookEvent
 
 test('failed の再送で attempts が増え、成功すれば failure_reason が消える', function (): void {
     $organization = billingStripeCustomer();
+    enableStandardMonthlyGrant();
     failedWebhookRecord('evt_retry_ok', 2);
 
     // Stripe 再送: failed→received 復帰 (attempts+1) して再処理 → 成功
@@ -282,6 +320,7 @@ function failedWebhookRecord(string $eventId, int $attempts): StripeWebhookEvent
 
 test('attempts が上限到達済みの failed は terminal-ack (処理せず例外も投げない)', function (): void {
     $organization = billingStripeCustomer();
+    enableStandardMonthlyGrant();
     failedWebhookRecord('evt_terminal', StripeWebhookProcessor::MAX_PROCESSING_ATTEMPTS);
 
     // 再送されても claim が null を返し、処理も再 throw もしない (= Cashier が 200 を返す)
diff --git a/tests/Feature/Marketing/PricingPageTest.php b/tests/Feature/Marketing/PricingPageTest.php
index 174d679..a0552db 100644
--- a/tests/Feature/Marketing/PricingPageTest.php
+++ b/tests/Feature/Marketing/PricingPageTest.php
@@ -14,36 +14,45 @@
  * (価格改定コミットでこのテストの修正を不要にする)。
  */
 
-/** seed 済み standard プランの current base 額 */
-function seededStandardBaseAmount(): int
+/** seed 済みプランの current base 額 */
+function seededBaseAmount(string $code): int
 {
-    $price = Plan::query()->where('code', 'standard')->firstOrFail()
+    $price = Plan::query()->where('code', $code)->firstOrFail()
         ->currentPrice(PlanPriceKind::Base);
-    WebmozartAssert::notNull($price, 'standard プランの current base price が未 seed');
+    WebmozartAssert::notNull($price, "{$code} プランの current base price が未 seed");
 
     return $price->amount;
 }
 
-test('guest は plans (free/standard) と quota limits 反映の能力値を受け取る', function (): void {
-    $standardAmount = seededStandardBaseAmount();
+test('guest は plans (free/personal/starter/standard) と quota limits 反映の能力値を受け取る', function (): void {
+    $starterAmount = seededBaseAmount('starter');
+    $standardAmount = seededBaseAmount('standard');
 
     $this->get('/pricing')
         ->assertOk()
         ->assertInertia(fn (Assert $page) => $page
             ->component('Pricing')
-            ->has('page.plans', 2)
+            ->has('page.plans', 4) // sort_order 昇順 (free 0 / personal 1 / starter 2 / standard 3)
             ->where('page.plans.0.code', 'free')
             ->where('page.plans.0.baseAmountJpy', null)
-            ->where('page.plans.0.monthlyTicketGrant', 10)
             ->where('page.plans.0.maxProjects', 1)
             ->where('page.plans.0.maxMembers', 3)
             ->where('page.plans.0.maxStorageGb', 1) // GiB 切り捨て規則 (intdiv(bytes, 1024**3))
-            ->where('page.plans.1.code', 'standard')
-            ->where('page.plans.1.baseAmountJpy', $standardAmount)
-            ->where('page.plans.1.monthlyTicketGrant', 100)
-            ->where('page.plans.1.maxProjects', 10)
-            ->where('page.plans.1.maxMembers', 10)
-            ->where('page.plans.1.maxStorageGb', 50)
+            ->where('page.plans.1.code', 'personal')
+            ->where('page.plans.1.baseAmountJpy', null) // Price 無し = 無料表示契約
+            ->where('page.plans.1.maxProjects', 1)
+            ->where('page.plans.1.maxMembers', 3)
+            ->where('page.plans.1.maxStorageGb', 1)
+            ->where('page.plans.2.code', 'starter')
+            ->where('page.plans.2.baseAmountJpy', $starterAmount)
+            ->where('page.plans.2.maxProjects', 1)
+            ->where('page.plans.2.maxMembers', 3)
+            ->where('page.plans.2.maxStorageGb', 1)
+            ->where('page.plans.3.code', 'standard')
+            ->where('page.plans.3.baseAmountJpy', $standardAmount)
+            ->where('page.plans.3.maxProjects', 10)
+            ->where('page.plans.3.maxMembers', 10)
+            ->where('page.plans.3.maxStorageGb', 50)
             ->where('page.isAuthenticated', false));
 });
 
diff --git a/tests/js/pages/Pricing.test.ts b/tests/js/pages/Pricing.test.ts
index 336cf34..4be7613 100644
--- a/tests/js/pages/Pricing.test.ts
+++ b/tests/js/pages/Pricing.test.ts
@@ -14,7 +14,6 @@ const basePage: PricingPageProps = {
             code: "free",
             name: "Free",
             baseAmountJpy: null,
-            monthlyTicketGrant: 10,
             maxProjects: 1,
             maxMembers: 3,
             maxStorageGb: 1,
@@ -23,7 +22,6 @@ const basePage: PricingPageProps = {
             code: "standard",
             name: "Standard",
             baseAmountJpy: 4980,
-            monthlyTicketGrant: 100,
             maxProjects: 10,
             maxMembers: 10,
             maxStorageGb: 50,
@@ -59,7 +57,6 @@ describe("Pricing", () => {
         const standardCard = screen.getByTestId("pricing-plan-standard");
         expect(standardCard).toHaveTextContent("¥4,980");
         expect(standardCard).toHaveTextContent("基本料金");
-        expect(standardCard).toHaveTextContent("月 100 枚のチケット付与");
         expect(standardCard).toHaveTextContent("ストレージ 50 GB");
     });
 
```

## テスト結果

- composer test: **1824 tests / 1822 passed / 0 failed / 2 skipped**（7708 assertions）
- composer phpstan: **[OK] No errors**（level 10）
- vendor/bin/pint --test: passed / pnpm lint: passed / pnpm typecheck: passed
- pnpm test: **87 files / 790 tests passed**
- pnpm build: 成功
- 負のコントロール検証（MembershipWriteLockInventoryTest）: 書き込み API 注入 → **fail**（期待どおり検出）/ 除去 → pass / probe 残留なし
