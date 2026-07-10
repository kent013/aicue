<?php

declare(strict_types=1);

namespace App\Http\Requests\Projects;

use App\DataTransferObjects\Manual\ScenarioPointInput;
use App\DataTransferObjects\Manual\ScenarioSaveInput;
use App\DataTransferObjects\Manual\ScenarioStepInput;
use App\Enums\Manual\MaterialType;
use App\Enums\Manual\ShotType;
use App\Http\Requests\Concerns\ProhibitsProtectedKeys;
use App\Support\Manual\ScenarioLimits;
use App\Support\Security\MassAssignmentProtectedKeys;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Webmozart\Assert\Assert;

/**
 * シナリオ document 一括保存 (doc/10 §10.8-5)。
 *
 * 入力境界の不変条件:
 * - 保護キー (parent_cut_id / adopted_take_id / video_manual_id 等) はトップレベルだけでなく
 *   steps.* / steps.*.points.* にも missing を明示 (ProhibitsProtectedKeys trait はトップレベル
 *   のみのため、ネスト配列は本 Request が自前で張る)
 * - sort_order / type もサーバ導出のため missing (§10.8-5: 構造から決定)
 * - id は「照合用」。存在検証は Service がロック下で行う (ここでは integer のみ)
 */
class UpdateScenarioRequest extends FormRequest
{
    use ProhibitsProtectedKeys;

    public function authorize(): bool
    {
        return true;
    }

