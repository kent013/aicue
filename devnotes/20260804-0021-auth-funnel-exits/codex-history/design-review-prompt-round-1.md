# AI-CUE 詳細設計レビュー依頼 (auth-funnel-exits: bug-hunt F-2-01 / F-2-02)

## 使命 (North Star)

<!-- app-codex-review スキルはこのセクションをレビュー使命として参照する。 -->

**AI-CUE** は、現場に既にある**作業手順書(SOP)を起点に**、AI が撮るべきカットを設計した**動画シナリオ**を生成し、そのシナリオを**スマホ(PWA)でナビゲーション撮影**することで、専門知識ゼロの現場作業者でも**標準化されたマニュアル動画**を作れるようにする。

- 「思考ゼロ・編集ゼロ」— 台本作成・撮影判断・編集の 3 ハードルを AI とナビ撮影で肩代わりする。
- 競合(OJT を撮って形式化する tebiki)と異なり、**標準作業を起点に AI が教材設計し撮影を指示する**（撮影者・教える人のスキルに品質を依存させない）。
- 熟練者の暗黙知を動画マニュアルという形式知へ変換する装置（SECI）。

> 詳細な設計は本リポジトリの `doc/`（01〜10）に置く。ドメインをテンプレート構造へどうマップしたかは `doc/08`〜`doc/10`（特に `doc/10 §10.8` に設計レビュー反映済み）。
> **v1 スコープ**: 字幕のみ(TTS 後回し) / 撮影は PWA(同一オリジン・セッション認証) / 動画合成は自前 ffmpeg / 単一 Default Project。

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
9. Artifact の使用(Artifact ツールでの成果物公開を行わない。成果物はリポジトリ内のファイルとして出力する)

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

【思考原則 — 全議論に適用】
まず仮説を立てろ。何を検証したいのか、なぜそう考えるのか、どうなれば成功と判断するのかを明確にしてから手を動かせ。仮説なき改善はただの試行錯誤であり、結果から学ぶことができない。

データに真摯に向き合え。成果だけでなく、多様性の変化、構造の揺らぎ、想定外のパターン — 全てが判断材料になる。数値を見て即座に閾値を弄るな。何が起きているのかを理解し、なぜそうなったのかを考え、どの方向に進むべきかを判断してから手を動かせ。

先人の知恵を探せ。自分たちだけで登る必要はない。乗るべき巨人の肩があるなら乗れ。

機能の名前に立ち返れ。名前はその機能が果たすべき役割を示している。現在の設計がその役割を果たしているか、常に問え。

仕組みが機能していない段階で値を弄るな。閾値チューニングやフィールド追加は、設計の方向性が正しいと確認できてから行え。方向性が間違っているなら、値をいくら調整しても意味はない。設計そのものを見直せ。成果が出なければ早期に見切り、次の仮説へ進め。

【ツール使用制限】
コマンド実行・ファイル書き込みは一切行わず、提供されたテキストの分析に集中すること。ファイル読み込みは許可。

あなたは経験豊富なWebアプリケーションアーキテクトです。Laravel + Svelte アプリケーション改善の詳細設計をレビューしてください。

【前提環境】
- PHP 8.4 + Laravel 12 + Svelte 5 + Inertia.js + TypeScript
- PHPStan level 10
- Pestテストフレームワーク (RefreshDatabase はグローバル適用、--parallel 実行)
- DTO + JsonResource パターン
- Laratrust RBAC（Organization → Team → Project階層）
- 認証は Laravel Fortify (view は Inertia::render に差し替え)
- JS テストは vitest + @testing-library/svelte。`pnpm typecheck` は `tsc --noEmit` であり **.svelte のテンプレートは型検査されない** (= Svelte の必須 props は型では強制されない)

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
10. DESIGN.md準拠（UI/frontend 変更を含む）: `/DESIGN.md` が design token の canonical source。color / radius / typography を token 経由で参照する設計か、hex 直書きを増やさないか
11. Atomic Design準拠: `resources/js/components/` の atoms/molecules/organisms/templates の責務分離に沿った配置か。アイコンは Lucide 前提で SVG 直書きを新設していないか

【特に判断してほしい論点】
- (a) 「onboarding.checkout を verified ゲートの外に出す」のではなく「verify notice の CTA を消して説明に変える」という方針選択は妥当か (未検証メールでの無料チケット付与という abuse 面を根拠にしている)
- (b) architecture テスト (ソース走査) で AuthLayout ページの footer 導線を強制する設計は、正規表現の頑健性・allowlist 運用の観点で妥当か
- (c) テスト計画が「この bug が二度と起きない」ことを本当に固定しているか (不足している層はないか)

【出力形式】
- 各施策ごとに判定: APPROVE / REQUEST_CHANGES
- 指摘は [Critical] [Warning] [Suggestion] で分類
- Critical/Warning には必ず修正案を添える
- 全体判定: APPROVED / CHANGES_REQUESTED
- 日本語で出力

---

## 詳細設計書

# 詳細設計: 認証ファネルの離脱導線 (auth-funnel-exits)

対象 finding: **F-2-01 (High)** / **F-2-02 (High)** — bug-hunt run `20260803-203721`

## 使命・制約（絶対遵守）

### アプリの使命（North Star）

**AI-CUE** は、現場に既にある**作業手順書(SOP)を起点に**、AI が撮るべきカットを設計した**動画シナリオ**を生成し、そのシナリオを**スマホ(PWA)でナビゲーション撮影**することで、専門知識ゼロの現場作業者でも**標準化されたマニュアル動画**を作れるようにする。

- 「思考ゼロ・編集ゼロ」— 台本作成・撮影判断・編集の 3 ハードルを AI とナビ撮影で肩代わりする。
- 競合(OJT を撮って形式化する tebiki)と異なり、**標準作業を起点に AI が教材設計し撮影を指示する**（撮影者・教える人のスキルに品質を依存させない）。
- 熟練者の暗黙知を動画マニュアルという形式知へ変換する装置（SECI）。

> **v1 スコープ**: 字幕のみ(TTS 後回し) / 撮影は PWA(同一オリジン・セッション認証) / 動画合成は自前 ffmpeg / 単一 Default Project。

### 禁止事項

1. テストなしの実装完了報告(不変条件は対応する Architecture/Feature テストへの登録まで含めて「実装済み」)
2. PHPStan エラーの widen(型を緩めて黙らせる)・baseline 化
3. dev DB への破壊操作(`migrate:fresh` 等)をエージェント判断で実行すること
4. `response()->json()` の直書き(DTO / JsonResource / Inertia を使う。仕様固定 endpoint のみ例外)
5. LLM 呼び出しの Prism 直呼び(`app/Prompts/` の factory 経由のみ。PromptGuardrailTest が検出)
6. prompt 文字列のコード直書き(`resources/prompts/*.yaml` に置く)
7. 操作系 POST の応答での `redirect()->intended()`(ログイン直後フロー専用。招待送信等は `back()->with(...)` で完結させる)
8. 必須条件未充足を理由にボタンを disabled にする UI(押下時にエラー表示する。DESIGN.md)
9. Artifact の使用(Artifact ツールでの成果物公開を行わない。成果物はリポジトリ内のファイルとして出力する)

### コーディングルール

