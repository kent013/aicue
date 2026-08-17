/**
 * 型情報の入口 (TypeScript の program と型検査器を作る)。
 *
 * **本番の gate は `tsconfig.json` が含む TS ファイル全体で program を作る**。
 * 目録のファイルだけを起点にすると、`include` だけで参加する宣言 (周囲宣言 `.d.ts` /
 * `declare global` / モジュールの拡張) が program に載らず、**本番の型と違う型世界**で
 * 判定してしまう。本リポジトリには実際に `resources/js/lib/recaptcha.ts` の
 * `declare global` があり、この経路は絵空事ではない。偽陰性 (取り残しを緑にする) に
 * なるので、速さのために起点を縮める判断はしない。
 */
import ts from "typescript";
import fs from "node:fs";
import path from "node:path";
import { fileURLToPath } from "node:url";
import { EnumTsSyncError } from "./errors";

/** リポジトリのルート (tests/js/support/enum-ts-sync から 4 つ上)。 */
export const REPO_ROOT = path.resolve(path.dirname(fileURLToPath(import.meta.url)), "../../../..");

export interface MirrorProgram {
    readonly program: ts.Program;
    readonly checker: ts.TypeChecker;
}

const formatHost: ts.FormatDiagnosticsHost = {
    getCanonicalFileName: (fileName) => fileName,
    getCurrentDirectory: () => REPO_ROOT,
    getNewLine: () => "\n",
};

/** tsconfig.json を読む。回復可能な診断も含めて 1 件でもあれば例外にする。 */
const parseRepoTsconfig = (): ts.ParsedCommandLine => {
    const configPath = path.join(REPO_ROOT, "tsconfig.json");
    const host: ts.ParseConfigFileHost = {
        useCaseSensitiveFileNames: ts.sys.useCaseSensitiveFileNames,
        readDirectory: ts.sys.readDirectory,
        fileExists: ts.sys.fileExists,
        readFile: ts.sys.readFile,
        getCurrentDirectory: () => REPO_ROOT,
        onUnRecoverableConfigFileDiagnostic: (d) => {
            throw new EnumTsSyncError("tsconfig.json", ts.flattenDiagnosticMessageText(d.messageText, " "));
        },
    };
    const parsed = ts.getParsedCommandLineOfConfigFile(configPath, {}, host);
    if (parsed === undefined) throw new EnumTsSyncError("tsconfig.json", "読み込みに失敗しました");
    if (parsed.errors.length > 0) {
        throw new EnumTsSyncError("tsconfig.json", ts.formatDiagnostics(parsed.errors, formatHost));
    }
    if (parsed.fileNames.length === 0) {
        throw new EnumTsSyncError("tsconfig.json", "対象ファイルが 0 件です (gate が空振りしている)");
    }
    return parsed;
};

const buildProgram = (rootNames: readonly string[], parsed: ts.ParsedCommandLine): MirrorProgram => {
    const program = ts.createProgram({
        rootNames: [...rootNames],
        options: { ...parsed.options, noEmit: true },
        projectReferences: parsed.projectReferences,
        configFileParsingDiagnostics: parsed.errors,
    });
    const optionsDiagnostics = program.getOptionsDiagnostics();
    if (optionsDiagnostics.length > 0) {
        throw new EnumTsSyncError("tsconfig.json", ts.formatDiagnostics(optionsDiagnostics, formatHost));
    }
    return { program, checker: program.getTypeChecker() };
};

/**
 * 目録が指す TS ファイルを含む program を作る。
 * 起点は **tsconfig が含む全ファイル ∪ 目録のファイル**。
 *
 * @param tsFiles リポジトリルートからの相対パス
 */
export const createMirrorProgram = (tsFiles: readonly string[]): MirrorProgram => {
    const parsed = parseRepoTsconfig();
    const inventoryRoots = tsFiles.map((file) => {
        const absolute = path.join(REPO_ROOT, file);
        if (!fs.existsSync(absolute)) {
            throw new EnumTsSyncError(file, "目録が指す TS ファイルが実在しません");
        }
        return absolute;
    });
    return buildProgram([...new Set([...parsed.fileNames, ...inventoryRoots])], parsed);
};

/**
 * 見本 (fixture) 専用の**起点を縮めた** program。**本番の gate では使わない**。
 * リポジトリの `compilerOptions` (`paths` を含む) はそのまま使い、起点だけを明示する。
 *
 * @param absoluteFiles 絶対パス
 */
export const createFixtureProgram = (absoluteFiles: readonly string[]): MirrorProgram => {
    const parsed = parseRepoTsconfig();
    for (const absolute of absoluteFiles) {
        if (!fs.existsSync(absolute)) throw new EnumTsSyncError(absolute, "見本ファイルが実在しません");
    }
    return buildProgram(absoluteFiles, parsed);
};
