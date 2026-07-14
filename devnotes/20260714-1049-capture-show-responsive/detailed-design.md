# 詳細設計: capture-show-responsive

## 使命・制約（絶対遵守）

### アプリの使命（North Star）

**AI-CUE** は、現場に既にある**作業手順書(SOP)を起点に**、AI が撮るべきカットを設計した**動画シナリオ**を生成し、
そのシナリオを**スマホ(PWA)でナビゲーション撮影**することで、専門知識ゼロの現場作業者でも
**標準化されたマニュアル動画**を作れるようにする。

- 「思考ゼロ・編集ゼロ」— 台本作成・撮影判断・編集の 3 ハードルを AI とナビ撮影で肩代わりする。
- 競合(tebiki)と異なり、標準作業を起点に AI が教材設計し撮影を指示する。
- 熟練者の暗黙知を動画マニュアルという形式知へ変換する装置(SECI)。
- v1: 字幕のみ / 撮影は PWA(同一オリジン・セッション認証) / 単一 Default Project。

### 禁止事項

1. テストなしの実装完了報告
2. PHPStan エラーの widen・baseline 化
3. dev DB への破壊操作をエージェント判断で実行
4. `response()->json()` の直書き(DTO / JsonResource / Inertia を使う)
5. LLM 呼び出しの Prism 直呼び
6. prompt 文字列のコード直書き
7. 操作系 POST の応答での `redirect()->intended()`
8. 必須条件未充足を理由にボタンを disabled にする UI

本施策はフロント(Svelte テンプレートの class 変更 + vitest)のみで、上記いずれにも抵触しない。

### コーディングルール

- **PHPStan level 10** 必須（今回 PHP 変更なし）
- **Pest**（今回 PHP 変更なし）/ フロントは **vitest**（`pnpm test`）
- フロントは Svelte 5 runes + DS token/ramp のみ（DESIGN.md canonical、ds-purity テスト）
- component 階層は atoms→molecules→organisms→features→templates→pages の単方向 import
- アイコンは `@lucide/svelte`（`MapPin` 既存利用、SVG 直書きの新設なし）
- コードフォーマット: `pnpm lint:fix` / `pnpm typecheck` / `pnpm build` 全 green
- **テストファースト**: 変更前に fail するテストを置いてから class を修正する

## 概念設計リファレンス

- `devnotes/20260714-1049-capture-show-responsive/conceptual-design.md`
- 概念レビュー: `conceptual-review-round-1.md`（**APPROVED**）+ 反映 `codex-history/conceptual-review-decisions-round-1.md`

## 受け入れ条件

1. mobile 375px / tablet 768px で撮影画面 `capture.manuals.show` にページ横スクロールが出ない。
2. `CutNavigator` の scene / shooting_point が枠内で truncate/ellipsis 表示され、
   「思考ゼロ」で次に撮るカットを一覧で読める（全文はタップで右パネルの narration にて確認可能な既存動線）。
3. vitest（構造回帰）green: grid が `grid-cols-1`、両 section が `min-w-0`、
   scene/shooting_point が truncate/min-w-0 を持つ。
4. `pnpm test` / `pnpm typecheck` / `pnpm lint` / `pnpm build` 全 green。
5. **最終確認は bug-hunt / Playwright 実走**で 375px・768px の horizontal overflow 消失を再確認する
   （jsdom はレイアウト計算をしないため vitest では overflow 自体は証明できない、という制約を明記）。
6. 実装 PR では `pnpm test -- CaptureShow` / `pnpm test -- CutNavigator` の **red→green の結果要約**を
   devnotes に残す（テストファースト履歴。Codex R1 Warning 反映。実装は app-implement の責務）。

> **右カラム保守メモ**（Codex R1 Suggestion）: `capture-right-pane` 配下に将来「横幅固定要素」を
> 追加する場合は、その要素にも `min-w-0` を優先付与し、flex/grid コンテキストで overflow を再発させないこと。

## 施策一覧

| # | 施策名 | 変更ファイル | 優先度 |
|---|--------|------------|--------|
| 1 | 撮影画面グリッドの mobile 単一列化 + グリッドアイテム min-w-0 | `resources/js/pages/Capture/Show.svelte` | High |
| 2 | CutNavigator の shooting_point 行を truncate 可能な構造へ | `resources/js/components/features/capture/CutNavigator.svelte` | High |
| 3 | ページレイアウトの回帰テスト追加 | `tests/js/pages/CaptureShow.test.ts` | High |
| 4 | CutNavigator の truncate 構造テスト新規追加 | `tests/js/components/features/capture/CutNavigator.test.ts`（新規） | High |

---

## 施策1: 撮影画面グリッドの mobile 単一列化 + グリッドアイテム min-w-0

### 変更箇所
- ファイル: `resources/js/pages/Capture/Show.svelte`（L153 グリッド div、L154 左 section、L165 右 section）

### 波及変更
- TypeScript 型定義: なし（class 文字列のみ）
- API Resource/DTO: なし
- テストファイル: `tests/js/pages/CaptureShow.test.ts`（施策3で更新）

