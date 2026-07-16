## アプリの使命(North Star)
AI-CUE は現場の SOP を起点に AI が設計した動画シナリオをスマホ(PWA)でナビ撮影し標準マニュアル動画を作る(思考ゼロ・編集ゼロ)。
## 禁止事項(核)
テストなし完了 / PHPStan widen / 既存テスト削除 / response()->json()直書き / オーバーエンジニアリング / 後方互換並走を残さない。
## 思考原則・ツール制限
先人の知恵(既存解)を使え。今必要なものだけ作れ。コマンド実行・書き込み禁止、テキスト分析に集中(読み込み可)。
---
あなたは Laravel+Svelte アプリ改善の詳細設計レビュアー(経験豊富なアーキテクト)です。
前提: PHP8.4+Laravel12+Svelte5(runes)+Inertia+TS / PHPStan lv10 / Pest / DTO+JsonResource / Laratrust。
レビュー観点: 1.正確性 2.既存整合 3.PHPStan 4.テスト網羅(Pest,RefreshDatabase) 5.DTO/JsonResource
6.Inertia vs API 7.後退リスク 8.波及変更網羅(TS型/テスト) 9.セキュリティ 10.DESIGN.md準拠(token/hex直書き)
11.Atomic Design準拠(層責務/単方向import/Lucide/SVG直書き)。
本件背景: bug-hunt 検出の T069 レイアウト後退 2 件の修正。概念設計は Codex Round4 APPROVED。
参照 aigenba は templates/PageContent(mx-auto max-w-7xl) を全ページで使い幅統一。AI-CUE 監査で AppLayout
使用 24 ページ中 23 枚が左寄せ narrow max-w。フロント規約: ds-purity / atomic-import-graph テストが強制。
出力: 各施策 APPROVE/REQUEST_CHANGES、[Critical]/[Warning]/[Suggestion] 分類、Critical/Warning に修正案。
全体判定 APPROVED/CHANGES_REQUESTED。日本語。
---
## 詳細設計書
# 詳細設計: t069-layout-followup（設定 nav 二重掲載の解消 + コンテンツ幅の PageContent 統一）

## 使命・制約（絶対遵守）

### アプリの使命（North Star）
AI-CUE は、現場の作業手順書(SOP)を起点に AI が撮るべきカットを設計した動画シナリオを生成し、
スマホ(PWA)でナビゲーション撮影することで、専門知識ゼロの現場作業者でも標準化されたマニュアル
動画を作れるようにする（思考ゼロ・編集ゼロ）。

### 禁止事項（核）
- テストなしの実装完了 / PHPStan widen / 既存テスト削除 / `response()->json()` 直書き /
  やたらに複雑な案（オーバーエンジニアリング）/ 後方互換の並走を残さない。

### コーディングルール
- PHPStan level 10 / Pest（`--parallel`, RefreshDatabase グローバル）。本件はバックエンド変更なし。
- フロント: Svelte 5 runes + DS token/ramp のみ（DESIGN.md canonical, ds-purity）。アイコンは Lucide。
  component 階層は atoms→…→templates→pages の単方向 import（atomic-import-graph が強制）。
- 検証: `pnpm lint` / `pnpm typecheck` / `pnpm test` / `pnpm build` + `composer test` / `composer phpstan`
  / `vendor/bin/pint --test`。

## 概念設計リファレンス
`devnotes/20260716-2042-t069-layout-followup/conceptual-design.md`（Round 4 APPROVED）

## 施策一覧

| # | 施策名 | 変更ファイル | 優先度 |
|---|--------|------------|--------|
| S1 | 設定 nav 二重掲載の解消 | `templates/AppLayout.svelte`, `tests/js/components/templates/AppLayout.test.ts` | 高 |
| S2 | PageContent primitive 新設 + AppLayout main を padding 責務のみに | `templates/PageContent.svelte`(新規), `templates/AppLayout.svelte`, `tests/js/components/templates/PageContent.test.ts`(新規) | 高 |
| S3 | 認証 23 ページを PageContent へ移行 + Architecture テストで強制 | `resources/js/pages/**`(23枚), `tests/js/architecture/page-content-usage.test.ts`(新規) | 高 |

---

## S1: 設定 nav 二重掲載の解消

### 変更箇所
- `resources/js/components/templates/AppLayout.svelte`（`navItems` の `$derived.by`）
- `tests/js/components/templates/AppLayout.test.ts`（負例追加）

### 波及変更
- TypeScript 型: なし（項目削除のみ）。
- テスト: AppLayout.test に「/settings が左 nav に出ない」負例を追加。
- 他: `SidebarUserMenu` の「個人設定 → /settings」は既存のまま（唯一の設定導線になる）。

