# impl-review Round 2: Round 1 指摘への対応

Round 1 の判定は CHANGES_REQUESTED でした。指摘への対応は以下のとおりです。再レビューをお願いします。

## [Critical] Escape の focus 復帰固定 / 発火対象の不一致 → 対応済み

`tests/js/components/features/organizations/OrganizationSwitcher.test.ts` の Escape ケースを修正:

```ts
it("Escape でパネルを閉じ、トリガーへ focus を復帰する", async () => {
    render(OrganizationSwitcher, {
        props: { currentOrganization: currentOrg(), organizations: [] },
    });
    await openPanel();
    expect(document.getElementById("org-switcher-panel")).not.toBeNull();

    // 実装は open 中のみ document に keydown を張るため、発火対象も document に合わせる
    await fireEvent.keyDown(document, { key: "Escape" });

    expect(document.getElementById("org-switcher-panel")).toBeNull();
    // S3 a11y 要件: Escape 後はトリガーへ focus 復帰する
    expect(screen.getByTestId("org-switcher-trigger")).toHaveFocus();
});
```

- 発火を `document` に変更し実装の keydown リスナ経路と一致。
- `toHaveFocus()` で S3 の focus 復帰要件を回帰固定。
- `pnpm test` 再実行: **490 passed / 69 files**（全 green 維持）。

## [Warning] native `<button>` 採用の設計差分 → コメントで明記済み

`OrganizationSwitcher.svelte` の先頭ドキュメントコメントに「設計との差分（意図的）」節を追加:

```
     * 設計との差分 (意図的): 詳細設計 S3 は「内部は atoms(Button) を合成」と記したが、トリガー/
     * 切替行は Button atom の variant スタイル (枠線・padding ramp) と噛み合わない menu-item 表現が
     * 必要なため native <button> を採用する。Button atom は単機能ボタン用で、id/aria-expanded/
     * aria-controls を要する disclosure トリガーには過剰。DS token は同一 (rounded-md/border-border/
     * bg-surface/text-body)。Lucide のみ・SVG 直書きなしは維持する。
```

## [Suggestion] 2 件 → 見送り（根拠を記録）

- `aria-controls` 補助属性: disclosure 標準実装であり実害なし。オーバーエンジニアリング回避のため見送り。
- Link onclick={close} のテスト追加: 実 `Link` click は Inertia 内部 `router.visit`（本テスト未 mock）を発火し不安定化するため見送り。close は switch/Escape/outside/focusout で固定済み。

以上を踏まえ、全体判定（APPROVED / CHANGES_REQUESTED）をお願いします。
