# 使命・禁止事項・思考原則・ツール使用制限

## アプリの使命 (North Star)

**AI-CUE** は、現場に既にある**作業手順書(SOP)を起点に**、AI が撮るべきカットを設計した**動画シナリオ**を生成し、そのシナリオを**スマホ(PWA)でナビゲーション撮影**することで、専門知識ゼロの現場作業者でも**標準化されたマニュアル動画**を作れるようにする。

- 「思考ゼロ・編集ゼロ」— 台本作成・撮影判断・編集の 3 ハードルを AI とナビ撮影で肩代わりする。
- 競合(OJT を撮って形式化する tebiki)と異なり、**標準作業を起点に AI が教材設計し撮影を指示する**。
- 熟練者の暗黙知を動画マニュアルという形式知へ変換する装置（SECI）。

## 禁止事項

1. テストなしの実装完了報告
2. PHPStan エラーの widen・baseline 化
3. dev DB への破壊操作をエージェント判断で実行すること
4. `response()->json()` の直書き（DTO / JsonResource / Inertia を使う）
5. LLM 呼び出しの Prism 直呼び
6. prompt 文字列のコード直書き
7. 操作系 POST の応答での `redirect()->intended()`
8. **必須条件未充足を理由にボタンを disabled にする UI**（押下時にエラー表示する。DESIGN.md）

## 思考原則 — 全議論に適用

まず仮説を立てろ。データに真摯に向き合え。先人の知恵を探せ。機能の名前に立ち返れ。仕組みが機能していない段階で値を弄るな。

## ツール使用制限

コマンド実行・ファイル書き込みは一切行わず、提供されたテキストの分析に集中すること。ファイル読み込みは許可。

---

# system: レビュアーの役割

あなたは Laravel + Svelte 5 (runes) + Inertia + TypeScript アプリの実装レビュアーである。本 diff は **client-side stale validation の横展開修正 (T044, topic=stale-validation-sweep)**。フロントのみ・PHP 変更なし。

## 背景 (バグと修正方針)

既存の 2 つのフォーム (オーナー移譲 select / プロジェクトメンバー追加 select) は、client precheck 失敗時に `useForm().setError("user_id", ...)` を呼び、サーバ validation と**共有の** `form.errors` bag に client エラーを書き込んでいた。このため「無効値で押下 → エラー表示 → 有効値を選び直しても文言・aria-invalid が残る (stale)」問題があった。

修正方針 (T041/T033 で確立済みの横展開イディオム):
1. client precheck 専用の transient `$state<string | null>` を分離し、`setError` をこの state 代入に置換 (serverErrors 経路は不変 = 非退行)。
2. 表示は client 優先の null 合体 `error={clientError ?? form.errors.user_id}`。FormField が `invalid = Boolean(error)` を導出し Select の `aria-invalid` に連動。
3. `$effect` で「precheck 合格条件 (isValid) に復帰したときだけ」client error をクリア (過剰クリア防止・「押下時にエラー表示」契約=禁止事項8 を維持)。

## レビュー観点

- 設計との一致性 (下記詳細設計と diff の整合)
- 正確性 ($effect の無限ループ回避・stale 解消の正しさ・過剰クリア防止・serverErrors 非退行)
- TypeScript 適合性 (`pnpm typecheck` は green 済)
- テスト網羅性 (stale 解消 / 過剰クリア防止 / serverErrors 非退行 の 3 観点を実際にクリア分岐を通して検証しているか)
- 禁止事項 8 (disabled 化していないか)
- DESIGN.md / Atomic Design (本 diff は pages 層のロジック追加のみ・token/import 不変更)

## 出力形式

ファイルごとに判定し、指摘を Critical / Warning / Suggestion に分類。最後に全体判定を **APPROVED** または **CHANGES_REQUESTED** で明示せよ。

---

# user: データ

## 詳細設計書 (要点)

