<?php

declare(strict_types=1);

namespace App\DataTransferObjects\Notification;

/**
 * 解析/レンダ完了通知の表示用 payload (manual_analyzed / manual_rendered 共用)。
 *
 * manualTitle / organizationName は発火時点のスナップショット
 * (manual 削除・org 改名・退会後も当時の名前で本文表示できる。join 不要)。
 * org 判定には使わない (org 文脈は notifications.organization_id 列が正)。
 */
final readonly class ManualJobPayload
{
    public function __construct(
        public int $projectId,
        public int $manualId,
        public string $manualTitle,
        public string $organizationName,
        public bool $succeeded,
        public ?string $error,
    ) {}

    /**
     * @return array{project_id: int, manual_id: int, manual_title: string,
     *   organization_name: string, succeeded: bool, error: string|null}
     */
    public function toArray(): array
    {
        return [
            'project_id' => $this->projectId,
            'manual_id' => $this->manualId,
            'manual_title' => $this->manualTitle,
            'organization_name' => $this->organizationName,
            'succeeded' => $this->succeeded,
            'error' => $this->error,
        ];
    }

    /**
     * 読み出し側の検証復元。型不整合は null 返し (= フロントは fallback 表示)。
     *
     * @param  array<array-key, mixed>  $data
     */
    public static function tryFromArray(array $data): ?self
    {
        $projectId = $data['project_id'] ?? null;
        $manualId = $data['manual_id'] ?? null;
        $manualTitle = $data['manual_title'] ?? null;
        $organizationName = $data['organization_name'] ?? null;
        $succeeded = $data['succeeded'] ?? null;
        $error = $data['error'] ?? null;

        if (! is_int($projectId) || ! is_int($manualId)
            || ! is_string($manualTitle) || ! is_string($organizationName)
            || ! is_bool($succeeded)
            || ($error !== null && ! is_string($error))) {
            return null;
        }

        return new self($projectId, $manualId, $manualTitle, $organizationName, $succeeded, $error);
    }
}
