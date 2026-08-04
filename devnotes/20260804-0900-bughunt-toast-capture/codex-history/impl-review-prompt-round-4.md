# impl-review Round 4 (確認のみ)

Round 3 の [Critical] 1 件 + [Suggestion] 1 件を反映した。変更は
`SKILL.md` 本文 2 箇所と `feedback-probe.test.ts` の assertion 1 件のみで、
**probe 本体 (`feedback-probe.js`) と `test_spec_ledger.py` は無変更** (Round 3 で APPROVED)。

---

## 対応マトリクス

# 対応マトリクス: impl-review Round 3

## [Critical] SKILL.md: 複数 probe 応答を統合するときの `errors` が未定義 (SKILL.md:286)

- 判断: **対応する** (指摘は正しい。私の Round 2 の修正に穴が残っていた)
- 根拠: `errors` は **drain した batch 単位の件数**なので、1 回目 `errors:1, pending:1` →
  再 probe `errors:0, pending:0` という並びが実際に起こりうる。
  Round 2 時点の規約は統合対象を `seen` / `present_new` の和集合としか書いておらず、
  **2 回目の `errors:0` を見て H7 陰性に倒す解釈が成立してしまう**。
  これは Round 2 [Critical] (判定不能を陰性証拠にしない) が再 probe 経路で破れることを意味し、
  「1 箇所塞いだつもりが別の入口が空いていた」という同じ失敗の繰り返しである。
- 対応内容: 判定表の `pending > 0` 行を「1 回目と 2 回目の**応答を統合**して判定する (統合規則は下記)」に改め、
  本文に **複数応答の統合規則**を独立項として明記した:
  - `seen` / `present_new` は**和集合** (1 回目の `present_new` は基線更新で 2 回目には
    `present_preexisting` に落ちるため、2 回目だけでは証拠を失う)。
  - `installed_now` / `errors` は **「いずれかの応答で真 / 非 0 なら操作全体でそう」** と扱う。
  - **陰性 (H7 起票) を主張してよいのは、統合後に `installed_now` が全て false、
    `errors` の合計が 0、最終応答の `pending` が 0 のときだけ。**
  これで「肯定証拠は和集合で拾い、陰性主張は全応答が揃って安全なときだけ許す」という
  非対称 (安全側に倒す) が文面から一意に読める。

## [Suggestion] `errors` が次回 drain で 0 に戻るテストを追加 (feedback-probe.test.ts:15)

- 判断: **対応する**
- 根拠: `errors` が batch 単位であることは上記統合規則の**前提**であり、
  前提がテストで固定されていないと規約だけが宙に浮く。
- 対応内容: ケース N に assertion **N4** を追加した (`expect(probe().errors).toBe(0)`)。
  コメントで「だから SKILL.md の統合規則は errors を『いずれかで非 0 なら未検証』と定めている」と
  規約への紐づけを残し、テストと規約が同じ事実を指していることを明示した。
  jsdom のケース数は 19 → **20** (詳細設計の表は 18。逸脱として記録する)。

## APPROVED 済み (Round 3 で変更なし)

- `.claude/skills/app-bug-hunt/probes/feedback-probe.js` — `errors` の batch 一致・`pending` の対称性・
  同期評価側の例外が probe 自体を失敗させる (= 陰性 JSON に偽装されない) 点を Codex が確認。
- `tests/js/bughunt/feedback-probe.test.ts` のケース N 本体 — false green の穴なしと評価。
- `.claude/skills/app-bug-hunt/ledger/test_spec_ledger.py` — 繰り返し化に過剰マッチなしと評価。
- `.claude/skills/app-bug-hunt/spec-ledger.md` / `.claude/agents/bughunt-shard.md` — Round 2 で APPROVED。

## ラウンド上限の扱い

