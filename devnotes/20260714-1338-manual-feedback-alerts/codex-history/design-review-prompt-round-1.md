# アプリの使命・禁止事項・思考原則（AGENTS.md 正本より）

## 使命 (North Star)
**AI-CUE** は、現場に既にある**作業手順書(SOP)を起点に**、AI が撮るべきカットを設計した**動画シナリオ**を生成し、そのシナリオを**スマホ(PWA)でナビゲーション撮影**することで、専門知識ゼロの現場作業者でも**標準化されたマニュアル動画**を作れるようにする。「思考ゼロ・編集ゼロ」を実現する。

## 禁止事項
1. テストなしの実装完了報告
2. PHPStan エラーの widen・baseline 化
3. dev DB への破壊操作をエージェント判断で実行
4. `response()->json()` の直書き（DTO / JsonResource / Inertia を使う）
5. LLM 呼び出しの Prism 直呼び
6. prompt 文字列のコード直書き
7. 操作系 POST 応答での `redirect()->intended()`
8. 必須条件未充足を理由にボタンを disabled にする UI（DESIGN.md）

## 思考原則
まず仮説を立てろ。データに真摯に向き合え。先人の知恵を探せ。機能の名前に立ち返れ。仕組みが機能していない段階で値を弄るな。

## ツール使用制限
コマンド実行・ファイル書き込みは一切行わず、提供されたテキストの分析に集中すること。ファイル読み込みは許可。

---

# system: 詳細設計レビュアー

あなたは経験豊富な Web アプリケーションアーキテクトです。Laravel + Svelte アプリの詳細設計をレビューしてください。

【前提環境】PHP 8.4 / Laravel 12 / Svelte 5 runes / Inertia.js / TypeScript strict / PHPStan level 10 / Pest / DTO+JsonResource / Laratrust RBAC。

【レビュー観点】
1. コードの正確性（ロジックエラー、エッジケース、null 安全性、Svelte 5 runes の反応性）
2. 既存コードとの整合性（命名規約、パターン、testId 互換）
3. PHPStan level 10 適合性（本件は PHP 変更なし。TS strict 適合を見る）
4. テスト計画の網羅性（各施策に vitest。既存テストの回帰）
5. DTO/JsonResource パターンの遵守（本件は backend 非変更の妥当性）
6. Inertia Props vs XHR/JSON の使い分け（scenario.update の 409/XHR 契約維持）
7. 副作用・後退リスク（既存 testId の破壊、状態遷移の穴）
8. 波及変更の網羅性（型定義・テストが変更対象に含まれるか）
9. セキュリティ（本件は表示層のみだが認可/入力の後退が無いか）
10. DESIGN.md 準拠（DS token 経由か、hex 直書きを増やさないか。Alert/Toast の既存 atom 流用か）
11. Atomic Design 準拠（feature 内で完結、単方向 import、アイコンは Lucide か）

【本件の要点】
- bug-hunt F-1-1/F-1-2。Claude が実コードを読み brief を再定義済み（F-1-1 の toast は既存・test 緑。真因は
  4s 自動消去 + その場確認欠如）。概念設計は Codex gpt-5.4 で APPROVED（justSaved 状態遷移の穴も是正済み）。
- 施策1: ScenarioEditor に保存成功の永続インジケータ（justSaved）。施策2: RenderPanel の起動失敗 state を
  source 別 2 state に分離 + 全 danger Alert に phase-aware title。

【出力形式】
- 各施策ごとに判定: APPROVE / REQUEST_CHANGES
- 指摘は [Critical] [Warning] [Suggestion] で分類、Critical/Warning に修正案必須
- 全体判定: APPROVED / CHANGES_REQUESTED
- 日本語で出力

---

# user: 詳細設計書 + 関連現行コード

現行コードは同リポジトリの以下を直接読んでよい（抜粋は設計書に含む）:
`resources/js/components/features/manual/ScenarioEditor.svelte`,
`resources/js/components/features/manual/RenderPanel.svelte`,
`resources/js/components/atoms/Alert.svelte`,
`resources/js/lib/stores/toast.ts`,
`tests/js/components/features/manual/ScenarioEditor.test.ts`,
`tests/js/components/features/manual/RenderPanel.test.ts`.

