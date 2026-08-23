<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * 初版の予約語と既存の識別名が衝突しないことを検査する (fail-closed。家系裁定 AG-039)。
 *
 * ★**予約語のスナップショットを migration 内に固定する**。可変の config を読むと、
 *   将来 config に語を足したときに**過去の migration の意味が変わる** (再実行や
 *   新規環境の構築で、当時は通った migration が落ちる)。
 * ★将来の追加は「同じ変更に新しい migration を足す」運用契約が担う
 *   (正本は `config/organization-slug-reserved.php` の冒頭 docblock)。
 * ★正規化 (小文字化) は 000100 が済ませている前提。ここは検査だけで更新しない。
 */
return new class extends Migration
{
    /** @var list<string> 初版 (2026-08-23) の予約語スナップショット。config を読まない。 */
    private const array RESERVED_AT_INTRODUCTION = [
        'create',
        'admin',
        'administrator',
        'root',
        'staff',
        'support',
        'system',
        'official',
        'www',
        'api',
        'null',
        'undefined',
    ];

    public function up(): void
    {
        $offenders = DB::table('organizations')
            ->whereIn(DB::raw('lower(slug)'), self::RESERVED_AT_INTRODUCTION)
            ->pluck('slug');

        if ($offenders->isNotEmpty()) {
            throw new RuntimeException(
                '予約語と同じ識別名の組織がある。改名してから再実行すること: '.$offenders->implode(', '),
            );
        }
    }

    public function down(): void
    {
        // 検査のみの migration なので巻き戻す状態を持たない。
    }
};
