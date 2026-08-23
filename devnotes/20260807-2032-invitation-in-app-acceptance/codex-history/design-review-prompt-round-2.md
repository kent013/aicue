# Round 2: 指摘への対応と再レビュー依頼

Round 1 の [Critical] 2 件 / [Warning] 8 件 / [Suggestion] 2 件を捌きました。
うち [Critical] 1 件（施策 4 の NestedRouteDefenseInventory）は**実コードの根拠を添えて反論**しています。
反論が妥当かを最優先で検証してください（誤っていれば設計を直します）。

## 対応マトリクス

# 対応マトリクス: design-review Round 1

## 施策 4 [Critical] `NestedRouteDefenseInventory` への登録案が不整合 (nested route ではない)
- 判断: **反論する**（登録は必須であり、モードも正しい）
- 根拠（実コードを読んだ結果）:
  1. `tests/Support/Routing/NestedRouteDefenseInventory.php` の class docblock が
     「**母集団は 1 個以上の parameter を持つ named route**（旧実装は 2 個以上だった）」と明記し、
     `candidates()` も `if ($route->parameterNames() === []) { continue; }` だけで絞っている。
     つまり **nested かどうかは母集団の条件ではない**。2+param に絞ると単独 param の route が
     母集団から丸ごと外れて穴になった（audit-cycle-2 High-1）という経緯まで書かれている。
     したがって `invitations.accept-in-app`（1 param）は**登録しないと
     `NestedRouteIdorDefenseTest` が「分類漏れ」で fail する** = 登録は選択肢ではない。
  2. 先例が同形で実在する: `'notifications.open' => ['notification' => $manual]` /
     `'notifications.read' => ['notification' => $manual]`（個人スコープ・単一 param・
     controller が `$user->notifications()` から手動解決）。本件はこれと構造が同一である。
  3. `ManualOwnerScopedResolution` は「implicit binding を使わず controller が
     owner-scoped relation から手動解決する」という**解決方式の宣言**であって、
     モデル種別に紐づく分類ではない（`NestedRouteDefenseMode` の docblock）。
     したがって「対象モデルが違う」という前提は成立しない。
  4. さらに `TenantBoundaryOrderingTest` 検査 3a は
     `ManualOwnerScopedResolution` を宣言した param に対してのみ
     「action 引数が Model 型でないこと」「`RouteBindingTypes::MANUALLY_RESOLVED` に
     route identity ごと登録済みであること」「explicit binder が無いこと」を機械検証する。
     **inventory に入れないとこの 3 検査が一切走らない**（= 存在オラクル防御が無検証になる）。
     Codex の代案「nested inventory には入れず MANUALLY_RESOLVED と feature test で守る」は、
     この検査 3a を殺すため防御が弱くなる。
- 対応内容: 設計は変更しない。ただし**なぜ登録が必須なのか**（母集団が 1 param 以上であること、
  検査 3a が inventory 宣言を起点にすること、`notifications.open` が同形の先例であること）を
  詳細設計の施策 4 に明記し、次の実装者・レビュアーが同じ誤解をしないようにした。

## 施策 7 [Critical] `project_role` drop のローリングデプロイ安全境界が未定義
- 判断: **対応する**
- 根拠: 妥当な指摘。旧プロセスが `inviteMember` で
  `forceFill(['project_role' => ...])` を実行すると存在しない列への INSERT で 500 になる
  （read 側は属性が欠落するだけで null 相当に落ちるため 500 にはならない）。
  書き込み側が壊れる以上、順序の定義が要る。
- 対応内容: 施策 7 に「デプロイ手順（expand/contract の contract 側）」節を追加。
  **コードを先にデプロイし、旧プロセスが残っていないことを確認してから migration を流す**
  （列を書かなくなったコードが全プロセスに行き渡ってから drop する）。
  rollback では `down()` で列と check 制約は復元できるが**値は復元不能**であることを明記。
  値の消失影響は「pending 招待が参加後に管理画面でロール割当を要する状態になるだけで、
  参加自体は成功する」ことも書いた。

## 施策 1 [Warning] email 正規化契約との接続が弱い
- 判断: **対応する**（挙動は変えない。契約を明記する）
- 根拠（実コード確認）: `App\Support\EmailNormalizer` は inquiry / billing contact 専用で、
  **User の email は登録時に正規化していない**（`CreateNewUser` は validated 値をそのまま保存）。
  招待側 `inviteMember` も入力 email をそのまま保存する。
  したがって招待 email とログイン email の大小差は実際に起こりうる。
  ただしこれは**新しい非対称ではない** — 既存の `emailBelongsToMember` /
  `hasPendingInvitation` / `acceptInvitationIfValid` の email 一致判定も
  すべて同じ完全一致の意味論である。ここで正規化を入れると
  blind index の再計算（既存全レコード）と全 `whereBlind` 呼び出し元の同時変更が必要になり、
  本件のスコープを大きく外れる。
- 対応内容: 施策 1 のリスク欄と施策 9（`docs/architecture.md`）に、
  「User の email は正規化保存していない」「大小差はアプリ内受諾では 0 件 = 404 に倒れる
  （fail-secure）」「メール token 経路は token_hash 照合なので影響を受けず受諾できる」
  「正規化するなら blind index 再計算を伴う別作業」を明記した。

## 施策 3 [Warning] `retrieved` 回数依存のテストは壊れやすい
- 判断: **対応する**（回数依存をやめる）
- 根拠: 指摘のとおり `acceptPendingInvitation` は
  「下見 → ロック下再解決 → `joinOrganization` 内のロック取得」で取得回数が経路ごとに違い、
  回数で当てるのは実装変更に脆い。
- 対応内容: `retrieved` の**回数**ではなく **SQL の形**で当てる方式へ変更した。
  `DB::beforeExecuting()` で「`organization_invitations` を対象にした `for update` の SELECT」を
  検出し、その直前に**同一接続・同一トランザクション内で**
  `DB::table('organization_invitations')->whereKey($id)->update(['revoked_at' => now()])` を当てる
  （one-shot フラグで自分自身の UPDATE による再入を止める）。
  これで `joinOrganization` のロック下再検証が `isRevoked() === true` を見て `false` を返す状態を
  経路に依らず決定的に作れる。後始末は `try/finally` でフラグを落とす。
  テスト docblock に「目的は競合の完全再現ではなく `joinOrganization() === false` の
  消費契約の決定的検証である」と明記する方針は維持。

## 施策 3 [Warning] `joinOrganization()` の戻り値消費を守る静的 gate が無い
- 判断: **対応する**（ただし文字列一致ではなく**トークナイザ**で行う）
- 根拠: 同一セッションの概念設計レビュー Round 3 で
  「`if (! $this->joinOrganization(` の完全一致は正常な実装
  （`$joined = $this->joinOrganization(...); if (! $joined)`）で壊れ、
  コメント内の同一文字列で通る。少なくともコメント除外を含む構文検査へ合わせよ」という
  指摘を受けて behavioral 固定に倒した経緯がある。両方の指摘を満たすには
  **コメントを除外できる構文レベルの検査**が必要。
- 対応内容: `MembershipWriteLockInventoryTest` に検査を 1 本追加する。
  `token_get_all()` でサービスファイルをトークン化し、`joinOrganization` 呼び出しトークンの
  **直前の有意トークン**（コメント・空白を除く）が
  `T_RETURN` / `=` / `!` / `&&` / `||` / `?` のいずれかであることを要求する
  （文の先頭 = `;` `{` `}` の直後なら戻り値を捨てているので fail）。
  コメント内の文字列はトークン種別で除外されるため誤検出しない。
  behavioral な `InvitationAcceptRaceTest` は**併存**させる
  （静的検査は「消費している形か」、behavioral は「消費した結果の契約が正しいか」を見る）。

## 施策 4 [Warning] `abort_if` 後の PHPStan narrowing
- 判断: **対応する**
- 対応内容: 実装コード例を `if ($organization === null) { abort(404); }` に統一した
  （`abort()` は `never` を返すため narrowing が確実）。

## 施策 5 [Warning] `Button` の `loading` が `disabled` を出すなら禁止事項 8 に反する
- 判断: **部分的に対応する**（テスト計画の記述を是正。実装方針は既存流儀を維持）
- 根拠（実コード確認）: `resources/js/components/atoms/Button.svelte` の `<button>` は
  `disabled={disabled || loading}` を出す。ただし**禁止事項 8 は
  「必須条件未充足を理由に disabled にする UI」を禁じるもの**であり、
  in-flight（送信中）の二重送信防止は該当しない。実際、同じ画面の招待送信ボタンが
  `loading={inviteForm.processing}` を使っており、これが既存の sanctioned な流儀である。
  ここで独自の `aria-busy` だけの wrapper を新設すると、既存 atom と二重の作法を作ることになる
  （思考原則 1・3 に反する）。
- 対応内容: 実装は `loading={acceptingId === invitation.id}` のまま。
  誤っていたのは**私のテスト計画の文言**（「参加ボタンは disabled 属性を持たない」）なので、
  「**初期描画（非 in-flight）では disabled 属性を持たない**」
  「in-flight 中の disabled は二重送信防止であり必須条件未充足による無効化ではない」
  へ是正し、その旨をコンポーネント docblock にも書く方針にした。

## 施策 5 [Warning] `class="..."` のままでは DESIGN.md 準拠をレビューできない
- 判断: **対応する**
- 対応内容: `PendingInvitationList.svelte` / `PendingInvitationsNotice.svelte` の
  クラス指定を具体化し、「既存 `Card` / `Button` / `Badge` atom を使い、
  色・radius・typography は DS token 経由（`text-body` / `text-caption` / `text-h3` /
  `border-border` / `bg-surface` / `text-text-secondary` 等）のみ」
  「hex 直書き・独自 radius・カードの入れ子を増やさない」
  「アイコンは `@lucide/svelte` の `Mail` のみで SVG 直書きを新設しない」を明記した。

## 施策 6 [Suggestion] partial reload では `pendingCount` が更新されない旨を docs へ
- 判断: **対応する**
- 対応内容: 施策 9 の `docs/architecture.md` 追記項目に
  「共有 prop は closure のため `only:` 指定の partial reload では評価されず、
  件数はフルページ遷移時に更新される（受諾直後は dashboard へフル遷移するため実害はない）」を追加。

## 施策 7 [Warning] `php artisan migrate` が dev DB だと禁止事項 3 に抵触
- 判断: **対応する**
- 対応内容: テスト計画の該当項目を
  「**worktree のテスト DB / CI DB に限定**して実行する。dev DB への適用は通常のデプロイ手順、
  またはユーザーの明示承認による」へ限定した。

## 施策 8 [Warning] 抽出結果 / floor / cap の実測値が devnotes に固定されていない
- 判断: **対応する**
- 対応内容: 完了条件に
  「`devnotes/{dir}/gate-mutation-log.md` に **(a) 初回抽出結果の全リスト**、
  **(b) 確定した floor**、**(c) `RecipientScopedPendingSet` の exact-fit cap** を記録し、
  mutation M1〜M7 の赤化結果と併せてレビュー対象にする」を追記した。

## 施策 9 [Warning] 完了条件の検証コマンドが AGENTS.md の正本と不一致
- 判断: **対応する**
- 対応内容: 完了条件を AGENTS.md の `VERIFICATION_COMMANDS` と完全一致させた
  （`pnpm typecheck:packages` / `pnpm build:packages` / `pnpm test:packages` を追加）。

## 施策 2 [Suggestion] `roleLabel` のみで value を持たない
- 判断: **見送る**
- 根拠: 現時点で front は表示しかしない。value が必要になった時点で足せばよい
  （思考原則 2「今必要なものだけ作る」）。開示最小化としては妥当という評価とも一致する。

---

## 反論の根拠となる現行コード (追加抜粋)

### tests/Support/Routing/NestedRouteDefenseInventory.php (docblock + inventory の個人スコープ部 + candidates())

```php
<?php

declare(strict_types=1);

namespace Tests\Support\Routing;

use App\Enums\Security\NestedRouteDefenseMode;
use Illuminate\Routing\Route as RoutingRoute;
use Illuminate\Routing\Router;
use Illuminate\Support\Facades\Route;

/**
 * route parameter ごとの IDOR / 存在オラクル 防御方式の inventory (単一 source of truth)。
 *
 * 2 つの Architecture テストが同じ inventory を読むため、Pest のファイル読み込み順に
 * 依存する global 関数ではなく静的クラスに置く:
 *   - NestedRouteIdorDefenseTest    … 分類漏れ・stale・理由の突合 (deny-by-default)
 *   - TenantBoundaryOrderingTest    … モードごとの解決後 middleware 順序不変条件
 *
 * **母集団は 1 個以上の parameter を持つ named route** (旧実装は 2 個以上だった)。
 * 2+param に絞ると単独 param の route (`projects/{project}` / `user/passkeys/{passkey}` 等) が
 * 母集団から丸ごと外れ、テナント越境が分類対象にならない。audit-cycle-2 High-1 で
 * 実際に穴が残ったのはこの層である。
 *
 * **分類は route 単位ではなく parameter 単位**。同じ param 名でも route ごとに防御方式が
 * 違いうる (例: {user} は organizations.members.* が scopeBindings、
 * projects.members.destroy が手動解決)。route 単位の allowlist は param 1 つの分類漏れを
 * 丸ごと免除してしまうため使わない。
 */
final class NestedRouteDefenseInventory
{
    /**
     * route 名 => (parameter 名 => 防御方式)。
     *
     * @return array<string, array<string, NestedRouteDefenseMode>>
     */
    public static function inventory(): array
    {
        $scoped = NestedRouteDefenseMode::ScopeBindings;
        $binder = NestedRouteDefenseMode::ScopedBinder;
        $tenant = NestedRouteDefenseMode::TenantGuardMiddleware;
        $manual = NestedRouteDefenseMode::ManualOwnerScopedResolution;
        $nonRes = NestedRouteDefenseMode::NonResourceParameter;

        // {project} は web/API とも テナント guard middleware が binding 直後に走る (T108 S2)
        $project = ['project' => $tenant];

        return [
            // --- REST API v1 ---
            'api.v1.projects.show' => $project,
...
            'organizations.members.destroy' => ['organization' => $binder, 'user' => $scoped],
            'organizations.members.two-factor.reset' => ['organization' => $binder, 'user' => $scoped],

            // --- 個人スコープ ---
            // {notification} は NotificationController が $user->notifications() から手動解決する
            'notifications.open' => ['notification' => $manual],
            'notifications.read' => ['notification' => $manual],
            // {passkey} は SelfScopedPasskeyBinder が認証ユーザーの passkeys() スコープで解決する
            'passkey.destroy' => ['passkey' => $binder],

            // --- テナント親子でない param (理由は nonTenantReasons に必須登録) ---
            'social.callback' => ['provider' => $nonRes],
            'social.redirect' => ['provider' => $nonRes, 'intent' => $nonRes],
...
                || str_starts_with($name, 'cashier.'))) {
                continue;
            }

            $candidates[] = $route;
        }

        return $candidates;
    }

    /**
     * inventory に登録された名前を持つ現存 route (名前 => route)。
     *
     * @return array<string, RoutingRoute>
     */
    public static function registeredRoutes(): array
    {
        $inventory = self::inventory();
        $routes = [];
        foreach (Route::getRoutes() as $route) {
            $name = $route->getName();
            if ($name !== null && array_key_exists($name, $inventory)) {
                $routes[$name] = $route;
            }
        }

        return $routes;
    }

    /**
     * テナント防御モードを 1 つ以上宣言した route (名前 => route)。
     *
     * @return array<string, RoutingRoute>
     */
    public static function tenantDefenseRoutes(): array
    {
        $inventory = self::inventory();

        return array_filter(
            self::registeredRoutes(),
            static function (RoutingRoute $route) use ($inventory): bool {
                foreach ($inventory[(string) $route->getName()] as $mode) {
                    if ($mode->isTenantDefense()) {
                        return true;
                    }
```