## 詳細設計書

# 詳細設計: manual-feedback-alerts

bug-hunt (real-llm run 20260714-093524) F-1-1 (Medium) / F-1-2 (Medium)。S3 manual 画面の
フィードバック一貫性。frontend 2 コンポーネントに閉じる incremental 改修。

## 使命・制約（絶対遵守）

### アプリの使命（North Star）
**AI-CUE** は、現場に既にある**作業手順書(SOP)を起点に**、AI が撮るべきカットを設計した**動画シナリオ**を
生成し、そのシナリオを**スマホ(PWA)でナビゲーション撮影**することで、専門知識ゼロの現場作業者でも
**標準化されたマニュアル動画**を作れるようにする。
- 「思考ゼロ・編集ゼロ」— 台本作成・撮影判断・編集の 3 ハードルを AI とナビ撮影で肩代わりする。
- 競合(tebiki)と異なり、**標準作業を起点に AI が教材設計し撮影を指示する**。
- 熟練者の暗黙知を動画マニュアルという形式知へ変換する装置（SECI）。

### 禁止事項
1. テストなしの実装完了報告
2. PHPStan エラーの widen・baseline 化
3. dev DB への破壊操作をエージェント判断で実行
4. `response()->json()` の直書き（DTO / JsonResource / Inertia を使う）
5. LLM 呼び出しの Prism 直呼び
6. prompt 文字列のコード直書き
7. 操作系 POST 応答での `redirect()->intended()`
8. 必須条件未充足を理由にボタンを disabled にする UI（押下時にエラー表示する。DESIGN.md）

### コーディングルール
- PHPStan level 10 / Pest / RefreshDatabase + `--parallel`（本件は PHP 変更なし）
- フロント: Svelte 5 runes + DS token/ramp のみ（DESIGN.md canonical, ds-purity テスト）。
  アイコンは `@lucide/svelte` のみ。component 階層は単方向 import（feature 内で完結）。
- 検証: `pnpm typecheck` / `pnpm lint` / `pnpm test` / `pnpm build`（全 green）。

## 概念設計リファレンス
`devnotes/20260714-1338-manual-feedback-alerts/conceptual-design.md`（Codex gpt-5.4 で APPROVED, Round 3）

## 施策一覧

| # | 施策名 | 変更ファイル | 優先度 |
|---|--------|------------|--------|
| 1 | F-1-1: シナリオ保存成功のその場残留インジケータ | `resources/js/components/features/manual/ScenarioEditor.svelte` | Medium |
| 2 | F-1-2: render/preview 失敗 alert の source+phase 帰属 | `resources/js/components/features/manual/RenderPanel.svelte` | Medium |

いずれも frontend 内部状態の追加/再構成のみ。backend / DTO / Inertia Props 型は不変。

---

## 施策1: F-1-1 シナリオ保存成功のその場残留インジケータ

### 背景（真因）
`applySaved()` は既に `addToast("success", "シナリオを保存しました")` を呼び、`AppLayout` の
`ToastContainer` が描画する（`ScenarioEditor.test.ts:249` で緑）。ただし success toast は
`toast.ts` の `AUTO_DISMISS_MS.success = 4000ms` で自動消去され、`scenario.update` は XHR で
画面遷移が無いため、保存後にユーザーが結果を確認する頃にはトーストが消え「未保存の変更があります」
インジケータが黙って消えるだけになる（`claimed_success_no_change` H7）。→ その場に残る確認を足す。

### 変更箇所
- ファイル: `resources/js/components/features/manual/ScenarioEditor.svelte`
  - state 追加（L94 付近）
  - `reseed()`（L328-333）: 常に `justSaved = false`
  - `applySaved()`（L336-339）: `reseed()` の後に `justSaved = true`
  - `save()`（L232-250）: 冒頭で `justSaved = false`
  - `showFailure()`（L224-230）: 失敗表示時に `justSaved = false`
  - dirty 転換での level-triggered クリア（新規 `$effect`）
  - 「シナリオを更新」ボタン横（L751-758）に残留インジケータを追加
  - `@lucide/svelte` から `Check` を import（L4 付近）

