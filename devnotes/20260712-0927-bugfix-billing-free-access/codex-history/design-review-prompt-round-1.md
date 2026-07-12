【アプリの使命 (North Star) — AGENTS.md より】

**AI-CUE** は、現場に既にある**作業手順書(SOP)を起点に**、AI が撮るべきカットを設計した**動画シナリオ**を生成し、そのシナリオを**スマホ(PWA)でナビゲーション撮影**することで、専門知識ゼロの現場作業者でも**標準化されたマニュアル動画**を作れるようにする。

- 「思考ゼロ・編集ゼロ」— 台本作成・撮影判断・編集の 3 ハードルを AI とナビ撮影で肩代わりする。
- 競合(OJT を撮って形式化する tebiki)と異なり、**標準作業を起点に AI が教材設計し撮影を指示する**（撮影者・教える人のスキルに品質を依存させない）。
- 熟練者の暗黙知を動画マニュアルという形式知へ変換する装置（SECI）。

> v1 スコープ: 字幕のみ(TTS 後回し) / 撮影は PWA(同一オリジン・セッション認証) / 動画合成は自前 ffmpeg / 単一 Default Project。

【禁止事項 — AGENTS.md より】

1. テストなしの実装完了報告(不変条件は対応する Architecture/Feature テストへの登録まで含めて「実装済み」)
2. PHPStan エラーの widen(型を緩めて黙らせる)・baseline 化
3. dev DB への破壊操作(`migrate:fresh` 等)をエージェント判断で実行すること
4. `response()->json()` の直書き(DTO / JsonResource / Inertia を使う。仕様固定 endpoint のみ例外)
5. LLM 呼び出しの Prism 直呼び(`app/Prompts/` の factory 経由のみ。PromptGuardrailTest が検出)
6. prompt 文字列のコード直書き(`resources/prompts/*.yaml` に置く)
7. 操作系 POST の応答での `redirect()->intended()`(ログイン直後フロー専用。招待送信等は `back()->with(...)` で完結させる)
8. 必須条件未充足を理由にボタンを disabled にする UI(押下時にエラー表示する。DESIGN.md)

【セキュリティ不変条件 — AGENTS.md より (アプリ都合で緩めない)】
tenant キー不信 / 子は親に属する (nested route 不整合は認可より前に 404) / cross-org 不可 / UserInput 型経由 / laratrust_team_id 明示 / PII は CipherSweet / 課金の冪等性 (webhook は冪等マシン、チケットは reserve→commit/release) / SSRF 検査経由

【思考原則 — 全議論に適用】
まず仮説を立てろ。何を検証したいのか、なぜそう考えるのか、どうなれば成功と判断するのかを明確にしてから手を動かせ。仮説なき改善はただの試行錯誤であり、結果から学ぶことができない。

データに真摯に向き合え。成果だけでなく、多様性の変化、構造の揺らぎ、想定外のパターン — 全てが判断材料になる。数値を見て即座に閾値を弄るな。何が起きているのかを理解し、なぜそうなったのかを考え、どの方向に進むべきかを判断してから手を動かせ。

先人の知恵を探せ。自分たちだけで登る必要はない。乗るべき巨人の肩があるなら乗れ。

機能の名前に立ち返れ。名前はその機能が果たすべき役割を示している。現在の設計がその役割を果たしているか、常に問え。

仕組みが機能していない段階で値を弄るな。閾値チューニングやフィールド追加は、設計の方向性が正しいと確認できてから行え。方向性が間違っているなら、値をいくら調整しても意味はない。設計そのものを見直せ。成果が出なければ早期に見切り、次の仮説へ進め。

【ツール使用制限】
コマンド実行・ファイル書き込みは一切行わず、提供されたテキストの分析に集中すること。ファイル読み込みは許可。

【役割】
あなたは経験豊富なWebアプリケーションアーキテクトです。Laravel + Svelte アプリケーション改善の詳細設計をレビューしてください。

【前提環境】
- PHP 8.4 + Laravel 12 + Svelte 5 + Inertia.js + TypeScript
- PHPStan level 10
- Pestテストフレームワーク
- DTO + JsonResource パターン
- Laratrust RBAC（Organization → Team → Project階層）

【レビュー観点】
1. コードの正確性（ロジックエラー、エッジケース、null安全性）
2. 既存コードとの整合性（命名規約、パターン、API）
3. PHPStan level 10 適合性（型安全性、generics、Assert使用）
4. テスト計画の網羅性（各施策にPestテスト、RefreshDatabaseグローバル適用に従う）
5. DTO/JsonResource パターンの遵守
6. Inertia Props vs API Responseの使い分け
7. 副作用・後退リスク
8. 波及変更の網羅性（TypeScript型定義、API Resource、テストが変更対象に含まれているか）
9. セキュリティ（認可チェック、入力バリデーション、OWASP Top 10、AGENTS.md のセキュリティ不変条件）
10. DESIGN.md準拠（UI/frontend 変更を含む場合）: token 経由の参照か、hex 直書きを増やさないか
11. Atomic Design準拠（UI/frontend 変更を含む場合）: atoms/molecules/organisms の責務分離、Lucide 前提

