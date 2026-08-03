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
| B | AuthLayout ページの離脱導線を規約化し architecture テストで強制 (F-2-02 + 同種欠落 2 件 + `ConfirmRecentAuth` の踏破不能 CTA) | `resources/js/pages/Auth/ResetPassword.svelte`, `resources/js/pages/Auth/TwoFactorChallenge.svelte`, `resources/js/pages/Auth/ConfirmRecentAuth.svelte`, `tests/js/architecture/page-shell-structure.test.ts`, `tests/js/pages/ResetPassword.test.ts`(新規), `tests/js/pages/TwoFactorChallenge.test.ts`(新規), `tests/js/pages/ConfirmRecentAuth.test.ts`(新規), `tests/Feature/Auth/RecentAuthTest.php`, `tests/Feature/Auth/RecentAuthPasswordRecoveryTest.php`(新規), `DESIGN.md` | High |

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
   - 実装は Pest の `dataset` で 5 ケースを列挙し、各ケースで
     **`hasContinuation() === (resolveUrl() !== null)` の同値性そのもの**を assert する
     （将来 `hasContinuation` が独自条件を持ち始めたら落ちる。Codex R1 施策 A Suggestion）。
4. **[更新] `tests/js/pages/VerifyEmail.test.ts`**
   - 「`continuesToCheckout=true` のとき checkout 予告文 (`verify-email-checkout-note`) を出す」
   - 「`continuesToCheckout=false` のとき予告文を出さない」
   - **[一般化された回帰検知 — Codex R1 施策 A Warning]**
     `continuesToCheckout` が true / false のどちらでも
     「**描画される button は『認証メールを再送信』と『ログアウト』の 2 つだけ**」かつ
     「**link (role=link) が 1 つも無い**」ことを固定する
     （`screen.getAllByRole("button").map(b => b.textContent.trim())` を期待集合と厳密比較）。
     **禁止したい CTA 側の testId・ラベルには一切依存しない**
     （依存するのは許可された 2 ボタンのラベルのみ）ため、
     **別実装の踏破不能 CTA が再混入しても検出できる**。
     `verify-email-continue` の不在 assert も併せて残す（旧実装の直接的な回帰）。
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
| `resources/js/pages/Auth/ConfirmRecentAuth.svelte` (L88-92 の置換 + 末尾) | `canSatisfy=false` 分岐の**踏破不能 CTA を差し替え** (下記 B-2) + footer snippet 追加: 「この操作を中止してダッシュボードへ戻る」(`/dashboard`) |
| `tests/js/architecture/page-shell-structure.test.ts` | AuthLayout ページの footer 契約を追加 (+ 理由必須 allowlist) |
| `DESIGN.md` (Do's and Don'ts) | Do / Don't を 1 行ずつ追加 |

各ページに `import TextLink from "@/components/atoms/TextLink.svelte";` を追加する
（`ResetPassword` / `TwoFactorChallenge` / `ConfirmRecentAuth` の 3 ファイル）。

### 波及変更

- **TypeScript 型定義**: なし（props 変更なし。`ConfirmRecentAuth` の `canSatisfy` / `passwordSet` /
  `availableProviders` は既存のまま = `RecentAuthStatusResource` / `RecentAuthStatusDto` に影響なし）
- **API Resource / DTO / route / DB**: なし（**フロントのみ**の変更）
- **テストファイル**: 新規 3 本（各ページの footer 描画）+ architecture テスト 3 本の追加
  + `tests/Feature/Auth/RecentAuthTest.php` への 2 本追記（B-2 の根拠）
  + `tests/Feature/Auth/RecentAuthPasswordRecoveryTest.php`（新規。回復手順の端まで）

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
  open-redirect の検査面も増える（概念設計 Codex R1 Warning 5 への回答）。

#### B-2: `ConfirmRecentAuth` の `canSatisfy=false` 分岐にある踏破不能 CTA の差し替え

**（Codex 詳細レビュー R1 施策 B Warning。F-2-01 と完全に同 species のため本 TODO で直す）**

現行 `ConfirmRecentAuth.svelte:88-90` は
`<Button href="/forgot-password" variant="ghost" fullWidth>パスワードを設定して再認証する</Button>`
を出すが、`/forgot-password` は Fortify が **`guest` middleware 付き**で登録している
(`vendor/laravel/fortify/routes/routes.php:55-57`)。本画面のユーザーは**ログイン済み**なので
`RedirectIfAuthenticated` により**フォームに到達せず**そのまま別画面へ飛ばされる
（リセットメールは 1 通も送られない）= 表示条件と踏破条件の不一致。

