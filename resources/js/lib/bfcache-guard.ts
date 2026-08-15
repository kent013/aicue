/**
 * bfcache 秘匿・再検証 guard (詳細設計 施策 6 / P3-b)。
 *
 * 問題: Safari (撮影 PWA の主要プラットフォーム) は `Cache-Control: no-store` でも
 * ページを bfcache に格納しうる。ログアウト後に「戻る」で認証済み画面が復元されると
 * PII が再表示される。サーバ側の no-store baseline
 * (NoStoreCacheHeadersForAuthenticatedPages) だけでは塞げない。
 *
 * 方針: 「復元後に検証」ではなく **検証完了まで復元内容を秘匿**する。
 * 復元してから非同期検証すると、検証完了までの間 復元済みの古い DOM が表示され PII が
 * 一瞬露出する (「無効なら遷移する」は「再表示しない」と同義ではない)。
 *
 * ただし hard reload は常用しない。撮影中の media stream・未送信フォーム・Inertia 履歴を
 * 破棄してしまい、撮影 PWA という使命に直撃するため。有効なら **秘匿を外すだけ**にする。
 *
 * | # | 契機                     | 動作                                                        |
 * |---|--------------------------|-------------------------------------------------------------|
 * | 1 | pagehide                 | documentElement に秘匿属性を同期付与 (この DOM ごと bfcache へ) |
 * | 2 | pageshow (属性あり)      | まず同期判定 → 一致したときだけ軽量プローブ (/session/status)  |
 * | 3 | 同期判定が不一致・不明   | 秘匿を維持したまま同じ URL を読み直す (通信を待たない)         |
 * | 4 | 認証済み + 世代が一致    | 秘匿属性を外すだけ (DOM / フォーム / Inertia 履歴は温存)       |
 * | 5 | 認証済み + 世代が不一致  | 秘匿を維持したまま読み直す (別のセッションの文書だった)        |
 * | 6 | セッション無効           | login へ hard navigation (遷移先は固定の相対パス)             |
 * | 7 | プローブ失敗             | 秘匿維持 + 再試行ボタン表示 (自動再試行はしない)              |
 * | 8 | 再試行押下               | 現在 URL を hard reload (サーバに再判定させる)                |
 *
 * **開示 (秘匿の解除) に到達する経路はただ 1 本** — プローブが「認証済み **かつ**
 * 描画世代が現世代と一致」と答えたときだけである。同期判定は通信を待たずに
 * 「読み直す」へ倒すためだけにあり、開示を表す結論を持たない (型で表現している)。
 *
 * 読み直しは 1 つの文書につき高々 1 回で、ループにはならない (読み直した先の文書は
 * 復元ではないので秘匿属性を持たず、ガードは何もしない)。保証するのはここまでで、
 * 読み直し自体が通信障害で完了しないことは塞がない (既存の /login 置換遷移も同じ性質)。
 *
 * 復元マーカーは **documentElement の秘匿属性そのもの**。sessionStorage は使わない
 * (タブ単位で共有されるため、ページ A の pagehide が立てたフラグを通常遷移先のページ B が
 * 読んで誤って秘匿・プローブする)。属性なら bfcache 復元時だけ DOM ごと戻り、通常遷移では
 * サーバから来た新しい HTML に存在しない = 本質的に履歴エントリ単位のマーカーになる。
 *
 * 秘匿は DOM 表示に限定する (属性付与 + CSS)。DOM ツリーの破棄・再構築はしない。
 * 見た目 (オーバーレイ / 非表示) のスタイルは resources/css/app.css 側に置く (DS token 経由)。
 *
 * **担当範囲**: 本 guard が守るのは **Safari の真の bfcache 復元 (pagehide/pageshow)** だけ。
 * Inertia SPA のクライアント履歴復元 (popstate) は本 guard を発火させないため、
 * そちらは Inertia 公式機構 (bootstrap/app.php の EncryptHistory + Inertia::clearHistory() の
 * 発行契機 2 つ = LogoutResponse と bootstrap/app.php の AuthenticationException callback)
 * が担当する (bug-hunt F-4-01)。
 *
 * **ここに popstate フックを足さないこと。** 二重実装になるから、だけではない。
 * 「popstate のたびに /session/status をプローブして、無効なら秘匿する」案は
 * 技術的には成立する (popstate リスナは Inertia の非同期 swap より先に同期実行できる) が、
 * 設計フェーズで**却下済み**である:
 *   1. **目的を達しない**。塞げるのは履歴の過去 PII だけで、そのタブが**表示中**の PII は残る
 *      (セッションが切れたタブは既に認証済み DOM を描画したままである)。
 *   2. **通常の戻る/進むを毎回ネットワーク往復 + 秘匿オーバーレイで塞ぐ**。本 guard は
 *      プローブ失敗を「秘匿維持 + 再試行ボタン」に倒す設計 (fail-closed) なので、
 *      現場の不安定な回線では**通常の戻る操作が再試行画面で塞がれる = 新しい詰み**になる。
 *      撮影 PWA (/app/*) の戻るは主要導線であり、ここを重くするのは使命に反する。
 * セッション終了後の履歴復元は「認証失敗を契機にサーバが clearHistory を出す」側で塞ぐ
 * (docs/supported-browsers.md が正本)。
 *
 * ※ 本 docblock の更新は**挙動変更ではない**ため、docs/supported-browsers.md の
 *   「実機受入確認の再確認条件」のトリガには当たらない (トリガは挙動変更に限る)。
 *
 * なお Inertia も pageshow(persisted) で history を復号し直すが、それは**非同期**であり、
 * 復元 DOM は既に描画されている。「検証完了まで秘匿する」という本 guard の要件は
 * pagehide で**同期的に**秘匿属性を立てる本実装でしか満たせない (公式機構に pagehide は無い)。
 * ログアウト後の真の bfcache 復元では両者が同時に走るが、着地はどちらも /login で一致する
 * (guard の hard navigation が Inertia の XHR 再訪問を打ち切るだけ)。
 *
 * 3 経路 × 3 枚の網の全体像は docs/supported-browsers.md が正本。
 */