- 施策1: `resources/js/pages/Organizations/Settings.svelte` — `transferClientError` を導入。`isValidTransferTarget = 候補に選択値が実在` derived。`$effect`: `transferClientError !== null && isValidTransferTarget` でクリア。候補0人エラーは isValidTransferTarget が常に false のため残留 (選択で直せない=正しい)。`onFinish` で `transferClientError = null` (再mountしないライフサイクルの stale 除去)。表示は `transferClientError ?? transferForm.errors.user_id`。
- 施策2: `resources/js/pages/Projects/Show.svelte` — `addMemberClientError` を導入。`isAddMemberSelected = memberForm.user_id !== ""` derived。`$effect` で選択が入ったらクリア。`onSuccess` の `memberForm.reset()` 後に `addMemberClientError = null`。表示は `addMemberClientError ?? memberForm.errors.user_id`。
- 施策3/4: 各テストに (a) stale 解消(有効値復帰) (b) 過剰クリア防止(無効値維持の単一条件) (c) serverErrors 非退行($effect のクリア分岐を実際に通し、client error クリア後に背後のサーバエラーが再表示されることを明示アサート) の 3 ケースを追加。serverErrors 非退行は `router.post` mock で `opts.onError({user_id: serverMsg})` を呼び、useForm 内部 onError (`form.clearErrors().setError`) 経由で実 `form.errors.user_id` にサーバエラーを載せる。

共通原則: select value は文字列前提 (`String(id)` 比較)。serverErrors bag には書き込まない。

## 実装差分 (git diff)

