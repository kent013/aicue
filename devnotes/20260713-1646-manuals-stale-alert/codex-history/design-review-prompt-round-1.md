# アプリの使命 (North Star) — AGENTS.md より

**AI-CUE** は、現場に既にある**作業手順書(SOP)を起点に**、AI が撮るべきカットを設計した**動画シナリオ**を生成し、そのシナリオを**スマホ(PWA)でナビゲーション撮影**することで、専門知識ゼロの現場作業者でも**標準化されたマニュアル動画**を作れるようにする。「思考ゼロ・編集ゼロ」。v1: 字幕のみ / 撮影は PWA(同一オリジン・セッション認証) / 動画合成は自前 ffmpeg / 単一 Default Project。

# 禁止事項 — AGENTS.md より

1. テストなしの実装完了報告
2. PHPStan エラーの widen・baseline 化
3. dev DB への破壊操作をエージェント判断で実行
4. `response()->json()` の直書き(DTO / JsonResource / Inertia を使う。仕様固定 endpoint のみ例外)
5. LLM 呼び出しの Prism 直呼び
6. prompt 文字列のコード直書き
7. 操作系 POST の応答での `redirect()->intended()`
8. 必須条件未充足を理由にボタンを disabled にする UI(押下時にエラー表示する。DESIGN.md)

【思考原則】まず仮説を立てろ。データに真摯に。先人の知恵 (Laravel/Svelte 公式作法) を探せ。機能の名前に立ち返れ。仕組みが機能していない段階で値を弄るな。
【ツール使用制限】コマンド実行・ファイル書き込みは一切行わず、提供テキストの分析に集中 (ファイル読み込みは許可)。

---

あなたは経験豊富なWebアプリケーションアーキテクトです。Laravel + Svelte アプリ改善の詳細設計をレビューしてください。

【前提環境】PHP 8.4 + Laravel 12 + Svelte 5 (runes) + Inertia.js + TypeScript / PHPStan level 10 / Pest / vitest / DTO + JsonResource / Laratrust RBAC。

【レビュー観点】
1. コードの正確性（ロジックエラー、エッジケース、null安全性）
2. 既存コードとの整合性（命名規約、パターン、API）
3. PHPStan level 10 適合性（本 PR は frontend のみ）
4. テスト計画の網羅性（vitest。回帰固定テスト）
5. DTO/JsonResource パターンの遵守（backend 変更有無の妥当性）
6. Inertia Props vs API Response の使い分け
7. 副作用・後退リスク（特に既存ポーリング $effect / failedJob 表示との干渉）
8. 波及変更の網羅性（TypeScript型定義、Props、テストが変更対象に含まれているか）
9. セキュリティ（本変更に認可・入力の観点で影響がないか）
10. DESIGN.md 準拠（DS token / hex 直書き増やさない）
11. Atomic Design 準拠（atoms→...→pages の単方向 import、Lucide アイコン）
12. Svelte 5 runes の正しさ（$effect の依存追跡・無限ループ・非リアクティブ変数の扱い、rerender テストの妥当性）

【出力形式】
- 各施策ごとに判定: APPROVE / REQUEST_CHANGES
- 指摘は [Critical] [Warning] [Suggestion] で分類
- Critical/Warning には必ず修正案を添える
- 全体判定: APPROVED / CHANGES_REQUESTED
- 日本語で出力

（レビュアーへ: リポジトリ内の以下ファイルを直接読んで文脈確認して構いません。
- resources/js/components/features/manual/AnalysisPanel.svelte
- resources/js/components/features/manual/SourceDocumentUpload.svelte
- resources/js/pages/Manuals/Show.svelte
- tests/js/components/features/manual/AnalysisPanel.test.ts
- resources/js/components/features/manual/insufficient-tickets.ts
- resources/js/types/manual.ts
- app/Http/Controllers/Projects/SourceDocumentController.php
- app/Services/Manual/AnalysisJobService.php (L76: 422 の throw)）

---

## 詳細設計書

