# 実装レビュー Round 2 (T245)

Round 1 の指摘 (Critical 0 / Warning 7 / Suggestion 2) に **9 件すべて対応**した (見送り 0 件)。
対応マトリクスと、対応後の差分 (Round 1 で見せた版からの差分ではなく、
**変更したファイルの main からの現在の差分全体**) を示す。

再レビューして全体判定 (APPROVED / CHANGES_REQUESTED) を出してほしい。
特に次の 3 点を重点的に見てほしい。

1. **W2 (AC-06 の負例)** — 実カード 7 枚のうち S1 の面だけを差し替える形にし、
   正の対照 (面を戻すと pin と一致する) を同じテストに置いた。これで
   「正しい理由で落ちる」を満たしているか
2. **W4 の副作用** — A5 を機械で閉じるために読み取り器の `FORBIDDEN_VALUE_CHARS` へ
   YAML の構造記号 (`&` `*` `|` `>` `{` `}`) を足した。**値の表現力を過度に狭めていないか**
   (実カード 7 枚の全値を走査して該当 0 件は確認済み。ただし将来の title / setup で
   これらを使いたくなる可能性の評価がほしい)
3. **W7 の原因究明** — `screens.md` の差分は**私の変更ではなく main の既存ドリフト**だった。
   T240 が生成物を直接編集し正本 (`inventory/notes-screens.md`) を更新していなかったため、
   main で `bug-hunt-inventory.py check` が **exit 3** になる (実測)。
   T240 の記述を正本へ移して再生成し、内容を保ったまま差分を「生成通知の 4 行だけ」にした。
   **既存ドリフトの解消を本コミットへ含める判断**が妥当か

## 検証結果 (対応後)

- `composer phpstan` (level 10): No errors / `vendor/bin/pint --test`: passed
- `python3 -m unittest test_story_front_matter` (stories/): **81 tests OK** (73 → 81)
- `python3 -m unittest test_bug_hunt_inventory` (scripts/tests/): 75 tests OK
- `python3 -m unittest test_correlate` (coverage/): **60 tests OK** (58 → 60)
- `python3 scripts/bug-hunt-inventory.py check`: exit 0 (画面 71 件 / 操作 79 件)
- 移行の検算: **成功** (変換前のみ 0 件 / 変換後のみ = S7 の追加分と完全一致 /
  7 枚の `## 手順` 節の sha256 が全件一致)
- `composer test`: 実行中 (結果は Round 2 の判定前に確認する)
- 新しい負例が「正しい理由で」落ちることは、違反メッセージを 1 件ずつ出力して実測確認した
  (例: `id:S1` → 「半角コロンの後に半角空白 1 つが要る」/ `title: &anchor …` →
  「スカラーに使えない文字がある: '&'」/ 区切り行 `|-|-|-|` → 「正準区切り行でない」)

---

## 対応マトリクス

# 対応マトリクス: impl-review Round 1

Codex Round 1 の全体判定は **CHANGES_REQUESTED** (Critical 0 / Warning 7 / Suggestion 2)。
**9 件すべてに対応した** (見送り 0 件)。

## [Warning] 1. S8 以降の追加を Architecture テストが禁止している

- 判断: **対応する**
- 根拠: 指摘のとおり。`test_readme_is_excluded_and_others_are_not()` の `len(names) == 7` は
  D7 (S8 以降を正規手続きで追加できる) と正面から矛盾する。AC-06 の pin を
  `PINNED_IDS` に限定して S8 を阻害しないように作ってあるのに、別テストが阻害していた。
- 対応内容: 件数の固定を削除し、「除外は閉じたリテラル 1 件だけで、他の `*.md` は全件が候補になる」
  ことの検査へ置き換えた (`glob("*.md") - EXCLUDED_FILENAMES == names`)。
  母集団の非空は `test_population_is_not_empty`、表 B との 1 対 1 は AC-05 が持つ。
  docstring に「件数を pin しない理由」を書いた。

## [Warning] 2. AC-06 の負例が「面の付け替え」を検出したことを証明していない

- 判断: **対応する**
- 根拠: 指摘のとおり。S1 だけのカード集合を 7 組の期待と比べていたので、S1 の面を
  正しい値へ戻しても不一致のまま落ちる = 共通規約 (c)「正しい理由で落ちる」を満たさない。
  この負例は `StoryFrontMatterPins::CORE_NEGATIVES` に pin されている中核なので影響が大きい。
- 対応内容: **実カード 7 枚のうち S1 の面だけを差し替える**形に直した。併せて
  **正の対照** (面を `signup_funnel` へ戻すと pin と一致する) を同じテストに置き、
  落ちた理由が面の付け替えであることを裏取りした。`assertEqual(6, len(others))` で
  母集団の取り違えも防いだ。

## [Warning] 3. 表 A / 表 B の構造検査が詳細設計より寛容

- 判断: **対応する**
- 根拠: 3 点とも実装が契約より緩かった。詳細設計は「マーカーごと。空行の位置も契約」と
  明記しており、実装がそれを検査していなかった。
- 対応内容: `marker_table()` に 3 つの判定を足した。
  (a) `text.index(begin) > text.index(end)` で **BEGIN/END の順序**を見る
  (b) 空行を除去せず `["", "", <表>, "", ""]` の**配置そのもの**を契約にし、
      表の中の空行も違反にする
  (c) 区切り行は各セルがちょうど `---` の**正準区切り行**に完全一致させる
  負例を 3 本追加した (`rejects_reversed_markers` /
  `rejects_blank_line_layout_change` / `rejects_non_canonical_separator_row`)。

## [Warning] 4. 制限文法の負例が全分岐を裏取りしていない

- 判断: **対応する**
- 根拠: 指摘のとおり。「主題に何らかの rejects がある」だけでは各不変条件の検出力を
  証明できない (共通規約 (c))。
- 対応内容: AC-01 に負例を 5 本追加した。
  - `rejects_malformed_key_value_separator` — コロン後の空白なし / 複数空白 / key 末尾空白 / コロンなし
  - `rejects_malformed_key_syntax` — 大文字・数字始まり・ハイフン・記号のみ
  - `rejects_malformed_array_syntax` — 区切りの揺れ 3 形 / ネスト / 角括弧なし / 閉じ忘れ
  - `rejects_yaml_structures` — 複数行スカラー (`|` `>`) / アンカー / 参照 / フローマップ / ネストマップ
  - `rejects_key_outside_type_sets` — 型集合への登録漏れ (下記 8 の裏取り)
  併せて `rejects_missing_delimiter` に「閉じる `---` が無い」を足した。
  **A5 を機械で閉じるために読み取り器も直した** — YAML の構造記号
  (`&` `*` `|` `>` `{` `}`) を値から締め出した。これを素のスカラーとして黙って受けると
  「アンカー・参照・複数行スカラーは書けない」と言えなくなるため。
  実カード 7 枚の全値を走査して、これらの文字を使っている値が 0 件であることを確認済み。

## [Warning] 5. `SKILL.md` と `scripts/README.md` の古い操作指示の残置

- 判断: **対応する** (当初は「設計が触らないと定めているので残置」としていた判断を撤回する)
- 根拠: 指摘のとおり。`story` を書くと exit 3 になるので静かな破損ではないが、
  **正規手順どおり操作すると必ず失敗する**状態であり、「割当の正本を一本化した」という
  完了条件に反する。採用時債務は「説明の無い食い違いを凍結する」ための一覧であって、
  運用契約を古いまま据え置く根拠にはならない。
- 対応内容: 両ファイルの該当箇所を更新し (SKILL.md 4 か所 / scripts/README.md 1 行)、
  乖離台帳の 3 択のうち**「登録を書いて債務から削る」**を採って D20 の対象パスへ移した。
  `ADOPTION_DEBT_COUNT` 168 → 166。D20 に「対象パスに運用文書 2 本を含める理由」の段を足し、
  **本エントリが説明するのは目録の生成方式に関わる記述だけである**ことを明記した
  (両ファイルの他の差分まで説明したことにしない)。

## [Warning] 6. correlate の終了コード 3 への写像がテストされていない

- 判断: **対応する**
- 根拠: 指摘のとおり。`parse_story_cell()` / `correlate()` が投げることだけを見ても
  `main()` の捕捉と写像は裏取りできず、catch を壊しても緑になる。
- 対応内容: `MainTest` に 2 本追加した。
  - `test_main_contract_violating_story_cell_returns_3` — 契約外セル 4 形
    (連続空白 / カンマ区切り / `S0` / 降順 / 重複) で `main()` が **3** を返し、
    **標準出力へ 1 バイトも出さない** (worklist 非出力) ことを固定する
  - `test_main_multi_value_story_cell_is_accepted` — 正の対照 (`S1 S4` は 0 で通る)
  前後空白の形は表ローダが `strip()` するので `main()` 経由では到達しない。
  その旨をコメントに書き、`parse_story_cell()` の単体検査が押さえていることを示した。

## [Warning] 7. `screens.md` に設計で宣言されていない意味変更が混入している

- 判断: **対応する** (指摘は正しいが、原因は本作業ではなかった)
- 根拠: 調査したところ、**main が既にドリフトしていた**。T240
  (`7d8d015b`「bughunt 要確認グループ対応 (Q-2-01 …)」) が**生成物 `screens.md` を直接編集**し、
  正本である `inventory/notes-screens.md` を更新していなかった。
  main で `python3 scripts/bug-hunt-inventory.py check` を走らせると
  `[生成物] 生成物が再生成の結果と一致しない: screens.md` で **exit 3** になる (実測)。
  つまり私の再生成は T240 の記述を**黙って消していた** — 指摘のとおり除外すべき差分だった。
- 対応内容: T240 の記述を**正本 `inventory/notes-screens.md` へ移して**再生成した。
  結果、`screens.md` の非表部分の差分は**生成通知の 4 行だけ**になり、T240 の内容
  (Q-2-01: `manageBilling` 非保持メンバーを `dashboard` へ寄せる) は保たれている。
  既存ドリフトの解消を本コミットに含める判断: 本作業は生成器そのものを作り替えており、
  ドリフトを残したままでは `generate` が走らせられない (段 3 の byte 一致が成立しない)。

## [Suggestion] 8. スカラー型の分類を fail-closed に

- 判断: **対応する**
- 根拠: 指摘のとおり。`SCALAR_KEYS` を宣言だけして使わない形は、共通規約 (d)
  「集めた走査結果を判定に使わない形を作らない」にも触れる。
- 対応内容: `elif key in SCALAR_KEYS:` にし、どの型集合にも属さない正準 key は
  「どの型集合にも登録されていない key である」として違反にした。
  負例 `test_ac_01_rejects_key_outside_type_sets` で `CANONICAL_KEYS` を一時的に
  拡張して検出分岐を裏取りしている。

## [Suggestion] 9. gate の docblock の「名指し 2 ファイル」が古い

- 判断: **対応する**
- 根拠: 指摘のとおり。読み取り器も名指しで複製するようになった。
- 対応内容: docblock を「名指しの 3 ファイル」へ直し、読み取り器のパスを列挙した。

---

## 対応後の差分 (変更したファイルの main からの現在の差分)