### tests/Architecture/TenantBoundaryOrderingTest.php 検査 3a (全文)

```php
// --- 検査 3a: 手動解決 param は binding 段で解決されない ---

test('検査3a: ManualOwnerScopedResolution の param は binding 段で解決されない', function (): void {
    $inventory = NestedRouteDefenseInventory::inventory();
    /** @var Router $router */
    $router = app('router');
    $violations = [];
    $checked = 0;

    foreach (NestedRouteDefenseInventory::registeredRoutes() as $name => $route) {
        foreach ($inventory[$name] as $param => $mode) {
            if ($mode !== NestedRouteDefenseMode::ManualOwnerScopedResolution) {
                continue;
            }

            // 条件 1: controller action の対応引数の型が Eloquent Model 派生でないこと
            // (Model 型だと ImplicitRouteBinding が binding 段で解決してしまう)
            $signature = null;
            foreach ($route->signatureParameters() as $parameter) {
                if ($parameter->getName() === $param) {
                    $signature = $parameter;

                    break;
                }
            }
            $type = $signature?->getType();
            $typeName = $type instanceof ReflectionNamedType ? $type->getName() : null;
            if ($typeName !== null && is_a($typeName, Model::class, true)) {
                $violations[] = "{$name} の {{$param}}: action 引数が Model 型 ({$typeName}) = implicit binding が"
                    .'復活しており、不在 id だけが binding 段で 404 になる (存在オラクル)';
            }

            // 条件 2: RouteBindingTypes の手動解決 exclusion に route identity ごと登録済みであること
            $registered = RouteBindingTypes::MANUALLY_RESOLVED[$param]['routes'] ?? [];
            if (! in_array($name, $registered, true)) {
                $violations[] = "{$name} の {{$param}}: RouteBindingTypes::MANUALLY_RESOLVED に未登録";
            }

            // 条件 3: explicit binder (Route::bind / Route::model) が登録されていないこと
            if ($router->getBindingCallback($param) !== null) {
                $violations[] = "{$name} の {{$param}}: explicit binder が登録されている = binding 段で解決される";
            }

            $checked++;
        }
    }

    expect($violations)->toBe([], PHP_EOL.implode(PHP_EOL, $violations));
    expect($checked)->toBeGreaterThan(0);
});
```

### app/Enums/Security/NestedRouteDefenseMode.php (全文)

```php
<?php

declare(strict_types=1);

namespace App\Enums\Security;

/**
 * route parameter ごとの IDOR / 存在オラクル 防御方式。
 *
 * URL で受ける各 parameter が「その id は必ず URL 上の親 (またはテナント) に属する」不変条件を
 * どの機構で担保しているかを明示分類する。`NestedRouteIdorDefenseTest` の inventory が本 enum を
 * 値に持ち、**1 個以上**の route parameter を取る named route を deny-by-default で分類漏れ・
 * drift から守る。さらに `TenantBoundaryOrderingTest` が、モードごとに要求される
 * **解決後 middleware の順序不変条件**を機械検証する。
 *
 * テンプレートは `Route::scopeBindings()` を既定 (主防御) とする (親 relation 経由で子を解決し、
 * 不整合は認可より前に 404)。model binding にならない子 (payload 由来・文字列 token 等) や
 * 解決順序の都合で scopeBindings に乗らない route のみ他方式を使う。
 * アプリ固有の防御方式が必要になったら case を追加し、docs/template-divergence.md に記録する。
 *
 * **例外機構は設けない**。テナント防御が要る param を「対象外」と宣言して逃がすと
 * 存在オラクルがそのまま再発するため、非テナントモードの宣言には必ず理由の登録を要求する
 * (`NestedRouteIdorDefenseTest` の reason 突合)。
 */
enum NestedRouteDefenseMode: string
{
    // --- テナント防御モード (id が親テナントに属することを担保する) ---

    /** Route::scopeBindings() (親 relation 経由で子を解決、不整合は 404)。テンプレートの主防御。 */
    case ScopeBindings = 'scope_bindings';

    /** Route::bind() の explicit binder が actor スコープで解決する (不整合は binding 段で 404)。 */
    case ScopedBinder = 'scoped_binder';

    /** テナント guard middleware (project.in-route-org / api.project-in-org) が担う。 */
    case TenantGuardMiddleware = 'tenant_guard_middleware';

    /** implicit binding を使わず controller が owner-scoped relation から手動解決する。 */
    case ManualOwnerScopedResolution = 'manual_owner_scoped_resolution';

    /** route-model binding + inline 親子整合 guard (authorize より前に検査し不整合は 404)。 */
    case UrlIntegrityGuard = 'url_integrity_guard';

    // --- 非テナントモード (テナント防御の対象ではないことを明示宣言する) ---

    /**
     * テナント親子関係の対象にならない param。
     * 固定集合 (provider / intent)、署名付き URL の構成要素 (id / hash / token)、
     * 非モデル文字列 (path)、local 専用 debug 経路の対象指定などが該当する。
     */
    case NonResourceParameter = 'non_resource_parameter';

    /** テナントに属さない公開リソース。 */
    case PublicGlobalResource = 'public_global_resource';

    /** テナント防御モードか (順序不変条件の検査対象か)。 */
    public function isTenantDefense(): bool
    {
        return match ($this) {
            self::NonResourceParameter, self::PublicGlobalResource => false,
            default => true,
        };
    }
}
```

### resources/js/components/atoms/Button.svelte (loading / disabled 部分)

```svelte
<script lang="ts">
    import { Link } from "@inertiajs/svelte";
    import { LoaderCircle } from "@lucide/svelte";
    import type { ButtonProps } from "./Button.types";
    import {
        ICON_ONLY_SIZE_CLASSES,
        ICON_ONLY_VARIANTS,
        SIZE_CLASSES,
        VARIANT_CLASSES,
    } from "./Button.types";

    let {
        variant = "primary",
        size = "md",
        fullWidth = false,
        loading = false,
        iconOnly = false,
        ariaLabel,
        href,
        inertia = false,
        target,
        rel,
        type = "button",
        disabled = false,
        onclick,
        testId,
        ariaExpanded,
        ariaControls,
        element = $bindable<HTMLButtonElement | undefined>(undefined),
        class: extraClass = "",
        children,
    }: ButtonProps = $props();

    $effect(() => {
        if (import.meta.env.DEV && iconOnly && !ICON_ONLY_VARIANTS.has(variant)) {
            console.warn(
                `[Button] iconOnly は ${[...ICON_ONLY_VARIANTS].join(" / ")} のみ許可です (variant=${variant})`,
            );
        }
    });
...
        }
        onclick?.(event);
    }
</script>

{#snippet content()}
    {#if loading}
        <LoaderCircle class="size-4 animate-spin" aria-hidden="true" />
    {/if}
    {@render children?.()}
{/snippet}

{#if href !== undefined && inertia}
    <Link
        {href}
        class={computedClass}
        aria-label={ariaLabel}
        aria-disabled={loading || undefined}
        aria-busy={loading || undefined}
        tabindex={loading ? -1 : undefined}
        data-testid={testId}
        onclick={handleAnchorClick}
    >
        {@render content()}
    </Link>
{:else if href !== undefined}
    <a
        {href}
        {target}
        rel={computedRel}
        class={computedClass}
        aria-label={ariaLabel}
        aria-disabled={loading || undefined}
        aria-busy={loading || undefined}
        tabindex={loading ? -1 : undefined}
        data-testid={testId}
        onclick={handleAnchorClick}
    >
        {@render content()}
    </a>
{:else}
    <button
        {type}
        bind:this={element}
        class={computedClass}
        disabled={disabled || loading}
        aria-label={ariaLabel}
        aria-expanded={ariaExpanded}
        aria-controls={ariaControls}
        aria-busy={loading || undefined}
        data-testid={testId}
        {onclick}
    >
        {@render content()}
    </button>
{/if}
```

### resources/js/pages/Admin/Users.svelte (既存の loading 使用箇所)

```svelte
                                </Select>
                            {/snippet}
                        </FormField>
                        <div>
                            <Button
                                type="submit"
                                loading={inviteForm.processing}
                                testId="invite-submit"
                            >
                                招待を送信
                            </Button>
                        </div>
                    </form>
                </Card>

                <Card padding="lg">
```

### app/Actions/Fortify/CreateNewUser.php (email 保存部。正規化していないことの根拠)

```php

        $validated = Validator::make($input, [
            'name' => ['required', 'string', 'max:255'],
            'email' => [
                'required',
                'string',
                'email',
                'max:255',
                new UniqueEncryptedEmail(message: 'このメールアドレスではアカウントを作成できません。'),
                // 招待 token がある場合のみ、招待 email との一致を検証する (通常登録では素通り)
                new MatchesInvitationEmail($invitationToken),
            ],
            // 強度の SSOT は PasswordPolicy (Password::defaults で配線済)。
            // 確認入力 (confirmed) は使わない (表示トグル + リセット導線 + SSO で代替)
            'password' => ['required', 'string', Password::default()],
            'terms_accepted' => ['accepted'],
        ], [
            'terms_accepted.accepted' => '利用規約への同意が必要です。',
        ])->validate();

        // 料金表 → /register?plan= のプラン意図を pending に書き込む (常に書き換える規約)。
        // validate 通過後・tx 前に 1 回だけ呼ぶ (422 で止めた入力の意図は保持しない)。
        $this->intendedPlanResolver->rememberPendingFromForm($input);

        $name = $validated['name'];
        $email = $validated['email'];
        $password = $validated['password'];
        Assert::string($name);
        Assert::string($email);
        Assert::string($password);

        try {
            $user = DB::transaction(function () use ($name, $email, $password, $invitationToken): User {
                $user = (new User([
                    'name' => $name,
                    'email' => $email,
                    'password' => $password,
                ]))->forceFill([
                    'terms_accepted_at' => now(),
                    'consent_version' => config()->string('legal.consent_version'),
                ]);
                $user->save();

                // 招待 token 経由なら招待組織へ参加し、個人組織生成をスキップする。
                // 受諾不能 (失効/取消/不一致/既メンバー) なら null が返るので個人組織へ fallback。
                $joined = $invitationToken !== null
```

---

## 修正後の詳細設計書 (全文)

# 詳細設計: invitation-in-app-acceptance

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
5. LLM 呼び出しの Prism 直呼び(`app/Prompts/` の factory 経由のみ)
6. prompt 文字列のコード直書き(`resources/prompts/*.yaml` に置く)
7. 操作系 POST の応答での `redirect()->intended()`(ログイン直後フロー専用。招待送信等は `back()->with(...)` で完結させる)
8. 必須条件未充足を理由にボタンを disabled にする UI(押下時にエラー表示する。DESIGN.md)
9. Artifact の使用(成果物はリポジトリ内のファイルとして出力する)

### 思考原則

1. フレームワークのレンジ内でやる / 2. 今必要なものだけ作る / 3. 後方互換の並走を残さない /
4. 別物の概念を「似ているから」で統合しない / 5. テストファースト / 6. タコツボ実装を避ける

### コーディングルール

- **PHPStan level 10** 必須（`composer phpstan`）
- **Pest**（`composer test`）。**RefreshDatabase** は `tests/Pest.php` でグローバル適用済み、
  `--parallel` 実行。**個別 `DatabaseTransactions` 使用禁止**
- **テストデータは必ず Factory** で生成（`Model::create()` 手組み禁止）
- **DTO + JsonResource / Inertia** パターン。`declare(strict_types=1)` + 日本語コメント
- Controller は薄く（Service 委譲）、transaction は Service 内。保護キーは forceFill / relation で明示代入
- アーリーリターン推奨 / `composer fix`（Pint）/ `pnpm lint:fix`
- PHP 8.4 + Laravel 12 + Svelte 5 + Inertia.js + TypeScript
- component 階層は `atoms → molecules → organisms → features/{domain} → templates → pages` の単方向 import のみ

### 本件が特に従うセキュリティ不変条件

- **子は親に属する**: nested route の不整合は**認可より前に 404**（`NestedRouteIdorDefenseTest` の inventory に登録必須）
- **cross-org 不可**: 組織を跨ぐ read/write をしない（relation / org-scoped 解決経由のみ）
- **PII(email/name)は CipherSweet**。検索は `whereBlind()`
- **変更系 route は認可を通る**: `Gate::authorize` を通すか exemption inventory へ理由付きで登録（deny-by-default）
- **層 2 は binding の直後・FormRequest より前で閉じる**（`TenantBoundaryOrderingTest`）
- **流量制限**: `invitations.` 始まりの route は `ThrottleCoverageInventoryTest` の S3

## 概念設計リファレンス

- `devnotes/20260807-2032-invitation-in-app-acceptance/conceptual-design.md`（Codex Round 4 で APPROVED）
- 実査ブリーフ: `devnotes/20260807-2032-invitation-in-app-acceptance/recon-brief.md`
- lctl 台帳: `auth-invitation-in-app-discovery`（裁定 AG-113）/ `auth-invitation-flow`（裁定 AG-079）

## 施策一覧

