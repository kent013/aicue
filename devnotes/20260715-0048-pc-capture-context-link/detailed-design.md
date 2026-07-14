# 詳細設計: pc-capture-context-link

## 使命・制約（絶対遵守）

### アプリの使命（North Star）

**AI-CUE** は、現場に既にある**作業手順書(SOP)を起点に**、AI が撮るべきカットを設計した**動画シナリオ**を生成し、そのシナリオを**スマホ(PWA)でナビゲーション撮影**することで、専門知識ゼロの現場作業者でも**標準化されたマニュアル動画**を作れるようにする。

- 「思考ゼロ・編集ゼロ」— 台本作成・撮影判断・編集の 3 ハードルを AI とナビ撮影で肩代わりする。
- 競合(tebiki)と異なり、**標準作業を起点に AI が教材設計し撮影を指示する**。
- 熟練者の暗黙知を動画マニュアルという形式知へ変換する装置（SECI）。
- v1 スコープ: 字幕のみ / 撮影は PWA(同一オリジン・セッション認証) / 単一 Default Project。

### 禁止事項

1. テストなしの実装完了報告
2. PHPStan エラーの widen・baseline 化
3. dev DB への破壊操作
4. `response()->json()` の直書き（DTO / JsonResource / Inertia）
5. LLM 呼び出しの Prism 直呼び
6. prompt 文字列のコード直書き
7. 操作系 POST 応答での `redirect()->intended()`
8. **必須条件未充足を理由にボタンを disabled にする UI**（押下時にエラー表示。DESIGN.md）

### コーディングルール

- **PHPStan level 10**（本改善はサーバ変更なし＝PHP 影響ゼロ）
- **Pest** / **vitest**（本改善は vitest のみ）
- フロントは **Svelte 5 runes + DS token/ramp のみ**（`DESIGN.md` canonical、ds-purity テスト）
- component 階層は `atoms → molecules → organisms → features → templates → pages` の単方向 import
- アイコンは **`@lucide/svelte` のみ**（SVG 直書き禁止）
- 検証コマンド: `pnpm typecheck` / `pnpm lint` / `pnpm test` / `pnpm build`

## 概念設計リファレンス

- [conceptual-design.md](./conceptual-design.md)（Codex `gpt-5.4` レビュー APPROVED / Round 3）

## 施策一覧

| # | 施策名 | 変更ファイル | 優先度 |
|---|--------|------------|--------|
| 1 | 撮影可否 predicate の追加（型付き網羅マップ） | `resources/js/types/manual.ts` | High |
| 2 | Show 画面に「この手順書を撮影する」導線を追加 | `resources/js/pages/Manuals/Show.svelte` | High |
| 3 | Edit 画面に「この手順書を撮影する」導線を追加 | `resources/js/pages/Manuals/Edit.svelte` | Med |
| 4 | predicate 単体テスト | `tests/js/types/manual.test.ts`（新規） | High |
| 5 | Show/Edit のコンポーネントテスト拡充 | `tests/js/pages/ManualsShow.test.ts` / `ManualsEdit.test.ts` | High |

共通仕様:
- 撮影リンクの遷移先 URL: `/app/projects/${project.id}/manuals/${manual.id}`（= `capture.manuals.show`）。
  既存 `Dashboard.svelte:65` と同一パス方式。
- リンクは `Button` atom（`variant="primary"` / `href` + `inertia`）で描画。アイコンは `Camera`
  （`@lucide/svelte`。Dashboard の撮影エントリと同一 iconography）。
- `testId="capture-manual-link"`（両画面共通）。
- 表示条件: `isCaptureNavigable(manual.status)` が `true` のときのみ描画（false のときは**非表示**。
  disabled ボタンにしない＝禁止事項 #8 準拠）。

---

## 施策 1: 撮影可否 predicate の追加

### 変更箇所
- ファイル: `resources/js/types/manual.ts`（`VideoManualStatus` / `STATUS_TONES` の定義元、L10-31 付近）

### 波及変更
- TypeScript 型定義: 本施策自体が型定義の追加。他ファイルへの破壊的変更なし（純増）。
- API Resource/DTO: なし（PHP 変更なし）。
- テストファイル: 施策 4（新規 predicate テスト）。

### 現行コード
```ts
export type VideoManualStatus = "draft" | "analyzing" | "ready" | "rendering" | "published";

/**
 * 状態バッジの tone (結果表示の意味色。UI 共通)。
 * satisfies でキー漏れ (status 追加時) をコンパイル時検出する。
 */
export const STATUS_TONES = {
    draft: "neutral",
    analyzing: "tertiary",
    ready: "success",
    rendering: "warning",
    published: "primary",
} as const satisfies Record<VideoManualStatus, BadgeTone>;
```

