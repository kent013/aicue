import fs from "fs/promises";
import path from "path";
import { parse } from "svelte/compiler";

/**
 * `.svelte` から native な file input と、その `accept` 属性の**実測**を集める走査器。
 *
 * # 走査対象
 *
 * - `scanFileInputs(root)`: `root` 配下 (再帰) の拡張子 `.svelte` のファイル全数。
 * - `scanSources(sources)`: 与えられた合成ソース全数 (自己検査用。実ファイルに依存しない)。
 *
 * 母集団は「native `input` を作りうる形の全数」で、AST 上の扱いは次のとおり
 * (svelte 5.56 で実測した形に基づく):
 *
 * | AST 上の形 | 扱い |
 * |---|---|
 * | `RegularElement` / name が `input` (大文字小文字を無視) | 母集団 (`type` 判定へ) |
 * | `RegularElement` / name が `input` 以外 | 対象外 |
 * | `SvelteElement` / `tag` が文字列 `Literal` で値が `input` (同上) | 母集団 |
 * | `SvelteElement` / `tag` が文字列 `Literal` で値が `input` 以外 | 対象外 (静的に非 input と確定) |
 * | `SvelteElement` / `tag` が `Literal` 以外、または非文字列 `Literal` | 診断 `unresolved-native-element` |
 * | `HtmlTag` (`{@html …}`) | `rawHtml` として実測 (診断ではない。免除目録と突き合わせる) |
 * | component (`<Foo />` / `<svelte:component>`) | 対象外 |
 *
 * `type` / `accept` の実測は「静的テキストだけで確定できるか」で分け、確定できない形は
 * すべて診断にする (**未解決を無言で候補から外さない** = fail-closed)。
 *
 * # 保証しないもの (誇張しない)
 *
 * - **`.svelte` 以外**には効かない。TS から `document.createElement('input')` する経路、
 *   Blade テンプレート、実行時に `accept` を書き換える形は見えない。
 * - **識別子の値の由来は追跡しない**。`accept={x}` を見ても `x` がサーバ由来かは分からない
 *   (Inertia props は実行時に注入されるため静的検査の到達範囲外)。
 * - **`{@html …}` に渡される文字列の中身は解析しない**。生 HTML の中に file input を
 *   書けるかどうかは分からないため、免除目録の登録は「そこに file input を作らない」という
 *   人の宣言であり、走査器が確かめた結果ではない。
 * - `occurrence` (序数) は**出現順**であって意味の追跡ではない。並べ替えると値がずれるが、
 *   ずれれば赤くなる (安全側)。
 * - 属性の重複は 2 通りに分かれる。**綴りが同じ重複**は svelte の parse が拒否するので
 *   `parse-failed` として現れる。**大小文字違いの重複**は parse を通るので
 *   (svelte の重複検査は大小文字を区別する) 走査器が `unresolved-type` /
 *   `unresolved-accept` へ落とす。どちらも母集団からは外れない (fail-closed)。
 * - `svelte/compiler` の AST 形状は major 更新で変わりうる。変われば自己検査
 *   (`tests/js/architecture/file-input-scan.test.ts`) の合成入力が最初に落ちる
 *   (無言で緑にはならない)。
 *
 * 検出力の裏取り (負例・正例の両方向) は `tests/js/architecture/file-input-scan.test.ts`。
 */

/** 走査に渡す 1 ファイル分のソース。 */
export interface SvelteSource {
    /** 走査根からの相対パス (POSIX 区切り)。目録の鍵になる。 */
    readonly file: string;
    readonly source: string;
}

/** 実測できた file input の 1 件。`occurrence` はファイル内の 1 始まりの序数。 */
export interface FileInputRecord {
    readonly file: string;
    readonly occurrence: number;
    readonly syntax: "static-text" | "expression";
    /** `static-text` のときだけ値。`expression` は null。 */
    readonly literal: string | null;
}

