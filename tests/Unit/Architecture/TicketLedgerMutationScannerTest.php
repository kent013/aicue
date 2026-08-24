<?php

declare(strict_types=1);

use Tests\Support\Architecture\TicketLedgerMutationScanner;

/*
 * 走査器 {@see TicketLedgerMutationScanner} の自己検査 (負例と正例の両方向)。
 *
 * AGENTS.md 「走査器・gate を新設・変更するときに同じ PR で揃える 4 点」の (1) と (2)。
 * gate 側 (`tests/Architecture/TicketLedgerMutationSiteGateTest.php`) は「実コードが目録と
 * 一致するか」を見る。ここは**検出器そのものが正しく数えるか**を見る。
 */

/**
 * 合成入力をトークン化する短縮形。
 *
 * @return list<array{id: int|null, text: string, line: int}>
 */
function tlmTokens(string $source): array
{
    return TicketLedgerMutationScanner::tokenize($source, 'scanner-self-test');
}

test('表名リテラルは完全一致だけを数える (接頭辞・接尾辞つきは数えない)', function (): void {
    $source = <<<'PHP'
        <?php
        final class R {
            public function f(): void {
                DB::table('ticket_ledger_entries')->get();
                DB::table('ticket_ledger_entries_backup')->get();
                DB::table('archive_ticket_ledger_entries')->get();
            }
        }
        PHP;

    expect(TicketLedgerMutationScanner::tableLiteralCount(tlmTokens($source)))->toBe(1);
});

test('表名リテラルはコメント・docblock の中では数えない', function (): void {
    $source = <<<'PHP'
        <?php
        /** 台帳の表名は ticket_ledger_entries である。 */
        final class R {
            // 'ticket_ledger_entries' をここで消してはならない
            public function f(): void {}
        }
        PHP;

    expect(TicketLedgerMutationScanner::tableLiteralCount(tlmTokens($source)))->toBe(0);
});

test('変更語彙は接頭辞つき・打ち消しつき・接尾辞つきの 3 形を数えない ((e) の負例)', function (): void {
    $source = <<<'PHP'
        <?php
        final class R {
            public function f($q): void {
                $q->presave();
                $q->unsave();
                $q->saveAll();
                $q->save();
            }
        }
        PHP;

    expect(TicketLedgerMutationScanner::verbCount(tlmTokens($source), ['save']))->toBe(1);
});

test('変更語彙はメソッド定義 (function delete()) を数えない', function (): void {
    $source = <<<'PHP'
        <?php
        final class R {
            public function delete(): void {}
            public function f($q): void { $q->delete(); }
        }
        PHP;

    expect(TicketLedgerMutationScanner::verbCount(tlmTokens($source), ['delete']))->toBe(1);
});

test('変更語彙はコメント・文字列の中では数えない', function (): void {
    $source = <<<'PHP'
        <?php
        final class R {
            public function f(): void {
                // $q->delete(); は書いてはならない
                $sql = 'delete(';
            }
        }
        PHP;

    expect(TicketLedgerMutationScanner::verbCount(tlmTokens($source), ['delete']))->toBe(0);
});

test('モデル参照は別名つき import を完全修飾名まで解決して拾う', function (): void {
    $source = <<<'PHP'
        <?php
        namespace App\Foo;
        use App\Models\Billing\TicketLedgerEntry as Ledger;
        final class R { public function f(): void { Ledger::query()->get(); } }
        PHP;

    expect(TicketLedgerMutationScanner::ledgerModelReference('app/Foo/R.php', $source, tlmTokens($source)))
        ->toBe(['fqcn' => true, 'shortName' => false]);
});

test('モデル参照は同名の別クラスを FQCN 一致とは区別する (短名側だけが立つ)', function (): void {
    // ★AGENTS.md 共通規約 (a) は「完全修飾名で突き合わせる」である。短名一致は
    //   **拾いすぎ側 (fail-closed) の補助**であって FQCN の解決結果ではないので、
    //   同じ bool へ潰さず別々に返す (利用側は和で判定し、失敗メッセージで区別できる)。
    $source = <<<'PHP'
        <?php
        namespace App\Foo;
        use Other\TicketLedgerEntry;
        final class R { public function f(): void { TicketLedgerEntry::query()->get(); } }
        PHP;

    expect(TicketLedgerMutationScanner::ledgerModelReference('app/Foo/R.php', $source, tlmTokens($source)))
        ->toBe(['fqcn' => false, 'shortName' => true]);
});

test('型宣言の位置の短名も短名側で拾う (走査器が emit しない位置を埋める)', function (): void {
    $source = <<<'PHP'
        <?php
        namespace App\Foo;
        use App\Models\Billing\TicketLedgerEntry;
        final class R { public function f(TicketLedgerEntry $e): void {} }
        PHP;

    $result = TicketLedgerMutationScanner::ledgerModelReference('app/Foo/R.php', $source, tlmTokens($source));
    expect($result['shortName'])->toBeTrue();
});

test('モデル参照を持たないファイルはどちらも false になる (負のコントロール)', function (): void {
    $source = <<<'PHP'
        <?php
        namespace App\Foo;
        final class R { public function f($q): void { $q->save(); } }
        PHP;

    expect(TicketLedgerMutationScanner::ledgerModelReference('app/Foo/R.php', $source, tlmTokens($source)))
        ->toBe(['fqcn' => false, 'shortName' => false]);
});

