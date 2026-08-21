<?php

declare(strict_types=1);

namespace App\DataTransferObjects\Manual;

use App\Models\SourceDocument;
use Carbon\CarbonInterface;
use Webmozart\Assert\Assert;

/**
 * 手順書 (SOP) パネルに出す「現在登録されている手順書」1 件の現況。
 * TS 側 types/manual.ts の SourceDocumentSummaryProps と対で保守。
 *
 * - name は SourceDocument.original_name (業務情報・PII を含み得るため、当該 manual に
 *   属する最新 1 件のみを組織境界内 relation 経由で解決したものだけを載せる)。
 * - 表示整形 (サイズ単位・日時) は Svelte 側で行う。DTO に表示文言を混ぜない。
 */
final readonly class SourceDocumentSummaryData
{
    public function __construct(
        public string $name,
        public int $sizeBytes,
        /** ISO 8601 (タイムゾーン付き) 文字列。表示整形はフロント */
        public string $uploadedAt,
    ) {}

    public static function fromDocument(SourceDocument $document): self
    {
        // created_at は timestamps 由来で Larastan は nullable と評価し得る。
        // 握り潰し (?-> ?? '') はせず non-null を明示検査してから変換する。
        $uploadedAt = $document->created_at;
        Assert::isInstanceOf($uploadedAt, CarbonInterface::class, 'source_documents.created_at は非 null (timestamps)');

        return new self(
            name: $document->original_name,
            sizeBytes: $document->size_bytes,
            uploadedAt: $uploadedAt->toIso8601String(),
        );
    }

    /**
     * @return array{name: string, sizeBytes: int, uploadedAt: string}
     */
    public function toArray(): array
    {
        return [
            'name' => $this->name,
            'sizeBytes' => $this->sizeBytes,
            'uploadedAt' => $this->uploadedAt,
        ];
    }
}
