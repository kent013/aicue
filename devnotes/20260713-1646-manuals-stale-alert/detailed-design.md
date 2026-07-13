# 詳細設計: manuals-stale-alert

bug-hunt finding **F-H2 (High, H10)** の修正。manuals show 画面で、解析起動失敗由来の
赤字 alert「手順書をアップロードしてください。」が SOP アップロード成功後も残留する stale local state を解消する。

## 使命・制約（絶対遵守）

### アプリの使命（North Star）

**AI-CUE** は、現場に既にある**作業手順書(SOP)を起点に**、AI が撮るべきカットを設計した**動画シナリオ**を生成し、
そのシナリオを**スマホ(PWA)でナビゲーション撮影**することで、専門知識ゼロの現場作業者でも
**標準化されたマニュアル動画**を作れるようにする。

- 「思考ゼロ・編集ゼロ」— 台本作成・撮影判断・編集の 3 ハードルを AI とナビ撮影で肩代わりする。
- 競合(tebiki)と異なり、**標準作業を起点に AI が教材設計し撮影を指示する**。
- 熟練者の暗黙知を動画マニュアルという形式知へ変換する装置（SECI）。
- v1: 字幕のみ / 撮影は PWA(同一オリジン・セッション認証) / 動画合成は自前 ffmpeg / 単一 Default Project。

### 禁止事項

1. テストなしの実装完了報告
2. PHPStan エラーの widen・baseline 化
3. dev DB への破壊操作をエージェント判断で実行
4. `response()->json()` の直書き(DTO / JsonResource / Inertia を使う。仕様固定 endpoint のみ例外)
5. LLM 呼び出しの Prism 直呼び
6. prompt 文字列のコード直書き
7. 操作系 POST の応答での `redirect()->intended()`
8. **必須条件未充足を理由にボタンを disabled にする UI(押下時にエラー表示する。DESIGN.md)**

### コーディングルール

- **PHPStan level 10** 必須（本 PR は frontend のみのため PHP 変更なし = 対象外だが既存 green 維持）
- **Pest**（本 PR は PHP 変更なしのため PHP テスト追加なし）／ フロントは **vitest**
- RefreshDatabase + `--parallel`（PHP テスト非追加のため該当なし）
- フロントは **Svelte 5 runes + DS token/ramp のみ**（DESIGN.md canonical、ds-purity テスト）
- component 階層は単方向 import のみ。アイコンは `@lucide/svelte` のみ
- コードフォーマット: `pnpm lint:fix`（ESLint）
- PHP 8.4 + Laravel 12 + Svelte 5 + Inertia.js + TypeScript

## 概念設計リファレンス

- `devnotes/20260712-... ` ではなく本ディレクトリの
  [`conceptual-design.md`](./conceptual-design.md)（Codex `gpt-5.4` レビュー Round 3 で APPROVED）。

### 根本原因（確定）

- 赤字 alert の実体は `AnalysisPanel.svelte` のローカル `$state` の `errorMessage`。
  文言は AI 解析起動 (`POST .../analyze`) の **422** 応答
  （`app/Services/Manual/AnalysisJobService.php:76`
  `ValidationException::withMessages(['document' => ['手順書をアップロードしてください。']])`）由来。
  フロントには `{ message, errors: { document: [...] } }` 形で届く。
- SOP アップロードは兄弟 `SourceDocumentUpload.svelte` の Inertia `form.post`。サーバは
  `SourceDocumentController::store` が `back()->with('success', ...)` を返し、Show ページを
  **同一コンポーネントのまま**新 props (`analysis.hasDocument: false→true`) で再描画する。
- Inertia は同一ページを**再マウントしない**ため、`AnalysisPanel` の `errorMessage` が
  seed のまま残留し、次に `startAnalyze()` を呼ぶまで消えない。

## 施策一覧

| # | 施策名 | 変更ファイル | 優先度 |
|---|--------|------------|--------|
| 1 | start error に種別 (`StartErrorKind`) を持たせ、`hasDocument: false→true` 遷移で「手順書なし(422)」overlay を破棄する | `resources/js/components/features/manual/AnalysisPanel.svelte` | High |
| 2 | 回帰テスト追加（overlay 破棄 / 種別ゲート / 非退行） | `tests/js/components/features/manual/AnalysisPanel.test.ts` | High |

> backend（Controller / Service / Request / DTO / JsonResource）と `SourceDocumentUpload.svelte`、
> 共有 TS 型（`resources/js/types/manual.ts`）は**変更不要**（下記「波及変更」参照）。

