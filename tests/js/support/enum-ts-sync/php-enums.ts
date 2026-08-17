/**
 * PHP 側の値集合を読む (本 gate 専用の最小の字句走査)。
 *
 * **保証するのは次の 1 点だけである**:
 * > 抽出の対象として認識した enum 宣言・case 宣言・禁止した字句状態については、
 * > 受理するか、理由付きの例外になる。
 *
 * PHP ファイル全体の構文の妥当性・名前空間・オートロード・完全修飾名の正しさ・
 * メソッド本体の妥当性は**検証しない** (それらは `composer test` / PHPStan の担当)。
 * また PHP が受理する構文をすべて受理するわけではない (閉じタグ・バッククォート・
 * ヒアドキュメントは拒否する)。正本は `docs/architecture.md`
 * §PHP 列挙と TypeScript 値域の同期。
 */
import fs from "node:fs";
import path from "node:path";
import { EnumTsSyncError } from "./errors";
import { REPO_ROOT } from "./program";

/** 字句の状態。 */
const CODE = 0;
const SINGLE = 1;
const DOUBLE = 2;
const LINE = 3;
const BLOCK = 4;

interface ScanResult {
    /** 元と**同じ長さ** (UTF-16 の符号単位数) の無害化した写し。 */
    readonly sanitized: string;
    /** その位置がコード状態か。 */
    readonly isCode: Uint8Array;
    /** その位置の波括弧の深さ (閉じ波括弧は外側の深さになる)。 */
    readonly depth: Int32Array;
}

/**
 * 文字列・注釈を空白へ潰しつつ、波括弧の深さを数える。
 *
 * - **引用符の終端は逆斜線の偶奇で決まる**。「直前が `\` でない」では不十分で、
 *   `'…\\'` の終端を文字列の中と誤認すると以降の case を丸ごと食い潰す。
 *   逆斜線を見たら次の 1 文字ごと飛ばす形で偶奇を自然に実装する。
 * - 改行は `\r` も `\n` もそのまま残す (CRLF で位置がずれない)。
 * - 走査は `charCodeAt` ではなく添字の 1 進みで回す (符号位置単位で回すと
 *   補助面の文字で位置がずれる)。
 */
const scan = (source: string, where: string): ScanResult => {
    const length = source.length;
    const out: string[] = new Array<string>(length);
    const isCode = new Uint8Array(length);
    const depth = new Int32Array(length);

    let state: number = CODE;
    let braceDepth = 0;
    let index = 0;

    /** 中身を潰す (改行だけは残す)。 */
    const blank = (at: number): void => {
        const ch = source[at];
        out[at] = ch === "\n" || ch === "\r" ? ch : " ";
    };

    while (index < length) {
        const ch = source[index];
        const next = index + 1 < length ? source[index + 1] : "";

        if (state === CODE) {
            if (ch === "`") {
                throw new EnumTsSyncError(where, "バッククォート文字列は受理しません");
            }
            if (ch === "?" && next === ">") {
                throw new EnumTsSyncError(where, "PHP の閉じタグ (?>) は受理しません");
            }
            if (ch === "<" && next === "<" && source[index + 2] === "<") {
                throw new EnumTsSyncError(where, "ヒアドキュメント / ナウドキュメント (<<<) は受理しません");
            }
            if (ch === "_" && source.slice(index, index + 15).toLowerCase() === "__halt_compiler") {
                throw new EnumTsSyncError(where, "__halt_compiler は受理しません");
            }

            if (ch === "/" && next === "/") {
                depth[index] = braceDepth;
                depth[index + 1] = braceDepth;
                out[index] = " ";
                out[index + 1] = " ";
                state = LINE;
                index += 2;
                continue;
            }
            // `#[` は属性なのでコードのまま。それ以外の `#` は行注釈。
            if (ch === "#" && next !== "[") {
                depth[index] = braceDepth;
                out[index] = " ";
                state = LINE;
                index += 1;
                continue;
            }
            if (ch === "/" && next === "*") {
                depth[index] = braceDepth;
                depth[index + 1] = braceDepth;
                out[index] = " ";
                out[index + 1] = " ";
                state = BLOCK;
                index += 2;
                continue;
            }

            isCode[index] = 1;
            if (ch === "{") {
                depth[index] = braceDepth;
                braceDepth += 1;
            } else if (ch === "}") {
                braceDepth -= 1;
                if (braceDepth < 0) throw new EnumTsSyncError(where, "波括弧の対応が壊れています");
                depth[index] = braceDepth;
            } else {
                depth[index] = braceDepth;
            }
            out[index] = ch;
            if (ch === "'") state = SINGLE;
            else if (ch === '"') state = DOUBLE;
            index += 1;
            continue;
        }

        if (state === SINGLE || state === DOUBLE) {
            depth[index] = braceDepth;
            if (ch === "\\") {
                blank(index);
                if (index + 1 < length) {
                    depth[index + 1] = braceDepth;
                    blank(index + 1);
                }
                index += 2;
                continue;
            }
            if ((state === SINGLE && ch === "'") || (state === DOUBLE && ch === '"')) {
                isCode[index] = 1;
                out[index] = ch;
                state = CODE;
                index += 1;
                continue;
            }
            blank(index);
            index += 1;
            continue;
        }

        if (state === LINE) {
            depth[index] = braceDepth;
            // PHP は**行注釈の中の `?>` でも PHP モードを抜ける**ので見逃さない。
            if (ch === "?" && next === ">") {
                throw new EnumTsSyncError(where, "PHP の閉じタグ (?>) は受理しません");
            }
            if (ch === "\n" || ch === "\r") {
                isCode[index] = 1;
                out[index] = ch;
                state = CODE;
                index += 1;
                continue;
            }
            blank(index);
            index += 1;
            continue;
        }

        // BLOCK
        depth[index] = braceDepth;
        if (ch === "*" && next === "/") {
            depth[index + 1] = braceDepth;
            out[index] = " ";
            out[index + 1] = " ";
            state = CODE;
            index += 2;
            continue;
        }
        blank(index);
        index += 1;
        continue;
    }

    if (state === SINGLE) throw new EnumTsSyncError(where, "単一引用符の文字列が閉じていません");
    if (state === DOUBLE) throw new EnumTsSyncError(where, "二重引用符の文字列が閉じていません");
    if (state === BLOCK) throw new EnumTsSyncError(where, "ブロック注釈が閉じていません");

    return { sanitized: out.join(""), isCode, depth };
};

