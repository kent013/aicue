import { afterEach, beforeEach, describe, expect, it, vi } from "vitest";
import { captureFetch, extractErrorMessage } from "@/lib/capture/http";

/*
 * captureFetch: X-XSRF-TOKEN 常時付与 + 419 は cookie 再取得 → 1 回だけ再送。
 * 再取得後の再送は更新後の cookie 値を読む (旧トークンを使い回さない)。
 */

const fetchMock = vi.fn();

function jsonResponse(status: number, body: unknown = {}): Response {
    // 204 は body を持てない (Response コンストラクタ制約)
    if (status === 204) {
        return new Response(null, { status });
    }
    return new Response(JSON.stringify(body), {
        status,
        headers: { "Content-Type": "application/json" },
    });
}

beforeEach(() => {
    vi.stubGlobal("fetch", fetchMock);
    document.cookie = "XSRF-TOKEN=initial-token";
});

afterEach(() => {
    fetchMock.mockReset();
    vi.unstubAllGlobals();
    document.cookie = "XSRF-TOKEN=; expires=Thu, 01 Jan 1970 00:00:00 GMT";
});

describe("captureFetch", () => {
    it("X-XSRF-TOKEN / X-Requested-With / Accept を常時付与する", async () => {
        fetchMock.mockResolvedValueOnce(jsonResponse(200));

        await captureFetch("/organizations/test-org/app/projects/1/manuals/2/sync", { method: "POST" });

        const [, init] = fetchMock.mock.calls[0];
        expect(init.headers["X-XSRF-TOKEN"]).toBe("initial-token");
        expect(init.headers["X-Requested-With"]).toBe("XMLHttpRequest");
        expect(init.headers.Accept).toBe("application/json");
        expect(init.credentials).toBe("same-origin");
    });

    it("419 は /organizations/test-org/app/csrf-cookie 再取得 → 1 回だけ再送し、再送は更新後の token を使う", async () => {
        fetchMock
            .mockResolvedValueOnce(jsonResponse(419)) // 元リクエスト
            .mockImplementationOnce(async () => {
                // cookie 再取得 GET が新しい token を発行する
                document.cookie = "XSRF-TOKEN=refreshed-token";
                return jsonResponse(204);
            })
            .mockResolvedValueOnce(jsonResponse(200));

        const response = await captureFetch("/organizations/test-org/app/target", { method: "POST" });

        expect(response.status).toBe(200);
        expect(fetchMock).toHaveBeenCalledTimes(3);
        expect(fetchMock.mock.calls[1][0]).toBe("/organizations/test-org/app/csrf-cookie");
        // 再送 (3 回目) は旧値でなく更新後 cookie の token
        expect(fetchMock.mock.calls[2][1].headers["X-XSRF-TOKEN"]).toBe("refreshed-token");
    });

    it("2 連続 419 は再々送せず 419 を返す (無限ループ防止)", async () => {
        fetchMock
            .mockResolvedValueOnce(jsonResponse(419))
            .mockResolvedValueOnce(jsonResponse(204)) // csrf-cookie
            .mockResolvedValueOnce(jsonResponse(419)); // 再送も 419

        const response = await captureFetch("/organizations/test-org/app/target", { method: "POST" });

        expect(response.status).toBe(419);
        expect(fetchMock).toHaveBeenCalledTimes(3);
    });

    it("cookie 再取得が失敗 (非 2xx) したら元の 419 を返す", async () => {
        fetchMock
            .mockResolvedValueOnce(jsonResponse(419))
            .mockResolvedValueOnce(jsonResponse(500));

        const response = await captureFetch("/organizations/test-org/app/target", { method: "POST" });

        expect(response.status).toBe(419);
        expect(fetchMock).toHaveBeenCalledTimes(2);
    });

    it("cookie 再取得が network error なら元の 419 を返す", async () => {
        fetchMock
            .mockResolvedValueOnce(jsonResponse(419))
            .mockRejectedValueOnce(new TypeError("network error"));

        const response = await captureFetch("/organizations/test-org/app/target", { method: "POST" });

        expect(response.status).toBe(419);
    });
});

describe("extractErrorMessage", () => {
    it("message → errors の先頭 → 既定文言 の順で取り出す", async () => {
        expect(await extractErrorMessage(jsonResponse(422, { message: "上限です" }))).toBe("上限です");
        expect(
            await extractErrorMessage(jsonResponse(422, { message: "", errors: { take: ["削除できません"] } })),
        ).toBe("削除できません");
        expect(await extractErrorMessage(new Response("<html>", { status: 500 }))).toContain(
            "操作に失敗しました",
        );
    });
});
