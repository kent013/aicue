## アプリの使命 (North Star)

**AI-CUE** は、現場に既にある**作業手順書(SOP)を起点に**、AI が撮るべきカットを設計した**動画シナリオ**を生成し、そのシナリオを**スマホ(PWA)でナビゲーション撮影**することで、専門知識ゼロの現場作業者でも**標準化されたマニュアル動画**を作れるようにする。

- 「思考ゼロ・編集ゼロ」— 台本作成・撮影判断・編集の 3 ハードルを AI とナビ撮影で肩代わりする。
- 競合(OJT を撮って形式化する tebiki)と異なり、**標準作業を起点に AI が教材設計し撮影を指示する**。
- 熟練者の暗黙知を動画マニュアルという形式知へ変換する装置（SECI）。
- v1 スコープ: 字幕のみ / 撮影は PWA(同一オリジン・セッション認証) / 動画合成は自前 ffmpeg / 単一 Default Project。

## 禁止事項

1. テストなしの実装完了報告
2. PHPStan エラーの widen・baseline 化
3. dev DB への破壊操作をエージェント判断で実行
4. `response()->json()` の直書き(DTO / JsonResource / Inertia を使う。仕様固定 endpoint のみ例外)
5. LLM 呼び出しの Prism 直呼び
6. prompt 文字列のコード直書き
7. 操作系 POST の応答での `redirect()->intended()`
8. 必須条件未充足を理由にボタンを disabled にする UI(押下時にエラー表示する。DESIGN.md)

【思考原則 — 全議論に適用】
まず仮説を立てろ。何を検証したいのか、なぜそう考えるのか、どうなれば成功と判断するのかを明確にしてから手を動かせ。仮説なき改善はただの試行錯誤であり、結果から学ぶことができない。

データに真摯に向き合え。成果だけでなく、多様性の変化、構造の揺らぎ、想定外のパターン — 全てが判断材料になる。数値を見て即座に閾値を弄るな。何が起きているのかを理解し、なぜそうなったのかを考え、どの方向に進むべきかを判断してから手を動かせ。

先人の知恵を探せ。自分たちだけで登る必要はない。乗るべき巨人の肩があるなら乗れ。

機能の名前に立ち返れ。名前はその機能が果たすべき役割を示している。現在の設計がその役割を果たしているか、常に問え。

仕組みが機能していない段階で値を弄るな。閾値チューニングやフィールド追加は、設計の方向性が正しいと確認できてから行え。方向性が間違っているなら、値をいくら調整しても意味はない。設計そのものを見直せ。成果が出なければ早期に見切り、次の仮説へ進め。

【ツール使用制限】
コマンド実行・ファイル書き込みは一切行わず、提供されたテキストの分析に集中すること。ファイル読み込みは許可。

---

## 役割・タスク

あなたは Laravel + Svelte アプリの改善実装をレビューするコードレビュアーである。TODO T022「manuals画面の残留エラーalert解消」の実装差分をレビューせよ。

bug-hunt finding F-H2 (High) の修正。manuals show 画面で、解析起動失敗由来の赤字 alert「手順書をアップロードしてください。」が SOP アップロード成功後も残留する stale local state を解消する。frontend 専用変更（`AnalysisPanel.svelte` + そのテスト）で PHP 変更なし。

### レビュー観点
- 設計との一致性（下記詳細設計書どおりに実装されているか）
- 正確性（stale alert 破棄ロジックの正しさ。level-triggered $effect の無限ループ有無、race 順序の扱い）
- TypeScript 型安全性（`unknown` narrowing、`any` 不使用、union 明示）
- テスト網羅性（再現テスト・種別ゲート・非退行・競合順序）
- セキュリティ
- DESIGN.md 準拠（token 経由参照、hex 直書きを増やさない。本 diff は既存 `Alert` atom のみで新規 hex/SVG なし）
- Atomic Design 準拠（`resources/js/components/` の階層。アイコンは Lucide のみ）

### 出力形式
- ファイルごとに判定
- 指摘は Critical / Warning / Suggestion に分類
- 最後に全体判定を **APPROVED** または **CHANGES_REQUESTED** で明示

---

## 詳細設計書

（要点）根本原因: 赤字 alert の実体は `AnalysisPanel.svelte` のローカル `$state` の `errorMessage`。文言は AI 解析起動 (POST .../analyze) の 422 応答 `{ message, errors: { document: [...] } }` 由来。SOP アップロードは兄弟 `SourceDocumentUpload.svelte` の Inertia form.post で、サーバは `back()->with('success', ...)` を返し Show ページを同一コンポーネントのまま新 props (`hasDocument: false→true`) で再描画する。Inertia は同一ページを再マウントしないため `errorMessage` が seed のまま残留する。

