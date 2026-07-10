<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * blind_indexes の email 一意制約 (partial unique index)。
 *
 * users.email は暗号化カラムのため平文 unique が張れない。email の一意性は
 * blind index 側の unique で DB 層でも担保する (アプリ層の UniqueEncryptedEmail /
 * whereBlind 明示チェックとの 2 層防御。INSERT race はここで止まる)。
 *
 * blanket UNIQUE(indexable_type, name, value) にしない理由: 非ユニークな blind index
 * (例: OrganizationInvitation.email は「同一 email を別組織へ複数招待」が正当) の
 * 2 件目を誤って弾く regression になるため、name = 'email_index' かつ email 一意が
 * 必要な型 (User / AdminUser) に限定した partial unique index を張る。
 *
 * 前提: morphMap 未設定で indexable_type は FQCN。将来 morphMap を導入する場合は
 * 本 index の WHERE / 生成列 CASE を見直すこと。FQCN は migration 履歴の固定値として
 * リテラルで埋め込む (runtime 定数に依存しない)。検索 (whereBlind) は基底
 * index(['name','value']) が担保するため、一意制約による検索性能の後退はない。
 *
 * driver 別実装: pgsql / sqlite は WHERE 付き partial unique index を直接張る。
 * MySQL / MariaDB は partial index 非対応のため、対象行のみ (indexable_type ':' value) を
 * 写す生成列 email_unique_key を作り、その列の通常 UNIQUE index で同じ不変条件を強制する
 * (NULL は UNIQUE で衝突しないため対象外行は無制約)。plan_prices / ticket_volume_prices の
 * 「生成列 + 部分 UNIQUE」焼き分けと同じ方針で 4 ドライバを揃える。
 */
return new class extends Migration
{
    public function up(): void
    {
        $driver = DB::connection()->getDriverName();

        // MySQL / MariaDB は partial (filtered / WHERE 付き) index 非対応のため、同じ
        // 不変条件を「生成列 + 通常 UNIQUE」で焼き分ける。email 一意が必要な型のときだけ
        // (indexable_type ':' value) を写す生成列を作り、その列に UNIQUE index を張る。
        // NULL は UNIQUE で衝突しないため、対象外の行 (非 email_index / 他型) は無制約のまま。
        // pgsql partial index の (indexable_type, name, value) uniqueness と等価
        // (name は WHERE で email_index 固定のため indexable_type と value のみ効く)。
        if ($driver === 'mysql' || $driver === 'mariadb') {
            // MySQL の文字列リテラルは既定でバックスラッシュをエスケープ文字として扱うため、
            // FQCN の区切り \ は \\ で表記する (pgsql / sqlite は標準どおりリテラル)。
            DB::statement(<<<'SQL'
                ALTER TABLE blind_indexes
                ADD COLUMN email_unique_key VARCHAR(512)
                GENERATED ALWAYS AS (
                    CASE WHEN name = 'email_index'
                              AND indexable_type IN ('App\\Models\\User', 'App\\Models\\AdminUser')
                         THEN CONCAT(indexable_type, ':', value) ELSE NULL END
                ) VIRTUAL
            SQL);
            DB::statement('CREATE UNIQUE INDEX blind_indexes_type_name_value_unique ON blind_indexes (email_unique_key)');

            return;
        }

        // pgsql / sqlite は partial unique index をそのまま張る。email 一意の型を増やす場合は
        // IN (...) に FQCN を追加する (mysql/mariadb 側の生成列 CASE も揃えること)。
        DB::statement(<<<'SQL'
            CREATE UNIQUE INDEX blind_indexes_type_name_value_unique
                ON blind_indexes (indexable_type, name, value)
                WHERE name = 'email_index'
                  AND indexable_type IN ('App\Models\User', 'App\Models\AdminUser')
            SQL);
    }

    public function down(): void
    {
        $driver = DB::connection()->getDriverName();

        // mysql / mariadb は生成列を落とせば付随する UNIQUE index も消える。
        if ($driver === 'mysql' || $driver === 'mariadb') {
            if (Schema::hasColumn('blind_indexes', 'email_unique_key')) {
                Schema::table('blind_indexes', function (Blueprint $table): void {
                    $table->dropColumn('email_unique_key');
                });
            }

            return;
        }

        // pgsql / sqlite は raw CREATE で作った partial index を drop する。
        DB::statement('DROP INDEX IF EXISTS blind_indexes_type_name_value_unique');
    }
};
