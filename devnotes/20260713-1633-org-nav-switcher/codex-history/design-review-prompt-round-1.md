【アプリの使命（North Star）】

AI-CUE は、現場に既にある作業手順書(SOP)を起点に、AI が撮るべきカットを設計した動画シナリオを生成し、そのシナリオをスマホ(PWA)でナビゲーション撮影することで、専門知識ゼロの現場作業者でも標準化されたマニュアル動画を作れるようにする。「思考ゼロ・編集ゼロ」。標準作業を起点に AI が教材設計し撮影を指示する。v1 は字幕のみ / 撮影は PWA(同一オリジン・セッション認証) / 動画合成は自前 ffmpeg / 単一 Default Project。

【禁止事項】
1. テストなしの実装完了報告(Architecture/Feature テスト登録まで含めて実装済み)
2. PHPStan エラーの widen・baseline 化
3. dev DB への破壊操作をエージェント判断で実行
4. response()->json() の直書き(DTO / JsonResource / Inertia を使う。仕様固定 endpoint のみ例外)
5. LLM 呼び出しの Prism 直呼び
6. prompt 文字列のコード直書き
7. 操作系 POST の応答での redirect()->intended()
8. 必須条件未充足を理由にボタンを disabled にする UI(押下時にエラー表示)

【セキュリティ不変条件(関連分)】cross-org 不可(組織を跨ぐ read/write をしない)。権限判定は常に laratrust_team_id を明示(strict_check=true)。tenant キー不信。

【思考原則】まず仮説を立てろ。データに真摯に向き合え。先人の知恵を探せ。機能の名前に立ち返れ。仕組みが機能していない段階で値を弄るな。

【ツール使用制限】コマンド実行・ファイル書き込みは一切行わず、提供されたテキストの分析に集中すること。ファイル読み込みは許可。

---

あなたは経験豊富なWebアプリケーションアーキテクトです。Laravel + Svelte アプリケーション改善の詳細設計をレビューしてください。

【前提環境】PHP 8.4 + Laravel 12 + Svelte 5 + Inertia.js + TypeScript / PHPStan level 10 / Pest / DTO + JsonResource パターン / Laratrust RBAC(Organization → Team → Project 階層)

【レビュー観点】
1. コードの正確性(ロジックエラー、エッジケース、null 安全性)
2. 既存コードとの整合性(命名規約、パターン、API)
3. PHPStan level 10 適合性(型安全性、generics、Assert 使用)
4. テスト計画の網羅性(各施策に Pest テスト、RefreshDatabase グローバル適用に従う)
5. DTO/JsonResource パターンの遵守
6. Inertia Props vs API Response の使い分け
7. 副作用・後退リスク
8. 波及変更の網羅性(TypeScript 型定義、API Resource、テストが変更対象に含まれているか)
9. セキュリティ(認可チェック、入力バリデーション、OWASP Top 10、セキュリティ不変条件)
10. DESIGN.md 準拠(design token 経由参照、hex 直書きを増やさないか)
11. Atomic Design 準拠(atoms/molecules/organisms/features/templates の責務分離・単方向 import、アイコンは Lucide 前提で SVG 直書きを新設していないか)

【出力形式】
- 各施策ごとに判定: APPROVE / REQUEST_CHANGES
- 指摘は [Critical] [Warning] [Suggestion] で分類
- Critical/Warning には必ず修正案を添える
- 全体判定: APPROVED / CHANGES_REQUESTED
- 日本語で出力

---

## 詳細設計書

（detailed-design.md 全文。以下に転記）

# 詳細設計: org-nav-switcher

## 使命・制約（絶対遵守）

### アプリの使命（North Star）

**AI-CUE** は、現場に既にある**作業手順書(SOP)を起点に**、AI が撮るべきカットを設計した**動画シナリオ**を生成し、そのシナリオを**スマホ(PWA)でナビゲーション撮影**することで、専門知識ゼロの現場作業者でも**標準化されたマニュアル動画**を作れるようにする。「思考ゼロ・編集ゼロ」。競合と異なり標準作業を起点に AI が教材設計し撮影を指示する。熟練者の暗黙知を動画マニュアル(形式知)へ変換する装置(SECI)。

