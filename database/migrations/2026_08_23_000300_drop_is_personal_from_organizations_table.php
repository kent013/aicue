<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 個人組織の**種別フラグを撤去する** (家系裁定 AG-038 / 不変条件 I3)。
 *
 * ★正典は「個人組織を種別として区別しない」と定める。初期組織生成の冪等判定は
 *   種別フラグではなく「**所属組織が 0 件か**」で行う (I4。
 *   `OrganizationProvisioningService::provisionInitialOrganization()`)。
 * ★列の値は判定にも課金にも使われていない (`plan.code === 'personal'` は別概念)。
 *   したがって撤去にデータ移送は要らない。
 * ★down は列を戻すだけで、**値は復元しない** (種別の概念そのものが無くなるため、
 *   戻す先の真値が存在しない)。
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('organizations', function (Blueprint $table): void {
            $table->dropColumn('is_personal');
        });
    }

    public function down(): void
    {
        Schema::table('organizations', function (Blueprint $table): void {
            $table->boolean('is_personal')->default(false);
        });
    }
};
