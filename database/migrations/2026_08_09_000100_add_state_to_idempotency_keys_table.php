<?php

declare(strict_types=1);

use App\Enums\Idempotency\IdempotencyState;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * 冪等キーに状態列を足し、実行**前** claim を可能にする。
 *
 * - `state`: processing / completed / indeterminate (App\Enums\Idempotency\IdempotencyState)
 * - `response_status` を nullable 化する (claim 時点ではまだ応答が無いため)
 *
 * **既存行は削除せず `completed` へ backfill する**。現行実装は 2xx の JsonResponse しか
 * 保存しない (旧 IdempotentRequest::handle の `$response->isSuccessful()` 分岐) ため、
 * 既存行の決着は構造上すべて「成功」で既知である。ここを indeterminate に倒すと
 * デプロイ直後の正当な再送 (成功の再生) が最大 24h ぶん 409 に化ける。
 * 既存行を **削除**しないのは、デプロイを跨いだ再送が二重実行になるのを防ぐため。
 *
 * 既存の unique 2 本 (api_key_id / user_id の NULL distinct 前提) には触らない。
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('idempotency_keys', function (Blueprint $table): void {
            $table->string('state', 24)->nullable()->after('request_hash');
            $table->unsignedSmallInteger('response_status')->nullable()->change();
        });

        // 既存行の backfill (決着は既知 = completed)
        DB::table('idempotency_keys')
            ->whereNull('state')
            ->update(['state' => IdempotencyState::Completed->value]);

        // backfill 後に NOT NULL 化する。**DB default は付けない**
        // (default があると「state を書き忘れた INSERT」が黙って completed になる)
        Schema::table('idempotency_keys', function (Blueprint $table): void {
            $table->string('state', 24)->nullable(false)->change();
        });

        // 期限切れ行の state 別 prune を index で支える
        // (prune は `where state = ? and expires_at <= ?` で回す)
        Schema::table('idempotency_keys', function (Blueprint $table): void {
            $table->index(['state', 'expires_at'], 'idempotency_keys_state_expires_at_index');
        });
    }

    public function down(): void
    {
        Schema::table('idempotency_keys', function (Blueprint $table): void {
            $table->dropIndex('idempotency_keys_state_expires_at_index');
            $table->dropColumn('state');
        });

        // down では response_status を NOT NULL に戻さない:
        // 戻す時点で claim 行 (response_status = null) が残っていると ALTER が失敗する。
        // ロールバックの安全性を優先し、nullable のままにする (前方互換)。
        //
        // ⚠ **この migration は実質 irreversible である**。down() はスキーマを
        //    「state 無し / response_status nullable」に戻すだけで、旧コードが前提とする
        //    「全行が完了応答を持つ」状態には戻せない (processing / indeterminate 行が
        //    response_status = null のまま残り、旧 replayResponse が null status で壊れる)。
        //    **旧コードへ戻す前に `DELETE FROM idempotency_keys WHERE response_status IS NULL`
        //    を人手で実行する**こと (削除しても失うのは未確定の claim だけで、
        //    再送は再実行になる = ロールバック時点では旧契約と同じ挙動)。
        //    手順は docs/api-idempotency.md の「ロールバック手順」節が正本。
    }
};
