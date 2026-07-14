# 使命・禁止事項・思考原則（このレビューに適用）

## アプリの使命 (North Star)

**AI-CUE** は、現場に既にある**作業手順書(SOP)を起点に**、AI が撮るべきカットを設計した**動画シナリオ**を生成し、そのシナリオを**スマホ(PWA)でナビゲーション撮影**することで、専門知識ゼロの現場作業者でも**標準化されたマニュアル動画**を作れるようにする。

- 「思考ゼロ・編集ゼロ」— 台本作成・撮影判断・編集の 3 ハードルを AI とナビ撮影で肩代わりする。
- 競合(OJT を撮って形式化する tebiki)と異なり、**標準作業を起点に AI が教材設計し撮影を指示する**。
- 熟練者の暗黙知を動画マニュアルという形式知へ変換する装置（SECI）。

本件はこの使命を支える **UI 一貫性・可読性** の軽微 (Low) 改善。

## 禁止事項

1. テストなしの実装完了報告
2. PHPStan エラーの widen・baseline 化
3. dev DB への破壊操作
4. `response()->json()` の直書き
5. LLM 呼び出しの Prism 直呼び
6. prompt 文字列のコード直書き
7. 操作系 POST 応答での `redirect()->intended()`
8. 必須条件未充足を理由にボタンを disabled にする UI

→ 本件はフロント表示のみ。1・8 が関係（テスト必須 / disabled 不使用維持）。2〜7 はバックエンド無変更のため非該当。

## 思考原則 — 全議論に適用

まず仮説を立てろ。何を検証したいのか、なぜそう考えるのか、どうなれば成功と判断するのかを明確にしてから手を動かせ。仮説なき改善はただの試行錯誤であり、結果から学ぶことができない。

データに真摯に向き合え。成果だけでなく、多様性の変化、構造の揺らぎ、想定外のパターン — 全てが判断材料になる。数値を見て即座に閾値を弄るな。何が起きているのかを理解し、なぜそうなったのかを考え、どの方向に進むべきかを判断してから手を動かせ。

先人の知恵を探せ。乗るべき巨人の肩があるなら乗れ。

機能の名前に立ち返れ。名前はその機能が果たすべき役割を示している。現在の設計がその役割を果たしているか、常に問え。

仕組みが機能していない段階で値を弄るな。設計そのものを見直せ。

## ツール使用制限

コマンド実行・ファイル書き込みは一切行わず、提供されたテキストの分析に集中すること。ファイル読み込みは許可。

---

# 役割・タスク

あなたは Svelte 5 + Inertia + Tailwind v4 + TypeScript フロントエンドの実装レビュアーである。
TODO **T042**「軽微UI: manage/users のタブレット名切れと settings のパスワード表示トグル」の実装 diff をレビューし、
設計 (`devnotes/20260714-1338-minor-ui-polish/detailed-design.md`) との合致・回帰リスク・テストの妥当性を判定せよ。

## 施策の要旨（詳細設計より）

- **S1** (`resources/js/pages/Admin/Users.svelte`): manage/users のメンバー行・招待行で、タブレット幅 (~768px) で名前/メール列が過剰 truncate される問題を解消。
  - 変更方針: 行 `<li>` に `sm:flex-wrap` を追加し `sm:justify-between` を除去。名前/メール列に `sm:min-w-40` (10rem) の最小幅の床を付与。操作ブロックに `sm:ml-auto` を付与して右寄せ整列を維持。`sm:flex-row` ブレークポイントは不変。
  - 狙い: 834px (iPad portrait) では 1 行維持、768px 付近で操作ブロックが折り返す（横スクロール F-14 を新設しない）。
- **S2** (`resources/js/pages/Settings/Index.svelte`): パスワード変更フォームの 2 入力を素の `Input type="password"` から既存 molecule `PasswordInput` に差し替え、表示トグルを付与（Login/Register/ResetPassword と操作モデルを統一）。
  - `PasswordInput` は `type` を Omit した Props を持ち、`type` は内部で `visible` 状態から導出。`autocomplete` / `aria-describedby` は rest props として Input へ透過。

## PasswordInput molecule の実装（参考）

