全体判定: **CHANGES_REQUESTED**

実装設計の主要部分は承認可能です。残件はテスト計画の1点です。

### 施策 A: APPROVE

追加の必須修正はありません。

### 施策 B: APPROVE

ボタン子孫からの操作と明示的な `click` を含むため、二重発火防止を適切に固定できます。

### 施策 C: APPROVE

`top-1/3` への分離とBrowserでの矩形検査は妥当です。

[Suggestion] 設計ではprimary・guide・secondaryの3レーンが交差しないと主張しているため、矩形テストではguide対primaryだけでなくguide対secondaryも確認すると、主張と機械保証が一致します。

### 施策 D: APPROVE

宣言順、初期判定、SSR保証範囲、告知の消去経路はいずれも整合しています。録画中のprops更新について保証範囲を限定した判断も妥当です。

### 施策 E: REQUEST_CHANGES

[Warning] `UploadQueueBar` の件数検査に `getAllByTestId` を使う計画は、0件の場合に失敗します。`UploadQueueBar` は内部で次の条件を持つため、通常状態ではDOM自体が存在しません。

```svelte
{#if pendingCount > 0 || quotaMessage !== null}
```

したがって、次の計画:

```ts
getAllByTestId("upload-queue-bar").length <= 1
```

は「重複していない正常な0件」で要素未検出例外になります。

修正案:

```ts
expect(screen.queryAllByTestId("upload-queue-bar")).toHaveLength(0);
// pending itemを作ったケースでは
expect(screen.queryAllByTestId("upload-queue-bar")).toHaveLength(1);
```

最低限、切替前後を通して次を使ってください。

```ts
expect(screen.queryAllByTestId("upload-queue-bar").length).toBeLessThanOrEqual(1);
```

可能ならpending状態を用意し、inlineとfullscreenの両方で「常にちょうど1件」を確認する方が、不変条件2の検出力は高くなります。

### 施策 F: APPROVE

正本の重複は解消されています。

[Suggestion] `docs/supported-browsers.md` はBrowserレーンの保証を列挙しているため、施策Cで追加した「撮影ガイドと字幕の非交差」も保証項目へ加えると、実際の自動検査範囲と文書が一致します。

必須修正は施策EのクエリAPIだけです。設計本体に新たなブロッカーはありません。