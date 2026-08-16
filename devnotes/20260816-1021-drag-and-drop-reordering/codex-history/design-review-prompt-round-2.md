# Round 2: Round 1 指摘への対応と再レビュー依頼

Round 1 の Critical 1 件・Warning 8 件・Suggestion 4 件をすべて処理しました。
対応マトリクスと、修正後の詳細設計書の全文を送ります。

**特に見てほしい点**:
1. `$effect` → `onMount` への変更が、Svelte 5 の lifecycle として妥当か
2. `finish(commit, notify)` の 2 引数化で「破棄」と「取消」を分けた契約が、
   呼び出し側 2 コンポーネントで正しく使われているか
3. 自動スクロール中の挿入位置の再計算 (`tickAutoScroll` 内) に新たな不整合が無いか
   (rAF ループの中で `onState` を毎フレーム呼ぶことの副作用を含む)
4. `run()` の戻り値を `Promise<boolean>` へ変えたことで、既存 4 呼び出し側に回帰が無いか
5. `moveItem` の `noUncheckedIndexedAccess` に関する反論が妥当か
   (本リポジトリの tsconfig は `@tsconfig/svelte/tsconfig.json` を extends し、
   base・自前とも `noUncheckedIndexedAccess` の記載が無いことを実読で確認しています。
   ただし修正案自体は採用済みです)

---

# 対応マトリクス: design-review Round 1

判定は **CHANGES_REQUESTED**（Critical 1 / Warning 8 / Suggestion 4）。
Critical と Warning はすべて処理した。1 件は事実確認の結果**一部反論**（ただし修正案自体は採用）。

## [Critical] 施策 4: `$effect` での controller 生成は多重生成リスクがある。`onMount` にせよ

- 判断: **対応する**
- 根拠: 指摘のとおり「effect 本体で `$state` を同期 read しなければ再実行されない」は
  **実装者の注意力に依存する不変条件**であり、設計としては脆い。
  D&D の controller は「マウント時に 1 度だけ作る browser-only の資源」なので、
  意図がそのまま型と API に出る `onMount` が正しい。
- 対応内容: 施策 4・5 の controller 生成を `onMount(() => { …; return () => { …destroy() }; })` に変更。
  `$effect` は使わない。あわせて「なぜ `$effect` ではないのか」を設計にコメントとして残した。

## [Warning] 施策 1: `const [moved] = next.splice(from, 1)` は `T | undefined` になり得る

- 判断: **一部反論しつつ、修正案は採用する**
- 根拠（反論部分）: 本リポジトリの `tsconfig.json` は `@tsconfig/svelte/tsconfig.json` を
  extends し、`strict: true` は有効だが **`noUncheckedIndexedAccess` は有効化されていない**
  （base・自前 config のどちらにも記載が無いことを実読で確認）。したがって現状の
  `pnpm typecheck` は指摘の形でも**落ちない**。「落ちる」という前提の説明は本リポジトリでは
  成立しない。
- 根拠（採用部分）: とはいえ、`from` の範囲検査と splice の戻り値の関係は**型では繋がっていない**。
  明示的に絞る形は (a) 将来 `noUncheckedIndexedAccess` を有効化しても壊れず、
  (b) 純関数の fail-safe 方針（範囲外は「動かさない」に倒す）をコードの形で示せる。ゼロコスト。
- 対応内容: `const moved = next[from]; if (moved === undefined) return next;` を先に置き、
  そのあと `splice` する形に変更。設計に「現行 config では型エラーにならないが、
  fail-safe の意図を型の外で担保するため明示的に絞る」と理由を明記した。

## [Warning] 施策 2: `destroy()` が `onCancel` を呼ぶため unmount 時に副作用が出る

- 判断: **対応する**
- 根拠: 妥当。unmount は「利用者が取り消した」わけではないので、同じ callback へ合流させると
  呼び出し側が両者を区別できない。将来 `onCancel` に告知や通信を足した瞬間にバグになる。
- 対応内容: `finish(commit: boolean, notify: boolean)` の 2 引数にし、
  `destroy()` は `finish(false, false)`（資源解放と `onState` のリセットのみ、`onCancel` を呼ばない）に変更。
  callback 契約に「`onCancel` は利用者由来の取消だけ。破棄では呼ばれない」と明記した。

## [Warning] 施策 2: 自動スクロール中に挿入位置が更新されず、古い位置で確定しうる

- 判断: **対応する**
- 根拠: 実害のある指摘。指を止めたまま端でスクロールさせると、行は動くのにポインタ座標は
  動かないため `pointermove` が来ない。スクロールした距離のぶんだけ挿入位置がずれた状態で
  drop できてしまう（iOS Safari で最も起きやすい）。
- 対応内容: 最後のポインタ Y (`lastClientY`) を保持し、`tickAutoScroll()` の各フレームで
  `insertionIndexFromRects(bounds(), lastClientY)` を再計算して `onState` も更新するよう変更。
  テスト計画に「自動スクロールの 1 フレームで挿入位置が更新される」ケースを追加した。

## [Warning] 施策 4: 急所 D&D の `onCommit` 後始末が本文コードに無い

- 判断: **対応する**
- 根拠: 設計書は実装の指示書なので、「実装では末尾でも同じ 2 行」という注釈で済ませたのは
  設計の欠落である。
- 対応内容: `onCommit` / `onCancel` の双方から呼ばれる `clearPointDragScope()` を定義し、
  `onCommit` は `try { … } finally { clearPointDragScope(); }` で必ず通す完成コードへ差し替えた。

## [Warning] 施策 4: `movePointTo` が `steps[stepIndex]` へ 2 回アクセスしている

- 判断: **対応する**
- 根拠: `?.` で undefined を弾いた事実は、その後の `steps[stepIndex].points` へは伝播しない。
  型の問題である以前に、間に `runSettled` の非同期な遅延実行が挟まりうる
  （IME 変換中はキューに積まれ、実行時には配列が変わっている可能性がある）ので、
  **実行時の再取得**が必要という意味でも指摘が正しい。
- 対応内容: `const step = steps[stepIndex]; if (step === undefined) return;` としたうえ、
  さらに `commitStructural` の中（= 実際に変異する時点）で**もう一度**取り直して範囲検査する形にした。
  これは Codex の指摘より一段強い対応である（IME キュー経由の遅延実行を考慮）。

## [Warning] 施策 5: PATCH の成否を待たずに「移動しました」と告知している

- 判断: **対応する**
- 根拠: 完全に正しい。失敗しても成功を読み上げるのは、スクリーンリーダ利用者にだけ
  嘘をつくことになる（視覚利用者は `role="alert"` のエラーを見る）。
- 対応内容: 既存 `run()` の戻り値を `Promise<void>` → `Promise<boolean>`（成功なら true）に変更し、
  `move()` もそれを返す。`reorderTo()` は `await` して**成功時のみ**告知する。
  失敗時は既存の `take-strip-error`（`role="alert"`）が担う（告知を二重に出さない）。
  既存の呼び出し側（`adopt` / `remove` / `downloadAndAck` / `confirmDelete`）は戻り値を
  無視するだけなので無変更で動く（`adoptFromPreview` の `error === null` 判定も現行のまま）。

## [Warning] 施策 5: 端での no-op PATCH 廃止は既存挙動変更なので期待値を明示せよ

- 判断: **対応する**
- 対応内容: 「端操作の期待 = **通信なし / busy なし / 再取得 (`onChanged`) なし / aria-live 告知あり**」
  を設計に表で固定し、同じ 4 点をテスト計画の assert に落とした。

## [Warning] 施策 6: テストで `setPointerCapture` を `undefined` にするのは型的に通りにくい

- 判断: **対応する**
- 根拠: `Element.prototype.setPointerCapture` の型は `(pointerId: number) => void` なので
  `undefined` の代入は型エラーになる。指摘のとおり。
- 対応内容: テスト側に型付き helper
  `withoutPointerCapture(run: () => void | Promise<void>): Promise<void>` を用意し、
  `Object.defineProperty(Element.prototype, "setPointerCapture", { value: undefined, configurable: true })`
  で外し、`finally` で元に戻す形へ変更した（`delete` も生の代入も使わない）。

## [Suggestion] 施策 2: drag 後に click が発火する経路を将来塞げるようにせよ

- 判断: **対応する**（型で塞ぐ）
- 対応内容: `DragHandleProps` に **`onclick` を定義しない**ことを明文化した。
  props に無いので呼び出し側は click ハンドラを付けられず、「ドラッグ後の誤 click」という
  経路自体が型で存在しない。設計に理由をコメントとして残した。

## [Suggestion] 施策 1: `toFinalIndex` の入力契約を書け

- 判断: **対応する**（契約の明記）
- 対応内容: 「`insertion` は `0..n`、`from` は `0..n-1` の正規化済み入力を前提とする。
  範囲外は下流の `moveItem` が clamp する（二重に clamp して意味を分散させない）」と doc に明記。

## [Suggestion] 施策 7: iOS 実機確認ファイルの存在を自動テストで強制するか

- 判断: **見送る**（強制しない。理由を明記する）
- 根拠: ファイルの存在は**実機で確認した事実の証明にならない**。存在チェックを緑にすると
  「機械が確認した」という誤った安心を作る（`docs/supported-browsers.md` が繰り返し戒めている
  「WebKit レーンの green を iOS Safari 対応の実証と言い換えない」と同型の誤り）。
- 対応内容: 設計に「自動テストでは強制せず、**人間のレビューで見る運用**である」と明記した。

## [Suggestion] 施策 3: ArrowUp/ArrowDown のコンポーネントテストは必須

- 判断: **対応済み**（既に施策 4・5 のテスト計画に含まれている。変更なし）


---

## 修正後の詳細設計書 (全文)

# 詳細設計: drag-and-drop-reordering (シナリオ行とテイクのドラッグ&ドロップ並べ替え)

## 使命・制約（絶対遵守）

### アプリの使命（North Star）