本施策は教材生成そのものではないが、組織(現場)を跨いだ運用・招待・請求という**運用の背骨**の到達導線を回復し、「迷子にならず思考ゼロで使える」前提を満たす基盤改善である。

### 禁止事項（AGENTS.md 正本）

1. テストなしの実装完了報告（不変条件は Architecture/Feature テスト登録まで含めて「実装済み」）
2. PHPStan エラーの widen・baseline 化
3. dev DB への破壊操作をエージェント判断で実行
4. `response()->json()` の直書き（DTO / JsonResource / Inertia を使う）
5. LLM 呼び出しの Prism 直呼び
6. prompt 文字列のコード直書き
7. 操作系 POST の応答での `redirect()->intended()`
8. 必須条件未充足を理由にボタンを disabled にする UI（押下時にエラー表示）

### セキュリティ不変条件（関連分）

- **cross-org 不可**: 組織を跨ぐ read/write をしない。切替リストは id のみで cross-org slug を露出しない。
- **権限判定は常に `laratrust_team_id` を明示**（strict_check=true）。権限フラグは currentOrganization を対象に評価。

### コーディングルール

- **PHPStan level 10** 必須（`composer phpstan`）
- **Pest** + **RefreshDatabase** グローバル適用・`--parallel`（個別 `DatabaseTransactions` 禁止）
- テストデータは Factory / 既存ヘルパ（`createOrganizationWithOwner` / `attachOrganizationMember`）で生成
- **DTO + JsonResource** パターン（本施策は Inertia 共有 prop の array-shape で完結。新エンドポイントなし）
- フロントは Svelte 5 runes + DS token のみ（`DESIGN.md` canonical）。アイコンは `@lucide/svelte` のみ
- component 階層は単方向 import（`atoms → molecules → organisms → features/{domain} → templates → pages`）
- PHP 8.4 + Laravel 12 + Svelte 5 + Inertia.js + TypeScript
- コードフォーマット: `composer fix`（Pint）/ `pnpm lint:fix`

## 概念設計リファレンス

- [conceptual-design.md](./conceptual-design.md)（Codex `gpt-5.4` 合議 Round 4 で APPROVED）

## 施策一覧

| # | 施策名 | 変更ファイル | 優先度 |
|---|--------|------------|--------|
| S1 | 共有 prop `currentOrganization` に `slug` + 権限フラグ 2 種を追加 | `app/Http/Middleware/HandleInertiaRequests.php` | Critical |
| S2 | TS 型 `CurrentOrganization` を拡張（PHP array-shape と 1:1） | `resources/js/lib/shared-props.ts` | Critical |
| S3 | 組織スイッチャー兼組織メニューを新設 | `resources/js/components/features/organizations/OrganizationSwitcher.svelte`（新規） | Critical |
| S4 | AppLayout ヘッダーへスイッチャーを常設配置 | `resources/js/components/templates/AppLayout.svelte` | Critical |
| S5 | テスト（PHP Feature + JS component） | `tests/Feature/...`, `tests/js/...` | Critical |

新規ルート・新規コントローラは無し（`organizations.switch` / 各画面ルートは既存）。組織一覧専用 GET 画面は作らない（スイッチャーで一覧・切替が完結）。

---

## S1. 共有 prop `currentOrganization` に `slug` + 権限フラグを追加

### 変更箇所
- ファイル: `app/Http/Middleware/HandleInertiaRequests.php`（`currentOrganizationProp()` L112-124）

### 波及変更
- TypeScript型定義: S2（`CurrentOrganization` を拡張）
- API Resource/DTO: なし（Inertia 共有 prop の array-shape。新規 DTO は Inertia 共有には過剰）
- テストファイル: S5（共有 prop shape の Feature テスト）

### 現行コード
```php
/**
 * @return array{id: int, name: string, role: string|null}|null
 */
private function currentOrganizationProp(?User $user): ?array
{
    $organization = $user?->currentOrganization;
    if ($user === null || $organization === null) {
        return null;
    }

    return [
        'id' => $organization->id,
        'name' => $organization->name,
        'role' => $user->organizationRole($organization)?->value,
    ];
}
```

