<?php

declare(strict_types=1);

use App\Enums\Security\DirectFetchJustification;
use App\Http\Middleware\LocalOnly;
use Illuminate\Support\Facades\Route;
use Tests\Support\Security\DirectFetchInventory;
use Tests\Support\Security\DirectFetchJustificationEntry;
use Tests\Support\Security\PrimaryKeyPredicateKind;
use Tests\Support\Security\PrimaryKeyStaticQueryCandidate;
use Tests\Support\Security\PrimaryKeyStaticQueryScanner;

/*
 * 直 fetch (テナントスコープ外の主キー同一性クエリ) の invariant (deny-by-default)。
 *
 * 「クラス起点 (`User::` / `new User` / `DB::table('users')`) で主キー同一性クエリを書くなら、
 * 分類と具体的根拠を inventory に明示登録する」を機械強制する。
 *
 * ★母集団は `app/**` + `routes/*.php` の全層。層で絞らない:
 *   entrypoint 層 (`app/Http` + `app/Mcp`) に絞ると「Controller が scalar id を Service に渡し
 *   Service 側で global fetch する」という抜け道が残る。ノイズはディレクトリではなく
 *   **識別子引数の出所 (provenance)** で落とす。
 *
 * ★本 gate が守るのは `NestedRouteIdorDefenseTest` と**素で交わらない母集団**である:
 *   前者は route parameter 由来の id、本 gate は route parameter **以外**の id
 *   (POST payload / query string / MCP tool 引数 / token claim / queue payload)。
 *   `NestedRouteDefenseInventory::candidates()` の母集団は parameterNames() !== [] の named route で、
 *   body で id を受け取る経路は route parameter を増やさないため 1 件も現れない。
 *
 * ★本 gate が**保証しないこと** (主張範囲。AGENTS.md 不変条件「cross-org 不可」の全面証明ではない):
 *   - 到達可能性 (`if (false) { … }` 中の候補も候補になる)
 *   - `whereRaw` / `whereIntegerInRaw` / 動的列名 (`where($col, $x)`) — テスト 11 が 0 件を固定する
 *   - relation / org-scoped 解決の一般的強制
 *   - `exists:` validation rule による存在漏れ (機構が別。FormRequest / route 側の話)
 *   - provenance 証明の第 2 段 (元モデルが保証済み provenance に属すること) — テスト 13 が代償措置
 *
 * 字句解析は tests/Support/Security/PrimaryKeyStaticQueryScanner に切り出し、解析器自体の
 * positive/negative は tests/Unit/Architecture/PrimaryKeyStaticQueryScannerTest が固定する。
 */

/** 候補数の下限 (degenerate PASS 防止。走査器が部分的に壊れた場合も検知する)。 */
function modelDirectFetchCandidateFloor(): int
{
    return 20;
}

/**
 * 債務 case の件数上限。
 *
 * **実測 0 件**。aicue:T118 で payload 由来 id 3 件 (org 移譲 / project メンバー追加 /
 * MCP consent) を relation 起点へ寄せ、`exists:` rule とセットで存在オラクルを閉じたため。
 * 0 のまま維持する — 1 件足すには inventory 登録と本 cap の引き上げの
 * **2 つ**が要り、必ずレビューの俎上に乗る。
 * 分類 case (`PayloadIdWithGlobalExistenceRuleDebt`) と
 * `DirectFetchJustificationEntry::globalExistenceRuleDebt()` は
 * 「この形は債務である」という裁定語彙として**残す** (消すと再発時の分類が失われる)。
 */
function modelDirectFetchDebtCap(): int
{
    return 0;
}

/** `actorSource` の既定値集合。 */
function modelDirectFetchActorSources(): array
{
    return ['authenticated_user', 'validated_token_claim', 'passport_token_record'];
}

/**
 * 債務 case の membership / tenant marker。
 *
 * `lockForUpdate` は marker に含めない (ロックは競合制御であって所属検証ではない)。
 */