/** documentElement に付ける秘匿属性 = bfcache 復元マーカー兼 CSS スイッチ。 */
export const BFCACHE_HIDDEN_ATTRIBUTE = "data-bfcache-hidden";

/** 秘匿属性の値 (状態遷移を一意に表す)。 */
export const BFCACHE_STATE_PENDING = "pending";
export const BFCACHE_STATE_VERIFYING = "verifying";
export const BFCACHE_STATE_RETRY = "retry";
/** 世代が変わっていたので、秘匿したまま同じ URL を読み直している状態。 */
export const BFCACHE_STATE_RELOADING = "reloading";

/** セッション世代の印を運ぶヘッダ (PHP の SessionEpoch::HEADER_NAME と対)。 */
export const SESSION_EPOCH_HEADER = "X-Session-Epoch";
/** 現世代の写しを運ぶ cookie (PHP の SessionEpoch::COOKIE_NAME と対)。 */
export const SESSION_EPOCH_COOKIE = "session_epoch";

/** 印の書式 (PHP の SessionEpoch::VALUE_PATTERN と対)。 */
const SESSION_EPOCH_PATTERN = /^[0-9a-f]{32}$/;

/** プローブ endpoint。サーバ側は routes/web.php の `session.status` (auth グループ外)。 */
export const SESSION_STATUS_PATH = "/session/status";

/** セッション無効時の遷移先。任意 URL は受け取らない (固定の相対パスのみ)。 */
export const LOGIN_PATH = "/login";

export const BFCACHE_OVERLAY_ID = "bfcache-guard-overlay";
export const BFCACHE_RETRY_BUTTON_ID = "bfcache-guard-retry";

/** プローブが必要とする最小 Response 契約 (テスト差替のため fetch 全体に依存しない)。 */
export interface ProbeResponseLike {
    ok: boolean;
    headers: { get(name: string): string | null };
    json(): Promise<unknown>;
}