### 変更後コード
```php
/**
 * 現在の組織 + 自分のロール + ナビ表示に必要な最小権限フラグ。
 * 権限は currentOrganization ($organization) を対象に評価し、OrganizationPolicy を
 * 唯一の真実源とする (role 直見しない)。Policy は organizationRole($organization)
 * = laratrust_team_id を明示した strict_check 判定を経由するため、別組織で付与された
 * 権限は現在組織へ漏れない (cross-org 分離。S5 のテストで固定)。
 * slug は organizations.settings / organizations.api-keys.index ({organization:slug}
 * バインド) への恒常リンク生成に必須。
 *
 * @return array{
 *     id: int,
 *     name: string,
 *     slug: string,
 *     role: string|null,
 *     canManageMembers: bool,
 *     canManageApiKeys: bool
 * }|null
 */
private function currentOrganizationProp(?User $user): ?array
{
    $organization = $user?->currentOrganization;
    if ($user === null || $organization === null) {
        return null;
    }

    return [
        'id' => $organization->id,
        'name' => $organization->name,
        'slug' => $organization->slug,
        'role' => $user->organizationRole($organization)?->value,
        // ナビ表示用の最小権限 (settings/billing は view=メンバー全員のためフラグ不要)。
        // billing 画面内の操作出し分けは既存 canManageBilling prop が担うため shared には載せない。
        'canManageMembers' => $user->can('manageMembers', $organization),
        'canManageApiKeys' => $user->can('manageApiKeys', $organization),
    ];
}
```

### PHPStan適合チェック
- [x] 戻り値の型が明示されている（array-shape に slug/bool 2 種を追加）
- [x] null 安全（`$organization === null` の early return は既存どおり維持）
- [x] 配列返却だが Inertia 共有 prop の array-shape（DTO は不要と判断）
- [x] `$organization->slug` は `Organization` の string カラム（nullable でない）。`$user->can()` は bool

### テスト計画
- [x] 新規 Feature: 共有 prop に slug + 権限フラグが role 別に載る（S5-a）
- [x] cross-org: 別組織のみで manage-api-keys 直接付与された member は現在組織で `canManageApiKeys=false`

### リスク
- 各 share() で `manageMembers` / `manageApiKeys` の Gate 評価が 1 回ずつ増える。`organizationRole` は
  currentOrganizationProp が既に 1 度呼ぶ。追加 2 回の policy 評価は軽微（同一 request 内・DB は
  laratrust の team-scoped role/permission 参照）。認証済み画面のみで評価され、ゲスト時は null 早期リターン。

---

## S2. TS 型 `CurrentOrganization` を拡張

### 変更箇所
- ファイル: `resources/js/lib/shared-props.ts`（`CurrentOrganization` interface L23-28）

### 波及変更
- TypeScript型定義: 本施策そのもの
- 参照側: `OrganizationSwitcher.svelte`（S3）が新規に参照。既存参照（`Settings.svelte` 等は
  独自 Props を持ち shared の `CurrentOrganization` に依存しないため影響なし）

### 現行コード
```ts
export interface CurrentOrganization {
    id: number;
    name: string;
    /** OrganizationRole の value (organization_owner / organization_admin / organization_member) */
    role: string | null;
}
```

### 変更後コード
```ts
export interface CurrentOrganization {
    id: number;
    name: string;
    /** organizations.settings / api-keys.index ({organization:slug}) 用 */
    slug: string;
    /** OrganizationRole の value (organization_owner / organization_admin / organization_member) */
    role: string | null;
    /** メンバー管理 (/manage/users) 導線の表示可否 (owner/admin) */
    canManageMembers: boolean;
    /** API キー画面 (organizations.api-keys.index) 導線の表示可否 */
    canManageApiKeys: boolean;
}
```

### PHPStan適合チェック
- N/A（TypeScript。`pnpm typecheck` で検証）

### テスト計画
- [x] 型は S3 のコンポーネントテストと `pnpm typecheck` で実効検証

### リスク
- なし（追加のみ。既存 `OrganizationSummary`（organizations[] 用）は id/name/isPersonal のまま据え置き）

