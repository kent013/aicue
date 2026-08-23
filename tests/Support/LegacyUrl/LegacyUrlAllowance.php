<?php

declare(strict_types=1);

namespace Tests\Support\LegacyUrl;

use RuntimeException;

/**
 * 旧 URL 検出の許可目録 (deny-by-default。**対象パターンと件数まで完全一致**)。
 *
 * ★形式は **パス + 検出規則 ID + 一致した語 + 区分 + 件数 + 30 文字以上の理由**である。
 *   「どの語を何件許すか」まで固定するので、**同じファイル・同じ件数で別の旧 URL へ
 *   置き換えても通らない**。
 * ★**旧 URL の文字列そのものを目録へ写さない**。語は走査器が断片から組み立てた値
 *   (`LegacyUrlScanner::legacyRoots()` / `captureRoot()` / `removedRouteName()`) から選ぶので、
 *   目録自身が検出対象になる再帰は起きない。
 * ★区分 (`LegacyUrlAllowanceKind`) は**判定に使う**。区分ごとの前提を
 *   `preconditionViolation()` が機械で確かめ、満たさない登録は赤になる
 *   (説明ラベルにしない = 走査器共通規約 (d))。
 * ★**ファイル全体を走査から外さない**。登録した (規則 ID, 語) 以外の検出は、
 *   同じファイルの中でも引き続き違反になる。
 * ★件数は完全一致である (増えても減っても赤)。減ったときも赤にするのは、
 *   許可の理由が消えたのに登録が残る状態を作らないためである。
 */
final class LegacyUrlAllowance
{
    /**
     * オブジェクトストレージの鍵を扱っている印 (区分 `StorageObjectKey` の前提)。
     *
     * ★**2 つのどちらかが同じファイルに現れること**を求める。
     *   本番の鍵は組織 id で始まる接頭辞を持ち、鍵の妥当性を検査する層は鍵の型名を持つ。
     *   どちらも無いファイルは「保存先の鍵である」と名乗れない。
     *
     * @var list<string>
     */
    public const array STORAGE_KEY_MARKERS = ['orgs/', 'StorageKey'];

    /** 撤去を説明する語 (区分 `AbsenceAssertion` の前提)。 */
    public const string REMOVAL_MARKER = '撤去';

    /** インスタンス化しない (目録の置き場)。 */
    private function __construct() {}

    /**
     * 走査器が組み立てた旧パスの根から、末尾が一致する 1 本を選ぶ。
     *
     * ★目録に旧 URL の文字列を書かないための入口である (綴りを写すと目録自身が検出対象になる)。
     *   一致が 1 本でなければ例外にする (綴り間違いを黙って許さない)。
     */
    private static function legacyRootEndingWith(string $suffix): string
    {
        $matched = array_values(array_filter(
            LegacyUrlScanner::legacyRoots(),
            static fn (string $root): bool => str_ends_with($root, $suffix),
        ));

        if (count($matched) !== 1) {
            throw new RuntimeException("旧パスの根を一意に選べません: {$suffix}");
        }

        return $matched[0];
    }

