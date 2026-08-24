<?php

declare(strict_types=1);

use Tests\Support\Architecture\TicketLedgerMutationInventory;
use Tests\Support\Architecture\TicketLedgerMutationScanner;
use Tests\Support\TrackedPhpSourceFiles;

/*
 * Architecture invariant: **追記専用チケット台帳 (`ticket_ledger_entries`) を変更する場所は
 * deny-by-default の目録制** (家系の正典 v1)。
 *
 * ★なぜ要るか:
 *   台帳モデルは `updating` / `deleting` を Eloquent イベントで例外化しているが、
 *   **Eloquent の一括削除 (`Builder::delete()` / Query Builder) はモデルイベントを発火しない**。
 *   つまり append-only は**コード上の規律**であって、静的な検査が無いと
 *   「**行の物理削除・残高スナップショットへの置換**を書いてよいのは畳み込み 1 ファイルだけ」は
 *   担保されない (台帳への変更そのものは `TicketLedgerService` の追記と限定 backfill も持つ)。
 *   目録は「変更しうる場所を宣言なしに増やせない」ための摩擦である。
 *
 * ★**既存 gate と同名のグローバル定数・関数を 1 つも宣言しない**。既存の
 *   `TicketLedgerReaderInventoryTest` がグローバル定数 `TICKET_LEDGER_TABLE` /
 *   `TICKET_LEDGER_MODEL_IDENTIFIER` とグローバル関数 `ticketLedgerScanFiles()` を宣言しており、
 *   Pest は同一プロセスでテストファイルを読み込むので同名を宣言すると
 *   `Cannot redeclare` で Architecture レーン全体が落ちる。本ファイルは
 *   **グローバル定数を 1 つも宣言せず**、目録と走査ロジックは
 *   `Tests\Support\Architecture\` のクラスに置く。ファイルスコープの helper は
 *   `ticketLedgerMutationScan` / `ticketLedgerMutationExpected` /
 *   `ticketLedgerMutationIsAmbiguous` / `ticketLedgerCarryForwardSource` /
 *   `ticketLedgerLockOrderViolations` の 5 つだけで、いずれも既存のどの gate とも綴りが違う。
 *
 * ★この gate が保証するもの:
 *   - TLM-1: 表名リテラルの出現ファイルと件数が目録と**完全一致**
 *   - TLM-2: モデル参照 + 変更語彙の同居ファイルと件数が目録と**完全一致**
 *   - TLM-3: **TLM-2 の候補ファイル (モデル参照 or 表名リテラルを持つファイル) のうち**
 *     削除語彙を持つのは畳み込みサービス 1 ファイルだけ
 *     (`app/` 全体の `delete(` を対象にするのではない)
 *   - TLM-4: `withTrashed(` / `onlyTrashed(` の出現ファイルと件数が目録と完全一致。かつ
 *     **すべての出現が受理する 2 形のいずれか**で受け手が `App\Models\Organization` に解決される
 *     (それ以外は**未解決として失敗**する = fail-closed)
 *   - TLM-2b: 変更語彙を持つファイルのモデル参照が**短名一致だけで当たっている**
 *     (完全修飾名まで解決できない) 状態を**曖昧として失敗させる**。
 *     登録済みファイルの本物の参照を同名の別クラスへ差し替える書き換えを止める
 *   - TLM-5: 畳み込みの**変更操作がすべて同一の `DB::transaction(` の引数範囲の内側にあり、
 *     ロック語彙がその中の最初の変更操作より前にある** (5 条。負例 9 変異で裏取り)。
 *     **見るのはトークン順の構造だけ**である — 引数範囲は closure 本体そのものではなく
 *     `transaction(` の**引数全体**であり、`lockForUpdate(` の受け手が組織モデルか、
 *     `delete(` の対象が台帳かは**見ない** (限界の正本は走査器の docblock 5b)
 *   - TLM-6: 目録が陳腐化していない (対象ファイルが実在 / 理由が 30 文字以上)
 *   - TLM-7: 空振り検知 (走査ファイル数 / 検出の非空 / 目録の非空)
 *
 * ★この gate が保証しないもの (誇張しない): 正本は
 *   {@see TicketLedgerMutationScanner} の docblock である (本ファイルに写さない)。
 *   要点だけ言えば、**変更経路の全数性は主張しない** —
 *   呼び出し側と共通処理側で語彙が分かれる形は検出できないため、
 *   「append-only の例外は畳み込み 1 ファイルだけ」は**人間向けのドメイン規約**
 *   (AGENTS.md ドメイン固有規約 22) として置き、gate がそれを証明するとは書かない。
 */

