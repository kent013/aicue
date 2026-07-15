## アプリの使命（North Star）

**AI-CUE** は、現場に既にある**作業手順書(SOP)を起点に**、AI が撮るべきカットを設計した**動画シナリオ**を生成し、そのシナリオを**スマホ(PWA)でナビゲーション撮影**することで、専門知識ゼロの現場作業者でも**標準化されたマニュアル動画**を作れるようにする。

- 「思考ゼロ・編集ゼロ」— 台本作成・撮影判断・編集の 3 ハードルを AI とナビ撮影で肩代わりする。
- 競合(OJT を撮って形式化する tebiki)と異なり、**標準作業を起点に AI が教材設計し撮影を指示する**。
- 熟練者の暗黙知を動画マニュアルという形式知へ変換する装置（SECI）。
- v1 スコープ: 字幕のみ / 撮影は PWA(同一オリジン・セッション認証) / 動画合成は自前 ffmpeg / 単一 Default Project。

## 禁止事項（AGENTS.md）

1. テストなしの実装完了報告（不変条件は対応する Architecture/Feature テストへの登録まで含めて「実装済み」）
2. PHPStan エラーの widen（型を緩めて黙らせる）・baseline 化
3. dev DB への破壊操作（`migrate:fresh` 等）をエージェント判断で実行すること
4. `response()->json()` の直書き（DTO / JsonResource / Inertia を使う。仕様固定 endpoint のみ例外）
5. LLM 呼び出しの Prism 直呼び（`app/Prompts/` の factory 経由のみ）
6. prompt 文字列のコード直書き（`resources/prompts/*.yaml` に置く）
7. 操作系 POST の応答での `redirect()->intended()`
8. 必須条件未充足を理由にボタンを disabled にする UI（押下時にエラー表示する。DESIGN.md）

```
【思考原則 — 全議論に適用】
まず仮説を立てろ。何を検証したいのか、なぜそう考えるのか、どうなれば成功と判断するのかを明確にしてから手を動かせ。仮説なき改善はただの試行錯誤であり、結果から学ぶことができない。

データに真摯に向き合え。成果だけでなく、多様性の変化、構造の揺らぎ、想定外のパターン — 全てが判断材料になる。数値を見て即座に閾値を弄るな。何が起きているのかを理解し、なぜそうなったのかを考え、どの方向に進むべきかを判断してから手を動かせ。

先人の知恵を探せ。自分たちだけで登る必要はない。乗るべき巨人の肩があるなら乗れ。

機能の名前に立ち返れ。名前はその機能が果たすべき役割を示している。現在の設計がその役割を果たしているか、常に問え。

仕組みが機能していない段階で値を弄るな。閾値チューニングやフィールド追加は、設計の方向性が正しいと確認できてから行え。方向性が間違っているなら、値をいくら調整しても意味はない。設計そのものを見直せ。成果が出なければ早期に見切り、次の仮説へ進め。

【ツール使用制限】
コマンド実行・ファイル書き込みは一切行わず、提供されたテキストの分析に集中すること。ファイル読み込みは許可。
```

---

## system: 役割・レビュー指示

あなたは Laravel + Svelte 5 アプリのコードレビュアーである。以下の改善実装（TODO T058: マニュアル複製ダイアログの成功後クローズと二重送信防止）をレビューせよ。

### レビュー観点
- **設計との一致性**: 詳細設計書の 5 施策（onSuccess close / 送信中ガード / 閉→開エッジ再 seed / vitest / test support の processing 反応化）が正しく実装されているか。
- **正確性**: `$effect` の依存が `open` のみに限定され `prevOpen` 経由で「開いている間の props 変化での再 seed」を排除できているか。二重送信ガードが Enter 経路・onclick 経路の双方を塞ぐか。onSuccess で `open = false` が親 `$state` へ双方向反映されるか。
- **Svelte 5 runes 作法**: `$bindable` / `$effect` / 非 reactive ローカル `prevOpen` の使い方。effect 自己依存の回避。
- **テスト網羅性**: 既存 3 テストを維持しつつ close / 関数ガード / UI ガード / 再 seed を追加できているか。偽陽性回避（エラー文言が一度表示されてから消滅を観測）。
- **型安全性**: `reactiveUseForm` の generic 制約 `{ processing?: never; errors?: never }` で data 型との衝突をコンパイル時に禁止。getter/setter 化の後方互換。
- **DESIGN.md 準拠 / Atomic Design 準拠**: 既存 Button(atom) / Modal(organism) の利用のみ。新規 SVG・hex 直書き・token 逸脱・階層逆流がないか。
- **禁止事項8**: disabled は送信中(processing)のみで、未充足では使わないことを維持しているか。

