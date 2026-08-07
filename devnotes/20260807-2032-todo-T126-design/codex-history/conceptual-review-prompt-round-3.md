# 概念設計レビュー Round 3 (T126)

Round 2 の [Critical] 1 件と [Warning] 4 件・[Suggestion] 1 件をすべて反映しました。
特に Critical (S3 到達境界) は **vendor 実査**を追加したうえで母集団を張り替えています。

---

## 対応マトリクス (Round 2)

# 対応マトリクス: conceptual-review Round 2

## [Critical] 「クライアント取得点」の目録では S3 の 900s 継承を塞げない (母集団は S3 到達境界であるべき)

- 判断: **対応する** (指摘どおり母集団を張り替える。ただし保証範囲は誇張せず明記する)
- 根拠: 指摘は正しい。`Storage::disk('s3')->exists()` / `->delete()` /
  `->readStream()` は `getClient()` を通らず内部 `S3Client` を使う。
  実査すると `app/` 配下の `Storage::disk(` 呼び出しは **6 site / 4 クラス**しかなく
  (`TakeObjectStorage` 4 / `RenderObjectStorage` 1 / fake 2)、
  「S3 到達境界」を exact-fit の母集団にすることは十分に現実的である。
  また `Aws\Sdk::createClient()` 経路の指摘も受け、走査対象に `Aws\Sdk` を加える。
- 対応内容:
  1. **Gate A (到達境界)**: `app/` 全体を token 走査し
     `Storage::disk(...)` / `->getClient(` / `new \Aws\…Client(` / `Aws\Sdk` 参照 の
     **全 site** を目録と対称差ゼロで突き合わせる。disk 名は文字列リテラル必須
     (動的名は違反)。免除は型付き enum + 30 文字以上の根拠。
  2. **Gate B (面分類)**: 到達境界に登録された実装 adapter
     (`TakeObjectStorage` / `RenderObjectStorage`) の **public メソッド全数**を
     Reflection で列挙し、`S3OperationSurface` enum
     (`NoNetwork` / `BoundedControl` / `Bulk`) のいずれか + 30 文字以上の根拠で
     目録登録を必須にする (対称差ゼロ)。
     `BoundedControl` は per-command 制御系 option を積むことを behavioral に固定する。
  3. 「クライアントを得る口は 2 パターンしかない」という**断定は削除**する。
  4. **保証範囲を誇張しない断り書き**を設計に入れる:
     機械で証明できるのは (i) 到達境界が目録に閉じている (ii) 全 public メソッドが
     面分類を持つ (iii) `BoundedControl` が短い option を実際に積む、の 3 点であり、
     「`Bulk` を web 同期経路から呼ばない」は**規約であって証明ではない**。
  5. 補助として、`app/Http/` 配下で adapter 型を参照するファイルを目録化する
     (現状 2 本。新規追加時にレビューを強制できて偽陽性が出ない粒度)。
- 補足 (実装可能性の実査): Flysystem の write 経路 (`AwsS3V3Adapter::upload()`) は
  `createOptionsFromConfig()` が `AVAILABLE_OPTIONS` / `MUP_AVAILABLE_OPTIONS` しか
  転送しないため、**`@http` を注入できない**。したがって
  「client 既定を短くして bulk だけ長くする」という fail-safe 反転は取れない。
  client 既定はデータ系の値を持たざるを得ない — これが `Bulk` を面分類で
  明示する必要がある根拠でもある。

## [Warning] `timeout × attempts` は「実効上限」ではない (DNS / credential / endpoint discovery / backoff が外側にある)

- 判断: **対応する**
- 対応内容: 表の列名を「**HTTP 試行 timeout 予算**」へ変更し、
  「SDK 操作全体の wall-clock deadline ではない」旨を明記する。
  php-fpm 枯渇の有限化という主張は維持するが、厳密な deadline とは書かない。

