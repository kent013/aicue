import { describe, it, expect } from "vitest";
import { designColors } from "../styles/design-md";
import {
    ALPHA_CONTRAST_PAIRS,
    ALPHA_PAIR_USAGE_LEDGER,
    COLOR_TOKEN_MAP,
    COLOR_TOKEN_ROLES,
    DECLARED_CONTRAST_PAIRS,
    FILL_LABEL_TOKENS,
    FILL_TOKENS,
    PENDING_CONTRAST_PAIRS,
    SURFACE_ROLE_TOKENS,
    TEXT_ON_SURFACE_TOKENS,
    UNDECIDABLE_PAIR_LEDGER,
    JS_SCAN_CHILD_CLASSIFICATION,
    NON_TEXT_BOUNDARY_REASONS,
    UNDECIDABLE_REASONS,
    distinctPairs,
    rolesOf,
    tokensWithRole,
    type AlphaPair,
    type CssColorSuffix,
    type UndecidableReason,
} from "../styles/inventory";
import { cssColorTokens, parseCssColor, requiredMapValue, type Rgb } from "../styles/theme-map";
import { scanClassUsage, unsupportedEntryPoints } from "../styles/class-usage";

/*
 * contrast-invariant — DESIGN.md のテーマ色が読める組合せであることを機械検証する。
 *
 * 【検査範囲】不透明 (opaque) なテキストペアのみ。
 *   - 面 (neutral / surface) の上のテキスト色
 *   - 塗り面 (primary / danger 等) の上のラベル色 (DESIGN.md §Components: bg-* + text-neutral)
 *
 * 【閾値】一律 4.5:1。
 *   WCAG 2.2 SC 1.4.3 (AA) には「大きな文字は 3:1」の緩和があるが、
 *   **トークン単位の gate は文字サイズを知り得ない**ため緩和は採らず、
 *   厳しい側 (通常文字基準) を一律適用する。これは WCAG の要求そのものではなく
 *   本プロジェクトの設計判断である。
 *
 * 【検査しないもの】inventory.ts の PENDING_CONTRAST_PAIRS を参照
 *   (非テキスト 1.4.11 / alpha 合成)。「gate があるからコントラストは守られている」
 *   という誤読を作らないため、未検査であることを明示宣言してある。
 *
 *   加えて `resources/views/vendor/mail/html/themes/template.css` は**対象外**。
 *   同ファイルは Laravel 同梱メールテーマの独立パレット (`.button-red` = #dc2626、
 *   `.button-green` = #16a34a 等) を直書きしており、DESIGN.md トークンの写像ではない。
 *   メール HTML は CSS 変数を使えないクライアントが多く、DS token 化するなら
 *   ビルド時展開の設計が別途要る (本バッチのスコープ外)。
 *   なお詳細設計 §施策 6 のリスク表は「メールテンプレに danger は含まれない」と
 *   書いているが、これは事実誤認 (実際は #dc2626 を直書きしている)。
 *   対象外という結論は変わらないため据え置いた。
 *
 * 色値そのものを変えるときは DESIGN.md / tokens.css を同一 PR で更新すること
 * (canonical-source-parity が drift を検出する)。
 */

const AA_NORMAL_TEXT = 4.5;

/**
 * sRGB チャンネルの線形化 (WCAG 2.x 相対輝度の定義)。**正規化済み (0..1) の値を受ける**。
 *
 * しきい値は **0.04045** を使う。WCAG 2.0 / 2.1 本文の 0.03928 は
 * **2022-02-22 の errata で訂正済み**で、IEC 61966-2-1 (sRGB) の正しい値が 0.04045 である。
 * **8bit の色値では判定結果は変わらない** (境界は 0.03928*255 = 10.02 と
 * 0.04045*255 = 10.31 の間にあり、整数のチャンネル値 10 と 11 のどちらも
 * 両しきい値の同じ側に落ちる)。正しい方へ揃えるだけの変更である。
 *
 * 純粋関数として切り出してあるのは、負のコントロールが**実装本体を呼ぶ**ためである
 * (8bit の全値で「両しきい値の判定が一致する」ことを確かめるだけの検査は実装を 1 度も
 * 呼ばないので、実装が 0.03928 のままでも緑になり正典 i13 を固定できない)。
 */
export function linearizeChannel(c: number): number {
    return c <= 0.04045 ? c / 12.92 : Math.pow((c + 0.055) / 1.055, 2.4);
}

function linearize(channel: number): number {
    return linearizeChannel(channel / 255);
}