test('TLM-5: DB::transaction の第 1 引数が closure でない形は違反として返る', function (): void {
    $source = <<<'PHP'
        <?php
        namespace App\Foo;
        use Illuminate\Support\Facades\DB;
        final class S {
            private function carryForwardOrganization($o): int {
                return DB::transaction($this->callback($o));
            }
        }
        PHP;

    $violations = TicketLedgerMutationScanner::lockOrderViolations(
        tlmTokens($source),
        'app/Foo/S.php',
        $source,
        'carryForwardOrganization',
        'appendCarryForward',
        ['save', 'delete'],
        ['delete'],
    );

    expect($violations)->not->toBe([]);
});

test('トークン化できない入力は無言で空にせず例外になる ((b) fail-closed)', function (): void {
    TicketLedgerMutationScanner::tokenize('<?php final class { ', 'scanner-self-test');
})->throws(RuntimeException::class);

test('メソッド本体の範囲は入れ子の波括弧・文字列補間で崩れない', function (): void {
    $source = <<<'PHP'
        <?php
        final class R {
            public function target(int $n): string {
                if ($n > 0) { $label = "値は {$n} です"; } else { $label = '負'; }
                foreach ([1, 2] as $i) { $label .= "{$i}"; }
                return $label;
            }
            public function after($q): void { $q->delete(); }
        }
        PHP;

    $tokens = tlmTokens($source);
    $range = TicketLedgerMutationScanner::methodBodyRange($tokens, 'target');
    expect($range)->not->toBeNull();

    // `after()` の delete( は target() の本体の**外**にある
    $deletes = TicketLedgerMutationScanner::verbPositions($tokens, ['delete']);
    expect($deletes)->toHaveCount(1);
    expect($range[0] < $deletes[0] && $deletes[0] < $range[1])->toBeFalse();
});

test('存在しないメソッドの本体範囲は null になる (呼び出し側が失敗させる材料)', function (): void {
    $source = '<?php final class R { public function f(): void {} }';

    expect(TicketLedgerMutationScanner::methodBodyRange(tlmTokens($source), 'missing'))->toBeNull();
});

test('論理削除 scope は受理する 2 形だけを解決済みとし、それ以外は未解決として返す', function (): void {
    $accepted = <<<'PHP'
        <?php
        namespace App\Foo;
        use App\Models\Organization;
        final class R {
            public function a(): void { Organization::withTrashed()->get(); }
            public function b(): void { Organization::query()->withTrashed()->get(); }
        }
        PHP;

    $result = TicketLedgerMutationScanner::trashedScopes('app/Foo/R.php', $accepted, tlmTokens($accepted));
    expect($result['count'])->toBe(2);
    expect($result['unresolved'])->toBe([]);

    $rejected = <<<'PHP'
        <?php
        namespace App\Foo;
        use App\Models\Organization;
        final class R {
            public function a($query): void { $query->withTrashed()->get(); }
            public function b(): void { Organization::query()->where('id', 1)->withTrashed()->get(); }
            public function c(): void { \App\Models\User::onlyTrashed()->get(); }
        }
        PHP;

    $result = TicketLedgerMutationScanner::trashedScopes('app/Foo/R.php', $rejected, tlmTokens($rejected));
    expect($result['count'])->toBe(3);
    expect($result['unresolved'])->toHaveCount(3);
});

test('論理削除 scope は完全修飾で書かれた組織モデルも受理する', function (): void {
    $source = <<<'PHP'
        <?php
        namespace App\Foo;
        final class R { public function a(): void { \App\Models\Organization::withTrashed()->get(); } }
        PHP;

    $result = TicketLedgerMutationScanner::trashedScopes('app/Foo/R.php', $source, tlmTokens($source));
    expect($result['count'])->toBe(1);
    expect($result['unresolved'])->toBe([]);
});

test('TLM-5: static で始まるが closure でない第 1 引数は違反として返る', function (): void {
    $source = <<<'PHP'
        <?php
        namespace App\Foo;
        use Illuminate\Support\Facades\DB;
        final class S {
            private function carryForwardOrganization($o): int {
                return DB::transaction(static::$callback, 3);
            }
        }
        PHP;

    $violations = TicketLedgerMutationScanner::lockOrderViolations(
        tlmTokens($source),
        'app/Foo/S.php',
        $source,
        'carryForwardOrganization',
        'appendCarryForward',
        ['save', 'delete'],
        ['delete'],
    );

    expect($violations)->not->toBe([]);
});

test('TLM-5: static closure は第 1 引数として受理する (誤検出しない)', function (): void {
    $source = <<<'PHP'
        <?php
        namespace App\Foo;
        use App\Models\Organization;
        use Illuminate\Support\Facades\DB;
        final class S {
            private function carryForwardOrganization($o): int {
                return DB::transaction(static fn (): int => $this->run($o));
            }
        }
        PHP;

    $violations = TicketLedgerMutationScanner::lockOrderViolations(
        tlmTokens($source),
        'app/Foo/S.php',
        $source,
        'carryForwardOrganization',
        'appendCarryForward',
        ['save', 'delete'],
        ['delete'],
    );

    // closure としては受理されるが、中身が空なので別の条件 (空振り検出) で落ちる。
    // ここで固定したいのは「第 1 引数が closure ではない」という違反が**出ない**ことである。
    expect($violations)->not->toContain('DB::transaction( の第 1 引数が closure ではない (carryForwardOrganization())');
});
