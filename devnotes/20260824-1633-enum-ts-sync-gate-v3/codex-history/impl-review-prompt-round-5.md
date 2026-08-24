# Round 5: Round 4 の指摘への対応

Round 4 の Warning 2 件に対応した。

## 対応マトリクス

| # | 区分 | 指摘 | 判断 | 対応内容 |
|---|---|---|---|---|
| 1 | Warning | `resolveOwner()` の失敗メッセージだけ、撤回した強い因果関係 (「型が縮んで候補が静かに消える」) が残っている | **対応する** | 「本番と異なる型世界で解析することになり、候補が静かに消える**恐れ**がある」へ直した (docblock / `docs/architecture.md` と同じ言い方に揃えた) |
| 2 | Warning | `composer test` のフルレーンが green でない | **対応する** | 他のレーンを完全に止めて再実行し、**全 green** を確認した |

## 変更後の `resolveOwner()`

```ts
export const resolveOwner = (
    relative: string,
    packageDirs: readonly string[],
    availableOwners: ReadonlySet<string>,
): string => {
    const owner = ownerNameOf(relative, packageDirs);
    if (!availableOwners.has(owner)) {
        throw new EnumTsSyncError(
            relative,
            `所有者 ${owner} の program がありません (自前の tsconfig.json を持たないパッケージです。ルートの設定で読むと本番と異なる型世界で解析することになり、候補が静かに消える恐れがあるので、扱いを決めてから走らせること)`,
        );
    }
    return owner;
};
```

## 検証コマンドの結果 (すべて他のレーンを止めた状態で実行)

| コマンド | 結果 |
|---|---|
| `composer test` | **7835 tests / 7833 passed / 2 skipped / 0 failed** (risky 5。所要 698 秒) |
| `composer phpstan` (level 10) | `[OK] No errors` |
| `vendor/bin/pint --test` | passed |
| `pnpm lint` | green |
| `pnpm typecheck` | green |
| `pnpm test` | **179 files / 2516 tests passed** |
| `pnpm build` | green |
| `pnpm typecheck:packages` | green |
| `pnpm build:packages` | green |
| `pnpm test:packages` | **11 files / 129 tests passed** |

前回 (Round 4 時点) に 1 件だけ error になっていた `BughuntSelfTestExecutionTest` は、
**他のレーン (vitest) を並行実行していたことによる CPU 競合**が原因だった。
内側の `scripts/bug-hunt-shard.sh self-test` は変更前の main で単独実行しても 154.8 秒かかり、
その中の 1 段に 120 秒の上限が掛かっている。競合を外したフル実行では
worktree 側も**変更前の main も同じく green** である
(main の基準実行: 7835 tests / 7833 passed / 0 failed)。

故障注入は累計 21 件、すべて赤を実測している (記録は `../fault-injection-log.md`)。
