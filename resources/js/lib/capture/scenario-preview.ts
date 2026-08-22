import { takeUrl } from "@/lib/capture/take-endpoints";
import type { CaptureCut } from "@/types/capture";

/**
 * 撮影 PWA の通し再生 (全体連結プレビュー) の再生リストと状態機械。
 *
 * 方式の決定 (端末側連結再生 / サーバ生成プレビューを撮影者に開かない) と、
 * ここで固定する契約の根拠は devnotes/20260816-1754-capture-full-scenario-preview/。
 *
 * **この面は素材の選択判定を持たない**。どのテイクを再生するかは
 * サーバの `AdoptedReadyTakeCoverage` が決め、`cut.adopted_ready_take_id` として渡ってくる
 * (adopted_take_id と take.status からここで組み立て直さない = T148 の二重化を作らない)。
 *
 * 判断はここ (純関数)、配線とメディア要素の操作は component
 * (landscape-capture.ts / panel-navigation.ts と同じ役割分担)。
 */

/** 再生リストの 1 件 (クリップ = 再生する / 欠落 = プレースホルダを出す) */
export type PreviewEntry =
    | {
          kind: "clip";
          cutId: number;
          takeId: number;
          label: string;
          subtitlePrimary: string | null;
          subtitleSecondary: string;
          /** capture.takes.playback の URL (takeUrl が唯一の導出元) */
          src: string;
      }
    | {
          kind: "missing";
          cutId: number;
          label: string;
          subtitlePrimary: string | null;
          subtitleSecondary: string;
      };

/** 再生状態 (可視性とは**直交**する) */
export type ClipState = "loading" | "playing" | "paused" | "blocked" | "failed" | "placeholder";

export interface PreviewState {
    /** 再生リスト内の位置 (0 起点)。entries.length に達したら finished */
    index: number;
    /** 非同期結果の受付世代。index の前進・スキップ・終了のたびに +1 する */
    generation: number;
    clip: ClipState;
    /** ページが表示されているか (可視性の軸) */
    visible: boolean;
    /** 直近に「進捗があった」時刻 (ms)。停滞判定の起点 */
    progressAt: number;
    /** 全カットを見終わったか */
    finished: boolean;
}

export interface PreviewEvent {
    type:
        | "progress" // timeupdate / progress / canplay 等の前進イベント
        | "playing"
        | "paused" // 利用者の一時停止
        | "resumed" // 利用者の再生
        | "ended"
        | "error" // media error / 404
        | "blocked" // 自動再生制限と判定できる play() 拒否
        | "retry" // 「再生を続ける」
        | "skip" // 「このカットをスキップ」
        | "hidden"
        | "shown"
        | "tick"; // 時間経過の通知 (停滞監視・プレースホルダ尺)
    /** 発生元の世代。省略時は現在世代とみなす (利用者操作など同期的なもの) */
    generation?: number;
    /** イベント時刻 (ms) */
    at: number;
}

export interface PreviewOptions {
    entries: PreviewEntry[];
    /** プレースホルダの表示秒数 (サーバの preview_placeholder_seconds と同じ値) */
    placeholderSeconds: number;
    /** 停滞と判定するまでの無進捗時間 (ms) */
    stallTimeoutMs?: number;
}

/**
 * 停滞判定の既定閾値。
 *
 * **この値が「正しい」ことは主張しない**。固定するのは「監視条件を満たす限り有限時間で
 * 必ず次へ進む」ことだけで、閾値そのものは実地の観測が出るまで動かさない
 * (仕組みが機能していない段階で値を弄らない)。現場のモバイル回線で先頭バッファに
 * 時間がかかることを想定して保守的に置く。
 */
export const PREVIEW_STALL_TIMEOUT_MS = 20_000;

/**
 * 再生リストを組み立てる。並び順は props の cuts の順 (= サーバの表示順: 手順 → 配下の急所) をそのまま使う。
 * ラベルは buildCutLabels の結果を受け取る (規則をここで再実装しない)。
 */
