/**
 * Lightweight ASCII-borderless table formatter.
 *
 * Designed for the read-side CLI commands (`uxi projects`, `uxi sites`, …) so
 * the output matches the samples in
 * `devnotes/20260409-0815-api-key-cli/cli-commands.md` —
 * leading-space indent, no border characters, two-space gutter, ragged-right
 * cells. Numeric columns are right-aligned because the design samples align
 * IDs and scores on their right edge for skim-readability.
 *
 * Cells are coerced to strings; `null`/`undefined` render as the configured
 * empty marker (default: em dash). Long cells are not truncated — terminal
 * wrapping is preferred over silent data loss because the CLI is also used in
 * CI logs where truncation hides issues.
 */
export type TableAlign = "left" | "right";

export type TableColumn = {
    header: string;
    align?: TableAlign;
};

export type TableOptions = {
    /**
     * Rendered when a cell value is `null` / `undefined` / empty string.
     * Defaults to the en dash so the column still aligns visually.
     */
    emptyMarker?: string;
    /**
     * Two-space leading indent matches the cli-commands.md samples.
     */
    indent?: string;
    /**
     * Two spaces between columns is enough to scan rows without visually
     * merging short cells (e.g. integer IDs).
     */
    gutter?: string;
};

const DEFAULT_EMPTY = "—";
const DEFAULT_INDENT = "  ";
const DEFAULT_GUTTER = "  ";

function cellToString(
    value: unknown,
    emptyMarker: string,
): string {
    if (value === null || value === undefined) return emptyMarker;
    if (typeof value === "string") return value === "" ? emptyMarker : value;
    if (typeof value === "number") {
        if (Number.isNaN(value)) return emptyMarker;
        return String(value);
    }
    if (typeof value === "boolean") return value ? "yes" : "no";
    return String(value);
}

/**
 * Visual width: counts surrogate pairs as 1 codepoint and CJK wide chars as
 * 2 columns so Japanese names ("料金ページ") align with ASCII.
 *
 * The classification is intentionally conservative — it only widens
 * codepoints that fall in the historic East Asian Wide ranges that show up in
 * the design samples. Anything outside those ranges is treated as 1 column,
 * matching the behaviour of every common terminal we target.
 */
function visualWidth(str: string): number {
    let w = 0;
    for (const ch of str) {
        const cp = ch.codePointAt(0);
        if (cp === undefined) continue;
        if (
            (cp >= 0x1100 && cp <= 0x115f)
            || (cp >= 0x2e80 && cp <= 0x303e)
            || (cp >= 0x3041 && cp <= 0x33ff)
            || (cp >= 0x3400 && cp <= 0x4dbf)
            || (cp >= 0x4e00 && cp <= 0x9fff)
            || (cp >= 0xa000 && cp <= 0xa4cf)
            || (cp >= 0xac00 && cp <= 0xd7a3)
            || (cp >= 0xf900 && cp <= 0xfaff)
            || (cp >= 0xfe30 && cp <= 0xfe4f)
            || (cp >= 0xff00 && cp <= 0xff60)
            || (cp >= 0xffe0 && cp <= 0xffe6)
        ) {
            w += 2;
        } else {
            w += 1;
        }
    }
    return w;
}

function pad(
    cell: string,
    width: number,
    align: TableAlign,
): string {
    const filler = " ".repeat(Math.max(0, width - visualWidth(cell)));
    return align === "right" ? filler + cell : cell + filler;
}

export type TableRow = ReadonlyArray<unknown>;

/**
 * Format a list of rows as a borderless table. Returns the multiline string
 * (no trailing newline) so callers can add their own trailing context (e.g.
 * footer hints).
 */
export function formatTable(
    columns: ReadonlyArray<TableColumn>,
    rows: ReadonlyArray<TableRow>,
    opts: TableOptions = {},
): string {
    const empty = opts.emptyMarker ?? DEFAULT_EMPTY;
    const indent = opts.indent ?? DEFAULT_INDENT;
    const gutter = opts.gutter ?? DEFAULT_GUTTER;

    const stringRows: string[][] = rows.map((row) =>
        columns.map((_, i) => cellToString(row[i], empty)),
    );
    const headerCells = columns.map((c) => c.header);

    const widths = columns.map((_, i) => {
        let max = visualWidth(headerCells[i] ?? "");
        for (const r of stringRows) {
            const w = visualWidth(r[i] ?? "");
            if (w > max) max = w;
        }
        return max;
    });

    const renderRow = (cells: ReadonlyArray<string>): string => {
        const parts = cells.map((cell, i) =>
            pad(cell, widths[i] ?? 0, columns[i]?.align ?? "left"),
        );
        return (indent + parts.join(gutter)).trimEnd();
    };

    const lines: string[] = [renderRow(headerCells)];
    for (const r of stringRows) lines.push(renderRow(r));
    return lines.join("\n");
}

/**
 * Convenience wrapper for the empty-list case — keeps the message wording
 * consistent across commands ("No projects found.", "No sites found.", …).
 */
export function formatEmptyList(resourcePlural: string): string {
    return `No ${resourcePlural} found.`;
}
