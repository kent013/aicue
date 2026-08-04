/**
 * bug-hunt driver の一過性フィードバック記録器 (feedback probe) の契約テスト。
 *
 * 対象: `.claude/skills/app-bug-hunt/probes/feedback-probe.js`
 * probe は「モジュール」ではなく **式** (IIFE) なので import せず、実 driver と同じく
 * テキストとして読み `window.eval()` に渡して評価する。
 *
 * jsdom は layout を持たず `getClientRects()` が常に空配列を返すため、
 * `tests/Browser/FlashToastTest.php` と同じ可視判定が成立するよう
 * `Element.prototype.getClientRects` を stub する (probe 本体にテスト用フックは入れない)。
 *
 * **skip 条件を作らない**: probe ファイルが無ければ本テストは落ちる (silent skip による
 * false green を避けるため)。bug-hunt スキルごと撤去する場合は本テストも一緒に削除すること。
 */
import { readFileSync } from "node:fs";
import { resolve } from "node:path";
import { afterAll, beforeAll, describe, expect, it } from "vitest";

/** probe が返す 1 件の live region 観測。`visible` は seen entry にのみ付く。 */
interface ProbeEntry {
    role: string | null;
    testid: string | null;
    text: string;
    t: number;
    visible?: boolean | "gone";
    /** 可視判定が例外で解決できなかった場合のみ付く (visible は false に倒れる)。 */
    error?: string;
}

interface ProbeResult {
    installed_now: boolean;
    armed_at_ms: number;
    seen: ProbeEntry[];
    present_new: ProbeEntry[];
    present_preexisting: number;
    pending: number;
    errors: number;
}

const PROBE_PATH = resolve(
    process.cwd(),
    ".claude/skills/app-bug-hunt/probes/feedback-probe.js",
);

// scripts/run-vitest.sh が cd "$WORKSPACE" してから vitest を起動するので cwd はリポジトリルート。
const probeSource = readFileSync(PROBE_PATH, "utf8");

/** probe を 1 回叩く (実 driver の `playwright-cli --raw eval "$(cat ...)"` 相当)。 */
function probe(): ProbeResult {
    return JSON.parse(window.eval(probeSource) as string) as ProbeResult;
}

/** probe が window に持つ内部状態 (可視判定の未解決件数を読むためだけに使う)。 */
interface RecorderState {
    pending: number;
}

function recorderState(): RecorderState | undefined {
    return (window as unknown as { __bhFeedbackRecorder?: RecorderState })
        .__bhFeedbackRecorder;
}

/**
 * 「MutationObserver の配送 (microtask) → rAF での可視判定」が**解決し切るまで**待つ。
 *
 * 固定 sleep で待つと、他 worktree 同時走行で load が上がったとき rAF が間に合わず
 * `visible:true` のはずの entry が `gone` になって flaky になる (実測)。
 * 待つのは時間ではなく**条件** (`pending === 0`) にする。
 * 解決しなければ黙って通さず throw する (false green を作らない)。
 */
async function settle(): Promise<void> {
    for (let i = 0; i < 600; i++) {
        // setTimeout は microtask checkpoint を跨ぐので、最初の 1 回で observer の配送は済む
        await new Promise((r) => setTimeout(r, 10));
        const state = recorderState();
        if (!state || state.pending === 0) return;
    }
    throw new Error("feedback probe の pending が解決しなかった (6s 待機)");
}

/** `<div data-testid>` でラップした live region を作る (ToastContainer の構造に相当)。 */
function mk(
    role: string,
    txt: string,
    testid: string | null,
    style?: string,
): HTMLDivElement {
    const wrap = document.createElement("div");
    if (testid) wrap.setAttribute("data-testid", testid);
    const el = document.createElement("div");
    el.setAttribute("role", role);
    if (style) el.setAttribute("style", style);
    el.textContent = txt;
    wrap.appendChild(el);
    return wrap;
}

