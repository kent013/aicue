<?php

declare(strict_types=1);

namespace Tests\Support\Llm;

use App\Support\Llm\GuardedPrompt;
use App\Support\Llm\PromptCanary;
use Kent013\PrismPrompt\Prompt;
use ReflectionProperty;
use Webmozart\Assert\Assert;

/**
 * `GuardedPrompt` の内側と vendor `Prompt` の private プロパティを覗く**唯一の場所**。
 *
 * ★ なぜ 1 ファイルに閉じるか: 検査には `GuardedPrompt::$prompt` / `$canary` と、
 *   vendor の `templateVariables` / `metadata_context` という 4 つの private への依存が要る。
 *   これを各テストへ散らすと、vendor がプロパティを改名したときに壊れる箇所が増える。
 *   ここだけが壊れる形にしておく (依存の量は増やさず、置き場所を 1 つにする)。
 * ★ `GuardedPrompt` に脱出口 (`inner()` 等) を生やして解決しない。公開面を増やすと
 *   本番コードから応答検査を迂回できてしまう (窓口 gate が公開面を完全一致で pin している)。
 */
final class GuardedPromptInspector
{
    /** 組み立て済みの vendor prompt (実体は TextPrompt)。 */
    public static function prompt(GuardedPrompt $guarded): Prompt
    {
        $property = new ReflectionProperty(GuardedPrompt::class, 'prompt');
        $prompt = $property->getValue($guarded);
        Assert::isInstanceOf($prompt, Prompt::class);

        return $prompt;
    }

    /** system prompt にだけ載る合言葉 (漏洩応答の合成に使う)。 */
    public static function canaryToken(GuardedPrompt $guarded): string
    {
        $property = new ReflectionProperty(GuardedPrompt::class, 'canary');
        $canary = $property->getValue($guarded);
        Assert::isInstanceOf($canary, PromptCanary::class);

        return $canary->token;
    }

    /**
     * `Prompt::load()` へ渡された template 変数 (untrusted は UserInput、合言葉は string)。
     *
     * @return array<string, mixed>
     */
    public static function templateVariables(GuardedPrompt $guarded): array
    {
        $property = new ReflectionProperty(Prompt::class, 'templateVariables');
        $variables = $property->getValue(self::prompt($guarded));
        Assert::isArray($variables);
        Assert::allString(array_keys($variables));

        /** @var array<string, mixed> $variables */
        return $variables;
    }

    /**
     * `withMetadata()` で付けた帰属 (llm_call_logs の organization / subject)。
     *
     * @return array<string, mixed>
     */
    public static function metadataContext(GuardedPrompt $guarded): array
    {
        $property = new ReflectionProperty(Prompt::class, 'metadata_context');
        $metadata = $property->getValue(self::prompt($guarded));
        Assert::isArray($metadata);
        Assert::allString(array_keys($metadata));

        /** @var array<string, mixed> $metadata */
        return $metadata;
    }

    /** Blade 描画後の user prompt (untrusted がタグ境界化されて載る側)。 */
    public static function renderedUserPrompt(GuardedPrompt $guarded): string
    {
        return self::prompt($guarded)->renderUserPromptForPool();
    }

    /** Blade 描画後の system prompt (防御指示と合言葉が載る側)。 */
    public static function renderedSystemPrompt(GuardedPrompt $guarded): string
    {
        return self::prompt($guarded)->getRenderedSystemPrompt();
    }
}
