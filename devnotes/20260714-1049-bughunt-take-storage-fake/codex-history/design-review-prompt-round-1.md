## アプリの使命（North Star）

**AI-CUE** は、現場に既にある**作業手順書(SOP)を起点に**、AI が撮るべきカットを設計した**動画シナリオ**を生成し、そのシナリオを**スマホ(PWA)でナビゲーション撮影**することで、専門知識ゼロの現場作業者でも**標準化されたマニュアル動画**を作れるようにする。「思考ゼロ・編集ゼロ」。v1: 字幕のみ / 撮影は PWA(同一オリジン・セッション認証) / 動画合成は自前 ffmpeg / 単一 Default Project。

## 禁止事項（AGENTS.md）
1. テストなしの実装完了報告（不変条件は Architecture/Feature テスト登録まで含めて実装済み）
2. PHPStan エラーの widen・baseline 化
3. dev DB への破壊操作
4. `response()->json()` 直書き（DTO/JsonResource/Inertia。仕様固定 endpoint のみ例外）
5. LLM 呼び出しの Prism 直呼び
6. prompt 文字列のコード直書き
7. 操作系 POST 応答での `redirect()->intended()`
8. 必須条件未充足でボタン disabled

### セキュリティ不変条件（関連）
tenant キー不信 / 子は親に属する（不整合は認可より前に 404）/ cross-org 不可。

【思考原則】まず仮説を立てろ。データに真摯に向き合え。先人の知恵を探せ。機能の名前に立ち返れ。仕組みが機能していない段階で値を弄るな。

【ツール使用制限】コマンド実行・ファイル書き込みは一切行わず、提供されたテキストの分析に集中すること。ファイル読み込みは許可。

---

あなたは経験豊富なWebアプリケーションアーキテクトです。Laravel + Svelte アプリの改善の詳細設計をレビューしてください。

【前提環境】PHP 8.4 + Laravel 12 + Svelte 5 + Inertia.js + TypeScript / PHPStan level 10 / Pest / DTO + JsonResource / Laratrust RBAC。

【レビュー観点】
1. コードの正確性（ロジックエラー、エッジケース、null安全性）
2. 既存コードとの整合性（命名規約、パターン、API）
3. PHPStan level 10 適合性（型安全性、generics、Assert使用）
4. テスト計画の網羅性（各施策に Pest テスト、RefreshDatabase グローバル適用）
5. DTO/JsonResource パターンの遵守
6. Inertia Props vs API Response の使い分け
7. 副作用・後退リスク
8. 波及変更の網羅性（TypeScript型定義、API Resource、テストが変更対象に含まれているか）
9. セキュリティ（認可チェック、入力バリデーション、OWASP、AGENTS.md のセキュリティ不変条件）
10. DESIGN.md 準拠（UI 変更を含む場合のみ）
11. Atomic Design 準拠（UI 変更を含む場合のみ）

【この設計特有の重点論点】
- サブクラス + container bind で fake を差す方式が、既存 concrete mock（`Mockery::mock(TakeObjectStorage::class)` + `app()->instance`）と `TakeObjectStorageTest`（実 SDK + 偽エンドポイント）を壊さないか。
- signed route で presigned PUT を emulate する方式のセキュリティ（signed middleware + 実行時 gate 再検証 + checksum 三者一致）。route が本番で生えないか（fail-secure）。
- `getContent(asResource: true)` で php://input をストリーム読み → 一時ファイル → atomic rename → sidecar completion marker、の正確性（Laravel 12 で本文未消費か、Range 応答 `response()->file()`、checksum 三者一致のロジック）。
- `FakeObjectStore` の PHPStan L10 適合（resource narrow、json_decode の型、config typed accessor）。
- render worker（別プロセス queue:listen）でも fake が bind されるか。
- drift 検知（契約テストで public surface 網羅）の妥当性。

【出力形式】
- 各施策ごとに判定: APPROVE / REQUEST_CHANGES
- 指摘は [Critical] [Warning] [Suggestion] で分類
- Critical/Warning には必ず修正案を添える
- 全体判定: APPROVED / CHANGES_REQUESTED
- 日本語で出力