### 変更後コード（追記）
```ts
/**
 * 撮影ナビ (capture.manuals.show) へ導線を出してよい状態か。
 * 撮影ナビ一覧 (CaptureManualController::index) が列挙する ready/published と一致させる
 * (draft/analyzing/rendering はシナリオ未確定でナビ画面が空になるため導線を出さない)。
 * satisfies で status 追加時のキー漏れをコンパイル時検出する (STATUS_TONES と同方針)。
 */
export const CAPTURE_NAVIGABLE_BY_STATUS = {
    draft: false,
    analyzing: false,
    ready: true,
    rendering: false,
    published: true,
} as const satisfies Record<VideoManualStatus, boolean>;

/** PC 編集/詳細から撮影ナビへ導線を出してよいか (型付き判定の単一ソース) */
export function isCaptureNavigable(status: VideoManualStatus): boolean {
    return CAPTURE_NAVIGABLE_BY_STATUS[status];
}
```

### PHPStan 適合チェック
- [x] PHP 変更なし（該当せず。PHPStan level 10 への新規影響ゼロ）

### テスト計画
- 施策 4 の `tests/js/types/manual.test.ts` で全 `VideoManualStatus` を網羅検証。

### リスク
- なし（純増の型付きヘルパ。既存 export に影響しない）。

---

## 施策 2: Show 画面に撮影導線を追加

### 変更箇所
- ファイル: `resources/js/pages/Manuals/Show.svelte`（import 追加 + ヘッダ領域 L62-98）

### 波及変更
- TypeScript 型定義: なし（既存 `Props.manual.status: VideoManualStatus` を利用）。
- API Resource/DTO: なし。
- テストファイル: 施策 5（`ManualsShow.test.ts`）。

### 現行コード（ヘッダ抜粋）
```svelte
<script lang="ts">
    ...
    import type { AnalysisProps, CategoryOption, RenderProps, VideoManualStatus } from "@/types/manual";
    import { STATUS_TONES, VIDEO_MANUAL_STATUS_LABELS } from "@/types/manual";
    ...
</script>

<AppLayout {appName}>
    <div class="flex items-start justify-between gap-4">
        <div class="min-w-0">
            <p class="text-caption text-text-secondary">
                <TextLink href={`/projects/${project.id}`}>{project.name}</TextLink>
            </p>
            <h1 class="mt-1 truncate text-h2" data-testid="manual-title">{manual.title}</h1>
            <div class="mt-2 flex items-center gap-3"> ... </div>
        </div>
        {#if canManage}
            <div class="flex items-center gap-2">
                <Button variant="ghost" onclick={...} testId="duplicate-manual-button">複製</Button>
                <Button variant="ghost" href={...} inertia testId="edit-manual-button">編集</Button>
            </div>
        {/if}
    </div>
```

### 変更後コード
```svelte
<script lang="ts">
    ...
    import { Camera } from "@lucide/svelte";
    ...
    import type { AnalysisProps, CategoryOption, RenderProps, VideoManualStatus } from "@/types/manual";
    import { STATUS_TONES, VIDEO_MANUAL_STATUS_LABELS, isCaptureNavigable } from "@/types/manual";
    ...
    // 撮影ナビ (capture.manuals.show) への文脈リンクを出してよい状態か
    const captureNavigable = $derived(isCaptureNavigable(manual.status));
</script>

<AppLayout {appName}>
    <div class="flex items-start justify-between gap-4">
        <div class="min-w-0"> ...（既存のタイトル/バッジ）... </div>
        <!-- action コンテナは撮影導線か管理系のいずれかが出るときだけ描画 (空 div を残さない。Codex 詳細R1) -->
        {#if captureNavigable || canManage}
        <div class="flex items-center gap-2">
            {#if captureNavigable}
                <!-- canManage 内外を問わず表示 (撮影者=project_member も撮影ナビ view 可) -->
                <Button
                    variant="primary"
                    href={`/app/projects/${project.id}/manuals/${manual.id}`}
                    inertia
                    testId="capture-manual-link"
                >
                    <Camera class="size-4" aria-hidden="true" />
                    この手順書を撮影する
                </Button>
            {/if}
            {#if canManage}
                <Button variant="ghost" onclick={() => (duplicateDialogOpen = true)} testId="duplicate-manual-button">複製</Button>
                <Button variant="ghost" href={`/projects/${project.id}/manuals/${manual.id}/edit`} inertia testId="edit-manual-button">編集</Button>
            {/if}
        </div>
        {/if}
    </div>
    ...
```

