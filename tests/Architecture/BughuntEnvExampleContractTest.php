<?php

declare(strict_types=1);

/*
 * Architecture invariant: .env.bughunt.local.example が bug-hunt 環境の
 * 「production 同等性の最小セット」を保持すること。
 *
 * SoT = .env.bughunt.local.example 本体 + AGENTS.md §bug-hunt (隔離方針) +
 *       .claude/skills/app-bug-hunt/SKILL.md (走行前提)。example が手元 .env の正本。
 *
 * 最小セット = プロセス間契約・検証忠実度・dev DB 隔離に影響する env:
 *   - APP_ENV=bughunt.local      : 環境判定 (fake_externals / seeder の fail-secure guard の軸)
 *   - APP_NAME                   : 画面のタイトル/ロゴ/フッターに露出。単独ロードされるファイルなので
 *                                  `${APP_NAME}` の自己参照はリテラル露出事故になる (実際に発生した)
 *   - APP_LOCALE=ja              : bug-hunt はユーザー向け文言 (日本語) の検証環境。en のままだと
 *                                  production と異なる文言を検証してしまう
 *   - DB_DATABASE=bug_hunt       : dev DB 隔離の核 (^bug_hunt(_[1-8])?$ のみ許可)
 *   - TESTING_FAKE_EXTERNALS=true: 決済等の外部を fake に落とす (実課金を踏まない)
 *   - ADMIN_MFA_REQUIRED=false   : true だと admin ログイン後 TOTP 強制で探索が詰む
 *
 * 併せて「秘密値を example に焼き込まない」ことも固定する (APP_KEY / CIPHERSWEET_KEY /
 * DB_PASSWORD / BUGHUNT_ADMIN_PASSWORD は空 = 手元で上書き必須)。
 *
 * 注: `${VAR}` 未解決参照の一般則は EnvExampleInvariantTest が本ファイルも含めて検査済み。
 * 本テストは「最小セットの存在と値」の契約に限定する。
 * DB 不使用の静的検査 (既存 Architecture テストと同じ作法)。
 */

/**
 * 最小セット契約の違反一覧を返す純関数 (負のコントロール用に content を受け取る)。
 *
 * @return list<string> 違反一覧 (空 = 合格)
 */
function bughuntEnvExampleViolations(string $content): array
{
    $violations = [];

    /** @var array<string, string> $exactValues */
    $exactValues = [
        'APP_ENV' => 'bughunt.local',
        'APP_LOCALE' => 'ja',
        'DB_DATABASE' => 'bug_hunt',
        'TESTING_FAKE_EXTERNALS' => 'true',
        'ADMIN_MFA_REQUIRED' => 'false',
    ];
    foreach ($exactValues as $key => $expected) {
        $m = [];
        // 行末コメント (`DB_DATABASE=bug_hunt   # 説明`) を許容して値だけを取り出す。
        if (preg_match('/^'.preg_quote($key, '/').'=([^\s#]*)/m', $content, $m) !== 1) {
            $violations[] = "{$key} が定義されていない";

            continue;
        }
        /** @var array{0: string, 1: string} $m */
        if (trim($m[1], "\"'") !== $expected) {
            $violations[] = "{$key} は {$expected} であること (実際: {$m[1]})";
        }
    }

    // APP_NAME は実値リテラル必須 (空 / 自己参照は画面露出事故)。
    $appName = [];
    if (preg_match('/^APP_NAME=(.*)$/m', $content, $appName) !== 1) {
        $violations[] = 'APP_NAME が定義されていない';
    } else {
        /** @var array{0: string, 1: string} $appName */
        $value = trim(trim($appName[1]), "\"'");
        if ($value === '') {
            $violations[] = 'APP_NAME が空 (bug-hunt 環境は単独ロードのため実値リテラルが必要)';
        }
        if (str_contains($value, '${')) {
            $violations[] = 'APP_NAME に ${...} 参照 (単独ロードでは解決されずリテラル露出する)';
        }
    }

    // 重複定義 (後勝ちで意図しない値になる) の禁止。
    foreach (['APP_ENV', 'APP_NAME', 'APP_LOCALE', 'DB_DATABASE'] as $key) {
        $count = preg_match_all('/^'.preg_quote($key, '/').'=/m', $content);
        if ($count > 1) {
            $violations[] = "{$key} が {$count} 回定義されている (重複禁止)";
        }
    }

    // 秘密値は example に焼き込まない (手元で上書き必須)。
    foreach (['APP_KEY', 'CIPHERSWEET_KEY', 'DB_PASSWORD', 'BUGHUNT_ADMIN_PASSWORD'] as $key) {
        $m = [];
        if (preg_match('/^'.preg_quote($key, '/').'=([^\s#]*)/m', $content, $m) !== 1) {
            $violations[] = "{$key} のプレースホルダ行が無い";

            continue;
        }
        /** @var array{0: string, 1: string} $m */
        if (trim($m[1], "\"'") !== '') {
            $violations[] = "{$key} に値が焼き込まれている (example は空で手元上書き必須)";
        }
    }

    return $violations;
}

