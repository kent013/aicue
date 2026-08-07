<?php

declare(strict_types=1);

namespace App\Enums\Storage;

/**
 * S3 集約 adapter の public メソッドが持つ「面」の分類。
 *
 * `tests/Architecture/ExternalClientTimeoutInventoryTest.php` が deny-by-default で
 * 全 public メソッドの登録を機械強制する (テストクラスへの {@see} 参照は
 * app → tests の import を生むため書かない)。
 *
 * ★分類の基準は「**転送量が有界か**」と「**per-command option を注入できるか**」の 2 軸。
 */
enum S3OperationSurface: string
{
    /**
     * S3 オブジェクト API を送信しない (ローカル署名 / 文字列生成のみ)。
     *
     * ★**credential 解決 (ECS/EC2 metadata 等) がネットワークへ出る可能性は保証外**である。
     *   「一切ネットワークに出ない」とは主張しない。
     */
    case NoObjectRequest = 'no_object_request';

    /**
     * 転送量が有界なメタデータ操作。**per-command の制御系 option を積むことが必須**。
     *
     * 適用条件: 生の S3Client を直接呼び、`@http` / `@retries` を注入できること。
     * web 同期経路 (HTTP リクエスト内) から呼んでよいのはこの面だけである。
     */
    case BoundedControl = 'bounded_control';

    /**
     * 本文転送、または Flysystem 経由で per-command option を注入できない操作。
     *
     * s3 disk のクライアント既定 (データ系の長い timeout) を継承する。
     * **web 同期経路から呼ばない** — これは規約であり、機械では証明していない
     * (呼び出しグラフ解析が要り、静的近似は偽陰性が静かに増えるため採らない)。
     * 既存の web 経路については Feature テストが `Bulk` 不使用を固定する。
     */
    case Bulk = 'bulk';
}
