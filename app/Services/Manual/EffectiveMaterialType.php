<?php

declare(strict_types=1);

namespace App\Services\Manual;

use App\Enums\Manual\MaterialType;
use App\Models\Cut;
use App\Models\Take;

/**
 * 「このカットを**実際に**どちらの素材として合成するか」を決める式の**唯一の所在**。
 *
 * 実体優先である: cut の計画が `still` でなくても、採用テイクの実体が画像なら `Still` を返す。
 * 理由は、**採用した後に編集者がシナリオ編集で cut.material_type を `video` へ戻せる**ためで、
 * 入口 (presign 422) でも採用 API でもこの状態は防げない。画像を動画クリップ経路
 * (`FfmpegVideoComposer::planTakeVideo()` = ffprobe で尺を測る) に流すと必ず壊れるので、
 * 「画像が動画クリップとして合成される道」を構造的に消す。
 *
 * **採用テイクは引数で受ける** (このクラスは `adoptedTake` relation を読まない)。
 * したがって `AdoptedTakeReferenceInventory` の登録は増えない。
 *
 * **ready 判定は一切しない** — 「採用済みかつ ready か」の述語は
 * `AdoptedReadyTakeCoverage` の専権である (AGENTS.md ドメイン固有規約 12)。本クラスは呼ばれる時点で
 * 採用テイクが確定していることを前提にする。
 */
final class EffectiveMaterialType
{
    public static function of(Cut $cut, Take $adoptedTake): MaterialType
    {
        return $cut->material_type === MaterialType::Still
            || $adoptedTake->material_type === MaterialType::Still
                ? MaterialType::Still
                : MaterialType::Video;
    }
}