    /**
     * 登録一覧。
     *
     * @return list<array{path: string, rule: string, matched: string, context: string, count: int, kind: LegacyUrlAllowanceKind, consumer: ?string, symbol: ?string, reason: string}>
     */
    public static function entries(): array
    {
        return [
            [
                'path' => '.claude/skills/app-bug-hunt/coverage/correlate.py',
                'rule' => LegacyUrlScanner::RULE_SCRIPT_LITERAL,
                'matched' => LegacyUrlScanner::captureRoot(),
                'context' => 'call:rfind',
                'count' => 1,
                'kind' => LegacyUrlAllowanceKind::FilesystemPath,
                'consumer' => null,
                'symbol' => null,
                'reason' => 'スタックトレースの絶対パスをリポジトリ相対へ畳む処理が探す区切りであり、指しているのはアプリ実装のディレクトリで画面の URL ではない',
            ],
            [
                'path' => '.claude/skills/app-bug-hunt/coverage/test_out_of_scope.py',
                'rule' => LegacyUrlScanner::RULE_SCRIPT_LITERAL,
                'matched' => LegacyUrlScanner::captureRoot(),
                'context' => 'call:in',
                'count' => 1,
                'kind' => LegacyUrlAllowanceKind::FilesystemPath,
                'consumer' => null,
                'symbol' => null,
                'reason' => '対象外判定の見本に置いた管理画面の実装ディレクトリのパスであり、ファイルシステム上の位置を指す文字列で画面の URL ではない',
            ],
            [
                'path' => 'app/Support/Seo/CrawlPolicy.php',
                'rule' => LegacyUrlScanner::RULE_PHP_LITERAL,
                'matched' => LegacyUrlScanner::captureRoot(),
                'context' => 'expr',
                'count' => 1,
                'kind' => LegacyUrlAllowanceKind::CanonicalCaptureEntry,
                'consumer' => null,
                'symbol' => null,
                'reason' => 'クローラに拒否させる経路の宣言であり、正規の分岐入口そのものを名指ししている (入口は認証必須なので索引させない)',
            ],
            [
                'path' => 'doc/08_システムアーキテクチャ設計.md',
                'rule' => LegacyUrlScanner::RULE_MARKDOWN_TEXT,
                'matched' => self::legacyRootEndingWith('ojects'),
                'context' => 'text',
                'count' => 1,
                'kind' => LegacyUrlAllowanceKind::StorageObjectKey,
                'consumer' => null,
                'symbol' => null,
                'reason' => 'オブジェクトストレージのキー prefix の書式であり、画面の URL ではない。鍵は組織 id で始まる別の体系で URL の組織セグメントとは無関係である',
            ],
            [
                'path' => 'doc/09_詳細実装設計.md',
                'rule' => LegacyUrlScanner::RULE_MARKDOWN_TEXT,
                'matched' => self::legacyRootEndingWith('ojects'),
                'context' => 'text',
                'count' => 1,
                'kind' => LegacyUrlAllowanceKind::StorageObjectKey,
                'consumer' => null,
                'symbol' => null,
                'reason' => 'テイク動画を置くオブジェクトストレージの鍵の書式であり、画面の URL ではない。鍵は組織 id で始まる別の体系である',
            ],
            [
                'path' => 'doc/10_実装仕様.md',
                'rule' => LegacyUrlScanner::RULE_MARKDOWN_TEXT,
                'matched' => LegacyUrlScanner::captureRoot(),
                'context' => 'text',
                'count' => 1,
                'kind' => LegacyUrlAllowanceKind::CanonicalCaptureEntry,
                'consumer' => null,
                'symbol' => null,
                'reason' => '撮影 PWA の prefix が「PWA 専用」を意味しないことの説明で、正規の分岐入口そのものを指している',
            ],
            [
                'path' => 'docs/architecture.md',
                'rule' => LegacyUrlScanner::RULE_MARKDOWN_TEXT,
                'matched' => LegacyUrlScanner::captureRoot(),
                'context' => 'text',
                'count' => 3,
                'kind' => LegacyUrlAllowanceKind::CanonicalCaptureEntry,
                'consumer' => null,
                'symbol' => null,
                'reason' => 'テイク API の prefix の由来と、組織文脈を持たない入口 2 本の説明であり、いずれも正規の分岐入口そのものを指している',
            ],
            [
                'path' => 'docs/architecture.md',
                'rule' => LegacyUrlScanner::RULE_REMOVED_ROUTE_NAME,
                'matched' => LegacyUrlScanner::removedRouteName(),
                'context' => 'text',
                'count' => 1,
                'kind' => LegacyUrlAllowanceKind::AbsenceAssertion,
                'consumer' => null,
                'symbol' => null,
                'reason' => '撤去した切替 endpoint の route 名を「撤去済みである」と説明する 1 行であり、撤去の記録としてこの名前が書けないと何を撤去したのかが文書から読めなくなる',
            ],
            [
                'path' => 'docs/supported-browsers.md',
                'rule' => LegacyUrlScanner::RULE_MARKDOWN_TEXT,
                'matched' => LegacyUrlScanner::captureRoot(),
                'context' => 'text',
                'count' => 1,
                'kind' => LegacyUrlAllowanceKind::CanonicalCaptureEntry,
                'consumer' => null,
                'symbol' => null,
                'reason' => 'PWA の manifest が持つ start_url の値の説明であり、正規の分岐入口そのものを指している',
            ],
            [
                'path' => 'public/manifest.webmanifest',
                'rule' => LegacyUrlScanner::RULE_DATA_TEXT,
                'matched' => LegacyUrlScanner::captureRoot(),
                'context' => 'key:start_url',
                'count' => 1,
                'kind' => LegacyUrlAllowanceKind::CanonicalCaptureEntry,
                'consumer' => null,
                'symbol' => null,
                'reason' => 'ホーム画面追加の start_url であり、正規の分岐入口そのものである (組織を持たない入口であることが仕様)',
            ],
            [
                'path' => 'resources/js/types/dashboard.ts',
                'rule' => LegacyUrlScanner::RULE_SCRIPT_LITERAL,
                'matched' => self::legacyRootEndingWith('illing'),
                'context' => 'expr',
                'count' => 1,
                'kind' => LegacyUrlAllowanceKind::OrganizationRelativePath,
                'consumer' => 'resources/js/pages/Dashboard.svelte',
                'symbol' => 'BILLING_CALLOUTS',
                'reason' => '課金 callout の CTA を持つ静的な表であり、画面から識別名を受け取れない。値は組織相対パスで利用側が currentOrgUrl() で組織 URL へ写す (tests/js/pages/Dashboard.test.ts が CTA の href を組織 URL で固定している)',
            ],
            [
                'path' => 'resources/js/types/dashboard.ts',
                'rule' => LegacyUrlScanner::RULE_SCRIPT_LITERAL,
                'matched' => self::legacyRootEndingWith('arding'),
                'context' => 'expr',
                'count' => 2,
                'kind' => LegacyUrlAllowanceKind::OrganizationRelativePath,
                'consumer' => 'resources/js/pages/Dashboard.svelte',
                'symbol' => 'BILLING_CALLOUTS',
                'reason' => '課金 callout の CTA を持つ静的な表であり、画面から識別名を受け取れない。値は組織相対パスで利用側が currentOrgUrl() で組織 URL へ写す (tests/js/pages/Dashboard.test.ts が CTA の href を組織 URL で固定している)',
            ],
            [
                'path' => 'resources/views/app.blade.php',
                'rule' => LegacyUrlScanner::RULE_BLADE_TEXT,
                'matched' => LegacyUrlScanner::captureRoot(),
                'context' => 'key:start_url',
                'count' => 1,
                'kind' => LegacyUrlAllowanceKind::CanonicalCaptureEntry,
                'consumer' => null,
                'symbol' => null,
                'reason' => 'PWA 専用 manifest を出し分ける条件の説明コメントであり、正規の分岐入口そのものを指している',
            ],
            [
                'path' => 'tests/Architecture/ExternalClientTimeoutInventoryTest.php',
                'rule' => LegacyUrlScanner::RULE_PHP_LITERAL,
                'matched' => LegacyUrlScanner::captureRoot(),
                'context' => 'call:phpFiles',
                'count' => 1,
                'kind' => LegacyUrlAllowanceKind::FilesystemPath,
                'consumer' => null,
                'symbol' => null,
                'reason' => '到達境界の走査根をリポジトリルートからの相対ディレクトリとして組み立てている箇所であり、URL ではなくファイルシステムのパスである',
            ],
            [
                'path' => 'tests/Architecture/ExternalSeamInventoryTest.php',
                'rule' => LegacyUrlScanner::RULE_PHP_LITERAL,
                'matched' => LegacyUrlScanner::captureRoot(),
                'context' => 'call:scanDirectory',
                'count' => 1,
                'kind' => LegacyUrlAllowanceKind::FilesystemPath,
                'consumer' => null,
                'symbol' => null,
                'reason' => '外部到達点の走査根をリポジトリルートからの相対ディレクトリとして組み立てている箇所であり、URL ではなくファイルシステムのパスである',
            ],
            [
                'path' => 'tests/Architecture/FlashNotificationRelayDriftTest.php',
                'rule' => LegacyUrlScanner::RULE_PHP_LITERAL,
                'matched' => LegacyUrlScanner::captureRoot(),
                'context' => 'call:RecursiveDirectoryIterator',
                'count' => 1,
                'kind' => LegacyUrlAllowanceKind::FilesystemPath,
                'consumer' => null,
                'symbol' => null,
                'reason' => '通知中継の走査根をリポジトリルートからの相対ディレクトリとして組み立てている箇所であり、URL ではなくファイルシステムのパスである',
            ],
            [
                'path' => 'tests/Architecture/InvitationResolutionInventoryTest.php',
                'rule' => LegacyUrlScanner::RULE_PHP_LITERAL,
                'matched' => LegacyUrlScanner::captureRoot(),
                'context' => 'expr',
                'count' => 1,
                'kind' => LegacyUrlAllowanceKind::FilesystemPath,
                'consumer' => null,
                'symbol' => null,
                'reason' => '招待解決の走査根をリポジトリルートからの相対ディレクトリとして組み立てている箇所であり、URL ではなくファイルシステムのパスである',
            ],
            [
                'path' => 'tests/Architecture/LlmDefenseConfigGateTest.php',
                'rule' => LegacyUrlScanner::RULE_PHP_LITERAL,
                'matched' => LegacyUrlScanner::captureRoot(),
                'context' => 'expr',
                'count' => 1,
                'kind' => LegacyUrlAllowanceKind::FilesystemPath,
                'consumer' => null,
                'symbol' => null,
                'reason' => 'LLM 防御設定の走査根をリポジトリルートからの相対ディレクトリとして組み立てている箇所であり、URL ではなくファイルシステムのパスである',
            ],
            [
                'path' => 'tests/Architecture/MembershipWriteLockInventoryTest.php',
                'rule' => LegacyUrlScanner::RULE_PHP_LITERAL,
                'matched' => LegacyUrlScanner::captureRoot(),
                'context' => 'expr',
                'count' => 1,
                'kind' => LegacyUrlAllowanceKind::FilesystemPath,
                'consumer' => null,
                'symbol' => null,
                'reason' => '所属書き込みの走査根をリポジトリルートからの相対ディレクトリとして組み立てている箇所であり、URL ではなくファイルシステムのパスである',
            ],
            [
                'path' => 'tests/Architecture/PostBootRouteMutationInventoryTest.php',
                'rule' => LegacyUrlScanner::RULE_PHP_LITERAL,
                'matched' => LegacyUrlScanner::captureRoot(),
                'context' => 'call:RecursiveDirectoryIterator',
                'count' => 1,
                'kind' => LegacyUrlAllowanceKind::FilesystemPath,
                'consumer' => null,
                'symbol' => null,
                'reason' => '後付け経路の走査根をリポジトリルートからの相対ディレクトリとして組み立てている箇所であり、URL ではなくファイルシステムのパスである',
            ],
            [
                'path' => 'tests/Architecture/PromptDefenseWindowGateTest.php',
                'rule' => LegacyUrlScanner::RULE_PHP_LITERAL,
                'matched' => LegacyUrlScanner::captureRoot(),
                'context' => 'expr',
                'count' => 3,
                'kind' => LegacyUrlAllowanceKind::FilesystemPath,
                'consumer' => null,
                'symbol' => null,
                'reason' => 'prompt factory の走査根をリポジトリルートからの相対ディレクトリとして組み立てている箇所であり、URL ではなくファイルシステムのパスである',
            ],
            [
                'path' => 'tests/Browser/CaptureAppBoundaryTest.php',
                'rule' => LegacyUrlScanner::RULE_PHP_LITERAL,
                'matched' => LegacyUrlScanner::captureRoot(),
                'context' => 'call:test',
                'count' => 3,
                'kind' => LegacyUrlAllowanceKind::CanonicalCaptureEntry,
                'consumer' => null,
                'symbol' => null,
                'reason' => '撮影 PWA が入口の配下から自動で離脱しないことを実ブラウザで確かめる検査であり、正規の分岐入口そのものを扱う',
            ],
            [
                'path' => 'tests/Browser/CaptureAppBoundaryTest.php',
                'rule' => LegacyUrlScanner::RULE_PHP_LITERAL,
                'matched' => LegacyUrlScanner::captureRoot(),
                'context' => 'call:toBe',
                'count' => 2,
                'kind' => LegacyUrlAllowanceKind::CanonicalCaptureEntry,
                'consumer' => null,
                'symbol' => null,
                'reason' => '撮影 PWA が入口の配下から自動で離脱しないことを実ブラウザで確かめる検査であり、正規の分岐入口そのものを扱う',
            ],
            [
                'path' => 'tests/Browser/CaptureAppBoundaryTest.php',
                'rule' => LegacyUrlScanner::RULE_PHP_LITERAL,
                'matched' => LegacyUrlScanner::captureRoot(),
                'context' => 'call:toBeTrue',
                'count' => 2,
                'kind' => LegacyUrlAllowanceKind::CanonicalCaptureEntry,
                'consumer' => null,
                'symbol' => null,
                'reason' => '撮影 PWA が入口の配下から自動で離脱しないことを実ブラウザで確かめる検査であり、正規の分岐入口そのものを扱う',
            ],
            [
                'path' => 'tests/Feature/Billing/GateInversionF07RegressionTest.php',
                'rule' => LegacyUrlScanner::RULE_PHP_LITERAL,
                'matched' => LegacyUrlScanner::captureRoot(),
                'context' => 'call:test',
                'count' => 1,
                'kind' => LegacyUrlAllowanceKind::CanonicalCaptureEntry,
                'consumer' => null,
                'symbol' => null,
                'reason' => '未契約の組織でも撮影の入口へ到達できることの回帰であり、正規の分岐入口そのものを扱う',
            ],
            [
                'path' => 'tests/Feature/Billing/RequireActiveSubscriptionMiddlewareTest.php',
                'rule' => LegacyUrlScanner::RULE_PHP_LITERAL,
                'matched' => LegacyUrlScanner::captureRoot(),
                'context' => 'call:test',
                'count' => 1,
                'kind' => LegacyUrlAllowanceKind::CanonicalCaptureEntry,
                'consumer' => null,
                'symbol' => null,
                'reason' => '課金ゲートが撮影の入口を遮らないことの検査であり、正規の分岐入口そのものを扱う',
            ],
            [
                'path' => 'tests/Feature/Capture/CaptureManualBrowsingTest.php',
                'rule' => LegacyUrlScanner::RULE_PHP_LITERAL,
                'matched' => LegacyUrlScanner::captureRoot(),
                'context' => 'call:test',
                'count' => 2,
                'kind' => LegacyUrlAllowanceKind::CanonicalCaptureEntry,
                'consumer' => null,
                'symbol' => null,
                'reason' => '撮影 PWA の一覧へ入る導線の検査であり、正規の分岐入口そのものを扱う',
            ],
            [
                'path' => 'tests/Feature/Capture/CapturePwaScopeTest.php',
                'rule' => LegacyUrlScanner::RULE_PHP_LITERAL,
                'matched' => LegacyUrlScanner::captureRoot(),
                'context' => 'call:test',
                'count' => 1,
                'kind' => LegacyUrlAllowanceKind::CanonicalCaptureEntry,
                'consumer' => null,
                'symbol' => null,
                'reason' => 'PWA の scope と start_url の契約を固定する検査であり、正規の分岐入口そのものを名指しする',
            ],
            [
                'path' => 'tests/Feature/Capture/CapturePwaScopeTest.php',
                'rule' => LegacyUrlScanner::RULE_PHP_LITERAL,
                'matched' => LegacyUrlScanner::captureRoot(),
                'context' => 'call:toBe',
                'count' => 2,
                'kind' => LegacyUrlAllowanceKind::CanonicalCaptureEntry,
                'consumer' => null,
                'symbol' => null,
                'reason' => 'PWA の scope と start_url の契約を固定する検査であり、正規の分岐入口そのものを名指しする',
            ],
            [
                'path' => 'tests/Feature/Organization/OrganizationEntryTest.php',
                'rule' => LegacyUrlScanner::RULE_PHP_LITERAL,
                'matched' => LegacyUrlScanner::captureRoot(),
                'context' => 'call:get',
                'count' => 4,
                'kind' => LegacyUrlAllowanceKind::CanonicalCaptureEntry,
                'consumer' => null,
                'symbol' => null,
                'reason' => '組織文脈を持たない入口の分岐 (所属 0 / 1 / 複数) を固定する検査であり、正規の分岐入口そのものを叩く',
            ],
            [
                'path' => 'tests/Feature/Organization/OrganizationEntryTest.php',
                'rule' => LegacyUrlScanner::RULE_PHP_LITERAL,
                'matched' => LegacyUrlScanner::captureRoot(),
                'context' => 'call:test',
                'count' => 3,
                'kind' => LegacyUrlAllowanceKind::CanonicalCaptureEntry,
                'consumer' => null,
                'symbol' => null,
                'reason' => '組織文脈を持たない入口の分岐 (所属 0 / 1 / 複数) を固定する検査であり、正規の分岐入口そのものを叩く',
            ],
            [
                'path' => 'tests/Feature/Organizations/TwoFactorEnforcementTest.php',
                'rule' => LegacyUrlScanner::RULE_PHP_LITERAL,
                'matched' => self::legacyRootEndingWith('illing'),
                'context' => 'call:with',
                'count' => 1,
                'kind' => LegacyUrlAllowanceKind::OrganizationRelativePath,
                'consumer' => 'tests/Feature/Organizations/TwoFactorEnforcementTest.php',
                'symbol' => '{$suffix}',
                'reason' => 'データセットが渡すのは組織 URL の後ろに継ぐ suffix であり、同じテストの中で組織 URL と連結してから要求している (連結後の要求先は同テストが behavioral に固定する)',
            ],
            [
                'path' => 'tests/Feature/Organizations/TwoFactorEnforcementTest.php',
                'rule' => LegacyUrlScanner::RULE_PHP_LITERAL,
                'matched' => self::legacyRootEndingWith('hboard'),
                'context' => 'call:with',
                'count' => 1,
                'kind' => LegacyUrlAllowanceKind::OrganizationRelativePath,
                'consumer' => 'tests/Feature/Organizations/TwoFactorEnforcementTest.php',
                'symbol' => '{$suffix}',
                'reason' => 'データセットが渡すのは組織 URL の後ろに継ぐ suffix であり、同じテストの中で組織 URL と連結してから要求している (連結後の要求先は同テストが behavioral に固定する)',
            ],
            [
                'path' => 'tests/Feature/Organizations/TwoFactorEnforcementTest.php',
                'rule' => LegacyUrlScanner::RULE_PHP_LITERAL,
                'matched' => self::legacyRootEndingWith('ojects'),
                'context' => 'call:with',
                'count' => 1,
                'kind' => LegacyUrlAllowanceKind::OrganizationRelativePath,
                'consumer' => 'tests/Feature/Organizations/TwoFactorEnforcementTest.php',
                'symbol' => '{$suffix}',
                'reason' => 'データセットが渡すのは組織 URL の後ろに継ぐ suffix であり、同じテストの中で組織 URL と連結してから要求している (連結後の要求先は同テストが behavioral に固定する)',
            ],
            [
                'path' => 'tests/Support/ExternalSeam/ExternalSeamInventory.php',
                'rule' => LegacyUrlScanner::RULE_PHP_LITERAL,
                'matched' => LegacyUrlScanner::captureRoot(),
                'context' => 'call:phpFiles',
                'count' => 1,
                'kind' => LegacyUrlAllowanceKind::FilesystemPath,
                'consumer' => null,
                'symbol' => null,
                'reason' => '外部到達点の目録が持つ走査根の相対ディレクトリであり、URL ではなくファイルシステムのパスである',
            ],
            [
                'path' => 'tests/Unit/Services/Storage/FakeStorageKeyTest.php',
                'rule' => LegacyUrlScanner::RULE_PHP_LITERAL,
                'matched' => self::legacyRootEndingWith('ojects'),
                'context' => 'call:with',
                'count' => 1,
                'kind' => LegacyUrlAllowanceKind::StorageObjectKey,
                'consumer' => null,
                'symbol' => null,
                'reason' => 'オブジェクトストレージの鍵 (保存先のパス) を組み立てる期待値であり、画面の URL ではないので組織セグメントを持たない',
            ],
        ];
    }

