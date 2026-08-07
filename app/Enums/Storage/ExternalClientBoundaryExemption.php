<?php

declare(strict_types=1);

namespace App\Enums\Storage;

/**
 * 「AWS SDK / Flysystem へ到達するが、S3 集約 adapter ではない」ことが正しいと裁定された理由の分類。
 *
 * ★分類は「汎用に見えるものほど適用条件を狭く」定義する。
 *   当てはまる case が無ければ、それは「adapter へ寄せるべきコード」である。
 */
enum ExternalClientBoundaryExemption: string
{
    /**
     * AWS の**値オブジェクト**だけを扱い、クライアントを構築も取得もしない。
     *
     * 適用条件: 参照が `Aws\Sns\Message` / `Aws\Sns\MessageValidator` のような
     * リクエストを送らない型に限られ、`disk()` も `getClient()` も呼ばない。
     * (証明書取得は自前 HTTP client 経由で timeout 指定済み = SDK の待ちではない)
     */
    case AwsValueObjectOnly = 'aws_value_object_only';

    /**
     * 制御系 AWS クライアントの**構築点**であり、pin 値を明示的に渡している。
     *
     * 適用条件: `App\Support\ExternalClientTimeouts::awsControlClientOptions()` を
     * 構築引数へ展開しており、per-command 上書きを必要としない (転送量が有界)。
     */
    case PinnedControlClientConstruction = 'pinned_control_client_construction';

    /**
     * pin 済みの制御系 AWS クライアントを **DI で受け取って使うだけ**の消費点。
     *
     * 適用条件: クライアントを自分で構築せず (`new` しない)、
     * `PinnedControlClientConstruction` の構築点が渡したインスタンスをそのまま使うこと。
     * 待ち上限は構築点の pin が決めるため、この層に per-command 上書きは要らない。
     */
    case InjectedPinnedControlClient = 'injected_pinned_control_client';

    /**
     * `Storage` facade を**既定 disk のみ**で使い、AWS クライアントを自分で解決しない。
     *
     * 適用条件: `disk(...)` / `getClient()` / `new Aws\…` のいずれも持たないこと
     * (この 3 つは目録 gate が走査結果で機械検査する)。
     *
     * ★**保証を誇張しない**: 既定 disk は `FILESYSTEM_DISK` で決まるため、
     *   運用が `s3` を指せばこの層も S3 へ到達する。そのときの待ちは
     *   **`driver=s3` の disk 全件に強制した pin (データ系の帯)** を継承する
     *   = 有界ではあるが長い。無制限にはならない、が「S3 へ行かない」わけでもない。
     */
    case DefaultDiskWithoutAwsClient = 'default_disk_without_aws_client';

    /**
     * 外部 SDK のプロセス大域設定を pin する専用 provider。
     *
     * 適用条件: `ApiRequestor::setHttpClient()` / `Stripe::setMaxNetworkRetries()` の
     * 呼び出しが本クラスに 1 箇所ずつだけ存在し、他に副作用を持たないこと。
     */
    case GlobalSdkTimeoutPin = 'global_sdk_timeout_pin';

    /**
     * 本番の外部到達を持たないテストダブル (fake) 実装。
     *
     * 適用条件: `disk()` の引数が **s3 以外のローカル disk** (`s3_fake`) に固定されているか、
     * `client()` が例外を投げて実 SDK 経路に落ちないこと。**面分類の対象にはしない**
     * (本番の外部呼び出しを持たないため「面」を持たない)。
     */
    case TestDoubleWithoutExternalEgress = 'test_double_without_external_egress';
}
