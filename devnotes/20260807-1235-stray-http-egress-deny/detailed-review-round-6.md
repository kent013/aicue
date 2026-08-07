## Round 5 指摘の解消判定

### S4 [Warning] 補間開始トークンを波括弧深度に含める
判定: 解消

修正契約は Round 5 の要求どおり、波括弧深度で `T_CURLY_OPEN` / `T_DOLLAR_OPEN_CURLY_BRACES` を開始側に含めています。加えて、`strayHttpEgressMatchingIndex()` 自体を直接固定する単体テストも追加されており、`"${json}"` 形で修正前に赤くなる入力になっています。

### S4 [Suggestion] 1 / 2
判定: 解消

`strayHttpEgressTokens()` の説明は補間文字列が複数トークンになる前提へ修正済みです。exemption enum の docblock も `preventStrayRequests(false)` 限定ではなく、引数付き `preventStrayRequests(...)` 全件という gate 契約に同期されています。

## §3 の反証に対する検算

提示された事実認定は妥当です。

`"value={$json}"` では `T_CURLY_OPEN` の `text` が `"{"` になるため、`text === '{'` だけの実装でも偶然開始側として数えられます。一方、`"value=${json}"` の `T_DOLLAR_OPEN_CURLY_BRACES` は `"${"` なので、`text === '{'` では拾えず、補間終端の `}` だけが深度を減らして closure 終端を早く見つける、という説明は筋が通っています。

したがって、Round 5 が提示した `{$json}` 形だけの単体テストは、修正前実装でも通る空振りテストになる、という帰結も正しいです。`${json}` 形を主回帰入力にし、`{$json}` 形を保険として併記する対応は適切です。

## この対応が作った新たな欠陥

なし。

## 全体判定

**APPROVED**