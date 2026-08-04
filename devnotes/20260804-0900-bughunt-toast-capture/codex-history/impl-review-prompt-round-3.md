# impl-review Round 3

Round 2 の [Critical] 2 件 + [Warning] 1 件 + [Suggestion] 2 件をすべて反映した。
対応マトリクスと、**変更した 4 ファイルの差分** (`feedback-probe.js` / `feedback-probe.test.ts` /
`SKILL.md` / `test_spec_ledger.py`。`spec-ledger.md` と `bughunt-shard.md` は Round 2 で APPROVED 済で無変更) を送る。

---

## 対応マトリクス (Round 2 の指摘をどう捌いたか)

# 対応マトリクス: impl-review Round 2

## [Critical] probe: 例外時の `visible:false` が新たな偽陽性を作る (feedback-probe.js:96)

- 判断: **対応する** (指摘は正しい。Round 1 の私の修正が不完全だった)
- 根拠: Round 1 で `catch` を足したことで `pending` の恒久残留は解消したが、
  結果として **「可視判定が例外で解決できなかった」応答が `visible:false` / `pending:0` /
  `present_new:[]` になり、判定表 2 行目の『本当に出なかった』と完全に一致**してしまう。
  つまり判定不能を**陰性証拠**として扱う経路を作っており、
  本設計が潰そうとしている誤検知 (F-1-02 と同型) を別の入口から作り直していた。
  Round 1 の Warning は「pending の対称性」だったが、観測契約としては未完という指摘は妥当。
- 対応内容 (probe / プロトコル / テストの 3 点セット):
  1. **probe**: 応答に `errors` を追加した。`seen` を drain した batch のうち
     `error` を持つ entry 数を数えて返す
     (`const drained = state.seen.splice(0); ... errors: drained.filter((e) => e.error !== undefined).length`)。
     `visible:false` に倒す点は変えない (証拠集合は `visible:true` のみという既存契約を壊さないため) が、
     **判定不能であることが応答から機械的に読める**ようにした。
  2. **SKILL.md**: 判定表に 1 行追加 —
     `errors > 0` は **陰性判断に使えない**。肯定証拠 (`visible:true` + 結果文言) があれば
     「フィードバックあり」でよいが、無ければ **未検証** (H7 finding にしない)。
     併せて 2 行目の条件を `pending:0` **かつ** `errors:0` に締め、
     H7 適用条件の本文も `installed_now:false` かつ `pending:0` かつ `errors:0` に更新した。
     finding 証跡欄の書式にも `errors=0` を足した。
  3. **テスト**: 下記 [Warning] の回帰テスト N を追加。
- 専用 `failed` カウンタではなく `errors` (件数) にしたのは、判定が件数ではなく
  「0 か否か」だけを見るためで、entry 側の `error` 文字列は triage 用の診断として残している。

## [Critical] SKILL.md: `seen[].error` の扱いが判定表に無い (SKILL.md:284)

- 判断: **対応する** (上記 Critical と同一原因。同じ修正で閉じる)
- 対応内容: 判定表の新規行と H7 適用条件の更新で、`errors > 0` が**陰性判断の出口を塞ぐ**ことを明文化した。
  「`visible:false` は『不可視だった』ではなく『判定不能』である」も同行に明記して、
  driver が `visible:false` を陰性証拠と読み違えないようにした。

## [Warning] 例外経路の回帰テストが無い (feedback-probe.test.ts:113)

- 判断: **対応する**
- 根拠: 例外経路は「壊れたときだけ通る」経路なので、テストが無いと
  次の変更で静かに壊れ、しかも**壊れ方が誤検知**という最悪の失敗モードになる。
- 対応内容: ケース **N** を追加した (K の直前。K は記録器を消すので最後に置く必要がある)。
  `Element.prototype.getClientRects` の stub に `rectsShouldThrow` フラグを足し、
  rAF が走る区間だけ throw させる (probe 本体の同期評価は正常系に戻してから叩く)。
  検証は 3 点: `pending === 0` (対称性) / entry に `error` があり `visible === false` /
  `errors === 1` (陰性判断に使えないことが機械的に読める)。
  これで jsdom のケース数は 18 → **19** になった (詳細設計の表は 18 のまま = 逸脱として記録する)。

