# 詳細設計: password-change-pending

## 使命・制約（絶対遵守）

### アプリの使命（North Star / AGENTS.md より転記）

**AI-CUE** は、現場に既にある**作業手順書(SOP)を起点に**、AI が撮るべきカットを設計した**動画シナリオ**を生成し、そのシナリオを**スマホ(PWA)でナビゲーション撮影**することで、専門知識ゼロの現場作業者でも**標準化されたマニュアル動画**を作れるようにする。

- 「思考ゼロ・編集ゼロ」— 台本作成・撮影判断・編集の 3 ハードルを AI とナビ撮影で肩代わりする。
- 競合(tebiki)と異なり、標準作業を起点に AI が教材設計し撮影を指示する。
- 熟練者の暗黙知を動画マニュアルという形式知へ変換する装置（SECI）。
- v1 スコープ: 字幕のみ / 撮影は PWA(同一オリジン・セッション認証) / 動画合成は自前 ffmpeg / 単一 Default Project。

### 禁止事項（AGENTS.md より転記）

1. テストなしの実装完了報告
2. PHPStan エラーの widen・baseline 化
3. dev DB への破壊操作をエージェント判断で実行
4. `response()->json()` の直書き(DTO / JsonResource / Inertia を使う)
5. LLM 呼び出しの Prism 直呼び
6. prompt 文字列のコード直書き
7. 操作系 POST の応答での `redirect()->intended()`
8. **必須条件未充足を理由にボタンを disabled にする UI(押下時にエラー表示する。DESIGN.md)**

### コーディングルール

- PHPStan level 10 / Pest / RefreshDatabase + `--parallel`（本 item はフロントのみで PHP 変更なし）
- フロントは Svelte 5 runes + DS token/ramp のみ（`DESIGN.md` canonical、ds-purity テスト）
- component 階層 `atoms → molecules → organisms → features → templates → pages` の単方向 import
- アイコンは `@lucide/svelte` のみ（本 item で新規 SVG は追加しない。スピナーは既存 Button 内 `LoaderCircle` を流用）
- 検証: `pnpm typecheck` / `pnpm lint` / `pnpm test` / `pnpm build` を全 green でコミット

## 概念設計リファレンス

- [conceptual-design.md](./conceptual-design.md)（Codex `gpt-5.4` レビュー APPROVED / Round 1）

## 背景要約（実装状況の確定事実）

| 症状 | 現状 | 本 item の対応 |
|------|------|----------------|
| (1) pending 表示が弱い | `Button loading` が既にスピナー + `disabled` + `aria-busy` を描画済。ボタン文言は「パスワードを変更」固定 | 文言を `processing` 中「変更中…」へ切替（10〜14 秒の長時間処理でも進行が明確に伝わる） |
| (2) 前回エラーが残留 | Inertia `useForm` は送信開始時に errors をクリアしない（`resetBeforeSubmit` は wasSuccessful/recentlySuccessful のみ。errors 更新は応答後の onError/onSuccess）| `submitPassword` 先頭で `passwordForm.clearErrors()` を明示呼び出し |
| 成功トースト | **既存**: `PasswordUpdatedResponse` が `success` flash → `AppLayout.consumeFlash` → `addToast`。`FortifyResponseTest`（server）+ `flash-to-toast.test.ts`（front）で担保済 | 変更なし（本 item では触らない） |

## 施策一覧

| # | 施策名 | 変更ファイル | 優先度 |
|---|--------|------------|--------|
| 1 | 送信開始時の前回エラークリア | `resources/js/pages/Settings/Index.svelte` | High |
| 2 | 送信中の明示的 pending 文言（「変更中…」） | `resources/js/pages/Settings/Index.svelte` | High |
| 3 | テスト double 拡張（`put`/`patch`/`reset` + 反応的 `processing`） | `tests/js/support/reactiveUseForm.svelte.ts` | High（施策 4 の前提） |
| 4 | vitest 回帰テスト追加（エラークリア DOM / pending 文言 / 配線） | `tests/js/pages/SettingsIndex.test.ts` | High |

---

## 施策 1 & 2: Settings/Index.svelte のパスワードフォーム修正

### 変更箇所
- ファイル: `resources/js/pages/Settings/Index.svelte`
  - `submitPassword`（L86-95）
  - パスワード送信ボタンの children（L226-229）

### 波及変更
- TypeScript 型定義: なし（`useForm` の既存メソッド `clearErrors` を使うのみ。新 prop なし）
- API Resource/DTO: なし（サーバ応答形式・ルート不変）
- テストファイル: `tests/js/pages/SettingsIndex.test.ts`（施策 4）/ `tests/js/support/reactiveUseForm.svelte.ts`（施策 3）

