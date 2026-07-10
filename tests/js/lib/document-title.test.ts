/**
 * Tests for resources/js/lib/document-title.ts
 *
 * 公開契約:
 * - applyDocumentTitle: 有効な string のみ document.title を更新し、非 string / 空文字は no-op
 * - registerDocumentTitleSync: router.on('navigate') を購読し、SPA 遷移 (props.title 変化) ごとに
 *   document.title を追従させる。初回は注入 page の props.title を反映。サーバ未供給 (title 欠落) では
 *   既存 title を温存。返り値 disposer で購読解除できる (HMR / テスト二重登録防止)。
 */
import { afterEach, describe, expect, it, vi } from "vitest";

import {
    applyDocumentTitle,
    registerDocumentTitleSync,
    type DocumentTitlePage,
    type DocumentTitleRouter,
} from "@/lib/document-title";

describe("applyDocumentTitle", () => {
    afterEach(() => {
        document.title = "";
    });

    it("trim 済みの文字列を document.title に設定する", () => {
        applyDocumentTitle("ダッシュボード | Acme");
        expect(document.title).toBe("ダッシュボード | Acme");

        applyDocumentTitle("  プロジェクト | Acme  ");
        expect(document.title).toBe("プロジェクト | Acme");
    });

    it("非 string は no-op (サーバ描画済み title を温存)", () => {
        document.title = "既存タイトル | Acme";
        applyDocumentTitle(undefined);
        applyDocumentTitle(null);
        applyDocumentTitle(123);
        applyDocumentTitle({ title: "x" });
        expect(document.title).toBe("既存タイトル | Acme");
    });

    it("空 / 空白のみの文字列は no-op", () => {
        document.title = "既存タイトル | Acme";
        applyDocumentTitle("");
        applyDocumentTitle("   ");
        expect(document.title).toBe("既存タイトル | Acme");
    });
});

/** テスト用に navigate ハンドラを手動発火できる router スタブを作る。 */
function makeRouter(): {
    router: DocumentTitleRouter;
    navigate(props: Record<string, unknown> | undefined): void;
    on: DocumentTitleRouter["on"];
    off: ReturnType<typeof vi.fn>;
} {
    let handler:
        | ((event: {
              detail: { page?: { props?: Record<string, unknown> } };
          }) => void)
        | null = null;
    const off = vi.fn();
    const on: DocumentTitleRouter["on"] = vi.fn((_event, callback) => {
        handler = callback;
        return off;
    });
    return {
        router: { on },
        navigate(props) {
            handler?.({
                detail: { page: props === undefined ? undefined : { props } },
            });
        },
        on,
        off,
    };
}

describe("registerDocumentTitleSync (navigate イベント購読)", () => {
    afterEach(() => {
        document.title = "";
    });

    it("'navigate' イベントを購読する", () => {
        const { router, on } = makeRouter();

        registerDocumentTitleSync(router, { props: {} });

        expect(on).toHaveBeenCalledTimes(1);
        expect(vi.mocked(on).mock.calls[0][0]).toBe("navigate");
    });

    it("登録時に注入 page の title を反映する (フルロード分の初期同期)", () => {
        document.title = "ログイン | Acme";
        const { router } = makeRouter();
        const page: DocumentTitlePage = {
            props: { title: "ダッシュボード | Acme" },
        };

        registerDocumentTitleSync(router, page);

        expect(document.title).toBe("ダッシュボード | Acme");
    });

    it("連続する navigate ごとに document.title を追従させる", () => {
        const { router, navigate } = makeRouter();
        const page: DocumentTitlePage = {
            props: { title: "ダッシュボード | Acme" },
        };

        registerDocumentTitleSync(router, page);
        expect(document.title).toBe("ダッシュボード | Acme");

        navigate({ title: "プロジェクト | Acme" });
        expect(document.title).toBe("プロジェクト | Acme");

        navigate({ title: "設定 | Acme" });
        expect(document.title).toBe("設定 | Acme");
    });

    it("navigate に title が無い場合は現在の title を温存する", () => {
        const { router, navigate } = makeRouter();
        const page: DocumentTitlePage = {
            props: { title: "ダッシュボード | Acme" },
        };

        registerDocumentTitleSync(router, page);
        expect(document.title).toBe("ダッシュボード | Acme");

        navigate({ someOtherProp: 3 });
        expect(document.title).toBe("ダッシュボード | Acme");

        navigate(undefined);
        expect(document.title).toBe("ダッシュボード | Acme");
    });

    it("disposer で購読解除できる (HMR / 二重登録防止)", () => {
        const { router, off } = makeRouter();
        const page: DocumentTitlePage = { props: {} };

        const dispose = registerDocumentTitleSync(router, page);
        expect(off).not.toHaveBeenCalled();

        dispose();
        expect(off).toHaveBeenCalledTimes(1);
    });
});
