# Round 2: Round 1 の指摘への対応

Round 1 の [Critical] と [Warning] は**どちらも受け入れて修正した**。反論は無い。

## 調査したこと (推測ではなく実測)

`@inertiajs/svelte` 3.3.1 の `dist/link.js` を grep したところ、`Link` が付ける `data-*` は
**読み込み中の `data-loading` だけ**で、通常時は素の `<a href>` として描画される。
したがって Round 1 のテストが見ていた `tagName === "A"` / `href` / `data-inertia === null` は
「Button に `inertia` を付ける」退行を 1 つも検出できない (実測でも green のままだった)。
指摘のとおり空振りだった。

なお `resources/js/components/atoms/Button.svelte` (本タスクでは未変更のため差分に無い) の実装は

```svelte
{#if href !== undefined && inertia}
    <Link {href} …>          <!-- @inertiajs/svelte の Link -->
{:else if href !== undefined}
    <a {href} …>             <!-- 素の anchor -->
{:else}
    <button …>
```

で `inertia` の既定値は `false`。よって現状の Error ページは native anchor になっている。
ただし「差分だけでは確認できない」という指摘自体が正しく、テストが無ければ
Button 側の既定値が変わった瞬間に契約が黙って壊れる。

## 修正内容

1. **新規** `tests/js/support/InertiaLinkStub.svelte` — 描画されると
   `data-testid="inertia-link-stub"` を残すスタブ。
2. `tests/js/pages/Error.test.ts` で `vi.mock("@inertiajs/svelte", …)` により `Link` をスタブへ差し替え、
   Error ページ描画時に**スタブが 1 つも現れないこと**を表明する。
3. **負のコントロールを併置** — `Button` を `inertia: true` で直接描画してスタブが現れることを表明する
   (mock 自体が効いていない場合の空振りを塞ぐ)。

## mutation による検証 (実施済み)

`resources/js/pages/Error.svelte` の `<Button href={destination.href}>` を
`<Button href={destination.href} inertia>` に変えて実行:

```
FAIL  tests/js/pages/Error.test.ts > pages/Error > 戻り先が通常の <a href> で描画される (Inertia Link を使わない)
Tests  1 failed | 5 passed (6)
```

mutation を戻すと 6 passed。Round 1 のテストではこの mutation が **green のまま**だったので、
指摘は正しく、修正で実際に検出できるようになった。

## 修正差分

