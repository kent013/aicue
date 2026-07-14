## アプリの使命（North Star）

**AI-CUE** は、現場に既にある**作業手順書(SOP)を起点に**、AI が撮るべきカットを設計した**動画シナリオ**を生成し、そのシナリオを**スマホ(PWA)でナビゲーション撮影**することで、専門知識ゼロの現場作業者でも**標準化されたマニュアル動画**を作れるようにする。

- 「思考ゼロ・編集ゼロ」— 台本作成・撮影判断・編集の 3 ハードルを AI とナビ撮影で肩代わりする。
- 競合(OJT を撮って形式化する tebiki)と異なり、**標準作業を起点に AI が教材設計し撮影を指示する**。
- 熟練者の暗黙知を動画マニュアルという形式知へ変換する装置（SECI）。
- v1 スコープ: 字幕のみ / 撮影は PWA(同一オリジン・セッション認証) / 動画合成は自前 ffmpeg / 単一 Default Project。

## 禁止事項（自分・実装双方に適用）

1. テストなしの実装完了報告（不変条件は Architecture/Feature テストへの登録まで含めて「実装済み」）
2. PHPStan エラーの widen（型を緩めて黙らせる）・baseline 化
3. dev DB への破壊操作（migrate:fresh 等）をエージェント判断で実行すること
4. `response()->json()` の直書き（DTO / JsonResource / Inertia を使う。仕様固定 endpoint のみ例外）
5. LLM 呼び出しの Prism 直呼び（app/Prompts/ の factory 経由のみ）
6. prompt 文字列のコード直書き（resources/prompts/*.yaml に置く）
7. 操作系 POST の応答での redirect()->intended()
8. 必須条件未充足を理由にボタンを disabled にする UI

**セキュリティ不変条件（関連）**: tenant キー不信 / 子は親に属する（不整合は認可より前に 404）/ cross-org 不可 / 外部 URL 取得は SSRF 検査経由。

【思考原則 — 全議論に適用】
まず仮説を立てろ。何を検証したいのか、なぜそう考えるのか、どうなれば成功と判断するのかを明確にしてから手を動かせ。
先人の知恵を探せ。乗るべき巨人の肩があるなら乗れ。
機能の名前に立ち返れ。名前はその機能が果たすべき役割を示している。現在の設計がその役割を果たしているか、常に問え。

【ツール使用制限】
コマンド実行・ファイル書き込みは一切行わず、提供されたテキストの分析に集中すること。ファイル読み込みは許可。

---

## system: あなたの役割

あなたは Laravel 12 + Svelte 5 + Inertia アプリのコードレビュアーです。以下の実装差分をレビューしてください。

本 item (T038) は **bug-hunt 環境のテイク/レンダ動画 storage を実 S3 非依存の fake へ配線** する変更です。bughunt (既定 `fake_storage=true`) で `TakeObjectStorage` / `RenderObjectStorage` を実 S3 に一切出ない fake サブクラスへ container bind し、presigned PUT をローカルディスク `s3_fake` + アプリ内 signed route で emulate します。実 S3 経路 (`--real-storage`) と DTO 契約は不変です。

### レビュー観点
1. **設計との一致性**: 添付の詳細設計書どおりか（gate predicate SSOT、FakeObjectStore の checksum 三点照合・completion marker・key ロック、provider の capability 別分離、signed route の多層防御）。
2. **正確性・堅牢性**: fail-secure（本番で fake が解決されない三重防御）、ストリーム処理・容量上限・checksum 照合・atomic promote・sidecar 整合、traversal 防御、LSP drift（fake が S3 到達メソッドを漏れなく override）。
3. **PHPStan level 10 適合**（`@phpstan-ignore` / widen / baseline は禁止）。
4. **DTO / JsonResource パターン**（`response()->json()` 直書き禁止。noContent/file/abort は対象外）。
5. **テスト網羅性**（禁止事項1: テストなし完了報告の禁止。正常系・異常系・契約・drift・並行）。
6. **セキュリティ**（signed middleware、実行時 gate 再検証、key allowlist、ヘッダ注入不能）。
7. **不必要な複雑化**の有無。

### 出力形式
- ファイルごとに判定。指摘は **[Critical] / [Warning] / [Suggestion]** で分類。
- 各指摘に根拠（なぜ問題か・どう直すか）を付す。
- 末尾に**全体判定**を `APPROVED` または `CHANGES_REQUESTED` で明示。

---

## user

### テスト結果サマリー
- `composer test`（PHP, --parallel）: **1715 tests, 1713 passed, 2 skipped, 0 failed**
- `composer phpstan`（level 10, app/ のみが解析対象）: **No errors**
- `vendor/bin/pint --test`: **passed**
- `pnpm lint` / `pnpm typecheck` / `pnpm build`: **passed**（フロント差分なし）
- 新規テスト内訳: FakeStorageGateTest(6) / FakeStorageKeyTest(4) / FakeObjectStoreTest(9) / FakeObjectStoreConcurrencyTest(3) / FakeStorageContractTest(6, reflection drift) / FakeStorageRouteTest(10, signed route E2E) / FakeStorageWiringTest(1) / FakeStorageWiringDefaultTest(1)

### 補足（設計からの実装判断）
- 詳細設計の `withKeyLock(int $operation)` は PHPStan の `flock` 期待型 `int<0,7>` を満たすため `bool $exclusive` に変更し、内部で `LOCK_EX`/`LOCK_SH` を選ぶ（literal 型で range を満たす）。
- `decodeMeta` の sidecar 破損検知は Webmozart Assert（`InvalidArgumentException`）ではなく明示 `if` で **一様に `RuntimeException`** を投げる（設計の「fail-loud (RuntimeException)」契約に合わせるため）。
- Feature テストの gate 有効化は、専用ベースクラス方式が Pest の `pest()->extend(TestCase)->in('Feature')` と衝突するため、`enableFakeStorage()` ヘルパで **provider 自身を再実走** させ bind + signed route を確立する（手動 bind/route 再実装はしていない。フレームワークが route 読込後に行う name lookup 再構築のみ `refreshNameLookups()` で補う）。

以下に詳細設計書と実装差分を添付する。


## 詳細設計書

```markdown
# 詳細設計: bughunt-take-storage-fake

## 使命・制約（絶対遵守）

### アプリの使命（North Star）

**AI-CUE** は、現場に既にある**作業手順書(SOP)を起点に**、AI が撮るべきカットを設計した**動画シナリオ**を生成し、そのシナリオを**スマホ(PWA)でナビゲーション撮影**することで、専門知識ゼロの現場作業者でも**標準化されたマニュアル動画**を作れるようにする。

- 「思考ゼロ・編集ゼロ」— 台本作成・撮影判断・編集の 3 ハードルを AI とナビ撮影で肩代わりする。
- 競合(tebiki)と異なり、標準作業を起点に AI が教材設計し撮影を指示する。
- 熟練者の暗黙知を動画マニュアルという形式知へ変換する装置（SECI）。
- v1 スコープ: 字幕のみ / 撮影は PWA(同一オリジン・セッション認証) / 動画合成は自前 ffmpeg / 単一 Default Project。

### 禁止事項

1. テストなしの実装完了報告（不変条件は Architecture/Feature テストへの登録まで含めて「実装済み」）
2. PHPStan エラーの widen（型を緩めて黙らせる）・baseline 化
3. dev DB への破壊操作（`migrate:fresh` 等）をエージェント判断で実行すること
4. `response()->json()` の直書き（DTO / JsonResource / Inertia を使う。仕様固定 endpoint のみ例外）
5. LLM 呼び出しの Prism 直呼び（`app/Prompts/` の factory 経由のみ）
6. prompt 文字列のコード直書き（`resources/prompts/*.yaml` に置く）
7. 操作系 POST の応答での `redirect()->intended()`
8. 必須条件未充足を理由にボタンを disabled にする UI

**セキュリティ不変条件（関連）**: tenant キー不信 / 子は親に属する（不整合は認可より前に 404）/ cross-org 不可。

### コーディングルール

- **PHPStan level 10** 必須（`composer phpstan`）
- **Pest**（`composer test`）/ **RefreshDatabase** グローバル + `--parallel`（個別 `DatabaseTransactions` 禁止）
- テストデータは Factory 生成 / DTO + JsonResource パターン / アーリーリターン
- `declare(strict_types=1)` + 日本語コメント / Controller は薄く（Service 委譲）
- PHP 8.4 + Laravel 12 + Svelte 5 + Inertia.js + TypeScript
- 検証: `composer test` / `composer phpstan` / `vendor/bin/pint --test`

## 概念設計リファレンス

`devnotes/20260714-1049-bughunt-take-storage-fake/conceptual-design.md`（Codex gpt-5.4 で APPROVED / Round 3）

---

## 全体像

bughunt（既定 `fake_storage=true`・`AWS_DEFAULT_REGION` 未設定）で、`TakeObjectStorage` / `RenderObjectStorage` を**実 S3 に一切出ない fake サブクラス**へ container bind し、presigned PUT を**ローカルディスク `s3_fake` + アプリ内 signed route** で emulate する。実 S3 経路（`--real-storage`）と DTO 契約は不変。

**中核コンポーネント**:
- `FakeStorageGate`（純粋クラス・predicate SSOT）: `fake_storage===true && (env==='bughunt.local' || (env==='testing' && runningUnitTests))`。route 登録と action guard が共有。
- `FakeObjectStore`（共有サービス）: `s3_fake` disk 上の put(stream)/head/delete/exists/path を集約。sidecar meta codec・容量上限・atomic move・checksum 三者一致をここに閉じる。
- `FakeTakeObjectStorage extends TakeObjectStorage` / `FakeRenderObjectStorage extends RenderObjectStorage`。
- `PutFakeStorageObjectController` / `GetFakeStorageObjectController`（signed route）。
- `FakeExternalsServiceProvider`: gate 成立時のみ bind + route 登録。

---

## 施策一覧

| # | 施策名 | 変更/新規ファイル | 優先度 |
|---|--------|------------------|--------|
| 1 | `s3_fake` local disk 追加 | `config/filesystems.php` | High |
| 2 | `FakeStorageGate`（predicate SSOT） | `app/Support/FakeStorageGate.php`（新規） | High |
| 3 | `FakeObjectMeta` VO + `FakeObjectStore` 共有サービス | `app/Services/Storage/Fakes/FakeObjectMeta.php` / `FakeObjectStore.php`（新規） | High |
| 4 | `FakeTakeObjectStorage` | `app/Services/Capture/Fakes/FakeTakeObjectStorage.php`（新規） | High |
| 5 | `FakeRenderObjectStorage` | `app/Services/Render/Fakes/FakeRenderObjectStorage.php`（新規） | High |
| 6 | signed route コントローラ（PUT 受け口 / GET serve）+ `FakeStorageKey` 検証ヘルパ | `app/Http/Controllers/Testing/PutFakeStorageObjectController.php` / `GetFakeStorageObjectController.php` / `app/Services/Storage/Fakes/FakeStorageKey.php`（新規） | High |
| 7 | provider 配線（bind + 条件付き route 登録） | `app/Providers/FakeExternalsServiceProvider.php` | High |
| 8 | `ProductionEnvGuard` の不変確認（変更なし・テストで固定） | `app/Support/ProductionEnvGuard.php`（確認のみ） | Med |
| 9 | テスト（Feature/Unit/契約/drift） | `tests/...`（新規） | High |

---

## 施策 1: `s3_fake` local disk 追加

### 変更箇所
- ファイル: `config/filesystems.php`（`disks` 配列に追記）

### 波及変更
- TypeScript型定義: なし
- API Resource/DTO: なし
- テストファイル: `Storage::fake('s3_fake')` を各 Feature/Unit テストで使用（施策 9）

### 変更後コード（追記）
```php
'disks' => [
    // ... local / public / s3 は不変 ...

    // bughunt / testing の storage fake 用ローカル disk (実 S3 非依存の emulation)。
    // TakeObjectStorage-fake / RenderObjectStorage-fake が共有し S3 key namespace を再現する。
    // 本番では誰も解決しない (fake bind されない限り inert)。throw=true で失敗を握り潰さない。
    's3_fake' => [
        'driver' => 'local',
        'root' => storage_path('app/s3-fake'),
        'throw' => true,
        'report' => false,
    ],
],
```

### PHPStan適合チェック
- [x] 純 config 配列（型なし）。他コードへの影響なし

### リスク
- 本番に disk 定義が残るが、fake bind が無ければ誰も参照しない（inert）。`FILESYSTEM_DISK` の default（`local`）は不変で bughunt self-check（`filesystems.default==='local'`）と非干渉。

---

## 施策 2: `FakeStorageGate`（predicate SSOT）

fail-secure predicate を 1 箇所に集約し、route 登録側（provider）と実行時 guard（controller）が同一メソッドを参照する（Codex Round 2 指摘: 登録条件と実行時条件の一致）。

### 新規ファイル: `app/Support/FakeStorageGate.php`
```php
<?php

declare(strict_types=1);

namespace App\Support;

use Illuminate\Contracts\Foundation\Application;

/**
 * storage fake の有効化 predicate の SSOT (fail-secure 二軸)。
 * route 登録 (FakeExternalsServiceProvider) と signed route action guard の双方が
 * 本メソッドを参照する (登録条件より実行時条件が弱いと route cache 残存で素通りするため)。
 *
 * 二軸:
 * 1. capability flag: config('testing.fake_storage') === true (既定 false = 完全 no-op)
 * 2. env allowlist: bughunt.local ∨ (testing ∧ runningUnitTests)
 *    - bughunt.local: 実 bug-hunt runtime
 *    - testing ∧ runningUnitTests: 自動テストのみ (testing を HTTP 実行環境として素通ししない)
 * production は ProductionEnvGuard が flag=true を deploy 時 fail-fast 拒否 (二重防御)。
 */
final readonly class FakeStorageGate
{
    public function __construct(private Application $app) {}

    public function enabled(): bool
    {
        if (config('testing.fake_storage') !== true) {
            return false;
        }

        $env = $this->app->environment();
        if ($env === 'bughunt.local') {
            return true;
        }

        return $env === 'testing' && $this->app->runningUnitTests();
    }
}
```

### PHPStan適合チェック
- [x] 戻り値型 `bool` 明示 / config 比較は `=== true`
- [x] `Application` 注入（`app()` ヘルパの mixed 回避）

### テスト計画
- Unit: flag off → false / bughunt.local + flag → true / testing + runningUnitTests + flag → true / **testing + runningUnitTests=false + flag → false**（HTTP 実行時の誤通過を固定）/ local + flag → false / production + flag → false

### リスク
- なし（純粋 predicate）

---

## 施策 3: `FakeObjectMeta` VO + `FakeObjectStore` 共有サービス

`s3_fake` disk 上のオブジェクト操作（stream 保存・head・delete・exists・path）と sidecar meta codec・容量上限・atomic move・checksum 三者一致を 1 箇所に閉じる。Take/Render の両 fake と PUT コントローラが共有する。

### 新規ファイル: `app/Services/Storage/Fakes/FakeObjectMeta.php`
```php
<?php

declare(strict_types=1);

namespace App\Services\Storage\Fakes;

/**
 * fake object の sidecar メタ (実 S3 が object metadata として持つ ContentType/Checksum の emulation)。
 * schema_version で将来の互換切りを可能にする。encode/decode は FakeObjectStore が担う。
 */
final readonly class FakeObjectMeta
{
    public const int SCHEMA_VERSION = 1;

    public function __construct(
        public string $contentType,
        public string $checksumSha256, // base64 sha256 (x-amz-checksum-sha256 と同形式)
    ) {}
}
```

### 新規ファイル: `app/Services/Storage/Fakes/FakeObjectStore.php`（要点）
```php
<?php

declare(strict_types=1);

namespace App\Services\Storage\Fakes;

use App\DataTransferObjects\Capture\ObjectMetadataData;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Support\Facades\Storage;
use JsonException;
use RuntimeException;
use Webmozart\Assert\Assert;

/**
 * s3_fake disk 上のオブジェクト操作の集約点 (fake storage 基盤)。
 * - 保存はストリーム: php://input を chunk 読みしながら sha256 を計算し一時ファイルへ書く。
 *   絶対容量上限 (config capture.max_take_bytes) を超えたら中断・一時ファイル削除・OverCapacity を投げる。
 * - checksum 三者一致は呼び出し側 (controller) が「署名パラメータ == ヘッダ」を先に検証し、
 *   本メソッドが「== 実 body」を担保する (期待 checksum を受け取り実 body と照合)。
 * - sidecar meta は object 配置の「後」に書く = completion marker (crash 途中は object あり sidecar 無し = 未完了)。
 */