## [Warning] getter-only では Stripe 大域 pin の独立検査にならない

- 判断: **対応する** (Round 1 の反論を撤回する)
- 根拠: 指摘が正しい。`ApiRequestor::$_httpClient` / `Stripe::$maxNetworkRetries` は
  **PHP プロセス大域**でアプリ再生成では戻らないため、「配線を消しても ambient state で green」
  という余地を論証で排除しきれない。
- 対応内容:
  1. pin を **専用 provider `ExternalClientTimeoutServiceProvider`** へ切り出す
     (`AppServiceProvider::boot()` に混ぜない)。これによりテストが
     **provider の `boot()` だけを再実行**でき、他の副作用 (Event::listen 等の二重登録) を
     踏まない。
  2. 専用テストで「既知の初期状態へ戻す → provider を boot → pin 値を検査 →
     `finally` で元の client と `maxNetworkRetries` を復元」を行う。
  3. `setHttpClient` / `setMaxNetworkRetries` / `CurlClient::instance()` の
     **呼び出し site を Gate A の目録に含める** (app/ 側は provider の 1 箇所だけ、
     tests/ 側は許可された 2 本だけ、と exact-fit で固定する)。

## [Warning] `240 < 300` は外部予算だけの序列で、ジョブ全体の上限を証明していない / 呼び出し数のドリフト

- 判断: **対応する** (値を組み直し、呼び出し回数を behavioral に固定する)
- 根拠: 指摘が正しい。60s の余白は薄く、呼び出しが 9 回になれば 30s まで縮む。
  Cashier 内部 (`createOrGetStripeCustomer`) の呼び出しは静的計数では数えられないため、
  **静的な site 計数ではなく実行時の HTTP 呼び出し回数**で固定するのが正しい。
- 対応内容:
  1. Stripe の timeout を **30s → 20s** へ引き下げる (実測 p99 の 10 倍のヘッドルーム)。
  2. `DEFAULT_CONNECTION_STRIPE_CALL_BUDGET = 10` を定数化 → 外部予算 **200s**。
  3. `DEFAULT_CONNECTION_LOCAL_HEADROOM_SECONDS = 100` を定数化 (非外部処理の余白) し、
     gate が `外部予算 + 余白 <= worker --timeout < retry_after` を機械固定する。
     余白 100s の根拠は docs に書く。
  4. 呼び出し回数のドリフト固定は **`Stripe\HttpClient\ClientInterface` を実装した
     計数 fake を `ApiRequestor::setHttpClient()` で差し込み**、実 `CashierAutoRechargeGateway`
     経由で `executeAttempt` を走らせて **HTTP 呼び出し回数 <= 10** を assert する
     (Stripe SDK 公式の seam。送信は発生しないので egress 規約に抵触しない)。
- 新しい帯: `外部予算 200 + 余白 100 = 300 = worker --timeout 300 < retry_after 360`。

## [Warning] ローリングデプロイ条件 (旧コード混在中に retry_after を縮めない)

- 判断: **対応する**
- 対応内容: `docs/architecture.md` の値表の直下に「**帯を変更するときのデプロイ順序**」を新設する:
  1. **先に** supervisor の `--timeout` を 540 → 300 に変更して worker を再起動する
     (このときコードは旧のまま = `retry_after 600`。`300 < 600` で規則 1 は成立)
  2. 新コード (pin + `retry_after 360`) をデプロイし、**全 worker を入れ替える**
  3. 旧 worker が残っていないことを確認する
  この順序なら「`retry_after 360` の期間に `--timeout 540` の worker が居る」窓は開かない。
  手順 1 で旧 Stripe 80s 前提のジョブが 300s で SIGALRM されうる点は
  `$tries = 1` + リコンサイルが受け止める (受容済み・明記する)。

## [Suggestion] shape の `mode` を literal string まで狭める

- 判断: **対応する**。`array{http: array{connect_timeout: int, timeout: int}, retries: array{mode: 'legacy', max_attempts: int}}` にする。


