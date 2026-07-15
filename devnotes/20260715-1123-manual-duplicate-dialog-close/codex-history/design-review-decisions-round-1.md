# 対応マトリクス: design-review Round 1

判定: CHANGES_REQUESTED（Critical 2 / Warning 5 / Suggestion 複数）

## [Critical] 施策3: $effect のエッジ検知が脆い
- 判断: 対応する
- 対応内容: `let prevOpen = $state(open)` で初期同期し、effect 冒頭で `const wasOpen = prevOpen; prevOpen = open; if (open && !wasOpen) seedFromDefaults();` の順に変更。初回マウント(open:true)でも seed が走らないことを明記し、施策3リスク欄も更新。

## [Critical] 施策4: 多重送信テストが UI 抑止か関数ガードか判別不能
- 判断: 対応する
- 対応内容: テストを 2a(関数ガード=form submit イベント直接発火で post 未呼び)と 2b(UI ガード=processing 時 aria-busy/disabled)に分離。

## [Warning] 施策1: onSuccess のタイミング断定が強い
- 判断: 対応する
- 対応内容: リスク欄を「onSuccess で open=false を必ず実行し同一ページ再利用時の開きっぱなしを防ぐ」不変条件記述に修正。visit 完了後に必ず呼ばれる断定を弱めた。

## [Warning] 施策3: seedFromDefaults の代入は useForm shape 依存
- 判断: 対応する
- 対応内容: 代入対象を title/category の 2 キーのみに限定する旨をコメントに明記。

## [Warning] 施策4: 再seedテストに実害観点(エラーDOM消滅)を追加
- 判断: 対応する
- 対応内容: テスト3で `holder.last.errors.title` にエラー注入 → 再オープン後 `queryByText` で消滅を観測する assertion を追加。

## [Warning] 施策5: initial に processing/errors が来ない前提が暗黙
- 判断: 対応する
- 対応内容: generic 制約を `TData extends Record<string, unknown> & { processing?: never; errors?: never }` にしてコンパイル時に衝突を禁止。

## [Suggestion] onSuccess 内 clearErrors / transform 記録拡張 / reopen 統合回帰
- 判断: 一部反映・一部見送り
- 対応内容: onSuccess の clearErrors は施策3で再オープン時に clear するため必須でなく見送り（重複回避）。transform 記録拡張は本タスク範囲外で見送り。reopen 統合回帰は施策4のテスト3(エッジ不変条件)で近似的にカバー。
