## 使命 (North Star)

<!-- app-codex-review スキルはこのセクションをレビュー使命として参照する。 -->

**AI-CUE** は、現場に既にある**作業手順書(SOP)を起点に**、AI が撮るべきカットを設計した**動画シナリオ**を生成し、そのシナリオを**スマホ(PWA)でナビゲーション撮影**することで、専門知識ゼロの現場作業者でも**標準化されたマニュアル動画**を作れるようにする。

- 「思考ゼロ・編集ゼロ」— 台本作成・撮影判断・編集の 3 ハードルを AI とナビ撮影で肩代わりする。
- 競合(OJT を撮って形式化する tebiki)と異なり、**標準作業を起点に AI が教材設計し撮影を指示する**（撮影者・教える人のスキルに品質を依存させない）。
- 熟練者の暗黙知を動画マニュアルという形式知へ変換する装置（SECI）。

> 詳細な設計は本リポジトリの `doc/`（01〜10）に置く。ドメインをテンプレート構造へどうマップしたかは `doc/08`〜`doc/10`（特に `doc/10 §10.8` に設計レビュー反映済み）。
> **v1 スコープ**: 字幕のみ(TTS 後回し) / 撮影は PWA(同一オリジン・セッション認証) / 動画合成は自前 ffmpeg / 単一 Default Project。

## 禁止事項

1. テストなしの実装完了報告(不変条件は対応する Architecture/Feature テストへの登録まで含めて「実装済み」)
2. PHPStan エラーの widen(型を緩めて黙らせる)・baseline 化
3. dev DB への破壊操作(`migrate:fresh` 等)をエージェント判断で実行すること
4. `response()->json()` の直書き(DTO / JsonResource / Inertia を使う。仕様固定 endpoint のみ例外)
5. LLM 呼び出しの Prism 直呼び(`app/Prompts/` の factory → 窓口 (`PromptDefense`) →
   実行単位 (`GuardedPrompt`) の**1 本道のみ**。`PromptGuardrailTest` が
   app/ routes/ database/ config/ bootstrap/ の 5 走査根で検出する)。
   **実行経路を持つ prompt factory は `LlmCallContextData` を必須引数で受け、
   `PromptDefense::load()` へ渡して帰属 (organization / subject) を付ける** — 付け忘れは
   PHPStan level 10 が落とす。帰属の対象を持たない見本 (`ExampleSummaryPrompt`) だけが
   `PromptDefense::loadUnattributed()` を使え、窓口 gate が**この 1 件を名指しで pin** する。
   併せて `PromptUntrustedInputContractTest` の inventory へ**帰属キーを空配列で exempt 登録**する
   (deny-by-default なので exempt にする操作がレビューで必ず見える)。
   欠けると `llm_call_logs.metadata_missing` になり組織別・対象別の費用が出せない
6. prompt 文字列のコード直書き(`resources/prompts/*.yaml` に置く)
7. 操作系 POST の応答での `redirect()->intended()`(ログイン直後フロー専用。
   招待送信等は `back()->with(...)` で完結させる)
8. 必須条件未充足を理由にボタンを disabled にする UI(押下時にエラー表示する。DESIGN.md)
9. Artifact の使用(Artifact ツールでの成果物公開を行わない。成果物はリポジトリ内のファイルとして出力する)

【思考原則 — 全議論に適用】
まず仮説を立てろ。何を検証したいのか、なぜそう考えるのか、どうなれば成功と判断するのかを明確にしてから手を動かせ。仮説なき改善はただの試行錯誤であり、結果から学ぶことができない。

データに真摯に向き合え。成果だけでなく、多様性の変化、構造の揺らぎ、想定外のパターン — 全てが判断材料になる。数値を見て即座に閾値を弄るな。何が起きているのかを理解し、なぜそうなったのかを考え、どの方向に進むべきかを判断してから手を動かせ。

先人の知恵を探せ。自分たちだけで登る必要はない。乗るべき巨人の肩があるなら乗れ。

機能の名前に立ち返れ。名前はその機能が果たすべき役割を示している。現在の設計がその役割を果たしているか、常に問え。