```diff
diff --git a/.claude/skills/app-bug-hunt/SKILL.md b/.claude/skills/app-bug-hunt/SKILL.md
index 1d60d17d..913d5576 100644
--- a/.claude/skills/app-bug-hunt/SKILL.md
+++ b/.claude/skills/app-bug-hunt/SKILL.md
@@ -16,7 +16,9 @@ # 探索的バグハント (bug-hunt)
 
 > **テンプレート注記**: 本スキルは spirux/aigenba の bug-hunt 基盤を汎用化したもの。アプリ名・ポート・DB 名は
 > プレースホルダ化してある。`screens.md` / `operations.md` は**生成物**で、注釈 (`inventory/annotations.toml`)
-> と散文 (`inventory/notes-*.md`) から作る (下記 Phase 1)。`stories/` はスケルトンのままである。
+> と散文 (`inventory/notes-*.md`) と**シナリオカードの前付け** (`stories/S*.md` の `covers_*`) から作る
+> (下記 Phase 1)。**割当 (どのカードが route を消化するか) の正本はカードの前付けである**
+> (書式の正本は `stories/README.md`。逸脱の登録は `docs/template-divergence.md` D20 / D40)。
 > オプトインで、使わなければアプリ実行には一切影響しない
 > (config/bughunt.php + BughuntCoverageMiddleware は env + function_exists の二重 guard で完全 no-op)。
 
@@ -123,8 +125,10 @@ ### 手順 (親 = このセッション。worktree 内から実行)
      **shard agent は consult しない** (子は素の `proposed` finding のみ)。
 6. **teardown**: `BUGHUNT_ORCHESTRATOR=1 scripts/bug-hunt-shard.sh teardown --run-id {ts} [--drop-db]`。
    その後、手順2 の `--hold-lock` 常駐プロセスを終了して lock 解放。
-7. **目録修正の反映**: 統合 report に記録した採用分のみを `inventory/annotations.toml` (割当・区分・理由) /
-   `inventory/notes-*.md` (散文) / stories に反映し、`python3 scripts/bug-hunt-inventory.py generate` を走らせる。
+7. **目録修正の反映**: 統合 report に記録した採用分のみを `stories/S*.md` の前付け (割当 = `covers_*`) /
+   `inventory/annotations.toml` (区分・理由・種別) / `inventory/notes-*.md` (散文) に反映し、
+   `python3 scripts/bug-hunt-inventory.py generate` を走らせる。
+   **割当を `annotations.toml` へ書かない** (`story` は未知の項目として exit 3 になる)。
 8. **adjudication 追記の規律 (人手判断時のみ)**: finding を誤検知 / 意図的仕様 / won't-fix と確定したら、
    cross-session の再 triage を避けるため `ledger/adjudications.jsonl` に 1 行 append (既存行は編集しない)。
    詳細スキーマは `ledger/README.md`。
@@ -213,8 +217,9 @@ ## Phase 1: 目録の鮮度確認 (生成物なので手で書かない)
 - **exit 3 (ドリフト)** の出力は 3 種類に分かれる。
   - `[注釈] 未注釈の route: …` — 実装に route が増えた。
     `.claude/skills/app-bug-hunt/inventory/annotations.toml` に 1 行足す
-    (画面なら `kind` = 画面 / JSON、割当なら `story` = S1..S7 と `kubun` = 通常 / 逸、
+    (画面なら `kind` = 画面 / JSON、`kubun` = 通常 / 逸 / 終、
     探索の分母に載せないなら `kubun` = 外 と 30 文字以上の `reason`)。
+    **割当はここに書かない** — 消化するカードの `covers_screens` / `covers_operations` へ足す。
   - `[注釈] 実装に無い route の注釈が残っている: …` — route が消えた。注釈も消す。
   - `[生成物] 生成物が再生成の結果と一致しない: …` — 再生成し忘れか手編集。下記を走らせる。
 - 注釈を直したら再生成する (**表の行は手で書かない**):
@@ -459,9 +464,11 @@ ### Phase 4b: worktree のクローズ (既定の worktree 走行時)
 
 ## メンテナンス規約
 
-- 新画面・新フローを実装したら `inventory/annotations.toml` に注釈を 1 行足して再生成し
-  (`python3 scripts/bug-hunt-inventory.py generate`)、該当ストーリーを更新する。
-  新しい書き込みルートは必ずいずれかのストーリーに割り当てる (未注釈は inventory-check.sh が exit 3)。
+- 新画面・新フローを実装したら `inventory/annotations.toml` に注釈を 1 行足し、**消化するカードの
+  前付け (`covers_screens` / `covers_operations`) にも route 名を足して**再生成する
+  (`python3 scripts/bug-hunt-inventory.py generate`)。
+  対象内 (区分が `外` でない) の route は必ず 1 枚以上のカードに載せる
+  (未注釈も未割当も inventory-check.sh が exit 3)。
   **screens.md / operations.md を直接編集しない** (生成物であり、byte 比較で赤くなる)。
 - ストーリーカードの「期待」は設計の正 (devnotes/docs) への参照を持つこと。カード自体が仕様の正本になってはならない。
 - 同じ finding が 2 回連続で「要確認」のまま放置されたら、仕様を確定させる TODO を提案する。
diff --git a/.claude/skills/app-bug-hunt/coverage/test_correlate.py b/.claude/skills/app-bug-hunt/coverage/test_correlate.py
index 3ad3c3d7..87c55de7 100644
--- a/.claude/skills/app-bug-hunt/coverage/test_correlate.py
+++ b/.claude/skills/app-bug-hunt/coverage/test_correlate.py
@@ -368,6 +368,38 @@ class CorrelateTest(unittest.TestCase):
             self.assertTrue(r.via_capability)
             self.assertIn("AUTH-03", r.capability_tags)
 
+    def test_複数値行は両方のstoryへブロードキャストされる(self):
+        operations = dict(self.operations)
+        operations["organizations.store"] = {
+            "operation": "organizations", "story": "S1 S4", "kubun": "◎",
+        }
+        findings = [
+            {"finding_id": "F-1", "run_id": self.run_id, "story_id": "S4",
+             "capability_tag": "ORG-04", "species_key": "x", "severity": "high"},
+        ]
+        corr = C.correlate(self.routes, operations, self._executed([]), findings, self.tb,
+                           run_id=self.run_id)
+        row = next(r for r in corr.rows if r.route_name == "organizations.store")
+        self.assertEqual(1, row.finding_count)
+        self.assertTrue(row.via_capability)
+        # 単一値の S4 機構にも同じ finding が届く (従来の挙動が変わっていない)。
+        transfer = next(r for r in corr.rows if r.route_name == "organizations.transfer")
+        self.assertEqual(1, transfer.finding_count)
+        # S1 の finding も複数値行へ届く。
+        s1 = [{"finding_id": "F-2", "run_id": self.run_id, "story_id": "S1",
+               "capability_tag": "AUTH-03", "species_key": "y", "severity": "low"}]
+        corr = C.correlate(self.routes, operations, self._executed([]), s1, self.tb,
+                           run_id=self.run_id)
+        row = next(r for r in corr.rows if r.route_name == "organizations.store")
+        self.assertEqual(1, row.finding_count)
+
+    def test_契約外の割当セルを持つ目録は走行を止める(self):
+        operations = dict(self.operations)
+        operations["login.store"] = {"operation": "login", "story": "S1  S4", "kubun": "◎"}
+        with self.assertRaises(C.FatalError):
+            C.correlate(self.routes, operations, self._executed([]), [], self.tb,
+                        run_id=self.run_id)
+
     def test_cross_unexec_findingful(self):
         # 未実行 ∧ finding≥2 の積集合
         findings = [
@@ -547,6 +579,43 @@ class MainTest(unittest.TestCase):
         ])
         self.assertEqual(rc, 0)
 
+    def test_main_contract_violating_story_cell_returns_3(self):
+        """契約外の割当セルを持つ目録で main() が 3 を返し worklist を出さないこと。
+
+        ★ `parse_story_cell()` / `correlate()` が FatalError を投げることだけを見ても、
+          **main() の捕捉と終了コードへの写像**は裏取りできない (catch を壊しても緑になる)。
+        """
+        import contextlib
+        import io
+
+        # 前後空白だけは表ローダが strip するのでここには到達しない
+        # (その形は parse_story_cell() の単体検査が押さえる)。
+        for cell in ("S1  S4", "S1,S4", "S0", "S4 S1", "S1 S1"):
+            with self.subTest(cell=cell):
+                self.ops_path.write_text(
+                    "| method | route | name | story | 区分 |\n"
+                    "|---|---|---|---|---|\n"
+                    f"| POST | register | register.store | {cell} | ◎ |\n",
+                    encoding="utf-8",
+                )
+                out, err = io.StringIO(), io.StringIO()
+                with contextlib.redirect_stdout(out), contextlib.redirect_stderr(err):
+                    rc = C.main(self._args())
+                self.assertEqual(C.EXIT_INPUT_UNAVAILABLE, rc, out.getvalue() + err.getvalue())
+                self.assertIn("契約に反している", err.getvalue())
+                # worklist を 1 行も出さない。
+                self.assertEqual("", out.getvalue())
+
+    def test_main_multi_value_story_cell_is_accepted(self):
+        """正の対照: 契約どおりの複数値セルは 0 で通ること (値域を狭めすぎていない)。"""
+        self.ops_path.write_text(
+            "| method | route | name | story | 区分 |\n"
+            "|---|---|---|---|---|\n"
+            "| POST | register | register.store | S1 S4 | ◎ |\n",
+            encoding="utf-8",
+        )
+        self.assertEqual(C.EXIT_OK, C.main(self._args(["--json"])))
+
     # ------------------------------------------------------------------ #
     # fail-closed 契約: 主入力が揃わない走行は成功にしない (終了コード 3)
     # ------------------------------------------------------------------ #
@@ -698,5 +767,39 @@ class RenderWorklistTest(unittest.TestCase):
         self.assertNotIn("未実行 candidate", out)
 
 
+class StoryCellParseTest(unittest.TestCase):
+    """割当セルの分解 (目録が複数値セルを書けるようになったことへの追従)。
+
+    実在 (そのカードが在るか) は見ない。目録は生成物であり、割当列は実在するカードの
+    前付けからしか作られない (生成器側の検査が担う)。
+    """
+
+    def test_単一値は従来どおり(self):
+        self.assertEqual(["S3"], C.parse_story_cell("S3", "r"))
+
+    def test_複数値は全部に索引される(self):
+        self.assertEqual(["S3", "S7"], C.parse_story_cell("S3 S7", "r"))
+
+    def test_対象外はどのstoryにも索引されない(self):
+        self.assertEqual([], C.parse_story_cell("-", "r"))
+
+    def test_実在しないカードでも通す(self):
+        # 責務外 (生成器側が出さないことを test_bug_hunt_inventory.py が固定する)。
+        self.assertEqual(["S8"], C.parse_story_cell("S8", "r"))
+
+    def test_契約外のセルは致命(self):
+        # **寛容に正規化しない**。str.split() は前後空白も連続空白も黙って吸収する。
+        for cell in (" S3", "S3 ", "S3  S7", "", "SX", "S0", "S03", "s3", "S3,S7", "S3 S7 "):
+            with self.subTest(cell=cell):
+                with self.assertRaises(C.FatalError):
+                    C.parse_story_cell(cell, "r")
+
+    def test_降順と重複は致命(self):
+        for cell in ("S7 S3", "S3 S3"):
+            with self.subTest(cell=cell):
+                with self.assertRaises(C.FatalError):
+                    C.parse_story_cell(cell, "r")
+
+
 if __name__ == "__main__":
     unittest.main()
diff --git a/.claude/skills/app-bug-hunt/inventory/notes-screens.md b/.claude/skills/app-bug-hunt/inventory/notes-screens.md
index 4e7879a6..47911f28 100644
--- a/.claude/skills/app-bug-hunt/inventory/notes-screens.md
+++ b/.claude/skills/app-bug-hunt/inventory/notes-screens.md
@@ -39,7 +39,9 @@ ## 課金ゲート着地 (P4 ゲート反転) の画面遷移
 > §サブスク契約 Checkout とオンボーディング着地)。
 
 - `onboarding.checkout` は**離脱ガード付き**: 契約済み (有効 sub / free personal) は
-  `billing.index` へ、`manageBilling` 非保持者は `onboarding.billing-required` へ逃がす。
+  `manageBilling` 保持者 → `billing.index` / 非保持メンバー → `dashboard` へ寄せる
+  (非保持メンバーに操作できない請求画面を見せず業務入口へ着地させる。Q-2-01)。
+  未契約で `manageBilling` 非保持者は `onboarding.billing-required` へ逃がす。
 - `onboarding.billing-required` も同様に、利用可なら `dashboard`、`manageBilling` 保持者なら
   `onboarding.checkout` へ逃がす。**どちらの画面も「行き先のない詰み」を作らないこと**が契約で、
   ここでループ・403・空画面が出たら finding (H4/H10)。
diff --git a/.claude/skills/app-bug-hunt/stories/story_front_matter.py b/.claude/skills/app-bug-hunt/stories/story_front_matter.py
new file mode 100644
index 00000000..5cc24f82
--- /dev/null
+++ b/.claude/skills/app-bug-hunt/stories/story_front_matter.py
@@ -0,0 +1,241 @@
+#!/usr/bin/env python3
+"""シナリオカードの前付け (制限文法) の読み取り器。
+
+文法の**正本は `README.md`** であり、ここは**従う読み手**である。
+読み取り器を書き換えて文法を広げてはならない (広げるなら README と自己テストを同じ変更で直す)。
+
+**この読み取り器が見るもの** (制限文法 = README §1):
+
+- 前付けの区切り (1 行目が厳密に `---` / 次に現れる「行頭から `---` だけ」の行で閉じる)
+- 1 行 1 項目の `key: value` (半角コロン + 半角空白 1 つ)
+- key の書式・重複・**この文法に無い key**
+- 値の 3 形 (素のスカラー / 真偽値 / 配列) と、key ごとにどの形を取るか
+
+**この読み取り器が見ないもの** (見るのは呼び出し側である):
+
+- 必須 key の全数と正準順序 / 閉じた語彙 / 表 A・表 B との突合 / 本文の確定形
+  … `test_story_front_matter.py` が見る
+- `covers_*` の値の実在 / 欄の意味 / 分母の被覆 … `scripts/bug-hunt-inventory.py` が見る
+
+**例外を投げない** (読み取り不能そのものを除く)。違反は並びで返す。1 件目で止めると
+直すたびに再実行が要るためである。
+
+依存は標準ライブラリのみ (AGENTS.md §bug-hunt)。
+"""
+from __future__ import annotations
+
+import re
+from dataclasses import dataclass
+from pathlib import Path
+
+CANONICAL_KEYS = (
+    "id", "title", "surface", "lane", "priority", "applicability",
+    "not_applicable_reason",
+    "depends_on", "reseed_before", "accounts", "setup",
+    "covers_screens", "covers_operations", "covers_capabilities",
+)
+CONDITIONAL_KEY = "not_applicable_reason"
+REQUIRED_KEYS = tuple(k for k in CANONICAL_KEYS if k != CONDITIONAL_KEY)
+
+SCALAR_KEYS = frozenset({
+    "id", "title", "surface", "lane", "priority", "applicability", CONDITIONAL_KEY,
+})
+BOOL_KEYS = frozenset({"reseed_before"})
+ARRAY_KEYS = frozenset({
+    "depends_on", "accounts", "setup",
+    "covers_screens", "covers_operations", "covers_capabilities",
+})
+
+LANE_VOCABULARY = ("parallel_browser", "serial_parent")
+PRIORITY_VOCABULARY = ("P1", "P2", "P3")
+APPLICABILITY_VOCABULARY = ("applicable", "not_applicable")
+ACCOUNT_VOCABULARY = ("guest", "owner", "admin", "member", "platform_admin")
+
+# 照合はすべて fullmatch() で行う (Python の `$` は**末尾改行の直前にも一致する**ため、
+# match() + `$` は「厳密一致」と同義ではない)。
+CARD_ID_RE = re.compile(r"S[1-9][0-9]*")
+KEY_RE = re.compile(r"[a-z][a-z0-9_]*")
+FILENAME_RE = re.compile(r"S[1-9][0-9]*-.+\.md")
+ROUTE_TOKEN_RE = re.compile(r"[a-z0-9]+([._-][a-z0-9]+)*")
+CAPABILITY_TOKEN_RE = re.compile(r"[A-Z]+-[0-9]{2}")
+SURFACE_TOKEN_RE = re.compile(r"[a-z][a-z0-9_]*")
+
+FRONT_MATTER_DELIMITER = "---"
+BOOLEAN_LITERALS = {"true": True, "false": False}
+ARRAY_SEPARATOR = ", "
+# スカラーと配列要素に許さない文字。2 群ある。
+#
+#   1. 引用符・注釈・区切り・入れ子の記号 (`#` `:` 角括弧 引用符)
+#   2. YAML の構造記号 (`&` アンカー / `*` 参照 / `|` `>` 複数行スカラー /
+#      `{` `}` フローマップ)。**素のスカラーとして黙って受けない**ためにここで落とす。
+#      受けてしまうと「A5 (アンカー・参照・複数行スカラーは書けない) を守っている」と
+#      言えなくなる (値としては読めてしまうため)
+FORBIDDEN_VALUE_CHARS = "#:[]'\"&*|>{}"
+
+# 除外は**閉じたリテラル集合**にする (パターン除外を作らない)。
+EXCLUDED_FILENAMES = frozenset({"README.md"})
+
+
+class StoryReadError(Exception):
+    """カードを読むこと自体が成立しない状態 (置き場が無い / 候補が 0 件 / 読み取り不能)。"""
+
+
+@dataclass(frozen=True)
+class Card:
+    """1 枚のカード。値は制限文法で読めた形のまま持つ。"""
+
+    filename: str
+    text: str
+    front_matter: dict[str, object]
+    keys_in_order: tuple[str, ...]
+    body: str
+
+
+def _scalar_violation(key: str, value: str) -> str | None:
+    if value == "":
+        return f"{key}: スカラーが空である"
+    if value != value.strip():
+        return f"{key}: スカラーの前後に空白がある"
+    for char in FORBIDDEN_VALUE_CHARS:
+        if char in value:
+            return f"{key}: スカラーに使えない文字がある: {char!r}"
+
+    return None
+
+
+def _parse_array(key: str, value: str) -> tuple[list[str], list[str]]:
+    """配列を読む。`[]` か `[a, b, c]` だけを認める (ネスト不可・引用符禁止)。"""
+    if not (value.startswith("[") and value.endswith("]")):
+        return [], [f"{key}: 配列が角括弧で囲まれていない: {value!r}"]
+    inner = value[1:-1]
+    if inner == "":
+        return [], []
+
+    elements = inner.split(ARRAY_SEPARATOR)
+    violations: list[str] = []
+    for element in elements:
+        violation = _scalar_violation(key, element)
+        if violation is not None:
+            violations.append(f"{violation} (要素 {element!r})")
+        elif "," in element:
+            violations.append(f"{key}: 配列の区切りが '{ARRAY_SEPARATOR}' でない: {element!r}")
+
+    return elements, violations
+
+
+def parse_front_matter(
+    text: str,
+) -> tuple[dict[str, object], tuple[str, ...], list[str], str]:
+    """前付けを読み、(値, 出現順の key, 違反, 本文) を返す。**例外を投げない**。"""
+    violations: list[str] = []
+    lines = text.split("\n")
+
+    if not lines or lines[0] != FRONT_MATTER_DELIMITER:
+        violations.append(f"1 行目が {FRONT_MATTER_DELIMITER!r} でない")
+
+        return {}, (), violations, text
+
+    close = None
+    for index in range(1, len(lines)):
+        if lines[index] == FRONT_MATTER_DELIMITER:
+            close = index
+            break
+    if close is None:
+        violations.append(f"前付けが {FRONT_MATTER_DELIMITER!r} で閉じていない")
+
+        return {}, (), violations, text
+
+    values: dict[str, object] = {}
+    order: list[str] = []
+    for line in lines[1:close]:
+        if line == "":
+            violations.append("前付けに空行がある")
+            continue
+        key, separator, rest = line.partition(":")
+        if separator == "":
+            violations.append(f"key: value の形でない: {line!r}")
+            continue
+        if not rest.startswith(" "):
+            violations.append(f"半角コロンの後に半角空白 1 つが要る: {line!r}")
+            continue
+        value = rest[1:]
+        if KEY_RE.fullmatch(key) is None:
+            violations.append(f"key の書式が契約外: {key!r}")
+            continue
+        if key in values:
+            violations.append(f"key が重複している: {key}")
+            continue
+        if key not in CANONICAL_KEYS:
+            violations.append(f"この文法に無い key: {key}")
+            continue
+
+        if key in BOOL_KEYS:
+            if value not in BOOLEAN_LITERALS:
+                violations.append(f"{key}: 真偽値が true / false でない: {value!r}")
+                continue
+            values[key] = BOOLEAN_LITERALS[value]
+        elif key in ARRAY_KEYS:
+            elements, element_violations = _parse_array(key, value)
+            violations += element_violations
+            if element_violations:
+                continue
+            values[key] = elements
+        elif key in SCALAR_KEYS:
+            violation = _scalar_violation(key, value)
+            if violation is not None:
+                violations.append(violation)
+                continue
+            values[key] = value
+        else:
+            # 正準 key に足したのに型集合 (SCALAR/BOOL/ARRAY) への登録を忘れた形。
+            # 黙ってスカラー扱いにせず、内部契約の違反として落とす (fail-closed)。
+            violations.append(f"{key}: どの型集合にも登録されていない key である")
+            continue
+        order.append(key)
+
+    return values, tuple(order), violations, "\n".join(lines[close + 1:])
+
+
+def parse_card(filename: str, text: str) -> tuple[Card, list[str]]:
+    """1 枚分の本文からカードを作る。違反があってもカードは返す (呼び出し側が判断する)。"""
+    values, order, violations, body = parse_front_matter(text)
+
+    return (
+        Card(filename=filename, text=text, front_matter=values, keys_in_order=order, body=body),
+        [f"{filename}: {v}" for v in violations],
+    )
+
+
+def stories_dir() -> Path:
+    return Path(__file__).resolve().parent
+
+
+def read_cards(directory: Path | None = None) -> tuple[list[Card], list[str]]:
+    """候補母集団 (`*.md` から `EXCLUDED_FILENAMES` を引いた全件) を読む。
+
+    **パターンで発見しない**。`S8.md` のような命名違反を「存在しないもの」にしないため、
+    全件走査してから命名契約を検査する (命名の判定は呼び出し側の責務)。
+
+    読むこと自体が成立しない場合 (置き場が無い / 候補が 0 件 / 読み取り不能) は
+    `StoryReadError` を投げる。**違反 0 件と母集団 0 件を混ぜない**ためである。
+    """
+    target = stories_dir() if directory is None else directory
+    if not target.is_dir():
+        raise StoryReadError(f"カードの置き場が無い: {target}")
+
+    candidates = [p for p in sorted(target.glob("*.md")) if p.name not in EXCLUDED_FILENAMES]
+    if not candidates:
+        raise StoryReadError(f"カードの候補が 1 件も無い: {target}")
+
+    cards: list[Card] = []
+    violations: list[str] = []
+    for path in candidates:
+        try:
+            text = path.read_text(encoding="utf-8")
+        except (OSError, UnicodeDecodeError) as exc:
+            raise StoryReadError(f"カードを読めない: {path} ({exc})") from exc
+        card, card_violations = parse_card(path.name, text)
+        cards.append(card)
+        violations += card_violations
+
+    return cards, violations
diff --git a/.claude/skills/app-bug-hunt/stories/test_story_front_matter.py b/.claude/skills/app-bug-hunt/stories/test_story_front_matter.py
new file mode 100644
index 00000000..4c13f19f
--- /dev/null
+++ b/.claude/skills/app-bug-hunt/stories/test_story_front_matter.py
@@ -0,0 +1,1341 @@
+#!/usr/bin/env python3
+"""シナリオカードの書式契約の自己テスト (標準ライブラリのみ)。
+
+    cd .claude/skills/app-bug-hunt/stories && python3 -m unittest test_story_front_matter
+
+`composer test` からは `tests/Architecture/BughuntStoryToolSelfTest.php` が起動する。
+
+**走査対象**: `stories/*.md` から `story_front_matter.EXCLUDED_FILENAMES` を引いた全件と、
+書式の正本 `README.md` のマーカー区間 2 つ (表 A = 許可する対象面の語彙 /
+表 B = カード目録)。判定に使う純関数 (`card_violations` / `graph_violations` /
+`marker_table` / `partition_violations`) は**合成入力にも実データにも同じものを使う**ので、
+負例は実ファイル母集団が 0 件になっても走る。
+
+**保証しないもの**:
+
+- `covers_screens` / `covers_operations` / `covers_capabilities` の値の**実在**は見ない
+  (形だけを見る)。実在・欄の意味・分母の被覆は `scripts/bug-hunt-inventory.py` の責務で、
+  同じ規則を 2 か所に持たない (B16)。
+- `lane` / `depends_on` と `scripts/bug-hunt-shard.sh` の固定 fan-out マップの一致は見ない
+  (固定マップは派生キャッシュ。E5)。
+- 兆候番号 (`H{n}`) の意味がカードに書かれていないことは見ない (G6)。
+- 手順の書式 (ステップ表・step 識別子) は**採っていない**ので検査しない
+  (`docs/template-divergence.md` D40)。
+"""
+from __future__ import annotations
+
+import re
+import unittest
+
+import story_front_matter as sfm
+
+STORIES_DIR = sfm.stories_dir()
+README_PATH = STORIES_DIR / "README.md"
+
+SURFACE_MARKER = "STORY-SURFACE-VOCABULARY"
+INVENTORY_MARKER = "STORY-CARD-INVENTORY"
+SURFACE_TABLE_HEADER = ("surface", "面", "由来")
+INVENTORY_TABLE_HEADER = ("id", "surface")
+
+# 家系必須の対象面。削除・改名は fail (追記は自由)。
+FAMILY_REQUIRED_SURFACES = (
+    "signup_funnel", "invitation", "core_journey", "org_project_admin", "billing",
+    "account_security", "authz_boundary", "result_view", "admin_console",
+    "cli_or_api", "public_share",
+)
+
+# 家系固定: 既存番号の面を付け替えない。
+FAMILY_SURFACE_PIN = (
+    ("S1", "signup_funnel"),
+    ("S2", "invitation"),
+    ("S3", "core_journey"),
+    ("S4", "org_project_admin"),
+    ("S5", "billing"),
+    ("S6", "account_security"),
+    ("S7", "authz_boundary"),
+)
+PINNED_IDS = frozenset(card_id for card_id, _ in FAMILY_SURFACE_PIN)
+
+# 旧メタ節。前付けと散文の二重正本を残さない (H1)。
+LEGACY_META_PATTERNS = (
+    "- 前提状態:",
+    "- 目的:",
+    "## このストーリーで消化する",
+)
+
+PURPOSE_HEADING = "## 目的"
+DEVIATION_HEADING = "## 逸脱アイデア (--deviate 時)"
+STEPS_HEADING = "## 手順"
+
+
+# --------------------------------------------------------------------------- #
+# 判定の純関数 (合成入力にも実データにも同じものを使う)
+# --------------------------------------------------------------------------- #
+def marker_table(
+    text: str, marker: str, header: tuple[str, ...]
+) -> tuple[list[tuple[str, ...]], list[str]]:
+    """マーカー区間から表を抜き、構造契約を検査して (データ行, 違反) を返す。
+
+    契約 (**空行の位置も契約である**):
+
+        <!-- {marker}:BEGIN -->
+        (空行 1 行)
+        | 正準ヘッダ |
+        | 正準区切り行 |     ← 各セルはちょうど `---`
+        | データ行 |         ← 1 行以上。**読み飛ばしを一切しない**
+        (空行 1 行)
+        <!-- {marker}:END -->
+
+    BEGIN / END はそれぞれちょうど 1 個で、**BEGIN が END より前**にあること。
+    表の中に空行を挟まないこと。
+    """
+    violations: list[str] = []
+    begin, end = f"<!-- {marker}:BEGIN -->", f"<!-- {marker}:END -->"
+    if text.count(begin) != 1 or text.count(end) != 1:
+        violations.append(f"{marker}: マーカー区間がちょうど 1 対でない")
+        return [], violations
+    if text.index(begin) > text.index(end):
+        violations.append(f"{marker}: END が BEGIN より前にある")
+        return [], violations
+
+    # BEGIN 行の残り / 空行 / 表 / 空行 / END 行の手前、で 4 つの空要素に挟まれる。
+    raw = text.split(begin, 1)[1].split(end, 1)[0].split("\n")
+    if len(raw) < 5 or raw[0] != "" or raw[1] != "" or raw[-1] != "" or raw[-2] != "":
+        violations.append(f"{marker}: マーカー区間の空行の配置が契約外")
+        return [], violations
+
+    lines = raw[2:-2]
+    if any(line.strip() == "" for line in lines):
+        violations.append(f"{marker}: 表の中に空行がある")
+        return [], violations
+
+    expected_header = "| " + " | ".join(header) + " |"
+    if lines[0] != expected_header:
+        violations.append(f"{marker}: 正準ヘッダでない: {lines[0]!r} (期待 {expected_header!r})")
+        return [], violations
+    if len(lines) < 2:
+        violations.append(f"{marker}: 区切り行が無い")
+        return [], violations
+    expected_separator = "|" + "|".join(["---"] * len(header)) + "|"
+    if lines[1] != expected_separator:
+        violations.append(
+            f"{marker}: 正準区切り行でない: {lines[1]!r} (期待 {expected_separator!r})"
+        )
+        return [], violations
+
+    rows: list[tuple[str, ...]] = []
+    for line in lines[2:]:
+        if not line.startswith("|") or not line.endswith("|"):
+            violations.append(f"{marker}: 区間に表以外の行がある: {line!r}")
+            continue
+        cols = tuple(c.strip() for c in line.strip("|").split("|"))
+        if len(cols) != len(header):
+            violations.append(f"{marker}: データ行の列数が {len(header)} でない: {line!r}")
+            continue
+        rows.append(cols)
+
+    if not rows:
+        violations.append(f"{marker}: データ行が 1 行も無い")
+
+    return rows, violations
+
+
+def unwrap_code(value: str) -> tuple[str, bool]:
+    """1 対のバッククォートを外す。装飾がそれ以外なら第 2 要素が False。"""
+    if len(value) >= 2 and value.startswith("`") and value.endswith("`") and "`" not in value[1:-1]:
+        return value[1:-1], True
+
+    return value, False
+
+
+def surface_vocabulary(text: str) -> tuple[list[str], list[str]]:
+    """表 A を読み、許可する対象面の語彙と違反を返す (C1 / C2 / C3)。"""
+    rows, violations = marker_table(text, SURFACE_MARKER, SURFACE_TABLE_HEADER)
+    surfaces: list[str] = []
+    for cols in rows:
+        token, decorated = unwrap_code(cols[0])
+        if not decorated:
+            violations.append(f"表 A: surface の装飾が 1 対のバッククォートでない: {cols[0]!r}")
+            continue
+        if sfm.SURFACE_TOKEN_RE.fullmatch(token) is None:
+            violations.append(f"表 A: surface が snake_case 1 語でない: {token!r}")
+            continue
+        if token in surfaces:
+            violations.append(f"表 A: surface が重複している: {token}")
+            continue
+        surfaces.append(token)
+
+    for required in FAMILY_REQUIRED_SURFACES:
+        if required not in surfaces:
+            violations.append(f"表 A: 家系必須の対象面が無い: {required}")
+
+    return surfaces, violations
+
+
+def card_inventory(text: str) -> tuple[list[tuple[str, str]], list[str]]:
+    """表 B を読み、(id, surface) の並びと違反を返す (C4 / C5)。"""
+    rows, violations = marker_table(text, INVENTORY_MARKER, INVENTORY_TABLE_HEADER)
+    entries: list[tuple[str, str]] = []
+    seen: set[str] = set()
+    for cols in rows:
+        card_id = cols[0]
+        token, decorated = unwrap_code(cols[1])
+        if sfm.CARD_ID_RE.fullmatch(card_id) is None:
+            violations.append(f"表 B: id の書式が契約外: {card_id!r}")
+            continue
+        if not decorated:
+            violations.append(f"表 B: surface の装飾が 1 対のバッククォートでない: {cols[1]!r}")
+            continue
+        if card_id in seen:
+            violations.append(f"表 B: id が重複している: {card_id}")
+            continue
+        seen.add(card_id)
+        entries.append((card_id, token))
+
+    return entries, violations
+
+
+def section_body(text: str, heading: str) -> str | None:
+    """H2 見出しの直後から次の H2 見出しの直前までを返す。無ければ None。"""
+    lines = text.splitlines()
+    start = None
+    for index, line in enumerate(lines):
+        if line == heading:
+            start = index + 1
+            break
+    if start is None:
+        return None
+    end = len(lines)
+    for index in range(start, len(lines)):
+        if lines[index].startswith("## "):
+            end = index
+            break
+
+    return "\n".join(lines[start:end])
+
+
+def card_violations(card: sfm.Card, surfaces: tuple[str, ...] | list[str]) -> list[str]:
+    """カード 1 枚の契約を検査する (B / F2 / H1 / J 群)。
+
+    ★ 前付けの**文法**違反は `story_front_matter.parse_card()` が既に返しているので、
+      ここでは重ねて見ない。ここが見るのは「読めた前付けの中身」と本文である。
+    """
+    violations: list[str] = []
+    prefix = f"{card.filename}:"
+    values = card.front_matter
+
+    # --- B1: 必須 key の全数と正準順序 (条件付き key は applicability で決まる) ---
+    applicability = values.get("applicability")
+    expected = list(sfm.REQUIRED_KEYS)
+    if applicability == "not_applicable":
+        expected.insert(sfm.CANONICAL_KEYS.index(sfm.CONDITIONAL_KEY), sfm.CONDITIONAL_KEY)
+    if list(card.keys_in_order) != expected:
+        violations.append(f"{prefix} key の全数か正準順序が契約外: {list(card.keys_in_order)}")
+        return violations
+
+    def scalar(key: str) -> str:
+        value = values.get(key)
+
+        return value if isinstance(value, str) else ""
+
+    def array(key: str) -> list[str]:
+        value = values.get(key)
+
+        return [str(v) for v in value] if isinstance(value, list) else []
+
+    # --- B2 / B4〜B7 / B10 / B11: 語彙と書式 ---
+    if sfm.CARD_ID_RE.fullmatch(scalar("id")) is None:
+        violations.append(f"{prefix} id の書式が契約外: {scalar('id')!r}")
+    if scalar("title") == "":
+        violations.append(f"{prefix} title が空である")
+    if scalar("surface") not in surfaces:
+        violations.append(f"{prefix} surface が表 A に無い: {scalar('surface')!r}")
+    if scalar("lane") not in sfm.LANE_VOCABULARY:
+        violations.append(f"{prefix} 未知の lane: {scalar('lane')!r}")
+    if scalar("priority") not in sfm.PRIORITY_VOCABULARY:
+        violations.append(f"{prefix} 未知の priority: {scalar('priority')!r}")
+    if scalar("applicability") not in sfm.APPLICABILITY_VOCABULARY:
+        violations.append(f"{prefix} 未知の applicability: {scalar('applicability')!r}")
+    if not isinstance(values.get("reseed_before"), bool):
+        violations.append(f"{prefix} reseed_before が真偽値でない")
+    for account in array("accounts"):
+        if account not in sfm.ACCOUNT_VOCABULARY:
+            violations.append(f"{prefix} 未知の accounts トークン: {account!r}")
+
+    # --- B8: 条件付き key の値 ---
+    if applicability == "not_applicable" and scalar(sfm.CONDITIONAL_KEY) == "":
+        violations.append(f"{prefix} not_applicable_reason が空である")
+
+    # --- B9 / B12〜B15 + AC-13: 配列の形と重複 ---
+    for key, pattern in (
+        ("depends_on", sfm.CARD_ID_RE),
+        ("covers_screens", sfm.ROUTE_TOKEN_RE),
+        ("covers_operations", sfm.ROUTE_TOKEN_RE),
+        ("covers_capabilities", sfm.CAPABILITY_TOKEN_RE),
+    ):
+        for element in array(key):
+            if pattern.fullmatch(element) is None:
+                violations.append(f"{prefix} {key} の要素の書式が契約外: {element!r}")
+    for key in sfm.ARRAY_KEYS:
+        elements = array(key)
+        duplicates = sorted({e for e in elements if elements.count(e) > 1})
+        if duplicates:
+            violations.append(f"{prefix} {key} に重複した要素がある: {', '.join(duplicates)}")
+    for element in array("setup"):
+        if element.strip() == "":
+            violations.append(f"{prefix} setup に空の要素がある")
+
+    # --- J1: H1 見出しと前付けの機械一致 ---
+    expected_heading = f"# {scalar('id')}: {scalar('title')}"
+    headings = [line for line in card.body.splitlines() if line.startswith("# ")]
+    if headings[:1] != [expected_heading]:
+        violations.append(f"{prefix} H1 見出しが前付けと一致しない (期待 {expected_heading!r})")
+
+    # --- F2: not_applicable のカードは手順を持たない ---
+    has_steps = any(line == STEPS_HEADING for line in card.body.splitlines())
+    if applicability == "not_applicable" and has_steps:
+        violations.append(f"{prefix} not_applicable のカードに {STEPS_HEADING} 節がある")
+
+    # --- H1: 旧メタ節が残っていない ---
+    for line in card.body.splitlines():
+        for pattern in LEGACY_META_PATTERNS:
+            if line.startswith(pattern):
+                violations.append(f"{prefix} 旧メタ節が残っている: {line!r}")
+
+    # --- J2 / J3: 本文の確定形 (ちょうど 1 個 + 中身が空でない) ---
+    for heading in (PURPOSE_HEADING, DEVIATION_HEADING):
+        count = sum(1 for line in card.body.splitlines() if line == heading)
+        if count != 1:
+            violations.append(f"{prefix} {heading} 節がちょうど 1 個でない ({count} 個)")
+            continue
+        body = section_body(card.body, heading)
+        if body is None or body.strip() == "":
+            violations.append(f"{prefix} {heading} 節の中身が空である")
+
+    return violations
+
+
+def graph_violations(cards: list[sfm.Card]) -> list[str]:
+    """カード横断の契約を検査する (D3 / D4 / D5 / E1 / E2 / E3)。"""
+    violations: list[str] = []
+    ids: list[str] = []
+    by_id: dict[str, sfm.Card] = {}
+
+    for card in cards:
+        # --- D5: ファイル名の先頭セグメントだけを機械一致させる ---
+        if sfm.FILENAME_RE.fullmatch(card.filename) is None:
+            violations.append(f"{card.filename}: ファイル名が S{{n}}-{{kebab}}.md でない")
+            continue
+        card_id = str(card.front_matter.get("id", ""))
+        if sfm.CARD_ID_RE.fullmatch(card_id) is None:
+            violations.append(f"{card.filename}: id の書式が契約外で番号規約を判定できない")
+            continue
+        if card.filename.split("-", 1)[0] != card_id:
+            violations.append(f"{card.filename}: ファイル名の先頭セグメントが id ({card_id}) と違う")
+            continue
+        # --- D3: id は一意 ---
+        if card_id in by_id:
+            violations.append(f"{card.filename}: id が重複している: {card_id}")
+            continue
+        ids.append(card_id)
+        by_id[card_id] = card
+
+    # --- D4: 欠番を作らない (S1 から最大番号まで連番) ---
+    if ids:
+        numbers = sorted(int(i[1:]) for i in ids)
+        if numbers != list(range(1, numbers[-1] + 1)):
+            violations.append(f"カード番号に欠番がある: {numbers}")
+
+    # --- E1: depends_on の実在・自己参照・循環 ---
+    for card_id, card in by_id.items():
+        for dependency in card.front_matter.get("depends_on", []) or []:
+            if dependency == card_id:
+                violations.append(f"{card.filename}: depends_on が自己参照している")
+            elif dependency not in by_id:
+                violations.append(f"{card.filename}: depends_on に実在しないカード: {dependency}")
+
+    def reaches_self(start: str) -> bool:
+        """start から depends_on を辿って start 自身へ戻れるか (自己参照を含む)。"""
+        stack, seen = [start], set()
+        while stack:
+            node = stack.pop()
+            for dependency in by_id[node].front_matter.get("depends_on") or []:
+                key = str(dependency)
+                if key == start:
+                    return True
+                if key in by_id and key not in seen:
+                    seen.add(key)
+                    stack.append(key)
+
+        return False
+
+    for card_id, card in by_id.items():
+        if reaches_self(card_id):
+            violations.append(f"{card.filename}: depends_on が循環している")
+
+    # --- E2 / E3 ---
+    for card_id, card in by_id.items():
+        dependencies = [str(d) for d in (card.front_matter.get("depends_on") or [])]
+        if dependencies and card.front_matter.get("reseed_before") is not False:
+            violations.append(f"{card.filename}: depends_on を持つなら reseed_before は false")
+        if card.front_matter.get("lane") == "parallel_browser":
+            for dependency in dependencies:
+                if dependency in by_id and by_id[dependency].front_matter.get("lane") == "serial_parent":
+                    violations.append(
+                        f"{card.filename}: parallel_browser のカードが serial_parent に依存している"
+                    )
+
+    return violations
+
+
+# --------------------------------------------------------------------------- #
+# AC-14: 全数点呼
+# --------------------------------------------------------------------------- #
+# 詳細設計の全数対応表の全 58 項目。**ここが点呼の基準**である。
+ALL_INVARIANTS = (
+    "A1", "A2", "A3", "A4", "A5", "A6",
+    "B1", "B2", "B3", "B4", "B5", "B6", "B7", "B8",
+    "B9", "B10", "B11", "B12", "B13", "B14", "B15", "B16",
+    "C1", "C2", "C3", "C4", "C5",
+    "D1", "D2", "D3", "D4", "D5", "D6", "D7",
+    "E1", "E2", "E3", "E4", "E5",
+    "F1", "F2",
+    "G1", "G2", "G3", "G4", "G5", "G6",
+    "H1",
+    "I1", "I2", "I3", "I4", "I5", "I6", "I7",
+    "J1", "J2", "J3",
+)
+EXPECTED_TOTAL = 58
+
+# --- 分類 (互いに排他。和が ALL_INVARIANTS と一致する) ---
+ADOPTED = (
+    "A1", "A2", "A3", "A4", "A5", "A6",
+    "B1", "B2", "B3", "B4", "B5", "B6", "B7", "B8",
+    "B9", "B10", "B11", "B12", "B13", "B14", "B15", "B16",
+    "C1", "C2", "C3", "C4", "C5",
+    "D1", "D2", "D3", "D4", "D5", "D7",
+    "E1", "E2", "E3", "E4", "E5",
+    "F2",
+    "G6",
+    "H1",
+    "I1", "I2", "I3", "I4", "I6",
+    "J1", "J2", "J3",
+)
+DIFFERENCES = ("I5", "I7")                                  # aicue 固有差 (既存 D20 が説明)
+NOT_ADOPTED = ("D6", "F1", "G1", "G2", "G3", "G4", "G5")    # 新規 D40 が説明
+
+# --- 担い手 (集合同士の重複を許す。B16 のように両側に現れる項目がある) ---
+STORY_SIDE = (
+    "A1", "A2", "A3", "A4", "A5", "A6",
+    "B1", "B2", "B3", "B4", "B5", "B6", "B7", "B8",
+    "B9", "B10", "B11", "B12", "B13", "B14", "B15", "B16",
+    "C1", "C2", "C3", "C4", "C5",
+    "D1", "D2", "D3", "D4", "D5", "D7",
+    "E1", "E2", "E3", "E4",
+    "F2", "H1", "J1", "J2", "J3",
+)
+INVENTORY_SIDE = ("B16", "I1", "I2", "I3", "I4", "I6")
+NON_MECHANICAL = ("E5", "G6")
+
+SUBJECT_TO_TESTS = {
+    "AC-01": (
+        "test_ac_01_accepts_canonical_front_matter",
+        "test_ac_01_accepts_horizontal_rule_in_body",
+        "test_ac_01_rejects_quoted_scalar",
+        "test_ac_01_rejects_duplicate_key",
+        "test_ac_01_rejects_key_out_of_canonical_order",
+        "test_ac_01_rejects_missing_required_key",
+        "test_ac_01_rejects_unknown_key",
+        "test_ac_01_rejects_blank_and_comment_line",
+        "test_ac_01_rejects_missing_delimiter",
+        "test_ac_01_rejects_malformed_key_value_separator",
+        "test_ac_01_rejects_malformed_key_syntax",
+        "test_ac_01_rejects_malformed_array_syntax",
+        "test_ac_01_rejects_yaml_structures",
+        "test_ac_01_rejects_key_outside_type_sets",
+    ),
+    "AC-02": (
+        "test_ac_02_accepts_real_cards_vocabulary",
+        "test_ac_02_rejects_unknown_lane",
+        "test_ac_02_rejects_unknown_priority",
+        "test_ac_02_rejects_unknown_account",
+        "test_ac_02_rejects_zero_padded_id",
+        "test_ac_02_rejects_non_boolean_reseed",
+    ),
+    "AC-03": (
+        "test_ac_03_accepts_real_card_naming",
+        "test_ac_03_rejects_gap_in_card_numbers",
+        "test_ac_03_rejects_duplicate_id",
+        "test_ac_03_rejects_filename_without_id_segment",
+    ),
+    "AC-04": (
+        "test_ac_04_accepts_surface_vocabulary_table",
+        "test_ac_04_rejects_removed_family_surface",
+        "test_ac_04_rejects_wrong_table_header",
+        "test_ac_04_rejects_duplicate_surface_row",
+        "test_ac_04_rejects_prose_line_inside_marker",
+        "test_ac_04_rejects_reversed_markers",
+        "test_ac_04_rejects_blank_line_layout_change",
+        "test_ac_04_rejects_non_canonical_separator_row",
+    ),
+    "AC-05": (
+        "test_ac_05_accepts_inventory_matching_cards",
+        "test_ac_05_rejects_card_missing_from_inventory",
+        "test_ac_05_rejects_inventory_row_without_card",
+        "test_ac_05_rejects_surface_outside_vocabulary",
+        "test_ac_05_rejects_inventory_table_with_extra_column",
+    ),
+    "AC-06": (
+        "test_ac_06_accepts_family_surface_pin",
+        "test_ac_06_rejects_reassigned_family_surface",
+    ),
+    "AC-07": (
+        "test_ac_07_accepts_real_dependencies",
+        "test_ac_07_rejects_dependency_cycle",
+        "test_ac_07_rejects_self_dependency",
+        "test_ac_07_rejects_unknown_dependency",
+    ),
+    "AC-08": (
+        "test_ac_08_accepts_dependency_without_reseed",
+        "test_ac_08_rejects_reseed_with_dependency",
+    ),
+    "AC-09": (
+        "test_ac_09_accepts_serial_depending_on_parallel",
+        "test_ac_09_rejects_parallel_depending_on_serial",
+    ),
+    "AC-10": (
+        "test_ac_10_accepts_not_applicable_card",
+        "test_ac_10_rejects_steps_in_not_applicable_card",
+        "test_ac_10_rejects_reason_on_applicable_card",
+        "test_ac_10_rejects_missing_reason_on_not_applicable_card",
+    ),
+    "AC-11": (
+        "test_ac_11_accepts_matching_heading",
+        "test_ac_11_rejects_heading_mismatch",
+        "test_ac_11_rejects_missing_heading",
+    ),
+    "AC-12": (
+        "test_ac_12_accepts_real_cards_without_legacy_meta",
+        "test_ac_12_rejects_legacy_meta_section",
+        "test_ac_12_rejects_legacy_purpose_bullet",
+    ),
+    "AC-13": (
+        "test_ac_13_accepts_covers_shape",
+        "test_ac_13_rejects_duplicate_array_element",
+        "test_ac_13_rejects_malformed_route_token",
+        "test_ac_13_rejects_malformed_capability_token",
+    ),
+    "AC-14": (
+        "test_ac_14_accepts_complete_partition",
+        "test_ac_14_accepts_explicit_subject_to_test_mapping",
+        "test_ac_14_rejects_missing_invariant",
+        "test_ac_14_rejects_duplicate_classification",
+        "test_ac_14_rejects_adopted_without_bearer",
+        "test_ac_14_rejects_unknown_bearer_id",
+        "test_ac_14_rejects_wrong_total",
+    ),
+    "AC-15": (
+        "test_ac_15_accepts_canonical_body",
+        "test_ac_15_rejects_missing_purpose_section",
+        "test_ac_15_rejects_duplicate_purpose_section",
+        "test_ac_15_rejects_empty_purpose_section",
+        "test_ac_15_rejects_missing_deviation_section",
+        "test_ac_15_rejects_duplicate_deviation_section",
+        "test_ac_15_rejects_empty_deviation_section",
+    ),
+}
+
+INVARIANT_TO_SUBJECT = {
+    "A1": "AC-01", "A2": "AC-01", "A3": "AC-01", "A4": "AC-01", "A5": "AC-01", "A6": "AC-01",
+    "B1": "AC-01",
+    "B2": "AC-02", "B5": "AC-02", "B6": "AC-02", "B7": "AC-02", "B10": "AC-02",
+    "B11": "AC-02", "B12": "AC-02",
+    "B3": "AC-11",
+    "B4": "AC-05",
+    "B8": "AC-10",
+    "B9": "AC-07",
+    "B13": "AC-13", "B14": "AC-13", "B15": "AC-13", "B16": "AC-13",
+    "C1": "AC-04", "C2": "AC-04", "C3": "AC-04",
+    "C4": "AC-05", "C5": "AC-05",
+    "D1": "AC-06", "D2": "AC-06",
+    "D3": "AC-03", "D4": "AC-03", "D5": "AC-03",
+    "D7": "AC-05",
+    "E1": "AC-07", "E2": "AC-08", "E3": "AC-09", "E4": "AC-05",
+    "F2": "AC-10",
+    "H1": "AC-12",
+    "J1": "AC-11", "J2": "AC-15", "J3": "AC-15",
+}
+
+
+def partition_violations(
+    all_invariants: tuple[str, ...],
+    adopted: tuple[str, ...],
+    differences: tuple[str, ...],
+    not_adopted: tuple[str, ...],
+    bearers: tuple[str, ...],
+    expected_total: int,
+) -> list[str]:
+    """分類と担い手の整合を見て違反の並びを返す (実データにも合成入力にも使う純関数)。"""
+    violations: list[str] = []
+    if len(all_invariants) != expected_total:
+        violations.append(f"全数が {expected_total} 件でない: {len(all_invariants)}")
+    if len(all_invariants) != len(set(all_invariants)):
+        violations.append("全数の一覧に重複がある")
+
+    classified = [*adopted, *differences, *not_adopted]
+    if len(classified) != len(set(classified)):
+        violations.append("分類が重複している")
+    if set(classified) != set(all_invariants):
+        missing = sorted(set(all_invariants) - set(classified))
+        extra = sorted(set(classified) - set(all_invariants))
+        violations.append(f"分類の和が全数と一致しない (不足 {missing} / 余分 {extra})")
+
+    for key in adopted:
+        if key not in bearers:
+            violations.append(f"担い手の無い採用項目: {key}")
+    for key in sorted(set(bearers) - set(all_invariants)):
+        violations.append(f"担い手集合に未知の ID: {key}")
+
+    return violations
+
+
+# --------------------------------------------------------------------------- #
+# 合成入力 (実ファイル母集団が 0 件になりうる違反分岐を必ず走らせる)
+# --------------------------------------------------------------------------- #
+BASE_VALUES: dict[str, object] = {
+    "id": "S1",
+    "title": "見本カード",
+    "surface": "signup_funnel",
+    "lane": "parallel_browser",
+    "priority": "P1",
+    "applicability": "applicable",
+    "depends_on": [],
+    "reseed_before": True,
+    "accounts": ["guest"],
+    "setup": [],
+    "covers_screens": ["home"],
+    "covers_operations": ["login.store"],
+    "covers_capabilities": ["AUTH-01"],
+}
+BASE_BODY = (
+    "# S1: 見本カード\n"
+    "\n"
+    "## 目的\n"
+    "見本のカードである。\n"
+    "\n"
+    "## 手順\n"
+    "1. 開く → 見える\n"
+    "\n"
+    "## 逸脱アイデア (--deviate 時)\n"
+    "- 二重送信してみる\n"
+)
+BASE_SURFACES = list(FAMILY_REQUIRED_SURFACES)
+
+
+def render_value(value: object) -> str:
+    if isinstance(value, bool):
+        return "true" if value else "false"
+    if isinstance(value, list):
+        return "[" + ", ".join(str(v) for v in value) + "]"
+
+    return str(value)
+
+
+def render_front_matter(values: dict[str, object], order: list[str] | None = None) -> str:
+    keys = order if order is not None else [k for k in sfm.CANONICAL_KEYS if k in values]
+
+    return "---\n" + "".join(f"{k}: {render_value(values[k])}\n" for k in keys) + "---\n"
+
+
+def build_card(
+    *,
+    values: dict[str, object] | None = None,
+    order: list[str] | None = None,
+    body: str | None = None,
+    filename: str = "S1-sample.md",
+    raw: str | None = None,
+) -> tuple[sfm.Card, list[str]]:
+    text = raw if raw is not None else render_front_matter(
+        dict(BASE_VALUES) if values is None else values, order
+    ) + "\n" + (BASE_BODY if body is None else body)
+
+    return sfm.parse_card(filename, text)
+
+
+def synthetic_violations(**kwargs: object) -> list[str]:
+    """合成カード 1 枚の文法違反と中身の違反を合わせて返す。"""
+    card, parse = build_card(**kwargs)  # type: ignore[arg-type]
+
+    return parse + card_violations(card, BASE_SURFACES)
+
+
+# --------------------------------------------------------------------------- #
+# 実データ (母集団)
+# --------------------------------------------------------------------------- #
+class StoryFrontMatterContractTest(unittest.TestCase):
+    """カードの書式契約。実データと合成入力の両方を同じ純関数で判定する。"""
+
+    @classmethod
+    def setUpClass(cls) -> None:
+        cls.readme = README_PATH.read_text(encoding="utf-8")
+        cls.cards, cls.parse_violations = sfm.read_cards(STORIES_DIR)
+        cls.surfaces, cls.surface_violations = surface_vocabulary(cls.readme)
+        cls.inventory, cls.inventory_violations = card_inventory(cls.readme)
+
+    # ----------------------------------------------------------------- #
+    # 母集団の非空 (走査が空振りしていないこと)
+    # ----------------------------------------------------------------- #
+    def test_population_is_not_empty(self) -> None:
+        """カード母集団と表 A / 表 B のデータ行がいずれも空でないこと。"""
+        self.assertNotEqual([], self.cards, "カード母集団が 0 件 (走査根が壊れている)")
+        self.assertNotEqual([], self.surfaces)
+        self.assertNotEqual([], self.inventory)
+
+    def test_real_cards_parse_without_violations(self) -> None:
+        """実カードの前付けが制限文法で読めること。"""
+        self.assertEqual([], self.parse_violations)
+
+    def test_real_cards_have_no_content_violations(self) -> None:
+        """実カードの中身が契約に反していないこと。"""
+        violations: list[str] = []
+        for card in self.cards:
+            violations += card_violations(card, self.surfaces)
+        self.assertEqual([], violations)
+
+    def test_real_cards_have_no_graph_violations(self) -> None:
+        """番号規約と依存の契約に反していないこと。"""
+        self.assertEqual([], graph_violations(self.cards))
+
+    # ----------------------------------------------------------------- #
+    # AC-01: 制限文法 + 必須 key 全数 + 正準順序 + 重複なし
+    # ----------------------------------------------------------------- #
+    def test_ac_01_accepts_canonical_front_matter(self) -> None:
+        self.assertEqual([], synthetic_violations())
+
+    def test_ac_01_accepts_horizontal_rule_in_body(self) -> None:
+        """本文中の水平線で前付けが閉じたことにならないこと (A1)。"""
+        body = BASE_BODY.replace("## 手順\n", "## 手順\n---\n")
+        card, parse = build_card(body=body)
+        self.assertEqual([], parse)
+        self.assertEqual("S1", card.front_matter["id"])
+
+    def test_ac_01_rejects_quoted_scalar(self) -> None:
+        values = dict(BASE_VALUES, title='"見本カード"')
+        self.assertNotEqual([], synthetic_violations(values=values))
+
+    def test_ac_01_rejects_duplicate_key(self) -> None:
+        raw = render_front_matter(dict(BASE_VALUES)).replace(
+            "id: S1\n", "id: S1\nid: S2\n"
+        ) + "\n" + BASE_BODY
+        self.assertNotEqual([], synthetic_violations(raw=raw))
+
+    def test_ac_01_rejects_key_out_of_canonical_order(self) -> None:
+        order = [k for k in sfm.CANONICAL_KEYS if k in BASE_VALUES]
+        order[0], order[1] = order[1], order[0]
+        self.assertNotEqual([], synthetic_violations(order=order))
+
+    def test_ac_01_rejects_missing_required_key(self) -> None:
+        values = {k: v for k, v in BASE_VALUES.items() if k != "priority"}
+        self.assertNotEqual([], synthetic_violations(values=values))
+
+    def test_ac_01_rejects_unknown_key(self) -> None:
+        raw = render_front_matter(dict(BASE_VALUES)).replace(
+            "---\nid: S1\n", "---\nid: S1\nowner: kento\n"
+        ) + "\n" + BASE_BODY
+        self.assertNotEqual([], synthetic_violations(raw=raw))
+
+    def test_ac_01_rejects_blank_and_comment_line(self) -> None:
+        for injected in ("\n", "# コメント\n"):
+            with self.subTest(injected=injected):
+                raw = render_front_matter(dict(BASE_VALUES)).replace(
+                    "id: S1\n", "id: S1\n" + injected
+                ) + "\n" + BASE_BODY
+                self.assertNotEqual([], synthetic_violations(raw=raw))
+
+    def test_ac_01_rejects_missing_delimiter(self) -> None:
+        for raw in (
+            # 1 行目が `---` でない
+            render_front_matter(dict(BASE_VALUES))[4:] + "\n" + BASE_BODY,
+            # 閉じる `---` が無い
+            render_front_matter(dict(BASE_VALUES))[:-4] + "\n" + BASE_BODY,
+        ):
+            with self.subTest(raw=raw[:20]):
+                self.assertNotEqual([], synthetic_violations(raw=raw))
+
+    def test_ac_01_rejects_malformed_key_value_separator(self) -> None:
+        """`key: value` (半角コロン + 半角空白 1 つ) 以外を認めないこと (A2)。"""
+        for broken in ("id:S1", "id:  S1", "id : S1", "id S1"):
+            with self.subTest(broken=broken):
+                raw = render_front_matter(dict(BASE_VALUES)).replace(
+                    "id: S1", broken, 1
+                ) + "\n" + BASE_BODY
+                self.assertNotEqual([], synthetic_violations(raw=raw))
+
+    def test_ac_01_rejects_malformed_key_syntax(self) -> None:
+        """key が `^[a-z][a-z0-9_]*$` でないこと (A3)。"""
+        for broken in ("Id: S1", "1id: S1", "id-x: S1", "-: S1"):
+            with self.subTest(broken=broken):
+                raw = render_front_matter(dict(BASE_VALUES)).replace(
+                    "id: S1", broken, 1
+                ) + "\n" + BASE_BODY
+                self.assertNotEqual([], synthetic_violations(raw=raw))
+
+    def test_ac_01_rejects_malformed_array_syntax(self) -> None:
+        """配列は `[]` か `[a, b]` だけで、区切りの揺れとネストを認めないこと (A4)。"""
+        for broken in (
+            "accounts: [guest,owner]",      # 区切りに空白が無い
+            "accounts: [guest ,owner]",     # 要素の後ろに空白
+            "accounts: [ guest]",           # 要素の前に空白
+            "accounts: [[guest]]",          # ネスト
+            "accounts: guest",              # 角括弧が無い
+            "accounts: [guest",             # 閉じていない
+        ):
+            with self.subTest(broken=broken):
+                raw = render_front_matter(dict(BASE_VALUES)).replace(
+                    "accounts: [guest]", broken, 1
+                ) + "\n" + BASE_BODY
+                self.assertNotEqual([], synthetic_violations(raw=raw))
+
+    def test_ac_01_rejects_yaml_structures(self) -> None:
+        """複数行スカラー・アンカー・参照・フローマップ・ネストマップを認めないこと (A5)。
+
+        ★ これらを「素のスカラーとして黙って受ける」と、A5 を守っているとは言えなくなる
+          (値としては読めてしまうため)。読み取り器が構造記号を値から締め出すことで閉じる。
+        """
+        for broken in (
+            "title: |",                     # 複数行スカラー (リテラル)
+            "title: >",                     # 複数行スカラー (畳み込み)
+            "title: &anchor 見本カード",     # アンカー
+            "title: *anchor",               # 参照
+            "title: {a: b}",                # フローマップ
+        ):
+            with self.subTest(broken=broken):
+                raw = render_front_matter(dict(BASE_VALUES)).replace(
+                    "title: 見本カード", broken, 1
+                ) + "\n" + BASE_BODY
+                self.assertNotEqual([], synthetic_violations(raw=raw))
+
+        # ネストマップ (字下げした続き行) は key の書式で落ちる。
+        raw = render_front_matter(dict(BASE_VALUES)).replace(
+            "title: 見本カード", "title: 見本カード\n  nested: value", 1
+        ) + "\n" + BASE_BODY
+        self.assertNotEqual([], synthetic_violations(raw=raw))
+
+    def test_ac_01_rejects_key_outside_type_sets(self) -> None:
+        """正準 key なのに型集合へ登録し忘れた形を黙ってスカラーにしないこと (fail-closed)。"""
+        violations = sfm.parse_front_matter("---\nghost: x\n---\n")[2]
+        self.assertNotEqual([], violations)
+        original = sfm.CANONICAL_KEYS
+        sfm.CANONICAL_KEYS = (*original, "ghost")
+        try:
+            self.assertNotEqual([], sfm.parse_front_matter("---\nghost: x\n---\n")[2])
+        finally:
+            sfm.CANONICAL_KEYS = original
+
+    # ----------------------------------------------------------------- #
+    # AC-02: 閉じた語彙と値の書式
+    # ----------------------------------------------------------------- #
+    def test_ac_02_accepts_real_cards_vocabulary(self) -> None:
+        for card in self.cards:
+            with self.subTest(card=card.filename):
+                self.assertIn(card.front_matter["lane"], sfm.LANE_VOCABULARY)
+                self.assertIn(card.front_matter["priority"], sfm.PRIORITY_VOCABULARY)
+                self.assertIn(card.front_matter["applicability"], sfm.APPLICABILITY_VOCABULARY)
+
+    def test_ac_02_rejects_unknown_lane(self) -> None:
+        self.assertNotEqual([], synthetic_violations(values=dict(BASE_VALUES, lane=("serial"))))
+
+    def test_ac_02_rejects_unknown_priority(self) -> None:
+        self.assertNotEqual([], synthetic_violations(values=dict(BASE_VALUES, priority="P0")))
+
+    def test_ac_02_rejects_unknown_account(self) -> None:
+        values = dict(BASE_VALUES, accounts=["photographer"])
+        self.assertNotEqual([], synthetic_violations(values=values))
+
+    def test_ac_02_rejects_zero_padded_id(self) -> None:
+        values = dict(BASE_VALUES, id="S01")
+        body = BASE_BODY.replace("# S1: ", "# S01: ")
+        self.assertNotEqual([], synthetic_violations(values=values, body=body))
+
+    def test_ac_02_rejects_non_boolean_reseed(self) -> None:
+        raw = render_front_matter(dict(BASE_VALUES)).replace(
+            "reseed_before: true", "reseed_before: yes"
+        ) + "\n" + BASE_BODY
+        self.assertNotEqual([], synthetic_violations(raw=raw))
+
+    # ----------------------------------------------------------------- #
+    # AC-03: 命名・id の一意性・欠番
+    # ----------------------------------------------------------------- #
+    def test_ac_03_accepts_real_card_naming(self) -> None:
+        self.assertEqual([], graph_violations(self.cards))
+
+    def test_ac_03_rejects_gap_in_card_numbers(self) -> None:
+        first, _ = build_card(filename="S1-a.md")
+        third, _ = build_card(
+            values=dict(BASE_VALUES, id="S3"),
+            body=BASE_BODY.replace("# S1: ", "# S3: "),
+            filename="S3-c.md",
+        )
+        self.assertNotEqual([], graph_violations([first, third]))
+
+    def test_ac_03_rejects_duplicate_id(self) -> None:
+        first, _ = build_card(filename="S1-a.md")
+        clone, _ = build_card(filename="S1-b.md")
+        self.assertNotEqual([], graph_violations([first, clone]))
+
+    def test_ac_03_rejects_filename_without_id_segment(self) -> None:
+        card, _ = build_card(filename="story-one.md")
+        self.assertNotEqual([], graph_violations([card]))
+
+    # ----------------------------------------------------------------- #
+    # AC-04: 表 A の構造契約と家系必須 11 語
+    # ----------------------------------------------------------------- #
+    def test_ac_04_accepts_surface_vocabulary_table(self) -> None:
+        self.assertEqual([], self.surface_violations)
+        for required in FAMILY_REQUIRED_SURFACES:
+            self.assertIn(required, self.surfaces)
+
+    def test_ac_04_rejects_removed_family_surface(self) -> None:
+        broken = self.readme.replace("| `public_share` |", "| `shared_link` |")
+        _, violations = surface_vocabulary(broken)
+        self.assertNotEqual([], violations)
+
+    def test_ac_04_rejects_wrong_table_header(self) -> None:
+        broken = self.readme.replace("| surface | 面 | 由来 |", "| surface | 面 |")
+        _, violations = surface_vocabulary(broken)
+        self.assertNotEqual([], violations)
+
+    def test_ac_04_rejects_duplicate_surface_row(self) -> None:
+        broken = self.readme.replace(
+            "| `billing` | 課金 | テンプレート同梱 |",
+            "| `billing` | 課金 | テンプレート同梱 |\n| `billing` | 課金 (写し) | テンプレート同梱 |",
+        )
+        _, violations = surface_vocabulary(broken)
+        self.assertNotEqual([], violations)
+
+    def test_ac_04_rejects_reversed_markers(self) -> None:
+        """END が BEGIN より前にある区間を通さないこと。"""
+        broken = self.readme.replace(
+            f"<!-- {SURFACE_MARKER}:BEGIN -->", "@@BEGIN@@", 1
+        ).replace(
+            f"<!-- {SURFACE_MARKER}:END -->", f"<!-- {SURFACE_MARKER}:BEGIN -->", 1
+        ).replace("@@BEGIN@@", f"<!-- {SURFACE_MARKER}:END -->", 1)
+        _, violations = surface_vocabulary(broken)
+        self.assertNotEqual([], violations)
+
+    def test_ac_04_rejects_blank_line_layout_change(self) -> None:
+        """空行の配置も契約であること (区間直後の空行を削る / 表の中に空行を挟む)。"""
+        for broken in (
+            self.readme.replace(
+                f"<!-- {SURFACE_MARKER}:BEGIN -->\n\n| surface",
+                f"<!-- {SURFACE_MARKER}:BEGIN -->\n| surface",
+                1,
+            ),
+            self.readme.replace(
+                "| `billing` | 課金 | テンプレート同梱 |",
+                "| `billing` | 課金 | テンプレート同梱 |\n",
+                1,
+            ),
+        ):
+            with self.subTest(broken=broken[:0]):
+                _, violations = surface_vocabulary(broken)
+                self.assertNotEqual([], violations)
+
+    def test_ac_04_rejects_non_canonical_separator_row(self) -> None:
+        """区切り行は各セルがちょうど `---` であること。"""
+        broken = self.readme.replace(
+            f"<!-- {SURFACE_MARKER}:BEGIN -->\n\n| surface | 面 | 由来 |\n|---|---|---|",
+            f"<!-- {SURFACE_MARKER}:BEGIN -->\n\n| surface | 面 | 由来 |\n|-|-|-|",
+            1,
+        )
+        _, violations = surface_vocabulary(broken)
+        self.assertNotEqual([], violations)
+
+    def test_ac_04_rejects_prose_line_inside_marker(self) -> None:
+        """区間の中の非表行を読み飛ばさないこと (読み飛ばしを一切しない)。"""
+        broken = self.readme.replace(
+            "| `billing` | 課金 | テンプレート同梱 |",
+            "| `billing` | 課金 | テンプレート同梱 |\nこの語彙はあとで整理する。",
+        )
+        _, violations = surface_vocabulary(broken)
+        self.assertNotEqual([], violations)
+
+    # ----------------------------------------------------------------- #
+    # AC-05: surface の所属と表 B とカードの 1 対 1
+    # ----------------------------------------------------------------- #
+    def inventory_mismatch(self, inventory: list[tuple[str, str]], cards: list[sfm.Card]) -> list[str]:
+        """表 B と実在カードの 1 対 1 を判定する (C5 / D7)。"""
+        violations: list[str] = []
+        declared = dict(inventory)
+        actual = {
+            str(c.front_matter.get("id")): str(c.front_matter.get("surface")) for c in cards
+        }
+        for card_id in sorted(set(actual) - set(declared)):
+            violations.append(f"表 B に載っていないカード: {card_id}")
+        for card_id in sorted(set(declared) - set(actual)):
+            violations.append(f"表 B の行に対応するカードが無い: {card_id}")
+        for card_id in sorted(set(declared) & set(actual)):
+            if declared[card_id] != actual[card_id]:
+                violations.append(f"表 B とカードの surface が違う: {card_id}")
+
+        return violations
+
+    def test_ac_05_accepts_inventory_matching_cards(self) -> None:
+        self.assertEqual([], self.inventory_violations)
+        self.assertEqual([], self.inventory_mismatch(self.inventory, self.cards))
+        for card in self.cards:
+            self.assertIn(card.front_matter["surface"], self.surfaces)
+
+    def test_ac_05_rejects_card_missing_from_inventory(self) -> None:
+        extra, _ = build_card(
+            values=dict(BASE_VALUES, id="S8", surface="result_view"),
+            body=BASE_BODY.replace("# S1: ", "# S8: "),
+            filename="S8-result.md",
+        )
+        self.assertNotEqual([], self.inventory_mismatch(self.inventory, [*self.cards, extra]))
+
+    def test_ac_05_rejects_inventory_row_without_card(self) -> None:
+        broken = self.readme.replace(
+            "| S7 | `authz_boundary` |",
+            "| S7 | `authz_boundary` |\n| S8 | `result_view` |",
+        )
+        inventory, violations = card_inventory(broken)
+        self.assertEqual([], violations)
+        self.assertNotEqual([], self.inventory_mismatch(inventory, self.cards))
+
+    def test_ac_05_rejects_surface_outside_vocabulary(self) -> None:
+        values = dict(BASE_VALUES, surface="not_registered")
+        self.assertNotEqual([], synthetic_violations(values=values))
+
+    def test_ac_05_rejects_inventory_table_with_extra_column(self) -> None:
+        """表 B に lane / priority / depends_on の写しを置けないこと (C4 / E4)。"""
+        broken = self.readme.replace("| id | surface |\n|---|---|", "| id | surface | lane |\n|---|---|---|")
+        _, violations = card_inventory(broken)
+        self.assertNotEqual([], violations)
+
+    # ----------------------------------------------------------------- #
+    # AC-06: 家系固定 (id, surface)
+    # ----------------------------------------------------------------- #
+    def family_pin_actual(self, cards: list[sfm.Card]) -> tuple[tuple[str, str], ...]:
+        return tuple(sorted(
+            (str(card.front_matter["id"]), str(card.front_matter["surface"]))
+            for card in cards
+            if str(card.front_matter.get("id")) in PINNED_IDS
+        ))
+
+    def test_ac_06_accepts_family_surface_pin(self) -> None:
+        """S1 から S7 の (id, surface) を家系で固定する。
+
+        番号は識別子であって意味を持たないが、**既存番号の面を付け替えない**ことが
+        家系固定の本体である (D1 / D2)。検査側のリテラルと完全一致で突き合わせる。
+
+        ★ pin の対象は PINNED_IDS に属するカードだけである。S8 以降を正規の手続き
+          (表 A に面を足し、表 B に 1 行、カードを 1 枚) で足しても落ちない。
+        """
+        self.assertEqual(tuple(sorted(FAMILY_SURFACE_PIN)), self.family_pin_actual(self.cards))
+
+    def test_ac_06_rejects_reassigned_family_surface(self) -> None:
+        # ★ **実カード 7 枚のうち S1 の面だけを差し替える**。カードを減らした集合で比べると
+        #   「6 枚足りない」で落ちてしまい、面の付け替えを検出したことにならない
+        #   (共通規約 (c): 正しい理由で落ちること)。
+        others = [c for c in self.cards if str(c.front_matter.get("id")) != "S1"]
+        self.assertEqual(6, len(others))
+        pin = tuple(sorted(FAMILY_SURFACE_PIN))
+
+        reassigned, _ = build_card(values=dict(BASE_VALUES, id="S1", surface="billing"))
+        self.assertNotEqual(pin, self.family_pin_actual([*others, reassigned]))
+
+        # 正の対照: 面を正しい値へ戻すと一致する (落ちた理由が面の付け替えであることの裏取り)。
+        restored, _ = build_card(values=dict(BASE_VALUES, id="S1", surface="signup_funnel"))
+        self.assertEqual(pin, self.family_pin_actual([*others, restored]))
+
+    # ----------------------------------------------------------------- #
+    # AC-07 / AC-08 / AC-09: 依存と実行方式
+    # ----------------------------------------------------------------- #
+    def two_cards(self, first: dict[str, object], second: dict[str, object]) -> list[sfm.Card]:
+        a, _ = build_card(
+            values=first, body=BASE_BODY.replace("# S1: ", f"# {first['id']}: "),
+            filename=f"{first['id']}-a.md",
+        )
+        b, _ = build_card(
+            values=second, body=BASE_BODY.replace("# S1: ", f"# {second['id']}: "),
+            filename=f"{second['id']}-b.md",
+        )
+
+        return [a, b]
+
+    def test_ac_07_accepts_real_dependencies(self) -> None:
+        self.assertEqual([], graph_violations(self.cards))
+
+    def test_ac_07_rejects_dependency_cycle(self) -> None:
+        cards = self.two_cards(
+            dict(BASE_VALUES, id="S1", depends_on=["S2"], reseed_before=False),
+            dict(BASE_VALUES, id="S2", depends_on=["S1"], reseed_before=False),
+        )
+        self.assertNotEqual([], graph_violations(cards))
+
+    def test_ac_07_rejects_self_dependency(self) -> None:
+        card, _ = build_card(values=dict(BASE_VALUES, depends_on=["S1"], reseed_before=False))
+        self.assertNotEqual([], graph_violations([card]))
+
+    def test_ac_07_rejects_unknown_dependency(self) -> None:
+        card, _ = build_card(values=dict(BASE_VALUES, depends_on=["S9"], reseed_before=False))
+        self.assertNotEqual([], graph_violations([card]))
+
+    def test_ac_08_accepts_dependency_without_reseed(self) -> None:
+        cards = self.two_cards(
+            dict(BASE_VALUES, id="S1"),
+            dict(BASE_VALUES, id="S2", depends_on=["S1"], reseed_before=False),
+        )
+        self.assertEqual([], graph_violations(cards))
+
+    def test_ac_08_rejects_reseed_with_dependency(self) -> None:
+        cards = self.two_cards(
+            dict(BASE_VALUES, id="S1"),
+            dict(BASE_VALUES, id="S2", depends_on=["S1"], reseed_before=True),
+        )
+        self.assertNotEqual([], graph_violations(cards))
+
+    def test_ac_09_accepts_serial_depending_on_parallel(self) -> None:
+        cards = self.two_cards(
+            dict(BASE_VALUES, id="S1", lane="parallel_browser"),
+            dict(BASE_VALUES, id="S2", lane="serial_parent", depends_on=["S1"], reseed_before=False),
+        )
+        self.assertEqual([], graph_violations(cards))
+
+    def test_ac_09_rejects_parallel_depending_on_serial(self) -> None:
+        cards = self.two_cards(
+            dict(BASE_VALUES, id="S1", lane="serial_parent"),
+            dict(BASE_VALUES, id="S2", lane="parallel_browser", depends_on=["S1"], reseed_before=False),
+        )
+        self.assertNotEqual([], graph_violations(cards))
+
+    # ----------------------------------------------------------------- #
+    # AC-10: not_applicable カードの中身
+    # ----------------------------------------------------------------- #
+    NOT_APPLICABLE_VALUES = {
+        "id": "S1",
+        "title": "見本カード",
+        "surface": "signup_funnel",
+        "lane": "parallel_browser",
+        "priority": "P3",
+        "applicability": "not_applicable",
+        "not_applicable_reason": "本アプリに該当する面が無いため実走しない",
+        "depends_on": [],
+        "reseed_before": False,
+        "accounts": [],
+        "setup": [],
+        "covers_screens": [],
+        "covers_operations": [],
+        "covers_capabilities": [],
+    }
+    NOT_APPLICABLE_BODY = (
+        "# S1: 見本カード\n"
+        "\n"
+        "## 目的\n"
+        "該当面が無いことを記録として残す。\n"
+        "\n"
+        "## 逸脱アイデア (--deviate 時)\n"
+        "- 該当面が生えていないか確認する\n"
+    )
+
+    def test_ac_10_accepts_not_applicable_card(self) -> None:
+        self.assertEqual([], synthetic_violations(
+            values=dict(self.NOT_APPLICABLE_VALUES), body=self.NOT_APPLICABLE_BODY,
+        ))
+
+    def test_ac_10_rejects_steps_in_not_applicable_card(self) -> None:
+        body = self.NOT_APPLICABLE_BODY.replace(
+            "## 逸脱アイデア", "## 手順\n1. 開く\n\n## 逸脱アイデア"
+        )
+        self.assertNotEqual([], synthetic_violations(
+            values=dict(self.NOT_APPLICABLE_VALUES), body=body,
+        ))
+
+    def test_ac_10_rejects_reason_on_applicable_card(self) -> None:
+        values = dict(self.NOT_APPLICABLE_VALUES, applicability="applicable")
+        self.assertNotEqual([], synthetic_violations(
+            values=values, body=self.NOT_APPLICABLE_BODY,
+        ))
+
+    def test_ac_10_rejects_missing_reason_on_not_applicable_card(self) -> None:
+        values = {
+            k: v for k, v in self.NOT_APPLICABLE_VALUES.items() if k != sfm.CONDITIONAL_KEY
+        }
+        self.assertNotEqual([], synthetic_violations(
+            values=values, body=self.NOT_APPLICABLE_BODY,
+        ))
+
+    # ----------------------------------------------------------------- #
+    # AC-11: H1 見出しと前付けの機械一致
+    # ----------------------------------------------------------------- #
+    def test_ac_11_accepts_matching_heading(self) -> None:
+        self.assertEqual([], synthetic_violations())
+
+    def test_ac_11_rejects_heading_mismatch(self) -> None:
+        body = BASE_BODY.replace("# S1: 見本カード", "# S1: 別のタイトル")
+        self.assertNotEqual([], synthetic_violations(body=body))
+
+    def test_ac_11_rejects_missing_heading(self) -> None:
+        body = BASE_BODY.replace("# S1: 見本カード\n\n", "")
+        self.assertNotEqual([], synthetic_violations(body=body))
+
+    # ----------------------------------------------------------------- #
+    # AC-12: 旧メタ節が残っていない
+    # ----------------------------------------------------------------- #
+    def test_ac_12_accepts_real_cards_without_legacy_meta(self) -> None:
+        for card in self.cards:
+            with self.subTest(card=card.filename):
+                for pattern in LEGACY_META_PATTERNS:
+                    for line in card.body.splitlines():
+                        self.assertFalse(line.startswith(pattern), line)
+
+    def test_ac_12_rejects_legacy_meta_section(self) -> None:
+        body = BASE_BODY + "\n## このストーリーで消化する screens / operations\n- screens: home\n"
+        self.assertNotEqual([], synthetic_violations(body=body))
+
+    def test_ac_12_rejects_legacy_purpose_bullet(self) -> None:
+        for legacy in ("- 前提状態: ゲスト\n", "- 目的: 何かする\n"):
+            with self.subTest(legacy=legacy):
+                body = BASE_BODY.replace("## 目的\n", "## 目的\n" + legacy)
+                self.assertNotEqual([], synthetic_violations(body=body))
+
+    # ----------------------------------------------------------------- #
+    # AC-13: covers_* は形だけを見る (実在は目録側)
+    # ----------------------------------------------------------------- #
+    def test_ac_13_accepts_covers_shape(self) -> None:
+        """実在しない route 名でも**形が正しければ**ここでは通ること (B16)。"""
+        values = dict(BASE_VALUES, covers_screens=["not.a.real.route"])
+        self.assertEqual([], synthetic_violations(values=values))
+
+    def test_ac_13_rejects_duplicate_array_element(self) -> None:
+        values = dict(BASE_VALUES, covers_operations=["login.store", "login.store"])
+        self.assertNotEqual([], synthetic_violations(values=values))
+
+    def test_ac_13_rejects_malformed_route_token(self) -> None:
+        values = dict(BASE_VALUES, covers_screens=["Home Page"])
+        self.assertNotEqual([], synthetic_violations(values=values))
+
+    def test_ac_13_rejects_malformed_capability_token(self) -> None:
+        values = dict(BASE_VALUES, covers_capabilities=["auth-1"])
+        self.assertNotEqual([], synthetic_violations(values=values))
+
+    # ----------------------------------------------------------------- #
+    # AC-14: 全数点呼
+    # ----------------------------------------------------------------- #
+    def test_ac_14_accepts_complete_partition(self) -> None:
+        """実データの 58 項目が 3 分類へ過不足なく割れ、採用項目に担い手が居ること。"""
+        self.assertEqual([], partition_violations(
+            ALL_INVARIANTS, ADOPTED, DIFFERENCES, NOT_ADOPTED,
+            (*STORY_SIDE, *INVENTORY_SIDE, *NON_MECHANICAL), EXPECTED_TOTAL,
+        ))
+        # 非機械保証は「保証しないもの」の節と 1 対 1 にする (黙って落とさない)。
+        self.assertEqual(("E5", "G6"), NON_MECHANICAL)
+
+    def test_ac_14_accepts_explicit_subject_to_test_mapping(self) -> None:
+        """stories 側が担う項目が、実在する検査へ**明示的に**紐づいていること。
+
+        ★ 主題名からテスト名を**推測しない**。`AC-01` から作った `test_ac_01` は
+          実際の `test_ac_01_rejects_quoted_scalar` と一致せず、hasattr が常に偽になる。
+        """
+        for key in STORY_SIDE:
+            self.assertIn(key, INVARIANT_TO_SUBJECT, f"{key} に主題が無い")
+            self.assertIn(INVARIANT_TO_SUBJECT[key], SUBJECT_TO_TESTS)
+
+        for subject, names in SUBJECT_TO_TESTS.items():
+            for name in names:
+                self.assertTrue(callable(getattr(self, name, None)), f"{name} が実在しない")
+            self.assertTrue(any("accepts" in n for n in names), f"{subject} に正例が無い")
+            self.assertTrue(any("rejects" in n for n in names), f"{subject} に負例が無い")
+
+    def test_ac_14_rejects_missing_invariant(self) -> None:
+        self.assertNotEqual([], partition_violations(
+            ("A1", "A2"), ("A1",), (), (), ("A1",), 2,
+        ))
+
+    def test_ac_14_rejects_duplicate_classification(self) -> None:
+        self.assertNotEqual([], partition_violations(
+            ("A1",), ("A1",), ("A1",), (), ("A1",), 1,
+        ))
+
+    def test_ac_14_rejects_adopted_without_bearer(self) -> None:
+        self.assertNotEqual([], partition_violations(
+            ("A1",), ("A1",), (), (), (), 1,
+        ))
+
+    def test_ac_14_rejects_unknown_bearer_id(self) -> None:
+        self.assertNotEqual([], partition_violations(
+            ("A1",), ("A1",), (), (), ("A1", "Z9"), 1,
+        ))
+
+    def test_ac_14_rejects_wrong_total(self) -> None:
+        self.assertNotEqual([], partition_violations(
+            ("A1",), ("A1",), (), (), ("A1",), 58,
+        ))
+
+    # ----------------------------------------------------------------- #
+    # AC-15: カード本文の確定形
+    # ----------------------------------------------------------------- #
+    def test_ac_15_accepts_canonical_body(self) -> None:
+        self.assertEqual([], synthetic_violations())
+
+    def test_ac_15_rejects_missing_purpose_section(self) -> None:
+        for body in (
+            BASE_BODY.replace("## 目的\n見本のカードである。\n\n", ""),
+            BASE_BODY.replace("## 目的", "## 目的:"),
+        ):
+            with self.subTest(body=body[:40]):
+                self.assertNotEqual([], synthetic_violations(body=body))
+
+    def test_ac_15_rejects_duplicate_purpose_section(self) -> None:
+        body = BASE_BODY + "\n## 目的\n2 つ目の目的。\n"
+        self.assertNotEqual([], synthetic_violations(body=body))
+
+    def test_ac_15_rejects_empty_purpose_section(self) -> None:
+        body = BASE_BODY.replace("## 目的\n見本のカードである。\n", "## 目的\n\n")
+        self.assertNotEqual([], synthetic_violations(body=body))
+
+    def test_ac_15_rejects_duplicate_deviation_section(self) -> None:
+        body = BASE_BODY + "\n## 逸脱アイデア (--deviate 時)\n- もう 1 つ\n"
+        self.assertNotEqual([], synthetic_violations(body=body))
+
+    def test_ac_15_rejects_empty_deviation_section(self) -> None:
+        body = BASE_BODY.replace("## 逸脱アイデア (--deviate 時)\n- 二重送信してみる\n",
+                                 "## 逸脱アイデア (--deviate 時)\n\n")
+        self.assertNotEqual([], synthetic_violations(body=body))
+
+    def test_ac_15_rejects_missing_deviation_section(self) -> None:
+        for body in (
+            BASE_BODY.replace("## 逸脱アイデア (--deviate 時)\n- 二重送信してみる\n", ""),
+            BASE_BODY.replace("## 逸脱アイデア (--deviate 時)", "## 逸脱アイデア"),
+        ):
+            with self.subTest(body=body[-40:]):
+                self.assertNotEqual([], synthetic_violations(body=body))
+
+
+class ReadCardsTest(unittest.TestCase):
+    """候補母集団の作り方 (パターンで発見しない)。"""
+
+    def test_readme_is_excluded_and_others_are_not(self) -> None:
+        """除外は閉じたリテラル集合 1 件だけで、他の `*.md` は全件が候補になること。
+
+        ★ **件数を pin しない**。S8 以降を正規の手続き (表 A に面を足し、表 B に 1 行、
+          カードを 1 枚) で足せることが D7 の契約であり、ここで 7 枚に固定すると
+          AC-06 が S8 を阻害しないよう作ってある意味が消える。
+          母集団の非空は `test_population_is_not_empty`、表 B との 1 対 1 は AC-05 が持つ。
+        """
+        self.assertEqual(frozenset({"README.md"}), sfm.EXCLUDED_FILENAMES)
+        names = {card.filename for card in sfm.read_cards(STORIES_DIR)[0]}
+        self.assertNotIn("README.md", names)
+        self.assertNotEqual(set(), names)
+        self.assertEqual(
+            {p.name for p in STORIES_DIR.glob("*.md")} - sfm.EXCLUDED_FILENAMES, names
+        )
+
+    def test_missing_directory_is_a_read_error(self) -> None:
+        with self.assertRaises(sfm.StoryReadError):
+            sfm.read_cards(STORIES_DIR / "no-such-dir")
+
+
+if __name__ == "__main__":
+    unittest.main()
diff --git a/docs/template-divergence.md b/docs/template-divergence.md
index 14198914..f3537717 100644
--- a/docs/template-divergence.md
+++ b/docs/template-divergence.md
@@ -8,7 +8,7 @@ # テンプレート差分レジストリ
 `template-divergence-ledger` が 2026-08-15 に確定した形) に従う。形式は
 `tests/Architecture/TemplateDivergenceLedgerFormatTest.php` が機械で強制する。
 