/** RGB (0..255。丸めていない実数も受ける) → 相対輝度 (WCAG 2.x) */
function luminanceOfRgb(rgb: Rgb): number {
    return (
        0.2126 * linearize(rgb.r) + 0.7152 * linearize(rgb.g) + 0.0722 * linearize(rgb.b)
    );
}

/** 相対輝度 2 つ → コントラスト比。1.0〜21.0 */
function ratioOfLuminance(a: number, b: number): number {
    return (Math.max(a, b) + 0.05) / (Math.min(a, b) + 0.05);
}

/** #rrggbb → 相対輝度 (WCAG 2.x) */
function relativeLuminance(hex: string): number {
    return luminanceOfRgb({
        r: parseInt(hex.slice(1, 3), 16),
        g: parseInt(hex.slice(3, 5), 16),
        b: parseInt(hex.slice(5, 7), 16),
    });
}

/** コントラスト比 (WCAG 2.x)。1.0〜21.0 */
export function contrastRatio(a: string, b: string): number {
    return ratioOfLuminance(relativeLuminance(a), relativeLuminance(b));
}

const colors = designColors();

function hex(token: string): string {
    const value = colors.get(token);
    if (value === undefined) throw new Error(`DESIGN.md colors に ${token} が無い`);
    return value;
}

/** 検査対象ペア: [前景トークン, 背景トークン, 文脈] */
const PAIRS: readonly (readonly [string, string, string])[] = [
    // 面ロールとテキストロールは素 (下の「両集合が素」テストが固定する) なので、
    // 自己ペア (同一トークン同士 = 比 1.0) は構造上生じない。
    // 素であることを型の widen による自己ペア除外 filter で暗黙に扱わず、
    // 独立した不変条件として明示的に検査する。
    ...TEXT_ON_SURFACE_TOKENS.flatMap((fg) =>
        SURFACE_ROLE_TOKENS.map((bg) => [fg, bg, "面上のテキスト"] as const),
    ),
    ...FILL_LABEL_TOKENS.flatMap((fg) =>
        FILL_TOKENS.map((bg) => [fg, bg, "塗り面のラベル"] as const),
    ),
    // 個別宣言ペアも直積と**同じ閾値**を課す (正典 i14)。
    ...DECLARED_CONTRAST_PAIRS.map((p) => [p.fg, p.bg, "個別宣言ペア"] as const),
];

