/**
 * PHP 列挙 ⇔ TS 値域の**関係**の目録 (`ENUM_TS_RELATIONS`) と、その体裁を検査する
 * `validateRelations()`。
 *
 * `tests/js/architecture/enum-ts-sync.test.ts` (登録した関係が成り立つことを見る) と
 * `tests/js/architecture/enum-ts-sync-discovery.test.ts` (発見の段・逆走査。
 * どの PHP 列挙・TS 宣言が「登録済み」かを判定するのに同じ目録を使う) の**両方から使う
 * 単一の出典**である。2 つに分かれると「片方だけ更新して食い違う」経路が生まれる。
 *
 * **関係は 2 つある**。`equal` は値域そのものの写しで双方向の差分が空であること、
 * `subset` は**値域の写しではなく、許される値域から選んだ非空の集合**で
 * 「TS 側にだけある値が無い」ことだけを見る。`subset` の行には
 * **なぜ値域の写しではないのか**を `subsetReason` (30 文字以上) で書く。
 * **`subset` は逃げ道になり得る** (完全一致の写しを `subset` と偽れば緩む)。
 * 機械では見分けられないので、`subsetReason` の記述とレビューで担保する。
 *
 * **登録できる TS の置き場**は `resources/js/` (画面側) と `packages/<name>/src/`
 * (付属のコマンドライン道具) で、拡張子は `.ts` と `.svelte`。
 * `tests/js/` と `packages/<name>/tests/` は登録の置き場ではない
 * (検査の見本を写しとして登録しない)。
 */
import fs from "node:fs";
import path from "node:path";
import { EnumTsSyncError } from "./errors";
import { REPO_ROOT } from "./repo-root";

/** 目録の 1 行の共通部分。 */
export interface EnumTsRelationBase {
    /** リポジトリルートからの PHP 列挙ファイルの相対パス (`app/` 配下の `*.php`)。 */
    readonly php: string;
    /** リポジトリルートからの TS ファイルの相対パス (`resources/js/` か `packages/<name>/src/` の配下)。 */
    readonly ts: string;
    /** TS 側の宣言の名前 (型別名 または `const` の配列)。 */
    readonly declaration: string;
    /** この関係が要る理由 (画面や道具のどこが値で分岐するか)。 */
    readonly note: string;
}

/**
 * PHP の値集合と TS の値集合の関係。**判別された合併**にして、
 * `"subset"` の行にだけ追加の申告 (`subsetReason`) を要求する
 * (`note` は `"equal"` の行にもあるので、`note` 非空だけでは subset 固有の負担にならない)。
 */
export type EnumTsRelationEntry =
    | (EnumTsRelationBase & { readonly relation: "equal" })
    | (EnumTsRelationBase & {
          readonly relation: "subset";
          /** **なぜ値域の写しではないのか** (30 文字以上)。 */
          readonly subsetReason: string;
      });

/**
 * 関係の目録。
 * `note` に最小文字数は課さない — 本目録は**免除の申告ではなく登録**であり、
 * 「検査から外す」判断ではないため (免除目録が 30 文字を課すのとは重さが違う)。
 * `subsetReason` だけは 30 文字以上を課す (前向きの検査を片側だけにする申告であるため)。
 */
