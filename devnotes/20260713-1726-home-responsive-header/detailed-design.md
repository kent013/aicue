# 詳細設計: home-responsive-header

## 使命・制約（絶対遵守）

### アプリの使命（North Star）

**AI-CUE** は、現場に既にある**作業手順書(SOP)を起点に**、AI が撮るべきカットを設計した
**動画シナリオ**を生成し、そのシナリオを**スマホ(PWA)でナビゲーション撮影**することで、
専門知識ゼロの現場作業者でも**標準化されたマニュアル動画**を作れるようにする。

- 「思考ゼロ・編集ゼロ」— 台本作成・撮影判断・編集の 3 ハードルを AI とナビ撮影で肩代わりする。
- 競合(tebiki)と異なり、**標準作業を起点に AI が教材設計し撮影を指示する**。
- 熟練者の暗黙知を動画マニュアルという形式知へ変換する装置（SECI）。

本 finding は LP/料金の**入口体験（第一印象）**の破綻を除く。North Star が想定する
「専門知識ゼロの現場作業者」が最初に触れる面の可読性を回復するもの。

### 禁止事項（AGENTS.md 正本）

1. テストなしの実装完了報告
2. PHPStan エラーの widen・baseline 化
3. dev DB への破壊操作
4. `response()->json()` の直書き（DTO / JsonResource / Inertia を使う）
5. LLM 呼び出しの Prism 直呼び
6. prompt 文字列のコード直書き
7. 操作系 POST 応答での `redirect()->intended()`
8. 必須条件未充足を理由にボタンを disabled にする UI

> 本施策は**純フロントエンド変更**。1・8 のみ関係（テスト必須 / disabled 不使用）。
> 2・4・5・6・7 は PHP/バックエンド禁止事項で本施策に非該当（PHP コードを一切触らない）。

### コーディングルール

- **PHPStan level 10**: PHP 変更なしのため影響なし（既存 green を維持）。
- **Pest**: PHP テスト変更なし。フロントは **vitest + @testing-library/svelte**（`pnpm test`）。
- テストデータは Factory 前提（本施策は DB 非依存のため該当なし）。
- **DTO + JsonResource**: バックエンド変更なしのため該当なし。
- フロントは **Svelte 5 runes + DESIGN.md token/ramp のみ**。アイコンは `@lucide/svelte` のみ。
- component 階層 `atoms → … → templates → pages` の単方向 import。
- 検証: `pnpm typecheck` / `pnpm lint` / `pnpm build` / `pnpm test`（全 green でコミット）。

## 概念設計リファレンス

- [conceptual-design.md](./conceptual-design.md)（Codex `gpt-5.4` レビュー round-4 で APPROVED）

## 施策一覧

| # | 施策名 | 変更ファイル | 優先度 |
|---|--------|------------|--------|
| 1 | Button atom に disclosure 用 props（`ariaExpanded` / `ariaControls` / `element` bindable）を最小追加 | `resources/js/components/atoms/Button.types.ts`, `resources/js/components/atoms/Button.svelte`, `DESIGN.md` | High |
| 2 | GuestLayout ヘッダーを狭幅ハンバーガー化（広幅ナビ + トグル + 展開パネル） | `resources/js/components/templates/GuestLayout.svelte` | High |
| 3 | テスト追加・回帰確認（Button atom / GuestLayout 経由の Welcome / GuestLayout 単体） | `tests/js/components/atoms/Button.test.ts`, `tests/js/pages/Welcome.test.ts`, `tests/js/components/templates/GuestLayout.test.ts`（新規） | High |

> ページ側 `Welcome.svelte` / `Pricing.svelte` の `nav` snippet は**変更しない**
> （共通根 GuestLayout の 1 箇所修正で両ページが同時に狭幅対応する）。

---

## 施策 1: Button atom に disclosure 用 props を最小追加

### 背景
現行 `Button.svelte` は明示 prop のみ受け取り `...rest` を持たない。よって
`aria-expanded` / `aria-controls` / DOM 参照を渡す口が無い。素の `<button>` 手書きは
DESIGN.md §Do's and Don'ts で禁止のため、トグルに Button atom を使うには atom を拡張する。

