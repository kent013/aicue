<?php

declare(strict_types=1);

namespace App\Services\Storage\Fakes;

use App\DataTransferObjects\Capture\ObjectMetadataData;
use HashContext;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Support\Facades\Storage;
use JsonException;
use RuntimeException;
use Webmozart\Assert\Assert;

/**
 * s3_fake disk 上のオブジェクト操作の集約点 (fake storage 基盤)。
 *
 * - 保存はストリーム: php://input を chunk 読みしながら sha256 を計算し一時ファイルへ書く。
 *   絶対容量上限 (config capture.max_take_bytes) を超えたら中断・一時ファイル削除・OverCapacity を投げる。
 * - checksum 三者一致は呼び出し側 (controller) が「署名パラメータ == ヘッダ」を先に検証し、
 *   本メソッドが「== 実 body」を担保する (期待 checksum を受け取り実 body と照合)。
 * - sidecar meta は object 配置の「後」に書く = completion marker (crash 途中は object あり sidecar 無し = 未完了)。
 *
 * object + sidecar 全体の整合は key 単位の排他ロック (flock LOCK_EX/LOCK_SH) と completion marker で担保する。
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
     *    root ハードコードだとテストと実装で別領域を書く)。
     *
     * @param  resource  $input
     *
     * @throws FakeStorageChecksumMismatch 実 body の checksum が期待値と不一致
     * @throws FakeStorageOverCapacity 絶対容量上限超過
     */
    public function storeStreamed(string $key, mixed $input, string $contentType, string $expectedChecksum): void
    {
        $target = $this->disk()->path($key); // Storage::fake でも実 local でも正しい実体パス
        $this->ensureDir(dirname($target));
        $tmp = $target.'.uploading-'.bin2hex(random_bytes(8)); // 同一ディレクトリ = 同一 filesystem

        try {
            // 攻撃者由来の php://input = 絶対容量上限 (take 上限) を適用
            $actual = $this->streamToTmp($input, $tmp, maxBytes: config()->integer('capture.max_take_bytes'));

            if (! hash_equals($expectedChecksum, $actual)) {
                throw new FakeStorageChecksumMismatch('fake storage: アップロード内容が checksum と一致しません');
            }

            $this->promote($key, $tmp, $target, new FakeObjectMeta($contentType, $actual));
        } finally {
            // withKeyLock 取得失敗等で promote が例外時にも未確定 tmp を必ず掃除する
            // (rename 成功後は tmp 不在で no-op)。
            if (is_file($tmp)) {
                @unlink($tmp);
            }
        }
    }

    /**
     * checksum 照合なしのストリーム保存 (render 出力・take footage コピー等の**サーバ生成の内部生成物**)。
     * sidecar (content_type) を必ず生成する = GET 配信の contract を満たす。
     * 入力は攻撃者由来の php://input ではなく信頼できるローカルファイルのため、絶対容量上限は課さない。
     *
     * @param  resource  $input
     */
    public function putStreamWithMeta(string $key, mixed $input, string $contentType): void
    {
        $target = $this->disk()->path($key);
        $this->ensureDir(dirname($target));
        $tmp = $target.'.uploading-'.bin2hex(random_bytes(8));

        try {
            $actual = $this->streamToTmp($input, $tmp, maxBytes: null); // 信頼できる内部入力 = cap なし
            $this->promote($key, $tmp, $target, new FakeObjectMeta($contentType, $actual));
        } finally {
            if (is_file($tmp)) {
                @unlink($tmp);
            }
        }
    }

    /**
     * $input を $tmp へ流しつつ sha256 を計算し、base64 sha256 を返す。
     *
     * @param  resource  $input
     */
    private function streamToTmp(mixed $input, string $tmp, ?int $maxBytes): string
    {
        Assert::resource($input, null, 'fake storage: 入力ストリームが resource ではありません');
        $ctx = hash_init('sha256');
        $out = fopen($tmp, 'wb');
        Assert::resource($out, null, 'fake storage: 一時ファイルを開けません');
        try {
            $this->streamInto($input, $out, $ctx, $maxBytes);
        } finally {
            fclose($out);
        }

        return base64_encode(hash_final($ctx, true));
    }

    /**
     * $input を $out へ流しつつ sha256 を計算。$maxBytes 非 null なら超過で中断、
     * fwrite の部分書き込みは完了まで再試行。
     *
     * @param  resource  $input
     * @param  resource  $out
     */
    private function streamInto(mixed $input, mixed $out, HashContext $ctx, ?int $maxBytes): void
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
            $this->writeAll($out, $chunk); // 部分書き込み対策
        }
    }

    /**
     * fwrite の部分書き込みを完了まで再試行。false は例外化。
     *
     * @param  resource  $out
     */
    private function writeAll(mixed $out, string $data): void
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
     * critical section を key ロックで直列化する理由: 同一 key への並行 PUT で
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
        $this->withKeyLock($key, exclusive: true, critical: function () use ($key, $tmp, $target, $meta): null {
            $this->disk()->delete($this->sidecarKey($key)); // 冪等 (不在は no-op)
            if (! @rename($tmp, $target)) {
                throw new RuntimeException('fake storage: object の確定に失敗しました');
            }
            $this->disk()->put($this->sidecarKey($key), $this->encodeMeta($meta));

            return null;
        });
    }

    /**
     * key 単位のロック下で $critical を実行する。ロックファイルは object とは別 namespace (`.locks/`) に置く
     * (object listing/GET を汚さない)。unlock/close は finally で保証。
     * $exclusive: true = LOCK_EX (writer: promote/delete) / false = LOCK_SH (reader: head)。
     * flock(..., LOCK_UN) の戻り値は fail-loud 対象外 (無視で可)。
     *
     * @template T
     *
     * @param  callable():T  $critical
     * @return T
     */
    private function withKeyLock(string $key, bool $exclusive, callable $critical): mixed
    {
        $operation = $exclusive ? LOCK_EX : LOCK_SH;
        $lockPath = $this->disk()->path('.locks/'.sha1($key).'.lock');
        $this->ensureDir(dirname($lockPath));
        $handle = fopen($lockPath, 'c');
        Assert::resource($handle, null, 'fake storage: ロックファイルを開けません');
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
     * 一括で行う (promote の LOCK_EX と排他され、reader が異世代の object/meta を組み合わせない・
     * sidecar exists→get 間の削除で例外化しない)。状態別に固定:
     * - object 不在 → null (PUT 未着手)
     * - object あり sidecar 不在 → null (PUT 未完了 = crash 途中)
     * - sidecar 破損 (不正 JSON/欠損キー/未知 schema) → fail-loud (RuntimeException)
     */
    public function head(string $key): ?ObjectMetadataData
    {
        return $this->withKeyLock($key, exclusive: false, critical: function () use ($key): ?ObjectMetadataData {
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

    /** object + sidecar 削除。promote と競合しないよう同一 key の LOCK_EX 下で行う。冪等。 */
    public function delete(string $key): void
    {
        $this->withKeyLock($key, exclusive: true, critical: function () use ($key): null {
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

    /**
     * sidecar 破損 (不正 JSON / 非 object / 欠損キー / 未知 schema / checksum 形式不正) は
     * 一様に RuntimeException で fail-loud にする (GET コントローラが 500 化して検知する)。
     */
    private function decodeMeta(?string $raw): FakeObjectMeta
    {
        if (! is_string($raw)) {
            throw new RuntimeException('fake storage: sidecar を読めません');
        }
        try {
            $decoded = json_decode($raw, true, flags: JSON_THROW_ON_ERROR); // mixed
        } catch (JsonException $e) {
            throw new RuntimeException('fake storage: sidecar が不正な JSON です', previous: $e);
        }
        if (! is_array($decoded)) {
            throw new RuntimeException('fake storage: sidecar が object ではありません');
        }
        /** @var array<array-key, mixed> $data */
        $data = $decoded;
        $version = $data['schema_version'] ?? null;
        if ($version !== FakeObjectMeta::SCHEMA_VERSION) {
            throw new RuntimeException('fake storage: sidecar の schema_version が未知です');
        }
        $contentType = $data['content_type'] ?? null;
        $checksum = $data['checksum_sha256'] ?? null;
        if (! is_string($contentType)) {
            throw new RuntimeException('fake storage: sidecar content_type 欠損');
        }
        if (! is_string($checksum)) {
            throw new RuntimeException('fake storage: sidecar checksum 欠損');
        }
        // base64(sha256) = 32 バイト → base64 44 文字 (末尾 '=')。軽い形式検証で異常を早期検知
        if (preg_match('/^[A-Za-z0-9+\/]{43}=$/', $checksum) !== 1) {
            throw new RuntimeException('fake storage: sidecar checksum 形式不正');
        }

        return new FakeObjectMeta($contentType, $checksum);
    }

    private function ensureDir(string $dir): void
    {
        if (! is_dir($dir) && ! @mkdir($dir, 0775, true) && ! is_dir($dir)) {
            throw new RuntimeException("fake storage: ディレクトリ作成に失敗しました: {$dir}");
        }
    }
}
