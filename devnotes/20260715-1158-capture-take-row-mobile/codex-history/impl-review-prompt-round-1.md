# 使命・禁止事項・思考原則・ツール使用制限

## アプリの使命 (North Star)

**AI-CUE** は、現場に既にある**作業手順書(SOP)を起点に**、AI が撮るべきカットを設計した**動画シナリオ**を生成し、そのシナリオを**スマホ(PWA)でナビゲーション撮影**することで、専門知識ゼロの現場作業者でも**標準化されたマニュアル動画**を作れるようにする。

- 「思考ゼロ・編集ゼロ」— 台本作成・撮影判断・編集の 3 ハードルを AI とナビ撮影で肩代わりする。
- 競合(OJT を撮って形式化する tebiki)と異なり、**標準作業を起点に AI が教材設計し撮影を指示する**。
- v1 スコープ: 字幕のみ / 撮影は PWA(同一オリジン・セッション認証) / 動画合成は自前 ffmpeg / 単一 Default Project。

## 禁止事項

1. テストなしの実装完了報告
2. PHPStan エラーの widen・baseline 化
3. dev DB への破壊操作をエージェント判断で実行
4. `response()->json()` の直書き(DTO / JsonResource / Inertia を使う)
5. LLM 呼び出しの Prism 直呼び
6. prompt 文字列のコード直書き
7. 操作系 POST の応答での `redirect()->intended()`
8. **必須条件未充足を理由にボタンを disabled にする UI**(押下時にエラー表示。DESIGN.md)

## 思考原則

まず仮説を立てろ。データに真摯に向き合え。先人の知恵(Laravel/Svelte エコシステム)を探せ。機能の名前に立ち返れ。仕組みが機能していない段階で値を弄るな。今必要なものだけ作る(オーバーエンジニアリング禁止)。

## ツール使用制限

コマンド実行・ファイル書き込みは一切行わず、提供されたテキストの分析に集中すること。ファイル読み込みは許可。

---

# system: コードレビュアーの役割

あなたは Laravel + Svelte アプリの改善実装をレビューするシニアレビュアーです。以下の観点で本 diff をレビューしてください。

- **設計との一致性**: 詳細設計書の施策 1・2 と diff が一致しているか。
- **正確性**: レイアウト崩れ(375px でのバッジと再生ボタンの重なり)が構造的に解消され、tablet/PC(≥640px)で非退行か。Tailwind クラスの意味的正しさ。
- **テスト網羅性**: 追加した vitest 構造テストが受け入れ基準(wrap / w-full / min-w-0 / sm: の契約)を守れているか。既存 15 ケースの非退行。
- **DESIGN.md 準拠**: color/radius/typography は token 経由(`border-border` / `bg-surface` / `text-body` 等)。hex 直書き(`#RRGGBB`)を増やしていないか。追加はレイアウトユーティリティ(`flex-wrap`/`w-full`/`sm:`/`min-w-0`/`gap-*`/`justify-*`/`shrink-0`)のみか。
- **Atomic Design 準拠**: `resources/js/components/` の atoms→molecules→organisms→features 単方向 import を崩していないか。atom(Badge/Button)の責務変更なし。新規 SVG/atom 追加なし、アイコンは Lucide のまま。
- **セキュリティ**: 本件はフロント表示のみ(PHP/DTO/API 波及なし)。

出力形式: ファイルごとに判定し、指摘を Critical / Warning / Suggestion に分類。最後に全体判定を **APPROVED** または **CHANGES_REQUESTED** で明記してください。

---

# user: データ

## 詳細設計書（抜粋）

### 施策 1: テイク行を mobile で 2 段化する responsive flex 化
`resources/js/components/features/capture/TakeStrip.svelte` のテイク行を、mobile(<640px)は `flex-wrap` で 2 段化(1 段目=chevron 列+ラベル列、2 段目=`w-full` 操作列を `justify-end` で右寄せ)、sm(≥640px)は `sm:flex-nowrap` / 操作列 `sm:w-auto sm:flex-nowrap sm:justify-start` で従来の 1 行に復帰(非退行)。バッジ行 `<p>` も `flex-wrap min-w-0` で段落ち可能にし、Play とバッジの重なりを構造的に解消。操作列自身も `flex-wrap`(failsafe)。

DOM クラス差分:
| 要素 | before | after |
|------|--------|-------|
| 行 | `flex items-center gap-2 ...` | `flex flex-wrap items-center gap-x-2 gap-y-2 ... sm:flex-nowrap` |
| chevron 列 | `flex flex-col gap-1` | `flex shrink-0 flex-col gap-1` |
| バッジ `<p>` | `flex items-center gap-2 text-body` | `flex min-w-0 flex-wrap items-center gap-x-2 gap-y-1 text-body` + `data-testid` |
| 操作列 | `flex shrink-0 items-center gap-1` | `flex w-full shrink-0 flex-wrap items-center justify-end gap-x-1 gap-y-1 sm:w-auto sm:flex-nowrap sm:justify-start` + `data-testid` |

### 施策 2: 構造検証用の data-testid 付与 + vitest 構造テスト
バッジ行に `take-label-${id}`、操作列に `take-actions-${id}` を付与。`describe("mobile 375px レイアウト構造 (F-1-05)")` で wrap/w-full/min-w-0/sm: の契約と、両バッジがラベル行内に収まること、最小ケースでバッジ非混入を検証。既存 15 ケース非退行。JSDOM は media query 非評価のため実ピクセル重なりは screenshot 証跡で補完。

