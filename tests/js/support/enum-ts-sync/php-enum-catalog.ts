/**
 * PHP の文字列付き列挙の母集団を全数走査する (裁定 AG-099 後半 / 発見の段)。
 *
 * `readPhpEnumValues` / `enum-ts-sync.test.ts` は**登録した写しだけ**を見る検査で、
 * 登録していない PHP 列挙は 1 件も検査していない。本モジュールは向きを変え、
 * `app/` 配下の git 追跡下の `*.php` を**全数**走査して、
 *
 * - `resolved`   … 深さ 0 に string backing の enum 宣言がちょうど 1 つあり、
 *                  case もすべて受理できたファイル (「値集合が読めた」)
 * - `unresolvable` … string backing の enum を宣言していそうだが、
 *                  本 gate 専用の走査器では読み切れなかったファイル
 *
 * の 2 つに分ける。int backing / backing 無し / enum を宣言していないファイルは
 * **母集団に含めない** (このモジュールが見るのは「PHP の文字列付き列挙」だけである)。
 *
 * **抽出器は 1 本しか持たない**。`detectEnumHeaders` (`php-enums.ts`) を
 * `readPhpEnumValuesFromText` と共用するので、母集団の発見と値集合の抽出が
 * 食い違ったまま育つことはない。
 *
 * **保証しないもの (誇張しない)**:
 * - `scan()` が拒否する字句 (バッククォート・ヒアドキュメント・閉じタグ・
 *   未終端の文字列や注釈) を含むファイルは、ファイル全体を読み切れない。
 *   このとき**生のソースに `enum` の語が無ければ母集団から外す**
 *   (`enum` の語すら無ければ、本走査器が受理する enum 宣言を書ける形になっていないと
 *   判断する)。**`enum` の語があれば**、直後の並びを問わず安全側に倒して
 *   `unresolvable` へ回す (コメントを挟む書き方・非 ASCII 識別子であっても見逃さない。
 *   実測では `app/Mcp/Servers/AppMcpServer.php` がここに該当する。ヒアドキュメントを持ち、
 *   docblock に「ToolName **enum** が」という言及があるため候補に上がるが、
 *   実際には enum を宣言していない。安全側に倒した結果の**意図した過剰検出**であり、
 *   目録の登録で解消する)
 * - 深さ 0 に enum 宣言が複数ある (稀) 場合は、どれが対象か機械的に選べないので
 *   常に `unresolvable` にする
 * - **深さ 0 でない enum 宣言が 1 件でも混ざっていれば `unresolvable` にする**。
 *   波括弧付き namespace 宣言 (`namespace Foo { … }`。大文字小文字・コメントの割り込み・
 *   非 ASCII 名前を問わない) の中は enum 宣言が深さ 1 になり、本走査器の「深さ 0」の
 *   前提が崩れる。個別の namespace 構文を正規表現で当てるのではなく、`detectEnumHeaders`
 *   (`php-enums.ts`) が返す**深さ付きの enum 候補**の**全件が深さ 0 であること**を見る
 *   (深さ 0 の候補だけを拾って残りを黙って捨てると、深さ 0 に別の enum があるファイルで
 *   非ゼロ深さの enum が影に隠れて母集団から消える。Codex 実装レビュー Round 3 の
 *   Critical)。この判定はどんな構文で深さがずれても同じ 1 つで拾える。
 *   本リポジトリは波括弧無しの namespace 宣言 (`namespace Foo;`) だけを使っており、
 *   現時点でこの分岐に該当するファイルは 0 件である
 */
import { execFileSync } from "node:child_process";
import fs from "node:fs";
import path from "node:path";
import { EnumTsSyncError } from "./errors";
import { REPO_ROOT } from "./program";
import { detectEnumHeaders, readPhpEnumValuesFromText } from "./php-enums";

/**
 * 生のソースに `enum` の語があるか (安全側の緩い判定)。
 * **直後の並びは問わない** (コメントを挟む書き方 `enum /* c *\/ Foo` や非 ASCII 識別子も
 * 見逃さないため)。fail-closed は「拾いすぎる方向へ倒すのは可、見逃す方向へ倒すのは不可」
 * (AGENTS.md §静的検査の共通規約 (b)) なので、この語があるだけで安全側 (unresolvable) へ倒す。
 * `scan()` 自身が字句を読み切れず `detectEnumHeaders` が使えないときの**最後の手段**であり、
 * 通常経路 (`scan()` が成功する場合) は使わない (深さ付きの enum 候補で判定する)。
 */
const LOOSE_ENUM_DECLARATION = /\benum\b/i;

