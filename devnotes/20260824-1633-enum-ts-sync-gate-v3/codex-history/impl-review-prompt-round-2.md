# Round 2: Round 1 の指摘への対応

Round 1 で挙がった Critical 2 件と Warning 12 件すべてに対応した。
**指摘 #10 だけは主張を弱める形の対応**である (実測すると差が出なかったため)。

再レビューをお願いする。観点は Round 1 と同じ。とくに次を見てほしい:

1. Critical 1 (パッケージの所属と program の有無の分離) が本当に fail-closed になったか
2. Critical 2 (語の非空検査の位置) で他に「黙って通る」経路が残っていないか
3. 指摘 #5 の「値集合は共有抽出器だけが作る」形が、前向きの診断の質を落としていないか
4. 指摘 #10 の弱めた主張が、文書と実装で食い違っていないか (誇張していないか)
5. 新設したテスト群が「その分岐」を本当に押さえているか

**差分ではなく、変更したファイルの現在の全文**を貼る (Round 1 で全文を読んでもらっているため)。

---

## 対応マトリクス

# 実装レビュー Round 1 の対応マトリクス (Claude 側)

| # | 区分 | 指摘 | 判断 | 対応内容 |
|---|---|---|---|---|
| 1 | Critical | tsconfig を持たないパッケージが fail-closed でない (`<root>` へ落ちる) | **対応する** | 所属の判定 (`listPackageDirectories()` = `packages/` 直下の全ディレクトリ) と「解析できる program があるか」(`hasPackageTsconfig()`) を**分離**した。所属は tsconfig の有無で絞らないので、自前の tsconfig を持たないパッケージのファイルは `ownerOf()` の時点で例外になる。純関数の負例 (見本の木) と実リポジトリの全数検査を追加 |
| 2 | Critical | 語へ分割できない宣言名が、交差率によっては例外にならず黙って消える | **対応する** | `matchReverseRule()` の**語の非空検査を交差条件の早期 return より前**へ移した。交差が半分未満の入力で例外になることを負例で固定した |
| 3 | Warning | `projectReferences` を `ts.createProgram()` に渡していない | **対応する** | 渡すように戻した (旧実装が渡していた値であり、外す根拠が無い) |
| 4 | Warning | `MirrorProgram.virtualPaths` が判定に使われていない (共通規約 (d)) | **対応する** | `virtualPaths` を**削除**した。仮想パスの綴りは `VIRTUAL_SUFFIX` から決まる決定的な値で、正規化の一致は `buildProgram()` が組んだ直後に例外で固定している (対応表を持ち回る必要が無い) |
| 5 | Warning | 型別名の値抽出が二重実装のまま (共有抽出器の値集合を使っていない) | **対応する** | `resolveTsDeclaration()` は共有抽出器 `readResolvedStringLiteralUnion()` の `values` を**そのまま返す**形にし、前向き固有の診断だけを `diagnoseTypeAlias()` (値集合を作らない) で作るようにした。副作用として負例行列の T22 / T23 が「判定保留」の言葉になったので、行の**意味を更新**した (削除ではない) |
| 6 | Warning | `nameResolved` が収集されるだけで判定に使われていない | **対応する** | 判定側を `!candidate.nameResolved` に統一し、`nameResolved` が真なのに名前が無い形は**内部矛盾として例外**にした (両方が判定に効く形にした) |
| 7 | Warning | locator の境界試験が不足 (判定保留 → 候補 / 非候補 → 候補 / 申告が他方へ効かない) | **対応する** | 見本 `fixtures/candidates/staged-occurrence.ts` を足し、3 つとも固定した。判定保留の申告が 1 件増えたので pin を 5 → 6 に直した |
| 8 | Warning | 共有抽出器 5 関数の三値分岐を直接試験していない | **対応する** | `ts-literal-values.ts` の 5 関数 + `isIndeterminateType` を直接突く describe を新設した (`const-array` に判定保留の分岐が無いこと、計算キー / `case` / 型別名の `any` が判定保留になること、素の `any` / `unknown` が正常な非候補であること) |
| 9 | Warning | 「`.svelte` の 4 形」と言いながら `switch-cases` が無い | **対応する** | 見本 `fixtures/svelte/Sample.svelte` に分岐を足し、4 形すべてを assert した |
| 10 | Warning | NodeNext の回帰試験がモジュール解決を通っていない | **対応する (ただし主張を弱める)** | **実測すると差が出なかった** — `./schemas.js` はルートの設定 (bundler) でも解決でき、両方の設定で意味診断は 0 件だった。したがって「取り込みが解決できず候補が消える」を現物で示すことはできない。代わりに**どの設定で組まれた program に載っているか**を直接固定する試験へ差し替え (`ownerOf` が `packages/cli` を返し、その program の `moduleResolution` が NodeNext、ルートが Bundler であること)。併せて `docs/architecture.md` と故障注入の記録に「この差は現物では観測されていない。方式は偽陰性を作らない側の予防である」と明記した |
| 11 | Warning | 除外根の境界試験が故障注入 1' の受け皿になっていない | **対応する** | gate が持っていた判定を `findExcludedSurvivors()` へ切り出し、gate はそれを呼ぶだけにした。自己検査は一時ディレクトリの見本 (正常な `.ts` / 壊れた `.ts` / 正常な `.svelte` / 壊れた `.svelte` / 本番の入口を持たない拡張子) を渡して**生き残りの集合**を固定する。関数を壊すとこの試験が赤くなることを実測した |
| 12 | Warning | `docs/architecture.md` の「tsconfig を持たないパッケージは載らず直和検査が赤くなる」が実装より強い | **対応する** | #1 の修正で成立するようになったが、落ちる場所は直和検査ではなく**所有者の解決**なので、その旨へ書き直した |
| 13 | Warning | D54 の「共有抽出器」「食い違いを検査する」が実装より強い | **対応する** | #5 の修正に合わせて D54 の文面を「共有抽出器が返した値集合をそのまま返し、前向き固有の診断への翻訳だけを自分で行う」へ直した |
| 14 | Warning | `composer test` が全 green ではない | **対応する** | 指摘のとおり。並列実行時の CPU 競合で `EmailPromotionTest` 2 件と `BughuntSelfTestExecutionTest` 2 件が落ちていた (直列では 46/46 green)。他のレーンを止めたクリーンな環境でフル実行し直して報告する |

## 追加した故障注入 (レビュー指摘の再現)

指摘 #1 / #2 / #5 は「直した後に元へ戻すと赤くなる」ことを実測した (C1 / C2 / C3)。
記録は `../fault-injection-log.md`。

## `tests/js/support/enum-ts-sync/program.ts` (全文)

```ts
/**
 * 型情報の入口 (TypeScript の program と型検査器を作る)。
 *
 * **program は 1 本ではなくパッケージごとに作る** (正典 v3 の i5)。
 * `packages/cli` をルートの設定 (bundler / ESNext) で読むと NodeNext 前提の取り込みが
 * 解決できず、型が `any` に落ちた宣言が「文字列リテラル型ではない = 非候補」として
 * **静かに消える**。i5 が言う「本番と同じ型世界」は、道具パッケージにとっては
 * **そのパッケージ自身の tsconfig** である。
 *
 * | program | 起点 |
 * |---|---|
 * | `<root>` | ルート `tsconfig.json` の全ファイル ∪ どのパッケージにも属さない版管理下の `*.ts` ∪ 仮想 `.svelte` |
 * | `packages/<name>` | そのパッケージの `tsconfig.json` の全ファイル ∪ 配下の版管理下の `*.ts` ∪ 配下の仮想 `.svelte` |
 *
 * **所有者の判定は `.ts` と `.svelte` で同じ規則を使う** (現時点で `packages/` の下に
 * `.svelte` は無いが、足されたときにルートの設定で読まれてしまうのを防ぐ)。
 * tsconfig を持たないパッケージのファイルはどの program にも載らず、母集団の直和検査が
 * 赤くなる (fail-closed。そのとき扱いを判断させる)。
 *
 * 出力はしないので、起点を `rootDir` の外へ足せるよう `rootDir` / `outDir` /
 * `declaration` / `declarationMap` / `composite` / `sourceMap` は落として組む。
 *
 * **`createMirrorProgram(tsFiles)` は廃止した** (2 つの program の作り方を残さない)。
 */
import ts from "typescript";
import fs from "node:fs";
import path from "node:path";
import { EnumTsSyncError } from "./errors";
import { REPO_ROOT } from "./repo-root";
import {
    listCandidateSvelteFiles,
    listCandidateTsFiles,
    listProgramTsFiles,
    validateExcludedRoots,
} from "./population";
import {
    assertNoModuleToInstanceReference,
    assertNoVirtualPathCollision,
    realPathOfVirtual,
    toVirtualUnit,
    type SvelteVirtualUnit,
} from "./svelte-source";

export { REPO_ROOT } from "./repo-root";

/** ルートの program の所有者名 (パッケージのディレクトリ名と衝突しない綴り)。 */
export const ROOT_OWNER = "<root>";

export interface MirrorProgram {
    readonly owner: string;
    readonly program: ts.Program;
    readonly checker: ts.TypeChecker;
    /** この program の起点 (`rootNames`) をリポジトリ相対で表したもの。 */
    readonly rootRelatives: ReadonlySet<string>;
}

/** 見本専用の**起点を縮めた** program。**本番の gate では使わない**。 */
export interface FixtureProgram extends MirrorProgram {
    readonly fixture: true;
}

export interface MirrorPrograms {
    /** 所有者 (`<root>` またはパッケージのディレクトリ) → program。 */
    readonly byOwner: ReadonlyMap<string, MirrorProgram>;
    /** 候補走査の母集団 (リポジトリ相対)。 */
    readonly population: {
        readonly ts: readonly string[];
        readonly svelte: readonly string[];
    };
    /** 母集団の相対パス → 所有者。 */
    ownerOf(relativePath: string): string;
    /** 母集団の相対パス → それを載せている program。 */
    programOf(relativePath: string): MirrorProgram;
    /** 相対パス → その program 上の SourceFile (`.svelte` は仮想単位)。 */
    sourceOf(relativePath: string): ts.SourceFile;
}

const formatHost: ts.FormatDiagnosticsHost = {
    getCanonicalFileName: (fileName) => fileName,
    getCurrentDirectory: () => REPO_ROOT,
    getNewLine: () => "\n",
};

/** tsconfig.json を読む。回復可能な診断も含めて 1 件でもあれば例外にする。 */
const parseTsconfig = (configPath: string): ts.ParsedCommandLine => {
    const where = path.relative(REPO_ROOT, configPath).split(path.sep).join("/");
    const host: ts.ParseConfigFileHost = {
        useCaseSensitiveFileNames: ts.sys.useCaseSensitiveFileNames,
        readDirectory: ts.sys.readDirectory,
        fileExists: ts.sys.fileExists,
        readFile: ts.sys.readFile,
        getCurrentDirectory: () => path.dirname(configPath),
        onUnRecoverableConfigFileDiagnostic: (d) => {
            throw new EnumTsSyncError(where, ts.flattenDiagnosticMessageText(d.messageText, " "));
        },
    };
    const parsed = ts.getParsedCommandLineOfConfigFile(configPath, {}, host);
    if (parsed === undefined) throw new EnumTsSyncError(where, "読み込みに失敗しました");
    if (parsed.errors.length > 0) {
        throw new EnumTsSyncError(where, ts.formatDiagnostics(parsed.errors, formatHost));
    }
    if (parsed.fileNames.length === 0) {
        throw new EnumTsSyncError(where, "対象ファイルが 0 件です (gate が空振りしている)");
    }
    return parsed;
};

const relativeOf = (fileName: string): string =>
    realPathOfVirtual(fileName) ?? path.relative(REPO_ROOT, fileName).split(path.sep).join("/");

/**
 * program を 1 本組み、仮想単位に対して**検査 B を必ず走らせる**。
 * **この関数は輸出しない** — 検査を飛ばした program を外から作る経路を型で消すためである。
 */
const buildProgram = (
    owner: string,
    parsed: ts.ParsedCommandLine,
    rootNames: readonly string[],
    virtualUnits: readonly SvelteVirtualUnit[],
): MirrorProgram => {
    const options: ts.CompilerOptions = {
        ...parsed.options,
        noEmit: true,
        rootDir: undefined,
        outDir: undefined,
        declaration: false,
        declarationMap: false,
        composite: false,
        sourceMap: false,
    };
    const base = ts.createCompilerHost(options, true);
    const virtualText = new Map(virtualUnits.map((unit) => [unit.virtualPath, unit.text]));
    const host: ts.CompilerHost = {
        ...base,
        fileExists: (fileName) => virtualText.has(fileName) || base.fileExists(fileName),
        readFile: (fileName) => virtualText.get(fileName) ?? base.readFile(fileName),
        getSourceFile: (fileName, languageVersion, onError, shouldCreate) => {
            const text = virtualText.get(fileName);
            return text !== undefined
                ? ts.createSourceFile(fileName, text, languageVersion, true, ts.ScriptKind.TS)
                : base.getSourceFile(fileName, languageVersion, onError, shouldCreate);
        },
    };

    const roots = [...new Set([...rootNames, ...virtualText.keys()])];
    // `projectReferences` を落とすと「本番と同じ型世界」でなくなる (参照先が欠ける)。
    const program = ts.createProgram({
        rootNames: roots,
        options,
        host,
        projectReferences: parsed.projectReferences,
    });
    const optionsDiagnostics = program.getOptionsDiagnostics();
    if (optionsDiagnostics.length > 0) {
        throw new EnumTsSyncError(owner, ts.formatDiagnostics(optionsDiagnostics, formatHost));
    }
    const checker = program.getTypeChecker();
    const canonical = host.getCanonicalFileName.bind(host);

    // 検査 B は program を組んだ直後に必ず走らせる (呼び出し義務を利用側へ渡さない)。
    for (const unit of virtualUnits) {
        const source = program.getSourceFile(unit.virtualPath);
        if (source === undefined) {
            throw new EnumTsSyncError(unit.source, "仮想単位が program に載っていません");
        }
        if (canonical(source.fileName) !== canonical(unit.virtualPath)) {
            throw new EnumTsSyncError(unit.source, "仮想単位の綴りが正規化の規則と食い違っています");
        }
        assertNoModuleToInstanceReference(checker, source, unit);
    }

    return {
        owner,
        program,
        checker,
        rootRelatives: new Set(roots.map(relativeOf)),
    };
};

/**
 * `packages/` 直下のディレクトリ全数 (リポジトリ相対・綴り順)。
 * **tsconfig の有無で絞らない** — 所属の判定と「解析できる program があるか」の判定を
 * 分けるためである。絞ると tsconfig を持たないパッケージのファイルが黙って `<root>` へ
 * 落ち、そのパッケージ用の設定ではない型世界で解析されてしまう (偽陰性)。
 */
export const listPackageDirectories = (root: string = REPO_ROOT): readonly string[] => {
    const packagesDir = path.join(root, "packages");
    if (!fs.existsSync(packagesDir) || !fs.lstatSync(packagesDir).isDirectory()) return [];
    return fs
        .readdirSync(packagesDir, { withFileTypes: true })
        .filter((entry) => entry.isDirectory())
        .map((entry) => `packages/${entry.name}`)
        .sort();
};

/** そのパッケージが自前の tsconfig を持つか (program を作れるか)。 */
export const hasPackageTsconfig = (packageDir: string, root: string = REPO_ROOT): boolean =>
    fs.existsSync(path.join(root, packageDir, "tsconfig.json"));

/**
 * 逆走査と前向きの検査が共通で使う program 群を作る。
 * 目録のファイルも母集団の一部なので所有者の program へ載る。
 */
export const createMirrorPrograms = (): MirrorPrograms => {
    validateExcludedRoots();

    const programTs = listProgramTsFiles();
    const candidateTs = listCandidateTsFiles();
    const candidateSvelte = listCandidateSvelteFiles();
    const packageDirs = listPackageDirectories();

    // **所属は `packages/<名前>/` の配下かどうかだけで決める** (tsconfig の有無で決めない)。
    const ownerOfRelative = (relative: string): string =>
        packageDirs.find((dir) => relative.startsWith(`${dir}/`)) ?? ROOT_OWNER;

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
        if (!hasPackageTsconfig(dir)) continue;
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

    const ownerOf = (relative: string): string => {
        const owner = ownerOfRelative(relative);
        if (!byOwner.has(owner)) {
            throw new EnumTsSyncError(
                relative,
                `所有者 ${owner} の program がありません (自前の tsconfig.json を持たないパッケージです。ルートの設定で読むと型が縮んで候補が静かに消えるので、扱いを決めてから走らせること)`,
            );
        }
        return owner;
    };

    const programOf = (relative: string): MirrorProgram => {
        const program = byOwner.get(ownerOf(relative));
        if (program === undefined) throw new EnumTsSyncError(relative, "所有者の program を解決できません");
        return program;
    };

    const sourceOf = (relative: string): ts.SourceFile => {
        const mirror = programOf(relative);
        let fileName = absolute(relative);
        if (relative.endsWith(".svelte")) {
            const unit = virtualByReal.get(relative);
            if (unit === undefined) throw new EnumTsSyncError(relative, ".svelte が仮想化されていません");
            fileName = unit.virtualPath;
        }
        const source = mirror.program.getSourceFile(fileName);
        if (source === undefined) throw new EnumTsSyncError(relative, `所有者 ${mirror.owner} の program に載っていません`);
        return source;
    };

    return {
        byOwner,
        population: { ts: candidateTs, svelte: candidateSvelte },
        ownerOf,
        programOf,
        sourceOf,
    };
};

/**
 * 見本 (fixture) 専用の**起点を縮めた** program。**本番の gate では使わない**。
 * リポジトリの `compilerOptions` (`paths` を含む) はそのまま使い、起点だけを明示する。
 * 仮想単位を渡した場合も**検査 B は必ず走る** (本番と同じ一本道)。
 *
 * @param absoluteFiles 絶対パス
 * @param virtualUnits  仮想 `.svelte` 単位 (省略可)
 */
export const createFixtureProgram = (
    absoluteFiles: readonly string[],
    virtualUnits: readonly SvelteVirtualUnit[] = [],
): FixtureProgram => {
    for (const file of absoluteFiles) {
        if (!fs.existsSync(file)) throw new EnumTsSyncError(file, "見本ファイルが実在しません");
    }
    // 起点は明示したものだけにする (tsconfig の全ファイルは載せない = 縮めた program)。
    const parsed = parseTsconfig(path.join(REPO_ROOT, "tsconfig.json"));
    return { ...buildProgram("<fixture>", parsed, absoluteFiles, virtualUnits), fixture: true };
};

/**
 * 除外根の**自己点検**: 与えられたファイルのうち「本番と同じ入口で落ちないもの」を返す。
 *
 * 除外根に置いてよいのは**わざと壊した見本**だけである。正常なファイルを置くと
 * 母集団から静かに消えるので、それを検出する受け皿がこの関数である。
 * **拡張子ごとに本番と同じ入口を使う** — `.ts` は TypeScript の構文診断、
 * `.svelte` は `toVirtualUnit()` の失敗で見る。どちらでもない拡張子は
 * 「本番の入口を持たない」ので落ちない側 (= 生き残り) として返す。
 *
 * @param files 除外根の配下のファイル (`root` からの相対パス)
 * @param root  走査根 (負のコントロールが一時ディレクトリを渡すためだけに引数化してある)
 */
export const findExcludedSurvivors = (
    files: readonly string[],
    root: string = REPO_ROOT,
): readonly string[] => {
    const survivors: string[] = [];
    for (const file of files) {
        const absolute = path.join(root, file);
        if (file.endsWith(".svelte")) {
            try {
                toVirtualUnit(file, fs.readFileSync(absolute, "utf-8"));
            } catch {
                continue;
            }
            survivors.push(file);
            continue;
        }
        if (!file.endsWith(".ts")) {
            survivors.push(file);
            continue;
        }
        const fixture = createFixtureProgram([absolute]);
        const source = fixture.program.getSourceFile(absolute);
        if (source === undefined || fixture.program.getSyntacticDiagnostics(source).length === 0) {
            survivors.push(file);
        }
    }
    return survivors;
};
```

## `tests/js/support/enum-ts-sync/reverse-sweep.ts` (全文)

