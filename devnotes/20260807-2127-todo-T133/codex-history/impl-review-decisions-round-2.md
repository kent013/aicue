# 対応マトリクス: impl-review Round 2

Round 2 の指摘は 1 件 ([Warning])。Round 1 の 3 指摘は「すべて適切に解消」と確認された。

## [Warning] DNF 型 (`(A&B)|C`) の括弧を越えられず、型付きキャッシュ受け手を見落とす

- 判断: **対応する**
- 根拠: 指摘のとおり。`cachePayloadReceiverNames()` の skip 集合は `|` / `&` / `?` だけで
  `(` / `)` を含まないため、`(Repository&Marker)|FallbackCache $cache` の `$cache` が
  受け手名に登録されない。**深刻なのは「既に role=write のファイルへこの形で足された場合」**で、
  L3 (面) の集合も L2 の件数も変わらず**緑のまま通る**。しかも冒頭コメントは
  「型宣言 (引数 / プロパティ / promoted ctor param) を見る」、実装コメントは
  「union / nullable / intersection を跨いで」と書いており、**説明と実態が食い違っていた**。
  誇張の是正という意味でも直すべき指摘。
- 対応内容:
  - skip 集合に `(` / `)` を追加し、DNF 型の括弧を跨げるようにした。
  - **副作用を同時に封じた**: 括弧を無条件に跨ぐと `cache($values, 60)` や
    `new Repository($store)` の**引数**が受け手名として登録され、無関係な `$values->put()` を
    キャッシュ書き込みと誤検出する。型名の**直後が `(`** の場合は型宣言ではなく
    呼び出し / インスタンス化なので、その時点で走査を打ち切るガードを入れた。
    (誤検出は「目録を意味の無い儀式に変える」方向の劣化なので、見落としと同様に潰す)
  - 負のコントロール fixture を 1 本追加 (DNF の順序 2 通り: `(A&B)|C` と `C|(B&A)`)。
  - 正のコントロール fixture を 1 本追加 (呼び出し / インスタンス化の引数を登録しないこと)。
  - 実装コメントを「union / nullable / intersection / DNF の括弧を跨いで」に更新し、
    説明と実態を一致させた。
- mutation M17: **既に role=write の** `FxRateService` へ
  `public function mutationProbe((\Illuminate\Contracts\Cache\Repository&\Stringable)|\Illuminate\Contracts\Cache\Store $c): void { $c->forever('k', new \stdClass); }`
  を追加 → 検査 2 が赤 (27 tests / 1 failed) を実測。修正前ならこの形は緑のまま通っていた。
  revert 後に 27 tests passed を再確認済み。