## [Suggestion] `ProbeEntry` に `error?: string` を追加 (feedback-probe.test.ts:21)

- 判断: **対応する**
- 対応内容: `ProbeEntry.error?: string` と `ProbeResult.errors: number` を型に追加した。
  テスト側の型が probe の返却契約と一致し、`errors` の取り違えを `tsc --noEmit` が検出する。

## [Suggestion] test_spec_ledger.py: `file.ts:12:5` が依然として検査対象外 (test_spec_ledger.py:61)

- 判断: **対応する**
- 根拠: 位置記法を 1 セグメントしか許容していないと、複数セグメント記法で書かれた根拠が
  丸ごとすり抜ける。守りたい不変条件は「パスの実在」なので、**許容集合は広い方が強い**。
- 対応内容: サフィックスを `(?:[:#][\w.-]*)?` → `(?:[:#][\w.-]*)*` に変更 (0 回以上の繰り返し)。
  `a/b.ts:12:5` / `AGENTS.md#anchor` / `x.js#L10` / `x.php:230-232` がパス部だけ抽出され、
  `role="status"` / `A-001` / `toast-success` のような非パストークンは従来どおり素通りすることを実測確認した。

## [Suggestion] dedupe 見送り / 短待機見送りの妥当性確認

- 判断: **対応不要** (Codex が両方とも妥当と評価。Round 1 の判断を維持)

## APPROVED 済みファイル

- `.claude/skills/app-bug-hunt/spec-ledger.md` / `.claude/agents/bughunt-shard.md`: 変更なし。


---

## 修正後の差分 (git diff HEAD、対象 4 ファイル)

```diff
diff --git a/.claude/skills/app-bug-hunt/SKILL.md b/.claude/skills/app-bug-hunt/SKILL.md
index e312fd6..9d83e8d 100644
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
@@ -247,6 +250,67 @@ ### 走行プロトコル (各ステップ共通)
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
+| `pending > 0` | 可視性判定が未解決 | probe をもう 1 回だけ叩き、**1 回目と 2 回目の `seen` / `present_new` の和集合**で判定する。2 回目も `pending > 0` なら**未検証** |
+| `errors > 0` | 可視判定そのものが例外で解決できなかった entry がある (`seen[].error`) | **陰性判断に使えない**。肯定証拠 (`visible:true` + 結果文言) があれば「フィードバックあり」でよいが、無ければ **未検証** (H7 finding にしない)。`visible:false` は「不可視だった」ではなく「判定不能」である |
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
@@ -262,6 +326,9 @@ ### 横断ヒューリスティクス (毎ステップ適用)
 | H9 | 権限外データの表示・操作 (IDOR 含む。他組織/他プロジェクトのリソース) | Critical |
 | H10 | 文言・件数・状態が直前の操作結果と矛盾 (例: 作成したのに一覧に出ない) | High |
 
+> **H7 の前提条件**: 「結果フィードバックが無い」の判定には **feedback probe の陰性所見**が必須
+> (§一過性フィードバックの観測)。事後 snapshot に無いことを根拠に H7 を起票しない。
+
 **UI/UX ヒューリスティクス (H11-H14、視覚/操作品質。snapshot + screenshot で観察)**
 
 | # | 兆候 | 既定 severity |
@@ -288,7 +355,9 @@ ## F-{連番}: {一行サマリ}
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
@@ -322,6 +391,7 @@ # bug-hunt report {日時}
 - 操作カバレッジ: 実行 n / operations.md 対象 m (未実行を列挙、skip は理由必須)
 - UI/UX 検証: 視覚破綻(H11) / アフォーダンス・状態(H12) / レスポンシブ(H13: resize した画面と viewport) / a11y 基礎(H14) の所見
 - findings: Critical x / High y / Medium z / Low w / 要確認 v (UI/UX = H11-H14 由来は H 番号を併記)
+- H7 未検証 (観測窓が途切れ肯定証拠も得られなかった操作): n 件 (操作名を列挙)
 (以下 finding 詳細を severity 降順で)
 ```
 