---

## 詳細設計書

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
| 6 | signed route コントローラ（PUT 受け口 / GET serve） | `app/Http/Controllers/Testing/PutFakeStorageObjectController.php` / `GetFakeStorageObjectController.php`（新規） | High |
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
- Unit: flag off → false / bughunt.local + flag → true / testing + runningUnitTests + flag → true / local + flag → false / production + flag → false

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
     *
     * @param  resource  $input
     * @throws FakeStorageChecksumMismatch  実 body の checksum が期待値と不一致
     * @throws FakeStorageOverCapacity      絶対容量上限超過
     */
    public function storeStreamed(string $key, $input, string $contentType, string $expectedChecksum): void
    {
        $maxBytes = config()->integer('capture.max_take_bytes');
        $root = storage_path('app/'.self::DISK); // = disk root。一時ファイルを同一 filesystem 上に作る
        $this->ensureDir($root.'/'.dirname($key));
        $tmp = $root.'/'.$key.'.uploading-'.bin2hex(random_bytes(8));

        $ctx = hash_init('sha256');
        $total = 0;
        $out = fopen($tmp, 'wb');
        Assert::resource($out, 'fake storage: 一時ファイルを開けません');
        try {
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
                fwrite($out, $chunk);
            }
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

        // atomic move (同一 filesystem)。失敗時は一時ファイルを掃除する。
        if (! @rename($tmp, $root.'/'.$key)) {
            @unlink($tmp);
            throw new RuntimeException('fake storage: object の確定に失敗しました');
        }

        // sidecar を最後に書く = completion marker
        $this->disk()->put($this->sidecarKey($key), $this->encodeMeta(new FakeObjectMeta($contentType, $actual)));
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

    public function contentTypeOf(string $key): string
    {
        $sidecar = $this->sidecarKey($key);
        Assert::true($this->disk()->exists($sidecar), 'fake storage: sidecar が存在しません');

        return $this->decodeMeta($this->disk()->get($sidecar))->contentType;
    }

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
            Storage::disk(FakeObjectStore::DISK)->writeStream($key, $stream);
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

> 注: `downloadToLocal`/`upload` は親とほぼ同一で disk 名のみ差し替え。実装時は親を
> `protected function disk(): Filesystem` に薄くリファクタし fake は disk 名だけ override する案も可
> （後方互換の並走を残さないため親の `Storage::disk('s3')` 直書きを 1 メソッドに集約）。
> ただし親の挙動不変を厳守し、既存 `RenderObjectStorageTest`/`RenderRetentionTest` を壊さないこと。

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
        abort_unless($store->exists($key), 404);

        $headers = ['Content-Type' => $store->contentTypeOf($key)];
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

---

## 施策 8: `ProductionEnvGuard` 不変確認

`ProductionEnvGuard::violations()` は既に `config('testing.fake_storage') === true` を production で違反として fail-fast 拒否する（L99-102、実装済み・**変更なし**）。本設計はこの不変を前提とし、施策 9 で「production + fake_storage=true → violation」を回帰テストで固定する（既存テストがあれば追加不要、無ければ追加）。

---

## 施策 9: テスト計画（全体）

> 禁止事項 1（テストなし完了報告）遵守。RefreshDatabase グローバル + `--parallel`。個別 `DatabaseTransactions` 不使用。Factory 生成。

### 9-1. Unit
| テスト | 検証 |
|--------|------|
| `FakeStorageGateTest` | flag off→false / bughunt.local+flag→true / testing+runningUnitTests+flag→true / local+flag→false / production+flag→false |
| `FakeObjectStoreTest`（`Storage::fake('s3_fake')`） | storeStreamed→head 三値一致 / checksum 不一致→例外+object 未確定 / 容量超過→413 例外+一時ファイル残存なし / object あり sidecar 無し→head null / sidecar 不正 JSON/欠損/未知 schema→fail-loud / delete 冪等 |
| `FakeStorageContractTest`（**drift 検知**） | `FakeTakeObjectStorage` / `FakeRenderObjectStorage` が親の S3 依存 public method を全て override していること（reflection で public surface を列挙し未 override を検出）。`client()` override が実 S3 を構築しないこと |

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


---

## 関連する現行コード（抜粋）

### app/Services/Capture/TakeObjectStorage.php（実装・不変）
```php
<?php

declare(strict_types=1);

namespace App\Services\Capture;

use App\DataTransferObjects\Capture\ObjectMetadataData;
use App\DataTransferObjects\Capture\PresignedUploadData;
use Aws\S3\Exception\S3Exception;
use Aws\S3\S3Client;
use Carbon\CarbonImmutable;
use Illuminate\Filesystem\AwsS3V3Adapter;
use Illuminate\Support\Facades\Storage;
use Webmozart\Assert\Assert;

/**
 * テイク動画 S3 オブジェクト操作の集約点 (概念設計 D11)。presign / HeadObject /
 * 署名 GET / 削除はすべて本クラス経由 (Feature テストでは container mock。
 * 配線検証は TakeObjectStorageTest が実 SDK オブジェクト + 偽エンドポイントで固定)。
 *
 * presigned PUT は SDK の createPresignedRequest を直接使い **ChecksumSHA256** を署名対象に
 * 含める (temporaryUploadUrl は署名できないため)。PHP SDK は content-type/length を presign
 * 署名から除外するが、checksum が本文を一意に固定するため内容・サイズは改変不能で、
 * content-type の照合は登録時の HeadObject 三点照合が担う。S3 は本文がチェックサムと
 * 一致しない PUT を拒否するため、この URL で置ける内容は申告ハッシュの 1 通りに固定される
 * = 登録後の再 PUT 差し替え防止 (概念設計 D2b)。
 */
class TakeObjectStorage
{
    public function presignUpload(string $path, string $contentType, int $sizeBytes, string $checksumSha256, CarbonImmutable $expiresAt): PresignedUploadData
    {
        $client = $this->client();
        $command = $client->getCommand('PutObject', [
            'Bucket' => $this->bucket(),
            'Key' => $path,
            'ContentType' => $contentType,
            'ContentLength' => $sizeBytes,
            'ChecksumSHA256' => $checksumSha256, // x-amz-checksum-sha256 として署名される
        ]);
        // 期限は DateTimeInterface で渡す (int timestamp は SDK 実装依存の誤解を招くため。
        // 期限・署名クエリは TakeObjectStorageTest が固定検証する)
        $request = $client->createPresignedRequest($command, $expiresAt);

        return new PresignedUploadData(
            url: (string) $request->getUri(),
            headers: [
                'Content-Type' => $contentType,
                'x-amz-checksum-sha256' => $checksumSha256,
            ],
            expiresAt: $expiresAt,
        );
    }

    /**
     * オブジェクトが存在しなければ null (PUT 未完了)。ChecksumMode=ENABLED で
     * ChecksumSHA256 も取得する (欠落する互換実装では null = 照合スキップの二重防御位置づけ)。
     */
    public function headObject(string $path): ?ObjectMetadataData
    {
        try {
            $result = $this->client()->headObject([
                'Bucket' => $this->bucket(),
                'Key' => $path,
                'ChecksumMode' => 'ENABLED',
            ]);
        } catch (S3Exception $exception) {
            if ($exception->getStatusCode() === 404) {
                return null;
            }

            throw $exception;
        }

        $contentLength = $result['ContentLength'];
        Assert::numeric($contentLength, 'HeadObject の ContentLength を取得できません');
        $contentType = $result['ContentType'] ?? null;
        $checksum = $result['ChecksumSHA256'] ?? null;

        return new ObjectMetadataData(
            contentLength: (int) $contentLength,
            contentType: is_string($contentType) ? $contentType : null,
            checksumSha256: is_string($checksum) ? $checksum : null,
        );
    }

    /** 採用テイク再生用の署名 GET URL (TTL は config capture.playback_url_ttl_minutes) */
    public function temporaryPlaybackUrl(string $path): string
    {
        return Storage::disk('s3')->temporaryUrl(
            $path,
            now()->addMinutes(config()->integer('capture.playback_url_ttl_minutes')),
        );
    }

    /** オブジェクト削除 (存在しないキーは no-op = 冪等) */
    public function delete(string $path): void
    {
        Storage::disk('s3')->delete($path);
    }

    /** stale 掃除用の存在確認 */
    public function exists(string $path): bool
    {
        return Storage::disk('s3')->exists($path);
    }

    /**
     * s3 disk の S3Client (テストでは本メソッドを override して MockHandler client を注入する)。
     */
    protected function client(): S3Client
    {
        $disk = Storage::disk('s3');
        Assert::isInstanceOf($disk, AwsS3V3Adapter::class);

        return $disk->getClient();
    }

    protected function bucket(): string
    {
        return config()->string('filesystems.disks.s3.bucket');
    }
}

```

### app/Services/Render/RenderObjectStorage.php（実装・不変。contentDisposition 再利用）
```php
<?php

declare(strict_types=1);

namespace App\Services\Render;

use App\Models\VideoManual;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Webmozart\Assert\Assert;

/**
 * レンダ出力 S3 オブジェクト操作の集約点 (TakeObjectStorage と同じ Storage::disk('s3') 経由)。
 * ダウンロード/アップロード/署名 URL/削除はすべて本クラス経由 (Feature テストは Storage::fake('s3'))。
 */
class RenderObjectStorage
{
    /** S3 素材をローカル一時ファイルへ取得する (readStream → ローカル書き込み) */
    public function downloadToLocal(string $key, string $localPath): void
    {
        $stream = Storage::disk('s3')->readStream($key);
        if ($stream === null) {
            throw new RuntimeException("S3 オブジェクトを読めません: {$key}");
        }

        $local = fopen($localPath, 'wb');
        if ($local === false) {
            throw new RuntimeException("ローカルファイルを開けません: {$localPath}");
        }

        try {
            if (stream_copy_to_stream($stream, $local) === false) {
                throw new RuntimeException("S3 オブジェクトのコピーに失敗しました: {$key}");
            }
        } finally {
            fclose($local);
            if (is_resource($stream)) {
                fclose($stream);
            }
        }
    }

    /** ローカル最終 mp4 を S3 出力キーへアップロードする (writeStream) */
    public function upload(string $localPath, string $key): void
    {
        $stream = fopen($localPath, 'rb');
        if ($stream === false) {
            throw new RuntimeException("ローカルファイルを開けません: {$localPath}");
        }

        try {
            Storage::disk('s3')->writeStream($key, $stream);
        } finally {
            if (is_resource($stream)) {
                fclose($stream);
            }
        }
    }

    /** preview 再生用の署名 GET URL (TTL は config manual.render_playback_url_ttl_minutes) */
    public function temporaryPlaybackUrl(string $key): string
    {
        return Storage::disk('s3')->temporaryUrl(
            $key,
            now()->addMinutes(config()->integer('manual.render_playback_url_ttl_minutes')),
        );
    }

    /**
     * DL 用署名 URL (attachment disposition)。filename 契約 (詳細レビュー Round 1 で明文化):
     * - filename は CR/LF・制御文字を除去し、Content-Disposition は
     *   RFC 5987 (filename*=UTF-8''...) + ASCII fallback (filename="...") の両建てで署名に含める
     * - ヘッダ注入 (改行) 不能であることを Unit テストで固定
     */
    public function temporaryDownloadUrl(string $key, string $filename): string
    {
        return Storage::disk('s3')->temporaryUrl(
            $key,
            now()->addMinutes(config()->integer('manual.render_playback_url_ttl_minutes')),
            ['ResponseContentDisposition' => $this->contentDisposition($filename)],
        );
    }

    /** オブジェクト削除 (存在しないキーは no-op = 冪等) */
    public function delete(string $key): void
    {
        Storage::disk('s3')->delete($key);
    }

    /** manual 配下のレンダ出力 prefix (DeleteRenderOutputsJob の過大削除防止に使う) */
    public function keyPrefixFor(VideoManual $manual): string
    {
        return "projects/{$manual->project_id}/manuals/{$manual->id}/";
    }

    /**
     * Content-Disposition 値の構築 (attachment 固定・ヘッダ注入不能)。
     * - 制御文字 (CR/LF 含む)・DEL を除去
     * - ASCII fallback: 非 ASCII を '_' 化し、quoted-string を壊す `"` `\` も '_' 化
     * - RFC 5987: UTF-8 percent-encoding (rawurlencode)
     */
    public function contentDisposition(string $filename): string
    {
        $sanitized = preg_replace('/[\x00-\x1F\x7F]/u', '', $filename);
        Assert::string($sanitized, 'filename の制御文字除去に失敗しました');
        if ($sanitized === '') {
            $sanitized = 'video.mp4';
        }

        $asciiFallback = preg_replace('/[^\x20-\x7E]/u', '_', $sanitized);
        Assert::string($asciiFallback, 'filename の ASCII fallback 生成に失敗しました');
        $asciiFallback = str_replace(['"', '\\'], '_', $asciiFallback);

        $rfc5987 = rawurlencode($sanitized);

        return "attachment; filename=\"{$asciiFallback}\"; filename*=UTF-8''{$rfc5987}";
    }
}