    /**
     * narration / subtitle_secondary の null → '' 正規化はここで行う (下書き途中の空セル許容)。
     * DTO / DB は非 null 文字列で統一 (Request と Service で責務を分散させない)。
     *
     * 注意: 正規化は「キーが存在し、かつ値が null の場合だけ」行う (array_key_exists 判定)。
     * キー欠落を '' で補完すると present ルールが無効化され、未知キー・保護キーを含む
     * 元配列を作り直すと missing ルールの検査対象が失われるため、既存配列への最小変更に留める。
     */
    protected function prepareForValidation(): void
    {
        $steps = $this->input('steps');
        if (! is_array($steps)) {
            return;
        }

        foreach ($steps as $stepKey => $step) {
            if (! is_array($step)) {
                continue;
            }
            $row = $this->normalizeNullableTextKeys($step);
            $points = $row['points'] ?? null;
            if (is_array($points)) {
                foreach ($points as $pointKey => $point) {
                    if (is_array($point)) {
                        $points[$pointKey] = $this->normalizeNullableTextKeys($point);
                    }
                }
                $row['points'] = $points;
            }
            $steps[$stepKey] = $row;
        }

        $this->merge(['steps' => $steps]);
    }

    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        return array_merge(
            [
                'expected_version' => ['required', 'integer', 'min:0'],
                'steps' => ['present', 'array', 'max:'.ScenarioLimits::MAX_STEPS],
                // points キー欠落はクライアント直列化バグ。行単位で明示エラーにする
                'steps.*' => ['array', 'required_array_keys:points'],
                'steps.*.points' => ['present', 'array', 'max:'.ScenarioLimits::MAX_POINTS_PER_STEP],
                'steps.*.points.*' => ['array'],
            ],
            $this->cutRowRules('steps.*'),
            $this->cutRowRules('steps.*.points.*'),
            $this->nestedProtectedKeyRules('steps.*'),
            $this->nestedProtectedKeyRules('steps.*.points.*'),
            $this->protectedKeyMissingRules(),
        );
    }

    /** validated() を型付き入力 DTO に変換する唯一の変換点 (Assert で narrow)。 */
    public function toScenarioSaveInput(): ScenarioSaveInput
    {
        $validated = $this->validated();
        Assert::isArray($validated);
        $expectedVersion = $validated['expected_version'] ?? null;
        Assert::integerish($expectedVersion);
        $rawSteps = $validated['steps'] ?? [];
        Assert::isArray($rawSteps);
        // validated() は wildcard ルールの展開順で配列を再構築するため兄弟キーの順序を保証しない。
        // steps / points は JSON 配列由来の数値キーなので ksort で payload の表示順に戻す
        ksort($rawSteps);

        $steps = [];
        foreach ($rawSteps as $rawStep) {
            Assert::isArray($rawStep);
            $rawPoints = $rawStep['points'] ?? [];
            Assert::isArray($rawPoints);
            ksort($rawPoints);

            $points = [];
            foreach ($rawPoints as $rawPoint) {
                Assert::isArray($rawPoint);
                $values = $this->cutRowValues($rawPoint);
                $points[] = new ScenarioPointInput(...$values);
            }

            $values = $this->cutRowValues($rawStep);
            $steps[] = new ScenarioStepInput(...$values, points: $points);
        }

        return new ScenarioSaveInput((int) $expectedVersion, $steps);
    }

    /**
     * cut 1 行分の本文フィールド検証 (step / point 共通)。
     * scene は必須 (カットの定義)、narration / subtitle_secondary は下書き途中の保存を許す
     * (prepareForValidation で null → '' 正規化済みのため present + string。DB は NOT NULL)。
     * subtitle_primary の max:100 は DB string(100) と一致。
     *
     * @return array<string, list<mixed>>
     */
    private function cutRowRules(string $prefix): array
    {
        return [
            "{$prefix}.id" => ['nullable', 'integer'],
            "{$prefix}.scene" => ['required', 'string', 'max:1000'],
            "{$prefix}.shot_type" => ['required', Rule::enum(ShotType::class)],
            "{$prefix}.shooting_point" => ['nullable', 'string', 'max:1000'],
            "{$prefix}.narration" => ['present', 'string', 'max:2000'],
            "{$prefix}.subtitle_primary" => ['nullable', 'string', 'max:100'],
            "{$prefix}.subtitle_secondary" => ['present', 'string', 'max:2000'],
            "{$prefix}.material_type" => ['nullable', Rule::enum(MaterialType::class)],
            "{$prefix}.static_display_seconds" => ['nullable', 'integer', 'min:1', 'max:60'],
        ];
    }

    /**
     * ネスト行に対する保護キー + サーバ導出キー (sort_order / type) の拒否 (存在するだけで 422)。
     * MassAssignmentProtectedKeys::all() 由来のため保護キー追加時に自動追従する (drift しない)。
     *
     * @return array<string, list<string>>
     */
    private function nestedProtectedKeyRules(string $prefix): array
    {
        $rules = [];
        foreach ([...MassAssignmentProtectedKeys::all(), 'sort_order', 'type'] as $key) {
            $rules["{$prefix}.{$key}"] = ['missing'];
        }

        return $rules;
    }

    /**
     * cut 1 行分の生配列を DTO コンストラクタ引数へ narrow する (step / point 共通)。
     *
     * @param  array<array-key, mixed>  $row
     * @return array{id: int|null, scene: string, shotType: ShotType, shootingPoint: string|null,
     *   narration: string, subtitlePrimary: string|null, subtitleSecondary: string,
     *   materialType: MaterialType|null, staticDisplaySeconds: int|null}
     */
    private function cutRowValues(array $row): array
    {
        $id = $row['id'] ?? null;
        Assert::nullOrIntegerish($id);
        $scene = $row['scene'] ?? null;
        Assert::string($scene);
        $shotType = $row['shot_type'] ?? null;
        Assert::string($shotType);
        $shootingPoint = $row['shooting_point'] ?? null;
        Assert::nullOrString($shootingPoint);
        $narration = $row['narration'] ?? null;
        Assert::string($narration);
        $subtitlePrimary = $row['subtitle_primary'] ?? null;
        Assert::nullOrString($subtitlePrimary);
        $subtitleSecondary = $row['subtitle_secondary'] ?? null;
        Assert::string($subtitleSecondary);
        $materialType = $row['material_type'] ?? null;
        Assert::nullOrString($materialType);
        $staticDisplaySeconds = $row['static_display_seconds'] ?? null;
        Assert::nullOrIntegerish($staticDisplaySeconds);

        return [
            'id' => $id === null ? null : (int) $id,
            'scene' => $scene,
            'shotType' => ShotType::from($shotType),
            'shootingPoint' => $shootingPoint,
            'narration' => $narration,
            'subtitlePrimary' => $subtitlePrimary,
            'subtitleSecondary' => $subtitleSecondary,
            'materialType' => $materialType === null ? null : MaterialType::from($materialType),
            'staticDisplaySeconds' => $staticDisplaySeconds === null ? null : (int) $staticDisplaySeconds,
        ];
    }

    /**
     * 下書き許容の 2 キー (narration / subtitle_secondary) のみ null → '' 正規化する。
     *
     * @param  array<array-key, mixed>  $row
     * @return array<array-key, mixed>
     */
    private function normalizeNullableTextKeys(array $row): array
    {
        foreach (['narration', 'subtitle_secondary'] as $key) {
            if (array_key_exists($key, $row) && $row[$key] === null) {
                $row[$key] = '';
            }
        }

        return $row;
    }
}