**AI-CUE** は、現場に既にある**作業手順書(SOP)を起点に**、AI が撮るべきカットを設計した**動画シナリオ**を生成し、そのシナリオを**スマホ(PWA)でナビゲーション撮影**することで、専門知識ゼロの現場作業者でも**標準化されたマニュアル動画**を作れるようにする。

- 「思考ゼロ・編集ゼロ」— 台本作成・撮影判断・編集の 3 ハードルを AI とナビ撮影で肩代わりする。
- 競合(OJT を撮って形式化する tebiki)と異なり、**標準作業を起点に AI が教材設計し撮影を指示する**（撮影者・教える人のスキルに品質を依存させない）。
- 熟練者の暗黙知を動画マニュアルという形式知へ変換する装置（SECI）。

> **v1 スコープ**: 字幕のみ(TTS 後回し) / 撮影は PWA(同一オリジン・セッション認証) / 動画合成は自前 ffmpeg / 単一 Default Project。

### 禁止事項

1. テストなしの実装完了報告
2. PHPStan エラーの widen(型を緩めて黙らせる)・baseline 化
3. dev DB への破壊操作(`migrate:fresh` 等)をエージェント判断で実行すること
4. `response()->json()` の直書き(DTO / JsonResource / Inertia を使う)
5. LLM 呼び出しの Prism 直呼び(`app/Prompts/` factory → `PromptDefense` → `GuardedPrompt` の 1 本道のみ)
6. prompt 文字列のコード直書き
7. 操作系 POST の応答での `redirect()->intended()`
8. **必須条件未充足を理由にボタンを disabled にする UI**(押下時にエラー表示する。DESIGN.md)
9. Artifact の使用

> 本設計は **8 に直接関係する**。新設するドラッグハンドルも既存の ▲▼ も disabled にしない。

### コーディングルール

- **PHPStan level 10** 必須（`composer phpstan`）
- **Pest** テストフレームワーク（`composer test`）
- **RefreshDatabase** + `--parallel` 並列実行（`tests/Pest.php` でグローバル適用、個別 `DatabaseTransactions` 使用禁止）
- **テストデータは必ず Factory で生成**
- **DTO + JsonResource** パターン
- **アーリーリターン** 推奨
- **コードフォーマット**: `composer fix`（Pint）/ `pnpm lint:fix`
- PHP 8.4 + Laravel 12 + Svelte 5 + Inertia.js + TypeScript
- フロント固有: Svelte 5 runes + DS token のみ (`DESIGN.md` canonical / `ds-purity` が検出)。
  component 階層は `atoms → molecules → organisms → features/{domain} → templates → pages` の
  単方向 import (`atomic-import-graph.test.ts`)。アイコンは `@lucide/svelte` のみ
  (`lucide-scoped-import.test.ts` / `svg-inline-allowlist.test.ts`)。

> **本設計は PHP を 1 行も変更しない**。よって各施策の「PHPStan 適合チェック」は
> **TypeScript strict 適合チェック**（`pnpm typecheck` = `tsc --noEmit` / `any` 不使用 /
> 素の型アサーション不使用）に読み替える。PHP 側は「変更 0 件であること」自体を
> 受け入れ条件とし、`composer phpstan` / `composer test` が**無変更のまま緑**であることを確認する。

## 概念設計リファレンス

- [conceptual-design.md](./conceptual-design.md) （Codex `conceptual-review` **Round 1 で APPROVED**）
- 受け入れ条件 A1〜A5（概念設計 §受け入れ条件）は本設計の各施策に割り付ける:

| 受け入れ条件 | 割り付け先 |
|---|---|
| A1 disabled を増やさない / 端は告知する | 施策 3・4・5 |
| A2 ポインタ状態を必ず解放する | 施策 2（単一出口 `finish()`）・施策 4・5（`$effect` の cleanup） |
| A3 iOS Safari 実機確認を devnotes に記録 | 施策 7（受け入れ手順） |
| A4 共通化の境界を越えない | 施策 1・2（lib は lifecycle / 挿入位置計算 / moveItem のみ） |
| A5 挿入 index と最終 index を分ける | 施策 1（関数分離）・施策 7（off-by-one テスト） |

## 施策一覧

| # | 施策名 | 変更ファイル | 優先度 |
|---|--------|------------|--------|
| 1 | 並べ替え計算の純関数モジュール | `resources/js/lib/dnd/list-reorder.ts` (新規) | 高 |
| 2 | Pointer Events のドラッグ制御 (Svelte 非依存の素 TS) | `resources/js/lib/dnd/pointer-drag.ts` (新規) | 高 |
| 3 | ドラッグハンドル atom | `resources/js/components/atoms/DragHandle.svelte` / `DragHandle.types.ts` (新規) | 高 |
| 4 | シナリオ編集への配線 (手順行・急所行) | `resources/js/components/features/manual/ScenarioEditor.svelte` | 高 |
| 5 | 撮影 PWA テイク列への配線 | `resources/js/components/features/capture/TakeStrip.svelte` | 高 |
| 6 | jsdom に無い pointer capture のスタブ | `tests/js/setup.ts` | 中 |
| 7 | テスト (純関数 2 本 + コンポーネント 2 本) | `tests/js/lib/dnd/*.test.ts` / 既存 2 テストへ追記 | 高 |

---

## 施策 1: 並べ替え計算の純関数モジュール

### 変更箇所

- ファイル: `resources/js/lib/dnd/list-reorder.ts` (**新規**)

### 波及変更

- TypeScript 型定義: 本ファイルが `RowBounds` を新規 export する。他型への影響なし
- API Resource/DTO: **なし**（サーバ変更なし）
- テストファイル: `tests/js/lib/dnd/list-reorder.test.ts` (新規)
- 走査系テストへの影響: `ds-purity` は `resources/js` 配下の `.ts` も走査するが、
  本ファイルは class 文字列を持たないため無関係。`atomic-import-graph` 上は `util` 層

### 現行コード

存在しない（新規）。同等の計算は現在 `ScenarioEditor.svelte` の隣接 swap に埋め込まれている:

```svelte
function moveStep(index: number, delta: -1 | 1): void {
    const next = index + delta;
    if (next < 0 || next >= steps.length) return; // 境界: 履歴も積まない
    runSettled(() =>
        commitStructural(() => {
            [steps[index], steps[next]] = [steps[next], steps[index]];
        }),
    );
}
```

### 変更後コード

```ts
/**
 * リスト並べ替えの純関数 (DOM に触れない)。
 *
 * D&D は DOM イベントの連鎖でテストしづらい。そこで「どこに落ちたら何番目になるか」の
 * 意味論だけをここに閉じ込め、Vitest で網羅する (概念設計 D3)。
 *
 * **index の語彙は 2 つある**。混同すると off-by-one になるため関数を分ける (受け入れ条件 A5):
 * - **挿入 index (insertion index)**: 行と行の隙間を数えた `0..n` の値。n 行のリストには
 *   隙間が n+1 個ある。「どの隙間に落としたか」を表す。
 * - **最終 index (final index)**: 移動が終わった後の配列での `0..n-1` の値。
 *   撮影 PWA がサーバへ渡す `position` はこちらである
 *   (`CaptureTakeService::reorderWithinCut` は対象を除いた配列へ splice するため、
 *   結果として「移動後の全体配列での 0 始まり index」と一致する)。
 */

/** 行の上下位置 (getBoundingClientRect の実測値。viewport 座標) */
export interface RowBounds {
    readonly top: number;
    readonly height: number;
}

/**
 * 要素を from から to (最終 index) へ動かした**新しい配列**を返す。入力は変更しない。
 * 範囲外・非整数は「動かさない」に倒す (fail-safe。呼び出し側で throw させない)。
 *
 * 取り出しを分割代入 (`const [moved] = next.splice(from, 1)`) にしないのは意図的である。
 * 本リポジトリの tsconfig は `noUncheckedIndexedAccess` を有効にしていないため
 * 分割代入でも型エラーにはならないが、「from を範囲検査したこと」と
 * 「splice が 1 要素返すこと」は型の上では繋がっていない。
 * 明示的に絞っておけば、将来そのフラグを有効化しても壊れず、
 * fail-safe の意図がコードの形として残る。
 *
 * 前提: `T` に `undefined` を含めない (本アプリの用途は行オブジェクトの配列)。
 * 含めた場合、その要素は「動かさない」に倒れる。
 */
export function moveItem<T>(list: readonly T[], from: number, to: number): T[] {
    const next = [...list];
    if (!Number.isInteger(from) || !Number.isInteger(to)) return next;
    if (from < 0 || from >= next.length) return next;
    const moved = next[from];
    if (moved === undefined) return next;
    const clamped = Math.min(Math.max(to, 0), next.length - 1);
    if (clamped === from) return next;
    next.splice(from, 1);
    next.splice(clamped, 0, moved);
    return next;
}

/**
 * ポインタの Y 座標 (viewport 座標) から**挿入 index** (0..rows.length) を決める。
 * 各行の中点より上なら「その行の前」、下ならさらに次の行を見る。
 *
 * rows は**表示順**で、掴んでいる行自身も含めて渡す (DOM から抜かないため)。
 * スクロールしても `getBoundingClientRect()` を採り直せば viewport 座標系で
 * ポインタ座標と一致する (受け入れ条件 A2)。
 */
export function insertionIndexFromRects(rows: readonly RowBounds[], pointerY: number): number {
    for (let i = 0; i < rows.length; i += 1) {
        if (pointerY < rows[i].top + rows[i].height / 2) return i;
    }
    return rows.length;
}

/**
 * 挿入 index → 最終 index。
 * 掴んだ行自身がいったんリストから抜けるぶん、掴んだ位置より後ろの隙間は 1 つ手前へ詰まる。
 * 掴んだ行の前後 2 つの隙間 (from と from+1) はどちらも「動かさない」= from になる。
 *
 * **入力契約**: `insertion` は `0..n` (insertionIndexFromRects の出力)、`from` は `0..n-1` の
 * 正規化済みの値を前提とする。範囲外の clamp はここでは行わない
 * (下流の `moveItem` が 1 箇所で clamp する。2 箇所で丸めると意味が分散する)。
 */
export function toFinalIndex(insertion: number, from: number): number {
    return insertion > from ? insertion - 1 : insertion;
}
```

