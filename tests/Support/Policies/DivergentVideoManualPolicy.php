<?php

declare(strict_types=1);

namespace Tests\Support\Policies;

use App\Models\User;
use App\Models\VideoManual;

/**
 * kind→ability 写像を **behavioral に観測する**ためのテスト専用 policy (T154)。
 *
 * 本番の `VideoManualPolicy::render` と `::download` はどちらも `ProjectPolicy::update` に
 * 落ちるため**可否が完全に同値**で、写像を `'render'` 固定へ変異させても本番 policy 下では
 * 観測差が出ない。そこで `Gate::policy()` でこの policy を差し込み、
 * ability ごとに可否を分岐させて写像そのものを固定する。
 *
 * **app/ には置かない** (本番経路から到達しないテスト専用の道具)。
 */
final class DivergentVideoManualPolicy
{
    /** テストが立てる分岐スイッチ (既定は両方許可 = 現行 policy と同挙動) */
    public static bool $allowRender = true;

    public static bool $allowDownload = true;

    /** 分岐スイッチを既定へ戻す (残留を実行順に依存させない) */
    public static function reset(): void
    {
        self::$allowRender = true;
        self::$allowDownload = true;
    }

    public function view(User $user, VideoManual $manual): bool
    {
        return true;
    }

    public function render(User $user, VideoManual $manual): bool
    {
        return self::$allowRender;
    }

    public function download(User $user, VideoManual $manual): bool
    {
        return self::$allowDownload;
    }
}
