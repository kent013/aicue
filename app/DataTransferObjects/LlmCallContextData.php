<?php

declare(strict_types=1);

namespace App\DataTransferObjects;

use Illuminate\Database\Eloquent\Model;
use Webmozart\Assert\Assert;

/**
 * LLM 呼び出しの**帰属コンテキスト**。`Prompt::withMetadata()` へ渡す 4 つの汎用キー
 * (organization_id / user_id / subject_type / subject_id) の値オブジェクト。
 *
 * ★ ここにアプリ固有の語彙を持ち込まない。subject は多態 (Model なら何でもよい) で持つ。
 *   これは記録層 (llm_call_logs) と listener (RecordLlmCallCost / RecordLlmCallFailure) が
 *   既に持っている契約そのものであり、本 DTO はその契約を**呼び出し側から型で守る**ためだけに存在する。
 * ★ organization / subject が null でも構築できる (console 実行など帰属が無い呼び出しがある)。
 *   欠落は LlmCallLogWriter が metadata_missing = true として記録し、
 *   コストレポート (LlmCostReportService) が件数として可視化する。
 */
final readonly class LlmCallContextData
{
    private function __construct(
        public ?int $organizationId,
        public ?int $userId,
        public ?string $subjectType,
        public ?string $subjectId,
    ) {}

    /**
     * subject は Eloquent Model から解決する。型名は **getMorphClass()** を使う
     * (morph map を設定しているリポジトリでもそのまま移植できる)。
     */
    public static function for(?int $organizationId, ?Model $subject, ?int $userId = null): self
    {
        $subjectId = null;
        if ($subject !== null) {
            // int 主キーでも ULID でも subject_id (string(64)) に収まる形へ寄せる
            $key = $subject->getKey();
            Assert::scalar($key, 'subject の主キーが scalar ではありません');
            $subjectId = (string) $key;
        }

        return new self(
            organizationId: $organizationId,
            userId: $userId,
            subjectType: $subject?->getMorphClass(),
            subjectId: $subjectId,
        );
    }

    /** 帰属が無い呼び出し (見本 / 運用スクリプト等) を**明示**するための名前付き構築子。 */
    public static function none(): self
    {
        return new self(null, null, null, null);
    }

    /**
     * withMetadata() へ渡す配列。**null のキーは落とす**
     * (LlmMetadataExtractor は isset() で判定するため null を入れても結果は同じだが、
     *  イベント payload に意味のない null を載せない)。
     *
     * @return array<string, int|string>
     */
    public function toMetadata(): array
    {
        return array_filter([
            'organization_id' => $this->organizationId,
            'user_id' => $this->userId,
            'subject_type' => $this->subjectType,
            'subject_id' => $this->subjectId,
        ], static fn (int|string|null $value): bool => $value !== null);
    }
}
