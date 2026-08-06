<?php

declare(strict_types=1);

namespace Tests\Support\ExternalFakes;

/**
 * container 差し替え 1 本の宣言 (fake 配線 gate の inventory 要素)。
 *
 * 「宣言 (本ファイル)」と「実証 (ExternalFakeWiringInvariantTest)」を分離する。
 * 本クラスは値の器であり判定ロジックを持たない。
 */
final readonly class ExternalFakeBinding
{
    /**
     * @param  class-string  $abstract  container から解決するキー (interface または具象クラス)
     * @param  class-string  $real  flag off のときに解決されるべきクラス (厳密一致)
     * @param  class-string  $fake  flag on + allowlist 内で解決されるべきクラス (厳密一致)
     * @param  string  $flag  capability flag の config キー
     * @param  list<string>  $allowedEnvironments  fake を許可する環境 allowlist
     * @param  string  $risk  なぜ外部副作用として危険か (レビュー用説明。機械照合しない)
     */
    public function __construct(
        public string $abstract,
        public string $real,
        public string $fake,
        public string $flag,
        public array $allowedEnvironments,
        public string $risk,
    ) {}

    /**
     * データセット名 (テスト出力に出る識別子)。
     *
     * FQCN ベースにする (class basename だと将来 namespace 違いの同名クラスが増えたとき
     * dataset 名が衝突する)。
     */
    public function label(): string
    {
        return str_replace('\\', '.', $this->abstract);
    }
}