---

## S3. 組織スイッチャー兼組織メニュー（新規）

### 変更箇所
- ファイル（新規）: `resources/js/components/features/organizations/OrganizationSwitcher.svelte`

### 波及変更
- TypeScript型定義: S2 の `SharedProps` / `CurrentOrganization` / `OrganizationSummary` を参照
- API Resource/DTO: なし
- テストファイル: S5-c（component テスト新規）
- import グラフ: `templates(AppLayout) ← features/organizations/OrganizationSwitcher` は許可方向
  （`atomic-import-graph.test.ts` 準拠）。内部は atoms（`Button`）+ `@inertiajs/svelte`（`Link`/`router`）
  + `@lucide/svelte` を合成

### 設計仕様

**Props**（親 AppLayout から渡す。shared prop を親で読んで渡すことで純粋・テスト容易にする）:
```ts
interface Props {
    /** 現在の組織 (未所属/未設定時は null → 「組織を作成」フォールバック) */
    currentOrganization: CurrentOrganization | null;
    /** 所属組織一覧 (切替候補。id で switch) */
    organizations: OrganizationSummary[];
}
```

**表示ロジック**:
- トリガー button: 現在組織名（null なら「組織を選択」）+ `ChevronsUpDown` アイコン。
  `aria-expanded={open}` + `aria-controls="org-switcher-panel"`（**`aria-haspopup` は付けない** =
  disclosure セマンティクス）。`data-testid="org-switcher-trigger"`。
- 展開パネル（`open` 時のみ描画、`id="org-switcher-panel"`、**`role="menu"` は付けない**）:
  1. **切替セクション**（`organizations.length > 1` のときのみ）: 各組織を `<button>` で描画し
     `router.post(\`/organizations/${org.id}/switch\`)` を呼ぶ。現在組織（`org.id === currentOrganization?.id`）
     には `Check` アイコン表示（disabled にはしない = 禁止事項 8。押下しても同一組織で no-op 遷移）。
     `org.isPersonal` は `個人` ラベルを添える。`data-testid="org-switch-{id}"`。
  2. **管理リンクセクション**（`currentOrganization != null` のとき）: `@inertiajs/svelte` の `Link`（GET）:
     - 組織設定 `Link href={\`/organizations/${slug}/settings\`}`（`Settings` アイコン）— 常時（view=メンバー）
     - メンバー管理 `Link href="/manage/users"`（`Users` アイコン）— `canManageMembers` のときのみ
     - API キー `Link href={\`/organizations/${slug}/api-keys\`}`（`KeyRound` アイコン）— `canManageApiKeys` のときのみ
     - 請求 `Link href="/billing"`（`CreditCard` アイコン）— 常時（view=メンバー）
     - 料金 `Link href="/pricing"`（`Tag` アイコン）— 常時（公開）
  3. **フォールバック**（`currentOrganization == null`）: `Link href="/organizations/create"`「組織を作成」
     （`Plus` アイコン）。切替セクション・管理リンクは出さない。
- 各リンク/ボタン押下後はパネルを閉じる（`open = false`）。

**a11y / 状態管理（disclosure パターン, runes）**:
- `let open = $state(false)`。トリガー押下で toggle。
- **click-outside**: `$effect` で `open` が true の間だけ `document` に `pointerdown` リスナを張り、
  ルート要素（`bind:this`）外なら閉じる。`return () => removeEventListener(...)` でクリーンアップ。
- **Escape**: パネル/ルートの `onkeydown` で `event.key === 'Escape'` なら閉じてトリガーへ focus 復帰
  （トリガーを `bind:this` で保持し `.focus()`）。
- 項目間は **Tab で順次移動**（Link/button の自然な tab 順。矢印キーナビは実装しない）。

**DESIGN.md 準拠**: 色 `text-text` / `text-text-secondary` / `bg-surface` / `border-border` /
`hover:bg-neutral`、radius `rounded-md`、typography `text-body` / `text-caption` の DS token のみ。
hex 直書き・新規 token は追加しない。アイコンは `@lucide/svelte`（`ChevronsUpDown, Check, Settings,
Users, KeyRound, CreditCard, Tag, Plus`）。SVG 直書きは新設しない。