export const ENUM_TS_RELATIONS = [
    {
        php: "app/Enums/EnterpriseSso/OidcConnectionStatus.php",
        ts: "resources/js/components/features/sso/oidc-connection.ts",
        declaration: "OidcConnectionStatus",
        relation: "equal",
        note: "企業 SSO の接続管理画面がバッジ・案内文・押せる操作を状態 4 値で分岐する",
    },
    {
        php: "app/Enums/Organization/EntryTarget.php",
        ts: "resources/js/types/organization.ts",
        declaration: "OrganizationEntryTarget",
        relation: "equal",
        note: "組織を選ぶ画面が「どこへ向かう選択なのか」を描くために値を受け取る",
    },
    {
        php: "app/Enums/Manual/VideoManualStatus.php",
        ts: "resources/js/types/manual.ts",
        declaration: "VideoManualStatus",
        relation: "equal",
        note: "詳細画面とダッシュボードが制作状態 5 値で CTA を分岐する",
    },
    {
        php: "app/Enums/Manual/ManualProgress.php",
        ts: "resources/js/types/manual.ts",
        declaration: "ManualProgress",
        relation: "equal",
        note: "一覧の絞り込みと行バッジが 3 値で分岐する",
    },
    {
        php: "app/Enums/Manual/RenderKind.php",
        ts: "resources/js/types/manual.ts",
        declaration: "RenderKind",
        relation: "equal",
        note: "プレビューと完成動画で受け取り口の扱いを分ける",
    },
    {
        php: "app/Enums/Manual/RenderStep.php",
        ts: "resources/js/types/manual.ts",
        declaration: "RenderStep",
        relation: "equal",
        note: "合成の進捗表示が段の値で分岐する",
    },
    {
        php: "app/Enums/Manual/RenderErrorCode.php",
        ts: "resources/js/types/manual.ts",
        declaration: "RenderErrorCode",
        relation: "equal",
        note: "失敗時の案内文を符号で選ぶ",
    },
    {
        php: "app/Enums/Manual/RenderConflictType.php",
        ts: "resources/js/types/manual.ts",
        declaration: "RenderConflictType",
        relation: "equal",
        note: "409 の理由ごとに画面の受け方を変える",
    },
    {
        php: "app/Enums/Manual/ScenarioVerdict.php",
        ts: "resources/js/types/manual.ts",
        declaration: "ScenarioVerdict",
        relation: "equal",
        note: "台本の判定バッジが 3 値で分岐する",
    },
    {
        php: "app/Enums/Manual/ScenarioRuleCode.php",
        ts: "resources/js/types/manual.ts",
        declaration: "ScenarioRuleCode",
        relation: "equal",
        note: "台本の指摘一覧が規則の符号で文言を選ぶ",
    },
    {
        php: "app/Enums/Manual/JobStatus.php",
        ts: "resources/js/types/manual.ts",
        declaration: "AnalysisJobStatus",
        relation: "equal",
        note: "解析ジョブの進行表示が状態で分岐する (TS 側は別名)",
    },
    {
        php: "app/Enums/Manual/MaterialType.php",
        ts: "resources/js/types/manual.ts",
        declaration: "CutMaterialType",
        relation: "equal",
        note: "カット編集が素材種別で入力欄を切り替える",
    },
    {
        php: "app/Enums/Manual/MaterialType.php",
        ts: "resources/js/types/capture.ts",
        declaration: "MaterialType",
        relation: "equal",
        note: "撮影 PWA 側の写し。PC 側と types を分けてあるので両方 pin する",
    },
    {
        php: "app/Enums/Notification/NotificationType.php",
        ts: "resources/js/types/notification.ts",
        declaration: "NotificationType",
        relation: "equal",
        note: "通知一覧がアイコンと文言を種別で選ぶ",
    },
    {
        php: "app/Enums/Billing/OnboardingBillingState.php",
        ts: "resources/js/types/billing.ts",
        declaration: "BillingStateValue",
        relation: "equal",
        note: "契約画面とダッシュボードの両方が契約状態で分岐する",
    },
    {
        php: "app/Enums/AccountDeletionBlockerAction.php",
        ts: "resources/js/types/account.ts",
        declaration: "AccountDeletionBlockerAction",
        relation: "equal",
        note: "退会ガードの「次の一手」で導線を分岐する",
    },
    {
        php: "app/Enums/PlanCode.php",
        ts: "resources/js/types/Auth.ts",
        declaration: "PlanCode",
        relation: "equal",
        note: "契約プランの符号で表示と導線を分岐する",
    },
    {
        php: "app/Enums/AdminConsoleRole.php",
        ts: "resources/js/types/admin.ts",
        declaration: "ConsoleRole",
        relation: "equal",
        note: "ユーザー管理のロール遷移コマンド (TS 側は別名)",
    },
    {
        php: "app/Enums/MemberRoleState.php",
        ts: "resources/js/types/admin.ts",
        declaration: "MemberRoleState",
        relation: "equal",
        note: "ユーザー管理の表示状態 5 値。TS 側は ConsoleRole の別名参照を含む",
    },
    {
        php: "app/Enums/OrganizationRole.php",
        ts: "resources/js/lib/shared-props.ts",
        declaration: "OrganizationRoleValue",
        relation: "equal",
        note: "共有 props の組織ロールで画面の権限表示を分岐する",
    },
    {
        php: "app/Enums/Billing/BillingFeedbackKind.php",
        ts: "resources/js/types/billing.ts",
        declaration: "BillingFeedbackKind",
        relation: "equal",
        note: "課金画面の通知種別で文言を選ぶ",
    },
    {
        php: "app/Enums/Billing/PurchaseFormState.php",
        ts: "resources/js/types/billing.ts",
        declaration: "PurchaseFormStateValue",
        relation: "equal",
        note: "購入フォームの状態で入力欄の初期値を変える",
    },
    {
        php: "app/Enums/Manual/TakeStatus.php",
        ts: "resources/js/types/capture.ts",
        declaration: "TakeStatus",
        relation: "equal",
        note: "撮影テイクの状態で再撮影・採用の可否表示を分岐する",
    },
    {
        php: "app/Enums/Dashboard/DashboardState.php",
        ts: "resources/js/types/dashboard.ts",
        declaration: "DashboardState",
        relation: "equal",
        note: "ダッシュボードの初期状態で案内を切り替える",
    },
    {
        php: "app/Enums/Dashboard/DashboardRole.php",
        ts: "resources/js/types/dashboard.ts",
        declaration: "DashboardRole",
        relation: "equal",
        note: "ダッシュボードの役割で出す導線を変える",
    },
    {
        php: "app/Enums/Manual/AnalysisStep.php",
        ts: "resources/js/types/manual.ts",
        declaration: "AnalysisStep",
        relation: "equal",
        note: "解析の進捗表示が段の値で分岐する",
    },
    {
        php: "app/Enums/Manual/AnalysisConflictType.php",
        ts: "resources/js/types/manual.ts",
        declaration: "AnalysisConflictType",
        relation: "equal",
        note: "解析要求の 409 の理由ごとに案内を変える",
    },
    {
        php: "app/Enums/Manual/ScenarioConflictType.php",
        ts: "resources/js/types/manual.ts",
        declaration: "ScenarioConflictType",
        relation: "equal",
        note: "台本保存の 409 の理由ごとに案内を変える",
    },
    {
        php: "app/Enums/Manual/ManualSortOption.php",
        ts: "resources/js/types/manual.ts",
        declaration: "ManualSortOption",
        relation: "equal",
        note: "一覧の並び順の選択肢を URL クエリと突き合わせる",
    },
    {
        php: "app/Enums/ApiErrorCode.php",
        ts: "packages/cli/src/api/schemas.ts",
        declaration: "API_ERROR_CODES",
        relation: "equal",
        note: "付属のコマンドライン道具が応答の符号で失敗の種類を分ける (rate-limit / conflict / auth)",
    },
    {
        php: "app/Enums/OAuth/CliOAuthScope.php",
        ts: "packages/cli/src/oauth/login.ts",
        declaration: "DEFAULT_CLI_SCOPES",
        relation: "subset",
        note: "道具がログインのときに既定で要求する権限の集合",
        subsetReason: "値域そのものの写しではなく、サーバが認識する値域から道具が既定で要求する権限だけを選んだ集合であるため。サーバ側の追加を道具へ強制しない (最小権限)",
    },
] as const satisfies readonly EnumTsRelationEntry[];

