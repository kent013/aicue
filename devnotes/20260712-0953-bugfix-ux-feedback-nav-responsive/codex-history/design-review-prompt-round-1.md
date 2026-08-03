# 詳細設計レビュー依頼 (Round 1)

## アプリの使命 (North Star) — AGENTS.md より

**AI-CUE** は、現場に既にある**作業手順書(SOP)を起点に**、AI が撮るべきカットを設計した**動画シナリオ**を生成し、そのシナリオを**スマホ(PWA)でナビゲーション撮影**することで、専門知識ゼロの現場作業者でも**標準化されたマニュアル動画**を作れるようにする。

- 「思考ゼロ・編集ゼロ」— 台本作成・撮影判断・編集の 3 ハードルを AI とナビ撮影で肩代わりする。
- 競合(OJT を撮って形式化する tebiki)と異なり、**標準作業を起点に AI が教材設計し撮影を指示する**（撮影者・教える人のスキルに品質を依存させない）。
- 熟練者の暗黙知を動画マニュアルという形式知へ変換する装置（SECI）。

v1 スコープ: 字幕のみ(TTS 後回し) / 撮影は PWA(同一オリジン・セッション認証) / 動画合成は自前 ffmpeg / 単一 Default Project。

## 禁止事項 — AGENTS.md より

1. テストなしの実装完了報告(不変条件は対応する Architecture/Feature テストへの登録まで含めて「実装済み」)
2. PHPStan エラーの widen(型を緩めて黙らせる)・baseline 化
3. dev DB への破壊操作(`migrate:fresh` 等)をエージェント判断で実行すること
4. `response()->json()` の直書き(DTO / JsonResource / Inertia を使う。仕様固定 endpoint のみ例外)
5. LLM 呼び出しの Prism 直呼び(`app/Prompts/` の factory 経由のみ。PromptGuardrailTest が検出)
6. prompt 文字列のコード直書き(`resources/prompts/*.yaml` に置く)
7. 操作系 POST の応答での `redirect()->intended()`(ログイン直後フロー専用。招待送信等は `back()->with(...)` で完結させる)
8. 必須条件未充足を理由にボタンを disabled にする UI(押下時にエラー表示する。DESIGN.md)

【思考原則 — 全議論に適用】
まず仮説を立てろ。何を検証したいのか、なぜそう考えるのか、どうなれば成功と判断するのかを明確にしてから手を動かせ。仮説なき改善はただの試行錯誤であり、結果から学ぶことができない。

データに真摯に向き合え。成果だけでなく、多様性の変化、構造の揺らぎ、想定外のパターン — 全てが判断材料になる。数値を見て即座に閾値を弄るな。何が起きているのかを理解し、なぜそうなったのかを考え、どの方向に進むべきかを判断してから手を動かせ。

先人の知恵を探せ。自分たちだけで登る必要はない。乗るべき巨人の肩があるなら乗れ。

機能の名前に立ち返れ。名前はその機能が果たすべき役割を示している。現在の設計がその役割を果たしているか、常に問え。

仕組みが機能していない段階で値を弄るな。閾値チューニングやフィールド追加は、設計の方向性が正しいと確認できてから行え。方向性が間違っているなら、値をいくら調整しても意味はない。設計そのものを見直せ。成果が出なければ早期に見切り、次の仮説へ進め。

【ツール使用制限】
コマンド実行・ファイル書き込みは一切行わず、提供されたテキストの分析に集中すること。ファイル読み込みは許可。

---

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
9. セキュリティ（認可チェック、入力バリデーション、OWASP Top 10、AGENTS.md のセキュリティ不変条件。特に F-06 の enumeration 抑止不変条件の維持）
10. DESIGN.md準拠（UI/frontend 変更を含む場合）: `/DESIGN.md` が design token の canonical source。color / radius / typography を token 経由で参照する設計か、hex 直書きを増やさないか
11. Atomic Design準拠（UI/frontend 変更を含む場合）: `resources/js/components/` の `atoms/molecules/organisms/templates` の責務分離に沿った配置か。階層を逆流していないか。アイコンは Lucide 前提で、SVG 直書きを新設していないか

【出力形式】
- 各施策ごとに判定: APPROVE / REQUEST_CHANGES
- 指摘は [Critical] [Warning] [Suggestion] で分類
- Critical/Warning には必ず修正案を添える
- 全体判定: APPROVED / CHANGES_REQUESTED
- 日本語で出力

---

## 詳細設計書
# 詳細設計: bugfix-ux-feedback-nav-responsive

bug-hunt finding F-03 / F-06 (成功フィードバック欠落)・F-08 (ヘッダーナビ不統一)・F-14 (manage.users モバイル横スクロール) の修正。
スコープは UX/表示のみ (ドメインロジック・ルート・認可の変更なし)。

## 使命・制約（絶対遵守）

### アプリの使命（North Star）

**AI-CUE** は、現場に既にある**作業手順書(SOP)を起点に**、AI が撮るべきカットを設計した**動画シナリオ**を生成し、そのシナリオを**スマホ(PWA)でナビゲーション撮影**することで、専門知識ゼロの現場作業者でも**標準化されたマニュアル動画**を作れるようにする。

- 「思考ゼロ・編集ゼロ」— 台本作成・撮影判断・編集の 3 ハードルを AI とナビ撮影で肩代わりする。
- 競合(OJT を撮って形式化する tebiki)と異なり、**標準作業を起点に AI が教材設計し撮影を指示する**（撮影者・教える人のスキルに品質を依存させない）。
- 熟練者の暗黙知を動画マニュアルという形式知へ変換する装置（SECI）。

### 禁止事項（AGENTS.md より）

1. テストなしの実装完了報告(不変条件は対応する Architecture/Feature テストへの登録まで含めて「実装済み」)
2. PHPStan エラーの widen(型を緩めて黙らせる)・baseline 化
3. dev DB への破壊操作(`migrate:fresh` 等)をエージェント判断で実行すること
4. `response()->json()` の直書き(DTO / JsonResource / Inertia を使う。仕様固定 endpoint のみ例外)
5. LLM 呼び出しの Prism 直呼び(`app/Prompts/` の factory 経由のみ)
6. prompt 文字列のコード直書き(`resources/prompts/*.yaml` に置く)
7. 操作系 POST の応答での `redirect()->intended()`(ログイン直後フロー専用。招待送信等は `back()->with(...)` で完結させる)
8. 必須条件未充足を理由にボタンを disabled にする UI(押下時にエラー表示する。DESIGN.md)