---

## 改訂後の概念設計 (全文)

# 概念設計: external-sdk-client-timeout (T126 外部 SDK の client timeout を pin する)

> **目的の主従 (最初に固定する)**
> **主**: web リクエスト経路の外部 SDK 待ちを有限化し、php-fpm ワーカーの枯渇を防ぐ。
> **従**: その結果として過大になっていた既定キュー接続の帯 (`retry_after` / worker `--timeout`) を縮める。
> この順序は施策の並び・テスト名・docs の記述にも反映する。T127 (キュー接続の分割) は
> **従**の側の話であり、本設計の主目的ではない。

## 背景・課題

### 事実確認 (実コードと vendor を読んで確認した内容)

1. **Stripe SDK の待ち上限は vendor 既定のまま**である。
   `vendor/stripe/stripe-php/lib/HttpClient/CurlClient.php` の
   `DEFAULT_TIMEOUT = 80` / `DEFAULT_CONNECT_TIMEOUT = 30` が効いており、
   アプリ側に `setTimeout()` / `setConnectTimeout()` / `ApiRequestor::setHttpClient()` を
   呼ぶ箇所は **1 つも無い**。`Stripe\Stripe::$maxNetworkRetries` も既定 0 のまま (明示 pin なし)。
   `Laravel\Cashier\Cashier::stripe()` は `StripeClient` に `api_key` / `stripe_version` /
   `api_base` しか渡さず、**`StripeClient` の config に timeout 系のキーはそもそも無い**
   (`BaseStripeClient::DEFAULT_CONFIG`)。したがって Stripe の待ち上限を動かせる層は
   **`ApiRequestor` の HTTP client (プロセス大域)** だけである。

2. **AWS SDK の待ち上限は「無指定 = 無制限」、かつ既定で 3 回試行する**。
   `vendor/aws/aws-sdk-php/src/ClientResolver.php` の `'http'` 引数は `'default' => []` で、
   `connect_timeout` / `timeout` の既定値を**持たない**。
   `retries` の既定は `Aws\Retry\ConfigurationProvider` の `DEFAULT_MODE = 'legacy'` /
   `DEFAULT_MAX_ATTEMPTS = 3` で、`_apply_retries()` は legacy のとき `maxAttempts - 1` を
   retry 数に使う = **3 attempts**。つまり **1 操作の HTTP 待ちは「無制限 × 3」**である。

   本アプリの AWS クライアント構築点・取得点:
   - `config/filesystems.php` の `disks.s3` → `FilesystemManager::createS3Driver()` が
     `new S3Client($s3Config)` に素通しする
   - `config/services.php` の `ses` → `MailManager::createSesV2Transport()` が
     `new SesV2Client(...)` に素通しする (キュー投入されたメール送信)
   - `app/Providers/AppServiceProvider.php` の `SnsClient` singleton (SNS 購読確認)
   - `app/Services/Capture/TakeObjectStorage::client()` の `Storage::disk('s3')->getClient()`

   **さらに `Storage::disk('s3')` 経由の Filesystem API (`exists` / `delete` /
   `readStream` / `writeStream` / `temporaryUrl`) は `getClient()` を通らずに
   内部の `S3Client` を使う**。`app/` 配下の `Storage::disk(` 呼び出しは
   **6 site / 4 クラス**である (`TakeObjectStorage` 4 / `RenderObjectStorage` 1 / fake 2)。

3. **キューの帯はこの既定値に引きずられている**。`config/queue.php` の `database` 接続は
   `retry_after = 600` で、コメントの根拠がそのまま
   「Stripe 4〜5 呼び出し × SDK 上限 80s = 約 400s」である。
   `docs/architecture.md` §キューのリース期間とワーカー制限時間の規約 の値表も
   `database` = retry_after 600 / worker `--timeout` 540 とこの前提で書かれている。

