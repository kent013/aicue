<?php

declare(strict_types=1);

use App\Enums\Security\OrgAccessRevocationExemption;
use App\Enums\Security\OrgAccessRevocationReason;
use App\Models\OauthSession;
use App\Models\Organization;
use App\Models\User;
use App\Services\OAuth\OrganizationAccessRevoker;
use App\Services\Organization\OrganizationMembershipService;
use Illuminate\Support\Facades\DB;
use Tests\Support\PhpTokenScan;

/*
 * 組織アクセス失効の配線 invariant (既定拒否)。
 *
 * 「組織の役割を書き込む経路は、同じひとまとまりの中で失効の窓口
 * ({@see OrganizationAccessRevoker}) を呼ぶ」を機械強制する。呼ばないものは
 * 型付き分類 + 30 文字以上の根拠で免除目録へ登録させる。
 *
 * ★既存の `MembershipWriteLockInventoryTest` (ロック規約と役割付与の単一窓口) とは
 *   役割を分ける。あちらは「ロックを取っているか」、本件は「失効を呼んでいるか」である。
 *   同じファイルに混ぜると 1 本のテストが 2 つの契約を持ち、失敗の意味が読めなくなる。
 *
 * ★**保証範囲を誇張しない**: 本 gate が見ているのは
 *   「メソッド本文に失効の呼び出しの字句が在ること」と
 *   「その位置が最後の役割書き込みより後であること」だけである。
 *   **すべての制御経路で失効が走ることは保証しない** — 途中に早期 return や条件分岐を
 *   足せば、本 gate は緑のまま失効しない経路が生まれる。
 *   実際に失効が起きることは `tests/Feature/Organizations/OrganizationAccessRevocationTest.php`
 *   (振る舞いのテスト) が担う。
 *   また母集団の抽出は字句ベースなので、変数経由の呼び出し (`$method = 'addRole';`) には
 *   **沈黙する**。
 *   失効列の検査 (検査D) は「資格情報 4 表の名前を文字列で持つファイル」×
 *   「`->update(` / `::update(` / `->forceFill(` 等の**引数**に失効列がある」の積で判定する。
 *   **「単一窓口であることの証明」ではなく「検出できる書き方に限った見張り」である**。
 *   次のいずれにも**沈黙する**: 表の名前を字句として持たない経路 (Eloquent モデル越しの更新だけで
 *   表名が出てこない形) / 属性への直接代入 (`$token->revoked = true; $token->save();`) /
 *   生 SQL (`DB::statement` / `whereRaw`) / 列名を変数で組み立てる形。
 */

/** 役割の書き込み / 組織メンバーの除去とみなす字句 (母集団のセレクタ)。 */
function orgRevocationRoleWriteMarkers(): array
{
    return ['addRole(', 'removeRole(', 'syncRoles(', 'users()->detach('];
}

/** 失効の呼び出しの字句。 */
function orgRevocationRevokeMarker(): string
{
    return 'accessRevoker->revoke(';
}

/** 免除理由の最低文字数 (「同上」「N/A」を機械的に弾く)。 */
function orgRevocationReasonMinLength(): int
{
    return 30;
}

/** 免除の**件数** (完全一致。増えても減っても赤くなる)。 */
function orgRevocationExemptionCount(): int
{
    return 2;
}

/**
 * 母集団メソッドの分類 (既定拒否。未分類は fail)。
 *
 * @return array<string, string> メソッド名 => 'revokes' | 'exempt'
 */
function orgRevocationClassification(): array
{
    return [
        'changeRole' => 'revokes',
        'removeMember' => 'revokes',
        'transferOwnership' => 'revokes',
        'normalizeOrganizationRole' => 'revokes',
        'joinOrganization' => 'exempt',
        'attachJustInTimeMember' => 'exempt',
    ];
}

/**
 * `revokes` 側の「ひとまとまり」の出所。
 *
 * 'self' = そのメソッド自身が `DB::transaction(` を張る。
 * それ以外 = そのメソッドを呼ぶ側のメソッド名 (private が親のひとまとまりに乗る形)。
 *
 * @return array<string, string>
 */