function modelDirectFetchMembershipMarkers(): array
{
    return [
        '->organizations()->whereKey(',
        '->users()->whereKey(',
        '->members()->whereKey(',
        'whereBelongsTo($organization',
        'organizationRole(',
    ];
}

/**
 * case × predicateKind の許可表。表に無い組み合わせは fail。
 *
 * `QueuePayloadRehydration + DestructiveIdentity` を禁じるのは「queue payload の id で
 * 無検証に削除する」形を設計段階で塞ぐため (現状 0 件)。
 * `OwnerScopedQueryConstraint + DestructiveIdentity` も禁じる — `Model::destroy($id)` は
 * 静的削除であり同一 chain に owner scope を足せないため、case の定義と矛盾する。
 */
function modelDirectFetchAllowedPredicateKinds(): array
{
    $single = PrimaryKeyPredicateKind::SingleIdentity->name;
    $multi = PrimaryKeyPredicateKind::MultiIdentity->name;
    $exclusion = PrimaryKeyPredicateKind::IdentityExclusion->name;
    $destructive = PrimaryKeyPredicateKind::DestructiveIdentity->name;

    return [
        DirectFetchJustification::OwnerScopedQueryConstraint->value => [$single, $multi, $exclusion],
        DirectFetchJustification::IdDerivedFromTenantScopedQuery->value => [$single, $multi, $exclusion],
        DirectFetchJustification::IdDerivedFromSameMethodQuery->value => [$single, $multi],
        DirectFetchJustification::IdSuppliedByInternalCaller->value => [$single, $multi],
        DirectFetchJustification::AuthenticatedActorScope->value => [$single, $multi, $exclusion],
        DirectFetchJustification::QueuePayloadRehydration->value => [$single, $multi],
        DirectFetchJustification::LocalOnlyDiagnostics->value => [$single],
        DirectFetchJustification::OperatorInvokedConsoleCommand->value => [$single, $multi, $exclusion, $destructive],
        DirectFetchJustification::PayloadIdWithGlobalExistenceRuleDebt->value => [$single],
    ];
}

/**
 * inventory と現存候補を突き合わせた組 (key => [candidate, entry])。
 *
 * @return array<string, array{0: PrimaryKeyStaticQueryCandidate, 1: DirectFetchJustificationEntry}>
 */
function modelDirectFetchMatchedPairs(): array
{
    $inventory = DirectFetchInventory::inventory();
    $pairs = [];

    foreach (DirectFetchInventory::candidates() as $candidate) {
        $entry = $inventory[$candidate->key] ?? null;
        if ($entry !== null) {
            $pairs[$candidate->key] = [$candidate, $entry];
        }
    }

    return $pairs;
}

/**
 * 指定 case の組だけを取り出す。
 *
 * @return array<string, array{0: PrimaryKeyStaticQueryCandidate, 1: DirectFetchJustificationEntry}>
 */
function modelDirectFetchPairsFor(DirectFetchJustification $case): array
{
    return array_filter(
        modelDirectFetchMatchedPairs(),
        static fn (array $pair): bool => $pair[1]->case === $case,
    );
}

/** `App\Foo\Bar::method` の `App\Foo\Bar` を app/ 相対のファイルパスへ。 */
function modelDirectFetchClassPath(string $reference): ?string
{
    if (preg_match('/^(App\\\\[A-Za-z0-9_\\\\]+)::[A-Za-z0-9_]+$/', $reference, $matches) !== 1) {
        return null;
    }

    return 'app/'.str_replace('\\', '/', substr($matches[1], 4)).'.php';
}

/** `App\Foo\Bar::method` の method 名。 */
function modelDirectFetchMethodName(string $reference): ?string
{
    $position = strrpos($reference, '::');

    return $position === false ? null : substr($reference, $position + 2);
}

/** 候補ファイル + スコープ名から `App\Foo\Bar::method` 形式を組み立てる。 */
function modelDirectFetchSelfReference(PrimaryKeyStaticQueryCandidate $candidate): string
{
    return 'App\\'.str_replace('/', '\\', substr($candidate->displayPath(), 0, -4)).'::'.$candidate->scopeName;
}

