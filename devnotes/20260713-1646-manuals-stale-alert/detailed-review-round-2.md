## 全体判定: CHANGES_REQUESTED

### 施策1: REQUEST_CHANGES

- [Warning] 応答と Inertia 更新の順序が逆転すると stale alert が残ります。解析要求中にアップロードが完了して `hasDocument=true` になった後、先行した 422 が遅れて返ると、`classifyStartError()` は現在値 `hasDocument=true` を読み `"generic"` と分類します。また false→true 遷移は既に消費済みなので、その後も破棄されません。

  修正案:

  - 解析開始時の `hasDocument` をスナップショットとして分類に渡す。
  - effect は遷移限定ではなく、`hasDocument && startErrorKind === "missing_document"` を満たす間は破棄する。

```ts
const hadDocumentAtStart = hasDocument;
await handleStartResponse(res, hadDocumentAtStart);

function classifyStartError(
    status: number,
    body: unknown,
    hadDocumentAtStart: boolean,
): StartErrorKind {
    if (status === 422 && !hadDocumentAtStart && hasDocumentValidationError(body)) {
        return "missing_document";
    }
    // ...
}

$effect(() => {
    if (hasDocument && isResolvedByDocumentUpload(startErrorKind)) {
        errorMessage = null;
        showPurchaseLink = false;
        startErrorKind = null;
    }
});
```

この形なら `previousHasDocument` 自体が不要で、422→upload と upload→遅延422 の両順序を扱えます。

### 施策2: REQUEST_CHANGES

- [Warning] 上記競合順序の回帰テストが必要です。deferred Promise を使い、解析開始後に `hasDocument=true` へ rerenderし、その後422を解決して alert が残らないことを固定してください。

それ以外の Round 1 指摘への対応は妥当です。DTO/JsonResource、Inertia、ポーリング、failedJob、DS/Atomic、セキュリティ上の新たな問題はありません。