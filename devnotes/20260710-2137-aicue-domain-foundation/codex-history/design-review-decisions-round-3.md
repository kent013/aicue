# 対応マトリクス: design-review Round 3

全体判定: CHANGES_REQUESTED（Round 3、親ロック後の子再解決に限定）→ 対応の上 Round 4 へ。

## [Critical] CategoryService::update/delete がロック済み親から子を再解決していない
- 判断: 対応する
- 根拠: 正当。子は親に属する不変条件を Service 境界でも担保すべき。route binding 以外の経路で cross-project 更新が可能になる。
- 対応内容: update/delete で `$lockedCategory = $locked->categories()->whereKey($category->id)->firstOrFail()` を実行し、以後 `$lockedCategory` のみ使用。

## [Critical] VideoManualService::updateMeta/delete にも再解決契約を明記
- 判断: 対応する
- 対応内容: `$locked->manuals()->whereKey($manual->id)->firstOrFail()` で再解決してから更新・削除、と明記。category も `$locked->categories()` から再解決 associate / dissociate。

## [Warning] Service 一覧が update 欠落で不一致
- 判断: 対応する
- 対応内容: CategoryService を `create / update / reorder / delete` に修正。

## [Warning] Service 直接呼び出し時の境界テスト不足
- 判断: 対応する
- 対応内容: 施策12 に「別 project の Category/VideoManual を Service update/delete に渡すと 404、DB 無変更」を追加（route の cross-project 404 とは別の Service 境界テスト）。Service 境界の不変条件節も施策7 冒頭に追記。
