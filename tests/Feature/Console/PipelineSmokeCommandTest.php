<?php

declare(strict_types=1);

use App\Models\Organization;
use App\Models\Project;
use App\Models\User;
use App\Models\VideoManual;
use App\Services\Billing\TicketLedgerService;
use App\Services\Project\ProjectService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Kent013\PrismPrompt\Prompt as PrismPrompt;

/*
 * pipeline smoke コマンド (施策 6) の**固有ロジック**を実 LLM なしに固定する。
 *
 * 固定するのは「fail-secure 条件 / preflight / 確認 / 出力」まで。
 * 各段の配線は段ごとの Feature テストが既に持っており、ffmpeg を Process::fake すると
 * このコマンドの唯一の固有価値 (実 ffmpeg が本当に回るか) が消えて偽グリーンになるため、
 * 全段を fake で通すテストは**書かない**。
 * `llm-evidence` 段の判定は純関数として SmokeFailureClassifierTest が固定する。
 */

/**
 * fail-secure 4 条件を満たす状態にする (bug-hunt レーン相当)。
 *
 * - env: bughunt.local
 * - DB 名: bug_hunt (接続名だけを差し替える。実 DB はテスト DB のまま)
 * - fake storage: on / fake LLM: off
 * - ffmpeg / ffprobe: PHP バイナリで代用 (`-version` が 0 終了する = preflight の分岐だけを固定する)
 */
function enterSmokeLane(): void
{
    app()->detectEnvironment(fn (): string => 'bughunt.local');
    DB::connection()->setDatabaseName('bug_hunt');
    config()->set('testing.fake_storage', true);
    config()->set('testing.fake_llm', false);
    config()->set('manual.render_ffmpeg_binary', PHP_BINARY);
    config()->set('manual.render_ffprobe_binary', PHP_BINARY);
}

/**
 * preflight を通せる組織 (所属 user あり・チケット残高十分) を作る。
 *
 * @return array{Organization, User}
 */
function smokeReadyOrganization(int $tickets = 100): array
{
    [$organization, $owner] = createOrganizationWithOwner();
    app(TicketLedgerService::class)->grant($organization, $tickets, 'pipeline-smoke test');

    return [$organization, $owner];
}

/**
 * @param  array<string, mixed>  $parameters
 * @return array{int, string}
 */
function runPipelineSmoke(array $parameters = []): array
{
    $exitCode = Artisan::call('dev:pipeline-smoke', $parameters);

    return [$exitCode, Artisan::output()];
}

/**
 * `--json` の契約 = **結果 JSON は最終行に 1 行**。
 * `--force` なしだと確認 UI が先に描かれるため、常に最終行から取り出す。
 *
 * @return array<string, mixed>
 */
function decodeSmokeJson(string $output): array
{
    $lines = array_values(array_filter(array_map(trim(...), explode(PHP_EOL, $output)), fn (string $line): bool => $line !== ''));
    expect($lines)->not->toBe([]);

    /** @var array<string, mixed> $decoded */
    $decoded = json_decode((string) end($lines), true, 512, JSON_THROW_ON_ERROR);

    return $decoded;
}

// ── fail-secure 4 条件 (--force でも迂回できない) ───────────────────────

it('bughunt.local 以外の env では実行しない', function (): void {
    smokeReadyOrganization();
    // enterSmokeLane() を呼ばない = env は testing のまま

    [$exitCode, $output] = runPipelineSmoke(['--check' => true]);

    expect($exitCode)->toBe(Command::FAILURE)
        ->and($output)->toContain('env が bughunt.local ではありません')
        ->and(PrismPrompt::isFaking())->toBeFalse();
});

it('fail-secure 失敗でも --json は DTO の 1 経路で機械可読出力を返す', function (): void {
    smokeReadyOrganization();
    // env は testing のまま = fail-secure 1 で落ちる

    [$exitCode, $output] = runPipelineSmoke(['--check' => true, '--json' => true]);
    $decoded = json_decode(trim($output), true, 512, JSON_THROW_ON_ERROR);

    expect($exitCode)->toBe(Command::FAILURE)
        ->and($decoded['passed'])->toBeFalse()
        ->and($decoded['failure_class'])->toBe('preflight')
        ->and($decoded['stages'][0]['stage'])->toBe('preflight')
        ->and($decoded['stages'][0]['detail'])->toContain('env が bughunt.local ではありません')
        // レーンの実測状態が context に出る (期待値の決め打ちではない)
        ->and($decoded['context'])->toHaveKeys(['env', 'db', 'fake_storage', 'fake_llm'])
        ->and($decoded['context']['env'])->toBe('testing');
});

it('bug-hunt DB 以外では実行しない', function (): void {
    smokeReadyOrganization();
    enterSmokeLane();
    DB::connection()->setDatabaseName('aicue_dev');

    [$exitCode, $output] = runPipelineSmoke(['--check' => true]);

    expect($exitCode)->toBe(Command::FAILURE)
        ->and($output)->toContain('bug-hunt DB ではありません');
});

