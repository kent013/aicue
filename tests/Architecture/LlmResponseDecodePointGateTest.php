<?php

declare(strict_types=1);

use App\Exceptions\Manual\LlmOutputInvalidException;
use App\Prompts\ExampleSummaryPrompt;
use App\Prompts\ScenarioGenerationPrompt;
use App\Prompts\SopExtractFromMediaPrompt;
use App\Prompts\SopExtractPrompt;
use App\Prompts\WorkDecompositionPrompt;
use App\Support\Llm\GuardedPrompt;
use App\Support\Manual\LlmJson;
use Tests\Support\Llm\DecodePointPublicSurface;
use Tests\Support\Llm\Fixtures\LenientDecodePointProbe;
use Tests\Support\Llm\LlmResponseHandling;
use Tests\Support\Llm\LlmResponseSeamResolution;
use Tests\Support\Llm\LlmResponseSeamScanner;
use Tests\Support\Llm\LlmSeamInventoryRules;
use Tests\Support\PhpReferenceScanner;
use Tests\Support\Prompts\PromptFactoryPopulation;
use Tests\Support\PromptYaml;

/**
 * LLM 応答の**復号点の単一性** gate (家系の正典 v1 の i1)。
 *
 * 土台にするのは「**LLM 応答が app/ に入る唯一の入口は `GuardedPrompt::executeSync()` である**」
 * という既存の事実 (窓口方式 T169。`PromptGuardrailTest` / `PromptDefenseWindowGateTest` が
 * Prism 直呼びの不在と窓口の公開面を既に固定している)。本 gate はその入口を**全数分類**し、
 * 応答が復号点以外へ流れない形を構造で閉じる。
 *
 * ## 8 つの検査
 *
 * 1. 依頼文の全数分類 + 目録項目の妥当性 (双方向・deny-by-default)
 * 2. 応答の受け取り口の全数分類 (3 分類。**未解決は 1 件でも失敗**)
 * 3. 応答の流れの構造的封じ (`executeSync()` は登録済みの受け取り関数の**直接の引数**)
 * 4. `GuardedPrompt` の参照者の分類
 * 5. 復号語彙 (`json_decode` / 囲みの印) の不在
 * 6. 依頼文 YAML と受理契約の同期
 * 7. 復号点の公開面の pin (緩い入口を持たない = i4)
 * 8. 受け取り関数が復号点に直結していること
 *
 * ## 保証しないもの (誇張しない)
 *
 * - 反射・動的に組み立てたクラス名・文字列キーだけの container 解決の経路は見えない。
 * - **動的な関数呼び出し** (`$fn($text)` / 変数に入れた callable) は見えない
 *   (文字列リテラルの完全一致だけは拾う)。走査器の docblock が正本。
 * - `vendor/` 配下と `tests/` 配下は走査しない。
 * - `json_decode` の不在を保証するのは**宣言した 4 つの走査根の中だけ**である
 *   (`app/` の他の `json_decode` は OIDC メタデータ・webhook 署名・冪等キー等の
 *    LLM と無関係な経路であり対象外。応答をそこへ運ぶ経路の側は検査 2・3 が塞ぐ)。
 * - `app/Services/AI/Testing/` は**応答を作る側**なので走査根に入れない
 *   (囲みの印を持つのが正しい)。
 * - 「復号点を通す」以外の 2 分類 (`ProviderShape` / `FreeText`) は**目録の宣言を信じる**
 *   (宣言と実装の食い違いを機械で見てはいない)。
 * - 検査 7 が見るのは**そのクラス自身が宣言した public メソッド**だけである
 *   (`DecodePointPublicSurface` の docblock が正本)。
 *
 * 負例は検出器の自己検査 (`tests/Unit/Architecture/LlmResponseSeamScannerTest.php`) と
 * 見本ファイル (`tests/Architecture/fixtures/llm-seam/`) に置く。
 */

/** 復号語彙を書いてよい唯一のファイル (完全一致 1 件)。 */
const LLM_DECODE_POINT_PATH = 'app/Support/Manual/LlmJson.php';

/** 復号語彙の不在を見る走査根 (存在しない根は fail-fast)。 */
const LLM_DECODE_VOCABULARY_ROOTS = [
    'app/Support/Manual',
    'app/DataTransferObjects/Manual/Analysis',
    'app/Services/Manual',
    'app/Prompts',
];

/** 依頼文 YAML に必ず書く出力指示 (受理契約と依頼文が黙って食い違う状態を作らない)。 */
const LLM_FENCE_INSTRUCTION = '出力は ```json の囲みちょうど 1 つに入れた JSON だけを返してください';

