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
  「認証メール再送は success flash を返す (web)」
  - **前提の固定**: `verification.send` ルートの middleware は `auth:web` + `throttle:6,1`
    （`config('fortify.limiters.verification')` 既定。`vendor/laravel/fortify/routes/routes.php` L98-100）。
    テストは **`actingAs(User::factory()->unverified()->create())` の 1 リクエストのみ**発行し、
    throttle 上限 (6/min) に構造的に触れない設計とする（`withoutMiddleware` 等の抑制は使わない。
    `RefreshDatabase` はグローバル適用・`--parallel` 実行でユーザー毎にレートキーも独立）
  - `Notification::fake()` → `$this->from('/email/verify')->post('/email/verification-notification')`
  - `assertRedirect('/email/verify')`（`back()` 契約）
  - `assertSessionHas('success', '認証メールを再送信しました。')`
  - `assertSessionMissing('status')`（flash キー統一ポリシー: status 併用の誤実装を検出）
  - `Notification::assertSentTo($user, VerifyEmail::class)`（再送自体が起きたことの確認）
  - テストコメントに「JSON 分岐は Fortify 元実装互換のため wantsJson/202 を維持（既存 3 クラスの
    expectsJson とあえて揃えない）」を明記し、将来の統一リファクタでの誤変換を防ぐ
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
（diff を flash キー変更に限定する）。docblock に「`STATUS_MESSAGE` は Fortify の status 言語キーに
対応する**メッセージ内容**の意味であり、flash キー名 (`success`) とは無関係」と 1 行追記して
将来の混同を防ぐ。

### PHPStan適合チェック
- [x] 戻り値の型が明示されている（既存シグネチャ不変）
- [x] null安全（変更なし）
- [x] DTOを返している（Response object）
- [x] Genericsの型パラメータ（該当なし）

### テスト計画
- [ ] 既存テスト更新 (`tests/Feature/Auth/FortifyResponseTest.php` L27-28):
  `assertSessionHas('status', ...)` → `assertSessionHas('success', 'パスワードリセット用のリンクをメールで送信しました。')`。
  **user 在/不在の両ケースで対に** `assertSessionHas('success', 同一文言)` + `assertSessionMissing('status')` を
  検証する（enumeration 抑止 = 同一メッセージだけでなく**同一キー**であることを固定。片側だけ status が
  残る誤実装も検出できる）。既存のアサーション構造（existing/missing の対比較）は維持
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
  - 「`notifications` が undefined でもクラッシュせず unreadCount 0 相当で描画する」
    （shared props の閉包 (closure) 共有が partial reload で省略されるケース・テスト環境での
    未定義ケースの両方をカバー。`shared.notifications?.unreadCount ?? 0` の回帰固定）
  - 「**ページ固有アクションの snippet**（独自 testId を持つ別の操作、例 `page-action`）を渡しても、
    常設ナビ `nav-settings` / `nav-logout` は**各 1 個**描画され、snippet 側アクションも共存する」
    （`getAllByTestId("nav-settings").length === 1` 等。※AppLayout に重複排除機構は無いため、
    snippet に設定/ログアウトそのものを渡すケースはテストしない — 再注入の防止は
    Dashboard 側のページテスト（下記）で固定する）
- [ ] 既存更新 (Vitest, `tests/js/pages/Dashboard.test.ts`):
  Dashboard が page-local の設定/ログアウトを持たないこと（AppLayout 常設化後の重複排除の回帰）。
  **検証方法**: テスト環境は page store 未設定 = auth なしのため AppLayout の常設ナビは描画されない。
  この状態で `queryByRole("link", { name: "設定" })` と `queryByRole("button", { name: "ログアウト" })` が
  **どちらも null** であることを検証する（旧実装の page-local snippet が残っていれば auth なしでも
  `headerActions` は描画されるため、この検証が旧実装残存を確実に検出する）。
  テスト意図として「logout POST は AppLayout の単一ハンドラの責務であり、Dashboard 内のイベントから
  `router.post('/logout')` を直接呼ばない」ことをコメントに明記する
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
    （jsdom はレイアウト計算をしないため、クラス不変条件を横スクロール回避のプロキシとして固定する）
  - **対象要素の特定は既存 `data-testid` 起点で行い、DOM 順序に依存しない**:
    メンバー行は `screen.getByTestId("member-role-3").closest("li")`（ロール select 起点）、
    操作ブロックは同 select の親 div (`element.parentElement`) を辿る。招待行は
    `screen.getByTestId("revoke-invitation-10").closest("li")` 起点
  - **fixture を bug-hunt 再現条件（最悪幅）に統一**: `membersFixture` の id=3 を
    `roleState: "unassigned"` **かつ** `twoFactorStatus: "enabled"` に変更する
    （閲覧者は id=1 の owner (isSelf) なので `canResetTwoFactor` は unassigned でも真）。
    これにより **同一行**に「2FA バッジ + 未割当バッジ + 2FA 解除ボタン + 未割当 select
    （『未割当（選択してください）』option）+ 削除ボタン」が揃い、F-14 実測の最悪幅構成を
    `member-role-3` 起点の同じ行で固定できる。同行に `reset-two-factor-3` /
    `remove-member-3` が存在することもあわせて検証する（既存テストの id=3 に依存する
    アサーション（未割当バッジ等）への影響は、2FA バッジ追加のみで壊れない想定だが実装時に確認）
  - 「招待行も同様の縦積みクラスを持つ」（`invitation-list` 側）
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
