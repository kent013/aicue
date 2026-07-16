## Round 2: Round 1 指摘への対応

- [Critical] Invitations/Accept 幅分類不一致 → 根因は当初監査 grep が max-w-md を見落とし「7xl」誤分類。
  実態は mx-auto max-w-md(既に中央寄せ狭幅フォーム)。対応: (1) PageContentMaxWidth union に sm/md/lg 追加、
  (2) Invitations/Accept を maxWidth="md" にし内側 mx-auto max-w-md を除去、(3) 詳細設計 割当表訂正、
  (4) 他 7xl 5ページは再監査で真の全幅と確認(変更なし)。
- [Warning] allowlist 実効性 → ALLOWLIST を {path, reason}[] 構造化し「reason 非空」を assert する
  テストを追加(空理由の無断追加を機械 fail)。
- [Warning] importsAppLayout default import 前提 → 見送り(Svelte は default import が標準、と Codex も明記)。
- テスト: 全 JS 82 files 786 passed / arch 27+ / typecheck・lint・build OK。

該当ファイルの更新差分:

### PageContent.svelte 更新差分 (union に sm/md/lg 追加)
```diff
diff --git a/resources/js/components/templates/PageContent.svelte b/resources/js/components/templates/PageContent.svelte
new file mode 100644
index 0000000..bac5799
--- /dev/null
+++ b/resources/js/components/templates/PageContent.svelte
@@ -0,0 +1,55 @@
+<script lang="ts" module>
+    /** PageContent の max-width 段 (union → 静的 Record で Tailwind class 解決)。 */
+    export type PageContentMaxWidth =
+        | "sm"
+        | "md"
+        | "lg"
+        | "xl"
+        | "2xl"
+        | "3xl"
+        | "4xl"
+        | "5xl"
+        | "6xl"
+        | "7xl";
+</script>
+
+<script lang="ts">
+    import type { Snippet } from "svelte";
+
+    /**
+     * PageContent — 認証ページ本文の中央寄せ + max-width 制御を一元所有する layout primitive
+     * (参照アプリ aigenba の PageContent 準拠)。
+     *
+     * 幅の責務はここに集約し、AppLayout の <main> は padding のみを担う (nested max-w を作らない)。
+     * 認証ページは本文ルート (見出し含む) をこれで包み、内側の重複 max-w-* は置かない。
+     * maxWidth は必須 prop (指定漏れは型エラー)。任意 class 直渡しを禁じ、union → 静的 Record で解決する
+     * (ds-purity 適合 / Tailwind class 消失防止)。
+     *
+     * 運用規約: 認証ページ本文の標準幅は "2xl"。例外 (3xl/4xl/7xl 等) は各ページで理由をもって指定する。
+     */
+    interface Props {
+        maxWidth: PageContentMaxWidth;
+        /** 既定 "page-content"。DOM 契約を固定化しないため任意化。 */
+        testId?: string;
+        children: Snippet;
+    }
+
+    let { maxWidth, testId = "page-content", children }: Props = $props();
+
+    const MAX_W: Record<PageContentMaxWidth, string> = {
+        sm: "max-w-sm",
+        md: "max-w-md",
+        lg: "max-w-lg",
+        xl: "max-w-xl",
+        "2xl": "max-w-2xl",
+        "3xl": "max-w-3xl",
+        "4xl": "max-w-4xl",
+        "5xl": "max-w-5xl",
+        "6xl": "max-w-6xl",
+        "7xl": "max-w-7xl",
+    };
+</script>
+
+<div class="mx-auto w-full {MAX_W[maxWidth]}" data-testid={testId}>
+    {@render children()}
+</div>
```
### Invitations/Accept 更新差分 (7xl → md, 内側 mx-auto max-w-md 除去)
```diff
diff --git a/resources/js/pages/Invitations/Accept.svelte b/resources/js/pages/Invitations/Accept.svelte
index e4889de..56cd482 100644
--- a/resources/js/pages/Invitations/Accept.svelte
+++ b/resources/js/pages/Invitations/Accept.svelte
@@ -3,6 +3,7 @@
     import Button from "@/components/atoms/Button.svelte";
     import Card from "@/components/atoms/Card.svelte";
     import AppLayout from "@/components/templates/AppLayout.svelte";
+    import PageContent from "@/components/templates/PageContent.svelte";
     import type { SharedProps } from "@/lib/shared-props";
 
     interface Props {
@@ -24,17 +25,19 @@
 </script>
 
 <AppLayout {appName}>
-    <div class="mx-auto mt-8 max-w-md">
-        <Card padding="lg">
-            <h1 class="text-h2">組織への招待</h1>
-            <p class="mt-3 text-body">
-                「{organizationName}」に招待されています。受諾するとこの組織のメンバーになります。
-            </p>
-            <form onsubmit={submit} class="mt-6">
-                <Button type="submit" loading={form.processing} testId="accept-invitation-button">
-                    招待を受諾する
-                </Button>
-            </form>
-        </Card>
-    </div>
+    <PageContent maxWidth="md">
+        <div class="mt-8">
+            <Card padding="lg">
+                <h1 class="text-h2">組織への招待</h1>
+                <p class="mt-3 text-body">
+                    「{organizationName}」に招待されています。受諾するとこの組織のメンバーになります。
+                </p>
+                <form onsubmit={submit} class="mt-6">
+                    <Button type="submit" loading={form.processing} testId="accept-invitation-button">
+                        招待を受諾する
+                    </Button>
+                </form>
+            </Card>
+        </div>
+    </PageContent>
 </AppLayout>
```
### page-content-usage.test.ts 更新差分 (allowlist {path,reason} + reason 非空テスト)
```diff
diff --git a/tests/js/architecture/page-content-usage.test.ts b/tests/js/architecture/page-content-usage.test.ts
new file mode 100644
index 0000000..c8f9161
--- /dev/null
+++ b/tests/js/architecture/page-content-usage.test.ts
@@ -0,0 +1,106 @@
+import { describe, it, expect } from "vitest";
+import fs from "fs/promises";
+import path from "path";
+import { fileURLToPath } from "url";
+
+/*
+ * page-content-usage — 認証ページのコンテンツ幅統一を構造保証する Architecture テスト。
+ *
+ * 契約: `AppLayout` を import するページ (= ログイン後シェルを使う認証ページ) は、本文を layout primitive
+ * `PageContent` で包む (import かつ使用) こと。これにより本文幅の中央寄せ/max-width 制御が PageContent に
+ * 一元化され、T069 で発生したような「各ページが独自 max-width を左寄せ」ドリフトを構造的に防ぐ。
+ *
+ * 運用規約 (機械強制ではない・レビュー観点):
+ *  - 認証ページ本文の標準幅は maxWidth="2xl"。例外 (3xl/4xl/7xl 等) は各ページで理由をもって指定する。
+ *  - ALLOWLIST への追加は理由コメント必須 (無理由追加禁止)。
+ */
+
+const HERE = path.dirname(fileURLToPath(import.meta.url));
+const PAGES_DIR = path.resolve(HERE, "../../../resources/js/pages");
+
+/**
+ * max-width 非制約 allowlist (PageContent を課さないページ)。
+ * 追加は `{ path, reason }` で行い、reason(理由)必須 = 空文字は機械的に fail する(無理由追加禁止)。
+ */
+const ALLOWLIST: ReadonlyArray<{ path: string; reason: string }> = [
+    {
+        path: "Capture/Show.svelte",
+        reason: "2 カラム grid の撮影レコーダー面。カメラ/カット一覧をワイドに使うため max-width 非制約。",
+    },
+];
+const ALLOWLIST_PATHS: ReadonlySet<string> = new Set(ALLOWLIST.map((e) => e.path));
+
+const escapeRegExp = (s: string): string => s.replace(/[.*+?^${}()|[\]\\]/g, "\\$&");
+
+/** HTML コメントと JS/TS コメントを除去 (コメント内の import / <PageContent> 誤認を防ぐ)。 */
+function stripComments(src: string): string {
+    return src
+        .replace(/<!--[\s\S]*?-->/g, "")
+        .replace(/\/\*[\s\S]*?\*\//g, "")
+        .replace(/(^|[^:])\/\/[^\n]*/g, "$1");
+}
+
+async function sveltePages(dir: string): Promise<string[]> {
+    const out: string[] = [];
+    for (const e of await fs.readdir(dir, { recursive: true, withFileTypes: true })) {
+        if (e.isFile() && e.name.endsWith(".svelte")) {
+            out.push(path.join(e.parentPath, e.name));
+        }
+    }
+    return out;
+}
+
+const importsAppLayout = (src: string): boolean =>
+    /import\s+\w+\s+from\s+["']@\/components\/templates\/AppLayout\.svelte["']/.test(src);
+
+/** PageContent の default import 識別子を返す (別名 import 対応)。無ければ null。 */
+function pageContentIdentifier(src: string): string | null {
+    const m = src.match(/import\s+(\w+)\s+from\s+["']@\/components\/templates\/PageContent\.svelte["']/);
+    return m ? m[1] : null;
+}
+
+describe("architecture/page-content-usage", () => {
+    it("allowlist の各エントリは理由(reason)必須 (無理由追加禁止を機械強制)", () => {
+        for (const entry of ALLOWLIST) {
+            expect(entry.reason.trim(), `allowlist "${entry.path}" は理由(reason)必須`).not.toBe("");
+        }
+    });
+
+    it("AppLayout を使う認証ページ (allowlist 除く) は PageContent を import かつ使用する", async () => {
+        const files = await sveltePages(PAGES_DIR);
+        const missingImport: string[] = [];
+        const unused: string[] = [];
+
+        for (const file of files) {
+            const rel = path.relative(PAGES_DIR, file).replace(/\\/g, "/");
+            if (ALLOWLIST_PATHS.has(rel)) continue;
+
+            const raw = await fs.readFile(file, "utf8");
+            const src = stripComments(raw);
+            if (!importsAppLayout(src)) continue;
+
+            const ident = pageContentIdentifier(src);
+            if (!ident) {
+                missingImport.push(rel);
+                continue;
+            }
+            // 開始タグをタグ名境界まで検査 (接頭辞一致 <PageContentPreview> 等を排除)。
+            const usage = new RegExp(`<${escapeRegExp(ident)}(?:\\s|/?>)`);
+            if (!usage.test(src)) unused.push(rel);
+        }
+
+        expect(
+            { missingImport, unused },
+            [
+                missingImport.length
+                    ? `PageContent import 不足 (本文を <PageContent> で包むこと):\n  - ${missingImport.join("\n  - ")}`
+                    : "",
+                unused.length
+                    ? `PageContent を import しているが未使用 (dead import。本文を <PageContent> で包むこと):\n  - ${unused.join("\n  - ")}`
+                    : "",
+            ]
+                .filter(Boolean)
+                .join("\n\n"),
+        ).toEqual({ missingImport: [], unused: [] });
+    });
+});
```
S1/S2/他ページ移行は Round 1 で APPROVE 済み。上記反映で APPROVED になると考えます。再評価をお願いします。
