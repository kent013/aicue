import { router } from "@inertiajs/svelte";
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
    /**
     * パスキーで再認証できるか (登録済み credential が 1 件以上ある)。
     * **ログイン可否とは別**: 2要素認証が有効なユーザーはパスキーでログインできないが、
     * 再認証には使える。
     */
    passkeyAvailable: boolean;
    canSatisfy: boolean;
    confirmedAt: number | null;
}

/** provider 要素を strict に検証する (欠落は「SSO ボタンが出ない」詰みになる) */
function parseProvider(value: unknown): AvailableReauthProvider | null {
    if (typeof value !== "object" || value === null) return null;
    const { provider, capability, reauthUrl } = value as Record<string, unknown>;
    if (typeof provider !== "string") return null;
    if (typeof capability !== "string") return null;
    if (typeof reauthUrl !== "string") return null;
    return { provider, capability, reauthUrl };
}

/**
 * `/recent-auth/status` の応答を **strict に** 検証する。
 *
 * 既定値による補完はしない: field が欠けた応答を既定値で埋めると
 * 「サーバは手段があると言っているのに UI に出ない」= 監査 F-1 と同じ詰みが
 * **通信境界で再演**する (call-site gate では検出できない)。
 * 契約不成立は null にし、withRecentAuth の delegated 経路 (= サーバの最終ゲートへ委譲) に倒す。
 */
export function parseRecentAuthStatus(body: unknown): RecentAuthStatus | null {
    if (typeof body !== "object" || body === null) return null;
    const { recent, passwordSet, availableProviders, passkeyAvailable, canSatisfy, confirmedAt } =
        body as Record<string, unknown>;
    if (typeof recent !== "boolean") return null;
    if (typeof passwordSet !== "boolean") return null;
    if (typeof passkeyAvailable !== "boolean") return null;
    if (typeof canSatisfy !== "boolean") return null;
    if (confirmedAt !== null && typeof confirmedAt !== "number") return null;
    if (!Array.isArray(availableProviders)) return null;

    const providers: AvailableReauthProvider[] = [];
    for (const raw of availableProviders) {
        const parsed = parseProvider(raw);
        if (parsed === null) return null;
        providers.push(parsed);
    }

    return {
        recent,
        passwordSet,
        availableProviders: providers,
        passkeyAvailable,
        canSatisfy,
        confirmedAt,
    };
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
        return parseRecentAuthStatus(await res.json());
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

/** RecentAuthRequiredDto::CODE と対 (code 厳格一致で自分宛て応答のみ処理する) */
const RECENT_AUTH_REQUIRED_CODE = "recent_auth_required";
/** 遷移を許す唯一の着地 (サーバ由来 URL を無検証でグローバル遷移に使わない) */
export const RECENT_AUTH_CONFIRM_PATH = "/recent-auth/confirm";

/**
 * XHR 応答が recent-auth の 409 契約か。**status だけでは判定しない**。
 *
 * 同じ 409 を `RequireTwoFactorForEnforcedOrganizations` も返す
 * (`code: "two_factor_required"`) ため、status のみの判定は**誤食する**。
 * body の形状は信用せず unknown から絞り込む型ガードにする
 * (parseRecentAuthStatus と同じ流儀)。
 *
 * Inertia visit 側の判定 (recentAuthRedirectTarget) と同じ定数を共有し、
 * 判定点を 2 つ作らない。
 */
export function isRecentAuthRequiredPayload(status: number, body: unknown): boolean {
    if (status !== 409) return false;
    if (typeof body !== "object" || body === null) return false;
    return (body as Record<string, unknown>).code === RECENT_AUTH_REQUIRED_CODE;
}

/**
 * `httpException` の `event.detail.response` (Inertia core の `HttpExceptionResponse` =
 * `{ status, data, headers }`。`data` は JSON なら parse 済みオブジェクト、
 * それ以外は生文字列) から confirm 画面への遷移先を取り出す。
 *
 * 受入条件を満たさないものは null を返し、呼び出し側は preventDefault しない (fail-closed)。
 */
function recentAuthRedirectTarget(response: unknown): string | null {
    if (typeof response !== "object" || response === null) return null;
    const { status, data } = response as { status?: unknown; data?: unknown };
    if (status !== 409) return null;
    if (typeof data !== "object" || data === null) return null;
    const { code, redirect } = data as Record<string, unknown>;
    if (code !== RECENT_AUTH_REQUIRED_CODE) return null; // 他の 409 契約を誤食しない
    if (typeof redirect !== "string") return null;
    // same-origin かつ既知 path のみ (外部 URL / 別 route への誘導を構造的に不能にする)
    let url: URL;
    try {
        url = new URL(redirect, window.location.origin);
    } catch {
        return null;
    }
    if (url.origin !== window.location.origin) return null;
    if (url.pathname !== RECENT_AUTH_CONFIRM_PATH) return null;
    return url.pathname + url.search;
}

/**
 * recent-auth 鮮度切れの 409 を confirm 画面への Inertia visit に変換する。
 *
 * precheck (withRecentAuth) を通れない経路 = status 取得失敗・契約不成立 (delegated) では
 * 元操作がそのまま飛び、サーバ (RequireRecentAuth) が 409 + `recent_auth_required` を返す。
 * 誰も拾わないと Inertia の既定 (エラーモーダル表示) になり **無言の行き止まり**になるため、
 * ここで単一のハンドラに集約する。
 *
 * **購読するイベントは `httpException`**: 詳細設計は Inertia v1/v2 の `invalid` を前提に
 * していたが、本リポジトリの @inertiajs/core 3.3.1 に `invalid` は存在せず、
 * 非 Inertia 応答 (4xx/5xx) の cancelable イベントは `httpException` に統合されている
 * (`Response#handleNonInertiaResponse`)。`preventDefault()` で既定のエラーモーダルを抑止する
 * 意味論は同一。
 *
 * サーバ側は 409 を返す際に `url.intended` と `recent_auth.dropped_mutation` を保存するため
 * (RequireRecentAuth)、confirm 成功後は元画面へ戻り「操作は未実行」の案内が出る。
 *
 * @returns 購読解除関数 (HMR の二重登録防止に使う)
 */
export function registerRecentAuthRedirectHandler(): () => void {
    return router.on("httpException", (event) => {
        const target = recentAuthRedirectTarget(event.detail.response);
        if (target === null) return;
        event.preventDefault();
        void router.visit(target);
    });
}