補足:
- 撮影リンクは `{#if canManage}` の**外側**に置き、撮影者（`canManage=false`）にも表示する。
- action コンテナ `div.flex` は `{#if captureNavigable || canManage}` でラップし、いずれも出ない場合は
  コンテナ自体を描画しない（空 div を残さない。Codex 詳細 R1 Warning 対応）。コンテナ内で撮影リンク
  （`captureNavigable`）と管理系ボタン（`canManage`）をそれぞれ条件描画する。

### 波及変更の網羅性
- `manual.status` は既存 Props に含まれる（追加 props 不要 = Controller/DTO 変更なし）。
- `Button` atom の `href` + `inertia` は既存 API（编集ボタンで実証済み）。

### テスト計画
- 施策 5（ManualsShow.test.ts）: 表示条件・権限非依存・href を検証。

### リスク
- ヘッダ action コンテナの構造変更で既存の複製/編集ボタンの testId が変わらないこと（維持する）。
- `captureNavigable=false` かつ `canManage=false` のとき空 `div` が残るが視覚影響なし。

---

## 施策 3: Edit 画面に撮影導線を追加

### 変更箇所
- ファイル: `resources/js/pages/Manuals/Edit.svelte`（import 追加 + ヘッダ領域 L51-55）

### 波及変更
- TypeScript 型定義: なし（`Props.manual.status: VideoManualStatus` は既存）。
- API Resource/DTO: なし。
- テストファイル: 施策 5（`ManualsEdit.test.ts`）。

### 現行コード（ヘッダ抜粋）
```svelte
<script lang="ts">
    ...
    import type { CategoryOption, ScenarioDocument, VideoManualStatus } from "@/types/manual";
    ...
</script>

<AppLayout {appName}>
    <h1 class="text-h2">動画マニュアルの編集</h1>
    <p class="mt-1 text-caption text-text-secondary">
        基本情報とシナリオ (撮影台本) を編集できます。
    </p>
    ...
```

### 変更後コード
```svelte
<script lang="ts">
    ...
    import { Camera } from "@lucide/svelte";
    ...
    import type { CategoryOption, ScenarioDocument, VideoManualStatus } from "@/types/manual";
    import { isCaptureNavigable } from "@/types/manual";
    ...
    const captureNavigable = $derived(isCaptureNavigable(manual.status));
</script>

<AppLayout {appName}>
    <div class="flex items-start justify-between gap-4">
        <div class="min-w-0">
            <h1 class="text-h2">動画マニュアルの編集</h1>
            <p class="mt-1 text-caption text-text-secondary">
                基本情報とシナリオ (撮影台本) を編集できます。
            </p>
        </div>
        {#if captureNavigable}
            <Button
                variant="primary"
                href={`/app/projects/${project.id}/manuals/${manual.id}`}
                inertia
                testId="capture-manual-link"
            >
                <Camera class="size-4" aria-hidden="true" />
                この手順書を撮影する
            </Button>
        {/if}
    </div>
    ...
```

補足:
- 撮影リンクは**ヘッダ側**に置き、基本情報カード内の「基本情報を保存」ボタン群とは視覚的に分離する
  （保存アクションとの競合回避。Codex 概念 R1 対応）。
- 遷移は既存「キャンセル」ghost リンクと**同一の Inertia 通常遷移**（dirty ガードなし）。未保存の
  title/category は破棄されるが既存キャンセルと同挙動であり本改善で挙動は変えない。アプリ全体の
  dirty-navigation ガードは out-of-scope（別課題）。撮影リンクは主 CTA で誤操作確率がやや上がるため、
  共通 dirty-navigation ガード（遷移確認）の**フォローアップ TODO 化を推奨**する（Codex 詳細 R1 Warning）。

### テスト計画
- 施策 5（ManualsEdit.test.ts）: 表示条件・href を検証。

### リスク
- ヘッダを `div.flex` でラップすることで既存の見出し/説明文のマークアップが変わるが、`h1` テキスト・
  説明文は維持（既存テスト `getByRole("heading", { name: "動画マニュアルの編集" })` は不変）。

---

## 施策 4: predicate 単体テスト（新規）

### 変更箇所
- ファイル: `tests/js/types/manual.test.ts`（新規）

### テスト計画
- [ ] `isCaptureNavigable("ready") === true`
- [ ] `isCaptureNavigable("published") === true`
- [ ] `isCaptureNavigable("draft") === false`
- [ ] `isCaptureNavigable("analyzing") === false`
- [ ] `isCaptureNavigable("rendering") === false`
- [ ] `CAPTURE_NAVIGABLE_BY_STATUS` が全 5 状態のキーを持つ（網羅性のスモーク）