### コーディングルール
- **PHPStan level 10** 必須（`composer phpstan`）
- **Pest**テストフレームワーク（`composer test`）
- **RefreshDatabase** + `--parallel` 並列実行（`tests/Pest.php` でグローバル適用、個別 `DatabaseTransactions` 使用禁止）
- **テストデータは必ずFactoryで生成**（`Model::create()` 手組み禁止）
- **DTO + JsonResource** パターン（AGENTS.md参照）
- **アーリーリターン** 推奨
- **コードフォーマット**: `composer fix`（Pint）/ `pnpm lint:fix`
- PHP 8.4 + Laravel 12 + Svelte 5 + Inertia.js + TypeScript
- フロントは DS token/ramp のみ (`DESIGN.md` canonical、ds-purity テストが検出)。アイコンは `@lucide/svelte` のみ
- component 階層は `atoms → molecules → organisms → features → templates → pages` の単方向 import のみ

## 概念設計リファレンス

`devnotes/20260712-0953-bugfix-ux-feedback-nav-responsive/conceptual-design.md`（Codex 概念レビュー Round 2 で APPROVED）

## 施策一覧

| # | 施策名 | 変更ファイル | 優先度 |
|---|--------|------------|--------|
| A1 | F-03: 認証メール再送の success flash (Fortify contract bind) | `app/Http/Responses/Fortify/VerificationNotificationSentResponse.php` (新規) / `app/Providers/FortifyServiceProvider.php` / `tests/Feature/Auth/FortifyResponseTest.php` | High |
| A2 | F-06: パスワードリセットリンク送信の flash キー `status`→`success` | `app/Http/Responses/Fortify/EnumerationSafePasswordResetLinkResponse.php` / `tests/Feature/Auth/FortifyResponseTest.php` | High |
| B | F-08: ヘッダーナビ統一 (設定/ログアウトを AppLayout 常設化) | `resources/js/components/templates/AppLayout.svelte` / `resources/js/pages/Dashboard.svelte` / `tests/js/components/templates/AppLayout.test.ts` (新規) / `tests/js/pages/Dashboard.test.ts` | High |
| C | F-14: manage.users メンバー/招待行のレスポンシブ対応 | `resources/js/pages/Admin/Users.svelte` / `tests/js/pages/AdminUsers.test.ts` | Medium |

施策 A (A1+A2) / B / C は互いに独立で、単独で実装・検証・revert 可能。

---

## 施策 A1: F-03 認証メール再送の success flash

### 変更箇所
- ファイル: `app/Http/Responses/Fortify/VerificationNotificationSentResponse.php`（新規）
- ファイル: `app/Providers/FortifyServiceProvider.php`（`register()` に bind 追加、L24-46 付近）

### 波及変更
- TypeScript型定義: なし（`FlashPayload.success` は定義済み。フロントは既存の flash→toast 機構で表示）
- API Resource/DTO: なし
- テストファイル: `tests/Feature/Auth/FortifyResponseTest.php`（新規テスト追加）
- フロント: なし（`/email/verify` = `AuthLayout`、`EmailVerificationBanner`/`VerifyEmailResendButton` が張られる各画面 = `AppLayout`。どちらも `ToastContainer` + `consumeFlash` 配線済み）

### 現行コード

Fortify 既定（`vendor/laravel/fortify/src/Http/Responses/EmailVerificationNotificationSentResponse.php`。bind 未差し替えのため現在これが使われる）:

```php
public function toResponse($request)
{
    return $request->wantsJson()
        ? new JsonResponse('', 202)
        : back()->with('status', Fortify::VERIFICATION_LINK_SENT);
}
```

`status` キーは `HandleInertiaRequests::share()`（success/error/info/warning の 4 キーのみ共有）にも
`flash-to-toast.ts`（同 4 キーのみ消費）にも渡らないため、toast が出ない = F-03。

### 変更後コード

`app/Http/Responses/Fortify/VerificationNotificationSentResponse.php`（新規。既存 `TwoFactorDisabledResponse` と同型）:

```php
<?php

declare(strict_types=1);

namespace App\Http\Responses\Fortify;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Laravel\Fortify\Contracts\EmailVerificationNotificationSentResponse as EmailVerificationNotificationSentResponseContract;

/**
 * 認証メール再送信後のレスポンス (Fortify contract bind)。
 *
 * Fortify 既定は `back()->with('status', ...)` を返すが、flash-to-toast は
 * status を意図的に gating (toast 化しない)。再送信の完了を toast でフィードバック
 * するため、web は `success` キーへ寄せる (flash キー統一ポリシー:
 * web 向け操作成功 flash は success に統一する。FortifyResponseTest が正本)。
 *
 * wantsJson (XHR / API) の raw JSON は「Fortify 固定契約の互換維持」であり
 * 禁止事項 4 の例外に該当する。このパターンは app/Http/Responses/Fortify/ に
 * 閉じ、通常のアプリ endpoint へ波及させない。
 */
final class VerificationNotificationSentResponse implements EmailVerificationNotificationSentResponseContract
{
    private const string SUCCESS_MESSAGE = '認証メールを再送信しました。';

    /**
     * @param  Request  $request
     */
    public function toResponse($request): JsonResponse|RedirectResponse
    {
        if ($request->wantsJson()) {
            return new JsonResponse('', 202);
        }

        return back()->with('success', self::SUCCESS_MESSAGE);
    }
}
```

`app/Providers/FortifyServiceProvider.php` の `register()` に追加（use 文も追加）:

```php
use App\Http\Responses\Fortify\VerificationNotificationSentResponse;
use Laravel\Fortify\Contracts\EmailVerificationNotificationSentResponse as EmailVerificationNotificationSentResponseContract;

// register() 内 (既存 bind 群の並びに追記):
$this->app->singleton(EmailVerificationNotificationSentResponseContract::class, VerificationNotificationSentResponse::class);
```

備考:
- Fortify の `EmailVerificationNotificationController` は「認証済みユーザーの再送」を
  `RedirectAsIntended`（= `redirect()->intended()`）で返すが、それは Fortify 内部のログイン直後系
  分岐でありこの施策の対象外（未認証ユーザーの再送 = `EmailVerificationNotificationSentResponse`
  contract のみ差し替える）。禁止事項 7 に抵触しない。
- JSON 分岐は Fortify 既定 (`wantsJson` / 202) をそのまま踏襲する（既存 3 クラスは `expectsJson` を
  使っているが、本クラスは差し替え元の Fortify 既定 `wantsJson` に合わせて挙動互換を最優先する）。

### PHPStan適合チェック
- [x] 戻り値の型が明示されている（`JsonResponse|RedirectResponse` union、既存 4 クラスと同型で level 10 実績あり）
- [x] null安全（引数 `$request` は contract 経由、null 分岐なし）
- [x] DTOを返している（Response object。配列返却なし）
- [x] Genericsの型パラメータ（該当なし）

### テスト計画
- [ ] 新規 (Pest, `tests/Feature/Auth/FortifyResponseTest.php`):
  「認証メール再送は success flash を返す」— 未認証ユーザー（`User::factory()->unverified()->create()`）で
  `actingAs` + `Notification::fake()` → `$this->from('/email/verify')->post('/email/verification-notification')` が
  `assertRedirect('/email/verify')` + `assertSessionHas('success', '認証メールを再送信しました。')` +
  `assertSessionMissing('status')`（flash キー統一ポリシー: status 併用の誤実装を検出）
