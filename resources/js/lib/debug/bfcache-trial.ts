/**
 * bfcache 実機受入確認 (T085) の観測ライブラリ。
 *
 * 目的: T085 の実機手順には**負のコントロールが無く**、「guard が働いた」と
 * 「そもそも bfcache 復元が起きなかった」を目視で区別できない。どちらも
 * 「PII が出ない」に見えるため、空振りを合格として記録しうる。
 * 同じ欠陥は Playwright レーンについては徹底的に潰されている
 * (詳細設計 施策 8「空振りを green と偽らない」)。その規律を実機レーンへ揃える。
 *
 * 設計方針:
 * - **検証対象 (bfcache-guard.ts / 秘匿 CSS / /session/status) には一切手を入れない**。
 *   本ライブラリは guard を**外から観測するだけ**である
 * - 判定は **二軸 + 総合**。「bfcache が成立したか」(軸 1) と
 *   「guard が合格したか」(軸 2) は別の問いで、混ぜると受入失敗を PASS と読む
 * - event log には**観測事実のみ**を保存し、verdict は保存しない
 *   (後から `redirect-observed` が追記されるため、保存すると必ず stale になる)
 * - **観測できないことを推論しない**。離脱先が /login だったか、離脱が
 *   intercept されたかは A から観測できないため、利用者の手動記録に倒す
 *
 * 全体設計は devnotes/20260812-1931-bfcache-device-verification-page/detailed-design.md。
 */

/** schema 変更時に必ず上げる。復元時に不一致なら破棄する。 */
export const TRIAL_SCHEMA_VERSION = 1;

/** sessionStorage のキー。 */
export const TRIAL_STORAGE_KEY = "bfcache-trial:v1";

/** 検証シナリオ。利用者が試行開始時に宣言する。 */
export type TrialScenario = "expired-session" | "active-session";

/** guard の秘匿属性がとりうる値 (属性削除は null で表す)。 */
export type GuardState = "pending" | "verifying" | "retry" | null;

/** 利用者申告フィールドの制約 (自由記述の抜け道にしない)。 */
export const DEVICE_MODEL_MAX_LENGTH = 40;
export const VERIFIED_OS_VERSION_MAX_LENGTH = 20;
export const STORAGE_FAILURE_REASON_MAX_LENGTH = 200;

const DEVICE_MODEL_PATTERN = /^[A-Za-z0-9 \-,().]*$/;
const VERIFIED_OS_VERSION_PATTERN = /^[A-Za-z0-9 .]*$/;

/** 前後の空白を落とし、連続空白を 1 個に畳む。 */
export function normalizeUserReported(value: string): string {
    return value.trim().replace(/\s+/g, " ");
}

/**
 * 利用者申告値を検証する。**許可外文字を除去して通さない**
 * (除去すると利用者が意図しない値が証跡に残る)。拒否して入力し直させる。
 */
export function isValidDeviceModel(value: string): boolean {
    return (
        value.length <= DEVICE_MODEL_MAX_LENGTH &&
        DEVICE_MODEL_PATTERN.test(value)
    );
}

export function isValidVerifiedOsVersion(value: string): boolean {
    return (
        value.length <= VERIFIED_OS_VERSION_MAX_LENGTH &&
        VERIFIED_OS_VERSION_PATTERN.test(value)
    );
}

interface EventBase {
    schemaVersion: number;
    trialId: string;
    sequence: number;
    /** ISO 8601。 */
    timestamp: string;
}

export interface TrialStartedEvent extends EventBase {
    type: "trial-started";
    scenario: TrialScenario;
    contextToken: string;
    userAgent: string;
    uaReportedOs: string;
    displayMode: string;
    navigatorStandalone: boolean | null;
    /** 利用者申告。長さ・文字種を制限する。 */
    deviceModel: string;
    /** 利用者申告。長さ・文字種を制限する。 */
    verifiedOsVersion: string;
}

/**
 * 離脱リンクが押された**操作事実**を同期記録する。
 * `page-hide` の不在だけから離脱失敗を推論しない (正常な時間差と区別できないため)。
 * 離脱失敗の判定は `AwayNavigationFailedEvent` (手動記録) のみが担う。
 */
export interface AwayNavigationStartedEvent extends EventBase {
    type: "away-navigation-started";
}