### 現行コード
```svelte
<div class="mt-4 grid gap-4 lg:grid-cols-2">
    <section class="rounded-md border border-border bg-surface">
        ...
        <CutNavigator .../>
    </section>

    <section class="flex flex-col gap-4">
        ...
    </section>
</div>
```

### 変更後コード
```svelte
<div class="mt-4 grid grid-cols-1 gap-4 lg:grid-cols-2" data-testid="capture-grid">
    <section class="min-w-0 rounded-md border border-border bg-surface" data-testid="capture-left-pane">
        ...
        <CutNavigator .../>
    </section>

    <section class="flex min-w-0 flex-col gap-4" data-testid="capture-right-pane">
        ...
    </section>
</div>
```

> **安定 testid**（Codex R1 Critical 反映）: grid/左右 pane に `data-testid` を付与し、
> テストは DOM 構造辿り（`closest`/`:scope`）ではなく testid 直接取得で class を検証する。

### 根拠
- 列テンプレート未指定の Grid は暗黙の `auto` 列を作り、`auto` 列は max-content
  （折り返さない最長テキスト幅）までトラックが伸びる。子の `min-w-0`/`truncate` は
  トラックが広いため発火せず、ページに横スクロールが出る（H13 の実測どおり truncate が無効）。
- Tailwind `grid-cols-1` = `grid-template-columns: repeat(1, minmax(0,1fr))`。`minmax(0,…)` で
  列の最小幅を 0 にクランプし、1fr で viewport 内に収める → 子 truncate が復活する。
- 両 section の `min-w-0` は保険。`lg:grid-cols-2` でも各列（`minmax(0,1fr)`）内で
  グリッドアイテムが max-content に膨らまないよう、アイテム側の自動最小サイズも 0 に固定する。

### テスト計画
- [ ] 施策3で grid が `grid-cols-1`、両 section が `min-w-0` を持つことを検証（回帰固定）
- [ ] `pnpm typecheck` / `pnpm build` green

### リスク
- `lg:grid-cols-2` の 2 カラム表示は既存どおり（`grid-cols-1` は `lg` 未満のみ効き、`lg:grid-cols-2` が上書き）。
  デスクトップ表示に後退なし。

---

## 施策2: CutNavigator の shooting_point 行を truncate 可能な構造へ

### 変更箇所
- ファイル: `resources/js/components/features/capture/CutNavigator.svelte`（L55-60）

### 波及変更
- TypeScript 型定義: なし
- テストファイル: `tests/js/components/features/capture/CutNavigator.test.ts`（施策4で新規）

### 現行コード
```svelte
<p class="truncate text-body">{cut.scene}</p>
{#if cut.shooting_point}
    <p class="flex items-center gap-1 truncate text-caption text-text-secondary">
        <MapPin class="size-3 shrink-0" aria-hidden="true" />
        {cut.shooting_point}
    </p>
{/if}
```

### 変更後コード
```svelte
<p class="truncate text-body">{cut.scene}</p>
{#if cut.shooting_point}
    <p class="flex min-w-0 items-center gap-1 text-caption text-text-secondary">
        <MapPin class="size-3 shrink-0" aria-hidden="true" />
        <span class="min-w-0 flex-1 truncate">{cut.shooting_point}</span>
    </p>
{/if}
```

### 根拠
- 現状は flex コンテナ自身に `truncate` が付いており、直下の匿名テキストノード（flex アイテム、
  `min-width:auto`）が縮まず ellipsis が正しく描画されない。
- アイコン（`shrink-0`）とテキストを分離し、テキストを `<span class="min-w-0 flex-1 truncate">` の
  明示 flex アイテムにすることで、匿名テキストノードを残さず truncate/ellipsis を確実に発火させる
  （Codex 概念レビュー Round1 Warning 反映：`flex-1 min-w-0 truncate` で固定）。
- scene 行（L54）は block の `truncate` で、施策1 の grid 是正により親幅が確定すれば truncate が復活するため
  **構造変更不要**。この据え置き判断はテスト名にコメントとして残す（施策4）。

> **適用順序（Codex R1 Warning 反映）**: scene の truncate は施策1（親幅確定）が前提のため、
> 施策2 は**単独適用不可**。施策1 → 施策2 の順で、**同一 PR でマージ必須**。

### テスト計画
- [ ] 施策4で shooting_point の span が `min-w-0` `truncate`、scene の p が `truncate` を持つことを検証
- [ ] `pnpm lint` / `pnpm typecheck` green

### リスク
- `MapPin` アイコンは `shrink-0` を維持するため、テキストが縮んでもアイコンは潰れない。表示後退なし。

---

## 施策3: ページレイアウトの回帰テスト追加

### 変更箇所
- ファイル: `tests/js/pages/CaptureShow.test.ts`（既存。describe を 1 つ追加。既存テストは変更・削除しない）

