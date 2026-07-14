<?php

declare(strict_types=1);

use App\Services\Storage\Fakes\FakeObjectStore;
use Illuminate\Support\Facades\Storage;

/*
 * 同一 key の writer/reader 直列化契約 (Round 3/4 Critical)。
 *
 * cross-process の blocking 時間を計る time-dependent 判定は flaky 化するため採らない。
 * 代わりに (1) store が object とは別 namespace の `.locks/` ロックファイルを実際に使うこと、
 * (2) そのロックファイル上で LOCK_EX が writer/reader (LOCK_SH) を排他すること
 * = promote/head/delete が異世代の object/meta を組み合わせない土台を、
 * 単一プロセス内の LOCK_NB プローブで決定的に固定する
 * (Linux の flock は open file description 単位 = 同一プロセスでも別 fopen なら排他する)。
 */

beforeEach(function (): void {
    Storage::fake(FakeObjectStore::DISK);
});

/** @return resource */
function streamFrom(string $body)
{
    $stream = fopen('php://temp', 'r+b');
    fwrite($stream, $body);
    rewind($stream);

    return $stream;
}

function lockPathFor(string $key): string
{
    return Storage::disk(FakeObjectStore::DISK)->path('.locks/'.sha1($key).'.lock');
}

test('store は object とは別 namespace の .locks/ ロックファイルを使う', function (): void {
    $store = new FakeObjectStore;
    $key = 'projects/1/manuals/2/cuts/3/takes/01LOCK.mp4';
    $body = 'bytes';

    $store->storeStreamed($key, streamFrom($body), 'video/mp4', base64_encode(hash('sha256', $body, true)));

    // ロックファイルが .locks/ に生成され、object listing (allFiles) を汚さない
    expect(file_exists(lockPathFor($key)))->toBeTrue();
    $objectFiles = collect(Storage::disk(FakeObjectStore::DISK)->allFiles())
        ->reject(fn (string $p): bool => str_starts_with($p, '.locks/'));
    expect($objectFiles)->toContain($key);
});

test('key ロック上の LOCK_EX は writer(LOCK_EX) と reader(LOCK_SH) を排他し、解放で reader が進む', function (): void {
    $store = new FakeObjectStore;
    $key = 'projects/1/manuals/2/cuts/3/takes/01EXCL.mp4';
    $body = 'bytes';
    // ロックファイルを生成させる (store が使う実パスを得る)
    $store->storeStreamed($key, streamFrom($body), 'video/mp4', base64_encode(hash('sha256', $body, true)));

    $path = lockPathFor($key);

    // writer 相当: LOCK_EX を保持
    $writer = fopen($path, 'c');
    expect(flock($writer, LOCK_EX))->toBeTrue();

    // 別 open file description からの probe: writer 保持中は EX も SH も取得できない
    $probe = fopen($path, 'c');
    expect(flock($probe, LOCK_EX | LOCK_NB))->toBeFalse(); // 別 writer (promote/delete) は待つ
    expect(flock($probe, LOCK_SH | LOCK_NB))->toBeFalse(); // reader (head) も待つ

    // writer 解放後は reader が即座に進める
    expect(flock($writer, LOCK_UN))->toBeTrue();
    expect(flock($probe, LOCK_SH | LOCK_NB))->toBeTrue();

    flock($probe, LOCK_UN);
    fclose($probe);
    fclose($writer);
});

test('reader の共有ロック中は別 reader は入れるが writer は待つ', function (): void {
    $store = new FakeObjectStore;
    $key = 'projects/1/manuals/2/cuts/3/takes/01SHARED.mp4';
    $body = 'bytes';
    $store->storeStreamed($key, streamFrom($body), 'video/mp4', base64_encode(hash('sha256', $body, true)));
    $path = lockPathFor($key);

    $reader = fopen($path, 'c');
    expect(flock($reader, LOCK_SH))->toBeTrue();

    $probe = fopen($path, 'c');
    expect(flock($probe, LOCK_SH | LOCK_NB))->toBeTrue();  // 複数 reader は同時可
    flock($probe, LOCK_UN);
    expect(flock($probe, LOCK_EX | LOCK_NB))->toBeFalse(); // writer (promote/delete) は待つ

    flock($reader, LOCK_UN);
    fclose($reader);
    fclose($probe);
});
