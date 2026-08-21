# 対応マトリクス: design-review Round 2

## [Warning] テスト #6 は実装前に赤にならない (characterization test)
- 判断: 対応する
- 根拠: 現行コードが既に未契約+非管理者→billing-required を返す。#6 は変更前から緑。
- 対応内容: #6 を「characterization / 境界回帰 test (実装前から緑、実装後も緑維持)」と明記。テストファースト節を「赤→緑は #2/#4/continuation の 3 本、#6 は緑維持の記録」に修正。証跡で赤→緑と最初から緑を分けて記録。

## [Warning] screens.md の乖離台帳節が同一 PR 方針と不整合
- 判断: 対応する
- 根拠: fingerprints に screens.md は不在を確認 (grep 0 hit)。
- 対応内容: 乖離台帳節を「fingerprint 対象外 = 乖離台帳登録対象ではない。挙動変更と同一 PR で更新 (app-update-docs 追跡に回さない)」に一本化。conceptual-design.md 側の app-update-docs 記述も同一 PR 方針に合わせて更新。

## [Warning] Inertia assertion のコールバック無型
- 判断: 対応する
- 対応内容: `fn (AssertableInertia $page): AssertableInertia => $page->component('Dashboard')` に型付け (`use Inertia\Testing\AssertableInertia;`)。PHPStan level 10 準拠。

## [Suggestion] continuation の中間ホップ保証
- 判断: 対応する (明瞭化)
- 対応内容: continuation テストを段階確認に (1) verification.verify → onboarding.checkout、(2) onboarding.checkout → dashboard。followingRedirects で最終だけ見ない。