### TypeScript strict 適合チェック（PHPStan 適合チェックの読み替え）

- [x] 戻り値の型が明示されている（`T[]` / `number`）
- [x] `any` / 素の型アサーション（`as`）を使っていない
- [x] 入力を破壊しない（`readonly T[]` を受け、`[...list]` を返す）
- [x] 範囲外入力で throw せず定義済みの値へ倒す（fail-safe）
- [x] DOM API に触れない（jsdom 非依存で単体テストできる）

### テスト計画

- [x] 新規 `tests/js/lib/dnd/list-reorder.test.ts`
  - `moveItem`: 前方移動 / 後方移動 / 端 → 端 / `from === to` の no-op /
    範囲外 from / 非整数 / 空配列 / **入力配列が変更されていないこと** (immutability)
  - `insertionIndexFromRects`: 空配列 → 0 / 1 行目の中点の上 → 0 / 中点の下 → 1 /
    最終行の中点より下 → `rows.length` / 行の高さが不揃いでも中点で切り替わること
  - `toFinalIndex`: `insertion <= from` は素通し / `insertion > from` は 1 減 /
    **`from` と `from+1` がどちらも `from` になる**こと
  - **合成テスト (A5 の本丸)**: 4 要素の配列に対し、全 (from, insertion) の組み合わせで
    `moveItem(list, from, toFinalIndex(insertion, from))` の結果が
    「手で並べた期待値」と一致することを表駆動で検証する（off-by-one の恒久回帰）
- [x] 個別の `DatabaseTransactions` を使っていない（JS テストのため対象外）

### リスク

- 挿入 index / 最終 index の語彙が実装者に伝わらないと再び混同が起きる。
  → 関数名・doc コメント・表駆動テストの 3 点で固定する。

---

## 施策 2: Pointer Events のドラッグ制御

### 変更箇所

- ファイル: `resources/js/lib/dnd/pointer-drag.ts` (**新規**)

### 波及変更

- TypeScript 型定義: `PointerDragCallbacks` / `PointerDragController` / `PointerDragState` を新規 export
- API Resource/DTO: **なし**
- テストファイル: `tests/js/lib/dnd/pointer-drag.test.ts` (新規)
- `tests/js/setup.ts`: pointer capture のスタブ（施策 6）

### 現行コード

存在しない（新規）。現状 `resources/js` 配下に `pointerdown` / `draggable` / `touch-action` を
使う実装は 1 件も無い（`rg` で確認済み）。

### 変更後コード

```ts
/**
 * Pointer Events による 1 軸 (縦) の並べ替えドラッグ制御。**Svelte に依存しない素の TS**。
 *
 * HTML5 Drag and Drop API は iOS Safari のタッチで発火しないため採らない (概念設計 D1)。
 * 撮影 PWA の主戦場は iOS Safari (docs/supported-browsers.md) なので、
 * マウス・タッチ・ペンを 1 系統で扱える Pointer Events に一本化する。
 *
 * **共通化の境界** (受け入れ条件 A4): ここに置くのは
 * (i) ポインタの生死管理 (ii) 挿入位置の算出 (iii) 端での自動スクロール だけである。
 * 保存経路・文言・aria-live メッセージ・見た目・サーバへ渡す position 変換は
 * 呼び出し側 (feature component) に残す。
 */
import { insertionIndexFromRects, toFinalIndex, type RowBounds } from "./list-reorder";

/** ドラッグ開始とみなす最小移動量 (px)。タップ/クリックをドラッグにしない */
export const DRAG_ACTIVATION_DISTANCE = 6;
/** 画面端からこの距離に入ったら自動スクロールする (px) */
export const AUTO_SCROLL_EDGE = 64;
/** 自動スクロールの 1 フレームあたりの移動量 (px) */
export const AUTO_SCROLL_STEP = 12;

/** 表示用の状態。UI (影ではなく border と不透明度) の描画にのみ使う */
export interface PointerDragState {
    /** 掴んでいる行の index。ドラッグしていなければ null */
    readonly activeIndex: number | null;
    /** 落とし先の隙間 (挿入 index)。ドラッグしていなければ null */
    readonly insertionIndex: number | null;
}

export interface PointerDragCallbacks {
    /** 表示順の行要素を返す (呼び出し側が DOM から採る)。毎回の pointermove で採り直す */
    readonly rows: () => ReadonlyArray<HTMLElement>;
    /** 表示状態の変化通知 */
    readonly onState: (state: PointerDragState) => void;
    /** 確定。`to` は**最終 index**。`from === to` のときは呼ばれない */
    readonly onCommit: (from: number, to: number) => void;
    /**
     * 取消。**利用者由来の取消だけ**を通知する (Esc / pointercancel / 位置が変わらない drop)。
     * `destroy()` (コンポーネント破棄) では**呼ばれない** — 破棄は利用者の意思ではないので、
     * ここに告知や通信を足したときに unmount で誤発火しないようにするためである。
     */
    readonly onCancel?: () => void;
}

export interface PointerDragController {
    /** ハンドルの pointerdown から呼ぶ */
    readonly start: (index: number, event: PointerEvent) => void;
    /** 現在ドラッグ中か (呼び出し側が click 抑止などに使う) */
    readonly isDragging: () => boolean;
    /** コンポーネント破棄時に必ず呼ぶ (受け入れ条件 A2) */
    readonly destroy: () => void;
}

export function createPointerDrag(callbacks: PointerDragCallbacks): PointerDragController {
    let pointerId: number | null = null;
    let handle: HTMLElement | null = null;
    let fromIndex = 0;
    let startY = 0;
    /** 閾値を超えて実際にドラッグが始まったか */
    let activated = false;
    let insertion: number | null = null;
    let scrollFrame: number | null = null;
    let scrollDelta = 0;
    /** 直近のポインタ Y (viewport 座標)。自動スクロール中の再計算に使う */
    let lastClientY = 0;

    function bounds(): RowBounds[] {
        return callbacks.rows().map((el): RowBounds => {
            const rect = el.getBoundingClientRect();
            return { top: rect.top, height: rect.height };
        });
    }

    function stopAutoScroll(): void {
        if (scrollFrame !== null && typeof cancelAnimationFrame === "function") {
            cancelAnimationFrame(scrollFrame);
        }
        scrollFrame = null;
        scrollDelta = 0;
    }

    /**
     * 自動スクロールの 1 フレーム。
     * **スクロールしたら挿入位置を必ず採り直す**。指を止めたまま端でスクロールさせると
     * `pointermove` は来ないのに行だけが動くため、採り直さないと古い挿入位置のまま
     * drop できてしまう (iOS Safari で最も起きやすい。design-review R1 の指摘)。
     */
    function tickAutoScroll(): void {
        scrollFrame = null;
        if (pointerId === null || scrollDelta === 0) return;
        window.scrollBy(0, scrollDelta);
        insertion = insertionIndexFromRects(bounds(), lastClientY);
        callbacks.onState({ activeIndex: fromIndex, insertionIndex: insertion });
        scrollFrame = requestAnimationFrame(tickAutoScroll);
    }

    /**
     * 画面端に近ければスクロールを回す。
     * requestAnimationFrame が無い環境 (jsdom 等) では自動スクロールだけ働かない。
     * 並べ替えそのものは動くので、機能検出で静かに劣化させる (誇張しない)。
     */
    function updateAutoScroll(clientY: number): void {
        if (typeof requestAnimationFrame !== "function") return;
        const height = window.innerHeight;
        const next =
            clientY < AUTO_SCROLL_EDGE
                ? -AUTO_SCROLL_STEP
                : clientY > height - AUTO_SCROLL_EDGE
                  ? AUTO_SCROLL_STEP
                  : 0;
        scrollDelta = next;
        if (next === 0) {
            stopAutoScroll();
            return;
        }
        if (scrollFrame === null) scrollFrame = requestAnimationFrame(tickAutoScroll);
    }

    /**
     * **すべての終了経路が合流する唯一の出口** (受け入れ条件 A2)。
     * pointerup / pointercancel / Escape / destroy はここへ入る。
     * 資源 (pointer capture / rAF) を先に解放してから callback を呼ぶので、
     * callback 内で再入しても状態は壊れない。
     *
     * @param commit true なら位置が変わっていれば onCommit する
     * @param notify false なら onCancel を呼ばない (destroy 専用。
     *        破棄は利用者の取消ではないため、告知や通信を伴う onCancel を発火させない)
     */
    function finish(commit: boolean, notify: boolean): void {
        if (pointerId === null) return;
        const wasActivated = activated;
        const target = insertion;
        const from = fromIndex;
        if (
            handle !== null &&
            typeof handle.releasePointerCapture === "function" &&
            typeof handle.hasPointerCapture === "function" &&
            handle.hasPointerCapture(pointerId)
        ) {
            handle.releasePointerCapture(pointerId);
        }
        pointerId = null;
        handle = null;
        activated = false;
        insertion = null;
        stopAutoScroll();
        callbacks.onState({ activeIndex: null, insertionIndex: null });
        if (!commit || !wasActivated || target === null) {
            if (notify) callbacks.onCancel?.();
            return;
        }
        const to = toFinalIndex(target, from);
        if (to === from) {
            if (notify) callbacks.onCancel?.();
            return;
        }
        callbacks.onCommit(from, to);
    }

    function onPointerMove(event: PointerEvent): void {
        if (pointerId === null || event.pointerId !== pointerId) return;
        lastClientY = event.clientY;
        if (!activated) {
            if (Math.abs(event.clientY - startY) < DRAG_ACTIVATION_DISTANCE) return;
            activated = true;
        }
        // ハンドルの touch-action:none と併せて、スクロール/テキスト選択との競合を断つ
        event.preventDefault();
        insertion = insertionIndexFromRects(bounds(), event.clientY);
        updateAutoScroll(event.clientY);
        callbacks.onState({ activeIndex: fromIndex, insertionIndex: insertion });
    }

    function onPointerUp(event: PointerEvent): void {
        if (pointerId === null || event.pointerId !== pointerId) return;
        finish(true, true);
    }

    function onPointerCancel(event: PointerEvent): void {
        if (pointerId === null || event.pointerId !== pointerId) return;
        finish(false, true);
    }

    function onKeyDown(event: KeyboardEvent): void {
        if (pointerId !== null && event.key === "Escape") finish(false, true);
    }

    // listener は生成時に 1 度だけ張り destroy で外す (start/finish のたびに張り替えない)。
    // capture が使えない環境でも window で拾えるよう、ハンドルではなく window に張る。
    window.addEventListener("pointermove", onPointerMove, { passive: false });
    window.addEventListener("pointerup", onPointerUp);
    window.addEventListener("pointercancel", onPointerCancel);
    window.addEventListener("keydown", onKeyDown);

    return {
        start(index: number, event: PointerEvent): void {
            if (pointerId !== null) return; // 2 本目の指は無視 (多点ドラッグは提供しない)
            if (event.pointerType === "mouse" && event.button !== 0) return; // 左ボタンのみ
            const target = event.currentTarget;
            handle = target instanceof HTMLElement ? target : null;
            pointerId = event.pointerId;
            fromIndex = index;
            startY = event.clientY;
            lastClientY = event.clientY;
            activated = false;
            insertion = null;
            // pointer capture が無い環境 (jsdom / 一部の古い WebKit) でも
            // window の listener で同じ callback 契約のまま完走する (受け入れ条件 A2)
            if (handle !== null && typeof handle.setPointerCapture === "function") {
                handle.setPointerCapture(event.pointerId);
            }
        },
        isDragging(): boolean {
            return pointerId !== null && activated;
        },
        destroy(): void {
            // 進行中のドラッグを畳むが onCancel は呼ばない (破棄は利用者の取消ではない)
            finish(false, false);
            window.removeEventListener("pointermove", onPointerMove);
            window.removeEventListener("pointerup", onPointerUp);
            window.removeEventListener("pointercancel", onPointerCancel);
            window.removeEventListener("keydown", onKeyDown);
        },
    };
}
```

