<?php

declare(strict_types=1);

namespace App\Services\Manual;

use App\Models\Project;
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

    /** VideoManual 削除。 */
    public function delete(Project $project, VideoManual $manual): void
    {
        DB::transaction(function () use ($project, $manual): void {
            $locked = Project::whereKey($project->id)->lockForUpdate()->firstOrFail();
            // 子は親に属する: ロック済み親 relation から再解決 (cross-project は 404)
            /** @var VideoManual $lockedManual */
            $lockedManual = $locked->manuals()->whereKey($manual->id)->firstOrFail();
            $lockedManual->delete();
        });
    }
}