export type ProbeFetch = (input: string, init: RequestInit) => Promise<ProbeResponseLike>;

/** guard が使う window の最小契約 (jsdom は実 navigation を持たないため差替可能にする)。 */
export interface GuardWindow {
    addEventListener(type: string, listener: (event: Event) => void): void;
    removeEventListener(type: string, listener: (event: Event) => void): void;
    location: { replace(url: string): void; reload(): void };
}

export interface BfcacheGuardDeps {
    doc?: Document;
    win?: GuardWindow;
    fetchImpl?: ProbeFetch;
    /**
     * 認証済みページか (Inertia 共有 props の `auth.user` を起点にする)。
     * 公開ページ (LP / login / SEO) では秘匿もプローブも行わない。
     */
    isAuthenticated?: () => boolean;
    /**
     * いま画面に出ている内容がどのセッション世代の応答で来たか (描画世代)。
     * 出所は Inertia 共有 prop `sessionEpoch` であり、**cookie ではない**。
     */
    readRenderedEpoch?: () => string | null;
    /** 現世代の写し (世代 cookie)。同期判定でしか使わない。 */
    readCurrentEpoch?: () => string | null;
}

/** 同期判定の結論。**開示を表す値を持たない** (開示はプローブだけが決める)。 */
export type SyncEpochDecision = "must-reload" | "undecided";

/**
 * 通信を待たない前置判定。
 *
 * 一致しても "undecided" (= プローブへ進む) にしかならない。
 * この関数が返しうる値に「開示」が無いことが、
 * 「同期判定は開示しない側にしか到達しない」という不変条件の型による表現である。
 */
export function decideBySyncEpoch(
    rendered: string | null,
    current: string | null,
): SyncEpochDecision {
    if (rendered === null || current === null) return "must-reload";
    return rendered === current ? "undecided" : "must-reload";
}

/**
 * document.cookie から現世代の写しを読む。書式が違えば null。
 *
 * **例外を投げない**。壊れた百分率エンコード (`%E0%A4%A` 等) で
 * `decodeURIComponent` は例外を投げるが、ここで落ちると秘匿属性が `pending` のまま
 * 誰も先へ進めない画面ができる。読めないものは null (= 読み直し) に倒す。
 */
export function readSessionEpochCookie(cookieHeader: string): string | null {
    for (const part of cookieHeader.split(";")) {
        const [name, ...rest] = part.split("=");
        if (name?.trim() !== SESSION_EPOCH_COOKIE) continue;
        let value: string;
        try {
            value = decodeURIComponent(rest.join("=").trim());
        } catch {
            return null;
        }
        return SESSION_EPOCH_PATTERN.test(value) ? value : null;
    }
    return null;
}

/**
 * プローブの判定結果。`failed` は「セッション無効」ではなく「判定不能」。
 * `stale` = 認証済みだが世代が違う (別の利用者・別の世代の文書だった)。
 */
export type SessionProbeOutcome =
    | "authenticated"
    | "stale"
    | "unauthenticated"
    | "failed";

/** Content-Type の media type 判定 (charset 等のパラメータは許容する)。 */
export function isJsonMediaType(contentType: string | null): boolean {
    if (contentType === null) return false;
    const mediaType = contentType.split(";")[0]?.trim().toLowerCase() ?? "";
    return mediaType === "application/json";
}

/**
 * プローブ応答の shape 厳密判定。top-level に boolean の `authenticated` と
 * `sessionEpochMatches` が**両方**揃った plain object のみ受理する
 * (data ラップ・型違い・旧 shape は判定不能として弾く = 契約ずれが開示に倒れない)。
 */
export function readSessionStatus(
    payload: unknown,
): { authenticated: boolean; sessionEpochMatches: boolean } | null {
    if (typeof payload !== "object" || payload === null || Array.isArray(payload)) {
        return null;
    }
    const record = payload as Record<string, unknown>;
    const authenticated = record.authenticated;
    const matches = record.sessionEpochMatches;
    if (typeof authenticated !== "boolean" || typeof matches !== "boolean") {
        return null;
    }
    return { authenticated, sessionEpochMatches: matches };
}