【出力形式】
- 各施策ごとに判定: APPROVE / REQUEST_CHANGES
- 指摘は [Critical] [Warning] [Suggestion] で分類
- Critical/Warning には必ず修正案を添える
- 全体判定: APPROVED / CHANGES_REQUESTED
- 日本語で出力

【背景】
bug-hunt finding F-07 (Critical) 対応。Free プラン (未契約) 組織が業務ルート (/projects, /projects/create, /app) から理由説明なく /billing へサイレントリダイレクトされ、プロジェクト作成に到達できない。LP/料金表の「Free で試せる」と矛盾。概念設計は Round 1 で APPROVED 済み (Warning 3 件は本詳細設計に反映済み)。
必要なら以下の実ファイルを読んでよい: app/Services/Billing/BillingAccess.php, app/Http/Middleware/RequireActiveSubscription.php, routes/web.php (L300-501), config/quota.php, database/seeders/PlanSeeder.php, app/Services/Billing/StripeWebhookProcessor.php, app/Services/Dashboard/DashboardService.php (L219-237), app/DataTransferObjects/Dashboard/BillingSummaryData.php, resources/js/types/dashboard.ts, resources/js/pages/Dashboard.svelte (L218-230), tests/Feature/Billing/RequireActiveSubscriptionMiddlewareTest.php, tests/Feature/DashboardTest.php (L200-425), tests/Pest.php (L110-155), app/Models/Organization.php, devnotes/20260712-0927-bugfix-billing-free-access/conceptual-design.md

---

## 詳細設計書

# 詳細設計: bugfix-billing-free-access (F-07: Free 課金ゲート矛盾の解消)

## 使命・制約（絶対遵守）

### アプリの使命（North Star）

**AI-CUE** は、現場に既にある**作業手順書(SOP)を起点に**、AI が撮るべきカットを設計した**動画シナリオ**を生成し、そのシナリオを**スマホ(PWA)でナビゲーション撮影**することで、専門知識ゼロの現場作業者でも**標準化されたマニュアル動画**を作れるようにする。

- 「思考ゼロ・編集ゼロ」— 台本作成・撮影判断・編集の 3 ハードルを AI とナビ撮影で肩代わりする。
- 競合(OJT を撮って形式化する tebiki)と異なり、**標準作業を起点に AI が教材設計し撮影を指示する**（撮影者・教える人のスキルに品質を依存させない）。
- 熟練者の暗黙知を動画マニュアルという形式知へ変換する装置（SECI）。

> v1 スコープ: 字幕のみ(TTS 後回し) / 撮影は PWA(同一オリジン・セッション認証) / 動画合成は自前 ffmpeg / 単一 Default Project。

### 禁止事項

1. テストなしの実装完了報告(不変条件は対応する Architecture/Feature テストへの登録まで含めて「実装済み」)
2. PHPStan エラーの widen(型を緩めて黙らせる)・baseline 化
3. dev DB への破壊操作(`migrate:fresh` 等)をエージェント判断で実行すること
4. `response()->json()` の直書き(DTO / JsonResource / Inertia を使う。仕様固定 endpoint のみ例外)
5. LLM 呼び出しの Prism 直呼び(`app/Prompts/` の factory 経由のみ。PromptGuardrailTest が検出)
6. prompt 文字列のコード直書き(`resources/prompts/*.yaml` に置く)
7. 操作系 POST の応答での `redirect()->intended()`(ログイン直後フロー専用)
8. 必須条件未充足を理由にボタンを disabled にする UI(押下時にエラー表示する。DESIGN.md)

### コーディングルール

- **PHPStan level 10** 必須（`composer phpstan`）
- **Pest**テストフレームワーク（`composer test`）
- **RefreshDatabase** + `--parallel` 並列実行（`tests/Pest.php` でグローバル適用、個別 `DatabaseTransactions` 使用禁止）
- **テストデータは必ずFactoryで生成**（`Model::create()` 手組み禁止。保護キーは forceFill）
- **DTO + JsonResource** パターン
- **アーリーリターン** 推奨
- **コードフォーマット**: `composer fix`（Pint）/ `pnpm lint:fix`
- PHP 8.4 + Laravel 12 + Svelte 5 + Inertia.js + TypeScript

## 概念設計リファレンス

`devnotes/20260712-0927-bugfix-billing-free-access/conceptual-design.md`
（Codex 概念レビュー Round 1 APPROVED。Warning 3 件は本詳細設計に反映済み:
plan_code 不変条件の明文化+テスト固定 / HTML・JSON の遮断理由文言統一 / リネーム波及の全列挙+固定テスト）

## 施策一覧