```ts
/**
 * 逆走査の突き合わせ (正典 v3 の i10)。
 *
 * `enum-ts-sync.test.ts` は「目録に登録した関係について PHP → TS を見る」向きの検査なので、
 * **登録し忘れた写し**は素通りする。本モジュールは向きを変え、TS 側の候補
 * (`collectTsCandidates`) と PHP の文字列付き列挙の母集団 (`buildPhpEnumCatalog`) を
 * 突き合わせ、次の規則で「未登録だが対応していそうな組」を検出する。
 *
 * - **規則 1 (完全一致)**: 値集合が PHP 列挙と完全に一致する未登録の宣言
 * - **規則 2a (厳密名対応 + 1 値以上の交差)**: 名前が小文字化して一致 / `+s` / `+es` /
 *   `+values` で対応し、値集合が交差するが完全一致ではない宣言
 * - **規則 2b (語分割名対応 + 両側から見て半分以上の交差)**: 名前を語に割って対応を見る
 *
 * **規則 2 は 2a と 2b の論理和**であり、**どちらの式も他方を包含しない**
 * (家系の未決論点 q2 に対する本リポジトリの一次観測。実測は
 * `devnotes/20260824-1633-enum-ts-sync-gate-v3/probe/measurements.md`)。
 *
 * **判定の順序 (排他)**:
 * 1. 値集合が完全一致 → 規則 1
 * 2. 交差が 0 なら何もしない
 * 3. 名前を決められない (`nameResolved` が偽) → **判定不能** (gate を赤くする)
 * 4. 2a の名前対応が成立 → 規則 2a
 * 5. 2b の名前対応と交差条件を満たす → 規則 2b
 * 6. どれでもなければ鳴らさない
 *
 * **語の区切りの宣言 (AGENTS.md §共通規約 (e))**: 語に割る文字は `_` `-` `.` `:` `$` と
 * 空白類。加えて**大文字の境界**(小文字または数字 → 大文字 / 大文字の連なり → 大文字 + 小文字)
 * と**数字の境界**(英字 ↔ 数字) でも割る。割った後は空の要素を捨て、すべて小文字化する。
 *
 * **正規化は「1 つの正規形へ畳む」形を採らない**。接尾辞だけで畳むと
 * `cases → cas` / `uses → us` のように誤った語幹を正規形にしてしまう。代わりに
 * 語ごとに候補形の集合 (`wordForms`) を作り、**集合が交われば同じ語**とみなす。
 * これは過剰検出の向きへ倒した判定であり、鳴った先は申告で逃がせる。
 * **これ以上の語形変化 (不規則変化・語幹の交替) は扱わない**。
 *
 * **これは「登録漏れが無いことの証明」ではなく「候補の検出」である**。
 * 名前も対応せず値も半分未満しか交差しない drift 済みの写しは検出できない (意図した限界)。
 */
import { EnumTsSyncError } from "./errors";
import type { ResolvedPhpEnum } from "./php-enum-catalog";
import type { TsCandidateLocator, TsUnionCandidate } from "./ts-candidates";
import { locatorKey } from "./ts-candidates";

/** 適用した規則。申告の同一性に含める (規則が変わったら申告は stale になる)。 */
export type ReverseSweepRule = "1" | "2a" | "2b";

export interface UnregisteredMirrorCandidate {
    readonly rule: ReverseSweepRule;
    readonly php: ResolvedPhpEnum;
    readonly candidate: TsUnionCandidate;
    /** 鳴った理由 (どの規則・どの語・どの値の交差で鳴ったか)。 */
    readonly reason: string;
    readonly onlyInPhp: readonly string[];
    readonly onlyInTs: readonly string[];
}

/** 名前を決められないので規則 2 を判定できなかった組 (gate を赤くする)。 */
export interface UndecidableMirrorPair {
    readonly php: ResolvedPhpEnum;
    readonly candidate: TsUnionCandidate;
    readonly intersectionSize: number;
}

export interface ReverseSweepResult {
    readonly found: readonly UnregisteredMirrorCandidate[];
    readonly undecidable: readonly UndecidableMirrorPair[];
}

export type ReverseRuleOutcome =
    | { readonly kind: "match"; readonly rule: ReverseSweepRule; readonly reason: string }
    | { readonly kind: "undecidable"; readonly intersectionSize: number }
    | { readonly kind: "none" };

/** ファイル名の語幹を取る (テストの見本構築用。判定本体は `ResolvedPhpEnum.name` を使う)。 */
export const shortEnumName = (path: string): string => {
    const base = path.split("/").pop() ?? path;
    return base.endsWith(".php") ? base.slice(0, -".php".length) : base;
};

/** 分岐のラベルの `switch:` は**両規則の共通の前処理**で外す。 */
const stripSwitchPrefix = (name: string): string => name.replace(/^switch:/, "");

/**
 * 厳密な名前対応 (一致 / `+s` / `+es` / `+values`)。
 * 小文字化して比較し、**英数字以外は除去しない**
 * (`_` や `$` まで消すと `Foo_Bar` と `FooBar` を同一視してしまう)。
 */
export const strictNameCorrespondence = (candidateName: string, enumName: string): string | null => {
    const candidate = candidateName.toLowerCase();
    const target = enumName.toLowerCase();
    if (candidate === target) return `厳密名対応 (${target} = ${candidate})`;
    for (const suffix of ["s", "es", "values"]) {
        if (candidate === `${target}${suffix}`) return `厳密名対応 (${target} + "${suffix}" = ${candidate})`;
    }
    return null;
};

/** 語の候補形の集合。**1 つの正規形へ畳まない** (誤った語幹を正規形にしないため)。 */
export const wordForms = (word: string): ReadonlySet<string> => {
    const forms = new Set<string>([word]);
    if (word.endsWith("ies") && word.length > 3) forms.add(`${word.slice(0, -3)}y`);
    if (word.length > 2 && /(?:s|x|z|ch|sh)es$/.test(word)) forms.add(word.slice(0, -2));
    if (word.endsWith("s") && !word.endsWith("ss") && word.length > 1) forms.add(word.slice(0, -1));
    return forms;
};

/** 2 つの語が対応するか (候補形の集合が交わるか)。**推移律は持たない**。 */
export const correspondWords = (a: string, b: string): boolean => {
    const formsOfA = wordForms(a);
    for (const form of wordForms(b)) if (formsOfA.has(form)) return true;
    return false;
};

/** 識別子を語に割る (区切りの宣言は本モジュールの docblock)。 */
export const splitWords = (identifier: string): readonly string[] =>
    stripSwitchPrefix(identifier)
        .replace(/([a-z0-9])([A-Z])/g, "$1 $2")
        .replace(/([A-Z]+)([A-Z][a-z])/g, "$1 $2")
        .replace(/([A-Za-z])([0-9])/g, "$1 $2")
        .replace(/([0-9])([A-Za-z])/g, "$1 $2")
        .split(/[^A-Za-z0-9]+/)
        .map((word) => word.toLowerCase())
        .filter((word) => word !== "");

/**
 * 列挙側の語と候補側の語袋の**最大マッチング** (候補側の 1 語を 2 回使わない)。
 * 「列挙の各語について語袋のどれかと対応するか」を単純に数えると候補側の 1 語が
 * 使い回されるが、`correspondWords` は推移律を持たないので同値類にも畳めない。
 */
export const maxWordMatching = (enumWords: readonly string[], bag: readonly string[]): number => {
    const matchOf = new Array<number>(bag.length).fill(-1);
    const tryAssign = (index: number, seen: boolean[]): boolean => {
        for (let j = 0; j < bag.length; j += 1) {
            if (seen[j] || !correspondWords(enumWords[index], bag[j])) continue;
            seen[j] = true;
            if (matchOf[j] === -1 || tryAssign(matchOf[j], seen)) {
                matchOf[j] = index;
                return true;
            }
        }
        return false;
    };
    let matched = 0;
    for (let i = 0; i < enumWords.length; i += 1) {
        if (tryAssign(i, new Array<boolean>(bag.length).fill(false))) matched += 1;
    }
    return matched;
};

/** ファイル名の語幹 (拡張子を除いた basename)。 */
const baseNameOf = (relative: string): string =>
    (relative.split("/").pop() ?? relative).replace(/\.(ts|svelte|php)$/, "");

export class ReverseSweepNameError extends Error {
    constructor(where: string) {
        super(`${where}: 宣言名から語を 1 つも取り出せません`);
        this.name = "ReverseSweepNameError";
    }
}

/**
 * 語に分けた名前対応 (2b)。
 * 候補側の語袋 = 宣言名の語 ∪ ファイル名の語。**主要語は宣言名の語列の末尾**
 * (ファイル名の語は主要語に使わない)。列挙の語と語袋の最大マッチングが
 * `min(2, 列挙の語数)` 以上であることを要求する。
 */
export const wordNameCorrespondence = (
    candidateName: string,
    candidateFile: string,
    enumName: string,
    where: string,
): string | null => {
    const declarationWords = splitWords(candidateName);
    if (declarationWords.length === 0) throw new ReverseSweepNameError(where);

    const bag = [...new Set([...declarationWords, ...splitWords(baseNameOf(candidateFile))])];
    const enumWords = splitWords(enumName);
    if (enumWords.length === 0) return null;

    const candidateHead = declarationWords[declarationWords.length - 1];
    const enumHead = enumWords[enumWords.length - 1];
    if (!correspondWords(candidateHead, enumHead)) return null;

    const shared = maxWordMatching(enumWords, bag);
    if (shared < Math.min(2, enumWords.length)) return null;
    return `語対応 ${shared}/${enumWords.length} 語 主要語=${enumHead}`;
};

const intersectionSizeOf = (a: ReadonlySet<string>, b: ReadonlySet<string>): number => {
    let size = 0;
    for (const value of a) if (b.has(value)) size += 1;
    return size;
};

/** 1 組の突き合わせ (自己検査の対象になる純関数)。 */
export const matchReverseRule = (php: ResolvedPhpEnum, candidate: TsUnionCandidate): ReverseRuleOutcome => {
    const size = intersectionSizeOf(php.values, candidate.values);
    if (size === php.values.size && size === candidate.values.size) {
        return { kind: "match", rule: "1", reason: "完全一致" };
    }
    if (size === 0) return { kind: "none" };
    if (!candidate.nameResolved) return { kind: "undecidable", intersectionSize: size };

    const where = `${candidate.locator.file}::${candidate.locator.name}`;
    const name = candidate.correspondenceName;
    if (name === null) {
        // `nameResolved` が真なのに名前が無いのは候補の作り方の内部矛盾である
        // (無言で名前不一致へ混ぜない)。
        throw new EnumTsSyncError(where, "nameResolved が真なのに名前対応に使う名前がありません");
    }

    const stripped = stripSwitchPrefix(name);
    const strict = strictNameCorrespondence(stripped, php.name);
    if (strict !== null) return { kind: "match", rule: "2a", reason: `${strict} / 交差 ${size} 値` };

    // **語の非空は交差条件より前に見る** — 後ろに置くと、交差が半分未満の組では
    // 「語を 1 つも取り出せない宣言名」が判定されないまま黙って通ってしまう
    // (Codex 実装レビュー Round 1 の Critical)。
    if (splitWords(stripped).length === 0) throw new ReverseSweepNameError(where);

    // 交差条件 (両側それぞれの要素数の半分以上。ceil 側で切り上げ)。
    if (!(size * 2 >= php.values.size && size * 2 >= candidate.values.size)) return { kind: "none" };

    const words = wordNameCorrespondence(stripped, candidate.locator.file, php.name, where);
    if (words === null) return { kind: "none" };
    return { kind: "match", rule: "2b", reason: `${words} / 交差 ${size} 値` };
};

const difference = (a: ReadonlySet<string>, b: ReadonlySet<string>): readonly string[] =>
    [...a].filter((value) => !b.has(value)).sort();

/**
 * 未登録の関係の候補を検出する。
 *
 * @param phpEnums     母集団のうち値集合が読めた PHP 列挙 (`resolved`)
 * @param candidates   TS 側の候補
 * @param isRegistered locator が既に目録へ登録済みかを判定する述語
 */
export const findUnregisteredMirrorCandidates = (
    phpEnums: readonly ResolvedPhpEnum[],
    candidates: readonly TsUnionCandidate[],
    isRegistered: (locator: TsCandidateLocator) => boolean,
): ReverseSweepResult => {
    const found: UnregisteredMirrorCandidate[] = [];
    const undecidable: UndecidableMirrorPair[] = [];

    for (const candidate of candidates) {
        if (isRegistered(candidate.locator)) continue;

        for (const php of phpEnums) {
            const outcome = matchReverseRule(php, candidate);
            if (outcome.kind === "none") continue;
            if (outcome.kind === "undecidable") {
                undecidable.push({ php, candidate, intersectionSize: outcome.intersectionSize });
                continue;
            }
            found.push({
                rule: outcome.rule,
                php,
                candidate,
                reason: outcome.reason,
                onlyInPhp: difference(php.values, candidate.values),
                onlyInTs: difference(candidate.values, php.values),
            });
        }
    }

    return { found, undecidable };
};

/** 申告の同一性 (`php` + 候補の locator + `rule`)。 */
export interface ReverseSweepExemptionKeyParts {
    readonly php: string;
    readonly locator: TsCandidateLocator;
    readonly rule: ReverseSweepRule;
}

export const reverseSweepKey = (parts: ReverseSweepExemptionKeyParts): string =>
    `${parts.php}|${locatorKey(parts.locator)}|${parts.rule}`;

export interface ReverseSweepAudit<E extends ReverseSweepExemptionKeyParts> {
    /** 申告で逃がせていない候補。 */
    readonly unexempted: readonly UnregisteredMirrorCandidate[];
    /** 実態と食い違った申告 (今はもう候補として鳴らない)。 */
    readonly stale: readonly E[];
}

/**
 * 申告の突き合わせ。**生死の判定は「免除を適用する前」の候補集合に対して行う**
 * (免除適用後の集合で判定すると、申告が自分自身を根拠にして永久に生き続ける)。
 */
export const auditReverseSweepExemptions = <E extends ReverseSweepExemptionKeyParts>(
    found: readonly UnregisteredMirrorCandidate[],
    exemptions: readonly E[],
): ReverseSweepAudit<E> => {
    const exemptKeys = new Set(exemptions.map(reverseSweepKey));
    const foundKeys = new Set(
        found.map((hit) => reverseSweepKey({ php: hit.php.path, locator: hit.candidate.locator, rule: hit.rule })),
    );

    return {
        unexempted: found.filter(
            (hit) =>
                !exemptKeys.has(
                    reverseSweepKey({ php: hit.php.path, locator: hit.candidate.locator, rule: hit.rule }),
                ),
        ),
        stale: exemptions.filter((entry) => !foundKeys.has(reverseSweepKey(entry))),
    };
};
```

## `tests/js/support/enum-ts-sync/ts-value-sets.ts` (全文)

```ts
/**
 * TS 側の値集合を**登録した 1 つの宣言について**読む (前向きの検査)。
 *
 * 受理する形は **2 つ**である:
 *   1. 対象ファイルの**最上位**にある**型別名の宣言** (解決した型が文字列リテラル型だけ)
 *   2. 対象ファイルの**最上位**にある **`const` 束縛の配列** (`as const` の有無を問わない)
 *
 * 同じ名前で受理できる宣言が**ちょうど 1 つ**あることを要求する (0 件・2 件以上は例外)。
 *
 * **値の読み取りは `ts-literal-values.ts` の共有抽出器を使う** (逆走査と同じ 1 本)。
 * とくに配列は**構文から読む** — `const X = ["a", "b"];` は型検査器の上では `string[]` に
 * 広げられるので、型から要素を復元してはいけない。`satisfies` を付けても対象型によって
 * 広げられ得るので、**受理の判断は常に配列リテラルの構文**から行う。
 *
 * **対応表のキーと分岐のラベルは登録できない**。写しとして扱うなら型別名か定数の配列へ
 * 切り出す (失敗メッセージにもそう書く)。
 *
 * **登録行の locator は AST から解決する** — 目録の行が持つのは `ts + declaration` だけで、
 * locator に要る `shape` と `occurrence` が無い。同名の入れ子の宣言が最上位より前にあると
 * 最上位でも `occurrence` は 0 とは限らないため、**逆走査と同じ採番器 (`buildScanIndex`)**
 * でその節の locator を求める (採番の実装を 2 本持たない)。
 *
 * **重複は検出しない**。`"a" | "a"` は型検査器が `"a"` へ正規化するため、値集合の側からは
 * 元の重複を観測できない。**意味の診断は見ない** — 型検査そのものは `pnpm typecheck` の担当。
 */
import ts from "typescript";
import path from "node:path";
import { EnumTsSyncError } from "./errors";
import { REPO_ROOT, type MirrorProgram, type MirrorPrograms } from "./program";
import { VIRTUAL_SUFFIX } from "./svelte-source";
import { buildScanIndex, type TsCandidateLocator } from "./ts-candidates";
import {
    readConstArrayLiteralValues,
    readResolvedStringLiteralUnion,
    unwrapInitializer,
} from "./ts-literal-values";

export interface ResolvedTsDeclaration {
    readonly locator: TsCandidateLocator;
    readonly values: ReadonlySet<string>;
}

/** 受理できる宣言の候補 (型別名 または最上位の配列束縛)。 */
type AcceptableDeclaration = ts.TypeAliasDeclaration | ts.VariableDeclaration;

const sourceFileOf = (mirror: MirrorProgram, tsFile: string, where: string): ts.SourceFile => {
    const absolute = path.join(REPO_ROOT, tsFile);
    if (tsFile.endsWith(".svelte")) {
        const virtual = absolute + VIRTUAL_SUFFIX;
        const source = mirror.program.getSourceFile(virtual);
        if (source === undefined) {
            throw new EnumTsSyncError(where, ".svelte の仮想単位が program にありません (仮想化されていません)");
        }
        return source;
    }
    const source = mirror.program.getSourceFile(absolute);
    if (source === undefined) throw new EnumTsSyncError(where, "TS ファイルが program に載っていません");
    return source;
};

const acceptableDeclarations = (source: ts.SourceFile, declaration: string): readonly AcceptableDeclaration[] => {
    const found: AcceptableDeclaration[] = [];
    for (const statement of source.statements) {
        if (ts.isTypeAliasDeclaration(statement) && statement.name.text === declaration) {
            found.push(statement);
            continue;
        }
        if (!ts.isVariableStatement(statement)) continue;
        for (const variable of statement.declarationList.declarations) {
            if (!ts.isIdentifier(variable.name) || variable.name.text !== declaration) continue;
            if (variable.initializer === undefined) continue;
            if (!ts.isArrayLiteralExpression(unwrapInitializer(variable.initializer).expression)) continue;
            found.push(variable);
        }
    }
    return found;
};

/**
 * 型別名が「正常な非候補」だったときに、**なぜ受理しないのか**を前向きの言葉にする。
 * **値集合は作らない** (値の読み取りは共有抽出器の 1 本だけが行う)。
 */
const diagnoseTypeAlias = (checker: ts.TypeChecker, alias: ts.TypeAliasDeclaration): string => {
    const symbol = checker.getSymbolAtLocation(alias.name);
    if (symbol === undefined) return "宣言の記号を解決できません";

    const declared = checker.getDeclaredTypeOfSymbol(symbol);
    const parts = declared.isUnion() ? declared.types : [declared];
    for (const part of parts) {
        if ((part.flags & ts.TypeFlags.EnumLiteral) !== 0) {
            return `TypeScript の enum の値は受理しません: ${checker.typeToString(part)}`;
        }
        if (!part.isStringLiteral()) {
            return `文字列リテラル型でない構成要素があります: ${checker.typeToString(part)}`;
        }
    }
    return "値を 1 つも取り出せません";
};

/**
 * 登録した 1 つの宣言の値集合と locator を解決する。
 * **値集合の比較より先に locator を解決する** — 値が食い違っていても登録済みの locator の
 * 母集団は変わらず、前向きの診断と逆走査が同じ解決結果を共有できる。
 */
export const resolveTsDeclaration = (
    mirror: MirrorProgram,
    tsFile: string,
    declaration: string,
): ResolvedTsDeclaration => {
    const where = `${tsFile}::${declaration}`;
    const source = sourceFileOf(mirror, tsFile, where);

    // 構文が壊れていると型解決が黙って縮むので、構文の診断だけは見る。
    if (mirror.program.getSyntacticDiagnostics(source).length > 0) {
        throw new EnumTsSyncError(where, "TS ファイルの構文が壊れています");
    }

    const found = acceptableDeclarations(source, declaration);
    if (found.length === 0) {
        throw new EnumTsSyncError(
            where,
            "受理できる宣言が見つかりません (受理するのは最上位の型別名の宣言か const の配列だけ。対応表のキーと分岐のラベルは登録できないので、写しなら型別名か定数の配列へ切り出すこと)",
        );
    }
    if (found.length > 1) {
        throw new EnumTsSyncError(where, `同名の受理できる宣言が ${found.length} 件あります`);
    }

    const node = found[0];
    const locator = buildScanIndex(source, mirror.checker, tsFile).locatorOf(node);

    if (ts.isTypeAliasDeclaration(node)) {
        // **値集合は共有抽出器だけが作る** (読み方を 2 本持たない)。
        // ここでやるのは前向き固有の診断への翻訳だけである。
        const result = readResolvedStringLiteralUnion(mirror.checker, node);
        if (result.kind === "values") return { locator, values: result.values };
        if (result.kind === "indeterminate") throw new EnumTsSyncError(where, result.reason);
        throw new EnumTsSyncError(where, diagnoseTypeAlias(mirror.checker, node));
    }

    const result = readConstArrayLiteralValues(node);
    if (result.kind !== "values") {
        throw new EnumTsSyncError(
            where,
            "const の配列として受理できません (const 束縛であり、要素が 1 件以上あり、すべて構文上の文字列リテラルであること)",
        );
    }
    return { locator, values: result.values };
};

/** 値集合だけを読む薄い入口 (負例行列が使う)。 */
export const readTsUnionValues = (
    mirror: MirrorProgram,
    tsFile: string,
    declaration: string,
): ReadonlySet<string> => resolveTsDeclaration(mirror, tsFile, declaration).values;

/** 目録の行を解決した結果 (前向きの判定と逆走査の登録済み判定が共有する)。 */
export interface ResolvedEnumTsRelation<E extends { readonly ts: string; readonly declaration: string }> {
    readonly entry: E;
    readonly tsLocator: TsCandidateLocator;
    readonly tsValues: ReadonlySet<string>;
}

/** 目録の全行を所有者の program 上で解決する。 */
export const resolveRelations = <E extends { readonly ts: string; readonly declaration: string }>(
    programs: MirrorPrograms,
    rows: readonly E[],
): readonly ResolvedEnumTsRelation<E>[] =>
    rows.map((entry) => {
        const resolved = resolveTsDeclaration(programs.programOf(entry.ts), entry.ts, entry.declaration);
        return { entry, tsLocator: resolved.locator, tsValues: resolved.values };
    });
```

## `tests/js/support/enum-ts-sync/fixtures/candidates/staged-occurrence.ts` (全文)

```ts
/**
 * 採番が**三値をまたぐ**ことの見本 (レビュー Round 4 の Critical / 実装レビュー Round 1)。
 *
 * `occurrence` は候補・判定保留・非候補の**同じ採番空間**で振る。したがって
 * 「判定保留が先・候補が後」「非候補が先・候補が後」のどちらでも、後の候補は
 * `occurrence: 1` になる (片方の申告がもう片方へ効かない)。
 *
 * 値は現物の列挙と交差しない綴りにすること (`fixtures/` は母集団に入る)。
 * **型検査は通らなくてよい** (`fixtures/**` は `pnpm typecheck` の対象外)。
 */
type IndirectAnyForStaged = any;

/** 判定保留 (別名越しの明示 `any`) が先。 */
export function stagedPendingFirst(): void {
    type StagedShadow = IndirectAnyForStaged;
    const value: StagedShadow = "zzz-staged-0";
    void value;
}

/** 候補が後 (occurrence: 1)。 */
export type StagedShadow = "zzz-staged-1";

/** 非候補 (開いた文字列) が先。 */
export function stagedNonCandidateFirst(): void {
    type MixedShadow = string;
    const value: MixedShadow = "zzz-mixed-0";
    void value;
}

/** 候補が後 (occurrence: 1)。 */
export type MixedShadow = "zzz-mixed-1";
```

## `tests/js/support/enum-ts-sync/fixtures/svelte/Sample.svelte` (全文)

```ts
<script lang="ts" module>
    /**
     * `.svelte` を仮想 TS へ平坦化する見本 (module 文脈と実体文脈の両方を持つ)。
     * 値は現物の PHP 列挙と交差しない綴りにすること (fixtures/ は母集団に入る)。
     */
    export type SampleModuleKind = "zzz-svelte-1" | "zzz-svelte-2";
</script>

<script lang="ts">
    // 実体から module の宣言を参照できること (Svelte 本来の可視性と同じ)。
    type SampleInstanceKind = SampleModuleKind;

    const SAMPLE_LABELS = { "zzz-svelte-1": "one", "zzz-svelte-2": "two" };
    const SAMPLE_LIST = ["zzz-svelte-1", "zzz-svelte-2"] as const;

    const current: SampleInstanceKind = "zzz-svelte-1";

    // 分岐のラベル (4 形目) も `.svelte` の中から拾えること。
    const describe = (kind: SampleInstanceKind): string => {
        switch (kind) {
            case "zzz-svelte-1":
                return "one";
            case "zzz-svelte-2":
                return "two";
            default:
                return "other";
        }
    };
</script>

<span>{SAMPLE_LABELS[current]}{SAMPLE_LIST.length}{describe(current)}</span>
```

## `tests/js/architecture/enum-ts-sync-discovery-extractor.test.ts` (全文)

```ts
/**
 * 発見の段・逆走査の抽出器・純関数の自己検査 (負例行列と故障注入の受け皿)。
 *
 * `enum-ts-sync-discovery.test.ts` の本体 gate は「未分類の PHP 列挙・未登録の候補・
 * 未登録の判定保留が 0 件であること」しか見ない。分類そのものが静かに間違える
 * (母集団に入れるべきものを落とす / 入れるべきでないものを混ぜる / 候補の突き合わせが
 * 緩すぎる・厳しすぎる) と、「0 件」という結果そのものが空虚になる。ここで抽出器・
 * 突き合わせの純関数の受理・拒否の境界を固定する。
 *
 * **本番の入口に差し替え口を作らない**。戦略は入口の側で固定し、自己検査は
 * **輸出した純関数へ入力のデータを渡して**判定を突く。
 *
 * **見本の置き方**:
 * - PHP はテスト内の文字列で書く (`classifyPhpFile` はファイルを要求しない)
 * - TS は `fixtures/candidates/` にファイルで置く (型検査器に実ファイルが要る)。
 *   `fixtures/` は**本番の母集団に入る**ので、見本の値は現物の列挙と交差しない綴りにする
 * - **不正な入力は追跡ファイルにしない** (母集団に入って本番の gate が恒久的に赤くなる)。
 *   構文の壊れた `.svelte`・受理しない属性・module から実体への参照・同名の最上位束縛は
 *   **テストの中の文字列**として `toVirtualUnit()` / `createFixtureProgram()` へ渡す
 *
 * 保証しないものの正本は `docs/architecture.md` §PHP 列挙と TypeScript 値域の同期。
 */