export interface ResolvedPhpEnum {
    /** リポジトリルートからの相対パス。 */
    readonly path: string;
    /** enum 宣言の名前。 */
    readonly name: string;
    /** case の値集合。 */
    readonly values: ReadonlySet<string>;
}

export interface UnresolvablePhpEnum {
    readonly path: string;
    /** 読み切れなかった理由 (例外の文面)。 */
    readonly reason: string;
}

export interface PhpEnumCatalog {
    readonly resolved: readonly ResolvedPhpEnum[];
    readonly unresolvable: readonly UnresolvablePhpEnum[];
}

/**
 * git 追跡下の `app/` 配下 `*.php` の一覧 (母集団の単一出典)。
 * 空を返すのは走査根が壊れているときだけなので fail-fast にする。
 */
export const listTrackedPhpFiles = (root: string = REPO_ROOT): readonly string[] => {
    const appRoot = path.join(root, "app");
    if (!fs.existsSync(appRoot) || !fs.statSync(appRoot).isDirectory()) {
        throw new EnumTsSyncError("php-enum-catalog", `走査根が実在しません: ${appRoot}`);
    }
    const output = execFileSync("git", ["-C", root, "ls-files", "--", "app/**/*.php"], { encoding: "utf-8" });
    const files = [...new Set(output.split("\n").map((line) => line.trim()).filter((line) => line !== ""))].sort();
    if (files.length === 0) {
        throw new EnumTsSyncError(
            "php-enum-catalog",
            "git ls-files が app/ 配下の *.php を 1 件も返しません (走査が空振りしています)",
        );
    }
    return files;
};

/** 1 ファイル分の分類。母集団に含めないときは `undefined`。 */
export const classifyPhpFile = (
    source: string,
    fileName: string,
): { readonly kind: "resolved"; readonly name: string; readonly values: ReadonlySet<string> }
    | { readonly kind: "unresolvable"; readonly reason: string }
    | undefined => {
    let headers;
    try {
        headers = detectEnumHeaders(source, fileName);
    } catch (error) {
        // scan() 自身が拒否する字句 (バッククォート等)。生のソースに `enum` の語が
        // 無ければ母集団から外す。あれば安全側に倒して unresolvable にする。
        if (LOOSE_ENUM_DECLARATION.test(source)) {
            return { kind: "unresolvable", reason: error instanceof Error ? error.message : String(error) };
        }
        return undefined;
    }

    if (headers.length === 0) return undefined;

    // 深さ 0 以外の候補が **1 件でも混ざっていれば** 安全側に倒す。深さ 0 の候補だけを
    // 拾って残りを黙って捨てると、波括弧付き namespace 宣言などで深さがずれた
    // string enum が、同じファイルにある別の深さ 0 の enum の影に隠れて母集団から
    // 消えてしまう (Codex 実装レビュー Round 3 の Critical)。
    const depthZero = headers.filter((header) => header.depth === 0);
    if (depthZero.length !== headers.length) {
        return {
            kind: "unresolvable",
            reason: `enum 宣言の頭が ${headers.length} 件見つかりましたが、深さ 0 は ${depthZero.length} 件だけです (波括弧付き namespace 宣言等で深さ 0 の前提が崩れています)`,
        };
    }

    if (depthZero.length > 1) {
        return { kind: "unresolvable", reason: `enum 宣言が ${depthZero.length} 件あります (母集団を機械的に選べません)` };
    }

    const backing = depthZero[0].backing;
    if (backing === undefined || backing.toLowerCase() !== "string") {
        // 文字列付き列挙だけが対象 (int backing / backing 無しは母集団に含めない)。
        return undefined;
    }

    try {
        const values = readPhpEnumValuesFromText(source, fileName);
        return { kind: "resolved", name: depthZero[0].name, values };
    } catch (error) {
        return { kind: "unresolvable", reason: error instanceof Error ? error.message : String(error) };
    }
};

/**
 * PHP の文字列付き列挙の母集団を全数走査する。
 *
 * @param root リポジトリルート (負のコントロール用に引数化してある。本番は既定値を使う)
 */
export const buildPhpEnumCatalog = (root: string = REPO_ROOT): PhpEnumCatalog => {
    const resolved: ResolvedPhpEnum[] = [];
    const unresolvable: UnresolvablePhpEnum[] = [];

    for (const relative of listTrackedPhpFiles(root)) {
        const absolute = path.join(root, relative);
        const source = fs.readFileSync(absolute, "utf-8");
        const classification = classifyPhpFile(source, relative);
        if (classification === undefined) continue;
        if (classification.kind === "resolved") {
            resolved.push({ path: relative, name: classification.name, values: classification.values });
        } else {
            unresolvable.push({ path: relative, reason: classification.reason });
        }
    }

    return { resolved, unresolvable };
};
