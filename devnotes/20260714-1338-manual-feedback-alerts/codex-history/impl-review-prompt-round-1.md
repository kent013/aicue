# アプリの使命 (North Star)

**AI-CUE** は、現場に既にある**作業手順書(SOP)を起点に**、AI が撮るべきカットを設計した**動画シナリオ**を生成し、そのシナリオを**スマホ(PWA)でナビゲーション撮影**することで、専門知識ゼロの現場作業者でも**標準化されたマニュアル動画**を作れるようにする。

- 「思考ゼロ・編集ゼロ」— 台本作成・撮影判断・編集の 3 ハードルを AI とナビ撮影で肩代わりする。
- 競合(OJT を撮って形式化する tebiki)と異なり、**標準作業を起点に AI が教材設計し撮影を指示する**（撮影者・教える人のスキルに品質を依存させない）。
- 熟練者の暗黙知を動画マニュアルという形式知へ変換する装置（SECI）。

# 禁止事項

1. テストなしの実装完了報告(不変条件は対応する Architecture/Feature テストへの登録まで含めて「実装済み」)
2. PHPStan エラーの widen(型を緩めて黙らせる)・baseline 化
3. dev DB への破壊操作(`migrate:fresh` 等)をエージェント判断で実行すること
4. `response()->json()` の直書き(DTO / JsonResource / Inertia を使う。仕様固定 endpoint のみ例外)
5. LLM 呼び出しの Prism 直呼び(`app/Prompts/` の factory 経由のみ)
6. prompt 文字列のコード直書き(`resources/prompts/*.yaml` に置く)
7. 操作系 POST の応答での `redirect()->intended()`
8. 必須条件未充足を理由にボタンを disabled にする UI(押下時にエラー表示する。DESIGN.md)

【思考原則 — 全議論に適用】
まず仮説を立てろ。何を検証したいのか、なぜそう考えるのか、どうなれば成功と判断するのかを明確にしてから手を動かせ。仮説なき改善はただの試行錯誤であり、結果から学ぶことができない。

データに真摯に向き合え。成果だけでなく、多様性の変化、構造の揺らぎ、想定外のパターン — 全てが判断材料になる。数値を見て即座に閾値を弄るな。何が起きているのかを理解し、なぜそうなったのかを考え、どの方向に進むべきかを判断してから手を動かせ。

先人の知恵を探せ。自分たちだけで登る必要はない。乗るべき巨人の肩があるなら乗れ。

機能の名前に立ち返れ。名前はその機能が果たすべき役割を示している。現在の設計がその役割を果たしているか、常に問え。

仕組みが機能していない段階で値を弄るな。設計そのものを見直せ。成果が出なければ早期に見切り、次の仮説へ進め。

【ツール使用制限】
コマンド実行・ファイル書き込みは一切行わず、提供されたテキストの分析に集中すること。ファイル読み込みは許可。

---

# system: コードレビュアー

あなたは Laravel + Svelte アプリのコードレビュアーです。TODO **T040**（manual 画面のフィードバック/alert 帰属改善）の frontend 実装をレビューしてください。bug-hunt real-llm run で見つかった 2 件（F-1-1 / F-1-2）への incremental 改修で、backend / DTO / Inertia Props 型は不変です。

## レビュー観点
- **設計との一致性**: 詳細設計書の施策 S1 / S2 の意図（justSaved は保存成功パスのみ true・409 reseed で偽成功を出さない / 起動失敗を render・preview で source 別 state に分離し後発が先発を上書きしない・phase-aware title で帰属明示）を満たしているか
- **正確性**: 状態遷移バグ・競合上書き・誤帰属が残っていないか。特に justSaved の遷移（applySaved=true / reseed=false / save 開始=false / showFailure=false / dirty 転換=false）と、renderStartError / previewStartError の独立性
- **テスト網羅性**: 施策の不変条件（偽成功防止・source 別共存・後発非上書き）が回帰テストで固定されているか
- **DESIGN.md 準拠**: color / radius / typography は DS token 経由か。hex 直書きを増やしていないか（text-success は既存 token）。ボタン disabled 化していないか
- **Atomic Design 準拠**: features/manual コンポーネントが atoms(Alert/TextLink/Button) を正しく利用し階層を逆流していないか。アイコンは Lucide のみか（Check を @lucide/svelte から import）
- **セキュリティ**: 403 body の内部文言を漏らしていないか等（本 diff の範囲で）
- **アクセシビリティ**: 成功インジケータが toast と二重読み上げにならないか（live region にしない設計）