/** トークン列を空白除去して marker 照合できる形にする。 */
function modelDirectFetchCompact(string $source): string
{
    return str_replace(' ', '', $source);
}

test('クラス起点の主キー同一性クエリが全て inventory に明示分類されている (未知は fail)', function (): void {
    $inventory = DirectFetchInventory::inventory();
    $violations = [];

    foreach (DirectFetchInventory::candidates() as $candidate) {
        if (! array_key_exists($candidate->key, $inventory)) {
            $violations[] = $candidate->key.' ('.$candidate->identityArgument.' で引いている)';
        }
    }

    expect($violations)->toBe([],
        'テナントスコープ外で id からモデルを引いている箇所があります。'
        .'まず relation 起点 ($organization->users()->whereKey(...)) に直せないか検討し、'
        .'直せない場合のみ DirectFetchInventory へ DirectFetchJustification + 具体的根拠を登録してください。'
        .PHP_EOL.implode(PHP_EOL, $violations));
});

test('inventory の key は全て現存する候補である (stale 検出)', function (): void {
    $keys = array_map(static fn (PrimaryKeyStaticQueryCandidate $c): string => $c->key, DirectFetchInventory::candidates());
    $stale = array_values(array_diff(array_keys(DirectFetchInventory::inventory()), $keys));

    expect($stale)->toBe([],
        'inventory に、現存しない候補の裁定が残っています (コードが直った / key が変わった)。'
        .'該当エントリを削除するか、新しい key へ書き換えてください。'.PHP_EOL.implode(PHP_EOL, $stale));
});

test('OwnerScopedQueryConstraint は同一 chain の所有者制約 (右辺 provenance 込み) を伴う', function (): void {
    $violations = [];

    foreach (modelDirectFetchPairsFor(DirectFetchJustification::OwnerScopedQueryConstraint) as $key => [$candidate, $entry]) {
        if (! PrimaryKeyStaticQueryScanner::hasOwnerScopedConstraint($candidate)) {
            $violations[] = $key.' — chain: '.$candidate->chainSource;
        }
    }

    // v1 の許可 signature は where('organization_id'|'user_id'|'team_id'|'project_id', $model->getKey())
    // と whereBelongsTo($model) の 2 形のみ。whereHas(...) は必要とする候補が実在せず、
    // ネスト closure 内の右辺 provenance 判定はコストが跳ねるため v1 では受理しない。
    expect($violations)->toBe([],
        'OwnerScopedQueryConstraint は「identity 述語と同じ chain に所有者/テナント制約があり、'
        .'その右辺が解決済みモデル由来である」ことが条件です。'.PHP_EOL.implode(PHP_EOL, $violations));
});

test('IdDerivedFromTenantScopedQuery は identity がテナントスコープ済みの解決に由来する', function (): void {
    $violations = [];

    foreach (modelDirectFetchPairsFor(DirectFetchJustification::IdDerivedFromTenantScopedQuery) as $key => [$candidate, $entry]) {
        if (! PrimaryKeyStaticQueryScanner::identityAssignedFromRelationQuery($candidate)) {
            $violations[] = $key.' — identity: '.$candidate->identityArgument;
        }
    }

    expect($violations)->toBe([],
        'IdDerivedFromTenantScopedQuery は identity 変数が relation 起点クエリ、または'
        .'解決済みモデルの key から代入されていることが条件です。'.PHP_EOL.implode(PHP_EOL, $violations));
});

test('IdDerivedFromSameMethodQuery は同一メソッドの走査クエリ由来かつ request 入力を読まない', function (): void {
    $violations = [];

    foreach (modelDirectFetchPairsFor(DirectFetchJustification::IdDerivedFromSameMethodQuery) as $key => [$candidate, $entry]) {
        if (! PrimaryKeyStaticQueryScanner::identityDerivedFromSameMethodQuery($candidate)) {
            $violations[] = $key.' — identity が同一メソッドのクエリ結果由来でない: '.$candidate->identityArgument;
        }
        if (! PrimaryKeyStaticQueryScanner::methodIsFreeOfRequestAccessors($candidate)) {
            $violations[] = $key.' — 同一メソッドに request accessor がある';
        }
    }

    expect($violations)->toBe([],
        'IdDerivedFromSameMethodQuery は「identity が同一メソッド内の走査クエリ結果由来」かつ'
        .'「HTTP 入力を経由しない」ことが条件です。'.PHP_EOL.implode(PHP_EOL, $violations));
});

