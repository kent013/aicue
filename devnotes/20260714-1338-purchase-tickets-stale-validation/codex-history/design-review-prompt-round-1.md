# アプリの使命 (North Star)

**AI-CUE** は、現場に既にある作業手順書(SOP)を起点に、AI が撮るべきカットを設計した動画シナリオを生成し、そのシナリオをスマホ(PWA)でナビゲーション撮影することで、専門知識ゼロの現場作業者でも標準化されたマニュアル動画を作れるようにする。「思考ゼロ・編集ゼロ」。

# 禁止事項

1. テストなしの実装完了報告
2. PHPStan エラーの widen・baseline 化
3. dev DB への破壊操作をエージェント判断で実行
4. `response()->json()` の直書き(DTO / JsonResource / Inertia)
5. LLM 呼び出しの Prism 直呼び
6. prompt 文字列のコード直書き
7. 操作系 POST の応答での `redirect()->intended()`
8. 必須条件未充足を理由にボタンを disabled にする UI(押下時にエラー表示する。DESIGN.md)

# 思考原則

まず仮説を立てろ。データに真摯に向き合え。先人の知恵を探せ。機能の名前に立ち返れ。仕組みが機能していない段階で値を弄るな。

# ツール使用制限

コマンド実行・ファイル書き込みは一切行わず、提供されたテキストの分析に集中すること。ファイル読み込みは許可。

---

# システム: レビュアー役割

あなたは経験豊富なWebアプリケーションアーキテクトです。Laravel + Svelte アプリケーション改善の詳細設計をレビューしてください。

【前提環境】
- PHP 8.4 + Laravel 12 + Svelte 5 + Inertia.js + TypeScript
- PHPStan level 10 / Pest / vitest
- DTO + JsonResource パターン
- フロントは Svelte 5 runes + DS token/ramp のみ(DESIGN.md canonical)、atomic import 階層

【レビュー観点】
1. コードの正確性(ロジックエラー、エッジケース、null安全性、$effect の収束性/無限ループ)
2. 既存コードとの整合性(命名規約、パターン、Svelte 5 runes の作法)
3. 型安全性(TypeScript / svelte-check)
4. テスト計画の網羅性(vitest、各施策にテスト)
5. Inertia Props vs API Response の使い分け(本件は Props のみ)
6. 副作用・後退リスク
7. 波及変更の網羅性(TypeScript型定義、テストが変更対象に含まれているか)
8. セキュリティ(本件はクライアント表示のみ。サーバ権威・attempt_token フロー不変更の妥当性)
9. DESIGN.md準拠(token 直書きを増やしていないか、UI 構造変更の有無)
10. Atomic Design準拠(pages 層のロジック追加のみで階層違反がないか)
11. Svelte 5 の $effect vs $derived の使い分けが idiomatic か(反パターンでないか)

【出力形式】
- 各施策ごとに判定: APPROVE / REQUEST_CHANGES
- 指摘は [Critical] [Warning] [Suggestion] で分類
- Critical/Warning には必ず修正案を添える
- 全体判定: APPROVED / CHANGES_REQUESTED
- 日本語で出力

---

## 詳細設計書

（devnotes/20260714-1338-purchase-tickets-stale-validation/detailed-design.md の内容）

（※ 概念設計 conceptual-review は gpt-5.4 により Round 1 で APPROVED 済み。症状: /purchase-tickets で範囲外(1001)入力→押下でエラー表示→送信し直さず有効値(20)へ修正すると合計は再計算されるが invalid とエラー文言が残留する。根本原因は clientError が独立 state で submit() 内でのみ更新され isValidCount の reactive 変化に追従しないこと。）

### 施策一覧
1. 有効値復帰時の client-side error 自動 dismissal — `resources/js/pages/Billing/PurchaseTickets.svelte`
2. 再現テスト追加 — `tests/js/pages/PurchaseTickets.test.ts`

### 施策1 変更後コード（isValidCount 定義直後に追加）

```ts
const isValidCount = $derived(
    parsedCount !== null && parsedCount >= page.minCount && parsedCount <= page.maxCount,
);

// 押下時に設定した client-side エラーは、値が有効へ復帰した時点で自動解消する。
// (「押下時にエラー表示」契約は維持: 無効のままなら残す。serverErrors は対象外)
$effect(() => {
    if (clientError !== null && isValidCount) {
        clientError = null;
    }
});
```

submit() の既存ロジックは不変更（押下時に clientError を設定/初期化）。

無限ループ不発の根拠: effect は clientError(読) と isValidCount(読) に依存し、書き込みは clientError = null のみ。書き込み後 clientError !== null が false になり次回は代入スキップ。isValidCount は countText 変更時のみ変化。

