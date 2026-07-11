# 対応マトリクス: conceptual-review Round 3

## [Warning] Service tx 内の resolver 呼び出しだけでは Default Project 削除競合を防げない
- 判断: 対応する
- 根拠: 指摘どおり。ロックなし取得は取得直後の削除競合を許す。
- 対応内容: `DefaultProjectResolver` を read/write の 2 メソッドに分離。
  - `resolve()`: 表示・redirect 用（ロックなし。capture.home / nav 導線 / 一覧表示）
  - `resolveForUpdate()`: 書き込み用（`lockForUpdate()` 付き解決。呼び出し側 tx 内で取得から
    pivot 更新完了まで Project 行ロックを保持）。ロール変更・招待受諾の pivot 書き込みは必ず
    こちらを経由。CategoryService の「Project 行ロック = 直列化点」既存規約とも整合。
  D2 に明記済み。
