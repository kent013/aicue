<?php

declare(strict_types=1);

use Tests\Support\Security\PrimaryKeyPredicateKind;
use Tests\Support\Security\PrimaryKeyStaticQueryScanner;

/*
 * 直 fetch 走査器そのものの positive/negative 固定。
 *
 * ModelDirectFetchInvariantTest (直 fetch の deny-by-default gate) の検出ロジックは
 * **gate 自体がセキュリティ機構**であり、走査器が壊れると inventory の突合が両方 green になって
 * gate が静かに無力化する。母集団走査に依存しない純粋 helper として切り出し、直接テストする。
 *
 * ★本テストの存在理由は「**抜け道 fixture が検出されること**」である。
 *   inventory が green になることは gate が効いている証明にならない。
 *   `Model::find()` だけを禁じても `Model::query()->where('id', $payload)->firstOrFail()` や
 *   builder alias 経由で等価なことができるため、書き方のバリエーションを恒久固定する。
 *
 * ★`outOfScope_*` の fixture は「検出しないことを**保証**している」のではなく
 *   「**既知の範囲外**である」ことを記録している。範囲外の実コード出現は
 *   ModelDirectFetchInvariantTest の 0 件 assertion が検知する。
 *
 * DB 非依存の Unit テスト。
 */

/** テスト用のモデルテーブル (DB::table 起点の絞り込み対象)。 */
function scannerModelTables(): array
{
    return ['users', 'projects', 'plans'];
}

/** クラス本体を `app/` 配下のファイルに見立てて走査する。 */
function scannerCandidates(string $body, string $path = 'app/Services/Sample.php'): array
{
    $source = <<<PHP
    <?php

    namespace App\\Services;

    use App\\Models\\User;
    use App\\Models\\Plan;
    use App\\Models\\Project;
    use Illuminate\\Support\\Facades\\DB;

    class Sample
    {
    {$body}
    }
    PHP;

    return PrimaryKeyStaticQueryScanner::candidates($source, $path, scannerModelTables());
}

/** @return list<string> */
function scannerKeys(array $candidates): array
{
    return array_map(static fn (object $c): string => $c->key, $candidates);
}

// --- positive: 検出されなければならない -------------------------------------

test('述語アンカー: query()->where(id) は検出される', function (): void {
    $candidates = scannerCandidates(<<<'PHP'
        public function run(int $payloadId): void
        {
            User::query()->where('id', $payloadId)->firstOrFail();
        }
    PHP);

    expect(scannerKeys($candidates))->toBe(['Services/Sample.php#run#User.where:id:=:$payloadId#1']);
});

test('builder alias: $q = User::query() 経由でも検出される', function (): void {
    $candidates = scannerCandidates(<<<'PHP'
        public function run(int $payloadId): void
        {
            $q = User::query();
            $q->where('id', $payloadId)->first();
        }
    PHP);

    expect(scannerKeys($candidates))->toContain('Services/Sample.php#run#User.where:id:=:$payloadId#1');
});

test('Service 委譲: scalar 引数を受けた findOrFail も検出される', function (): void {
    $candidates = scannerCandidates(<<<'PHP'
        public function run(int $userId): void
        {
            User::findOrFail($userId);
        }
    PHP);

    expect(scannerKeys($candidates))->toBe(['Services/Sample.php#run#User.findOrFail:$userId#1']);
});

test('qualified 列 / array 形 / 3 引数の等価形も検出される', function (): void {
    $candidates = scannerCandidates(<<<'PHP'
        public function run(int $id): void
        {
            User::query()->where('users.id', $id)->first();
            User::query()->where(['id' => $id])->first();
            User::query()->where([['id', '=', $id]])->first();
            User::query()->where('id', '=', $id)->first();
        }
    PHP);

    expect(count($candidates))->toBe(4);
});