仕組みが機能していない段階で値を弄るな。閾値チューニングやフィールド追加は、設計の方向性が正しいと確認できてから行え。方向性が間違っているなら、値をいくら調整しても意味はない。設計そのものを見直せ。成果が出なければ早期に見切り、次の仮説へ進め。

【ツール使用制限】
コマンド実行・ファイル書き込みは一切行わず、提供されたテキストの分析に集中すること。ファイル読み込みは許可。

---

## 役割・タスク

あなたはコードレビュアーである。TODO T224「bug-hunt 裁定 A-001 の監視対象に toast.ts を足す
(再オープン条件と watch_globs の食い違いを閉じる)」の実装差分をレビューする。

対象は Python (stdlib のみ) と JSONL / Markdown のみであり、PHP / TypeScript / Svelte は
1 行も変更していない。レビュー観点は以下:

1. **設計との一致性**: 下記の詳細設計 (施策 8 / 後続タスク節、および保証しないこと節) の
   要求どおりか。特に「A-001 は機械項目・context・移行台帳の鍵・hash pin を一切変更しない」
   「新登録 (A-004) が A-001 を supersede し、修正済み watch_globs と context を持つ」
   「新登録は移行時点に存在しないので machine_projection_sha256 の pin 対象に加えない」
2. **正確性**: JSONL の追記が既存行 (A-001〜A-003) を書き換えていないか、supersede の整合性
   (A-004.supersedes == "A-001")、spec-ledger.md の再生成結果が adjudications.jsonl の内容と
   一致しているか
3. **テスト網羅性**: 新設した fail-first テスト `test_t224_a001_watch_globs.py` が
   「A-001 自身は不変」「A-001 を supersede した active な登録の watch_globs に toast.ts が
   含まれる」の両方を検証しているか。`test_spec_ledger.py` の合成 id 衝突回避
   (`_unused_adjudication_id`) が既存の契約テストの意図を壊していないか
4. **既存契約テストへの無影響**: 118→120 本への増分以外に既存テストの意味を変えていないか

出力形式: ファイルごとに判定 (問題なし / 指摘あり)。指摘は Critical / Warning / Suggestion に
分類。最後に全体判定を **APPROVED** または **CHANGES_REQUESTED** の 1 行で明記すること。

---

## 詳細設計書 (該当節: 施策 8 / 後続タスク、保証しないこと)

設計ファイル全体: devnotes/20260817-1755-bughunt-handover-to-ledger/detailed-design.md
(本レビューに関連する節のみ抜粋)

## 施策 8 / 後続タスク (本設計から必ず起票する)

全テストが緑になったあと、次の 1 件を `docs/TODO.md` へ**必ず登録する**
(`app-todo-add` スキル経由。本設計ディレクトリを設計リンクとして引く)。
**登録しないまま本タスクを閉じない** — 閉じると A-001 の invalidation の穴が追跡から落ちる。
**採番された ID は実装完了報告に書く。**

| 項目 | 内容 |
|---|---|
| タイトル | bug-hunt 裁定 A-001 の監視対象に toast.ts を足す (再オープン条件と watch_globs の食い違いを閉じる) |
| テーマ | test |
| 内容 | A-001 の再オープン条件 (b) は `resources/js/lib/stores/toast.ts` の `AUTO_DISMISS_MS` を挙げているが、`watch_globs` に同ファイルが無く invalidation が発火しない。append-only 規約に従い、**A-001 は機械項目・`context`・移行台帳の鍵・hash の pin をいずれも変更せず**、A-001 を supersede する新登録を 1 行 append して、修正済みの `watch_globs` と必要な `context` を新登録に持たせる。**新登録は移行時点に存在しなかったので `machine_projection_sha256` の pin 対象へ加えない** (pin に無い id は検査対象外)。移行台帳の provenance は「旧 spec-ledger.md のブロックが A-001 へ移った」という事実の記録なので書き換えない |
| 優先度 | Medium |
| モード | standalone |
| 前提 | 本タスク (申し送りの生成物化) が main に入っていること |

## 保証しないこと (誇張しない)

