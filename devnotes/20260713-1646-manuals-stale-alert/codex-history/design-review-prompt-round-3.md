# 詳細設計レビュー Round 3

Round 2 の [Warning] 2件 (施策1 race / 施策2 race テスト) を反映した。判定を再確認してほしい。

## 対応サマリ

### [Warning] 施策1: 応答と Inertia 更新の順序逆転で stale が残る (race) → 対応 (Codex 案を全面採用)
1. 分類を **要求時スナップショット**で固定: `startAnalyze` 冒頭で `const hadDocumentAtStart = hasDocument;` を取り、
   `handleStartResponse(res, hadDocumentAtStart)` → `classifyStartError(status, body, hadDocumentAtStart)` に渡す。
   422 分岐は `!hadDocumentAtStart` で判定。
2. effect を **level-triggered** 化 (previousHasDocument 廃止):
   `if (hasDocument && isResolvedByDocumentUpload(startErrorKind)) { errorMessage=null; showPurchaseLink=false; startErrorKind=null; }`。
   `missing_document && hasDocument=true` は常に矛盾なので、両順序 (422→upload / 解析中 upload 完了→遅延422) を
   一様に破棄。破棄後 startErrorKind=null で条件が偽になり収束 (無限ループなし)。

### [Warning] 施策2: 競合順序の回帰テスト → 対応
deferred Promise で fetch を保留 → 解析開始 → `hasDocument=true` へ rerender → 遅延 422 を resolve →
alert が残らないことを固定するケース4 を追加。

## 反映後の主要コード (施策1)

```svelte
    type StartErrorKind = "missing_document" | "insufficient_tickets" | "conflict" | "generic";
    let startErrorKind = $state<StartErrorKind | null>(null);

    // level-triggered: missing_document の start error 中に hasDocument=true になったら破棄
    $effect(() => {
        if (hasDocument && isResolvedByDocumentUpload(startErrorKind)) {
            errorMessage = null;
            showPurchaseLink = false;
            startErrorKind = null;
        }
    });

    async function startAnalyze(): Promise<void> {
        if (starting) return;
        starting = true;
        errorMessage = null;
        showPurchaseLink = false;
        sessionExpiredMessage = null;
        startErrorKind = null;
        const hadDocumentAtStart = hasDocument; // 要求時スナップショット
        try {
            const res = await fetch(/* 変更なし */);
            await handleStartResponse(res, hadDocumentAtStart);
        } catch {
            errorMessage = "通信に失敗しました。接続を確認して再度お試しください。";
            startErrorKind = "generic";
        } finally {
            starting = false;
            confirmingReanalyze = false;
        }
    }

    async function handleStartResponse(res: Response, hadDocumentAtStart: boolean): Promise<void> {
        const body = (await res.json().catch(() => null)) as unknown;
        showPurchaseLink = res.status === 402 && isInsufficientTickets(body);
        if (res.status === 201 && body !== null && typeof body === "object") {
            const jobBody = body as AnalysisJobProps;
            currentJob = jobBody;
            status = jobBody.manual_status;
            startErrorKind = null;
            return;
        }
        startErrorKind = classifyStartError(res.status, body, hadDocumentAtStart);
        errorMessage =
            extractMessage(body) ?? "解析を開始できませんでした。時間をおいて再度お試しください。";
    }

    function classifyStartError(status: number, body: unknown, hadDocumentAtStart: boolean): StartErrorKind {
        if (status === 402 && isInsufficientTickets(body)) return "insufficient_tickets";
        if (status === 409) return "conflict";
        if (status === 422 && !hadDocumentAtStart && hasDocumentValidationError(body)) return "missing_document";
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

これで APPROVED か、残る Critical/Warning があれば指摘してほしい。
