import { captureJson } from "@/lib/capture/http";
import type { CaptureManualDetail } from "@/types/capture";

/**
 * 採用済みテイクの入室時自動ダウンロード・オーケストレータ (T051 / 概念設計 D6)。
 *
 * 責務: `CaptureManualDetail` を受け取り → 未 DL の採用テイクを列挙 → 順次
 * `videoFetcher`(実バイト完読) + ACK (`POST .../downloaded`) を行い、ACK 成功件数を
 * 戻り値 `changed` に集約する。reload の実行判断は呼び出し側 (Show.svelte) が `changed` を
 * 見て行う (コールバックは設けず戻り値に一本化)。
 *
 * サーバ変更なし: 既存 `POST takes.downloaded` (ACK・冪等) と詳細 GET payload を変更しない。
 * `downloaded_at` はワークフロー単位のグローバル同期状態であり端末単位ではない
 * (手動 window.open と同一意味・同一 ACK 経路)。
 *
 * `upload-queue.ts` と同じく依存注入 (`videoFetcher`/`ackFetch`/`delay`/`isOnline`) で
 * テスト可能にする。
 */

/** videoFetcher の判別可能 union (取得成功/各種失敗を厳密に区別する) */
export type FetchOutcome =
    | { ok: true }
    | { ok: false; reason: "http"; status: number }
    | { ok: false; reason: "network" | "aborted" | "size_mismatch" };

export interface AutoDownloadOptions {
    /** 既定: 本物の fetch + body 完読実装 (fetchAndDrain) */
    videoFetcher?: (url: string) => Promise<FetchOutcome>;
    /** 既定: captureJson (X-XSRF-TOKEN 付き・419 再取得は http.ts が担う) */
    ackFetch?: typeof captureJson;
    /** backoff 待機 (テストで即時解決に差し替える) */
    delay?: (ms: number) => Promise<void>;
    /** navigator.onLine の参照 (テスト差し替え用) */
    isOnline?: () => boolean;
    /** 初回試行に加える再試行回数 (総試行 = 1 + maxRetries)。既定 2 → 総 3 回 */
    maxRetries?: number;
    /** 指数 backoff の基準ミリ秒 (delay(2**attempt * baseDelayMs)) */
    baseDelayMs?: number;
}

export interface AutoDownloadResult {
    /** ACK 成功が 1 件でもあれば true (呼び出し側はこの時のみ reload を 1 回行う) */
    changed: boolean;
    /** fetch 成功済みで ACK 未達の take が残るか (将来の軽量再試行フック用) */
    hasPendingAck: boolean;
}

/** 列挙で確定した DL 対象 (playback_url / ack_token は非 null に絞り込み済み) */
interface DownloadTarget {
    cutId: number;
    takeId: number;
    playbackUrl: string;
    ackToken: string;
}

/**
 * 本物の fetch + body 完読実装 (既定 videoFetcher)。
 * - `credentials: "omit"` + カスタムヘッダ無し (cookie 非送信 + CORS preflight 回避)。
 * - body は ReadableStream を drain (chunk 読み捨て・一括保持しない = メモリ配慮)。
 *   stream 非提供環境は arrayBuffer() フォールバック。
 * - size 検査は Content-Encoding 無し + Content-Length 有効数値のときのみ (補助・条件付き)。
 */
async function fetchAndDrain(url: string): Promise<FetchOutcome> {
    let response: Response;
    try {
        response = await fetch(url, { credentials: "omit" });
    } catch (error) {
        return toFailureReason(error);
    }
    if (!response.ok) {
        return { ok: false, reason: "http", status: response.status };
    }

    let received: number;
    try {
        received = await drainBody(response);
    } catch (error) {
        return toFailureReason(error);
    }

    // size 検査 (補助): Content-Encoding 付きは復号後サイズと転送サイズが不一致になり得るため検査しない。
    const encoding = response.headers.get("Content-Encoding");
    if (encoding === null || encoding === "") {
        const lengthHeader = response.headers.get("Content-Length");
        // 非数値/負数 (先頭 - を含む) は /^\d+$/ で除外、非安全整数は isSafeInteger で除外
        if (lengthHeader !== null && /^\d+$/.test(lengthHeader)) {
            const contentLength = Number(lengthHeader);
            if (Number.isSafeInteger(contentLength) && received !== contentLength) {
                return { ok: false, reason: "size_mismatch" };
            }
        }
    }
    return { ok: true };
}

