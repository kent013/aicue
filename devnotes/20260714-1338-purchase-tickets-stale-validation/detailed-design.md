# 詳細設計: purchase-tickets-stale-validation

## 使命・制約(絶対遵守)

### アプリの使命(North Star)

**AI-CUE** は、現場に既にある**作業手順書(SOP)を起点に**、AI が撮るべきカットを設計した
**動画シナリオ**を生成し、そのシナリオを**スマホ(PWA)でナビゲーション撮影**することで、
専門知識ゼロの現場作業者でも**標準化されたマニュアル動画**を作れるようにする。

- 「思考ゼロ・編集ゼロ」— 台本作成・撮影判断・編集の 3 ハードルを AI とナビ撮影で肩代わりする。
- 競合(tebiki)と異なり、標準作業を起点に AI が教材設計し撮影を指示する。
- 熟練者の暗黙知を動画マニュアルという形式知へ変換する装置(SECI)。

### 禁止事項

1. テストなしの実装完了報告(不変条件は対応する Architecture/Feature テストへの登録まで含めて「実装済み」)
2. PHPStan エラーの widen・baseline 化
3. dev DB への破壊操作をエージェント判断で実行すること
4. `response()->json()` の直書き(DTO / JsonResource / Inertia を使う)
5. LLM 呼び出しの Prism 直呼び(`app/Prompts/` の factory 経由のみ)
6. prompt 文字列のコード直書き(`resources/prompts/*.yaml` に置く)
7. 操作系 POST の応答での `redirect()->intended()`
8. 必須条件未充足を理由にボタンを disabled にする UI(押下時にエラー表示する。DESIGN.md)

### コーディングルール

- PHPStan level 10 必須(本件はフロントのみ、PHP 変更なし)
- Pest / vitest テストフレームワーク
- フロントは Svelte 5 runes + DS token/ramp のみ(DESIGN.md canonical、ds-purity テスト)
- component 階層は単方向 import(atoms → ... → pages)。本件は pages 層のロジック追加のみ
- コードフォーマット: `pnpm lint:fix` / `pnpm lint` / `pnpm typecheck` / `pnpm test` / `pnpm build`
- PHP 8.4 + Laravel 12 + Svelte 5 + Inertia.js + TypeScript

## 概念設計リファレンス

[devnotes/20260714-1338-purchase-tickets-stale-validation/conceptual-design.md](./conceptual-design.md)(APPROVED, conceptual-review Round 1)

## 施策一覧

| # | 施策名 | 変更ファイル | 優先度 |
|---|--------|------------|--------|
| 1 | 有効値復帰時の client-side error 自動 dismissal | `resources/js/pages/Billing/PurchaseTickets.svelte` | Medium |
| 2 | 再現テスト追加(stale error 解消の固定) | `tests/js/pages/PurchaseTickets.test.ts` | Medium |

---

## 施策 1: 有効値復帰時の client-side error 自動 dismissal

### 変更箇所

- ファイル: `resources/js/pages/Billing/PurchaseTickets.svelte`(L47-49 の `isValidCount` 定義直後に `$effect` を追加)

### 波及変更

- TypeScript型定義: なし(`clientError: string | null` / `isValidCount: boolean` の既存型のまま)
- API Resource/DTO: なし(サーバ側・Inertia Props 変更なし)
- Inertia Props: なし(`PurchaseTicketsPageProps` 不変更)
- テストファイル: `tests/js/pages/PurchaseTickets.test.ts`(施策2で追加)
- DS token / atomic import: なし(UI 構造・token・import グラフ不変更)

### 現行コード

```ts
// L36
let clientError = $state<string | null>(null);

// L47-49
const isValidCount = $derived(
    parsedCount !== null && parsedCount >= page.minCount && parsedCount <= page.maxCount,
);

// L81-100
function submit(): void {
    if (submitting) return; // 多重送信ガード (disabled にはしない)
    clientError = null;
    if (!isValidCount || parsedCount === null) {
        clientError = `購入枚数は ${page.minCount}〜${page.maxCount} の整数で入力してください`;
        return;
    }
    router.post(/* ... */);
}
```

`clientError` は独立 state で `submit()` 内でのみ設定/リセットされ、`isValidCount` の
reactive な変化に追従しない。範囲外入力で押下しエラーを出した後、有効値へ修正しても残留する。

### 変更後コード

`isValidCount`(L47-49)の定義直後に、有効値復帰時の dismissal effect を追加する:

```ts
const isValidCount = $derived(
    parsedCount !== null && parsedCount >= page.minCount && parsedCount <= page.maxCount,
);

// clientError は「購入枚数の範囲バリデーション」専用の transient state。押下時にのみ設定され、
// 値が有効へ復帰した時点で自動解消する(「押下時にエラー表示」契約は維持: 無効のままなら残す)。
// serverErrors(full POST 往復由来)は本 effect の対象外で別経路。
// ※不変条件: 将来 clientError に別種のメッセージを載せる場合はこのクリア条件の再検討が必要。
// clientError の有無も条件に含めることで不要な代入を避け、意図を明確化する。
$effect(() => {
    if (clientError !== null && isValidCount) {
        clientError = null;
    }
});
```

