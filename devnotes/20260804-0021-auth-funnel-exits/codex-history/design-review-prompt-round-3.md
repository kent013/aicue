# Round 3: Round 2 指摘への対応と再レビュー依頼

Round 2 の指摘 (施策 B: Warning 2 + Suggestion 1、施策 A: 表現の精度 1) に対応しました。
施策 A は Round 2 で APPROVE 済みのため、変更したのは表現 1 箇所のみです
(「ラベル非依存」→「禁止したい CTA 側の testId・ラベルには依存しない (依存するのは許可された 2 ボタンのラベルのみ)」)。

## 対応マトリクス

# 対応マトリクス: design-review Round 2

Codex 判定: 施策 A **APPROVE** / 施策 B **REQUEST_CHANGES** / 全体 **CHANGES_REQUESTED**。
反論した 2 点（A の architecture テスト不採用 / B の AST 不採用）は**いずれも妥当と判定**された。

## [Warning] B-2 は「ログアウト後に本当にパスワードを設定できる」ことを固定できていない
- 判断: **対応する**
- 根拠: 指摘のとおり。追加していた Feature テストは「認証中に `/forgot-password` へ到達できない」
  ことしか示しておらず、**案内した回復手順の終端**（実際にパスワードを得て再認証可能になる）は
  未固定だった。案内だけあって実行できない導線は本 TODO が排除しようとしている species そのもの。
- 対応内容: `tests/Feature/Auth/RecentAuthPasswordRecoveryTest.php`（新規）を追加し、
  SSO 専用ユーザー（`User::factory()->ssoOnly()`、social account なし = `canSatisfy=false`）が
  ログアウト → `POST /forgot-password` → 通知の token で `POST /reset-password` →
  `/recent-auth/confirm` の props が `passwordSet=true` / `canSatisfy=true` になるところまでを固定する。
  email は CipherSweet 暗号化だが `App\Auth\EncryptedUserProvider` が `whereBlind` 経由で
  解決する（平文 where に依存しない）ことを裏取り済み。HIBP 照会は `Http::fake` で止める。
  併せて `RecentAuthTest.php` に「SSO 専用ユーザーは `canSatisfy=false`」の 1 本を追記した。

## [Warning] CTA の文言と実際の着地が一致していない（押しても `/` に着地するだけ）
- 判断: **対応する（文言側を実挙動に合わせる）**
- 根拠: 「ログアウトしてパスワードを設定する」は 1 アクションで完了する印象を与えるが、
  実際に起きるのはログアウトのみ。**ラベルと着地の不一致は F-2-01 と同じ不誠実さ**である。
  一方で `router.post('/logout')` の成功後に `/forgot-password` へ二段遷移させる案は、
  Fortify の logout 応答契約の外側にクライアント固有の遷移規則を発明することになるため採らない。
- 対応内容: CTA を「**ログアウトする**」に変更し、手順（ログアウト → ログイン画面の
  「パスワードをお忘れの方」）は本文の説明が担う形にした。
  Codex が条件として挙げた「`/` にログイン導線が常時存在すること」は、
  既存テスト `tests/js/pages/Welcome.test.ts:120`（guest nav の `ログイン` role=link）が
  既に固定しているため、**その契約に依存する旨を設計へ明記**した（テストの二重化はしない）。

## [Suggestion] `AUTH_EXIT_ALLOWLIST` の path 重複も検出する
- 判断: **対応する**
- 対応内容: reason 必須の it に `AUTH_EXIT_ALLOWLIST_PATHS.size === AUTH_EXIT_ALLOWLIST.length` を追加。

## 施策 A への指摘（表現の精度）
- 「ラベル非依存」は厳密には不正確、という指摘を反映し、
  「**禁止したい CTA 側の testId・ラベルには依存しない**（依存するのは許可された 2 ボタンのラベルのみ）」
  と記述を修正した。


---

## 修正後の施策 B (全文)

## 施策 B: AuthLayout ページの離脱導線を規約化する

### 規約（本設計で確定する契約）

