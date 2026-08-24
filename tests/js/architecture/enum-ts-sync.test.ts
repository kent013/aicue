/**
 * PHP 列挙 ⇔ TypeScript 値域の汎用同期 gate (家系の機能台帳 `enum-ts-sync-gate`)。
 *
 * 目録 (`ENUM_TS_RELATIONS`。実体は `../support/enum-ts-sync/relation-inventory.ts`) に
 * 登録した**関係**について、PHP の文字列付き列挙の値集合と TS の宣言が解決する値集合の
 * 関係が成り立つことを固定する。写しが片方だけ増えると、画面の分岐に
 * 「どこにも当たらない値」が生まれて無言の描画漏れになる。
 *
 * **関係は 2 つ**である:
 * - `equal` … 値域そのものの写し。**双方向の差分が空**であること
 * - `subset` … 値域の写しではなく、許される値域から選んだ非空の集合。
 *   **TS 側にだけある値が無い**ことだけを見る (PHP 側の追加では赤くならない)
 *
 * **登録の仕方**: PHP の列挙の値を TS で受ける箇所を作ったら、`ENUM_TS_RELATIONS` へ
 * 1 行足し、`EXPECTED_RELATION_COUNT` を 1 増やす。個別の検査ファイルは**増やさない**
 * (増殖を止めるのが本 gate の目的)。受理する TS の形は**型別名の宣言**か
 * **`const` の配列**で、置き場は `resources/js/` と `packages/<name>/src/` である。
 *
 * **本ファイルが見るのは登録した関係だけ**である。未登録の PHP 列挙・TS 宣言の発見と、
 * 「登録し忘れ」「名前は対応するが既に食い違った写し」の検出は
 * `enum-ts-sync-discovery.test.ts` の担当であり、そちらが `ENUM_TS_RELATIONS` を
 * **登録済みの判定**に再利用する (単一の出典)。
 *
 * **レーンの非対称**: 本 gate は `pnpm test` (CI の frontend job) でだけ走る。
 * `composer test` だけでは値集合の同期は検証されない。
 * 保証しないものの正本は `docs/architecture.md` §PHP 列挙と TypeScript 値域の同期。
 */
import { afterAll, beforeAll, describe, expect, it } from "vitest";
import fs from "node:fs";
import os from "node:os";
import path from "node:path";
import { EnumTsSyncError } from "../support/enum-ts-sync/errors";
import { REPO_ROOT, createMirrorPrograms, type MirrorPrograms } from "../support/enum-ts-sync/program";
import { resolveRelations, type ResolvedEnumTsRelation } from "../support/enum-ts-sync/ts-value-sets";
import { readPhpEnumValues } from "../support/enum-ts-sync/php-enums";
import {
    ENUM_TS_RELATIONS,
    EXPECTED_RELATION_COUNT,
    validateRelations,
    type EnumTsRelationEntry,
} from "../support/enum-ts-sync/relation-inventory";

type Row = (typeof ENUM_TS_RELATIONS)[number];

let programs: MirrorPrograms | undefined;
let resolved: readonly ResolvedEnumTsRelation<Row>[] | undefined;

/** 初期化されていなければ落ちる (definite assignment の `!` を使わない)。 */
const requireResolved = (): readonly ResolvedEnumTsRelation<Row>[] => {
    if (resolved === undefined) throw new EnumTsSyncError("relation program", "初期化されていません");
    return resolved;
};

describe("PHP 列挙 ⇔ TS 値域の同期", () => {
    beforeAll(() => {
        validateRelations(ENUM_TS_RELATIONS);
        programs = createMirrorPrograms();
        resolved = resolveRelations(programs, ENUM_TS_RELATIONS);
    }, 300_000);

    it("目録の件数が pin と一致する", () => {
        expect(ENUM_TS_RELATIONS).toHaveLength(EXPECTED_RELATION_COUNT);
    });

    it("目録の行の体裁が守られている", () => {
        expect(() => validateRelations(ENUM_TS_RELATIONS)).not.toThrow();
    });

    it.each(ENUM_TS_RELATIONS)("$php ⇔ $ts::$declaration ($relation)", (row) => {
        const phpValues = readPhpEnumValues(row.php);
        const entry = requireResolved().find((r) => r.entry === row);
        expect(entry, `${row.ts}::${row.declaration} の解決結果がありません`).toBeDefined();
        if (entry === undefined) return;

        // 空 vs 空で素通りしないことを明示する (抽出器は空集合を返さないが、意図を残す)。
        expect(phpValues.size).toBeGreaterThan(0);
        expect(entry.tsValues.size).toBeGreaterThan(0);

        const onlyInTs = [...entry.tsValues].filter((value) => !phpValues.has(value)).sort();
        expect(onlyInTs, `TS 側にだけある値があります: ${onlyInTs.join(", ")}`).toEqual([]);

        if (row.relation === "equal") {
            const onlyInPhp = [...phpValues].filter((value) => !entry.tsValues.has(value)).sort();
            expect(onlyInPhp, `PHP 側にだけある値があります: ${onlyInPhp.join(", ")}`).toEqual([]);
        }
    });
});