| # | 施策名 | 変更ファイル | 優先度 |
|---|--------|------------|--------|
| 1 | 受信者視点の単一解決口 (`scopeActivePendingForEmail`) | `app/Models/OrganizationInvitation.php` | 高 |
| 2 | 受信者視点 DTO | `app/DataTransferObjects/Invitations/PendingInvitationForUserDto.php` (新規) | 高 |
| 3 | 受諾サービス + 共有コアの戻り値強化 | `app/Services/Organization/OrganizationMembershipService.php` | 高 |
| 4 | 受諾 route / Controller / named limiter / gate 6 本 | `routes/web.php` / `app/Http/Controllers/Organizations/AcceptInvitationInAppController.php` (新規) / `app/Providers/AppServiceProvider.php` / `app/Http/Routing/RouteBindingTypes.php` / `tests/Support/Routing/NestedRouteDefenseInventory.php` / `tests/Architecture/ControllerAuthorizationGateTest.php` / `tests/Architecture/MembershipWriteLockInventoryTest.php` / `tests/Architecture/RateLimiterKeyConventionTest.php` | 高 |
| 5 | 発見面 → 受諾の導線 | `app/Http/Controllers/NotificationController.php` / `resources/js/pages/Notifications/Index.svelte` / `resources/js/components/features/notifications/NotificationListItem.svelte` / `resources/js/components/features/invitations/PendingInvitationList.svelte` (新規) / `resources/js/types/invitation.ts` (新規) | 高 |
| 6 | 横断の気づき (共有 prop + notice) | `app/Http/Middleware/HandleInertiaRequests.php` / `resources/js/lib/shared-props.ts` / `resources/js/components/molecules/PendingInvitationsNotice.svelte` (新規) / `resources/js/components/templates/AppLayout.svelte` | 中 |
| 7 | 役割付き招待 (`project_role`) の撤去 | migration (新規) / `app/Models/OrganizationInvitation.php` / `app/Services/Organization/OrganizationMembershipService.php` / `app/Http/Requests/Organizations/StoreOrganizationInvitationRequest.php` / `app/DataTransferObjects/Admin/InvitationRowData.php` / `database/factories/OrganizationInvitationFactory.php` / `resources/js/pages/Admin/Users.svelte` / `resources/js/types/admin.ts` | 高 |
| 8 | 目録型 gate (受信者視点解決の deny-by-default) | `app/Enums/Security/InvitationResolutionScope.php` (新規) / `tests/Architecture/InvitationResolutionInventoryTest.php` (新規) | 高 |
| 9 | ドキュメント更新 | `docs/architecture.md` / `docs/template-divergence.md` / `docs/factories.md` | 中 |

実装順序は **8 → 1 → 2 → 3 → 4 → 7 → 5 → 6 → 9**。
gate (施策 8) を先に置き、mutation で赤化を確認してから本体に入る（思考原則 5 テストファースト）。

---

## 施策 1: 受信者視点の単一解決口 (`scopeActivePendingForEmail`)

### 変更箇所

- `app/Models/OrganizationInvitation.php`（`scopeActive` の直後に追加。L120-130 付近）

### 波及変更

- TypeScript 型定義: なし
- API Resource/DTO: なし（施策 2 が消費する）
- テストファイル: `tests/Feature/Invitations/PendingInvitationScopeTest.php`（新規）

### 現行コード

```php
    /**
     * Active (受諾可能: 未受諾・未失効・期限内) な招待の query scope。
     *
     * @param  Builder<OrganizationInvitation>  $query
     */
    public function scopeActive(Builder $query): void
    {
        $query->whereNull('accepted_at')
            ->whereNull('revoked_at')
            ->where('expires_at', '>', now());
    }
```

### 変更後コード

```php
    /**
     * Active (受諾可能: 未受諾・未失効・期限内) な招待の query scope。
     *
     * @param  Builder<OrganizationInvitation>  $query
     */
    public function scopeActive(Builder $query): void
    {
        $query->whereNull('accepted_at')
            ->whereNull('revoked_at')
            ->where('expires_at', '>', now());
    }

    /**
     * **受信者視点の単一解決口** — 「この email 宛の、いま受諾できる招待」の集合。
     *
     * アプリ内受諾 (invitations.accept-in-app) の解決・一覧・件数はすべてこの scope を
     * 再利用する (裁定 AG-113 の必須要素 (b)。2 つがずれると「件数は出るのに受諾できない」が起きる)。
     * 再利用の強制は InvitationResolutionInventoryTest が deny-by-default で行う。
     *
     * 3 条件は**すべて存在秘匿のためにある**:
     *  - active(): 期限切れ・取消済・受諾済を落とす
     *  - whereBlind: 宛先不一致を落とす (CipherSweet の blind index。平文 where は hit しない)
     *  - whereHas('organization'): 削除済み組織宛を落とす
     *    (Organization は SoftDeletes。default scope が効くため deleted_at 判定を手書きしない)
     * これらが**すべて同じ「0 件」に collapse する**ことが、呼び出し側で理由を出し分けずに
     * 一律 404 へ畳める根拠である (403 を返さない = 招待の存在を教えない)。
     *
     * ★email は**大文字小文字を区別する完全一致**である (email の blind index に
     *   Lowercase transformer を付けていない。name 側とは非対称)。大小差のある宛先は
     *   0 件 = 404 に倒れる (fail-secure)。従来のメール token 経路は token_hash 照合なので
     *   影響を受けず、そちらで受諾できる。
     * ★空文字 email での呼び出しは**呼び出し側が事前に弾く**契約
     *   (OrganizationMembershipService::pendingInvitationsQuery)。ここでは防御しない
     *   (guard を 2 箇所に置くと「どちらが正か」が曖昧になる)。
     *
     * @param  Builder<OrganizationInvitation>  $query
     */
    public function scopeActivePendingForEmail(Builder $query, string $email): void
    {
        $query->active()
            ->whereBlind('email', 'email_index', $email)
            ->whereHas('organization');
    }
```

### PHPStan 適合チェック

- [x] 戻り値の型が明示されている（`void`。Eloquent scope 規約）
- [x] null 安全（`string $email` は非 null。空文字契約は呼び出し側）
- [x] DTO を返している（scope なので該当なし）
- [x] Generics の型パラメータが正しい（`Builder<OrganizationInvitation>`）
- `whereBlind` は `Spatie\LaravelCipherSweet\Concerns\UsesCipherSweet` が提供する
  `scopeWhereBlind` 由来。既存 `User::whereBlind('email', 'email_index', ...)` と同じ形で
  PHPStan は解決済み（`app/Services/Organization/OrganizationMembershipService::emailBelongsToMember` に前例）

### テスト計画

`tests/Feature/Invitations/PendingInvitationScopeTest.php`（新規）

- [ ] `自分宛の active な招待だけを返す` — 同一 email 宛 active 1 件 + 他人宛 active 1 件 → 1 件
- [ ] `期限切れ・取消済・受諾済は返さない` — `expired()` / `revoked()` / `accepted()` state を各 1 件作り 0 件
- [ ] `削除済み (soft-deleted) 組織宛は返さない` — `$organization->delete()` 後に 0 件
- [ ] `email の大小差は一致しない (完全一致契約)` — `Foo@example.com` 宛の招待は `foo@example.com` で 0 件
- [ ] テストデータは `OrganizationInvitation::factory()->forOrganization($organization)->create([...])`
- [ ] 個別の `DatabaseTransactions` を使っていないことを確認

### リスク

- `whereHas('organization')` はサブクエリを 1 本足す。招待テーブルは 1 組織あたり数件規模で、
  `organization_id` に index がある（`create_organization_invitations_table` の複合 index）ため
  実害はない。共有 prop で毎リクエスト評価される点は施策 6 のリスク欄で扱う。
- **email の正規化契約**: `App\Support\EmailNormalizer` は inquiry / billing contact 専用で、
  **User の email は登録時に正規化していない**（`CreateNewUser` は validated 値をそのまま保存する）。
  招待側 `inviteMember` も入力 email をそのまま保存する。したがって
  「招待は `Foo@example.com` 宛 / ログインは `foo@example.com`」が実運用で起こりうる。
  **これは本件が持ち込む新しい非対称ではない** — 既存の `emailBelongsToMember` /
  `hasPendingInvitation` / `acceptInvitationIfValid` の email 一致判定もすべて同じ完全一致である。
  本件では**挙動を変えず契約を明記する**（施策 9 で `docs/architecture.md` へ）:
  大小差は 0 件 = 404 に倒れる（fail-secure）/ メール token 経路は `token_hash` 照合なので
  影響を受けず従来どおり受諾できる / 正規化するなら既存全レコードの blind index 再計算と
  全 `whereBlind` 呼び出し元の同時変更を伴う別作業になる。

---

## 施策 2: 受信者視点 DTO (`PendingInvitationForUserDto`)

### 変更箇所

- `app/DataTransferObjects/Invitations/PendingInvitationForUserDto.php`（新規）

### 波及変更

- TypeScript 型定義: `resources/js/types/invitation.ts`（新規。施策 5 で作る `PendingInvitation`）
- API Resource/DTO: 管理者視点の `app/DataTransferObjects/Admin/InvitationRowData.php` とは**別クラスのまま**
- テストファイル: `tests/Feature/Invitations/PendingInvitationForUserDtoTest.php`（新規）

### 現行コード

（新規ファイル。参考にする既存形は `app/DataTransferObjects/Admin/InvitationRowData.php`）

```php
final readonly class InvitationRowData
{
    public function __construct(
        public int $id,
        public string $email,
        public string $roleState, // MemberRoleState value
        public string $roleLabel,
        public string $expiresAt, // Y-m-d
    ) {}
    // ...
}
```

### 変更後コード

```php
<?php

declare(strict_types=1);

namespace App\DataTransferObjects\Invitations;

use App\Enums\OrganizationRole;
use App\Models\Organization;
use App\Models\OrganizationInvitation;
use Illuminate\Support\Carbon;
use Webmozart\Assert\Assert;

/**
 * **受信者視点**の保留中招待 1 件（アプリ内受諾 UI 用）。
 *
 * 管理者視点の InvitationRowData とは契約を分離したままにする（裁定 AG-113）:
 * 管理者は「誰を招待したか (email)」を見る面、受信者は「どこへ参加できるか」を見る面であり、
 * 開示すべき項目が違う。似ているからと統合しない（思考原則 4）。
 *
 * **開示するのはこの 4 つだけ**。email（自分の値だが載せる必要がない）/ token_hash /
 * accepted_at・revoked_at・expires_at の生値 / invited_by_user_id / organization_id は出さない。
 * 受諾 URL も持たせない（署名も token も無い経路のため、サーバが URL を配る意味が無く
 * 開示面だけ増える。front が route から組む）。
 */
final readonly class PendingInvitationForUserDto
{
    public function __construct(
        public int $id,
        public string $organizationName,
        public string $roleLabel,
        public string $expiresAt, // Y-m-d
    ) {}

    /**
     * scopeActivePendingForEmail で解決済みの招待から組み立てる。
     *
     * 呼び出し側で `->format()` を書かない（日時の文字列化責務をここへ集約する）。
     * organization は scope の whereHas で存在が保証されているが、
     * relation の遅延解決が null を返す可能性を型で潰すため Assert で narrow する。
     */
    public static function fromInvitation(OrganizationInvitation $invitation): self
    {
        $organization = $invitation->organization;
        Assert::isInstanceOf($organization, Organization::class);

        $expiresAt = $invitation->getAttribute('expires_at');
        Assert::isInstanceOf($expiresAt, Carbon::class);

        return new self(
            id: $invitation->id,
            organizationName: $organization->name,
            roleLabel: OrganizationRole::from($invitation->role)->label(),
            expiresAt: $expiresAt->toDateString(),
        );
    }

    /**
     * @return array{id: int, organizationName: string, roleLabel: string, expiresAt: string}
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'organizationName' => $this->organizationName,
            'roleLabel' => $this->roleLabel,
            'expiresAt' => $this->expiresAt,
        ];
    }
}
```

### PHPStan 適合チェック

- [x] 戻り値の型が明示されている（`self` / `array{...}` shape）
- [x] null 安全（`Assert::isInstanceOf` で `?Organization` と `mixed` を narrow）
- [x] DTO を返している（Inertia props へは `toArray()` の shape で渡す）
- [x] Generics の型パラメータが正しい（該当なし）

### テスト計画

`tests/Feature/Invitations/PendingInvitationForUserDtoTest.php`（新規）

- [ ] `開示項目は 4 つだけ` — `toArray()` の `array_keys()` が
      `['id', 'organizationName', 'roleLabel', 'expiresAt']` と**完全一致**すること
      （キー追加を機械検出する = 将来 email 等が混入したら赤くなる）
- [ ] `email / token_hash を含まない` — `json_encode($dto->toArray())` に招待 email 文字列と
      `token_hash` 値が含まれないこと（値ベースの negative control）
- [ ] `roleLabel は org ロールのラベル` — admin 招待 → `管理者` / member 招待 → `メンバー`
- [ ] `expiresAt は Y-m-d` — `expires_at` に固定日時を入れて文字列一致
- [ ] Factory 経由で招待を生成する

### リスク

- なし（新規 read-only DTO）。既存 `InvitationRowData` は触らない（施策 7 で別途変更）。

---

## 施策 3: 受諾サービス + 共有コアの戻り値強化

### 変更箇所

- `app/Services/Organization/OrganizationMembershipService.php`
  - `acceptInvitation()`（L110-138 付近）: `joinOrganization()` の `false` を消費
  - `acceptInvitationIfValid()`（L160-195 付近）: 同上 + 現在組織確定の抑止
  - `joinOrganization()`（L273-315 付近）: `void` → `bool`
  - **新規**: `pendingInvitationsQuery()`（private）/ `pendingInvitationsFor()` /
    `pendingInvitationCountFor()` / `acceptPendingInvitation()`

### 波及変更

- TypeScript 型定義: なし
- API Resource/DTO: `PendingInvitationForUserDto`（施策 2）を返す
- テストファイル:
  - `tests/Architecture/MembershipWriteLockInventoryTest.php`（新 public メソッドの分類登録。施策 4）
  - `tests/Feature/Organization/InvitationAcceptRaceTest.php`（新規。`false` 消費の契約）
  - `tests/Feature/Organization/InvitationTest.php`（既存。register 経路の現在組織確定の契約を追加）

### 現行コード

```php
    private function joinOrganization(OrganizationInvitation $invitation, Organization $organization, User $user, OrganizationRole $role): void
    {
        DB::transaction(function () use ($organization, $user, $role, $invitation): void {
            $this->lockForMembershipWrite([$this->keyOf($user)], [$this->keyOf($organization)]);

            /** @var OrganizationInvitation $locked */
            $locked = OrganizationInvitation::query()->whereKey($invitation->id)->lockForUpdate()->firstOrFail();
            if ($locked->isAccepted() || $locked->isRevoked() || $locked->isExpired()) {
                return; // 期限境界の TOCTOU も含めロック下で完全再検証 (敗者は冪等 no-op)
            }

            $joined = DB::table('organization_user')->insertOrIgnore([...]);

            if ($joined === 1) {
                $user->addRole($role->value, $organization->laratrust_team_id);

                $projectRole = $locked->project_role;
                if ($projectRole instanceof ProjectRole) {
                    $project = $this->defaultProjects->resolveForUpdate($organization);
                    $project?->members()->syncWithoutDetaching([...]);
                }
            }

            $locked->forceFill(['accepted_at' => now()])->save();
        });
    }
```

