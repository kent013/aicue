# 負のコントロール実測 (T188 施策 5)

対象: `resources/js/components/features/manual/ScenarioEditor.svelte` の遅延構造操作 3 経路
(`addPoint` / `removeStep` / `removePoint`) と、施策 6 の目録テスト。

- 実行日時: 2026-08-16 18:40〜18:45 JST
- ブランチ: `todo/T188` (worktree `.claude/worktrees/tasks/T188`)
- 実行コマンド:
  - behavioral: `pnpm test tests/js/components/features/manual/ScenarioEditor.test.ts`
  - 目録: `pnpm test tests/js/architecture/scenario-editor-deferred-ops-inventory.test.ts`
- 手順: 変種を 1 つだけ適用 → 実測 → **必ず元へ戻す** → 次の変種。
  最終差分に一時的な巻き戻しが残っていないことは `git diff` で確認済み
  (`ScenarioEditor.svelte` の差分は施策 1-3 の変更だけ)。

## 基準 (すべて正しい実装のとき)

| レーン | 結果 |
|---|---|
| `ScenarioEditor.test.ts` | **80 passed** (既存 72 + 新規 8) |
| `scenario-editor-deferred-ops-inventory.test.ts` | **2 passed** |

## 変種と観測

### 変種 (a): 安定キー解決を外す (= 修正前の実装そのもの)

3 経路を数値 index を捕捉する形へ戻し、markup 側の引数も index へ戻した状態。
これは実装前に**先に赤を確認した**ときの実測でもある (テストファースト)。

結果: **8 failed | 72 passed (80)** — 新規 8 件が**全滅**し、既存 72 件は**全て緑のまま**。
= 既存テストはこの欠陥に元々沈黙しており、新規ケースだけが検出している。

| # | ケース | 失敗の内容 |
|---|--------|-----------|
| 1 | 並べ替え → 手順削除 | `expected [ '手順シーンB' ] to deeply equal [ '手順シーンA' ]` (別の手順が消えた) |
| 2 | 並べ替え → 急所追加 | `expected [ '急所B-1', '急所B-2' ] to deeply equal [ '急所B-1', '急所B-2', '' ]` (別の手順へ付いた) |
| 3 | 並べ替え → 急所削除 | `expected [ '急所B-2' ] to deeply equal [ '急所B-1', '急所B-2' ]` (別の手順の急所が消えた) |
| 4 | 対象手順が消えた後の急所追加 | `expected [ …, '' ] to deeply equal [ …, '', '' ]` + **Uncaught TypeError** (下記) |
| 5 | ダイアログ中に並べ替えが確定 | `expected [ '手順シーンB' ] to deeply equal [ '手順シーンA' ]` |
| 6 | 消えている手順の再削除 | `TestingLibraryElementError: Unable to find an element by: [data-testid="/^step-\d+-scene$/"]` = **手順が 1 件も残らず EmptyState** になった (2 回目の削除が index 0 = 手順B を消した) |
| 7 | 消えている急所の再削除 | `expected [] to deeply equal [ '急所A-2' ]` (急所が全滅) |
| 8 | 親手順が消えた後の急所削除 | `expected [ '急所B-2' ] to deeply equal [ '急所B-1', '急所B-2', '' ]` + **Uncaught TypeError** |

**2 種類の壊れ方が実測で分かれた証跡** (ケース 4 / 8 が出した uncaught 例外):

```
TypeError: Cannot read properties of undefined (reading 'points')
 ❯ resources/js/components/features/manual/ScenarioEditor.svelte:224:51
    224|  commitStructural(() => steps[stepIndex].points.push({ ...e…
 ❯ commitStructural resources/js/components/features/manual/ScenarioEditor.svelte:481:9
 ❯ HTMLElement.onCompositionEnd resources/js/components/features/manual/ScenarioEditor.svelte:580:38
```

- **静かな取り違え** (ケース 1・2・3・5・6・7): 例外を出さず、別の行が消える / 別の手順へ付く。
- **操作の消失** (ケース 4・8): 範囲外アクセスが `compositionend` の drain 中に `TypeError` を投げ、
  **その後ろに積まれた遅延操作が実行されない**。

### 変種 (b1): `removeStep` の `at < 0` 早期 return を落とす (安定キー解決は残す)

結果: **1 failed | 79 passed (80)** — 落ちたのは**ケース 6 だけ**。

観測: `TestingLibraryElementError: Unable to find an element by: [data-testid="/^step-\d+-scene$/"]`
= 画面が EmptyState になった。2 回目の `removeStep` が `findIndex` の `-1` をそのまま
`steps.splice(-1, 1)` へ渡し、**末尾の手順 (手順B) を巻き添えで消した**。
`splice` の負数が「何もしない」ではなく**末尾からのオフセット**であることの実証。