test('destroy / findMany / whereKeyNot は predicateKind を分けて検出される', function (): void {
    $candidates = scannerCandidates(<<<'PHP'
        public function run(int $id, array $ids, int $requestId): void
        {
            User::destroy($id);
            User::findMany($ids);
            User::query()->whereIn('id', $ids)->get();
            User::whereKeyNot($requestId)->get();
        }
    PHP);

    $kinds = array_map(static fn (object $c): string => $c->predicateKind->name, $candidates);
    expect($kinds)->toBe([
        PrimaryKeyPredicateKind::DestructiveIdentity->name,
        PrimaryKeyPredicateKind::MultiIdentity->name,
        PrimaryKeyPredicateKind::MultiIdentity->name,
        PrimaryKeyPredicateKind::IdentityExclusion->name,
    ]);
});

test('DB::table 起点はモデルのテーブルに限って検出される', function (): void {
    $candidates = scannerCandidates(<<<'PHP'
        public function run(int $payloadId, string $tokenId): void
        {
            DB::table('users')->where('id', $payloadId)->first();
            DB::table('users as u')->where('u.id', $payloadId)->first();
            DB::table('oauth_access_tokens')->where('id', $tokenId)->first();
        }
    PHP);

    $roots = array_map(static fn (object $c): string => $c->rootKind, $candidates);
    expect($roots)->toBe(['DB:users', 'DB:users']);
});

test('FQCN 起点 / new 起点 / magic where も検出される', function (): void {
    $candidates = scannerCandidates(<<<'PHP'
        public function run(int $id): void
        {
            \App\Models\User::query()->whereKey($id);
            (new User())->newQuery()->whereKey($id);
            User::whereId($id)->first();
        }
    PHP);

    expect(count($candidates))->toBe(3);
});

test('型を証明できない $dto->user_id は provenance フィルタで除外されない', function (): void {
    $candidates = scannerCandidates(<<<'PHP'
        public function run(object $dto): void
        {
            User::query()->whereKey($dto->user_id)->first();
        }
    PHP);

    expect(scannerKeys($candidates))->toBe(['Services/Sample.php#run#User.whereKey:$dto->user_id#1']);
});

test('builder alias は再代入で取り消さない (再代入で gate を黙らせる回避を許さない)', function (): void {
    $branching = scannerCandidates(<<<'PHP'
        public function run(int $id, bool $x, $other): void
        {
            $q = User::query();
            if ($x) {
                $q = $other;
            }
            $q->whereKey($id);
        }
    PHP);
    expect(scannerKeys($branching))->toContain('Services/Sample.php#run#User.whereKey:$id#1');

    $straight = scannerCandidates(<<<'PHP'
        public function run(int $id, $other): void
        {
            $q = User::query();
            $q = $other;
            $q->whereKey($id);
        }
    PHP);
    expect(scannerKeys($straight))->toContain('Services/Sample.php#run#User.whereKey:$id#1');
});

test('route closure 内の直 fetch は疑似スコープ付きで検出される', function (): void {
    $source = <<<'PHP'
    <?php

    use App\Models\User;
    use Illuminate\Support\Facades\Route;

    Route::post('/x', function () {
        User::findOrFail(request('user_id'));
    });
    PHP;

    $candidates = PrimaryKeyStaticQueryScanner::candidates($source, 'routes/web.php', scannerModelTables());

    expect(scannerKeys($candidates))
        ->toBe(["routes/web.php#__closure1#User.findOrFail:request('user_id')#1"]);
});

test('予約語名のメソッドでもスコープ名がずれない', function (): void {
    $candidates = scannerCandidates(<<<'PHP'
        public static function for(int $id): void
        {
            User::query()->find($id);
        }
    PHP);

    expect(scannerKeys($candidates))->toBe(['Services/Sample.php#for#User.find:$id#1']);
});

test('or 系 / 否定系の列述語も検出される (片方だけ見ると素通りする)', function (): void {
    $candidates = scannerCandidates(<<<'PHP'
        public function run(int $payloadId, array $ids): void
        {
            User::query()->orWhere('id', $payloadId)->first();
            User::query()->orWhereIn('id', $ids)->get();
            User::query()->whereNotIn('id', $ids)->get();
            User::query()->where('id', '!=', $payloadId)->get();
            User::query()->orWhereKey($payloadId)->first();
        }
    PHP);

    $kinds = array_map(static fn (object $c): string => $c->predicateKind->name, $candidates);
    expect($kinds)->toBe([
        PrimaryKeyPredicateKind::SingleIdentity->name,
        PrimaryKeyPredicateKind::MultiIdentity->name,
        PrimaryKeyPredicateKind::IdentityExclusion->name,
        PrimaryKeyPredicateKind::IdentityExclusion->name,
        PrimaryKeyPredicateKind::SingleIdentity->name,
    ]);
});

