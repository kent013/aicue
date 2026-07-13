# アプリの使命（North Star）

**AI-CUE** は、現場に既にある作業手順書(SOP)を起点に、AI が撮るべきカットを設計した動画シナリオを生成し、そのシナリオをスマホ(PWA)でナビゲーション撮影することで、専門知識ゼロの現場作業者でも標準化されたマニュアル動画を作れるようにする（「思考ゼロ・編集ゼロ」）。

# 禁止事項（AGENTS.md）

1. テストなしの実装完了報告（不変条件は Architecture/Feature テストへの登録まで含めて「実装済み」）
2. PHPStan エラーの widen・baseline 化
3. dev DB への破壊操作をエージェント判断で実行
4. `response()->json()` の直書き（DTO / JsonResource / Inertia を使う。仕様固定 endpoint のみ例外）
5. LLM 呼び出しの Prism 直呼び / 6. prompt 文字列のコード直書き
7. 操作系 POST の応答での `redirect()->intended()`
8. 必須条件未充足を理由にボタンを disabled にする UI

# 思考原則・ツール使用制限

まず仮説を立てろ。先人の知恵（Laravel/Fortify 公式作法）を優先。機能の名前に立ち返れ。
コマンド実行・ファイル書き込みは一切行わず、提供テキストの分析に集中（ファイル読み込みは可）。

---

# あなたの役割

あなたは経験豊富な Web アプリケーションアーキテクトです。Laravel + Svelte アプリの詳細設計をレビューしてください。

【前提環境】PHP 8.4 + Laravel 12 + Svelte 5 + Inertia.js + TypeScript / PHPStan level 10 / Pest / Fortify / DTO + JsonResource パターン。

【レビュー観点】
1. コードの正確性（ロジックエラー、エッジケース、null 安全性）
2. 既存コードとの整合性（命名規約、パターン、API）
3. PHPStan level 10 適合性（型安全性、generics、Assert、trans() の戻り型）
4. テスト計画の網羅性（各施策に Pest/vitest、RefreshDatabase グローバル）
5. DTO/JsonResource パターンの遵守（Fortify contract 実装の妥当性）
6. Inertia Props vs API Response の使い分け（expectsJson/wantsJson の分岐）
7. 副作用・後退リスク
8. 波及変更の網羅性（TS 型定義、テストが変更対象に含まれるか）
9. セキュリティ（password reset のトークン検証、enumeration、認可）
10. DESIGN.md 準拠 / 11. Atomic Design 準拠（UI 変更を含む場合）

【出力形式】
- 各施策ごとに判定: APPROVE / REQUEST_CHANGES
- 指摘は [Critical] [Warning] [Suggestion] で分類、Critical/Warning には修正案を必ず添える
- 全体判定: APPROVED / CHANGES_REQUESTED
- 日本語で出力

---

## 詳細設計書

# 詳細設計: save-success-toast

## 使命・制約（絶対遵守）

### アプリの使命（North Star）
**AI-CUE** は、現場に既にある作業手順書(SOP)を起点に、AI が撮るべきカットを設計した動画シナリオを生成し、
そのシナリオをスマホ(PWA)でナビゲーション撮影することで、専門知識ゼロの現場作業者でも標準化された
マニュアル動画を作れるようにする。「思考ゼロ・編集ゼロ」— 台本作成・撮影判断・編集の 3 ハードルを
AI とナビ撮影で肩代わりする。競合(tebiki)と異なり標準作業を起点に AI が教材設計し撮影を指示する。
熟練者の暗黙知を動画マニュアルという形式知へ変換する装置(SECI)。

本施策の位置づけ: 差別化機能の追加ではなく、**「思考ゼロで安心して操作できる土台の補強」**。
設定変更の成否が即座に伝わることは、現場作業者の操作不安・二重送信を減らす基礎 UX の底上げである。