### 現行コード（navItems 抜粋）
```ts
const navItems = $derived.by((): SidebarNavItem[] => {
    const org = currentOrganization;
    const items: SidebarNavItem[] = [{ href: "/dashboard", label: "ダッシュボード", icon: House }];
    if (org) items.push({ href: "/projects", label: "プロジェクト", icon: FolderKanban });
    if (org?.canManageMembers) items.push({ href: "/manage/users", label: "メンバー", icon: UserPlus });
    if (org?.canManageApiKeys) items.push({ href: `/organizations/${org.slug}/api-keys`, label: "API キー", icon: KeyRound });
    if (org) items.push({ href: "/billing", label: "請求", icon: CreditCard });
    items.push({ href: "/settings", label: "設定", icon: Settings }); // ← 二重掲載（SidebarUserMenu にもある）
    return items;
});
```

### 変更後コード
```ts
const navItems = $derived.by((): SidebarNavItem[] => {
    const org = currentOrganization;
    const items: SidebarNavItem[] = [{ href: "/dashboard", label: "ダッシュボード", icon: House }];
    if (org) items.push({ href: "/projects", label: "プロジェクト", icon: FolderKanban });
    if (org?.canManageMembers) items.push({ href: "/manage/users", label: "メンバー", icon: UserPlus });
    if (org?.canManageApiKeys) items.push({ href: `/organizations/${org.slug}/api-keys`, label: "API キー", icon: KeyRound });
    if (org) items.push({ href: "/billing", label: "請求", icon: CreditCard });
    // 設定は下部 SidebarUserMenu（個人設定）に一本化。左 nav には出さない（aigenba 準拠）。
    return items;
});
```
`Settings` icon の import が他で未使用になれば除去（lint で検出）。

### テスト計画（テストファースト: red → green）
- 先に `AppLayout.test.ts` に負例を追加し fail 確認 → 実装 → green:
  - 「ログイン時、左サイドバー nav に `nav-item-/settings` が**存在しない**」
    （`desktop().queryByTestId("nav-item-/settings")` が null）。
  - 「個人設定は下部ユーザーメニュー内の `nav-settings`（/settings）として存在する」（既存正例の維持）。

### リスク
- 低（項目 1 個削除）。設定への到達は下部メニュー経由に一本化（回帰は上記負例＋既存正例で担保）。

---

## S2: PageContent primitive 新設 + AppLayout main を padding 責務のみに

### 変更箇所
- 新規 `resources/js/components/templates/PageContent.svelte`
- `resources/js/components/templates/AppLayout.svelte`（`<main>` の責務確認：padding のみ・max-width を持たない）
- 新規 `tests/js/components/templates/PageContent.test.ts`

### 変更後コード（PageContent）
```svelte
<script lang="ts" module>
    export type PageContentMaxWidth = "xl" | "2xl" | "3xl" | "4xl" | "5xl" | "6xl" | "7xl";
</script>

<script lang="ts">
    import type { Snippet } from "svelte";
    /**
     * PageContent — 認証ページ本文の中央寄せ + max-width 制御を一元所有する layout primitive
     * (aigenba PageContent 準拠)。幅の責務はここに集約し、AppLayout の <main> は padding のみを担う。
     * ページは本文ルート(見出し含む)をこれで包み、内側の重複 max-w-* は置かない。
     */
    interface Props {
        maxWidth: PageContentMaxWidth; // 必須。指定漏れは型エラー
        children: Snippet;
    }
    let { maxWidth, children }: Props = $props();

    // union → 静的 Record で Tailwind class を解決 (任意 class 拡散を防ぐ / ds-purity 適合 / class 消失防止)
    const MAX_W: Record<PageContentMaxWidth, string> = {
        xl: "max-w-xl", "2xl": "max-w-2xl", "3xl": "max-w-3xl", "4xl": "max-w-4xl",
        "5xl": "max-w-5xl", "6xl": "max-w-6xl", "7xl": "max-w-7xl",
    };
</script>

<div class="mx-auto w-full {MAX_W[maxWidth]}" data-testid="page-content">
    {@render children()}
</div>
```
- DS-pure（layout utility のみ・任意色/hex なし）。`w-full` で狭幅 viewport でも full。`mx-auto` で中央寄せ。
- `data-testid="page-content"` を付与（代表ページ表示テストの中央寄せラッパ検出用）。

### AppLayout `<main>` の責務（変更なし・確認）
現行の `<main>` 内ラッパは `px-4 py-6 lg:px-8`（padding）+ `lg:[margin-left:var(--app-sidebar-w)]`
（サイドバーオフセット）。**max-width / mx-auto は持たない**（幅は PageContent が単独所有）。S2 では新規に
max-width を足さないことを確認（nested max-w を作らない）。

### PHPStan 適合チェック
- 該当なし（フロントのみ）。

