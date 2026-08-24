# Round 4: Round 3 の指摘への対応と再レビュー依頼

Round 3 の Warning 2 件 (実現可能性・型安全性はいずれも `json_decode` の旗の件) を対応しました。

## 対応マトリクス

# 対応マトリクス: conceptual-review Round 3

## [Warning] / [Warning](型安全性) `json_decode($json)` は `JsonException` を投げない (構文エラーで `null` を返す)
- 判断: 対応する
- 根拠: PHP の事実として正しい。旗を付けないと「構文エラー」と「JSON 値 null」を区別できず、
  fail-closed の分類が 1 つ潰れる。
- 対応内容: 呼び出しを `json_decode(json: $json, associative: false, flags: JSON_THROW_ON_ERROR)` と
  明記し、`JsonException` = 構文エラー / decode 成功後の非 `stdClass` = トップレベル型違反 の
  2 種を別々に fail-closed で扱うことを概念設計に書いた (深さは既定値のまま省略)。

## [Suggestion] 使命 / テストファーストの分離 / S06b の 4 点 / i8 の具体化 / 保証の 3 層 / リスクの受容 / スコープ
- 判断: 見送る (現行のままで趣旨を満たしている)
- 根拠: いずれも Round 2 までの修正で解消済みという評価。

## 該当箇所の修正後の記述 (概念設計 改善アイデア 5)

5. i10: ローカル層のトップレベルを全数申告制にする (申告に `hooks` を含めない)。判定は
   **純関数**へ切り出し、契約を 3 つに固定する — (a) ファイル不在は合格 (常設配線を上書きする経路が
   そもそも無い)、(b) 存在するときは**全キーを申告と完全一致で照合**する (許可外が 1 つでもあれば違反)、
   (c) **`json_decode($json, true)` を使わない** — 連想配列へ直接落とすと空オブジェクト `{}` と
   空配列 `[]` がどちらも `[]` になり、「object でない」の負例が正例と同じ結果になって裏取りが
   形骸化する。`json_decode(json: $json, associative: false, flags: JSON_THROW_ON_ERROR)` で受けて
   `Assert::isInstanceOf($decoded, stdClass::class)` で narrow し、`get_object_vars()` で
   キー集合 (`list<string>`) を得る。**旗は省略できない** — 付けないと構文エラーで `null` が返り、
   「構文が壊れている」と「JSON 値が null」を区別できなくなる。fail-closed の分類は 2 種で、
   `JsonException` = 構文エラー / decode 成功後の非 `stdClass` = トップレベル型違反 として
   別々に落とす。値は照合に使わないので型を広げない。
   検出力は合成入力で裏取りする — 負例 3 形 (`hooks` を持つ / 未知の項目を持つ / object でない) と
   正例 (空オブジェクト)。
