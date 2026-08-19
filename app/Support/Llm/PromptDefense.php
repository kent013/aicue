<?php

declare(strict_types=1);

namespace App\Support\Llm;

use App\DataTransferObjects\LlmCallContextData;
use App\DataTransferObjects\Manual\Analysis\ImageAnalysisMediaData;
use App\DataTransferObjects\Manual\Analysis\PdfAnalysisMediaData;
use App\Exceptions\Llm\UntrustedInputRejectedException;
use Illuminate\Support\Facades\Log;
use Kent013\PrismPrompt\Prompt;
use Kent013\PrismPrompt\TextPrompt;
use Kent013\PrismPrompt\Values\UserInput;
use Prism\Prism\Contracts\Message;
use Prism\Prism\ValueObjects\Media\Document;
use Prism\Prism\ValueObjects\Media\Image;
use Prism\Prism\ValueObjects\Messages\UserMessage;
use Webmozart\Assert\Assert;

/**
 * LLM prompt の唯一の窓口 (裁定 AG-028 の「窓口クラス」)。
 *
 * ここ以外から vendor の `Prompt::load()` を呼んではならない
 * (`PromptDefenseWindowGateTest` / `PromptGuardrailTest` が構造で固定する)。
 *
 * 窓口の内側で行うこと: 無害化 → タグ境界化 (UserInput) → 合言葉の合流 → 帰属の付与。
 * 窓口の引数は**生の string の連想配列**なので、呼び出し側が自分で vendor の
 * 入力値型を作って渡す経路が型で消える。
 *
 * ★ trusted 変数の入口は**作らない**。現在 prompt YAML の変数はすべて untrusted であり、
 *   入口が無ければ「trusted に混ぜて素通しする」経路は構造的に存在しない。
 *   必要になったら入口・字句 gate・目録を同じ PR で足す (docs/template-divergence.md)。
 * ★ 監視条件 (AG-028): 実行時に決まる値 (履歴・過去の出力・他利用者の入力) を prompt へ
 *   入れる形が生まれたら、その経路も**必ず本窓口の untrusted 側**を通す。
 */
final class PromptDefense
{
    /** system prompt にだけ置く合言葉の変数名 (YAML と 1 対 1)。 */
    public const string CANARY_VARIABLE = 'llm_canary';

    /** untrusted 変数名として許す形 (合言葉との衝突と動的なキー生成を防ぐ)。 */
    private const string VARIABLE_NAME_PATTERN = '/\A[a-z][a-z0-9_]*\z/';

    /**
     * 実行経路を持つ prompt の窓口。**帰属 (`LlmCallContextData`) は必須**である
     * (AGENTS.md 禁止事項 5。既定 null にすると帰属なしの本番 prompt が通ってしまう)。
     *
     * @param  array<string, string>  $untrusted  YAML の変数名 => 外部由来の生文字列
     *
     * @throws UntrustedInputRejectedException
     */
    public static function load(string $template, array $untrusted, LlmCallContextData $context): GuardedPrompt
    {
        return self::build($template, $untrusted, $context);
    }

    /**
     * 帰属の対象を**構造的に持たない** prompt 専用の窓口 (テンプレート同梱の見本 1 本のみ)。
     *
     * ★ 呼び出し site は `app/Prompts/ExampleSummaryPrompt.php` **ただ 1 件**に
     *   `PromptDefenseWindowGateTest` が名指しで pin する。新しい factory はここへ
     *   滑り込めない (帰属を省く逃げ道にしない)。
     *
     * @param  array<string, string>  $untrusted
     *
     * @throws UntrustedInputRejectedException
     */
    public static function loadUnattributed(string $template, array $untrusted): GuardedPrompt
    {
        return self::build($template, $untrusted, null);
    }