### 現行コード
```svelte
function submitPassword(event: SubmitEvent): void {
    event.preventDefault();
    passwordForm.put("/user/password", {
        errorBag: "updatePassword",
        preserveScroll: true,
        onSuccess: () => {
            passwordForm.reset();
        },
    });
}
```
```svelte
<Button type="submit" loading={passwordForm.processing}>
    パスワードを変更
</Button>
```

### 変更後コード
```svelte
function submitPassword(event: SubmitEvent): void {
    event.preventDefault();
    // 送信中の誤認防止のため、前回エラーを送信開始時に明示クリア
    // (Inertia useForm は送信ではクリアせず応答後にのみ errors を更新するため)。
    passwordForm.clearErrors();
    passwordForm.put("/user/password", {
        errorBag: "updatePassword",
        preserveScroll: true,
        onSuccess: () => {
            passwordForm.reset();
        },
    });
}
```
```svelte
<Button type="submit" loading={passwordForm.processing}>
    {passwordForm.processing ? "変更中…" : "パスワードを変更"}
</Button>
```

### 設計上の注記
- **スピナー / disabled / aria-busy は既存 `Button loading` が担保**（`Button.svelte` L91-93, L130, L134）。
  本 item は文言追加のみで、二重の pending 機構を作らない（DRY / オーバーエンジニアリング禁止）。
- `clearErrors()` は Inertia `useForm` の公開 API。引数なしで全 errorBag のフィールドを消す。
  `errorBag: "updatePassword"` を使っていても、`form.errors` はバッグ名でネストせずフィールド名
  （`current_password` / `password`）で保持されるため、引数なし `clearErrors()` で過不足なく消える。
- **プロフィールフォームには本修正を波及させない**（スコープを password に閉じる。conceptual スコープ外方針）。
  ただし将来 profile も同 UX を要するなら同型対応可能（横展開は別 item）。

### 禁止事項 8 との整合
- ここで button が `disabled` になるのは **送信処理中（`processing`）の二重送信防止**であり、
  「必須条件未充足を理由にした事前 disabled」ではない。押下は可能で、押下後にサーバが検証しエラー表示する。
  よって禁止事項 8 に抵触しない（既存挙動を踏襲）。

### リスク
- 低。フロント 1 ファイル、既存メソッドの追加呼び出しと文言の条件分岐のみ。サーバ・型・ルート不変。
- アクセシビリティのさらなる向上（フォーム領域の live region / 説明文）は将来検討。本 item はスコープ外
  （Codex 概念レビューも「今回スコープ外でよい」と同意）。

---

## 施策 3: reactiveUseForm テスト double の拡張

### 変更箇所
- ファイル: `tests/js/support/reactiveUseForm.svelte.ts`

### 目的
既存の `reactiveUseForm`（`$state` 反応的 errors + 実 `clearErrors`）は `post` のみ公開。パスワード
フォームは `put` を使い、成功時に `reset()` を呼ぶため、これらを **追加（additive）** する。さらに
「送信中は『変更中…』」を DOM で検証するため `processing` を反応的（getter/setter で `$state` 背後）にする。

### 波及変更
- 既存 consumer（`tests/js/pages/ManualsCreate.test.ts` / `tests/js/components/features/manual/DuplicateManualDialog.test.ts`）は
  `post` / `transform` / `errors` / `clearErrors` のみ参照。**追加フィールドは既存参照に影響しない**（後方互換）。

### 変更後コード（該当箇所）
```ts
export function reactiveUseForm<TData extends Record<string, unknown>>(
  initial: TData,
  initialErrors: Record<string, string> = {},
): TData & {
  errors: Record<string, string>;
  processing: boolean;
  clearErrors: (...keys: string[]) => void;
  reset: ReturnType<typeof vi.fn>;
  transform: (fn: (data: TData) => unknown) => {
    post: ReturnType<typeof vi.fn>;
    put: ReturnType<typeof vi.fn>;
    patch: ReturnType<typeof vi.fn>;
  };
  post: ReturnType<typeof vi.fn>;
  put: ReturnType<typeof vi.fn>;
  patch: ReturnType<typeof vi.fn>;
} {
  const errors = $state<Record<string, string>>({ ...initialErrors });
  let processing = $state(false); // 反応的: テストから true にすると pending 文言を再描画で観測できる
  const post = vi.fn();
  const put = vi.fn();
  const patch = vi.fn();

  const form = {
    ...initial,
    get errors() {
      return errors;
    },
    get processing() {
      return processing;
    },
    set processing(value: boolean) {
      processing = value;
    },
    clearErrors: vi.fn((...keys: string[]) => {
      if (keys.length === 0) {
        for (const key of Object.keys(errors)) delete errors[key];
        return;
      }
      for (const key of keys) delete errors[key];
    }),
    reset: vi.fn(),
    transform() {
      // 戻り値に put/patch も含め、将来 transform().put(...) 連鎖テストでも不整合を出さない
      // (Codex 詳細レビュー Warning 反映。既存 consumer は post のみ参照で後方互換)。
      return { post, put, patch };
    },
    post,
    put,
    patch,
  };

  return form;
}
```

