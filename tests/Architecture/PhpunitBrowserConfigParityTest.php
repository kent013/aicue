<?php

declare(strict_types=1);

use Webmozart\Assert\Assert;

/*
 * Architecture invariant: phpunit.xml と phpunit.browser.xml の <php> 設定同値性。
 *
 * 背景: Browser lane は Feature lane と同じ worktree 固有 pgsql テスト DB を使い、
 * 同じ LLM 実通信遮断 (StrayLlmCallGuard の最終防壁となるダミー API キー) を要求する。
 * 片方にだけ新 provider のダミーキーを足す / 片方だけ SESSION_DRIVER を変える、という
 * 乖離は「Browser lane だけ dev DB を向く」「Browser lane だけ実 LLM を叩く」に直結する。
 * 唯一許される差分は memory_limit (実ブラウザ + in-process サーバ分の余裕)。
 *
 * phpunit.browser.xml のコメントが既に宣言している契約
 * (「<php> の値は phpunit.xml と揃える (乖離させない)。差分は memory_limit のみ」) を
 * 機械強制するのが本テストの役割。禁止事項 1 に従い、不変条件を Architecture テストへ
 * 登録するところまでを「実装済み」とする。
 *
 * 本テストは DB を触らない (ファイル読み取りのみ)。
 */

/** Browser lane にのみ存在してよい <ini> の name。ここを増やすときは理由を書くこと。 */
const PHPUNIT_BROWSER_ONLY_INI = [
    // 実ブラウザ + in-process サーバの分だけ余裕を持たせる (phpunit.browser.xml のコメント)。
    'memory_limit',
];

/**
 * 設定ファイルの <php> 配下 <server> を name => [value, force] へ正規化する (純関数)。
 *
 * @return array<string, array{value: string, force: bool}>
 */
function phpunitServerEntries(string $xml): array
{
    $entries = [];
    foreach (phpunitPhpChildren($xml, 'server') as $element) {
        $force = strtolower($element->getAttribute('force'));
        $entries[$element->getAttribute('name')] = [
            'value' => $element->getAttribute('value'),
            'force' => $force === 'true' || $force === '1',
        ];
    }

    ksort($entries);

    return $entries;
}

/**
 * <php> 配下 <ini> を name => value へ正規化する (純関数)。
 *
 * @return array<string, string>
 */
function phpunitIniEntries(string $xml): array
{
    $entries = [];
    foreach (phpunitPhpChildren($xml, 'ini') as $element) {
        $entries[$element->getAttribute('name')] = $element->getAttribute('value');
    }

    ksort($entries);

    return $entries;
}

/**
 * <testsuites> 配下の <directory> を返す (純関数)。
 *
 * @return list<string>
 */
function phpunitSuiteDirectories(string $xml): array
{
    $directories = [];
    foreach (phpunitQuery($xml, '//testsuites//directory') as $element) {
        $directories[] = trim($element->textContent);
    }

    return $directories;
}

/** phpunit 設定の bootstrap 属性を返す (純関数)。 */
function phpunitBootstrap(string $xml): string
{
    $elements = phpunitQuery($xml, '/phpunit');
    Assert::notEmpty($elements, 'ルート要素 <phpunit> が見つからない');

    return $elements[0]->getAttribute('bootstrap');
}

/**
 * `<php>` 直下の指定タグを DOMElement のリストで返す (純関数)。
 *
 * @return list<DOMElement>
 */
function phpunitPhpChildren(string $xml, string $tag): array
{
    return phpunitQuery($xml, '//php/'.$tag);
}

/**
 * XPath で DOMElement のみを取り出す (純関数)。
 *
 * DOMXPath::query() の戻り (DOMNodeList|false) と item() の (DOMNode|null) を
 * instanceof で narrow する (PHPStan level 10)。
 *
 * @return list<DOMElement>
 */
function phpunitQuery(string $xml, string $expression): array
{
    $document = new DOMDocument;
    // 整形用の空白テキストノードを落として走査を安定させる。
    $document->preserveWhiteSpace = false;
    Assert::true($document->loadXML($xml), "XML の parse に失敗した: {$expression}");

    $nodes = (new DOMXPath($document))->query($expression);
    if ($nodes === false) {
        return [];
    }

    $elements = [];
    foreach ($nodes as $node) {
        if ($node instanceof DOMElement) {
            $elements[] = $node;
        }
    }

    return $elements;
}

