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
}