- **CI では 1 つも走らない**。生成物のドリフト・移行の痩せ・掲載の完全性は、
  人が `python3 -m unittest` か `--check` を走らせたときにだけ検出される
  (現行の `test_spec_ledger.py` も同じで、後退ではない)。
  したがって「**登録したのに申し送りに無い状態は起こらない**」とは言えない。
  言えるのは「**正常に再生成された出力では**全登録がちょうど 1 回載る」までである。
- **JSONL の構文エラーは照合器から隔離されない**。隔離されるのは
  「JSON として妥当なまま `context` の形だけが壊れている」場合に限る。
- **経緯の内容が正しいことは検証しない**。機械が見るのは形・全数性・痩せ・drift だけである。
- **`watch_globs` の実在は誰も検査しない** (A-002 が実在しないパスを持ったままである)。
  家系の台帳がこの不足を settle 送りにしているため本タスクでは触らない。
- **A-001 の invalidation の穴を閉じない**: A-001 の再オープン条件 (b) は
  `resources/js/lib/stores/toast.ts` の `AUTO_DISMISS_MS` を挙げているが、
  A-001 の `watch_globs` にこのファイルは無い。したがって
  **`toast.ts` が変わっても照合器の invalidation は発火せず**、
  本物の退行を旧 false-positive として downrank し続けうる。
  直し方は確定している — **A-001 は機械項目・`context`・移行台帳の鍵・`provenance`・
  既存の hash pin をいずれも変更せず**、新登録を 1 行 append して A-001 を supersede し、
  修正済みの `watch_globs` と `context` を新登録に持たせる
  (新登録は移行時点に存在しないので hash pin の対象外)。
  **移行と判断の変更を 1 つの変更に混ぜるとどちらが原因で赤くなったか分からなくなる**ため、
  本タスクでは穴を閉じない。ただし**施策 8 で後続 TODO を必ず登録する** (「候補」では終わらせない)。
- **`spec_basis` のパス実在検査はテストの担当**であり、生成の必須条件ではない。
  テストを走らせない限り腐りは見つからない。
- **原子的書き込みは電源断まで耐えない** (`fsync` していない)。
  保証するのは「通常の失敗では既存ファイルが 1 バイトも変わらない」ことまでである。
</content>

## 実装差分 (git diff)