### 波及変更
- TypeScript 型定義: なし（`justSaved` は component 内 `$state<boolean>`）
- API Resource/DTO: なし（backend 非変更。`scenario.update` は JsonResource/XHR/409 契約を維持）
- テストファイル: `tests/js/components/features/manual/ScenarioEditor.test.ts`（下記テスト計画）

### 現行コード（抜粋）
```svelte
<!-- L328-339 -->
function reseed(document: ScenarioDocument): void {
    version = document.scenario_version;
    steps = toDraftSteps(document.steps);
    snapshot = serializeSteps(steps);
    errors = {};
}
function applySaved(document: ScenarioDocument): void {
    reseed(document);
    addToast("success", "シナリオを保存しました");
}

<!-- L751-758 -->
<div class="mt-6 flex items-center gap-2">
    <Button onclick={save} loading={saving} testId="scenario-submit">シナリオを更新</Button>
    {#if dirty}
        <span class="text-caption text-text-secondary" data-testid="scenario-dirty-indicator">
            未保存の変更があります
        </span>
    {/if}
</div>
```

### 変更後コード（抜粋）
```svelte
<!-- import に Check を追加 (L3-4 付近) -->
import { ChevronDown, ChevronUp, Check, ListPlus, Plus, Trash2 } from "@lucide/svelte";

<!-- state 追加 (saving の近く) -->
// 直近の保存成功をその場に残す (toast の 4s 自動消去に依存しない永続確認)。
// true にするのは applySaved() のみ。reseed()・save 開始・失敗・dirty 転換で false。
let justSaved = $state(false);

<!-- reseed: 常に false (409 競合/明示リロードの reseed で偽の成功表示を出さない) -->
function reseed(document: ScenarioDocument): void {
    version = document.scenario_version;
    steps = toDraftSteps(document.steps);
    snapshot = serializeSteps(steps);
    errors = {};
    justSaved = false;
}

<!-- applySaved: reseed の後に true (保存成功パスのみ) -->
function applySaved(document: ScenarioDocument): void {
    reseed(document);
    justSaved = true;
    addToast("success", "シナリオを保存しました");
}

<!-- save() 冒頭 (saveFailure = null の隣) -->
justSaved = false; // 再保存中は前回の成功確認を伏せる

<!-- showFailure() 冒頭 (saveFailure = failure の前後) -->
justSaved = false; // 失敗表示時は成功確認を消す

<!-- dirty 転換での level-triggered クリア (既存 dirty 離脱 $effect とは別に追加) -->
$effect(() => {
    if (dirty) justSaved = false; // 編集で dirty に転じたら成功確認を消す
});

<!-- ボタン横のインジケータ (dirty と排他) -->
<div class="mt-6 flex items-center gap-2">
    <Button onclick={save} loading={saving} testId="scenario-submit">シナリオを更新</Button>
    {#if dirty}
        <span class="text-caption text-text-secondary" data-testid="scenario-dirty-indicator">
            未保存の変更があります
        </span>
    {:else if justSaved}
        <span
            class="flex items-center gap-1 text-caption text-success"
            data-testid="scenario-saved-indicator"
        >
            <Check class="size-4" aria-hidden="true" />
            保存しました
        </span>
    {/if}
</div>
```

### justSaved 状態遷移（確定仕様）
| 契機 | justSaved |
|------|-----------|
| `applySaved()`（保存成功。reseed の後） | **true** |
| `reseed()`（409 競合リロード / 明示リロード含む全経路） | false |
| `save()` 開始 | false |
| `showFailure()`（generic/conflict/forbidden 全失敗） | false |
| dirty へ転換（編集） | false（`$effect`） |
| 初期値 | false |

これで「保存成功のみ true」「409 競合後の reseed でも偽成功を出さない」を保証（Codex 概念 R2 合意）。

### PHPStan適合チェック
- 該当なし（PHP 変更なし）。TypeScript strict は `justSaved: boolean` で自明に型安全。

