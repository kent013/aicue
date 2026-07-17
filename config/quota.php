<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Quota (多次元上限) の既定 map
|--------------------------------------------------------------------------
|
| plan_code → limits のプラン既定値。組織別の上書きは organization_quotas.limits
| (key 単位で上書き)。判定は QuotaService 経由のみ (docs 07 ガイド §4)。
|
| 規約: コードにプラン名で分岐を書かない。「プロジェクトは N 個まで」等の能力は
| ここの「値」で表現し、アプリは key (max_*) だけを参照する。
| limits に無い key は無制限扱い。
|
| 規約: limits キーを追加するときは App\Enums\QuotaKey にも必ず case を追加する
| (QuotaService::check は QuotaKey enum 経由でのみ参照する。
|  tests/Architecture/QuotaKeyConfigInvariantTest が集合整合を CI で固定する)。
|
*/

return [

    /*
    | plan_code が未設定 (未契約) の組織に適用するプラン。
    */
    'fallback_plan' => 'free',

    /*
    | plan_code → limits。プラン追加時は PlanSeeder と合わせてここに limits を定義する。
    */
    'plans' => [
        'free' => [
            'max_projects' => 1,
            'max_members' => 3,
            'max_storage_bytes' => 1 * 1024 * 1024 * 1024,      // 1 GiB (初期値。プラン設計で調整可能)
        ],
        'personal' => [
            'max_projects' => 1,
            'max_members' => 3,                                 // PersonalPlanService::MAX_MEMBERS と一致させる
            'max_storage_bytes' => 1 * 1024 * 1024 * 1024,      // 1 GiB (free の後継 = 実効 limits 不変)
        ],
        'starter' => [
            'max_projects' => 1,
            'max_members' => 3,
            'max_storage_bytes' => 1 * 1024 * 1024 * 1024,      // 1 GiB (personal と同能力。差は基本料のみ)
        ],
        'standard' => [
            'max_projects' => 10,
            'max_members' => 10,
            'max_storage_bytes' => 50 * 1024 * 1024 * 1024,     // 50 GiB (初期値。プラン設計で調整可能)
        ],
    ],

];