import { beforeAll, describe, expect, it } from "vitest";
import fs from "node:fs";
import os from "node:os";
import path from "node:path";
import ts from "typescript";
import { EnumTsSyncError } from "../support/enum-ts-sync/errors";
import { classifyPhpFile, listTrackedPhpFiles } from "../support/enum-ts-sync/php-enum-catalog";
import {
    createFixtureProgram,
    createMirrorPrograms,
    findExcludedSurvivors,
    hasPackageTsconfig,
    listPackageDirectories,
    REPO_ROOT,
    type MirrorPrograms,
} from "../support/enum-ts-sync/program";
import {
    listProgramTsFiles,
    parseTrackedOutput,
    validateExcludedRoots,
    type ExcludedRoot,
} from "../support/enum-ts-sync/population";
import {
    assertNoVirtualPathCollision,
    toVirtualUnit,
    VIRTUAL_SUFFIX,
} from "../support/enum-ts-sync/svelte-source";
import {
    isIndeterminateType,
    readConstArrayLiteralValues,
    readObjectLiteralKeys,
    readResolvedStringLiteralUnion,
    readSwitchCaseValues,
    unwrapInitializer,
    type LiteralValuesResult,
} from "../support/enum-ts-sync/ts-literal-values";
import {
    buildWitnessIndex,
    collectTsCandidates,
    isDerivedObjectKeys,
    locatorKey,
    switchSubjectName,
    type DerivedFacts,
    type TsCandidateScan,
    type TsCandidateShape,
    type TsUnionCandidate,
} from "../support/enum-ts-sync/ts-candidates";
import {
    auditReverseSweepExemptions,
    correspondWords,
    findUnregisteredMirrorCandidates,
    matchReverseRule,
    maxWordMatching,
    shortEnumName,
    strictNameCorrespondence,
    wordForms,
} from "../support/enum-ts-sync/reverse-sweep";
import type { ResolvedPhpEnum } from "../support/enum-ts-sync/php-enum-catalog";

const FIXTURE = "tests/js/support/enum-ts-sync/fixtures/candidates";

describe("classifyPhpFile() (発見の段の PHP 側分類)", () => {
    it("D1: 素直な string enum は resolved になる", () => {
        const source = "<?php\nenum D1: string\n{\n    case A = 'a';\n    case B = 'b';\n}\n";
        const result = classifyPhpFile(source, "D1.php");
        expect(result?.kind).toBe("resolved");
        expect(result?.kind === "resolved" && [...result.values].sort()).toEqual(["a", "b"]);
    });

    it("D1b: resolved は enum 宣言の頭の行を持つ (失敗メッセージが PHP 側の位置を出せる)", () => {
        const source = "<?php\n\ndeclare(strict_types=1);\n\nenum D1b: string\n{\n    case A = 'a';\n}\n";
        const result = classifyPhpFile(source, "D1b.php");
        expect(result?.kind === "resolved" && result.line).toBe(5);
    });

    it("D2: int backing の enum は母集団から外れる (undefined)", () => {
        const source = "<?php\nenum D2: int\n{\n    case A = 1;\n}\n";
        expect(classifyPhpFile(source, "D2.php")).toBeUndefined();
    });

    it("D3: backing の無い pure enum は母集団から外れる (undefined)", () => {
        const source = "<?php\nenum D3\n{\n    case A;\n}\n";
        expect(classifyPhpFile(source, "D3.php")).toBeUndefined();
    });

    it("D4: enum を宣言していないファイルは母集団から外れる (undefined)", () => {
        const source = "<?php\nclass D4\n{\n    public function example(): void {}\n}\n";
        expect(classifyPhpFile(source, "D4.php")).toBeUndefined();
    });

    it("D5: 深さ 0 に enum 宣言が 2 つあると unresolvable になる (機械的に選べない)", () => {
        const source = "<?php\nenum D5A: string\n{\n    case A = 'a';\n}\nenum D5B: string\n{\n    case A = 'a';\n}\n";
        const result = classifyPhpFile(source, "D5.php");
        expect(result?.kind).toBe("unresolvable");
        expect(result?.kind === "unresolvable" && result.reason).toContain("件あります");
    });

    it("D6: case が 0 件の string enum は unresolvable になる", () => {
        const source = "<?php\nenum D6: string\n{\n}\n";
        const result = classifyPhpFile(source, "D6.php");
        expect(result?.kind).toBe("unresolvable");
        expect(result?.kind === "unresolvable" && result.reason).toContain("1 件も取り出せません");
    });

    it("D7: case の値に逆斜線を含む string enum は unresolvable になる", () => {
        const source = "<?php\nenum D7: string\n{\n    case A = 'Foo\\\\Bar';\n}\n";
        const result = classifyPhpFile(source, "D7.php");
        expect(result?.kind).toBe("unresolvable");
    });

    it("D8: ファイル名の語幹と enum 名が食い違うと unresolvable になる", () => {
        const source = "<?php\nenum Other: string\n{\n    case A = 'a';\n}\n";
        const result = classifyPhpFile(source, "D8.php");
        expect(result?.kind).toBe("unresolvable");
        expect(result?.kind === "unresolvable" && result.reason).toContain("ファイル名の語幹");
    });

    it("D9: scan() が拒否する字句 (ヒアドキュメント) を含み、生のソースに enum の語が 1 つも無いと母集団から外れる", () => {
        const source =
            "<?php\nclass D9\n{\n    /** ここには対象の語が無い */\n    public function example(): string\n    {\n        return <<<EOT\nplain text\nEOT;\n    }\n}\n";
        expect(classifyPhpFile(source, "D9.php")).toBeUndefined();
    });

    it("D10: scan() が拒否する字句を含み、生のソースに enum の語があれば安全側に倒して unresolvable になる", () => {
        const source =
            "<?php\nenum D10: string\n{\n    case A = 'a';\n}\nclass D10Helper\n{\n    public function example(): string\n    {\n        return <<<EOT\nplain text\nEOT;\n    }\n}\n";
        const result = classifyPhpFile(source, "D10.php");
        expect(result?.kind).toBe("unresolvable");
    });

    it("D11: scan() が拒否する字句と併存する enum の語は、直後の並び (日本語の助詞等) を問わず unresolvable になる (fail-closed。過剰検出は可)", () => {
        const source =
            "<?php\nclass D11\n{\n    /** ToolName の enum が配線する */\n    public function example(): string\n    {\n        return <<<EOT\nplain text\nEOT;\n    }\n}\n";
        const result = classifyPhpFile(source, "D11.php");
        expect(result?.kind).toBe("unresolvable");
    });

    it("D12: scan() が拒否する字句と、コメントを挟む enum 宣言 (`enum /* c */ Name`) が併存すると unresolvable になる", () => {
        const source =
            "<?php\nclass D12\n{\n    public function example(): string\n    {\n        return <<<EOT\nplain text\nEOT;\n    }\n}\n// enum /* c */ Ghost\n";
        const result = classifyPhpFile(source, "D12.php");
        expect(result?.kind).toBe("unresolvable");
    });

    it("D13: 名前付き波括弧 namespace 宣言の中の enum は unresolvable になる (深さ 0 の前提が崩れるため判別できない)", () => {
        const source =
            "<?php\nnamespace App\\Example {\n    enum State: string\n    {\n        case Ready = 'ready';\n    }\n}\n";
        const result = classifyPhpFile(source, "D13.php");
        expect(result?.kind).toBe("unresolvable");
    });

    it("D14: 波括弧付き namespace 宣言があっても enum の語が無ければ母集団から外れる (過剰検出を無闇に広げない)", () => {
        const source = "<?php\nnamespace App\\Example {\n    class Plain\n    {\n    }\n}\n";
        expect(classifyPhpFile(source, "D14.php")).toBeUndefined();
    });

    it("D15: 無名の (グローバルな) 波括弧 namespace 宣言の中の enum も unresolvable になる (正規表現の当て木ではなく深さで判定するため、名前の有無を問わない)", () => {
        const source = "<?php\nnamespace {\n    enum State: string\n    {\n        case Ready = 'ready';\n    }\n}\n";
        const result = classifyPhpFile(source, "D15.php");
        expect(result?.kind).toBe("unresolvable");
    });

    it("D16: 大文字の NAMESPACE / コメントを挟む namespace 宣言の中の enum も unresolvable になる (キーワードの綴りや空白の書き方を問わない)", () => {
        const source =
            "<?php\nNAMESPACE /* c */ App\\Example {\n    enum State: string\n    {\n        case Ready = 'ready';\n    }\n}\n";
        const result = classifyPhpFile(source, "D16.php");
        expect(result?.kind).toBe("unresolvable");
    });

    it("D17: 深さ 0 の string enum と深さ 0 以外の string enum が同じファイルに共存すると unresolvable になる (深さ 0 だけを拾って残りを黙って捨てない)", () => {
        const source =
            "<?php\nenum D17: string\n{\n    case A = 'a';\n}\n\nif (true) {\n    enum Nested: string\n    {\n        case B = 'b';\n    }\n}\n";
        const result = classifyPhpFile(source, "D17.php");
        expect(result?.kind).toBe("unresolvable");
    });

    it("D18: 深さ 0 の int (対象外) 列挙と深さ 0 以外の string enum が共存しても unresolvable になる (深さ 0 の backing だけを見て undefined にしない)", () => {
        const source =
            "<?php\nenum D18: int\n{\n    case A = 1;\n}\n\nif (true) {\n    enum Nested: string\n    {\n        case B = 'b';\n    }\n}\n";
        const result = classifyPhpFile(source, "D18.php");
        expect(result?.kind).toBe("unresolvable");
    });
});

describe("listTrackedPhpFiles() (PHP 側母集団の走査根)", () => {
    it("実リポジトリの app/ 配下は空でない", () => {
        expect(listTrackedPhpFiles().length).toBeGreaterThan(0);
    });

    it("走査根 (app/) が実在しなければ fail-fast する", () => {
        expect(() => listTrackedPhpFiles(path.join(REPO_ROOT, "tests/js/support/enum-ts-sync"))).toThrow(
            "走査根が実在しません",
        );
    });
});

/** カテゴリ 3: 母集団の列挙が空振りしたら赤くする。 */
describe("population.ts (逆走査の母集団と唯一の除外)", () => {
    it("parseTrackedOutput は空出力を空の一覧にする (0 件の分岐を単体で突く)", () => {
        expect(parseTrackedOutput("")).toEqual([]);
        expect(parseTrackedOutput("a.ts\0b.ts\0")).toEqual(["a.ts", "b.ts"]);
    });

    it("列挙が 0 件になったら例外になる (空振りを緑にしない)", () => {
        // `app/Enums` を根にすると版管理下の `*.ts` は 1 件も無い。
        expect(() => listProgramTsFiles(path.join(REPO_ROOT, "app", "Enums"))).toThrow(
            "母集団の走査が空振りしています",
        );
    });

    it("除外根の一覧が空だと例外になる", () => {
        expect(() => validateExcludedRoots([])).toThrow("除外根の一覧が空です");
    });

    it("除外根の体裁の負例 (配下でない / 実在しない / 重複 / 理由 29 文字)", () => {
        const reason = "あ".repeat(30);
        const valid: ExcludedRoot = {
            root: "tests/js/support/enum-ts-sync/fixtures/candidates-broken",
            reason,
        };
        expect(() => validateExcludedRoots([valid])).not.toThrow();
        expect(() => validateExcludedRoots([{ root: "tests/js/architecture", reason }])).toThrow(
            "の配下だけです",
        );
        expect(() =>
            validateExcludedRoots([{ root: "tests/js/support/enum-ts-sync/no-such-dir", reason }]),
        ).toThrow("除外根が実在するディレクトリではありません");
        expect(() => validateExcludedRoots([valid, valid])).toThrow("2 回登録されています");
        expect(() => validateExcludedRoots([{ ...valid, reason: "あ".repeat(29) }])).toThrow(
            "理由は 30 文字以上",
        );
    });

    it("境界: 除外根の自己点検は、正常な .ts を生き残りとして返す (壊れた見本だけが落ちる)", () => {
        const sandbox = fs.realpathSync(fs.mkdtempSync(path.join(os.tmpdir(), "enum-ts-sync-excluded-")));
        try {
            fs.writeFileSync(path.join(sandbox, "healthy.ts"), 'export type Healthy = "zzz-healthy";\n');
            fs.writeFileSync(path.join(sandbox, "broken.ts"), 'export const oops = {\n');
            fs.writeFileSync(path.join(sandbox, "broken.svelte"), '<script lang="ts">\ntype A = "zzz-a";\n');
            fs.writeFileSync(path.join(sandbox, "healthy.svelte"), '<script lang="ts">\ntype A = "zzz-a";\n</script>\n');
            fs.writeFileSync(path.join(sandbox, "notes.md"), "# 本番の入口を持たない拡張子\n");

            // 正常なファイル (と本番の入口を持たない拡張子) だけが生き残る =
            // 除外根に置いたら gate が赤くなる。
            expect(
                [...findExcludedSurvivors(
                    ["healthy.ts", "broken.ts", "healthy.svelte", "broken.svelte", "notes.md"],
                    sandbox,
                )].sort(),
            ).toEqual(["healthy.svelte", "healthy.ts", "notes.md"]);
        } finally {
            fs.rmSync(sandbox, { recursive: true, force: true });
        }
    });

    it("パッケージの所属は tsconfig の有無で絞らない (絞ると <root> へ静かに落ちる)", () => {
        const sandbox = fs.realpathSync(fs.mkdtempSync(path.join(os.tmpdir(), "enum-ts-sync-packages-")));
        try {
            fs.mkdirSync(path.join(sandbox, "packages", "with-config"), { recursive: true });
            fs.mkdirSync(path.join(sandbox, "packages", "without-config"), { recursive: true });
            fs.writeFileSync(path.join(sandbox, "packages", "with-config", "tsconfig.json"), "{}\n");

            expect([...listPackageDirectories(sandbox)]).toEqual([
                "packages/with-config",
                "packages/without-config",
            ]);
            expect(hasPackageTsconfig("packages/with-config", sandbox)).toBe(true);
            expect(hasPackageTsconfig("packages/without-config", sandbox)).toBe(false);
        } finally {
            fs.rmSync(sandbox, { recursive: true, force: true });
        }
    });

    it("実リポジトリのパッケージはすべて自前の tsconfig を持つ (持たなければ program の解決で落ちる)", () => {
        const withoutConfig = listPackageDirectories().filter((dir) => !hasPackageTsconfig(dir));
        expect(
            withoutConfig,
            `自前の tsconfig.json を持たないパッケージがあります (扱いを決めること):\n${withoutConfig.join("\n")}`,
        ).toEqual([]);
    });
});

/** カテゴリ 4 / 4': `.svelte` の仮想化と平坦化で再現できない形の不合格。 */
describe("toVirtualUnit() (.svelte の仮想 TS 化)", () => {
    const svelteFile = "tests/js/support/enum-ts-sync/fixtures/svelte/__negative__.svelte";

    const unitOf = (source: string) => toVirtualUnit(svelteFile, source);

    it("script の中身以外を空白で潰し、行と列を元ファイルと一致させる", () => {
        const source = '<div>x</div>\n<script lang="ts">\ntype A = "zzz-a";\n</script>\n';
        const unit = unitOf(source);
        expect(unit.text.startsWith("            \n")).toBe(true);
        expect(unit.text.length).toBe(source.length + "\nexport {};\n".length);
        // 元ファイル上の位置がそのまま使える。
        expect(unit.text.indexOf('type A = "zzz-a";')).toBe(source.indexOf('type A = "zzz-a";'));
    });

    it.each([
        ["LF", 'a\n<script lang="ts">\ntype A = "zzz-a";\n</script>\n'],
        ["CRLF", 'a\r\n<script lang="ts">\r\ntype A = "zzz-a";\r\n</script>\r\n'],
        ["孤立 CR", 'a\r<script lang="ts">\rtype A = "zzz-a";\r</script>\r'],
        ["非 BMP 文字", '<p>\u{1F600}</p>\n<script lang="ts">\ntype A = "zzz-a";\n</script>\n'],
        ["U+2028", '<p>a b</p>\n<script lang="ts">\ntype A = "zzz-a";\n</script>\n'],
    ])("行と列が保たれる (%s)", (_label, source) => {
        const unit = unitOf(source);
        const original = ts.createSourceFile("o.ts", source, ts.ScriptTarget.Latest, true, ts.ScriptKind.TS);
        const virtual = ts.createSourceFile("v.ts", unit.text, ts.ScriptTarget.Latest, true, ts.ScriptKind.TS);
        const offset = source.indexOf('type A = "zzz-a";');
        expect(virtual.getLineAndCharacterOfPosition(offset)).toEqual(
            original.getLineAndCharacterOfPosition(offset),
        );
    });

    it("末尾が改行で終わらない / 行注釈で終わっても export {}; が独立した文になる", () => {
        for (const tail of ['<script lang="ts">type A = "zzz-a";</script>', '<script lang="ts">\n// 注釈</script>']) {
            const unit = unitOf(tail);
            const virtual = ts.createSourceFile("v.ts", unit.text, ts.ScriptTarget.Latest, true, ts.ScriptKind.TS);
            expect(virtual.statements.some((s) => ts.isExportDeclaration(s))).toBe(true);
        }
    });

    it.each([
        // 属性なし / `lang="js"` の script は svelte の parse が JS として読むので、
        // 見本の中身も JS にする (走査器はその中身を TS として読む = 過剰検出の向き)。
        ["属性なし (実体)", "<script>\nconst a = 1;\n</script>\n"],
        ["lang=ts (実体)", '<script lang="ts">\ntype A = "zzz-a";\n</script>\n'],
        ["lang=js (実体)", '<script lang="js">\nconst a = 1;\n</script>\n'],
        ["module + lang=ts", '<script lang="ts" module>\ntype A = "zzz-a";\n</script>\n'],
        ["module (値なし)", "<script module>\nconst a = 1;\n</script>\n"],
        ["module + lang=js", '<script lang="js" module>\nconst a = 1;\n</script>\n'],
    ])("受理する script の形 (%s)", (_label, source) => {
        expect(() => unitOf(source)).not.toThrow();
    });

    it.each([
        ["lang が受理表の外", '<script lang="scss">\n$a: 1;\n</script>\n', "受理しない script の lang"],
        // 値つきの `module` は svelte の parse 自身が先に拒む。**どちらの層で落ちても不合格**
        // であることが要点で、走査器側の検査は parse の仕様が緩んだときの受け皿として残す。
        ["値つきの module 属性", '<script module="x">\nconst a = 1;\n</script>\n', ".svelte の構文を読めません"],
        ["src 属性", '<script src="./a.js"></script>\n', "受理しない script 属性"],
        ["generics 属性", '<script lang="ts" generics="T">\nconst a = 1;\n</script>\n', "受理しない script 属性"],
    ])("不合格にする script の形 (%s)", (_label, source, reason) => {
        expect(() => unitOf(source)).toThrow(reason);
    });

    it("構文の壊れた .svelte は無言で読み飛ばさず例外になる", () => {
        expect(() => unitOf('<script lang="ts">\ntype A = "zzz-a";\n')).toThrow(EnumTsSyncError);
    });

    it.each([
        ["変数", '<script lang="ts" module>\nlet shared = 1;\n</script>\n<script lang="ts">\nlet shared = 2;\n</script>\n'],
        ["分割代入", '<script lang="ts" module>\nconst { shared } = { shared: 1 };\n</script>\n<script lang="ts">\nconst shared = 2;\n</script>\n'],
        ["関数", '<script lang="ts" module>\nfunction shared(): void {}\n</script>\n<script lang="ts">\nconst shared = 2;\n</script>\n'],
        ["型別名", '<script lang="ts" module>\ntype Shared = "zzz-a";\n</script>\n<script lang="ts">\ntype Shared = "zzz-b";\n</script>\n'],
        ["enum", '<script lang="ts" module>\nenum Shared { A }\n</script>\n<script lang="ts">\nconst Shared = 2;\n</script>\n'],
        ["namespace", '<script lang="ts" module>\nnamespace Shared { export const a = 1; }\n</script>\n<script lang="ts">\nconst Shared = 2;\n</script>\n'],
        ["取り込み", '<script lang="ts" module>\nimport type { Shared } from "./x";\n</script>\n<script lang="ts">\ntype Shared = "zzz-b";\n</script>\n'],
    ])("検査 A: module と実体に同名の最上位束縛があると不合格 (%s)", (_label, source) => {
        expect(() => unitOf(source)).toThrow("同名の最上位束縛");
    });
});

describe("assertNoVirtualPathCollision() (仮想パスの綴り)", () => {
    const unit = toVirtualUnit(
        "resources/js/components/atoms/Sample.svelte",
        '<script lang="ts">\ntype A = "zzz-a";\n</script>\n',
    );

    it("衝突しなければ通る", () => {
        expect(() => assertNoVirtualPathCollision([unit], ["resources/js/lib/x.ts"])).not.toThrow();
    });

    it("版管理下に同じ綴りのファイルがあれば例外になる", () => {
        expect(() =>
            assertNoVirtualPathCollision([unit], [`resources/js/components/atoms/Sample.svelte${VIRTUAL_SUFFIX}`]),
        ).toThrow("仮想パスの綴りが版管理下のファイルと衝突しています");
    });
});

describe("createFixtureProgram() / createMirrorPrograms() が検査 B を必ず走らせる", () => {
    const svelteFile = "tests/js/support/enum-ts-sync/fixtures/svelte/__negative__.svelte";

    it("境界: module から実体側の宣言を参照する .svelte は program の作成そのものが失敗する", () => {
        const unit = toVirtualUnit(
            svelteFile,
            '<script lang="ts" module>\ntype FromInstance = typeof instanceValue;\nexport type Exposed = FromInstance;\n</script>\n<script lang="ts">\nconst instanceValue = "zzz-b";\n</script>\n',
        );
        expect(() => createFixtureProgram([], [unit])).toThrow("実体側の宣言");
    });

    it("境界: 実体側の取り込みを module 側が参照する形も不合格 (別名の宣言位置で捕まえる)", () => {
        const unit = toVirtualUnit(
            svelteFile,
            '<script lang="ts" module>\nexport type Alias = ImportedDerivedKey;\n</script>\n<script lang="ts">\nimport type { ImportedDerivedKey } from "../candidates/derived-keys";\nconst value: ImportedDerivedKey = "zzz-i-1";\n</script>\n',
        );
        expect(() => createFixtureProgram([], [unit])).toThrow("実体側の宣言");
    });

    it("実体から module の宣言を参照するのは正しいので通る", () => {
        const unit = toVirtualUnit(
            svelteFile,
            '<script lang="ts" module>\nexport type ModuleKind = "zzz-m-1";\n</script>\n<script lang="ts">\ntype InstanceKind = ModuleKind;\nconst value: InstanceKind = "zzz-m-1";\n</script>\n',
        );
        expect(() => createFixtureProgram([], [unit])).not.toThrow();
    });

    it("仮想 TS はモジュール文脈なので、宣言が別の見本コンポーネントへ漏れない", () => {
        const declaring = toVirtualUnit(
            "tests/js/support/enum-ts-sync/fixtures/svelte/__A__.svelte",
            '<script lang="ts">\ntype Leaked = "zzz-leak-1";\nconst a: Leaked = "zzz-leak-1";\n</script>\n',
        );
        const referencing = toVirtualUnit(
            "tests/js/support/enum-ts-sync/fixtures/svelte/__B__.svelte",
            '<script lang="ts">\ntype Reference = Leaked;\n</script>\n',
        );
        const fixture = createFixtureProgram([], [declaring, referencing]);
        const source = fixture.program.getSourceFile(referencing.virtualPath);
        expect(source).toBeDefined();
        const alias = source?.statements.find(ts.isTypeAliasDeclaration);
        expect(alias).toBeDefined();
        if (alias === undefined) return;
        const symbol = fixture.checker.getSymbolAtLocation(alias.name);
        expect(symbol).toBeDefined();
        if (symbol === undefined) return;
        // 漏れていれば `"zzz-leak-1"` に解決してしまう。
        const declared = fixture.checker.getDeclaredTypeOfSymbol(symbol);
        expect(declared.isStringLiteral()).toBe(false);
    });
});