## 出力形式
- ファイルごとに判定
- 指摘を Critical / Warning / Suggestion に分類
- 最後に全体判定を **APPROVED** または **CHANGES_REQUESTED** で明示

---

# user

## 詳細設計書（detailed-design.md 抜粋）

施策1 (F-1-1): ScenarioEditor.svelte に永続の「保存しました」インジケータ `justSaved` を追加。true にするのは applySaved() のみ。reseed()・save 開始・showFailure()・dirty 転換($effect) で false。ボタン横で dirty と排他表示（dirty 優先 → justSaved）。409 競合リロードの reseed でも偽成功を出さない。text-success token + Lucide Check。

施策2 (F-1-2): RenderPanel.svelte の起動失敗 state `errorMessage`/`showPurchaseLink`（render/preview 共有）を source 別 2 state（`renderStartError` / `previewStartError`、型 `StartError = { message; showPurchaseLink }`）に分離。start() は該当 source のみクリア。handleStartResponse は kind に応じて格納。preview 起動失敗を preview 小節に `preview-start-error`（新 testId）+ `preview-purchase-link`（新 testId）として表示。全 danger Alert に phase-aware title を付与（完成動画の生成に失敗しました / 完成動画の生成を開始できませんでした / プレビューの生成に失敗しました / プレビューの生成を開始できませんでした）。

帰属マトリクス:
| 発生源×局面 | testId | Alert title |
| 完成動画・起動失敗 | render-start-error | 完成動画の生成を開始できませんでした |
| 完成動画・ジョブ失敗 | render-error | 完成動画の生成に失敗しました |
| プレビュー・起動失敗 | preview-start-error(新) | プレビューの生成を開始できませんでした |
| プレビュー・ジョブ失敗 | preview-error | プレビューの生成に失敗しました |

制約: backend / DTO / Props 型 不変。PHP 変更なし。Svelte 5 runes + DS token のみ。

## design system 参照（DESIGN.md 抜粋）
- `success: "#15803D"` / tailwind `text-success`, `bg-success`, `border-success`（完了・正常・公開済み）
- Alert atom は `type: success/warning/danger/info` + 任意 `title`（状態色見出し）。danger のみ role=alert(assertive)。
- Toast 自動消去: success/info/warning = 4 秒、error = 手動閉じのみ。
- 触れた atomic 階層: `resources/js/components/features/manual/{ScenarioEditor,RenderPanel}.svelte`（features 層）が `atoms/{Alert,TextLink,Button,Card,Input,Select,Textarea}` と `molecules/{EmptyState,FormField}` / `organisms/ConfirmDialog` を利用。アイコンは `@lucide/svelte`（Check 追加）。

## 実装差分（git diff）