### 課題 A (主・可用性): web リクエスト経路の外部待ちが実質無制限である

キュー経路には worker の `--timeout` (SIGALRM) というハード上限があるが、
web リクエスト経路には**それが無い**。PHP の `max_execution_time` は Unix では
ソケット待ちの時間を数えないため、外部 SDK が応答しない間 php-fpm ワーカーは占有され続ける。

| web 経路 | 呼び出し | 現状の HTTP 試行 timeout 予算 |
|---|---|---|
| `TakeRegistrationService::register()` (撮影テイク登録) | S3 `HeadObject` (`TakeObjectStorage`) | **無制限 × 3 attempts** |
| `CashierAutoRechargeGateway::createSetupCheckout()` ほか Checkout / Portal 導線 | Stripe API | 80s × 1 attempt |
| `StripeWebhookProcessor` (Stripe webhook) | Stripe API | 80s × 1 attempt |
| SNS 購読確認 (`SnsClient::confirmSubscription`) | AWS SNS | **無制限 × 3 attempts** |

撮影 PWA の登録 API が S3 の応答待ちで詰まると、**php-fpm プール枯渇 = 全画面の停止**に
発展しうる。これは「思考ゼロ・編集ゼロ」で現場作業者が撮る、という使命の一次経路を止める。

### 課題 B (従・回収遅延): 既定キュー接続の帯が SDK 既定値のせいで過大である

`retry_after = 600` は「Stripe SDK が 80s も待つから」という理由だけで置かれている。
上限をアプリ側で固定すれば帯を縮められ、リース切れジョブの回収遅延が縮む。

## 改善アイデア

**「外部 SDK の待ち上限をアプリの単一出典から pin し、その値をキューの帯の根拠にする」**。

### 1. 値の単一出典を 1 クラスに置く

`app/Support/ExternalClientTimeouts.php` (final class + `public const int` + shape 付き
static メソッド) に pin する値を集約する。**config ファイルからも参照できる**ことが選定理由である
(config ファイルの中で `config()` を呼ぶのは読み込み順に依存して壊れるが、
オートロード済みクラスの定数参照は安全)。env で上書きできる口は作らない —
`config/queue.php` の `retry_after` が「gate が嘘をつく」という理由でリテラル固定されているのと
同じ理屈で、**静的 gate が読む値と本番の実値を一致させる**ためである。

AWS 側は

```
array{
    http: array{connect_timeout: int, timeout: int},
    retries: array{mode: 'legacy', max_attempts: int},
}
```

という shape を返す static メソッド経由で配線し、config 3 箇所へ定数を手で撒かない
(綴りずれを PHPStan level 10 で落とす)。

### 2. 「制御系」と「データ系」で上限を分ける

一律の総時間上限は誤りである。S3 の `readStream` / `writeStream` は
**本文サイズに比例して時間がかかる**ため、短い総時間上限は正当なレンダ素材の
ダウンロード/アップロードを殺す。

> **用語**: 以下の「HTTP 試行 timeout 予算」は **cURL / Guzzle に与える 1 試行あたりの
> 上限 × attempts** であり、**SDK 操作全体の wall-clock deadline ではない**。
> DNS 解決・credential provider・endpoint discovery・retry backoff はこの外側にある。
> php-fpm 枯渇を有限化する主張は維持するが、厳密な deadline とは書かない。