/**
 * `app/` 配下の走査結果。
 *
 * @return array<string, array{
 *     tableLiterals: int,
 *     model: bool,
 *     modelFqcn: bool,
 *     mutations: int,
 *     deletes: int,
 *     trashed: int,
 *     trashedUnresolved: list<string>,
 * }>
 */
function ticketLedgerMutationScan(): array
{
    /** @var array<string, array{tableLiterals: int, model: bool, modelFqcn: bool, mutations: int, deletes: int, trashed: int, trashedUnresolved: list<string>}>|null $cache */
    static $cache = null;
    if ($cache !== null) {
        return $cache;
    }

    $scanned = [];
    foreach (TrackedPhpSourceFiles::all(base_path()) as $file) {
        if (! str_starts_with($file['relative'], 'app/')) {
            continue;
        }
        $source = file_get_contents($file['absolute']);
        if ($source === false) {
            throw new RuntimeException('走査対象を読めない: '.$file['relative']);
        }
        $tokens = TicketLedgerMutationScanner::tokenize($source, $file['relative']);
        $trashed = TicketLedgerMutationScanner::trashedScopes($file['relative'], $source, $tokens);
        $model = TicketLedgerMutationScanner::ledgerModelReference($file['relative'], $source, $tokens);

        $scanned[$file['relative']] = [
            'tableLiterals' => TicketLedgerMutationScanner::tableLiteralCount($tokens),
            // 和で拾いすぎ側 (fail-closed) へ倒すが、**どちらで当たったか**は残す
            // (短名だけで当たったファイルを「台帳モデルを参照している」と断定しないため)
            'model' => $model['fqcn'] || $model['shortName'],
            'modelFqcn' => $model['fqcn'],
            'mutations' => TicketLedgerMutationScanner::verbCount(
                $tokens,
                TicketLedgerMutationInventory::MUTATION_VERBS,
            ),
            'deletes' => TicketLedgerMutationScanner::verbCount(
                $tokens,
                TicketLedgerMutationInventory::DELETE_VERBS,
            ),
            'trashed' => $trashed['count'],
            'trashedUnresolved' => $trashed['unresolved'],
        ];
    }

    $cache = $scanned;

    return $cache;
}

/**
 * 目録の {path: {count, reason}} を {path: count} へ落とす。
 *
 * @param  array<string, array{count: int, reason: string}>  $sites
 * @return array<string, int>
 */
function ticketLedgerMutationExpected(array $sites): array
{
    $expected = [];
    foreach ($sites as $path => $entry) {
        $expected[$path] = $entry['count'];
    }
    ksort($expected);

    return $expected;
}

/** 畳み込みサービスのソース (TLM-5 と正例で使う)。 */
function ticketLedgerCarryForwardSource(): string
{
    $source = file_get_contents(base_path(TicketLedgerMutationInventory::CARRY_FORWARD_FILE));
    expect($source)->toBeString();

    return (string) $source;
}

/**
 * 合成入力に対して TLM-5 の 5 条を判定する (負例・正例で共有)。
 *
 * @return list<string>
 */
function ticketLedgerLockOrderViolations(string $source): array
{
    return TicketLedgerMutationScanner::lockOrderViolations(
        TicketLedgerMutationScanner::tokenize($source, 'lock-order-fixture'),
        'fixture.php',
        $source,
        TicketLedgerMutationInventory::LOCK_ORDER_METHOD,
        TicketLedgerMutationInventory::APPEND_CALL,
        TicketLedgerMutationInventory::MUTATION_VERBS,
        TicketLedgerMutationInventory::DELETE_VERBS,
    );
}

/**
 * TLM-2b の判定 (純関数)。**短名一致だけで当たったモデル参照 + 変更語彙**を曖昧として落とす。
 *
 * 実コードの母集団は現在たまたま空なので、この分岐は**合成入力の負例で裏取りする**
 * (AGENTS.md 共通規約 (c)。母集団が空のまま判定を反転しても緑になる形を作らない)。
 */