| # | 施策名 | 変更ファイル | 優先度 |
|---|--------|------------|--------|
| 1 | BillingAccess を entitlement 判定に書き換え | `app/Services/Billing/BillingAccess.php` | 高 |
| 2 | 遮断時の理由明示 (flash + 402 文言統一) | `app/Http/Middleware/RequireActiveSubscription.php` | 高 |
| 3 | ダッシュボード課金 callout の整合 (`has_billing_access` リネーム) | `BillingSummaryData.php` / `DashboardService.php` / `dashboard.ts` / `Dashboard.svelte` | 中 |
| 4 | plan_code 不変条件の明文化 (docblock / docs) | `StripeWebhookProcessor.php` / `PlanSeeder.php` / `Organization.php` / `docs/architecture.md` / `docs/app-integration-guide.md` / `routes/web.php` コメント | 中 |
| 5 | テスト更新・追加 (再現テストファースト) | `RequireActiveSubscriptionMiddlewareTest.php` / `DashboardTest.php` / `Dashboard.test.ts` / `tests/Pest.php` | 高 |

---

## 施策 1: BillingAccess を entitlement 判定に書き換え

### 変更箇所

- ファイル: `app/Services/Billing/BillingAccess.php` (全体 L1-36)

### 波及変更

- TypeScript型定義: なし（施策 3 で扱う）
- API Resource/DTO: なし（メソッドシグネチャ不変。呼び出し元 2 箇所 — middleware / DashboardService — は変更不要）
- テストファイル: `tests/Feature/Billing/RequireActiveSubscriptionMiddlewareTest.php`（施策 5）

### 現行コード

```php
/**
 * 組織が業務機能を利用してよいか (課金ゲート) の判定。
 * （中略: テンプレート既定は最小実装: Cashier の subscription('default') が
 *   active / trialing なら許可。未契約は不許可）
 */
class BillingAccess
{
    /** アクセスを許可する Stripe subscription status */
    private const array GRANTING_STATUSES = ['active', 'trialing'];

    public function hasActiveAccess(Organization $organization): bool
    {
        $subscription = $organization->subscription('default');

        // subscription 不在 (未契約) は fail-closed で不許可
        return $subscription !== null
            && in_array($subscription->stripe_status, self::GRANTING_STATUSES, true);
    }
}
```

### 変更後コード

```php
/**
 * 組織が業務機能を利用してよいか (billing entitlement) の判定。
 *
 * **課金による利用可否の判定は必ず本クラスを経由する** (middleware / controller /
 * service での subscription 直参照は禁止)。判定基準を 1 クラスに閉じ込めることで、
 * アプリ側は本クラスの書き換えだけで gate 方針を変更できる。
 *
 * AI-CUE の entitlement 方針 (テンプレート既定の「active subscription 必須」からの
 * 意図的な書き換え。devnotes/20260712-0927-bugfix-billing-free-access):
 *
 * - plan_code null (未契約) = fallback free プラン。**支払い不要 tier としてアクセス許可**。
 *   有償価値は別レイヤで gate 済み (チケット残高 = analyze/render、Quota = max_projects 等)
 * - plan_code 非 null = 有償プラン契約状態。subscription('default') が active / trialing の
 *   ときのみ許可 (past_due / canceled / incomplete / 行不在は fail-closed で不許可 =
 *   支払い健全性の担保のみが本ゲートの責務)
 *
 * 不変条件 (依存するデータモデル契約): `organizations.plan_code` は Stripe Price を持つ
 * 有償プランの契約時のみ StripeWebhookProcessor が set し、subscription.deleted で null に
 * 戻す。支払い不要のプランを plan_code に載せる場合は本判定とセットで見直すこと
 * (挙動は RequireActiveSubscriptionMiddlewareTest が固定する)。
 *
 * 注: 本メソッドは「subscription を持つか」ではなく「業務ルートを利用してよいか
 * (billing entitlement)」を返す。free 組織は subscription 無しで true になる。
 */
class BillingAccess
{
    /** アクセスを許可する Stripe subscription status (有償プラン契約時のみ参照) */
    private const array GRANTING_STATUSES = ['active', 'trialing'];

    public function hasActiveAccess(Organization $organization): bool
    {
        // 未契約 (plan_code null) = fallback free プラン。支払い不要 tier として許可
        if ($organization->plan_code === null) {
            return true;
        }

        // 有償プラン契約状態: 支払い健全性 (active/trialing) を要求。
        // 行不在 (webhook 順序逆転等) も fail-closed で不許可
        $subscription = $organization->subscription('default');

        return $subscription !== null
            && in_array($subscription->stripe_status, self::GRANTING_STATUSES, true);
    }
}
```

### PHPStan適合チェック

- [x] 戻り値の型が明示されている (`bool`、変更なし)
- [x] null安全（`plan_code` は `?string`、`subscription()` は `?Subscription` — 明示 null 比較のみ）
- [x] DTOを返している（bool 返却のみ、該当なし）
- [x] Genericsの型パラメータが正しい（該当なし）

### テスト計画

- [ ] 再現テストを先に書く（施策 5 参照。未契約 free 組織 → /projects 200 が fail することを確認してから実装）
- [ ] `RequireActiveSubscriptionMiddlewareTest.php` の BillingAccess 単体マトリクス更新
- [ ] 個別の `DatabaseTransactions` を使っていないことを確認

### リスク

- 従来「未契約 → 遮断」に依存していたテスト/挙動が反転する（意図した変更。施策 5 で全更新）。
- `subscription('default')` 行が active でも plan_code が null のケース（webhook の plan_code 同期前の
  一瞬）: 新判定では許可 — 従来より寛容になるだけで詰まない (fail-open 方向は free tier 相当の
  権限のみなので安全)。