- **PHPStan level 10** 必須（`composer phpstan`）
- **Pest**テストフレームワーク（`composer test`）
- **RefreshDatabase** + `--parallel` 並列実行（`tests/Pest.php` でグローバル適用、個別 `DatabaseTransactions` 使用禁止）
- **テストデータは必ずFactoryで生成**（`Model::create()` 手組み禁止。組織は `createOrganizationWithOwner()` ヘルパ）
- **DTO + JsonResource** パターン（本設計は DTO 新設なし。Inertia props のみ）
- フロントは Svelte 5 runes + DS token/atom のみ。リンクは `TextLink` atom 経由（素の `<a>` を書かない。DESIGN.md）
- component 階層は単方向 import（`atoms → molecules → organisms → features → templates → pages`）
- **アーリーリターン** 推奨 / **コードフォーマット**: `composer fix`（Pint）/ `pnpm lint:fix`
- PHP 8.4 + Laravel 12 + Svelte 5 + Inertia.js + TypeScript

## 概念設計リファレンス

- [conceptual-design.md](./conceptual-design.md) （Codex `gpt-5.4` Round 1 = **APPROVED**）
- レビュー: [conceptual-review-round-1.md](./conceptual-review-round-1.md) /
  対応: [codex-history/conceptual-review-decisions-round-1.md](./codex-history/conceptual-review-decisions-round-1.md)

## 施策一覧

| # | 施策名 | 変更ファイル | 優先度 |
|---|--------|------------|--------|
| A | verify notice の踏破不能 CTA を撤去し、認証後の着地を説明に変える (F-2-01) | `app/Support/Auth/EmailVerificationContinuation.php`, `app/Providers/FortifyServiceProvider.php`, `resources/js/pages/Auth/VerifyEmail.svelte`, `tests/Feature/Auth/RegisterVerifyFlowTest.php`, `tests/Feature/Onboarding/OnboardingCheckoutEmailVerificationGuardTest.php`(新規), `tests/Unit/Support/Auth/EmailVerificationContinuationTest.php`, `tests/js/pages/VerifyEmail.test.ts`, `docs/architecture.md` | High |
| B | AuthLayout ページの離脱導線を規約化し architecture テストで強制 (F-2-02 + 同種欠落 2 件) | `resources/js/pages/Auth/ResetPassword.svelte`, `resources/js/pages/Auth/TwoFactorChallenge.svelte`, `resources/js/pages/Auth/ConfirmRecentAuth.svelte`, `tests/js/architecture/page-shell-structure.test.ts`, `tests/js/pages/ResetPassword.test.ts`(新規), `tests/js/pages/TwoFactorChallenge.test.ts`(新規), `tests/js/pages/ConfirmRecentAuth.test.ts`(新規), `DESIGN.md` | High |

施策 A / B は**独立した受け入れ条件**を持つ（Codex R1 Warning 6）。同一 worktree で実装してよいが、
片方だけでは DoD を満たさない。

---

## 施策 A: verify notice の踏破不能 CTA を撤去する

### 変更箇所

- `app/Support/Auth/EmailVerificationContinuation.php` (L35-52 の直後に `hasContinuation()` を追加)
- `app/Providers/FortifyServiceProvider.php` (L230-241 `verifyEmailView`)
- `resources/js/pages/Auth/VerifyEmail.svelte` (L6-15 Props / L49-64 CTA)

### 波及変更

- **TypeScript 型定義**: `resources/js/types/` への追加なし（`VerifyEmail.svelte` の Props は
  ページ内 inline interface。`resources/js/types/billing.ts` の `continueUrl` は
  `OnboardingReturnResolver` 由来の**別物**で無関係 = 触らない）
- **API Resource / DTO**: なし（Inertia props の直渡し。DTO 新設は不要）
- **route / middleware / DB**: **変更なし**（`onboarding.checkout` は `['auth','verified']` 配下のまま = 意図した配置）
- **テストファイル**: `tests/Feature/Auth/RegisterVerifyFlowTest.php`(更新) /
  `tests/Unit/Support/Auth/EmailVerificationContinuationTest.php`(追記) /
  `tests/js/pages/VerifyEmail.test.ts`(更新) / `tests/Feature/Onboarding/OnboardingCheckoutEmailVerificationGuardTest.php`(新規)
- **ドキュメント**: `docs/architecture.md` §サブスク契約 Checkout とオンボーディング着地 (P7/P9)

### 現行コード

```php
// app/Providers/FortifyServiceProvider.php:230-241
Fortify::verifyEmailView(static function (Request $request): InertiaResponse {
    $user = $request->user();

    // 登録由来の継続導線 (「あとで認証する」)。session には組織 id のみ保持し、
    // membership 確認を通ったときだけ URL 化する (IDOR 防御)。
    return Inertia::render('Auth/VerifyEmail', [
        'continueUrl' => EmailVerificationContinuation::resolveUrl(
            $user instanceof User ? $user : null,
            $request->session(),
        ),
    ]);
});
```

```svelte
<!-- resources/js/pages/Auth/VerifyEmail.svelte:6-15, 49-64 (抜粋) -->
interface Props {
    appName?: string;
    /**
     * 登録由来の継続導線 (プラン選択へ進む)。サーバが membership 確認を通ったときだけ
     * 非 null で届く。null のときは二次 CTA を出さない。
     */
    continueUrl?: string | null;
}

let { appName, continueUrl = null }: Props = $props();
...
<form onsubmit={resend} class="flex flex-col gap-3">
    <Button type="submit" loading={form.processing} fullWidth>認証メールを再送信</Button>
    {#if continueUrl !== null}
        <Button
            variant="ghost"
            onclick={() => router.visit(continueUrl)}
            fullWidth
            testId="verify-email-continue"
        >
            あとで認証する（プラン選択へ進む）
        </Button>
    {/if}
    <Button variant="ghost" onclick={logout} loading={loggingOut} fullWidth>
        ログアウト
    </Button>
</form>
```

### 変更後コード

```php
// app/Support/Auth/EmailVerificationContinuation.php — resolveUrl() の直後に追加
/**
 * 継続導線が実在するか (URL を露出せず有無だけを返す)。
 * membership 確認の単一出典を保つため resolveUrl() へ委譲する。
 * 「どの画面へ進むか」という UI 語彙はここに持ち込まない (呼び出し側が写像する)。
 */
public static function hasContinuation(?User $user, Session $session): bool
{
    return self::resolveUrl($user, $session) !== null;
}
```

```php
// app/Providers/FortifyServiceProvider.php:230-241 の置き換え
Fortify::verifyEmailView(static function (Request $request): InertiaResponse {
    $user = $request->user();

    // 認証前に onboarding.checkout へ進ませる CTA は出さない (route は ['auth','verified']
    // 配下 = 未認証は必ず差し戻される)。継続が実在するときだけ「認証後にプラン選択へ進む」
    // ことを予告する説明を出す (bug-hunt F-2-01)。
    return Inertia::render('Auth/VerifyEmail', [
        'continuesToCheckout' => EmailVerificationContinuation::hasContinuation(
            $user instanceof User ? $user : null,
            $request->session(),
        ),
    ]);
});
```

