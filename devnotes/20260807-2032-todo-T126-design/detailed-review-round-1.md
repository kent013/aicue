全体判定: **CHANGES_REQUESTED**

主因は、施策 5 の gate 設計が実装困難かつ PHPStan/実行時で崩れやすい点、施策 6 の Stripe fake が vendor interface 契約と fixture 前提をまだ詰め切れていない点、施策 7 がテストから別テストの目録に依存する構造になりかけている点です。

**施策別判定**
1. pin 値の単一出典クラス: **APPROVE**
   - [Suggestion] `AWS_MAX_ATTEMPTS` は AWS SDK の `max_attempts` が「初回を含む試行回数」か「retry 回数」かを docs/コメントで明示してください。`@retries = 0` との語彙差が混乱源になります。

2. Stripe provider: **APPROVE**
   - [Suggestion] テストで復元する `$originalClient` が `null` を取り得る SDK 実装なら型上の扱いを確認してください。`ApiRequestor::setHttpClient()` が nullable を受けない場合、復元方法を SDK 既定 client の再設定に寄せる必要があります。

3. AWS 3 構築点: **REQUEST_CHANGES**
   - [Warning] `config/services.php` の `ses` 配列へ `http` / `retries` を足す設計は、Laravel Mail の SES transport がその配列をそのまま `SesV2Client` に渡す前提に依存していますが、同じ配列に既にある `options` / `sns_topic_arns` は transport 用のアプリ設定であり、AWS client option と混在します。将来 Laravel 側が strict に扱うと壊れます。
     - 修正案: `services.ses.client_options` などに分離し、SES transport 構築が本当にその値を読む箇所を確認した上で配線してください。もし Laravel 標準が素通し必須なら、その vendor 契約を gate 名に明記してください。
   - [Warning] `Storage::build(...)` から構築 client の `getCommand()` を見る behavioral テスト案が曖昧です。`Storage::build()` は Filesystem adapter を返すため、`S3Client` への到達方法を設計に明記しないと実装時にテストが崩れます。
     - 修正案: `Assert::isInstanceOf($disk, AwsS3V3Adapter::class); $disk->getClient()` まで具体化してください。

4. `headObject` per-command: **APPROVE**
   - [Suggestion] `@retries = 0` が AWS SDK v3 の現在の RetryMiddleware / RetryMiddlewareV2 で有効なことを、vendor 実査だけでなく behavioral テストで `@retries` が command に残るところまで固定する方針は妥当です。

5. 面分類 enum / 免除 enum / gate: **REQUEST_CHANGES**
   - [Critical] `S3_OPERATION_SURFACE_INVENTORY` の骨子が `array{surface: S3OperationSurface, rationale: string}` と書きながら、実例は `[S3OperationSurface::NoObjectRequest, '...']` の tuple です。施策 7 でも `$e[0]` 参照になっており、型定義と実体が不一致です。PHPStan level 10 で確実に問題になります。
     - 修正案: どちらかに統一してください。推奨は `['surface' => S3OperationSurface::Bulk, 'rationale' => '...']` です。施策 7 も `$entry['surface']` に統一。
   - [Critical] `tests/Support/PhpTokenScan.php` への共通化が「施策 5 の変更箇所」に入っていません。既存 `QueuedJobLeaseInventoryTest` 側も変更対象になるため、波及範囲が過少申告です。
     - 修正案: 変更ファイル一覧に `tests/Support/PhpTokenScan.php` と既存 token scan 利用テストの更新を追加し、既存 gate の回帰テストも対象に含めてください。
   - [Warning] 到達境界 scanner が何を母集団にするかがまだ粗いです。`use Aws\...`、型宣言、`Storage::disk()`、`getClient()`、`new S3Client`、`Filesystem` 型などが混在しており、検出条件を誤ると fake や value object だけ拾って肝心の container 解決を漏らします。
     - 修正案: scanner の仕様を「検出 token 種別」と「検出対象 namespace/class」に分解して明文化してください。fixture テストだけでなく、実 app 走査の検出理由も failure message に出す設計にすると維持できます。

6. Stripe 呼び出し回数 behavioral 固定: **REQUEST_CHANGES**
   - [Critical] `CountingStripeHttpClient` に `@implements ClientInterface` とありますが、Stripe の `ClientInterface` は generic interface ではありません。PHPStan で無意味または不正な PHPDoc になる可能性があります。
     - 修正案: `@implements` を削除し、`implements ClientInterface` と `request()` の PHPDoc/実シグネチャだけにしてください。
   - [Warning] `request()` の引数型が未宣言のため level 10 で mixed 周りが厳しくなります。vendor interface に型が無い場合でも、PHPDoc に `@param` を全引数分入れてください。
     - 修正案: `$method`, `$absUrl`, `$params`, `$hasFile`, `$apiMode`, `$maxNetworkRetries` の PHPDoc を vendor 契約に合わせて追加。
   - [Warning] `executeAttempt()` が失敗経路で例外を投げる/終端状態にする場合、dataset ごとの期待結果が未定義です。呼び出し数だけを見ると、途中失敗で `isExhausted()` が false になる以外の診断が弱いです。
     - 修正案: dataset に期待 terminal status / 期待例外有無 / 期待 call count 上限を持たせてください。

7. web 経路が `Bulk` を呼ばない固定: **REQUEST_CHANGES**
   - [Warning] spy が `TakeObjectStorage` を継承して public method を override する設計は、親の constructor 追加や final 化で壊れやすいです。
     - 修正案: 可能なら interface 抽出済みの storage contract に差し替える。未抽出なら今回抽出しない判断でもよいですが、spy の override 対象を `S3SurfaceInventory` から生成的に検査し、未 override public method があれば fail させてください。
   - [Warning] 施策 5 の目録形式不一致の影響を受けています。
     - 修正案: `tests/Support/Storage/S3SurfaceInventory.php` を正本にし、tuple ではなく named array に統一。

8. timeout 例外分類固定: **APPROVE**
   - [Suggestion] message 文字列を timeout らしくしているだけで分類は class-based なので、テスト名に「timeout message ではなく ApiConnectionException の分類固定」と書くと意図がより正確です。

9. 帯の張り替え + docs + gate 更新: **REQUEST_CHANGES**
   - [Warning] `mprocs.yaml` は `queue:listen` ですが、デプロイ順序の確認コマンドは `queue:work database` になっています。dev と本番 supervisor の運用が違うなら問題ありませんが、設計書内では確認対象がずれています。
     - 修正案: 本番が `queue:work` なら「mprocs は dev、supervisor は本番」と明記。両方あり得るなら grep pattern を `queue:(work|listen) database` 相当にしてください。
   - [Warning] 手順 1 で旧コードを `--timeout=300` に先行変更すると、設計書自身も認める通り旧 Stripe 80s 経路が SIGALRM されます。受容事項としては書かれていますが、実施タイミングの制約が弱いです。
     - 修正案: 低トラフィック時間帯、リコンサイル監視、対象 job の未処理件数確認を deploy runbook に追加してください。

**補足**
DTO/JsonResource、Inertia Props、frontend/DESIGN/Atomic Design は今回の変更範囲では対象外という判断で問題ありません。セキュリティ面では、外部 SDK の待ち上限を pin し、web 同期経路から Bulk S3 操作を排除する方向性自体は妥当です。ただし deny-by-default gate は「強いが壊れやすい」部品なので、目録形式・scanner 仕様・共通 helper の波及を詰めてから実装に入るべきです。