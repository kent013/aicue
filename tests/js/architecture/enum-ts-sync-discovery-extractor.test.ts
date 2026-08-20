/**
 * 発見の段・逆走査 (T225) の抽出器・純関数の自己検査 (負例行列)。
 *
 * `enum-ts-sync-discovery.test.ts` の本体 gate は「未分類の PHP 列挙・未登録の候補が
 * 0 件であること」しか見ない。分類そのものが静かに間違える (母集団に入れるべきものを
 * 落とす / 入れるべきでないものを混ぜる / 候補の突き合わせが緩すぎる・厳しすぎる) と、
 * 「0 件」という結果そのものが空虚になる。ここで抽出器・突き合わせの純関数の
 * 受理・拒否の境界を固定する。
 *
 * **見本の置き方**: PHP はテスト内の文字列で書く (`classifyPhpFile` はファイルを要求しない。
 * `.php` として置くと strict_types 宣言 gate 等の母集団に入ってしまうのを避ける。
 * `enum-ts-sync-extractor.test.ts` と同じ理由)。TS は `fixtures/candidates/` にファイルで置く
 * (型検査器に実ファイルが要るため。`tsconfig.json` の `exclude` に
 * `tests/js/support/enum-ts-sync/fixtures/**` が既にあるので新設不要)。
 *
 * 保証しないものの正本は `docs/architecture.md` §PHP 列挙と TypeScript 値域の同期。
 */
import { describe, expect, it } from "vitest";
import fs from "node:fs";
import path from "node:path";
import { classifyPhpFile, listTrackedPhpFiles } from "../support/enum-ts-sync/php-enum-catalog";
import { createFixtureProgram, createMirrorProgram, REPO_ROOT } from "../support/enum-ts-sync/program";
import { collectTsUnionCandidates } from "../support/enum-ts-sync/ts-candidates";
import { findUnregisteredMirrorCandidates, shortEnumName } from "../support/enum-ts-sync/reverse-sweep";
import type { ResolvedPhpEnum } from "../support/enum-ts-sync/php-enum-catalog";
import type { TsUnionCandidate } from "../support/enum-ts-sync/ts-candidates";