/**
 * 離脱が始まらなかったことを**利用者が明示的に記録する**手動イベント。
 *
 * タイマーで自動判定しない: 次タスク時点で `visibilityState !== "hidden"` でも
 * その後に正常な full navigation が進みうる (誤検出) 一方、intercept 後に
 * 別処理がページを hidden にすれば見逃す。どちらの向きにも外すので、
 * 「観測できないことを推論しない」という本設計の原則に反する。
 */
export interface AwayNavigationFailedEvent extends EventBase {
    type: "away-navigation-failed";
    observationMethod: "manual";
}

export interface PageHideEvent extends EventBase {
    type: "page-hide";
    persisted: boolean;
    guardState: GuardState;
}

export interface PageShowEvent extends EventBase {
    type: "page-show";
    persisted: boolean;
    contextToken: string;
    displayMode: string;
}

export interface GuardStateChangedEvent extends EventBase {
    type: "guard-state-changed";
    state: GuardState;
}

/**
 * 利用者が /login 到達を確認して記録する手入力イベント。
 *
 * guard は `unauthenticated` のとき属性を `verifying` のまま
 * `location.replace('/login')` を呼ぶため、A から観測できるのは
 * 「秘匿を維持したまま離脱した」までである。離脱先は観測できない。
 */
export interface RedirectObservedEvent extends EventBase {
    type: "redirect-observed";
    observationMethod: "manual";
}

/**
 * 保存できれば記録する診断イベント。**保存不能の永続証拠ではない**
 * (storage が壊れていれば本イベント自身も残らない)。
 */
export interface StorageFailedEvent extends EventBase {
    type: "storage-failed";
    reason: string;
}

export interface TrialAbortedEvent extends EventBase {
    type: "trial-aborted";
}

export type TrialEvent =
    | TrialStartedEvent
    | AwayNavigationStartedEvent
    | AwayNavigationFailedEvent
    | PageHideEvent
    | PageShowEvent
    | GuardStateChangedEvent
    | RedirectObservedEvent
    | StorageFailedEvent
    | TrialAbortedEvent;

export type TrialEventType = TrialEvent["type"];

/** 軸 1: 試行が成立したか (bfcache 復元が本当に起きたか)。 */
export type TrialVerdict =
    | "valid-bfcache"
    | "invalid-not-bfcache"
    | "invalid-wrong-route"
    | "inconsistent"
    | "incomplete";

/** 軸 2: guard がどう振る舞ったか。`in-progress` は正常遷移の途中 (終端していない)。 */
export type GuardVerdict =
    | "in-progress"
    | "authenticated-unhidden"
    | "unauthenticated-redirected"
    | "hidden-then-left"
    | "retry-hidden"
    | "failed-transition"
    | "not-observed";

/** 軸 3: 総合。保存せず軸 1・軸 2 から導出する。 */
export type OverallVerdict =
    | "pass"
    | "fail"
    | "expectation-mismatch"
    | "undetermined";

/** 試行の進行状態。保存せず導出する (保存すると stale 化する)。 */
export type TrialPhase =
    | "invalid"
    | "collecting-axis1"
    | "collecting-axis2"
    | "awaiting-manual-confirmation"
    | "complete"
    | "aborted";

// ---------------------------------------------------------------------------
// validator
// ---------------------------------------------------------------------------

/**
 * 各 event type に許可されるキー。**ここに無いキーを 1 つでも持つイベントは拒否する**
 * (余分キーの混入を黙って通さない)。
 */
const ALLOWED_KEYS: Record<TrialEventType, readonly string[]> = {
    "trial-started": [
        "scenario",
        "contextToken",
        "userAgent",
        "uaReportedOs",
        "displayMode",
        "navigatorStandalone",
        "deviceModel",
        "verifiedOsVersion",
    ],
    "away-navigation-started": [],
    "away-navigation-failed": ["observationMethod"],
    "page-hide": ["persisted", "guardState"],
    "page-show": ["persisted", "contextToken", "displayMode"],
    "guard-state-changed": ["state"],
    "redirect-observed": ["observationMethod"],
    "storage-failed": ["reason"],
    "trial-aborted": [],
} as const;

const BASE_KEYS: readonly string[] = [
    "schemaVersion",
    "trialId",
    "sequence",
    "timestamp",
    "type",
] as const;

const GUARD_STATES: readonly GuardState[] = [
    "pending",
    "verifying",
    "retry",
    null,
] as const;

