# 対応マトリクス: impl-review Round 1

## [Critical] `AuthThrottleCoverageTest` の「inline へ戻す変更を入れたらここが必ず落ちる」が M8 実測と矛盾する

- 判断: **対応する**
- 根拠: 指摘のとおり。mutation M8 (2FA 管理だけを inline へ戻す) で本セクションは緑のままだった。
  巻き添え先が named レーンに居る限り、1 本の差し戻しでは巻き添えが復活しないためである。
  コメントの断定は**その場で反証されている嘘**であり、この codebase が最も嫌う
  「保証範囲の誇張」に当たる。mutation-evidence.md には実態を書いてあるのに
  コード側のコメントが古い主張のままだと、次に読む人はコメントを信じる。
- 対応内容: T125 セクション冒頭のコメントを書き換え、
  (a) 本セクションが固定するのは**巻き添え 429 の消滅**であること、
  (b) 1 本だけ inline へ戻しても緑のままになりうること、
  (c) **inline 差し戻しそのものの検出は目録 gate (`InlineThrottleInventoryTest` の
      「未登録」/ `ThrottleLaneAssignmentTest` の「割当一致」「レーンはすべて 1 本以上」) の担当**
  であることを明記し、「両者はセットで維持すること」を添えた。

## [Warning] `passport.token` の根拠文字列が「$request->user() は常に null」と断定している

- 判断: **対応する**
- 根拠: 同ファイル上部の premise docblock が
  「`StartSession` が無い」は「`$request->user()` が絶対に null」を意味しない、と
  保証範囲を明示的に限定しているのに、根拠文字列だけが強い断定になっていた
  (ファイル内で非対称)。指摘のとおり弱い側が正しい。
- 対応内容: 目録の根拠 2 本 (`passport.token` / `passport.device.code`) を
  「StartSession も認証 middleware も通らない構造のため、**session guard 経由で
  user へ倒れる経路が無い**」という書き方へ弱めた。
  併せて `inlineThrottleCasePremises()` の stateless 側インラインコメントと、
  `InlineThrottleBucketRationale::VendorStatelessIpBucket` の docblock にも
  同じ保証範囲の注記を入れ、3 箇所の表現を揃えた
  (enum 側は app/ にあるため、実装を読む人が最初に当たる場所である)。

## [Suggestion] `livewire.upload-file` の「bucket を専有する」は対象が曖昧

- 判断: **対応する**
- 根拠: 「専有」は guest/IP 側まで含む主張に読める。実際に唯一なのは
  **認証済み actor の inline bucket** についてのみで、未認証時に IP へ倒れる分は
  passport 2 本と同じ性質を持つ。指摘のとおり対象を明示した方が主張が締まる。
- 対応内容: 根拠文字列を「認証済み actor の inline bucket を使う route はこれ 1 本だけ」に改め、
  未認証側については passport 2 本と同性質であることを併記した。

## Round 1 で「問題なし」と判定された箇所

`RateLimiterKeys` / `FortifyServiceProvider` / `AppServiceProvider` / `routes/web.php` /
`config/fortify.php` / `ThrottleLaneAssignmentTest` / `RateLimiterKeyConventionTest` /
`RateLimiterKeysTest` / AGENTS.md・docs — 変更なし。

M8 の整理そのものは「妥当」と判定されたため、mutation-evidence.md の記述は維持する。