/** response body を最後まで読み切り、総バイト数を返す (空応答=0 も成功許容) */
async function drainBody(response: Response): Promise<number> {
    if (response.body === null) {
        // jsdom/古環境等で stream 非提供: arrayBuffer() で全読 (メモリ制約あり)
        const buffer = await response.arrayBuffer();
        return buffer.byteLength;
    }
    const reader = response.body.getReader();
    let received = 0;
    for (;;) {
        const { done, value } = await reader.read();
        if (done) break;
        if (value !== undefined) received += value.byteLength;
    }
    return received;
}

/** 例外/中断を判別 union へ変換 (AbortError のみ aborted、他は network) */
function toFailureReason(error: unknown): FetchOutcome {
    if (error instanceof DOMException && error.name === "AbortError") {
        return { ok: false, reason: "aborted" };
    }
    return { ok: false, reason: "network" };
}

export class AdoptedTakeAutoDownloader {
    private readonly projectId: number;
    private readonly manualId: number;
    private readonly videoFetcher: (url: string) => Promise<FetchOutcome>;
    private readonly ackFetch: typeof captureJson;
    private readonly delay: (ms: number) => Promise<void>;
    private readonly isOnline: () => boolean;
    private readonly maxRetries: number;
    private readonly baseDelayMs: number;

    /** fetch を完読成功した take id (同一セッションで再 fetch しない) */
    private readonly fetchSucceeded = new Set<number>();
    /** fetch 成功済みだが ACK 未成功の take id (再 fetch せず ACK のみ再試行対象) */
    private readonly ackPending = new Set<number>();
    /** run() の多重起動防止 (onMount と online 復帰の二重発火を単一化) */
    private running = false;

    constructor(projectId: number, manualId: number, options: AutoDownloadOptions = {}) {
        this.projectId = projectId;
        this.manualId = manualId;
        this.videoFetcher = options.videoFetcher ?? fetchAndDrain;
        this.ackFetch = options.ackFetch ?? captureJson;
        this.delay = options.delay ?? ((ms) => new Promise((resolve) => setTimeout(resolve, ms)));
        this.isOnline = options.isOnline ?? (() => navigator.onLine);
        // 負値は無意味なので 0 にクランプ (総試行 = 1 + maxRetries は 1 以上を保証)
        this.maxRetries = Math.max(0, options.maxRetries ?? 2);
        this.baseDelayMs = Math.max(0, options.baseDelayMs ?? 1000);
    }

    /** 未 DL 採用テイクを順次 fetch+ACK。ACK 成功が 1 件でもあれば changed=true */
    async run(manual: CaptureManualDetail): Promise<AutoDownloadResult> {
        // 多重起動防止: 実行中は即 return (二重に fetch を走らせない)
        if (this.running) {
            return { changed: false, hasPendingAck: this.ackPending.size > 0 };
        }
        // オフラインは失敗ではない: fetch も ACK も呼ばず return
        if (!this.isOnline()) {
            return { changed: false, hasPendingAck: this.ackPending.size > 0 };
        }

        this.running = true;
        try {
            const targets = this.collectTargets(manual);
            this.sweepTombstones(targets);

            let changed = false;
            // 順次 (直列): 帯域配慮のため 1 件ずつ処理 (並列 fetch しない)
            for (const target of targets) {
                if (await this.processTarget(target)) {
                    changed = true;
                }
            }
            return { changed, hasPendingAck: this.ackPending.size > 0 };
        } finally {
            this.running = false;
        }
    }