final class FakeObjectStore
{
    public const string DISK = 's3_fake';

    private function disk(): Filesystem
    {
        return Storage::disk(self::DISK);
    }

    /**
     * ストリーム保存 (atomic)。$expectedChecksum は base64 sha256。
     * checksum 三者一致の 3/3 (実 body == 期待値) を担保する。
     *
     * ※ パスは必ず `disk()->path()` 基準にする (Storage::fake は root を tmp へ差し替えるため、
     *    root ハードコードだとテストと実装で別領域を書く = Round 1 Critical)。
     *
     * @param  resource  $input
     * @throws FakeStorageChecksumMismatch  実 body の checksum が期待値と不一致
     * @throws FakeStorageOverCapacity      絶対容量上限超過
     */
    public function storeStreamed(string $key, $input, string $contentType, string $expectedChecksum): void
    {
        $target = $this->disk()->path($key); // Storage::fake でも実 local でも正しい実体パス
        $this->ensureDir(dirname($target));
        $tmp = $target.'.uploading-'.bin2hex(random_bytes(8)); // 同一ディレクトリ = 同一 filesystem

        $ctx = hash_init('sha256');
        $out = fopen($tmp, 'wb');
        Assert::resource($out, 'fake storage: 一時ファイルを開けません');
        try {
            // 攻撃者由来の php://input = 絶対容量上限 (take 上限) を適用
            $this->streamInto($input, $out, $ctx, maxBytes: config()->integer('capture.max_take_bytes'));
        } catch (\Throwable $e) {
            fclose($out);
            @unlink($tmp);
            throw $e;
        }
        fclose($out);

        $actual = base64_encode(hash_final($ctx, true));
        if (! hash_equals($expectedChecksum, $actual)) {
            @unlink($tmp);
            throw new FakeStorageChecksumMismatch;
        }

        $this->promote($key, $tmp, $target, new FakeObjectMeta($contentType, $actual));
    }

    /**
     * checksum 照合なしのストリーム保存 (render 出力・take footage コピー等の**サーバ生成の内部生成物**)。
     * sidecar (content_type) を必ず生成する = GET 配信の contract を満たす (Round 1 Warning)。
     * 入力は攻撃者由来の php://input ではなく信頼できるローカルファイルのため、絶対容量上限は課さない
     * (Round 2 Suggestion: take 上限 max_take_bytes の流用は概念不一致。新 config も増やさない)。
     *
     * @param  resource  $input
     */
    public function putStreamWithMeta(string $key, $input, string $contentType): void
    {
        $target = $this->disk()->path($key);
        $this->ensureDir(dirname($target));
        $tmp = $target.'.uploading-'.bin2hex(random_bytes(8));

        $ctx = hash_init('sha256');
        $out = fopen($tmp, 'wb');
        Assert::resource($out, 'fake storage: 一時ファイルを開けません');
        try {
            $this->streamInto($input, $out, $ctx, maxBytes: null); // 信頼できる内部入力 = cap なし
        } catch (\Throwable $e) {
            fclose($out);
            @unlink($tmp);
            throw $e;
        }
        fclose($out);

        $this->promote($key, $tmp, $target, new FakeObjectMeta($contentType, base64_encode(hash_final($ctx, true))));
    }

    /**
     * $input を $out へ流しつつ sha256 を計算。$maxBytes 非 null なら超過で中断、
     * fwrite の部分書き込みは完了まで再試行。
     *
     * @param  resource  $input
     * @param  resource  $out
     */
    private function streamInto($input, $out, \HashContext $ctx, ?int $maxBytes): void
    {
        $total = 0;
        while (! feof($input)) {
            $chunk = fread($input, 1024 * 1024);
            if ($chunk === false) {
                throw new RuntimeException('fake storage: 入力ストリーム読込に失敗しました');
            }
            if ($chunk === '') {
                continue;
            }
            $total += strlen($chunk);
            if ($maxBytes !== null && $total > $maxBytes) {
                throw new FakeStorageOverCapacity($maxBytes);
            }
            hash_update($ctx, $chunk);
            $this->writeAll($out, $chunk); // 部分書き込み対策 (Round 1 Warning)
        }
    }

    /** fwrite の部分書き込みを完了まで再試行。false は例外化 */
    private function writeAll($out, string $data): void
    {
        $offset = 0;
        $len = strlen($data);
        while ($offset < $len) {
            $written = fwrite($out, substr($data, $offset));
            if ($written === false || $written === 0) {
                throw new RuntimeException('fake storage: 一時ファイルへの書き込みに失敗しました');
            }
            $offset += $written;
        }
    }

    /**
     * 確定手順。**atomic なのは object rename のみ**。object + sidecar 全体の整合は
     * key 単位の排他ロック (flock LOCK_EX) と completion marker (sidecar) で担保する。
     *
     * critical section を key ロックで直列化する理由 (Round 3 Critical): 同一 key への並行 PUT で
     * A/B が sidecar 削除→rename→sidecar 作成を interleave すると「object B + meta A」が観測され得る。
     * ロックで writer を直列化すれば、reader は常に null / (objectA,metaA) / (objectB,metaB) のいずれか。
     *
     * 手順 (ロック保持下):
     * 1. 既存 sidecar 削除 (以降 head()===null = 未完了扱い。旧 meta が新 object に付かない)
     * 2. object を atomic rename で確定
     * 3. sidecar を最後に書く (= completion marker)。失敗しても「object あり sidecar 無し = 未完了」で
     *    不整合な complete を返さない (再 PUT で回復可能)。
     */
    private function promote(string $key, string $tmp, string $target, FakeObjectMeta $meta): void
    {
        $this->withKeyLock($key, LOCK_EX, function () use ($key, $tmp, $target, $meta): null {
            $this->disk()->delete($this->sidecarKey($key)); // 冪等 (不在は no-op)
            if (! @rename($tmp, $target)) {
                @unlink($tmp);
                throw new RuntimeException('fake storage: object の確定に失敗しました');
            }
            $this->disk()->put($this->sidecarKey($key), $this->encodeMeta($meta));

            return null;
        });
    }

    /**
     * key 単位のロック下で $critical を実行する。ロックファイルは object とは別 namespace (`.locks/`) に置く
     * (object listing/GET を汚さない)。unlock/close は finally で保証。
     * $operation: LOCK_EX (writer: promote/delete) or LOCK_SH (reader: head)。
     *
     * @template T
     * @param  callable():T  $critical
     * @return T
     */
    private function withKeyLock(string $key, int $operation, callable $critical): mixed
    {
        $lockPath = $this->disk()->path('.locks/'.sha1($key).'.lock');
        $this->ensureDir(dirname($lockPath));
        $handle = fopen($lockPath, 'c');
        Assert::resource($handle, 'fake storage: ロックファイルを開けません');
        try {
            if (! flock($handle, $operation)) {
                throw new RuntimeException('fake storage: ロック取得に失敗しました');
            }
            try {
                return $critical();
            } finally {
                flock($handle, LOCK_UN);
            }
        } finally {
            fclose($handle);
        }
    }

    /**
     * HeadObject 相当。**同一 key の共有ロック (LOCK_SH) 下**で object 確認・sidecar 確認/読込・size 取得を
     * 一括で行う (Round 4 Critical: promote の LOCK_EX と排他され、reader が異世代の object/meta を
     * 組み合わせない・sidecar exists→get 間の削除で例外化しない)。状態別に固定:
     * - object 不在 → null (PUT 未着手)
     * - object あり sidecar 不在 → null (PUT 未完了 = crash 途中)
     * - sidecar 破損 (不正 JSON/欠損キー/未知 schema) → fail-loud (RuntimeException)
     */
    public function head(string $key): ?ObjectMetadataData
    {
        return $this->withKeyLock($key, LOCK_SH, function () use ($key): ?ObjectMetadataData {
            if (! $this->disk()->exists($key)) {
                return null;
            }
            if (! $this->disk()->exists($this->sidecarKey($key))) {
                return null;
            }
            $meta = $this->decodeMeta($this->disk()->get($this->sidecarKey($key)));

            return new ObjectMetadataData(
                contentLength: (int) $this->disk()->size($key),
                contentType: $meta->contentType,
                checksumSha256: $meta->checksumSha256,
            );
        });
    }

    /** object + sidecar 削除。promote と競合しないよう同一 key の LOCK_EX 下で行う (Round 4 Warning)。冪等。 */
    public function delete(string $key): void
    {
        $this->withKeyLock($key, LOCK_EX, function () use ($key): null {
            $this->disk()->delete([$key, $this->sidecarKey($key)]); // 不在は no-op

            return null;
        });
    }

    public function exists(string $key): bool
    {
        return $this->disk()->exists($key);
    }

    /** GET serve 用の絶対パス (response()->file の Range 対応のため) */
    public function absolutePath(string $key): string
    {
        return $this->disk()->path($key);
    }

    // contentTypeOf() は廃止 (Round 1 Warning): GET コントローラは head() を呼び、
    // null→404 / 値あり→contentType を使う (sidecar 欠損で 500 化しない)。

    private function sidecarKey(string $key): string
    {
        return $key.'.meta.json';
    }

    private function encodeMeta(FakeObjectMeta $meta): string
    {
        return json_encode([
            'schema_version' => FakeObjectMeta::SCHEMA_VERSION,
            'content_type' => $meta->contentType,
            'checksum_sha256' => $meta->checksumSha256,
        ], JSON_THROW_ON_ERROR);
    }

    private function decodeMeta(?string $raw): FakeObjectMeta
    {
        Assert::string($raw, 'fake storage: sidecar を読めません');
        try {
            $decoded = json_decode($raw, true, flags: JSON_THROW_ON_ERROR); // mixed
        } catch (JsonException $e) {
            throw new RuntimeException('fake storage: sidecar が不正な JSON です', previous: $e);
        }
        Assert::isArray($decoded, 'fake storage: sidecar が object ではありません'); // 実行時 narrow
        /** @var array<array-key, mixed> $data */
        $data = $decoded;
        $version = $data['schema_version'] ?? null;
        if ($version !== FakeObjectMeta::SCHEMA_VERSION) {
            throw new RuntimeException('fake storage: sidecar の schema_version が未知です');
        }
        $contentType = $data['content_type'] ?? null;
        $checksum = $data['checksum_sha256'] ?? null;
        Assert::string($contentType, 'fake storage: sidecar content_type 欠損');
        Assert::string($checksum, 'fake storage: sidecar checksum 欠損');
        // base64(sha256) = 32 バイト → base64 44 文字 (末尾 '=')。軽い形式検証で異常を早期検知
        Assert::regex($checksum, '/^[A-Za-z0-9+\/]{43}=$/', 'fake storage: sidecar checksum 形式不正');

        return new FakeObjectMeta($contentType, $checksum);
    }

    private function ensureDir(string $dir): void
    {
        if (! is_dir($dir) && ! @mkdir($dir, 0775, true) && ! is_dir($dir)) {
            throw new RuntimeException("fake storage: ディレクトリ作成に失敗しました: {$dir}");
        }
    }
}
```

補助例外（新規・同 namespace）: `FakeStorageChecksumMismatch`（`RuntimeException` 派生）/ `FakeStorageOverCapacity`（`RuntimeException` 派生、`maxBytes` 保持）。controller が catch して 4xx/413 に写像する。

**実装時の堅牢化（Round 5 非ブロッキング Suggestion・反映）**:
- `storeStreamed` / `putStreamWithMeta` は `promote()` 呼び出しを含めて `try { ... } finally { if (is_file($tmp)) @unlink($tmp); }` で囲み、`withKeyLock` のロック取得失敗等で `promote()` が例外時にも未確定 tmp を必ず掃除する（rename 成功後は tmp 不在で no-op）。
- `flock(..., LOCK_UN)` の戻り値は無視で可（fail-loud 対象外）。この方針をコメントで明記する。
- `FakeObjectStoreConcurrencyTest` は短い timeout の時間依存判定に頼らず、**子プロセスとの同期用 pipe/marker** で「ロック取得待ち」を決定的に再現する（flaky 化防止）。

### PHPStan適合チェック
- [x] `resource` は `Assert::resource` で narrow / `@param resource $input` 明示
- [x] `config()->integer()` typed accessor / `disk()->size()` は int cast + Assert
- [x] `json_decode` は `array<string,mixed>` 注釈 + キー毎に `Assert::string`
- [x] 例外は `RuntimeException` 派生（catch 側で型明示）
- [x] 配列返却なし（DTO `ObjectMetadataData` / VO `FakeObjectMeta`）

### テスト計画
- Unit（`Storage::fake('s3_fake')`）: storeStreamed 正常 → head が size/content_type/checksum を返す / checksum 不一致 → `FakeStorageChecksumMismatch` + object 未確定 / 容量超過 → `FakeStorageOverCapacity` + 一時ファイル残存なし / object あり sidecar 無し → head null / sidecar 破損 → fail-loud / delete 冪等

### リスク
- 一時ファイル名は `random_bytes` で衝突回避。crash 時の `.uploading-*` 残骸は stale sweep 対象外だが `s3_fake` は bughunt teardown で破棄されるため実害なし（詳細設計注記）。

---

## 施策 4: `FakeTakeObjectStorage`

### 新規ファイル: `app/Services/Capture/Fakes/FakeTakeObjectStorage.php`
```php
<?php

declare(strict_types=1);

namespace App\Services\Capture\Fakes;

use App\DataTransferObjects\Capture\ObjectMetadataData;
use App\DataTransferObjects\Capture\PresignedUploadData;
use App\Services\Capture\TakeObjectStorage;
use App\Services\Storage\Fakes\FakeObjectStore;
use Aws\S3\S3Client;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\URL;
use RuntimeException;

/**
 * TakeObjectStorage の fake (実 S3 非依存)。presigned PUT を signed route + s3_fake disk で emulate。
 * 契約 (PresignedUploadData / ObjectMetadataData / checksum 照合の趣旨) は実装と同一。
 */
final class FakeTakeObjectStorage extends TakeObjectStorage
{
    public function __construct(private readonly FakeObjectStore $store) {}

    public function presignUpload(string $path, string $contentType, int $sizeBytes, string $checksumSha256, CarbonImmutable $expiresAt): PresignedUploadData
    {
        // 実 S3 presign の代替: signed route。checksum を署名パラメータに固定 (D2b 再PUT差し替え防止)。
        $url = URL::temporarySignedRoute('bughunt.storage.put', $expiresAt, [
            'key' => $path,
            'checksum' => $checksumSha256,
        ]);

        return new PresignedUploadData(
            url: $url,
            headers: [
                'Content-Type' => $contentType,
                'x-amz-checksum-sha256' => $checksumSha256,
            ],
            expiresAt: $expiresAt,
        );
    }

    public function headObject(string $path): ?ObjectMetadataData
    {
        return $this->store->head($path);
    }

    public function temporaryPlaybackUrl(string $path): string
    {
        return URL::temporarySignedRoute('bughunt.storage.get', now()->addMinutes(config()->integer('capture.playback_url_ttl_minutes')), [
            'key' => $path,
        ]);
    }

    public function delete(string $path): void
    {
        $this->store->delete($path);
    }

    public function exists(string $path): bool
    {
        return $this->store->exists($path);
    }

    /** fake モードで実 S3 経路に落ちたら fail-loud (LSP drift 検知) */
    protected function client(): S3Client
    {
        throw new RuntimeException('FakeTakeObjectStorage は実 S3 クライアントを構築しません');
    }
}
```

### 波及変更
- TypeScript型定義: なし / API Resource・DTO: なし（契約不変）/ 消費側（`TakeUploadService` 等）シグネチャ不変（concrete 型注入のまま container が fake 解決）
- テストファイル: 施策 9

### PHPStan適合チェック
- [x] 全メソッド戻り値型を親と一致（override 互換）/ DTO 返却 / `config()->integer()`
- [x] `client()` は親と同一シグネチャ（protected `S3Client` 返却）で throw

### リスク
- 親に S3 依存 public method が将来増えると fake が未 override で実 S3 に落ちる → 施策 9 の**契約テスト**で public surface を固定し検知。

---

## 施策 5: `FakeRenderObjectStorage`

### 新規ファイル: `app/Services/Render/Fakes/FakeRenderObjectStorage.php`
```php
<?php

declare(strict_types=1);

namespace App\Services\Render\Fakes;

