# 対応マトリクス: impl-review Round 3

> ⚠ 本ラウンドが**合議の上限 (3 ラウンド)** だった。Round 3 の全体判定は `CHANGES_REQUESTED` で、
> 指摘は [Critical] 1 件 + [Warning] 1 件 (同じ事象の別側面) である。
> **上限に達したため 4 ラウンド目は回していない**が、指摘は正当だったので**修正は入れた**。
> 修正内容と実測は以下のとおりで、最終判定は親エージェント側の裁量に委ねる。

## [Critical] S4-3c は先行する `return;` による無力化を検出できない

- 判断: **対応する** (指摘は正しい。実際に無力化できた)
- 根拠: Pest のテストファイルは素のスクリプトなので、`ArchBaselineTest.php` の最上位に
  `return;` を 1 行足すと**禁止表明 7 本も S1〜S5 も丸ごと登録されない**。
  そのとき **S4-3c 自身も登録されない**ので、自己検査では原理的に検出できない。
  自己参照の穴であり、Round 2 の「brace-less `if`」とは別経路である。
- 対応内容: 指摘のとおり**外部自己検査**を足した。
  - `ArchSurfaceScanner::topLevelAbortSites()` を新設。
    波括弧の深さ 0 に現れる `return` / `exit` / `die` / `throw` / `goto` / `__halt_compiler`
    の位置を返す純関数である。深さの計算は `braceDepthAt()` と**同じ表** (`braceDepths()`) を
    使う (数え方の写しを 2 つ持たない)。
  - `tests/Unit/Architecture/ArchBaselineScannerTest.php` に**テスト 38** を追加。
    **別ファイルから** `ArchBaselineTest.php` を読み、
    (a) 最上位に実行を打ち切る文が 0 件、(b) チェーンが `EXPECTED_CHAIN_TOKENS` と完全一致、
    (c) 囲みが `EXPECTED_CHAIN_HEADER_TOKENS` と完全一致、(d) その囲みの外に波括弧が無い、
    の 4 点を確かめる。
  - 走査器の負例 **13e** を追加。最上位の `return;` を拾い、
    **関数・クロージャ・制御構造の本体にある `return` / `throw` は拾わない**ことを固定した
    (`exit` / `die` / `throw` の 3 形も最上位なら拾うことを併せて固定)。
  - **注入で実測**: `ArchBaselineTest.php` の最上位へ `return;` を入れると
    **同ファイルの 41 本が全滅して 40 tests になり、テスト 38 が赤**になった。
    取り除くと 81 tests 全緑に戻る。
  - `ArchBaselineTest` の docblock と S4-3c のコメントに
    「本ファイルの中からは原理的に検出できない穴があり、外部自己検査 (テスト 38) が見張る」ことと、
    **その外部検査自身が同じ手口で短絡されたら検出しない** (検査を見張る検査は無限に続くので
    置かない。最後の砦は git のレビュー) ことを明記した。

## [Warning] 乖離台帳の「実行時に確かめる」という記述が先行 `return` に対して成立しない

- 判断: **対応する** (上と同じ修正で成立するようにし、記述も揃えた)
- 対応内容: D43 の「揃え続ける不変条件と保証機構」行を
  「…7 本が実際に Pest へ登録されたことまで実行時に確かめ、**宿主ファイルが最上位の return 等で
  短絡されていないことは別ファイルの外部自己検査が見張る**」へ更新した。

## [解消済み] PHPStan / braceDepthAt の保証範囲 / 13c / その他の走査器

Round 3 で「解消」「問題なし」と判定された。追加変更なし。

## 修正後の実測

- `composer test -- --filter=ArchBaseline`: **81 tests / 81 passed / 182 assertions**
- `composer test` (全数): **6763 tests / 0 failed** (本修正の追加 2 本を含む再実行結果は報告本文)
- `vendor/bin/phpstan analyse --level=10 tests/Support/Architecture tests/Architecture/ArchBaselineTest.php tests/Unit/Architecture/ArchBaselineScannerTest.php`: **0 errors**
- `composer phpstan`: No errors / `vendor/bin/pint --test`: passed
- 無力化の注入 3 形すべてで**必ずどれかの検査が赤くなる**ことを実測した:

  | 注入 | 赤くなる検査 |
  |---|---|
  | `if (false) { foreach … }` (波括弧つき) | S4-3b (深さ 1) と S4-3c |
  | `if (false)` + 改行 + `foreach …` (波括弧なし) | S4-3c (7 規則 ID を missing と報告) |
  | 最上位の `return;` | 外部自己検査 (テスト 38) |
