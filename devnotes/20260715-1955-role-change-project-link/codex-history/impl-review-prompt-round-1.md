【使命】AI-CUE: SOP 起点に AI が動画シナリオ生成、PWA 撮影で標準化マニュアル動画。思考ゼロ・編集ゼロ。
【禁止事項】1 テストなし完了禁止 / 2 PHPStan widen 禁止 / 4 response()->json 直書き禁止 / 8 必須条件未充足でボタン disabled 禁止。フロントは Svelte5 runes + DS token、アイコン Lucide。
【ツール制限】コマンド実行・書き込み禁止。読み込み可。
---
あなたは Laravel + Svelte 改善実装のコードレビュアーです。観点: 設計一致 / 正確性 / PHPStan L10 / DTO・JsonResource / テスト網羅 / セキュリティ / DESIGN.md(token/hex 直書き回避) / Atomic Design(atoms/molecules/organisms 責務・Lucide・単方向 import)。出力: ファイルごと判定、[Critical]/[Warning]/[Suggestion]、Critical/Warning に修正案、全体判定 APPROVED/CHANGES_REQUESTED、日本語。

---

## 詳細設計書(要点)
Admin/Users.svelte の !hasDefaultProject 注記ブロックに projects.create への CTA を追加 (Button href="/projects/create" inertia。既存 CTA 流儀)。禁止事項#8: disabled 不使用・条件表示。純フロント (controller/route/DTO/Props 不変)。テスト: vitest でリンク表示/href/非表示・注記文言維持、backend reachability (owner/admin は GET /projects/create=200、member=403) で CTA が 403 で詰まらない不変条件を固定。
注: 設計では variant="secondary" と書いたが Button atom に secondary は無いため variant="ghost" (副次アクションの控えめ外形) で実装。href の vitest は Inertia Link が絶対 URL 化するため pathname で検証。

## 実装差分（git diff）
```diff
diff --git a/resources/js/pages/Admin/Users.svelte b/resources/js/pages/Admin/Users.svelte
index 40deb58..3a5c3ff 100644
--- a/resources/js/pages/Admin/Users.svelte
+++ b/resources/js/pages/Admin/Users.svelte
@@ -267,6 +267,17 @@
                     <p class="mt-2 text-caption text-text-secondary" data-testid="no-project-note">
                         プロジェクトがまだありません。編集者・撮影者を割り当てるには、先にプロジェクトを作成してください。
                     </p>
+                    <!-- 詰まりの文脈から 1 ホップで作成画面へ (既存 CTA 流儀 = Button href+inertia) -->
+                    <Button
+                        href="/projects/create"
+                        inertia
+                        variant="ghost"
+                        size="sm"
+                        class="mt-3"
+                        testId="create-project-link"
+                    >
+                        プロジェクトを作成
+                    </Button>
                 {/if}
                 <ul class="mt-4 flex flex-col divide-y divide-border" data-testid="member-list">
                     {#each members as member (member.id)}
diff --git a/tests/Feature/Admin/UserManagementPageTest.php b/tests/Feature/Admin/UserManagementPageTest.php
index 0ac655a..3864f78 100644
--- a/tests/Feature/Admin/UserManagementPageTest.php
+++ b/tests/Feature/Admin/UserManagementPageTest.php
@@ -51,6 +51,27 @@
     $this->actingAs($editor)->get('/manage/users')->assertForbidden();
 });
 
+// CTA 導線の到達性 (T067): ユーザー管理注記の「プロジェクトを作成」リンクが指す
+// projects.create に、ユーザー管理を見られる owner/admin は到達でき、見られない member は
+// 到達できない (403) ことを HTTP レベルで固定する。CTA が 403 で詰まらない不変条件を守る。
+test('CTA 導線: Owner/Admin は projects.create に到達できる (200)', function (): void {
+    // createOrganizationWithOwner は無償プラン (plan_code null) = 課金ゲート通過
+    [$organization, $owner] = createOrganizationWithOwner();
+    $admin = attachOrganizationMember($organization, OrganizationRole::Admin);
+    $admin->forceFill(['current_organization_id' => $organization->id])->save();
+
+    $this->actingAs($owner)->get('/projects/create')->assertOk();
+    $this->actingAs($admin)->get('/projects/create')->assertOk();
+});
+
+test('CTA 導線: org Member は projects.create で 403 (権限境界が非退化)', function (): void {
+    [$organization] = createOrganizationWithOwner();
+    $member = attachOrganizationMember($organization, OrganizationRole::Member);
+    $member->forceFill(['current_organization_id' => $organization->id])->save();
+
+    $this->actingAs($member)->get('/projects/create')->assertForbidden();
+});
+
 test('未ログインは login へ redirect される', function (): void {
     $this->get('/manage/users')->assertRedirect('/login');
 });
diff --git a/tests/js/pages/AdminUsers.test.ts b/tests/js/pages/AdminUsers.test.ts
index dbe864e..4fb48ee 100644
--- a/tests/js/pages/AdminUsers.test.ts
+++ b/tests/js/pages/AdminUsers.test.ts
@@ -278,6 +278,27 @@ describe("Admin/Users", () => {
         expect(screen.queryByTestId("admin-nav-categories")).toBeNull();
     });
 
+    it("project 不在時は projects.create への作成リンクを出す (href 正しい・注記文言維持)", () => {
+        render(Users, {
+            props: { ...baseProps, hasDefaultProject: false, categoriesUrl: null },
+        });
+
+        const link = screen.getByTestId("create-project-link");
+        // Inertia Link は href を絶対 URL に正規化するため pathname で検証する
+        const href = link.getAttribute("href") ?? "";
+        expect(new URL(href, "http://localhost").pathname).toBe("/projects/create");
+        // 既存注記の文言は維持
+        expect(screen.getByTestId("no-project-note")).toHaveTextContent(
+            "編集者・撮影者を割り当てるには、先にプロジェクトを作成してください。",
+        );
+    });
+
+    it("project 在時は作成リンクを出さない", () => {
+        render(Users, { props: { ...baseProps, hasDefaultProject: true } });
+
+        expect(screen.queryByTestId("create-project-link")).toBeNull();
+    });
+
     it("メンバー行はモバイル縦積みクラスを持ち、操作ブロックは flex-wrap を持つ (F-14)", () => {
         // jsdom はレイアウト計算をしないため、クラス不変条件を横スクロール回避のプロキシとして固定する。
         // 対象要素は data-testid 起点で特定し DOM 順序に依存しない。
```

## テスト結果
- AdminUsers.test.ts: 21 passed (link 表示/href pathname/非表示、既存維持)。fail-first 確認済 (実装前は link test fail)
- UserManagementPageTest: 10 passed (reachability 2 件追加)
- composer test 全体: 1791 passed / 2 skipped / 0 failed
- pnpm test 全体: 778 passed / typecheck / lint / build: OK
- composer phpstan: No errors / pint: passed

## design system 参照
- Button atom (atoms) を pages から利用 = 単方向 import。variant="ghost" (VARIANT_CLASSES に定義済、DS token: border-border-strong/text-text/hover:border-primary)。hex 直書きなし。アイコンなし (テキストのみ)。href+inertia で Inertia Link を描画。
- 既存 no-project-note の <p> は testid/文言とも不変、Button を兄弟として追加 (DOM 親は Card のまま)。