/** S3b: 共有抽出器 5 関数の三値の境界を**直接**突く (S4 経由の試験だけにしない)。 */
describe("ts-literal-values.ts (共有抽出器の三値)", () => {
    const svelteFile = "tests/js/support/enum-ts-sync/fixtures/svelte/__literal__.svelte";

    /** 見本のソースから checker 付きの SourceFile を作る (仮想単位の一本道を使う)。 */
    const analyze = (body: string): { readonly checker: ts.TypeChecker; readonly file: ts.SourceFile } => {
        const unit = toVirtualUnit(svelteFile, `<script lang="ts">\n${body}\n</script>\n`);
        const fixture = createFixtureProgram([], [unit]);
        const file = fixture.program.getSourceFile(unit.virtualPath);
        if (file === undefined) throw new EnumTsSyncError(svelteFile, "見本の仮想単位が program に載っていません");
        return { checker: fixture.checker, file };
    };

    const collect = <T extends ts.Node>(file: ts.SourceFile, guard: (node: ts.Node) => node is T): readonly T[] => {
        const out: T[] = [];
        const visit = (node: ts.Node): void => {
            if (guard(node)) out.push(node);
            ts.forEachChild(node, visit);
        };
        visit(file);
        return out;
    };

    const variables = (file: ts.SourceFile): readonly ts.VariableDeclaration[] =>
        collect(file, ts.isVariableDeclaration);

    const kinds = (results: readonly LiteralValuesResult[]): readonly string[] => results.map((r) => r.kind);

    it("readConstArrayLiteralValues: 値 / 非候補の 2 値だけを返す (判定保留の分岐を持たない)", () => {
        const { file } = analyze(
            [
                'const a = ["zzz-1", "zzz-2"];',
                'const b = ["zzz-3"] as const;',
                'const c = (["zzz-4"] satisfies readonly string[]);',
                'let d = ["zzz-5"];',
                "const e: readonly string[] = [];",
                'const f = ["zzz-6", d[0]];',
                // 型注釈が any でも**構文だけ**を見るので値は読める (型解決に依存しない)。
                'const g: any = ["zzz-7"];',
            ].join("\n"),
        );
        const results = variables(file).map(readConstArrayLiteralValues);
        expect(kinds(results)).toEqual([
            "values",
            "values",
            "values",
            "not-a-catalogue",
            "not-a-catalogue",
            "not-a-catalogue",
            "values",
        ]);
        expect(results.some((r) => r.kind === "indeterminate")).toBe(false);
        expect(results[0].kind === "values" && [...results[0].values]).toEqual(["zzz-1", "zzz-2"]);
    });

    it("readObjectLiteralKeys: 計算キーが any なら判定保留、enum の要素・展開なら非候補", () => {
        const { checker, file } = analyze(
            [
                'enum E { A = "zzz-e-1" }',
                'const anyKey: any = "zzz-k";',
                'const plain = { "zzz-k-1": 1, zzzK2: 2 };',
                'const computed = { ["zzz-k-3" as const]: 1 };',
                "const viaAny = { [anyKey]: 1 };",
                "const viaEnum = { [E.A]: 1 };",
                "const spread = { ...plain };",
                "const empty = {};",
            ].join("\n"),
        );
        const objects = variables(file)
            .filter((declaration) => declaration.initializer !== undefined)
            .map((declaration) => unwrapInitializer(declaration.initializer as ts.Expression).expression)
            .filter(ts.isObjectLiteralExpression);
        expect(kinds(objects.map((object) => readObjectLiteralKeys(checker, object)))).toEqual([
            "values",
            "values",
            "indeterminate",
            "not-a-catalogue",
            "not-a-catalogue",
            "not-a-catalogue",
        ]);
    });

    it("readResolvedStringLiteralUnion: 別名越しの any は判定保留、素の any / unknown / enum は非候補", () => {
        const { checker, file } = analyze(
            [
                'enum E { A = "zzz-e-2" }',
                "type Dynamic = any;",
                'type Ok = "zzz-u-1" | "zzz-u-2";',
                "type ViaAlias = Dynamic;",
                "type PlainAny = any;",
                "type PlainUnknown = unknown;",
                "type Open = string;",
                "type FromEnum = E;",
            ].join("\n"),
        );
        const aliases = collect(file, ts.isTypeAliasDeclaration);
        expect(kinds(aliases.map((alias) => readResolvedStringLiteralUnion(checker, alias)))).toEqual([
            "not-a-catalogue", // type Dynamic = any (構文が any の綴りなので正常な非候補)
            "values",
            "indeterminate",
            "not-a-catalogue",
            "not-a-catalogue",
            "not-a-catalogue",
            "not-a-catalogue",
        ]);
    });

    it("readSwitchCaseValues: case の式が any なら判定保留、enum の要素・0 件なら非候補", () => {
        const { checker, file } = analyze(
            [
                'enum E { A = "zzz-e-3" }',
                'const anyCase: any = "zzz-c";',
                'const ok = (v: string): number => { switch (v) { case "zzz-c-1": return 1; default: return 0; } };',
                "const viaAny = (v: string): number => { switch (v) { case anyCase: return 1; default: return 0; } };",
                "const viaEnum = (v: unknown): number => { switch (v) { case E.A: return 1; default: return 0; } };",
                "const none = (v: string): number => { switch (v) { default: return 0; } };",
            ].join("\n"),
        );
        const switches = collect(file, ts.isSwitchStatement);
        expect(kinds(switches.map((statement) => readSwitchCaseValues(checker, statement)))).toEqual([
            "values",
            "indeterminate",
            "not-a-catalogue",
            "not-a-catalogue",
        ]);
    });

    it("unwrapInitializer: 丸括弧 / as / satisfies の入れ子を剥がし、satisfies の型ノードを返す", () => {
        const { file } = analyze('const a = ((["zzz-1"] as const) satisfies readonly string[]);');
        const declaration = variables(file)[0];
        const unwrapped = unwrapInitializer(declaration.initializer as ts.Expression);
        expect(ts.isArrayLiteralExpression(unwrapped.expression)).toBe(true);
        expect(unwrapped.satisfiesType).toBeDefined();
    });

    it("isIndeterminateType: 構文が any / unknown の綴りそのものなら判定保留にしない", () => {
        const { checker, file } = analyze("type Dynamic = any;\ntype ViaAlias = Dynamic;");
        const [plain, viaAlias] = collect(file, ts.isTypeAliasDeclaration);
        const typeOf = (alias: ts.TypeAliasDeclaration): ts.Type => {
            const symbol = checker.getSymbolAtLocation(alias.name);
            if (symbol === undefined) throw new EnumTsSyncError(svelteFile, "記号を解決できません");
            return checker.getDeclaredTypeOfSymbol(symbol);
        };
        expect(isIndeterminateType(typeOf(plain), plain.type)).toBe(false);
        expect(isIndeterminateType(typeOf(viaAlias), viaAlias.type)).toBe(true);
    });
});

/** カテゴリ 2 / 7: 派生の除外と証人の索引。 */
describe("isDerivedObjectKeys() (対応表のキーの派生除外)", () => {
    const derived: DerivedFacts = {
        hasExplicitType: true,
        explicitTypeResolved: true,
        hasStringIndexSignature: false,
        hasOptionalProperty: false,
        requiredKeys: ["a", "b"],
        writtenKeys: ["b", "a"],
        witnessed: true,
    };

    it("5 条件をすべて満たすときだけ派生と認める", () => {
        expect(isDerivedObjectKeys(derived)).toBe(true);
    });

    it.each([
        ["明示の型が無い", { hasExplicitType: false }],
        ["明示の型を解決できない", { explicitTypeResolved: false }],
        ["文字列の添字シグネチャがある", { hasStringIndexSignature: true }],
        ["任意プロパティがある", { hasOptionalProperty: true }],
        ["必須プロパティが 0 件", { requiredKeys: [] }],
        ["書かれたキーが必須プロパティと違う (欠落)", { requiredKeys: ["a", "b", "c"] }],
        ["書かれたキーが必須プロパティと違う (余剰)", { requiredKeys: ["a"] }],
        ["証人が無い", { witnessed: false }],
    ] as const)("%s なら派生と認めない (候補として残す)", (_label, patch) => {
        expect(isDerivedObjectKeys({ ...derived, ...patch })).toBe(false);
    });
});

describe("buildWitnessIndex() (証人の資格)", () => {
    const candidate = (shape: TsCandidateShape, values: readonly string[]): TsUnionCandidate => ({
        locator: { file: "resources/js/types/x.ts", shape, name: "X", occurrence: 0 },
        line: 1,
        topLevel: true,
        values: new Set(values),
        correspondenceName: "X",
        nameResolved: true,
    });

    it("対応表のキー形だけの候補集合では索引が空になる (対応表は証人になれない)", () => {
        expect(buildWitnessIndex([candidate("object-keys", ["a", "b"])]).size).toBe(0);
    });

    it("対応表以外の形は証人になれる", () => {
        const index = buildWitnessIndex([
            candidate("literal-union", ["a", "b"]),
            candidate("const-array", ["c"]),
            candidate("switch-cases", ["d"]),
        ]);
        expect(index.has("a b")).toBe(true);
        expect(index.size).toBe(3);
    });
});

/** カテゴリ 8: 分岐の判定対象の名前。 */
describe("switchSubjectName() (分岐のラベルの名前解決)", () => {
    const svelteFile = "tests/js/support/enum-ts-sync/fixtures/svelte/__switch__.svelte";
    const body = (subject: string): string =>
        `{ switch (${subject}) { case "zzz-s-1": return 1; default: return 0; } }`;
    const source = [
        '<script lang="ts">',
        'type SubjectKind = "zzz-s-1" | "zzz-s-2";',
        `export const a = (subject: SubjectKind): number => ${body("subject")};`,
        `export const b = (holder: { kind: SubjectKind }): number => ${body("holder.kind")};`,
        `export const c = (plain: string): number => ${body("plain")};`,
        `export const d = (make: () => string): number => ${body("make()")};`,
        `export const e = (table: readonly string[]): number => ${body("table[0]")};`,
        "</script>",
        "",
    ].join("\n");

    const subjects = (): readonly (string | null)[] => {
        const unit = toVirtualUnit(svelteFile, source);
        const fixture = createFixtureProgram([], [unit]);
        const file = fixture.program.getSourceFile(unit.virtualPath);
        expect(file).toBeDefined();
        if (file === undefined) return [];
        const names: (string | null)[] = [];
        const visit = (node: ts.Node): void => {
            if (ts.isSwitchStatement(node)) names.push(switchSubjectName(fixture.checker, node.expression, file));
            ts.forEachChild(node, visit);
        };
        visit(file);
        return names;
    };

    it("型別名が解決できれば型の名前を優先し、できなければ識別子とプロパティ参照だけを名前にする", () => {
        const [aliasIdentifier, aliasProperty, plainIdentifier, call, indexed] = subjects();
        expect(aliasIdentifier).toBe("SubjectKind");
        expect(aliasProperty).toBe("SubjectKind");
        expect(plainIdentifier).toBe("plain");
        // 呼び出し式・添字アクセスは名前対応に使わない (任意の式の字面を名前にしない)。
        expect(call).toBeNull();
        expect(indexed).toBeNull();
    });
});

const phpEnum = (path_: string, values: readonly string[], line = 1): ResolvedPhpEnum => ({
    path: path_,
    name: shortEnumName(path_),
    line,
    values: new Set(values),
});

const tsCandidate = (
    file: string,
    name: string,
    values: readonly string[],
    options: { readonly shape?: TsCandidateShape; readonly correspondenceName?: string | null } = {},
): TsUnionCandidate => ({
    locator: { file, shape: options.shape ?? "literal-union", name, occurrence: 0 },
    line: 1,
    topLevel: true,
    values: new Set(values),
    correspondenceName: options.correspondenceName === undefined ? name : options.correspondenceName,
    nameResolved: (options.correspondenceName === undefined ? name : options.correspondenceName) !== null,
});

describe("findUnregisteredMirrorCandidates() (逆走査の突き合わせ純関数)", () => {
    const notRegistered = (): boolean => false;

    it("E1: 値集合が完全一致する未登録の宣言は規則 1 で見つかる", () => {
        const { found } = findUnregisteredMirrorCandidates(
            [phpEnum("app/Enums/Foo.php", ["a", "b"])],
            [tsCandidate("resources/js/types/x.ts", "Unrelated", ["a", "b"])],
            notRegistered,
        );
        expect(found).toHaveLength(1);
        expect(found[0].rule).toBe("1");
        expect(found[0].reason).toBe("完全一致");
    });

    it("E2: 完全一致でも登録済みなら見つからない", () => {
        const { found } = findUnregisteredMirrorCandidates(
            [phpEnum("app/Enums/Foo.php", ["a", "b"])],
            [tsCandidate("resources/js/types/x.ts", "Foo", ["a", "b"])],
            () => true,
        );
        expect(found).toEqual([]);
    });

    it("E3: 名前が一致し値が交差 (完全一致ではない) する未登録の宣言は規則 2a で見つかる", () => {
        const { found } = findUnregisteredMirrorCandidates(
            [phpEnum("app/Enums/Foo.php", ["a", "b", "c"])],
            [tsCandidate("resources/js/types/x.ts", "Foo", ["a", "z"])],
            notRegistered,
        );
        expect(found).toHaveLength(1);
        expect(found[0].rule).toBe("2a");
        expect(found[0].onlyInPhp).toEqual(["b", "c"]);
        expect(found[0].onlyInTs).toEqual(["z"]);
    });

    it("E4: 名前が複数形接尾辞 (s) で対応し値が交差すると規則 2a で見つかる", () => {
        const { found } = findUnregisteredMirrorCandidates(
            [phpEnum("app/Enums/Foo.php", ["a", "b"])],
            [tsCandidate("resources/js/types/x.ts", "Foos", ["a", "z"])],
            notRegistered,
        );
        expect(found).toHaveLength(1);
        expect(found[0].rule).toBe("2a");
    });

    it("E5: 複数形接尾辞 (es) でも対応する", () => {
        const { found } = findUnregisteredMirrorCandidates(
            [phpEnum("app/Enums/Box.php", ["a", "b"])],
            [tsCandidate("resources/js/types/x.ts", "Boxes", ["a", "z"])],
            notRegistered,
        );
        expect(found).toHaveLength(1);
        expect(found[0].rule).toBe("2a");
    });

    it("E6: 接尾辞 values でも対応する", () => {
        const { found } = findUnregisteredMirrorCandidates(
            [phpEnum("app/Enums/Foo.php", ["a", "b"])],
            [tsCandidate("resources/js/types/x.ts", "FooValues", ["a", "z"])],
            notRegistered,
        );
        expect(found).toHaveLength(1);
        expect(found[0].rule).toBe("2a");
    });

    it("E7: 名前が対応しても値が交差しなければ見つからない", () => {
        const { found } = findUnregisteredMirrorCandidates(
            [phpEnum("app/Enums/Foo.php", ["a", "b"])],
            [tsCandidate("resources/js/types/x.ts", "Foo", ["x", "y"])],
            notRegistered,
        );
        expect(found).toEqual([]);
    });

    it("E8: 値が交差しても名前が対応しなければ見つからない (緩い名前対応は採らない)", () => {
        const { found } = findUnregisteredMirrorCandidates(
            [phpEnum("app/Enums/Foo.php", ["a", "b"])],
            [tsCandidate("resources/js/types/x.ts", "CompletelyUnrelatedName", ["a", "b", "c"])],
            notRegistered,
        );
        expect(found).toEqual([]);
    });

    it("E9: 名前も値も対応しなければ見つからない", () => {
        const { found } = findUnregisteredMirrorCandidates(
            [phpEnum("app/Enums/Foo.php", ["a", "b"])],
            [tsCandidate("resources/js/types/x.ts", "Bar", ["x", "y"])],
            notRegistered,
        );
        expect(found).toEqual([]);
    });

    it("E10: 厳密名対応 (2a) は英数字以外を除去しない。語対応 (2b) は区切りとして割るので成立する", () => {
        // 2a の側は Foo_Bar と FooBar を同一視しない (この不変条件は維持する)。
        expect(strictNameCorrespondence("FooBar", "Foo_Bar")).toBeNull();
        // 論理和にしたので、語に割れば対応する 2b の側では鳴る (意図した拡張)。
        const { found } = findUnregisteredMirrorCandidates(
            [phpEnum("app/Enums/Foo_Bar.php", ["a", "b"])],
            [tsCandidate("resources/js/types/x.ts", "FooBar", ["a", "z"])],
            notRegistered,
        );
        expect(found).toHaveLength(1);
        expect(found[0].rule).toBe("2b");
    });

    it("E11: 名前の一部が一致するだけ (部分文字列) では対応と認めない", () => {
        const { found } = findUnregisteredMirrorCandidates(
            [phpEnum("app/Enums/Foo.php", ["a", "b"])],
            [tsCandidate("resources/js/types/x.ts", "MyFooValue", ["a", "z"])],
            notRegistered,
        );
        expect(found).toEqual([]);
    });

    it("E12: 大文字小文字の違いだけは対応と認める (名前対応は大小無視)", () => {
        const { found } = findUnregisteredMirrorCandidates(
            [phpEnum("app/Enums/Foo.php", ["a", "b", "c"])],
            [tsCandidate("resources/js/types/x.ts", "FOO", ["a", "z"])],
            notRegistered,
        );
        expect(found).toHaveLength(1);
        expect(found[0].rule).toBe("2a");
    });

    it("E13: 判定は ResolvedPhpEnum.name を使う (ファイル名の語幹と enum 名が食い違っていても name を見る)", () => {
        const { found } = findUnregisteredMirrorCandidates(
            [{ path: "app/Enums/FileStem.php", name: "ActualEnumName", line: 1, values: new Set(["a", "b"]) }],
            [tsCandidate("resources/js/types/x.ts", "ActualEnumName", ["a", "z"])],
            notRegistered,
        );
        expect(found).toHaveLength(1);
        expect(found[0].rule).toBe("2a");
        // ファイル名の語幹 (FileStem) とは対応しないので、そちらでは見つからないことも確かめる。
        const notFoundByFileStem = findUnregisteredMirrorCandidates(
            [{ path: "app/Enums/FileStem.php", name: "ActualEnumName", line: 1, values: new Set(["a", "b"]) }],
            [tsCandidate("resources/js/types/x.ts", "FileStem", ["a", "z"])],
            notRegistered,
        );
        expect(notFoundByFileStem.found).toEqual([]);
    });
});

/** カテゴリ 5: 規則 2 の論理和 (2a と 2b はどちらも他方を包含しない)。 */
describe("規則 2 の論理和 (2a ∨ 2b)", () => {
    it("wordForms() の期待値 (1 つの正規形へ畳まない)", () => {
        expect([...wordForms("status")].sort()).toEqual(["statu", "status"]);
        expect([...wordForms("statuses")].sort()).toEqual(["status", "statuse", "statuses"]);
        expect([...wordForms("class")].sort()).toEqual(["class"]);
        expect([...wordForms("policies")].sort()).toEqual(["policie", "policies", "policy"]);
        expect([...wordForms("kind")].sort()).toEqual(["kind"]);
    });

    it.each([
        ["status", "statuses", true],
        ["class", "classes", true],
        ["policy", "policies", true],
        ["value", "values", true],
        ["kind", "kinds", true],
        ["case", "cases", true],
        ["response", "responses", true],
        ["use", "uses", true],
        ["status", "state", false],
        ["code", "codec", false],
    ] as const)("語の対応: %s ⇔ %s = %s", (a, b, expected) => {
        expect(correspondWords(a, b)).toBe(expected);
        expect(correspondWords(b, a)).toBe(expected);
    });

    it("最大マッチング: 候補側の 1 語を 2 回数えない", () => {
        expect(maxWordMatching(["status", "status"], ["status"])).toBe(1);
        expect(maxWordMatching(["status", "status"], ["status", "statuses"])).toBe(2);
    });

    it("最大マッチング: 増補路が要る入力でも最大値へ届く", () => {
        // 左 L1 は {R1, R2} と、L2 は {R1} とだけ対応する。貪欲に L1→R1 を選んでも
        // 付け替えて大きさ 2 になること。
        expect(maxWordMatching(["kind", "case"], ["cases", "kinds"])).toBe(2);
    });

    it("2b だけが拾う組 (厳密名対応では鳴らない)", () => {
        const outcome = matchReverseRule(
            phpEnum("app/Enums/JobStatus.php", ["queued", "running", "succeeded", "failed"]),
            tsCandidate("resources/js/types/dashboard.ts", "DashboardJobStatus", ["queued", "running"]),
        );
        expect(outcome.kind === "match" && outcome.rule).toBe("2b");
    });

    it("2a だけが拾う組 (両側半分以上の交差を満たさない)", () => {
        const outcome = matchReverseRule(
            phpEnum("app/Enums/Foo.php", ["a", "b", "c", "d", "e"]),
            tsCandidate("resources/js/types/x.ts", "Foo", ["a", "y", "z"]),
        );
        expect(outcome.kind === "match" && outcome.rule).toBe("2a");
    });

    it("2a と 2b の両方に当たる組は 2a が勝つ (判定は排他)", () => {
        const outcome = matchReverseRule(
            phpEnum("app/Enums/JobStatus.php", ["queued", "running", "failed"]),
            tsCandidate("resources/js/types/x.ts", "JobStatus", ["queued", "running", "zzz-extra"]),
        );
        expect(outcome.kind === "match" && outcome.rule).toBe("2a");
    });

    it.each([
        ["接頭辞つき", "PrejobStatus"],
        ["打ち消しつき", "JobNonstatus"],
        ["接尾辞つき", "JobStatusKind"],
    ])("2b の負例 3 形 (%s) は鳴らない", (_label, name) => {
        const outcome = matchReverseRule(
            phpEnum("app/Enums/JobStatus.php", ["queued", "running", "failed"]),
            tsCandidate("resources/js/types/x.ts", name, ["queued", "running"]),
        );
        expect(outcome.kind).toBe("none");
    });

    it("2b は主要語が一致しても交差が片側半分未満なら鳴らない", () => {
        const outcome = matchReverseRule(
            phpEnum("app/Enums/JobStatus.php", ["queued", "running", "succeeded", "failed"]),
            tsCandidate("resources/js/types/x.ts", "DashboardJobStatus", ["queued", "z1", "z2", "z3"]),
        );
        expect(outcome.kind).toBe("none");
    });

    it("境界 8': 名前を決められない候補は、交差があれば判定不能・無ければ鳴らない", () => {
        const undecided = matchReverseRule(
            phpEnum("app/Enums/Foo.php", ["a", "b", "c"]),
            tsCandidate("resources/js/types/x.ts", "switch:next()", ["a", "z"], {
                shape: "switch-cases",
                correspondenceName: null,
            }),
        );
        expect(undecided.kind).toBe("undecidable");

        const silent = matchReverseRule(
            phpEnum("app/Enums/Foo.php", ["a", "b", "c"]),
            tsCandidate("resources/js/types/x.ts", "switch:next()", ["y", "z"], {
                shape: "switch-cases",
                correspondenceName: null,
            }),
        );
        expect(silent.kind).toBe("none");
    });

    it("宣言名から語が取れない候補は、交差が半分未満でも例外になる (黙って通さない)", () => {
        // 交差率の早期 return より前に語の非空を見ていないと、この組は `none` で
        // 黙って通ってしまう (Codex 実装レビュー Round 1 の Critical)。
        expect(() =>
            matchReverseRule(
                phpEnum("app/Enums/Foo.php", ["a", "b", "c", "d"]),
                tsCandidate("resources/js/types/x.ts", "___", ["a", "x", "y"], { correspondenceName: "___" }),
            ),
        ).toThrow("宣言名から語を 1 つも取り出せません");
    });

    it("宣言名から語が取れない候補は例外になる (静かに名前不一致へ混ぜない)", () => {
        expect(() =>
            matchReverseRule(
                phpEnum("app/Enums/Foo.php", ["a", "b"]),
                tsCandidate("resources/js/types/x.ts", "___", ["a", "z"], { correspondenceName: "___" }),
            ),
        ).toThrow("宣言名から語を 1 つも取り出せません");
    });
});