| 面 | connect_timeout | timeout | attempts | HTTP 試行 timeout 予算 | 根拠 |
|---|---|---|---|---|---|
| Stripe (プロセス大域) | 5s | 20s | 1 (`max_network_retries = 0`) | **20s** | 単一オブジェクトの create/retrieve/pay のみ。SDK 既定 80s は大きな一括処理向けの値。実測 p99 (数秒) の 10 倍のヘッドルーム |
| AWS 制御系 (SES 送信 / SNS) | 5s | 15s | 2 | **30s** | 転送量が有界。既存の SNS 証明書取得 pin (`AwsSnsSignatureVerifier`: connect 5 / timeout 10) と同じ帯 |
| AWS データ系 (s3 disk のクライアント既定) | 10s | 900s | 2 | 1800s (worker `--timeout` が実際の上限) | 転送量が本体サイズに比例する。ハングの有界化が目的で、正当な転送を殺さない値 |
| S3 制御系 (per-command 上書き) | 5s | 15s | **0 retries** | **15s** | web 同期 API では SDK 内で粘らせず、アプリ側で失敗を返して再操作を促す |

`@http` / `@retries` の per-command 上書きが効くことは vendor で確認済み
(`Aws\AwsClient::getCommand()` が `@http` を `+=` で合成 = 渡した側が勝つ /
`Aws\RetryMiddleware` と `RetryMiddlewareV2` が `$command['@retries']` を読む)。

**逆向き (client 既定を短くして bulk だけ長くする) は取れない**。
Flysystem の write 経路 (`AwsS3V3Adapter::upload()` → `createOptionsFromConfig()`) は
`AVAILABLE_OPTIONS` / `MUP_AVAILABLE_OPTIONS` しか転送せず、**`@http` を注入できない**
(vendor 実査)。client 既定はデータ系の値を持たざるを得ない。

### 3. 「S3 到達境界」と「操作の面」を deny-by-default の目録にする

per-command 上書きだけでは、**将来 web 経路に足された metadata 操作が
データ系の 900s を継承する**という穴が残る。母集団を「クライアント取得点」ではなく
**S3 能力への到達境界**に置いて塞ぐ。

**Gate A (到達境界)**: `app/` 全体を token 走査し、
`Storage::disk(...)` / `->getClient(` / `new \Aws\…Client(` / `Aws\Sdk` 参照 の
**全 site** を目録と**対称差ゼロ**で突き合わせる (disk 名は文字列リテラル必須。
動的名は違反)。免除は型付き enum + 30 文字以上の根拠。
Stripe 側も同じ目録に載せる — `ApiRequestor::setHttpClient()` /
`Stripe::setMaxNetworkRetries()` / `CurlClient::instance()` の呼び出し site は
**app/ では pin 用 provider の 1 箇所だけ**であることを exact-fit で固定する。

**Gate B (面分類)**: 到達境界に登録された実装 adapter
(`TakeObjectStorage` / `RenderObjectStorage`) の **public メソッド全数**を Reflection で列挙し、
`S3OperationSurface` enum のいずれか + 30 文字以上の根拠で目録登録を必須にする (対称差ゼロ)。

| case | 意味 | 例 |
|---|---|---|
| `NoNetwork` | ローカル署名 / 文字列生成のみ (ネットワークに出ない) | `presignUpload` / `temporaryPlaybackUrl` / `temporaryDownloadUrl` / `keyPrefixFor` / `contentDisposition` |
| `BoundedControl` | 転送量が有界なメタデータ操作。**per-command 制御系 option を積むことが必須**。web 同期経路から呼んでよい | `headObject` |
| `Bulk` | 本文転送、または Flysystem 経由で option を注入できない操作。disk 既定 (900s) を継承する。**web 同期経路から呼ばない** | `downloadToLocal` / `upload` / `delete` / `exists` |

> **保証範囲を誇張しない**。機械で証明できるのは
> (i) S3 到達境界が目録に閉じていること、
> (ii) adapter の全 public メソッドが面分類を持つこと、
> (iii) `BoundedControl` が実際に短い option を積むこと、の 3 点である。
> **「`Bulk` を web 同期経路から呼ばない」は規約であって証明ではない**。
> 補助として `app/Http/` 配下で adapter 型を参照するファイルを目録化する
> (現状 2 本。新規追加時にレビューを強制でき、偽陽性が出ない粒度)。