> `AuthLayout` を使うページは、**手順を完了できないユーザーが別の入口へ抜けられる導線**を
> `{#snippet footer()}` に 1 つ以上持ち、`TextLink` atom で表現する。
> 例外は architecture テストの allowlist に**理由付き**で登録する。

行き先は「その画面のユーザーの認証状態で**実際に踏破できる先**」に限る（新しい行き止まりを作らない）。

### 変更箇所

| ファイル | 変更 |
|---|---|
| `resources/js/pages/Auth/ResetPassword.svelte` (L58 の後) | footer snippet 追加: 「新しいリセットリンクをリクエスト」(`/forgot-password`) / 「ログインに戻る」(`/login`) |
| `resources/js/pages/Auth/TwoFactorChallenge.svelte` (L107 の後) | footer snippet 追加: 「ログインをやり直す」(`/login`) |
| `resources/js/pages/Auth/ConfirmRecentAuth.svelte` (L88-92 の置換 + 末尾) | `canSatisfy=false` 分岐の**踏破不能 CTA を差し替え** (下記 B-2) + footer snippet 追加: 「この操作を中止してダッシュボードへ戻る」(`/dashboard`) |
| `tests/js/architecture/page-shell-structure.test.ts` | AuthLayout ページの footer 契約を追加 (+ 理由必須 allowlist) |
| `DESIGN.md` (Do's and Don'ts) | Do / Don't を 1 行ずつ追加 |

各ページに `import TextLink from "@/components/atoms/TextLink.svelte";` を追加する
（`ResetPassword` / `TwoFactorChallenge` / `ConfirmRecentAuth` の 3 ファイル）。

### 波及変更

- **TypeScript 型定義**: なし（props 変更なし。`ConfirmRecentAuth` の `canSatisfy` / `passwordSet` /
  `availableProviders` は既存のまま = `RecentAuthStatusResource` / `RecentAuthStatusDto` に影響なし）
- **API Resource / DTO / route / DB**: なし（**フロントのみ**の変更）
- **テストファイル**: 新規 3 本（各ページの footer 描画）+ architecture テスト 3 本の追加
  + `tests/Feature/Auth/RecentAuthTest.php` への 2 本追記（B-2 の根拠）
  + `tests/Feature/Auth/RecentAuthPasswordRecoveryTest.php`（新規。回復手順の端まで）

### 変更後コード

```svelte
<!-- resources/js/pages/Auth/ResetPassword.svelte — </form> の後 -->
    {#snippet footer()}
        <p>
            リンクの有効期限が切れている場合は
            <TextLink href="/forgot-password">新しいリセットリンクをリクエスト</TextLink>
            できます。
        </p>
        <p class="mt-1">
            <TextLink href="/login">ログインに戻る</TextLink>
        </p>
    {/snippet}
```

```svelte
<!-- resources/js/pages/Auth/TwoFactorChallenge.svelte — </form> の後 -->
    {#snippet footer()}
        <p>
            認証コードもリカバリコードも使えない場合は
            <TextLink href="/login">ログインをやり直す</TextLink>
            か、組織の管理者に 2 要素認証のリセットを依頼してください。
        </p>
    {/snippet}
```

- 2FA チャレンジ中のユーザーはまだ未ログイン（Fortify の `login.id` セッション状態）のため
  `guest` middleware 配下の `/login` に到達できる。
- 「管理者に依頼」は既存機能（`organizations.members.two-factor.reset` = Owner/Admin が実行可能）の
  事実に基づく案内であり、新規機能ではない。

```svelte
<!-- resources/js/pages/Auth/ConfirmRecentAuth.svelte — 末尾ブロックの後 -->
    {#snippet footer()}
        <p>
            <TextLink href="/dashboard">この操作を中止してダッシュボードへ戻る</TextLink>
        </p>
    {/snippet}
```

- 本画面のユーザーは `auth` + `verified` 済みのため `/dashboard` に到達できる。
- **intended URL へは戻さない**: step-up 未充足のまま元操作へ戻しても `recent-auth` middleware が
  再び本画面へ送り返すだけで「中止」の意味と食い違う。session の intended URL を UI へ露出させると
  open-redirect の検査面も増える（概念設計 Codex R1 Warning 5 への回答）。

