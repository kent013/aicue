<?php

declare(strict_types=1);

use App\Models\EnterpriseIdentity;
use App\Models\OrganizationOidcConnection;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/*
 * 身元表の隔離 (A2)。
 *
 * ★**スキーマの読み取りだけ**を行う (migrate:fresh 等の破壊操作を伴わない = 禁止事項 3)。
 */

test('申告メールの列を含む索引が 1 本も無い (メールで引ける経路を作らない)', function (): void {
    /** @var list<object{indexdef: string}> $indexes */
    $indexes = DB::select("SELECT indexdef FROM pg_indexes WHERE tablename = 'enterprise_identities'");

    $withClaimedEmail = array_values(array_filter(
        $indexes,
        static fn (object $index): bool => str_contains($index->indexdef, 'claimed_email_encrypted'),
    ));

    expect($withClaimedEmail)->toBe([]);
});

test('申告メールの blind index を作らない (configureCipherSweet が addBlindIndex を呼ばない)', function (): void {
    $identity = EnterpriseIdentity::factory()->create();

    $blindIndexes = DB::table('blind_indexes')
        ->where('indexable_type', EnterpriseIdentity::class)
        ->where('indexable_id', $identity->id)
        ->count();

    expect($blindIndexes)->toBe(0);
});

test('subject 列の照合順序が C である (バイト一致で引き当てる)', function (): void {
    /** @var object{collation_name: string|null}|null $column */
    $column = DB::selectOne(
        "SELECT collation_name FROM information_schema.columns
         WHERE table_name = 'enterprise_identities' AND column_name = 'subject'",
    );

    expect($column?->collation_name)->toBe('C');
});

test('大文字小文字が違う subject は別の身元になる (照合順序の実挙動)', function (): void {
    $connection = OrganizationOidcConnection::factory()->create();

    EnterpriseIdentity::factory()->create([
        'organization_oidc_connection_id' => $connection->id,
        'subject' => 'Alice',
    ]);
    EnterpriseIdentity::factory()->create([
        'organization_oidc_connection_id' => $connection->id,
        'subject' => 'alice',
    ]);

    expect($connection->identities()->count())->toBe(2);
    expect($connection->identities()->where('subject', 'Alice')->count())->toBe(1);
});

test('DB の CHECK 制約 2 本が名前で実在する', function (string $name): void {
    /** @var object{conname: string}|null $constraint */
    $constraint = DB::selectOne(
        'SELECT conname FROM pg_constraint WHERE conname = ?',
        [$name],
    );

    expect($constraint?->conname)->toBe($name);
})->with([
    'enterprise_identities_subject_octet_length_check',
    'enterprise_identities_subject_no_control_chars_check',
]);

test('DTO を迂回して直接書いても DB が拒む (二層目が実際に効く)', function (string $subject): void {
    $connection = OrganizationOidcConnection::factory()->create();
    $user = User::factory()->create();

    expect(fn () => DB::table('enterprise_identities')->insert([
        'organization_oidc_connection_id' => $connection->id,
        'user_id' => $user->id,
        'subject' => $subject,
        'created_at' => now(),
        'updated_at' => now(),
    ]))->toThrow(QueryException::class);
})->with([
    // ★pgsql の varchar(255) は 255 **文字**なので、日本語 256 文字は型では通り
    //   octet_length の CHECK だけが弾く (二層のうち DB 側が効いている証拠)。
    '256 バイト超' => [str_repeat('a', 256)],
    '制御文字を含む' => ["a\x1Fb"],
]);

test('C1 制御文字と書式文字は通る (負のコントロール: 保証外が保証外のままである)', function (string $subject): void {
    $connection = OrganizationOidcConnection::factory()->create();
    $user = User::factory()->create();

    DB::table('enterprise_identities')->insert([
        'organization_oidc_connection_id' => $connection->id,
        'user_id' => $user->id,
        'subject' => $subject,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    expect($connection->identities()->count())->toBe(1);
})->with([
    'C1 制御文字 (U+0085)' => ["a\u{0085}b"],
    'ゼロ幅スペース (U+200B)' => ["a\u{200B}b"],
]);

test('同じ接続で同じ subject は 2 件作れない (最後の防波堤)', function (): void {
    $connection = OrganizationOidcConnection::factory()->create();

    EnterpriseIdentity::factory()->create([
        'organization_oidc_connection_id' => $connection->id,
        'subject' => 'sub-1',
    ]);

    expect(fn () => EnterpriseIdentity::factory()->create([
        'organization_oidc_connection_id' => $connection->id,
        'subject' => 'sub-1',
    ]))->toThrow(QueryException::class);
});

test('接続が違えば同じ subject を持てる (身元の名前空間は接続ごと)', function (): void {
    $first = OrganizationOidcConnection::factory()->create();
    $second = OrganizationOidcConnection::factory()->create();

    EnterpriseIdentity::factory()->create(['organization_oidc_connection_id' => $first->id, 'subject' => 'sub-1']);
    EnterpriseIdentity::factory()->create(['organization_oidc_connection_id' => $second->id, 'subject' => 'sub-1']);

    expect($first->identities()->count())->toBe(1);
    expect($second->identities()->count())->toBe(1);
});

test('credentials_revision の既定値が 1 である', function (): void {
    expect(OrganizationOidcConnection::factory()->create()->credentials_revision)->toBe(1);
});