/**
 * 依頼文 factory の応答の扱い (deny-by-default)。
 *
 * `reason` は `Decoded` のときだけ空文字列で、それ以外は 30 文字以上の根拠が要る。
 *
 * @return array<class-string, array{kind: LlmResponseHandling, template: string, reason: string}>
 */
function llmResponseHandlingInventory(): array
{
    return [
        SopExtractPrompt::class => ['kind' => LlmResponseHandling::Decoded, 'template' => 'sop-extract', 'reason' => ''],
        SopExtractFromMediaPrompt::class => ['kind' => LlmResponseHandling::Decoded, 'template' => 'sop-extract-media', 'reason' => ''],
        WorkDecompositionPrompt::class => ['kind' => LlmResponseHandling::Decoded, 'template' => 'work-decomposition', 'reason' => ''],
        ScenarioGenerationPrompt::class => ['kind' => LlmResponseHandling::Decoded, 'template' => 'scenario-generation', 'reason' => ''],
        ExampleSummaryPrompt::class => ['kind' => LlmResponseHandling::FreeText, 'template' => 'example-summary',
            'reason' => '見本の依頼文で応答は 1 文の要約 (文章) であり、構造化データとして読む経路を持たない'],
    ];
}

/**
 * 登録済みの受け取り関数 (`{FQCN}::{method}`)。
 *
 * `executeSync()` の応答はこの引数として**直接**渡されなければならない
 * (変数へ束縛する形は検査 3 で赤くなる)。
 *
 * @return list<string>
 */
function llmResponseReceivers(): array
{
    return [
        'App\DataTransferObjects\Manual\Analysis\ExtractedSopData::fromLlmText',
        'App\DataTransferObjects\Manual\Analysis\WorkDecompositionResponseData::fromLlmText',
        'App\DataTransferObjects\Manual\Analysis\GeneratedScenarioData::fromLlmText',
    ];
}

/**
 * 目録外の型に解決された `executeSync()` の受け手 (理由つき)。**現在 0 件**。
 *
 * @return array<string, string> 完全修飾名 => 30 文字以上の根拠
 */
function llmResponseOtherReceivers(): array
{
    return [];
}

/**
 * `executeSync()` の母集団から外すファイル (理由つき。deny-by-default の exact-fit)。
 *
 * @return array<string, string> 相対パス => 30 文字以上の根拠
 */
function llmExecuteSyncPopulationExemptions(): array
{
    return [
        'app/Support/Llm/GuardedPrompt.php' => '実行単位そのもの。vendor の Prompt へ委譲する内側の呼び出しであり、'
            .'応答を受け取る側ではない (窓口の公開面は PromptDefenseWindowGateTest が pin する)',
    ];
}

/**
 * 走査対象の app/ ファイル (相対パス => ソース)。
 *
 * @return array<string, string>
 */
function llmSeamAppFiles(): array
{
    return PhpReferenceScanner::phpFiles(base_path('app'), 'app');
}

// ---- 検査 1: 依頼文の全数分類 ----

test('検査 1: app/Prompts/ の依頼文は全数が応答の扱いの目録に載る (双方向)', function (): void {
    $population = PromptFactoryPopulation::classes();
    expect($population)->not->toBeEmpty('依頼文 factory の母集団が空 (走査が壊れている)');

    $registered = array_keys(llmResponseHandlingInventory());
    sort($registered);
    expect(array_values($population))->toBe($registered);
});

test('検査 1: 目録の項目が妥当 (template の実在 / Decoded 以外は 30 文字以上の根拠)', function (): void {
    foreach (llmResponseHandlingInventory() as $class => $entry) {
        expect($entry['template'])->not->toBe('', "{$class}: template が空");
        expect(file_exists(resource_path("prompts/{$entry['template']}.yaml")))
            ->toBeTrue("{$class}: 依頼文 YAML {$entry['template']}.yaml が実在しない");

        if ($entry['kind'] === LlmResponseHandling::Decoded) {
            expect($entry['reason'])->toBe('', "{$class}: Decoded は reason を空にする");

            continue;
        }
        expect(mb_strlen($entry['reason']))->toBeGreaterThanOrEqual(
            30,
            "{$class}: Decoded 以外は 30 文字以上の根拠が必要",
        );
    }
});

// ---- 検査 2: 応答の受け取り口の全数分類 ----