```

### app/Services/Capture/TakeUploadService.php（presignUpload 消費側・不変）
```php
<?php

declare(strict_types=1);

namespace App\Services\Capture;

use App\DataTransferObjects\Capture\TakeUploadInput;
use App\DataTransferObjects\Capture\TakeUploadTicketData;
use App\DataTransferObjects\Capture\UploadTicketClaims;
use App\Enums\Manual\VideoManualStatus;
use App\Enums\QuotaKey;
use App\Models\Cut;
use App\Models\Organization;
use App\Models\Project;
use App\Models\TakeUploadReservation;
use App\Models\VideoManual;
use App\Services\Billing\QuotaService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Webmozart\Assert\Assert;

/**
 * presigned PUT URL + 署名チケット発行 (doc/10 §10.3 / §10.8-4,-7 / 概念設計 D2,D3)。
 * 直列化点: Organization 行ロック (check→reserve の TOCTOU 防止)。
 */
class TakeUploadService
{
    public function __construct(
        private readonly QuotaService $quota,
        private readonly StorageUsageService $usage,
        private readonly TakeObjectStorage $storage,
        private readonly UploadTicketCodec $codec,
    ) {}

    public function issue(Organization $organization, Project $project, VideoManual $manual, Cut $cut, TakeUploadInput $input): TakeUploadTicketData
    {
        $expiresAt = CarbonImmutable::now()->addMinutes(config()->integer('capture.upload_ticket_ttl_minutes'));

        $reservation = DB::transaction(function () use ($organization, $project, $manual, $cut, $input, $expiresAt): TakeUploadReservation {
            /** @var Organization $lockedOrg */
            $lockedOrg = Organization::query()->whereKey($organization->id)->lockForUpdate()->firstOrFail();
            // 子は親に属する: ロック済み経路で再解決 (cross は 404)。manual 状態 guard も同時に行う
            /** @var VideoManual $lockedManual */
            $lockedManual = $project->manuals()->whereKey($manual->id)->firstOrFail();
            if (! in_array($lockedManual->status, [VideoManualStatus::Ready, VideoManualStatus::Published], true)) {
                throw ValidationException::withMessages([
                    'manual' => ['このマニュアルは現在撮影できません（解析中・書き出し中）。'],
                ]);
            }
            /** @var Cut $lockedCut */
            $lockedCut = $lockedManual->cuts()->whereKey($cut->id)->firstOrFail();

            // Quota: bytes_used + bytes_pending + size が上限を超えるなら 422 (QuotaExceededException)。
            // 加算合成は occupiedBytes() (overflow 安全) に委譲し、呼び出し側で生加算しない。
            // occupiedBytes() は pending→used の読み取り順が並行制御上の不変条件
            // (finalize は org ロックを取らないため。StorageUsageService の docblock 参照)
            $this->quota->checkAddition(
                $lockedOrg,
                QuotaKey::MaxStorageBytes,
                current: $this->usage->occupiedBytes($lockedOrg),
                addition: $input->sizeBytes,
            );

            // S3 キーはサーバ生成 (SourceDocumentService と同じ規約)
            $path = sprintf(
                'projects/%d/manuals/%d/cuts/%d/takes/%s.%s',
                $lockedManual->project_id,
                $lockedManual->id,
                $lockedCut->id,
                (string) Str::ulid(),
                self::extensionFor($input->contentType),
            );

            $reservation = $lockedCut->uploadReservations()->make([
                'client_take_id' => $input->clientTakeId,
                'video_path' => $path,
                'size_bytes' => $input->sizeBytes,
                'content_type' => $input->contentType,
                'checksum_sha256' => $input->checksum->base64,
                'expires_at' => $expiresAt,
            ]);
            $reservation->forceFill(['organization_id' => $lockedOrg->id])->save();

            return $reservation;
        });

        // presign は外部 I/O のため tx 外 (ロック保持時間を最小化)。checksum を署名条件に含める (D2b)
        $presigned = $this->storage->presignUpload(
            $reservation->video_path,
            $input->contentType,
            $input->sizeBytes,
            $input->checksum->base64,
            $expiresAt,
        );
        $ticket = $this->codec->seal(UploadTicketClaims::fromReservation($reservation));

        return new TakeUploadTicketData($presigned, $ticket, $reservation->client_take_id);
    }