```svelte
<!-- resources/js/pages/Auth/VerifyEmail.svelte -->
interface Props {
    appName?: string;
    /**
     * 登録由来の継続 (認証完了後に onboarding.checkout へ着地する) が実在するか。
     * true のときだけ「認証後にプラン選択へ進む」ことを予告する。
     * 認証前に checkout へ遷移する CTA は出さない (verified ゲートで必ず弾かれるため)。
     */
    continuesToCheckout: boolean;
}

let { appName, continuesToCheckout }: Props = $props();
...
<p class="mb-6 text-body text-text-secondary">
    ご登録いただいたメールアドレスに認証メールを送信しました。
    メール内のリンクをクリックして認証を完了してください。
    メールが届かない場合は、再送信できます。
</p>

{#if continuesToCheckout}
    <p class="mb-6 text-body text-text-secondary" data-testid="verify-email-checkout-note">
        メール認証が完了すると、そのままプラン選択に進みます。
    </p>
{/if}

<form onsubmit={resend} class="flex flex-col gap-3">
    <Button type="submit" loading={form.processing} fullWidth>認証メールを再送信</Button>
    <Button variant="ghost" onclick={logout} loading={loggingOut} fullWidth>
        ログアウト
    </Button>
</form>
```

- `router` の import は `logout()` が使い続けるため残す（`router.visit` の呼び出しだけが消える）。
- `data-testid="verify-email-continue"` は**完全に消える**（旧 CTA の痕跡を残さない = 思考原則 3）。

### PHPStan適合チェック

- [ ] `hasContinuation()` の戻り値型 `bool` を明示（`?User` / `Session` の引数型は `resolveUrl` と同一）
- [ ] `resolveUrl()` へ委譲するため null 安全は既存実装のまま（`is_int()` guard / membership fetch）
- [ ] Inertia props は配列直渡し（本経路に DTO は存在せず、`response()->json()` も使わない = 禁止事項 #4 非該当）
- [ ] Generics: `$user->organizations()` の `BelongsToMany<Organization, User>` は既存コードのまま触らない

### テスト計画（テストファースト: 1 → 2 → 3 → 4 の順に fail を確認してから実装）

1. **[新規・再現テスト] `tests/Feature/Onboarding/OnboardingCheckoutEmailVerificationGuardTest.php`**
   — *この bug の根本原因を「仕様」として固定する*。
   - `test('メール未認証ユーザーの GET /onboarding/checkout は verification.notice へ差し戻される')`:
     `createOrganizationWithOwner(grandfatherFreePlan: false)` → owner を
     `forceFill(['email_verified_at' => null])->save()`（既存 `EmailVerificationGateTest.php:106` と同作法）
     → `actingAs($owner)->get('/onboarding/checkout')` が `assertRedirect(route('verification.notice'))`。
   - `test('認証済み owner は GET /onboarding/checkout に到達できる')`（ゲートを締めすぎていないことの対照）。
   - 意図: 「未認証で checkout へ行けないこと」は**仕様**であり、UI 側で CTA を出さない根拠である。
2. **[更新] `tests/Feature/Auth/RegisterVerifyFlowTest.php`**
   - L50-60 「登録後の /email/verify GET は continueUrl に onboarding.checkout を返す」→
     **「登録後の /email/verify GET は continuesToCheckout=true を返し、continueUrl prop を持たない」**
     （`->where('continuesToCheckout', true)` + `->missing('continueUrl')`）。
   - L62-71 / L73-82 / L84-92（継続なし / 他組織 id / 非 int）→ いずれも
     `->where('continuesToCheckout', false)` + `->missing('continueUrl')` に置換
     （membership 確認 = IDOR 防御の期待は**維持**する。値の形が変わるだけ）。
   - L94-115（verify 完了 → `onboarding.checkout` 着地 / 継続なしは `fortify.home?verified=1`）は**変更しない**
     = 認証後の着地契約を後退させないことの回帰。
3. **[追記] `tests/Unit/Support/Auth/EmailVerificationContinuationTest.php`**
   - `hasContinuation()` が `resolveUrl()` と**同じ条件**で true/false になること
     （remember 後 true / 他組織 id false / 非 int false / null user false / forget 後 false）。
4. **[更新] `tests/js/pages/VerifyEmail.test.ts`**
   - 「`continuesToCheckout=true` のとき checkout 予告文 (`verify-email-checkout-note`) を出す」
   - 「`continuesToCheckout=false` のとき予告文を出さない」
   - 「`verify-email-continue` CTA はいずれの場合も存在しない（踏破不能導線の再発検出）」
   - 既存の「CTA は disabled にせず押下可能 (DESIGN.md)」は
     「描画されるボタン（再送信 / ログアウト）に disabled が無い」ことの assert として維持。
   - 個別 `DatabaseTransactions` は使用しない（JS テスト。PHP 側も `RefreshDatabase` グローバル適用のまま）

### 受け入れ条件 (DoD)

- `/email/verify` の Inertia props に `continueUrl` が存在しない（Feature テストで固定）。
- `verify-email-continue` testId がリポジトリ全体から消えている（vitest + grep）。
- 「未認証で `/onboarding/checkout` に到達できない」ことが Feature テストで固定されている。
- verify 完了後に `onboarding.checkout` へ着地する既存契約が壊れていない（既存テスト green）。
- `docs/architecture.md` §P7/P9 に「verify notice に checkout CTA を置かない」根拠が記載されている。

### リスク

| リスク | 緩和 |
|---|---|
| 「あとで認証する」導線を失うことで登録直後の離脱が増える | 元々**一度も機能していない**導線であり、機能低下は生じない（bug-hunt が 302 ループを実測）。代わりに「認証後にプラン選択へ進む」予告文で先の見通しを与える |
| `continuesToCheckout` を渡し忘れた画面で予告文が消える | サーバは常に渡す（Props を optional にしない）。Feature テストで true / false 双方を固定 |
| `EmailVerificationContinuation` の他の利用箇所への影響 | 利用箇所は `verifyEmailView` と `VerifyEmailResponse` の 2 つのみ（grep 済み）。`resolveUrl` / `remember` / `forget` の挙動は不変 |
| 未認証で URL 直打ち / ブックマークした場合の無言 302 が残る | 既存規約（`routes/web.php` 課金ゲートのコメント「遮断理由は着地ページが持つ」）と同じ扱い。着地する `verification.notice` 画面自体が状況を説明する。GET への新規 flash 機構は作らない（オーバーエンジニアリング回避） |

---

## 施策 B: AuthLayout ページの離脱導線を規約化する

### 規約（本設計で確定する契約）

> `AuthLayout` を使うページは、**手順を完了できないユーザーが別の入口へ抜けられる導線**を
> `{#snippet footer()}` に 1 つ以上持ち、`TextLink` atom で表現する。
> 例外は architecture テストの allowlist に**理由付き**で登録する。

行き先は「その画面のユーザーの認証状態で**実際に踏破できる先**」に限る（新しい行き止まりを作らない）。

### 変更箇所

| ファイル | 変更 |
|---|---|
| `resources/js/pages/Auth/ResetPassword.svelte` (L58 の後) | footer snippet 追加: 「新しいリセットリンクをリクエスト」(`/forgot-password`) / 「ログインに戻る」(`/login`) |
| `resources/js/pages/Auth/TwoFactorChallenge.svelte` (L107 の後) | footer snippet 追加: 「ログインをやり直す」(`/login`) |
| `resources/js/pages/Auth/ConfirmRecentAuth.svelte` (L92 の後) | footer snippet 追加: 「この操作を中止してダッシュボードへ戻る」(`/dashboard`) |
| `tests/js/architecture/page-shell-structure.test.ts` | AuthLayout ページの footer 契約を追加 (+ 理由必須 allowlist) |
| `DESIGN.md` (Do's and Don'ts) | Do / Don't を 1 行ずつ追加 |