### テスト計画（red → green）
- 先に `PageContent.test.ts` を書き fail → 実装 → green:
  - children を描画する。
  - ルート div が `mx-auto` を持つ。
  - `maxWidth` prop（例 "2xl" / "7xl"）に対しルート div が対応 class（`max-w-2xl` / `max-w-7xl`）を持つ。
  - `data-testid="page-content"` を持つ。

### リスク
- 低（純表示 primitive）。maxWidth 必須のため指定漏れは型/テストで露見。

---

## S3: 認証 23 ページを PageContent へ移行 + Architecture テストで強制

### 変更箇所
- `resources/js/pages/**` の 23 枚（下表）
- 新規 `tests/js/architecture/page-content-usage.test.ts`

### maxWidth 割当（現行実効幅を維持 = 中央寄せのみ変更）
| ページ | 現行 max-w | PageContent maxWidth |
|---|---|---|
| Billing/Index, Billing/PurchaseTickets | 3xl | **3xl** |
| Settings/Index, Settings/Security | 2xl | **2xl** |
| Projects/{Index, Show, Create, Edit} | 2xl | **2xl** |
| Manuals/{Show, Create} | 2xl | **2xl** |
| Manuals/Edit | 2xl + 4xl | **4xl**（外側; 内側の狭い区画は必要なら PageContent 内で個別 max-w 保持可） |
| Organizations/{ApiKeys/Index, ApiKeys/Sessions, Create, Settings, Onboarding/Cli, Onboarding/Mcp} | 2xl | **2xl** |
| Admin/Users, Admin/Categories | なし(全幅) | **7xl**（テーブル: 全幅相当・ultra-wide のみキャップ+中央寄せ） |
| Notifications/Index, Invitations/Accept, Capture/Index, Dashboard | なし(全幅) | **7xl**（現行全幅を維持しつつ統一+中央寄せ） |

原則: **各ページの現行実効幅は変えない**（narrow フォームは narrow のまま中央寄せ、全幅ページは 7xl で
ほぼ全幅維持）。今回の是正は「左寄せ→中央寄せ」であり、コンテンツの再設計はしない。

### 移行パターン（各ページ共通）
```svelte
<!-- before -->
<AppLayout {appName}>
    <h1 class="text-h2">…</h1>
    <div class="mt-6 flex max-w-2xl flex-col gap-10"> … </div>
</AppLayout>

<!-- after -->
<script>… import PageContent from "@/components/templates/PageContent.svelte"; …</script>
<AppLayout {appName}>
    <PageContent maxWidth="2xl">
        <h1 class="text-h2">…</h1>
        <div class="mt-6 flex flex-col gap-10"> … </div>  <!-- 内側の max-w-2xl は除去（幅は PageContent 所有） -->
    </PageContent>
</AppLayout>
```
- 見出し(h1)も PageContent 内に含め、中央寄せ列に揃える。
- 内側の重複 `max-w-*`（本文ルートの幅指定）は**除去**。ページ内の特殊な内側 max-w（画像・prose 等、
  本文幅とは別目的）は残してよいが、本文ルートの幅制御は PageContent に一本化する。
- testid / フォーム / 振る舞いは不変（表示の中央寄せのみ）。

### Architecture テスト（新規 `page-content-usage.test.ts`）
既存 `atomic-import-graph.test.ts` と同じ fs 走査 + 正規表現方式に合わせる（完全 AST は導入しない）。
```
- resources/js/pages/**/*.svelte を走査。
- 各ファイルについて「AppLayout を import しているか」を判定（import 正規表現）。
- import している & allowlist(下記)に無い ⇒ 次を assert:
    (1) PageContent を import している
    (2) テンプレートに `<PageContent` の使用が出現する（dead import 不可）
- コメント誤認回避 (Codex R4 Suggestion): 走査前に `<!-- -->` HTML コメントと `//`,`/* */` の
  script コメントを除去してから `<PageContent` を検出する（既存 arch テストのコメント除去ヘルパに合わせる）。
- soft check (採用): 移行対象ページのテンプレートに、本文ルート直下の `max-w-` 直書きが
  `<PageContent>` 経由以外で残っていないこと（top-level の競合 max-width 検出）。誤検知を避けるため
  「AppLayout 直下〜PageContent までの範囲に `max-w-` が無い」ことのみを検査対象とし、PageContent 内側の
  特殊 max-w は対象外とする。判定が困難な場合は soft check を外し review 観点に落とす（実装時に確定）。