/**
 * 2 つの設定 XML の <php>/<server>・<ini>・testsuite・bootstrap の乖離を列挙する (純関数)。
 *
 * 実ファイルを読まない純関数に切り出すのは、負のコントロール (検出器が空振りしていないこと)
 * を fixture 文字列で確認できるようにするため。
 *
 * @return list<string> 違反一覧 (空 = 合格)
 */
function phpunitBrowserParityViolations(string $baseXml, string $browserXml): array
{
    $violations = [];

    // P1: <server> 集合が name / value / force まで完全一致
    $baseServers = phpunitServerEntries($baseXml);
    $browserServers = phpunitServerEntries($browserXml);

    foreach ($baseServers as $name => $entry) {
        if (! array_key_exists($name, $browserServers)) {
            $violations[] = "P1: <server name=\"{$name}\"> が phpunit.browser.xml に無い";

            continue;
        }
        if ($browserServers[$name]['value'] !== $entry['value']) {
            $violations[] = sprintf(
                'P1: <server name="%s"> の value が乖離している (phpunit.xml="%s" / browser="%s")',
                $name,
                $entry['value'],
                $browserServers[$name]['value'],
            );
        }
        if ($browserServers[$name]['force'] !== $entry['force']) {
            $violations[] = sprintf(
                'P1: <server name="%s"> の force が乖離している (phpunit.xml=%s / browser=%s)',
                $name,
                $entry['force'] ? 'true' : 'false',
                $browserServers[$name]['force'] ? 'true' : 'false',
            );
        }
    }
    foreach ($browserServers as $name => $_entry) {
        if (! array_key_exists($name, $baseServers)) {
            $violations[] = "P1: <server name=\"{$name}\"> が phpunit.browser.xml にのみ存在する";
        }
    }

    // P2 / P3: <ini> の差分は memory_limit のみ
    $baseIni = phpunitIniEntries($baseXml);
    $browserIni = phpunitIniEntries($browserXml);

    foreach (PHPUNIT_BROWSER_ONLY_INI as $allowed) {
        if (! array_key_exists($allowed, $browserIni)) {
            $violations[] = "P2: <ini name=\"{$allowed}\"> が phpunit.browser.xml に無い";
        }
        if (array_key_exists($allowed, $baseIni)) {
            $violations[] = "P2: <ini name=\"{$allowed}\"> が phpunit.xml にもある (差分でなくなっている)";
        }
    }
    foreach ($browserIni as $name => $_value) {
        if (! in_array($name, PHPUNIT_BROWSER_ONLY_INI, true) && ! array_key_exists($name, $baseIni)) {
            $violations[] = "P3: <ini name=\"{$name}\"> が phpunit.browser.xml にのみ存在する (許可外の ini 差分)";
        }
    }
    foreach ($baseIni as $name => $value) {
        if (! array_key_exists($name, $browserIni)) {
            $violations[] = "P3: <ini name=\"{$name}\"> が phpunit.xml にのみ存在する";

            continue;
        }
        if ($browserIni[$name] !== $value) {
            $violations[] = "P3: <ini name=\"{$name}\"> の value が乖離している";
        }
    }

    // P4: bootstrap の一致 (dev-DB 保護の単一点ガードを共有する根拠)
    $baseBootstrap = phpunitBootstrap($baseXml);
    $browserBootstrap = phpunitBootstrap($browserXml);
    if ($baseBootstrap !== $browserBootstrap) {
        $violations[] = "P4: bootstrap が乖離している ({$baseBootstrap} / {$browserBootstrap})";
    }
    if ($baseBootstrap !== 'tests/bootstrap.php') {
        $violations[] = "P4: bootstrap が tests/bootstrap.php でない ({$baseBootstrap})";
    }

    // P5: testsuite の分離 (composer test から Browser テストが誤起動しないこと)
    $baseSuites = phpunitSuiteDirectories($baseXml);
    $browserSuites = phpunitSuiteDirectories($browserXml);

    foreach ($baseSuites as $directory) {
        if (str_starts_with($directory, 'tests/Browser')) {
            $violations[] = "P5: phpunit.xml の testsuite に {$directory} が含まれている (composer test から Browser が誤起動する)";
        }
    }
    if ($browserSuites !== ['tests/Browser']) {
        $violations[] = 'P5: phpunit.browser.xml の testsuite が tests/Browser のみでない ('.implode(', ', $browserSuites).')';
    }

    return $violations;
}

