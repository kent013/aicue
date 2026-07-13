# 使命・禁止事項・思考原則（全レビューに適用）

## アプリの使命（North Star）

**AI-CUE** は、現場に既にある**作業手順書(SOP)を起点に**、AI が撮るべきカットを設計した**動画シナリオ**を生成し、そのシナリオを**スマホ(PWA)でナビゲーション撮影**することで、専門知識ゼロの現場作業者でも**標準化されたマニュアル動画**を作れるようにする。「思考ゼロ・編集ゼロ」。競合と異なり標準作業を起点に AI が教材設計し撮影を指示する。熟練者の暗黙知を動画マニュアル(形式知)へ変換する装置(SECI)。

本施策は教材生成そのものではないが、組織(現場)を跨いだ運用・招待・請求という**運用の背骨**の到達導線を回復し、「迷子にならず思考ゼロで使える」前提を満たす基盤改善である。

## 禁止事項（AGENTS.md 正本）

1. テストなしの実装完了報告（不変条件は Architecture/Feature テスト登録まで含めて「実装済み」）
2. PHPStan エラーの widen・baseline 化
3. dev DB への破壊操作をエージェント判断で実行
4. `response()->json()` の直書き（DTO / JsonResource / Inertia を使う。仕様固定 endpoint のみ例外）
5. LLM 呼び出しの Prism 直呼び
6. prompt 文字列のコード直書き
7. 操作系 POST の応答での `redirect()->intended()`
8. 必須条件未充足を理由にボタンを disabled にする UI（押下時にエラー表示）

### セキュリティ不変条件（関連分）
- **cross-org 不可**: 組織を跨ぐ read/write をしない。切替リストは id のみで cross-org slug を露出しない。
- **権限判定は常に `laratrust_team_id` を明示**（strict_check=true）。権限フラグは currentOrganization を対象に評価。

## 思考原則 — 全議論に適用
まず仮説を立てろ。データに真摯に向き合え。先人の知恵（Laravel/Svelte エコシステム）を探せ。機能の名前に立ち返れ。仕組みが機能していない段階で値を弄るな。オーバーエンジニアリング禁止。

## ツール使用制限
コマンド実行・ファイル書き込みは一切行わず、提供されたテキストの分析に集中すること。ファイル読み込みは許可。

---

# system: レビュアーとしての役割

あなたは Laravel 12 + Svelte 5 + Inertia.js のシニアレビュアーである。TODO T019「組織ナビ&組織スイッチャー導線の追加」の実装差分を、以下の観点でレビューせよ。

**レビュー観点**:
1. **設計との一致性**: 詳細設計書 S1〜S5 の仕様どおり実装されているか
2. **正確性**: ロジックの誤り・エッジケース漏れ・a11y 破綻・状態管理（runes / $effect クリーンアップ）の欠陥
3. **PHPStan 適合性**: level 10 で型が締まっているか（widen/baseline なし）
4. **DTO/JsonResource/Inertia パターン**: `response()->json()` 直書きがないか（本施策は Inertia 共有 prop の array-shape）
5. **テスト網羅性**: 各施策にテストがあるか。cross-org 分離・a11y の 3 経路・権限出し分けが固定されているか
6. **セキュリティ**: cross-org 漏れ（別組織権限が現在組織へ漏れない）、slug 非露出、laratrust_team_id 明示評価
7. **DESIGN.md 準拠**: color/radius/typography は DS token 経由か（hex 直書きなし）。DESIGN.md が canonical
8. **Atomic Design 準拠**: `features/organizations/` 配置が単方向 import（atoms→…→features→templates）に従うか。アイコンは Lucide のみ、SVG 直書きなし

**出力形式**: ファイルごとに判定。指摘は Critical / Warning / Suggestion に分類。最後に全体判定 **APPROVED** または **CHANGES_REQUESTED** を明記せよ。

---

# user: レビュー対象

## テスト結果

- `composer test`（Pest, --parallel, RefreshDatabase）: **1568 tests / 1566 passed / 2 skipped / 6527 assertions**
- `composer phpstan`（level 10）: **No errors**（631 files）
- `vendor/bin/pint --test`: **passed**
- `pnpm lint`（eslint）: **passed**
- `pnpm typecheck`（tsc --noEmit）: **passed**
- `pnpm test`（vitest）: **490 passed / 69 files**（新規 OrganizationSwitcher.test.ts 15 ケース + AppLayout.test.ts 追記 2 ケースを含む）
- `pnpm build`（vite）: **built OK**
- 新規/変更 PHP Feature テスト単体: OrganizationNavSharedPropsTest(6) + OrganizationSwitchTest(追記1) = 13 passed / 115 assertions

## design system 参照（DESIGN.md 抜粋 = canonical token）

- colors: `primary #2563EB` / `primary-hover` / `neutral #F4F4F5`(画面背景) / `surface #FFFFFF`(浮遊要素) / `border #E4E4E7` / `text-primary #18181B`(`text-text`) / `text-secondary #52525B`(`text-text-secondary`)
- typography ramp（raw text-sm/font-bold 禁止）: `text-display/h1/h2/h3/body/caption`
- rounded: `rounded-sm/md/lg` の 3 段のみ（方向別・任意値・full 禁止。full は真円 UI のみ allowlist）
- Elevation: shadow/gradient 禁止（明度差 + border で階層）
- アイコン: `@lucide/svelte` のみ。SVG 直書きは `atoms/icons/` のみ
- 使用トークン（本 diff）: `bg-surface` / `bg-neutral`(hover) / `border-border` / `text-text` / `text-text-secondary` / `text-primary` / `text-body` / `text-caption` / `rounded-md`

## Atomic Design 構造（features 配下）

```
components/features/{admin,auth,capture,manual,notifications,organizations}
```
新規 `features/organizations/OrganizationSwitcher.svelte` は atoms(なし。Button 不使用) + `@inertiajs/svelte`(Link/router) + `@lucide/svelte` を合成。`templates/AppLayout.svelte`(templates 層) が features を import する（許可方向）。

