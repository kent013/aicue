<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * 役割付き招待の撤去 (裁定 AG-079。Default Project という概念自体が不要という
     * オーナー判断の帰結)。招待は「組織に入れる」ことだけを意味するようになり、
     * 編集者 / 撮影者の割当は参加後に管理画面のロール割当コマンドで行う。
     *
     * ★デプロイ順序が安全境界 (expand/contract の contract 側):
     *   1. project_role を読み書きしないコードを先にデプロイする
     *   2. 旧プロセスが残っていないことを確認する (queue:restart / web worker 入替完了)
     *   3. 本 migration を流す
     *   列を先に消すと旧コードの inviteMember が存在しない列へ INSERT して 500 になる。
     */
    public function up(): void
    {
        DB::statement('alter table organization_invitations drop constraint if exists organization_invitations_project_role_check');
        Schema::table('organization_invitations', function (Blueprint $table): void {
            $table->dropColumn('project_role');
        });
    }

    /**
     * 列と check 制約は復元できるが**値は復元できない** (不可逆)。
     * 値を失った pending 招待は「参加後に管理画面でロールを割り当てる」運用に倒れるだけで、
     * 参加そのものは成功する (joinOrganization は org 参加とロール付与だけを行う)。
     */
    public function down(): void
    {
        Schema::table('organization_invitations', function (Blueprint $table): void {
            $table->string('project_role')->nullable()->after('role');
        });
        DB::statement(
            'alter table organization_invitations add constraint organization_invitations_project_role_check '
            ."check (project_role is null or project_role in ('project_admin', 'project_member'))",
        );
    }
};