各ページに `import TextLink from "@/components/atoms/TextLink.svelte";` を追加する
（`ResetPassword` / `TwoFactorChallenge` / `ConfirmRecentAuth` の 3 ファイル）。

### 波及変更

- **TypeScript 型定義**: なし（props 変更なし）
- **API Resource / DTO / route / DB**: なし（**フロントのみ**の変更）
- **テストファイル**: 新規 3 本（各ページの footer 描画）+ architecture テスト 1 本の追加

### 変更後コード

```svelte
<!-- resources/js/pages/Auth/ResetPassword.svelte — </form> の後 -->
    {#snippet footer()}
        <p>
            リンクの有効期限が切れている場合は
            <TextLink href="/forgot-password">新しいリセットリンクをリクエスト</TextLink>
            できます。
        </p>
        <p class="mt-1">
            <TextLink href="/login">ログインに戻る</TextLink>
        </p>
    {/snippet}
```

```svelte
<!-- resources/js/pages/Auth/TwoFactorChallenge.svelte — </form> の後 -->
    {#snippet footer()}
        <p>
            認証コードもリカバリコードも使えない場合は
            <TextLink href="/login">ログインをやり直す</TextLink>
            か、組織の管理者に 2 要素認証のリセットを依頼してください。
        </p>
    {/snippet}
```

- 2FA チャレンジ中のユーザーはまだ未ログイン（Fortify の `login.id` セッション状態）のため
  `guest` middleware 配下の `/login` に到達できる。
- 「管理者に依頼」は既存機能（`organizations.members.two-factor.reset` = Owner/Admin が実行可能）の
  事実に基づく案内であり、新規機能ではない。

```svelte
<!-- resources/js/pages/Auth/ConfirmRecentAuth.svelte — 末尾ブロックの後 -->
    {#snippet footer()}
        <p>
            <TextLink href="/dashboard">この操作を中止してダッシュボードへ戻る</TextLink>
        </p>
    {/snippet}
```

- 本画面のユーザーは `auth` + `verified` 済みのため `/dashboard` に到達できる。
- **intended URL へは戻さない**: step-up 未充足のまま元操作へ戻しても `recent-auth` middleware が
  再び本画面へ送り返すだけで「中止」の意味と食い違う。session の intended URL を UI へ露出させると
  open-redirect の検査面も増える（Codex R1 Warning 5 への回答）。

```ts
// tests/js/architecture/page-shell-structure.test.ts — 追加分
/**
 * AuthLayout ページの離脱導線契約の除外 allowlist。追加は理由必須(reason 非空)。
 */
const AUTH_EXIT_ALLOWLIST: ReadonlyArray<{ path: string; reason: string }> = [
    {
        path: "Auth/VerifyEmail.svelte",
        reason:
            "離脱導線は本文の『ログアウト』(POST 遷移) が担う。footer の TextLink では表現できない。" +
            "認証前に到達できる別入口が無いため、代替リンクを置くと新たな行き止まりを作る。",
    },
];
const AUTH_EXIT_ALLOWLIST_PATHS = new Set(AUTH_EXIT_ALLOWLIST.map((e) => e.path));

/** footer snippet 本体を取り出す (先頭の {/snippet} まで)。無ければ null。 */
function footerSnippetBody(src: string): string | null {
    const m = src.match(/\{#snippet\s+footer\s*\(\s*\)\s*\}([\s\S]*?)\{\/snippet\}/);
    return m ? m[1] : null;
}

it("AUTH_EXIT_ALLOWLIST の各エントリは理由(reason)必須", () => {
    for (const e of AUTH_EXIT_ALLOWLIST) {
        expect(e.reason.trim(), `allowlist "${e.path}" は理由必須`).not.toBe("");
    }
});

it("AuthLayout ページは footer snippet に TextLink の離脱導線を持つ", async () => {
    const files = await sveltePages(PAGES_DIR);
    const missingFooter: string[] = [];
    const footerWithoutLink: string[] = [];

    for (const file of files) {
        const rel = path.relative(PAGES_DIR, file).replace(/\\/g, "/");
        const src = stripComments(await fs.readFile(file, "utf8"));
        if (!importIdentifier(src, "@/components/templates/AuthLayout.svelte")) continue;
        if (AUTH_EXIT_ALLOWLIST_PATHS.has(rel)) continue;

        const body = footerSnippetBody(src);
        if (body === null) {
            missingFooter.push(rel);
            continue;
        }
        const link = importIdentifier(src, "@/components/atoms/TextLink.svelte");
        if (!link || !usesTag(body, link)) footerWithoutLink.push(rel);
    }

    const msg = [
        missingFooter.length && `AuthLayout ページに footer snippet が無い:\n  - ${missingFooter.join("\n  - ")}`,
        footerWithoutLink.length && `footer に TextLink の離脱導線が無い:\n  - ${footerWithoutLink.join("\n  - ")}`,
    ].filter(Boolean).join("\n\n");
    expect({ missingFooter, footerWithoutLink }, msg).toEqual({ missingFooter: [], footerWithoutLink: [] });
});
```

- `sveltePages` / `stripComments` / `importIdentifier` / `usesTag` は**同ファイルの既存ヘルパを再利用**する
  （新規ユーティリティを作らない）。`usesTag` は第 1 引数に任意の文字列を取れるため footer 本体に適用できる。
- テストファイル冒頭の docblock に「AuthLayout ページの離脱導線契約」を追記する
  （このファイルは *ページ外枠テンプレートの構造契約* を集約する場所である、と定義を広げる）。

### DESIGN.md への追記（要否の判断: **要**）

`DESIGN.md` は UI 規約の canonical source であり、禁止事項 #8（disabled ボタン禁止）も
ここに書かれている。今回確定する 2 つの規約は同じ粒度の UI 不変条件なので Do's and Don'ts に追記する:

- **Do**: 認証フロー画面（`AuthLayout`）には、手順を完了できないユーザーが別の入口へ抜けられる導線を
  footer に必ず置く（`tests/js/architecture/page-shell-structure.test.ts` が強制）
- **Don't**: 表示条件と踏破条件が食い違う導線を出さない。押しても必ず失敗するボタン・リンクは、
  **出さずに理由を文章で説明する**（disabled 化でも代替しない = 上の Don't と同根）

`resources/css/tokens.css` との同期は不要（token 追加・変更なし。既存 `TextLink` / `text-caption` を使うのみ）。

### テスト計画（テストファースト）

1. **[新規] `tests/js/architecture/page-shell-structure.test.ts` の追加 it 2 本**
   — 実装前に走らせると `ResetPassword` / `TwoFactorChallenge` / `ConfirmRecentAuth` の 3 件で fail する
   （= 現状の欠落をテストが正しく検出することの確認）。
2. **[新規] `tests/js/pages/ResetPassword.test.ts`**
   - フォーム（メールアドレス / 新しいパスワード / 送信ボタン）を描画する
   - **`/forgot-password` と `/login` への離脱リンクを描画する**（`new URL(link.href).pathname` で比較。
     既存 `Login.test.ts` と同作法）
   - `errors.email`（トークン無効）が渡ってもリンクが消えない
     ＝ *bug-hunt F-2-02 の再現シナリオそのもの*
