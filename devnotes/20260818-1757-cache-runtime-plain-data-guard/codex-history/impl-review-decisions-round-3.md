# 対応マトリクス: impl-review Round 3

## [Critical] 同一名前空間の短名を完全修飾名へ解決できず、guard 実装クラスの継承禁止を迂回できる

- 判断: **対応する**
- 根拠: 指摘のとおり `cachePayloadResolveName()` は現在の名前空間を受け取っておらず、
  `namespace Tests\Support\Cache; class X extends PlainDataGuardedRepository {}` は
  短名のまま比較されて母集団から外れていた。AGENTS.md 走査規約 (a) の違反であり、
  「合法な未解決形は存在しない」という Round 2 の整理への反例でもある。
- 対応内容:
  - `cachePayloadNamespace()` を新設し、`namespace A\B;` を抽出する
  - `cachePayloadResolveName()` に `$namespace` を渡し、
    **取り込みにも無い非完全修飾名は現在の名前空間からの相対**として解決する
  - `namespace\Foo` (`T_NAME_RELATIVE`) を解決する。名前判定の token 集合にも
    `T_NAME_RELATIVE` を足した (継承句 / 受け手型 / 収集ループ / `new` の対象)
  - 全呼び出し元 (受け手型の解決 / 継承句 / コンテナ束縛の引数 / 収集ループ) へ
    名前空間を通した
  - 負例に**同一名前空間の短名**と `namespace\Foo` の 2 形を追加した
  - 正例として「完全修飾名 / 別名 / 同一名前空間の短名 / 相対参照」の 4 経路が
    同じ完全修飾名になることと、名前空間の中の裸の名前が global へ落ちないことを固定した
  - `use Cache;` の facade 特例が取り込み表の分岐でも効くことを確認した
    (既存の正例が一度赤くなったので、取り込み解決の後にも特例を適用する形へ直した)
  - 冒頭 docblock の「合法な未解決形は無い」の記述に、
    **名前の解決は取り込み表 → 現在の名前空間の順で行い完全修飾名で突き合わせる**ことを併記した

## [Warning] W2/W3 が try と finally の対応関係を見ていない

- 判断: **対応する**
- 根拠: 指摘の合成例のとおり、独立に探すと「flush を持つ finally 無しの try」と
  「別の try-finally」を組み合わせた形が通ってしまう。flush が投げると reset へ到達しない。
- 対応内容: `cacheGuardTryStatement()` を新設し、try ブロックの直後の `catch` 群を読み飛ばして
  **その try 文自身に属する finally** だけを組にして返す形にした。
  finally を持たない try しか無ければ違反である。
  負例「reset が別の try 文の finally にある」を追加した。

## [Warning] W4 が動的な `uses($trait)` を保証外にしている

- 判断: **対応する (未解決として落とす側を選んだ)**
- 根拠: 指摘のとおり、保護対象の状態を作れる構文を保証外へ書くだけでは AGENTS.md (b) に
  適合しない。通常の `uses(X::class, Y::class)` はすべて名前で書かれるので誤検出は出ない。
- 対応内容: `uses()` の引数に名前として解決できない token があれば
  `UNRESOLVED_USES(...)` を返し、W4 が落ちる形にした。
  負例 (`$trait = WithCachedConfig::class; uses($trait);`) と
  正例 (`uses(TestCase::class, RefreshDatabase::class)`) を追加した。

## [Suggestion] 動的 `new` の目録はファイル単位の件数なので用途は機械検証していない

- 判断: **対応する (docblock へ明記した)**
- 対応内容: 冒頭 docblock の「保証しないもの」へ
  「`rationale` は人間の申告で、機械は件数の exact-fit しか見ない。同じファイルの中で
  許可済みの生成をキャッシュの保管先の生成へ置き換えると、件数が変わらない限り検出できない」
  と書いた (L2 の `payload` 欄と同じ扱いであることも併記)。

## [Suggestion] guide の記述が「自己テストだけを exact-fit」のままだった

- 判断: **対応する**
- 根拠: Round 2 で直したつもりだったが、置換が当たっておらず旧文が残っていた。
- 対応内容: 迂回の pin を **3 つの目録** (境界 API と直接生成 / 継承・実装 / 動的生成) として
  書き分けた。

## [Suggestion] D30 が L4h と動的生成の目録を含んでいない

- 判断: **対応する**
- 対応内容: 「揃え続ける不変条件と保証機構」を L4a-L4h へ更新し、
  観点表へ「静的に解決できない生成 (`new $class`)」の行を追加した。
