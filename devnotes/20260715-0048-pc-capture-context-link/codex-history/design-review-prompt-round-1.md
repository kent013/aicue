＝＝＝ アプリの使命・禁止事項・思考原則（全レビューに適用） ＝＝＝

## アプリの使命 (North Star)

**AI-CUE** は、現場に既にある**作業手順書(SOP)を起点に**、AI が撮るべきカットを設計した**動画シナリオ**を生成し、そのシナリオを**スマホ(PWA)でナビゲーション撮影**することで、専門知識ゼロの現場作業者でも**標準化されたマニュアル動画**を作れるようにする。「思考ゼロ・編集ゼロ」。v1 スコープ: 字幕のみ / 撮影は PWA(同一オリジン・セッション認証) / 単一 Default Project。

## 禁止事項

1. テストなしの実装完了報告
2. PHPStan エラーの widen・baseline 化
3. dev DB への破壊操作
4. `response()->json()` の直書き
5. LLM 呼び出しの Prism 直呼び
6. prompt 文字列のコード直書き
7. 操作系 POST 応答での `redirect()->intended()`
8. 必須条件未充足を理由にボタンを disabled にする UI（押下時にエラー表示。DESIGN.md）

【思考原則】まず仮説を立てろ。データに真摯に。先人の知恵を探せ。機能の名前に立ち返れ。仕組みが機能していない段階で値を弄るな。
【ツール使用制限】コマンド実行・ファイル書き込みは行わず、テキスト分析に集中（ファイル読み込みは許可）。

＝＝＝ ここからレビュー依頼 ＝＝＝

あなたは経験豊富な Web アプリケーションアーキテクトです。Laravel + Svelte アプリの詳細設計をレビューしてください。

【前提環境】
- PHP 8.4 + Laravel 12 + Svelte 5 + Inertia.js + TypeScript / PHPStan level 10 / Pest + vitest
- DTO + JsonResource パターン / Laratrust RBAC（Organization → Team → Project 階層）
- 本改善は**サーバ変更なしの純フロント (view) 変更**（ルート/Controller/DTO/認可は既存のまま）

【レビュー観点】
1. コードの正確性（ロジック、エッジケース、null 安全）
2. 既存コードとの整合性（命名規約、パターン、API）
3. PHPStan level 10 適合性（本件は PHP 変更なし）
4. テスト計画の網羅性（各施策に vitest）
5. DTO/JsonResource パターン遵守（本件は該当薄）
6. Inertia Props vs API Response の使い分け
7. 副作用・後退リスク
8. 波及変更の網羅性（TS 型定義・テストが変更対象に含まれるか）
9. セキュリティ（認可、組織スコープ、IDOR、AGENTS.md セキュリティ不変条件）
10. DESIGN.md 準拠（token 経由参照、hex 直書きを増やさないか）
11. Atomic Design 準拠（atoms→…→pages 単方向 import、アイコンは Lucide 前提、SVG 直書き新設なし）

【出力形式】
- 各施策ごとに判定: APPROVE / REQUEST_CHANGES
- 指摘は [Critical] [Warning] [Suggestion] で分類、Critical/Warning には修正案を添える
- 全体判定: APPROVED / CHANGES_REQUESTED
- 日本語で出力

## 補足（認可・組織スコープの確認結果）

- 遷移先 `capture.manuals.show` の `CaptureManualController::show` は `Gate::authorize('view', $manual)`
  のみ要求（org member = 撮影者含む）。`ProjectPolicy::view` は「所属組織のメンバーなら可」。
- PC の `VideoManualController::show` は `view` 認可、`edit` は `update` 認可で到達。いずれも撮影ナビの
  `view` を包含 → リンク遷移先で 403 にならない。最終認可はサーバ側 `capture.manuals.show` が担保
  （フロントの表示条件は UX 上の話）。
- `capture.manuals.show` は `project.in-route-org` + `require-active-subscription` middleware 配下。
  PC のマニュアルも current org 配下 → cross-org 遷移は発生しない（URL は project.id/manual.id を素直に埋めるのみ）。
- `resources/js/types/manual.ts` の `VideoManualStatus` は PHP enum `App\Enums\Manual\VideoManualStatus`
  と値集合一致（draft/analyzing/ready/rendering/published）。既存 `STATUS_TONES` が
  `as const satisfies Record<VideoManualStatus, BadgeTone>` の網羅マップ方式を採用済み（本件もこれに倣う）。

## 詳細設計書（全文は本プロンプト末尾に貼付）

## 関連する現行コード（抜粋）

### resources/js/types/manual.ts（L10-31）
```ts
export type VideoManualStatus = "draft" | "analyzing" | "ready" | "rendering" | "published";
export const VIDEO_MANUAL_STATUS_LABELS: Record<VideoManualStatus, string> = { ... };
export const STATUS_TONES = {
    draft: "neutral", analyzing: "tertiary", ready: "success", rendering: "warning", published: "primary",
} as const satisfies Record<VideoManualStatus, BadgeTone>;
```

### resources/js/pages/Manuals/Show.svelte（ヘッダ L62-98）
```svelte
<div class="flex items-start justify-between gap-4">
    <div class="min-w-0">
        <p class="text-caption text-text-secondary"><TextLink href={`/projects/${project.id}`}>{project.name}</TextLink></p>
        <h1 class="mt-1 truncate text-h2" data-testid="manual-title">{manual.title}</h1>
        <div class="mt-2 flex items-center gap-3"> ...badge/category/created_at... </div>
    </div>
    {#if canManage}
        <div class="flex items-center gap-2">
            <Button variant="ghost" onclick={() => (duplicateDialogOpen = true)} testId="duplicate-manual-button">複製</Button>
            <Button variant="ghost" href={`/projects/${project.id}/manuals/${manual.id}/edit`} inertia testId="edit-manual-button">編集</Button>
        </div>
    {/if}
</div>
```
（Props: `manual: { id, title, status: VideoManualStatus, category, created_at }`, `canManage: boolean`。
canManage = `$user->can('update', $manual)`。撮影者は Show を閲覧可・canManage=false）

### resources/js/pages/Manuals/Edit.svelte（ヘッダ L51-55）
```svelte
<AppLayout {appName}>
    <h1 class="text-h2">動画マニュアルの編集</h1>
    <p class="mt-1 text-caption text-text-secondary">基本情報とシナリオ (撮影台本) を編集できます。</p>
    ...（基本情報カード内に「基本情報を保存」ボタン + 「キャンセル」ghost リンク href=Show）
```
（Edit は `update` 認可到達 = 編集者のみ。Props: `manual: { id, title, category, status }`）

### Button atom（既存 API）
- `variant`: primary/tertiary/ghost/neutral/success/danger/danger-outline/danger-ghost
- href + inertia で Inertia `<Link>` 描画。children に Lucide アイコン + テキストを並べられる。

### Dashboard.svelte（既存の同一パス使用例 L65）
```svelte
<a href={`/app/projects/${project.id}/manuals/${target.manual_id}`}> ... </a>
```
（Dashboard は `Camera` アイコンを @lucide/svelte から import 済み）