### 出力形式
- ファイルごとに判定
- 指摘は Critical / Warning / Suggestion に分類
- 最後に全体判定: **APPROVED** または **CHANGES_REQUESTED**

---

## user

### 詳細設計書（要約）

根本原因: `DuplicateManualDialog.svelte` の `submit()` は `onError` のみを渡し成功時にダイアログを閉じない。複製成功時サーバは新 VideoManual へ redirect し、Inertia は同一 `Manuals/Show` へ props 差し替えで遷移する（再マウントしない）。そのため親 `Manuals/Show.svelte` の `duplicateDialogOpen`（`$state`）が `true` のまま生存しモーダルが残る。`form.processing` は完了で false に戻り再クリックで現マニュアル（=直前のコピー）を無言で再複製する。

施策:
1. 複製成功時に `onSuccess: () => { open = false }` でダイアログを確実に閉じる（`bind:open` で親 $state へ反映）。
2. 送信中の多重送信ガード: `submit()` 冒頭 `if (form.processing) return;`（Enter/onclick 両経路を 1 箇所で塞ぐ）。
3. 再オープン(false→true エッジ)時に現 props で再 seed（title/category）+ `clearErrors()`。`open=true` 中の props 変化では seed しない（入力途中の上書き防止）。`prevOpen` は非 reactive ローカルで effect 依存は `open` のみ。
4. vitest 追加（close / 関数ガード / UI ガード / 再 seed / 既存維持）。
5. test support: `reactiveUseForm` の `processing` を `$state` + getter/setter で反応化（DOM disabled/aria-busy を観測可能に）。generic 制約で data 型と processing/errors の衝突を型で禁止。

制約: 本タスクは PHP 変更なし（フロントのみ）。禁止事項8 遵守（disabled は processing のみ、未充足では使わない）。既存 3 テスト維持（削除・上書き禁止）。

### 実装差分（git diff）

