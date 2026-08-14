<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use Inertia\Inertia;
use Inertia\Response;

/**
 * bfcache 実機受入確認 (T085) の検証ページ。LocalOnly + auth の背後でのみ動く。
 *
 * 目的: T085 の実機手順には**負のコントロールが無く**、「guard が働いた」と
 * 「そもそも bfcache 復元が起きなかった」を目視で区別できない。どちらも
 * 「PII が出ない」に見えるため空振りを合格として記録しうる。本ページは
 * pageshow.persisted・JS 実行コンテキスト生存トークン・guard の状態遷移を
 * 観測して、その区別を機械化する。
 *
 * 観測値はすべてクライアント側で生成されるため **controller 固有の props を渡さない**。
 * (HandleInertiaRequests の共有 props は別で、そちらは載る。とくに `auth.user` は
 * bfcache guard の作動条件そのものなので、無いと検証が成立しない。)
 * サーバの責務は「認証済みページとして描画されること」だけで、それにより
 * web グループの NoStoreCacheHeadersForAuthenticatedPages が no-store を付け、
 * app.ts が登録した**本物の** bfcache guard がそのまま作動する
 * (検証対象を検証の都合で変えたら、確認しているものが production と別物になる)。
 *
 * ページが 2 枚あるのは、A から **full document navigation** で離脱しないと
 * A が bfcache に入らないためである。Inertia visit は同一 Document のままなので
 * pagehide が起きず、戻る操作は経路 C (Inertia の history 復元) になってしまう。
 */
class DebugBfcacheTrialController extends Controller
{
    /** 検証ページ A (観測・判定・証跡表示)。 */
    public function trial(): Response
    {
        return Inertia::render('Debug/BfcacheTrial');
    }

    /** 相方ページ B。A を bfcache に入れるための full document navigation の着地点。 */
    public function away(): Response
    {
        return Inertia::render('Debug/BfcacheTrialAway');
    }
}