function isPlainObject(value: unknown): value is Record<string, unknown> {
    return (
        typeof value === "object" && value !== null && !Array.isArray(value)
    );
}

function isNonEmptyString(value: unknown): value is string {
    return typeof value === "string" && value.length > 0;
}

function isConstrainedString(
    value: unknown,
    maxLength: number,
    pattern: RegExp,
): value is string {
    return (
        typeof value === "string" &&
        value.length <= maxLength &&
        pattern.test(value)
    );
}

function isEventType(value: unknown): value is TrialEventType {
    // `in` は prototype 由来のキー ("toString" 等) にも真を返し、後段の
    // ALLOWED_KEYS[value] が関数になって spread で例外化する。自身のキーだけを見る。
    return typeof value === "string" && Object.hasOwn(ALLOWED_KEYS, value);
}

/**
 * 1 イベントを厳密に検証する。shape が少しでも崩れていたら `null` を返す。
 *
 * `bfcache-guard.ts` の `readAuthenticatedFlag()` と同じ
 * 「shape を厳密判定し、崩れていたら採用しない」idiom に揃えている。
 */
export function parseTrialEvent(value: unknown): TrialEvent | null {
    if (!isPlainObject(value)) return null;
    if (value.schemaVersion !== TRIAL_SCHEMA_VERSION) return null;
    if (!isEventType(value.type)) return null;
    if (!isNonEmptyString(value.trialId)) return null;
    if (typeof value.sequence !== "number" || !Number.isInteger(value.sequence)) {
        return null;
    }
    if (value.sequence < 0) return null;
    if (!isNonEmptyString(value.timestamp)) return null;

    const allowed = new Set<string>([...BASE_KEYS, ...ALLOWED_KEYS[value.type]]);
    for (const key of Object.keys(value)) {
        if (!allowed.has(key)) return null;
    }
    for (const key of ALLOWED_KEYS[value.type]) {
        if (!(key in value)) return null;
    }

    if (!parsePayload(value.type, value)) return null;

    return value as unknown as TrialEvent;
}

/** type 固有フィールドの型・制約を検証する。 */
function parsePayload(
    type: TrialEventType,
    value: Record<string, unknown>,
): boolean {
    switch (type) {
        case "trial-started":
            return (
                (value.scenario === "expired-session" ||
                    value.scenario === "active-session") &&
                isNonEmptyString(value.contextToken) &&
                typeof value.userAgent === "string" &&
                typeof value.uaReportedOs === "string" &&
                typeof value.displayMode === "string" &&
                (typeof value.navigatorStandalone === "boolean" ||
                    value.navigatorStandalone === null) &&
                isConstrainedString(
                    value.deviceModel,
                    DEVICE_MODEL_MAX_LENGTH,
                    DEVICE_MODEL_PATTERN,
                ) &&
                isConstrainedString(
                    value.verifiedOsVersion,
                    VERIFIED_OS_VERSION_MAX_LENGTH,
                    VERIFIED_OS_VERSION_PATTERN,
                )
            );
        case "page-hide":
            return (
                typeof value.persisted === "boolean" &&
                GUARD_STATES.includes(value.guardState as GuardState)
            );
        case "page-show":
            return (
                typeof value.persisted === "boolean" &&
                isNonEmptyString(value.contextToken) &&
                typeof value.displayMode === "string"
            );
        case "guard-state-changed":
            return GUARD_STATES.includes(value.state as GuardState);
        case "away-navigation-failed":
        case "redirect-observed":
            return value.observationMethod === "manual";
        case "storage-failed":
            return (
                typeof value.reason === "string" &&
                value.reason.length <= STORAGE_FAILURE_REASON_MAX_LENGTH
            );
        case "away-navigation-started":
        case "trial-aborted":
            return true;
    }
}

/**
 * 保存済みログ全体をパースする。
 *
 * **1 件でも壊れていたらログ全体を破棄する** (部分採用しない)。
 * 欠落した証跡を完全な証跡と誤読させないため。
 */
export function parseTrialLog(raw: string | null): TrialEvent[] | null {
    if (raw === null || raw === "") return null;

    let decoded: unknown;
    try {
        decoded = JSON.parse(raw);
    } catch {
        return null;
    }
    if (!Array.isArray(decoded)) return null;

    const events: TrialEvent[] = [];
    for (const entry of decoded) {
        const parsed = parseTrialEvent(entry);
        if (parsed === null) return null;
        events.push(parsed);
    }
    return events;
}

