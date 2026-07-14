# 対応マトリクス: conceptual-review Round 2

## [Warning] テスト方針が概念設計に未記載（テストファースト原則）
- 判断: 対応する
- 対応内容: 概念設計に「テスト方針」節を追加。predicate 単体テスト（ready/published=true、他=false）、
  Show/Edit の対象状態のみリンク表示、Show で canManage=false でもリンク表示、リンク先 URL が対象
  project/manual を使う、を明記。詳細なテストケースは詳細設計の「テスト計画」で確定。

## [Warning] 型安全性: 単なる配列では status 追加時にコンパイルエラーにならない
- 判断: 対応する
- 根拠: 妥当。網羅性を型で保証するには exhaustive map が最適。
- 対応内容: `CAPTURE_NAVIGABLE_STATUSES` を配列ではなく
  `satisfies Record<VideoManualStatus, boolean>` の対応表 `CAPTURE_NAVIGABLE_BY_STATUS` として定義し、
  `VideoManualStatus` に case が増えたら型エラーで検知できる設計にする。`isCaptureNavigable()` はこの表を引く。
