<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use Webmozart\Assert\Assert;

/*
 * 「1 組織 1 signup grant」の DB 不変条件 (課金冪等性 §7 の一部)。
 *
 * ticket_ledger_entries の部分 UNIQUE index が、organization_id ごとに
 * idempotency_key LIKE 'signup_grant:%' 行を高々 1 行に強制することを検証する。
 * この index が旧キー (signup_grant:{subId}) と新 org スコープキー (signup_grant:org:{id}) の
 * 双方をカバーし、ローリングデプロイ中の別キー同時 insert でも二重付与を DB レベルで防ぐ。
 *
 * テストは pgsql driver 前提 (テスト DB は pgsql)。pgsql は LIKE を ~~ 演算子・リテラルを
 * 'signup_grant:%'::text として indexdef に描画するため、完全一致文字列ではなく
 * UNIQUE / organization_id / signup_grant (部分文字列) の含有で検証する (Codex Round 4 caveat)。
 */

test('ticket_ledger_entries は 1 組織 1 signup grant を部分 UNIQUE index で強制する', function (): void {
    $definition = DB::scalar(
        "SELECT indexdef FROM pg_indexes
         WHERE tablename = 'ticket_ledger_entries'
           AND indexname = 'ticket_ledger_entries_signup_grant_unique'",
    );
    Assert::string($definition); // index 不在なら null → fail (存在保証も兼ねる)

    expect($definition)
        ->toContain('UNIQUE')                 // 一意制約であること
        ->toContain('ticket_ledger_entries')  // 対象テーブル
        ->toContain('organization_id')        // 対象列
        ->toContain('WHERE')                  // 部分 index (述語) であること
        ->toContain('signup_grant');          // 述語がキー prefix を参照 (LIKE は ~~ に正規化され得る)
});
