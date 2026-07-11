<?php

declare(strict_types=1);

namespace App\Services\Project;

use App\Models\Organization;
use App\Models\Project;

/**
 * Default Project の解決規約の single source of truth (v1 = 単一 Default Project 前提)。
 * 「org の先頭 project (projects.id 昇順の最初)」を Default Project と定義する。
 * 複数 project 化の際はここだけを差し替える (呼び出し側は不変)。
 *
 * read / write の分離 (概念設計 D2):
 * - resolve(): 表示・redirect 用 (ロックなし)。capture.home / 管理メニュー導線 / 一覧表示
 * - resolveForUpdate(): pivot 書き込み用 (lockForUpdate)。呼び出し側トランザクション内で
 *   取得から pivot 更新完了まで Project 行ロックを保持し、解決直後の project 削除競合を
 *   排除する (CategoryService の「Project 行ロック = 直列化点」既存規約と同型)。
 */
class DefaultProjectResolver
{
    public function resolve(Organization $organization): ?Project
    {
        /** @var Project|null */
        return $organization->projects()->orderBy('projects.id')->first();
    }

    /**
     * 必ず DB::transaction 内から呼ぶこと (ロール変更・招待受諾の pivot 書き込み専用)。
     *
     * 「id を先に確定 → 行ロック付き再取得」の 2 段にする: HasManyThrough に直接
     * lockForUpdate() を掛けると JOIN 先 (custom_teams) までロック対象になり、pgsql では
     * FOR UPDATE と JOIN の組合せが複雑化するため、単一テーブルの主キー lock に落とす。
     * id 確定後に行が消えた場合は null が返り、呼び出し側の不在時契約 (error bag / 未割当)
     * に倒れる。
     */
    public function resolveForUpdate(Organization $organization): ?Project
    {
        $id = $organization->projects()->orderBy('projects.id')->value('projects.id');
        if ($id === null) {
            return null;
        }

        /** @var Project|null */
        return Project::query()->whereKey($id)->lockForUpdate()->first();
    }
}
