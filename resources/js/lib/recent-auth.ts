import { addToast } from "@/lib/stores/toast";

/**
 * recent-auth step-up (再認証) の共通クライアントヘルパ。
 *
 * 機微操作 (API キー発行/失効・アカウント削除・オーナー移譲) の前段 precheck を共通化する。
 * 最終ゲートはサーバ側 recent-auth middleware であり、本ヘルパは UX 補助にすぎない。
 *
 * - {@link fetchRecentAuthStatus}: `/recent-auth/status` を fresh に取得 (失敗時は null)。
 * - {@link withRecentAuth}: fresh なら action 即実行、stale なら再認証モーダル用の status を返し、
 *   status 取得失敗時は最終ゲート (middleware) へ委譲して action を直接実行する。
 */

/** RecentAuthStatusResource (backend) に対応する型 */
export interface AvailableReauthProvider {
    provider: string;
    capability: string;
    /** 再SSO 開始 URL (/auth/{provider}/redirect/step-up。OAuth フルリダイレクト) */
    reauthUrl: string;
}

export interface RecentAuthStatus {
    recent: boolean;
    passwordSet: boolean;
    availableProviders: AvailableReauthProvider[];
    canSatisfy: boolean;
    confirmedAt: number | null;
}

/**
 * recent-auth 状態を fresh に取得する。失敗時は null (呼び出し側は最終ゲート委譲にフォールバック)。
 */
export async function fetchRecentAuthStatus(): Promise<RecentAuthStatus | null> {
    try {
        const res = await fetch("/recent-auth/status", {
            headers: { Accept: "application/json", "X-Requested-With": "XMLHttpRequest" },
            credentials: "same-origin",
        });
        if (!res.ok) return null;
        const body = (await res.json()) as Partial<RecentAuthStatus>;
        if (typeof body.recent !== "boolean") return null;
        return {
            recent: body.recent,
            passwordSet: body.passwordSet ?? false,
            availableProviders: body.availableProviders ?? [],
            canSatisfy: body.canSatisfy ?? false,
            confirmedAt: body.confirmedAt ?? null,
        };
    } catch {
        return null;
    }
}

/**
 * 保護操作の前段ゲート。
 *
 * - fresh: `onFresh` を実行して終了。
 * - stale: `onStale(status)` を呼び、呼び出し側が再認証モーダルを開く。
 * - status 取得失敗 (network/5xx/parse) = delegated: 状態不明でモーダルを出せないため
 *   middleware の最終ゲートに委譲する (既定は `onFresh` にフォールバック。
 *   別動作が必要な画面は `onDelegated` を明示指定する)。
 *
 * 戻り値は実行した分岐 (テスト・呼び出し側の分岐確認用)。
 */
export async function withRecentAuth(handlers: {
    onFresh: () => void;
    onStale: (status: RecentAuthStatus) => void;
    /** status 取得失敗時の委譲動作。未指定なら onFresh にフォールバック (最終ゲート委譲)。 */
    onDelegated?: () => void;
}): Promise<"fresh" | "stale" | "delegated"> {
    const status = await fetchRecentAuthStatus();
    if (status === null) {
        addToast("info", "再認証が必要な場合は確認ページへ移動します。");
        (handlers.onDelegated ?? handlers.onFresh)();
        return "delegated";
    }
    if (status.recent) {
        handlers.onFresh();
        return "fresh";
    }
    handlers.onStale(status);
    return "stale";
}
