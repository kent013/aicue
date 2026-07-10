<?php

declare(strict_types=1);

namespace App\Services\Manual;

use App\DataTransferObjects\Manual\Render\OrderedCut;
use App\Enums\Manual\CutType;
use App\Models\Cut;
use App\Models\VideoManual;

/**
 * カットの表示順 (step を sort_order 順 → 直後に配下 point を sort_order 順) と
 * 表示ラベル (手順N / 急所N-M) の導出。レンダのトリガー検証・マニフェスト構築が共用する
 * (読み取り専用。cuts / status / version は書かない)。
 */
final class CutSequencer
{
    /**
     * adoptedTake を eager load した表示順カット列を返す (行ロック済み manual の tx 内で呼ぶ)。
     *
     * @return list<OrderedCut>
     */
    public static function orderedWithLabels(VideoManual $manual): array
    {
        $cuts = $manual->cuts()->with('adoptedTake')->orderBy('sort_order')->orderBy('id')->get();

        /** @var list<Cut> $steps */
        $steps = $cuts->filter(fn (Cut $cut): bool => $cut->type === CutType::Step)->values()->all();
        /** @var array<int, list<Cut>> $pointsByParent */
        $pointsByParent = [];
        foreach ($cuts as $cut) {
            if ($cut->type === CutType::Point && $cut->parent_cut_id !== null) {
                $pointsByParent[$cut->parent_cut_id][] = $cut;
            }
        }

        $ordered = [];
        foreach ($steps as $stepIndex => $step) {
            $stepNumber = $stepIndex + 1;
            $ordered[] = new OrderedCut($step, "手順{$stepNumber}");
            foreach ($pointsByParent[$step->id] ?? [] as $pointIndex => $point) {
                $pointNumber = $pointIndex + 1;
                $ordered[] = new OrderedCut($point, "急所{$stepNumber}-{$pointNumber}");
            }
        }

        return $ordered;
    }
}