it('fake storage が無効なら実行しない', function (): void {
    smokeReadyOrganization();
    enterSmokeLane();
    config()->set('testing.fake_storage', false);

    [$exitCode, $output] = runPipelineSmoke(['--check' => true]);

    expect($exitCode)->toBe(Command::FAILURE)
        ->and($output)->toContain('fake storage が無効です');
});

it('fake LLM が有効なら実行しない', function (): void {
    smokeReadyOrganization();
    enterSmokeLane();
    config()->set('testing.fake_llm', true);

    [$exitCode, $output] = runPipelineSmoke(['--check' => true]);

    expect($exitCode)->toBe(Command::FAILURE)
        ->and($output)->toContain('fake LLM が有効です');
});

it('--force でも fail-secure 条件は迂回できない', function (): void {
    smokeReadyOrganization();
    enterSmokeLane();
    config()->set('testing.fake_llm', true);

    [$exitCode, $output] = runPipelineSmoke(['--force' => true]);

    expect($exitCode)->toBe(Command::FAILURE)
        ->and($output)->toContain('fake LLM が有効です')
        ->and(VideoManual::query()->count())->toBe(0);
});

// ── preflight (--check) ────────────────────────────────────────────────

it('--check は preflight の結果を出して終了する (LLM を 1 回も呼ばない)', function (): void {
    [$organization] = smokeReadyOrganization();
    enterSmokeLane();

    [$exitCode, $output] = runPipelineSmoke(['--check' => true]);

    expect($exitCode)->toBe(Command::SUCCESS)
        ->and($output)->toContain('preflight')
        ->and($output)->toContain('org=#'.$organization->id)
        ->and($output)->toContain('PASS')
        // Prompt の fake すら install しない (StrayLlmCallGuard が赤くならないことと対)
        ->and(PrismPrompt::isFaking())->toBeFalse()
        ->and(VideoManual::query()->count())->toBe(0);
});

it('--check で ffmpeg が実行できなければ preflight 失敗', function (): void {
    smokeReadyOrganization();
    enterSmokeLane();
    config()->set('manual.render_ffmpeg_binary', '/nonexistent/ffmpeg-for-smoke-test');

    [$exitCode, $output] = runPipelineSmoke(['--check' => true, '--json' => true]);
    $decoded = json_decode(trim($output), true, 512, JSON_THROW_ON_ERROR);

    expect($exitCode)->toBe(Command::FAILURE)
        ->and($decoded['failure_class'])->toBe('preflight')
        ->and($decoded['context']['ffmpeg'])->toBe('MISSING');
});

it('--check でチケット残高が足りなければ preflight 失敗', function (): void {
    // 残高不足の組織しか無い状態 (--org で名指しして「先頭の組織」探索に落とさない)
    [$organization] = smokeReadyOrganization(tickets: 1);
    enterSmokeLane();

    [$exitCode, $output] = runPipelineSmoke([
        '--check' => true, '--json' => true, '--org' => (string) $organization->id,
    ]);
    $decoded = json_decode(trim($output), true, 512, JSON_THROW_ON_ERROR);

    expect($exitCode)->toBe(Command::FAILURE)
        ->and($decoded['failure_class'])->toBe('preflight')
        ->and($decoded['stages'][0]['detail'])->toContain('チケット残高が不足');
});

it('--check で対象組織に所属 user がいなければ preflight 失敗', function (): void {
    $organization = Organization::factory()->create();
    app(TicketLedgerService::class)->grant($organization, 100, 'pipeline-smoke test');
    enterSmokeLane();

    [$exitCode, $output] = runPipelineSmoke([
        '--check' => true, '--json' => true, '--org' => (string) $organization->id,
    ]);
    $decoded = json_decode(trim($output), true, 512, JSON_THROW_ON_ERROR);

    expect($exitCode)->toBe(Command::FAILURE)
        ->and($decoded['failure_class'])->toBe('preflight')
        ->and($decoded['stages'][0]['detail'])->toContain('所属 user がいません');
});

it('--check --json の shape が固定される', function (): void {
    smokeReadyOrganization();
    enterSmokeLane();

    [$exitCode, $output] = runPipelineSmoke(['--check' => true, '--json' => true]);
    $decoded = json_decode(trim($output), true, 512, JSON_THROW_ON_ERROR);

    expect($exitCode)->toBe(Command::SUCCESS)
        ->and($decoded)->toHaveKeys([
            'passed', 'check_only', 'failure_class', 'total_elapsed_ms', 'context', 'stages', 'cost',
        ])
        ->and($decoded['passed'])->toBeTrue()
        ->and($decoded['check_only'])->toBeTrue()
        ->and($decoded['failure_class'])->toBeNull()
        // --check は LLM を 1 回も呼ばないのでコストレポートは付かない
        ->and($decoded['cost'])->toBeNull()
        ->and($decoded['stages'][0])->toHaveKeys(['stage', 'ok', 'elapsed_ms', 'detail', 'failure_class'])
        ->and($decoded['stages'][0]['stage'])->toBe('preflight');
});

