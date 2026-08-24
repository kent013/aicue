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

/**
 * 所属だけを決める純関数。**tsconfig の有無で決めない**
 * (絞ると、自前の設定を持たないパッケージのファイルが黙って `<root>` の型世界へ落ちる)。
 */
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
            `所有者 ${owner} の program がありません (自前の tsconfig.json を持たないパッケージです。ルートの設定で読むと本番と異なる型世界で解析することになり、候補が静かに消える恐れがあるので、扱いを決めてから走らせること)`,
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
            // 読み込みは try の外で行う (I/O の失敗を「期待した構文不正」として
            // 吸収しない。捕捉するのは `toVirtualUnit()` の拒否だけである)。
            const source = fs.readFileSync(absolute, "utf-8");
            try {
                toVirtualUnit(file, source);
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