test('検査 2: executeSync() の呼び出し点は全数が解決でき、目録の依頼文 factory である', function (): void {
    $factories = array_keys(llmResponseHandlingInventory());
    $exemptions = llmExecuteSyncPopulationExemptions();
    $files = llmSeamAppFiles();

    $total = 0;
    $unresolved = [];
    /** @var list<string> $otherFactories */
    $otherFactories = [];
    /** @var array<string, int> $siteCounts */
    $siteCounts = [];
    foreach ($files as $path => $source) {
        $findings = LlmResponseSeamScanner::executeSyncSites($path, $source, $factories);
        $siteCounts[$path] = count($findings);
        if (array_key_exists($path, $exemptions)) {
            continue; // 免除の前提検査 (site を持つこと) は下の目録判定が見る
        }
        foreach ($findings as $finding) {
            $total++;
            match ($finding->resolution) {
                LlmResponseSeamResolution::Unresolved => $unresolved[] = $finding->location(),
                LlmResponseSeamResolution::ResolvedOther => $otherFactories[] = (string) $finding->factory,
                LlmResponseSeamResolution::ResolvedPromptFactory => null,
            };
        }
    }

    expect($total)->toBeGreaterThan(0, 'executeSync() の母集団が空 (走査が壊れている)');
    expect($unresolved)->toBe([], '受け手を解決できない executeSync() があります (共通規約 (b): 未解決は落とす)');

    // 免除は exact-fit (実在 / 30 文字以上の根拠 / **前提が生きていること**)。
    // 目録外の型は**完全修飾名の完全一致**で双方向に照合する。
    // ★判定は純関数へ切り出してあり、負例は同じ関数を通して裏取りしてある (共通規約 (c))
    expect(LlmSeamInventoryRules::exemptionViolations($exemptions, array_keys($files), $siteCounts))->toBe([]);
    expect(LlmSeamInventoryRules::otherReceiverViolations($otherFactories, llmResponseOtherReceivers()))->toBe([]);
});

// ---- 検査 3: 応答の流れの構造的封じ ----

test('検査 3: 復号点を通す依頼文の応答は登録済みの受け取り関数の直接の引数である', function (): void {
    $inventory = llmResponseHandlingInventory();
    $factories = array_keys($inventory);
    $receivers = llmResponseReceivers();
    $exemptions = llmExecuteSyncPopulationExemptions();

    $checked = 0;
    $violations = [];
    foreach (llmSeamAppFiles() as $path => $source) {
        if (array_key_exists($path, $exemptions)) {
            continue;
        }
        foreach (LlmResponseSeamScanner::executeSyncSites($path, $source, $factories) as $finding) {
            if ($finding->resolution !== LlmResponseSeamResolution::ResolvedPromptFactory) {
                continue;
            }
            $factory = $finding->factory;
            if ($factory === null || ($inventory[$factory]['kind'] ?? null) !== LlmResponseHandling::Decoded) {
                continue; // free_text / provider_shape は受け取り関数を持たない
            }
            $checked++;
            if ($finding->enclosingCall === null || ! in_array($finding->enclosingCall, $receivers, true)) {
                $violations[] = $finding->location().' => '.($finding->enclosingCall ?? '(解決できない形)');
            }
        }
    }

    expect($checked)->toBeGreaterThan(0, '復号点を通す executeSync() が 1 件も無い (走査が壊れている)');
    expect($violations)->toBe([], '応答が登録済みの受け取り関数以外へ渡っています');
});

// ---- 検査 4: GuardedPrompt の参照者の分類 ----

test('検査 4: GuardedPrompt を参照する app/ のファイルは依頼文 factory か窓口・実行単位だけ', function (): void {
    $allowedPrefix = 'app/Support/Llm/';
    $factories = array_keys(llmResponseHandlingInventory());

    $referencing = [];
    $violations = [];
    foreach (llmSeamAppFiles() as $path => $source) {
        if (! LlmResponseSeamScanner::referencesGuardedPrompt($path, $source, GuardedPrompt::class)) {
            continue;
        }
        $referencing[] = $path;
        if (str_starts_with($path, $allowedPrefix)) {
            continue;
        }
        $class = 'App\\'.str_replace('/', '\\', substr($path, strlen('app/'), -4));
        if (! in_array($class, $factories, true)) {
            $violations[] = $path;
        }
    }

    expect($referencing)->not->toBeEmpty('GuardedPrompt の参照が 1 件も無い (走査が壊れている)');
    expect($violations)->toBe([], 'GuardedPrompt を参照する未登録のファイルがあります');
});

// ---- 検査 5: 復号語彙の不在 ----