### 変更箇所
- `resources/js/components/atoms/Button.types.ts`（`ModeProps` の **button モード分岐**）
- `resources/js/components/atoms/Button.svelte`（script props + `<button>` 分岐のみ）
- `DESIGN.md`（§Components > Button に disclosure props の 1 行追記）

### 波及変更
- **TypeScript 型定義**: `Button.types.ts` の button モード union member に
  `ariaExpanded?: boolean` / `ariaControls?: string` / `element?: HTMLButtonElement` を追加。
  anchor / inertia モードには追加しない（disclosure と DOM ref は `<button>` 専用）。
- **API Resource/DTO**: なし（フロントのみ）。
- **テストファイル**: `tests/js/components/atoms/Button.test.ts` に新 props のテストを追加（施策 3）。

### 現行コード（`Button.types.ts` ModeProps 抜粋）
```ts
type ModeProps =
    | {
          href?: never; inertia?: never; target?: never; rel?: never;
          type?: "button" | "submit" | "reset";
          disabled?: boolean;
          onclick?: (event: MouseEvent) => void;
      }
    | { href: string; inertia?: boolean; target?: "_blank" | "_self"; rel?: string;
        type?: never; disabled?: never; onclick?: (event: MouseEvent) => void; };
```

### 変更後コード（`Button.types.ts`）
```ts
type ModeProps =
    | {
          href?: never; inertia?: never; target?: never; rel?: never;
          type?: "button" | "submit" | "reset";
          disabled?: boolean;
          onclick?: (event: MouseEvent) => void;
          /** disclosure ボタン用。トグルの開閉状態を aria-expanded で公開する */
          ariaExpanded?: boolean;
          /** disclosure ボタンが制御する要素の id（aria-controls） */
          ariaControls?: string;
          /** フォーカス制御用の DOM 参照（bindable, button モード限定・具体型を維持） */
          element?: HTMLButtonElement;
      }
    | { href: string; inertia?: boolean; target?: "_blank" | "_self"; rel?: string;
        type?: never; disabled?: never; onclick?: (event: MouseEvent) => void;
        /** anchor モードでは disclosure props を型で禁止しつつ分割代入を可能にする */
        ariaExpanded?: never; ariaControls?: never; element?: never;
      };
```

> **重要（Codex design-review round-2 施策1 Critical 対応）**: anchor モード union member にも
> `ariaExpanded?: never; ariaControls?: never; element?: never;` を**必ず追加**する。
> これがないと `Button.svelte` の `let { ..., ariaExpanded, ariaControls, element } = $props()`
> 分割代入が union の両メンバーに存在しないプロパティ参照となり TypeScript エラーになりうる。
> `never` 補完で「分割代入可能 + anchor モードでの誤用を型で禁止」を両立する。

### 変更後コード（`Button.svelte`）
```svelte
let {
    variant = "primary", size = "md", fullWidth = false, loading = false,
    iconOnly = false, ariaLabel, href, inertia = false, target, rel,
    type = "button", disabled = false, onclick, testId,
    ariaExpanded,                       // 追加（button モードのみ有効。anchor では undefined）
    ariaControls,                       // 追加
    element = $bindable<HTMLButtonElement | undefined>(undefined), // 追加
    class: extraClass = "", children,
}: ButtonProps = $props();
```
`<button>` 分岐のみ以下を付与（anchor / inertia 分岐は不変）:
```svelte
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
```

さらに、既存の `iconOnly` DEV 警告 `$effect` に倣い、**disclosure props を anchor モードで
誤用したときの DEV 専用警告**を 1 本追加する（型で防げるが JS 利用時の混入を検知する。
Codex round-1 施策1 Warning 対応）:
```svelte
$effect(() => {
    if (
        import.meta.env.DEV &&
        href !== undefined &&
        (ariaExpanded !== undefined || ariaControls !== undefined)
    ) {
        console.warn("[Button] ariaExpanded / ariaControls は button モード (href なし) 専用です");
    }
});
```

### PHPStan 適合チェック
- [x] PHP 変更なし（該当なし。既存 PHPStan level 10 green を維持）

