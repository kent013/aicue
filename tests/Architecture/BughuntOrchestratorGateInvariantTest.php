<?php

declare(strict_types=1);

use Tests\Support\Bughunt\ShellFunctionWindow;

/*
 * Architecture invariant (B-HARNESS-01): bug-hunt の orchestrator gate 2 層が構造的に整合すること。
 *
 * SoT:
 *   - AGENTS.md §bug-hunt「dev DB 防御 (非交渉)」= provision/teardown は
 *     BUGHUNT_ORCHESTRATOR=1 を持つ親のみ (worker は default-deny)
 *   - scripts/bug-hunt-shard.sh  = MECHANICAL gate (require_orchestrator)
 *   - .claude/agents/bughunt-shard.md = AGENT prose gate (worker への禁止規律)
 *
 * 固定する事故: 環境障害に直面した shard worker が自走復旧を試み、共有 worktree / serve / DB を
 * 壊して run 全体を巻き添えにすること。機械 gate (env token の default-deny) と散文 gate
 * (worker への明示禁止) の 2 層が呼応していることを、ファイル静的検査で固定する。
 *
 * 実行配線 (副作用の前に die すること) は `scripts/bug-hunt-shard.sh self-test` の
 * end-to-end ケースが担う (二段防御): Architecture = 静的配線、self-test = 実行配線。
 * DB 不使用の静的検査 (既存 Architecture テストと同じ作法)。
 */

function bughuntGateReadSource(string $relativePath): string
{
    $contents = file_get_contents(base_path($relativePath));
    expect($contents)->toBeString("{$relativePath} が読めない");
    /** @var string $contents */
    expect($contents)->not->toBe('', "{$relativePath} が空");

    return $contents;
}

/**
 * `^cmd_name()` 行から次の `^cmd_` 定義 (または EOF) までの関数窓を切り出す。
 *
 * 切り出しの実体は Tests\Support\Bughunt\ShellFunctionWindow に一本化してある
 * (BughuntSeedWiringInvariantTest と共有する。同じ切り出しを 2 本持たない)。
 */
function bughuntGateFunctionWindow(string $source, string $name): string
{
    return ShellFunctionWindow::ofCommand($source, $name);
}

/**
 * heredoc を持たない関数 (require_orchestrator) 用の窓。行頭 `}` で終端する。
 * cmd_ 窓 (次の cmd_ 定義まで) は他関数を巻き込むため、gate 本体の検査には使わない。
 */
function bughuntGateBraceWindow(string $source, string $name): string
{
    $m = [];
    $matched = preg_match('/^'.preg_quote($name, '/').'\(\)\s*\{[\s\S]*?^\}/m', $source, $m);
    expect($matched)->toBe(1, "関数窓が見つからない: {$name}");

    /** @var array{0: string} $m */
    return $m[0];
}

/**
 * `local ...` 行が「副作用を持たない引数束縛」かを判定する。
 *
 * bash の `local x="$(cmd)"` / `local x=`cmd`` / `local x=$(< <(cmd))` は **コマンドを実行する**。
 * `local` を一律で前置きとみなすと、gate より前に任意コマンドを差し込めてしまい
 * 「gate が最初の実効文」の保証が silent hole になる (impl-review R1 Critical)。
 * したがって command substitution / process substitution / backtick を含む `local` は
 * **実効文として扱う** (= gate より前にあれば fail させる)。
 */
function bughuntGateIsInertLocal(string $trimmed): bool
{
    if (preg_match('/^local\s/', $trimmed) !== 1) {
        return false;
    }

    // $(...) / `...` / <(...) / >(...) はいずれもコマンドを起動する。
    return preg_match('/\$\(|`|<\(|>\(/', $trimmed) !== 1;
}

/**
 * 関数窓から「最初の実効文」を返す。関数定義行・`{`・コメント・空行・引数束縛のみの
 * `local ...` 宣言は読み飛ばす (= 副作用を持たない前置き)。
 * ただしコマンド置換を含む `local` は副作用を持つため読み飛ばさない。
 *
 * aigenba 版の「gate が特定の呼び出しより前に現れる」より強く、「gate が最初の実効文である」
 * ことを直接固定する (AI-CUE の cmd_teardown は aigenba と本体構造が異なり、
 * 特定呼び出しへのアンカーが脆いため)。
 */
function bughuntGateFirstEffectiveStatement(string $window): string
{
    // `/u` は必須 (PcreUnicodeModifierGateTest): 非 UTF-8 モードの `\R` はバイト 0x85 (NEL)
    // にも一致し、日本語コメントを文字途中で分断して行構造を壊す。
    foreach (preg_split('/\R/u', $window) ?: [] as $line) {
        $trimmed = trim($line);
        if ($trimmed === '' || str_starts_with($trimmed, '#') || $trimmed === '{') {
            continue;
        }
        if (preg_match('/^[A-Za-z_][A-Za-z0-9_]*\(\)\s*\{?$/', $trimmed) === 1) {
            continue; // 関数定義行
        }
        if (bughuntGateIsInertLocal($trimmed)) {
            continue; // 引数束縛のみ (副作用なし)
        }

        return $trimmed;
    }

    return '';
}

