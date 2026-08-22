<?php

declare(strict_types=1);

namespace Tests\Support\Concurrency;

use Webmozart\Assert\Assert;

/**
 * 合図の名前 (これ以外の形は作れない)。
 *
 * ★{@see self::make()} が**唯一の生成口**である ({@see ProcessBarrier} に name() のような
 *   二重入口は置かない)。`ProcessBarrier` のメソッドはすべて `SignalName` を受け取り
 *   `string` を受けない。これで `/` や `..` を含む名前は**型の段階で作れない**
 *   (入口ごとの再検証が要らない)。
 * ★種別ごとに child ID の要否が違う。`go-a` や `ready` (child ID 無し) のような
 *   語彙としては正しいがプロトコル上は不正な組合せも作れない。
 * ★child ID は**実在する 2 つに限定**する (正規表現で 26 文字を許すと `ready-c` が作れてしまい、
 *   「生成できるのは 8 通りだけ」という保証が実体と食い違う)。
 *
 * 生成できるのは次の **8 通りだけ**である:
 *   go / release / ready-a / ready-b / entered-a / entered-b / out-a / out-b
 */
final readonly class SignalName
{
    /**
     * child ID を**取らない**種別 (プロセス全体で 1 つの合図)。
     *
     * @var list<string>
     */
    public const array GLOBAL_KINDS = ['go', 'release'];

    /**
     * child ID を**必ず取る**種別 (子ごとの合図)。
     *
     * @var list<string>
     */
    public const array PER_CHILD_KINDS = ['ready', 'entered', 'out'];

    /**
     * 実在する child ID (固定 2 本。N 本への一般化はしない)。
     *
     * @var list<string>
     */
    public const array CHILD_IDS = ['a', 'b'];

    /** @param non-empty-string $value */
    private function __construct(public string $value) {}

    /**
     * 唯一の生成口。
     *
     * @throws \InvalidArgumentException 種別と child ID の組合せが 8 通りの外
     */
    public static function make(string $kind, ?string $childId = null): self
    {
        if (in_array($kind, self::GLOBAL_KINDS, true)) {
            Assert::null($childId, "{$kind} は child ID を取らない");

            return new self($kind);
        }

        Assert::oneOf($kind, self::PER_CHILD_KINDS);
        Assert::string($childId, "{$kind} は child ID が必須");
        // ★正規表現ではなく実在集合で絞る (`ready-c` を作れなくする)
        Assert::oneOf($childId, self::CHILD_IDS);

        return new self($kind.'-'.$childId);
    }

    /**
     * 許可される完成合図の全集合 (未知の完成ファイルの検出に使う)。
     *
     * @return list<self> ちょうど 8 件
     */
    public static function all(): array
    {
        $names = [];

        foreach (self::GLOBAL_KINDS as $kind) {
            $names[] = self::make($kind);
        }

        foreach (self::PER_CHILD_KINDS as $kind) {
            foreach (self::CHILD_IDS as $childId) {
                $names[] = self::make($kind, $childId);
            }
        }

        return $names;
    }

    public function equals(self $other): bool
    {
        return $this->value === $other->value;
    }
}