diff --git a/.claude/skills/app-bug-hunt/ledger/test_spec_ledger.py b/.claude/skills/app-bug-hunt/ledger/test_spec_ledger.py
new file mode 100644
index 0000000..2ce8856
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
+# 位置指定 (`:123-125` / `:12:5` / `#L12` / `#anchor`) は**捨てて**パス部だけを実在確認する。
+# 位置記法を許容集合に入れておかないと、その記法で書かれた根拠が丸ごと検査対象外に
+# すり抜けてしまう (腐りの見逃し)。
+PATH_LIKE = re.compile(
+    r"^(?P<path>[\w./-]+\.(?:php|ts|js|svelte|md|json|ya?ml|py|sh))(?:[:#][\w.-]*)*$"
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
index 0000000..4b1647b
--- /dev/null
+++ b/.claude/skills/app-bug-hunt/probes/feedback-probe.js
@@ -0,0 +1,154 @@
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
+ *   errors                : 可視判定が例外で解決できなかった entry 数 (>0 なら**陰性判断に使えない**)
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
+                    // 判定不能は「不可視」ではない。visible:false に倒したうえで error を刻み、
+                    // 応答の errors に数える (errors>0 の応答は**陰性判断に使えない** = 未検証)。
+                    // ここを黙って visible:false にすると「本当に出なかった」と区別できず、
+                    // 本設計が潰そうとしている誤検知を作り直すことになる。
+                    entry.visible = false;
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
+    const drained = state.seen.splice(0);
+
+    return JSON.stringify({
+        installed_now: installedNow,
+        armed_at_ms: state.armedAt,
+        seen: drained,
+        present_new: presentNew,
+        present_preexisting: preexisting,
+        pending: state.pending,
+        errors: drained.filter((e) => e.error !== undefined).length,
+    });
+})()
diff --git a/tests/js/bughunt/feedback-probe.test.ts b/tests/js/bughunt/feedback-probe.test.ts
new file mode 100644
index 0000000..b90d977
--- /dev/null
+++ b/tests/js/bughunt/feedback-probe.test.ts
@@ -0,0 +1,264 @@
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

## 検証結果

- `python3 -m unittest discover -s .claude/skills/app-bug-hunt/ledger -p 'test_spec_ledger.py'` → **3 tests OK**
- `PATH_LIKE` の挙動を実測:
  `a/b.php:12` → `a/b.php` / `a/b.ts:12:5` → `a/b.ts` / `AGENTS.md#anchor` → `AGENTS.md` /
  `x.js#L10` → `x.js` / `x.php:230-232` → `x.php` / `role="status"` → 非マッチ /
  `A-001` → 非マッチ / `toast-success` → 非マッチ / `no-ext` → 非マッチ
- `composer phpstan` → **[OK] No errors**
- `scripts/bug-hunt-shard.sh self-test` → all passed
- `pnpm test` (フル) と `composer test` は **load average 70 超**の環境事情で実行待ち。
  probe テスト (ケース N 追加後の 19 ケース) の再実行結果は本ラウンドの返答後に確認する
  — この点は「未確認」として正直に扱い、green を確認できるまでコミットしない。

---

## 依頼

1. `errors` の導入 (probe 応答 + 判定表 + H7 適用条件 + 証跡書式) が
   Round 2 [Critical] の意図 —「可視判定不能を陰性証拠にしない」— を**完全に**満たしているか。
   まだ判定不能が陰性に倒れる経路が残っていないか探せ。
2. ケース N のテスト設計 (rAF 区間だけ throw させ、probe 本体は正常系に戻してから叩く) が
   検証したい契約を実際に固定しているか。false green になっていないか。
3. `PATH_LIKE` の繰り返し化で過剰マッチ (非パストークンをパス扱いして誤 FAIL) が起きないか。
4. 全体判定 **APPROVED** / **CHANGES_REQUESTED** を最後に 1 行で出せ。