### 4. 帯 (retry_after / worker --timeout) を pin 値から導く

pin 後の既定接続 `database` の外部呼び出し予算:

- Stripe: 20s × **呼び出し予算 10 回** = **200s**
  (`ExecuteAutoRechargeAttemptJob` の最長経路。customer retrieve / invoice create /
  invoiceItem create / finalize / pay / paymentIntent retrieve / 後始末の void 等)
- SES (メール送信ジョブ): 15s × 2 attempts = 30s

**呼び出し予算 10 回はドリフトする**ため、静的な site 計数ではなく
**実行時の HTTP 呼び出し回数**で固定する — `Stripe\HttpClient\ClientInterface` を実装した
計数 fake を `ApiRequestor::setHttpClient()` で差し込み、実 `CashierAutoRechargeGateway`
経由で `executeAttempt` を走らせて **HTTP 呼び出し回数 <= 10** を assert する
(Stripe SDK 公式の seam。実送信は発生しないので egress 規約に抵触しない)。

さらに **非外部処理の余白**を定数で持ち、gate が序列を機械固定する:

```
外部予算 200s + 局所処理の余白 100s = worker --timeout 300s < retry_after 360s
```

(現行は `400 < 540 < 600`)。回収遅延の代償は **最大 510s → 最大 270s** に縮む。
既存 gate の前提は新値でも成立する
(`AutoRechargeService::LOCK_TTL_SECONDS = 180 < 360` / `uniqueFor = 30 < 360`)。

### 5. gate の空振り防止

- 母集団 0 件は fail (到達境界 site / adapter public メソッド / driver=database 接続)
- **負のコントロール**: pin 値が SDK 既定と**異なる**ことを固定する
  (`STRIPE_TIMEOUT_SECONDS !== CurlClient::DEFAULT_TIMEOUT` 等)。
  「pin していないのに green」を検出する
- **exact fit**: 走査結果と目録の対称差ゼロ (件数一致ではなくキー同一性)
- **behavioral**: config を読むだけで終わらせず、構築したクライアントに値が届いていることを
  `AwsClient::getCommand()` の `@http` / `@retries` で確認する
- **mutation で赤化を確認**する手順を詳細設計に書く (素の main では赤にならない gate の受け入れ)

## 期待効果

### 主 (可用性) — HTTP 試行 timeout 予算

| 経路 | 現状 | 変更後 |
|---|---|---|
| 撮影テイク登録の S3 `HeadObject` (web) | 無制限 × 3 attempts | **15s** (timeout 15s / retry なし) |
| SNS 購読確認 (web) | 無制限 × 3 attempts | **30s** (15s × 2 attempts) |
| Stripe API (web / webhook) | 80s × 1 attempt | **20s** × 1 attempt |
| SES 送信 (queue) | 無制限 × 3 attempts | **30s** (15s × 2 attempts) |
| S3 本文 read/write (queue) | 無制限 × 3 attempts | 900s × 2 attempts (worker `--timeout` が実際の上限) |

### 従 (回収遅延)

- 既定キュー接続のリース切れ回収遅延: 最大 600s → **最大 360s**
  (T127 が問題にした代償 510s → 270s)

### 構造

- 「外部 SDK の待ち上限が SDK の既定値に依存している」という**無宣言の状態を解消**し、
  SDK バージョン更新で既定値が動いても静かに帯が壊れなくなる。
- S3 到達境界と操作の面が目録化され、新しい S3 操作の追加が必ずレビューを通る。

## 実装方針（概要）

Codex の助言に従い、**同一 PR 内で実装順を分ける** (中間状態を残さないため PR は 1 本)。

