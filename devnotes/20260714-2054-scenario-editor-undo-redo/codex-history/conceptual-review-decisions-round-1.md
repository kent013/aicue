# 対応マトリクス: conceptual-review Round 1

## [Warning] Ctrl/Cmd+Z のグローバル上書きが native text undo を奪う (観点4)
- 判断: 対応する
- 根拠: 妥当。テキスト編集中に文字単位 undo を失うのは UX 後退。brief のショートカット要件は
  維持しつつ、native undo と両立させる方が良い。
- 対応内容: キーボード undo/redo を**フォーカス文脈依存**にする。編集フィールド
  (input/textarea/select/contenteditable) にフォーカスがある間は preventDefault せず
  native undo に委ねる。フォーカスが編集フィールド外(ボタン/body 等)のときのみ
  アプリ層 document undo/redo を実行。ボタンは常にアプリ層 undo/redo(明示操作)。
  → ショートカット仕様は満たしつつ衝突を回避。

## [Warning] focusin/focusout 境界のイベント順序・IME・二重 push (観点3)
- 判断: 対応する
- 根拠: IME 変換中の誤確定・二重 push は実バグになりうる。
- 対応内容: (a) keydown に `event.isComposing` ガード追加。(b) `flushPendingEdit()` は
  `editBaseline=null` を必ず立て**冪等**にし、直後の focusout で再 push しない。
  (c) 構造操作は `flushPendingEdit()`→現在状態を before として push、の順で、
  テキスト編集分と構造変更分を別エントリに分離(同一遷移の二重 push は起きない)。
  (d) テストで `blur→click`(構造操作) と `keydown while focused` を個別固定。

## [Warning] MAX_HISTORY が件数のみで粗い (観点5)
- 判断: 対応する
- 根拠: 大規模シナリオで全文書 JSON × 100 件はメモリ非有界。
- 対応内容: 件数上限 `MAX_HISTORY_ENTRIES=100` に加え **総文字数上限**
  `MAX_HISTORY_CHARS`(例 2,000,000 ≈ 数 MB)を併用。push 後、どちらかを超える間
  undoStack 先頭を捨てる。running total を保持して O(1) 管理。

## [Warning] deserialize 失敗でエディタ全体が落ちる (観点5)
- 判断: 対応する
- 根拠: 内部データ前提でも fail-safe は必要(防御的パースは既存コードの方針とも一致)。
- 対応内容: 復元を `parseHistory(serialized): DraftStep[] | null` に集約し try/catch +
  shape 検証。失敗時は steps を変えず `resetHistory()` + 警告トースト。スタック変更前に
  peek→validate してから pop/push する(失敗時にスタックを壊さない)。

## [Warning] deserializeSteps が実質キャストで型安全性が見かけだけ (観点7)
- 判断: 対応する
- 根拠: 妥当。既存コードは `isScenarioDocument` 等で防御的パースしている。整合させる。
- 対応内容: 履歴シリアライズ形を明示型 `SerializedStep`/`SerializedPoint` で定義し、
  `unknown -> validate(型ガード) -> rowOf 正規化 -> DraftStep[]` の薄いデコーダにする。
  既存 `isScenarioRow` 系の粒度に合わせる。

## [Warning] テスト計画が粗い (観点2)
- 判断: 対応する
- 対応内容: fail-first で以下を固定: dirty 往復 / redo クリア / save→reseed 後の履歴リセット /
  409・明示リロード後の履歴リセット / ショートカット(field 内 native 委譲・field 外 app undo) /
  blur→構造操作の二重 push 防止 / 復元失敗の fail-safe / メモリ上限打ち切り。詳細設計で列挙。

## [Suggestion] 期待効果の表現を誇大にしない (観点1,4)
- 判断: 対応する
- 対応内容: 「編集ゼロの実現」ではなく「保存前の試行錯誤・誤操作復旧コストの低減」に表現を寄せる。
  主効果を行削除/並べ替え/ポイント削除の復旧に定義。