```diff
diff --git a/resources/js/pages/Organizations/Settings.svelte b/resources/js/pages/Organizations/Settings.svelte
index 7ebe2a3..8215c87 100644
--- a/resources/js/pages/Organizations/Settings.svelte
+++ b/resources/js/pages/Organizations/Settings.svelte
@@ -87,6 +87,9 @@
     /* ---- オーナー移譲 (recent-auth 必須。precheck で鮮度を確認してから送る) ---- */
     const transferForm = useForm({ user_id: "" });
     let transferDialogOpen = $state(false);
+    // client precheck 専用の transient error。serverErrors (transferForm.errors) とは分離し、
+    // 有効値復帰で自動解消する (「押下時にエラー表示」契約は維持: 無効のままなら残す)。
+    let transferClientError = $state<string | null>(null);
 
     const transferCandidates = $derived(members.filter((member) => member.id !== myId));
     const transferTargetName = $derived(
@@ -94,6 +97,20 @@
             "",
     );
 
+    // precheck 合格条件 = 選択値が実在候補に一致すること。エラー条件はこの否定。
+    const isValidTransferTarget = $derived(
+        transferCandidates.some((member) => String(member.id) === transferForm.user_id),
+    );
+
+    // 有効候補へ復帰した時点で client error を連動クリア (過剰クリア防止: clientError!=null かつ有効時のみ)。
+    // 候補 0 人ケースのエラーは isValidTransferTarget が常に false のため残留する = 選択では直せないので正しい。
+    // serverErrors (transferForm.errors) はこの effect の対象外 = 非退行。
+    $effect(() => {
+        if (transferClientError !== null && isValidTransferTarget) {
+            transferClientError = null;
+        }
+    });
+
     /** 候補 0 人時の共通文言 (案内文と押下時エラーで揺れないよう単一定義。テストも本文言を検証) */
     const NO_TRANSFER_CANDIDATES = "移譲先にできるメンバーがいません。";
 
@@ -107,17 +124,11 @@
     function openTransferDialog(event: SubmitEvent): void {
         event.preventDefault();
         if (transferCandidates.length === 0) {
-            transferForm.setError(
-                "user_id",
-                `${NO_TRANSFER_CANDIDATES}先にメンバーを招待してください。`,
-            );
+            transferClientError = `${NO_TRANSFER_CANDIDATES}先にメンバーを招待してください。`;
             return;
         }
-        const isValidTarget = transferCandidates.some(
-            (member) => String(member.id) === transferForm.user_id,
-        );
-        if (!isValidTarget) {
-            transferForm.setError("user_id", "移譲先のメンバーを選択してください。");
+        if (!isValidTransferTarget) {
+            transferClientError = "移譲先のメンバーを選択してください。";
             return;
         }
         transferDialogOpen = true;
@@ -129,6 +140,8 @@
                 preserveScroll: true,
                 onFinish: () => {
                     transferDialogOpen = false;
+                    // 再 mount しないライフサイクル (再認証キャンセル等) でも stale を残さない
+                    transferClientError = null;
                 },
             });
         });
@@ -270,7 +283,7 @@
                     <FormField
                         label="移譲先のメンバー"
                         id="transfer-target"
-                        error={transferForm.errors.user_id}
+                        error={transferClientError ?? transferForm.errors.user_id}
                     >
                         {#snippet children({ id, describedBy, invalid })}
                             <Select
diff --git a/resources/js/pages/Projects/Show.svelte b/resources/js/pages/Projects/Show.svelte
index 05585a3..f755933 100644
--- a/resources/js/pages/Projects/Show.svelte
+++ b/resources/js/pages/Projects/Show.svelte
@@ -119,19 +119,33 @@
 
     /* メンバー追加 (store。assignableUsers から選択) */
     const memberForm = useForm({ user_id: "", role: "project_member" });
+    // client precheck 専用の transient error。serverErrors (memberForm.errors) とは分離。
+    let addMemberClientError = $state<string | null>(null);
+
+    // precheck 合格条件 = 候補が選択済み。エラー条件はこの否定。
+    const isAddMemberSelected = $derived(memberForm.user_id !== "");
+
+    // 選択が入った時点で client error を連動クリア (過剰クリア防止)。serverErrors は対象外 = 非退行。
+    $effect(() => {
+        if (addMemberClientError !== null && isAddMemberSelected) {
+            addMemberClientError = null;
+        }
+    });
 
     function submitAddMember(event: SubmitEvent): void {
         event.preventDefault();
         if (memberForm.processing) return; // 二重送信ガード
         // 候補未選択なら押下時エラー (disabled にしない = 禁止事項 8)
         if (memberForm.user_id === "") {
-            memberForm.setError("user_id", "追加するメンバーを選択してください。");
+            addMemberClientError = "追加するメンバーを選択してください。";
             return;
         }
         memberForm.post(`/projects/${project.id}/members`, {
             preserveScroll: true,
             onSuccess: () => {
                 memberForm.reset();
+                // reset で user_id が空へ戻るため、直前の client error も揃えて解消する
+                addMemberClientError = null;
             },
         });
     }
@@ -508,7 +522,7 @@
                     <FormField
                         label="メンバー"
                         id="project-member-user"
-                        error={memberForm.errors.user_id}
+                        error={addMemberClientError ?? memberForm.errors.user_id}
                     >
                         {#snippet children({ id, describedBy, invalid })}
                             <Select
diff --git a/tests/js/pages/OrganizationsSettings.test.ts b/tests/js/pages/OrganizationsSettings.test.ts
index de043a4..312e88c 100644
--- a/tests/js/pages/OrganizationsSettings.test.ts
+++ b/tests/js/pages/OrganizationsSettings.test.ts
@@ -218,3 +218,116 @@ describe("Organizations/Settings オーナー移譲の確定フロー (F-12)", (
         expect(routerPostSpy).not.toHaveBeenCalled();
     });
 });
+
+describe("Organizations/Settings オーナー移譲の client error 自動解消 (T044)", () => {
+    // 自分 (id:1) + 有効候補 2 人 (A id:2 / B id:3)。page 未モック (myId=null) のため
+    // 3 人全員が候補になるが、A→B の切替で $effect のクリア分岐を通せればよい。
+    const multiCandidateProps = {
+        ...baseProps,
+        members: [
+            { id: 1, name: "オーナー 太郎" },
+            { id: 2, name: "候補 A" },
+            { id: 3, name: "候補 B" },
+        ],
+    };
+
+    function stubRecentAuthStatus(recent: boolean): ReturnType<typeof vi.fn> {
+        const fetchMock = vi.fn().mockImplementation((input: RequestInfo | URL) => {
+            if (String(input).includes("/recent-auth/status")) {
+                return Promise.resolve({
+                    ok: true,
+                    status: 200,
+                    json: () =>
+                        Promise.resolve({
+                            recent,
+                            passwordSet: true,
+                            availableProviders: [],
+                            canSatisfy: true,
+                            confirmedAt: recent ? 1 : null,
+                        }),
+                });
+            }
+            return Promise.reject(new Error(`unexpected fetch: ${String(input)}`));
+        });
+        vi.stubGlobal("fetch", fetchMock);
+        return fetchMock;
+    }
+
+    afterEach(() => {
+        vi.unstubAllGlobals();
+        vi.restoreAllMocks();
+    });
+
+    it("空選択で押下→エラー表示後、有効候補を選ぶと client error と aria-invalid が自動解消する", async () => {
+        render(Settings, { props: multiCandidateProps });
+
+        await fireEvent.click(screen.getByTestId("transfer-ownership-button"));
+
+        expect(screen.getByText("移譲先のメンバーを選択してください。")).toBeInTheDocument();
+        const select = screen.getByLabelText("移譲先のメンバー");
+        expect(select).toHaveAttribute("aria-invalid", "true");
+
+        await fireEvent.change(select, { target: { value: "2" } });
+
+        await waitFor(() => {
+            expect(
+                screen.queryByText("移譲先のメンバーを選択してください。"),
+            ).toBeNull();
+        });
+        expect(select).not.toHaveAttribute("aria-invalid");
+    });
+
+    it("無効値のまま (空選択維持) なら client error は残留する (過剰クリア防止)", async () => {
+        render(Settings, { props: multiCandidateProps });
+
+        await fireEvent.click(screen.getByTestId("transfer-ownership-button"));
+        expect(screen.getByText("移譲先のメンバーを選択してください。")).toBeInTheDocument();
+
+        // 選択を空のまま保持 (isValidTransferTarget=false) → $effect はクリアしない
+        const select = screen.getByLabelText("移譲先のメンバー");
+        await fireEvent.change(select, { target: { value: "" } });
+
+        expect(screen.getByText("移譲先のメンバーを選択してください。")).toBeInTheDocument();
+        expect(select).toHaveAttribute("aria-invalid", "true");
+    });
+
+    it("client error の自動クリアは serverErrors を破壊せず、背後のサーバエラーが再表示される (非退行)", async () => {
+        // router.post を onError 呼び出しに差し替える。useForm 内部 onError が
+        // form.clearErrors().setError(errors) を実行し、実 transferForm.errors.user_id に載る。
+        const serverMsg = "サーバ由来: 対象は組織メンバーではありません";
+        vi.spyOn(router, "post").mockImplementation(
+            (_url, _data, opts) => {
+                (opts as { onError?: (e: Record<string, string>) => void } | undefined)?.onError?.(
+                    { user_id: serverMsg },
+                );
+            },
+        );
+        stubRecentAuthStatus(true);
+        render(Settings, { props: multiCandidateProps });
+
+        const select = screen.getByLabelText("移譲先のメンバー");
+
+        // 1. 有効候補 A を選択 → 確認ダイアログ → 確定 → サーバエラー表示 (client error は null)
+        await fireEvent.change(select, { target: { value: "2" } });
+        await fireEvent.click(screen.getByTestId("transfer-ownership-button"));
+        await fireEvent.click(screen.getByRole("button", { name: "移譲する" }));
+        await waitFor(() => {
+            expect(screen.getByText(serverMsg)).toBeInTheDocument();
+        });
+
+        // 2. 空選択に戻して送信 → client error がサーバエラーを一時的に覆う
+        await fireEvent.change(select, { target: { value: "" } });
+        await fireEvent.click(screen.getByTestId("transfer-ownership-button"));
+        expect(screen.getByText("移譲先のメンバーを選択してください。")).toBeInTheDocument();
+        expect(screen.queryByText(serverMsg)).toBeNull();
+
+        // 3. 有効候補 B を選択 → $effect がクリア分岐を通り client error=null → サーバエラー再表示
+        await fireEvent.change(select, { target: { value: "3" } });
+        await waitFor(() => {
+            expect(
+                screen.queryByText("移譲先のメンバーを選択してください。"),
+            ).toBeNull();
+        });
+        expect(screen.getByText(serverMsg)).toBeInTheDocument();
+    });
+});
diff --git a/tests/js/pages/ProjectsShow.test.ts b/tests/js/pages/ProjectsShow.test.ts
index 591c581..99497a4 100644
--- a/tests/js/pages/ProjectsShow.test.ts
+++ b/tests/js/pages/ProjectsShow.test.ts
@@ -1,5 +1,5 @@
 import { afterEach, describe, expect, it, vi } from "vitest";
-import { fireEvent, render, screen, within } from "@testing-library/svelte";
+import { fireEvent, render, screen, waitFor, within } from "@testing-library/svelte";
 import { router } from "@inertiajs/svelte";
 import Show from "@/pages/Projects/Show.svelte";
 import type { ManualFilters, ManualListItem, PaginationMeta } from "@/types/manual";
@@ -323,3 +323,83 @@ describe("Projects/Show メンバー管理", () => {
         expect(screen.queryByTestId("project-member-list")).toBeNull();
     });
 });
+
+describe("Projects/Show メンバー追加の client error 自動解消 (T044)", () => {
+    // 有効候補 2 人 (A id:4 / B id:5)。A→B の切替で $effect のクリア分岐を通す。
+    const multiCandidateProps = {
+        ...baseProps,
+        assignableUsers: [
+            { id: 4, name: "候補 四郎" },
+            { id: 5, name: "候補 五郎" },
+        ],
+    };
+
+    afterEach(() => {
+        vi.restoreAllMocks();
+    });
+
+    it("未選択で押下→エラー表示後、候補を選ぶと client error と aria-invalid が自動解消する", async () => {
+        vi.spyOn(router, "post").mockImplementation(() => {});
+        render(Show, { props: multiCandidateProps });
+
+        await fireEvent.submit(screen.getByTestId("project-member-add-form"));
+        expect(screen.getByText("追加するメンバーを選択してください。")).toBeInTheDocument();
+        const select = screen.getByLabelText("メンバー");
+        expect(select).toHaveAttribute("aria-invalid", "true");
+
+        await fireEvent.change(select, { target: { value: "4" } });
+
+        await waitFor(() => {
+            expect(screen.queryByText("追加するメンバーを選択してください。")).toBeNull();
+        });
+        expect(select).not.toHaveAttribute("aria-invalid");
+    });
+
+    it("未選択のまま維持なら client error は残留する (過剰クリア防止)", async () => {
+        vi.spyOn(router, "post").mockImplementation(() => {});
+        render(Show, { props: multiCandidateProps });
+
+        await fireEvent.submit(screen.getByTestId("project-member-add-form"));
+        expect(screen.getByText("追加するメンバーを選択してください。")).toBeInTheDocument();
+
+        const select = screen.getByLabelText("メンバー");
+        await fireEvent.change(select, { target: { value: "" } });
+
+        expect(screen.getByText("追加するメンバーを選択してください。")).toBeInTheDocument();
+        expect(select).toHaveAttribute("aria-invalid", "true");
+    });
+
+    it("client error の自動クリアは serverErrors を破壊せず、背後のサーバエラーが再表示される (非退行)", async () => {
+        const serverMsg = "サーバ由来: 追加できません";
+        vi.spyOn(router, "post").mockImplementation(
+            (_url, _data, opts) => {
+                (opts as { onError?: (e: Record<string, string>) => void } | undefined)?.onError?.(
+                    { user_id: serverMsg },
+                );
+            },
+        );
+        render(Show, { props: multiCandidateProps });
+
+        const select = screen.getByLabelText("メンバー");
+
+        // 1. 有効候補 A を選択 → 追加 → サーバエラー表示 (onError 経路のため reset は発火せず選択値は残る)
+        await fireEvent.change(select, { target: { value: "4" } });
+        await fireEvent.submit(screen.getByTestId("project-member-add-form"));
+        await waitFor(() => {
+            expect(screen.getByText(serverMsg)).toBeInTheDocument();
+        });
+
+        // 2. 空選択に戻して送信 → client error がサーバエラーを一時的に覆う
+        await fireEvent.change(select, { target: { value: "" } });
+        await fireEvent.submit(screen.getByTestId("project-member-add-form"));
+        expect(screen.getByText("追加するメンバーを選択してください。")).toBeInTheDocument();
+        expect(screen.queryByText(serverMsg)).toBeNull();
+
+        // 3. 有効候補 B を選択 → $effect がクリア分岐を通り client error=null → サーバエラー再表示
+        await fireEvent.change(select, { target: { value: "5" } });
+        await waitFor(() => {
+            expect(screen.queryByText("追加するメンバーを選択してください。")).toBeNull();
+        });
+        expect(screen.getByText(serverMsg)).toBeInTheDocument();
+    });
+});

```

## テスト結果

- 追加テスト含む対象2ファイル: 42 passed。
- フルスイート: `pnpm test --testTimeout=30000` → **72 files / 560 tests passed**。
- `pnpm typecheck` / `pnpm lint` / `pnpm build` すべて green。
- PHP 変更なし (diff に app/ なし) = composer test/phpstan 非該当。
