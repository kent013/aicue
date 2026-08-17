<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * cuts.video_manual_id へ索引を足す。
     *
     * **PostgreSQL は FK 列に索引を自動生成しない** (MySQL/InnoDB とは異なる)。
     * 元の create migration は foreignId()->constrained() だけで索引を宣言していないため、
     * cuts を video_manual_id で引く経路がすべて逐次走査になっていた。
     *
     * 効く経路は本改善のカット本文検索 (相関 EXISTS) だけではない:
     * 撮影 PWA 一覧の withCount(['cuts', ...]) は**行ごとに** cuts への相関副問い合わせを
     * 出しており、索引が無いと cuts 全走査 × 表示行数になる。
     * シナリオ編集・レンダリングの cuts 取得、manual 削除時の cascade も同様。
     *
     * `%語%` の LIKE 自体には B-tree 索引は効かない (前方一致でないため)。
     * 本索引が支えるのは**相関 nested-loop 計画のときの cuts 取得**である。
     * pg_trgm + GIN は導入しない (拡張の導入は運用権限と運用負担を増やす。
     * 引き金は devnotes の概念設計に Conditional として記録した)。
     */
    public function up(): void
    {
        Schema::table('cuts', function (Blueprint $table): void {
            $table->index('video_manual_id');
        });
    }

    public function down(): void
    {
        Schema::table('cuts', function (Blueprint $table): void {
            $table->dropIndex(['video_manual_id']);
        });
    }
};
