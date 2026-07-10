# 対応マトリクス: design-review Round 2

## [Warning] 施策2: groupBy 後の型が PHPStan level 10 で mixed に崩れる（get(0, collect()) の default 汚染）
- 判断: 対応する
- 対応内容: `fromManual()` の骨子を書き換え。`$cuts` / `$grouped` に
  `Collection<int, Cut>` / `Collection<int, Collection<int, Cut>>` の PHPDoc を明示し、
  default 引数をやめて `$grouped->get(0) ?? $empty`（型付き空 Collection 変数）で受ける形にした。
  Eloquent Collection → base Collection の変換（toBase）も明示。

## [Warning] 施策6: fetch のネットワーク reject が未処理 Promise になる
- 判断: 対応する
- 対応内容: `save()` に catch を追加。ネットワーク断・419 回復 GET / 再試行 PUT の reject も
  同経路で捕捉し、作業コピーを保持したまま `genericError`（通信失敗文言）を設定する。

## [Warning] 施策6: 422 処理の契約が未確定（JSON 破損・期待外 shape への防御）
- 判断: 対応する
- 対応内容: `handleResponse` の 422 分岐を具体化。`{ errors: Record<string, string[]> }` を
  実行時 type guard（`isValidationErrors`）で判別し、shape 不一致・JSON 破損は
  汎用エラーへフォールバックする方針を設計に明記。

## [Warning] 施策7: 通信失敗経路の Vitest 未網羅
- 判断: 対応する
- 対応内容: Vitest に「PUT の reject」「419 回復 GET の reject（多重 retry なし）」
  「422 body 不正のフォールバック」を追加（いずれも作業コピー保持を検証）。

## [Suggestion] 施策3: prepareForValidation はキー存在 + null の場合だけ正規化
- 判断: 対応する
- 対応内容: 注意書きを設計に追記（array_key_exists 判定・present/missing ルールを
  無効化しない最小変更・元配列を作り直さない）。

## [Suggestion] 施策4: upsertCut コメントの正規化記述の残骸
- 判断: 対応する（Round 2 プロンプト送付直後に修正済み）
- 対応内容: コメントを「本文は fill、parent/sort/type は forceFill。入力 DTO は正規化済み（施策 3）」
  に差し替え済み。

## [Suggestion] 施策4: 波及先の ManualServiceBoundaryTest 表記
- 判断: 対応する
- 対応内容: 施策 4 の波及変更を `ScenarioServiceTest` に更新（残る言及は「同じ流儀」という
  スタイル参照のみ）。

## [Suggestion] 施策6: payloadSteps は毎回新規生成 + snapshot も同正規形の clone
- 判断: 対応する
- 対応内容: payloadSteps() が呼び出しごとに新しい配列/オブジェクトを生成し、snapshot も
  その生成物を保持して `$state` proxy と参照を共有しない旨を明記。

## [Suggestion] 施策2: public const string の Round 1 指摘は誤りだった（撤回）
- 判断: 記録のみ（設計は既存規約どおり型付き定数を維持）