function orgRevocationTransactionOwners(): array
{
    return [
        'changeRole' => 'self',
        'removeMember' => 'self',
        'transferOwnership' => 'self',
        'normalizeOrganizationRole' => 'applyConsoleRole',
    ];
}

/** 免除目録 (deny-by-default)。 */
function orgRevocationExemptions(): array
{
    return [
        'joinOrganization' => OrgAccessRevocationExemption::JoinOrganization,
        'attachJustInTimeMember' => OrgAccessRevocationExemption::AttachJustInTimeMember,
    ];
}

/**
 * ソースを「空白とコメントを除いた 1 本の文字列」へ畳む。
 *
 * 文字列リテラルの中身は残る (列名の照合に要る) が、コメント / docblock は消える。
 * したがって説明文に `addRole(` や `$reason` と書いても検出には影響しない。
 */
function orgRevocationCompact(string $phpFragment): string
{
    $text = '';
    foreach (PhpTokenScan::normalize('<?php '.$phpFragment) as $token) {
        $text .= $token['text'];
    }

    return $text;
}

/** クラスのメソッド本文 (最初の `{` 以降) を素のソースとして取り出す。 */
function orgRevocationMethodBody(string $class, string $method): string
{
    $reflection = new ReflectionMethod($class, $method);
    $file = $reflection->getFileName();
    expect($file)->toBeString();
    $start = $reflection->getStartLine();
    $end = $reflection->getEndLine();
    expect($start)->toBeInt();
    expect($end)->toBeInt();

    $lines = file((string) $file, FILE_IGNORE_NEW_LINES);
    expect($lines)->toBeArray();
    /** @var list<string> $lines */
    $source = implode(PHP_EOL, array_slice($lines, $start - 1, $end - $start + 1));

    $brace = strpos($source, '{');

    // 抽象メソッド等で本文が無い形は本 gate の母集団に入らない (空文字を返す)
    return $brace === false ? '' : substr($source, $brace);
}

/**
 * 検出器の本体 (負のコントロールから再利用するため純関数にする)。
 *
 * @return list<string> 違反の説明 (空なら適合)
 */
function orgRevocationBodyViolations(string $label, string $rawBody): array
{
    $body = orgRevocationCompact($rawBody);
    $violations = [];

    // 最後の役割書き込みの位置
    $lastWrite = null;
    foreach (orgRevocationRoleWriteMarkers() as $marker) {
        $offset = 0;
        while (($pos = strpos($body, $marker, $offset)) !== false) {
            $lastWrite = $lastWrite === null ? $pos : max($lastWrite, $pos);
            $offset = $pos + 1;
        }
    }

    if ($lastWrite === null) {
        return [$label.': 役割の書き込みが 1 件も無い (母集団の抽出が壊れている)'];
    }

    // 最後の役割書き込みより後に失効の呼び出しがあること
    $after = strpos($body, orgRevocationRevokeMarker(), $lastWrite);
    if ($after === false) {
        $violations[] = strpos($body, orgRevocationRevokeMarker()) === false
            ? $label.': 失効の呼び出し ('.orgRevocationRevokeMarker().') が本文に無い'
            : $label.': 失効の呼び出しが最後の役割書き込みより前にある '
                .'(役割の入れ替えの途中で失敗すると失効だけが残る)';
    }

    return $violations;
}

/** `revoke()` の中の `$reason` 参照が「監査 metadata の値の位置」ちょうど 1 回であること。 */
function orgRevocationReasonUsageViolations(string $label, string $rawBody): array
{
    $tokens = PhpTokenScan::normalize('<?php '.$rawBody);

    $indexes = [];
    foreach ($tokens as $i => $token) {
        if ($token['id'] === T_VARIABLE && $token['text'] === '$reason') {
            $indexes[] = $i;
        }
    }

    if (count($indexes) !== 1) {
        return [$label.': $reason の参照が '.count($indexes).' 回ある (監査 metadata の 1 回だけであること)'];
    }

    $i = $indexes[0];
    $before2 = $tokens[$i - 2] ?? null;
    $before1 = $tokens[$i - 1] ?? null;
    $after1 = $tokens[$i + 1] ?? null;
    $after2 = $tokens[$i + 2] ?? null;

    $ok = $before2 !== null && $before2['id'] === T_CONSTANT_ENCAPSED_STRING
        && trim($before2['text'], "'\"") === 'reason'
        && $before1 !== null && $before1['id'] === T_DOUBLE_ARROW
        && $after1 !== null && $after1['id'] === T_OBJECT_OPERATOR
        && $after2 !== null && $after2['text'] === 'value';

    if (! $ok) {
        return [$label.": \$reason の唯一の参照が \"'reason' => \$reason->value\" の位置にない "
            .'(理由は観測であって制御ではない)'];
    }

    return [];
}