- [ ] 個別の `DatabaseTransactions` を使っていないことを確認

### リスク
- Fortify 既定応答からの変更点は flash キーのみ（redirect 先・JSON 分岐は互換）。
  `status` キーを読んでいるフロントコードは存在しない（grep 確認済み）ため後退リスクは低い。

---

## 施策 A2: F-06 パスワードリセットリンク送信の flash キー統一

### 変更箇所
- ファイル: `app/Http/Responses/Fortify/EnumerationSafePasswordResetLinkResponse.php`（L37-46）

### 波及変更
- TypeScript型定義: なし
- API Resource/DTO: なし
- テストファイル: `tests/Feature/Auth/FortifyResponseTest.php`（既存テストの `status` アサーション更新）
- フロント: なし（`ForgotPassword.svelte` は「flash success は AuthLayout の ToastContainer 経由で表示される」
  というコメントどおり success キーを期待済み）

### 現行コード

```php
    public function toResponse($request): JsonResponse|RedirectResponse
    {
        if ($request->wantsJson()) {
            return new JsonResponse(['message' => self::STATUS_MESSAGE], 200);
        }

        return back()->with('status', self::STATUS_MESSAGE);
    }
```

### 変更後コード

```php
    public function toResponse($request): JsonResponse|RedirectResponse
    {
        if ($request->wantsJson()) {
            return new JsonResponse(['message' => self::STATUS_MESSAGE], 200);
        }

        // flash キー統一ポリシー: web 向け操作成功 flash は success に統一する
        // (status は flash-to-toast が意図的に gating しており toast にならない = F-06)
        return back()->with('success', self::STATUS_MESSAGE);
    }
```

あわせて class docblock の「同一の『送信しました』flash」の記述に
「flash キーは success（flash-to-toast 消費対象）」であることを追記する。
enumeration 抑止の不変条件（user 在/不在で同一メッセージ・同一キー・同一 redirect）は変更しない。
定数名 `STATUS_MESSAGE` は enumeration 抑止文言の意味で使われているため改名しない
（diff を flash キー変更に限定する）。

### PHPStan適合チェック
- [x] 戻り値の型が明示されている（既存シグネチャ不変）
- [x] null安全（変更なし）
- [x] DTOを返している（Response object）
- [x] Genericsの型パラメータ（該当なし）

### テスト計画
- [ ] 既存テスト更新 (`tests/Feature/Auth/FortifyResponseTest.php` L27-28):
  `assertSessionHas('status', ...)` → `assertSessionHas('success', 'パスワードリセット用のリンクをメールで送信しました。')` +
  `assertSessionMissing('status')`。enumeration 抑止（user 在/不在で同一応答）のアサーション構造は維持
- [ ] ファイル冒頭コメントに flash キー統一ポリシーを明記
  （「web 向け操作成功 flash は success に統一。status は flash-to-toast が gating するため使わない。
  本テストが Fortify Response contract bind の応答契約の正本」）
- [ ] 個別の `DatabaseTransactions` を使っていないことを確認

補足（ポリシーの回帰防止範囲）: bind 済み Response のうち `TwoFactorDisabledResponse` /
`RecoveryCodesGeneratedResponse` は既に success キーで実装済み。これらの flash 契約は
2FA 系の既存 Feature テストと本ファイルの冒頭コメント（ポリシー正本の宣言）でカバーし、
本改修では A1/A2 の 2 endpoint のテスト追加・更新に留める（テスト重複を作らない）。

### リスク
- `status` キー消滅による後退: フロントに `flash.status` の読み手なし（grep 確認済み）。
  API 側 (`wantsJson`) は不変。リスクは極小。

---

## 施策 B: F-08 ヘッダーナビ統一（設定/ログアウトの AppLayout 常設化）

### 変更箇所
- ファイル: `resources/js/components/templates/AppLayout.svelte`（script + header 部）
- ファイル: `resources/js/pages/Dashboard.svelte`（L33-48 の logout ロジック、L155-160 の headerActions snippet を削除）

### 波及変更
- TypeScript型定義: なし（`Props` インターフェース不変。`headerActions?: Snippet` は optional のまま存置）
- Inertia Props: なし（shared props `auth` は共有済み。`SharedProps`/`AuthUser` 型を利用するのみ）
- API Resource/DTO: なし
- テストファイル: `tests/js/components/templates/AppLayout.test.ts`（新規）/ `tests/js/pages/Dashboard.test.ts`（回帰追記）
- 他の AppLayout 利用ページ (24 ページ): 変更不要（headerActions を渡しているのは Dashboard のみ。
  追加ナビは auth.user があるページで自動的に表示される）

### 現行コード

`AppLayout.svelte`（抜粋）:

```svelte
<script lang="ts">
    // ...
    let { appName, children, headerActions }: Props = $props();

    $effect(() => {
        consumeFlash(page.props.flash as FlashPayload | undefined);
    });

    const auth = $derived(page.props.auth as { user?: { emailVerified?: boolean } | null } | undefined);
    const showEmailBanner = $derived(auth?.user != null && auth.user.emailVerified === false);
    const notifications = $derived(
        page.props.notifications as NotificationSharedProps | undefined,
    );
    const showBell = $derived(auth?.user != null);
</script>

<header class="border-b border-border bg-surface">
    <div class="mx-auto flex max-w-6xl items-center justify-between px-8 py-3">
        <a href="/dashboard" class="text-h3 text-primary">{appName}</a>
        <div class="flex items-center gap-3">
            {#if showBell}
                <NotificationBell unreadCount={notifications?.unreadCount ?? 0} />
            {/if}
            {#if headerActions}
                {@render headerActions()}
            {/if}
        </div>
    </div>
</header>
```

`Dashboard.svelte`（削除対象。L33-48 / L155-160）:

```svelte
    let loggingOut = $state(false);

    function logout(): void {
        router.post("/logout", {}, {
            onStart: () => { loggingOut = true; },
            onFinish: () => { loggingOut = false; },
        });
    }
...
    {#snippet headerActions()}
        <TextLink href="/settings">設定</TextLink>
        <Button variant="ghost" size="sm" onclick={logout} loading={loggingOut}>
            ログアウト
        </Button>
    {/snippet}
```

### 変更後コード

`AppLayout.svelte`:

```svelte
<script lang="ts">
    import type { Snippet } from "svelte";
    import { page, router } from "@inertiajs/svelte";
    import Button from "@/components/atoms/Button.svelte";
    import TextLink from "@/components/atoms/TextLink.svelte";
    import ToastContainer from "@/components/organisms/ToastContainer.svelte";
    import EmailVerificationBanner from "@/components/features/auth/EmailVerificationBanner.svelte";
    import NotificationBell from "@/components/molecules/NotificationBell.svelte";
    import { consumeFlash } from "@/lib/stores/flash-to-toast";
    import type { SharedProps } from "@/lib/shared-props";

    /**
     * 認証済み画面用レイアウト (最小骨格)。
     * Phase 2 (組織・Team・Project 導入) でサイドバー・組織切替・通知センターを拡張する。
     * Laravel flash は consumeFlash で toast に変換する (visitKey で de-dup)。
     * ログイン中は通知ベル・設定・ログアウトを全ページ常設する (F-08: ナビ統一)。
     * ログアウト POST はこのレイアウトの単一ハンドラに一本化する (ページ側に実装を残さない)。
     */
    interface Props {
        appName: string;
        children: Snippet;
        /** ヘッダー右側のページ固有の追加アクション (常設ナビの左に並ぶ) */
        headerActions?: Snippet;
    }

    let { appName, children, headerActions }: Props = $props();

    // shared props は backend (HandleInertiaRequests) が真実。lib/shared-props.ts の型で読む
    const shared = $derived(page.props as unknown as SharedProps);

    $effect(() => {
        consumeFlash(shared.flash);
    });

    // メール未認証のソフトゲート案内 (organizations.store / invitations.store は
    // verified.or-back で back + error flash になるため、常設バナーで導線を先出しする)。
    const showEmailBanner = $derived(shared.auth?.user != null && shared.auth.user.emailVerified === false);

    // ログイン時のみベル + アカウントナビ (設定/ログアウト) を常設する
    // (invitations.accept 等、ゲスト到達がある AppLayout ページでは出さない)
    const showAccountNav = $derived(shared.auth?.user != null);

    let loggingOut = $state(false);

    // ログアウト (二重送信ガード。失敗時も onFinish で解除され再試行できる)
    function logout(): void {
        if (loggingOut) return;
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

<ToastContainer />

<div class="flex min-h-screen flex-col bg-neutral text-text">
    <header class="border-b border-border bg-surface">
        <!-- 375px 方針: ロゴは shrink-0、右側アクション群は flex-wrap で行内折り返し (2 段化) -->
        <div class="mx-auto flex max-w-6xl items-center justify-between gap-3 px-8 py-3">
            <a href="/dashboard" class="shrink-0 text-h3 text-primary">{appName}</a>
            <div class="flex flex-wrap items-center justify-end gap-x-3 gap-y-1">
                {#if headerActions}
                    {@render headerActions()}
                {/if}
                {#if showAccountNav}
                    <NotificationBell unreadCount={shared.notifications?.unreadCount ?? 0} />
                    <TextLink href="/settings" testId="nav-settings">設定</TextLink>
                    <Button
                        variant="ghost"
                        size="sm"
                        onclick={logout}
                        loading={loggingOut}
                        testId="nav-logout"
                    >
                        ログアウト
                    </Button>
                {/if}
            </div>
        </div>
    </header>
    <main class="mx-auto w-full max-w-6xl flex-1 px-8 py-8">
        {#if showEmailBanner}
            <div class="mb-6">
                <EmailVerificationBanner />
            </div>
        {/if}
        {@render children()}
    </main>
</div>
```

設計判断:
- ベル表示は従来どおり auth ゲート（`showBell` を `showAccountNav` に統合。条件は同一 `auth.user != null`）。
- `headerActions` は常設ナビの**左**に描画（ページ固有アクション → 定位置ナビの順。定位置ナビが右端で安定する）。
- `page.props.flash` / `page.props.notifications` のインラインキャストも `SharedProps` 経由に置き換え、
  未使用になる `FlashPayload` / `NotificationSharedProps` の直接 import を削除する
  （`consumeFlash(shared.flash)` は `SharedProps.flash: FlashPayload` で型が付く）。
- ログアウトボタンは Inertia visit 中 `loading` 表示（disabled 属性は Button atom の loading 実装に従う。
  「必須条件未充足による disabled」ではなく通信中の二重送信ガードであり禁止事項 8 の対象外。
  現行 Dashboard と同じ挙動）。

`Dashboard.svelte`:
- `logout` 関数・`loggingOut` state・`{#snippet headerActions()}` ブロックを削除。
- 不要になる import (`router`（他で未使用なら）) を整理。`TextLink`・`Button` は本文他所で使用しているため残す。

### PHPStan適合チェック
- [x] PHP 変更なし（フロントのみ）
- [x] TypeScript: `SharedProps` 型で auth/flash/notifications を参照（`any` 不使用、`pnpm typecheck` green 想定）

### テスト計画
- [ ] 新規 (Vitest, `tests/js/components/templates/AppLayout.test.ts`):
  - `page` store を `vi.mock("@inertiajs/svelte", ...)` で差し替え（`readable({ props: { auth: { user: {...} }, appName, notifications: { unreadCount: 0 } } })`。router も mock）。
    `children` は `createRawSnippet` で渡す
  - 「ログイン中は 設定 リンク (/settings 宛) と ログアウト ボタンを描画する」
    （`getByTestId("nav-settings")` の pathname = `/settings`、`getByTestId("nav-logout")` 存在）
  - 「ログアウトボタン押下で POST /logout が呼ばれる」（router mock で検証）
  - 「auth.user が null なら 設定/ログアウト/ベル を描画しない」（ゲスト到達ページの回帰）
  - 「ログアウトボタンは disabled でない」（禁止事項 8 の系）
- [ ] 既存更新 (Vitest, `tests/js/pages/Dashboard.test.ts`):
  Dashboard が page-local の設定/ログアウトを持たないこと（AppLayout 常設化後の重複排除の回帰。
  page store 未設定 = auth なしの現行テスト環境では `queryByTestId("nav-logout")` が null であることを確認）
- [ ] 主要レイアウトへのナビ常設は AppLayout.test.ts が単一の真実（全ページの個別テストは追加しない。
  24 ページはすべて AppLayout 経由のため template テストで代表する）

### リスク
- ヘッダー右側の要素増による狭幅崩れ → flex-wrap 方針で折り返しに逃がす（概念設計で固定済み）。
  実装 Phase の verify で 375px の実ブラウザ観察（header 要素自身の `scrollWidth <= clientWidth` を含む）を行う。
- `invitations.accept` などゲスト到達ページでは従来どおり非表示（挙動変更なし）。
- Capture PWA (`/app` 系) にもナビが出る。撮影動線を阻害しない小型要素（テキストリンク + sm ボタン）であり、
  設定/ログアウトへ到達できることは PWA でも要件どおりプラス。

---

## 施策 C: F-14 manage.users メンバー/招待行のレスポンシブ対応

### 変更箇所
- ファイル: `resources/js/pages/Admin/Users.svelte`
  - メンバー行 `<li>`（L235）とメンバー操作ブロック `<div>`（L258）
  - 招待行 `<li>`（L367）と招待右側ブロック `<div>`（L369）

