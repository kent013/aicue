<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Query\Builder;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * T142 (標準形 v1 / 裁定 AG-128 の必須 (2)): 猶予期間つき退会 (**凍結方式**) の予約列。
 *
 * ★**SoftDeletes は使わない**。users 行の生死を変えないのが凍結方式の定義で、
 *   FK cascade / nullOnDelete / CipherSweet の blind index (email_index) の一意照合 /
 *   passkey / OAuth セッション / 招待の email 照合が、すべて users 行の実在を前提にしている。
 *
 * ★`deletion_purge_after` は **絶対時刻**で持つ (猶予日数のスナップショットにしない)。
 *   不可逆な物理削除のため config 変更を既予約へ遡及させてはならず、絶対時刻なら 1 列で
 *   それが表現でき、日次バッチのクエリも `deletion_purge_after <= now()` の 1 条件で済む。
 *   猶予日数は `purge_after - requested_at` から導出する (2 つの表現を持たない)。
 *
 * ★**状態機械を DB で閉じる**。片列だけの非正規状態になると `isPending()` が false になり、
 *   凍結を通過し、`cancelAccountDeletion()` も no-op で解消できず、日次バッチが毎日
 *   FAILURE を出し続ける (検出はできても解消できない)。アプリ層だけでなく CHECK 制約で防ぐ。
 */
return new class extends Migration
{
    private const string PAIR_CONSTRAINT = 'users_deletion_request_pair_check';

    private const string ORDER_CONSTRAINT = 'users_deletion_purge_after_order_check';

    public function up(): void
    {
        // precondition 検査 (非破壊 = SELECT のみ)。新規列なので理論上 0 件だが、
        // 「制約追加 migration は既存データを壊しうる」という一般則に従って明示する。
        // 列がまだ無い時点では検査不能なので、列追加の**後**・制約追加の**前**に置く。
        Schema::table('users', function (Blueprint $table): void {
            $table->timestamp('deletion_requested_at')->nullable()->after('remember_token');
            $table->timestamp('deletion_purge_after')->nullable()->after('deletion_requested_at');
            // 日次バッチの走査条件 (deletion_purge_after <= now())。
            // 部分 index (WHERE NOT NULL) は pgsql 固有の書き方になるため、まず素の index で入れる
            // (予約中ユーザーは常に極少数。性能問題が出てから絞る = 思考原則 2)。
            $table->index('deletion_purge_after');
        });

        $nonNormalized = DB::table('users')
            ->where(function (Builder $query): void {
                $query->whereNull('deletion_requested_at')->whereNotNull('deletion_purge_after');
            })
            ->orWhere(function (Builder $query): void {
                $query->whereNotNull('deletion_requested_at')->whereNull('deletion_purge_after');
            })
            ->orWhereColumn('deletion_purge_after', '<', 'deletion_requested_at')
            ->count();
        if ($nonNormalized > 0) {
            // 件数だけを出す (user id / email は出さない = PII 非出力)。
            throw new RuntimeException(
                "退会予約列が非正規な行が既に存在するため CHECK 制約を張れません: count={$nonNormalized}",
            );
        }

        DB::statement(
            'ALTER TABLE users ADD CONSTRAINT '.self::PAIR_CONSTRAINT.' CHECK ('
            .'(deletion_requested_at IS NULL AND deletion_purge_after IS NULL)'
            .' OR (deletion_requested_at IS NOT NULL AND deletion_purge_after IS NOT NULL))',
        );
        // 両列 non-null だが期限が予約時刻より前、という別の非正規状態も防ぐ
        DB::statement(
            'ALTER TABLE users ADD CONSTRAINT '.self::ORDER_CONSTRAINT.' CHECK ('
            .'deletion_purge_after IS NULL OR deletion_purge_after >= deletion_requested_at)',
        );
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE users DROP CONSTRAINT IF EXISTS '.self::ORDER_CONSTRAINT);
        DB::statement('ALTER TABLE users DROP CONSTRAINT IF EXISTS '.self::PAIR_CONSTRAINT);

        Schema::table('users', function (Blueprint $table): void {
            $table->dropIndex(['deletion_purge_after']);
            $table->dropColumn(['deletion_requested_at', 'deletion_purge_after']);
        });
    }
};