## 詳細設計書（全文）

（下記は detailed-design.md。長いため要点: S1 共有 prop に slug + canManageMembers/canManageApiKeys 追加 + isMemberOf 防御 / S2 TS 型拡張 / S3 OrganizationSwitcher 新規（disclosure, Escape/outside/focusout の 3 経路で閉じる, 現在組織は非対話行, 権限でリンク出し分け, null で「組織を作成」）/ S4 AppLayout 常設配置 / S5 テスト）

```md
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
 * defense-in-depth: current_organization_id が万一 (データドリフト等で) 非所属 org を
 * 指した場合に slug/name を露出しないよう、isMemberOf で membership を再検証して null に倒す。
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

    // cross-org 防御: current が非所属 org を指していたら共有しない (存在秘匿)。
    if (! $user->isMemberOf($organization)) {
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
- `organizationRole` 呼び出しは currentOrganizationProp(1) + 各 policy(manageMembers/manageApiKeys の 2)
  の計 3 回/認証リクエスト。laratrust の team-scoped 参照で軽微。認証済み画面のみで評価され、
  ゲスト・非メンバー時は null 早期リターン。将来 N+1 が顕在化した場合は role をローカル解決して
  policy に渡す最適化余地があるが、今回は Policy 契約を歪めないため実装しない（オーバーエンジニアリング回避）。

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
/** OrganizationRole enum の value と 1:1 のユニオン (型の網羅性を上げる) */
export type OrganizationRoleValue =
    | "organization_owner"
    | "organization_admin"
    | "organization_member";

export interface CurrentOrganization {
    id: number;
    name: string;
    /** organizations.settings / api-keys.index ({organization:slug}) 用 */
    slug: string;
    role: OrganizationRoleValue | null;
    /** メンバー管理 (/manage/users) 導線の表示可否 (owner/admin) */
    canManageMembers: boolean;
    /** API キー画面 (organizations.api-keys.index) 導線の表示可否 */
    canManageApiKeys: boolean;
}
```
- 波及: 既存 `role` 参照箇所は文字列比較のため互換 (union は string のサブタイプ)。既存ページの
  独自 Props 型には影響しない (それらは shared の `CurrentOrganization` を import していない)。

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
  `id="org-switcher-trigger"` + `aria-expanded={open}` + `aria-controls="org-switcher-panel"`
  （**`aria-haspopup` は付けない** = disclosure セマンティクス）。`data-testid="org-switcher-trigger"`。
- 展開パネル（`open` 時のみ描画、`id="org-switcher-panel"`、`aria-labelledby="org-switcher-trigger"`、
  **`role="menu"` は付けない**）:
  1. **切替セクション**（`organizations.length > 1` のときのみ）:
     - **他組織**（`org.id !== currentOrganization?.id`）: `<button>` で描画し
       `router.post(\`/organizations/${org.id}/switch\`)` を呼び、押下後 `open=false`。
       URL は文字列パス直書き（本プロジェクトは **Ziggy 未導入**で、これが既存標準。
       cf. `Admin/Users.svelte:181` `router.post(\`/organizations/${slug}/invitations\`)`,
       `Projects/Show.svelte:89`）。`data-testid="org-switch-{id}"`。
     - **現在組織**（`org.id === currentOrganization?.id`）: **切替ボタンにしない**（no-op 押下による
       誤操作を避ける）。`aria-current="true"` + 「現在の組織」ラベル + `Check` アイコンの
       非対話行として描画。`disabled` 属性は付けない（禁止事項 8 非抵触）。押下要素にしないため
       送信も発生しない。
     - `org.isPersonal` は `個人` ラベルを添える。
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
- **focusout（キーボード離脱）**: ルート要素の `onfocusout` で `event.relatedTarget` がルート外
  なら閉じる。`relatedTarget` は `EventTarget | null` のため、`relatedTarget instanceof Node` を
  確認してから `root.contains(relatedTarget)` に渡す（TypeScript 型安全）。Tab でパネル外へ
  抜けたときも閉じる。
- **Escape**: パネル/ルートの `onkeydown` で `event.key === 'Escape'` なら閉じてトリガーへ focus 復帰
  （トリガーを `bind:this` で保持し `.focus()`）。
- 項目間は **Tab で順次移動**（Link/button の自然な tab 順。矢印キーナビは実装しない）。

**DESIGN.md 準拠**: 色 `text-text` / `text-text-secondary` / `bg-surface` / `border-border` /
`hover:bg-neutral`、radius `rounded-md`、typography `text-body` / `text-caption` の DS token のみ。
hex 直書き・新規 token は追加しない。アイコンは `@lucide/svelte`（`ChevronsUpDown, Check, Settings,
Users, KeyRound, CreditCard, Tag, Plus`）。SVG 直書きは新設しない。トリガーは 375px ヘッダーの
折返し方針に合わせ `shrink-0`。回帰は `pnpm test`（ds-purity / atomic-import-graph 含む）で固定する。

### PHPStan適合チェック
- N/A（Svelte/TS。`pnpm typecheck` / `pnpm lint` / ds-purity テストで検証）

### テスト計画
- [x] S5-c（component テスト）

### リスク
- click-outside の `$effect` クリーンアップ漏れは再レンダで多重リスナになるため、`open` 依存で
  張り/剥がしを厳密化（テストで Escape / outside pointer / focusout の 3 経路の閉動作を固定）。
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
  6. **current_organization_id が所属外 org を指す（データドリフト）**: 共有 prop
     `currentOrganization=null`（S1 の isMemberOf フォールバック。slug/name を露出しない）
- [x] 個別 `DatabaseTransactions` を使わない（グローバル `RefreshDatabase`）

### S5-b. PHP Feature: post-switch redirect 契約 + 認可契約の参照

既存 `tests/Feature/Organization/OrganizationSwitchTest.php` に追記（既存テストは維持）:

- 「settings 画面（slug）から切替しても dashboard へ 302 + `current_organization_id` が更新される」
  ケースを追加。所属 2 組織を用意し、`GET /organizations/{A.slug}/settings` 到達後に
  `POST /organizations/{B.id}/switch` → `assertRedirect('/dashboard')` +
  `current_organization_id === B.id`。既存の switch 基本テスト（L13-26）と重複しない観点として、
  slug 画面起点でも中立ページへ着地する契約を明示的に固定する。
- **認可契約（controller は変更しない）**: switch は「自分の current_organization_id を X にする」=
  ユーザー自身の状態変更で、必要な認可は「X のメンバーであること」のみ。これは
  `MembershipScopedOrganizationBinder` が membership スコープで解決し非メンバー/不在を等しく 404 に
  倒すことで**構造的に強制**される（view 認可 = membership と同義）。既存テスト
  `OrganizationSwitchTest` L28-36「所属していない組織へは切り替えられない (404)」がこの認可契約を
  固定済みのため、controller への明示 `Gate::authorize` 追加はスコープ外（同一判定の二重化）。
- 波及変更: なし

### S5-c. JS component テスト

新規: `tests/js/components/features/organizations/OrganizationSwitcher.test.ts`
（`vi.mock("@inertiajs/svelte")` で `router.post` を mock、`Link` は原物を使用。既存
`AppLayout.test.ts` パターン準拠）:

1. 現在組織名をトリガーに表示（`org-switcher-trigger`）
2. トリガー押下でパネル開、`aria-expanded` が false↔true
3. `organizations.length > 1` のとき**他組織**の切替ボタンを描画、押下で
   `router.post` が `/organizations/{id}/switch` で呼ばれる
4. 現在組織行は切替ボタンにならない（`aria-current="true"` + 「現在の組織」ラベル、押下で
   `router.post` が呼ばれない）
5. `organizations.length === 1` のとき切替セクション非表示
6. 権限リンク出し分け:
   - `canManageMembers=false` でメンバー管理リンク非表示 / `canManageApiKeys=false` で API キーリンク非表示
   - **`canManageMembers=true` でメンバー管理リンク表示 / `canManageApiKeys=true` で API キーリンク表示**（復活）
   - 組織設定・請求・料金は常時表示 / 設定リンクの href が `/organizations/{slug}/settings`
7. `currentOrganization=null` で「組織を作成」(`/organizations/create`) を表示し切替/管理リンクは非表示
8. a11y: Escape / outside pointerdown / focusout の 3 経路でパネルが閉じる / トリガーに
   `disabled` 属性を持たない（禁止事項 8）/ パネルに `aria-labelledby="org-switcher-trigger"`

`tests/js/components/templates/AppLayout.test.ts` に追記（既存 assertion は維持）:
9. ログイン中は `org-switcher-trigger` を常設描画する（`currentOrganization`/`organizations` を page props に設定）
10. トリガーが `shrink-0` クラスを持つ（375px ヘッダー折返し回帰防止）

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

```

## 実装差分（git diff HEAD）

```diff
diff --git a/app/Http/Middleware/HandleInertiaRequests.php b/app/Http/Middleware/HandleInertiaRequests.php
index 5f0c378..4731ee8 100644
--- a/app/Http/Middleware/HandleInertiaRequests.php
+++ b/app/Http/Middleware/HandleInertiaRequests.php
@@ -105,9 +105,24 @@ private function organizationsProp(?User $user): array
     }
 
     /**
-     * 現在の組織 + 自分のロール (organizationRole = laratrust_team_id 明示判定)。
+     * 現在の組織 + 自分のロール + ナビ表示に必要な最小権限フラグ。
+     * 権限は currentOrganization ($organization) を対象に評価し、OrganizationPolicy を
+     * 唯一の真実源とする (role 直見しない)。Policy は organizationRole($organization)
+     * = laratrust_team_id を明示した strict_check 判定を経由するため、別組織で付与された
+     * 権限は現在組織へ漏れない (cross-org 分離。テストで固定)。
+     * slug は organizations.settings / organizations.api-keys.index ({organization:slug}
+     * バインド) への恒常リンク生成に必須。
+     * defense-in-depth: current_organization_id が万一 (データドリフト等で) 非所属 org を
+     * 指した場合に slug/name を露出しないよう、isMemberOf で membership を再検証して null に倒す。
      *
-     * @return array{id: int, name: string, role: string|null}|null
+     * @return array{
+     *     id: int,
+     *     name: string,
+     *     slug: string,
+     *     role: string|null,
+     *     canManageMembers: bool,
+     *     canManageApiKeys: bool
+     * }|null
      */
     private function currentOrganizationProp(?User $user): ?array
     {
@@ -116,10 +131,20 @@ private function currentOrganizationProp(?User $user): ?array
             return null;
         }
 
+        // cross-org 防御: current が非所属 org を指していたら共有しない (存在秘匿)。
+        if (! $user->isMemberOf($organization)) {
+            return null;
+        }
+
         return [
             'id' => $organization->id,
             'name' => $organization->name,
+            'slug' => $organization->slug,
             'role' => $user->organizationRole($organization)?->value,
+            // ナビ表示用の最小権限 (settings/billing は view=メンバー全員のためフラグ不要)。
+            // billing 画面内の操作出し分けは既存 canManageBilling prop が担うため shared には載せない。
+            'canManageMembers' => $user->can('manageMembers', $organization),
+            'canManageApiKeys' => $user->can('manageApiKeys', $organization),
         ];
     }
 }
