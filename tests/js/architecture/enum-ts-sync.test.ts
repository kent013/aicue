/**
 * PHP 列挙 ⇔ TypeScript 値域の汎用同期 gate (家系の裁定 AG-099 前半)。
 *
 * 目録に登録した写しについて、PHP の文字列付き列挙の値集合と TS の型別名が解決する
 * 値集合が**完全一致**することを固定する。写しが片方だけ増えると、画面の分岐に
 * 「どこにも当たらない値」が生まれて無言の描画漏れになる。
 *
 * **登録の仕方**: PHP の列挙の値を TS の型別名で受ける箇所を作ったら、
 * `ENUM_TS_MIRRORS` へ 1 行足し、`EXPECTED_MIRROR_COUNT` を 1 増やす。
 * 個別の検査ファイルは**増やさない** (増殖を止めるのが本 gate の目的)。
 *
 * **登録していない写しは 1 件も検査していない**。全数走査による既定拒否の分類と
 * 逆走査は AG-099 後半の担当で、本 gate には無い (`docs/template-divergence.md` D29)。
 * 現時点で意図的に登録していないものと理由:
 *
 * | TS 宣言 | 理由 |
 * |---|---|
 * | `types/manual.ts::SelectableTakeStatus` | 「選択できるテイクの状態」という部分集合の意図。今は `TakeStatus` と全一致だが完全一致で縛ると意図と食い違う |
 * | `types/dashboard.ts::DashboardJobStatus` | `JobStatus` の真部分集合 (進行中のみ) |
 * | `types/capture.ts::CaptureProgress` ほか画面側だけの語彙 | 対応する PHP 列挙が無い |
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

interface EnumTsMirror {
    /** リポジトリルートからの PHP 列挙ファイルの相対パス (`app/` 配下の `*.php`)。 */
    readonly php: string;
    /** リポジトリルートからの TS ファイルの相対パス (`resources/js/` 配下の `*.ts`)。 */
    readonly ts: string;
    /** TS 側の型別名の名前。 */
    readonly declaration: string;
    /** この写しが要る理由 (画面のどこが値で分岐するか)。 */
    readonly note: string;
}

/**
 * 写しの目録。
 * `note` に最小文字数は課さない — 本目録は**免除の申告ではなく登録**であり、
 * 「検査から外す」判断ではないため (免除目録が 30 文字を課すのとは重さが違う)。
 */