/** カテゴリ 6: 申告の生死判定は「免除を適用する前」の集合で行う。 */
describe("auditReverseSweepExemptions() (申告の突き合わせ)", () => {
    const php = phpEnum("app/Enums/Foo.php", ["a", "b"]);
    const candidate = tsCandidate("resources/js/types/x.ts", "Unrelated", ["a", "b"]);
    const exemption = {
        php: php.path,
        locator: candidate.locator,
        rule: "1",
        reason: "テストの見本なので登録しない (30 文字以上の理由をここに書いておく)",
    } as const;

    it("申告した候補は unexempted から外れ、stale にもならない", () => {
        const { found } = findUnregisteredMirrorCandidates([php], [candidate], () => false);
        const audit = auditReverseSweepExemptions(found, [exemption]);
        expect(audit.unexempted).toEqual([]);
        expect(audit.stale).toEqual([]);
    });

    it("免除を適用した後の集合で判定すると、自分自身を根拠にする申告が stale になる", () => {
        const { found } = findUnregisteredMirrorCandidates([php], [candidate], () => false);
        const afterExemption = auditReverseSweepExemptions(found, [exemption]).unexempted;
        // 生死判定に「免除適用後」を渡すと、申告が実態から消えたことになる = この形にしない。
        expect(auditReverseSweepExemptions(afterExemption, [exemption]).stale).toHaveLength(1);
    });

    it("規則が移ると申告は stale になる", () => {
        const { found } = findUnregisteredMirrorCandidates([php], [candidate], () => false);
        expect(auditReverseSweepExemptions(found, [{ ...exemption, rule: "2a" }]).stale).toHaveLength(1);
    });

    it("occurrence が違えば片方の申告はもう片方へ効かない", () => {
        const other = tsCandidate("resources/js/types/x.ts", "Unrelated", ["a", "b"]);
        const moved: TsUnionCandidate = { ...other, locator: { ...other.locator, occurrence: 1 } };
        const { found } = findUnregisteredMirrorCandidates([php], [candidate, moved], () => false);
        expect(found).toHaveLength(2);
        const audit = auditReverseSweepExemptions(found, [exemption]);
        // occurrence 0 を申告しても occurrence 1 は残る。
        expect(audit.unexempted).toHaveLength(1);
        expect(audit.unexempted[0].candidate.locator.occurrence).toBe(1);
        expect(audit.stale).toEqual([]);
    });

    it("occurrence が違うと申告は stale になる", () => {
        const { found } = findUnregisteredMirrorCandidates([php], [candidate], () => false);
        const moved = { ...exemption, locator: { ...exemption.locator, occurrence: 1 } };
        expect(auditReverseSweepExemptions(found, [moved]).stale).toHaveLength(1);
    });
});

/** カテゴリ 9: 本番の走査を通した候補の形・locator・派生・証人。 */
describe("collectTsCandidates() (本番の走査を通した見本の検査)", () => {
    let programs: MirrorPrograms | undefined;
    let scan: TsCandidateScan | undefined;

    const requireScan = (): TsCandidateScan => {
        if (scan === undefined) throw new EnumTsSyncError("scan", "初期化されていません");
        return scan;
    };

    const find = (
        file: string,
        shape: TsCandidateShape,
        name: string,
        occurrence = 0,
    ): TsUnionCandidate | undefined =>
        requireScan().candidates.find(
            (candidate) => locatorKey(candidate.locator) === `${file}|${shape}|${name}|${occurrence}`,
        );

    const values = (
        file: string,
        shape: TsCandidateShape,
        name: string,
        occurrence = 0,
    ): readonly string[] => [...(find(file, shape, name, occurrence)?.values ?? [])].sort();

    beforeAll(() => {
        programs = createMirrorPrograms();
        scan = collectTsCandidates(programs);
    }, 300_000);

    it("母集団は版管理下の全数で、道具パッケージも `.svelte` も含む", () => {
        const { population } = programs ?? { population: { ts: [], svelte: [] } };
        expect(population.ts).toContain("packages/cli/src/api/schemas.ts");
        expect(population.svelte).toContain("resources/js/components/features/manual/ScenarioEditor.svelte");
        expect(programs?.ownerOf("packages/cli/src/api/schemas.ts")).toBe("packages/cli");
        expect(programs?.ownerOf("resources/js/types/manual.ts")).toBe("<root>");
    });

    it("道具パッケージは自前の tsconfig (NodeNext) で解決される", () => {
        expect(values("packages/cli/src/api/schemas.ts", "const-array", "API_ERROR_CODES")).toContain(
            "rate_limited",
        );
        expect(values("packages/cli/src/api/schemas.ts", "literal-union", "ApiErrorCode")).toContain(
            "quota_exceeded",
        );
    });

    it("道具パッケージの program はそのパッケージ自身の tsconfig の設定で組まれている", () => {
        // 値集合だけを見ても差は出ない (現物の候補は同一ファイル内で閉じている) ので、
        // **どの設定で組まれた program に載っているか**を直接突く。
        // `packages/cli` をルートの program へ混ぜる改変を入れると `ownerOf` が
        // `<root>` を返し、ここと母集団の直和検査が赤くなる。
        expect(programs?.ownerOf("packages/cli/src/api/client.ts")).toBe("packages/cli");
        const owner = programs?.programOf("packages/cli/src/api/client.ts");
        expect(owner?.program.getCompilerOptions().moduleResolution).toBe(ts.ModuleResolutionKind.NodeNext);

        const root = programs?.byOwner.get("<root>");
        expect(root?.program.getCompilerOptions().moduleResolution).toBe(ts.ModuleResolutionKind.Bundler);
    });

    it("4 形すべてを拾う", () => {
        expect(values(`${FIXTURE}/mixed.ts`, "literal-union", "LiteralUnionCandidate")).toEqual(["a", "b"]);
        expect(values(`${FIXTURE}/mixed.ts`, "const-array", "ConstArrayCandidate")).toEqual([
            "zzz-sample-1",
            "zzz-sample-2",
        ]);
        expect(values(`${FIXTURE}/mixed.ts`, "object-keys", "ObjectKeysCandidate")).toEqual([
            "zzz-key-1",
            "zzzKey2",
        ]);
        expect(values(`${FIXTURE}/mixed.ts`, "switch-cases", "switch:value")).toEqual(["a", "b"]);
    });

    it("包み (as const / satisfies / 丸括弧) を剥がして読む", () => {
        expect(values(`${FIXTURE}/mixed.ts`, "const-array", "ConstArrayAsConst")).toEqual(["zzz-sample-3"]);
        expect(values(`${FIXTURE}/mixed.ts`, "const-array", "ConstArraySatisfies")).toEqual(["zzz-sample-4"]);
        expect(values(`${FIXTURE}/mixed.ts`, "const-array", "ConstArrayParenthesized")).toEqual(["zzz-sample-5"]);
    });

    it("非候補は拾わない (開いた文字列 / 数値 / let の配列 / 非リテラル混在 / 空配列 / 展開)", () => {
        for (const [shape, name] of [
            ["literal-union", "NotAUnionCandidate"],
            ["literal-union", "NumberCandidate"],
            ["literal-union", "ExplicitAnyCandidate"],
            ["literal-union", "ExplicitUnknownCandidate"],
            ["const-array", "LetArrayCandidate"],
            ["const-array", "MixedArrayCandidate"],
            ["const-array", "EmptyArrayCandidate"],
            ["object-keys", "ObjectSpreadCandidate"],
        ] as const) {
            expect(find(`${FIXTURE}/mixed.ts`, shape, name), `${name} は非候補であること`).toBeUndefined();
        }
    });

    it("計算キーは型検査器が文字列リテラルへ解決したときだけ読む", () => {
        expect(values(`${FIXTURE}/mixed.ts`, "object-keys", "ObjectComputedKeyCandidate")).toEqual(["zzz-key-4"]);
    });

    it("判定保留は候補にも非候補にもならず indeterminate へ入る", () => {
        const keys = requireScan().indeterminate.map((row) => locatorKey(row.locator));
        expect(keys).toContain(`${FIXTURE}/mixed.ts|literal-union|IndirectAnyCandidate|0`);
        expect(keys).toContain(`${FIXTURE}/mixed.ts|object-keys|ObjectAnyComputedKeyCandidate|0`);
        expect(find(`${FIXTURE}/mixed.ts`, "literal-union", "IndirectAnyCandidate")).toBeUndefined();
    });

    it("入れ子の宣言も拾い、同名なら occurrence で区別する", () => {
        expect(values(`${FIXTURE}/mixed.ts`, "literal-union", "NestedShadow", 0)).toEqual(["zzz-nested-1"]);
        expect(values(`${FIXTURE}/mixed.ts`, "literal-union", "NestedShadow", 1)).toEqual(["zzz-nested-2"]);
        expect(find(`${FIXTURE}/mixed.ts`, "literal-union", "NestedShadow", 0)?.topLevel).toBe(false);
    });

    it("採番は三値をまたぐ (判定保留が先・候補が後なら候補は occurrence 1)", () => {
        const staged = `${FIXTURE}/staged-occurrence.ts`;
        // 判定保留は occurrence 0 を占める。
        expect(requireScan().indeterminate.map((row) => locatorKey(row.locator))).toContain(
            `${staged}|literal-union|StagedShadow|0`,
        );
        expect(find(staged, "literal-union", "StagedShadow", 0)).toBeUndefined();
        expect(values(staged, "literal-union", "StagedShadow", 1)).toEqual(["zzz-staged-1"]);
    });

    it("採番は三値をまたぐ (非候補が先・候補が後なら候補は occurrence 1)", () => {
        const staged = `${FIXTURE}/staged-occurrence.ts`;
        expect(find(staged, "literal-union", "MixedShadow", 0)).toBeUndefined();
        expect(values(staged, "literal-union", "MixedShadow", 1)).toEqual(["zzz-mixed-1"]);
    });

    it("入れ子が先・最上位が後なら、最上位の occurrence は 0 ではない", () => {
        const nested = find(`${FIXTURE}/nested-occurrence.ts`, "literal-union", "NestedFirst", 0);
        const top = find(`${FIXTURE}/nested-occurrence.ts`, "literal-union", "NestedFirst", 1);
        expect(nested?.topLevel).toBe(false);
        expect(top?.topLevel).toBe(true);
        expect([...(top?.values ?? [])]).toEqual(["zzz-nested-4"]);
    });

    it("派生の対応表は証人があるときだけ外れる", () => {
        for (const name of ["DerivedRecord", "DerivedSatisfies", "DerivedViaAlias", "DerivedViaKeyof", "DerivedViaImport"]) {
            expect(find(`${FIXTURE}/derived.ts`, "object-keys", name), `${name} は派生として外れる`).toBeUndefined();
        }
        for (const name of [
            "DerivedPartial",
            "DerivedIndexSignature",
            "DerivedMissingKey",
            "DerivedExtraKey",
            "DerivedUnionType",
            "DerivedIntersectionType",
            "DerivedNoExplicitType",
            "DerivedWitnessless",
        ]) {
            expect(find(`${FIXTURE}/derived.ts`, "object-keys", name), `${name} は候補として残る`).toBeDefined();
        }
    });

    it("証人は対応表以外の形に限る (自己証人・相互証人・循環証人では消えない)", () => {
        for (const name of [
            "SelfWitness",
            "MutualWitnessA",
            "MutualWitnessB",
            "CycleWitnessA",
            "CycleWitnessB",
            "CycleWitnessC",
        ]) {
            expect(find(`${FIXTURE}/witness-cycle.ts`, "object-keys", name), `${name} は候補として残る`).toBeDefined();
        }
    });

    it(".svelte の script の中の 4 形を拾い、module と実体を 1 つの単位として扱う", () => {
        const svelte = "tests/js/support/enum-ts-sync/fixtures/svelte/Sample.svelte";
        expect(values(svelte, "literal-union", "SampleModuleKind")).toEqual(["zzz-svelte-1", "zzz-svelte-2"]);
        // 実体側から module 側の型別名を参照できる (Svelte 本来の可視性)。
        expect(values(svelte, "literal-union", "SampleInstanceKind")).toEqual(["zzz-svelte-1", "zzz-svelte-2"]);
        expect(values(svelte, "const-array", "SAMPLE_LIST")).toEqual(["zzz-svelte-1", "zzz-svelte-2"]);
        expect(values(svelte, "object-keys", "SAMPLE_LABELS")).toEqual(["zzz-svelte-1", "zzz-svelte-2"]);
        expect(values(svelte, "switch-cases", "switch:kind")).toEqual(["zzz-svelte-1", "zzz-svelte-2"]);
    });

    it(".svelte はモジュール文脈なので、別のコンポーネントの同名宣言と混ざらない", () => {
        expect(
            values("tests/js/support/enum-ts-sync/fixtures/svelte/Other.svelte", "literal-union", "SampleInstanceKind"),
        ).toEqual(["zzz-svelte-3"]);
    });
});
```

## 変更した箇所だけの差分 (gate / 負例行列 / 文書)

```diff
diff --git a/docs/architecture.md b/docs/architecture.md
index 2f85ef95..df63533c 100644
--- a/docs/architecture.md
+++ b/docs/architecture.md
@@ -3086,102 +3086,222 @@ ### 保証しないもの (誇張しない。**本節が正本**)
 - **認可コードの交換時に所属を確認してはいない**。閉じているのは「失効の時点で未交換だった
   コードを撃つ」ところまでである (後続の候補)。
 
-## PHP 列挙と TypeScript 値域の同期 (T218 / 家系の裁定 AG-099 前半)
+## PHP 列挙と TypeScript 値域の同期 (T218 / T225 / T261。家系の機能台帳 `enum-ts-sync-gate` 正典 v3)
 
-サーバの語彙 (PHP の文字列付き列挙) を画面が受けるとき、TS 側は型別名の値域として
-同じ集合を持つ。片方だけ増えると画面の分岐に「どこにも当たらない値」が生まれ、
-**無言の描画漏れ**になる。これを 1 本の汎用 gate
-(`tests/js/architecture/enum-ts-sync.test.ts`) で固定する。
+サーバの語彙 (PHP の文字列付き列挙) を画面と付属の道具が受けるとき、TS 側は
+同じ集合を値域として持つ。片方だけ増えると分岐に「どこにも当たらない値」が生まれ、
+**無言の描画漏れ**になる。しかも**どちらの側も単体では整合している**ので、
+型検査でも通常のテストでも落ちない。これを 2 本の gate で固定する。
 
-- **登録の仕方**: 目録 `ENUM_TS_MIRRORS` へ 1 行 (PHP のパス / TS のパス / 型別名 / 理由) を足し、
-  件数の pin `EXPECTED_MIRROR_COUNT` を 1 増やす。**個別の検査ファイルは増やさない**
+| 向き | 検査 | 見るもの |
+|---|---|---|
+| 前向き | `tests/js/architecture/enum-ts-sync.test.ts` | 目録に**登録した関係**が成り立つこと |
+| 逆走査 | `tests/js/architecture/enum-ts-sync-discovery.test.ts` | **登録し忘れ**と**判定保留**が 0 件であること |
+
+### 目録 (単一の出典) と関係の 2 値
+
+- **登録の仕方**: 目録 `ENUM_TS_RELATIONS`
+  (`tests/js/support/enum-ts-sync/relation-inventory.ts`) へ 1 行足し、
+  件数の pin `EXPECTED_RELATION_COUNT` を 1 増やす。**個別の検査ファイルは増やさない**
   (裁定 AG-099 が止めたかったのは検査の増殖である)。
-  `note` に最小文字数は課さない — 本目録は**免除の申告ではなく登録**であり、
-  「検査から外す」判断ではないため。
-- **受理する形 (TS 側)**: 対象ファイルのトップレベルに、その名前の型別名の宣言が
-  ちょうど 1 つあり、**解決・正規化された後の型**が文字列リテラル型だけの union
-  (または単独の文字列リテラル型) であること。別名参照・import 越しの参照・
-  `keyof typeof`・`Lowercase<…>`・具体化された条件型・有限のテンプレートリテラル型は
-  すべて受理する (型検査器が畳んだ後を見るため)。TypeScript の `enum` の値は受理しない
-  (本リポジトリに 1 件も無く、文字列リテラル型と同じ契約ではない。必要になってから広げる)。
+  目録は前向きと逆走査の**両方**が読む単一の出典である。
+- **`equal` と `subset` は同じ目録に載るが意味が違う**。
+  `equal` は**値域そのものの写し**で双方向の差分が空であること、
+  `subset` は**値域の写しではなく、許される値域から選んだ非空の集合**で
+  「TS 側にだけある値が無い」ことだけを見る。
+  **許可する値域と、そこから選んだ集合は別の概念である** — 例えばサーバの
+  `App\Enums\OAuth\CliOAuthScope` は「サーバが認識する全スコープ」、道具側の
+  `DEFAULT_CLI_SCOPES` は「道具が既定で要求する権限」である。完全一致で登録すると
+  「サーバにスコープを足したら道具も要求する」方向へ設計が引っ張られ最小権限に反する。
+  `subset` の登録は**前者を後者へ広げないための装置**であり、
+  `subsetReason` (30 文字以上) に**なぜ値域の写しではないのか**を書く。
+  **`subset` は逃げ道になり得る** (完全一致の写しを `subset` と偽れば緩む)。
+  機械では見分けられないので、`subsetReason` の記述とレビューで担保する。
+- **登録できる TS の置き場**は `resources/js/` (画面側) と `packages/<名前>/src/`
+  (付属のコマンドライン道具) で、拡張子は `.ts` と `.svelte`。
+  `tests/js/` と `packages/<名前>/tests/` は登録の置き場ではない
+  (検査の見本を写しとして登録しない)。
+- **受理する形 (TS 側) は 2 つ**である。対象ファイルの**最上位**にある
+  **型別名の宣言** (解決した型が文字列リテラル型だけ) か、**`const` 束縛の配列**
+  (`as const` の有無を問わない)。同じ名前で受理できる宣言がちょうど 1 つあること。
+  別名参照・import 越しの参照・`keyof typeof`・`Lowercase<…>`・具体化された条件型・
+  有限のテンプレートリテラル型はすべて受理する (型検査器が畳んだ後を見るため)。
+  TypeScript の `enum` の値は受理しない。
+  **配列の値は構文から読む** — `const X = ["a", "b"];` は型検査器の上では `string[]` に
+  広げられるので、型から要素を復元してはいけない。
+  **対応表のキーと分岐のラベルは登録できない**。写しとして扱うなら型別名か
+  定数の配列へ切り出す。
 - **受理する形 (PHP 側)**: 深さ 0 の `enum <名前>: string` がちょうど 1 つあり、
   その名前がファイル名の語幹と一致し、本体の直下の `case` が
   `case Name = '値';` / `case Name = "値";` の 1 行に一致すること。
   定数式・逆斜線・変数の埋め込み・複数行の case は例外にする。