---

## 施策1: `AnalysisPanel.svelte` に start error 種別 + overlay 破棄 effect を追加

### 変更箇所
- ファイル: `resources/js/components/features/manual/AnalysisPanel.svelte`
  - state 宣言（現行 L33-44 付近）
  - `startAnalyze()` の冒頭リセット + 開始時 `hadDocumentAtStart` スナップショット（現行 L134-157）
  - `catch`（現行 L151-152）
  - `handleStartResponse()` シグネチャに `hadDocumentAtStart` 追加（現行 L159-175）
  - 新規 level-triggered `$effect`（overlay 破棄）と分類ヘルパー / 破棄述語の追加

### 波及変更
- **TypeScript 型定義**: `StartErrorKind` は当コンポーネント内ローカル `type`。共有 `types/manual.ts`
  への追加は不要（他コンポーネントで未使用。features/manual に閉じる。将来 RenderPanel 等で共有が必要に
  なったら昇格を検討）。→ **共有型変更なし**。
- **Props インターフェース**: `Props` 変更なし（新規 prop 追加なし）。→ **Show.svelte 変更なし**。
- **API Resource / DTO**: backend 変更なし。→ **なし**。
- **テストファイル**: `tests/js/components/features/manual/AnalysisPanel.test.ts` に施策2でケース追加。

### 現行コード（抜粋）

```svelte
// svelte-ignore state_referenced_locally
let currentJob = $state<AnalysisJobProps | null>(job);
// svelte-ignore state_referenced_locally
let status = $state<VideoManualStatus>(manualStatus);
let starting = $state(false);
let errorMessage = $state<string | null>(null);
// 402 (残高不足) のとき購入導線を併記する (code 厳格一致。他エラーで誤表示しない)
let showPurchaseLink = $state(false);
// セッション失効 (401/419) の案内。解析中表示の中で出す (ポーリングは停止する)
let sessionExpiredMessage = $state<string | null>(null);
let confirmingReanalyze = $state(false);
```

```svelte
    async function startAnalyze(): Promise<void> {
        if (starting) return; // 多重送信ガード (disabled にはしない)
        starting = true;
        errorMessage = null;
        showPurchaseLink = false;
        sessionExpiredMessage = null;
        try {
            const res = await fetch(/* ... */);
            await handleStartResponse(res);
        } catch {
            errorMessage = "通信に失敗しました。接続を確認して再度お試しください。";
        } finally {
            starting = false;
            confirmingReanalyze = false;
        }
    }

    async function handleStartResponse(res: Response): Promise<void> {
        const body = (await res.json().catch(() => null)) as unknown;
        showPurchaseLink = res.status === 402 && isInsufficientTickets(body);
        if (res.status === 201 && body !== null && typeof body === "object") {
            const jobBody = body as AnalysisJobProps;
            currentJob = jobBody;
            status = jobBody.manual_status;
            return;
        }
        // 402 (残高不足) / 409 (競合) / 422 (手順書なし) はサーバのメッセージをそのまま表示
        const message = extractMessage(body);
        if (message !== null) {
            errorMessage = message;
            return;
        }
        errorMessage = "解析を開始できませんでした。時間をおいて再度お試しください。";
    }
```

### 変更後コード

**(a) state 宣言に種別を追加**（`sessionExpiredMessage` の下あたり）:

```svelte
    /**
     * start error (解析起動 XHR の失敗) の種別。文言一致でなく種別で分岐することで、
     * i18n・文言変更に強く、「手順書なし(422)」だけを型安全に破棄できる。
     * - missing_document: 422 かつ errors.document 由来 (SOP 未アップロード)
     * - insufficient_tickets / conflict / generic: それ以外
     */
    type StartErrorKind = "missing_document" | "insufficient_tickets" | "conflict" | "generic";
    let startErrorKind = $state<StartErrorKind | null>(null);
```

**(b) overlay 破棄 effect を追加**（既存のポーリング `$effect` の後ろに配置）:

```svelte
    /* ---- stale overlay 破棄 (bug-hunt F-H2 再現対策) ----
     * 症状: 解析起動が 422「手順書をアップロードしてください。」で失敗して errorMessage が
     *   出た後、同一 Show 画面で SOP アップロードが成功しても (Inertia が Show を再描画し
     *   hasDocument: false→true) errorMessage が seed のまま残留する。
     * 対策 (level-triggered): 「手順書があれば解消される種別 (missing_document)」の start error が
     *   出ている状態で hasDocument が true になったら破棄する。missing_document かつ hasDocument=true は
     *   常に矛盾なので、edge (false→true 遷移) でなく level で判定する。これにより
     *   「422 表示 → upload」と「解析要求中に upload 完了 → 遅延 422 到達」の両順序を一様に扱える
     *   (Codex design-review R2 [Warning] 対応。previousHasDocument は不要)。
     * 注: currentJob/status は server-truth、sessionExpiredMessage は poll 系のため触らない。
     *   ポーリングは props を変えない (XHR でローカル state のみ更新) ので、この effect は
     *   ポーリング進行では発火せず、進捗表示・2.5 秒間隔は壊れない。
     */
    $effect(() => {
        if (hasDocument && isResolvedByDocumentUpload(startErrorKind)) {
            errorMessage = null;
            showPurchaseLink = false;
            startErrorKind = null;
        }
    });
```

**(c) 分類ヘルパー + 破棄述語を追加**（`extractMessage` の近く）:

```svelte
    /**
     * start error 種別を res.status / body / 解析開始時の hadDocumentAtStart から判定する (文言非依存)。
     * missing_document は「解析要求時に手順書が無かった (hadDocumentAtStart=false)」を条件に含める。
     * これにより:
     *  - 将来 document フィールド由来の別 422 (形式/容量。実際は upload endpoint 側で発生) を誤分類しない
     *    (Codex design-review R1 [Critical] 対応)。
     *  - 応答遅延中に hasDocument が true へ変わっても、要求時点の値で安定して分類できる
     *    (Codex design-review R2 [Warning] race 対応)。
     */
    function classifyStartError(
        status: number,
        body: unknown,
        hadDocumentAtStart: boolean,
    ): StartErrorKind {
        if (status === 402 && isInsufficientTickets(body)) return "insufficient_tickets";
        if (status === 409) return "conflict";
        if (status === 422 && !hadDocumentAtStart && hasDocumentValidationError(body)) {
            return "missing_document";
        }
        return "generic";
    }

    /** 422 body に errors.document (SOP 未アップロード) が含まれるか。
     *  bare 422 を一律 missing_document にしない (将来別用途の 422 と混同しないため)。 */
    function hasDocumentValidationError(body: unknown): boolean {
        if (body === null || typeof body !== "object") return false;
        const errors = (body as { errors?: unknown }).errors;
        if (errors === null || typeof errors !== "object") return false;
        const doc = (errors as { document?: unknown }).document;
        return Array.isArray(doc) && doc.length > 0;
    }

    /** hasDocument が満たされたとき自動破棄してよい start error 種別か (missing_document のみ) */
    function isResolvedByDocumentUpload(kind: StartErrorKind | null): boolean {
        return kind === "missing_document";
    }
```

**(d) `startAnalyze` リセット / catch / `handleStartResponse` で種別を設定**:

```svelte
    async function startAnalyze(): Promise<void> {
        if (starting) return;
        starting = true;
        errorMessage = null;
        showPurchaseLink = false;
        sessionExpiredMessage = null;
        startErrorKind = null; // 追加: 再送時に種別もリセット
        // 追加: 分類を要求時点に固定 (応答遅延中に hasDocument が変わっても安定分類)
        const hadDocumentAtStart = hasDocument;
        try {
            const res = await fetch(/* ... 変更なし ... */);
            await handleStartResponse(res, hadDocumentAtStart);
        } catch {
            errorMessage = "通信に失敗しました。接続を確認して再度お試しください。";
            startErrorKind = "generic"; // 追加
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
            startErrorKind = null; // 追加: 成功時は種別もクリア (自己記述的)
            return;
        }
        // 402 / 409 / 422 は種別を記録しつつサーバのメッセージをそのまま表示
        startErrorKind = classifyStartError(res.status, body, hadDocumentAtStart); // 追加
        errorMessage =
            extractMessage(body) ?? "解析を開始できませんでした。時間をおいて再度お試しください。";
    }
```

### PHPStan適合チェック
- [x] frontend 専用変更のため PHP 変更なし（PHPStan level 10 は現状維持で影響なし）

### TypeScript / lint 適合チェック
- [ ] `StartErrorKind` は明示 union、`startErrorKind` は `$state<StartErrorKind | null>(null)` と初期型明示
- [ ] `classifyStartError` / `hasDocumentValidationError` の戻り値型を明示（`StartErrorKind` / `boolean`）
- [ ] `body` は `unknown` を narrowing（既存 `extractMessage` と同様、`any` を使わない）
- [ ] 破棄 effect は **level-triggered**（`hasDocument && startErrorKind === "missing_document"`）。
      反応的依存は `hasDocument`（prop）と `startErrorKind`。無限ループしないこと
      （破棄後 `startErrorKind = null` で条件が偽になり再発火しても no-op に収束）。
      前回値追跡（`previousHasDocument`）は不要
