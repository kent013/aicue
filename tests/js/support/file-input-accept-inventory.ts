import type {
    FileInputScanResult,
    ScanDiagnostic,
    ScanDiagnosticReason,
} from "./file-input-scan";

/**
 * file input の `accept` 供給元目録 (deny-by-default) と、その判定関数。
 *
 * # 軸を 2 つに分ける
 *
 * | 軸 | 値 | 誰が決めるか |
 * |---|---|---|
 * | 実測構文 (`syntax`) | `static-text` / `expression` | **走査器が AST から実測する** |
 * | 供給元の宣言 (`supply`) | `server-prop` / `client-owned` | **人がレビューで宣言する** (理由必須) |
 *
 * `syntax` は機械が確かめられる事実である。`supply` は**設計意図の宣言であって由来の証明ではない**
 * — `server-prop` と書いてあっても、この目録はその識別子がサーバの
 * `AcceptedSourceDocumentTypes` 由来であることを検証しない。
 *
 * # 保証しないもの (誇張しない)
 *
 * - **由来の証明はしない**。`accept={sourceDocumentAccept}` の値が単一の情報源から来ている
 *   ことは、Feature テスト (Controller の props) と component テスト (props の使い方) が担う。
 * - **免除は人の宣言**である。生 HTML (`{@html …}`) の免除は「そこに file input を作らない」
 *   という宣言で、中身を解析した結果ではない。未解決の形 (`diagnostics`) の免除も同じで、
 *   「この形は accept の供給元を持たない」という宣言である。
 * - 走査器側の限界 (`.svelte` 以外・実行時の書き換え・識別子の追跡) はそのまま引き継ぐ。
 *   走査対象と走査器の保証範囲の正本は `./file-input-scan.ts` の docblock。
 *
 * 検出力の裏取りは `tests/js/architecture/file-input-scan.test.ts` (負例・正例の両方向)。
 */

/** 供給元の宣言。**人が宣言する設計意図**であり、gate は由来を検証しない。 */
export type AcceptSupply = "server-prop" | "client-owned";

export interface FileInputAcceptEntry {
    readonly file: string;
    /** ファイル内の 1 始まりの序数 (正の整数)。 */
    readonly occurrence: number;
    /** 実測と一致していなければ違反。 */
    readonly syntax: "static-text" | "expression";
    readonly supply: AcceptSupply;
    /** 30 文字以上 (supply の値に関わらず全エントリ)。 */
    readonly rationale: string;
}

/**
 * 現在の実測ちょうど。**新しいアップロード面を足したら 1 行足し、件数 pin も 1 増やす**。
 *
 * 現在 4 件すべてが `expression` である。`static-text` は 0 件だが区分値としては必要で、
 * `accept="image/*"` と直書きする面が将来増えたときに `expression` から `static-text` へ
 * 変わって赤くなり、供給元の宣言を見直す契機になる (0 件の区分が正しく動くことは
 * 自己検査の合成入力が担保する)。
 */
export const FILE_INPUT_ACCEPT_INVENTORY: readonly FileInputAcceptEntry[] = [
    {
        file: "components/features/manual/SourceDocumentUpload.svelte",
        occurrence: 1,
        syntax: "expression",
        supply: "server-prop",
        rationale:
            "SOP の受理形式はサーバの AcceptedSourceDocumentTypes が単一の情報源で、Inertia props 経由で受け取る",
    },
    {
        file: "pages/Manuals/Create.svelte",
        occurrence: 1,
        syntax: "expression",
        supply: "server-prop",
        rationale:
            "作成と同時の SOP アップロードも同じ単一の情報源から props で受け取る (経路ごとに直書きしない)",
    },
    {
        file: "components/features/capture/CaptureFileFallback.svelte",
        occurrence: 1,
        syntax: "expression",
        supply: "client-owned",
        rationale:
            "撮影テイクの入力は静止画 image/* と動画 video/* の 2 択で、SOP の受理形式とは別概念のためクライアント側で決める",
    },
    {
        file: "components/features/manual/TakeFileUpload.svelte",
        occurrence: 1,
        syntax: "expression",
        supply: "client-owned",
        rationale:
            "テイクの後付けアップロードも静止画・動画の 2 択で、サーバの SOP 受理形式とは無関係のためクライアント側で決める",
    },
] as const;

