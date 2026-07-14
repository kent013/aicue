# 詳細設計レビュー Round 2

Round 1 の 2 Critical + Warning/Suggestion を反映しました。対応マトリクスと更新後の詳細設計（全文）を送ります。全体判定を再度お願いします。

主な変更:
- 施策3 FakeObjectStore: パスを `disk()->path($key)` 基準に統一（Storage::fake 整合）。fwrite 部分書き込み対策 (writeAll)。putStreamWithMeta 追加（render 用 sidecar 生成）。contentTypeOf 廃止し head() へ一本化。decodeMeta に checksum 形式検証。
- 施策5 FakeRenderObjectStorage: upload が putStreamWithMeta で sidecar 生成。親 RenderObjectStorage を disk() 抽象化。
- 施策6 GET controller: head() で存在+完了+content_type を一括判定（404/200）。FakeStorageKey で prefix 検証。
- 施策9: テストハーネス設定 (9-0) 明文化。E2E 契約テスト (AWS 未設定でも成功) と FakeStorageKey/gate 追加ケース。

## 対応マトリクス（Round 1 指摘への対応）

# 対応マトリクス: design-review Round 1

## [Critical] 施策3: `storage_path('app/s3-fake')` ハードコードが `Storage::fake('s3_fake')` と衝突
- 判断: 対応する
- 根拠: `Storage::fake` は disk root を tmp へ差し替えるため、root 直参照だとテスト時に別領域を書き実装/テスト不整合。
- 対応内容: 一時ファイル・確定先ともに `Storage::disk(self::DISK)->path($key)` を基準にする。temp は同ディレクトリに作り（同一 filesystem）、`dirname()` を `ensureDir`。root ハードコード撤廃。パス整合を Unit テストで固定。

## [Critical] 施策9: `Storage::fake` 併用時の初期化順・env 注入順・provider 再解決が未定義
- 判断: 対応する
- 根拠: provider の register/boot は bootstrap 時に config を読むため、テスト body で config を変えても route/bind は遡って登録されない。
- 対応内容: テスト設定パターンを明文化。(a) Unit（FakeObjectStore/FakeTakeObjectStorage 等）は route/provider 不要 = 直接 new + `Storage::fake('s3_fake')`。(b) signed route を要する Feature は `withFakeStorage()` ヘルパで「config(['testing.fake_storage'=>true]) → fake を bind → provider の route 登録ロジックを再実行」を beforeEach で行う（testing かつ runningUnitTests=true で gate 成立）。app 全体再起動は不要（route/ bind の明示再登録で足りる）ことを明記。

## [Warning] 施策3: `fwrite` 戻り値未検証で部分書き込みを見逃す
- 判断: 対応する
- 対応内容: 書込完了までループ再試行し、`false` は例外化するヘルパ `writeAll()` を追加。

## [Warning] 施策3/6: sidecar 欠損時 `contentTypeOf()` が 500 になる
- 判断: 対応する
- 根拠: 「object あり sidecar 無し」は未完了扱い = 404 が自然。
- 対応内容: GET コントローラは `contentTypeOf()` を使わず `head($key)` を呼び、null→404、値あり→`contentType` 使用に統一。`contentTypeOf()` は廃止（head へ一本化）。

## [Warning] 施策5: render `upload()` が sidecar を書かず GET DL と契約が崩れる
- 判断: 対応する
- 根拠: render 成果物も `bughunt.storage.get` で配信するため sidecar（content_type）が必要。
- 対応内容: `FakeObjectStore` に `putStreamWithMeta(string $key, resource $in, string $contentType): void`（checksum 照合なしでストリーム保存 + sidecar 生成、容量上限は適用）を追加。render `upload()` はこれ経由で `video/mp4` の sidecar を必ず生成。

## [Warning] 施策9: drift 契約テストが reflection だけだと protected hook 追加時の実 S3 到達を取りこぼす
- 判断: 対応する
- 対応内容: reflection（public surface 網羅）に加え、**fake モードで主要ユースケース（take presign/head, render upload/download/url）を実行し「AWS region 未設定でも成功（実 S3 非到達）」を契約として固定する E2E 契約テスト**を追加。