既存契約維持: 無効→無効(1001→2002)は isValidCount=false のためクリアされずエラーが残る。禁止事項#8(disabled にしない/押下時エラー表示)を壊さない。

### 施策2 追加テスト（既存 setCount / basePage を再利用）

```ts
it("範囲外送信でエラー表示後、有効値に修正するとエラーが消え invalid が外れる", async () => {
    render(PurchaseTickets, { props: { page: basePage } });
    await setCount("1001");
    await fireEvent.click(screen.getByTestId("purchase-submit"));
    expect(routerPostMock).not.toHaveBeenCalled();
    expect(screen.getByText("購入枚数は 1〜1000 の整数で入力してください")).toBeInTheDocument();
    expect(screen.getByTestId("ticket-count-input")).toHaveAttribute("aria-invalid", "true");
    await setCount("20");
    expect(screen.queryByText("購入枚数は 1〜1000 の整数で入力してください")).toBeNull();
    expect(screen.getByTestId("ticket-count-input")).not.toHaveAttribute("aria-invalid");
    expect(screen.getByTestId("purchase-total")).toHaveTextContent("単価 ¥80 × 20 枚 = 合計 ¥1,600");
});

it("無効値のまま別の無効値へ変えてもエラーは残る (過剰クリアしない)", async () => {
    render(PurchaseTickets, { props: { page: basePage } });
    await setCount("1001");
    await fireEvent.click(screen.getByTestId("purchase-submit"));
    expect(screen.getByText("購入枚数は 1〜1000 の整数で入力してください")).toBeInTheDocument();
    await setCount("2002");
    expect(screen.getByText("購入枚数は 1〜1000 の整数で入力してください")).toBeInTheDocument();
    expect(screen.getByTestId("purchase-total")).toHaveTextContent("合計 —");
});
```

### 実装モード
incremental（単一ページ + そのテストのみへの局所追加、2 ファイル、非干渉）。

---

## 関連する現行コード

### resources/js/pages/Billing/PurchaseTickets.svelte（抜粋 L30-100）

```svelte
const serverErrors = $derived((inertiaPage.props.errors ?? {}) as Record<string, string>);

// props から一度だけ seed する
// svelte-ignore state_referenced_locally
let countText = $state<string | number>(String(page.defaultCount));
let submitting = $state(false);
let clientError = $state<string | null>(null);

const parsedCount = $derived.by<number | null>(() => {
    const trimmed = String(countText).trim();
    if (!/^\d+$/.test(trimmed)) return null;
    const n = Number(trimmed);
    return Number.isSafeInteger(n) ? n : null;
});

const isValidCount = $derived(
    parsedCount !== null && parsedCount >= page.minCount && parsedCount <= page.maxCount,
);

const appliedUnit = $derived.by<number | null>(() => {
    if (parsedCount === null) return null;
    let unit: number | null = null;
    for (const tier of page.tiers) {
        if (parsedCount >= tier.minCount) unit = tier.unitAmount;
    }
    return unit;
});

const totalAmount = $derived(
    isValidCount && parsedCount !== null && appliedUnit !== null ? parsedCount * appliedUnit : null,
);

function submit(): void {
    if (submitting) return;
    clientError = null;
    if (!isValidCount || parsedCount === null) {
        clientError = `購入枚数は ${page.minCount}〜${page.maxCount} の整数で入力してください`;
        return;
    }
    router.post("/purchase-tickets/checkout", { count: parsedCount, attempt_token: page.attemptToken }, {
        onStart: () => { submitting = true; },
        onFinish: () => { submitting = false; },
    });
}
```

FormField 呼び出し部:
```svelte
<FormField
    label={`枚数 (${page.minCount}〜${page.maxCount})`}
    id="ticket-count"
    error={clientError ?? serverErrors.count ?? serverErrors.attempt_token ?? null}
>
    {#snippet children({ id, describedBy, invalid })}
        <Input {id} type="number" bind:value={countText} error={invalid}
            aria-describedby={describedBy} min={page.minCount} max={page.maxCount}
            testId="ticket-count-input" />
    {/snippet}
</FormField>
```

### resources/js/components/atoms/Input.svelte（要点）
`<input ... error → aria-invalid={error || undefined} ...>`。error=false 時は aria-invalid 属性自体が消える。

### resources/js/components/molecules/FormField.svelte（要点）
`invalid: Boolean(error)` を children snippet に渡す。error が null になれば invalid=false → Input の aria-invalid 解除、FormError 非表示。

## 質問
この詳細設計(施策1の $effect + 施策2の vitest)で症状を過不足なく解消できるか。$effect の収束性・既存契約維持・a11y 検証・波及変更の網羅性に見落としはないか。より idiomatic な代替(例: $derived ベース)を採るべき強い理由があれば指摘してほしい。