/** 資格情報の 4 表 (この表の失効列だけが本 gate の対象)。 */
function orgRevocationCredentialTables(): array
{
    return ['oauth_sessions', 'oauth_access_tokens', 'oauth_refresh_tokens', 'oauth_auth_codes'];
}

/**
 * 資格情報の 4 表の名前を文字列リテラルとして持つファイルか。
 *
 * ★列名 (`revoked` / `revoked_at`) だけで判定すると、API キーの失効
 * (`api_keys.revoked_at`) や招待の取り消し (`organization_invitations.revoked_at`) まで
 * 拾ってしまう。**別物の概念を列名の一致だけで統合しない**ため、表の名前と対にする。
 */
function orgRevocationTouchesCredentialTable(string $phpSource): bool
{
    foreach (PhpTokenScan::normalize($phpSource) as $token) {
        if ($token['id'] !== T_CONSTANT_ENCAPSED_STRING) {
            continue;
        }
        if (in_array(trim($token['text'], "'\""), orgRevocationCredentialTables(), true)) {
            return true;
        }
    }

    return false;
}

/**
 * `update([...])` / `forceFill([...])` の引数に失効列 (`revoked` / `revoked_at`) を
 * 含むファイルか (= 失効列への書き込み)。
 *
 * 受け手はメソッド呼び出し (`->`) と静的呼び出し (`::`) の両方を見る。
 * **どちらでもない書き方 (属性への直接代入など) には沈黙する** —
 * これは検出であって網羅の証明ではない。
 */
function orgRevocationHasRevocationColumnWrite(string $phpSource): bool
{
    $tokens = PhpTokenScan::normalize($phpSource);
    $count = count($tokens);

    for ($i = 0; $i < $count; $i++) {
        $text = $tokens[$i]['text'];
        if ($text !== 'update' && $text !== 'forceFill') {
            continue;
        }
        $receiver = $tokens[$i - 1]['id'] ?? null;
        if ($receiver !== T_OBJECT_OPERATOR && $receiver !== T_DOUBLE_COLON) {
            continue;
        }
        if (($tokens[$i + 1]['text'] ?? '') !== '(') {
            continue;
        }

        // 呼び出しの括弧が閉じるまでを引数の範囲とする
        $depth = 0;
        for ($j = $i + 1; $j < $count; $j++) {
            $t = $tokens[$j]['text'];
            if ($t === '(') {
                $depth++;

                continue;
            }
            if ($t === ')') {
                $depth--;
                if ($depth === 0) {
                    break;
                }

                continue;
            }
            if ($tokens[$j]['id'] === T_CONSTANT_ENCAPSED_STRING
                && in_array(trim($t, "'\""), ['revoked', 'revoked_at'], true)) {
                return true;
            }
        }
    }

    return false;
}

/** 資格情報 4 表の失効列へ書き込むファイルか (表の名前と失効列の書き込みの両方を持つ)。 */
function orgRevocationWritesCredentialRevocation(string $phpSource): bool
{
    return orgRevocationTouchesCredentialTable($phpSource)
        && orgRevocationHasRevocationColumnWrite($phpSource);
}

/**
 * 失効列へ書き込んでよいファイル (allowlist)。
 *
 * @return array<string, string> 相対パス => 理由
 */
function orgRevocationWriteAllowlist(): array
{
    return [
        'app/Services/OAuth/OrganizationAccessRevoker.php' => '本件の窓口 (ある組織におけるある利用者の資格情報をまとめて失効させる)',
        'app/Models/OauthSession.php' => '画面 / CLI からの 1 セッションだけの失効。対象の広さが違うので窓口と統合しない',
    ];
}

