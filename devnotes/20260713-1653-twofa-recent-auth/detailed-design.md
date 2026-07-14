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
| S3 | disable step-up の Feature テスト（新規）＋既存 enforcement テストの fresh セッション付与＋共有ヘルパ | `tests/Feature/Auth/TwoFactorDisableStepUpTest.php`（新規）, `tests/Feature/Organizations/TwoFactorEnforcementTest.php`（L315-324 更新）, `tests/Pest.php`（`freshRecentAuthSession()` 追加） | High |
| S4 | フロント disable 前段 precheck＋キャンセル時 pending 破棄＋component テスト | `resources/js/pages/Settings/Security.svelte`, `tests/js/pages/SettingsSecurity.test.ts`（disable フロー追加・`routerDeleteMock` hoist） | Medium |
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
        ->withSession(freshRecentAuthSession()) // recent-auth を満たす (step-up 済み相当)
        ->delete('/user/two-factor-authentication')
        ->assertRedirect();

    expect($member->fresh()->two_factor_secret)->toBeNull();
});
```

- 参照: `TwoFactorRecoveryCodesStepUpTest` が同種配線・同じ `withSession(['recent_auth_at' => time()])` パターンで既に成立している。

### fresh セッション値のヘルパ集約（テスト安定化）

recent-auth の窓は `config('auth.recent_auth_timeout')`（既定 900 秒）。`now()->timestamp` を入れた瞬間の elapsed は 0〜1 秒で窓の 0.1% 未満のため、時刻境界での不安定は実運用上起きない（既存 `TwoFactorRecoveryCodesStepUpTest` が同一パターンで安定稼働）。ただし「確実に fresh」を意図が読める形にするため、fresh 値を **`tests/Pest.php` に一度だけ**グローバルヘルパとして定義し（既存の `createOrganizationWithOwner` / `attachOrganizationMember` 等と同じ場所）、全テストから参照する。テストファイル内に関数宣言するとロード順依存 / 再宣言衝突になるため、配置先は `tests/Pest.php` に確定する:

```php
// tests/Pest.php （既存のグローバルヘルパ群に追記）
/** recent-auth を確実に満たす fresh session (窓 900s に対し elapsed≈0)。 */
function freshRecentAuthSession(): array
{
    return ['recent_auth_at' => now()->timestamp];
}
```

以降のテストは `->withSession(freshRecentAuthSession())` で統一注入する（S3 の新規テストと、S3 で更新する `TwoFactorEnforcementTest.php` L315-324 の双方）。

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
        ->withSession(freshRecentAuthSession())
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
- ファイル: `resources/js/pages/Settings/Security.svelte`（`disableTwoFactor()`, L215-230）＋ `pendingAction` 破棄 `$effect`
- ファイル: `tests/js/pages/SettingsSecurity.test.ts`（disable フローの component テスト追加・`routerDeleteMock` hoist）

### 波及変更
- TypeScript型定義: なし（`withRecentAuth` / `RecentAuthStatus` は import 済み）
- API Resource/DTO: なし
- テストファイル: `tests/js/pages/SettingsSecurity.test.ts` に disable フロー（fresh 発火 / stale 遮断 / キャンセル破棄 / resume 発火）を追加（必須。下記テスト計画）。

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

二重モーダル（無効化確認ダイアログ + recent-auth ダイアログ）の focus trap 競合を避けるため、**stale 検知時は先に確認ダイアログを閉じてから** recent-auth ダイアログを開く。`guardWithRecentAuth` の `onStale` に相当する分岐で確認ダイアログを畳むため、`guardWithRecentAuth` を直接使わず `withRecentAuth` の `onStale` に確認ダイアログの close を挟むローカル分岐にする（既存 `guardWithRecentAuth` は共有のまま非破壊）:

```svelte
function disableTwoFactor(): void {
    // recent-auth 必須 (サーバが最終ゲート)。regenerateRecoveryCodes と同一の resume 契約。
    const action = () => {
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
    };

    void withRecentAuth({
        onFresh: action,
        onStale: (status) => {
            // 二重モーダル回避: 確認ダイアログを閉じてから再認証ダイアログを開く。
            disableDialogOpen = false;
            recentAuthStatus = status;
            pendingAction = action;
            recentAuthOpen = true;
        },
        // delegated (status 取得失敗) は onFresh フォールバック = server middleware が最終ゲート。
    });
}
```

- 再認証ダイアログ（`recentAuthOpen` / `RecentAuthModal`、L412-）と `resumePendingAction()` は regenerate 用に既に配線済みで、disable でもそのまま機能する（modal の `onConfirmed` 成功 → `resumePendingAction()` → 退避した `action` closure 再実行 → disable 実行 → `onSuccess`）。追加の state / component は不要。

#### 再認証キャンセル時の pending 破棄（destructive closure を残さない）

`RecentAuthModal` は `open = $bindable` + `onConfirmed` のみを持ち、**キャンセル/close 用コールバックがない**。現状 `pendingAction` は `resumePendingAction()`（onConfirmed 経由）でのみ null 化されるため、ユーザーが再認証をキャンセルすると disable の destructive closure が `pendingAction` に残置する。次回別操作で `guardWithRecentAuth` が上書きするため即事故ではないが、defense-in-depth として再認証モーダルが閉じたら pending を破棄する `$effect` を追加する（disable/regenerate 共通の shared state に一括適用）:

```svelte
$effect(() => {
    // 再認証モーダルが閉じたら pending の destructive closure を破棄 (キャンセル時の残置防止)。
    // onConfirmed 経由の resume は action をローカルへ退避してから pendingAction を null 化するため
    // (resumePendingAction: `const a = pendingAction; pendingAction = null; a?.();`)、
    // 本 effect と二重で走っても resume が先に action を握っており安全。
    if (!recentAuthOpen) {
        pendingAction = null;
    }
});
```

- 順序安全性: `resumePendingAction()` は `action` をローカル変数へ退避してから `pendingAction = null` するため、本 `$effect`（モーダル close で null 化）と競合しても既に実行対象は確保済み。
- 本 `$effect` は初期マウント時（`recentAuthOpen === false`）にも走るが `pendingAction` は既に null で無害。open→true 時は発火分岐に入らない。
- **代替案**: 既存の共有 `guardWithRecentAuth(action)` をそのまま使い、`onStale` に「確認ダイアログを閉じる」責務を持たせる形へ小改修する手もあるが、regenerate は確認ダイアログ（`regenerateDialogOpen`）を stale 時に閉じない挙動で成立しているため、共有 helper の挙動を変えると regenerate の UX に波及する。よって disable 側はローカルに `withRecentAuth` を呼ぶ上記形を採り、helper は非破壊で共有する。
- fresh（ログイン直後）なら確認ダイアログはそのまま、`action` が即発火し `onSuccess` で閉じる。

### PHPStan適合チェック
- N/A（フロント）。`pnpm typecheck` / `pnpm lint` を通す。

### テスト計画（component テスト必須 — 新しいセキュリティ挙動のため）

新しい destructive-closure 破棄はセキュリティ挙動であり、AGENTS.md 禁止事項①「テストなしの実装完了報告」に該当しないよう **自動テストを必須**とする。既存 `tests/js/pages/SettingsSecurity.test.ts`（vitest + @testing-library/svelte、`@inertiajs/svelte` の `router` を mock 済み）に disable フローの describe を追加する。

**前提の小改修**: 既存テストは `router: { post: routerPostMock, delete: vi.fn() }` と delete を無名 mock にしているため、`routerPostMock` と同様に **`routerDeleteMock` へ hoist**して呼び出しを検証可能にする（`delete: routerDeleteMock`、`afterEach` で `routerDeleteMock.mockReset()`）。

追加する component テスト（regenerate 既存テストと同じ helper・mock を流用）:

1. **fresh → 1 回だけ delete 発火**: `stubFetchRoutes({ recent: true })`。disable 確認ダイアログを開いて「無効化」確定 → `routerDeleteMock` が `/user/two-factor-authentication` で **exactly once** 呼ばれる。
2. **stale → modal を開き delete しない**: `stubFetchRoutes({ recent: false })`。disable 確定 → `recent-auth-modal` が表示され、`routerDeleteMock` は **呼ばれない**。加えて確認ダイアログが閉じている（二重モーダル回避）ことを確認。
3. **stale → キャンセル → pending 破棄（自動再開しない）**: stale で recent-auth モーダルを開いた後、モーダルを閉じる（`open=false`）。その後 **別操作（例: 再生成）で recent-auth を成功**させても、`routerDeleteMock` は **一度も呼ばれない**（破棄された disable closure が resume されない）＝ `$effect` による pending 破棄の検証。
4. **stale → password 確認成功 → resume で 1 回だけ delete 発火**: stale で開いたモーダルに password を入力し `recent-auth-submit` → `resumePendingAction()` 経由で `routerDeleteMock` が **exactly once**。

- [x] `pnpm typecheck` / `pnpm lint` / `pnpm test`（vitest）green
- [x] 手動確認観点: fresh（ログイン直後）で無効化が 1 クリック完結、stale で再認証 modal 経由になること。
- [x] 手動確認観点（二重モーダル）: stale 時に無効化確認ダイアログが閉じてから recent-auth ダイアログが開き、focus trap が recent-auth ダイアログに移ること。

### リスク
- disable 確認ダイアログと再認証モーダルの二段重ね表示。stale 時に確認ダイアログを先に畳むため focus trap 競合を回避。regenerate も同構造（`regenerateDialogOpen` + `recentAuthOpen`）で既に成立している。
- 追加の `$effect`（pending 破棄）は shared state を触るが、regenerate の resume も同じ null 化契約に従うため後退なし（むしろ regenerate 側の潜在残置も同時に解消）。

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