    /**
     * 許可の件数表 ("path\0rule\0matched" => count)。**キーの重複は例外**にする。
     *
     * @return array<string, int>
     */
    public static function counts(): array
    {
        $counts = [];
        foreach (self::entries() as $entry) {
            $key = self::keyOf($entry['path'], $entry['rule'], $entry['matched'], $entry['context']);
            if (array_key_exists($key, $counts)) {
                throw new RuntimeException("許可目録のキーが重複しています: {$entry['path']} / {$entry['rule']}");
            }
            $counts[$key] = $entry['count'];
        }

        return $counts;
    }

    /** 目録のキー (パス + 規則 ID + 一致した語 + 構文文脈)。 */
    public static function keyOf(string $path, string $rule, string $matched, string $context): string
    {
        return $path."\0".$rule."\0".$matched."\0".$context;
    }

    /**
     * 区分ごとの前提が満たされていないときの理由 (満たしていれば null)。
     *
     * ★ここが `kind` を**判定に使う**唯一の場所である。前提を持たない区分は作らない。
     *
     * @param  array{path: string, rule: string, matched: string, context: string, count: int, kind: LegacyUrlAllowanceKind, consumer: ?string, symbol: ?string, reason: string}  $entry
     * @param  string  $captureEntryUri  route 表の `capture.entry` の URI (先頭スラッシュつき)
     * @param  list<string>  $observedPaths  この登録が許した出現の path 全体
     */
    public static function preconditionViolation(
        array $entry,
        string $repositoryRoot,
        string $captureEntryUri,
        array $observedPaths,
    ): ?string {
        $contents = @file_get_contents($repositoryRoot.'/'.$entry['path']);
        if ($contents === false) {
            return "登録したパスが読めません: {$entry['path']}";
        }
        if ($observedPaths === []) {
            return '許可した出現が 1 件も見つかりません (登録が実在しない)';
        }

        return match ($entry['kind']) {
            // ★**出現ごとの path 全体**が正規の分岐入口そのものであること。
            //   `/app` を `/app/projects/1` へ置き換えると path が変わって落ちる。
            LegacyUrlAllowanceKind::CanonicalCaptureEntry => self::allPathsAre($observedPaths, $captureEntryUri)
                ?? ($captureEntryUri === LegacyUrlScanner::captureRoot()
                    ? null
                    : "route 表の入口の URI が撮影 PWA の根と一致しません: {$captureEntryUri}"),
            // ★**出現ごとの path 全体**が実在するディレクトリであること。
            LegacyUrlAllowanceKind::FilesystemPath => self::allPathsAreDirectories($observedPaths, $repositoryRoot),
            LegacyUrlAllowanceKind::StorageObjectKey => self::containsAny($contents, self::STORAGE_KEY_MARKERS)
                ? null
                : '保存先の鍵を扱っている印が同じファイルに現れません',
            LegacyUrlAllowanceKind::AbsenceAssertion => str_contains($contents, self::REMOVAL_MARKER)
                ? null
                : '撤去を説明する語が同じファイルに現れません',
            LegacyUrlAllowanceKind::OrganizationRelativePath => self::consumerViolation($entry, $repositoryRoot),
        };
    }