describe("architecture/contrast-invariant: 不透明ペアのテキストコントラスト (一律 4.5:1)", () => {
    it("役割宣言が DESIGN.md の全色トークンを覆う (deny-by-default)", () => {
        // 分類の全数性は COLOR_TOKEN_ROLES **だけ**で見る。
        // 個別宣言ペアに現れることを「分類済み」と数えると、任意の新 token を
        // 1 組登録するだけで既定拒否を通せてしまう。
        expect(
            Object.keys(COLOR_TOKEN_ROLES).sort(),
            "未分類の色トークン、または DESIGN.md に存在しないトークンの宣言がある。" +
                "tests/js/styles/inventory.ts の COLOR_TOKEN_ROLES で分類すること",
        ).toEqual(Object.keys(COLOR_TOKEN_MAP).sort());

        for (const [token, roles] of Object.entries(COLOR_TOKEN_ROLES)) {
            expect(roles.length, `${token}: 役割が 0 件`).toBeGreaterThan(0);
            // 同じ役割の重複登録を拒否する (導出した直積に重複ペアが生じるのを防ぐ)
            expect(new Set(roles).size, `${token}: 役割が重複している`).toBe(roles.length);
        }
    });

    it("non-text-boundary の役割と理由の集合が一致する (理由だけ残る / 役割だけ足す を落とす)", () => {
        expect(Object.keys(NON_TEXT_BOUNDARY_REASONS).sort()).toEqual(
            [...tokensWithRole("non-text-boundary")].sort(),
        );
        for (const [token, reason] of Object.entries(NON_TEXT_BOUNDARY_REASONS)) {
            expect(reason.length, `${token}: 理由`).toBeGreaterThan(30);
        }
    });

    it("検査対象ペアが 0 件でない (空振り防止)", () => {
        expect(PAIRS.length).toBeGreaterThan(0);
    });

    it("面ロールとテキストロールが素である (自己ペア = 比 1.0 が混入しない)", () => {
        // PAIRS は両集合の直積を取るので、重複トークンがあると
        // 「自分自身の上の自分」という無意味なペア (常に 1.0 で必ず fail) が生まれる。
        // 将来あるトークンが面とテキストの両方の役割を持つなら、
        // PAIRS の作り方 (直積) の見直しが要る — それをここで検知する。
        const surfaces = new Set<string>(SURFACE_ROLE_TOKENS);
        const overlap = TEXT_ON_SURFACE_TOKENS.filter((t) => surfaces.has(t));
        expect(
            overlap,
            `SURFACE_ROLE_TOKENS と TEXT_ON_SURFACE_TOKENS が重複している: ${overlap.join(", ")}。` +
                `直積で自己ペアが生じるので PAIRS の構築方法を見直すこと`,
        ).toEqual([]);
    });

    it("未検査宣言 (PENDING_CONTRAST_PAIRS) が空でない", () => {
        // 「gate があるからコントラストは守られている」という誤読を防ぐ宣言そのものが
        // 消し飛ばされないよう固定する。
        // 出口: 1.4.11 / alpha 合成に対応して pending が空になったら、
        // inventory.ts の宣言と本 it を **同時に削除**すること
        // (空の宣言と、空でないことを確かめるテストを残すと形骸化する)。
        expect(PENDING_CONTRAST_PAIRS.length).toBeGreaterThan(0);
    });

    it.each(PAIRS)("[opaque text] %s on %s (%s) が 4.5:1 以上", (fg, bg, context) => {
        const ratio = contrastRatio(hex(fg), hex(bg));
        expect(
            ratio,
            `${context}: text-${fg} on bg-${bg} = ${ratio.toFixed(2)}:1。` +
                `DESIGN.md の色値を見直すこと (ペア集合を縮めて green にしないこと)`,
        ).toBeGreaterThanOrEqual(AA_NORMAL_TEXT);
    });

    it("負のコントロール: 線形化のしきい値が errata 後の 0.04045 である", () => {
        // 2 つのしきい値の**間**の値でだけ実装の差が出る。
        //   c = 0.04 → 0.04045 実装は線形枝 = 0.04 / 12.92
        //              0.03928 実装は pow 枝  = ((0.04 + 0.055) / 1.055) ** 2.4
        // 実装本体 (linearizeChannel) を呼ぶので、0.03928 のままならこの toBe が落ちる。
        expect(linearizeChannel(0.04)).toBe(0.04 / 12.92);
        // 両しきい値の外側では当然一致する (この it が「何でも通る」形でないことの裏取り)。
        expect(linearizeChannel(0.03)).toBe(0.03 / 12.92);
        expect(linearizeChannel(0.5)).toBeCloseTo(Math.pow((0.5 + 0.055) / 1.055, 2.4), 12);
    });

    it("補助: errata のしきい値の差が 8bit では判定を変えない", () => {
        // 「揃えたら結果が変わった」= どちらかの実装が間違っていたことになるので、
        // 変わらないことを 8bit の全チャンネル値で固定する。
        // これは**性質の検査**であって実装のしきい値は固定しない (上の it が固定する)。
        for (let channel = 0; channel <= 255; channel += 1) {
            const c = channel / 255;
            expect(c <= 0.03928, `channel=${channel}`).toBe(c <= 0.04045);
        }
    });

    /* 負のコントロール: 計算器が実際に点灯することを既知値で確認する */
    it("負のコントロール: 既知の低コントラスト対を検出し、既知の高コントラスト対は通す", () => {
        expect(contrastRatio("#ffffff", "#ffffff")).toBeCloseTo(1, 5);
        expect(contrastRatio("#000000", "#ffffff")).toBeCloseTo(21, 5);
        // red-600 (#dc2626) on neutral (#f4f4f5) = 4.39 — 是正前の実測値。4.5 を割る
        expect(contrastRatio("#dc2626", "#f4f4f5")).toBeLessThan(AA_NORMAL_TEXT);
        // red-700 (#b91c1c) on neutral = 5.89 — 是正後
        expect(contrastRatio("#b91c1c", "#f4f4f5")).toBeGreaterThanOrEqual(AA_NORMAL_TEXT);
    });
});