3. **[新規] `tests/js/pages/TwoFactorChallenge.test.ts`**
   - タブ（認証コード / リカバリコード）切替の既存挙動 + `/login` への離脱リンク
4. **[新規] `tests/js/pages/ConfirmRecentAuth.test.ts`**
   - `passwordSet=true` / `canSatisfy=false` の双方で `/dashboard` への離脱リンクが出る
5. 既存 `tests/js/pages/Login.test.ts` の「register / forgot-password への導線」は**変更しない**（回帰の基準）

### 受け入れ条件 (DoD)

- `AuthLayout` を import する全ページ（allowlist の `Auth/VerifyEmail` を除く）が footer に
  `TextLink` の離脱導線を持ち、architecture テストが green。
- `ResetPassword` は `errors.email` があるときも `/forgot-password` `/login` への導線を出す。
- `DESIGN.md` に 2 規約が記載されている。
- 新しい行き止まり・新しい踏破不能リンクを増やしていない（各リンク先の到達可能性を上表の根拠で説明できる）。

### リスク

| リスク | 緩和 |
|---|---|
| footer リンク先が別の罠になる（例: 未検証ユーザーを `/dashboard` へ送る） | リンク先は「その画面のユーザーの認証状態で到達できる先」に限定し、根拠を設計に明記。`VerifyEmail`（未検証状態）には**リンクを足さない**（allowlist で本文のログアウトを離脱導線と認める） |
| architecture テストの正規表現が footer を誤検出する | 既存ヘルパ（コメント除去 + import 識別子解決）を再利用し、footer 本体を抽出してから `TextLink` を探す。alias import にも対応 |
| allowlist が将来の抜け道になる | `reason` 非空を別 it で強制（既存 `PAGECONTENT_ALLOWLIST` と同方式）。エントリは現時点で 1 件のみ |
| Debug/Login.svelte が対象に入る | 既に footer（`/login`）を持つため追加変更不要。local 専用画面だが規約対象のままにする（例外を作らない） |

---

## 実装モード

| 項目 | 内容 |
|------|------|
| 推奨モード | **incremental** |
| 判断根拠 | DB・route・middleware の変更なし。変更は Auth 系 Svelte ページ 4 枚 + Provider の 1 クロージャ + Support クラスへの 3 行追加 + テスト/ドキュメント。他ドメインと共有する層（Service / DTO / モデル）に触れない |
| 競合リスク | 低。`DESIGN.md` と `docs/architecture.md` は他 TODO と同時編集の可能性があるため、追記は末尾/該当節に限定し diff を小さく保つ。`tests/js/architecture/page-shell-structure.test.ts` は他施策が触る頻度が低い |

## 実装順序（テストファースト）

1. 施策 A のテスト 1（未認証 → checkout 差し戻しの Feature テスト）を書き、**green であることを確認**
   （= 現行仕様の固定。この 1 本だけは最初から green で正しい）
2. 施策 A のテスト 2〜4（`continuesToCheckout` 期待）を書き、**fail を確認** → 実装 → green
3. 施策 B の architecture テストを書き、**3 ページで fail することを確認**
4. 施策 B のページ別 vitest を書き、fail を確認 → footer 実装 → green
5. `DESIGN.md` / `docs/architecture.md` 追記
6. `composer test` / `composer phpstan` / `vendor/bin/pint --test` / `pnpm lint` / `pnpm typecheck` /
   `pnpm test` / `pnpm build` を全 green にする

## 使命・禁止事項チェック

- **使命**: 認証ファネルは SOP → シナリオ → 撮影という価値提供の入口。行き止まり・偽の導線の除去は
  「専門知識ゼロの現場作業者でも使える」ための前提条件に直接寄与する。
- **禁止事項 #1**: 全施策に Feature / Unit / vitest / architecture テストを割り当て済み。
- **禁止事項 #4**: `response()->json()` 不使用（Inertia props のみ）。
- **禁止事項 #7**: `redirect()->intended()` を新たに増やさない（`VerifyEmailResponse` の既存利用は不変で、
  GET signed URL 踏破 = ログイン直後フローに該当）。
- **禁止事項 #8**: disabled 化ではなく「出さずに説明する」で解決。
- **セキュリティ不変条件**: membership 確認（relation 経由 fetch = IDOR 防御）を単一出典のまま維持。
  認証ゲート（`verified`）を緩めない = 未検証メールでの無料枠取得を許さない。


---

## 関連する現行コード

### app/Support/Auth/EmailVerificationContinuation.php

```
<?php

declare(strict_types=1);

namespace App\Support\Auth;

use App\Models\User;
use Illuminate\Contracts\Session\Session;

/**
 * 登録 → verify notice ソフトゲートの「あとで認証する」継続導線。
 *
 * 生 URL を session に持たず organization_id のみ保持し、参照時に route を再構築 +
 * membership 確認する (URL 直保持はルート変更・値汚染に脆い)。所属確認 (relation 経由
 * fetch、IDOR 防御規約) を通らない値は null = 導線を出さない。
 * 寿命: remember (登録時) → forget (verify 完了時)。
 *
 * AI-CUE の onboarding route は current-org スコープ (route parameter なし) のため、
 * 再構築するのは引数なしの `route('onboarding.checkout')`。session に保持した
 * organization_id は「その組織のメンバーであること」の確認にのみ使う。
 */
final class EmailVerificationContinuation
{
    private const string SESSION_KEY = 'verify_continue_organization_id';

    public static function remember(Session $session, int $organizationId): void
    {
        $session->put(self::SESSION_KEY, $organizationId);
    }

    /**
     * session の organization_id から checkout URL を再構築する。
     * 所属確認を通らない値・非 int・null user は null (= 導線を出さない)。
     */
    public static function resolveUrl(?User $user, Session $session): ?string
    {
        if ($user === null) {
            return null;
        }

        $organizationId = $session->get(self::SESSION_KEY);
        if (! is_int($organizationId)) {
            return null;
        }

        $organization = $user->organizations()->whereKey($organizationId)->first();
        if ($organization === null) {
            return null;
        }

        return route('onboarding.checkout');
    }

    public static function forget(Session $session): void
    {
        $session->forget(self::SESSION_KEY);
    }
}
```

### app/Providers/FortifyServiceProvider.php (L226-245)

```
                'email' => is_string($email) ? $email : null,
            ]);
        });

        Fortify::verifyEmailView(static function (Request $request): InertiaResponse {
            $user = $request->user();

            // 登録由来の継続導線 (「あとで認証する」)。session には組織 id のみ保持し、
            // membership 確認を通ったときだけ URL 化する (IDOR 防御)。
            return Inertia::render('Auth/VerifyEmail', [
                'continueUrl' => EmailVerificationContinuation::resolveUrl(
                    $user instanceof User ? $user : null,
                    $request->session(),
                ),
            ]);
        });

        // password.confirm (Fortify 生 step-up) は generic recent-auth に置換済み。
        // ただし fortify.views=true の間は GET /user/confirm-password が Fortify により
        // 無条件登録され、ConfirmPasswordViewResponse 未 bind だと直アクセスが
```

### app/Http/Responses/Fortify/VerifyEmailResponse.php