-登録エントリ: 36 件
+登録エントリ: 37 件
 
 ## 記録の原則
 
@@ -717,10 +717,10 @@ ## D14 実行した route の記録をアプリ側の観測器で採る (退避
 
 | 行 | 内容 |
 |---|---|
-| 対象パス | `app/Http/Middleware/BughuntExecutedRouteMiddleware.php` / `bootstrap/app.php` / `config/bughunt.php` / `.claude/skills/app-bug-hunt/coverage/build_executed.py` / `.claude/skills/app-bug-hunt/coverage/correlate.py` |
-| 業務要件起因の説明 | 記録が採れていないことと本当に叩けていないことを取り違えると操作到達の一覧そのものが嘘になるため、遮断 middleware の内側で 1 要求 1 行を機械記録する |
-| 揃え続ける不変条件と保証機構 | 主入力が揃わない走行は成功にしない。`BughuntExecutedRouteOrderingTest` が記録器の位置を、集約と照合の 2 つの Python ツールが終了コード 3 を担う |
-| 再判定の条件 | 家系の正典が退避 → 正規化 → route 名解決の 3 段へ揃える裁定を出したとき / web グループ外の面を分母に載せるとき |
+| 対象パス | `app/Http/Middleware/BughuntExecutedRouteMiddleware.php` / `bootstrap/app.php` / `config/bughunt.php` / `.claude/skills/app-bug-hunt/coverage/build_executed.py` / `.claude/skills/app-bug-hunt/coverage/correlate.py` / `.claude/skills/app-bug-hunt/coverage/test_correlate.py` |
+| 業務要件起因の説明 | 記録が採れていないことと本当に叩けていないことを取り違えると操作到達の一覧そのものが嘘になるため、遮断 middleware の内側で 1 要求 1 行を機械記録する。併せて、割当列が複数値になった目録を照合器が取り違えずに読む |
+| 揃え続ける不変条件と保証機構 | 主入力が揃わない走行は成功にしない。`BughuntExecutedRouteOrderingTest` が記録器の位置を、集約と照合の 2 つの Python ツールが終了コード 3 を担う。割当セルの分解は `test_correlate.py` が値域の両方向で固定する |
+| 再判定の条件 | 家系の正典が退避 → 正規化 → route 名解決の 3 段へ揃える裁定を出したとき / web グループ外の面を分母に載せるとき / 家系の正典が割当列の分解を実装したとき |
 | 決めた日 | 2026-08-15 |
 | 決めた人 | 開発者 |
 | 根拠 | T164 |
