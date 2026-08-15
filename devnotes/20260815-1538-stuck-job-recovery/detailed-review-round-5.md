## 再レビュー結果

Round 4 の未解消事項はすべて対応されています。回収ロジック、DirectFetch の分類、監視語彙、テスト計画の間に承認を妨げる矛盾は見当たりません。

### 施策別判定

| 施策 | 判定 | 理由 |
|---|---|---|
| 1. 回収の共通契約と語彙 | **APPROVE** | stream・outcome・DTO・cadence・ロック期限が型とテストで固定されている |
| 2. registry と sweeper | **APPROVE** | ページ送り、実効上限、dry-run、例外継続、契約違反時の防御が網羅されている |
| 3. 入口コマンドと定期実行 | **APPROVE** | 引数異常系、Schedule 配線、ロック期限、監視語彙5つが明確 |
| 4. 解析ジョブ stream | **APPROVE** | 滞留述語を単一化し、ロック下で再評価するため誤回収の窓が閉じている |
| 5. レンダジョブ stream | **APPROVE** | 2閾値と kind・状態の組合せを含め、解析と同じ不変条件を維持している |
| 6. チケット予約 stream | **APPROVE** | 会計述語を Service 内に閉じ、競合を例外ではなく `Skipped` にできている |
| 7. Stripe webhook stream | **APPROVE** | replay safety、世代 CAS、Deferred/Escalated の意味が維持されている |
| 8. 撮影アップロード予約 stream | **APPROVE** | 主キークエリを1本に集約し、DB commit 後にS3処理を行う構造が妥当 |
| 9. 目録 gate・撤去 gate | **APPROVE** | exact-fit、Schedule 配線、撤去保証の限界、変異テストが明文化されている |
| 10. 目録・docs 更新 | **APPROVE** | DirectFetch の2形と出現単位登録、監視語彙移行、保証限界が整合している |

### 確認結果

未解消の **[Critical] / [Warning] はありません**。

[Suggestion] `RecoveryFetchShape` のファイル集合検査は、コメントや文字列ではなくPHPトークン上のメソッド宣言・呼び出し識別子だけを数えることをテスト名またはdocblockで明記すると、将来の実装者が単純な文字列検索に置き換える事故を防げます。

[Suggestion] アップロード回収の競合テストは、可能であれば単なる「候補列挙後に completed 化」だけでなく、別接続によるロック待ちとロック解放後の述語再評価を1ケース持つと、CASから行ロックへ変更した際の並行実行契約をより直接的に固定できます。ただし承認条件ではありません。

## 全体判定

**APPROVED**

実装時は設計どおり fail-first で進め、`composer test`、`composer phpstan`、`vendor/bin/pint --test`、DirectFetch inventory の出現件数確認を完了条件として扱ってください。