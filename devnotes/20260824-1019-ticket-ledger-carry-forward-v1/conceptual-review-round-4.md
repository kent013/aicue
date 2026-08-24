- 全体判定: **APPROVED**

Round 3 の Critical は解消されています。SoftDeletes による母集団漏れをv0の実在バグとして特定し、列挙・ロック・候補数・残数判定を同じ母集団へ揃える設計は妥当です。

## 1. 使命との整合性

[Suggestion] 撮影導線の残高判定を支える台帳の有界化に加え、退会済み組織で保持処理が永久に完了しない不具合も解消します。基盤改善としてNorth Starに整合しています。

## 2. 禁止事項違反

[Suggestion] 禁止事項への抵触は見当たりません。テストファースト、旧機構の撤去、DTO境界、Architecture gate、既存migrationを残したdrop migration追加という方針も適切です。

## 3. 実現可能性

[Suggestion] `Organization::withTrashed()` による列挙と、同じく `withTrashed()` を使った組織行ロックはLaravel 12で実現可能です。

特に次の非対称が解消されます。

- 列挙: active／soft-deletedの両方
- 処理: active／soft-deletedの両方
- `countExpired()`: active／soft-deletedの両方
- dry-run: active／soft-deletedの両方

`whereKey($organization->getKey())` のIDが解決済みモデル由来であり、既存走査器の候補外になることを実測したうえで現行のモデル反復を維持する判断も合理的です。

## 4. 期待効果の妥当性

[Suggestion] 期待効果は妥当です。有界性を絶対行数ではなく「失効済み窓数Nに依存しない」と定義したことで、テスト可能な仮説になっています。

soft-deleted組織も母集団に含めたため、効果がactive組織だけに限定される問題も解消されています。

## 5. リスク

[Suggestion] 保持分類のリスクは適切に管理されています。

- 技術的なデータ形状と法的分類を分離
- SoftDeletesの実態を明記
- 契約終了後も残ることを隠さない
- オーナー／法務確認を実装・リリースの前提条件に設定
- 確認未了ではlctlを `implemented/v1` にしない
- 不許容時の退路を明示

条件付き承認を「法的に問題ないという承認」と誤読させない構造になっています。

[Suggestion] 実装時には、`withTrashed()` の静的gateを単なる語彙の全リポジトリ禁止にせず、可能なら完全修飾された `Organization::withTrashed()` を対象にしてください。他モデルの正当なSoftDeletes運用まで同じ規約で塞がないためです。

## 6. スコープの適切さ

[Suggestion] 母集団是正を同じPRへ含める判断は適切です。これは周辺的な退会機能改善ではなく、畳み込みが処理すべき台帳行の定義そのものです。

正典形への全面的な書き換えを避け、必要な `withTrashed()` だけを加える判断も、実測結果に基づいており過大ではありません。

## 7. 型安全性

[Suggestion] PHPStan level 10を通せる設計です。

- `delta_sum` はキャスト前に10進文字列としてint4範囲を検査
- `source === null` はそのまま保持
- 文字列だけを `TicketSource::from()` に渡す
- 未知値や不正型は例外
- 日時、件数、必須列をDTO入口で検証
- Eloquent castを通さないquery-builder境界

詳細設計では、`CarryForwardGroup` の `source` プロパティを明示的に `?TicketSource` とすれば、legacyのNULLを含む契約も型に表現できます。

現時点で概念設計を差し戻すCritical／Warningはありません。オーナー／法務確認を実装・リリースおよびlctl昇格の前提条件として維持したうえで、詳細設計へ進めます。