```diff
diff --git a/resources/js/components/features/manual/RenderPanel.svelte b/resources/js/components/features/manual/RenderPanel.svelte
index 021eea7..b355936 100644
--- a/resources/js/components/features/manual/RenderPanel.svelte
+++ b/resources/js/components/features/manual/RenderPanel.svelte
@@ -43,9 +43,12 @@
     // svelte-ignore state_referenced_locally
     let status = $state<VideoManualStatus>(manualStatus);
     let starting = $state(false);
-    let errorMessage = $state<string | null>(null);
-    // 402 (残高不足) のとき購入導線を併記する (code 厳格一致。他エラーで誤表示しない)
-    let showPurchaseLink = $state(false);
+    // 起動失敗の表示モデル (message + 402 残高不足時の購入導線)。402 (残高不足) のときのみ
+    // showPurchaseLink=true (code 厳格一致。他エラーで誤表示しない)。
+    type StartError = { message: string; showPurchaseLink: boolean };
+    // 起動失敗は render/preview 独立に保持する (共有だと後発が先発を上書きし帰属が崩れる)
+    let renderStartError = $state<StartError | null>(null);
+    let previewStartError = $state<StartError | null>(null);
     let sessionExpiredMessage = $state<string | null>(null);
     let confirmingRender = $state(false);
 
@@ -161,8 +164,9 @@
     async function start(kind: "render" | "preview"): Promise<void> {
         if (starting) return; // 多重送信ガード (disabled にはしない)
         starting = true;
-        errorMessage = null;
-        showPurchaseLink = false;
+        // 該当 source のみクリア (もう片方の失敗表示は帰属を保つため残す)
+        if (kind === "render") renderStartError = null;
+        else previewStartError = null;
         sessionExpiredMessage = null;
         try {
             const res = await fetch(`/projects/${projectId}/manuals/${manualId}/${kind}`, {
@@ -176,7 +180,12 @@
             });
             await handleStartResponse(kind, res);
         } catch {
-            errorMessage = "通信に失敗しました。接続を確認して再度お試しください。";
+            const failure: StartError = {
+                message: "通信に失敗しました。接続を確認して再度お試しください。",
+                showPurchaseLink: false,
+            };
+            if (kind === "render") renderStartError = failure;
+            else previewStartError = failure;
         } finally {
             starting = false;
             confirmingRender = false;
@@ -185,7 +194,6 @@
 
     async function handleStartResponse(kind: "render" | "preview", res: Response): Promise<void> {
         const body = (await res.json().catch(() => null)) as unknown;
-        showPurchaseLink = res.status === 402 && isInsufficientTickets(body);
         if (res.status === 201 && body !== null && typeof body === "object") {
             const jobBody = body as RenderJobProps;
             if (kind === "render") {
@@ -196,10 +204,16 @@
             }
             return;
         }
-        // 402 (残高不足) / 409 (競合) / 422 (採用テイク欠落・尺超過) はサーバのメッセージを表示
-        const message = extractMessage(body);
-        errorMessage =
-            message ?? "書き出しを開始できませんでした。時間をおいて再度お試しください。";
+        // 402 (残高不足) / 409 (競合) / 422 (採用テイク欠落・尺超過) はサーバのメッセージを表示。
+        // 起動失敗は source 別 state に積む (完成動画/プレビューの帰属を保つ)。
+        const failure: StartError = {
+            message:
+                extractMessage(body) ??
+                "書き出しを開始できませんでした。時間をおいて再度お試しください。",
+            showPurchaseLink: res.status === 402 && isInsufficientTickets(body),
+        };
+        if (kind === "render") renderStartError = failure;
+        else previewStartError = failure;
     }
 
     /** 402/409 の { message } と 422 の { message, errors } からユーザー向け文言を取り出す */
@@ -259,7 +273,9 @@
     {:else}
         {#if failedRenderJob?.error}
             <div class="mt-4" data-testid="render-error">
-                <Alert type="danger">{failedRenderJob.error}</Alert>
+                <Alert type="danger" title="完成動画の生成に失敗しました">
+                    {failedRenderJob.error}
+                </Alert>
             </div>
         {/if}
         {#if needsRegenerate}
@@ -286,11 +302,11 @@
         {/if}
     {/if}
 
-    {#if errorMessage}
+    {#if renderStartError}
         <div class="mt-4" data-testid="render-start-error">
-            <Alert type="danger">
-                {errorMessage}
-                {#if showPurchaseLink}
+            <Alert type="danger" title="完成動画の生成を開始できませんでした">
+                {renderStartError.message}
+                {#if renderStartError.showPurchaseLink}
                     <span class="ml-1">
                         <TextLink href="/purchase-tickets" testId="render-purchase-link">
                             チケットを購入する
@@ -318,7 +334,7 @@
                 </div>
             {:else if failedPreviewJob}
                 <div data-testid="preview-error">
-                    <Alert type="danger">
+                    <Alert type="danger" title="プレビューの生成に失敗しました">
                         {failedPreviewJob.error ?? "プレビューの生成に失敗しました。"}
                     </Alert>
                 </div>
@@ -335,6 +351,20 @@
                     </div>
                 {/if}
             {/if}
+            {#if previewStartError}
+                <div data-testid="preview-start-error">
+                    <Alert type="danger" title="プレビューの生成を開始できませんでした">
+                        {previewStartError.message}
+                        {#if previewStartError.showPurchaseLink}
+                            <span class="ml-1">
+                                <TextLink href="/purchase-tickets" testId="preview-purchase-link">
+                                    チケットを購入する
+                                </TextLink>
+                            </span>
+                        {/if}
+                    </Alert>
+                </div>
+            {/if}
             {#if playbackId !== null && !previewInFlight}
                 <!-- svelte-ignore a11y_media_has_caption (プレビュー動画の字幕は焼き込み済み) -->
                 <video
diff --git a/resources/js/components/features/manual/ScenarioEditor.svelte b/resources/js/components/features/manual/ScenarioEditor.svelte
index 66c37fc..44fd7ab 100644
--- a/resources/js/components/features/manual/ScenarioEditor.svelte
+++ b/resources/js/components/features/manual/ScenarioEditor.svelte
@@ -1,7 +1,7 @@
 <script lang="ts">
     import { tick } from "svelte";
     import { router } from "@inertiajs/svelte";
-    import { ChevronDown, ChevronUp, ListPlus, Plus, Trash2 } from "@lucide/svelte";
+    import { Check, ChevronDown, ChevronUp, ListPlus, Plus, Trash2 } from "@lucide/svelte";
     import Alert from "@/components/atoms/Alert.svelte";
     import Button from "@/components/atoms/Button.svelte";
     import Card from "@/components/atoms/Card.svelte";
@@ -92,6 +92,9 @@
     // svelte-ignore state_referenced_locally
     let snapshot = $state(serializeSteps(toDraftSteps(scenario.steps)));
     let saving = $state(false);
+    // 直近の保存成功をその場に残す (toast の 4s 自動消去に依存しない永続確認)。
+    // true にするのは applySaved() のみ。reseed()・save 開始・失敗・dirty 転換で false。
+    let justSaved = $state(false);
     let errors = $state<Record<string, string[]>>({});
 
     /**
@@ -124,6 +127,12 @@
 
     const dirty = $derived(serializeSteps(steps) !== snapshot);
 
+    // 編集で dirty に転じたら成功確認を消す (level-triggered)。dirty は derived で決定的なため
+    // applySaved 直後は dirty=false のままで justSaved=true が保たれる。
+    $effect(() => {
+        if (dirty) justSaved = false;
+    });
+
     /** 新規行の空値 (scene のみ必須のため空で作る) */
     function emptyRow(shotType: "hiki" | "yori"): Omit<DraftPoint, "id"> {
         return {
@@ -222,6 +231,7 @@
      * (完全可視ならスクロールは原則発生せず、連続失敗時のジャンプを起こしにくい)。
      */
     async function showFailure(failure: SaveFailure): Promise<void> {
+        justSaved = false; // 失敗表示時は成功確認を消す
         saveFailure = failure;
         await tick();
         failureEl?.focus({ preventScroll: true });
@@ -233,6 +243,7 @@
         if (saving) return; // 多重送信ガード (disabled にはしない。押下は受けて即 return)
         saving = true;
         errors = {};
+        justSaved = false; // 再保存中は前回の成功確認を伏せる
         saveFailure = null; // 前回の失敗表示をクリア (再保存成功後に旧エラーを残さない)
         try {
             const res = await putScenario();
@@ -330,11 +341,13 @@
         steps = toDraftSteps(document.steps);
         snapshot = serializeSteps(steps);
         errors = {};
+        justSaved = false; // 409 競合/明示リロードの reseed で偽の成功表示を出さない
     }
 
     /** 成功応答の取り込み: 確定 id + version + スナップショット更新 + 成功トースト */
     function applySaved(document: ScenarioDocument): void {
         reseed(document);
+        justSaved = true; // 保存成功パスのみ (reseed の後)
         addToast("success", "シナリオを保存しました");
     }
 
@@ -754,6 +767,14 @@
             <span class="text-caption text-text-secondary" data-testid="scenario-dirty-indicator">
                 未保存の変更があります
             </span>
+        {:else if justSaved}
+            <span
+                class="flex items-center gap-1 text-caption text-success"
+                data-testid="scenario-saved-indicator"
+            >
+                <Check class="size-4" aria-hidden="true" />
+                保存しました
+            </span>
         {/if}
     </div>
 </section>
diff --git a/tests/js/components/features/manual/RenderPanel.test.ts b/tests/js/components/features/manual/RenderPanel.test.ts
index 09bd60a..bd3ba6a 100644
--- a/tests/js/components/features/manual/RenderPanel.test.ts
+++ b/tests/js/components/features/manual/RenderPanel.test.ts
@@ -137,6 +137,10 @@ describe("RenderPanel", () => {
                 "チケット残高が不足しています",
             );
         });
+        // T040: 起動失敗は完成動画への帰属を title で明示する
+        expect(screen.getByTestId("render-start-error")).toHaveTextContent(
+            "完成動画の生成を開始できませんでした",
+        );
         // 押下可能なまま (disabled にしない)
         expect(screen.getByTestId("render-button")).toBeInTheDocument();
         // T007: 残高不足 (code 厳格一致) では購入導線を併記する
@@ -184,6 +188,8 @@ describe("RenderPanel", () => {
         });
 
         expect(screen.getByTestId("render-error")).toHaveTextContent("書き出しに失敗しました");
+        // T040: ジョブ失敗も完成動画への帰属を title で明示する
+        expect(screen.getByTestId("render-error")).toHaveTextContent("完成動画の生成に失敗しました");
     });
 
     it("preview failed + scenario_version_changed は「作り直す」CTA を表示する", async () => {
@@ -201,6 +207,8 @@ describe("RenderPanel", () => {
         });
 
         expect(screen.getByTestId("preview-error")).toHaveTextContent("シナリオが変更された");
+        // T040: プレビューのジョブ失敗を title で帰属明示する
+        expect(screen.getByTestId("preview-error")).toHaveTextContent("プレビューの生成に失敗しました");
         fetchMock.mockResolvedValueOnce(
             jsonResponse(201, renderJobBody({ kind: "preview", manual_status: "ready" })),
         );
@@ -246,4 +254,109 @@ describe("RenderPanel", () => {
         expect(screen.queryByTestId("preview-button")).not.toBeInTheDocument();
         expect(screen.getByTestId("render-progress")).toBeInTheDocument();
     });
+
+    // --- T040 F-1-2: 起動失敗 alert の source+phase 帰属 ---
+
+    it("プレビュー起動 402 は preview-start-error に帰属し、完成動画欄には出さない", async () => {
+        fetchMock.mockResolvedValueOnce(
+            jsonResponse(402, {
+                code: "insufficient_tickets",
+                message: "チケット残高が不足しています (必要: 1 / 残高: 0)。",
+            }),
+        );
+
+        render(RenderPanel, { props: baseProps });
+        await fireEvent.click(screen.getByTestId("preview-button"));
+
+        await waitFor(() => {
+            expect(screen.getByTestId("preview-start-error")).toHaveTextContent(
+                "チケット残高が不足しています",
+            );
+        });
+        expect(screen.getByTestId("preview-start-error")).toHaveTextContent(
+            "プレビューの生成を開始できませんでした",
+        );
+        // プレビュー起動 402 の購入導線は preview 側に出る
+        expect(screen.getByTestId("preview-purchase-link")).toBeInTheDocument();
+        expect(
+            new URL(
+                (screen.getByTestId("preview-purchase-link") as HTMLAnchorElement).href,
+            ).pathname,
+        ).toBe("/purchase-tickets");
+        // 完成動画欄へ誤帰属しない
+        expect(screen.queryByTestId("render-start-error")).not.toBeInTheDocument();
+        expect(screen.queryByTestId("render-purchase-link")).not.toBeInTheDocument();
+    });
+
+    it("完成動画起動失敗とプレビュー起動失敗は別々に共存し、後発が先発を消さない", async () => {
+        render(RenderPanel, { props: baseProps });
+
+        // 完成動画起動を 422 で失敗させる
+        fetchMock.mockResolvedValueOnce(
+            jsonResponse(422, { message: "採用テイクが不足しています。" }),
+        );
+        await fireEvent.click(screen.getByTestId("render-button"));
+        await waitFor(() => {
+            expect(screen.getByTestId("render-dialog")).toBeInTheDocument();
+        });
+        await fireEvent.click(screen.getByText("生成する"));
+        await waitFor(() => {
+            expect(screen.getByTestId("render-start-error")).toHaveTextContent(
+                "採用テイクが不足しています",
+            );
+        });
+
+        // 続けてプレビュー起動を 422 で失敗させる
+        fetchMock.mockResolvedValueOnce(
+            jsonResponse(422, { message: "プレビューを開始できません。" }),
+        );
+        await fireEvent.click(screen.getByTestId("preview-button"));
+        await waitFor(() => {
+            expect(screen.getByTestId("preview-start-error")).toHaveTextContent(
+                "プレビューを開始できません",
+            );
+        });
+
+        // 両方が別 title で共存する (後発が先発を上書きしない)
+        expect(screen.getByTestId("render-start-error")).toHaveTextContent(
+            "完成動画の生成を開始できませんでした",
+        );
+        expect(screen.getByTestId("preview-start-error")).toHaveTextContent(
+            "プレビューの生成を開始できませんでした",
+        );
+    });
+
+    it("プレビューのジョブ失敗と完成動画の起動失敗が別 title で並ぶ", async () => {
+        render(RenderPanel, {
+            props: {
+                ...baseProps,
+                previewJob: renderJobBody({
+                    kind: "preview",
+                    status: "failed",
+                    error: "プレビュー合成に失敗しました。",
+                    error_code: "internal",
+                    manual_status: "ready",
+                }),
+            },
+        });
+
+        fetchMock.mockResolvedValueOnce(
+            jsonResponse(422, { message: "採用テイクが不足しています。" }),
+        );
+        await fireEvent.click(screen.getByTestId("render-button"));
+        await waitFor(() => {
+            expect(screen.getByTestId("render-dialog")).toBeInTheDocument();
+        });
+        await fireEvent.click(screen.getByText("生成する"));
+        await waitFor(() => {
+            expect(screen.getByTestId("render-start-error")).toBeInTheDocument();
+        });
+
+        expect(screen.getByTestId("preview-error")).toHaveTextContent(
+            "プレビューの生成に失敗しました",
+        );
+        expect(screen.getByTestId("render-start-error")).toHaveTextContent(
+            "完成動画の生成を開始できませんでした",
+        );
+    });
 });
diff --git a/tests/js/components/features/manual/ScenarioEditor.test.ts b/tests/js/components/features/manual/ScenarioEditor.test.ts
index 88ef1ed..d114d1c 100644
--- a/tests/js/components/features/manual/ScenarioEditor.test.ts
+++ b/tests/js/components/features/manual/ScenarioEditor.test.ts
@@ -748,6 +748,98 @@ describe("ScenarioEditor", () => {
         expect(screen.getByTestId("scenario-dirty-indicator")).toBeInTheDocument();
     });
 
+    // --- T040 F-1-1: 保存成功のその場残留インジケータ (justSaved) ---
+
+    it("保存成功後は「保存しました」インジケータを表示し dirty 表示は出さない", async () => {
+        fetchMock.mockResolvedValueOnce(jsonResponse(200, { ...makeDocument(), scenario_version: 4 }));
+
+        render(ScenarioEditor, { props: { ...baseProps, scenario: makeDocument() } });
+        await typeInto("step-0-scene", "手順シーンAX");
+        await fireEvent.click(screen.getByTestId("scenario-submit"));
+
+        await waitFor(() => {
+            expect(screen.getByTestId("scenario-saved-indicator")).toBeInTheDocument();
+        });
+        expect(screen.getByTestId("scenario-saved-indicator")).toHaveTextContent("保存しました");
+        expect(screen.queryByTestId("scenario-dirty-indicator")).not.toBeInTheDocument();
+    });
+
+    it("保存直後は dirty=false でも justSaved=true を維持する (意図せぬ消去が混入しない不変)", async () => {
+        // dirty 算出変更に対する回帰の砦: applySaved 後もインジケータが残ることを固定する
+        fetchMock.mockResolvedValueOnce(jsonResponse(200, { ...makeDocument(), scenario_version: 4 }));
+
+        render(ScenarioEditor, { props: { ...baseProps, scenario: makeDocument() } });
+        await fireEvent.click(screen.getByTestId("scenario-submit"));
+
+        await waitFor(() => {
+            expect(screen.getByTestId("scenario-saved-indicator")).toBeInTheDocument();
+        });
+        expect(screen.queryByTestId("scenario-dirty-indicator")).not.toBeInTheDocument();
+    });
+
+    it("保存成功後に編集で dirty に転じると保存インジケータが消え dirty 表示に切り替わる", async () => {
+        fetchMock.mockResolvedValueOnce(jsonResponse(200, { ...makeDocument(), scenario_version: 4 }));
+
+        render(ScenarioEditor, { props: { ...baseProps, scenario: makeDocument() } });
+        await fireEvent.click(screen.getByTestId("scenario-submit"));
+        await waitFor(() => {
+            expect(screen.getByTestId("scenario-saved-indicator")).toBeInTheDocument();
+        });
+
+        await typeInto("step-0-scene", "手順シーンAX");
+        await waitFor(() => {
+            expect(screen.getByTestId("scenario-dirty-indicator")).toBeInTheDocument();
+        });
+        expect(screen.queryByTestId("scenario-saved-indicator")).not.toBeInTheDocument();
+    });
+
+    it("409 競合後のサーバ最新取得 (reseed) では偽の保存インジケータを出さない", async () => {
+        fetchMock.mockResolvedValueOnce(
+            jsonResponse(409, {
+                code: "scenario_conflict",
+                conflict_type: "version_mismatch",
+                message: "他の編集と競合しました。",
+                current_version: 9,
+            }),
+        );
+
+        render(ScenarioEditor, { props: { ...baseProps, scenario: makeDocument() } });
+        await typeInto("step-0-scene", "手順シーンAX");
+        await fireEvent.click(screen.getByTestId("scenario-submit"));
+        await waitFor(() => {
+            expect(screen.getByTestId("scenario-conflict-reload")).toBeInTheDocument();
+        });
+        await fireEvent.click(screen.getByTestId("scenario-conflict-reload"));
+        await waitFor(() => {
+            expect(screen.getByRole("button", { name: "破棄して最新を取得" })).toBeInTheDocument();
+        });
+        await fireEvent.click(screen.getByRole("button", { name: "破棄して最新を取得" }));
+
+        const latest: ScenarioDocument = {
+            scenario_version: 9,
+            steps: [{ ...makeDocument().steps[0], scene: "サーバ最新シーン", points: [] }],
+        };
+        lastReloadOptions().onSuccess({ props: { scenario: latest } });
+        lastReloadOptions().onFinish();
+
+        await waitFor(() => {
+            expect(screen.getByTestId("step-0-scene")).toHaveValue("サーバ最新シーン");
+        });
+        expect(screen.queryByTestId("scenario-saved-indicator")).not.toBeInTheDocument();
+    });
+
+    it("保存失敗 (generic) では保存インジケータを出さない", async () => {
+        fetchMock.mockRejectedValueOnce(new TypeError("network error"));
+
+        render(ScenarioEditor, { props: { ...baseProps, scenario: makeDocument() } });
+        await fireEvent.click(screen.getByTestId("scenario-submit"));
+
+        await waitFor(() => {
+            expect(screen.getByTestId("scenario-generic-error")).toBeInTheDocument();
+        });
+        expect(screen.queryByTestId("scenario-saved-indicator")).not.toBeInTheDocument();
+    });
+
     it("保存成功で失敗リージョンが消える", async () => {
         fetchMock
             .mockResolvedValueOnce(jsonResponse(403, {}))

```

## テスト結果
- `pnpm typecheck`: green（tsc --noEmit エラーなし）
- `pnpm lint`: green（eslint resources/js エラーなし）
- `pnpm test --testTimeout=30000`: 全 72 files / 553 tests passed（うち manual feature 3 files 63 tests、新規 8 ケース追加）
- `pnpm build`: green
- PHP 変更なし（diff は resources/ tests/ のみ）→ composer 系は非該当
