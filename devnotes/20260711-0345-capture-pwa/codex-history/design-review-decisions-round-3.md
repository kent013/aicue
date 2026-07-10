# 対応マトリクス: design-review Round 3（全体判定 APPROVED）

Round 2 の全指摘解消が確認され、全 10 施策 APPROVE・全体 **APPROVED**。残る指摘は Suggestion 3 件のみで、いずれも採用して設計へ反映済み。

## [Suggestion] checkAddition の非負 Assert を limit null 判定より前へ
- 判断: 対応する
- 対応内容: Assert 2 行を `$limit` 取得より前へ移動（無制限プランでも事前条件を保証。施策2）。

## [Suggestion] CSRF 再発行テストでトークン値の更新まで固定
- 判断: 対応する
- 対応内容: `http.test.ts` のケースに「再取得後の `csrfToken()` が更新後 cookie 値を読み、再送の X-XSRF-TOKEN が旧値から変わる」検証を追記（施策10）。

## [Suggestion] sweep() の時刻境界の一貫化
- 判断: 対応する
- 対応内容: `$now` / `$cutoff` を sweep() 冒頭で一度だけ生成し、一覧抽出と CAS 条件の両方で共有（施策9）。
