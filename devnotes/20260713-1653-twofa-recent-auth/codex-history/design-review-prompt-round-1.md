# アプリの使命・禁止事項・思考原則（レビュー基準）

## アプリの使命（North Star — AGENTS.md より）

**AI-CUE** は、現場に既にある**作業手順書(SOP)を起点に**、AI が撮るべきカットを設計した**動画シナリオ**を生成し、そのシナリオを**スマホ(PWA)でナビゲーション撮影**することで、専門知識ゼロの現場作業者でも**標準化されたマニュアル動画**を作れるようにする。

## 禁止事項（AGENTS.md より）

1. テストなしの実装完了報告（Architecture/Feature テスト登録まで含めて「実装済み」）
2. PHPStan エラーの widen・baseline 化
3. dev DB への破壊操作
4. `response()->json()` の直書き（DTO / JsonResource / Inertia。仕様固定 endpoint のみ例外）
5. LLM 呼び出しの Prism 直呼び / 6. prompt 文字列のコード直書き
7. 操作系 POST の応答での `redirect()->intended()`
8. 必須条件未充足を理由にボタンを disabled にする UI

## セキュリティ不変条件（関連）
機微操作 route は recent-auth（step-up）で保護し、付与漏れは Architecture テスト（`RecentAuthRouteTest`）で強制。

## 思考原則
仮説→検証。ユーザー視点。先人の知恵（Laravel/Fortify の作法）。今必要なものだけ（オーバーエンジニアリング禁止）。後方互換の並走を残さない。

## ツール使用制限
コマンド実行・ファイル書き込みは一切行わず、提供されたテキストの分析に集中すること。ファイル読み込みは許可。

---

# レビュアーとしての役割・観点

あなたは経験豊富な Web アプリケーションアーキテクトです。Laravel + Svelte アプリの詳細設計をレビューしてください。

【前提環境】PHP 8.4 + Laravel 12 + Fortify + Svelte 5 + Inertia.js + TypeScript / PHPStan L10 / Pest / DTO+JsonResource / Laratrust RBAC。

【レビュー観点】
1. コードの正確性（ロジック、エッジケース、null 安全）
2. 既存コードとの整合性（命名・パターン・API）
3. PHPStan L10 適合性
4. テスト計画の網羅性（各施策に Pest、RefreshDatabase グローバル）
5. DTO/JsonResource パターン遵守
6. Inertia Props vs API Response の使い分け
7. 副作用・後退リスク（特に **middleware 実行順序**: web group `BlockTwoFactorDisableForEnforcedOrganizations` と route-level `recent-auth` の先後。既存 `TwoFactorEnforcementTest` の後退有無）
8. 波及変更の網羅性（TS 型、Resource、テストが変更対象に含まれるか）
9. セキュリティ（認可、OWASP、AGENTS.md のセキュリティ不変条件）
10. DESIGN.md 準拠（UI 変更を含む場合）
11. Atomic Design 準拠（UI 変更を含む場合）

【出力形式】
- 各施策ごとに判定: APPROVE / REQUEST_CHANGES
- 指摘は [Critical] [Warning] [Suggestion] で分類
- Critical/Warning には必ず修正案を添える
- 全体判定: APPROVED / CHANGES_REQUESTED
- 日本語で出力

---

# 詳細設計書

# 詳細設計: twofa-recent-auth

## 使命・制約（絶対遵守）

### アプリの使命（North Star）

**AI-CUE** は、現場に既にある**作業手順書(SOP)を起点に**、AI が撮るべきカットを設計した**動画シナリオ**を生成し、そのシナリオを**スマホ(PWA)でナビゲーション撮影**することで、専門知識ゼロの現場作業者でも**標準化されたマニュアル動画**を作れるようにする。

- 「思考ゼロ・編集ゼロ」— 台本作成・撮影判断・編集の 3 ハードルを AI とナビ撮影で肩代わりする。
- 競合(OJT を撮って形式化する tebiki)と異なり、**標準作業を起点に AI が教材設計し撮影を指示する**。
- 熟練者の暗黙知を動画マニュアルという形式知へ変換する装置（SECI）。

### 禁止事項（AGENTS.md より）