const ENUM_TS_MIRRORS = [
    {
        php: "app/Enums/Manual/VideoManualStatus.php",
        ts: "resources/js/types/manual.ts",
        declaration: "VideoManualStatus",
        note: "詳細画面とダッシュボードが制作状態 5 値で CTA を分岐する",
    },
    {
        php: "app/Enums/Manual/ManualProgress.php",
        ts: "resources/js/types/manual.ts",
        declaration: "ManualProgress",
        note: "一覧の絞り込みと行バッジが 3 値で分岐する",
    },
    {
        php: "app/Enums/Manual/RenderKind.php",
        ts: "resources/js/types/manual.ts",
        declaration: "RenderKind",
        note: "プレビューと完成動画で受け取り口の扱いを分ける",
    },
    {
        php: "app/Enums/Manual/RenderStep.php",
        ts: "resources/js/types/manual.ts",
        declaration: "RenderStep",
        note: "合成の進捗表示が段の値で分岐する",
    },
    {
        php: "app/Enums/Manual/RenderErrorCode.php",
        ts: "resources/js/types/manual.ts",
        declaration: "RenderErrorCode",
        note: "失敗時の案内文を符号で選ぶ",
    },
    {
        php: "app/Enums/Manual/RenderConflictType.php",
        ts: "resources/js/types/manual.ts",
        declaration: "RenderConflictType",
        note: "409 の理由ごとに画面の受け方を変える",
    },
    {
        php: "app/Enums/Manual/ScenarioVerdict.php",
        ts: "resources/js/types/manual.ts",
        declaration: "ScenarioVerdict",
        note: "台本の判定バッジが 3 値で分岐する",
    },
    {
        php: "app/Enums/Manual/ScenarioRuleCode.php",
        ts: "resources/js/types/manual.ts",
        declaration: "ScenarioRuleCode",
        note: "台本の指摘一覧が規則の符号で文言を選ぶ",
    },
    {
        php: "app/Enums/Manual/JobStatus.php",
        ts: "resources/js/types/manual.ts",
        declaration: "AnalysisJobStatus",
        note: "解析ジョブの進行表示が状態で分岐する (TS 側は別名)",
    },
    {
        php: "app/Enums/Manual/MaterialType.php",
        ts: "resources/js/types/manual.ts",
        declaration: "CutMaterialType",
        note: "カット編集が素材種別で入力欄を切り替える",
    },
    {
        php: "app/Enums/Manual/MaterialType.php",
        ts: "resources/js/types/capture.ts",
        declaration: "MaterialType",
        note: "撮影 PWA 側の写し。PC 側と types を分けてあるので両方 pin する",
    },
    {
        php: "app/Enums/Notification/NotificationType.php",
        ts: "resources/js/types/notification.ts",
        declaration: "NotificationType",
        note: "通知一覧がアイコンと文言を種別で選ぶ",
    },
    {
        php: "app/Enums/Billing/OnboardingBillingState.php",
        ts: "resources/js/types/billing.ts",
        declaration: "BillingStateValue",
        note: "契約画面とダッシュボードの両方が契約状態で分岐する",
    },
    {
        php: "app/Enums/AccountDeletionBlockerAction.php",
        ts: "resources/js/types/account.ts",
        declaration: "AccountDeletionBlockerAction",
        note: "退会ガードの「次の一手」で導線を分岐する",
    },
    {
        php: "app/Enums/PlanCode.php",
        ts: "resources/js/types/Auth.ts",
        declaration: "PlanCode",
        note: "契約プランの符号で表示と導線を分岐する",
    },
    {
        php: "app/Enums/AdminConsoleRole.php",
        ts: "resources/js/types/admin.ts",
        declaration: "ConsoleRole",
        note: "ユーザー管理のロール遷移コマンド (TS 側は別名)",
    },
    {
        php: "app/Enums/MemberRoleState.php",
        ts: "resources/js/types/admin.ts",
        declaration: "MemberRoleState",
        note: "ユーザー管理の表示状態 5 値。TS 側は ConsoleRole の別名参照を含む",
    },
    {
        php: "app/Enums/OrganizationRole.php",
        ts: "resources/js/lib/shared-props.ts",
        declaration: "OrganizationRoleValue",
        note: "共有 props の組織ロールで画面の権限表示を分岐する",
    },
    {
        php: "app/Enums/Billing/BillingFeedbackKind.php",
        ts: "resources/js/types/billing.ts",
        declaration: "BillingFeedbackKind",
        note: "課金画面の通知種別で文言を選ぶ",
    },
    {
        php: "app/Enums/Billing/PurchaseFormState.php",
        ts: "resources/js/types/billing.ts",
        declaration: "PurchaseFormStateValue",
        note: "購入フォームの状態で入力欄の初期値を変える",
    },
    {
        php: "app/Enums/Manual/TakeStatus.php",
        ts: "resources/js/types/capture.ts",
        declaration: "TakeStatus",
        note: "撮影テイクの状態で再撮影・採用の可否表示を分岐する",
    },
    {
        php: "app/Enums/Dashboard/DashboardState.php",
        ts: "resources/js/types/dashboard.ts",
        declaration: "DashboardState",
        note: "ダッシュボードの初期状態で案内を切り替える",
    },
    {
        php: "app/Enums/Dashboard/DashboardRole.php",
        ts: "resources/js/types/dashboard.ts",
        declaration: "DashboardRole",
        note: "ダッシュボードの役割で出す導線を変える",
    },
    {
        php: "app/Enums/Manual/AnalysisStep.php",
        ts: "resources/js/types/manual.ts",
        declaration: "AnalysisStep",
        note: "解析の進捗表示が段の値で分岐する",
    },
    {
        php: "app/Enums/Manual/AnalysisConflictType.php",
        ts: "resources/js/types/manual.ts",
        declaration: "AnalysisConflictType",
        note: "解析要求の 409 の理由ごとに案内を変える",
    },
    {
        php: "app/Enums/Manual/ScenarioConflictType.php",
        ts: "resources/js/types/manual.ts",
        declaration: "ScenarioConflictType",
        note: "台本保存の 409 の理由ごとに案内を変える",
    },
    {
        php: "app/Enums/Manual/ManualSortOption.php",
        ts: "resources/js/types/manual.ts",
        declaration: "ManualSortOption",
        note: "一覧の並び順の選択肢を URL クエリと突き合わせる",
    },
] as const satisfies readonly EnumTsMirror[];