test('IdSuppliedByInternalCaller は private + 引数由来 + request 入力を読まない + calledBy 実在', function (): void {
    $violations = [];

    foreach (modelDirectFetchPairsFor(DirectFetchJustification::IdSuppliedByInternalCaller) as $key => [$candidate, $entry]) {
        if (! PrimaryKeyStaticQueryScanner::methodIsPrivate($candidate)) {
            $violations[] = $key.' — private メソッドでない (public は本 case を使えない)';
        }
        if (! PrimaryKeyStaticQueryScanner::identityDerivedFromMethodParameters($candidate)) {
            $violations[] = $key.' — identity が引数由来でない: '.$candidate->identityArgument;
        }
        if (! PrimaryKeyStaticQueryScanner::methodIsFreeOfRequestAccessors($candidate)) {
            $violations[] = $key.' — 同一メソッドに request accessor がある';
        }

        $path = modelDirectFetchClassPath($entry->calledBy());
        $method = modelDirectFetchMethodName($entry->calledBy());
        $sources = DirectFetchInventory::sourceFiles();
        if ($path === null || $method === null || ! array_key_exists($path, $sources)) {
            $violations[] = $key.' — calledBy のクラスが実在しない: '.$entry->calledBy();

            continue;
        }
        $body = PrimaryKeyStaticQueryScanner::methodBody($sources[$path], $method);
        if ($body === null || ! str_contains(modelDirectFetchCompact($body), '->'.$candidate->scopeName.'(')) {
            $violations[] = $key.' — calledBy の本文が '.$candidate->scopeName.'() を呼んでいない';
        }
    }

    expect($violations)->toBe([],
        'IdSuppliedByInternalCaller は private メソッド + 引数由来 identity + request accessor 無し +'
        .'calledBy の実在呼び出しが条件です。'.PHP_EOL.implode(PHP_EOL, $violations));
});

test('AuthenticatedActorScope は同一メソッドに request accessor を持たない', function (): void {
    $violations = [];

    foreach (modelDirectFetchPairsFor(DirectFetchJustification::AuthenticatedActorScope) as $key => [$candidate, $entry]) {
        if (! PrimaryKeyStaticQueryScanner::methodIsFreeOfRequestAccessors($candidate)) {
            $violations[] = $key.' — 同一メソッドに request accessor がある';
        }
        if (! in_array($entry->actorSource(), modelDirectFetchActorSources(), true)) {
            $violations[] = $key.' — actorSource が既定値集合にない: '.$entry->actorSource();
        }
    }

    // ★本 case のみ機械証明ができない (provenance のデータフロー解析は走査器の範囲外)。
    //   negative check と構造化 field で濫用を抑えるが、最終的には人手の根拠文に依存する。
    expect($violations)->toBe([],
        'AuthenticatedActorScope は「identity が request payload 由来でない」ことが条件です。'
        .PHP_EOL.implode(PHP_EOL, $violations));
});

