<?php

declare(strict_types=1);

namespace App\DataTransferObjects\Manual\Analysis;

use App\DataTransferObjects\Manual\ScenarioPointInput;
use App\DataTransferObjects\Manual\ScenarioStepInput;
use App\Enums\Manual\ShotType;
use App\Support\Manual\LlmJson;
use App\Support\Manual\ScenarioLimits;

/**
 * scenario-generation プロンプトの出力 (`{ cuts: [...] }`) の検証済み DTO。
 *
 * cuts 行スキーマ: { no: int, type: "step"|"point", parent_no: int|null, scene: string,
 *   shot_type: "hiki"|"yori", shooting_point: string|null, narration: string,
 *   subtitle_primary: string|null, subtitle_secondary: string }
 *
 * 検証 (違反は LlmOutputInvalidException → 有界リトライ):
 * - type=step は parent_no null / type=point は既出 step の no を参照 (無参照・前方参照は不正)
 * - 文字数上限・steps/points 有界性は ScenarioLimits と同値 (手動保存と同じ有界性)
 *
 * toScenarioSteps() で手動保存と同じ入力型 (ScenarioStepInput / ScenarioPointInput、
 * id=null の新規のみ) に変換して materialize へ渡す (生成物と手動編集の合流点)。
 */
final readonly class GeneratedScenarioData
{
    /** @param list<ScenarioStepInput> $steps */
    public function __construct(public array $steps) {}

    public static function fromLlmText(string $text): self
    {
        $decoded = LlmJson::decode($text);

        $rawCuts = $decoded['cuts'] ?? null;
        if (! is_array($rawCuts) || ! array_is_list($rawCuts)) {
            throw LlmJson::schemaViolation('cuts は配列でなければなりません');
        }
        if (count($rawCuts) < 1) {
            throw LlmJson::schemaViolation('cuts は 1 件以上でなければなりません');
        }

        /** @var array<int, array{step: ScenarioStepInput, points: list<ScenarioPointInput>}> $stepsByNo */
        $stepsByNo = [];
        /** @var list<int> $stepOrder */
        $stepOrder = [];
        foreach ($rawCuts as $index => $rawCut) {
            if (! is_array($rawCut)) {
                throw LlmJson::schemaViolation("cuts.{$index} は object でなければなりません");
            }

            $no = $rawCut['no'] ?? null;
            if (! is_int($no)) {
                throw LlmJson::schemaViolation("cuts.{$index}.no は整数でなければなりません");
            }
            $type = $rawCut['type'] ?? null;
            if ($type !== 'step' && $type !== 'point') {
                throw LlmJson::schemaViolation("cuts.{$index}.type は step または point でなければなりません");
            }
            $parentNo = $rawCut['parent_no'] ?? null;
            if ($parentNo !== null && ! is_int($parentNo)) {
                throw LlmJson::schemaViolation("cuts.{$index}.parent_no は整数または null でなければなりません");
            }

            $fields = self::validateCutFields($rawCut, "cuts.{$index}");

            if ($type === 'step') {
                if ($parentNo !== null) {
                    throw LlmJson::schemaViolation("cuts.{$index}: step は parent_no を持てません");
                }
                if (isset($stepsByNo[$no])) {
                    throw LlmJson::schemaViolation("cuts.{$index}: step no {$no} が重複しています");
                }
                if (count($stepOrder) >= ScenarioLimits::MAX_STEPS) {
                    throw LlmJson::schemaViolation('steps が上限 ('.ScenarioLimits::MAX_STEPS.') を超えています');
                }
                $stepsByNo[$no] = [
                    'step' => new ScenarioStepInput(
                        id: null,
                        scene: $fields['scene'],
                        shotType: $fields['shotType'],
                        shootingPoint: $fields['shootingPoint'],
                        narration: $fields['narration'],
                        subtitlePrimary: $fields['subtitlePrimary'],
                        subtitleSecondary: $fields['subtitleSecondary'],
                        materialType: null,
                        staticDisplaySeconds: null,
                        points: [],
                    ),
                    'points' => [],
                ];
                $stepOrder[] = $no;

                continue;
            }

            // type=point: 既出 step の no を参照 (無参照・前方参照は不正)
            if ($parentNo === null || ! isset($stepsByNo[$parentNo])) {
                throw LlmJson::schemaViolation("cuts.{$index}: point の parent_no が既出 step を参照していません");
            }
            if (count($stepsByNo[$parentNo]['points']) >= ScenarioLimits::MAX_POINTS_PER_STEP) {
                throw LlmJson::schemaViolation("cuts.{$index}: step {$parentNo} の points が上限 (".ScenarioLimits::MAX_POINTS_PER_STEP.') を超えています');
            }
            $stepsByNo[$parentNo]['points'][] = new ScenarioPointInput(
                id: null,
                scene: $fields['scene'],
                shotType: $fields['shotType'],
                shootingPoint: $fields['shootingPoint'],
                narration: $fields['narration'],
                subtitlePrimary: $fields['subtitlePrimary'],
                subtitleSecondary: $fields['subtitleSecondary'],
                materialType: null,
                staticDisplaySeconds: null,
            );
        }

        $steps = [];
        foreach ($stepOrder as $no) {
            $entry = $stepsByNo[$no];
            $base = $entry['step'];
            $steps[] = new ScenarioStepInput(
                id: null,
                scene: $base->scene,
                shotType: $base->shotType,
                shootingPoint: $base->shootingPoint,
                narration: $base->narration,
                subtitlePrimary: $base->subtitlePrimary,
                subtitleSecondary: $base->subtitleSecondary,
                materialType: null,
                staticDisplaySeconds: null,
                points: $entry['points'],
            );
        }

        return new self($steps);
    }

    /**
     * cut 1 行の本文フィールド検証 (step / point 共通)。null 許容の narration /
     * subtitle_secondary は '' へ正規化する (DB NOT NULL。手動保存の正規化と同じ責務)。
     *
     * @param  array<array-key, mixed>  $rawCut
     * @return array{scene: string, shotType: ShotType, shootingPoint: string|null,
     *   narration: string, subtitlePrimary: string|null, subtitleSecondary: string}
     */
    private static function validateCutFields(array $rawCut, string $path): array
    {
        $scene = $rawCut['scene'] ?? null;
        if (! is_string($scene) || trim($scene) === '') {
            throw LlmJson::schemaViolation("{$path}.scene は非空文字列でなければなりません");
        }
        if (mb_strlen($scene) > ScenarioLimits::MAX_SCENE_CHARS) {
            throw LlmJson::schemaViolation("{$path}.scene が文字数上限を超えています");
        }

        $shotType = $rawCut['shot_type'] ?? null;
        if (! is_string($shotType) || ShotType::tryFrom($shotType) === null) {
            throw LlmJson::schemaViolation("{$path}.shot_type は hiki または yori でなければなりません");
        }

        $shootingPoint = $rawCut['shooting_point'] ?? null;
        if ($shootingPoint !== null && ! is_string($shootingPoint)) {
            throw LlmJson::schemaViolation("{$path}.shooting_point は文字列または null でなければなりません");
        }
        if (is_string($shootingPoint) && mb_strlen($shootingPoint) > ScenarioLimits::MAX_SCENE_CHARS) {
            throw LlmJson::schemaViolation("{$path}.shooting_point が文字数上限を超えています");
        }

        $narration = $rawCut['narration'] ?? null;
        if ($narration !== null && ! is_string($narration)) {
            throw LlmJson::schemaViolation("{$path}.narration は文字列または null でなければなりません");
        }
        $narration ??= '';
        if (mb_strlen($narration) > ScenarioLimits::MAX_NARRATION_CHARS) {
            throw LlmJson::schemaViolation("{$path}.narration が文字数上限を超えています");
        }

        $subtitlePrimary = $rawCut['subtitle_primary'] ?? null;
        if ($subtitlePrimary !== null && ! is_string($subtitlePrimary)) {
            throw LlmJson::schemaViolation("{$path}.subtitle_primary は文字列または null でなければなりません");
        }
        if (is_string($subtitlePrimary) && mb_strlen($subtitlePrimary) > ScenarioLimits::MAX_SUBTITLE_PRIMARY_CHARS) {
            throw LlmJson::schemaViolation("{$path}.subtitle_primary が文字数上限を超えています");
        }

        $subtitleSecondary = $rawCut['subtitle_secondary'] ?? null;
        if ($subtitleSecondary !== null && ! is_string($subtitleSecondary)) {
            throw LlmJson::schemaViolation("{$path}.subtitle_secondary は文字列または null でなければなりません");
        }
        $subtitleSecondary ??= '';
        if (mb_strlen($subtitleSecondary) > ScenarioLimits::MAX_SUBTITLE_SECONDARY_CHARS) {
            throw LlmJson::schemaViolation("{$path}.subtitle_secondary が文字数上限を超えています");
        }

        return [
            'scene' => $scene,
            'shotType' => ShotType::from($shotType),
            'shootingPoint' => $shootingPoint,
            'narration' => $narration,
            'subtitlePrimary' => $subtitlePrimary,
            'subtitleSecondary' => $subtitleSecondary,
        ];
    }

    /**
     * materialize 入力 (id=null, materialType=null, staticDisplaySeconds=null の新規のみ)。
     *
     * @return list<ScenarioStepInput>
     */
    public function toScenarioSteps(): array
    {
        return $this->steps;
    }
}
