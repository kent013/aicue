import type { DraftPoint, DraftStep } from "@/types/manual";

/**
 * シナリオ編集の undo/redo 履歴ユーティリティ(純関数)。
 * 1 エントリ = ScenarioEditor.serializeSteps() の正規化 JSON 文字列
 * (clientKey + id + 本文 8 フィールド + points)。
 * サイズ上限は件数と総文字数の二本立て (メモリ非有界化の防止)。
 */

/** 履歴の最大エントリ数 */
export const MAX_HISTORY_ENTRIES = 100;
/** 履歴の総文字数ソフト上限 (≈ 数 MB。単一エントリが超えても保持する) */
export const MAX_HISTORY_CHARS = 2_000_000;

/** serializeSteps が出力する行 shape (DraftPoint と構造一致。id は number|null) */
export type SerializedRow = DraftPoint;
/** serializeSteps が出力する step shape (DraftStep と構造一致) */
export type SerializedStep = DraftStep;

/**
 * スタック(古→新)を上限内に収める(破壊的 in-place。同一参照を返す)。
 * 先頭(最古)から捨てるが、length>1 を保持し単一エントリでは空にしない
 * (= MAX_HISTORY_CHARS はソフト上限)。
 */
export function boundHistory(
    stack: string[],
    maxEntries: number = MAX_HISTORY_ENTRIES,
    maxChars: number = MAX_HISTORY_CHARS,
): string[] {
    let chars = stack.reduce((total, entry) => total + entry.length, 0);
    while (stack.length > 1 && (stack.length > maxEntries || chars > maxChars)) {
        const removed = stack.shift();
        if (removed === undefined) break;
        chars -= removed.length;
    }
    return stack;
}

/**
 * before が current と異なるときのみ before を stack に積み、上限を適用する。
 * 積んだら true(呼び出し側は true のとき redo スタックをクリアする)。
 */
export function pushHistory(stack: string[], before: string, current: string): boolean {
    if (before === current) return false;
    stack.push(before);
    boundHistory(stack);
    return true;
}

/** 履歴 1 行 (clientKey + id + 本文 8 フィールド) の type predicate */
function isSerializedRow(value: unknown): value is SerializedRow {
    if (value === null || typeof value !== "object") return false;
    const r = value as Record<string, unknown>;
    return (
        typeof r.clientKey === "string" &&
        r.clientKey.length > 0 && // 空文字 clientKey は keyed each を壊すため拒否
        (r.id === null || typeof r.id === "number") &&
        typeof r.scene === "string" &&
        (r.shot_type === "hiki" || r.shot_type === "yori") &&
        (r.shooting_point === null || typeof r.shooting_point === "string") &&
        typeof r.narration === "string" &&
        (r.subtitle_primary === null || typeof r.subtitle_primary === "string") &&
        typeof r.subtitle_secondary === "string" &&
        (r.material_type === null || r.material_type === "video" || r.material_type === "still") &&
        (r.static_display_seconds === null || typeof r.static_display_seconds === "number")
    );
}

/** step (row + points 配列) の type predicate */
function isSerializedStep(value: unknown): value is SerializedStep {
    if (!isSerializedRow(value)) return false;
    const points = (value as { points?: unknown }).points; // 未知プロパティ読取の局所 cast
    return Array.isArray(points) && points.every(isSerializedRow);
}

/**
 * 履歴文字列 → 検証済み SerializedStep[] の防御的デコーダ。
 * JSON 破損・shape 不一致は null(呼び出し側 fail-safe が履歴を破棄)。
 * 素の型アサーションをデータ経路に残さない。
 */
export function parseHistorySnapshot(serialized: string): SerializedStep[] | null {
    let parsed: unknown;
    try {
        parsed = JSON.parse(serialized);
    } catch {
        return null;
    }
    if (!Array.isArray(parsed)) return null;
    const steps: SerializedStep[] = [];
    const keys = new Set<string>();
    for (const step of parsed) {
        if (!isSerializedStep(step)) return null;
        // clientKey は復元対象全体 (step + 全 point) で一意。重複は keyed each を壊すため拒否
        if (keys.has(step.clientKey)) return null;
        keys.add(step.clientKey);
        for (const point of step.points) {
            if (keys.has(point.clientKey)) return null;
            keys.add(point.clientKey);
        }
        steps.push(step);
    }
    return steps;
}
