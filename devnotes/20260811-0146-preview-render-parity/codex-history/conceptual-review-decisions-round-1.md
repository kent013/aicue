# 対応マトリクス: conceptual-review Round 1

## [Warning] 禁止事項 8 との整合を明文化せよ (プレビューボタンを disabled にしない)
- 判断: 対応する
- 根拠: 実装者が「未撮影なら押させない」に倒す余地を残さないため。設計の意図の核でもある。
- 対応内容: 「改善アイデア 判断 1」に *プレビューボタンは disabled にしない / 確認ダイアログも足さない* を
  明示条件として追記。詳細設計の受入条件にも引き継ぐ。

## [Warning] カット単位の判定 API が無いと manifest 側で判定が再実装される
- 判断: 対応する (指摘のとおり。判定単一化の目的を満たさない設計だった)
- 根拠: `RenderPipeline::clipSpecFor()` はカット単位で placeholder か否かを決める。集計 DTO しか
  提供しないと `adoptedTake === null || status !== Ready` が再び 2 箇所に書かれる。
- 対応内容: `AdoptedReadyTakeCoverage::isMissing(Cut $cut): bool` を**唯一の述語**とし、
  `for(VideoManual): TakeCoverageData` はその述語を畳むだけの実装にする。
  `clipSpecFor()` / `trigger()` の 422 / props の 3 消費者がすべて同じ述語を通る。

## [Warning] クラス名が「adopted 有無だけの判定」と混同されうる
- 判断: 対応する
- 根拠: 同リポジトリに `whereDoesntHave('adoptedTake')` (ready を見ない別基準) が既にあり、
  名前で区別できたほうが誤用が減る (思考原則「機能の名前に立ち返れ」)。
- 対応内容: `AdoptedTakeCoverage` → **`AdoptedReadyTakeCoverage`** に改名して記述を統一。

## [Warning] 成功条件が「事前表示と実 placeholder 数が常に一致」と読める
- 判断: 対応する
- 根拠: 事前表示は描画時点のスナップショットであり、常時一致は保証できない (嘘になる)。
- 対応内容: 成功条件を 2 本に分離し、「一致を期待するのは同一操作直後の通常ケースのみ」と明記。
  機械で固定するのは **render 422 の件数と同時点 coverage の件数の一致** (同一 tx・同一述語) に限定する。

## [Warning] `placeholder_cut_count` の値の意味 (null 契約) が曖昧
- 判断: 対応する
- 根拠: 既存行・失敗行・running 行・render 行で意味が違うと UI が誤読する。
- 対応内容: 値契約表 (historical/running/failed=null、succeeded preview=実数、succeeded render=0) を
  概念設計に追記し、DTO/TS も `?int` / `number | null` で受けると明記。

## [Warning] finalize が「現在状態から再計算」してはならない
- 判断: 対応する (設計意図どおりだが明文化されていなかった)
- 対応内容: `RenderManifest::placeholderCutCount` を必須条件として明記し、
  finalize は manifest 由来の値を書くだけであることを制約に格上げ。

## [Warning] Architecture gate の母集団が広すぎるとノイズになる
- 判断: 対応する (ただし「別基準の経路も母集団に入れて理由付き登録」は維持)
- 根拠: deny-by-default の価値は「新しい経路が黙って増えないこと」なので母集団は落とさない。
  一方でノイズ懸念は正しいので、登録済み経路には *別基準である* 旨を目録の理由に書く。
- 対応内容: gate の目的を「レンダ系の採用済み ready 判定を 1 箇所に閉じる」と定義し直し、
  ダッシュボード / 撮影ナビの `whereDoesntHave('adoptedTake')` は
  **`DifferentCriterion` 区分で理由付き登録**する形にする (母集団からは外さない)。

## [Warning] PHPStan level 10 のための型明示
- 判断: 対応する
- 対応内容: `missingLabels: list<string>` / `missingCutIds: list<int>` (持つ場合) /
  `placeholder_cut_count: ?int` を概念設計の時点で明記。