`app-implement` SKILL.md の合議終了条件は「APPROVED になるまで。最大 3 ラウンド」。
Round 3 の残指摘は **SKILL.md 本文 2 箇所 + テスト 1 assertion** の局所修正で、
実装ロジック (probe 本体) には触れていない。この修正が意図どおりかの確認だけを目的に
**Round 4 (確認のみ)** を 1 回行う。上限を 1 超過している事実はここに記録する。


---

## 差分 (SKILL.md + feedback-probe.test.ts のみ)

```diff
diff --git a/.claude/skills/app-bug-hunt/SKILL.md b/.claude/skills/app-bug-hunt/SKILL.md
index e312fd6..11c4817 100644
--- a/.claude/skills/app-bug-hunt/SKILL.md
+++ b/.claude/skills/app-bug-hunt/SKILL.md
@@ -237,6 +237,9 @@ ### 走行プロトコル (各ステップ共通)
    対応するボタン/フォームが UI に見つからない operation は、それ自体を finding 候補として記録する。
 1. `playwright-cli snapshot` で現在地と要素 ref を確認 → カードの「操作」を実行。
 2. 遷移を伴う操作の後は再 snapshot して testid / テキスト出現を確認する (Inertia+Svelte は描画が非同期)。
+2b. **一過性フィードバック (toast 等) は事後 snapshot では観測できない。** 書き込み操作は
+   **直前に記録器を仕込み直後に読む** (§一過性フィードバックの観測)。「事後 snapshot に無い」を
+   根拠にフィードバック欠落を主張してはならない。
 3. カードの「期待」と照合 + 下の**横断ヒューリスティクス**を毎ステップ通す。
 4. `playwright-cli console`(error) と `playwright-cli requests`(4xx/5xx、外部ドメイン) を確認。
 5. 異常を見たら: `playwright-cli screenshot` で証跡保存 → finding 記録 → **finding は停止信号ではない**。
@@ -247,6 +250,74 @@ ### 走行プロトコル (各ステップ共通)
 8. **走行中に突然の 401/ログイン失敗が出たら、アプリバグと断定する前に DB 生存確認**
    (`tmp/bug-hunt/shard-0-cmd.sh db-check`)。空なら `reseed` してやり直し、環境ハザードとして記録。
 
+### 一過性フィードバックの観測 (feedback probe)
+
+**成功 toast は 4 秒で自動消滅する** (`resources/js/lib/stores/toast.ts` の `AUTO_DISMISS_MS`。
+error だけは消えない)。「コピー完了」表示は 2 秒 (`resources/js/components/molecules/CodeSnippet.svelte`)。
+driver の観測は「操作 → 事後 snapshot」の 1 点サンプリングで、Bash 1 往復ぶん (数百 ms〜数秒、
+並列 shard ではさらに遅延) 後ろにずれる。したがって **事後 snapshot に無いことは「出なかった」の
+証拠にならない** (run 20260803-203721 の F-1-02 が誤検知になった機序。spec-ledger.md 参照)。
+
+そこで **操作の直前に記録器を仕込み、直後に読む**。記録器は ARIA live region
+(`role="status"` / `role="alert"`) の出現・変化を記録するので、**消えた後でも読める**。
+
+```bash
+# 設置 (arm) と読み出しは同じ 1 コマンド (冪等)。--raw で JSON だけを受け取る
+playwright-cli --raw eval "$(cat "$(git rev-parse --show-toplevel)/.claude/skills/app-bug-hunt/probes/feedback-probe.js")"
+```
+
+**呼ぶタイミング**:
+- `open` / `goto` / `reload` / `go-back` / `go-forward` の**直後**に 1 回 (= arm)。
+- **各書き込み操作の直後**に 1 回 (= 読み出し)。この呼び出しが次の操作の arm を兼ねるので、
+  操作を続ける限り **1 操作 = probe 1 コール**で済む。
+- arm を忘れた場合や document が置換された場合は、次の読み出しが `installed_now:true` を返す。
+  **arm 漏れは黙って「フィードバック無し」にはならず、必ず「未検証」に倒れる** (下表 3 行目)。
+  逆に言えば **`installed_now:false` を得られていない操作について H7 陰性を主張してはならない**。
+
+**判定 (これを守ること)**:
+
+| 記録器の戻り値 | 解釈 | 行動 |
+|---|---|---|
+| `installed_now:false` かつ (`seen` の `visible:true` entry または `present_new`) に**操作結果を伝える文言**がある | 観測窓が連続し、ユーザーに見える変化を捕捉した | フィードバックあり → finding にしない |
+| `installed_now:false` かつ どちらにも無い (`pending:0` かつ `errors:0`) | 操作の全区間で記録器が生きていた = **本当に出なかった** | H7 finding 候補 |
+| `installed_now:true` | 途中で document が置換され記録器が失われた (基線も無い) | **肯定証拠のみ採用**: `present_new` または直後の `snapshot` に**操作結果を伝える文言**があれば「フィードバックあり」と結論してよい。無い場合は **未検証** (finding にしない)。基線が無いので常駐 live region も `present_new` に混ざる = 陰性判断には使えない |
+| `pending > 0` | 可視性判定が未解決 | probe をもう 1 回だけ叩き、**1 回目と 2 回目の応答を統合**して判定する (統合規則は下記)。2 回目も `pending > 0` なら**未検証** |
+| `errors > 0` | 可視判定そのものが例外で解決できなかった entry がある (`seen[].error`) | **陰性判断に使えない**。肯定証拠 (`visible:true` + 結果文言) があれば「フィードバックあり」でよいが、無ければ **未検証** (H7 finding にしない)。`visible:false` は「不可視だった」ではなく「判定不能」である |
+
+- **複数応答の統合規則** (再 probe したときは必ずこれで畳む):
+  `seen` / `present_new` は**和集合** (1 回目の `present_new` は基線更新で 2 回目には
+  `present_preexisting` に落ちるので、2 回目だけを見ると証拠を失う)。
+  一方 **`installed_now` / `errors` は「いずれかの応答で真 / 非 0 なら操作全体でそう」と扱う**
+  (`errors` は drain 単位の件数なので、2 回目が `errors:0` でも 1 回目の判定不能は消えない)。
+  **陰性 (H7 起票) を主張してよいのは、統合後に `installed_now` が全て false、
+  `errors` の合計が 0、最終応答の `pending` が 0 のときだけ。**
+- **`visible:false` / `visible:"gone"` は証拠に数えない** (返るのは診断のため)。
+- **件数ではなく本文で判定する**: `role="status"` は進捗表示にも使われうる
+  (`resources/js/components/atoms/Spinner.svelte` は `label` 指定時に `role="status"`)。
+  ローディング/進捗は「操作結果のフィードバック」ではない。
+  判定の目安 (最小辞書。網羅列挙ではない):
+  - **結果文言 (単独で採用してよい)**: 「〜しました」「完了」「成功」/
+    失敗系「〜できません」「失敗」「エラー」
+  - **文脈依存語 (単独では採用しない)**: 「削除」「変更」「保存」「作成」「更新」「送信」「招待」。
+    これらはボタン名・見出しにも出るので、**`role="status"` / `role="alert"` の中**か
+    **フィードバック用 testid (`toast-*` 等) の中**にある場合だけ採用する。
+    probe の `seen` / `present_new` は定義上 live region の中なのでこの制限に自動で適合する。
+    制限が効くのは `installed_now:true` 時に `snapshot` を肯定証拠に使う経路である。
+  - **数えない**: 「読み込み中」「処理中」「Loading」など進捗・状態表示、
+    および操作前から出ていた常駐 Alert (基線で `present_preexisting` に落ちる)
+- **H7 の「結果フィードバックが無い」は `installed_now:false` かつ `pending:0` かつ `errors:0` の
+  操作にのみ適用する。** `installed_now:true` / `pending>0` 継続 / `errors>0` で肯定証拠も得られなかった操作は
+  **`H7 未検証` として shard-report に件数と操作名を必ず出す** (無言の skip は禁止 = 走行プロトコル 7)。
+  この件数が run ごとに増えていくなら、probe 方式ではなく**導線側の観測設計**を見直す信号とする。
+  再実行は 1 回まで。**非冪等な破壊操作 (削除等) は再実行せず未検証のまま記録する。**
+- probe が空でも「**視覚的**フィードバックが無い」とまでは言えない (live region を持たない
+  一過性 UI は記録されない)。H14 (a11y) に格上げしてよいのは、snapshot / DOM 調査で
+  **視覚的な一過性フィードバックの存在を別途確認でき、かつ live region が無い**と示せた場合だけ。
+- **probe の結果を `findings.jsonl` の `symptom_tokens` に入れてはならない。**
+  `ledger/validate_findings.py` の `has_new_signal()` は symptom_tokens の新語で
+  adjudication を `ambiguous` に倒すため、probe 由来の語を混ぜると**既存 adjudication の
+  downrank が無効化される**。probe 出力は report.md の証跡欄に書く。
+
 ### 横断ヒューリスティクス (毎ステップ適用)
 
 | # | 兆候 | 既定 severity |
@@ -262,6 +333,9 @@ ### 横断ヒューリスティクス (毎ステップ適用)
 | H9 | 権限外データの表示・操作 (IDOR 含む。他組織/他プロジェクトのリソース) | Critical |
 | H10 | 文言・件数・状態が直前の操作結果と矛盾 (例: 作成したのに一覧に出ない) | High |
 
+> **H7 の前提条件**: 「結果フィードバックが無い」の判定には **feedback probe の陰性所見**が必須
+> (§一過性フィードバックの観測)。事後 snapshot に無いことを根拠に H7 を起票しない。
+
 **UI/UX ヒューリスティクス (H11-H14、視覚/操作品質。snapshot + screenshot で観察)**
 
 | # | 兆候 | 既定 severity |
@@ -288,7 +362,9 @@ ## F-{連番}: {一行サマリ}
 - 期待: / 実際:
 - 阻害されたユーザージョブ: (このバグでユーザーが達成できなくなった目的。使命接続の必須欄)
 - 改善アクション候補: (どう直せばユーザーが目的を達成できるか)
-- 証跡: screenshots/F-xx.png, console: ..., network: ...
+- 証跡: screenshots/F-xx.png, console: ..., network: ...,
+  feedback-probe: `installed_now=false seen=0(visible:true) present_new=0 pending=0 errors=0`
+  (フィードバック欠落を主張する finding では**必須**)
 - 推定原因: (code-review-graph で当たりを付ける。5 分で見つからなければ「未調査」)
 - 関連既知情報: (devnotes/TODO.md に同種の記録があれば参照。regression かどうかが重要)
 ```