test('QueuePayloadRehydration は job property 由来、または job から委譲された引数由来である', function (): void {
    $violations = [];
    $sources = DirectFetchInventory::sourceFiles();

    foreach (modelDirectFetchPairsFor(DirectFetchJustification::QueuePayloadRehydration) as $key => [$candidate, $entry]) {
        $enqueuedBy = $entry->enqueuedBy();
        $path = modelDirectFetchClassPath($enqueuedBy);
        if ($path === null || ! array_key_exists($path, $sources)) {
            $violations[] = $key.' — enqueuedBy のクラスが実在しない: '.$enqueuedBy;

            continue;
        }

        $enqueuedMethod = modelDirectFetchMethodName($enqueuedBy);
        $enqueuedBody = $enqueuedMethod === null
            ? null
            : PrimaryKeyStaticQueryScanner::methodBody($sources[$path], $enqueuedMethod);
        if ($enqueuedBody === null) {
            $violations[] = $key.' — enqueuedBy のメソッドが実在しない: '.$enqueuedBy;

            continue;
        }

        // (a) 直接形: job 本体が自身の property を再水和する。
        //     enqueuedBy が**その job を実際に dispatch している**ことまで確認する
        //     (メソッドが実在するだけでは無関係な既存メソッド名でも通ってしまう)
        $isJobFile = str_starts_with($candidate->displayPath(), 'Jobs/');
        $isJobProperty = preg_match('/^\$this->[A-Za-z0-9_]*Ids?$/', $candidate->identityArgument) === 1;
        if ($isJobFile && $isJobProperty) {
            $jobClass = basename($candidate->displayPath(), '.php');
            $compactBody = modelDirectFetchCompact($enqueuedBody);
            if (! str_contains($compactBody, $jobClass.'::dispatch(')) {
                $violations[] = $key.' — enqueuedBy が '.$jobClass.' を dispatch していない: '.$enqueuedBy;

                continue;
            }
            // 「enqueue 時にサーバが確定した値」であることの最低条件として、dispatch 引数が
            // request 入力の直読みでないことを確認する
            // (`Job::dispatch($request->integer('user_id'))` を通すと case 名が嘘になる)
            if (preg_match_all('/'.preg_quote($jobClass, '/').'::dispatch\(([^)]*)\)/u', $compactBody, $calls) === false) {
                continue;
            }
            foreach ($calls[1] as $argument) {
                if (preg_match('/\$request->|request\(|->input\(|->validated\(|->query\(|->post\(|->json\(/u', $argument) === 1) {
                    $violations[] = $key.' — dispatch 引数が request 入力の直読み: '.$argument;
                }
            }

            continue;
        }

        // (b) 委譲形: job が **自身の property を** scalar id としてそのまま Service へ渡す。
        //     「dispatch 元の job のメソッドが実在し、そのメソッド本文が
        //     `->{候補メソッド}($this->...)` を呼んでいる」ところまで機械確認する
        //     (メソッド名の一致だけだと job のどこかに同名呼び出しがあれば通ってしまう)
        $delegated = PrimaryKeyStaticQueryScanner::identityDerivedFromMethodParameters($candidate)
            && str_starts_with($path, 'app/Jobs/')
            && str_contains(modelDirectFetchCompact($enqueuedBody), '->'.$candidate->scopeName.'($this->');
        if ($delegated) {
            continue;
        }

        $violations[] = $key.' — job property でも job からの委譲でもない: '.$candidate->identityArgument;
    }

    expect($violations)->toBe([],
        'QueuePayloadRehydration は「app/Jobs 配下で $this->{…}Id を再水和する」か'
        .'「app/Jobs の dispatch 元がそのメソッドへ id を渡している」ことが条件です。'
        .PHP_EOL.implode(PHP_EOL, $violations));
});

