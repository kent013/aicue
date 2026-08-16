<?php

declare(strict_types=1);

namespace App\DataTransferObjects\Manual;

use App\DataTransferObjects\Manual\Analysis\SopValidationData;

/**
 * 画面に出す「手順書への所見」。保存値 (SopValidationData) に**鮮度**を足したもの。
 *
 * is_current_document = 所見の対象がいまアップロードされている手順書と同一か。
 * false のとき画面は「解析時の手順書に対する所見です」と添えて再解析へ誘導する
 * (所見自体は隠さない)。
 */
final readonly class ScenarioVerdictViewData
{
    public function __construct(
        public SopValidationData $validation,
        public bool $isCurrentDocument,
    ) {}

    /**
     * @return array{verdict: string, reason: string, works: list<string>, work_count: int,
     *   split_recommended: bool, is_current_document: bool}
     */
    public function toArray(): array
    {
        return [
            ...$this->validation->toArray(),
            'work_count' => $this->validation->workCount(),
            'is_current_document' => $this->isCurrentDocument,
        ];
    }
}
