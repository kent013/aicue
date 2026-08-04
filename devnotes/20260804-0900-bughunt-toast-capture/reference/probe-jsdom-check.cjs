/*
 * 設計フェーズの検証ハーネス (成果物ではない)。
 *
 * feedback-probe.js の契約を jsdom で実測するための使い捨てスクリプト。
 * 実装時は同じ 18 ケースを vitest (tests/js/bughunt/feedback-probe.test.ts) に移植する。
 *
 *   node devnotes/20260804-0900-bughunt-toast-capture/reference/probe-jsdom-check.cjs
 *
 * jsdom は layout を持たず getClientRects() が常に空配列を返すため、
 * FlashToastTest.php と同じ可視判定が成立するよう Element.prototype.getClientRects を stub する
 * (probe 本体にはテスト用フックを入れない)。
 */
// jsdom はリポジトリの devDependency (vitest の environment) を借りる
const { JSDOM } = require(require.resolve("jsdom", { paths: [require("node:path").resolve(__dirname, "../../..")] }));
const { readFileSync } = require("node:fs");
const SRC = process.env.PROBE_SRC || require("node:path").resolve(__dirname, "feedback-probe.js");
const src = readFileSync(SRC, "utf8");
const dom = new JSDOM(`<!doctype html><body><div id="host"></div></body>`, { runScripts: "outside-only", pretendToBeVisual: true });
const w = dom.window;

// jsdom は layout を持たない → FlashToastTest と同じ可視判定を成立させるため stub する。
// 「接続済み かつ hidden でない かつ display:none でない」なら rect を返す。
w.Element.prototype.getClientRects = function () {
  const st = w.getComputedStyle(this);
  const ok = this.isConnected && !this.hidden && st.display !== "none" && st.visibility !== "hidden";
  return ok ? [{ width: 10, height: 10 }] : [];
};

const probe = () => JSON.parse(w.eval(src));
const tick = (ms = 150) => new Promise((r) => setTimeout(r, ms));
const mk = (role, txt, testid, style) => {
  const wrap = w.document.createElement("div");
  if (testid) wrap.setAttribute("data-testid", testid);
  const el = w.document.createElement("div");
  el.setAttribute("role", role);
  if (style) el.setAttribute("style", style);
  el.textContent = txt;
  wrap.appendChild(el);
  return wrap;
};
const host = () => w.document.getElementById("host");
const assert = (c, m) => console.log((c ? "PASS" : "**FAIL**") + " " + m);

(async () => {
  // A. 常駐 Alert (arm 前から居る) と 残存 error toast
  host().appendChild(mk("status", "この組織は未契約です", "standing-alert"));
  host().appendChild(mk("alert", "前の操作のエラー", "toast-error"));
  let r = probe();
  assert(r.installed_now === true, "A1 初回 probe = arm");
  assert(r.present_new.length === 2 && r.present_preexisting === 0, "A2 arm 時は基線が無いので全部 new");

  // B. 何もせず再 probe → 常駐分は preexisting に落ちる (証拠にならない)
  r = probe();
  assert(r.present_new.length === 0 && r.present_preexisting === 2, "B1 常駐 live region は present_new に出ない");
  assert(r.seen.length === 0 && r.installed_now === false, "B2 seen 空 / 記録器は生存");

  // C. 操作: toast が出て 4 秒相当で消える → 消えた後に読む (F-1-02 の機序)
  const toast = mk("status", "動画マニュアルを削除しました", "toast-success");
  host().appendChild(toast);
  await tick();          // rAF で可視判定が解決
  toast.remove();        // auto-dismiss
  await tick();
  r = probe();
  assert(r.seen.length === 1 && r.seen[0].visible === true, "C1 消えた後でも seen に visible:true で残る");
  assert(r.seen[0].testid === "toast-success" && r.seen[0].text.includes("削除しました"), "C2 testid/本文が取れる");
  assert(r.present_new.length === 0 && r.present_preexisting === 2, "C3 常駐分は依然 preexisting");
  assert(r.pending === 0, "C4 pending 解決済み");

  // D. drain (二重計上しない)
  r = probe();
  assert(r.seen.length === 0, "D1 seen は drain される");

  // E. 不可視 live region は証拠にしない
  const invisible = mk("status", "見えない通知", "hidden-toast", "display:none");
  host().appendChild(invisible);
  await tick();
  invisible.remove();
  await tick();
  r = probe();
  assert(r.seen.length === 1 && r.seen[0].visible === false, "E1 display:none は visible:false で記録 (証拠にしない)");

  // F. aria-hidden 配下は記録すらしない
  const wrap = w.document.createElement("div");
  wrap.setAttribute("aria-hidden", "true");
  host().appendChild(wrap);
  wrap.appendChild(mk("status", "aria-hidden 配下", null));
  await tick();
  r = probe();
  assert(r.seen.length === 0, "F1 aria-hidden 祖先の live region は seen に入らない");

  // G. 1 フレーム以内に消えたもの = gone
  const flash = mk("status", "一瞬", "flash");
  host().appendChild(flash);
  flash.remove();
  await tick();
  r = probe();
  assert(r.seen.length === 1 && r.seen[0].visible === "gone", "G1 サブフレーム点滅は gone");

  // H. 非 live-region の DOM 変化は拾わない
  host().appendChild(w.document.createElement("p"));
  await tick();
  r = probe();
  assert(r.seen.length === 0 && r.present_new.length === 0, "H1 ノイズを拾わない");

  // I. 既存 live region の in-place テキスト更新
  const standing = w.document.querySelector('[data-testid=standing-alert] [role=status]');
  standing.firstChild.data = "契約が失効しました";
  await tick();
  r = probe();
  assert(r.seen.length === 1 && r.seen[0].visible === true, "I1 characterData 更新を捕捉");
  assert(r.present_new.length === 1, "I2 テキスト変化は present_new にも出る");

  // J. hidden -> visible (属性は監視しないが基線差分で拾える)
  const toggled = mk("status", "切替通知", "toggle", "display:none");
  host().appendChild(toggled);
  await tick();
  r = probe();  // ここでは不可視 → 基線は刻まれない
  toggled.querySelector("[role=status]").setAttribute("style", "");
  r = probe();
  assert(r.present_new.some((e) => e.text === "切替通知"), "J1 hidden→visible は present_new で拾える");

  // M. 既存 live region 内のテキストノード**差し替え** (Svelte の {#if}/{expr} 更新に相当)
  const std = w.document.querySelector('[data-testid=standing-alert] [role=status]');
  std.textContent = "";                       // 旧 Text を除去
  std.appendChild(w.document.createTextNode("再検証が必要です")); // 新 Text を追加 (childList)
  await tick();
  r = probe();
  assert(r.seen.some((e) => e.visible === true && e.text === "再検証が必要です"), "M1 テキストノード差し替えを seen で捕捉");

  // K. window 喪失 (cross-document 相当)
  delete w.__bhFeedbackRecorder;
  r = probe();
  assert(r.installed_now === true, "K1 記録器喪失を installed_now:true で検知");
})();