/*
 * ===== 半透明背景 × 不透明文字の合成 (正典 i16) =====
 *
 * 【本 gate が採用する**近似モデル** (版や環境で変わりうるので gate 本体に書く)】
 *   1. 不透明度修飾は `color-mix(…, transparent)` へ展開され、**透明との混色は
 *      同じ色の alpha になる** (透明側の乗算済み色が寄与しないため色相・明度は変わらない)。
 *      alpha を値に持つ token にさらに修飾が付く形は**実効 alpha が積**になる。
 *      生成形そのものは tokens.test.ts の「H. 不透明度修飾の生成形」が固定する
 *   2. 合成は**チャンネルごとの `a*FG + (1-a)*BG`** で、ガンマ符号化された sRGB 値を
 *      直接ブレンドする (web の既定)
 *   3. 比の計算に使うのは **8bit へ丸めた値**である。丸めまで再現しないと
 *      docs/design-system.md の記録値と 0.01 ずれる
 *   これは「ブラウザが必ずこう描く」という主張ではない。**本 gate が判定に使う近似**であり、
 *   近似が判定を変えていないことは「丸めない合成との比が 4.5 の境界を跨がない」検査が別に固定する。
 *   広い色域 (Display P3 等) の実描画との厳密一致は**測っていない** (家系の未決論点 q3)。
 *
 * 【下地について】**下地は宣言しない**。実在する不透明な下地 = 役割分類の「面」
 *   (`SURFACE_ROLE_TOKENS`) の**すべて**の上で 4.5:1 を要求するので、部品がどちらに
 *   置かれても成立する。**「面」と「テキストを載せる塗り」は別物である** —
 *   `border` は Button の hover 塗りとしてテキストを載せるが、容器の背景として宣言された
 *   用途は無いので「面」ではなく、半透明の合成の**下地には数えない**。
 *   下地に数えると、実際には起きない重ね方 (ソフト背景のバッジを Button の hover 塗りの上へ
 *   置く) を根拠にテーマ値の是正を要求することになる。
 *   この線引きは**宣言であって導出ではない** (静的走査は親要素を辿れない = 正典 i22 (2))。
 *   ソフト背景の部品を面以外の上へ置かないことは DESIGN.md §状態色の規約行が受ける。
 *
 * 【本 gate が保証しないもの】走査単位をまたいで成立する組 / 親から渡る class /
 *   親要素から継承する背景 / 実行時に組み立てられる class
 *   (正本は tests/js/styles/class-usage.ts の docblock)。
 */

/** 合成の入力は**完全に正規化してから**渡す (alpha の出所を 1 つにする)。 */
interface ResolvedAlphaBackground {
    readonly rgb: Rgb;
    readonly effectiveAlpha: number;
}

/**
 * **token 固有 alpha と class 修飾を合成する唯一の場所**である。
 *
 * 引数は**百分率** (`modifierPercent`)、戻り値は **0..1 の実効値** (`effectiveAlpha`) で、
 * 名前が単位を表す。正規化の規則は 1 本である —
 *   `effectiveAlpha = (token の値が持つ alpha ?? 1) × ((modifierPercent ?? 100) / 100)`
 */
function resolveAlphaBackground(
    suffix: CssColorSuffix,
    modifierPercent: number | null,
): ResolvedAlphaBackground {
    const parsed = parseCssColor(
        requiredMapValue(cssColorTokens(), suffix, `--color-${suffix}`),
    );
    const tokenAlpha = parsed.kind === "alpha" ? parsed.alpha : 1;

    return { rgb: parsed.rgb, effectiveAlpha: tokenAlpha * ((modifierPercent ?? 100) / 100) };
}

/** suffix 空間の不透明な色を RGB で取る (前景と下地に使う)。 */
function opaqueRgb(suffix: string): Rgb {
    const parsed = parseCssColor(requiredMapValue(cssColorTokens(), suffix, `--color-${suffix}`));
    if (parsed.kind !== "opaque") throw new Error(`--color-${suffix} が不透明色ではない`);

    return parsed.rgb;
}

/** DESIGN.md の色キー → tokens.css の suffix。 */
function toSuffix(designKey: string): string {
    const suffix = (COLOR_TOKEN_MAP as Readonly<Record<string, string>>)[designKey];
    if (suffix === undefined) throw new Error(`COLOR_TOKEN_MAP に ${designKey} が無い`);

    return suffix;
}

/** `ParsedColor` を直接受けない (alpha の出所を 1 つにする)。`round` を切ると近似の裏取りになる。 */
function compositeOverOpaque(
    background: ResolvedAlphaBackground,
    base: Rgb,
    round: boolean,
): Rgb {
    const mix = (fg: number, bg: number): number => {
        const value = background.effectiveAlpha * fg + (1 - background.effectiveAlpha) * bg;

        return round ? Math.round(value) : value;
    };

    return {
        r: mix(background.rgb.r, base.r),
        g: mix(background.rgb.g, base.g),
        b: mix(background.rgb.b, base.b),
    };
}

