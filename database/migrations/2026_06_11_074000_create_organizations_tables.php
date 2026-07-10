<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * 組織 3 階層 (Organization → CustomTeam → Project) + メンバーシップ。
     *
     * - organizations.laratrust_team_id: 認可スコープ (Laratrust team) と 1:1。
     *   restrictOnDelete で孤児化を防ぐ (組織削除時は service が team も処理する)
     * - custom_teams.is_default: Default Team パターン (docs/default-team-pattern.md)。
     *   「どの組織にも Default Team がちょうど 1 つ」を部分 unique index で強制
     * - projects は custom_team 配下固定 (NOT NULL cascade)
     */
    public function up(): void
    {
        Schema::create('organizations', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('slug')->unique();
            $table->foreignId('laratrust_team_id')->unique()->constrained('teams')->restrictOnDelete();
            $table->boolean('is_personal')->default(false);
            // 組織単位の 2FA 必須方針 (Owner のみ変更可。fillable 外 = forceFill で明示代入)
            $table->boolean('two_factor_required')->default(false);
            $table->softDeletes();
            $table->timestamps();
        });

        Schema::create('organization_user', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->timestamps();

            $table->unique(['organization_id', 'user_id']);
        });

        Schema::create('custom_teams', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->boolean('is_default')->default(false);
            $table->timestamps();
        });

        // 部分 unique: 組織ごとに is_default=true は 1 行のみ (PostgreSQL / SQLite 対応)
        // MySQL は部分 index 非対応のため、アプリ層 (provisioning service) + Feature テストで担保する
        $driver = Schema::getConnection()->getDriverName();
        if (in_array($driver, ['pgsql', 'sqlite'], true)) {
            Schema::getConnection()->statement(
                'CREATE UNIQUE INDEX custom_teams_one_default_per_org ON custom_teams (organization_id) WHERE is_default',
            );
        }

        Schema::create('projects', function (Blueprint $table) {
            $table->id();
            $table->foreignId('custom_team_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->text('description')->nullable();
            $table->timestamps();
        });

        Schema::create('project_members', function (Blueprint $table) {
            $table->id();
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('role');
            $table->timestamps();

            $table->unique(['project_id', 'user_id']);
        });

        Schema::table('users', function (Blueprint $table) {
            $table->foreignId('current_organization_id')->nullable()
                ->constrained('organizations')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropConstrainedForeignId('current_organization_id');
        });
        Schema::dropIfExists('project_members');
        Schema::dropIfExists('projects');
        Schema::dropIfExists('custom_teams');
        Schema::dropIfExists('organization_user');
        Schema::dropIfExists('organizations');
    }
};