| 順 | 施策 | 対象 |
|---|---|---|
| 1 | pin 値の単一出典クラス新設 | `app/Support/ExternalClientTimeouts.php` |
| 2 | Stripe pin の専用 provider 新設 | `app/Providers/ExternalClientTimeoutServiceProvider.php` + `bootstrap/providers.php` |
| 3 | AWS クライアント 3 構築点へ `http` / `retries` を配線 | `config/filesystems.php` / `config/services.php` / `AppServiceProvider` (SnsClient) |
| 4 | `headObject` の per-command `@http` + `@retries` 上書き | `app/Services/Capture/TakeObjectStorage.php` |
| 5 | 面分類 enum + 免除 enum + deny-by-default 目録 gate | `app/Enums/Storage/S3OperationSurface.php` ほか + `tests/Architecture/ExternalClientTimeoutInventoryTest.php` |
| 6 | Stripe 呼び出し回数の behavioral 固定 | `tests/Feature/Billing/AutoRechargeStripeCallBudgetTest.php` |
| 7 | worst-case 表とデプロイ順序の更新 (帯を動かす前に根拠を先に置く) | `docs/architecture.md` |
| 8 | 既定接続の帯の張り替え | `config/queue.php` / `mprocs.yaml` |
| 9 | 既存 lease invariant の更新 (literal 600 の assert 等) | `tests/Architecture/QueueWorkerLeaseInvariantTest.php` |

## 制約・前提

- **Stripe の pin はプロセス大域にしか置けない**。`Cashier::stripe()` /
  `$organization->stripe()` / `AppServiceProvider` の `PriceService` bind という
  3 系統の呼び出し口があるが、いずれも `ApiRequestor::httpClient()` (static) を通るため、
  大域 pin 1 本で全経路を覆える。**「クライアントごとに違う timeout」は SDK が支えない**
  (この非対称を誇張して書かない)。**テナント別 timeout は設計として持たない**。
- **大域状態はテストで復元する**。`ApiRequestor::$_httpClient` / `Stripe::$maxNetworkRetries` は
  **PHP プロセス大域**でアプリ再生成では戻らない。setter を使うテストは
  「pin 配線の検査」と「Stripe 呼び出し回数の検査」の **2 本だけ**に限定し、
  いずれも `finally` で元の値へ復元する。この 2 本以外に setter を呼ばないことを
  Gate A の目録が exact-fit で固定する。pin を **専用 provider** に切り出すのは、
  テストが `boot()` を単独で再実行しても他の副作用を踏まないようにするためである。
- **テストレーンから実 API を叩かない**。`Http::` 経由の egress guard は AGENTS.md の記述どおり
  Stripe SDK / AWS SDK には効かないため、gate は `getCommand()` (送信しない) /
  getter 参照 / 計数 fake だけで完結させる。Stripe/S3 の fake 配線
  (`FakeExternalsServiceProvider` / `FakeStorageGate`) は変更しない。
- **既存 gate の前提を壊さない**。`QueueWorkerLeaseInvariantTest` は `retry_after` が
  リテラルであることと env 上書き不能であることを固定し、`600` を直接 assert している。
  `JobExclusionOrderingInvariantTest` は `LOCK_TTL_SECONDS = 180` / `uniqueFor = 30` が
  `retry_after` を下回ることを固定している。新値 360 でも両方成立するが、
  前者の literal assert は更新が要る。
- **T132 (`GatewayFailureClassifier`) との整合**: Stripe の timeout 由来例外は cURL エラーであり
  `ApiConnectionException` に写る。`directMap()` は既に
  `ApiConnectionException => ProviderUnavailable` を持つため、**分類表の変更は不要**。
  ただし「timeout が `ProviderUnavailable` に落ちる」ことは現状どこにも明文化されておらず、
  timeout を短くすると出現頻度が上がるため、**この対応関係を固定するテストを 1 本足す**
  (分類表を変えないことの根拠を CI に残す)。