function alphaPairRatio(pair: AlphaPair, baseDesignKey: string, round: boolean): number {
    const background = resolveAlphaBackground(pair.bg, pair.modifierPercent);
    const composite = compositeOverOpaque(background, opaqueRgb(toSuffix(baseDesignKey)), round);

    return ratioOfLuminance(luminanceOfRgb(opaqueRgb(pair.fg)), luminanceOfRgb(composite));
}

/** 既知の値だけで比を出す (負のコントロール用。台帳にも写像にも依存しない)。 */
function ratioOfComposite(fgHex: string, bgHex: string, alpha: number, baseHex: string): number {
    const hexRgb = (hex: string): Rgb => ({
        r: parseInt(hex.slice(1, 3), 16),
        g: parseInt(hex.slice(3, 5), 16),
        b: parseInt(hex.slice(5, 7), 16),
    });
    const composite = compositeOverOpaque(
        { rgb: hexRgb(bgHex), effectiveAlpha: alpha },
        hexRgb(baseHex),
        true,
    );

    return ratioOfLuminance(luminanceOfRgb(hexRgb(fgHex)), luminanceOfRgb(composite));
}

/** キーの一意性を確かめる (同じキーを複数行へ分割して集合一致を誤魔化せないようにする)。 */
function expectUnique<T>(rows: readonly T[], key: (row: T) => readonly unknown[]): void {
    const keys = rows.map((row) => JSON.stringify(key(row)));
    expect(new Set(keys).size, `台帳のキーが重複している: ${keys.join(", ")}`).toBe(keys.length);
}

