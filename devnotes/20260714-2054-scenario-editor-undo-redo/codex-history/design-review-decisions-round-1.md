# 対応マトリクス: design-review Round 1

## [Critical 施策3] `{#each steps as step (step)}` の object-identity key
- 判断: 対応する
- 根拠: 妥当。undo/redo の `steps = restored` は全オブジェクト新規生成 → 全行再生成
  (フォーカス/selection/IME 文脈の過剰リセット)。Svelte のベストプラクティスは安定 key。
- 対応内容: 各行に**クライアント安定キー `clientKey: string`** を持たせ、each の key を
  `(step.clientKey)` / `(point.clientKey)` に変更。clientKey は
  (a) 型 `DraftStep`/`DraftPoint`(manual.ts)に追加、(b) `serializeSteps` に含め履歴で round-trip、
  (c) `payloadSteps`(PUT body)には**含めない**(サーバは関知しない)、(d) `toDraftSteps`/
  `emptyRow` で採番(モジュール内カウンタ `nextClientKey()`)。→ undo/redo で不変行の DOM を
  保持し、変化行のみパッチされる。dirty 判定は clientKey 込みでも整合(reseed 後の snapshot と
  同一 clientKey が履歴に保存されるため往復で一致)。

## [Warning 施策3] parseHistory 内の `as DraftPoint` アサーション残存
- 判断: 対応する
- 根拠: strict 方針とズレ。
- 対応内容: 検証+shape を**util 側の純関数** `parseHistorySnapshot(serialized): SerializedStep[] | null`
  に移設し、`isSerializedRow`/`isSerializedStep` を type predicate 化。
  `SerializedRow`/`SerializedStep` 型を util に定義。component は util の戻り(検証済み構造)を
  `rowOf` で正規化して `DraftStep[]` を作る(データ経路の素アサーションを排除。points を読む
  一時 cast は既存 `isScenarioDocument` と同水準で許容)。

## [Warning 施策3] onEditorFocusOut が編集セッション外移動でも flush(relatedTarget 未考慮)
- 判断: 反論する(現状維持 + 明文化)
- 根拠: **フィールド単位 1 エントリ**は意図した粒度(細かい undo は望ましい)。編集可能→編集可能の
  coalesce は「1 回の undo で複数フィールドの編集が消える」粗い挙動になり UX 上むしろ劣る。
  さらに `pushHistory` は `before===current`(実編集なし)で no-op のため、値を変えない
  フォーカス巡回では履歴を汚さない。IME は composing gate で別処理。→ relatedTarget ガードは不要。
  設計に「粒度=フィールド単位・空巡回は no-op」を明記する。

## [Suggestion 施策3] 履歴破損時に console.warn を追加
- 判断: 対応する
- 対応内容: fail-safe 経路に `if (import.meta.env.DEV) console.warn(...)`(既存 Button と同作法)。

## [Warning 施策4] parseHistory fail-safe の実挙動テスト欠如
- 判断: 対応する
- 対応内容: (a) util の `parseHistorySnapshot` を施策2で直接単体テスト(不正 JSON / 非配列 /
  必須欠落 / 正常)。(b) component テストは `vi.mock("@/lib/manual/scenario-history")` で
  `parseHistorySnapshot` を一時的に null 返却させ、「steps 非破壊 + 履歴リセット + warning toast」を検証。

## [Warning 施策4] mutation 後の dirty/canUndo/canRedo 即時反映テスト
- 判断: 対応する
- 対応内容: 各操作直後に Undo/Redo ボタン活性・dirty インジケータを 1 ケースずつ検証。

## [Suggestion 施策4] Ctrl+Z と Cmd+Z 両方 / 施策2 複合条件
- 判断: 対応する
- 対応内容: ショートカットは `ctrlKey` と `metaKey` 両方のケース。`boundHistory` は
  「件数超過かつ文字数超過」の複合ケースを 1 件追加。
