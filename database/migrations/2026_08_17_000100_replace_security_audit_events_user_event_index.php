<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * security_audit_events の複合索引を occurred_at まで伸ばす (**列は足さない**)。
 *
 * 用途: /manage/users の最終ログイン表示が
 *   select user_id, max(occurred_at) … where user_id in (…) and event_type = 'login' group by user_id
 * を撃つ。既存の ['user_id','event_type'] には集約対象の occurred_at が含まれないため、
 * 選択された実行計画では heap から値を取得する必要がある
 * (どの走査を選ぶかは統計情報しだいなので、実行計画を断定しない)。occurred_at まで索引に含めると
 * **集約に必要な値を索引から供給でき、heap 参照を減らせる (index-only scan の候補になる)**。
 *
 * ⚠ **計算量が定数になるわけではない**。group by は原則としてその利用者の login エントリを
 * 走査するため、履歴件数に対しては依然として線形である。「最大値の取得に効く」とは書かない。
 * 最新 1 件だけを索引順で取る形 (DISTINCT ON / LATERAL) が要るほど遅くなったら、
 * そのときに実測 (EXPLAIN ANALYZE, BUFFERS) を根拠に導出方式ごと設計し直す。
 * 先回りして今は導入しない (思考原則 2)。
 *
 * **追加ではなく置き換え**である。新索引は先頭 2 列が旧索引と同じなので、
 * `user_id, event_type` の**前方一致クエリでは代替できる** (B-tree の左端一致)。
 * 「旧索引の全用途を保証する」とは書かない (誇張しない)。並走を残さない (AGENTS.md 思考原則 3)。
 *
 * ⚠ **この migration は短時間の書き込み停止を許容する**。
 * pgsql の CREATE INDEX (非 CONCURRENTLY) は対象表に SHARE lock を取り、索引構築の間
 * INSERT を止める。本表へ INSERT するのは認証経路 (ログイン / ログアウト / ログイン失敗) なので、
 * **構築中はログインが待たされる**。現行の行数では体感できない長さだが、
 * **低トラフィックの時間帯に実行すること**。無停止が要件になった場合の作り直し方は
 * devnotes/20260817-0909-user-last-login-at/detailed-design.md の施策 D。
 *
 * ⚠ **rollback (down) も同じ SHARE lock を取る**。切り戻しも同じ条件で実行すること。
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('security_audit_events', function (Blueprint $table): void {
            // 先に新索引を張ってから旧索引を落とす (索引が 1 本も無い瞬間を作らない)。
            // 既定命名なので新索引は security_audit_events_user_id_event_type_occurred_at_index。
            $table->index(['user_id', 'event_type', 'occurred_at']);
            // 旧索引 security_audit_events_user_id_event_type_index を落とす。
            // 張った側 (2026_06_11_071300_create_security_audit_events_table.php) も配列指定なので
            // 既定命名で一致する。名前を直書きせず配列で指定する (2 か所に同じ文字列を持たない)
            $table->dropIndex(['user_id', 'event_type']);
        });
    }

    public function down(): void
    {
        Schema::table('security_audit_events', function (Blueprint $table): void {
            $table->index(['user_id', 'event_type']);
            $table->dropIndex(['user_id', 'event_type', 'occurred_at']);
        });
    }
};