```php
    public function acceptInvitation(string $plainToken, User $user): Organization
    {
        // ... 事前検証 (invalid / accepted / expired / 既メンバー) ...
        $role = OrganizationRole::from($invitation->role);

        $this->joinOrganization($invitation, $organization, $user, $role);

        return $organization;
    }
```

```php
    public function acceptInvitationIfValid(string $plainToken, User $user): ?Organization
    {
        // ... findActiveByPlainToken / email 一致 / 既メンバー ...
        $this->joinOrganization($invitation, $organization, $user, OrganizationRole::from($invitation->role));

        $user->forceFill(['current_organization_id' => $organization->id])->save();

        return $organization;
    }
```

### 変更後コード

```php
    /**
     * **受信者視点の pending 集合クエリの唯一の起点**。
     *
     * 裁定 AG-113 の必須要素 (b)(c) をここ 1 箇所で満たす:
     *  (b) 受諾の解決・一覧・件数がすべてこのメソッドを通る (絞り込みが 1 本 = drift しない)
     *  (c) 未ログイン / 未 verified / email 空は **null を返し DB を一切引かない**
     *      (共有 prop は全リクエストで評価されるため、この early return が実効的な負荷契約になる)
     *
     * @return Builder<OrganizationInvitation>|null null = 引くべきでない (クエリを組み立てない)
     */
    private function pendingInvitationsQuery(?User $user): ?Builder
    {
        if ($user === null || ! $user->hasVerifiedEmail()) {
            return null;
        }

        $email = $user->email; // CipherSweet 復号後
        if ($email === '') {
            return null;
        }

        return OrganizationInvitation::query()->activePendingForEmail($email);
    }

    /**
     * 自分宛の受諾可能な招待の一覧 (受信者視点 DTO)。表示専用でロックしない。
     *
     * @return list<PendingInvitationForUserDto>
     */
    public function pendingInvitationsFor(?User $user): array
    {
        $query = $this->pendingInvitationsQuery($user);
        if ($query === null) {
            return [];
        }

        return $query->with('organization')
            ->orderBy('expires_at')
            ->get()
            ->map(fn (OrganizationInvitation $invitation): PendingInvitationForUserDto => PendingInvitationForUserDto::fromInvitation($invitation))
            ->values()
            ->all();
    }

    /** 自分宛の受諾可能な招待の件数 (共有 prop 用。一覧と同一 scope を再利用する)。 */
    public function pendingInvitationCountFor(?User $user): int
    {
        return $this->pendingInvitationsQuery($user)?->count() ?? 0;
    }

    /**
     * **アプリ内受諾** (メールの URL を根拠にしない受諾。裁定 AG-113 標準形 v1)。
     *
     * 受諾の根拠は「auth 済み ∧ email 確認済み ∧ ログイン者 email = 招待宛先」であり、
     * その全部が pendingInvitationsQuery() の 1 本に畳まれている。
     *
     * **戻り値契約**: 業務上の受諾不能 (宛先不一致 / 不在 / 期限切れ / 取消済 / 受諾済 /
     * 組織削除済 / ロック下再検証での敗北) は例外にせず null を返す (呼び出し側が一律 404)。
     * DB 障害・インフラ障害・プログラム不整合の例外は**捕捉せず伝播させる** (500 のまま。
     * 404 に化けさせない)。この分離により、将来この分岐へ理由を足しても情報が漏れない。
     *
     * **ロックと最終権威**:
     *  1. 下見 (ロック無し) で organization_id を得る
     *  2. canonical 順序 (users 昇順 → organizations) で lockForMembershipWrite
     *     — 組織の soft-delete は同じ organizations 行の UPDATE なのでここで直列化される
     *  3. **ロック下で同一 scope を再解決** — ここが組織 soft-delete / 取消 / 期限に対する権威
     *  4. joinOrganization() が招待行を lockForUpdate して最終再検証 (取消の割り込みはここが閉じる。
     *     revokeInvitation は membership ロックを取らないため 3 と 4 の間に窓があるが、
     *     取り消し側の UPDATE も同じ招待行を取るため直列化される)
     * joinOrganization() は同一 tx 内で同じ行の lockForMembershipWrite を再取得するが、
     * 取得済み行の再取得は no-op でロック順序も変わらない (新しい順序を作らない
     * = デッドロックを導入しない)。
     *
     * @param  string  $invitationId  route parameter (未検証の文字列。pattern で 1-18 桁数値に制約済み)
     */
    public function acceptPendingInvitation(?User $user, string $invitationId): ?Organization
    {
        if ($user === null) {
            return null;
        }

        return DB::transaction(function () use ($user, $invitationId): ?Organization {
            // 1. 下見 (ロック前)。ここで null なら DB もロックも最小で終わる
            $preliminary = $this->pendingInvitationsQuery($user)?->whereKey($invitationId)->first();
            if ($preliminary === null) {
                return null;
            }

            // 2. canonical 順序でロック (users 昇順 → organizations)
            $organizationId = $preliminary->getAttribute('organization_id');
            Assert::integer($organizationId);
            $this->lockForMembershipWrite([$this->keyOf($user)], [$organizationId]);

            // 3. ロック下で同一 scope を再解決 (下見の結果は信用しない)
            $invitation = $this->pendingInvitationsQuery($user)?->whereKey($invitationId)->first();
            if ($invitation === null) {
                return null;
            }

            $organization = $invitation->organization;
            Assert::isInstanceOf($organization, Organization::class);

            // 4. 変換本体 (署名経路と共有)。false = 招待行ロック下の再検証で受諾不能
            if (! $this->joinOrganization($invitation, $organization, $user, OrganizationRole::from($invitation->role))) {
                return null;
            }

            // 現在組織は切り替えない (POST 受諾の既存契約と揃える。驚き最小)
            return $organization;
        });
    }
```

`joinOrganization()` の変更（`bool` 化 + pivot attach 撤去は施策 7）:

```php
    /**
     * 招待受諾の確定処理 (attach + ロール付与 + accepted_at)。両受諾経路の共通コア。
     *
     * @return bool true = ロック下再検証を通り変換が完了した (既 join の冪等 no-op を含む) /
     *              false = ロック下で受諾不能 (受諾済 / 取消済 / 期限切れ) だった。
     *              **false は全呼び出し元が必ず消費する** (成功扱いで返さない)。
     */
    private function joinOrganization(OrganizationInvitation $invitation, Organization $organization, User $user, OrganizationRole $role): bool
    {
        return DB::transaction(function () use ($organization, $user, $role, $invitation): bool {
            $this->lockForMembershipWrite([$this->keyOf($user)], [$this->keyOf($organization)]);

            /** @var OrganizationInvitation $locked */
            $locked = OrganizationInvitation::query()->whereKey($invitation->id)->lockForUpdate()->firstOrFail();
            if ($locked->isAccepted() || $locked->isRevoked() || $locked->isExpired()) {
                return false; // 期限境界の TOCTOU も含めロック下で完全再検証 (敗者は受諾不能)
            }

            $joined = DB::table('organization_user')->insertOrIgnore([
                'organization_id' => $organization->id,
                'user_id' => $user->getKey(),
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            if ($joined === 1) {
                $user->addRole($role->value, $organization->laratrust_team_id);
            }

            $locked->forceFill(['accepted_at' => now()])->save();

            return true;
        });
    }
```

既存 2 経路の追随:

```php
    public function acceptInvitation(string $plainToken, User $user): Organization
    {
        // ... 事前検証は現行のまま ...
        if (! $this->joinOrganization($invitation, $organization, $user, $role)) {
            // ロック下再検証で受諾不能になった (並行受諾 / 取り消し / 期限到来)。
            // 事前検証と同じ中立メッセージへ畳む (取り消された事実を token 保持者に開示しない)
            throw ValidationException::withMessages(['token' => ['この招待は無効です。']]);
        }

        return $organization;
    }
```

```php
    public function acceptInvitationIfValid(string $plainToken, User $user): ?Organization
    {
        // ... findActiveByPlainToken / email 一致 / 既メンバー は現行のまま ...
        if (! $this->joinOrganization($invitation, $organization, $user, OrganizationRole::from($invitation->role))) {
            // 受諾不能なら現在組織も確定しない (現行は join 失敗でも current_organization_id を
            // 招待組織へ書いてしまい、非所属 org が current になる非正規状態を作っていた)
            return null;
        }

        $user->forceFill(['current_organization_id' => $organization->id])->save();

        return $organization;
    }
```

### PHPStan 適合チェック

- [x] 戻り値の型が明示されている（`?Builder`, `list<PendingInvitationForUserDto>`, `int`, `?Organization`, `bool`）
- [x] null 安全（`Assert::integer` / `Assert::isInstanceOf` / null 合体演算子）
- [x] DTO を返している（一覧は DTO の list。配列生値を返さない）
- [x] Generics の型パラメータが正しい（`Builder<OrganizationInvitation>` を `@return` に明記）
- `DB::transaction()` のクロージャ戻り値は Laravel が透過して返す（`@template` 付き）ため
  `?Organization` / `bool` が推論される。`use` 変数の型も明示済み

### テスト計画

`tests/Feature/Organization/InvitationAcceptRaceTest.php`（新規。**`false` 消費の契約**）

> docblock に「**目的は競合の完全再現ではなく `joinOrganization() === false` の消費契約を
> 決定的に検証すること**」と明記する。

決定的な作り方は **SQL の形で当てる**（取得回数で当てない — `acceptPendingInvitation` は
「下見 → ロック下再解決 → `joinOrganization` 内のロック取得」で回数が経路ごとに変わり、
回数依存は実装変更に脆いため）:

```php
// tests/Feature/Organization/InvitationAcceptRaceTest.php の helper
function revokeOnLockedRead(int $invitationId): void
{
    $fired = false; // one-shot。自分の UPDATE による再入を止める
    DB::beforeExecuting(function (string $query) use ($invitationId, &$fired): void {
        if ($fired) {
            return;
        }
        // joinOrganization がロック下再検証のために発行する SELECT ... FOR UPDATE を検出する
        if (! str_contains($query, 'organization_invitations') || ! str_contains(strtolower($query), 'for update')) {
            return;
        }
        $fired = true;
        // 同一接続・同一トランザクション内なので自分のロックと競合しない。
        // 取り消しが割り込んだのと同じ状態を作る
        DB::table('organization_invitations')->where('id', $invitationId)->update(['revoked_at' => now()]);
    });
}
```

`joinOrganization` の `lockForUpdate()->firstOrFail()` が更新後の行を読み、
`isRevoked() === true` で `false` を返す。one-shot フラグの後始末は `try/finally` で行う。

- [ ] `acceptInvitation はロック下再検証の敗北を ValidationException へ畳む` —
      メッセージが事前検証と同一（`この招待は無効です。`）であること
- [ ] `acceptInvitationIfValid はロック下再検証の敗北で null を返し、現在組織を書き換えない` —
      `$user->fresh()->current_organization_id` が呼び出し前と不変
- [ ] `acceptPendingInvitation はロック下再検証の敗北で null を返す`
- [ ] いずれのケースでも `organization_user` に行が増えていないこと

`tests/Architecture/MembershipWriteLockInventoryTest.php`（既存に検査を 1 本追加。
**`false` を捨てた呼び出しの静的検出**）

`token_get_all()` でサービスファイルをトークン化し、`joinOrganization` 呼び出しトークンの
**直前の有意トークン**（空白・コメントを除く）が
`T_RETURN` / `=` / `!` / `&&` / `||` / `?` のいずれかであることを要求する
（文の先頭 = `;` `{` `}` の直後なら戻り値を捨てているので fail）。

- 文字列一致（`if (! $this->joinOrganization(`）にしないのは、
  `$joined = $this->joinOrganization(...); if (! $joined)` という正常な実装で壊れ、
  かつコメント中の同一文字列で誤って通るため。トークン種別で見ればどちらも起きない。
- この静的検査は「**消費している形か**」だけを見る。
  「消費した結果の契約が正しいか」は `InvitationAcceptRaceTest`（behavioral）が見る。
  2 本は役割が違うので**併存させる**。
- [ ] 負のコントロール: 実装時に `acceptPendingInvitation` 内の呼び出しを
      `$this->joinOrganization(...);` （戻り値破棄）へ一時的に書き換えて fail することを確認する

`tests/Feature/Invitations/PendingInvitationQueryGuardTest.php`（新規。**(c) の DB 非問い合わせ**）

- [ ] `未ログインは organization_invitations を引かない` — `DB::listen` で
      `organization_invitations` を含む SQL が 0 件、戻り値 0 / `[]`
- [ ] `未 verified は引かない` — `User::factory()->unverified()` で同上
- [ ] `email 空は引かない` — `forceFill(['email' => ''])` した user で同上
- [ ] `verified かつ email 非空のときだけ引く` — SQL が 1 件以上発行される（**負のコントロール**。
      guard が常に null を返す実装退行を検出する）

`tests/Feature/Organization/InvitationTest.php`（既存の更新）

- [ ] register 経路の既存テストに「join に成功したときだけ current_organization_id が確定する」
      アサーションを追加（既存の成功系テストは挙動不変であることの確認）

### リスク

- `joinOrganization()` の戻り値変更は**署名 token 経路の競合時挙動を変える**（成功扱い →
  失敗契約）。これは是正であって後退ではないが、既存 `InvitationTest` の
  「二重受諾」系テストが成功を期待していないかを実装時に確認する
  （現行は事前検証で `既に使用されています` に落ちるため影響しない見込み）。
- `acceptPendingInvitation` は下見 + 再解決で**招待の解決クエリを 2 回**発行する。
  受諾は一回性の低頻度操作であり、ロック確立後の再解決を省くと存在秘匿の権威が失われるため
  この 2 回は設計上必要なコスト。
- `pendingInvitationsFor` は `with('organization')` で N+1 を避ける（DTO が organization->name を読む）。

---

## 施策 4: 受諾 route / Controller / named limiter / gate 6 本

### 変更箇所

- `routes/web.php`（招待受諾セクション L598-617 の直後）
- `app/Http/Controllers/Organizations/AcceptInvitationInAppController.php`（新規）
- `app/Providers/AppServiceProvider.php`（named limiter の追加。L282 付近）
- `app/Http/Routing/RouteBindingTypes.php`（`MANUALLY_RESOLVED`）
- `tests/Support/Routing/NestedRouteDefenseInventory.php`（`inventory()`）
- `tests/Architecture/ControllerAuthorizationGateTest.php`（`controllerAuthorizationExemptions()`）
- `tests/Architecture/MembershipWriteLockInventoryTest.php`（`delegatedToLocked` / `exempt`）
- `tests/Architecture/RateLimiterKeyConventionTest.php`（limiter inventory + 評価シナリオ）

### 波及変更

