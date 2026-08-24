/**
 * 抽出器の自己検査 (負例行列)。
 *
 * `enum-ts-sync.test.ts` の本体 gate は「PHP 列挙 ⇔ TS 値域が一致すること」しか見ない。
 * 抽出器が**静かに間違える** (値を落とす / 注釈の語を混ぜる / 受理範囲外を素通しする) と
 * 一致の主張そのものが空になるので、抽出器の受理・拒否の境界をここで固定する。
 *
 * **見本の置き方が TS と PHP で非対称なのは理由がある**:
 * - TS の見本は**ファイル**で置く。型検査器に解決させるには実ファイルが要るため。
 *   わざと壊した見本を含むので `tsconfig.json` の `exclude` で `pnpm typecheck` の
 *   対象から外してある (`program-fixtures/` は T25 のために**除外しない**)。
 * - PHP の見本は**テスト内の文字列**で書く。抽出器はファイルを要求しないので、
 *   `.php` として置くと 4 つの PHP 側検査 (strict_types 宣言 gate / 禁止文の字句走査 /
 *   Pint / PHPStan) の母集団に入ってしまうのを避ける。
 *
 * 保証しないものの正本は `docs/architecture.md` §PHP 列挙と TypeScript 値域の同期。
 */
import { describe, expect, it, beforeAll } from "vitest";
import fs from "node:fs";
import path from "node:path";
import { EnumTsSyncError } from "../support/enum-ts-sync/errors";
import {
    REPO_ROOT,
    createFixtureProgram,
    createMirrorPrograms,
    type MirrorProgram,
    type MirrorPrograms,
} from "../support/enum-ts-sync/program";
import { readTsUnionValues } from "../support/enum-ts-sync/ts-value-sets";
import { readPhpEnumValues, readPhpEnumValuesFromText } from "../support/enum-ts-sync/php-enums";

const FIXTURE_DIR = path.join(REPO_ROOT, "tests/js/support/enum-ts-sync/fixtures");
const PROGRAM_FIXTURE_DIR = path.join(REPO_ROOT, "tests/js/support/enum-ts-sync/program-fixtures");

/** 見本ファイルのリポジトリ相対パス。 */
const fixture = (name: string): string => `tests/js/support/enum-ts-sync/fixtures/${name}`;

/**
 * どちらの program で判定するか。
 * `full` = 本番の gate と同じ全体 program / `narrow` = 起点だけに縮めた program。
 */
type ProgramKind = "full" | "narrow";

interface TsCase {
    /** 行列の番号 (設計の表と 1:1)。 */
    readonly id: string;
    /** 見本ファイル名 (`fixtures/` 配下)。 */
    readonly file: string;
    /** 読ませる型別名の名前。 */
    readonly declaration: string;
    /** 受理するなら期待する値集合、拒否するなら `undefined`。 */
    readonly accepts: readonly string[] | undefined;
    /** 拒否するときに文面へ必ず含まれる語 (別の理由の例外で緑にならないようにする)。 */
    readonly reason?: string;
    /** 既定は全体 program。T25b だけが起点を縮めた program を使う。 */
    readonly program?: ProgramKind;
}

/**
 * TS 側の行列。**既定は全体 program で判定する** (本番の gate と同じ型世界)。
 * program の別は**行のデータとして持つ** — `it` を別に書くと、その `it` を消しても
 * 件数 pin が動かず**行列から静かに消せてしまう** (Codex 実装レビュー Round 1 の Warning)。
 */