-- **program は tsconfig が含む TS 全体で作る**。目録のファイルだけを起点にすると、
-  `include` だけで参加する宣言 (周囲宣言 / `declare global` / モジュールの拡張) が載らず
-  **本番の型と違う型世界**で判定してしまう (偽陰性)。速さのために起点を縮めない。
-  縮める改変が入ったら `enum-ts-sync-extractor.test.ts` の T25 が赤くなる。
-- **抽出器が静かに間違えないこと**は `tests/js/architecture/enum-ts-sync-extractor.test.ts` の
-  負例行列 (TS 27 件 / PHP 40 件) が固定する。見本の置き方は非対称で、
-  TS は**ファイル** (型検査器に実ファイルが要る。`tsconfig.json` の `exclude` で
-  `pnpm typecheck` の対象から外す)、PHP は**テスト内の文字列** (`.php` として置くと
-  strict_types 宣言 gate / 禁止文の字句走査 / Pint / PHPStan の母集団に入るため)。
-
-## 発見の段と逆走査 (T225 / 家系の裁定 AG-099 後半)
-
-`enum-ts-sync.test.ts` は目録に登録した写しだけを見る (未登録は沈黙する)。この欠落を
-`tests/js/architecture/enum-ts-sync-discovery.test.ts` が向きを変えて埋める
-(`docs/template-divergence.md` の D29 はこの実装で再判定条件を満たし、登録を削除した)。
-
-- **発見の段 (全数走査 → 既定拒否の分類)**: `buildPhpEnumCatalog()`
-  (`tests/js/support/enum-ts-sync/php-enum-catalog.ts`) が `app/` 配下の git 追跡下の
-  `*.php` を全数走査する。抽出器は既存の `readPhpEnumValuesFromText` が使う字句走査器を
-  `detectEnumHeaders` として共有し (**2 本目の抽出器を作らない**)、値集合を読めたもの
-  (`resolved`) と読めなかったもの (`unresolvable`) に分ける。`resolved` の**すべて**が
-  「登録済み (`ENUM_TS_MIRRORS`)」か「対象外の理由つき (`PHP_ENUM_EXEMPTIONS`。理由は
-  30 文字以上)」のどちらか一方に分類されていることを固定する。`unresolvable` の
-  **すべて**が `KNOWN_UNRESOLVABLE_PHP_ENUMS` に登録されていることを固定する。
-  どの分類にも入らない PHP 列挙が 1 件でもあれば赤くする (既定拒否)。登録先が実態と
-  食い違った (stale) ときも赤くする。
-  - `scan()` が拒否する字句 (バッククォート・ヒアドキュメント等) を含むファイルは、
-    生のソースに **`enum` の語が無ければ**母集団から外し、**あれば**
-    (直後の並びを問わず。コメントを挟む書き方・非 ASCII 識別子も見逃さない)
-    安全側に倒して `unresolvable` へ回す (取りこぼしを作らない側に倒す。実測では
-    ヒアドキュメントを持ちつつ docblock で「enum」に言及するだけの
-    `app/Mcp/Servers/AppMcpServer.php` がここで意図した過剰検出になる)。
-  - **波括弧付き namespace 宣言** (`namespace Foo { … }`。無名・大文字小文字・
-    コメントの割り込みを問わない) の中は `enum` 宣言の波括弧の深さが 1 になり、
-    「深さ 0」の前提が崩れる。**個別の namespace 構文を正規表現で当てるのではなく**、
-    `detectEnumHeaders` が返す**深さ付きの enum 候補**を見て、**深さ 0 でない候補が
-    1 件でも混ざっていれば**安全側で `unresolvable` へ回す (深さ 0 の候補だけを拾って
-    残りを黙って捨てると、同じファイルの別の深さ 0 enum の影に隠れて消えてしまう。
-    どんな書き方で深さがずれても同じ 1 つの判定で拾える。本リポジトリは波括弧無しの
-    namespace 宣言 (`namespace Foo;`) だけを使っており、現時点で該当ファイルは 0 件)。
-- **逆走査 (未登録候補の検出。2 規則)**: `collectTsUnionCandidates()`
-  (`tests/js/support/enum-ts-sync/ts-candidates.ts`) が `resources/js/` 配下の
-  文字列リテラル型だけの union に解決するトップレベルの型別名を全数走査する。
-  母集団は `tsconfig.json` の `include` (`resources/js/**` 配下の `*.ts`) が実際に
-  決めるが、**それだけを出典とは言わない** — `resources/js/` をプログラムを介さず
-  直接再帰的に歩いた `*.ts` (`.d.ts` を除く) の集合と、program に載った集合が
-  **完全一致すること**を独立実装の回帰テストで固定しており、この一致こそが
-  「登録済みファイルの import グラフに閉じない・tsconfig の `exclude` が
-  意図せず広がっていない」という不変条件の実体である
-  (`createMirrorProgram` の rootNames が tsconfig の全ファイルを含むことにも依存しない)。
-  走査対象ファイルの構文が壊れているときは無言で読み飛ばさず例外にする (fail-closed)。
-  `findUnregisteredMirrorCandidates()` (`tests/js/support/enum-ts-sync/reverse-sweep.ts`)
-  が未登録の宣言を PHP の母集団 (`resolved`。分類にかかわらず全件) と突き合わせる。
+- **登録行の locator は AST から解決する**。目録の行が持つのは `ts + declaration` だけで、
+  候補の同一性に要る**形**と**出現順**が無い。同名の入れ子の宣言が最上位より前にあると
+  最上位でも出現順は 0 とは限らないため、**逆走査の候補と同じ採番器**で locator を作り、
+  逆走査の「登録済み」の判定は **locator の完全一致**で行う (採番の実装を 2 本持たない)。
+
+### program はパッケージごとに作る
+
+**`packages/<名前>` をルートの設定 (bundler / ESNext) で読まない**。読むと NodeNext 前提の
+取り込みが解決できず、型が `any` に落ちた宣言が「文字列リテラル型ではない = 非候補」として
+**静かに消える**。「本番と同じ型世界」は、道具パッケージにとっては
+**そのパッケージ自身の tsconfig** である。したがって program を複数本持つ。
+
+| program | 起点 |
+|---|---|
+| `<root>` | ルート `tsconfig.json` の全ファイル ∪ どのパッケージにも属さない版管理下の `*.ts` ∪ 仮想 `.svelte` |
+| `packages/<名前>` (tsconfig を持つものだけ) | そのパッケージの tsconfig の全ファイル ∪ 配下の版管理下の `*.ts` ∪ 配下の仮想 `.svelte` |
+
+- 起点を**速さのために縮めない** (`include` だけで参加する周囲宣言 / `declare global` /
+  モジュールの拡張が載らないと本番と違う型世界になる。縮める改変が入ったら
+  `enum-ts-sync-extractor.test.ts` の T25 が赤くなる)。
+- **所属は `packages/<名前>/` の配下かどうかだけで決める** (tsconfig の有無で決めない)。
+  自前の tsconfig を持たないパッケージのファイルは**所有者の program が無い**ので
+  解決の時点で落ちる (fail-closed)。tsconfig の有無で所属を決めると、そのパッケージの
+  ファイルが黙って `<root>` の型世界へ落ちる。
+- **母集団の全件が「所有者」をちょうど 1 つ持つ**ことも別に検査する
+  (起点として 2 本以上の program に載っていないこと)。
+- **候補走査は「所有者の program 上の `SourceFile`」だけを使う**
+  (`program.getSourceFiles()` 全体は依存ライブラリ・推移的な取り込み・JSON が載るので
+  母集団の一致根拠にしない)。
+- **「型が `any` へ落ちて候補が静かに消える」は現物では観測されていない** —
+  現時点の `packages/cli` の取り込みはルートの設定 (bundler) でも解決できてしまう。
+  gate が機械で固定しているのは「どのファイルもちょうど 1 本の program に載る」ことと
+  「パッケージのファイルはそのパッケージの設定 (NodeNext) で組まれた program に載る」
+  ところまでである。**この方式は偽陰性を作らない側の予防であって、
+  現に偽陰性が起きていたことの証拠ではない**。
+
+### `.svelte` は 1 つの仮想 TS へ平坦化する
+
+`.svelte` は第一級の解析対象である。`svelte/compiler` の `parse` で script の範囲を取り、
+**script の中身以外を空白で潰した**仮想 TypeScript を **1 ファイルにつき 1 本**作る。
+潰すときに UTF-16 の符号単位の数を変えないので**行も列も元ファイルと一致する**。
+末尾に `export {};` を足して**モジュール文脈**にする (付けないと大域スクリプトになり、
+取り込みも書き出しも無いコンポーネント同士の宣言が混ざって偽の候補が立つ)。
+
+**文脈ごとに別ファイルへ割らない** (割ると module の宣言を実体側から参照できなくなる。
+Svelte では参照できる)。代わりに、平坦化で再現できない 2 つを
+**保証外にせず不合格条件として塞ぐ**:
+
+| 食い違い | Svelte 本来 | 平坦化した TS | 対処 |
+|---|---|---|---|
+| module から実体側の宣言を参照 | 見えない | 前方参照として解決する | **不合格** |
+| module と実体に同名の最上位束縛 | 実体側が覆う | 重複宣言になる | **不合格** |
+| 実体から module の宣言を参照 | 見える | 解決する | 正しいので許す |
+
+検査の呼び出し義務は利用側に無い — program を組む一本道が内部で必ず走らせ、
+低層の組み立て関数は輸出しないので検査を飛ばした program を外から作れない。
+**`.svelte` 全体が `parse` できることは前提**であり、script の外
+(目印の中・制御構文の中・スタイル) は候補にしない。
+
+### 逆走査 (母集団の全数 → 4 形の候補 → 3 規則)
+
+- **母集団**: `git ls-files` が返す**版管理下の `*.ts` と `*.svelte` の全数**。
+  **唯一の除外**は検出器自身の構文破壊見本 1 ディレクトリ
+  (`tests/js/support/enum-ts-sync/fixtures/candidates-broken/`) で、除外根は
+  `tests/js/support/enum-ts-sync/` の配下に限り、**件数を pin** し、
+  **配下の全ファイルが実際に本番と同じ入口で落ちること**を検査する
+  (これが「除外根へ正常なファイルを置いて母集団から静かに消す」経路を塞ぐ)。
+  型世界に載せる起点は `.d.ts` を**含み**、候補を探す対象は `.d.ts` を**除く**。
+  どちらかが 0 件なら例外にする。
+- **候補の形は 4 種**: リテラル型の合併 / 定数の配列 / 対応表のキー / 分岐のラベル。
+  **入れ子の宣言も拾う**ので、候補の同一性は「置き場・形・名前・出現順」の 4 つ組
+  (locator) で持つ。**行は診断にだけ使う** (同一性に入れると無関係な行移動で
+  申告が一斉に stale になる)。**採番は三値の分類より前に**構文上の宣言の場所の全体へ行う。
+- **三値にする**: 「候補かどうかを決められない」(`any` / `unknown` へ解決したが構文が
+  その綴りではない) を非候補と混ぜず、**判定保留**として
+  `KNOWN_INDETERMINATE_TS_DECLARATIONS` の既定拒否の申告で受ける。
+- **派生の除外**: 対応表のキーは、明示の型があり・文字列の添字シグネチャが無く・
+  プロパティが 1 件以上ですべて必須で・書かれたキーが必須プロパティと集合として一致し・
+  **`object-keys` 以外の形の候補に同じ値集合の証人がある**ときだけ外す。
+  証人の資格を「派生除外の対象になり得ない形」に限るのは**循環の遮断**である
+  (任意の候補を証人にすると、同じキー集合の対応表が互いを証人にして両方消える)。
+- **規則は 3 つ**で、判定は排他 (完全一致 → 交差 0 なら無視 → 名前不明なら判定不能 →
+  2a → 2b の順):
   - **規則 1 (完全一致)**: 値集合が PHP 列挙と完全一致する未登録の宣言 = 登録漏れの疑い。
-  - **規則 2 (名前対応 + 値の交差)**: 名前が厳密に対応し (大文字小文字の違いを除く
-    一致 / `+s` / `+es` / `+values`。**英数字以外を除去する正規化はしない**。
-    `Foo_Bar` と `FooBar` を同一視すると要件より緩くなるため) 値が交差するが
-    完全一致ではない未登録の宣言 = 片方だけ値を足してズレた写しの疑い。
-    緩い名前対応 (部分集合・ファイル名を名前に混ぜる形) は採らない
-    (家系の実測で偽陽性が支配的になったため)。判定は `ResolvedPhpEnum.name`
-    (抽出器が読んだ enum 宣言の名前) を使い、ファイル名の語幹からの再計算はしない。
-  - 見つかった候補は `REVERSE_SWEEP_EXEMPTIONS`
-    (`php` + `file` + `declaration` + `rule` の組で固定) に登録された分だけ許す。
-    未登録の候補が 1 件でもあれば赤くする。登録先が実態と食い違ったときも赤くする。
-
-### 保証しないもの (誇張しない。発見の段・逆走査を含む)
+  - **規則 2a (厳密名対応 + 1 値以上の交差)**: 小文字化して一致 / `+s` / `+es` / `+values`。
+    **英数字以外を除去する正規化はしない** (`Foo_Bar` と `FooBar` を 2a では同一視しない)。
+  - **規則 2b (語分割名対応 + 両側から見て半分以上の交差)**: 名前を語に割り、
+    主要語 (語列の末尾) が対応し、列挙の語と候補の語袋の**最大マッチング**が
+    `min(2, 列挙の語数)` 以上であること。**規則 2 は 2a と 2b の論理和**であり、
+    どちらの式も他方を包含しない (本リポジトリの実測が家系の未決論点 q2 への一次観測)。
+  - 語の正規化は**1 つの正規形へ畳まない**。接尾辞だけで畳むと `cases → cas` /
+    `uses → us` のような誤った語幹を正規形にしてしまうので、語ごとに候補形の集合を作り
+    **集合が交われば同じ語**とみなす (過剰検出の向き)。
+- **名前を決められない候補**: 分岐のラベルは判定対象の**型の名前**を優先し、
+  取れなければ識別子とプロパティ参照の連なりだけを名前に使う。どちらも取れないときは
+  規則 2 を判定できないので、**列挙と 1 値でも交差するなら判定不能として gate を赤くする**
+  (交差 0 なら規則 2 の対象になり得ないので黙って通す)。
+  候補から静かに落とすと、完全一致しない真の部分写しが規則 1 にも規則 2 にも掛からず
+  無言で通過する。
+- 見つかった候補は `REVERSE_SWEEP_EXEMPTIONS` (`php` + locator + 規則の組で固定) に
+  登録された分だけ許す。**申告の生死判定は「免除を適用する前」の候補集合に対して行う**
+  (免除適用後で判定すると、申告が自分自身を根拠にして永久に生き続ける)。
+
+### 発見の段 (PHP 側の全数走査 → 既定拒否の分類)
+
+`buildPhpEnumCatalog()` (`tests/js/support/enum-ts-sync/php-enum-catalog.ts`) が
+`app/` 配下の git 追跡下の `*.php` を全数走査する。抽出器は既存の
+`readPhpEnumValuesFromText` が使う字句走査器を `detectEnumHeaders` として共有し
+(**2 本目の抽出器を作らない**)、値集合を読めたもの (`resolved`) と読めなかったもの
+(`unresolvable`) に分ける。`resolved` の**すべて**が「TS との関係を登録済み
+(`ENUM_TS_RELATIONS`)」か「対象外の理由つき (`PHP_ENUM_EXEMPTIONS`。理由は 30 文字以上)」の
+どちらか一方に分類されていることを固定する (分類の呼び名を「登録済み」ではなく
+「TS との関係を登録済み」とするのは、`subset` の行を「写し」と言えないためである)。
+`unresolvable` の**すべて**が `KNOWN_UNRESOLVABLE_PHP_ENUMS` に登録されていることを固定する。
+どの分類にも入らない PHP 列挙が 1 件でもあれば赤くする (既定拒否)。登録先が実態と
+食い違った (stale) ときも赤くする。
+
+- `scan()` が拒否する字句 (バッククォート・ヒアドキュメント等) を含むファイルは、
+  生のソースに **`enum` の語が無ければ**母集団から外し、**あれば**
+  (直後の並びを問わず。コメントを挟む書き方・非 ASCII 識別子も見逃さない)
+  安全側に倒して `unresolvable` へ回す (取りこぼしを作らない側に倒す。実測では
+  ヒアドキュメントを持ちつつ docblock で「enum」に言及するだけの
+  `app/Mcp/Servers/AppMcpServer.php` がここで意図した過剰検出になる)。
+- **波括弧付き namespace 宣言** (`namespace Foo { … }`。無名・大文字小文字・
+  コメントの割り込みを問わない) の中は `enum` 宣言の波括弧の深さが 1 になり、
+  「深さ 0」の前提が崩れる。**個別の namespace 構文を正規表現で当てるのではなく**、
+  `detectEnumHeaders` が返す**深さ付きの enum 候補**を見て、**深さ 0 でない候補が
+  1 件でも混ざっていれば**安全側で `unresolvable` へ回す (深さ 0 の候補だけを拾って
+  残りを黙って捨てると、同じファイルの別の深さ 0 enum の影に隠れて消えてしまう)。
+
+### 抽出器が静かに間違えないことの裏取り
+
+- 前向きの受理・拒否の境界は `tests/js/architecture/enum-ts-sync-extractor.test.ts` の
+  負例行列 (TS 27 件 / PHP 40 件) が固定する。
+- 逆走査の抽出器・純関数の境界と故障注入の受け皿は
+  `tests/js/architecture/enum-ts-sync-discovery-extractor.test.ts` が持つ。
+- 見本の置き方は非対称である。TS は**ファイル** (型検査器に実ファイルが要る。
+  `tsconfig.json` の `exclude` で `pnpm typecheck` の対象から外す)、PHP は
+  **テスト内の文字列** (`.php` として置くと strict_types 宣言 gate / 禁止文の字句走査 /
+  Pint / PHPStan の母集団に入るため)。
+  **不正な TS / `.svelte` の入力は追跡ファイルにしない** — 母集団に入って本番の gate が
+  恒久的に赤くなるので、テストの中の文字列として渡す。
+  逆に `fixtures/` の正常な見本は**母集団に入る**ので、見本の値は現物の列挙と
+  交差しない綴り (`"zzz-…"`) にする。
+
+### 保証しないもの (誇張しない。前向き・発見の段・逆走査を含む)
 
 - **値の集合だけを見る**。表示ラベル・並び順・意味は見ない。
-- **部分集合の関係は表現できない** (完全一致だけ)。
-- `.svelte` の中の宣言・定数配列 (`as const` の配列)・`switch` の case ラベルは読まない。
+- **版管理外のファイル**(無視されたもの・未追跡のもの) は見ない。
+  `.js` / `.mjs` / `.cjs` は母集団に入れない。`.d.ts` は候補にしない。
+- `.svelte` は **script の中だけ**を見る (目印の中の式・制御構文の中・スタイルは見ない)。
+  ただしファイル全体が `parse` できることは前提である。
+- 候補にするのは「**すべての**要素が読める」形だけである
+  (1 つでも読めない要素があれば候補にしない)。
+- 派生として外した対応表は、**証人 (対応表以外の形の候補) がある場合だけ**外れる。
+  証人が無ければ候補として残る (fail-closed)。
+- **分岐のラベルと対応表のキーは登録できない**。写しなら型別名か定数の配列へ切り出す。
+- パッケージの型は**そのパッケージ自身の tsconfig** で解決する
+  (ルートの設定で解決するわけではない)。tsconfig を持たないパッケージは
+  どの program にも載らず、母集団の直和検査が赤くなる。
+- **除外根の中は見ない**。`fixtures/` の残りは**見る**ので、見本を書き換えると
+  本番の候補集合も動く (過剰検出の向きなので許容する)。
+- **`subset` の妥当性は機械では見分けられない** (完全一致の写しを `subset` と偽れば緩む)。
+  `subsetReason` の記述とレビューで担保する。
 - TS 側は**解決・正規化された後の型**で判断するので、ソース上の重複した union
   (`"a" | "a"`) や union の中の `never` は区別できない。**「同じ値が 2 回あると落ちる」とは
-  主張しない**。PHP 側の backing の値の重複だけは抽出器が明示的に落とす
-  (旧テストが配列比較で持っていた保証の引き継ぎ)。
+  主張しない**。PHP 側の backing の値の重複だけは抽出器が明示的に落とす。
 - PHP 側はファイル全体の構文の妥当性・名前空間・オートロード・完全修飾名を検証しない
   (それらは `composer test` と PHPStan の担当)。PHP が受理する構文をすべて受理する
   わけでもない (閉じタグ・バッククォート・ヒアドキュメントは拒否する)。
@@ -3189,15 +3309,8 @@ ### 保証しないもの (誇張しない。発見の段・逆走査を含む)
 - **レーンの非対称**: 値集合の同期は `pnpm test` (CI の frontend job) でだけ走る。
   PHP としての妥当性は backend job (`composer test` / PHPStan)。
   **`composer test` だけでは値集合の同期は検証されない**。
-- **逆走査は「登録漏れが無いことの証明」ではない**。名前も対応せず値も完全一致しない
-  drift 済みの写しは検出できない (2 規則それぞれの意図した限界)。
-- `collectTsUnionCandidates` は `resources/js/` 配下の `type X = …` という
-  トップレベル宣言だけを見る。`.svelte` の中の宣言・定数配列・switch の case ラベルは
-  逆走査の対象にもならない。**`.d.ts` (宣言ファイル) も対象外**である。
-  母集団は `tsconfig.json` の `include`/`exclude` が実際に決めるが、それだけを出典とは
-  言わない — `resources/js/` を直接歩いた `*.ts` (`.d.ts` を除く) の集合と program に
-  載った集合が完全一致することを独立実装の回帰テストで固定しており、目録に登録済みの
-  ファイルから import されるかどうかにも `tsconfig` の設定だけにも依存しない。
+- **逆走査は「登録漏れが無いことの証明」ではない**。名前も対応せず値も半分未満しか
+  交差しない drift 済みの写しは検出できない (規則それぞれの意図した限界)。
 
 ## キャッシュ素データ規約の 2 層 (T228 / 家系の裁定 AG-151 = 正典 v2)
 
diff --git a/docs/template-divergence.md b/docs/template-divergence.md
index 3c0b7727..773f1b07 100644
--- a/docs/template-divergence.md
+++ b/docs/template-divergence.md
@@ -8,7 +8,7 @@ # テンプレート差分レジストリ
 `template-divergence-ledger` が 2026-08-15 に確定した形) に従う。形式は
 `tests/Architecture/TemplateDivergenceLedgerFormatTest.php` が機械で強制する。
 
-登録エントリ: 50 件
+登録エントリ: 51 件
 
 ## 記録の原則
 
@@ -3098,3 +3098,62 @@ ### 関連
 - 実装: `tests/Support/RawEnv/` / `tests/Architecture/RawEnvDirectWriteGateTest.php`
 - 設計: `devnotes/20260824-1633-raw-env-snapshot-restore-v1/`
 - 関連する登録: D30 (`scripts/ci/pgsql_test_conn.php` の出自の記録) / D42 (契約文書のゲート索引)