describe("architecture/contrast-invariant: 半透明背景 × 不透明文字 (面のすべての上で 4.5:1)", () => {
    const scan = scanClassUsage();

    it("走査で見つかった半透明の組と使用箇所台帳が (ファイル, 組, 修飾, 件数) で完全一致する", () => {
        const counted = new Map<string, number>();
        for (const pair of scan.pairs) {
            if (pair.kind !== "alpha-background") continue;
            const key = `${pair.file}|${pair.fg}|${pair.bg}|${pair.modifierPercent ?? "-"}`;
            counted.set(key, (counted.get(key) ?? 0) + 1);
        }
        const declared = new Map<string, number>(
            ALPHA_PAIR_USAGE_LEDGER.map((row) => [
                `${row.file}|${row.fg}|${row.bg}|${row.modifierPercent ?? "-"}`,
                row.count,
            ]),
        );
        expect(counted.size, "半透明の組が 1 件も取れない (走査の空振り)").toBeGreaterThan(0);
        expect(
            Object.fromEntries([...counted].sort()),
            "走査結果と ALPHA_PAIR_USAGE_LEDGER が食い違っている (台帳を更新すること)",
        ).toEqual(Object.fromEntries([...declared].sort()));
    });

    it("判定不能の単位と台帳が (ファイル, 理由, 件数) の完全一致で揃う", () => {
        const counted = new Map<string, number>();
        for (const pair of scan.pairs) {
            if (pair.kind !== "undecidable") continue;
            const key = `${pair.file}|${pair.reason}`;
            counted.set(key, (counted.get(key) ?? 0) + 1);
        }
        const declared = new Map<string, number>(
            UNDECIDABLE_PAIR_LEDGER.map((row) => [`${row.file}|${row.reason}`, row.count]),
        );
        expect(counted.size, "判定不能が 1 件も取れない (走査の空振り)").toBeGreaterThan(0);
        expect(
            Object.fromEntries([...counted].sort()),
            "走査結果と UNDECIDABLE_PAIR_LEDGER が食い違っている (台帳を更新すること)",
        ).toEqual(Object.fromEntries([...declared].sort()));
    });

    it("台帳の理由が UndecidableReason の値域に収まり、分類が全数である (never で収束)", () => {
        const known = new Set<string>(UNDECIDABLE_REASONS.map((r) => r.id));
        for (const row of UNDECIDABLE_PAIR_LEDGER) {
            expect(known.has(row.reason), `${row.file}: 未知の理由 ${row.reason}`).toBe(true);
            expect(row.note.length, `${row.file}: 理由の説明`).toBeGreaterThan(0);
        }
        // 分類の網羅を never へ収束させる (値域に足したら必ずここが赤くなる)。
        const label = (reason: UndecidableReason): string => {
            switch (reason) {
                case "foreground-alpha":
                case "keyword-color":
                case "alpha-background-no-text":
                case "opaque-and-alpha-background":
                case "multiple-background":
                case "multiple-foreground":
                case "element-opacity":
                case "interpolated":
                case "variant-composition":
                    return reason;
                default: {
                    const exhaustive: never = reason;

                    return exhaustive;
                }
            }
        };
        expect(UNDECIDABLE_REASONS.map((r) => label(r.id))).toEqual(
            UNDECIDABLE_REASONS.map((r) => r.id),
        );
    });

    it("台帳の行が一意で、件数と修飾率が値域に収まる", () => {
        // 集合 + 件数の比較は、同じキーを複数行へ分割したり count: 0 を登録したりすると
        // 正規化のしかた次第で意図しない一致が起きる。
        // キーの一意性と値域を独立した不変条件として固定する。
        expectUnique(ALPHA_PAIR_USAGE_LEDGER, (r) => [r.file, r.fg, r.bg, r.modifierPercent]);
        expectUnique(UNDECIDABLE_PAIR_LEDGER, (r) => [r.file, r.reason]);
        for (const row of [...ALPHA_PAIR_USAGE_LEDGER, ...UNDECIDABLE_PAIR_LEDGER]) {
            expect(Number.isInteger(row.count) && row.count > 0, `${row.file}: count`).toBe(true);
        }
        for (const row of ALPHA_PAIR_USAGE_LEDGER) {
            const m = row.modifierPercent;
            expect(
                m === null || (Number.isInteger(m) && m >= 0 && m <= 100),
                `${row.file}: modifierPercent`,
            ).toBe(true);
        }
    });

    it("distinctPairs の仕様 (重複除去・並び順・キー生成) を固定検体で固定する", () => {
        // 「射影と ALPHA_CONTRAST_PAIRS が集合一致する」は導出しているので恒真に近い。
        // 共通規約 (d) の形骸化に当たるため置かず、導出関数そのものを固定する。
        const fixture: readonly AlphaPair[] = [
            { fg: "primary", bg: "primary-soft", modifierPercent: null },
            { fg: "primary", bg: "primary-soft", modifierPercent: null },
            { fg: "primary", bg: "primary-soft", modifierPercent: 40 },
            { fg: "danger", bg: "danger", modifierPercent: 10 },
        ];
        // 並び順はキー文字列 (`fg|bg|修飾率、修飾なしは "-"`) の昇順である。
        expect(distinctPairs(fixture)).toEqual([
            { fg: "danger", bg: "danger", modifierPercent: 10 },
            { fg: "primary", bg: "primary-soft", modifierPercent: null },
            { fg: "primary", bg: "primary-soft", modifierPercent: 40 },
        ]);
        // 修飾率 null と 0 は別のキーになる (null を 0 へ潰さない)
        expect(
            distinctPairs([
                { fg: "primary", bg: "primary", modifierPercent: null },
                { fg: "primary", bg: "primary", modifierPercent: 0 },
            ]).length,
        ).toBe(2);
    });

    it("意味ペアが 0 件でない (空振り防止)", () => {
        expect(ALPHA_CONTRAST_PAIRS.length).toBeGreaterThan(0);
        expect(SURFACE_ROLE_TOKENS.length).toBeGreaterThan(0);
    });

    it.each(ALPHA_CONTRAST_PAIRS)(
        "[alpha bg] %o が面のすべての上で 4.5:1 以上",
        ({ fg, bg, modifierPercent }) => {
            for (const base of SURFACE_ROLE_TOKENS) {
                const ratio = alphaPairRatio({ fg, bg, modifierPercent }, base, true);
                expect(
                    ratio,
                    `text-${fg} on bg-${bg}${modifierPercent === null ? "" : `/${modifierPercent}`} ` +
                        `over ${base} = ${ratio.toFixed(2)}:1。` +
                        `DESIGN.md の色値を見直すこと (ペア集合を縮めて green にしないこと)`,
                ).toBeGreaterThanOrEqual(AA_NORMAL_TEXT);
            }
        },
    );

    /**
     * 是正前の値で AA を割っていた **5 組**を既知値として固定する (正典 i18 (d))。
     *
     * 台帳からも写像からも独立した**リテラルだけ**で書く — 合成器そのものが
     * 「何を渡しても 4.5 以上を返す」形に退行したことを検出するための負のコントロールであり、
     * 同時に「なぜテーマ値を 1 段暗くしたのか」の根拠を機械可読な形で残す。
     * 各行は `[前景 hex, 背景 hex, 実効 alpha, 下地 hex, 是正後の前景/背景 hex]`。
     */
    const PRE_CORRECTION_FAILURES = [
        ["primary-soft", "#2563eb", 0.12, "#f4f4f5", "#1d4ed8"],
        ["primary/10", "#2563eb", 0.1, "#f4f4f5", "#1d4ed8"],
        ["success/10", "#15803d", 0.1, "#f4f4f5", "#166534"],
        ["warning/10", "#b45309", 0.1, "#f4f4f5", "#92400e"],
        ["tertiary/10", "#0f766e", 0.1, "#f4f4f5", "#115e59"],
    ] as const;

    it.each(PRE_CORRECTION_FAILURES)(
        "負のコントロール: 是正前の %s は AA を割り、是正後は通る",
        (_label, before, alpha, base, after) => {
            expect(ratioOfComposite(before, before, alpha, base)).toBeLessThan(AA_NORMAL_TEXT);
            expect(ratioOfComposite(after, after, alpha, base)).toBeGreaterThanOrEqual(
                AA_NORMAL_TEXT,
            );
        },
    );

    it("負のコントロール: danger は是正前の値でも soft 背景で通る (一律に暗くしたのではない)", () => {
        // 「5 組だけが未達だった」という実測の裏取り。danger は 2026-08 に red-700 へ
        // 是正済みだったので据え置いた。
        expect(ratioOfComposite("#b91c1c", "#b91c1c", 0.1, "#f4f4f5")).toBeGreaterThanOrEqual(
            AA_NORMAL_TEXT,
        );
    });

    it("負のコントロール: 8bit の丸めを省くと比がずれる (丸めが判定に効いている)", () => {
        const pair: AlphaPair = { fg: "primary", bg: "primary-soft", modifierPercent: null };
        const rounded = alphaPairRatio(pair, "neutral", true);
        const exact = alphaPairRatio(pair, "neutral", false);
        expect(rounded).not.toBe(exact);
        expect(rounded).toBeCloseTo(exact, 1);
    });

    it("近似の裏取り: 丸めない合成との比が 4.5 の境界を跨ぐ組が無い", () => {
        // 8bit へ丸める近似が**判定そのものを変えていない**ことを固定する。
        // 跨ぐ組が現れたら、その組は近似の当否に判定が依存しているので、
        // 近似モデルの側を見直す契機になる (緩める理由にはしない)。
        for (const pair of ALPHA_CONTRAST_PAIRS) {
            for (const base of SURFACE_ROLE_TOKENS) {
                const rounded = alphaPairRatio(pair, base, true);
                const exact = alphaPairRatio(pair, base, false);
                expect(
                    rounded >= AA_NORMAL_TEXT,
                    `${pair.fg} on ${pair.bg} over ${base}`,
                ).toBe(exact >= AA_NORMAL_TEXT);
            }
        }
    });
});