const TS_CASES: readonly TsCase[] = [
    { id: "T1", file: "t01-plain-union.ts", declaration: "X", accepts: ["a", "b"] },
    { id: "T2", file: "t02-single-literal.ts", declaration: "X", accepts: ["only"] },
    { id: "T3", file: "t03-comment-noise.ts", declaration: "X", accepts: ["a", "b"] },
    { id: "T4", file: "t04-open-string.ts", declaration: "X", accepts: undefined, reason: "文字列リテラル型でない" },
    { id: "T5", file: "t05-number-member.ts", declaration: "X", accepts: undefined, reason: "文字列リテラル型でない" },
    { id: "T6", file: "t06-never.ts", declaration: "X", accepts: undefined, reason: "文字列リテラル型でない" },
    { id: "T7", file: "t07-absent.ts", declaration: "X", accepts: undefined, reason: "受理できる宣言が見つかりません" },
    { id: "T8", file: "t08-duplicate-alias.ts", declaration: "X", accepts: undefined, reason: "同名の受理できる宣言が 2 件あります" },
    // T9 は**意味を更新した**行である (削除ではない)。受理する形が 2 つ (型別名 / const の配列)
    // へ広がったので、`const X = ["a"] as const;` は拒否から受理へ移った。
    { id: "T9", file: "t09-const-array.ts", declaration: "X", accepts: ["a"] },
    { id: "T10a", file: "t10a-target.ts", declaration: "X", accepts: ["c", "y1", "y2"] },
    { id: "T10b", file: "t10b-path-alias.ts", declaration: "X", accepts: ["editor", "extra", "shooter", "viewer"] },
    { id: "T11", file: "t11-indexed-access.ts", declaration: "X", accepts: ["p", "q"] },
    { id: "T12", file: "t12-keyof-typeof.ts", declaration: "X", accepts: ["a", "b"] },
    { id: "T13", file: "t13-value-of.ts", declaration: "X", accepts: ["va", "vb"] },
    { id: "T14", file: "t14-lowercase.ts", declaration: "X", accepts: ["a", "b"] },
    { id: "T15", file: "t15-instantiated-conditional.ts", declaration: "X", accepts: ["a", "b"] },
    { id: "T16", file: "t16-generic-conditional.ts", declaration: "X", accepts: undefined, reason: "文字列リテラル型でない" },
    { id: "T17", file: "t17-closed-template.ts", declaration: "X", accepts: ["a1", "a2"] },
    { id: "T18", file: "t18-open-template.ts", declaration: "X", accepts: undefined, reason: "文字列リテラル型でない" },
    { id: "T19", file: "t19-string-enum.ts", declaration: "X", accepts: undefined, reason: "TypeScript の enum の値は受理しません" },
    { id: "T20", file: "t20-numeric-enum.ts", declaration: "X", accepts: undefined, reason: "TypeScript の enum の値は受理しません" },
    { id: "T21", file: "t21-unique-symbol.ts", declaration: "X", accepts: undefined, reason: "文字列リテラル型でない" },
    // T22 / T23 も**意味を更新した**行である (削除ではない)。値の読み取りを共有抽出器の
    // 1 本へ集約した結果、「解決したが文字列リテラル型でない」と「そもそも解決できない
    // (判定保留)」が分かれた。どちらも拒否だが、理由の言葉が変わる。
    { id: "T22", file: "t22-circular.ts", declaration: "X", accepts: undefined, reason: "any / unknown へ解決しました" },
    { id: "T23", file: "t23-unresolved-import.ts", declaration: "X", accepts: undefined, reason: "any / unknown へ解決しました" },
    { id: "T24", file: "t24-source-duplicate.ts", declaration: "X", accepts: ["a"] },
    { id: "T25a", file: "t25-target.ts", declaration: "X", accepts: ["a", "b"], program: "full" },
    // T25b: 起点だけの program では拡張が載らないので値が減る (起点を縮める改変の回帰)。
    { id: "T25b", file: "t25-target.ts", declaration: "X", accepts: ["a"], program: "narrow" },
];

/** 行列の件数の pin (概念上の行数ではなく `it.each` に渡す要素数)。 */
const EXPECTED_TS_CASE_COUNT = 27;

/**
 * 見本の実在集合と行列の参照集合を突き合わせるための「補助」一覧。
 * 行列から直接は読まないが、見本の解決に必要なファイル。
 */
const TS_AUXILIARY_FIXTURES: readonly string[] = ["t10a-other.ts"];

/** `program-fixtures/` に置く補助 (tsconfig の対象に残す)。 */
const PROGRAM_FIXTURES: readonly string[] = ["registry-base.ts", "registry-augmentation.ts"];

