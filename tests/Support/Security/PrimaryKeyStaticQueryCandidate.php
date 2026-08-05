<?php

declare(strict_types=1);

namespace Tests\Support\Security;

/**
 * クラス起点の主キー同一性クエリ (`ClassRootedPrimaryKeyQuery`) の候補 1 件。
 *
 * `PrimaryKeyStaticQueryScanner` が抽出し、`ModelDirectFetchInvariantTest` が
 * `DirectFetchInventory::inventory()` と突合する。
 */
final readonly class PrimaryKeyStaticQueryCandidate
{
    /**
     * @param  string  $key  構造 fingerprint 入りの安定 key (行番号を含めない)
     * @param  string  $relativePath  リポジトリ相対パス
     * @param  string  $scopeName  メソッド名、または routes/*.php の疑似スコープ名 (`__file` / `__closure1` / `__fn1`)
     * @param  string  $rootKind  `User` (モデル短縮名) / `DB:users` (テーブル名)
     * @param  string  $predicate  `findOrFail` / `whereKey` / `where:id:=` 等
     * @param  string  $identityArgument  正規化した識別子引数 (cast は除去済み)
     * @param  string  $chainSource  候補式を構成する chain のトークン列
     * @param  string  $methodSource  候補が属するスコープ (メソッド / 疑似スコープ) のトークン列
     * @param  list<string>  $provenModelVariables  当該スコープで `App\Models\*` と証明できた変数名
     * @param  list<string>  $queryResultVariables  候補位置までにクラス起点クエリの実行結果が代入された変数名
     * @param  bool  $tenantScopedIdentity  identity がテナントスコープ済みの解決から確定しているか
     * @param  bool  $sameMethodScanIdentity  identity が同一メソッド内の走査クエリ結果由来か
     * @param  bool  $parameterDerivedIdentity  identity が当該メソッドの引数から導出されているか
     */
    public function __construct(
        public string $key,
        public string $relativePath,
        public string $scopeName,
        public PrimaryKeyPredicateKind $predicateKind,
        public string $rootKind,
        public string $predicate,
        public string $identityArgument,
        public string $chainSource,
        public string $methodSource,
        public array $provenModelVariables,
        public array $queryResultVariables,
        public bool $tenantScopedIdentity,
        public bool $sameMethodScanIdentity,
        public bool $parameterDerivedIdentity,
    ) {}

    /** ordinal を除いた構造 fingerprint (テスト 15 の重複検出に使う)。 */
    public function fingerprint(): string
    {
        return $this->displayPath().'#'.$this->scopeName.'#'.$this->rootKind.'.'.$this->predicate.':'.$this->identityArgument;
    }

    /** key に使う表示パス (`app/` 配下は `app/` を落とし、`routes/` はそのまま)。 */
    public function displayPath(): string
    {
        return str_starts_with($this->relativePath, 'app/')
            ? substr($this->relativePath, 4)
            : $this->relativePath;
    }
}
