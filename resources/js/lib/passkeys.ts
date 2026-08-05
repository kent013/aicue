import { csrfToken } from "@/lib/csrf";

/**
 * WebAuthn (passkey) ceremony の薄いラッパ。
 *
 * サーバとの JSON 契約は laravel/passkeys が定義する
 * (`{ options }` を受け取り、credential を JSON で返す)。
 *
 * **feature detection を必ず経由すること**。現場 PWA が主戦場であり、
 * 非対応端末 / 生体未設定端末は常態である (docs/supported-browsers.md)。
 *
 * **transport 契約 (詳細設計 施策 4-d) に対応する責務分担**:
 *   本モジュールは「options 取得 + ceremony 実行 + 送信可能な JSON への変換」までを担う。
 *   **登録は送信までしない** (Inertia `router.post` は呼び出し側 Svelte が行う。
 *   passkey 一覧 prop を更新する必要があるため)。confirm / login は fetch 完結なので
 *   送信まで担う。
 *
 * eslint の noInlineConfig (T102) により inline eslint-disable は使えない。
 * base64url 変換は型安全に書き、`any` / `@ts-ignore` を持ち込まないこと。
 */

/** Settings/Security の passkey 一覧 1 件 (PasskeyListItemDto と 1:1) */
export interface PasskeyListItem {
    id: number;
    name: string;
    authenticator: string | null;
    lastUsedAt: string | null;
    createdAt: string | null;
}

/** ceremony の結果種別。キャンセル/タイムアウトはエラーとして騒がない */
export type PasskeyOutcome<T> =
    | { status: "ok"; value: T }
    | { status: "cancelled" }
    | { status: "unsupported" }
    | { status: "failed"; message: string };

const GENERIC_FAILURE = "パスキーの処理に失敗しました。時間をおいて再度お試しください。";

/** この端末で passkey ceremony を開始できるか (API の存在確認) */
export function isPasskeySupported(): boolean {
    return (
        typeof window !== "undefined" &&
        typeof window.PublicKeyCredential === "function" &&
        typeof navigator !== "undefined" &&
        typeof navigator.credentials?.get === "function"
    );
}

/** この端末で passkey を **作成** できるか (プラットフォーム認証器 + user verification) */
export async function canCreatePasskey(): Promise<boolean> {
    if (!isPasskeySupported()) return false;
    try {
        return await window.PublicKeyCredential.isUserVerifyingPlatformAuthenticatorAvailable();
    } catch {
        // 端末/ブラウザによっては未実装で throw する。作成不可として畳む (騒がない)
        return false;
    }
}

/* ------------------------------------------------------------------ *
 * base64url <-> ArrayBuffer
 * サーバ (webauthn-lib の normalizer) は binary を base64url (padding なし) で送る。
 * ------------------------------------------------------------------ */

export function base64UrlToBuffer(value: string): ArrayBuffer {
    const padded = value.replace(/-/g, "+").replace(/_/g, "/");
    const binary = atob(padded + "=".repeat((4 - (padded.length % 4)) % 4));
    const bytes = new Uint8Array(binary.length);
    for (let i = 0; i < binary.length; i += 1) {
        bytes[i] = binary.charCodeAt(i);
    }
    return bytes.buffer;
}

