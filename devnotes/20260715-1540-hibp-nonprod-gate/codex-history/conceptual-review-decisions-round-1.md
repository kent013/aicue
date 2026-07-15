# 対応マトリクス: conceptual-review Round 1

## [Critical] 準本番(staging 等)の扱いが本文と述語実装で不一致 / 未知 env で HIBP が静かに外れる
- 判断: 対応する
- 根拠: 指摘は正当。述語 3 を `App::environment('staging')` 固定にすると、preprod/qa/review 等の別名ミラー環境で HIBP が静かに無効化される。これは「本番同等の検証代表性」を損ない、かつセキュリティの安全側にも反する。
- 対応内容: **allowlist(ON にする env 列挙)から denylist(OFF にする既知の開発/テスト env 列挙)へ反転**する。HIBP は既定 ON(fail-secure)とし、`PWNED_CHECK_DISABLED_ENVIRONMENTS = ['local','testing','bughunt.local']` に属する env と `fake_externals=true` のときだけ OFF。未知 env(staging/preprod/qa/review 等)は既定 ON。これは FakeExternalsServiceProvider が「fake は allowlist で倒す(未知 env では fake しない=安全側)」とするのと対称で、HIBP の安全側は逆に「未知 env では照合する」。本文の「staging 等」の曖昧語を廃し、OFF リストを明示。

## [Warning] 実装可能だが述語 3 が staging 固定で説明文と不一致
- 判断: 対応する(Critical と同一原因)
- 根拠: 同上。
- 対応内容: denylist 反転で解消。有効/無効の env 分類を実装定数と本文で一致させた。

## [Warning] 期待効果の受け入れ基準が弱い(HIBP 呼び出し 0 回 / POST 閾値)
- 判断: 対応する
- 根拠: 効果主張には検証条件の明文化が要る。ただし POST 実時間の閾値測定はユニットテストでは flaky。
- 対応内容: テスト計画に「非本番では rule に uncompromised が付与されない=HIBP HTTP 経路が構造的に発生しない」を成功条件として追加。加えて Feature レベルで `Http::preventStrayRequests()` / `Http::assertNothingSent()` により登録/リセット/変更 POST が外部 HIBP を呼ばないことを固定する項目を追加。

## [Warning] rule() の reflection 検査は Laravel 内部結合が強く脆い
- 判断: 対応する
- 根拠: 妥当。フレームワーク更新で protected プロパティ名が変わると壊れる。
- 対応内容: **主テスト面を public 述語 `shouldCheckPwned()` の振る舞い検査に据える**。`rule()` 側の reflection は「述語 true/false が uncompromised 付与に正しく配線されているか」を確認する補助に留める旨を明記。

## [Warning] env 分類を確定してから着手すべき
- 判断: 対応する
- 根拠: 妥当。
- 対応内容: denylist 定数 `PWNED_CHECK_DISABLED_ENVIRONMENTS` を SSOT として設計で確定。

## [Suggestion] bughunt.local が host 名に見える / APP_ENV と host の分離
- 判断: 対応する(軽微)
- 対応内容: `bughunt.local` は `APP_ENV` の値(host ではない)である旨を注記。

## [Suggestion] 効果は「非本番の開発・検証速度改善」に限定して記述
- 判断: 対応する
- 対応内容: 期待効果の見出しを「非本番限定の速度・決定性改善(本番 UX 不変)」に明確化。

## [Suggestion] production で fake_externals=true 混入でも true を返す順序は fail-secure で良い
- 判断: 反映済み(維持)
- 対応内容: 述語 1(production 先行)を維持。denylist でも production は無条件 true のまま。
