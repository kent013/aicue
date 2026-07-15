# Round 2: 詳細設計の修正反映

Round 1 の指摘（Critical 2 / Warning 5）に全対応しました。対応マトリクスと修正差分を提示します。再判定をお願いします。

## 対応マトリクス

| 指摘 | 対応 |
|------|------|
| [Critical] 施策3 エッジ検知が脆い | `let prevOpen = $state(open)` で初期同期し、effect 冒頭で「前値退避→更新→遷移判定」の順に変更 |
| [Critical] 施策4 UI抑止/関数ガードの判別不能 | テストを 2a(関数ガード=form submit 直接発火→post未呼び)と 2b(UIガード=processing時 aria-busy/disabled)に分離 |
| [Warning] 施策1 onSuccess タイミング断定 | 不変条件記述に修正（「onSuccess で open=false を必ず実行し開きっぱなしを防ぐ」。必ず呼ばれる断定を撤回） |
| [Warning] 施策3 seed の shape 依存 | title/category の 2 キーのみ代入する旨コメント明記 |
| [Warning] 施策4 実害観点不足 | テスト3にエラー注入→再オープン後 queryByText で消滅を観測する assertion 追加 |
| [Warning] 施策5 initial 衝突が暗黙 | generic 制約を `TData extends Record<string, unknown> & { processing?: never; errors?: never }` に |
| [Suggestion] onSuccess 内 clearErrors 等 | 施策3で reopen 時 clear するため重複回避で見送り。他は範囲外で見送り |

## 修正後コード（施策3 エッジ検知）

```ts
function seedFromDefaults(): void {
    // 代入対象は useForm の shape と一致する title / category の 2 キーのみ (他キー拡張時の事故防止)。
    form.title = defaultTitle;
    form.category = defaultCategory === null ? "" : String(defaultCategory);
    form.clearErrors();
}

// prevOpen を open で初期同期し、effect 冒頭で「前値退避→更新→遷移判定」の順でエッジ (false→true) を検知。
let prevOpen = $state(open);
$effect(() => {
    const wasOpen = prevOpen;
    prevOpen = open;
    if (open && !wasOpen) {
        seedFromDefaults();
    }
});
```

初回マウントは `prevOpen = $state(open)` で同期されるため、`open:true` 直接マウント（既存 prefill テスト）でも
wasOpen=true となり seed は走らない（useForm 初期値をそのまま使う）。

## 修正後の施策1リスク記述（不変条件化）

「複製成功（onSuccess）時は `open = false` を必ず実行し、同一 Manuals/Show コンポーネント再利用時にモーダルが
開きっぱなしになることを防ぐ」。onSuccess が redirect と競合することはない。Inertia 実装差異・将来変更に
依存しない不変条件として扱う。

## 修正後のテスト計画（施策4 抜粋）

- 2a 関数ガード: `holder.last.processing = true` → `#duplicate-manual-form` へ `fireEvent.submit` →
  `expect(holder.last.post).not.toHaveBeenCalled()`（冒頭 `if (form.processing) return`）。
- 2b UIガード: `holder.last.processing = true` → `waitFor` で `duplicate-manual-confirm` が `aria-busy="true"`
  かつ `toBeDisabled()`（施策5で processing 反応化後に観測）。
- 3 再seed+エラー消滅: `open:false` で render → `holder.last.errors.title = "サーバエラー"` 注入 →
  `rerender({ open:true, defaultTitle:"新タイトル のコピー", defaultCategory:1 })` →
  `holder.last.title === "新タイトル のコピー"` / `category === "1"` / `clearErrors` 呼び出し /
  `queryByText("サーバエラー")` が null。続けて open=true のまま `rerender({ defaultTitle:"別タイトル" })` →
  `holder.last.title` は据え置き（エッジ限定不変条件）。
- 4 onSuccess close: confirm クリック → `post.mock.calls[0][1].onSuccess()` 発火 →
  `queryByTestId("duplicate-manual-dialog")` が null。
- 5 既存維持: 「未充足でも disabled にしない（禁止事項8）」。

## 修正後コード（施策5 型ガード）

```ts
export function reactiveUseForm<
  TData extends Record<string, unknown> & { processing?: never; errors?: never },
>(
  initial: TData,
  initialErrors: Record<string, string> = {},
): TData & { errors: Record<string, string>; processing: boolean; /* ... */ } {
  const errors = $state<Record<string, string>>({ ...initialErrors });
  let processing = $state(false);
  // ... get/set processing で反応化 (errors と同型パターン)
}
```