### 波及変更
- TypeScript型定義: なし（クラス文字列のみの変更）
- API Resource/DTO: なし
- テストファイル: `tests/js/pages/AdminUsers.test.ts`（クラス不変条件テストを追記）

### 現行コード

メンバー行（L235 / L258）:

```svelte
<li class="flex items-center justify-between gap-4 py-3">
    <div class="min-w-0">
        ...名前/バッジ/メール...
    </div>
    <div class="flex shrink-0 items-center gap-2">
        ...2FA 解除 / ロール select / 削除...
    </div>
</li>
```

招待行（L367-369）:

```svelte
<li class="flex items-center justify-between gap-4 py-3">
    <p class="truncate text-body">{invitation.email}</p>
    <div class="flex shrink-0 items-center gap-3">
        ...roleLabel・期限 / 取消...
    </div>
</li>
```

`shrink-0` + `flex-wrap` なしのため、375px で操作ブロック（バッジ2種 + 2FA解除 + select「未割当（選択してください）」+ 削除）が
コンテンツ幅を強制し `scrollWidth 468 > clientWidth 375`（bug-hunt 実測）。

### 変更後コード

メンバー行:

```svelte
<!-- 375px 方針: モバイルは縦積み、sm 以上は現行の横並び (F-14)。操作ブロックは要素単位で折り返し可 -->
<li class="flex flex-col gap-2 py-3 sm:flex-row sm:items-center sm:justify-between sm:gap-4">
    <div class="min-w-0">
        ...変更なし (min-w-0 + truncate 維持)...
    </div>
    <div class="flex flex-wrap items-center gap-2 sm:shrink-0 sm:justify-end">
        ...変更なし...
    </div>
</li>
```

招待行:

```svelte
<li class="flex flex-col gap-2 py-3 sm:flex-row sm:items-center sm:justify-between sm:gap-4">
    <p class="min-w-0 truncate text-body">{invitation.email}</p>
    <div class="flex flex-wrap items-center gap-3 sm:shrink-0 sm:justify-end">
        ...変更なし...
    </div>
</li>
```

設計判断:
- **モバイル（<640px）**: `flex-col` で名前ブロックと操作ブロックを縦積み。操作ブロック内は `flex-wrap` で
  select + ボタンが収まらない場合に要素単位で折り返す（select の option 文言は変更しない —
  「未割当（選択してください）」は禁止事項 8 の代替 UX として意味を持つため短縮しない）。
- **sm 以上**: `sm:shrink-0` で現行の右寄せ横並びレイアウトを完全温存（デスクトップの見た目は不変）。
- 色/角丸/タイポ/spacing token は変更しない（Tailwind レイアウトユーティリティのみ = ds-purity 影響なし）。
- 名前/メールの `min-w-0` + `truncate` は維持（長文・多言語ラベル耐性）。

### PHPStan適合チェック
- [x] PHP 変更なし

### テスト計画
- [ ] 既存更新 (Vitest, `tests/js/pages/AdminUsers.test.ts`) に追記:
  - 「メンバー行はモバイル縦積みクラス (`flex-col` + `sm:flex-row`) を持ち、操作ブロックは `flex-wrap` を持つ」
    （`member-list` 内の li / 操作 div の className を検証。jsdom はレイアウト計算をしないため、
    クラス不変条件を横スクロール回避のプロキシとして固定する）
  - 「招待行も同様の縦積みクラスを持つ」（`invitation-list`）
  - 検証対象は「2FA有効 + 未割当」member（bug-hunt 再現条件の fixture は既存 membersFixture id=3 相当を利用）
- [ ] 出口条件（実装 Phase の verify 手順）: 実ブラウザ 375px で `/manage/users` を開き、
  `document.body` / member-list コンテナ / header の `scrollWidth <= clientWidth` を確認。
  bug-hunt 再走行での F-14 消込を最終確認とする

### リスク
- sm 境界 (640px) 直下のタブレット縦などで縦積みになるが、操作性は損なわない（要素は全て全幅内に収まる）。
- デスクトップは `sm:` プレフィックスで現行完全互換のため後退リスクなし。

---

## 実装モード

| 項目 | 内容 |
|------|------|
| 推奨モード | incremental |
| 判断根拠 | 4 finding とも既存ファイルへの小差分（新規は Response class 1 + テスト 1）で、A/B/C は互いに独立。単一 worktree で施策単位にコミットを分ければ検証・revert が容易 |
| 競合リスク | 他設計 (20260712-0925 confirm-password / 0926 i18n / 0927 billing) とファイル重複なし。強いて言えば 0927 が billing 画面に触れる場合に `AppLayout` 利用ページとしての見た目変化が重なるが、ファイル単位の競合はない |

## 検証コマンド

`composer test` / `composer phpstan` / `vendor/bin/pint --test` / `pnpm lint` / `pnpm typecheck` / `pnpm test` / `pnpm build` 全 green でコミット。

---

## 関連する現行コード

### app/Http/Responses/Fortify/EnumerationSafePasswordResetLinkResponse.php
```php
<?php

declare(strict_types=1);

namespace App\Http\Responses\Fortify;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Laravel\Fortify\Contracts\FailedPasswordResetLinkRequestResponse as FailedPasswordResetLinkRequestResponseContract;
use Laravel\Fortify\Contracts\SuccessfulPasswordResetLinkRequestResponse as SuccessfulPasswordResetLinkRequestResponseContract;

/**
 * forgot-password の enumeration 抑止レスポンス (Fortify contract bind)。
 *
 * Fortify 標準は user 在/不在で異なるレスポンス (成功 flash vs エラー) を返すため
 * account enumeration を許してしまう。user 在/不在を問わず常に同一の
 * 「送信しました」flash を返して抑止する。
 *
 * 成功 (SuccessfulPasswordResetLinkRequestResponse) / 失敗
 * (FailedPasswordResetLinkRequestResponse) の両契約を本クラスに差し替える。
 */
final class EnumerationSafePasswordResetLinkResponse implements FailedPasswordResetLinkRequestResponseContract, SuccessfulPasswordResetLinkRequestResponseContract
{
    private const string STATUS_MESSAGE = 'パスワードリセット用のリンクをメールで送信しました。';

    /**
     * Fortify は status 言語キー (passwords.sent / passwords.throttled / passwords.user 等) を
     * constructor で渡す。user 在/不在を区別させないため status 値そのものは応答に反映せず、
     * 常に同一の汎用メッセージを返す。プロパティとしては保持し将来の拡張点とする。
     */
    public function __construct(private readonly string $status) {}

    /**
     * @param  Request  $request
     */
    public function toResponse($request): JsonResponse|RedirectResponse
    {
        if ($request->wantsJson()) {
            return new JsonResponse(['message' => self::STATUS_MESSAGE], 200);
        }

        return back()->with('status', self::STATUS_MESSAGE);
    }

    /**
     * Fortify が渡した元の status 言語キー (デバッグ / 将来拡張用)。
     */
    public function rawStatus(): string
    {
        return $this->status;
    }
}
```