### 禁止事項（AGENTS.md）
1. テストなしの実装完了報告（不変条件は Architecture/Feature テストへの登録まで含めて「実装済み」）
2. PHPStan エラーの widen・baseline 化
3. dev DB への破壊操作をエージェント判断で実行
4. `response()->json()` の直書き（DTO / JsonResource / Inertia を使う。仕様固定 endpoint のみ例外）
5. LLM 呼び出しの Prism 直呼び / 6. prompt 文字列のコード直書き
7. 操作系 POST の応答での `redirect()->intended()`（ログイン直後フロー専用）
8. 必須条件未充足を理由にボタンを disabled にする UI（押下時にエラー表示。DESIGN.md）

> §禁止4 について: 本施策の Fortify Response 実装は `JsonResponse` / `RedirectResponse` を返す
> Fortify contract 実装であり、Fortify 既定と同じ返り値形。既存の
> `TwoFactorDisabledResponse` / `EnumerationSafePasswordResetLinkResponse` と同型で
> `response()->json()` の直書きには当たらない（DTO/JsonResource は不要）。

### コーディングルール
- **PHPStan level 10** 必須（`composer phpstan`）
- **Pest**（`composer test`）/ **RefreshDatabase** グローバル + `--parallel`（個別 `DatabaseTransactions` 禁止）
- テストデータは Factory 生成（`User::factory()`）
- `declare(strict_types=1)` + 日本語コメント。Controller は薄く（本施策では Controller 変更なし）
- フロントは Svelte 5 runes + DS token。フォームは FormField/atom 経由（本施策は既存のまま）
- 検証: `composer test` / `composer phpstan` / `vendor/bin/pint --test` / `pnpm lint` /
  `pnpm typecheck` / `pnpm test` / `pnpm build`

## 概念設計リファレンス
`devnotes/20260713-1713-save-success-toast/conceptual-design.md`
（Codex `gpt-5.4` レビュー Round 1 で APPROVED。Warning 1 件＝GET 失敗時 error 文言の明確化を本設計に反映済み）

## 施策一覧

| # | 施策名 | 変更ファイル | 優先度 |
|---|--------|------------|--------|
| 1 | プロフィール更新の success flash 化 | `app/Http/Responses/Fortify/ProfileUpdatedResponse.php`（新規）, `FortifyServiceProvider.php` | High |
| 2 | パスワード変更の success flash 化 | `app/Http/Responses/Fortify/PasswordUpdatedResponse.php`（新規）, `FortifyServiceProvider.php` | High |
| 3 | パスワードリセットの success flash 化 | `app/Http/Responses/Fortify/PasswordResetResponse.php`（新規）, `FortifyServiceProvider.php` | High |
| 4 | 再生成 toast の正本一本化（client success toast 削除 + サーバ文言集約） | `resources/js/pages/Settings/Security.svelte`, `RecoveryCodesGeneratedResponse.php` | Medium |
| 5 | Feature テスト（3 操作の web success flash + JSON 契約） | `tests/Feature/Auth/FortifyResponseTest.php` | High |
| 6 | vitest 更新（再生成 happy path で client success toast 非発火 / GET 失敗文言） | `tests/js/pages/SettingsSecurity.test.ts` | Medium |

> 全施策とも **Controller は変更しない**（処理は Fortify Response contract 実装に閉じる）。
> 施策 1〜3 は互いに独立。施策 4 は施策 5/6 のテストと対で完成させる。

---

## 施策1: プロフィール更新の success flash 化

### 変更箇所
- 新規: `app/Http/Responses/Fortify/ProfileUpdatedResponse.php`
- 追記: `app/Providers/FortifyServiceProvider.php`（`register()` に singleton bind 1 行 + import）

### 波及変更
- TypeScript 型定義: なし（`flash.success` は既に `FlashPayload` に定義済み）
- API Resource/DTO: なし（Fortify contract 実装。DTO 不要）
- テストファイル: `tests/Feature/Auth/FortifyResponseTest.php`（施策5）

### 現行コード
未 bind のため Fortify 既定 `Laravel\Fortify\Http\Responses\ProfileInformationUpdatedResponse` が使われる:
```php
return $request->wantsJson()
    ? new JsonResponse('', 200)
    : back()->with('status', Fortify::PROFILE_INFORMATION_UPDATED);
```
→ `status` キーは flash-to-toast が gating するため toast が出ない。