/** 件数の pin。実測件数・目録配列長・一意キー数の 3 つと一致させる。 */
export const FILE_INPUT_COUNT = 4;

/** `{@html …}` を持つことを許すファイルの名指し目録 (deny-by-default)。 */
export interface RawHtmlExemption {
    readonly file: string;
    /** ファイル内の `{@html}` の 1 始まりの序数 (正の整数)。 */
    readonly occurrence: number;
    /** 30 文字以上。 */
    readonly rationale: string;
}

export const RAW_HTML_EXEMPTIONS: readonly RawHtmlExemption[] = [
    {
        file: "pages/Settings/Security.svelte",
        occurrence: 1,
        rationale:
            "2FA の QR コードはサーバが生成した SVG をそのまま描画する箇所で、ファイル入力を作らない",
    },
] as const;

/** 免除の件数の pin (増減のどちらでも赤くする)。 */
export const RAW_HTML_EXEMPTION_COUNT = 1;

/**
 * 免除の登録を許す診断の理由。**現在 1 つだけ**である。
 *
 * `spread-attribute` だけを許すのは、汎用入力 atom が呼び出し側の属性をそのまま転送する
 * 設計が正当に実在するためである (実測で 1 件)。それ以外の理由は免除できない:
 *
 * - `parse-failed`: 解析できていない状態で緑にできてしまう (走査そのものの故障)
 * - `missing-accept`: 未解決ではなく、file input と確定した上で accept が無い明白な違反
 * - `unresolved-accept` / `unresolved-type` / `unresolved-native-element`:
 *   実装を直せば解消できる形であり、免除の受け皿を先回りして用意しない
 *
 * 理由を増やすときは、その形が本当に直せないことを示した上でこの配列を広げる
 * (広げる操作そのものがレビューに見える)。
 *
 * 型と実行時の集合は**この 1 つの配列から導出する** (二重定義にすると片方だけ広げられる)。
 * 実行時にも検査するのは、目録が人の書くデータで `as` で型を抜けた登録もありうるため。
 */
export const EXEMPTIBLE_DIAGNOSTIC_REASONS = ["spread-attribute"] as const satisfies readonly ScanDiagnosticReason[];

export type ExemptibleDiagnosticReason = (typeof EXEMPTIBLE_DIAGNOSTIC_REASONS)[number];

/**
 * 未解決の形 (`diagnostics`) の免除目録 (deny-by-default)。
 *
 * **詳細設計からの逸脱**: 詳細設計は「診断に免除の概念は無い (無条件で違反)」としていたが、
 * その前提 (実リポジトリの診断が 0 件) は実測で成り立たなかった。汎用入力 atom は
 * `type={…}` と `{...rest}` を持ち、静的には file input になりうる形が正当に実在する。
 * 無条件違反にすると gate そのものが実装できないため、**免除できる理由を 1 つに限った上で**
 * 件数の完全一致つきの免除目録で扱う。未登録の未解決形は依然として違反であり、
 * 無言で候補から外す経路は作っていない。
 *
 * 鍵は `file` + `reason` で、`count` は**その組の実測件数ちょうど**である
 * (同じファイルに 2 件目が増えれば件数不一致で赤くなる)。
 *
 * **保証しないもの (誇張しない)**: 鍵はファイル単位なので、**同一ファイル・同一理由・
 * 同数の置き換え** (既存の 1 件を消して同じファイルの別の場所へ 1 件足す) は検出しない。
 * 位置を鍵に含めれば検出できるが、無関係な編集で行がずれるたびに赤くなり
 * 「赤くなったら目録を緩める」習慣を作るため採らない。新しいアップロード面は
 * 別ファイル・別理由・件数増のいずれかになるので、そこは検出できる。
 * この限界は `file-input-scan.test.ts` の負のコントロールが機械で pin している
 * (厳しくする実装へ変えたらそのテストが落ちて、本 docblock を直す契機になる)。
 */
