# 概念設計レビュー Round 2 (T126)

Round 1 の指摘への対応マトリクスと、改訂した概念設計を提示します。
[Critical] 2 件はいずれも **vendor を実査して指摘が正しいことを確認**したうえで対応しました。

---

## 対応マトリクス (Round 1)

# 対応マトリクス: conceptual-review Round 1

## [Critical] AWS の worst-case が `timeout × attempts + backoff` であり、web 経路の見積もりが甘い

- 判断: **対応する**
- 根拠: vendor を実査して指摘が正しいことを確認した。
  `Aws\Retry\ConfigurationProvider::DEFAULT_MAX_ATTEMPTS = 3` / `DEFAULT_MODE = 'legacy'` で、
  `ClientResolver::_apply_retries()` は legacy のとき `maxAttempts - 1` を retry 数に使う。
  つまり既定は **3 attempts**。`retries` を pin しない限り実効上限は `timeout × 3 + backoff` になる。
  設計の表が「15s」と書いていたのは誤り。
- 対応内容:
  1. AWS クライアント 3 面すべてに `retries` を **明示 pin** する
     (`['mode' => 'legacy', 'max_attempts' => 2]`)。値の単一出典クラスに定数として置く。
  2. **web 同期経路の S3 metadata 操作 (`headObject`) は per-command `@retries => 0`** にする。
     `Aws\RetryMiddleware` / `RetryMiddlewareV2` が `$command['@retries']` を読むことを vendor で確認済み
     (V1 は retry 数、V2 は `+1` して max attempts)。指摘どおり「同期 API は SDK 内で粘らせず
     アプリ側で失敗を返す」方針に寄せる。
  3. 期待効果の表を **attempts 込みの実効上限**へ書き直す。

## [Critical] S3 disk 全体 900s + `headObject` だけ per-command 上書きだと、将来 web 経路に足された metadata 操作が 900s を継承する

- 判断: **対応する** (ただし「操作名の目録」ではなく「S3Client を握れる箇所の目録」で解く)
- 根拠: 指摘の危険は本物である。ただし「web 同期経路から呼ばれる S3 操作」を静的に判定するのは
  呼び出しグラフの解析が要り、deny-by-default の母集団として脆い (偽陰性が静かに増える)。
  一方、**生の `S3Client` を取得できる口は構造的に有限**である —
  `Storage::disk(...)->getClient()` と `new \Aws\…Client(...)` の 2 パターンしかない
  (`TakeObjectStorage::client()` が唯一の `getClient()` 呼び出し点であることを実査で確認)。
  ここを exact-fit の目録にすれば、**新しい metadata 操作を足すときに必ず目録を通る**。
- 対応内容: gate の母集団を「AWS SDK クライアントの構築点」から
  「**AWS SDK クライアントの構築点 + 取得点 (`->getClient()`)**」へ拡張し、各 entry に
  「per-command 制御系 option を必ず渡す (pinned)」か「型付き enum 免除 + 30 文字以上の根拠」を要求する。
  さらに `TakeObjectStorage::headObject()` が実際に `@http` / `@retries` を積むことを
  behavioral に固定する (既存 `TakeObjectStorageTest` が実 SDK オブジェクトを使う形と同じ土俵)。

## [Warning] Stripe のプロセス大域 pin のテスト間状態漏れが設計に落ちていない

- 判断: **対応する** (ただし「退避・復元 helper」は作らない)
- 根拠: 状態漏れが起きるのは「テストが pin を書き換える」場合である。本設計では
  **テストは setter を一切呼ばず getter だけを読む** ため、書き換える主体が存在しない。
  退避・復元 helper を先回りで作るのは AGENTS.md 思考原則 2 (今必要なものだけ作る) に反する。
- 対応内容: 制約・前提に「gate は `ApiRequestor::httpClient()` の **getter しか触らない**
  (大域状態を汚さない)」ことと、「テナント別 timeout は設計として持たない」ことを明記する。
  pin が実際に効いていることは mutation (pin 行の削除で赤化) で確認する。

## [Warning] 本番 supervisor はリポジトリ外なので、コード変更だけでは不変条件が成立しない

- 判断: **対応する**
- 根拠: 既に `docs/architecture.md` が「本番/ステージングの supervisor 定義にもこの `--timeout` を
  必ず設定する (リポジトリ外にあるため CI は検知しない。上表が正本)」と書いている。
  帯を動かす以上、この一文だけでは不十分で「**いつ・何を・どの順で**変えるか」が要る。