- TypeScript 型定義: なし（施策 5 で front を作る）
- API Resource/DTO: なし
- テストファイル: `tests/Feature/Invitations/AcceptInvitationInAppTest.php`（新規、存在秘匿の網羅）

### 現行コード

```php
// routes/web.php L609-616
Route::get('/invitations/accept', [InvitationAcceptanceController::class, 'show'])
    ->middleware('throttle:invitation-accept')
    ->name('invitations.accept');
Route::post('/invitations/accept', [InvitationAcceptanceController::class, 'store'])
    ->middleware(['auth', 'throttle:10,1'])
    ->name('invitations.accept.store');
```

```php
// app/Providers/AppServiceProvider.php L282-283
        RateLimiter::for('invitation-accept', fn (Request $request): Limit => Limit::perMinute(10)
            ->by('invitation-accept:ip:'.($request->ip() ?? 'unknown')));
```

### 変更後コード

**route**（既存 2 本の直後に追加。`require-active-subscription` group の外＝既存 `invitations.*` と同じ扱い。
未契約組織を current org に持つユーザーが別組織の招待を受諾できないと詰むため）:

```php
/*
| アプリ内受諾 (メールの URL を根拠にしない第 2 の受諾経路。裁定 AG-113 標準形 v1)。
| 根拠は「auth 済み ∧ email 確認済み ∧ ログイン者 email = 招待宛先」。
| 既存 token 経路を**置き換えず並べて持つ** (未登録の人にはメールが唯一の入口)。
|
| ★{invitation} は implicit binding させない。binding 段で解決すると
|   「不在 id = binding 404 / 実在の他人宛 = 後段の応答」に分岐し 1 bit の存在オラクルになる。
|   controller が $user 宛の有効 pending 集合から手動解決し、全ての不成立を 404 へ畳む。
| ★verified 必須。姉妹の invitations.accept.store は意図的に verified を要求しない
|   (招待直後の未検証ユーザーも token で受諾できる仕様) — この非対称は仕様であり、
|   受諾根拠が違うことに由来する (docs/architecture.md に理由を明記)。
| ★throttle は named limiter。inline throttle は同一 actor の全 inline route と bucket を
|   共有し、最小 max (recent-auth.password = 6) を巻き添えにするため使わない。
*/
Route::post('/invitations/{invitation}/accept-in-app', AcceptInvitationInAppController::class)
    ->middleware(['auth', 'verified', 'throttle:invitation-accept-in-app'])
    ->name('invitations.accept-in-app');
```

**named limiter**:

```php
        // アプリ内受諾 (invitations.accept-in-app)。閾値は姉妹 invitations.accept.store
        // (throttle:10,1) / invitation-accept (10/min) と同値 = 既存値を変えない。
        // ★キーは actor 単位。throttle は auth より前に走るため未認証でも評価されうるので
        //   render-trigger と同じ idiom で 'guest' へ落とす (未認証は後段の auth で 302 になり
        //   受諾へ到達できないため、guest 同士が同一 bucket でも実害が無い)。
        // ★route parameter ({invitation}) をキーに混ぜない。混ぜると bucket が分かれ
        //   「429 になるまでの回数」が存在オラクルになる。
        RateLimiter::for('invitation-accept-in-app', function (Request $request): Limit {
            $user = $request->user();
            $actor = $user instanceof User ? (string) $user->id : 'guest';

            return Limit::perMinute(10)->by("invitation-accept-in-app:user:{$actor}");
        });
```

**Controller**:

```php
<?php

declare(strict_types=1);

namespace App\Http\Controllers\Organizations;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\Organization\OrganizationMembershipService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Webmozart\Assert\Assert;

/**
 * アプリ内からの招待受諾 (POST invitations.accept-in-app)。裁定 AG-113 標準形 v1。
 *
 * 受諾の根拠は URL の所持ではなく「auth 済み (middleware) ∧ email 確認済み (middleware) ∧
 * ログイン者 email = 招待の宛先 (Service の scope)」。
 *
 * **認可 (Gate) を持たない**のは意図的である:
 * 受諾前の user は対象組織の非メンバーで組織 Policy は構造的に必ず拒否になるうえ、
 * 403 を返すこと自体が「その招待は実在する」を教える口になる。代わりに
 * OrganizationMembershipService::acceptPendingInvitation() が
 * $user 宛の有効 pending 集合からしか解決せず、**すべての不成立を 404 に畳む**
 * (ControllerAuthorizationGateTest に SelfScopedResource として理由付きで登録済み)。
 *
 * **{invitation} は string で受ける**。型を Model にすると implicit binding が復活し、
 * 不在 id だけが binding 段で 404 になって存在オラクルが生まれる
 * (TenantBoundaryOrderingTest 検査 3a が action 引数型を機械検証する)。
 */
class AcceptInvitationInAppController extends Controller
{
    public function __invoke(Request $request, string $invitation, OrganizationMembershipService $membership): RedirectResponse
    {
        $user = $request->user();
        Assert::isInstanceOf($user, User::class);

        $organization = $membership->acceptPendingInvitation($user, $invitation);

        // 業務上の受諾不能はすべて 404 (403 を返さない = 存在を教えない)。
        // back() も flash も出さない (文脈依存の戻り先そのものが手掛かりになるため)。
        // abort_if ではなく if + abort にする (abort() は never を返すため PHPStan が
        // 以降で $organization を非 null に narrow できる)。
        if ($organization === null) {
            abort(404);
        }

        // 現在組織は切り替えない契約のため、参加先の画面ではなく dashboard へ着地させる
        // (既存 invitations.accept.store の成功応答と同形。intended は使わない = 禁止事項 7)。
        return redirect()->route('dashboard')
            ->with('success', "「{$organization->name}」に参加しました");
    }
}
```

**gate 登録**（対応が要る gate は 6 本。うち inventory への明示登録は 5 本）:

> **なぜ `NestedRouteDefenseInventory` への登録が必須か**（nested route ではないから不要、
> という誤解を防ぐ）:
> 同 inventory の class docblock が「**母集団は 1 個以上の parameter を持つ named route**
> （旧実装は 2 個以上だった）」と明記し、`candidates()` も
> `if ($route->parameterNames() === []) { continue; }` だけで絞っている。
> **nested かどうかは母集団の条件ではない**（2+param に絞ったせいで単独 param の route が
> 丸ごと母集団から外れ、実際に穴が残ったのが audit-cycle-2 High-1）。
> したがって 1 param の `invitations.accept-in-app` は登録しないと
> `NestedRouteIdorDefenseTest` が「分類漏れ」で fail する = 登録は選択肢ではない。
> 先例として `'notifications.open' => ['notification' => $manual]` が**完全に同形**
> （個人スコープ・単一 param・controller が `$user->notifications()` から手動解決）。
> さらに `TenantBoundaryOrderingTest` 検査 3a は
> **inventory で `ManualOwnerScopedResolution` を宣言した param に対してのみ**
> 「action 引数が Model 型でない」「`MANUALLY_RESOLVED` に route identity ごと登録済み」
> 「explicit binder が無い」を機械検証する。inventory に入れないとこの 3 検査が走らず、
> 存在オラクル防御が無検証になる。

| # | gate | 登録内容 |
|---|------|---------|
| 1 | `tests/Support/Routing/NestedRouteDefenseInventory.php` | `'invitations.accept-in-app' => ['invitation' => $manual],` を「個人スコープ」ブロックへ追加（`$manual = ManualOwnerScopedResolution`。これは**解決方式の宣言**でありモデル種別ではない） |
| 2 | `app/Http/Routing/RouteBindingTypes.php` | `MANUALLY_RESOLVED` に `'invitation' => ['routes' => ['invitations.accept-in-app'], 'reason' => '...']` を追加（`{invitation}` は既に `BIGINT` 登録済みなので pattern は継続適用される） |
| 3 | `tests/Architecture/ControllerAuthorizationGateTest.php` | `'invitations.accept-in-app' => [$selfScoped, '...']`（理由は下記） |
| 4 | `tests/Architecture/MembershipWriteLockInventoryTest.php` | `delegatedToLocked` に `acceptPendingInvitation`、`exempt` に `pendingInvitationsFor` / `pendingInvitationCountFor`。加えて**戻り値消費のトークナイザ検査を 1 本追加**（施策 3） |
| 5 | `tests/Architecture/RateLimiterKeyConventionTest.php` | limiter inventory に `invitation-accept-in-app` + 認証済み / guest の評価シナリオ |
| 6 | `tests/Architecture/ThrottleCoverageInventoryTest.php` | **登録不要**（throttle を 1 本持つため母集団の要求を満たす。exemption cap=25 は変えない） |

`RouteBindingTypes::MANUALLY_RESOLVED` の理由文:

```php
        // AcceptInvitationInAppController は $user 宛の有効 pending 集合 (scopeActivePendingForEmail)
        // から解決する。implicit binding のままだと「不在 id = binding 404 / 実在の他人宛 =
        // 後段短絡」に分岐し 1 bit の存在オラクルになる。
        'invitation' => [
            'routes' => ['invitations.accept-in-app'],
            'reason' => '存在秘匿のため controller が認証ユーザー宛の有効 pending 集合から解決する'
                .' (binding 段で解決しないことが不在 id と実在の他人宛招待を同一の 404 にする根拠)',
        ],
```

`ControllerAuthorizationGateTest` の exemption 理由文（30 字以上）:

```php
        'invitations.accept-in-app' => [$selfScoped,
            '対象は認証ユーザー自身宛の招待のみ。acceptPendingInvitation が '
            .'scopeActivePendingForEmail($user->email) の集合からしか解決せず、宛先不一致・不在・'
            .'期限切れ・取消済・削除済み組織宛はすべて 404 に畳まれる。受諾前の user は対象組織の'
            .'非メンバーであり組織 Policy は構造的に必ず拒否になるうえ、403 を返すこと自体が'
            .'招待の存在を教える口になるため Gate を置かない (層 2 の 404 が層 3 より前という不変条件)。'],
```

`MembershipWriteLockInventoryTest` の分類（`revokeInvitation` の exempt 理由も更新）:

```php
    $delegatedToLocked = ['acceptInvitation', 'acceptInvitationIfValid', 'acceptPendingInvitation'];
    $exempt = [
        'inviteMember',
        // 招待の論理失効のみ (membership/role 不変)。**受諾との競合の最終権威は
        // joinOrganization が取る招待行の lockForUpdate 側にあり**、取り消しの UPDATE も
        // 同じ行を取るため直列化される (ここで membership ロックを取る必要はない)
        'revokeInvitation',
        // 受信者視点の read-only (表示・件数)。membership/role を変えない
        'pendingInvitationsFor',
        'pendingInvitationCountFor',
        // ...既存...
    ];
```

### PHPStan 適合チェック

- [x] 戻り値の型が明示されている（`RedirectResponse` / `Limit`）
- [x] null 安全（`Assert::isInstanceOf($user, User::class)` で union を narrow、`abort_if` の後は非 null）
- [x] DTO を返している（本 route は redirect のみ。`response()->json()` を使わない）
- [x] Generics の型パラメータが正しい（該当なし）
- 404 は `if ($organization === null) { abort(404); }` の形で書く。
  `abort_if()` は `never` を返さないため PHPStan が以降で非 null に narrow できない

### テスト計画

`tests/Feature/Invitations/AcceptInvitationInAppTest.php`（新規。**存在秘匿の網羅**）

- [ ] `自分宛の有効な招待を受諾できる` — 302 → `dashboard`、`success` flash に組織名、
      `organization_user` に行、`accepted_at` が非 null、Laratrust ロールが付与されている
- [ ] `受諾しても現在組織は切り替わらない` — `current_organization_id` が不変
- [ ] `他人宛の実在する招待は 404 (403 ではない)` — `assertNotFound()` かつ
      `assertStatus(403)` にならないこと（403 でないことを明示的に検証する）
- [ ] `不在 id は 404`
- [ ] `期限切れは 404`（`expired()` state）
- [ ] `取消済みは 404`（`revoked()` state）
- [ ] `受諾済みは 404`（`accepted()` state）
- [ ] `削除済み (soft-deleted) 組織宛は 404`
- [ ] `受諾直後の再 POST は 404` — 1 回目 302、2 回目 404（冪等 200 にしない = 秘匿）
- [ ] `既にメンバーの user 宛の招待は冪等に成功する` — `organization_user` が重複せず
      `accepted_at` が立ち success flash（`insertOrIgnore` の 0 行分岐）
- [ ] `未 verified は verified middleware で遮断され、実在 id と不在 id で応答が同一` —
      両方 302 かつ同一 location（**存在オラクルが無いことの検証**）
- [ ] `guest は login へ 302`
- [ ] `非数値 id / 19 桁 id は 404 (500 にならない)` — `BIGINT_PATTERN` の効きを確認
- [ ] `throttle: 不在 id へ 10 回 POST はすべて 404、11 回目が 429`
- [ ] `throttle: 有効な招待への正常受諾 1 回は 429 にならない`
- [ ] テストデータは `OrganizationInvitation::factory()` / `User::factory()` 経由
- [ ] 個別の `DatabaseTransactions` を使っていない

Architecture 側は既存 gate が自動で走る（`NestedRouteIdorDefenseTest` /
`TenantBoundaryOrderingTest` 検査 3a / `ControllerAuthorizationGateTest` /
`ThrottleCoverageInventoryTest` / `RateLimiterKeyConventionTest` /
`RouteBindingTypeConstraintInventoryTest`）。**登録を忘れると赤くなる**のが正しい状態。

### リスク

- `verified` を必須にしたため、**招待された直後にまだメール確認していないユーザーは
  アプリ内受諾を使えない**（メール token 経路は従来どおり使える）。これは受諾根拠の定義から来る
  意図的な制約で、非対称の理由を `docs/architecture.md` に明記する（施策 9）。
- named limiter を 1 本増やす。`RateLimiterKeyConventionTest` は全 limiter を実評価するため、
  キー組み立てに `$request->user()` を使う点（guest シナリオでも例外を投げない）を確認する。
- route 追加により `ThrottleCoverageInventoryTest` の母集団が 1 増える（floor=60 に対し余裕あり）。

---

## 施策 5: 発見面 → 受諾の導線

### 変更箇所

- `app/Http/Controllers/NotificationController.php`（`index()` に prop 追加 / `open()` の
  `InvitationReceived` 分岐 L85-88 付近）
- `resources/js/pages/Notifications/Index.svelte`
- `resources/js/components/features/notifications/NotificationListItem.svelte`（本文の文言）
- `resources/js/components/features/invitations/PendingInvitationList.svelte`（新規）
- `resources/js/types/invitation.ts`（新規）

### 波及変更

- TypeScript 型定義: `resources/js/types/invitation.ts` の `PendingInvitation`
  （PHP の `PendingInvitationForUserDto::toArray()` と対で保守。docblock で相互参照する）