- `id` 必須、`value` bindable、`error`、`disabled`、`class`、`testId` + 残余 HTMLInputAttributes。
- 内部: `<div class="relative"><Input {...rest} {id} type={inputType} .../> <button aria-label={visible?"パスワードを非表示":"パスワードを表示"} aria-pressed={visible} aria-controls={id} .../></div>`
- `inputType = visible ? "text" : "password"`。

## FormField molecule の実装（参考）

- `describedBy` は `error` または `help` があるときだけ `${id}-error` / `${id}-help` を結合して生成し、いずれも無ければ `undefined`。
- children snippet に `{ id, describedBy, invalid }` を渡す。

## テストの補正点（レビュー対象）

前回実装で `SettingsIndex.test.ts` の「autocomplete / aria-describedby 透過」ケースが失敗した。原因は FormField がエラー無しでは `aria-describedby` を生成しないため、素の `Input` でも同じく属性が付かない（テストの前提誤り）。補正として useForm fake に `formSeed.passwordErrors` を導入し、当該ケースだけ両フィールドにエラーを載せて `aria-describedby="{id}-error"` の透過を検証するよう変更した。この補正が妥当か、テストとして意味のある不変条件を固定できているかも評価せよ。

## 品質ゲート結果（worktree 内）

- `pnpm typecheck` green / `pnpm lint` green / `pnpm build` green
- `pnpm test --testTimeout=30000` 全 green: 72 files / 542 tests
- PHP 変更なし（diff は resources/ と tests/ のみ）

## 判定の観点

1. 設計 (S1/S2) との合致（クラス構成・molecule 差し替えが設計どおりか）
2. 回帰リスク（wrap 時の整列、min-w-40 の床が 834px で早すぎる折り返しを起こさないか等。ただし jsdom はレイアウト非計算なのでクラス不変条件がプロキシである点を踏まえる）
3. テストの妥当性（不変条件を正しく固定しているか、over/under assert していないか、aria-describedby 補正が妥当か）
4. Atomic Design / DESIGN.md 準拠（単方向 import、token、Lucide のみ、SVG 直書き無し）
5. 禁止事項 1・8 の遵守

## 出力フォーマット

- 冒頭に **判定: APPROVED / CHANGES_REQUESTED** を明記。
- 指摘は **[Critical] / [Warning] / [Suggestion]** で分類し、各々にファイル・根拠・提案を添える。
- APPROVED の場合もあれば残存する軽微な観点を Suggestion として挙げてよい。

---

# 実装 diff（レビュー対象）

