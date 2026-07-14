# 詳細設計レビュー依頼 (design-review round 3)

Round 2 の残 Warning（施策4）に対応しました。

## [Warning] 施策4: span の min-w-0/flex-1 も検証
対応: アサーションを toHaveClass に強化。
```ts
const sp = screen.getByText(cut.shooting_point!);
expect(sp.tagName).toBe("SPAN");
expect(sp).toHaveClass("min-w-0", "flex-1", "truncate");
const row = sp.closest("p");
expect(row).not.toBeNull();
expect(row).toHaveClass("flex", "min-w-0");
const icon = row!.querySelector("svg");
expect(icon).not.toBeNull();
expect(icon).toHaveClass("shrink-0");
```

これで min-w-0/flex-1/truncate のいずれが欠けても red になります。全体判定を返してください。