- API Resource/DTO: `PendingInvitationForUserDto`（施策 2）
- テストファイル: `tests/Feature/Notifications/NotificationCenterTest.php`（既存の
  `InvitationReceived` open 分岐アサーションが割れる）/
  `tests/js/components/features/invitations/PendingInvitationList.test.ts`（新規）

### 現行コード

```php
    /** 通知一覧 (全 org 横断 = 自分宛のみで構造的に閉じる) */
    public function index(Request $request): Response
    {
        $user = $this->authedUser($request);
        $paginator = $this->notifications->paginateFor($user);
        // ...
        return Inertia::render('Notifications/Index', [
            'notifications' => $items,
            'unreadCount' => $this->notifications->unreadCountFor($user),
            'meta' => [...],
        ]);
    }
```

```php
            $item->type === NotificationType::InvitationReceived => redirect()
                ->route('notifications.index', [], 303)
                ->with('info', '招待はメールの受諾リンクから参加してください。'),
```

```svelte
        if (invitationPayload) {
            return "メールの受諾リンクから参加してください";
        }
```

### 変更後コード

```php
class NotificationController extends Controller
{
    public function __construct(
        private readonly NotificationCenterService $notifications,
        private readonly OrganizationMembershipService $membership,
    ) {}

    public function index(Request $request): Response
    {
        $user = $this->authedUser($request);
        // ...
        return Inertia::render('Notifications/Index', [
            'notifications' => $items,
            'unreadCount' => $this->notifications->unreadCountFor($user),
            // 自分宛の受諾可能な招待 (受信者視点 DTO)。共有 prop の件数・受諾の解決と
            // **同一 scope** から算出する (裁定 AG-113 必須要素 (b))
            'pendingInvitations' => array_map(
                fn (PendingInvitationForUserDto $dto): array => $dto->toArray(),
                $this->membership->pendingInvitationsFor($user),
            ),
            'meta' => [...],
        ]);
    }
```

```php
            // 招待通知: 受諾可能な一覧が出る通知センターへ戻す。
            // ★通知 payload は招待 id を持たないため「この招待」を特定できない。
            //   したがって flash は**集合表現**にする (件数 0 のときだけ説明を出す)。
            //   件数は受諾の解決と同一 scope から算出する。
            $item->type === NotificationType::InvitationReceived => $this->membership->pendingInvitationCountFor($user) > 0
                ? redirect()->route('notifications.index', [], 303)
                : redirect()->route('notifications.index', [], 303)
                    ->with('info', '現在有効な招待はありません (取り消し・期限切れ・参加済みの可能性があります)。'),
```

```svelte
        if (invitationPayload) {
            return "クリックすると、届いている招待から参加できます";
        }
```

`resources/js/types/invitation.ts`（新規）:

```ts
/**
 * 受信者視点の保留中招待。
 * PHP 側 App\DataTransferObjects\Invitations\PendingInvitationForUserDto::toArray() と対で保守する。
 * 管理者視点の InvitationRow (types/admin.ts) とは別契約 (統合しない)。
 */
export interface PendingInvitation {
    id: number;
    organizationName: string;
    roleLabel: string;
    /** Y-m-d */
    expiresAt: string;
}

/** HandleInertiaRequests が共有する invitations props */
export interface InvitationSharedProps {
    pendingCount: number;
}
```

`resources/js/components/features/invitations/PendingInvitationList.svelte`（新規、要点のみ）:

```svelte
<script lang="ts">
    import { router } from "@inertiajs/svelte";
    import { Mail } from "@lucide/svelte";
    import Button from "@/components/atoms/Button.svelte";
    import Card from "@/components/atoms/Card.svelte";
    import Badge from "@/components/atoms/Badge.svelte";
    import type { PendingInvitation } from "@/types/invitation";

    /**
     * 自分宛の受諾可能な招待の一覧 (受諾ボタン付き)。
     * 受諾 = POST /invitations/{id}/accept-in-app (サーバが 302 で dashboard へ着地させる)。
     *
     * ★禁止事項 8 との関係: 「必須条件未充足を理由に disabled にする」ことはしない
     *   (前提条件で押せないボタンを作らない)。in-flight 中は既存 Button atom の
     *   `loading` (= disabled + aria-busy) を使う — これは二重送信防止であって
     *   必須条件未充足による無効化ではなく、同画面の招待送信ボタン
     *   (`loading={inviteForm.processing}`) と同じ既存流儀である。
     *   加えてハンドラ側でも in-flight 中の再入を無視する (二重の送信ガード)。
     * ★DS: 色 / radius / typography は token 経由のみ (hex 直書き・独自 radius を増やさない)。
     *   アイコンは @lucide/svelte の Mail のみ (SVG 直書きを新設しない)。
     *   Card の入れ子を作らない (この component 自身が 1 枚の Card)。
     */
    interface Props {
        invitations: PendingInvitation[];
    }

    let { invitations }: Props = $props();

    let acceptingId = $state<number | null>(null);

    function accept(invitation: PendingInvitation): void {
        if (acceptingId !== null) return; // in-flight 中の再入を無視 (disabled ではない)
        acceptingId = invitation.id;
        router.post(
            `/invitations/${invitation.id}/accept-in-app`,
            {},
            { onFinish: () => { acceptingId = null; } },
        );
    }
</script>

{#if invitations.length > 0}
    <Card padding="lg" data-testid="pending-invitation-list">
        <h2 class="text-h3">届いている招待</h2>
        <p class="mt-1 text-caption text-text-secondary">
            あなた宛の招待です。参加すると、その組織のメンバーになります。
        </p>
        <ul class="mt-4 divide-y divide-border">
            {#each invitations as invitation (invitation.id)}
            <li
                class="flex flex-wrap items-center gap-3 py-3"
                data-testid={`pending-invitation-${invitation.id}`}
            >
                <span class="inline-flex size-8 shrink-0 items-center justify-center rounded-md bg-primary-soft text-primary" aria-hidden="true">
                    <Mail class="size-4" />
                </span>
                <p class="min-w-0 grow truncate text-body text-text">{invitation.organizationName}</p>
                <Badge tone="neutral" size="sm">{invitation.roleLabel}</Badge>
                <span class="text-caption text-text-secondary">期限 {invitation.expiresAt}</span>
                <Button
                    onclick={() => accept(invitation)}
                    loading={acceptingId === invitation.id}
                    testId={`accept-invitation-${invitation.id}`}
                >
                    参加する
                </Button>
            </li>
            {/each}
        </ul>
    </Card>
{/if}
```

> 使用クラスはすべて既存の DS token / utility（`text-h3` `text-body` `text-caption`
> `text-text` `text-text-secondary` `bg-primary-soft` `text-primary` `divide-border`
> `rounded-md`）で、`NotificationListItem.svelte` / `Admin/Users.svelte` が既に使っている語彙に揃える。
> hex 直書き・独自 radius・新規 SVG は導入しない。`resources/css/tokens.css` の変更も無い。

> **Atomic Design 上の位置**: `features/invitations/` に置く（domain 固有の操作を持つため
> molecule ではない）。使う側は `pages/Notifications/Index.svelte`（pages → features は順方向）。
> import するのは `atoms/`（Card / Button / Badge）と `@inertiajs/svelte` / `@lucide/svelte` のみで、
> 他 domain の `features/` を横参照しない（`atomic-import-graph.test.ts` の単方向規約）。

`Notifications/Index.svelte` は Props に `pendingInvitations: PendingInvitation[]` を足し、
`PageContent` の先頭で `<PendingInvitationList invitations={pendingInvitations} />` を描画する。

### PHPStan 適合チェック

- [x] 戻り値の型が明示されている（`Response` / `RedirectResponse`）
- [x] null 安全（`authedUser()` が `User` を保証。`pendingInvitationCountFor` は `?User` を受ける）
- [x] DTO を返している（`PendingInvitationForUserDto::toArray()` の shape で Inertia props へ）
- [x] Generics の型パラメータが正しい（`array_map` のコールバック引数型を明示）
- `NotificationController` のコンストラクタに Service を 1 つ追加する（既存の DI 流儀のまま）

### テスト計画

`tests/Feature/Notifications/NotificationCenterTest.php`（既存の更新）

- [ ] 既存 `InvitationReceived の open は info flash で一覧へ戻す` を書き換え:
      **有効な招待があるとき** → 303 `notifications.index` かつ `info` flash **なし**
- [ ] 新規: **有効な招待が無いとき** → 303 かつ
      `info`「現在有効な招待はありません...」（取消済み招待を作って検証）
- [ ] 新規: `通知一覧の props に pendingInvitations が載る` — `assertInertia` で
      `pendingInvitations.0.organizationName` / `roleLabel` / `expiresAt` を検証し、
      `pendingInvitations.0` に `email` キーが `missing` であること

`tests/js/components/features/invitations/PendingInvitationList.test.ts`（新規）

- [ ] `招待 0 件では何も描画しない`
- [ ] `組織名・ロール・期限・参加ボタンを描画する`
- [ ] `初期描画では参加ボタンが disabled 属性を持たない`（禁止事項 8 の回帰封じ。
      **in-flight 中の disabled は対象外** — 二重送信防止であって必須条件未充足による
      無効化ではなく、既存の `loading={inviteForm.processing}` と同じ流儀）
- [ ] `参加ボタン押下で POST /invitations/{id}/accept-in-app を送る`（`router.post` を vi.mock）
- [ ] `in-flight 中の 2 回目の押下は送信しない`（`router.post` の呼び出し回数が 1）

`tests/js/components/features/NotificationListItem.test.ts`（既存の更新）

- [ ] 招待通知の本文が「メールの受諾リンクから参加してください」でなくなったことを反映

### リスク

- `NotificationController::open()` に `pendingInvitationCountFor` のクエリが 1 本増える。
  open は低頻度の POST なので実害はない。
- 既存 `NotificationCenterTest` の招待分岐アサーションが割れる（想定済み。上で更新する）。
- `Notifications/Index` に招待セクションを置くのは「通知 = 気づき」「一覧 = 行動」を
  1 画面に収める判断。新しい GET route を作らないことで `DocumentTitleCoverageTest` /
  S3 throttle の追加対応を避けている（今必要なものだけ作る）。

---

## 施策 6: 横断の気づき（共有 prop + notice）

### 変更箇所

- `app/Http/Middleware/HandleInertiaRequests.php`（`share()` に `invitations` を追加）
- `resources/js/lib/shared-props.ts`（`SharedProps` に `invitations`）
- `resources/js/components/molecules/PendingInvitationsNotice.svelte`（新規）
- `resources/js/components/templates/AppLayout.svelte`（notice の設置）

### 波及変更

- TypeScript 型定義: `resources/js/types/invitation.ts` の `InvitationSharedProps`（施策 5 で定義）
- API Resource/DTO: なし（scalar 1 つ）
- テストファイル: `tests/Feature/Invitations/PendingInvitationSharedPropTest.php`（新規）/
  `tests/js/components/molecules/PendingInvitationsNotice.test.ts`（新規）

### 現行コード

```php
            // 通知センターの未読数 (全 org 横断・自分宛のみ)。closure = Inertia partial reload で
            // 省略可能 (将来の router.reload({ only: ['notifications'] }) ポーリング拡張にも使える)
            'notifications' => [
                'unreadCount' => fn (): int => $user === null ? 0 : $user->unreadNotifications()->count(),
            ],
```

### 変更後コード

```php
            'notifications' => [
                'unreadCount' => fn (): int => $user === null ? 0 : $user->unreadNotifications()->count(),
            ],
            // 自分宛の受諾可能な招待の件数 (全画面横断の気づき。裁定 AG-113 必須要素 (b)(c))。
            // ★件数は受諾の解決・一覧と**同一 scope** から算出する
            //   (ずれると「件数は出るのに受諾できない」が起きる)。
            // ★未ログイン・未 verified・email 空は pendingInvitationCountFor が
            //   DB を一切引かずに 0 を返す (全リクエストで評価されるため実効的な負荷契約)。
            'invitations' => [
                'pendingCount' => fn (): int => app(OrganizationMembershipService::class)
                    ->pendingInvitationCountFor($user),
            ],
```

> `app()` 解決にするのは、`HandleInertiaRequests` のコンストラクタ注入を増やさないため
> （既存 `contact` prop が `app(ContactUrl::class)` を closure 内で解決している同じ流儀に合わせる）。

`resources/js/components/molecules/PendingInvitationsNotice.svelte`（新規）:

```svelte
<script lang="ts">
    import { Link } from "@inertiajs/svelte";
    import { Mail } from "@lucide/svelte";

    /**
     * 自分宛の保留中招待の件数だけを出す誘導専用 notice (受諾 UI は持たない)。
     * 受諾は /notifications の「届いている招待」から行う。
     * 件数は shared props invitations.pendingCount (親が渡す)。
     *
     * molecule に置くのは、atom (Link + Lucide icon) の組合せだけで状態も domain 操作も
     * 持たないため (NotificationBell と同じ位置づけ)。
     * ★DS: 色 / radius / typography は token 経由のみ。SVG 直書きを新設しない。
     */
    interface Props {
        pendingCount: number;
        testId?: string;
    }

    let { pendingCount, testId = "pending-invitations-notice" }: Props = $props();
</script>

{#if pendingCount > 0}
    <Link
        href="/notifications"
        class="flex items-center gap-2 rounded-md border border-border bg-primary-soft/40
            px-4 py-2 text-body text-text hover:bg-primary-soft"
        data-testid={testId}
    >
        <Mail class="size-4 text-primary" aria-hidden="true" />
        あなた宛の招待が {pendingCount} 件あります
    </Link>
{/if}
```

`AppLayout.svelte` は `const pendingInvitationCount = $derived(shared.invitations?.pendingCount ?? 0);`
を足し、既存 `NotificationBell` と同じ header 領域の直下（main の先頭）に
`<PendingInvitationsNotice pendingCount={pendingInvitationCount} />` を置く。

### PHPStan 適合チェック

- [x] 戻り値の型が明示されている（closure に `: int`）
- [x] null 安全（`$user` は `?User` に narrow 済み。`pendingInvitationCountFor(?User)` が受ける）
- [x] DTO を返している（scalar 1 つのため該当なし。配列生値の露出を増やさない）
- [x] Generics の型パラメータが正しい（該当なし）

### テスト計画

`tests/Feature/Invitations/PendingInvitationSharedPropTest.php`（新規）

- [ ] `未ログインのページでは pendingCount が 0 で DB を引かない` — guest でアクセス可能な
      Inertia 画面（`login`）で `assertInertia(fn ($page) => $page->where('invitations.pendingCount', 0))`
      かつ `DB::listen` で `organization_invitations` を含む SQL が 0 件
- [ ] `未 verified では 0 で DB を引かない`
- [ ] `verified かつ自分宛 active 招待 2 件で pendingCount = 2`（**負のコントロール**:
      guard が常に 0 を返す退行を検出する）