describe("validateRelations() の負のコントロール (実リポジトリを根にする)", () => {
    const valid: EnumTsRelationEntry = {
        php: "app/Enums/Manual/RenderKind.php",
        ts: "resources/js/types/manual.ts",
        declaration: "RenderKind",
        relation: "equal",
        note: "負のコントロール用の正常な行",
    };

    it("正常な行は通る", () => {
        expect(() => validateRelations([valid])).not.toThrow();
    });

    it("app/ の外の php は拒否する", () => {
        expect(() => validateRelations([{ ...valid, php: "config/app.php" }])).toThrow("app/ 配下だけ");
    });

    it("登録できる置き場の外の ts は拒否する", () => {
        expect(() => validateRelations([{ ...valid, ts: "tests/js/setup.ts" }])).toThrow(
            "resources/js/ 配下か packages/*/src/ 配下だけ",
        );
    });

    it("道具パッケージでも src の外は拒否する", () => {
        expect(() => validateRelations([{ ...valid, ts: "packages/cli/vitest.config.ts" }])).toThrow(
            "resources/js/ 配下か packages/*/src/ 配下だけ",
        );
        expect(() => validateRelations([{ ...valid, ts: "packages/cli/tests/branding.test.ts" }])).toThrow(
            "resources/js/ 配下か packages/*/src/ 配下だけ",
        );
    });

    it("道具パッケージの src は通る", () => {
        expect(() =>
            validateRelations([
                {
                    php: "app/Enums/ApiErrorCode.php",
                    ts: "packages/cli/src/api/schemas.ts",
                    declaration: "API_ERROR_CODES",
                    relation: "equal",
                    note: "見本の行",
                },
            ]),
        ).not.toThrow();
    });

    it("絶対パスは拒否する", () => {
        expect(() => validateRelations([{ ...valid, php: path.join(REPO_ROOT, valid.php) }])).toThrow(
            "絶対パスは登録できません",
        );
    });

    it("逆斜線を含むパスは拒否する", () => {
        expect(() => validateRelations([{ ...valid, php: "app\\Enums\\Manual\\RenderKind.php" }])).toThrow(
            "逆斜線を含むパス",
        );
    });

    it(".. を含むパスは拒否する", () => {
        expect(() => validateRelations([{ ...valid, php: "app/../app/Enums/Manual/RenderKind.php" }])).toThrow(
            ". / .. / 空の区間",
        );
    });

    it(". と空の区間を含むパスは拒否する", () => {
        expect(() => validateRelations([{ ...valid, php: "app/./Enums/Manual/RenderKind.php" }])).toThrow(
            ". / .. / 空の区間",
        );
        expect(() => validateRelations([{ ...valid, ts: "resources/js//types/manual.ts" }])).toThrow(
            ". / .. / 空の区間",
        );
    });

    it("拡張子が違う登録は拒否する", () => {
        expect(() => validateRelations([{ ...valid, php: "app/Enums/Manual/RenderKind.phpx" }])).toThrow(
            "php は .php で終わること",
        );
        expect(() => validateRelations([{ ...valid, ts: "resources/js/types/manual.d.ts.map" }])).toThrow(
            "ts は .ts か .svelte で終わること",
        );
    });

    it("実在しないファイルは拒否する", () => {
        expect(() => validateRelations([{ ...valid, php: "app/Enums/NoSuchEnum.php" }])).toThrow("実在しません");
    });

    it("ディレクトリの登録は拒否する", () => {
        expect(() => validateRelations([{ ...valid, php: "app/Enums/Manual.php" }])).toThrow("実在しません");
    });

    it("同じ TS 宣言の二重登録は拒否する", () => {
        expect(() => validateRelations([valid, { ...valid, note: "別の理由" }])).toThrow("2 回登録されています");
    });

    it("note が空の行は拒否する", () => {
        expect(() => validateRelations([{ ...valid, note: "  " }])).toThrow("note が空です");
    });

    it("subset の行に subsetReason が無い / 短いと拒否する", () => {
        const subsetRow: EnumTsRelationEntry = {
            ...valid,
            relation: "subset",
            subsetReason: "短すぎる理由",
        };
        expect(() => validateRelations([subsetRow])).toThrow("subsetReason は 30 文字以上");
        expect(() =>
            validateRelations([
                {
                    ...subsetRow,
                    subsetReason: " ".repeat(40),
                },
            ]),
        ).toThrow("subsetReason は 30 文字以上");
    });
});

/**
 * 走査根の境界そのものを固定する負のコントロール。
 * 兄弟ディレクトリ (`app-legacy/`)・symlink による脱出・symlink 別名の二重登録は
 * **実リポジトリには作れない**ので、一時ディレクトリに同じ形の木を作って根を差し替える。
 * ここが無いと `root + path.sep` を素の `root` へ弱める回帰や `realpathSync` 検査の
 * 撤去を検出できない。登録できる根が 2 系統になったので、**根ごとに**負例を置く。
 */