### PHPStan適合チェック
- N/A（Svelte/TS。`pnpm typecheck` / `pnpm lint` / ds-purity テストで検証）

### テスト計画
- [x] S5-c（component テスト）

### リスク
- click-outside の `$effect` クリーンアップ漏れは再レンダで多重リスナになるため、`open` 依存で
  張り/剥がしを厳密化（テストで Escape/outside 両方の閉動作を固定）。
- `router` を JS テストで mock（既存 `AppLayout.test.ts` の `vi.mock("@inertiajs/svelte")` パターン準拠）。

---

## S4. AppLayout へスイッチャーを常設配置

### 変更箇所
- ファイル: `resources/js/components/templates/AppLayout.svelte`（ヘッダー右側アクション群 L72-89、
  および「Phase 2」コメント L12-13）

### 波及変更
- TypeScript型定義: S2 参照（`shared.currentOrganization` / `shared.organizations`）
- テストファイル: S5-c（`AppLayout.test.ts` にスイッチャー描画の assertion を追加）

### 現行コード（抜粋）
```svelte
{#if showAccountNav}
    <NotificationBell unreadCount={shared.notifications?.unreadCount ?? 0} />
    <TextLink href="/settings" testId="nav-settings">設定</TextLink>
    <Button variant="ghost" size="sm" onclick={logout} loading={loggingOut} testId="nav-logout">
        ログアウト
    </Button>
{/if}
```

### 変更後コード（抜粋）
```svelte
{#if showAccountNav}
    <OrganizationSwitcher
        currentOrganization={shared.currentOrganization ?? null}
        organizations={shared.organizations ?? []}
    />
    <NotificationBell unreadCount={shared.notifications?.unreadCount ?? 0} />
    <TextLink href="/settings" testId="nav-settings">設定</TextLink>
    <Button variant="ghost" size="sm" onclick={logout} loading={loggingOut} testId="nav-logout">
        ログアウト
    </Button>
{/if}
```
- import 追加: `import OrganizationSwitcher from "@/components/features/organizations/OrganizationSwitcher.svelte";`
- L12-13 の「Phase 2 (組織・Team・Project 導入) でサイドバー・組織切替・通知センターを拡張する」コメントを
  「組織スイッチャー/組織メニューを常設（組織切替・組織設定/請求/招待/API キー導線）。サイドバー/Team/Project
  ナビは後続 Phase」に更新（陳腐化コメントを残さない）。

### PHPStan適合チェック
- N/A（Svelte/TS）

### テスト計画
- [x] `AppLayout.test.ts` に「ログイン中はスイッチャートリガーを常設」assertion を追加（S5-c）
- [x] 既存の設定/ログアウト/ベル常設テストは維持（削除・上書きしない）

### リスク
- ヘッダーは 375px 方針で `flex-wrap`。スイッチャートリガーを最左に足すことで折返し段が増える可能性
  → トリガーは `shrink-0` かつ簡潔なラベルにし、既存 wrap 挙動を壊さない（component テストは論理描画のみ、
  レイアウト崩れは実機/既存 responsive 方針の範囲）。

---

## S5. テスト

### S5-a. PHP Feature: 共有 prop shape + 権限フラグ（cross-org 分離）

新規: `tests/Feature/Organizations/OrganizationNavSharedPropsTest.php`

- 波及変更: なし（テスト追加のみ）
- ヘルパ: `createOrganizationWithOwner()` / `attachOrganizationMember()`（`tests/Pest.php` 既存）、
  `manage-api-keys` 直接付与は `ApiKeyPermissionService::grant()` または既存
  `tests/Feature/Organizations/ApiKeyPermissionTest.php` の付与パターンを流用。
- 検証（`GET /dashboard` を `assertInertia` で共有 prop を検査）:
  1. owner: `currentOrganization.slug` が組織 slug と一致、`canManageMembers=true`, `canManageApiKeys=true`
  2. admin: `canManageMembers=true`, `canManageApiKeys=true`
  3. 権限なし member: `canManageMembers=false`, `canManageApiKeys=false`
  4. 現在組織で `manage-api-keys` 直接付与された member: `canManageMembers=false`, `canManageApiKeys=true`
  5. **別組織でのみ `manage-api-keys` 直接付与された member（現在組織は無権限）**:
     現在組織の共有 prop で `canManageApiKeys=false`（cross-org 漏れ防止）