### 変更後コード
```php
<?php

declare(strict_types=1);

namespace App\Http\Responses\Fortify;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Laravel\Fortify\Contracts\ProfileInformationUpdatedResponse as ProfileInformationUpdatedResponseContract;

/**
 * プロフィール更新後のレスポンス (Fortify contract bind)。
 *
 * Fortify 既定は `back()->with('status', ...)` を返すが、flash-to-toast は
 * status を意図的に gating (toast 化しない)。更新完了を toast でフィードバック
 * するため web のみ `success` キーへ寄せる。expectsJson (XHR / API) は
 * Fortify 既定どおり JSON 200 を維持する。
 */
final class ProfileUpdatedResponse implements ProfileInformationUpdatedResponseContract
{
    private const string SUCCESS_MESSAGE = 'プロフィールを更新しました。';

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

FortifyServiceProvider::register() に追記:
```php
use App\Http\Responses\Fortify\ProfileUpdatedResponse;
use Laravel\Fortify\Contracts\ProfileInformationUpdatedResponse as ProfileInformationUpdatedResponseContract;
// ...
$this->app->singleton(ProfileInformationUpdatedResponseContract::class, ProfileUpdatedResponse::class);
```

> **`expectsJson()` 採用の根拠**: 既存の custom Response 家系
> (`TwoFactorDisabledResponse` / `RecoveryCodesGeneratedResponse`) と揃える。Inertia の
> PUT `/user/profile-information`（Accept: text/html）は `expectsJson()`=false のため
> `back()->with('success')` 分岐に入り、`AppLayout` の `consumeFlash` が toast 化する。

### PHPStan適合チェック
- [x] 戻り値型 `JsonResponse|RedirectResponse` を明示
- [x] null 非依存（Assert 不要）
- [x] DTO 返却なし（Fortify contract のため対象外＝既存 family と同型）
- [x] Generics なし

### テスト計画（施策5に集約）
- [ ] web: PUT `/user/profile-information` 成功 → `assertRedirect` + `assertSessionHas('success', 'プロフィールを更新しました。')` + `assertSessionMissing('status')`
- [ ] JSON: `putJson('/user/profile-information', ...)` → `assertOk`（本文空 200）

### リスク
- 既存で `status` = `PROFILE_INFORMATION_UPDATED` を参照する箇所があれば表示が変わる。
  → grep 済み: フロントは `status` を toast 化していない（gating）ため影響なし。

---

## 施策2: パスワード変更の success flash 化

### 変更箇所
- 新規: `app/Http/Responses/Fortify/PasswordUpdatedResponse.php`
- 追記: `app/Providers/FortifyServiceProvider.php`（singleton bind 1 行 + import）

### 波及変更
- TypeScript 型定義: なし / API Resource/DTO: なし
- テストファイル: `tests/Feature/Auth/FortifyResponseTest.php`（施策5）

### 変更後コード
施策1 と同型。contract = `PasswordUpdateResponse`、
`SUCCESS_MESSAGE = 'パスワードを変更しました。'`、クラス名 `PasswordUpdatedResponse`。
```php
final class PasswordUpdatedResponse implements PasswordUpdateResponseContract
{
    private const string SUCCESS_MESSAGE = 'パスワードを変更しました。';

    public function toResponse($request): JsonResponse|RedirectResponse
    {
        if ($request->expectsJson()) {
            return new JsonResponse('', 200);
        }

        return back()->with('success', self::SUCCESS_MESSAGE);
    }
}
```
bind:
```php
$this->app->singleton(PasswordUpdateResponseContract::class, PasswordUpdatedResponse::class);
```

> Settings/Index.svelte 側の `passwordForm.put('/user/password', { onSuccess: () => passwordForm.reset() })`
> は変更不要（`back()` 後 `AppLayout` の consumeFlash が toast 化）。

### PHPStan適合チェック / テスト計画 / リスク
施策1 と同じ（web success flash + JSON 200）。テスト文言は `'パスワードを変更しました。'`。

---

## 施策3: パスワードリセットの success flash 化

### 変更箇所
- 新規: `app/Http/Responses/Fortify/PasswordResetResponse.php`
- 追記: `app/Providers/FortifyServiceProvider.php`（**bind（非 singleton）** + import）

### 波及変更
- TypeScript 型定義: なし / API Resource/DTO: なし
- テストファイル: `tests/Feature/Auth/FortifyResponseTest.php`（施策5）

### 現行コード
未 bind のため Fortify 既定（constructor に `$status` を取る）:
```php
return $request->wantsJson()
    ? new JsonResponse(['message' => trans($this->status)], 200)
    : redirect(Fortify::redirects('password-reset', config('fortify.views', true) ? route('login') : null))
        ->with('status', trans($this->status));
