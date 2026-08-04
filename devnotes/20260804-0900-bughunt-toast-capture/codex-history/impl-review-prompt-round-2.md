# impl-review Round 2

Round 1 の指摘に対する対応マトリクスと、修正後の**全差分** (git diff HEAD、Round 1 と同じ範囲) を送る。

---

## 対応マトリクス (Round 1 の指摘をどう捌いたか)

# 対応マトリクス: impl-review Round 1

## [Warning] probe: `pending` の増減が例外安全でない (feedback-probe.js:89)

- 判断: **対応する**
- 根拠: 指摘は正しい。`raf` コールバック内で `visible()` (= `getComputedStyle` / `getClientRects`) が
  throw すると `pending -= 1` に到達せず、**その probe 状態は永久に `pending > 0`** になる。
  SKILL.md の判定表は `pending > 0` を「未検証」に倒すので、**H7 が恒常的に判定不能**になる
  = 誤検知を減らす代わりに取りこぼしへ倒れる、という本設計が最も避けたい失敗モードそのもの。
- 対応内容: `try / catch / finally` で対称性を固定した。
  ```javascript
  try {
      entry.visible = el.isConnected ? visible(el) : "gone";
  } catch (e) {
      entry.visible = false;          // 判定不能。証拠は visible:true のみなので数えられない
      entry.error = String((e && e.message) || e);
  } finally {
      state.pending -= 1;
      state.seen.push(entry);
  }
  ```
  `catch` で `visible:false` に倒したのは、**判定不能を肯定証拠にしない**ため
  (証拠集合は `visible:true` のみという既存契約をそのまま使う)。entry 自体は捨てずに
  `error` を添えて `seen` に残し、人が事情を読めるようにする (`visible:false`/`"gone"` と同じ扱い)。
  probe 全体を `try/catch` で包む案は採らない — 本体が throw したら driver 側が
  「probe が壊れた」と気づくべきで、黙って空 JSON を返すと**陰性証拠に見えてしまう**ため。

## [Suggestion] probe: 同一 mutation での二重 enqueue を dedupe (feedback-probe.js:98)

- 判断: **見送る** (design-review Round 1 の同種 Suggestion と同じ判断を維持)
- 根拠:
  1. **判定規約が件数を使わない**。SKILL.md の判定は「証拠集合が空か否か」と「本文が操作結果か」だけで
     行うので、重複は判定結果を一切変えない。
  2. 実際に二重 enqueue が起きるのは「live region の中に live region が追加された」場合に限られる
     (`collect()` は Text ノードを返さないので、テキスト差し替え経路と `addedNodes` 経路は排他)。
     AI-CUE の非単調 UI 2 件 (`ToastContainer` / `CodeSnippet`) に入れ子 live region は無い。
  3. `WeakSet` を足すと probe の状態と失敗モードが増える。得られるのは triage 時の見やすさだけで、
     思考原則 2 (今必要なものだけ作る) に反する。実 run で読みづらいと判明した時点で足す。

## [Suggestion] SKILL.md: 最初の書き込み操作前に arm が成立する条件を明文化 (SKILL.md:268)

- 判断: **対応する**
- 根拠: 指摘のとおり解釈余地があった。ただし機構としては既に閉じている
  (probe は arm と読み出しが同一コマンドで冪等なので、arm 漏れは次の読み出しで
  `installed_now:true` になり「未検証」に倒れる)。**閉じていることが文面から読めない**のが問題。
- 対応内容: 「呼ぶタイミング」に 1 項を追加し、
  「arm 漏れは黙って『フィードバック無し』にはならず必ず『未検証』に倒れる」
  「`installed_now:false` を得られていない操作について H7 陰性を主張してはならない」を明記した。
  新しい機構は足していない (既存の判定表 3 行目を参照させただけ)。

## [Suggestion] SKILL.md: `pending > 0` の再 probe 前に短待機を規約化 (SKILL.md:281)

- 判断: **見送る**
- 根拠: 再 probe は **Bash 1 往復** (プロセス起動 + 往復で数百 ms〜数秒) を挟む。
  未解決なのは rAF 1 フレーム (~16ms) 待ちなので、**待機時間は既に 1 桁以上余っている**。
  ここに追加の待機を規約として書くと、(a) 効果が無いのに全 driver のコストを増やし、
  (b) 本設計が明示的にスコープ外とした「待ち時間依存の観測」を規約に呼び戻すことになる。
  実 run で `pending > 0` 継続 (= `H7 未検証`) が実際に増えるなら、そのときは待機値ではなく
  **方式**を見直す — これは SKILL.md に既に書いてある信号設計である。

## [Suggestion] spec-ledger: `watch_globs` 欄に「registry 正本参照のみ」の定型句 (spec-ledger.md:107)

