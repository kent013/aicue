# 全体判定: CHANGES_REQUESTED

Round 1 の大半は適切に解消されています。ただし、`category_id` の扱いに実装不能な矛盾が残っています。

## 1. 使命との整合性

[Suggestion] Tier A / Tier B の分割により、直接価値のある Category / VideoManual に実装を集中できています。North Star とフェーズ1の位置づけに整合しています。

[Suggestion] `JobStatus` は対応するジョブテーブルも利用箇所もフェーズ1にありません。「今必要なものだけ作る」に従い、ジョブ導入フェーズへ移す方が適切です。

## 2. 禁止事項違反

[Critical] `category_id` を `MassAssignmentProtectedKeys` に登録し、同じ FormRequest で `ProhibitsProtectedKeys` を使いながら、入力として `category_id` を受ける設計は両立しません。さらにテスト仕様でも「category_id を送れば 422」としており、通常のカテゴリ選択も必ず拒否されます。

修正提案: HTTP入力には保護キーと異なる名前、例えば `category` を使用してください。これを当該 Project 配下に限定して検証・解決し、`category()->associate($resolvedCategory)` で代入します。DB/FK名の `category_id` は引き続き protected とし、`category_id` を直接送った場合は 422 に固定できます。

## 3. 実現可能性

[Warning] `cuts.adopted_take_id` と `takes.cut_id` は循環FKです。通常の作成順では単一のテーブル作成マイグレーションだけで構築できません。

修正提案: `cuts`、`takes` の順に作成した後、別マイグレーションまたは後段の `Schema::table('cuts')` で `adopted_take_id` のFKを追加する手順を明記してください。

[Warning] reorder は「全件再採番」としながら、検証が各IDの存在確認だけです。欠落・重複・空配列を許すと、順序の重複や一部未更新が発生します。

修正提案: IDの重複禁止に加え、送信されたID集合が当該ProjectのCategory集合と完全一致することをService内で検証し、不一致なら422としてください。代案として「指定分だけ移動」に契約を変更します。

## 4. 期待効果の妥当性

[Suggestion] 先取りカラムについて更新主体・整合先・フェーズ1状態が明文化され、後続フェーズでの意味のドリフトは十分抑制されています。

[Warning] `adopted_take_id` の通常FKだけでは、「採用Takeが同じCutに属する」ことを保証できません。

修正提案: 後続の採用APIで `cut->takes()` 経由に限定して解決することと、cross-cut指定を404にするFeature/IDORテストを将来の必須条件として記載してください。

## 5. リスク

[Warning] `Rule::exists(...)->where(project_id)` は検証時点の保証であり、保存時の再解決を省略すると競合や実装逸脱が残ります。

修正提案: 記載済みの「ServiceでProject relationから再解決」を必須契約とし、検証済みIDをそのまま `associate($id)` しないことをテストまたはArchitecture規約で固定してください。

[Suggestion] Category削除とVideoManual更新が競合した場合も、DBの `ON DELETE SET NULL` が最終的な整合性を担保する設計は妥当です。

## 6. スコープの適切さ

[Suggestion] Tier Bからルート・Controller・UI・IDOR inventoryを除外したことで、過大スコープは解消されています。

[Suggestion] Categoryを「CRUD」と呼ぶ場合、専用index/showを持たずProjects/Showに内包する点だけ用語上の注記があると明確です。機能上の問題ではありません。

## 7. 型安全性

[Warning] nullable属性を単に `?Type` とするだけでは、EloquentモデルのPHPStan型は確定しません。

修正提案: 各ModelのプロパティPHPDoc、`casts()` の返却型、relation generics、Resource/Dataの返却shapeまでItem規約に合わせて固定してください。

[Suggestion] Inertia propsとTypeScript interfaceの具体化、Resource/Data経由への統一は適切です。

`category_id` の入力名衝突を解消し、reorderの集合契約と循環FK手順を追記すれば、概念設計としては `APPROVED` に到達できます。