- [ ] `pnpm typecheck` / `pnpm lint` green

### テスト計画（施策2で実装）
- [x] バグ修正の再現テストを追加（下記 施策2）
- [ ] 既存 `AnalysisPanel.test.ts` の全ケースが green のまま（非退行）
- [ ] `DatabaseTransactions` 等 PHP テスト規約は該当なし（frontend）

### リスク
- `$effect` は `hasDocument` と `startErrorKind` を反応的依存に含み、いずれか変化で再評価される。
  破棄条件 `hasDocument && missing_document` は成立時に `startErrorKind=null` して自ら偽になるため、
  再発火しても no-op に収束（無限ループなし）。
- level-triggered のため、`missing_document` の start error が出ている間に `hasDocument` が true に
  なれば順序に関わらず破棄される（「422→upload」「解析中に upload 完了→遅延422」の両方をカバー）。
  `missing_document && hasDocument=true` は常に矛盾状態なので、破棄が誤動作になるケースはない。
- `currentJob`/`status`/`sessionExpiredMessage` を触らないため、ポーリング・failedJob 表示・
  session 失効案内は非退行。

---

## 施策2: `AnalysisPanel.test.ts` に回帰テストを追加

### 変更箇所
- ファイル: `tests/js/components/features/manual/AnalysisPanel.test.ts`（`describe("AnalysisPanel", ...)` 内に追加）

### 波及変更
- なし（テストファイルのみ）。

### テスト手法
- 既存テストは `@testing-library/svelte` の `render(AnalysisPanel, { props })` を使用。
  props 更新は同ライブラリ v5 (`^5.3.1`) の **`rerender`** で行う
  （`const { rerender } = render(...); await rerender({ ...newProps })` が `$props` を更新する）。
- 422 応答は既存テストと同形 `{ message, errors: { document: [...] } }` を fetch mock で返す。

### 追加テストケース

1. **「422 表示 → `hasDocument: false→true` の rerender で start-error alert が消える (購入リンクも出ない)」**
   （本 finding の再現 + 非干渉固定）
   ```ts
   it("SOP アップロード成功 (hasDocument false→true) で 422 の残留 alert が消える", async () => {
       fetchMock.mockResolvedValue(
           jsonResponse(422, {
               message: "手順書をアップロードしてください。",
               errors: { document: ["手順書をアップロードしてください。"] },
           }),
       );
       const { rerender } = render(AnalysisPanel, { props: { ...baseProps, hasDocument: false } });
       await fireEvent.click(screen.getByTestId("analyze-button"));
       await waitFor(() =>
           expect(screen.getByTestId("analysis-start-error")).toHaveTextContent(
               "手順書をアップロードしてください",
           ),
       );
       // SOP アップロード成功 = Inertia が hasDocument=true で Show を再描画
       await rerender({ ...baseProps, hasDocument: true });
       await waitFor(() =>
           expect(screen.queryByTestId("analysis-start-error")).toBeNull(),
       );
       // 非干渉: 購入リンク等の他 overlay を巻き込んで表示していない
       expect(screen.queryByTestId("analysis-purchase-link")).toBeNull();
   });
   ```

2. **「種別ゲート: `missing_document` 以外 (402) は消えない」**（誤破棄防止・実運用前提）
   - 402 は **hasDocument=true 文脈**で発生する。初期 props を `hasDocument: true` で開始し、
     `rerender` は `manualStatus` 等のみ変更して start-error alert が**残る**ことを検証
     （`false→true` 遷移が起きず、かつ種別も missing_document でないため破棄されない。
     強化後の分類 `!hasDocument` 条件とも整合）。
   ```ts
   it("402 (残高不足) は他 props 更新後も消えない (missing_document 以外は保持)", async () => {
       fetchMock.mockResolvedValue(
           jsonResponse(402, { code: "insufficient_tickets", message: "チケット残高が不足しています。" }),
       );
       // 402 は手順書ありの文脈で発生する → hasDocument:true 開始 (baseProps 既定)
       const { rerender } = render(AnalysisPanel, { props: baseProps });
       await fireEvent.click(screen.getByTestId("analyze-button"));
       await waitFor(() =>
           expect(screen.getByTestId("analysis-start-error")).toHaveTextContent("チケット残高が不足"),
       );
       // 別 props (manualStatus) が更新されても start error は保持される
       await rerender({ ...baseProps, manualStatus: "ready" as const });
       expect(screen.getByTestId("analysis-start-error")).toBeInTheDocument();
   });
   ```

