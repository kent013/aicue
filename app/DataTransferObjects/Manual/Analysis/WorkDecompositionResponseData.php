<?php

declare(strict_types=1);

namespace App\DataTransferObjects\Manual\Analysis;

use App\Support\Manual\LlmJson;

/**
 * work-decomposition プロンプトの応答全体 (`{ steps, validation }`)。
 * **decode は本クラスの fromLlmText() だけが行う** (同じ応答を 2 回パースしない)。
 */
final readonly class WorkDecompositionResponseData
{
    public function __construct(
        public WorkDecompositionData $decomposition,
        public SopValidationData $validation,
    ) {}

    public static function fromLlmText(string $text): self
    {
        $decoded = LlmJson::decode($text);

        return new self(
            WorkDecompositionData::fromPayload($decoded),
            SopValidationData::fromPayload($decoded),
        );
    }
}
