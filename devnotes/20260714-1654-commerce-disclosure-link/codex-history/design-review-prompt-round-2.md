# design-review Round 2（Round 1 CHANGES_REQUESTED への対応）

Round 1 の指摘への対応を反映しました。対応マトリクスと更新後のテスト設計を提示します。

## 対応サマリー

### [Warning] 施策3/4: 順序依存の脆さ → 対応
- 順序検証は元々 `.filter(href => ["/terms","/privacy","/commerce-disclosure"].includes(href))` で
  **法的リンクだけに絞ってから** DOM 順比較しており、非法的リンク（料金プラン/お問い合わせ）の
  増減では壊れません（ノイズ耐性あり）。この点を明確化しつつ、意図をさらに固めるため
  **terms / privacy / commerce の href を個別に `getByRole` で取得して assert** する二段構えに補強しました。

### [Warning] 施策4: `within` 未 import → 対応
- 施策4 の「変更箇所」に import 差し替えを正式に格上げしました:
  `import { fireEvent, render, screen, within } from "@testing-library/svelte";`
  （Welcome.test.ts は既に within を import 済みのため追加不要）。

### [Warning] ラベル完全一致 → 見送り（反論）
- 本テストの狙いは footer 文言を blade（`legal/commerce-disclosure.blade.php` の
  `特定商取引法に基づく表記`）と一致させる契約の固定です。法定表記であり表記ゆれを許容する
  正規表現は契約を緩めるため、完全一致（exact name）を維持します。Round1 でも「契約として
  文言固定が必要なら現状維持で可」と許容いただいた点です。

## 更新後テスト（施策3: Welcome、施策4: Pricing とも同型）

```ts
it("フッターに法的リンク3件 (利用規約→プライバシー→特商法) を href と順序どおり出す", () => {
    render(Welcome, { props: baseProps }); // Pricing 版は render(Pricing, { props: { appName: "AI-CUE", page: basePage } })

    const footer = screen.getByRole("contentinfo");

    // (a) 法的3リンクを名前で個別取得し href を契約化 (terms/privacy の欠落も個別検出)
    expect(within(footer).getByRole("link", { name: "利用規約" })).toHaveAttribute("href", "/terms");
    expect(within(footer).getByRole("link", { name: "プライバシーポリシー" })).toHaveAttribute("href", "/privacy");
    expect(within(footer).getByRole("link", { name: "特定商取引法に基づく表記" })).toHaveAttribute("href", "/commerce-disclosure");

    // (b) 法的リンクのみを DOM 順で抽出し表示順を固定 (非法的リンクは filter 除外 = ノイズ耐性)
    const legalHrefs = within(footer)
        .getAllByRole("link")
        .map((a) => a.getAttribute("href"))
        .filter((href) => ["/terms", "/privacy", "/commerce-disclosure"].includes(href ?? ""));
    expect(legalHrefs).toEqual(["/terms", "/privacy", "/commerce-disclosure"]);
});
```

施策4（Pricing）は import に `within` を追加した上で同型テストを追加します。

施策1/2（footer への `<a href="/commerce-disclosure" class="hover:text-primary">特定商取引法に基づく表記</a>` 追加、
privacy の直後）は Round 1 で APPROVE 済みのため変更ありません。

この対応で全施策が APPROVED になるかご判定ください。残る指摘があれば挙げてください。
