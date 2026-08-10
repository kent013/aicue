import { afterEach, beforeEach, describe, expect, it, vi } from "vitest";
import {
    base64UrlToBuffer,
    bufferToBase64Url,
    canCreatePasskey,
    confirmWithPasskey,
    createPasskeyCredential,
    isPasskeySupported,
    loginWithPasskey,
} from "@/lib/passkeys";

/*
 * WebAuthn ラッパの分岐契約。
 *
 * **限界**: 実 ceremony は jsdom でエミュレートできない (仮想認証器が要る)。
 * ここで固定するのは
 *   - feature detection (非対応端末で throw しない / unsupported を返す)
 *   - キャンセル (NotAllowedError) を "cancelled" に畳むこと
 *   - base64url 変換の往復
 *   - fetch のヘッダ契約 (Accept: application/json が無いと
 *     PasskeyLoginResponse の JSON 分岐に入らない)
 * 実 ceremony の確認は docs/supported-browsers.md の実機受入確認に委ねる。
 */

const originalNavigator = globalThis.navigator;

interface CredentialsStub {
    create: ReturnType<typeof vi.fn>;
    get: ReturnType<typeof vi.fn>;
}

function stubWebAuthnApis(credentials: Partial<CredentialsStub> = {}): CredentialsStub {
    const stub: CredentialsStub = {
        create: vi.fn(),
        get: vi.fn(),
        ...credentials,
    } as CredentialsStub;

    Object.defineProperty(globalThis, "navigator", {
        configurable: true,
        value: { credentials: stub },
    });

    const publicKeyCredential = function PublicKeyCredentialStub() {
        // 実体は使わない (instanceof 判定にのみ使う)
    } as unknown as typeof PublicKeyCredential;
    (
        publicKeyCredential as unknown as {
            isUserVerifyingPlatformAuthenticatorAvailable: () => Promise<boolean>;
        }
    ).isUserVerifyingPlatformAuthenticatorAvailable = () => Promise.resolve(true);

    Object.defineProperty(window, "PublicKeyCredential", {
        configurable: true,
        writable: true,
        value: publicKeyCredential,
    });

    return stub;
}

function removeWebAuthnApis(): void {
    Object.defineProperty(window, "PublicKeyCredential", {
        configurable: true,
        writable: true,
        value: undefined,
    });
}

afterEach(() => {
    vi.restoreAllMocks();
    Object.defineProperty(globalThis, "navigator", {
        configurable: true,
        value: originalNavigator,
    });
    removeWebAuthnApis();
});

describe("feature detection", () => {
    it("PublicKeyCredential 不在では未対応と判定する", () => {
        removeWebAuthnApis();
        expect(isPasskeySupported()).toBe(false);
    });

    it("PublicKeyCredential があれば対応と判定する", () => {
        stubWebAuthnApis();
        expect(isPasskeySupported()).toBe(true);
    });

    it("未対応端末では canCreatePasskey が false (throw しない)", async () => {
        removeWebAuthnApis();
        await expect(canCreatePasskey()).resolves.toBe(false);
    });

    it("isUserVerifyingPlatformAuthenticatorAvailable の reject を false に畳む", async () => {
        stubWebAuthnApis();
        (
            window.PublicKeyCredential as unknown as {
                isUserVerifyingPlatformAuthenticatorAvailable: () => Promise<boolean>;
            }
        ).isUserVerifyingPlatformAuthenticatorAvailable = () => Promise.reject(new Error("nope"));

        await expect(canCreatePasskey()).resolves.toBe(false);
    });

    it("未対応端末では ceremony が unsupported を返す (例外にしない)", async () => {
        removeWebAuthnApis();
        await expect(createPasskeyCredential()).resolves.toEqual({ status: "unsupported" });
        await expect(loginWithPasskey()).resolves.toEqual({ status: "unsupported" });
        await expect(confirmWithPasskey()).resolves.toEqual({ status: "unsupported" });
    });
});