1. テストなしの実装完了報告（不変条件は対応する Architecture/Feature テストへの登録まで含めて「実装済み」）
2. PHPStan エラーの widen（型を緩めて黙らせる）・baseline 化
3. dev DB への破壊操作をエージェント判断で実行
4. `response()->json()` の直書き（DTO / JsonResource / Inertia を使う。仕様固定 endpoint のみ例外）
5. LLM 呼び出しの Prism 直呼び
6. prompt 文字列のコード直書き
7. 操作系 POST の応答での `redirect()->intended()`
8. 必須条件未充足を理由にボタンを disabled にする UI

### セキュリティ不変条件（関連分）

- 機微操作 route は recent-auth（step-up 再認証）で保護し、付与漏れは Architecture テスト（`RecentAuthRouteTest`）で強制する。

### コーディングルール

- **PHPStan level 10** 必須（`composer phpstan`）
- **Pest**（`composer test`、`--parallel`）
- **RefreshDatabase** グローバル適用（`tests/Pest.php`、個別 `DatabaseTransactions` 禁止）
- テストデータは **Factory** 生成（`User::factory()->withTwoFactor()`）
- DTO + JsonResource パターン（本設計は既存 `RecentAuthRequiredDto` / `RecentAuthRequiredResource` を再利用、新規なし）
- アーリーリターン推奨 / `composer fix`（Pint）/ `pnpm lint:fix`
- PHP 8.4 + Laravel 12 + Fortify + Svelte 5 + Inertia.js + TypeScript

## 概念設計リファレンス

- [conceptual-design.md](./conceptual-design.md)（APPROVED, conceptual-review Round 3）

## 施策一覧

| # | 施策名 | 変更ファイル | 優先度 |
|---|--------|------------|--------|
| S1 | `two-factor.disable` へ recent-auth を後付け配線 | `app/Providers/FortifyServiceProvider.php` | High |
| S2 | Architecture allowlist に `two-factor.disable` を登録 | `tests/Architecture/RecentAuthRouteTest.php` | High |
| S3 | disable step-up の Feature テスト（新規）＋既存 enforcement テストの fresh セッション付与 | `tests/Feature/Auth/TwoFactorDisableStepUpTest.php`（新規）, `tests/Feature/Organizations/TwoFactorEnforcementTest.php`（L315-324 更新） | High |
| S4 | フロント disable 前段 precheck（`guardWithRecentAuth` でラップ） | `resources/js/pages/Settings/Security.svelte` | Medium |
| S5 | config TODO コメントの追従（disable を「対応済み」へ） | `config/fortify.php` | Low |

> 本設計は**新規ルート・DTO・Resource・モデルを一切追加しない**。既存の recent-auth 機構（middleware・DTO・Resource・フロント helper）へ `two-factor.disable` を 1 経路追加するだけ。

---

## S1: `two-factor.disable` へ recent-auth を後付け配線

### 変更箇所
- ファイル: `app/Providers/FortifyServiceProvider.php`（`RECENT_AUTH_ROUTE_NAMES` 定数, L48-51）

### 波及変更
- TypeScript型定義: なし
- API Resource/DTO: なし（middleware が既存 `RecentAuthRequiredResource` を返す）
- テストファイル: S2（Architecture allowlist）と S3（Feature）で担保

### 現行コード
```php
/**
 * recent-auth (step-up) を後付け配線する Fortify 登録ルート。
 * リカバリコードは TOTP を伴わないログイン成立手段 = 第二要素の bypass 経路そのものなので、
 * 表示 (GET) / 再生成 (POST) の双方を機微操作として扱う
 * (姉妹操作: organizations.members.two-factor.reset / settings.account.destroy 等と同基準)。
 * 付与漏れは RecentAuthRouteTest (Architecture) が CI で検出する。
 *
 * @var list<string>
 */
private const RECENT_AUTH_ROUTE_NAMES = [
    'two-factor.recovery-codes',
    'two-factor.regenerate-recovery-codes',
];
```