diff --git a/resources/js/components/features/organizations/OrganizationSwitcher.svelte b/resources/js/components/features/organizations/OrganizationSwitcher.svelte
new file mode 100644
index 0000000..7e9aad7
--- /dev/null
+++ b/resources/js/components/features/organizations/OrganizationSwitcher.svelte
@@ -0,0 +1,220 @@
+<script lang="ts">
+    import { Link, router } from "@inertiajs/svelte";
+    import {
+        Check,
+        ChevronsUpDown,
+        CreditCard,
+        KeyRound,
+        Plus,
+        Settings,
+        Tag,
+        Users,
+    } from "@lucide/svelte";
+    import type { CurrentOrganization, OrganizationSummary } from "@/lib/shared-props";
+
+    /**
+     * 組織スイッチャー兼組織メニュー (disclosure パターン)。
+     * 現在組織を表示するトリガー + 展開パネルで「組織切替」と「組織設定/メンバー/API キー/
+     * 請求/料金」への恒常導線を提供する (North Star: 組織横断運用の到達導線を回復)。
+     *
+     * 純粋・テスト容易にするため shared prop は親 (AppLayout) が読んで props で渡す。
+     * cross-org 防御は backend (currentOrganizationProp の isMemberOf + Policy 評価) が担い、
+     * ここは受け取った権限フラグでリンクを出し分けるだけ (二重判定しない)。
+     *
+     * a11y: disclosure セマンティクス (aria-expanded/aria-controls。role=menu は付けない)。
+     * Escape / outside pointerdown / focusout の 3 経路で閉じる。禁止事項 8 に従い現在組織項目や
+     * リンクを disabled にしない (現在組織は非対話行、他は遷移)。
+     */
+    interface Props {
+        /** 現在の組織 (未所属/未設定時は null → 「組織を作成」フォールバック) */
+        currentOrganization: CurrentOrganization | null;
+        /** 所属組織一覧 (切替候補。id で switch) */
+        organizations: OrganizationSummary[];
+    }
+
+    let { currentOrganization, organizations }: Props = $props();
+
+    let open = $state(false);
+    let root = $state<HTMLDivElement | null>(null);
+    let trigger = $state<HTMLButtonElement | null>(null);
+
+    const triggerLabel = $derived(currentOrganization?.name ?? "組織を選択");
+    // 切替候補セクションは 2 組織以上のときのみ (1 組織なら切替不要)
+    const showSwitchSection = $derived(organizations.length > 1);
+
+    function close(): void {
+        open = false;
+    }
+
+    function toggle(): void {
+        open = !open;
+    }
+
+    function switchTo(id: number): void {
+        // Ziggy 未導入のため文字列パス直書きが既存標準 (cf. Admin/Users.svelte)。
+        router.post(`/organizations/${id}/switch`);
+        close();
+    }
+
+    // focusout: Tab 等でルート外へ focus が抜けたら閉じる (focus 系は静的要素でも a11y 上許容)
+    function onFocusOut(event: FocusEvent): void {
+        const next = event.relatedTarget;
+        if (next instanceof Node && root?.contains(next)) {
+            return;
+        }
+        close();
+    }
+
+    // open の間だけ document へ pointerdown / keydown を張り、outside クリックと Escape で閉じる。
+    // keydown を静的な wrapper div に載せると a11y_no_static_element_interactions になるため
+    // document スコープに寄せる (disclosure の open 中のみ有効化)。
+    $effect(() => {
+        if (!open) {
+            return;
+        }
+        function onPointerDown(event: PointerEvent): void {
+            const target = event.target;
+            if (target instanceof Node && root?.contains(target)) {
+                return;
+            }
+            close();
+        }
+        function onKeydown(event: KeyboardEvent): void {
+            if (event.key === "Escape") {
+                close();
+                trigger?.focus();
+            }
+        }
+        document.addEventListener("pointerdown", onPointerDown);
+        document.addEventListener("keydown", onKeydown);
+        return () => {
+            document.removeEventListener("pointerdown", onPointerDown);
+            document.removeEventListener("keydown", onKeydown);
+        };
+    });
+</script>
+
+<div class="relative shrink-0" bind:this={root} onfocusout={onFocusOut}>
+    <button
+        type="button"
+        id="org-switcher-trigger"
+        class="inline-flex shrink-0 items-center gap-2 rounded-md border border-border
+            bg-surface px-3 py-1.5 text-body text-text hover:bg-neutral"
+        aria-expanded={open}
+        aria-controls="org-switcher-panel"
+        onclick={toggle}
+        bind:this={trigger}
+        data-testid="org-switcher-trigger"
+    >
+        <span class="max-w-40 truncate">{triggerLabel}</span>
+        <ChevronsUpDown class="size-4 shrink-0 text-text-secondary" aria-hidden="true" />
+    </button>
+
+    {#if open}
+        <div
+            id="org-switcher-panel"
+            class="absolute right-0 z-20 mt-1 w-64 rounded-md border border-border bg-surface py-1"
+            aria-labelledby="org-switcher-trigger"
+        >
+            {#if currentOrganization != null}
+                {#if showSwitchSection}
+                    <p class="px-3 py-1 text-caption text-text-secondary">組織を切り替え</p>
+                    {#each organizations as org (org.id)}
+                        {#if org.id === currentOrganization.id}
+                            <div
+                                class="flex items-center gap-2 px-3 py-2 text-body text-text"
+                                aria-current="true"
+                                data-testid="org-current-{org.id}"
+                            >
+                                <Check
+                                    class="size-4 shrink-0 text-primary"
+                                    aria-hidden="true"
+                                />
+                                <span class="min-w-0 flex-1 truncate">{org.name}</span>
+                                {#if org.isPersonal}
+                                    <span class="text-caption text-text-secondary">個人</span>
+                                {/if}
+                                <span class="text-caption text-text-secondary">現在の組織</span>
+                            </div>
+                        {:else}
+                            <button
+                                type="button"
+                                class="flex w-full items-center gap-2 px-3 py-2 text-left
+                                    text-body text-text hover:bg-neutral"
+                                onclick={() => switchTo(org.id)}
+                                data-testid="org-switch-{org.id}"
+                            >
+                                <span class="size-4 shrink-0" aria-hidden="true"></span>
+                                <span class="min-w-0 flex-1 truncate">{org.name}</span>
+                                {#if org.isPersonal}
+                                    <span class="text-caption text-text-secondary">個人</span>
+                                {/if}
+                            </button>
+                        {/if}
+                    {/each}
+                    <div class="my-1 border-t border-border" role="separator"></div>
+                {/if}
+
+                <Link
+                    href={`/organizations/${currentOrganization.slug}/settings`}
+                    class="flex items-center gap-2 px-3 py-2 text-body text-text hover:bg-neutral"
+                    onclick={close}
+                    data-testid="org-link-settings"
+                >
+                    <Settings class="size-4 shrink-0 text-text-secondary" aria-hidden="true" />
+                    組織設定
+                </Link>
+                {#if currentOrganization.canManageMembers}
+                    <Link
+                        href="/manage/users"
+                        class="flex items-center gap-2 px-3 py-2 text-body text-text hover:bg-neutral"
+                        onclick={close}
+                        data-testid="org-link-members"
+                    >
+                        <Users class="size-4 shrink-0 text-text-secondary" aria-hidden="true" />
+                        メンバー管理
+                    </Link>
+                {/if}
+                {#if currentOrganization.canManageApiKeys}
+                    <Link
+                        href={`/organizations/${currentOrganization.slug}/api-keys`}
+                        class="flex items-center gap-2 px-3 py-2 text-body text-text hover:bg-neutral"
+                        onclick={close}
+                        data-testid="org-link-api-keys"
+                    >
+                        <KeyRound class="size-4 shrink-0 text-text-secondary" aria-hidden="true" />
+                        API キー
+                    </Link>
+                {/if}
+                <Link
+                    href="/billing"
+                    class="flex items-center gap-2 px-3 py-2 text-body text-text hover:bg-neutral"
+                    onclick={close}
+                    data-testid="org-link-billing"
+                >
+                    <CreditCard class="size-4 shrink-0 text-text-secondary" aria-hidden="true" />
+                    請求
+                </Link>
+                <Link
+                    href="/pricing"
+                    class="flex items-center gap-2 px-3 py-2 text-body text-text hover:bg-neutral"
+                    onclick={close}
+                    data-testid="org-link-pricing"
+                >
+                    <Tag class="size-4 shrink-0 text-text-secondary" aria-hidden="true" />
+                    料金
+                </Link>
+            {:else}
+                <Link
+                    href="/organizations/create"
+                    class="flex items-center gap-2 px-3 py-2 text-body text-text hover:bg-neutral"
+                    onclick={close}
+                    data-testid="org-link-create"
+                >
+                    <Plus class="size-4 shrink-0 text-text-secondary" aria-hidden="true" />
+                    組織を作成
+                </Link>
+            {/if}
+        </div>
+    {/if}
+</div>
diff --git a/resources/js/components/templates/AppLayout.svelte b/resources/js/components/templates/AppLayout.svelte
index d8b5b13..82f973d 100644
--- a/resources/js/components/templates/AppLayout.svelte
+++ b/resources/js/components/templates/AppLayout.svelte
@@ -4,6 +4,7 @@
     import Button from "@/components/atoms/Button.svelte";
     import TextLink from "@/components/atoms/TextLink.svelte";
     import EmailVerificationBanner from "@/components/features/auth/EmailVerificationBanner.svelte";
+    import OrganizationSwitcher from "@/components/features/organizations/OrganizationSwitcher.svelte";
     import NotificationBell from "@/components/molecules/NotificationBell.svelte";
     import ToastContainer from "@/components/organisms/ToastContainer.svelte";
     import type { SharedProps } from "@/lib/shared-props";
@@ -11,7 +12,8 @@
 
     /**
      * 認証済み画面用レイアウト (最小骨格)。
-     * Phase 2 (組織・Team・Project 導入) でサイドバー・組織切替・通知センターを拡張する。
+     * 組織スイッチャー/組織メニューを常設 (組織切替・組織設定/請求/招待/API キー導線)。
+     * サイドバー/Team/Project ナビは後続 Phase。
      * Laravel flash は consumeFlash で toast に変換する (visitKey で de-dup)。
      * ログイン中は通知ベル・設定・ログアウトを全ページ常設する (F-08: ナビ統一)。
      * ログアウト POST はこのレイアウトの単一ハンドラに一本化する (ページ側に実装を残さない)。
@@ -74,6 +76,10 @@
                     {@render headerActions()}
                 {/if}
                 {#if showAccountNav}
+                    <OrganizationSwitcher
+                        currentOrganization={shared.currentOrganization ?? null}
+                        organizations={shared.organizations ?? []}
+                    />
                     <NotificationBell unreadCount={shared.notifications?.unreadCount ?? 0} />
                     <TextLink href="/settings" testId="nav-settings">設定</TextLink>
                     <Button
diff --git a/resources/js/lib/shared-props.ts b/resources/js/lib/shared-props.ts
index 88e59bd..5922045 100644
--- a/resources/js/lib/shared-props.ts
+++ b/resources/js/lib/shared-props.ts
@@ -20,11 +20,22 @@ export interface OrganizationSummary {
     isPersonal: boolean;
 }
 
+/** OrganizationRole enum の value と 1:1 のユニオン (型の網羅性を上げる) */
+export type OrganizationRoleValue =
+    | "organization_owner"
+    | "organization_admin"
+    | "organization_member";
+
 export interface CurrentOrganization {
     id: number;
     name: string;
-    /** OrganizationRole の value (organization_owner / organization_admin / organization_member) */
-    role: string | null;
+    /** organizations.settings / api-keys.index ({organization:slug}) 用 */
+    slug: string;
+    role: OrganizationRoleValue | null;
+    /** メンバー管理 (/manage/users) 導線の表示可否 (owner/admin) */
+    canManageMembers: boolean;
+    /** API キー画面 (organizations.api-keys.index) 導線の表示可否 */
+    canManageApiKeys: boolean;
 }
 
 export interface SharedProps {
diff --git a/tests/Feature/Organization/OrganizationSwitchTest.php b/tests/Feature/Organization/OrganizationSwitchTest.php
index 160b466..de95268 100644
--- a/tests/Feature/Organization/OrganizationSwitchTest.php
+++ b/tests/Feature/Organization/OrganizationSwitchTest.php
@@ -35,6 +35,24 @@
     expect($user->fresh()->current_organization_id)->toBe($organizationA->id);
 });
 
+test('slug 画面 (settings) 起点で切替しても dashboard へ 302 + current が更新される', function (): void {
+    // スイッチャーは組織設定 (slug 画面) 内からも押せるため、slug 起点でも中立ページ
+    // (dashboard) へ着地する post-switch redirect 契約を明示的に固定する
+    // (既存の switch 基本テストと重複しない観点)。
+    [$organizationA, $user] = createOrganizationWithOwner('組織A');
+    $organizationB = Organization::factory()->create();
+    $organizationB->users()->attach($user);
+    $user->addRole(OrganizationRole::Member->value, $organizationB->laratrust_team_id);
+
+    // slug 画面 (A の設定) へ到達してからスイッチャーで B へ切替
+    $this->actingAs($user)->get("/organizations/{$organizationA->slug}/settings")->assertOk();
+
+    $response = $this->actingAs($user)->post("/organizations/{$organizationB->id}/switch");
+
+    $response->assertRedirect('/dashboard');
+    expect($user->fresh()->current_organization_id)->toBe($organizationB->id);
+});
+
 test('新規組織を作成すると provisioning が走り current が切り替わる', function (): void {
     [, $user] = createOrganizationWithOwner('既存組織');
 
diff --git a/tests/Feature/Organizations/OrganizationNavSharedPropsTest.php b/tests/Feature/Organizations/OrganizationNavSharedPropsTest.php
new file mode 100644
index 0000000..b11b6fa
--- /dev/null
+++ b/tests/Feature/Organizations/OrganizationNavSharedPropsTest.php
@@ -0,0 +1,106 @@
+<?php
+
+declare(strict_types=1);
+
+use App\Enums\OrganizationRole;
+use App\Models\User;
+use App\Services\ApiKey\ApiKeyPermissionService;
+use Inertia\Testing\AssertableInertia as Assert;
+
+/*
+ * HandleInertiaRequests の共有 prop currentOrganization に slug + ナビ表示用の
+ * 最小権限フラグ (canManageMembers / canManageApiKeys) が role 別に載ること、
+ * および cross-org 分離 (別組織で付与された権限が現在組織へ漏れない) を固定する。
+ *
+ * 権限は OrganizationPolicy (organizationRole = laratrust_team_id 明示) を唯一の真実源とし、
+ * defense-in-depth の isMemberOf フォールバックで dangling current を秘匿する。
+ */
+
+test('owner: slug + 両権限フラグ true を共有する', function (): void {
+    [$organization, $owner] = createOrganizationWithOwner('オーナー組織');
+
+    $this->actingAs($owner)->get('/dashboard')
+        ->assertOk()
+        ->assertInertia(fn (Assert $page) => $page
+            ->where('currentOrganization.id', $organization->id)
+            ->where('currentOrganization.slug', $organization->slug)
+            ->where('currentOrganization.role', OrganizationRole::Owner->value)
+            ->where('currentOrganization.canManageMembers', true)
+            ->where('currentOrganization.canManageApiKeys', true));
+});
+
+test('admin: 両権限フラグ true を共有する', function (): void {
+    [$organization] = createOrganizationWithOwner('管理者組織');
+    $admin = attachOrganizationMember($organization, OrganizationRole::Admin);
+    $admin->forceFill(['current_organization_id' => $organization->id])->save();
+
+    $this->actingAs($admin)->get('/dashboard')
+        ->assertOk()
+        ->assertInertia(fn (Assert $page) => $page
+            ->where('currentOrganization.role', OrganizationRole::Admin->value)
+            ->where('currentOrganization.canManageMembers', true)
+            ->where('currentOrganization.canManageApiKeys', true));
+});
+
+test('権限なし member: 両権限フラグ false を共有する', function (): void {
+    [$organization] = createOrganizationWithOwner('一般組織');
+    $member = attachOrganizationMember($organization, OrganizationRole::Member);
+    $member->forceFill(['current_organization_id' => $organization->id])->save();
+
+    $this->actingAs($member)->get('/dashboard')
+        ->assertOk()
+        ->assertInertia(fn (Assert $page) => $page
+            ->where('currentOrganization.slug', $organization->slug)
+            ->where('currentOrganization.role', OrganizationRole::Member->value)
+            ->where('currentOrganization.canManageMembers', false)
+            ->where('currentOrganization.canManageApiKeys', false));
+});
+
+test('現在組織で manage-api-keys 直接付与された member: canManageApiKeys のみ true', function (): void {
+    [$organization] = createOrganizationWithOwner('直接付与組織');
+    $member = attachOrganizationMember($organization, OrganizationRole::Member);
+    $member->forceFill(['current_organization_id' => $organization->id])->save();
+    app(ApiKeyPermissionService::class)->grant($member, $organization);
+
+    $this->actingAs($member->fresh())->get('/dashboard')
+        ->assertOk()
+        ->assertInertia(fn (Assert $page) => $page
+            ->where('currentOrganization.canManageMembers', false)
+            ->where('currentOrganization.canManageApiKeys', true));
+});
+
+test('別組織でのみ manage-api-keys 付与された member: 現在組織では canManageApiKeys=false (cross-org 漏れ防止)', function (): void {
+    [$orgA] = createOrganizationWithOwner('現在組織A');
+    [$orgB] = createOrganizationWithOwner('別組織B');
+
+    $member = User::factory()->create();
+    $orgA->users()->attach($member);
+    $member->addRole(OrganizationRole::Member->value, $orgA->laratrust_team_id);
+    $orgB->users()->attach($member);
+    $member->addRole(OrganizationRole::Member->value, $orgB->laratrust_team_id);
+    $member->forceFill(['current_organization_id' => $orgA->id])->save();
+
+    // manage-api-keys は別組織 B にのみ付与 (現在組織 A ではない)
+    app(ApiKeyPermissionService::class)->grant($member, $orgB);
+
+    $this->actingAs($member->fresh())->get('/dashboard')
+        ->assertOk()
+        ->assertInertia(fn (Assert $page) => $page
+            ->where('currentOrganization.id', $orgA->id)
+            ->where('currentOrganization.canManageApiKeys', false)
+            ->where('currentOrganization.canManageMembers', false));
+});
+
+test('current_organization_id が所属外 org を指す (データドリフト): currentOrganization=null で秘匿する', function (): void {
+    // 所属 0 件のユーザーは resolver が自己修復候補を持たないため current は dangling のまま残り、
+    // S1 の isMemberOf フォールバックが slug/name を露出せず null に倒す (defense-in-depth)。
+    $foreign = createOrganizationWithOwner('他人の組織')[0];
+    $user = User::factory()->create();
+    $user->forceFill(['current_organization_id' => $foreign->id])->save();
+
+    expect($user->isMemberOf($foreign))->toBeFalse();
+
+    $this->actingAs($user)->get('/dashboard')
+        ->assertOk()
+        ->assertInertia(fn (Assert $page) => $page->where('currentOrganization', null));
+});
diff --git a/tests/js/components/features/organizations/OrganizationSwitcher.test.ts b/tests/js/components/features/organizations/OrganizationSwitcher.test.ts
new file mode 100644
index 0000000..0b84e62
--- /dev/null
+++ b/tests/js/components/features/organizations/OrganizationSwitcher.test.ts
@@ -0,0 +1,223 @@
+import { afterEach, describe, expect, it, vi } from "vitest";
+import { cleanup, fireEvent, render, screen } from "@testing-library/svelte";
+import { tick } from "svelte";
+import OrganizationSwitcher from "@/components/features/organizations/OrganizationSwitcher.svelte";
+import type { CurrentOrganization, OrganizationSummary } from "@/lib/shared-props";
+
+// router.post をモックし Link は原物を使う (AppLayout.test.ts パターン準拠)
+const { routerMock } = vi.hoisted(() => ({
+    routerMock: { post: vi.fn() },
+}));
+
+vi.mock("@inertiajs/svelte", async (importOriginal) => ({
+    ...(await importOriginal<typeof import("@inertiajs/svelte")>()),
+    router: routerMock,
+}));
+
+function currentOrg(overrides: Partial<CurrentOrganization> = {}): CurrentOrganization {
+    return {
+        id: 1,
+        name: "現在組織",
+        slug: "current-org",
+        role: "organization_owner",
+        canManageMembers: true,
+        canManageApiKeys: true,
+        ...overrides,
+    };
+}
+
+function org(id: number, name: string, isPersonal = false): OrganizationSummary {
+    return { id, name, isPersonal };
+}
+
+/** トリガーを押してパネルを開く ($effect の click-outside 登録まで待つ) */
+async function openPanel(): Promise<void> {
+    await fireEvent.click(screen.getByTestId("org-switcher-trigger"));
+    await tick();
+}
+
+afterEach(() => {
+    cleanup();
+    routerMock.post.mockReset();
+});
+
+describe("features/organizations/OrganizationSwitcher", () => {
+    it("トリガーに現在組織名を表示する", () => {
+        render(OrganizationSwitcher, {
+            props: { currentOrganization: currentOrg({ name: "アクメ社" }), organizations: [] },
+        });
+
+        expect(screen.getByTestId("org-switcher-trigger")).toHaveTextContent("アクメ社");
+    });
+
+    it("トリガー押下でパネルが開き aria-expanded が false↔true する", async () => {
+        render(OrganizationSwitcher, {
+            props: { currentOrganization: currentOrg(), organizations: [] },
+        });
+        const trigger = screen.getByTestId("org-switcher-trigger");
+        expect(trigger).toHaveAttribute("aria-expanded", "false");
+
+        await openPanel();
+
+        expect(trigger).toHaveAttribute("aria-expanded", "true");
+        expect(document.getElementById("org-switcher-panel")).not.toBeNull();
+    });
+
+    it("2 組織以上なら他組織の切替ボタンを描画し、押下で /organizations/{id}/switch を POST する", async () => {
+        render(OrganizationSwitcher, {
+            props: {
+                currentOrganization: currentOrg({ id: 1 }),
+                organizations: [org(1, "現在組織"), org(2, "別組織")],
+            },
+        });
+        await openPanel();
+
+        await fireEvent.click(screen.getByTestId("org-switch-2"));
+
+        expect(routerMock.post).toHaveBeenCalledTimes(1);
+        expect(routerMock.post.mock.calls[0][0]).toBe("/organizations/2/switch");
+    });
+
+    it("現在組織行は切替ボタンにならない (aria-current + ラベル、押下で POST しない)", async () => {
+        render(OrganizationSwitcher, {
+            props: {
+                currentOrganization: currentOrg({ id: 1 }),
+                organizations: [org(1, "現在組織"), org(2, "別組織")],
+            },
+        });
+        await openPanel();
+
+        const currentRow = screen.getByTestId("org-current-1");
+        expect(currentRow).toHaveAttribute("aria-current", "true");
+        expect(currentRow).toHaveTextContent("現在の組織");
+        // 現在組織は切替ボタンとして描画されない
+        expect(screen.queryByTestId("org-switch-1")).toBeNull();
+
+        await fireEvent.click(currentRow);
+        expect(routerMock.post).not.toHaveBeenCalled();
+    });
+
+    it("1 組織のみなら切替セクションを描画しない", async () => {
+        render(OrganizationSwitcher, {
+            props: {
+                currentOrganization: currentOrg({ id: 1 }),
+                organizations: [org(1, "現在組織")],
+            },
+        });
+        await openPanel();
+
+        expect(screen.queryByTestId("org-current-1")).toBeNull();
+        expect(screen.queryByTestId("org-switch-1")).toBeNull();
+        // 管理リンクは出る
+        expect(screen.getByTestId("org-link-settings")).toBeInTheDocument();
+    });
+
+    it("権限フラグでメンバー管理 / API キーリンクを出し分ける", async () => {
+        render(OrganizationSwitcher, {
+            props: {
+                currentOrganization: currentOrg({
+                    slug: "acme",
+                    canManageMembers: false,
+                    canManageApiKeys: false,
+                }),
+                organizations: [],
+            },
+        });
+        await openPanel();
+
+        expect(screen.queryByTestId("org-link-members")).toBeNull();
+        expect(screen.queryByTestId("org-link-api-keys")).toBeNull();
+        // 常時表示のリンク
+        expect(screen.getByTestId("org-link-billing")).toBeInTheDocument();
+        expect(screen.getByTestId("org-link-pricing")).toBeInTheDocument();
+        const settingsHref = screen.getByTestId("org-link-settings").getAttribute("href") ?? "";
+        expect(new URL(settingsHref, "http://localhost").pathname).toBe(
+            "/organizations/acme/settings",
+        );
+    });
+
+    it("権限フラグ true でメンバー管理 / API キーリンクを表示する", async () => {
+        render(OrganizationSwitcher, {
+            props: {
+                currentOrganization: currentOrg({
+                    slug: "acme",
+                    canManageMembers: true,
+                    canManageApiKeys: true,
+                }),
+                organizations: [],
+            },
+        });
+        await openPanel();
+
+        expect(screen.getByTestId("org-link-members")).toBeInTheDocument();
+        const apiHref = screen.getByTestId("org-link-api-keys").getAttribute("href") ?? "";
+        expect(new URL(apiHref, "http://localhost").pathname).toBe("/organizations/acme/api-keys");
+    });
+
+    it("currentOrganization=null なら組織を作成のみ表示し切替/管理リンクは出さない", async () => {
+        render(OrganizationSwitcher, {
+            props: { currentOrganization: null, organizations: [] },
+        });
+        expect(screen.getByTestId("org-switcher-trigger")).toHaveTextContent("組織を選択");
+
+        await openPanel();
+
+        const createHref = screen.getByTestId("org-link-create").getAttribute("href") ?? "";
+        expect(new URL(createHref, "http://localhost").pathname).toBe("/organizations/create");
+        expect(screen.queryByTestId("org-link-settings")).toBeNull();
+        expect(screen.queryByTestId("org-link-billing")).toBeNull();
+    });
+
+    it("Escape でパネルを閉じる", async () => {
+        const { container } = render(OrganizationSwitcher, {
+            props: { currentOrganization: currentOrg(), organizations: [] },
+        });
+        await openPanel();
+        expect(document.getElementById("org-switcher-panel")).not.toBeNull();
+
+        await fireEvent.keyDown(container.firstElementChild as Element, { key: "Escape" });
+
+        expect(document.getElementById("org-switcher-panel")).toBeNull();
+    });
+
+    it("ルート外の pointerdown でパネルを閉じる", async () => {
+        render(OrganizationSwitcher, {
+            props: { currentOrganization: currentOrg(), organizations: [] },
+        });
+        await openPanel();
+        expect(document.getElementById("org-switcher-panel")).not.toBeNull();
+
+        await fireEvent.pointerDown(document.body);
+        await tick();
+
+        expect(document.getElementById("org-switcher-panel")).toBeNull();
+    });
+
+    it("focusout でルート外へ抜けたらパネルを閉じる", async () => {
+        const { container } = render(OrganizationSwitcher, {
+            props: { currentOrganization: currentOrg(), organizations: [] },
+        });
+        await openPanel();
+        expect(document.getElementById("org-switcher-panel")).not.toBeNull();
+
+        await fireEvent.focusOut(container.firstElementChild as Element, {
+            relatedTarget: document.body,
+        });
+
+        expect(document.getElementById("org-switcher-panel")).toBeNull();
+    });
+
+    it("トリガーは disabled 属性を持たず、パネルは aria-labelledby でトリガーに紐づく (a11y)", async () => {
+        render(OrganizationSwitcher, {
+            props: { currentOrganization: currentOrg(), organizations: [] },
+        });
+        expect(screen.getByTestId("org-switcher-trigger")).not.toBeDisabled();
+
+        await openPanel();
+
+        expect(document.getElementById("org-switcher-panel")).toHaveAttribute(
+            "aria-labelledby",
+            "org-switcher-trigger",
+        );
+    });
+});
diff --git a/tests/js/components/templates/AppLayout.test.ts b/tests/js/components/templates/AppLayout.test.ts
index 1a32c55..5cd1fab 100644
--- a/tests/js/components/templates/AppLayout.test.ts
+++ b/tests/js/components/templates/AppLayout.test.ts
@@ -104,6 +104,38 @@ describe("templates/AppLayout", () => {
         expect(screen.queryByTestId("unread-badge")).toBeNull();
     });
 
+    it("ログイン中は組織スイッチャートリガーを常設描画する", () => {
+        setPageProps({
+            auth: { user: authUser() },
+            notifications: { unreadCount: 0 },
+            currentOrganization: {
+                id: 1,
+                name: "アクメ社",
+                slug: "acme",
+                role: "organization_owner",
+                canManageMembers: true,
+                canManageApiKeys: true,
+            },
+            organizations: [{ id: 1, name: "アクメ社", isPersonal: false }],
+        });
+        render(AppLayout, { props: { appName: "AI-CUE", children } });
+
+        expect(screen.getByTestId("org-switcher-trigger")).toBeInTheDocument();
+        expect(screen.getByTestId("org-switcher-trigger")).toHaveTextContent("アクメ社");
+    });
+
+    it("組織スイッチャートリガーは shrink-0 で 375px ヘッダー折返しを維持する", () => {
+        setPageProps({
+            auth: { user: authUser() },
+            notifications: { unreadCount: 0 },
+            currentOrganization: null,
+            organizations: [],
+        });
+        render(AppLayout, { props: { appName: "AI-CUE", children } });
+
+        expect(screen.getByTestId("org-switcher-trigger")).toHaveClass("shrink-0");
+    });
+
     it("ページ固有の headerActions snippet と常設ナビが共存する (常設ナビは各 1 個)", () => {
         setPageProps({
             auth: { user: authUser() },

```