- allowlist（max-width 非制約。理由付きで列挙）: `Capture/Show`（2 カラム grid の撮影レコーダー、ワイド意図）。
```

### テスト計画（red → green）
- 先に `page-content-usage.test.ts` を追加 → **未移行状態で fail 確認**（23 枚が落ちる）。
- 23 枚を移行 → green。
- 補助（表示回帰）: 幅バリエーション代表 3 ページの既存/新規テストで「本文が `page-content`(中央寄せラッパ)
  内に描画される」ことを確認（Settings=2xl / Billing=3xl / Manuals/Edit=4xl）。各ページ既存テストは
  testid/振る舞い不変で green を維持。

### リスク
- 移行漏れ → Architecture テストが検出（構造保証）。
- 表示崩れ → 各ページ既存テスト + 代表表示テスト + 実ブラウザ確認（verify）で担保。中央寄せは表示のみの変更。
- Manuals/Edit の 2 段 max-w は個別確認（外側 4xl + 内側区画）。

## 実装モード

| 項目 | 内容 |
|------|------|
| 推奨モード | standalone |
| 判断根拠 | AppLayout(S1/S2) + primitive 新設 + 23 ページ横断移行 + Architecture テストは密結合の一括変更で、部分適用だと arch テストが赤のまま。バックエンド変更なしで他施策と独立。 |
| 競合リスク | 低（純フロント・レイアウト）。ただし全認証ページに触れるため、同時期の他 UI 変更とはマージ順に注意。 |

---
## 関連する現行コード

### AppLayout.svelte navItems (S1 対象・現行、設定が二重掲載)
```
    const navItems = $derived.by((): SidebarNavItem[] => {
        const org = currentOrganization;
        const items: SidebarNavItem[] = [
            { href: "/dashboard", label: "ダッシュボード", icon: House },
        ];
        if (org) items.push({ href: "/projects", label: "プロジェクト", icon: FolderKanban });
        if (org?.canManageMembers)
            items.push({ href: "/manage/users", label: "メンバー", icon: UserPlus });
        if (org?.canManageApiKeys)
            items.push({
                href: `/organizations/${org.slug}/api-keys`,
                label: "API キー",
                icon: KeyRound,
            });
        if (org) items.push({ href: "/billing", label: "請求", icon: CreditCard });
        items.push({ href: "/settings", label: "設定", icon: Settings });
        return items;
    });

```
### Settings/Index.svelte 本文ルート (S3 移行対象の代表)
```
<AppLayout {appName}>
    <h1 class="text-h2">設定</h1>

    <nav aria-label="設定メニュー" class="mt-4 flex gap-4 border-b border-border pb-2">
        <TextLink href="/settings">プロフィール</TextLink>
        <TextLink href="/settings/security">セキュリティ</TextLink>
    </nav>

    <div class="mt-6 flex max-w-2xl flex-col gap-10">
        <Card padding="lg">
            <h2 class="text-h3">プロフィール</h2>
            <p class="mt-1 text-caption text-text-secondary">名前とメールアドレスを更新します。</p>
            <form onsubmit={submitProfile} class="mt-4 flex flex-col gap-4">
```
### 既存 atomic-import-graph.test.ts の走査方式 (page-content-usage.test.ts の参考)
```
import { describe, it, expect } from "vitest";
import fs from "fs/promises";
import path from "path";

/**
 * Atomic Design 階層の import 方向契約を機械保証する。
 *
 * 階層: atoms < molecules < organisms < features < templates < pages。
 * 依存方向の規則 (上層は下層を import 可、下層から上層は禁止):
 *   1. atoms      → token / util / external のみ (他コンポーネント層 import 禁止)
 *   2. molecules  → atoms + molecules(同層) + token/util/external
 *   3. organisms  → atoms + molecules + organisms(同層) + token/util/external
 *   4. features/{D} → atoms + molecules + organisms + features/{D} 自 domain のみ
 *                     (他 domain feature の横参照禁止)
 *   5. templates  → atoms + molecules + organisms + features + token/util/external
 *   6. 逆方向: いずれの component 層も pages を import 禁止
 *   7. molecules / organisms / 各 feature domain の同層 import graph は DAG (循環禁止)
 *
 * 検出: import 文 (from 'x' + side-effect import 'x') を正規表現で抽出し path から階層判定。
 * 未配置層は空集合 pass (テストファースト)。
 */

const REPO_RESOURCES_JS = path.resolve(__dirname, "../../../resources/js");
const COMPONENTS_DIR = path.join(REPO_RESOURCES_JS, "components");

const LAYER_DIRS = {
    atoms: path.join(COMPONENTS_DIR, "atoms"),
    molecules: path.join(COMPONENTS_DIR, "molecules"),
    organisms: path.join(COMPONENTS_DIR, "organisms"),
    features: path.join(COMPONENTS_DIR, "features"),
    templates: path.join(COMPONENTS_DIR, "templates"),
} as const;

const SVELTE_AND_TS: ReadonlySet<string> = new Set([".svelte", ".ts"]);

const dirExists = async (dir: string): Promise<boolean> => {
    try {
        await fs.access(dir);
        return true;
    } catch {
```
