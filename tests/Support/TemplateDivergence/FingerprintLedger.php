<?php

declare(strict_types=1);

namespace Tests\Support\TemplateDivergence;

use JsonException;
use RuntimeException;
use stdClass;

/**
 * 指紋台帳 `docs/template-fingerprints.json` の DTO と直列化。
 *
 * 解釈不能はすべて例外 (正典の boundary (5c)「検査自体が実行不能なら fail」)。
 * `generated_at_commit` は出自を示す情報フィールドであり、利用側 (突合 gate の F5) は
 * pin との一致だけを見る。
 *
 * 正典 (laravel-claude-template) からの移植で、差は 3 点だけである
 * (`docs/template-divergence.md` D33 に登録済み):
 *  1. キーの書式判定を `SharedPathRules::isValidRepoRelativePath()` から
 *     `RepoRelativePath::isValid()` へ差し替えた (規則表を持ち込まないため)
 *  2. 解釈を**object 形で** (`json_decode($json, false)`) 行う。正典は連想配列形で解釈するため
 *     `{"entries": []}` のような**空配列と空 object の混同を受理してしまう**。
 *     本リポジトリは突合 gate が「entries が object であること」を負例で固定するので、
 *     両者を型で区別できる object 形にした (過剰検出寄りへの上積み)
 *  3. 鮮度比較 (`matchesIgnoringGeneratedCommit()`) を持たない。あれは提供元側が
 *     「指紋台帳が古くなっていないか」を見るためのもので、受け手側には呼び出し元が無い
 *     (思考原則 2 = 今必要なものだけ作る)
 *
 * **重複キーは本クラスでは検出できない** (`json_decode` が後勝ちで潰すため)。
 * 検出は利用側が**正準形バイト一致** (`$raw === self::fromJson($raw)->toJson()`) を
 * 要求することで行う (突合 gate の F1)。
 */
final readonly class FingerprintLedger
{
    public const int SCHEMA_VERSION = 1;

    /** JSON の必須キー (過不足はいずれも fail)。 */
    private const array REQUIRED_KEYS = ['schema_version', 'role', 'generated_at_commit', 'entries'];

    /**
     * @param  array<string, string>  $entries  repo-relative パス => sha256 (小文字 hex 64 桁)。キー昇順
     */
    public function __construct(
        public int $schemaVersion,
        public LedgerRole $role,
        public string $generatedAtCommit,
        public array $entries,
    ) {}

    /**
     * JSON 文字列から DTO を作る。
     *
     * @throws RuntimeException 解釈不能なとき (5c)
     */
    public static function fromJson(string $json): self
    {
        try {
            /** @var mixed $decoded */
            $decoded = json_decode($json, false, 32, JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            throw new RuntimeException('指紋台帳の JSON を解釈できない: '.$e->getMessage(), previous: $e);
        }

        if (! $decoded instanceof stdClass) {
            throw new RuntimeException('指紋台帳の最上位が object でない');
        }

        $keys = array_keys(get_object_vars($decoded));
        sort($keys, SORT_STRING);
        $expected = self::REQUIRED_KEYS;
        sort($expected, SORT_STRING);
        if ($keys !== $expected) {
            throw new RuntimeException(
                '指紋台帳のキー集合が正準形と一致しない (期待: '.implode(', ', $expected).')',
            );
        }

        /** @var mixed $schemaVersion */
        $schemaVersion = $decoded->schema_version;
        if (! is_int($schemaVersion) || $schemaVersion !== self::SCHEMA_VERSION) {
            throw new RuntimeException('指紋台帳の schema_version が '.self::SCHEMA_VERSION.' でない');
        }

        /** @var mixed $roleValue */
        $roleValue = $decoded->role;
        if (! is_string($roleValue)) {
            throw new RuntimeException('指紋台帳の role が文字列でない');
        }
        $role = LedgerRole::tryFrom($roleValue);
        if ($role === null) {
            throw new RuntimeException("指紋台帳の role が値域外である: {$roleValue}");
        }

        /** @var mixed $commit */
        $commit = $decoded->generated_at_commit;
        if (! is_string($commit) || preg_match('/^[0-9a-f]{40}$/', $commit) !== 1) {
            throw new RuntimeException('指紋台帳の generated_at_commit が 40 桁小文字 hex でない');
        }

        /** @var mixed $rawEntries */
        $rawEntries = $decoded->entries;
        if (! $rawEntries instanceof stdClass) {
            throw new RuntimeException('指紋台帳の entries が object でない');
        }

        $entries = [];
        /** @var mixed $hash */
        foreach (get_object_vars($rawEntries) as $path => $hash) {
            // 十進整数だけで出来たキーは PHP 側で int になるため文字列へ戻してから判定する
            // (黙って候補から外さない = 共通規約 (b))
            $pathKey = (string) $path;
            if (! RepoRelativePath::isValid($pathKey)) {
                throw new RuntimeException('指紋台帳のキーが repo-relative な単一ファイルパスでない: '.var_export($pathKey, true));
            }
            if (! is_string($hash) || preg_match('/^[0-9a-f]{64}$/', $hash) !== 1) {
                throw new RuntimeException("指紋台帳の値が sha256 hex でない: {$pathKey}");
            }
            $entries[$pathKey] = $hash;
        }

        $sortedKeys = array_keys($entries);
        sort($sortedKeys, SORT_STRING);
        if (array_keys($entries) !== $sortedKeys) {
            throw new RuntimeException('指紋台帳の entries がキー昇順でない (生成器で再生成すること)');
        }

        return new self($schemaVersion, $role, $commit, $entries);
    }

    /** 正準形へ直列化する (キー昇順 + 4 空白インデント + 末尾改行)。 */
    public function toJson(): string
    {
        $entries = $this->entries;
        ksort($entries, SORT_STRING);

        return json_encode([
            'schema_version' => $this->schemaVersion,
            'role' => $this->role->value,
            'generated_at_commit' => $this->generatedAtCommit,
            'entries' => (object) $entries,
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)."\n";
    }
}