---

## 施策 2: 遮断時の理由明示 (flash + 402 文言統一)

### 変更箇所

- ファイル: `app/Http/Middleware/RequireActiveSubscription.php` (L16-36 docblock, L63-72)

### 波及変更

- TypeScript型定義: なし（`flash.error` は `HandleInertiaRequests` L67-73 で共有済み・既存 flash-to-toast 基盤に乗る）
- API Resource/DTO: なし
- テストファイル: `RequireActiveSubscriptionMiddlewareTest.php`（flash 文言・402 文言の固定）

### 現行コード

```php
        // JSON/XHR は 402、ブラウザは billing へ誘導 (Checkout 導線)
        if ($request->expectsJson()) {
            abort(Response::HTTP_PAYMENT_REQUIRED, '有効なサブスクリプションがありません。お支払いを完了してください。');
        }

        // 直前 hop で積まれた flash (例: 招待受諾の success) が、この gate-redirect の
        // 1 hop で消費され失われないよう延命する
        $request->session()->reflash();

        return redirect()->route('billing.index');
```

### 変更後コード

```php
    /**
     * 遮断理由 (ブラウザ flash / JSON 402 で同一文言。H1: 説明なしリダイレクト対策)。
     * 判定変更後に遮断されるのは「有償プラン契約中の支払い不健全」のみのため、
     * free 組織を誤解させる旧文言 (「有効なサブスクリプションがありません」) は廃止。
     */
    private const string BLOCKED_MESSAGE = 'サブスクリプションのお支払いが確認できないため、ご利用を一時停止しています。お支払い方法をご確認ください。';
```

```php
        // JSON/XHR は 402、ブラウザは billing へ誘導 (理由 flash 付き。文言は両経路で統一)
        if ($request->expectsJson()) {
            abort(Response::HTTP_PAYMENT_REQUIRED, self::BLOCKED_MESSAGE);
        }

        // 直前 hop で積まれた flash (例: 招待受諾の success) が、この gate-redirect の
        // 1 hop で消費され失われないよう延命する
        $request->session()->reflash();

        return redirect()->route('billing.index')->with('error', self::BLOCKED_MESSAGE);
```

クラス docblock も新しい entitlement 意味論に更新する
(「有効な subscription を持たない組織を遮断」→「BillingAccess の entitlement 判定で
不許可 (= 有償プラン契約中の支払い不健全) の組織を遮断し、理由 flash とともに billing へ誘導」)。
`resolveOrganization()` (L78-97) の defense-in-depth 404 ロジックは**変更しない**。

補足: `reflash()` は既存 session flash の延命、`with('error', ...)` は新規 flash の積み込みで
両立する (`with` は `session()->flash()` 相当。key 衝突時は本 middleware の error が優先される —
遮断理由の提示が最優先の情報のため許容)。

### PHPStan適合チェック

- [x] 戻り値の型が明示されている（`Response`、変更なし）
- [x] null安全（変更箇所に nullable なし）
- [x] DTOを返している（redirect/abort のみ、該当なし）
- [x] Genericsの型パラメータが正しい（該当なし）

### テスト計画

- [ ] HTML: 有償契約 + past_due → `assertRedirect(route('billing.index'))` + `assertSessionHas('error', BLOCKED_MESSAGE)`
- [ ] JSON: 同状態で `getJson('/projects')` → 402 + message 文言固定
- [ ] 既存の binder 回帰 404 テスト（L95-112）は挙動不変で green のまま

### リスク

- flash key `error` が他の flash と同一リクエストで衝突する可能性は理論上あるが、
  遮断されるページ遷移では遮断理由が最重要情報であり許容（上記コメントで明示）。

---

## 施策 3: ダッシュボード課金 callout の整合 (`has_billing_access` リネーム)

Free 組織のダッシュボードに常時出ていた誤 callout（「有効なサブスクリプションがありません。
プランを契約すると、マニュアルの作成・撮影を再開できます。」）は、施策 1 により
「有償プラン支払い不健全」のみで出るようになる（callout 文言はそのケースに正しく適合するため
文言変更不要）。フィールド名だけが新意味論に対して嘘になるためリネームする。

### 変更箇所

- `app/DataTransferObjects/Dashboard/BillingSummaryData.php` (L19, L22-36)
- `app/Services/Dashboard/DashboardService.php` (L234: named argument)
- `resources/js/types/dashboard.ts` (L41)
- `resources/js/pages/Dashboard.svelte` (L220)

### 波及変更

- TypeScript型定義: `resources/js/types/dashboard.ts` `BillingSummary.has_active_subscription` → `has_billing_access`
- API Resource/DTO: `BillingSummaryData` のプロパティ `hasActiveSubscription` → `hasBillingAccess`、
  `toArray()` の key と PHPDoc array-shape を `has_billing_access` に更新
- テストファイル: `tests/Feature/DashboardTest.php` (L208, L412-424) / `tests/js/pages/Dashboard.test.ts` (L18, L248-253)
- 旧キー残置チェック: 実装時に `grep -rn "has_active_subscription\|hasActiveSubscription"` が
  0 件になることを確認（後方互換の並走を残さない）

