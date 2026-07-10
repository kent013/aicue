<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * チケット台帳 (2 フェーズ消費プリミティブ。docs 07 ガイド §4) + 傾斜単価。
     *
     * - ticket_reservations: reserve → commit / release の予約 (TTL 付き)
     * - ticket_ledger_entries: 残高の真実源。**append-only** (updated_at なし、
     *   Model 側で update/delete を例外化)。残高 = SUM(delta, 未失効) − active 予約
     * - ticket_volume_prices: スポット購入の数量逐減 (volume tier) 単価の
     *   Stripe one-time Price DB snapshot (min_count 軸)
     */
    public function up(): void
    {
        Schema::create('ticket_reservations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->integer('amount');
            // reserved | committed | released (App\Enums\Billing\TicketReservationStatus)
            $table->string('status')->default('reserved');
            // reserve TTL。超過分は billing:release-stale-reservations cron が解放する
            $table->timestamp('expires_at');
            $table->timestamps();

            $table->index(['organization_id', 'status']);
            $table->index(['status', 'expires_at']);
        });

        Schema::create('ticket_ledger_entries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            // 正 = 付与 / 負 = 消費 (commit)・クローバック。release は監査用の 0 行
            $table->integer('delta');
            // grant | reserve_commit | release | adjustment | clawback (App\Enums\Billing\TicketLedgerKind)
            $table->string('kind');
            // 付与系エントリの出所 (monthly | purchased。App\Enums\Billing\TicketSource)。消費・解放行は null
            $table->string('source')->nullable();
            $table->foreignId('reservation_id')->nullable()->constrained('ticket_reservations')->restrictOnDelete();
            $table->string('description');
            // 付与時刻 (付与系エントリのみ。監査用)
            $table->timestamp('granted_at')->nullable();
            // 期限付き付与の失効時刻。null = 無期限。balance() は未失効行のみ合算する
            $table->timestamp('expires_at')->nullable();
            // 買い切り購入の Stripe Checkout Session (grantPurchased の冪等キー由来)
            $table->string('stripe_checkout_session_id')->nullable();
            // 返金逆仕訳 (clawback) の正本キー。charge.refunded の payment_intent と照合する
            $table->string('payment_intent_id')->nullable();
            // 購入時の元決済額 (最小通貨単位)。部分返金の按分分母
            $table->unsignedInteger('purchase_amount')->nullable();
            // 冪等付与のキー (grantMonthly / grantPurchased / clawback)。手動 grant / 消費行は null
            // (UNIQUE は NULL を複数許容するので既存経路と共存できる)
            $table->string('idempotency_key')->nullable()->unique();
            // append-only のため updated_at は持たない
            $table->timestamp('created_at');

            $table->index(['organization_id', 'kind']);
            $table->index('payment_intent_id');
        });

        $this->createTicketVolumePricesTable();
    }

    /**
     * スポット購入の数量逐減 (volume tier) 単価を保持する Stripe one-time Price の DB snapshot。
     * tier 解決は「min_count <= count を満たす最大 min_count の current 行」
     * (TicketVolumePrice::currentTierFor)。min_count=1 の行が spot 単価を兼ねる。
     *
     * plan_prices と同じ Stripe Catalog snapshot 規約 (invariant を DB で機械強制):
     * - `is_current=true ⇔ active_to IS NULL` を CHECK で双方向強制
     * - 「current は min_count (段) あたり 1 行」を生成列 + 部分 UNIQUE で強制
     *   (同一段に複数 current が並ぶ単価の曖昧化を防ぐ。一意軸は lookup_key ではなく min_count)
     * - lookup_key / min_count 単独 UNIQUE は付けない (価格改定の履歴行を複数持てる)
     */
    private function createTicketVolumePricesTable(): void
    {
        $driver = DB::connection()->getDriverName();

        if ($driver === 'sqlite') {
            // sqlite は CREATE 後の ADD CONSTRAINT CHECK 不可のため raw CREATE TABLE で CHECK を焼き込む
            DB::statement(<<<'SQL'
                CREATE TABLE ticket_volume_prices (
                    id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL,
                    lookup_key VARCHAR(255) NOT NULL,
                    stripe_price_id VARCHAR(255) NOT NULL,
                    min_count INTEGER UNSIGNED NOT NULL,
                    unit_amount INTEGER UNSIGNED NOT NULL,
                    currency CHAR(3) NOT NULL DEFAULT 'jpy',
                    nickname VARCHAR(255),
                    livemode TINYINT(1) NOT NULL DEFAULT 0,
                    synced_at DATETIME,
                    active_from DATETIME NOT NULL,
                    active_to DATETIME,
                    is_current TINYINT(1) NOT NULL DEFAULT 0,
                    created_at DATETIME,
                    updated_at DATETIME,
                    CHECK (
                        (is_current = 1 AND active_to IS NULL) OR
                        (is_current = 0 AND active_to IS NOT NULL)
                    )
                )
            SQL);
            DB::statement('CREATE UNIQUE INDEX ticket_volume_prices_stripe_price_id_unique ON ticket_volume_prices (stripe_price_id)');
            DB::statement('CREATE INDEX ticket_volume_prices_min_count_is_current_index ON ticket_volume_prices (min_count, is_current)');
        } else {
            Schema::create('ticket_volume_prices', function (Blueprint $table): void {
                $table->id();
                $table->string('lookup_key'); // 単独 UNIQUE は付けない (履歴行を複数持てる)
                $table->string('stripe_price_id')->unique();
                // この段が適用される最小購入枚数 (min_count=1 の行が spot 単価を兼ねる)
                $table->unsignedInteger('min_count');
                // 単価 (最小通貨単位)。config('billing.ticket_unit_price_floor') 未満は解決時に fail-closed
                $table->unsignedInteger('unit_amount');
                $table->char('currency', 3)->default('jpy');
                $table->string('nickname')->nullable();
                $table->boolean('livemode')->default(false);
                $table->timestamp('synced_at')->nullable();
                $table->timestamp('active_from');
                $table->timestamp('active_to')->nullable();
                $table->boolean('is_current')->default(false);
                $table->timestamps();

                $table->index(['min_count', 'is_current']);
            });

            DB::statement(<<<'SQL'
                ALTER TABLE ticket_volume_prices
                ADD CONSTRAINT ticket_volume_prices_is_current_active_to_check
                CHECK (
                    (is_current = true AND active_to IS NULL) OR
                    (is_current = false AND active_to IS NOT NULL)
                )
            SQL);
        }

        // 「current は min_count あたり 1 行」を生成列 + 部分 UNIQUE で強制 (driver 別構文)
        $generated = match ($driver) {
            'pgsql' => <<<'SQL'
                ALTER TABLE ticket_volume_prices
                ADD COLUMN current_unique_key INTEGER
                GENERATED ALWAYS AS (CASE WHEN is_current THEN min_count ELSE NULL END) STORED
            SQL,
            // mysql / mariadb / sqlite は ADD COLUMN で VIRTUAL 生成列を追加できる
            default => <<<'SQL'
                ALTER TABLE ticket_volume_prices
                ADD COLUMN current_unique_key INTEGER
                GENERATED ALWAYS AS (CASE WHEN is_current = 1 THEN min_count ELSE NULL END) VIRTUAL
            SQL,
        };
        DB::statement($generated);
        DB::statement('CREATE UNIQUE INDEX ticket_volume_prices_current_unique ON ticket_volume_prices (current_unique_key)');
    }

    public function down(): void
    {
        Schema::dropIfExists('ticket_volume_prices');
        Schema::dropIfExists('ticket_ledger_entries');
        Schema::dropIfExists('ticket_reservations');
    }
};
