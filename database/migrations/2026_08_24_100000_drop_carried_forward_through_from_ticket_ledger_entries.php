<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 繰越行の集約終端を表す専用列 `carried_forward_through` を落とす。
 *
 * 正典 v1 (二段判定・収束繰越形) では**繰越行の `created_at` が集約の基準時刻**であり
 * (畳み込んだ行の最大 `created_at`)、集約単位ごとに 1 行へ収束するため、
 * 終端を別列で単調前進させる必要が無くなった。書き手のいない列を残さない
 * (AGENTS.md 思考原則 3「後方互換の並走を残さない」)。
 *
 * ★**列を足した migration (2026_08_10_114500) は消さない**。消すと新規環境で
 *   この drop が失敗する (schema の歴史は歴史として残す)。
 * ★**破壊条件の要約 (この 2 行だけをここに置く)**:
 *   **コード先行が必須**である (drop 先行にすると、まだ動いている旧コードの
 *   `MAX(carried_forward_through)` の集計と繰越行の INSERT が `Undefined column` で落ちる)。
 *   **drop 後に旧コードへ単純 rollback できない**。
 *   → **手順・rollback・maintenance window の判断の正本は
 *   `docs/billing-retention-runbook.md` の「`carried_forward_through` 撤去のデプロイ順序」節**である。
 *   ここに手順を写さない (2 か所に書くと必ず食い違う)。
 * ★**v0 形の繰越行のデータ移行は置かない**。台帳表を作った migration は
 *   `2026_06_11_091400` で保持期限は 7 年なので、**通常のアプリ経路では**
 *   v0 の畳み込みが繰越行を作れるのは 2033-06-11 以降である。
 *   手動投入・DB 復元は保証外で、限界と自己修復の説明は runbook が正本である
 *   (「`carried_forward_through` 撤去のデプロイ順序」節)。
 * ★`down()` は列を戻すだけで**値は復元しない** (新形の繰越行は集約終端を `created_at` で
 *   表すので、復元すると嘘の値になる)。旧コードを再稼働させると既存の繰越行は
 *   「終端が未記録 (null)」として扱われる。
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ticket_ledger_entries', function (Blueprint $table): void {
            $table->dropColumn('carried_forward_through');
        });
    }

    public function down(): void
    {
        Schema::table('ticket_ledger_entries', function (Blueprint $table): void {
            // 値は復元しない (旧形の意味を持つ値を作れないため、すべて null で戻す)
            $table->timestamp('carried_forward_through')->nullable()->after('expires_at');
        });
    }
};