```diff
diff --git a/resources/js/pages/Admin/Users.svelte b/resources/js/pages/Admin/Users.svelte
index 4798fb0..d4d77f6 100644
--- a/resources/js/pages/Admin/Users.svelte
+++ b/resources/js/pages/Admin/Users.svelte
@@ -268,9 +268,9 @@
                     {#each members as member (member.id)}
                         <!-- 375px 方針: モバイルは縦積み、sm 以上は現行の横並び (F-14)。操作ブロックは要素単位で折り返し可 -->
                         <li
-                            class="flex flex-col gap-2 py-3 sm:flex-row sm:items-center sm:justify-between sm:gap-4"
+                            class="flex flex-col gap-2 py-3 sm:flex-row sm:flex-wrap sm:items-center sm:gap-4"
                         >
-                            <div class="min-w-0">
+                            <div class="min-w-0 sm:min-w-40">
                                 <div class="flex items-center gap-2">
                                     <p class="truncate text-body">{member.name}</p>
                                     {#if member.twoFactorStatus === "enabled"}
@@ -286,7 +286,7 @@
                                     {member.email}
                                 </p>
                             </div>
-                            <div class="flex flex-wrap items-center gap-2 sm:shrink-0 sm:justify-end">
+                            <div class="flex flex-wrap items-center gap-2 sm:ml-auto sm:shrink-0 sm:justify-end">
                                 {#if canResetTwoFactor(member)}
                                     <Button
                                         variant="danger-ghost"
@@ -419,10 +419,10 @@
                         {#each invitations as invitation (invitation.id)}
                             <!-- 375px 方針: モバイルは縦積み、sm 以上は現行の横並び (F-14) -->
                             <li
-                                class="flex flex-col gap-2 py-3 sm:flex-row sm:items-center sm:justify-between sm:gap-4"
+                                class="flex flex-col gap-2 py-3 sm:flex-row sm:flex-wrap sm:items-center sm:gap-4"
                             >
-                                <p class="min-w-0 truncate text-body">{invitation.email}</p>
-                                <div class="flex flex-wrap items-center gap-3 sm:shrink-0 sm:justify-end">
+                                <p class="min-w-0 truncate text-body sm:min-w-40">{invitation.email}</p>
+                                <div class="flex flex-wrap items-center gap-3 sm:ml-auto sm:shrink-0 sm:justify-end">
                                     <p class="text-caption text-text-secondary">
                                         {invitation.roleLabel} ・ 期限 {invitation.expiresAt}
                                     </p>
diff --git a/resources/js/pages/Settings/Index.svelte b/resources/js/pages/Settings/Index.svelte
index 3a29196..dc82b52 100644
--- a/resources/js/pages/Settings/Index.svelte
+++ b/resources/js/pages/Settings/Index.svelte
@@ -7,6 +7,7 @@
     import TextLink from "@/components/atoms/TextLink.svelte";
     import DangerZone from "@/components/molecules/DangerZone.svelte";
     import FormField from "@/components/molecules/FormField.svelte";
+    import PasswordInput from "@/components/molecules/PasswordInput.svelte";
     import ConfirmDialog from "@/components/organisms/ConfirmDialog.svelte";
     import RecentAuthModal from "@/components/organisms/RecentAuthModal.svelte";
     import AppLayout from "@/components/templates/AppLayout.svelte";
@@ -197,9 +198,8 @@
                     error={passwordForm.errors.current_password}
                 >
                     {#snippet children({ id, describedBy, invalid })}
-                        <Input
+                        <PasswordInput
                             {id}
-                            type="password"
                             bind:value={passwordForm.current_password}
                             error={invalid}
                             aria-describedby={describedBy}
@@ -213,9 +213,8 @@
                     error={passwordForm.errors.password}
                 >
                     {#snippet children({ id, describedBy, invalid })}
-                        <Input
+                        <PasswordInput
                             {id}
-                            type="password"
                             bind:value={passwordForm.password}
                             error={invalid}
                             aria-describedby={describedBy}
diff --git a/tests/js/pages/AdminUsers.test.ts b/tests/js/pages/AdminUsers.test.ts
index a498a84..e5d0f77 100644
--- a/tests/js/pages/AdminUsers.test.ts
+++ b/tests/js/pages/AdminUsers.test.ts
@@ -234,11 +234,18 @@ describe("Admin/Users", () => {
         const roleSelect = screen.getByTestId("member-role-3");
         const row = roleSelect.closest("li");
         expect(row).not.toBeNull();
-        expect(row).toHaveClass("flex-col", "sm:flex-row");
+        expect(row).toHaveClass("flex-col", "sm:flex-row", "sm:flex-wrap");
+        // 行折り返しへ切替済み: justify-between へ逆戻りしていないこと (T042 S1)
+        expect(row).not.toHaveClass("sm:justify-between");
 
         const actions = roleSelect.parentElement;
         expect(actions).not.toBeNull();
-        expect(actions).toHaveClass("flex-wrap");
+        expect(actions).toHaveClass("flex-wrap", "sm:ml-auto");
+
+        // 名前/メール列は sm 以上で最小幅の床を持ち、過剰 truncate を防ぐ (T042 S1)
+        const nameColumn = screen.getByText("unassigned@example.com").parentElement;
+        expect(nameColumn).not.toBeNull();
+        expect(nameColumn).toHaveClass("min-w-0", "sm:min-w-40");
 
         // bug-hunt 実測の最悪幅構成 (2FA バッジ + 未割当バッジ + 2FA 解除 + 未割当 select + 削除)
         // が同一行に揃っていることを固定する
@@ -258,11 +265,17 @@ describe("Admin/Users", () => {
         const revokeButton = screen.getByTestId("revoke-invitation-10");
         const row = revokeButton.closest("li");
         expect(row).not.toBeNull();
-        expect(row).toHaveClass("flex-col", "sm:flex-row");
+        expect(row).toHaveClass("flex-col", "sm:flex-row", "sm:flex-wrap");
+        // 行折り返しへ切替済み: justify-between へ逆戻りしていないこと (T042 S1)
+        expect(row).not.toHaveClass("sm:justify-between");
 
         const actions = revokeButton.parentElement;
         expect(actions).not.toBeNull();
-        expect(actions).toHaveClass("flex-wrap");
+        expect(actions).toHaveClass("flex-wrap", "sm:ml-auto");
+
+        // 招待メール列は sm 以上で最小幅の床を持つ (T042 S1)
+        const emailColumn = screen.getByText("invited@example.com");
+        expect(emailColumn).toHaveClass("min-w-0", "truncate", "sm:min-w-40");
     });
 
     it("削除 ConfirmDialog はメンバー名入りの警告文言を持つ", async () => {
diff --git a/tests/js/pages/SettingsIndex.test.ts b/tests/js/pages/SettingsIndex.test.ts
index c9ee7c2..ef0c11d 100644
--- a/tests/js/pages/SettingsIndex.test.ts
+++ b/tests/js/pages/SettingsIndex.test.ts
@@ -1,5 +1,5 @@
 import { afterEach, beforeEach, describe, expect, it, vi } from "vitest";
-import { cleanup, fireEvent, render, screen, waitFor } from "@testing-library/svelte";
+import { cleanup, fireEvent, render, screen, waitFor, within } from "@testing-library/svelte";
 
 /*
  * プロフィール設定画面 (T025: 唯一オーナーのアカウント削除ガード)。
@@ -11,14 +11,22 @@ import { cleanup, fireEvent, render, screen, waitFor } from "@testing-library/sv
  * - 削除 (router.delete) の onError はダイアログを閉じる (押下後に理由が見える)
  */
 
-const { pageState, routerDeleteMock, formHolder } = vi.hoisted(() => ({
+const { pageState, routerDeleteMock, formHolder, formSeed } = vi.hoisted(() => ({
     pageState: {
         props: {} as Record<string, unknown>,
         url: "/settings",
     },
     routerDeleteMock: vi.fn(),
-    // profileForm (email キーを持つ form) を捕捉する holder。case 6 で put を検証する。
-    formHolder: { profile: null as Record<string, unknown> | null },
+    // useForm fake が捕捉する各 form の holder。初期データキーで二分岐する:
+    //   "email" を持つ → profileForm (case 6 で put を検証)
+    //   "current_password" を持つ → passwordForm (T042 S2 で put/errorBag を検証)
+    formHolder: {
+        profile: null as Record<string, unknown> | null,
+        password: null as Record<string, unknown> | null,
+    },
+    // passwordForm の初期 errors シード。FormField は error があるときだけ
+    // aria-describedby を生成するため、透過検証ケースだけがここに値を入れる。
+    formSeed: { passwordErrors: {} as Record<string, string> },
 }));
 
 vi.mock("@inertiajs/svelte", async (importOriginal) => ({
@@ -30,7 +38,7 @@ vi.mock("@inertiajs/svelte", async (importOriginal) => ({
     useForm: (initial: Record<string, unknown>) => {
         const form: Record<string, unknown> = {
             ...initial,
-            errors: {},
+            errors: "current_password" in initial ? { ...formSeed.passwordErrors } : {},
             processing: false,
             get: vi.fn(),
             post: vi.fn(),
@@ -43,6 +51,8 @@ vi.mock("@inertiajs/svelte", async (importOriginal) => ({
         };
         if ("email" in initial) {
             formHolder.profile = form;
+        } else if ("current_password" in initial) {
+            formHolder.password = form;
         }
         return form;
     },
@@ -121,6 +131,8 @@ interface DeleteVisitOptions {
 beforeEach(() => {
     setProps();
     formHolder.profile = null;
+    formHolder.password = null;
+    formSeed.passwordErrors = {};
 });
 
 afterEach(() => {
@@ -281,3 +293,103 @@ describe("Settings/Index プロフィール更新の recent-auth precheck", () =
         expect(screen.queryByTestId("recent-auth-modal")).toBeNull();
     });
 });
+
+describe("Settings/Index パスワード変更フォームの表示トグル (T042 S2)", () => {
+    /** ラベル文言からパスワード入力とその PasswordInput コンテナ (div.relative) を得る */
+    function passwordField(label: string): { input: HTMLInputElement; container: HTMLElement } {
+        const input = screen.getByLabelText(label) as HTMLInputElement;
+        const container = input.parentElement as HTMLElement;
+        expect(container).not.toBeNull();
+        return { input, container };
+    }
+
+    it("現在/新しい パスワード入力が表示トグル付き (PasswordInput) で描画される", () => {
+        render(Index, { props: {} });
+
+        const current = passwordField("現在のパスワード");
+        const next = passwordField("新しいパスワード");
+
+        // 初期状態は伏字。各コンテナ内に表示トグルボタンが 1 個ずつ存在する
+        expect(current.input.type).toBe("password");
+        expect(next.input.type).toBe("password");
+        expect(
+            within(current.container).getByRole("button", { name: "パスワードを表示" }),
+        ).toBeInTheDocument();
+        expect(
+            within(next.container).getByRole("button", { name: "パスワードを表示" }),
+        ).toBeInTheDocument();
+    });
+
+    it("トグルで type が password↔text に切り替わり、2 フィールドは独立している", async () => {
+        render(Index, { props: {} });
+
+        const current = passwordField("現在のパスワード");
+        const next = passwordField("新しいパスワード");
+
+        // 現在のパスワードだけトグル → current は text、next は password のまま (相互干渉なし)
+        await fireEvent.click(
+            within(current.container).getByRole("button", { name: "パスワードを表示" }),
+        );
+        expect(current.input.type).toBe("text");
+        expect(next.input.type).toBe("password");
+
+        // もう一度押すと password に戻る
+        await fireEvent.click(
+            within(current.container).getByRole("button", { name: "パスワードを非表示" }),
+        );
+        expect(current.input.type).toBe("password");
+
+        // 新しいパスワード側も独立して切り替わる
+        await fireEvent.click(
+            within(next.container).getByRole("button", { name: "パスワードを表示" }),
+        );
+        expect(next.input.type).toBe("text");
+        expect(current.input.type).toBe("password");
+    });
+
+    it("autocomplete / aria-describedby が PasswordInput を透過して保持される", () => {
+        // FormField は error があるときだけ aria-describedby を生成するため、
+        // 透過を検証するには両フィールドにエラーを載せた状態で描画する。
+        formSeed.passwordErrors = {
+            current_password: "現在のパスワードが違います",
+            password: "パスワードは8文字以上必要です",
+        };
+        render(Index, { props: {} });
+
+        const current = passwordField("現在のパスワード");
+        const next = passwordField("新しいパスワード");
+
+        expect(current.input).toHaveAttribute("autocomplete", "current-password");
+        expect(next.input).toHaveAttribute("autocomplete", "new-password");
+        // FormField 由来の aria-describedby (error id) が rest props として透過している
+        expect(current.input).toHaveAttribute("aria-describedby", "current-password-error");
+        expect(next.input).toHaveAttribute("aria-describedby", "new-password-error");
+    });
+
+    it("送信配線 (put ルート + errorBag) と bind:value が維持される", async () => {
+        render(Index, { props: {} });
+
+        const passwordForm = formHolder.password;
+        expect(passwordForm).not.toBeNull();
+        const putMock = passwordForm?.put as ReturnType<typeof vi.fn>;
+
+        const current = passwordField("現在のパスワード");
+        const next = passwordField("新しいパスワード");
+
+        await fireEvent.input(current.input, { target: { value: "old-secret" } });
+        await fireEvent.input(next.input, { target: { value: "new-secret" } });
+
+        // bind:value が PasswordInput 経由でも form に反映される
+        expect(passwordForm?.current_password).toBe("old-secret");
+        expect(passwordForm?.password).toBe("new-secret");
+
+        const saveButton = screen.getByRole("button", { name: "パスワードを変更" });
+        await fireEvent.submit(saveButton.closest("form") as HTMLFormElement);
+
+        await waitFor(() => expect(putMock).toHaveBeenCalledTimes(1));
+        const call = putMock.mock.calls.at(-1);
+        expect(call?.[0]).toBe("/user/password");
+        const options = call?.[1] as { errorBag?: string };
+        expect(options.errorBag).toBe("updatePassword");
+    });
+});

```