/** リポジトリ直下の設定ファイルを読む。 */
function phpunitConfigSource(string $relativePath): string
{
    $contents = file_get_contents(base_path($relativePath));
    Assert::string($contents, "{$relativePath} を読めない");

    return $contents;
}

test('phpunit.xml と phpunit.browser.xml の <php> / testsuite / bootstrap が契約どおりであること', function (): void {
    $violations = phpunitBrowserParityViolations(
        phpunitConfigSource('phpunit.xml'),
        phpunitConfigSource('phpunit.browser.xml'),
    );

    expect($violations)->toBe([], "phpunit 設定の乖離:\n".implode("\n", $violations));
});

test('P1 負のコントロール: browser 側にだけ <server> がある fixture を検出すること', function (): void {
    $base = phpunitConfigSource('phpunit.xml');
    $browser = str_replace(
        '<server name="SESSION_DRIVER" value="array" force="true"/>',
        '<server name="SESSION_DRIVER" value="array" force="true"/>'
            ."\n".'<server name="EXTRA_BROWSER_ONLY" value="x" force="true"/>',
        phpunitConfigSource('phpunit.browser.xml'),
    );

    expect(phpunitBrowserParityViolations($base, $browser))
        ->toContain('P1: <server name="EXTRA_BROWSER_ONLY"> が phpunit.browser.xml にのみ存在する');
});

test('P1 負のコントロール: force の差分まで検出すること', function (): void {
    $base = phpunitConfigSource('phpunit.xml');
    $browser = str_replace(
        '<server name="OPENAI_API_KEY" value="test-dummy-openai-key" force="true"/>',
        '<server name="OPENAI_API_KEY" value="test-dummy-openai-key" force="false"/>',
        phpunitConfigSource('phpunit.browser.xml'),
    );

    expect(phpunitBrowserParityViolations($base, $browser))
        ->toContain('P1: <server name="OPENAI_API_KEY"> の force が乖離している (phpunit.xml=true / browser=false)');
});

test('P3 負のコントロール: 許可外の <ini> 追加を検出すること', function (): void {
    $base = phpunitConfigSource('phpunit.xml');
    $browser = str_replace(
        '<ini name="memory_limit" value="1G"/>',
        '<ini name="memory_limit" value="1G"/>'."\n".'<ini name="error_reporting" value="0"/>',
        phpunitConfigSource('phpunit.browser.xml'),
    );

    expect(phpunitBrowserParityViolations($base, $browser))
        ->toContain('P3: <ini name="error_reporting"> が phpunit.browser.xml にのみ存在する (許可外の ini 差分)');
});

test('P5 負のコントロール: phpunit.xml の testsuite に tests/Browser を足すと検出すること', function (): void {
    $base = str_replace(
        '<directory>tests/Architecture</directory>',
        '<directory>tests/Architecture</directory>'."\n".'<directory>tests/Browser</directory>',
        phpunitConfigSource('phpunit.xml'),
    );

    expect(phpunitBrowserParityViolations($base, phpunitConfigSource('phpunit.browser.xml')))
        ->toContain('P5: phpunit.xml の testsuite に tests/Browser が含まれている (composer test から Browser が誤起動する)');
});

test('P4 負のコントロール: bootstrap の乖離を検出すること', function (): void {
    $browser = str_replace(
        'bootstrap="tests/bootstrap.php"',
        'bootstrap="vendor/autoload.php"',
        phpunitConfigSource('phpunit.browser.xml'),
    );

    expect(phpunitBrowserParityViolations(phpunitConfigSource('phpunit.xml'), $browser))
        ->toContain('P4: bootstrap が乖離している (tests/bootstrap.php / vendor/autoload.php)');
});