/**
 * セッション有効性と世代の一致を問い合わせる。
 * (1) response.ok (2) Content-Type が JSON (3) JSON shape が厳密 — の全てを満たした時のみ
 * 結果を採用し、1 つでも崩れたら `failed` (秘匿維持) に倒す。
 *
 * 描画世代は `X-Session-Epoch` ヘッダで運ぶ。**サーバはこのヘッダだけを照合に使う**
 * (要求の Cookie ヘッダに載る世代 cookie は一致判定に使わない)。
 * 描画世代が無いときはヘッダを付けない (空文字を送らない = サーバ側は不一致に倒す)。
 */
export async function probeSessionStatus(
    fetchImpl: ProbeFetch,
    renderedEpoch: string | null,
    url: string = SESSION_STATUS_PATH,
): Promise<SessionProbeOutcome> {
    const headers: Record<string, string> = { Accept: "application/json" };
    if (renderedEpoch !== null) headers[SESSION_EPOCH_HEADER] = renderedEpoch;

    try {
        const response = await fetchImpl(url, {
            credentials: "same-origin",
            cache: "no-store",
            headers,
        });

        if (!response.ok) return "failed";
        if (!isJsonMediaType(response.headers.get("Content-Type"))) return "failed";

        const status = readSessionStatus(await response.json());
        if (status === null) return "failed";
        if (!status.authenticated) return "unauthenticated";

        return status.sessionEpochMatches ? "authenticated" : "stale";
    } catch {
        return "failed";
    }
}

/**
 * 秘匿オーバーレイを (無ければ) 生成する。Atomic Design 階層には component を足さない
 * (app 起動時のグローバル要素 + CSS で完結させる = atoms/molecules の責務ではない)。
 */
function ensureOverlay(doc: Document): HTMLElement {
    const existing = doc.getElementById(BFCACHE_OVERLAY_ID);
    if (existing !== null) return existing;

    const overlay = doc.createElement("div");
    overlay.id = BFCACHE_OVERLAY_ID;
    overlay.setAttribute("role", "status");
    overlay.setAttribute("aria-live", "polite");
    overlay.dataset.testid = BFCACHE_OVERLAY_ID;

    const panel = doc.createElement("div");
    panel.className = "bfcache-guard__panel";

    const verifying = doc.createElement("p");
    verifying.className = "text-body";
    verifying.dataset.bfcacheGuardVerifying = "";
    verifying.textContent = "セッションを確認しています…";

    const failure = doc.createElement("p");
    failure.className = "text-body";
    failure.dataset.bfcacheGuardFailure = "";
    failure.textContent =
        "セッションを確認できませんでした。通信状況を確認して、もう一度お試しください。";

    const retry = doc.createElement("button");
    retry.type = "button";
    retry.id = BFCACHE_RETRY_BUTTON_ID;
    retry.className = "bfcache-guard__retry";
    retry.dataset.testid = BFCACHE_RETRY_BUTTON_ID;
    retry.textContent = "再試行";

    panel.append(verifying, failure, retry);
    overlay.append(panel);
    doc.body.append(overlay);

    return overlay;
}

function setHiddenState(doc: Document, state: string): void {
    doc.documentElement.setAttribute(BFCACHE_HIDDEN_ATTRIBUTE, state);
}

function clearHiddenState(doc: Document): void {
    doc.documentElement.removeAttribute(BFCACHE_HIDDEN_ATTRIBUTE);
}

function isHidden(doc: Document): boolean {
    return doc.documentElement.hasAttribute(BFCACHE_HIDDEN_ATTRIBUTE);
}

/**
 * pagehide 時に秘匿すべきか。`PageTransitionEvent.persisted` が使える環境では
 * bfcache 対象 (persisted) のときだけ秘匿し、通常遷移のちらつきを避ける。
 * 取得できない環境では安全側 (秘匿する) へ倒す
 * (通常遷移では直後に新しい Document へ移るため実害はほぼ無い)。
 */
