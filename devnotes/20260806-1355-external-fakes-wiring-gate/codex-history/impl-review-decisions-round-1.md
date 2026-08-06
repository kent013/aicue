# 対応マトリクス: impl-review Round 1

## [Critical] `disallowedIndirectAccess()` が use alias を解決していない (FakeWiringSourceScanner)

- 判断: **対応する**
- 根拠: 指摘は正しい。`use Illuminate\Container\Container as C;` + `C::getInstance()->bind(…)` は
  (a) `$this->app->…` ではないので `disallowedContainerCalls()` / `bindPairs()` に出ず、
  (b) 既存 fake クラスを concrete に使えば 3-10 の参照集合も変わらないため、
  **inventory 未登録の差し替えを 1 本も赤くせずに追加できる** = fail-open。
  実際に mutation M8 / M8b を当てて修正前は 3-9 が緑のまま素通りすることを確認した。
- 対応内容:
  1. `parseUseStatement()` を「クラス use map」と「**関数 use map**」に分離した
     (`use function app as container;` を捨てずに保持する。`use const` は container 到達に
     関与しないので従来どおり捨てる)。
  2. `disallowedIndirectAccess()` の判定を書き換えた:
     - helper 関数は `resolveFunctionName()` で **alias を解いた末尾セグメント**を
       `CONTAINER_HELPERS` と照合する。
     - 静的起点は `resolve()` で **FQCN 化**して `CONTAINER_STATIC_FQCNS`
       (`Illuminate\Support\Facades\App` / `Illuminate\Container\Container`) と照合し、
       **加えて**従来の末尾セグメント照合 (`CONTAINER_STATIC_ROOTS`) も残した
       (use が無く FQCN 解決が現在 namespace 配下になる `App::` を取りこぼさない fail-closed の二重線)。
  3. 走査器 Unit テストに negative ケースを 2 本追加:
     - 5-20: `Container as C` / `App as LaravelApp` の alias 経由を各 1 件検出する
     - 5-21: `use function app as container;` の alias 経由を 1 件検出する
  4. mutation に M8 / M8b を追加し、**3-9 が赤くなる**ことを実走で確認した
     (`devnotes/.../mutation-evidence.md` 段階 3 の表に追記)。

## 検証

- `composer test -- --testsuite=Unit --filter=FakeWiringSourceScanner`: 21 passed / 0 failed (19 → 21)
- `composer test -- --testsuite=Architecture`: 381 passed / 0 failed
- `vendor/bin/pint --test`: passed