test('bug-hunt-shard.sh の require_orchestrator が default-deny (token 無し → die 1) であること', function (): void {
    $sh = bughuntGateReadSource('scripts/bug-hunt-shard.sh');

    expect($sh)->toMatch('/require_orchestrator\s*\(\)\s*\{/');
    $window = bughuntGateBraceWindow($sh, 'require_orchestrator');
    // token があれば通し、無ければ die 1 (default-deny)。
    expect($window)->toMatch('/BUGHUNT_ORCHESTRATOR/');
    expect($window)->toMatch('/die\s+1/');
    // self-test (dryrun) は実資源に触れないため素通りさせる例外。
    expect($window)->toMatch('/is_dryrun\s*&&\s*return\s*0/');
});

test('provision / provision-all / teardown / pipeline-smoke が最初の実効文で require_orchestrator を呼ぶこと', function (): void {
    $sh = bughuntGateReadSource('scripts/bug-hunt-shard.sh');

    $expectations = [
        'cmd_provision' => 'provision',
        'cmd_provision_all' => 'provision-all',
        'cmd_teardown' => 'teardown',
        // pipeline-smoke は実 LLM を 3 段呼ぶ = 実行そのものが課金である。
        // dev DB 防御と同じ理由ではなく**費用の防壁**として同じ gate に乗せる。
        'cmd_pipeline_smoke' => 'pipeline-smoke',
    ];
    foreach ($expectations as $function => $label) {
        $window = bughuntGateFunctionWindow($sh, $function);
        $first = bughuntGateFirstEffectiveStatement($window);
        expect($first)->toMatch(
            '/^require_orchestrator\s+"'.preg_quote($label, '/').'"/',
            "{$function} の最初の実効文が require_orchestrator \"{$label}\" でない (実際: {$first})"
        );
    }
});

/**
 * AGENTS.md §bug-hunt が規約側に持つべき記述の違反一覧 (純関数 = 負のコントロール用)。
 *
 * @return list<string>
 */
function bughuntAgentsMdViolations(string $content): array
{
    $violations = [];
    if (! str_contains($content, 'BUGHUNT_ORCHESTRATOR=1')) {
        $violations[] = 'AGENTS.md に BUGHUNT_ORCHESTRATOR=1 の記述が無い';
    }
    if (! str_contains($content, 'default-deny')) {
        $violations[] = 'AGENTS.md に default-deny の明記が無い';
    }
    // 機械 gate の対象コマンドが規約側にも書かれていること。
    if (preg_match('/`provision`\/`teardown`/', $content) !== 1) {
        $violations[] = 'AGENTS.md に gate 対象コマンド (provision/teardown) の明記が無い';
    }

    return $violations;
}

/**
 * bughunt-shard.md (worker への散文 gate) が持つべき記述の違反一覧 (純関数)。
 *
 * @return list<string>
 */
function bughuntShardAgentViolations(string $content): array
{
    $violations = [];
    foreach (['B-HARNESS-01', '環境障害時の鉄則'] as $needle) {
        if (! str_contains($content, $needle)) {
            $violations[] = "bughunt-shard.md に「{$needle}」が無い";
        }
    }
    // 復旧を試みず報告して終了する規律。
    if (preg_match('/復旧を絶対に試みない/u', $content) !== 1) {
        $violations[] = 'bughunt-shard.md に「復旧を絶対に試みない」規律が無い';
    }
    // 禁止コマンド列 (worktree / provision 系)。
    foreach (['teardown-worktree.sh', 'setup-worktree.sh', 'provision-all'] as $needle) {
        if (! str_contains($content, $needle)) {
            $violations[] = "bughunt-shard.md の禁止コマンド列に {$needle} が無い";
        }
    }
    if (preg_match('/git worktree (add|remove|prune)/', $content) !== 1) {
        $violations[] = 'bughunt-shard.md の禁止コマンド列に git worktree 操作が無い';
    }
    // 散文 gate 単独ではなく「機械的にも拒否される」ことを worker に伝える = 2 層の呼応。
    if (! str_contains($content, 'BUGHUNT_ORCHESTRATOR')) {
        $violations[] = 'bughunt-shard.md が機械 gate (BUGHUNT_ORCHESTRATOR) に言及していない';
    }
    // 環境ハザードは自分の shard-report.md に記録して終了する。
    if (preg_match('/shard-report\.md/', $content) !== 1) {
        $violations[] = 'bughunt-shard.md に shard-report.md への記録指示が無い';
    }

    return $violations;
}

test('AGENTS.md §bug-hunt が BUGHUNT_ORCHESTRATOR の default-deny を規約として持つこと', function (): void {
    expect(bughuntAgentsMdViolations(bughuntGateReadSource('AGENTS.md')))->toBe([]);
});