```
<?php

declare(strict_types=1);

namespace App\Http\Responses\Fortify;

use App\Models\User;
use App\Support\Auth\EmailVerificationContinuation;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Laravel\Fortify\Contracts\VerifyEmailResponse as VerifyEmailResponseContract;

/**
 * メール認証完了後の着地 (Fortify contract bind)。
 *
 * 登録由来の continuation (個人組織 id) があれば onboarding.checkout へ復帰し、
 * continuation は verify 完了時に必ず forget する (寿命の terminal)。
 * continuation が無い場合の着地は **Fortify 既定と同値** (`fortify.home` + `?verified=1`)
 * に保つ = 既存の verify 完了フローを後退させない。
 *
 * `redirect()->intended()` の使用はログイン直後フロー (GET の signed URL 踏破) に限られ、
 * 操作系 POST の応答ではない (AGENTS.md 禁止事項 #7 に抵触しない)。
 */
final class VerifyEmailResponse implements VerifyEmailResponseContract
{
    /**
     * @param  Request  $request
     */
    public function toResponse($request): RedirectResponse
    {
        $user = $request->user();
        $continueUrl = EmailVerificationContinuation::resolveUrl(
            $user instanceof User ? $user : null,
            $request->session(),
        );
        EmailVerificationContinuation::forget($request->session());

        if ($continueUrl !== null) {
            return redirect()->to($continueUrl);
        }

        return redirect()->intended(config()->string('fortify.home').'?verified=1');
    }
}
```

### routes/web.php (L163-172)

```

/*
|--------------------------------------------------------------------------
| 認証済み
|--------------------------------------------------------------------------
*/
Route::middleware(['auth', 'verified'])->group(function (): void {
    // ログイン直後の着地点 (課金ゲート外のまま。未契約でも状況把握と復帰導線を提供)
    Route::get('/dashboard', DashboardController::class)->name('dashboard');

```

### routes/web.php (L347-366)

```

    /*
    | 課金オンボーディング (current org スコープ)。登録直後の Plan 選択 +
    | 未契約 manageBilling なし member 向け説明画面。billing.* と同じく課金ゲート
    | (require-active-subscription) の外に置く = 未契約組織が導線に到達できることを保証する
    | (ゲート内に入れると「契約するための画面が契約してないと見られない」詰みになる)。
    | 組織解決は billing.* と同一 (route parameter なし = URL の org ≠ current org が
    | 構造的に発生しない)。認可は Controller 冒頭の Gate::authorize が担う。
    | MCP/CLI 導入ガイド (organizations.onboarding.{mcp,cli}) とは別責務・別 name。
    */
    Route::get('/onboarding/checkout', [OnboardingController::class, 'show'])
        ->name('onboarding.checkout');
    // Personal (free) の有効化 (Stripe checkout を通らない。自己申告チェック必須)
    Route::post('/onboarding/activate-personal', ActivatePersonalController::class)
        ->middleware('throttle:10,1')
        ->name('onboarding.activate-personal');
    Route::get('/billing-required', [BillingRequiredController::class, 'show'])
        ->name('onboarding.billing-required');

    /*
```

### resources/js/pages/Auth/VerifyEmail.svelte

```
<script lang="ts">
    import { router, useForm } from "@inertiajs/svelte";
    import Button from "@/components/atoms/Button.svelte";
    import AuthLayout from "@/components/templates/AuthLayout.svelte";

    interface Props {
        appName?: string;
        /**
         * 登録由来の継続導線 (プラン選択へ進む)。サーバが membership 確認を通ったときだけ
         * 非 null で届く。null のときは二次 CTA を出さない。
         */
        continueUrl?: string | null;
    }

    let { appName, continueUrl = null }: Props = $props();

    const form = useForm({});

    let loggingOut = $state(false);

    function resend(event: SubmitEvent): void {
        event.preventDefault();
        form.post("/email/verification-notification");
    }

    function logout(): void {
        router.post(
            "/logout",
            {},
            {
                onStart: () => {
                    loggingOut = true;
                },
                onFinish: () => {
                    loggingOut = false;
                },
            },
        );
    }
</script>

<AuthLayout title="メール認証" {appName}>
    <p class="mb-6 text-body text-text-secondary">
        ご登録いただいたメールアドレスに認証メールを送信しました。
        メール内のリンクをクリックして認証を完了してください。
        メールが届かない場合は、再送信できます。
    </p>

    <form onsubmit={resend} class="flex flex-col gap-3">
        <Button type="submit" loading={form.processing} fullWidth>認証メールを再送信</Button>
        {#if continueUrl !== null}
            <Button
                variant="ghost"
                onclick={() => router.visit(continueUrl)}
                fullWidth
                testId="verify-email-continue"
            >
                あとで認証する（プラン選択へ進む）
            </Button>
        {/if}
        <Button variant="ghost" onclick={logout} loading={loggingOut} fullWidth>
            ログアウト
        </Button>
    </form>
</AuthLayout>
```

### resources/js/components/templates/AuthLayout.svelte

```
<script lang="ts">
    import type { Snippet } from "svelte";
    import { page } from "@inertiajs/svelte";
    import ToastContainer from "@/components/organisms/ToastContainer.svelte";
    import { consumeFlash, type FlashPayload } from "@/lib/stores/flash-to-toast";

    /**
     * 認証画面 (login / register / reset 等) 用レイアウト。
     * 中央寄せの surface カード 1 枚構成。
     * Laravel flash は consumeFlash で toast に変換する (visitKey で de-dup)。
     */
    interface Props {
        /** カード上部の見出し */
        title: string;
        appName?: string;
        children: Snippet;
        /** カード下部の補助導線 (別画面へのリンク等) */
        footer?: Snippet;
    }

    let { title, appName, children, footer }: Props = $props();

    $effect(() => {
        consumeFlash(page.props.flash as FlashPayload | undefined);
    });
</script>

<ToastContainer />

<div class="flex min-h-screen flex-col items-center justify-center bg-neutral px-4 py-10 text-text">
    {#if appName}
        <p class="mb-6 text-h3 text-primary">{appName}</p>
    {/if}
    <main class="w-full max-w-md rounded-lg border border-border bg-surface p-6">
        <h1 class="mb-6 text-h2">{title}</h1>
        {@render children()}
    </main>
    {#if footer}
        <div class="mt-4 text-center text-caption text-text-secondary">
            {@render footer()}
        </div>
    {/if}
</div>
```

### resources/js/pages/Auth/ResetPassword.svelte

```
<script lang="ts">
    import { useForm } from "@inertiajs/svelte";
    import Button from "@/components/atoms/Button.svelte";
    import Input from "@/components/atoms/Input.svelte";
    import FormField from "@/components/molecules/FormField.svelte";
    import PasswordInput from "@/components/molecules/PasswordInput.svelte";
    import AuthLayout from "@/components/templates/AuthLayout.svelte";

    interface Props {
        token: string;
        email: string | null;
        appName?: string;
    }

    let { token, email, appName }: Props = $props();

    // token は表示しない hidden 値としてフォームデータに含める
    const form = useForm({
        token,
        email: email ?? "",
        password: "",
    });

    function submit(event: SubmitEvent): void {
        event.preventDefault();
        form.post("/reset-password");
    }
</script>

<AuthLayout title="パスワードリセット" {appName}>
    <form onsubmit={submit} class="flex flex-col gap-4">
        <FormField label="メールアドレス" id="email" error={form.errors.email}>
            {#snippet children({ id, describedBy, invalid })}
                <Input
                    {id}
                    type="email"
                    bind:value={form.email}
                    error={invalid}
                    aria-describedby={describedBy}
                    autocomplete="email"
                />
            {/snippet}
        </FormField>

        <FormField label="新しいパスワード" id="password" error={form.errors.password}>
            {#snippet children({ id, describedBy, invalid })}
                <PasswordInput
                    {id}
                    bind:value={form.password}
                    error={invalid}
                    aria-describedby={describedBy}
                    autocomplete="new-password"
                />
            {/snippet}
        </FormField>

        <Button type="submit" loading={form.processing} fullWidth>パスワードをリセット</Button>
    </form>
</AuthLayout>
```