test('group use / 複数 use でもモデルが解決される (書き方で候補を消せない)', function (): void {
    $group = <<<'PHP'
    <?php

    namespace App\Services;

    use App\Models\{User, Project};

    class Sample
    {
        public function run(int $payloadId): void
        {
            User::find($payloadId);
        }
    }
    PHP;
    expect(scannerKeys(PrimaryKeyStaticQueryScanner::candidates($group, 'app/Services/Sample.php', scannerModelTables())))
        ->toBe(['Services/Sample.php#run#User.find:$payloadId#1']);

    $aliased = <<<'PHP'
    <?php

    namespace App\Services;

    use App\Models\{User as U, Project};

    class Sample
    {
        public function run(int $payloadId): void
        {
            U::find($payloadId);
        }
    }
    PHP;
    expect(scannerKeys(PrimaryKeyStaticQueryScanner::candidates($aliased, 'app/Services/Sample.php', scannerModelTables())))
        ->toBe(['Services/Sample.php#run#User.find:$payloadId#1']);
});

test('chain が削除で終わると DestructiveIdentity になる (Single のまま禁止表を素通りさせない)', function (): void {
    $candidates = scannerCandidates(<<<'PHP'
        public function run(): void
        {
            User::query()->whereKey($this->userId)->delete();
        }
    PHP);

    expect($candidates[0]->predicateKind)->toBe(PrimaryKeyPredicateKind::DestructiveIdentity);
});

test('array 形の否定演算子も IdentityExclusion として検出される', function (): void {
    $candidates = scannerCandidates(<<<'PHP'
        public function run(int $payloadId): void
        {
            User::query()->where([['id', '!=', $payloadId]])->get();
            User::query()->orWhere([['id', '<>', $payloadId]])->get();
        }
    PHP);

    $kinds = array_map(static fn (object $c): string => $c->predicateKind->name, $candidates);
    expect($kinds)->toBe([
        PrimaryKeyPredicateKind::IdentityExclusion->name,
        PrimaryKeyPredicateKind::IdentityExclusion->name,
    ]);
});

test('動的列名は候補にならないが dynamicColumnPredicates が値引数と ordinal 付きで列挙する', function (): void {
    $body = <<<'PHP'
        public function run(int $payloadId, int $otherId): void
        {
            $column = 'id';
            User::query()->where($column, $payloadId)->first();
            User::query()->where($column, $otherId)->first();
            User::query()->where([$column => $payloadId])->first();
            User::query()->where([[$column, '=', $payloadId]])->first();
        }
    PHP;
    $source = <<<PHP
    <?php

    namespace App\\Services;

    use App\\Models\\User;

    class Sample
    {
    {$body}
    }
    PHP;

    expect(PrimaryKeyStaticQueryScanner::candidates($source, 'app/Services/Sample.php', scannerModelTables()))->toBe([]);

    // 値引数まで fingerprint に含めないと、同一メソッド内の別呼び出しへ裁定理由が横滑りする
    expect(PrimaryKeyStaticQueryScanner::dynamicColumnPredicates($source, 'app/Services/Sample.php', scannerModelTables()))
        ->toBe([
            'Services/Sample.php#run#User.where:$column=>$payloadId#1',
            'Services/Sample.php#run#User.where:$column=>$otherId#1',
            'Services/Sample.php#run#User.where:$column=>$payloadId#2',
            'Services/Sample.php#run#User.where:$column=>$payloadId#3',
        ]);
});

test('findOr も主キー同一性述語として検出される', function (): void {
    $candidates = scannerCandidates(<<<'PHP'
        public function run(int $payloadId): void
        {
            User::findOr($payloadId, fn () => null);
        }
    PHP);

    expect(scannerKeys($candidates))->toBe(['Services/Sample.php#run#User.findOr:$payloadId#1']);
});

