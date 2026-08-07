# 対応マトリクス: impl-review Round 2

## [Critical] case J は第 2 層が壊れたとき「実通信してから」赤くなる (回帰テスト自身が deny-by-default を破る)

- 判断: **対応する**
- 根拠: 指摘のとおり。M11 の実測ログがまさにその証拠で、第 2 層を外すと 1 本目が
  `api.frankfurter.dev` へ実際に送信され、301 リダイレクト先で framework 側の
  `StrayRequestException` が出ていた。「既定拒否を守るテストが既定拒否を破る」構造は
  本 TODO の目的に真っ向から反する。提案された全許可 fake は識別力を落とさずこれを解く。
- 対応内容:
  - case J の先頭に `Http::fake(['*' => Http::response('', 200)])` を追加。
    - 第 2 層あり → 最外側 middleware が stub より**先に** throw → 元 URL が accumulator に 1 件。
    - 第 2 層なし → stub が `'*'` に一致して 200 を返し、**例外も記録も送信も無い** → 赤。
  - これに伴い assertion の依存が redirect 挙動から切れたため、
    「元 URL 完全一致でなければ区別できない」旨の長いコメントは不要になったので簡潔化した
    (完全一致 assertion 自体は残す)。
  - S6 の「`'*'` fake でごまかさない」規律に対しては、詳細設計が認めている例外
    (「テストの主題が『外部呼び出しをしない』ことの検証で、どの URL であれ出たら異常な場合」)
    に該当する旨をコメントに明記した。
  - 再実測: baseline 10/10 緑 / M11 適用時 9/10 (case J のみ赤)。
    `mutation-evidence.md` §M11 を更新した。

## [Warning] `__invoke(callable $handler)` の callable signature 不足 (将来 tests を PHPStan 対象にしたとき)

- 判断: **対応する**
- 根拠: 詳細設計の「型注釈は解析対象に入っているかのように厳密に書く」方針に一致する指摘。
- 対応内容: `@param callable(RequestInterface, array<string, mixed>): mixed $handler` を追加。

## [Warning] `$m[1]` が PHPStan で shape narrowing されない (gate の LOOPBACK_HOSTS 1:1 テスト)

- 判断: **対応する**
- 根拠: 指摘のとおり Pest の `expect()` は静的解析上の narrowing にならない。
- 対応内容: `preg_match(...) !== 1` を明示分岐して `RuntimeException` を throw する形に変更し、
  そのあとで `$matches[1]` を参照するようにした (変数名も `$m` → `$matches` へ)。

## [参考] Browser lane 未実行の残余リスク

- 判断: **受容 (対応不能)**。
- 根拠: この環境には Playwright のブラウザバイナリが無く (`~/.cache/ms-playwright` 不在)、
  `composer test:browser` は本差分の有無に関わらず chromium / webkit 両レーンとも
  「Playwright is outdated」で全 14 本失敗する。差分起因ではない。
  Browser lane の配線自体は gate (`STRAY_HTTP_EGRESS_REQUIRED_LANES` に `Browser` を含む) が
  ソースレベルで強制しており、実動作確認だけが残余リスクとして残る。
  この事実は最終報告の blockers に明記する。