    /**
     * 媒体添付用の窓口入口 (画像・スキャン SOP の OCR 対応)。既存の `load()` (生 string のみ) は
     * そのまま残し、別メソッドとして追加する (既存契約を緩めない)。媒体経路も帰属は必須
     * (`loadUnattributed()` 相当は無い)。
     *
     * @param  array<string, string>  $untrusted  既存と同じ: 生 string の連想配列
     * @param  ImageAnalysisMediaData|PdfAnalysisMediaData  $media  検証済み DTO のみ
     *                                                              (`AnalysisMediaValidator` が生成したものに限る。`PromptDefenseWindowGateTest` の
     *                                                              `MediaDataNamedConstructorCall` ルールが呼び出し箇所を pin する)
     *
     * @throws UntrustedInputRejectedException
     */
    public static function loadWithMedia(
        string $template,
        array $untrusted,
        ImageAnalysisMediaData|PdfAnalysisMediaData $media,
        LlmCallContextData $context,
    ): GuardedPrompt {
        $canary = PromptCanary::generate();
        $variables = self::sanitizeUntrusted($template, $untrusted, $canary);

        // vendor 媒体型 (Image/Document) の生成は窓口のこの 1 箇所に閉じる
        // (PromptWindowScanner の VendorMediaTypeConstruction ルールが呼び出し件数を pin する)。
        // PHP の match は値の厳密比較であり、DTO の型に対する網羅的分岐そのものにはできないため
        // instanceof による型判別を使う。default 節は置かない (union が閉じている前提)。
        $vendorMedia = match (true) {
            $media instanceof ImageAnalysisMediaData => Image::fromRawContent($media->bytes, $media->mime),
            $media instanceof PdfAnalysisMediaData => Document::fromRawContent($media->bytes, $media->mime),
        };

        $basePath = config('prism-prompt.prompts_path', resource_path('prompts'));
        Assert::string($basePath);
        $templatePath = $basePath.'/'.$template.'.yaml';

        // 媒体を載せる無名クラス。窓口ファイルの中だけで宣言・生成される
        // (宣言と生成が同一の PHP 式であることが、生成箇所を 1 件に pin する根拠)。
        // コンストラクタの内側で Prompt::load() と同じ初期化 (templatePath 代入 →
        // loadMetadata()) を行うため、provider/model/system_prompt/client_options/max_tokens
        // の解決ロジックが素の TextPrompt と同じように働く
        // (「外側から作った別インスタンスで TextPrompt を包む」形は metadata が空のままになる
        // バグを生むため採らない)。
        $prompt = new class($templatePath, $variables, $vendorMedia) extends TextPrompt
        {
            /** @param  array<string, UserInput|string>  $variables */
            public function __construct(
                string $templatePath,
                array $variables,
                private readonly Image|Document $media,
            ) {
                $this->templatePath = $templatePath;
                $this->templateVariables = $variables;
                $this->loadMetadata();
            }

            /** @return list<Message> */
            protected function buildConversationMessages(): array
            {
                return [new UserMessage($this->render(), [$this->media])];
            }
        };
        $prompt = $prompt->withMetadata($context->toMetadata());

        return new GuardedPrompt($prompt, $canary, $template);
    }

    /**
     * @param  array<string, string>  $untrusted
     * @return array<string, UserInput|string>
     *
     * @throws UntrustedInputRejectedException
     */
    private static function sanitizeUntrusted(string $template, array $untrusted, PromptCanary $canary): array
    {
        /** @var array<string, UserInput|string> $variables */
        $variables = [];
        foreach ($untrusted as $name => $value) {
            Assert::regex($name, self::VARIABLE_NAME_PATTERN, "変数名が不正です: {$name}");
            Assert::notSame($name, self::CANARY_VARIABLE, '合言葉の変数名は上書きできません');

            $sanitized = UntrustedTextSanitizer::sanitize($value);
            if ($sanitized->removedCharacters > 0) {
                // 中身は載せない (untrusted 文字列をログに流さない)。件数だけを観測する。
                Log::info('untrusted 入力から不可視文字を除去しました', [
                    'prompt' => $template,
                    'variable' => $name,
                    'removed_characters' => $sanitized->removedCharacters,
                ]);
            }
            $variables[$name] = UserInput::from($sanitized->text);
        }
        $variables[self::CANARY_VARIABLE] = $canary->token;

        return $variables;
    }

    /**
     * @param  array<string, string>  $untrusted
     *
     * @throws UntrustedInputRejectedException
     */
    private static function build(string $template, array $untrusted, ?LlmCallContextData $context): GuardedPrompt
    {
        $canary = PromptCanary::generate();
        $variables = self::sanitizeUntrusted($template, $untrusted, $canary);

        $prompt = Prompt::load($template, $variables);
        if ($context !== null) {
            $prompt = $prompt->withMetadata($context->toMetadata());
        }

        return new GuardedPrompt($prompt, $canary, $template);
    }
}