```
→ login へ redirect するが `status` は gating され toast なし。

### 変更後コード
```php
<?php

declare(strict_types=1);

namespace App\Http\Responses\Fortify;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Laravel\Fortify\Contracts\PasswordResetResponse as PasswordResetResponseContract;
use Laravel\Fortify\Fortify;

/**
 * パスワードリセット完了後のレスポンス (Fortify contract bind)。
 *
 * Fortify 既定は login へ redirect し `status` を flash するが、flash-to-toast は
 * status を意図的に gating する。リセット完了を login 画面で toast 表示するため
 * web のみ `success` キーへ寄せる (AuthLayout も consumeFlash を持つ)。
 * JSON 分岐は Fortify 既定 (trans(status) メッセージ) を維持し API 契約を壊さない。
 */
final class PasswordResetResponse implements PasswordResetResponseContract
{
    private const string SUCCESS_MESSAGE = 'パスワードを変更しました。ログインしてください。';

    /**
     * Fortify は status 言語キー (passwords.reset) を constructor で渡す。
     * JSON 応答では既定どおり localize した status を返し、web では汎用 success へ寄せる。
     */
    public function __construct(private readonly string $status) {}

    /**
     * @param  Request  $request
     */
    public function toResponse($request): JsonResponse|RedirectResponse
    {
        if ($request->expectsJson()) {
            return new JsonResponse(['message' => trans($this->status)], 200);
        }

        return redirect(Fortify::redirects('password-reset', route('login')))
            ->with('success', self::SUCCESS_MESSAGE);
    }
}
```
bind（constructor 引数 `$status` があるため **bind**、singleton にしない）:
```php
use App\Http\Responses\Fortify\PasswordResetResponse;
use Laravel\Fortify\Contracts\PasswordResetResponse as PasswordResetResponseContract;
// ...
$this->app->bind(PasswordResetResponseContract::class, PasswordResetResponse::class);
```

> `trans($this->status)` は string を返す（level 10 で `mixed` 化する場合は
> `(string) trans($this->status)` でキャスト、または `__()` の string overload を確認）。
> 実装時に PHPStan が `trans()` を `array|string` と推論する場合は
> `is_string()` early-return か明示キャストで narrow する。

### PHPStan適合チェック
- [x] 戻り値型 `JsonResponse|RedirectResponse` 明示
- [x] `$status` は `readonly string`
- [ ] **注意**: `trans($this->status)` の戻り型 narrowing（上記 note）
- [x] DTO 返却なし（Fortify contract）

### テスト計画（施策5）
- [ ] web: 有効 token で POST `/reset-password` 成功 → `assertRedirect(route('login'))` +
  `assertSessionHas('success', 'パスワードを変更しました。ログインしてください。')` + `assertSessionMissing('status')`
- [ ] JSON: `postJson('/reset-password', ...)` 成功 → `assertOk` + `assertJson(['message' => ...])`
  （trans('passwords.reset') 相当）

### リスク
- リセットは Password Broker のトークン検証を通す必要があり、テストでは
  `Password::createToken($user)` で有効トークンを生成して POST する。
- redirect 先が login に変わらないこと（`Fortify::redirects('password-reset', route('login'))`
  は既定と同一表現）を確認。

---

## 施策4: 再生成 toast の正本一本化

### 変更箇所
- `resources/js/pages/Settings/Security.svelte`（`handleRegenerateSuccess()` L134-151）
- `app/Http/Responses/Fortify/RecoveryCodesGeneratedResponse.php`（`SUCCESS_MESSAGE` L22）

### 波及変更
- TypeScript 型定義: なし
- API Resource/DTO: なし
- テストファイル: `tests/js/pages/SettingsSecurity.test.ts`（施策6）。
  Feature 側は `TwoFactorRecoveryCodesStepUpTest`（postJson=JSON 分岐のみ assert、
  flash 文言は未 assert）のため影響なし。

### 現行コード（Security.svelte）
```ts
async function handleRegenerateSuccess(): Promise<void> {
    regenerateDialogOpen = false;
    recoveryCodes = [];
    if (await loadRecoveryCodes()) {
        addToast(
            "success",
            "リカバリコードを再生成しました。新しいコードを保管してください。",
        );
        await tick();
        recoveryCodesPanel?.focus();
        return;
    }
    addToast(
        "error",
        "新しいコードの取得に失敗しました。以前のコードは既に無効です。「リカバリコードを表示」から再取得してください。",
    );
}
```

### 変更後コード（Security.svelte）
サーバ flash（`RecoveryCodesGeneratedResponse` の success）を成功 toast の唯一の源とし、
client 側 success `addToast` を削除。GET 失敗時の error 文言は「再生成は成功／表示取得が失敗」と
別事象であることを明示（Codex Warning 反映）:
```ts
async function handleRegenerateSuccess(): Promise<void> {
    regenerateDialogOpen = false;
    // 旧コードは失効済み。誤保管を防ぐため画面から即クリアする。
    // 成功 toast はサーバ flash (RecoveryCodesGeneratedResponse) が単一の源として出す
    // (二重発火 F-L1 の解消)。ここでは client 楽観 toast を出さない。
    recoveryCodes = [];
    if (await loadRecoveryCodes()) {
        await tick();
        recoveryCodesPanel?.focus();
        return;
    }
    // GET 失敗は「表示取得の失敗」= 再生成成功とは別事象。成功 toast と並んでも
    // 矛盾しないよう対象を明示する。
    addToast(
        "error",
        "リカバリコードは再生成されましたが、新しいコードの表示取得に失敗しました。旧コードは既に無効です。「リカバリコードを表示」から再取得してください。",
    );
}
```
- `addToast` の import は `loadQrCode` / `showRecoveryCodes` 等でも使用中のため残す。

### 変更後コード（RecoveryCodesGeneratedResponse.php）
保管を促す文言をサーバへ集約（client 削除分を補う）:
```php
private const string SUCCESS_MESSAGE = 'リカバリコードを再生成しました。新しいコードを保管してください。';
```
他は変更なし（web=`back()->with('success')` / expectsJson=JSON 200）。

### PHPStan適合チェック
- 該当なし（PHP 変更は定数文字列のみ。Svelte は TS）
- TypeScript: `pnpm typecheck` green（`addToast` 呼び出し型は不変）

### テスト計画（施策6）
- [ ] happy path（POST 成功 → GET 成功）: **client `addToast('success', ...)` が呼ばれない**こと、
  新コード表示 + パネル focus は従来どおり。
- [ ] GET 失敗: `addToast('error', ...)` が 1 回、文言に「再生成されました」「表示取得に失敗」を含む。
- [ ] 既存 stepup Feature テストは不変（回帰なし確認）。

### リスク
- サーバ flash はサーバ往復（Inertia redirect）に依存するため、happy path で toast が出る条件は
  「POST が 200/redirect を返し `consumeFlash` が発火」。既存の `router.post` 成功時に
  `back()->with('success')` が返る配線は現状動作しており（F-L1 は二重発火＝サーバ flash は既に出ている）、
  削除は client 側のみで安全。
- vitest はサーバ往復をモックするため、サーバ flash 由来 toast は unit では検証しない
  （施策5 の Feature/契約テストが web success flash を担保）。

---

## 施策5: Feature テスト（Fortify Response 契約）

### 変更箇所
- `tests/Feature/Auth/FortifyResponseTest.php`（テスト追加。既存の flash 統一ポリシー doc の正本）

### 追加テスト
```
- profile 更新は success flash を返す (web): actingAs(user)->from('/settings')
  ->put('/user/profile-information', name/email) → assertRedirect('/settings')
  + assertSessionHas('success', 'プロフィールを更新しました。') + assertSessionMissing('status')
