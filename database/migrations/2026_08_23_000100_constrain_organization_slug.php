<?php

declare(strict_types=1);

use App\Exceptions\Organization\InvalidOrganizationSlugException;
use App\Support\Organization\OrganizationSlug;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * 組織識別名を正規化し、**構文** を DB の CHECK 制約で固定する (家系裁定 AG-039b / AG-039c)。
 *
 * ★責務の分割: 000100 = 正規化・構文・衝突 / 000200 = 予約語。
 *   予約語は設定ファイルで増減するものなので DDL に焼かない (二重管理になる)。
 * ★既存データに構文違反・正規化後の衝突があれば **fail-closed で止める** (意図した挙動)。
 *   運用で解消してから再実行すること。
 * ★UNIQUE は **既存の organizations_slug_unique をそのまま使う**
 *   (create migration の `$table->string('slug')->unique()` で既に在る)。再追加しない。
 *
 * **PostgreSQL の `~` と PHP の `\A...\z` の対応**: PostgreSQL の `^`/`$` は既定で
 * 文字列全体の先頭末尾に一致する (`n` フラグを付けない限り改行を行境界として扱わない)。
 * かつ slug に改行が入る余地は CHECK 自体が塞ぐ。したがって PHP 側 `\A\z` /
 * DB 側 `^$` という非対称でも判定は一致する。
 *
 * ★本 migration の直接 UPDATE は `OrganizationSlugWritePathTest` の例外目録
 *   (`OrganizationSlugWriteExemptions`) に rule ID `raw-sql-update` / 件数 1 で登録済み。
 *   値オブジェクト導入**前**の既存行を正規化する一度きりの処理であり、型を通せる対象が
 *   まだ存在しないため。
 */
return new class extends Migration
{
    /** CHECK 制約の名前 (down で名指しで落とすため定数化する)。 */
    private const string SYNTAX_CONSTRAINT = 'organizations_slug_syntax';

    public function up(): void
    {
        // 1. 更新せずに、正規化後の値が **構文** を満たすかを全行検査する
        //    (小文字化だけでは I6 の文字種・先頭末尾/連続ハイフンを守れない)
        $violations = DB::table('organizations')
            ->select('id', 'slug')
            ->get()
            ->filter(function (object $row): bool {
                $slug = $row->slug;

                // 列は NOT NULL の varchar なので文字列以外は来ない。来たら構文違反として扱う
                // (無言で素通しさせない = fail-closed)。
                return ! is_string($slug) || ! self::normalizesToValidSlug($slug);
            });

        // 2. 正規化後に衝突する行を検査する
        $collisions = DB::table('organizations')
            ->selectRaw('lower(slug) as normalized, count(*) as c')
            ->groupBy('normalized')
            ->havingRaw('count(*) > 1')
            ->pluck('normalized');

        if ($violations->isNotEmpty() || $collisions->isNotEmpty()) {
            throw new RuntimeException(
                '識別名の正規化に失敗する組織がある。運用で解消してから再実行すること。'
                .' 構文違反: '.$violations->pluck('id')->implode(', ')
                .' / 衝突: '.$collisions->implode(', '),
            );
        }

        // 3. 検査を通った場合だけ既存値を小文字化する
        DB::statement('UPDATE organizations SET slug = lower(slug) WHERE slug <> lower(slug)');

        // 4. CHECK を付与する (Schema Builder に CHECK の抽象は無いので生 DDL)
        DB::statement(
            'ALTER TABLE organizations ADD CONSTRAINT '.self::SYNTAX_CONSTRAINT
            ." CHECK (slug ~ '^[a-z0-9]+(-[a-z0-9]+)*$' AND length(slug) <= ".OrganizationSlug::MAX_LENGTH.')'
        );
    }

    public function down(): void
    {
        // ★CHECK は名前で落とす。既存の organizations_slug_unique は本 migration が
        //   作っていないので触らない。
        DB::statement('ALTER TABLE organizations DROP CONSTRAINT IF EXISTS '.self::SYNTAX_CONSTRAINT);
    }

    /** 正規化後に構文型を作れるか (作れなければ運用で直す必要がある行)。 */
    private static function normalizesToValidSlug(string $slug): bool
    {
        try {
            OrganizationSlug::fromString($slug);

            return true;
        } catch (InvalidOrganizationSlugException) {
            return false;
        }
    }
};
