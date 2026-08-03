# 実装レビュー依頼: T092 認証ファネルの離脱導線 (auth-funnel-exits) Round 1

【アプリの使命 (North Star) — AGENTS.md より】

**AI-CUE** は、現場に既にある**作業手順書(SOP)を起点に**、AI が撮るべきカットを設計した**動画シナリオ**を生成し、そのシナリオを**スマホ(PWA)でナビゲーション撮影**することで、専門知識ゼロの現場作業者でも**標準化されたマニュアル動画**を作れるようにする。

- 「思考ゼロ・編集ゼロ」— 台本作成・撮影判断・編集の 3 ハードルを AI とナビ撮影で肩代わりする。
- 競合(OJT を撮って形式化する tebiki)と異なり、**標準作業を起点に AI が教材設計し撮影を指示する**（撮影者・教える人のスキルに品質を依存させない）。
- 熟練者の暗黙知を動画マニュアルという形式知へ変換する装置（SECI）。

> **v1 スコープ**: 字幕のみ(TTS 後回し) / 撮影は PWA(同一オリジン・セッション認証) / 動画合成は自前 ffmpeg / 単一 Default Project。

【禁止事項 — AGENTS.md より (自分・レビュアー双方に適用)】

1. テストなしの実装完了報告(不変条件は対応する Architecture/Feature テストへの登録まで含めて「実装済み」)
2. PHPStan エラーの widen(型を緩めて黙らせる)・baseline 化
3. dev DB への破壊操作(`migrate:fresh` 等)をエージェント判断で実行すること
4. `response()->json()` の直書き(DTO / JsonResource / Inertia を使う。仕様固定 endpoint のみ例外)
5. LLM 呼び出しの Prism 直呼び(`app/Prompts/` の factory 経由のみ。PromptGuardrailTest が検出)
6. prompt 文字列のコード直書き(`resources/prompts/*.yaml` に置く)
7. 操作系 POST の応答での `redirect()->intended()`(ログイン直後フロー専用。招待送信等は `back()->with(...)` で完結させる)
8. 必須条件未充足を理由にボタンを disabled にする UI(押下時にエラー表示する。DESIGN.md)
9. Artifact の使用(Artifact ツールでの成果物公開を行わない。成果物はリポジトリ内のファイルとして出力する)

【思考原則 — 全議論に適用】
まず仮説を立てろ。何を検証したいのか、なぜそう考えるのか、どうなれば成功と判断するのかを明確にしてから手を動かせ。仮説なき改善はただの試行錯誤であり、結果から学ぶことができない。

データに真摯に向き合え。成果だけでなく、多様性の変化、構造の揺らぎ、想定外のパターン — 全てが判断材料になる。数値を見て即座に閾値を弄るな。何が起きているのかを理解し、なぜそうなったのかを考え、どの方向に進むべきかを判断してから手を動かせ。

先人の知恵を探せ。自分たちだけで登る必要はない。乗るべき巨人の肩があるなら乗れ。

機能の名前に立ち返れ。名前はその機能が果たすべき役割を示している。現在の設計がその役割を果たしているか、常に問え。

仕組みが機能していない段階で値を弄るな。閾値チューニングやフィールド追加は、設計の方向性が正しいと確認できてから行え。方向性が間違っているなら、値をいくら調整しても意味はない。設計そのものを見直せ。成果が出なければ早期に見切り、次の仮説へ進め。

【ツール使用制限】
コマンド実行・ファイル書き込みは一切行わず、提供されたテキストの分析に集中すること。ファイル読み込みは許可。

---

## あなたの役割

Laravel 12 + Svelte 5 (runes) + Inertia.js の改善実装をレビューするコードレビュアー。

### レビュー観点

1. **設計との一致性**: 詳細設計書の施策 A / B の意図どおり実装されているか。設計から外れた箇所は妥当な理由があるか
2. **正確性**: ロジックの誤り、境界条件、リグレッション
3. **PHPStan level 10 適合性**: 型の緩め・ignore の混入がないか
4. **DTO / JsonResource パターン**: `response()->json()` 直書きが無いか (本 diff は Inertia props のみのはず)
5. **テスト網羅性**: 不変条件が Architecture / Feature テストへ登録されているか。テストが「実装の写経」でなく振る舞いを固定しているか。回帰検知が実装詳細 (testId 等) に過剰依存していないか
6. **セキュリティ**: 認証ゲート (verified / guest / auth) を緩めていないか。IDOR 防御 (membership 確認) の単一出典が保たれているか。open-redirect 面を増やしていないか
7. **DESIGN.md 準拠**: color / radius / typography は design token 経由か (hex 直書きを増やさない)。リンクは `TextLink` atom 経由で素の `<a>` を書いていないか。form には `novalidate`。必須条件未充足を理由に disabled にしていないか
8. **Atomic Design 準拠**: `atoms → molecules → organisms → features → templates → pages` の単方向 import。atom は単機能・状態を持たない。アイコンは `@lucide/svelte` のみで SVG 直書きを増やさない

### 出力形式

ファイルごとに判定を述べたうえで、指摘を **[Critical] / [Warning] / [Suggestion]** に分類して列挙すること。
最後に全体判定を **APPROVED** または **CHANGES_REQUESTED** で明記すること。

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

---

## 実装コンテキスト (第 1 波のマージ済み変更との共存)

本 worktree は T089 / T090 / T091 / T094 がマージ済みの main から切られている。設計書執筆時点との差分:

- T089: `tests/js/architecture/logout-call-site-inventory.test.ts` が **deny-by-default で `/logout` 呼び出し箇所の inventory を固定**している (Inertia history 暗号化の経路 C の保証条件)。本実装で `ConfirmRecentAuth.svelte` に `router.post("/logout")` を足したため inventory へ登録し `docs/supported-browsers.md` の 2 箇所→3 箇所の記述も更新した (設計書には無い波及)。
- T094: Auth 配下の全 `<form>` に `novalidate` が付与済み。触った 4 ページはいずれもその状態を保っている。
- `DESIGN.md` には T094 が入力 UX 規約を追記済み。本実装の Do / Don't 追記は既存節を壊さず追加している。

## 検証結果 (worktree 内で実行済み・全 green)

- `composer test`: 2602 tests, 2600 passed, 2 skipped, 10478 assertions
- `composer phpstan` (level 10): No errors
- `vendor/bin/pint --test`: passed
- `pnpm lint` / `pnpm typecheck`: エラーなし
- `pnpm test` (vitest): 103 files / 934 tests passed
- `pnpm build`: 成功

## design system 参照 (DESIGN.md の関連抜粋)

### TextLink (atoms)
リンク風 `<a>` / `<button>` の手書きは禁止、本 atom を使う。3 モードの discriminated union:
(a) `href` のみ = Inertia Link(SPA 遷移)、(b) `href` + `external` = ネイティブ `<a>` + 別タブ + `rel="noopener noreferrer"`、(c) `onclick` のみ = リンク風 `<button type="button">`。
様式は `text-primary` + 下線で 3 モード共通。

### AuthLayout (templates)
認証画面用レイアウト。`title` / `appName` / `children` / **`footer?: Snippet`** (カード下部の補助導線) を受ける。footer は `mt-4 text-center text-caption text-text-secondary` の div 内で render される。

### 触れた atomic ディレクトリ
- `resources/js/pages/Auth/{VerifyEmail,ResetPassword,TwoFactorChallenge,ConfirmRecentAuth}.svelte` (pages 層)
- 参照した atom: `components/atoms/{Button,TextLink,FormError}.svelte`、molecule: `{FormField,PasswordInput,Divider,Tabs}.svelte`、template: `templates/AuthLayout.svelte`
- 新規 component の追加は無し (pages 層のみの変更)

---

## 実装差分 (git diff HEAD)

