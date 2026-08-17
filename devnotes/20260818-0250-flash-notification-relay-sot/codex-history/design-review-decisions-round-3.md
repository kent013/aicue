# 対応マトリクス: design-review Round 3

全体判定 CHANGES_REQUESTED (Critical なし / 必須修正 3 点)。**全件対応**した。

## [Warning] 施策 1: 別 helper による後勝ち上書きが依然可能
- 判断: 対応する (Round 2 で書いた「別名 helper 経由はクラス名込み検査で塞がる」という
  説明が誤りだったことを認める)
- 根拠: 正しい委譲 entry の後ろに `'flash' => OtherFlashBuilder::build($request)` を置けば
  現在の 3 検査を全部通る。prop 名を**文字列リテラルで**書き直す口が空いていた。
- 対応内容: 検査を 1 つ足した。
  `expect(phpStringLiterals($middleware))->not->toContain(FlashNotificationRelay::SHARED_PROP_KEY);`
  これで prop 名は定数 1 回だけになり、リテラル表記と定数表記の併存もできない。
  併せて `session` / `uuid` の 0 件固定の説明を直し、
  **これ単独では別 helper 経由を防げない** (それは委譲の形と prop 名リテラル禁止が担当) と明記した。

## [Warning] 施策 3: `$location` が PHPStan 上で `?string` のまま
- 判断: 対応する
- 根拠: 正当。`expect()->toBeString()` は静的解析の narrowing ではない
  (Round 1 で `renderedFlash` に同じ指摘を受けたのに、追加ケースで再発させていた)。
- 対応内容: `if (! is_string($location)) { throw new RuntimeException(…); }` に直した。

## [Warning] 施策 5: 文字列検索はコメント・文字列リテラルでも成立する (degenerate PASS)
- 判断: 対応する
- 根拠: 正当。`// consumeFlash(readFlash(` だけ残しても緑になる。
- 対応内容: 走査を**構文木**へ変えた (`typescript` は devDependency に既にある。実測 6.0.3)。
  - 目録は「`@/lib/stores/flash-to-toast` から `consumeFlash` を import しているファイル」
    に変えた (import は markup 側で呼ぶ場合も必要なので取りこぼさない)
  - 呼び出しは `ts.createSourceFile` の構文木から集め、**第 1 引数が `readFlash(...)` の
    呼び出しであること**を見る
  - 指定された負のコントロール (コメント / 文字列リテラルの fixture は 0 件、
    実コードの形は 1 件) をテストに追加した
  - 保証しないもの (動的な名前での呼び出し / `.svelte` は `<script>` 区間のみ /
    変数を挟む書き方は赤になる) を明記した
