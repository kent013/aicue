<?php

declare(strict_types=1);

namespace App\Services\Manual;

use App\Enums\Manual\CutType;
use App\Enums\Manual\JobStatus;
use App\Enums\Manual\RenderKind;
use App\Enums\Manual\VideoManualStatus;
use App\Jobs\Capture\DeleteTakeObjectsJob;
use App\Models\AnalysisJob;
use App\Models\Cut;
use App\Models\Project;
use App\Models\RenderJob;
use App\Models\SourceDocument;
use App\Models\Take;
use App\Models\VideoManual;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

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

    /**
     * VideoManual の複製 (別名保存)。保存済み cuts (シナリオ) を雛形に、新タイトル・カテゴリで
     * 新規 manual を作る。**takes / adopted_take_id / render 成果物 / source_documents /
     * analysis_jobs は複製しない** (新規撮影・再合成前提)。複製 manual は必ず
     * status=Draft・scenario_version=0 から開始する (この初期状態を INSERT 時に明示代入し、
     * DB カラム default に依存しない = 将来の migration default 変更による silent break を防ぐ)。
     *
     * シナリオ整合の共有ロック規約 (AGENTS.md ドメイン規約 1) の書き込み経路:
     *  - 元 manual を lockForUpdate してシナリオを一貫読み取り (read/copy の一貫性を確保)
     *  - cuts の書き込み先は**新規** manual。新 manual を save() 後に同一 tx 内で
     *    lockForUpdate 再取得し、その locked インスタンスの relation 経由で cut を作成する
     *    (「対象 VideoManual 行を lockForUpdate で取得した同一 tx 内で反映」を literal に満たす)
     *  - scenario_version / status は新 manual の INSERT 時に明示代入する (新規行生成のため
     *    lockForUpdate 前だが、その tx が生成した排他的新規行であり既存行への並行書き込みではない)。
     *    ScenarioWritePathInventoryTest の STATUS_WRITE_ALLOWED / SCENARIO_VERSION_ALLOWED に登録済み
     */
    public function duplicate(Project $project, VideoManual $source, string $title, ?int $categoryId, int $userId): VideoManual
    {
        return DB::transaction(function () use ($project, $source, $title, $categoryId, $userId): VideoManual {
            // ロック順は create/updateMeta と同じ project → manual
            $locked = Project::whereKey($project->id)->lockForUpdate()->firstOrFail();
            // 子は親に属する: 元 manual をロック済み親 relation から再解決 (cross-project は 404) + 一貫読み取り
            /** @var VideoManual $lockedSource */
            $lockedSource = $locked->manuals()->whereKey($source->id)->lockForUpdate()->firstOrFail();

            // 新 manual: status=Draft / scenario_version=0 を INSERT 時に明示代入して
            // 不変条件をアプリ層で固定する (DB default 依存をやめ silent break を防ぐ)。
            // created_by はサーバ導出。すべて排他的新規行 (並行書き込みなし) の初期値。
            $new = $locked->manuals()->make(['title' => $title]);
            $new->forceFill([
                'created_by' => $userId,
                'status' => VideoManualStatus::Draft,
                'scenario_version' => 0,
            ])->save();
            if ($categoryId !== null) {
                // 保存時再解決: 既存 create() と同一の firstOrFail。通常の不正/他 project category は
                // FormRequest の Rule::exists で 422 (検証時) に落ち、ここで 404 になるのは
                // 「検証通過後に category が削除/移動された」ごく稀な競合のみ (create と完全一致・後退なし)。
                $category = $locked->categories()->whereKey($categoryId)->firstOrFail();
                $new->category()->associate($category)->save();
            }

            // 共有ロック規約 literal 準拠: cuts 書き込み先の新 manual をロックして再取得
            /** @var VideoManual $lockedNew */
            $lockedNew = $locked->manuals()->whereKey($new->id)->lockForUpdate()->firstOrFail();
            $this->copyCuts($lockedSource, $lockedNew);

            return $lockedNew;
        });
    }

    /**
     * 元 manual の cuts を新 manual へ複製する (ロック済み tx 内前提)。
     * step を sort_order 順に複製 → 各 step 配下 point を sort_order 順に複製。
     * parent_cut_id は旧 step id→新 step id で張り替え、adopted_take_id/cut_length_ms は複製しない。
     */
    private function copyCuts(VideoManual $source, VideoManual $target): void
    {
        // initial orderBy(sort_order,id) を維持したまま filter する (Eloquent Collection の
        // filter/where は順序を保持 = 親内 point 順序は sort_order 準拠 = CutSequencer と同順)。
        $cuts = $source->cuts()->orderBy('sort_order')->orderBy('id')->get();
        /** @var array<int, Cut> $newStepByOldId 旧 step id → 新 step Cut */
        $newStepByOldId = [];

        // 段階1: step を複製 (parent_cut_id=null)
        foreach ($cuts->where('type', CutType::Step) as $step) {
            $newStepByOldId[$step->id] = $this->replicateCut($target, $step, null);
        }
        // 段階2: point を複製 (親 step の新 id へ張り替え)。
        // 孤児 point (親不明。通常発生しない) は skip し warning ログで観測可能にする (データ破損を黙殺しない)。
        foreach ($cuts->where('type', CutType::Point) as $point) {
            $parentOldId = $point->parent_cut_id;
            if ($parentOldId === null || ! isset($newStepByOldId[$parentOldId])) {
                Log::warning('マニュアル複製: 親不明の急所カットを複製対象から除外しました', [
                    'source_manual_id' => $source->id,
                    'cut_id' => $point->id,
                    'parent_cut_id' => $parentOldId,
                ]);

                continue;
            }
            $this->replicateCut($target, $point, $newStepByOldId[$parentOldId]->id);
        }
    }

    /**
     * 1 cut の複製。本文は fill、type/sort_order/parent_cut_id はサーバ導出値を forceFill。
     * adopted_take_id / cut_length_ms は複製しない (前者は default null、後者は明示 null リセット)。
     */
    private function replicateCut(VideoManual $target, Cut $source, ?int $parentCutId): Cut
    {
        $cut = $target->cuts()->make([
            'scene' => $source->scene,
            'shot_type' => $source->shot_type,
            'shooting_point' => $source->shooting_point,
            'narration' => $source->narration,
            'subtitle_primary' => $source->subtitle_primary,
            'subtitle_secondary' => $source->subtitle_secondary,
            'material_type' => $source->material_type,
            'static_display_seconds' => $source->static_display_seconds,
        ]);
        $cut->forceFill([
            'type' => $source->type,
            'sort_order' => $source->sort_order,
            'parent_cut_id' => $parentCutId,
            'cut_length_ms' => null, // レンダ由来。撮影前はリセット
        ]);
        $cut->save();

        return $cut;
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
