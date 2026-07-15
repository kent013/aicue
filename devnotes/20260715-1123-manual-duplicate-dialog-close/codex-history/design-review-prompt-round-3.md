# Round 3: 詳細設計の修正反映（Warning 2 件対応）

Round 2 の Warning 2 件（Critical なし）に対応しました。施策1/2/5 は既に APPROVE。再判定をお願いします。

## 対応マトリクス

| 指摘 | 対応 |
|------|------|
| [Warning] 施策3 prevOpen の $state 自己依存 | 非 reactive ローカル `let prevOpen = open;` に戻し、依存を reactive な open のみに限定 |
| [Warning] 施策4 エラー消滅テストが偽陽性 | 「open:true で注入し表示確認 → open:false → defaults 変更で open:true → 新値/clearErrors/消滅を確認」の遷移観測に変更 |

## 修正後コード（施策3）

```ts
function seedFromDefaults(): void {
    // 代入対象は useForm の shape と一致する title / category の 2 キーのみ。
    form.title = defaultTitle;
    form.category = defaultCategory === null ? "" : String(defaultCategory);
    form.clearErrors();
}

// prevOpen は非 reactive ローカル（初回 open で同期）。依存は open だけに限定し prevOpen を追跡しない。
let prevOpen = open;
$effect(() => {
    const isOpen = open;
    if (isOpen && !prevOpen) {
        seedFromDefaults();
    }
    prevOpen = isOpen;
});
```

## 修正後テスト3（施策4）

1. `render({ open:true })` → `holder.last.errors.title = "サーバエラー"` 注入 → `waitFor` で
   `getByText("サーバエラー")` 表示確認。
2. `rerender({ open:false })`。
3. `rerender({ open:true, defaultTitle:"新タイトル のコピー", defaultCategory:1 })`（false→true エッジで seed）。
4. `holder.last.title === "新タイトル のコピー"` / `category === "1"` / `clearErrors` 呼び出し /
   `queryByText("サーバエラー")` が null。
5. 追加で open=true のまま `rerender({ defaultTitle:"別タイトル" })` → `holder.last.title` 据え置き（エッジ不変条件）。
