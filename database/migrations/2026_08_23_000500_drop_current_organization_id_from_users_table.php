<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 「いまどの組織か」の**保持列を撤去する** (家系裁定 AG-037 / 不変条件 I2)。
 *
 * ★正典は「組織文脈は **URL だけ**で決まる。保持列と切替 endpoint は存在してはならない
 *   (2 方式の併存不可)」と定める。列を残したまま URL 方式を足すと、その状態自体が裁定違反になる。
 * ★**ローリングデプロイ非互換**である (旧アプリは列を読むため、列を落とすと 500 になる)。
 *   切替はメンテナンス前提で、手順は次のとおり:
 *     1. メンテナンスモードに入れる (`dep artisan:down`)
 *     2. 新コードをデプロイ + migrate (`dep deploy`)
 *     3. メンテナンスモードを解除 (`dep artisan:up`)
 *   経路キャッシュは**毎デプロイ再生成する** (AGENTS.md 「運用要件 (route:cache)」。
 *   本 migration を書いた時点では「経路キャッシュを打たない」契約だったが、
 *   2026-08-26 に家系正典の実行点方式へ移行して再生成が前提条件になった)。
 *   メンテナンスモードを挟む判断そのものは機械強制されておらず、**人手で守られる**。
 * ★down は列と FK を戻すだけで、**値は復元しない** (概念そのものが無くなるため)。
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            // FK → index → 列 の順に落とす (dropConstrainedForeignId が FK と index を面倒見る)
            $table->dropConstrainedForeignId('current_organization_id');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->foreignId('current_organization_id')->nullable()
                ->constrained('organizations')->nullOnDelete();
        });
    }
};
