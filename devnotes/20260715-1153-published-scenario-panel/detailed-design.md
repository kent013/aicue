# 詳細設計: published-scenario-panel

bug-hunt run 20260715-084108 F-1-03 (Medium, H10)
title: published マニュアルでシナリオパネルが未作成表示に戻る不具合

## 使命・制約（絶対遵守）

### アプリの使命（North Star）
**AI-CUE** は、現場に既にある**作業手順書(SOP)を起点に**、AI が撮るべきカットを設計した
**動画シナリオ**を生成し、そのシナリオを**スマホ(PWA)でナビゲーション撮影**することで、
専門知識ゼロの現場作業者でも**標準化されたマニュアル動画**を作れるようにする。
- 「思考ゼロ・編集ゼロ」— 台本作成・撮影判断・編集の 3 ハードルを AI とナビ撮影で肩代わり。
- v1 スコープ: 字幕のみ / 撮影は PWA(同一オリジン・セッション認証) / 動画合成は自前 ffmpeg / 単一 Default Project。

### 禁止事項
1. テストなしの実装完了報告
2. PHPStan エラーの widen・baseline 化
3. dev DB への破壊操作をエージェント判断で実行
4. `response()->json()` の直書き（DTO / JsonResource / Inertia を使う）
5. LLM 呼び出しの Prism 直呼び
6. prompt 文字列のコード直書き
7. 操作系 POST の応答での `redirect()->intended()`
8. 必須条件未充足を理由にボタンを disabled にする UI（押下時にエラー表示する）

### コーディングルール
- 本 PR は **純フロント（TypeScript + Svelte 5 runes）** 変更。PHP / PHPStan / DTO 影響なし。
- Vitest（`pnpm test`） / typecheck（`pnpm typecheck`） / lint（`pnpm lint`） / build（`pnpm build`） 全 green。
- DS token / Atomic Design 階層は変更しない（文言・分岐のみ。DESIGN.md 準拠）。
- Svelte 5 runes（`$derived` 等）既存パターンに従う。

## 概念設計リファレンス

`devnotes/20260715-1153-published-scenario-panel/conceptual-design.md`（概念設計 Round 2 APPROVED）

## 施策一覧

| # | 施策名 | 変更ファイル | 優先度 |
|---|--------|------------|--------|
| 1 | シナリオ確定相の表示判定 helper 追加 | `resources/js/types/manual.ts` | High |
| 2 | 解析可能状態の判定 helper 追加 | `resources/js/types/manual.ts` | High |
| 3 | AnalysisPanel 説明文分岐を確定相優先へ組み替え | `resources/js/components/features/manual/AnalysisPanel.svelte` | High |
| 4 | AnalysisPanel AI 解析 CTA を解析可能状態に限定 | `resources/js/components/features/manual/AnalysisPanel.svelte` | High |
| 5 | 回帰テスト（helper 単体 + パネル分岐/CTA） | `tests/js/types/manual.test.ts`, `tests/js/components/features/manual/AnalysisPanel.test.ts` | High |

---

## 施策1・2: status 判定 helper の追加（types/manual.ts）

### 変更箇所
- ファイル: `resources/js/types/manual.ts`（`CAPTURE_NAVIGABLE_BY_STATUS` / `isCaptureNavigable` の直後, 現行 L33-49 の後ろ）

### 波及変更
- TypeScript 型定義: 本ファイル内で完結（新 const + 関数の追加のみ。既存 export 変更なし）。
- API Resource/DTO: なし（UI 専用 map。PHP enum 値集合には触れない）。
- テストファイル: `tests/js/types/manual.test.ts` に単体テスト追加（施策5）。
- `ManualEnumTsSyncInvariantTest`（`tests/Architecture/`）: enum の**値集合**同期のみ検査し、UI map
  （`CAPTURE_NAVIGABLE_BY_STATUS` 等）は対象外。新 map 追加は同期テストに影響しない。

### 現行コード（抜粋 L33-49）
```ts
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

### 変更後コード（`isCaptureNavigable` の直後に追加）
```ts
/**
 * シナリオが確定した「表示相」か (ready 以降)。
 * status がシナリオ確定相 (ready / rendering / published) かを表す **UI 表示判定** であり、
 * cuts の実在判定ではない (複製直後の draft+cuts はここでは false = 別症状。下記 note 参照)。
 * これにより確定相で「未生成」案内を出さない。
 * 注: CAPTURE_NAVIGABLE_BY_STATUS (撮影ナビ導線, rendering=false) とは別概念なので統合しない。
 * satisfies で status 追加時のキー漏れをコンパイル時検出する。
 */