- profile 更新は JSON に 200 を返す: putJson(...) → assertStatus(200)
- password 変更は success flash を返す (web): current_password/password を渡し
  → assertSessionHas('success', 'パスワードを変更しました。') + assertSessionMissing('status')
- password 変更は JSON に 200: putJson('/user/password', ...) → assertStatus(200)
- password reset は success flash + login redirect (web): Password::createToken(user) の
  有効トークンで post('/reset-password', token/email/password)
  → assertRedirect(route('login')) + assertSessionHas('success', 'パスワードを変更しました。ログインしてください。')
  + assertSessionMissing('status')
- password reset は JSON に 200 + message: postJson('/reset-password', ...) → assertOk + assertJsonStructure(['message'])
```

### PHPStan / 規約
- [x] `User::factory()` でユーザ生成（手組み禁止）
- [x] `RefreshDatabase` グローバル（個別 `DatabaseTransactions` 不使用）
- [x] `Password::createToken` はファサード経由（Notification::fake 不要だがリセットは token 検証のみ）
- 注意: password 更新系は `current_password` current-password ルール検証があるため、
  factory の既定パスワード（`password`）を current に指定する。
- 注意: profile 更新の email 変更は MustVerifyEmail 再検証をトリガしうるが、
  flash（success）assert には影響しない。テストは success flash と status 不在の固定に集中する。

### テスト計画
- [ ] 上記 6 ケースを追加（web 3 + JSON 3）
- [ ] `--parallel` で green

---

## 施策6: vitest 更新（Security ページ）

### 変更箇所
- `tests/js/pages/SettingsSecurity.test.ts`（既存 L204 の success toast assert を置換 + GET 失敗ケース更新）

### 変更内容
- 「POST 成功 → GET 成功で新コードを表示し success トーストを出す」テストを
  「POST 成功 → GET 成功で新コードを表示（success トーストは client から出さない＝サーバ flash 委譲）」へ改める:
  - `expect(addToastMock).not.toHaveBeenCalledWith('success', expect.anything())`
  - 新コード表示（`recovery-codes` testId にコード）+ focus は従来 assert を維持。
- GET 失敗ケース: `addToastMock` が `'error'` で 1 回、文言に「再生成されました」「表示取得に失敗」を含むことを assert。

### テスト計画
- [ ] happy path: client success toast 非発火 + 新コード表示
- [ ] GET 失敗: error toast の文言更新に追随
- [ ] `pnpm test` green

---

## 実装モード

| 項目 | 内容 |
|------|------|
| 推奨モード | **incremental** |
| 判断根拠 | 既存 Fortify Response family / flash-to-toast 正本の上に、同型クラス追加と bind、client 1 箇所削除という小さな漸進変更。新規アーキテクチャや大規模リファクタを伴わず、既存テスト資産（FortifyResponseTest / SettingsSecurity.test.ts）に追記する形で完結する。 |
| 競合リスク | 低。施策 1〜3 は独立クラス新規追加 + register への行追加（近接行だが別行）。施策 4 は別ファイル（Svelte + 定数）。同時編集は FortifyServiceProvider::register のみで軽微。 |

## 最終確認（使命・禁止事項）
- 全施策は「思考ゼロで安心して操作できる土台の補強」に寄与（使命整合）。
- §禁止4: Fortify contract 実装（既存 family と同型）で `response()->json()` 直書きに非該当。
- §禁止1: 全施策にテスト（Feature 施策5 / vitest 施策6）を対で用意。
- §禁止2: PHPStan は型明示 + `trans()` narrowing note で level 10 を通す設計。
- Controller 変更なし（処理は Response contract に閉じる）。flash-to-toast/toast store は不変。


---

## 関連する現行コード

### app/Http/Responses/Fortify/RecoveryCodesGeneratedResponse.php（既存パターンの見本 + 施策4 対象）
```php
final class RecoveryCodesGeneratedResponse implements RecoveryCodesGeneratedResponseContract
{
    private const string SUCCESS_MESSAGE = 'リカバリコードを再生成しました。';