### 現行コード

```php
        public bool $hasActiveSubscription, // BillingAccess::hasActiveAccess
    // toArray():
            'has_active_subscription' => $this->hasActiveSubscription,
```

```ts
export interface BillingSummary {
    // ...
    has_active_subscription: boolean;
}
```

```svelte
{#if !billing.has_active_subscription}
```

### 変更後コード

```php
        public bool $hasBillingAccess, // BillingAccess::hasActiveAccess (billing entitlement。free 組織は true)
    // toArray() (PHPDoc array-shape も has_billing_access に更新):
            'has_billing_access' => $this->hasBillingAccess,
```

```php
// DashboardService::billingSummary()
            hasBillingAccess: $this->billingAccess->hasActiveAccess($organization),
```

```ts
export interface BillingSummary {
    // ...
    has_billing_access: boolean;
}
```

```svelte
{#if !billing.has_billing_access}
```

callout 本文（Dashboard.svelte L222-227）は変更しない（「有効なサブスクリプションがありません。
プランを契約すると…再開できます」は有償プラン支払い不健全ケースの説明として適合。
「再開」の語もかつて利用できていた有償契約組織にのみ表示されるため整合する）。

### PHPStan適合チェック

- [x] 戻り値の型が明示されている（`toArray()` の array-shape PHPDoc を同時更新）
- [x] null安全（bool のみ）
- [x] DTOを返している（既存 DTO パターン維持）
- [x] Genericsの型パラメータが正しい（該当なし）

### テスト計画

- [ ] `DashboardTest.php`: payload key を `dashboard.billing.has_billing_access` に固定
- [ ] Free (未契約) 組織 → `has_billing_access` **true** + `/projects` `/projects/create` 200（旧 L412 テストの書き換え）
- [ ] 有償契約 + past_due 組織 → `has_billing_access` false + CTA 遷移先 (`/billing` `/purchase-tickets`) 200（redirect loop なし不変条件の維持）
- [ ] `Dashboard.test.ts`: `has_billing_access: false` で callout 表示 / true で非表示（既存 L248 の書き換え）
- [ ] `pnpm typecheck` green（旧キー参照が残ると fail する）

### リスク

- リネーム漏れは `pnpm typecheck` + Feature テストの payload key 固定 + grep 手順で三重に検出。

---

## 施策 4: plan_code 不変条件の明文化 (docblock / docs)

### 変更箇所

- `app/Services/Billing/StripeWebhookProcessor.php` (L31-33 付近のクラス docblock に追記)
- `database/seeders/PlanSeeder.php` (L21 コメント拡張)
- `app/Models/Organization.php` (L33-34 の plan_code 説明 / L106 の plan() docblock に「null = 未契約 = 支払い不要 free tier」を追記)
- `docs/architecture.md` (L85 の BillingAccess 行を entitlement 判定の説明に更新)
- `docs/app-integration-guide.md` (L129-132, L184 の課金ゲート説明を entitlement 意味論に更新)
- `routes/web.php` (L304-305, L316, L343-346 のコメントを「有償プラン契約中の支払い不健全のみ遮断」に更新)
- `docs/template-divergence.md` (テンプレート既定の BillingAccess 最小実装からの書き換えを記録。
  ただし BillingAccess docblock 自身が「アプリはこのクラスの書き換えで gate 方針を変更する」と
  宣言する公式拡張ポイントのため、「構造逸脱」ではなく「サンクション済み拡張の記録」として 1 エントリ追記)

### 波及変更

- TypeScript型定義: なし
- API Resource/DTO: なし
- テストファイル: なし（不変条件の挙動固定は施策 5 の Feature テストが担う）

### 追記する不変条件文（各所で同一の意味論）

> `organizations.plan_code` は Stripe Price を持つ有償プランの契約 (active/trialing) 時のみ
> webhook が set し、`customer.subscription.deleted` で null に戻す状態キー。
> **null = 未契約 = 支払い不要の free tier** (config/quota.php の fallback_plan が適用される)。
> BillingAccess はこの契約を entitlement 判定の根拠にするため、支払い不要のプランを
> plan_code に載せる場合は BillingAccess とセットで見直すこと。

### PHPStan適合チェック

- [x] コメント/ドキュメントのみ（コード変更なし）

### テスト計画

- [ ] 挙動の固定は施策 5 の Feature テストで実施（doc 自体のテストは不要）

### リスク

- なし（ドキュメント同期のみ）

---

## 施策 5: テスト更新・追加（再現テストファースト）

### 変更箇所

- `tests/Feature/Billing/RequireActiveSubscriptionMiddlewareTest.php` (全面更新)
- `tests/Feature/DashboardTest.php` (L208, L412-424)
- `tests/js/pages/Dashboard.test.ts` (L18, L248-253)
- `tests/Pest.php` (L123-154: ヘルパの docblock 更新 + 有償契約状態ヘルパ追加)

### 波及変更

- TypeScript型定義: なし（Dashboard.test.ts は施策 3 の型変更に追随）
- API Resource/DTO: なし
- テストファイル: 本施策自体