/**
 * 目録の件数の pin。増えても減っても赤くする (関係が黙って消えるのを防ぐ)。
 * **これは網羅の証明ではない** — 登録していない写しは 1 件も検査していない
 * (未登録の検出は逆走査の担当)。
 */
export const EXPECTED_RELATION_COUNT = 31;

/** `root` の**配下**にあるか (兄弟ディレクトリを通さないよう区切りまで含めて見る)。 */
export const isUnder = (absolute: string, root: string): boolean => absolute.startsWith(root + path.sep);

/** 登録できる TS の拡張子。 */
const TS_EXTENSIONS = [".ts", ".svelte"] as const;

/**
 * 登録できる TS の置き場。
 * - `resources/js/` … 画面側
 * - `packages/<name>/src/` … 付属のコマンドライン道具 (本 feature の境界は画面側に限らない)
 *
 * `listPackageSrcRoots()` は綴り順に整列し、**通常ディレクトリだけ**を返す (診断を安定させる)。
 */
const listPackageSrcRoots = (root: string): readonly string[] => {
    const packagesDir = path.join(root, "packages");
    if (!fs.existsSync(packagesDir) || !fs.statSync(packagesDir).isDirectory()) return [];
    return fs
        .readdirSync(packagesDir, { withFileTypes: true })
        .filter((entry) => entry.isDirectory())
        .map((entry) => path.join(packagesDir, entry.name, "src"))
        // `lstat` で見る = **symlink のディレクトリは根にしない** (根そのものが
        // 走査範囲の外を指す形を最初から作らせない)。
        .filter((dir) => fs.existsSync(dir) && fs.lstatSync(dir).isDirectory())
        .sort();
};