#### B-2: `ConfirmRecentAuth` の `canSatisfy=false` 分岐にある踏破不能 CTA の差し替え

**（Codex 詳細レビュー R1 施策 B Warning。F-2-01 と完全に同 species のため本 TODO で直す）**

現行 `ConfirmRecentAuth.svelte:88-90` は
`<Button href="/forgot-password" variant="ghost" fullWidth>パスワードを設定して再認証する</Button>`
を出すが、`/forgot-password` は Fortify が **`guest` middleware 付き**で登録している
(`vendor/laravel/fortify/routes/routes.php:55-57`)。本画面のユーザーは**ログイン済み**なので
`RedirectIfAuthenticated` により**フォームに到達せず**そのまま別画面へ飛ばされる
（リセットメールは 1 通も送られない）= 表示条件と踏破条件の不一致。

さらに、アプリ内でパスワードを設定する経路も存在しない:
`UpdateUserPassword`（`PUT /user/password`）は `current_password` 必須のため、
パスワード未設定の SSO 専用ユーザーは使えない（`app/Actions/Fortify/UpdateUserPassword.php:33`）。

→ **実際に踏破できる唯一の回復手順は「ログアウトしてから（guest として）パスワード再設定を行う」**。
CTA をその事実に合わせる:

```svelte
<!-- resources/js/pages/Auth/ConfirmRecentAuth.svelte — canSatisfy=false 分岐 -->
{#if !canSatisfy}
    <div class="mt-6 flex flex-col gap-3 text-caption text-text-secondary">
        <p>
            この操作を続けるための再認証手段が設定されていません。
            いったんログアウトし、ログイン画面の「パスワードをお忘れの方」から
            パスワードを設定すると再認証できるようになります。
        </p>
        <Button variant="ghost" onclick={logout} loading={loggingOut} fullWidth>
            ログアウトする
        </Button>
    </div>
{/if}
```

- `logout()` は `VerifyEmail.svelte:26-39` と同じ `router.post("/logout")` パターンを流用する
  （`loggingOut` の `$state` 込み。新しい仕組みを作らない）。
- **CTA のラベルは実際の着地と一致させる**（Codex R2 施策 B Warning）。押下で起きることは
  「ログアウト」だけなので `ログアウトする` とし、その後の手順は本文の説明が担う。
  **ログアウト直後に `/forgot-password` へ強制遷移させる特別扱いは作らない**（Fortify の
  logout 応答契約に手を入れない / クライアント側の二段遷移を発明しない = オーバーエンジニアリング回避）。
- ログアウト後の着地は Fortify 既定（`/` = `Welcome`）で、そこには **guest 向け nav の
  「ログイン」リンクが常時ある**（`resources/js/pages/Welcome.svelte:136`）。
  この契約は既存テスト `tests/js/pages/Welcome.test.ts:120` が
  「`ログイン` の role=link が存在する」で固定済みであり、**本設計はその契約に依存する**
  （新規テストは追加せず、依存関係を設計に明記して壊れたら気づけるようにする）。