export function buildPreviewEntries(
    cuts: CaptureCut[],
    labels: Record<number, string>,
    target: { organizationSlug: string; projectId: number; manualId: number },
): PreviewEntry[] {
    return cuts.map((cut): PreviewEntry => {
        const label = labels[cut.id] ?? "カット";
        const takeId = cut.adopted_ready_take_id;
        if (takeId === null) {
            return {
                kind: "missing",
                cutId: cut.id,
                label,
                subtitlePrimary: cut.subtitle_primary,
                subtitleSecondary: cut.subtitle_secondary,
            };
        }

        return {
            kind: "clip",
            cutId: cut.id,
            takeId,
            label,
            subtitlePrimary: cut.subtitle_primary,
            subtitleSecondary: cut.subtitle_secondary,
            src: takeUrl(
                {
                    organizationSlug: target.organizationSlug,
                    projectId: target.projectId,
                    manualId: target.manualId,
                    cutId: cut.id,
                },
                takeId,
                "/playback",
            ),
        };
    });
}

/** 使用できる採用テイクが無いカットの件数 (再生前の告知に使う。述語は持たない = null を数えるだけ) */
export function missingCount(entries: PreviewEntry[]): number {
    return entries.filter((entry) => entry.kind === "missing").length;
}

/**
 * 初期状態 (先頭 entry の種別で clip / placeholder が決まる)。
 *
 * **entries が空のときの `clip` は意味を持たない** — `finished: true` の状態では
 * UI も reducer も `clip` を読まない (reducer は先頭で `finished` を見て素通しする)。
 * 便宜上 `"placeholder"` を入れるが、**この値に依存する分岐を書かない**
 * (この約束は Vitest の「空リストでは finished かつどのイベントでも状態が変わらない」で固定する)。
 */
export function initialPreviewState(options: PreviewOptions, at: number): PreviewState {
    return {
        index: 0,
        generation: 0,
        clip: stateForEntry(options.entries[0]),
        visible: true,
        progressAt: at,
        finished: options.entries.length === 0,
    };
}

/**
 * 停滞監視を動かす条件。
 * **可視性 × 再生要求 × 状態**の 3 つが揃ったときだけ監視する
 * (一時停止・非表示・blocked・failed の間は監視しない = 誤って次へ進めない)。
 */
export function shouldWatchStall(state: PreviewState): boolean {
    return state.visible && !state.finished && (state.clip === "loading" || state.clip === "playing");
}

/**
 * 状態遷移。**現在世代と一致しない非同期結果は 1 ビットも状態を変えない**
 * (要素の入れ替えで生じる古い reject / error を誤って現在のクリップの失敗にしない)。
 */
export function reducePreview(
    state: PreviewState,
    event: PreviewEvent,
    options: PreviewOptions,
): PreviewState {
    if (state.finished) return state;
    if (event.generation !== undefined && event.generation !== state.generation) return state;
    // **非表示中はメディア由来のイベントを受け付けない**。実メディアを pause() しても、
    // 既にキューへ入った ended / error は到着しうるため、実要素の操作だけに依存しない
    // (非表示の間に勝手に次のカットへ進むのを構造で止める)。
    // 利用者操作 (skip / retry) と可視性 (hidden / shown) と時間 (tick) は常に処理する。
    if (!state.visible && isMediaOriginEvent(event.type)) return state;
    // **`failed` / `placeholder` は「見せてから次へ進むまでの待ち」であり、メディア由来の
    // イベントで延命・復帰させない**。失敗したクリップの要素はバッファリングを続けて
    // `progress` を出し続けうるため、受け付けると `progressAt` が更新され続けて
    // 尺の満了判定が永久に成立しない (= 停滞回収が空転して次のカットへ進めなくなる)。
    // 利用者操作 (skip / retry) と可視性と時間は引き続き処理する。
    if (isWaitingState(state.clip) && isMediaOriginEvent(event.type)) return state;

    switch (event.type) {
        case "hidden":
            return { ...state, visible: false };
        case "shown":
            // 再生状態は変えない (playing なら component が再開を試み、paused/blocked は維持)。
            // 進捗の起点だけ引き直す (非表示だった時間を停滞に数えない)。
            return { ...state, visible: true, progressAt: event.at };
        case "progress":
            return { ...state, progressAt: event.at };
        case "playing":
            return { ...state, clip: "playing", progressAt: event.at };
        case "paused":
            // **利用者操作由来の pause だけがここへ来る** (component が programmatic pause を送らない)。
            // 読み込み中に利用者が止めることもあるため loading からも受け付ける
            // (受け付けないと「止めたのに停滞監視が動き続けて failed になる」)。
            return state.clip === "playing" || state.clip === "loading"
                ? { ...state, clip: "paused" }
                : state;
        case "resumed":
            return state.clip === "paused" ? { ...state, clip: "loading", progressAt: event.at } : state;
        case "blocked":
            return { ...state, clip: "blocked" };
        case "retry":
            // 「再生を続ける」= もう一度読み込みからやり直す (再拒否ならまた blocked になる)
            return { ...state, clip: "loading", progressAt: event.at };
        case "error":
            return { ...state, clip: "failed", progressAt: event.at };
        case "ended":
        case "skip":
            return advance(state, options, event.at);
        case "tick":
            return onTick(state, options, event.at);
    }
}

