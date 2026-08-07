# 対応マトリクス: design-review Round 1

## [Critical] 施策 5: 目録の型定義 (`array{surface: …, rationale: …}`) と実例 (tuple) が不一致

- 判断: **対応する**
- 根拠: 指摘のとおり。PHPStan level 10 で確実に落ちるうえ、施策 7 の `$e[0]` 参照も
  同じ不整合を伝播していた。
- 対応内容: **named array に統一**した
  (`['surface' => S3OperationSurface::Bulk, 'rationale' => '…']`)。
  施策 7 の参照も `$entry['surface']` に統一。
  さらに目録の正本を `tests/Support/Storage/S3SurfaceInventory.php` の
  **static メソッド**に置き、Architecture テストと Feature テストの両方がそこを読む
  (Pest の `--parallel` はファイル単位でプロセスを分けるため、
   他テストファイルのグローバル定数を参照しない — 既存
   `QueuedJobLeaseInventoryTest` のコメントと同じ規律)。

## [Critical] 施策 5: `tests/Support/PhpTokenScan.php` への共通化が変更箇所に入っていない (波及の過少申告)

- 判断: **対応する**
- 根拠: 指摘のとおり。共通化するなら既存 `QueuedJobLeaseInventoryTest` も変更対象である。
  T131 が走査母集団を `Tests\Support\QueuedJobPopulation` へ 1 本化した前例があり、
  「同じ実装を 2 本持たない」方向は本リポジトリの作法と一致する。
- 対応内容: 施策 5 の変更ファイル一覧へ
  `tests/Support/PhpTokenScan.php` (新規) と
  `tests/Architecture/QueuedJobLeaseInventoryTest.php` (既存・delegate 化) を追加し、
  **既存 gate の回帰確認**（`composer test -- --filter=QueuedJobLeaseInventoryTest`）を
  テスト計画に明記した。切り出すのは
  `token_get_all()` の正規化 (`normalize()`) **だけ**に限り、
  `jobLeaseConnectionDeclarationSites()` 等の意味解析には触れない
  (既存 gate の振る舞いを変えない最小の共通化)。

## [Critical] 施策 6: `@implements ClientInterface` は不正 (generic interface ではない)

- 判断: **対応する**
- 対応内容: `@implements` を削除。`implements ClientInterface` の宣言と、
  `request()` の**全引数**に対する `@param` PHPDoc（vendor 契約に合わせる）だけにした。

## [Warning] 施策 3: `services.ses` に AWS client option とアプリ設定が混在する

- 判断: **一部反論・一部対応**
- 根拠: 提案された「`services.ses.client_options` へ分離」は**実装できない**。
  `Illuminate\Mail\MailManager::createSesV2Transport()` は
  `array_merge(config('services.ses'), ['version' => 'latest'], $config)` を
  `Arr::except(…, ['transport'])` してから **そのまま `new SesV2Client(...)`** へ渡す実装で、
  ネストした `client_options` キーは AWS の `ClientResolver` から見て未知キーになり
  **無視される** (= pin が効かない)。混在は Laravel 側の契約であり、
  既に `options` / `sns_topic_arns` も同じ配列に同居している。
- 対応内容: Codex 自身が示した代替
  「Laravel 標準が素通し必須なら、その vendor 契約を gate 名に明記する」を採る。
  - config のコメントに `MailManager::createSesV2Transport()` を名指しで書く
  - behavioral テストの名前を
    **`vendor 契約: MailManager は services.ses を SesV2Client の構築引数へ素通しする`**
    にして、Laravel 側が strict になった瞬間に赤くなる形にする

## [Warning] 施策 3: `Storage::build()` から `S3Client` への到達方法が曖昧

- 判断: **対応する**
- 対応内容: テスト計画を
  `$disk = Storage::build([...]); Assert::isInstanceOf($disk, AwsS3V3Adapter::class); $client = $disk->getClient();`
  まで具体化した (既存 `TakeObjectStorage::client()` と同じ到達手順)。