- 対応内容: 施策に「`docs/architecture.md` の値表更新 + **デプロイ順序の明記**
  (`--timeout=300` を supervisor へ反映してから `retry_after=360` をデプロイする、
  逆順は規則 1 違反の窓を開く)」を含め、**運用上の破壊的変更**として扱うことを明記する。

## [Warning] 期待効果を attempts 込みで書き直すべき

- 判断: **対応する** (Critical 1 の対応に含める)

## [Warning] 主目的 (web 経路の有限化) と従目的 (帯の短縮) の順序を明文化すべき

- 判断: **対応する**
- 対応内容: 概念設計の「課題」「期待効果」「実装方針」を主従の順に並べ直し、
  T127 との境界を明示する。

## [Warning] 同一 PR でよいが実装順を分けるべき

- 判断: **対応する**
- 対応内容: 詳細設計の施策順を Codex の提案どおり
  (1) SDK pin + gate → (2) worst-case 表 → (3) 帯の変更 → (4) 既存 lease invariant 更新
  に固定する。

## [Suggestion] `maxNetworkRetries = 0` の理由を docs に残す

- 判断: **対応する**。`docs/architecture.md` に「課金は外部冪等キーとリコンサイルで担保するため
  SDK 自動 retry に寄せない」を書く。

## [Suggestion] AWS config array の shape が緩い → 小さな factory/helper へ寄せる

- 判断: **対応する**
- 根拠: PHPStan level 10 で `array{...}` shape を宣言した static メソッドにすれば、
  config 3 箇所の綴りずれが型で落ちる。定数を 3 箇所へ手で撒くより堅い。
- 対応内容: `ExternalClientTimeouts::awsClientOptions()` /
  `::awsControlPlaneCommandOptions()` を shape 付きで用意し、config から呼ぶ。

## [Suggestion] 見落とし候補 (Socialite / vendor SDK 直呼び)

- 判断: **一部反論・一部対応**
- 根拠: Socialite は Guzzle 直で AWS/Stripe SDK ではない。AGENTS.md が
  「テストレーンの egress guard は Socialite / Stripe SDK / AWS SDK に効かない」と
  既に非対称を明記しており、Socialite の timeout は**別テーマ**である
  (T126 の題名は「外部 SDK の client timeout」)。今回混ぜない = 思考原則 2。
- 対応内容: 「スコープ外」に Socialite を明記し、**なぜ外すか**の根拠を書く
  (対象が SDK ではなく Guzzle 直呼びで、pin の層がまったく別)。
  一方「S3 disk を使うが本文転送ではない操作」は Critical 2 の対応に含めた。


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
   retry 数に使う = **3 attempts**。つまり **1 操作の実効上限は「無制限 × 3」**である。

   本アプリの AWS クライアント構築点・取得点は 4 つあり、いずれも `http` / `retries` を渡していない:
   - `config/filesystems.php` の `disks.s3` → `FilesystemManager::createS3Driver()` が
     `new S3Client($s3Config)` に素通しする
   - `config/services.php` の `ses` → `MailManager::createSesV2Transport()` が
     `new SesV2Client(...)` に素通しする (キュー投入されたメール送信)
   - `app/Providers/AppServiceProvider.php` の `SnsClient` singleton (SNS 購読確認)
   - `app/Services/Capture/TakeObjectStorage::client()` の
     `Storage::disk('s3')->getClient()` (= 生の `S3Client` を握れる**唯一**の口)

3. **キューの帯はこの既定値に引きずられている**。`config/queue.php` の `database` 接続は
   `retry_after = 600` で、コメントの根拠がそのまま
   「Stripe 4〜5 呼び出し × SDK 上限 80s = 約 400s」である。
   `docs/architecture.md` §キューのリース期間とワーカー制限時間の規約 の値表も
   `database` = retry_after 600 / worker `--timeout` 540 とこの前提で書かれている。

### 課題 A (主・可用性): web リクエスト経路の外部待ちが実質無制限である

キュー経路には worker の `--timeout` (SIGALRM) というハード上限があるが、
web リクエスト経路には**それが無い**。PHP の `max_execution_time` は Unix では
ソケット待ちの時間を数えないため、外部 SDK が応答しない間 php-fpm ワーカーは占有され続ける。

| web 経路 | 呼び出し | 現状の実効上限 |
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

AWS 側は `array{http: array{connect_timeout: int, timeout: int}, retries: array{mode: string, max_attempts: int}}`
のような shape を返す static メソッド経由で配線し、config 3 箇所へ定数を手で撒かない
(綴りずれを PHPStan level 10 で落とす)。

### 2. 「制御系」と「データ系」で上限を分ける

一律の総時間上限は誤りである。S3 の `readStream` / `writeStream` は
**本文サイズに比例して時間がかかる**ため、短い総時間上限は正当なレンダ素材の
ダウンロード/アップロードを殺す。したがって:

| 面 | connect_timeout | timeout | max_attempts | 実効上限 | 根拠 |
|---|---|---|---|---|---|
| Stripe (プロセス大域) | 10s | 30s | 1 (`max_network_retries = 0`) | **30s** | 単一オブジェクトの create/retrieve/pay のみ。SDK 既定 80s は大きな一括処理向けの値 |
| AWS 制御系 (SES 送信 / SNS) | 5s | 15s | 2 | **30s + backoff** | 転送量が有界。既存の SNS 証明書取得 pin (`AwsSnsSignatureVerifier`: connect 5 / timeout 10) と同じ帯 |
| AWS データ系 (s3 disk のクライアント既定) | 10s | 900s | 2 | 1800s + backoff (worker `--timeout` が上限) | 転送量が本体サイズに比例する。ハングの有界化が目的で、正当な転送を殺さない値 |
| S3 制御系 (per-command 上書き) | 5s | 15s | **0 retries** | **15s** | web 同期 API では SDK 内で粘らせず、アプリ側で失敗を返して再操作を促す |

`@http` / `@retries` の per-command 上書きが効くことは vendor で確認済み
(`Aws\AwsClient::getCommand()` が `@http` を `+=` で合成 = 渡した側が勝つ /
`Aws\RetryMiddleware` と `RetryMiddlewareV2` が `$command['@retries']` を読む)。

### 3. 「生の S3Client を握れる口」を deny-by-default の目録にする

上の per-command 上書きは、**将来 web 経路に足された metadata 操作が
データ系の 900s を継承する**という穴を持つ。これを構造で塞ぐ。

生の SDK クライアントを得る口は 2 パターンしかない —
`Storage::disk(...)->getClient()` と `new \Aws\…Client(...)`。
gate はこの 2 パターンを `app/` 全体から token 走査で全数列挙し、目録との**対称差ゼロ**を要求する。
各 entry には「per-command 制御系 option を必ず渡す (pinned)」か
「型付き enum 免除 + 30 文字以上の根拠」を要求する。
これで新しい metadata 操作を足すときに**必ず目録を通る**。

### 4. 帯 (retry_after / worker --timeout) を pin 値から導く

pin 後の既定接続 `database` の外部呼び出し実効上限:

- Stripe: 30s × 最大 8 呼び出し (`ExecuteAutoRechargeAttemptJob` の最長経路。
  customer retrieve / invoice create / invoiceItem create / finalize / pay /
  paymentIntent retrieve / 後始末の void 等) = **240s**
- SES (メール送信ジョブ): 15s × 2 attempts = **30s**

したがって既定接続の外部予算を **240s** と宣言し、序列を
`外部予算 240 < worker --timeout 300 < retry_after 360` へ張り替える
(現行 `400 < 540 < 600`)。回収遅延の代償は **最大 510s → 最大 270s** に縮む。

既存 gate の前提は新値でも成立する
(`AutoRechargeService::LOCK_TTL_SECONDS = 180 < 360` / `uniqueFor = 30 < 360`)。

### 5. deny-by-default の目録 gate を置く

`tests/Architecture/ExternalClientTimeoutInventoryTest.php` を新設し、
既存の `ThrottleCoverageInventoryTest` / `QueuedJobLeaseInventoryTest` /
`BillingGatewayFailureTaxonomyInventoryTest` と同じ形で

- **AWS SDK クライアントの構築点・取得点の全数**を目録に登録させる (対称差ゼロ = exact fit)
- 免除は型付き enum + 30 文字以上の根拠を必須にする
- pin 値が **SDK 既定値と異なる**ことを負のコントロールとして固定する
  (「pin していないのに green」を検出する)
- config を読むだけで終わらせず、**実際に構築したクライアントに値が届いている**ことを
  behavioral に検査する (`AwsClient::getCommand()` の `@http` / `@retries`、
  `ApiRequestor::httpClient()->getTimeout()`)
- 帯の序列 (外部予算 < worker `--timeout` < `retry_after`) を機械で固定する

## 期待効果

### 主 (可用性)

| 経路 | 現状の実効上限 | 変更後の実効上限 |
|---|---|---|
| 撮影テイク登録の S3 `HeadObject` (web) | 無制限 × 3 attempts | **15s** (timeout 15s × 1 attempt) |
| SNS 購読確認 (web) | 無制限 × 3 attempts | **30s + backoff** (15s × 2 attempts) |
| Stripe API (web / webhook) | 80s × 1 attempt | **30s** × 1 attempt |
| SES 送信 (queue) | 無制限 × 3 attempts | **30s + backoff** (15s × 2 attempts) |
| S3 本文 read/write (queue) | 無制限 × 3 attempts | 900s × 2 attempts (worker `--timeout` が上限) |

