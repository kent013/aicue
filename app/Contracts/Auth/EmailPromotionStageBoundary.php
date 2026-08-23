<?php

declare(strict_types=1);

namespace App\Contracts\Auth;

use App\Models\User;
use App\Services\Auth\EmailPromotionService;
use App\Services\Auth\InertEmailPromotionStageBoundary;

/**
 * メールアドレスの昇格の**第 1 段 (消費の commit) と第 2 段 (適用) の継ぎ目**。
 *
 * ## なぜ本番のコードに継ぎ目があるのか
 *
 * {@see EmailPromotionService} は消費と適用を**別のトランザクション**に分ける
 * (理由は同クラスの docblock「なぜ消費と適用を 2 段に分けるか」)。分けた結果、
 * **2 段の間に別経路が `users.email` を入れる窓**が開く。第 2 段はその窓を前提に
 * 行ロックの下で読み直して弾く — つまり**窓の存在そのものが本サービスの契約**である。
 *
 * その契約を検査するには「第 1 段が commit した後・第 2 段が始まる前」に割り込む必要がある。
 * ここを塞ぐ選び方は 2 つあった:
 *
 * 1. **2 段を公開メソッドにする** — 却下した。`confirm()` が担っている
 *    トークンの指紋照合・期限・本人結合を**迂回できる第 2 の入口**になる
 *    (任意の `VerifiedEmail` を適用できてしまう)。テスト容易性のために
 *    本番の操作面を広げてはならない。
 * 2. **継ぎ目だけを 1 メソッドの協力者として外に出す** — こちらを採った。
 *    本番実装 ({@see InertEmailPromotionStageBoundary}) は**何もしない**。
 *    公開の入口は `confirm()` 1 本のままであり、操作面は 1 ミリも広がらない。
 *
 * ★この継ぎ目は**メールを書かない・トークンを消費しない**。できるのは
 *   「第 1 段が終わった」という時点で任意のコードが走ることだけである。
 */
interface EmailPromotionStageBoundary
{
    /**
     * 第 1 段のトランザクションが閉じ、第 2 段が始まる**前**に呼ばれる。
     *
     * 本番実装は何もしない。検査だけが割り込む。
     */
    public function afterConsume(User $user): void;
}