const tsRootsOf = (root: string): readonly string[] => [
    path.join(root, "resources", "js"),
    ...listPackageSrcRoots(root),
];

/** 登録できる置き場の説明 (失敗メッセージと負の対照が同じ文面を見る)。 */
export const TS_ROOT_DESCRIPTION = "ts は resources/js/ 配下か packages/*/src/ 配下だけです";

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
export const validateRelations = (rows: readonly EnumTsRelationEntry[], root: string = REPO_ROOT): void => {
    const appRoot = path.join(root, "app");
    const tsRoots = tsRootsOf(root);
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
        if (!TS_EXTENSIONS.some((extension) => row.ts.endsWith(extension))) {
            throw new EnumTsSyncError(where, `ts は .ts か .svelte で終わること: ${row.ts}`);
        }
        if (row.note.trim() === "") throw new EnumTsSyncError(where, "note が空です");
        if (row.relation === "subset" && row.subsetReason.trim().length < 30) {
            throw new EnumTsSyncError(where, "subsetReason は 30 文字以上で書くこと (なぜ値域の写しではないのか)");
        }

        const phpAbs = path.resolve(root, row.php);
        const tsAbs = path.resolve(root, row.ts);
        if (!isUnder(phpAbs, appRoot)) throw new EnumTsSyncError(where, `php は app/ 配下だけ: ${row.php}`);
        // 字面で一致した根に対して symlink の脱出検査を行う
        // (別の根と比べると拒否漏れ・誤拒否のどちらも起きる)。
        const matchedRoot = tsRoots.find((tsRoot) => isUnder(tsAbs, tsRoot));
        if (matchedRoot === undefined) throw new EnumTsSyncError(where, `${TS_ROOT_DESCRIPTION}: ${row.ts}`);

        for (const [absolute, scanRoot, label] of [
            [phpAbs, appRoot, row.php],
            [tsAbs, matchedRoot, row.ts],
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
export const declaredPhpPaths = (rows: readonly EnumTsRelationEntry[] = ENUM_TS_RELATIONS): ReadonlySet<string> =>
    new Set(rows.map((row) => row.php));

/**
 * 登録済みの `(ts パス, 宣言名)` 集合。
 * **逆走査が「登録済み」を判定するのに使うのは locator であり本集合ではない**
 * (locator は `ts-value-sets.ts` の解決が AST から作る)。本集合は診断と重複検査の補助である。
 */
export const declaredTsKeys = (rows: readonly EnumTsRelationEntry[] = ENUM_TS_RELATIONS): ReadonlySet<string> =>
    new Set(rows.map((row) => `${row.ts}::${row.declaration}`));
