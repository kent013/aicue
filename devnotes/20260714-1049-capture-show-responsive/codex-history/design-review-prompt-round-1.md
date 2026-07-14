# 詳細設計レビュー依頼 (design-review round 1)

あなたは経験豊富なWebアプリケーションアーキテクトです。Laravel + Svelte アプリの詳細設計をレビューしてください。

## アプリの使命・禁止事項（AGENTS.md より）

## 使命 (North Star)

<!-- app-codex-review スキルはこのセクションをレビュー使命として参照する。 -->

**AI-CUE** は、現場に既にある**作業手順書(SOP)を起点に**、AI が撮るべきカットを設計した**動画シナリオ**を生成し、そのシナリオを**スマホ(PWA)でナビゲーション撮影**することで、専門知識ゼロの現場作業者でも**標準化されたマニュアル動画**を作れるようにする。

- 「思考ゼロ・編集ゼロ」— 台本作成・撮影判断・編集の 3 ハードルを AI とナビ撮影で肩代わりする。
- 競合(OJT を撮って形式化する tebiki)と異なり、**標準作業を起点に AI が教材設計し撮影を指示する**（撮影者・教える人のスキルに品質を依存させない）。
- 熟練者の暗黙知を動画マニュアルという形式知へ変換する装置（SECI）。

> 詳細な設計は本リポジトリの `doc/`（01〜10）に置く。ドメインをテンプレート構造へどうマップしたかは `doc/08`〜`doc/10`（特に `doc/10 §10.8` に設計レビュー反映済み）。
> **v1 スコープ**: 字幕のみ(TTS 後回し) / 撮影は PWA(同一オリジン・セッション認証) / 動画合成は自前 ffmpeg / 単一 Default Project。

## 思考原則

1. **フレームワークのレンジ内でやる**。自前機構の前に Laravel / 同梱モジュールの公式作法を確認する
2. **今必要なものだけ作る**(オーバーエンジニアリング禁止。「あったら便利」は作らない)
3. **後方互換の並走を残さない**。書き換えると決めたら同じ PR で旧実装を消す
4. **別物の概念を「似ているから」で統合しない**
5. **テストファースト**。fail を確認してから実装に入る
6. **タコツボ実装を避ける**。各ステップで他要素との結合観点を確認する

## 禁止事項

1. テストなしの実装完了報告(不変条件は対応する Architecture/Feature テストへの登録まで含めて「実装済み」)
2. PHPStan エラーの widen(型を緩めて黙らせる)・baseline 化
3. dev DB への破壊操作(`migrate:fresh` 等)をエージェント判断で実行すること
4. `response()->json()` の直書き(DTO / JsonResource / Inertia を使う。仕様固定 endpoint のみ例外)
5. LLM 呼び出しの Prism 直呼び(`app/Prompts/` の factory 経由のみ。PromptGuardrailTest が検出)
6. prompt 文字列のコード直書き(`resources/prompts/*.yaml` に置く)
7. 操作系 POST の応答での `redirect()->intended()`(ログイン直後フロー専用。
   招待送信等は `back()->with(...)` で完結させる)
8. 必須条件未充足を理由にボタンを disabled にする UI(押下時にエラー表示する。DESIGN.md)

## セキュリティ不変条件(アプリ都合で緩めない)

詳細と実装手順は `docs/app-integration-guide.md` §7。すべて Architecture テストで強制されている:

1. **tenant キー不信**: ownership/actor/tenant キーを payload から受け取らない
   (`ProhibitsProtectedKeys` + `MassAssignmentSafetyTest`)
2. **子は親に属する**: nested route の不整合は**認可より前に 404**
   (`NestedRouteIdorDefenseTest` の inventory に登録必須)
3. **cross-org 不可**: 組織を跨ぐ read/write をしない(relation / org-scoped 解決経由のみ)
4. **untrusted 文字列は UserInput 型経由でのみ prompt に入れる**
5. **権限判定は常に `laratrust_team_id` を明示**(strict_check=true)
6. **PII(email/name)は CipherSweet**。検索は `whereBlind()`(平文 where は hit しない)
7. **課金の冪等性**: webhook は冪等マシン経由、チケットは reserve→commit/release の 2 フェーズ
8. **外部 URL 取得は SSRF 検査経由**: 外部 URL(特にユーザ入力由来)を取得する機能は
   必ず `Kent013\SsrfPin\UrlSafetyInspector` / `PinnedHttpClient` を通す。
   安全境界は `config/ssrf-pin.php` に pin する(`SsrfPinBoundaryTest` が pin 値を固定)


## 前提環境
- PHP8.4 + Laravel12 + Svelte5 + Inertia.js + TypeScript / PHPStan L10 / Pest / DTO+JsonResource / Laratrust RBAC

## レビュー観点
1.正確性 2.既存整合性 3.PHPStan L10 4.テスト網羅性 5.DTO/JsonResource 6.Inertia Props vs API 7.副作用/後退 8.波及変更網羅性 9.セキュリティ 10.DESIGN.md準拠(token/hex) 11.Atomic Design準拠(層/lucide/SVG)