> **誇張しない断り書き**: 「実効上限」は SDK が待つ時間の上限であり、
> retry 間の backoff (legacy mode の指数バックオフ) を含まない。
> attempts が 2 以上の面は「timeout × attempts + backoff」と読むこと。

### 従 (回収遅延)

- 既定キュー接続のリース切れ回収遅延: 最大 600s → **最大 360s**
  (T127 が問題にした代償 510s → 270s)

### 構造

- 「外部 SDK の待ち上限が SDK の既定値に依存している」という**無宣言の状態を解消**し、
  SDK バージョン更新で既定値が動いても静かに帯が壊れなくなる。

## 実装方針（概要）

Codex Round 1 の助言に従い、**同一 PR 内で実装順を分ける** (中間状態を残さないため PR は 1 本)。

| 順 | 施策 | 対象 |
|---|---|---|
| 1 | pin 値の単一出典クラス新設 | `app/Support/ExternalClientTimeouts.php` |
| 2 | Stripe のプロセス大域 pin (timeout / connect / `maxNetworkRetries`) | `app/Providers/AppServiceProvider.php` (`boot()`) |
| 3 | AWS クライアント 3 構築点へ `http` / `retries` を配線 | `config/filesystems.php` / `config/services.php` / `AppServiceProvider` (SnsClient) |
| 4 | `headObject` の per-command `@http` + `@retries` 上書き | `app/Services/Capture/TakeObjectStorage.php` |
| 5 | deny-by-default 目録 gate + 免除 enum 新設 | `tests/Architecture/ExternalClientTimeoutInventoryTest.php` ほか |
| 6 | worst-case 表の更新 (帯を動かす前に根拠を先に置く) | `docs/architecture.md` |
| 7 | 既定接続の帯の張り替え | `config/queue.php` / `mprocs.yaml` |
| 8 | 既存 lease invariant の更新 (literal 600 の assert 等) | `tests/Architecture/QueueWorkerLeaseInvariantTest.php` |

## 制約・前提

- **Stripe の pin はプロセス大域にしか置けない**。`Cashier::stripe()` /
  `$organization->stripe()` / `AppServiceProvider` の `PriceService` bind という
  3 系統の呼び出し口があるが、いずれも `ApiRequestor::httpClient()` (static) を通るため、
  大域 pin 1 本で全経路を覆える。**逆に「クライアントごとに違う timeout」は SDK が支えない**
  (この非対称を誇張して書かない)。**テナント別 timeout は設計として持たない**。
- **大域状態の汚染を作らない**。gate は `ApiRequestor::httpClient()` の **getter しか触らない**
  (`setTimeout()` / `setHttpClient()` をテストから呼ばない) ため、退避・復元 helper は要らない。
  pin が実際に効いていることは mutation (pin 行の削除で赤化) で確認する。
- **テストレーンから実 API を叩かない**。`Http::` 経由の egress guard は AGENTS.md の記述どおり
  Stripe SDK / AWS SDK には効かないため、gate は `getCommand()` (送信しない) と
  getter 参照だけで完結させる。Stripe/S3 の fake 配線
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
- **本番 supervisor はリポジトリ外 = 運用上の破壊的変更**。帯を動かすデプロイは
  **`--timeout=300` を supervisor に反映してから `retry_after=360` をデプロイする**
  (逆順にすると `--timeout 540 > retry_after 360` の期間ができ、規則 1 違反の
  二重取得の窓が開く)。この順序を `docs/architecture.md` に明記する。
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

## Round 2 で確認したいこと

1. [Critical] 2 件の対応は指摘の意図を満たしているか。特に Critical 2 について、
   「web 同期経路から呼ばれる S3 操作」を目録にする代わりに
   「**生の SDK クライアントを握れる口 (`->getClient()` / `new \Aws\…Client(`)**」を
   exact-fit の目録にする置き換えは、危険 (900s の継承) を構造的に塞げているか。
   偽陰性 (目録を通らずに 900s を継承する経路) が残っていないか指摘してほしい。
2. Stripe 大域 pin のテスト隔離について、「テストが setter を呼ばないので退避・復元 helper は要らない」
   という反論は妥当か。
3. 帯の新値 (外部予算 240 < worker `--timeout` 300 < `retry_after` 360) と、
   デプロイ順序 (supervisor 先行) の扱いに穴はないか。
4. 残る [Warning] / [Suggestion] で未対応のものがあれば指摘してほしい。
