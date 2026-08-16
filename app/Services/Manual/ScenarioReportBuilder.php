<?php

declare(strict_types=1);

namespace App\Services\Manual;

use App\DataTransferObjects\Manual\Analysis\SopValidationData;
use App\DataTransferObjects\Manual\ScenarioReportData;
use App\DataTransferObjects\Manual\ScenarioVerdictViewData;
use App\Enums\Manual\JobStatus;
use App\Models\Cut;
use App\Models\VideoManual;
use App\Support\Manual\ScenarioRuleCheck;
use Illuminate\Database\Eloquent\Collection;

/**
 * 詳細画面の「生成結果の確認」props の組み立て。
 *
 * クエリは **cut 件数に依存しない 3 本**:
 *  1. cuts 全件 (sort_order 昇順) — 規約検査とカット構成
 *  2. 最新の succeeded な解析ジョブ (relation 起点) — 所見
 *  3. 最新の手順書 id (relation 起点、id のみ) — 所見の鮮度
 *
 * ★ 取得はすべて **$manual の relation 経由**である (クラス起点の主キー取得を作らない =
 *   cross-org 不可の不変条件を構造的に満たし、DirectFetchInventory への登録も要らない)。
 * ★ 所見の出所は「最新の succeeded ジョブ」であって「最新のジョブ」ではない。
 *   いま画面にある cuts を作ったのは最後に成功した解析だからである
 *   (再解析が失敗しても、前回の所見と現在のシナリオの対応は保たれる)。
 * ★ 鮮度を **id の一致**で見てよい前提: source_document は **追記型 (append-only)** である。
 *   差し替えは新しい行の INSERT であり (SourceDocumentService::appendDocument。
 *   file_path を上書き更新する経路は無い)、解析対象は常に「最新 id の 1 件」
 *   (AnalysisJobService::trigger が行ロック下で latest('id') を選ぶ)。
 *   将来ファイルを in-place 更新する経路を作るなら、id ではなく内容の版で比較する必要がある。
 */
final class ScenarioReportBuilder
{
    public function build(VideoManual $manual): ?ScenarioReportData
    {
        // 位置表記が同値 sort_order で揺れないよう id を第 2 キーにする (CutSequencer と同じ並び)
        /** @var Collection<int, Cut> $cuts */
        $cuts = $manual->cuts()->orderBy('sort_order')->orderBy('id')->get();
        $report = ScenarioRuleCheck::run($cuts);

        $merged = new ScenarioReportData(
            verdict: $this->resolveVerdict($manual),
            stepCount: $report->stepCount,
            pointCount: $report->pointCount,
            findings: $report->findings,
        );

        return $merged->isEmpty() ? null : $merged; // 出す材料が何も無ければ props を出さない
    }

    private function resolveVerdict(VideoManual $manual): ?ScenarioVerdictViewData
    {
        $job = $manual->analysisJobs()
            ->where('status', JobStatus::Succeeded->value)
            ->latest('id')
            ->first();
        if ($job === null) {
            return null;
        }

        $validation = SopValidationData::fromStorage($job->validation_json, $job->id);
        if ($validation === null) {
            return null; // 未生成 (旧ジョブ) / 復元失敗 (fromStorage が警告を残している)
        }

        // max() は mixed を返す (driver により string 化されうる) ため数値であることを確かめてから比較する
        $rawLatestDocumentId = $manual->sourceDocuments()->max('id');
        $latestDocumentId = is_numeric($rawLatestDocumentId) ? (int) $rawLatestDocumentId : null;

        return new ScenarioVerdictViewData(
            validation: $validation,
            isCurrentDocument: $job->source_document_id !== null
                && $latestDocumentId !== null
                && $latestDocumentId === $job->source_document_id,
        );
    }
}