### 型安全性チェック（TS / Svelte）
- [x] `element` は `HTMLButtonElement | undefined` の具体型で保持（widen しない）
- [x] `ariaExpanded` は `boolean?`、`ariaControls` は `string?`
- [x] button モード union member にのみ追加（anchor モードで `element` 等が使えないことを型で保証）
- [x] `aria-expanded={undefined}` は Svelte が属性を出力しない（既存 button 用途に無影響）

### テスト計画
- [ ] `Button.test.ts`: `ariaExpanded` / `ariaControls` 指定時に `<button>` へ属性が出る
- [ ] `Button.test.ts`: 未指定時に `aria-expanded` / `aria-controls` 属性が出ない（既存 button 用途の回帰）
- [ ] `Button.test.ts`: `bind:element` で `HTMLButtonElement` が取得でき `.focus()` できる
      （フォーカス復帰の実挙動は施策3 の GuestLayout 経由 Escape ケースで固定）
- [ ] （任意 / DEV 警告）href モードで `ariaExpanded` を渡すと `console.warn` が出る
- [ ] 既存 `Button.test.ts` 全ケース green（型追加のみでランタイム分岐は増やさない）

### リスク
- 低。button モードにオプショナル props を足すのみ。既存呼び出しは全て未指定 = 無影響。
  anchor モードには追加しないため OAuth/外部リンク等の Button 呼び出しに一切影響しない。

---

## 施策 2: GuestLayout ヘッダーを狭幅ハンバーガー化

### 変更箇所
- `resources/js/components/templates/GuestLayout.svelte`（ヘッダー全体 + script）

### 波及変更
- **TypeScript 型定義**: `Props` は不変（`nav?: Snippet` のまま）。JSDoc に「nav は単純な
  リンク群を想定」の契約コメントを追記。
- **API Resource/DTO**: なし。
- **テストファイル**: `tests/js/pages/Welcome.test.ts` に狭幅トグル挙動を追加（施策 3）。
- **利用ページ**: `Welcome.svelte` / `Pricing.svelte`（nav あり）は無変更で狭幅対応。
  `Contact/Index` / `Contact/Thanks`（nav なし）は `{#if nav}` によりヘッダー右側が
  現状どおり空のまま（トグルも出ない）。

### 現行コード
```svelte
<script lang="ts">
    import type { Snippet } from "svelte";
    interface Props { appName: string; children: Snippet; nav?: Snippet; footerLinks?: Snippet; }
    let { appName, children, nav, footerLinks }: Props = $props();
</script>
<div class="flex min-h-screen flex-col bg-neutral text-text">
    <header class="border-b border-border bg-surface">
        <div class="mx-auto flex max-w-5xl items-center justify-between px-8 py-4">
            <a href="/" class="text-h3 text-primary">{appName}</a>
            {#if nav}
                <nav class="flex items-center gap-4 text-body">{@render nav()}</nav>
            {/if}
        </div>
    </header>
    ...
```

