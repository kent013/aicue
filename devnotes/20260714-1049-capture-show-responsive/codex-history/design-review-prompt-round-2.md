# 詳細設計レビュー依頼 (design-review round 2)

Round 1 の指摘への対応です。対応マトリクスと修正差分を示します。

## [Critical] 施策3 テストの brittle な DOM 辿り → 安定 testid へ
対応: `Show.svelte` の grid/左右 pane に `data-testid` を付与し、テストは testid 直接取得に変更。

変更後 Show.svelte（レイアウト部）:
```svelte
<div class="mt-4 grid grid-cols-1 gap-4 lg:grid-cols-2" data-testid="capture-grid">
    <section class="min-w-0 rounded-md border border-border bg-surface" data-testid="capture-left-pane">
        ... <CutNavigator .../> ...
    </section>
    <section class="flex min-w-0 flex-col gap-4" data-testid="capture-right-pane"> ... </section>
</div>
```
変更後 施策3 テスト:
```ts
it("グリッドは mobile 単一列 (grid-cols-1)、左右 pane が min-w-0 を持つ", () => {
    stubCameraSupported(false);
    render(CaptureShow, { props: baseProps });
    const grid = screen.getByTestId("capture-grid");
    expect(grid.className).toContain("grid-cols-1");
    expect(screen.getByTestId("capture-left-pane").className).toContain("min-w-0");
    expect(screen.getByTestId("capture-right-pane").className).toContain("min-w-0");
});
```

## [Critical] 施策4 の makeCut() 都度生成 → 同一参照へ + 2段検証
対応: `const cut = makeCut();` を先に定義し render/getByText で同一参照。shooting_point は
span(truncate) と 親<p>(min-w-0) の 2 段検証 + MapPin(shrink-0) アサーションを追加。
```ts
it("shooting_point 行は <p>min-w-0 + <span>truncate、MapPin は shrink-0", () => {
    const cut = makeCut();
    render(CutNavigator, { props: { cuts: [cut], selectedCutId: null, onSelect: vi.fn() } });
    const sp = screen.getByText(cut.shooting_point!);
    expect(sp.tagName).toBe("SPAN");
    expect(sp.className).toContain("truncate");
    const row = sp.closest("p");
    expect(row!.className).toContain("min-w-0");
    expect(row!.querySelector("svg")?.getAttribute("class") ?? "").toContain("shrink-0");
});
```

## [Warning] 施策2 は施策1 依存 → 単独適用不可を明示
対応: 「施策1 → 施策2 の順、同一 PR でマージ必須」を詳細設計に明記。

## [Warning] red→green のコマンド結果を残す
対応: 受け入れ条件に `pnpm test -- CaptureShow` / `CutNavigator` の red→green 要約を
実装 PR の devnotes に残すことを追加（実装は app-implement の責務）。

## [Suggestion] 右カラム保守メモ / MapPin shrink-0 仕様化
対応: 右カラムに横幅固定要素を足す場合は min-w-0 優先、の保守メモを追記。MapPin shrink-0 をテストで固定化。

---

上記反映で施策3/4 の Critical は解消したと考えます。全体判定（APPROVED か）を返してください。残課題があれば具体修正案付きで指摘してください。
