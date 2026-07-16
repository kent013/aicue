# 対応マトリクス: design-review Round 8（CHANGES_REQUESTED / Critical 1・Warning 2）

## [Critical] P8b の `down()` 契約が自己矛盾
- 判断: 対応する（直前で「Starter のみ false / Personal は絶対に触らない」と書きながら次行で「当該 2 行を false へ戻す」だった）
- 対応: `down()` を **「`code='starter'` の 1 行のみを false へ戻す（`personal` は絶対に触らない）」** に置換。理由（P4 後に本 migration
  だけ rollback すると無料導線が消えて F-07 変種が再発する）も併記。

## [Warning] 旧公開契約が数箇所残る
- 判断: 対応する / 対応: 「**Personal=P3、Starter=P8b**」へ全て統一（P1 PlanSeeder / P4 一覧・リスク / P3 Plan 集合 /
  P8b の更新テスト記述）。

## [Warning] P8b テストの基準件数が旧状態
- 判断: 対応する（**P3 後は Personal + Standard の 2 枚**が基準。指摘のとおり）
- 対応: `PricingPageTest` の件数を **2 → 3** に固定（P8b の Starter 再公開で 3 枚）。露出テストの「再公開前は 1 枚」を
  **「再公開前は personal + standard の 2 枚」**へ。**加えて `down()` 後も personal + standard の 2 枚が維持される
  （= Personal が非公開へ戻らない）テストを追加**。