test('array 形の主キー条件は 2 件目以降も候補になる', function (): void {
    $candidates = scannerCandidates(<<<'PHP'
        public function run(int $trustedId, int $payloadId): void
        {
            User::where([
                ['id', '=', $trustedId],
                ['id', '=', $payloadId],
            ])->first();
        }
    PHP);

    expect(scannerKeys($candidates))->toBe([
        'Services/Sample.php#run#User.where:id:=:$trustedId#1',
        'Services/Sample.php#run#User.where:id:=:$payloadId#1',
    ]);
});

test('provenance は代入順序と再代入を守る (後段の安全な代入で前段を安全扱いしない)', function (): void {
    // 安全な代入が候補より**後**にある
    $after = scannerCandidates(<<<'PHP'
        public function run($input): void
        {
            $dto = $input;
            User::find($dto->user_id);
            $dto = User::query()->firstOrFail();
        }
    PHP);
    expect(scannerKeys($after))->toBe(['Services/Sample.php#run#User.find:$dto->user_id#1']);

    // 安全な代入の**後**に untrusted 値へ再代入
    $reassigned = scannerCandidates(<<<'PHP'
        public function run($input): void
        {
            $dto = User::query()->firstOrFail();
            $dto = $input;
            User::find($dto->user_id);
        }
    PHP);
    expect(scannerKeys($reassigned))->toBe(['Services/Sample.php#run#User.find:$dto->user_id#1']);
});

test('PHPDoc @var は宣言されたメソッドの外へ効かない', function (): void {
    $source = <<<'PHP'
    <?php

    namespace App\Services;

    use App\Models\User;

    class Sample
    {
        public function safe(): void
        {
            /** @var User $dto */
            $dto = User::query()->first();
        }

        public function unsafe(object $dto): void
        {
            User::find($dto->user_id);
        }
    }
    PHP;

    expect(scannerKeys(PrimaryKeyStaticQueryScanner::candidates($source, 'app/Services/Sample.php', scannerModelTables())))
        ->toBe(['Services/Sample.php#unsafe#User.find:$dto->user_id#1']);
});

test('sameMethodQuery / tenantScoped の副条件も代入順序を守る', function (): void {
    // 走査クエリの代入が候補より**後**にある
    $late = scannerCandidates(<<<'PHP'
        public function run($input): void
        {
            $ids = $input->ids();
            foreach ($ids as $id) {
                User::find($id);
            }
            $ids = User::query()->pluck('id');
        }
    PHP);
    expect(PrimaryKeyStaticQueryScanner::identityDerivedFromSameMethodQuery($late[0]))->toBeFalse();

    // 安全な代入が候補より後にある (テナントスコープ側)
    $lateScoped = scannerCandidates(<<<'PHP'
        public function run(Project $project, $input): void
        {
            $id = $input->id;
            User::find($id);
            $id = $project->manuals()->value('id');
        }
    PHP);
    expect(PrimaryKeyStaticQueryScanner::identityAssignedFromRelationQuery($lateScoped[0]))->toBeFalse();
});

// --- negative: 検出してはならない -------------------------------------------

test('relation 起点は検出されない', function (): void {
    expect(scannerCandidates(<<<'PHP'
        public function run($organization, int $id): void
        {
            $organization->users()->whereKey($id)->first();
        }
    PHP))->toBe([]);
});

test('型付き引数のモデル由来 id は検出されない (provenance 証明あり)', function (): void {
    expect(scannerCandidates(<<<'PHP'
        public function run(Project $project): void
        {
            Project::whereKey($project->id)->lockForUpdate()->firstOrFail();
        }
    PHP))->toBe([]);
});

test('順序比較 (主キー同一性でない) は検出されない', function (): void {
    expect(scannerCandidates(<<<'PHP'
        public function run($manual, int $cursor): void
        {
            $manual->renderJobs()->where('id', '>', $cursor)->get();
            User::query()->where('id', '>', $cursor)->get();
        }
    PHP))->toBe([]);
});