    /**
     * すべての出現の path が期待値と一致するか (違えば理由)。
     *
     * @param  list<string>  $observedPaths
     */
    private static function allPathsAre(array $observedPaths, string $expected): ?string
    {
        foreach ($observedPaths as $path) {
            if ($path !== $expected) {
                return "正規の分岐入口そのものではない出現があります: {$path}";
            }
        }

        return null;
    }

    /**
     * すべての出現の path が実在するディレクトリか (違えば理由)。
     *
     * @param  list<string>  $observedPaths
     */
    private static function allPathsAreDirectories(array $observedPaths, string $repositoryRoot): ?string
    {
        foreach ($observedPaths as $path) {
            if (! is_dir($repositoryRoot.'/'.ltrim($path, '/'))) {
                return "実在するディレクトリではありません: {$path}";
            }
        }

        return null;
    }

    /**
     * 印のどれかが本文に現れるか。
     *
     * @param  list<string>  $markers
     */
    private static function containsAny(string $contents, array $markers): bool
    {
        foreach ($markers as $marker) {
            if (str_contains($contents, $marker)) {
                return true;
            }
        }

        return false;
    }

    /**
     * 組織相対パスの登録が名指しした**利用側**の検査。
     *
     * @param  array{path: string, rule: string, matched: string, count: int, kind: LegacyUrlAllowanceKind, consumer: ?string, reason: string}  $entry
     */
    private static function consumerViolation(array $entry, string $repositoryRoot): ?string
    {
        $consumer = $entry['consumer'];
        $symbol = $entry['symbol'];
        if ($consumer === null || $symbol === null) {
            return '組織相対パスの登録は利用側のファイルと、そこで値を受ける記号を名指しすること';
        }

        $source = @file_get_contents($repositoryRoot.'/'.$consumer);
        if ($source === false) {
            return "利用側のファイルが読めません: {$consumer}";
        }

        // 利用側が「組織 URL を組み立てている」ことを確かめる。
        // module の取り込み (script) か、組織セグメントつきの組み立て (PHP テスト) のどちらか。
        $buildsOrganizationUrl = str_contains($source, LegacyUrlScanner::ORGANIZATION_URL_MODULE)
            || str_contains($source, '/'.LegacyUrlScanner::organizationSegment().'/');
        if (! $buildsOrganizationUrl) {
            return "利用側が組織 URL を組み立てていません: {$consumer}";
        }

        // ★登録した値を受ける記号が利用側に現れること。
        //   **これはデータフローの証明ではない** (同じファイルにあることまでしか言えない)。
        //   値が本当にその builder へ渡ることは利用側の component テスト / Feature テストが担う。
        return str_contains($source, $symbol)
            ? null
            : "利用側に登録した記号が現れません: {$consumer} / {$symbol}";
    }
}