### app/Http/Responses/Fortify/TwoFactorDisabledResponse.php (既存の同型前例)
```php
<?php

declare(strict_types=1);

namespace App\Http\Responses\Fortify;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Laravel\Fortify\Contracts\TwoFactorDisabledResponse as TwoFactorDisabledResponseContract;

/**
 * 2FA 無効化後のレスポンス (Fortify contract bind)。
 *
 * Fortify 既定は `back()->with('status', ...)` を返すが、flash-to-toast は
 * status を意図的に gating (toast 化しない)。設定変更の完了を toast でフィードバック
 * するため、web のみ `success` キーへ寄せる。expectsJson (XHR / API) は
 * Fortify 既定どおり JSON 200 を維持する。
 */
final class TwoFactorDisabledResponse implements TwoFactorDisabledResponseContract
{
    private const string SUCCESS_MESSAGE = '二要素認証を無効化しました。';

    /**
     * @param  Request  $request
     */
    public function toResponse($request): JsonResponse|RedirectResponse
    {
        if ($request->expectsJson()) {
            return new JsonResponse('', 200);
        }

        return back()->with('success', self::SUCCESS_MESSAGE);
    }
}
```

### app/Providers/FortifyServiceProvider.php (register 部)
```php
<?php

declare(strict_types=1);

namespace App\Providers;

use App\Actions\Fortify\CreateNewUser;
use App\Actions\Fortify\ResetUserPassword;
use App\Actions\Fortify\UpdateUserPassword;
use App\Actions\Fortify\UpdateUserProfileInformation;
use App\Http\Responses\Fortify\EnumerationSafePasswordResetLinkResponse;
use App\Http\Responses\Fortify\LoginResponse;
use App\Http\Responses\Fortify\RecoveryCodesGeneratedResponse;
use App\Http\Responses\Fortify\RegisterResponse;
use App\Http\Responses\Fortify\TwoFactorDisabledResponse;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response as InertiaResponse;
use Laravel\Fortify\Actions\RedirectIfTwoFactorAuthenticatable;
use Laravel\Fortify\Contracts\FailedPasswordResetLinkRequestResponse as FailedPasswordResetLinkRequestResponseContract;
use Laravel\Fortify\Contracts\LoginResponse as LoginResponseContract;
use Laravel\Fortify\Contracts\RecoveryCodesGeneratedResponse as RecoveryCodesGeneratedResponseContract;
use Laravel\Fortify\Contracts\RegisterResponse as RegisterResponseContract;
use Laravel\Fortify\Contracts\SuccessfulPasswordResetLinkRequestResponse as SuccessfulPasswordResetLinkRequestResponseContract;
use Laravel\Fortify\Contracts\TwoFactorDisabledResponse as TwoFactorDisabledResponseContract;
use Laravel\Fortify\Fortify;

class FortifyServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // Fortify Response contract の差し替え (redirect + flash の Inertia 整合化)。
        // 挙動の意図は各 Response クラスの docblock を参照。
        $this->app->singleton(LoginResponseContract::class, LoginResponse::class);
        $this->app->singleton(RegisterResponseContract::class, RegisterResponse::class);
        $this->app->singleton(TwoFactorDisabledResponseContract::class, TwoFactorDisabledResponse::class);
        $this->app->singleton(RecoveryCodesGeneratedResponseContract::class, RecoveryCodesGeneratedResponse::class);
        // forgot-password は成功/失敗の両契約を enumeration-safe な同一応答へ差し替える。
        // Fortify は constructor に status を渡して make するため bind (非 singleton)
        $this->app->bind(SuccessfulPasswordResetLinkRequestResponseContract::class, EnumerationSafePasswordResetLinkResponse::class);
        $this->app->bind(FailedPasswordResetLinkRequestResponseContract::class, EnumerationSafePasswordResetLinkResponse::class);
    }

    public function boot(): void
    {
        Fortify::createUsersUsing(CreateNewUser::class);
        Fortify::updateUserProfileInformationUsing(UpdateUserProfileInformation::class);
        Fortify::updateUserPasswordsUsing(UpdateUserPassword::class);
        Fortify::resetUserPasswordsUsing(ResetUserPassword::class);
        Fortify::redirectUserForTwoFactorAuthenticationUsing(RedirectIfTwoFactorAuthenticatable::class);

        $this->configureRateLimiters();
        $this->configureViews();
    }
```

### app/Http/Middleware/HandleInertiaRequests.php (flash 共有部)
```php
    public function share(Request $request): array
    {
        // admin guard (AdminUser) 追加により user() は union 型になるため、
        // Inertia (web guard) の共有 props は User のみを対象に narrowing する
        $user = $request->user();
        if (! $user instanceof User) {
            $user = null;
        }

        return [
            ...parent::share($request),
            'appName' => config('app.name'),
            'auth' => [
                'user' => $user === null ? null : [
                    'id' => $user->id,
                    'name' => $user->name,
                    'email' => $user->email,
                    'emailVerified' => $user->hasVerifiedEmail(),
                    'twoFactorEnabled' => $user->hasEnabledTwoFactorAuthentication(),
                ],
            ],
            'organizations' => $this->organizationsProp($user),
            'currentOrganization' => $this->currentOrganizationProp($user),
            // 通知センターの未読数 (全 org 横断・自分宛のみ)。closure = Inertia partial reload で
            // 省略可能 (将来の router.reload({ only: ['notifications'] }) ポーリング拡張にも使える)
            'notifications' => [
                'unreadCount' => fn (): int => $user === null ? 0 : $user->unreadNotifications()->count(),
            ],
            'flash' => [
                'success' => $request->session()->get('success'),
                'error' => $request->session()->get('error'),
                'info' => $request->session()->get('info'),
                'warning' => $request->session()->get('warning'),
                'visitKey' => Str::uuid()->toString(),
            ],
            // 問い合わせ CTA の宛先 (内部 /contact / 外部 URL / mailto を config 駆動で切替)。
            'contact' => fn (): array => [
                'url' => app(ContactUrl::class)->resolve(),
                'kind' => app(ContactUrl::class)->kind()->value,
            ],
            // サーバ描画 <title> と同一文字列を共有し、SPA 遷移後の document.title 陳腐化を解消する
            // (resources/js/lib/document-title.ts が同期)。SeoManager は request-scoped で
            // SeoComposer と同じ実体 (二重 SoT を作らない)。controller の set / setPrivateTitle は
            // share 評価時点 (response 構築時) で反映済み。
            'title' => fn (): string => $this->seoManager->resolveDocumentTitle($request->route()?->getName()),
        ];
    }
```

