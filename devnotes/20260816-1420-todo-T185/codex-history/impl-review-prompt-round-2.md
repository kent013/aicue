# Round 2: Round 1 の指摘への対応

Round 1 の全体判定は CHANGES_REQUESTED でした。指摘ごとの判断と対応を示します。
使命・禁止事項・レビュー観点は Round 1 と同じです (再掲しません)。

---

## 対応マトリクス

# 対応マトリクス: impl-review Round 1

Codex (gpt-5.5 / reasoning=high) 全体判定: **CHANGES_REQUESTED**

---

## [Critical] `runSettled()` で遅延される並べ替えが数値 index に依存し、別の手順・急所へ適用され得る (ScenarioEditor.svelte)

- 判断: **対応する**
- 根拠: 指摘は正しく、かつ**本タスクが持ち込んだ回帰**である。
  変更前の `movePoint()` は `const points = steps[stepIndex].points;` と
  **配列参照を呼び出し時点で捕まえて**いたため、先行する手順入れ替えが実行されても
  同じ手順の急所を指し続けていた。本タスクで詳細設計の「実行時点で取り直す」
  (`const current = steps[stepIndex]`) を入れた結果、**数値 index で引き直すようになり
  別の手順を指すようになった**。設計書の意図 (削除された手順への適用を防ぐ) は正しいが、
  実現手段が同一性を壊していた。
- 対応内容:
  - `moveStepTo()` / `movePointTo()` を**安定キー (`clientKey`) で対象を解決し直す**形にした。
    呼び出し時点で `clientKey` を捕まえ、実行時点で `findIndex` して現在位置を求める。
    見つからなければ (= 削除済み) no-op。これで**削除への耐性と同一性の両方**を満たす。
  - 告知文の番号も解決し直した位置 (`at + 1` / `stepAt + 1`) を使う
    (遅延実行後に読み上げる番号が実際の位置と食い違わないようにする)。
  - 回帰テストを追加:
    「IME 変換中に手順の並べ替えと急所の並べ替えを続けて確定しても、掴んだ手順の急所が動く」。
  - **負のコントロール実施済み**: `stepAt` をキー解決から数値 index に戻すと、
    追加したテストが期待どおり赤くなる (手順 B の急所が入れ替わる) ことを確認し、戻した。
- スコープ判断 (広げなかったもの): `removeStep` / `removePoint` / `addPoint` も
  `runSettled` に数値 index を載せており**同じ弱点を持つが、いずれも本タスク以前からの挙動**で
  T185 は 1 文字も触れていない。思考原則 2 (今必要なものだけ作る) に従い本タスクでは直さない。
  直すなら「遅延構造操作は一律で安定キーを持ち回る」という別タスクとして起票するのが筋である。

## [Critical] iOS Safari 実機確認記録 (A3) が無い

- 判断: **対応する (ただし確認済みとは書かない)**
- 根拠: 指摘のとおり、詳細設計の受け入れ条件 3 は実機確認記録を完了条件にしている。
  一方で**実装したエージェントは実機を持たない**ため、確認を実施することはできない。
  ここで記録を「済み」と書くのは虚偽報告 (禁止事項 1 の精神に反する) であり、
  設計書自身が「ファイルが在ることは実機で確認した事実の証明にならない」と戒めている。
- 対応内容:
  `devnotes/20260816-1021-drag-and-drop-reordering/ios-acceptance.md` を
  **状態: 未実施**として作成した。空欄の理由・埋める人がやること (チェックリスト)・
  未確認のまま先行マージする判断の理由 (▲▼ を無変更で残しているため詰みは作らない) を明記した。
  **A3 は人間が実機で埋めるまで未達である**ことを最終報告の blockers にも書く。

## [Warning] IME 遅延 queue 後の index ずれは現行テストの負のコントロールでは塞げていない

- 判断: **対応する**
- 根拠: 上の Critical と同一の指摘。`dragOwner` の排他は「同時進行の取り違え」を塞ぐもので、
  「遅延実行後の番号ずれ」は別の系統である、という整理は正しい。
- 対応内容: 上記の回帰テストを追加し、負のコントロールで赤くなることを確認した。

## [Warning] テスト内の素の型アサーション (`as DOMRect` / `as HTMLInputElement`)

- 判断: **対応する**
- 根拠: レビュー観点として明示していたのは自分たちなので、テスト側だけ免れる理由がない。
  どちらも実体を作る / 型で絞る書き方に置き換えられる (回りくどくならない)。
- 対応内容:
  - `getBoundingClientRect` のスタブを `new DOMRect(0, top, 0, height)` に変えた
    (jsdom の実体が `top` / `bottom` を導出するので手で組む必要がない)。2 ファイルとも。
  - `stepScenes()` を `filter((el): el is HTMLInputElement => el instanceof HTMLInputElement)`
    に変えた。取りこぼしがあれば期待配列との比較で赤くなるので空振りしない。

## [Suggestion] D&D 成功ケースで `onChanged()` の assert があると強い (TakeStrip)

- 判断: **対応する**
- 根拠: 「楽観更新をしない = サーバ権威」が本実装の設計判断の要であり、
  その観測点は `onChanged()` の呼び出しである。安いので入れる。
- 対応内容: 「1 番目のテイクを 3 番目へ落とす」テストに
  `await waitFor(() => expect(onChanged).toHaveBeenCalled())` を足した。

## [Suggestion] 端操作の busy 検証が常に `take-adopt-10` を見ている (TakeStrip)

- 判断: **対応する**
- 根拠: 末尾テイクの操作なのに先頭行の busy を見ており、指摘どおり空振りしうる。
- 対応内容: `it.each` の行に対象の adopt testId を足し、
  **操作した行**の `aria-busy` を見るようにした (`take-adopt-10` / `take-adopt-12`)。

## [Suggestion] `setPointerCapture()` の例外を try/catch する

- 判断: **見送る**
- 根拠: Codex 自身が「通常の pointerdown 経路では必須修正ではありません」と述べている。
  実装は既に `typeof handle.setPointerCapture === "function"` の機能検出を通しており、
  さらに**捕捉の有無で結果が変わらない設計** (listener は `window` に張る) にしてある。
  例外が投げられる具体的経路を提示できていない段階で防御コードを足すのは
  思考原則 2 (今必要なものだけ作る) に反する。実際に投げる環境が見つかったら、
  その環境を再現するテストと一緒に入れる。

## [Suggestion] DragHandle に `aria-describedby` で補助説明を足す

- 判断: **見送る**
- 根拠: Codex 自身が「現状で最低限成立している」と評価している。
  `aria-label` が「(ドラッグ、または上下キー)」と操作方法まで含んでおり、
  読み上げが二重になる方が害が大きい。

---

## 設計書からの意図的な逸脱 (Round 1 で申告済み・Codex から異議なし)

`directRows()` の絞り込みを設計書の `:scope > li` から
`:scope > li[data-reorder-index]` に変えた。落とし先の目印として末尾に差し込む `<li>` が
測定対象に混ざると、**目印の出現でリスト長が変わり挿入位置が n と n+1 の間で振動する**ため。

---

## 修正後のコード (Critical 1 の該当箇所を全文で示す)

`resources/js/components/features/manual/ScenarioEditor.svelte` の並べ替え入口:

```svelte
    // --- 並べ替え (同一スコープ内のみ。階層をまたぐ移動は提供しない) ---

    /** 並べ替え結果のスクリーンリーダ告知 (視覚的には出さない) */
    let reorderStatus = $state("");
    function announce(message: string): void {
        reorderStatus = message;
    }

    /**
     * 並べ替えは「任意位置への移動」1 本に集約する。
     * ▲▼ ボタン・ハンドルのキーボード操作・D&D のすべてがここへ合流するので、
     * undo/redo 履歴・dirty 判定・IME ゲート (runSettled) との整合が 1 箇所で保たれる。
     * 保存 payload は配列順がそのまま順序 (sort_order はサーバ採番) なので、
     * ここで順序表現を作る必要はない。
     */
    function moveStepTo(from: number, to: number): void {
        const target = steps[from];
        if (target === undefined || from === to || to < 0 || to >= steps.length) return;
        // 掴んだ行を**安定キーで覚える**。runSettled は IME 変換中なら実行を compositionend まで
        // 遅らせるので、その間に先行する構造操作が実行されると数値 index は別の行を指す。
        const key = target.clientKey;
        // 告知は runSettled の**中**に置く。実行時の再検査で no-op になることもあるため、
        // 外に置くと「移動していないのに移動しましたと読み上げる」ことになる (design-review R2)。
        runSettled(() => {
            const at = steps.findIndex((step) => step.clientKey === key); // 実行時点で解決し直す
            if (at < 0 || at === to || to >= steps.length) return;
            commitStructural(() => {
                steps = moveItem(steps, at, to);
            });
            announce(`手順 ${at + 1} を ${to + 1} 番目に移動しました`);
        });
    }

    /**
     * 急所の移動。
     * **対象は数値 index ではなく安定キー (clientKey) で持ち回る**。`runSettled` は IME 変換中に
     * 実行を compositionend まで遅らせるため、実行時点では手順の並びが変わっていることがあり、
     * 数値 index を持ち回ると「掴んだのとは別の手順の急所」を並べ替えてしまう
     * (impl-review R1 Critical)。呼び出し時点と実行時点の両方で検査する。
     */
    function movePointTo(stepIndex: number, from: number, to: number): void {
        const step = steps[stepIndex];
        if (step === undefined) return;
        const point = step.points[from];
        if (point === undefined || from === to || to < 0 || to >= step.points.length) return;
        const stepKey = step.clientKey;
        const pointKey = point.clientKey;
        runSettled(() => {
            const stepAt = steps.findIndex((row) => row.clientKey === stepKey);
            if (stepAt < 0) return;
            const current = steps[stepAt];
            const at = current.points.findIndex((row) => row.clientKey === pointKey);
            if (at < 0 || at === to || to >= current.points.length) return;
            commitStructural(() => {
                current.points = moveItem(current.points, at, to);
            });
            announce(`急所 ${stepAt + 1}-${at + 1} を ${to + 1} 番目に移動しました`);
        });
    }

    /** ▲▼ (既存 UI。挙動は現行と同じ = 1 段移動 + 端は無変更) */
```

## 追加した回帰テスト (Critical 1)

```ts
    it("IME 変換中に手順の並べ替えと急所の並べ替えを続けて確定しても、掴んだ手順の急所が動く", async () => {
        renderDnd();

        await fireEvent.compositionStart(screen.getByTestId("step-0-scene"));
        // (1) 手順 1 (手順シーンA) を 2 番目へ
        await dragHandle("step-0-drag-handle", 50, 160);
        // (2) その手順シーンA の急所 1 を 2 番目へ (この時点では手順シーンA はまだ index 0)
        await dragHandle("point-0-0-drag-handle", 50, 160);

        // どちらも compositionend まで保留される
        expect(stepScenes()).toEqual(["手順シーンA", "手順シーンB"]);

        await fireEvent.compositionEnd(screen.getByTestId("step-0-scene"));

        // (1) が先に効いて並びが変わっても、(2) は**掴んだ手順シーンA の急所**に適用される。
        // 数値 index を持ち回っていると手順シーンB の急所が入れ替わってしまう。
        expect(stepScenes()).toEqual(["手順シーンB", "手順シーンA"]);
        expect(screen.getByTestId("point-0-0-scene")).toHaveValue("急所B-1");
        expect(screen.getByTestId("point-0-1-scene")).toHaveValue("急所B-2");
        expect(screen.getByTestId("point-1-0-scene")).toHaveValue("急所A-2");
        expect(screen.getByTestId("point-1-1-scene")).toHaveValue("急所A-1");
    });
```

### 負のコントロールの実測

`movePointTo()` の実行時解決を安定キーから数値 index に戻す
(`const stepAt = stepIndex;`) と、上のテストが赤くなることを実測した:

```
FAIL > IME 変換中に手順の並べ替えと急所の並べ替えを続けて確定しても、掴んだ手順の急所が動く
Expected the element to have value: 急所A-2
Received: 急所A-1
```

確認後、安定キー解決に戻してある。

## Round 1 → Round 2 の差分 (該当 3 ファイル)