## design system 参照

- token 由来クラス: `border-border` / `border-border-strong` / `bg-surface` / `text-body` / `text-caption` / `text-text-secondary`(DESIGN.md canonical / `resources/css/tokens.css`)。本 diff は既存のまま維持、hex 直書き追加なし。
- 触れた atomic ディレクトリ: `resources/js/components/features/capture/TakeStrip.svelte`(features 層)。使用 atom は `atoms` の Badge / Button、アイコンは `@lucide/svelte`(ChevronUp/ChevronDown/Play/Check/Download/Pencil)。層構造・import 方向は不変。

## 実装差分（git diff）

```diff
diff --git a/resources/js/components/features/capture/TakeStrip.svelte b/resources/js/components/features/capture/TakeStrip.svelte
@@ 行コンテナ・chevron列・バッジ行・操作列 @@
-            class="flex items-center gap-2 rounded-md border border-border bg-surface px-3 py-2 {take.downloaded
+            class="flex flex-wrap items-center gap-x-2 gap-y-2 rounded-md border border-border bg-surface px-3 py-2 sm:flex-nowrap {take.downloaded
                 ? 'border-border-strong'
                 : ''}"
             data-testid={`take-item-${take.id}`}
-            <div class="flex flex-col gap-1">
+            <div class="flex shrink-0 flex-col gap-1">
             <div class="min-w-0 flex-1">
-                <p class="flex items-center gap-2 text-body">
+                <p
+                    class="flex min-w-0 flex-wrap items-center gap-x-2 gap-y-1 text-body"
+                    data-testid={`take-label-${take.id}`}
+                >
                     テイク {index + 1}
                     {#if cut.adopted_take_id === take.id}
                         <Badge tone="success" testId={`take-adopted-${take.id}`}>採用中</Badge>
                     {/if}
                     {#if take.downloaded}
                         <Badge tone="neutral">DL 済み</Badge>
                     {/if}
-            <div class="flex shrink-0 items-center gap-1">
+            <div
+                class="flex w-full shrink-0 flex-wrap items-center justify-end gap-x-1 gap-y-1 sm:w-auto sm:flex-nowrap sm:justify-start"
+                data-testid={`take-actions-${take.id}`}
+            >
```

（操作列内の Play/採用/DL/コメント/削除 Button 群、ラベル列の min-w-0 flex-1 コンテナは不変）

## テスト結果

`pnpm test tests/js/components/features/capture/TakeStrip.test.ts`: **18 passed (18)** — 既存 13 + 追加 5(構造契約 3・両バッジ収まり 1・最小ケース非混入 1)。
`pnpm lint` / `pnpm typecheck` / `pnpm build`: 全て green。
PHP 変更なしのため composer test / phpstan 対象外。

## 追加テスト全文

```ts
describe("mobile 375px レイアウト構造 (F-1-05)", () => {
    it("行コンテナは mobile で wrap し sm で 1 行復帰する", () => {
        render(TakeStrip, { projectId: 1, manualId: 2, cut: makeCut([makeTake()]), onChanged: vi.fn() });
        const row = screen.getByTestId("take-item-10");
        expect(row.className).toContain("flex-wrap");
        expect(row.className).toContain("sm:flex-nowrap");
    });
    it("操作列は mobile full-width 右寄せ+wrap failsafe・sm で従来 1 行に戻る", () => {
        render(TakeStrip, { projectId: 1, manualId: 2, cut: makeCut([makeTake()]), onChanged: vi.fn() });
        const actions = screen.getByTestId("take-actions-10");
        for (const c of ["w-full", "justify-end", "flex-wrap", "sm:w-auto", "sm:flex-nowrap", "sm:justify-start"]) {
            expect(actions.className).toContain(c);
        }
    });
    it("ラベル(バッジ)行は wrap・min-w-0 で段落ちできる", () => {
        render(TakeStrip, { projectId: 1, manualId: 2, cut: makeCut([makeTake()]), onChanged: vi.fn() });
        const label = screen.getByTestId("take-label-10");
        expect(label.className).toContain("flex-wrap");
        expect(label.className).toContain("min-w-0");
    });
    it("採用中+DL済み 両バッジがラベル行内に収まる (重なりでなく段落ち構造)", () => {
        const take = makeTake({ downloaded: true });
        render(TakeStrip, { projectId: 1, manualId: 2, cut: makeCut([take], take.id), onChanged: vi.fn() });
        const label = within(screen.getByTestId("take-label-10"));
        expect(label.getByTestId("take-adopted-10")).toBeInTheDocument();
        expect(label.getByText("DL 済み")).toBeInTheDocument();
    });
    it("最小ケース (未採用・未DL) ではバッジが混入しない", () => {
        render(TakeStrip, { projectId: 1, manualId: 2, cut: makeCut([makeTake()]), onChanged: vi.fn() });
        const label = within(screen.getByTestId("take-label-10"));
        expect(label.queryByTestId("take-adopted-10")).not.toBeInTheDocument();
        expect(label.queryByText("DL 済み")).not.toBeInTheDocument();
    });
});
```

上記をレビューし、Critical/Warning/Suggestion 分類と全体判定(APPROVED / CHANGES_REQUESTED)を出してください。