- [x] 個別 `DatabaseTransactions` を使わない（グローバル `RefreshDatabase`）

### S5-b. PHP Feature: post-switch redirect 契約

既存 `tests/Feature/Organization/OrganizationSwitchTest.php` に追記（既存テストは維持）:

- 「settings 画面（slug）から切替しても dashboard へ 302 + `current_organization_id` が更新される」
  ケースを追加。所属 2 組織を用意し、`GET /organizations/{A.slug}/settings` 到達後に
  `POST /organizations/{B.id}/switch` → `assertRedirect('/dashboard')` +
  `current_organization_id === B.id`。既存の switch 基本テスト（L13-26）と重複しない観点として、
  slug 画面起点でも中立ページへ着地する契約を明示的に固定する。
- 波及変更: なし

### S5-c. JS component テスト

新規: `tests/js/components/features/organizations/OrganizationSwitcher.test.ts`
（`vi.mock("@inertiajs/svelte")` で `router.post` を mock、`Link` は原物を使用。既存
`AppLayout.test.ts` パターン準拠）:

1. 現在組織名をトリガーに表示（`org-switcher-trigger`）
2. トリガー押下でパネル開、`aria-expanded` が false↔true
3. `organizations.length > 1` のとき他組織の切替ボタンを描画、押下で
   `router.post` が `/organizations/{id}/switch` で呼ばれる
4. `organizations.length === 1` のとき切替セクション非表示
5. 権限リンク出し分け: `canManageMembers=false` でメンバー管理リンク非表示 /
   `canManageApiKeys=false` で API キーリンク非表示 / 組織設定・請求・料金は常時表示 /
   設定リンクの href が `/organizations/{slug}/settings`
6. `currentOrganization=null` で「組織を作成」(`/organizations/create`) を表示し切替/管理リンクは非表示
7. a11y: Escape でパネルが閉じる / トリガーに `disabled` 属性を持たない（禁止事項 8）

`tests/js/components/templates/AppLayout.test.ts` に追記（既存 assertion は維持）:
8. ログイン中は `org-switcher-trigger` を常設描画する（`currentOrganization`/`organizations` を page props に設定）

- 波及変更: なし（テスト追加）

### PHPStan適合チェック（S5 全体）
- [x] Feature テストは型注釈済みヘルパを使用、`@var` で narrowing
- [x] 個別 `DatabaseTransactions` 不使用（`tests/Pest.php` グローバル `RefreshDatabase`）

### リスク
- `manage-api-keys` 直接付与の team_id 明示を誤ると cross-org 分離テスト(5) が誤って pass する恐れ →
  付与は `ApiKeyPermissionService::grant($user, $org)`（内部で `laratrust_team_id` 明示）を使い、
  別組織付与ケースでは別 org を対象に付与して現在組織 prop が false であることを厳密に検証。

---

## 実装モード

| 項目 | 内容 |
|------|------|
| 推奨モード | standalone |
| 判断根拠 | 5 施策が「共有 prop 拡張 → 型 → 新規コンポーネント → 配置 → テスト」と密結合の 1 まとまりで、分割すると型不整合・未配線の中間状態が生じる。独立した 1 worktree で一括実装・検証するのが安全。 |
| 競合リスク | `HandleInertiaRequests.php`（共有 prop）と `AppLayout.svelte`（全画面共通レイアウト）に触れるため、同ファイルを触る他タスクとの並走は避ける。新規コンポーネント/テストは競合しない。 |

## 使命・禁止事項 最終チェック

