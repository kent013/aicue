## Round 2: Round 1 指摘への対応

- [Critical] page-shell-structure のコメント除去 → `//` 除去を**行頭コメント限定** `/^\s*\/\/[^\n]*$/gm` に変更。
  文字列内 (URL 等) を壊さない。arch テスト green 維持。
- [Critical] Projects/Show の categories URL 直書き → **反論**。AI-CUE は Ziggy 未導入(grep 0 件)で、FE の URL は
  文字列パス直書きが既存標準(Projects/Index の href={`/projects/${project.id}`}、OrganizationSwitcher の
  router.post(`/organizations/${id}/switch`) 等。コメントにも「Ziggy 未導入のため文字列パス直書きが既存標準」)。
  route() は BE 専用。よって文字列 href は現行標準に一致し独自逸脱ではない(変更しない)。
- [Suggestion] PageContainer コメント名称ドリフト → page-content-usage → page-shell-structure に修正。
- [Warning] PageHeaderSection の const $derived → AI-CUE 既存流儀(AppLayout 等でも const x = $derived)。変更不要。
- Admin/Users の二次メニュー不在テストは既に追加済み(admin-nav null)。

修正差分:

### page-shell-structure.test.ts (stripComments の // 行頭限定化)
```diff
diff --git a/tests/js/architecture/page-shell-structure.test.ts b/tests/js/architecture/page-shell-structure.test.ts
new file mode 100644
index 0000000..e182b34
--- /dev/null
+++ b/tests/js/architecture/page-shell-structure.test.ts
@@ -0,0 +1,112 @@
+import { describe, it, expect } from "vitest";
+import fs from "fs/promises";
+import path from "path";
+import { fileURLToPath } from "url";
+
+/*
+ * page-shell-structure — 認証ページ外枠の aigenba parity を構造保証する Architecture テスト。
+ *
+ * 契約: `AppLayout` を import するページ (ログイン後シェルを使う認証ページ) は、aigenba の統一外枠
+ *   <AppLayout><PageContainer><PageHeader|PageHeaderSection><PageContent>…
+ * に従い、layout primitive を import かつ使用する。これにより外枠(padding/見出し/中央寄せ max-w-7xl)が
+ * primitive に一元化され、ページ独自の外枠ドリフトを構造的に防ぐ。
+ *
+ * 運用規約(機械強制でない・レビュー観点): 本文標準は上記外枠。ALLOWLIST 追加は理由必須。
+ * (旧 page-content-usage.test.ts をリネーム。AdminMenuNav 等の廃止 import は deprecated-imports.test.ts。)
+ */
+
+const HERE = path.dirname(fileURLToPath(import.meta.url));
+const PAGES_DIR = path.resolve(HERE, "../../../resources/js/pages");
+
+/** PageContent 必須契約の除外 allowlist (PageContainer/PageHeader は必須)。追加は理由必須(reason 非空)。 */
+const PAGECONTENT_ALLOWLIST: ReadonlyArray<{ path: string; reason: string }> = [
+    {
+        path: "Capture/Show.svelte",
+        reason: "2 カラム grid の撮影レコーダー面。全幅のため PageContent の max-w-7xl 中央寄せを課さない。",
+    },
+];
+const PAGECONTENT_ALLOWLIST_PATHS = new Set(PAGECONTENT_ALLOWLIST.map((e) => e.path));
+
+const escapeRegExp = (s: string): string => s.replace(/[.*+?^${}()|[\]\\]/g, "\\$&");
+
+function stripComments(src: string): string {
+    return src
+        .replace(/<!--[\s\S]*?-->/g, "")
```
### PageContainer.svelte コメント修正
```diff
diff --git a/resources/js/components/templates/PageContainer.svelte b/resources/js/components/templates/PageContainer.svelte
new file mode 100644
index 0000000..4b6cfcd
--- /dev/null
+++ b/resources/js/components/templates/PageContainer.svelte
@@ -0,0 +1,22 @@
+<script lang="ts">
+    import type { Snippet } from "svelte";
+
+    /**
+     * PageContainer — page 内側の薄い padding wrapper (layout primitive, aigenba 準拠)。
+     * 認証ページの外周 padding を担う (AppLayout <main> は padding を持たない)。
+     * padding=false は PageHeaderSection の負マージン全幅バー契約を壊すため、認証ページからは使わない
+     * (Architecture テスト page-shell-structure が padding={false} を禁止)。
+     */
+    interface Props {
+        padding?: boolean;
+        children?: Snippet;
+    }
+
+    let { padding = true, children }: Props = $props();
+</script>
+
+<div class="w-full {padding ? 'px-4 py-8 sm:px-6 lg:px-8' : ''}">
+    {#if children}
+        {@render children()}
+    {/if}
+</div>
```
S1/S2/S4/BE/Feature は Round 1 で APPROVE 済み。上記反映で APPROVED になると考えます。