function host(): HTMLElement {
    const el = document.getElementById("bughunt-probe-host");
    if (!el) throw new Error("host element missing");
    return el;
}

// 以降の it は**順序依存**である (probe は window に記録器を持ち、基線を回ごとに更新するため)。
// 実 run の 1 セッションを再現している。`describe.sequential` で並列化を機械的に禁止する
// (コメントだけだと将来 vitest 設定で concurrent 既定にされたとき黙って壊れる)。
describe.sequential("bug-hunt feedback probe", () => {
    const originalGetClientRects = Element.prototype.getClientRects;
    // 例外経路 (N) の再現用。true の間だけ layout 取得が throw する。
    let rectsShouldThrow = false;

    beforeAll(() => {
        // jsdom は layout を持たない → FlashToastTest と同じ可視判定を成立させるための stub。
        // 「接続済み かつ hidden でない かつ display:none / visibility:hidden でない」なら矩形あり。
        Element.prototype.getClientRects = function (
            this: Element,
        ): DOMRectList {
            if (rectsShouldThrow) throw new Error("layout unavailable");
            const st = window.getComputedStyle(this);
            const ok =
                this.isConnected &&
                !(this as HTMLElement).hidden &&
                st.display !== "none" &&
                st.visibility !== "hidden";
            const rects = ok ? [{ width: 10, height: 10 }] : [];
            return rects as unknown as DOMRectList;
        };

        const h = document.createElement("div");
        h.id = "bughunt-probe-host";
        document.body.appendChild(h);
    });

    afterAll(() => {
        Element.prototype.getClientRects = originalGetClientRects;
    });

    it("A: 初回 probe が記録器を設置し、基線が無いので可視 live region は全て present_new", () => {
        host().appendChild(mk("status", "この組織は未契約です", "standing-alert"));
        host().appendChild(mk("alert", "前の操作のエラー", "toast-error"));

        const r = probe();
        expect(r.installed_now).toBe(true); // A1
        expect(r.present_new).toHaveLength(2); // A2
        expect(r.present_preexisting).toBe(0); // A2
    });

    it("B: 無操作の再 probe では常駐 live region が present_preexisting に落ちる", () => {
        const r = probe();
        expect(r.present_new).toHaveLength(0); // B1
        expect(r.present_preexisting).toBe(2); // B1
        expect(r.seen).toHaveLength(0); // B2
        expect(r.installed_now).toBe(false); // B2
    });

    it("C: auto-dismiss 後に読んでも seen に visible:true で残る (F-1-02 の機序)", async () => {
        const toast = mk("status", "動画マニュアルを削除しました", "toast-success");
        host().appendChild(toast);
        await settle(); // rAF で可視判定が解決
        toast.remove(); // auto-dismiss 相当
        await settle();

        const r = probe();
        expect(r.seen).toHaveLength(1); // C1
        expect(r.seen[0].visible).toBe(true); // C1
        expect(r.seen[0].testid).toBe("toast-success"); // C2
        expect(r.seen[0].text).toContain("削除しました"); // C2
        expect(r.present_new).toHaveLength(0); // C3
        expect(r.present_preexisting).toBe(2); // C3
        expect(r.pending).toBe(0); // C4
    });

    it("D: seen は drain される (二重計上しない)", () => {
        const r = probe();
        expect(r.seen).toHaveLength(0); // D1
    });

    it("E: display:none の live region は visible:false で記録 (証拠には数えない)", async () => {
        const invisible = mk("status", "見えない通知", "hidden-toast", "display:none");
        host().appendChild(invisible);
        await settle();
        invisible.remove();
        await settle();

        const r = probe();
        expect(r.seen).toHaveLength(1); // E1
        expect(r.seen[0].visible).toBe(false); // E1
    });

    it("F: 祖先 aria-hidden=true の live region は seen に入らない (記録時足切り)", async () => {
        const wrap = document.createElement("div");
        wrap.setAttribute("aria-hidden", "true");
        host().appendChild(wrap);
        wrap.appendChild(mk("status", "aria-hidden 配下", null));
        await settle();

        const r = probe();
        expect(r.seen).toHaveLength(0); // F1
    });

    it("G: 1 フレーム以内に消えた点滅は visible:'gone'", async () => {
        const flash = mk("status", "一瞬", "flash");
        host().appendChild(flash);
        flash.remove();
        await settle();

        const r = probe();
        expect(r.seen).toHaveLength(1); // G1
        expect(r.seen[0].visible).toBe("gone"); // G1
    });

    it("H: 非 live-region の DOM 変化は拾わない", async () => {
        host().appendChild(document.createElement("p"));
        await settle();

        const r = probe();
        expect(r.seen).toHaveLength(0); // H1
        expect(r.present_new).toHaveLength(0); // H1
    });

    it("I: 既存 live region の in-place テキスト更新 (characterData) を捕捉", async () => {
        const standing = document.querySelector(
            "[data-testid=standing-alert] [role=status]",
        );
        if (!standing || !standing.firstChild) throw new Error("fixture missing");
        (standing.firstChild as Text).data = "契約が失効しました";
        await settle();

        const r = probe();
        expect(r.seen).toHaveLength(1); // I1
        expect(r.seen[0].visible).toBe(true); // I1
        expect(r.present_new).toHaveLength(1); // I2
    });

    it("J: hidden→visible は属性を監視しなくても基線差分で present_new に出る", async () => {
        const toggled = mk("status", "切替通知", "toggle", "display:none");
        host().appendChild(toggled);
        await settle();
        probe(); // ここでは不可視 → 基線は刻まれない

        const inner = toggled.querySelector("[role=status]");
        if (!inner) throw new Error("fixture missing");
        inner.setAttribute("style", "");

        const r = probe();
        expect(r.present_new.some((e) => e.text === "切替通知")).toBe(true); // J1
    });

    it("M: 既存 live region 内のテキストノード差し替えを seen で捕捉", async () => {
        const std = document.querySelector(
            "[data-testid=standing-alert] [role=status]",
        );
        if (!std) throw new Error("fixture missing");
        std.textContent = ""; // 旧 Text を除去
        std.appendChild(document.createTextNode("再検証が必要です")); // 新 Text (childList)
        await settle();

        const r = probe();
        expect(
            r.seen.some((e) => e.visible === true && e.text === "再検証が必要です"),
        ).toBe(true); // M1
    });

    it("N: 可視判定が例外で解決できないと pending は戻り errors に数えられる", async () => {
        rectsShouldThrow = true;
        const broken = mk("status", "判定不能な通知", "broken-toast");
        host().appendChild(broken);
        await settle(); // rAF 内で visible() が throw する
        rectsShouldThrow = false; // probe 本体 (present_new の同期評価) は正常に動かす

        const r = probe();
        expect(r.pending).toBe(0); // N1 例外でも pending は対称に戻る (恒久残留しない)
        const entry = r.seen.find((e) => e.text === "判定不能な通知");
        expect(entry).toBeDefined();
        expect(entry?.error).toBeDefined(); // N2 判定不能であることが残る
        expect(entry?.visible).toBe(false); // N2 証拠には数えない
        expect(r.errors).toBe(1); // N3 陰性判断に使えないことが機械的に読める

        // N4 errors は drain した batch 単位。次の probe では 0 に戻る
        // (= 再 probe 時に 2 回目の errors:0 だけを見ると 1 回目の判定不能を取り落とす。
        //   だから SKILL.md の統合規則は errors を「いずれかで非 0 なら未検証」と定めている)
        expect(probe().errors).toBe(0);
    });

    it("K: 記録器の喪失 (cross-document 相当) を installed_now:true で検知", () => {
        // @ts-expect-error probe が window に持つ内部状態 (型定義は置かない)
        delete window.__bhFeedbackRecorder;

        const r = probe();
        expect(r.installed_now).toBe(true); // K1
    });
});
