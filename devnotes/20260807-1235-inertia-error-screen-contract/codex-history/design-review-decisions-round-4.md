# 対応マトリクス: design-review Round 4

Codex 全体判定: CHANGES_REQUESTED
(S1〜S3 APPROVE / S4 REQUEST_CHANGES / S5・S6 APPROVE)
[Critical] 0 件・[Warning] 1 件・[Suggestion] 1 件。

## [Warning] S4: `Cache-Control` の実装が記載した契約を満たしていない

- 判断: **対応する** (Codex 提示の修正案をそのまま採用)
- 根拠: 指摘は正確。前案の
  `if (! hasCacheControlDirective('no-store')) { headers->set('Cache-Control', 'no-store, private'); }`
  には 3 つの欠陥があった:
  1. 既存が `no-store, public` だと `private` が付かない
  2. `no-store` が無く `must-revalidate` 等がある場合、`set()` が既存 directive を消す
  3. テストが `no-store` しか見ておらず、設計が約束した `private` と directive 保持を固定していない
  「no-store だけで保存禁止としては十分」という指摘も正しいが、
  **設計本文とリスク表が `private` を書いている以上、実装とテストをそちらへ揃える**のが筋
  (契約を狭める側の選択肢は、既に書いた「セッション依存だから private」の根拠を捨てることになる)。
- 対応内容:
  - 実装を `addCacheControlDirective('no-store')` + `setPrivate()` に変更。
    `Symfony\Component\HttpFoundation\Response::setPrivate()` が
    `removeCacheControlDirective('public')` + `addCacheControlDirective('private')` である
    ことを vendor (Response.php L592-598) で確認済み。加算方式なので既存 directive を落とさない
  - テストを 3 本に分割・強化:
    (a) guest 応答に `no-store` と `private` があり `public` が残らない + Vary 3 種、
    (b) 既存の `must-revalidate` 等の directive を落とさない、
    (c) 認証済み (既に no-store) でも二重付与・矛盾が起きない
  - リスク表 6 番を「既存 directive は落とさず加算」に更新
  - mutation 表に M17 (`addCacheControlDirective` を `set()` に戻す) を追加

## [Suggestion] S4: 「セッション由来の分岐は Vary では宣言できない」は言い過ぎ

- 判断: **対応する**
- 根拠: 正当。`Vary: Cookie` は原理的には可能で、採らないのは
  「キャッシュキーの爆発と cookie 全体への依存を招くため」という**判断**である。
  技術的に不可能であるかのように書くと、後から読む人が選択肢を誤認する。
- 対応内容: コメントとリスク表の表現を
  「原理的には `Vary: Cookie` で宣言できるが、キャッシュキーの爆発と cookie 全体への依存を
  招くため採らない」に修正。
