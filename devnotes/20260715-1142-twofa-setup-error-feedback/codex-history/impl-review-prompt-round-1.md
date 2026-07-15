## アプリの使命 (North Star) — AGENTS.md より

AI-CUE は、現場に既にある作業手順書(SOP)を起点に、AI が撮るべきカットを設計した動画シナリオを
生成し、そのシナリオをスマホ(PWA)でナビゲーション撮影することで、専門知識ゼロの現場作業者でも
標準化されたマニュアル動画を作れるようにする。「思考ゼロ・編集ゼロ」。v1 スコープ: 字幕のみ /
撮影は PWA(同一オリジン・セッション認証) / 動画合成は自前 ffmpeg / 単一 Default Project。

## 禁止事項 — AGENTS.md より
1. テストなしの実装完了報告
2. PHPStan エラーの widen・baseline 化
3. dev DB への破壊操作をエージェント判断で実行
4. response()->json() の直書き(DTO / JsonResource / Inertia を使う。仕様固定 endpoint のみ例外)
5. LLM 呼び出しの Prism 直呼び
6. prompt 文字列のコード直書き
7. 操作系 POST の応答での redirect()->intended()
8. 必須条件未充足を理由にボタンを disabled にする UI(押下時にエラー表示する)

## 思考原則 — 全議論に適用
まず仮説を立てろ。何を検証したいのか、なぜそう考えるのか、どうなれば成功と判断するのかを明確に
してから手を動かせ。先人の知恵(Laravel/Fortify/Inertia/Svelte の公式作法)を探せ。機能の名前に
立ち返れ。今必要なものだけ作れ(オーバーエンジニアリング禁止)。テストファースト。

## ツール使用制限
コマンド実行・ファイル書き込みは一切行わず、提供されたテキストの分析に集中すること。ファイル読み込みは許可。

---

## system: レビュアーの役割

あなたは Laravel + Svelte アプリの実装レビュアーです。以下の観点で TODO T059 の実装差分をレビューし、
ファイルごとに判定を述べ、指摘を [Critical] / [Warning] / [Suggestion] に分類し、最後に全体判定
**APPROVED** または **CHANGES_REQUESTED** を明記してください。

レビュー観点:
- 詳細設計との一致性(施策1: 確認 POST に errorBag 指定 / 施策2: vitest 回帰テスト)
- 正確性(Fortify の named error bag "confirmTwoFactorAuthentication" を Inertia の errorBag で
  スコープする修正が、誤コード時に confirmForm.errors.code を解決し UI 表示させる根本原因の解決になっているか)
- テスト網羅性(errorBag 指定の固定 / 誤コード表示 / 正コード成功。回帰を守れているか)
- DTO/JsonResource パターン(本修正は client のみ・サーバ非変更のため該当なしの想定。逸脱がないか)
- セキュリティ(errorBag は visit option でありデータに影響しない。副作用がないか)
- DESIGN.md 準拠(color/radius/typography は token 経由。hex 直書きや token 変更が無いこと。
  本 diff は CSS/token を変更しない)
- Atomic Design 準拠(FormField molecule / Input atom / FormError の既存配線を使い、階層逆流や
  SVG 直書き・Lucide 以外のアイコン追加が無いこと。本 diff は component 構造を変更しない)

## user: データ

### 詳細設計書(要旨)
- bug-hunt F-2-02 (High, validation_gap): 2FA セットアップ確認画面で誤コードを入力しても
  エラーが表示されず無言失敗する。
- 根本原因: Fortify の ConfirmTwoFactorAuthentication は検証失敗を名前付き error bag
  "confirmTwoFactorAuthentication" に投げる(login チャレンジは default bag)。Inertia は default
  bag が無いと named bag をネストしたまま共有するため、client が同名 errorBag を指定しないと
  confirmForm.errors.code が undefined になり FormField がエラーを描画しない。
- 施策1: resources/js/pages/Settings/Security.svelte の confirmForm.post(...) に
  errorBag: "confirmTwoFactorAuthentication" を指定(literal const 化)。UI 表示経路
  (FormField error={confirmForm.errors.code} → FormError / Input aria-invalid)は既存のまま。
- 施策2: vitest 回帰テスト追加。useForm を reactiveUseForm フェイクへ差し替え、
  (a) errorBag 指定の固定 (b) 誤コードレスポンス errors 反映で入力直下にエラー表示 + Input aria-invalid
  (c) 正コード成功で確認フォームが閉じ reset される、を検証。support/reactiveUseForm.svelte.ts を
  後方互換に拡張(reset / processing の $state+getter / setProcessing / respondWithErrors 追加)。