```ts
// tests/js/architecture/page-shell-structure.test.ts — 追加分
/**
 * AuthLayout ページの離脱導線契約の除外 allowlist。追加は理由必須(reason 非空)。
 */
const AUTH_EXIT_ALLOWLIST: ReadonlyArray<{ path: string; reason: string }> = [
    {
        path: "Auth/VerifyEmail.svelte",
        reason:
            "離脱導線は本文の『ログアウト』(POST 遷移) が担う。footer の TextLink では表現できない。" +
            "認証前に到達できる別入口が無いため、代替リンクを置くと新たな行き止まりを作る。",
    },
];
const AUTH_EXIT_ALLOWLIST_PATHS = new Set(AUTH_EXIT_ALLOWLIST.map((e) => e.path));

/**
 * footer snippet 本体を取り出す (先頭の {/snippet} まで)。
 * - 定義が 0 個 → null (= 契約違反として報告)
 * - 定義が 2 個以上 / 本体に snippet が入れ子 → "抽出器が現実に追いつけていない" 印として
 *   例外を投げる (fail-closed。黙って見逃さない)
 */
function footerSnippetBody(src: string): string | null {
    const matches = [...src.matchAll(/\{#snippet\s+footer\s*\(\s*\)\s*\}([\s\S]*?)\{\/snippet\}/g)];
    if (matches.length === 0) return null;
    if (matches.length > 1) {
        throw new Error("footer snippet の定義が複数あります。抽出器の前提が崩れています。");
    }
    const body = matches[0][1];
    if (/\{#snippet\b/.test(body)) {
        throw new Error("footer snippet に snippet が入れ子です。抽出器を AST 方式へ更新してください。");
    }
    return body;
}

it("AUTH_EXIT_ALLOWLIST の各エントリは理由(reason)必須 / path 重複なし", () => {
    for (const e of AUTH_EXIT_ALLOWLIST) {
        expect(e.reason.trim(), `allowlist "${e.path}" は理由必須`).not.toBe("");
    }
    // path 重複は編集ミスの兆候 (Codex R2 Suggestion)
    expect(AUTH_EXIT_ALLOWLIST_PATHS.size).toBe(AUTH_EXIT_ALLOWLIST.length);
});

it("AUTH_EXIT_ALLOWLIST の各エントリは実在し AuthLayout を使うページである (死蔵 entry 検出)", async () => {
    for (const e of AUTH_EXIT_ALLOWLIST) {
        const abs = path.join(PAGES_DIR, e.path);
        const src = stripComments(await fs.readFile(abs, "utf8"));
        expect(
            importIdentifier(src, "@/components/templates/AuthLayout.svelte"),
            `allowlist "${e.path}" は AuthLayout ページではない (entry が死蔵または typo)`,
        ).not.toBeNull();
    }
});

it("AuthLayout ページは footer snippet に TextLink の離脱導線を持つ", async () => {
    const files = await sveltePages(PAGES_DIR);
    const missingFooter: string[] = [];
    const footerWithoutLink: string[] = [];

    for (const file of files) {
        const rel = path.relative(PAGES_DIR, file).replace(/\\/g, "/");
        const src = stripComments(await fs.readFile(file, "utf8"));
        if (!importIdentifier(src, "@/components/templates/AuthLayout.svelte")) continue;
        if (AUTH_EXIT_ALLOWLIST_PATHS.has(rel)) continue;

        const body = footerSnippetBody(src);
        if (body === null) {
            missingFooter.push(rel);
            continue;
        }
        const link = importIdentifier(src, "@/components/atoms/TextLink.svelte");
        if (!link || !usesTag(body, link)) footerWithoutLink.push(rel);
    }

    const msg = [
        missingFooter.length && `AuthLayout ページに footer snippet が無い:\n  - ${missingFooter.join("\n  - ")}`,
        footerWithoutLink.length && `footer に TextLink の離脱導線が無い:\n  - ${footerWithoutLink.join("\n  - ")}`,
    ].filter(Boolean).join("\n\n");
    expect({ missingFooter, footerWithoutLink }, msg).toEqual({ missingFooter: [], footerWithoutLink: [] });
});
```

- `sveltePages` / `stripComments` / `importIdentifier` / `usesTag` は**同ファイルの既存ヘルパを再利用**する
  （新規ユーティリティを作らない）。`usesTag` は第 1 引数に任意の文字列を取れるため footer 本体に適用できる。
- テストファイル冒頭の docblock に「AuthLayout ページの離脱導線契約」を追記する
  （このファイルは *ページ外枠テンプレートの構造契約* を集約する場所である、と定義を広げる）。
- **AST (`svelte/compiler`) 方式は採らない**（Codex R1 施策 B Warning への回答）:
  同ファイルの既存契約（`AppLayout` 側）が正規表現 + import 識別子解決で統一されており、
  1 ファイル内に 2 方式を並走させない方が保守的。代わりに上記の **fail-closed ガード**
  （footer 定義の重複・snippet 入れ子で例外）を置き、**抽出器の前提が崩れたときに黙って
  pass しない**ことを保証する。ガードが発火したら AST 方式への移行を検討する。

