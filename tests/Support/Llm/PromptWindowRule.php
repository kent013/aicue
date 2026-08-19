<?php

declare(strict_types=1);

namespace Tests\Support\Llm;

/** 窓口 gate が数える site の種別 (何として現れたか)。 */
enum PromptWindowRule: string
{
    /** vendor prompt の読み込み (`Prompt::load()` / `TextPrompt::load()` / `EmbeddingPrompt::load()`)。 */
    case VendorPromptLoad = 'vendor_prompt_load';

    /** 実行単位の構築 (`new GuardedPrompt(...)`)。 */
    case GuardedPromptConstruction = 'guarded_prompt_construction';

    /** 帰属つきの窓口呼び出し (`PromptDefense::load()`)。 */
    case WindowLoad = 'window_load';

    /** 帰属なしの窓口呼び出し (`PromptDefense::loadUnattributed()`)。 */
    case WindowLoadUnattributed = 'window_load_unattributed';

    /** 窓口の内部部品への参照 (`UserInput` / `UntrustedTextSanitizer` / `PromptCanary`)。 */
    case InternalPartReference = 'internal_part_reference';

    /*
     * 以下 5 つは画像・スキャン SOP の OCR 対応 (媒体添付) 施策で追加した。
     */

    /** 媒体添付つきの窓口呼び出し (`PromptDefense::loadWithMedia()`)。 */
    case WindowLoadWithMedia = 'window_load_with_media';

    /**
     * vendor 媒体型 (`Prism\Prism\ValueObjects\Media\Image` / `Media\Document`) の構築。
     * `new Image(...)` / `new Document(...)` の直接構築と、`Image::` / `Document::` への
     * あらゆる static メソッド呼び出しの両方を含む (`Media` に構築以外の static メソッドは
     * 存在しないため、メソッド名を列挙せずに母集団を表せる)。
     */
    case VendorMediaTypeConstruction = 'vendor_media_type_construction';

    /**
     * vendor prompt 型 (`Kent013\PrismPrompt\Prompt` / `TextPrompt`) を継承する `extends` 宣言
     * (無名クラス・記名クラスの両方)。
     */
    case MediaPromptExtendsDeclaration = 'media_prompt_extends_declaration';

    /**
     * vendor 媒体型 (`Image` / `Document` / `Media`) を継承する `extends` 宣言。
     * 許可箇所は 0 件 (subclass 経由の未検証構築点を作らせない)。
     */
    case VendorMediaTypeSubclassDeclaration = 'vendor_media_type_subclass_declaration';

    /**
     * 媒体 DTO の named constructor (`ImageAnalysisMediaData::fromValidated()` /
     * `PdfAnalysisMediaData::fromValidated()`) の呼び出し。
     */
    case MediaDataNamedConstructorCall = 'media_data_named_constructor_call';
}