test('検査A: 役割を書き込むメソッドはすべて分類されている (未分類は fail)', function (): void {
    $classification = orgRevocationClassification();
    $reflection = new ReflectionClass(OrganizationMembershipService::class);

    $population = [];
    foreach ($reflection->getMethods() as $method) {
        if ($method->getDeclaringClass()->getName() !== OrganizationMembershipService::class) {
            continue;
        }
        $body = orgRevocationCompact(orgRevocationMethodBody(OrganizationMembershipService::class, $method->getName()));
        foreach (orgRevocationRoleWriteMarkers() as $marker) {
            if (str_contains($body, $marker)) {
                $population[] = $method->getName();

                break;
            }
        }
    }

    sort($population);
    $declared = array_keys($classification);
    sort($declared);

    expect($population)->toBe($declared,
        '役割を書き込むメソッドの集合と分類表が一致しません。新しい経路は '
        .'失効を呼ぶ (revokes) か、免除目録へ登録する (exempt) かのどちらかに分類してください。');
});

test('検査A2: 分類の値は revokes / exempt のいずれかで、exempt は免除目録に登録されている', function (): void {
    $violations = [];

    foreach (orgRevocationClassification() as $method => $kind) {
        if (! in_array($kind, ['revokes', 'exempt'], true)) {
            $violations[] = "{$method}: 未知の分類 {$kind}";

            continue;
        }
        if ($kind !== 'exempt') {
            continue;
        }
        $exemption = orgRevocationExemptions()[$method] ?? null;
        if (! $exemption instanceof OrgAccessRevocationExemption) {
            $violations[] = "{$method}: exempt なのに免除目録に登録がありません";

            continue;
        }
        if ($exemption->value !== 'OrganizationMembershipService::'.$method) {
            $violations[] = "{$method}: 免除目録の case 値がメソッドを指していません ({$exemption->value})";
        }
        if (mb_strlen($exemption->rationale()) < orgRevocationReasonMinLength()) {
            $violations[] = "{$method}: 免除の根拠が ".orgRevocationReasonMinLength().' 文字未満です';
        }
    }

    expect($violations)->toBe([], PHP_EOL.implode(PHP_EOL, $violations));
});

test('検査A3: 免除の件数が宣言値と一致する (増えても減っても検出する)', function (): void {
    expect(count(orgRevocationExemptions()))->toBe(orgRevocationExemptionCount(),
        '免除を増減させたら orgRevocationExemptionCount() も書き換えてください '
        .'(件数の変化が必ず差分に現れるようにするため)。');
    expect(count(OrgAccessRevocationExemption::cases()))->toBe(orgRevocationExemptionCount(),
        'OrgAccessRevocationExemption の case 数と目録の件数が食い違っています (死んだ case の残置)。');
});

test('検査B: revokes に分類したメソッドは最後の役割書き込みより後で失効を呼ぶ', function (): void {
    $violations = [];

    foreach (orgRevocationClassification() as $method => $kind) {
        if ($kind !== 'revokes') {
            continue;
        }
        $body = orgRevocationMethodBody(OrganizationMembershipService::class, $method);
        $violations = [...$violations, ...orgRevocationBodyViolations($method, $body)];
    }

    expect($violations)->toBe([], PHP_EOL.implode(PHP_EOL, $violations));
});

test('検査B2: revokes の失効は必ずひとまとまり (トランザクション) の内側にある', function (): void {
    $violations = [];

    foreach (orgRevocationTransactionOwners() as $method => $owner) {
        $body = orgRevocationCompact(orgRevocationMethodBody(OrganizationMembershipService::class, $method));

        if ($owner === 'self') {
            if (! str_contains($body, 'DB::transaction(')) {
                $violations[] = "{$method}: 自分でトランザクションを張る宣言なのに DB::transaction( が無い";
            }

            continue;
        }

        $ownerBody = orgRevocationCompact(orgRevocationMethodBody(OrganizationMembershipService::class, $owner));
        if (! str_contains($ownerBody, 'DB::transaction(')) {
            $violations[] = "{$method}: 呼び出し元 {$owner} が DB::transaction( を張っていない";
        }
        if (! str_contains($ownerBody, '->'.$method.'(')) {
            $violations[] = "{$method}: 呼び出し元 {$owner} が {$method}() を呼んでいない (宣言が陳腐化)";
        }
    }

    // 宣言表と分類表 (revokes) の集合が一致すること
    $declared = array_keys(orgRevocationTransactionOwners());
    $revokes = array_keys(array_filter(orgRevocationClassification(), static fn (string $k): bool => $k === 'revokes'));
    sort($declared);
    sort($revokes);
    expect($declared)->toBe($revokes, 'revokes の集合とトランザクション出所の宣言表が一致していません。');

    expect($violations)->toBe([], PHP_EOL.implode(PHP_EOL, $violations));
});

