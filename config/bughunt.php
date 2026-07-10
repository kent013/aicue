<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| bug-hunt (LLM 探索的バグハント) 専用環境の動作フラグ
|--------------------------------------------------------------------------
|
| ここの値は APP_ENV=bughunt.local の bug-hunt 走行でのみ意味を持つ。
| 本番・開発・testing では gate (env-default-false + function_exists) により
| 一切参照されない (完全 no-op)。
|
| - pcov.*: Stage3 (実装到達カバレッジ) 収集の env 入口。BughuntCoverageMiddleware が
|   参照する。enabled は function_exists('\pcov\start') と AND され、pcov 未導入の
|   本環境/CI/本番では常に no-op になる。run/shard は出力ファイル名に使う。
|   scripts/bug-hunt-shard.sh provision --coverage が BUGHUNT_PCOV* を serve に渡す。
|
*/

return [
    'pcov' => [
        'enabled' => (bool) env('BUGHUNT_PCOV', false),
        'run' => env('BUGHUNT_PCOV_RUN'),
        'shard' => env('BUGHUNT_PCOV_SHARD'),
    ],
];