Codex design-review Round1 [Warning](種別化提案)への対応: 現行 `clientError` は count 範囲
バリデーション専用の単一用途 state であり、discriminated union の導入は「今必要のない抽象化」
(AGENTS.md 思考原則: オーバーエンジニアリング禁止)。上記コメントで不変条件を明記し、種別化は行わない。

`submit()` の既存ロジックは一切変更しない(押下時の設定は維持)。

### 設計上の根拠 / 正当性

- **反応的副作用としての妥当性**: `clientError` は「押下」という imperative イベントで設定される
  transient な UI state。その dismissal(有効化時の解除)は純粋な派生ではなく反応的副作用であり、
  `$effect` の正当な用途(state の単純ミラーリングではない)。Svelte の「$derived で表せる純粋派生に
  $effect を使うな」の反パターンには該当しない。
- **無限ループ不発の保証**: effect は `clientError`(読み) と `isValidCount`(読み)に依存するが、
  書き込みは `clientError = null`。書き込み後 `clientError !== null` が false になり、次回 effect 実行では
  代入がスキップされるため収束する。`isValidCount` は `countText` 変更時のみ変化。ループしない。
- **既存契約の維持**: 無効値のまま別の無効値へ変えても(`1001`→`2002`)`isValidCount` が false のため
  クリアされず、エラーは残る。「押下時にエラー表示」(禁止事項#8 / DESIGN.md)を壊さない。
- **serverErrors 非対象**: FormField の `error={clientError ?? serverErrors.count ?? ...}` のうち
  本 effect は `clientError` のみ扱う。サーバ由来エラーは full POST 往復で戻るもので、そのクリア戦略は別件。

### 型安全性チェック(TypeScript / svelte-check)

- [x] `clientError` は `string | null`、`isValidCount` は `boolean` の既存型で型変更なし
- [x] `$effect` のコールバックは戻り値 void、追加 import 不要($effect はランタイムグローバル rune)
- [x] `pnpm typecheck`(svelte-check)green を維持(新規型・any 導入なし)
- [x] PHPStan は PHP 変更がないため無関係(本施策で PHP ファイル不変更)

### テスト計画

- [x] バグ修正のため再現テストを先に書く(施策2、vitest)
- [x] 既存テスト `tests/js/pages/PurchaseTickets.test.ts` は全ケース不変で pass すること(回帰防止)
- [x] 個別の `DatabaseTransactions` は無関係(フロントのみ)

### リスク

- **極小**。追加は 1 つの `$effect` のみ。既存の派生・送信・表示ロジックは不変更。
- 万一 `$effect` が過剰発火しても、`clientError !== null && isValidCount` のガードで no-op に収束。
- a11y: `error=null` で FormField が `aria-invalid` / `aria-describedby` を解除する(施策2で検証)。

---

## 施策 2: 再現テスト追加(stale error 解消の固定)

### 変更箇所

- ファイル: `tests/js/pages/PurchaseTickets.test.ts`(`describe("Billing/PurchaseTickets", ...)` 内に it を追加)

### 波及変更

- TypeScript型定義: なし(既存の `basePage: PurchaseTicketsPageProps` / `setCount` ヘルパを再利用)
- API Resource/DTO: なし
- テストファイル: 本ファイル自体が対象
- **テストの vi.mock 拡張**: 現行テストは `router` のみモックし `page`(Inertia store)は実物(空)を
  使っている。施策2の3本目(serverErrors 非退行)で `errors.count` を注入するため、既存の
  `AdminUsers.test.ts` と同じ**hoisted `pageState` パターン**で `page` もモックする。
  既存6テストは `serverErrors={}` 相当のまま挙動不変(回帰なし)。

### テストセットアップの変更(vi.mock / afterEach)

```ts
const { routerPostMock, pageState } = vi.hoisted(() => ({
    routerPostMock: vi.fn(),
    // Inertia page store の最小 fake。props.errors を注入して serverErrors 経路を検証する
    pageState: { props: {} as Record<string, unknown> },
}));

vi.mock("@inertiajs/svelte", async (importOriginal) => ({
    ...(await importOriginal<typeof import("@inertiajs/svelte")>()),
    router: { post: routerPostMock },
    page: pageState,
}));

afterEach(() => {
    cleanup();
    routerPostMock.mockReset();
    pageState.props = {}; // errors 注入をリセット(テスト間の汚染防止)
});
```

補足: `PurchaseTickets.svelte` は `inertiaPage.props` を直接読み `(... ?? {})` でフォールバック
するため、`pageState.props = {}` の空オブジェクトで既存テストは従来どおり動作する
(`serverErrors = {}`、`appName = ""`)。

### 追加テスト

既存の `setCount` ヘルパ・`basePage` フィクスチャを再利用し、以下の it を追加する。
主判定は「エラー文言が消えること」と「入力の `aria-invalid` が外れること」の 2 点。

```ts
it("範囲外送信でエラー表示後、有効値に修正するとエラーが消え invalid が外れる", async () => {
    render(PurchaseTickets, { props: { page: basePage } });

    // 範囲外 (1001) を入力して押下 → client-side エラー表示
    await setCount("1001");
    await fireEvent.click(screen.getByTestId("purchase-submit"));
    expect(routerPostMock).not.toHaveBeenCalled();
    expect(
        screen.getByText("購入枚数は 1〜1000 の整数で入力してください"),
    ).toBeInTheDocument();
    // invalid が立っている (FormField 経由で aria-invalid)
    expect(screen.getByTestId("ticket-count-input")).toHaveAttribute("aria-invalid", "true");

    // 送信し直さずに有効値 (20) へ修正 → エラー消失 + invalid 解除 + 合計再計算
    await setCount("20");
    expect(
        screen.queryByText("購入枚数は 1〜1000 の整数で入力してください"),
    ).toBeNull();
    expect(screen.getByTestId("ticket-count-input")).not.toHaveAttribute("aria-invalid");
    expect(screen.getByTestId("purchase-total")).toHaveTextContent(
        "単価 ¥80 × 20 枚 = 合計 ¥1,600",
    );
});

it("無効値のまま別の無効値へ変えてもエラーは残る (過剰クリアしない)", async () => {
    render(PurchaseTickets, { props: { page: basePage } });

    await setCount("1001");
    await fireEvent.click(screen.getByTestId("purchase-submit"));
    expect(
        screen.getByText("購入枚数は 1〜1000 の整数で入力してください"),
    ).toBeInTheDocument();

    // 別の無効値 (2002) へ変えてもエラーは残る (押下時にエラー表示の契約維持)
    await setCount("2002");
    expect(
        screen.getByText("購入枚数は 1〜1000 の整数で入力してください"),
    ).toBeInTheDocument();
    expect(screen.getByTestId("purchase-total")).toHaveTextContent("合計 —");
});
```

3本目(Codex design-review Round1 [Warning] 対応 — serverErrors 非退行):

```ts
it("serverErrors.count がある場合は有効値に修正してもエラー表示が残る", async () => {
    pageState.props = { errors: { count: "サーバ側で拒否されました" } };
    render(PurchaseTickets, { props: { page: basePage } });

    // 有効値でも server 由来エラーは残る (本 effect の対象は clientError のみ)
    await setCount("20");
    expect(screen.getByText("サーバ側で拒否されました")).toBeInTheDocument();
    expect(screen.getByTestId("ticket-count-input")).toHaveAttribute("aria-invalid", "true");
});
```

補足(a11y 検証の根拠):
- `Input.svelte` は `aria-invalid={error || undefined}` を出力するため、`error=false` 時は
  属性自体が消える。`not.toHaveAttribute("aria-invalid")` で解除を検証できる。
- FormField は `invalid: Boolean(error)` を snippet に渡し、`error` は
  `clientError ?? serverErrors.count ?? serverErrors.attempt_token ?? null`。テストは serverErrors
  未設定(空 `errors`)なので `clientError` の消長がそのまま `aria-invalid` に反映される。

### 型安全性チェック

- [x] 既存の import・`basePage`・`setCount` を再利用(新規型なし)
- [x] `toHaveAttribute` / `queryByText` は既存テストで使用済みの jest-dom マッチャ

### テスト計画

- [x] 新規テスト1: 範囲外送信→有効値修正でエラー消失 + `aria-invalid` 解除 + 合計再計算
- [x] 新規テスト2: 無効→無効ではエラーが残る(過剰クリアの回帰防止)
- [x] 新規テスト3: serverErrors.count 有り時は有効値でもエラー/invalid が残る(serverErrors 非退行)
- [x] 既存 6 テストは `pageState` モック化後も挙動不変で pass(回帰確認)
- [x] `pnpm test`(vitest)green

### リスク

- 極小。テスト追加のみ。既存テストのフィクスチャ・ヘルパに変更を加えない。

---

## 検証コマンド(実装時、全 green でコミット)

- `pnpm lint`(eslint / ds-purity 等)
- `pnpm typecheck`(svelte-check)
- `pnpm test`(vitest)
- `pnpm build`(本番ビルド)
- PHP 変更がないため `composer test` / `composer phpstan` は非該当(実装時に PHP 差分ゼロを確認)

## 実装モード

| 項目 | 内容 |
|------|------|
| 推奨モード | incremental |
| 判断根拠 | 単一ページ + そのテストのみへの局所的追加変更。新規ファイル・スキーマ・ルート追加なし。既存構造の中に `$effect` 1つとテスト2本を足す小差分で、main への incremental 反映が自然。 |
| 競合リスク | 低。`PurchaseTickets.svelte` / `PurchaseTickets.test.ts` の 2 ファイルのみ。他施策・他ページと非干渉。 |