- [ ] `他人宛の招待は数えない`
- [ ] `件数と一覧が一致する` — 同一ユーザーで `/notifications` の `pendingInvitations` の件数と
      shared prop の `pendingCount` が一致（**scope 再利用の behavioral proof**）

`tests/js/components/molecules/PendingInvitationsNotice.test.ts`（新規）

- [ ] `pendingCount=0 では描画しない`
- [ ] `pendingCount=3 で件数と /notifications への link を描画する`
- [ ] `disabled 属性を持たない`

### リスク

- **全 Inertia レスポンスでクエリが 1 本増える**（verified かつ email 非空のユーザーのみ）。
  `organization_invitations` は組織あたり数件規模、`whereBlind` は
  `blind_indexes` の index を使う完全一致で、`whereHas('organization')` も PK 参照。
  実測が必要なほどのコストではないが、**closure 共有 prop なので Inertia の partial reload
  （`only:` 指定）では評価されない**ため、実効頻度はフルページ遷移時のみ。
- 未ログイン画面にも `invitations` prop が出る（値は常に 0）。情報漏洩ではない。

---

## 施策 7: 役割付き招待（`project_role`）の撤去

> 裁定 AG-079（オーナー判断: Default Project という概念自体が不要）。
> 本作業単位では**招待が `project_role` を持つのをやめる**ところまでを扱う。
> `DefaultProjectResolver` / `applyConsoleRole` の pivot 経路 / `MemberRoleState` の
> Editor・Shooter は dashboard・撮影 PWA・ユーザー管理画面が現に使っており、撤去はスコープ外。

### 変更箇所

- `database/migrations/2026_08_07_210000_drop_project_role_from_organization_invitations_table.php`（新規）
- `app/Models/OrganizationInvitation.php`（`casts()` / class docblock の `@property-read`）
- `app/Services/Organization/OrganizationMembershipService.php`（`inviteMember` / `joinOrganization`）
- `app/Http/Requests/Organizations/StoreOrganizationInvitationRequest.php`
- `app/DataTransferObjects/Admin/InvitationRowData.php`
- `database/factories/OrganizationInvitationFactory.php`
- `resources/js/pages/Admin/Users.svelte` / `resources/js/types/admin.ts`

### 波及変更

- TypeScript 型定義: `types/admin.ts` の `InvitationRow.roleState`（`MemberRoleState`）→
  `role` / `roleLabel`。`Admin/Users.svelte` の招待フォームの選択肢
- API Resource/DTO: `InvitationRowData`（管理者視点）
- テストファイル: `tests/Feature/Organization/InvitationTest.php`（L494-650 の
  「3 値ロールコマンド招待」ブロック）/ `tests/Feature/Admin/UserManagementPageTest.php`（L19, L140）/
  `tests/Feature/Notifications/InvitationNotificationTest.php`（`AdminConsoleRole::Admin` の呼び出し）/
  `tests/js/pages/Admin/Users.test.ts`（存在すれば）

### 現行コード

```php
// Model
    protected function casts(): array
    {
        return [
            // 受諾時に Default Project へ付与する pivot ロール (null = org 参加のみ)。
            'project_role' => ProjectRole::class,
            'expires_at' => 'datetime',
            // ...
        ];
    }
```

```php
// Service::inviteMember
    public function inviteMember(Organization $organization, User $invitedBy, string $email, AdminConsoleRole $role): OrganizationInvitation
    {
        // ...
        if ($role->projectRole() !== null && $this->defaultProjects->resolve($organization) === null) {
            throw ValidationException::withMessages([
                'role' => ['編集者・撮影者を招待するには、先にプロジェクトを作成してください。'],
            ]);
        }
        // ...
        $invitation->forceFill([
            'role' => $role->organizationRole()->value,
            'project_role' => $role->projectRole()?->value,
            // ...
        ]);
```

```php
// FormRequest
            'role' => ['required', 'string', Rule::enum(AdminConsoleRole::class)],
```

```php
// InvitationRowData
        $state = MemberRoleState::derive(
            OrganizationRole::from($invitation->role),
            $invitation->project_role,
        );
```

### 変更後コード

**migration**（`add_project_role_...` の `down()` と同内容を `up()` に置く）:

```php
return new class extends Migration
{
    /**
     * 役割付き招待の撤去 (裁定 AG-079。Default Project という概念自体が不要という
     * オーナー判断の帰結)。招待は「組織に入れる」ことだけを意味するようになり、
     * 編集者 / 撮影者の割当は参加後に管理画面のロール割当コマンドで行う。
     */
    public function up(): void
    {
        DB::statement('alter table organization_invitations drop constraint if exists organization_invitations_project_role_check');
        Schema::table('organization_invitations', function (Blueprint $table): void {
            $table->dropColumn('project_role');
        });
    }

    public function down(): void
    {
        Schema::table('organization_invitations', function (Blueprint $table): void {
            $table->string('project_role')->nullable()->after('role');
        });
        DB::statement(
            'alter table organization_invitations add constraint organization_invitations_project_role_check '
            ."check (project_role is null or project_role in ('project_admin', 'project_member'))",
        );
    }
};
```

**Model**: `casts()` から `'project_role' => ProjectRole::class` を削除、
class docblock の `@property-read ProjectRole|null $project_role` を削除、
`use App\Enums\ProjectRole;` を削除。

**Service**:

```php
    /**
     * メンバー招待。招待レコード生成 + 受諾 URL 付きメール送信。
     * ロールは**組織ロール 2 値 (管理者 / メンバー)**。Owner は招待で付与できない
     * (Owner 昇格は transferOwnership のみという不変条件の型表現)。
     * 編集者 / 撮影者 (Default Project の pivot ロール) は参加後に applyConsoleRole で割り当てる
     * (裁定 AG-079 で役割付き招待を撤去したため)。
     *
     * @throws ValidationException 既存メンバー / 有効な既存招待 (中立メッセージ)
     */
    public function inviteMember(Organization $organization, User $invitedBy, string $email, OrganizationRole $role): OrganizationInvitation
    {
        // Owner は FormRequest の Rule::enum(...)->except() で構造的に弾かれるが、
        // Service を直接呼ぶ経路 (テスト・将来のバッチ) でも不変条件を守る
        Assert::notSame($role, OrganizationRole::Owner, 'Owner は招待で付与できない');

        if ($this->emailBelongsToMember($organization, $email) || $this->hasPendingInvitation($organization, $email)) {
            throw ValidationException::withMessages([
                'email' => ['このメールアドレスには招待を送信できません。'],
            ]);
        }

        $plainToken = OrganizationInvitation::generateToken();

        $invitation = new OrganizationInvitation(['email' => $email]);
        $invitation->organization()->associate($organization);
        $invitation->invitedBy()->associate($invitedBy);
        // role / token_hash / expires_at は明示代入 (mass-assignment させない)
        $invitation->forceFill([
            'role' => $role->value,
            'token_hash' => OrganizationInvitation::hashToken($plainToken),
            'expires_at' => now()->addDays(self::EXPIRES_DAYS),
        ]);
        $invitation->save();

        // ... 以降のメール送信 / 通知発火は現行のまま ...
    }
```

`joinOrganization()` からは `$projectRole` の分岐ごと削除（施策 3 の変更後コードに反映済み）。
`DefaultProjectResolver` の DI は `applyConsoleRole` / `changeRole` が使い続けるため残す。

**FormRequest**:

```php
            // Owner は招待で付与できない (Owner 昇格は transferOwnership のみ)
            'role' => ['required', 'string', Rule::enum(OrganizationRole::class)->except([OrganizationRole::Owner])],
```

型付きアクセサも `role(): OrganizationRole` へ変更（`$this->enum('role', OrganizationRole::class)`）。
`messages()` の旧契約値に関するメッセージはそのまま残す（デプロイ跨ぎタブの回復導線）。

**InvitationRowData**:

```php
final readonly class InvitationRowData
{
    public function __construct(
        public int $id,
        public string $email,
        public string $role,      // OrganizationRole value
        public string $roleLabel, // OrganizationRole label
        public string $expiresAt, // Y-m-d
    ) {}

    public static function fromInvitation(OrganizationInvitation $invitation): self
    {
        // 招待は org ロールだけを持つ (役割付き招待は AG-079 で撤去)。
        // MemberRoleState (受諾後の 5 値表示状態) は使わない — 招待中の行を「未割当」と
        // 表示するのは意味的に誤り (割当漏れではなく、まだ参加していないだけ)。
        $role = OrganizationRole::from($invitation->role);
        // ...
        return new self(
            id: $invitation->id,
            email: $invitation->email,
            role: $role->value,
            roleLabel: $role->label(),
            expiresAt: $expiresAt->toDateString(),
        );
    }
}
```

**Factory**: `editorInvitation()` / `shooterInvitation()` を削除、`use App\Enums\ProjectRole;` を削除。
`asAdmin()` は維持。

**front**: `Admin/Users.svelte` に招待専用の選択肢を持たせる（メンバーのロール変更用
`ROLE_OPTIONS` は 3 値のまま）:

```ts
    /** 招待のロール = org ロール 2 値 (編集者/撮影者は参加後に割り当てる) */
    const INVITE_ROLE_OPTIONS: { value: string; label: string }[] = [
        { value: "organization_admin", label: "管理者" },
        { value: "organization_member", label: "メンバー" },
    ];
    const inviteForm = useForm({ email: "", role: "organization_member" });
```

招待一覧の行は `{invitation.roleLabel} ・ 期限 {invitation.expiresAt}` のまま（型だけ変わる）。
`PageHeader` の description と `hasDefaultProject` の注意書きは
「編集者・撮影者は**参加後に**割り当てます」の趣旨へ更新する（招待時の前提ではなくなるため）。
`types/admin.ts` の `InvitationRow` は `roleState: MemberRoleState` → `role: string; roleLabel: string`。

### デプロイ手順（expand/contract の contract 側。**順序が安全境界**）

列 drop は破壊的変更のため、ローリングデプロイ中の**旧プロセスと新 schema の同居**を避ける。

| 段階 | やること | なぜ |
|---|---|---|
| 1 | **コードを先にデプロイ**（`project_role` を読み書きしないコード） | 旧コードの `inviteMember` は `forceFill(['project_role' => ...])` を実行するため、列が先に消えると**存在しない列への INSERT で 500** になる（read 側は属性が欠落するだけで null 相当に落ちるので 500 にはならない = 壊れるのは書き込み側） |
| 2 | **旧プロセスが残っていないことを確認**（`php artisan queue:restart` 済み / web worker の入れ替え完了） | queue worker も `OrganizationMembershipService` を持つ |
| 3 | **migration を流す**（列 + check 制約の drop） | ここで初めて schema を縮める |

- **rollback**: `down()` で列と check 制約は復元できるが、**値は復元できない**。
  値を失った pending 招待は「参加後に管理画面でロールを割り当てる」運用に倒れるだけで、
  **参加自体は成功する**（`joinOrganization` は org 参加とロール付与だけを行う）。
- `php artisan route:cache` は毎デプロイ再生成する（AGENTS.md ドメイン規約 5。
  新 route の throttle 後付けが cached 起動で skip されるのを防ぐ）。

### PHPStan 適合チェック

- [x] 戻り値の型が明示されている（`OrganizationInvitation` / `self` / `OrganizationRole`）
- [x] null 安全（`Assert::notSame` / `Assert::isInstanceOf`。`project_role` の nullable 分岐が消える）
- [x] DTO を返している（`InvitationRowData`）
- [x] Generics の型パラメータが正しい（該当なし）
- `Rule::enum()->except()` は Laravel 11+ の API。`Illuminate\Validation\Rules\Enum` を返すため
  `array<string, list<mixed>>` の型に収まる

### テスト計画

`tests/Feature/Organization/InvitationTest.php`（既存の**削除と置換**）

- [ ] 削除: `editor 招待の受諾で Default Project へ project_admin pivot が attach される`
- [ ] 削除: `shooter 招待の受諾で Default Project へ project_member pivot が attach される`
- [ ] 削除: `editor/shooter 招待の送信は Default Project 不在なら error bag (role)`
- [ ] 削除: `受諾時に project が消えていた場合は org 参加のみ = 未割当に落ちる`
- [ ] 削除: `旧招待互換: project_role = null の既存行の受諾は従来どおり org 参加のみ`
      （`project_role` 自体が無くなるため「旧招待互換」という概念が消える）
- [ ] 削除: `register 経路でも project_role 付き招待の受諾で pivot が attach される`
- [ ] 追加: `招待の受諾は org 参加のみで Default Project の pivot を作らない` —
      project がある組織へ member 招待 → 受諾 → `$project->memberRole($invitee)` が null
- [ ] 追加: `招待は Default Project が無くても送信できる` — project 0 件の組織で 302 + success
      （撤去で消えた前提検査の回帰封じ）
- [ ] 追加: `role に organization_owner を送ると 422` — Owner を招待で付与できない
- [ ] 更新: `inviteAndCaptureToken()` helper の第 4 引数を `OrganizationRole` へ
- [ ] 既存の admin 招待 / 受諾 / 失効 / 重複中立メッセージ系テストは**挙動不変**であることを確認

`tests/Feature/Admin/UserManagementPageTest.php`（既存の更新）

- [ ] L19 の `editorInvitation()` を `->create(['role' => OrganizationRole::Member->value])` へ
- [ ] `invitations.0.roleState` のアサーションを `invitations.0.role` / `roleLabel` へ
- [ ] L140 の「旧招待 (project_role なし) は未割当語彙で表示される」を
      「招待は org ロールで表示される (メンバー)」へ置換

`tests/Feature/Notifications/InvitationNotificationTest.php`（既存の更新）

- [ ] `AdminConsoleRole::Admin` → `OrganizationRole::Admin`

`tests/Unit/Enums/MemberRoleStateTest.php`

- [ ] **変更しない**（`AdminConsoleRole` はメンバーのロール変更コマンドとして存続する）

DB 側（**実行先は worktree のテスト DB / CI DB に限定する**。dev DB への適用は
通常のデプロイ手順、またはユーザーの明示承認による = 禁止事項 3）:

- [ ] テスト DB で migration が通ること（`composer test` が `RefreshDatabase` で毎回流す）
- [ ] `down()` で列と check 制約が復元できること（rollback 手順の確認。テスト DB で
      `migrate:rollback --step=1` → `migrate` を 1 往復させる）
- [ ] **dev DB に対して `migrate` / `migrate:fresh` をエージェント判断で実行しない**

### リスク

- **仕様変更（受け入れる後退）**: 招待時に「編集者 / 撮影者」を選べなくなる。
  参加後にユーザー管理画面で「未割当」として可視化され、既存のロール割当コマンドで
  編集者 / 撮影者へ遷移させる 2 段の運用になる。3 値のまま「選べるが効かない」UI を残す方が
  有害（思考原則 3）。この変更は `docs/template-divergence.md` D8 に記録する。