let fullPrograms: MirrorPrograms | undefined;
let narrowProgram: MirrorProgram | undefined;

const requireFullPrograms = (): MirrorPrograms => {
    if (fullPrograms === undefined) throw new EnumTsSyncError("fixture full programs", "初期化されていません");
    return fullPrograms;
};
const requireNarrowProgram = (): MirrorProgram => {
    if (narrowProgram === undefined) throw new EnumTsSyncError("fixture narrow program", "初期化されていません");
    return narrowProgram;
};

describe("TS 側抽出器の負例行列", () => {
    beforeAll(() => {
        // 見本は tsconfig から除外してあるが、版管理下なので母集団 (= 起点) には入る。
        fullPrograms = createMirrorPrograms();
        // 起点を縮めた program は「縮めた行が指す見本だけ」を起点にする。
        narrowProgram = createFixtureProgram(
            TS_CASES.filter((c) => c.program === "narrow").map((c) => path.join(FIXTURE_DIR, c.file)),
        );
    }, 300_000);

    it("行列の件数が pin と一致する", () => {
        expect(TS_CASES).toHaveLength(EXPECTED_TS_CASE_COUNT);
        // 起点を縮めた program で判定する行が**実在する** (回帰の対照が消えていない)。
        expect(TS_CASES.filter((c) => c.program === "narrow")).toHaveLength(1);
    });

    it("見本の実在集合と行列の参照集合が完全一致する", () => {
        const onDisk = fs
            .readdirSync(FIXTURE_DIR)
            .filter((f) => f.endsWith(".ts"))
            .sort();
        const referenced = [...new Set([...TS_CASES.map((c) => c.file), ...TS_AUXILIARY_FIXTURES])].sort();
        expect(onDisk).toEqual(referenced);
    });

    it("program-fixtures の実在集合と宣言が完全一致する", () => {
        const onDisk = fs
            .readdirSync(PROGRAM_FIXTURE_DIR)
            .filter((f) => f.endsWith(".ts"))
            .sort();
        expect(onDisk).toEqual([...PROGRAM_FIXTURES].sort());
    });

    it.each(TS_CASES)("$id: $file::$declaration", (testCase) => {
        const mirrorProgram =
            testCase.program === "narrow"
                ? requireNarrowProgram()
                : requireFullPrograms().programOf(fixture(testCase.file));
        const read = (): ReadonlySet<string> =>
            readTsUnionValues(mirrorProgram, fixture(testCase.file), testCase.declaration);

        if (testCase.accepts === undefined) {
            expect(read).toThrow(EnumTsSyncError);
            expect(read).toThrow(testCase.reason);
            return;
        }
        expect([...read()].sort()).toEqual([...testCase.accepts].sort());
    });
});

interface PhpCase {
    readonly id: string;
    /** 見本の PHP ソース (文字列で書く)。 */
    readonly source: string;
    /** 語幹の照合に使うファイル名。 */
    readonly fileName: string;
    readonly accepts: readonly string[] | undefined;
    readonly reason?: string;
}

/** よく使う前置き (見本を短く保つ)。 */
const HEAD = "<?php\n\ndeclare(strict_types=1);\n\nnamespace App\\Enums;\n\n";

const php = (body: string): string => HEAD + body;

/** 逆斜線を n 個だけ並べた文字列 (見本の段数を取り違えないための唯一の作り方)。 */
const backslashes = (n: number): string => "\\".repeat(n);

