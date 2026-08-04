/*
 * bug-hunt driver 用「一過性フィードバック記録器」(feedback probe)。
 *
 * 使い方 (正本は .claude/skills/app-bug-hunt/SKILL.md §走行プロトコル):
 *   playwright-cli --raw eval "$(cat "$(git rev-parse --show-toplevel)/.claude/skills/app-bug-hunt/probes/feedback-probe.js")"
 *
 * なぜ必要か: toast (success/info/warning) は 4000ms、CodeSnippet の「コピー完了」は 2000ms で
 * 自動消滅する (resources/js/lib/stores/toast.ts / components/molecules/CodeSnippet.svelte)。
 * 事後 snapshot 1 点では「出なかった」と「もう消えた」を区別できず、誤検知になる
 * (run 20260803-203721 の F-1-02 = 誤検知確定)。そこで **操作の前に記録器を仕込む**。
 *
 * 返り値 (JSON 文字列):
 *   installed_now         : 今回の呼び出しで記録器を新規設置したか (true = 直前に document が置換された)
 *   seen[]                : 前回 probe 以降に出現/変化した live region (消えた後も残る)
 *                           visible: true=可視 / false=不可視 / gone=1 フレーム以内に消えた
 *   present_new[]         : 現在 DOM にある live region のうち「基線が無い or テキストが変わった」もの
 *   present_preexisting   : 基線と一致する常駐 live region の件数 (判定に使わない)
 *   pending               : 可視性判定が未解決の候補数 (>0 ならもう一度 probe する)
 */
(() => {
    const KEY = "__bhFeedbackRecorder";
    const LIVE = "[role=status],[role=alert]";
    const raf =
        typeof window.requestAnimationFrame === "function"
            ? window.requestAnimationFrame.bind(window)
            : (cb) => setTimeout(cb, 0);

    /** layout 非依存の足切り (mutation callback 内で使う)。 */
    const plausible = (el) =>
        el.isConnected && !el.hidden && !el.closest("[aria-hidden=true]");

    /** 完全な可視判定 (layout 依存。FlashToastTest.php と同じ条件)。 */
    const visible = (el) => {
        if (!plausible(el)) return false;
        const style = window.getComputedStyle(el);
        return (
            style.visibility !== "hidden" &&
            style.display !== "none" &&
            el.getClientRects().length > 0
        );
    };

    const text = (el) => (el.textContent || "").replace(/\s+/g, " ").trim().slice(0, 200);

    const describe = (el) => {
        const host = el.closest("[data-testid]");
        return {
            role: el.getAttribute("role"),
            testid: host ? host.getAttribute("data-testid") : null,
            text: text(el),
            t: Math.round(performance.now()),
        };
    };

    const collect = (node) => {
        const out = [];
        if (!node || node.nodeType !== 1) return out;
        if (node.matches(LIVE)) out.push(node);
        for (const el of node.querySelectorAll(LIVE)) out.push(el);
        return out;
    };

    let installedNow = false;

    if (!window[KEY]) {
        installedNow = true;
        const state = { seen: [], pending: 0, armedAt: Math.round(performance.now()) };

        // 候補を「生きているうちに」次フレームで可視判定してから seen に確定する。
        // 一過性 UI は消えた後では可視性を測れないため、記録時点の同期評価では足りない。
        const enqueue = (el) => {
            // layout 非依存の足切り (detached でも closest は辿れる)
            if (el.hidden || el.closest("[aria-hidden=true]")) return;
            const entry = describe(el);
            if (!el.isConnected) {
                // callback 到達前に消えた = 1 フレーム未満の点滅 (知覚不能)。捨てずに gone で残す
                entry.visible = "gone";
                state.seen.push(entry);
                return;
            }
            state.pending += 1;
            raf(() => {
                entry.visible = el.isConnected ? visible(el) : "gone";
                state.pending -= 1;
                state.seen.push(entry);
            });
        };

        state.observer = new MutationObserver((records) => {
            for (const r of records) {
                for (const n of r.addedNodes) for (const el of collect(n)) enqueue(el);
                // 既存 live region の**中身が差し替えられた**場合 (Svelte のテキストノード置換)。
                // addedNodes は Text なので collect() では拾えず、characterData も発火しない。
                if (r.type === "childList" && r.addedNodes.length > 0 && r.target.nodeType === 1) {
                    const host = r.target.closest(LIVE);
                    if (host) enqueue(host);
                }
                if (r.type === "characterData") {
                    const host = r.target.parentElement && r.target.parentElement.closest(LIVE);
                    if (host) enqueue(host);
                }
            }
        });
        state.observer.observe(document.documentElement, {
            childList: true,
            subtree: true,
            characterData: true,
        });
        window[KEY] = state;
    }

    const state = window[KEY];

    // 基線差分: 「前回 probe 以降に可視化された / テキストが変わった」live region だけを新規とする。
    // 常駐 Alert (atoms/Alert.svelte) や自動消去しない error toast を証拠にしないための核。
    const presentNew = [];
    let preexisting = 0;
    for (const el of document.querySelectorAll(LIVE)) {
        if (!visible(el)) continue;
        const current = text(el);
        if (el.__bhBaseline === undefined || el.__bhBaseline !== current) presentNew.push(describe(el));
        else preexisting += 1;
        el.__bhBaseline = current;
    }

    return JSON.stringify({
        installed_now: installedNow,
        armed_at_ms: state.armedAt,
        seen: state.seen.splice(0),
        present_new: presentNew,
        present_preexisting: preexisting,
        pending: state.pending,
    });
})()