@@ -733,6 +733,7 @@ ## D14 実行した route の記録をアプリ側の観測器で採る (退避
 | 採取の起動 | 走行中の LLM (探索エージェント) が退避コマンドを呼ぶ | 起動時に `provision` が env で仕込み、以後は無条件 |
 | 遮断された要求の扱い | 通信履歴なので 302/403 も「叩いた」側に残り、後段で除外しきれない | 遮断 middleware より**内側**に置いてあるため、そもそも記録に現れない |
 | 主入力が欠けたとき | 照合器が「全 in_scope を未実行 candidate」として出力し 0 で終わる | **終了コード 3 で落ちる** (worklist を出さない) |
+| 目録の割当列の読み方 (理由 2) | セルをそのままキーにするので `S3 S7` の行は `S3` の finding と一致しない | **セルを検証してから分解**し、各 story へ索引する (単一値の挙動は不変。正典に無い上乗せ = 家系への還流候補) |
 
 ### なぜ正当な差分か(logic-driven)
 
@@ -770,6 +771,18 @@ ### 揃えている不変条件(これは保証し続ける)
 - 記録器が既定 no-op であること (env 既定 false + production 除外) と ok/blocked の写像は
   `tests/Feature/Bughunt/ExecutedRouteCaptureTest.php` が実 HTTP 要求で固定する
 
