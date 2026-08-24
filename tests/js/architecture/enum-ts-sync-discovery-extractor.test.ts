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
    ownerNameOf,
    planOwners,
    resolveOwner,
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

    it("所属が package なのに program が無ければ所有者の解決で落ちる (fail-closed)", () => {
        const dirs = ["packages/with-config", "packages/without-config"] as const;
        const available = new Set(["<root>", "packages/with-config"]);

        expect(resolveOwner("packages/with-config/src/x.ts", dirs, available)).toBe("packages/with-config");
        expect(resolveOwner("resources/js/types/x.ts", dirs, available)).toBe("<root>");
        // **所属は tsconfig の有無で決めない**ので、`<root>` へ静かに落ちずに例外になる。
        expect(ownerNameOf("packages/without-config/src/x.ts", dirs)).toBe("packages/without-config");
        expect(() => resolveOwner("packages/without-config/src/x.ts", dirs, available)).toThrow(
            "の program がありません",
        );
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

    it("列挙名から語が取れない組も、交差が半分未満でも例外になる (黙って通さない)", () => {
        // 候補側だけを見ていると、PHP 側の列挙名が語に割れないときに規則 2b から
        // 黙って消える (Codex 実装レビュー Round 2 の Critical)。
        for (const values of [["a", "x", "y"], ["a", "b", "x"]]) {
            expect(() =>
                matchReverseRule(
                    { path: "app/Enums/___.php", name: "___", line: 1, values: new Set(["a", "b", "c", "d"]) },
                    tsCandidate("resources/js/types/x.ts", "JobStatus", values),
                ),
            ).toThrow("列挙名から語を 1 つも取り出せません");
        }
    });

    it("nameResolved が真なのに名前が無い候補は内部矛盾として例外になる", () => {
        const broken: TsUnionCandidate = {
            ...tsCandidate("resources/js/types/x.ts", "Foo", ["a", "z"]),
            correspondenceName: null,
            nameResolved: true,
        };
        expect(() => matchReverseRule(phpEnum("app/Enums/Foo.php", ["a", "b"]), broken)).toThrow(
            "nameResolved が真なのに名前対応に使う名前がありません",
        );
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