```diff
diff --git a/resources/js/components/features/manual/ScenarioEditor.svelte b/resources/js/components/features/manual/ScenarioEditor.svelte
index 83942e1..a9609db 100644
--- a/resources/js/components/features/manual/ScenarioEditor.svelte
+++ b/resources/js/components/features/manual/ScenarioEditor.svelte
@@ -1,5 +1,5 @@
 <script lang="ts">
-    import { tick } from "svelte";
+    import { onMount, tick } from "svelte";
     import { router } from "@inertiajs/svelte";
     import {
         Check,
@@ -16,6 +16,7 @@
     import Badge from "@/components/atoms/Badge.svelte";
     import Button from "@/components/atoms/Button.svelte";
     import Card from "@/components/atoms/Card.svelte";
+    import DragHandle from "@/components/atoms/DragHandle.svelte";
     import Input from "@/components/atoms/Input.svelte";
     import Select from "@/components/atoms/Select.svelte";
     import Textarea from "@/components/atoms/Textarea.svelte";
@@ -23,6 +24,8 @@
     import FormField from "@/components/molecules/FormField.svelte";
     import ConfirmDialog from "@/components/organisms/ConfirmDialog.svelte";
     import { csrfToken } from "@/lib/csrf";
+    import { moveItem } from "@/lib/dnd/list-reorder";
+    import { createPointerDrag, type PointerDragState } from "@/lib/dnd/pointer-drag";
     import { boundHistory, parseHistorySnapshot, pushHistory } from "@/lib/manual/scenario-history";
     import { addToast } from "@/lib/stores/toast";
     import type {
@@ -231,26 +234,210 @@
         runSettled(() => commitStructural(() => steps[stepIndex].points.splice(pointIndex, 1)));
     }
 
-    /** ▲▼ 並べ替え (同一スコープ内のみ。階層をまたぐ移動は提供しない) */
+    // --- 並べ替え (同一スコープ内のみ。階層をまたぐ移動は提供しない) ---
+
+    /** 並べ替え結果のスクリーンリーダ告知 (視覚的には出さない) */
+    let reorderStatus = $state("");
+    function announce(message: string): void {
+        reorderStatus = message;
+    }
+
+    /**
+     * 並べ替えは「任意位置への移動」1 本に集約する。
+     * ▲▼ ボタン・ハンドルのキーボード操作・D&D のすべてがここへ合流するので、
+     * undo/redo 履歴・dirty 判定・IME ゲート (runSettled) との整合が 1 箇所で保たれる。
+     * 保存 payload は配列順がそのまま順序 (sort_order はサーバ採番) なので、
+     * ここで順序表現を作る必要はない。
+     */
+    function moveStepTo(from: number, to: number): void {
+        const target = steps[from];
+        if (target === undefined || from === to || to < 0 || to >= steps.length) return;
+        // 掴んだ行を**安定キーで覚える**。runSettled は IME 変換中なら実行を compositionend まで
+        // 遅らせるので、その間に先行する構造操作が実行されると数値 index は別の行を指す。
+        const key = target.clientKey;
+        // 告知は runSettled の**中**に置く。実行時の再検査で no-op になることもあるため、
+        // 外に置くと「移動していないのに移動しましたと読み上げる」ことになる (design-review R2)。
+        runSettled(() => {
+            const at = steps.findIndex((step) => step.clientKey === key); // 実行時点で解決し直す
+            if (at < 0 || at === to || to >= steps.length) return;
+            commitStructural(() => {
+                steps = moveItem(steps, at, to);
+            });
+            announce(`手順 ${at + 1} を ${to + 1} 番目に移動しました`);
+        });
+    }
+
+    /**
+     * 急所の移動。
+     * **対象は数値 index ではなく安定キー (clientKey) で持ち回る**。`runSettled` は IME 変換中に
+     * 実行を compositionend まで遅らせるため、実行時点では手順の並びが変わっていることがあり、
+     * 数値 index を持ち回ると「掴んだのとは別の手順の急所」を並べ替えてしまう
+     * (impl-review R1 Critical)。呼び出し時点と実行時点の両方で検査する。
+     */
+    function movePointTo(stepIndex: number, from: number, to: number): void {
+        const step = steps[stepIndex];
+        if (step === undefined) return;
+        const point = step.points[from];
+        if (point === undefined || from === to || to < 0 || to >= step.points.length) return;
+        const stepKey = step.clientKey;
+        const pointKey = point.clientKey;
+        runSettled(() => {
+            const stepAt = steps.findIndex((row) => row.clientKey === stepKey);
+            if (stepAt < 0) return;
+            const current = steps[stepAt];
+            const at = current.points.findIndex((row) => row.clientKey === pointKey);
+            if (at < 0 || at === to || to >= current.points.length) return;
+            commitStructural(() => {
+                current.points = moveItem(current.points, at, to);
+            });
+            announce(`急所 ${stepAt + 1}-${at + 1} を ${to + 1} 番目に移動しました`);
+        });
+    }
+
+    /** ▲▼ (既存 UI。挙動は現行と同じ = 1 段移動 + 端は無変更) */
     function moveStep(index: number, delta: -1 | 1): void {
         const next = index + delta;
-        if (next < 0 || next >= steps.length) return; // 境界: 履歴も積まない
-        runSettled(() =>
-            commitStructural(() => {
-                [steps[index], steps[next]] = [steps[next], steps[index]];
-            }),
-        );
+        if (next < 0 || next >= steps.length) {
+            // disabled にはしない (禁止事項 8)。押されたら「なぜ動かないか」を告知する
+            announce(delta < 0 ? "これ以上、上へは移動できません" : "これ以上、下へは移動できません");
+            return;
+        }
+        moveStepTo(index, next);
     }
 
     function movePoint(stepIndex: number, index: number, delta: -1 | 1): void {
-        const points = steps[stepIndex].points;
+        const step = steps[stepIndex];
+        if (step === undefined) return;
         const next = index + delta;
-        if (next < 0 || next >= points.length) return;
-        runSettled(() =>
-            commitStructural(() => {
-                [points[index], points[next]] = [points[next], points[index]];
-            }),
-        );
+        if (next < 0 || next >= step.points.length) {
+            announce(delta < 0 ? "これ以上、上へは移動できません" : "これ以上、下へは移動できません");
+            return;
+        }
+        movePointTo(stepIndex, index, next);
+    }
+
+    /** ドラッグ表示状態 (手順リスト / 急所リストで別々に持つ) */
+    let stepDrag = $state<PointerDragState>({ activeIndex: null, insertionIndex: null });
+    let pointDrag = $state<PointerDragState>({ activeIndex: null, insertionIndex: null });
+    /** 急所ドラッグ中の親手順 index (急所は手順をまたがないので 1 つで足りる) */
+    let pointDragStep = $state<number | null>(null);
+
+    /** 手順 <ol> / ドラッグ中の急所 <ol> の実体 (行の実測に使う) */
+    let stepListEl = $state<HTMLOListElement | null>(null);
+    let pointListEl: HTMLOListElement | null = null; // ドラッグ中のみ有効 (非 reactive で足りる)
+
+    /**
+     * リスト直下の**行だけ**を表示順で採る。
+     * `data-reorder-index` で絞るのは、落とし先の目印として末尾に差し込む `<li>` を
+     * 測定対象から外すためである (混ざると目印の出現でリスト長が変わり、
+     * 挿入位置が n と n+1 の間で振動する)。
+     */
+    function directRows(list: HTMLElement | null): HTMLElement[] {
+        if (list === null) return [];
+        return [...list.querySelectorAll<HTMLElement>(":scope > li[data-reorder-index]")];
+    }
+
+    // controller は**マウント時に 1 度だけ**作り、破棄時に必ず destroy する (受け入れ条件 A2)。
+    // $effect ではなく onMount を使う: これは「派生状態」ではなく
+    // 「ブラウザ資源 (window listener) をマウント期間だけ持つ」ことであり、
+    // $effect だと「本体で $state を同期 read しなければ再実行されない」という
+    // 実装者の注意力に依存した不変条件になる (多重生成のリスク)。
+    let stepDragCtl: ReturnType<typeof createPointerDrag> | null = null;
+    let pointDragCtl: ReturnType<typeof createPointerDrag> | null = null;
+
+    /**
+     * **コンポーネント全体で 1 つだけドラッグを許す所有権**。
+     * controller は自分の pointerId しか知らないため、手順用と急所用の 2 つは
+     * 互いを排他できない。所有権を持たないと
+     * 「手順のドラッグ中に急所のドラッグを開始 → 手順を先に drop して並びが変わる →
+     * 急所の drop が指す `pointDragStep` の数値 index が別の手順を指す」
+     * という取り違えが起きる (design-review R3 Critical)。
+     * 判定の基準は `start()` が**受理した瞬間**である (閾値超えではない)。
+     * UI には出さないので非 reactive な local で足りる (既存の `composing` と同じ扱い)。
+     */
+    type DragOwner = "step" | "point";
+    let dragOwner: DragOwner | null = null;
+
+    /** ドラッグに紐づく状態 (所有権 + 急所スコープ) を 1 箇所で捨てる */
+    function releaseDrag(): void {
+        dragOwner = null;
+        pointListEl = null;
+        pointDragStep = null;
+    }
+
+    onMount(() => {
+        stepDragCtl = createPointerDrag({
+            rows: () => directRows(stepListEl),
+            onState: (state) => (stepDrag = state),
+            onCommit: (from, to) => {
+                try {
+                    moveStepTo(from, to);
+                } finally {
+                    releaseDrag();
+                }
+            },
+            onCancel: releaseDrag,
+        });
+        pointDragCtl = createPointerDrag({
+            rows: () => directRows(pointListEl),
+            onState: (state) => (pointDrag = state),
+            onCommit: (from, to) => {
+                // 確定でも取消でも所有権とスコープは必ず捨てる (finally で漏れを塞ぐ)
+                try {
+                    if (pointDragStep !== null) movePointTo(pointDragStep, from, to);
+                } finally {
+                    releaseDrag();
+                }
+            },
+            onCancel: releaseDrag,
+        });
+        return () => {
+            stepDragCtl?.destroy();
+            pointDragCtl?.destroy();
+            stepDragCtl = null;
+            pointDragCtl = null;
+            releaseDrag(); // destroy は onCancel を呼ばないので、ここで明示的に捨てる
+        };
+    });
+
+    /**
+     * 手順ドラッグの開始。
+     * 所有権 (`dragOwner`) が空いているときだけ開始し、**受理されたときだけ**所有権を確定する。
+     */
+    function onStepHandleDown(index: number, event: PointerEvent): void {
+        if (dragOwner !== null || stepDragCtl === null) return; // 急所ドラッグ中は開始しない
+        if (!stepDragCtl.start(index, event)) return;
+        dragOwner = "step";
+    }
+
+    /**
+     * 急所ドラッグの開始。
+     * **スコープ (どの手順の <ol> を掴んでいるか) は `start()` が受理したときだけ確定する。**
+     * 先に書き換えてしまうと、1 本目のドラッグが進行中に 2 本目の指で別の手順のハンドルを
+     * 押したとき、1 本目の drop が**別の手順**へ適用される (iOS の多点入力で起きる
+     * データ整合性バグ。design-review R2 Critical)。
+     * さらに `dragOwner` により**手順ドラッグとの同時進行も断つ** (design-review R3 Critical)。
+     */
+    function onPointHandleDown(stepIndex: number, pointIndex: number, event: PointerEvent): void {
+        if (dragOwner !== null || pointDragCtl === null) return; // 手順ドラッグ中は開始しない
+        const target = event.currentTarget;
+        const list =
+            target instanceof HTMLElement
+                ? target.closest<HTMLOListElement>("ol[data-point-list]")
+                : null;
+        if (list === null) return;
+        // 一時変数で受け、受理されたときだけ反映する (順序が本質)
+        if (!pointDragCtl.start(pointIndex, event)) return;
+        dragOwner = "point";
+        pointListEl = list;
+        pointDragStep = stepIndex;
+    }
+
+    /** ハンドル上のキーボード操作 (▲▼ と同じ 1 段移動へ写す) */
+    function onHandleKeydown(event: KeyboardEvent, move: (delta: -1 | 1) => void): void {
+        if (event.key !== "ArrowUp" && event.key !== "ArrowDown") return;
+        event.preventDefault();
+        move(event.key === "ArrowUp" ? -1 : 1);
     }
 
     // --- 履歴コア (保存前ローカル編集のみ対象。undo/redo は steps を再代入し安定 clientKey で差分描画) ---
@@ -869,6 +1056,9 @@
     oncompositionstart={onCompositionStart}
     oncompositionend={onCompositionEnd}
 >
+    <!-- 並べ替え結果の読み上げ (視覚的には出さない。端で動かせない理由もここへ出す) -->
+    <p class="sr-only" aria-live="polite" data-testid="scenario-reorder-status">{reorderStatus}</p>
+
     {#if steps.length === 0}
         <div class="mt-4">
             <EmptyState
@@ -881,12 +1071,30 @@
             />
         </div>
     {:else}
-        <ol class="mt-4 flex flex-col gap-4" data-testid="scenario-steps">
+        <ol
+            class="mt-4 flex flex-col gap-4 {stepDrag.activeIndex !== null ? 'select-none' : ''}"
+            data-testid="scenario-steps"
+            bind:this={stepListEl}
+        >
             {#each steps as step, stepIndex (step.clientKey)}
-                <li>
+                <li class="relative" data-reorder-index={stepIndex}>
+                    {#if stepDrag.insertionIndex === stepIndex}
+                        <!-- 落とし先の目印。影・scale は使わない (DESIGN.md §Elevation) -->
+                        <div class="absolute inset-x-0 -top-2 h-0.5 bg-primary" aria-hidden="true"></div>
+                    {/if}
+                    <div class={stepDrag.activeIndex === stepIndex ? "opacity-50" : ""}>
                     <Card padding="md">
                         <div class="flex items-start justify-between gap-2">
-                            <h3 class="text-body font-medium text-text">手順 {stepIndex + 1}</h3>
+                            <div class="flex items-center gap-2">
+                                <DragHandle
+                                    ariaLabel={`手順 ${stepIndex + 1} の並び順を変更 (ドラッグ、または上下キー)`}
+                                    onpointerdown={(event) => onStepHandleDown(stepIndex, event)}
+                                    onkeydown={(event) =>
+                                        onHandleKeydown(event, (delta) => moveStep(stepIndex, delta))}
+                                    testId="step-{stepIndex}-drag-handle"
+                                />
+                                <h3 class="text-body font-medium text-text">手順 {stepIndex + 1}</h3>
+                            </div>
                             <div class="flex items-center gap-1">
                                 <Button
                                     variant="ghost"
@@ -926,13 +1134,42 @@
                         {@render videoCell(step.id, `step-${stepIndex}`)}
 
                         {#if step.points.length > 0}
-                            <ol class="mt-4 flex flex-col gap-3 border-l-2 border-border pl-4">
+                            <ol
+                                class="mt-4 flex flex-col gap-3 border-l-2 border-border pl-4"
+                                data-point-list
+                            >
                                 {#each step.points as point, pointIndex (point.clientKey)}
-                                    <li>
+                                    {@const dragging = pointDragStep === stepIndex}
+                                    <li class="relative" data-reorder-index={pointIndex}>
+                                        {#if dragging && pointDrag.insertionIndex === pointIndex}
+                                            <div
+                                                class="absolute inset-x-0 -top-1.5 h-0.5 bg-primary"
+                                                aria-hidden="true"
+                                            ></div>
+                                        {/if}
+                                        <div
+                                            class={dragging && pointDrag.activeIndex === pointIndex
+                                                ? "opacity-50"
+                                                : ""}
+                                        >
                                         <div class="flex items-start justify-between gap-2">
-                                            <h4 class="text-caption font-medium text-text-secondary">
-                                                急所 {stepIndex + 1}-{pointIndex + 1}
-                                            </h4>
+                                            <div class="flex items-center gap-2">
+                                                <DragHandle
+                                                    ariaLabel={`急所 ${stepIndex + 1}-${pointIndex + 1} の並び順を変更 (ドラッグ、または上下キー)`}
+                                                    onpointerdown={(event) =>
+                                                        onPointHandleDown(stepIndex, pointIndex, event)}
+                                                    onkeydown={(event) =>
+                                                        onHandleKeydown(event, (delta) =>
+                                                            movePoint(stepIndex, pointIndex, delta),
+                                                        )}
+                                                    testId="point-{stepIndex}-{pointIndex}-drag-handle"
+                                                />
+                                                <h4
+                                                    class="text-caption font-medium text-text-secondary"
+                                                >
+                                                    急所 {stepIndex + 1}-{pointIndex + 1}
+                                                </h4>
+                                            </div>
                                             <div class="flex items-center gap-1">
                                                 <Button
                                                     variant="ghost"
@@ -977,8 +1214,12 @@
                                             point.id,
                                             `point-${stepIndex}-${pointIndex}`,
                                         )}
+                                        </div>
                                     </li>
                                 {/each}
+                                {#if pointDragStep === stepIndex && pointDrag.insertionIndex === step.points.length}
+                                    <li class="h-0.5 bg-primary" aria-hidden="true"></li>
+                                {/if}
                             </ol>
                         {/if}
 
@@ -994,8 +1235,12 @@
                             </Button>
                         </div>
                     </Card>
+                    </div>
                 </li>
             {/each}
+            {#if stepDrag.insertionIndex === steps.length}
+                <li class="h-0.5 bg-primary" aria-hidden="true"></li>
+            {/if}
         </ol>
 
         <div class="mt-4">
diff --git a/tests/js/components/features/capture/TakeStrip.test.ts b/tests/js/components/features/capture/TakeStrip.test.ts
index 2883fd5..fa20c86 100644
--- a/tests/js/components/features/capture/TakeStrip.test.ts
+++ b/tests/js/components/features/capture/TakeStrip.test.ts
@@ -410,3 +410,210 @@ describe("サムネイル表示 (T183)", () => {
         expect(screen.queryByTestId("take-thumbnail-placeholder-10")).not.toBeInTheDocument();
     });
 });
+
+/*
+ * 並べ替え (T185)。層 3 = 配線: 落としたら既存の PATCH 経路が期待どおりの position を出すか。
+ * position は**最終 index** (移動後の全体配列での 0 始まり index)。サーバの reorderWithinCut が
+ * 対象を除いた配列へ splice するため両者は一致する。
+ */
+describe("テイクの並べ替え (T185)", () => {
+    /** 行の実測を data-reorder-index から固定値へ差し替える (top = index * 100, height = 100) */
+    function stubRowRects(): void {
+        vi.spyOn(HTMLElement.prototype, "getBoundingClientRect").mockImplementation(function (
+            this: HTMLElement,
+        ): DOMRect {
+            const raw = this.dataset.reorderIndex;
+            const index = raw === undefined ? -1 : Number(raw);
+            const top = index < 0 ? 0 : index * 100;
+            const height = index < 0 ? 0 : 100;
+            // 素の型アサーションを使わずに実体を作る (new DOMRect が top/bottom を導出する)
+            return new DOMRect(0, top, 0, height);
+        });
+    }
+
+    function pointerEvent(type: string, clientY: number, pointerId = 1): PointerEvent {
+        return new PointerEvent(type, {
+            bubbles: true,
+            cancelable: true,
+            pointerId,
+            clientY,
+            button: 0,
+            pointerType: "touch",
+        });
+    }
+
+    /** 3 テイク (id 10 / 11 / 12) */
+    function threeTakes(): CaptureTake[] {
+        return [makeTake({ id: 10 }), makeTake({ id: 11 }), makeTake({ id: 12 })];
+    }
+
+    function renderStrip(onChanged = vi.fn()): { onChanged: ReturnType<typeof vi.fn> } {
+        render(TakeStrip, {
+            projectId: 1,
+            manualId: 2,
+            cut: makeCut(threeTakes()),
+            cutLabel: "手順 1",
+            onChanged,
+        });
+        return { onChanged };
+    }
+
+    /** ハンドルを掴んで pointerY まで動かし drop する */
+    async function dragHandle(testId: string, startY: number, endY: number): Promise<void> {
+        await fireEvent(screen.getByTestId(testId), pointerEvent("pointerdown", startY));
+        await fireEvent(window, pointerEvent("pointermove", endY));
+        await fireEvent(window, pointerEvent("pointerup", endY));
+    }
+
+    /** 直近の PATCH の URL と body */
+    function lastPatch(): { url: string; body: unknown } {
+        const call = fetchMock.mock.calls.filter((c) => c[1]?.method === "PATCH").at(-1);
+        if (!call) throw new Error("PATCH リクエストがありません");
+        return { url: String(call[0]), body: JSON.parse(String(call[1].body)) as unknown };
+    }
+
+    beforeEach(() => {
+        stubRowRects();
+    });
+
+    it("1 番目のテイクを 3 番目へ落とすと position: 2 の PATCH が飛び、親が再取得する", async () => {
+        fetchMock.mockResolvedValue(jsonResponse(200, {}));
+        const { onChanged } = renderStrip();
+
+        // 掴んだ行 index 0 → 最終行の中点 (250) より下 = 挿入 index 3 → 最終 index 2
+        await dragHandle("take-drag-10", 50, 260);
+
+        await waitFor(() => expect(fetchMock).toHaveBeenCalled());
+        expect(lastPatch().url).toBe("/app/projects/1/manuals/2/cuts/3/takes/10");
+        expect(lastPatch().body).toEqual({ position: 2 });
+        // 楽観更新はせずサーバ権威。成功したら親が最新を取り直す
+        await waitFor(() => expect(onChanged).toHaveBeenCalled());
+    });
+
+    it("3 番目のテイクを 1 番目へ落とすと position: 0 の PATCH が飛ぶ", async () => {
+        fetchMock.mockResolvedValue(jsonResponse(200, {}));
+        renderStrip();
+
+        await dragHandle("take-drag-12", 250, 10);
+
+        await waitFor(() => expect(fetchMock).toHaveBeenCalled());
+        expect(lastPatch().url).toBe("/app/projects/1/manuals/2/cuts/3/takes/12");
+        expect(lastPatch().body).toEqual({ position: 0 });
+    });
+
+    it("位置が変わらない drop では通信しない", async () => {
+        renderStrip();
+
+        // 掴んだ行 index 0 の直後の隙間 (挿入 index 1) → 最終 index 0 = from
+        await dragHandle("take-drag-10", 50, 120);
+
+        expect(fetchMock).not.toHaveBeenCalled();
+    });
+
+    it("ドラッグ中の Escape では通信しない", async () => {
+        renderStrip();
+
+        await fireEvent(screen.getByTestId("take-drag-10"), pointerEvent("pointerdown", 50));
+        await fireEvent(window, pointerEvent("pointermove", 260));
+        await fireEvent.keyDown(window, { key: "Escape" });
+        await fireEvent(window, pointerEvent("pointerup", 260));
+
+        expect(fetchMock).not.toHaveBeenCalled();
+    });
+
+    it("ドラッグ中の pointercancel では通信しない", async () => {
+        renderStrip();
+
+        await fireEvent(screen.getByTestId("take-drag-10"), pointerEvent("pointerdown", 50));
+        await fireEvent(window, pointerEvent("pointermove", 260));
+        await fireEvent(window, pointerEvent("pointercancel", 260));
+
+        expect(fetchMock).not.toHaveBeenCalled();
+    });
+
+    it("ハンドル上の ArrowDown は ▼ と同じ 1 段移動の PATCH を出す", async () => {
+        fetchMock.mockResolvedValue(jsonResponse(200, {}));
+        renderStrip();
+
+        await fireEvent.keyDown(screen.getByTestId("take-drag-10"), { key: "ArrowDown" });
+
+        await waitFor(() => expect(fetchMock).toHaveBeenCalled());
+        expect(lastPatch().url).toBe("/app/projects/1/manuals/2/cuts/3/takes/10");
+        expect(lastPatch().body).toEqual({ position: 1 });
+    });
+
+    it("ハンドル上の ArrowUp は ▲ と同じ 1 段移動の PATCH を出す", async () => {
+        fetchMock.mockResolvedValue(jsonResponse(200, {}));
+        renderStrip();
+
+        await fireEvent.keyDown(screen.getByTestId("take-drag-12"), { key: "ArrowUp" });
+
+        await waitFor(() => expect(fetchMock).toHaveBeenCalled());
+        expect(lastPatch().url).toBe("/app/projects/1/manuals/2/cuts/3/takes/12");
+        expect(lastPatch().body).toEqual({ position: 1 });
+    });
+
+    it.each([
+        ["先頭で ▲", "take-up-10", "take-adopt-10", "これ以上、上へは移動できません"],
+        ["末尾で ▼", "take-down-12", "take-adopt-12", "これ以上、下へは移動できません"],
+    ])(
+        "%s は通信せず・busy にせず・再取得せず、理由を告知する",
+        async (_label, testId, adoptTestId, message) => {
+            const { onChanged } = renderStrip();
+
+            await fireEvent.click(screen.getByTestId(testId));
+
+            expect(fetchMock).not.toHaveBeenCalled();
+            expect(onChanged).not.toHaveBeenCalled();
+            // busy は**操作した行**で見る (別の行を見ると空振りする)
+            expect(screen.getByTestId(adoptTestId)).not.toHaveAttribute("aria-busy");
+            expect(screen.getByTestId("take-reorder-status")).toHaveTextContent(message);
+        },
+    );
+
+    it("端のハンドル操作 (ArrowUp) も同じく通信せず理由を告知する", async () => {
+        renderStrip();
+
+        await fireEvent.keyDown(screen.getByTestId("take-drag-10"), { key: "ArrowUp" });
+
+        expect(fetchMock).not.toHaveBeenCalled();
+        expect(screen.getByTestId("take-reorder-status")).toHaveTextContent(
+            "これ以上、上へは移動できません",
+        );
+    });
+
+    it("成功した並べ替えは aria-live で告知する", async () => {
+        fetchMock.mockResolvedValue(jsonResponse(200, {}));
+        renderStrip();
+
+        await dragHandle("take-drag-10", 50, 260);
+
+        await waitFor(() =>
+            expect(screen.getByTestId("take-reorder-status")).toHaveTextContent(
+                "テイク 1 を 3 番目に移動しました",
+            ),
+        );
+    });
+
+    it("PATCH が 422 ならサーバ文言を role=alert に出し、告知はしない", async () => {
+        fetchMock.mockResolvedValue(jsonResponse(422, { message: "処理中のため並べ替えできません" }));
+        renderStrip();
+
+        await dragHandle("take-drag-10", 50, 260);
+
+        await waitFor(() =>
+            expect(screen.getByTestId("take-strip-error")).toHaveTextContent(
+                "処理中のため並べ替えできません",
+            ),
+        );
+        expect(screen.getByTestId("take-reorder-status")).toHaveTextContent("");
+    });
+
+    it("ハンドルは disabled 属性を持たない (禁止事項 8)", () => {
+        renderStrip();
+
+        for (const id of ["take-drag-10", "take-drag-11", "take-drag-12"]) {
+            expect(screen.getByTestId(id)).not.toHaveAttribute("disabled");
+        }
+    });
+});
diff --git a/tests/js/components/features/manual/ScenarioEditor.test.ts b/tests/js/components/features/manual/ScenarioEditor.test.ts
index 6359d42..8cf00ad 100644
--- a/tests/js/components/features/manual/ScenarioEditor.test.ts
+++ b/tests/js/components/features/manual/ScenarioEditor.test.ts
@@ -1210,3 +1210,324 @@ describe("ScenarioEditor", () => {
         });
     });
 });
+
+/*
+ * ドラッグ&ドロップ並べ替え (T185)。層 3 = 配線:
+ * 落としたら既存の保存経路 (payloadSteps の配列順) / 履歴 / dirty 判定が期待どおり動くか。
+ * 意味論 (どこに落ちたら何番目か) は tests/js/lib/dnd/list-reorder.test.ts が持つ。
+ */
+describe("ドラッグ&ドロップ並べ替え (T185)", () => {
+    let rectSpy: ReturnType<typeof vi.spyOn> | null = null;
+
+    /** 行の実測を data-reorder-index から固定値へ差し替える (top = index * 100, height = 100) */
+    function stubRowRects(): void {
+        rectSpy = vi.spyOn(HTMLElement.prototype, "getBoundingClientRect").mockImplementation(
+            function (this: HTMLElement): DOMRect {
+                const raw = this.dataset.reorderIndex;
+                const index = raw === undefined ? -1 : Number(raw);
+                const top = index < 0 ? 0 : index * 100;
+                const height = index < 0 ? 0 : 100;
+                // 素の型アサーションを使わずに実体を作る (new DOMRect が top/bottom を導出する)
+                return new DOMRect(0, top, 0, height);
+            },
+        );
+    }
+
+    function pointerEvent(type: string, clientY: number, pointerId = 1): PointerEvent {
+        return new PointerEvent(type, {
+            bubbles: true,
+            cancelable: true,
+            pointerId,
+            clientY,
+            button: 0,
+            pointerType: "touch",
+        });
+    }
+
+    async function grab(testId: string, clientY: number, pointerId = 1): Promise<void> {
+        await fireEvent(screen.getByTestId(testId), pointerEvent("pointerdown", clientY, pointerId));
+    }
+
+    async function dragTo(clientY: number, pointerId = 1): Promise<void> {
+        await fireEvent(window, pointerEvent("pointermove", clientY, pointerId));
+    }
+
+    async function drop(clientY: number, pointerId = 1): Promise<void> {
+        await fireEvent(window, pointerEvent("pointerup", clientY, pointerId));
+    }
+
+    /** 掴む → 動かす → 落とす */
+    async function dragHandle(testId: string, startY: number, endY: number): Promise<void> {
+        await grab(testId, startY);
+        await dragTo(endY);
+        await drop(endY);
+    }
+
+    /** 2 手順 × 2 急所 (急所の同一スコープ性を検証できる形) */
+    function makeDndDocument(): ScenarioDocument {
+        const row = (id: number, scene: string) => ({
+            id,
+            scene,
+            shot_type: "yori" as const,
+            shooting_point: null,
+            narration: "",
+            subtitle_primary: null,
+            subtitle_secondary: "",
+            material_type: null,
+            static_display_seconds: null,
+        });
+        return {
+            scenario_version: 3,
+            steps: [
+                {
+                    ...row(11, "手順シーンA"),
+                    shot_type: "hiki",
+                    points: [row(21, "急所A-1"), row(22, "急所A-2")],
+                },
+                {
+                    ...row(12, "手順シーンB"),
+                    shot_type: "hiki",
+                    points: [row(23, "急所B-1"), row(24, "急所B-2")],
+                },
+            ],
+        };
+    }
+
+    function renderDnd(): void {
+        render(ScenarioEditor, { props: { ...baseProps, scenario: makeDndDocument() } });
+    }
+
+    /** 現在の手順の scene 値 (表示順) */
+    function stepScenes(): string[] {
+        return screen
+            .getAllByTestId(/^step-\d+-scene$/)
+            .filter((el): el is HTMLInputElement => el instanceof HTMLInputElement)
+            .map((el) => el.value);
+    }
+
+    beforeEach(() => {
+        stubRowRects();
+    });
+
+    afterEach(() => {
+        rectSpy?.mockRestore();
+        rectSpy = null;
+    });
+
+    it("手順のハンドルをドラッグすると順序が入れ替わり、保存 payload の並びも変わる", async () => {
+        const saved: ScenarioDocument = { ...makeDndDocument(), scenario_version: 4 };
+        fetchMock.mockResolvedValueOnce(jsonResponse(200, saved));
+        renderDnd();
+
+        // 手順 1 を掴んで手順 2 の中点 (150) より下へ落とす → 挿入 index 2 → 最終 index 1
+        await dragHandle("step-0-drag-handle", 50, 160);
+
+        expect(stepScenes()).toEqual(["手順シーンB", "手順シーンA"]);
+
+        await fireEvent.click(screen.getByTestId("scenario-submit"));
+        await waitFor(() => expect(fetchMock).toHaveBeenCalled());
+        expect(lastPutPayload().steps.map((step) => step.id)).toEqual([12, 11]);
+    });
+
+    it("D&D の直後は未保存の変更として表示される", async () => {
+        renderDnd();
+
+        await dragHandle("step-0-drag-handle", 50, 160);
+
+        expect(screen.getByTestId("scenario-dirty-indicator")).toBeInTheDocument();
+    });
+
+    it("D&D の直後に『元に戻す』で元の順序へ戻る", async () => {
+        renderDnd();
+
+        await dragHandle("step-0-drag-handle", 50, 160);
+        expect(stepScenes()).toEqual(["手順シーンB", "手順シーンA"]);
+
+        await fireEvent.click(screen.getByTestId("scenario-undo"));
+
+        expect(stepScenes()).toEqual(["手順シーンA", "手順シーンB"]);
+    });
+
+    it("成功した並べ替えは aria-live で告知する", async () => {
+        renderDnd();
+
+        await dragHandle("step-0-drag-handle", 50, 160);
+
+        expect(screen.getByTestId("scenario-reorder-status")).toHaveTextContent(
+            "手順 1 を 2 番目に移動しました",
+        );
+    });
+
+    it("急所の D&D は同じ手順の中だけで完結する", async () => {
+        renderDnd();
+
+        await dragHandle("point-0-0-drag-handle", 50, 160);
+
+        expect(screen.getByTestId("point-0-0-scene")).toHaveValue("急所A-2");
+        expect(screen.getByTestId("point-0-1-scene")).toHaveValue("急所A-1");
+        // 別手順の急所は無変更 (closest による絞り込みが効いている)
+        expect(screen.getByTestId("point-1-0-scene")).toHaveValue("急所B-1");
+        expect(screen.getByTestId("point-1-1-scene")).toHaveValue("急所B-2");
+    });
+
+    it("ドラッグ中に Escape を押すと順序が変わらない", async () => {
+        renderDnd();
+
+        await grab("step-0-drag-handle", 50);
+        await dragTo(160);
+        await fireEvent.keyDown(window, { key: "Escape" });
+        await drop(160);
+
+        expect(stepScenes()).toEqual(["手順シーンA", "手順シーンB"]);
+        expect(screen.getByTestId("scenario-reorder-status")).toHaveTextContent("");
+    });
+
+    it("2 本目の指は 1 本目のドラッグ対象をすり替えない (同一 controller)", async () => {
+        renderDnd();
+
+        // 手順 A の急所を pointerId=1 で掴んで動かす
+        await grab("point-0-0-drag-handle", 50, 1);
+        await dragTo(160, 1);
+        // その最中に手順 B の急所ハンドルを別の指 (pointerId=2) で押す
+        await grab("point-1-0-drag-handle", 50, 2);
+        // 1 本目を drop する
+        await drop(160, 1);
+
+        // 手順 A の急所だけが動き、手順 B は無変更
+        expect(screen.getByTestId("point-0-0-scene")).toHaveValue("急所A-2");
+        expect(screen.getByTestId("point-0-1-scene")).toHaveValue("急所A-1");
+        expect(screen.getByTestId("point-1-0-scene")).toHaveValue("急所B-1");
+        expect(screen.getByTestId("point-1-1-scene")).toHaveValue("急所B-2");
+    });
+
+    it("手順ドラッグ中は急所ドラッグが始まらない (controller またぎの排他)", async () => {
+        renderDnd();
+
+        await grab("step-0-drag-handle", 50, 1);
+        await dragTo(160, 1);
+        // 急所ハンドルを別の指で押し、急所の drop 相当まで出しても始まらない
+        await grab("point-1-0-drag-handle", 50, 2);
+        await dragTo(160, 2);
+        await drop(160, 2);
+
+        expect(screen.getByTestId("point-1-0-scene")).toHaveValue("急所B-1");
+        expect(stepScenes()).toEqual(["手順シーンA", "手順シーンB"]); // 1 本目はまだ drop していない
+
+        await drop(160, 1);
+
+        // 1 本目は掴んだとおりの行が期待どおりの位置へ動く
+        expect(stepScenes()).toEqual(["手順シーンB", "手順シーンA"]);
+    });
+
+    it("急所ドラッグ中は手順ドラッグが始まらない (逆向き)", async () => {
+        renderDnd();
+
+        await grab("point-0-0-drag-handle", 50, 1);
+        await dragTo(160, 1);
+        await grab("step-0-drag-handle", 50, 2);
+        await dragTo(160, 2);
+        await drop(160, 2);
+
+        expect(stepScenes()).toEqual(["手順シーンA", "手順シーンB"]);
+
+        await drop(160, 1);
+
+        expect(screen.getByTestId("point-0-0-scene")).toHaveValue("急所A-2");
+        expect(screen.getByTestId("point-0-1-scene")).toHaveValue("急所A-1");
+        expect(stepScenes()).toEqual(["手順シーンA", "手順シーンB"]);
+    });
+
+    it("IME 変換中に確定した D&D は compositionend まで順序も告知も変わらない", async () => {
+        renderDnd();
+
+        await fireEvent.compositionStart(screen.getByTestId("step-0-scene"));
+        await dragHandle("step-0-drag-handle", 50, 160);
+
+        expect(stepScenes()).toEqual(["手順シーンA", "手順シーンB"]);
+        expect(screen.getByTestId("scenario-reorder-status")).toHaveTextContent("");
+
+        await fireEvent.compositionEnd(screen.getByTestId("step-0-scene"));
+
+        expect(stepScenes()).toEqual(["手順シーンB", "手順シーンA"]);
+        expect(screen.getByTestId("scenario-reorder-status")).toHaveTextContent(
+            "手順 1 を 2 番目に移動しました",
+        );
+    });
+
+    it("IME 変換中に手順の並べ替えと急所の並べ替えを続けて確定しても、掴んだ手順の急所が動く", async () => {
+        renderDnd();
+
+        await fireEvent.compositionStart(screen.getByTestId("step-0-scene"));
+        // (1) 手順 1 (手順シーンA) を 2 番目へ
+        await dragHandle("step-0-drag-handle", 50, 160);
+        // (2) その手順シーンA の急所 1 を 2 番目へ (この時点では手順シーンA はまだ index 0)
+        await dragHandle("point-0-0-drag-handle", 50, 160);
+
+        // どちらも compositionend まで保留される
+        expect(stepScenes()).toEqual(["手順シーンA", "手順シーンB"]);
+
+        await fireEvent.compositionEnd(screen.getByTestId("step-0-scene"));
+
+        // (1) が先に効いて並びが変わっても、(2) は**掴んだ手順シーンA の急所**に適用される。
+        // 数値 index を持ち回っていると手順シーンB の急所が入れ替わってしまう。
+        expect(stepScenes()).toEqual(["手順シーンB", "手順シーンA"]);
+        expect(screen.getByTestId("point-0-0-scene")).toHaveValue("急所B-1");
+        expect(screen.getByTestId("point-0-1-scene")).toHaveValue("急所B-2");
+        expect(screen.getByTestId("point-1-0-scene")).toHaveValue("急所A-2");
+        expect(screen.getByTestId("point-1-1-scene")).toHaveValue("急所A-1");
+    });
+
+    it("ハンドル上の ArrowDown / ArrowUp で 1 段移動する", async () => {
+        renderDnd();
+
+        await fireEvent.keyDown(screen.getByTestId("step-0-drag-handle"), { key: "ArrowDown" });
+        expect(stepScenes()).toEqual(["手順シーンB", "手順シーンA"]);
+
+        await fireEvent.keyDown(screen.getByTestId("step-1-drag-handle"), { key: "ArrowUp" });
+        expect(stepScenes()).toEqual(["手順シーンA", "手順シーンB"]);
+    });
+
+    it("急所ハンドル上の ArrowDown でも 1 段移動する", async () => {
+        renderDnd();
+
+        await fireEvent.keyDown(screen.getByTestId("point-0-0-drag-handle"), { key: "ArrowDown" });
+
+        expect(screen.getByTestId("point-0-0-scene")).toHaveValue("急所A-2");
+        expect(screen.getByTestId("point-0-1-scene")).toHaveValue("急所A-1");
+    });
+
+    it("先頭行の ArrowUp は順序を変えず、理由を告知する (disabled にしない)", async () => {
+        renderDnd();
+
+        await fireEvent.keyDown(screen.getByTestId("step-0-drag-handle"), { key: "ArrowUp" });
+
+        expect(stepScenes()).toEqual(["手順シーンA", "手順シーンB"]);
+        expect(screen.getByTestId("scenario-reorder-status")).toHaveTextContent(
+            "これ以上、上へは移動できません",
+        );
+    });
+
+    it("末尾行の ▼ ボタンも順序を変えず理由を告知する", async () => {
+        renderDnd();
+
+        await fireEvent.click(screen.getByTestId("step-1-move-down"));
+
+        expect(stepScenes()).toEqual(["手順シーンA", "手順シーンB"]);
+        expect(screen.getByTestId("scenario-reorder-status")).toHaveTextContent(
+            "これ以上、下へは移動できません",
+        );
+    });
+
+    it("ハンドルは disabled 属性を持たない (禁止事項 8)", () => {
+        renderDnd();
+
+        for (const id of [
+            "step-0-drag-handle",
+            "step-1-drag-handle",
+            "point-0-0-drag-handle",
+            "point-1-1-drag-handle",
+        ]) {
+            expect(screen.getByTestId(id)).not.toHaveAttribute("disabled");
+        }
+    });
+});
```