    /** 許可 Content-Type → S3 キー拡張子 (config capture.allowed_video_content_types と対で保守) */
    private static function extensionFor(string $contentType): string
    {
        $extension = match ($contentType) {
            'video/mp4' => 'mp4',
            'video/webm' => 'webm',
            'video/quicktime' => 'mov',
            default => null,
        };
        Assert::notNull($extension, "未許可の Content-Type です: {$contentType}");

        return $extension;
    }
}

```

### app/Services/Capture/TakeRegistrationService.php（headObject 三点照合 消費側・不変。抜粋）
```php
        // 5. HeadObject 三点照合 (D2b/D4-3)
        $head = $this->storage->headObject($reservation->video_path);
        if ($head === null) {
            // PUT 未完了: 期限内なら pending へ戻し再送可能に (Quota 占有は継続)。
            // claim 後に期限超過した予約は released へ倒して 422 (曖昧な再試行を残さない)
            $revertTo = $reservation->expires_at->isFuture()
                ? TakeUploadReservationStatus::Pending
                : TakeUploadReservationStatus::Released;
            $reservation->forceFill(['status' => $revertTo])->save();

            throw ValidationException::withMessages(['ticket' => ['アップロードが完了していません。']]);
        }
        $checksumMismatch = $head->checksumSha256 !== null && $head->checksumSha256 !== $reservation->checksum_sha256;
        if ($head->contentLength !== $reservation->size_bytes
            || ($head->contentType !== null && $head->contentType !== $reservation->content_type)
            || $checksumMismatch) {
            // §10.8-7: 申告と不一致のオブジェクトは削除・拒否 (released 化 = Quota 解放)
            $reservation->forceFill(['status' => TakeUploadReservationStatus::Released])->save();
            $this->storage->delete($reservation->video_path);

            throw ValidationException::withMessages(['ticket' => ['アップロード内容が申告と一致しません。']]);
        }