- 判断: **対応する**
- 根拠: F-1-02 の `watch_globs` 欄は設計どおり glob を書かず registry を指しているが、
  **テンプレート側にはその書き方が無い**ので、後続の人が glob を書き写して二重管理を始めうる。
  「同じ説明文を両方に重複させない」は spec-ledger.md 冒頭が定める役割分担そのものなので、
  テンプレートに書くのが正しい置き場所。
- 対応内容: 初回登録テンプレートの `watch_globs` 欄に注記 1 行を追加
  (「既に registry に登録済なら glob を書き写さず『`A-NNN` に登録済 (正本は registry)』とだけ書く」)。
  欄名文字列は変えていない (`test_spec_ledger.py` の `REQUIRED_FIELDS` との 1 対 1 関係を維持)。

## [Suggestion] test_spec_ledger.py: `#L10` 形式の根拠パスがすり抜ける (test_spec_ledger.py:56)

- 判断: **対応する**
- 根拠: 指摘のとおり。`PATH_LIKE` が `:` 位置指定しか許容していなかったため、
  `foo.php#L10` / `AGENTS.md#anchor` のような記法で書かれた根拠は
  **丸ごと検査対象外にすり抜け**、パスが消えても検知できなかった。
  これは本テストが守ろうとしている不変条件 (根拠パスの実在) の穴であり、
  「行番号を検証しない」という設計判断とは独立である。
- 対応内容: `PATH_LIKE` に名前付きグループ `path` を持たせ、位置指定サフィックスを
  `(?:[:#][\w.-]*)?` に拡張した。**位置情報は従来どおり捨て、パス部だけを実在確認する**。
  fail に倒す案 (記法を禁止する) は採らない — 台帳の記法を狭めるだけで、
  守りたい不変条件 (実在) は許容集合を広げる方が強くなるため。

## [Suggestion] feedback-probe.test.ts: 順序依存を `sequential` で固定 (feedback-probe.test.ts:108)

- 判断: **対応する**
- 根拠: 本テストは probe が `window` に持つ記録器と要素基線を跨いで積み上げる**意図的な順序依存**で、
  それをコメントだけで守っていた。vitest 設定が将来 concurrent 既定になると
  **黙って壊れる** (しかも失敗の出方が基線ずれで分かりにくい)。
- 対応内容: `describe(...)` を `describe.sequential(...)` に変え、意図をコメントに残した。


---

## 修正後の実装差分 (git diff HEAD 全文)

```diff
diff --git a/.claude/agents/bughunt-shard.md b/.claude/agents/bughunt-shard.md
index 28c40c9..0367641 100644
--- a/.claude/agents/bughunt-shard.md
+++ b/.claude/agents/bughunt-shard.md
@@ -53,6 +53,9 @@ ## ブラウザ操作 = @playwright/cli (Bash)
 playwright-cli type   "<text>" / fill <ref> "<text>" / press Enter
 playwright-cli goto <url> / go-back / reload
 playwright-cli console / requests                   # console error / network (4xx/5xx・外部ドメイン) 確認
+playwright-cli --raw eval "$(cat "$(git rev-parse --show-toplevel)/.claude/skills/app-bug-hunt/probes/feedback-probe.js")"
+                                                    # 一過性フィードバック記録器 (toast は 4 秒で消える)。
+                                                    # 呼ぶタイミングと判定は SKILL.md §一過性フィードバックの観測 が正本
 playwright-cli resize <w> <h>                        # レスポンシブ確認 (mobile 375 667 / tablet 768 1024)
 playwright-cli screenshot shot.png                   # 証跡。異常時に必ず残す
 playwright-cli close                                 # 走行終了時に自セッションを閉じる
@@ -70,8 +73,8 @@ ## なぜ自分のセッションだけを使うか (取り違え厳禁)
 ## 走行手順
 
 1. スキル正本 `.claude/skills/app-bug-hunt/SKILL.md` と、割り当てられた `stories/S*.md` を読む。
-   走行プロトコル・横断ヒューリスティクス (H1-H14)・finding フォーマット・逐次レポート書き出し規約は
-   すべて SKILL.md / stories に従う (本ファイルは差分のみ)。
+   走行プロトコル・**feedback probe 規約**・横断ヒューリスティクス (H1-H14)・finding フォーマット・
+   逐次レポート書き出し規約はすべて SKILL.md / stories に従う (本ファイルは差分のみ)。
 2. **Phase 1 (インベントリ鮮度確認) はスキップ** — 親が 1 回だけ行う。screens.md / operations.md / stories は
    **読み取りのみ** (気づきは自 report の「インベントリ修正提案」節に書く)。
 3. 開始時に `tmp/bug-hunt/shard-{i}-cmd.sh db-check` で DB 名と User::count() を確認してから走行。
diff --git a/.claude/skills/app-bug-hunt/SKILL.md b/.claude/skills/app-bug-hunt/SKILL.md
index e312fd6..8e7a8ba 100644
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
@@ -247,6 +250,66 @@ ### 走行プロトコル (各ステップ共通)
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
+| `installed_now:false` かつ どちらにも無い (`pending:0`) | 操作の全区間で記録器が生きていた = **本当に出なかった** | H7 finding 候補 |
+| `installed_now:true` | 途中で document が置換され記録器が失われた (基線も無い) | **肯定証拠のみ採用**: `present_new` または直後の `snapshot` に**操作結果を伝える文言**があれば「フィードバックあり」と結論してよい。無い場合は **未検証** (finding にしない)。基線が無いので常駐 live region も `present_new` に混ざる = 陰性判断には使えない |
+| `pending > 0` | 可視性判定が未解決 | probe をもう 1 回だけ叩き、**1 回目と 2 回目の `seen` / `present_new` の和集合**で判定する。2 回目も `pending > 0` なら**未検証** |
+
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
+- **H7 の「結果フィードバックが無い」は `installed_now:false` の操作にのみ適用する。**
+  `installed_now:true` / `pending>0` 継続で肯定証拠も得られなかった操作は
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
@@ -262,6 +325,9 @@ ### 横断ヒューリスティクス (毎ステップ適用)
 | H9 | 権限外データの表示・操作 (IDOR 含む。他組織/他プロジェクトのリソース) | Critical |
 | H10 | 文言・件数・状態が直前の操作結果と矛盾 (例: 作成したのに一覧に出ない) | High |
 
+> **H7 の前提条件**: 「結果フィードバックが無い」の判定には **feedback probe の陰性所見**が必須
+> (§一過性フィードバックの観測)。事後 snapshot に無いことを根拠に H7 を起票しない。
+
 **UI/UX ヒューリスティクス (H11-H14、視覚/操作品質。snapshot + screenshot で観察)**
 
 | # | 兆候 | 既定 severity |
@@ -288,7 +354,9 @@ ## F-{連番}: {一行サマリ}
 - 期待: / 実際:
 - 阻害されたユーザージョブ: (このバグでユーザーが達成できなくなった目的。使命接続の必須欄)
 - 改善アクション候補: (どう直せばユーザーが目的を達成できるか)
-- 証跡: screenshots/F-xx.png, console: ..., network: ...
+- 証跡: screenshots/F-xx.png, console: ..., network: ...,
+  feedback-probe: `installed_now=false seen=0(visible:true) present_new=0 pending=0`
+  (フィードバック欠落を主張する finding では**必須**)
 - 推定原因: (code-review-graph で当たりを付ける。5 分で見つからなければ「未調査」)
 - 関連既知情報: (devnotes/TODO.md に同種の記録があれば参照。regression かどうかが重要)
 ```
