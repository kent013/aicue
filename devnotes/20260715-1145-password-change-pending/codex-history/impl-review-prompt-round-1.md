# Codex 実装レビュー依頼: T060 password-change-pending (Round 1)

## アプリの使命 (North Star / AGENTS.md より)

**AI-CUE** は、現場に既にある**作業手順書(SOP)を起点に**、AI が撮るべきカットを設計した**動画シナリオ**を生成し、そのシナリオを**スマホ(PWA)でナビゲーション撮影**することで、専門知識ゼロの現場作業者でも**標準化されたマニュアル動画**を作れるようにする。

- 「思考ゼロ・編集ゼロ」— 台本作成・撮影判断・編集の 3 ハードルを AI とナビ撮影で肩代わりする。
- 競合(OJT を撮って形式化する tebiki)と異なり、標準作業を起点に AI が教材設計し撮影を指示する。
- v1 スコープ: 字幕のみ / 撮影は PWA(同一オリジン・セッション認証) / 動画合成は自前 ffmpeg / 単一 Default Project。

## 禁止事項 (AGENTS.md より)

1. テストなしの実装完了報告
2. PHPStan エラーの widen・baseline 化
3. dev DB への破壊操作をエージェント判断で実行
4. `response()->json()` の直書き (DTO / JsonResource / Inertia を使う。仕様固定 endpoint のみ例外)
5. LLM 呼び出しの Prism 直呼び
6. prompt 文字列のコード直書き
7. 操作系 POST の応答での `redirect()->intended()`
8. **必須条件未充足を理由にボタンを disabled にする UI (押下時にエラー表示する。DESIGN.md)**

## 思考原則 (全議論に適用)

まず仮説を立てろ。何を検証したいのか、なぜそう考えるのか、どうなれば成功と判断するのかを明確にしてから手を動かせ。先人の知恵 (Laravel / Svelte / Inertia の公式作法) を探せ。機能の名前に立ち返れ。オーバーエンジニアリング禁止 (今必要なものだけ作る)。後方互換の並走を残さない。

## ツール使用制限

コマンド実行・ファイル書き込みは一切行わず、提供されたテキストの分析に集中すること。ファイル読み込みは許可。

---

## system: レビュアーの役割

あなたは Laravel + Svelte (Inertia) アプリの改善実装をレビューするシニアレビュアーである。以下の観点で本 diff をレビューし、ファイルごとに判定し、指摘を **[Critical] / [Warning] / [Suggestion]** に分類し、最後に全体判定 **APPROVED / CHANGES_REQUESTED** を明示せよ。

レビュー観点:
1. **設計との一致性**: 添付の detailed-design.md の施策 1〜4 と実装が一致しているか。
2. **正確性**: `clearErrors()` を送信開始時に呼ぶことで前回エラーが pending 中に消える挙動が正しく実現されているか。`clearErrors()` 引数なしが `errorBag: "updatePassword"` 使用時も過不足なくフィールドを消すという設計前提が妥当か。
3. **テスト網羅性**: 新規 4 テスト (エラークリア DOM / clearErrors→put 順序 / 「変更中…」+disabled+aria-busy / onSuccess→reset 配線) がバグ修正の再現と回帰防止に十分か。既存 password 系 4 テストを反応的 double 差し替えで壊していないか。
4. **後方互換 (reactiveUseForm)**: 施策 3 の helper 拡張 (put/patch/reset 追加・processing の getter/setter 化) が既存 2 consumer (`ManualsCreate.test.ts` / `DuplicateManualDialog.test.ts`, いずれも post のみ参照) を壊さないか。
5. **禁止事項 8 との整合**: Button の disabled は「送信処理中の二重送信防止」であり「必須条件未充足の事前 disabled」ではない — この整合が保たれているか。
6. **DESIGN.md / Atomic Design 準拠**: 本 diff は design token (color/radius/typography) を新規追加せず、hex 直書き・新規 SVG も増やさない。スピナーは既存 `Button` atom の `loading`(内部 `LoaderCircle`)を流用し二重の pending 機構を作っていない。この方針が守られているか。
7. **オーバーエンジニアリング**: 文言の条件分岐 + 既存メソッド 1 呼び出しに閉じ、不要な複雑化がないか。