### TypeScript strict 適合チェック

- [x] 戻り値の型が明示されている（全関数）
- [x] `any` を使わない。`event.currentTarget` は `instanceof HTMLElement` で絞る（素の `as` なし）
- [x] `null` 安全（`pointerId === null` の早期 return を全ハンドラの先頭に置く）
- [x] 省略可能な callback は `?.()` で呼ぶ
- [x] ブラウザ API は機能検出する（`setPointerCapture` / `hasPointerCapture` /
      `releasePointerCapture` / `requestAnimationFrame` / `cancelAnimationFrame`）
- [x] `window` を module 読み込み時に触らない（`createPointerDrag` を呼んだ時だけ触るので
      SSR/Node 読み込みで落ちない）

### テスト計画

- [x] 新規 `tests/js/lib/dnd/pointer-drag.test.ts`（jsdom 上で `PointerEvent` を直接 dispatch）
  - 閾値未満の移動では `onCommit` も `onState(active)` も起きない（タップがドラッグにならない）
  - 閾値超え → `onState` が `{activeIndex, insertionIndex}` を通知する
  - `pointerup` で `onCommit(from, to)` が**最終 index** で 1 回だけ呼ばれる
  - 位置が変わらない drop は `onCommit` ではなく `onCancel`
  - `pointercancel` / `Escape` は `onCommit` を呼ばず `onCancel` を呼ぶ
  - **異なる `pointerId` の move/up を無視する**（2 本目の指で確定しない）
  - `destroy()` 後に `pointermove` を投げても callback が呼ばれない（listener 解放の証明）
  - **`destroy()` を drag 中に呼ぶと `onCommit` も `onCancel` も呼ばれず、
    `onState` だけが `{null, null}` にリセットされる**（破棄と取消を区別する契約の固定）
  - **自動スクロール**: `requestAnimationFrame` を `vi.stubGlobal` で即時実行に差し替え、
    `window.scrollBy` を spy して行の `getBoundingClientRect` をスクロール分ずらすと、
    `pointermove` を出さなくても `onState` の `insertionIndex` が更新されること
    （指を止めたまま端でスクロールしたときの stale 挿入位置の回帰防止）
  - `setPointerCapture` が**無い**環境でも一連の流れが完走する
    （施策 6 の helper `withoutPointerCapture()` で 1 ケースだけ外して検証する）
- [x] 個別の `DatabaseTransactions` を使っていない（JS テストのため対象外）

### リスク

| リスク | 対応 |
|---|---|
| `window` に張った listener が漏れる | `destroy()` で必ず外す。テストで「destroy 後は callback が来ない」ことを固定する |
| rAF の自動スクロールが止まらない | `finish()` が必ず `stopAutoScroll()` を通る。`pointerId === null` なら tick 自身も自走を止める |
| pointer capture が効かず、ハンドル外へ出るとイベントが切れる | listener を `window` に張っているため capture の有無に依存しない（capture は補助） |
| `preventDefault()` がスクロールを殺しすぎる | `preventDefault` はドラッグが**確定してから**(閾値超え後)しか呼ばない。ハンドル以外は `touch-action` を変えないので通常スクロールは無傷 |
| iOS Safari の実挙動が jsdom と異なる | A3: 実機確認を devnotes に記録する。自動テストの緑を「iOS 対応の実証」と書かない |
| 端スクロール中に指を止めて drop すると古い挿入位置で確定する | `tickAutoScroll()` が毎フレーム `lastClientY` で挿入位置を採り直す。テストで固定 |
| unmount 時の `onCancel` が告知や通信を誤発火させる | `finish(commit, notify)` の `notify=false` で `destroy()` からは呼ばない。契約を型 doc とテストで固定 |
| ドラッグ後にハンドルの click が発火して別の操作が走る | `DragHandleProps` に `onclick` を**定義しない**（施策 3）。経路が型で存在しない |

---

## 施策 3: ドラッグハンドル atom

### 変更箇所

- ファイル: `resources/js/components/atoms/DragHandle.svelte` (**新規**)
- ファイル: `resources/js/components/atoms/DragHandle.types.ts` (**新規**)

### 波及変更

- TypeScript 型定義: `DragHandleProps` を新規 export（既存 atom の `Badge.types.ts` /
  `Button.types.ts` と同じ companion 型ファイル方式に合わせる）
- API Resource/DTO: **なし**
- テストファイル: 単体テストは置かず、施策 4・5 のコンポーネントテストで挙動を検証する
  （atom 単体では「押しても何も起きない」ため、意味のある assert は配線側にしか無い）
- 走査系テスト: `atomic-import-graph`（atom は token/util/external のみ import 可 →
  `@lucide/svelte` は external、自身の `.types.ts` は相対 import で同層 = 適合）、
  `lucide-scoped-import` / `svg-inline-allowlist`（Lucide 経由のみ）、`ds-purity`（token のみ）

### 現行コード

存在しない（新規）。既存 `Button` atom は `onpointerdown` / `onkeydown` を prop に持たないため、
**共有 atom である `Button` に prop を足すのではなく**、専用 atom を新設する
（`Button` への prop 追加は全画面が影響範囲になるのに対し、ここで必要なのは
「掴むための小さな取っ手」という別の役割である。思考原則 4）。

### 変更後コード

`resources/js/components/atoms/DragHandle.types.ts`:

```ts
/**
 * 並べ替えの取っ手 (DragHandle) の props。
 * 「掴む」ことが役割であり、押しても何も起きない点で Button とは別物なので atom を分ける。
 *
 * **`onclick` は意図的に定義しない**。ドラッグの後には click が発火しうるため、
 * click ハンドラを付けられる口を残すと「ドラッグしたのに別の操作が走る」経路が生まれる。
 * props に無ければ呼び出し側は付けられない = 型で経路を消す (design-review R1)。
 */
export interface DragHandleProps {
    /**
     * 何を掴んでいるかの読み上げ。
     * 例: 「手順 2 の並び順を変更 (ドラッグ、または上下キー)」
     */
    ariaLabel: string;
    /** ドラッグ開始 (PointerDragController.start へ中継する) */
    onpointerdown: (event: PointerEvent) => void;
    /** キーボードでの 1 段移動 (ArrowUp / ArrowDown)。呼び出し側が既存の移動関数へ写す */
    onkeydown?: (event: KeyboardEvent) => void;
    testId?: string;
    class?: string;
}
```

`resources/js/components/atoms/DragHandle.svelte`:

```svelte
<script lang="ts">
    import { GripVertical } from "@lucide/svelte";
    import type { DragHandleProps } from "./DragHandle.types";

    let {
        ariaLabel,
        onpointerdown,
        onkeydown,
        testId,
        class: extraClass = "",
    }: DragHandleProps = $props();

    // 小コントロール → rounded-sm (DESIGN.md §Shapes)。影・scale は使わない (§Elevation)。
    // touch-none: ハンドル上のタッチをブラウザのスクロールに奪われないようにする
    //   (これを付けないと iOS Safari で縦ドラッグがページスクロールになる)。
    // select-none: ドラッグ中のテキスト選択を抑止する。
    // **disabled にはしない** (禁止事項 8 / 受け入れ条件 A1)。
    const computedClass = $derived(
        [
            "inline-flex size-8 shrink-0 cursor-grab touch-none items-center justify-center",
            "rounded-sm border border-transparent text-text-secondary select-none",
            "transition-colors duration-150",
            "hover:border-border-strong hover:text-text",
            "focus-visible:ring-3 focus-visible:ring-primary/35 focus-visible:outline-none",
            "active:cursor-grabbing",
            extraClass,
        ]
            .filter(Boolean)
            .join(" "),
    );
</script>

<button
    type="button"
    class={computedClass}
    aria-label={ariaLabel}
    data-testid={testId}
    {onpointerdown}
    {onkeydown}
>
    <GripVertical class="size-4" aria-hidden="true" />
</button>
```

### TypeScript strict 適合チェック

