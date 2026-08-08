# 対応マトリクス: conceptual-review Round 1

Codex 全体判定: **APPROVED** (Round 1)。Critical なし。Warning 4 / Suggestion 4。
APPROVED でも Warning は「対応を検討」の対象なので、以下のとおり捌いて概念設計へ反映した。

## [Warning] 既存の route 保護系テストが差分なしで green であることを完了条件に明記せよ

- 判断: **対応する**
- 根拠: `RecentAuthRouteTest` / `PasskeyRouteProtectionTest` / `TwoFactorStepUpInventoryTest` /
  `ThrottleCoverageInventoryTest` は**非 cached レーン**で走るため、施策 B のあとも
  結果が変わらないことが「付与内容が変わっていない (= 振る舞い不変)」の直接の回帰になる。
  本設計の主張の核心なので完了条件に入れるべき。
- 対応内容: 概念設計「実装方針」に完了条件として明記した (これらのテストは 1 行も変更しない)。

## [Warning] 施策 B は採るべき。`routesAreCached === true` の early return を route 解決より前に置き、テストで固定せよ

- 判断: **対応する** (元設計と同意見。テストの表現を強くする)
- 根拠: docblock 修正だけでは「非 cached で vendor が route 名を変えたとき無音で保護が外れる」
  穴が残る。`RouteThrottleBinder` が既に分離済みの事故クラスであり、家系で作法が割れている
  ことこそが本設計の課題。
- 対応内容: 概念設計の施策 B / D-1 に「early return は route 解決より**前**である」ことと、
  それを `routesAreCached: true` + **存在しない route 名だけを渡す** ケースで固定する
  (= T120 の恒久回帰) と明記した。

## [Warning] `feature` 条件は遅延評価できる形にせよ。PHPStan level 10 なら readonly DTO が安全

- 判断: **一部対応する / 一部反論する**
- 根拠 (遅延評価は対応):
  現行 2 経路は feature 判定を**それぞれ違うタイミング**で行っている —
  `attachThrottleToFortifyRoutes()` は `boot()` 内、`attachMiddlewareToPasskeyRoutes()` は
  `$app->booted()` 内。新 helper が spec を `boot()` で受け取る形にすると、後者の評価が
  **早まる** = 振る舞いを変える改変になる。本設計は「振る舞いを変えない」が原則なので、
  helper の入口を `callable(): array` にして **booted の中で解決**し、現行タイミングを保存する。
- 根拠 (readonly DTO は反論):
  spec の shape は既存の `FortifyServiceProvider::throttledFortifyRoutes()` が
  `array{throttle: string, feature: ...}` の配列で表現しており、ここだけ DTO にすると
  同じ意味のものが 2 表現に割れる (思考原則 4「別物の概念を似ているからで統合しない」の裏返し
  = 同じ概念を理由なく別表現にしない)。PHPStan level 10 は `@phpstan-type` の array shape で
  完全に閉じられるため、新クラスを 1 つ増やす理由がない (禁止事項 6 / 思考原則 2)。
- 対応内容: 概念設計の施策 B を「入口は `callable(): array<string, list<string>>`、
  shape は `@phpstan-type` で固定」に更新。詳細設計で型注釈を確定させる。

## [Warning] `computedMiddleware` の扱いを明文化するか、binder と同等に無効化せよ

- 判断: **対応する** (無効化を入れる側で揃える)
- 根拠: 現行経路は `gatherMiddleware()` を呼ばないため computed memo は温まらず、
  今日時点では無効化しなくても問題ない (vendor 実読で確認済み)。しかし
  `RouteThrottleBinder` がこれを固有責務として持つ理由は
  「**middleware() には載るのに dispatch されない = 無音の無防備**」であり、
  それはまさに本設計が潰そうとしている失敗形である。1 行で将来の無音化を閉じられるなら
  入れる方が家系の作法として一貫する。
- 対応内容: 施策 B に「付与後に `$route->computedMiddleware = null` を置く。
  **現時点では no-op であることを確認済み**である旨をコメントに残す (誇張しない)」を追加。

## [Suggestion] 使命への貢献説明がやや大きめ

- 判断: **一部対応する**
- 根拠: 誇張しない方針 (AGENTS.md の「保証範囲を誇張しない」文化) に合わせ、
  「基盤整備であって直接の機能価値ではない」ことを明示した方が正確。
- 対応内容: 期待効果に「これは基盤整備であり、直接のユーザー価値ではない」旨を追記。

## [Suggestion] デプロイ基盤は記述で十分 / 起動時 cache 鮮度検査は作らない判断でよい

- 判断: **見送る (元設計のまま)**
- 根拠: 元設計の結論と一致。追加変更なし。

## [Suggestion] 3 層 (コード / guide / AGENTS.md) で同じ言葉に揃えるのは効果がある

- 判断: **見送る (元設計のまま)**
- 根拠: 施策 A / C が既にそれを行う設計になっている。
