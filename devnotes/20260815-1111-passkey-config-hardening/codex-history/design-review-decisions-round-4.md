# 対応マトリクス: design-review Round 4

## [Warning] validator が大文字 host を reject しない (正規表現が `[A-Za-z0-9.-]` で、取得後に小文字化していた)
- 判断: 対応する
- 根拠: 指摘のとおり実装と契約・テスト計画が矛盾していた。`https://APP.example.com` は
  正規表現を通り、`strtolower()` された後の値で照合されるため正常終了してしまう。
- 対応内容: **小文字だけを通す形に一本化**した。
  - origin の正規表現を `#^https://([a-z0-9.-]+)(?::(\d{1,5}))?$#` に変更し、
    取得後の `strtolower()` を削除した (`$host = $m[1]`)。
  - `isDnsName()` のラベル正規表現も `[a-z0-9]` に変更し、末尾ラベルの英字要求も `[a-z]` にした。
    これで **身元の識別子側の大文字も同じ規則で reject** される (2 箇所に別の規則を持たない)。
  - docblock に「大文字を受理しない理由」(宣言側が正規化する / 未正規化値は
    webauthn-lib の strict 比較で無言に壊れる) を書いた。
  - テスト dataset に `APP.example.com` を追加した。

## [Suggestion] `raw_allowed_origins` の説明が「trim のみ」のままで実装と一致しない
- 判断: 対応する
- 対応内容: config コメントと validator の phpdoc を
  「フィルタ前の接続元列 (trim・小文字化済み、空要素を保持)」に直し、
  「ここでの『生』は env の原文ではなく『空要素を除去する前』の意味」と明記した。

## [Suggestion] リスク欄の版 pin 担当範囲が不正確 (Fortify は pin 対象ではない)
- 判断: 対応する
- 対応内容: リスク欄を「版 pin が守るのは laravel/passkeys の 0.2 系だけ。
  写像を持つ laravel/fortify は pin せず (1.x semver)、sentinel を使った実効値の契約テストが守る」に直した。

## [Suggestion] `ReflectionMethod::setAccessible(true)` は現行 PHP では必須ではない
- 判断: 見送る（現状維持）
- 根拠: 機能上の問題は無いと Codex も認めている。**vendor の protected API へ意図的に結合している**ことを
  読み手に示す標識として残す方が、後から読む人に親切である。
