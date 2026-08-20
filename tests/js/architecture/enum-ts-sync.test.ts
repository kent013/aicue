/**
 * PHP 列挙 ⇔ TypeScript 値域の汎用同期 gate (家系の裁定 AG-099 前半)。
 *
 * 目録 (`ENUM_TS_MIRRORS`。実体は `../support/enum-ts-sync/mirror-inventory.ts`) に
 * 登録した写しについて、PHP の文字列付き列挙の値集合と TS の型別名が解決する値集合が
 * **完全一致**することを固定する。写しが片方だけ増えると、画面の分岐に
 * 「どこにも当たらない値」が生まれて無言の描画漏れになる。
 *
 * **登録の仕方**: PHP の列挙の値を TS の型別名で受ける箇所を作ったら、
 * `ENUM_TS_MIRRORS` へ 1 行足し、`EXPECTED_MIRROR_COUNT` を 1 増やす。
 * 個別の検査ファイルは**増やさない** (増殖を止めるのが本 gate の目的)。
 *
 * **本ファイルが見るのは登録した写しだけ**である。未登録の PHP 列挙・TS 宣言の発見と、
 * 「登録し忘れ」「名前は対応するが既に食い違った写し」の検出は
 * `enum-ts-sync-discovery.test.ts` (裁定 AG-099 後半) の担当であり、そちらが
 * `ENUM_TS_MIRRORS` を**登録済みの判定**に再利用する (単一の出典)。
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
import { REPO_ROOT, createMirrorProgram, type MirrorProgram } from "../support/enum-ts-sync/program";
import { readTsUnionValues } from "../support/enum-ts-sync/ts-value-sets";
import { readPhpEnumValues } from "../support/enum-ts-sync/php-enums";
import {
    ENUM_TS_MIRRORS,
    EXPECTED_MIRROR_COUNT,
    validateMirrors,
    type EnumTsMirror,
} from "../support/enum-ts-sync/mirror-inventory";


let mirrorProgram: MirrorProgram | undefined;

/** 初期化されていなければ落ちる (definite assignment の `!` を使わない)。 */
const requireMirrorProgram = (): MirrorProgram => {
    if (mirrorProgram === undefined) throw new EnumTsSyncError("mirror program", "初期化されていません");
    return mirrorProgram;
};

describe("PHP 列挙 ⇔ TS 値域の同期", () => {
    beforeAll(() => {
        validateMirrors(ENUM_TS_MIRRORS);
        mirrorProgram = createMirrorProgram([...new Set(ENUM_TS_MIRRORS.map((m) => m.ts))]);
    }, 300_000);

    it("目録の件数が pin と一致する", () => {
        expect(ENUM_TS_MIRRORS).toHaveLength(EXPECTED_MIRROR_COUNT);
    });

    it("目録の行の体裁が守られている", () => {
        expect(() => validateMirrors(ENUM_TS_MIRRORS)).not.toThrow();
    });

    it.each(ENUM_TS_MIRRORS)("$php ⇔ $ts::$declaration の値集合が一致する", (mirror) => {
        const phpValues = readPhpEnumValues(mirror.php);
        const tsValues = readTsUnionValues(requireMirrorProgram(), mirror.ts, mirror.declaration);

        // 空 vs 空で素通りしないことを明示する (抽出器は空集合を返さないが、意図を残す)
        expect(phpValues.size).toBeGreaterThan(0);
        expect([...tsValues].sort()).toEqual([...phpValues].sort());
    });
});

