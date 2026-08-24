# 対応マトリクス: impl-review Round 4 (Round 3 は判定の 1 語のみだったため内訳を再要求した)

## [Warning] `resolveClassName()` が `namespace\X` (T_NAME_RELATIVE) を誤解決する

- 判断: **対応する**
- 根拠: 指摘のとおり `namespace\LocalException` を `Tests\Probe\namespace\LocalException` へ
  解決していた。`namespace` は予約語なので本物の名前空間の要素にはならず、誤解決は必ず不一致になる
  (= 本来一致すべき生成を見つけられない = 構造検査が空振りする)。
- 対応内容:
  - 先頭要素が `namespace` の綴りを**現在の名前空間からの相対参照**として解くようにした
    (取り込み表より先に判定する)。
  - 相対参照で要素が続かない形 (`namespace\` 単体) は例外にした (fail-closed)。
  - docblock に「解ける形は 3 つ (完全修飾 / 相対 / 取り込み・グローバル fallback)」を明記した。
  - 正例を追加した — `new namespace\RawEnvChannels()` が
    `Tests\Support\RawEnv\RawEnvChannels` として 1 件検出され、
    `new namespace\RawEnvWriteSite()` は同じ期待クラスに一致しないこと (誤検出しない)。

## [Warning] 最新の `composer test` 全数が green でない

- 判断: **対応する** (再実行で解消)
- 根拠: 指摘のとおり「全数 green」の代替にはならない。
- 対応内容: 修正後に全数を 2 回走らせ、いずれも green を確認した。
  - 修正前の状態: 7834 tests / 7832 passed / 2 skipped / 0 failed
  - 本修正を含む最終状態: 7835 tests / 7833 passed / 2 skipped / 0 failed
  - 先に 1 度落ちた `tests/Architecture/BughuntSelfTestExecutionTest.php` は、
    同一コンテナで他プロセスが走っているときにプロセスグループの所有確認が揺れる**既存の
    不安定テスト**であり、本差分とは無関係である (同ファイル単体では 3 passed、
    全数でも 2 回連続 green)。