### テスト計画（`ScenarioEditor.test.ts`）
- [ ] 既存: 保存成功で success toast が積まれる（L249）— 不変（回帰確認）
- [ ] 新規: 保存成功後に `scenario-saved-indicator`（「保存しました」）が表示され、
      `scenario-dirty-indicator` は表示されない
- [ ] 新規: 保存成功 → 行編集で dirty に転じると `scenario-saved-indicator` が消え
      `scenario-dirty-indicator` が出る（排他 + level クリア）
- [ ] 新規（Codex R2 の要）: 409 競合 → `scenario-conflict-reload` → reseed 後に
      `scenario-saved-indicator` が**出ない**（偽成功防止）
- [ ] 新規: 保存失敗（generic）で `scenario-saved-indicator` が出ない
- [ ] 個別の `DatabaseTransactions` は使用しない（vitest。DB 非依存）

### リスク
- `$effect(() => { if (dirty) justSaved = false; })` は dirty が derived で決定的なため誤発火しない。
  applySaved 直後は dirty=false（snapshot 更新済み）で justSaved=true が保たれる。
- SR への二重読み上げ: toast（role=status polite「シナリオを保存しました」）と重複しないよう、
  インジケータは live region にしない（視覚のみ）。text-success は既存 DS token（ToastContainer で使用実績）。

---

## 施策2: F-1-2 render/preview 失敗 alert の source+phase 帰属

### 背景（真因）
`RenderPanel.svelte` の起動失敗 state `errorMessage`/`showPurchaseLink` は render/preview 起動で
**共有**され、表示位置は「完成動画」見出し直下 1 か所（`render-start-error`）のみ。よって
**preview 起動失敗が完成動画欄へ誤帰属**し、購入導線も render 側に出る（誤帰属バグ a）。加えて
どの danger Alert にも source/phase の見出しが無く、preview ジョブ失敗 + render 起動失敗が同時に
並ぶと帰属不能（帰属バグ b）。

### 変更箇所
- ファイル: `resources/js/components/features/manual/RenderPanel.svelte`
  - state: `errorMessage`/`showPurchaseLink`（L46-47）→ source 別 2 state へ置換
  - `start()`（L161-184）: 起動時に該当 source の error をクリア、catch も source 別へ
  - `handleStartResponse()`（L186-203）: kind に応じて `renderStartError`/`previewStartError` へ格納
  - テンプレート: render 起動エラー（L289-302）を `renderStartError` + phase-aware title へ
  - テンプレート: `render-error`（L260-264）/ `preview-error`（L319-337）に phase-aware title を付与
  - テンプレート: preview 起動エラーを preview 小節（L309-349）に `preview-start-error` として追加

### 波及変更
- TypeScript 型定義: なし。`type StartError` は component 内ローカル型。Props（`RenderJobProps` 等）不変。
- API Resource/DTO: なし（backend 非変更）。
- テストファイル: `tests/js/components/features/manual/RenderPanel.test.ts`（下記テスト計画。既存 testId
  `render-start-error` / `render-purchase-link` / `render-error` / `preview-error` は維持）。

### 現行コード（抜粋）
```svelte
<!-- L46-47 -->
let errorMessage = $state<string | null>(null);
let showPurchaseLink = $state(false);

<!-- L161-184 start() 冒頭 -->
starting = true;
errorMessage = null;
showPurchaseLink = false;
sessionExpiredMessage = null;
try { ... await handleStartResponse(kind, res);
} catch { errorMessage = "通信に失敗しました。接続を確認して再度お試しください。"; }

<!-- L186-203 handleStartResponse -->
const body = (await res.json().catch(() => null)) as unknown;
showPurchaseLink = res.status === 402 && isInsufficientTickets(body);
if (res.status === 201 && ...) { ...renderJob/preview 更新...; return; }
const message = extractMessage(body);
errorMessage = message ?? "書き出しを開始できませんでした。時間をおいて再度お試しください。";

<!-- L260-264 render job 失敗 -->
{#if failedRenderJob?.error}
    <div class="mt-4" data-testid="render-error"><Alert type="danger">{failedRenderJob.error}</Alert></div>
{/if}

<!-- L289-302 共有 起動エラー (render 欄) -->
{#if errorMessage}
    <div class="mt-4" data-testid="render-start-error">
        <Alert type="danger">
            {errorMessage}
            {#if showPurchaseLink}
                <span class="ml-1"><TextLink href="/purchase-tickets" testId="render-purchase-link">チケットを購入する</TextLink></span>
            {/if}
        </Alert>
    </div>
{/if}

<!-- L319-337 preview job 失敗 -->
{:else if failedPreviewJob}
    <div data-testid="preview-error">
        <Alert type="danger">{failedPreviewJob.error ?? "プレビューの生成に失敗しました。"}</Alert>
    </div>
    ...preview-retry-button...
{/if}
```