+
+---
+
+## D54 前向きの同期検査を、単一ファイルの構文木方式ではなく共有の走査器 + 型情報方式で持つ
+
+| 行 | 内容 |
+|---|---|
+| 対象パス | `tests/js/architecture/enum-ts-sync.test.ts` |
+| 業務要件起因の説明 | 撮影 PWA と管理画面と付属のコマンドライン道具は、制作状態・カット種別・通知種別・API のエラー符号といったサーバ側の選択肢で分岐する。写しがずれると導線が無言で 1 本欠けるが、どちらの側も単体では整合しているので型検査でも通常のテストでも落ちない。テンプレートの前向き検査は単一ファイルに構文木だけで閉じており、別名参照・添字アクセス・閉じたテンプレート文字列・定数の配列を読めないため、写しを登録するには実装側の書き方を変えるよう強いることになる。本アプリは家系の機能台帳 `enum-ts-sync-gate` の正典 v3 (i4 / i5) へ追従し、共有の走査器と型情報 (Program + TypeChecker) で読む形にして、目録を逆走査の gate と共有する |
+| 揃え続ける不変条件と保証機構 | 目録 (`ENUM_TS_RELATIONS`) が前向きの検査と逆走査の単一の出典であること (両 gate が同じモジュールを読む) / 値集合の抽出器を 2 本持たないこと (`ts-literal-values.ts` の 1 本を前向きと逆走査が共有し、登録行の locator も逆走査と同じ採番器が作る) / 受理範囲の外は空集合ではなく例外にすること (`EnumTsSyncError`。空 vs 空で素通りさせない) / 正本のレーンは `pnpm test` であり `composer test` ではないこと (レーンの非対称を台帳から追える形にする) |
+| 再判定の条件 | 家系の機能台帳 `enum-ts-sync-gate` が v4 を確定したとき / テンプレート側が型情報方式を採用して還流できるようになったとき / TypeScript の Program API が型の解決結果の観測方法を変えたとき / 目録の置き場を `resources/js` と `packages/<名前>/src` 以外へ広げる必要が出たとき |
+| 決めた日 | 2026-08-24 |
+| 決めた人 | 開発者 |
+| 根拠 | devnotes/20260824-1633-enum-ts-sync-gate-v3/ |
+| 状態 | 恒久 |
+| 見直し期限 | — |
+
+| 観点 | テンプレート | 本アプリ |
+|---|---|---|
+| 前向きの検査の実体 | 単一ファイル (正典 v2 = 3,858 行) に構文木だけで閉じる | 220 行の gate + 支援モジュール群 (`tests/js/support/enum-ts-sync/`) |
+| 値の読み取り | 構文木のみ | 型検査器で解決した型 (別名参照・`keyof typeof`・閉じたテンプレート文字列を受理) + 配列は構文から読む |
+| 目録 | 前向きの検査が自分で持つ | `relation-inventory.ts` へ切り出し、逆走査の gate と共有する |
+| 関係 | 完全一致だけ | `equal` と `subset` の 2 値 (許す値域と、そこから選んだ集合は別概念) |
+| 登録できる置き場 | 画面側だけ | 画面側 (`resources/js/`) と付属の道具 (`packages/<名前>/src/`) |
+| program の作り方 | 単一 | パッケージごと (道具は自前の tsconfig で解決する) |
+
+### なぜ正当な差分か (logic-driven)
+
+1. **構文木だけでは本アプリの写しを読めない**。`resources/js/types/` の値域は
+   別名参照・`keyof typeof`・具体化された条件型で書かれており、構文木方式では
+   「登録できるように実装の書き方を変える」ことになる。検査の都合で本番のコードの
+   書き方を決めるのは順序が逆である。
+2. **目録を 2 つ持てない**。逆走査 (`enum-ts-sync-discovery.test.ts`) は
+   「どの宣言が登録済みか」を判定するのに同じ目録を読む。分けると
+   「片方だけ更新して食い違う」経路が生まれ、逆走査が登録済みの写しを
+   未登録として鳴らし続ける (または黙る)。
+3. **道具パッケージが境界の外に無い**。付属のコマンドライン道具はサーバの
+   エラー符号と OAuth スコープを写しとして持っており、実測でどちらもドリフトしていた。
+   画面側だけを見る検査では、この 2 件は永久に見えない。
+
+### 揃えている不変条件 (これは保証し続ける)
+
+> 「前向きの検査と逆走査は**同じ目録**と**同じ抽出器**と**同じ採番器**を使い、
+> 受理範囲の外は空集合ではなく例外になる」
+
+- 目録の単一性は、両 gate が `relation-inventory.ts` を読むことで構造的に保たれる
+- 抽出器の単一性は `ts-literal-values.ts` に集約してある (前向きの読み取りは共有抽出器が
+  返した値集合をそのまま返し、前向き固有の診断への翻訳だけを自分で行う)
+- 採番器の単一性は `buildScanIndex` に集約してある (登録行の locator と逆走査の候補の
+  locator が同じ採番空間に載る)
+- **機械が保証するのはここまでである**。「テンプレートより厳しい」ことは主張しない —
+  受理範囲・除外集合・保証しないものの正本は `docs/architecture.md`
+  §PHP 列挙と TypeScript 値域の同期 である
+
+### 関連
+
+- 実装: `tests/js/architecture/enum-ts-sync.test.ts` / `tests/js/support/enum-ts-sync/`
+- 設計: `devnotes/20260824-1633-enum-ts-sync-gate-v3/`
+- 関連する登録: D34 (採用時債務の凍結層。本登録で 1 行解消した)
diff --git a/tests/js/architecture/enum-ts-sync-discovery.test.ts b/tests/js/architecture/enum-ts-sync-discovery.test.ts
index d102ca93..ba3f8436 100644
--- a/tests/js/architecture/enum-ts-sync-discovery.test.ts
+++ b/tests/js/architecture/enum-ts-sync-discovery.test.ts
@@ -1,10 +1,9 @@
 /**
- * PHP の文字列付き列挙の発見の段と逆走査 (家系の裁定 AG-099 後半 / T225)。
+ * PHP の文字列付き列挙の発見の段と逆走査 (家系の機能台帳 `enum-ts-sync-gate` の正典 v3)。
  *
- * `enum-ts-sync.test.ts` は「目録 (`ENUM_TS_MIRRORS`) に登録した写しだけ」を見る検査で、
- * 登録し忘れた PHP 列挙・TS 宣言は 1 件も検査していなかった (`docs/template-divergence.md`
- * の D29 が記録していた欠落)。本ファイルは向きを変え、次の 2 段で「登録し忘れ」を
- * **既定拒否 (deny-by-default)** で炙り出す。
+ * `enum-ts-sync.test.ts` は「目録 (`ENUM_TS_RELATIONS`) に登録した関係だけ」を見る検査で、
+ * 登録し忘れた PHP 列挙・TS 宣言は 1 件も検査していなかった。本ファイルは向きを変え、
+ * 次の 2 段で「登録し忘れ」を**既定拒否 (deny-by-default)** で炙り出す。
  *
  * ## 1. 発見の段 (全数走査 → 既定拒否の分類)
  *
@@ -12,40 +11,40 @@
  * 値集合を読めた PHP の文字列付き列挙 (`resolved`) と、読めなかったもの (`unresolvable`)
  * に分ける。`resolved` の**すべて**が次のどちらか一方に分類されていることを固定する。
  *
- * - **登録済み** (`ENUM_TS_MIRRORS` に php パスがある)
- * - **対象外の理由つき** (`PHP_ENUM_EXEMPTIONS` に登録がある。TS 側に写しを作らない
+ * - **TS との関係を登録済み** (`ENUM_TS_RELATIONS` に php パスがある。
+ *   `equal` と `subset` の両方を含むので「写しを登録済み」とは呼ばない)
+ * - **対象外の理由つき** (`PHP_ENUM_EXEMPTIONS` に登録がある。TS 側に値域の写しを作らない
  *   意図的な判断で、理由を 30 文字以上で書く)
  *
  * `unresolvable` の**すべて**が `KNOWN_UNRESOLVABLE_PHP_ENUMS` に登録されていることを
  * 固定する (本 gate 専用の字句走査器では値集合を読み切れないと分かっている残余)。
  *
- * どの分類にも入らない PHP 列挙が 1 件でもあれば赤くする (**既定拒否**)。
- * 逆に、分類の登録先が実際にはその分類でなくなった (stale) ときも赤くする
- * (登録が実態と食い違ったまま残るのを防ぐ)。
+ * ## 2. 逆走査 (未登録候補の検出。母集団の全数 → 4 形の候補 → 3 規則)
  *
- * ## 2. 逆走査 (未登録候補の検出。2 規則)
+ * - **母集団 (i8)**: 版管理下の `*.ts` と `*.svelte` の**全数**
+ *   (`population.ts`。唯一の除外は検出器自身の構文破壊見本 1 ディレクトリ)
+ * - **候補の形 (i9)**: リテラル型の合併 / 定数の配列 / 対応表のキー / 分岐のラベルの 4 種
+ * - **規則**: 完全一致 (規則 1) / 厳密名対応 + 1 値交差 (規則 2a) /
+ *   語分割名対応 + 両側半分以上の交差 (規則 2b)。**規則 2 は 2a と 2b の論理和**である
  *
- * `collectTsUnionCandidates()` が `resources/js/` 配下の文字列リテラル型だけの union に
- * 解決する型別名を全数走査し、`findUnregisteredMirrorCandidates()` が
- * 未登録 (`ENUM_TS_MIRRORS` に無い) の宣言を PHP の母集団と突き合わせて次の 2 規則で拾う。
- *
- * - **規則 1 (完全一致)**: 値集合が PHP 列挙と完全一致する未登録の宣言 = 登録漏れの疑い
- * - **規則 2 (名前対応 + 値の交差)**: 名前が厳密に対応し値が交差するが完全一致ではない
- *   未登録の宣言 = 片方だけ値を足してズレた写しの疑い
- *
- * 見つかった候補は `REVERSE_SWEEP_EXEMPTIONS` に登録された分だけ許す
- * (意図的に登録しない判断を明示する)。未登録の候補が 1 件でもあれば赤くする。
+ * 見つかった候補は `REVERSE_SWEEP_EXEMPTIONS` に登録された分だけ許す。
+ * 候補かどうかを決められなかった宣言 (判定保留) は `KNOWN_INDETERMINATE_TS_DECLARATIONS`
+ * に登録された分だけ許す (どちらも既定拒否)。
  *
  * **保証しないもの (誇張しない)**:
- * - 名前も対応せず値も完全一致しない drift 済みの写しは検出できない (規則の意図した限界)
- * - 緩い名前対応 (部分集合・ファイル名を名前に混ぜる形) は採らない。実測 (家系の記録) で
- *   偽陽性が支配的になるため、名前対応は「一致 / +s / +es / +values」の厳密な形だけを見る
- * - `.svelte` の中の宣言・定数配列・switch の case ラベルは走査しない
- *   (`collectTsUnionCandidates` は `type X = …` のトップレベル宣言だけを見る。
- *   `.d.ts` も対象外)
+ * - 版管理外のファイル (無視されたもの・未追跡のもの) は見ない。
+ *   `.js` / `.mjs` / `.cjs` は母集団に入れない。`.d.ts` は候補にしない
+ * - `.svelte` は script の中だけを見る (目印の中・制御構文の中・スタイルは見ない)。
+ *   ただし**ファイル全体が `parse` できることは前提**である
+ * - 「すべての要素が読める」形だけを候補にする (1 つでも読めない要素があれば候補にしない)
+ * - 派生として外した対応表は、**証人 (対応表以外の形の候補) がある場合だけ**外れる
+ * - 分岐のラベルと対応表のキーは**登録できない**。写しなら型別名か定数の配列へ切り出す
+ * - パッケージの型は**そのパッケージ自身の tsconfig** で解決する
+ *   (ルートの設定で解決するわけではない)
+ * - 除外根 (`fixtures/candidates-broken`) の中は見ない。
+ *   `fixtures/` の残りは**見る** (見本を書き換えると本番の候補集合も動く)
+ * - 名前も対応せず値も半分未満しか交差しない drift 済みの写しは検出できない (規則の限界)
  * - PHP 側の母集団は `php-enum-catalog.ts` の docblock が明記する範囲に限る
- *   (走査器が読み切れない字句を含むファイルは、生のソースに `enum` の語が
- *   無ければ母集団から外れる。あれば安全側に倒して `unresolvable` へ回る)
  *
  * 正本のレーンは `pnpm test`。詳細は `docs/architecture.md`
  * §PHP 列挙と TypeScript 値域の同期。
@@ -53,11 +52,35 @@
 import { beforeAll, describe, expect, it } from "vitest";
 import fs from "node:fs";
 import path from "node:path";
-import { createMirrorProgram, REPO_ROOT, type MirrorProgram } from "../support/enum-ts-sync/program";
+import {
+    createMirrorPrograms,
+    findExcludedSurvivors,
+    REPO_ROOT,
+    type MirrorPrograms,
+} from "../support/enum-ts-sync/program";
+import {
+    EXCLUDED_ROOTS,
+    EXPECTED_EXCLUDED_ROOT_COUNT,
+    listExcludedFiles,
+    validateExcludedRoots,
+} from "../support/enum-ts-sync/population";
 import { buildPhpEnumCatalog, type PhpEnumCatalog } from "../support/enum-ts-sync/php-enum-catalog";
-import { collectTsUnionCandidates, type TsUnionCandidate } from "../support/enum-ts-sync/ts-candidates";
-import { findUnregisteredMirrorCandidates } from "../support/enum-ts-sync/reverse-sweep";
-import { ENUM_TS_MIRRORS, registeredPhpPaths, registeredTsKeys } from "../support/enum-ts-sync/mirror-inventory";
+import {
+    collectTsCandidates,
+    locatorKey,
+    type TsCandidateLocator,
+    type TsCandidateScan,
+    type TsCandidateShape,
+} from "../support/enum-ts-sync/ts-candidates";
+import {
+    auditReverseSweepExemptions,
+    findUnregisteredMirrorCandidates,
+    type ReverseSweepResult,
+    type ReverseSweepRule,
+    type UnregisteredMirrorCandidate,
+} from "../support/enum-ts-sync/reverse-sweep";
+import { ENUM_TS_RELATIONS, declaredPhpPaths, validateRelations } from "../support/enum-ts-sync/relation-inventory";
+import { resolveRelations } from "../support/enum-ts-sync/ts-value-sets";
 
 interface PhpEnumExemption {
     /** リポジトリルートからの PHP 列挙ファイルの相対パス。 */
