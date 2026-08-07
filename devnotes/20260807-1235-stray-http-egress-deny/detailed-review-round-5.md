## Round 5 判定

Round 4 の必須修正は解消しています。hook引数が直接closureリテラルであること、単一引数であること、取得不能時のfail-closedまで契約化されています。

ただし、トークン列方式に移行したことで、補間文字列の波括弧処理に1件の設計不整合が残っています。

### S1

**判定: APPROVE**

変更ありません。

### S2

**判定: APPROVE**

変更ありません。

### S3

**判定: APPROVE**

変更ありません。

### S4

**判定: REQUEST_CHANGES**

[Warning] `strayHttpEgressMatchingIndex()` が、補間文字列の開始トークンを波括弧の開始として数える契約になっていません。

PHPの補間文字列は必ずしも1トークンに畳まれません。例えば、

```php
$value = "value={$json}";
```

は概念的に次のようなトークン列になります。

```text
T_ENCAPSED_AND_WHITESPACE("value=")
T_CURLY_OPEN("{$")
T_VARIABLE("$json")
"}"
```

PHPStanチェックリストでは、記号トークンの `{` / `}` を `text` 比較するとしています。このまま単独の `{` だけを開始として数えると、`T_CURLY_OPEN` は数えられず、補間終端の単独 `}` だけがclosure深度を減らします。その結果、closure終端を早く見つける可能性があります。

提示された「JSON文字列 / 補間 / heredoc」の負のコントロールは、この問題により実装時に赤くなるはずです。空振りではありませんが、設計されたアルゴリズムのままではそのテストを通せません。

修正案:

- `{` / `}` の対応を調べる場合、次を開始側として深度に加える。

```php
$token->text === '{'
    || $token->is(T_CURLY_OPEN)
    || $token->is(T_DOLLAR_OPEN_CURLY_BRACES)
```

- 終了側の単独 `}` は通常どおり深度を減らす。
- `(` / `)` の探索ではこの追加処理を行わない。
- PHPStanチェックリストを「記号トークンだけtext比較可」から、補間開始トークンをID判定する例外込みに修正する。
- 次の単体テストを `strayHttpEgressMatchingIndex()` 自体に追加する。

```php
$tokens = strayHttpEgressTokens(
    '<?php function () { $a = "value={$json}"; guard(); }',
);
```

この入力で、返される対応位置が補間の `}` ではなくclosure末尾の `}` になることを直接固定してください。

[Suggestion] `strayHttpEgressTokens()` の説明にある次の表現は修正した方が正確です。

> literal は1個の `T_CONSTANT_ENCAPSED_STRING` / `T_ENCAPSED_AND_WHITESPACE` にまとまり

単一引用符や補間のない文字列は概ねその説明でよいものの、補間文字列は複数トークンに分割されます。「文字列内容の括弧は文字列系トークン内に保持され、構文上の補間境界は専用トークンで識別できる」としてください。

[Suggestion] exemption enumのクラスdocblockは、検出対象を現在も `preventStrayRequests(false)` と記載しています。実際の契約は「引数付き `preventStrayRequests(...)` 全件」なので同期してください。

### S5

**判定: APPROVE**

変更ありません。UI/frontendは非該当です。

### S6

**判定: APPROVE**

変更ありません。

## 全体判定

**CHANGES_REQUESTED**

Round 4 の必須修正と、文字列grep由来の過去の問題は解消しています。残る必須修正は、`strayHttpEgressMatchingIndex()` が `T_CURLY_OPEN` と `T_DOLLAR_OPEN_CURLY_BRACES` を波括弧深度へ含めることです。現状の負のコントロールはこの問題を検出できますが、アルゴリズムの契約側にも明記が必要です。