    /** 採用 && ready && 未 DL && playback_url≠null && ack_token≠null のテイクを列挙 */
    private collectTargets(manual: CaptureManualDetail): DownloadTarget[] {
        const targets: DownloadTarget[] = [];
        for (const cut of manual.cuts) {
            if (cut.adopted_take_id === null) continue;
            for (const take of cut.takes) {
                if (take.id !== cut.adopted_take_id) continue;
                if (take.status !== "ready") continue;
                if (take.downloaded) continue;
                if (take.playback_url === null) continue;
                if (take.download_ack_token === null) continue;
                targets.push({
                    cutId: cut.id,
                    takeId: take.id,
                    playbackUrl: take.playback_url,
                    ackToken: take.download_ack_token,
                });
            }
        }
        return targets;
    }

    /**
     * 墓石掃除: 現在の対象集合に無い take id を fetchSucceeded/ackPending から除去する
     * (manual 更新で採用差し替え・削除された take の墓石が残らないようにする)。
     */
    private sweepTombstones(targets: DownloadTarget[]): void {
        const targetIds = new Set(targets.map((target) => target.takeId));
        for (const id of [...this.fetchSucceeded]) {
            if (!targetIds.has(id)) this.fetchSucceeded.delete(id);
        }
        for (const id of [...this.ackPending]) {
            if (!targetIds.has(id)) this.ackPending.delete(id);
        }
    }

    /** 1 件の対象を fetch(未成功時) → ACK。ACK 成功で true (changed 集計用) */
    private async processTarget(target: DownloadTarget): Promise<boolean> {
        if (!this.fetchSucceeded.has(target.takeId)) {
            const fetched = await this.fetchWithRetry(target.playbackUrl);
            if (!fetched) {
                // fetch 失敗: fetchSucceeded に入れない (次トリガ=online/再入室で再取得可)
                return false;
            }
            this.fetchSucceeded.add(target.takeId);
            this.ackPending.add(target.takeId);
        }
        // 既に ACK 済み (ackPending から除去済み) なら再 ACK しない
        if (!this.ackPending.has(target.takeId)) {
            return false;
        }
        if (await this.ackWithRetry(target)) {
            this.ackPending.delete(target.takeId);
            return true;
        }
        return false;
    }

    /** videoFetcher を有界リトライ (総 1 + maxRetries 回)。完読成功で true */
    private async fetchWithRetry(url: string): Promise<boolean> {
        for (let attempt = 0; ; attempt++) {
            const outcome = await this.videoFetcher(url);
            if (outcome.ok) return true;
            // 判別 union の網羅性を switch + never チェックで担保 (any 混入予防)
            switch (outcome.reason) {
                case "http":
                case "network":
                case "aborted":
                case "size_mismatch":
                    break;
                default: {
                    // outcome は default で never に narrow 済み (判別 union 網羅性の担保)
                    const exhaustive: never = outcome;
                    return exhaustive;
                }
            }
            if (attempt >= this.maxRetries) return false;
            await this.delay(2 ** attempt * this.baseDelayMs);
        }
    }

    /** ACK を有界リトライ (総 1 + maxRetries 回)。response.ok で true */
    private async ackWithRetry(target: DownloadTarget): Promise<boolean> {
        const url = `/app/projects/${this.projectId}/manuals/${this.manualId}/cuts/${target.cutId}/takes/${target.takeId}/downloaded`;
        for (let attempt = 0; ; attempt++) {
            let ok = false;
            try {
                const response = await this.ackFetch(url, "POST", { ack_token: target.ackToken });
                ok = response.ok;
            } catch {
                ok = false;
            }
            if (ok) return true;
            if (attempt >= this.maxRetries) return false;
            await this.delay(2 ** attempt * this.baseDelayMs);
        }
    }
}
