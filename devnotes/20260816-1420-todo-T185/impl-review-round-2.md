仮説は「安定キー化で対象同一性バグは解消したが、A3 は文書作成では達成できない」です。提示差分をその前提で再評価しました。

`resources/js/components/features/manual/ScenarioEditor.svelte`

- 判定: 実装修正は妥当です。
- `moveStepTo()` は手順の `clientKey`、`movePointTo()` は親手順と急所双方の `clientKey` を捕捉し、実行時に再解決しています。Round 1 で指摘した「遅延後に別要素へ適用される」経路は塞がれています。
- 残る `to` は対象の同一性ではなく「実行時の最終位置」を表すため、数値 index のままで問題ありません。範囲も実行時に再検査されています。
- `pointDragStep` はドラッグ確定までしか使われず、`movePointTo()` 呼び出し時に安定キーへ変換されます。遅延 queue に数値の親 index を持ち越してはいません。
- [Suggestion] `findIndex()` 後の `const current = steps[stepAt]` は論理的には存在しますが、将来 `noUncheckedIndexedAccess` を有効化するなら `current === undefined` の検査が必要になります。現行 typecheck が通る以上、今回の blocker ではありません。

`tests/js/components/features/manual/ScenarioEditor.test.ts`

- 判定: 妥当です。
- 追加テストは「先行する手順移動で親 index が変わった後、急所移動が正しい親へ適用される」ことを直接検証しています。
- 負のコントロールも、安定キー解決が assertion に実際に寄与していることを示しています。
- `DOMRect` と `HTMLInputElement` の型アサーション除去も適切です。

`resources/js/components/features/capture/TakeStrip.svelte`

- 判定: Round 1 から新たな問題はありません。
- `run(): Promise<boolean>` は既存呼び出しが戻り値を無視できる契約であり、成功時の `onChanged()`、失敗時の非告知も維持されています。
- 楽観更新を行わず、サーバ再取得を権威とする設計も変わっていません。

`tests/js/components/features/capture/TakeStrip.test.ts`

- 判定: 改善されています。
- 成功時の `onChanged()` と、操作対象行の busy 状態を確認するようになり、Round 1 の空振り懸念は解消しました。
- [Suggestion] `JSON.parse(...) as unknown` はまだ素の型アサーションです。`JSON.parse` 自体が通常 `any` を返すため、厳密に「テストを含め `as` 不使用」を貫くなら、JSON parser helperやschemaによる検証が必要です。ただし Round 1 の指摘対象だった DOM assertion とは性質が異なり、今回のマージ blocker にはしません。

`removeStep` / `removePoint` / `addPoint`

- 判定: T185 の修正範囲外とする判断は妥当です。
- T185 が変更していない既存問題であり、今回の安定キー化によって悪化させてもいません。
- ただし既知の正確性問題として放置せず、別タスクへ登録すべきです。題名は「IME 遅延中の構造操作を安定キーで解決する」程度が適切です。削除・追加・並べ替えを同じ不変条件で監査するのが筋です。

`resources/js/lib/dnd/pointer-drag.ts`

- 判定: 問題ありません。
- `setPointerCapture()` の `try/catch` 見送りは妥当です。具体的な失敗環境や再現テストなしに防御だけを増やす必要はありません。
- window listener によって capture 非対応時も完走するため、capture は正しさの前提になっていません。

`resources/js/components/atoms/DragHandle.svelte` / `DragHandle.types.ts`

- 判定: 問題ありません。
- `aria-describedby` の見送りも妥当です。現状の `aria-label` は対象と操作手段を含み、Arrow キー経路も実装されています。重複読み上げを増やす根拠はありません。

`devnotes/20260816-1021-drag-and-drop-reordering/ios-acceptance.md`

- [Critical] 未実施記録は誠実な引き継ぎ資料ですが、受け入れ条件 A3 を達成したことにはなりません。
- 詳細設計は「人間が実機で記録するまで未達」「記録が無ければ完了にしない」と明示しています。したがって、エージェントが取り得る対応としては最善ですが、レビュー判定として承認へ変える根拠にはできません。
- 「既存の▲▼が残るため先行マージ可能」というリスク判断は合理的でも、詳細設計の完了条件をこのレビュー段階で緩和するものではありません。先行マージを許すなら、A3をマージ後確認へ変更する設計判断を明示的に承認する必要があります。現行設計のままならマージを止めるべきです。

実装上の Critical 1 は解消しています。残る変更要求はコードではなく、A3 の実機確認完了だけです。

CHANGES_REQUESTED