@@ -322,6 +390,7 @@ # bug-hunt report {日時}
 - 操作カバレッジ: 実行 n / operations.md 対象 m (未実行を列挙、skip は理由必須)
 - UI/UX 検証: 視覚破綻(H11) / アフォーダンス・状態(H12) / レスポンシブ(H13: resize した画面と viewport) / a11y 基礎(H14) の所見
 - findings: Critical x / High y / Medium z / Low w / 要確認 v (UI/UX = H11-H14 由来は H 番号を併記)
+- H7 未検証 (観測窓が途切れ肯定証拠も得られなかった操作): n 件 (操作名を列挙)
 (以下 finding 詳細を severity 降順で)
 ```
 
diff --git a/.claude/skills/app-bug-hunt/ledger/test_spec_ledger.py b/.claude/skills/app-bug-hunt/ledger/test_spec_ledger.py
new file mode 100644
index 0000000..708a5c0
--- /dev/null
+++ b/.claude/skills/app-bug-hunt/ledger/test_spec_ledger.py
@@ -0,0 +1,196 @@
+"""spec-ledger.md の腐り検知 (stdlib のみ)。
+
+`spec-ledger.md` は機械 registry (`adjudications.jsonl`) の「対」であり、人間向け申し送りの正本。
+台帳は放置すると腐る (根拠に書いたファイルが消える / registry に「登録済」と書いたのに実体が無い)
+ため、次の 3 点だけを機械検知する:
+
+ (1) 確定項目の必須欄が揃っているか (初回登録テンプレートの「欄を削らない」の機械化)
+ (2) 根拠欄に書いたファイルが実在するか (**行番号は見ない**)
+ (3) 「機械 registry に登録済」と書いた A-NNN が adjudications.jsonl に実在するか
+
+(2) で行番号を検証しないのは意図的である。通常のリファクタで台帳テストが壊れる保守負債になるため。
+旧 registry 18 件が「実在しないパス」を指し watch_globs invalidation が永久に発火しなかった事故
+(`ledger/README.md` 運用ガード (d)) の再発防止が目的なので、**実在**だけを見れば足りる。
+
+台帳が空 (エントリ 0 件) のときは 3 つとも vacuous に PASS する (テンプレート初期状態を壊さない)。
+
+実行: python3 -m unittest discover -s .claude/skills/app-bug-hunt/ledger -p 'test_*.py'
+"""
+
+from __future__ import annotations
+
+import json
+import re
+import unittest
+from pathlib import Path
+
+LEDGER_DIR = Path(__file__).resolve().parent
+SKILL_ROOT = LEDGER_DIR.parent
+REPO_ROOT = SKILL_ROOT.parents[2]  # .claude/skills/app-bug-hunt -> repo root
+SPEC_LEDGER = SKILL_ROOT / "spec-ledger.md"
+ADJUDICATIONS = LEDGER_DIR / "adjudications.jsonl"
+
+ENTRY_RE = re.compile(r"^#### (?P<fid>\S+) — (?P<title>.+)$")
+HEADING_RE = re.compile(r"^#{1,6} ")
+FENCE_RE = re.compile(r"^\s*```")
+
+# 初回登録テンプレートの全 9 欄。テンプレートを直したらこの定数も直す (1 対 1 の関係)。
+REQUIRED_FIELDS = (
+    "判定",
+    "根拠 (file:line)",
+    "なぜ誤検知に見えたか",
+    "driver 側の再発防止",
+    "watch_globs (機械 registry に載せる場合)",
+    "review_after_days",
+    "確定した run_id",
+    "再オープン条件",
+    "機械 registry",
+)
+# 照合は「キー文字列が本文のどこかにある」ではなく **行形式** で行う
+# (本文中に同じ語が出ただけで PASS する誤検知を避ける)。
+FIELD_LINE = "- **{name}**:"
+FIELD_START_RE = re.compile(r"^- \*\*(?P<name>[^*]+)\*\*:")
+
+BACKTICK_RE = re.compile(r"`([^`]+)`")
+# 位置指定 (`:123-125` / `#L12` / `#anchor`) は**捨てて**パス部だけを実在確認する。
+# 位置記法を許容集合に入れておかないと、その記法で書かれた根拠が丸ごと検査対象外に
+# すり抜けてしまう (腐りの見逃し)。
+PATH_LIKE = re.compile(
+    r"^(?P<path>[\w./-]+\.(?:php|ts|js|svelte|md|json|ya?ml|py|sh))(?:[:#][\w.-]*)?$"
+)
+ADJ_ID_RE = re.compile(r"\bA-\d{3}\b")
+
+
+def _lines_outside_fences(text: str) -> list[str]:
+    """コードフェンス (```) の内側を空行に潰した行リスト。
+
+    `## 初回登録テンプレート` のプレースホルダ (`path/to/File.php` 等) を
+    実エントリとして拾わないため。行番号を保つよう「除去」ではなく「空行化」する。
+    """
+    out: list[str] = []
+    in_fence = False
+    for line in text.splitlines():
+        if FENCE_RE.match(line):
+            in_fence = not in_fence
+            out.append("")
+            continue
+        out.append("" if in_fence else line)
+    return out
+
+
+def _entries() -> list[tuple[str, str]]:
+    """(finding_id, 本文) のリスト。テンプレート節 (フェンス内) は除外済み。"""
+    if not SPEC_LEDGER.exists():
+        raise AssertionError(f"spec-ledger.md が見つからない: {SPEC_LEDGER}")
+    lines = _lines_outside_fences(SPEC_LEDGER.read_text(encoding="utf-8"))
+    entries: list[tuple[str, str]] = []
+    current_id: str | None = None
+    body: list[str] = []
+    for line in lines:
+        match = ENTRY_RE.match(line)
+        if match:
+            if current_id is not None:
+                entries.append((current_id, "\n".join(body)))
+            current_id = match.group("fid")
+            body = []
+            continue
+        if current_id is not None and HEADING_RE.match(line):
+            entries.append((current_id, "\n".join(body)))
+            current_id = None
+            body = []
+            continue
+        if current_id is not None:
+            body.append(line)
+    if current_id is not None:
+        entries.append((current_id, "\n".join(body)))
+    return entries
+
+
+def _field_body(entry_body: str, name: str) -> str:
+    """`- **{name}**:` 欄の本文 (次の欄が始まるまでの継続行を含む)。無ければ空文字。"""
+    prefix = FIELD_LINE.format(name=name)
+    collected: list[str] = []
+    capturing = False
+    for line in entry_body.splitlines():
+        if capturing:
+            if FIELD_START_RE.match(line):
+                break
+            collected.append(line)
+            continue
+        if line.startswith(prefix):
+            capturing = True
+            collected.append(line[len(prefix) :])
+    return "\n".join(collected)
+
+
+def _registered_adjudication_ids() -> set[str]:
+    if not ADJUDICATIONS.exists():
+        return set()
+    ids: set[str] = set()
+    for raw in ADJUDICATIONS.read_text(encoding="utf-8").splitlines():
+        line = raw.strip()
+        if not line or line.startswith("#"):
+            continue
+        record = json.loads(line)
+        adjudication_id = record.get("adjudication_id")
+        if isinstance(adjudication_id, str):
+            ids.add(adjudication_id)
+    return ids
+
+
+class SpecLedgerTest(unittest.TestCase):
+    def test_required_fields_present(self) -> None:
+        """確定項目はテンプレートの全 9 欄を `- **欄名**:` の行形式で持つ。"""
+        missing: list[str] = []
+        for finding_id, body in _entries():
+            for name in REQUIRED_FIELDS:
+                prefix = FIELD_LINE.format(name=name)
+                if not any(line.startswith(prefix) for line in body.splitlines()):
+                    missing.append(f"{finding_id}: 欄 '{name}' が無い")
+        self.assertEqual(
+            missing,
+            [],
+            "spec-ledger.md の確定項目に必須欄の欠落:\n" + "\n".join(missing),
+        )
+
+    def test_evidence_paths_exist(self) -> None:
+        """根拠欄に書いたファイルパスがリポジトリに実在する (行番号は見ない)。"""
+        broken: list[str] = []
+        for finding_id, body in _entries():
+            evidence = _field_body(body, "根拠 (file:line)")
+            for token in BACKTICK_RE.findall(evidence):
+                matched = PATH_LIKE.match(token.strip())
+                if matched is None:
+                    continue
+                path = matched.group("path")
+                if not (REPO_ROOT / path).exists():
+                    broken.append(f"{finding_id}: 根拠パスが実在しない: {path}")
+        self.assertEqual(
+            broken,
+            [],
+            "spec-ledger.md の根拠パスが腐っている:\n" + "\n".join(broken),
+        )
+
+    def test_registry_cross_reference_resolves(self) -> None:
+        """「機械 registry に登録済」と書いた A-NNN が adjudications.jsonl に実在する。"""
+        known = _registered_adjudication_ids()
+        dangling: list[str] = []
+        for finding_id, body in _entries():
+            registry = _field_body(body, "機械 registry")
+            if "登録済" not in registry:
+                continue
+            for adjudication_id in ADJ_ID_RE.findall(registry):
+                if adjudication_id not in known:
+                    dangling.append(
+                        f"{finding_id}: {adjudication_id} が adjudications.jsonl に無い"
+                    )
+        self.assertEqual(
+            dangling,
+            [],
+            "spec-ledger.md と機械 registry の相互参照が切れている:\n"
+            + "\n".join(dangling),
+        )
+
+
+if __name__ == "__main__":
+    unittest.main()
diff --git a/.claude/skills/app-bug-hunt/probes/feedback-probe.js b/.claude/skills/app-bug-hunt/probes/feedback-probe.js
new file mode 100644
index 0000000..7ad55de
--- /dev/null
+++ b/.claude/skills/app-bug-hunt/probes/feedback-probe.js
@@ -0,0 +1,146 @@
+/*
+ * bug-hunt driver 用「一過性フィードバック記録器」(feedback probe)。
+ *
+ * 使い方 (正本は .claude/skills/app-bug-hunt/SKILL.md §一過性フィードバックの観測):
+ *   playwright-cli --raw eval "$(cat "$(git rev-parse --show-toplevel)/.claude/skills/app-bug-hunt/probes/feedback-probe.js")"
+ *
+ * なぜ必要か: toast (success/info/warning) は 4000ms、CodeSnippet の「コピー完了」は 2000ms で
+ * 自動消滅する (resources/js/lib/stores/toast.ts / components/molecules/CodeSnippet.svelte)。
+ * 事後 snapshot 1 点では「出なかった」と「もう消えた」を区別できず、誤検知になる
+ * (run 20260803-203721 の F-1-02 = 誤検知確定)。そこで **操作の前に記録器を仕込む**。
+ *
+ * 返り値 (JSON 文字列):
+ *   installed_now         : 今回の呼び出しで記録器を新規設置したか (true = 直前に document が置換された)
+ *   seen[]                : 前回 probe 以降に出現/変化した live region (消えた後も残る)
+ *                           visible: true=可視 / false=不可視 / gone=1 フレーム以内に消えた
+ *                           (可視判定が例外で解決できなかった場合は visible:false + error)
+ *   present_new[]         : 現在 DOM にある live region のうち「基線が無い or テキストが変わった」もの
+ *   present_preexisting   : 基線と一致する常駐 live region の件数 (判定に使わない)
+ *   pending               : 可視性判定が未解決の候補数 (>0 ならもう一度 probe する)
+ *
+ * 契約の回帰テストは tests/js/bughunt/feedback-probe.test.ts (bug-hunt スキルごと撤去するなら
+ * そちらも一緒に削除すること)。
+ */
+(() => {
+    const KEY = "__bhFeedbackRecorder";
+    const LIVE = "[role=status],[role=alert]";
+    const raf =
+        typeof window.requestAnimationFrame === "function"
+            ? window.requestAnimationFrame.bind(window)
+            : (cb) => setTimeout(cb, 0);
+
+    /** layout 非依存の足切り (mutation callback 内で使う)。 */
+    const plausible = (el) =>
+        el.isConnected && !el.hidden && !el.closest("[aria-hidden=true]");
+
+    /** 完全な可視判定 (layout 依存。FlashToastTest.php と同じ条件)。 */
+    const visible = (el) => {
+        if (!plausible(el)) return false;
+        const style = window.getComputedStyle(el);
+        return (
+            style.visibility !== "hidden" &&
+            style.display !== "none" &&
+            el.getClientRects().length > 0
+        );
+    };
+
+    const text = (el) => (el.textContent || "").replace(/\s+/g, " ").trim().slice(0, 200);
+
+    const describe = (el) => {
+        const host = el.closest("[data-testid]");
+        return {
+            role: el.getAttribute("role"),
+            testid: host ? host.getAttribute("data-testid") : null,
+            text: text(el),
+            t: Math.round(performance.now()),
+        };
+    };
+
+    const collect = (node) => {
+        const out = [];
+        if (!node || node.nodeType !== 1) return out;
+        if (node.matches(LIVE)) out.push(node);
+        for (const el of node.querySelectorAll(LIVE)) out.push(el);
+        return out;
+    };
+
+    let installedNow = false;
+
+    if (!window[KEY]) {
+        installedNow = true;
+        const state = { seen: [], pending: 0, armedAt: Math.round(performance.now()) };
+
+        // 候補を「生きているうちに」次フレームで可視判定してから seen に確定する。
+        // 一過性 UI は消えた後では可視性を測れないため、記録時点の同期評価では足りない。
+        const enqueue = (el) => {
+            // layout 非依存の足切り (detached でも closest は辿れる)
+            if (el.hidden || el.closest("[aria-hidden=true]")) return;
+            const entry = describe(el);
+            if (!el.isConnected) {
+                // callback 到達前に消えた = 1 フレーム未満の点滅 (知覚不能)。捨てずに gone で残す
+                entry.visible = "gone";
+                state.seen.push(entry);
+                return;
+            }
+            state.pending += 1;
+            raf(() => {
+                // pending の増減は例外があっても対称に保つ。ここで throw して pending が
+                // 減り損ねると、以後ずっと pending>0 = H7 が恒常的に「未検証」へ倒れる。
+                try {
+                    entry.visible = el.isConnected ? visible(el) : "gone";
+                } catch (e) {
+                    entry.visible = false; // 判定不能。証拠は visible:true のみなので数えられない
+                    entry.error = String((e && e.message) || e);
+                } finally {
+                    state.pending -= 1;
+                    state.seen.push(entry);
+                }
+            });
+        };
+
+        state.observer = new MutationObserver((records) => {
+            for (const r of records) {
+                for (const n of r.addedNodes) for (const el of collect(n)) enqueue(el);
+                // 既存 live region の**中身が差し替えられた**場合 (Svelte のテキストノード置換)。
+                // addedNodes は Text なので collect() では拾えず、characterData も発火しない。
+                if (r.type === "childList" && r.addedNodes.length > 0 && r.target.nodeType === 1) {
+                    const host = r.target.closest(LIVE);
+                    if (host) enqueue(host);
+                }
+                if (r.type === "characterData") {
+                    const host = r.target.parentElement && r.target.parentElement.closest(LIVE);
+                    if (host) enqueue(host);
+                }
+            }
+        });
+        state.observer.observe(document.documentElement, {
+            childList: true,
+            subtree: true,
+            characterData: true,
+        });
+        window[KEY] = state;
+    }
+
+    const state = window[KEY];
+
+    // 基線差分: 「前回 probe 以降に可視化された / テキストが変わった」live region だけを新規とする。
+    // 常駐 Alert (atoms/Alert.svelte) や自動消去しない error toast を証拠にしないための核。
+    const presentNew = [];
+    let preexisting = 0;
+    for (const el of document.querySelectorAll(LIVE)) {
+        if (!visible(el)) continue;
+        const current = text(el);
+        if (el.__bhBaseline === undefined || el.__bhBaseline !== current) presentNew.push(describe(el));
+        else preexisting += 1;
+        el.__bhBaseline = current;
+    }
+
+    return JSON.stringify({
+        installed_now: installedNow,
+        armed_at_ms: state.armedAt,
+        seen: state.seen.splice(0),
+        present_new: presentNew,
+        present_preexisting: preexisting,
+        pending: state.pending,
+    });
+})()
diff --git a/.claude/skills/app-bug-hunt/spec-ledger.md b/.claude/skills/app-bug-hunt/spec-ledger.md
index e640a3f..3b2dc96 100644
--- a/.claude/skills/app-bug-hunt/spec-ledger.md
+++ b/.claude/skills/app-bug-hunt/spec-ledger.md
@@ -1,7 +1,8 @@
 # bug-hunt 仕様台帳 (spec-ledger) — 既知仕様 / 誤検知の申し送り
 
 このファイルは、過去の bug-hunt run で挙がった finding のうち **実コード裏取り + 敵対的検証の結果