describe("classifyPhpFile() (発見の段の PHP 側分類)", () => {
    it("D1: 素直な string enum は resolved になる", () => {
        const source = "<?php\nenum D1: string\n{\n    case A = 'a';\n    case B = 'b';\n}\n";
        const result = classifyPhpFile(source, "D1.php");
        expect(result?.kind).toBe("resolved");
        expect(result?.kind === "resolved" && [...result.values].sort()).toEqual(["a", "b"]);
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

describe("listTrackedPhpFiles() (母集団の走査根)", () => {
    it("実リポジトリの app/ 配下は空でない", () => {
        expect(listTrackedPhpFiles().length).toBeGreaterThan(0);
    });

    it("走査根 (app/) が実在しなければ fail-fast する", () => {
        expect(() => listTrackedPhpFiles(path.join(REPO_ROOT, "tests/js/support/enum-ts-sync"))).toThrow(
            "走査根が実在しません",
        );
    });
});

describe("collectTsUnionCandidates() (逆走査の TS 側候補走査)", () => {
    const fixtureDir = path.join(REPO_ROOT, "tests/js/support/enum-ts-sync/fixtures/candidates");
    const fixtureFile = path.join(fixtureDir, "mixed.ts");

    it("文字列リテラル型だけの union / 単独リテラルを候補として拾い、それ以外は拾わない", () => {
        const program = createFixtureProgram([fixtureFile]);
        const candidates = collectTsUnionCandidates(program, fixtureDir);
        const byName = new Map(candidates.map((c) => [c.name, c]));

        expect([...(byName.get("LiteralUnionCandidate")?.values ?? [])].sort()).toEqual(["a", "b"]);
        expect([...(byName.get("SingleLiteralCandidate")?.values ?? [])].sort()).toEqual(["only"]);
        expect(byName.has("NotAUnionCandidate")).toBe(false);
        expect(byName.has("NumberCandidate")).toBe(false);
    });

    it("走査根の配下でないファイルは対象にしない", () => {
        const program = createFixtureProgram([fixtureFile]);
        const candidates = collectTsUnionCandidates(program, path.join(REPO_ROOT, "tests/js/support/enum-ts-sync/program-fixtures"));
        expect(candidates.some((c) => c.name === "LiteralUnionCandidate")).toBe(false);
    });

    it("走査対象ファイルの構文が壊れていると無言で読み飛ばさず例外になる (fail-closed)", () => {
        const brokenDir = path.join(REPO_ROOT, "tests/js/support/enum-ts-sync/fixtures/candidates-broken");
        const brokenFile = path.join(brokenDir, "broken.ts");
        const program = createFixtureProgram([brokenFile]);
        expect(() => collectTsUnionCandidates(program, brokenDir)).toThrow("構文が壊れているため候補を読めません");
    });

    it("母集団は明示した tsFiles に依存しない (tsconfig の include が実際に決める)", () => {
        // createMirrorProgram に登録済みミラーの ts ファイルを 1 つも渡さなくても、
        // tsconfig の include (`resources/js/**/*.ts`) が母集団を決めるので、
        // 登録済みミラーから import されない実在のファイルの宣言が見つかる。
        // ここが崩れると「TS 側の逆走査は登録済みファイルの import グラフに閉じる」
        // という回帰になる。**母集団の単一出典が tsconfig だと主張するものではない**
        // (それは次のテストが固定する、ファイルシステムを直接歩いた集合との完全一致)。
        const program = createMirrorProgram([]);
        const candidates = collectTsUnionCandidates(program);
        expect(candidates.some((c) => c.file === "resources/js/lib/stores/toast.ts")).toBe(true);
    }, 60_000);

    it("走査した非宣言ファイルの集合は、ファイルシステムを直接歩いた集合と一致する (対象ファイル集合の差分が空)", () => {
        // `collectTsUnionCandidates` 自身は「見つかった候補」しか返さないので、
        // 「対象にしたファイル集合」を候補の有無だけでは裏取りできない。
        // ここでは `collectTsUnionCandidates` と**独立した実装**
        // (プログラムを介さない素朴なファイルシステム走査) で resources/js 配下の
        // 期待する .ts (.d.ts を除く) の集合を作り、program 側の集合と完全一致させる。
        // 片方だけ絞られる改変 (tsconfig の exclude を広げる等) が入ったらここが赤くなる。
        const jsRoot = path.join(REPO_ROOT, "resources", "js");
        const expectedFiles = new Set<string>();
        const walk = (dir: string): void => {
            for (const entry of fs.readdirSync(dir, { withFileTypes: true })) {
                const absolute = path.join(dir, entry.name);
                if (entry.isDirectory()) {
                    walk(absolute);
                } else if (entry.isFile() && absolute.endsWith(".ts") && !absolute.endsWith(".d.ts")) {
                    expectedFiles.add(path.relative(REPO_ROOT, absolute).split(path.sep).join("/"));
                }
            }
        };
        walk(jsRoot);

        const program = createMirrorProgram([]);
        const scannedFiles = new Set(
            program.program
                .getSourceFiles()
                .filter((s) => !s.isDeclarationFile && s.fileName.startsWith(jsRoot + path.sep))
                .map((s) => path.relative(REPO_ROOT, s.fileName).split(path.sep).join("/")),
        );

        const missingFromProgram = [...expectedFiles].filter((f) => !scannedFiles.has(f));
        const unexpectedInProgram = [...scannedFiles].filter((f) => !expectedFiles.has(f));

        expect(missingFromProgram, `ファイルシステムには実在するのに program に載っていない: ${missingFromProgram.join(", ")}`).toEqual([]);
        expect(unexpectedInProgram, `program には載っているがファイルシステム走査に無い: ${unexpectedInProgram.join(", ")}`).toEqual([]);
    }, 60_000);
});

const phpEnum = (path_: string, values: readonly string[]): ResolvedPhpEnum => ({
    path: path_,
    name: shortEnumName(path_),
    values: new Set(values),
});

const tsCandidate = (file: string, name: string, values: readonly string[]): TsUnionCandidate => ({
    file,
    name,
    values: new Set(values),
});

describe("findUnregisteredMirrorCandidates() (逆走査の突き合わせ純関数)", () => {
    const notRegistered = (): boolean => false;

    it("E1: 値集合が完全一致する未登録の宣言は規則 1 で見つかる", () => {
        const found = findUnregisteredMirrorCandidates(
            [phpEnum("app/Enums/Foo.php", ["a", "b"])],
            [tsCandidate("resources/js/types/x.ts", "Unrelated", ["a", "b"])],
            notRegistered,
        );
        expect(found).toHaveLength(1);
        expect(found[0].rule).toBe(1);
        expect(found[0].nameMatch).toBeNull();
    });

    it("E2: 完全一致でも登録済みなら見つからない", () => {
        const found = findUnregisteredMirrorCandidates(
            [phpEnum("app/Enums/Foo.php", ["a", "b"])],
            [tsCandidate("resources/js/types/x.ts", "Foo", ["a", "b"])],
            () => true,
        );
        expect(found).toEqual([]);
    });

    it("E3: 名前が一致し値が交差 (完全一致ではない) する未登録の宣言は規則 2 で見つかる", () => {
        const found = findUnregisteredMirrorCandidates(
            [phpEnum("app/Enums/Foo.php", ["a", "b", "c"])],
            [tsCandidate("resources/js/types/x.ts", "Foo", ["a", "z"])],
            notRegistered,
        );
        expect(found).toHaveLength(1);
        expect(found[0].rule).toBe(2);
        expect(found[0].nameMatch).not.toBeNull();
    });

    it("E4: 名前が複数形接尾辞 (s) で対応し値が交差すると規則 2 で見つかる", () => {
        const found = findUnregisteredMirrorCandidates(
            [phpEnum("app/Enums/Foo.php", ["a", "b"])],
            [tsCandidate("resources/js/types/x.ts", "Foos", ["a", "z"])],
            notRegistered,
        );
        expect(found).toHaveLength(1);
        expect(found[0].rule).toBe(2);
    });

    it("E5: 複数形接尾辞 (es) でも対応する", () => {
        const found = findUnregisteredMirrorCandidates(
            [phpEnum("app/Enums/Box.php", ["a", "b"])],
            [tsCandidate("resources/js/types/x.ts", "Boxes", ["a", "z"])],
            notRegistered,
        );
        expect(found).toHaveLength(1);
        expect(found[0].rule).toBe(2);
    });

    it("E6: 接尾辞 values でも対応する", () => {
        const found = findUnregisteredMirrorCandidates(
            [phpEnum("app/Enums/Foo.php", ["a", "b"])],
            [tsCandidate("resources/js/types/x.ts", "FooValues", ["a", "z"])],
            notRegistered,
        );
        expect(found).toHaveLength(1);
        expect(found[0].rule).toBe(2);
    });

    it("E7: 名前が対応しても値が交差しなければ見つからない", () => {
        const found = findUnregisteredMirrorCandidates(
            [phpEnum("app/Enums/Foo.php", ["a", "b"])],
            [tsCandidate("resources/js/types/x.ts", "Foo", ["x", "y"])],
            notRegistered,
        );
        expect(found).toEqual([]);
    });

    it("E8: 値が交差しても名前が対応しなければ見つからない (緩い名前対応は採らない)", () => {
        const found = findUnregisteredMirrorCandidates(
            [phpEnum("app/Enums/Foo.php", ["a", "b"])],
            [tsCandidate("resources/js/types/x.ts", "CompletelyUnrelatedName", ["a", "b", "c"])],
            notRegistered,
        );
        expect(found).toEqual([]);
    });

    it("E9: 名前も値も対応しなければ見つからない", () => {
        const found = findUnregisteredMirrorCandidates(
            [phpEnum("app/Enums/Foo.php", ["a", "b"])],
            [tsCandidate("resources/js/types/x.ts", "Bar", ["x", "y"])],
            notRegistered,
        );
        expect(found).toEqual([]);
    });

    it("E10: 名前対応は英数字以外を除去しない (Foo_Bar と FooBar は同一視しない)", () => {
        const found = findUnregisteredMirrorCandidates(
            [phpEnum("app/Enums/Foo_Bar.php", ["a", "b"])],
            [tsCandidate("resources/js/types/x.ts", "FooBar", ["a", "z"])],
            notRegistered,
        );
        expect(found).toEqual([]);
    });

    it("E11: 名前の一部が一致するだけ (部分文字列) では対応と認めない", () => {
        const found = findUnregisteredMirrorCandidates(
            [phpEnum("app/Enums/Foo.php", ["a", "b"])],
            [tsCandidate("resources/js/types/x.ts", "MyFooValue", ["a", "z"])],
            notRegistered,
        );
        expect(found).toEqual([]);
    });

    it("E12: 大文字小文字の違いだけは対応と認める (名前対応は大小無視)", () => {
        const found = findUnregisteredMirrorCandidates(
            [phpEnum("app/Enums/Foo.php", ["a", "b", "c"])],
            [tsCandidate("resources/js/types/x.ts", "FOO", ["a", "z"])],
            notRegistered,
        );
        expect(found).toHaveLength(1);
        expect(found[0].rule).toBe(2);
    });

    it("E13: 判定は ResolvedPhpEnum.name を使う (ファイル名の語幹と enum 名が食い違っていても name を見る)", () => {
        const found = findUnregisteredMirrorCandidates(
            [{ path: "app/Enums/FileStem.php", name: "ActualEnumName", values: new Set(["a", "b"]) }],
            [tsCandidate("resources/js/types/x.ts", "ActualEnumName", ["a", "z"])],
            notRegistered,
        );
        expect(found).toHaveLength(1);
        expect(found[0].rule).toBe(2);
        // ファイル名の語幹 (FileStem) とは対応しないので、そちらでは見つからないことも確かめる。
        const notFoundByFileStem = findUnregisteredMirrorCandidates(
            [{ path: "app/Enums/FileStem.php", name: "ActualEnumName", values: new Set(["a", "b"]) }],
            [tsCandidate("resources/js/types/x.ts", "FileStem", ["a", "z"])],
            notRegistered,
        );
        expect(notFoundByFileStem).toEqual([]);
    });
});