/**
 * 目録の件数の pin。増えても減っても赤くする (写しが黙って消えるのを防ぐ)。
 * **これは網羅の証明ではない** — 登録していない写しは 1 件も検査していない。
 */
const EXPECTED_MIRROR_COUNT = 27;

/** `root` の**配下**にあるか (兄弟ディレクトリを通さないよう区切りまで含めて見る)。 */
const isUnder = (absolute: string, root: string): boolean => absolute.startsWith(root + path.sep);

/**
 * 目録の行の体裁を検査する純関数。
 * **program を作る前に呼ぶ** — 後回しにすると、検査の外にあるファイルを
 * 「赤くなる前に読んでしまう」ことになる。
 *
 * @param rows 目録の行
 * @param root 走査根 (既定はリポジトリのルート)。**負のコントロールが symlink や
 *             兄弟ディレクトリを含む見本の木を一時ディレクトリに作って渡すためだけ**に
 *             引数化してある (本番の呼び出しは既定値を使う)。
 */
export const validateMirrors = (rows: readonly EnumTsMirror[], root: string = REPO_ROOT): void => {
    const appRoot = path.join(root, "app");
    const jsRoot = path.join(root, "resources", "js");
    const seen = new Set<string>();
    const seenReal = new Set<string>();

    for (const row of rows) {
        const where = `${row.php} ⇔ ${row.ts}::${row.declaration}`;

        for (const relative of [row.php, row.ts]) {
            if (path.isAbsolute(relative)) throw new EnumTsSyncError(where, `絶対パスは登録できません: ${relative}`);
            if (relative.includes("\\")) throw new EnumTsSyncError(where, `逆斜線を含むパスは登録できません: ${relative}`);
            const segments = relative.split("/");
            if (segments.some((s) => s === "" || s === "." || s === "..")) {
                throw new EnumTsSyncError(where, `. / .. / 空の区間を含むパスは登録できません: ${relative}`);
            }
        }

        if (!row.php.endsWith(".php")) throw new EnumTsSyncError(where, `php は .php で終わること: ${row.php}`);
        if (!row.ts.endsWith(".ts")) throw new EnumTsSyncError(where, `ts は .ts で終わること: ${row.ts}`);
        if (row.note.trim() === "") throw new EnumTsSyncError(where, "note が空です");

        const phpAbs = path.resolve(root, row.php);
        const tsAbs = path.resolve(root, row.ts);
        if (!isUnder(phpAbs, appRoot)) throw new EnumTsSyncError(where, `php は app/ 配下だけ: ${row.php}`);
        if (!isUnder(tsAbs, jsRoot)) throw new EnumTsSyncError(where, `ts は resources/js/ 配下だけ: ${row.ts}`);

        for (const [absolute, scanRoot, label] of [
            [phpAbs, appRoot, row.php],
            [tsAbs, jsRoot, row.ts],
        ] as const) {
            if (!fs.existsSync(absolute)) throw new EnumTsSyncError(where, `登録されたファイルが実在しません: ${label}`);
            if (!fs.statSync(absolute).isFile()) throw new EnumTsSyncError(where, `通常ファイルではありません: ${label}`);
            // symlink 経由で走査範囲の外へ抜けられないようにする
            if (!isUnder(fs.realpathSync(absolute), scanRoot)) {
                throw new EnumTsSyncError(where, `symlink の解決先が走査範囲の外です: ${label}`);
            }
        }

        const key = `${row.ts}::${row.declaration}`;
        if (seen.has(key)) throw new EnumTsSyncError(where, `同じ TS 宣言が 2 回登録されています: ${key}`);
        seen.add(key);

        const realKey = `${fs.realpathSync(tsAbs)}::${row.declaration}`;
        if (seenReal.has(realKey)) {
            throw new EnumTsSyncError(where, `symlink 越しに同じ TS 宣言が 2 回登録されています: ${realKey}`);
        }
        seenReal.add(realKey);
    }
};

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