/**
 * 深さ 0 のコード状態に現れる `enum` の位置 / 深さ 1 の `case` の位置。
 * `lastIndex` を持つので**呼び出しごとに作る** (共有すると走査位置が持ち越される)。
 */
const enumTokenRe = (): RegExp => /(?<![\w$\\])enum(?=[\s])/gi;
const caseTokenRe = (): RegExp => /(?<![\w$\\])case(?![\w])/gi;
/** `enum <名前>[: <backing>]` の頭。 */
const ENUM_HEADER = /^enum\s+([A-Za-z_\u0080-\uFFFF][A-Za-z0-9_\u0080-\uFFFF]*)(?:\s*:\s*([A-Za-z_\\][A-Za-z0-9_\\]*))?/i;
/**
 * 受理する case の書き方 (単一引用符)。
 * **値の中に改行を許さない** (`[^'\\\r\n]`)。許すと `case A = 'a<改行>b';` が
 * 1 件の宣言として受理され、TS 側を `"a\nb"` にすれば gate 全体が緑になってしまう
 * (Codex 実装レビュー Round 1 の Critical)。
 */
const CASE_SINGLE = /^case[ \t]+([A-Za-z_][A-Za-z0-9_]*)[ \t]*=[ \t]*'([^'\\\r\n]*)'[ \t]*;$/i;
/** 受理する case の書き方 (二重引用符。変数の埋め込みを拒むため `$` も除く)。 */
const CASE_DOUBLE = /^case[ \t]+([A-Za-z_][A-Za-z0-9_]*)[ \t]*=[ \t]*"([^"\\$\r\n]*)"[ \t]*;$/i;

/**
 * PHP の文字列付き列挙の値集合を読む (本体)。
 *
 * @param source   PHP のソース
 * @param fileName 語幹の照合と例外の文面にだけ使うファイル名
 */