---

## user

### 詳細設計書 (detailed-design.md)

（施策 1: 送信開始時 `passwordForm.clearErrors()` 追加 / 施策 2: ボタン文言を processing 中「変更中…」へ切替 / 施策 3: `reactiveUseForm` テスト double に put/patch/reset + 反応的 processing を additive 拡張 / 施策 4: `SettingsIndex.test.ts` の password 分岐を反応的 double へ差し替え + 新規 describe 追加。既存 password 系 4 ケースは無改変で green を維持する方針。詳細は下記ファイルを参照）

設計ファイル: `/workspace/devnotes/20260715-1145-password-change-pending/detailed-design.md`（読み込み可）

### 実装差分 (git diff)

```diff
diff --git a/resources/js/pages/Settings/Index.svelte b/resources/js/pages/Settings/Index.svelte
index dc82b52..91c6594 100644
--- a/resources/js/pages/Settings/Index.svelte
+++ b/resources/js/pages/Settings/Index.svelte
@@ -85,6 +85,9 @@
 
     function submitPassword(event: SubmitEvent): void {
         event.preventDefault();
+        // 送信中の誤認防止のため、前回エラーを送信開始時に明示クリアする
+        // (Inertia useForm は送信ではクリアせず応答後にのみ errors を更新するため)。
+        passwordForm.clearErrors();
         passwordForm.put("/user/password", {
             errorBag: "updatePassword",
             preserveScroll: true,
@@ -224,7 +227,7 @@
                 </FormField>
                 <div>
                     <Button type="submit" loading={passwordForm.processing}>
-                        パスワードを変更
+                        {passwordForm.processing ? "変更中…" : "パスワードを変更"}
                     </Button>
                 </div>
             </form>
diff --git a/tests/js/pages/SettingsIndex.test.ts b/tests/js/pages/SettingsIndex.test.ts
index ef0c11d..39f1fa5 100644
--- a/tests/js/pages/SettingsIndex.test.ts
+++ b/tests/js/pages/SettingsIndex.test.ts
@@ -29,34 +29,44 @@ const { pageState, routerDeleteMock, formHolder, formSeed } = vi.hoisted(() => (
     formSeed: { passwordErrors: {} as Record<string, string> },
 }));
 
-vi.mock("@inertiajs/svelte", async (importOriginal) => ({
-    ...(await importOriginal<typeof import("@inertiajs/svelte")>()),
-    router: { delete: routerDeleteMock },
-    page: pageState,
-    // useForm を最小 fake に差し替え、profileForm.put を spy する (case 6)。
-    // email キーを持つ form を profileForm とみなし holder に記録する。
-    useForm: (initial: Record<string, unknown>) => {
-        const form: Record<string, unknown> = {
-            ...initial,
-            errors: "current_password" in initial ? { ...formSeed.passwordErrors } : {},
-            processing: false,
-            get: vi.fn(),
-            post: vi.fn(),
-            put: vi.fn(),
-            patch: vi.fn(),
-            delete: vi.fn(),
-            submit: vi.fn(),
-            reset: vi.fn(),
-            clearErrors: vi.fn(),
-        };
-        if ("email" in initial) {
-            formHolder.profile = form;
-        } else if ("current_password" in initial) {
-            formHolder.password = form;
-        }
-        return form;
-    },
-}));
+vi.mock("@inertiajs/svelte", async (importOriginal) => {
+    // password フォームは反応的 double を使う (clearErrors で errors が消える再描画、
+    // processing=true で pending 文言が出る再描画を DOM で観測するため)。hoisting 制約は
+    // async factory 内の dynamic import で回避する (既存 ManualsCreate.test と同じ helper)。
+    const { reactiveUseForm } = await import("../support/reactiveUseForm.svelte");
+    return {
+        ...(await importOriginal<typeof import("@inertiajs/svelte")>()),
+        router: { delete: routerDeleteMock },
+        page: pageState,
+        // useForm を fake に差し替え、form を holder に記録する。
+        //   "current_password" を持つ → passwordForm: 反応的 double
+        //   "email" を持つ → profileForm: 最小 fake (put を spy)
+        useForm: (initial: Record<string, unknown>) => {
+            if ("current_password" in initial) {
+                const form = reactiveUseForm(initial, { ...formSeed.passwordErrors });
+                formHolder.password = form;
+                return form;
+            }
+            const form: Record<string, unknown> = {
+                ...initial,
+                errors: {},
+                processing: false,
+                get: vi.fn(),
+                post: vi.fn(),
+                put: vi.fn(),
+                patch: vi.fn(),
+                delete: vi.fn(),
+                submit: vi.fn(),
+                reset: vi.fn(),
+                clearErrors: vi.fn(),
+            };
+            if ("email" in initial) {
+                formHolder.profile = form;
+            }
+            return form;
+        },
+    };
+});
 
 // eslint-disable-next-line import/first
 import Index from "@/pages/Settings/Index.svelte";
@@ -393,3 +403,82 @@ describe("Settings/Index パスワード変更フォームの表示トグル (T0
         expect(options.errorBag).toBe("updatePassword");
     });
 });
+
+describe("Settings/Index パスワード変更の pending / エラークリア (F-4-01)", () => {
+    /** submit ボタンを含む form 要素を null 安全に取得し、submit の完了を待つ */
+    async function submitPasswordForm(): Promise<void> {
+        const submit = screen.getByRole("button", { name: /パスワードを変更|変更中…/ });
+        const formEl = submit.closest("form");
+        expect(formEl).not.toBeNull();
+        await fireEvent.submit(formEl as HTMLFormElement);
+    }
+
+    it("送信すると前回のエラー文言が pending 中に画面から消える (clearErrors)", async () => {
+        formSeed.passwordErrors = { current_password: "現在のパスワードが違います" };
+        render(Index, { props: {} });
+
+        // 前回の失敗エラーが初期表示されている
+        expect(screen.getByText("現在のパスワードが違います")).toBeInTheDocument();
+
+        // 送信 → submitPassword が clearErrors() → 反応的 errors が空になり文言が DOM から消える
+        await submitPasswordForm();
+
+        await waitFor(() =>
+            expect(screen.queryByText("現在のパスワードが違います")).toBeNull(),
+        );
+        // 送信自体は継続している (put が 1 回呼ばれる)
+        const passwordForm = formHolder.password;
+        expect(passwordForm?.clearErrors as ReturnType<typeof vi.fn>).toHaveBeenCalledTimes(1);
+        expect(passwordForm?.put as ReturnType<typeof vi.fn>).toHaveBeenCalledTimes(1);
+    });
+
+    it("clearErrors は put より前に呼ばれる (pending 前にエラーを消す)", async () => {
+        formSeed.passwordErrors = { current_password: "現在のパスワードが違います" };
+        render(Index, { props: {} });
+
+        await submitPasswordForm();
+
+        const form = formHolder.password;
+        const clearMock = form?.clearErrors as ReturnType<typeof vi.fn>;
+        const putMock = form?.put as ReturnType<typeof vi.fn>;
+        // まず両方が確実に 1 回ずつ呼ばれたことを確認 (invocationCallOrder が undefined になる偽陽性を防ぐ)
+        await waitFor(() => expect(putMock).toHaveBeenCalledTimes(1));
+        expect(clearMock).toHaveBeenCalledTimes(1);
+        // その上で呼び出し順序を比較する
+        expect(clearMock.mock.invocationCallOrder[0]).toBeLessThan(
+            putMock.mock.invocationCallOrder[0],
+        );
+    });
+
+    it("送信中は『変更中…』文言 + disabled + aria-busy を示す", async () => {
+        render(Index, { props: {} });
+
+        // 通常時は「パスワードを変更」
+        expect(screen.getByRole("button", { name: "パスワードを変更" })).toBeInTheDocument();
+
+        // processing=true に切替 (反応的 double)。tick 1 回依存でフレークしないよう waitFor で待つ
+        const form = formHolder.password as { processing: boolean };
+        form.processing = true;
+
+        await waitFor(() =>
+            expect(screen.getByRole("button", { name: "変更中…" })).toBeInTheDocument(),
+        );
+        const busyButton = screen.getByRole("button", { name: "変更中…" });
+        expect(busyButton).toBeDisabled();
+        expect(busyButton).toHaveAttribute("aria-busy", "true");
+    });
+
+    it("成功時はフォームを reset する (成功トーストはサーバ flash 経由 = 別テストで担保)", async () => {
+        render(Index, { props: {} });
+        const form = formHolder.password;
+        const putMock = form?.put as ReturnType<typeof vi.fn>;
+
+        await submitPasswordForm();
+        await waitFor(() => expect(putMock).toHaveBeenCalledTimes(1));
+
+        // put のオプションの onSuccess が reset を呼ぶ配線を検証
+        const options = putMock.mock.calls.at(-1)?.[1] as { onSuccess?: () => void };
+        options.onSuccess?.();
+        expect(form?.reset as ReturnType<typeof vi.fn>).toHaveBeenCalledTimes(1);
+    });
+});
diff --git a/tests/js/support/reactiveUseForm.svelte.ts b/tests/js/support/reactiveUseForm.svelte.ts
index e4aef57..35c4748 100644
--- a/tests/js/support/reactiveUseForm.svelte.ts
+++ b/tests/js/support/reactiveUseForm.svelte.ts
@@ -14,18 +14,34 @@ export function reactiveUseForm<TData extends Record<string, unknown>>(
   errors: Record<string, string>;
   processing: boolean;
   clearErrors: (...keys: string[]) => void;
-  transform: (fn: (data: TData) => unknown) => { post: ReturnType<typeof vi.fn> };
+  reset: ReturnType<typeof vi.fn>;
+  transform: (fn: (data: TData) => unknown) => {
+    post: ReturnType<typeof vi.fn>;
+    put: ReturnType<typeof vi.fn>;
+    patch: ReturnType<typeof vi.fn>;
+  };
   post: ReturnType<typeof vi.fn>;
+  put: ReturnType<typeof vi.fn>;
+  patch: ReturnType<typeof vi.fn>;
 } {
   const errors = $state<Record<string, string>>({ ...initialErrors });
+  // 反応的: テストから true にすると pending 文言 (「変更中…」) を再描画で観測できる。
+  let processing = $state(false);
   const post = vi.fn();
+  const put = vi.fn();
+  const patch = vi.fn();
 
   const form = {
     ...initial,
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
@@ -33,10 +49,15 @@ export function reactiveUseForm<TData extends Record<string, unknown>>(
       }
       for (const key of keys) delete errors[key];
     }),
+    reset: vi.fn(),
     transform() {
-      return { post };
+      // 戻り値に put/patch も含め、将来 transform().put(...) 連鎖テストでも不整合を出さない
+      // (既存 consumer は post のみ参照で後方互換)。
+      return { post, put, patch };
     },
     post,
+    put,
+    patch,
   };
 
   return form;

```

### テスト結果

- `tests/js/pages/SettingsIndex.test.ts`: 17 passed (既存 13 + 新規 4)
- 全 frontend スイート (`pnpm test --testTimeout=30000`): 全 80 test files / 735 tests passed (worktree のメモリ制約で単一プロセス実行が OOM するため 4 バッチに分割して foreground 実行、全バッチ green)。うち SettingsIndex 17 passed (既存 13 + 新規 4)、reactiveUseForm 既存 consumer (ManualsCreate + DuplicateManualDialog) も green
- `pnpm typecheck` (tsc --noEmit): OK
- `pnpm lint` (eslint resources/js): OK
- `pnpm build` (vite build): OK
- PHP 変更なし (PHPStan 対象外)

### design system 参照

- 触れた atomic 層: `components/atoms/Button.svelte`(既読・変更なし)。`loading` 時に `<button disabled={disabled||loading} aria-busy={loading||undefined}>` を出し、children(文言)を LoaderCircle(aria-hidden)と併置して描画する。本 diff はこの atom の呼び出し側(page 層 `Settings/Index.svelte`)の children 文言を条件分岐しただけ。
- design token・hex・新規 SVG・新規アイコンの追加なし。CSS 変更なし。