```

### app/Providers/FakeExternalsServiceProvider.php（配線追加対象）
```php
<?php

declare(strict_types=1);

namespace App\Providers;

use App\Services\AI\Testing\CannedPromptFakeRegistrar;
use App\Services\Billing\Fakes\FakeSubscriptionCheckoutGateway;
use App\Services\Billing\Fakes\FakeTicketCheckoutGateway;
use App\Services\Billing\SubscriptionCheckoutGateway;
use App\Services\Billing\TicketCheckoutGateway;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\ServiceProvider;

/**
 * 外部サービス fake の配線 (系統別に capability flag を分離)。
 *
 * bootstrap/providers.php で AppServiceProvider より後に登録する (後勝ち rebind)。
 * fail-secure 二軸:
 * 1. flag === true (既定 false = 完全 no-op)
 * 2. 環境 allowlist。denylist (非 production) ではなく allowlist で倒す = staging 等の
 *    未知環境で flag が誤設定されても fake しない (warning ログで検出可能にする)。
 *    production は加えて ProductionEnvGuard が flag=true を deploy 時 fail-fast で拒否する。
 *
 * fake 対象は 2 系統で capability flag も allowlist も異なる:
 * - Stripe 課金 gateway: config('testing.fake_externals') が capability flag。
 *   container bind (per-test 隔離が効くため testing 可)。register() で配線。
 * - LLM (Prism): config('testing.fake_llm') が capability flag (fake_externals から分離)。
 *   Prompt::$fake は static (プロセスグローバル) のため testing/local を除外し bughunt.local のみ配線。
 *   bughunt 既定は real-llm (fake_llm off) で install しない。--fake-llm 時のみ install する。
 *   LLM fake 許可環境は bughunt.local のみ (定数 LLM_FAKE_ENVIRONMENTS が正本)。
 */
