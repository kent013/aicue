# 対応マトリクス: conceptual-review Round 2

## [Warning] `DeterminedScenarioDuration` の入力契約が未確定 (relation を読むのか組を受けるのか)
- 判断: 対応する
- 根拠: 指摘のとおり曖昧だった。relation を読む形にすると
  `AdoptedTakeReferenceInventory` を増やさないという前提と衝突する。
- 対応内容: 入力を **`list<int|null>` (カット 1 本ずつの確定尺)** に固定した。
  `Cut` も `Take` も受け取らないので relation を読みようがない
  (指摘の「組を渡す」案よりさらに強く、型の上で不可能にした)。
  採用済みかつ ready のテイクの解決は呼び出し側 (`CaptureManualDetailData` が既に持つ
  `AdoptedReadyTakeCoverage::readyTakeId()` 経由の解決) の責務である旨を明記した。

## [Warning] 「4 フィールド」と「5 キー」の食い違い
- 判断: 対応する
- 対応内容: 改善アイデア冒頭を「5 キー」へ修正し、内訳が実装方針 3 にあることを書いた。

## [Warning] `DeterminedScenarioDuration` の戻り値型が未定義
- 判断: 対応する
- 根拠: 連想配列で返すと PHPStan level 10 で呼び出し境界を固定できない。
- 対応内容: `DeterminedScenarioDuration` 自身を `final readonly class` の結果型にし、
  `?int $totalDurationMs` / `int $undeterminedCutCount` を持たせた。
  生成は `fromCutDurations(list<int|null>): self` の 1 本だけにする。
  別に `...Data` サフィックスのクラスを足さないのは、このクラスが結果そのものであり、
  同名概念のクラスを 2 つ並べると呼び分けの規則がもう 1 つ増えるためである
  (既存の `AdoptedReadyTakeCoverage` も式と結果を 1 クラスで持つ形で先例がある)。
