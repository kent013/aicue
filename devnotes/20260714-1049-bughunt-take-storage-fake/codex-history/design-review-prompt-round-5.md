# 詳細設計レビュー Round 5

Round 4 の指摘（head の LOCK_SH、delete の LOCK_EX、GET 世代ずれ制約の明記、reader/writer 競合テスト）を反映しました。全体判定を再度お願いします。

主な変更:
- 施策3: withKeyLock を (key, operation, critical) に一般化しジェネリック戻り値化。head() を LOCK_SH 下で object 確認・sidecar 読込・size 取得まで一括。delete() を LOCK_EX 下で object+sidecar 削除。promote は LOCK_EX。
- 施策6: GET ストリーミングの整合スコープを明記（強整合は登録時 HEAD まで。take key は予約ごと一意 ULID で同一 key 並行上書きは起きない。playback/download は確定済み・不変 object を読む）。
- 施策9: FakeObjectStoreConcurrencyTest に reader/writer 競合（head は null か同一世代のみ）+ delete×promote 競合を追加。

## 対応マトリクス（Round 4 指摘への対応）

# 対応マトリクス: design-review Round 4

## [Critical] head() がロックを取らず reader が異世代 object/meta を組み合わせる
- 判断: 対応する
- 対応内容: `withKeyLock` を `$operation`（LOCK_EX/LOCK_SH）+ ジェネリック戻り値に一般化。`head()` を **LOCK_SH** 下で object 確認・sidecar 確認/読込・size 取得まで一括実行。promote の LOCK_EX と排他され、exists→get 間の削除による例外も防止。

## [Warning] delete() がロック外で promote と競合し不安定
- 判断: 対応する
- 対応内容: `delete()` を同一 key の **LOCK_EX** 下で object+sidecar 削除に変更。

## [Warning] GET は head() 後にロック解放してから本文を読むため世代ずれの可能性
- 判断: 対応する（制約を明記し、テストスコープを限定）
- 根拠: take key は予約ごとの一意 ULID で実フロー上「同一 key 並行上書き」は起きない。playback/download は登録確定済み・以後不変の object を読むため世代ずれは発生しない。
- 対応内容: 「強整合は登録時 HEAD まで、GET 配信中の共有ロック保持は要求しない」を設計に明記（emulator の防御的堅牢化として key ロックは promote/head/delete に限定）。

## [Critical] 並行契約テストに reader/writer 競合を追加
- 判断: 対応する
- 対応内容: `FakeObjectStoreConcurrencyTest` に「writer を head() の各 filesystem 操作へ割り込ませても null か同一世代 metadata のみ」「delete×promote 競合で不整合が出ない」を追加。


---

## 更新後の詳細設計（全文）

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