test('検査C: 検出器の負例 (空振り防止)', function (): void {
    // 1. 失効の呼び出しが無い
    $noRevoke = '{ $u->addRole($r, $team); }';
    expect(orgRevocationBodyViolations('fixture', $noRevoke))->toHaveCount(1);
    expect(orgRevocationBodyViolations('fixture', $noRevoke)[0])->toContain('が本文に無い');

    // 2. 失効が役割書き込みより前にある
    $before = '{ $this->accessRevoker->revoke($org, $u, $reason, $actor); $u->addRole($r, $team); }';
    expect(orgRevocationBodyViolations('fixture', $before))->toHaveCount(1);
    expect(orgRevocationBodyViolations('fixture', $before)[0])
        ->toContain('失効の呼び出しが最後の役割書き込みより前にある');

    // 3. 役割書き込みが 2 回あり、失効がその間にある
    $between = '{ $u->removeRole($old, $team); $this->accessRevoker->revoke($org, $u, $reason, $actor);'
        .' $u->addRole($new, $team); }';
    expect(orgRevocationBodyViolations('fixture', $between))->toHaveCount(1);

    // 4. 正例 (検出器が何でも赤くするわけではないことの対照)
    $ok = '{ $u->removeRole($old, $team); $u->addRole($new, $team);'
        .' $this->accessRevoker->revoke($org, $u, $reason, $actor); }';
    expect(orgRevocationBodyViolations('fixture', $ok))->toBe([]);

    // 5. コメントの中の呼び出しは数えない (正規化がコメントを落とすことの確認)
    $comment = '{ $u->addRole($new, $team); // $this->accessRevoker->revoke(...) を後で足す'.PHP_EOL.'}';
    expect(orgRevocationBodyViolations('fixture', $comment))->toHaveCount(1);
});

test('検査D: 検出できる書き方で失効列へ書き込むのは allowlist のファイルだけ', function (): void {
    $allowlist = orgRevocationWriteAllowlist();
    $violations = [];
    $found = [];

    $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(base_path('app')));
    foreach ($iterator as $file) {
        if (! $file instanceof SplFileInfo || $file->getExtension() !== 'php') {
            continue;
        }
        $relative = str_replace(base_path().'/', '', $file->getPathname());
        $source = (string) file_get_contents($file->getPathname());
        if (! orgRevocationWritesCredentialRevocation($source)) {
            continue;
        }
        $found[] = $relative;
        if (! array_key_exists($relative, $allowlist)) {
            $violations[] = $relative.': 資格情報の失効列への書き込みは窓口 (OrganizationAccessRevoker) へ'
                .'集約するか、対象の広さが違う理由を添えて allowlist へ登録してください';
        }
    }

    expect($violations)->toBe([], PHP_EOL.implode(PHP_EOL, $violations));

    sort($found);
    $declared = array_keys($allowlist);
    sort($declared);
    expect($found)->toBe($declared, 'allowlist に現存しないファイルが残っています (stale 検出)。');
});