const PHP_CASES: readonly PhpCase[] = [
    {
        id: "P1",
        fileName: "X.php",
        source: php("enum X: string\n{\n    case A = 'a';\n    case B = 'b';\n    case C = 'c';\n}\n"),
        accepts: ["a", "b", "c"],
    },
    {
        id: "P2",
        fileName: "X.php",
        source: php("enum X: string\n{\n    // case Fake = 'x';\n    /* case Ghost = 'y'; */\n    case A = 'a';\n}\n"),
        accepts: ["a"],
    },
    {
        id: "P3",
        fileName: "X.php",
        source: php(
            "enum X: string\n{\n    case A = 'a';\n\n    public function f(int $n): string\n    {\n        switch ($n) {\n            case 1:\n                return 'one';\n            default:\n                return 'other';\n        }\n    }\n}\n",
        ),
        accepts: ["a"],
    },
    {
        id: "P4",
        fileName: "X.php",
        source: php(
            "enum X: string\n{\n    case A = 'a';\n\n    public function label(): string\n    {\n        return match ($this) {\n            self::A => 'ラベル',\n        };\n    }\n}\n",
        ),
        accepts: ["a"],
    },
    {
        id: "P5",
        fileName: "X.php",
        source: php(
            "enum X: string\n{\n    case A = 'a';\n\n    public function make(): object\n    {\n        return new class\n        {\n            public string $v = 'inner';\n        };\n    }\n}\n",
        ),
        accepts: ["a"],
    },
    {
        id: "P6",
        fileName: "X.php",
        source: php("enum X: string\n{\n    case A = 'a{b}c';\n    case B = 'plain';\n}\n"),
        accepts: ["a{b}c", "plain"],
    },
    {
        id: "P7",
        fileName: "X.php",
        source: php("#[SomeAttribute]\nenum X: string\n{\n    #[Another]\n    case A = 'a';\n}\n"),
        accepts: ["a"],
    },
    {
        id: "P8",
        fileName: "X.php",
        source: php("enum X: string\n{\n    # case Fake = 'x';\n    case A = 'a';\n}\n"),
        accepts: ["a"],
    },
    {
        id: "P9",
        fileName: "X.php",
        source: php("enum X: string implements Foo, Bar\n{\n    case A = 'a';\n}\n"),
        accepts: ["a"],
    },
    {
        id: "P10",
        fileName: "X.php",
        source: php("enum X: string\n{\n    private const PREFIX = 'p';\n\n    case A = 'a';\n}\n"),
        accepts: ["a"],
    },
    {
        id: "P11",
        fileName: "X.php",
        source: php("enum X: string\n{\n    case A = 'a';\n    CASE B = 'b';\n    Case C = 'c';\n}\n"),
        accepts: ["a", "b", "c"],
    },
    {
        id: "P12",
        fileName: "X.php",
        source: php("enum X: string\n{\n    private const PREFIX = 'p';\n\n    case A = self::PREFIX.'a';\n}\n"),
        accepts: undefined,
        reason: "受理する書き方に一致しません",
    },
    {
        id: "P13",
        fileName: "X.php",
        source: php("enum X: string\n{\n    case A = 'it\\'s';\n}\n"),
        accepts: undefined,
        reason: "受理する書き方に一致しません",
    },
    {
        id: "P14",
        fileName: "X.php",
        source: php('enum X: string\n{\n    case A = "pre{$x}";\n}\n'),
        accepts: undefined,
        reason: "受理する書き方に一致しません",
    },
    {
        id: "P15",
        fileName: "X.php",
        source: php("enum X: string\n{\n    case A =\n        'a';\n}\n"),
        accepts: undefined,
        reason: "受理する書き方に一致しません",
    },
    {
        id: "P16",
        fileName: "X.php",
        source: php("enum X: int\n{\n    case A = 1;\n}\n"),
        accepts: undefined,
        reason: "backing 型が string ではありません",
    },
    {
        id: "P17",
        fileName: "X.php",
        source: php("enum X\n{\n    case A;\n}\n"),
        accepts: undefined,
        reason: "backing 型がありません",
    },
    {
        id: "P18",
        fileName: "X.php",
        source: php("enum X: string\n{\n    case A = 'a';\n}\n\nenum Y: string\n{\n    case B = 'b';\n}\n"),
        accepts: undefined,
        reason: "enum 宣言が 2 件あります",
    },
    {
        id: "P19",
        fileName: "X.php",
        source: php("enum X: string\n{\n    public function f(): int\n    {\n        return 1;\n    }\n}\n"),
        accepts: undefined,
        reason: "case を 1 件も取り出せません",
    },
    {
        id: "P20",
        fileName: "X.php",
        source: php("enum X: string\n{\n    case A = <<<'TXT'\n        a\n        TXT;\n}\n"),
        accepts: undefined,
        reason: "ヒアドキュメント",
    },
    {
        id: "P21",
        fileName: "X.php",
        source: php("enum X: string\n{\n    // <<<TXT は注釈の中\n    case A = 'a <<<TXT';\n}\n"),
        accepts: ["a <<<TXT"],
    },
    {
        id: "P22",
        fileName: "X.php",
        source: php("enum X: string\n{\n    case A = 'a';\n\n    public function f(): string\n    {\n        return `ls`;\n    }\n}\n"),
        accepts: undefined,
        reason: "バッククォート",
    },
    {
        id: "P23",
        fileName: "X.php",
        source: php("enum X: string\n{\n    case A = 'a';\n}\n?>\n<p>html</p>\n"),
        accepts: undefined,
        reason: "PHP の閉じタグ",
    },
    {
        id: "P24",
        fileName: "X.php",
        source: php("enum X: string\n{\n    case A = 'a';\n}\n// ?>\n<p>html</p>\n"),
        accepts: undefined,
        reason: "PHP の閉じタグ",
    },
    {
        id: "P25",
        fileName: "X.php",
        source: php("enum X: string\n{\n    case A = 'a';\n}\n# ?>\n<p>html</p>\n"),
        accepts: undefined,
        reason: "PHP の閉じタグ",
    },
    {
        id: "P26",
        fileName: "X.php",
        source: php("enum X: string\n{\n    /* ?> はブロック注釈の中 */\n    case A = 'a ?> b';\n}\n"),
        accepts: ["a ?> b"],
    },
    {
        id: "P27",
        fileName: "X.php",
        source: php("enum X: string\n{\n    case A = 'a';\n}\n\n__halt_compiler();\nrest\n"),
        accepts: undefined,
        reason: "__halt_compiler",
    },
    {
        id: "P28",
        fileName: "X.php",
        source: php("enum X: string\n{\n    case A = 'a';\n\n    public function f(): string\n    {\n        return 'unterminated;\n    }\n}\n"),
        accepts: undefined,
        reason: "単一引用符の文字列が閉じていません",
    },
    {
        id: "P29",
        fileName: "X.php",
        source: php('enum X: string\n{\n    case A = \'a\';\n\n    public function f(): string\n    {\n        return "unterminated;\n    }\n}\n'),
        accepts: undefined,
        reason: "二重引用符の文字列が閉じていません",
    },
    {
        id: "P30",
        fileName: "X.php",
        source: php("enum X: string\n{\n    case A = 'a';\n    /* 閉じない注釈\n}\n"),
        accepts: undefined,
        reason: "ブロック注釈が閉じていません",
    },
    {
        id: "P31",
        // 逆斜線が**奇数**個 → 直後の引用符は逃がされ、文字列は続く。
        // 走査が偶奇を取り違えるとこの後ろの case A を食い潰すので、戻り値の差として観測できる。
        fileName: "X.php",
        source: php(
            "enum X: string\n{\n    public function f(): string\n    {\n        return 'odd " +
                backslashes(1) +
                "'';\n    }\n\n    case A = 'a';\n}\n",
        ),
        accepts: ["a"],
    },
    {
        id: "P32",
        fileName: "X.php",
        source: php(
            "enum X: string\n{\n    public function f(): string\n    {\n        return 'even " +
                backslashes(2) +
                "';\n    }\n\n    case A = 'a';\n}\n",
        ),
        accepts: ["a"],
    },
    {
        id: "P33",
        fileName: "X.php",
        source: php(
            "enum X: string\n{\n    public function f(): string\n    {\n        return 'three " +
                backslashes(3) +
                "'';\n    }\n\n    case A = 'a';\n}\n",
        ),
        accepts: ["a"],
    },
    {
        id: "P34",
        fileName: "Other.php",
        source: php("enum X: string\n{\n    case A = 'a';\n}\n"),
        accepts: undefined,
        reason: "ファイル名の語幹",
    },
    {
        id: "P35",
        fileName: "X.php",
        source: php("enum X: string\n{\n    case A = 'a';\n    case A = 'b';\n}\n"),
        accepts: undefined,
        reason: "case の名前が重複",
    },
    {
        id: "P36",
        fileName: "X.php",
        source: php("enum X: string\n{\n    case A = 'a';\n    case B = 'a';\n}\n"),
        accepts: undefined,
        reason: "backing の値が重複",
    },
    {
        id: "P37",
        fileName: "X.php",
        source: php("enum X: string\n{\n    // 補助面の文字 \u{1F600} を注釈に含む\n    case A = 'a';\n}\n"),
        accepts: ["a"],
    },
    {
        id: "P38",
        fileName: "X.php",
        source: php("enum X: string\r\n{\r\n    case A = 'a';\r\n    case B = 'b';\r\n}\r\n"),
        accepts: ["a", "b"],
    },
    {
        // 値の**中**に改行がある単一引用符の case。受理すると TS 側を "a\nb" にするだけで
        // gate 全体が緑になる (Codex 実装レビュー Round 1 の Critical)。P15 は `=` と
        // 文字列の**間**の改行しか見ていないので、この形は別の負例として要る。
        id: "P39",
        fileName: "X.php",
        source: php("enum X: string\n{\n    case A = 'a\nb';\n}\n"),
        accepts: undefined,
        reason: "改行を含む case は受理しません",
    },
    {
        id: "P40",
        fileName: "X.php",
        source: php('enum X: string\n{\n    case A = "a\nb";\n}\n'),
        accepts: undefined,
        reason: "改行を含む case は受理しません",
    },
];

