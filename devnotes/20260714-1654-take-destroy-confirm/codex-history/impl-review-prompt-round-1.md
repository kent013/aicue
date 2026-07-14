# 使命 (North Star)

**AI-CUE** は、現場に既にある**作業手順書(SOP)を起点に**、AI が撮るべきカットを設計した**動画シナリオ**を生成し、そのシナリオを**スマホ(PWA)でナビゲーション撮影**することで、専門知識ゼロの現場作業者でも**標準化されたマニュアル動画**を作れるようにする。

- 「思考ゼロ・編集ゼロ」— 台本作成・撮影判断・編集の 3 ハードルを AI とナビ撮影で肩代わりする。
- 競合(tebiki)と異なり、標準作業を起点に AI が教材設計し撮影を指示する。
- v1 スコープ: 字幕のみ / 撮影は PWA(同一オリジン・セッション認証) / 単一 Default Project。

# 禁止事項

1. テストなしの実装完了報告
2. PHPStan エラーの widen・baseline 化
3. dev DB への破壊操作をエージェント判断で実行
4. `response()->json()` の直書き(DTO / JsonResource / Inertia を使う)
5. LLM 呼び出しの Prism 直呼び
6. prompt 文字列のコード直書き
7. 操作系 POST 応答での `redirect()->intended()`
8. 必須条件未充足を理由にボタンを disabled にする UI(押下時にエラー表示する。DESIGN.md)

# 思考原則 — 全議論に適用

まず仮説を立てろ。何を検証したいのか、なぜそう考えるのか、どうなれば成功と判断するのかを明確にしてから手を動かせ。
先人の知恵を探せ。乗るべき巨人の肩があるなら乗れ。
機能の名前に立ち返れ。名前はその機能が果たすべき役割を示している。

# ツール使用制限

コマンド実行・ファイル書き込みは一切行わず、提供されたテキストの分析に集中すること。ファイル読み込みは許可。

---

# system: 役割

あなたは Laravel + Svelte アプリのコードレビュアーである。本 PR は UI (Svelte 5 + TypeScript) に閉じた小規模変更「テイク削除に確認ダイアログを追加 (T043)」の実装差分をレビューする。

レビュー観点:
1. **設計との一致性**: 下記詳細設計書の S1/S2 に忠実か
2. **正確性**: state 管理・null ガード・非同期フローにバグがないか。特に `processing={busyTakeId === deleteTargetId}` の評価 (両者 null 時 true になるが dialog は閉じているので無害か)、削除確定後の state リセット順序、対象が既に一覧から消えた場合のガード
3. **TypeScript 型安全性**: 新規 `any` なし、明示型
4. **テスト網羅性**: 即発火しない / confirm で DELETE / cancel で未発火 / DL 済み 422 の 4 系統を確実に検証できているか。`within(dialog)` スコープクエリの妥当性
5. **DESIGN.md 準拠**: ConfirmDialog の契約 (confirm で自動 close しない・close は呼び出し側責務、danger variant、processing 中の close 抑止) に沿っているか。hex 直書きを増やしていないか
6. **Atomic Design 準拠**: features/capture が organisms/ConfirmDialog を利用する import 方向は単方向 (features → organisms) で正しいか。SVG 直書きを増やしていないか

出力形式: ファイルごとに判定。指摘は Critical / Warning / Suggestion に分類。最後に全体判定 **APPROVED** または **CHANGES_REQUESTED** を明記すること。

---

# user

## 詳細設計書 (detailed-design.md 抜粋)

施策:
- S1: テイク削除に確認ダイアログ (ConfirmDialog organism) を挟む。削除ボタンの onclick を即 `remove(take)` から `requestDelete(take, index)` へ変更し、確認ダイアログ「削除する」確定時のみ DELETE を送る。
- S2: vitest 更新。即発火しない / confirm で DELETE / cancel で未発火 の 3 新規テスト + 既存「DL 済み 422」テストを confirm 2 ステップ経由に更新。

設計上の契約:
- `deleteTargetId: number | null` / `deleteLabel: string` / `deleteDialogOpen: boolean` を component ローカル state で保持。object 参照でなく id + label スナップショットを持つ (親の再取得・並べ替えで参照がずれない)。
- `confirmDelete` は `deleteTargetId` の null ガード + `cut.takes.find` の undefined ガードを明示。成功・失敗いずれも解決後に close。失敗 (422) は既存 `take-strip-error` (role="alert") に表示。
- `processing={busyTakeId === deleteTargetId}` で DELETE 送信中は ConfirmDialog を loading 表示し ESC/overlay/cancel close を抑止。
- 確認文言・variant・「削除する」ラベルは動画マニュアル削除に合わせる。
- ESC/overlay での close はデフォルトで「削除しない」(安全側)。