it('--check は Default Project が無くても成功し will-create と出す (作成はしない)', function (): void {
    smokeReadyOrganization();
    enterSmokeLane();
    expect(Project::query()->count())->toBe(0);

    [$exitCode, $output] = runPipelineSmoke(['--check' => true, '--json' => true]);
    $decoded = json_decode(trim($output), true, 512, JSON_THROW_ON_ERROR);

    expect($exitCode)->toBe(Command::SUCCESS)
        ->and($decoded['context']['project'])->toBe('will-create')
        // --check は DB を 1 行も変更しない
        ->and(Project::query()->count())->toBe(0);
});

it('--check は既存 Default Project を existing として表示する', function (): void {
    [$organization, $owner] = smokeReadyOrganization();
    $project = app(ProjectService::class)->createProject($organization, '既存', null);
    enterSmokeLane();

    [, $output] = runPipelineSmoke(['--check' => true, '--json' => true]);
    $decoded = json_decode(trim($output), true, 512, JSON_THROW_ON_ERROR);

    expect($decoded['context']['project'])->toBe('existing #'.$project->id)
        ->and($decoded['context']['actor'])->toBe('#'.$owner->id);
});

it('--check は DB を読めない場合も preflight 失敗として JSON を返す', function (): void {
    smokeReadyOrganization();
    enterSmokeLane();
    // bug-hunt DB が未 provision / 未 migrate の状況を再現する
    // (DDL はトランザクショナルなので RefreshDatabase のロールバックで元に戻る)
    DB::statement('ALTER TABLE organizations RENAME TO organizations_absent_for_test');

    [$exitCode, $output] = runPipelineSmoke(['--check' => true, '--json' => true]);
    $decoded = json_decode(trim($output), true, 512, JSON_THROW_ON_ERROR);

    expect($exitCode)->toBe(Command::FAILURE)
        ->and($decoded['failure_class'])->toBe('preflight')
        ->and($decoded['stages'][0]['detail'])->toContain('DB を読めません');
});

// ── 実行確認 (課金の防壁) ──────────────────────────────────────────────

it('bughunt.local でも実行確認が出て、拒否したら何も実行しない', function (): void {
    smokeReadyOrganization();
    enterSmokeLane();

    // confirmToProceed() の第 2 引数 (常に確認する) を外すと確認が出ず、この期待が落ちる
    $this->artisan('dev:pipeline-smoke')
        ->expectsConfirmation('Are you sure you want to run this command?', 'no')
        ->assertExitCode(Command::INVALID);

    expect(VideoManual::query()->count())->toBe(0)
        ->and(Project::query()->count())->toBe(0);
});

it('確認なしで進めない (--force 無しの非対話実行は中止) / --json も DTO の 1 経路を通る', function (): void {
    smokeReadyOrganization();
    enterSmokeLane();

    // ★ `--no-interaction` を明示する。これが無いと確認プロンプトが実 TTY を掴んで
    //   **入力待ちで止まる** (= 確認が実在することの裏返し)。非対話の既定は「no」なので、
    //   このケースは「--force 無しの非対話実行は必ず中止される」= 費用の防壁でもある。
    [$exitCode, $output] = runPipelineSmoke(['--json' => true, '--no-interaction' => true]);
    $decoded = decodeSmokeJson($output);

    expect($exitCode)->toBe(Command::INVALID)
        ->and($decoded['passed'])->toBeFalse()
        ->and($decoded['check_only'])->toBeFalse()
        ->and($decoded['cost'])->toBeNull()
        ->and($decoded['context']['aborted'])->toContain('実行確認で拒否されました')
        // 確認は preflight の**後**に出るので preflight 段だけが記録され、業務経路は 1 つも走らない
        ->and($decoded['stages'])->toHaveCount(1)
        ->and($decoded['stages'][0]['stage'])->toBe('preflight')
        ->and($decoded['stages'][0]['ok'])->toBeTrue()
        ->and(VideoManual::query()->count())->toBe(0)
        ->and(Project::query()->count())->toBe(0);
});

it('--force なら確認を出さずに進む (fail-secure 条件は依然として効く)', function (): void {
    [$organization] = smokeReadyOrganization();
    enterSmokeLane();
    // fixture 段で必ず落ちるようにして、実 LLM / worker 待ちへ進ませない
    // (Default Project 不在 + max_projects=0 → ProjectService::createProject が Quota で失敗)
    config()->set('quota.plans.'.config()->string('quota.fallback_plan').'.max_projects', 0);
    expect($organization->plan_code)->toBeNull();

    [$exitCode, $output] = runPipelineSmoke(['--force' => true, '--json' => true]);
    $decoded = json_decode(trim($output), true, 512, JSON_THROW_ON_ERROR);

    // 確認は出ず (出ていれば PendingCommand ではなく Artisan::call が入力待ちで壊れる)、
    // preflight を通過して fixture 段まで進んだうえで失敗している
    expect($exitCode)->toBe(Command::FAILURE)
        ->and($decoded['stages'][0]['stage'])->toBe('preflight')
        ->and($decoded['stages'][0]['ok'])->toBeTrue()
        ->and($decoded['stages'][1]['stage'])->toBe('fixture')
        ->and($decoded['stages'][1]['ok'])->toBeFalse()
        ->and(VideoManual::query()->count())->toBe(0);
});