@@ -68,7 +91,7 @@ interface PhpEnumExemption {
 
 /**
  * 「対象外の理由つき」に分類する PHP の文字列付き列挙。
- * ここに無く、かつ `ENUM_TS_MIRRORS` にも無い `resolved` エントリが 1 件でもあれば
+ * ここに無く、かつ `ENUM_TS_RELATIONS` にも無い `resolved` エントリが 1 件でもあれば
  * 発見の段が赤くなる (既定拒否)。
  */
 const PHP_ENUM_EXEMPTIONS = [
@@ -82,8 +105,7 @@ const PHP_ENUM_EXEMPTIONS = [
     { path: "app/DataTransferObjects/Manual/Render/RenderClipSource.php", reason: "レンダーパイプライン内部でクリップの取得元を表す区分。フロントは個別のフラグで結果を受け取り、この値そのものは渡らない" },
     { path: "app/Enums/Account/AccountDeletionFreezeAllowance.php", reason: "退会凍結中に許可する route 名相当の内部許可リスト。ガード判定にのみ使い、画面には表示しない" },
     { path: "app/Enums/AccountDeletionBlockReason.php", reason: "退会ブロックの内部理由コード。画面には理由ごとの案内文をサーバ側で確定して渡すだけである" },
-    { path: "app/Enums/ApiErrorCode.php", reason: "公開 API のエラーコード語彙。TS 側はコードで分岐せず HTTP 状態とエラー文言だけを見る" },
-    { path: "app/Enums/ApiKeyAbility.php", reason: "API キー権限 (read/write) の内部語彙。管理画面はチェックボックスの選択状態だけを見る" },
+    { path: "app/Enums/ApiKeyAbility.php", reason: "API キー権限 (read/write)。画面はチェックボックスの選択状態で操作し、表示ラベル表は未知の値を素の文字列へ退避するため値域の写しを要さない" },
     { path: "app/Enums/Auth/AuthMethodChangeEvent.php", reason: "認証手段変更メール通知の内部分類 (T110)。件名・本文はサーバ側で確定して送るだけで画面へは一切渡らない" },
     { path: "app/Enums/Auth/EmailVerificationGateContext.php", reason: "メール確認ゲートの発生元コンテキスト。内部のルーティング判定にのみ使う語彙である" },
     { path: "app/Enums/Billing/AutoRechargeAttemptStatus.php", reason: "自動追加購入試行の内部状態機械。画面は結果の通知種別 (BillingFeedbackKind) 経由でしか見ない" },
@@ -127,8 +149,7 @@ const PHP_ENUM_EXEMPTIONS = [
     { path: "app/Enums/Manual/LlmOutputInvalidReason.php", reason: "LLM 出力不正の内部理由。画面には再試行可否の結果だけが渡る" },
     { path: "app/Enums/Manual/ShotType.php", reason: "ショット種別 (hiki/yori) の内部語彙。台本表示は文言化済みの値を受け取るだけである" },
     { path: "app/Enums/Mcp/ToolName.php", reason: "MCP ツール名の内部登録名。Web UI からは呼ばれない CLI/MCP 専用の語彙である" },
-    { path: "app/Enums/OAuth/CliOAuthScope.php", reason: "CLI OAuth スコープの内部語彙。認可判定にのみ使い画面へは出ない" },
-    { path: "app/Enums/OAuth/OAuthClientKind.php", reason: "OAuth クライアント種別の内部判定。認可ロジックの内部でのみ使う" },
+    { path: "app/Enums/OAuth/OAuthClientKind.php", reason: "OAuth クライアント種別。認可判定の内部語彙で、画面の表示ラベル表は未知の値を素の文字列へ退避するため値域の写しを要さない" },
     { path: "app/Enums/Organization/SlugReservationReason.php", reason: "組織識別名の予約理由の 3 分類 (家系裁定 AG-039)。設定ファイルの読み込み検査とレビューのための語彙で、画面には拒否の文言だけが渡る" },
     { path: "app/Enums/ProjectRole.php", reason: "プロジェクトロールの内部判定。画面は権限の有無を真偽値として受け取るだけである" },
     { path: "app/Enums/ProviderCapability.php", reason: "認証プロバイダの能力分類の内部語彙。認可ロジックの内部でのみ使う" },
@@ -170,7 +191,7 @@ const PHP_ENUM_EXEMPTIONS = [
 ] as const satisfies readonly PhpEnumExemption[];
 
 /** `PHP_ENUM_EXEMPTIONS` の件数の pin。増えても減っても赤くする。 */
-const EXPECTED_EXEMPTION_COUNT = 95;
+const EXPECTED_EXEMPTION_COUNT = 93;
 
 interface UnresolvablePhpEnumEntry {
     readonly path: string;
@@ -198,59 +219,199 @@ const KNOWN_UNRESOLVABLE_PHP_ENUMS = [
 
 const EXPECTED_UNRESOLVABLE_COUNT = 3;
 
+
 interface ReverseSweepExemption {
     /** 一致した PHP 列挙のパス。 */
     readonly php: string;
-    /** 未登録の TS 宣言のファイル。 */
-    readonly file: string;
-    /** 未登録の TS 宣言の名前。 */
-    readonly declaration: string;
-    readonly rule: 1 | 2;
+    /** 未登録の TS 宣言の locator (置き場・形・名前・出現順の 4 つ組)。 */
+    readonly locator: TsCandidateLocator;
+    /** 適用された規則。**規則が移ると申告は stale になる**。 */
+    readonly rule: ReverseSweepRule;
     /** 登録しない理由 (30 文字以上)。 */
     readonly reason: string;
 }
 
+const locator = (file: string, shape: TsCandidateShape, name: string, occurrence = 0): TsCandidateLocator => ({
+    file,
+    shape,
+    name,
+    occurrence,
+});
+
 /**
  * 逆走査が見つける候補のうち、意図的に登録しないものの一覧。
- * `(php, file, declaration, rule)` の組が完全一致したものだけを免除する
+ * `(php, locator, rule)` が完全一致したものだけを免除する
  * (php パスまで固定するので、たまたま同じ値集合を持つ**別の** PHP 列挙が現れたときは
- * 新しい候補として検出され続ける)。
+ * 新しい候補として検出され続ける。`occurrence` まで固定するので、同名の入れ子の宣言が
+ * 前に足されると申告が stale になり赤くなる = 人が見直す合図である)。
  */
 const REVERSE_SWEEP_EXEMPTIONS = [
     {
         php: "app/Enums/Manual/TakeStatus.php",
-        file: "resources/js/types/manual.ts",
-        declaration: "SelectableTakeStatus",
-        rule: 1,
+        locator: locator("resources/js/types/manual.ts", "literal-union", "SelectableTakeStatus"),
+        rule: "1",
         reason: "「選択できるテイクの状態」という部分集合の意図の宣言。今は TakeStatus と値が完全一致するが、意図は部分集合なので登録しない",
     },
+    {
+        php: "app/Enums/Manual/CutType.php",
+        locator: locator(
+            "resources/js/components/features/manual/ScenarioEditor.svelte",
+            "literal-union",
+            "DragOwner",
+        ),
+        rule: "1",
+        reason: "台本編集のドラッグの所有者 (カット / 素材) という別概念で、値がたまたまカット種別と一致しているだけである。似ているからで統合しない (思考原則 4)",
+    },
+    {
+        php: "app/Enums/Notification/NotificationType.php",
+        locator: locator(
+            "resources/js/components/features/notifications/NotificationListItem.svelte",
+            "switch-cases",
+            "switch:notification.type",
+        ),
+        rule: "1",
+        reason: "通知の絵柄を選ぶ分岐。既定の枝があるので、種別が増えると新種の通知は汎用のベルの絵柄で出る (操作は詰まらない)。期待動作は「新種を足すときに絵柄も足す」であり、値が増えれば完全一致が崩れて本申告が stale になり赤くなる",
+    },
+    {
+        php: "app/Enums/ApiKeyAbility.php",
+        locator: locator("resources/js/pages/Organizations/ApiKeys/Index.svelte", "object-keys", "ABILITY_LABELS"),
+        rule: "1",
+        reason: "API キー権限の表示ラベル表。未知の値は素の文字列で表示する退避 (?? ability) があるので、値の取りこぼしが画面を壊さない。値域の写しではない",
+    },
+    {
+        php: "app/Enums/OAuth/OAuthClientKind.php",
+        locator: locator("resources/js/pages/Organizations/ApiKeys/Sessions.svelte", "object-keys", "CLIENT_KIND_LABELS"),
+        rule: "1",
+        reason: "OAuth クライアント種別の表示ラベル表。未知の値は素の文字列で表示する退避 (?? kind) があるので、値の取りこぼしが画面を壊さない。値域の写しではない",
+    },
+    {
+        php: "app/Enums/EnterpriseSso/OidcConnectionStatus.php",
+        locator: locator("tests/js/components/features/sso/oidc-connection.test.ts", "const-array", "ALL_STATUSES"),
+        rule: "1",
+        reason: "検査が全値を並べた入力であって画面の写しではない。目録の置き場は resources/js と packages/<name>/src に限るので、そもそも登録できない",
+    },
+    {
+        php: "app/Enums/Manual/JobStatus.php",
+        locator: locator("resources/js/types/dashboard.ts", "literal-union", "DashboardJobStatus"),
+        rule: "2b",
+        reason: "ダッシュボードが出す「進行中のジョブ」だけを表す意図した真部分集合である。終端の状態はダッシュボードに出ないので値域の写しにしない",
+    },
+    {
+        php: "app/Enums/ApiErrorCode.php",
+        locator: locator("packages/cli/src/api/schemas.ts", "literal-union", "ApiErrorCode"),
+        rule: "2a",
+        reason: "サーバの符号 (API_ERROR_CODES) と正規でない面固有の符号 (NON_CANONICAL_API_ERROR_CODES) の合併型である。写しの実体は API_ERROR_CODES として relation equal で登録済みで、合併型そのものは写しではない",
+    },
 ] as const satisfies readonly ReverseSweepExemption[];
 
-const EXPECTED_REVERSE_SWEEP_EXEMPTION_COUNT = 1;
+const EXPECTED_REVERSE_SWEEP_EXEMPTION_COUNT = 8;
+
+interface IndeterminateTsEntry {
+    readonly locator: TsCandidateLocator;
+    /** 判定保留のまま残す理由 (30 文字以上)。 */
+    readonly reason: string;
+}
+
+/**
+ * 候補かどうかを**決められなかった** TS 宣言 (判定保留) の申告。
+ * PHP 側の `KNOWN_UNRESOLVABLE_PHP_ENUMS` と同じ形の既定拒否の受け皿である
+ * (判定保留を非候補と混ぜないための当て所。共通規約 (b))。
+ */
+const KNOWN_INDETERMINATE_TS_DECLARATIONS = [
+    {
+        locator: locator("tests/js/support/enum-ts-sync/fixtures/t22-circular.ts", "literal-union", "X"),
+        reason: "型別名が自分自身を経由して循環する見本。型検査器が解決できないことを固定するために置いてある負の対照である",
+    },
+    {
+        locator: locator("tests/js/support/enum-ts-sync/fixtures/t22-circular.ts", "literal-union", "Y"),
+        reason: "同上 (循環の相方)。型検査器が解決できないことを固定するために置いてある負の対照である",
+    },
+    {
+        locator: locator("tests/js/support/enum-ts-sync/fixtures/t23-unresolved-import.ts", "literal-union", "X"),
+        reason: "実在しないモジュールからの取り込みに依存する見本。解決できないことを固定するために置いてある負の対照である",
+    },
+    {
+        locator: locator(
+            "tests/js/support/enum-ts-sync/fixtures/candidates/mixed.ts",
+            "literal-union",
+            "IndirectAnyCandidate",
+        ),
+        reason: "別名越しに明示の any へ解決する見本。構文が any の綴りでないので「正常な非候補」と区別できないことを固定する負の対照である",
+    },
+    {
+        locator: locator(
+            "tests/js/support/enum-ts-sync/fixtures/candidates/mixed.ts",
+            "object-keys",
+            "ObjectAnyComputedKeyCandidate",
+        ),
+        reason: "計算キーの型が any へ解決する対応表の見本。判定保留を非候補と混ぜないことを固定するために置いてある負の対照である",
+    },
+    {
+        locator: locator(
+            "tests/js/support/enum-ts-sync/fixtures/candidates/staged-occurrence.ts",
+            "literal-union",
+            "StagedShadow",
+        ),
+        reason: "採番が三値をまたぐことの見本 (判定保留が先・候補が後)。同名の候補が occurrence 1 になり、本申告が候補側へ効かないことを固定するために置いてある",
+    },
+] as const satisfies readonly IndeterminateTsEntry[];
 
-const reverseSweepKey = (php: string, file: string, declaration: string, rule: number): string =>
-    `${php}|${file}|${declaration}|${rule}`;
+const EXPECTED_INDETERMINATE_TS_COUNT = 6;
 
 let catalog: PhpEnumCatalog | undefined;
-let mirrorProgram: MirrorProgram | undefined;
-let tsCandidates: readonly TsUnionCandidate[] | undefined;
+let programs: MirrorPrograms | undefined;
+let scan: TsCandidateScan | undefined;
+let sweep: ReverseSweepResult | undefined;
 
 const requireCatalog = (): PhpEnumCatalog => {
     if (catalog === undefined) throw new Error("catalog が初期化されていません");
     return catalog;
 };
-
-const requireTsCandidates = (): readonly TsUnionCandidate[] => {
-    if (tsCandidates === undefined) throw new Error("tsCandidates が初期化されていません");
-    return tsCandidates;
+const requirePrograms = (): MirrorPrograms => {
+    if (programs === undefined) throw new Error("programs が初期化されていません");
+    return programs;
+};
+const requireScan = (): TsCandidateScan => {
+    if (scan === undefined) throw new Error("scan が初期化されていません");
+    return scan;
+};
+const requireSweep = (): ReverseSweepResult => {
+    if (sweep === undefined) throw new Error("sweep が初期化されていません");
+    return sweep;
 };
 
 beforeAll(() => {
+    validateRelations(ENUM_TS_RELATIONS);
     catalog = buildPhpEnumCatalog();
-    mirrorProgram = createMirrorProgram([...new Set(ENUM_TS_MIRRORS.map((m) => m.ts))]);
-    tsCandidates = collectTsUnionCandidates(mirrorProgram);
+    programs = createMirrorPrograms();
+    scan = collectTsCandidates(programs);
+    // 登録済みの判定は locator の完全一致で行う (前向きの解決と同じ採番器の出力を使う)。
+    const declared = new Set(
+        resolveRelations(programs, ENUM_TS_RELATIONS).map((row) => locatorKey(row.tsLocator)),
+    );
+    sweep = findUnregisteredMirrorCandidates(catalog.resolved, scan.candidates, (row) =>
+        declared.has(locatorKey(row)),
+    );
 }, 300_000);
 
+/** 失敗メッセージ (i13。PHP 側と TS 側の**両方の位置**を出す)。 */
+const describeHit = (hit: UnregisteredMirrorCandidate): string =>
+    [
+        `規則${hit.rule} ${hit.php.path}:${hit.php.line} (${hit.php.name})`,
+        `     ⇔ ${hit.candidate.locator.file}:${hit.candidate.line}::${hit.candidate.locator.name} (${hit.candidate.locator.shape} #${hit.candidate.locator.occurrence})`,
+        `     ${hit.reason}`,
+        `     PHP にだけある値: ${hit.onlyInPhp.join(", ")}`,
+        `     TS にだけある値: ${hit.onlyInTs.join(", ")}`,
+    ].join("\n");
+
+const HOW_TO_FIX = [
+    "直し方:",
+    "  - TS が PHP の値域そのものの写しなら ENUM_TS_RELATIONS へ relation:\"equal\" で 1 行足し、EXPECTED_RELATION_COUNT を 1 増やす",
+    "  - TS が PHP の値域から選んだ非空の集合なら relation:\"subset\" と subsetReason (30 文字以上) を付けて登録する",
+    "  - どちらでもないなら REVERSE_SWEEP_EXEMPTIONS へ理由 30 文字以上で登録し EXPECTED_REVERSE_SWEEP_EXEMPTION_COUNT を直す",
+    "  - 登録できるのは型別名か const の配列である。対応表のキーと分岐のラベルは、いったん型別名か const の配列へ切り出す",
+].join("\n");
+
 describe("PHP 文字列付き列挙の発見の段 (全数走査・既定拒否の分類)", () => {
     it("走査が空振りしていない (母集団が空でない)", () => {
         const { resolved, unresolvable } = requireCatalog();
@@ -275,8 +436,8 @@ describe("PHP 文字列付き列挙の発見の段 (全数走査・既定拒否
         }
     });
 
-    it("resolved はすべて『登録済み』か『対象外の理由つき』のどちらか一方に分類される", () => {
-        const registered = registeredPhpPaths();
+    it("resolved はすべて『TS との関係を登録済み』か『対象外の理由つき』のどちらか一方に分類される", () => {
+        const registered = declaredPhpPaths();
         const exempt = new Set<string>(PHP_ENUM_EXEMPTIONS.map((e) => e.path));
 
         const unclassified: string[] = [];
@@ -293,7 +454,7 @@ describe("PHP 文字列付き列挙の発見の段 (全数走査・既定拒否
     });
 
     it("exemption の登録先が stale になっていない (今も resolved かつ未登録のままである)", () => {
-        const registered = registeredPhpPaths();
+        const registered = declaredPhpPaths();
         const resolvedPaths = new Set(requireCatalog().resolved.map((r) => r.path));
 
         const stale = PHP_ENUM_EXEMPTIONS.filter(
@@ -337,34 +498,119 @@ describe("PHP 文字列付き列挙の発見の段 (全数走査・既定拒否
     });
 });
 
-describe("PHP ⇔ TS 値域の逆走査 (未登録候補の検出)", () => {
-    it("TS 側の候補走査が空振りしていない (母集団が空でない)", () => {
-        expect(requireTsCandidates().length).toBeGreaterThan(0);
+describe("逆走査の母集団 (版管理下の全数・唯一の除外)", () => {
+    it("除外根の件数が pin と一致する", () => {
+        expect(EXCLUDED_ROOTS).toHaveLength(EXPECTED_EXCLUDED_ROOT_COUNT);
     });
 
-    it("逆走査で見つかる候補は REVERSE_SWEEP_EXEMPTIONS に登録された分だけである", () => {
-        const registered = registeredTsKeys();
-        const found = findUnregisteredMirrorCandidates(
-            requireCatalog().resolved,
-            requireTsCandidates(),
-            (file, name) => registered.has(`${file}::${name}`),
-        );
+    it("除外根の体裁 (配下・実在・重複無し・理由 30 文字以上) が守られている", () => {
+        expect(() => validateExcludedRoots()).not.toThrow();
+    });
+
+    it("除外根の配下は 0 件でなく、全ファイルが実際に本番と同じ入口で落ちる", () => {
+        const files = listExcludedFiles();
+        expect(files.length).toBeGreaterThan(0);
+
+        // 判定の本体は `findExcludedSurvivors()` が持つ (拡張子ごとに本番と同じ入口を使う)。
+        // ここが「除外根へ正常なファイルを置いて母集団から静かに消す」経路を塞ぐ。
+        const survivors = findExcludedSurvivors(files);
+        expect(
+            survivors,
+            `除外根の配下に本番の入口で落ちないファイルがあります (母集団から静かに消える経路です。除外根から出すこと):\n${survivors.join("\n")}`,
+        ).toEqual([]);
+    });
+
+    it("母集団が空でない (.ts と .svelte のどちらも)", () => {
+        const { population } = requirePrograms();
+        expect(population.ts.length).toBeGreaterThan(0);
+        expect(population.svelte.length).toBeGreaterThan(0);
+        expect(requireScan().scannedFiles.size).toBe(population.ts.length + population.svelte.length);
+    });
+
+    it("母集団の全件がちょうど 1 本の program に載っている (過不足の両方を見る)", () => {
+        const { byOwner, population } = requirePrograms();
+        const owners = [...byOwner.values()];
 
-        const exemptKeys = new Set(
-            REVERSE_SWEEP_EXEMPTIONS.map((e) => reverseSweepKey(e.php, e.file, e.declaration, e.rule)),
-        );
+        const missing: string[] = [];
+        const duplicated: string[] = [];
+        for (const file of [...population.ts, ...population.svelte]) {
+            const carriers = owners.filter((mirror) => mirror.rootRelatives.has(file));
+            if (carriers.length === 0) missing.push(file);
+            if (carriers.length > 1) duplicated.push(`${file} (${carriers.map((c) => c.owner).join(", ")})`);
+        }
+
+        expect(missing, `どの program の起点にも載っていない母集団のファイル:\n${missing.join("\n")}`).toEqual([]);
+        expect(duplicated, `2 本以上の program の起点に載っている母集団のファイル:\n${duplicated.join("\n")}`).toEqual([]);
+    });
+});
 
-        const unexempted = found.filter(
-            (f) => !exemptKeys.has(reverseSweepKey(f.php.path, f.candidate.file, f.candidate.name, f.rule)),
-        );
+describe("TS 側の判定保留 (既定拒否の受け皿)", () => {
+    it("登録の件数が pin と一致し、実在・重複無し・reason が 30 文字以上", () => {
+        expect(KNOWN_INDETERMINATE_TS_DECLARATIONS).toHaveLength(EXPECTED_INDETERMINATE_TS_COUNT);
+
+        const seen = new Set<string>();
+        for (const entry of KNOWN_INDETERMINATE_TS_DECLARATIONS) {
+            expect(fs.existsSync(path.join(REPO_ROOT, entry.locator.file))).toBe(true);
+            const key = locatorKey(entry.locator);
+            expect(seen.has(key)).toBe(false);
+            seen.add(key);
+            expect(entry.reason.length).toBeGreaterThanOrEqual(30);
+        }
+    });
+
+    it("indeterminate はすべて KNOWN_INDETERMINATE_TS_DECLARATIONS に登録されている", () => {
+        const known = new Set(KNOWN_INDETERMINATE_TS_DECLARATIONS.map((e) => locatorKey(e.locator)));
+        const unknown = requireScan().indeterminate.filter((row) => !known.has(locatorKey(row.locator)));
 
         expect(
-            unexempted,
-            `未登録のミラー候補が見つかりました (登録するか REVERSE_SWEEP_EXEMPTIONS へ理由付きで登録すること):\n${unexempted
-                .map((f) => `規則${f.rule} ${f.php.path} <-> ${f.candidate.file}::${f.candidate.name}${f.nameMatch !== null ? ` (${f.nameMatch})` : ""}`)
+            unknown,
+            `未登録の判定保留の TS 宣言 (実装を直して解消するか KNOWN_INDETERMINATE_TS_DECLARATIONS へ理由付きで登録すること):\n${unknown
+                .map((row) => `${row.locator.file}:${row.line}::${row.locator.name} (${row.locator.shape}) ${row.reason}`)
+                .join("\n")}`,
+        ).toEqual([]);
+    });
+
+    it("登録先が stale になっていない (今も判定保留のままである)", () => {
+        const actual = new Set(requireScan().indeterminate.map((row) => locatorKey(row.locator)));
+        const stale = KNOWN_INDETERMINATE_TS_DECLARATIONS.filter((e) => !actual.has(locatorKey(e.locator)));
+
+        expect(
+            stale,
+            `KNOWN_INDETERMINATE_TS_DECLARATIONS の登録が実態と食い違っている (削除するか登録し直すこと):\n${stale
+                .map((e) => locatorKey(e.locator))
                 .join("\n")}`,
         ).toEqual([]);
     });
+});
+
+describe("PHP ⇔ TS 値域の逆走査 (未登録候補の検出)", () => {
+    it("TS 側の候補走査が空振りしていない (候補が空でない)", () => {
+        expect(requireScan().candidates.length).toBeGreaterThan(0);
+    });
+
+    it("判定不能な組は 0 件である (名前を決められないのに列挙と交差する候補は無い)", () => {
+        const { undecidable } = requireSweep();
+        expect(
+            undecidable,
+            `規則 2 を判定できない組があります (判定対象の名前を解決できる形へ直すこと):\n${undecidable
+                .map(
+                    (row) =>
+                        `${row.php.path}:${row.php.line} <-> ${row.candidate.locator.file}:${row.candidate.line}::${row.candidate.locator.name} (交差 ${row.intersectionSize} 値)`,
+                )
+                .join("\n")}`,
+        ).toEqual([]);
+    });
+
+    it("逆走査で見つかる候補は REVERSE_SWEEP_EXEMPTIONS に登録された分だけである", () => {
+        const { unexempted } = auditReverseSweepExemptions(requireSweep().found, REVERSE_SWEEP_EXEMPTIONS);
+
+        expect(
+            unexempted,
+            `未登録の PHP・TS 関係の候補が見つかりました。正本は PHP 側です。\n${unexempted
+                .map(describeHit)
+                .join("\n")}\n${HOW_TO_FIX}`,
+        ).toEqual([]);
+    });
 
     it("REVERSE_SWEEP_EXEMPTIONS の件数が pin と一致し、登録先が実在・重複無し・reason が 30 文字以上", () => {
         expect(REVERSE_SWEEP_EXEMPTIONS).toHaveLength(EXPECTED_REVERSE_SWEEP_EXEMPTION_COUNT);
@@ -372,30 +618,52 @@ describe("PHP ⇔ TS 値域の逆走査 (未登録候補の検出)", () => {
         const seen = new Set<string>();
         for (const entry of REVERSE_SWEEP_EXEMPTIONS) {
             expect(fs.existsSync(path.join(REPO_ROOT, entry.php))).toBe(true);
-            expect(fs.existsSync(path.join(REPO_ROOT, entry.file))).toBe(true);
-            const key = reverseSweepKey(entry.php, entry.file, entry.declaration, entry.rule);
+            expect(fs.existsSync(path.join(REPO_ROOT, entry.locator.file))).toBe(true);
+            const key = `${entry.php}|${locatorKey(entry.locator)}|${entry.rule}`;
             expect(seen.has(key)).toBe(false);
             seen.add(key);
             expect(entry.reason.length).toBeGreaterThanOrEqual(30);
         }
     });
 
+    it("失敗メッセージに PHP 側と TS 側の両方の位置が出る (i13)", () => {
+        // 実際に鳴る組が 0 件でも診断文の形は固定する
+        // (収集した情報が判定と診断に使われていることを保証する。共通規約 (d))。
+        const message = describeHit({
+            rule: "2a",
+            php: { path: "app/Enums/ApiErrorCode.php", name: "ApiErrorCode", line: 13, values: new Set(["a"]) },
+            candidate: {
+                locator: locator("packages/cli/src/api/schemas.ts", "literal-union", "ApiErrorCode"),
+                line: 327,
+                topLevel: true,
+                values: new Set(["b"]),
+                correspondenceName: "ApiErrorCode",
+                nameResolved: true,
+            },
+            reason: "厳密名対応 (apierrorcode = apierrorcode) / 交差 1 値",
+            onlyInPhp: ["a"],
+            onlyInTs: ["b"],
+        });
+
+        expect(message).toContain("app/Enums/ApiErrorCode.php:13");
+        expect(message).toContain("packages/cli/src/api/schemas.ts:327::ApiErrorCode");
+        expect(message).toContain("literal-union #0");
+        expect(message).toContain("PHP にだけある値: a");
+        expect(message).toContain("TS にだけある値: b");
+        expect(HOW_TO_FIX).toContain("ENUM_TS_RELATIONS");
+        expect(HOW_TO_FIX).toContain("REVERSE_SWEEP_EXEMPTIONS");
+    });
+
     it("REVERSE_SWEEP_EXEMPTIONS の登録先が stale になっていない (今も候補として検出され続けている)", () => {
-        const registered = registeredTsKeys();
-        const found = findUnregisteredMirrorCandidates(
-            requireCatalog().resolved,
-            requireTsCandidates(),
-            (file, name) => registered.has(`${file}::${name}`),
-        );
-        const foundKeys = new Set(found.map((f) => reverseSweepKey(f.php.path, f.candidate.file, f.candidate.name, f.rule)));
-
-        const stale = REVERSE_SWEEP_EXEMPTIONS.filter(
-            (e) => !foundKeys.has(reverseSweepKey(e.php, e.file, e.declaration, e.rule)),
-        );
+        // 生死の判定は**免除を適用する前**の候補集合に対して行う
+        // (免除適用後で判定すると、申告が自分自身を根拠にして永久に生き続ける)。
+        const { stale } = auditReverseSweepExemptions(requireSweep().found, REVERSE_SWEEP_EXEMPTIONS);
 
         expect(
             stale,
-            `REVERSE_SWEEP_EXEMPTIONS の登録が実態と食い違っている (削除するか登録し直すこと):\n${stale.map((e) => `${e.php} <-> ${e.file}::${e.declaration}`).join("\n")}`,
+            `REVERSE_SWEEP_EXEMPTIONS の登録が実態と食い違っている (削除するか登録し直すこと):\n${stale
+                .map((e) => `${e.php} <-> ${locatorKey(e.locator)} 規則${e.rule}`)
+                .join("\n")}`,
         ).toEqual([]);
     });
 });
diff --git a/tests/js/architecture/enum-ts-sync-extractor.test.ts b/tests/js/architecture/enum-ts-sync-extractor.test.ts
index adf9f4ef..da0de32f 100644
--- a/tests/js/architecture/enum-ts-sync-extractor.test.ts
+++ b/tests/js/architecture/enum-ts-sync-extractor.test.ts
@@ -22,8 +22,9 @@ import { EnumTsSyncError } from "../support/enum-ts-sync/errors";
 import {
     REPO_ROOT,
     createFixtureProgram,
-    createMirrorProgram,
+    createMirrorPrograms,
     type MirrorProgram,
+    type MirrorPrograms,
 } from "../support/enum-ts-sync/program";
 import { readTsUnionValues } from "../support/enum-ts-sync/ts-value-sets";
 import { readPhpEnumValues, readPhpEnumValuesFromText } from "../support/enum-ts-sync/php-enums";
@@ -67,9 +68,11 @@ const TS_CASES: readonly TsCase[] = [
     { id: "T4", file: "t04-open-string.ts", declaration: "X", accepts: undefined, reason: "文字列リテラル型でない" },
     { id: "T5", file: "t05-number-member.ts", declaration: "X", accepts: undefined, reason: "文字列リテラル型でない" },
     { id: "T6", file: "t06-never.ts", declaration: "X", accepts: undefined, reason: "文字列リテラル型でない" },
-    { id: "T7", file: "t07-absent.ts", declaration: "X", accepts: undefined, reason: "型別名の宣言が見つかりません" },
-    { id: "T8", file: "t08-duplicate-alias.ts", declaration: "X", accepts: undefined, reason: "同名の型別名が 2 件あります" },
-    { id: "T9", file: "t09-const-array.ts", declaration: "X", accepts: undefined, reason: "型別名の宣言が見つかりません" },
+    { id: "T7", file: "t07-absent.ts", declaration: "X", accepts: undefined, reason: "受理できる宣言が見つかりません" },
+    { id: "T8", file: "t08-duplicate-alias.ts", declaration: "X", accepts: undefined, reason: "同名の受理できる宣言が 2 件あります" },
+    // T9 は**意味を更新した**行である (削除ではない)。受理する形が 2 つ (型別名 / const の配列)
+    // へ広がったので、`const X = ["a"] as const;` は拒否から受理へ移った。
+    { id: "T9", file: "t09-const-array.ts", declaration: "X", accepts: ["a"] },
     { id: "T10a", file: "t10a-target.ts", declaration: "X", accepts: ["c", "y1", "y2"] },
     { id: "T10b", file: "t10b-path-alias.ts", declaration: "X", accepts: ["editor", "extra", "shooter", "viewer"] },
     { id: "T11", file: "t11-indexed-access.ts", declaration: "X", accepts: ["p", "q"] },
@@ -83,8 +86,11 @@ const TS_CASES: readonly TsCase[] = [
     { id: "T19", file: "t19-string-enum.ts", declaration: "X", accepts: undefined, reason: "TypeScript の enum の値は受理しません" },
     { id: "T20", file: "t20-numeric-enum.ts", declaration: "X", accepts: undefined, reason: "TypeScript の enum の値は受理しません" },
     { id: "T21", file: "t21-unique-symbol.ts", declaration: "X", accepts: undefined, reason: "文字列リテラル型でない" },
-    { id: "T22", file: "t22-circular.ts", declaration: "X", accepts: undefined, reason: "文字列リテラル型でない" },
-    { id: "T23", file: "t23-unresolved-import.ts", declaration: "X", accepts: undefined, reason: "文字列リテラル型でない" },
+    // T22 / T23 も**意味を更新した**行である (削除ではない)。値の読み取りを共有抽出器の
+    // 1 本へ集約した結果、「解決したが文字列リテラル型でない」と「そもそも解決できない
+    // (判定保留)」が分かれた。どちらも拒否だが、理由の言葉が変わる。
+    { id: "T22", file: "t22-circular.ts", declaration: "X", accepts: undefined, reason: "any / unknown へ解決しました" },
+    { id: "T23", file: "t23-unresolved-import.ts", declaration: "X", accepts: undefined, reason: "any / unknown へ解決しました" },
     { id: "T24", file: "t24-source-duplicate.ts", declaration: "X", accepts: ["a"] },
     { id: "T25a", file: "t25-target.ts", declaration: "X", accepts: ["a", "b"], program: "full" },
     // T25b: 起点だけの program では拡張が載らないので値が減る (起点を縮める改変の回帰)。
@@ -103,12 +109,12 @@ const TS_AUXILIARY_FIXTURES: readonly string[] = ["t10a-other.ts"];
 /** `program-fixtures/` に置く補助 (tsconfig の対象に残す)。 */
 const PROGRAM_FIXTURES: readonly string[] = ["registry-base.ts", "registry-augmentation.ts"];
 
-let fullProgram: MirrorProgram | undefined;
+let fullPrograms: MirrorPrograms | undefined;
 let narrowProgram: MirrorProgram | undefined;
 
-const requireFullProgram = (): MirrorProgram => {
-    if (fullProgram === undefined) throw new EnumTsSyncError("fixture full program", "初期化されていません");
-    return fullProgram;
+const requireFullPrograms = (): MirrorPrograms => {
+    if (fullPrograms === undefined) throw new EnumTsSyncError("fixture full programs", "初期化されていません");
+    return fullPrograms;
 };
 const requireNarrowProgram = (): MirrorProgram => {
     if (narrowProgram === undefined) throw new EnumTsSyncError("fixture narrow program", "初期化されていません");
@@ -117,8 +123,8 @@ const requireNarrowProgram = (): MirrorProgram => {
 
 describe("TS 側抽出器の負例行列", () => {
     beforeAll(() => {
-        // 見本は tsconfig から除外してあるので、全体 program にも起点として明示的に足す。
-        fullProgram = createMirrorProgram(TS_CASES.map((c) => fixture(c.file)));
+        // 見本は tsconfig から除外してあるが、版管理下なので母集団 (= 起点) には入る。
+        fullPrograms = createMirrorPrograms();
         // 起点を縮めた program は「縮めた行が指す見本だけ」を起点にする。
         narrowProgram = createFixtureProgram(
             TS_CASES.filter((c) => c.program === "narrow").map((c) => path.join(FIXTURE_DIR, c.file)),
@@ -149,7 +155,10 @@ describe("TS 側抽出器の負例行列", () => {
     });
 
     it.each(TS_CASES)("$id: $file::$declaration", (testCase) => {
-        const mirrorProgram = testCase.program === "narrow" ? requireNarrowProgram() : requireFullProgram();
+        const mirrorProgram =
+            testCase.program === "narrow"
+                ? requireNarrowProgram()
+                : requireFullPrograms().programOf(fixture(testCase.file));
         const read = (): ReadonlySet<string> =>
             readTsUnionValues(mirrorProgram, fixture(testCase.file), testCase.declaration);
 
```

## 現在の実測

```
programs=<root>,packages/cli
population .ts=389 .svelte=132 / scanned=521
candidates=394 {const-array:64, object-keys:195, literal-union:120, switch-cases:15}
indeterminate=6 / undecidable=0
hits=8 {規則1:6, 規則2a:1, 規則2b:1}  ← 申告 8 件と 1:1
```

件数 pin: EXPECTED_RELATION_COUNT=31 / EXPECTED_EXEMPTION_COUNT=93 /
EXPECTED_UNRESOLVABLE_COUNT=3 / EXPECTED_REVERSE_SWEEP_EXEMPTION_COUNT=8 /
EXPECTED_INDETERMINATE_TS_COUNT=6 / EXPECTED_EXCLUDED_ROOT_COUNT=1 /
LedgerPins: DIVERGENCE_ENTRY_COUNT=51 (D54 新設) / ADOPTION_DEBT_COUNT=145

## テスト結果 (Round 2 時点)

- enum-ts-sync 系 4 ファイル: 286 tests passed
- pnpm typecheck / lint / typecheck:packages / build:packages: green
- composer phpstan (level 10): No errors / vendor/bin/pint --test: passed
- TemplateDivergenceFingerprintTest / TemplateDivergenceLedgerFormatTest: 17/17 passed
- 故障注入は Round 1 の 16 件に C1 / C2 / C3 を足した 19 件すべてで赤を実測
  (指摘 #1 / #2 / #5 は「直した後に元へ戻すと赤くなる」ことを実測した)
- `composer test` のクリーンなフル実行は本レビューと並行して再実行中 (結果は最終報告に載せる)
