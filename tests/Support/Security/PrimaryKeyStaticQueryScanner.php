<?php

declare(strict_types=1);

namespace Tests\Support\Security;

/**
 * クラス起点の主キー同一性クエリ (`ClassRootedPrimaryKeyQuery`) の字句解析器。
 *
 * `ModelDirectFetchInvariantTest` (直 fetch の deny-by-default gate) の検出ロジックを
 * 母集団走査から切り離した純粋 helper。「母集団走査と突合 = テスト、字句解析 = 本 helper」
 * という `AuthorizationMarkerScanner` と同じ責務分離で、解析器そのものの positive/negative は
 * `tests/Unit/Architecture/PrimaryKeyStaticQueryScannerTest.php` が恒久固定する
 * (gate 自体がセキュリティ機構であり、走査器が壊れたら gate は静かに無力化するため)。
 *
 * ★設計判断:
 *  - 正規表現ではなく `token_get_all` の状態機械にする。コメント / docblock 中の
 *    `AuthenticatedSessionController::destroy()` を誤検出した実例があるため、
 *    コメントはトークン段で除去する (文字列リテラルは列名照合に要るのでトークンとしては残すが、
 *    その**中身をコードとして解釈しない**)。
 *  - 検出アンカーはメソッド名の列挙ではなく「**静的起点 + 主キー同一性述語**」という意味に張る。
 *    `Model::find()` だけを禁じても `Model::query()->where('id', $payload)->firstOrFail()` で
 *    等価なことができる。
 *  - `use` import による裏取りを行う。これが無いと同名の別クラス
 *    (`SomeOtherPackage\User::find()`) を誤検出する。
 *
 * ★builder alias の fail 方向 (重要):
 *  `$q = User::query();` のように**一度でも静的起点から代入された変数**は、同一スコープ内の
 *  以降の使用をすべて候補として扱う。**再代入があっても取り消さない**。
 *  取り消す実装にすると `$q = User::query(); if ($x) { $q = $other; } $q->whereKey($id);` が
 *  検出されなくなり、「再代入すれば gate を黙らせられる」という最も安易な回避手段になる
 *  (= fail-open)。**誤検出は分類 1 行で解消できるが、検出漏れは永久に気付けない**という
 *  非対称性を根拠に、過剰検出側 (fail-closed) へ倒している。
 *
 * ★本 helper の限界 (意図的な線引き。テストの docblock にも明記する):
 *  - **到達可能性を判定しない** (`if (false) { … }` 中の候補も候補になる)。
 *  - `whereRaw` / `whereIntegerInRaw` / 動的列名 (`where($col, $x)`) は**範囲外**。
 *    範囲外を放置しないため {@see self::containsRawPrimaryKeyPredicate()} が
 *    0 件 assertion 用の検出を提供する。
 *  - alias 追跡は同一スコープ内の単純代入のみ。引数渡し・プロパティ代入・
 *    メソッドをまたぐ伝播は追跡しない。
 *  - provenance 証明は「変数が `App\Models\*` である」ことまで (第 1 段) しか行わない。
 *    「その元モデルが保証済み provenance に属する」ことの証明 (第 2 段) は v1 では実装せず、
 *    代償措置として {@see self::uniqueColumnResolutions()} の 0 件固定を置く。
 *  - 非 bracketed namespace (`namespace App\Foo;` 形式) を前提とする
 *    (`AuthorizationMarkerScanner` と同じ前提。Pint が強制している)。
 */
final class PrimaryKeyStaticQueryScanner
{
    /** 所有者/テナント制約とみなす列 (`OwnerScopedQueryConstraint` の許可 signature)。 */
    private const OWNER_COLUMNS = ['organization_id', 'user_id', 'team_id', 'project_id'];

    /**
     * 非主キー一意列 (provenance 前提の見張り用)。
     *
     * `code` を含めるのは、列名だけで丸ごと除外すると将来
     * `OrganizationInvitation::where('code', $payload)` のような**テナント資源**が生えても
     * 検知できなくなるため。グローバルカタログである `Plan` 起点のみ {@see self::CATALOG_ROOTS}
     * で除外する。CipherSweet の `whereBlind(…)` は列名を取らないため本一覧に現れない
     * (AGENTS.md セキュリティ不変条件「PII は CipherSweet」が別途統制する)。
     */
    private const UNIQUE_COLUMNS = ['uuid', 'slug', 'public_id', 'ulid', 'code'];

    /** `code` 列による解決を除外してよい root (グローバルカタログでテナント資源でない)。 */
    private const CATALOG_ROOTS = ['Plan', 'DB:plans'];

    /** 列名を第 1 引数に取る述語 (or 系も含める。片方だけ見ると `orWhere('id', …)` が素通りする)。 */
    private const COLUMN_PREDICATES = [
        'where', 'orWhere', 'firstWhere', 'whereIn', 'orWhereIn', 'whereNotIn', 'orWhereNotIn',
    ];

    /** raw SQL を直接渡す述語 (本 gate の範囲外。0 件 assertion で見張る)。 */
    private const RAW_PREDICATES = [
        'whereRaw', 'orWhereRaw', 'havingRaw', 'orHavingRaw',
        'whereIntegerInRaw', 'orWhereIntegerInRaw', 'whereIntegerNotInRaw', 'orWhereIntegerNotInRaw',
    ];

    /**
     * chain を「取得」ではなく「削除」に変える終端 (`whereKey($id)->delete()`)。
     *
     * `update` は含めない — CAS 更新 (`whereKey($id)->where('status', …)->update(…)`) は
     * 識別子による削除とは危険度が違い、含めると既存の正当な CAS 経路まで
     * DestructiveIdentity になって許可表の意味が薄れるため。
     */
    private const DESTRUCTIVE_TERMINATORS = ['delete', 'forceDelete', 'restore', 'truncate'];

    /** DB ファサードの完全修飾名 (同名の別クラスによる誤検出を防ぐ)。 */
    private const DB_FACADE = 'Illuminate\Support\Facades\DB';

    /**
     * クエリを**実行して結果を返す**メソッド (builder alias の伝播を打ち切る境界)。
     *
     * ここで終わる代入式の左辺は Builder ではなく Model / Collection / scalar である。
     */
    private const EXECUTOR_METHODS = [
        'get', 'first', 'firstOrFail', 'firstOr', 'firstOrCreate', 'firstOrNew', 'firstWhere',
        'sole', 'find', 'findOrFail', 'findOr', 'findOrNew', 'findMany', 'value', 'pluck',
        'exists', 'doesntExist', 'count', 'sum', 'max', 'min', 'avg', 'paginate', 'simplePaginate',
        'cursorPaginate', 'chunk', 'chunkById', 'each', 'create', 'update', 'delete', 'destroy',
        'toArray', 'toBase', 'all', 'lazy', 'cursor', 'increment', 'decrement', 'insert', 'upsert',
    ];

    /**
     * request の「入力を読む」accessor。
     *
     * `user()` / `attributes` はここに含めない (前者は認証済み actor、後者は middleware が
     * サーバ側で確定させたバッグであり、どちらも client 由来の payload ではないため)。
     */
    private const REQUEST_INPUT_ACCESSORS = [
        'input', 'query', 'post', 'json', 'all', 'only', 'except', 'validated', 'safe',
        'has', 'hasAny', 'filled', 'missing', 'boolean', 'string', 'integer', 'float',
        'date', 'enum', 'collect', 'get', 'header', 'headers', 'cookie', 'route',
        'segment', 'segments', 'path', 'url', 'fullUrl', 'getContent', 'file', 'allFiles',
    ];

    /**
     * @param  list<array{id: int, text: string, line: int}>  $tokens
     * @param  list<string>  $modelTables
     * @param  array<string, string>  $imports  短縮名 => FQCN
     * @param  list<array{name: string, start: int, end: int}>  $scopes
     * @param  list<int>  $scopeIdOf  トークン位置 => scope 添字 (-1 = ファイル直下)
     * @param  list<array{var: string, type: string, line: int}>  $docVarDeclarations  `@var` 宣言 (行番号付き)
     * @param  array<string, string>  $methodReturnTypes  メソッド名 => 戻り値型宣言
     */
    private function __construct(
        private readonly array $tokens,
        private readonly string $relativePath,
        private readonly array $modelTables,
        private readonly array $imports,
        private readonly string $namespace,
        private readonly ?string $selfClass,
        private readonly array $scopes,
        private readonly array $scopeIdOf,
        private readonly array $docVarDeclarations,
        private readonly array $methodReturnTypes,
    ) {}

    /** @var array<int, list<array{index: int, set: list<string>}>> スコープごとの provenance 時系列 */
    private array $provenCache = [];

    /** @var array<int, list<array{index: int, set: list<string>}>> スコープごとのクエリ結果変数の時系列 */
    private array $queryResultCache = [];

    /**
     * ファイル 1 本から候補 (ClassRootedPrimaryKeyQuery) を抽出する。
     *
     * @param  string  $source  PHP ソース全文
     * @param  string  $relativePath  リポジトリ相対パス (候補 key の生成に使う)
     * @param  list<string>  $modelTables  `App\Models\*` に対応するテーブル名
     * @return list<PrimaryKeyStaticQueryCandidate>
     */
    public static function candidates(string $source, string $relativePath, array $modelTables): array
    {
        return self::make($source, $relativePath, $modelTables)->scan();
    }

    /**
     * 非主キー一意列 (`uuid` / `slug` / `public_id` / `ulid` / `code`) による解決の一覧。
     *
     * provenance 証明の第 2 段を v1 で実装しないことの代償措置。
     * 「呼び出し側が model を非主キー一意列で untrusted 入力から解決している」経路が
     * 生えた瞬間に気付くための見張りであり、**第 2 段と同等の証明ではない**。
     *
     * @param  list<string>  $modelTables
     * @return list<string> 人が読める記述子 (`Models/Plan.php#lookup#Plan.where:slug`)
     */
    public static function uniqueColumnResolutions(string $source, string $relativePath, array $modelTables): array
    {
        return self::make($source, $relativePath, $modelTables)->scanUniqueColumns();
    }

    /**
     * クラス起点 chain のうち、列名が**動的** (変数 / 定数式) な述語の一覧。
     *
     * 動的列名は列が `id` かを字句的に決められないため候補にできない (範囲外) が、
     * 放置すると `$column = 'id'; User::query()->where($column, $payloadId);` で
     * gate を黙らせられる。0 件ではない (membership binder が実在する) ため
     * 「0 件固定」ではなく**明示 inventory**で見張る。
     *
     * @param  list<string>  $modelTables
     * @return list<string> 人が読める記述子
     */
    public static function dynamicColumnPredicates(string $source, string $relativePath, array $modelTables): array
    {
        return self::make($source, $relativePath, $modelTables)->scanDynamicColumns();
    }

