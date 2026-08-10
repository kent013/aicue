<?php

declare(strict_types=1);

use App\Actions\Fortify\UpdateUserProfileInformation;
use App\Console\Commands\ResetAdminMfaCommand;
use App\Enums\SecurityEventType;
use App\Http\Controllers\Organizations\OrganizationApiKeyController;
use App\Http\Controllers\Organizations\OrganizationMemberController;
use App\Services\Auth\PasswordCredentialService;
use App\Services\Auth\SocialAccountService;
use App\Services\Organization\OrganizationMembershipService;
use Illuminate\Auth\Events\Failed;
use Illuminate\Auth\Events\Login;
use Illuminate\Auth\Events\Logout;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Support\Facades\Event;
use Laravel\Fortify\Events\TwoFactorAuthenticationConfirmed;
use Laravel\Fortify\Events\TwoFactorAuthenticationDisabled;
use Laravel\Passkeys\Events\PasskeyDeleted;
use Laravel\Passkeys\Events\PasskeyRegistered;

/**
 * security_audit_events の**記録経路の網羅性** invariant (T108 S7)。
 *
 * `SecurityEventType` は「監査に残すと決めた事象」の固定集合だが、case を足しても
 * 記録経路を配線し忘れると **enum に存在するのに 1 行も記録されない**幽霊 case になる。
 * 実際 `password_changed` と `email_changed` はその状態で放置されていた。
 *
 * そこで全 case の記録経路を構造化 map で宣言させ、
 *   - enum の全 case と map の key が完全一致 (双方向 = deny-by-default)
 *   - `event` 宣言なら当該イベントに listener が実際に登録されている
 *   - `caller` 宣言なら当該クラスが実在し SecurityEventRecorder を参照している
 *   - すべての case に `covered_by` があり、そのファイルが実在し、
 *     **その中で当該 case を名指ししている** (空疎な登録の禁止)
 *   - `event` / `caller` は**いずれか一方**だけを持つ
 * を機械検証する。
 */

/**
 * SecurityEventType の値 => 記録経路の宣言。
 *
 * `event`  : 購読するイベントクラス (RecordSecurityEvent が listener を張る)
 * `caller` : 直接 SecurityEventRecorder を呼ぶクラス
 * `covered_by` : その event_type が記録されることを担保するテストファイル
 *
 * @return array<string, array{event?: class-string, caller?: class-string, covered_by: string}>
 */
function securityEventRecordingMap(): array
{
    return [
        SecurityEventType::Login->value => [
            'event' => Login::class,
            'covered_by' => 'tests/Feature/Auth/AuthenticationTest.php',
        ],
        SecurityEventType::LoginFailed->value => [
            'event' => Failed::class,
            'covered_by' => 'tests/Feature/Security/SecurityAuditTrailCoverageTest.php',
        ],
        SecurityEventType::Logout->value => [
            'event' => Logout::class,
            'covered_by' => 'tests/Feature/Security/SecurityAuditTrailCoverageTest.php',
        ],
        SecurityEventType::PasswordReset->value => [
            'event' => PasswordReset::class,
            'covered_by' => 'tests/Feature/Security/SecurityAuditTrailCoverageTest.php',
        ],
        // T107 で users.password 確定の単一窓口が PasswordCredentialService に統合された
        // (変更 = PasswordChanged / 初回設定 = PasswordSet)
        SecurityEventType::PasswordChanged->value => [
            'caller' => PasswordCredentialService::class,
            'covered_by' => 'tests/Feature/Auth/PasswordUpdateSessionInvalidationTest.php',
        ],
        SecurityEventType::PasswordSet->value => [
            'caller' => PasswordCredentialService::class,
            'covered_by' => 'tests/Feature/Settings/PasswordSetupTest.php',
        ],
        SecurityEventType::TwoFactorEnabled->value => [
            'event' => TwoFactorAuthenticationConfirmed::class,
            'covered_by' => 'tests/Feature/Security/SecurityAuditTrailCoverageTest.php',
        ],
        SecurityEventType::TwoFactorDisabled->value => [
            'event' => TwoFactorAuthenticationDisabled::class,
            'covered_by' => 'tests/Feature/Security/SecurityAuditTrailCoverageTest.php',
        ],
        SecurityEventType::EmailChanged->value => [
            'caller' => UpdateUserProfileInformation::class,
            'covered_by' => 'tests/Feature/Security/SecurityAuditTrailCoverageTest.php',
        ],
        SecurityEventType::AccountDeleted->value => [
            'caller' => OrganizationMembershipService::class,
            'covered_by' => 'tests/Feature/Auth/AccountDeletionTest.php',
        ],
        SecurityEventType::AccountDeletionRequested->value => [
            'caller' => OrganizationMembershipService::class,
            'covered_by' => 'tests/Feature/Auth/AccountDeletionGraceTest.php',
        ],
        SecurityEventType::AccountDeletionCancelled->value => [
            'caller' => OrganizationMembershipService::class,
            'covered_by' => 'tests/Feature/Auth/AccountDeletionGraceTest.php',
        ],
        SecurityEventType::SocialAccountLinked->value => [
            'caller' => SocialAccountService::class,
            'covered_by' => 'tests/Feature/Security/SecurityAuditTrailCoverageTest.php',
        ],
        SecurityEventType::OwnershipTransferred->value => [
            'caller' => OrganizationMembershipService::class,
            'covered_by' => 'tests/Feature/Organization/OwnershipTransferTest.php',
        ],
        SecurityEventType::ApiKeyIssued->value => [
            'caller' => OrganizationApiKeyController::class,
            'covered_by' => 'tests/Feature/Api/ApiKeyTest.php',
        ],
        SecurityEventType::ApiKeyRevoked->value => [
            'caller' => OrganizationApiKeyController::class,
            'covered_by' => 'tests/Feature/Api/ApiKeyTest.php',
        ],
        SecurityEventType::AdminMfaReset->value => [
            'caller' => ResetAdminMfaCommand::class,
            'covered_by' => 'tests/Feature/Console/ResetAdminMfaCommandTest.php',
        ],
        SecurityEventType::OrgMemberTwoFactorReset->value => [
            'caller' => OrganizationMemberController::class,
            'covered_by' => 'tests/Feature/Organizations/TwoFactorEnforcementTest.php',
        ],
        SecurityEventType::PasskeyRegistered->value => [
            'event' => PasskeyRegistered::class,
            'covered_by' => 'tests/Feature/Auth/PasskeyAuditTrailTest.php',
        ],
        SecurityEventType::PasskeyDeleted->value => [
            'event' => PasskeyDeleted::class,
            'covered_by' => 'tests/Feature/Auth/PasskeyAuditTrailTest.php',
        ],
    ];
}