test('検査D2: 検出器の負例 (表示用の配列や別テーブルの失効は数えない)', function (): void {
    $write = '<?php DB::table(\'oauth_access_tokens\')->whereIn(\'id\', $ids)->update([\'revoked\' => true]);';
    expect(orgRevocationWritesCredentialRevocation($write))->toBeTrue();

    $writeAt = '<?php DB::table(\'oauth_sessions\')->where(\'id\', $id)'
        .'->update([\'revoked_at\' => now()]);';
    expect(orgRevocationWritesCredentialRevocation($writeAt))->toBeTrue();

    // 表示用の配列 (書き込みではない)
    $display = '<?php DB::table(\'oauth_sessions\')->get(); return [\'revoked_at\' => $this->revokedAt];';
    expect(orgRevocationWritesCredentialRevocation($display))->toBeFalse();

    // 資格情報の表だが失効列ではない列の更新
    $otherColumn = '<?php DB::table(\'oauth_access_tokens\')->where(\'id\', $id)->update([\'session_id\' => $id]);';
    expect(orgRevocationWritesCredentialRevocation($otherColumn))->toBeFalse();

    // 別テーブルの失効 (API キー / 招待の取り消し) は本 gate の対象外
    $apiKey = '<?php $key->forceFill([\'revoked_at\' => now()])->save();';
    expect(orgRevocationWritesCredentialRevocation($apiKey))->toBeFalse();

    // 1 セッションだけの失効 (OauthSession) は allowlist 側なので検出されて構わない
    expect(orgRevocationWritesCredentialRevocation(
        (string) file_get_contents((new ReflectionClass(OauthSession::class))->getFileName() ?: ''),
    ))->toBeTrue();
});

test('検査E: 窓口の revoke() は理由を監査 metadata にしか使わない', function (): void {
    $body = orgRevocationMethodBody(OrganizationAccessRevoker::class, 'revoke');

    expect(orgRevocationReasonUsageViolations('OrganizationAccessRevoker::revoke', $body))->toBe([],
        '理由 ($reason) は観測であって制御ではありません。分岐に使うと理由の追加が挙動の変更になります。');
});

test('検査E2: 検出器の負例 (理由の使われ方)', function (): void {
    // 別メソッドへ逃がす形 (回数は 1 だが位置が違う)
    $delegated = '{ $this->applyRevocationPolicy($reason); }';
    expect(orgRevocationReasonUsageViolations('fixture', $delegated))->toHaveCount(1);

    // 分岐に使う形
    $branch = '{ $x = match ($reason) { A => 1 }; }';
    expect(orgRevocationReasonUsageViolations('fixture', $branch))->toHaveCount(1);

    // metadata に固定文字列を入れ、別用途で 1 回使う形
    $decoy = "{ \$m = ['reason' => 'fixed']; \$this->log(\$reason); }";
    expect(orgRevocationReasonUsageViolations('fixture', $decoy))->toHaveCount(1);

    // 正例 + 説明のコメントに $reason と書いてあっても緑
    $ok = '{ // $reason は観測にしか使わない'.PHP_EOL
        ."\$this->recorder->recordOrFail(\$type, \$u, ['reason' => \$reason->value]); }";
    expect(orgRevocationReasonUsageViolations('fixture', $ok))->toBe([]);
});

test('検査G: ひとまとまりの外から窓口を呼ぶと実行時に拒否される', function (): void {
    // ★このレーンに置く理由: Feature / Unit レーンは RefreshDatabase が全体を
    //   トランザクションで包むため、深さ 0 の状態を作れず「外から呼ぶ」形を再現できない。
    //   Architecture レーンは RefreshDatabase を使わないので深さ 0 のまま呼べる。
    //   引数のモデルは検査の前に例外になるため保存不要 (DB に触れない)。
    expect(DB::transactionLevel())->toBe(0);

    expect(fn () => app(OrganizationAccessRevoker::class)->revoke(
        new Organization,
        new User,
        OrgAccessRevocationReason::RoleChanged,
        null,
    ))->toThrow(InvalidArgumentException::class);
});

test('検査F: 失効の監査は握り潰さない版 (recordOrFail) を使う', function (): void {
    $body = orgRevocationCompact(orgRevocationMethodBody(OrganizationAccessRevoker::class, 'revoke'));

    expect($body)->toContain('->recordOrFail(');
    expect(str_contains($body, '->record('))->toBeFalse(
        '握り潰す版 (record) に差し替わると「資格情報は失効したが監査に残っていない」状態が'
        .'静かに生まれます。書き分けを構造で固定します。',
    );
});