さらに、アプリ内でパスワードを設定する経路も存在しない:
`UpdateUserPassword`（`PUT /user/password`）は `current_password` 必須のため、
パスワード未設定のユーザーは使えない（`app/Actions/Fortify/UpdateUserPassword.php:33`）。

→ **実際に踏破できる唯一の回復手順は「ログアウトしてから（guest として）パスワード再設定を行う」**。
CTA をその事実に合わせる:

```svelte
<!-- resources/js/pages/Auth/ConfirmRecentAuth.svelte — canSatisfy=false 分岐 -->
{#if !canSatisfy}
    <div class="mt-6 flex flex-col gap-3 text-caption text-text-secondary">
        <p>
            この操作を続けるための再認証手段が設定されていません。
            いったんログアウトし、ログイン画面の「パスワードをお忘れの方」から
            パスワードを設定すると再認証できるようになります。
        </p>
        <Button variant="ghost" onclick={logout} loading={loggingOut} fullWidth>
            ログアウトする
        </Button>
    </div>
{/if}
```

- `logout()` は `VerifyEmail.svelte:26-39` と同じ `router.post("/logout")` パターンを流用する
  （`loggingOut` の `$state` 込み。新しい仕組みを作らない）。
- **CTA のラベルは実際の着地と一致させる**（Codex R2 施策 B Warning）。押下で起きることは
  「ログアウト」だけなので `ログアウトする` とし、その後の手順は本文の説明が担う。
  **ログアウト直後に `/forgot-password` へ強制遷移させる特別扱いは作らない**（Fortify の
  logout 応答契約に手を入れない / クライアント側の二段遷移を発明しない = オーバーエンジニアリング回避）。
- ログアウト後の着地は Fortify 既定（`/` = `Welcome`）で、そこには **guest 向け nav の
  「ログイン」リンクが常時ある**（`resources/js/pages/Welcome.svelte:136`）。
  この契約は既存テスト `tests/js/pages/Welcome.test.ts:120` が
  「`ログイン` の role=link が存在する」で固定済みであり、**本設計はその契約に依存する**
  （新規テストは追加せず、依存関係を設計に明記して壊れたら気づけるようにする）。

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

/**
 * footer snippet 本体を取り出す (先頭の {/snippet} まで)。
 * - 定義が 0 個 → null (= 契約違反として報告)
 * - 定義が 2 個以上 / 本体に snippet が入れ子 → "抽出器が現実に追いつけていない" 印として
 *   例外を投げる (fail-closed。黙って見逃さない)
 */