// ---------------------------------------------------------------------------
// 採番 / 前提条件
// ---------------------------------------------------------------------------

/**
 * 常に `max(sequence) + 1` を返す。sessionStorage から復元した進行中試行へ
 * 追記する場合も採番が壊れない (欠番・重複があっても max を基準にする)。
 * 空配列では 1 を返す (先頭イベントの sequence は 1)。
 */
export function nextSequence(events: TrialEvent[], trialId: string): number {
    const target = events.filter((event) => event.trialId === trialId);
    if (target.length === 0) return 1;
    return Math.max(...target.map((event) => event.sequence)) + 1;
}

/** 3 導出関数の共通事前条件。イベントが 1 つの trialId だけに属するか。 */
export function hasSingleTrialId(events: TrialEvent[]): boolean {
    if (events.length === 0) return true;
    const first = events[0].trialId;
    return events.every((event) => event.trialId === first);
}

// ---------------------------------------------------------------------------
// 軸 1: 試行成立判定
// ---------------------------------------------------------------------------

interface Axis1Window {
    started: TrialStartedEvent;
    away: AwayNavigationStartedEvent;
    hide: PageHideEvent;
    show: PageShowEvent;
}

function bySequence(events: TrialEvent[]): TrialEvent[] {
    return [...events].sort((a, b) => a.sequence - b.sequence);
}

/**
 * 軸 1 window を探す。
 *
 * 最初に成立した `trial-started < away-navigation-started < page-hide < page-show` を
 * **軸 1 が参照する唯一の範囲**として確定させる。窓の外は軸 1 の判定に用いない
 * (失効セッション経路では再ログイン後に必ず追加観測が発生するため、
 * これが無いと判定が後から崩れる)。
 */
export function findAxis1Window(events: TrialEvent[]): Axis1Window | null {
    const ordered = bySequence(events);

    const started = ordered.find(
        (event): event is TrialStartedEvent => event.type === "trial-started",
    );
    if (started === undefined) return null;

    const away = ordered.find(
        (event): event is AwayNavigationStartedEvent =>
            event.type === "away-navigation-started" &&
            event.sequence > started.sequence,
    );
    if (away === undefined) return null;

    const hide = ordered.find(
        (event): event is PageHideEvent =>
            event.type === "page-hide" && event.sequence > away.sequence,
    );
    if (hide === undefined) return null;

    const show = ordered.find(
        (event): event is PageShowEvent =>
            event.type === "page-show" && event.sequence > hide.sequence,
    );
    if (show === undefined) return null;

    return { started, away, hide, show };
}

export function deriveTrialVerdict(events: TrialEvent[]): TrialVerdict {
    if (!hasSingleTrialId(events)) return "inconsistent";

    const window = findAxis1Window(events);
    if (window !== null) {
        const tokenMatches =
            window.show.contextToken === window.started.contextToken;

        if (window.hide.persisted && window.show.persisted && tokenMatches) {
            return "valid-bfcache";
        }
        if (!window.show.persisted && !tokenMatches) {
            return "invalid-not-bfcache";
        }
        return "inconsistent";
    }

    const hasHide = events.some((event) => event.type === "page-hide");
    const hasShow = events.some((event) => event.type === "page-show");
    // hide と show が揃っているのに窓を成せない = away 欠落 or 順序異常
    if (hasHide && hasShow) return "inconsistent";

    if (hasOrderedAwayFailure(events)) return "invalid-wrong-route";

    return "incomplete";
}

/**
 * 離脱失敗の手動記録を採用してよいか。
 *
 * 順序 `trial-started < away-navigation-started < away-navigation-failed` を要求する。
 * 「離脱を試していないのに離脱失敗が記録されている」列を根拠にしない
 * (単独の failed は状態として意味を成さない)。
 */
function hasOrderedAwayFailure(events: TrialEvent[]): boolean {
    const ordered = bySequence(events);

    const started = ordered.find((event) => event.type === "trial-started");
    if (started === undefined) return false;

    const away = ordered.find(
        (event) =>
            event.type === "away-navigation-started" &&
            event.sequence > started.sequence,
    );
    if (away === undefined) return false;

    return ordered.some(
        (event) =>
            event.type === "away-navigation-failed" &&
            event.sequence > away.sequence,
    );
}

