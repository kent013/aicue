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
  - 「ログイン時、**desktop** 左サイドバー nav に `nav-item-/settings` が**存在しない**」
    （`desktop().queryByTestId("nav-item-/settings")` が null）。
  - 「ログイン時、**mobile** ドロワー nav にも `nav-item-/settings` が**存在しない**」（R1 対応: 両シェル固定）。
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
    export type PageContentMaxWidth =
        | "sm" | "md" | "lg" | "xl" | "2xl" | "3xl" | "4xl" | "5xl" | "6xl" | "7xl";
    // (impl-review R1: sm/md/lg を追加。Invitations/Accept の max-w-md 等、狭幅カードも表現可能にするため)
</script>

<script lang="ts">
    import type { Snippet } from "svelte";
    /**
     * PageContent — 認証ページ本文の中央寄せ + max-width 制御を一元所有する layout primitive
     * (aigenba PageContent 準拠)。幅の責務はここに集約し、AppLayout の <main> は padding のみを担う。
     * ページは本文ルート(見出し含む)をこれで包み、内側の重複 max-w-* は置かない。
     */
    // 認証ページ本文の標準幅は 2xl。例外(3xl/4xl/7xl 等)は各ページで理由をもって指定する(運用規約)。
    interface Props {
        maxWidth: PageContentMaxWidth; // 必須。指定漏れは型エラー
        testId?: string;               // 既定 "page-content"。DOM 契約を固定化しない(R1 対応)
        children: Snippet;
    }
    let { maxWidth, testId = "page-content", children }: Props = $props();

    // union → 静的 Record で Tailwind class を解決 (任意 class 拡散を防ぐ / ds-purity 適合 / class 消失防止)
    const MAX_W: Record<PageContentMaxWidth, string> = {
        xl: "max-w-xl", "2xl": "max-w-2xl", "3xl": "max-w-3xl", "4xl": "max-w-4xl",
        "5xl": "max-w-5xl", "6xl": "max-w-6xl", "7xl": "max-w-7xl",
    };
</script>

<div class="mx-auto w-full {MAX_W[maxWidth]}" data-testid={testId}>
    {@render children()}
</div>
```
- DS-pure（layout utility のみ・任意色/hex なし）。`w-full` で狭幅 viewport でも full。`mx-auto` で中央寄せ。
- **運用規約**（docblock + arch テストに明文化）: 認証ページ本文の**標準幅は 2xl**、例外(3xl/4xl/7xl)は
  理由付きで各ページ指定。`testId` は任意（既定 `"page-content"`）で DOM 契約を固定化しない。
- 表示テストは **class assertion（`mx-auto` + `max-w-*`）を主**とし、testId は補助。

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
| Notifications/Index, Capture/Index, Dashboard | なし(全幅) | **7xl**（現行全幅を維持しつつ統一+中央寄せ） |
| Invitations/Accept | **max-w-md**(既に mx-auto 中央寄せの狭幅カード) | **md**（元の狭幅を維持。impl-review R1 訂正: 当初 7xl 誤分類。監査 grep が max-w-md を見落としていた。PageContent が mx-auto max-w-md を所有し内側の重複 mx-auto max-w-md を除去） |

原則: **各ページの実効幅を実質変えない**（narrow フォームは narrow のまま中央寄せ、全幅ページは `7xl` で
**実効上ほぼ全幅を維持 = 超広幅ビューポートのみキャップ + 中央寄せ**。通常ビューポート〜1280 では見た目不変）。
今回の是正は「左寄せ→中央寄せ」であり、コンテンツの再設計はしない。

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
**識別子ベース**の検査にして固定文字列 `<PageContent` 依存を避ける（R1 Critical 対応）:
```
- resources/js/pages/**/*.svelte を走査。走査前に HTML コメント <!-- --> と script コメント //,/* */ を除去
  (Codex 推奨: コメント内 <PageContent> や import の誤認を防ぐ)。
- 各ファイルについて「AppLayout を import しているか」を判定 (import 正規表現; 既存 arch テストと同方式)。
- import している & allowlist(下記)に無い ⇒ 次を assert:
    (1) `import <IDENT> from "@/components/templates/PageContent.svelte"` の識別子 <IDENT> を capture
        (default import 名。別名 import に頑健)。無ければ「import 不足」で fail。
    (2) テンプレートに <IDENT> の**開始タグ**が出現する。接頭辞一致誤検出(識別子 `PageContent` が
        `<PageContentPreview>` に誤マッチ)を避けるため**タグ名境界まで検査**する(R2 Warning 対応):
          new RegExp(`<${escapeRegExp(identifier)}(?:\\s|/?>)`)
        (通常属性・改行・自己閉じ `/>`・空タグ `>` に対応しつつ接頭辞一致を排除)。無ければ「import は
        あるが未使用」で fail (dead import 不可)。
- 失敗メッセージは「PageContent import 不足 / import はあるが未使用」の 2 分類のみ(「allowlist 未登録」は
  import 不足と機械的に区別できないため独立分類にしない。R2 Suggestion)。
- allowlist (max-width 非制約): `Capture/Show`(2 カラム grid の撮影レコーダー面、ワイド意図)。**追加時は
  理由コメント必須・無理由追加禁止**をファイル先頭の**運用規約コメント**に明記(機械強制ではなくレビュー規約)。
- 「認証ページ本文の標準幅は 2xl・例外は理由付き」も**機械検証されない運用規約**である旨を先頭コメントに明記。
```
- **スコープ外(R1 Critical 対応)**: 「AppLayout 直下〜PageContent 間に max-w 直書きが無い」soft check は
  Svelte テンプレート上の「直下」判定が曖昧で誤検知/見逃しの両リスクがあるため**導入しない**。強制は
  「AppLayout 使用ページは PageContent を import かつ使用」までに限定し、内側 max-w 除去は移行時の実装 +
  代表表示テスト + コードレビューで担保する。

### テスト計画（red → green）
- 先に `page-content-usage.test.ts` を追加 → **未移行状態で fail 確認**（23 枚が落ちる）。
- 23 枚を移行 → green。
- 補助（表示回帰）: 幅バリエーション代表 3 ページで「本文が中央寄せラッパ（`mx-auto` + 対応 `max-w-*`、
  既定 testId `page-content`）内に描画される」ことを class assertion 主体で確認
  （Settings=2xl / Billing=3xl / **Manuals/Edit=4xl「二段 max-w 構造」**をテスト名に明記）。各ページ既存
  テストは testid/振る舞い不変で green を維持。

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