test('LocalOnlyDiagnostics は LocalOnly middleware 付き route と local 限定登録の 2 段で守られている', function (): void {
    $violations = [];
    $routeSources = array_filter(
        DirectFetchInventory::sourceFiles(),
        static fn (string $path): bool => str_starts_with($path, 'routes/'),
        ARRAY_FILTER_USE_KEY,
    );

    foreach (modelDirectFetchPairsFor(DirectFetchJustification::LocalOnlyDiagnostics) as $key => [$candidate, $entry]) {
        $route = Route::getRoutes()->getByName($entry->routeName());
        if ($route === null) {
            $violations[] = $key.' — route が存在しない: '.$entry->routeName();

            continue;
        }
        if (! in_array(LocalOnly::class, $route->gatherMiddleware(), true)) {
            $violations[] = $key.' — route に LocalOnly middleware が付いていない: '.$entry->routeName();
        }
        // route が候補のコントローラを指していること (無関係な local route を借りて通せないように)
        $action = $route->getActionName();
        if (! str_contains($action, basename($candidate->displayPath(), '.php'))) {
            $violations[] = $key.' — routeName が候補のコントローラを指していない: '.$action;
        }
        // 登録条件の中に route 名リテラルが実在すること (ファイル全体の文字列一致では弱い)
        $guarded = false;
        foreach ($routeSources as $source) {
            $guarded = $guarded || PrimaryKeyStaticQueryScanner::literalIsInsideGuardedBlock(
                $source,
                $entry->routeName(),
                ['isLocal', 'runningUnitTests'],
            );
        }
        if (! $guarded) {
            $violations[] = $key.' — route 名が local 限定ブロック (isLocal / runningUnitTests) の中に無い';
        }
    }

    expect($violations)->toBe([],
        'LocalOnlyDiagnostics は「route 登録自体が local 限定」かつ「LocalOnly middleware」の'
        .'2 段で production から到達不能であることが条件です。'.PHP_EOL.implode(PHP_EOL, $violations));
});

test('OperatorInvokedConsoleCommand は Console/Commands 配下で signature が実在する', function (): void {
    $violations = [];
    $sources = DirectFetchInventory::sourceFiles();

    foreach (modelDirectFetchPairsFor(DirectFetchJustification::OperatorInvokedConsoleCommand) as $key => [$candidate, $entry]) {
        if (! str_starts_with($candidate->displayPath(), 'Console/Commands/')) {
            $violations[] = $key.' — app/Console/Commands 配下でない';

            continue;
        }
        $commandName = explode(' ', trim($entry->commandSignature()))[0];
        $source = $sources['app/'.$candidate->displayPath()] ?? '';
        if ($commandName === '' || ! str_contains($source, $commandName)) {
            $violations[] = $key.' — commandSignature の command 名がファイルに実在しない: '.$entry->commandSignature();
        }
    }

    expect($violations)->toBe([],
        'OperatorInvokedConsoleCommand は app/Console/Commands 配下 + 実在する command signature が条件です。'
        .PHP_EOL.implode(PHP_EOL, $violations));
});

test('債務 case は補償チェックの実在 (verifiedBy / 呼び出し / marker / todoRef) を伴う', function (): void {
    $violations = [];
    $sources = DirectFetchInventory::sourceFiles();
    $todoSources = file_get_contents(base_path('docs/TODO.md')).file_get_contents(base_path('docs/TODO-closed.md'));

    foreach (modelDirectFetchPairsFor(DirectFetchJustification::PayloadIdWithGlobalExistenceRuleDebt) as $key => [$candidate, $entry]) {
        $verifiedBy = $entry->verifiedBy();
        $path = modelDirectFetchClassPath($verifiedBy);
        $method = modelDirectFetchMethodName($verifiedBy);
        if ($path === null || $method === null || ! array_key_exists($path, $sources)) {
            $violations[] = $key.' — verifiedBy のクラスが実在しない: '.$verifiedBy;

            continue;
        }
        $body = PrimaryKeyStaticQueryScanner::methodBody($sources[$path], $method);
        if ($body === null) {
            $violations[] = $key.' — verifiedBy のメソッドが実在しない: '.$verifiedBy;

            continue;
        }
        $compact = modelDirectFetchCompact($body);
        $hasMarker = false;
        foreach (modelDirectFetchMembershipMarkers() as $marker) {
            $hasMarker = $hasMarker || str_contains($compact, $marker);
        }
        if (! $hasMarker) {
            $violations[] = $key.' — verifiedBy の本文に membership/tenant marker が無い: '.$verifiedBy;
        }

        // 呼び出し側が exact method を呼んでいること (同一メソッド内で検証する形は自己参照で受理する)
        $callsIt = str_contains(modelDirectFetchCompact($candidate->methodSource), '->'.$method.'(')
            || $verifiedBy === modelDirectFetchSelfReference($candidate);
        if (! $callsIt) {
            $violations[] = $key.' — 候補のメソッドが '.$method.'() を呼んでいない';
        }

        if ($entry->validationRule() === '') {
            $violations[] = $key.' — validationRule が空';
        }

        // todoRef は「実在する追跡先」でなければならない (プレースホルダを許さない)。
        // ファイル形式は **devnotes 配下の follow-up-todo.md** に限定する — 任意の既存ファイル
        // (AGENTS.md 等) を許すと「実在する」だけで通ってしまい追跡の役に立たない
        $todoRef = $entry->todoRef();
        $tracked = preg_match('/^aicue:T\d+$/', $todoRef) === 1
            ? str_contains($todoSources, substr($todoRef, 6))
            : preg_match('#^devnotes/[A-Za-z0-9._-]+/follow-up-todo\.md$#', $todoRef) === 1
                && file_exists(base_path($todoRef));
        if (! $tracked) {
            $violations[] = $key.' — todoRef が実在しない: '.$todoRef;
        }
    }

    // ★v1 の限界: `$this->membership->transferOwnership(` の `$this->membership` が
    //   OrganizationMembershipService であることは token 走査では証明できない
    //   (constructor promoted property の型追跡が要る)。本テストは「メソッド名の呼び出しが
    //   候補と同一メソッド内に存在する」「verifiedBy のクラスが実在し当該メソッド本文に
    //   marker がある」「根拠文がある」の 3 点で受理し、**exact class を証明したとは主張しない**。
    expect($violations)->toBe([],
        '債務 case は補償チェックの実在が条件です。'.PHP_EOL.implode(PHP_EOL, $violations));
});

