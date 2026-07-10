<?php

declare(strict_types=1);

/*
 * scripts/ci/make-shard-phpunit.php — GitHub Actions の matrix sharding 用に、
 * phpunit.xml をベースに「このシャードが担当するテストファイルだけ」を
 * <testsuites> に持つ phpunit 設定を生成する。
 *
 * 使い方:
 *   php scripts/ci/make-shard-phpunit.php <shardIndex> <shardTotal> <outPath>
 *     shardIndex : 1..shardTotal
 *     shardTotal : 総シャード数
 *     outPath    : 出力先 (プロジェクトルートに置くこと。bootstrap="tests/bootstrap.php"
 *                  が設定ファイルからの相対で解決されるため。既定の生成物
 *                  phpunit.ci-shard.xml は .gitignore 済み)
 *
 * 方針:
 *   - phpunit.xml をそのまま DOMDocument で読み、<php> / <source> / bootstrap 属性等は
 *     一切いじらず継承する (= <server force> による DB force / bootstrap の
 *     `<slug>_test_<hash>` 後勝ち上書き + fail-closed ガードを drift させない)。
 *   - 既存 <testsuites> の <directory> 配下の *Test.php を収集し、ファイル名 sort 後に
 *     interleave (index % total) でシャードへ割り当てる。遅いテストが偏らないよう
 *     round-robin で散らす。
 *   - <testsuites> の中身を、選ばれたファイルだけを <file> で列挙する単一 testsuite に置換。
 *   - 自 shard の割当ファイル数が 0 件なら exit 2 (テスト 0 件で緑になる偽グリーン防止)。
 */

if ($argc < 4) {
    fwrite(STDERR, "usage: php make-shard-phpunit.php <shardIndex> <shardTotal> <outPath>\n");
    exit(2);
}

$shardIndex = (int) $argv[1];
$shardTotal = (int) $argv[2];
$outPath = $argv[3];

if ($shardIndex < 1 || $shardTotal < 1 || $shardIndex > $shardTotal) {
    fwrite(STDERR, "invalid shard: {$shardIndex}/{$shardTotal}\n");
    exit(2);
}

$root = dirname(__DIR__, 2);
$src = $root.'/phpunit.xml';

$dom = new DOMDocument;
$dom->preserveWhiteSpace = false;
$dom->formatOutput = true;
if (! $dom->load($src)) {
    fwrite(STDERR, "failed to load {$src}\n");
    exit(1);
}

$xpath = new DOMXPath($dom);

/** @var DOMElement|null $testsuites */
$testsuites = $xpath->query('//testsuites')->item(0);
if (! $testsuites instanceof DOMElement) {
    fwrite(STDERR, "no <testsuites> in phpunit.xml\n");
    exit(1);
}

// <testsuites>/<testsuite>/<directory> を収集 (Unit / Feature / Architecture)。
$dirs = [];
foreach ($xpath->query('//testsuites/testsuite') as $suiteNode) {
    if (! $suiteNode instanceof DOMElement) {
        continue;
    }
    foreach ($suiteNode->getElementsByTagName('directory') as $d) {
        $dirs[] = trim($d->textContent);
    }
}

// 各ディレクトリ配下の *Test.php を収集。
$files = [];
foreach ($dirs as $dir) {
    $abs = $root.'/'.$dir;
    if (! is_dir($abs)) {
        continue;
    }
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($abs, FilesystemIterator::SKIP_DOTS));
    foreach ($it as $f) {
        /** @var SplFileInfo $f */
        if ($f->isFile() && str_ends_with($f->getFilename(), 'Test.php')) {
            // phpunit 設定からの相対パス (= ルート相対) で記録。
            $files[] = ltrim(str_replace($root, '', $f->getPathname()), '/');
        }
    }
}

sort($files); // 決定的順序

// interleave 割り当て: index % total == shardIndex-1
$mine = [];
foreach ($files as $i => $file) {
    if ($i % $shardTotal === $shardIndex - 1) {
        $mine[] = $file;
    }
}

// 偽グリーン防止: 0 件は gate を無効化する。
if (count($mine) === 0) {
    fwrite(STDERR, sprintf(
        "shard %d/%d collected 0 files. refusing to emit empty gate.\n",
        $shardIndex, $shardTotal
    ));
    exit(2);
}

// <testsuites> の中身を置換。
while ($testsuites->firstChild) {
    $testsuites->removeChild($testsuites->firstChild);
}
$suite = $dom->createElement('testsuite');
$suite->setAttribute('name', "CIShard-{$shardIndex}-of-{$shardTotal}");
foreach ($mine as $file) {
    $fileEl = $dom->createElement('file', $file);
    $suite->appendChild($fileEl);
}
$testsuites->appendChild($suite);

if ($dom->save($outPath) === false) {
    fwrite(STDERR, "failed to write {$outPath}\n");
    exit(1);
}

fwrite(STDERR, sprintf("shard %d/%d: %d test files -> %s\n", $shardIndex, $shardTotal, count($mine), $outPath));