export type ScanDiagnosticReason =
    /** ファイル単位。parse そのものが失敗した。 */
    | "parse-failed"
    /** `type` が式・真偽短縮・複数パートで、file かどうか確定できない。 */
    | "unresolved-type"
    /** 同一要素の spread 属性が `type` / `accept` を上書きしうる。 */
    | "spread-attribute"
    /** file input なのに `accept` が無い。 */
    | "missing-accept"
    /** `accept` が真偽短縮などで値を確定できない。 */
    | "unresolved-accept"
    /** `<svelte:element this={…}>` が実行時に input になりうる。 */
    | "unresolved-native-element";

/** ソース上の位置 (行は 1 始まり、列は 0 始まり)。 */
export interface SourcePosition {
    readonly line: number;
    readonly column: number;
}

/**
 * 生 HTML の描画 (`{@html …}`) の実測 1 件。**診断とは別の集合**である
 * (突き合わせる免除目録が別で、鍵も別: 生 HTML は `file` + `occurrence`、
 * 診断は `file` + `reason` + 件数)。
 */
export interface RawHtmlRecord {
    readonly file: string;
    readonly occurrence: number;
    readonly at: SourcePosition;
}

export interface ScanDiagnostic {
    readonly file: string;
    readonly reason: ScanDiagnosticReason;
    /** `parse-failed` は null (ファイル単位のため位置を持たない)。 */
    readonly at: SourcePosition | null;
    readonly detail: string;
}

export interface FileInputScanResult {
    /** 走査したファイル数 (走査根が生きていることの確認用)。 */
    readonly svelteFileCount: number;
    /** native input 要素の全数 (母集団非空 その 1)。 */
    readonly nativeInputCount: number;
    /** 静的に file と確定し accept を実測できた input (母集団非空 その 2)。 */
    readonly fileInputs: readonly FileInputRecord[];
    /**
     * 未解決の形。判定側の既定は**無条件で違反**で、免除目録と突き合わせるのは
     * 免除できる理由に限られる (現在は `spread-attribute` だけ。正本は
     * `./file-input-accept-inventory.ts` の `ExemptibleDiagnosticReason`)。
     */
    readonly diagnostics: readonly ScanDiagnostic[];
    /** 生 HTML の実測。判定側で免除目録と両方向で突き合わせる。 */
    readonly rawHtml: readonly RawHtmlRecord[];
}

/** AST ノードの最低限の形 (走査器が触る範囲だけを型で表す)。 */
interface AstNode {
    readonly type: string;
    readonly start?: number;
    readonly name?: string;
    readonly attributes?: readonly AstNode[];
    readonly tag?: { readonly type: string; readonly value?: unknown };
    readonly value?: unknown;
    readonly data?: string;
    readonly [key: string]: unknown;
}

const isAstNode = (value: unknown): value is AstNode =>
    typeof value === "object" && value !== null && typeof (value as { type?: unknown }).type === "string";

/** バイト offset を 1 始まりの行 / 0 始まりの列へ変換する。 */
function positionAt(source: string, offset: number): SourcePosition {
    const before = source.slice(0, offset);
    const lineBreaks = before.split("\n");

    return { line: lineBreaks.length, column: lineBreaks[lineBreaks.length - 1].length };
}

/** ノードを再帰的に列挙する (テンプレートと式の区別をせず全走査し、type で振り分ける)。 */
function eachNode(value: unknown, visit: (node: AstNode) => void): void {
    if (Array.isArray(value)) {
        for (const item of value) eachNode(item, visit);

        return;
    }
    if (typeof value !== "object" || value === null) return;
    if (isAstNode(value)) visit(value);
    for (const [key, child] of Object.entries(value)) {
        // 位置情報と親参照は走査しない (循環と無駄打ちの回避)
        if (key === "type" || key === "parent" || key === "loc" || key === "name_loc") continue;
        eachNode(child, visit);
    }
}

/** 属性値を「静的テキストだけで確定できるか」で分類する。 */
type AttributeValue =
    | { readonly kind: "static"; readonly text: string }
    | { readonly kind: "expression" }
    | { readonly kind: "unresolved"; readonly detail: string };

