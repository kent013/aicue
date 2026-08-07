# 対応マトリクス: design-review Round 1

[Critical] 2 件・[Warning] 3 件・[Suggestion] 1 件。**すべて対応**（反論なし）。

## [Critical] S8 の behavioral proof が false green になりうる
- 判断: **対応する**（指摘の前提の一部は事実と異なるが、指摘の帰結は正しい）
- 根拠:
  - 事実確認: `settings.password.store` の実効 middleware 順は
    `Authenticate` → `ThrottleRequests` → `EnsureEmailIsVerified` → `RequireRecentAuth`
    （`route:list` 実測。既存テスト「2FA 管理 route は throttle が recent-auth より先に走る」と同型）。
    したがって **recent-auth が throttle より前に短絡することは現状では起きない**。
  - しかし指摘の本質「`not 429` だけを見る probe は、throttle が走らなかった場合も緑になる」は正しい。
    実効順が将来変わる / throttle が剥がれる / route が別物になる、のいずれでも false green になる。
- 対応内容: probe を `expectNotThrottled(TestResponse, string)` helper に置き換えた。
  この helper は **`X-RateLimit-Remaining` の存在を先に検査**してから 429 でないことを見る
  （= throttle が実際に走ったうえで通った、を主張する）。
  cross-lane の probe 6 か所すべてを置換。実効順の事実と、順序が変わっても
  false green にならない理由を S8 の注記に明記した。
  helper 自体の有効性は mutation **M9**（ヘッダ検査を外すと緑のままになることの観測）で確認する。

## [Critical] mutation 手順の「期待するテストだけが赤」が成立していない
- 判断: **対応する**
- 根拠: 指摘のとおり。route の指定を 1 か所変えれば複数 gate が反応するのが正常であり、
  「そのテストだけが赤」という書き方は結果の誤読を招く。
- 対応内容: mutation 表を **primary / collateral の 2 列**に分割し、
  M1 / M2 / M3 / M4 / M5 / M8 について collateral を明記した。
  併せて **gate ごとにファイル単位で実行する手順**
  （`composer test -- --filter=InlineThrottleInventoryTest`）を規定し、
  「そのテストだけが赤」とは書かないことを明文化した。
  検証の網羅性を上げるため mutation を 5 件追加（M2''' 減少方向 / M2'''' premise /
  M5' 種別文字列 / M9 false green 検出器の検出器）。

## [Warning] S5 の cap が exact-fit ではなく upper-bound
- 判断: **対応する**
- 根拠: `count > cap` では件数が減ったときに余った枠が残り、
  「余裕を持たせない」という設計意図と矛盾する。既存 `ThrottleCoverageInventoryTest` は
  `<=` だが、本 gate は母集団が 3 件と小さく、**減る方向こそが望ましい変化**なので
  そのたびに宣言値を下げさせる方が正しい。
- 対応内容: 関数名を `inlineThrottleRationaleCapByCase()` →
  `inlineThrottleRationaleExactCountByCase()` に改め、検査を `!==` にした。
  減少方向を検出する mutation **M2'''** を追加。

## [Warning] S6 の typo 検出が不十分（inventory 側の lane しか見ていない）
- 判断: **対応する**
- 根拠: 指摘のとおり。route に `throttle:password-sett` と書かれた場合、
  目録側の lane を見るテストでは「未知の名前」を列挙できず、
  完全一致テストは「route が消えた」としか言わないため原因が分からない。
- 対応内容: 検査の母集団を **全 route の named throttle params** へ広げ、
  `CacheRateLimiter::limiter($params) !== null` を検査するテストに置き換えた
  （本設計のレーンに限らない。未登録 limiter はリクエスト時に
  `MissingRateLimiterException` になるため build 時に落とす）。
  この検査自体が空振りしないよう「named throttle を貼った route が 1 本以上ある」
  （下限 25）も追加した。

## [Warning] S1/S7 の「キー文字列が変わっていない」主張が過大（prefix しか見ていない）
- 判断: **対応する**
- 根拠: `expectedKeyPrefixes` は `{レーン}:{種別}` までしか見ないため、
  suffix（actor id / IP）の作り方が変われば bucket はリセットされるのに検出できない。
  「bucket をリセットしない」と主張するなら full key を固定する必要がある。
- 対応内容: S7 に `rateLimiterActorOrIpFullKeys()` と
  「actor/IP レーンの full key が宣言と完全一致する」テストを追加。
  対象は helper を使う 8 レーン（`passkeys` / `two-factor-secret-read` + 新 6 レーン）で、
  probe の固定値（user id 4242 / IP 203.0.113.7）に対する
  `{lane}:user:4242` / `{lane}:ip:203.0.113.7` を完全一致で照合する。
  S1 のテスト計画とリスク欄の主張も「prefix では不十分」と書き直した。
  検出力は mutation **M5'**（`:user:` → `:actor:`）で確認する。

## [Suggestion] S5 の vendor inline rationale に premise test を足す
- 判断: **対応する**
- 根拠: 分類が「作文」で終わると vendor 更新時の drift に気づけない。
  本リポジトリは `ThrottleExemptionPremiseTest` で同じことを既にやっている。
- 対応内容: `inlineThrottleCasePremises()` と
  「分類 case の適用条件が実効 middleware 列と一致する」テストを追加した。
  - `VendorStatelessIpBucket` → 実効列に `StartSession` が**無い**
  - `VendorMixedUserOrIpBucket` → `StartSession` が**有り** `Authenticate` が**無い**
  これで「passport は stateless」「livewire は mixed かつ唯一」が機械固定される。
  空振り保証の表にも 1 行追加。

## [補足] 閾値は 1 つも変えていない旨の確認
- Codex の確認どおり。6/min・10/min・60/min はすべて移行元の inline 値のまま。
