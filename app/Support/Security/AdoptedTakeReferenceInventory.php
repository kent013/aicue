<?php

declare(strict_types=1);

namespace App\Support\Security;

use App\Enums\Security\AdoptedTakeReferenceKind;

/**
 * `adoptedTake` relation を参照する app/ 配下ファイルの目録 (deny-by-default。T148)。
 *
 * 守る不変条件:
 *   「採用済みかつ ready のテイクを持つか」の判定式を書いてよいのは
 *   `Services/Manual/AdoptedReadyTakeCoverage.php` ただ 1 ファイルである。
 *
 * 強制は `tests/Architecture/AdoptedReadyTakeCriterionInventoryTest.php`
 * (exact-fit: 未登録の参照も、参照実体を失った stale entry も fail させる)。
 */
final class AdoptedTakeReferenceInventory
{
    /**
     * app/ 相対パス => [区分, 根拠 (30 文字以上)]。
     *
     * @return array<string, array{kind: AdoptedTakeReferenceKind, rationale: string}>
     */
    public static function entries(): array
    {
        return [
            'Services/Manual/AdoptedReadyTakeCoverage.php' => [
                'kind' => AdoptedTakeReferenceKind::Canonical,
                'rationale' => '判定式の実体。render の 422 と preview の事前告知・Placeholder 分岐が'
                    .'同じ述語 isMissing() を通るための唯一の場所 (bug-hunt F-1-01 の再発防止)。',
            ],
            'Services/Manual/CutSequencer.php' => [
                'kind' => AdoptedTakeReferenceKind::RelationWiring,
                'rationale' => '表示順カット列の取得で with(adoptedTake) の eager load を張るだけで、'
                    .'ready 判定も採用有無の判定も持たない (N+1 回避のための構造上の参照)。',
            ],
            'Services/Manual/RenderJobService.php' => [
                'kind' => AdoptedTakeReferenceKind::DelegatedToCoverage,
                'rationale' => '充足判定は AdoptedReadyTakeCoverage へ委譲済みで、残る参照は'
                    .'尺上限ソフトゲートが採用テイクの duration_ms を読む 1 箇所だけである。',
            ],
            'Services/Manual/RenderPipeline.php' => [
                'kind' => AdoptedTakeReferenceKind::DelegatedToCoverage,
                'rationale' => 'clipSpecFor が isMissing() を呼んで Placeholder 分岐を決め、'
                    .'非欠落側でのみ素材パス (video_path) 取得のため take 実体を読む。',
            ],
            'Models/Cut.php' => [
                'kind' => AdoptedTakeReferenceKind::RelationWiring,
                'rationale' => 'adoptedTake の belongsTo relation 宣言そのもの。'
                    .'判定式は一切持たず、参照の起点を提供するだけのモデル定義である。',
            ],
            'DataTransferObjects/Capture/CaptureManualDetailData.php' => [
                'kind' => AdoptedTakeReferenceKind::DelegatedToCoverage,
                'rationale' => '採用テイクの署名 URL / ACK を出すかどうかを'
                    .'AdoptedReadyTakeCoverage::readyTakeId() へ委譲し、自前の ready 判定は持たない。'
                    .'残る参照は非欠落側で素材パスと take id を読む 1 箇所と、N+1 を防ぐ eager load である。',
            ],
            'DataTransferObjects/Manual/CutTakeSummaryData.php' => [
                'kind' => AdoptedTakeReferenceKind::DifferentCriterion,
                'rationale' => 'シナリオ編集画面の動画列が、カットごとに採用テイクの id / status / '
                    .'サムネイル生成有無を表示条件として読むだけで、採用済み ready テイクの充足判定はしない。'
                    .'レンダの充足判定 (AdoptedReadyTakeCoverage) とは基準が違うため意図的に統合しない。',
            ],
            'DataTransferObjects/Manual/TakeSelectionPageData.php' => [
                'kind' => AdoptedTakeReferenceKind::DifferentCriterion,
                'rationale' => 'PC テイク選択画面が「今どれを採用しているか」を示すために'
                    .'採用テイクの id と status を読むだけで、ready 判定も充足判定もしない。'
                    .'レンダの充足判定 (AdoptedReadyTakeCoverage) とは意図的に統合しない。',
            ],
            'Http/Controllers/Projects/VideoManualController.php' => [
                'kind' => AdoptedTakeReferenceKind::RelationWiring,
                'rationale' => 'シナリオ編集画面の動画列を N+1 なしで取るため with(adoptedTake) の'
                    .'eager load を張るだけで、判定も読み取りも持たない。値の取り出しは'
                    .'CutTakeSummaryData 側にあり、そちらが別基準として登録済みである。',
            ],
            'Models/VideoManual.php' => [
                'kind' => AdoptedTakeReferenceKind::DifferentCriterion,
                'rationale' => 'coverCut() が一覧カードの代表サムネイル候補を絞る条件として'
                    .'whereHas(adoptedTake, thumbnail_path 非 null) を持つ。'
                    .'見るのはサムネイルの生成有無だけで ready 状態は見ない別基準であり、'
                    .'採用済み ready テイクの充足判定 (AdoptedReadyTakeCoverage) とは意図的に統合しない。',
            ],
            'Http/Controllers/Capture/CaptureManualController.php' => [
                'kind' => AdoptedTakeReferenceKind::DifferentCriterion,
                'rationale' => 'whereHas(adoptedTake) による採用済みカット数の集計。'
                    .'ready を見ない別基準であり、レンダの充足判定とは意図的に統合しない。'
                    .'代表サムネイルの eager load (coverCut.adoptedTake) も同ファイルに並ぶが、'
                    .'こちらは N+1 を防ぐ構造上の指定で判定を持たない。',
            ],
            'Services/Dashboard/DashboardService.php' => [
                'kind' => AdoptedTakeReferenceKind::DifferentCriterion,
                'rationale' => 'whereDoesntHave(adoptedTake) による撮影待ち件数の集計。'
                    .'ready を見ない別基準であり、レンダの充足判定とは意図的に統合しない。',
            ],
            'Console/Commands/Development/PipelineSmokeCommand.php' => [
                'kind' => AdoptedTakeReferenceKind::DifferentCriterion,
                'rationale' => 'bug-hunt のパイプライン通し確認で未採用カット件数を数えるだけの'
                    .'開発用コマンド。adoptedTake 参照側は ready を見ない (別の TakeStatus::Ready '
                    .'参照は登録直後のテイク自身の確認であって採用テイクの充足判定ではない)。',
            ],
        ];
    }
}
