<?php

declare(strict_types=1);

namespace App\Enums\Security;

/**
 * `render_jobs` に対する succeeded 条件つきの直接クエリを持つ app/ 配下ファイルの区分 (T154)。
 *
 * 守る不変条件:
 *   「いま受け取れるレンダ成果物はどれか」を **1 件選ぶ**式を書いてよいのは
 *   `Services/Manual/CurrentRenderArtifact.php` ただ 1 ファイルである。
 *
 * 区分は「統合してよい」の意味ではなく、**何のために succeeded 行を引いているか**の記録である。
 * 強制は `tests/Architecture/CurrentRenderArtifactInventoryTest.php` (deny-by-default + exact-fit)。
 */
enum RenderArtifactSelectionKind: string
{
    /** 受け取り対象を 1 件選ぶ選択式の実体。**1 ファイルのみ** */
    case Canonical = 'canonical';

    /**
     * 世代交代の判定 (より新しい succeeded が在るか / 旧世代の収集)。
     * **選択ではない** — id の大小比較だけで「どれを受け取るか」を決めない。
     */
    case SupersessionCriterion = 'supersession_criterion';

    /**
     * 一覧が eager load する**候補行**の relation (最新 succeeded 1 行)。
     * **受け取れるかを判断しない** — `output_path` を見ないため、
     * 「受け取れる成果物はどれか」の決定は Canonical に残る
     * (両者が同じ行を指すことは behavioral な parity テストが固定する)。
     */
    case EagerLoadCandidate = 'eager_load_candidate';
}
