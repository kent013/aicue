<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * プラン定義 (PlanSeeder が真実源)。
     *
     * - plans.code: プランの機械可読 ID。コードにプラン名で分岐を書かない規約のため、
     *   能力は monthly_ticket_grant / config/quota.php の limits の「値」で表現する
     * - plan_prices: Stripe Price の DB snapshot (runtime は本テーブルのみ参照)。
     *   書き込みは bootstrap seeder / `billing:sync-stripe-prices` のみ。
     *   invariant (DB CHECK + 生成列 partial UNIQUE で強制):
     *   - `is_current=true ⇔ active_to IS NULL`
     *   - current は plan×kind (base|seat) あたり 1 行
     *
     * 前提: Laravel table prefix 未使用 (raw SQL のテーブル名は素のまま)。
     */
    public function up(): void
    {
        Schema::create('plans', function (Blueprint $table) {
            $table->id();
            $table->string('code')->unique();
            $table->string('name');
            // 月次チケット付与数 (invoice.paid 受信時に grant)
            $table->integer('monthly_ticket_grant')->default(0);
            $table->integer('sort_order')->default(0);
            $table->timestamps();
        });

        $driver = DB::connection()->getDriverName();

        // SQLite は ALTER TABLE ADD CONSTRAINT CHECK 非対応のため CREATE TABLE 時に含める。
        if ($driver === 'sqlite') {
            DB::statement(<<<'SQL'
                CREATE TABLE plan_prices (
                    id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL,
                    plan_id INTEGER UNSIGNED NOT NULL,
                    kind VARCHAR(16) NOT NULL,
                    stripe_price_id VARCHAR(255) NOT NULL,
                    lookup_key VARCHAR(255),
                    amount INTEGER UNSIGNED NOT NULL,
                    currency CHAR(3) NOT NULL DEFAULT 'jpy',
                    livemode TINYINT(1) NOT NULL DEFAULT 0,
                    synced_at DATETIME,
                    active_from DATETIME NOT NULL,
                    active_to DATETIME,
                    is_current TINYINT(1) NOT NULL DEFAULT 0,
                    created_at DATETIME,
                    updated_at DATETIME,
                    FOREIGN KEY (plan_id) REFERENCES plans(id) ON DELETE CASCADE,
                    CHECK (
                        (is_current = 1 AND active_to IS NULL) OR
                        (is_current = 0 AND active_to IS NOT NULL)
                    )
                )
            SQL);
            DB::statement('CREATE UNIQUE INDEX plan_prices_stripe_price_id_unique ON plan_prices (stripe_price_id)');
            DB::statement('CREATE INDEX plan_prices_plan_id_kind_is_current_index ON plan_prices (plan_id, kind, is_current)');
        } else {
            Schema::create('plan_prices', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('plan_id')->constrained()->cascadeOnDelete();
                $table->string('kind', 16);
                $table->string('stripe_price_id')->unique();
                $table->string('lookup_key')->nullable();
                // JPY (ゼロ十進通貨) 前提では unit_amount をそのまま円として扱う
                $table->unsignedInteger('amount');
                $table->char('currency', 3)->default('jpy');
                $table->boolean('livemode')->default(false);
                $table->timestamp('synced_at')->nullable();
                $table->timestamp('active_from');
                $table->timestamp('active_to')->nullable();
                $table->boolean('is_current')->default(false);
                $table->timestamps();

                $table->index(['plan_id', 'kind', 'is_current']);
            });

            DB::statement(<<<'SQL'
                ALTER TABLE plan_prices
                ADD CONSTRAINT plan_prices_is_current_active_to_check
                CHECK (
                    (is_current = true AND active_to IS NULL) OR
                    (is_current = false AND active_to IS NOT NULL)
                )
            SQL);
        }

        // 「current は plan×kind あたり 1 行」を生成列 + 部分 UNIQUE で機械強制。
        if ($driver === 'pgsql') {
            DB::statement(<<<'SQL'
                ALTER TABLE plan_prices
                ADD COLUMN current_unique_key TEXT
                GENERATED ALWAYS AS (
                    CASE WHEN is_current THEN (plan_id::text || ':' || kind) ELSE NULL END
                ) STORED
            SQL);
            DB::statement('CREATE UNIQUE INDEX plan_prices_current_unique ON plan_prices (current_unique_key)');

            return;
        }

        if ($driver === 'mysql' || $driver === 'mariadb') {
            DB::statement(<<<'SQL'
                ALTER TABLE plan_prices
                ADD COLUMN current_unique_key VARCHAR(64)
                GENERATED ALWAYS AS (
                    CASE WHEN is_current = 1 THEN CONCAT(plan_id, ':', kind) ELSE NULL END
                ) VIRTUAL
            SQL);
            DB::statement('CREATE UNIQUE INDEX plan_prices_current_unique ON plan_prices (current_unique_key)');

            return;
        }

        // sqlite
        DB::statement(<<<'SQL'
            ALTER TABLE plan_prices
            ADD COLUMN current_unique_key TEXT
            GENERATED ALWAYS AS (
                CASE WHEN is_current = 1 THEN (plan_id || ':' || kind) ELSE NULL END
            ) VIRTUAL
        SQL);
        DB::statement('CREATE UNIQUE INDEX plan_prices_current_unique ON plan_prices (current_unique_key)');
    }

    public function down(): void
    {
        Schema::dropIfExists('plan_prices');
        Schema::dropIfExists('plans');
    }
};