### 注記
- `processing` を getter/setter 化しても `processing: boolean` という外形は不変（既存 consumer は読み取りのみ）。
- `reset` は `vi.fn()`（no-op）。本 item のテストは `reset` 呼び出しの有無だけを確認できれば十分。
- `transform()` は `{ post, put, patch }` を返す。既存 consumer は `.post` のみ参照するため後方互換。

### リスク
- 低。純粋な additive 拡張。既存 2 consumer のテストは変更なしで green を維持する。

---

## 施策 4: SettingsIndex.test.ts の回帰テスト追加

### 変更箇所
- ファイル: `tests/js/pages/SettingsIndex.test.ts`
  - `vi.mock("@inertiajs/svelte", ...)` の password 分岐を反応的 double へ差し替え
  - 新 `describe("パスワード変更の pending / エラークリア (F-4-01)")` を追加

### 設計方針（既存テストを壊さない）
- 既存の inline `useForm` fake は **password フォーム（`"current_password" in initial`）分岐のみ**
  `reactiveUseForm(initial, formSeed.passwordErrors)` に差し替える。profile フォーム分岐は現状維持。
- 差し替えは `vi.mock` の async factory 内で `await import("../support/reactiveUseForm.svelte")` を用いる
  （hoisting 制約を dynamic import で回避。既存 `ManualsCreate.test.ts` と同じ反応的 double を使う）。
- 反応的 double は既存 password 系 4 テストが参照する surface（`put` / `reset` / `errors` getter /
  `current_password`・`password` バインド / `clearErrors`）をすべて備えるため、**既存テストは無改変で green**。
  - 「autocomplete / aria-describedby 透過」: `formSeed.passwordErrors` を errors getter が返し初期描画で error id 生成 → 不変。
  - 「送信配線 (put ルート + errorBag)」: `put` は `vi.fn()` として存在 → `putMock` 検証が通る。
- **既存 4 ケースの `describe`/`it` 名は変更しない**（追加のみ。回帰の可視性を保つ。Codex 詳細レビュー Suggestion 反映）。

### 波及変更
- TypeScript 型定義: なし
- API Resource/DTO: なし
- テストファイル: 本ファイル（追加）+ 施策 3（helper 拡張）

### 新規テスト（追加する describe ブロック）

```ts
describe("Settings/Index パスワード変更の pending / エラークリア (F-4-01)", () => {
  /** submit ボタンを含む form 要素を null 安全に取得し、submit の完了を待つ */
  async function submitPasswordForm(): Promise<void> {
    const submit = screen.getByRole("button", { name: /パスワードを変更|変更中…/ });
    const formEl = submit.closest("form");
    expect(formEl).not.toBeNull();
    await fireEvent.submit(formEl as HTMLFormElement);
  }

  it("送信すると前回のエラー文言が pending 中に画面から消える (clearErrors)", async () => {
    formSeed.passwordErrors = { current_password: "現在のパスワードが違います" };
    render(Index, { props: {} });

    // 前回の失敗エラーが初期表示されている
    expect(screen.getByText("現在のパスワードが違います")).toBeInTheDocument();

    // 送信 → submitPassword が clearErrors() → 反応的 errors が空になり文言が DOM から消える
    await submitPasswordForm();

    await waitFor(() =>
      expect(screen.queryByText("現在のパスワードが違います")).toBeNull(),
    );
    // 送信自体は継続している (put が 1 回呼ばれる)
    const passwordForm = formHolder.password;
    expect(passwordForm?.clearErrors as ReturnType<typeof vi.fn>).toHaveBeenCalledTimes(1);
    expect(passwordForm?.put as ReturnType<typeof vi.fn>).toHaveBeenCalledTimes(1);
  });

  it("clearErrors は put より前に呼ばれる (pending 前にエラーを消す)", async () => {
    formSeed.passwordErrors = { current_password: "現在のパスワードが違います" };
    render(Index, { props: {} });

    await submitPasswordForm();

    const form = formHolder.password;
    const clearMock = form?.clearErrors as ReturnType<typeof vi.fn>;
    const putMock = form?.put as ReturnType<typeof vi.fn>;
    // まず両方が確実に 1 回ずつ呼ばれたことを確認 (invocationCallOrder が undefined になる偽陽性を防ぐ)
    await waitFor(() => expect(putMock).toHaveBeenCalledTimes(1));
    expect(clearMock).toHaveBeenCalledTimes(1);
    // その上で呼び出し順序を比較する
    expect(clearMock.mock.invocationCallOrder[0]).toBeLessThan(
      putMock.mock.invocationCallOrder[0],
    );
  });

  it("送信中は『変更中…』文言 + disabled + aria-busy を示す", async () => {
    render(Index, { props: {} });

    // 通常時は「パスワードを変更」
    expect(screen.getByRole("button", { name: "パスワードを変更" })).toBeInTheDocument();

    // processing=true に切替 (反応的 double)。tick 1 回依存でフレークしないよう waitFor で待つ
    const form = formHolder.password as { processing: boolean };
    form.processing = true;

    await waitFor(() =>
      expect(screen.getByRole("button", { name: "変更中…" })).toBeInTheDocument(),
    );
    const busyButton = screen.getByRole("button", { name: "変更中…" });
    expect(busyButton).toBeDisabled();
    expect(busyButton).toHaveAttribute("aria-busy", "true");
  });

  it("成功時はフォームを reset する (成功トーストはサーバ flash 経由 = 別テストで担保)", async () => {
    render(Index, { props: {} });
    const form = formHolder.password;
    const putMock = form?.put as ReturnType<typeof vi.fn>;

    await submitPasswordForm();
    await waitFor(() => expect(putMock).toHaveBeenCalledTimes(1));

    // put のオプションの onSuccess が reset を呼ぶ配線を検証
    const options = putMock.mock.calls.at(-1)?.[1] as { onSuccess?: () => void };
    options.onSuccess?.();
    expect(form?.reset as ReturnType<typeof vi.fn>).toHaveBeenCalledTimes(1);
  });
});
```