@@ -322,6 +398,7 @@ # bug-hunt report {日時}
 - 操作カバレッジ: 実行 n / operations.md 対象 m (未実行を列挙、skip は理由必須)
 - UI/UX 検証: 視覚破綻(H11) / アフォーダンス・状態(H12) / レスポンシブ(H13: resize した画面と viewport) / a11y 基礎(H14) の所見
 - findings: Critical x / High y / Medium z / Low w / 要確認 v (UI/UX = H11-H14 由来は H 番号を併記)
+- H7 未検証 (観測窓が途切れ肯定証拠も得られなかった操作): n 件 (操作名を列挙)
 (以下 finding 詳細を severity 降順で)
 ```
 
diff --git a/tests/js/bughunt/feedback-probe.test.ts b/tests/js/bughunt/feedback-probe.test.ts
new file mode 100644
index 0000000..b4ccf1c
--- /dev/null
+++ b/tests/js/bughunt/feedback-probe.test.ts
@@ -0,0 +1,269 @@
+/**
+ * bug-hunt driver の一過性フィードバック記録器 (feedback probe) の契約テスト。
+ *
+ * 対象: `.claude/skills/app-bug-hunt/probes/feedback-probe.js`
+ * probe は「モジュール」ではなく **式** (IIFE) なので import せず、実 driver と同じく
+ * テキストとして読み `window.eval()` に渡して評価する。
+ *
+ * jsdom は layout を持たず `getClientRects()` が常に空配列を返すため、
+ * `tests/Browser/FlashToastTest.php` と同じ可視判定が成立するよう
+ * `Element.prototype.getClientRects` を stub する (probe 本体にテスト用フックは入れない)。
+ *
+ * **skip 条件を作らない**: probe ファイルが無ければ本テストは落ちる (silent skip による
+ * false green を避けるため)。bug-hunt スキルごと撤去する場合は本テストも一緒に削除すること。
+ */
+import { readFileSync } from "node:fs";
+import { resolve } from "node:path";
+import { afterAll, beforeAll, describe, expect, it } from "vitest";
+
+/** probe が返す 1 件の live region 観測。`visible` は seen entry にのみ付く。 */
+interface ProbeEntry {
+    role: string | null;
+    testid: string | null;
+    text: string;
+    t: number;
+    visible?: boolean | "gone";
+    /** 可視判定が例外で解決できなかった場合のみ付く (visible は false に倒れる)。 */
+    error?: string;
+}
+
+interface ProbeResult {
+    installed_now: boolean;
+    armed_at_ms: number;
+    seen: ProbeEntry[];
+    present_new: ProbeEntry[];
+    present_preexisting: number;
+    pending: number;
+    errors: number;
+}
+
+const PROBE_PATH = resolve(
+    process.cwd(),
+    ".claude/skills/app-bug-hunt/probes/feedback-probe.js",
+);
+
+// scripts/run-vitest.sh が cd "$WORKSPACE" してから vitest を起動するので cwd はリポジトリルート。
+const probeSource = readFileSync(PROBE_PATH, "utf8");
+
+/** probe を 1 回叩く (実 driver の `playwright-cli --raw eval "$(cat ...)"` 相当)。 */
+function probe(): ProbeResult {
+    return JSON.parse(window.eval(probeSource) as string) as ProbeResult;
+}
+
+/** rAF (jsdom では ~16ms タイマ) の解決を実時間で待つ。30ms では競合して flaky。 */
+function tick(ms = 150): Promise<void> {
+    return new Promise((r) => setTimeout(r, ms));
+}
+
+/** `<div data-testid>` でラップした live region を作る (ToastContainer の構造に相当)。 */
+function mk(
+    role: string,
+    txt: string,
+    testid: string | null,
+    style?: string,
+): HTMLDivElement {
+    const wrap = document.createElement("div");
+    if (testid) wrap.setAttribute("data-testid", testid);
+    const el = document.createElement("div");
+    el.setAttribute("role", role);
+    if (style) el.setAttribute("style", style);
+    el.textContent = txt;
+    wrap.appendChild(el);
+    return wrap;
+}
+
+function host(): HTMLElement {
+    const el = document.getElementById("bughunt-probe-host");
+    if (!el) throw new Error("host element missing");
+    return el;
+}
+
+// 以降の it は**順序依存**である (probe は window に記録器を持ち、基線を回ごとに更新するため)。
+// 実 run の 1 セッションを再現している。`describe.sequential` で並列化を機械的に禁止する
+// (コメントだけだと将来 vitest 設定で concurrent 既定にされたとき黙って壊れる)。
+describe.sequential("bug-hunt feedback probe", () => {
+    const originalGetClientRects = Element.prototype.getClientRects;
+    // 例外経路 (N) の再現用。true の間だけ layout 取得が throw する。
+    let rectsShouldThrow = false;
+
+    beforeAll(() => {
+        // jsdom は layout を持たない → FlashToastTest と同じ可視判定を成立させるための stub。
+        // 「接続済み かつ hidden でない かつ display:none / visibility:hidden でない」なら矩形あり。
+        Element.prototype.getClientRects = function (
+            this: Element,
+        ): DOMRectList {
+            if (rectsShouldThrow) throw new Error("layout unavailable");
+            const st = window.getComputedStyle(this);
+            const ok =
+                this.isConnected &&
+                !(this as HTMLElement).hidden &&
+                st.display !== "none" &&
+                st.visibility !== "hidden";
+            const rects = ok ? [{ width: 10, height: 10 }] : [];
+            return rects as unknown as DOMRectList;
+        };
+
+        const h = document.createElement("div");
+        h.id = "bughunt-probe-host";
+        document.body.appendChild(h);
+    });
+
+    afterAll(() => {
+        Element.prototype.getClientRects = originalGetClientRects;
+    });
+
+    it("A: 初回 probe が記録器を設置し、基線が無いので可視 live region は全て present_new", () => {
+        host().appendChild(mk("status", "この組織は未契約です", "standing-alert"));
+        host().appendChild(mk("alert", "前の操作のエラー", "toast-error"));
+
+        const r = probe();
+        expect(r.installed_now).toBe(true); // A1
+        expect(r.present_new).toHaveLength(2); // A2
+        expect(r.present_preexisting).toBe(0); // A2
+    });
+
+    it("B: 無操作の再 probe では常駐 live region が present_preexisting に落ちる", () => {
+        const r = probe();
+        expect(r.present_new).toHaveLength(0); // B1
+        expect(r.present_preexisting).toBe(2); // B1
+        expect(r.seen).toHaveLength(0); // B2
+        expect(r.installed_now).toBe(false); // B2
+    });
+
+    it("C: auto-dismiss 後に読んでも seen に visible:true で残る (F-1-02 の機序)", async () => {
+        const toast = mk("status", "動画マニュアルを削除しました", "toast-success");
+        host().appendChild(toast);
+        await tick(); // rAF で可視判定が解決
+        toast.remove(); // auto-dismiss 相当
+        await tick();
+
+        const r = probe();
+        expect(r.seen).toHaveLength(1); // C1
+        expect(r.seen[0].visible).toBe(true); // C1
+        expect(r.seen[0].testid).toBe("toast-success"); // C2
+        expect(r.seen[0].text).toContain("削除しました"); // C2
+        expect(r.present_new).toHaveLength(0); // C3
+        expect(r.present_preexisting).toBe(2); // C3
+        expect(r.pending).toBe(0); // C4
+    });
+
+    it("D: seen は drain される (二重計上しない)", () => {
+        const r = probe();
+        expect(r.seen).toHaveLength(0); // D1
+    });
+
+    it("E: display:none の live region は visible:false で記録 (証拠には数えない)", async () => {
+        const invisible = mk("status", "見えない通知", "hidden-toast", "display:none");
+        host().appendChild(invisible);
+        await tick();
+        invisible.remove();
+        await tick();
+
+        const r = probe();
+        expect(r.seen).toHaveLength(1); // E1
+        expect(r.seen[0].visible).toBe(false); // E1
+    });
+
+    it("F: 祖先 aria-hidden=true の live region は seen に入らない (記録時足切り)", async () => {
+        const wrap = document.createElement("div");
+        wrap.setAttribute("aria-hidden", "true");
+        host().appendChild(wrap);
+        wrap.appendChild(mk("status", "aria-hidden 配下", null));
+        await tick();
+
+        const r = probe();
+        expect(r.seen).toHaveLength(0); // F1
+    });
+
+    it("G: 1 フレーム以内に消えた点滅は visible:'gone'", async () => {
+        const flash = mk("status", "一瞬", "flash");
+        host().appendChild(flash);
+        flash.remove();
+        await tick();
+
+        const r = probe();
+        expect(r.seen).toHaveLength(1); // G1
+        expect(r.seen[0].visible).toBe("gone"); // G1
+    });
+
+    it("H: 非 live-region の DOM 変化は拾わない", async () => {
+        host().appendChild(document.createElement("p"));
+        await tick();
+
+        const r = probe();
+        expect(r.seen).toHaveLength(0); // H1
+        expect(r.present_new).toHaveLength(0); // H1
+    });
+
+    it("I: 既存 live region の in-place テキスト更新 (characterData) を捕捉", async () => {
+        const standing = document.querySelector(
+            "[data-testid=standing-alert] [role=status]",
+        );
+        if (!standing || !standing.firstChild) throw new Error("fixture missing");
+        (standing.firstChild as Text).data = "契約が失効しました";
+        await tick();
+
+        const r = probe();
+        expect(r.seen).toHaveLength(1); // I1
+        expect(r.seen[0].visible).toBe(true); // I1
+        expect(r.present_new).toHaveLength(1); // I2
+    });
+
+    it("J: hidden→visible は属性を監視しなくても基線差分で present_new に出る", async () => {
+        const toggled = mk("status", "切替通知", "toggle", "display:none");
+        host().appendChild(toggled);
+        await tick();
+        probe(); // ここでは不可視 → 基線は刻まれない
+
+        const inner = toggled.querySelector("[role=status]");
+        if (!inner) throw new Error("fixture missing");
+        inner.setAttribute("style", "");
+
+        const r = probe();
+        expect(r.present_new.some((e) => e.text === "切替通知")).toBe(true); // J1
+    });
+
+    it("M: 既存 live region 内のテキストノード差し替えを seen で捕捉", async () => {
+        const std = document.querySelector(
+            "[data-testid=standing-alert] [role=status]",
+        );
+        if (!std) throw new Error("fixture missing");
+        std.textContent = ""; // 旧 Text を除去
+        std.appendChild(document.createTextNode("再検証が必要です")); // 新 Text (childList)
+        await tick();
+
+        const r = probe();
+        expect(
+            r.seen.some((e) => e.visible === true && e.text === "再検証が必要です"),
+        ).toBe(true); // M1
+    });
+
+    it("N: 可視判定が例外で解決できないと pending は戻り errors に数えられる", async () => {
+        rectsShouldThrow = true;
+        const broken = mk("status", "判定不能な通知", "broken-toast");
+        host().appendChild(broken);
+        await tick(); // rAF 内で visible() が throw する
+        rectsShouldThrow = false; // probe 本体 (present_new の同期評価) は正常に動かす
+
+        const r = probe();
+        expect(r.pending).toBe(0); // N1 例外でも pending は対称に戻る (恒久残留しない)
+        const entry = r.seen.find((e) => e.text === "判定不能な通知");
+        expect(entry).toBeDefined();
+        expect(entry?.error).toBeDefined(); // N2 判定不能であることが残る
+        expect(entry?.visible).toBe(false); // N2 証拠には数えない
+        expect(r.errors).toBe(1); // N3 陰性判断に使えないことが機械的に読める
+
+        // N4 errors は drain した batch 単位。次の probe では 0 に戻る
+        // (= 再 probe 時に 2 回目の errors:0 だけを見ると 1 回目の判定不能を取り落とす。
+        //   だから SKILL.md の統合規則は errors を「いずれかで非 0 なら未検証」と定めている)
+        expect(probe().errors).toBe(0);
+    });
+
+    it("K: 記録器の喪失 (cross-document 相当) を installed_now:true で検知", () => {
+        // @ts-expect-error probe が window に持つ内部状態 (型定義は置かない)
+        delete window.__bhFeedbackRecorder;
+
+        const r = probe();
+        expect(r.installed_now).toBe(true); // K1
+    });
+});

```

---

## 依頼 (確認のみ)

1. 統合規則の文面で Round 3 [Critical] が閉じたか。まだ判定不能・窓喪失が陰性に倒れる経路が残っていないか。
2. 全体判定 **APPROVED** / **CHANGES_REQUESTED** を最後に 1 行で出せ。
