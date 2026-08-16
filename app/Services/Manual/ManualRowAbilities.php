<?php

declare(strict_types=1);

namespace App\Services\Manual;

use App\Models\Project;
use App\Models\User;
use App\Models\VideoManual;

/**
 * 一覧の行に出す操作 (完成動画のダウンロード / 削除) の可否。
 *
 * **前提 (名前が示す約束)**: download / delete の可否は「その manual が属する project」で決まり、
 * manual 個別の属性 (status / 作成者 / カテゴリ) には依存しない。
 * VideoManualPolicy::download / ::delete が対象から `project` しか読まず
 * ProjectPolicy::update へ委譲しているためである。よって**ページで 1 回だけ**評価して全行へ配る。
 *
 * **なぜ畳むか**: ProjectPolicy は毎回 DB を見る (Project::memberRole() は memo 無しのクエリ、
 * Laratrust のキャッシュは config/laratrust.php の既定で production 以外は無効)。
 * 行ごとに can() を呼ぶと権限解決クエリが行数に比例する (per_page=10 × 2 ability)。
 *
 * **なぜ ProjectPolicy::update を直接問わないか**: それは委譲関係を呼び出し側へ
 * ハードコードすることであり、policy が分岐した日に**赤くならずに間違う**。
 * 問う ability 名は download / delete のまま保ち、評価の**回数**だけを畳む。
 *
 * 前提は ManualRowAbilityPremiseTest が固定し (manual 依存になったら赤くなる)、
 * 行数に比例しないことは ManualListQueryCountTest が固定する。読み取り専用。
 *
 * **前提が崩れたときの手順**: ManualRowAbilityPremiseTest が赤くなったら、
 * 評価を行ループへ移す (そのとき N+1 の解消も同時に設計し直す)。
 */
final readonly class ManualRowAbilities
{
    private function __construct(
        public bool $canDownload,
        public bool $canDelete,
    ) {}

    /**
     * ページに載る行に対する可否。行が 1 件も無いページでは両方 false
     * (出す導線が無いので評価しない = 無駄な権限クエリを撃たない)。
     *
     * @param  list<VideoManual>  $manuals  同一 $project 配下であること (呼び出し側が保証する)
     */
    public static function forPage(User $user, Project $project, array $manuals): self
    {
        $representative = $manuals[0] ?? null;
        if ($representative === null) {
            return new self(canDownload: false, canDelete: false);
        }

        // policy が親を読み直すクエリを避けるため、解決済みの project を先に確定させる
        // (同一 project 配下であることは呼び出し側 = $project->manuals() が保証している)
        $representative->setRelation('project', $project);

        return new self(
            canDownload: $user->can('download', $representative),
            canDelete: $user->can('delete', $representative),
        );
    }
}
