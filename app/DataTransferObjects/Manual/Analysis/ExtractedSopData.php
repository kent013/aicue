<?php

declare(strict_types=1);

namespace App\DataTransferObjects\Manual\Analysis;

use App\Support\Manual\LlmJson;
use App\Support\Manual\ScenarioLimits;

/**
 * sop-extract プロンプトの統一 JSON (doc/03 §3.4 unified スキーマ) の検証済み DTO。
 * `{ header: object, sections: [{ title: string|null, steps: [{ no, work_process,
 *   work_points[], safety_points[], quality_points[], pm_points[] }] }] }`
 *
 * 次段 (work-decomposition) へは toJsonString() で正規化 JSON を渡す。
 * source_documents.extracted_json へは toArray() を write-only 保存する (監査スナップショット)。
 */
final readonly class ExtractedSopData
{
    /**
     * @param  array<string, mixed>  $header
     * @param  list<array{title: string|null, steps: list<array{no: int, work_process: string,
     *   work_points: list<string>, safety_points: list<string>, quality_points: list<string>,
     *   pm_points: list<string>}>}>  $sections
     */
    public function __construct(
        public array $header,
        public array $sections,
    ) {}

    public static function fromLlmText(string $text): self
    {
        $decoded = LlmJson::decode($text);

        $header = $decoded['header'] ?? [];
        if (! is_array($header)) {
            throw LlmJson::schemaViolation('header は object でなければなりません');
        }
        /** @var array<string, mixed> $header */
        $rawSections = $decoded['sections'] ?? null;
        if (! is_array($rawSections) || ! array_is_list($rawSections)) {
            throw LlmJson::schemaViolation('sections は配列でなければなりません');
        }

        $sections = [];
        $totalSteps = 0;
        foreach ($rawSections as $index => $rawSection) {
            if (! is_array($rawSection)) {
                throw LlmJson::schemaViolation("sections.{$index} は object でなければなりません");
            }
            $title = $rawSection['title'] ?? null;
            if ($title !== null && ! is_string($title)) {
                throw LlmJson::schemaViolation("sections.{$index}.title は文字列または null でなければなりません");
            }
            $rawSteps = $rawSection['steps'] ?? null;
            if (! is_array($rawSteps) || ! array_is_list($rawSteps)) {
                throw LlmJson::schemaViolation("sections.{$index}.steps は配列でなければなりません");
            }

            $steps = [];
            foreach ($rawSteps as $stepIndex => $rawStep) {
                $steps[] = self::validateStep($rawStep, "sections.{$index}.steps.{$stepIndex}");
                $totalSteps++;
            }
            $sections[] = ['title' => $title, 'steps' => $steps];
        }

        if ($totalSteps < 1) {
            throw LlmJson::schemaViolation('手順が 1 件も抽出されていません');
        }
        // 有界性: 後段の作業分解が有界でも入力段で暴走させない (steps 総数を上限で打ち切らず拒否)
        if ($totalSteps > ScenarioLimits::MAX_STEPS * (1 + ScenarioLimits::MAX_POINTS_PER_STEP)) {
            throw LlmJson::schemaViolation('抽出手順数が上限を超えています');
        }

        return new self($header, $sections);
    }

    /**
     * @return array{no: int, work_process: string, work_points: list<string>,
     *   safety_points: list<string>, quality_points: list<string>, pm_points: list<string>}
     */
    private static function validateStep(mixed $rawStep, string $path): array
    {
        if (! is_array($rawStep)) {
            throw LlmJson::schemaViolation("{$path} は object でなければなりません");
        }
        $no = $rawStep['no'] ?? null;
        if (! is_int($no)) {
            throw LlmJson::schemaViolation("{$path}.no は整数でなければなりません");
        }
        $workProcess = $rawStep['work_process'] ?? null;
        if (! is_string($workProcess) || trim($workProcess) === '') {
            throw LlmJson::schemaViolation("{$path}.work_process は非空文字列でなければなりません");
        }

        $lists = [];
        foreach (['work_points', 'safety_points', 'quality_points', 'pm_points'] as $key) {
            $raw = $rawStep[$key] ?? [];
            if (! is_array($raw) || ! array_is_list($raw)) {
                throw LlmJson::schemaViolation("{$path}.{$key} は配列でなければなりません");
            }
            $items = [];
            foreach ($raw as $item) {
                if (! is_string($item)) {
                    throw LlmJson::schemaViolation("{$path}.{$key} の要素は文字列でなければなりません");
                }
                $items[] = $item;
            }
            $lists[$key] = $items;
        }

        return [
            'no' => $no,
            'work_process' => $workProcess,
            'work_points' => $lists['work_points'],
            'safety_points' => $lists['safety_points'],
            'quality_points' => $lists['quality_points'],
            'pm_points' => $lists['pm_points'],
        ];
    }

    /** 次段プロンプトへ渡す正規化 JSON */
    public function toJsonString(): string
    {
        return json_encode($this->toArray(), JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    }

    /**
     * @return array<string, mixed> extracted_json 保存用
     */
    public function toArray(): array
    {
        return [
            'header' => $this->header,
            'sections' => $this->sections,
        ];
    }
}