test('債務 case が増殖していない (上限を超えたら是正 TODO を先に進める)', function (): void {
    $debts = array_keys(modelDirectFetchPairsFor(DirectFetchJustification::PayloadIdWithGlobalExistenceRuleDebt));

    expect(count($debts))->toBeLessThanOrEqual(modelDirectFetchDebtCap(),
        'PayloadIdWithGlobalExistenceRuleDebt は「fetch 後に弾く」形であり準拠形ではありません。'
        .'新規に増やさず、org 相対化 (relation 起点) と exists: rule の見直しをセットで行ってください。'
        .PHP_EOL.implode(PHP_EOL, $debts));
});

test('case × predicateKind が許可表の組み合わせに収まっている', function (): void {
    $allowed = modelDirectFetchAllowedPredicateKinds();
    $violations = [];

    foreach (modelDirectFetchMatchedPairs() as $key => [$candidate, $entry]) {
        $kinds = $allowed[$entry->case->value] ?? [];
        if (! in_array($candidate->predicateKind->name, $kinds, true)) {
            $violations[] = $key.' — '.$entry->case->value.' に '.$candidate->predicateKind->name.' は許可されていない';
        }
    }

    expect($violations)->toBe([],
        '許可表に無い case × predicateKind の組み合わせです。'.PHP_EOL.implode(PHP_EOL, $violations));
});

test('同一 fingerprint の候補が重複していない (裁定理由の横滑り防止)', function (): void {
    $groups = [];
    foreach (DirectFetchInventory::candidates() as $candidate) {
        $groups[$candidate->fingerprint()][] = $candidate->chainSource;
    }

    $duplicates = [];
    foreach ($groups as $fingerprint => $chains) {
        if (count($chains) > 1 && ! in_array($fingerprint, DirectFetchInventory::reviewedDuplicateFingerprints(), true)) {
            $duplicates[] = $fingerprint.' (x'.count($chains).')';
        }
    }

    expect($duplicates)->toBe([],
        '同一 fingerprint の候補が複数あります。ordinal 依存になり、裁定理由が別の候補へ'
        .'横滑りしても気付けません。chainSource を確認のうえ '
        .'DirectFetchInventory::reviewedDuplicateFingerprints() へ group 単位で明示登録してください。'
        .PHP_EOL.implode(PHP_EOL, $duplicates));
});