```diff
diff --git a/resources/js/components/features/manual/DuplicateManualDialog.svelte b/resources/js/components/features/manual/DuplicateManualDialog.svelte
index 2d1066b..b75087b 100644
--- a/resources/js/components/features/manual/DuplicateManualDialog.svelte
+++ b/resources/js/components/features/manual/DuplicateManualDialog.svelte
@@ -29,16 +29,41 @@
         categories,
     }: Props = $props();
 
-    // useForm はマウント時 1 回だけ初期化する (Manuals/Edit と同じ流儀。複製後は redirect で
-    // 画面遷移するため props の再供給は起きない = 初期値のみ参照で足りる)。
+    // useForm はマウント時 1 回だけ初期化する (Manuals/Edit と同じ流儀)。
+    // 複製成功は同一 Manuals/Show へ props 差し替えで遷移するため本コンポーネントは再マウント
+    // されない。そこで閉→開エッジで seedFromDefaults により現 props へ値を揃える (下記 $effect)。
     const form = useForm<{ title: string; category: string }>({
         title: defaultTitle,
         category: defaultCategory === null ? "" : String(defaultCategory),
     });
 
+    // 閉→開エッジでのみ現 props を再 seed する。open=true 中の props 変化では seed しない
+    // (入力途中の上書きを防ぐ)。代入対象は useForm の shape と一致する title / category の
+    // 2 キーのみ (他キー拡張時の事故防止)。
+    function seedFromDefaults(): void {
+        form.title = defaultTitle;
+        form.category = defaultCategory === null ? "" : String(defaultCategory);
+        form.clearErrors();
+    }
+
+    // prevOpen は非 reactive なローカル変数 (初回 open で同期)。$effect の依存は open だけに限定し、
+    // prevOpen の読み書きを追跡対象にしない ($state 化すると effect が自己依存し余分に再実行されるため避ける)。
+    let prevOpen = open;
+    $effect(() => {
+        const isOpen = open;
+        if (isOpen && !prevOpen) {
+            seedFromDefaults();
+        }
+        prevOpen = isOpen;
+    });
+
     // 送信本体。form 送信 (Enter) と footer ボタン onclick の双方から呼ぶ
     // (Button atom は form 属性を持たないため footer は onclick で発火させる)。
     function submit(): void {
+        // 送信中の再入 (二重クリック / Enter 連打 / redirect 完了前の再クリック) を塞ぐ。
+        // これは「必須未充足で disabled」(禁止事項8) ではなく、送信中の submit 多重防止。
+        if (form.processing) return;
+
         form
             .transform((data) => ({
                 title: data.title,
@@ -46,7 +71,11 @@
                 category: data.category === "" ? null : Number(data.category),
             }))
             .post(`/projects/${projectId}/manuals/${manualId}/duplicate`, {
-                // 成功時は redirect で新 manual へ遷移するため onSuccess で閉じる必要はない
+                // 成功時は新 manual へ redirect するが、遷移先も同一 Manuals/Show のため
+                // 親の open state が生存しモーダルが残る。ここで明示的に閉じる (F-1-01)。
+                onSuccess: () => {
+                    open = false;
+                },
                 onError: () => {
                     /* エラーは FormField 経由で表示 (ダイアログは開いたまま) */
                 },
diff --git a/tests/js/components/features/manual/DuplicateManualDialog.test.ts b/tests/js/components/features/manual/DuplicateManualDialog.test.ts
index 8db75c2..c4da93c 100644
--- a/tests/js/components/features/manual/DuplicateManualDialog.test.ts
+++ b/tests/js/components/features/manual/DuplicateManualDialog.test.ts
@@ -66,4 +66,112 @@ describe("features/manual/DuplicateManualDialog", () => {
             expect(screen.getByTestId("duplicate-manual-confirm")).not.toBeDisabled();
         });
     });
+
+    it("複製 submit の onSuccess でダイアログが閉じる (F-1-01)", async () => {
+        render(DuplicateManualDialog, { props: baseProps });
+
+        await waitFor(() => {
+            expect(screen.getByTestId("duplicate-manual-confirm")).toBeInTheDocument();
+        });
+        await fireEvent.click(screen.getByTestId("duplicate-manual-confirm"));
+
+        const form = holder.last as { post: ReturnType<typeof vi.fn> };
+        expect(form.post).toHaveBeenCalledTimes(1);
+        // reactiveUseForm の post は callback を自動実行しないため、捕捉した onSuccess を手動発火する
+        const options = form.post.mock.calls[0][1] as { onSuccess?: () => void };
+        options.onSuccess?.();
+
+        await waitFor(() => {
+            expect(screen.queryByTestId("duplicate-manual-dialog")).not.toBeInTheDocument();
+        });
+    });
+
+    it("送信中は submit() 冒頭ガードで二重送信しない (関数ガード)", async () => {
+        render(DuplicateManualDialog, { props: baseProps });
+
+        await waitFor(() => {
+            expect(screen.getByTestId("duplicate-manual-confirm")).toBeInTheDocument();
+        });
+
+        const form = holder.last as { processing: boolean; post: ReturnType<typeof vi.fn> };
+        form.processing = true;
+
+        // フォームへ submit を直接発火 (ボタン disabled に依らず handler を叩く = Enter 相当)。
+        // Modal は portal でツリー外へ描画されるため document から取得する。
+        const formEl = document.getElementById("duplicate-manual-form") as HTMLFormElement;
+        await fireEvent.submit(formEl);
+
+        expect(form.post).not.toHaveBeenCalled();
+    });
+
+    it("送信中は confirm ボタンが disabled かつ aria-busy になる (UI ガード)", async () => {
+        render(DuplicateManualDialog, { props: baseProps });
+
+        await waitFor(() => {
+            expect(screen.getByTestId("duplicate-manual-confirm")).toBeInTheDocument();
+        });
+
+        const form = holder.last as { processing: boolean };
+        form.processing = true;
+
+        await waitFor(() => {
+            const confirm = screen.getByTestId("duplicate-manual-confirm");
+            expect(confirm).toHaveAttribute("aria-busy", "true");
+            expect(confirm).toBeDisabled();
+        });
+    });
+
+    it("再オープン (false→true) で現 props に再 seed + clearErrors + エラーDOM消滅", async () => {
+        const { rerender } = render(DuplicateManualDialog, { props: baseProps });
+
+        await waitFor(() => {
+            expect(screen.getByTestId("duplicate-manual-dialog")).toBeInTheDocument();
+        });
+
+        // エラー文言が一度 DOM 表示されたことを確認 (偽陽性防止)
+        const form = holder.last as {
+            errors: Record<string, string>;
+            title: string;
+            category: string;
+            clearErrors: ReturnType<typeof vi.fn>;
+        };
+        form.errors.title = "サーバエラー";
+        await waitFor(() => {
+            expect(screen.getByText("サーバエラー")).toBeInTheDocument();
+        });
+
+        // 一旦閉じて unmount を確認してから再オープンする (false 状態を effect が観測できるように)
+        await rerender({ ...baseProps, open: false });
+        await waitFor(() => {
+            expect(screen.queryByTestId("duplicate-manual-dialog")).not.toBeInTheDocument();
+        });
+
+        form.clearErrors.mockClear();
+
+        // false→true エッジで seedFromDefaults 発火
+        await rerender({
+            ...baseProps,
+            open: true,
+            defaultTitle: "新タイトル のコピー",
+            defaultCategory: 1,
+        });
+
+        await waitFor(() => {
+            expect(screen.getByTestId("duplicate-manual-dialog")).toBeInTheDocument();
+        });
+
+        expect(form.title).toBe("新タイトル のコピー");
+        expect(form.category).toBe("1");
+        expect(form.clearErrors).toHaveBeenCalled();
+        expect(screen.queryByText("サーバエラー")).not.toBeInTheDocument();
+
+        // エッジ限定: open=true のまま props 変化しても再 seed しない
+        await rerender({
+            ...baseProps,
+            open: true,
+            defaultTitle: "別タイトル",
+            defaultCategory: 1,
+        });
+        expect(form.title).toBe("新タイトル のコピー");
+    });
 });
diff --git a/tests/js/support/reactiveUseForm.svelte.ts b/tests/js/support/reactiveUseForm.svelte.ts
index e4aef57..aac4334 100644
--- a/tests/js/support/reactiveUseForm.svelte.ts
+++ b/tests/js/support/reactiveUseForm.svelte.ts
@@ -7,7 +7,9 @@ import { vi } from "vitest";
  * 再描画を観測できない。本フェイクは errors を $state で持ち、clearErrors がキーを削除すると
  * バインド先 (FormField の error prop) が再評価される = ユーザー体験と同じ挙動を検証できる。
  */
-export function reactiveUseForm<TData extends Record<string, unknown>>(
+export function reactiveUseForm<
+  TData extends Record<string, unknown> & { processing?: never; errors?: never },
+>(
   initial: TData,
   initialErrors: Record<string, string> = {},
 ): TData & {
@@ -18,6 +20,7 @@ export function reactiveUseForm<TData extends Record<string, unknown>>(
   post: ReturnType<typeof vi.fn>;
 } {
   const errors = $state<Record<string, string>>({ ...initialErrors });
+  let processing = $state(false);
   const post = vi.fn();
 
   const form = {
@@ -25,7 +28,12 @@ export function reactiveUseForm<TData extends Record<string, unknown>>(
     get errors() {
       return errors;
     },
-    processing: false,
+    get processing() {
+      return processing;
+    },
+    set processing(value: boolean) {
+      processing = value;
+    },
     clearErrors: vi.fn((...keys: string[]) => {
       if (keys.length === 0) {
         for (const key of Object.keys(errors)) delete errors[key];

```

### テスト結果

- 対象テスト（DuplicateManualDialog.test.ts）: 7 passed
- 全フロント test suite: `pnpm test --testTimeout=30000` → 80 files / 735 tests passed（回帰なし。共有 helper `reactiveUseForm` の consumer `ManualsCreate.test.ts` も green）
- `pnpm typecheck` / `pnpm lint` / `pnpm build` すべて green

### design system 参照（Atomic Design / DESIGN.md 観点）

- 触れた component 層: `resources/js/components/features/manual/DuplicateManualDialog.svelte`（features/manual 層）。import は `atoms/Button` `atoms/Input` `atoms/Select` `molecules/FormField` `organisms/Modal` の単方向のみ（変更なし）。
- 新規 SVG 内包・hex 直書き・DS token 逸脱: なし（本 diff は script ロジックとコメントのみで、markup / class は変更していない）。