### 変更後コード
```php
/**
 * recent-auth (step-up) を後付け配線する Fortify 登録ルート。
 * いずれも「確立済み第二要素の bypass / 除去」経路であり、通常セッション認証だけで
 * 到達させない (姉妹操作: organizations.members.two-factor.reset /
 * settings.account.destroy 等と同基準)。
 * - recovery-codes 表示 (GET) / 再生成 (POST): TOTP を伴わないログイン成立手段の露出・更新。
 * - disable (DELETE): 第二要素そのものの無効化 (bug-hunt F-H3)。
 *   ※ 2FA 必須組織の準拠ユーザーは BlockTwoFactorDisableForEnforcedOrganizations
 *     (web group、recent-auth より先行) が 422 で拒否するため、本配線が実効するのは
 *     self-disable が許可される非 enforced 組織のユーザー。
 * 付与漏れは RecentAuthRouteTest (Architecture) が CI で検出する。
 *
 * @var list<string>
 */
private const RECENT_AUTH_ROUTE_NAMES = [
    'two-factor.recovery-codes',
    'two-factor.regenerate-recovery-codes',
    'two-factor.disable',
];
```

### PHPStan適合チェック
- [x] 戻り値の型が明示されている（定数のため対象外。`attachRecentAuthToSensitiveRoutes` は既存、`void`）
- [x] null安全（`$routes->getByName($name)` の null は既存 `if ($route !== null && ...)` guard 済）
- [x] DTOを返している（該当なし。middleware が Resource を返す）
- [x] Genericsの型パラメータが正しい（`@var list<string>` 維持）

### テスト計画
- S2（Architecture）と S3（Feature）で担保。

### リスク
- **ミドルウェア順序**: `recent-auth` は route-level、`BlockTwoFactorDisableForEnforcedOrganizations` は web group `append`（global）。Laravel のパイプラインは group/global → route の順で、enforced org の 422 が recent-auth より先に走る。したがって enforced org 準拠ユーザーの体験（422）は不変。→ S3 のテストで順序を固定。
- **既存テスト後退**: `TwoFactorEnforcementTest`（enforced org の self-disable 422）が recent-auth 追加で壊れないか。enforced org のテストユーザーは 422 が先行するため recent-auth の stale 409 には落ちない。ただし**非 enforced org のユーザーで disable を叩く既存テスト**があれば、recent-auth stale で 409/302 に変わり得る → 影響調査を S3 の前提作業に含める（下記「既存テスト影響調査」）。

---

## S2: Architecture allowlist に `two-factor.disable` を登録

### 変更箇所
- ファイル: `tests/Architecture/RecentAuthRouteTest.php`（`recentAuthRequiredRouteNames()`, L17-38）

### 波及変更
- TypeScript型定義: なし / API Resource/DTO: なし / テストファイル: 本体

### 変更後コード（追加行）
```php
function recentAuthRequiredRouteNames(): array
{
    return [
        // ... 既存 ...
        // リカバリコード表示 / 再生成 (第二要素の bypass 経路。Fortify 登録ルートへ
        // FortifyServiceProvider::attachRecentAuthToSensitiveRoutes() が後付け配線)
        'two-factor.recovery-codes',
        'two-factor.regenerate-recovery-codes',
        // 2FA 無効化 (第二要素そのものの除去。bug-hunt F-H3。同じく後付け配線)
        'two-factor.disable',
    ];
}
```

### PHPStan適合チェック
- [x] 戻り値 `@return list<string>` を維持（要素追加のみ）

### テスト計画
- このテスト自体が「`two-factor.disable` に recent-auth が付与されている」を検証する。S1 未実施なら fail する（テストファースト: 先に S2 を追加して赤を確認 → S1 で緑）。

### リスク
- なし（allowlist に 1 要素追加）。

---

## S3: disable step-up の Feature テスト（新規）

### 変更箇所
- ファイル: `tests/Feature/Auth/TwoFactorDisableStepUpTest.php`（新規）

### 既存テスト影響調査（実施済み・確定）

`grep -rn "two-factor-authentication" tests/` の結果、disable を叩く既存テストは `tests/Feature/Organizations/TwoFactorEnforcementTest.php` の 3 件のみ:

| 行 | 内容 | recent-auth 追加後の判定 | 対応 |
|----|------|------------------------|------|
| L288-300 | enforced org 準拠メンバーの `deleteJson` → **422** (`two_factor_disable_forbidden`) | **変化なし（緑のまま）**。`BlockTwoFactorDisableForEnforcedOrganizations`（web group）が recent-auth（route-level）より先に 422 を返す。recent-auth に到達しない。 | 変更不要 |
| L302-313 | enforced org 非 XHR DELETE → settings.security へ redirect + flash error | **変化なし（緑のまま）**。同上、422 相当の block が先行。 | 変更不要 |
| **L315-324** | **非 enforced org** 準拠ユーザーの非 XHR DELETE → redirect + secret 消去を期待 | **赤化する**。block は no-op、recent-auth が stale を検知し 302 で `recent-auth.confirm` へ。`->assertRedirect()` は通るが `two_factor_secret->toBeNull()` が**失敗**（無効化されない）。 | **fresh セッション付与で修正**（下記） |

L315-324 の修正（意図＝「非 enforced org は self-disable できる」を維持したまま recent-auth を満たす。**削除・意図変更はせず fresh セッション付与のみ**）:

```php
test('非必須組織のみ所属の準拠ユーザーは self-disable できる (secret 消去)', function (): void {
    [$organization] = tfeCreateOrganization(twoFactorRequired: false);
    $member = tfeAddMember($organization, 'enabled');

    $this->actingAs($member)
        ->withSession(['recent_auth_at' => time()]) // recent-auth を満たす (step-up 済み相当)
        ->delete('/user/two-factor-authentication')
        ->assertRedirect();

    expect($member->fresh()->two_factor_secret)->toBeNull();
});
```

- 参照: `TwoFactorRecoveryCodesStepUpTest` が同種配線・同じ `withSession(['recent_auth_at' => time()])` パターンで既に成立している。

### テスト設計（`TwoFactorRecoveryCodesStepUpTest` と同形式・Pest）
```php
<?php

declare(strict_types=1);

use App\Models\User;

/*
 * 2FA 無効化 (DELETE /user/two-factor-authentication, route two-factor.disable) の
 * recent-auth (step-up) 配線。FortifyServiceProvider::attachRecentAuthToSensitiveRoutes()
 * が booted callback で recent-auth middleware を後付けする。ここではその実効性を HTTP 経由で
 * 検証する。allowlist の付与漏れ検出は RecentAuthRouteTest (Architecture) 側。
 */

test('鮮度なしの DELETE 無効化 (XHR) は 409 recent_auth_required で 2FA を無効化しない', function (): void {
    $user = User::factory()->withTwoFactor()->create();

    $this->actingAs($user)
        ->deleteJson('/user/two-factor-authentication')
        ->assertStatus(409)
        ->assertJson([
            'code' => 'recent_auth_required',
            'redirect' => route('recent-auth.confirm'),
        ]);

    $user->refresh();
    expect($user->two_factor_secret)->not->toBeNull();
    expect($user->two_factor_confirmed_at)->not->toBeNull();
});

test('鮮度なしの Inertia DELETE 無効化は 409 で 2FA を無効化しない', function (): void {
    $user = User::factory()->withTwoFactor()->create();

    $this->actingAs($user)
        ->delete('/user/two-factor-authentication', [], ['X-Inertia' => 'true'])
        ->assertStatus(409)
        ->assertJsonPath('code', 'recent_auth_required');

    $user->refresh();
    expect($user->two_factor_secret)->not->toBeNull();
});

test('鮮度なしの通常 (非 XHR/非 Inertia) DELETE 無効化は recent-auth confirm へ 302 する', function (): void {
    $user = User::factory()->withTwoFactor()->create();

    $this->actingAs($user)
        ->delete('/user/two-factor-authentication')
        ->assertRedirect(route('recent-auth.confirm'));

    $user->refresh();
    expect($user->two_factor_secret)->not->toBeNull();
});

test('fresh なら DELETE が 2FA を無効化する', function (): void {
    $user = User::factory()->withTwoFactor()->create();

    $this->actingAs($user)
        ->withSession(['recent_auth_at' => time()])
        ->deleteJson('/user/two-factor-authentication')
        ->assertOk();

    $user->refresh();
    expect($user->two_factor_secret)->toBeNull();
    expect($user->two_factor_confirmed_at)->toBeNull();
});
```