test('範囲外の raw 主キー述語が 0 件である', function (): void {
    $violations = [];

    foreach (DirectFetchInventory::sourceFiles() as $path => $source) {
        if (PrimaryKeyStaticQueryScanner::containsRawPrimaryKeyPredicate($source)) {
            $violations[] = $path;
        }
    }

    expect($violations)->toBe([],
        'whereRaw / whereIntegerInRaw は本 gate の走査範囲外です。主キーを指す raw 述語が'
        .'現れたら、本 gate の検出規則を拡張するか relation 起点に書き直してください。'
        .PHP_EOL.implode(PHP_EOL, $violations));
});

test('非主キー一意列によるモデル解決が 0 件である (provenance 前提の見張り)', function (): void {
    $tables = DirectFetchInventory::modelTables();
    $violations = [];

    foreach (DirectFetchInventory::sourceFiles() as $path => $source) {
        foreach (PrimaryKeyStaticQueryScanner::uniqueColumnResolutions($source, $path, $tables) as $resolution) {
            $violations[] = $resolution;
        }
    }

    // ★本テストは provenance 証明の第 2 段 (元モデルが保証済み provenance に属することの確認) と
    //   同等の証明ではない。「呼び出し側が model を非主キー一意列で untrusted 入力から解決している」
    //   経路が現状 0 件であるという前提が崩れた瞬間に気付くための guard である。
    //   1 件でも生えたら本 gate の provenance 設計 (§4-2(c)) に戻って再検討すること。
    expect($violations)->toBe([],
        'クラス起点クエリが非主キー一意列 (uuid / slug / public_id / ulid / code) でモデルを解決しています。'
        .'この形は主キー同一性の候補に現れないため、本 gate の provenance 除外の前提が崩れます。'
        .PHP_EOL.implode(PHP_EOL, $violations));
});

test('動的列名を使うクラス起点 chain が全て inventory に登録されている (双方向整合)', function (): void {
    $tables = DirectFetchInventory::modelTables();
    $reviewed = DirectFetchInventory::reviewedDynamicColumnPredicates();
    $found = [];

    foreach (DirectFetchInventory::sourceFiles() as $path => $source) {
        foreach (PrimaryKeyStaticQueryScanner::dynamicColumnPredicates($source, $path, $tables) as $descriptor) {
            $found[] = $descriptor;
        }
    }

    $unknown = array_values(array_diff($found, array_keys($reviewed)));
    $stale = array_values(array_diff(array_keys($reviewed), $found));

    // 動的列名は「列が id か」を字句的に決められないため主キー同一性の候補にできない。
    // 0 件ではない (membership binder が実在する) ので 0 件固定ではなく明示 inventory で見張り、
    // `$column = 'id'; User::query()->where($column, $payloadId);` という回避を塞ぐ。
    expect($unknown)->toBe([],
        '動的列名でクラス起点クエリを絞り込んでいる箇所が未登録です。列名を literal にできないか'
        .'検討し、できない場合のみ理由付きで reviewedDynamicColumnPredicates() へ登録してください。'
        .PHP_EOL.implode(PHP_EOL, $unknown));
    expect($stale)->toBe([],
        'reviewedDynamicColumnPredicates() に現存しない記述子が残っています。'.PHP_EOL.implode(PHP_EOL, $stale));

    foreach ($reviewed as $descriptor => $reason) {
        expect(mb_strlen($reason))->toBeGreaterThanOrEqual(DirectFetchJustificationEntry::REASON_MIN_LENGTH, $descriptor);
    }
});

test('走査器が現行コードベースから十分な数の候補を検出している (degenerate PASS 防止)', function (): void {
    $count = count(DirectFetchInventory::candidates());

    expect($count)->toBeGreaterThanOrEqual(modelDirectFetchCandidateFloor(),
        '検出数が下限を割りました。走査器が壊れると inventory の突合が両方 green になり '
        .'gate が静かに無力化します (現状の実測は 34 件)。実測 '.$count.' 件');
});
