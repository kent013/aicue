<?php

declare(strict_types=1);

namespace App\Services\Manual;

use App\Enums\Manual\MaterialType;
use App\Models\Cut;
use App\Models\Take;

/**
 * 「このカット 1 本の尺は何 ms か。決まっていないなら決まっていない」を返す式の**唯一の所在**。
 *
 * 決まり方は 2 通りしかない:
 *   - 静止画として合成されるカット … `StillDisplayDuration::secondsFor()` × 1000。
 *     **撮影前でも決まる** (編集者がシナリオ編集で入れる計画値だから)。
 *   - 動画として合成されるカット … 採用済みかつ ready のテイクの `duration_ms`。
 *     テイクが無い / テイクの `duration_ms` が NULL なら**決まらない** (null を返す)。
 *
 * **null を既定値で埋めない**。埋めたい側 (レンダの尺上限ソフトゲートは上界を安全側に見たいので
 * `config('manual.render_default_take_duration_ms')` で埋める) が自分の政策として埋める。
 * 表示に使う側は埋めずに「未確定」として数える。ここで埋めると、
 * 撮っていないカットに 1 分あると利用者へ嘘をつくことになる。
 *
 * **採用テイクは引数で受ける** (このクラスは `adoptedTake` relation を読まない)。
 * したがって `AdoptedTakeReferenceInventory` の登録は増えない
 * (`EffectiveMaterialType` と同じ作法)。
 *
 * **ready 判定は一切しない** — 「採用済みかつ ready か」の述語は
 * `AdoptedReadyTakeCoverage` の専権である (AGENTS.md ドメイン固有規約 12)。
 * 呼び出し側がその述語で解決した結果を `$adoptedReadyTake` に渡す。
 *
 * **ナレーション尺は見ない**。v1 は字幕のみで TTS を持たず、ナレーション文に再生時間という
 * 属性が存在しない (`StillDisplayDuration` の docblock と同じ理由・同じ再検討条件)。
 */
final class DeterminedCutDuration
{
    /**
     * @param  Take|null  $adoptedReadyTake  採用済みかつ ready のテイク
     *                                       (`AdoptedReadyTakeCoverage` で解決済みのもの。無ければ null)
     * @return int|null 確定している尺 (ms)。確定していなければ null
     */
    public static function milliseconds(Cut $cut, ?Take $adoptedReadyTake): ?int
    {
        // テイクがまだ無いカットでも、計画が静止画なら尺は決まっている
        if ($adoptedReadyTake === null) {
            return $cut->material_type === MaterialType::Still
                ? StillDisplayDuration::secondsFor($cut) * 1000
                : null;
        }

        // 実体優先の判定 (cut=video / take=still の組み合わせを含む) は EffectiveMaterialType が持つ
        if (EffectiveMaterialType::of($cut, $adoptedReadyTake) === MaterialType::Still) {
            return StillDisplayDuration::secondsFor($cut) * 1000;
        }

        return $adoptedReadyTake->duration_ms;
    }
}
