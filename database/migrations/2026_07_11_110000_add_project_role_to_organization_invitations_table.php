<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('organization_invitations', function (Blueprint $table): void {
            // 受諾時に Default Project へ付与する pivot ロール (ProjectRole 値)。
            // null = org 参加のみ (管理者招待 / 旧招待)。値はサーバが AdminConsoleRole から導出し、
            // クライアント payload からは受けない (forceFill 専用)
            $table->string('project_role')->nullable()->after('role');
        });
        // 許容値を DB 層でも固定 (手動更新・バッチ経由の不正値混入を構造的に拒否)
        DB::statement(
            'alter table organization_invitations add constraint organization_invitations_project_role_check '
            ."check (project_role is null or project_role in ('project_admin', 'project_member'))",
        );
    }

    public function down(): void
    {
        DB::statement('alter table organization_invitations drop constraint if exists organization_invitations_project_role_check');
        Schema::table('organization_invitations', function (Blueprint $table): void {
            $table->dropColumn('project_role');
        });
    }
};
