<?php

declare(strict_types=1);

namespace App\Exceptions\Manual;

use App\Enums\Manual\JobStatus;
use App\Enums\Security\ExternalCallKind;
use RuntimeException;

/**
 * 外部呼び出しの直前に所有権 (行の主キー, 進行中 status) を再検証して失われていた場合に投げる。
 *
 * 利用者は `App\Services\Manual\AnalysisPipeline` と `App\Services\Manual\RenderPipeline` の
 * 2 つだけで、どちらも Manual ドメインであるため本 namespace に置く
 * (Billing 側は例外を投げず structured return で閉じるので共用しない)。
 *
 * ★これは「異常」ではなく「正常だが観測したい事象」である。`report()` せず、
 *   固定 event 名つきの `Log::warning` で観測する (無音で握らない)。
 * ★コンテキストに PII (email / name) と外部 payload を**一切含めない**
 *   (JobOwnershipLostContextTest が固定する)。
 */
final class JobOwnershipLostException extends RuntimeException
{
    /**
     * @param  class-string  $jobType  所有権を失ったジョブ行のモデルクラス
     * @param  non-empty-string  $stage  既存ドメイン step enum の値
     *                                   (AnalysisStep / RenderStep。同じ語彙の enum を 2 本作らない)
     */
    private function __construct(
        public readonly string $jobType,
        public readonly int $jobId,
        public readonly JobStatus $expectedStatus,
        public readonly ?JobStatus $actualStatus,
        public readonly string $stage,
        public readonly ExternalCallKind $externalCall,
    ) {
        parent::__construct(sprintf(
            '%s#%d: 所有権を失ったため %s を中止しました (期待 %s / 実際 %s)',
            $jobType,
            $jobId,
            $externalCall->value,
            $expectedStatus->value,
            // 行が消えている (null) ケースを「missing」として文言に残す
            $actualStatus instanceof JobStatus ? $actualStatus->value : 'missing',
        ));
    }

    /**
     * @param  class-string  $jobType
     * @param  non-empty-string  $stage
     */
    public static function whileRunning(
        string $jobType,
        int $jobId,
        ?JobStatus $actualStatus,
        string $stage,
        ExternalCallKind $externalCall,
    ): self {
        return new self($jobType, $jobId, JobStatus::Running, $actualStatus, $stage, $externalCall);
    }

    /**
     * 構造化ログ用コンテキスト (PII を含まない)。
     *
     * @return array{event: string, job_type: string, job_id: int, expected_status: string,
     *               actual_status: string|null, stage: string, external_call: string}
     */
    public function logContext(): array
    {
        return [
            'event' => ExternalCallKind::LOG_EVENT,
            'job_type' => $this->jobType,
            'job_id' => $this->jobId,
            'expected_status' => $this->expectedStatus->value,
            'actual_status' => $this->actualStatus?->value,
            'stage' => $this->stage,
            'external_call' => $this->externalCall->value,
        ];
    }
}
