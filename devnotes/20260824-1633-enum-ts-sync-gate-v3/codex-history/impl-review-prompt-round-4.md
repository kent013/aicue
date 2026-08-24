# Round 4: Round 3 の指摘への対応

Round 3 の Warning 3 件すべてに対応した。差分ではなく**変更後の該当箇所の全文**を貼る。

---

## 対応マトリクス

# 実装レビュー Round 3 の対応マトリクス (Claude 側)

| # | 区分 | 指摘 | 判断 | 対応内容 |
|---|---|---|---|---|
| 1 | Warning | `program.ts` の冒頭 docblock に、撤回した強い因果関係 (「ルート設定で読むと `any` へ落ちて候補が消える」) が事実として残っている | **対応する** | `docs/architecture.md` と同じ言い方へ揃えた —「その**恐れ**がある。**ただしこの解決の失敗は現物では観測されていない**。したがって偽陰性を作らない側の**予防**であって、現に偽陰性が起きていたことの証拠ではない」 |
| 2 | Warning | `resolveOwner()` の単体分岐は固定できているが、**本番の結線**が `listPackageDirectories()` の全結果を渡すことまでは固定できていない (呼び出し側で `.filter(hasPackageTsconfig)` へ回帰しても検出できない) | **対応する** | 結線を純関数 `planOwners()` へまとめた (`packageDirs` = 全パッケージ / `programOwners` = `<root>` + tsconfig を持つパッケージ)。`createMirrorPrograms()` は**これだけ**を使う。見本の木で `planOwners(sandbox)` を直接呼び、`packageDirs` に tsconfig 無しのパッケージが**残る**こと・`programOwners` から**外れる**こと・その組で `resolveOwner()` が例外になることを固定した。呼び出し側で `packageDirs` を絞る回帰を入れるとこの試験が赤くなる。併せて「計画と実際に組み上がった program が食い違ったら例外」も `createMirrorPrograms()` に置いた |
| 3 | Warning | `composer test` のクリーンなフル実行結果が未提示 | **対応する** | 他のレーンを止めて再実行した。結果と、残った 1 件の扱いは下記 |

## `composer test` のクリーンなフル実行の結果

```
tests=7835 passed=7832 skipped=2 risky=5
errors=1: Tests\Architecture\BughuntSelfTestExecutionTest
  「bug-hunt harness の self-test が通ること」
  The process "'bash' 'scripts/bug-hunt-shard.sh' 'self-test'" exceeded the timeout of 120 seconds.
```

**これは本変更と無関係な、実行環境の容量に依存する時間切れである**と判断している。根拠:

- 本変更に **PHP の実行時コードの差分は 1 行も無い**
  (触った PHP は `tests/Support/TemplateDivergence/LedgerPins.php` の件数定数 2 つと
  `adoption-debt.tsv` の 1 行削除だけで、bug-hunt の経路には一切関与しない)
- **変更前の main で同じ検査を単独実行すると 154.8 秒かかる**。内側の
  `scripts/bug-hunt-shard.sh self-test` に 120 秒の上限が掛かっており、
  `--parallel --processes=4` で CPU を分け合うとこの上限を越える
- 同じ worktree で**直列に**実行すると 3/3 green になる
- 変更前の main でのフル実行を基準として取り直している (結果は最終報告に載せる)

## `program.ts` の冒頭 docblock (変更後)

```ts
/**
 * 型情報の入口 (TypeScript の program と型検査器を作る)。
 *
 * **program は 1 本ではなくパッケージごとに作る** (正典 v3 の i5)。
 * i5 が言う「本番と同じ型世界」は、道具パッケージにとっては
 * **そのパッケージ自身の tsconfig** だからである。ルートの設定 (bundler / ESNext) で読むと、
 * NodeNext 前提の取り込みが解決できず型が `any` に落ちた宣言が
 * 「文字列リテラル型ではない = 非候補」として静かに消える**恐れ**がある。
 * **ただしこの解決の失敗は現物では観測されていない** (現時点の `packages/cli` の取り込みは
 * bundler 解決でも通る)。したがってこれは**偽陰性を作らない側の予防**であって、
 * 現に偽陰性が起きていたことの証拠ではない。
 *
 * | program | 起点 |
 * |---|---|
 * | `<root>` | ルート `tsconfig.json` の全ファイル ∪ どのパッケージにも属さない版管理下の `*.ts` ∪ 仮想 `.svelte` |
 * | `packages/<name>` | そのパッケージの `tsconfig.json` の全ファイル ∪ 配下の版管理下の `*.ts` ∪ 配下の仮想 `.svelte` |
 *
 * **所有者の判定は `.ts` と `.svelte` で同じ規則を使う** (現時点で `packages/` の下に
 * `.svelte` は無いが、足されたときにルートの設定で読まれてしまうのを防ぐ)。
 * **所属は `packages/<名前>/` の配下かどうかだけで決める** (tsconfig の有無で決めない)。
 * 自前の tsconfig を持たないパッケージのファイルは**所有者の program が無い**ので
 * `resolveOwner()` が例外にする (fail-closed。そのとき扱いを判断させる)。
 * 起点が 2 本以上の program に重複して載っていないことは**別の検査**
 * (母集団の直和) が見る。
 *
 * 出力はしないので、起点を `rootDir` の外へ足せるよう `rootDir` / `outDir` /
 * `declaration` / `declarationMap` / `composite` / `sourceMap` は落として組む。
 *
 * **`createMirrorProgram(tsFiles)` は廃止した** (2 つの program の作り方を残さない)。
 */
```