### enforced-org 順序の担保
- enforced org（`BlockTwoFactorDisableForEnforcedOrganizations` が 422 先行）の挙動は既存 `TwoFactorEnforcementTest`（「準拠 (enabled) ユーザーの self-disable は 422 で拒否」）が既に固定している。本設計はその route に recent-auth を足すが、web group middleware が route middleware より先行するため 422 が優先される。→ 既存テストが緑のままであることが「順序不変」の回帰ガードになる（新規テスト追加は不要。既存テストが担保）。
- 万一 `TwoFactorEnforcementTest` の 422 テストが recent-auth 追加後に赤化した場合は、`recent_auth_at` の fresh 付与では**なく**middleware 順序の問題であり、S1 の配線ではなく順序設計を見直すシグナルとする（想定外）。

### PHPStan適合チェック
- [x] Factory 経由（`User::factory()->withTwoFactor()`）でデータ生成、`Model::create()` 手組みなし
- [x] 個別 `DatabaseTransactions` を使用しない（`RefreshDatabase` グローバル適用）
- [x] 409 の shape（`code` / `redirect`）を固定（Resource 契約の退行検知）

### テスト計画
- [x] バグ修正の再現テスト: 「鮮度なしの DELETE が 2FA を無効化してしまう」現行バグを、上記 stale 系テストが赤で再現（S1 未実施時は 200 で無効化されるため fail）→ S1 で緑。
- [x] fresh 通過テストで正当フローの非後退を保証。
- [x] `factories.md` の `withTwoFactor` state 前提を確認（既存 `TwoFactorRecoveryCodesStepUpTest` が使用済み = 利用可能）。

### リスク
- `deleteJson` の DELETE は Fortify `TwoFactorAuthenticationController@destroy`。fresh 時の応答は `TwoFactorDisabledResponse`（XHR は 200）。`assertOk()` で足りる。
- 非 XHR プレーン DELETE（3 つ目のテスト）は Inertia ヘッダなし・`expectsJson()` false のため 302 分岐に入る（RequireRecentAuth の設計どおり）。

---

## S4: フロント disable 前段 precheck

### 変更箇所
- ファイル: `resources/js/pages/Settings/Security.svelte`（`disableTwoFactor()`, L215-230）

### 波及変更
- TypeScript型定義: なし（`guardWithRecentAuth` / `RecentAuthStatus` は import 済み）
- API Resource/DTO: なし
- テストファイル: 既存の JS component テストがあれば分岐追加を確認（下記）。無ければ server middleware が最終ゲートのため必須ではないが、`data-testid` は維持。

### 現行コード
```svelte
function disableTwoFactor(): void {
    router.delete("/user/two-factor-authentication", {
        preserveScroll: true,
        onStart: () => {
            disabling = true;
        },
        onSuccess: () => {
            disableDialogOpen = false;
            confirming = false;
            qrSvg = null;
            recoveryCodes = [];
        },
        onFinish: () => {
            disabling = false;
        },
    });
}
```

### 変更後コード（regenerate と同型に `guardWithRecentAuth` でラップ）
```svelte
function disableTwoFactor(): void {
    // recent-auth 必須 (サーバが最終ゲート)。stale なら再認証モーダル→resume で再実行。
    // regenerateRecoveryCodes と同一の resume 契約。
    guardWithRecentAuth(() => {
        router.delete("/user/two-factor-authentication", {
            preserveScroll: true,
            onStart: () => {
                disabling = true;
            },
            onSuccess: () => {
                disableDialogOpen = false;
                confirming = false;
                qrSvg = null;
                recoveryCodes = [];
            },
            onFinish: () => {
                disabling = false;
            },
        });
    });
}
```

- 既存の再認証モーダル（`recentAuthOpen` / `RecentAuthDialog`、L413-）と `resumePendingAction()` は regenerate 用に既に配線済みで、disable でもそのまま機能する（modal の onConfirm 成功 → `resumePendingAction()` → 退避した closure 再実行）。追加の state / component は不要。
- `disableDialogOpen`（無効化確認ダイアログ）との関係: ユーザーが確認ダイアログで「無効化」を押す → `disableTwoFactor()` → `guardWithRecentAuth` が stale を検出したら再認証 modal を重ねて開く。fresh なら即 `router.delete`。確認ダイアログは `onSuccess` で閉じる。

### PHPStan適合チェック
- N/A（フロント）。`pnpm typecheck` / `pnpm lint` を通す。