```diff
diff --git a/tests/js/pages/Error.test.ts b/tests/js/pages/Error.test.ts
new file mode 100644
index 0000000..2518625
--- /dev/null
+++ b/tests/js/pages/Error.test.ts
@@ -0,0 +1,81 @@
+import { describe, expect, it, vi } from "vitest";
+import { render, screen } from "@testing-library/svelte";
+import Button from "@/components/atoms/Button.svelte";
+import ErrorPage from "@/pages/Error.svelte";
+import type { ErrorScreenProps } from "@/types/error-screen";
+
+/*
+ * Inertia の `Link` は素の <a href> として描画され、判別できる属性を持たない。
+ * そのため「Inertia Link ではない」を描画結果 (tagName / data-*) だけで検証すると空振りする。
+ * `Link` をスタブへ差し替え、**描画されたら印が残る**状態にして退行を確実に検出する
+ * (Codex impl-review R1 [Critical])。Error.svelte が transitively import する
+ * `@inertiajs/svelte` の使用箇所は Button の anchor モード分岐だけなので全置換で足りる。
+ */
+vi.mock("@inertiajs/svelte", async () => ({
+    Link: (await import("../support/InertiaLinkStub.svelte")).default,
+}));
+
+const baseProps: ErrorScreenProps = {
+    status: 404,
+    title: "ページが見つかりません",
+    message: "お探しのページは存在しないか、移動された可能性があります。",
+    retryAfterSeconds: null,
+    destinations: [
+        { label: "ログインへ", href: "/login" },
+        { label: "トップへ", href: "/" },
+    ],
+};
+
+describe("pages/Error", () => {
+    it("status / title / message / 戻り先を描画する", () => {
+        render(ErrorPage, { props: baseProps });
+
+        expect(screen.getByTestId("error-screen")).toBeInTheDocument();
+        expect(screen.getByTestId("error-status")).toHaveTextContent("404");
+        expect(screen.getByRole("heading", { name: "ページが見つかりません" })).toBeInTheDocument();
+        expect(screen.getByText(baseProps.message)).toBeInTheDocument();
+        expect(screen.getByRole("link", { name: "ログインへ" })).toBeInTheDocument();
+        expect(screen.getByRole("link", { name: "トップへ" })).toBeInTheDocument();
+    });
+
+    it("retryAfterSeconds が null なら待ち時間を描画しない", () => {
+        render(ErrorPage, { props: baseProps });
+
+        expect(screen.queryByTestId("error-retry-after")).toBeNull();
+    });
+
+    it("retryAfterSeconds があれば秒数を描画する", () => {
+        render(ErrorPage, {
+            props: { ...baseProps, status: 429, retryAfterSeconds: 30 },
+        });
+
+        expect(screen.getByTestId("error-retry-after")).toHaveTextContent("30");
+    });
+
+    it("戻り先が通常の <a href> で描画される (Inertia Link を使わない)", () => {
+        // 419 の原因が古い CSRF token のとき、SPA 遷移では同じ document を保つため
+        // 遷移後の POST で同じ 419 を踏み直す。document を作り直して初めて復旧する。
+        render(ErrorPage, { props: baseProps });
+
+        expect(screen.queryByTestId("inertia-link-stub")).toBeNull();
+
+        const link = screen.getByRole("link", { name: "ログインへ" });
+        expect(link.tagName).toBe("A");
+        expect(link.getAttribute("href")).toBe("/login");
+    });
+
+    it("負のコントロール: Inertia 遷移にすればスタブが描画される (上の検査が空振りでない証拠)", () => {
+        render(Button, { props: { href: "/login", inertia: true } });
+
+        expect(screen.getByTestId("inertia-link-stub")).toBeInTheDocument();
+    });
+
+    it("disabled な CTA を作らない", () => {
+        render(ErrorPage, { props: baseProps });
+
+        for (const link of screen.getAllByRole("link")) {
+            expect(link.getAttribute("aria-disabled")).toBeNull();
+            expect(link.getAttribute("tabindex")).toBeNull();
+        }
+    });
+});
diff --git a/tests/js/support/InertiaLinkStub.svelte b/tests/js/support/InertiaLinkStub.svelte
new file mode 100644
index 0000000..3312a23
--- /dev/null
+++ b/tests/js/support/InertiaLinkStub.svelte
@@ -0,0 +1,16 @@
+<script lang="ts">
+    import type { Snippet } from "svelte";
+
+    /**
+     * `@inertiajs/svelte` の `Link` を差し替えるテスト用スタブ。
+     *
+     * Inertia の `Link` は素の `<a href>` として描画され、判別できる属性を持たない
+     * (dist を確認: 付くのは読み込み中の `data-loading` だけ)。そのため
+     * 「Inertia Link ではなく通常の `<a>` である」ことを描画結果だけで検証すると**空振り**する。
+     * 本スタブを `vi.mock` で注入し、**描画されたら判別できる印**を残すことで
+     * SPA 遷移化への退行を確実に赤くする (Codex impl-review R1 [Critical])。
+     */
+    let { href, children }: { href?: string; children?: Snippet } = $props();
+</script>
+
+<a {href} data-testid="inertia-link-stub">{@render children?.()}</a>
```

## 再実行したテスト

- `pnpm vitest run tests/js/pages/Error.test.ts`: 6 passed
- `pnpm test` (全 JS): 126 files / 1236 tests passed
- `pnpm lint` / `pnpm typecheck`: OK
- `composer test` / `composer phpstan` / `vendor/bin/pint --test`: PHP 側は無変更のため再実行結果も Round 1 と同じ (green)

他に [Critical] / [Warning] が残っていれば指摘してほしい。無ければ全体判定を書いてほしい。
