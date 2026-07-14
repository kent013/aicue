<?php

declare(strict_types=1);

// シナリオ導入/総括カットの定型文面 (DB の cut コンテンツ。プロンプトではないため resources/prompts 対象外)。
// :title は VideoManual->title を truncate した作業名。:points は決定的に抽出した要点再掲。
return [
    'bookend' => [
        'intro' => [
            'scene' => '作業全体の俯瞰（導入）',
            'narration' => 'この動画では「:title」の手順と注意点を示します。',
            'subtitle_primary' => ':title',
            'subtitle_secondary' => 'この動画では「:title」の手順と注意点を確認します。',
        ],
        'summary' => [
            'scene' => '作業全体の俯瞰（総括）',
            'narration' => '以上で「:title」は完了です。要点を振り返ります。',
            'subtitle_primary' => '要点の再確認',
            // 要点再掲あり
            'subtitle_secondary_recap' => '要点の再確認：:points',
            // 再掲元が無い場合のフォールバック (締めカット)
            'subtitle_secondary_fallback' => '以上で「:title」の作業は完了です。安全に留意して作業しましょう。',
        ],
    ],
];
