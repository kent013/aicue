/**
 * PHP 列挙 ⇔ TS 値域の写しの目録 (`ENUM_TS_MIRRORS`) と、その体裁を検査する
 * `validateMirrors()`。
 *
 * `tests/js/architecture/enum-ts-sync.test.ts` (登録した写しの値集合が一致することを見る)
 * と `tests/js/architecture/enum-ts-sync-discovery.test.ts` (発見の段・逆走査。
 * どの PHP 列挙・TS 宣言が「登録済み」かを判定するのに同じ目録を使う) の**両方から使う
 * 単一の出典**である。2 つに分かれると「片方だけ更新して食い違う」経路が生まれるため、
 * ここへ集約している。
 */
import fs from "node:fs";
import path from "node:path";
import { EnumTsSyncError } from "./errors";
import { REPO_ROOT } from "./program";

export interface EnumTsMirror {
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
export const ENUM_TS_MIRRORS = [
    {
        php: "app/Enums/EnterpriseSso/OidcConnectionStatus.php",
        ts: "resources/js/components/features/sso/oidc-connection.ts",
        declaration: "OidcConnectionStatus",
        note: "企業 SSO の接続管理画面がバッジ・案内文・押せる操作を状態 4 値で分岐する",
    },
    {
        php: "app/Enums/Organization/EntryTarget.php",
        ts: "resources/js/types/organization.ts",
        declaration: "OrganizationEntryTarget",
        note: "組織を選ぶ画面が「どこへ向かう選択なのか」を描くために値を受け取る",
    },
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
export const EXPECTED_MIRROR_COUNT = 29;

/** `root` の**配下**にあるか (兄弟ディレクトリを通さないよう区切りまで含めて見る)。 */
export const isUnder = (absolute: string, root: string): boolean => absolute.startsWith(root + path.sep);

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

/** 登録済みの `(php パス)` 集合。発見の段が「登録済み」を判定するのに使う。 */
export const registeredPhpPaths = (rows: readonly EnumTsMirror[] = ENUM_TS_MIRRORS): ReadonlySet<string> =>
    new Set(rows.map((row) => row.php));

/** 登録済みの `(ts パス, 宣言名)` 集合。逆走査が「登録済み」を判定するのに使う。 */
export const registeredTsKeys = (rows: readonly EnumTsMirror[] = ENUM_TS_MIRRORS): ReadonlySet<string> =>
    new Set(rows.map((row) => `${row.ts}::${row.declaration}`));