use App\Services\Render\RenderObjectStorage;
use App\Services\Storage\Fakes\FakeObjectStore;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use RuntimeException;

/**
 * RenderObjectStorage の fake。disk() を s3_fake へ差し替え、署名 URL / upload の sidecar 生成 / delete を override。
 * contentDisposition() / keyPrefixFor() / downloadToLocal() は親を継承 (契約不変。downloadToLocal は disk() 経由)。
 * take footage は FakeTakeObjectStorage と同一 s3_fake disk・同一 key で読める。
 */
final class FakeRenderObjectStorage extends RenderObjectStorage
{
    public function __construct(private readonly FakeObjectStore $store) {}

    /** 親の disk() を s3_fake へ差し替え (downloadToLocal は親実装を継承したまま fake disk を読む) */
    protected function disk(): Filesystem
    {
        return Storage::disk(FakeObjectStore::DISK);
    }

    public function upload(string $localPath, string $key): void
    {
        $stream = fopen($localPath, 'rb');
        if ($stream === false) {
            throw new RuntimeException("ローカルファイルを開けません: {$localPath}");
        }
        try {
            // sidecar (content_type=video/mp4) を必ず生成する = GET DL の contract を満たす (Round 1 Warning)。
            // render 成果物は完成 mp4 固定。
            $this->store->putStreamWithMeta($key, $stream, 'video/mp4');
        } finally {
            if (is_resource($stream)) {
                fclose($stream);
            }
        }
    }

    public function temporaryPlaybackUrl(string $key): string
    {
        return URL::temporarySignedRoute('bughunt.storage.get', now()->addMinutes(config()->integer('manual.render_playback_url_ttl_minutes')), [
            'key' => $key,
        ]);
    }

    public function temporaryDownloadUrl(string $key, string $filename): string
    {
        // filename のみ signed パラメータに載せる (verbatim disposition は流さない)。
        // Content-Disposition は GET コントローラが contentDisposition() で再生成する。
        return URL::temporarySignedRoute('bughunt.storage.get', now()->addMinutes(config()->integer('manual.render_playback_url_ttl_minutes')), [
            'key' => $key,
            'filename' => $filename,
        ]);
    }

    public function delete(string $key): void
    {
        $this->store->delete($key);
    }
}
```

### 親 `RenderObjectStorage` の薄いリファクタ（Round 1 Suggestion 採用・重複/drift 削減）
親の `Storage::disk('s3')` 直書きを 1 箇所（`disk()`）に集約する（後方互換の並走を残さない）:
```php
// RenderObjectStorage (親) に追加。
protected function disk(): \Illuminate\Contracts\Filesystem\Filesystem
{
    return Storage::disk('s3');
}
```
親の `downloadToLocal` / `upload` / `temporaryPlaybackUrl` / `temporaryDownloadUrl` / `delete` 内の
`Storage::disk('s3')` を全て `$this->disk()` に置換（**挙動不変**）。これにより fake は `disk()` の
override だけで read/download 系を s3_fake へ向けられる（`upload` のみ sidecar 生成のため override、
署名 URL 系は signed route のため override）。既存 `RenderObjectStorageTest`/`RenderRetentionTest`
（`Storage::fake('s3')` 前提）は `disk()` 経由でも同一挙動で緑であることを厳守。

### 波及変更
- TypeScript型定義・DTO: なし / 消費側（`RenderPipeline` / `ManualDownloadController` / `DeleteRenderOutputsJob`）シグネチャ不変

### PHPStan適合チェック
- [x] override シグネチャ親一致 / `readStream` null チェック / `config()->integer()`

### リスク
- render は media queue worker（bughunt は queue:listen ワーカー稼働）で走る。worker も同一 env（bughunt.local + fake_storage）で bind されるため fake 解決される（施策 7 で担保）。

---

## 施策 6: signed route コントローラ（PUT 受け口 / GET serve）

### 新規: `app/Http/Controllers/Testing/PutFakeStorageObjectController.php`（要点）
```php
final class PutFakeStorageObjectController extends Controller
{
    public function __invoke(Request $request, FakeStorageGate $gate, FakeObjectStore $store): Response
    {
        abort_unless($gate->enabled(), 404); // route cache 残存対策の実行時再検証 (登録条件と同一 predicate)

        // signed パラメータ (署名済 = 改竄不能)
        $key = (string) $request->query('key');
        $signedChecksum = (string) $request->query('checksum');
        abort_if($key === '' || $signedChecksum === '', 400);
        // key プレフィックス最小検証 (署名前提でも多層防御。横断読取/書込面積を縮小)
        abort_unless(FakeStorageKey::isAllowed($key), 400);

        // checksum 三者一致の 1/2: 署名パラメータ == リクエストヘッダ (ヘッダ送信契約の検証)
        $header = $request->header('x-amz-checksum-sha256');
        abort_if(! is_string($header) || ! hash_equals($signedChecksum, $header), 400,
            'x-amz-checksum-sha256 ヘッダが署名 checksum と一致しません');

        $contentType = (string) ($request->header('Content-Type') ?: 'application/octet-stream');
        $input = $request->getContent(asResource: true); // php://input ストリーム (未消費)

        try {
            // 3/3: 実 body の checksum == 期待値 (FakeObjectStore が担保)
            $store->storeStreamed($key, $input, $contentType, $signedChecksum);
        } catch (FakeStorageChecksumMismatch) {
            abort(400, 'アップロード内容が checksum と一致しません');
        } catch (FakeStorageOverCapacity) {
            abort(413, 'アップロードサイズが上限を超えています');
        }

        return response()->noContent(); // 200/204 = 実 S3 PUT 成功と同じ扱い (フロントは ok を見るだけ)
    }
}
```

### 新規: `app/Http/Controllers/Testing/GetFakeStorageObjectController.php`（要点）
```php
final class GetFakeStorageObjectController extends Controller
{
    public function __invoke(Request $request, FakeStorageGate $gate, FakeObjectStore $store, RenderObjectStorage $disposition): BinaryFileResponse
    {
        abort_unless($gate->enabled(), 404);

        $key = (string) $request->query('key');
        abort_if($key === '', 400);
        abort_unless(FakeStorageKey::isAllowed($key), 400);

        // head() で「存在 + 完了 + content_type 取得」を一括判定 (sidecar 欠損=未完了は null→404。
        // 500 化しない = Round 1 Warning 対応)。破損 sidecar は fail-loud (RuntimeException→500) で検出する。
        $meta = $store->head($key);
        abort_if($meta === null, 404);

        $headers = ['Content-Type' => $meta->contentType ?? 'application/octet-stream'];
        $filename = $request->query('filename');
        if (is_string($filename) && $filename !== '') {
            // verbatim ではなく contentDisposition() で再生成 (ヘッダ注入面を作らない)
            $headers['Content-Disposition'] = $disposition->contentDisposition($filename);
        }

        // response()->file = BinaryFileResponse (Range 対応 = <video> シーク可)
        return response()->file($store->absolutePath($key), $headers);
    }
}
```

**GET ストリーミングの整合性スコープ（Round 4 Warning・明記）**:
`response()->file()` は `head()`（LOCK_SH）解放後に本文を読むため、**同一 key の並行上書き中**はヘッダ世代（content-type）と本文世代がずれ得る。fake 用途としてこの制約を許容し、**強整合を保証するのは登録時 HEAD（三点照合）まで**とする。この割り切りが安全な根拠: take の object key は**予約ごとにサーバ生成の ULID**（`takes/{ulid}.mp4`）で一意であり、実フロー上「同一 key への並行上書き」は起きない（idempotent 再送は同一 checksum=同一 bytes に固定）。playback/download は**登録確定済みで以後上書きされない** object を読むため、世代ずれは発生しない。key ロック（promote/head/delete）は emulator の防御的堅牢化（belt-and-suspenders）であり、GET 配信中の共有ロック保持までは要求しない。

**`FakeStorageKey`（新規・key 検証ヘルパ、`app/Services/Storage/Fakes/FakeStorageKey.php`）**:
```php
final class FakeStorageKey
{
    /**
     * 許可 key の segment 単位検証 (Round 2 Suggestion: 単純 str_contains('..') は誤検知するため):
     * - 先頭 segment は 'projects'
     * - segment 数 >= 2
     * - 各 segment: 空でない / `.`・`..` でない / `\`・NUL を含まない
     */
    public static function isAllowed(string $key): bool
    {
        if (! str_starts_with($key, 'projects/')) {
            return false;
        }
        $segments = explode('/', $key);
        if (count($segments) < 2) {
            return false;
        }
        foreach ($segments as $segment) {
            if ($segment === '' || $segment === '.' || $segment === '..') {
                return false;
            }
            if (str_contains($segment, '\\') || str_contains($segment, "\0")) {
                return false;
            }
        }

        return true;
    }
}
```

### 変更/波及
- ルート登録は施策 7（provider）で条件付き。`response()->json()` 不使用（`noContent`/`file`/`abort` は DTO 規約対象外）。
- `contentDisposition()` は `RenderObjectStorage` の既存 public メソッドを再利用（新設ロジックなし）。

### PHPStan適合チェック
- [x] query/header は `(string)`/`is_string` で narrow / `getContent(asResource: true)` は resource（FakeObjectStore 側で Assert）
- [x] 戻り値型 `Response` / `BinaryFileResponse` 明示
- [x] 例外 catch は具象型（`FakeStorageChecksumMismatch` / `FakeStorageOverCapacity`）

### テスト計画（施策 9 に集約）
- signed 無し → 403（`signed` middleware）/ gate 無効 env で route 不在（404）/ ヘッダ欠落・不一致 → 400 / 容量超過 → 413 / 正常 PUT→GET で bytes 往復 / Range リクエスト応答

### リスク
- `getContent(asResource: true)` が web group 外（本文未パース）で有効なこと。signed route は web CSRF group に入れない（施策 7）。

---

## 施策 7: provider 配線（bind + 条件付き route 登録）

### 変更箇所
- ファイル: `app/Providers/FakeExternalsServiceProvider.php`（`register()` に bind 追記、`boot()` に route 登録追記）

### 変更後コード（**capability 別 private method へ分離**。Round 2 Critical: early return が他 capability を巻き込まない構造）

現行 provider は `register()` 冒頭で `fake_externals !== true` を early return、`boot()` 冒頭で `fake_llm !== true` を early return する。storage をその後に追記すると storage flag 単独で走らない。→ **capability ごとに独立 private method へ分離**し、`register()`/`boot()` は各 method を順に呼ぶだけにする。

```php
public function register(): void
{
    $this->registerPaymentFakes(); // Stripe: fake_externals 依存 (挙動不変)
    $this->registerStorageFakes(); // storage: fake_storage (FakeStorageGate) 依存 — 独立
}

public function boot(): void
{
    $this->bootLlmFake();          // LLM: fake_llm 依存 (挙動不変)
    $this->bootStorageRoutes();    // storage signed route — 独立
}

/** Stripe 課金 gateway fake (既存ロジックをそのまま移設。fake_externals + PAYMENT_FAKE_ENVIRONMENTS) */
private function registerPaymentFakes(): void
{
    if (config('testing.fake_externals') !== true) {
        return;
    }
    $environment = $this->app->environment();
    if (! in_array($environment, self::PAYMENT_FAKE_ENVIRONMENTS, true)) {
        Log::warning('TESTING_FAKE_EXTERNALS=true ですが allowlist 外の環境のため fake を bind しません。', [
            'environment' => $environment,
        ]);

        return;
    }
    $this->app->bind(TicketCheckoutGateway::class, FakeTicketCheckoutGateway::class);
    $this->app->bind(SubscriptionCheckoutGateway::class, FakeSubscriptionCheckoutGateway::class);
}

/** LLM (Prism) fake (既存ロジックをそのまま移設。fake_llm + LLM_FAKE_ENVIRONMENTS) */
private function bootLlmFake(): void
{
    if (config('testing.fake_llm') !== true) {
        return;
    }
    if (! in_array($this->app->environment(), self::LLM_FAKE_ENVIRONMENTS, true)) {
        return;
    }
    $this->app->make(CannedPromptFakeRegistrar::class)->install();
}

/** storage fake: FakeStorageGate 成立時のみ concrete → fake へ rebind (gate = predicate SSOT) */
private function registerStorageFakes(): void
{
    if (! $this->app->make(FakeStorageGate::class)->enabled()) {
        return;
    }
    $this->app->bind(TakeObjectStorage::class, FakeTakeObjectStorage::class);
    $this->app->bind(RenderObjectStorage::class, FakeRenderObjectStorage::class);
}

