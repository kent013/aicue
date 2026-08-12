# 対応マトリクス: design-review Round 2

判定 REQUEST_CHANGES。Critical 0 / Warning 3 / Suggestion 1。**すべて対応**(反論なし)。

## [Warning] 契約 7 / 7b が「配下」しか見ておらず、直下パターンの削除を検出できない

- 判断: **対応する**
- 根拠: そのとおり。`'oauth'` / `'.well-known'` を定数から消しても、配下しか検査しないなら緑のまま。
  **Round 1 で直したことがテストで守られていなかった**。
- 対応内容: 契約 7 を dataset 化し、**直下と配下の 4 件** (`/oauth` / `/oauth/no-such-path` /
  `/.well-known` / `/.well-known/no-such-path`) を検査する形にした (7b は 7 に統合)。

## [Warning] fail 先行の対象に契約 7b が無い / `Not Found` は既定文言と一致しうる

- 判断: **対応する**
- 根拠: 妥当。`Not Found` は Laravel 既定の 404 message でもあるため、機械向け経路の契約は
  **実装前から偶然緑**になりうる。
- 対応内容: fail 先行の対象を書き直し、「**偶然一致しているだけで実装前後の意味が違う**ことを
  実測として記録し、fail-first の対象から外した理由を書く」と明記した (誇張しない)。

## [Warning] Architecture テストの実装方式が二択のまま。token/AST を必須にせよ

- 判断: **対応する**
- 根拠: 正規表現では named argument の引数順不同・ネスト・コメント/文字列中の疑似コード・
  完全修飾名/alias を安定して扱えない。**不変条件として登録するなら構文的判定が必須**。
- 対応内容: 「**token ベースに固定する (正規表現へフォールバックしない)**」と明記し、
  扱うべきケース 4 種を列挙した。既存 `Tests\Support\PhpReferenceScanner` が
  名前空間解決と alias 追跡を持つので適合するならそれに乗る、と方針も確定した。

## [Suggestion] 契約 4 の「dataset で分ける」と「1 本に集約」が併記されていて曖昧

- 判断: **対応する**
- 対応内容: 「**このテストファイルに集約**し、ファイル内では status ごとに dataset を分ける」と
  書き換えた。