- サーバ側テストはスコープ外(バッグ名は Fortify vendor が固定して投げる契約であり本修正は client のみ)。

### 実装差分 (git diff)

```diff
diff --git a/resources/js/pages/Settings/Security.svelte b/resources/js/pages/Settings/Security.svelte
index f511366..df1b004 100644
--- a/resources/js/pages/Settings/Security.svelte
+++ b/resources/js/pages/Settings/Security.svelte
@@ -79,6 +79,15 @@
     /** 新コード一覧へのフォーカス移動用 (再生成成功時に再保管を促す) */
     let recoveryCodesPanel = $state<HTMLDivElement | null>(null);
 
+    /**
+     * Fortify の 2FA 確認アクション (ConfirmTwoFactorAuthentication) は検証失敗を
+     * 名前付き error bag "confirmTwoFactorAuthentication" に投げる
+     * (login チャレンジ側は default bag)。Inertia は default bag が無いと named bag を
+     * ネストしたまま共有するため、client 側で同名の errorBag を指定しないと
+     * confirmForm.errors.code が解決されず、誤コード時に無言失敗する (F-2-02)。
+     */
+    const CONFIRM_TWO_FACTOR_ERROR_BAG = "confirmTwoFactorAuthentication" as const;
+
     const confirmForm = useForm({
         code: "",
     });
@@ -211,6 +220,8 @@
         event.preventDefault();
         confirmForm.post("/user/confirmed-two-factor-authentication", {
             preserveScroll: true,
+            // Fortify の named error bag からエラーをスコープする (未指定だと errors.code が解決されない)
+            errorBag: CONFIRM_TWO_FACTOR_ERROR_BAG,
             onSuccess: () => {
                 confirming = false;
                 qrSvg = null;
diff --git a/tests/js/pages/SettingsSecurityTwoFactorConfirm.test.ts b/tests/js/pages/SettingsSecurityTwoFactorConfirm.test.ts
new file mode 100644
index 0000000..06912ce
--- /dev/null
+++ b/tests/js/pages/SettingsSecurityTwoFactorConfirm.test.ts
@@ -0,0 +1,198 @@
+import { afterEach, beforeEach, describe, expect, it, vi } from "vitest";
+import { cleanup, fireEvent, render, screen, waitFor } from "@testing-library/svelte";
+import { reactiveUseForm } from "../support/reactiveUseForm.svelte";
+
+/*
+ * Settings/Security 2FA セットアップ確認 (F-2-02 / T059)。
+ * Fortify の ConfirmTwoFactorAuthentication は検証失敗を名前付き error bag
+ * "confirmTwoFactorAuthentication" に投げる。client が同名の errorBag を指定しないと
+ * Inertia が named bag をネストしたまま共有し confirmForm.errors.code が解決されず、
+ * 誤コード時に無言失敗する。本テストは以下を回帰固定する:
+ *   (a) 確認 POST に errorBag: "confirmTwoFactorAuthentication" が付く
+ *   (b) レスポンスの errors 反映で入力直下にエラーが表示され Input が aria-invalid になる
+ *   (c) 正コード成功で確認フォームが閉じ reset される
+ *
+ * useForm を reactiveUseForm フェイクへ差し替え「post の visit options 検証」と
+ * 「named bag エラーからの表示」を分離して検証する。router.post / page は既存テスト同様モック。
+ */
+
+const { routerPostMock, pageState, addToastMock, holder } = vi.hoisted(() => ({
+    routerPostMock: vi.fn(),
+    pageState: {
+        props: {} as Record<string, unknown>,
+        url: "/settings/security",
+    },
+    addToastMock: vi.fn(),
+    holder: { form: null as ReturnType<typeof reactiveUseForm> | null },
+}));
+
+vi.mock("@inertiajs/svelte", async (importOriginal) => ({
+    ...(await importOriginal<typeof import("@inertiajs/svelte")>()),
+    router: { post: routerPostMock },
+    page: pageState,
+    useForm: (init: Record<string, unknown>) => {
+        const form = reactiveUseForm(init);
+        holder.form = form;
+        return form;
+    },
+}));
+
+vi.mock("@/lib/stores/toast", async (importOriginal) => ({
+    ...(await importOriginal<typeof import("@/lib/stores/toast")>()),
+    addToast: addToastMock,
+}));
+
+import Security from "@/pages/Settings/Security.svelte";
+
+const fetchMock = vi.fn();
+
+/** JSON レスポンス風オブジェクト (fetch mock 用) */
+function jsonResponse(ok: boolean, status: number, body: unknown): unknown {
+    return { ok, status, json: () => Promise.resolve(body) };
+}
+
+/** 確認フロー描画に必要な fetch (QR / recent-auth / recovery codes) を stub する */
+function stubFetchRoutes(): void {
+    fetchMock.mockImplementation((input: RequestInfo | URL) => {
+        const url = String(input);
+        if (url.includes("/user/two-factor-qr-code")) {
+            return Promise.resolve(jsonResponse(true, 200, { svg: "<svg></svg>" }));
+        }
+        if (url.includes("/recent-auth/status")) {
+            return Promise.resolve(
+                jsonResponse(true, 200, {
+                    recent: true,
+                    passwordSet: true,
+                    availableProviders: [],
+                    canSatisfy: true,
+                    confirmedAt: 1,
+                }),
+            );
+        }
+        // /user/two-factor-recovery-codes (成功 callback 後の showRecoveryCodes)
+        return Promise.resolve(jsonResponse(true, 200, ["code-a", "code-b"]));
+    });
+}
+
+/** Inertia visit options (第3引数) の検証対象部分 */
+interface InertiaVisitOptions {
+    onStart?: () => void;
+    onSuccess?: () => void;
+    onError?: () => void;
+    onFinish?: () => void;
+}
+
+/** router.post (enableTwoFactor) の第3引数を取り出す */
+function lastRouterVisitOptions(): InertiaVisitOptions {
+    const call = routerPostMock.mock.calls.at(-1);
+    if (!call) throw new Error("router.post が呼ばれていない");
+    return call[2] as InertiaVisitOptions;
+}
+
+function currentForm(): ReturnType<typeof reactiveUseForm> {
+    if (!holder.form) throw new Error("confirmForm フェイクが未生成");
+    return holder.form;
+}
+
+/** confirmForm.post の第2引数 (visit options) を取り出す */
+function lastConfirmPostOptions(): InertiaVisitOptions {
+    const call = currentForm().post.mock.calls.at(-1);
+    if (!call) throw new Error("confirmForm.post が呼ばれていない");
+    return call[1] as InertiaVisitOptions;
+}
+
+/**
+ * 2FA 無効状態から確認フォームを表示させる。
+ * 有効化ボタン押下 → router.post onSuccess で confirming=true にして QR/確認フォームを描画する。
+ */
+async function openConfirmForm(): Promise<void> {
+    await fireEvent.click(screen.getByTestId("enable-two-factor-button"));
+    await waitFor(() => {
+        expect(routerPostMock).toHaveBeenCalledWith(
+            "/user/two-factor-authentication",
+            {},
+            expect.objectContaining({ preserveScroll: true }),
+        );
+    });
+    lastRouterVisitOptions().onSuccess?.();
+    await waitFor(() => {
+        expect(screen.getByLabelText("認証コード")).toBeInTheDocument();
+    });
+}
+
+/** 認証コードを入力して確認フォームを submit する */
+async function submitConfirm(code = "123456"): Promise<void> {
+    await fireEvent.input(screen.getByLabelText("認証コード"), { target: { value: code } });
+    await fireEvent.click(screen.getByRole("button", { name: "確認して有効化" }));
+}
+
+beforeEach(() => {
+    holder.form = null;
+    pageState.props = {
+        appName: "AI-CUE",
+        auth: { user: { id: 1, name: "テスト太郎", twoFactorEnabled: false } },
+    };
+    stubFetchRoutes();
+    vi.stubGlobal("fetch", fetchMock);
+});
+
+afterEach(() => {
+    cleanup();
+    vi.unstubAllGlobals();
+    routerPostMock.mockReset();
+    addToastMock.mockReset();
+    fetchMock.mockReset();
+});
+
+describe("Settings/Security 2FA 確認 (F-2-02: 誤コードエラー表示)", () => {
+    it("(a) 確認 POST に errorBag: confirmTwoFactorAuthentication を指定する", async () => {
+        render(Security, { props: {} });
+
+        await openConfirmForm();
+        await submitConfirm();
+
+        expect(currentForm().post).toHaveBeenCalledWith(
+            "/user/confirmed-two-factor-authentication",
+            expect.objectContaining({ errorBag: "confirmTwoFactorAuthentication" }),
+        );
+    });
+
+    it("(b) 誤コードのレスポンス errors 反映で入力直下にエラーを表示し Input を aria-invalid にする", async () => {
+        render(Security, { props: {} });
+
+        await openConfirmForm();
+        await submitConfirm("000000");
+
+        // Inertia がレスポンス受領後に form.errors を更新する挙動を模倣 (named bag からスコープ済み)
+        currentForm().respondWithErrors({ code: "認証コードが無効です" });
+
+        await waitFor(() => {
+            expect(screen.getByText("認証コードが無効です")).toBeInTheDocument();
+        });
+        // 入力直下 (#two-factor-code-error) に文言が紐づく
+        expect(screen.getByText("認証コードが無効です")).toHaveAttribute(
+            "id",
+            "two-factor-code-error",
+        );
+        // Input が error 状態 (赤枠 class は実装詳細のため aria-invalid で固定する)
+        expect(screen.getByLabelText("認証コード")).toHaveAttribute("aria-invalid", "true");
+    });
+
+    it("(c) 正コード成功で確認フォームが閉じ reset される", async () => {
+        render(Security, { props: {} });
+
+        await openConfirmForm();
+        await submitConfirm("123456");
+
+        const form = currentForm();
+        // 成功 callback を発火 (Inertia visit 成功時の onSuccess)
+        lastConfirmPostOptions().onSuccess?.();
+
+        await waitFor(() => {
+            expect(screen.queryByLabelText("認証コード")).toBeNull();
+        });
+        expect(form.reset).toHaveBeenCalled();
+        // 有効化ボタンに戻る (twoFactorEnabled は依然 false のため)
+        expect(screen.getByTestId("enable-two-factor-button")).toBeInTheDocument();
+    });
+});
diff --git a/tests/js/support/reactiveUseForm.svelte.ts b/tests/js/support/reactiveUseForm.svelte.ts
index e4aef57..fd08ffc 100644
--- a/tests/js/support/reactiveUseForm.svelte.ts
+++ b/tests/js/support/reactiveUseForm.svelte.ts
@@ -6,6 +6,10 @@ import { vi } from "vitest";
  * fakeUseForm は errors が非反応な plain object のため「clearErrors で赤枠/文言が消える」
  * 再描画を観測できない。本フェイクは errors を $state で持ち、clearErrors がキーを削除すると
  * バインド先 (FormField の error prop) が再評価される = ユーザー体験と同じ挙動を検証できる。
+ *
+ * processing も $state + getter で保持し、setProcessing(bool) で onStart→onFinish 遷移
+ * (loading={form.processing}) を検証できる。reset()・respondWithErrors() は Inertia が
+ * 成功/失敗レスポンス受領後に form を更新する挙動 (reset / form.errors 反映) を模倣する。
  */
 export function reactiveUseForm<TData extends Record<string, unknown>>(
   initial: TData,
@@ -13,11 +17,20 @@ export function reactiveUseForm<TData extends Record<string, unknown>>(
 ): TData & {
   errors: Record<string, string>;
   processing: boolean;
+  reset: ReturnType<typeof vi.fn>;
   clearErrors: (...keys: string[]) => void;
   transform: (fn: (data: TData) => unknown) => { post: ReturnType<typeof vi.fn> };
   post: ReturnType<typeof vi.fn>;
+  /** テスト用: processing ($state) を切り替える。onStart→onFinish 遷移の観測に使う。 */
+  setProcessing: (value: boolean) => void;
+  /**
+   * テスト用: Inertia がレスポンス受領後に form.errors を更新する挙動を模倣する。
+   * リアクティブな errors ($state) へ Object.assign で反映し、FormField を再評価させる。
+   */
+  respondWithErrors: (next: Record<string, string>) => void;
 } {
   const errors = $state<Record<string, string>>({ ...initialErrors });
+  let processing = $state(false);
   const post = vi.fn();
 
   const form = {
@@ -25,7 +38,10 @@ export function reactiveUseForm<TData extends Record<string, unknown>>(
     get errors() {
       return errors;
     },
-    processing: false,
+    get processing() {
+      return processing;
+    },
+    reset: vi.fn(),
     clearErrors: vi.fn((...keys: string[]) => {
       if (keys.length === 0) {
         for (const key of Object.keys(errors)) delete errors[key];
@@ -37,6 +53,12 @@ export function reactiveUseForm<TData extends Record<string, unknown>>(
       return { post };
     },
     post,
+    setProcessing(value: boolean) {
+      processing = value;
+    },
+    respondWithErrors(next: Record<string, string>) {
+      Object.assign(errors, next);
+    },
   };
 
   return form;

```

### テスト結果
- 新規テストファイル tests/js/pages/SettingsSecurityTwoFactorConfirm.test.ts 単独: 3 passed (3)
- pnpm typecheck: OK / pnpm lint: OK / pnpm build: OK
- 既存 tests/js/pages/SettingsSecurity.test.ts (F-10 群) は非改変で維持

### design system 参照(補足)
- 本 diff は resources/css/tokens.css を変更せず、hex 直書き・新規 SVG・Lucide 以外のアイコン追加も無い。
- 触れた UI 表示要素は既存の FormField(molecule) / Input(atom) / FormError で、階層や import 方向は不変更。