export function bufferToBase64Url(value: ArrayBuffer): string {
    const bytes = new Uint8Array(value);
    let binary = "";
    for (let i = 0; i < bytes.length; i += 1) {
        binary += String.fromCharCode(bytes[i]);
    }
    return btoa(binary).replace(/\+/g, "-").replace(/\//g, "_").replace(/=+$/, "");
}

/* ------------------------------------------------------------------ *
 * サーバ options の最小 shape (信用せず narrowing する)
 * ------------------------------------------------------------------ */

type JsonRecord = Record<string, unknown>;

function isRecord(value: unknown): value is JsonRecord {
    return typeof value === "object" && value !== null && !Array.isArray(value);
}

function readString(source: JsonRecord, key: string): string | null {
    const value = source[key];
    return typeof value === "string" && value !== "" ? value : null;
}

function readDescriptors(source: JsonRecord, key: string): PublicKeyCredentialDescriptor[] {
    const raw = source[key];
    if (!Array.isArray(raw)) return [];
    const descriptors: PublicKeyCredentialDescriptor[] = [];
    for (const entry of raw) {
        if (!isRecord(entry)) continue;
        const id = readString(entry, "id");
        if (id === null) continue;
        // type は WebAuthn 仕様上 "public-key" のみ (webauthn-lib も他を送らない)
        descriptors.push({ id: base64UrlToBuffer(id), type: "public-key" });
    }
    return descriptors;
}

/**
 * 共通 fetch。`Accept: application/json` は必須
 * (無いと Laravel が redirect を返し、PasskeyLoginResponse も JSON 分岐に入らない)。
 */
async function requestJson(url: string, body?: JsonRecord): Promise<Response> {
    const headers: Record<string, string> = {
        Accept: "application/json",
        "X-Requested-With": "XMLHttpRequest",
    };
    if (body !== undefined) {
        headers["Content-Type"] = "application/json";
        headers["X-XSRF-TOKEN"] = csrfToken();
    }
    return fetch(url, {
        method: body === undefined ? "GET" : "POST",
        headers,
        credentials: "same-origin",
        body: body === undefined ? undefined : JSON.stringify(body),
    });
}

/** options endpoint から `{ options }` を取り出す (不正 shape は null) */
async function fetchOptions(url: string): Promise<JsonRecord | null> {
    try {
        const res = await requestJson(url);
        if (!res.ok) return null;
        const payload: unknown = await res.json();
        if (!isRecord(payload) || !isRecord(payload.options)) return null;
        return payload.options;
    } catch {
        return null;
    }
}

/** ユーザーキャンセル / タイムアウトを「失敗」として騒がないために畳む */
function isCancellation(error: unknown): boolean {
    return (
        error instanceof Error &&
        (error.name === "NotAllowedError" || error.name === "AbortError")
    );
}

/* ------------------------------------------------------------------ *
 * ceremony
 * ------------------------------------------------------------------ */

/** ArrayBuffer 相当のフィールドだけを base64url へ写す (存在しないキーは落とす) */
function encodeBufferField(source: JsonRecord, key: string): string | null {
    const value = source[key];
    return value instanceof ArrayBuffer ? bufferToBase64Url(value) : null;
}

/**
 * navigator.credentials の戻りを送信可能な JSON へ変換する。
 *
 * 種別判定は `instanceof AuthenticatorAttestationResponse` **ではなく** フィールドの
 * 有無で行う。認証器レスポンスのクラスはグローバルに存在しない実行環境があり
 * (jsdom / 一部の WebView)、instanceof は ReferenceError で ceremony 全体を落とすため。
 */
function serializeCredential(credential: PublicKeyCredential): JsonRecord {
    const response = credential.response as unknown as JsonRecord;
    const clientDataJSON = encodeBufferField(response, "clientDataJSON");
    const attestationObject = encodeBufferField(response, "attestationObject");

    const serializedResponse: JsonRecord = {};
    if (clientDataJSON !== null) serializedResponse.clientDataJSON = clientDataJSON;

    if (attestationObject !== null) {
        // 登録 (attestation)
        serializedResponse.attestationObject = attestationObject;
    } else {
        // 認証 (assertion)。userHandle は null を明示的に送る (仕様上 null がありうる)
        const authenticatorData = encodeBufferField(response, "authenticatorData");
        const signature = encodeBufferField(response, "signature");
        if (authenticatorData !== null) serializedResponse.authenticatorData = authenticatorData;
        if (signature !== null) serializedResponse.signature = signature;
        serializedResponse.userHandle = encodeBufferField(response, "userHandle");
    }

    return {
        id: credential.id,
        rawId: bufferToBase64Url(credential.rawId),
        type: credential.type,
        response: serializedResponse,
    };
}

function toCreationOptions(options: JsonRecord): PublicKeyCredentialCreationOptions | null {
    const challenge = readString(options, "challenge");
    const rp = isRecord(options.rp) ? options.rp : null;
    const user = isRecord(options.user) ? options.user : null;
    const userId = user === null ? null : readString(user, "id");
    if (challenge === null || rp === null || user === null || userId === null) return null;

    const params = Array.isArray(options.pubKeyCredParams)
        ? options.pubKeyCredParams
              .filter(isRecord)
              .filter((entry): entry is JsonRecord => typeof entry.alg === "number")
              .map((entry): PublicKeyCredentialParameters => ({
                  type: "public-key",
                  alg: entry.alg as number,
              }))
        : [];

    return {
        challenge: base64UrlToBuffer(challenge),
        rp: {
            id: readString(rp, "id") ?? undefined,
            name: readString(rp, "name") ?? "",
        },
        user: {
            id: base64UrlToBuffer(userId),
            name: readString(user, "name") ?? "",
            displayName: readString(user, "displayName") ?? "",
        },
        pubKeyCredParams: params,
        excludeCredentials: readDescriptors(options, "excludeCredentials"),
        timeout: typeof options.timeout === "number" ? options.timeout : undefined,
        authenticatorSelection: isRecord(options.authenticatorSelection)
            ? {
                  residentKey: readString(options.authenticatorSelection, "residentKey") as
                      | ResidentKeyRequirement
                      | undefined,
                  userVerification: readString(options.authenticatorSelection, "userVerification") as
                      | UserVerificationRequirement
                      | undefined,
              }
            : undefined,
        attestation: (readString(options, "attestation") ?? undefined) as
            | AttestationConveyancePreference
            | undefined,
    };
}

function toRequestOptions(options: JsonRecord): PublicKeyCredentialRequestOptions | null {
    const challenge = readString(options, "challenge");
    if (challenge === null) return null;

    return {
        challenge: base64UrlToBuffer(challenge),
        rpId: readString(options, "rpId") ?? undefined,
        allowCredentials: readDescriptors(options, "allowCredentials"),
        timeout: typeof options.timeout === "number" ? options.timeout : undefined,
        userVerification: (readString(options, "userVerification") ?? undefined) as
            | UserVerificationRequirement
            | undefined,
    };
}

/**
 * 登録 ceremony (GET options → navigator.credentials.create)。
 * **送信は行わない**。呼び出し側が
 * `router.post('/user/passkeys', { name, credential })` する (transport 契約 4-d)。
 */
export async function createPasskeyCredential(): Promise<PasskeyOutcome<JsonRecord>> {
    if (!isPasskeySupported()) return { status: "unsupported" };

    const options = await fetchOptions("/user/passkeys/options");
    if (options === null) {
        return { status: "failed", message: "パスキーの登録を開始できませんでした。" };
    }

    const creationOptions = toCreationOptions(options);
    if (creationOptions === null) {
        return { status: "failed", message: GENERIC_FAILURE };
    }

    try {
        const credential = await navigator.credentials.create({ publicKey: creationOptions });
        if (!(credential instanceof PublicKeyCredential)) {
            return { status: "failed", message: GENERIC_FAILURE };
        }
        return { status: "ok", value: serializeCredential(credential) };
    } catch (error) {
        if (isCancellation(error)) return { status: "cancelled" };
        return { status: "failed", message: GENERIC_FAILURE };
    }
}

/** ログイン ceremony (GET options → navigator.credentials.get → POST → `{ redirect }`) */
export async function loginWithPasskey(
    remember = false,
): Promise<PasskeyOutcome<{ redirect: string }>> {
    const assertion = await assertPasskey("/passkeys/login/options");
    if (assertion.status !== "ok") return assertion;

    try {
        const res = await requestJson("/passkeys/login", {
            credential: assertion.value,
            remember,
        });
        if (!res.ok) {
            return { status: "failed", message: await readErrorMessage(res) };
        }
        const payload: unknown = await res.json();
        const redirect = isRecord(payload) ? readString(payload, "redirect") : null;
        if (redirect === null) {
            return { status: "failed", message: GENERIC_FAILURE };
        }
        return { status: "ok", value: { redirect } };
    } catch {
        return { status: "failed", message: "通信エラーが発生しました。" };
    }
}

/**
 * step-up 確認 ceremony (GET confirm-options → navigator.credentials.get)。
 * **送信は行わない**。
 *
 * 全画面の confirm 画面 (302 fallback 経路) 専用: そちらはサーバが保持する
 * `url.intended` へ戻す必要があり、Inertia の `router.post` で送って
 * PasskeyConfirmationResponse の `redirect()->intended()` 分岐に乗せる。
 * インラインモーダルは元操作を client 側で再開するため fetch 完結の
 * {@link confirmWithPasskey} を使う。
 */
export async function confirmPasskeyCredential(): Promise<PasskeyOutcome<Record<string, unknown>>> {
    return assertPasskey("/passkeys/confirm/options");
}

/** step-up 確認 ceremony (GET confirm-options → navigator.credentials.get → POST → 204) */
export async function confirmWithPasskey(): Promise<PasskeyOutcome<void>> {
    const assertion = await assertPasskey("/passkeys/confirm/options");
    if (assertion.status !== "ok") return assertion;

    try {
        const res = await requestJson("/passkeys/confirm", { credential: assertion.value });
        // 成功は 204 No Content (recent-auth.password と同契約)
        if (res.status === 204) return { status: "ok", value: undefined };
        return { status: "failed", message: await readErrorMessage(res) };
    } catch {
        return { status: "failed", message: "通信エラーが発生しました。" };
    }
}

/** options 取得 + assertion ceremony の共通部 */
async function assertPasskey(optionsUrl: string): Promise<PasskeyOutcome<JsonRecord>> {
    if (!isPasskeySupported()) return { status: "unsupported" };

    const options = await fetchOptions(optionsUrl);
    if (options === null) {
        return { status: "failed", message: "パスキーの認証を開始できませんでした。" };
    }

    const requestOptions = toRequestOptions(options);
    if (requestOptions === null) {
        return { status: "failed", message: GENERIC_FAILURE };
    }

    try {
        const credential = await navigator.credentials.get({ publicKey: requestOptions });
        if (!(credential instanceof PublicKeyCredential)) {
            return { status: "failed", message: GENERIC_FAILURE };
        }
        return { status: "ok", value: serializeCredential(credential) };
    } catch (error) {
        if (isCancellation(error)) return { status: "cancelled" };
        return { status: "failed", message: GENERIC_FAILURE };
    }
}

/** サーバのエラー本文から表示可能なメッセージを取り出す (取れなければ既定文言) */
async function readErrorMessage(response: Response): Promise<string> {
    try {
        const payload: unknown = await response.json();
        if (!isRecord(payload)) return GENERIC_FAILURE;
        const direct = readString(payload, "message");
        if (direct !== null) return direct;
        const errors = payload.errors;
        if (isRecord(errors)) {
            for (const value of Object.values(errors)) {
                if (Array.isArray(value) && typeof value[0] === "string") return value[0];
            }
        }
        return GENERIC_FAILURE;
    } catch {
        return GENERIC_FAILURE;
    }
}