### テスト計画
- [x] `pnpm typecheck` / `pnpm lint` green
- [ ] （任意）Security.svelte の component テストが存在すれば、stale 時に `router.delete` が即発火せず modal が開く分岐を追加。存在しなければ server-side S3 が最終担保のため新規 JS テストは必須としない（オーバーエンジニアリング回避）。
- [x] 手動確認観点: fresh（ログイン直後）で無効化が 1 クリック完結、stale で再認証 modal 経由になること。

### リスク
- disable 確認ダイアログと再認証モーダルの二段重ね表示。regenerate も同構造（`regenerateDialogOpen` + `recentAuthOpen`）で既に成立しており、同じ z-index / focus 挙動を踏襲するため新規リスクは低い。

---

## S5: config TODO コメントの追従

### 変更箇所
- ファイル: `config/fortify.php`（`twoFactorAuthentication` の TODO(template) コメント, L159-166）

### 現行コメント（要旨）
```
// TODO(template): 残る 2FA 管理エンドポイント (enable/confirm/disable/qr-code/secret-key)
// は step-up なしで到達可能。enable/confirm は enrollment 動線 ... と衝突しない設計を
// 決めてから同方式で固めること ...
```

### 変更後（disable を「対応済み」に落とし、残りを列挙）
```
// recovery-codes (GET/POST) と disable (DELETE) は
// FortifyServiceProvider::attachRecentAuthToSensitiveRoutes() で recent-auth を
// 後付け配線済み (RecentAuthRouteTest が CI 固定)。
// TODO(template): 残る 2FA 管理エンドポイント (enable/confirm/qr-code/secret-key) は
// step-up なしで到達可能。enable/confirm は enrollment 動線 (2FA 強制組織の
// オンボーディング) と衝突しない設計を決めてから同方式で固めること
// (参照: aigenba RequireRecentAuthOnFortifyRoutes / spirux attachFortifyRouteMiddleware)。
```

### 波及変更
- なし（コメントのみ）。

### PHPStan適合チェック
- N/A（コメント）。

### テスト計画
- N/A。ドリフト防止は S2 の Architecture テストが担う。

### リスク
- なし。

---

## 実装順序（テストファースト）

1. **S2**（allowlist に `two-factor.disable` 追加）→ `composer test -- --filter RecentAuthRouteTest` が**赤**（未配線）を確認。
2. **S3**（Feature テスト新規）→ stale 系が**赤**（現行は無効化成功）を確認。加えて `TwoFactorEnforcementTest.php` L315-324（非 enforced org self-disable）に fresh セッション（`withSession(['recent_auth_at' => time()])`）を付与（enforced org の 2 件は 422 先行のため変更不要）。
3. **S1**（配線）→ S2 / S3 が**緑**に。
4. **S4**（フロント precheck）→ `pnpm typecheck` / `pnpm lint` green。
5. **S5**（config コメント追従）。
6. 全体検証: `composer test` / `composer phpstan` / `vendor/bin/pint --test` / `pnpm lint` / `pnpm typecheck` / `pnpm build`。

## 実装モード

| 項目 | 内容 |
|------|------|
| 推奨モード | **incremental** |
| 判断根拠 | 既存の recent-auth 機構への 1 経路追加。新規ファイルは Feature テスト 1 本のみで、他は既存ファイルの局所編集（定数 1 要素・allowlist 1 行・フロント 1 関数・コメント）。独立性が高く小さい。 |
| 競合リスク | 低。`FortifyServiceProvider` / `RecentAuthRouteTest` / `Security.svelte` / `config/fortify.php` を触る他タスクと同時進行する場合のみ軽微な merge 競合の可能性。いずれも局所差分。 |

## 使命・禁止事項チェック

- [x] 使命寄与: 非 enforced org のセッション侵害耐性を上げ、認証境界と現場マニュアル資産を守る。
- [x] 禁止事項: `response()->json()` 直書きなし（既存 Resource 再利用）。テストなし完了なし（Architecture + Feature を施策に含む）。既存テスト削除・上書きなし（fresh セッション付与のみ）。PHPStan widen / baseline なし。
- [x] DTO/JsonResource: 新規なし、既存 `RecentAuthRequiredDto` / `RecentAuthRequiredResource` 再利用。
- [x] Factory: `User::factory()->withTwoFactor()` 使用、手組みなし。
- [x] RefreshDatabase グローバル適用、個別 `DatabaseTransactions` なし。


---

# 関連する現行コード（抜粋）