test('bughunt-shard.md が環境障害鉄則・禁止コマンド列・機械 gate との呼応を持つこと', function (): void {
    expect(bughuntShardAgentViolations(bughuntGateReadSource('.claude/agents/bughunt-shard.md')))->toBe([]);
});

/*
 * 負のコントロール: gate が「壊れた配線」を実際に検出することを fixture で確認する
 * (実スクリプトは書き換えない)。
 */
test('負のコントロール: gate が副作用の後ろに落ちた配線を検出する', function (): void {
    $broken = <<<'SH'
    cmd_provision() {
        local shard=$1 run_id=$2
        assert_worktree_context
        require_orchestrator "provision"
    }
    cmd_teardown() {
        :
    }
    SH;

    $first = bughuntGateFirstEffectiveStatement(bughuntGateFunctionWindow($broken, 'cmd_provision'));
    expect($first)->toBe('assert_worktree_context');
    expect($first)->not->toMatch('/^require_orchestrator/');

    // 正のコントロール: gate が先頭なら検出しない。
    $good = <<<'SH'
    cmd_provision() {
        local shard=$1 run_id=$2
        require_orchestrator "provision"
        assert_worktree_context
    }
    cmd_teardown() {
        :
    }
    SH;
    expect(bughuntGateFirstEffectiveStatement(bughuntGateFunctionWindow($good, 'cmd_provision')))
        ->toMatch('/^require_orchestrator\s+"provision"/');
});

test('負のコントロール: コマンド置換を含む local を gate より前に置いた配線を検出する', function (): void {
    // `local x="$(cmd)"` は cmd を実行する = 副作用。gate の前に置けてはならない。
    $broken = <<<'SH'
    cmd_provision() {
        local shard=$1 run_id=$2
        local db="$(dropdb --if-exists app_dev)"
        require_orchestrator "provision"
    }
    cmd_teardown() {
        :
    }
    SH;
    $first = bughuntGateFirstEffectiveStatement(bughuntGateFunctionWindow($broken, 'cmd_provision'));
    expect($first)->toBe('local db="$(dropdb --if-exists app_dev)"');
    expect($first)->not->toMatch('/^require_orchestrator/');

    // backtick / process substitution も同様に実効文として扱う。
    foreach (['local db=`shard_db 0`', 'local fd=<(psql -c "drop database app_dev")'] as $line) {
        expect(bughuntGateIsInertLocal($line))->toBeFalse("コマンド起動を含む local を前置き扱いした: {$line}");
    }

    // 正のコントロール: 純粋な引数束縛は前置き扱いのまま (実スクリプトの現行形)。
    foreach (['local shard=$1 run_id=$2', 'local n=$1 hold=${2:-}', 'local run_id=$1 drop_db=${2:-}'] as $line) {
        expect(bughuntGateIsInertLocal($line))->toBeTrue("純粋な引数束縛を実効文扱いした: {$line}");
    }
});

test('負のコントロール: default-deny を fail-open にした require_orchestrator を検出する', function (): void {
    // token 未設定でも return 0 する (= 誰でも通る) 実装。die 1 が消えている。
    $broken = <<<'SH'
    require_orchestrator() {
        is_dryrun && return 0
        return 0
    }
    cmd_provision() {
        :
    }
    SH;

    $window = bughuntGateBraceWindow($broken, 'require_orchestrator');
    expect($window)->not->toMatch('/BUGHUNT_ORCHESTRATOR/');
    expect($window)->not->toMatch('/die\s+1/');
});

test('負のコントロール: 散文 gate (規約文書) の喪失を検出する', function (): void {
    // AGENTS.md から default-deny 規約が消え、worker 向け禁止コマンド列も薄まった状態。
    $weakenedAgents = "## bug-hunt\n\nDB 操作は wrapper 経由で行う。provision は親が実行する。\n";
    $agentsViolations = bughuntAgentsMdViolations($weakenedAgents);
    expect($agentsViolations)->not->toBe([]);
    expect(implode("\n", $agentsViolations))->toContain('default-deny');

    $weakenedShardAgent = "あなたはバグハントの 1 シャードワーカーである。\n"
        ."環境が壊れたら状況に応じて復旧してよい。\n";
    $shardViolations = bughuntShardAgentViolations($weakenedShardAgent);
    expect($shardViolations)->not->toBe([]);
    expect(implode("\n", $shardViolations))->toContain('B-HARNESS-01');
    expect(implode("\n", $shardViolations))->toContain('teardown-worktree.sh');
    expect(implode("\n", $shardViolations))->toContain('BUGHUNT_ORCHESTRATOR');

    // 正のコントロール: 実ファイルは違反ゼロ (上の正 gate と同じ判定器であることの確認)。
    expect(bughuntAgentsMdViolations(bughuntGateReadSource('AGENTS.md')))->toBe([]);
    expect(bughuntShardAgentViolations(bughuntGateReadSource('.claude/agents/bughunt-shard.md')))->toBe([]);
});