/** storage fake の signed route (gate 成立時のみ。web CSRF group 外 = signed のみ) */
private function bootStorageRoutes(): void
{
    if (! $this->app->make(FakeStorageGate::class)->enabled()) {
        return;
    }
    Route::middleware('signed')->group(function (): void {
        Route::put('/_fake-storage/object', PutFakeStorageObjectController::class)
            ->name('bughunt.storage.put');
        Route::get('/_fake-storage/object', GetFakeStorageObjectController::class)
            ->name('bughunt.storage.get');
    });
}
```

> 既存 Stripe/LLM ロジックは**そのまま private method へ移設するだけ**（挙動不変）。既存の
> `FakeExternalsServiceProviderTest`（あれば）が緑であることを確認する。

### 波及変更
- provider 登録順は不変（`bootstrap/providers.php` 末尾で AppServiceProvider の後）。bind は `register()`、route は `boot()`（Route facade は boot で解決可能）。
- テストファイル: provider レベルの bind/route 登録テスト（施策 9）

### PHPStan適合チェック
- [x] `$this->app->make(FakeStorageGate::class)` は具象型解決（mixed 回避のため `make` の generics 明示 or Assert）
- [x] クロージャ戻り値 `void`

### リスク
- `boot()` で route 登録すると route:cache との相互作用があるが、bughunt は route cache を使わない前提（serve 直実行）。実行時 guard（施策 6 の `abort_unless`）が cache 残存時の最終防波堤。

### 運用注記（Round 1 Suggestion）
- render は media queue worker（`queue:listen` 別プロセス）で走る。**worker 起動時の env で bind が決まる**ため、worker 起動前に `TESTING_FAKE_STORAGE=true` が env に入っていること（bughunt では `scripts/bug-hunt-shard.sh` の `MODE_ENV` が serve/worker 双方へ注入し担保済み）を運用手順に明記する。real-storage run では worker も実 S3 経路になる（対称）。

---

## 施策 8: `ProductionEnvGuard` 不変確認

`ProductionEnvGuard::violations()` は既に `config('testing.fake_storage') === true` を production で違反として fail-fast 拒否する（L99-102、実装済み・**変更なし**）。本設計はこの不変を前提とし、施策 9 で「production + fake_storage=true → violation」を回帰テストで固定する（既存テストがあれば追加不要、無ければ追加）。

---

## 施策 9: テスト計画（全体）

> 禁止事項 1（テストなし完了報告）遵守。RefreshDatabase グローバル + `--parallel`。個別 `DatabaseTransactions` 不使用。Factory 生成。

### 9-0. テストハーネス設定（Round 1 Critical: 初期化順・env 注入順の明文化）

provider の `register()`/`boot()` は**アプリ bootstrap 時**に config を読むため、テスト body で `config()` を後から変えても bind/route は遡って登録されない。テスト種別で手当てを分ける:

- **Unit（`FakeObjectStore` / `FakeObjectMeta` / `FakeStorageGate` / `FakeTakeObjectStorage` / `FakeRenderObjectStorage`）**: route/provider 不要。`Storage::fake('s3_fake')` で disk を差し替え、対象クラスを**直接 `new`（依存は手動注入）**して検証する。DB 不要なら `RefreshDatabase` の副作用も無関係（`Storage::fake` は FS のみ差し替え、DB 初期化順と独立）。
- **Feature（signed route + bind を通す）**: テストヘルパ `withFakeStorage()`（`tests/` の trait or Pest helper）を `beforeEach` で呼ぶ。**provider 自身に配線させる**（手動 bind/route 再実装は provider の欠陥を隠すため禁止 = Round 2 Critical）:
  1. bootstrap **前**に `TESTING_FAKE_STORAGE=true` を env へ投入（`putenv` + `$_SERVER`/`$_ENV`）し、`$this->refreshApplication()` でアプリを再生成する（= provider の `register()`/`boot()` が実際に走り、bind と route を確立する）。testing 環境 + `runningUnitTests()===true` で `FakeStorageGate::enabled()` 成立。
  2. `Storage::fake('s3_fake')`（disk 実体を tmp へ隔離）。
  - route は**新しい application で 1 度だけ登録**される（同名再登録の隠蔽・テスト間リークを避ける = Round 2 Warning）。
  - `s3` 実 disk は region 未設定のまま放置 = **もし実 S3 に触れたら即例外**（fake が実 S3 非依存であることの negative 担保）。
  - Laravel の env 早期束縛（config キャッシュ）の都合で refreshApplication だけで反映しない場合は、テスト用の base `TestCase` で `defineEnvironment`/`getEnvironmentSetUp` 相当に `TESTING_FAKE_STORAGE=true` を注入する専用ベースクラスを用意する（provider を実走させる原則は不変）。
  - **env 復元（Round 3 Warning）**: helper は `putenv` / `$_ENV` / `$_SERVER` の元値を保存し、`afterEach`（or `finally`）で 3 箇所を必ず復元する（同一 Pest プロセス内の後続テストへ fake 設定が漏れない）。復元後、必要なら application を再生成。

### 9-1. Unit
| テスト | 検証 |
|--------|------|
| `FakeStorageGateTest` | flag off→false / bughunt.local+flag→true / testing+runningUnitTests+flag→true / local+flag→false / production+flag→false |
| `FakeObjectStoreTest`（`Storage::fake('s3_fake')`） | storeStreamed→head 三値一致 / checksum 不一致→例外+object 未確定 / 容量超過→例外+一時ファイル残存なし / object あり sidecar 無し→head null / sidecar 不正 JSON/欠損/未知 schema/checksum 形式不正→fail-loud / delete 冪等 / **上書き PUT で head が新 meta を返す（旧 meta 混同なし）** / **上書き途中で sidecar 未書込なら head null（未完了扱い・旧 meta を complete で返さない）** / putStreamWithMeta→head が content_type=video/mp4 を返す |
| `FakeObjectStoreConcurrencyTest`（**同一 key 並行 writer/reader 契約**、Round 3/4 Critical） | (a) 外部で lock ファイルを `LOCK_EX` 保持中は `promote`（storeStreamed）と `delete` がブロックし、`head`（LOCK_SH）もブロックする（直列化を確認）/ (b) writer を head() の各 filesystem 操作へ割り込ませても head() が返すのは `null` または**同一世代の metadata**のみ（「objectB + metaA」・exists→get 間削除の例外を出さない）/ (c) delete と promote の競合で sidecar だけ残る等の不整合が出ない |
| `FakeStorageContractTest`（**drift 検知・reflection**） | fake が **S3 到達性を持つメソッドの明示 inventory**（Take: `presignUpload`/`headObject`/`temporaryPlaybackUrl`/`delete`/`exists`/`client`。Render: `downloadToLocal`※/`upload`/`temporaryPlaybackUrl`/`temporaryDownloadUrl`/`delete`/`disk`）を override（Render は `disk()` override により downloadToLocal も fake disk 経由 = inventory 上「disk() が override されていれば充足」）していることを検証。**意図的継承の `contentDisposition()`/`keyPrefixFor()` は inventory 外**（override 不要）。`client()` override が実 S3 を構築しないこと |
| `FakeStorageAwsUnsetContractTest`（**drift 検知・E2E**、Round 1 Warning） | reflection では protected hook 追加の実 S3 到達を取りこぼすため、**AWS 設定を空（region 未設定）にした状態で** fake の主要ユースケース（take: presignUpload/headObject/temporaryPlaybackUrl/delete、render: upload/downloadToLocal/temporaryDownloadUrl/temporaryPlaybackUrl/delete）を実行し、**いずれも実 S3 に触れず成功**することを契約として固定。親に S3 依存 hook が増え fake が未 override なら region 例外で落ちる = 検知 |
| `FakeStorageKeyTest` | `projects/` prefix のみ許可 / `..` 含む key を拒否 |

### 9-2. Feature（gate 有効化して routes+bind を通す）
> `TESTING_FAKE_STORAGE=true` を env 注入し `Storage::fake('s3_fake')` で駆動。app env は testing + runningUnitTests のため gate 成立。実 s3 disk は region 未設定のまま（実 S3 に触れたら即例外 = негатив検証の担保）。

| テスト | 検証 |
|--------|------|
| `FakeTakeStoragePresignPutHeadTest` | presignUpload→signed PUT URL / その URL へ PUT（ヘッダ + blob）→`s3_fake` に保存 / headObject が size/content_type/checksum を返す / **実 S3 client が一度も構築されない**（region 未設定でも成功） |
| `FakeTakeStorageChecksumTest` | PUT 時ヘッダ欠落→400 / ヘッダ≠署名 checksum→400 / body≠checksum→400 / 容量超過→413 |
| `TakeUploadUrlTest`（既存・回帰） | 既存 Mockery mock 束縛が壊れないこと（fake bind は mock instance に上書きされる） |
| `TakeRegistrationTest`（**新規 fake 経路 E2E**） | fake mode で upload-url→PUT→register が実 S3 非依存で 3 点照合まで通り Take 生成（既存 mock 版とは別テスト） |
| `CaptureManualBrowsingTest`（採用テイク再生） | temporaryPlaybackUrl が signed GET を返し、GET が bytes を返す（Range 応答含む） |
| `FakeRenderStorageTest` | downloadToLocal/upload/temporaryDownloadUrl が `s3_fake` 経由 / GET download の Content-Disposition が `contentDisposition()` 生成（ヘッダ注入不能） |
| `GetFakeStorageObjectSignedTest` | 署名無し→403 / 署名済→200 / gate 無効時 route 未登録（404） |

### 9-3. Architecture / 不変条件
| テスト | 検証 |
|--------|------|
| `ProductionEnvGuardTest`（既存 or 追加） | production + `fake_storage=true` → violation（fail-fast） |
| `FakeExternalsProviderStorageWiringTest`（**provider 統合**、Round 2 Critical） | `fake_externals=false`・`fake_llm=false`・`fake_storage=true` **だけ**で、provider が `app(TakeObjectStorage::class)` を `FakeTakeObjectStorage`・`app(RenderObjectStorage::class)` を `FakeRenderObjectStorage` に解決し、`route('bughunt.storage.put')`/`route('bughunt.storage.get')` が登録されていることを検証（capability 別 early return が storage を巻き込まないことの回帰。手動配線ではなく provider 実走で確認）。**反対ケース（Round 3 Suggestion）**: `fake_storage=false` で実クラス `TakeObjectStorage`/`RenderObjectStorage` が解決され fake route が存在しない（`Route::has('bughunt.storage.put')===false`）ことを固定 |
| drift 不変（9-1 の `FakeStorageContractTest`）を Architecture 相当として位置づけ | fake の S3 到達メソッド inventory 網羅を CI 強制 |

### 9-4. 静的・整形
- `composer phpstan`（L10）/ `vendor/bin/pint --test` / 既存 `TakeObjectStorageTest`・`RenderObjectStorageTest`・`RenderRetentionTest` が緑（実 S3 経路不変）

---

## 波及変更まとめ（インターフェース非破壊の確認）

| 層 | 変更 | 理由 |
|----|------|------|
| DTO（`PresignedUploadData`/`ObjectMetadataData`） | なし | 契約維持 |
| 消費側 Service（`TakeUploadService`/`TakeRegistrationService`/`CaptureSyncService`/`RenderPipeline`/`CaptureManualDetailData`） | なし | concrete 型注入のまま container が fake 解決（サブクラス方式） |
| Controller（`TakeUploadUrlController`/`ManualDownloadController` 等） | なし | 同上 |
| Job（`DeleteTakeObjectsJob`/`DeleteRenderOutputsJob`） | なし | 同上（worker も同 env で fake bind） |
| TypeScript / Svelte（`upload-queue.ts` 等） | なし | `fetch(upload_url,{PUT,headers,blob})` 契約が fake でも成立 |
| 既存テスト mock（`Mockery::mock(TakeObjectStorage::class)`） | なし | fake bind は `app()->instance` mock に上書きされる |

---

## 実装モード

| 項目 | 内容 |
|------|------|
| 推奨モード | **standalone** |
| 判断根拠 | 新規ファイル中心（fake 実装・gate・store・controller）+ provider/config の局所追記。既存 concrete/DTO/消費側は不変で、他 item と競合しにくい。F-1-0a という単一 bug の恒久対応で完結する独立変更。 |
| 競合リスク | 低。`config/filesystems.php`・`FakeExternalsServiceProvider.php` の追記のみ他変更と行競合の可能性があるが軽微。 |

## リスク総括

- **本番安全性**: flag 既定 false + gate（env allowlist）+ `ProductionEnvGuard` fail-fast + signed route の三重防御。fake は本番で誰も解決せず route も生えない。
- **実 S3 経路不変**: `--real-storage` / testing 非 fake は従来どおり `TakeObjectStorage`/`RenderObjectStorage` の実装が解決される。
- **drift**: サブクロス方式の LSP リスクは契約テスト（public surface 網羅）+ `client()` fail-loud で検知。
- **メモリ/disk**: ストリーム処理 + 絶対容量上限（`capture.max_take_bytes` 由来）で 500MiB でも安全。
```

## 実装差分（git diff HEAD）

