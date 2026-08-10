<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Account Configuration
|--------------------------------------------------------------------------
|
| 退会 (アカウント削除) の猶予期間つき削除に関する設定。
|
*/

return [

    /*
    | 退会 (アカウント削除) の猶予日数。**env を使わない** — 環境ごとに変えてよい運用値ではなく、
    | オーナーが確定したプロダクト判断であり、利用者に「いつまで取り消せるか」を約束する値である
    | (config/legal.php の billing_retention_years / config/idempotency.php の
    | retention_hours と同じ理由)。
    |
    | 唯一の解決点は App\Support\Account\AccountDeletionGrace で、Service / Command / 画面は
    | config を直読しない (直読は AccountDeletionGraceConfigTest が deny-by-default で禁止する)。
    |
    | この値の変更は**既に入っている予約には遡及しない**。予約は users.deletion_purge_after に
    | **絶対時刻**で確定するため、変更後の値が効くのは以後の新規予約だけである
    | (不可逆な物理削除の期日を後から動かさないための設計)。
    */
    'deletion_grace_days' => 30,

];