/**
 * 時間経過: プレースホルダの尺満了と停滞判定の 2 つだけを見る。
 *
 * `failed` の表示待ちにも `placeholderSeconds` を流用する (**欠落と同じ長さで通過させる**)。
 * 別の設定値を新設しないのは、どちらも「見せてから次へ進むまでの待ち」であり、
 * 2 つ持つと必ず食い違うためである (値の意味は「プレースホルダ表示秒数」のまま)。
 */
function onTick(state: PreviewState, options: PreviewOptions, at: number): PreviewState {
    if (!state.visible) return state; // 非表示の間は尺も停滞も進めない
    if (state.clip === "placeholder" || state.clip === "failed") {
        return at - state.progressAt >= options.placeholderSeconds * 1000
            ? advance(state, options, at)
            : state;
    }
    if (!shouldWatchStall(state)) return state;
    const timeout = options.stallTimeoutMs ?? PREVIEW_STALL_TIMEOUT_MS;

    // 進捗が途切れたまま閾値を超えた → そのカットだけ失敗にする (通し再生は止めない)
    return at - state.progressAt >= timeout ? { ...state, clip: "failed", progressAt: at } : state;
}

/** 次の entry へ。**世代を必ず +1 する** (破棄したクリップの遅延イベントを無効化する) */
function advance(state: PreviewState, options: PreviewOptions, at: number): PreviewState {
    const next = state.index + 1;
    if (next >= options.entries.length) {
        return {
            ...state,
            index: next,
            generation: state.generation + 1,
            finished: true,
            progressAt: at,
        };
    }

    return {
        ...state,
        index: next,
        generation: state.generation + 1,
        clip: stateForEntry(options.entries[next]),
        progressAt: at,
    };
}

function stateForEntry(entry: PreviewEntry | undefined): ClipState {
    return entry?.kind === "clip" ? "loading" : "placeholder";
}

/**
 * メディア要素が起点のイベント (非表示中は受け付けない側)。
 * `Set<PreviewEvent["type"]>` が担保するのは**要素型の正当性**だけで、
 * **必要なイベントの登録漏れは検出しない** (漏れは Vitest が拾う)。
 */
const MEDIA_ORIGIN_EVENTS = new Set<PreviewEvent["type"]>([
    "progress",
    "playing",
    "paused",
    "resumed",
    "ended",
    "error",
    "blocked",
]);

function isMediaOriginEvent(type: PreviewEvent["type"]): boolean {
    return MEDIA_ORIGIN_EVENTS.has(type);
}

/** 「見せてから次へ進むまでの待ち」の状態 (尺が満了したら必ず前進する側) */
function isWaitingState(clip: ClipState): boolean {
    return clip === "failed" || clip === "placeholder";
}