## 新規作成した実機確認記録 (Critical 2)

`devnotes/20260816-1021-drag-and-drop-reordering/ios-acceptance.md`:

```markdown
# iOS Safari 実機確認記録 (受け入れ条件 A3 / T185)

## 状態: **未実施**

| 項目 | 値 |
|---|---|
| 実施日時 | — |
| 端末 | — |
| OS バージョン | — |
| 実施者 | — |
| 結果 | — |

## なぜ空欄なのか

実装は LLM エージェントが行ったが、**エージェントは実機を持たない**。
詳細設計 §施策 7 は受け入れ条件 3 について次のように定めている:

> **実機確認記録の存在を自動テストで強制しない**。ファイルが在ることは**実機で確認した事実の
> 証明にならない**ため、存在チェックを緑にすると「機械が確認した」という誤った安心を作る。
> よって受け入れ条件 3 は**人間のレビューで見る運用**とし、記録が無ければ完了にしない。

この方針に従い、**確認していないことを確認したと書かない**。本ファイルは
「まだ埋まっていない」という事実そのものを記録として残すために置く
(ファイルが存在すること自体は何の保証でもない)。

`pnpm test` の緑は **jsdom 上の意味論と生死の固定**であって、
**iOS Safari で動く証明ではない**。同じ理由で Browser lane (Chromium + WebKit) の緑も
実機 iOS Safari の代替にはならない (`docs/supported-browsers.md` が繰り返し戒めている通り)。

## 埋める人がやること

iOS Safari の実機 (**PWA standalone 起動を含む**) で以下を確認し、
上の表に日時・端末・OS バージョン・実施者・結果を記入する。

- [ ] テイク列 (撮影 PWA) のハンドルを縦にドラッグして順序が変わる
- [ ] ハンドル**以外**を触ったときは従来どおりページがスクロールする
      (ハンドルだけに `touch-none` を付けている前提の実挙動確認)
- [ ] リスト末尾が画面外にあるとき、指を画面端に寄せると自動スクロールして落とせる
- [ ] 自動スクロール中に**指を止めたまま**離しても、見えている位置に落ちる
      (挿入位置を毎フレーム採り直している前提の実挙動確認)
- [ ] ドラッグ中に着信・アプリ切替が入っても (`pointercancel`)、
      操作が中断されるだけで順序が壊れない
- [ ] ハンドルの押しやすさ (`size-8` = 32px) が実機のタッチで許容できる
- [ ] 行が横に広がってスマホで不自然に折り返していない

あわせて **Chrome / Safari デスクトップ**でシナリオ編集をマウス操作し、
「元に戻す」で元の順序へ戻ることを確認する (受け入れ条件 4)。

## 未確認のまま先行マージした理由 (T185)

並べ替え手段は **▲▼ ボタン / ハンドルの上下キー / D&D の 3 本**あり、
**既存の ▲▼ は無変更で残している**。したがって仮に実機の D&D が期待どおり動かなくても、
利用者は従来どおりの手段で並べ替えられる (詰みを作らない)。
この非対称を理由に「iOS 対応済み」と書かないこと — 本ファイルが埋まるまで
**D&D の iOS 対応は未検証**である。
```