- [x] props は `DragHandleProps` で型付け（`$props()` の暗黙 any なし）
- [x] `any` / 素の型アサーションなし
- [x] `class` を `class: extraClass` で受ける（既存 atom と同じ命名）
- [x] `ds-purity` 抵触なし（raw palette / hex / 静的 inline style / shadow / gradient /
      scale / 素の rounded / raw text-size をいずれも含まない）

### テスト計画

- [x] 施策 4・5 のコンポーネントテストで、`data-testid` によるハンドルの実在、
      `aria-label` の文言、`ArrowUp` / `ArrowDown` の動作を検証する
- [x] `disabled` 属性を一切持たないこと（禁止事項 8 の回帰防止）を
      ScenarioEditor / TakeStrip 双方のテストで assert する

### リスク

| リスク | 対応 |
|---|---|
| focus できるのに何も起きないコントロールになる | `ArrowUp`/`ArrowDown` で既存の 1 段移動を必ず呼ぶ（受け入れ条件 A1/D6） |
| `touch-none` が広がりすぎてスクロールを殺す | ハンドル要素**だけ**に付ける。行やリストには付けない |
| icon-only ボタンの操作対象が小さすぎる（タッチ） | `size-8` (32px) を最低ラインとし、実機確認 (A3) で押しにくさを確認する |

---

## 施策 4: シナリオ編集への配線

### 変更箇所

- ファイル: `resources/js/components/features/manual/ScenarioEditor.svelte`
  - L224-244（`moveStep` / `movePoint`）
  - L841-951（手順 `<ol>` と急所 `<ol>` の markup）
  - script 冒頭の import 群（L1-32）

### 波及変更

- TypeScript 型定義: **変更なし**（`DraftStep` / `DraftPoint` / `ScenarioDocument` は無変更）
- Inertia Props: **変更なし**（`projectId` / `manualId` / `scenario` の 3 props は無変更）
- API Resource / DTO: **変更なし**（PUT payload は `payloadSteps()` のまま。
  `sort_order` / `parent_cut_id` / `type` はサーバ導出のままで、**順序は配列順が唯一の表現**）
- テストファイル: `tests/js/components/features/manual/ScenarioEditor.test.ts` に追記
- 履歴 util: `resources/js/lib/manual/scenario-history.ts` は**変更なし**
  （並べ替えは既存 `commitStructural` を通るので履歴形式が変わらない）

### 現行コード

```svelte
/** ▲▼ 並べ替え (同一スコープ内のみ。階層をまたぐ移動は提供しない) */
function moveStep(index: number, delta: -1 | 1): void {
    const next = index + delta;
    if (next < 0 || next >= steps.length) return; // 境界: 履歴も積まない
    runSettled(() =>
        commitStructural(() => {
            [steps[index], steps[next]] = [steps[next], steps[index]];
        }),
    );
}

function movePoint(stepIndex: number, index: number, delta: -1 | 1): void {
    const points = steps[stepIndex].points;
    const next = index + delta;
    if (next < 0 || next >= points.length) return;
    runSettled(() =>
        commitStructural(() => {
            [points[index], points[next]] = [points[next], points[index]];
        }),
    );
}
```

markup（抜粋）:

```svelte
<ol class="mt-4 flex flex-col gap-4" data-testid="scenario-steps">
    {#each steps as step, stepIndex (step.clientKey)}
        <li>
            <Card padding="md">
                <div class="flex items-start justify-between gap-2">
                    <h3 class="text-body font-medium text-text">手順 {stepIndex + 1}</h3>
                    <div class="flex items-center gap-1">
                        <Button ... onclick={() => moveStep(stepIndex, -1)} testId="step-{stepIndex}-move-up"> ...
```

### 変更後コード

**(a) import 追加**

```svelte
import { onMount, tick } from "svelte"; // tick は既存 import。onMount を追加
import DragHandle from "@/components/atoms/DragHandle.svelte";
import { moveItem } from "@/lib/dnd/list-reorder";
import { createPointerDrag, type PointerDragState } from "@/lib/dnd/pointer-drag";
```

> `verbatimModuleSyntax: true` のため、型は必ず `type` 修飾子付きで import する
> （`import { createPointerDrag, type PointerDragState }` の形）。

**(b) 移動関数を「任意位置移動」に一本化し、▲▼ はその特殊形にする**

```svelte
/**
 * 並べ替えは「任意位置への移動」1 本に集約する。
 * ▲▼ ボタン・ハンドルのキーボード操作・D&D のすべてがここへ合流するので、
 * undo/redo 履歴・dirty 判定・IME ゲート (runSettled) との整合が 1 箇所で保たれる。
 * 保存 payload は配列順がそのまま順序 (sort_order はサーバ採番) なので、
 * ここで順序表現を作る必要はない。
 */
function moveStepTo(from: number, to: number): void {
    if (from === to || to < 0 || to >= steps.length) return;
    runSettled(() =>
        commitStructural(() => {
            // 実行時点で再検査する (runSettled は IME 変換中に実行を遅らせる)
            if (from >= steps.length || to >= steps.length) return;
            steps = moveItem(steps, from, to);
        }),
    );
    announce(`手順 ${from + 1} を ${to + 1} 番目に移動しました`);
}

/**
 * 急所の移動。
 * **変異の直前にもう一度 step を取り直す**のは意図的である。`runSettled` は IME 変換中に
 * 実行を compositionend まで遅らせるため、呼び出し時点の参照が実行時点でも有効とは限らない。
 * 「呼び出し時点の検査」と「実行時点の検査」を両方置く (design-review R1 の指摘より一段強い対応)。
 */
function movePointTo(stepIndex: number, from: number, to: number): void {
    const step = steps[stepIndex];
    if (step === undefined) return;
    if (from === to || to < 0 || to >= step.points.length) return;
    runSettled(() =>
        commitStructural(() => {
            const current = steps[stepIndex];
            if (current === undefined) return;
            if (from >= current.points.length || to >= current.points.length) return;
            current.points = moveItem(current.points, from, to);
        }),
    );
    announce(`急所 ${stepIndex + 1}-${from + 1} を ${to + 1} 番目に移動しました`);
}

/** ▲▼ (既存 UI。挙動は現行と同じ = 1 段移動 + 端は無変更) */
function moveStep(index: number, delta: -1 | 1): void {
    const next = index + delta;
    if (next < 0 || next >= steps.length) {
        // disabled にはしない (禁止事項 8)。押されたら「なぜ動かないか」を告知する
        announce(delta < 0 ? "これ以上、上へは移動できません" : "これ以上、下へは移動できません");
        return;
    }
    moveStepTo(index, next);
}

function movePoint(stepIndex: number, index: number, delta: -1 | 1): void {
    const step = steps[stepIndex];
    if (step === undefined) return;
    const next = index + delta;
    if (next < 0 || next >= step.points.length) {
        announce(delta < 0 ? "これ以上、上へは移動できません" : "これ以上、下へは移動できません");
        return;
    }
    movePointTo(stepIndex, index, next);
}
```

**(c) 読み上げ領域 (aria-live)**

```svelte
/** 並べ替え結果のスクリーンリーダ告知 (視覚的には出さない) */
let reorderStatus = $state("");
function announce(message: string): void {
    reorderStatus = message;
}
```

```svelte
<p class="sr-only" aria-live="polite" data-testid="scenario-reorder-status">{reorderStatus}</p>
```

**(d) ドラッグ制御の生成と破棄**

```svelte
/** ドラッグ表示状態 (手順リスト / 急所リストで別々に持つ) */
let stepDrag = $state<PointerDragState>({ activeIndex: null, insertionIndex: null });
let pointDrag = $state<PointerDragState>({ activeIndex: null, insertionIndex: null });
/** 急所ドラッグ中の親手順 index (急所は手順をまたがないので 1 つで足りる) */
let pointDragStep = $state<number | null>(null);

/** 手順 <ol> / ドラッグ中の急所 <ol> の実体 (行の実測に使う) */
let stepListEl = $state<HTMLOListElement | null>(null);
let pointListEl: HTMLOListElement | null = null; // ドラッグ中のみ有効 (非 reactive で足りる)

function directRows(list: HTMLElement | null): HTMLElement[] {
    if (list === null) return [];
    return [...list.querySelectorAll<HTMLElement>(":scope > li")];
}

// controller は**マウント時に 1 度だけ**作り、破棄時に必ず destroy する (受け入れ条件 A2)。
// $effect ではなく onMount を使う: これは「派生状態」ではなく
// 「ブラウザ資源 (window listener) をマウント期間だけ持つ」ことであり、
// $effect だと「本体で $state を同期 read しなければ再実行されない」という
// 実装者の注意力に依存した不変条件になる (多重生成のリスク。design-review R1 Critical)。
let stepDragCtl: ReturnType<typeof createPointerDrag> | null = null;
let pointDragCtl: ReturnType<typeof createPointerDrag> | null = null;

/** 急所ドラッグのスコープ (どの手順の <ol> を掴んでいるか) を必ず捨てる */
function clearPointDragScope(): void {
    pointListEl = null;
    pointDragStep = null;
}

onMount(() => {
    stepDragCtl = createPointerDrag({
        rows: () => directRows(stepListEl),
        onState: (state) => (stepDrag = state),
        onCommit: (from, to) => moveStepTo(from, to),
    });
    pointDragCtl = createPointerDrag({
        rows: () => directRows(pointListEl),
        onState: (state) => (pointDrag = state),
        onCommit: (from, to) => {
            // 確定でも取消でもスコープは必ず捨てる (finally で漏れを塞ぐ)
            try {
                if (pointDragStep !== null) movePointTo(pointDragStep, from, to);
            } finally {
                clearPointDragScope();
            }
        },
        onCancel: clearPointDragScope,
    });
    return () => {
        stepDragCtl?.destroy();
        pointDragCtl?.destroy();
        stepDragCtl = null;
        pointDragCtl = null;
        clearPointDragScope();
    };
});

function onStepHandleDown(index: number, event: PointerEvent): void {
    stepDragCtl?.start(index, event);
}

/** 急所は「掴んだ行が属する <ol>」を先に確定してから開始する (手順をまたがないため) */
function onPointHandleDown(stepIndex: number, pointIndex: number, event: PointerEvent): void {
    const target = event.currentTarget;
    pointListEl =
        target instanceof HTMLElement ? target.closest<HTMLOListElement>("ol[data-point-list]") : null;
    pointDragStep = stepIndex;
    pointDragCtl?.start(pointIndex, event);
}

/** ハンドル上のキーボード操作 (▲▼ と同じ 1 段移動へ写す) */
function onHandleKeydown(event: KeyboardEvent, move: (delta: -1 | 1) => void): void {
    if (event.key !== "ArrowUp" && event.key !== "ArrowDown") return;
    event.preventDefault();
    move(event.key === "ArrowUp" ? -1 : 1);
}
```

