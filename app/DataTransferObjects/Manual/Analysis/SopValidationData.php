<?php

declare(strict_types=1);

namespace App\DataTransferObjects\Manual\Analysis;

use App\Enums\Manual\ScenarioVerdict;
use App\Exceptions\Manual\LlmOutputInvalidException;
use App\Support\Manual\LlmJson;
use Illuminate\Support\Facades\Log;

/**
 * work-decomposition プロンプト出力の `validation` (手順書に対する所見) の検証済み DTO。
 *
 * 2 つの入口を持ち、**厳しさが違う**ことがこの DTO の要点である:
 * - fromPayload(): LLM 応答用。不正は LlmOutputInvalidException (= 有界リトライ)。
 * - fromStorage(): 保存済み JSON 用。不正は null + Log::warning (詳細画面を落とさない)。
 * どちらも同一の parse() を通るため、保存 shape と応答 shape は構造的に一致する。
 *
 * 所見は**表示専用**であり制御フローには使わない (保存・撮影・レンダを止めない)。
 */
final readonly class SopValidationData
{
    public const int MAX_REASON_CHARS = 200;

    public const int MAX_WORKS = 10;

    public const int MAX_WORK_TITLE_CHARS = 60;

    /** @param list<string> $works */
    public function __construct(
        public ScenarioVerdict $verdict,
        public string $reason,
        public array $works,
        public bool $splitRecommended,
    ) {}

    /**
     * LLM 応答 (decode 済み全体) から `validation` を厳格に取り出す。
     *
     * @param  array<array-key, mixed>  $decoded
     */
    public static function fromPayload(array $decoded): self
    {
        $raw = $decoded['validation'] ?? null;
        if (! is_array($raw)) {
            throw LlmJson::schemaViolation('validation は object でなければなりません', 'validation');
        }

        return self::parse($raw);
    }

    /**
     * 保存済み JSON からの復元 (壊れていたら null + 警告)。
     * **保存値の本文はログに載せない** (LLM 由来の可変文字列)。
     *
     * ★ 引数は `mixed` である。JSON カラムは cast の結果が array とは限らず
     *   (scalar / string が入っていれば `?array` 型宣言は **TypeError で詳細画面を落とす**)、
     *   「壊れていても画面を落とさない」という本メソッドの目的と矛盾するため。
     *   null は正常 (未生成)、array 以外は復元失敗として扱う。
     */
    public static function fromStorage(mixed $stored, int $analysisJobId): ?self
    {
        if ($stored === null) {
            return null; // 未生成 (旧ジョブ) は正常系
        }

        try {
            if (! is_array($stored)) {
                throw LlmJson::schemaViolation('validation_json が object ではありません', 'validation');
            }

            return self::parse($stored);
        } catch (LlmOutputInvalidException $exception) {
            Log::warning('解析ジョブの妥当性所見の復元に失敗しました', [
                'analysis_job_id' => $analysisJobId,
                'failure_category' => $exception->reason->value,
                'failure_path' => $exception->path,
            ]);

            return null;
        }
    }

    /** @param array<array-key, mixed> $raw */
    private static function parse(array $raw): self
    {
        $rawVerdict = $raw['verdict'] ?? null;
        // tryFrom の結果を変数で保持する (from() で二度引かない)
        $verdict = is_string($rawVerdict) ? ScenarioVerdict::tryFrom($rawVerdict) : null;
        if ($verdict === null) {
            throw LlmJson::schemaViolation(
                'validation.verdict は valid / needs_review / invalid のいずれかでなければなりません',
                'validation.verdict',
            );
        }

        $reason = $raw['reason'] ?? null;
        if (! is_string($reason) || trim($reason) === '') {
            throw LlmJson::schemaViolation('validation.reason は非空文字列でなければなりません', 'validation.reason');
        }
        if (mb_strlen($reason) > self::MAX_REASON_CHARS) {
            throw LlmJson::schemaViolation('validation.reason が文字数上限を超えています', 'validation.reason');
        }

        $rawWorks = $raw['works'] ?? null;
        if (! is_array($rawWorks) || ! array_is_list($rawWorks)) {
            throw LlmJson::schemaViolation('validation.works は配列でなければなりません', 'validation.works');
        }
        if (count($rawWorks) < 1 || count($rawWorks) > self::MAX_WORKS) {
            throw LlmJson::schemaViolation(
                'validation.works は 1 件以上 '.self::MAX_WORKS.' 件以内でなければなりません',
                'validation.works',
            );
        }

        $works = [];
        foreach ($rawWorks as $index => $work) {
            if (! is_string($work) || trim($work) === '') {
                throw LlmJson::schemaViolation(
                    "validation.works.{$index} は非空文字列でなければなりません",
                    "validation.works.{$index}",
                );
            }
            if (mb_strlen($work) > self::MAX_WORK_TITLE_CHARS) {
                throw LlmJson::schemaViolation(
                    "validation.works.{$index} が文字数上限を超えています",
                    "validation.works.{$index}",
                );
            }
            $works[] = $work;
        }

        $split = $raw['split_recommended'] ?? null;
        if (! is_bool($split)) {
            throw LlmJson::schemaViolation(
                'validation.split_recommended は真偽値でなければなりません',
                'validation.split_recommended',
            );
        }

        return new self($verdict, $reason, $works, $split);
    }

    /** 作業数は保存も出力もせず count() で導出する (LLM に数えさせない) */
    public function workCount(): int
    {
        return count($this->works);
    }

    /**
     * validation_json の保存 shape (fromStorage が受理する shape と同一)。
     *
     * @return array{verdict: string, reason: string, works: list<string>, split_recommended: bool}
     */
    public function toArray(): array
    {
        return [
            'verdict' => $this->verdict->value,
            'reason' => $this->reason,
            'works' => $this->works,
            'split_recommended' => $this->splitRecommended,
        ];
    }
}