## 再検証結果 (すべて green)

- `pnpm test`: 145 files / **1703 tests passed** (Round 1 から +1 = 追加した回帰テスト)
- `pnpm typecheck` / `pnpm lint`: green
- PHP 側は依然として差分 0 件 (`composer test` 5416 / phpstan level 10 No errors /
  pint passed は Round 1 時点の測定から変化なし。マージ前に再実行する)

## 判定してほしいこと

1. Critical 1 の修正 (安定キーによる実行時解決) が指摘を**過不足なく**塞いでいるか。
   まだ数値 index を持ち回っている経路が並べ替えの中に残っていないか
2. `removeStep` / `removePoint` / `addPoint` の同種の弱点を
   **本タスクのスコープ外**とした判断が妥当か (T185 は当該コードを 1 文字も変更していない)。
   別タスクとして起票すべきなら、そう明記してほしい
3. Critical 2 について、**実機を持たないエージェントが取り得る最善**として
   「未実施と明記した記録を残し、blockers として人間に引き継ぐ」形が受け入れられるか。
   受け入れられないなら、マージ自体を止めるべきという判断か
4. 見送った 2 件の Suggestion (setPointerCapture の try/catch / aria-describedby) の
   見送り理由が妥当か

**全体判定を `APPROVED` または `CHANGES_REQUESTED` の 1 語で明示してください。**