test('検査1: enum の全 case と記録経路 map の key が完全一致する', function (): void {
    $cases = array_map(
        static fn (SecurityEventType $type): string => $type->value,
        SecurityEventType::cases(),
    );
    $declared = array_keys(securityEventRecordingMap());

    sort($cases);
    sort($declared);

    expect($declared)->toBe($cases,
        'SecurityEventType に case を足したら securityEventRecordingMap() にも記録経路を宣言してください '
        .'(宣言のない case は「enum にあるのに 1 行も記録されない」幽霊 case になります)');
});

test('検査2: event 宣言の case は当該イベントに listener が登録されている', function (): void {
    $violations = [];

    foreach (securityEventRecordingMap() as $value => $entry) {
        $eventClass = $entry['event'] ?? null;
        if ($eventClass === null) {
            continue;
        }
        if (! class_exists($eventClass)) {
            $violations[] = "{$value}: イベントクラス {$eventClass} が実在しない";

            continue;
        }
        if (Event::getRawListeners()[$eventClass] ?? null) {
            continue;
        }
        $violations[] = "{$value}: {$eventClass} に listener が登録されていない "
            .'(RecordSecurityEvent::subscribe で listen していますか?)';
    }

    expect($violations)->toBe([], PHP_EOL.implode(PHP_EOL, $violations));
});

test('検査3: caller 宣言の case は当該クラスが SecurityEventRecorder を参照している', function (): void {
    $violations = [];

    foreach (securityEventRecordingMap() as $value => $entry) {
        $callerClass = $entry['caller'] ?? null;
        if ($callerClass === null) {
            continue;
        }
        if (! class_exists($callerClass)) {
            $violations[] = "{$value}: 呼び出し元クラス {$callerClass} が実在しない";

            continue;
        }

        $file = (new ReflectionClass($callerClass))->getFileName();
        $source = $file === false ? '' : (string) file_get_contents($file);
        if (! str_contains($source, 'SecurityEventRecorder')) {
            $violations[] = "{$value}: {$callerClass} が SecurityEventRecorder を参照していない";
        }
        // enum case を名指ししていること (「recorder を持っているだけ」を通さない)
        $caseName = SecurityEventType::from($value)->name;
        if (! str_contains($source, 'SecurityEventType::'.$caseName)) {
            $violations[] = "{$value}: {$callerClass} が SecurityEventType::{$caseName} を記録していない";
        }
    }

    expect($violations)->toBe([], PHP_EOL.implode(PHP_EOL, $violations));
});

test('検査4: 全 case に実在する covered_by があり、その中で当該 case を名指ししている', function (): void {
    $violations = [];

    foreach (securityEventRecordingMap() as $value => $entry) {
        $path = $entry['covered_by'];
        if (! file_exists(base_path($path))) {
            $violations[] = "{$value}: covered_by のファイル {$path} が実在しない";

            continue;
        }

        $source = (string) file_get_contents(base_path($path));
        $caseName = SecurityEventType::from($value)->name;
        // 生の値 ('passkey_registered') か enum case 参照のどちらかで名指ししていること
        $mentionsValue = str_contains($source, "'".$value."'") || str_contains($source, '"'.$value.'"');
        $mentionsCase = str_contains($source, 'SecurityEventType::'.$caseName);
        if (! $mentionsValue && ! $mentionsCase) {
            $violations[] = "{$value}: {$path} が当該 event_type を名指ししていない "
                .'(空疎な covered_by 登録。実際にその event_type を検証するテストを書いてください)';
        }
    }

    expect($violations)->toBe([], PHP_EOL.implode(PHP_EOL, $violations));
});

test('検査5: 各エントリは event か caller のいずれか一方だけを持つ', function (): void {
    $violations = [];

    foreach (securityEventRecordingMap() as $value => $entry) {
        $hasEvent = array_key_exists('event', $entry);
        $hasCaller = array_key_exists('caller', $entry);

        if ($hasEvent === $hasCaller) {
            $violations[] = "{$value}: event / caller は"
                .($hasEvent ? '両方宣言されている' : 'どちらも宣言されていない');
        }
    }

    expect($violations)->toBe([], PHP_EOL.implode(PHP_EOL, $violations));
});