- [x] 使命寄与: 組織(現場)横断運用・招待・請求の到達導線を回復（North Star の運用基盤）
- [x] 禁止事項 1: 全施策にテスト（PHP Feature + JS component）を紐付け
- [x] 禁止事項 2: PHPStan は array-shape 拡張で型を締める（widen/baseline なし）
- [x] 禁止事項 4: 新エンドポイントなし。既存 Inertia 共有 prop の array-shape（`response()->json()` 直書きなし）
- [x] 禁止事項 8: 現在組織項目・リンクを disabled にしない（非表示 or no-op 遷移）
- [x] セキュリティ: cross-org slug 非露出、権限は laratrust_team_id 明示評価、cross-org 分離テストで固定
- [x] DESIGN.md / Atomic Design: DS token のみ、Lucide のみ、features/{domain} 配置で単方向 import 準拠


## 関連する現行コード

### app/Http/Middleware/HandleInertiaRequests.php (currentOrganizationProp / organizationsProp)
```php
/**
 * @return list<array{id: int, name: string, isPersonal: bool}>
 */
private function organizationsProp(?User $user): array
{
    if ($user === null) {
        return [];
    }
    return array_values($user->organizations()->get()
        ->map(fn (Organization $organization): array => [
            'id' => $organization->id,
            'name' => $organization->name,
            'isPersonal' => $organization->is_personal,
        ])->all());
}

/**
 * @return array{id: int, name: string, role: string|null}|null
 */
private function currentOrganizationProp(?User $user): ?array
{
    $organization = $user?->currentOrganization;
    if ($user === null || $organization === null) {
        return null;
    }
    return [
        'id' => $organization->id,
        'name' => $organization->name,
        'role' => $user->organizationRole($organization)?->value,
    ];
}
```

### app/Http/Controllers/Organizations/OrganizationSwitchController.php
```php
public function store(Request $request, Organization $organization): RedirectResponse
{
    $user = $request->user();
    Assert::isInstanceOf($user, User::class);
    $user->forceFill(['current_organization_id' => $organization->id])->save();
    return redirect()->route('dashboard')->with('success', "「{$organization->name}」に切り替えました");
}
```

### app/Policies/OrganizationPolicy.php (関連メソッド)
```php
public function view(User $user, Organization $organization): bool { /* メンバー全員 (organizationRole != null) */ }
public function manageMembers(User $user, Organization $organization): bool
{
    return $user->organizationRole($organization)?->canManage() ?? false; // owner/admin
}
public function manageBilling(User $user, Organization $organization): bool
{
    return $user->organizationRole($organization)?->canManage() ?? false; // owner/admin
}
public function manageApiKeys(User $user, Organization $organization): bool
{
    $role = $user->organizationRole($organization);
    if ($role === null) { return false; }
    if ($role->canManage()) { return true; }
    return app(ApiKeyPermissionService::class)->hasDirectPermission($user, $organization); // 直接付与
}
```

### 認可契約 (実コード確認済み)
- BillingController::index() = Gate::authorize('view', $organization) (メンバー全員)。checkout/portal のみ manageBilling。
- OrganizationController::settings() = Gate::authorize('view', $organization) (メンバー全員)。
- organizations.switch は {organization} = id 解決 (MembershipScopedOrganizationBinder。非メンバー/不在は 404 で存在秘匿)。
- organizations.settings / organizations.api-keys.index は {organization:slug} バインド (slug 必須)。billing.index は current-org スコープ (slug 不要)。

### resources/js/components/templates/AppLayout.svelte (現行ヘッダー抜粋)
```svelte
{#if showAccountNav}
    <NotificationBell unreadCount={shared.notifications?.unreadCount ?? 0} />
    <TextLink href="/settings" testId="nav-settings">設定</TextLink>
    <Button variant="ghost" size="sm" onclick={logout} loading={loggingOut} testId="nav-logout">ログアウト</Button>
{/if}
```

### resources/js/lib/shared-props.ts (現行 CurrentOrganization / OrganizationSummary)
```ts
export interface OrganizationSummary { id: number; name: string; isPersonal: boolean; }
export interface CurrentOrganization { id: number; name: string; role: string | null; }
export interface SharedProps {
    appName: string;
    auth: { user: AuthUser | null };
    organizations: OrganizationSummary[];
    currentOrganization: CurrentOrganization | null;
    flash: FlashPayload;
    notifications: NotificationSharedProps;
    title: string;
}
```