-「仕様 (SPEC)」または「ドキュメント側対応 (DOC)」と確定したもの**を記録する、人間可読の申し送り台帳。
+「仕様 (SPEC)」「ドキュメント側対応 (DOC)」「誤検知 (FALSE_POSITIVE)」と確定したもの**を記録する、
+人間可読の申し送り台帳。
 
 機械 registry (`ledger/adjudications.jsonl`) の**対**である:
 
@@ -13,7 +14,6 @@ # bug-hunt 仕様台帳 (spec-ledger) — 既知仕様 / 誤検知の申し送
 同じ説明文を両方に重複させない。機械照合が要るものは registry に、
 「なぜ SPEC と確定したか」の物語は本ファイルに書く。
 
-> **現状: 中身は空**。AI-CUE の実 run から書き起こす。
 > 旧 registry の spirux 由来 18 件は AI-CUE に実在しない資産を指していたため削除済み
 > (理由は `ledger/README.md` 運用ガード (d))。**他アプリの申し送りを写さない**。
 
@@ -39,8 +39,12 @@ ## 書式ルール
 - **append-only + supersede**。既存の確定項目を黙って書き換えない。撤回するときは
   「実装で解消 (旧 SPEC を撤回)」節を作り、**撤回した事実と根拠**を残す。
 - run 単位の節 (`## run {run_id} 申し送り ({date})`) を**新しい run が上**になるよう積む。