### 変更後コード（抜粋）
```svelte
<!-- state 置換 -->
type StartError = { message: string; showPurchaseLink: boolean };
// 起動失敗は render/preview 独立に保持する (共有だと後発が先発を上書きし帰属が崩れる)
let renderStartError = $state<StartError | null>(null);
let previewStartError = $state<StartError | null>(null);

<!-- start() 冒頭: 該当 source のみクリア -->
starting = true;
if (kind === "render") renderStartError = null;
else previewStartError = null;
sessionExpiredMessage = null;
try {
    const res = await fetch(...);
    await handleStartResponse(kind, res);
} catch {
    const failure: StartError = {
        message: "通信に失敗しました。接続を確認して再度お試しください。",
        showPurchaseLink: false,
    };
    if (kind === "render") renderStartError = failure;
    else previewStartError = failure;
}

<!-- handleStartResponse: source 別に格納 -->
async function handleStartResponse(kind: "render" | "preview", res: Response): Promise<void> {
    const body = (await res.json().catch(() => null)) as unknown;
    if (res.status === 201 && body !== null && typeof body === "object") {
        const jobBody = body as RenderJobProps;
        if (kind === "render") { renderJob = jobBody; status = jobBody.manual_status; }
        else { preview = jobBody; }
        return;
    }
    const failure: StartError = {
        message: extractMessage(body) ?? "書き出しを開始できませんでした。時間をおいて再度お試しください。",
        showPurchaseLink: res.status === 402 && isInsufficientTickets(body),
    };
    if (kind === "render") renderStartError = failure;
    else previewStartError = failure;
}

<!-- render job 失敗: phase-aware title -->
{#if failedRenderJob?.error}
    <div class="mt-4" data-testid="render-error">
        <Alert type="danger" title="完成動画の生成に失敗しました">{failedRenderJob.error}</Alert>
    </div>
{/if}

<!-- render 起動エラー: renderStartError + title (完成動画欄, 位置は現状のまま) -->
{#if renderStartError}
    <div class="mt-4" data-testid="render-start-error">
        <Alert type="danger" title="完成動画の生成を開始できませんでした">
            {renderStartError.message}
            {#if renderStartError.showPurchaseLink}
                <span class="ml-1"><TextLink href="/purchase-tickets" testId="render-purchase-link">チケットを購入する</TextLink></span>
            {/if}
        </Alert>
    </div>
{/if}

<!-- preview 小節: preview job 失敗に title -->
{:else if failedPreviewJob}
    <div data-testid="preview-error">
        <Alert type="danger" title="プレビューの生成に失敗しました">
            {failedPreviewJob.error ?? "プレビューの生成に失敗しました。"}
        </Alert>
    </div>
    ...preview-retry-button (既存) ...
{/if}
<!-- preview 小節: preview 起動エラーを preview 側に表示 (render 欄には出さない) -->
{#if previewStartError}
    <div data-testid="preview-start-error">
        <Alert type="danger" title="プレビューの生成を開始できませんでした">
            {previewStartError.message}
            {#if previewStartError.showPurchaseLink}
                <span class="ml-1"><TextLink href="/purchase-tickets" testId="preview-purchase-link">チケットを購入する</TextLink></span>
            {/if}
        </Alert>
    </div>
{/if}
```