function shouldHideOnPageHide(event: Event): boolean {
    const persisted: unknown = (event as PageTransitionEvent).persisted;
    return typeof persisted === "boolean" ? persisted : true;
}

/**
 * guard を登録し、購読解除 disposer を返す (HMR / テストの二重登録防止)。
 *
 * 秘匿・プローブは `isAuthenticated()` が true のページでのみ作動する
 * (公開ページでは不要なちらつき・プローブを起こさない)。
 */
export function registerBfcacheGuard(deps: BfcacheGuardDeps = {}): () => void {
    const doc = deps.doc ?? document;
    const win = deps.win ?? window;
    const fetchImpl: ProbeFetch =
        deps.fetchImpl ?? ((input, init) => fetch(input, init) as Promise<ProbeResponseLike>);
    const isAuthenticated = deps.isAuthenticated ?? (() => false);
    // **描画世代の既定を cookie にしてはならない**。両方が同じ出所になると常に一致し、
    // 同期判定が素通しになる (前置がある振りだけになる)。既定は安全側 (= 読み直し)。
    const readRenderedEpoch = deps.readRenderedEpoch ?? (() => null);
    const readCurrentEpoch =
        deps.readCurrentEpoch ?? (() => readSessionEpochCookie(doc.cookie));

    const overlay = ensureOverlay(doc);
    const retryButton = overlay.querySelector<HTMLButtonElement>(`#${BFCACHE_RETRY_BUTTON_ID}`);

    const onRetry = (): void => {
        // 自動再試行はしない。押下時に現在 URL を hard reload し、サーバに再判定させる。
        win.location.reload();
    };
    retryButton?.addEventListener("click", onRetry);

    const reloadHidden = (): void => {
        // 秘匿を維持したまま同じ URL を読み直す。読み直した文書には秘匿属性が無いので
        // ガードは何もせず、1 文書につき読み直しは高々 1 回でループにならない。
        setHiddenState(doc, BFCACHE_STATE_RELOADING);
        win.location.reload();
    };

    const verify = async (): Promise<void> => {
        setHiddenState(doc, BFCACHE_STATE_VERIFYING);

        const outcome = await probeSessionStatus(
            fetchImpl,
            readRenderedEpoch(),
            SESSION_STATUS_PATH,
        );
        if (outcome === "authenticated") {
            clearHiddenState(doc);
            return;
        }
        if (outcome === "unauthenticated") {
            // 秘匿したまま login へ。replace で秘匿済み履歴エントリを残さない。
            win.location.replace(LOGIN_PATH);
            return;
        }
        if (outcome === "stale") {
            // いまの利用者は認証済みなので /login へは倒さない (嘘の着地を作らない)。
            reloadHidden();
            return;
        }
        setHiddenState(doc, BFCACHE_STATE_RETRY);
    };

    const onPageHide = (event: Event): void => {
        if (!isAuthenticated()) return;
        if (!shouldHideOnPageHide(event)) return;
        setHiddenState(doc, BFCACHE_STATE_PENDING);
    };

    const onPageShow = (): void => {
        // 復元マーカーは秘匿属性そのもの。通常ロードではサーバ由来の新しい HTML に
        // 属性が無いため、ここで抜ける。
        if (!isHidden(doc)) return;
        // 通信を待たない前置判定。描画世代と世代 cookie が食い違う (または読めない) なら
        // プローブを 1 度も呼ばずに読み直す。一致しても開示はせず、プローブへ進むだけ。
        if (decideBySyncEpoch(readRenderedEpoch(), readCurrentEpoch()) === "must-reload") {
            reloadHidden();
            return;
        }
        void verify();
    };

    win.addEventListener("pagehide", onPageHide);
    win.addEventListener("pageshow", onPageShow);

    return () => {
        win.removeEventListener("pagehide", onPageHide);
        win.removeEventListener("pageshow", onPageShow);
        retryButton?.removeEventListener("click", onRetry);
    };
}
