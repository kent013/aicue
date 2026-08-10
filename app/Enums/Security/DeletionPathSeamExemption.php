<?php

declare(strict_types=1);

namespace App\Enums\Security;

/**
 * 退会 (アカウント削除) 経路の依存閉包から決済事業者記号へ到達することを認める免除。
 *
 * 母集団は `tests/Architecture/AccountDeletionPathGateTest.php` の検査 2 が出す hit で、
 * 免除は **型付き case + 30 文字以上の根拠**のセットでのみ成立する
 * (文字列で免除できると根拠なしに穴を開けられるため)。
 *
 * ★**現時点で case は 0 本**である。閉包内に決済事業者記号は 1 件も無く、
 *   gate は「0 本ちょうど」を cap として pin している (余裕枠を持たせない)。
 *   case を足すときは gate 側の `DELETION_PATH_SEAM_EXEMPTION_RATIONALES` へ
 *   同じ value をキーに 30 文字以上の根拠を**同時に**登録する必要がある
 *   (登録しなければ gate が赤くなる = 免除は必ずレビューを通る)。
 *
 * ★value の書式は `{クラス FQCN}#{記号}` である (gate の hit が持つ**安定 symbol** と同じ形。
 *   **行番号を含まない**ので、コードが上下に動いても免除は壊れない)。symbol の実例:
 *   - 型・クラス参照: `App\Services\Foo#Stripe\StripeClient`
 *   - 静的呼び出し: `App\Services\Foo#Laravel\Cashier\Cashier::stripe()`
 *   - メソッド呼び出し: `App\Services\Foo#->newSubscription()`
 *   - container literal: `App\Services\Foo#container:cashier.stripe`
 */
enum DeletionPathSeamExemption: string
{
    // 現時点で case は 0 本 (閉包内に決済事業者記号は 1 つも無い)。
    // 足すときは上の docblock の手順に従うこと。
}