export interface UnresolvedFormExemption {
    readonly file: string;
    readonly reason: ExemptibleDiagnosticReason;
    /** その file + reason の実測件数ちょうど (正の整数)。 */
    readonly count: number;
    /** 30 文字以上。 */
    readonly rationale: string;
}

export const UNRESOLVED_FORM_EXEMPTIONS: readonly UnresolvedFormExemption[] = [
    {
        file: "components/atoms/Input.svelte",
        reason: "spread-attribute",
        count: 1,
        rationale:
            "汎用入力 atom は type も残りの属性も呼び出し側から受けて転送する設計で、accept の供給元を自分では持たない",
    },
] as const;

/** 未解決の形の免除の件数の pin (増減のどちらでも赤くする)。 */
export const UNRESOLVED_FORM_EXEMPTION_COUNT = 1;

/** 判定関数へ渡す目録一式 (引数の取り違えを型で防ぐためオブジェクトで受ける)。 */
export interface FileInputPolicy {
    readonly inventory: readonly FileInputAcceptEntry[];
    readonly countPin: number;
    readonly rawHtmlExemptions: readonly RawHtmlExemption[];
    readonly rawHtmlExemptionCountPin: number;
    readonly unresolvedFormExemptions: readonly UnresolvedFormExemption[];
    readonly unresolvedFormExemptionCountPin: number;
}

const MIN_RATIONALE_LENGTH = 30;

/** 免除の登録を許す理由かどうか (実行時の検査。型を抜けた登録も止める)。 */
const isExemptibleReason = (reason: ScanDiagnosticReason): boolean =>
    (EXEMPTIBLE_DIAGNOSTIC_REASONS as readonly ScanDiagnosticReason[]).includes(reason);

const isPositiveInteger = (value: number): boolean => Number.isInteger(value) && value > 0;

const keyOf = (file: string, occurrence: number): string => `${file}#${occurrence}`;

/** 重複しているキーを列挙する。 */
function duplicatedKeys(keys: readonly string[]): string[] {
    const seen = new Set<string>();
    const duplicates = new Set<string>();
    for (const key of keys) {
        if (seen.has(key)) duplicates.add(key);
        seen.add(key);
    }

    return [...duplicates];
}

/**
 * gate の判定本体 (純関数)。**判定はすべてこの 1 関数へ集約する** —
 * 母集団非空や診断の扱いを gate 側の assert へ散らすと、その分岐に負例が付かず
 * 「走査器は診断を集めたのに gate が無視する」実装ミスを自己検査できなくなる。
 *
 * @returns 違反の説明文の配列 (空 = 適合)
 */