test('主キー以外の列による絞り込みは検出されない', function (): void {
    expect(scannerCandidates(<<<'PHP'
        public function run(string $code): void
        {
            Plan::query()->where('code', $code)->first();
        }
    PHP))->toBe([]);
});

test('docblock / コメント中の記述は検出されない', function (): void {
    expect(scannerCandidates(<<<'PHP'
        /**
         * User::destroy($id) を呼ぶ (User::query()->where('id', $id) と等価)。
         */
        public function run(): void
        {
            // User::findOrFail($id) はここでは使わない
            $this->noop();
        }
    PHP))->toBe([]);
});

test('Models 集合に無い同名クラスは検出されない (import 裏取り)', function (): void {
    $source = <<<'PHP'
    <?php

    namespace App\Services;

    use SomeOtherPackage\User;

    class Sample
    {
        public function run(int $id): void
        {
            User::find($id);
        }
    }
    PHP;

    expect(PrimaryKeyStaticQueryScanner::candidates($source, 'app/Services/Sample.php', scannerModelTables()))->toBe([]);
});

test('静的起点から代入されていない変数は builder alias にならない', function (): void {
    expect(scannerCandidates(<<<'PHP'
        public function run($other, int $id): void
        {
            $q = $other->users();
            $q->whereKey($id)->first();
        }
    PHP))->toBe([]);
});

test('実行系で終わる代入は builder alias にならず、結果はモデルとして provenance 証明になる', function (): void {
    expect(scannerCandidates(<<<'PHP'
        public function run(Project $project, int $categoryId): void
        {
            $locked = Project::whereKey($project->id)->lockForUpdate()->firstOrFail();
            $locked->categories()->whereKey($categoryId)->firstOrFail();
        }
    PHP))->toBe([]);
});

// --- outOfScope: 「保証」ではなく「既知の範囲外」 -----------------------------

test('outOfScope_whereRaw は候補にならないが 0 件 assertion 側で見張られる', function (): void {
    expect(scannerCandidates(<<<'PHP'
        public function run(int $id): void
        {
            User::query()->whereRaw('id = ?', [$id])->first();
        }
    PHP))->toBe([]);

    expect(PrimaryKeyStaticQueryScanner::containsRawPrimaryKeyPredicate(
        "User::query()->whereRaw('id = ?', [\$id])->first();"
    ))->toBeTrue();

    // 非リテラル引数は中身が読めないので無条件 fail させる
    expect(PrimaryKeyStaticQueryScanner::containsRawPrimaryKeyPredicate(
        'User::query()->whereRaw($sql)->first();'
    ))->toBeTrue();

    // quoted identifier / raw variant も同じ列指定なので見逃さない
    foreach ([
        "User::query()->whereRaw('`id` = ?', [\$id]);",
        "User::query()->whereRaw('\"id\" = ?', [\$id]);",
        "User::query()->orWhereIntegerInRaw('id', \$ids);",
        "User::query()->whereIntegerNotInRaw('id', \$ids);",
    ] as $snippet) {
        expect(PrimaryKeyStaticQueryScanner::containsRawPrimaryKeyPredicate($snippet))->toBeTrue($snippet);
    }

    expect(PrimaryKeyStaticQueryScanner::containsRawPrimaryKeyPredicate(
        "User::query()->whereRaw('lower(email) = ?', [\$email])->first();"
    ))->toBeFalse();
});

test('sameMethodQuery の副条件は任意 object / 任意クラスの静的メソッド結果では通らない', function (): void {
    $loose = scannerCandidates(<<<'PHP'
        public function run($input): void
        {
            $ids = $input->ids();
            foreach ($ids as $id) {
                User::find($id);
            }
        }
    PHP);
    expect($loose)->not->toBe([]);
    expect(PrimaryKeyStaticQueryScanner::identityDerivedFromSameMethodQuery($loose[0]))->toBeFalse();

    // `Payload::ids()` は Models 集合に無いので「クエリ結果」ではない
    $staticCall = scannerCandidates(<<<'PHP'
        public function run(): void
        {
            $ids = \App\Support\Payload::ids();
            foreach ($ids as $id) {
                User::find($id);
            }
        }
    PHP);
    expect($staticCall)->not->toBe([]);
    expect(PrimaryKeyStaticQueryScanner::identityDerivedFromSameMethodQuery($staticCall[0]))->toBeFalse();

    $scan = scannerCandidates(<<<'PHP'
        public function run(): void
        {
            $ids = User::query()->where('active', true)->pluck('id');
            foreach ($ids as $id) {
                User::find($id);
            }
        }
    PHP);
    expect(PrimaryKeyStaticQueryScanner::identityDerivedFromSameMethodQuery($scan[0]))->toBeTrue();
});

