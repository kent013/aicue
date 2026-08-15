# 対応マトリクス: conceptual-review Round 2

## [Critical] `ok` の行が 0 件で終了コード 3 は不適切 (観測不能と「観測できたが成立しなかった」の混同)

- 判断: **対応する**
- 根拠: 指摘のとおり。422 を未実行側へ倒す方針と真正面から矛盾していた。
  全操作が 403/422/500 で跳ねた走行は、主入力としては完全に成立しており、
  正しい結果は「終了コード 0 で全件を未実行 worklist に残す」である。
- 対応内容: 照合器の終了コード 3 の条件を
  「`executed_routes` が 0 件 (有効な観測行が無い)」へ変更した。
  `ok` / `blocked` の内訳は終了コードに影響させず、summary の
  `executed_ok_count` / `executed_blocked_count` として出す。

## [Warning] JSONL 追記の排他制御が概念設計に無い

- 判断: **対応する**
- 根拠: 行が混線して JSON が壊れると、正常な探索を観測基盤の競合で落とすことになる
  (嘘はつかないが、無用な失敗を生む)。
- 対応内容: 1 レコードを改行込みの 1 文字列に組み立て、
  `file_put_contents($path, $line, FILE_APPEND | LOCK_EX)` の **1 回の追記**で書く契約にした
  (既存 `BughuntCoverageMiddleware::appendJsonl` と同じ作法)。
  追記の戻り値が false のときは失敗マーカーを残す。

## [Warning] 「書き込み失敗は警告ログのみ」が失敗マーカー契約と食い違う

- 判断: **対応する**
- 対応内容: 制約欄の文言を
  「アプリ応答には影響させない。書き込み失敗は警告ログを出し、同時に失敗マーカーを best-effort で記録する。
   生成器がマーカーを検出したら終了コード 3」に統一した。

## [Warning] 完了条件と スコープ外 の不整合 (実走行を含むのか否か)

- 判断: **対応する**
- 対応内容: 成功条件を「自動テストで記録器 → JSONL → executed.json → 照合器 の一連を固定する」までとし、
  実 bug-hunt 走行は**リリース後の運用確認であって本 TODO の完了条件にしない**と明記した。

## [Suggestion] `unresolved_count` は shard ごとに持つ

- 判断: **対応する**
- 根拠: 特定 shard の設定不良や大量 404 を切り分けられる。集計コストはゼロに近い。
- 対応内容: executed.json の `unresolved` を `{shard: 件数}` の対応表にした。

## [Suggestion] 配線検証は `terminate()` の直接呼び出しではなく実 HTTP で

- 判断: **対応する**
- 対応内容: Feature テストは `$this->get(...)` / `$this->post(...)` で実際に kernel を通し、
  `bootstrap/app.php` の登録ごと検証する形にすると詳細設計へ書く。

## [Suggestion] 型安全性 / 反論 3 点の承認

- 判断: 承認を受領。詳細設計の「PHPStan 適合チェック」に、提示された 6 条件
  (route の絞り込み / route 名の `is_string` + 完全一致検査 / 組み立て前に確定型へ絞る /
   JSON 化失敗の明示処理 / array shape / `mixed` をパス結合・正規表現へ渡さない) を反映する。