-- 節の中は `### SPEC 確定 (再起票しない)` / `### DOC 確定` / `### 実装で解消 (旧 SPEC / accepted を撤回)`
-  / `### CLOSED (非再発を確認)` に分ける。
+- 節の中は `### SPEC 確定 (再起票しない)` / `### 誤検知確定 (再起票しない)` / `### DOC 確定`
+  / `### 実装で解消 (旧 SPEC / accepted を撤回)` / `### CLOSED (非再発を確認)` に分ける。
+  節見出しは機械 registry の `verdict` 語彙に対応させる
+  (`誤検知確定` = `false_positive` / `SPEC 確定` = `intentional`)。
+  `wont_fix` は現時点で該当項目が無いため節を作らない。必要になったら
+  `### wont_fix 確定 (再起票しない)` を追加する (節の追加は書式ルールの更新を伴う)。
 
 ---
 
@@ -55,15 +59,54 @@ ## run {run_id} 申し送り ({YYYY-MM-DD})
 ### SPEC 確定 (再起票しない)
 
 #### {finding_id} — {事象を 1 行で。何が「バグに見えた」か}
-- **判定**: SPEC (意図仕様) | DOC (ドキュメント側の陳腐化)
+- **判定**: SPEC (意図仕様) | DOC (ドキュメント側の陳腐化) | FALSE_POSITIVE (観測 artifact)
 - **根拠 (file:line)**: `path/to/File.php:123` (何をしているか) /
   `resources/js/pages/Foo/Bar.svelte:45` / `AGENTS.md#anchor` / `tests/Feature/FooTest.php`
   ※ 設計文書・実コード・テストの三点。**実在するパスのみ**書く
 - **なぜ誤検知に見えたか**: {fake mode / 観測窓 / viewport 等、bug-hunt 側の事情}
