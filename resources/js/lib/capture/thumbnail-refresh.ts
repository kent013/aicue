import type { CaptureManualDetail } from "@/types/capture";

/**
 * サムネイル生成の完了を撮影画面へ反映するための**有界な**再取得スケジューラ。
 *
 * サムネイルはテイク登録の応答より後に出来るため、登録直後の has_thumbnail は必ず false になる。
 * 放置すると画面を離れて戻るまで反映されない (doc/05 の撮影後確認が成立しない)。
 *
 * 設計上の制約 (無制限ポーリングにしない):
 * - **監視するのはこの端末がこのセッションで登録した client_take_id だけ**。
 *   画面差分で現れた ID は追わない = 別端末が同じマニュアルを撮っていても巻き込まれず、
 *   **サムネイルを持たない過去分のテイクで再取得が走ることもない**
 * - 停止条件は 4 つ: 監視集合が空 / 試行上限 / 画面が非表示 / stop()
 * - 再取得中は次の再取得を始めない (single-flight。呼び出し側の reload も同じ 1 本を通す)
 *
 * **有界性の単位 (誇張しない)**: 試行予算は集合全体で 1 本持ち、**新しいテイクを watch した時点で
 * リセットされる** (新しい録画には新しい予算を与えるのが意図)。したがって
 * 「画面全体で最大 4 回」ではなく「**最後に監視集合へ追加されたテイクを起点に最大 4 回 (~29 秒)**」が
 * 保証の単位である (既に監視中の ID を再度 watch しても予算は戻らない = 早期 return する。
 * キュー再開で複数件を連続追加した場合は、**最後に追加された ID** を起点に集合全体の予算が更新される)。
 * 撮影を続ける限り予算は更新され続けるが、撮影を止めれば必ず 4 回で停止する。
 */
const INTERVALS_MS = [2_000, 4_000, 8_000, 15_000] as const;

export class ThumbnailRefreshScheduler {
    private readonly watched = new Set<string>();
    private attempt = 0;
    private timer: ReturnType<typeof setTimeout> | null = null;
    private running = false;
    private paused = false;
    private stopped = false;

    /** @param reload 画面側の single-flight な再取得 (完了で解決する Promise を返す) */
    constructor(private readonly reload: () => Promise<void>) {}

    /** この端末が登録に成功したテイクを監視対象へ merge する (既存集合は消さない) */
    watch(clientTakeId: string): void {
        if (this.stopped || this.watched.has(clientTakeId)) return;
        this.watched.add(clientTakeId);
        this.attempt = 0; // 新しい録画には新しい試行予算を与える
        // ★ 旧予算で予約済みの発火は持ち越さない。残しておくと「最後に watch した時点を起点に
        //   最大 4 回」という保証の単位が崩れる (予約済みの 1 回ぶんだけ超える)。
        this.clearTimer();
        this.schedule();
    }

    /** 最新の manual で監視集合を更新する (完了後の最新スナップショットだけで判断する) */
    sync(manual: CaptureManualDetail): void {
        if (this.stopped) return;
        for (const id of [...this.watched]) {
            const take = manual.cuts
                .flatMap((cut) => cut.takes)
                .find((t) => t.client_take_id === id);
            // 見つからない (削除された) / サムネイルが付いた → 監視終了
            if (take === undefined || take.has_thumbnail) this.watched.delete(id);
        }
        if (this.watched.size === 0) {
            this.clearTimer();
            this.attempt = 0;
            return;
        }
        this.schedule();
    }

    pause(): void {
        this.paused = true;
        this.clearTimer();
    }

    resume(): void {
        this.paused = false;
        this.schedule();
    }

    stop(): void {
        this.stopped = true;
        this.clearTimer();
        this.watched.clear();
    }

    private schedule(): void {
        if (this.stopped || this.paused || this.running || this.timer !== null) return;
        if (this.watched.size === 0 || this.attempt >= INTERVALS_MS.length) return;

        const delay = INTERVALS_MS[this.attempt];
        this.attempt += 1;
        this.timer = setTimeout(() => {
            this.timer = null;
            void this.run();
        }, delay);
    }

    private async run(): Promise<void> {
        if (this.stopped || this.paused) return;
        this.running = true;
        try {
            await this.reload();
        } catch {
            // 失敗しても監視対象は消さない (残りの試行へ進む)
        } finally {
            this.running = false;
        }
        // 停止・unmount 後に到着した完了処理は状態を変更しない
        if (!this.stopped) this.schedule();
    }

    private clearTimer(): void {
        if (this.timer === null) return;
        clearTimeout(this.timer);
        this.timer = null;
    }
}
