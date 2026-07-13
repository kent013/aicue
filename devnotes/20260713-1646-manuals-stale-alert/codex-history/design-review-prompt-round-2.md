# 詳細設計レビュー Round 2

Round 1 の [Critical] 2件 / [Warning] 4件 / [Suggestion] 1件を反映した。判定を再確認してほしい。

## 対応サマリ

### [Critical] 施策1: `hasDocumentValidationError` が弱く将来の document 由来 422 を誤破棄 → 対応
`classifyStartError` の 422 分岐を **`status === 422 && !hasDocument && hasDocumentValidationError(body)`** に強化。
missing-doc 422 は「手順書が存在しない」ときのみ発生し `hasDocument: false` と一致する
(両者とも sourceDocuments()->exists() 由来)。現在の `hasDocument === false` を分類条件に含めることで、
将来 document フィールド由来の別 422 (形式/容量。実際は upload endpoint 側で発生) を missing_document へ
誤分類しない。backend 変更なし。

### [Critical] 施策2 ケース2: 402 を hasDocument:false で作るのはドメイン乖離 → 対応
ケース2 は初期 props を `hasDocument: true` (baseProps 既定) で開始し、`rerender` は `manualStatus` のみ変更して
「missing_document 以外 (402) は消えない」を検証する形に修正。強化後の分類 `!hasDocument` 条件とも整合。

### [Warning] 施策1: plain 変数の遷移検出が見落とされやすい → 対応
変数名を `previousHasDocument` に変更。遷移ケース (初回 true / false→true / true→ready) をテストで固定
(初回 true は破棄対象エラーが出ない旨を明記)。

### [Warning] 施策1: 「missing_document だけ破棄・他は保持」がコードから読み取りづらい → 対応
述語関数 `isResolvedByDocumentUpload(kind): boolean`（= `kind === "missing_document"`）を導入し
effect で使用（自己記述化）。

### [Suggestion] 施策1: 201 成功分岐で startErrorKind=null 明示 → 対応
`handleStartResponse` の 201 分岐に `startErrorKind = null;` を追加。

### [Warning] 施策2 ケース1: showPurchaseLink 等への非干渉が未固定 → 対応
ケース1 に `expect(screen.queryByTestId("analysis-purchase-link")).toBeNull()` を追加。

### [Warning] 施策2: 非退行テストの意図を名前/コメントに明記 → 対応
ケース3 のテスト名を「start-error のみ破棄、failedJob (server-truth) は維持される」に。

## 反映後の主要コード（施策1）

```svelte
    type StartErrorKind = "missing_document" | "insufficient_tickets" | "conflict" | "generic";
    let startErrorKind = $state<StartErrorKind | null>(null);

    let previousHasDocument = hasDocument; // 非リアクティブ: 前回値
    $effect(() => {
        const nowHasDocument = hasDocument;
        const wasHasDocument = previousHasDocument;
        previousHasDocument = nowHasDocument;
        if (!wasHasDocument && nowHasDocument && isResolvedByDocumentUpload(startErrorKind)) {
            errorMessage = null;
            showPurchaseLink = false;
            startErrorKind = null;
        }
    });

    function classifyStartError(status: number, body: unknown): StartErrorKind {
        if (status === 402 && isInsufficientTickets(body)) return "insufficient_tickets";
        if (status === 409) return "conflict";
        if (status === 422 && !hasDocument && hasDocumentValidationError(body)) return "missing_document";
        return "generic";
    }

    function hasDocumentValidationError(body: unknown): boolean {
        if (body === null || typeof body !== "object") return false;
        const errors = (body as { errors?: unknown }).errors;
        if (errors === null || typeof errors !== "object") return false;
        const doc = (errors as { document?: unknown }).document;
        return Array.isArray(doc) && doc.length > 0;
    }

    function isResolvedByDocumentUpload(kind: StartErrorKind | null): boolean {
        return kind === "missing_document";
    }
```

handleStartResponse の error 分岐: `startErrorKind = classifyStartError(res.status, body);` の後
`errorMessage = extractMessage(body) ?? "解析を開始できませんでした。時間をおいて再度お試しください。";`。
startAnalyze 冒頭リセットに `startErrorKind = null;`、catch に `startErrorKind = "generic";`。

これで APPROVED か、残る Critical/Warning があれば指摘してほしい。
