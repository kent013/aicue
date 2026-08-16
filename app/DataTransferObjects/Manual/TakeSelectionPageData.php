<?php

declare(strict_types=1);

namespace App\DataTransferObjects\Manual;

use App\Models\Cut;
use App\Models\Project;
use App\Models\Take;
use App\Models\VideoManual;
use App\Services\Manual\CutSequencer;

/**
 * PC テイク選択画面 (Manuals/Takes) の Inertia props 全体。
 * TS 側 types/manual.ts の TakeSelectionPageProps と対で保守する。
 *
 * 表示ラベル (手順N / 急所N-M) は CutSequencer::orderedWithLabels() から取る
 * (レンダの欠落ラベル・マニフェストと同じ導出元。ラベル規則を増やさない)。
 * 採用テイクは `adopted` キーで出す — 採用テイク外部キーの識別子は
 * ScenarioWritePathInventoryTest 検出 4 の deny-by-default 走査対象であり、
 * 表示のために security gate の allowlist を広げないための命名である。
 */
final readonly class TakeSelectionPageData
{
    /** @param list<SelectableTakeData> $takes */
    public function __construct(
        public Project $project,
        public VideoManual $manual,
        public Cut $cut,
        public string $label,
        public array $takes,
    ) {}

    public static function fromCut(Project $project, VideoManual $manual, Cut $cut): self
    {
        // route binding 済みの $cut は relation 未ロードなので明示的に読む
        // (暗黙の追加クエリを残さない)。
        $cut->loadMissing('adoptedTake');

        // 見つからないのは「親を持たない急所」= データ異常のときだけ。
        // 画面タイトルを空にせず中立語へ倒す (静かに空にして異常を隠さない)
        $label = 'カット';
        foreach (CutSequencer::orderedWithLabels($manual) as $ordered) {
            if ($ordered->cut->id === $cut->id) {
                $label = $ordered->label;
                break;
            }
        }

        /** @var list<SelectableTakeData> $takes */
        $takes = $cut->takes()
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get()
            ->map(static fn (Take $take): SelectableTakeData => SelectableTakeData::fromTake($take))
            ->values()
            ->all();

        return new self($project, $manual, $cut, $label, $takes);
    }

    /**
     * @return array{project: array{id: int, name: string},
     *   manual: array{id: int, title: string, status: string},
     *   cut: array{id: int, type: string, label: string, scene: string, narration: string,
     *     subtitle_primary: string|null, subtitle_secondary: string,
     *     adopted: array{id: int, status: string}|null},
     *   takes: list<array{id: int, status: string, size_bytes: int, duration_ms: int|null,
     *     comment: string|null, captured_at: string|null, sort_order: int, downloaded: bool,
     *     has_thumbnail: bool}>}
     */
    public function toArray(): array
    {
        $adopted = $this->cut->adoptedTake;

        return [
            'project' => ['id' => $this->project->id, 'name' => $this->project->name],
            'manual' => [
                'id' => $this->manual->id,
                'title' => $this->manual->title,
                // rendering / analyzing 中は採用が 409 になることの事前告知に使う
                'status' => $this->manual->status->value,
            ],
            'cut' => [
                'id' => $this->cut->id,
                'type' => $this->cut->type->value,
                'label' => $this->label,
                'scene' => $this->cut->scene,
                'narration' => $this->cut->narration,
                'subtitle_primary' => $this->cut->subtitle_primary,
                'subtitle_secondary' => $this->cut->subtitle_secondary,
                'adopted' => $adopted === null
                    ? null
                    : ['id' => $adopted->id, 'status' => $adopted->status->value],
            ],
            'takes' => array_map(
                static fn (SelectableTakeData $take): array => $take->toArray(),
                $this->takes,
            ),
        ];
    }
}