### DESIGN.md への追記（要否の判断: **要**）

`DESIGN.md` は UI 規約の canonical source であり、禁止事項 #8（disabled ボタン禁止）も
ここに書かれている。今回確定する 2 つの規約は同じ粒度の UI 不変条件なので Do's and Don'ts に追記する:

- **Do**: 認証フロー画面（`AuthLayout`）には、手順を完了できないユーザーが別の入口へ抜けられる導線を
  footer に必ず置く（`tests/js/architecture/page-shell-structure.test.ts` が強制）
- **Don't**: 表示条件と踏破条件が食い違う導線を出さない。押しても必ず失敗するボタン・リンクは、
  **出さずに理由を文章で説明する**（disabled 化でも代替しない = 上の Don't と同根）

`resources/css/tokens.css` との同期は不要（token 追加・変更なし。既存 `TextLink` / `text-caption` を使うのみ）。

### テスト計画（テストファースト）

1. **[新規] `tests/js/architecture/page-shell-structure.test.ts` の追加 it 2 本**
   — 実装前に走らせると `ResetPassword` / `TwoFactorChallenge` / `ConfirmRecentAuth` の 3 件で fail する
   （= 現状の欠落をテストが正しく検出することの確認）。
2. **[新規] `tests/js/pages/ResetPassword.test.ts`**
   - フォーム（メールアドレス / 新しいパスワード / 送信ボタン）を描画する
   - **`/forgot-password` と `/login` への離脱リンクを描画する**（`new URL(link.href).pathname` で比較。
     既存 `Login.test.ts` と同作法）
   - `errors.email`（トークン無効）が渡ってもリンクが消えない
     ＝ *bug-hunt F-2-02 の再現シナリオそのもの*
3. **[新規] `tests/js/pages/TwoFactorChallenge.test.ts`**
   - タブ（認証コード / リカバリコード）切替の既存挙動 + `/login` への離脱リンク
4. **[新規] `tests/js/pages/ConfirmRecentAuth.test.ts`**
   - `passwordSet=true` / `canSatisfy=false` の双方で `/dashboard` への離脱リンクが出る
   - **`canSatisfy=false` のとき `/forgot-password` へのリンクが存在しない**（B-2 の回帰。
     `screen.queryAllByRole("link")` の href に `/forgot-password` を含まないことを assert）
   - `canSatisfy=false` のとき「ログアウトしてパスワードを設定する」ボタンが出る
5. **[追記] `tests/Feature/Auth/RecentAuthTest.php`** — B-2 の根拠を仕様として固定する
   - `test('ログイン済みユーザーは GET /forgot-password のフォームに到達できない (guest ゲート)')`:
     `actingAs($user)->get('/forgot-password')` が **redirect であり 200 ではない**ことを assert
     （redirect 先は `RedirectIfAuthenticated::defaultRedirectUri()` 依存のため pin しない）。
     = 「認証済み画面から `/forgot-password` へリンクしてはならない」根拠。
   - `test('再認証手段の無いユーザー (SSO 専用・password 未設定) は canSatisfy=false')`:
     `User::factory()->ssoOnly()->create()`（social account を紐付けない）→
     `/recent-auth/confirm` の props が `passwordSet=false` / `availableProviders=[]` / `canSatisfy=false`。