/** 行列の件数の pin。 */
const EXPECTED_PHP_CASE_COUNT = 40;

describe("PHP 側抽出器の負例行列", () => {
    it("行列の件数が pin と一致する", () => {
        expect(PHP_CASES).toHaveLength(EXPECTED_PHP_CASE_COUNT);
    });

    /**
     * P31〜P33 は逆斜線の段数を取り違えやすい (TypeScript の文字列で PHP を書くと
     * 逃がしが二重にかかる)。**抽出器へ渡る PHP のソースそのもの**の逆斜線の個数を
     * 先に pin して、期待値が意図どおりの見本に対するものであることを確かめる。
     */
    it("P31〜P33 の見本に含まれる逆斜線の個数が意図どおり", () => {
        const count = (id: string): number => {
            const found = PHP_CASES.find((c) => c.id === id);
            if (found === undefined) throw new Error(`見本 ${id} がありません`);
            return [...found.source].filter((ch) => ch === "\\").length;
        };
        // namespace App\Enums; の 1 個を含むので、本体の段数 + 1 になる。
        expect(count("P31")).toBe(2);
        expect(count("P32")).toBe(3);
        expect(count("P33")).toBe(4);
    });

    it.each(PHP_CASES)("$id", (testCase) => {
        const read = (): ReadonlySet<string> => readPhpEnumValuesFromText(testCase.source, testCase.fileName);

        if (testCase.accepts === undefined) {
            expect(read).toThrow(EnumTsSyncError);
            expect(read).toThrow(testCase.reason);
            return;
        }
        expect([...read()].sort()).toEqual([...testCase.accepts].sort());
    });

    /**
     * ファイルから読む包み (`readPhpEnumValues`) の経路を、見本を増やさずに通す。
     * `MemberRoleState` は `match` 式を持つ実例、`Manual/RenderKind` は素直な実例。
     */
    it("実在の enum ファイルを相対パスから読める", () => {
        expect([...readPhpEnumValues("app/Enums/MemberRoleState.php")].sort()).toEqual([
            "admin",
            "editor",
            "owner",
            "shooter",
            "unassigned",
        ]);
        expect([...readPhpEnumValues("app/Enums/Manual/RenderKind.php")].sort()).toEqual(["preview", "render"]);
    });

    it("実在しないパスは理由付きで落ちる", () => {
        expect(() => readPhpEnumValues("app/Enums/NoSuchEnum.php")).toThrow(EnumTsSyncError);
    });
});