### resources/js/pages/Auth/ForgotPassword.svelte (L44-53)

```

        <Button type="submit" loading={form.processing} fullWidth>リセットリンクを送信</Button>
    </form>

    {#snippet footer()}
        <p>
            <TextLink href="/login">ログインに戻る</TextLink>
        </p>
    {/snippet}
</AuthLayout>
```

### resources/js/pages/Auth/TwoFactorChallenge.svelte (L100-109)

```
                            autocomplete="one-time-code"
                        />
                    {/snippet}
                </FormField>
            </div>
        {/if}

        <Button type="submit" loading={form.processing} fullWidth>認証する</Button>
    </form>
</AuthLayout>
```

### resources/js/pages/Auth/ConfirmRecentAuth.svelte (L70-93)

```
    {/if}

    {#if availableProviders.length > 0}
        <div class="mt-6 flex flex-col gap-3">
            {#if passwordSet}
                <Divider label="または" />
            {/if}
            {#each availableProviders as provider (provider.provider)}
                <Button href={provider.reauthUrl} variant="ghost" fullWidth>
                    {providerLabel(provider.provider)}で再認証
                </Button>
            {/each}
        </div>
    {/if}

    {#if !canSatisfy}
        <div class="mt-6 flex flex-col gap-3 text-caption text-text-secondary">
            <p>この操作を続けるための再認証手段が設定されていません。パスワードを設定すると再認証できます。</p>
            <Button href="/forgot-password" variant="ghost" fullWidth>
                パスワードを設定して再認証する
            </Button>
        </div>
    {/if}
</AuthLayout>
```

### tests/js/architecture/page-shell-structure.test.ts

```
import { describe, it, expect } from "vitest";
import fs from "fs/promises";
import path from "path";
import { fileURLToPath } from "url";

/*
 * page-shell-structure — 認証ページ外枠の aigenba parity を構造保証する Architecture テスト。
 *
 * 契約: `AppLayout` を import するページ (ログイン後シェルを使う認証ページ) は、aigenba の統一外枠
 *   <AppLayout><PageContainer><PageHeader|PageHeaderSection><PageContent>…
 * に従い、layout primitive を import かつ使用する。これにより外枠(padding/見出し/中央寄せ max-w-7xl)が
 * primitive に一元化され、ページ独自の外枠ドリフトを構造的に防ぐ。
 *
 * 運用規約(機械強制でない・レビュー観点): 本文標準は上記外枠。ALLOWLIST 追加は理由必須。
 * (旧 page-content-usage.test.ts をリネーム。AdminMenuNav 等の廃止 import は deprecated-imports.test.ts。)
 */

const HERE = path.dirname(fileURLToPath(import.meta.url));
const PAGES_DIR = path.resolve(HERE, "../../../resources/js/pages");

/** PageContent 必須契約の除外 allowlist (PageContainer/PageHeader は必須)。追加は理由必須(reason 非空)。 */
const PAGECONTENT_ALLOWLIST: ReadonlyArray<{ path: string; reason: string }> = [
    {
        path: "Capture/Show.svelte",
        reason: "2 カラム grid の撮影レコーダー面。全幅のため PageContent の max-w-7xl 中央寄せを課さない。",
    },
];
const PAGECONTENT_ALLOWLIST_PATHS = new Set(PAGECONTENT_ALLOWLIST.map((e) => e.path));

const escapeRegExp = (s: string): string => s.replace(/[.*+?^${}()|[\]\\]/g, "\\$&");

function stripComments(src: string): string {
    return src
        .replace(/<!--[\s\S]*?-->/g, "")
        .replace(/\/\*[\s\S]*?\*\//g, "")
        // `//` 行コメントは**行頭コメント限定**で除去 (先頭空白のみ許容)。文字列内 (URL "https://" 等)
        // や行内の `//` を壊さないため、`//` が行の内容の先頭にある場合のみ落とす (Codex impl-review R1)。
        .replace(/^\s*\/\/[^\n]*$/gm, "");
}

async function sveltePages(dir: string): Promise<string[]> {
    const out: string[] = [];
    for (const e of await fs.readdir(dir, { recursive: true, withFileTypes: true })) {
        if (e.isFile() && e.name.endsWith(".svelte")) out.push(path.join(e.parentPath, e.name));
    }
    return out;
}