export const readPhpEnumValuesFromText = (source: string, fileName: string): ReadonlySet<string> => {
    const where = fileName;
    const base = path.basename(fileName);
    if (!base.endsWith(".php")) {
        throw new EnumTsSyncError(where, "ファイル名の拡張子が .php ではありません");
    }
    const stem = base.slice(0, -".php".length);

    const { sanitized, isCode, depth } = scan(source, where);

    // 1. 深さ 0 の enum 宣言がちょうど 1 つ
    const enumOffsets: number[] = [];
    const enumToken = enumTokenRe();
    for (let m = enumToken.exec(sanitized); m !== null; m = enumToken.exec(sanitized)) {
        if (isCode[m.index] === 1 && depth[m.index] === 0) enumOffsets.push(m.index);
    }
    if (enumOffsets.length === 0) throw new EnumTsSyncError(where, "enum 宣言が見つかりません");
    if (enumOffsets.length > 1) {
        throw new EnumTsSyncError(where, `enum 宣言が ${enumOffsets.length} 件あります`);
    }

    const headerOffset = enumOffsets[0];
    const header = ENUM_HEADER.exec(sanitized.slice(headerOffset));
    if (header === null) throw new EnumTsSyncError(where, "enum 宣言の頭を読めません");
    const enumName = header[1];
    const backing = header[2];
    if (backing === undefined) {
        throw new EnumTsSyncError(where, "backing 型がありません (string 付きの列挙だけを受理します)");
    }
    if (backing.toLowerCase() !== "string") {
        throw new EnumTsSyncError(where, `backing 型が string ではありません: ${backing}`);
    }

    // 2. PSR-4 の前提の裏取り (ファイル名の語幹と一致すること)
    if (enumName !== stem) {
        throw new EnumTsSyncError(where, `ファイル名の語幹 (${stem}) と enum 名 (${enumName}) が食い違います`);
    }

    // 3. 本体の範囲を取る
    let bodyStart = -1;
    for (let i = headerOffset + header[0].length; i < sanitized.length; i += 1) {
        if (isCode[i] === 1 && sanitized[i] === "{") {
            bodyStart = i;
            break;
        }
    }
    if (bodyStart < 0) throw new EnumTsSyncError(where, "enum の本体が見つかりません");

    let bodyEnd = -1;
    for (let i = bodyStart + 1; i < sanitized.length; i += 1) {
        if (isCode[i] === 1 && sanitized[i] === "}" && depth[i] === 0) {
            bodyEnd = i;
            break;
        }
    }
    if (bodyEnd < 0) throw new EnumTsSyncError(where, "enum の本体が閉じていません");

    // 4. 深さ 1 の case を 1 件ずつ受理する
    const names = new Set<string>();
    const values = new Set<string>();
    const caseToken = caseTokenRe();
    for (let m = caseToken.exec(sanitized); m !== null; m = caseToken.exec(sanitized)) {
        const at = m.index;
        if (at < bodyStart || at > bodyEnd) continue;
        if (isCode[at] !== 1 || depth[at] !== 1) continue;

        let semicolon = -1;
        for (let i = at; i < bodyEnd; i += 1) {
            if (isCode[i] === 1 && sanitized[i] === ";") {
                semicolon = i;
                break;
            }
        }
        if (semicolon < 0) throw new EnumTsSyncError(where, "case 宣言の終端 (;) が見つかりません");

        // **元の本文**を照合する (無害化した写しではない)
        const declaration = source.slice(at, semicolon + 1);
        // 改行を含む宣言は先に落とす (受理する書き方は 1 行に閉じたものだけである)。
        if (/[\r\n]/.test(declaration)) {
            throw new EnumTsSyncError(
                where,
                `受理する書き方に一致しません (改行を含む case は受理しません): ${JSON.stringify(declaration)}`,
            );
        }
        const matched = CASE_SINGLE.exec(declaration) ?? CASE_DOUBLE.exec(declaration);
        if (matched === null) {
            throw new EnumTsSyncError(where, `受理する書き方に一致しません: ${JSON.stringify(declaration)}`);
        }
        const caseName = matched[1];
        const caseValue = matched[2];
        if (names.has(caseName)) throw new EnumTsSyncError(where, `case の名前が重複しています: ${caseName}`);
        // 旧テストは配列同士を比べていて値の重複を検出できた。集合にすると消えるので
        // **抽出器の側で明示的に落として**保証を引き継ぐ。
        if (values.has(caseValue)) throw new EnumTsSyncError(where, `backing の値が重複しています: ${caseValue}`);
        names.add(caseName);
        values.add(caseValue);
    }

    if (values.size === 0) throw new EnumTsSyncError(where, "case を 1 件も取り出せません");

    return values;
};

/** リポジトリ相対のパスから読む薄い包み。 */
export const readPhpEnumValues = (phpFile: string): ReadonlySet<string> => {
    const absolute = path.join(REPO_ROOT, phpFile);
    if (!fs.existsSync(absolute)) {
        throw new EnumTsSyncError(phpFile, "PHP ファイルが実在しません");
    }
    // 例外の文面には**リポジトリ相対のパス**を載せる (語幹の照合は中で basename を取る)。
    return readPhpEnumValuesFromText(fs.readFileSync(absolute, "utf-8"), phpFile);
};