```diff
diff --git a/DESIGN.md b/DESIGN.md
index cfe7cc6..09aea24 100644
--- a/DESIGN.md
+++ b/DESIGN.md
@@ -459,6 +459,11 @@ ## Do's and Don'ts
 - 背景は常に neutral、浮いた要素は surface(逆に使わない)
 - 余白を多めにとる。色は Primary / Tertiary / 状態色 1 種までを目安に
 - 操作の可否は**押した後のフィードバック**で伝える(バリデーションエラー表示+フォーカス移動)
+- **認証フロー画面(`AuthLayout`)には離脱導線を footer に必ず置く**。その手順を完了できない
+  ユーザー(リンク期限切れ・コード紛失・再認証手段なし)が別の入口へ抜けられる `TextLink` を
+  `{#snippet footer()}` に 1 つ以上持つ。行き先は**その画面のユーザーの認証状態で実際に
+  踏破できる先**に限る(`tests/js/architecture/page-shell-structure.test.ts` が機械強制。
+  例外は理由付き allowlist)
 
 **Don't**
 
@@ -467,6 +472,10 @@ ## Do's and Don'ts
 - **必須条件未充足を理由にボタンを disabled でブロックしない**。ボタンは活性のまま、
   押下時に何が足りないかをエラー表示する(例: 利用規約同意チェック。
   disabled はユーザーに「なぜ押せないか」を伝えられない)
+- **表示条件と踏破条件が食い違う導線を出さない**。押しても必ず失敗するボタン・リンク
+  (認証・権限・ゲートで確実に弾かれる先を指すもの)は**出さずに、なぜ今は進めないかを
+  文章で説明する**。disabled 化でも代替しない(上の Don't と同根。例: メール未認証画面から
+  `verified` ゲート内の checkout へ進む CTA)
 - ページ内で素の `<input>` / `<table>` / リンク風 `<a>` 手書きをしない(対応する atom/molecule を使う)
 - **native の constraint validation に検証を任せない**。`<form>` には `novalidate` を付け、
   検証文言はサーバ(日本語)と押下時の client エラーに一本化する。
diff --git a/app/Providers/FortifyServiceProvider.php b/app/Providers/FortifyServiceProvider.php
index c258281..da71856 100644
--- a/app/Providers/FortifyServiceProvider.php
+++ b/app/Providers/FortifyServiceProvider.php
@@ -235,10 +235,12 @@ private function configureViews(): void
         Fortify::verifyEmailView(static function (Request $request): InertiaResponse {
             $user = $request->user();
 
-            // 登録由来の継続導線 (「あとで認証する」)。session には組織 id のみ保持し、
-            // membership 確認を通ったときだけ URL 化する (IDOR 防御)。
+            // 認証前に onboarding.checkout へ進ませる CTA は出さない (route は
+            // ['auth','verified'] 配下 = 未認証は必ず差し戻される)。継続が実在するときだけ
+            // 「認証後にプラン選択へ進む」ことを予告する説明を出す (bug-hunt F-2-01)。
+            // service は継続の有無までを返し、画面語彙への写像はここで行う。
             return Inertia::render('Auth/VerifyEmail', [
-                'continueUrl' => EmailVerificationContinuation::resolveUrl(
+                'continuesToCheckout' => EmailVerificationContinuation::hasContinuation(
                     $user instanceof User ? $user : null,
                     $request->session(),
                 ),
diff --git a/app/Support/Auth/EmailVerificationContinuation.php b/app/Support/Auth/EmailVerificationContinuation.php
index ddff982..ac01676 100644
--- a/app/Support/Auth/EmailVerificationContinuation.php
+++ b/app/Support/Auth/EmailVerificationContinuation.php
@@ -8,13 +8,18 @@
 use Illuminate\Contracts\Session\Session;
 
 /**
- * 登録 → verify notice ソフトゲートの「あとで認証する」継続導線。
+ * 登録 → verify notice → **認証完了後**に checkout へ着地させる継続導線。
  *
  * 生 URL を session に持たず organization_id のみ保持し、参照時に route を再構築 +
  * membership 確認する (URL 直保持はルート変更・値汚染に脆い)。所属確認 (relation 経由
- * fetch、IDOR 防御規約) を通らない値は null = 導線を出さない。
+ * fetch、IDOR 防御規約) を通らない値は null = 継続なし。
  * 寿命: remember (登録時) → forget (verify 完了時)。
  *
+ * URL を実際に使うのは `VerifyEmailResponse` (認証完了後の着地) だけである。
+ * verify notice 画面へは URL を渡さない — `onboarding.checkout` は ['auth','verified']
+ * 配下にあり、未認証で遷移させると必ず差し戻されるため (bug-hunt F-2-01)。
+ * 画面へは `hasContinuation()` の bool だけを渡す。
+ *
  * AI-CUE の onboarding route は current-org スコープ (route parameter なし) のため、
  * 再構築するのは引数なしの `route('onboarding.checkout')`。session に保持した
  * organization_id は「その組織のメンバーであること」の確認にのみ使う。
@@ -51,6 +56,16 @@ public static function resolveUrl(?User $user, Session $session): ?string
         return route('onboarding.checkout');
     }
 
+    /**
+     * 継続導線が実在するか (URL を露出せず有無だけを返す)。
+     * membership 確認の単一出典を保つため resolveUrl() へ委譲する。
+     * 「どの画面へ進むか」という UI 語彙はここに持ち込まない (呼び出し側が写像する)。
+     */
+    public static function hasContinuation(?User $user, Session $session): bool
+    {
+        return self::resolveUrl($user, $session) !== null;
+    }
+
     public static function forget(Session $session): void
     {
         $session->forget(self::SESSION_KEY);
diff --git a/docs/architecture.md b/docs/architecture.md
index 6f4d2d7..754ee09 100644
--- a/docs/architecture.md
+++ b/docs/architecture.md
@@ -286,6 +286,16 @@ ## サブスク契約 Checkout とオンボーディング着地 (P7/P9) の運
 - **`?plan=` handoff (P7)**: `IntendedPlanResolver` が org スコープ session へ積み、canonical URL
   へ 303 する (再読込・共有時に query が残らない)。以降は peek = 消費しない (リロード耐性)。
   Enterprise / 未知値は正規化で null に倒れる (Checkout を通らないプランを選ばせない)。
+- **`onboarding.checkout` はメール認証済みが前提** (`['auth','verified']` group 配下)。
+  未検証メールのまま到達できると `PersonalPlanService::activate()` の無料チケット付与と
+  Stripe Checkout の入口が開き、使い捨てアドレスで無料枠を刈れるため、この配置は意図的である
+  (`OnboardingCheckoutEmailVerificationGuardTest` が固定)。
+  したがって **verify notice 画面 (`Auth/VerifyEmail`) に checkout へ進む CTA は置かない** —
+  表示条件 (membership) と踏破条件 (verified) が食い違う恒常的に無効な導線になる
+  (bug-hunt F-2-01)。プラン意図の継続は認証**後**に `VerifyEmailResponse` が
+  `EmailVerificationContinuation::resolveUrl()` で解決して着地させる。画面へ渡すのは
+  URL ではなく `continuesToCheckout` (継続の有無) のみで、認証後の着地を予告する文言に使う。
+  認証前にプランを見たい需要は公開面 (`/pricing`) が満たす。
 - **契約 Checkout の冪等状態機械 (P9)**: `SubscriptionService::startCheckout()` は
   `attempt_token` 冪等マシン (段 0 事前 assert → 1 既存 subscription guard → 2 同 token 行 →
   3 同 plan の live pending dedup (org-wide) → 4 別 plan の live pending を expire →
diff --git a/docs/supported-browsers.md b/docs/supported-browsers.md
index 3a31ad9..d93484b 100644
--- a/docs/supported-browsers.md
+++ b/docs/supported-browsers.md
@@ -16,7 +16,8 @@ # サポート対象ブラウザ方針
 `Inertia::clearHistory()` はサーバ session にフラグを積むだけで、`sessionStorage` の
 履歴暗号鍵が実際に消えるのは `page.set()` 冒頭の `history.clear()` が走った瞬間だからである
 (受信ではなく適用。通信断や JS 例外で適用前に中断すれば鍵は残る)。
-アプリの `/logout` 導線は 2 箇所 (`AppLayout.svelte` / `pages/Auth/VerifyEmail.svelte`) で
+アプリの `/logout` 導線は 3 箇所 (`AppLayout.svelte` / `pages/Auth/VerifyEmail.svelte` /
+`pages/Auth/ConfirmRecentAuth.svelte`) で
 いずれも `router.post` = Inertia visit のため、正常完了時にこの条件を満たす
 (この不変条件は `tests/js/architecture/logout-call-site-inventory.test.ts` が固定する)。
 **ログアウト導線を非 Inertia 経路 (JSON 204 で完結する XHR 等) で新設すると、
@@ -109,7 +110,7 @@ ## 未対応事項 (誤読を防ぐため明示列挙する)
 - **経路 C は「`clearHistory: true` を含む Inertia page をクライアントが適用したタブ」のみを保証する**
   (受信ではなく適用)。JSON 204 で完結するログアウト (Fortify 既定の `wantsJson()` 分岐) では、
   次の Inertia page を適用するまでクライアントの履歴暗号鍵は残る。
-  現行の `/logout` 導線は 2 箇所ともに Inertia visit のため実運用では条件を満たすが、
+  現行の `/logout` 導線は 3 箇所ともに Inertia visit のため実運用では条件を満たすが、
   非 Inertia のログアウト導線を新設すると保証が外れる
   (`tests/js/architecture/logout-call-site-inventory.test.ts` が deny-by-default で固定)。
 - **上記を満たしたタブ以外は保証外**。Inertia の履歴暗号鍵は
diff --git a/resources/js/pages/Auth/ConfirmRecentAuth.svelte b/resources/js/pages/Auth/ConfirmRecentAuth.svelte
index 1594b2b..051170c 100644
--- a/resources/js/pages/Auth/ConfirmRecentAuth.svelte
+++ b/resources/js/pages/Auth/ConfirmRecentAuth.svelte
@@ -1,7 +1,8 @@
 <script lang="ts">
-    import { useForm } from "@inertiajs/svelte";
+    import { router, useForm } from "@inertiajs/svelte";
     import Button from "@/components/atoms/Button.svelte";
     import FormError from "@/components/atoms/FormError.svelte";
+    import TextLink from "@/components/atoms/TextLink.svelte";
     import Divider from "@/components/molecules/Divider.svelte";
     import FormField from "@/components/molecules/FormField.svelte";
     import PasswordInput from "@/components/molecules/PasswordInput.svelte";
@@ -15,7 +16,9 @@
      * intended URL へ戻る (server 側 redirect()->intended)。
      * - password 設定済みユーザー: password 再入力フォーム (POST /recent-auth/password)
      * - 再SSO 可能な provider: reauthUrl (/auth/{provider}/redirect/step-up) で再認証
-     * - canSatisfy=false: 回復導線 (パスワードリセット) を案内
+     * - canSatisfy=false: 回復手順 (ログアウト → guest としてパスワード再設定) を案内。
+     *   /forgot-password へ直接リンクしない — Fortify が `guest` middleware 付きで登録しており
+     *   ログイン済みの本画面ユーザーはフォームに到達できない (踏破不能 CTA。bug-hunt F-2-01 と同 species)
      */
     interface Props {
         appName?: string;
@@ -35,10 +38,27 @@
         password: "",
     });
 
+    let loggingOut = $state(false);
+
     function submit(event: SubmitEvent): void {
         event.preventDefault();
         form.post("/recent-auth/password");
     }
+
+    function logout(): void {
+        router.post(
+            "/logout",
+            {},
+            {
+                onStart: () => {
+                    loggingOut = true;
+                },
+                onFinish: () => {
+                    loggingOut = false;
+                },
+            },
+        );
+    }
 </script>
 
 <AuthLayout title="本人確認" {appName}>
@@ -84,10 +104,20 @@
 
     {#if !canSatisfy}
         <div class="mt-6 flex flex-col gap-3 text-caption text-text-secondary">
-            <p>この操作を続けるための再認証手段が設定されていません。パスワードを設定すると再認証できます。</p>
-            <Button href="/forgot-password" variant="ghost" fullWidth>
-                パスワードを設定して再認証する
+            <p>
+                この操作を続けるための再認証手段が設定されていません。
+                いったんログアウトし、ログイン画面の「パスワードをお忘れの方」から
+                パスワードを設定すると再認証できるようになります。
+            </p>
+            <Button variant="ghost" onclick={logout} loading={loggingOut} fullWidth>
+                ログアウトする
             </Button>
         </div>
     {/if}
+
+    {#snippet footer()}
+        <p>
+            <TextLink href="/dashboard">この操作を中止してダッシュボードへ戻る</TextLink>
+        </p>
+    {/snippet}
 </AuthLayout>
diff --git a/resources/js/pages/Auth/ResetPassword.svelte b/resources/js/pages/Auth/ResetPassword.svelte
index 5d41189..749bc4c 100644
--- a/resources/js/pages/Auth/ResetPassword.svelte
+++ b/resources/js/pages/Auth/ResetPassword.svelte
@@ -2,6 +2,7 @@
     import { useForm } from "@inertiajs/svelte";
     import Button from "@/components/atoms/Button.svelte";
     import Input from "@/components/atoms/Input.svelte";
+    import TextLink from "@/components/atoms/TextLink.svelte";
     import FormField from "@/components/molecules/FormField.svelte";
     import PasswordInput from "@/components/molecules/PasswordInput.svelte";
     import AuthLayout from "@/components/templates/AuthLayout.svelte";
@@ -56,4 +57,15 @@
 
         <Button type="submit" loading={form.processing} fullWidth>パスワードをリセット</Button>
     </form>
+
+    {#snippet footer()}
+        <p>
+            リンクの有効期限が切れている場合は
+            <TextLink href="/forgot-password">新しいリセットリンクをリクエスト</TextLink>
+            できます。
+        </p>
+        <p class="mt-1">
+            <TextLink href="/login">ログインに戻る</TextLink>
+        </p>
+    {/snippet}
 </AuthLayout>
diff --git a/resources/js/pages/Auth/TwoFactorChallenge.svelte b/resources/js/pages/Auth/TwoFactorChallenge.svelte
index 3c6d6c4..c526d2e 100644
--- a/resources/js/pages/Auth/TwoFactorChallenge.svelte
+++ b/resources/js/pages/Auth/TwoFactorChallenge.svelte
@@ -2,6 +2,7 @@
     import { useForm } from "@inertiajs/svelte";
     import Button from "@/components/atoms/Button.svelte";
     import Input from "@/components/atoms/Input.svelte";
+    import TextLink from "@/components/atoms/TextLink.svelte";
     import FormField from "@/components/molecules/FormField.svelte";
     import Tabs from "@/components/molecules/Tabs.svelte";
     import AuthLayout from "@/components/templates/AuthLayout.svelte";
@@ -106,4 +107,12 @@
 
         <Button type="submit" loading={form.processing} fullWidth>認証する</Button>
     </form>
+
+    {#snippet footer()}
+        <p>
+            認証コードもリカバリコードも使えない場合は
+            <TextLink href="/login">ログインをやり直す</TextLink>
+            か、組織の管理者に 2 要素認証のリセットを依頼してください。
+        </p>
+    {/snippet}
 </AuthLayout>
diff --git a/resources/js/pages/Auth/VerifyEmail.svelte b/resources/js/pages/Auth/VerifyEmail.svelte
index 5a259a5..e29ed0a 100644
--- a/resources/js/pages/Auth/VerifyEmail.svelte
+++ b/resources/js/pages/Auth/VerifyEmail.svelte
@@ -6,13 +6,14 @@
     interface Props {
         appName?: string;
         /**
-         * 登録由来の継続導線 (プラン選択へ進む)。サーバが membership 確認を通ったときだけ
-         * 非 null で届く。null のときは二次 CTA を出さない。
+         * 登録由来の継続 (認証完了後に onboarding.checkout へ着地する) が実在するか。
+         * true のときだけ「認証後にプラン選択へ進む」ことを予告する。
+         * 認証前に checkout へ遷移する CTA は出さない (verified ゲートで必ず弾かれるため)。
          */
-        continueUrl?: string | null;
+        continuesToCheckout: boolean;
     }
 
-    let { appName, continueUrl = null }: Props = $props();
+    let { appName, continuesToCheckout }: Props = $props();
 
     const form = useForm({});
 
@@ -46,18 +47,14 @@
         メールが届かない場合は、再送信できます。
     </p>
 
+    {#if continuesToCheckout}
+        <p class="mb-6 text-body text-text-secondary" data-testid="verify-email-checkout-note">
+            メール認証が完了すると、そのままプラン選択に進みます。
+        </p>
+    {/if}
+
     <form novalidate onsubmit={resend} class="flex flex-col gap-3">
         <Button type="submit" loading={form.processing} fullWidth>認証メールを再送信</Button>
-        {#if continueUrl !== null}
-            <Button
-                variant="ghost"
-                onclick={() => router.visit(continueUrl)}
-                fullWidth
-                testId="verify-email-continue"
-            >
-                あとで認証する（プラン選択へ進む）
-            </Button>
-        {/if}
         <Button variant="ghost" onclick={logout} loading={loggingOut} fullWidth>
             ログアウト
         </Button>
diff --git a/tests/Feature/Auth/RecentAuthPasswordRecoveryTest.php b/tests/Feature/Auth/RecentAuthPasswordRecoveryTest.php
new file mode 100644
index 0000000..50d603a
--- /dev/null
+++ b/tests/Feature/Auth/RecentAuthPasswordRecoveryTest.php
@@ -0,0 +1,72 @@
+<?php
+
+declare(strict_types=1);
+
+use App\Models\User;
+use Illuminate\Auth\Notifications\ResetPassword;
+use Illuminate\Support\Facades\Http;
+use Illuminate\Support\Facades\Notification;
+
+/*
+ * ConfirmRecentAuth (canSatisfy=false) が案内する回復手順が **端まで成立する** ことの固定。
+ *
+ * 「再認証手段が無い」ユーザーに提示できるのは「いったんログアウトし、guest として
+ * パスワードを再設定する」経路だけである (アプリ内でパスワードを設定する経路は無い:
+ * UpdateUserPassword は current_password 必須、/forgot-password は guest middleware 付き)。
+ * 案内はあるが実際にはできない、という F-2-01 型の再発を防ぐため、
+ * ログアウト着地 → リセットリンク → パスワード設定 → canSatisfy=true までを 1 本で通す。
+ *
+ * 画面上の導線 (Welcome の「ログイン」/ Login の「パスワードをお忘れの方」) は
+ * tests/js/pages/Welcome.test.ts / Login.test.ts が保証しており、回復経路は
+ * これらのテスト群**全体**で担保される (本テスト 1 本で完結するわけではない)。
+ */
+test('再認証手段が無いユーザーはログアウト後にパスワードを設定でき、再認証可能になる', function (): void {
+    // PasswordPolicy の HIBP 照会を止める (外部依存をテストに持ち込まない)
+    Http::fake(['https://api.pwnedpasswords.com/range/*' => Http::response('', 200)]);
+    Notification::fake();
+
+    $user = User::factory()->ssoOnly()->create();
+    $email = $user->email;
+
+    // 1. 出発点: 再認証手段が無い (= 本画面が回復手順を案内する状態)
+    $this->actingAs($user)->get('/recent-auth/confirm')
+        ->assertOk()
+        ->assertInertia(fn ($page) => $page->where('canSatisfy', false));
+
+    // 2. 案内どおりログアウトする。Fortify 既定 (Fortify::redirects('logout')) で `/` = Welcome へ
+    //    着地し、そこには guest nav の「ログイン」リンクが常時ある (Welcome.test.ts が固定)。
+    $logout = $this->post('/logout');
+    $logout->assertRedirect('/');
+    $this->assertGuest();
+
+    // 3. guest としてリセットリンクを要求する (ログイン済みでは guest ゲートに阻まれる経路)
+    $this->post('/forgot-password', ['email' => $email])->assertSessionHasNoErrors();
+
+    $token = null;
+    Notification::assertSentTo($user, ResetPassword::class, function (ResetPassword $notification) use (&$token): bool {
+        $token = $notification->token;
+
+        return true;
+    });
+    expect($token)->toBeString();
+
+    // 4. パスワードを設定する。ResetUserPassword は confirmed を使わない (確認入力フィールドは無い)
+    $response = $this->post('/reset-password', [
+        'token' => $token,
+        'email' => $email,
+        'password' => 'CorrectHorse9Battery',
+    ]);
+    $response->assertRedirect(route('login'));
+    $response->assertSessionHasNoErrors();
+
+    // 5. 到達点: password が設定され、再認証できる状態になっている
+    $fresh = $user->fresh();
+    expect($fresh)->not->toBeNull()
+        ->and($fresh?->hasPassword())->toBeTrue();
+
+    $this->actingAs($fresh)->get('/recent-auth/confirm')
+        ->assertOk()
+        ->assertInertia(fn ($page) => $page
+            ->where('passwordSet', true)
+            ->where('canSatisfy', true));
+});
diff --git a/tests/Feature/Auth/RecentAuthTest.php b/tests/Feature/Auth/RecentAuthTest.php
index 2c0557e..95abd6f 100644
--- a/tests/Feature/Auth/RecentAuthTest.php
+++ b/tests/Feature/Auth/RecentAuthTest.php
@@ -169,6 +169,34 @@ function linkGoogleAccount(User $user, string $providerUserId): void
             ->where('availableProviders.0.reauthUrl', route('social.redirect', ['provider' => 'google', 'intent' => 'step-up'])));
 });
 
+test('password 未設定かつ利用可能な再認証 provider が無いユーザーは canSatisfy=false', function (): void {
+    // 「SSO 専用ユーザー」ではなく「password 未設定 かつ 利用可能な再認証 provider なし」という
+    // **状態**。provider が生きている通常の SSO ユーザー (canSatisfy=true) と混同しない。
+    // この状態の confirm 画面が案内する回復手順は RecentAuthPasswordRecoveryTest が端まで固定する。
+    $user = User::factory()->ssoOnly()->create();
+
+    $this->actingAs($user)->get('/recent-auth/confirm')
+        ->assertOk()
+        ->assertInertia(fn ($page) => $page
+            ->component('Auth/ConfirmRecentAuth')
+            ->where('passwordSet', false)
+            ->where('availableProviders', [])
+            ->where('canSatisfy', false));
+});
+
+test('ログイン済みユーザーは GET /forgot-password のフォームに到達できない (guest ゲート)', function (): void {
+    // Fortify は /forgot-password を `guest` middleware 付きで登録している。認証済み画面
+    // (ConfirmRecentAuth 等) から /forgot-password へリンクすると RedirectIfAuthenticated に
+    // 弾かれてフォームに到達しない = 踏破不能 CTA になる、という根拠を仕様として固定する。
+    // redirect 先は RedirectIfAuthenticated::defaultRedirectUri() 依存のため pin しない。
+    $user = User::factory()->create();
+
+    $response = $this->actingAs($user)->get('/forgot-password');
+
+    expect($response->isRedirect())->toBeTrue();
+    $response->assertStatus(302);
+});
+
 test('status は鮮度と satisfier 情報を返す (no-store)', function (): void {
     $user = User::factory()->create();
 
diff --git a/tests/Feature/Auth/RegisterVerifyFlowTest.php b/tests/Feature/Auth/RegisterVerifyFlowTest.php
index c2a7303..5773c27 100644
--- a/tests/Feature/Auth/RegisterVerifyFlowTest.php
+++ b/tests/Feature/Auth/RegisterVerifyFlowTest.php
@@ -10,11 +10,15 @@
 use Inertia\Testing\AssertableInertia;
 
 /**
- * P7: メール/パスワード登録 → verification.notice ソフトゲート。
+ * P7: メール/パスワード登録 → verification.notice。
  *
  * EmailVerificationContinuation が session に personal org id を保持し、
- * /email/verify の二次 CTA (continueUrl) と verify 完了後の checkout 復帰を支える。
- * session 値は membership 確認 (relation 経由 fetch) を通らない限り URL 化されない。
+ * verify 完了後の checkout 復帰を支える。
+ * session 値は membership 確認 (relation 経由 fetch) を通らない限り継続として成立しない。
+ *
+ * 認証**前**に onboarding.checkout へ進む CTA は出さない (bug-hunt F-2-01)。
+ * route が ['auth','verified'] 配下 = 未認証は必ず差し戻されるため、画面へは
+ * URL を渡さず「継続が実在するか」だけを continuesToCheckout として渡す。
  */
 beforeEach(function (): void {
     Http::fake(['https://api.pwnedpasswords.com/range/*' => Http::response('', 200)]);
@@ -47,7 +51,7 @@
     expect(session('verify_continue_organization_id'))->toBe($personalOrg->id);
 });
 
-test('登録後の /email/verify GET は continueUrl に onboarding.checkout を返す', function (): void {
+test('登録後の /email/verify GET は continuesToCheckout=true を返し URL を渡さない', function (): void {
     Notification::fake();
     $payload = ($this->validPayload)();
     $this->post('/register', $payload);
@@ -56,10 +60,11 @@
         ->assertOk()
         ->assertInertia(fn (AssertableInertia $page) => $page
             ->component('Auth/VerifyEmail')
-            ->where('continueUrl', route('onboarding.checkout')));
+            ->where('continuesToCheckout', true)
+            ->missing('continueUrl'));
 });
 
-test('continuation なしで /email/verify を直接開くと continueUrl は null', function (): void {
+test('continuation なしで /email/verify を直接開くと continuesToCheckout は false', function (): void {
     $user = User::factory()->unverified()->create();
 
     $this->actingAs($user)
@@ -67,10 +72,11 @@
         ->assertOk()
         ->assertInertia(fn (AssertableInertia $page) => $page
             ->component('Auth/VerifyEmail')
-            ->where('continueUrl', null));
+            ->where('continuesToCheckout', false)
+            ->missing('continueUrl'));
 });
 
-test('session に他人の organization id が混入しても continueUrl は null (membership 確認)', function (): void {
+test('session に他人の organization id が混入しても continuesToCheckout は false (membership 確認)', function (): void {
     $otherOrg = Organization::factory()->create();
     $user = User::factory()->unverified()->create();
 
@@ -78,17 +84,21 @@
         ->withSession(['verify_continue_organization_id' => $otherOrg->id])
         ->get('/email/verify')
         ->assertOk()
-        ->assertInertia(fn (AssertableInertia $page) => $page->where('continueUrl', null));
+        ->assertInertia(fn (AssertableInertia $page) => $page
+            ->where('continuesToCheckout', false)
+            ->missing('continueUrl'));
 });
 
-test('session 値が int でない場合は continueUrl は null (値汚染防御)', function (): void {
+test('session 値が int でない場合は continuesToCheckout は false (値汚染防御)', function (): void {
     $user = User::factory()->unverified()->create();
 
     $this->actingAs($user)
         ->withSession(['verify_continue_organization_id' => 'not-an-int'])
         ->get('/email/verify')
         ->assertOk()
-        ->assertInertia(fn (AssertableInertia $page) => $page->where('continueUrl', null));
+        ->assertInertia(fn (AssertableInertia $page) => $page
+            ->where('continuesToCheckout', false)
+            ->missing('continueUrl'));
 });
 
 test('verify 完了で onboarding.checkout へ redirect し continuation が消える', function (): void {
diff --git a/tests/Feature/Onboarding/OnboardingCheckoutEmailVerificationGuardTest.php b/tests/Feature/Onboarding/OnboardingCheckoutEmailVerificationGuardTest.php
new file mode 100644
index 0000000..a1687f1
--- /dev/null
+++ b/tests/Feature/Onboarding/OnboardingCheckoutEmailVerificationGuardTest.php
@@ -0,0 +1,48 @@
+<?php
+
+declare(strict_types=1);
+
+use App\Models\User;
+
+/*
+ * bug-hunt F-2-01 の根本原因を「仕様」として固定する回帰テスト。
+ *
+ * `/onboarding/checkout` は routes/web.php の `['auth','verified']` group 配下にあり、
+ * メール未認証ユーザーは Laravel 標準の `verified` middleware により必ず
+ * `verification.notice` へ差し戻される。これは意図した配置である
+ * (未検証メールで Personal 無料枠付与 / Stripe Checkout の入口へ到達させない)。
+ *
+ * したがって verify notice 画面から `onboarding.checkout` へ進む CTA を出してはならない
+ * (表示条件と踏破条件が食い違う = 恒常的に失敗する導線になる)。本テストはその
+ * 「出してはならない根拠」をサーバ側の事実として固定する。
+ */
+
+test('メール未認証ユーザーの GET /onboarding/checkout は verification.notice へ差し戻される', function (): void {
+    [, $owner] = createOrganizationWithOwner(grandfatherFreePlan: false);
+    // email_verified_at は $fillable 外の状態キーのため forceFill で明示代入する
+    $owner->forceFill(['email_verified_at' => null])->save();
+
+    $this->actingAs($owner->fresh())
+        ->get('/onboarding/checkout')
+        ->assertRedirect(route('verification.notice'));
+});
+
+test('認証済み owner は GET /onboarding/checkout に到達できる (ゲートを締めすぎていない)', function (): void {
+    [, $owner] = createOrganizationWithOwner(grandfatherFreePlan: false);
+
+    expect($owner->hasVerifiedEmail())->toBeTrue();
+
+    $this->actingAs($owner)
+        ->get('/onboarding/checkout')
+        ->assertOk();
+});
+
+test('未認証ユーザーは verify notice 画面へ着地し状況説明を受け取る (行き先のない詰みにしない)', function (): void {
+    $user = User::factory()->unverified()->create();
+
+    $this->actingAs($user)
+        ->followingRedirects()
+        ->get('/onboarding/checkout')
+        ->assertOk()
+        ->assertInertia(fn ($page) => $page->component('Auth/VerifyEmail'));
+});
diff --git a/tests/Unit/Support/Auth/EmailVerificationContinuationTest.php b/tests/Unit/Support/Auth/EmailVerificationContinuationTest.php
index de3195d..789f7df 100644
--- a/tests/Unit/Support/Auth/EmailVerificationContinuationTest.php
+++ b/tests/Unit/Support/Auth/EmailVerificationContinuationTest.php
@@ -8,10 +8,13 @@
 use Illuminate\Contracts\Session\Session;
 
 /**
- * P7: 登録 → verify notice ソフトゲートの継続導線 (session に org id のみ保持) の Unit テスト。
+ * P7: 登録 → verify notice の継続導線 (session に org id のみ保持) の Unit テスト。
  *
  * URL を直保持せず、参照時に membership 確認 → 引数なし route('onboarding.checkout') を再構築する
  * (IDOR 防御 = セキュリティ不変条件 #2 / #3)。
+ *
+ * hasContinuation() は「継続の有無」だけを返す派生 API で、判定条件は resolveUrl() の
+ * 単一出典に委譲する (membership 確認を二重実装しない)。
  */
 beforeEach(function (): void {
     app('session.store')->flush();
@@ -87,3 +90,63 @@
 
     expect(EmailVerificationContinuation::resolveUrl($stranger, $session))->toBeNull();
 });
+
+/* ------------------------------------------------ hasContinuation (有無だけを返す派生 API) */
+
+/**
+ * 各シナリオは「setup を行い [user, session] を返す closure」と「継続が実在するか」の組。
+ * hasContinuation が将来 resolveUrl と別の条件を持ち始めたら同値性 assert が落ちる。
+ */
+dataset('継続の有無シナリオ', [
+    'remember 済みメンバー' => [function (): array {
+        [$organization, $owner] = createOrganizationWithOwner();
+        /** @var Session $session */
+        $session = app('session.store');
+        EmailVerificationContinuation::remember($session, $organization->id);
+
+        return [$owner, $session];
+    }, true],
+    '他組織の id が混入' => [function (): array {
+        [, $owner] = createOrganizationWithOwner();
+        $otherOrg = Organization::factory()->create();
+        /** @var Session $session */
+        $session = app('session.store');
+        EmailVerificationContinuation::remember($session, $otherOrg->id);
+
+        return [$owner, $session];
+    }, false],
+    'session 値が int でない' => [function (): array {
+        [, $owner] = createOrganizationWithOwner();
+        /** @var Session $session */
+        $session = app('session.store');
+        $session->put('verify_continue_organization_id', 'not-an-int');
+
+        return [$owner, $session];
+    }, false],
+    'user が null' => [function (): array {
+        [$organization] = createOrganizationWithOwner();
+        /** @var Session $session */
+        $session = app('session.store');
+        EmailVerificationContinuation::remember($session, $organization->id);
+
+        return [null, $session];
+    }, false],
+    'forget 後' => [function (): array {
+        [$organization, $owner] = createOrganizationWithOwner();
+        /** @var Session $session */
+        $session = app('session.store');
+        EmailVerificationContinuation::remember($session, $organization->id);
+        EmailVerificationContinuation::forget($session);
+
+        return [$owner, $session];
+    }, false],
+]);
+
+it('hasContinuation は resolveUrl の null 判定と常に同値 (条件を二重実装しない)', function (Closure $scenario, bool $expected): void {
+    [$user, $session] = $scenario();
+
+    $has = EmailVerificationContinuation::hasContinuation($user, $session);
+
+    expect($has)->toBe(EmailVerificationContinuation::resolveUrl($user, $session) !== null)
+        ->and($has)->toBe($expected);
+})->with('継続の有無シナリオ');
diff --git a/tests/js/architecture/logout-call-site-inventory.test.ts b/tests/js/architecture/logout-call-site-inventory.test.ts
index 42bdc5a..a6ea212 100644
--- a/tests/js/architecture/logout-call-site-inventory.test.ts
+++ b/tests/js/architecture/logout-call-site-inventory.test.ts
@@ -21,12 +21,15 @@ const JS_ROOT = path.resolve(__dirname, "../../../resources/js");
 
 /**
  * `/logout` を参照してよいファイル (resources/js からの相対パス)。
- * 現状 2 箇所あり、いずれも router.post = Inertia visit
- * (AppLayout: 通常画面のユーザーメニュー / VerifyEmail: メール認証待ち画面の離脱導線)。
+ * 現状 3 箇所あり、いずれも router.post = Inertia visit
+ * (AppLayout: 通常画面のユーザーメニュー / VerifyEmail: メール認証待ち画面の離脱導線 /
+ *  ConfirmRecentAuth: 再認証手段が無いユーザーの回復導線 = ログアウトして guest として
+ *  パスワードを再設定する。/forgot-password は guest middleware 付きで直リンクできない)。
  */
 const LOGOUT_CALL_SITE_INVENTORY: readonly string[] = [
   "components/templates/AppLayout.svelte",
   "pages/Auth/VerifyEmail.svelte",
+  "pages/Auth/ConfirmRecentAuth.svelte",
 ] as const;
 
 const LOGOUT_PATH_PATTERN = /["'`]\/logout["'`]/;
diff --git a/tests/js/architecture/page-shell-structure.test.ts b/tests/js/architecture/page-shell-structure.test.ts
index e182b34..838275a 100644
--- a/tests/js/architecture/page-shell-structure.test.ts
+++ b/tests/js/architecture/page-shell-structure.test.ts
@@ -4,13 +4,19 @@ import path from "path";
 import { fileURLToPath } from "url";
 
 /*
- * page-shell-structure — 認証ページ外枠の aigenba parity を構造保証する Architecture テスト。
+ * page-shell-structure — ページ外枠テンプレートの構造契約を集約する Architecture テスト。
  *
- * 契約: `AppLayout` を import するページ (ログイン後シェルを使う認証ページ) は、aigenba の統一外枠
+ * 契約 1 (AppLayout): `AppLayout` を import するページ (ログイン後シェルを使う認証ページ) は、
+ * aigenba の統一外枠
  *   <AppLayout><PageContainer><PageHeader|PageHeaderSection><PageContent>…
  * に従い、layout primitive を import かつ使用する。これにより外枠(padding/見出し/中央寄せ max-w-7xl)が
  * primitive に一元化され、ページ独自の外枠ドリフトを構造的に防ぐ。
  *
+ * 契約 2 (AuthLayout の離脱導線): `AuthLayout` を import するページは、**その手順を完了できない
+ * ユーザーが別の入口へ抜けられる導線**を `{#snippet footer()}` に 1 つ以上持ち、`TextLink` atom で
+ * 表現する (DESIGN.md §Do's and Don'ts)。認証ファネルはアプリ最初の関門であり、ここでの行き止まりは
+ * 価値提供の入口をそのまま失う (bug-hunt F-2-02)。例外は AUTH_EXIT_ALLOWLIST に理由付きで登録する。
+ *
  * 運用規約(機械強制でない・レビュー観点): 本文標準は上記外枠。ALLOWLIST 追加は理由必須。
  * (旧 page-content-usage.test.ts をリネーム。AdminMenuNav 等の廃止 import は deprecated-imports.test.ts。)
  */
@@ -27,6 +33,17 @@ const PAGECONTENT_ALLOWLIST: ReadonlyArray<{ path: string; reason: string }> = [
 ];
 const PAGECONTENT_ALLOWLIST_PATHS = new Set(PAGECONTENT_ALLOWLIST.map((e) => e.path));
 
+/** AuthLayout ページの離脱導線契約の除外 allowlist。追加は理由必須(reason 非空)。 */
+const AUTH_EXIT_ALLOWLIST: ReadonlyArray<{ path: string; reason: string }> = [
+    {
+        path: "Auth/VerifyEmail.svelte",
+        reason:
+            "離脱導線は本文の『ログアウト』(POST 遷移) が担う。footer の TextLink では表現できない。" +
+            "未検証状態で到達できる別入口が無いため、代替リンクを置くと新たな行き止まりを作る。",
+    },
+];
+const AUTH_EXIT_ALLOWLIST_PATHS = new Set(AUTH_EXIT_ALLOWLIST.map((e) => e.path));
+
 const escapeRegExp = (s: string): string => s.replace(/[.*+?^${}()|[\]\\]/g, "\\$&");
 
 function stripComments(src: string): string {
@@ -60,6 +77,27 @@ function importIdentifier(src: string, importPath: string): string | null {
 const usesTag = (src: string, ident: string): boolean =>
     new RegExp(`<${escapeRegExp(ident)}(?:\\s|/?>)`).test(src);
 
+/**
+ * footer snippet 本体を取り出す (先頭の {/snippet} まで)。
+ * - 定義が 0 個 → null (= 契約違反として報告)
+ * - 定義が 2 個以上 / 本体に snippet が入れ子 → "抽出器が現実に追いつけていない" 印として
+ *   例外を投げる (fail-closed。黙って pass させない)
+ */
+function footerSnippetBody(src: string): string | null {
+    const matches = [...src.matchAll(/\{#snippet\s+footer\s*\(\s*\)\s*\}([\s\S]*?)\{\/snippet\}/g)];
+    if (matches.length === 0) return null;
+    if (matches.length > 1) {
+        throw new Error("footer snippet の定義が複数あります。抽出器の前提が崩れています。");
+    }
+    const body = matches[0][1];
+    if (/\{#snippet\b/.test(body)) {
+        throw new Error(
+            "footer snippet に snippet が入れ子です。抽出器を AST 方式へ更新してください。",
+        );
+    }
+    return body;
+}
+
 describe("architecture/page-shell-structure", () => {
     it("PAGECONTENT_ALLOWLIST の各エントリは理由(reason)必須", () => {
         for (const e of PAGECONTENT_ALLOWLIST) {
@@ -109,4 +147,57 @@ describe("architecture/page-shell-structure", () => {
             msg,
         ).toEqual({ missingContainer: [], missingHeader: [], missingContent: [], paddingFalse: [] });
     });
+
+    it("AUTH_EXIT_ALLOWLIST の各エントリは理由(reason)必須 / path 重複なし", () => {
+        for (const e of AUTH_EXIT_ALLOWLIST) {
+            expect(e.reason.trim(), `allowlist "${e.path}" は理由必須`).not.toBe("");
+        }
+        // path 重複は編集ミスの兆候
+        expect(AUTH_EXIT_ALLOWLIST_PATHS.size).toBe(AUTH_EXIT_ALLOWLIST.length);
+    });
+
+    it("AUTH_EXIT_ALLOWLIST の各エントリは実在し AuthLayout を使うページである (死蔵 entry 検出)", async () => {
+        for (const e of AUTH_EXIT_ALLOWLIST) {
+            const abs = path.join(PAGES_DIR, e.path);
+            const src = stripComments(await fs.readFile(abs, "utf8"));
+            expect(
+                importIdentifier(src, "@/components/templates/AuthLayout.svelte"),
+                `allowlist "${e.path}" は AuthLayout ページではない (entry が死蔵または typo)`,
+            ).not.toBeNull();
+        }
+    });
+
+    it("AuthLayout ページは footer snippet に TextLink の離脱導線を持つ", async () => {
+        const files = await sveltePages(PAGES_DIR);
+        const missingFooter: string[] = [];
+        const footerWithoutLink: string[] = [];
+
+        for (const file of files) {
+            const rel = path.relative(PAGES_DIR, file).replace(/\\/g, "/");
+            const src = stripComments(await fs.readFile(file, "utf8"));
+            if (!importIdentifier(src, "@/components/templates/AuthLayout.svelte")) continue;
+            if (AUTH_EXIT_ALLOWLIST_PATHS.has(rel)) continue;
+
+            const body = footerSnippetBody(src);
+            if (body === null) {
+                missingFooter.push(rel);
+                continue;
+            }
+            const link = importIdentifier(src, "@/components/atoms/TextLink.svelte");
+            if (!link || !usesTag(body, link)) footerWithoutLink.push(rel);
+        }
+
+        const msg = [
+            missingFooter.length &&
+                `AuthLayout ページに footer snippet が無い:\n  - ${missingFooter.join("\n  - ")}`,
+            footerWithoutLink.length &&
+                `footer に TextLink の離脱導線が無い:\n  - ${footerWithoutLink.join("\n  - ")}`,
+        ]
+            .filter(Boolean)
+            .join("\n\n");
+        expect({ missingFooter, footerWithoutLink }, msg).toEqual({
+            missingFooter: [],
+            footerWithoutLink: [],
+        });
+    });
 });
diff --git a/tests/js/pages/ConfirmRecentAuth.test.ts b/tests/js/pages/ConfirmRecentAuth.test.ts
new file mode 100644
index 0000000..c147d1c
--- /dev/null
+++ b/tests/js/pages/ConfirmRecentAuth.test.ts
@@ -0,0 +1,85 @@
+import { beforeEach, describe, expect, it, vi } from "vitest";
+import { fireEvent, render, screen } from "@testing-library/svelte";
+
+/*
+ * recent-auth step-up の confirm 画面。
+ *
+ * 2 つの行き止まりを潰す:
+ *  (1) step-up を満たせない / 満たしたくないユーザーが操作を中止して抜けられない
+ *      → footer に /dashboard への離脱導線 (本画面のユーザーは auth+verified 済みで到達可)。
+ *      intended URL へは戻さない (満たさず戻っても middleware が再びここへ送り返すだけ)。
+ *  (2) canSatisfy=false の「パスワードを設定して再認証する」→ /forgot-password は
+ *      Fortify が `guest` middleware 付きで登録しており、ログイン済みの本画面ユーザーは
+ *      フォームに到達できない (F-2-01 と同 species の踏破不能 CTA)。
+ *      実際に踏破できる回復手順は「ログアウトしてから guest としてリセットする」だけなので、
+ *      CTA はログアウトに差し替える (ラベルは実際の着地と一致させる)。
+ */
+const { routerPostMock } = vi.hoisted(() => ({ routerPostMock: vi.fn() }));
+
+vi.mock("@inertiajs/svelte", async (importOriginal) => ({
+    ...(await importOriginal<typeof import("@inertiajs/svelte")>()),
+    router: { post: routerPostMock, visit: vi.fn() },
+}));
+
+import ConfirmRecentAuth from "@/pages/Auth/ConfirmRecentAuth.svelte";
+
+/** Inertia Link は href を絶対 URL に正規化するため pathname で比較する。 */
+const linkPathnames = (): string[] =>
+    screen.getAllByRole("link").map((a) => new URL((a as HTMLAnchorElement).href).pathname);
+
+beforeEach(() => {
+    routerPostMock.mockClear();
+});
+
+describe("Auth/ConfirmRecentAuth", () => {
+    it("passwordSet=true でパスワード再入力フォームと /dashboard への中止導線を出す", () => {
+        render(ConfirmRecentAuth, {
+            props: { appName: "My App", passwordSet: true, canSatisfy: true },
+        });
+
+        expect(screen.getByLabelText("現在のパスワード")).toBeInTheDocument();
+        expect(linkPathnames()).toContain("/dashboard");
+    });
+
+    it("canSatisfy=false でも /dashboard への中止導線を出す", () => {
+        render(ConfirmRecentAuth, {
+            props: {
+                appName: "My App",
+                passwordSet: false,
+                availableProviders: [],
+                canSatisfy: false,
+            },
+        });
+
+        expect(linkPathnames()).toContain("/dashboard");
+    });
+
+    it("canSatisfy=false で /forgot-password へのリンクを出さない (ログイン済みでは踏破不能)", () => {
+        render(ConfirmRecentAuth, {
+            props: {
+                appName: "My App",
+                passwordSet: false,
+                availableProviders: [],
+                canSatisfy: false,
+            },
+        });
+
+        expect(linkPathnames()).not.toContain("/forgot-password");
+        expect(screen.queryByRole("button", { name: "パスワードを設定して再認証する" })).toBeNull();
+    });
+
+    it("canSatisfy=false ではログアウトボタンを出し、押下で POST /logout する", async () => {
+        render(ConfirmRecentAuth, {
+            props: {
+                appName: "My App",
+                passwordSet: false,
+                availableProviders: [],
+                canSatisfy: false,
+            },
+        });
+
+        await fireEvent.click(screen.getByRole("button", { name: "ログアウトする" }));
+
+        expect(routerPostMock).toHaveBeenCalledWith("/logout", {}, expect.anything());
+    });
+});
diff --git a/tests/js/pages/ResetPassword.test.ts b/tests/js/pages/ResetPassword.test.ts
new file mode 100644
index 0000000..7a23f06
--- /dev/null
+++ b/tests/js/pages/ResetPassword.test.ts
@@ -0,0 +1,66 @@
+import { describe, expect, it, vi } from "vitest";
+import { render, screen, waitFor } from "@testing-library/svelte";
+import { reactiveUseForm } from "../support/reactiveUseForm.svelte";
+
+/*
+ * パスワードリセット画面。
+ *
+ * 期限切れ・使用済みリンクを踏むと errors.email が出るだけの「同じエラーが出続ける行き止まり」に
+ * なりうる (bug-hunt F-2-02)。**エラーの有無にかかわらず**別の入口へ抜けられる導線
+ * (/forgot-password で新しいリンクを取り直す / /login へ戻る) を footer に出すことを固定する。
+ * どちらも guest 状態のこのユーザーが実際に踏破できる先である。
+ *
+ * トークン無効時の errors 反映は reactiveUseForm フェイクで模倣する (サーバ応答を待たない)。
+ */
+const { holder } = vi.hoisted(() => ({
+    holder: { form: null as ReturnType<typeof reactiveUseForm> | null },
+}));
+
+vi.mock("@inertiajs/svelte", async (importOriginal) => ({
+    ...(await importOriginal<typeof import("@inertiajs/svelte")>()),
+    useForm: (init: Record<string, unknown>) => {
+        const form = reactiveUseForm(init);
+        holder.form = form;
+        return form;
+    },
+}));
+
+import ResetPassword from "@/pages/Auth/ResetPassword.svelte";
+
+const baseProps = { appName: "My App", token: "tok-123", email: "user@example.com" };
+
+/** Inertia Link は href を絶対 URL に正規化するため pathname で比較する。 */
+const linkPathnames = (): string[] =>
+    screen.getAllByRole("link").map((a) => new URL((a as HTMLAnchorElement).href).pathname);
+
+describe("Auth/ResetPassword", () => {
+    it("リセットフォーム (メールアドレス / 新しいパスワード / 送信ボタン) を描画する", () => {
+        render(ResetPassword, { props: baseProps });
+
+        expect(screen.getByRole("heading", { name: "パスワードリセット" })).toBeInTheDocument();
+        expect(screen.getByLabelText("メールアドレス")).toBeInTheDocument();
+        expect(screen.getByLabelText("新しいパスワード")).toBeInTheDocument();
+        expect(screen.getByRole("button", { name: "パスワードをリセット" })).toBeInTheDocument();
+    });
+
+    it("/forgot-password と /login への離脱導線を描画する", () => {
+        render(ResetPassword, { props: baseProps });
+
+        expect(linkPathnames()).toEqual(expect.arrayContaining(["/forgot-password", "/login"]));
+    });
+
+    it("トークン無効のエラーが出ても離脱導線が消えない (行き止まりにしない)", async () => {
+        render(ResetPassword, { props: baseProps });
+
+        holder.form?.respondWithErrors({
+            email: "このパスワードリセットトークンは無効です。",
+        });
+
+        await waitFor(() => {
+            expect(
+                screen.getByText("このパスワードリセットトークンは無効です。"),
+            ).toBeInTheDocument();
+        });
+        expect(linkPathnames()).toEqual(expect.arrayContaining(["/forgot-password", "/login"]));
+    });
+});
diff --git a/tests/js/pages/TwoFactorChallenge.test.ts b/tests/js/pages/TwoFactorChallenge.test.ts
new file mode 100644
index 0000000..77afcf0
--- /dev/null
+++ b/tests/js/pages/TwoFactorChallenge.test.ts
@@ -0,0 +1,48 @@
+import { describe, expect, it } from "vitest";
+import { fireEvent, render, screen, within } from "@testing-library/svelte";
+import TwoFactorChallenge from "@/pages/Auth/TwoFactorChallenge.svelte";
+
+/*
+ * 2要素認証チャレンジ画面。
+ *
+ * 認証コードもリカバリコードも手元に無いユーザーは、このままでは画面から抜けられない
+ * (bug-hunt F-2-02 と同種の欠落)。チャレンジ中はまだ未ログイン (Fortify の login.id
+ * セッション状態) なので `guest` middleware 配下の /login へ到達でき、そこが唯一の
+ * 実際に踏破できる離脱先になる。
+ */
+const baseProps = { appName: "My App" };
+
+/** Inertia Link は href を絶対 URL に正規化するため pathname で比較する。 */
+const linkPathnames = (): string[] =>
+    screen.getAllByRole("link").map((a) => new URL((a as HTMLAnchorElement).href).pathname);
+
+/** タブ見出しと入力ラベルが同名のため、入力は tabpanel 内にスコープして探す。 */
+const panelInput = (label: string): HTMLElement =>
+    within(screen.getByRole("tabpanel")).getByLabelText(label);
+
+describe("Auth/TwoFactorChallenge", () => {
+    it("既定では認証コード入力を描画する", () => {
+        render(TwoFactorChallenge, { props: baseProps });
+
+        expect(screen.getByRole("heading", { name: "2要素認証" })).toBeInTheDocument();
+        expect(panelInput("認証コード")).toBeInTheDocument();
+        expect(screen.getByRole("button", { name: "認証する" })).toBeInTheDocument();
+    });
+
+    it("リカバリコードタブへ切り替えると入力が入れ替わる", async () => {
+        render(TwoFactorChallenge, { props: baseProps });
+
+        await fireEvent.click(screen.getByRole("tab", { name: "リカバリコード" }));
+
+        expect(panelInput("リカバリコード")).toBeInTheDocument();
+        expect(
+            within(screen.getByRole("tabpanel")).queryByLabelText("認証コード"),
+        ).toBeNull();
+    });
+
+    it("/login への離脱導線を描画する (どちらのコードも使えないユーザーの出口)", () => {
+        render(TwoFactorChallenge, { props: baseProps });
+
+        expect(linkPathnames()).toContain("/login");
+    });
+});
diff --git a/tests/js/pages/VerifyEmail.test.ts b/tests/js/pages/VerifyEmail.test.ts
index 0931fd1..1e6ae41 100644
--- a/tests/js/pages/VerifyEmail.test.ts
+++ b/tests/js/pages/VerifyEmail.test.ts
@@ -2,37 +2,57 @@ import { describe, expect, it, vi } from "vitest";
 import { render, screen } from "@testing-library/svelte";
 import VerifyEmail from "@/pages/Auth/VerifyEmail.svelte";
 
-const { routerVisitMock } = vi.hoisted(() => ({ routerVisitMock: vi.fn() }));
-
 vi.mock("@inertiajs/svelte", async (importOriginal) => ({
     ...(await importOriginal<typeof import("@inertiajs/svelte")>()),
-    router: { visit: routerVisitMock, post: vi.fn() },
+    router: { post: vi.fn(), visit: vi.fn() },
 }));
 
 /*
- * メール認証待ち画面 (ソフトゲート)。
- * continueUrl は「登録由来の継続導線が実在するとき」だけサーバが非 null で渡す。
- * null のときに二次 CTA を出さない (= 継続先の無いボタンを出さない) ことを固定する。
+ * メール認証待ち画面。
+ *
+ * この画面から `onboarding.checkout` へ**進む**導線は出さない (bug-hunt F-2-01):
+ * route は ['auth','verified'] 配下にあり未認証は必ず差し戻されるため、
+ * 表示条件 (membership) と踏破条件 (verified) が食い違う恒常的に無効な CTA になる。
+ * サーバが渡すのは URL ではなく「認証完了後に checkout へ着地するか」(continuesToCheckout)
+ * だけで、画面はそれを**予告文**として出す。
  */
+
+/** この画面に出てよいボタンの全集合 (ここに無いボタンが増えたら落とす)。 */
+const ALLOWED_BUTTONS = ["認証メールを再送信", "ログアウト"];
+
+const renderedButtonLabels = (): string[] =>
+    screen.getAllByRole("button").map((b) => b.textContent?.trim() ?? "");
+
 describe("Auth/VerifyEmail", () => {
-    it("continueUrl が null なら二次 CTA を出さない", () => {
-        render(VerifyEmail, { props: { appName: "My App" } });
+    it("continuesToCheckout=true のとき認証後にプラン選択へ進む予告を出す", () => {
+        render(VerifyEmail, { props: { appName: "My App", continuesToCheckout: true } });
 
-        expect(screen.queryByTestId("verify-email-continue")).toBeNull();
-        expect(screen.getByRole("button", { name: "認証メールを再送信" })).toBeInTheDocument();
+        expect(screen.getByTestId("verify-email-checkout-note")).toBeInTheDocument();
     });
 
-    it("continueUrl があるとき「あとで認証する」CTA を出す", () => {
-        render(VerifyEmail, {
-            props: { appName: "My App", continueUrl: "/onboarding/checkout" },
-        });
+    it("continuesToCheckout=false のとき予告文を出さない (継続の無いユーザーに嘘をつかない)", () => {
+        render(VerifyEmail, { props: { appName: "My App", continuesToCheckout: false } });
 
-        expect(screen.getByTestId("verify-email-continue")).toBeInTheDocument();
+        expect(screen.queryByTestId("verify-email-checkout-note")).toBeNull();
     });
 
-    it("CTA は disabled にせず押下可能 (DESIGN.md)", () => {
+    it.each([true, false])(
+        "continuesToCheckout=%s でも操作は再送信 / ログアウトの 2 つだけでリンクを出さない",
+        (continuesToCheckout) => {
+            render(VerifyEmail, { props: { appName: "My App", continuesToCheckout } });
+
+            // 許可された 2 ボタンのラベルにのみ依存する (禁止したい CTA の testId / 文言には
+            // 一切依存しない) ため、別実装の踏破不能 CTA が再混入しても検出できる。
+            expect(renderedButtonLabels()).toEqual(ALLOWED_BUTTONS);
+            expect(screen.queryAllByRole("link")).toHaveLength(0);
+            // 旧実装 (「あとで認証する（プラン選択へ進む）」) の直接的な回帰ガード
+            expect(screen.queryByTestId("verify-email-continue")).toBeNull();
+        },
+    );
+
+    it("描画されるボタンは disabled にしない (DESIGN.md)", () => {
         const { container } = render(VerifyEmail, {
-            props: { appName: "My App", continueUrl: "/onboarding/checkout" },
+            props: { appName: "My App", continuesToCheckout: true },
         });
 
         expect(container.querySelectorAll("button[disabled]")).toHaveLength(0);

```