/*
 * ===== 実装からの逆向き被覆 (正典 i15) =====
 *
 * 役割の宣言を書かずに新しい前景 × 背景の組を足す経路を塞ぐ。
 * 走査器 (tests/js/styles/class-usage.ts) は CSS suffix 空間を返すので、
 * COLOR_TOKEN_MAP の逆写像で DESIGN.md の色キー空間へ写してから母集団と突き合わせる
 * (逆写像が一意であることは canonical-source-parity が固定する)。
 *
 * **解決できなかった class トークン (`resolution.kind === "unresolved"`) を 0 件に固定するのは
 * token-reference-closure.test.ts (参照の閉包) の担当である** — 同じ主張を 2 つの gate へ
 * 書くと、片方を緩めたときにもう片方が残っていることが分かりにくくなる
 * (責務境界は docs/design-system.md の表が正本)。
 */

/** CSS suffix → DESIGN.md の色キー (逆写像)。 */
function toDesignKey(suffix: string): string {
    const found = Object.entries(COLOR_TOKEN_MAP).find(([, value]) => value === suffix);
    if (found === undefined) throw new Error(`COLOR_TOKEN_MAP の逆写像に ${suffix} が無い`);

    return found[0];
}

describe("architecture/contrast-invariant: 実装からの逆向き被覆 (i15)", () => {
    const scan = scanClassUsage();

    it("走査の分母が空でない (ディレクトリ単位の走査が生きている)", () => {
        // 非空を要求するのは `requiresOccurrences: true` の子だけである
        // (全件へ要求すると、抽出 0 件が正常な lib / types / 直下ファイルで必ず赤になる)。
        expect(scan.files.length).toBeGreaterThan(0);
        expect([...scan.perDirectory.keys()].sort()).toEqual(
            Object.keys(JS_SCAN_CHILD_CLASSIFICATION).sort(),
        );
        for (const [dir, spec] of Object.entries(JS_SCAN_CHILD_CLASSIFICATION)) {
            if (!spec.requiresOccurrences) continue;
            expect(scan.perDirectory.get(dir), `${dir} から 1 件も抽出できていない`).toBeGreaterThan(
                0,
            );
        }
    });

    it("走査で得た不透明ペアがすべて母集団 (役割の直積 + 個別宣言) の内側にある", () => {
        const population = new Set(PAIRS.map(([fg, bg]) => `${fg}|${bg}`));
        const scanned = [
            ...new Set(
                scan.pairs.flatMap((p) =>
                    p.kind === "opaque" ? [`${toDesignKey(p.fg)}|${toDesignKey(p.bg)}`] : [],
                ),
            ),
        ].sort();
        expect(scanned.length, "不透明ペアが 1 件も取れない (走査の空振り)").toBeGreaterThan(0);
        expect(
            scanned.filter((pair) => !population.has(pair)),
            "役割宣言に無い前景 × 背景の組が実装に現れた。" +
                "COLOR_TOKEN_ROLES へ役割を足すか、直積で表現できないなら " +
                "DECLARED_CONTRAST_PAIRS へ理由つきで登録すること",
        ).toEqual([]);
    });

    it("既知の要求組が抽出結果から実際に生成される (抽出の空振り防止)", () => {
        // Badge の soft 背景 (全 tone) と Button の塗り面が、走査結果に実際に現れることを固定する。
        // 分解の**意味**まで見るのは class-usage.test.ts の担当で、ここは
        // 「実リポジトリの走査からその組が消えていない」ことだけを見る。
        const alpha = new Set(
            scan.pairs.flatMap((p) =>
                p.kind === "alpha-background" ? [`${p.fg}|${p.bg}|${p.modifierPercent ?? "-"}`] : [],
            ),
        );
        const opaque = new Set(
            scan.pairs.flatMap((p) => (p.kind === "opaque" ? [`${p.fg}|${p.bg}`] : [])),
        );
        for (const required of [
            "primary|primary-soft|-",
            "tertiary|tertiary|10",
            "success|success|10",
            "warning|warning|10",
            "danger|danger|10",
        ]) {
            expect(alpha.has(required), `Badge の soft 背景 ${required} が抽出できていない`).toBe(
                true,
            );
        }
        for (const required of ["neutral|primary", "neutral|danger", "text|border"]) {
            expect(opaque.has(required), `Button の組 ${required} が抽出できていない`).toBe(true);
        }
    });

    it("走査器が扱えない既知の入口が 0 件である", () => {
        expect(unsupportedEntryPoints()).toEqual([]);
    });

    it("個別宣言ペアが 5 条を満たす (直積の既定拒否を迂回できない)", () => {
        const declaredBackgrounds = new Set<string>(DECLARED_CONTRAST_PAIRS.map((p) => p.bg));
        const scanned = new Set(
            scan.pairs.flatMap((p) => (p.kind === "opaque" ? [`${p.fg}|${p.bg}`] : [])),
        );
        expectUnique(DECLARED_CONTRAST_PAIRS, (p) => [p.fg, p.bg]);
        for (const p of DECLARED_CONTRAST_PAIRS) {
            expect(rolesOf(p.bg), `${p.bg}: 背景側の役割`).toContain("declared-text-background");
            expect(rolesOf(p.bg), `${p.bg}: 直積で受けられる背景は個別宣言にしない`).not.toContain(
                "surface",
            );
            expect(rolesOf(p.bg), `${p.bg}: 直積で受けられる背景は個別宣言にしない`).not.toContain(
                "fill",
            );
            expect(
                rolesOf(p.fg).some((r) => r === "text-on-surface" || r === "fill-label"),
                `${p.fg}: 前景側の役割`,
            ).toBe(true);
            expect(p.reason.length, `${p.fg} on ${p.bg}: 理由`).toBeGreaterThan(30);
            // 実装に存在しない個別宣言ペアを足せないようにする (走査は suffix 空間なので写す)
            expect(
                scanned.has(
                    `${(COLOR_TOKEN_MAP as Readonly<Record<string, string>>)[p.fg]}|` +
                        `${(COLOR_TOKEN_MAP as Readonly<Record<string, string>>)[p.bg]}`,
                ),
                `${p.fg} on ${p.bg}: 実装に 1 件も無い個別宣言ペア`,
            ).toBe(true);
        }
        // 役割だけ宣言して組を書かない = 死んだ宣言を作らせない
        for (const token of tokensWithRole("declared-text-background")) {
            expect(declaredBackgrounds.has(token), `${token}: 役割はあるが個別宣言ペアが無い`).toBe(
                true,
            );
        }
    });
});