test('literalIsInsideGuardedBlock は if 条件に紐づく肯定ブロックだけを受理する', function (): void {
    $guards = ['isLocal', 'runningUnitTests'];

    $source = <<<'PHP'
    <?php

    if (app()->isLocal() || app()->runningUnitTests()) {
        Route::post('/debug/login/{userId}', [C::class, 'loginAs'])->name('debug.login-as');
    }

    Route::get('/health', [C::class, 'health'])->name('health');
    PHP;
    expect(PrimaryKeyStaticQueryScanner::literalIsInsideGuardedBlock($source, 'debug.login-as', $guards))->toBeTrue();
    expect(PrimaryKeyStaticQueryScanner::literalIsInsideGuardedBlock($source, 'health', $guards))->toBeFalse();

    // guard が条件式に属さない (ただの代入) ブロックは受理しない
    $detached = <<<'PHP'
    <?php

    $local = app()->isLocal();

    if (true) {
        Route::post('/debug/login/{userId}', [C::class, 'loginAs'])->name('debug.login-as');
    }
    PHP;
    expect(PrimaryKeyStaticQueryScanner::literalIsInsideGuardedBlock($detached, 'debug.login-as', $guards))->toBeFalse();

    // 否定条件 (`if (! app()->isLocal())`) は「local 限定」の逆なので受理しない
    $negated = <<<'PHP'
    <?php

    if (! app()->isLocal()) {
        Route::post('/debug/login/{userId}', [C::class, 'loginAs'])->name('debug.login-as');
    }
    PHP;
    expect(PrimaryKeyStaticQueryScanner::literalIsInsideGuardedBlock($negated, 'debug.login-as', $guards))->toBeFalse();
});

test('provenance 証明は任意 object の chain 結果をモデルとみなさない', function (): void {
    // `$dto = $input->payload()->dto();` を relation 起点とみなすと `$dto->user_id` が
    // 「モデル由来」に化け、候補が inventory 登録すら要求されずに消える (最悪の fail-open)
    $loose = scannerCandidates(<<<'PHP'
        public function run($input): void
        {
            $dto = $input->payload()->dto();
            User::find($dto->user_id);
        }
    PHP);
    expect(scannerKeys($loose))->toBe(['Services/Sample.php#run#User.find:$dto->user_id#1']);

    // 基底がモデルと証明できる場合だけ relation 起点として受理する
    $proven = scannerCandidates(<<<'PHP'
        public function run(Project $project): void
        {
            $manual = $project->manuals()->firstOrFail();
            User::find($manual->user_id);
        }
    PHP);
    expect($proven)->toBe([]);
});

test('IdDerivedFromTenantScopedQuery の副条件も基底がモデルでなければ通らない', function (): void {
    $loose = scannerCandidates(<<<'PHP'
        public function run($input): void
        {
            $id = $input->payload()->value('id');
            User::find($id);
        }
    PHP);
    expect($loose)->not->toBe([]);
    expect(PrimaryKeyStaticQueryScanner::identityAssignedFromRelationQuery($loose[0]))->toBeFalse();

    $scoped = scannerCandidates(<<<'PHP'
        public function run(Project $project): void
        {
            $id = $project->manuals()->value('id');
            User::find($id);
        }
    PHP);
    expect(PrimaryKeyStaticQueryScanner::identityAssignedFromRelationQuery($scoped[0]))->toBeTrue();
});