### 帰属マトリクス（変更後）
| 発生源×局面 | testId | Alert title | 表示位置 |
|-------------|--------|-------------|---------|
| 完成動画・起動失敗 | `render-start-error` | 完成動画の生成を開始できませんでした | 完成動画小節 |
| 完成動画・ジョブ失敗 | `render-error` | 完成動画の生成に失敗しました | 完成動画小節 |
| プレビュー・起動失敗 | `preview-start-error`（新） | プレビューの生成を開始できませんでした | プレビュー小節 |
| プレビュー・ジョブ失敗 | `preview-error` | プレビューの生成に失敗しました | プレビュー小節 |

preview 失敗 + render 失敗が同時でも、さらに同一 source 内で起動失敗とジョブ失敗が併存しても、
各 alert が source+phase の見出しで一義に読める。

### PHPStan適合チェック
- 該当なし（PHP 変更なし）。TS: `StartError` 明示型 + source 別 2 state で状態空間を正しく表現
  （Codex 概念 R1 合意。`showPurchaseLink` 誤帰属も型で排除）。

### テスト計画（`RenderPanel.test.ts`）
- [ ] 既存更新: render 起動 402 → `render-start-error` に本文 + `render-purchase-link`（不変）に加え、
      title「完成動画の生成を開始できませんでした」を検証（`toHaveTextContent` は部分一致で既存 assert は維持）
- [ ] 既存更新: `render-error` に title「完成動画の生成に失敗しました」を検証
- [ ] 既存更新: `preview-error`（scenario_version_changed）に title「プレビューの生成に失敗しました」を検証
- [ ] 新規: preview 起動 402 → `preview-start-error` に本文 + title「プレビューの生成を開始できませんでした」
      + `preview-purchase-link`。かつ `render-start-error` は**存在しない**（誤帰属しない）を検証
- [ ] 新規（帰属の要）: render 起動失敗と preview 起動失敗を続けて発生させ、両 alert が同時に別々に
      表示され、後発が先発を消さない（`render-start-error` と `preview-start-error` が共存）を検証
- [ ] 新規（同時提示）: preview ジョブ失敗（props）+ render 起動失敗（POST 422）→ `preview-error` と
      `render-start-error` が別 title で並ぶことを検証
- [ ] canManage=false / rendering 中 / published 等の既存ケースは不変（回帰）

### リスク
- 既存 test の `getByTestId(...).toHaveTextContent(message)` は wrapper div を対象にした部分一致のため、
  title（`<h4>`）追加で textContent が増えても既存 assert は壊れない。
- preview 起動エラーの表示位置移動により、preview 小節（`{#if canManage}`）内で描画される。canManage
  でない閲覧者は起動 UI を持たないため previewStartError は発生し得ず問題なし。
- `starting` は render/preview 共有ガード（同時に 1 起動）だが、起動失敗 state は source 別なので、
  片方の失敗表示中にもう片方を起動→失敗しても各 source に正しく積まれる。

---

## 実装モード

| 項目 | 内容 |
|------|------|
| 推奨モード | incremental |
| 判断根拠 | 2 施策とも独立した既存 feature コンポーネント 1 ファイルずつの局所改修で、backend/型/DTO への波及が無い。既存 UX（T026 toast / T032 stale 抑制）と整合する追補であり、main への小さな差分で安全に取り込める。 |
| 競合リスク | 施策1（ScenarioEditor）と施策2（RenderPanel）は別ファイルで無関係。他の進行中変更との競合可能性も低い（両ファイルとも直近で活発な変更なし）。 |

## 最終チェック（使命・禁止事項）
- 使命寄与: 保存成否・失敗発生源が常に自明になり「思考ゼロ・編集ゼロ」の完成導線の信頼性を上げる。
- 禁止事項: 抵触なし（backend flash/`response()->json()` 追加なし、ボタン disabled 化なし、テスト必須を満たす）。
- コーディングルール: Svelte 5 runes / DS token（text-success）/ Lucide（Check）/ 既存 atom(Alert) 流用。
  検証は `pnpm typecheck` / `pnpm lint` / `pnpm test` / `pnpm build` を全 green にしてから完了とする。