### resources/js/lib/stores/flash-to-toast.ts
```ts
import { addToast } from "@/lib/stores/toast";

/**
 * Laravel flash → toast 変換。
 *
 * Inertia の shared props (flash) は Layout の再評価ごとに同じ値で再注入されるため、
 * visit ごとに一意な visitKey で de-dup し、同一 visit の flash は一度だけ消費する。
 */

export interface FlashPayload {
    success?: string | null;
    error?: string | null;
    info?: string | null;
    warning?: string | null;
    /** visit ごとに一意なキー (de-dup 用)。backend が flash と一緒に発行する */
    visitKey?: string | null;
}

/** 最後に消費した visitKey (モジュール変数で保持し、同一 visit の再評価を抑止する) */
let lastVisitKey: string | null = null;

/** flash の各キーと toast type の対応 (キーが入っていれば対応する type で addToast する) */
const FLASH_KEYS = ["success", "error", "info", "warning"] as const;

/**
 * flash payload を toast に変換して enqueue する。
 * 同じ visitKey は一度だけ消費する。visitKey 不在時は de-dup 不能のため消費しない
 * (stale props の再評価で同じ通知を二重表示しないことを優先する)。
 */
export function consumeFlash(flash: FlashPayload | null | undefined): void {
    const key = flash?.visitKey ?? null;
    if (!key || key === lastVisitKey) return;
    lastVisitKey = key;
    for (const flashKey of FLASH_KEYS) {
        const message = flash?.[flashKey];
        if (message) {
            addToast(flashKey, message);
        }
    }
}

/** de-dup 状態をリセットする (テスト用。アプリコードからは呼ばない) */
export function resetFlashConsumption(): void {
    lastVisitKey = null;
}
```

### resources/js/lib/shared-props.ts
```ts
import type { FlashPayload } from "@/lib/stores/flash-to-toast";
import type { NotificationSharedProps } from "@/types/notification";

/**
 * HandleInertiaRequests が共有する props の型 (backend が真実)。
 * ページ側は `page.props as unknown as SharedProps` で参照する。
 */

export interface AuthUser {
    id: number;
    name: string;
    email: string;
    emailVerified: boolean;
    twoFactorEnabled: boolean;
}

export interface OrganizationSummary {
    id: number;
    name: string;
    isPersonal: boolean;
}

export interface CurrentOrganization {
    id: number;
    name: string;
    /** OrganizationRole の value (organization_owner / organization_admin / organization_member) */
    role: string | null;
}

export interface SharedProps {
    appName: string;
    auth: { user: AuthUser | null };
    organizations: OrganizationSummary[];
    currentOrganization: CurrentOrganization | null;
    flash: FlashPayload;
    /** 通知センターの未読数 (全 org 横断・自分宛のみ。未ログイン時は 0) */
    notifications: NotificationSharedProps;
    /** サーバ描画 <title> と同一の完成タイトル (document-title.ts が SPA 遷移時に同期する) */
    title: string;
}
```

### resources/js/components/templates/AppLayout.svelte (現行全文)
```svelte
<script lang="ts">
    import type { Snippet } from "svelte";
    import { page } from "@inertiajs/svelte";
    import ToastContainer from "@/components/organisms/ToastContainer.svelte";
    import EmailVerificationBanner from "@/components/features/auth/EmailVerificationBanner.svelte";
    import NotificationBell from "@/components/molecules/NotificationBell.svelte";
    import { consumeFlash, type FlashPayload } from "@/lib/stores/flash-to-toast";
    import type { NotificationSharedProps } from "@/types/notification";

    /**
     * 認証済み画面用レイアウト (最小骨格)。
     * Phase 2 (組織・Team・Project 導入) でサイドバー・組織切替・通知センターを拡張する。
     * Laravel flash は consumeFlash で toast に変換する (visitKey で de-dup)。
     */
    interface Props {
        appName: string;
        children: Snippet;
        /** ヘッダー右側 (ユーザーメニュー等) */
        headerActions?: Snippet;
    }

    let { appName, children, headerActions }: Props = $props();

    $effect(() => {
        consumeFlash(page.props.flash as FlashPayload | undefined);
    });

    // メール未認証のソフトゲート案内 (organizations.store / invitations.store は
    // verified.or-back で back + error flash になるため、常設バナーで導線を先出しする)。
    const auth = $derived(page.props.auth as { user?: { emailVerified?: boolean } | null } | undefined);
    const showEmailBanner = $derived(auth?.user != null && auth.user.emailVerified === false);

    // 通知センターの未読数 (shared props)。ログイン時のみベルを常設する
    const notifications = $derived(
        page.props.notifications as NotificationSharedProps | undefined,
    );
    const showBell = $derived(auth?.user != null);
</script>

<ToastContainer />

<div class="flex min-h-screen flex-col bg-neutral text-text">
    <header class="border-b border-border bg-surface">
        <div class="mx-auto flex max-w-6xl items-center justify-between px-8 py-3">
            <a href="/dashboard" class="text-h3 text-primary">{appName}</a>
            <div class="flex items-center gap-3">
                {#if showBell}
                    <NotificationBell unreadCount={notifications?.unreadCount ?? 0} />
                {/if}
                {#if headerActions}
                    {@render headerActions()}
                {/if}
            </div>
        </div>
    </header>
    <main class="mx-auto w-full max-w-6xl flex-1 px-8 py-8">
        {#if showEmailBanner}
            <div class="mb-6">
                <EmailVerificationBanner />
            </div>
        {/if}
        {@render children()}
    </main>
</div>
```