+理由 2 (割当列の分解) が揃え続けるのは次である。
+
+> 「**目録の割当列に載ったカードは、すべてその finding の索引先になる**」
+
+- 割当セルの値域 (`S{n}` を番号の昇順で半角空白 1 つ区切り、または `-`) は
+  書き出し側 (`scripts/bug-hunt-inventory.py`) が自分の出力を突き合わせ、
+  読み手 (`correlate.py`) が `fullmatch` で強制する。**寛容に正規化しない**
+- 契約外のセル (前後空白 / 連続空白 / 降順 / 重複 / 未知の綴り) は照合器が
+  **終了コード 3** で落ちる (目録の手編集と生成器の故障を黙って進めない)
+- 両側の定数が一致することと、生成側が書くセルを読み手が同じ値へ分解することは
+  `scripts/tests/test_bug_hunt_inventory.py` が同一ケースの列挙で固定する
+
 ### 保証しないもの (誇張しない)
 
 - **web グループ外は観測しない** (`api/*` / Filament `/admin` / MCP)。分母に載っていれば
@@ -777,6 +790,9 @@ ### 保証しないもの (誇張しない)
 - **部分欠測は検出しない**。分かるのは「名前付き route の行が 1 件も無い」「別 run が混ざった」
   「失敗マーカーが残せた」まで
 - **偽造耐性は無い**。記録ファイルは worktree 内にあり、書き換えを検出する仕組みは持たない