describe("validateRelations() の負のコントロール (走査根の境界)", () => {
    let sandbox = "";

    const row = (php: string, ts: string, declaration = "X"): EnumTsRelationEntry => ({
        php,
        ts,
        declaration,
        relation: "equal",
        note: "見本の木の行",
    });

    beforeAll(() => {
        // realpath を取る (一時ディレクトリ自体が symlink の環境で判定がぶれないようにする)。
        sandbox = fs.realpathSync(fs.mkdtempSync(path.join(os.tmpdir(), "enum-ts-sync-")));
        fs.mkdirSync(path.join(sandbox, "app", "Enums"), { recursive: true });
        fs.mkdirSync(path.join(sandbox, "app-legacy", "Enums"), { recursive: true });
        fs.mkdirSync(path.join(sandbox, "resources", "js", "types"), { recursive: true });
        fs.mkdirSync(path.join(sandbox, "packages", "tool", "src"), { recursive: true });
        fs.mkdirSync(path.join(sandbox, "packages", "linked"), { recursive: true });
        fs.mkdirSync(path.join(sandbox, "outside"), { recursive: true });

        fs.writeFileSync(path.join(sandbox, "app", "Enums", "X.php"), "<?php\n");
        fs.writeFileSync(path.join(sandbox, "app-legacy", "Enums", "X.php"), "<?php\n");
        fs.writeFileSync(path.join(sandbox, "outside", "X.php"), "<?php\n");
        fs.writeFileSync(path.join(sandbox, "outside", "x.ts"), "export type X = \"a\";\n");
        fs.writeFileSync(path.join(sandbox, "resources", "js", "types", "x.ts"), "export type X = \"a\";\n");
        fs.writeFileSync(path.join(sandbox, "packages", "tool", "src", "x.ts"), "export type X = \"a\";\n");

        // app/ の中から走査範囲の外を指す symlink。
        fs.symlinkSync(path.join(sandbox, "outside", "X.php"), path.join(sandbox, "app", "Enums", "escape.php"));
        // 同じ TS ファイルを別名で指す symlink。
        fs.symlinkSync(
            path.join(sandbox, "resources", "js", "types", "x.ts"),
            path.join(sandbox, "resources", "js", "types", "alias.ts"),
        );
        // packages/<name>/src の中から外へ抜ける symlink。
        fs.symlinkSync(path.join(sandbox, "outside", "x.ts"), path.join(sandbox, "packages", "tool", "src", "escape.ts"));
        // packages/<name>/src 自体が symlink である場合。
        fs.symlinkSync(path.join(sandbox, "outside"), path.join(sandbox, "packages", "linked", "src"));
    });

    afterAll(() => {
        if (sandbox !== "") fs.rmSync(sandbox, { recursive: true, force: true });
    });

    it("見本の木の正常な行は通る", () => {
        expect(() => validateRelations([row("app/Enums/X.php", "resources/js/types/x.ts")], sandbox)).not.toThrow();
    });

    it("見本の木の道具パッケージの src も通る", () => {
        expect(() =>
            validateRelations([row("app/Enums/X.php", "packages/tool/src/x.ts")], sandbox),
        ).not.toThrow();
    });

    it("兄弟ディレクトリ (app-legacy/) は app/ 配下と認めない", () => {
        expect(() =>
            validateRelations([row("app-legacy/Enums/X.php", "resources/js/types/x.ts")], sandbox),
        ).toThrow("app/ 配下だけ");
    });

    it("symlink で走査範囲の外へ抜ける登録は拒否する", () => {
        expect(() =>
            validateRelations([row("app/Enums/escape.php", "resources/js/types/x.ts")], sandbox),
        ).toThrow("symlink の解決先が走査範囲の外です");
    });

    it("道具パッケージの src から外へ抜ける symlink も拒否する", () => {
        expect(() =>
            validateRelations([row("app/Enums/X.php", "packages/tool/src/escape.ts")], sandbox),
        ).toThrow("symlink の解決先が走査範囲の外です");
    });

    it("src 自体が symlink のパッケージも走査範囲の外として拒否する", () => {
        expect(() =>
            validateRelations([row("app/Enums/X.php", "packages/linked/src/x.ts")], sandbox),
        ).toThrow("resources/js/ 配下か packages/*/src/ 配下だけ");
    });

    it("symlink の別名で同じ TS 宣言を 2 回登録するのは拒否する", () => {
        expect(() =>
            validateRelations(
                [
                    row("app/Enums/X.php", "resources/js/types/x.ts"),
                    row("app/Enums/X.php", "resources/js/types/alias.ts"),
                ],
                sandbox,
            ),
        ).toThrow("symlink 越しに同じ TS 宣言が 2 回登録されています");
    });

    it("ディレクトリを登録するのは拒否する", () => {
        fs.mkdirSync(path.join(sandbox, "app", "Enums", "dir.php"), { recursive: true });
        expect(() =>
            validateRelations([row("app/Enums/dir.php", "resources/js/types/x.ts")], sandbox),
        ).toThrow("通常ファイルではありません");
    });
});