export const SCENARIO_ESTABLISHED_BY_STATUS = {
    draft: false,
    analyzing: false,
    ready: true,
    rendering: true,
    published: true,
} as const satisfies Record<VideoManualStatus, boolean>;

/** status がシナリオ確定相 (ready 以降) の表示相か (型付き判定の単一ソース) */
export function isScenarioEstablished(status: VideoManualStatus): boolean {
    return SCENARIO_ESTABLISHED_BY_STATUS[status];
}

/**
 * AI 解析操作を適用できる状態か (サーバ AnalysisJobService の許可集合 = draft / ready と一致)。
 * これは **解析操作の適用可能状態** の判定であり、rendering / published / analyzing は
 * status_not_analyzable (409) となるため false。AI 解析ボタン (CTA) の表示可否に使う。
 * satisfies で status 追加時のキー漏れをコンパイル時検出する。
 */
export const SCENARIO_ANALYZABLE_BY_STATUS = {
    draft: true,
    analyzing: false,
    ready: true,
    rendering: false,
    published: false,
} as const satisfies Record<VideoManualStatus, boolean>;

/** AI 解析操作を適用できる状態か (draft / ready。型付き判定の単一ソース) */
export function isAnalyzable(status: VideoManualStatus): boolean {
    return SCENARIO_ANALYZABLE_BY_STATUS[status];
}
```

### 型安全チェック
- [x] 戻り値の型が明示されている（`: boolean`）。
- [x] `satisfies Record<VideoManualStatus, boolean>` で全 status キー網羅を強制。
- [x] 既存 `isCaptureNavigable` と同一パターン（単一ソース化）。
- [x] cuts 実在との混同を避ける命名・コメント（Round 1 Critical 対応）。

### リスク
- 低。純追加。既存 export に非破壊。

---

## 施策3: 説明文分岐を確定相優先へ組み替え（AnalysisPanel.svelte）

### 変更箇所
- ファイル: `resources/js/components/features/manual/AnalysisPanel.svelte`
  - import（L11-12）
  - 説明文分岐（L313-321）

### 波及変更
- TypeScript 型定義: なし（既存 `status: $state<VideoManualStatus>` を使用）。
- API Resource/DTO: なし。
- テストファイル: `AnalysisPanel.test.ts` に分岐固定テスト追加（施策5）。

### 現行コード
import（L11-12）:
```ts
import type { AnalysisJobProps, VideoManualStatus } from "@/types/manual";
import { ANALYSIS_STEP_LABELS } from "@/types/manual";
```
説明文分岐（L313-321）:
```svelte
<p class="mt-2 text-body text-text-secondary">
    {#if !hasDocument}
        手順書 (SOP) をアップロードすると、AI が撮るべきカットを設計したシナリオを生成します。
    {:else if status === "ready"}
        手順書から生成したシナリオを編集画面で確認できます。再解析すると既存のシナリオは置き換えられます。
    {:else}
        アップロード済みの手順書から AI がシナリオを生成できます。
    {/if}
</p>
```

### 変更後コード
import:
```ts
import type { AnalysisJobProps, VideoManualStatus } from "@/types/manual";
import { ANALYSIS_STEP_LABELS, isAnalyzable, isScenarioEstablished } from "@/types/manual";
```
説明文分岐:
```svelte
<p class="mt-2 text-body text-text-secondary">
    {#if isScenarioEstablished(status)}
        {#if status === "ready"}
            手順書から生成したシナリオを編集画面で確認できます。再解析すると既存のシナリオは置き換えられます。
        {:else}
            生成済みのシナリオは編集画面で確認できます。
        {/if}
    {:else if !hasDocument}
        手順書 (SOP) をアップロードすると、AI が撮るべきカットを設計したシナリオを生成します。
    {:else}
        アップロード済みの手順書から AI がシナリオを生成できます。
    {/if}
</p>
```

### 設計判断（分岐順序）
- **`isScenarioEstablished(status)` を `!hasDocument` より前に判定する**のが本修正の核心。
  理由: `published` / `ready` は SOP を持たない自作シナリオ経路でも到達し得る（`ScenarioService`
  L272 の draft→ready 自作遷移 → レンダで published）。`!hasDocument` を先に見ると
  **published+no-document で「手順書をアップロードすると…生成します」(未生成案内) が出て F-1-03 が
  再発する**。確定相を最優先することで document 有無に依らず「シナリオ有り」を保証する。
- **`ready` の文言は現行を byte-identical 維持**（`status === "ready"` 分岐）。`ready` は解析可能
  状態で再解析注記が正確。共通ケース (ready+document) の既存挙動は不変。
- **`rendering` / `published` は「生成済みのシナリオは編集画面で確認できます。」**（再解析注記なし）。
  これらは `status_not_analyzable`（409）で再解析不可のため、「再解析すると置き換えられます」を
  出すと誤誘導になる。また「手順書から生成した」を断定しない中立文言で自作シナリオ経路にも正確。

### 意図的な挙動変更（要記録）
- **ready+no-document**: 現行は `!hasDocument` 先行のため「手順書をアップロードすると…生成します」
  (未生成案内) を表示していた。本修正後は確定相優先により ready 文言（シナリオ有り）を表示する。
  これは F-1-03 と同一根本原因（確定相で未生成案内を出す）の副次修正であり、正しい方向の変更。
  ブリーフの「ready 既存挙動不変」は主ケース（ready+document）で満たす。テストで固定する（施策5）。
  **PR 本文への明記（Codex R1 Warning）**: 「確定相優先のため ready+no-document / published+no-document の
  表示文言をシナリオ有りへ統一」を PR 説明に 1 行残す（実装時 app-implement で反映）。

### テスト計画
- `AnalysisPanel.test.ts` に status × document の分岐固定テストを追加（施策5 参照）。

### リスク
- 低〜中。ready+no-document の文言が変わる（改善方向）。既存テストは status 依存の文言 assert を
  持たない（grep 済み）ため回帰破壊なし。ポーリング / stale alert / 解析起動ロジックは未変更。

---

## 施策4: AI 解析 CTA を解析可能状態に限定（AnalysisPanel.svelte）

### 変更箇所
- ファイル: `resources/js/components/features/manual/AnalysisPanel.svelte`（ボタン表示条件 L254）

### 波及変更
- TypeScript 型定義: なし。
- API Resource/DTO: なし。
- テストファイル: `AnalysisPanel.test.ts` に CTA 表示/非表示テスト追加（施策5）。

### 現行コード（L254）
```svelte
{#if canManage && !analyzing}
    <Button
        onclick={requestAnalyze}
        loading={starting}
        testId="analyze-button"
    >
        <Sparkles class="size-4" />
        AI 解析
    </Button>
{/if}
```

### 変更後コード
```svelte
{#if canManage && isAnalyzable(status) && !analyzing}
    <Button
        onclick={requestAnalyze}
        loading={starting}
        testId="analyze-button"
    >
        <Sparkles class="size-4" />
        AI 解析
    </Button>
{/if}
```

### 設計判断 / prohibition #8 との区別
- 禁止事項#8 は「**必須条件未充足**（例: SOP 未アップロード = ユーザーが解消可能）を理由に
  **ボタンを disabled** にする」ことの禁止。本施策は「その状態では解析操作自体が
  **カテゴリ的に適用対象外**（rendering/published は編集で ready へ戻さない限りサーバが 409）」
  ゆえの **非表示** であり、disabled 化でも「未充足を理由にした抑止」でもない。
- `draft` / `ready` では SOP 未アップロードでもボタンを出し、押下時にサーバのメッセージを表示する
  既存挙動（402/409/422 ハンドリング）を**完全に維持**する（#8 遵守）。
- `analyzing`（解析中）は従来どおり `!analyzing` で非表示（`isAnalyzable("analyzing")=false` とも整合）。
- 失敗ジョブ後の再実行導線: 失敗時 status は draft/ready へ復帰しており `isAnalyzable=true`。
  ボタンは表示され、再実行導線は保持される（既存テスト「failed job → analyze-button 表示」と整合）。

### リスク
- 低。`rendering` / `published` でボタン非表示になるが、既存テストにこれらで analyze-button の
  存在を assert するものはない（`ManualsShow.test.ts` の published テストは capture-link のみ assert、
  `AnalysisPanel.test.ts` は draft/ready/analyzing のみ）。grep で確認済み。

---

## 施策5: 回帰テスト

### 5-1. helper 単体テスト（`tests/js/types/manual.test.ts`）
既存 `isCaptureNavigable` テストに倣い、`it.each` で全 5 status を固定 + キー網羅を検証:

- `isScenarioEstablished`: draft=false, analyzing=false, ready=true, rendering=true, published=true。
  `Object.keys(SCENARIO_ESTABLISHED_BY_STATUS).sort()` が全 status キーを持つこと。
- `isAnalyzable`: draft=true, analyzing=false, ready=true, rendering=false, published=false。
  `Object.keys(SCENARIO_ANALYZABLE_BY_STATUS).sort()` が全 status キーを持つこと。

### 5-2. パネル分岐/CTA テスト（`tests/js/components/features/manual/AnalysisPanel.test.ts`）
status × document の 6 条件で文言と CTA を固定する（Codex 指摘: draft のみ document 有無で分岐）:

| # | status | hasDocument | 期待文言 | analyze-button | 種別 |
|---|--------|-------------|----------|----------------|------|
| 1 | draft | false | 「手順書 (SOP) をアップロードすると」を含む | 表示 | 既存不変 |
| 2 | draft | true | 「アップロード済みの手順書から AI がシナリオを生成できます」を含む | 表示 | 既存不変 |
| 3 | ready | true | 「手順書から生成したシナリオを編集画面で確認できます」+「再解析すると」を含む | 表示 | 既存不変 |
| 4 | ready | false | シナリオ有り文言（「編集画面で確認」を含む）。「アップロードすると…生成します」を**含まない** | 表示 | 意図的変更 |
| 5 | rendering | true | 「生成済みのシナリオは編集画面で確認できます」を含む。未生成/生成 CTA 文言を含まない | **非表示** | 修正 |
| 6 | published | true | 「生成済みのシナリオは編集画面で確認できます」を含む。「アップロード済みの手順書から AI がシナリオを生成できます」「手順書 (SOP) をアップロードすると」を**含まない** | **非表示** | 修正 (F-1-03) |
| 7 | published | false | 「生成済みのシナリオは編集画面で確認できます」を含む。「手順書 (SOP) をアップロードすると」を**含まない** | **非表示** | 修正 (再発耐性。Codex R1 Warning) |

- 主張の核（F-1-03）: **#6/#7 published で「未生成」案内文言を出さない**ことを明示 assert
  （`screen.queryByText(/シナリオを生成できます/) === null`, `screen.queryByText(/アップロードすると/) === null` 等）。
  document 有無いずれでも確定相優先で「シナリオ有り」を保証することを #6(document あり)/#7(document なし)で固定。
- CTA: #5/#6/#7 で `screen.queryByTestId("analyze-button")` が `null`、#1〜#4 で存在。
- `analyzing` 中の CTA 非表示は既存テスト（`AnalysisPanel.test.ts` L291「analyzing 中は…解析ボタンは出さない」）で
  固定済みのため追加不要（Codex R1 Suggestion に対する確認事項）。
- 既存テスト（draft/ready/analyzing 系, ポーリング, stale alert）はそのまま green を維持。

### テスト作成方針
- テストデータは props オブジェクトで生成（Factory 不要のフロントコンポーネントテスト）。
- `RefreshDatabase` / `DatabaseTransactions` は無関係（フロント Vitest）。
- 個別テストで既存のポーリング fetch mock パターン（`vi.stubGlobal("fetch", ...)`）を踏襲。
  ただし本追加テストは XHR を発火しない（描画のみ）ため fetch mock は最小で可。

---

## 波及変更まとめ（全体）

| 種別 | 変更 |
|------|------|
| Controller / Route | なし |
| DTO / JsonResource / Inertia props | なし（`status` は既存 props） |
| PHP enum / 値集合同期テスト | なし（UI map は同期対象外） |
| TypeScript 型 | `resources/js/types/manual.ts` に const 2 + 関数 2 を追加（非破壊） |
| Svelte コンポーネント | `AnalysisPanel.svelte` の import・説明文分岐・CTA 条件 |
| テスト | `manual.test.ts`（helper 単体）, `AnalysisPanel.test.ts`（分岐/CTA） |

## 実装モード

| 項目 | 内容 |
|------|------|
| 推奨モード | incremental |
| 判断根拠 | 既存 `types/manual.ts` と `AnalysisPanel.svelte` への局所的追加/変更。新規ファイル・新規ドメイン・DB 変更なし。既存パターン（`isCaptureNavigable`）に寄せる小規模フロント修正 |
| 競合リスク | 低。`AnalysisPanel.svelte` を同時に触る他施策がなければ干渉しない。`types/manual.ts` は追記のみで衝突しにくい |

## スコープ外（別 finding として管理）

- **複製直後の `draft`+cuts 表示**: `VideoManualService::copyCuts` が `ScenarioService` を経由せず
  cuts を複製するため `draft`+cuts が生成され、複製直後の Show で「手順書をアップロードすると…生成
  します」(a)（`hasDocument=false`）が出る。status ベース（表示相）判定では拾えない別症状。
  本 PR の helper は「表示相判定」に限定命名し cuts 実在を吸収しない。別 finding / TODO として起票前提。
- AI 解析ボタンの draft/ready での押下時挙動（402/409/422）は不変。
- サーバ側の状態遷移・解析可否ロジックの変更。