+- 割当セルに書かれたカードが**実在するか**は照合器では見ない (目録は生成物であり、
+  割当列は実在するカードの前付けからしか作られない。手編集で紛れ込んだ id は
+  目録の byte 一致検査が落とす)
 
 ### 関連
 
@@ -1134,7 +1150,7 @@ ## D20 bug-hunt 目録の生成方式を、注釈 TOML・機能カタログ 3 
 
 | 行 | 内容 |
 |---|---|
-| 対象パス | `scripts/bug-hunt-inventory.py` / `app/Console/Commands/Bughunt/InventoryScanCommand.php` / `.claude/skills/app-bug-hunt/inventory/annotations.toml` |
+| 対象パス | `scripts/bug-hunt-inventory.py` / `app/Console/Commands/Bughunt/InventoryScanCommand.php` / `.claude/skills/app-bug-hunt/inventory/annotations.toml` / `scripts/tests/test_bug_hunt_inventory.py` / `tests/Architecture/BugHuntInventoryCheckInvariantTest.php` / `.claude/skills/app-bug-hunt/SKILL.md` / `scripts/README.md` |
 | 業務要件起因の説明 | 機能カタログの id 列が所見記録の語彙の正本であり、Python ツールを標準ライブラリだけで書く規約から注釈は TOML になる |
 | 揃え続ける不変条件と保証機構 | 目録は実装と注釈から再生成でき、ずれていたら CI が落ちる。`BugHuntInventoryCheckInvariantTest` と生成器の自己テストが 4 段の判定を固定する |
 | 再判定の条件 | 家系の正典が id 列を持つ形へ変わったとき / Python に依存を足す裁定が出たとき / 中間 JSON を読む道具が家系に現れたとき |
@@ -1153,6 +1169,9 @@ ## D20 bug-hunt 目録の生成方式を、注釈 TOML・機能カタログ 3 
 | 機能カタログ (`capability-catalog.md`) | 生成物。3 列は 機能 / 対応する画面 / 対応する操作 | **生成しない**。3 列は `id` / `機能 (actor→outcome)` / `代表機構 (route name)` を維持し、参照整合だけを検査する |
 | 注釈ファイル | `inventory/annotations.yaml` | **`inventory/annotations.toml`** |
 | 中間成果物 | `inventory/inventory.json` をコミットする | **持たない** (生成・検査の実行中にだけ存在する) |