- **デプロイ跨ぎタブ**: 旧 UI が `role=editor` を POST してくると 422 になる。
  `StoreOrganizationInvitationRequest::messages()` の既存メッセージ
  「ロールの指定が不正です。画面を再読み込みしてやり直してください。」がそのまま回復導線になる。
- **列 drop は不可逆**（`down()` で列は戻るが値は戻らない）。
  既存の pending 招待の `project_role` 値は失われる。値を持つ pending 招待は
  「参加後に管理画面で割り当てる」運用に倒れるだけで、参加自体は成功する。

---

## 施策 8: 目録型 gate（受信者視点解決の deny-by-default）

### 変更箇所

- `app/Enums/Security/InvitationResolutionScope.php`（新規）
- `tests/Architecture/InvitationResolutionInventoryTest.php`（新規）

### 波及変更

- TypeScript 型定義: なし
- API Resource/DTO: なし
- テストファイル: 本施策が新設するもののみ

### 現行コード

（新規。見本は `tests/Architecture/ThrottleCoverageInventoryTest.php` /
`tests/Architecture/QueuedJobLeaseInventoryTest.php` /
`tests/Architecture/BillingGatewayFailureTaxonomyInventoryTest.php`）

### 変更後コード

```php
<?php

declare(strict_types=1);

namespace App\Enums\Security;

/**
 * OrganizationInvitation を解決する経路の分類（存在秘匿の視点別）。
 *
 * 招待は「誰の視点から引くか」で開示していい情報が変わる。視点を混ぜると
 * 受信者向けの経路が管理者向けの絞り込みを使ってしまい、他人宛の招待に到達できる
 * = 存在オラクルになる。**例外機構は設けない**（分類外は fail）。
 */
enum InvitationResolutionScope: string
{
    /**
     * 受信者視点。認証ユーザー宛の有効 pending 集合
     * (OrganizationInvitation::scopeActivePendingForEmail) からのみ解決する。
     * 不成立はすべて 0 件 = 呼び出し側は一律 404 に畳める。
     */
    case RecipientScopedPendingSet = 'recipient_scoped_pending_set';

    /** 平文 token の sha256 照合で解決する（署名なし token URL 経路）。 */
    case TokenHashLookup = 'token_hash_lookup';

    /** 管理者視点。$organization->invitations() の relation 経由でのみ解決する。 */
    case OrganizationScoped = 'organization_scoped';

    /** モデル自身が持つ解決口 / scope の定義そのもの。 */
    case ModelInternal = 'model_internal';
}
```

```php
<?php

declare(strict_types=1);

use App\Enums\Security\InvitationResolutionScope;

/*
 * OrganizationInvitation のクエリ起点の deny-by-default 目録。
 *
 * 守る不変条件（裁定 AG-113 の必須要素 (b)）:
 *   「**受信者視点の解決・一覧・件数は、必ず scopeActivePendingForEmail の 1 本を再利用する**」
 * これがずれると「件数は出るのに受諾できない」が起き、さらに悪いことに、
 * 受信者向け経路が別の絞り込みで招待に到達すると**他人宛の招待に届く存在オラクル**になる。
 *
 * 本テストは app/ 配下で OrganizationInvitation のクエリを起点する箇所を機械抽出し、
 * 4 分類のいずれかへの登録を要求する（未登録は fail / 実在しない登録も fail）。
 * さらに RecipientScopedPendingSet に分類した箇所は、**本文に activePendingForEmail(
 * が現れること**を要求する（分類したのに別の絞り込みを書く退行の検出）。
 *
 * ★空振り対策を 3 つ持つ:
 *   (1) 母集団 floor — 抽出が 0 件 / 縮小したら fail（セレクタの空振り検出）
 *   (2) RecipientScopedPendingSet の exact-fit cap — 現在値ちょうど。
 *       受信者視点の解決口を増やす差分は必ずこの数値を変える形で現れ、再レビューを強制する
 *   (3) 負のコントロール — 分類済み各 case が 1 件以上存在すること（分類の死に枝を作らない）
 */
```

検査の骨子（`ThrottleCoverageInventoryTest` の構成に倣う）:

```php
/** 目録キー = "{app/ からの相対パス}#{メソッド名}"。 */
function invitationResolutionInventory(): array
{
    $recipient = InvitationResolutionScope::RecipientScopedPendingSet;
    $token = InvitationResolutionScope::TokenHashLookup;
    $orgScoped = InvitationResolutionScope::OrganizationScoped;
    $model = InvitationResolutionScope::ModelInternal;

    return [
        'Models/OrganizationInvitation.php#findActiveByPlainToken' => [$model,
            '平文 token の sha256 照合による active 解決の単一口。email での絞り込みを持たない'
            .'（列挙面を広げない）。MatchesInvitationEmail / acceptInvitationIfValid / '
            .'register prefill が共有する。'],
        'Models/OrganizationInvitation.php#scopeActivePendingForEmail' => [$model,
            '受信者視点の絞り込みの定義そのもの。active + blind index 完全一致 + 組織実在の'
            .'3 条件がすべて 0 件へ collapse することが、一律 404 に畳める根拠。'],
        'Services/Organization/OrganizationMembershipService.php#pendingInvitationsQuery' => [$recipient,
            '受信者視点の唯一のクエリ起点。未ログイン・未 verified・email 空は null を返し'
            .'DB を引かない。一覧・件数・受諾解決がすべてここを通る。'],
        'Services/Organization/OrganizationMembershipService.php#acceptInvitation' => [$token,
            'POST token 受諾。token_hash 照合で解決し、失効/期限/受諾済みを個別メッセージに'
            .'出し分ける（token 保持者向けの既存契約）。'],
        'Services/Organization/OrganizationMembershipService.php#hasPendingInvitation' => [$orgScoped,
            '管理者視点。$organization->invitations() 経由で同一組織内の重複招待だけを見る'
            .'（中立メッセージのための存在判定であり受信者視点ではない）。'],
        // ... 実装時に抽出結果へ合わせて確定する ...
    ];
}
```

- **母集団の抽出**: `app/` 配下の PHP を走査し、
  `OrganizationInvitation::query(` / `OrganizationInvitation::where` /
  `OrganizationInvitation::find` / `->invitations()` / `activePendingForEmail(` の
  いずれかを含む**メソッド**を、`ReflectionClass` の
  `getStartLine()`/`getEndLine()` でメソッド本文へ切り分けて列挙する
  （`MembershipWriteLockInventoryTest` の `bodyOf()` と同じ手法）。
- **stale 検出**: 目録キーのうち抽出結果に現れないものは fail。
- **理由の最低文字数**: 30 文字（`invitationResolutionReasonMinLength()`）。
- **floor**: `invitationResolutionSiteFloor()` = 実測値（実装時に確定。目安 6）。
- **exact-fit cap**: `RecipientScopedPendingSet` の件数は**現在値ちょうど**（= 1）。

### PHPStan 適合チェック

- [x] 戻り値の型が明示されている（enum のメソッドなし。テストの helper 関数も戻り値型を明示）
- [x] null 安全（`ReflectionMethod::getStartLine()` の `false` を分岐で処理）
- [x] DTO を返している（該当なし）
- [x] Generics の型パラメータが正しい（`array<string, array{InvitationResolutionScope, string}>`）

### テスト計画（gate 自身の検証 = mutation で赤化を確認する手順）

**素の main では常に green の gate であるため、実装時に以下の mutation を順に当てて
赤化を確認し、結果を `devnotes/{dir}/gate-mutation-log.md` に記録してから元へ戻す。**

| # | mutation | 期待する fail |
|---|---|---|
| M1 | `AcceptInvitationInAppController::__invoke` に `OrganizationInvitation::query()->whereKey($invitation)->first();` を一時的に足す | 「未登録のクエリ起点」で fail（deny-by-default が効いている） |
| M2 | `pendingInvitationsQuery()` の本文を `activePendingForEmail(...)` から `->active()->whereBlind(...)` の手書きへ置換 | `RecipientScopedPendingSet` の本文検査で fail（**scope 再利用の強制**が効いている） |
| M3 | 目録から `pendingInvitationsQuery` の行を削除 | 未登録 fail |
| M4 | 目録に実在しないキー（`Services/Foo.php#bar`）を足す | stale fail |
| M5 | 理由文を `'短い'` に置換 | 理由 30 字未満で fail |
| M6 | `invitationResolutionSiteFloor()` を実測 +1 に上げる | floor 下回りで fail（**空振り検出が生きている**ことの確認） |
| M7 | `RecipientScopedPendingSet` 分類の 2 件目を目録に足す（実在サイトを作って） | exact-fit cap 超過で fail |

- [ ] gate 追加時点では**まだ本体が無い**ため、まず M1 相当の状態（= 施策 1・3 の実装前）で
      「目録が空でも floor で fail する」ことを確認する（テストファースト）
- [ ] 個別の `DatabaseTransactions` を使わない（Architecture レーンは DB を触らない）
- [ ] **実測値を devnotes に固定する**: `devnotes/{dir}/gate-mutation-log.md` に
      **(a) 初回の抽出結果（クエリ起点の全リスト。パス#メソッド名）**、
      **(b) 確定した `invitationResolutionSiteFloor()` の値**、
      **(c) `RecipientScopedPendingSet` の exact-fit cap の値** を記録し、
      M1〜M7 の赤化結果と併せてレビュー対象に含める
      （目録の中身そのものがレビューの対象であり、mutation ログだけでは不十分）

### リスク

- 抽出セレクタが正規表現ベースのため、`OrganizationInvitation` を変数経由で扱う書き方
  （`$model = OrganizationInvitation::class; $model::query()`）は検出できない。
  これは既存の `ModelDirectFetchInvariantTest` / `MembershipWriteLockInventoryTest` と
  同じ限界であり、**保証範囲を誇張しない**（テスト docblock に明記する）。
- 目録が育ちすぎると形骸化する。`RecipientScopedPendingSet` の exact-fit cap がその歯止め。

---

## 施策 9: ドキュメント更新

### 変更箇所

- `docs/architecture.md`（招待セクション）
- `docs/template-divergence.md`（D8）
- `docs/factories.md`（`OrganizationInvitationFactory` の state 一覧）

### 波及変更

- テストファイル: なし（`verification-commands-doc-sync` 等の同期テストは対象外）

### 変更後コード（要点）

`docs/architecture.md` に「招待受諾の 2 経路」節を追加:

- 受諾経路が 2 本あること（署名なし token URL / アプリ内）と**受諾の根拠が違う**こと
- **`verified` の非対称は仕様**であること
  （token 経路は招待直後の未検証ユーザーも受諾できる / アプリ内経路は根拠そのものが
  email 確認済みのため必須）。片方だけ見て「不整合」と直さない
- **email 照合の非対称**（アプリ内経路は blind index の大小文字完全一致 /
  token 経路は token_hash 照合のため大小差の影響を受けない）
- 存在秘匿の畳み方（受信者視点の解決は `scopeActivePendingForEmail` 1 本。
  `InvitationResolutionInventoryTest` が deny-by-default で強制）
- 最終権威の表（組織 soft-delete = organizations 行ロック /
  取消・期限・並行受諾 = 招待行ロック / 並行 join = `insertOrIgnore`）
- **email を正規化保存していない**こと（`EmailNormalizer` は inquiry / billing contact 専用）と、
  その帰結（アプリ内受諾は大小差で 404 に倒れる / メール token 経路は影響を受けない /
  正規化するなら blind index 再計算を伴う別作業）
- 共有 prop `invitations.pendingCount` は closure のため
  **`only:` 指定の partial reload では評価されない**（件数はフルページ遷移時に更新される。
  受諾直後は dashboard へフル遷移するため実害はない）
- **デプロイ順序**（施策 7 の expand/contract）を `docs/architecture.md` からも参照できるようにする

`docs/template-divergence.md` D8:

- 「招待」行を「org ロールのみ（テンプレートと同じ）」へ戻し、
  **役割付き招待は裁定 AG-079 で撤去済み**と記録（差分そのものを削除せず、
  「一度逸脱し、裁定で戻した」経緯を残す）
- 「揃えている不変条件」から `project_role` の項目を削除
- ロール語彙の行に「招待は org ロール 2 値 / メンバーのロール変更は 3 値コマンド」の
  非対称を明記

`docs/factories.md`: `editorInvitation()` / `shooterInvitation()` の記述を削除。

### テスト計画

- [ ] `composer test` / `pnpm test` が green（doc 同期テストの対象外だが回帰確認）
- [ ] `docs/architecture.md` の追記が既存の目次構造と矛盾しないこと（目視）

### リスク

- なし（ドキュメントのみ）。ただし**書き忘れると次の実装者が非対称を「バグ」と誤認して直す**ため、
  施策 4・7 と同じ PR に必ず含める。

---

## 実装モード

| 項目 | 内容 |
|------|------|
| 推奨モード | **standalone** |
| 判断根拠 | (1) `app/Models/OrganizationInvitation.php` と `OrganizationMembershipService.php` を**構造的に**変更する（scope 追加 / 公開メソッド 4 本追加 / 共有コアの戻り値型変更 / `project_role` 撤去）。他 TODO が同ファイルに触ると衝突が確実。(2) DB migration（列 drop）を含むため、他の worktree と DB 状態を共有できない。(3) Architecture gate を 6 本触る（うち 5 本は inventory 登録）ため、他施策の route 追加と同じ inventory ファイルで機械的に衝突する。(4) front も `AppLayout` / `shared-props.ts` という全画面共有の面に触る。 |
| 競合リスク | **高**。`routes/web.php` / `tests/Support/Routing/NestedRouteDefenseInventory.php` / `tests/Architecture/ControllerAuthorizationGateTest.php` / `app/Providers/AppServiceProvider.php` は他の route 追加系 TODO と衝突しやすい。`resources/js/lib/shared-props.ts` と `AppLayout.svelte` は front 系 TODO と衝突しやすい。**並列実装しない**こと。 |
| 実装順序 | 8（gate + mutation 確認）→ 1 → 2 → 3 → 4 → 7 → 5 → 6 → 9。各段で `composer test --filter` の対象テストを先に赤くしてから実装に入る（思考原則 5） |
| 完了条件 | AGENTS.md の `VERIFICATION_COMMANDS` **全部**が green: `composer test` / `composer phpstan` / `vendor/bin/pint --test` / `pnpm lint` / `pnpm typecheck` / `pnpm test` / `pnpm build` / `pnpm typecheck:packages` / `pnpm build:packages` / `pnpm test:packages`。加えて `devnotes/{dir}/gate-mutation-log.md` に **(a) 施策 8 の抽出結果全リスト / (b) floor 実測値 / (c) exact-fit cap 実測値 / (d) mutation M1〜M7 の赤化結果 / (e) 戻り値消費トークナイザ検査の負のコントロール結果** が残っていること |
