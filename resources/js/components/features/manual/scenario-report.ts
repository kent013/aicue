import type { BadgeTone } from "@/components/atoms/Badge.types";
import type { ScenarioRuleCode, ScenarioVerdict } from "@/types/manual";

/**
 * 「生成結果の確認」パネルの表示語彙 (ラベル / tone) と整形。
 * ドメイン型 (types/manual.ts) から UI atom 型への依存をここで受け止め、
 * types 側が atom を知らない状態を保つ (features 層の presentation helper)。
 */

/** satisfies で verdict / code 追加時のキー漏れをコンパイル時に検出する */
export const SCENARIO_VERDICT_LABELS = {
    valid: "マニュアルとして有効",
    needs_review: "確認が必要な箇所があります",
    invalid: "このままでは元資料として不十分",
} as const satisfies Record<ScenarioVerdict, string>;

export const SCENARIO_VERDICT_TONES = {
    valid: "success",
    needs_review: "warning",
    invalid: "danger",
} as const satisfies Record<ScenarioVerdict, BadgeTone>;

/** 指摘ラベル (規則そのものを言い切る。原因を断定しない文言にする) */
export const SCENARIO_RULE_LABELS = {
    narration_missing: "ナレーションが空のカット",
    narration_not_polite: "ナレーションが「です・ます」調で終わっていないカット",
    narration_directive: "ナレーションに「ください」が入っているカット",
    subtitle_primary_sentence: "字幕①が名称・数値でなく文になっている可能性のあるカット",
    subtitle_secondary_missing: "字幕②が空のカット",
} as const satisfies Record<ScenarioRuleCode, string>;

/**
 * 位置の整形。「手順 2」/「急所 2-3」(編集画面の読み上げ表記と同じ)。
 * **count は positions.length と別に受け取る** — positions は先頭 5 件で打ち切られており、
 * 「ほか」を出すかは総件数でしか判定できないため。
 */
export function formatPositions(
    positions: { step: number; point: number | null }[],
    count: number,
): string {
    const labels = positions.map((p) =>
        p.point === null ? `手順 ${p.step}` : `急所 ${p.step}-${p.point}`,
    );

    return count > positions.length ? `${labels.join(" / ")} ほか` : labels.join(" / ");
}
