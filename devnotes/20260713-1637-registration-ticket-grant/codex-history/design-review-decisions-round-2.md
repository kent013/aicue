# 対応マトリクス: design-review Round 2

## [Warning] Architecture テストの `DB::selectOne()->indexdef` が PHPStan L10 で ?object プロパティアクセス
- 判断: 対応する
- 根拠: `DB::selectOne()` は `?object` を返し、後置 `@var` はアクセス式を型保証できない。
- 対応内容: `DB::scalar()` で indexdef を直接取得し `Assert::string($definition)` で絞り込む
  （null = index 不在 → fail で存在保証も兼ねる）。`use Webmozart\Assert\Assert;` を追記。

## [Suggestion] 6-3 直接投入行の delta / 期限を config 由来へ
- 判断: 対応する（一貫性向上）
- 対応内容: `delta => config('billing.signup_grant_tickets')`、
  `expires_at => now()->addDays(config()->integer('billing.signup_grant_expiry_days'))` に変更。