6. **[新規] `tests/Feature/Auth/RecentAuthPasswordRecoveryTest.php`** — B-2 が案内する回復手順が
   **端まで成立する**ことを固定する（Codex R2 施策 B Warning。「案内はあるが実際にはできない」の再発防止）
   - `test('canSatisfy=false のユーザーはログアウト後にパスワードを設定でき、再認証可能になる')`:
     1. `Http::fake(['https://api.pwnedpasswords.com/range/*' => Http::response('', 200)])`
        （`PasswordPolicy` の HIBP 照会を止める。既存 `RegisterVerifyFlowTest:20` と同作法）
        + `Notification::fake()`
     2. `$user = User::factory()->ssoOnly()->create();`（password null / social account なし）
     3. `actingAs($user)->get('/recent-auth/confirm')` → `canSatisfy=false` を確認
     4. `post('/logout')` → 以降は guest
     5. `post('/forgot-password', ['email' => $email])` →
        `Notification::assertSentTo($user, ResetPassword::class)` で token を取り出す
        （email は CipherSweet 暗号化だが `App\Auth\EncryptedUserProvider` が
        `whereBlind` 経由で解決する = 平文 where に依存しない）
     6. `post('/reset-password', [...token, email, password])` → 成功
     7. `expect($user->fresh()->hasPassword())->toBeTrue()` かつ
        `actingAs($user->fresh())->get('/recent-auth/confirm')` の props が
        **`passwordSet=true` / `canSatisfy=true`**
   - この 1 本が「SSO 専用ユーザーの回復手順の終端」を保証する。
7. 既存 `tests/js/pages/Login.test.ts` の「register / forgot-password への導線」は**変更しない**（回帰の基準。
   `/login` は guest 画面なので `/forgot-password` へのリンクは正しい）
8. 既存 `tests/js/pages/Welcome.test.ts:120`（guest nav の「ログイン」リンク）を**依存契約として維持**
   （B-2 のログアウト後着地から `/login` へ辿れることの担保。変更しない）

### 受け入れ条件 (DoD)

- `AuthLayout` を import する全ページ（allowlist の `Auth/VerifyEmail` を除く）が footer に
  `TextLink` の離脱導線を持ち、architecture テストが green。
- allowlist の健全性（reason 非空 / 実在 / AuthLayout ページであること）がテストで固定されている。
- `ResetPassword` は `errors.email` があるときも `/forgot-password` `/login` への導線を出す。
- `ConfirmRecentAuth` の `canSatisfy=false` 分岐に `/forgot-password` へのリンクが**無く**、
  代わりに実際に踏破できる回復手順（ログアウト）が提示されている。
- 案内した回復手順が**端まで成立する**ことが Feature テストで固定されている
  （SSO 専用ユーザーがログアウト → リセットリンク → パスワード設定 → `canSatisfy=true`）。
- CTA のラベルが実際の着地と一致している（「ログアウトする」= ログアウトのみを行う）。
- `DESIGN.md` に 2 規約が記載されている。
- 新しい行き止まり・新しい踏破不能リンクを増やしていない（各リンク先の到達可能性を上表の根拠で説明できる）。

### リスク

| リスク | 緩和 |
|---|---|
| footer リンク先が別の罠になる（例: 未検証ユーザーを `/dashboard` へ送る） | リンク先は「その画面のユーザーの認証状態で到達できる先」に限定し、根拠を設計に明記。`VerifyEmail`（未検証状態）には**リンクを足さない**（allowlist で本文のログアウトを離脱導線と認める） |
| architecture テストの正規表現が footer を誤検出する | 既存ヘルパ（コメント除去 + import 識別子解決）を再利用し、footer 本体を抽出してから `TextLink` を探す。alias import にも対応 |
| allowlist が将来の抜け道になる | `reason` 非空を別 it で強制（既存 `PAGECONTENT_ALLOWLIST` と同方式）。エントリは現時点で 1 件のみ |
| Debug/Login.svelte が対象に入る | 既に footer（`/login`）を持つため追加変更不要。local 専用画面だが規約対象のままにする（例外を作らない） |

---



---

## 再レビュー依頼

1. B-2 の回復手順の終端を固定する Feature テスト (`RecentAuthPasswordRecoveryTest`) の手順に
   技術的な穴が無いか (Fortify + CipherSweet + PasswordPolicy の組み合わせで成立するか)
2. CTA を「ログアウトする」に変更し、手順を説明文へ移した判断が妥当か
   (Welcome.test.ts の既存契約に依存し、新規テストを足さない判断も含む)
3. 施策 B の判定と全体判定 (APPROVED / CHANGES_REQUESTED)
