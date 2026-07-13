<?php

declare(strict_types=1);

namespace App\Services\Manual;

use App\Enums\Manual\JobStatus;
use App\Enums\Manual\RenderKind;
use App\Jobs\Capture\DeleteTakeObjectsJob;
use App\Models\AnalysisJob;
use App\Models\Project;
use App\Models\RenderJob;
use App\Models\SourceDocument;
use App\Models\Take;
use App\Models\VideoManual;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;

/**
 * VideoManual の書き込み操作 (create / updateMeta / delete)。
 *
 * - created_by はサーバ導出 (Auth 由来の userId を forceFill。payload から受けない)
 * - category は「入力名 category (id 値)」をロック済み project relation から再解決して
 *   associate する (FormRequest の exists 検証と保存時再解決の二段構え。
 *   cross-project の id は firstOrFail → 404 で拒否し DB を変更しない)
 * - 並行制御は CategoryService と同じ Project 行ロック (category 集合との整合を直列化)
 */
class VideoManualService
{
    public function __construct(
        private readonly SourceDocumentService $sourceDocuments,
    ) {}

    /** VideoManual 作成 (status は DB default の draft)。$document は任意の SOP 同時アップロード */
    public function create(Project $project, string $title, ?int $categoryId, int $userId, ?UploadedFile $document = null): VideoManual
    {
        return DB::transaction(function () use ($project, $title, $categoryId, $userId, $document): VideoManual {
            $locked = Project::whereKey($project->id)->lockForUpdate()->firstOrFail();
            $manual = $locked->manuals()->make(['title' => $title]);
            $manual->forceFill(['created_by' => $userId])->save();
            if ($categoryId !== null) {
                // 保存時再解決: ロック済み project 配下から取得 (cross-project は 404)
                $category = $locked->categories()->whereKey($categoryId)->firstOrFail();
                $manual->category()->associate($category)->save();
            }
            if ($document !== null) {
                // 新規 manual は競合なし (状態 guard 不要) のため appendDocument 直呼び
                $this->sourceDocuments->appendDocument($manual, $document);
            }

            return $manual;
        });
    }

    /** メタデータ更新 (title / category)。categoryId null は未分類化 (dissociate)。 */
    public function updateMeta(Project $project, VideoManual $manual, string $title, ?int $categoryId): VideoManual
    {
        return DB::transaction(function () use ($project, $manual, $title, $categoryId): VideoManual {
            $locked = Project::whereKey($project->id)->lockForUpdate()->firstOrFail();
            // 子は親に属する: ロック済み親 relation から再解決 (cross-project は 404)
            /** @var VideoManual $lockedManual */
            $lockedManual = $locked->manuals()->whereKey($manual->id)->firstOrFail();
            $lockedManual->fill(['title' => $title]);
            if ($categoryId !== null) {
                $category = $locked->categories()->whereKey($categoryId)->firstOrFail();
                $lockedManual->category()->associate($category);
            } else {
                $lockedManual->category()->dissociate();
            }
            $lockedManual->save();

            return $lockedManual;
        });
    }

    /**
     * VideoManual 削除。cascade で消える takes / source_documents の S3 キーを収集し
     * DeleteTakeObjectsJob (media queue) へ委譲する (概念設計 D7。孤児オブジェクト防止)。
     */
    public function delete(Project $project, VideoManual $manual): void
    {
        $paths = DB::transaction(function () use ($project, $manual): array {
            $locked = Project::whereKey($project->id)->lockForUpdate()->firstOrFail();
            // 子は親に属する: ロック済み親 relation から再解決 (cross-project は 404)
            /** @var VideoManual $lockedManual */
            $lockedManual = $locked->manuals()->whereKey($manual->id)->firstOrFail();
            $takePaths = Take::query()
                ->whereIn('cut_id', $lockedManual->cuts()->select('id'))
                ->get(['video_path', 'thumbnail_path'])
                ->flatMap(
                    /** @return list<string> */
                    static fn (Take $take): array => array_values(array_filter([$take->video_path, $take->thumbnail_path])),
                )
                ->all();
            $documentPaths = $lockedManual->sourceDocuments()
                ->get(['file_path'])
                ->map(static fn (SourceDocument $document): string => $document->file_path)
                ->all();
            $lockedManual->delete(); // cuts / takes / source_documents は FK cascade

            return array_values(array_unique([...$takePaths, ...$documentPaths]));
        });

        if ($paths !== []) {
            DeleteTakeObjectsJob::dispatch($paths); // tx 成功後に media queue へ (重複キーは除去済み)
        }
    }

    /**
     * 表示用の最新解析 job。stale な失敗 (失敗確定後に scenario 保存が成立) は null を返す。
     * これにより Show の解析パネルは矛盾した「解析失敗」alert を出さない (T032 / F-1-1)。
     */
    public function displayAnalysisJob(VideoManual $manual): ?AnalysisJob
    {
        $job = $manual->analysisJobs()->latest('id')->first();

        return $job !== null && $this->isStaleFailure($manual, $job->status, $job->scenario_version_at_terminal)
            ? null
            : $job;
    }

    /** 表示用の最新 kind=render の job (stale 失敗は null)。 */
    public function displayRenderJob(VideoManual $manual): ?RenderJob
    {
        return $this->latestRenderJobForDisplay($manual, RenderKind::Render);
    }

    /** 表示用の最新 kind=preview の job (stale 失敗は null)。 */
    public function displayPreviewJob(VideoManual $manual): ?RenderJob
    {
        return $this->latestRenderJobForDisplay($manual, RenderKind::Preview);
    }

    private function latestRenderJobForDisplay(VideoManual $manual, RenderKind $kind): ?RenderJob
    {
        $job = $manual->renderJobs()->where('kind', $kind->value)->latest('id')->first();

        return $job !== null && $this->isStaleFailure($manual, $job->status, $job->scenario_version_at_terminal)
            ? null
            : $job;
    }

    /**
     * 失敗 job が stale か (失敗確定後に scenario 保存が成立 = version が進んだ)。
     * snapshot が null (旧データ / 非失敗) の場合は not stale = 表示 (保守的に隠さない)。
     * 比較は `>` であり `>=` ではない: 同世代 (保存が挟まらなかった) 失敗はユーザーの
     * 現在の状態と矛盾しないため alert を残す。version が進んだ = 保存が挟まった時だけ抑制する。
     */
    private function isStaleFailure(VideoManual $manual, JobStatus $status, ?int $versionAtTerminal): bool
    {
        return $status === JobStatus::Failed
            && $versionAtTerminal !== null
            && $manual->scenario_version > $versionAtTerminal;
    }
}