## [Warning] 施策 5: scanner の母集団仕様が粗い

- 判断: **対応する**
- 対応内容: scanner 仕様を「**検出 token 種別 × 検出対象 namespace/class**」の表へ分解して
  明文化した。加えて失敗メッセージに**検出理由 (どの規則で拾ったか) とファイル:行**を
  出す設計を明記した (維持できない gate は形骸化するため)。

## [Warning] 施策 6: dataset ごとの期待結果が未定義

- 判断: **対応する**
- 対応内容: dataset の各行に
  `期待 terminal status` / `期待例外の有無` / `期待呼び出し回数の上限` を持たせ、
  呼び出し回数だけでなく**経路が意図どおり終端したこと**も assert する形にした。

## [Warning] 施策 7: spy の継承が壊れやすい

- 判断: **一部対応** (interface 抽出はしない)
- 根拠: `TakeObjectStorage` の contract 化は本タスクの主目的 (timeout の有限化) と無関係で、
  `FakeTakeObjectStorage` / `FakeExternalsServiceProvider` / `ExternalFakeWiringInvariantTest` に
  波及する大きな変更になる。AGENTS.md 思考原則 2 (今必要なものだけ作る) により今回は抽出しない。
  なお `TakeObjectStorage` は既に `FakeTakeObjectStorage` が継承しており、
  「継承で差し替える」形自体は本リポジトリの既存作法である。
- 対応内容: Codex の代替案「未 override の public method があれば fail させる」を採る。
  spy のテスト内で `S3SurfaceInventory` のメソッド一覧と
  `(new ReflectionClass($spy))->getMethods(PUBLIC)` の**宣言クラス**を突き合わせ、
  未 override があれば fail させる (親にメソッドが増えたら気づける)。

## [Warning] 施策 9: 確認コマンドが `queue:work` だが mprocs は `queue:listen`

- 判断: **対応する**
- 根拠: 指摘のとおり。`mprocs.yaml` は **dev** の `queue:listen`、
  本番 supervisor は `docs/architecture.md` が `queue:work` を指定している (両方存在する)。
- 対応内容: 確認コマンドの grep を `queue:(work|listen) database` 相当に広げ、
  「mprocs = dev / supervisor = 本番」を明記した。

## [Warning] 施策 9: 手順 1 の実施タイミングの制約が弱い

- 判断: **対応する**
- 対応内容: runbook に
  「低トラフィック時間帯に実施」「`jobs` テーブルの `database` キュー未処理件数が 0 に近いこと」
  「オートリチャージのリコンサイル (失敗 attempt の残留) を実施前後で確認」を追加した。

## [Suggestion] 施策 1: `AWS_MAX_ATTEMPTS` の語彙 (`max_attempts` vs `@retries`) を明示

- 判断: **対応する**
- 対応内容: 定数の PHPDoc に vendor 実査の結果を書いた —
  `Aws\Retry\ConfigurationProvider::unwrap()` の array 形式は `max_attempts` = **初回を含む試行回数**、
  `_apply_retries()` は legacy で `maxAttempts - 1` を retry 数に使う。
  一方 per-command の `@retries` は **retry 回数**（0 = 再試行しない）。
  同じ「2」でも意味が違うことを明記する。

## [Suggestion] 施策 2: 復元する `$originalClient` の nullable

- 判断: **対応する (確認のうえ非 nullable と確定)**
- 根拠: vendor 実査。`ApiRequestor::httpClient()` は
  `if (!self::$_httpClient) { self::$_httpClient = HttpClient\CurlClient::instance(); }` の遅延生成で
  **null を返さない**。`setHttpClient($client)` も nullable を受けない。
- 対応内容: 設計に「`httpClient()` は遅延生成のため null を返さない (vendor 実査)」を明記した。

## [Suggestion] 施策 8: テスト名を「class-based 分類の固定」であると明示

- 判断: **対応する**。テスト名を
  `Stripe の接続断/timeout は ApiConnectionException の class 分類で ProviderUnavailable になる` に変更した。