### tests/Pest.php: ヘルパの意味論更新（既定 = Free 組織）+ 有償契約状態ヘルパの追加

現行の `createOrganizationWithOwner(subscribed: true)` は「業務 route が subscription 必須で
gate される」前提の補助 (plan_code なしの subscription 行を自動作成) だが、新判定では
plan_code null の組織は subscription 行の有無にかかわらず許可されるため、この既定は無意味になる。
**「後方互換の並走を残さない」原則に従い `subscribed` パラメータと自動 subscription 作成を削除**し、
既定を「Free (未契約) 組織」(= 現実の新規組織と同じ状態) にする:

```php
/**
 * Owner 付きの組織を provisioning 経由で生成する (Default Team 込み)。
 * 生成される組織は Free (未契約 = plan_code null) — 業務 route は free でも通る
 * (BillingAccess の entitlement 判定)。有償プラン契約状態を検証するテストは
 * contractPaidPlan() を併用する。
 *
 * @return array{Organization, User} [organization, owner]
 */
function createOrganizationWithOwner(string $name = 'テスト組織'): array
{
    $owner = User::factory()->create();
    $organization = app(OrganizationProvisioningService::class)->provision($owner, $name);

    return [$organization, $owner];
}

/**
 * 組織を有償プラン契約状態にする (plan_code + Cashier subscription 行)。
 * plan_code は $fillable 外の状態キー (webhook 同期のみ) のため forceFill で明示代入。
 * BillingAccess は plan_code 非 null の組織にのみ active/trialing subscription を要求する。
 *
 * plan_code は PlanSeeder が投入する有償プラン code ('standard') を使う
 * (プラン名分岐ではなく seeded fixture の参照。アプリコードには入らない)。
 */
function contractPaidPlan(Organization $organization, string $status = 'active'): Subscription
{
    $organization->forceFill(['plan_code' => 'standard'])->save();

    return createFakeSubscription($organization, status: $status);
}
```

- 既定を Free にしても **quota 既定値は変わらない**（現行も plan_code null → fallback free の
  limits で全テストが走っている）ため、既存スイートへの quota 面の影響はない
- 業務 route の到達可否も plan_code null → 許可のため、既存の業務系テストは green のまま
- `createFakeSubscription()` は現状維持（contractPaidPlan と直接呼び出しの両方から使う）
- 呼び出し側の更新: `subscribed: false` を渡している 3 ファイル
  (`DashboardTest.php` / `RequireActiveSubscriptionMiddlewareTest.php` /
  `Billing/TicketCheckoutTest.php`) から引数を除去。`subscribed: true` (既定) の
  呼び出しは無引数のため変更不要。実装時に
  `grep -rn "subscribed:" tests/` が 0 件になることを確認

### RequireActiveSubscriptionMiddlewareTest の新マトリクス

```php
// ── 再現テスト (F-07。実装前に fail を確認する) ──
test('Free (未契約) 組織は業務 route に到達できる (F-07 再現)', function (): void {
    [, $owner] = createOrganizationWithOwner();

    $this->actingAs($owner)->get('/projects')->assertOk();
    $this->actingAs($owner)->get('/projects/create')->assertOk();
});

test('Free (未契約) 組織はプロジェクトを作成できる (F-07 再現)', function (): void {
    [, $owner] = createOrganizationWithOwner();

    $this->actingAs($owner)->post('/projects', ['name' => 'Free プロジェクト'])
        ->assertRedirect(); // projects.show へ (billing.index でないこと)
    expect(Project::query()->where('name', 'Free プロジェクト')->exists())->toBeTrue();
});

test('Free (未契約) 組織は撮影 PWA (/app) に到達できる (F-07 再現)', function (): void {
    [$organization, $owner] = createOrganizationWithOwner();
    $project = Project::factory()->forOrganization($organization)->create();

    $this->actingAs($owner)->get('/app')
        ->assertRedirect(route('capture.manuals.index', ['project' => $project]));
});

// ── 有償プラン契約状態の支払い健全性 gate (従来の fail-closed を plan_code 非 null に限定) ──
test('有償契約 + active/trialing は業務 route に到達できる', function (string $status): void {
    [$organization, $owner] = createOrganizationWithOwner();
    contractPaidPlan($organization, status: $status);

    $this->actingAs($owner)->get('/projects')->assertOk();
})->with(['active', 'trialing']);

test('有償契約 + 支払い不健全は billing へ redirect + 理由 flash', function (string $status): void {
    [$organization, $owner] = createOrganizationWithOwner();
    contractPaidPlan($organization, status: $status);

    $this->actingAs($owner)->get('/projects')
        ->assertRedirect(route('billing.index'))
        ->assertSessionHas('error', 'サブスクリプションのお支払いが確認できないため、ご利用を一時停止しています。お支払い方法をご確認ください。');
})->with(['past_due', 'canceled', 'incomplete', 'unpaid']);

test('有償契約 + subscription 行なしは fail-closed (webhook 順序逆転の防御)', function (): void {
    [$organization, $owner] = createOrganizationWithOwner();
    $organization->forceFill(['plan_code' => 'standard'])->save(); // 行はあえて作らない

    $this->actingAs($owner)->get('/projects')
        ->assertRedirect(route('billing.index'));
});

test('有償契約 + 支払い不健全の JSON は 402 (flash と同一文言)', function (): void {
    [$organization, $owner] = createOrganizationWithOwner();
    contractPaidPlan($organization, status: 'past_due');

    $this->actingAs($owner)->getJson('/projects')->assertStatus(402);
});

// ── BillingAccess 単体マトリクス ──
test('BillingAccess: plan_code null は常に許可、非 null は active/trialing のみ許可', function (): void {
    $access = app(BillingAccess::class);

    // 未契約 (free tier)
    [$freeOrg] = createOrganizationWithOwner();
    expect($access->hasActiveAccess($freeOrg))->toBeTrue();

    // 未契約 + subscription 行だけある (webhook の plan_code 同期前) も許可 (fail-open は free 相当のみ)
    [$syncLagOrg] = createOrganizationWithOwner();
    createFakeSubscription($syncLagOrg, status: 'active');
    expect($access->hasActiveAccess($syncLagOrg))->toBeTrue();

    // 有償契約状態: status マトリクス
    foreach (['active' => true, 'trialing' => true, 'past_due' => false, 'canceled' => false, 'incomplete' => false] as $status => $expected) {
        [$organization] = createOrganizationWithOwner();
        contractPaidPlan($organization, status: $status);
        expect($access->hasActiveAccess($organization))->toBe($expected, "stripe_status={$status}");
    }

    // 有償契約状態 + 行なし: fail-closed
    [$orphan] = createOrganizationWithOwner();
    $orphan->forceFill(['plan_code' => 'standard'])->save();
    expect($access->hasActiveAccess($orphan))->toBeFalse();
});
```