test('outOfScope_動的列名は候補にならない', function (): void {
    expect(scannerCandidates(<<<'PHP'
        public function run(string $col, int $id): void
        {
            User::query()->where($col, $id)->first();
        }
    PHP))->toBe([]);
});

// --- 副条件ヘルパ -----------------------------------------------------------

test('request accessor 判定: 入力読み出しだけを accessor とみなす', function (): void {
    $accessors = [
        'public function run(): void { $x = $request->input("a"); User::find($x); }',
        'public function run(): void { $x = $request->query("a"); User::find($x); }',
        'public function run(): void { $x = $request->validated()["a"]; User::find($x); }',
        'public function run(): void { User::find(request("user_id")); }',
        'public function run(): void { User::find(request()->input("user_id")); }',
    ];
    foreach ($accessors as $body) {
        $candidates = scannerCandidates($body);
        expect($candidates)->not->toBe([], $body);
        expect(PrimaryKeyStaticQueryScanner::methodIsFreeOfRequestAccessors($candidates[0]))->toBeFalse($body);
    }

    // $request の素通し / attributes バッグは入力読み出しではない
    $passthrough = scannerCandidates(
        'public function run($request, int $id): void { $this->actor($request); User::find($id); }'
    );
    expect(PrimaryKeyStaticQueryScanner::methodIsFreeOfRequestAccessors($passthrough[0]))->toBeTrue();

    $attributes = scannerCandidates(
        'public function run(): void { $id = request()->attributes->get("org"); User::find($id); }'
    );
    expect(PrimaryKeyStaticQueryScanner::methodIsFreeOfRequestAccessors($attributes[0]))->toBeTrue();
});

test('所有者スコープ判定は右辺 provenance まで見る', function (): void {
    $scoped = scannerCandidates(<<<'PHP'
        public function run(User $user, int $id): void
        {
            Project::query()->whereKey($id)->where('user_id', $user->getKey())->first();
        }
    PHP);
    expect(PrimaryKeyStaticQueryScanner::hasOwnerScopedConstraint($scoped[0]))->toBeTrue();

    // 右辺が request 由来の値なら所有者スコープとみなさない
    $unscoped = scannerCandidates(<<<'PHP'
        public function run(int $id, int $requestOrgId): void
        {
            Project::query()->whereKey($id)->where('organization_id', $requestOrgId)->first();
        }
    PHP);
    expect(PrimaryKeyStaticQueryScanner::hasOwnerScopedConstraint($unscoped[0]))->toBeFalse();

    // 所有者列でない制約では通らない
    $irrelevant = scannerCandidates(<<<'PHP'
        public function run(User $user, int $id): void
        {
            Project::query()->whereKey($id)->where('active', true)->first();
        }
    PHP);
    expect(PrimaryKeyStaticQueryScanner::hasOwnerScopedConstraint($irrelevant[0]))->toBeFalse();
});

test('非主キー一意列による解決は列挙され、Plan の code だけ除外される', function (): void {
    $source = <<<'PHP'
    <?php

    namespace App\Services;

    use App\Models\Plan;
    use App\Models\Project;

    class Sample
    {
        public function run(string $value): void
        {
            Plan::query()->where('code', $value)->first();
            Project::query()->where('slug', $value)->first();
            Project::query()->whereUuid($value)->first();
        }
    }
    PHP;

    expect(PrimaryKeyStaticQueryScanner::uniqueColumnResolutions($source, 'app/Services/Sample.php', scannerModelTables()))
        ->toBe([
            'Services/Sample.php#run#Project.where:slug',
            'Services/Sample.php#run#Project.whereUuid:uuid',
        ]);
});

test('methodBody は指定メソッドの本文だけを切り出す', function (): void {
    $source = <<<'PHP'
    <?php

    namespace App\Services;

    class Sample
    {
        public function alpha(): void
        {
            $this->one();
        }

        public function beta(): void
        {
            $this->two();
        }
    }
    PHP;

    $body = PrimaryKeyStaticQueryScanner::methodBody($source, 'beta');
    expect($body)->not->toBeNull();
    expect(str_contains((string) $body, 'two'))->toBeTrue();
    expect(str_contains((string) $body, 'one'))->toBeFalse();
});