+| 割当の正本 | カードの前付け (`covers_screens` / `covers_operations`) | **同じ** (2026-08-23 に注釈の `story` を撤去して一本化した。以前は注釈側が正本だった) |
+| `covers_screens` の母集合 | `kind` が `screen` / `read` / `redirect` の web route | **safe method (GET / HEAD / OPTIONS) の web route** (`kind` の語彙が `画面` / `JSON` で違うため `kind` に依存させない) |
+| `covers_capabilities` の検査 | 実在 / 欄の意味 / 分母 / 被覆の 4 段 | **実在・形・一意まで** (機能カタログが継承宣言の欄 `no_route` / `coverage_surface` / `covered_via` を持たないため、分母・被覆は見ない) |
 
 ### なぜ正当な差分か (logic-driven)
 
@@ -1178,9 +1197,11 @@ ### 揃えている不変条件 (これは保証し続ける)
 | 不変条件 | 担い手 |
 |---|---|
 | 抽出が成功し、宣言した抽出条件で走り、母集合が 0 件でないこと (段 1) | `scripts/bug-hunt-inventory.py` (exit 2) / `scripts/tests/test_bug_hunt_inventory.py` |
-| 注釈の集合が面の集合と一致し、語彙・必須・理由の長さを満たすこと (段 2) | 同上 (exit 3)。未注釈も残置注釈も許さない |
+| 注釈の集合が面の集合と一致し、語彙・必須・理由の長さを満たすこと (段 2) | 同上 (exit 3)。未注釈も残置注釈も許さない。割当を注釈へ書き戻す道は未知の項目として塞ぐ |
+| 対象内 (区分が `外` でない) の route が 1 枚以上のカードの `covers_*` に載っていること (段 2) | 同上 (exit 3)。載せた route の実在・欄の意味・対象外でないことも見る |
 | 生成物が再生成の結果と byte 一致すること (段 3) | 同上 (exit 3)。手編集と再生成の忘れをまとめて捕まえる |
 | 機能カタログの代表機構が実在し、id が重複しないこと (段 4) | 同上 (exit 3) |
+| カードが挙げる capability が実在すること (段 4) | 同上 (exit 3)。**被覆漏れは見ない** |
 | 検査シェルが判定を持たず、終了コード 0 / 2 / 3 を実際に返すこと | `tests/Architecture/BugHuntInventoryCheckInvariantTest.php` (sandbox 実走) |
 | 生成器の自己テストが `composer test` の下で実走すること | `tests/Architecture/BughuntInventoryToolSelfTest.php` |
 | 抽出コマンドが事実だけを書き出すこと (面の判定を持たない) | `tests/Feature/Bughunt/InventoryScanCommandTest.php` |
@@ -1194,13 +1215,23 @@ ### 揃えている不変条件 (これは保証し続ける)
 必ず目録に入り注釈を要求される。
 注釈の**内容**の妥当性 (割当が適切か) は見ない。画面題名の欠落も検出しない。
 機能カタログの網羅性も見ない (代表機構の実在と id の一意性まで)。
+**割当が痩せたこと**も検出できない — 見るのは「1 枚以上のカードに載っていること」だけなので、
+ある route が `S3 S7` から `S3` へ減っても緑のままである (PR レビューの義務)。
 目録の母集合は T164 の記録器が観測しうる route の**部分集合**であり、両者は一致しない。
 
+**対象パスに運用文書 2 本を含める理由 (範囲を誇張しない)**: `.claude/skills/app-bug-hunt/SKILL.md` と
+`scripts/README.md` は本エントリで**目録の生成方式に関わる記述だけ**を説明する
+(どこを直して再生成するか / 割当の正本はどこか)。両ファイルには本エントリと無関係な
+テンプレート差分も含まれうるが、それらは本エントリが説明したことにはならない。
+2026-08-23 に採用時債務一覧から本エントリへ移した (割当の正本を一本化したのに、
+運用手順が廃止済みの入力先へ誘導したままになるのを避けるため)。
+
 ### 再検討の条件 (解消条件)
 
 - 家系の正典が id 列を持つ形へ変わったとき (機能カタログの生成を採り直す)
 - 本リポジトリの Python に依存を足す裁定が出たとき (注釈を YAML へ寄せる)
 - 中間 JSON を読む道具が家系に現れたとき
+- 機能カタログが継承宣言の欄を持つ形になったとき (`covers_capabilities` の被覆判定を採り直す)
 
 ### 関連
 
@@ -2259,3 +2290,77 @@ ### 関連
 
 - 実装: `tests/Architecture/PasskeyPackageContractTest.php`
 - 設計: `devnotes/20260821-2015-auth-method-change-notification/`
+
+---
+
+## D40 シナリオカードの前付けは採るが、ステップ表の書式は採らない
+
+| 行 | 内容 |
+|---|---|
+| 対象パス | `.claude/skills/app-bug-hunt/stories/README.md` / `.claude/skills/app-bug-hunt/stories/test_story_front_matter.py` |
+| 業務要件起因の説明 | 所見台帳の finding は story までしか指さず step を指す欄を持たないため、ステップ識別子を入れても読む機械が 1 つも無い |
+| 揃え続ける不変条件と保証機構 | 前付けの制限文法・番号規約・表 A / 表 B との突合は `stories/test_story_front_matter.py` が強制し、`BughuntStoryToolSelfTest` が composer test の配線に載せる |
+| 再判定の条件 | `ledger/findings.schema.json` に step を指す欄が入ったとき / 家系の正典が t2 以降でステップ表を版の名前に含めたとき / `applicability` に `not_applicable` を取るカードを 1 枚でも置くことになったとき |
+| 決めた日 | 2026-08-22 |
+| 決めた人 | 開発者 |
+| 根拠 | devnotes/20260823-0022-bughunt-story-front-matter-adoption/ |
+| 状態 | 恒久 |
+| 見直し期限 | — |
+
+家系の正典 (機能台帳 `bughunt-story-front-matter` の t1) は、シナリオカードに制限文法の前付けを
+置いて割当の正本にし、併せて**手順をステップ表の書式で書く**ことまでを 1 つの契約にしている。
+本アプリは**前付けは全面的に採る**が、次の 2 点は採らないので登録する。
+
+| 外している契約 | 本アプリの形 |
+|---|---|
+| ステップ表の書式 (正準 4 列ヘッダ `step / 操作 / 期待 / 注目` / 疎な step 識別子 `{id}-{3 桁}` / 副ブロック / 期待欄・注目欄の書き分け) | **散文の番号付きリストのまま**置く |
+| `not_applicable` のカードを実走対象から外す契約 (`SKILL.md` 側が持つ) | **持たない** |
+
+### なぜ正当な差分か (logic-driven)
+
+1. **step 識別子を読む機械が 1 つも無い**。所見台帳の schema
+   (`.claude/skills/app-bug-hunt/ledger/findings.schema.json`) は finding の位置を
+   `story_id` / `route_name` / `capability_tag` で指し、**step を指す欄を持たない**。
+   識別子を振っても照合器・抑制機構・目録のどれもそれで join しないので、
+   増えるのは「振り直してはいけない番号」という保守債務だけである。
+   正典が step を切ったのは finding が step を指す形を前提にしているためで、
+   その前提が本アプリには無い。
+2. **`not_applicable` の実走除外は該当カードが 0 枚である**。本アプリは家系必須 7 面の
+   すべてに実カードがあり、`not_applicable` を取るカードは 1 枚も無い。契約の置き場は
+   `SKILL.md` だが、同ファイルは採用時債務 (D34) に在るため触らない。
+   **今必要なものだけ作る** (思考原則 2) に従い、該当カードが生まれるまで置かない。
+
+### 揃えている不変条件 (これは保証し続ける)
+
+> 「**割当の正本はカードの前付けだけであり、前付けは制限文法と番号規約を機械で満たす**」
+
+| 不変条件 | 担い手 |
+|---|---|
+| 前付けの制限文法 (区切り / 1 行 1 項目 / key の書式と重複 / 値の 3 形) | `.claude/skills/app-bug-hunt/stories/story_front_matter.py` |
+| 必須 13 key の全数と正準順序・閉じた語彙・値の書式 | `stories/test_story_front_matter.py` (AC-01 / AC-02) |
+| 番号規約 (命名 / 一意 / 欠番なし / 家系固定の `(id, surface)`) | 同上 (AC-03 / AC-06) |
+| 表 A の構造と家系必須 11 語・表 B とカードの 1 対 1 | 同上 (AC-04 / AC-05) |
+| 依存と実行方式の整合 (実在 / 自己参照 / 循環 / 初期化 / 直列待ち) | 同上 (AC-07 / AC-08 / AC-09) |
+| 本文の確定形と旧メタ節の不在 (二重の正本を残さない) | 同上 (AC-10 / AC-11 / AC-12 / AC-15) |
+| 採用した不変条件の全数点呼 (未割当 0 件・担い手の実在) | 同上 (AC-14) |
+| 上記が `composer test` の下で実走し、検査を削って緑にできないこと | `tests/Architecture/BughuntStoryToolSelfTest.php` (件数の下限 + 中核負例の成功表示) |
+
+### 保証しないもの (誇張しない)
+
+- **ステップ表を採らない帰結**: step 識別子の再採番の禁止・副ブロックの個数・期待欄と注目欄の
+  書き分けは 1 つも検査しない (概念ごと持たない)
+- 兆候番号 (`H{n}`) の意味をカードに書かないことは**文書規約であり機械検査しない**
+  (正典もこれ単独の検査は持たない)
+- `lane` / `depends_on` と `scripts/bug-hunt-shard.sh` の固定 fan-out マップの一致は見ない
+  (固定マップは前付けからの派生キャッシュ。**正典も未達**)
+- `accounts` と `database/seeders/ManualTestSeeder.php` の一致は見ない (正典も同じ)
+- `covers_*` の値の**実在**は前付け側では見ない (形だけ)。実在・欄の意味・分母の被覆は
+  目録側 (D20) の責務である
+
+### 関連
+
+- 実装: `.claude/skills/app-bug-hunt/stories/` (README.md / story_front_matter.py /
+  test_story_front_matter.py / S1〜S7 のカード)
+- gate: `tests/Architecture/BughuntStoryToolSelfTest.php` /
+  `tests/Support/Bughunt/StoryFrontMatterPins.php`
+- 設計: `devnotes/20260823-0022-bughunt-story-front-matter-adoption/`
diff --git a/scripts/README.md b/scripts/README.md
index 062695d6..d52ec425 100644
--- a/scripts/README.md
+++ b/scripts/README.md
@@ -46,7 +46,7 @@ ## スクリプト一覧
 | `setup-browser-testing.contract.test.ts` | `setup-browser-testing.sh` の契約テスト (決定表の sandbox 実走 / 静的契約 / pin された実 Playwright の出力との突合) | `pnpm test` |
 | `run-browser-test.sh` | Browser テスト (pest-plugin-browser) を**グローバルテストロック配下**で並列上限付きで実行。**Chromium / WebKit の 2 レーンが契約** (bfcache 復元シナリオは WebKit レーンが正本)。残留 playwright run-server を前後で掃除する (`@playwright/` = bug-hunt 側は除外)。起動時に bughunt ポート `:8010..8018` の best-effort pre-flight guard を掛ける (cap=4 より広く取るのは残留検出のため) | `composer test:browser` 等から呼び出し。レーン限定は `BROWSER_TEST_LANES` / 並列度は `BROWSER_TEST_PROCESSES` |
 | `bug-hunt-shard.sh` | bug-hunt シャードオーケストレータ。隔離環境 (DB `bug_hunt(_N)` / `:8010+N`) の provision / serve / teardown と、**dev DB を wipe しないための用途別 DB wrapper + 3-way hard-deny guard** を提供する (AGENTS.md §bug-hunt) | `/app-bug-hunt` から。`self-test` は実資源に触れず guard を検証 |
-| `bug-hunt-inventory.py` | bug-hunt 目録 (`.claude/skills/app-bug-hunt/{screens,operations}.md`) の生成器兼検査器。`generate` は実装の機械事実 + 注釈 (`inventory/annotations.toml`) + 散文 (`inventory/notes-*.md`) から 2 ファイルを作り、`check` は同じ合成をメモリ上で行って byte 比較する (**1 バイトも書かない**)。exit 0=一致 / 2=致命 / 3=ドリフト | route 追加・削除時に `generate` / CI と bug-hunt 実行前に `check` |
+| `bug-hunt-inventory.py` | bug-hunt 目録 (`.claude/skills/app-bug-hunt/{screens,operations}.md`) の生成器兼検査器。`generate` は実装の機械事実 + 注釈 (`inventory/annotations.toml`) + 散文 (`inventory/notes-*.md`) + シナリオカードの前付け (`stories/S*.md` の `covers_*` = **割当の正本**) から 2 ファイルを作り、`check` は同じ合成をメモリ上で行って byte 比較する (**1 バイトも書かない**)。exit 0=一致 / 2=致命 / 3=ドリフト | route 追加・削除時に `generate` / CI と bug-hunt 実行前に `check` |
 | `bug-hunt-inventory-check.sh` | bug-hunt 目録のドリフト検査の起動口。判定は持たず `bug-hunt-inventory.py check` を exec するだけ (同じ規則を 2 か所に置かない) | route 追加・削除時 / bug-hunt 実行前 / CI (`php` job) |
 | `tests/test_bug_hunt_inventory.py` | `bug-hunt-inventory.py` の自己テスト (標準ライブラリのみ)。実 `php` を呼ばず fake scanner で段 1..4 と差し替えの失敗経路を検証する | `composer test` (`tests/Architecture/BughuntInventoryToolSelfTest.php` が起動) |
 | `bughunt-worktree-hook.sh` | PreToolUse(Bash) ガード。`bug-hunt-shard.sh provision` の **main 直叩き** (worktree 指紋なし) を harness 層で拒否する (拒否は終了コード 97。起動子が 97 だけを 2 へ写す)。判定は bash の組み込みだけで完結し、外部コマンドを 1 つも使わない | `.claude/settings.json` に常設配線 (AGENTS.md §常設 hook 配線) |
diff --git a/tests/Architecture/BugHuntInventoryCheckInvariantTest.php b/tests/Architecture/BugHuntInventoryCheckInvariantTest.php
index 92daf0a3..b22a7dad 100644
--- a/tests/Architecture/BugHuntInventoryCheckInvariantTest.php
+++ b/tests/Architecture/BugHuntInventoryCheckInvariantTest.php
@@ -22,9 +22,14 @@
  * (boot + APP_KEY + DB) には依存させない: 一時 sandbox へ道具一式を複製し、`php` を
  * 固定の scan JSON を吐く shim に差し替えて走らせる (決定論・DB 不使用)。
  *
+ * 道具一式には**シナリオカードと前付けの読み取り器**が含まれる。割当 (どのカードが route を
+ * 消化するか) の正本はカードの前付けであり (`docs/template-divergence.md` D20 / D40)、
+ * 生成器はそれを読めないと段 2 を成立させられないためである。
+ *
  * ★空振り検査 (母集団非空) の付与対象外である。理由:
- *   本 gate は**ディレクトリを列挙して母集団を作らない**。見るのは名指しの 2 ファイル
- *   (`scripts/bug-hunt-inventory-check.sh` / `scripts/bug-hunt-inventory.py`) と、
+ *   本 gate は**ディレクトリを列挙して母集団を作らない**。見るのは名指しの 3 ファイル
+ *   (`scripts/bug-hunt-inventory-check.sh` / `scripts/bug-hunt-inventory.py` /
+ *   `.claude/skills/app-bug-hunt/stories/story_front_matter.py`) と、
  *   テスト自身が組み立てた sandbox の fixture だけである。走査根の改名・移動は
  *   「母集団が 0 件になって緑」ではなく `Assert::fileExists` / `expect(file_exists(...))` の
  *   即時 fail になる (= 無言の空振りが起きる形になっていない)。
@@ -44,9 +49,44 @@ function bhicGeneratorPath(): string
     return base_path('scripts/bug-hunt-inventory.py');
 }
 
+/** 前付けの読み取り器 (生成器が割当を読むのに使う。カードの隣に置く)。 */
+function bhicStoryReaderPath(): string
+{
+    return base_path('.claude/skills/app-bug-hunt/stories/story_front_matter.py');
+}
+
 /** sandbox 内の相対パス (生成器が持つ正本パスと同じ場所へ置く)。 */
 const BHIC_SKILL_DIR = '.claude/skills/app-bug-hunt';
 