    /**
     * 文字列リテラル `$literal` が、`$guards` のいずれかを含む条件式のブロック内に現れるか。
     *
     * `routes/*.php` で「この route は local 限定の登録条件の中にある」ことを
     * ファイル全体の文字列一致より強く確認するために使う (波括弧の対応をトークンで数える)。
     *
     * @param  list<string>  $guards
     */
    public static function literalIsInsideGuardedBlock(string $source, string $literal, array $guards): bool
    {
        $tokens = self::significantTokens($source);
        $count = count($tokens);

        for ($i = 0; $i < $count; $i++) {
            // **`if` の条件式**に guard があることまで確認する。任意の `{ … }` を受理すると
            // `$local = app()->isLocal(); if (true) { … }` のような無関係なブロックでも通る
            if ($tokens[$i]['id'] !== T_IF || ($tokens[$i + 1]['text'] ?? '') !== '(') {
                continue;
            }
            $conditionEnd = self::matchingParenthesis($tokens, $i + 1);
            if ($conditionEnd === null || ($tokens[$conditionEnd + 1]['text'] ?? '') !== '{') {
                continue;
            }
            $hasGuard = false;
            $negated = false;
            for ($k = $i + 1; $k < $conditionEnd; $k++) {
                if ($tokens[$k]['id'] === T_STRING && in_array($tokens[$k]['text'], $guards, true)) {
                    $hasGuard = true;
                }
                // 否定条件 (`if (! app()->isLocal())`) は「local 限定」の逆なので受理しない
                if ($tokens[$k]['text'] === '!') {
                    $negated = true;
                }
            }
            if (! $hasGuard || $negated) {
                continue;
            }
            $close = self::matchingBrace($tokens, $conditionEnd + 1);
            if ($close === null) {
                continue;
            }
            for ($k = $conditionEnd + 1; $k <= $close; $k++) {
                if ($tokens[$k]['id'] === T_CONSTANT_ENCAPSED_STRING
                    && self::literalValue($tokens[$k]['text']) === $literal) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * ソース中に**範囲外**の raw 主キー述語があるか。
     *
     * `whereRaw` / `whereIntegerInRaw` の第 1 引数が文字列リテラルなら `id` 列への言及を照合し、
     * **非リテラル (変数・連結) なら中身が読めないので無条件に true** を返す
     * (範囲外の経路が実際に生えた合図として fail させるため)。
     */
    public static function containsRawPrimaryKeyPredicate(string $source): bool
    {
        $tokens = self::significantTokens($source);
        $count = count($tokens);

        for ($i = 0; $i < $count; $i++) {
            if ($tokens[$i]['id'] !== T_STRING) {
                continue;
            }
            if (! in_array($tokens[$i]['text'], self::RAW_PREDICATES, true)) {
                continue;
            }
            if (($tokens[$i + 1]['text'] ?? '') !== '(') {
                continue;
            }
            $prev = $tokens[$i - 1]['text'] ?? '';
            if ($prev !== '->' && $prev !== '?->' && $prev !== '::') {
                continue;
            }

            $args = self::argumentRanges($tokens, $i + 1);
            if ($args === []) {
                return true; // 引数無し = 想定外の形。読めないので fail させる
            }
            [$start, $end] = $args[0];
            if ($start !== $end || $tokens[$start]['id'] !== T_CONSTANT_ENCAPSED_STRING) {
                return true; // 非リテラル引数は中身が読めない
            }
            // quoted identifier (`` `id` `` / `"id"` / `[id]`) も同じ列指定なので引用符を空白に潰し、
            // 未引用識別子は大小文字を区別しない (PostgreSQL の `ID` は `id` と同じ列) ので小文字化する
            $sql = strtolower(str_replace(['`', '"', '[', ']'], ' ', self::literalValue($tokens[$start]['text'])));
            if (preg_match('/(^|[.\s(])id\b/', $sql) === 1) {
                return true;
            }
        }

        return false;
    }

    /**
     * 指定メソッドの**本文だけ**を切り出す (存在オラクル case の `verifiedBy` 検証に使う)。
     *
     * 同名メソッドが複数ある場合は最初の 1 つを返す。
     */
    public static function methodBody(string $source, string $methodName): ?string
    {
        $tokens = self::significantTokens($source);
        foreach (self::scopesOf($tokens) as $scope) {
            if ($scope['name'] === $methodName) {
                return self::join($tokens, $scope['start'], $scope['end']);
            }
        }

        return null;
    }

    /** 候補が「同一 chain に所有者/テナント制約 (右辺 provenance 込み)」を持つか。 */
    public static function hasOwnerScopedConstraint(PrimaryKeyStaticQueryCandidate $candidate): bool
    {
        $tokens = self::significantTokens($candidate->chainSource);
        $count = count($tokens);

        for ($i = 0; $i < $count; $i++) {
            if ($tokens[$i]['id'] !== T_STRING || ($tokens[$i + 1]['text'] ?? '') !== '(') {
                continue;
            }
            $prev = $tokens[$i - 1]['text'] ?? '';
            if ($prev !== '->' && $prev !== '?->' && $prev !== '::') {
                continue;
            }
            $args = self::argumentRanges($tokens, $i + 1);

            $proven = array_values(array_unique([
                ...$candidate->provenModelVariables,
                ...self::authenticatedActorVariables($candidate->methodSource),
            ]));

            // whereBelongsTo($model) — 引数が解決済みモデルであること
            if ($tokens[$i]['text'] === 'whereBelongsTo' && count($args) >= 1) {
                if (self::isProvenModelExpression($tokens, $args[0], $proven, false)) {
                    return true;
                }

                continue;
            }

            // where('organization_id'|'user_id'|'team_id'|'project_id', $model->getKey())
            if ($tokens[$i]['text'] !== 'where' || count($args) < 2) {
                continue;
            }
            $column = self::columnOf($tokens, $args[0]);
            if ($column === null || ! in_array($column, self::OWNER_COLUMNS, true)) {
                continue;
            }
            $valueRange = count($args) === 2 ? $args[1] : $args[2];
            if (count($args) >= 3 && self::literalOf($tokens, $args[1]) !== '=') {
                continue;
            }
            if (self::isProvenModelExpression($tokens, $valueRange, $proven, true)) {
                return true;
            }
        }

        return false;
    }

    /**
     * 認証済み actor が代入された変数 (`$user = Auth::guard('web')->user();` 等)。
     *
     * **所有者制約の右辺としてのみ**受理する。候補側の provenance 除外には使わない
     * (使うと actor 由来の直 fetch が inventory から静かに消え、分類対象でなくなるため)。
     *
     * @return list<string>
     */
    private static function authenticatedActorVariables(string $methodSource): array
    {
        $tokens = self::significantTokens($methodSource);
        $count = count($tokens);
        $variables = [];

        for ($i = 0; $i < $count; $i++) {
            if ($tokens[$i]['id'] !== T_VARIABLE || ($tokens[$i + 1]['text'] ?? '') !== '=') {
                continue;
            }
            $expression = '';
            for ($j = $i + 2; $j < $count && $tokens[$j]['text'] !== ';'; $j++) {
                $expression .= $tokens[$j]['text'];
            }
            if (preg_match('/^(Auth::user\(|Auth::guard\(.*\)->user\(|auth\(.*\)->user\(|\$request->user\(|\$this->request->user\()/', $expression) === 1) {
                $variables[] = $tokens[$i]['text'];
            }
        }

        return $variables;
    }

    /**
     * 候補のスコープ本文に request accessor が 1 つも無いか (AuthenticatedActorScope の negative check)。
     *
     * **accessor = 入力を読む呼び出し**であり、`$request` を素通しで別メソッドへ渡すだけの
     * 使用は accessor に数えない (`$this->apiActor($request)` で落とすと、token 由来 actor を
     * 解決するだけの Controller が本 case を使えなくなる)。
     *
     * ★`attributes` バッグは例外扱いする: これは middleware が**サーバ側で確定させた値**であり
     *   client 入力ではない。ただし「その attribute を置いた middleware が何を検証したか」は
     *   機械証明できないため、本 case を使う側の根拠文でそれを名指しさせる
     *   (本 case が機械証明できない旨は enum の docblock に明記済み)。
     */
    public static function methodIsFreeOfRequestAccessors(PrimaryKeyStaticQueryCandidate $candidate): bool
    {
        $tokens = self::significantTokens($candidate->methodSource);
        $count = count($tokens);

        for ($i = 0; $i < $count; $i++) {
            $token = $tokens[$i];

            // `$request->input(...)` / `$this->request->validated()` など
            if ($token['id'] === T_VARIABLE && $token['text'] === '$request'
                && self::readsRequestInput($tokens, $i)) {
                return false;
            }
            if ($token['id'] === T_STRING && $token['text'] === 'request'
                && ($tokens[$i - 1]['text'] ?? '') === '->'
                && self::readsRequestInput($tokens, $i)) {
                return false;
            }

            // `request('user_id')` / `request()->input(...)` helper
            if ($token['id'] !== T_STRING || $token['text'] !== 'request') {
                continue;
            }
            if (($tokens[$i + 1]['text'] ?? '') !== '(') {
                continue;
            }
            $prev = $tokens[$i - 1]['text'] ?? '';
            if ($prev === '::' || $prev === '->' || $prev === '?->' || $prev === 'function') {
                continue;
            }
            $close = self::matchingParenthesis($tokens, $i + 1);
            if ($close === null) {
                return false;
            }
            if ($close !== $i + 2) {
                return false; // `request('user_id')` = 入力の直読み
            }
            if (self::readsRequestInput($tokens, $close)) {
                return false;
            }
        }

        return true;
    }

    /**
     * request を表すトークン位置の直後が「入力を読む」呼び出しか。
     *
     * @param  list<array{id: int, text: string, line: int}>  $tokens
     */
    private static function readsRequestInput(array $tokens, int $at): bool
    {
        $arrow = $tokens[$at + 1]['text'] ?? '';
        if ($arrow !== '->' && $arrow !== '?->') {
            return false;
        }
        $member = $tokens[$at + 2] ?? null;
        if ($member === null || $member['id'] !== T_STRING) {
            return false;
        }

        return in_array($member['text'], self::REQUEST_INPUT_ACCESSORS, true);
    }

    /**
     * 候補の identity 変数が「テナントスコープ済みの解決」から確定しているか。
     *
     * 受理する形は 2 つだけ:
     *  (a) relation 起点クエリからの代入 (`$id = $organization->projects()->value('id')`)。
     *      基底変数が `App\Models\*` と証明済みであることを要求する
     *  (b) **解決済みモデルの key** からの代入 (`$organizationId = $org->getKey()`)
     *
     * (b) を受理するのは、`Model::find($org->getKey())` なら provenance フィルタで
     * そもそも候補にならないのに、スカラー変数を 1 つ挟んだだけで候補化してしまうため。
     *
     * 判定は**候補位置の直前までの束縛**に対して行う (走査時に確定させている)。
     */
    public static function identityAssignedFromRelationQuery(PrimaryKeyStaticQueryCandidate $candidate): bool
    {
        return $candidate->tenantScopedIdentity;
    }

    /**
     * identity が「同一メソッド内で自身が発行した走査クエリ」の結果から確定しているか。
     *
     * 走査クエリ = `App\Models\*` / `DB::` 起点で実行系まで到達した chain の結果。
     * 判定は**候補位置の直前までの束縛**に対して行う。
     */
    public static function identityDerivedFromSameMethodQuery(PrimaryKeyStaticQueryCandidate $candidate): bool
    {
        return $candidate->sameMethodScanIdentity;
    }

    /**
     * identity が当該メソッドの引数そのもの、または引数から導出された変数か。
     *
     * 「呼び出し元で確定した値を受け取っているだけ」であることの機械的な必要条件。
     * 呼び出し元での provenance は証明しない (メソッドをまたぐデータフロー解析は範囲外)。
     */
    public static function identityDerivedFromMethodParameters(PrimaryKeyStaticQueryCandidate $candidate): bool
    {
        return $candidate->parameterDerivedIdentity;
    }

    /** 候補のメソッドが private 宣言か (外部から直接呼べないこと)。 */
    public static function methodIsPrivate(PrimaryKeyStaticQueryCandidate $candidate): bool
    {
        foreach (self::significantTokens($candidate->methodSource) as $token) {
            if ($token['id'] === T_PRIVATE) {
                return true;
            }
            if ($token['id'] === T_FUNCTION) {
                return false;
            }
        }

        return false;
    }

    /** `$reservation->id` → `$reservation` / `$id` → `$id` / それ以外は null。 */
    private static function baseVariableOf(string $identityArgument): ?string
    {
        if (preg_match('/^(\$[A-Za-z_][A-Za-z0-9_]*)(->(id|getKey\(\)|[a-z0-9_]+_id))?$/', $identityArgument, $m) !== 1) {
            return null;
        }

        return $m[1];
    }

    /**
     * スコープ内の「モデル/DB 起点クエリの実行結果が代入された変数」の**時系列**。
     *
     * ★「静的呼び出しなら何でもよい」ではない: `$ids = Payload::ids();` を受理すると
     *   `IdDerivedFromSameMethodQuery` の副条件が「任意クラスの静的メソッド結果を foreach しただけ」で
     *   通ってしまう。root 判定 ({@see self::staticRootAt()}) を通すことと、実行系で終わることを要求する。
     *
     * ★relation 起点 (`$x->rel()->…`) は含めない。トークン上は任意 object の
     *   メソッド呼び出しと区別できないため。relation 起点でテナントに閉じている形は
     *   `IdDerivedFromTenantScopedQuery` の担当。
     *
     * ★**時間順序を持つ**: 「後段の安全な代入で前段の危険な値が安全扱いされる」ことを防ぐため、
     *   使用位置より前の代入だけを見て、再代入があれば失効させる。
     *
     * @return list<array{index: int, set: list<string>}>
     */
    private function queryResultTimeline(int $scopeId): array
    {
        if (isset($this->queryResultCache[$scopeId])) {
            return $this->queryResultCache[$scopeId];
        }
        [$from, $to] = $this->scopeRange($scopeId);
        /** @var list<string> $set */
        $set = [];
        $timeline = [];

        for ($i = $from; $i <= $to; $i++) {
            if (! $this->isAssignmentAt($i)) {
                continue;
            }
            $variable = $this->tokens[$i]['text'];
            $root = $this->staticRootAt($i + 2);
            // `DB::table('oauth_access_tokens')` のようにモデルを持たないテーブルの走査も
            // 「同一メソッド内のクエリ」ではある (root 判定は modelTables で絞るため通らない)
            $isDatabaseRoot = $this->resolveClass($this->tokens[$i + 2]['text'] ?? '') === self::DB_FACADE
                && ($this->tokens[$i + 3]['text'] ?? '') === '::';
            $isQuery = ($root !== null || $isDatabaseRoot) && $this->chainEndsWithExecutor($i + 2);

            $set = array_values(array_filter($set, static fn (string $v): bool => $v !== $variable));
            if ($isQuery) {
                $set[] = $variable;
            }
            $timeline[] = ['index' => $i, 'set' => $set];
        }

        $this->queryResultCache[$scopeId] = $timeline;

        return $timeline;
    }

    /**
     * 位置 `$at` の直前までに確定しているクエリ結果変数。
     *
     * @return list<string>
     */
    private function queryResultAt(int $scopeId, int $at): array
    {
        $set = [];
        foreach ($this->queryResultTimeline($scopeId) as $entry) {
            if ($entry['index'] >= $at) {
                break;
            }
            $set = $entry['set'];
        }

        return $set;
    }

    /**
     * identity 変数への**最後の束縛**を候補位置より前から探す。
     *
     * 代入 (`$x = …`) と foreach 束縛 (`foreach ($src as $x)`) の両方を見る。
     * 「安全な代入が候補より後にある」「安全な代入の後に untrusted 値へ再代入されている」
     * のどちらも副条件を通さないために、**最後の 1 つだけ**を返す。
     *
     * @return array{kind: 'assign'|'foreach', index: int, source: string, range: array{int, int}}|null
     */
    private function lastBindingOf(int $scopeId, string $variable, int $before): ?array
    {
        [$from, $to] = $this->scopeRange($scopeId);
        $last = null;

        for ($i = $from; $i < $before && $i <= $to; $i++) {
            if ($this->isAssignmentAt($i) && $this->tokens[$i]['text'] === $variable) {
                $end = $i + 2;
                while ($end <= $to && $this->tokens[$end]['text'] !== ';') {
                    $end++;
                }
                $last = ['kind' => 'assign', 'index' => $i, 'source' => '', 'range' => [$i + 2, $end - 1]];

                continue;
            }
            if ($this->tokens[$i]['id'] !== T_FOREACH || ($this->tokens[$i + 1]['text'] ?? '') !== '(') {
                continue;
            }
            $close = self::matchingParenthesis($this->tokens, $i + 1);
            if ($close === null || ($this->tokens[$close - 1]['text'] ?? '') !== $variable) {
                continue;
            }
            $last = [
                'kind' => 'foreach',
                'index' => $i,
                'source' => $this->tokens[$i + 2]['text'] ?? '',
                'range' => [$i + 2, $close - 1],
            ];
        }

        return $last;
    }

    /** identity が「テナントスコープ済みの解決」から確定しているか (候補位置基準)。 */
    private function identityIsTenantScoped(int $scopeId, int $at, string $identity): bool
    {
        if (preg_match('/^\$[A-Za-z_][A-Za-z0-9_]*$/', $identity) !== 1) {
            return false;
        }
        $binding = $this->lastBindingOf($scopeId, $identity, $at);
        if ($binding === null || $binding['kind'] !== 'assign') {
            return false;
        }
        $proven = $this->provenAt($scopeId, $binding['index']);

        // (b) 解決済みモデルの key (`$organizationId = $org->getKey()`)
        if (self::isProvenModelExpression($this->tokens, $binding['range'], $proven, true)) {
            return true;
        }

        // (a) relation 起点クエリ (`$id = $organization->projects()->value('id')`)
        [$start] = $binding['range'];
        if (($this->tokens[$start]['id'] ?? 0) !== T_VARIABLE
            || ! in_array($this->tokens[$start]['text'], $proven, true)) {
            return false;
        }
        $arrow = $this->tokens[$start + 1]['text'] ?? '';
        if (($arrow !== '->' && $arrow !== '?->') || ($this->tokens[$start + 2]['id'] ?? 0) !== T_STRING) {
            return false;
        }
        if (($this->tokens[$start + 3]['text'] ?? '') !== '(') {
            return false;
        }
        $close = self::matchingParenthesis($this->tokens, $start + 3);
        if ($close === null) {
            return false;
        }
        $after = $this->tokens[$close + 1]['text'] ?? '';

        return $after === '->' || $after === '?->';
    }

    /** identity が「同一メソッド内の走査クエリ結果」由来か (候補位置基準)。 */
    private function identityIsSameMethodScan(int $scopeId, int $at, string $identity): bool
    {
        $base = self::baseVariableOf($identity);
        if ($base === null) {
            return false;
        }
        $binding = $this->lastBindingOf($scopeId, $base, $at);
        if ($binding === null) {
            return false;
        }
        $sources = $this->queryResultAt($scopeId, $binding['index']);
        if ($sources === []) {
            return false;
        }
        if ($binding['kind'] === 'foreach') {
            return in_array($binding['source'], $sources, true);
        }
        for ($i = $binding['range'][0]; $i <= $binding['range'][1]; $i++) {
            if ($this->tokens[$i]['id'] === T_VARIABLE && in_array($this->tokens[$i]['text'], $sources, true)) {
                return true;
            }
        }

        return false;
    }

    /** identity が当該メソッドの引数そのもの / 引数から導出された値か (候補位置基準)。 */
    private function identityIsParameterDerived(int $scopeId, int $at, string $identity): bool
    {
        $base = self::baseVariableOf($identity);
        if ($base === null || $scopeId < 0) {
            return false;
        }
        $parameters = $this->parameterVariables($scopeId);
        $binding = $this->lastBindingOf($scopeId, $base, $at);
        if ($binding === null) {
            return in_array($base, $parameters, true); // 引数のまま使われている
        }
        if ($binding['kind'] === 'foreach') {
            return in_array($binding['source'], $parameters, true);
        }
        for ($i = $binding['range'][0]; $i <= $binding['range'][1]; $i++) {
            if ($this->tokens[$i]['id'] === T_VARIABLE && in_array($this->tokens[$i]['text'], $parameters, true)) {
                return true;
            }
        }

        return false;
    }

    /**
     * メソッドシグネチャの引数変数名。
     *
     * @return list<string>
     */
    private function parameterVariables(int $scopeId): array
    {
        if ($scopeId < 0) {
            return [];
        }
        $start = $this->scopes[$scopeId]['start'];
        $end = $this->signatureEnd($start);
        $variables = [];
        for ($i = $start; $i <= $end; $i++) {
            if ($this->tokens[$i]['id'] === T_VARIABLE) {
                $variables[] = $this->tokens[$i]['text'];
            }
        }

        return array_values(array_unique($variables));
    }

    /** トークン位置が `$var = …` の代入か (`==` / `=>` を除く)。 */
    private function isAssignmentAt(int $i): bool
    {
        return $this->tokens[$i]['id'] === T_VARIABLE
            && ($this->tokens[$i + 1]['text'] ?? '') === '='
            && ($this->tokens[$i + 2]['text'] ?? '') !== '=';
    }

    /**
     * スコープのトークン範囲。
     *
     * @return array{int, int}
     */
    private function scopeRange(int $scopeId): array
    {
        return $scopeId < 0
            ? [0, count($this->tokens) - 1]
            : [$this->scopes[$scopeId]['start'], $this->scopes[$scopeId]['end']];
    }

    // ------------------------------------------------------------------
    // 内部実装
    // ------------------------------------------------------------------

    /** @param  list<string>  $modelTables */
    private static function make(string $source, string $relativePath, array $modelTables): self
    {
        $tokens = self::significantTokens($source);
        $scopes = self::scopesOf($tokens);

        /** @var list<int> $scopeIdOf */
        $scopeIdOf = array_fill(0, max(count($tokens), 1), -1);
        foreach ($scopes as $id => $scope) {
            for ($i = $scope['start']; $i <= $scope['end'] && $i < count($tokens); $i++) {
                $scopeIdOf[$i] = $id;
            }
        }

        return new self(
            $tokens,
            $relativePath,
            $modelTables,
            self::importsOf($tokens),
            self::namespaceOf($tokens),
            self::selfClassOf($tokens),
            $scopes,
            $scopeIdOf,
            self::docVarDeclarationsOf($source),
            self::methodReturnTypesOf($tokens, $scopes),
        );
    }

    /**
     * メソッド名 => 戻り値型宣言 (`private function x(...): Organization` の `Organization`)。
     *
     * union / nullable は「単一のクラス名」だけを採る (`?Organization` は null を許すので採らない)。
     *
     * @param  list<array{id: int, text: string, line: int}>  $tokens
     * @param  list<array{name: string, start: int, end: int}>  $scopes
     * @return array<string, string>
     */
    private static function methodReturnTypesOf(array $tokens, array $scopes): array
    {
        $types = [];
        foreach ($scopes as $scope) {
            if (str_starts_with($scope['name'], '__')) {
                continue;
            }
            $open = null;
            for ($i = $scope['start']; $i <= $scope['end']; $i++) {
                if ($tokens[$i]['text'] === '(') {
                    $open = $i;

                    break;
                }
            }
            if ($open === null) {
                continue;
            }
            $close = self::matchingParenthesis($tokens, $open);
            if ($close === null || ($tokens[$close + 1]['text'] ?? '') !== ':') {
                continue;
            }
            $type = $tokens[$close + 2] ?? null;
            if ($type === null
                || ! in_array($type['id'], [T_STRING, T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED], true)) {
                continue;
            }
            // `: Organization {` 以外 (union / intersection) は採らない
            if (($tokens[$close + 3]['text'] ?? '') !== '{') {
                continue;
            }
            $types[$scope['name']] = $type['text'];
        }

        return $types;
    }

    /** @return list<PrimaryKeyStaticQueryCandidate> */
    private function scan(): array
    {
        $aliases = $this->builderAliases();
        $candidates = [];
        /** @var array<string, int> $ordinals */
        $ordinals = [];
        $count = count($this->tokens);

        for ($i = 0; $i < $count; $i++) {
            $root = $this->rootAt($i, $aliases);
            if ($root === null) {
                continue;
            }
            $chainEnd = $this->chainEnd($root['start']);
            $chainSource = self::join($this->tokens, $root['start'], $chainEnd);
            $scopeName = $this->scopeNameAt($i);
            $scopeId = $this->scopeIdOf[$i] ?? -1;
            // provenance / クエリ結果は**候補の位置まで**の状態で判定する
            // (スコープ全体から集めると、後段の安全な代入で前段の危険な値が安全扱いされる)
            $proven = $this->provenAt($scopeId, $root['start']);

            foreach ($this->predicatesIn($root['start'], $chainEnd, $root['kind']) as $predicate) {
                // 識別子が解決済みモデル由来なら除外する (元モデルの解決式が別途候補として捕まるため、
                // provenance は候補へ遡及する)。単数の識別子を取る述語だけに適用し、
                // MultiIdentity (配列変数) には適用しない
                $singular = $predicate['kind'] === PrimaryKeyPredicateKind::SingleIdentity
                    || $predicate['kind'] === PrimaryKeyPredicateKind::IdentityExclusion;
                if ($singular && $this->isProvenModelIdentity($predicate['identityRange'], $proven)) {
                    continue;
                }

                $fingerprint = $this->displayPath().'#'.$scopeName.'#'.$root['kind']
                    .'.'.$predicate['label'].':'.$predicate['identity'];
                $ordinals[$fingerprint] = ($ordinals[$fingerprint] ?? 0) + 1;

                $candidates[] = new PrimaryKeyStaticQueryCandidate(
                    key: $fingerprint.'#'.$ordinals[$fingerprint],
                    relativePath: $this->relativePath,
                    scopeName: $scopeName,
                    predicateKind: $predicate['kind'],
                    rootKind: $root['kind'],
                    predicate: $predicate['label'],
                    identityArgument: $predicate['identity'],
                    chainSource: $chainSource,
                    methodSource: $this->scopeSource($scopeId),
                    provenModelVariables: $proven,
                    queryResultVariables: $this->queryResultAt($scopeId, $root['start']),
                    tenantScopedIdentity: $this->identityIsTenantScoped($scopeId, $root['start'], $predicate['identity']),
                    sameMethodScanIdentity: $this->identityIsSameMethodScan($scopeId, $root['start'], $predicate['identity']),
                    parameterDerivedIdentity: $this->identityIsParameterDerived($scopeId, $root['start'], $predicate['identity']),
                );
            }
        }

        return $candidates;
    }

    /** @return list<string> */
    private function scanDynamicColumns(): array
    {
        $aliases = $this->builderAliases();
        $found = [];
        /** @var array<string, int> $ordinals */
        $ordinals = [];
        $count = count($this->tokens);

        for ($i = 0; $i < $count; $i++) {
            $root = $this->rootAt($i, $aliases);
            if ($root === null) {
                continue;
            }
            $chainEnd = $this->chainEnd($root['start']);
            $scopeName = $this->scopeNameAt($i);

            for ($p = $root['start']; $p <= $chainEnd; $p++) {
                if ($this->tokens[$p]['id'] !== T_STRING || ($this->tokens[$p + 1]['text'] ?? '') !== '(') {
                    continue;
                }
                $prev = $this->tokens[$p - 1]['text'] ?? '';
                if ($prev !== '->' && $prev !== '?->' && $prev !== '::') {
                    continue;
                }
                if (! in_array($this->tokens[$p]['text'], self::COLUMN_PREDICATES, true)) {
                    continue;
                }
                $args = self::argumentRanges($this->tokens, $p + 1);
                if ($args === []) {
                    continue;
                }

                /** @var list<array{column: array{int, int}, value: array{int, int}}> $pairs */
                $pairs = [];
                if (count($args) >= 2) {
                    $pairs[] = ['column' => $args[0], 'value' => $args[count($args) === 2 ? 1 : 2]];
                } else {
                    // 単一引数の array 形 (`where([$column => $x])` / `where([[$column, '=', $x]])`)
                    foreach ($this->arrayFormEntries($args[0]) as $entry) {
                        $pairs[] = ['column' => $entry['column'], 'value' => $entry['value']];
                    }
                }

                foreach ($pairs as $pair) {
                    if (self::columnOf($this->tokens, $pair['column']) !== null) {
                        continue; // 列名が字句的に確定している
                    }
                    // 値引数まで fingerprint に含める (含めないと同一メソッド内の別呼び出しへ
                    // 裁定理由が横滑りする。通常 inventory の key 方針と揃える)
                    $fingerprint = $this->displayPath().'#'.$scopeName.'#'.$root['kind']
                        .'.'.$this->tokens[$p]['text'].':'.$this->identityText($pair['column'])
                        .'=>'.$this->identityText($pair['value']);
                    $ordinals[$fingerprint] = ($ordinals[$fingerprint] ?? 0) + 1;
                    $found[] = $fingerprint.'#'.$ordinals[$fingerprint];
                }
            }
        }

        return $found;
    }

    /** @return list<string> */
    private function scanUniqueColumns(): array
    {
        $aliases = $this->builderAliases();
        $found = [];
        $count = count($this->tokens);

        for ($i = 0; $i < $count; $i++) {
            $root = $this->rootAt($i, $aliases);
            if ($root === null) {
                continue;
            }
            if (in_array($root['kind'], self::CATALOG_ROOTS, true)) {
                // Plan はグローバルカタログでテナント資源ではない (root ごと除外する)
                continue;
            }
            $chainEnd = $this->chainEnd($root['start']);
            $scopeName = $this->scopeNameAt($i);

            for ($p = $root['start']; $p <= $chainEnd; $p++) {
                if ($this->tokens[$p]['id'] !== T_STRING || ($this->tokens[$p + 1]['text'] ?? '') !== '(') {
                    continue;
                }
                $prev = $this->tokens[$p - 1]['text'] ?? '';
                if ($prev !== '->' && $prev !== '?->' && $prev !== '::') {
                    continue;
                }
                $name = $this->tokens[$p]['text'];
                $column = null;

                if ($name === 'where' || $name === 'firstWhere') {
                    $args = self::argumentRanges($this->tokens, $p + 1);
                    $column = $args === [] ? null : self::columnOf($this->tokens, $args[0]);
                } elseif (str_starts_with($name, 'where')) {
                    $magic = self::snake(substr($name, 5));
                    $column = $magic === '' ? null : $magic;
                }

                if ($column === null || ! in_array($column, self::UNIQUE_COLUMNS, true)) {
                    continue;
                }
                $found[] = $this->displayPath().'#'.$scopeName.'#'.$root['kind'].'.'.$name.':'.$column;
            }
        }

        return array_values(array_unique($found));
    }

    /**
     * `$var = <静的起点式>` の単純代入で伝播する builder alias。
     *
     * **再代入では取り消さない** (docblock の fail 方向を参照)。
     *
     * @return array<int, array<string, array{kind: string, at: int}>> scope 添字 => 変数名 => alias
     */
    private function builderAliases(): array
    {
        /** @var array<int, array<string, array{kind: string, at: int}>> $aliases */
        $aliases = [];
        $count = count($this->tokens);

        for ($i = 0; $i < $count; $i++) {
            if ($this->tokens[$i]['id'] !== T_VARIABLE || ($this->tokens[$i + 1]['text'] ?? '') !== '=') {
                continue;
            }
            $root = $this->staticRootAt($i + 2);
            if ($root === null) {
                continue;
            }
            if ($this->chainEndsWithExecutor($root['start'])) {
                // 代入式が実行系メソッドで終わっている = 変数に入るのは Builder ではなく
                // **結果 (Model / Collection)**。`$locked = Project::whereKey(...)->firstOrFail();`
                // の `$locked` を builder alias 扱いすると、続く
                // `$locked->categories()->whereKey($categoryId)` (relation 起点 = 安全) まで
                // 候補化してしまい inventory が形骸化する。
                // 「$q = User::query();」のような**未実行の Builder** だけを alias として伝播する
                continue;
            }
            $scopeId = $this->scopeIdOf[$i] ?? -1;
            $variable = $this->tokens[$i]['text'];
            if (isset($aliases[$scopeId][$variable])) {
                continue; // 最初の代入位置を保持する (以降の使用をすべて候補にするため)
            }
            $aliases[$scopeId][$variable] = ['kind' => $root['kind'], 'at' => $i];
        }

        return $aliases;
    }

    /**
     * chain の最後の depth 0 メソッド呼び出しが「実行系」か (= 変数に入るのは Builder でない)。
     */
    private function chainEndsWithExecutor(int $start): bool
    {
        $end = $this->chainEnd($start);
        $last = null;
        $depth = 0;

        for ($i = $start; $i <= $end; $i++) {
            $text = $this->tokens[$i]['text'];
            if ($text === '(' || $text === '[' || $text === '{') {
                $depth++;

                continue;
            }
            if ($text === ')' || $text === ']' || $text === '}') {
                $depth--;

                continue;
            }
            if ($depth !== 0 || $this->tokens[$i]['id'] !== T_STRING) {
                continue;
            }
            if (($this->tokens[$i + 1]['text'] ?? '') !== '(') {
                continue;
            }
            $prev = $this->tokens[$i - 1]['text'] ?? '';
            if ($prev === '->' || $prev === '?->' || $prev === '::') {
                $last = $this->tokens[$i]['text'];
            }
        }

        return $last !== null && in_array($last, self::EXECUTOR_METHODS, true);
    }

    /**
     * トークン位置 `$i` が chain root なら root 情報を返す。
     *
     * @param  array<int, array<string, array{kind: string, at: int}>>  $aliases
     * @return array{kind: string, start: int}|null
     */
    private function rootAt(int $i, array $aliases): ?array
    {
        $static = $this->staticRootAt($i);
        if ($static !== null) {
            return $static;
        }

        // builder alias の使用 (`$q->whereKey($id)`)
        $token = $this->tokens[$i];
        if ($token['id'] !== T_VARIABLE) {
            return null;
        }
        $next = $this->tokens[$i + 1]['text'] ?? '';
        if ($next !== '->' && $next !== '?->') {
            return null;
        }
        $scopeId = $this->scopeIdOf[$i] ?? -1;
        $alias = $aliases[$scopeId][$token['text']] ?? null;
        if ($alias === null || $alias['at'] >= $i) {
            return null;
        }

        return ['kind' => $alias['kind'], 'start' => $i];
    }

    /**
     * 静的起点 (クラス起点 / `new` 起点 / `DB::table()` 起点) の判定。
     *
     * @return array{kind: string, start: int}|null
     */
    private function staticRootAt(int $i): ?array
    {
        $token = $this->tokens[$i] ?? null;
        if ($token === null) {
            return null;
        }
        $prev = $this->tokens[$i - 1]['text'] ?? '';

        // (1) `new App\Models\User`
        if ($token['id'] === T_NEW) {
            $class = $this->classNameAt($i + 1);
            if ($class === null || ! $this->isModelClass($class)) {
                return null;
            }

            // `(new User())->newQuery()` の chain は囲みの `(` から始まる
            return ['kind' => self::shortName($class), 'start' => $prev === '(' ? $i - 1 : $i];
        }

        // (2) `ClassName::` / `self::` / `static::` / `DB::`
        if (($this->tokens[$i + 1]['text'] ?? '') !== '::') {
            return null;
        }
        if ($prev === '->' || $prev === '?->' || $prev === '::' || $prev === 'new' || $prev === 'function') {
            return null;
        }
        $text = $token['text'];
        if (! in_array($token['id'], [T_STRING, T_STATIC, T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED], true)) {
            return null;
        }

        if ($text === 'self' || $text === 'static' || $text === 'parent') {
            if ($this->selfClass === null || ! $this->isModelClass($this->selfClass)) {
                return null;
            }

            return ['kind' => self::shortName($this->selfClass), 'start' => $i];
        }

        $fqcn = $this->resolveClass($text);
        if ($fqcn === self::DB_FACADE) {
            $table = $this->tableInChain($i);

            return $table === null ? null : ['kind' => 'DB:'.$table, 'start' => $i];
        }
        if (! $this->isModelClass($fqcn)) {
            return null;
        }

        return ['kind' => self::shortName($fqcn), 'start' => $i];
    }

    /** `DB::table('users')` / `DB::connection(...)->table('users as u')` の解決テーブル名。 */
    private function tableInChain(int $start): ?string
    {
        $end = $this->chainEnd($start);
        for ($i = $start; $i <= $end; $i++) {
            if ($this->tokens[$i]['id'] !== T_STRING || $this->tokens[$i]['text'] !== 'table') {
                continue;
            }
            if (($this->tokens[$i + 1]['text'] ?? '') !== '(') {
                continue;
            }
            $args = self::argumentRanges($this->tokens, $i + 1);
            if ($args === []) {
                return null;
            }
            $literal = self::literalOf($this->tokens, $args[0]);
            if ($literal === null) {
                return null;
            }
            $table = trim(explode(' ', str_ireplace(' as ', ' ', $literal))[0]);

            return in_array($table, $this->modelTables, true) ? $table : null;
        }

        return null;
    }

    /**
     * chain 内の主キー同一性述語を列挙する。
     *
     * @return list<array{kind: PrimaryKeyPredicateKind, label: string, identity: string, identityRange: array{int, int}}>
     */
    private function predicatesIn(int $start, int $end, string $rootKind): array
    {
        $found = [];
        $depth = 0;

        for ($i = $start; $i <= $end; $i++) {
            $text = $this->tokens[$i]['text'];
            if ($text === '(' || $text === '[' || $text === '{') {
                $depth++;

                continue;
            }
            if ($text === ')' || $text === ']' || $text === '}') {
                $depth--;

                continue;
            }
            if ($depth !== 0 || $this->tokens[$i]['id'] !== T_STRING) {
                continue;
            }
            if (($this->tokens[$i + 1]['text'] ?? '') !== '(') {
                continue;
            }
            $prev = $this->tokens[$i - 1]['text'] ?? '';
            if ($prev !== '->' && $prev !== '?->' && $prev !== '::') {
                continue;
            }
            foreach ($this->predicateAt($i, $rootKind) as $predicate) {
                $found[] = $predicate;
            }
        }

        // chain が削除で終わるなら「取得」ではなく「削除」として扱う。
        // これをやらないと `User::query()->whereKey($this->userId)->delete();` が
        // SingleIdentity のまま通り、DestructiveIdentity の禁止表を素通りできてしまう
        if ($this->chainEndsWithDestructiveTerminator($start, $end)) {
            $found = array_map(
                static fn (array $predicate): array => [
                    ...$predicate,
                    'kind' => PrimaryKeyPredicateKind::DestructiveIdentity,
                ],
                $found,
            );
        }

        return $found;
    }

    /** chain の最後の depth 0 呼び出しが削除系か。 */
    private function chainEndsWithDestructiveTerminator(int $start, int $end): bool
    {
        $last = null;
        $depth = 0;

        for ($i = $start; $i <= $end; $i++) {
            $text = $this->tokens[$i]['text'];
            if ($text === '(' || $text === '[' || $text === '{') {
                $depth++;

                continue;
            }
            if ($text === ')' || $text === ']' || $text === '}') {
                $depth--;

                continue;
            }
            if ($depth !== 0 || $this->tokens[$i]['id'] !== T_STRING) {
                continue;
            }
            if (($this->tokens[$i + 1]['text'] ?? '') !== '(') {
                continue;
            }
            $prev = $this->tokens[$i - 1]['text'] ?? '';
            if ($prev === '->' || $prev === '?->' || $prev === '::') {
                $last = $this->tokens[$i]['text'];
            }
        }

        return $last !== null && in_array($last, self::DESTRUCTIVE_TERMINATORS, true);
    }

    /**
     * @return list<array{kind: PrimaryKeyPredicateKind, label: string, identity: string, identityRange: array{int, int}}>
     */
    private function predicateAt(int $i, string $rootKind): array
    {
        $name = $this->tokens[$i]['text'];
        $args = self::argumentRanges($this->tokens, $i + 1);
        $single = PrimaryKeyPredicateKind::SingleIdentity;
        $multi = PrimaryKeyPredicateKind::MultiIdentity;
        $exclusion = PrimaryKeyPredicateKind::IdentityExclusion;

        // find 系 / key 述語 / magic where
        $simple = match ($name) {
            'find', 'findOrFail', 'findOrNew', 'findOr' => $single,
            'whereKey', 'orWhereKey' => $single,
            'whereId' => $single,
            'findMany' => $multi,
            'whereKeyNot', 'orWhereKeyNot' => $exclusion,
            'destroy' => PrimaryKeyPredicateKind::DestructiveIdentity,
            default => null,
        };
        if ($simple !== null) {
            if ($args === []) {
                return [];
            }

            return [$this->predicate($simple, $name, $args[0])];
        }

        if (in_array($name, self::COLUMN_PREDICATES, true)) {
            return $this->columnPredicate($name, $args, $rootKind);
        }

        return [];
    }

    /**
     * @param  list<array{int, int}>  $args
     * @return list<array{kind: PrimaryKeyPredicateKind, label: string, identity: string, identityRange: array{int, int}}>
     */
    private function columnPredicate(string $name, array $args, string $rootKind): array
    {
        $single = PrimaryKeyPredicateKind::SingleIdentity;
        $multi = PrimaryKeyPredicateKind::MultiIdentity;
        $exclusion = PrimaryKeyPredicateKind::IdentityExclusion;

        // array 形 `where(['id' => $x])` / `where([['id', '=', $x]])`
        if (($name === 'where' || $name === 'orWhere') && count($args) === 1) {
            return $this->arrayFormPredicates($args[0]);
        }
        if (count($args) < 2) {
            return [];
        }
        $column = self::columnOf($this->tokens, $args[0]);
        if ($column !== 'id') {
            return [];
        }
        if ($name === 'whereIn' || $name === 'orWhereIn') {
            return [$this->predicate($multi, $name.':id:in', $args[1])];
        }
        if ($name === 'whereNotIn' || $name === 'orWhereNotIn') {
            return [$this->predicate($exclusion, $name.':id:not-in', $args[1])];
        }
        if (count($args) === 2) {
            return [$this->predicate($single, $name.':id:=', $args[1])];
        }

        // 3 引数形は等価 / IN / 除外のみ
        // (順序比較 `where('id', '>', $cursor)` は主キー同一性ではないので候補にしない)
        $operator = strtolower((string) self::literalOf($this->tokens, $args[1]));
        if ($operator === '=') {
            return [$this->predicate($single, $name.':id:=', $args[2])];
        }
        if ($operator === 'in') {
            return [$this->predicate($multi, $name.':id:in', $args[2])];
        }
        if ($operator === '!=' || $operator === '<>' || $operator === 'not in') {
            return [$this->predicate($exclusion, $name.':id:'.$operator, $args[2])];
        }

        return [];
    }

    /**
     * array 形 where の**全**主キーエントリ。
     *
     * 最初の 1 件で打ち切ると `where([['id', '=', $trusted], ['id', '=', $payload]])` の
     * 後続 identity が候補化されず素通りする。
     *
     * @param  array{int, int}  $range
     * @return list<array{kind: PrimaryKeyPredicateKind, label: string, identity: string, identityRange: array{int, int}}>
     */
    private function arrayFormPredicates(array $range): array
    {
        $predicates = [];
        foreach ($this->arrayFormEntries($range) as $entry) {
            if (self::columnOf($this->tokens, $entry['column']) !== 'id') {
                continue;
            }
            $kind = match ($entry['operator']) {
                '=' => PrimaryKeyPredicateKind::SingleIdentity,
                'in' => PrimaryKeyPredicateKind::MultiIdentity,
                '!=', '<>', 'not in' => PrimaryKeyPredicateKind::IdentityExclusion,
                default => null,
            };
            if ($kind === null) {
                continue; // 順序比較 (`['id', '>', $cursor]`) は主キー同一性ではない
            }
            $predicates[] = $this->predicate($kind, 'where:id:'.$entry['operator'], $entry['value']);
        }

        return $predicates;
    }

    /**
     * array 形 where の要素を (列 / 演算子 / 値) に分解する。
     *
     * 対応する形:
     *   `['id' => $x]`            → operator `=`
     *   `[['id', '=', $x]]`       → operator はリテラルの第 2 要素
     *   `[$column => $x]`         → 列が動的 (columnOf が null を返す)
     *
     * @param  array{int, int}  $range
     * @return list<array{column: array{int, int}, operator: string, value: array{int, int}}>
     */
    private function arrayFormEntries(array $range): array
    {
        [$start, $end] = $range;
        if (($this->tokens[$start]['text'] ?? '') !== '[') {
            return [];
        }
        $entries = [];

        foreach (self::bracketElements($this->tokens, $start, $end) as $element) {
            [$elementStart, $elementEnd] = $element;

            // ネスト array (`['id', '=', $x]`)
            if (($this->tokens[$elementStart]['text'] ?? '') === '[') {
                $inner = self::bracketElements($this->tokens, $elementStart, $elementEnd);
                if (count($inner) < 3) {
                    continue;
                }
                $operator = self::literalOf($this->tokens, $inner[1]);
                if ($operator === null) {
                    continue; // 演算子が動的なら読めない
                }
                $entries[] = [
                    'column' => $inner[0],
                    'operator' => strtolower($operator),
                    'value' => $inner[2],
                ];

                continue;
            }

            // `key => value`
            $arrow = null;
            $depth = 0;
            for ($i = $elementStart; $i <= $elementEnd; $i++) {
                $text = $this->tokens[$i]['text'];
                if ($text === '(' || $text === '[' || $text === '{') {
                    $depth++;
                } elseif ($text === ')' || $text === ']' || $text === '}') {
                    $depth--;
                } elseif ($depth === 0 && $text === '=>') {
                    $arrow = $i;

                    break;
                }
            }
            if ($arrow === null) {
                continue;
            }
            $entries[] = [
                'column' => [$elementStart, $arrow - 1],
                'operator' => '=',
                'value' => [$arrow + 1, $elementEnd],
            ];
        }

        return $entries;
    }

    /**
     * `[` の位置から対応する `]` までを、深さ 0 の `,` で分割した要素範囲。
     *
     * @param  list<array{id: int, text: string, line: int}>  $tokens
     * @return list<array{int, int}>
     */
    private static function bracketElements(array $tokens, int $open, int $limit): array
    {
        $depth = 0;
        $close = null;
        for ($i = $open; $i <= $limit; $i++) {
            $text = $tokens[$i]['text'];
            if ($text === '[' || $text === '(' || $text === '{') {
                $depth++;
            } elseif ($text === ']' || $text === ')' || $text === '}') {
                $depth--;
                if ($depth === 0) {
                    $close = $i;

                    break;
                }
            }
        }
        if ($close === null || $close === $open + 1) {
            return [];
        }
        $elements = [];
        $depth = 0;
        $start = $open + 1;
        for ($i = $open + 1; $i < $close; $i++) {
            $text = $tokens[$i]['text'];
            if ($text === '[' || $text === '(' || $text === '{') {
                $depth++;
            } elseif ($text === ']' || $text === ')' || $text === '}') {
                $depth--;
            } elseif ($depth === 0 && $text === ',') {
                if ($start <= $i - 1) {
                    $elements[] = [$start, $i - 1];
                }
                $start = $i + 1;
            }
        }
        if ($start <= $close - 1) {
            $elements[] = [$start, $close - 1];
        }

        return $elements;
    }

    /**
     * @param  array{int, int}  $range
     * @return array{kind: PrimaryKeyPredicateKind, label: string, identity: string, identityRange: array{int, int}}
     */
    private function predicate(PrimaryKeyPredicateKind $kind, string $label, array $range): array
    {
        return [
            'kind' => $kind,
            'label' => $label,
            'identity' => $this->identityText($range),
            'identityRange' => $range,
        ];
    }

    /**
     * 識別子引数の正規化 (cast を除去してトークンを連結する)。
     *
     * @param  array{int, int}  $range
     */
    private function identityText(array $range): string
    {
        [$start, $end] = $range;
        $casts = [T_INT_CAST, T_STRING_CAST, T_BOOL_CAST, T_DOUBLE_CAST, T_ARRAY_CAST, T_OBJECT_CAST];
        while ($start <= $end && in_array($this->tokens[$start]['id'], $casts, true)) {
            $start++;
        }
        $text = '';
        for ($i = $start; $i <= $end; $i++) {
            $text .= $this->tokens[$i]['text'];
        }

        return $text === '' ? '(none)' : $text;
    }

    /**
     * 識別子が解決済みモデル由来 (`$model->getKey()` / `$model->id` / `$model->{fk}_id`) か。
     *
     * **形だけでは除外しない**。`$dto->user_id` はトークン上まったく同じ形であり、
     * 形だけで除外すると payload object 由来 id の global fetch が静かに消える。
     * 除外は「変数が `App\Models\*` であると証明できる場合」に限る (fail-closed)。
     *
     * @param  array{int, int}  $range
     * @param  list<string>  $proven
     */
    private function isProvenModelIdentity(array $range, array $proven): bool
    {
        return self::isProvenModelExpression($this->tokens, $range, $proven, true);
    }

    /**
     * @param  list<array{id: int, text: string, line: int}>  $tokens
     * @param  array{int, int}  $range
     * @param  list<string>  $proven
     * @param  bool  $requireKeyAccess  `->getKey()` / `->id` / `->{fk}_id` のアクセスを要求するか
     */
    private static function isProvenModelExpression(array $tokens, array $range, array $proven, bool $requireKeyAccess): bool
    {
        [$start, $end] = $range;
        if (($tokens[$start]['id'] ?? 0) !== T_VARIABLE) {
            return false;
        }
        if (! in_array($tokens[$start]['text'], $proven, true)) {
            return false;
        }
        if (! $requireKeyAccess) {
            return $start === $end;
        }
        $arrow = $tokens[$start + 1]['text'] ?? '';
        if (($arrow !== '->' && $arrow !== '?->') || ($tokens[$start + 2]['id'] ?? 0) !== T_STRING) {
            return false;
        }
        $property = $tokens[$start + 2]['text'];
        $isCall = ($tokens[$start + 3]['text'] ?? '') === '(';

        if ($isCall) {
            return in_array($property, ['getKey', 'getRouteKey'], true) && $start + 4 === $end;
        }

        return ($property === 'id' || str_ends_with($property, '_id')) && $start + 2 === $end;
    }

    /**
     * スコープ内で `App\Models\*` と証明できた変数の**時系列**。
     *
     * 証明手段は 4 つだけ:
     *   (1) 型付き引数 / モデルファイル内の `$this`
     *   (2) PHPDoc `@var` (**同一スコープの宣言行以降**にだけ効く)
     *   (3) モデル起点クエリの実行結果 / 基底が証明済みの relation 起点クエリからの代入
     *   (4) 同一クラスのメソッド呼び出しで、そのメソッドの**戻り値型宣言**が `App\Models\*`
     * 証明できなければ候補に残す (fail-closed)。
     *
     * ★**時間順序を持つ**のが要点。スコープ全体から先に集めると
     *   `$dto = $input; User::find($dto->user_id); $dto = User::firstOrFail();` のように
     *   「後段の安全な代入で前段の危険な値が安全扱いされる」= 候補が inventory 登録すら
     *   要求されずに消える。使用位置より前の代入だけを見て、再代入で証明を失効させる。
     *
     * @return list<array{index: int, set: list<string>}>
     */
    private function provenTimeline(int $scopeId): array
    {
        if (isset($this->provenCache[$scopeId])) {
            return $this->provenCache[$scopeId];
        }
        [$from, $to] = $this->scopeRange($scopeId);

        /** @var list<string> $set */
        $set = [];
        // (1) モデル自身のファイルでは `$this` がモデルである
        if ($this->selfClass !== null && $this->isModelClass($this->selfClass)) {
            $set[] = '$this';
        }
        // (1) 型付き引数 (promoted constructor property を含む)
        if ($scopeId >= 0) {
            $signatureEnd = $this->signatureEnd($from);
            for ($i = $from; $i <= $signatureEnd; $i++) {
                if ($this->tokens[$i]['id'] !== T_VARIABLE) {
                    continue;
                }
                $type = $this->tokens[$i - 1] ?? null;
                if ($type === null
                    || ! in_array($type['id'], [T_STRING, T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED], true)) {
                    continue;
                }
                if ($this->isModelClass($this->resolveClass($type['text']))) {
                    $set[] = $this->tokens[$i]['text'];
                }
            }
        }

        // (2) PHPDoc `@var` を「宣言行以降に効く証明イベント」としてトークン位置へ写す
        $docEvents = [];
        $scopeFirstLine = $this->tokens[$from]['line'] ?? 0;
        $scopeLastLine = $this->tokens[$to]['line'] ?? PHP_INT_MAX;
        foreach ($this->docVarDeclarations as $declaration) {
            if (! $this->isModelClass($this->resolveClass($declaration['type']))) {
                continue;
            }
            // 宣言行が当該スコープの行範囲内にあるものだけ (別メソッドの `@var` を効かせない)
            if ($declaration['line'] < $scopeFirstLine || $declaration['line'] > $scopeLastLine) {
                continue;
            }
            for ($i = $from; $i <= $to; $i++) {
                if ($this->tokens[$i]['line'] >= $declaration['line']) {
                    $docEvents[$i][] = $declaration['var'];

                    break;
                }
            }
        }

        $timeline = [];
        for ($i = $from; $i <= $to; $i++) {
            foreach ($docEvents[$i] ?? [] as $variable) {
                if (! in_array($variable, $set, true)) {
                    $set[] = $variable;
                    $timeline[] = ['index' => $i, 'set' => $set];
                }
            }
            if (! $this->isAssignmentAt($i)) {
                continue;
            }
            $variable = $this->tokens[$i]['text'];
            $proving = $this->assignmentProvesModel($i, $set);
            $set = array_values(array_filter($set, static fn (string $v): bool => $v !== $variable));
            if ($proving) {
                $set[] = $variable;
            }
            $timeline[] = ['index' => $i, 'set' => $set];
        }

        $this->provenCache[$scopeId] = $timeline;

        return $timeline;
    }

    /**
     * 位置 `$at` の直前までに証明されているモデル変数。
     *
     * @return list<string>
     */
    private function provenAt(int $scopeId, int $at): array
    {
        [$from] = $this->scopeRange($scopeId);
        $timeline = $this->provenTimeline($scopeId);
        $set = [];
        // 代入イベントが 1 つも無い場合は初期集合 (型付き引数 / $this) を復元する必要がある
        if ($timeline === []) {
            return $this->initialProvenSet($scopeId, $from);
        }
        $set = $this->initialProvenSet($scopeId, $from);
        foreach ($timeline as $entry) {
            if ($entry['index'] >= $at) {
                break;
            }
            $set = $entry['set'];
        }

        return $set;
    }

    /**
     * 型付き引数 / `$this` による初期の証明集合。
     *
     * @return list<string>
     */
    private function initialProvenSet(int $scopeId, int $from): array
    {
        $set = [];
        if ($this->selfClass !== null && $this->isModelClass($this->selfClass)) {
            $set[] = '$this';
        }
        if ($scopeId < 0) {
            return $set;
        }
        $signatureEnd = $this->signatureEnd($from);
        for ($i = $from; $i <= $signatureEnd; $i++) {
            if ($this->tokens[$i]['id'] !== T_VARIABLE) {
                continue;
            }
            $type = $this->tokens[$i - 1] ?? null;
            if ($type === null
                || ! in_array($type['id'], [T_STRING, T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED], true)) {
                continue;
            }
            if ($this->isModelClass($this->resolveClass($type['text']))) {
                $set[] = $this->tokens[$i]['text'];
            }
        }

        return $set;
    }

    /**
     * 位置 `$i` の代入がモデルを証明するか。
     *
     * @param  list<string>  $proven  その時点で証明済みの変数
     */
    private function assignmentProvesModel(int $i, array $proven): bool
    {
        // (3) モデル起点クエリの実行結果 (代入式そのものが候補として分類を要求されるため循環しない)
        $modelRoot = $this->staticRootAt($i + 2);
        if ($modelRoot !== null
            && ! str_starts_with($modelRoot['kind'], 'DB:')
            && $this->chainEndsWithExecutor($modelRoot['start'])) {
            return true;
        }
        if (($this->tokens[$i + 2]['id'] ?? 0) !== T_VARIABLE) {
            return false;
        }
        $arrow = $this->tokens[$i + 3]['text'] ?? '';
        if (($arrow !== '->' && $arrow !== '?->') || ($this->tokens[$i + 4]['id'] ?? 0) !== T_STRING) {
            return false;
        }
        if (($this->tokens[$i + 5]['text'] ?? '') !== '(') {
            return false;
        }

        // (4) 同一クラスのメソッドで戻り値型宣言が `App\Models\*`
        //     (宣言された型は PHP が実行時に強制するので、これは形ではなく型の証明である)
        if ($this->tokens[$i + 2]['text'] === '$this') {
            $returnType = $this->methodReturnTypes[$this->tokens[$i + 4]['text']] ?? null;

            return $returnType !== null && $this->isModelClass($this->resolveClass($returnType));
        }

        // (3) relation 起点クエリ。**基底変数が既にモデルと証明されている場合のみ**受理する
        //     (`$dto = $input->payload()->dto();` を受理すると `$dto->user_id` が
        //     「モデル由来」に化け、候補が inventory 登録すら要求されずに消える)
        if (! in_array($this->tokens[$i + 2]['text'], $proven, true)) {
            return false;
        }
        $close = self::matchingParenthesis($this->tokens, $i + 5);
        if ($close === null) {
            return false;
        }
        $after = $this->tokens[$close + 1]['text'] ?? '';

        return $after === '->' || $after === '?->';
    }

    /** `function` トークン位置から引数リストの `)` 位置を返す。 */
    private function signatureEnd(int $functionToken): int
    {
        $count = count($this->tokens);
        for ($i = $functionToken; $i < $count; $i++) {
            if ($this->tokens[$i]['text'] === '(') {
                return self::matchingParenthesis($this->tokens, $i) ?? $i;
            }
        }

        return $functionToken;
    }

    /** chain の終端トークン位置 (`;` / 同深さの `,` / 囲みの外側まで)。 */
    private function chainEnd(int $start): int
    {
        $count = count($this->tokens);
        $depth = 0;
        for ($i = $start; $i < $count; $i++) {
            $text = $this->tokens[$i]['text'];
            if ($text === '(' || $text === '[' || $text === '{') {
                $depth++;

                continue;
            }
            if ($text === ')' || $text === ']' || $text === '}') {
                $depth--;
                if ($depth < 0) {
                    return $i - 1;
                }

                continue;
            }
            if ($depth === 0 && ($text === ';' || $text === ',')) {
                return $i - 1;
            }
        }

        return $count - 1;
    }

    /** 配列要素などの式の終端 (同深さの `,` / 範囲末尾)。 */
    private function expressionEnd(int $start, int $limit): int
    {
        $depth = 0;
        for ($i = $start; $i <= $limit; $i++) {
            $text = $this->tokens[$i]['text'];
            if ($text === '(' || $text === '[' || $text === '{') {
                $depth++;

                continue;
            }
            if ($text === ')' || $text === ']' || $text === '}') {
                if ($depth === 0) {
                    return $i - 1;
                }
                $depth--;

                continue;
            }
            if ($depth === 0 && $text === ',') {
                return $i - 1;
            }
        }

        return $limit;
    }

    private function scopeNameAt(int $i): string
    {
        $id = $this->scopeIdOf[$i] ?? -1;

        return $id < 0 ? '__file' : $this->scopes[$id]['name'];
    }

    private function scopeSource(int $scopeId): string
    {
        if ($scopeId < 0) {
            return self::join($this->tokens, 0, count($this->tokens) - 1);
        }

        return self::join($this->tokens, $this->scopes[$scopeId]['start'], $this->scopes[$scopeId]['end']);
    }

    private function displayPath(): string
    {
        return str_starts_with($this->relativePath, 'app/')
            ? substr($this->relativePath, 4)
            : $this->relativePath;
    }

    /** `new` の直後などに現れるクラス名を FQCN へ解決する。 */
    private function classNameAt(int $i): ?string
    {
        $token = $this->tokens[$i] ?? null;
        if ($token === null
            || ! in_array($token['id'], [T_STRING, T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED], true)) {
            return null;
        }

        return $this->resolveClass($token['text']);
    }

    /** 短縮名 / 修飾名を FQCN へ解決する (import → 同一 namespace の順)。 */
    private function resolveClass(string $name): string
    {
        if (str_starts_with($name, '\\')) {
            return ltrim($name, '\\');
        }
        $parts = explode('\\', $name);
        $first = $parts[0];
        if (isset($this->imports[$first])) {
            $rest = array_slice($parts, 1);

            return $rest === [] ? $this->imports[$first] : $this->imports[$first].'\\'.implode('\\', $rest);
        }

        return $this->namespace === '' ? $name : $this->namespace.'\\'.$name;
    }

    private function isModelClass(?string $fqcn): bool
    {
        return $fqcn !== null && str_starts_with($fqcn, 'App\\Models\\');
    }

    private static function shortName(string $fqcn): string
    {
        $position = strrpos($fqcn, '\\');

        return $position === false ? $fqcn : substr($fqcn, $position + 1);
    }

    /**
     * 引数リストの各引数のトークン範囲 (start, end とも inclusive)。
     *
     * @param  list<array{id: int, text: string, line: int}>  $tokens
     * @param  int  $open  `(` のトークン位置
     * @return list<array{int, int}>
     */
    private static function argumentRanges(array $tokens, int $open): array
    {
        $close = self::matchingParenthesis($tokens, $open);
        if ($close === null || $close === $open + 1) {
            return [];
        }
        $ranges = [];
        $depth = 0;
        $start = $open + 1;
        for ($i = $open + 1; $i < $close; $i++) {
            $text = $tokens[$i]['text'];
            if ($text === '(' || $text === '[' || $text === '{') {
                $depth++;

                continue;
            }
            if ($text === ')' || $text === ']' || $text === '}') {
                $depth--;

                continue;
            }
            if ($depth === 0 && $text === ',') {
                $ranges[] = [$start, $i - 1];
                $start = $i + 1;
            }
        }
        if ($start <= $close - 1) {
            $ranges[] = [$start, $close - 1];
        }

        return $ranges;
    }

    /**
     * 引数が単一の文字列リテラルならその値、そうでなければ null。
     *
     * @param  list<array{id: int, text: string, line: int}>  $tokens
     * @param  array{int, int}  $range
     */
    private static function literalOf(array $tokens, array $range): ?string
    {
        [$start, $end] = $range;
        if ($start !== $end || ($tokens[$start]['id'] ?? 0) !== T_CONSTANT_ENCAPSED_STRING) {
            return null;
        }

        return self::literalValue($tokens[$start]['text']);
    }

    /**
     * 列名を表す引数を正規化して返す (`'users.id'` → `id`)。
     *
     * `$model->getKeyName()` / `$model->getQualifiedKeyName()` も主キー列とみなす。
     *
     * @param  list<array{id: int, text: string, line: int}>  $tokens
     * @param  array{int, int}  $range
     */
    private static function columnOf(array $tokens, array $range): ?string
    {
        $literal = self::literalOf($tokens, $range);
        if ($literal !== null) {
            return self::normalizeColumn($literal);
        }
        [$start, $end] = $range;
        for ($i = $start; $i <= $end; $i++) {
            if (($tokens[$i]['id'] ?? 0) === T_STRING
                && in_array($tokens[$i]['text'], ['getKeyName', 'getQualifiedKeyName'], true)) {
                return 'id';
            }
        }

        return null;
    }

    /** `'users.id'` → `id` / `'u.id'` → `id`。 */
    private static function normalizeColumn(string $column): string
    {
        $position = strrpos($column, '.');

        return $position === false ? $column : substr($column, $position + 1);
    }

    /** 文字列リテラルのトークンテキストから引用符を外す。 */
    private static function literalValue(string $text): string
    {
        if (strlen($text) < 2) {
            return $text;
        }
        $quote = $text[0];
        if ($quote !== "'" && $quote !== '"') {
            return $text;
        }

        return stripcslashes(substr($text, 1, -1));
    }

    /** `whereUuid` → `uuid` のような magic where の列名変換。 */
    private static function snake(string $studly): string
    {
        $snake = preg_replace('/(?<!^)[A-Z]/u', '_$0', $studly);

        return strtolower($snake ?? $studly);
    }

    /**
     * `(` の位置から対応する `)` の位置。
     *
     * @param  list<array{id: int, text: string, line: int}>  $tokens
     */
    private static function matchingParenthesis(array $tokens, int $open): ?int
    {
        $count = count($tokens);
        $depth = 0;
        for ($i = $open; $i < $count; $i++) {
            if ($tokens[$i]['text'] === '(') {
                $depth++;
            } elseif ($tokens[$i]['text'] === ')') {
                $depth--;
                if ($depth === 0) {
                    return $i;
                }
            }
        }

        return null;
    }

    /**
     * スコープ (メソッド / routes の疑似スコープ) の一覧。
     *
     * `app/**` はメソッド境界。`routes/*.php` はクラス/メソッドが無いため疑似スコープ
     * (`__file` / `__closure{n}` / `__fn{n}`) を使う。疑似スコープが無いと
     * 「route closure に直 fetch を書く」経路を key 化できず gate が実現しない。
     *
     * @param  list<array{id: int, text: string, line: int}>  $tokens
     * @return list<array{name: string, start: int, end: int}>
     */
    private static function scopesOf(array $tokens): array
    {
        $count = count($tokens);
        $scopes = [];
        /** @var list<array{named: bool, end: int}> $stack */
        $stack = [];
        $anonymous = 0;

        for ($i = 0; $i < $count; $i++) {
            while ($stack !== [] && $stack[count($stack) - 1]['end'] < $i) {
                array_pop($stack);
            }
            if ($tokens[$i]['id'] !== T_FUNCTION && $tokens[$i]['id'] !== T_FN) {
                continue;
            }
            if (($tokens[$i - 1]['id'] ?? 0) === T_USE) {
                continue; // `use function ...`
            }
            $j = $i + 1;
            if (($tokens[$j]['text'] ?? '') === '&') {
                $j++;
            }
            // メソッド名は予約語でもよい (`function for(...)` は T_FOR になる) ため、
            // 「`(` でない = 名前がある」で判定する。T_STRING 限定にすると
            // 予約語名のメソッドが匿名クロージャ扱いになりスコープ名がずれる
            $named = ($tokens[$j]['text'] ?? '(') !== '(';
            $name = $named ? $tokens[$j]['text'] : null;

            $open = null;
            for ($k = $j; $k < $count; $k++) {
                if ($tokens[$k]['text'] === '(') {
                    $open = $k;

                    break;
                }
            }
            if ($open === null) {
                continue;
            }
            $close = self::matchingParenthesis($tokens, $open);
            if ($close === null) {
                continue;
            }
            $end = self::bodyEnd($tokens, $close + 1);
            if ($end === null) {
                continue; // abstract / interface の宣言のみ
            }

            $hasNamed = false;
            foreach ($stack as $entry) {
                $hasNamed = $hasNamed || $entry['named'];
            }

            if ($named && $name !== null) {
                // 可視性修飾子まで遡って start にする (private 判定に要る)
                $scopes[] = ['name' => $name, 'start' => self::modifiersStart($tokens, $i), 'end' => $end];
                $stack[] = ['named' => true, 'end' => $end];

                continue;
            }
            if ($hasNamed) {
                continue; // メソッド内のクロージャはメソッドスコープに属させる
            }
            $anonymous++;
            $scopes[] = [
                'name' => ($tokens[$i]['id'] === T_FN ? '__fn' : '__closure').$anonymous,
                'start' => self::modifiersStart($tokens, $i),
                'end' => $end,
            ];
            $stack[] = ['named' => false, 'end' => $end];
        }

        return $scopes;
    }

    /**
     * `function` トークンから可視性修飾子まで遡った開始位置。
     *
     * @param  list<array{id: int, text: string, line: int}>  $tokens
     */
    private static function modifiersStart(array $tokens, int $functionToken): int
    {
        $modifiers = [T_PUBLIC, T_PRIVATE, T_PROTECTED, T_STATIC, T_FINAL, T_ABSTRACT, T_READONLY];
        $start = $functionToken;
        while ($start > 0 && in_array($tokens[$start - 1]['id'], $modifiers, true)) {
            $start--;
        }

        return $start;
    }

    /**
     * 関数シグネチャの `)` の次から本体の終端位置を求める。
     *
     * @param  list<array{id: int, text: string, line: int}>  $tokens
     */
    private static function bodyEnd(array $tokens, int $from): ?int
    {
        $count = count($tokens);
        $depth = 0;
        for ($i = $from; $i < $count; $i++) {
            $text = $tokens[$i]['text'];
            if ($text === '(') {
                $depth++;

                continue;
            }
            if ($text === ')') {
                $depth--;

                continue;
            }
            if ($depth !== 0) {
                continue;
            }
            if ($text === ';') {
                return null;
            }
            if ($text === '{') {
                $close = self::matchingBrace($tokens, $i);

                return $close ?? $count - 1;
            }
            if ($text === '=>') {
                return self::arrowFunctionEnd($tokens, $i + 1);
            }
        }

        return null;
    }

    /** @param  list<array{id: int, text: string, line: int}>  $tokens */
    private static function matchingBrace(array $tokens, int $open): ?int
    {
        $count = count($tokens);
        $depth = 0;
        for ($i = $open; $i < $count; $i++) {
            if ($tokens[$i]['text'] === '{') {
                $depth++;
            } elseif ($tokens[$i]['text'] === '}') {
                $depth--;
                if ($depth === 0) {
                    return $i;
                }
            }
        }

        return null;
    }

    /** @param  list<array{id: int, text: string, line: int}>  $tokens */
    private static function arrowFunctionEnd(array $tokens, int $from): int
    {
        $count = count($tokens);
        $depth = 0;
        for ($i = $from; $i < $count; $i++) {
            $text = $tokens[$i]['text'];
            if ($text === '(' || $text === '[' || $text === '{') {
                $depth++;

                continue;
            }
            if ($text === ')' || $text === ']' || $text === '}') {
                $depth--;
                if ($depth < 0) {
                    return $i - 1;
                }

                continue;
            }
            if ($depth === 0 && ($text === ';' || $text === ',')) {
                return $i - 1;
            }
        }

        return $count - 1;
    }

    /**
     * 名前空間 import (短縮名 => FQCN)。
     *
     * group use (`use App\Models\{User, Project};`) は短縮名の対応が曖昧になるため受理しない
     * (受理しない = 候補にしない方向ではなく、そのファイルでの解決に失敗して候補が減るため、
     * 本リポジトリで group use が使われていないことを前提とする)。
     *
     * @param  list<array{id: int, text: string, line: int}>  $tokens
     * @return array<string, string>
     */
    private static function importsOf(array $tokens): array
    {
        $imports = [];
        $count = count($tokens);
        $depth = 0;

        for ($i = 0; $i < $count; $i++) {
            $text = $tokens[$i]['text'];
            if ($text === '{') {
                $depth++;

                continue;
            }
            if ($text === '}') {
                $depth--;

                continue;
            }
            if ($tokens[$i]['id'] !== T_USE || $depth !== 0) {
                continue;
            }
            if (($tokens[$i + 1]['text'] ?? '') === '(') {
                continue; // クロージャの lexical use
            }
            if (($tokens[$i + 1]['id'] ?? 0) === T_FUNCTION || ($tokens[$i + 1]['id'] ?? 0) === T_CONST) {
                continue; // `use function ...` / `use const ...`
            }

            // group use (`use App\Models\{User, Project};`) と複数 use (`use A, B;`) を
            // ここで展開する。無視すると `App\Models\*` の解決に失敗して**候補が消える**
            // (= fail-open) ため、書き方の違いで gate を黙らせられないようにする
            $prefix = '';
            $name = '';
            $alias = null;
            $expectAlias = false;
            for ($j = $i + 1; $j < $count; $j++) {
                $token = $tokens[$j];
                $text = $token['text'];

                if ($token['id'] === T_AS) {
                    $expectAlias = true;

                    continue;
                }
                if ($expectAlias && $text !== ',' && $text !== ';' && $text !== '}') {
                    $alias = $text;

                    continue;
                }
                if ($text === '{') {
                    $prefix = rtrim($name, '\\').'\\';
                    $name = '';

                    continue;
                }
                if ($text === ',' || $text === ';' || $text === '}') {
                    if ($name !== '') {
                        $fqcn = ltrim($prefix.$name, '\\');
                        $position = strrpos($fqcn, '\\');
                        $short = $alias ?? ($position === false ? $fqcn : substr($fqcn, $position + 1));
                        $imports[$short] = $fqcn;
                    }
                    $name = '';
                    $alias = null;
                    $expectAlias = false;
                    if ($text === ';') {
                        break;
                    }
                    if ($text === '}') {
                        $prefix = '';
                    }

                    continue;
                }
                $name .= $text;
            }
        }

        return $imports;
    }

    /** @param  list<array{id: int, text: string, line: int}>  $tokens */
    private static function namespaceOf(array $tokens): string
    {
        $count = count($tokens);
        for ($i = 0; $i < $count; $i++) {
            if ($tokens[$i]['id'] !== T_NAMESPACE) {
                continue;
            }
            $name = '';
            for ($j = $i + 1; $j < $count; $j++) {
                if ($tokens[$j]['text'] === ';' || $tokens[$j]['text'] === '{') {
                    break;
                }
                $name .= $tokens[$j]['text'];
            }

            return trim($name, '\\');
        }

        return '';
    }

    /**
     * ファイルが宣言する最初のクラスの FQCN (`self::` / `static::` の解決に使う)。
     *
     * @param  list<array{id: int, text: string, line: int}>  $tokens
     */
    private static function selfClassOf(array $tokens): ?string
    {
        $namespace = self::namespaceOf($tokens);
        $count = count($tokens);
        for ($i = 0; $i < $count; $i++) {
            if ($tokens[$i]['id'] !== T_CLASS || ($tokens[$i + 1]['id'] ?? 0) !== T_STRING) {
                continue;
            }
            $prev = $tokens[$i - 1]['text'] ?? '';
            if ($prev === '::' || $prev === 'new') {
                continue; // `Foo::class` / 匿名クラス
            }

            return $namespace === '' ? $tokens[$i + 1]['text'] : $namespace.'\\'.$tokens[$i + 1]['text'];
        }

        return null;
    }

    /**
     * PHPDoc `@var Type $variable` の宣言 (**行番号付き**)。
     *
     * ★ファイル全体で 1 つのマップに畳んではならない。畳むと別メソッドの `@var` が
     * 同名変数を証明してしまい (`unsafe(object $dto)` が `safe()` の `@var User $dto` で
     * 通ってしまう)、候補が inventory 登録すら要求されずに消える。
     *
     * @return list<array{var: string, type: string, line: int}>
     */
    private static function docVarDeclarationsOf(string $source): array
    {
        $declarations = [];
        $matched = preg_match_all(
            '/@var\s+([\\\\A-Za-z0-9_|]+)\s+(\$[A-Za-z_][A-Za-z0-9_]*)/u',
            $source,
            $matches,
            PREG_OFFSET_CAPTURE,
        );
        if ($matched === false) {
            return [];
        }
        foreach ($matches[2] as $index => $capture) {
            $types = array_values(array_filter(
                explode('|', $matches[1][$index][0]),
                static fn (string $type): bool => $type !== '' && $type !== 'null',
            ));
            if ($types === []) {
                continue;
            }
            $declarations[] = [
                'var' => $capture[0],
                'type' => $types[0],
                'line' => substr_count(substr($source, 0, $capture[1]), "\n") + 1,
            ];
        }

        return $declarations;
    }

    /**
     * 意味のあるトークンだけを正規化する。
     *
     * コメント / docblock / 空白 / inline HTML / 補間文字列の中身を除去する。
     * **文字列リテラル本体は残す** (列名 `'id'` の照合に要るため)。ただし
     * 内容をコードとして解釈することはないので、コメント中の `Foo::destroy()` のような
     * 誤検出は起きない。
     *
     * @return list<array{id: int, text: string, line: int}>
     */
    private static function significantTokens(string $source): array
    {
        $ignored = [
            T_COMMENT,
            T_DOC_COMMENT,
            T_WHITESPACE,
            T_INLINE_HTML,
            T_OPEN_TAG,
            T_OPEN_TAG_WITH_ECHO,
            T_CLOSE_TAG,
            T_ENCAPSED_AND_WHITESPACE,
        ];

        $result = [];
        $line = 1;
        foreach (token_get_all(self::withOpenTag($source)) as $token) {
            if (is_array($token)) {
                $line = $token[2];
                if (in_array($token[0], $ignored, true)) {
                    continue;
                }
                $result[] = ['id' => $token[0], 'text' => $token[1], 'line' => $line];

                continue;
            }
            $result[] = ['id' => -1, 'text' => $token, 'line' => $line];
        }

        return $result;
    }

    /**
     * @param  list<array{id: int, text: string, line: int}>  $tokens
     */
    private static function join(array $tokens, int $start, int $end): string
    {
        $parts = [];
        for ($i = max($start, 0); $i <= $end && $i < count($tokens); $i++) {
            $parts[] = $tokens[$i]['text'];
        }

        return implode(' ', $parts);
    }

    private static function withOpenTag(string $source): string
    {
        return str_starts_with(ltrim($source), '<?php') ? $source : '<?php '.$source;
    }
}