## app/Providers/FortifyServiceProvider.php（該当部）
```php
private const RECENT_AUTH_ROUTE_NAMES = [
    'two-factor.recovery-codes',
    'two-factor.regenerate-recovery-codes',
];

private function attachRecentAuthToSensitiveRoutes(): void
{
    $this->app->booted(static function (Application $app): void {
        $routes = $app->make(Router::class)->getRoutes();
        $routes->refreshNameLookups();

        foreach (self::RECENT_AUTH_ROUTE_NAMES as $name) {
            $route = $routes->getByName($name);
            if ($route !== null && ! in_array('recent-auth', $route->middleware(), true)) {
                $route->middleware('recent-auth');
            }
        }
    });
}
```

## app/Http/Middleware/RequireRecentAuth.php（handle 抜粋）
```php
public function handle(Request $request, Closure $next): Response
{
    $session = $request->session();
    if (RecentAuthWindow::isFresh($session->get('recent_auth_at'))) {
        return $next($request); // (LogicException guard 省略)
    }
    $confirmUrl = route('recent-auth.confirm');
    if ($request->expectsJson() || $this->isInertiaMutation($request)) {
        return RecentAuthRequiredResource::make(new RecentAuthRequiredDto(
            message: 'この操作には直近の再認証が必要です。',
            redirect: $confirmUrl,
        ))->response()->setStatusCode(409)->withHeaders(['Cache-Control' => 'no-store']);
    }
    // GET は fullUrl、非 GET は same-origin referer or dashboard を intended に保存し 302
    return redirect()->route('recent-auth.confirm');
}
private function isInertiaMutation(Request $request): bool
{
    return $request->hasHeader('X-Inertia') && ! $request->isMethod('GET');
}
```
※ `RecentAuthRequiredResource` は `{ code: 'recent_auth_required', message, redirect }` 形の JSON を返す（既存 Feature テスト `TwoFactorRecoveryCodesStepUpTest` が `->assertJson(['code' => 'recent_auth_required', 'redirect' => route('recent-auth.confirm')])` を検証済み）。

## bootstrap/app.php（web group append 抜粋）
```php
$middleware->web(append: [
    HandleInertiaRequests::class,
    SecurityHeaders::class,
    // (1) 未準拠ユーザーの全画面ゲート → (2) 準拠ユーザーの self-disable 禁止
    RequireTwoFactorForEnforcedOrganizations::class,
    BlockTwoFactorDisableForEnforcedOrganizations::class,
]);
$middleware->alias([
    'recent-auth' => RequireRecentAuth::class,
    // ...
]);
```
※ `recent-auth` は `two-factor.disable` route へ FortifyServiceProvider が動的 append する route-level middleware。web group middleware（Block...）は route-level より先に実行される。

## tests/Feature/Organizations/TwoFactorEnforcementTest.php（disable を叩く既存 3 テスト）
```php
// (A) enforced org 準拠メンバー: deleteJson → 422 two_factor_disable_forbidden, secret 残存
$response = $this->actingAs($member)->deleteJson('/user/two-factor-authentication');
$response->assertStatus(422)->assertJsonPath('code', 'two_factor_disable_forbidden');

// (B) enforced org 非 XHR: settings.security へ redirect + flash error, secret 残存
$this->actingAs($member)->from(route('settings.security'))
    ->delete('/user/two-factor-authentication')
    ->assertRedirect(route('settings.security'))->assertSessionHas('error');

// (C) 非 enforced org 準拠ユーザー: 非 XHR delete → assertRedirect, secret が null になる
$this->actingAs($member)->delete('/user/two-factor-authentication')->assertRedirect();
expect($member->fresh()->two_factor_secret)->toBeNull();
```

## resources/js/pages/Settings/Security.svelte（既存の recent-auth guard 機構）
```svelte
function guardWithRecentAuth(action: () => void): void {
    void withRecentAuth({
        onFresh: action,
        onStale: (status) => { recentAuthStatus = status; pendingAction = action; recentAuthOpen = true; },
    });
}
function resumePendingAction(): void { const a = pendingAction; pendingAction = null; a?.(); }
// regenerateRecoveryCodes は既に guardWithRecentAuth でラップ済み。
// disableTwoFactor は現状 router.delete を直呼び（ラップなし）。
```