export function evaluateFileInputInventory(
    scan: FileInputScanResult,
    policy: FileInputPolicy,
): readonly string[] {
    const violations: string[] = [];

    // --- 走査が生きているか / 母集団が空でないか ---
    if (scan.svelteFileCount === 0) {
        violations.push("走査が空振りしている: .svelte が 1 件も見つからない (走査根を確認)");
    }
    if (scan.nativeInputCount === 0) {
        violations.push("母集団が空: native input が 0 件 (走査器の要素判定が壊れている疑い)");
    }
    if (scan.fileInputs.length === 0) {
        violations.push("母集団が空: file input が 0 件 (走査器の type 判定が壊れている疑い)");
    }

    // --- 未解決の形 (診断) ---
    // 免除できない理由は突き合わせに入れず、**無条件で違反**にする
    // (parse 失敗や accept 欠落を免除の受け皿へ通さない = fail-closed の中核)
    const exemptibleDiagnostics: ScanDiagnostic[] = [];
    for (const diagnostic of scan.diagnostics) {
        if (isExemptibleReason(diagnostic.reason)) {
            exemptibleDiagnostics.push(diagnostic);

            continue;
        }
        const where = diagnostic.at ? ` (${diagnostic.at.line}:${diagnostic.at.column})` : "";
        violations.push(
            `免除できない未解決の形: ${diagnostic.file} (${diagnostic.reason})${where} — ` +
                `${diagnostic.detail}。実装を直して解消してください`,
        );
    }

    // 免除できる理由だけを免除目録と両方向で突き合わせる
    const diagnosticCounts = new Map<string, number>();
    for (const diagnostic of exemptibleDiagnostics) {
        const key = `${diagnostic.file}#${diagnostic.reason}`;
        diagnosticCounts.set(key, (diagnosticCounts.get(key) ?? 0) + 1);
    }
    const unresolvedByKey = new Map<string, UnresolvedFormExemption>();
    for (const exemption of policy.unresolvedFormExemptions) {
        unresolvedByKey.set(`${exemption.file}#${exemption.reason}`, exemption);
        if (!isExemptibleReason(exemption.reason)) {
            violations.push(
                `免除できない理由が免除目録に登録されている: ${exemption.file} (${exemption.reason})。` +
                    `登録できるのは ${EXEMPTIBLE_DIAGNOSTIC_REASONS.join(" / ")} だけです`,
            );
        }
        if (!isPositiveInteger(exemption.count)) {
            violations.push(
                `未解決の形の免除の count が正の整数でない: ${exemption.file} (${exemption.reason}) count=${exemption.count}`,
            );
        }
        if (exemption.rationale.length < MIN_RATIONALE_LENGTH) {
            violations.push(
                `未解決の形の免除の理由が 30 文字未満: ${exemption.file} (${exemption.reason})`,
            );
        }
    }
    for (const key of duplicatedKeys(
        policy.unresolvedFormExemptions.map((e) => `${e.file}#${e.reason}`),
    )) {
        violations.push(`未解決の形の免除キーが重複している: ${key}`);
    }
    for (const [key, count] of diagnosticCounts) {
        const exemption = unresolvedByKey.get(key);
        const sample = exemptibleDiagnostics.find((d) => `${d.file}#${d.reason}` === key);
        const where = sample?.at ? ` (${sample.at.line}:${sample.at.column})` : "";
        if (!exemption) {
            violations.push(
                `未登録の未解決の形: ${key}${where} — ${sample?.detail ?? ""}。` +
                    "解消するか UNRESOLVED_FORM_EXEMPTIONS へ理由付きで登録してください",
            );

            continue;
        }
        if (exemption.count !== count) {
            violations.push(
                `未解決の形の免除の件数が実測と一致しない: ${key} 実測=${count} 免除=${exemption.count}`,
            );
        }
    }
    for (const key of unresolvedByKey.keys()) {
        if (!diagnosticCounts.has(key)) {
            violations.push(`未解決の形の免除が残置されている (実測に無い): ${key}`);
        }
    }
    if (policy.unresolvedFormExemptions.length !== policy.unresolvedFormExemptionCountPin) {
        violations.push(
            `未解決の形の免除の件数 pin が配列長と一致しない: pin=${policy.unresolvedFormExemptionCountPin} 配列長=${policy.unresolvedFormExemptions.length}`,
        );
    }
    if (unresolvedByKey.size !== policy.unresolvedFormExemptionCountPin) {
        violations.push(
            `未解決の形の免除の件数 pin が一意キー数と一致しない: pin=${policy.unresolvedFormExemptionCountPin} 一意キー数=${unresolvedByKey.size}`,
        );
    }

    // --- file input の目録を両方向で突き合わせる ---
    const inventoryByKey = new Map<string, FileInputAcceptEntry>();
    for (const entry of policy.inventory) {
        inventoryByKey.set(keyOf(entry.file, entry.occurrence), entry);
        if (!isPositiveInteger(entry.occurrence)) {
            violations.push(
                `目録の occurrence が正の整数でない: ${entry.file} occurrence=${entry.occurrence}`,
            );
        }
        if (entry.rationale.length < MIN_RATIONALE_LENGTH) {
            violations.push(`目録の理由が 30 文字未満: ${keyOf(entry.file, entry.occurrence)}`);
        }
        if (entry.supply === "server-prop" && entry.syntax !== "expression") {
            violations.push(
                `server-prop の宣言は syntax=expression のときだけ許す (静的テキストをサーバ由来と宣言している): ${keyOf(entry.file, entry.occurrence)}`,
            );
        }
    }
    for (const key of duplicatedKeys(policy.inventory.map((e) => keyOf(e.file, e.occurrence)))) {
        violations.push(`目録キーが重複している: ${key}`);
    }
    const measuredKeys = new Set<string>();
    for (const record of scan.fileInputs) {
        const key = keyOf(record.file, record.occurrence);
        measuredKeys.add(key);
        const entry = inventoryByKey.get(key);
        if (!entry) {
            violations.push(
                `未登録の file input: ${key} (実測 syntax=${record.syntax})。` +
                    "受理形式の供給元を判断して FILE_INPUT_ACCEPT_INVENTORY へ登録してください",
            );

            continue;
        }
        if (entry.syntax !== record.syntax) {
            violations.push(
                `syntax の宣言が実測と違う: ${key} 実測=${record.syntax} 宣言=${entry.syntax}`,
            );
        }
    }
    for (const key of inventoryByKey.keys()) {
        if (!measuredKeys.has(key)) {
            violations.push(`目録が残置されている (実測に無い): ${key}`);
        }
    }
    if (scan.fileInputs.length !== policy.countPin) {
        violations.push(
            `file input の件数 pin が実測と一致しない: pin=${policy.countPin} 実測=${scan.fileInputs.length}`,
        );
    }
    if (policy.inventory.length !== policy.countPin) {
        violations.push(
            `file input の件数 pin が目録配列長と一致しない: pin=${policy.countPin} 配列長=${policy.inventory.length}`,
        );
    }
    if (inventoryByKey.size !== policy.countPin) {
        violations.push(
            `file input の件数 pin が一意キー数と一致しない: pin=${policy.countPin} 一意キー数=${inventoryByKey.size}`,
        );
    }

    // --- 生 HTML を免除目録と両方向で突き合わせる ---
    const rawHtmlExemptionByKey = new Map<string, RawHtmlExemption>();
    for (const exemption of policy.rawHtmlExemptions) {
        rawHtmlExemptionByKey.set(keyOf(exemption.file, exemption.occurrence), exemption);
        if (!isPositiveInteger(exemption.occurrence)) {
            violations.push(
                `生 HTML の免除の occurrence が正の整数でない: ${exemption.file} occurrence=${exemption.occurrence}`,
            );
        }
        if (exemption.rationale.length < MIN_RATIONALE_LENGTH) {
            violations.push(
                `生 HTML の免除の理由が 30 文字未満: ${keyOf(exemption.file, exemption.occurrence)}`,
            );
        }
    }
    for (const key of duplicatedKeys(
        policy.rawHtmlExemptions.map((e) => keyOf(e.file, e.occurrence)),
    )) {
        violations.push(`生 HTML の免除キーが重複している: ${key}`);
    }
    const measuredRawHtmlKeys = new Set<string>();
    for (const record of scan.rawHtml) {
        const key = keyOf(record.file, record.occurrence);
        measuredRawHtmlKeys.add(key);
        if (!rawHtmlExemptionByKey.has(key)) {
            violations.push(
                `未登録の生 HTML ({@html}): ${record.file} occurrence=${record.occurrence} ` +
                    `(${record.at.line}:${record.at.column})。` +
                    "そこに file input を作らないことを確認して RAW_HTML_EXEMPTIONS へ登録してください",
            );
        }
    }
    for (const key of rawHtmlExemptionByKey.keys()) {
        if (!measuredRawHtmlKeys.has(key)) {
            violations.push(`生 HTML の免除が残置されている (実測に無い): ${key}`);
        }
    }
    if (scan.rawHtml.length !== policy.rawHtmlExemptionCountPin) {
        violations.push(
            `生 HTML の件数 pin が実測と一致しない: pin=${policy.rawHtmlExemptionCountPin} 実測=${scan.rawHtml.length}`,
        );
    }
    if (policy.rawHtmlExemptions.length !== policy.rawHtmlExemptionCountPin) {
        violations.push(
            `生 HTML の件数 pin が免除配列長と一致しない: pin=${policy.rawHtmlExemptionCountPin} 配列長=${policy.rawHtmlExemptions.length}`,
        );
    }
    if (rawHtmlExemptionByKey.size !== policy.rawHtmlExemptionCountPin) {
        violations.push(
            `生 HTML の件数 pin が一意キー数と一致しない: pin=${policy.rawHtmlExemptionCountPin} 一意キー数=${rawHtmlExemptionByKey.size}`,
        );
    }

    return violations;
}