3. **「非退行: start-error のみ破棄、failedJob (server-truth) は維持される」**
   - `job` = failed で render → `analysis-error` 表示。`hasDocument` を false→true に rerender しても
     `analysis-error`（server-truth 由来）が**残る**ことを確認（本変更は `currentJob`/failedJob を触らない）。

4. **「競合順序: 解析要求中に upload 完了 (hasDocument false→true) → 遅延 422 でも alert が残らない」**
   （Codex R2 [Warning] race 対応）
   ```ts
   it("解析要求中に hasDocument が true になり、遅延 422 が来ても alert は残らない", async () => {
       let resolveFetch!: (r: Response) => void;
       fetchMock.mockReturnValue(new Promise<Response>((r) => { resolveFetch = r; }));
       const { rerender } = render(AnalysisPanel, { props: { ...baseProps, hasDocument: false } });
       await fireEvent.click(screen.getByTestId("analyze-button"));
       // 応答が返る前に SOP アップロード完了 → Inertia が hasDocument=true で再描画
       await rerender({ ...baseProps, hasDocument: true });
       // 遅延していた 422 がここで解決 (分類は hadDocumentAtStart=false → missing_document)
       resolveFetch(
           jsonResponse(422, {
               message: "手順書をアップロードしてください。",
               errors: { document: ["手順書をアップロードしてください。"] },
           }),
       );
       // level-triggered effect が hasDocument=true && missing_document を検知して即破棄
       await waitFor(() => expect(screen.queryByTestId("analysis-start-error")).toBeNull());
   });
   ```

> **遷移/順序ケースの網羅** (Codex R1/R2 [Warning] 対応): ケース1 が `false→true` 通常順、
> ケース4 が upload→遅延422 の競合順、ケース3 が failedJob 非破棄、ケース2 とベース群
> (初回 `true` / `true→ready`) で「初回 true では破棄対象エラーが出ない」「true 系変化で
> missing_document 以外は破棄しない」を固定する。

### PHPStan適合チェック
- [x] PHP テスト非該当（frontend vitest）

### テスト計画
- [ ] 上記 3 ケース追加、`pnpm test`（vitest）で green
- [ ] 既存 11 ケース非退行

### リスク
- `rerender` の props マージ挙動（v5 は渡した props で `$props` を更新）に依存。既存テストが
  render のみだったため、`rerender` の API 前提を実装時に一度確認する
  （代替: `hasDocument` を持つ薄いラッパー Svelte コンポーネントで bind する）。

---

## 実装後の検証コマンド

```bash
pnpm lint          # ESLint
pnpm typecheck     # tsc --noEmit
pnpm test          # vitest (AnalysisPanel.test.ts 含む)
pnpm build         # vite build
```

PHP 側変更なしのため `composer phpstan` / `composer test` は現状維持（回さないが壊さない）。

## 実装モード

| 項目 | 内容 |
|------|------|
| 推奨モード | **incremental** |
| 判断根拠 | 既存 `AnalysisPanel.svelte` / `AnalysisPanel.test.ts` への局所的追記のみ。新規ファイル・新規モデル・DB 変更なし。単一ファイルの振る舞い追加でリスク小。 |
| 競合リスク | 低。`AnalysisPanel.svelte` を同時に触る他施策がなければ衝突しない。backend・共有型・Show.svelte に触れないため波及も局所。 |

## 使命・禁止事項 最終チェック
- 使命寄与: SOP → シナリオ → 撮影の中核導線で、成功操作後の誤エラー表示を除去し操作の詰まりを解消（○）。
- 禁止事項#1（テストなし禁止）: 再現テスト＋非退行テストを施策2で必須化（○）。
- 禁止事項#4（`response()->json()` 直書き）: backend 変更なし（該当なし）。
- 禁止事項#8（disabled 禁止）: overlay 破棄のみでボタン disabled 制御に触れない（○）。
- DS/Atomic: 既存 `Alert` atom のみ。新規 hex/SVG/コンポーネントなし（○）。
- PHPStan/型: PHP 変更なし。TS は union 明示・`unknown` narrowing で `any` 不使用（○）。
