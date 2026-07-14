# 詳細設計レビュー Round 3（Round 2 指摘への対応）

Round 2 の Warning / Suggestion に対応しました。**全体判定の再評価**をお願いします。

## [Warning] Architecture テストの `DB::selectOne()->indexdef` が PHPStan L10 で ?object プロパティアクセス
→ **対応**。`DB::scalar()` で indexdef を直接取得し `Assert::string($definition)` で絞り込む形へ変更
（null = index 不在 → fail で存在保証も兼ねる）。`use Webmozart\Assert\Assert;` を追記。更新後:

```php
test('ticket_ledger_entries は 1 組織 1 signup grant を部分 UNIQUE index で強制する', function (): void {
    $definition = DB::scalar(
        "SELECT indexdef FROM pg_indexes
         WHERE tablename = 'ticket_ledger_entries'
           AND indexname = 'ticket_ledger_entries_signup_grant_unique'",
    );
    Assert::string($definition);

    expect($definition)
        ->toContain('UNIQUE')
        ->toContain('ticket_ledger_entries')
        ->toContain('organization_id')
        ->toContain('WHERE')
        ->toContain('signup_grant');
});
```

## [Suggestion] 6-3 直接投入行の delta / 期限を config 由来へ
→ **対応**。`delta => config('billing.signup_grant_tickets')`、
`expires_at => now()->addDays(config()->integer('billing.signup_grant_expiry_days'))` に変更。

---

以上で Round 2 の残指摘に対応済みです。他に懸念がなければ **APPROVED** をお願いします。