施策1: `AnalysisPanel.svelte` に start error 種別 (`StartErrorKind`) を追加し、`hasDocument` が true になったら「手順書なし(422)」種別 (`missing_document`) の overlay を level-triggered `$effect` で破棄する。分類は解析要求時点の `hadDocumentAtStart` に固定し、応答遅延中に hasDocument が変わっても安定分類する。`missing_document` は `status===422 && !hadDocumentAtStart && errors.document 存在` のときのみ。他 (402 insufficient_tickets / 409 conflict / generic) は破棄しない。

施策2: `AnalysisPanel.test.ts` に回帰テスト追加（overlay 破棄 / 種別ゲート / 非退行 failedJob / 競合順序の遅延422）。`@testing-library/svelte` v5 の `rerender` で props 更新。

設計は Codex gpt-5.4 の概念レビュー R3・詳細レビュー R3 で APPROVED 済み。

## 実装差分（git diff）

```diff
diff --git a/resources/js/components/features/manual/AnalysisPanel.svelte b/resources/js/components/features/manual/AnalysisPanel.svelte
index 17cbd06..fae21db 100644
--- a/resources/js/components/features/manual/AnalysisPanel.svelte
+++ b/resources/js/components/features/manual/AnalysisPanel.svelte
@@ -43,6 +43,15 @@
     let sessionExpiredMessage = $state<string | null>(null);
     let confirmingReanalyze = $state(false);
 
+    /**
+     * start error (解析起動 XHR の失敗) の種別。文言一致でなく種別で分岐することで、
+     * i18n・文言変更に強く、「手順書なし(422)」だけを型安全に破棄できる。
+     * - missing_document: 422 かつ errors.document 由来 (SOP 未アップロード)
+     * - insufficient_tickets / conflict / generic: それ以外
+     */
+    type StartErrorKind = "missing_document" | "insufficient_tickets" | "conflict" | "generic";
+    let startErrorKind = $state<StartErrorKind | null>(null);
+
     const analyzing = $derived(
         status === "analyzing" ||
             currentJob?.status === "queued" ||
@@ -122,6 +131,26 @@
         };
     });
 
+    /* ---- stale overlay 破棄 (bug-hunt F-H2 再現対策) ----
+     * 症状: 解析起動が 422「手順書をアップロードしてください。」で失敗して errorMessage が
+     *   出た後、同一 Show 画面で SOP アップロードが成功しても (Inertia が Show を再描画し
+     *   hasDocument: false→true) errorMessage が seed のまま残留する。
+     * 対策 (level-triggered): 「手順書があれば解消される種別 (missing_document)」の start error が
+     *   出ている状態で hasDocument が true になったら破棄する。missing_document かつ hasDocument=true は
+     *   常に矛盾なので、edge (false→true 遷移) でなく level で判定する。これにより
+     *   「422 表示 → upload」と「解析要求中に upload 完了 → 遅延 422 到達」の両順序を一様に扱える。
+     * 注: currentJob/status は server-truth、sessionExpiredMessage は poll 系のため触らない。
+     *   ポーリングは props を変えない (XHR でローカル state のみ更新) ので、この effect は
+     *   ポーリング進行では発火せず、進捗表示・2.5 秒間隔は壊れない。
+     */
+    $effect(() => {
+        if (hasDocument && isResolvedByDocumentUpload(startErrorKind)) {
+            errorMessage = null;
+            showPurchaseLink = false;
+            startErrorKind = null;
+        }
+    });
+
     /* ---- 起動 ---- */
     function requestAnalyze(): void {
         if (status === "ready") {
@@ -137,6 +166,9 @@
         errorMessage = null;
         showPurchaseLink = false;
         sessionExpiredMessage = null;
+        startErrorKind = null; // 再送時に種別もリセット
+        // 分類を要求時点に固定 (応答遅延中に hasDocument が変わっても安定分類)
+        const hadDocumentAtStart = hasDocument;
         try {
             const res = await fetch(`/projects/${projectId}/manuals/${manualId}/analyze`, {
                 method: "POST",
@@ -147,31 +179,65 @@
                 },
                 credentials: "same-origin",
             });
-            await handleStartResponse(res);
+            await handleStartResponse(res, hadDocumentAtStart);
         } catch {
             errorMessage = "通信に失敗しました。接続を確認して再度お試しください。";
+            startErrorKind = "generic";
         } finally {
             starting = false;
             confirmingReanalyze = false;
         }
     }
 
-    async function handleStartResponse(res: Response): Promise<void> {
+    async function handleStartResponse(res: Response, hadDocumentAtStart: boolean): Promise<void> {
         const body = (await res.json().catch(() => null)) as unknown;
         showPurchaseLink = res.status === 402 && isInsufficientTickets(body);
         if (res.status === 201 && body !== null && typeof body === "object") {
             const jobBody = body as AnalysisJobProps;
             currentJob = jobBody;
             status = jobBody.manual_status;
+            startErrorKind = null; // 成功時は種別もクリア (自己記述的)
             return;
         }
-        // 402 (残高不足) / 409 (競合) / 422 (手順書なし) はサーバのメッセージをそのまま表示
-        const message = extractMessage(body);
-        if (message !== null) {
-            errorMessage = message;
-            return;
+        // 402 (残高不足) / 409 (競合) / 422 (手順書なし) は種別を記録しつつサーバのメッセージを表示
+        startErrorKind = classifyStartError(res.status, body, hadDocumentAtStart);
+        errorMessage =
+            extractMessage(body) ?? "解析を開始できませんでした。時間をおいて再度お試しください。";
+    }
+
+    /**
+     * start error 種別を res.status / body / 解析開始時の hadDocumentAtStart から判定する (文言非依存)。
+     * missing_document は「解析要求時に手順書が無かった (hadDocumentAtStart=false)」を条件に含める。
+     * これにより:
+     *  - 将来 document フィールド由来の別 422 (形式/容量) を誤分類しない。
+     *  - 応答遅延中に hasDocument が true へ変わっても、要求時点の値で安定して分類できる。
+     */
+    function classifyStartError(
+        status: number,
+        body: unknown,
+        hadDocumentAtStart: boolean,
+    ): StartErrorKind {
+        if (status === 402 && isInsufficientTickets(body)) return "insufficient_tickets";
+        if (status === 409) return "conflict";
+        if (status === 422 && !hadDocumentAtStart && hasDocumentValidationError(body)) {
+            return "missing_document";
         }
-        errorMessage = "解析を開始できませんでした。時間をおいて再度お試しください。";
+        return "generic";
+    }
+
+    /** 422 body に errors.document (SOP 未アップロード) が含まれるか。
+     *  bare 422 を一律 missing_document にしない (将来別用途の 422 と混同しないため)。 */
+    function hasDocumentValidationError(body: unknown): boolean {
+        if (body === null || typeof body !== "object") return false;
+        const errors = (body as { errors?: unknown }).errors;
+        if (errors === null || typeof errors !== "object") return false;
+        const doc = (errors as { document?: unknown }).document;
+        return Array.isArray(doc) && doc.length > 0;
+    }
+
+    /** hasDocument が満たされたとき自動破棄してよい start error 種別か (missing_document のみ) */
+    function isResolvedByDocumentUpload(kind: StartErrorKind | null): boolean {
+        return kind === "missing_document";
     }
 
     /** 402/409 の { message } と 422 の { message, errors } からユーザー向け文言を取り出す */
diff --git a/tests/js/components/features/manual/AnalysisPanel.test.ts b/tests/js/components/features/manual/AnalysisPanel.test.ts
index 10e4886..cab9032 100644
--- a/tests/js/components/features/manual/AnalysisPanel.test.ts
+++ b/tests/js/components/features/manual/AnalysisPanel.test.ts
@@ -147,6 +147,97 @@ describe("AnalysisPanel", () => {
         expect(screen.getByTestId("analyze-button")).not.toBeDisabled();
     });
 
+    it("SOP アップロード成功 (hasDocument false→true) で 422 の残留 alert が消える", async () => {
+        // bug-hunt F-H2 再現: 422 の start error が SOP アップロード成功後も残留しないこと
+        fetchMock.mockResolvedValue(
+            jsonResponse(422, {
+                message: "手順書をアップロードしてください。",
+                errors: { document: ["手順書をアップロードしてください。"] },
+            }),
+        );
+        const { rerender } = render(AnalysisPanel, {
+            props: { ...baseProps, hasDocument: false },
+        });
+        await fireEvent.click(screen.getByTestId("analyze-button"));
+        await waitFor(() =>
+            expect(screen.getByTestId("analysis-start-error")).toHaveTextContent(
+                "手順書をアップロードしてください",
+            ),
+        );
+        // SOP アップロード成功 = Inertia が hasDocument=true で Show を再描画
+        await rerender({ ...baseProps, hasDocument: true });
+        await waitFor(() => expect(screen.queryByTestId("analysis-start-error")).toBeNull());
+        // 非干渉: 購入リンク等の他 overlay を巻き込んで表示していない
+        expect(screen.queryByTestId("analysis-purchase-link")).toBeNull();
+    });
+
+    it("402 (残高不足) は他 props 更新後も消えない (missing_document 以外は保持)", async () => {
+        fetchMock.mockResolvedValue(
+            jsonResponse(402, {
+                code: "insufficient_tickets",
+                message: "チケット残高が不足しています。",
+            }),
+        );
+        // 402 は手順書ありの文脈で発生する → hasDocument:true 開始 (baseProps 既定)
+        const { rerender } = render(AnalysisPanel, { props: baseProps });
+        await fireEvent.click(screen.getByTestId("analyze-button"));
+        await waitFor(() =>
+            expect(screen.getByTestId("analysis-start-error")).toHaveTextContent(
+                "チケット残高が不足",
+            ),
+        );
+        // 別 props (manualStatus) が更新されても start error は保持される
+        await rerender({ ...baseProps, manualStatus: "ready" as const });
+        expect(screen.getByTestId("analysis-start-error")).toBeInTheDocument();
+    });
+
+    it("非退行: failed job (server-truth) は hasDocument false→true でも維持される", async () => {
+        const failedJob: AnalysisJobProps = {
+            id: 9,
+            status: "failed",
+            step: "extract",
+            progress: 10,
+            error: "テキストを抽出できません。画像・スキャンの手順書は現在未対応です。",
+            manual_status: "draft",
+        };
+        const { rerender } = render(AnalysisPanel, {
+            props: { ...baseProps, hasDocument: false, manualStatus: "draft" as const, job: failedJob },
+        });
+        expect(screen.getByTestId("analysis-error")).toHaveTextContent("テキストを抽出できません");
+        // overlay 破棄は start-error のみ対象。failedJob は currentJob 由来なので残る
+        await rerender({
+            ...baseProps,
+            hasDocument: true,
+            manualStatus: "draft" as const,
+            job: failedJob,
+        });
+        expect(screen.getByTestId("analysis-error")).toBeInTheDocument();
+    });
+
+    it("解析要求中に hasDocument が true になり、遅延 422 が来ても alert は残らない", async () => {
+        let resolveFetch!: (r: Response) => void;
+        fetchMock.mockReturnValue(
+            new Promise<Response>((r) => {
+                resolveFetch = r;
+            }),
+        );
+        const { rerender } = render(AnalysisPanel, {
+            props: { ...baseProps, hasDocument: false },
+        });
+        await fireEvent.click(screen.getByTestId("analyze-button"));
+        // 応答が返る前に SOP アップロード完了 → Inertia が hasDocument=true で再描画
+        await rerender({ ...baseProps, hasDocument: true });
+        // 遅延していた 422 がここで解決 (分類は hadDocumentAtStart=false → missing_document)
+        resolveFetch(
+            jsonResponse(422, {
+                message: "手順書をアップロードしてください。",
+                errors: { document: ["手順書をアップロードしてください。"] },
+            }),
+        );
+        // level-triggered effect が hasDocument=true && missing_document を検知して即破棄
+        await waitFor(() => expect(screen.queryByTestId("analysis-start-error")).toBeNull());
+    });
+
     it("analyzing 中は step ラベルと progress を表示し、解析ボタンは出さない", () => {
         fetchMock.mockResolvedValue(
             jsonResponse(200, {

```

## テスト結果

- pnpm test (vitest): 480 passed (68 files)。うち AnalysisPanel.test.ts に 4 ケース追加、既存 11 ケース非退行。
- pnpm lint / pnpm typecheck / pnpm build: green
- vendor/bin/pint --test: passed / composer phpstan: No errors (631 files) / composer test (PHP): 1559 passed, 2 skipped（PHP 変更なし・非退行確認）

## design system 参照

触れた atomic ディレクトリ: `resources/js/components/features/manual/AnalysisPanel.svelte`（features 層）。使用コンポーネントは既存 `atoms/Alert.svelte`, `atoms/Button.svelte`, `atoms/Card.svelte`, `atoms/TextLink.svelte`, `organisms/ConfirmDialog.svelte`。アイコンは `@lucide/svelte`（LoaderCircle, Sparkles）。本 diff で新規 hex カラー・SVG 直書き・新規コンポーネントの追加はなし。