### 変更後コード
```svelte
<script lang="ts">
    import type { Snippet } from "svelte";
    import { Menu, X } from "@lucide/svelte";
    import Button from "@/components/atoms/Button.svelte";

    /**
     * 未認証公開ページ (LP / Pricing / Contact / Legal) 用レイアウト。
     * nav は「単純なリンク群 (<a>)」を想定する契約: 広幅ナビと狭幅パネルで二重に
     * @render するため、状態 full 要素・複雑構造を snippet に入れないこと。
     */
    interface Props { appName: string; children: Snippet; nav?: Snippet; footerLinks?: Snippet; }
    let { appName, children, nav, footerLinks }: Props = $props();

    // 狭幅 (sm 未満) のハンバーガー開閉。sm 以上は広幅ナビ表示のため未使用。
    let menuOpen = $state(false);
    // Escape close 時のフォーカス復帰用にトグルボタン DOM を保持
    let toggleEl = $state<HTMLButtonElement>();

    function closeMenu(): void { menuOpen = false; }

    // Escape で閉じてトグルへフォーカスを戻す (nav 有り・open 時のみ作用)。
    // 入力要素起点 (input/textarea/contenteditable) の Escape は誤クローズ防止のため無視する
    // (nav は単純リンク群契約だが将来 snippet 逸脱に対する防御。Codex round-1 施策2 Critical 対応)
    function handleKeydown(event: KeyboardEvent): void {
        // defaultPrevented: 他ハンドラが Escape を処理済みなら二重処理しない
        if (event.defaultPrevented || event.key !== "Escape" || !menuOpen) return;
        const target = event.target;
        if (target instanceof HTMLElement && target.closest("input, textarea, [contenteditable='true']")) {
            return;
        }
        closeMenu();
        toggleEl?.focus();
    }

    // パネル内リンク押下で閉じる (イベント委譲: <a> 起点のクリックのみ)。
    // target が Element でない可能性を narrowing (Codex round-1 施策2 Warning 対応)
    function handlePanelClick(event: MouseEvent): void {
        const target = event.target;
        if (target instanceof Element && target.closest("a")) closeMenu();
    }
</script>

<svelte:window onkeydown={handleKeydown} />

<div class="flex min-h-screen flex-col bg-neutral text-text">
    <header class="border-b border-border bg-surface">
        <div class="mx-auto flex max-w-5xl items-center justify-between px-8 py-4">
            <a href="/" class="text-h3 text-primary">{appName}</a>
            {#if nav}
                <!-- 広幅 (sm+) は横並びナビ。狭幅では非表示 -->
                <nav class="hidden items-center gap-4 text-body sm:flex">
                    {@render nav()}
                </nav>
                <!-- 狭幅 (sm 未満) はハンバーガー。sm+ では非表示 -->
                <Button
                    iconOnly
                    variant="ghost"
                    size="sm"
                    ariaLabel={menuOpen ? "メニューを閉じる" : "メニューを開く"}
                    ariaExpanded={menuOpen}
                    ariaControls="guest-nav-panel"
                    onclick={() => (menuOpen = !menuOpen)}
                    bind:element={toggleEl}
                    class="sm:hidden"
                    testId="guest-nav-toggle"
                >
                    {#if menuOpen}
                        <X class="size-5" aria-hidden="true" />
                    {:else}
                        <Menu class="size-5" aria-hidden="true" />
                    {/if}
                </Button>
            {/if}
        </div>
        <!-- 狭幅パネル: 開いているときだけ DOM に描画。sm+ では sm:hidden で必ず非表示 -->
        {#if nav && menuOpen}
            <nav
                id="guest-nav-panel"
                data-testid="guest-nav-panel"
                class="flex flex-col gap-2 border-t border-border px-8 py-4 text-body sm:hidden"
                onclick={handlePanelClick}
            >
                {@render nav()}
            </nav>
        {/if}
    </header>
    ...（main / footer は現行不変）
```

### 設計上の要点
- **ブレークポイント境界 = `sm` (640px)**: `375px`=ハンバーガー / `768px`=横並び を満たす。
  既存レスポンシブ (`sm:grid-cols-*` 等) と整合。
- **広幅表示保証**: 展開パネルは `{#if menuOpen}`（DOM 制御）に加え `sm:hidden`（表示制御）。
  CSS ブレークポイントは state を変えないため、広幅では **menuOpen の値によらず必ず非表示**。
- **閉時に DOM 非描画**: `{#if nav && menuOpen}` により既定 (closed) では panel を描画しない。
  jsdom は `hidden sm:flex` を実際には隠さないため、**closed 時にナビリンクが二重ヒットしない**
  ことを保証し、既存 `Welcome.test.ts` の `getByRole("link", ...)` を壊さない。
- **リサイズ監視なし**: 「狭幅で開く→広幅→狭幅」で開状態が残る挙動は許容（実害小・監視追加は
  オーバーエンジニアリング）。広幅では `sm:hidden` で見た目は必ず正しい。
- **二重 @render の安全性**: `nav` は単純リンク群契約。広幅=横 (`sm:flex`)・狭幅パネル=縦
  (`flex-col`) をコンテナ側 flex-direction で出し分け、snippet 本体は不変。
- **a11y**: トグルに `aria-label`（開閉で文言切替）・`aria-expanded`・`aria-controls`。
  パネル `id="guest-nav-panel"`。Escape で閉じてトグルへ focus 復帰。パネル内リンク押下で閉じる。
- **DESIGN.md 準拠**: 影・グラデーション・scale 不使用。色は既存 token
  (`bg-surface` / `border-border` / `text-primary` 等)。角丸は追加しない（パネルは border 区切り）。
  アイコンは Lucide `Menu` / `X`。