+/**
+ * sandbox 用のシナリオカード 1 枚 (割当の正本は前付けである)。
+ *
+ * @param  list<string>  $screens
+ * @param  list<string>  $operations
+ */
+function bhicCard(string $id, string $surface, array $screens, array $operations): string
+{
+    return "---\n"
+        ."id: {$id}\n"
+        ."title: 見本カード {$id}\n"
+        ."surface: {$surface}\n"
+        ."lane: parallel_browser\n"
+        ."priority: P1\n"
+        ."applicability: applicable\n"
+        ."depends_on: []\n"
+        ."reseed_before: true\n"
+        ."accounts: [guest]\n"
+        ."setup: []\n"
+        .'covers_screens: ['.implode(', ', $screens)."]\n"
+        .'covers_operations: ['.implode(', ', $operations)."]\n"
+        ."covers_capabilities: []\n"
+        ."---\n\n"
+        ."# {$id}: 見本カード {$id}\n\n"
+        ."## 目的\n見本である。\n\n"
+        ."## 手順\n1. 開く → 見える\n\n"
+        ."## 逸脱アイデア (--deviate 時)\n- 二重送信してみる\n";
+}
+
 /**
  * sandbox を組み立てる。scripts/ に検査シェルと生成器、skill ディレクトリに注釈・散文ノート・
  * 機能カタログ、bin/ に `php` shim (固定の scan JSON を吐く) を置く。
@@ -60,10 +100,12 @@ function bhicMakeSandbox(bool $phpFails = false): string
     $sandbox = sys_get_temp_dir().'/bhic-'.bin2hex(random_bytes(6));
     mkdir($sandbox.'/scripts', 0o755, true);
     mkdir($sandbox.'/'.BHIC_SKILL_DIR.'/inventory', 0o755, true);
+    mkdir($sandbox.'/'.BHIC_SKILL_DIR.'/stories', 0o755, true);
     mkdir($sandbox.'/bin', 0o755, true);
 
     copy(bhicScriptPath(), $sandbox.'/scripts/bug-hunt-inventory-check.sh');
     copy(bhicGeneratorPath(), $sandbox.'/scripts/bug-hunt-inventory.py');
+    copy(bhicStoryReaderPath(), $sandbox.'/'.BHIC_SKILL_DIR.'/stories/story_front_matter.py');
 
     $scan = [
         'schema_version' => 1,
@@ -84,8 +126,17 @@ function bhicMakeSandbox(bool $phpFails = false): string
 
     file_put_contents(
         $sandbox.'/'.BHIC_SKILL_DIR.'/inventory/annotations.toml',
-        "schema_version = 1\n\n[routes.\"dashboard\"]\nkind = \"画面\"\nstory = \"S1\"\nkubun = \"通常\"\n\n"
-        ."[routes.\"projects.store\"]\nstory = \"S4\"\nkubun = \"通常\"\n"
+        "schema_version = 1\n\n[routes.\"dashboard\"]\nkind = \"画面\"\nkubun = \"通常\"\n\n"
+        ."[routes.\"projects.store\"]\nkubun = \"通常\"\n"
+    );
+    // 割当の正本はカードの前付けである (注釈には書かない)。対象内 2 route をちょうど覆う。
+    file_put_contents(
+        $sandbox.'/'.BHIC_SKILL_DIR.'/stories/S1-signup.md',
+        bhicCard('S1', 'signup_funnel', ['dashboard'], []),
+    );
+    file_put_contents(
+        $sandbox.'/'.BHIC_SKILL_DIR.'/stories/S2-admin.md',
+        bhicCard('S2', 'org_project_admin', [], ['projects.store']),
     );
     file_put_contents($sandbox.'/'.BHIC_SKILL_DIR.'/inventory/notes-screens.md', "## 画面の散文\n\n人が書く。\n");
     file_put_contents($sandbox.'/'.BHIC_SKILL_DIR.'/inventory/notes-operations.md', "## 操作の散文\n\n人が書く。\n");
diff --git a/tests/Support/Bughunt/StoryFrontMatterPins.php b/tests/Support/Bughunt/StoryFrontMatterPins.php
new file mode 100644
index 00000000..c2760762
--- /dev/null
+++ b/tests/Support/Bughunt/StoryFrontMatterPins.php
@@ -0,0 +1,50 @@
+<?php
+
+declare(strict_types=1);
+
+namespace Tests\Support\Bughunt;
+
+/**
+ * シナリオカードの書式契約の自己テストに対する固定値 (不変の scalar / 配列定数だけを持つ)。
+ *
+ * ★**解析・ファイル I/O・プロセス実行を一切持たない**。値の置き場所を 1 か所にするための型である。
+ *   Pest のテストファイルに書いた `const` は**そのファイルが読み込まれた後にしか見えない**ため、
+ *   固定値はクラス定数として置く (`Tests\Support\TemplateDivergence\LedgerPins` と同じ理由・同じ作法)。
+ * ★**これは免除の一覧ではない**。個別の検査を名指しして無効化する仕組みは本機構のどこにも無い。
+ */
+final class StoryFrontMatterPins
+{
+    /** インスタンス化しない (定数の置き場)。 */
+    private function __construct() {}
+
+    /**
+     * 活きている検査の件数の下限 (実測値)。
+     *
+     * ★**下限**である (上振れは許す)。減ることだけを禁じ、検査を削って緑にする道を塞ぐ。
+     */
+    public const int MIN_TESTS = 81;
+
+    /**
+     * 中核の負例。名前だけでなく `... ok` の成功表示まで照合して skip 逃げを塞ぐ。
+     *
+     * @var list<string>
+     */
+    public const array CORE_NEGATIVES = [
+        'test_ac_01_rejects_quoted_scalar',
+        'test_ac_01_rejects_duplicate_key',
+        'test_ac_01_rejects_key_out_of_canonical_order',
+        'test_ac_02_rejects_unknown_lane',
+        'test_ac_03_rejects_gap_in_card_numbers',
+        'test_ac_04_rejects_removed_family_surface',
+        'test_ac_05_rejects_card_missing_from_inventory',
+        'test_ac_06_rejects_reassigned_family_surface',
+        'test_ac_07_rejects_dependency_cycle',
+        'test_ac_08_rejects_reseed_with_dependency',
+        'test_ac_09_rejects_parallel_depending_on_serial',
+        'test_ac_10_rejects_steps_in_not_applicable_card',
+        'test_ac_11_rejects_heading_mismatch',
+        'test_ac_12_rejects_legacy_meta_section',
+        'test_ac_13_rejects_duplicate_array_element',
+        'test_ac_15_rejects_missing_purpose_section',
+    ];
+}
diff --git a/tests/Support/TemplateDivergence/LedgerPins.php b/tests/Support/TemplateDivergence/LedgerPins.php
index 80e882c9..f53de4e0 100644
--- a/tests/Support/TemplateDivergence/LedgerPins.php
+++ b/tests/Support/TemplateDivergence/LedgerPins.php
@@ -19,7 +19,7 @@ final class LedgerPins
     private function __construct() {}
 
     /** 逸脱の登録件数 (宣言行 / 見出しの実数 / 本定数の 3 点一致)。 */
-    public const int DIVERGENCE_ENTRY_COUNT = 36;
+    public const int DIVERGENCE_ENTRY_COUNT = 37;
 
     /** 指紋台帳の登録パス件数 (「以下」ではない完全一致)。 */
     public const int FINGERPRINT_POPULATION_COUNT = 281;
@@ -31,7 +31,7 @@ private function __construct() {}
      *   増やせば通る)。増加を許さないのは生成器のガードとレビュー規約であり、
      *   検査は「一覧と定数と実測が食い違ったら赤」を担う。
      */
-    public const int ADOPTION_DEBT_COUNT = 171;
+    public const int ADOPTION_DEBT_COUNT = 166;
 
     /**
      * 採用時債務一覧を説明する逸脱の登録番号 (D34)。
diff --git a/tests/Support/TemplateDivergence/adoption-debt.tsv b/tests/Support/TemplateDivergence/adoption-debt.tsv
index 1f239ab2..535ba6d0 100644
--- a/tests/Support/TemplateDivergence/adoption-debt.tsv
+++ b/tests/Support/TemplateDivergence/adoption-debt.tsv
@@ -1,11 +1,9 @@
 # template_ledger_commit=a078806b0574518ddc64966f60f7d536b1338b2f
 .claude/agents/bughunt-shard.md	85c2a7b649178200415baa06768940aebb7d9ffce8f615c23da856dbec8922cf
-.claude/skills/app-bug-hunt/SKILL.md	72504c5e21f3acb24eedde7bec4f6a4923005d9d99e941b708657649d48a4e81
 .claude/skills/app-bug-hunt/coverage/README.md	644e649a15d603d9ffd60f708fe6ce444ff9e83fd13c15264c78514943872d1f
 .claude/skills/app-bug-hunt/coverage/fixtures/executed.sample.json	360f716d2f09e68d63963c7bac2254c6c2c5a91329a292a9b2ce9dff5cc79fc3
 .claude/skills/app-bug-hunt/coverage/fixtures/operations.sample.md	d7925e4f682fef426ad7836887d19459fd068687c20ec611414caef031bec1ad
 .claude/skills/app-bug-hunt/coverage/merge_pcov.py	58188a2395e3e6217e8a7c529747290a6b320c6a3258f9f4902ad2cc83fbe667
-.claude/skills/app-bug-hunt/coverage/test_correlate.py	586039bd67ac81145d990fcf398885f6809e561184c75aefde3b39a6b007d7aa
 .claude/skills/app-bug-hunt/coverage/test_merge_pcov.py	af796fa2dc20752f5022543cae3029de5a71f2b3a0474a9d8aafc155935388ab
 .claude/skills/app-bug-hunt/coverage/test_out_of_scope.py	4a8681e55ad4005f41578ebc308fa3983babf4547d03f8301fb33c8b5f9f6bb7
 .claude/skills/app-bug-hunt/ledger/README.md	8df5c3a8eec38e1ddaef93bcf8651b2fea84fdfbba8703cbbad897a5ff9eea52
@@ -66,7 +64,6 @@ phpstan.neon	df096ab994cd7f32a82e9aa46e83feb15d85ef3f10dceb533fd9b7f081b595d5
 phpunit.browser.xml	a13026158e86bc3845e5dfc4b4d7e12564fbf065041d193da4464e6a50d17cc5
 phpunit.xml	a914a68dd63583bb65767a66e30ad5d7d6d9619439c4cc6da888b45e12b982e4
 pnpm-workspace.yaml	800626e0abd9fa6ebcc56099d4699a2acdee791f80bd8a70a890ae08d03c6a07
-scripts/README.md	37124606e20d35633be16dbcefa128c2743576dda4b0d17eb5b4249794292e6e
 scripts/audit-gate.sh	5d68bb3b9677b68469901da5de59792838aa6f27596e4d655c3a3c03f87b3718
 scripts/audit-gate.test.ts	e2ee4e98b41fe99ec65b4bfe1233f10aa12e9e7492c3842cb60607fb293a1696
 scripts/audit-gate.ts	cbbee89e21d452f9fb52e670251b96ecca6539b15744217d86e15672b24ad96e
@@ -77,7 +74,6 @@ scripts/setup-browser-testing.sh	eda46c5940927f2dcdf732762429213821388bbb87a4182
 scripts/setup-worktree.sh	cedd1213dcb5c00f5fe19993dfa408a845224750ff090c931b5de4fdc2223dd5
 scripts/teardown-worktree.sh	53fe7eec049a0fa4315ddb27b8b1e804c70f00bd9146006a3509f06bf78db086
 scripts/test-inventory-config.ts	208fbd5d727abf5776bff87ccdda4a7684a80073dfbfe863c6f9245bb368e61d
-scripts/tests/test_bug_hunt_inventory.py	17ed2e5d63cfbd4f6203732c5a52623bce7f0cc30d2bdcf7dfb3798ace564a59
 scripts/vitest-inventory-gate.test.ts	3c17589f7d309f13b542cf1b6ae962b5aad71fd06462cfbd43e4c617f58e807b
 skills-lock.json	3e8e488491111ba3736f79e7954f2c82a75f724edca77f500fe3225aebb07377
 tests/Architecture/AccountDeletionFreezeRouteGateTest.php	82d7b260ef3ae05555e0c08e9e1b1a3bc801e373c8dcf314a806e346cf5c80ac
@@ -90,7 +86,6 @@ tests/Architecture/BfcacheGuardClientContractSyncTest.php	1de798c9587d8d5d70eaa9
 tests/Architecture/BillingGatewayFailureTaxonomyInventoryTest.php	a97127afa35977e75c350231d9a016758ee56879329217275b8ac48a87b02c6a
 tests/Architecture/BillingRetentionConfigSingleSourceTest.php	d03eb1ed368cb00545deacc424eb57cb0e0e8b6f4ae5442035e7bc0609bc4189
 tests/Architecture/BillingRetentionTargetInventoryTest.php	338da106bfe063adb4f23285933c59c76bb044c44cf802404eab605211b4719b
-tests/Architecture/BugHuntInventoryCheckInvariantTest.php	51195ac2fcd52cb53b21a808bbd62a72ea5b1829360221ec5df758dccc534fd9
 tests/Architecture/BugHuntSkillInvariantTest.php	7ac57d13113b5bb97c6aa252d30f825f8438f3c275281fedabc5e8fd41a837b4
 tests/Architecture/BughuntOrchestratorGateInvariantTest.php	d6c12c7a5faba29643a98f3b8bcabb31b10d957ea59845c4d6b34f0dfa2cc299
 tests/Architecture/CachePayloadPlainDataGateTest.php	c92f8a4b364fcad254869f43327bc5c99a2fa55b618c05428f7e90cbabd87508
```

## 参考: `screens.md` の差分 (W7 対応後 = 生成通知 + S7 追加分だけ)

```diff
diff --git a/.claude/skills/app-bug-hunt/screens.md b/.claude/skills/app-bug-hunt/screens.md
index 63609c1b..a43ea381 100644
--- a/.claude/skills/app-bug-hunt/screens.md
+++ b/.claude/skills/app-bug-hunt/screens.md
@@ -1,8 +1,10 @@
 # 画面インベントリ (screens.md) — AI-CUE
 
 > **このファイルは生成物である。手で編集しない。**
-> 直し方: `.claude/skills/app-bug-hunt/inventory/annotations.toml` (割当・区分・理由) か
-> `inventory/notes-*.md` (散文) を直してから `python3 scripts/bug-hunt-inventory.py generate` を走らせる。
+> 直し方: 割当ストーリー列は `.claude/skills/app-bug-hunt/stories/S*.md` の前付け
+> (`covers_screens` / `covers_operations`) を、区分・理由・種別は
+> `inventory/annotations.toml` を、散文は `inventory/notes-*.md` を直してから
+> `python3 scripts/bug-hunt-inventory.py generate` を走らせる。
 > 抽出条件: 開発環境 (local) またはテスト実行中に登録される route 集合。
 > ドリフト検査: `scripts/bug-hunt-inventory-check.sh` (exit 3 = ドリフト)。
 
@@ -19,8 +21,8 @@ ## GET × web 一覧 (画面 + 画面に付随する JSON GET)
 | app/csrf-cookie | capture.csrf-cookie | JSON | - | S3 | 通常 |
 | app | capture.home | 画面 | - | S3 | 通常 |
 | app/projects/{project}/manuals | capture.manuals.index | 画面 | 撮影するマニュアルを選ぶ | S3 | 通常 |
-| app/projects/{project}/manuals/{manual} | capture.manuals.show | 画面 | - | S3 | 通常 |
-| app/projects/{project}/manuals/{manual}/cuts/{cut}/takes/{take}/playback | capture.takes.playback | 画面 | - | S3 | 通常 |
+| app/projects/{project}/manuals/{manual} | capture.manuals.show | 画面 | - | S3 S7 | 通常 |
+| app/projects/{project}/manuals/{manual}/cuts/{cut}/takes/{take}/playback | capture.takes.playback | 画面 | - | S3 S7 | 通常 |
 | app/projects/{project}/manuals/{manual}/cuts/{cut}/takes/{take}/thumbnail | capture.takes.thumbnail | 画面 | - | S3 | 通常 |
 | contact | contact | 画面 | お問い合わせ | S1 | 通常 |
 | contact/thanks | contact.thanks | 画面 | お問い合わせ完了 | S1 | 通常 |
@@ -52,19 +54,19 @@ ## GET × web 一覧 (画面 + 画面に付随する JSON GET)
 | forgot-password | password.request | 画面 | パスワードリセット | S1 | 通常 |
 | reset-password/{token} | password.reset | 画面 | パスワードリセット | S1 | 通常 |
 | pricing | pricing | 画面 | - | S5 | 通常 |
-| projects/{project}/categories | projects.categories.index | 画面 | カテゴリ管理 | S4 | 通常 |
+| projects/{project}/categories | projects.categories.index | 画面 | カテゴリ管理 | S4 S7 | 通常 |
 | projects/create | projects.create | 画面 | プロジェクトの作成 | S4 | 通常 |
-| projects/{project}/edit | projects.edit | 画面 | プロジェクトの編集 | S4 | 通常 |
+| projects/{project}/edit | projects.edit | 画面 | プロジェクトの編集 | S4 S7 | 通常 |
 | projects | projects.index | 画面 | プロジェクト | S4 | 通常 |
 | projects/{project}/manuals/create | projects.manuals.create | 画面 | 動画マニュアルの作成 | S3 | 通常 |
 | projects/{project}/manuals/{manual}/cuts/{cut}/takes | projects.manuals.cuts.takes.index | 画面 | - | S3 | 通常 |
-| projects/{project}/manuals/{manual}/download | projects.manuals.download | 画面 | - | S3 | 通常 |
-| projects/{project}/manuals/{manual}/edit | projects.manuals.edit | 画面 | - | S3 | 通常 |
-| projects/{project}/manuals/{manual}/jobs/{analysisJob} | projects.manuals.jobs.show | 画面 | - | S3 | 通常 |
-| projects/{project}/manuals/{manual}/render-jobs/{renderJob}/playback | projects.manuals.render-jobs.playback | 画面 | - | S3 | 通常 |
-| projects/{project}/manuals/{manual}/render-jobs/{renderJob} | projects.manuals.render-jobs.show | 画面 | - | S3 | 通常 |
-| projects/{project}/manuals/{manual} | projects.manuals.show | 画面 | - | S3 | 通常 |
-| projects/{project} | projects.show | 画面 | - | S3 | 通常 |
+| projects/{project}/manuals/{manual}/download | projects.manuals.download | 画面 | - | S3 S7 | 通常 |
+| projects/{project}/manuals/{manual}/edit | projects.manuals.edit | 画面 | - | S3 S7 | 通常 |
+| projects/{project}/manuals/{manual}/jobs/{analysisJob} | projects.manuals.jobs.show | 画面 | - | S3 S7 | 通常 |
+| projects/{project}/manuals/{manual}/render-jobs/{renderJob}/playback | projects.manuals.render-jobs.playback | 画面 | - | S3 S7 | 通常 |
+| projects/{project}/manuals/{manual}/render-jobs/{renderJob} | projects.manuals.render-jobs.show | 画面 | - | S3 S7 | 通常 |
+| projects/{project}/manuals/{manual} | projects.manuals.show | 画面 | - | S3 S7 | 通常 |
+| projects/{project} | projects.show | 画面 | - | S3 S7 | 通常 |
 | recent-auth/confirm | recent-auth.confirm | 画面 | 本人確認 | S6 | 通常 |
 | recent-auth/status | recent-auth.status | 画面 | - | S6 | 通常 |
 | register | register | 画面 | アカウント登録 | S1 | 通常 |
```