describe("validateMirrors() の負のコントロール (実リポジトリを根にする)", () => {
    const valid: EnumTsMirror = {
        php: "app/Enums/Manual/RenderKind.php",
        ts: "resources/js/types/manual.ts",
        declaration: "RenderKind",
        note: "負のコントロール用の正常な行",
    };

    it("正常な行は通る", () => {
        expect(() => validateMirrors([valid])).not.toThrow();
    });

    it("app/ の外の php は拒否する", () => {
        expect(() => validateMirrors([{ ...valid, php: "config/app.php" }])).toThrow("app/ 配下だけ");
    });

    it("resources/js/ の外の ts は拒否する", () => {
        expect(() => validateMirrors([{ ...valid, ts: "tests/js/setup.ts" }])).toThrow("resources/js/ 配下だけ");
    });

    it("絶対パスは拒否する", () => {
        expect(() => validateMirrors([{ ...valid, php: path.join(REPO_ROOT, valid.php) }])).toThrow(
            "絶対パスは登録できません",
        );
    });

    it("逆斜線を含むパスは拒否する", () => {
        expect(() => validateMirrors([{ ...valid, php: "app\\Enums\\Manual\\RenderKind.php" }])).toThrow(
            "逆斜線を含むパス",
        );
    });

    it(".. を含むパスは拒否する", () => {
        expect(() => validateMirrors([{ ...valid, php: "app/../app/Enums/Manual/RenderKind.php" }])).toThrow(
            ". / .. / 空の区間",
        );
    });

    it(". と空の区間を含むパスは拒否する", () => {
        expect(() => validateMirrors([{ ...valid, php: "app/./Enums/Manual/RenderKind.php" }])).toThrow(
            ". / .. / 空の区間",
        );
        expect(() => validateMirrors([{ ...valid, ts: "resources/js//types/manual.ts" }])).toThrow(
            ". / .. / 空の区間",
        );
    });

    it("拡張子が違う登録は拒否する", () => {
        expect(() => validateMirrors([{ ...valid, php: "app/Enums/Manual/RenderKind.phpx" }])).toThrow(
            "php は .php で終わること",
        );
        expect(() => validateMirrors([{ ...valid, ts: "resources/js/types/manual.d.ts.map" }])).toThrow(
            "ts は .ts で終わること",
        );
    });

    it("実在しないファイルは拒否する", () => {
        expect(() => validateMirrors([{ ...valid, php: "app/Enums/NoSuchEnum.php" }])).toThrow("実在しません");
    });

    it("ディレクトリの登録は拒否する", () => {
        expect(() => validateMirrors([{ ...valid, php: "app/Enums/Manual.php" }])).toThrow("実在しません");
    });

    it("同じ TS 宣言の二重登録は拒否する", () => {
        expect(() => validateMirrors([valid, { ...valid, note: "別の理由" }])).toThrow("2 回登録されています");
    });

    it("note が空の行は拒否する", () => {
        expect(() => validateMirrors([{ ...valid, note: "  " }])).toThrow("note が空です");
    });
});

/**
 * 走査根の境界そのものを固定する負のコントロール。
 * 兄弟ディレクトリ (`app-legacy/`)・symlink による脱出・symlink 別名の二重登録は
 * **実リポジトリには作れない**ので、一時ディレクトリに同じ形の木を作って根を差し替える。
 * ここが無いと `root + path.sep` を素の `root` へ弱める回帰や `realpathSync` 検査の
 * 撤去を検出できない (Codex 実装レビュー Round 1 の Warning)。
 */
describe("validateMirrors() の負のコントロール (走査根の境界)", () => {
    let sandbox = "";

    const row = (php: string, ts: string, declaration = "X"): EnumTsMirror => ({
        php,
        ts,
        declaration,
        note: "見本の木の行",
    });

    beforeAll(() => {
        // realpath を取る (一時ディレクトリ自体が symlink の環境で判定がぶれないようにする)。
        sandbox = fs.realpathSync(fs.mkdtempSync(path.join(os.tmpdir(), "enum-ts-sync-")));
        fs.mkdirSync(path.join(sandbox, "app", "Enums"), { recursive: true });
        fs.mkdirSync(path.join(sandbox, "app-legacy", "Enums"), { recursive: true });
        fs.mkdirSync(path.join(sandbox, "resources", "js", "types"), { recursive: true });
        fs.mkdirSync(path.join(sandbox, "outside"), { recursive: true });

        fs.writeFileSync(path.join(sandbox, "app", "Enums", "X.php"), "<?php\n");
        fs.writeFileSync(path.join(sandbox, "app-legacy", "Enums", "X.php"), "<?php\n");
        fs.writeFileSync(path.join(sandbox, "outside", "X.php"), "<?php\n");
        fs.writeFileSync(path.join(sandbox, "resources", "js", "types", "x.ts"), "export type X = \"a\";\n");

        // app/ の中から走査範囲の外を指す symlink。
        fs.symlinkSync(path.join(sandbox, "outside", "X.php"), path.join(sandbox, "app", "Enums", "escape.php"));
        // 同じ TS ファイルを別名で指す symlink。
        fs.symlinkSync(
            path.join(sandbox, "resources", "js", "types", "x.ts"),
            path.join(sandbox, "resources", "js", "types", "alias.ts"),
        );
    });

    afterAll(() => {
        if (sandbox !== "") fs.rmSync(sandbox, { recursive: true, force: true });
    });

    it("見本の木の正常な行は通る", () => {
        expect(() => validateMirrors([row("app/Enums/X.php", "resources/js/types/x.ts")], sandbox)).not.toThrow();
    });

    it("兄弟ディレクトリ (app-legacy/) は app/ 配下と認めない", () => {
        expect(() =>
            validateMirrors([row("app-legacy/Enums/X.php", "resources/js/types/x.ts")], sandbox),
        ).toThrow("app/ 配下だけ");
    });

    it("symlink で走査範囲の外へ抜ける登録は拒否する", () => {
        expect(() =>
            validateMirrors([row("app/Enums/escape.php", "resources/js/types/x.ts")], sandbox),
        ).toThrow("symlink の解決先が走査範囲の外です");
    });

    it("symlink の別名で同じ TS 宣言を 2 回登録するのは拒否する", () => {
        expect(() =>
            validateMirrors(
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
            validateMirrors([row("app/Enums/dir.php", "resources/js/types/x.ts")], sandbox),
        ).toThrow("通常ファイルではありません");
    });
});