test('.env.bughunt.local.example が production 同等性の最小セットを満たすこと', function (): void {
    $content = file_get_contents(base_path('.env.bughunt.local.example'));
    expect($content)->toBeString('.env.bughunt.local.example が読めない');
    /** @var string $content */
    expect(bughuntEnvExampleViolations($content))->toBe([]);
});

test('.env.bughunt.local.example の DB_DATABASE が shard script の DB 接頭辞既定と一致すること', function (): void {
    $content = file_get_contents(base_path('.env.bughunt.local.example'));
    expect($content)->toBeString();
    /** @var string $content */
    $env = [];
    expect(preg_match('/^DB_DATABASE=([^\s#]*)/m', $content, $env))->toBe(1);
    /** @var array{0: string, 1: string} $env */
    $script = file_get_contents(base_path('scripts/bug-hunt-shard.sh'));
    expect($script)->toBeString();
    /** @var string $script */
    $prefix = [];
    expect(preg_match('/^BUGHUNT_DB_PREFIX="\$\{BUGHUNT_DB_PREFIX:-([a-z_]+)\}"/m', $script, $prefix))->toBe(1);
    /** @var array{0: string, 1: string} $prefix */

    // 乖離すると直列走行 (shard 0) の DB 名が guard regex (^bug_hunt(_[1-8])?$) を外れて abort する。
    expect(trim($env[1], "\"'"))->toBe($prefix[1]);
});

/*
 * 負のコントロール: 契約が破れた fixture を実際に検出することを確認する (実ファイルは書き換えない)。
 */
test('負のコントロール: 最小セット欠落 / 自己参照 / 秘密値焼き込みを検出する', function (): void {
    $broken = <<<'ENV'
    APP_ENV=bughunt.local
    APP_NAME="${APP_NAME}"
    DB_DATABASE=bug_hunt
    DB_PASSWORD=hunter2
    APP_KEY=base64:AAAA
    CIPHERSWEET_KEY=deadbeef
    BUGHUNT_ADMIN_PASSWORD=
    TESTING_FAKE_EXTERNALS=true
    ADMIN_MFA_REQUIRED=true
    ENV;

    $violations = bughuntEnvExampleViolations($broken);
    $joined = implode("\n", $violations);

    expect($joined)->toContain('APP_LOCALE が定義されていない');          // 最小セット欠落
    expect($joined)->toContain('APP_NAME に ${...} 参照');                 // 自己参照 (リテラル露出事故)
    expect($joined)->toContain('DB_PASSWORD に値が焼き込まれている');       // 秘密値
    expect($joined)->toContain('APP_KEY に値が焼き込まれている');
    expect($joined)->toContain('ADMIN_MFA_REQUIRED は false であること');   // TOTP 詰み
});

test('負のコントロール: 重複定義 (後勝ち事故) を検出する', function (): void {
    $duplicated = <<<'ENV'
    APP_ENV=bughunt.local
    APP_NAME="AI-CUE"
    APP_LOCALE=ja
    APP_LOCALE=en
    DB_DATABASE=bug_hunt
    DB_DATABASE=app_dev
    DB_PASSWORD=
    APP_KEY=
    CIPHERSWEET_KEY=
    BUGHUNT_ADMIN_PASSWORD=
    TESTING_FAKE_EXTERNALS=true
    ADMIN_MFA_REQUIRED=false
    ENV;

    $joined = implode("\n", bughuntEnvExampleViolations($duplicated));
    expect($joined)->toContain('DB_DATABASE が 2 回定義されている');
    // 先勝ち抽出なので APP_LOCALE の値自体は ja に見えるが、重複そのものを違反として検出する。
    expect($joined)->toContain('DB_DATABASE');
});