test('検査 5: 走査根に json_decode と囲みの印の文字列リテラルが無い (復号点の 1 件を除く)', function (): void {
    $scanned = 0;
    $violations = [];
    foreach (LLM_DECODE_VOCABULARY_ROOTS as $root) {
        $absolute = realpath(base_path($root));
        expect($absolute)->toBeString("走査根を解決できません: {$root}");
        /** @var string $absolute */
        $files = PhpReferenceScanner::phpFiles($absolute, $root);
        expect($files)->not->toBeEmpty("走査根が空です: {$root}");

        foreach ($files as $path => $source) {
            if ($path === LLM_DECODE_POINT_PATH) {
                continue;
            }
            $scanned++;
            $violations = [...$violations, ...LlmResponseSeamScanner::decodeVocabularyViolations($path, $source)];
        }
    }

    expect($scanned)->toBeGreaterThan(0, '走査対象が空 (走査が壊れている)');
    expect($violations)->toBe([], '復号点以外で応答を自前で読む語彙が現れています');
});

test('検査 5: 除外している復号点は実在し、実際に復号語彙を持つ (負のコントロール)', function (): void {
    $source = file_get_contents(base_path(LLM_DECODE_POINT_PATH));
    expect($source)->toBeString();
    /** @var string $source */
    expect(LlmResponseSeamScanner::decodeVocabularyViolations(LLM_DECODE_POINT_PATH, $source))
        ->not->toBe([], '復号点が復号語彙を持たない = 検出条件が壊れている');
});

// ---- 検査 6: 依頼文と受理契約の同期 ----

test('検査 6: 復号点を通す依頼文 YAML は囲みちょうど 1 つを指示している', function (): void {
    $checked = 0;
    foreach (llmResponseHandlingInventory() as $class => $entry) {
        if ($entry['kind'] !== LlmResponseHandling::Decoded) {
            continue;
        }
        $path = resource_path("prompts/{$entry['template']}.yaml");
        /** @var list<string> $problems */
        $problems = [];
        $parsed = PromptYaml::parseOrFail($path, $problems);
        expect($problems)->toBe([]);
        expect($parsed)->toBeArray();
        /** @var array<string, mixed> $parsed */
        $systemPrompt = $parsed['system_prompt'] ?? null;
        expect($systemPrompt)->toBeString("{$class}: system_prompt がありません");
        /** @var string $systemPrompt */
        expect(str_contains($systemPrompt, LLM_FENCE_INSTRUCTION))->toBeTrue(
            "{$class}: 依頼文 {$entry['template']}.yaml に所定の出力指示がありません",
        );
        $checked++;
    }

    expect($checked)->toBeGreaterThan(0, '復号点を通す依頼文が 1 件も無い (目録が壊れている)');
});

// ---- 検査 7: 復号点の公開面の pin ----

test('検査 7: 復号点の公開面は decode / schemaViolation の 2 つだけ (緩い入口を持たない)', function (): void {
    expect(DecodePointPublicSurface::violations(LlmJson::class, LlmOutputInvalidException::class))->toBe([]);
});

test('検査 7 の負例: 緩い入口を足した見本は同じ判定経路で赤くなる', function (): void {
    // ★本番と**同一の判定関数**へ渡す (負例が別ロジックで数えると検出力を証明しない)
    expect(DecodePointPublicSurface::violations(LenientDecodePointProbe::class, LlmOutputInvalidException::class))
        ->not->toBe([]);
});

// ---- 検査 8: 受け取り関数が復号点に直結していること ----

test('検査 8: 受け取り関数は生の応答を復号点へ直接 1 回だけ渡す', function (): void {
    $violations = [];
    foreach (llmResponseReceivers() as $receiver) {
        [$class, $method] = explode('::', $receiver, 2);
        $relative = 'app/'.str_replace('\\', '/', substr($class, strlen('App\\'))).'.php';
        $absolute = base_path($relative);
        expect(file_exists($absolute))->toBeTrue("受け取り関数のファイルが実在しません: {$relative}");

        $source = file_get_contents($absolute);
        expect($source)->toBeString();
        /** @var string $source */
        $violations = [...$violations, ...LlmResponseSeamScanner::receiverFlowViolations(
            $relative,
            $source,
            $class,
            $method,
            LlmJson::class,
            'decode',
        )];
    }

    expect(llmResponseReceivers())->not->toBeEmpty('受け取り関数の目録が空');
    expect($violations)->toBe([], '受け取り関数の中で生の応答が復号点以外へ流れています');
});