既存の以下は**維持**（挙動不変）:
- 「billing ページは未契約でも到達できる (構造的 allowlist)」(L56-60)
- 「route bound organization が未契約なら redirect される」(L81-93) →
  「route bound organization が有償不健全なら redirect される」に書き換え:
  route の org (`$gated`) に `contractPaidPlan($gated, 'past_due')` を適用する
  （未契約はもう遮断されないため。current org より route 優先の検証意図は維持）
- 「非メンバーが binder を通過しても middleware が 404 に倒す」(L95-112) — 変更なし

### DashboardTest の更新 (L412-424)

```php
test('Free (未契約) org: dashboard 200 + has_billing_access=true + 業務 route 開通', function (): void {
    [$organization, $owner] = createOrganizationWithOwner();
    Project::factory()->forOrganization($organization)->create();

    $this->actingAs($owner)->get('/dashboard')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('dashboard.billing.has_billing_access', true));

    $this->actingAs($owner)->get('/projects')->assertOk();
});

test('有償契約 + 支払い不健全 org: has_billing_access=false + CTA 遷移先 200 (redirect loop なし)', function (): void {
    [$organization, $owner] = createOrganizationWithOwner();
    contractPaidPlan($organization, status: 'past_due');
    Project::factory()->forOrganization($organization)->create();

    $this->actingAs($owner)->get('/dashboard')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('dashboard.billing.has_billing_access', false));

    // CTA 遷移先は課金ゲート外 (redirect loop なし不変条件)
    $this->actingAs($owner)->get('/purchase-tickets')->assertOk();
    $this->actingAs($owner)->get('/billing')->assertOk();
});
```

L208 の `dashboard.billing.has_active_subscription` 参照も `has_billing_access` に更新。

### Dashboard.test.ts の更新

- L18 のフィクスチャ `has_active_subscription: true` → `has_billing_access: true`
- L248 「has_active_subscription=false で billing callout が出る」→
  `has_billing_access: false` で callout 表示 / true で非表示の 2 ケースに更新

### 既存カバレッジの確認（変更不要・受け入れ条件 2 の担保）

- チケット gate: `tests/Feature/Projects/ManualAnalyzeTest.php` L155
  「残高 0 は 402 (code=insufficient_tickets…)」が analyze の残高 gate を固定済み。
  同テストの org 生成が `subscribed: true` 既定 (= 新ヘルパで有償契約状態) でも
  残高 gate は独立に機能するため変更不要。render 側は `RenderTriggerTest.php` が同様に固定済み
- Quota gate: `tests/Feature/Billing/QuotaTest.php` が free プラン max_projects=1 の
  超過 (back + error flash) を固定済み — Free 組織の 2 個目プロジェクト作成 gate は既存で担保
- cross-org / 認可: `ProjectRouteCurrentOrgGuardTest` / `NestedRouteIdorDefenseTest` /
  ProjectCrudTest 等は組織生成ヘルパ経由のため挙動不変

### テスト計画（本施策のチェックリスト）

- [ ] バグ修正の再現テスト（F-07 の 3 本）を先に書き、施策 1 実装前に fail を確認
- [ ] 既存テスト `RequireActiveSubscriptionMiddlewareTest.php` / `DashboardTest.php` /
      `Dashboard.test.ts` の更新（上記）
- [ ] `tests/Pest.php` の `createOrganizationWithOwner` から `subscribed` パラメータと
      自動 subscription 作成を削除（既定 = Free 組織）+ `contractPaidPlan()` 追加