### PHPStan 適合チェック
- [x] PHP 変更なし（該当なし）

### 型安全性チェック（TS / Svelte）
- [x] `toggleEl` は `HTMLButtonElement | undefined`（`$state<HTMLButtonElement>()`）で具体型
- [x] `menuOpen` は `boolean`
- [x] `handleKeydown` / `handlePanelClick` は具体イベント型（`KeyboardEvent` / `MouseEvent`）
- [x] `bind:element={toggleEl}` は施策 1 で追加した bindable と型整合
- [x] `event.target as HTMLElement` の narrowing は `.closest("a")` 判定のみに限定

### テスト計画（施策 3 で実装）
- [ ] closed 既定でナビリンクが単一ヒット（既存 Welcome.test.ts が壊れないこと）
- [ ] `guest-nav-toggle` 押下で `guest-nav-panel` が現れる / `aria-expanded` が true になる
- [ ] `Escape` でパネルが閉じる
- [ ] パネル内リンク押下でパネルが閉じる
- [ ] `nav` を渡さないケースでトグル・パネルが出ない（Contact 相当）
- [ ] 個別 `DatabaseTransactions` 不使用（フロントテストのため該当なし）

### リスク
- 低〜中。既存 GuestLayout の 4 ページに影響するが、広幅の見た目は現行維持（`hidden sm:flex`
  は既存 `flex` と同じ表示）。closed 時に DOM 構造が増えないため既存テスト回帰リスクは限定的。
- `svelte:window onkeydown` は全ページ常設だが、`menuOpen` が false のとき即 return するため
  他のキー操作への副作用なし。

---

## 施策 3: テスト追加・回帰確認

### 変更箇所
- `tests/js/components/atoms/Button.test.ts`（disclosure props）
- `tests/js/pages/Welcome.test.ts`（GuestLayout 経由の狭幅トグル挙動）
- `tests/js/components/templates/GuestLayout.test.ts`（新規: nav 未指定の回帰）

### 波及変更
- TypeScript 型定義 / API Resource/DTO: なし。

### Button.test.ts（追加ケース）
```ts
it("ariaExpanded / ariaControls を <button> に反映する", () => {
    render(Button, { props: { ariaExpanded: true, ariaControls: "panel-x", testId: "t" } });
    const btn = screen.getByTestId("t");
    expect(btn).toHaveAttribute("aria-expanded", "true");
    expect(btn).toHaveAttribute("aria-controls", "panel-x");
});

it("disclosure props 未指定なら aria-expanded / aria-controls を出さない", () => {
    render(Button, { props: { testId: "t" } });
    const btn = screen.getByTestId("t");
    expect(btn).not.toHaveAttribute("aria-expanded");
    expect(btn).not.toHaveAttribute("aria-controls");
});
```
> `element` bindable の `.focus()` は GuestLayout 経由（Welcome.test.ts の Escape ケース）で
> 実挙動を検証するため、atom 単体では属性の有無に絞る（bindable の単体検証は snippet 準備が
> 重く費用対効果が低い）。

### Welcome.test.ts（追加ケース、要点）
```ts
import { fireEvent, render, screen, within } from "@testing-library/svelte";

it("既定ではモバイルパネルを描画せずナビリンクが単一ヒットする", () => {
    render(Welcome, { props: baseProps });
    expect(screen.queryByTestId("guest-nav-panel")).not.toBeInTheDocument();
    // 広幅ナビのみ = 単一ヒット（hidden sm:flex は jsdom で DOM に残るが二重化しない）
    expect(screen.getByRole("link", { name: "料金プラン" })).toBeInTheDocument();
});

it("ハンバーガー押下でモバイルパネルが開き aria-expanded が切り替わる", async () => {
    render(Welcome, { props: baseProps });
    const toggle = screen.getByTestId("guest-nav-toggle");
    expect(toggle).toHaveAttribute("aria-expanded", "false");
    await fireEvent.click(toggle);
    expect(toggle).toHaveAttribute("aria-expanded", "true");
    const panel = screen.getByTestId("guest-nav-panel");
    expect(within(panel).getByRole("link", { name: "ログイン" })).toBeInTheDocument();
});

it("Escape でモバイルパネルが閉じ、トグルにフォーカスが戻る", async () => {
    render(Welcome, { props: baseProps });
    const toggle = screen.getByTestId("guest-nav-toggle");
    await fireEvent.click(toggle);
    await fireEvent.keyDown(window, { key: "Escape" });
    expect(screen.queryByTestId("guest-nav-panel")).not.toBeInTheDocument();
    // element bindable によるフォーカス復帰を回帰固定（Codex round-1 施策3 Critical 対応）
    expect(toggle).toHaveFocus();
});

it("パネル内リンク押下でパネルが閉じる", async () => {
    render(Welcome, { props: baseProps });
    await fireEvent.click(screen.getByTestId("guest-nav-toggle"));
    const panel = screen.getByTestId("guest-nav-panel");
    await fireEvent.click(within(panel).getByRole("link", { name: "料金プラン" }));
    expect(screen.queryByTestId("guest-nav-panel")).not.toBeInTheDocument();
});
```
> **重要**: パネルを開くと広幅ナビ (DOM に残存) とパネルの両方にリンクが出るため、
> open 後のリンク取得は `within(panel)` でスコープする。closed 前提の既存アサーション
> （`getByRole("link", ...)` 単一ヒット）は変更しない。

