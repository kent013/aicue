## Round 2: Round 1 指摘への対応

### [Critical] GuestLayout の `svelte-ignore a11y_click_events_have_key_events` 不要抑止 → 対応

指摘の核 (a11y 警告の blanket 抑止は負債) を受け、**svelte-ignore を消すのではなく、抑止が必要な
状況そのものを消しました**。パネル `<nav>` から onclick リスナを完全に除去し、リンク押下での
close 委譲を既存の `<svelte:window>` の `onclick` に移設しました。window は要素 a11y ルールの対象外の
ため、svelte-ignore を 1 つも残さずコンパイラ警告ゼロ (build 再実行で GuestLayout の a11y 警告消失を確認)。

- `handlePanelClick` → `handleWindowClick(event)`: `menuOpen` かつ
  `target.closest("#guest-nav-panel a")` のときだけ `closeMenu()`。
- `<svelte:window onkeydown={handleKeydown} onclick={handleWindowClick} />`
- パネル `<nav id="guest-nav-panel">` から `onclick` と両 `svelte-ignore` コメントを削除。
- リンクの Enter 押下も既定で click を発火するためキーボード操作でも閉じる (a11y 上も正当)。

トグル押下 (open) 時は target がパネル外のため close されず、パネル内リンク押下時のみ close する
挙動を確認済み (Welcome.test.ts の 4 ケース + GuestLayout.test.ts の 2 ケース全 green)。

### [Warning] `svelte:window onkeydown` の nav 未指定時常駐 (`if (!nav) return` 提案) → 見送り
両ハンドラは先頭で `!menuOpen` を early return。トグルは `{#if nav}` 配下のみ描画されるため
nav 未指定では `menuOpen` が真になり得ず、既存ガードで本体は必ず no-op。`!nav` 追加は論理的に冗長。

### [Warning] Button.types `element?: HTMLButtonElement | undefined` の明示 → 見送り
`element?:` の optional 記法が既に `| undefined` を含意。typecheck green。冗長明示は不要。

### [Warning] Welcome.test 単一ヒットを `within(header)` に → 見送り (Codex も現状許容と明記)
判定に使う "ログイン" は nav 専用リンクで footer に同名なし。

### 品質ゲート再実行結果
- pnpm build: OK (GuestLayout a11y 警告なし) / pnpm typecheck: OK / pnpm lint: OK
- pnpm test: 484 passed (69 files)

### 更新後の GuestLayout.svelte 差分

```diff
diff --git a/resources/js/components/templates/GuestLayout.svelte b/resources/js/components/templates/GuestLayout.svelte
index d2b7c1c..f925bcc 100644
--- a/resources/js/components/templates/GuestLayout.svelte
+++ b/resources/js/components/templates/GuestLayout.svelte
@@ -1,9 +1,13 @@
 <script lang="ts">
     import type { Snippet } from "svelte";
+    import { Menu, X } from "@lucide/svelte";
+    import Button from "@/components/atoms/Button.svelte";
 
     /**
      * 未認証公開ページ (LP / Pricing / Contact / Legal) 用レイアウト。
      * ヘッダーのナビとフッターのリンク群は snippet で差し込む。
+     * nav は「単純なリンク群 (<a>)」を想定する契約: 広幅ナビと狭幅パネルで二重に
+     * @render するため、状態を持つ要素・複雑な構造を snippet に入れないこと。
      */
     interface Props {
         appName: string;
@@ -13,18 +17,86 @@
     }
 
     let { appName, children, nav, footerLinks }: Props = $props();
+
+    // 狭幅 (sm 未満) のハンバーガー開閉。sm 以上は広幅ナビ表示のため未使用。
+    let menuOpen = $state(false);
+    // Escape close 時のフォーカス復帰用にトグルボタン DOM を保持
+    let toggleEl = $state<HTMLButtonElement>();
+
+    function closeMenu(): void {
+        menuOpen = false;
+    }
+
+    // Escape で閉じてトグルへフォーカスを戻す (open 時のみ作用)。
+    // 入力要素起点 (input/textarea/contenteditable) の Escape は誤クローズ防止のため無視する
+    // (nav は単純リンク群契約だが将来 snippet 逸脱に対する防御)。
+    function handleKeydown(event: KeyboardEvent): void {
+        // defaultPrevented: 他ハンドラが Escape を処理済みなら二重処理しない
+        if (event.defaultPrevented || event.key !== "Escape" || !menuOpen) return;
+        const target = event.target;
+        if (
+            target instanceof HTMLElement &&
+            target.closest("input, textarea, [contenteditable='true']")
+        ) {
+            return;
+        }
+        closeMenu();
+        toggleEl?.focus();
+    }
+
+    // パネル内リンク押下で閉じる。委譲は window 側で受ける (パネル <nav> 自体には
+    // イベントリスナを付けず a11y_click_events_have_key_events を発生させない)。
+    // リンクの Enter 押下も既定で click を発火するためキーボード操作でも閉じる。
+    function handleWindowClick(event: MouseEvent): void {
+        if (!menuOpen) return;
+        const target = event.target;
+        if (target instanceof Element && target.closest("#guest-nav-panel a")) closeMenu();
+    }
 </script>
 
+<svelte:window onkeydown={handleKeydown} onclick={handleWindowClick} />
+
 <div class="flex min-h-screen flex-col bg-neutral text-text">
     <header class="border-b border-border bg-surface">
         <div class="mx-auto flex max-w-5xl items-center justify-between px-8 py-4">
             <a href="/" class="text-h3 text-primary">{appName}</a>
             {#if nav}
-                <nav class="flex items-center gap-4 text-body">
+                <!-- 広幅 (sm+) は横並びナビ。狭幅では非表示 -->
+                <nav class="hidden items-center gap-4 text-body sm:flex">
                     {@render nav()}
                 </nav>
+                <!-- 狭幅 (sm 未満) はハンバーガー。sm+ では非表示 -->
+                <Button
+                    iconOnly
+                    variant="ghost"
+                    size="sm"
+                    ariaLabel={menuOpen ? "メニューを閉じる" : "メニューを開く"}
+                    ariaExpanded={menuOpen}
+                    ariaControls="guest-nav-panel"
+                    onclick={() => (menuOpen = !menuOpen)}
+                    bind:element={toggleEl}
+                    class="sm:hidden"
+                    testId="guest-nav-toggle"
+                >
+                    {#if menuOpen}
+                        <X class="size-5" aria-hidden="true" />
+                    {:else}
+                        <Menu class="size-5" aria-hidden="true" />
+                    {/if}
+                </Button>
             {/if}
         </div>
+        <!-- 狭幅パネル: 開いているときだけ DOM に描画。sm+ では sm:hidden で必ず非表示。
+             リンク押下での close は window の onclick 委譲で受ける (この <nav> にリスナを付けない) -->
+        {#if nav && menuOpen}
+            <nav
+                id="guest-nav-panel"
+                data-testid="guest-nav-panel"
+                class="flex flex-col gap-2 border-t border-border px-8 py-4 text-body sm:hidden"
+            >
+                {@render nav()}
+            </nav>
+        {/if}
     </header>
     <main class="mx-auto w-full max-w-5xl flex-1 px-8 py-10">
         {@render children()}
```

上記対応で全体判定を再評価してください。