class FakeExternalsServiceProvider extends ServiceProvider
{
    /** Stripe 課金 gateway fake を許可する環境 allowlist (container bind。per-test 隔離が効くため testing 可) */
    private const array PAYMENT_FAKE_ENVIRONMENTS = ['local', 'testing', 'bughunt.local'];

    /** LLM (Prism) fake の install を許可する環境 allowlist (Prompt::$fake は static。testing/local を除外) */
    private const array LLM_FAKE_ENVIRONMENTS = ['bughunt.local'];

    public function register(): void
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

        // Stripe 到達点を fake へ rebind (課金状態の正本は BughuntBillingSeeder)
        $this->app->bind(TicketCheckoutGateway::class, FakeTicketCheckoutGateway::class);
        $this->app->bind(SubscriptionCheckoutGateway::class, FakeSubscriptionCheckoutGateway::class);
    }

    public function boot(): void
    {
        // LLM fake は fake_llm (既定 false = real LLM) で判定する。bughunt 既定は real-llm で、
        // --fake-llm 指定時のみ TESTING_FAKE_LLM=true が注入され install される。
        // Stripe fake (register) は従来どおり fake_externals 依存で不変。
        if (config('testing.fake_llm') !== true) {
            return;
        }

        // LLM fake は Prompt::$fake (プロセスグローバル static) を書き換えるため、
        // per-test で static を占有する testing、実 API 検証を潰す local は allowlist から除外する。
        // LLM fake 許可環境は bughunt.local のみ (定数 LLM_FAKE_ENVIRONMENTS が正本)。
        // (Stripe と違い warning は出さない: testing/local の除外は誤設定ではなく設計上の除外)
        if (! in_array($this->app->environment(), self::LLM_FAKE_ENVIRONMENTS, true)) {
            return;
        }

        // Browser lane (tests/Pest.php) と同一の install API を使う (Prompt::installFake の封じ込め)。
        $this->app->make(CannedPromptFakeRegistrar::class)->install();
    }
}