function footerSnippetBody(src: string): string | null {
    const matches = [...src.matchAll(/\{#snippet\s+footer\s*\(\s*\)\s*\}([\s\S]*?)\{\/snippet\}/g)];
    if (matches.length === 0) return null;
    if (matches.length > 1) {
        throw new Error("footer snippet の定義が複数あります。抽出器の前提が崩れています。");
    }
    const body = matches[0][1];
    if (/\{#snippet\b/.test(body)) {
        throw new Error("footer snippet に snippet が入れ子です。抽出器を AST 方式へ更新してください。");
    }
    return body;
}

it("AUTH_EXIT_ALLOWLIST の各エントリは理由(reason)必須 / path 重複なし", () => {
    for (const e of AUTH_EXIT_ALLOWLIST) {
        expect(e.reason.trim(), `allowlist "${e.path}" は理由必須`).not.toBe("");
    }
    // path 重複は編集ミスの兆候 (Codex R2 Suggestion)
    expect(AUTH_EXIT_ALLOWLIST_PATHS.size).toBe(AUTH_EXIT_ALLOWLIST.length);
});

it("AUTH_EXIT_ALLOWLIST の各エントリは実在し AuthLayout を使うページである (死蔵 entry 検出)", async () => {
    for (const e of AUTH_EXIT_ALLOWLIST) {
        const abs = path.join(PAGES_DIR, e.path);
        const src = stripComments(await fs.readFile(abs, "utf8"));
        expect(
            importIdentifier(src, "@/components/templates/AuthLayout.svelte"),
            `allowlist "${e.path}" は AuthLayout ページではない (entry が死蔵または typo)`,
        ).not.toBeNull();
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
- **AST (`svelte/compiler`) 方式は採らない**（Codex R1 施策 B Warning への回答）:
  同ファイルの既存契約（`AppLayout` 側）が正規表現 + import 識別子解決で統一されており、
  1 ファイル内に 2 方式を並走させない方が保守的。代わりに上記の **fail-closed ガード**
  （footer 定義の重複・snippet 入れ子で例外）を置き、**抽出器の前提が崩れたときに黙って
  pass しない**ことを保証する。ガードが発火したら AST 方式への移行を検討する。

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
   - **`canSatisfy=false` のとき `/forgot-password` へのリンクが存在しない**（B-2 の回帰。
     `screen.queryAllByRole("link")` の href に `/forgot-password` を含まないことを assert）
   - `canSatisfy=false` のとき「**ログアウトする**」ボタンが出て、押下で `router.post("/logout")` が
     呼ばれる（`vi.mock("@inertiajs/svelte")` で router を差し替える。既存
     `tests/js/pages/VerifyEmail.test.ts:5-10` と同作法。Codex R3 Warning）
5. **[追記] `tests/Feature/Auth/RecentAuthTest.php`** — B-2 の根拠を仕様として固定する
   - `test('ログイン済みユーザーは GET /forgot-password のフォームに到達できない (guest ゲート)')`:
     `actingAs($user)->get('/forgot-password')` が **redirect であり 200 ではない**ことを assert
     （redirect 先は `RedirectIfAuthenticated::defaultRedirectUri()` 依存のため pin しない）。
     = 「認証済み画面から `/forgot-password` へリンクしてはならない」根拠。
   - `test('password 未設定かつ利用可能な再認証 provider が無いユーザーは canSatisfy=false')`:
     `User::factory()->ssoOnly()->create()`（social account を紐付けない）→
     `/recent-auth/confirm` の props が `passwordSet=false` / `availableProviders=[]` / `canSatisfy=false`。
     ※ **「SSO 専用ユーザー」とは呼ばない**（Codex R3 Suggestion）。実態は
     「password 未設定 かつ 利用可能な再認証 provider なし」という**状態**であり、
     provider が生きている通常の SSO ユーザー（`canSatisfy=true`）と混同しない。
6. **[新規] `tests/Feature/Auth/RecentAuthPasswordRecoveryTest.php`** — B-2 が案内する回復手順が
   **端まで成立する**ことを固定する（Codex R2 施策 B Warning。「案内はあるが実際にはできない」の再発防止）
   - `test('再認証手段が無いユーザーはログアウト後にパスワードを設定でき、再認証可能になる')`:
     1. `Http::fake(['https://api.pwnedpasswords.com/range/*' => Http::response('', 200)])`
        （`PasswordPolicy` の HIBP 照会を止める。既存 `RegisterVerifyFlowTest:20` と同作法）
        + `Notification::fake()`
     2. `$user = User::factory()->ssoOnly()->create();`（password null / social account なし）
     3. `actingAs($user)->get('/recent-auth/confirm')` → `canSatisfy=false` を確認
     4. `post('/logout')` → **`$response->assertRedirect('/');` + `$this->assertGuest();`**
        （`assertGuest()` は TestResponse ではなく TestCase 側のメソッドなので 2 文に分ける。Codex R4 Suggestion）
        （B-2 が前提にしている「ログアウトで Welcome に着地し guest になる」契約の固定。
        Fortify 既定 `Fortify::redirects('logout', '/')` = `/`。Codex R3 Warning）
     5. `post('/forgot-password', ['email' => $email])` →
        `Notification::assertSentTo($user, ResetPassword::class)` で token を取り出す
        （email は CipherSweet 暗号化だが `App\Auth\EncryptedUserProvider` が
        `whereBlind` 経由で解決する = 平文 where に依存しない）
     6. `post('/reset-password', ['token' => $token, 'email' => $email, 'password' => $new])` →
        **`assertRedirect(route('login'))` + `assertSessionHasNoErrors()`**
        （`PasswordResetResponse` は login へ redirect + `success` flash）。
        **`password_confirmation` は送らない**: `App\Actions\Fortify\ResetUserPassword::reset()` の
        rules は `['password' => ['required','string', Password::default()]]` のみで
        `confirmed` を**意図的に使っていない**（docblock「確認入力 (confirmed) は使わない」）。
        画面 `ResetPassword.svelte` にも確認用フィールドは無い。
        → Codex R3 Warning 1 は本リポジトリの構成には当てはまらないため、
        payload には足さず「成功 redirect + エラー不在」の確認だけを採用する。
     7. `expect($user->fresh()->hasPassword())->toBeTrue()` かつ
        `actingAs($user->fresh())->get('/recent-auth/confirm')` の props が
        **`passwordSet=true` / `canSatisfy=true`**
   - この 1 本が「回復手順の終端」（ログアウト着地 → reset → password 取得 → `canSatisfy=true`）を
     サーバ側で保証する。画面上の導線（Welcome の「ログイン」/ Login の「パスワードをお忘れの方」）は
     既存の `tests/js/pages/Welcome.test.ts:120` / `Login.test.ts:26-40` が保証しており、
     **回復経路はこれらのテスト群全体で担保される**（1 本で完結するとは書かない。Codex R4 Suggestion）。
7. 既存 `tests/js/pages/Login.test.ts` の「register / forgot-password への導線」は**変更しない**（回帰の基準。
   `/login` は guest 画面なので `/forgot-password` へのリンクは正しい）
8. 既存 `tests/js/pages/Welcome.test.ts:120`（guest nav の「ログイン」リンク）を**依存契約として維持**
   （B-2 のログアウト後着地から `/login` へ辿れることの担保。変更しない）

### 受け入れ条件 (DoD)

- `AuthLayout` を import する全ページ（allowlist の `Auth/VerifyEmail` を除く）が footer に
  `TextLink` の離脱導線を持ち、architecture テストが green。
- allowlist の健全性（reason 非空 / 実在 / AuthLayout ページであること）がテストで固定されている。
- `ResetPassword` は `errors.email` があるときも `/forgot-password` `/login` への導線を出す。
- `ConfirmRecentAuth` の `canSatisfy=false` 分岐に `/forgot-password` へのリンクが**無く**、
  代わりに実際に踏破できる回復手順（ログアウト）が提示されている。
- 案内した回復手順が**端まで成立する**ことが Feature テストで固定されている
  （password 未設定かつ利用可能な再認証 provider が無いユーザーが、ログアウト → リセットリンク →
  パスワード設定 → `canSatisfy=true` に到達できる）。
- CTA のラベルが実際の着地と一致している（「ログアウトする」= ログアウトのみを行う）。
- `DESIGN.md` に 2 規約が記載されている。
- 新しい行き止まり・新しい踏破不能リンクを増やしていない（各リンク先の到達可能性を上表の根拠で説明できる）。

### リスク

| リスク | 緩和 |
|---|---|
| footer リンク先が別の罠になる（例: 未検証ユーザーを `/dashboard` へ送る） | リンク先は「その画面のユーザーの認証状態で到達できる先」に限定し、根拠を設計に明記。`VerifyEmail`（未検証状態）には**リンクを足さない**（allowlist で本文のログアウトを離脱導線と認める） |
| architecture テストの正規表現が footer を誤検出する | 既存ヘルパ（コメント除去 + import 識別子解決）を再利用し、footer 本体を抽出してから `TextLink` を探す。alias import にも対応 |
| allowlist が将来の抜け道になる | `reason` 非空を別 it で強制（既存 `PAGECONTENT_ALLOWLIST` と同方式）。エントリは現時点で 1 件のみ |
| Debug/Login.svelte が対象に入る | 既に footer（`/login`）を持つため追加変更不要。local 専用画面だが規約対象のままにする（例外を作らない） |
| `ConfirmRecentAuth` の CTA 差し替えで既存 Feature テストが落ちる | `RecentAuthTest` は props（`passwordSet` / `canSatisfy` / `availableProviders`）しか見ておらず、CTA の DOM を assert していない（確認済み）。サーバ側の契約は不変 |

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
3. 施策 B の architecture テスト（footer 契約 + allowlist 健全性）を書き、
   **`ResetPassword` / `TwoFactorChallenge` / `ConfirmRecentAuth` の 3 ページで fail することを確認**
4. 施策 B のページ別 vitest を書き、fail を確認 → footer 実装 → green
5. 施策 B-2 の Feature テスト（`RecentAuthTest` 追記 2 本 + `RecentAuthPasswordRecoveryTest`）を書き、
   **`canSatisfy=false` 分岐の CTA 差し替え前でも通る（サーバ挙動の固定）ことを確認** →
   `ConfirmRecentAuth.svelte` の CTA を差し替え → vitest で `/forgot-password` リンク不在を green にする
6. `DESIGN.md` / `docs/architecture.md` 追記
7. `composer test` / `composer phpstan` / `vendor/bin/pint --test` / `pnpm lint` / `pnpm typecheck` /
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
