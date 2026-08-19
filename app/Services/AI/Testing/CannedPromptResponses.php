<?php

declare(strict_types=1);

namespace App\Services\AI\Testing;

use Kent013\PrismPrompt\Testing\TextResponseFake;
use Prism\Prism\Contracts\Message;
use Prism\Prism\ValueObjects\Messages\SystemMessage;
use RuntimeException;
use Webmozart\Assert\Assert;

/**
 * canned response の定義と SystemMessage signature による解決。
 *
 * 全実プロンプトは Prompt::load 経由で generic TextPrompt を返すため、
 * PromptFake::record() のキー (static::class) は TextPrompt::class に潰れる。
 * よってクラス名ではなく system_prompt の役割文 (signature) で canned を返し分ける。
 * signature は各 YAML 固有の一意句 (DefensiveInstructions preamble は全 YAML 共通なので使わない)。
 *
 * 未一致 (0 件) / 曖昧 (2 件以上) はいずれも fail-fast で例外を投げ、silent false-positive を防ぐ。
 * Browser lane (tests/Pest.php) と bughunt 実行時 (BughuntFakesServiceProvider) の双方で共有される。
 */
final class CannedPromptResponses
{
    /**
     * SystemMessage の内容から一意な signature を引いて canned を返す。
     *
     * @param  array<int, Message>  $messages
     */
    public function forMessages(array $messages): TextResponseFake
    {
        $systemText = $this->systemMessageText($messages);

        $matched = [];
        foreach ($this->map() as $signature => $canned) {
            if ($systemText !== '' && str_contains($systemText, $signature)) {
                $matched[$signature] = $canned;
            }
        }

        if (count($matched) !== 1) {
            $registered = implode(', ', array_keys($this->map()));
            $collided = implode(', ', array_keys($matched));
            $snippet = mb_substr($systemText, 0, 200);
            throw new RuntimeException(sprintf(
                'CannedPromptResponses could not uniquely resolve a canned response '
                ."(matched %d signatures: [%s]).\nRegistered signatures: [%s]\n"
                ."System text (first 200 chars): %s\n"
                .'Register/adjust one in app/Services/AI/Testing/CannedPromptResponses.php '
                .'to avoid silent false-positives.',
                count($matched),
                $collided === '' ? '(none)' : $collided,
                $registered,
                $snippet,
            ));
        }

        $canned = array_values($matched)[0];
        Assert::string($canned);

        return TextResponseFake::make()->withText($canned);
    }

    /**
     * @return list<string> 登録済み signature 一覧 (テスト用)
     */
    public function supportedSignatures(): array
    {
        return array_keys($this->map());
    }

    /**
     * @param  array<int, Message>  $messages
     */
    private function systemMessageText(array $messages): string
    {
        $parts = [];
        foreach ($messages as $message) {
            if ($message instanceof SystemMessage) {
                $parts[] = $message->content;
            }
        }

        return implode("\n", $parts);
    }

    /**
     * signature (system_prompt 固有の一意句) => canned response (決定論)。
     *
     * @return array<string, string>
     */
    private function map(): array
    {
        return [
            '作業手順書 (SOP) を構造化するエキスパート' => self::sopExtractCanned(),
            '手順書を画像や PDF から読み取り構造化するエキスパート' => self::sopExtractMediaCanned(),
            '作業標準化エキスパート' => self::workDecompositionCanned(),
            'マニュアル動画の演出家' => self::scenarioGenerationCanned(),
            'テキストを 1 文に要約するアシスタント' => self::exampleSummaryCanned(),
        ];
    }

    // ---- canned 応答 (各 DTO の fromLlmText を通過する最小妥当 JSON) ----

    /** sop-extract: ExtractedSopData::fromLlmText を通過 (header + 1 section + 1 step) */
    private static function sopExtractCanned(): string
    {
        return json_encode([
            'header' => ['title' => 'bughunt サンプル手順書', 'department' => null, 'revision' => null],
            'sections' => [[
                'title' => null,
                'steps' => [[
                    'no' => 1,
                    'work_process' => 'バルブを閉じる',
                    'work_points' => ['ハンドルを時計回りに回す'],
                    'safety_points' => ['保護手袋を着用する'],
                    'quality_points' => [],
                    'pm_points' => [],
                ]],
            ]],
        ], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    }

    /** sop-extract-media (OCR 経路): ExtractedSopData::fromLlmText を通過する最小妥当 JSON */
    private static function sopExtractMediaCanned(): string
    {
        return json_encode([
            'header' => ['title' => 'bughunt サンプル手順書 (画像)', 'department' => null, 'revision' => null],
            'sections' => [[
                'title' => null,
                'steps' => [[
                    'no' => 1,
                    'work_process' => 'バルブを閉じる',
                    'work_points' => ['ハンドルを時計回りに回す'],
                    'safety_points' => ['保護手袋を着用する'],
                    'quality_points' => [],
                    'pm_points' => [],
                ]],
            ]],
        ], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    }

    /** work-decomposition: WorkDecompositionResponseData::fromLlmText を通過 (1 step / points 1 / 所見つき) */
    private static function workDecompositionCanned(): string
    {
        return json_encode([
            'steps' => [[
                'no' => 1,
                'action' => 'バルブを閉じる',
                'points' => ['ハンドルが止まるまで回す'],
            ]],
            'validation' => [
                'verdict' => 'valid',
                'reason' => '手順と急所が読み取れており、動画マニュアルの元資料として成立しています。',
                'works' => ['バルブ閉止作業'],
                'split_recommended' => false,
            ],
        ], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    }

    /** scenario-generation: GeneratedScenarioData::fromLlmText を通過 (step→それを参照する point) */
    private static function scenarioGenerationCanned(): string
    {
        return json_encode([
            'cuts' => [
                [
                    'no' => 1, 'type' => 'step', 'parent_no' => null,
                    'scene' => '作業台全体を引きで写す', 'shot_type' => 'hiki',
                    'shooting_point' => null, 'narration' => 'バルブを閉じます。',
                    'subtitle_primary' => 'バルブ閉', 'subtitle_secondary' => '',
                ],
                [
                    'no' => 2, 'type' => 'point', 'parent_no' => 1,
                    'scene' => 'ハンドル操作を寄りで写す', 'shot_type' => 'yori',
                    'shooting_point' => null, 'narration' => 'ハンドルが止まるまで回します。',
                    'subtitle_primary' => null, 'subtitle_secondary' => '',
                ],
            ],
        ], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    }

    /** example-summary: 1 文の要約 (非空 string) */
    private static function exampleSummaryCanned(): string
    {
        return 'テスト/bughunt 共通の固定要約文です。';
    }
}