describe("base64url", () => {
    it("往復して元の文字列に戻る", () => {
        const samples = ["AQIDBA", "-_-_", "aGVsbG8", "AA"];
        for (const sample of samples) {
            expect(bufferToBase64Url(base64UrlToBuffer(sample))).toBe(sample);
        }
    });

    it("padding / + / を含まない", () => {
        const bytes = new Uint8Array([251, 255, 190, 239]);
        const encoded = bufferToBase64Url(bytes.buffer);
        expect(encoded).not.toContain("=");
        expect(encoded).not.toContain("+");
        expect(encoded).not.toContain("/");
    });
});

describe("ceremony の分岐", () => {
    let fetchMock: ReturnType<typeof vi.fn>;

    beforeEach(() => {
        fetchMock = vi.fn();
        vi.stubGlobal("fetch", fetchMock);
    });

    function optionsResponse(options: Record<string, unknown>): unknown {
        return { ok: true, status: 200, json: () => Promise.resolve({ options }) };
    }

    const loginOptions = {
        challenge: "AQIDBA",
        rpId: "localhost",
        allowCredentials: [{ id: "AQIDBA", type: "public-key" }],
        userVerification: "required",
        timeout: 60000,
    };

    it("ユーザーキャンセル (NotAllowedError) を cancelled に畳む", async () => {
        const credentials = stubWebAuthnApis();
        fetchMock.mockResolvedValue(optionsResponse(loginOptions));
        const cancelled = new Error("cancelled");
        cancelled.name = "NotAllowedError";
        credentials.get.mockRejectedValue(cancelled);

        await expect(loginWithPasskey()).resolves.toEqual({ status: "cancelled" });
    });

    it("options 取得失敗は failed (メッセージ付き)", async () => {
        stubWebAuthnApis();
        fetchMock.mockResolvedValue({ ok: false, status: 500, json: () => Promise.resolve({}) });

        const outcome = await loginWithPasskey();
        expect(outcome.status).toBe("failed");
    });

    /*
     * F-2-02 (bug-hunt 20260811-003230): 429 が汎用の「開始できませんでした」に畳まれ、
     * 流量制限だと分からないためユーザーが連打して状況を悪化させていた。
     * 429 だけが「待てば直る」という他と質の違う行動指針を持つので、それだけを区別する。
     */
    const RATE_LIMITED_FAILURE =
        "リクエストが続けて行われました。少し時間をおいてからお試しください。";

    it("options が 429 のとき流量制限だと分かる文言を返す (F-2-02)", async () => {
        stubWebAuthnApis();
        fetchMock.mockResolvedValue({ ok: false, status: 429, json: () => Promise.resolve({}) });

        await expect(loginWithPasskey()).resolves.toEqual({
            status: "failed",
            message: RATE_LIMITED_FAILURE,
        });
    });

    it("options が 500 のときは汎用文言のまま (429 だけを特別扱いする)", async () => {
        // negative control: 429 判定が「あらゆる失敗」に反応していないことを示す
        stubWebAuthnApis();
        fetchMock.mockResolvedValue({ ok: false, status: 500, json: () => Promise.resolve({}) });

        await expect(loginWithPasskey()).resolves.toEqual({
            status: "failed",
            message: "パスキーの認証を開始できませんでした。",
        });
    });

    it("登録 options が 429 のとき流量制限の文言を返す", async () => {
        stubWebAuthnApis();
        fetchMock.mockResolvedValue({ ok: false, status: 429, json: () => Promise.resolve({}) });

        await expect(createPasskeyCredential()).resolves.toEqual({
            status: "failed",
            message: RATE_LIMITED_FAILURE,
        });
    });

    it("POST が 429 のとき本文の英語 message ではなく流量制限の文言を返す", async () => {
        const credentials = stubWebAuthnApis();
        fetchMock.mockImplementation((url: string) =>
            url.endsWith("/options")
                ? Promise.resolve(optionsResponse(loginOptions))
                : Promise.resolve({
                      ok: false,
                      status: 429,
                      json: () => Promise.resolve({ message: "Too Many Requests" }),
                  }),
        );

        const credential = Object.create(
            (window.PublicKeyCredential as unknown as { prototype: object }).prototype,
        ) as Record<string, unknown>;
        credential.id = "cred-id";
        credential.rawId = new Uint8Array([1, 2, 3, 4]).buffer;
        credential.type = "public-key";
        credential.response = {};
        credentials.get.mockResolvedValue(credential);

        await expect(loginWithPasskey()).resolves.toEqual({
            status: "failed",
            message: RATE_LIMITED_FAILURE,
        });
    });

    it("options 取得は Accept: application/json を送る", async () => {
        stubWebAuthnApis();
        fetchMock.mockResolvedValue({ ok: false, status: 500, json: () => Promise.resolve({}) });

        await loginWithPasskey();

        expect(fetchMock).toHaveBeenCalledWith(
            "/passkeys/login/options",
            expect.objectContaining({
                method: "GET",
                credentials: "same-origin",
                headers: expect.objectContaining({ Accept: "application/json" }),
            }),
        );
    });

    it("登録 ceremony は options endpoint を叩き、送信までは行わない", async () => {
        const credentials = stubWebAuthnApis();
        fetchMock.mockResolvedValue({ ok: false, status: 500, json: () => Promise.resolve({}) });

        await createPasskeyCredential();

        expect(fetchMock).toHaveBeenCalledTimes(1);
        expect(fetchMock.mock.calls[0][0]).toBe("/user/passkeys/options");
        expect(credentials.create).not.toHaveBeenCalled();
    });

    it("confirm は POST に CSRF / Content-Type ヘッダを付ける", async () => {
        const credentials = stubWebAuthnApis();
        document.cookie = "XSRF-TOKEN=test-token";
        fetchMock.mockImplementation((url: string) =>
            url.endsWith("/options")
                ? Promise.resolve(optionsResponse(loginOptions))
                : Promise.resolve({ ok: true, status: 204, json: () => Promise.resolve({}) }),
        );

        // navigator.credentials.get が PublicKeyCredential インスタンスを返すよう偽装する
        const credential = Object.create(
            (window.PublicKeyCredential as unknown as { prototype: object }).prototype,
        ) as Record<string, unknown>;
        credential.id = "cred-id";
        credential.rawId = new Uint8Array([1, 2, 3, 4]).buffer;
        credential.type = "public-key";
        credential.response = {};
        credentials.get.mockResolvedValue(credential);

        const outcome = await confirmWithPasskey();

        expect(outcome.status).toBe("ok");
        const postCall = fetchMock.mock.calls.find(([url]) => url === "/passkeys/confirm");
        expect(postCall).toBeDefined();
        expect(postCall?.[1]).toMatchObject({
            method: "POST",
            headers: expect.objectContaining({
                Accept: "application/json",
                "Content-Type": "application/json",
                "X-XSRF-TOKEN": "test-token",
            }),
        });
    });

    it("login は redirect を含まない応答を failed に畳む (非 JSON / 想定外 shape の拒否)", async () => {
        const credentials = stubWebAuthnApis();
        fetchMock.mockImplementation((url: string) =>
            url.endsWith("/options")
                ? Promise.resolve(optionsResponse(loginOptions))
                : Promise.resolve({ ok: true, status: 200, json: () => Promise.resolve({}) }),
        );

        const credential = Object.create(
            (window.PublicKeyCredential as unknown as { prototype: object }).prototype,
        ) as Record<string, unknown>;
        credential.id = "cred-id";
        credential.rawId = new Uint8Array([1, 2, 3, 4]).buffer;
        credential.type = "public-key";
        credential.response = {};
        credentials.get.mockResolvedValue(credential);

        const outcome = await loginWithPasskey();
        expect(outcome.status).toBe("failed");
    });
});