function ticketLedgerMutationIsAmbiguous(bool $model, bool $modelFqcn, int $mutations): bool
{
    if ($mutations === 0 || ! $model) {
        return false;
    }

    return ! $modelFqcn;
}

test('TLM-1: 表名リテラルの出現ファイルと件数が目録と完全一致する', function (): void {
    $detected = [];
    foreach (ticketLedgerMutationScan() as $path => $result) {
        if ($result['tableLiterals'] > 0) {
            $detected[$path] = $result['tableLiterals'];
        }
    }
    ksort($detected);

    expect($detected)->toBe(
        ticketLedgerMutationExpected(TicketLedgerMutationInventory::tableLiteralSites()),
        '台帳の表名リテラルを持つファイル / 件数が目録と食い違います。'
        .'Tests\Support\Architecture\TicketLedgerMutationInventory::tableLiteralSites() を'
        .'理由付きで更新してください (件数は完全一致)。',
    );
});

test('TLM-2: モデル参照と変更語彙を同居させるファイルと件数が目録と完全一致する', function (): void {
    $detected = [];
    foreach (ticketLedgerMutationScan() as $path => $result) {
        if ($result['model'] && $result['mutations'] > 0) {
            $detected[$path] = $result['mutations'];
        }
    }
    ksort($detected);

    expect($detected)->toBe(
        ticketLedgerMutationExpected(TicketLedgerMutationInventory::mutationSites()),
        '台帳を変更しうる場所 (モデル参照 + 変更語彙) が目録と食い違います。'
        .'Tests\Support\Architecture\TicketLedgerMutationInventory::mutationSites() を'
        .'理由付きで更新してください (件数は完全一致 = 既存ファイルに 2 本目の変更経路を足しても赤になる)。',
    );
});

test('TLM-2b: 変更語彙を持つファイルのモデル参照が短名一致だけで当たっていない', function (): void {
    // ★短名一致は**拾いすぎ側 (fail-closed) の補助**であって完全修飾名の解決ではない。
    //   登録済みファイルの本物の参照を同名の別クラスへ差し替えても変更語彙数が同じなら
    //   TLM-2 の exact-fit を通ってしまうので、**曖昧な参照そのもの**をここで落とす。
    $ambiguous = [];
    foreach (ticketLedgerMutationScan() as $path => $result) {
        if (ticketLedgerMutationIsAmbiguous($result['model'], $result['modelFqcn'], $result['mutations'])) {
            $ambiguous[] = $path;
        }
    }

    expect($ambiguous)->toBe([],
        '台帳モデルの参照が短名一致だけで当たっているファイルに変更語彙があります。'
        .'完全修飾名まで解決できる形 (import した台帳モデルの参照) へ直すか、'
        .'そのファイルが台帳を変更しないようにしてください。'
        .PHP_EOL.implode(PHP_EOL, $ambiguous));
});

test('TLM-2b (負例と正例): 曖昧判定が両方向で正しい', function (bool $model, bool $fqcn, int $mutations, bool $expected): void {
    expect(ticketLedgerMutationIsAmbiguous($model, $fqcn, $mutations))->toBe($expected);
})->with([
    // 短名だけで当たった参照 + 変更語彙 => 曖昧 (落とす)
    '短名のみ + 変更語彙あり' => [true, false, 1, true],
    // 完全修飾名まで解決できていれば適合
    '短名 + FQCN 解決あり + 変更語彙あり' => [true, true, 1, false],
    // 変更語彙が無ければ対象外 (読むだけのファイルを巻き込まない)
    '短名のみ + 変更語彙なし' => [true, false, 0, false],
    // モデル参照が無ければ対象外
    '参照なし + 変更語彙あり' => [false, false, 3, false],
]);

