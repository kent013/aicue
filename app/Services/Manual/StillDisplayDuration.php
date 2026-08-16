<?php

declare(strict_types=1);

namespace App\Services\Manual;

use App\Models\Cut;

/**
 * 静止画カットの表示秒を決める式の**唯一の所在**。
 *
 * 編集者が `cuts.static_display_seconds` を指定していればそれを使い、未指定なら
 * `config('manual.default_still_display_seconds')` を使う。
 *
 * 以前は `RenderPipeline` が `manual.preview_placeholder_seconds`
 * (= 採用テイク欠落 cut のプレースホルダ尺) を流用していた。これは別概念であり、
 * プレースホルダ尺を変えると完成動画の静止画尺まで黙って変わる状態だった。撤去済み。
 *
 * **クランプしない**。異常値を黙って別の値へ変えると設定ミスが隠れる。
 *
 * **doc/02 §2.2 の「ナレーション尺より短ければナレーション尺が優先」は v1 では実装しない。**
 * v1 は字幕のみで TTS を持たず、ナレーション文に再生時間という属性が存在しないためである
 * (doc/09 の v1 尺算出も `cut_length = material_ms` / 静止画は `static_display_seconds*1000`)。
 * 再検討の条件は「TTS を導入してナレーション音声の実尺が確定したとき」で、
 * そのときの変更点は本クラス 1 か所に閉じる。
 */
final class StillDisplayDuration
{
    public static function secondsFor(Cut $cut): int
    {
        return $cut->static_display_seconds
            ?? config()->integer('manual.default_still_display_seconds');
    }
}