// ---------------------------------------------------------------------------
// 軸 2: guard 結果判定
// ---------------------------------------------------------------------------

/**
 * 軸 2 は**軸 1 window の `page-show` より後**のイベントだけを見る。
 * 往路 (A → B) の `page-hide` をリダイレクト離脱として拾ってはならない。
 */
export function deriveGuardVerdict(events: TrialEvent[]): GuardVerdict {
    if (!hasSingleTrialId(events)) return "failed-transition";

    const window = findAxis1Window(events);
    const boundary = window?.show.sequence ?? Number.POSITIVE_INFINITY;
    const after = bySequence(events).filter(
        (event) => event.sequence > boundary,
    );

    // **最初の終端まででフィルタを閉じる**。終端後に fresh load の guard イベントが
    // 追記されても判定が崩れないようにするため (失効セッション経路では再ログイン後に
    // A を開き直すので、これが無いと確実に崩れる)。
    const states: GuardState[] = [];
    let hiddenThenLeft = false;
    let contradiction = false;

    for (const event of after) {
        if (event.type === "guard-state-changed") {
            states.push(event.state);
            if (states.length === 3) break; // 3 つ目で終端か異常かが決まる
            continue;
        }
        if (
            event.type === "page-hide" &&
            states.length === 2 &&
            states[0] === "pending" &&
            states[1] === "verifying"
        ) {
            // **離脱時点で実際に秘匿されていたか**を page-hide のスナップショットで確かめる。
            // guardState が null (= 秘匿解除済み) の離脱は「秘匿維持のまま離脱した」証拠に
            // ならない。証跡どうしの矛盾なので合格側へ倒さない
            if (event.guardState === "verifying") {
                hiddenThenLeft = true;
            } else {
                contradiction = true;
            }
            break;
        }
    }

    if (contradiction) return "failed-transition";

    const aborted = events.some((event) => event.type === "trial-aborted");

    if (states.length === 0) return aborted ? "not-observed" : "in-progress";

    // 正常遷移は pending → verifying → (null | retry)。prefix を異常扱いしない
    if (states[0] !== "pending") return "failed-transition";
    if (states.length === 1) return "in-progress";
    if (states[1] !== "verifying") return "failed-transition";

    if (states.length === 2) {
        if (!hiddenThenLeft) return "in-progress";
        return events.some((event) => event.type === "redirect-observed")
            ? "unauthenticated-redirected"
            : "hidden-then-left";
    }

    if (states[2] === null) return "authenticated-unhidden";
    if (states[2] === "retry") return "retry-hidden";
    return "failed-transition";
}

// ---------------------------------------------------------------------------
// 軸 3: 総合判定 / 進行状態
// ---------------------------------------------------------------------------

/** シナリオごとに期待される guard 結果。 */
export function expectedGuardVerdict(scenario: TrialScenario): GuardVerdict {
    return scenario === "expired-session"
        ? "unauthenticated-redirected"
        : "authenticated-unhidden";
}

/**
 * 総合判定。**軸 1 と軸 2 から導出するだけで、保存しない**。
 *
 * `in-progress` / `not-observed` / `hidden-then-left` を `undetermined` に落とすのが要点。
 * - `in-progress`: 観測途中。ここを fail にすると復元直後の正常な状態が FAIL 表示になる
 * - `not-observed`: guard が発火しなかったのか利用者が早く中止したのか**区別できない**
 * - `hidden-then-left`: `redirect-observed` が入るまで終端していない
 */
export function deriveOverallVerdict(
    scenario: TrialScenario,
    trial: TrialVerdict,
    guard: GuardVerdict,
): OverallVerdict {
    if (trial !== "valid-bfcache") return "undetermined";
    if (
        guard === "in-progress" ||
        guard === "not-observed" ||
        guard === "hidden-then-left"
    ) {
        return "undetermined";
    }
    if (guard === expectedGuardVerdict(scenario)) return "pass";
    if (guard === "failed-transition") return "fail";
    return "expectation-mismatch";
}

/**
 * 試行の進行状態。listener の追記可否をこの結果で決める。
 *
 * `in-progress` が `collecting-axis2` に写ることが要点である。これが無いと
 * 正常な `pending` / `pending → verifying` の途中で `complete` に落ちて
 * 自動追記が止まり、`null` / `retry` / 復元後 `page-hide` を記録できなくなる。
 */