- `waitFor` は `@testing-library/svelte` から import 済（既存 import に含まれる）。`tick` への 1 回依存は避けた。
- **成功トースト**は `PasswordUpdatedResponse`（`success` flash）→ `flash-to-toast` の経路で、
  `FortifyResponseTest`（server / Pest）と `tests/js/lib/flash-to-toast.test.ts`（front）が既に担保。
  コンポーネント単体テストではサーバ flash を再現しないため、本ファイルでは **`onSuccess` が `reset()` を呼ぶ配線**
  の維持のみ検証する（トーストの二重テストはしない = 責務分離）。

### PHPStan 適合チェック
- 対象外（PHP 変更なし）。フロントは `pnpm typecheck`（tsc/svelte-check）で型検証。

### テスト計画
- [x] バグ修正の再現観点: 「前回エラーが pending 中に残る」→ 修正後は DOM から消える（Test 1）
- [x] 既存テスト `tests/js/pages/SettingsIndex.test.ts` の password 系 4 ケースは無改変で green を維持
- [x] 新規: エラークリア DOM / clearErrors→put 順序 / 「変更中…」+disabled+aria-busy / onSuccess→reset 配線
- [x] 個別の `DatabaseTransactions` 不使用（フロントテストで DB 非関与）
- [x] `pnpm typecheck` / `pnpm lint` / `pnpm test` / `pnpm build` 全 green

### リスク
- 低〜中。既存 inline fake の password 分岐差し替えが唯一の非自明点。反応的 double が既存 surface を
  完全に備えるため回帰は起きない想定だが、実装時に既存 4 ケースの green を先に確認する（テストファースト）。

---

## 実装モード

| 項目 | 内容 |
|------|------|
| 推奨モード | incremental |
| 判断根拠 | フロント 1 ファイル + テスト 2 ファイルの小規模・低リスク改修。既存 UX 機構（Button loading / flash-to-toast）に乗る差分で、他施策との依存や大きな新規面がない |
| 競合リスク | 低。`Settings/Index.svelte` と `SettingsIndex.test.ts` / `reactiveUseForm.svelte.ts` に限局。他 worktree との衝突面は狭い |

## スコープ外（別 item として notes / scope_note に送る）

- **HIBP `uncompromised()` の bughunt 実 HTTP 呼び出し（禁止事項 4: fake-externals 不整合）**。
  `app/Support/PasswordPolicy::rule()` が `App::runningUnitTests()` 時のみ `uncompromised()` を外すため、
  bughunt（`APP_ENV=bughunt.local`）では実 HIBP を叩き、10〜14 秒の遅延と外部依存を生む。
  修正の型（別 item）: bughunt/fake_externals 系 capability flag（例: `config('testing.fake_externals')` や
  bughunt 専用フラグ）に応じて `uncompromised()` を外す or Http fake を bind する。
  本 item（フロント UX）とは独立。**優先度: 高め**（外部依存の遅延が UX 問題の根因でもあるため）。
- サーバ側の応答時間短縮（HIBP 非同期化・キャッシュ等）は本 item スコープ外。
- profile など他フォームへの errors クリア UX 横展開は本 item スコープ外。