### resources/js/pages/Dashboard.svelte (L1-60 / L150-165 抜粋)
```svelte
<script lang="ts">
    import { page, router } from "@inertiajs/svelte";
    import { Bell, Building, Camera, FolderPlus, HardDrive, Loader, Ticket } from "@lucide/svelte";
    import Badge from "@/components/atoms/Badge.svelte";
    import Button from "@/components/atoms/Button.svelte";
    import Card from "@/components/atoms/Card.svelte";
    import TextLink from "@/components/atoms/TextLink.svelte";
    import EmptyState from "@/components/molecules/EmptyState.svelte";
    import StatCard from "@/components/molecules/StatCard.svelte";
    import AppLayout from "@/components/templates/AppLayout.svelte";
    import type { SharedProps } from "@/lib/shared-props";
    import type { DashboardProps } from "@/types/dashboard";
    import { STATUS_TONES, VIDEO_MANUAL_STATUS_LABELS } from "@/types/manual";

    /**
     * ダッシュボード (ログイン直後の着地点)。PHP: DashboardController / DashboardPageData と対。
     * state (no_organization / no_project / ready) とロール (editor / shooter / viewer) で
     * 表示を分岐する。権限がない導線は非描画 (disabled ボタンは一切作らない)。
     */
    let { dashboard }: DashboardProps = $props();

    const shared = $derived(page.props as unknown as SharedProps);
    const user = $derived(shared.auth?.user ?? null);
    const appName = $derived(shared.appName ?? "");
    // 未読数は shared props (T008 ベルと同源。サーバ二重集計なし)
    const unreadCount = $derived(shared.notifications?.unreadCount ?? 0);

    const billing = $derived(dashboard.billing);
    const project = $derived(dashboard.project);
    const isEditor = $derived(dashboard.role === "editor");
    const isShooter = $derived(dashboard.role === "shooter");

    let loggingOut = $state(false);

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

    /** バイト数の可読表記 (残容量タイルの subtext 用) */
    function formatBytes(bytes: number): string {
        if (bytes >= 1024 ** 3) return `${(bytes / 1024 ** 3).toFixed(1)} GB`;
        if (bytes >= 1024 ** 2) return `${(bytes / 1024 ** 2).toFixed(1)} MB`;
        if (bytes >= 1024) return `${(bytes / 1024).toFixed(1)} KB`;
        return `${bytes} B`;
    }
</script>

{#snippet shootingCard()}
    <Card class="mt-6" testId="shooting-card">
...
        {/if}
    </Card>
{/snippet}

<AppLayout {appName}>
    {#snippet headerActions()}
        <TextLink href="/settings">設定</TextLink>
        <Button variant="ghost" size="sm" onclick={logout} loading={loggingOut}>
            ログアウト
        </Button>
    {/snippet}

    <h1 class="text-h2">{user?.name ?? ""} さん、ようこそ</h1>
    <p class="mt-1 text-caption text-text-secondary">今日のアクティビティを確認しましょう。</p>

    {#if dashboard.state === "no_organization"}
```

### resources/js/pages/Admin/Users.svelte (メンバー行 L233-302 / 招待行 L361-385 抜粋)
```svelte
                <ul class="mt-4 flex flex-col divide-y divide-border" data-testid="member-list">
                    {#each members as member (member.id)}
                        <li class="flex items-center justify-between gap-4 py-3">
                            <div class="min-w-0">
                                <div class="flex items-center gap-2">
                                    <p class="truncate text-body">{member.name}</p>
                                    {#if member.twoFactorStatus === "enabled"}
                                        <Badge tone="success">2FA</Badge>
                                    {/if}
                                    {#if member.roleState === "unassigned"}
                                        <Badge tone="warning" testId={`unassigned-${member.id}`}>
                                            未割当
                                        </Badge>
                                    {/if}
                                </div>
                                <p class="truncate text-caption text-text-secondary">
                                    {member.email}
                                </p>
                                {#if roleErrorMemberId === member.id && pageErrors.role}
                                    <FormError
                                        message={pageErrors.role}
                                        testId={`role-error-${member.id}`}
                                    />
                                {/if}
                            </div>
                            <div class="flex shrink-0 items-center gap-2">
                                {#if canResetTwoFactor(member)}
                                    <Button
                                        variant="danger-ghost"
                                        size="sm"
                                        onclick={() => openResetTwoFactorModal(member)}
                                        testId={`reset-two-factor-${member.id}`}
                                    >
                                        2FA 解除
                                    </Button>
                                {/if}
                                {#if canChangeRole(member)}
                                    <Select
                                        value={member.roleState === "unassigned"
                                            ? ""
                                            : member.roleState}
                                        aria-label={`${member.name} のロール`}
                                        onchange={(event) =>
                                            changeRole(member, event.currentTarget.value)}
                                        testId={`member-role-${member.id}`}
                                    >
                                        {#if member.roleState === "unassigned"}
                                            <option value="">未割当（選択してください）</option>
                                        {/if}
                                        {#each ROLE_OPTIONS as option (option.value)}
                                            <option value={option.value}>{option.label}</option>
                                        {/each}
                                    </Select>
                                    <Button
                                        variant="danger-ghost"
                                        size="sm"
                                        onclick={() => openRemoveDialog(member)}
                                        testId={`remove-member-${member.id}`}
                                    >
                                        削除
                                    </Button>
                                {:else}
                                    <span class="text-caption text-text-secondary">
                                        {member.roleLabel}
                                    </span>
                                {/if}
                            </div>
                        </li>
                    {/each}
                </ul>
...
                {:else}
                    <ul
                        class="mt-4 flex flex-col divide-y divide-border"
                        data-testid="invitation-list"
                    >
                        {#each invitations as invitation (invitation.id)}
                            <li class="flex items-center justify-between gap-4 py-3">
                                <p class="truncate text-body">{invitation.email}</p>
                                <div class="flex shrink-0 items-center gap-3">
                                    <p class="text-caption text-text-secondary">
                                        {invitation.roleLabel} ・ 期限 {invitation.expiresAt}
                                    </p>
                                    <Button
                                        variant="danger-ghost"
                                        size="sm"
                                        onclick={() => openRevokeDialog(invitation)}
                                        testId={`revoke-invitation-${invitation.id}`}
                                    >
                                        取消
                                    </Button>
                                </div>
                            </li>
                        {/each}
                    </ul>
                {/if}
```

### tests/Feature/Auth/FortifyResponseTest.php (現行全文)
```php
<?php

declare(strict_types=1);

use App\Models\User;
use Illuminate\Support\Facades\Notification;

/*
 * Fortify Response contract bind (app/Http/Responses/Fortify/) の応答契約。
 * Login / Register の redirect 契約は AuthenticationTest / RegistrationTest が担う。
 */

test('forgot-password は user 在/不在で同一応答 (enumeration 抑止)', function (): void {
    Notification::fake();
    User::factory()->create(['email' => 'exists@example.com']);

    $existing = $this->from('/forgot-password')->post('/forgot-password', [
        'email' => 'exists@example.com',
    ]);
    $missing = $this->from('/forgot-password')->post('/forgot-password', [
        'email' => 'missing@example.com',
    ]);

    // どちらも同一の status flash + redirect back (成功/失敗を区別させない)
    $existing->assertRedirect('/forgot-password');
    $missing->assertRedirect('/forgot-password');
    $existing->assertSessionHas('status', 'パスワードリセット用のリンクをメールで送信しました。');
    $missing->assertSessionHas('status', 'パスワードリセット用のリンクをメールで送信しました。');
    $missing->assertSessionDoesntHaveErrors();
});
```

### vendor/laravel/fortify EmailVerificationNotificationController@store (差し替え対象の呼び出し元)
```php
class EmailVerificationNotificationController extends Controller
{
    /**
     * Send a new email verification notification.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return mixed
     */
    public function store(Request $request)
    {
        if ($request->user()->hasVerifiedEmail()) {
            return $request->wantsJson()
                ? new JsonResponse('', 204)
                : app(RedirectAsIntended::class, ['name' => 'email-verification']);
        }

        $request->user()->sendEmailVerificationNotification();

        return app(EmailVerificationNotificationSentResponse::class);
    }
}
```