## 出力形式
- 各施策ごとに判定: APPROVE / REQUEST_CHANGES
- 指摘は [Critical] [Warning] [Suggestion]、Critical/Warning には必ず修正案
- 全体判定: APPROVED / CHANGES_REQUESTED / 日本語

---

## 詳細設計書

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
<div class="mt-4 grid grid-cols-1 gap-4 lg:grid-cols-2">
    <section class="min-w-0 rounded-md border border-border bg-surface">
        ...
        <CutNavigator .../>
    </section>

    <section class="flex min-w-0 flex-col gap-4">
        ...
    </section>
</div>
```

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

### テスト計画
- [ ] 施策4で shooting_point の span が `min-w-0` `truncate`、scene の p が `truncate` を持つことを検証
- [ ] `pnpm lint` / `pnpm typecheck` green

### リスク
- `MapPin` アイコンは `shrink-0` を維持するため、テキストが縮んでもアイコンは潰れない。表示後退なし。

---

## 施策3: ページレイアウトの回帰テスト追加

### 変更箇所
- ファイル: `tests/js/pages/CaptureShow.test.ts`（既存。describe を 1 つ追加。既存テストは変更・削除しない）

### 追加テスト（イメージ）
```ts
describe("Capture/Show レイアウト overflow ガード (H13/F-1-3)", () => {
    it("グリッドは mobile 単一列 (grid-cols-1) で、両 section が min-w-0 を持つ", () => {
        stubCameraSupported(false);
        render(CaptureShow, { props: baseProps });

        // 左 section (CutNavigator を内包) から grid コンテナを辿る
        const leftSection = screen.getByTestId("cut-navigator").closest("section");
        expect(leftSection).not.toBeNull();
        expect(leftSection!.className).toContain("min-w-0");

        const grid = leftSection!.parentElement;
        expect(grid).not.toBeNull();
        expect(grid!.className).toContain("grid-cols-1");

        // 2 つの section がいずれも min-w-0
        const sections = grid!.querySelectorAll(":scope > section");
        expect(sections).toHaveLength(2);
        sections.forEach((s) => expect(s.className).toContain("min-w-0"));
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
    it("scene 行は truncate を保つ (grid 是正で親幅が確定すれば効く。構造変更は不要)", () => {
        render(CutNavigator, { props: { cuts: [makeCut()], selectedCutId: null, onSelect: vi.fn() } });
        const scene = screen.getByText(makeCut().scene);
        expect(scene.className).toContain("truncate");
    });

    it("shooting_point は min-w-0 + truncate の span で ellipsis 可能", () => {
        const cut = makeCut();
        render(CutNavigator, { props: { cuts: [cut], selectedCutId: null, onSelect: vi.fn() } });
        const sp = screen.getByText(cut.shooting_point!);
        expect(sp.tagName).toBe("SPAN");
        expect(sp.className).toContain("min-w-0");
        expect(sp.className).toContain("truncate");
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

---

## 関連する現行コード

### resources/js/pages/Capture/Show.svelte (L140-211 抜粋)
```svelte
<AppLayout {appName}>
    <h1 class="mt-1 truncate text-h2" data-testid="capture-manual-title">{manual.title}</h1>
    <div class="mt-4 grid gap-4 lg:grid-cols-2">
        <section class="rounded-md border border-border bg-surface">
            <h2 class="border-b border-border px-3 py-2 text-caption text-text-secondary">シナリオ (タップして撮影)</h2>
            <CutNavigator cuts={manual.cuts} {selectedCutId} onSelect={(cutId) => (selectedCutId = cutId)} />
        </section>
        <section class="flex flex-col gap-4">
            {#if selectedCut === null} ... {:else} ...詳細パネル/CameraRecorder/CaptureFileFallback/TakeStrip... {/if}
        </section>
    </div>
</AppLayout>
```

### resources/js/components/features/capture/CutNavigator.svelte (L37-79 抜粋)
```svelte
<ul class="divide-y divide-border" data-testid="cut-navigator">
    {#each cuts as cut (cut.id)}
        <li>
            <button type="button" class={["flex w-full items-center gap-3 px-3 py-3 text-left ...", ...]} onclick={() => onSelect(cut.id)} data-testid={`cut-row-${cut.id}`}>
                <div class="min-w-0 flex-1">
                    <p class="text-caption text-text-secondary">{labels[cut.id]}<span class="ml-1">{cut.shot_type === "hiki" ? "引き" : "寄り"}</span></p>
                    <p class="truncate text-body">{cut.scene}</p>
                    {#if cut.shooting_point}
                        <p class="flex items-center gap-1 truncate text-caption text-text-secondary">
                            <MapPin class="size-3 shrink-0" aria-hidden="true" />
                            {cut.shooting_point}
                        </p>
                    {/if}
                </div>
                <div class="flex shrink-0 items-center gap-2">...Badge...</div>
            </button>
        </li>
    {/each}
</ul>
```