export function deriveTrialPhase(events: TrialEvent[]): TrialPhase {
    if (!hasSingleTrialId(events)) return "invalid";
    if (events.some((event) => event.type === "trial-aborted")) return "aborted";

    const trial = deriveTrialVerdict(events);
    if (trial === "incomplete") return "collecting-axis1";
    if (trial !== "valid-bfcache") return "complete";

    const guard = deriveGuardVerdict(events);
    if (guard === "in-progress") return "collecting-axis2";
    if (guard === "hidden-then-left") return "awaiting-manual-confirmation";
    return "complete";
}

/** phase ごとに追記を許可するイベント種別。 */
const ALLOWED_APPENDS: Record<TrialPhase, readonly TrialEventType[]> = {
    invalid: [],
    "collecting-axis1": [
        "away-navigation-started",
        "away-navigation-failed",
        "page-hide",
        "page-show",
        "guard-state-changed",
        "storage-failed",
        "trial-aborted",
    ],
    "collecting-axis2": [
        "page-hide",
        "page-show",
        "guard-state-changed",
        "storage-failed",
        "trial-aborted",
    ],
    "awaiting-manual-confirmation": ["redirect-observed", "trial-aborted"],
    complete: [],
    aborted: [],
} as const;

/**
 * その phase でそのイベントを追記してよいか。
 *
 * `awaiting-manual-confirmation` で自動イベントを止めることが、
 * **再ログイン後の fresh load による証跡汚染を防ぐ実装上の要**である。
 */
export function canAppend(phase: TrialPhase, type: TrialEventType): boolean {
    return ALLOWED_APPENDS[phase].includes(type);
}

// ---------------------------------------------------------------------------
// storage
// ---------------------------------------------------------------------------

function storage(): Storage | null {
    try {
        return globalThis.sessionStorage;
    } catch {
        return null;
    }
}

/** 試行開始前の保存テスト。書けない環境では試行を始めさせない。 */
export function probeStorageWritable(): boolean {
    const store = storage();
    if (store === null) return false;

    const probeKey = `${TRIAL_STORAGE_KEY}:probe`;
    try {
        store.setItem(probeKey, "1");
        const readBack = store.getItem(probeKey);
        store.removeItem(probeKey);
        return readBack === "1";
    } catch {
        return false;
    }
}

/** 保存済みログを読む。壊れていたら `null` (呼び出し側が破棄を表示する)。 */
export function readTrialLog(): TrialEvent[] | null {
    const store = storage();
    if (store === null) return null;
    try {
        return parseTrialLog(store.getItem(TRIAL_STORAGE_KEY));
    } catch {
        return null;
    }
}

/**
 * イベントを追記する。**例外を投げず、失敗を `false` で返す**
 * (黙って成功扱いにしない)。
 *
 * 書き込み後に read-back validation を行い、追記したイベントが末尾に
 * 同値で存在することまで確認する (JSON parse 成功だけでは破損を見逃す)。
 */
export function appendEvent(event: TrialEvent): boolean {
    const store = storage();
    if (store === null) return false;

    try {
        const existing = parseTrialLog(store.getItem(TRIAL_STORAGE_KEY)) ?? [];
        const next = [...existing, event];
        store.setItem(TRIAL_STORAGE_KEY, JSON.stringify(next));

        const readBack = parseTrialLog(store.getItem(TRIAL_STORAGE_KEY));
        if (readBack === null) return false;
        if (readBack.length !== next.length) return false;

        const tail = readBack[readBack.length - 1];
        return JSON.stringify(tail) === JSON.stringify(event);
    } catch {
        return false;
    }
}

/** 保存済みイベントを trialId ごとに分離して返す (混ぜて誤判定させない)。 */
export function loadTrials(): Map<string, TrialEvent[]> {
    return groupByTrialId(readTrialLog() ?? []);
}

/** イベント列を trialId ごとに分離する (純粋関数。テスト用に公開する)。 */
export function groupByTrialId(
    events: TrialEvent[],
): Map<string, TrialEvent[]> {
    const grouped = new Map<string, TrialEvent[]>();
    for (const event of bySequence(events)) {
        const bucket = grouped.get(event.trialId);
        if (bucket === undefined) {
            grouped.set(event.trialId, [event]);
            continue;
        }
        bucket.push(event);
    }
    return grouped;
}