function classifyAttributeValue(value: unknown): AttributeValue {
    // 短縮の真偽属性 (`<input type />`) は値を持たない
    if (value === true) return { kind: "unresolved", detail: "属性が真偽短縮で値を持たない" };

    const parts = Array.isArray(value) ? value : [value];
    const nodes: AstNode[] = [];
    for (const part of parts) {
        if (!isAstNode(part)) {
            return { kind: "unresolved", detail: "属性値の AST を解決できない" };
        }
        nodes.push(part);
    }
    if (nodes.every((node) => node.type === "Text")) {
        return { kind: "static", text: nodes.map((node) => node.data ?? "").join("") };
    }
    if (nodes.some((node) => node.type === "ExpressionTag")) return { kind: "expression" };

    return { kind: "unresolved", detail: `属性値に未知のノード (${nodes.map((n) => n.type).join(",")})` };
}

/**
 * 名前付き属性を集める。**native HTML の属性名は ASCII 大文字小文字を区別しない**ため、
 * 要素名・`type` の値と同じく小文字化して照合する (区別すると `TYPE="file"` が
 * 「type 属性なし」として母集団から無言で外れる = fail-open)。
 *
 * 綴りが同じ重複 (`type="file" type="text"`) は svelte の parse 自体が拒否するが、
 * **svelte の重複検査は大小文字を区別する**ため大小文字違いの重複 (`type` と `TYPE`) は
 * parse を通る (実測)。そのため**複数件返りうる**。先頭だけ採って後続を捨てると
 * fail-open になるので、複数件は呼び出し側が診断へ落とす。
 */
function attributesNamed(node: AstNode, name: string): AstNode[] {
    return (node.attributes ?? []).filter(
        (attr) => attr.type === "Attribute" && (attr.name ?? "").toLowerCase() === name,
    ) as AstNode[];
}

const ELEMENT_NAME_INPUT = "input";

/** 1 ファイルを走査した中間結果 (序数は付与前)。 */
interface FileScan {
    readonly nativeInputCount: number;
    readonly fileInputs: readonly { readonly start: number; readonly syntax: "static-text" | "expression"; readonly literal: string | null }[];
    readonly diagnostics: readonly ScanDiagnostic[];
    readonly rawHtml: readonly { readonly start: number }[];
}

function scanOneSource({ file, source }: SvelteSource): FileScan {
    let ast: { fragment: unknown };
    try {
        ast = parse(source, { modern: true });
    } catch (error) {
        return {
            nativeInputCount: 0,
            fileInputs: [],
            diagnostics: [
                {
                    file,
                    reason: "parse-failed",
                    at: null,
                    detail: error instanceof Error ? error.message : String(error),
                },
            ],
            rawHtml: [],
        };
    }

    let nativeInputCount = 0;
    const fileInputs: { start: number; syntax: "static-text" | "expression"; literal: string | null }[] = [];
    const diagnostics: ScanDiagnostic[] = [];
    const rawHtml: { start: number }[] = [];

    const diagnose = (reason: ScanDiagnosticReason, start: number, detail: string): void => {
        diagnostics.push({ file, reason, at: positionAt(source, start), detail });
    };

    eachNode(ast.fragment, (node) => {
        const start = node.start ?? 0;

        if (node.type === "HtmlTag") {
            rawHtml.push({ start });

            return;
        }

        // --- 要素の側: native input を作りうる形を確定する ---
        if (node.type === "RegularElement") {
            if ((node.name ?? "").toLowerCase() !== ELEMENT_NAME_INPUT) return;
        } else if (node.type === "SvelteElement") {
            const tag = node.tag;
            if (!tag || tag.type !== "Literal" || typeof tag.value !== "string") {
                diagnose(
                    "unresolved-native-element",
                    start,
                    "<svelte:element this={…}> の要素名を静的に確定できない (実行時に input になりうる)",
                );

                return;
            }
            if (tag.value.toLowerCase() !== ELEMENT_NAME_INPUT) return;
        } else {
            return;
        }

        nativeInputCount++;

        // --- spread は type / accept を上書きしうるので、他の判定より先に落とす ---
        if ((node.attributes ?? []).some((attr) => attr.type === "SpreadAttribute")) {
            diagnose("spread-attribute", start, "spread 属性が type / accept を上書きしうる");

            return;
        }

        // --- type の側 ---
        const typeAttributes = attributesNamed(node, "type");
        // 属性が無い = HTML 既定の text なので母集団外
        if (typeAttributes.length === 0) return;
        if (typeAttributes.length > 1) {
            diagnose(
                "unresolved-type",
                start,
                "type 属性が (大文字小文字を無視して) 複数あり、どれが効くか確定できない",
            );

            return;
        }
        const typeValue = classifyAttributeValue(typeAttributes[0].value);
        if (typeValue.kind !== "static") {
            diagnose(
                "unresolved-type",
                start,
                typeValue.kind === "expression"
                    ? "type 属性が式で、file かどうか確定できない"
                    : typeValue.detail,
            );

            return;
        }
        if (typeValue.text.toLowerCase() !== "file") return;

        // --- accept の側 (ここに来たものだけが母集団) ---
        const acceptAttributes = attributesNamed(node, "accept");
        if (acceptAttributes.length === 0) {
            diagnose("missing-accept", start, "file input に accept 属性が無い");

            return;
        }
        if (acceptAttributes.length > 1) {
            diagnose(
                "unresolved-accept",
                start,
                "accept 属性が (大文字小文字を無視して) 複数あり、どれが効くか確定できない",
            );

            return;
        }
        const acceptValue = classifyAttributeValue(acceptAttributes[0].value);
        if (acceptValue.kind === "unresolved") {
            diagnose("unresolved-accept", start, acceptValue.detail);

            return;
        }
        fileInputs.push(
            acceptValue.kind === "static"
                ? { start, syntax: "static-text", literal: acceptValue.text }
                : { start, syntax: "expression", literal: null },
        );
    });

    return { nativeInputCount, fileInputs, diagnostics, rawHtml };
}