```

### app/Support/ProductionEnvGuard.php（fake_storage=true を production で拒否・不変。抜粋 L98-102）
```php
// storage fake は production で実ストレージを潰し得るため禁止。
if (config('testing.fake_storage') === true) {
    $errors[] = 'TESTING_FAKE_STORAGE must be false in production '
        .'(storage fake must never be enabled in production).';
}
```

### DTO（契約・不変）
```php
final readonly class PresignedUploadData {
    public function __construct(public string $url, public array $headers, public CarbonImmutable $expiresAt) {}
}
final readonly class ObjectMetadataData {
    public function __construct(public int $contentLength, public ?string $contentType, public ?string $checksumSha256) {}
}
```

### resources/js/lib/capture/upload-queue.ts（フロント PUT・不変）
```ts
const putResponse = await fetch(ticket.upload_url, {
    method: "PUT",
    headers: ticket.headers, // { "Content-Type", "x-amz-checksum-sha256" }
    body: item.blob,
});
if (!putResponse.ok) { throw new Error("動画のアップロードに失敗しました。"); }
```

### 既存テストの mock パターン（不変であるべき）
```php
$mock = Mockery::mock(TakeObjectStorage::class);
$mock->shouldReceive('presignUpload')->andReturn(...);
app()->instance(TakeObjectStorage::class, $mock);
```