### 変種 (b2): `removePoint` の子側 (`at < 0`) 早期 return を落とす

結果: **1 failed | 79 passed (80)** — 落ちたのは**ケース 7 だけ**。

観測: `AssertionError: expected [] to deeply equal [ '急所A-2' ]`
= 2 回目の削除が `splice(-1, 1)` で**末尾の急所 (急所A-2) を巻き添えで消した**。

### 変種 (b3): `removePoint` の親側 (`stepAt < 0`) 早期 return を落とす

結果: **1 failed | 79 passed (80)** — 落ちたのは**ケース 8 だけ**。

観測は**両方**の形で現れた:

- `AssertionError: expected [ '急所B-1', '急所B-2' ] to deeply equal [ '急所B-1', '急所B-2', '' ]`
  = 後続の急所追加が**実行されなかった**
- `TypeError: Cannot read properties of undefined (reading 'points')`
  = `steps[-1]` が `undefined` (添字アクセスは末尾要素を返さない) となり drain が中断した

(b1)/(b2) の「静かなデータ喪失」と (b3) の「drain の中断」が**別の壊れ方**であることを、
同じ手法で 3 回とも実測で分離できた。

### 変種 (d1): 名前付き function から未登録の `runSettled` 呼び出しを足す

`ScenarioEditor.svelte` へ `function probeDeferredNamed(): void { runSettled(() => {}); }` を追加。

結果: 目録テストが **1 failed | 1 passed (2)**。

```
AssertionError: expected [ 'addPoint', 'addStep', …(7) ] to deeply equal [ 'addPoint', 'addStep', …(6) ]
+   "probeDeferredNamed",
```

= 未登録の呼び出し元が名前で出る (deny-by-default が効いている)。

### 変種 (d2): 文レベル arrow function から未登録の `runSettled` 呼び出しを足す

`const probeDeferredArrow = (): void => { runSettled(() => {}); };` を
`addStep` の**後ろ**に追加 (帰属は直前の名前付き関数 `addStep` に付く形)。

結果: 目録テストが **1 failed | 1 passed (2)**。

```
AssertionError: expected 9 to be 8 // Object.is equality
```

= 検出はされたが、**赤くしたのは「呼び出し元集合の一致」ではなく「件数の完全一致」**である。
arrow から呼ぶと呼び出し元は登録済みの `addStep` に誤帰属するため、集合の比較は緑のまま通り、
件数の pin が拾った。`fromNamedFunction` の検査も同じ形を弾く設計だが、`expect` は最初の失敗で
止まるため今回の実測では発火に至っていない (**発火したのは件数の pin である**、と正確に記す)。

- 帰結: この目録テストは「増減に気づく」ことはできるが、**帰属の正しさは保証しない**。
  テスト冒頭の「保証しないもの」に書いたとおりであり、実測がそれを裏付けた。

## 新規 8 ケース × 変種の対応表

| ケース | (a) 安定キーなし | (b1) removeStep guard 無 | (b2) removePoint 子 guard 無 | (b3) removePoint 親 guard 無 |
|---|---|---|---|---|
| 1 並べ替え→手順削除 | **赤** | 緑 | 緑 | 緑 |
| 2 並べ替え→急所追加 | **赤** | 緑 | 緑 | 緑 |
| 3 並べ替え→急所削除 | **赤** | 緑 | 緑 | 緑 |
| 4 対象消滅後の急所追加 | **赤** | 緑 | 緑 | 緑 |
| 5 ダイアログ中の並べ替え確定 | **赤** | 緑 | 緑 | 緑 |
| 6 消えている手順の再削除 | **赤** | **赤** | 緑 | 緑 |
| 7 消えている急所の再削除 | **赤** | 緑 | **赤** | 緑 |
| 8 親消滅後の急所削除 | **赤** | 緑 | 緑 | **赤** |

**どの変種でも落ちないケースは 1 件も無い** = 8 件すべてが何かを検出している。
ガード 3 本 (b1/b2/b3) はそれぞれ**ちょうど 1 ケース**が専任で見張っており、
守備範囲が重複していない。

目録テスト 2 件は (d1)(d2) の両方で赤くなることを確認した
(ただし d2 が発火させたのは件数の pin。上記のとおり帰属の正しさは保証しない)。

## 既存テストの沈黙

全変種を通じて**既存 72 ケースは 1 件も落ちなかった**。
= 修正前のコードベースには、この欠陥を検出する回帰テストが 1 件も存在しなかった。

## 戻し確認

すべての変種を戻したうえで:

- `pnpm test tests/js/components/features/manual/ScenarioEditor.test.ts` → 80 passed
- `pnpm test tests/js/architecture/scenario-editor-deferred-ops-inventory.test.ts` → 2 passed
- `git diff` に一時的な巻き戻し・probe 関数が残っていないことを確認済み
