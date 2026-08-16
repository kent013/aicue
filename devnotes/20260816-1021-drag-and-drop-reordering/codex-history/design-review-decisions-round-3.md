# 対応マトリクス: design-review Round 3

判定は **CHANGES_REQUESTED**（Critical 1 / Warning 1 / Suggestion 1）。
施策 1 / 2 / 3 / 5 / 6 は **APPROVE**。**全件対応した（反論なし）**。

## [Critical] 施策 4: 手順 controller と急所 controller が互いを排他できない

- 判断: **対応する**（指摘が正しい。R2 の修正では**同一 controller 内**しか閉じていなかった）
- 根拠: R2 で閉じたのは「`pointDragCtl` の 1 本目 → 2 本目を拒否」だけである。
  手順用と急所用は**別インスタンス**なので、各々は自分の `pointerId` しか知らず、
  次の経路が通ってしまう:

  1. `stepDragCtl` で手順 A を掴む
  2. `pointDragCtl` で手順 A 配下の急所を掴む（別 controller なので受理される）
  3. 手順を先に drop する → `steps` の並びが変わる
  4. 急所を drop する → **`pointDragStep` に入っている数値 index が、並べ替え後は別の手順を指す**

  逆向き（急所ドラッグ中に手順ドラッグ開始）も同様に成立する。
  結果はシナリオの取り違えであり、R2 の Critical と同じ「保存するまで気付かない」種類の
  データ整合性バグである。
- 対応内容:
  1. コンポーネント全体で 1 つの所有権 `dragOwner: "step" | "point" | null` を持つ。
     UI に出さないので既存の `composing` と同じく非 reactive な local にする。
  2. 開始処理の順序を **「所有権が空か → `start()` → 受理されたときだけ所有権とスコープを確定」**
     に統一（`onStepHandleDown` / `onPointHandleDown` の両方）。
  3. 解放を `releaseDrag()`（所有権 + 急所スコープ）に一本化し、
     **step 側にも `onCancel` を付ける**。`onCommit` は両方 `try/finally` で必ず通す。
     unmount の cleanup でも明示的に呼ぶ（`destroy()` は仕様上 `onCancel` を呼ばないため）。

## [Suggestion] 排他の基準は `isDragging()` ではなく「受理した時点」にせよ

- 判断: **対応する**（さらに一歩進めて API 自体を削除する）
- 根拠: 指摘のとおり `isDragging()` は閾値超過後にしか true にならないので、
  閾値未満の待機中に 2 本目を受理してしまう。排他の判定に使うと穴になる。
  そして本設計では `isDragging()` を**どこからも使っていない**。
  使い道が無いうえ誤用すると穴になる API を残す理由が無い（思考原則 2: 今必要なものだけ作る）。
- 対応内容: `PointerDragController` から `isDragging()` を**削除**した。
  併せて型 doc に「この種の API を置かない理由（閾値未満の待機中に穴が開く）」を明記し、
  排他は `start()` の戻り値 = 受理した瞬間を基準にすると書いた。

## [Warning] 施策 7: R2-1 は controller をまたぐ競合を検出できない

- 判断: **対応する**
- 根拠: そのとおり。R2-1 は `pointDragCtl` 内部の競合しか見ておらず、
  上の Critical の経路は素通りする。
- 対応内容: テスト計画に **双方向** のケースを追加した（R3-1 / R3-2）:
  - 手順ドラッグ中に急所ハンドルの `pointerdown` を出しても急所ドラッグが始まらない
  - 急所ドラッグ中に手順ハンドルの `pointerdown` を出しても手順ドラッグが始まらない
  - どちらの向きでも、拒否された 2 本目が 1 本目の対象と結果を変えない

  併せて controller 単体側にも R3-3（`start()` の 2 回目が `false` を返し、
  1 本目の `fromIndex` が保持される）を追加した。

## APPROVE された施策（変更なし）

- 施策 1（純関数）: `splice` + spread の実装、`Array<number | undefined>` のテストとも妥当
- 施策 2（pointer 制御）: `start(): boolean` の排他、`setInsertion()` の間引き、
  rAF の手動 1 フレーム実行
- 施策 3（DragHandle atom）
- 施策 5（TakeStrip）: controller が 1 つでドラッグに付随する外部スコープも無いため同種の穴が無い
- 施策 6（pointer capture スタブ）
