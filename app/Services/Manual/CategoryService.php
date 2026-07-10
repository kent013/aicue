<?php

declare(strict_types=1);

namespace App\Services\Manual;

use App\Models\Category;
use App\Models\Project;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Webmozart\Assert\Assert;

/**
 * Category の書き込み操作 (create / update / reorder / delete)。
 *
 * 並行制御: 全操作は transaction 冒頭で対象 Project 行を lockForUpdate() し、
 * project 単位に直列化する (行ロックは未作成行を守らないため「Category 全行ロック」では
 * 同時 create の末尾採番を直列化できない。共通の Project 行ロックが唯一の直列化点)。
 *
 * Service 境界の不変条件: ロック取得後に対象の子 (Category) を親 relation から
 * 再解決してから操作する。route binding 以外の経路から cross-project の子を
 * 渡されても firstOrFail (→404) で拒否し、DB を変更しない。
 */
class CategoryService
{
    /**
     * Category 作成。sort_order は project 内の末尾 (max+1) を採番する
     * ($fillable 外のため forceFill で明示代入)。
     */
    public function create(Project $project, string $name): Category
    {
        return DB::transaction(function () use ($project, $name): Category {
            $locked = Project::whereKey($project->id)->lockForUpdate()->firstOrFail();
            // ロック後の重複再検査 (複合 unique 違反の QueryException=500 を 422 へ先回り)
            $this->assertNameUnique($locked, $name, null);
            $max = $locked->categories()->max('sort_order');
            Assert::nullOrIntegerish($max);
            $next = $max === null ? 1 : (int) $max + 1;
            $category = $locked->categories()->make(['name' => $name]);
            $category->forceFill(['sort_order' => $next])->save();

            return $category;
        });
    }

    /** Category 更新 (name のみ)。 */
    public function update(Project $project, Category $category, string $name): Category
    {
        return DB::transaction(function () use ($project, $category, $name): Category {
            $locked = Project::whereKey($project->id)->lockForUpdate()->firstOrFail();
            // 子は親に属する: ロック済み親 relation から再解決 (cross-project は 404)
            /** @var Category $lockedCategory */
            $lockedCategory = $locked->categories()->whereKey($category->id)->firstOrFail();
            $this->assertNameUnique($locked, $name, $lockedCategory->id);
            $lockedCategory->fill(['name' => $name])->save();

            return $lockedCategory;
        });
    }

    /**
     * Category 並べ替え。送信 id 集合が当該 project の Category 集合と完全一致
     * (distinct・過不足なし) しなければ 422 (ロック中は集合が増減しないため 409 は不要)。
     *
     * @param  list<int>  $orderedIds
     */
    public function reorder(Project $project, array $orderedIds): void
    {
        DB::transaction(function () use ($project, $orderedIds): void {
            $locked = Project::whereKey($project->id)->lockForUpdate()->firstOrFail();
            /** @var list<int> $existing */
            $existing = $locked->categories()->pluck('id')->all();
            sort($existing);
            $sorted = $orderedIds;
            sort($sorted);
            if (count($orderedIds) !== count(array_unique($orderedIds)) || $sorted !== $existing) {
                throw ValidationException::withMessages([
                    'order' => ['並び順の指定がカテゴリ一覧と一致しません。画面を再読み込みしてやり直してください。'],
                ]);
            }
            if ($orderedIds === []) {
                return; // 空 (0 件 project) は再採番不要
            }
            // 集合一致検証済みの id へ 0..N-1 を割り当てる。Project 行ロック下の直列実行のため
            // per-row update で十分 (raw CASE 式は literal-string 制約と相性が悪く採用しない)
            foreach ($orderedIds as $index => $id) {
                $locked->categories()->whereKey($id)->update(['sort_order' => $index]);
            }
        });
    }

    /** Category 削除。所属 manual は FK nullOnDelete で未分類化される。 */
    public function delete(Project $project, Category $category): void
    {
        DB::transaction(function () use ($project, $category): void {
            $locked = Project::whereKey($project->id)->lockForUpdate()->firstOrFail();
            // 子は親に属する: ロック済み親 relation から再解決 (cross-project は 404)
            /** @var Category $lockedCategory */
            $lockedCategory = $locked->categories()->whereKey($category->id)->firstOrFail();
            $lockedCategory->delete();
        });
    }

    /** ロック取得後の重複再検査 (name は project 内ユニーク)。重複は 422。 */
    private function assertNameUnique(Project $locked, string $name, ?int $excludeId): void
    {
        $exists = $locked->categories()->where('name', $name)
            ->when($excludeId !== null, fn ($query) => $query->whereKeyNot($excludeId))
            ->exists();
        if ($exists) {
            throw ValidationException::withMessages([
                'name' => ['このカテゴリ名は既に使われています。'],
            ]);
        }
    }
}