**(e) markup（手順リスト。急所リストも同型）**

```svelte
<ol
    class="mt-4 flex flex-col gap-4 {stepDrag.activeIndex !== null ? 'select-none' : ''}"
    data-testid="scenario-steps"
    bind:this={stepListEl}
>
    {#each steps as step, stepIndex (step.clientKey)}
        <li class="relative" data-reorder-index={stepIndex}>
            {#if stepDrag.insertionIndex === stepIndex}
                <!-- 落とし先の目印。影・scale は使わない (DESIGN.md §Elevation) -->
                <div class="absolute inset-x-0 -top-2 h-0.5 bg-primary" aria-hidden="true"></div>
            {/if}
            <div class={stepDrag.activeIndex === stepIndex ? "opacity-50" : ""}>
                <Card padding="md">
                    <div class="flex items-start justify-between gap-2">
                        <div class="flex items-center gap-2">
                            <DragHandle
                                ariaLabel={`手順 ${stepIndex + 1} の並び順を変更 (ドラッグ、または上下キー)`}
                                onpointerdown={(event) => onStepHandleDown(stepIndex, event)}
                                onkeydown={(event) =>
                                    onHandleKeydown(event, (delta) => moveStep(stepIndex, delta))}
                                testId="step-{stepIndex}-drag-handle"
                            />
                            <h3 class="text-body font-medium text-text">手順 {stepIndex + 1}</h3>
                        </div>
                        <div class="flex items-center gap-1">
                            <!-- 既存の ▲▼ と削除ボタンは無変更 (キーボード経路を消さない) -->
                        </div>
                    </div>
                    ...
                </Card>
            </div>
        </li>
    {/each}
    {#if stepDrag.insertionIndex === steps.length}
        <li class="h-0.5 bg-primary" aria-hidden="true"></li>
    {/if}
</ol>
```

急所側は `<ol ... data-point-list bind:this=...>` の代わりに **属性 `data-point-list` だけ**を
付ける（`bind:this` は使わず `closest()` で引く。手順ごとに ref を持たずに済む）。

### TypeScript strict 適合チェック

- [x] `querySelectorAll<HTMLElement>` / `closest<HTMLOListElement>` の型引数を明示
      （素の `as` を使わない）
- [x] `event.currentTarget` は `instanceof HTMLElement` で絞る
- [x] `steps[stepIndex]?.points` の undefined を早期 return で処理
- [x] `PointerDragState` を型 import して `$state` の型を明示
- [x] `ReturnType<typeof createPointerDrag>` で controller の型を明示（`any` なし）
- [x] `verbatimModuleSyntax: true` に従い型は `type` 修飾子付きで import
- [x] `steps[stepIndex]` は必ず const に受けて undefined 検査（`?.` の結果を別式で再アクセスしない）
- [x] `pnpm typecheck` / `pnpm lint` / `svelte-no-undef-gate` が緑

### テスト計画

`tests/js/components/features/manual/ScenarioEditor.test.ts` に describe を追加する。

- [x] 既存テスト（`step-1-move-up` / `step-0-move-down` を使う 3 箇所）は**無変更で緑**
      であること（▲▼ の外形と挙動を変えていないことの証明）
- [x] 新規: ハンドルの pointerdown → pointermove（閾値超え、目的行の中点より下）→ pointerup で
      **`payloadSteps` の順序が入れ替わる**こと（保存ボタン押下時の PUT body で検証する。
      `fetch` mock の body を JSON.parse して `steps[].id` の並びを assert）
- [x] 新規: D&D の直後に `scenario-dirty-indicator` が出ること（dirty 判定との整合）
- [x] 新規: D&D の直後に「元に戻す」で**元の順序へ戻る**こと（undo 履歴との整合）
- [x] 新規: 急所行の D&D が**同じ手順の中だけ**で完結すること
      （別手順の急所 `<ol>` の行は `rows()` に入らない = `closest()` による絞り込みの検証）
- [x] 新規: ドラッグ中に `Escape` を押すと順序が変わらないこと
- [x] 新規: ハンドルに focus して `ArrowUp` / `ArrowDown` で 1 段移動すること
- [x] 新規: ハンドルが `disabled` 属性を持たないこと（禁止事項 8）
- [x] 新規: 先頭行で `ArrowUp` を押すと順序が変わらず、
      `scenario-reorder-status` に「これ以上、上へは移動できません」が入ること（A1）
- [x] テスト用の DOM 実測: `HTMLElement.prototype.getBoundingClientRect` を
      `vi.spyOn` で行ごとに固定値（`top = index * 100`, `height = 100`）へ差し替える
- [x] 個別の `DatabaseTransactions` を使っていない（JS テストのため対象外）

### リスク

| リスク | 対応 |
|---|---|
| `steps = moveItem(...)` の再代入で keyed each が全再描画になる | key は `clientKey` で変わらないため DOM は移動のみ。既存 undo/redo も `steps` 再代入で動いている（`restoreFrom`）ので前例がある |
| controller が多重生成され、1 回のドロップで 2 回移動する | `onMount` で 1 度だけ生成する（`$effect` は使わない）。テストで「1 回のドロップで順序が 1 回だけ変わる」ことを assert する |
| IME 変換中の D&D が履歴を壊す | `runSettled` を通すので既存の pending キューに乗る（構造操作と同じ扱い） |
| ドラッグ中に別タブ/別操作でリストが縮む | `moveStepTo` が `to` の範囲を毎回検査する。範囲外は no-op |
| `-top-2` の目印がカード間の `gap-4` の中央に来ない | 目印は視覚的補助であり、判定は `insertionIndexFromRects` が持つ。位置は実装時に微調整（値ではなく仕組みで判定している。思考原則） |

---

## 施策 5: 撮影 PWA テイク列への配線

### 変更箇所

- ファイル: `resources/js/components/features/capture/TakeStrip.svelte`
  - L106-109（`move` の定義）
  - L188-220（リスト container と ▲▼ の markup）
  - script 冒頭の import 群

### 波及変更

- TypeScript 型定義: **変更なし**（`CaptureTake` / `CaptureCut` は無変更）
- API Resource / DTO: **変更なし**。既存の `PATCH /app/projects/{p}/manuals/{m}/cuts/{c}/takes/{t}`
  に `{ position }` を送る既存経路をそのまま使う。
  `UpdateCaptureTakeRequest`（`position` は `nullable|integer|min:0`）・
  `CaptureTakeService::update()` / `reorderWithinCut()` は**無変更**
- Inertia Props: **変更なし**（`onChanged` によるサーバ再取得も現行どおり）
- テストファイル: `tests/js/components/features/capture/TakeStrip.test.ts` に追記

### 現行コード

```svelte
async function run(take: CaptureTake, action: () => Promise<Response>): Promise<void> {
    error = null;
    busyTakeId = take.id;
    try {
        const response = await action();
        if (!response.ok) {
            error = await extractErrorMessage(response);
            return;
        }
        onChanged();
    } catch {
        error = "通信に失敗しました。ネットワークを確認してください。";
    } finally {
        busyTakeId = null;
    }
}

const move = (take: CaptureTake, position: number) =>
    run(take, () => captureJson(takeUrl(take), "PATCH", { position: Math.max(0, position) }));
```

```svelte
<div class="flex shrink-0 flex-col gap-1">
    <Button variant="ghost" size="sm" iconOnly ariaLabel="上へ"
        onclick={() => move(take, index - 1)} testId={`take-up-${take.id}`}>
        <ChevronUp class="size-4" aria-hidden="true" />
    </Button>
    <Button variant="ghost" size="sm" iconOnly ariaLabel="下へ"
        onclick={() => move(take, index + 1)} testId={`take-down-${take.id}`}>
        <ChevronDown class="size-4" aria-hidden="true" />
    </Button>
</div>
```

サーバ側（無変更。`position` の意味の根拠）:

```php
/** cut 内の並べ替え (対象を position に挿入し 0..n-1 でサーバ再採番)。行ロック下で呼ぶ */
private function reorderWithinCut(Cut $lockedCut, Take $target, int $position): void
{
    $ordered = $lockedCut->takes()->whereKeyNot($target->id)->orderBy('sort_order')->orderBy('id')->get()->all();
    $position = min($position, count($ordered));
    array_splice($ordered, $position, 0, [$target]);
    ...
}
```

> **`position` の基準（受け入れ条件 A5 の確定）**: サーバは「対象を除いた配列」へ `position` で
> splice する。対象を除いた配列に index `p` で挿入した結果、その要素は**全体配列でも index `p`**
> に来る。したがって **`position` = 移動後の全体配列での 0 始まり index = 最終 index** であり、
> `toFinalIndex()` の出力をそのまま渡してよい。既存 ▲▼ の `index + 1`（1 段下げ）もこの定義と
> 一致する（`[A,B,C]` で A を position 1 → 除外後 `[B,C]` に 1 で挿入 → `[B,A,C]`）。

### 変更後コード

**(a) import 追加**

```svelte
import { onMount } from "svelte";
import DragHandle from "@/components/atoms/DragHandle.svelte";
import { createPointerDrag, type PointerDragState } from "@/lib/dnd/pointer-drag";
```

**(b) `run()` を成否が分かる形に変える（告知を成功時だけにするため）**