### テストコード（概略）
```ts
import { describe, expect, it } from "vitest";
import {
    CAPTURE_NAVIGABLE_BY_STATUS,
    isCaptureNavigable,
    type VideoManualStatus,
} from "@/types/manual";

describe("isCaptureNavigable", () => {
    it.each<[VideoManualStatus, boolean]>([
        ["draft", false],
        ["analyzing", false],
        ["ready", true],
        ["rendering", false],
        ["published", true],
    ])("%s -> %s", (status, expected) => {
        expect(isCaptureNavigable(status)).toBe(expected);
    });

    it("全 VideoManualStatus のキーを持つ", () => {
        expect(Object.keys(CAPTURE_NAVIGABLE_BY_STATUS).sort()).toEqual(
            ["analyzing", "draft", "published", "ready", "rendering"],
        );
    });
});
```

### リスク
- なし。

---

## 施策 5: Show/Edit コンポーネントテスト拡充

### 変更箇所
- ファイル: `tests/js/pages/ManualsShow.test.ts` / `tests/js/pages/ManualsEdit.test.ts`

### テスト計画（ManualsShow.test.ts）
- [ ] `status="ready"` で `capture-manual-link` が描画され、`href` が **厳密一致**
      `toBe("/app/projects/1/manuals/5")`（prefix/クエリ変化を検知。`toMatch` の緩さを避ける。Codex 詳細 R1）。
- [ ] `status="published"` でも `capture-manual-link` が描画される（ready/published=true の仕様意図を明示）。
- [ ] `status="ready"` かつ `canManage=false`（撮影者）でも `capture-manual-link` が描画される
      （権限非依存の主張を担保）。
- [ ] `status="draft"` では `capture-manual-link` が描画されない（`queryByTestId` が null）。
- [ ] 既存テスト（複製/編集/削除の canManage 出し分け）が壊れないこと（baseProps は draft のまま
      = 撮影リンクは既存テストに現れない）。

### テスト計画（ManualsEdit.test.ts）
- [ ] `status="published"` で `capture-manual-link` が描画され、`href` が `/app/projects/1/manuals/5`
      にマッチする。
- [ ] `status="draft"`（baseProps 既定）では `capture-manual-link` が描画されない。
- [ ] 既存テスト（見出し・保存系統分離）が壊れないこと。

### テストコード（ManualsShow 追加分・概略）
```ts
it("ready 状態では撮影ナビへの導線を表示し href が撮影ナビを指す", () => {
    render(Show, { props: { ...baseProps, manual: { ...baseProps.manual, status: "ready" } } });
    expect(screen.getByTestId("capture-manual-link").getAttribute("href")).toBe(
        "/app/projects/1/manuals/5",
    );
});

it("published 状態でも撮影導線を表示する", () => {
    render(Show, { props: { ...baseProps, manual: { ...baseProps.manual, status: "published" } } });
    expect(screen.getByTestId("capture-manual-link")).toBeInTheDocument();
});

it("ready 状態なら canManage=false (撮影者) でも撮影導線を表示する", () => {
    render(Show, {
        props: { ...baseProps, canManage: false, manual: { ...baseProps.manual, status: "ready" } },
    });
    expect(screen.getByTestId("capture-manual-link")).toBeInTheDocument();
});

it("draft 状態では撮影導線を表示しない", () => {
    render(Show, { props: baseProps }); // baseProps.status = "draft"
    expect(screen.queryByTestId("capture-manual-link")).toBeNull();
});
```

### リスク
- なし。

---

## 実装モード

| 項目 | 内容 |
|------|------|
| 推奨モード | incremental |
| 判断根拠 | 既存 3 ファイルへの追記 + テスト 2 ファイル拡充 + 1 テスト新規。小さな純フロント変更で、他の進行中施策との競合が起きにくい。サーバ・DTO・ルート変更を伴わない。 |
| 競合リスク | `types/manual.ts` は共有ファイルだが**純増**（既存 export を変えない）ため競合は軽微。Show/Edit のヘッダ領域変更は局所的。 |

## 使命・禁止事項チェック（最終）

- 使命寄与: SOP→シナリオ→ナビ撮影の一気通貫の**アプリ内導線**摩擦を除去（North Star に直接寄与）。
- 禁止事項: #4/#5/#6/#7（サーバ変更なしで非該当）。#8（撮影不可状態は非表示＝ disabled ボタンにしない）。#1（各施策に vitest 必須）。
- コーディングルール: Svelte 5 runes（`$derived`）、DS token、`@lucide/svelte`（Camera）、Atomic Design（`Button` atom を pages から利用＝単方向 import 準拠）、SVG 直書きなし。

## スコープ外（再掲）

- 逆方向（撮影ナビ → PC 編集）の導線追加。
- Ziggy `route()` の Svelte 導入。
- 撮影権限（take upload 可否）に基づく表示制御。
- アプリ全体の dirty-navigation ガード（Edit の未保存破棄は既存キャンセルと同挙動）。