```diff
diff --git a/.claude/skills/app-bug-hunt/ledger/adjudications.jsonl b/.claude/skills/app-bug-hunt/ledger/adjudications.jsonl
index a1c6e7a4..7cbf2b11 100644
--- a/.claude/skills/app-bug-hunt/ledger/adjudications.jsonl
+++ b/.claude/skills/app-bug-hunt/ledger/adjudications.jsonl
@@ -10,3 +10,4 @@
 {"adjudication_id": "A-001", "species_key": "other:video_manual:delete:self", "scope": {"scope_kind": "route_name", "scope_value": "projects.manuals.destroy"}, "conditions": {"browser": "chromium", "mode": "real-llm"}, "symptom": {"required_tokens": ["delete_success_flash_missing"], "known_tokens": ["toast", "auto_dismiss", "projects_show_redirect"]}, "verdict": "false_positive", "rationale_ref": "devnotes/20260804-0021-ux-small-gaps/detailed-design.md", "source_finding_ids": ["F-1-02"], "adjudicated_at_run": "20260803-203721", "adjudicated_at_commit": "22d6d30", "watch_globs": ["app/Http/Controllers/Projects/VideoManualController.php", "resources/js/components/organisms/ToastContainer.svelte", "resources/js/lib/stores/flash-to-toast.ts"], "review_after_days": 180, "context": {"title": "動画マニュアル削除後に「成功 flash が出ない」ように見えた", "spec_basis": ["app/Http/Controllers/Projects/VideoManualController.php:230-232 削除後 projects.show へ redirect し ->with('success', '動画マニュアルを削除しました')", "resources/js/lib/stores/toast.ts:23-29 success/info/warning は 4000ms で auto-dismiss、error のみ null = 自動消去しない", "resources/js/components/organisms/ToastContainer.svelte role=\"status\" + data-testid=\"toast-{type}\" で描画", "tests/Browser/FlashToastTest.php 着地マーカーと同一時間窓で toast-success が可視になることを Chromium / WebKit の 2 レーンで pin"], "narrative": "**なぜ誤検知に見えたか**: bug-hunt driver の観測は「操作 → 事後 snapshot」の 1 点サンプリングで、Bash 1 往復ぶん (数百 ms〜数秒、並列 shard ではさらに遅延) 後ろにずれる。可視窓 4000ms の後に snapshot が来れば「flash 無し」に見える。T095 の実装フェーズで **現行コードのまま** Browser テストを両レーンで走らせて PASS したため、アプリ側は正しいと確定した。**アプリコードは変更していない。**\n\n**driver 側の再発防止**: `SKILL.md` §一過性フィードバックの観測 — 書き込み操作の**直前**に feedback probe (`.claude/skills/app-bug-hunt/probes/feedback-probe.js`) を仕込み、直後に読む。「事後 snapshot に無い」を根拠に H7 を起票することを禁止した。回帰は `tests/js/bughunt/feedback-probe.test.ts` が固定する。", "reopen_condition": "次のいずれか。(a) VideoManualController::destroy が ->with('success', ...) を落とした、(b) toast.ts の success 用 AUTO_DISMISS_MS が大幅に短縮された、(c) feedback probe が installed_now:false かつ seen(visible:true) / present_new ともに空を返した。**probe を使わない事後 snapshot 単独の観察は再オープン根拠にならない。**"}}
 {"adjudication_id": "A-002", "species_key": "other:organization_member:delete:same_tenant", "scope": {"scope_kind": "route_name", "scope_value": "organizations.members.destroy"}, "conditions": {"browser": "chromium", "mode": "real-llm"}, "symptom": {"required_tokens": ["403_vs_404"], "known_tokens": ["existence_hint", "member_delete"]}, "verdict": "intentional", "rationale_ref": "AGENTS.md セキュリティ不変条件 9 (層 2 テナント境界 = 404 は層 3 認可 = 403 より前)", "source_finding_ids": ["F-3-01"], "adjudicated_at_run": "20260812-100645", "adjudicated_at_commit": "6d0cf1d", "watch_globs": ["app/Http/Controllers/Organizations/ProjectMemberController.php", "app/Http/Controllers/Admin/UserManagementController.php", "app/Policies/OrganizationPolicy.php"], "review_after_days": 180}
 {"adjudication_id": "A-003", "supersedes": "A-002", "species_key": "other:organization_member:delete:same_tenant", "scope": {"scope_kind": "route_name", "scope_value": "organizations.members.destroy"}, "conditions": {"browser": "chromium", "mode": "real-llm"}, "symptom": {"required_tokens": ["403_vs_404"], "known_tokens": ["existence_hint", "member_delete"]}, "verdict": "intentional", "rationale_ref": "AGENTS.md セキュリティ不変条件 9 (層 2 テナント境界 = 404 は層 3 認可 = 403 より前)。同一組織内で権限不足なら 403 が設計どおりで、404 へ潰すと文書化済みの 3 層モデルに反する", "source_finding_ids": ["F-3-01"], "adjudicated_at_run": "20260812-100645", "adjudicated_at_commit": "6d0cf1d", "watch_globs": ["app/Http/Controllers/Organizations/OrganizationMemberController.php", "app/Policies/OrganizationPolicy.php"], "review_after_days": 180, "context": {"title": "同一組織内のメンバー削除で 403 と 404 が分かれ、組織内の id 存在を弱く推測できる", "spec_basis": ["AGENTS.md#セキュリティ不変条件アプリ都合で緩めない 層 2 のテナント境界 404 は層 3 の認可 403 より前 (当時の判断の拠り所)", "devnotes/20260812-100645-bug-hunt/report.md 当該 run の F-3-01 節と事後の決着表 (当時の一次記録)", "devnotes/20260812-100645-bug-hunt/findings-merged.jsonl 当時の機械記録 (species / symptom_tokens / surface / observed_conditions)", "app/Http/Controllers/Organizations/OrganizationMemberController.php 実装時に確認した現行の実装 (当時の判断根拠ではない)", "app/Policies/OrganizationPolicy.php 実装時に確認した現行の実装 (当時の判断根拠ではない)"], "narrative": "**当時の判断 (run 20260812-100645 / commit 6d0cf1d)**: 同一組織内で権限が足りなければ 403 が設計どおりであり、404 へ潰すと文書化済みの 3 層モデル (層 2 のテナント境界 = 404 は層 3 の認可 = 403 より前) に反する。cross-tenant の存在秘匿とは層が違うため、bug-hunt は「バグと断定しない」として needs_spec で挙げ、事後に intentional として登録した。\n\n**この経緯は 2026-08-17 の移行時に、当時の rationale_ref と run 成果物から起こしたものである** (2026-08-12 の時点では人間向けの申し送りが書かれていなかった)。当時確認されていない事実は足していない。", "reopen_condition": "次のいずれか。(a) 403 / 404 の分岐がテナント境界 (層 2) の判定より前で起きるようになった、(b) 同じ差が cross-org からも観測できるようになった、(c) 同一組織内の存在秘匿要件そのものが変わった (組織内でも id の存在を隠す方針になった)、(d) nested route の binding またはテナント境界 404 の実装が変わった。**(b)-(d) に対応する load-bearing なファイルは A-003 の watch_globs に入っていないため、これらの変化は照合器の invalidation では自動検知されない。**"}}
+{"adjudication_id": "A-004", "supersedes": "A-001", "species_key": "other:video_manual:delete:self", "scope": {"scope_kind": "route_name", "scope_value": "projects.manuals.destroy"}, "conditions": {"browser": "chromium", "mode": "real-llm"}, "symptom": {"required_tokens": ["delete_success_flash_missing"], "known_tokens": ["toast", "auto_dismiss", "projects_show_redirect"]}, "verdict": "false_positive", "rationale_ref": "devnotes/20260804-0021-ux-small-gaps/detailed-design.md", "source_finding_ids": ["F-1-02"], "adjudicated_at_run": "20260803-203721", "adjudicated_at_commit": "22d6d30", "watch_globs": ["app/Http/Controllers/Projects/VideoManualController.php", "resources/js/components/organisms/ToastContainer.svelte", "resources/js/lib/stores/flash-to-toast.ts", "resources/js/lib/stores/toast.ts"], "review_after_days": 180, "context": {"title": "動画マニュアル削除後に「成功 flash が出ない」ように見えた (A-001 の watch_globs 是正)", "spec_basis": ["app/Http/Controllers/Projects/VideoManualController.php:230-232 削除後 projects.show へ redirect し ->with('success', '動画マニュアルを削除しました')", "resources/js/lib/stores/toast.ts:23-29 success/info/warning は 4000ms で auto-dismiss、error のみ null = 自動消去しない", "resources/js/components/organisms/ToastContainer.svelte role=\"status\" + data-testid=\"toast-{type}\" で描画", "tests/Browser/FlashToastTest.php 着地マーカーと同一時間窓で toast-success が可視になることを Chromium / WebKit の 2 レーンで pin"], "narrative": "**判定内容は A-001 から変えていない**: verdict / species_key / scope / conditions / symptom / rationale_ref / adjudicated_at_run / adjudicated_at_commit / source_finding_ids はすべて A-001 と同じである。判定そのものが変わったわけではなく、A-001 の登録に技術的な不備があったための是正である。\n\n**是正した理由 (T224)**: A-001 の再オープン条件 (b) は `resources/js/lib/stores/toast.ts` の `AUTO_DISMISS_MS` が大幅に短縮された場合を再オープン根拠として挙げていたが、A-001 の `watch_globs` にこのファイルが含まれておらず、照合器の invalidation (watch_globs の変更検知) が発火しない食い違いがあった。A-001 は append-only 規約により機械項目を書き換えないため、本登録が `watch_globs` へ `resources/js/lib/stores/toast.ts` を足した上で A-001 を supersede する。\n\n**なぜ誤検知に見えたか (A-001 からの引き継ぎ)**: bug-hunt driver の観測は「操作 → 事後 snapshot」の 1 点サンプリングで、Bash 1 往復ぶん (数百 ms〜数秒、並列 shard ではさらに遅延) 後ろにずれる。可視窓 4000ms の後に snapshot が来れば「flash 無し」に見える。T095 の実装フェーズで **現行コードのまま** Browser テストを両レーンで走らせて PASS したため、アプリ側は正しいと確定した。**アプリコードは変更していない。**\n\n**driver 側の再発防止 (A-001 からの引き継ぎ)**: `SKILL.md` §一過性フィードバックの観測 — 書き込み操作の**直前**に feedback probe (`.claude/skills/app-bug-hunt/probes/feedback-probe.js`) を仕込み、直後に読む。「事後 snapshot に無い」を根拠に H7 を起票することを禁止した。回帰は `tests/js/bughunt/feedback-probe.test.ts` が固定する。", "reopen_condition": "次のいずれか。(a) VideoManualController::destroy が ->with('success', ...) を落とした、(b) toast.ts の success 用 AUTO_DISMISS_MS が大幅に短縮された、(c) feedback probe が installed_now:false かつ seen(visible:true) / present_new ともに空を返した。**probe を使わない事後 snapshot 単独の観察は再オープン根拠にならない。**"}}
diff --git a/.claude/skills/app-bug-hunt/ledger/test_spec_ledger.py b/.claude/skills/app-bug-hunt/ledger/test_spec_ledger.py
index f15e297f..0ff9f2af 100644
--- a/.claude/skills/app-bug-hunt/ledger/test_spec_ledger.py
+++ b/.claude/skills/app-bug-hunt/ledger/test_spec_ledger.py
@@ -142,6 +142,15 @@ def _entry_blocks(text: str) -> dict[str, str]:
     return blocks
 
 
+def _unused_adjudication_id(records: list[dict]) -> str:
+    """現物の登録に無い `A-NNN` を 1 つ作る (実登録の増減で合成テストが衝突しないため)。"""
+    used = {r.get("adjudication_id") for r in records}
+    n = 1
+    while f"A-{n:03d}" in used:
+        n += 1
+    return f"A-{n:03d}"
+
+
 class _Stage:
     """入力 2 点の写しを持つ一時作業場。**現物は絶対に書き換えない**。"""
 
@@ -544,14 +553,15 @@ class ListingCompletenessTest(unittest.TestCase):
         """契約 15: 同じ id を差し替える登録が 2 件あれば、両方の id が昇順で出る。"""
         with staged() as stage:
             records = stage.records()
+            new_id = _unused_adjudication_id(records)
             extra = json.loads(json.dumps(stage.record("A-003")))
-            extra["adjudication_id"] = "A-004"
+            extra["adjudication_id"] = new_id
             extra["supersedes"] = "A-002"
             extra.pop("context", None)
             records.append(extra)
             stage.write_records(records)
             blocks = _entry_blocks(stage.build())
-            self.assertIn("A-003 / A-004 に差し替えられた", blocks["A-002"])
+            self.assertIn(f"A-003 / {new_id} に差し替えられた", blocks["A-002"])
 
     def test_broken_supersede_relations_are_rejected(self) -> None:
         """契約 16: 書式不正 / 実在しない id / 自己参照 / 循環はいずれも RenderError。"""
diff --git a/.claude/skills/app-bug-hunt/ledger/test_t224_a001_watch_globs.py b/.claude/skills/app-bug-hunt/ledger/test_t224_a001_watch_globs.py
new file mode 100644
index 00000000..e316de80
--- /dev/null
+++ b/.claude/skills/app-bug-hunt/ledger/test_t224_a001_watch_globs.py
@@ -0,0 +1,77 @@
+"""T224: A-001 の再オープン条件と watch_globs の食い違いを閉じたことの契約テスト (stdlib のみ)。
+
+A-001 の再オープン条件 (b) は `resources/js/lib/stores/toast.ts` の
+`AUTO_DISMISS_MS` を挙げているが、A-001 自身の `watch_globs` にこのファイルは無かった
+(移行時点の登録はそのままにする append-only 規約のため、A-001 は今後も直さない)。
+この登録を supersede する新登録 (A-004) が `watch_globs` に `toast.ts` を持つことを固定する。
+
+実行: python3 -m unittest discover -s .claude/skills/app-bug-hunt/ledger -p 'test_*.py'
+"""
+
+from __future__ import annotations
+
+import unittest
+from pathlib import Path
+
+import render_spec_ledger as renderer
+
+LEDGER_DIR = Path(__file__).resolve().parent
+ADJUDICATIONS = LEDGER_DIR / "adjudications.jsonl"
+
+# A-001 が持っていた種別・対象面 (機械項目は不変。新登録も同じ判定を指す)。
+SPECIES_KEY = "other:video_manual:delete:self"
+SCOPE_VALUE = "projects.manuals.destroy"
+REQUIRED_WATCH_GLOB = "resources/js/lib/stores/toast.ts"
+
+
+class A001WatchGlobsCoverToastTest(unittest.TestCase):
+    def test_active_successor_of_a001_watches_toast_ts(self) -> None:
+        """A-001 と同じ種別・対象面を持つ、現在 active な登録の watch_globs に toast.ts があること。
+
+        A-001 自身は supersede されて履歴化されている前提 (機械項目は書き換えない)。
+        """
+        records = renderer.load_adjudications(str(ADJUDICATIONS))
+        superseded_ids = {r["supersedes"] for r in records if r.get("supersedes")}
+
+        matches = [
+            r
+            for r in records
+            if r["species_key"] == SPECIES_KEY
+            and r["scope"]["scope_value"] == SCOPE_VALUE
+            and r["adjudication_id"] not in superseded_ids
+        ]
+        self.assertEqual(
+            len(matches),
+            1,
+            "同じ種別・対象面の active な登録がちょうど 1 件であること: "
+            f"{[r['adjudication_id'] for r in matches]!r}",
+        )
+        active = matches[0]
+        self.assertIn(
+            REQUIRED_WATCH_GLOB,
+            active["watch_globs"],
+            f"{active['adjudication_id']}: watch_globs に {REQUIRED_WATCH_GLOB} が無い "
+            "(toast.ts の AUTO_DISMISS_MS 変更が invalidation を発火しない)",
+        )
+
+    def test_a001_itself_is_unchanged_and_now_superseded(self) -> None:
+        """A-001 の機械項目は書き換えず、supersede によって履歴化されていること。"""
+        records = {r["adjudication_id"]: r for r in renderer.load_adjudications(str(ADJUDICATIONS))}
+        self.assertIn("A-001", records)
+        a001 = records["A-001"]
+        self.assertEqual(a001["species_key"], SPECIES_KEY)
+        self.assertEqual(
+            a001["watch_globs"],
+            [
+                "app/Http/Controllers/Projects/VideoManualController.php",
+                "resources/js/components/organisms/ToastContainer.svelte",
+                "resources/js/lib/stores/flash-to-toast.ts",
+            ],
+            "A-001 の watch_globs は append-only 規約により書き換えない",
+        )
+        superseded_ids = {r["supersedes"] for r in records.values() if r.get("supersedes")}
+        self.assertIn("A-001", superseded_ids, "A-001 は新登録に supersede されて履歴化されていること")
+
+
+if __name__ == "__main__":
+    unittest.main()
diff --git a/.claude/skills/app-bug-hunt/spec-ledger.md b/.claude/skills/app-bug-hunt/spec-ledger.md
index c04a43f4..7c8bba78 100644
--- a/.claude/skills/app-bug-hunt/spec-ledger.md
+++ b/.claude/skills/app-bug-hunt/spec-ledger.md
@@ -33,7 +33,7 @@ ## 登録一覧 (adjudications.jsonl の可視化)
 <!-- entry: A-001 -->
 ### A-001 — 動画マニュアル削除後に「成功 flash が出ない」ように見えた
 
-- 有効性: **active**
+- 有効性: **superseded** (A-004 に差し替えられた。判断の正本は後継)
 - 由来 finding: F-1-02
 - 判定: false_positive / 対象面: route_name=projects.manuals.destroy
 - 確定: run 20260803-203721 (commit 22d6d30) / 見直し期限: 180 日
@@ -68,6 +68,25 @@ ### A-003 — 同一組織内のメンバー削除で 403 と 404 が分かれ
 
 **この経緯は 2026-08-17 の移行時に、当時の rationale_ref と run 成果物から起こしたものである** (2026-08-12 の時点では人間向けの申し送りが書かれていなかった)。当時確認されていない事実は足していない。
 
+<!-- entry: A-004 -->
+### A-004 — 動画マニュアル削除後に「成功 flash が出ない」ように見えた (A-001 の watch_globs 是正)
+
+- 有効性: **active**
+- 差し替え: A-001 を差し替えた
+- 由来 finding: F-1-02
+- 判定: false_positive / 対象面: route_name=projects.manuals.destroy
+- 確定: run 20260803-203721 (commit 22d6d30) / 見直し期限: 180 日
+- 仕様根拠: app/Http/Controllers/Projects/VideoManualController.php:230-232 削除後 projects.show へ redirect し ->with('success', '動画マニュアルを削除しました') ; resources/js/lib/stores/toast.ts:23-29 success/info/warning は 4000ms で auto-dismiss、error のみ null = 自動消去しない ; resources/js/components/organisms/ToastContainer.svelte role="status" + data-testid="toast-{type}" で描画 ; tests/Browser/FlashToastTest.php 着地マーカーと同一時間窓で toast-success が可視になることを Chromium / WebKit の 2 レーンで pin
+- 再オープン条件: 次のいずれか。(a) VideoManualController::destroy が ->with('success', ...) を落とした、(b) toast.ts の success 用 AUTO_DISMISS_MS が大幅に短縮された、(c) feedback probe が installed_now:false かつ seen(visible:true) / present_new ともに空を返した。**probe を使わない事後 snapshot 単独の観察は再オープン根拠にならない。**
+
+**判定内容は A-001 から変えていない**: verdict / species_key / scope / conditions / symptom / rationale_ref / adjudicated_at_run / adjudicated_at_commit / source_finding_ids はすべて A-001 と同じである。判定そのものが変わったわけではなく、A-001 の登録に技術的な不備があったための是正である。
+
+**是正した理由 (T224)**: A-001 の再オープン条件 (b) は `resources/js/lib/stores/toast.ts` の `AUTO_DISMISS_MS` が大幅に短縮された場合を再オープン根拠として挙げていたが、A-001 の `watch_globs` にこのファイルが含まれておらず、照合器の invalidation (watch_globs の変更検知) が発火しない食い違いがあった。A-001 は append-only 規約により機械項目を書き換えないため、本登録が `watch_globs` へ `resources/js/lib/stores/toast.ts` を足した上で A-001 を supersede する。
+
+**なぜ誤検知に見えたか (A-001 からの引き継ぎ)**: bug-hunt driver の観測は「操作 → 事後 snapshot」の 1 点サンプリングで、Bash 1 往復ぶん (数百 ms〜数秒、並列 shard ではさらに遅延) 後ろにずれる。可視窓 4000ms の後に snapshot が来れば「flash 無し」に見える。T095 の実装フェーズで **現行コードのまま** Browser テストを両レーンで走らせて PASS したため、アプリ側は正しいと確定した。**アプリコードは変更していない。**
+
+**driver 側の再発防止 (A-001 からの引き継ぎ)**: `SKILL.md` §一過性フィードバックの観測 — 書き込み操作の**直前**に feedback probe (`.claude/skills/app-bug-hunt/probes/feedback-probe.js`) を仕込み、直後に読む。「事後 snapshot に無い」を根拠に H7 を起票することを禁止した。回帰は `tests/js/bughunt/feedback-probe.test.ts` が固定する。
+
 ---
 
 ## 移行の全数性 (機械可読)
```

## テスト結果

- `python3 -m unittest discover -s .claude/skills/app-bug-hunt/ledger -p 'test_*.py'`: Ran 120 tests, OK (全緑)
- `python3 .claude/skills/app-bug-hunt/ledger/render_spec_ledger.py --check`: exit 0 (drift なし)
- `python3 ledger/validate_findings.py ledger/example.findings.jsonl --adjudications ledger/adjudications.jsonl`: errors 0
- composer test: 6149 tests, 6147 passed, 2 skipped, 0 failed (PHP は無変更なので既存結果と整合)
- composer phpstan: No errors
- vendor/bin/pint --test: passed
- pnpm lint / typecheck / test / build / typecheck:packages / build:packages / test:packages: 全て EXIT=0