```diff
diff --git a/app/Http/Controllers/Testing/GetFakeStorageObjectController.php b/app/Http/Controllers/Testing/GetFakeStorageObjectController.php
new file mode 100644
index 0000000..928de27
--- /dev/null
+++ b/app/Http/Controllers/Testing/GetFakeStorageObjectController.php
@@ -0,0 +1,43 @@
+<?php
+
+declare(strict_types=1);
+
+namespace App\Http\Controllers\Testing;
+
+use App\Http\Controllers\Controller;
+use App\Services\Render\RenderObjectStorage;
+use App\Services\Storage\Fakes\FakeObjectStore;
+use App\Services\Storage\Fakes\FakeStorageKey;
+use App\Support\FakeStorageGate;
+use Illuminate\Http\Request;
+use Symfony\Component\HttpFoundation\BinaryFileResponse;
+
+/**
+ * fake storage の signed GET serve (実 S3 署名 GET の emulation)。Range 対応 (<video> シーク可)。
+ * head() で「存在 + 完了 + content_type 取得」を一括判定する (sidecar 欠損=未完了は null→404。
+ * 500 化しない)。破損 sidecar は fail-loud (RuntimeException→500) で検出する。
+ */
+final class GetFakeStorageObjectController extends Controller
+{
+    public function __invoke(Request $request, FakeStorageGate $gate, FakeObjectStore $store, RenderObjectStorage $disposition): BinaryFileResponse
+    {
+        abort_unless($gate->enabled(), 404);
+
+        $key = (string) $request->query('key');
+        abort_if($key === '', 400);
+        abort_unless(FakeStorageKey::isAllowed($key), 400);
+
+        $meta = $store->head($key);
+        abort_if($meta === null, 404);
+
+        $headers = ['Content-Type' => $meta->contentType ?? 'application/octet-stream'];
+        $filename = $request->query('filename');
+        if (is_string($filename) && $filename !== '') {
+            // verbatim ではなく contentDisposition() で再生成 (ヘッダ注入面を作らない)
+            $headers['Content-Disposition'] = $disposition->contentDisposition($filename);
+        }
+
+        // response()->file = BinaryFileResponse (Range 対応 = <video> シーク可)
+        return response()->file($store->absolutePath($key), $headers);
+    }
+}
diff --git a/app/Http/Controllers/Testing/PutFakeStorageObjectController.php b/app/Http/Controllers/Testing/PutFakeStorageObjectController.php
new file mode 100644
index 0000000..b997d4a
--- /dev/null
+++ b/app/Http/Controllers/Testing/PutFakeStorageObjectController.php
@@ -0,0 +1,56 @@
+<?php
+
+declare(strict_types=1);
+
+namespace App\Http\Controllers\Testing;
+
+use App\Http\Controllers\Controller;
+use App\Services\Storage\Fakes\FakeObjectStore;
+use App\Services\Storage\Fakes\FakeStorageChecksumMismatch;
+use App\Services\Storage\Fakes\FakeStorageKey;
+use App\Services\Storage\Fakes\FakeStorageOverCapacity;
+use App\Support\FakeStorageGate;
+use Illuminate\Http\Request;
+use Symfony\Component\HttpFoundation\Response;
+
+/**
+ * fake storage の signed PUT 受け口 (実 S3 presigned PUT の emulation)。
+ * gate 成立時のみ route 登録されるが、route cache 残存対策で実行時にも同一 predicate で再検証する。
+ * response()->json() 不使用 (noContent / abort は DTO 規約対象外)。
+ */
+final class PutFakeStorageObjectController extends Controller
+{
+    public function __invoke(Request $request, FakeStorageGate $gate, FakeObjectStore $store): Response
+    {
+        abort_unless($gate->enabled(), 404); // route cache 残存対策の実行時再検証 (登録条件と同一 predicate)
+
+        // signed パラメータ (署名済 = 改竄不能)
+        $key = (string) $request->query('key');
+        $signedChecksum = (string) $request->query('checksum');
+        abort_if($key === '' || $signedChecksum === '', 400);
+        // key プレフィックス最小検証 (署名前提でも多層防御。横断読取/書込面積を縮小)
+        abort_unless(FakeStorageKey::isAllowed($key), 400);
+
+        // checksum 三者一致の 1/2: 署名パラメータ == リクエストヘッダ (ヘッダ送信契約の検証)
+        $header = $request->header('x-amz-checksum-sha256');
+        abort_if(
+            ! is_string($header) || ! hash_equals($signedChecksum, $header),
+            400,
+            'x-amz-checksum-sha256 ヘッダが署名 checksum と一致しません',
+        );
+
+        $contentType = (string) ($request->header('Content-Type') ?: 'application/octet-stream');
+        $input = $request->getContent(asResource: true); // php://input ストリーム (未消費)
+
+        try {
+            // 3/3: 実 body の checksum == 期待値 (FakeObjectStore が担保)
+            $store->storeStreamed($key, $input, $contentType, $signedChecksum);
+        } catch (FakeStorageChecksumMismatch) {
+            abort(400, 'アップロード内容が checksum と一致しません');
+        } catch (FakeStorageOverCapacity) {
+            abort(413, 'アップロードサイズが上限を超えています');
+        }
+
+        return response()->noContent(); // 204 = 実 S3 PUT 成功と同じ扱い (フロントは ok を見るだけ)
+    }
+}
diff --git a/app/Providers/FakeExternalsServiceProvider.php b/app/Providers/FakeExternalsServiceProvider.php
index 5f5aa2e..6dc6396 100644
--- a/app/Providers/FakeExternalsServiceProvider.php
+++ b/app/Providers/FakeExternalsServiceProvider.php
@@ -4,12 +4,20 @@
 
 namespace App\Providers;
 
+use App\Http\Controllers\Testing\GetFakeStorageObjectController;
+use App\Http\Controllers\Testing\PutFakeStorageObjectController;
 use App\Services\AI\Testing\CannedPromptFakeRegistrar;
 use App\Services\Billing\Fakes\FakeSubscriptionCheckoutGateway;
 use App\Services\Billing\Fakes\FakeTicketCheckoutGateway;
 use App\Services\Billing\SubscriptionCheckoutGateway;
 use App\Services\Billing\TicketCheckoutGateway;
+use App\Services\Capture\Fakes\FakeTakeObjectStorage;
+use App\Services\Capture\TakeObjectStorage;
+use App\Services\Render\Fakes\FakeRenderObjectStorage;
+use App\Services\Render\RenderObjectStorage;
+use App\Support\FakeStorageGate;
 use Illuminate\Support\Facades\Log;
+use Illuminate\Support\Facades\Route;
 use Illuminate\Support\ServiceProvider;
 
 /**
@@ -39,6 +47,20 @@ class FakeExternalsServiceProvider extends ServiceProvider
     private const array LLM_FAKE_ENVIRONMENTS = ['bughunt.local'];
 
     public function register(): void
+    {
+        // capability ごとに独立 private method へ分離する (early return が他 capability を巻き込まない)。
+        $this->registerPaymentFakes(); // Stripe: fake_externals 依存 (挙動不変)
+        $this->registerStorageFakes(); // storage: fake_storage (FakeStorageGate) 依存 — 独立
+    }
+
+    public function boot(): void
+    {
+        $this->bootLlmFake();       // LLM: fake_llm 依存 (挙動不変)
+        $this->bootStorageRoutes(); // storage signed route — 独立
+    }
+
+    /** Stripe 課金 gateway fake (fake_externals + PAYMENT_FAKE_ENVIRONMENTS。挙動不変) */
+    private function registerPaymentFakes(): void
     {
         if (config('testing.fake_externals') !== true) {
             return;
@@ -58,7 +80,8 @@ public function register(): void
         $this->app->bind(SubscriptionCheckoutGateway::class, FakeSubscriptionCheckoutGateway::class);
     }
 
-    public function boot(): void
+    /** LLM (Prism) fake (fake_llm + LLM_FAKE_ENVIRONMENTS。挙動不変) */
+    private function bootLlmFake(): void
     {
         // LLM fake は fake_llm (既定 false = real LLM) で判定する。bughunt 既定は real-llm で、
         // --fake-llm 指定時のみ TESTING_FAKE_LLM=true が注入され install される。
@@ -78,4 +101,33 @@ public function boot(): void
         // Browser lane (tests/Pest.php) と同一の install API を使う (Prompt::installFake の封じ込め)。
         $this->app->make(CannedPromptFakeRegistrar::class)->install();
     }
+
+    /**
+     * storage fake: FakeStorageGate 成立時のみ concrete → fake へ rebind (gate = predicate SSOT)。
+     * env allowlist / production 拒否は gate に一元化される。
+     */
+    private function registerStorageFakes(): void
+    {
+        if (! $this->app->make(FakeStorageGate::class)->enabled()) {
+            return;
+        }
+
+        $this->app->bind(TakeObjectStorage::class, FakeTakeObjectStorage::class);
+        $this->app->bind(RenderObjectStorage::class, FakeRenderObjectStorage::class);
+    }
+
+    /** storage fake の signed route (gate 成立時のみ。web CSRF group 外 = signed のみ) */
+    private function bootStorageRoutes(): void
+    {
+        if (! $this->app->make(FakeStorageGate::class)->enabled()) {
+            return;
+        }
+
+        Route::middleware('signed')->group(function (): void {
+            Route::put('/_fake-storage/object', PutFakeStorageObjectController::class)
+                ->name('bughunt.storage.put');
+            Route::get('/_fake-storage/object', GetFakeStorageObjectController::class)
+                ->name('bughunt.storage.get');
+        });
+    }
 }
diff --git a/app/Services/Capture/Fakes/FakeTakeObjectStorage.php b/app/Services/Capture/Fakes/FakeTakeObjectStorage.php
new file mode 100644
index 0000000..53e2443
--- /dev/null
+++ b/app/Services/Capture/Fakes/FakeTakeObjectStorage.php
@@ -0,0 +1,71 @@
+<?php
+
+declare(strict_types=1);
+
+namespace App\Services\Capture\Fakes;
+
+use App\DataTransferObjects\Capture\ObjectMetadataData;
+use App\DataTransferObjects\Capture\PresignedUploadData;
+use App\Services\Capture\TakeObjectStorage;
+use App\Services\Storage\Fakes\FakeObjectStore;
+use Aws\S3\S3Client;
+use Carbon\CarbonImmutable;
+use Illuminate\Support\Facades\URL;
+use RuntimeException;
+
+/**
+ * TakeObjectStorage の fake (実 S3 非依存)。presigned PUT を signed route + s3_fake disk で emulate。
+ * 契約 (PresignedUploadData / ObjectMetadataData / checksum 照合の趣旨) は実装と同一。
+ */
+final class FakeTakeObjectStorage extends TakeObjectStorage
+{
+    public function __construct(private readonly FakeObjectStore $store) {}
+
+    public function presignUpload(string $path, string $contentType, int $sizeBytes, string $checksumSha256, CarbonImmutable $expiresAt): PresignedUploadData
+    {
+        // 実 S3 presign の代替: signed route。checksum を署名パラメータに固定 (D2b 再PUT差し替え防止)。
+        $url = URL::temporarySignedRoute('bughunt.storage.put', $expiresAt, [
+            'key' => $path,
+            'checksum' => $checksumSha256,
+        ]);
+
+        return new PresignedUploadData(
+            url: $url,
+            headers: [
+                'Content-Type' => $contentType,
+                'x-amz-checksum-sha256' => $checksumSha256,
+            ],
+            expiresAt: $expiresAt,
+        );
+    }
+
+    public function headObject(string $path): ?ObjectMetadataData
+    {
+        return $this->store->head($path);
+    }
+
+    public function temporaryPlaybackUrl(string $path): string
+    {
+        return URL::temporarySignedRoute(
+            'bughunt.storage.get',
+            now()->addMinutes(config()->integer('capture.playback_url_ttl_minutes')),
+            ['key' => $path],
+        );
+    }
+
+    public function delete(string $path): void
+    {
+        $this->store->delete($path);
+    }
+
+    public function exists(string $path): bool
+    {
+        return $this->store->exists($path);
+    }
+
+    /** fake モードで実 S3 経路に落ちたら fail-loud (LSP drift 検知) */
+    protected function client(): S3Client
+    {
+        throw new RuntimeException('FakeTakeObjectStorage は実 S3 クライアントを構築しません');
+    }
+}
diff --git a/app/Services/Render/Fakes/FakeRenderObjectStorage.php b/app/Services/Render/Fakes/FakeRenderObjectStorage.php
new file mode 100644
index 0000000..5cd5a71
--- /dev/null
+++ b/app/Services/Render/Fakes/FakeRenderObjectStorage.php
@@ -0,0 +1,70 @@
+<?php
+
+declare(strict_types=1);
+
+namespace App\Services\Render\Fakes;
+
+use App\Services\Render\RenderObjectStorage;
+use App\Services\Storage\Fakes\FakeObjectStore;
+use Illuminate\Contracts\Filesystem\Filesystem;
+use Illuminate\Support\Facades\Storage;
+use Illuminate\Support\Facades\URL;
+use RuntimeException;
+
+/**
+ * RenderObjectStorage の fake。disk() を s3_fake へ差し替え、署名 URL / upload の sidecar 生成 / delete を override。
+ * contentDisposition() / keyPrefixFor() / downloadToLocal() は親を継承 (契約不変。downloadToLocal は disk() 経由)。
+ * take footage は FakeTakeObjectStorage と同一 s3_fake disk・同一 key で読める。
+ */
+final class FakeRenderObjectStorage extends RenderObjectStorage
+{
+    public function __construct(private readonly FakeObjectStore $store) {}
+
+    /** 親の disk() を s3_fake へ差し替え (downloadToLocal は親実装を継承したまま fake disk を読む) */
+    protected function disk(): Filesystem
+    {
+        return Storage::disk(FakeObjectStore::DISK);
+    }
+
+    public function upload(string $localPath, string $key): void
+    {
+        $stream = fopen($localPath, 'rb');
+        if ($stream === false) {
+            throw new RuntimeException("ローカルファイルを開けません: {$localPath}");
+        }
+        try {
+            // sidecar (content_type=video/mp4) を必ず生成する = GET DL の contract を満たす。
+            // render 成果物は完成 mp4 固定。
+            $this->store->putStreamWithMeta($key, $stream, 'video/mp4');
+        } finally {
+            if (is_resource($stream)) {
+                fclose($stream);
+            }
+        }
+    }
+
+    public function temporaryPlaybackUrl(string $key): string
+    {
+        return URL::temporarySignedRoute(
+            'bughunt.storage.get',
+            now()->addMinutes(config()->integer('manual.render_playback_url_ttl_minutes')),
+            ['key' => $key],
+        );
+    }
+
+    public function temporaryDownloadUrl(string $key, string $filename): string
+    {
+        // filename のみ signed パラメータに載せる (verbatim disposition は流さない)。
+        // Content-Disposition は GET コントローラが contentDisposition() で再生成する。
+        return URL::temporarySignedRoute(
+            'bughunt.storage.get',
+            now()->addMinutes(config()->integer('manual.render_playback_url_ttl_minutes')),
+            ['key' => $key, 'filename' => $filename],
+        );
+    }
+
+    public function delete(string $key): void
+    {
+        $this->store->delete($key);
+    }
+}
diff --git a/app/Services/Render/RenderObjectStorage.php b/app/Services/Render/RenderObjectStorage.php
index 0bbd35e..3a89124 100644
--- a/app/Services/Render/RenderObjectStorage.php
+++ b/app/Services/Render/RenderObjectStorage.php
@@ -5,6 +5,7 @@
 namespace App\Services\Render;
 
 use App\Models\VideoManual;
+use Illuminate\Contracts\Filesystem\Filesystem;
 use Illuminate\Support\Facades\Storage;
 use RuntimeException;
 use Webmozart\Assert\Assert;
@@ -12,13 +13,22 @@
 /**
  * レンダ出力 S3 オブジェクト操作の集約点 (TakeObjectStorage と同じ Storage::disk('s3') 経由)。
  * ダウンロード/アップロード/署名 URL/削除はすべて本クラス経由 (Feature テストは Storage::fake('s3'))。
+ *
+ * disk 解決は disk() の 1 箇所に集約する。fake (FakeRenderObjectStorage) は disk() を s3_fake へ
+ * override するだけで read/download 系を切り替えられる (後方互換の並走を残さない)。
  */
 class RenderObjectStorage
 {
+    /** 集約された disk 解決点 (fake は本メソッドを override する。挙動不変) */
+    protected function disk(): Filesystem
+    {
+        return Storage::disk('s3');
+    }
+
     /** S3 素材をローカル一時ファイルへ取得する (readStream → ローカル書き込み) */
     public function downloadToLocal(string $key, string $localPath): void
     {
-        $stream = Storage::disk('s3')->readStream($key);
+        $stream = $this->disk()->readStream($key);
         if ($stream === null) {
             throw new RuntimeException("S3 オブジェクトを読めません: {$key}");
         }
@@ -49,7 +59,7 @@ public function upload(string $localPath, string $key): void
         }
 
         try {
-            Storage::disk('s3')->writeStream($key, $stream);
+            $this->disk()->writeStream($key, $stream);
         } finally {
             if (is_resource($stream)) {
                 fclose($stream);
@@ -60,7 +70,7 @@ public function upload(string $localPath, string $key): void
     /** preview 再生用の署名 GET URL (TTL は config manual.render_playback_url_ttl_minutes) */
     public function temporaryPlaybackUrl(string $key): string
     {
-        return Storage::disk('s3')->temporaryUrl(
+        return $this->disk()->temporaryUrl(
             $key,
             now()->addMinutes(config()->integer('manual.render_playback_url_ttl_minutes')),
         );
@@ -74,7 +84,7 @@ public function temporaryPlaybackUrl(string $key): string
      */
     public function temporaryDownloadUrl(string $key, string $filename): string
     {
-        return Storage::disk('s3')->temporaryUrl(
+        return $this->disk()->temporaryUrl(
             $key,
             now()->addMinutes(config()->integer('manual.render_playback_url_ttl_minutes')),
             ['ResponseContentDisposition' => $this->contentDisposition($filename)],
@@ -84,7 +94,7 @@ public function temporaryDownloadUrl(string $key, string $filename): string
     /** オブジェクト削除 (存在しないキーは no-op = 冪等) */
     public function delete(string $key): void
     {
-        Storage::disk('s3')->delete($key);
+        $this->disk()->delete($key);
     }
 
     /** manual 配下のレンダ出力 prefix (DeleteRenderOutputsJob の過大削除防止に使う) */
diff --git a/app/Services/Storage/Fakes/FakeObjectMeta.php b/app/Services/Storage/Fakes/FakeObjectMeta.php
new file mode 100644
index 0000000..3afd920
--- /dev/null
+++ b/app/Services/Storage/Fakes/FakeObjectMeta.php
@@ -0,0 +1,19 @@
+<?php
+
+declare(strict_types=1);
+
+namespace App\Services\Storage\Fakes;
+
+/**
+ * fake object の sidecar メタ (実 S3 が object metadata として持つ ContentType/Checksum の emulation)。
+ * schema_version で将来の互換切りを可能にする。encode/decode は FakeObjectStore が担う。
+ */
+final readonly class FakeObjectMeta
+{
+    public const int SCHEMA_VERSION = 1;
+
+    public function __construct(
+        public string $contentType,
+        public string $checksumSha256, // base64 sha256 (x-amz-checksum-sha256 と同形式)
+    ) {}
+}
diff --git a/app/Services/Storage/Fakes/FakeObjectStore.php b/app/Services/Storage/Fakes/FakeObjectStore.php
new file mode 100644
index 0000000..d5aa4b8
--- /dev/null
+++ b/app/Services/Storage/Fakes/FakeObjectStore.php
@@ -0,0 +1,325 @@
+<?php
+
+declare(strict_types=1);
+
+namespace App\Services\Storage\Fakes;
+
+use App\DataTransferObjects\Capture\ObjectMetadataData;
+use HashContext;
+use Illuminate\Contracts\Filesystem\Filesystem;
+use Illuminate\Support\Facades\Storage;
+use JsonException;
+use RuntimeException;
+use Webmozart\Assert\Assert;
+
+/**
+ * s3_fake disk 上のオブジェクト操作の集約点 (fake storage 基盤)。
+ *
+ * - 保存はストリーム: php://input を chunk 読みしながら sha256 を計算し一時ファイルへ書く。
+ *   絶対容量上限 (config capture.max_take_bytes) を超えたら中断・一時ファイル削除・OverCapacity を投げる。
+ * - checksum 三者一致は呼び出し側 (controller) が「署名パラメータ == ヘッダ」を先に検証し、
+ *   本メソッドが「== 実 body」を担保する (期待 checksum を受け取り実 body と照合)。
+ * - sidecar meta は object 配置の「後」に書く = completion marker (crash 途中は object あり sidecar 無し = 未完了)。
+ *
+ * object + sidecar 全体の整合は key 単位の排他ロック (flock LOCK_EX/LOCK_SH) と completion marker で担保する。
+ */
+final class FakeObjectStore
+{
+    public const string DISK = 's3_fake';
+
+    private function disk(): Filesystem
+    {
+        return Storage::disk(self::DISK);
+    }
+
+    /**
+     * ストリーム保存 (atomic)。$expectedChecksum は base64 sha256。
+     * checksum 三者一致の 3/3 (実 body == 期待値) を担保する。
+     *
+     * ※ パスは必ず `disk()->path()` 基準にする (Storage::fake は root を tmp へ差し替えるため、
+     *    root ハードコードだとテストと実装で別領域を書く)。
+     *
+     * @param  resource  $input
+     *
+     * @throws FakeStorageChecksumMismatch 実 body の checksum が期待値と不一致
+     * @throws FakeStorageOverCapacity 絶対容量上限超過
+     */
+    public function storeStreamed(string $key, mixed $input, string $contentType, string $expectedChecksum): void
+    {
+        $target = $this->disk()->path($key); // Storage::fake でも実 local でも正しい実体パス
+        $this->ensureDir(dirname($target));
+        $tmp = $target.'.uploading-'.bin2hex(random_bytes(8)); // 同一ディレクトリ = 同一 filesystem
+
+        try {
+            // 攻撃者由来の php://input = 絶対容量上限 (take 上限) を適用
+            $actual = $this->streamToTmp($input, $tmp, maxBytes: config()->integer('capture.max_take_bytes'));
+
+            if (! hash_equals($expectedChecksum, $actual)) {
+                throw new FakeStorageChecksumMismatch('fake storage: アップロード内容が checksum と一致しません');
+            }
+
+            $this->promote($key, $tmp, $target, new FakeObjectMeta($contentType, $actual));
+        } finally {
+            // withKeyLock 取得失敗等で promote が例外時にも未確定 tmp を必ず掃除する
+            // (rename 成功後は tmp 不在で no-op)。
+            if (is_file($tmp)) {
+                @unlink($tmp);
+            }
+        }
+    }
+
+    /**
+     * checksum 照合なしのストリーム保存 (render 出力・take footage コピー等の**サーバ生成の内部生成物**)。
+     * sidecar (content_type) を必ず生成する = GET 配信の contract を満たす。
+     * 入力は攻撃者由来の php://input ではなく信頼できるローカルファイルのため、絶対容量上限は課さない。
+     *
+     * @param  resource  $input
+     */
+    public function putStreamWithMeta(string $key, mixed $input, string $contentType): void
+    {
+        $target = $this->disk()->path($key);
+        $this->ensureDir(dirname($target));
+        $tmp = $target.'.uploading-'.bin2hex(random_bytes(8));
+
+        try {
+            $actual = $this->streamToTmp($input, $tmp, maxBytes: null); // 信頼できる内部入力 = cap なし
+            $this->promote($key, $tmp, $target, new FakeObjectMeta($contentType, $actual));
+        } finally {
+            if (is_file($tmp)) {
+                @unlink($tmp);
+            }
+        }
+    }
+
+    /**
+     * $input を $tmp へ流しつつ sha256 を計算し、base64 sha256 を返す。
+     *
+     * @param  resource  $input
+     */
+    private function streamToTmp(mixed $input, string $tmp, ?int $maxBytes): string
+    {
+        Assert::resource($input, null, 'fake storage: 入力ストリームが resource ではありません');
+        $ctx = hash_init('sha256');
+        $out = fopen($tmp, 'wb');
+        Assert::resource($out, null, 'fake storage: 一時ファイルを開けません');
+        try {
+            $this->streamInto($input, $out, $ctx, $maxBytes);
+        } finally {
+            fclose($out);
+        }
+
+        return base64_encode(hash_final($ctx, true));
+    }
+
+    /**
+     * $input を $out へ流しつつ sha256 を計算。$maxBytes 非 null なら超過で中断、
+     * fwrite の部分書き込みは完了まで再試行。
+     *
+     * @param  resource  $input
+     * @param  resource  $out
+     */
+    private function streamInto(mixed $input, mixed $out, HashContext $ctx, ?int $maxBytes): void
+    {
+        $total = 0;
+        while (! feof($input)) {
+            $chunk = fread($input, 1024 * 1024);
+            if ($chunk === false) {
+                throw new RuntimeException('fake storage: 入力ストリーム読込に失敗しました');
+            }
+            if ($chunk === '') {
+                continue;
+            }
+            $total += strlen($chunk);
+            if ($maxBytes !== null && $total > $maxBytes) {
+                throw new FakeStorageOverCapacity($maxBytes);
+            }
+            hash_update($ctx, $chunk);
+            $this->writeAll($out, $chunk); // 部分書き込み対策
+        }
+    }
+
+    /**
+     * fwrite の部分書き込みを完了まで再試行。false は例外化。
+     *
+     * @param  resource  $out
+     */
+    private function writeAll(mixed $out, string $data): void
+    {
+        $offset = 0;
+        $len = strlen($data);
+        while ($offset < $len) {
+            $written = fwrite($out, substr($data, $offset));
+            if ($written === false || $written === 0) {
+                throw new RuntimeException('fake storage: 一時ファイルへの書き込みに失敗しました');
+            }
+            $offset += $written;
+        }
+    }
+
+    /**
+     * 確定手順。**atomic なのは object rename のみ**。object + sidecar 全体の整合は
+     * key 単位の排他ロック (flock LOCK_EX) と completion marker (sidecar) で担保する。
+     *
+     * critical section を key ロックで直列化する理由: 同一 key への並行 PUT で
+     * A/B が sidecar 削除→rename→sidecar 作成を interleave すると「object B + meta A」が観測され得る。
+     * ロックで writer を直列化すれば、reader は常に null / (objectA,metaA) / (objectB,metaB) のいずれか。
+     *
+     * 手順 (ロック保持下):
+     * 1. 既存 sidecar 削除 (以降 head()===null = 未完了扱い。旧 meta が新 object に付かない)
+     * 2. object を atomic rename で確定
+     * 3. sidecar を最後に書く (= completion marker)。失敗しても「object あり sidecar 無し = 未完了」で
+     *    不整合な complete を返さない (再 PUT で回復可能)。
+     */
+    private function promote(string $key, string $tmp, string $target, FakeObjectMeta $meta): void
+    {
+        $this->withKeyLock($key, exclusive: true, critical: function () use ($key, $tmp, $target, $meta): null {
+            $this->disk()->delete($this->sidecarKey($key)); // 冪等 (不在は no-op)
+            if (! @rename($tmp, $target)) {
+                throw new RuntimeException('fake storage: object の確定に失敗しました');
+            }
+            $this->disk()->put($this->sidecarKey($key), $this->encodeMeta($meta));
+
+            return null;
+        });
+    }
+
+    /**
+     * key 単位のロック下で $critical を実行する。ロックファイルは object とは別 namespace (`.locks/`) に置く
+     * (object listing/GET を汚さない)。unlock/close は finally で保証。
+     * $exclusive: true = LOCK_EX (writer: promote/delete) / false = LOCK_SH (reader: head)。
+     * flock(..., LOCK_UN) の戻り値は fail-loud 対象外 (無視で可)。
+     *
+     * @template T
+     *
+     * @param  callable():T  $critical
+     * @return T
+     */
+    private function withKeyLock(string $key, bool $exclusive, callable $critical): mixed
+    {
+        $operation = $exclusive ? LOCK_EX : LOCK_SH;
+        $lockPath = $this->disk()->path('.locks/'.sha1($key).'.lock');
+        $this->ensureDir(dirname($lockPath));
+        $handle = fopen($lockPath, 'c');
+        Assert::resource($handle, null, 'fake storage: ロックファイルを開けません');
+        try {
+            if (! flock($handle, $operation)) {
+                throw new RuntimeException('fake storage: ロック取得に失敗しました');
+            }
+            try {
+                return $critical();
+            } finally {
+                flock($handle, LOCK_UN);
+            }
+        } finally {
+            fclose($handle);
+        }
+    }
+
+    /**
+     * HeadObject 相当。**同一 key の共有ロック (LOCK_SH) 下**で object 確認・sidecar 確認/読込・size 取得を
+     * 一括で行う (promote の LOCK_EX と排他され、reader が異世代の object/meta を組み合わせない・
+     * sidecar exists→get 間の削除で例外化しない)。状態別に固定:
+     * - object 不在 → null (PUT 未着手)
+     * - object あり sidecar 不在 → null (PUT 未完了 = crash 途中)
+     * - sidecar 破損 (不正 JSON/欠損キー/未知 schema) → fail-loud (RuntimeException)
+     */
+    public function head(string $key): ?ObjectMetadataData
+    {
+        return $this->withKeyLock($key, exclusive: false, critical: function () use ($key): ?ObjectMetadataData {
+            if (! $this->disk()->exists($key)) {
+                return null;
+            }
+            if (! $this->disk()->exists($this->sidecarKey($key))) {
+                return null;
+            }
+            $meta = $this->decodeMeta($this->disk()->get($this->sidecarKey($key)));
+
+            return new ObjectMetadataData(
+                contentLength: (int) $this->disk()->size($key),
+                contentType: $meta->contentType,
+                checksumSha256: $meta->checksumSha256,
+            );
+        });
+    }
+
+    /** object + sidecar 削除。promote と競合しないよう同一 key の LOCK_EX 下で行う。冪等。 */
+    public function delete(string $key): void
+    {
+        $this->withKeyLock($key, exclusive: true, critical: function () use ($key): null {
+            $this->disk()->delete([$key, $this->sidecarKey($key)]); // 不在は no-op
+
+            return null;
+        });
+    }
+
+    public function exists(string $key): bool
+    {
+        return $this->disk()->exists($key);
+    }
+
+    /** GET serve 用の絶対パス (response()->file の Range 対応のため) */
+    public function absolutePath(string $key): string
+    {
+        return $this->disk()->path($key);
+    }
+
+    private function sidecarKey(string $key): string
+    {
+        return $key.'.meta.json';
+    }
+
+    private function encodeMeta(FakeObjectMeta $meta): string
+    {
+        return json_encode([
+            'schema_version' => FakeObjectMeta::SCHEMA_VERSION,
+            'content_type' => $meta->contentType,
+            'checksum_sha256' => $meta->checksumSha256,
+        ], JSON_THROW_ON_ERROR);
+    }
+
+    /**
+     * sidecar 破損 (不正 JSON / 非 object / 欠損キー / 未知 schema / checksum 形式不正) は
+     * 一様に RuntimeException で fail-loud にする (GET コントローラが 500 化して検知する)。
+     */
+    private function decodeMeta(?string $raw): FakeObjectMeta
+    {
+        if (! is_string($raw)) {
+            throw new RuntimeException('fake storage: sidecar を読めません');
+        }
+        try {
+            $decoded = json_decode($raw, true, flags: JSON_THROW_ON_ERROR); // mixed
+        } catch (JsonException $e) {
+            throw new RuntimeException('fake storage: sidecar が不正な JSON です', previous: $e);
+        }
+        if (! is_array($decoded)) {
+            throw new RuntimeException('fake storage: sidecar が object ではありません');
+        }
+        /** @var array<array-key, mixed> $data */
+        $data = $decoded;
+        $version = $data['schema_version'] ?? null;
+        if ($version !== FakeObjectMeta::SCHEMA_VERSION) {
+            throw new RuntimeException('fake storage: sidecar の schema_version が未知です');
+        }
+        $contentType = $data['content_type'] ?? null;
+        $checksum = $data['checksum_sha256'] ?? null;
+        if (! is_string($contentType)) {
+            throw new RuntimeException('fake storage: sidecar content_type 欠損');
+        }
+        if (! is_string($checksum)) {
+            throw new RuntimeException('fake storage: sidecar checksum 欠損');
+        }
+        // base64(sha256) = 32 バイト → base64 44 文字 (末尾 '=')。軽い形式検証で異常を早期検知
+        if (preg_match('/^[A-Za-z0-9+\/]{43}=$/', $checksum) !== 1) {
+            throw new RuntimeException('fake storage: sidecar checksum 形式不正');
+        }
+
+        return new FakeObjectMeta($contentType, $checksum);
+    }
+
+    private function ensureDir(string $dir): void
+    {
+        if (! is_dir($dir) && ! @mkdir($dir, 0775, true) && ! is_dir($dir)) {
+            throw new RuntimeException("fake storage: ディレクトリ作成に失敗しました: {$dir}");
+        }
+    }
+}
diff --git a/app/Services/Storage/Fakes/FakeStorageChecksumMismatch.php b/app/Services/Storage/Fakes/FakeStorageChecksumMismatch.php
new file mode 100644
index 0000000..0d1b43b
--- /dev/null
+++ b/app/Services/Storage/Fakes/FakeStorageChecksumMismatch.php
@@ -0,0 +1,13 @@
+<?php
+
+declare(strict_types=1);
+
+namespace App\Services\Storage\Fakes;
+
+use RuntimeException;
+
+/**
+ * fake storage の PUT で実 body の checksum が期待値 (署名 checksum) と一致しない。
+ * controller が catch して 400 に写像する (実 S3 の checksum 不一致 PUT 拒否の emulation)。
+ */
+final class FakeStorageChecksumMismatch extends RuntimeException {}
diff --git a/app/Services/Storage/Fakes/FakeStorageKey.php b/app/Services/Storage/Fakes/FakeStorageKey.php
new file mode 100644
index 0000000..78f9694
--- /dev/null
+++ b/app/Services/Storage/Fakes/FakeStorageKey.php
@@ -0,0 +1,38 @@
+<?php
+
+declare(strict_types=1);
+
+namespace App\Services\Storage\Fakes;
+
+/**
+ * fake storage signed route の key 検証ヘルパ (多層防御。署名前提でも横断読取/書込面積を縮小する)。
+ */
+final class FakeStorageKey
+{
+    /**
+     * 許可 key の segment 単位検証 (単純 str_contains('..') は誤検知するため segment 分割で判定):
+     * - 先頭 segment は 'projects'
+     * - segment 数 >= 2
+     * - 各 segment: 空でない / `.`・`..` でない / `\`・NUL を含まない
+     */
+    public static function isAllowed(string $key): bool
+    {
+        if (! str_starts_with($key, 'projects/')) {
+            return false;
+        }
+        $segments = explode('/', $key);
+        if (count($segments) < 2) {
+            return false;
+        }
+        foreach ($segments as $segment) {
+            if ($segment === '' || $segment === '.' || $segment === '..') {
+                return false;
+            }
+            if (str_contains($segment, '\\') || str_contains($segment, "\0")) {
+                return false;
+            }
+        }
+
+        return true;
+    }
+}
diff --git a/app/Services/Storage/Fakes/FakeStorageOverCapacity.php b/app/Services/Storage/Fakes/FakeStorageOverCapacity.php
new file mode 100644
index 0000000..1d140c4
--- /dev/null
+++ b/app/Services/Storage/Fakes/FakeStorageOverCapacity.php
@@ -0,0 +1,19 @@
+<?php
+
+declare(strict_types=1);
+
+namespace App\Services\Storage\Fakes;
+
+use RuntimeException;
+
+/**
+ * fake storage の PUT で絶対容量上限 (capture.max_take_bytes) を超過した。
+ * controller が catch して 413 に写像する。
+ */
+final class FakeStorageOverCapacity extends RuntimeException
+{
+    public function __construct(public readonly int $maxBytes)
+    {
+        parent::__construct("fake storage: アップロードサイズが上限 ({$maxBytes} bytes) を超えています");
+    }
+}
diff --git a/app/Support/FakeStorageGate.php b/app/Support/FakeStorageGate.php
new file mode 100644
index 0000000..13e10c1
--- /dev/null
+++ b/app/Support/FakeStorageGate.php
@@ -0,0 +1,41 @@
+<?php
+
+declare(strict_types=1);
+
+namespace App\Support;
+
+use Illuminate\Contracts\Foundation\Application;
+
+/**
+ * storage fake の有効化 predicate の SSOT (fail-secure 二軸)。
+ *
+ * route 登録 (FakeExternalsServiceProvider) と signed route action guard の双方が
+ * 本メソッドを参照する (登録条件より実行時条件が弱いと route cache 残存で素通りするため
+ * 完全一致させる)。
+ *
+ * 二軸:
+ * 1. capability flag: config('testing.fake_storage') === true (既定 false = 完全 no-op)
+ * 2. env allowlist: bughunt.local ∨ (testing ∧ runningUnitTests)
+ *    - bughunt.local: 実 bug-hunt runtime
+ *    - testing ∧ runningUnitTests: 自動テストのみ (testing を HTTP 実行環境として素通ししない)
+ *
+ * production は ProductionEnvGuard が flag=true を deploy 時 fail-fast で拒否する (二重防御)。
+ */
+final readonly class FakeStorageGate
+{
+    public function __construct(private Application $app) {}
+
+    public function enabled(): bool
+    {
+        if (config('testing.fake_storage') !== true) {
+            return false;
+        }
+
+        $env = $this->app->environment();
+        if ($env === 'bughunt.local') {
+            return true;
+        }
+
+        return $env === 'testing' && $this->app->runningUnitTests();
+    }
+}
diff --git a/config/filesystems.php b/config/filesystems.php
index 6cfdfb3..33d898b 100644
--- a/config/filesystems.php
+++ b/config/filesystems.php
@@ -60,6 +60,17 @@
             'report' => false,
         ],
 
+        // bughunt / testing の storage fake 用ローカル disk (実 S3 非依存の emulation)。
+        // FakeTakeObjectStorage / FakeRenderObjectStorage が共有し S3 key namespace を再現する。
+        // 本番では誰も解決しない (FakeStorageGate 成立時のみ fake が bind される限り inert)。
+        // throw=true で失敗を握り潰さない。FILESYSTEM_DISK の default (local) は不変。
+        's3_fake' => [
+            'driver' => 'local',
+            'root' => storage_path('app/s3-fake'),
+            'throw' => true,
+            'report' => false,
+        ],
+
     ],
 
     /*
diff --git a/tests/Feature/Storage/FakeStorageRouteTest.php b/tests/Feature/Storage/FakeStorageRouteTest.php
new file mode 100644
index 0000000..478ba2a
--- /dev/null
+++ b/tests/Feature/Storage/FakeStorageRouteTest.php
@@ -0,0 +1,197 @@
+<?php
+
+declare(strict_types=1);
+
+use App\Services\Capture\TakeObjectStorage;
+use App\Services\Render\RenderObjectStorage;
+use Carbon\CarbonImmutable;
+use Illuminate\Testing\TestResponse;
+
+/*
+ * fake storage signed route (PUT 受け口 / GET serve) の E2E。
+ * gate 有効 (enableFakeStorage) で provider が bind + route を実配線し、
+ * 実 S3 に一切触れず bytes 往復・checksum 三点照合・容量上限・Range 応答を固定する。
+ */
+
+beforeEach(fn () => enableFakeStorage());
+
+const FAKE_KEY = 'projects/1/manuals/2/cuts/3/takes/01ABCDEF.mp4';
+
+/** 実 S3 に触れたら即例外になるよう region 未設定を明示する (negative 担保) */
+function unsetRealS3Region(): void
+{
+    config()->set('filesystems.disks.s3.region', null);
+    config()->set('filesystems.disks.s3.bucket', null);
+}
+
+/** fake storage は container で解決される (provider 実配線) */
+function fakeTakeStorage(): TakeObjectStorage
+{
+    return app(TakeObjectStorage::class);
+}
+
+function presignPut(string $key, string $checksum): string
+{
+    return fakeTakeStorage()->presignUpload(
+        $key,
+        'video/mp4',
+        100,
+        $checksum,
+        CarbonImmutable::now()->addMinutes(30),
+    )->url;
+}
+
+/** signed PUT を実行する (raw body + checksum ヘッダ) */
+function putObject(string $url, string $body, ?string $checksumHeader = null): TestResponse
+{
+    $checksumHeader ??= base64_encode(hash('sha256', $body, true));
+
+    return test()->call('PUT', $url, [], [], [], [
+        'CONTENT_TYPE' => 'video/mp4',
+        'HTTP_X_AMZ_CHECKSUM_SHA256' => $checksumHeader,
+    ], $body);
+}
+
+test('presignUpload → signed PUT → headObject が実 S3 非依存で往復する', function (): void {
+    unsetRealS3Region();
+    $body = 'fake-video-bytes';
+    $checksum = base64_encode(hash('sha256', $body, true));
+
+    $url = presignPut(FAKE_KEY, $checksum);
+    expect($url)->toContain('/_fake-storage/object');
+    expect($url)->toContain('signature=');
+
+    putObject($url, $body)->assertNoContent();
+
+    $meta = fakeTakeStorage()->headObject(FAKE_KEY);
+    expect($meta)->not->toBeNull();
+    expect($meta?->contentLength)->toBe(strlen($body));
+    expect($meta?->contentType)->toBe('video/mp4');
+    expect($meta?->checksumSha256)->toBe($checksum);
+});
+
+test('署名なし PUT は 403 (signed middleware)', function (): void {
+    // 署名クエリを外した素の route path
+    putObject('/_fake-storage/object?key='.rawurlencode(FAKE_KEY).'&checksum=x', 'body')
+        ->assertForbidden();
+});
+
+test('ヘッダ欠落 / ヘッダ != 署名 checksum は 400', function (): void {
+    $body = 'abc';
+    $checksum = base64_encode(hash('sha256', $body, true));
+    $url = presignPut(FAKE_KEY, $checksum);
+
+    // ヘッダ欠落
+    test()->call('PUT', $url, [], [], [], ['CONTENT_TYPE' => 'video/mp4'], $body)
+        ->assertStatus(400);
+
+    // ヘッダ != 署名 checksum
+    putObject($url, $body, checksumHeader: base64_encode(hash('sha256', 'other', true)))
+        ->assertStatus(400);
+});
+
+test('body の checksum が署名値と不一致なら 400 (三点照合 3/3)', function (): void {
+    $signedChecksum = base64_encode(hash('sha256', 'declared', true));
+    $url = presignPut(FAKE_KEY, $signedChecksum);
+
+    // ヘッダは署名値と一致するが body が異なる
+    putObject($url, 'tampered-body', checksumHeader: $signedChecksum)
+        ->assertStatus(400);
+
+    expect(fakeTakeStorage()->exists(FAKE_KEY))->toBeFalse();
+});
+
+test('容量超過は 413', function (): void {
+    config()->set('capture.max_take_bytes', 4);
+    $body = str_repeat('z', 64);
+    $checksum = base64_encode(hash('sha256', $body, true));
+    $url = presignPut(FAKE_KEY, $checksum);
+
+    putObject($url, $body)->assertStatus(413);
+});
+
+test('temporaryPlaybackUrl の signed GET が bytes を返し Range に応答する', function (): void {
+    unsetRealS3Region();
+    $body = 'range-test-bytes-0123456789';
+    $checksum = base64_encode(hash('sha256', $body, true));
+    putObject(presignPut(FAKE_KEY, $checksum), $body)->assertNoContent();
+
+    $getUrl = fakeTakeStorage()->temporaryPlaybackUrl(FAKE_KEY);
+    $full = test()->get($getUrl);
+    $full->assertOk();
+    expect($full->streamedContent())->toBe($body);
+    $full->assertHeader('Content-Type', 'video/mp4');
+
+    // Range: 先頭 4 バイト → 206 partial
+    $partial = test()->call('GET', $getUrl, [], [], [], ['HTTP_RANGE' => 'bytes=0-3']);
+    $partial->assertStatus(206);
+    expect($partial->streamedContent())->toBe(substr($body, 0, 4));
+});
+
+test('未登録 object の GET は 404 (sidecar 欠損=未完了も 404)', function (): void {
+    $getUrl = fakeTakeStorage()->temporaryPlaybackUrl('projects/1/manuals/2/cuts/3/takes/MISSING.mp4');
+    test()->get($getUrl)->assertNotFound();
+});
+
+test('不正 key (traversal) の PUT/GET は 400', function (): void {
+    // 署名は通るが key 検証で 400 (多層防御)
+    $badKey = 'projects/../etc/passwd';
+    $checksum = base64_encode(hash('sha256', 'x', true));
+    $putUrl = presignPut($badKey, $checksum);
+    putObject($putUrl, 'x', checksumHeader: $checksum)->assertStatus(400);
+
+    $getUrl = fakeTakeStorage()->temporaryPlaybackUrl($badKey);
+    test()->get($getUrl)->assertStatus(400);
+});
+
+test('render DL: temporaryDownloadUrl の GET は contentDisposition() 生成のヘッダを返す (注入不能)', function (): void {
+    unsetRealS3Region();
+    $render = app(RenderObjectStorage::class);
+    $key = 'projects/1/manuals/2/renders/v1-1.mp4';
+
+    // render 出力を fake disk へ upload (ローカル一時ファイル経由)
+    $local = tempnam(sys_get_temp_dir(), 'render');
+    expect($local)->not->toBeFalse();
+    file_put_contents((string) $local, 'rendered-mp4-bytes');
+    $render->upload((string) $local, $key);
+    @unlink((string) $local);
+
+    // 改行を含む filename でも Content-Disposition にそのまま流れない
+    $url = $render->temporaryDownloadUrl($key, "evil\r\nInjected: x.mp4");
+    $response = test()->get($url);
+    $response->assertOk();
+    $disposition = (string) $response->headers->get('Content-Disposition');
+    expect($disposition)->toStartWith('attachment; ');
+    expect($disposition)->not->toContain("\r");
+    expect($disposition)->not->toContain("\n");
+});
+
+test('AWS 設定が空でも fake の主要ユースケースは実 S3 に触れず成功する (drift E2E)', function (): void {
+    unsetRealS3Region();
+    $take = fakeTakeStorage();
+    $render = app(RenderObjectStorage::class);
+    $body = 'contract-bytes';
+    $checksum = base64_encode(hash('sha256', $body, true));
+
+    // take: presign→PUT→head→playback→delete
+    putObject(presignPut(FAKE_KEY, $checksum), $body)->assertNoContent();
+    expect($take->headObject(FAKE_KEY))->not->toBeNull();
+    test()->get($take->temporaryPlaybackUrl(FAKE_KEY))->assertOk();
+    $take->delete(FAKE_KEY);
+    expect($take->exists(FAKE_KEY))->toBeFalse();
+
+    // render: upload→downloadToLocal→temporaryDownloadUrl→delete
+    $renderKey = 'projects/1/manuals/2/renders/v1-1.mp4';
+    $local = tempnam(sys_get_temp_dir(), 'render');
+    file_put_contents((string) $local, 'render-bytes');
+    $render->upload((string) $local, $renderKey);
+    @unlink((string) $local);
+
+    $dlTarget = tempnam(sys_get_temp_dir(), 'dl');
+    $render->downloadToLocal($renderKey, (string) $dlTarget);
+    expect(file_get_contents((string) $dlTarget))->toBe('render-bytes');
+    @unlink((string) $dlTarget);
+
+    test()->get($render->temporaryDownloadUrl($renderKey, 'manual.mp4'))->assertOk();
+    $render->delete($renderKey);
+});
diff --git a/tests/Feature/Storage/FakeStorageWiringDefaultTest.php b/tests/Feature/Storage/FakeStorageWiringDefaultTest.php
new file mode 100644
index 0000000..384617a
--- /dev/null
+++ b/tests/Feature/Storage/FakeStorageWiringDefaultTest.php
@@ -0,0 +1,28 @@
+<?php
+
+declare(strict_types=1);
+
+use App\Services\Capture\Fakes\FakeTakeObjectStorage;
+use App\Services\Capture\TakeObjectStorage;
+use App\Services\Render\Fakes\FakeRenderObjectStorage;
+use App\Services\Render\RenderObjectStorage;
+use Illuminate\Support\Facades\Route;
+
+/*
+ * provider 統合 (fake OFF = 既定): fake_storage 未設定では実クラスが解決され
+ * fake route が一切生えないことを固定する (本番安全側の既定 = 完全 no-op)。
+ */
+
+test('既定 (fake_storage off) では実 storage クラスが解決され fake route は存在しない', function (): void {
+    expect(config('testing.fake_storage'))->toBeFalse();
+
+    $take = app(TakeObjectStorage::class);
+    $render = app(RenderObjectStorage::class);
+    expect($take)->not->toBeInstanceOf(FakeTakeObjectStorage::class);
+    expect($render)->not->toBeInstanceOf(FakeRenderObjectStorage::class);
+    expect($take::class)->toBe(TakeObjectStorage::class);
+    expect($render::class)->toBe(RenderObjectStorage::class);
+
+    expect(Route::has('bughunt.storage.put'))->toBeFalse();
+    expect(Route::has('bughunt.storage.get'))->toBeFalse();
+});
diff --git a/tests/Feature/Storage/FakeStorageWiringTest.php b/tests/Feature/Storage/FakeStorageWiringTest.php
new file mode 100644
index 0000000..be31776
--- /dev/null
+++ b/tests/Feature/Storage/FakeStorageWiringTest.php
@@ -0,0 +1,30 @@
+<?php
+
+declare(strict_types=1);
+
+use App\Services\Capture\Fakes\FakeTakeObjectStorage;
+use App\Services\Capture\TakeObjectStorage;
+use App\Services\Render\Fakes\FakeRenderObjectStorage;
+use App\Services\Render\RenderObjectStorage;
+use Illuminate\Support\Facades\Route;
+
+/*
+ * provider 統合 (fake ON): fake_storage だけで (fake_externals=false・fake_llm=false)
+ * storage の bind と signed route が provider 実配線で確立することを固定する
+ * (capability 別 early return が storage を巻き込まないことの回帰)。
+ */
+
+beforeEach(fn () => enableFakeStorage());
+
+test('fake_storage だけで storage fake が bind され signed route が登録される', function (): void {
+    // 他 capability が off のまま storage だけ有効 (独立性)
+    expect(config('testing.fake_externals'))->toBeFalse();
+    expect(config('testing.fake_llm'))->toBeFalse();
+    expect(config('testing.fake_storage'))->toBeTrue();
+
+    expect(app(TakeObjectStorage::class))->toBeInstanceOf(FakeTakeObjectStorage::class);
+    expect(app(RenderObjectStorage::class))->toBeInstanceOf(FakeRenderObjectStorage::class);
+
+    expect(Route::has('bughunt.storage.put'))->toBeTrue();
+    expect(Route::has('bughunt.storage.get'))->toBeTrue();
+});
diff --git a/tests/Pest.php b/tests/Pest.php
index 935026f..9a6b603 100644
--- a/tests/Pest.php
+++ b/tests/Pest.php
@@ -8,9 +8,12 @@
 use App\Models\Organization;
 use App\Models\Project;
 use App\Models\User;
+use App\Providers\FakeExternalsServiceProvider;
 use App\Services\AI\Testing\CannedPromptFakeRegistrar;
 use App\Services\Organization\OrganizationProvisioningService;
+use App\Services\Storage\Fakes\FakeObjectStore;
 use Illuminate\Foundation\Testing\RefreshDatabase;
+use Illuminate\Support\Facades\Storage;
 use Illuminate\Support\Facades\Vite;
 use Illuminate\Support\Str;
 use Kent013\PrismPrompt\Prompt;
@@ -227,3 +230,30 @@ function attachProjectMember(
 ): void {
     $project->members()->attach($user, ['role' => $role->value]);
 }
+
+/**
+ * storage fake を有効化する (Feature テスト用)。
+ *
+ * config('testing.fake_storage')=true にした上で **provider 自身を再実走** させ、
+ * bind と signed route を確立する (手動 bind/route 再実装は provider の欠陥を隠すため禁止)。
+ * app env は phpunit.xml の testing + runningUnitTests()===true のため FakeStorageGate が成立する。
+ * s3_fake disk は Storage::fake で tmp へ隔離し、実 s3 disk は放置 =
+ * もし実 S3 に触れたら即例外になる (fake が実 S3 非依存であることの negative 担保)。
+ *
+ * 各テストは setUp の refreshApplication で fresh app + fresh config を得るため、
+ * 明示的な env/config の後始末は不要 (テスト間リークしない)。
+ */
+function enableFakeStorage(): void
+{
+    config()->set('testing.fake_storage', true);
+
+    $provider = new FakeExternalsServiceProvider(app());
+    $provider->register();
+    $provider->boot();
+    // provider の register()/boot() は本来 bootstrap 時に走り、フレームワークが route 読込後に
+    // name lookup を再構築する。テストでは boot を後追いで実行するため、その最終手順のみ明示的に補う
+    // (route 自体は provider が登録済み = 配線ロジックは provider を実走して検証している)。
+    app('router')->getRoutes()->refreshNameLookups();
+
+    Storage::fake(FakeObjectStore::DISK);
+}
diff --git a/tests/Unit/Services/Storage/FakeObjectStoreConcurrencyTest.php b/tests/Unit/Services/Storage/FakeObjectStoreConcurrencyTest.php
new file mode 100644
index 0000000..c41df7a
--- /dev/null
+++ b/tests/Unit/Services/Storage/FakeObjectStoreConcurrencyTest.php
@@ -0,0 +1,97 @@
+<?php
+
+declare(strict_types=1);
+
+use App\Services\Storage\Fakes\FakeObjectStore;
+use Illuminate\Support\Facades\Storage;
+
+/*
+ * 同一 key の writer/reader 直列化契約 (Round 3/4 Critical)。
+ *
+ * cross-process の blocking 時間を計る time-dependent 判定は flaky 化するため採らない。
+ * 代わりに (1) store が object とは別 namespace の `.locks/` ロックファイルを実際に使うこと、
+ * (2) そのロックファイル上で LOCK_EX が writer/reader (LOCK_SH) を排他すること
+ * = promote/head/delete が異世代の object/meta を組み合わせない土台を、
+ * 単一プロセス内の LOCK_NB プローブで決定的に固定する
+ * (Linux の flock は open file description 単位 = 同一プロセスでも別 fopen なら排他する)。
+ */
+
+beforeEach(function (): void {
+    Storage::fake(FakeObjectStore::DISK);
+});
+
+/** @return resource */
+function streamFrom(string $body)
+{
+    $stream = fopen('php://temp', 'r+b');
+    fwrite($stream, $body);
+    rewind($stream);
+
+    return $stream;
+}
+
+function lockPathFor(string $key): string
+{
+    return Storage::disk(FakeObjectStore::DISK)->path('.locks/'.sha1($key).'.lock');
+}
+
+test('store は object とは別 namespace の .locks/ ロックファイルを使う', function (): void {
+    $store = new FakeObjectStore;
+    $key = 'projects/1/manuals/2/cuts/3/takes/01LOCK.mp4';
+    $body = 'bytes';
+
+    $store->storeStreamed($key, streamFrom($body), 'video/mp4', base64_encode(hash('sha256', $body, true)));
+
+    // ロックファイルが .locks/ に生成され、object listing (allFiles) を汚さない
+    expect(file_exists(lockPathFor($key)))->toBeTrue();
+    $objectFiles = collect(Storage::disk(FakeObjectStore::DISK)->allFiles())
+        ->reject(fn (string $p): bool => str_starts_with($p, '.locks/'));
+    expect($objectFiles)->toContain($key);
+});
+
+test('key ロック上の LOCK_EX は writer(LOCK_EX) と reader(LOCK_SH) を排他し、解放で reader が進む', function (): void {
+    $store = new FakeObjectStore;
+    $key = 'projects/1/manuals/2/cuts/3/takes/01EXCL.mp4';
+    $body = 'bytes';
+    // ロックファイルを生成させる (store が使う実パスを得る)
+    $store->storeStreamed($key, streamFrom($body), 'video/mp4', base64_encode(hash('sha256', $body, true)));
+
+    $path = lockPathFor($key);
+
+    // writer 相当: LOCK_EX を保持
+    $writer = fopen($path, 'c');
+    expect(flock($writer, LOCK_EX))->toBeTrue();
+
+    // 別 open file description からの probe: writer 保持中は EX も SH も取得できない
+    $probe = fopen($path, 'c');
+    expect(flock($probe, LOCK_EX | LOCK_NB))->toBeFalse(); // 別 writer (promote/delete) は待つ
+    expect(flock($probe, LOCK_SH | LOCK_NB))->toBeFalse(); // reader (head) も待つ
+
+    // writer 解放後は reader が即座に進める
+    expect(flock($writer, LOCK_UN))->toBeTrue();
+    expect(flock($probe, LOCK_SH | LOCK_NB))->toBeTrue();
+
+    flock($probe, LOCK_UN);
+    fclose($probe);
+    fclose($writer);
+});
+
+test('reader の共有ロック中は別 reader は入れるが writer は待つ', function (): void {
+    $store = new FakeObjectStore;
+    $key = 'projects/1/manuals/2/cuts/3/takes/01SHARED.mp4';
+    $body = 'bytes';
+    $store->storeStreamed($key, streamFrom($body), 'video/mp4', base64_encode(hash('sha256', $body, true)));
+    $path = lockPathFor($key);
+
+    $reader = fopen($path, 'c');
+    expect(flock($reader, LOCK_SH))->toBeTrue();
+
+    $probe = fopen($path, 'c');
+    expect(flock($probe, LOCK_SH | LOCK_NB))->toBeTrue();  // 複数 reader は同時可
+    flock($probe, LOCK_UN);
+    expect(flock($probe, LOCK_EX | LOCK_NB))->toBeFalse(); // writer (promote/delete) は待つ
+
+    flock($reader, LOCK_UN);
+    fclose($reader);
+    fclose($probe);
+});
diff --git a/tests/Unit/Services/Storage/FakeObjectStoreTest.php b/tests/Unit/Services/Storage/FakeObjectStoreTest.php
new file mode 100644
index 0000000..fbe4b6a
--- /dev/null
+++ b/tests/Unit/Services/Storage/FakeObjectStoreTest.php
@@ -0,0 +1,141 @@
+<?php
+
+declare(strict_types=1);
+
+use App\Services\Storage\Fakes\FakeObjectStore;
+use App\Services\Storage\Fakes\FakeStorageChecksumMismatch;
+use App\Services\Storage\Fakes\FakeStorageOverCapacity;
+use Illuminate\Support\Facades\Storage;
+
+/*
+ * FakeObjectStore: s3_fake disk 上の stream 保存 / head / delete と
+ * checksum 三者一致・容量上限・completion marker (sidecar) を固定する。
+ */
+
+beforeEach(function (): void {
+    Storage::fake(FakeObjectStore::DISK);
+});
+
+/** @return resource */
+function streamOf(string $body)
+{
+    $stream = fopen('php://temp', 'r+b');
+    expect($stream)->not->toBeFalse();
+    fwrite($stream, $body);
+    rewind($stream);
+
+    return $stream;
+}
+
+function checksumOf(string $body): string
+{
+    return base64_encode(hash('sha256', $body, true));
+}
+
+test('storeStreamed 正常: head が size / content_type / checksum を返す', function (): void {
+    $store = new FakeObjectStore;
+    $body = 'video-bytes';
+    $key = 'projects/1/manuals/2/cuts/3/takes/01ABC.mp4';
+
+    $store->storeStreamed($key, streamOf($body), 'video/mp4', checksumOf($body));
+
+    $meta = $store->head($key);
+    expect($meta)->not->toBeNull();
+    expect($meta?->contentLength)->toBe(strlen($body));
+    expect($meta?->contentType)->toBe('video/mp4');
+    expect($meta?->checksumSha256)->toBe(checksumOf($body));
+});
+
+test('storeStreamed checksum 不一致: 例外 + object 未確定 (head null)', function (): void {
+    $store = new FakeObjectStore;
+    $key = 'projects/1/manuals/2/cuts/3/takes/01ABC.mp4';
+
+    expect(fn () => $store->storeStreamed($key, streamOf('real-bytes'), 'video/mp4', checksumOf('other-bytes')))
+        ->toThrow(FakeStorageChecksumMismatch::class);
+
+    expect($store->head($key))->toBeNull();
+    expect($store->exists($key))->toBeFalse();
+});
+
+test('storeStreamed 容量超過: 例外 + 一時ファイル残存なし', function (): void {
+    config()->set('capture.max_take_bytes', 8);
+    $store = new FakeObjectStore;
+    $key = 'projects/1/manuals/2/cuts/3/takes/01ABC.mp4';
+    $body = str_repeat('x', 64);
+
+    expect(fn () => $store->storeStreamed($key, streamOf($body), 'video/mp4', checksumOf($body)))
+        ->toThrow(FakeStorageOverCapacity::class);
+
+    expect($store->head($key))->toBeNull();
+    // .uploading-* 一時ファイルが残っていない (disk 直下を走査)
+    $leftovers = collect(Storage::disk(FakeObjectStore::DISK)->allFiles())
+        ->filter(fn (string $p): bool => str_contains($p, '.uploading-'));
+    expect($leftovers)->toBeEmpty();
+});
+
+test('putStreamWithMeta: 容量上限なしで content_type=video/mp4 の sidecar を生成する', function (): void {
+    config()->set('capture.max_take_bytes', 4); // storeStreamed なら超過するサイズ
+    $store = new FakeObjectStore;
+    $key = 'projects/1/manuals/2/renders/v1-1.mp4';
+    $body = str_repeat('y', 64);
+
+    $store->putStreamWithMeta($key, streamOf($body), 'video/mp4');
+
+    $meta = $store->head($key);
+    expect($meta?->contentType)->toBe('video/mp4');
+    expect($meta?->contentLength)->toBe(64);
+});
+
+test('object あり sidecar なし: head は null (PUT 未完了 = crash 途中扱い)', function (): void {
+    $store = new FakeObjectStore;
+    $key = 'projects/1/manuals/2/cuts/3/takes/01ABC.mp4';
+    // sidecar を書かず object だけ置く
+    Storage::disk(FakeObjectStore::DISK)->put($key, 'orphan');
+
+    expect($store->head($key))->toBeNull();
+});
+
+test('sidecar 破損 (不正 JSON / 未知 schema / checksum 形式不正) は fail-loud', function (string $sidecar): void {
+    $store = new FakeObjectStore;
+    $key = 'projects/1/manuals/2/cuts/3/takes/01ABC.mp4';
+    $disk = Storage::disk(FakeObjectStore::DISK);
+    $disk->put($key, 'bytes');
+    $disk->put($key.'.meta.json', $sidecar);
+
+    expect(fn () => $store->head($key))->toThrow(RuntimeException::class);
+})->with([
+    '不正 JSON' => ['{not json'],
+    '未知 schema' => ['{"schema_version":99,"content_type":"video/mp4","checksum_sha256":"'.'A'.'"}'],
+    'content_type 欠損' => ['{"schema_version":1,"checksum_sha256":"'.str_repeat('A', 43).'="}'],
+    'checksum 形式不正' => ['{"schema_version":1,"content_type":"video/mp4","checksum_sha256":"short"}'],
+]);
+
+test('上書き PUT: head は新 meta を返す (旧 meta 混同なし)', function (): void {
+    $store = new FakeObjectStore;
+    $key = 'projects/1/manuals/2/cuts/3/takes/01ABC.mp4';
+
+    $store->storeStreamed($key, streamOf('old'), 'video/mp4', checksumOf('old'));
+    $store->storeStreamed($key, streamOf('brand-new'), 'video/webm', checksumOf('brand-new'));
+
+    $meta = $store->head($key);
+    expect($meta?->contentType)->toBe('video/webm');
+    expect($meta?->checksumSha256)->toBe(checksumOf('brand-new'));
+    expect($meta?->contentLength)->toBe(strlen('brand-new'));
+});
+
+test('delete は object + sidecar を消し、二重 delete でも例外を出さない (冪等)', function (): void {
+    $store = new FakeObjectStore;
+    $key = 'projects/1/manuals/2/cuts/3/takes/01ABC.mp4';
+
+    $store->storeStreamed($key, streamOf('bytes'), 'video/mp4', checksumOf('bytes'));
+    expect($store->exists($key))->toBeTrue();
+
+    $store->delete($key);
+    expect($store->exists($key))->toBeFalse();
+    expect($store->head($key))->toBeNull();
+    expect(Storage::disk(FakeObjectStore::DISK)->exists($key.'.meta.json'))->toBeFalse();
+
+    // 冪等: 不在 key の delete は no-op
+    $store->delete($key);
+    expect($store->exists($key))->toBeFalse();
+});
diff --git a/tests/Unit/Services/Storage/FakeStorageContractTest.php b/tests/Unit/Services/Storage/FakeStorageContractTest.php
new file mode 100644
index 0000000..9830b26
--- /dev/null
+++ b/tests/Unit/Services/Storage/FakeStorageContractTest.php
@@ -0,0 +1,63 @@
+<?php
+
+declare(strict_types=1);
+
+use App\Services\Capture\Fakes\FakeTakeObjectStorage;
+use App\Services\Capture\TakeObjectStorage;
+use App\Services\Render\Fakes\FakeRenderObjectStorage;
+use App\Services\Render\RenderObjectStorage;
+
+/*
+ * drift 検知 (reflection): fake が「S3 到達性を持つメソッド」の明示 inventory を override し、
+ * 親に S3 依存メソッドが増えても未 override で実 S3 に落ちないことを固定する。
+ */
+
+/** 指定メソッドが $fakeClass で宣言されている (= override 済) か */
+function isOverriddenOn(string $fakeClass, string $method): bool
+{
+    $reflection = new ReflectionMethod($fakeClass, $method);
+
+    return $reflection->getDeclaringClass()->getName() === $fakeClass;
+}
+
+test('FakeTakeObjectStorage は S3 到達メソッドをすべて override する', function (string $method): void {
+    expect(isOverriddenOn(FakeTakeObjectStorage::class, $method))->toBeTrue();
+})->with([
+    'presignUpload',
+    'headObject',
+    'temporaryPlaybackUrl',
+    'delete',
+    'exists',
+    'client', // 実 S3 client を構築しない (fail-loud)
+]);
+
+test('FakeTakeObjectStorage::client は実 S3 client を構築せず fail-loud する', function (): void {
+    $fake = app(FakeTakeObjectStorage::class);
+    $client = new ReflectionMethod($fake, 'client');
+    $client->setAccessible(true);
+
+    expect(fn () => $client->invoke($fake))->toThrow(RuntimeException::class);
+});
+
+test('FakeRenderObjectStorage は disk 直叩きメソッドを override する', function (string $method): void {
+    expect(isOverriddenOn(FakeRenderObjectStorage::class, $method))->toBeTrue();
+})->with([
+    'disk', // downloadToLocal は disk() override 経由で fake disk を読む
+    'upload',
+    'temporaryPlaybackUrl',
+    'temporaryDownloadUrl',
+    'delete',
+]);
+
+test('意図的継承 (contentDisposition / keyPrefixFor) は override 不要 (inventory 外)', function (): void {
+    // 継承のままでも S3 に到達しない純粋メソッド = fake 側で宣言していないことを固定
+    expect(isOverriddenOn(FakeRenderObjectStorage::class, 'contentDisposition'))->toBeFalse();
+    expect(isOverriddenOn(FakeRenderObjectStorage::class, 'keyPrefixFor'))->toBeFalse();
+    // downloadToLocal も fake 側で再宣言せず親実装 (disk() 経由) を継承する
+    expect(isOverriddenOn(FakeRenderObjectStorage::class, 'downloadToLocal'))->toBeFalse();
+});
+
+test('fake は親クラスの subtype である (container bind の LSP 前提)', function (): void {
+    expect(is_subclass_of(FakeTakeObjectStorage::class, TakeObjectStorage::class))->toBeTrue();
+    expect(is_subclass_of(FakeRenderObjectStorage::class, RenderObjectStorage::class))->toBeTrue();
+});
diff --git a/tests/Unit/Services/Storage/FakeStorageKeyTest.php b/tests/Unit/Services/Storage/FakeStorageKeyTest.php
new file mode 100644
index 0000000..b17cb7e
--- /dev/null
+++ b/tests/Unit/Services/Storage/FakeStorageKeyTest.php
@@ -0,0 +1,39 @@
+<?php
+
+declare(strict_types=1);
+
+use App\Services\Storage\Fakes\FakeStorageKey;
+
+/*
+ * FakeStorageKey: signed route の多層防御 key 検証 (segment 単位)。
+ */
+
+test('projects/ prefix + 2 segment 以上の正規 key を許可する', function (): void {
+    expect(FakeStorageKey::isAllowed('projects/1/manuals/2/cuts/3/takes/01ABC.mp4'))->toBeTrue();
+    expect(FakeStorageKey::isAllowed('projects/1/manuals/2/renders/v1-1.mp4'))->toBeTrue();
+});
+
+test('projects/ 以外の prefix を拒否する', function (string $key): void {
+    expect(FakeStorageKey::isAllowed($key))->toBeFalse();
+})->with([
+    'other prefix' => ['secrets/1/a.mp4'],
+    'no slash' => ['projects'],
+    'projects のみ' => ['projects/'],
+    'empty' => [''],
+    '絶対パス' => ['/projects/1/a.mp4'],
+]);
+
+test('.. / . / バックスラッシュ / NUL を含む segment を拒否する (traversal 防御)', function (string $key): void {
+    expect(FakeStorageKey::isAllowed($key))->toBeFalse();
+})->with([
+    'parent traversal' => ['projects/../etc/passwd'],
+    'current dir' => ['projects/./1/a.mp4'],
+    '空 segment' => ['projects//a.mp4'],
+    'バックスラッシュ' => ['projects/1\\..\\a.mp4'],
+    'NUL' => ["projects/1/a\0.mp4"],
+]);
+
+test('segment 内の .. を含む文字列だが独立 segment でないものは誤検知しない', function (): void {
+    // 'a..b' は '..' segment ではないため許可される (単純 str_contains との差分)
+    expect(FakeStorageKey::isAllowed('projects/1/a..b.mp4'))->toBeTrue();
+});
diff --git a/tests/Unit/Support/FakeStorageGateTest.php b/tests/Unit/Support/FakeStorageGateTest.php
new file mode 100644
index 0000000..991aa63
--- /dev/null
+++ b/tests/Unit/Support/FakeStorageGateTest.php
@@ -0,0 +1,47 @@
+<?php
+
+declare(strict_types=1);
+
+use App\Support\FakeStorageGate;
+use Illuminate\Contracts\Foundation\Application;
+
+/*
+ * FakeStorageGate (predicate SSOT): fail-secure 二軸 (flag + env allowlist) を固定する。
+ */
+
+/** environment()/runningUnitTests() を差し替えた Application 上で gate を評価する */
+function evaluateGate(string $environment, bool $runningUnitTests, bool $flag): bool
+{
+    config()->set('testing.fake_storage', $flag);
+
+    $app = Mockery::mock(Application::class);
+    $app->shouldReceive('environment')->withNoArgs()->andReturn($environment);
+    $app->shouldReceive('runningUnitTests')->withNoArgs()->andReturn($runningUnitTests);
+
+    return (new FakeStorageGate($app))->enabled();
+}
+
+test('flag off なら常に false (完全 no-op)', function (): void {
+    expect(evaluateGate('bughunt.local', true, false))->toBeFalse();
+    expect(evaluateGate('testing', true, false))->toBeFalse();
+});
+
+test('bughunt.local + flag → true', function (): void {
+    expect(evaluateGate('bughunt.local', false, true))->toBeTrue();
+});
+
+test('testing + runningUnitTests + flag → true', function (): void {
+    expect(evaluateGate('testing', true, true))->toBeTrue();
+});
+
+test('testing だが runningUnitTests=false + flag → false (HTTP 実行時の誤通過を封じる)', function (): void {
+    expect(evaluateGate('testing', false, true))->toBeFalse();
+});
+
+test('local + flag → false (allowlist 外)', function (): void {
+    expect(evaluateGate('local', true, true))->toBeFalse();
+});
+
+test('production + flag → false (allowlist 外)', function (): void {
+    expect(evaluateGate('production', true, true))->toBeFalse();
+});
```