```svelte
/**
 * 共通の XHR 実行。**成功したら true** を返す。
 * 戻り値を足したのは、並べ替えの読み上げ (aria-live) を**成功時だけ**にするためである。
 * 失敗しても「移動しました」と読み上げると、スクリーンリーダ利用者にだけ嘘をつくことになる
 * (視覚利用者は role="alert" のエラーを見る。design-review R1 の指摘)。
 * 既存の呼び出し側 (adopt / remove / downloadAndAck / confirmDelete) は戻り値を無視するだけで
 * 無変更のまま動く。
 */
async function run(take: CaptureTake, action: () => Promise<Response>): Promise<boolean> {
    error = null;
    busyTakeId = take.id;
    try {
        const response = await action();
        if (!response.ok) {
            error = await extractErrorMessage(response);
            return false;
        }
        onChanged();
        return true;
    } catch {
        error = "通信に失敗しました。ネットワークを確認してください。";
        return false;
    } finally {
        busyTakeId = null;
    }
}
```

**(c) 並べ替えの単一入口**

```svelte
/**
 * テイクの並べ替え。▲▼・ハンドルのキーボード・D&D のすべてがここへ合流する。
 * position は**最終 index**（移動後の全体配列での 0 始まり index）。
 * サーバの reorderWithinCut が対象を除いた配列へ splice するため両者は一致する。
 *
 * クライアント側で楽観的に並べ替えない（サーバ権威）。理由は 2 つ:
 *   1. 採用テイク (adopted_take_id) と親の再取得 (onChanged) が同じ経路で更新されるため、
 *      ローカル順序を別に持つと 2 つの真実ができる
 *   2. 既存の ▲▼ と同じ挙動になり、並べ替え手段ごとに体感が割れない
 *
 * 告知は**成功後**に行う（失敗は既存の role="alert" が伝えるので二重に出さない）。
 */
async function reorderTo(from: number, to: number): Promise<void> {
    const take = cut.takes[from];
    if (take === undefined || from === to || to < 0 || to >= cut.takes.length) return;
    const label = `テイク ${from + 1} を ${to + 1} 番目に移動しました`;
    if (await move(take, to)) announce(label);
}

/** ▲▼ とハンドルのキーボードの共通経路 (1 段移動)。端は disabled にせず告知する */
function moveBy(index: number, delta: -1 | 1): void {
    const to = index + delta;
    if (to < 0 || to >= cut.takes.length) {
        // 端: 通信しない / busy にしない / 再取得しない。理由だけ告知する
        announce(delta < 0 ? "これ以上、上へは移動できません" : "これ以上、下へは移動できません");
        return;
    }
    void reorderTo(index, to);
}

let reorderStatus = $state("");
function announce(message: string): void {
    reorderStatus = message;
}
```

既存 `move` は残す（`reorderTo` が呼ぶ）。▲▼ の `onclick` は
`() => move(take, index - 1)` から `() => moveBy(index, -1)` へ差し替える。

> **既存挙動の変化（意図的）**: 現行は先頭で「上へ」を押すと `position: 0` の PATCH が飛び、
> サーバ側 no-op のうえで親の再取得が走っていた。変更後の**端操作の期待値**を次に固定する:
>
> | 観点 | 変更前 | 変更後 |
> |---|---|---|
> | 通信 (`fetch`) | 発生する（no-op PATCH） | **発生しない** |
> | `busyTakeId` | 立つ | **立たない** |
> | 親の再取得 (`onChanged`) | 呼ばれる | **呼ばれない** |
> | 利用者へのフィードバック | 無し（見た目も変わらない） | **aria-live で理由を告知** |
> | ボタンの活性 | 活性 | 活性のまま（禁止事項 8 に適合） |

**(d) ドラッグ制御**

```svelte
let takeDrag = $state<PointerDragState>({ activeIndex: null, insertionIndex: null });
let listEl = $state<HTMLDivElement | null>(null);
let dragCtl: ReturnType<typeof createPointerDrag> | null = null;

// $effect ではなく onMount (施策 4 と同じ理由: マウント期間だけ持つブラウザ資源であり、
// 派生状態ではない)。
onMount(() => {
    dragCtl = createPointerDrag({
        rows: () =>
            listEl === null ? [] : [...listEl.querySelectorAll<HTMLElement>(":scope > [data-reorder-index]")],
        onState: (state) => (takeDrag = state),
        onCommit: (from, to) => void reorderTo(from, to),
    });
    return () => {
        dragCtl?.destroy();
        dragCtl = null;
    };
});

function onHandleKeydown(event: KeyboardEvent, index: number): void {
    if (event.key !== "ArrowUp" && event.key !== "ArrowDown") return;
    event.preventDefault();
    moveBy(index, event.key === "ArrowUp" ? -1 : 1);
}
```

**(e) markup**

```svelte
<div class="flex flex-col gap-2" data-testid={`take-strip-${cut.id}`} bind:this={listEl}>
    ...
    {#each cut.takes as take, index (take.id)}
        <div
            class="relative flex flex-wrap items-center ... {takeDrag.activeIndex === index ? 'opacity-50' : ''}"
            data-testid={`take-item-${take.id}`}
            data-reorder-index={index}
        >
            {#if takeDrag.insertionIndex === index}
                <div class="absolute inset-x-0 -top-1 h-0.5 bg-primary" aria-hidden="true"></div>
            {/if}
            <DragHandle
                ariaLabel={`テイク ${index + 1} の並び順を変更 (ドラッグ、または上下キー)`}
                onpointerdown={(event) => dragCtl?.start(index, event)}
                onkeydown={(event) => onHandleKeydown(event, index)}
                testId={`take-drag-${take.id}`}
            />
            <div class="flex shrink-0 flex-col gap-1">
                <!-- 既存の 上へ / 下へ ボタン (onclick だけ moveBy へ差し替え) -->
            </div>
            ...
        </div>
    {/each}
    <p class="sr-only" aria-live="polite" data-testid="take-reorder-status">{reorderStatus}</p>
    {#if error}
        <p class="text-caption text-danger" role="alert" data-testid="take-strip-error">{error}</p>
    {/if}
</div>
```

> `listEl` を `data-testid={take-strip-...}` の div にそのまま bind するため、
> `:scope > [data-reorder-index]` の絞り込みで**行だけ**を採る
> （末尾の告知段落・エラー段落は `data-reorder-index` を持たないので混ざらない）。

### TypeScript strict 適合チェック

- [x] `querySelectorAll<HTMLElement>` の型引数を明示
- [x] `cut.takes[from]` の undefined を早期 return で処理
- [x] `run()` の戻り値を `Promise<boolean>` に変更（`finally` の `busyTakeId = null` は維持。
      `finally` に `return` を書かない = 戻り値を握り潰さない）
- [x] `void reorderTo(...)` で floating promise を明示的に破棄
- [x] `PointerDragState` / `ReturnType<typeof createPointerDrag>` で型明示（`any` なし）
- [x] `verbatimModuleSyntax: true` に従い型は `type` 修飾子付きで import
- [x] `pnpm typecheck` / `pnpm lint` が緑

### テスト計画

`tests/js/components/features/capture/TakeStrip.test.ts` に describe を追加する。

- [x] 新規: ハンドルの pointerdown → pointermove（3 行目の中点より下）→ pointerup で
      **`PATCH` の body が `{ position: <最終 index> }`** になること（A5 の本丸。
      3 要素で「1 番目を 3 番目へ」= `position: 2`、「3 番目を 1 番目へ」= `position: 0`）
- [x] 新規: 位置が変わらない drop では **`fetch` が呼ばれない**こと
- [x] 新規: ドラッグ中の `Escape` / `pointercancel` で `fetch` が呼ばれないこと
- [x] 新規: ハンドル focus 中の `ArrowUp` / `ArrowDown` が既存 ▲▼ と同じ PATCH を出すこと
- [x] 新規: 先頭で `ArrowUp` / 末尾で `ArrowDown` は上の表のとおり
      **`fetch` を出さず / 採用ボタンが loading にならず / `onChanged` を呼ばず /
      `take-reorder-status` に理由が入る**こと（A1。既存挙動からの意図的な変更を 4 点で固定）
- [x] 新規: PATCH が 422 を返したら既存の `take-strip-error`（`role="alert"`）に
      サーバ文言が出ること（失敗経路が D&D でも同じであることの証明）
- [x] 新規: **PATCH が失敗したときに `take-reorder-status` が空のままである**こと
      （成功していないのに「移動しました」と読み上げない。design-review R1 の指摘の回帰防止）
- [x] 新規: ハンドルが `disabled` 属性を持たないこと（禁止事項 8）
- [x] 既存テスト（採用 / 削除 / DL ACK / プレビュー）は**無変更で緑**であること
- [x] `getBoundingClientRect` は施策 4 と同じ方法でテストごとに固定する
- [x] 個別の `DatabaseTransactions` を使っていない（JS テストのため対象外）

### リスク

| リスク | 対応 |
|---|---|
| drop 後、サーバ再取得までの間に行が元位置へ戻って見える | 既存 ▲▼ と同じ挙動。`run()` が `busyTakeId` を立てるので操作中であることは伝わる。楽観更新は「2 つの真実」を作るため採らない（設計判断として明記） |
| 端での PATCH をやめたことで、意図せず何かが壊れる | 既存テストに端 PATCH を assert するものは無い（確認済み）。no-op PATCH と再取得が消えるだけ。期待値は上の 4 点表で固定 |
| `run()` の戻り値追加が既存呼び出しを壊す | 既存 4 箇所（`adopt` / `remove` / `downloadAndAck` / `confirmDelete`）は戻り値を使わない。`adoptFromPreview` の成否判定は現行どおり `error === null` のままにする（判定を 2 つに増やさない） |
| ドラッグ中に親の再取得（ポーリング等）でリストが差し替わる | `rows()` は毎 move で DOM を採り直し、`reorderTo` が範囲を再検査する。範囲外は no-op |
| ハンドルが増えて 1 行が横に広がりスマホで折り返す | 行は既に `flex-wrap`。ハンドルは `shrink-0` で最左に固定。実機確認 (A3) の確認項目に入れる |

