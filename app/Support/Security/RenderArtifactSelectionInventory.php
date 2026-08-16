<?php

declare(strict_types=1);

namespace App\Support\Security;

use App\Enums\Security\RenderArtifactSelectionKind;

/**
 * `render_jobs` に対する succeeded 条件つきの直接クエリを持つ app/ 配下ファイルの目録
 * (deny-by-default。T154)。
 *
 * 守る不変条件:
 *   app/ 配下で render_jobs に succeeded 条件つきの直接クエリを書いてよいファイルは
 *   本目録に登録されたものだけである。そのうち「**受け取り対象を 1 件選ぶ**」区分
 *   (`Canonical`) は `Services/Manual/CurrentRenderArtifact.php` ちょうど 1 ファイルに限る。
 *
 * 強制は `tests/Architecture/CurrentRenderArtifactInventoryTest.php`
 * (exact-fit: 未登録の直接クエリも、実体を失った stale entry も fail させる)。
 *
 * **保証しないもの**: gate が閉じるのはファイル粒度の直接クエリだけである
 * (同一登録ファイル内のメソッド追加・別 helper 経由・動的呼び出しには沈黙する)。
 */
final class RenderArtifactSelectionInventory
{
    /**
     * app/ 相対パス => [区分, 根拠 (30 文字以上)]。
     *
     * @return array<string, array{kind: RenderArtifactSelectionKind, rationale: string}>
     */
    public static function entries(): array
    {
        return [
            'Services/Manual/CurrentRenderArtifact.php' => [
                'kind' => RenderArtifactSelectionKind::Canonical,
                'rationale' => '「いま受け取れる成果物はどれか」の唯一の選択式。playback / download / 詳細画面 props /'
                    .'一覧行 props の 4 消費者が同じ行を指すための場所であり、保持ポリシーと同じ世代定義を持つ。',
            ],
            'Services/Manual/RenderJobService.php' => [
                'kind' => RenderArtifactSelectionKind::SupersessionCriterion,
                'rationale' => 'newerSucceededExists() は「より新しい succeeded が在るか」の世代交代判定であり、'
                    .'受け取り対象を 1 件選ぶ式ではない (削除 job と reconcile の前提条件)。',
            ],
            'Models/VideoManual.php' => [
                'kind' => RenderArtifactSelectionKind::EagerLoadCandidate,
                'rationale' => 'latestSucceededRender() は一覧が eager load する候補行の relation であり、'
                    .'output_path を見ないため受け取れるかを判断しない (決定は Canonical に残る)。'
                    .'世代定義の一致は ManualRowFinishedVideoParityTest が固定する。',
            ],
            'Services/Manual/RenderPipeline.php' => [
                'kind' => RenderArtifactSelectionKind::SupersessionCriterion,
                'rationale' => 'finalize が自分より古い succeeded 行を集めて削除 job を投入するための収集であり、'
                    .'最新 1 件を選ぶ式ではない (id の大小比較のみで latest を使わない)。',
            ],
        ];
    }
}