    public function toResponse($request): JsonResponse|RedirectResponse
    {
        if ($request->expectsJson()) {
            return new JsonResponse('', 200);
        }
        return back()->with('success', self::SUCCESS_MESSAGE);
    }
}
```

### app/Http/Responses/Fortify/TwoFactorDisabledResponse.php（同型の見本）
`SUCCESS_MESSAGE = '二要素認証を無効化しました。'` / `expectsJson()` ? JSON 200 : `back()->with('success', ...)`。

### Fortify 既定 Response（差し替え対象の元実装）
- ProfileInformationUpdatedResponse: `wantsJson() ? JsonResponse('',200) : back()->with('status', Fortify::PROFILE_INFORMATION_UPDATED)`
- PasswordUpdateResponse: `wantsJson() ? JsonResponse('',200) : back()->with('status', Fortify::PASSWORD_UPDATED)`
- PasswordResetResponse(`__construct(string $status)`): `wantsJson() ? JsonResponse(['message'=>trans($this->status)],200) : redirect(Fortify::redirects('password-reset', config('fortify.views',true)?route('login'):null))->with('status', trans($this->status))`

### app/Providers/FortifyServiceProvider.php register()（bind 追加先）
```php
public function register(): void
{
    $this->app->singleton(LoginResponseContract::class, LoginResponse::class);
    $this->app->singleton(RegisterResponseContract::class, RegisterResponse::class);
    $this->app->singleton(TwoFactorDisabledResponseContract::class, TwoFactorDisabledResponse::class);
    $this->app->singleton(RecoveryCodesGeneratedResponseContract::class, RecoveryCodesGeneratedResponse::class);
    $this->app->singleton(EmailVerificationNotificationSentResponseContract::class, VerificationNotificationSentResponse::class);
    $this->app->bind(SuccessfulPasswordResetLinkRequestResponseContract::class, EnumerationSafePasswordResetLinkResponse::class);
    $this->app->bind(FailedPasswordResetLinkRequestResponseContract::class, EnumerationSafePasswordResetLinkResponse::class);
}
```

### resources/js/lib/stores/flash-to-toast.ts（consumeFlash: success/error/info/warning のみ toast 化、status は gating）
```ts
const FLASH_KEYS = ["success", "error", "info", "warning"] as const;
export function consumeFlash(flash: FlashPayload | null | undefined): void {
    const key = flash?.visitKey ?? null;
    if (!key || key === lastVisitKey) return;
    lastVisitKey = key;
    for (const flashKey of FLASH_KEYS) {
        const message = flash?.[flashKey];
        if (message) addToast(flashKey, message);
    }
}
```

### resources/js/pages/Settings/Security.svelte（施策4 対象。handleRegenerateSuccess + regenerateRecoveryCodes 抜粋）
```ts
async function handleRegenerateSuccess(): Promise<void> {
    regenerateDialogOpen = false;
    recoveryCodes = [];
    if (await loadRecoveryCodes()) {
        addToast("success", "リカバリコードを再生成しました。新しいコードを保管してください。");
        await tick();
        recoveryCodesPanel?.focus();
        return;
    }
    addToast("error", "新しいコードの取得に失敗しました。以前のコードは既に無効です。「リカバリコードを表示」から再取得してください。");
}
function regenerateRecoveryCodes(): void {
    guardWithRecentAuth(() => {
        router.post("/user/two-factor-recovery-codes", {}, {
            preserveScroll: true,
            onStart: () => { regenerating = true; },
            onSuccess: () => { void handleRegenerateSuccess(); },
            onError: () => { regenerateDialogOpen = false; addToast("error", "リカバリコードの再生成に失敗しました。"); },
            onFinish: () => { regenerating = false; },
        });
    });
}
```

### resources/js/pages/Settings/Index.svelte（profile/password フォーム。onSuccess なし/reset のみ）
```ts
profileForm.put("/user/profile-information", { errorBag: "updateProfileInformation", preserveScroll: true });
passwordForm.put("/user/password", { errorBag: "updatePassword", preserveScroll: true, onSuccess: () => passwordForm.reset() });
```

### AppLayout / AuthLayout はいずれも ToastContainer を mount し consumeFlash(flash) を呼ぶ。