test('TLM-3: 削除語彙を持ってよいのは畳み込みサービス 1 ファイルだけである', function (): void {
    $detected = [];
    foreach (ticketLedgerMutationScan() as $path => $result) {
        // 候補は「モデル参照 or 表名リテラル」を持つファイルに限る
        // (app/ 全体の delete( を対象にすると台帳と無関係な hit で信号が死ぬ)
        if (! $result['model'] && $result['tableLiterals'] === 0) {
            continue;
        }
        if ($result['deletes'] > 0) {
            $detected[$path] = $result['deletes'];
        }
    }
    ksort($detected);

    expect($detected)->toBe(
        ticketLedgerMutationExpected(TicketLedgerMutationInventory::deleteSites()),
        '台帳を参照するファイルに削除語彙が増えました。append-only の例外は'
        .'畳み込みサービス 1 ファイルだけです。',
    );
});

test('TLM-4: 論理削除 scope の出現が目録と完全一致し、受理する 2 形に解決できる', function (): void {
    $detected = [];
    $unresolved = [];
    foreach (ticketLedgerMutationScan() as $path => $result) {
        if ($result['trashed'] > 0) {
            $detected[$path] = $result['trashed'];
        }
        foreach ($result['trashedUnresolved'] as $entry) {
            $unresolved[] = $entry;
        }
    }
    ksort($detected);

    expect($detected)->toBe(
        ticketLedgerMutationExpected(TicketLedgerMutationInventory::trashedScopeSites()),
        'withTrashed( / onlyTrashed( の出現が目録と食い違います。テナント境界を迂回する'
        .'一般的な主キー取得への転用を防ぐため、件数まで申告してください。',
    );

    expect($unresolved)->toBe([],
        '受理する 2 形 (Organization::withTrashed() / Organization::query()->withTrashed()) '
        .'以外の書き方が現れました。同じファイルに Organization::query() が在ることを根拠に'
        .'認定する形は fail-open なので受理集合を広げず、実装側を直してください。'
        .PHP_EOL.implode(PHP_EOL, $unresolved));
});

test('TLM-5 (正例): 畳み込みは変更操作をすべて DB::transaction( の引数範囲の内側に置きロックを先頭に取る', function (): void {
    $violations = TicketLedgerMutationScanner::lockOrderViolations(
        TicketLedgerMutationScanner::tokenize(
            ticketLedgerCarryForwardSource(),
            TicketLedgerMutationInventory::CARRY_FORWARD_FILE,
        ),
        TicketLedgerMutationInventory::CARRY_FORWARD_FILE,
        ticketLedgerCarryForwardSource(),
        TicketLedgerMutationInventory::LOCK_ORDER_METHOD,
        TicketLedgerMutationInventory::APPEND_CALL,
        TicketLedgerMutationInventory::MUTATION_VERBS,
        TicketLedgerMutationInventory::DELETE_VERBS,
    );

    expect($violations)->toBe([], implode(PHP_EOL, $violations));
});