## DESIGN.md §ConfirmDialog (canonical)

- 実装: `components/organisms/ConfirmDialog.svelte` (仕様の真実は `ConfirmDialog.types.ts`)。Modal の composition。
- `confirmVariant` は `primary` / `danger` の 2 値のみ。irreversible / destructive な操作は danger。
- footer は Button atom (cancel=`ghost` / confirm=`confirmVariant`、processing 中は loading)。
- confirm で自動 close しない (処理完了後に呼び出し側が `open=false` にする)。cancel / ESC / overlay / X は `onCancel` を発火して close。

ConfirmDialogProps (types):
- `open: boolean` (bindable) / `title` / `message` / `confirmLabel?` / `cancelLabel?` / `confirmVariant?: "primary"|"danger"` / `processing?` / `onConfirm: () => void` / `onCancel?` / `testId?`

## Atomic Design ディレクトリ

`resources/js/components/` は `atoms → molecules → organisms → features/{domain} → templates → pages` の単方向 import。本変更は `features/capture/TakeStrip.svelte` が `organisms/ConfirmDialog.svelte` を import (features → organisms、下層参照で正方向)。

## 実装差分 (git diff)

```diff
diff --git a/resources/js/components/features/capture/TakeStrip.svelte b/resources/js/components/features/capture/TakeStrip.svelte
index 70288be..37fd5e4 100644
--- a/resources/js/components/features/capture/TakeStrip.svelte
+++ b/resources/js/components/features/capture/TakeStrip.svelte
@@ -3,6 +3,7 @@
     import Badge from "@/components/atoms/Badge.svelte";
     import Button from "@/components/atoms/Button.svelte";
     import TakeCommentDialog from "@/components/features/capture/TakeCommentDialog.svelte";
+    import ConfirmDialog from "@/components/organisms/ConfirmDialog.svelte";
     import { captureJson, extractErrorMessage } from "@/lib/capture/http";
     import type { CaptureCut, CaptureTake } from "@/types/capture";
 
@@ -27,6 +28,37 @@
     let commentSaving = $state(false);
     let commentError = $state<string | null>(null);
 
+    // 削除確認ダイアログ。id をスナップショット保持し、ラベルは開いた時点の値で確定する
+    // (親の再取得・並べ替えで参照内容がずれないように object 参照ではなく id + label を持つ)。
+    let deleteTargetId = $state<number | null>(null);
+    let deleteLabel = $state("");
+    let deleteDialogOpen = $state(false);
+
+    // 削除ボタン押下: 即 DELETE せず、対象を確定して確認ダイアログを開く。
+    function requestDelete(take: CaptureTake, index: number): void {
+        deleteTargetId = take.id;
+        deleteLabel = `テイク ${index + 1}`;
+        deleteDialogOpen = true;
+    }
+
+    // 「削除する」確定時のみ DELETE を送る。null ガードを明示 (optional 連鎖任せにしない)。
+    async function confirmDelete(): Promise<void> {
+        const id = deleteTargetId;
+        if (id === null) return;
+        const target = cut.takes.find((t) => t.id === id);
+        if (target === undefined) {
+            // 既に一覧から消えている等: ダイアログを閉じるだけ
+            deleteDialogOpen = false;
+            deleteTargetId = null;
+            return;
+        }
+        // 成功・失敗いずれも解決後に閉じる。失敗 (422 等) は既存の take-strip-error (role="alert") に表示。
+        await remove(target);
+        deleteDialogOpen = false;
+        deleteTargetId = null;
+        deleteLabel = ""; // 再オープン時の古い文言混入を防ぐ (design-review S1 Suggestion)
+    }
+
     function takeUrl(take: CaptureTake, suffix = ""): string {
         return `/app/projects/${projectId}/manuals/${manualId}/cuts/${cut.id}/takes/${take.id}${suffix}`;
     }
@@ -189,7 +221,7 @@
                     size="sm"
                     iconOnly
                     ariaLabel="削除"
-                    onclick={() => remove(take)}
+                    onclick={() => requestDelete(take, index)}
                     testId={`take-delete-${take.id}`}
                 >
                     <Trash2 class="size-4" aria-hidden="true" />
@@ -209,3 +241,14 @@
     error={commentError}
     onSave={saveComment}
 />
+
+<ConfirmDialog
+    bind:open={deleteDialogOpen}
+    title="テイク削除"
+    message={`${deleteLabel}を削除しますか？ この操作は取り消せません。`}
+    confirmLabel="削除する"
+    confirmVariant="danger"
+    processing={busyTakeId === deleteTargetId}
+    onConfirm={confirmDelete}
+    testId="take-delete-dialog"
+/>
diff --git a/tests/js/components/features/capture/TakeStrip.test.ts b/tests/js/components/features/capture/TakeStrip.test.ts
index 5d2d821..59873b4 100644
--- a/tests/js/components/features/capture/TakeStrip.test.ts
+++ b/tests/js/components/features/capture/TakeStrip.test.ts
@@ -1,5 +1,5 @@
 import { afterEach, beforeEach, describe, expect, it, vi } from "vitest";
-import { cleanup, fireEvent, render, screen, waitFor } from "@testing-library/svelte";
+import { cleanup, fireEvent, render, screen, waitFor, within } from "@testing-library/svelte";
 import TakeStrip from "@/components/features/capture/TakeStrip.svelte";
 import type { CaptureCut, CaptureTake } from "@/types/capture";
 
@@ -81,7 +81,60 @@ describe("TakeStrip", () => {
         expect(fetchMock.mock.calls[0][1].method).toBe("POST");
     });
 
-    it("DL 済みテイクの削除ボタンは disabled にせず、押下時に 422 メッセージを表示する", async () => {
+    it("削除ボタン押下では即 DELETE せず、確認ダイアログを表示する", async () => {
+        const onChanged = vi.fn();
+        render(TakeStrip, {
+            projectId: 1,
+            manualId: 2,
+            cut: makeCut([makeTake()]),
+            onChanged,
+        });
+
+        await fireEvent.click(screen.getByTestId("take-delete-10"));
+
+        expect(fetchMock).not.toHaveBeenCalled();
+        expect(screen.getByTestId("take-delete-dialog")).toBeInTheDocument();
+        expect(onChanged).not.toHaveBeenCalled();
+    });
+
+    it("確認ダイアログの『削除する』押下で DELETE .../takes/{id} が飛び onChanged が呼ばれる", async () => {
+        fetchMock.mockResolvedValueOnce(jsonResponse(200, {}));
+        const onChanged = vi.fn();
+        render(TakeStrip, {
+            projectId: 1,
+            manualId: 2,
+            cut: makeCut([makeTake()]),
+            onChanged,
+        });
+
+        await fireEvent.click(screen.getByTestId("take-delete-10"));
+        const dialog = screen.getByTestId("take-delete-dialog");
+        await fireEvent.click(within(dialog).getByRole("button", { name: "削除する" }));
+
+        await waitFor(() => expect(onChanged).toHaveBeenCalled());
+        expect(fetchMock.mock.calls[0][0]).toBe("/app/projects/1/manuals/2/cuts/3/takes/10");
+        expect(fetchMock.mock.calls[0][1].method).toBe("DELETE");
+    });
+
+    it("確認ダイアログのキャンセルでは DELETE が発火せずダイアログが閉じる", async () => {
+        const onChanged = vi.fn();
+        render(TakeStrip, {
+            projectId: 1,
+            manualId: 2,
+            cut: makeCut([makeTake()]),
+            onChanged,
+        });
+
+        await fireEvent.click(screen.getByTestId("take-delete-10"));
+        const dialog = screen.getByTestId("take-delete-dialog");
+        await fireEvent.click(within(dialog).getByRole("button", { name: "キャンセル" }));
+
+        await waitFor(() => expect(fetchMock).not.toHaveBeenCalled());
+        expect(onChanged).not.toHaveBeenCalled();
+        expect(screen.queryByTestId("take-delete-dialog")).not.toBeInTheDocument();
+    });
+
+    it("DL 済みテイクの削除ボタンは disabled にせず、確認後 422 メッセージを表示する", async () => {
         fetchMock.mockResolvedValueOnce(
             jsonResponse(422, { message: "ダウンロード済みのテイクは削除できません。" }),
         );
@@ -97,12 +150,16 @@ describe("TakeStrip", () => {
         expect(deleteButton).not.toBeDisabled(); // 事前条件 disabled 禁止 (DESIGN.md)
 
         await fireEvent.click(deleteButton);
+        const dialog = screen.getByTestId("take-delete-dialog");
+        await fireEvent.click(within(dialog).getByRole("button", { name: "削除する" }));
 
         await waitFor(() =>
             expect(screen.getByTestId("take-strip-error").textContent).toContain(
                 "ダウンロード済みのテイクは削除できません",
             ),
         );
+        expect(fetchMock.mock.calls[0][0]).toBe("/app/projects/1/manuals/2/cuts/3/takes/10");
+        expect(fetchMock.mock.calls[0][1].method).toBe("DELETE");
         expect(onChanged).not.toHaveBeenCalled();
     });
 

```

## テスト結果

`pnpm test tests/js/components/features/capture/TakeStrip.test.ts` → **7 passed (7)**。
`pnpm lint` / `pnpm typecheck` / `pnpm build` すべて green。PHP 変更なし (diff に app/ を含まない)。