（以下、detailed-design.md 全文）

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
  - `startAnalyze()` の冒頭リセット（現行 L137-139）
  - `catch`（現行 L151-152）
  - `handleStartResponse()`（現行 L159-175）
  - 新規 `$effect`（overlay 破棄）と分類ヘルパー関数の追加

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
     * 対策: hasDocument が false→true に遷移し、かつ start error が「手順書なし」種別なら、
     *   前提 (手順書なし) が解消されたので transient overlay を破棄する。
     * 注: currentJob/status は server-truth、sessionExpiredMessage は poll 系のため触らない。
     *   ポーリングは props を変えない (XHR でローカル state のみ更新) ので、解析進行中に
     *   この effect は発火せず、進捗表示・2.5 秒間隔は壊れない。
     */
    let hadDocument = hasDocument; // 非リアクティブ: 前回の hasDocument (遷移検出用)
    $effect(() => {
        const nowHasDocument = hasDocument;
        const wasHasDocument = hadDocument;
        hadDocument = nowHasDocument;
        if (!wasHasDocument && nowHasDocument && startErrorKind === "missing_document") {
            errorMessage = null;
            showPurchaseLink = false;
            startErrorKind = null;
        }
    });
```

**(c) 分類ヘルパーを追加**（`extractMessage` の近く）:

```svelte
    /** start error 種別を res.status / body から判定する (文言非依存) */
    function classifyStartError(status: number, body: unknown): StartErrorKind {
        if (status === 402 && isInsufficientTickets(body)) return "insufficient_tickets";
        if (status === 409) return "conflict";
        if (status === 422 && hasDocumentValidationError(body)) return "missing_document";
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
        try {
            const res = await fetch(/* ... 変更なし ... */);
            await handleStartResponse(res);
        } catch {
            errorMessage = "通信に失敗しました。接続を確認して再度お試しください。";
            startErrorKind = "generic"; // 追加
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
        // 402 / 409 / 422 は種別を記録しつつサーバのメッセージをそのまま表示
        startErrorKind = classifyStartError(res.status, body); // 追加
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
- [ ] `hadDocument` は非リアクティブな plain `let`（$state ではない）＝ effect 内で読む前回値。
      effect の反応的依存は `hasDocument`（prop）と `startErrorKind`。無限ループしないこと
      （破棄分岐は `!was && now` 遷移時のみ実行され、実行後 `hadDocument` は true 更新済み）
- [ ] `pnpm typecheck` / `pnpm lint` green

### テスト計画（施策2で実装）
- [x] バグ修正の再現テストを追加（下記 施策2）
- [ ] 既存 `AnalysisPanel.test.ts` の全ケースが green のまま（非退行）
- [ ] `DatabaseTransactions` 等 PHP テスト規約は該当なし（frontend）

### リスク
- `$effect` が `startErrorKind` を反応的依存に含むため、種別変化のたびに再評価される。
  ただし破棄分岐は `hasDocument` の `false→true` 遷移時のみ通るため副作用は限定的（無限ループなし）。
- Inertia が `hasDocument` を「同値」で再送しても Svelte は値変化なしとして effect を発火させない。
  本 finding の再現契機は必ず `false→true` の値変化を伴う（未アップロード→アップロード）ため問題ない。
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

1. **「422 表示 → `hasDocument: false→true` の rerender で start-error alert が消える」**（本 finding の再現）
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
   });
   ```

2. **「種別ゲート: `missing_document` 以外は `hasDocument` 遷移で消えない」**（誤破棄防止）
   - 402 (`code: "insufficient_tickets"`) を表示 → `hasDocument` を（同値で）true のまま or
     false→true にしても start-error alert が**残る**ことを検証。
   - 402 は hasDocument=true 前提で発生し得るため、`baseProps`（hasDocument:true）で 402 を出し、
     `rerender` で他 props（例: `manualStatus`）を変えても alert が残ることを確認する
     （false→true 遷移が起きないので破棄されない、を明示）。
   ```ts
   it("402 (残高不足) は hasDocument 遷移では消えない (種別ゲート)", async () => {
       fetchMock.mockResolvedValue(
           jsonResponse(402, { code: "insufficient_tickets", message: "チケット残高が不足しています。" }),
       );
       const { rerender } = render(AnalysisPanel, { props: { ...baseProps, hasDocument: false } });
       // 注: 402 は本来 hasDocument=true で発生するが、テストはクライアント側の破棄ゲートの
       // 種別判定を検証する。hasDocument false→true 遷移でも missing_document でないので残る。
       await fireEvent.click(screen.getByTestId("analyze-button"));
       await waitFor(() =>
           expect(screen.getByTestId("analysis-start-error")).toHaveTextContent("チケット残高が不足"),
       );
       await rerender({ ...baseProps, hasDocument: true });
       // 種別が insufficient_tickets のため破棄されない
       expect(screen.getByTestId("analysis-start-error")).toBeInTheDocument();
   });
   ```

3. **「非退行: failedJob 表示は rerender で維持される」**
   - `job` = failed で render → `analysis-error` 表示。`hasDocument` を false→true に rerender しても
     `analysis-error`（server-truth 由来）が**残る**ことを確認（本変更は failedJob を触らない）。

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


---

## 関連する現行コード（抜粋）

### resources/js/components/features/manual/AnalysisPanel.svelte (script 部 L1-183)

<script lang="ts">
    import { router } from "@inertiajs/svelte";
    import { LoaderCircle, Sparkles } from "@lucide/svelte";
    import Alert from "@/components/atoms/Alert.svelte";
    import Button from "@/components/atoms/Button.svelte";
    import Card from "@/components/atoms/Card.svelte";
    import ConfirmDialog from "@/components/organisms/ConfirmDialog.svelte";
    import TextLink from "@/components/atoms/TextLink.svelte";
    import { isInsufficientTickets } from "@/components/features/manual/insufficient-tickets";
    import { csrfToken } from "@/lib/csrf";
    import type { AnalysisJobProps, VideoManualStatus } from "@/types/manual";
    import { ANALYSIS_STEP_LABELS } from "@/types/manual";

    /**
     * AI 解析パネル (起動・進捗ポーリング・エラー表示)。doc/10 §10.3 / 概念設計 §8。
     * - 起動は POST .../analyze (XHR)。402/409/422 は押下時にサーバのメッセージを表示
     *   (必須未充足でもボタンは disabled にしない = DESIGN.md)
     * - analyzing 中は GET .../jobs/{id} を 2.5 秒間隔でポーリング。
     *   succeeded → router.reload() (ready 反映)、failed → エラー + 再実行導線
     * - ready からの再解析は「既存シナリオが置き換えられます」確認ダイアログを挟む
     */
    interface Props {
        projectId: number;
        manualId: number;
        manualStatus: VideoManualStatus;
        job: AnalysisJobProps | null;
        hasDocument: boolean;
        canManage: boolean;
    }

    let { projectId, manualId, manualStatus, job, hasDocument, canManage }: Props = $props();

    // 作業状態 (props から一度だけ seed し、以後は XHR 応答で更新する)
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

    const analyzing = $derived(
        status === "analyzing" ||
            currentJob?.status === "queued" ||
            currentJob?.status === "running",
    );
    const failedJob = $derived(currentJob?.status === "failed" ? currentJob : null);
    const stepLabel = $derived(
        currentJob?.step ? ANALYSIS_STEP_LABELS[currentJob.step] : "解析を待機中",
    );
    // ポーリング対象の job id。effect の依存を id に狭めることで、running/queued の
    // 各応答で currentJob を更新しても同一 id なら effect が再購読されず、
    // setInterval の 2.5 秒間隔が保たれる (terminal 遷移で analyzing=false → null で停止)
    const pollJobId = $derived(analyzing && currentJob !== null ? currentJob.id : null);

    /* ---- ポーリング (analyzing 中のみ。cleanup で必ず破棄) ---- */
    $effect(() => {
        // この effect の反応的依存は pollJobId のみ (currentJob 本体は読まない)
        const jobId = pollJobId;
        if (jobId === null) return;

        let stopped = false;
        let interval: ReturnType<typeof setInterval> | null = null;

        const poll = async (): Promise<void> => {
            if (stopped || document.hidden) return;
            try {
                const res = await fetch(
                    `/projects/${projectId}/manuals/${manualId}/jobs/${jobId}`,
                    {
                        headers: {
                            Accept: "application/json",
                            "X-Requested-With": "XMLHttpRequest",
                        },
                        credentials: "same-origin",
                    },
                );
                if (res.status === 401 || res.status === 419) {
                    // セッション失効は再試行しても回復しない → 停止して再読み込みを案内
                    // (解析はサーバ側で継続する。再読み込み後のログインで進捗表示に復帰できる)
                    stop();
                    sessionExpiredMessage =
                        "セッションの有効期限が切れました。ページを再読み込みしてください (解析はサーバで継続しています)。";
                    return;
                }
                if (!res.ok) return; // 一時失敗は次周期に任せる
                const body = (await res.json().catch(() => null)) as AnalysisJobProps | null;
                if (body === null || typeof body.status !== "string") return;
                if (stopped) return;
                currentJob = body;
                status = body.manual_status;
                if (body.status === "succeeded") {
                    stop();
                    router.reload();
                }
            } catch {
                // ネットワーク断は次周期に任せる
            }
        };

        const stop = (): void => {
            stopped = true;
            if (interval !== null) clearInterval(interval);
            interval = null;
        };

        // バックグラウンドタブの無駄打ちを避ける (再表示で即時 1 回 fetch)
        const onVisibilityChange = (): void => {
            if (!document.hidden) void poll();
        };
        document.addEventListener("visibilitychange", onVisibilityChange);
        interval = setInterval(() => void poll(), 2500);
        void poll();

        return () => {
            stop();
            document.removeEventListener("visibilitychange", onVisibilityChange);
        };
    });

    /* ---- 起動 ---- */
    function requestAnalyze(): void {
        if (status === "ready") {
            confirmingReanalyze = true;
            return;
        }
        void startAnalyze();
    }

    async function startAnalyze(): Promise<void> {
        if (starting) return; // 多重送信ガード (disabled にはしない)
        starting = true;
        errorMessage = null;
        showPurchaseLink = false;
        sessionExpiredMessage = null;
        try {
            const res = await fetch(`/projects/${projectId}/manuals/${manualId}/analyze`, {
                method: "POST",
                headers: {
                    Accept: "application/json",
                    "X-XSRF-TOKEN": csrfToken(),
                    "X-Requested-With": "XMLHttpRequest",
                },
                credentials: "same-origin",
            });
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

    /** 402/409 の { message } と 422 の { message, errors } からユーザー向け文言を取り出す */
    function extractMessage(body: unknown): string | null {
        if (body === null || typeof body !== "object") return null;
        const message = (body as { message?: unknown }).message;
        return typeof message === "string" && message !== "" ? message : null;
    }
</script>


### tests/js/components/features/manual/AnalysisPanel.test.ts (先頭 L1-56)

import { afterEach, beforeEach, describe, expect, it, vi } from "vitest";
import { cleanup, fireEvent, render, screen, waitFor } from "@testing-library/svelte";
import AnalysisPanel from "@/components/features/manual/AnalysisPanel.svelte";
import type { AnalysisJobProps } from "@/types/manual";

// router.reload はテスト環境では実行できないためモックする
const { routerReloadMock } = vi.hoisted(() => ({
    routerReloadMock: vi.fn(),
}));

// Link (TextLink 経由) は実物を使い、router のみ差し替える
vi.mock("@inertiajs/svelte", async (importOriginal) => ({
    ...(await importOriginal<typeof import("@inertiajs/svelte")>()),
    router: {
        reload: routerReloadMock,
    },
}));

/*
 * AI 解析パネル:
 * - draft + document で解析ボタン → POST /analyze (fetch)
 * - 402/422 はサーバの message を表示 (ボタンは disabled にしない)
 * - analyzing 中は進捗 + step ラベル
 * - failed はエラー表示 + 再実行可能
 */

const fetchMock = vi.fn();

const baseProps = {
    projectId: 1,
    manualId: 5,
    manualStatus: "draft" as const,
    job: null,
    hasDocument: true,
    canManage: true,
};

function jsonResponse(status: number, body: unknown): Response {
    return new Response(JSON.stringify(body), {
        status,
        headers: { "Content-Type": "application/json" },
    });
}

beforeEach(() => {
    vi.stubGlobal("fetch", fetchMock);
    document.cookie = "XSRF-TOKEN=test-token";
});

afterEach(() => {
    cleanup();
    fetchMock.mockReset();
    routerReloadMock.mockReset();
    vi.unstubAllGlobals();
});


