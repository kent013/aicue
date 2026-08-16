# 概念設計: ime-deferred-structural-ops-stable-key (IME 変換中の構造操作を安定キーで解決する)

## 背景・課題

`resources/js/components/features/manual/ScenarioEditor.svelte` は、IME 変換中 (composing) に
要求された構造操作を `compositionend` 後に FIFO で遅延実行する仕組み (`runSettled` /
`pendingActions`) を持つ。

```ts
function runSettled(action: () => void): void {
    if (composing) {
        pendingActions.push(action); // FIFO: 発行順に compositionend で実行
        return;
    }
    action();
}
```

遅延実行される closure が **数値 index を捕捉している**と、先行して実行された構造操作で
配列の添字がずれ、**呼び出し時に意図した行とは別の行へ操作が適用される**。

T185 (D&D 並べ替え) では並べ替えの 2 経路 (`moveStepTo` / `movePointTo`) について、
掴んだ行の安定キー `clientKey` を捕捉し、**実行時に `findIndex` で解決し直す**形へ改めてこの
弱点を解消した。しかし削除・追加の 3 経路 (`removeStep` / `removePoint` / `addPoint`) は
数値 index を載せたまま遅延実行される状態が残っている。

T185 の実装レビュー (Codex impl-review Round 2) はこれを
「T185 が悪化させたものではないが、既知の正確性問題として放置せず別タスクへ登録すべき。
削除・追加・並べ替えを同じ不変条件で監査するのが筋」と明示的に求めた
(`devnotes/20260816-1420-todo-T185/impl-review-round-2.md`)。本設計はその申し送りに応える。

### 現状の欠陥コード (3 経路)

```ts
function addPoint(stepIndex: number): void {
    runSettled(() =>
        commitStructural(() => steps[stepIndex].points.push({ ...emptyRow("yori"), id: null })),
    );
}

function removeStep(index: number): void {
    runSettled(() => commitStructural(() => steps.splice(index, 1)));
    confirmingStepIndex = null;
}

function removePoint(stepIndex: number, pointIndex: number): void {
    runSettled(() => commitStructural(() => steps[stepIndex].points.splice(pointIndex, 1)));
}
```

### 具体的な破綻シナリオ (机上で確認済み。回帰テストで実証する)

前提: 手順が 3 件 (A / B / C)。利用者は本文セルで日本語入力中 (composing = true)。