const importsAppLayout = (src: string): boolean =>
    /import\s+\w+\s+from\s+["']@\/components\/templates\/AppLayout\.svelte["']/.test(src);

/** 指定 primitive path の default import 識別子を返す (alias 対応)。無ければ null。 */
function importIdentifier(src: string, importPath: string): string | null {
    const re = new RegExp(`import\\s+(\\w+)\\s+from\\s+["']${escapeRegExp(importPath)}["']`);
    const m = src.match(re);
    return m ? m[1] : null;
}

/** 識別子の通常開始タグが使われているか (タグ名境界まで)。 */
const usesTag = (src: string, ident: string): boolean =>
    new RegExp(`<${escapeRegExp(ident)}(?:\\s|/?>)`).test(src);

describe("architecture/page-shell-structure", () => {
    it("PAGECONTENT_ALLOWLIST の各エントリは理由(reason)必須", () => {
        for (const e of PAGECONTENT_ALLOWLIST) {
            expect(e.reason.trim(), `allowlist "${e.path}" は理由必須`).not.toBe("");
        }
    });

    it("AppLayout ページは PageContainer + PageHeader(Section) + PageContent を使い、padding={false} を使わない", async () => {
        const files = await sveltePages(PAGES_DIR);
        const missingContainer: string[] = [];
        const missingHeader: string[] = [];
        const missingContent: string[] = [];
        const paddingFalse: string[] = [];

        for (const file of files) {
            const rel = path.relative(PAGES_DIR, file).replace(/\\/g, "/");
            const src = stripComments(await fs.readFile(file, "utf8"));
            if (!importsAppLayout(src)) continue;

            // PageContainer 必須 + padding={false} 禁止
            const pc = importIdentifier(src, "@/components/templates/PageContainer.svelte");
            if (!pc || !usesTag(src, pc)) missingContainer.push(rel);
            else if (new RegExp(`<${escapeRegExp(pc)}\\b[^>]*\\bpadding=\\{false\\}`).test(src))
                paddingFalse.push(rel);

            // PageHeader または PageHeaderSection 必須
            const ph = importIdentifier(src, "@/components/molecules/PageHeader.svelte");
            const phs = importIdentifier(src, "@/components/molecules/PageHeaderSection.svelte");
            const hasHeader = (ph && usesTag(src, ph)) || (phs && usesTag(src, phs));
            if (!hasHeader) missingHeader.push(rel);

            // PageContent 必須 (allowlist 除く)
            if (!PAGECONTENT_ALLOWLIST_PATHS.has(rel)) {
                const pcnt = importIdentifier(src, "@/components/templates/PageContent.svelte");
                if (!pcnt || !usesTag(src, pcnt)) missingContent.push(rel);
            }
        }

        const msg = [
            missingContainer.length && `PageContainer 不足/未使用:\n  - ${missingContainer.join("\n  - ")}`,
            missingHeader.length && `PageHeader(Section) 不足/未使用:\n  - ${missingHeader.join("\n  - ")}`,
            missingContent.length && `PageContent 不足/未使用:\n  - ${missingContent.join("\n  - ")}`,
            paddingFalse.length && `PageContainer padding={false} は禁止:\n  - ${paddingFalse.join("\n  - ")}`,
        ].filter(Boolean).join("\n\n");
        expect(
            { missingContainer, missingHeader, missingContent, paddingFalse },
            msg,
        ).toEqual({ missingContainer: [], missingHeader: [], missingContent: [], paddingFalse: [] });
    });
});
```

### tests/Feature/Auth/RegisterVerifyFlowTest.php

```
<?php

declare(strict_types=1);

use App\Models\Organization;
use App\Models\User;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\URL;
use Inertia\Testing\AssertableInertia;

/**
 * P7: メール/パスワード登録 → verification.notice ソフトゲート。
 *
 * EmailVerificationContinuation が session に personal org id を保持し、
 * /email/verify の二次 CTA (continueUrl) と verify 完了後の checkout 復帰を支える。
 * session 値は membership 確認 (relation 経由 fetch) を通らない限り URL 化されない。
 */
beforeEach(function (): void {
    Http::fake(['https://api.pwnedpasswords.com/range/*' => Http::response('', 200)]);

    $this->validPayload = fn (array $overrides = []): array => array_merge([
        'name' => 'Verify Flow Tester',
        'email' => 'verify-flow-'.uniqid().'@example.com',
        'password' => 'CorrectHorse9Battery',
        'terms_accepted' => '1',
    ], $overrides);

    // Fortify 標準 verify controller が踏む signed URL を再現する。
    $this->verificationUrlFor = fn (User $user): string => URL::temporarySignedRoute(
        'verification.verify',
        now()->addMinutes(60),
        ['id' => $user->getKey(), 'hash' => sha1($user->getEmailForVerification())],
    );
});

test('登録 POST は verification.notice へ redirect し personal org id を session に保持する', function (): void {
    Notification::fake();
    $payload = ($this->validPayload)();

    $response = $this->post('/register', $payload);

    $response->assertRedirect(route('verification.notice'));

    $user = User::query()->whereBlind('email', 'email_index', $payload['email'])->firstOrFail();
    $personalOrg = $user->organizations()->where('is_personal', true)->firstOrFail();
    expect(session('verify_continue_organization_id'))->toBe($personalOrg->id);
});

test('登録後の /email/verify GET は continueUrl に onboarding.checkout を返す', function (): void {
    Notification::fake();
    $payload = ($this->validPayload)();
    $this->post('/register', $payload);

    $this->get('/email/verify')
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('Auth/VerifyEmail')
            ->where('continueUrl', route('onboarding.checkout')));
});

test('continuation なしで /email/verify を直接開くと continueUrl は null', function (): void {
    $user = User::factory()->unverified()->create();

    $this->actingAs($user)
        ->get('/email/verify')
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('Auth/VerifyEmail')
            ->where('continueUrl', null));
});

test('session に他人の organization id が混入しても continueUrl は null (membership 確認)', function (): void {
    $otherOrg = Organization::factory()->create();
    $user = User::factory()->unverified()->create();

    $this->actingAs($user)
        ->withSession(['verify_continue_organization_id' => $otherOrg->id])
        ->get('/email/verify')
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page->where('continueUrl', null));
});

test('session 値が int でない場合は continueUrl は null (値汚染防御)', function (): void {
    $user = User::factory()->unverified()->create();

    $this->actingAs($user)
        ->withSession(['verify_continue_organization_id' => 'not-an-int'])
        ->get('/email/verify')
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page->where('continueUrl', null));
});

test('verify 完了で onboarding.checkout へ redirect し continuation が消える', function (): void {
    Notification::fake();
    $payload = ($this->validPayload)();
    $this->post('/register', $payload);

    $user = User::query()->whereBlind('email', 'email_index', $payload['email'])->firstOrFail();

    $response = $this->get(($this->verificationUrlFor)($user));

    $response->assertRedirect(route('onboarding.checkout'));
    $response->assertSessionMissing('verify_continue_organization_id');
    expect($user->fresh()?->hasVerifiedEmail())->toBeTrue();
});

test('continuation なしの verify 完了は Fortify 既定と同値 (/dashboard?verified=1)', function (): void {
    $user = User::factory()->unverified()->create();

    $response = $this->actingAs($user)->get(($this->verificationUrlFor)($user));

    $response->assertRedirect(config()->string('fortify.home').'?verified=1');
    expect($user->fresh()?->hasVerifiedEmail())->toBeTrue();
});
```

### tests/js/pages/VerifyEmail.test.ts

```
import { describe, expect, it, vi } from "vitest";
import { render, screen } from "@testing-library/svelte";
import VerifyEmail from "@/pages/Auth/VerifyEmail.svelte";

const { routerVisitMock } = vi.hoisted(() => ({ routerVisitMock: vi.fn() }));

vi.mock("@inertiajs/svelte", async (importOriginal) => ({
    ...(await importOriginal<typeof import("@inertiajs/svelte")>()),
    router: { visit: routerVisitMock, post: vi.fn() },
}));

/*
 * メール認証待ち画面 (ソフトゲート)。
 * continueUrl は「登録由来の継続導線が実在するとき」だけサーバが非 null で渡す。
 * null のときに二次 CTA を出さない (= 継続先の無いボタンを出さない) ことを固定する。
 */
describe("Auth/VerifyEmail", () => {
    it("continueUrl が null なら二次 CTA を出さない", () => {
        render(VerifyEmail, { props: { appName: "My App" } });

        expect(screen.queryByTestId("verify-email-continue")).toBeNull();
        expect(screen.getByRole("button", { name: "認証メールを再送信" })).toBeInTheDocument();
    });

    it("continueUrl があるとき「あとで認証する」CTA を出す", () => {
        render(VerifyEmail, {
            props: { appName: "My App", continueUrl: "/onboarding/checkout" },
        });

        expect(screen.getByTestId("verify-email-continue")).toBeInTheDocument();
    });

    it("CTA は disabled にせず押下可能 (DESIGN.md)", () => {
        const { container } = render(VerifyEmail, {
            props: { appName: "My App", continueUrl: "/onboarding/checkout" },
        });

        expect(container.querySelectorAll("button[disabled]")).toHaveLength(0);
    });
});
```

### tests/js/pages/Login.test.ts (L26-40)

```
    it("register / forgot-password への導線を表示する", () => {
        render(Login, { props: { appName: "My App", socialProviders: [] } });

        // Inertia Link は href を絶対 URL に正規化するため pathname で比較する
        const registerLink = screen.getByRole("link", { name: "登録" }) as HTMLAnchorElement;
        const forgotLink = screen.getByRole("link", {
            name: "パスワードをお忘れの方",
        }) as HTMLAnchorElement;
        expect(new URL(registerLink.href).pathname).toBe("/register");
        expect(new URL(forgotLink.href).pathname).toBe("/forgot-password");
    });
});
```