/** 合成ソース (または読み込み済みファイル) の集合を走査する。 */
export function scanSources(sources: readonly SvelteSource[]): FileInputScanResult {
    const fileInputs: FileInputRecord[] = [];
    const diagnostics: ScanDiagnostic[] = [];
    const rawHtml: RawHtmlRecord[] = [];
    let nativeInputCount = 0;

    for (const entry of sources) {
        const scan = scanOneSource(entry);
        nativeInputCount += scan.nativeInputCount;
        diagnostics.push(...scan.diagnostics);

        // 序数はソース上の出現順で確定する (走査順ではなく offset で並べる)
        [...scan.fileInputs]
            .sort((a, b) => a.start - b.start)
            .forEach((record, index) => {
                fileInputs.push({
                    file: entry.file,
                    occurrence: index + 1,
                    syntax: record.syntax,
                    literal: record.literal,
                });
            });
        [...scan.rawHtml]
            .sort((a, b) => a.start - b.start)
            .forEach((record, index) => {
                rawHtml.push({
                    file: entry.file,
                    occurrence: index + 1,
                    at: positionAt(entry.source, record.start),
                });
            });
    }

    return {
        svelteFileCount: sources.length,
        nativeInputCount,
        fileInputs,
        diagnostics,
        rawHtml,
    };
}

/** `root` 配下の `.svelte` を再帰列挙する。 */
async function listSvelteFiles(root: string): Promise<string[]> {
    const entries = await fs.readdir(root, { recursive: true, withFileTypes: true });
    const files: string[] = [];
    for (const entry of entries) {
        if (!entry.isFile()) continue;
        if (path.extname(entry.name) !== ".svelte") continue;
        const parent = (entry as unknown as { parentPath?: string }).parentPath ?? root;
        files.push(path.join(parent, entry.name));
    }

    return files.sort();
}

/** 実リポジトリの走査根を読み込んで走査する (gate 用)。 */
export async function scanFileInputs(root: string): Promise<FileInputScanResult> {
    const files = await listSvelteFiles(root);
    const sources: SvelteSource[] = [];
    for (const absolute of files) {
        sources.push({
            file: path.relative(root, absolute).split(path.sep).join("/"),
            source: await fs.readFile(absolute, "utf8"),
        });
    }

    return scanSources(sources);
}