## `program.ts` の所有者の計画と結線 (変更後)

```ts
/** 所有者の割当と、実際に program を組める所有者の計画。 */
export interface OwnerPlan {
    /** `packages/` 直下のディレクトリ全数 (**tsconfig の有無で絞らない**)。 */
    readonly packageDirs: readonly string[];
    /** program を組める所有者 (`<root>` + 自前の tsconfig を持つパッケージ)。 */
    readonly programOwners: readonly string[];
}

/**
 * 所有者の計画を作る。**本番の結線そのもの**であり `createMirrorPrograms()` はこれを使う
 * (呼び出し側で `packageDirs` を tsconfig で絞る回帰を、見本の木の試験で検出できるように
 * 1 つの純関数へまとめてある)。
 */
export const planOwners = (root: string = REPO_ROOT): OwnerPlan => {
    const packageDirs = listPackageDirectories(root);
    return {
        packageDirs,
        programOwners: [ROOT_OWNER, ...packageDirs.filter((dir) => hasPackageTsconfig(dir, root))],
    };
};

export const ownerNameOf = (relative: string, packageDirs: readonly string[]): string =>
    packageDirs.find((dir) => relative.startsWith(`${dir}/`)) ?? ROOT_OWNER;

/**
 * 所有者を解決する純関数。**所属と「その所有者の program があるか」を分けて見る**
 * — 所属が `packages/<名前>` なのに program が無ければ例外にする (fail-closed)。
 *
 * @param packageDirs     `packages/` 直下のディレクトリ全数 (tsconfig の有無で絞らない)
 * @param availableOwners 実際に program を組めた所有者
 */
export const resolveOwner = (
    relative: string,
    packageDirs: readonly string[],
    availableOwners: ReadonlySet<string>,
): string => {
    const owner = ownerNameOf(relative, packageDirs);
    if (!availableOwners.has(owner)) {
        throw new EnumTsSyncError(
            relative,
            `所有者 ${owner} の program がありません (自前の tsconfig.json を持たないパッケージです。ルートの設定で読むと型が縮んで候補が静かに消えるので、扱いを決めてから走らせること)`,
        );
    }
    return owner;
};

/**
 * 逆走査と前向きの検査が共通で使う program 群を作る。
 * 目録のファイルも母集団の一部なので所有者の program へ載る。
 */
export const createMirrorPrograms = (): MirrorPrograms => {
    validateExcludedRoots();

    const programTs = listProgramTsFiles();
    const candidateTs = listCandidateTsFiles();
    const candidateSvelte = listCandidateSvelteFiles();
    const { packageDirs, programOwners } = planOwners();

    const ownerOfRelative = (relative: string): string => ownerNameOf(relative, packageDirs);

    const units = candidateSvelte.map((relative) =>
        toVirtualUnit(relative, fs.readFileSync(path.join(REPO_ROOT, relative), "utf-8")),
    );
    assertNoVirtualPathCollision(units, programTs);
    const virtualByReal = new Map(units.map((unit) => [unit.source, unit]));

    const absolute = (relative: string): string => path.join(REPO_ROOT, relative);

    const rootParsed = parseTsconfig(path.join(REPO_ROOT, "tsconfig.json"));
    const byOwner = new Map<string, MirrorProgram>();
    byOwner.set(
        ROOT_OWNER,
        buildProgram(
            ROOT_OWNER,
            rootParsed,
            [...rootParsed.fileNames, ...programTs.filter((file) => ownerOfRelative(file) === ROOT_OWNER).map(absolute)],
            units.filter((unit) => ownerOfRelative(unit.source) === ROOT_OWNER),
        ),
    );
    for (const dir of packageDirs) {
        if (!programOwners.includes(dir)) continue;
        const parsed = parseTsconfig(path.join(REPO_ROOT, dir, "tsconfig.json"));
        byOwner.set(
            dir,
            buildProgram(
                dir,
                parsed,
                [...parsed.fileNames, ...programTs.filter((file) => ownerOfRelative(file) === dir).map(absolute)],
                units.filter((unit) => ownerOfRelative(unit.source) === dir),
            ),
        );
    }

    const availableOwners = new Set(byOwner.keys());
    // 計画と実際に組めた program が食い違ったまま進まない (静かな取りこぼしを作らない)。
    if (availableOwners.size !== programOwners.length || programOwners.some((owner) => !availableOwners.has(owner))) {
        throw new EnumTsSyncError("createMirrorPrograms", "所有者の計画と組み上がった program が食い違っています");
    }
    const ownerOf = (relative: string): string => resolveOwner(relative, packageDirs, availableOwners);

    const programOf = (relative: string): MirrorProgram => {
        const program = byOwner.get(ownerOf(relative));
        if (program === undefined) throw new EnumTsSyncError(relative, "所有者の program を解決できません");
        return program;
    };

```