test('TLM-5 (負例): 9 変異がすべて赤になる', function (string $label, string $source): void {
    expect(ticketLedgerLockOrderViolations($source))
        ->not->toBe([], "変異「{$label}」を検出できていません (検出力が無い)");
})->with([
    // 1. ロックがトランザクションの外
    ['ロックがトランザクションの外', <<<'PHP'
        <?php
        final class S {
            private function carryForwardOrganization($o): int {
                Organization::withTrashed()->whereKey(1)->lockForUpdate()->firstOrFail();
                return DB::transaction(function () use ($o): int {
                    $n = $this->expiredScope($o)->delete();
                    $n += $this->groupScope($o)->delete();
                    $this->appendCarryForward($o);
                    return $n;
                });
            }
        }
        PHP],
    // 2. ロックが削除の後ろ
    ['ロックが削除の後ろ', <<<'PHP'
        <?php
        final class S {
            private function carryForwardOrganization($o): int {
                return DB::transaction(function () use ($o): int {
                    $n = $this->expiredScope($o)->delete();
                    Organization::withTrashed()->whereKey(1)->lockForUpdate()->firstOrFail();
                    $n += $this->groupScope($o)->delete();
                    $this->appendCarryForward($o);
                    return $n;
                });
            }
        }
        PHP],
    // 3. ロック語彙が別メソッドにだけある
    ['ロックが別メソッドにだけある', <<<'PHP'
        <?php
        final class S {
            private function lockRow($o): void {
                Organization::withTrashed()->whereKey(1)->lockForUpdate()->firstOrFail();
            }
            private function carryForwardOrganization($o): int {
                return DB::transaction(function () use ($o): int {
                    $this->lockRow($o);
                    $n = $this->expiredScope($o)->delete();
                    $n += $this->groupScope($o)->delete();
                    $this->appendCarryForward($o);
                    return $n;
                });
            }
        }
        PHP],
    // 4. DB::transaction ごと別メソッドへ逃がす
    ['トランザクションごと別メソッドへ逃がす', <<<'PHP'
        <?php
        final class S {
            private function carryForwardOrganization($o): int {
                return $this->run($o);
            }
            private function run($o): int {
                return DB::transaction(function () use ($o): int {
                    Organization::withTrashed()->whereKey(1)->lockForUpdate()->firstOrFail();
                    $n = $this->expiredScope($o)->delete();
                    $n += $this->groupScope($o)->delete();
                    $this->appendCarryForward($o);
                    return $n;
                });
            }
        }
        PHP],
    // 5. 受け手が DB ファサードでない transaction( は数えない
    ['受け手が DB ファサードでない', <<<'PHP'
        <?php
        final class S {
            private function carryForwardOrganization($o): int {
                return Connection::transaction(function () use ($o): int {
                    Organization::withTrashed()->whereKey(1)->lockForUpdate()->firstOrFail();
                    $n = $this->expiredScope($o)->delete();
                    $n += $this->groupScope($o)->delete();
                    $this->appendCarryForward($o);
                    return $n;
                });
            }
        }
        PHP],
    // 6. コメント・文字列中の削除語彙は数えない (= 空振り検出が発火する)
    ['削除語彙がコメント・文字列だけ', <<<'PHP'
        <?php
        final class S {
            private function carryForwardOrganization($o): int {
                return DB::transaction(function () use ($o): int {
                    Organization::withTrashed()->whereKey(1)->lockForUpdate()->firstOrFail();
                    // $this->expiredScope($o)->delete(); は消した
                    $sql = 'delete(';
                    $this->appendCarryForward($o);
                    return 0;
                });
            }
        }
        PHP],
    // 7c. transaction の第 1 引数が `static` で始まるが closure ではない
    ['transaction の第 1 引数が static だけ', <<<'PHP'
        <?php
        final class S {
            private function carryForwardOrganization($o): int {
                return DB::transaction(static::$callback, 3);
            }
        }
        PHP],
    // 7b. transaction の第 1 引数が closure でない (引数範囲を closure と同一視できない)
    ['transaction の第 1 引数が closure でない', <<<'PHP'
        <?php
        final class S {
            private function carryForwardOrganization($o): int {
                return DB::transaction($this->callback($o));
            }
        }
        PHP],
    // 7. 追記の呼び出しだけを closure の外へ移す
    ['追記だけ closure の外', <<<'PHP'
        <?php
        final class S {
            private function carryForwardOrganization($o): int {
                $n = DB::transaction(function () use ($o): int {
                    Organization::withTrashed()->whereKey(1)->lockForUpdate()->firstOrFail();
                    $x = $this->expiredScope($o)->delete();
                    $x += $this->groupScope($o)->delete();
                    return $x;
                });
                $this->appendCarryForward($o);
                return $n;
            }
        }
        PHP],
]);

test('TLM-5 (正例の合成入力): 規定どおりの形は誤検出しない', function (): void {
    $source = <<<'PHP'
        <?php
        namespace App\Services\Billing\Retention;
        use App\Models\Organization;
        use Illuminate\Support\Facades\DB;
        final class S {
            private function carryForwardOrganization($o): int {
                return DB::transaction(function () use ($o): int {
                    Organization::withTrashed()->whereKey(1)->lockForUpdate()->firstOrFail();
                    $n = $this->expiredScope($o)->delete();
                    $n += $this->groupScope($o)->delete();
                    $this->appendCarryForward($o);
                    return $n;
                });
            }
            private function appendCarryForward($o): void {}
        }
        PHP;

    expect(ticketLedgerLockOrderViolations($source))->toBe([]);
});

