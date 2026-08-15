<?php

declare(strict_types=1);

namespace App\Enums\Security;

/**
 * 滞留回収が主キーで行を取り直すときの「入口の形」。
 *
 * 形ごとに封じ込めの検査が違うため、目録の登録で明示させる
 * (`tests/Architecture/ModelDirectFetchInvariantTest.php` が形ごとの検査を実走する。
 * テストクラスへの {@see} 参照は app → tests の import を生むため書かない)。
 */
enum RecoveryFetchShape: string
{
    /**
     * 回収の系列 → ドメインサービスの公開メソッド → 同クラスの private ヘルパ。
     *
     * 封じ込めの検査: 公開メソッドの名前が `app/` 配下に現れるファイルが、
     * 宣言したファイルと申告した系列のファイルの組だけに収まっていること。
     */
    case DomainService = 'domain_service';

    /**
     * 回収の系列 → 同じ系列クラスの private ヘルパ (ドメインサービスを挟まない)。
     *
     * 封じ込めの検査: private ヘルパの名前が `app/` 配下に現れるファイルが、
     * その系列のファイル 1 つだけであること。
     */
    case StreamInternal = 'stream_internal';
}