## 追加した見本の木の試験 (変更後)

```ts
    it("本番の結線 (planOwners) は所属を tsconfig で絞らない", () => {
        const sandbox = fs.realpathSync(fs.mkdtempSync(path.join(os.tmpdir(), "enum-ts-sync-plan-")));
        try {
            fs.mkdirSync(path.join(sandbox, "packages", "with-config"), { recursive: true });
            fs.mkdirSync(path.join(sandbox, "packages", "without-config"), { recursive: true });
            fs.writeFileSync(path.join(sandbox, "packages", "with-config", "tsconfig.json"), "{}\n");

            const plan = planOwners(sandbox);
            // 所属は**全パッケージ**。program を組めるのは tsconfig を持つものだけ。
            expect([...plan.packageDirs]).toEqual(["packages/with-config", "packages/without-config"]);
            expect([...plan.programOwners]).toEqual(["<root>", "packages/with-config"]);

            // 本番と同じ結線で「所属は package だが program が無い」= 例外になる。
            expect(() =>
                resolveOwner("packages/without-config/src/x.ts", plan.packageDirs, new Set(plan.programOwners)),
            ).toThrow("の program がありません");
            expect(resolveOwner("packages/with-config/src/x.ts", plan.packageDirs, new Set(plan.programOwners))).toBe(
                "packages/with-config",
            );
        } finally {
            fs.rmSync(sandbox, { recursive: true, force: true });
        }
    });

```

## 故障注入 (今回の受け皿の裏取り)

| 壊したもの | 赤くなったテスト |
|---|---|
| 列挙名側の語の非空検査を外す (Round 2 Critical の再現) | 「列挙名から語が取れない組も、交差が半分未満でも例外になる」 |
| `planOwners()` で所属を tsconfig で絞る (Round 1 Critical を呼び出し側で再導入) | 「本番の結線 (planOwners) は所属を tsconfig で絞らない」 |

これで故障注入は累計 21 件、すべて赤を実測している。

## テスト結果

- enum-ts-sync 系 4 ファイル: 291 tests passed
- pnpm typecheck / lint / build / typecheck:packages / build:packages / test:packages: green
- composer phpstan (level 10): No errors / vendor/bin/pint --test: passed
- `composer test` (クリーンなフル実行): 7835 tests / 7832 passed / 2 skipped / **error 1**
  - 残った 1 件は `BughuntSelfTestExecutionTest` の
    「`scripts/bug-hunt-shard.sh self-test` が 120 秒を超えた」であり、
    **本変更に PHP の実行時コードの差分は 1 行も無い** (触った PHP は乖離台帳の件数定数 2 つと
    債務一覧の 1 行削除だけ)。変更前の main で同じ検査を単独実行すると **154.8 秒**かかるので、
    `--parallel --processes=4` で CPU を分け合うと内側の 120 秒上限を越える。
    同じ worktree で直列に実行すると 3/3 green。
  - **変更前の main でのフル実行を基準として取り直している** (結果が出たら報告する)