test('TLM-5 (正例): static closure も第 1 引数として受理する', function (): void {
    $source = <<<'PHP'
        <?php
        namespace App\Services\Billing\Retention;
        use App\Models\Organization;
        use Illuminate\Support\Facades\DB;
        final class S {
            private function carryForwardOrganization($o): int {
                return DB::transaction(static function () use ($o): int {
                    Organization::withTrashed()->whereKey(1)->lockForUpdate()->firstOrFail();
                    $n = $this->expiredScope($o)->delete();
                    $n += $this->groupScope($o)->delete();
                    $this->appendCarryForward($o);
                    return $n;
                });
            }
            private function appendCarryForward($o): void {}
        }
        PHP;

    expect(ticketLedgerLockOrderViolations($source))->toBe([]);
});

test('TLM-6: 目録が陳腐化していない (対象ファイルが実在し理由が 30 文字以上)', function (): void {
    $violations = [];
    $inventories = [
        'tableLiteralSites' => TicketLedgerMutationInventory::tableLiteralSites(),
        'mutationSites' => TicketLedgerMutationInventory::mutationSites(),
        'deleteSites' => TicketLedgerMutationInventory::deleteSites(),
        'trashedScopeSites' => TicketLedgerMutationInventory::trashedScopeSites(),
    ];

    foreach ($inventories as $name => $sites) {
        foreach ($sites as $path => $entry) {
            if (! is_file(base_path($path))) {
                $violations[] = "{$name}: 実在しないファイルが登録されている ({$path})";
            }
            if (mb_strlen($entry['reason']) < 30) {
                $violations[] = "{$name}: 理由が 30 文字未満である ({$path})";
            }
            if ($entry['count'] < 1) {
                $violations[] = "{$name}: 件数が 1 未満である ({$path})";
            }
        }
    }

    expect($violations)->toBe([], implode(PHP_EOL, $violations));
});

test('TLM-7: 空振り検知 (走査ファイル数 / 検出 / 目録が非空である)', function (): void {
    $scanned = ticketLedgerMutationScan();
    expect(count($scanned))->toBeGreaterThan(TicketLedgerMutationInventory::SCAN_FLOOR);

    // 走査根が生きている (母集団に代表パスが居る)
    expect($scanned)->toHaveKey(TicketLedgerMutationInventory::CARRY_FORWARD_FILE);
    expect($scanned)->toHaveKey(TicketLedgerMutationInventory::LEDGER_SERVICE_FILE);

    // 検出そのものが非空である (抽出条件の綴り間違いで全部 0 になっていない)
    $withTable = array_filter($scanned, static fn (array $r): bool => $r['tableLiterals'] > 0);
    $withModel = array_filter($scanned, static fn (array $r): bool => $r['model']);
    $withModelFqcn = array_filter($scanned, static fn (array $r): bool => $r['modelFqcn']);
    $withMutation = array_filter($scanned, static fn (array $r): bool => $r['mutations'] > 0);
    $withTrashed = array_filter($scanned, static fn (array $r): bool => $r['trashed'] > 0);
    expect($withTable)->not->toBeEmpty();
    expect($withModel)->not->toBeEmpty();
    // 完全修飾名まで解決できた参照が 0 件なら、名前解決そのものが壊れている
    expect($withModelFqcn)->not->toBeEmpty();
    expect($withMutation)->not->toBeEmpty();
    expect($withTrashed)->not->toBeEmpty();

    // 目録が非空である
    expect(TicketLedgerMutationInventory::tableLiteralSites())->not->toBeEmpty();
    expect(TicketLedgerMutationInventory::mutationSites())->not->toBeEmpty();
    expect(TicketLedgerMutationInventory::deleteSites())->not->toBeEmpty();
    expect(TicketLedgerMutationInventory::trashedScopeSites())->not->toBeEmpty();
});

test('TLM-2 の負のコントロール: 未申告の変更サイトを混ぜると exact-fit が点灯する', function (): void {
    $detected = [];
    foreach (ticketLedgerMutationScan() as $path => $result) {
        if ($result['model'] && $result['mutations'] > 0) {
            $detected[$path] = $result['mutations'];
        }
    }
    $detected['app/Services/Billing/UndeclaredLedgerMutator.php'] = 1;
    ksort($detected);

    expect($detected)->not->toBe(
        ticketLedgerMutationExpected(TicketLedgerMutationInventory::mutationSites()),
    );
});
