# 対応マトリクス: impl-review Round 2

## [Critical] `$this->{'app'}` の動的メンバアクセスが未検出 (FakeWiringSourceScanner)

- 判断: **対応する**
- 根拠: 指摘は正しい。`appAccessIndexes()` は `$this` `->` `T_STRING(app)` の並びだけを見るため、
  `$this->{'app'}->bind(…)` は `disallowedContainerCalls()` にも `bindPairs()` にも
  `disallowedIndirectAccess()` にも現れない。既存 fake クラスを concrete に使えば 3-10 の集合も
  変わらないので、**inventory 未登録の差し替えを 1 本も赤くせず追加できる** = fail-open。
  mutation M9 を当てて修正前に素通りすることを確認した。
- 対応内容:
  1. `disallowedIndirectAccess()` に「`$this->` の**動的メンバアクセス**を一律禁止」する走査を追加した。
     判定は「`$this` の直後が `->` / `?->` で、その次が `T_STRING` **以外**」= fail-closed。
     これで `$this->{'app'}` / `$this->{"app"}` / `$this->{$property}` / `$this->$property` の
     4 形すべてを 1 つのルールで閉じる (指摘にある「静的に `app` と判定できない形も
     provider 内では fail-closed で禁止するのが設計意図と一致する」に沿う)。
     `appAccessIndexes()` 側を拡張して個別に許可する案は採らなかった —
     許可形を増やすほど「静的に判定できない書き方」の分類が増え、gate が緩む方向に働くため。
  2. 走査器 Unit テストに 5-22 (3 形の negative) を追加。
  3. mutation に M9 を追加し **3-9 が赤くなる**ことを実走で確認した。
- 併せて [Suggestion] のテスト順序も対応 (5-19 を 5-20 / 5-21 より前へ移動し、番号順に揃えた)。

## 検証

- `composer test -- --testsuite=Unit --filter=FakeWiringSourceScanner`: 22 passed / 0 failed (21 → 22)
- `composer test -- --testsuite=Architecture`: 381 passed / 0 failed
- mutation M9: `--testsuite=Architecture` で 3-9 が赤 → revert 後は全緑
- `vendor/bin/pint --test`: passed
