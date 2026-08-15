<?php

declare(strict_types=1);

use App\Support\Llm\PromptCanary;
use App\Support\Llm\UntrustedTextSanitizer;
use Tests\Support\PhpReferenceScanner;
use Tests\Support\PhpTokenScan;

/*
 * 集約設定 (config/llm-defense.php) の gate (裁定 AG-028 の「防御設定の集約ファイル」)。
 *
 * ここが守るのは「防御の設定が 1 箇所に集まり、増殖も環境ごとの緩和もしない」ことである:
 *  - キーは宣言した 2 つだけ (文言や on/off スイッチを持ち込ませない)
 *  - どのキーも**宣言した読み手クラスから読まれている** (死んだ設定を残さない / 読み手が増えたら宣言を更新させる)
 *  - 値はすべて int
 *  - env を使わない (環境ごとに防御を緩める経路を作らない)
 *  - SOP 経路では利用者向け文言のほうが**先に**出る大小関係を保つ
 *
 * ★ env の検査は**字句**で行う (PhpTokenScan で正規化してからトークン列を見る)。
 *   ソースを正規表現で数えると gate 自身やファイル冒頭の説明文の "env" に反応する
 *   (家系の先行実装で実際に起きた事故)。
 */

/**
 * 設定キー => 読み手クラス (双方向 pin の宣言)。
 *
 * @return array<string, class-string>
 */
function llmDefenseConfigReaders(): array
{
    return [
        'max_untrusted_bytes' => UntrustedTextSanitizer::class,
        'canary_bytes' => PromptCanary::class,
    ];
}

/**
 * app/ 配下で `llm-defense.<key>` という文字列リテラルを持つファイル (相対パス)。
 *
 * @return list<string>
 */
function llmDefenseConfigReadSites(string $key): array
{
    $needle = 'llm-defense.'.$key;
    $paths = [];
    foreach (PhpReferenceScanner::phpFiles(dirname(__DIR__, 2).'/app', 'app') as $relative => $source) {
        foreach (PhpTokenScan::normalize($source) as $token) {
            if ($token['id'] !== T_CONSTANT_ENCAPSED_STRING) {
                continue;
            }
            if (trim($token['text'], "'\"") === $needle) {
                $paths[$relative] = true;
                break;
            }
        }
    }
    $unique = array_keys($paths);
    sort($unique);

    return $unique;
}

test('config(llm-defense) のキー集合は宣言した 2 つと完全一致する', function (): void {
    $keys = array_keys(config()->array('llm-defense'));
    sort($keys);

    $declared = array_keys(llmDefenseConfigReaders());
    sort($declared);

    expect($keys)->toBe($declared,
        '防御設定に持ち込んでよいのは構造的なしきい値だけです。'
        .'防御指示の文言は resources/prompts/*.yaml、防御の on/off スイッチは持ちません'
        .' (切れる防御は防御ではない)。');
});

test('全キーが宣言した読み手クラスからだけ読まれている (双方向 pin)', function (): void {
    $violations = [];
    foreach (llmDefenseConfigReaders() as $key => $reader) {
        $expected = 'app/'.str_replace('\\', '/', substr($reader, strlen('App\\'))).'.php';
        $actual = llmDefenseConfigReadSites($key);
        if ($actual !== [$expected]) {
            $violations[] = "llm-defense.{$key}: 期待 [{$expected}] / 実際 [".implode(', ', $actual).']';
        }
    }

    expect($violations)->toBe([],
        '設定キーの読み手が変わったら宣言 (llmDefenseConfigReaders) も同じ PR で更新してください。'
        .'読み手のいないキー = 死んだ設定、宣言外の読み手 = 防御の判断が散った状態です。'
        .PHP_EOL.implode(PHP_EOL, $violations));
});

test('全キーの値が int である', function (): void {
    foreach (config()->array('llm-defense') as $key => $value) {
        expect($value)->toBeInt("llm-defense.{$key} は int でなければなりません (文言や真偽スイッチの混入を防ぐ)");
    }
});

test('config/llm-defense.php のコード部分に env( が現れない', function (): void {
    $source = file_get_contents(base_path('config/llm-defense.php'));
    expect($source)->toBeString();

    $tokens = PhpTokenScan::normalize((string) $source);
    $count = count($tokens);
    $violations = [];
    for ($i = 0; $i < $count; $i++) {
        if ($tokens[$i]['id'] !== T_STRING || mb_strtolower($tokens[$i]['text']) !== 'env') {
            continue;
        }
        $next = $tokens[$i + 1] ?? null;
        if ($next !== null && $next['id'] === null && $next['text'] === '(') {
            $violations[] = 'line '.$tokens[$i]['line'];
        }
    }

    expect($violations)->toBe([],
        '防御のしきい値は環境ごとに変えてよい値ではありません (env 化すると本番だけ緩められる)。'
        .PHP_EOL.implode(PHP_EOL, $violations));
});

test('窓口の上限は SOP 経路の運用上限以上である (利用者向け文言が先に出る順序の固定)', function (): void {
    $windowLimit = config()->integer('llm-defense.max_untrusted_bytes');
    $sopLimit = config()->integer('manual.analysis_max_text_bytes');

    expect($windowLimit)->toBeGreaterThanOrEqual($sopLimit,
        '窓口の上限を SOP 経路の運用上限より小さくすると、大きい手順書で'
        .'「分割してアップロードしてください」の案内より先に窓口が落ちます。');
});