---

## 施策 6: jsdom に無い pointer capture のスタブ

### 変更箇所

- ファイル: `tests/js/setup.ts`（末尾の既存スタブ群の隣に追加）
- ファイル: `tests/js/support/pointer-capture.ts`（**新規**。capture 非対応を模す型付き helper）

### 波及変更

- TypeScript 型定義: なし
- API Resource/DTO: なし
- テストファイル: 本ファイル自体がテスト基盤

### 現行コード

`tests/js/setup.ts` は `Element.prototype.animate` / `ResizeObserver` /
`Element.prototype.scrollTo` / `scrollIntoView` を「未実装なら最小スタブ」の形で補っている。

### 変更後コード

```ts
// jsdom は Pointer capture (setPointerCapture / releasePointerCapture / hasPointerCapture) を
// 実装しない。並べ替えの制御 (resources/js/lib/dnd/pointer-drag.ts) は機能検出して
// 無い環境でも完走するが、「capture がある環境」の分岐もテストで通せるよう
// 実際の捕捉状態を覚える最小スタブを入れる。
if (typeof Element !== "undefined" && typeof Element.prototype.setPointerCapture !== "function") {
    const captured = new WeakMap<Element, Set<number>>();
    Element.prototype.setPointerCapture = function (pointerId: number): void {
        const ids = captured.get(this) ?? new Set<number>();
        ids.add(pointerId);
        captured.set(this, ids);
    };
    Element.prototype.releasePointerCapture = function (pointerId: number): void {
        captured.get(this)?.delete(pointerId);
    };
    Element.prototype.hasPointerCapture = function (pointerId: number): boolean {
        return captured.get(this)?.has(pointerId) ?? false;
    };
}
```

### TypeScript strict 適合チェック

- [x] `WeakMap<Element, Set<number>>` で型を明示（`any` なし）
- [x] 既存スタブと同じ「未実装なら入れる」条件付きにする（実装がある環境を上書きしない）

**capture 非対応を模す型付き helper**（`tests/js/support/pointer-capture.ts` に置く）:

```ts
/**
 * pointer capture が実装されていない環境 (古い WebKit 等) を模して run を実行する。
 *
 * `Element.prototype.setPointerCapture = undefined` は
 * `(pointerId: number) => void` 型への undefined 代入になり型エラーになるため、
 * `Object.defineProperty` で差し替えて finally で必ず戻す
 * (delete も生の代入も使わない。design-review R1 の指摘)。
 */
export async function withoutPointerCapture(run: () => void | Promise<void>): Promise<void> {
    const original = Object.getOwnPropertyDescriptor(Element.prototype, "setPointerCapture");
    Object.defineProperty(Element.prototype, "setPointerCapture", {
        value: undefined,
        configurable: true,
        writable: true,
    });
    try {
        await run();
    } finally {
        if (original === undefined) {
            Reflect.deleteProperty(Element.prototype, "setPointerCapture");
        } else {
            Object.defineProperty(Element.prototype, "setPointerCapture", original);
        }
    }
}
```

### テスト計画

- [x] `pointer-drag.test.ts` の 1 ケースを `withoutPointerCapture(async () => { … })` で包み、
      capture が無くても一連の流れ（開始 → 移動 → 確定）が完走することを検証する
- [x] スタブ導入で既存テストが 1 件も壊れないこと（`pnpm test` 全緑）
- [x] helper は `tests/js/support/` に置く（`tests/js/**/*.test.ts` の include に入らないので
      テストとして実行されない = 目録 gate に影響しない）

### リスク

| リスク | 対応 |
|---|---|
| スタブが本物と挙動が違い、テストが実ブラウザを保証しない | 保証範囲を誇張しない。**capture の有無で結果が変わらない設計**（listener は window）にしてあり、スタブは分岐網羅のためだけに置く。iOS Safari の実挙動は A3 の実機確認が担う |

---

## 施策 7: テストと受け入れ手順

### 変更箇所

- 新規: `tests/js/lib/dnd/list-reorder.test.ts` / `tests/js/lib/dnd/pointer-drag.test.ts`
- 追記: `tests/js/components/features/manual/ScenarioEditor.test.ts`
- 追記: `tests/js/components/features/capture/TakeStrip.test.ts`

### 波及変更

- **テスト目録**: `scripts/test-inventory-config.ts` が Vitest の `include` の正本であり、
  `scripts/vitest-inventory-gate.test.ts` が FS 走査と突き合わせる。
  **確認済み**: root project の include は `["tests/js/**/*.test.ts", "scripts/**/*.test.ts"]` で、
  新規の `tests/js/lib/dnd/*.test.ts` は既存 glob に入る = **目録の変更は不要**。
  （テスト置き場を `tests/js/` の外へ動かす場合のみ目録を同じ変更で直すこと）
- **CI**: 追加の workflow は作らない（`ci-workflow-inventory.test.ts` の W17 等に触れない）

### テスト方針（D&D をどうテストするか）

D&D の実挙動は DOM とブラウザ実装に強く依存する。そこで**検証対象を 3 層に分ける**:

| 層 | 何を固定するか | 手段 |
|---|---|---|
| 1. 意味論 | どこに落ちたら何番目になるか（off-by-one） | `list-reorder.test.ts`（純関数・表駆動・DOM なし） |
| 2. 生死 | ポインタの開始/確定/取消/解放が漏れないか | `pointer-drag.test.ts`（jsdom で `PointerEvent` を dispatch。`getBoundingClientRect` は spy で固定） |
| 3. 配線 | 落としたら既存の保存経路が期待どおり動くか | 2 つのコンポーネントテスト（PUT body の順序 / PATCH の position） |

**実機でしか分からないこと**（タッチの取りこぼし、慣性スクロールとの競合、標準外の
ジェスチャ割り込み）は自動テストの対象外であり、A3 の実機確認で扱う。
**Vitest の緑を「iOS Safari で動く証明」と書かない。**

### 受け入れ手順（実装完了の条件）

1. `pnpm test` / `pnpm typecheck` / `pnpm lint` / `pnpm build` が緑
2. `composer test` / `composer phpstan` / `vendor/bin/pint --test` が**無変更のまま**緑
   （PHP 差分が 0 件であることを併せて確認する）
3. **iOS Safari 実機**（PWA standalone を含む）で以下を確認し、日時・端末・OS バージョン・
   結果を `devnotes/20260816-1021-drag-and-drop-reordering/ios-acceptance.md` に記録する（A3）:
   - テイク列のハンドルを縦にドラッグして順序が変わる
   - ハンドル以外を触ったときは従来どおりページがスクロールする
   - リスト末尾が画面外にあるとき、端に寄せると自動スクロールして落とせる
   - ドラッグ中に着信・アプリ切替が入っても（`pointercancel`）操作が中断されるだけで
     順序が壊れない
4. シナリオ編集を **Chrome / Safari デスクトップ**でマウス操作し、
   undo（元に戻す）で元の順序に戻ることを確認する

> **実機確認記録の存在を自動テストで強制しない**（design-review R1 Suggestion への回答）。
> ファイルが在ることは**実機で確認した事実の証明にならない**ため、存在チェックを緑にすると
> 「機械が確認した」という誤った安心を作る。これは `docs/supported-browsers.md` が繰り返し
> 戒めている「WebKit レーンの green を iOS Safari 対応の実証と言い換えない」と同型の誤りである。
> よって受け入れ条件 3 は**人間のレビューで見る運用**とし、記録が無ければ完了にしない。

### リスク

| リスク | 対応 |
|---|---|
| 新規テストディレクトリが `include` に載らず走らない | 確認済み（`tests/js/**/*.test.ts` に含まれる）。テスト置き場を動かす場合のみ目録を直す |
| 実機確認が省略されて「対応済み」と書かれる | 記録ファイルを受け入れ条件 3 に明記。機械強制はしない（上記の理由）ため、レビュー時に人間が見る |

---

## 実装モード

| 項目 | 内容 |
|------|------|
| 推奨モード | **standalone** |
| 判断根拠 | (1) 新規ファイル 5 本 + 既存 3 ファイル改修で 1 つの機能が完結し、途中状態に意味が無い（純関数だけ入れても UI は変わらず、UI だけ入れても計算が無い）。(2) 触るのはフロントの 2 画面と共通 lib のみで、他ドメインの同時作業とファイルが重ならない。(3) サーバ・DB・API 契約に触れないためマイグレーション順序の制約が無い |
| 競合リスク | `ScenarioEditor.svelte` / `TakeStrip.svelte` を触る他タスクがあれば衝突する（どちらも大きなファイル）。`tests/js/setup.ts` は追記のみで衝突しにくい。`scripts/test-inventory-config.ts` を触る場合は他タスクと確認する |
| 実装順序 | 施策 1 → 2 → 6（テスト基盤）→ 3 → 4 → 5 → 7。1・2 はテストファースト（fail を見てから実装）で進められる |

## 使命・禁止事項の最終チェック

- **使命への寄与**: 「編集ゼロ」の中核である「AI 生成シナリオの構成順の修正」と
  「採用候補テイクの入れ替え」を、それぞれ 1 ジェスチャに縮める。撮影 PWA（現場）と
  PC 編集（設計）の両方に効く。
- **禁止事項 1（テストなしの完了報告）**: 全施策にテストを割り付け済み。
  D&D の弱点（テストしづらさ）に対しては 3 層分割で対処した。
- **禁止事項 8（disabled）**: ハンドルも ▲▼ も disabled にしない。端での要求は
  aria-live で告知する。
- **禁止事項 2/4/5/6/7（PHP 側）**: PHP を変更しないため抵触しない。
- **禁止事項 9（Artifact）**: 成果物は本 devnotes 配下のファイルのみ。
- **依存追加なし**: 新しい npm パッケージを入れないため、
  `docs/supply-chain/review-checklist.md` の審査対象は増えない。
  将来ライブラリ化する場合は同 §（新規依存の審査観点）に沿って別途審議する。