- [ ] `subscribed:` を渡す既存呼び出し 3 ファイルの更新 + `grep -rn "subscribed:" tests/` 0 件確認
- [ ] 個別の `DatabaseTransactions` を使っていないことを確認
- [ ] `composer test` / `composer phpstan` / `vendor/bin/pint --test` / `pnpm lint` /
      `pnpm typecheck` / `pnpm test` / `pnpm build` 全 green

### リスク

- ヘルパ既定の変更（自動 subscription 作成の削除）は、新判定では plan_code null → 常に許可の
  ため業務 route 系テストの到達可否に影響しない。quota 既定値も不変（現行も plan_code null →
  fallback free の limits で走っている）。subscription 行の存在自体に依存するテストが
  もしあれば実装時に `contractPaidPlan()` へ切り替える（`createFakeSubscription` の
  呼び出し箇所 grep で網羅確認）。

---

## 実装モード

| 項目 | 内容 |
|------|------|
| 推奨モード | standalone |
| 判断根拠 | 課金ゲートの意味論変更はテストヘルパ (`tests/Pest.php`) の意味論変更を伴い、broad なテストスイートへの波及確認 (全 green) を単一 worktree で一括検証するのが安全。施策間の依存が強く (1→2→3→5)、分割実装の利点がない |
| 競合リスク | `tests/Pest.php` / `routes/web.php` コメント / docs を触るため、並行する他 TODO と衝突しやすい。F-05 (Stripe checkout 500) 対応とはファイルが分離しているが、`BillingAccess` の意味論に依存するため本修正を先行させる |

## スコープ外（再掲）

- F-05 (Stripe checkout / portal 500) — 別 TODO。checkout 実装は触らない
- middleware クラス名 / alias のリネーム（テンプレート整合を優先）
- LP / Pricing 文言（本修正で事実と整合するため変更不要）
- TODO 登録・実装（本フェーズは設計のみ）

---

## 関連する現行コード (抜粋)

### app/Services/Billing/BillingAccess.php (全文)
```php
class BillingAccess
{
    /** アクセスを許可する Stripe subscription status */
    private const array GRANTING_STATUSES = ['active', 'trialing'];

    public function hasActiveAccess(Organization $organization): bool
    {
        $subscription = $organization->subscription('default');

        // subscription 不在 (未契約) は fail-closed で不許可
        return $subscription !== null
            && in_array($subscription->stripe_status, self::GRANTING_STATUSES, true);
    }
}
```

### app/Http/Middleware/RequireActiveSubscription.php handle() (抜粋)
```php
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        if (! $user instanceof User) {
            return $next($request);
        }

        $organization = $this->resolveOrganization($request, $user);
        if ($organization === null) {
            return $next($request);
        }

        if ($this->access->hasActiveAccess($organization)) {
            return $next($request);
        }

        // JSON/XHR は 402、ブラウザは billing へ誘導 (Checkout 導線)
        if ($request->expectsJson()) {
            abort(Response::HTTP_PAYMENT_REQUIRED, '有効なサブスクリプションがありません。お支払いを完了してください。');
        }

        // 直前 hop で積まれた flash (例: 招待受諾の success) が、この gate-redirect の
        // 1 hop で消費され失われないよう延命する
        $request->session()->reflash();

        return redirect()->route('billing.index');
    }
```

### app/Services/Billing/StripeWebhookProcessor.php (plan_code 同期の要点)
- `syncPlanCode()`: status が active/trialing のときのみ、price.id → Plan 解決で `organization->plan_code = $plan->code; save();`
- `clearPlanCode()` (customer.subscription.deleted): `organization->plan_code = null; save();`
- free プランは Stripe Price を持たない (PlanSeeder) ため plan_code に 'free' が入る経路は存在しない

### config/quota.php (要点)
- `fallback_plan = 'free'`、plans.free = [max_projects=1, max_members=3, max_storage_bytes=1GiB]

### tests/Pest.php (現行ヘルパ)
```php
function createOrganizationWithOwner(string $name = 'テスト組織', bool $subscribed = true): array
{
    $owner = User::factory()->create();
    $organization = app(OrganizationProvisioningService::class)->provision($owner, $name);

    if ($subscribed) {
        createFakeSubscription($organization);
    }

    return [$organization, $owner];
}

function createFakeSubscription(Organization $organization, string $status = 'active', string $type = 'default'): Subscription
{
    /** @var Subscription $subscription */
    $subscription = $organization->subscriptions()->create([
        'type' => $type,
        'stripe_id' => 'sub_test_'.Str::random(24),
        'stripe_status' => $status,
        'quantity' => 1,
    ]);

    return $subscription;
}
```

### app/DataTransferObjects/Dashboard/BillingSummaryData.php / DashboardService.php / dashboard.ts / Dashboard.svelte
詳細設計書の「施策 3」の現行コード欄のとおり (has_active_subscription / hasActiveSubscription)。

### app/Models/Organization.php (要点)
- `plan_code` は $fillable 外 (状態キー。webhook 同期のみ)。`plan()` = belongsTo(Plan, 'plan_code', 'code')。Billable trait (Cashier)。