1. **削除が別の行に当たる**
   IME 変換中に手順ハンドルで A を 3 番目へドラッグして落とす (遅延 #1)。
   続いて B の削除を確認ダイアログで確定する (遅延 #2 = `removeStep(1)`)。
   `compositionend` で #1 が実行され並びは B / C / A になる。#2 は index 1 を splice するので
   **C が消える**。利用者は B を消したつもりで C を失う。
   (削除確認ダイアログが index を保持している経路も同じ壊れ方をする。後述)

2. **追加が別の手順にぶら下がる**
   同様に並べ替えを遅延させたあと、手順 B の「急所を追加」を押す (遅延 = `addPoint(1)`)。
   実行時には index 1 が C なので、**C に急所が生える**。

3. **追加が例外で落ち、後続の遅延操作ごと失われる**
   遅延 #1 = 手順 A の削除、遅延 #2 = 手順 B の「急所を追加」(`addPoint(1)`)。
   #1 実行後 `steps.length` は 2 → 1 ではなく…(3 件なら 2 件) となり、末尾を指していた場合
   `steps[stepIndex]` が `undefined` になり **`TypeError` を投げる**。
   `onCompositionEnd` の drain ループは try/catch を持たないため、
   **例外以降にキューされていた操作はすべて失われ**、compositionend ハンドラの外へ例外が漏れる。
   (これは #1/#2 と違い「静かな取り違え」ではなく「操作の消失」という別の壊れ方をする)

### 影響 (使命との関係)

シナリオは「AI が設計した撮るべきカットの台本」であり、この画面はそれを現場の言葉へ直す
唯一の編集面である。**日本語入力中に構造操作をすると別の手順が消える**という欠陥は、
「思考ゼロ・編集ゼロ」を掲げる製品で利用者が最も気づきにくい形のデータ喪失である
(消えたことに気づくのは保存後、あるいは撮影段階になってからになる)。
日本語入力は本アプリの主要利用形態そのものなので、IME 変換中という条件は例外的ではない。

## 改善アイデア

**遅延実行される構造操作は、削除・追加・並べ替えを問わず、対象を安定キー (`clientKey`) で
捕捉し、実行時に解決し直してから変異する。** T185 が並べ替えで確立した形を残り 3 経路へ
そのまま横展開し、`ScenarioEditor` 全体で 1 つの不変条件に揃える。

新しい仕組みは作らない。`runSettled` / `commitStructural` / `pendingActions` / 履歴機構は
一切変更せず、**捕捉する値を「数値 index」から「安定キー」へ変える**だけの局所変更である。

### 遅延 queue に積まれる全操作の棚卸し (`runSettled` の呼び出し 8 件)

| # | 操作 | 現在 closure が捕捉する値 | 判定 | 本タスクでの扱い |
|---|------|--------------------------|------|-----------------|
| 1 | `addStep()` | なし (末尾 push) | 安全 | 変更しない |
| 2 | `addPoint(stepIndex)` | 親手順の数値 index | **欠陥** | 親手順を `clientKey` で解決 |
| 3 | `removeStep(index)` | 手順の数値 index | **欠陥** | 手順を `clientKey` で解決 |
| 4 | `removePoint(stepIndex, pointIndex)` | 親手順 + 急所の数値 index | **欠陥** | 両方を `clientKey` で解決 |
| 5 | `moveStepTo(from, to)` | `from` は `clientKey` (T185 済) / `to` は位置 | 安全 | 変更しない |
| 6 | `movePointTo(stepIndex, from, to)` | 親と対象が `clientKey` (T185 済) / `to` は位置 | 安全 | 変更しない |
| 7 | `undo()` → `doUndo` | なし (undoStack の先頭) | 安全 | 変更しない |
| 8 | `redo()` → `doRedo` | なし (redoStack の先頭) | 安全 | 変更しない |

補足:

- **`to` (移動先の位置) を安定キー化しないのは意図的**である。「n 番目へ移動する」は位置そのものが
  利用者の意図であり、キーへ置き換える対象ではない (T185 の判断を踏襲する)。
- **`addStep()` が安全なのは「末尾へ追加」だから**であり、位置指定の追加を将来足すなら
  同じ監査が要る。本タスクでは位置指定の追加を作らない。

### queue 外にある同種の弱点 (数値 index が非同期境界をまたぐ経路)

棚卸しの過程で、`runSettled` の外にも**同じ種類の**弱点を 1 件見つけた。

- **`confirmingStepIndex` (削除確認ダイアログ)**: 削除ボタン押下時に数値 index を state へ
  置き、利用者が「削除する」を押した時点で `removeStep(confirmingStepIndex)` を呼ぶ。
  ダイアログが開いている間に `compositionend` が発火して遅延中の並べ替えが実行されると、
  **確定した時点で index はすでに別の手順を指している**。
  `removeStep` の入口で安定キーを捕捉しても、捕捉するのは確定時 = ずれた後なので閉じない。
  本タスクで `confirmingStepIndex: number | null` → `confirmingStepKey: string | null` へ
  変え、`removeStep` を「`clientKey` を受け取る」関数にすることで同時に閉じる
  (対象を index で持つ箇所が削除経路から 1 つも無くなるので、施策 3 の一部として扱う)。
  - **ダイアログ表示側の解決は不要**である。現行の `ConfirmDialog` 呼び出しは
    `open` / 固定文言の `title` `message` / `onConfirm` / `onCancel` だけで、
    **対象手順の内容 (番号・scene) を一切描画していない** (`ScenarioEditor.svelte` L1323-1332)。
    したがって key 化しても表示側に index 依存は残らない。
  - **対象が解決できなくてもダイアログは必ず閉じる**。`confirmingStepKey = null` は
    現行と同じく `runSettled` の**外**で即時実行する (履歴とは独立、という現行のコメントの
    意図をそのまま維持する)。閉じ方を `compositionend` の drain 側へ移すと、遅延実行の
    仕組みへ新しい結合を作ることになるため採らない。
    観測される結果は「消したかった行はもう無く、ダイアログも閉じている」で不整合が無い。
- **`save()` は `runSettled` を通さない**(変換確定前の文字列で PUT しうる)。これは
  「対象の取り違え」ではなく「確定前テキストの送信」であり別種の論点なので、本タスクでは
  扱わない (現状維持)。棚卸しの結果として記録だけ残す。

## 期待効果

- **使命への貢献**: 日本語入力中の構造操作で「別の手順が消える / 別の手順に急所が生える」
  データ喪失が構造的に起きなくなる。現場作業者が編集面を安心して使えることは、
  「専門知識ゼロでも標準化されたマニュアル動画を作れる」という使命の前提条件である。
- **不変条件の一本化**: 「遅延実行される構造操作は安定キーで解決し直す」という 1 つの規則が
  削除・追加・並べ替えの全経路に揃い、次に構造操作を足す人が従うべき形が 1 つになる。
- **操作の消失が消える**: **今回棚卸しした** index ずれ由来の throw 経路
  (`steps[stepIndex]` が undefined) が無くなるため、その原因で queue の drain が
  途中で止まることは無くなる。
  (「queue が今後一切 throw しない」ことを保証するものではない — 将来
  `pendingActions` に積む closure を足す人は同じ監査を通す必要がある)
- **リスクの非対称性**: 変更は「実行時に対象を探し直す」ことだけで、
  解決できた場合の挙動は現行と完全に同一である。

## 実装方針（概要）

対象は **`resources/js/components/features/manual/ScenarioEditor.svelte` の 1 ファイル** と、
その回帰テスト `tests/js/components/features/manual/ScenarioEditor.test.ts` のみ。
サーバ側 (PHP / DTO / JsonResource / route) の変更は無い。TypeScript 型定義の変更も無い
(`DraftStep.clientKey` / `DraftPoint.clientKey` は既存)。

1. **`addPoint(stepKey: string)`**: 実行時に `steps.findIndex` で親手順を解決してから
   `points.push` する。解決できなければ何もしない。
2. **`removePoint(stepKey: string, pointKey: string)`**: 実行時に親 → 子の順で
   解決してから `splice` する。どちらか解決できなければ何もしない。
3. **`removeStep(stepKey: string)`**: 実行時に解決してから `splice` する。
   併せて `confirmingStepIndex` を `confirmingStepKey` へ替え、削除経路から数値 index を無くす。

**引数名・型で key 前提を明示する** (`stepIndex: number` のまま中身だけ key にしない)。
`findIndex` の戻り値 `-1` は必ず早期 return し、**その後でのみ**添字アクセスする
(TypeScript の `noUncheckedIndexedAccess` 有無に依存せず読み手に安全性が見える形にする)。
markup 側の呼び出し漏れは `pnpm typecheck` が検出する
(数値を `string` 引数へ渡すとコンパイルエラーになる)。

いずれも T185 の `moveStepTo` / `movePointTo` と同じ形にする:
**呼び出し時に存在を確認 → キーを捕捉 → `runSettled` の中で解決し直す → `commitStructural` で変異**。

### 対象が実行時に存在しない場合の振る舞い (決定と根拠)

**3 経路とも「黙って捨てる (no-op)」**。エラー表示もトーストも読み上げも出さない。根拠:

- **削除**: 対象がもう無いなら、利用者の意図 (この行を消す) は**すでに満たされている**。
  観測できる最終状態は成功時と同一なので、失敗として知らせる意味がない。
- **追加**: 「**この手順に**急所を足す」という意図は、親手順が消えた時点で無効になる。
  親を作り直したり、別の手順や末尾へ足したりすると**利用者が指示していない行**が生まれる。
  何もしないのが唯一の安全側である。
- **告知を足さない理由**: 現行 UI は追加・削除の**成功時にも**読み上げを出していない
  (`announce` は並べ替え専用)。失敗時だけ読み上げると告知の粒度が不揃いになる。
  また、この分岐が起きるのは「変換中に、同じ対象を消す操作と別の操作を続けて要求した」
  ときだけで、その時点で画面上その行は既に消えている = 利用者にとって不整合に見えない。
- **履歴・dirty との整合**: 解決失敗時は変異しないので、`dirty` も undo スタックも動かない。
  `commitStructural` を呼んだとしても `pushHistory` が before === after で no-op を返すため
  空エントリは積まれない (履歴コアが既にそれを保証している。design-review R2 で確認)。
  それでも早期 return するのは、**無駄な `serializeSteps` 2 回を避ける**ためと、
  「解決できなければ何もしない」が読んで分かる形にするためである。
- T185 の `moveStepTo` / `movePointTo` も解決失敗時は同じく黙って return しており、
  **既存の流儀と一致する**。

### テスト方針

`tests/js/components/features/manual/ScenarioEditor.test.ts` に、
T185 が追加した「IME 変換中に手順の並べ替えと急所の並べ替えを続けて確定しても、掴んだ手順の
急所が動く」テストと同じ構造で回帰テストを足す。**先行する構造操作で index がずれた後に、
遅延実行された削除/追加が正しい対象へ適用される**ことを直接検証する:

1. 変換中に「手順 A を末尾へ移動」→「手順 B を削除」を続けて要求 → 確定後、**B が消え A/C が残る**
2. 変換中に「手順 A を末尾へ移動」→「手順 B に急所を追加」を続けて要求 → 確定後、
   **急所は B にぶら下がる**
3. 変換中に「手順 A の急所 1 を末尾へ移動」→「同じ急所を削除」…ではなく、
   親手順の並びが変わった後に**掴んだ手順の急所**が削除されること
4. 対象が実行時に消えている場合に **no-op で完走し、後続の遅延操作も実行される**
   (現状の `TypeError` による queue 消失の回帰)
5. 削除ダイアログを開いている間に遅延中の並べ替えが確定しても、**開いたときの手順が消える**

さらに **負のコントロール**を実測する: 安定キー解決を外した (数値 index に戻した) 状態で
上記テストが赤くなることを確認し、実測ログを `devnotes/{dir}/negative-control.md` に残す。
「テストが本当にこの欠陥を検出しているか」を証拠として残すためで、
確認後は安定キー解決を戻す。

## 制約・前提

- **遅延実行の仕組みそのものを作り替えない** (思考原則 2)。`runSettled` / `pendingActions` /
  `onCompositionEnd` の drain ループ / `commitStructural` / 履歴機構は変更しない。
  drain ループへの try/catch も足さない — 本変更で**今回棚卸しした** throw 経路が消えるため、
  実在しない失敗に備える防御を先回りして作らない (思考原則 2)。
  ただしこれは「queue が今後 throw しない」という保証ではない。
- **undo/redo 履歴・dirty 判定との整合を壊さない**。すべての変異は T185 と同じく
  `commitStructural` へ合流させる。`serializeSteps` / `payloadSteps` / `snapshot` の形は変えない。
- `clientKey` は `serializeSteps` に含まれ undo/redo で round-trip する既存の安定キーであり、
  `payloadSteps` (PUT body) には含まれない (サーバ保護キー混入防止)。この非対称は維持する。
- フロント規約: Svelte 5 runes + DS token のみ。本変更は script ブロック中心で、
  markup 側は削除ボタンの `onclick` と急所追加ボタンの引数の差し替えのみ。
  新しい component・icon・token は増やさない。
- 検証コマンドは `pnpm test` / `pnpm typecheck` / `pnpm lint` が対象
  (PHP 側の変更が無いため `composer test` / `composer phpstan` は無関係だが、
  実装時には規約どおり全 green を確認する)。

## スコープ外

- `save()` を `runSettled` で IME ゲートすること (別種の論点。上記「queue 外」参照)
- 遅延 queue の上限・タイムアウト・可視化などの新機能
- `to` (移動先位置) の安定キー化 (位置指定は意図そのもの)
- 位置指定の行追加 (「ここに手順を挿入」) の新設
- サーバ側の整合強化 (本欠陥はクライアント作業コピー内で完結する)
- iOS 実機での IME 挙動の受け入れ確認 (T185 の A3 と同種の作業。本タスクは
  jsdom 上の回帰テストで不変条件を固定することを完了条件とする)