+- **driver 側の再発防止**: {この誤検知を機構で防ぐ手立て。SKILL.md のどの規約か / 「なし (人手注意のみ)」}
+  ※ 人手の心構えで終わらせないための必須欄
 - **watch_globs (機械 registry に載せる場合)**: `path/to/File.php`, `resources/js/pages/Foo/Bar.svelte`
   ※ この判定を無効化しうる実在ファイルのみ。過広 (`app/**` 等) 禁止
+  ※ **既に registry に登録済なら glob を書き写さず「`A-NNN` に登録済 (正本は registry)」とだけ書く**
+  (照合条件の正本は registry。二重管理は腐りの温床)
 - **review_after_days**: {int > 0。仕様の揺れやすさで決める。例 120 / 180}
 - **確定した run_id**: {run_id} (commit {short_sha})
 - **再オープン条件**: {どうなったら再び finding として起票してよいか}
 - **機械 registry**: `ledger/adjudications.jsonl` の `A-NNN` に登録済 / 未登録 (理由: …)
 ```
+
+---
+
+## run 20260803-203721 申し送り (2026-08-04)
+
+### 誤検知確定 (再起票しない)
+
+#### F-1-02 — 動画マニュアル削除後に「成功 flash が出ない」ように見えた
+- **判定**: FALSE_POSITIVE (観測 artifact)
+- **根拠 (file:line)**: `app/Http/Controllers/Projects/VideoManualController.php:230-232`
+  (削除後 `projects.show` へ redirect し `->with('success', '動画マニュアルを削除しました')`) /
+  `resources/js/lib/stores/toast.ts:23-29` (success/info/warning は **4000ms で auto-dismiss**、
+  error のみ `null` = 自動消去しない) /
+  `resources/js/components/organisms/ToastContainer.svelte`
+  (`role="status"` + `data-testid="toast-{type}"` で描画) /
+  `tests/Browser/FlashToastTest.php` (着地マーカーと**同一時間窓**で `toast-success` が可視になることを
+  Chromium / WebKit の 2 レーンで pin)
+- **なぜ誤検知に見えたか**: bug-hunt driver の観測は「操作 → 事後 snapshot」の 1 点サンプリングで、
+  Bash 1 往復ぶん (数百 ms〜数秒、並列 shard ではさらに遅延) 後ろにずれる。可視窓 4000ms の後に
+  snapshot が来れば「flash 無し」に見える。T095 の実装フェーズで **現行コードのまま** Browser テストを
+  両レーンで走らせて PASS したため、アプリ側は正しいと確定した。**アプリコードは変更していない。**
+- **driver 側の再発防止**: `SKILL.md` §一過性フィードバックの観測 — 書き込み操作の**直前**に
+  feedback probe (`.claude/skills/app-bug-hunt/probes/feedback-probe.js`) を仕込み、直後に読む。
+  「事後 snapshot に無い」を根拠に H7 を起票することを禁止した。
+  回帰は `tests/js/bughunt/feedback-probe.test.ts` が固定する。
+- **watch_globs (機械 registry に載せる場合)**: `ledger/adjudications.jsonl` の A-001 に登録済。
+  **本ファイルには重複させない** (正本は registry)。
+- **review_after_days**: 180 (A-001 と同値)
+- **確定した run_id**: 20260803-203721 (commit 22d6d30)
+- **再オープン条件**: 次のいずれか。
+  (a) `VideoManualController::destroy` が `->with('success', ...)` を落とした、
+  (b) `toast.ts` の success 用 `AUTO_DISMISS_MS` が大幅に短縮された、
+  (c) feedback probe が `installed_now:false` かつ `seen`(visible:true) / `present_new` ともに空を返した。
+  **probe を使わない事後 snapshot 単独の観察は再オープン根拠にならない。**
+- **機械 registry**: `ledger/adjudications.jsonl` の `A-001` に登録済 (verdict=false_positive)
diff --git a/tests/js/bughunt/feedback-probe.test.ts b/tests/js/bughunt/feedback-probe.test.ts
new file mode 100644
index 0000000..d119889
--- /dev/null
+++ b/tests/js/bughunt/feedback-probe.test.ts
@@ -0,0 +1,242 @@
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
+}
+
+interface ProbeResult {
+    installed_now: boolean;
+    armed_at_ms: number;
+    seen: ProbeEntry[];
+    present_new: ProbeEntry[];
+    present_preexisting: number;
+    pending: number;
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
+
+    beforeAll(() => {
+        // jsdom は layout を持たない → FlashToastTest と同じ可視判定を成立させるための stub。
+        // 「接続済み かつ hidden でない かつ display:none / visibility:hidden でない」なら矩形あり。
+        Element.prototype.getClientRects = function (
+            this: Element,
+        ): DOMRectList {
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

## 修正後の検証結果

- `python3 -m unittest discover -s .claude/skills/app-bug-hunt/ledger -p 'test_spec_ledger.py'` → **3 tests OK**
  (`PATH_LIKE` 変更後も F-1-02 の根拠 4 パスが実在確認されることを確認)
- probe の構文チェック (`new Function` で式としてパース) → OK
- `pnpm typecheck` / `pnpm lint` → OK (Round 1 時点。`describe.sequential` 追加後に再実行予定)
- `composer phpstan` → **[OK] No errors** (PHP 変更なし)
- `scripts/bug-hunt-shard.sh self-test` → all passed
- `pnpm test` フル / `composer test` は**環境の load average が 55 超**のため実行待ち
  (この環境では他 worktree の同時走行で vitest の component render が既定 timeout に
  間に合わず**本変更と無関係なファイル**が落ちることがあるため、負荷が落ちてから完走させる)。
  probe テスト単体 (`vitest run tests/js/bughunt/feedback-probe.test.ts`) は
  Round 1 時点で **12 block / 18 ケース PASS を 3 連続**で確認済み。

---

## 依頼

1. 上記の対応 (特に [Warning] `pending` の例外安全) が指摘の意図を満たしているか確認せよ。
2. **見送り**とした 2 件 (同一 mutation の dedupe / `pending>0` 再 probe 前の短待機) の
   反論根拠が妥当か評価せよ。妥当でないなら再反論を出せ。
3. 修正によって**新たに入った欠陥**が無いか (特に `PATH_LIKE` の拡張で
   意図しないトークンをパス扱いして誤 FAIL しないか、`describe.sequential` の副作用)。
4. 全体判定 **APPROVED** / **CHANGES_REQUESTED** を最後に 1 行で出せ。