### 追加テスト（イメージ）— 安定 testid を直接取得（Codex R1 Critical 反映）
```ts
describe("Capture/Show レイアウト overflow ガード (H13/F-1-3)", () => {
    it("グリッドは mobile 単一列 (grid-cols-1)、左右 pane が min-w-0 を持つ", () => {
        stubCameraSupported(false);
        render(CaptureShow, { props: baseProps });

        const grid = screen.getByTestId("capture-grid");
        expect(grid.className).toContain("grid-cols-1");

        expect(screen.getByTestId("capture-left-pane").className).toContain("min-w-0");
        expect(screen.getByTestId("capture-right-pane").className).toContain("min-w-0");
    });
});
```

### 波及変更
- なし（テストの追加のみ）。既存の「カメラフォールバック」describe は無改変。

### テスト計画
- [ ] `RefreshDatabase` 等は無関係（フロント vitest）
- [ ] 変更前に fail することを確認（現状 grid に `grid-cols-1` / section に `min-w-0` が無いため red）
- [ ] 施策1 適用後に green

### リスク
- DOM 構造（section > CutNavigator）に依存するため、将来レイアウトを組み替えると要追随。
  ただし closest/parentElement 辿りで最小限の結合に留める。

---

## 施策4: CutNavigator の truncate 構造テスト新規追加

### 変更箇所
- ファイル: `tests/js/components/features/capture/CutNavigator.test.ts`（**新規作成**）

### 追加テスト（イメージ）
```ts
import { afterEach, describe, expect, it, vi } from "vitest";
import { cleanup, render, screen } from "@testing-library/svelte";
import CutNavigator from "@/components/features/capture/CutNavigator.svelte";
import type { CaptureCut } from "@/types/capture";

function makeCut(overrides: Partial<CaptureCut> = {}): CaptureCut {
    return {
        id: 1, type: "step", parent_cut_id: null,
        scene: "コーヒーメーカー全体を映し、作業者が電源ボタンに手を伸ばして押す一連",
        shot_type: "hiki",
        shooting_point: "電源ボタンとランプが画面中央に大きく映るように寄って撮影",
        narration: "", subtitle_primary: null, subtitle_secondary: null,
        adopted_take_id: null, takes: [],
        ...overrides,
    };
}

afterEach(() => cleanup());

describe("CutNavigator 狭幅 truncate 構造 (H13/F-1-3)", () => {
    // Codex R1 Critical 反映: cut を一度だけ生成し render/getByText で同一参照を使う
    it("scene 行は truncate を保つ (grid 是正で親幅が確定すれば効く。構造変更は不要)", () => {
        const cut = makeCut();
        render(CutNavigator, { props: { cuts: [cut], selectedCutId: null, onSelect: vi.fn() } });
        expect(screen.getByText(cut.scene).className).toContain("truncate");
    });

    it("shooting_point 行は <p>min-w-0 + <span>truncate、MapPin は shrink-0 で ellipsis 可能", () => {
        const cut = makeCut();
        render(CutNavigator, { props: { cuts: [cut], selectedCutId: null, onSelect: vi.fn() } });

        const sp = screen.getByText(cut.shooting_point!);
        // 2 段検証: span 自身と親 <p> 行で役割分担を固定（付与先ずれ検出）。
        // span は min-w-0/flex-1/truncate を全て要求（どれが欠けても red。Codex R2 Warning 反映）
        expect(sp.tagName).toBe("SPAN");
        expect(sp).toHaveClass("min-w-0", "flex-1", "truncate");
        const row = sp.closest("p");
        expect(row).not.toBeNull();
        expect(row).toHaveClass("flex", "min-w-0");
        // アイコン非圧縮（shrink-0）を仕様として固定
        const icon = row!.querySelector("svg");
        expect(icon).not.toBeNull();
        expect(icon).toHaveClass("shrink-0");
    });
});
```

### 波及変更
- なし（新規テストファイル）。

### テスト計画
- [ ] 変更前に fail することを確認（現状 shooting_point はテキストノードで span が無いため red）
- [ ] 施策2 適用後に green
- [ ] atomic-import-graph / svg-inline-allowlist テストに抵触しない（既存 import のみ）

### リスク
- `screen.getByText` は完全一致。テキストにアイコン由来の別ノードが混ざらないよう、
  施策2 で span にテキストを完全に閉じ込める設計と整合。

---

## 実装モード

| 項目 | 内容 |
|------|------|
| 推奨モード | incremental |
| 判断根拠 | 既存ファイル 2 つの class 変更 + テスト追加のみ。新規モデル/ルート/DTO なし、他機能と独立。小さく安全に取り込める。 |
| 競合リスク | 低。撮影画面の 2 ファイルに閉じ、バックエンド・型・他ページに波及しない。 |

## 使命・禁止事項 最終チェック

- [x] 使命寄与: 撮影 PWA の狭幅表示破綻を解消し「思考ゼロ」で次カットを読める体験を維持。
- [x] 禁止事項: 抵触なし（PHP/DTO/prompt/redirect/disabled いずれも無関係）。
- [x] テスト必須: 全施策に vitest（施策3/4）を用意。テストファースト（fail 先行）を明記。
- [x] DESIGN.md/Atomic Design: Tailwind 既存ユーティリティのみ、hex/token/SVG の新設なし、層構成不変。