### GuestLayout.test.ts（新規・nav 未指定の回帰）

Codex round-1 施策3 Warning 対応: 「構造的保証」で済ませず、`nav` 未指定で
トグル・パネルが出ないことを**専用テスト**で固定する。GuestLayout を直接レンダリングし、
`children` snippet は `createRawSnippet` で最小生成する（`nav` は渡さない）。
```ts
import { describe, expect, it } from "vitest";
import { createRawSnippet } from "svelte";
import { render, screen } from "@testing-library/svelte";
import GuestLayout from "@/components/templates/GuestLayout.svelte";

const children = createRawSnippet(() => ({ render: () => `<p>content</p>` }));

it("nav を渡さないとハンバーガー・パネルを描画しない (Contact 相当)", () => {
    render(GuestLayout, { props: { appName: "AI-CUE", children } });
    expect(screen.queryByTestId("guest-nav-toggle")).not.toBeInTheDocument();
    expect(screen.queryByTestId("guest-nav-panel")).not.toBeInTheDocument();
});
```
> `nav` を渡すケース（トグル出現）も同ファイルで `createRawSnippet` により
> リンク群を生成して補強してよいが、実挙動の主検証は Welcome.test.ts 側が担う。

### PHPStan 適合チェック
- [x] PHP 変更なし（該当なし）

### テスト計画
- [ ] `pnpm test`（vitest）green
- [ ] `pnpm typecheck` green（Button 型追加・GuestLayout の bind:element 型整合）
- [ ] `pnpm lint` green
- [ ] `pnpm build` green
- [ ] 既存アーキテクチャテスト green: `atomic-import-graph` / `lucide-scoped-import`
      （`Menu` / `X` は `@lucide/svelte` scoped import）/ `svg-inline-allowlist`（SVG 直書きなし）/
      `ds-purity` / `shape-ramp-purity` / `typography-invariant`

### リスク
- 低。open 状態のリンク二重化を `within(panel)` で回避する点さえ守れば安定。

---

## 実装モード

| 項目 | 内容 |
|------|------|
| 推奨モード | incremental |
| 判断根拠 | 既存 template (GuestLayout) と atom (Button) への追記中心で、新規ファイル・新規ドメインを作らない。段階的に施策 1→2→3 を積み上げ、各段で `pnpm typecheck/lint/test/build` を回して回帰を早期検知できる。 |
| 競合リスク | 低。変更ファイルは Button.svelte / Button.types.ts / GuestLayout.svelte / DESIGN.md（Button 節）/ テスト 2 本に限定。他の進行中施策が同ファイルを触らない限り衝突しない。 |

## 使命・禁止事項 最終チェック

- [x] 全施策が使命（入口体験の可読性回復）に寄与する
- [x] 禁止事項に違反しない（PHP 変更なし。テスト必須を満たす。disabled UI を作らない）
- [x] コーディングルール反映: PHPStan（非該当）/ vitest テスト必須 / DESIGN.md token・ramp /
      Lucide アイコン / Atomic Design 単方向 import / DTO（非該当）
