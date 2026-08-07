<?php

declare(strict_types=1);

use App\Enums\Manual\AnalysisStep;
use App\Enums\Manual\JobStatus;
use App\Enums\Security\ExternalCallKind;
use App\Exceptions\Manual\JobOwnershipLostException;
use App\Models\AnalysisJob;

/*
 * S1: 所有権喪失の共通語彙 (例外 + ExternalCallKind)。
 *
 * この例外は「異常」ではなく「正常だが観測したい事象」であり、固定 event 名の構造化ログとして
 * 集計される。したがって守るべき契約は **ログ context の schema** である:
 *  - 固定 event 名 (ExternalCallKind::LOG_EVENT) を含む
 *  - 必須 7 キーが揃う
 *  - PII (email / name / user) 由来のキーを含まない
 *  - 値がすべて scalar|null (外部 payload オブジェクトを埋め込まない)
 *
 * 運用契約: docs/architecture.md §ジョブの重複実行と結果の一回性
 */

/** 抑止ログの必須キー集合 (Manual / Billing 双方がこれを満たす)。 */
const JOB_OWNERSHIP_LOST_REQUIRED_KEYS = [
    'event',
    'job_type',
    'job_id',
    'expected_status',
    'actual_status',
    'stage',
    'external_call',
];

test('logContext() が固定 event 名 job_ownership_lost を含む', function (): void {
    $exception = JobOwnershipLostException::whileRunning(
        jobType: AnalysisJob::class,
        jobId: 42,
        actualStatus: JobStatus::Failed,
        stage: AnalysisStep::Extract->value,
        externalCall: ExternalCallKind::LlmCompletion,
    );

    expect($exception->logContext()['event'])->toBe(ExternalCallKind::LOG_EVENT)
        ->and(ExternalCallKind::LOG_EVENT)->toBe('job_ownership_lost');
});

test('logContext() の必須キー集合が仕様どおり (7 キー) で PII 由来のキーを含まない', function (): void {
    $exception = JobOwnershipLostException::whileRunning(
        jobType: AnalysisJob::class,
        jobId: 42,
        actualStatus: JobStatus::Failed,
        stage: AnalysisStep::Decompose->value,
        externalCall: ExternalCallKind::LlmCompletion,
    );

    $keys = array_keys($exception->logContext());
    sort($keys);
    $required = JOB_OWNERSHIP_LOST_REQUIRED_KEYS;
    sort($required);

    expect($keys)->toBe($required);

    // PII (email / name) をキー名レベルで機械的に排除する
    foreach ($keys as $key) {
        expect($key)->not->toContain('email')
            ->and($key)->not->toContain('name')
            ->and($key)->not->toContain('user');
    }
});

test('logContext() の値がすべて scalar|null (payload オブジェクトを埋め込んでいない)', function (): void {
    $exception = JobOwnershipLostException::whileRunning(
        jobType: AnalysisJob::class,
        jobId: 7,
        actualStatus: null, // 行が消えているケース
        stage: AnalysisStep::Generate->value,
        externalCall: ExternalCallKind::ObjectStoragePut,
    );

    $context = $exception->logContext();
    foreach ($context as $key => $value) {
        expect($value === null || is_scalar($value))->toBeTrue("{$key} が scalar|null ではない");
    }

    expect($context['actual_status'])->toBeNull()
        ->and($context['job_id'])->toBe(7)
        ->and($context['stage'])->toBe('generate')
        ->and($context['external_call'])->toBe('object_storage_put');
});

test('whileRunning() は expectedStatus に JobStatus::Running を入れる', function (): void {
    $exception = JobOwnershipLostException::whileRunning(
        jobType: AnalysisJob::class,
        jobId: 1,
        actualStatus: JobStatus::Succeeded,
        stage: AnalysisStep::Extract->value,
        externalCall: ExternalCallKind::LlmCompletion,
    );

    expect($exception->expectedStatus)->toBe(JobStatus::Running)
        ->and($exception->logContext()['expected_status'])->toBe('running')
        ->and($exception->logContext()['actual_status'])->toBe('succeeded');
});

test('cleanup 用の event 名は抑止用と別である (schema の混線防止)', function (): void {
    expect(ExternalCallKind::CLEANUP_LOG_EVENT)->toBe('job_ownership_lost_cleanup')
        ->and(ExternalCallKind::CLEANUP_LOG_EVENT)->not->toBe(ExternalCallKind::LOG_EVENT);
});