- **帯の変更は運用上の破壊的変更 (ローリングデプロイ条件)**。本番 supervisor はリポジトリ外で
  CI が検知できない。`docs/architecture.md` の値表直下に手順を明記する:
  1. **先に** supervisor の `--timeout` を 540 → 300 に変更して worker を再起動する
     (このときコードは旧のまま = `retry_after 600`。`300 < 600` で規則 1 は成立)
  2. 新コード (pin + `retry_after 360`) をデプロイし、**全 worker を入れ替える**
  3. 旧 worker が残っていないことを確認する

  この順序なら「`retry_after 360` の期間に `--timeout 540` の旧 worker が居る」窓は開かない。
  手順 1 で旧 Stripe 80s 前提のジョブが 300s で SIGALRM されうる点は、
  `ExecuteAutoRechargeAttemptJob` の `$tries = 1` + リコンサイルが受け止める (受容する)。
- **`max_network_retries = 0` を pin する理由も docs に残す**: 課金の一回性は
  Stripe idempotency key とリコンサイルで担保する設計 (AGENTS.md ドメイン規約 6) であり、
  SDK 自動 retry に寄せない。

## スコープ外

- **T127 (既定キュー接続の分割) の昇格はしない**。本設計で代償が 510s → 270s に縮むため、
  条件 (「回収遅延が実害として観測されたとき」) は依然未成立である。
  接続を割ると `JobExclusionOrderingInvariantTest` の
  「auto-recharge の 2 ジョブは既定接続で動く」前提も同時に張り替えることになり、
  今必要でない複雑さを持ち込む (AGENTS.md 思考原則 2)。
- **`GatewayFailureClass` への新 case 追加はしない**。timeout は
  「決済事業者側の一時的な不能」であり `ProviderUnavailable` で語彙が足りている。
  分類は観測専用で制御フローを変えないという T132 の裁定を維持する。
- **Socialite の timeout**。Socialite は AWS/Stripe のような SDK ではなく **Guzzle 直呼び**で、
  pin を置く層 (Guzzle client の生成点) がまったく別である。T126 の題名どおり
  「外部 **SDK** の client timeout」に閉じる (混ぜると 1 タスクで 2 つの機構を作ることになる)。
- **LLM (Prism) の timeout**。既に `resources/prompts/*.yaml` の `client_options.timeout` で
  pin 済みで `PromptClientTimeoutInvariantTest` が固定している。二重管理にしない。
- **`Http::` (Laravel HTTP client) 経由の外部呼び出し**。reCAPTCHA / SNS 証明書取得は
  すでに呼び出し側で timeout を指定している。
- **timeout 超過時のリトライ戦略の変更**。`ExecuteAutoRechargeAttemptJob` は `$tries = 1` で
  リコンサイルに再試行を一本化する設計であり、これを触らない。
- **`AWS_DEFAULT_REGION` の既定値付与など、s3 disk の設定不備の是正**。別件。


---

## Round 3 で確認したいこと

1. Critical (S3 到達境界) の解消は十分か。
   母集団を「`Storage::disk(` / `->getClient(` / `new \Aws\…Client(` / `Aws\Sdk` 参照の全 site」
   に置き、面分類 (`NoNetwork` / `BoundedControl` / `Bulk`) を adapter の public メソッド全数に
   強制する形で、**900s 継承の穴**は塞げているか。まだ抜ける経路があれば具体的に指摘してほしい。
   (なお「`Bulk` を web から呼ばない」を機械証明する案は、呼び出しグラフ解析が必要で
    偽陰性が静かに増えるため採らず、**規約であって証明ではない**と明記する方針にした。
    この割り切りが妥当かも判定してほしい。)
2. 帯の新値 `外部予算 200 + 余白 100 = worker --timeout 300 < retry_after 360` と、
   呼び出し回数を計数 fake で behavioral に固定する方針に穴はないか。
3. ローリングデプロイ手順 (supervisor 先行 → 新コード全入れ替え → 旧 worker 不在確認) で
   窓が閉じているか。
4. 残る指摘があれば挙げたうえで、**全体判定**を示してほしい。