## [Suggestion] key プレフィックス allowlist / checksum 形式検証 / パス整合テスト / testing∧¬unit テスト
- 判断: 対応する（多層防御・堅牢化）
- 対応内容: PUT/GET コントローラで `key` が `projects/` プレフィックス（+ `..` 不含）であることを最小検証（署名漏洩時の横断読取縮小）。`decodeMeta` で checksum が base64 sha256 長（44 文字・末尾 `=`）を軽く検証。FakeObjectStore の path 整合と gate の testing∧runningUnitTests=false ケースを明示テスト化。

## [Suggestion] 施策5: 親 `disk()` 抽象化 / 施策7: worker 起動前 env 明記
- 判断: 対応する
- 対応内容: `RenderObjectStorage` を `protected function disk(): Filesystem { return Storage::disk('s3'); }` に薄くリファクタし fake は disk 名のみ override（重複削減・drift 低減、既存テスト不変厳守）。運用手順に「`queue:listen` 起動前に `TESTING_FAKE_STORAGE=true` が worker env に入っていること（bughunt は `scripts/bug-hunt-shard.sh` が担保）」を明記。


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
            $this->streamInto($input, $out, $ctx); // 容量上限・部分書き込みを担保
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
     * checksum 照合なしのストリーム保存 (render 出力・take footage コピー等の内部生成物)。
     * sidecar (content_type) を必ず生成する = GET 配信の contract を満たす (Round 1 Warning)。
     *
     * @param  resource  $input
     * @throws FakeStorageOverCapacity 絶対容量上限超過
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
            $this->streamInto($input, $out, $ctx);
        } catch (\Throwable $e) {
            fclose($out);
            @unlink($tmp);
            throw $e;
        }
        fclose($out);

        $this->promote($key, $tmp, $target, new FakeObjectMeta($contentType, base64_encode(hash_final($ctx, true))));
    }

    /**
     * $input を $out へ流しつつ sha256 を計算。容量上限超過は中断、fwrite の部分書き込みは完了まで再試行。
     *
     * @param  resource  $input
     * @param  resource  $out
     * @param  \HashContext  $ctx
     */
    private function streamInto($input, $out, \HashContext $ctx): void
    {
        $maxBytes = config()->integer('capture.max_take_bytes');
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
            if ($total > $maxBytes) {
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

    /** atomic rename で確定し、sidecar を最後に書く (= completion marker) */
    private function promote(string $key, string $tmp, string $target, FakeObjectMeta $meta): void
    {
        if (! @rename($tmp, $target)) {
            @unlink($tmp);
            throw new RuntimeException('fake storage: object の確定に失敗しました');
        }
        $this->disk()->put($this->sidecarKey($key), $this->encodeMeta($meta));
    }

    /**
     * HeadObject 相当。状態別に固定:
     * - object 不在 → null (PUT 未着手)
     * - object あり sidecar 不在 → null (PUT 未完了 = crash 途中)
     * - sidecar 破損 (不正 JSON/欠損キー/未知 schema) → fail-loud (RuntimeException)
     */
    public function head(string $key): ?ObjectMetadataData
    {
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
    }

    public function delete(string $key): void
    {
        $this->disk()->delete([$key, $this->sidecarKey($key)]); // 冪等 (不在は no-op)
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
            /** @var array<string, mixed> $data */
            $data = json_decode($raw, true, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            throw new RuntimeException('fake storage: sidecar が不正な JSON です', previous: $e);
        }
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
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use RuntimeException;

/**
 * RenderObjectStorage の fake。read/write/署名URL/delete を s3_fake disk 経由に。
 * contentDisposition() / keyPrefixFor() は親を継承 (契約不変)。
 * take footage は FakeTakeObjectStorage と同一 s3_fake disk・同一 key で読める。
 */
final class FakeRenderObjectStorage extends RenderObjectStorage
{
    public function __construct(private readonly FakeObjectStore $store) {}

    public function downloadToLocal(string $key, string $localPath): void
    {
        $stream = Storage::disk(FakeObjectStore::DISK)->readStream($key);
        if ($stream === null) {
            throw new RuntimeException("fake storage: object を読めません: {$key}");
        }
        // 以降の readStream→ローカル書き込みは親と同ロジック (共通化しても可)
        $local = fopen($localPath, 'wb');
        if ($local === false) {
            throw new RuntimeException("ローカルファイルを開けません: {$localPath}");
        }
        try {
            if (stream_copy_to_stream($stream, $local) === false) {
                throw new RuntimeException("fake storage: コピー失敗: {$key}");
            }
        } finally {
            fclose($local);
            if (is_resource($stream)) {
                fclose($stream);
            }
        }
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
親の `Storage::disk('s3')` 直書きを 1 箇所に集約する（後方互換の並走を残さない）:
```php
// RenderObjectStorage (親) に追加。既存メソッドは disk() 経由へ置換 (挙動不変)。
protected function disk(): \Illuminate\Contracts\Filesystem\Filesystem
{
    return Storage::disk('s3');
}
```
fake は `downloadToLocal` の `readStream` 部分のみ disk 名差し替えで override（`upload` は sidecar 生成のため上記 `putStreamWithMeta` 経由に置換）。**親の挙動は不変**で、既存 `RenderObjectStorageTest`/`RenderRetentionTest` が緑であることを厳守（`Storage::fake('s3')` 前提の既存テストは disk() 経由でも同一挙動）。

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

**`FakeStorageKey`（新規・key 検証ヘルパ、`app/Services/Storage/Fakes/FakeStorageKey.php`）**:
```php
final class FakeStorageKey
{
    /** 許可 prefix (`projects/`) かつ path traversal (`..`) を含まないこと */
    public static function isAllowed(string $key): bool
    {
        return str_starts_with($key, 'projects/') && ! str_contains($key, '..');
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

### 変更後コード（要点・追記部のみ）
```php
public function register(): void
{
    // ... 既存 Stripe fake（fake_externals 依存）は不変 ...

    // storage fake: FakeStorageGate 成立時のみ concrete → fake へ rebind。
    // gate は predicate SSOT (route 登録と実行時 guard が共有)。
    if ($this->app->make(FakeStorageGate::class)->enabled()) {
        $this->app->bind(TakeObjectStorage::class, FakeTakeObjectStorage::class);
        $this->app->bind(RenderObjectStorage::class, FakeRenderObjectStorage::class);
    }
}

public function boot(): void
{
    // ... 既存 LLM fake（fake_llm 依存）は不変 ...

    // storage fake の signed route を gate 成立時のみ登録 (web CSRF group 外 = signed のみ)。
    if ($this->app->make(FakeStorageGate::class)->enabled()) {
        Route::middleware('signed')->group(function (): void {
            Route::put('/_fake-storage/object', PutFakeStorageObjectController::class)
                ->name('bughunt.storage.put');
            Route::get('/_fake-storage/object', GetFakeStorageObjectController::class)
                ->name('bughunt.storage.get');
        });
    }
}
```

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
- **Feature（signed route + bind を通す）**: テストヘルパ `withFakeStorage()`（`tests/` の trait or Pest helper）を `beforeEach` で呼ぶ。処理内容:
  1. `config(['testing.fake_storage' => true])`（testing 環境 + `runningUnitTests()===true` で `FakeStorageGate::enabled()` 成立）
  2. `Storage::fake('s3_fake')`（disk 実体を tmp へ隔離）
  3. `app()->bind(TakeObjectStorage::class, FakeTakeObjectStorage::class)` / `RenderObjectStorage::class` → fake（provider の register 相当を明示再実行）
  4. signed route を明示登録（provider の boot 相当。`Route::middleware('signed')->...` を再実行）。※ アプリ全体の再起動は不要（bind + route 明示登録で十分。route 二重登録は同名上書きで無害）
  - `s3` 実 disk は region 未設定のまま放置 = **もし実 S3 に触れたら即例外**（fake が実 S3 非依存であることの negative 担保）。

### 9-1. Unit
| テスト | 検証 |
|--------|------|
| `FakeStorageGateTest` | flag off→false / bughunt.local+flag→true / testing+runningUnitTests+flag→true / local+flag→false / production+flag→false |
| `FakeObjectStoreTest`（`Storage::fake('s3_fake')`） | storeStreamed→head 三値一致 / checksum 不一致→例外+object 未確定 / 容量超過→413 例外+一時ファイル残存なし / object あり sidecar 無し→head null / sidecar 不正 JSON/欠損/未知 schema→fail-loud / delete 冪等 |
| `FakeStorageContractTest`（**drift 検知・reflection**） | `FakeTakeObjectStorage` / `FakeRenderObjectStorage` が親の S3 依存 public method を全て override していること（reflection で public surface を列挙し未 override を検出）。`client()` override が実 S3 を構築しないこと |
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
| drift 不変（9-1 の `FakeStorageContractTest`）を Architecture 相当として位置づけ | fake の public surface 網羅を CI 強制 |

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
