【アプリの使命 (North Star)】

**AI-CUE** は、現場に既にある**作業手順書(SOP)を起点に**、AI が撮るべきカットを設計した**動画シナリオ**を生成し、そのシナリオを**スマホ(PWA)でナビゲーション撮影**することで、専門知識ゼロの現場作業者でも**標準化されたマニュアル動画**を作れるようにする。

- 「思考ゼロ・編集ゼロ」— 台本作成・撮影判断・編集の 3 ハードルを AI とナビ撮影で肩代わりする。
- 競合(OJT を撮って形式化する tebiki)と異なり、**標準作業を起点に AI が教材設計し撮影を指示する**（撮影者・教える人のスキルに品質を依存させない）。
- 熟練者の暗黙知を動画マニュアルという形式知へ変換する装置（SECI）。

【禁止事項】

1. テストなしの実装完了報告(不変条件は対応する Architecture/Feature テストへの登録まで含めて「実装済み」)
2. PHPStan エラーの widen(型を緩めて黙らせる)・baseline 化
3. dev DB への破壊操作(`migrate:fresh` 等)をエージェント判断で実行すること
4. `response()->json()` の直書き(DTO / JsonResource / Inertia を使う。仕様固定 endpoint のみ例外)
5. LLM 呼び出しの Prism 直呼び
6. prompt 文字列のコード直書き(`resources/prompts/*.yaml` に置く)
7. 操作系 POST の応答での `redirect()->intended()`
8. 必須条件未充足を理由にボタンを disabled にする UI
9. Artifact の使用

【思考原則 — 全議論に適用】
まず仮説を立てろ。何を検証したいのか、なぜそう考えるのか、どうなれば成功と判断するのかを明確にしてから手を動かせ。仮説なき改善はただの試行錯誤であり、結果から学ぶことができない。

データに真摯に向き合え。成果だけでなく、多様性の変化、構造の揺らぎ、想定外のパターン — 全てが判断材料になる。数値を見て即座に閾値を弄るな。何が起きているのかを理解し、なぜそうなったのかを考え、どの方向に進むべきかを判断してから手を動かせ。

先人の知恵を探せ。自分たちだけで登る必要はない。乗るべき巨人の肩があるなら乗れ。

機能の名前に立ち返れ。名前はその機能が果たすべき役割を示している。現在の設計がその役割を果たしているか、常に問え。

仕組みが機能していない段階で値を弄るな。閾値チューニングやフィールド追加は、設計の方向性が正しいと確認できてから行え。方向性が間違っているなら、値をいくら調整しても意味はない。設計そのものを見直せ。成果が出なければ早期に見切り、次の仮説へ進め。

【ツール使用制限】
コマンド実行・ファイル書き込みは一切行わず、提供されたテキストの分析に集中すること。ファイル読み込みは許可。

---

## system: 役割

あなたは Laravel + Svelte アプリのコードレビュアーである。以下の実装差分を、詳細設計書との一致性の観点でレビューせよ。

レビュー観点:
- 設計との一致性 (詳細設計書の施策 1〜7 が過不足なく実装されているか)
- 正確性 (Python の検証ロジックに穴・迂回路・偽陽性が無いか)
- PHPStan level 10 適合性 / strict_types / 日本語コメント
- テスト網羅性 (deny-by-default になっているか、空振りする検査が無いか)
- 保証範囲の記述が実装より広く書かれていないか (誇張の検出)
- セキュリティ (パス検証の迂回、symlink、fail-open)

出力形式: ファイルごとに判定を書き、指摘を [Critical] / [Warning] / [Suggestion] に分類し、
最後に全体判定を **APPROVED** または **CHANGES_REQUESTED** で明示すること。

## 前提 (レビュー時に踏まえること)

- 詳細設計書の全文はリポジトリの `devnotes/20260818-0243-bughunt-coverage-audit-doc/detailed-design.md` にある (必要なら読んでよい)。
- 本施策は製品コード (`app/`) の振る舞いを変えない。`app/` に触れるのは middleware の docblock (コメント) 1 ファイルのみである。
- Python は標準ライブラリのみという既存規約がある。
- 検証結果: `vendor/bin/pint --test` = passed / `composer phpstan` = No errors /
  coverage ディレクトリの `python3 -m unittest` (test_correlate / test_build_executed / test_naming_no_stale / test_out_of_scope / test_merge_pcov) = 140 tests OK /
  `scripts/bug-hunt-inventory-check.sh` = exit 0。`composer test` は実行中。

---

## user: 実装差分 (git diff)

```diff
diff --git a/.claude/skills/app-bug-hunt/coverage-audit.md b/.claude/skills/app-bug-hunt/coverage-audit.md
new file mode 100644
index 0000000..da26d9e
--- /dev/null
+++ b/.claude/skills/app-bug-hunt/coverage-audit.md
@@ -0,0 +1,64 @@
+# カバレッジ網羅監査 (静的な棚卸し)
+
+bug-hunt の網羅性を静的に棚卸しするための文書。**走行のたびに機械で出せるもの
+(分母の件数・画面や操作の一覧・route 名・シナリオの割当・まだ通れていないものの一覧) は
+ここに書かない** — それらは `scripts/bug-hunt-inventory-check.sh` と `coverage/correlate.py` /
+`coverage/merge_pcov.py` の出力が正本であり、手で写すと必ず腐る。
+この文書が扱うのは**実装から導けない人の判断**、すなわち
+「設計上ブラウザでは検査できない面はどれで、なぜか、代わりに何で検査するか」だけである。
+
+> 走行ごとの突合 (`coverage/`) との役割分担は `coverage/README.md` を参照。
+> 過去の監査の実測値は git 履歴と `devnotes/{run}-bug-hunt/report.md` に残る。
+
+## 対象外の正本は 2 本ある (軸が違う)
+
+| 軸 | 単位 | 正本 |
+|---|---|---|
+| 探索の分母 (どの操作・画面を走るか) | route 名 | `inventory/annotations.toml` の区分 外 と理由 |
+| コード到達 (どのコードが未到達でよいか) | `app/` 配下のパス | `coverage/out-of-scope.json` |
+
+同じ面が両方に現れることはある。問いが違うからで、二重管理ではない。
+
+## コード到達の対象外を読む
+
+    python3 .claude/skills/app-bug-hunt/coverage/out_of_scope.py --emit markdown
+
+宣言の妥当性の検査 (読み取り器の自己テスト):
+
+    cd .claude/skills/app-bug-hunt/coverage && python3 -m unittest test_out_of_scope
+
+## 対象外を増やすとき
+
+1. `coverage/out-of-scope.json` に面を足す。`reason` (なぜブラウザ走行では検査できないか) と
+   `alternative_verification` (代わりに何が検査するか) と `verification_refs` (その実体のパス) は必須。
+2. 自己テストの承認済み範囲のスナップショットを同じ変更で更新する
+   (更新しないと赤になる = 追加は必ずレビューに出る)。
+3. `composer test --filter=BughuntCoverageToolSelfTest` を通す。
+
+**対象外を増やすことは、監査のうえで「未到達の穴」として扱う範囲を縮めることである**
+(集計器の分母と出力は変わらない。変わるのは人がどこを穴と読むかである)。
+理由と代替検証が書けないなら、それは対象外ではなく**まだ埋めていない穴**であり、
+次の走行で埋める対象として残す。理由として認めるのは次の 3 型だけである。
+
+| 型 | 意味 |
+|---|---|
+| 利用者が到達しない面 | 製品の利用者が触る導線に存在しない (社内運用向け・走行専用の代替) |
+| ブラウザ操作では発火しない面 | 機械が接続する面や署名付きの受信要求で、画面の操作からは起動しない |
+| 計測の到達点の外 | 実際には動くが、収集の仕組みの外側で動くため行の到達として現れない |
+
+## 計測の癖 (未到達を誤読しないために)
+
+コード到達は **serve のプロセスでしか採れない**。別プロセスで走る実行単位は、
+bug-hunt が実際に動かしていても未到達として現れる。**動いていないのではなく見えていない**。
+
+## この文書が保証しないもの
+
+- 機械が見るのは宣言の**形式**と参照先の**実在**まで (`composer test` の下では
+  **追跡下かどうか**も見る) である。代替検証がその面を本当に守っているか
+  (テストの意味的十分性) は人のレビューの担当である。
+- 集計器 (`merge_pcov.py`) は宣言を読まない。まだ通れていないものの一覧と宣言の突合は
+  **人が読んで行う**。
+- `verification_refs` にディレクトリを書いた面では、その中のファイルが 1 本消えても
+  気付けない (消えるのを検出できるのはディレクトリごと消えたときである)。
+- 古い断定の再混入を見る走査は、このスキル配下の文書と道具だけが射程である
+  (`app/` のコメントは見ない)。
diff --git a/.claude/skills/app-bug-hunt/coverage/README.md b/.claude/skills/app-bug-hunt/coverage/README.md
index fceb802..1197bd7 100644
--- a/.claude/skills/app-bug-hunt/coverage/README.md
+++ b/.claude/skills/app-bug-hunt/coverage/README.md
@@ -6,15 +6,30 @@ # Bug-hunt Coverage (操作到達カバレッジ / コード到達カバレッ
 **絶対 % は副**（`*_pct` に添えるだけ・目標にしない＝gaming 防止）。
 「機能カバレッジ%」「品質保証%」という表現は出力にもこの README にも書かない。
 
-> 静的棚卸しの `coverage-audit.md`（route/operation の机上対応表）とは役割が違う。
+> 静的棚卸しの `coverage-audit.md` とは役割が違う。あちらが扱うのは
+> **コード到達で対象外と判断する面・その理由・代替検証**（実装から導けない人の判断）で、
+> 面の一覧そのものは `coverage/out-of-scope.json` が正本である。
 > こちらは **run 突合の動的 proxy**（実際に走った run の結果と機構分母を突き合わせる）。
-> audit = 静的棚卸し / `coverage/` = run 突合の動的 proxy、と区別すること。
+> 3 者の責務分担はこう分かれている:
+>
+> | 文書 / 道具 | 扱うもの | 単位 |
+> |---|---|---|
+> | `coverage-audit.md` + `coverage/out-of-scope.json` | コード到達で対象外と判断する面の理由と代替検証 | `app/` 配下のパス |
+> | `inventory/annotations.toml` | 探索の分母から外す操作・画面 | route 名 |
+> | `correlate.py` / `merge_pcov.py` | 走行ごとの突合結果 | run |
 
 ## 正直な前提（最重要・読み飛ばさない）
 
-- **pcov は本環境未導入**。コード到達カバレッジ (merge_pcov.py) は pcov 非依存の純ロジック
+- **拡張の有無と収集の有効化は別の話である**。開発コンテナ（`docker/Dockerfile`）では pcov を
+  使える。bug-hunt は **serve の起動時にだけ**収集を有効にする（`scripts/bug-hunt-shard.sh` が
+  `PHP_INI_SCAN_DIR` と `BUGHUNT_PCOV` を serve の起動行へ渡す）。
+  一方で**本リポジトリには、CI または本番でコード到達の収集を有効にする構成が存在しない**
+  （CI の workflow に pcov の導入記述は無く、デプロイ定義そのものが無い）。
+  リポジトリの外にある本番構成がどうなっているかは**分からない**。
+  したがって**拡張の有無に関わらず、設定 (`config('bughunt.pcov.enabled')`) と
+  関数の存在 (`function_exists('\pcov\start')`) の二重 guard は引き続き必要**である。
+- コード到達カバレッジ (merge_pcov.py) は pcov 非依存の純ロジック
   (入力は C3 middleware 出力形の JSON) であり、テストは fixture の shard を union して検証する。
-  pcov を入れたら C3/C4/C5 の end-to-end を実機で検証してから運用する。
 - **graph の TESTED_BY は TypeScript 専用**。`/workspace/.code-review-graph/graph.db` 実測
   (2026-06-20): **TESTED_BY=15787 全て TS、PHP(.php::)=0**。
   → PHP web route の TESTED_BY は **「false」ではなく `unknown_graph_gap`**（unknown）として扱う。
@@ -179,7 +194,8 @@ ### 出力の読み方（主＝未カバー worklist、% は副）
 ## コード到達カバレッジ（code-reach / pcov / merge_pcov.py、`--coverage` 時限定）
 
 実装到達カバレッジを行レベルで採る。**既定 OFF**。`--coverage` フラグ到達時のみ使う。
-**pcov 未導入のため本環境では実 coverage が出ない** → merge は fixture で検証する純ロジック。
+merge は pcov 非依存の純ロジックなので、単体では fixture の shard で検証する
+（二重 guard を満たさない環境では middleware が完全 no-op になり、入力そのものが出ない）。
 
 ### 収集 → merge の流れ（pcov 導入時）
 
diff --git a/.claude/skills/app-bug-hunt/coverage/correlate.py b/.claude/skills/app-bug-hunt/coverage/correlate.py
index 45d0045..ee2307e 100644
--- a/.claude/skills/app-bug-hunt/coverage/correlate.py
+++ b/.claude/skills/app-bug-hunt/coverage/correlate.py
@@ -21,7 +21,7 @@ findings.jsonl / graph.db(TESTED_BY) を join し、**未カバー worklist** 
   - route 名は graph に無い。route -> graph の join は action(FQCN@method) ->
     controller ファイル相対パス -> graph node の file_path 経由で行う。
     action='Closure' / null は join 不能 = unknown_graph_gap。
-  - pcov 未導入のため本コンポーネントは pcov 非依存 (executed.json は別途記録)。
+  - 本コンポーネントは pcov 非依存 (executed.json は別途記録)。
 
 依存は標準ライブラリのみ。参考スタイル: ledger/findings.schema.json (finding 形)。
 
diff --git a/.claude/skills/app-bug-hunt/coverage/merge_pcov.py b/.claude/skills/app-bug-hunt/coverage/merge_pcov.py
index 58e5571..4d74a5c 100644
--- a/.claude/skills/app-bug-hunt/coverage/merge_pcov.py
+++ b/.claude/skills/app-bug-hunt/coverage/merge_pcov.py
@@ -6,10 +6,10 @@ C3 middleware (BughuntCoverageMiddleware) が per-request で書き出す JSONL
 **未カバー (uncovered) を主出力** とする。絶対 % (line_pct) は補助フィールドに
 添えるのみで目標にしない (gaming 防止 / 命名「コード到達カバレッジ」)。
 
-HONEST 注記: 本環境は pcov 未導入のため実 coverage は取得できない。
-本スクリプトは pcov 非依存の純ロジック (入力は C3 出力形の JSON) であり、
-テストは fixture の shard を union して検証する。app の shard は 0-4
-(直列 shard-0 :8010 / 並列 shard-1..4 :8011..8014)。
+HONEST 注記: 本スクリプトは pcov 非依存の純ロジック (入力は C3 出力形の JSON) であり、
+テストは fixture の shard を union して検証する。収集の有効化は serve 側の話で、
+二重 guard (設定 + 関数の存在) を満たさない環境では入力そのものが出ない。
+app の shard は 0-4 (直列 shard-0 :8010 / 並列 shard-1..4 :8011..8014)。
 
 依存は標準ライブラリのみ (json, argparse, glob, sys, pathlib, dataclasses)。
 
diff --git a/.claude/skills/app-bug-hunt/coverage/out-of-scope.json b/.claude/skills/app-bug-hunt/coverage/out-of-scope.json
new file mode 100644
index 0000000..b1fbbc0
--- /dev/null
+++ b/.claude/skills/app-bug-hunt/coverage/out-of-scope.json
@@ -0,0 +1,82 @@
+{
+  "version": 1,
+  "note": "bug-hunt のコード到達カバレッジで未到達でよい面の唯一の正本。人の判断であり機械生成しない。理由として認めるのは 3 型 (利用者が到達しない面 / ブラウザ操作では発火しない面 / 計測の到達点の外) だけで、それ以外は穴として残す。",
+  "entries": [
+    {
+      "id": "filament-admin",
+      "title": "運営向け Filament 管理画面",
+      "reason": "運営 (社内) 向けの管理画面であり、現場作業者が手順書から動画を作る導線ではない。探索ストーリー S1..S7 はいずれもこの面を走らない設計になっている。",
+      "alternative_verification": "画面の表示と認可の実挙動を tests/Feature/Filament と tests/Feature/Admin の Feature テストが検査する。",
+      "verification_refs": ["tests/Feature/Filament", "tests/Feature/Admin"],
+      "path_prefixes": ["app/Filament", "app/Providers/Filament", "app/Http/Controllers/Admin"]
+    },
+    {
+      "id": "seo-static-delivery",
+      "title": "クローラ向けの静的配信",
+      "reason": "認証もセッションも持たない機械可読の配信で、人が操作する画面ではない。目録でも区分 外 として探索の分母から外している。",
+      "alternative_verification": "配信内容とヘッダを tests/Feature/Seo の Feature テストが検査する。",
+      "verification_refs": ["tests/Feature/Seo"],
+      "path_prefixes": ["app/Http/Controllers/Seo", "app/Providers/SeoServiceProvider.php"]
+    },
+    {
+      "id": "inbound-webhook",
+      "title": "外部サービスから届く受信通知",
+      "reason": "外部サービスが送ってくる受信要求で、署名検証が前提のためブラウザ操作からは発火しない。",
+      "alternative_verification": "署名検証と副作用を tests/Feature/Mail の受信通知まわりの Feature テストが検査する。",
+      "verification_refs": [
+        "tests/Feature/Mail/SesNotificationControllerTest.php",
+        "tests/Feature/Mail/SesSignatureMiddlewareTest.php"
+      ],
+      "path_prefixes": ["app/Http/Controllers/Webhooks"]
+    },
+    {
+      "id": "mcp-oauth-interface",
+      "title": "外部クライアントが接続する MCP と OAuth 認可の面",
+      "reason": "外部の機械が接続する機械間の面で、ブラウザの画面と操作として存在しない。",
+      "alternative_verification": "接続の流れと道具の契約を tests/Feature/Mcp の Feature テストが検査する。",
+      "verification_refs": ["tests/Feature/Mcp"],
+      "path_prefixes": ["app/Mcp", "app/Passport"]
+    },
+    {
+      "id": "rest-api",
+      "title": "API キーで認証する機械向けの REST 面",
+      "reason": "セッションではなく API キーで認証する機械向けの面で、ブラウザのセッションからはたどれない。",
+      "alternative_verification": "認証と冪等性と応答契約を tests/Feature/Api の Feature テストが検査する。",
+      "verification_refs": ["tests/Feature/Api"],
+      "path_prefixes": ["app/Http/Controllers/Api"]
+    },
+    {
+      "id": "artisan-command",
+      "title": "手動と定時で走る artisan コマンド",
+      "reason": "コマンドは serve とは別のプロセスで走るため、走行中に実行されても行の到達として記録されない (計測の到達点の外)。",
+      "alternative_verification": "各コマンドの挙動を tests/Feature/Console の Feature テストが検査する。",
+      "verification_refs": ["tests/Feature/Console"],
+      "path_prefixes": ["app/Console"]
+    },
+    {
+      "id": "queued-job",
+      "title": "キューのワーカーが処理する実行単位",
+      "reason": "ワーカーは serve とは別のプロセスで走るため、走行中に実際へ処理されても行の到達として記録されない (計測の到達点の外)。動いていないのではなく見えていない。",
+      "alternative_verification": "待ち時間の扱いと重複実行の目録を tests/Feature/Queue と tests/Architecture/JobExecutionDedupInventoryTest.php が検査する。",
+      "verification_refs": [
+        "tests/Feature/Queue",
+        "tests/Architecture/JobExecutionDedupInventoryTest.php"
+      ],
+      "path_prefixes": ["app/Jobs"]
+    },
+    {
+      "id": "bughunt-external-fake",
+      "title": "bug-hunt 専用の外部代替 (保存先の偽物)",
+      "reason": "bug-hunt 環境でだけ差し替わる外部代替であり、製品の利用者が触る面ではない。",
+      "alternative_verification": "差し替えの配線と条件を tests/Feature/Providers と tests/Architecture/ExternalFakeWiringInvariantTest.php が検査する。",
+      "verification_refs": [
+        "tests/Feature/Providers/BughuntFakesServiceProviderTest.php",
+        "tests/Architecture/ExternalFakeWiringInvariantTest.php"
+      ],
+      "path_prefixes": [
+        "app/Http/Controllers/Testing",
+        "app/Providers/BughuntFakesServiceProvider.php"
+      ]
+    }
+  ]
+}
diff --git a/.claude/skills/app-bug-hunt/coverage/out_of_scope.py b/.claude/skills/app-bug-hunt/coverage/out_of_scope.py
new file mode 100644
index 0000000..32ea029
--- /dev/null
+++ b/.claude/skills/app-bug-hunt/coverage/out_of_scope.py
@@ -0,0 +1,416 @@
+#!/usr/bin/env python3
+"""bug-hunt コード到達カバレッジの「対象外の面」の宣言を読み、検証し、出力する道具。
+
+宣言 (out-of-scope.json) が答える問いは 1 つだけである:
+    「この範囲のコードがコード到達カバレッジで未到達でも、なぜ穴ではないのか。
+      代わりに何が検査しているのか」
+
+本モジュールは宣言を **検証済みの型** (OutOfScopeDeclaration) へ変換して返す。
+生の dict を持ち回らない。検証に通らない宣言は DeclarationError で落ち、CLI としては
+**終了コード 2 かつ標準出力へ 1 バイトも書かない** (fail-closed)。
+
+パスの検査は 2 層に分かれている。層を混ぜると covers() が repo_root を要求する形になり、
+公開インターフェースと契約が食い違うためである。
+
+  層 1 (normalize) … リポジトリに依存しない字句の正規形。実在検査・包含検査・
+                     循環検査・covers() のすべてがこの 1 本を共用する。
+  層 2 (load)     … repo_root を基点にした実在・境界・symlink の検査。
+
+依存は標準ライブラリのみ。使い方:
+    python3 out_of_scope.py --emit markdown
+    python3 out_of_scope.py --emit json
+"""
+
+from __future__ import annotations
+
+import argparse
+import json
+import re
+import sys
+from dataclasses import dataclass
+from pathlib import Path, PurePosixPath
+
+# 宣言の版。増やすときは読み取り側の分岐ではなく移行を書く。
+DECLARATION_VERSION = 1
+
+# 理由と代替検証の最小長。inventory/annotations.toml の区分「外」の理由と同じ閾値を使う
+# (同じ判断に別の閾値を置くと説明できなくなる)。
+MIN_STATEMENT_LENGTH = 30
+
+# 「書けば通る」空洞化を防ぐための無内容な値 (trim 後の完全一致で拒否する)。
+HOLLOW_STATEMENTS = frozenset({"対象外", "なし", "-", "N/A", "TBD"})
+
+ID_PATTERN = re.compile(r"^[a-z0-9][a-z0-9-]*$")
+
+TOP_LEVEL_KEYS = frozenset({"version", "note", "entries"})
+ENTRY_KEYS = frozenset(
+    {
+        "id",
+        "title",
+        "reason",
+        "alternative_verification",
+        "verification_refs",
+        "path_prefixes",
+    }
+)
+
+# 対象パスの根。app/ の外は本宣言の管轄ではない。
+PATH_PREFIX_ROOT = ("app",)
+
+# 一撃で全体を対象外にする幹。規則を推測させないため明示的な禁止集合との完全一致で判定する。
+TRUNK_PREFIXES = frozenset(
+    {
+        ("app",),
+        ("app", "Http"),
+        ("app", "Http", "Controllers"),
+    }
+)
+
+# 自己言及の禁止先 (宣言自身と監査文書)。
+DECLARATION_REL_PATH = ".claude/skills/app-bug-hunt/coverage/out-of-scope.json"
+AUDIT_DOC_REL_PATH = ".claude/skills/app-bug-hunt/coverage-audit.md"
+
+DEFAULT_DECLARATION = Path(__file__).resolve().parent / "out-of-scope.json"
+# coverage -> app-bug-hunt -> skills -> .claude -> リポジトリルート
+DEFAULT_REPO_ROOT = Path(__file__).resolve().parents[4]
+
+
+class DeclarationError(Exception):
+    """宣言が契約を満たさない (読み込み失敗・書式違反・境界違反をすべて含む)。"""
+
+
+@dataclass(frozen=True)
+class OutOfScopeEntry:
+    """対象外の面 1 件。理由か代替検証が違うなら別の面である。"""
+
+    id: str
+    title: str
+    reason: str
+    alternative_verification: str
+    verification_refs: tuple[str, ...]
+    path_prefixes: tuple[str, ...]
+
+
+@dataclass(frozen=True)
+class OutOfScopeDeclaration:
+    """検証済みの宣言全体。entries の並び順は宣言の並び順を保つ。"""
+
+    version: int
+    note: str
+    entries: tuple[OutOfScopeEntry, ...]
+
+
+def normalize(raw: object) -> tuple[str, ...]:
+    """層 1: 正規形の相対パスをパス要素の列にする。非正規形は DeclarationError。
+
+    PurePosixPath へ入れた後では `a//b` や `.` が畳まれて元の非正規形を検出できないため、
+    変換より前に生の文字列のまま拒否する。
+    """
+    if not isinstance(raw, str):
+        raise DeclarationError("パスは文字列である必要がある")
+    if raw.strip() == "":
+        raise DeclarationError("パスが空である")
+    if "\\" in raw:
+        raise DeclarationError(f"パスにバックスラッシュがある: {raw}")
+    if raw.startswith("/"):
+        raise DeclarationError(f"絶対パスは書けない: {raw}")
+    if raw.endswith("/"):
+        raise DeclarationError(f"末尾スラッシュは書けない: {raw}")
+
+    segments = raw.split("/")
+    for segment in segments:
+        if segment.strip() == "":
+            raise DeclarationError(f"空のパス要素がある: {raw}")
+        if segment in (".", ".."):
+            raise DeclarationError(f"相対指定は書けない: {raw}")
+
+    parts = PurePosixPath(raw).parts
+    if parts != tuple(segments):
+        raise DeclarationError(f"正規形の相対パスではない: {raw}")
+
+    return parts
+
+
+def covers(declaration: OutOfScopeDeclaration, rel_path: str) -> OutOfScopeEntry | None:
+    """そのパスを覆う面を返す (無ければ None)。層 1 だけを使い repo_root を要求しない。
+
+    宣言は antichain (どの対象パスも他を包含しない) なので結果は並び順に依存しない。
+    """
+    target = normalize(rel_path)
+    for entry in declaration.entries:
+        for prefix in entry.path_prefixes:
+            parts = normalize(prefix)
+            if target[: len(parts)] == parts:
+                return entry
+
+    return None
+
+
+def load(path: Path | str, repo_root: Path | str) -> OutOfScopeDeclaration:
+    """宣言を読み、層 1 と層 2 の両方を検証して返す。"""
+    declaration_path = Path(path)
+    root = Path(repo_root).resolve()
+
+    raw = _read_json(declaration_path)
+    entries = _build_entries(raw)
+    _reject_overlapping_prefixes(entries)
+    _verify_against_repository(entries, root)
+
+    return OutOfScopeDeclaration(
+        version=DECLARATION_VERSION,
+        note=_require_text(raw["note"], "note"),
+        entries=entries,
+    )
+
+
+def render_markdown(declaration: OutOfScopeDeclaration) -> str:
+    """人が読む表を作る (値に含まれる区切りと改行は退避する)。"""
+    header = "| id | 面 | ブラウザ走行では検査できない理由 | 代替検証 | 対象パス |"
+    lines = [header, "|---|---|---|---|---|"]
+    for entry in declaration.entries:
+        alternative = (
+            entry.alternative_verification + " 参照: " + " / ".join(entry.verification_refs)
+        )
+        cells = [
+            _markdown_cell(entry.id),
+            _markdown_cell(entry.title),
+            _markdown_cell(entry.reason),
+            _markdown_cell(alternative),
+            _markdown_cell(" / ".join(entry.path_prefixes)),
+        ]
+        lines.append("| " + " | ".join(cells) + " |")
+
+    return "\n".join(lines) + "\n"
+
+
+def render_json(declaration: OutOfScopeDeclaration) -> str:
+    """正規化済みトップレベル object を返す (宣言の並び順を保つ)。"""
+    payload = {
+        "version": declaration.version,
+        "note": declaration.note,
+        "entries": [
+            {
+                "id": entry.id,
+                "title": entry.title,
+                "reason": entry.reason,
+                "alternative_verification": entry.alternative_verification,
+                "verification_refs": list(entry.verification_refs),
+                "path_prefixes": list(entry.path_prefixes),
+            }
+            for entry in declaration.entries
+        ],
+    }
+
+    return json.dumps(payload, ensure_ascii=False, indent=2) + "\n"
+
+
+def main(argv: list[str] | None = None) -> int:
+    parser = argparse.ArgumentParser(
+        description="bug-hunt コード到達カバレッジの対象外宣言を検証して出力する",
+    )
+    parser.add_argument("--declaration", default=str(DEFAULT_DECLARATION), help="宣言ファイル")
+    parser.add_argument("--repo-root", default=str(DEFAULT_REPO_ROOT), help="実在検査の基点")
+    parser.add_argument("--emit", choices=("markdown", "json"), default="markdown")
+    args = parser.parse_args(argv)
+
+    try:
+        declaration = load(args.declaration, args.repo_root)
+        # 出力は組み立て切ってから書く (途中で落ちて標準出力を汚さないため)。
+        rendered = render_markdown(declaration) if args.emit == "markdown" else render_json(declaration)
+    except DeclarationError as error:
+        sys.stderr.write(_single_line(f"対象外宣言が不正です: {error}") + "\n")
+
+        return 2
+
+    sys.stdout.write(rendered)
+
+    return 0
+
+
+def _read_json(declaration_path: Path) -> dict:
+    """読み込みと parse の失敗を理由を問わず DeclarationError へ落とす。"""
+    try:
+        text = declaration_path.read_text(encoding="utf-8")
+    except (OSError, UnicodeError) as error:
+        raise DeclarationError(f"宣言を読めない: {error}") from error
+
+    try:
+        raw = json.loads(text)
+    except (ValueError, RecursionError) as error:
+        raise DeclarationError(f"宣言を JSON として読めない: {error}") from error
+
+    if not isinstance(raw, dict):
+        raise DeclarationError("宣言のトップレベルは object である必要がある")
+
+    _require_exact_keys(set(raw), TOP_LEVEL_KEYS, "トップレベル")
+
+    if type(raw["version"]) is not int or raw["version"] != DECLARATION_VERSION:
+        # 真偽値は int の派生なので isinstance では通ってしまう。type で見る。
+        raise DeclarationError(f"version は整数の {DECLARATION_VERSION} である必要がある")
+
+    _require_text(raw["note"], "note")
+
+    return raw
+
+
+def _build_entries(raw: dict) -> tuple[OutOfScopeEntry, ...]:
+    rows = raw["entries"]
+    if not isinstance(rows, list):
+        raise DeclarationError("entries は配列である必要がある")
+    if not rows:
+        raise DeclarationError("entries は 1 件以上である必要がある")
+
+    entries: list[OutOfScopeEntry] = []
+    seen_ids: set[str] = set()
+    seen_refs: set[str] = set()
+    for index, row in enumerate(rows):
+        if not isinstance(row, dict):
+            raise DeclarationError(f"entries[{index}] は object である必要がある")
+        _require_exact_keys(set(row), ENTRY_KEYS, f"entries[{index}]")
+
+        identifier = _require_text(row["id"], f"entries[{index}].id")
+        if not ID_PATTERN.match(identifier):
+            raise DeclarationError(f"id の書式が不正: {identifier}")
+        if identifier in seen_ids:
+            raise DeclarationError(f"id が重複している: {identifier}")
+        seen_ids.add(identifier)
+
+        refs = _require_text_list(row["verification_refs"], f"{identifier}.verification_refs")
+        for ref in refs:
+            normalize(ref)
+            if ref in seen_refs:
+                raise DeclarationError(f"verification_refs が重複している: {ref}")
+            seen_refs.add(ref)
+
+        prefixes = _require_text_list(row["path_prefixes"], f"{identifier}.path_prefixes")
+        for prefix in prefixes:
+            parts = normalize(prefix)
+            if parts[: len(PATH_PREFIX_ROOT)] != PATH_PREFIX_ROOT:
+                raise DeclarationError(f"対象パスは app/ 配下である必要がある: {prefix}")
+            if parts in TRUNK_PREFIXES:
+                raise DeclarationError(f"幹は対象パスにできない: {prefix}")
+
+        entries.append(
+            OutOfScopeEntry(
+                id=identifier,
+                title=_require_text(row["title"], f"{identifier}.title"),
+                reason=_require_statement(row["reason"], f"{identifier}.reason"),
+                alternative_verification=_require_statement(
+                    row["alternative_verification"], f"{identifier}.alternative_verification"
+                ),
+                verification_refs=refs,
+                path_prefixes=prefixes,
+            )
+        )
+
+    return tuple(entries)
+
+
+def _reject_overlapping_prefixes(entries: tuple[OutOfScopeEntry, ...]) -> None:
+    """対象パスを全 entry の直積で antichain にする (完全重複も包含も禁止)。"""
+    collected: list[tuple[str, tuple[str, ...]]] = []
+    for entry in entries:
+        for prefix in entry.path_prefixes:
+            collected.append((prefix, normalize(prefix)))
+
+    for i, (left_raw, left) in enumerate(collected):
+        for right_raw, right in collected[i + 1 :]:
+            if left == right:
+                raise DeclarationError(f"対象パスが重複している: {left_raw}")
+            shorter, longer = (left, right) if len(left) < len(right) else (right, left)
+            if longer[: len(shorter)] == shorter:
+                raise DeclarationError(f"対象パスが包含関係にある: {left_raw} と {right_raw}")
+
+
+def _verify_against_repository(entries: tuple[OutOfScopeEntry, ...], root: Path) -> None:
+    """層 2: 実在・境界・symlink・循環参照を repo_root を基点に検査する。"""
+    prefixes = [normalize(p) for entry in entries for p in entry.path_prefixes]
+
+    for entry in entries:
+        for prefix in entry.path_prefixes:
+            _resolve_within(root, normalize(prefix), f"{entry.id} の対象パス {prefix}")
+
+        for ref in entry.verification_refs:
+            parts = normalize(ref)
+            if ref in (DECLARATION_REL_PATH, AUDIT_DOC_REL_PATH):
+                raise DeclarationError(f"代替検証に宣言自身や監査文書は書けない: {ref}")
+            for prefix in prefixes:
+                if parts[: len(prefix)] == prefix:
+                    raise DeclarationError(f"代替検証が対象外の面そのものを指している: {ref}")
+            _resolve_within(root, parts, f"{entry.id} の代替検証 {ref}")
+
+
+def _resolve_within(root: Path, parts: tuple[str, ...], label: str) -> Path:
+    """repo_root を基点に解決し、symlink・不在・repo の外を拒否する。
+
+    先に完全解決すると「どの要素が symlink だったか」が失われるため、
+    先頭から 1 要素ずつたどって各要素を見る (末尾だけ見ると親が symlink の場合を通す)。
+    """
+    current = root
+    for part in parts:
+        current = current / part
+        if current.is_symlink():
+            raise DeclarationError(f"{label} の経路に symlink がある")
+
+    if not current.exists():
+        raise DeclarationError(f"{label} が実在しない")
+
+    resolved = current.resolve()
+    if resolved != root and root not in resolved.parents:
+        raise DeclarationError(f"{label} がリポジトリの外を指している")
+
+    return current
+
+
+def _require_exact_keys(actual: set[str], expected: frozenset[str], label: str) -> None:
+    missing = sorted(expected - actual)
+    if missing:
+        raise DeclarationError(f"{label} に必須キーが無い: {', '.join(missing)}")
+    unknown = sorted(actual - expected)
+    if unknown:
+        raise DeclarationError(f"{label} に未知のキーがある: {', '.join(unknown)}")
+
+
+def _require_text(value: object, label: str) -> str:
+    if not isinstance(value, str):
+        raise DeclarationError(f"{label} は文字列である必要がある")
+    if value.strip() == "":
+        raise DeclarationError(f"{label} が空である")
+
+    return value
+
+
+def _require_statement(value: object, label: str) -> str:
+    text = _require_text(value, label)
+    trimmed = text.strip()
+    if trimmed in HOLLOW_STATEMENTS:
+        raise DeclarationError(f"{label} が無内容である")
+    if len(trimmed) < MIN_STATEMENT_LENGTH:
+        raise DeclarationError(f"{label} は {MIN_STATEMENT_LENGTH} 文字以上である必要がある")
+
+    return text
+
+
+def _require_text_list(value: object, label: str) -> tuple[str, ...]:
+    if not isinstance(value, list):
+        raise DeclarationError(f"{label} は配列である必要がある")
+    if not value:
+        raise DeclarationError(f"{label} は 1 件以上である必要がある")
+
+    return tuple(_require_text(item, f"{label}[{i}]") for i, item in enumerate(value))
+
+
+def _markdown_cell(value: str) -> str:
+    """表を壊さないよう区切りを退避し、改行を空白へ畳む。"""
+    escaped = value.replace("\\", "\\\\").replace("|", "\\|")
+
+    return escaped.replace("\r\n", " ").replace("\n", " ").replace("\r", " ")
+
+
+def _single_line(message: str) -> str:
+    """診断を 1 行に保つ (外部入力の改行で契約を壊されないため)。"""
+    return message.replace("\r\n", " ").replace("\n", " ").replace("\r", " ")
+
+
+if __name__ == "__main__":
+    sys.exit(main())
diff --git a/.claude/skills/app-bug-hunt/coverage/test_naming_no_stale.py b/.claude/skills/app-bug-hunt/coverage/test_naming_no_stale.py
index 818cd25..0a0b0a0 100644
--- a/.claude/skills/app-bug-hunt/coverage/test_naming_no_stale.py
+++ b/.claude/skills/app-bug-hunt/coverage/test_naming_no_stale.py
@@ -3,7 +3,9 @@
 2 群のパターンを検知する。
 
 1. STALE_PATTERNS — 旧 Stage 付番 (Stage1/Stage3) と旧出力ファイル名
-   (coverage-stage1.md / coverage-stage3.md)。対象は skill 配下の本文 (.md / .py) 全部。
+   (coverage-stage1.md / coverage-stage3.md)、および計測基盤についての古い断定
+   (pcov がこの環境に無い / 実測が取れない、と言い切る文面)。
+   対象は skill 配下の本文 (.md / .py) 全部。
 2. IMPLEMENTATION_ONLY_PATTERNS — 旧 fail-open の文言 (「--executed 省略時」「未実行 candidate」)
    と旧語彙 (skipped_blocked_count / status 値としての skipped)。
    **対象から test_*.py を除外する** — 旧値を拒否する負の対照テストは、入力 fixture として
@@ -22,11 +24,17 @@ from pathlib import Path
 
 SKILL_ROOT = Path(__file__).resolve().parent.parent  # .claude/skills/app-bug-hunt/
 
-# 旧用語パターン: 単語境界付き Stage1/Stage3 (Stage 1 / Stage 3 表記も) と旧出力ファイル名。
+# 旧用語パターン: 単語境界付き Stage1/Stage3 (Stage 1 / Stage 3 表記も) と旧出力ファイル名、
+# および計測基盤について事実でなくなった古い断定 3 つ。
+# 古い断定は「新しい正しい文面を完全一致で pin する」のではなく **再混入だけ**を検出する
+# (正しい文面を pin すると言い回しを直すたびに赤くなり、腐りやすい側になる)。
 STALE_PATTERNS = [
     re.compile(r"Stage\s?1\b"),
     re.compile(r"Stage\s?3\b"),
     re.compile(r"coverage-stage[13]\.md"),
+    re.compile(r"pcov は本環境未導入"),
+    re.compile(r"本環境/CI/本番には pcov が入っていない"),
+    re.compile(r"実 coverage は取得できない"),
 ]
 
 # 旧 fail-open の文言と旧語彙。実装ファイル (と全 .md) にだけ禁じる。
@@ -136,6 +144,14 @@ class GateItselfTest(unittest.TestCase):
         stale, _ = scan(self.root)
         self.assertEqual(len(stale), 1, stale)
 
+    def test_detects_stale_measurement_premise(self) -> None:
+        # 計測基盤についての古い断定 3 つ (再混入だけを検出する側の負の対照)。
+        self._write("doc.md", "pcov は本環境未導入。\n")
+        self._write("impl.py", "# 本環境/CI/本番には pcov が入っていない\n")
+        self._write("other.md", "よって実 coverage は取得できない。\n")
+        stale, _ = scan(self.root)
+        self.assertEqual(len(stale), 3, stale)
+
     def test_excluded_name_is_skipped(self) -> None:
         # 自ファイル除外 (EXCLUDE_NAMES) が効いていること (依存を暗黙にしない)。
         self._write("test_naming_no_stale.py", "# Stage1 と 未実行 candidate\n")
diff --git a/.claude/skills/app-bug-hunt/coverage/test_out_of_scope.py b/.claude/skills/app-bug-hunt/coverage/test_out_of_scope.py
new file mode 100644
index 0000000..b2a94a6
--- /dev/null
+++ b/.claude/skills/app-bug-hunt/coverage/test_out_of_scope.py
@@ -0,0 +1,562 @@
+"""コード到達カバレッジの「対象外の面」の宣言 (out-of-scope.json) の契約テスト。
+
+1 契約 1 テスト。実データ (実宣言) の妥当性と、読み取り器の拒否契約と、
+CLI の終了コード契約 (実プロセス起動) を固定する。
+
+依存は標準ライブラリのみ。実行:
+    cd .claude/skills/app-bug-hunt/coverage && python3 -m unittest test_out_of_scope
+"""
+
+from __future__ import annotations
+
+import json
+import subprocess
+import sys
+import tempfile
+import unittest
+from pathlib import Path
+
+import out_of_scope
+from out_of_scope import (
+    AUDIT_DOC_REL_PATH,
+    DECLARATION_REL_PATH,
+    DEFAULT_DECLARATION,
+    DEFAULT_REPO_ROOT,
+    DeclarationError,
+    covers,
+    load,
+    normalize,
+)
+
+MODULE_PATH = Path(out_of_scope.__file__).resolve()
+
+# 承認済み範囲のスナップショット (施策 3 の 17 番)。
+# **宣言から生成しない** — テスト側に独立に書くことで、対象外の増減が必ずこの定数の
+# diff としてレビューに出るようにする。運用上の正本は JSON の側である。
+APPROVED_SCOPE: tuple[tuple[str, tuple[str, ...]], ...] = (
+    ("filament-admin", ("app/Filament", "app/Providers/Filament", "app/Http/Controllers/Admin")),
+    ("seo-static-delivery", ("app/Http/Controllers/Seo", "app/Providers/SeoServiceProvider.php")),
+    ("inbound-webhook", ("app/Http/Controllers/Webhooks",)),
+    ("mcp-oauth-interface", ("app/Mcp", "app/Passport")),
+    ("rest-api", ("app/Http/Controllers/Api",)),
+    ("artisan-command", ("app/Console",)),
+    ("queued-job", ("app/Jobs",)),
+    (
+        "bughunt-external-fake",
+        ("app/Http/Controllers/Testing", "app/Providers/BughuntFakesServiceProvider.php"),
+    ),
+)
+
+
+def _long(text: str) -> str:
+    """30 文字以上の説明文を作る (閾値そのものの検査は専用テストで行う)。"""
+    filler = "この面をブラウザ走行で検査できない事情をここに十分な長さで説明する。"
+    return text + filler
+
+
+def _split_md_row(row: str) -> list[str]:
+    """markdown の 1 行を、退避された区切り (\\|) を区別して分解する。
+
+    素の split('|') では退避された区切りまで数えてしまい、表の崩壊を検出できない。
+    """
+    cells: list[str] = []
+    buffer = ""
+    escaped = False
+    for char in row:
+        if escaped:
+            buffer += char
+            escaped = False
+            continue
+        if char == "\\":
+            escaped = True
+            continue
+        if char == "|":
+            cells.append(buffer.strip())
+            buffer = ""
+            continue
+        buffer += char
+    cells.append(buffer.strip())
+    # 先頭と末尾の縦棒による空セルを落とす。
+    return cells[1:-1]
+
+
+def _is_tracked(rel_path: str, tracked: frozenset[str]) -> bool:
+    """追跡集合に対し、ファイルは完全一致・ディレクトリはパス要素の境界で判定する。"""
+    if rel_path in tracked:
+        return True
+    prefix = tuple(rel_path.split("/"))
+    for candidate in tracked:
+        parts = tuple(candidate.split("/"))
+        if len(parts) > len(prefix) and parts[: len(prefix)] == prefix:
+            return True
+    return False
+
+
+class SyntheticRepo:
+    """層 2 (実在・symlink・追跡) の検査に使う合成リポジトリ。"""
+
+    def __init__(self, root: Path) -> None:
+        self.root = root
+        for rel in (
+            "app/Alpha",
+            "app/Beta",
+            "app/Http/Controllers/Gamma",
+            "tests/Feature/Alpha",
+            "tests/Feature/Beta",
+            ".claude/skills/app-bug-hunt/coverage",
+        ):
+            (root / rel).mkdir(parents=True, exist_ok=True)
+        (root / "app/Alpha/Alpha.php").write_text("<?php\n", encoding="utf-8")
+        (root / "app/Beta/Beta.php").write_text("<?php\n", encoding="utf-8")
+        (root / "app/Http/Controllers/Gamma/Gamma.php").write_text("<?php\n", encoding="utf-8")
+        (root / "tests/Feature/Alpha/AlphaTest.php").write_text("<?php\n", encoding="utf-8")
+        (root / "tests/Feature/Beta/BetaTest.php").write_text("<?php\n", encoding="utf-8")
+        (root / DECLARATION_REL_PATH).write_text("{}\n", encoding="utf-8")
+        (root / AUDIT_DOC_REL_PATH).write_text("# audit\n", encoding="utf-8")
+
+    def payload(self) -> dict:
+        return {
+            "version": 1,
+            "note": "合成リポジトリ向けの宣言 (テスト用)。",
+            "entries": [
+                {
+                    "id": "alpha",
+                    "title": "アルファ面",
+                    "reason": _long("アルファ面は利用者が到達しない。"),
+                    "alternative_verification": _long("アルファ面の挙動は Feature テストが見る。"),
+                    "verification_refs": ["tests/Feature/Alpha"],
+                    "path_prefixes": ["app/Alpha"],
+                },
+                {
+                    "id": "beta",
+                    "title": "ベータ面",
+                    "reason": _long("ベータ面はブラウザ操作では発火しない。"),
+                    "alternative_verification": _long("ベータ面の挙動は Feature テストが見る。"),
+                    "verification_refs": ["tests/Feature/Beta"],
+                    "path_prefixes": ["app/Beta"],
+                },
+            ],
+        }
+
+    def write(self, payload: dict) -> Path:
+        target = self.root / "declaration.json"
+        target.write_text(
+            json.dumps(payload, ensure_ascii=False, indent=2) + "\n", encoding="utf-8"
+        )
+        return target
+
+    def load(self, payload: dict):
+        return load(self.write(payload), self.root)
+
+
+class SyntheticCase(unittest.TestCase):
+    """合成リポジトリを持つテストの土台。"""
+
+    def setUp(self) -> None:
+        self.tmp = tempfile.TemporaryDirectory()
+        self.addCleanup(self.tmp.cleanup)
+        self.repo = SyntheticRepo(Path(self.tmp.name))
+
+    def assertRejects(self, payload: dict, hint: str) -> None:
+        with self.assertRaises(DeclarationError, msg=hint):
+            self.repo.load(payload)
+
+    def valid(self) -> dict:
+        return self.repo.payload()
+
+
+class RealDeclarationTest(unittest.TestCase):
+    """1 / 17 / 23: 実データそのものの契約。"""
+
+    def setUp(self) -> None:
+        self.declaration = load(DEFAULT_DECLARATION, DEFAULT_REPO_ROOT)
+
+    def test_1_real_declaration_loads(self) -> None:
+        self.assertEqual(self.declaration.version, 1)
+        self.assertTrue(self.declaration.entries)
+
+    def test_17_matches_approved_scope_snapshot(self) -> None:
+        actual = tuple((e.id, e.path_prefixes) for e in self.declaration.entries)
+        self.assertEqual(
+            actual,
+            APPROVED_SCOPE,
+            "対象外の面が承認済み範囲と食い違う (増減はどちらでも赤にする)",
+        )
+
+    def test_23_audit_document_does_not_copy_the_list(self) -> None:
+        audit = (DEFAULT_REPO_ROOT / AUDIT_DOC_REL_PATH).read_text(encoding="utf-8")
+        leaked: list[str] = []
+        for entry in self.declaration.entries:
+            for literal in (entry.id, entry.title, *entry.path_prefixes):
+                if literal in audit:
+                    leaked.append(literal)
+        self.assertEqual(leaked, [], "監査文書に対象外の面の一覧が複製されている: " + str(leaked))
+
+
+class TrackedRefsTest(unittest.TestCase):
+    """14 / 14b: 代替検証と対象パスが git の追跡下にあること。"""
+
+    def setUp(self) -> None:
+        self.declaration = load(DEFAULT_DECLARATION, DEFAULT_REPO_ROOT)
+
+    def _tracked(self) -> frozenset[str]:
+        proc = subprocess.run(
+            ["git", "-C", str(DEFAULT_REPO_ROOT), "ls-files", "-z"],
+            capture_output=True,
+        )
+        # git が使えない環境は skip ではなく fail にする (環境不備を隠さない)。
+        self.assertEqual(proc.returncode, 0, "git ls-files を実行できない: " + proc.stderr.decode())
+        return frozenset(p for p in proc.stdout.decode("utf-8").split("\0") if p)
+
+    def test_14_refs_and_prefixes_are_tracked(self) -> None:
+        tracked = self._tracked()
+        untracked: list[str] = []
+        for entry in self.declaration.entries:
+            for rel in (*entry.verification_refs, *entry.path_prefixes):
+                if not _is_tracked(rel, tracked):
+                    untracked.append(rel)
+        self.assertEqual(untracked, [], "追跡下にないパスが宣言されている: " + str(untracked))
+
+    def test_14b_directory_tracking_uses_segment_boundary(self) -> None:
+        tracked = frozenset({"tests/Foobar/Test.php"})
+        self.assertFalse(_is_tracked("tests/Foo", tracked))
+        self.assertTrue(_is_tracked("tests/Foobar", tracked))
+
+
+class RequiredKeysTest(SyntheticCase):
+    """2 / 3: 必須キーの欠落と未知キー。"""
+
+    def test_2_missing_top_level_key_is_rejected(self) -> None:
+        for key in ("version", "note", "entries"):
+            payload = self.valid()
+            del payload[key]
+            self.assertRejects(payload, f"トップレベル {key} の欠落を通した")
+
+    def test_2_missing_entry_key_is_rejected(self) -> None:
+        for key in (
+            "id",
+            "title",
+            "reason",
+            "alternative_verification",
+            "verification_refs",
+            "path_prefixes",
+        ):
+            payload = self.valid()
+            del payload["entries"][0][key]
+            self.assertRejects(payload, f"entry の {key} 欠落を通した")
+
+    def test_3_unknown_key_is_rejected(self) -> None:
+        payload = self.valid()
+        payload["extra"] = 1
+        self.assertRejects(payload, "トップレベルの未知キーを通した")
+
+        payload = self.valid()
+        payload["entries"][0]["extra"] = 1
+        self.assertRejects(payload, "entry の未知キーを通した")
+
+
+class TypeContractTest(SyntheticCase):
+    """4 / 5: 型の厳密判定。"""
+
+    def test_4_wrong_types_are_rejected(self) -> None:
+        self.assertRejects([], "トップレベルが配列でも通した")
+
+        payload = self.valid()
+        payload["entries"] = {"alpha": {}}
+        self.assertRejects(payload, "entries が object でも通した")
+
+        payload = self.valid()
+        payload["entries"] = []
+        self.assertRejects(payload, "entries が空でも通した")
+
+        payload = self.valid()
+        payload["entries"][0]["title"] = 12345
+        self.assertRejects(payload, "文字列欄が数値でも通した")
+
+        payload = self.valid()
+        payload["entries"][0]["verification_refs"] = [12345]
+        self.assertRejects(payload, "配列要素が非文字列でも通した")
+
+        payload = self.valid()
+        payload["entries"][0]["title"] = "   "
+        self.assertRejects(payload, "空白だけの文字列を通した")
+
+        payload = self.valid()
+        payload["entries"][0] = "alpha"
+        self.assertRejects(payload, "entry が文字列でも通した")
+
+    def test_5_version_must_be_the_integer_one(self) -> None:
+        for bad in (2, 0, "1", 1.0, True):
+            payload = self.valid()
+            payload["version"] = bad
+            self.assertRejects(payload, f"version={bad!r} を通した")
+
+
+class IdentifierTest(SyntheticCase):
+    """6: id の書式と一意性。"""
+
+    def test_6_bad_id_format_is_rejected(self) -> None:
+        for bad in ("Alpha", "-alpha", "alpha_beta", "alpha/beta", "", "アルファ"):
+            payload = self.valid()
+            payload["entries"][0]["id"] = bad
+            self.assertRejects(payload, f"id={bad!r} を通した")
+
+    def test_6_duplicate_id_is_rejected(self) -> None:
+        payload = self.valid()
+        payload["entries"][1]["id"] = payload["entries"][0]["id"]
+        self.assertRejects(payload, "id の重複を通した")
+
+
+class StatementTest(SyntheticCase):
+    """7: 理由と代替検証の中身。"""
+
+    def test_7_short_statement_is_rejected(self) -> None:
+        for key in ("reason", "alternative_verification"):
+            payload = self.valid()
+            payload["entries"][0][key] = "短い理由"
+            self.assertRejects(payload, f"{key} が 30 文字未満でも通した")
+
+    def test_7_hollow_statement_is_rejected(self) -> None:
+        for hollow in ("対象外", "なし", "-", "N/A", "TBD"):
+            payload = self.valid()
+            payload["entries"][0]["reason"] = hollow
+            self.assertRejects(payload, f"無内容な理由 {hollow!r} を通した")
+
+
+class PathPrefixTest(SyntheticCase):
+    """8 / 9 / 10 / 11: 対象パスの制約。"""
+
+    def test_8_empty_missing_or_outside_app_is_rejected(self) -> None:
+        payload = self.valid()
+        payload["entries"][0]["path_prefixes"] = []
+        self.assertRejects(payload, "path_prefixes が空でも通した")
+
+        payload = self.valid()
+        payload["entries"][0]["path_prefixes"] = ["app/NoSuchDirectory"]
+        self.assertRejects(payload, "不在の対象パスを通した")
+
+        payload = self.valid()
+        payload["entries"][0]["path_prefixes"] = ["tests/Feature/Alpha"]
+        self.assertRejects(payload, "app/ の外の対象パスを通した")
+
+    def test_9_symlinks_and_missing_paths_are_rejected(self) -> None:
+        outside = Path(self.tmp.name).parent / "outside-target"
+        outside.mkdir(exist_ok=True)
+        self.addCleanup(outside.rmdir)
+
+        (self.repo.root / "app/OutsideLink").symlink_to(outside, target_is_directory=True)
+        payload = self.valid()
+        payload["entries"][0]["path_prefixes"] = ["app/OutsideLink"]
+        self.assertRejects(payload, "repo の外を指す symlink を通した")
+
+        (self.repo.root / "app/InsideLink").symlink_to(
+            self.repo.root / "app/Beta", target_is_directory=True
+        )
+        payload = self.valid()
+        payload["entries"][0]["path_prefixes"] = ["app/InsideLink"]
+        self.assertRejects(payload, "repo の内を指す symlink を通した")
+
+        (self.repo.root / "app/LinkedParent").symlink_to(
+            self.repo.root / "app/Http", target_is_directory=True
+        )
+        payload = self.valid()
+        payload["entries"][0]["path_prefixes"] = ["app/LinkedParent/Controllers/Gamma"]
+        self.assertRejects(payload, "親ディレクトリが symlink の対象パスを通した")
+
+    def test_10_containment_and_duplicates_across_entries_are_rejected(self) -> None:
+        payload = self.valid()
+        payload["entries"][1]["path_prefixes"] = ["app/Alpha/Deeper"]
+        (self.repo.root / "app/Alpha/Deeper").mkdir()
+        self.assertRejects(payload, "entry を跨いだ包含関係を通した")
+
+        payload = self.valid()
+        payload["entries"][1]["path_prefixes"] = ["app/Alpha"]
+        self.assertRejects(payload, "entry を跨いだ完全重複を通した")
+
+        payload = self.valid()
+        payload["entries"][0]["path_prefixes"] = ["app/Alpha", "app/Alpha"]
+        self.assertRejects(payload, "entry 内の完全重複を通した")
+
+    def test_11_trunk_prefixes_are_rejected(self) -> None:
+        for trunk in ("app", "app/Http", "app/Http/Controllers"):
+            payload = self.valid()
+            payload["entries"][0]["path_prefixes"] = [trunk]
+            self.assertRejects(payload, f"幹 {trunk} を通した")
+
+
+class VerificationRefsTest(SyntheticCase):
+    """12 / 13: 代替検証の参照。"""
+
+    def test_12_empty_missing_or_duplicated_refs_are_rejected(self) -> None:
+        payload = self.valid()
+        payload["entries"][0]["verification_refs"] = []
+        self.assertRejects(payload, "verification_refs が空でも通した")
+
+        payload = self.valid()
+        payload["entries"][0]["verification_refs"] = ["tests/Feature/NoSuchTest.php"]
+        self.assertRejects(payload, "不在の代替検証を通した")
+
+        payload = self.valid()
+        payload["entries"][1]["verification_refs"] = ["tests/Feature/Alpha"]
+        self.assertRejects(payload, "宣言内での重複を通した")
+
+    def test_13_self_referencing_refs_are_rejected(self) -> None:
+        for circular in (DECLARATION_REL_PATH, AUDIT_DOC_REL_PATH, "app/Beta"):
+            payload = self.valid()
+            payload["entries"][0]["verification_refs"] = [circular]
+            self.assertRejects(payload, f"循環参照 {circular} を通した")
+
+
+class NormalizeTest(unittest.TestCase):
+    """15: 層 1 (字句の正規形) と covers のセグメント境界。"""
+
+    def test_15_non_canonical_paths_are_rejected(self) -> None:
+        for bad in (
+            "/app/Filament",
+            "app/../../etc",
+            "app/./Filament",
+            "app//Filament",
+            "app/Filament/",
+            "app\\Filament",
+            "..",
+            ".",
+            "",
+            "   ",
+        ):
+            with self.assertRaises(DeclarationError, msg=f"{bad!r} を通した"):
+                normalize(bad)
+
+    def test_15_normalize_returns_segments(self) -> None:
+        self.assertEqual(normalize("app/Http/Controllers/Api"), ("app", "Http", "Controllers", "Api"))
+
+    def test_15_covers_uses_segment_boundary(self) -> None:
+        declaration = load(DEFAULT_DECLARATION, DEFAULT_REPO_ROOT)
+        matched = covers(declaration, "app/Filament/Resources/Foo.php")
+        self.assertIsNotNone(matched)
+        self.assertIsNone(covers(declaration, "app/Filamentary/Foo.php"))
+        self.assertIsNone(covers(declaration, "app/Services/Manual/ScenarioService.php"))
+
+    def test_15_covers_rejects_non_canonical_argument(self) -> None:
+        declaration = load(DEFAULT_DECLARATION, DEFAULT_REPO_ROOT)
+        with self.assertRaises(DeclarationError):
+            covers(declaration, "app/../app/Filament")
+
+
+class InputFailureTest(SyntheticCase):
+    """16: 入力障害が DeclarationError へ収束すること。"""
+
+    def test_16_missing_file(self) -> None:
+        with self.assertRaises(DeclarationError):
+            load(self.repo.root / "no-such-file.json", self.repo.root)
+
+    def test_16_invalid_utf8(self) -> None:
+        target = self.repo.root / "broken.json"
+        target.write_bytes(b"\xff\xfe{ invalid }")
+        with self.assertRaises(DeclarationError):
+            load(target, self.repo.root)
+
+    def test_16_broken_json(self) -> None:
+        target = self.repo.root / "broken2.json"
+        target.write_text("{ this is not json", encoding="utf-8")
+        with self.assertRaises(DeclarationError):
+            load(target, self.repo.root)
+
+    def test_16_deeply_nested_json(self) -> None:
+        target = self.repo.root / "deep.json"
+        target.write_text("[" * 200000, encoding="utf-8")
+        with self.assertRaises(DeclarationError):
+            load(target, self.repo.root)
+
+
+class CliTest(SyntheticCase):
+    """1b / 18 / 19 / 20 / 21 / 22: CLI の契約 (実プロセス起動)。"""
+
+    def _run(self, args: list[str]) -> subprocess.CompletedProcess[str]:
+        return subprocess.run(
+            [sys.executable, str(MODULE_PATH), *args],
+            capture_output=True,
+            text=True,
+            cwd=str(MODULE_PATH.parent),
+        )
+
+    def test_1b_runs_with_default_paths(self) -> None:
+        proc = self._run([])
+        self.assertEqual(proc.returncode, 0, proc.stderr)
+        self.assertTrue(proc.stdout.strip())
+
+    def test_18_emit_json_matches_normalized_data(self) -> None:
+        proc = self._run(["--emit", "json"])
+        self.assertEqual(proc.returncode, 0, proc.stderr)
+        payload = json.loads(proc.stdout)
+        declaration = load(DEFAULT_DECLARATION, DEFAULT_REPO_ROOT)
+        self.assertEqual(payload["version"], declaration.version)
+        self.assertEqual(payload["note"], declaration.note)
+        self.assertEqual(
+            [e["id"] for e in payload["entries"]],
+            [e.id for e in declaration.entries],
+        )
+        self.assertEqual(
+            [tuple(e["path_prefixes"]) for e in payload["entries"]],
+            [e.path_prefixes for e in declaration.entries],
+        )
+
+    def test_19_emit_markdown_contains_every_entry(self) -> None:
+        proc = self._run(["--emit", "markdown"])
+        self.assertEqual(proc.returncode, 0, proc.stderr)
+        declaration = load(DEFAULT_DECLARATION, DEFAULT_REPO_ROOT)
+        for entry in declaration.entries:
+            for literal in (
+                entry.title,
+                entry.reason,
+                entry.alternative_verification,
+                *entry.path_prefixes,
+            ):
+                self.assertIn(literal, proc.stdout, f"markdown に {literal} が現れない")
+
+    def test_19_emit_markdown_keeps_column_count(self) -> None:
+        payload = self.valid()
+        payload["entries"][0]["reason"] = _long("縦棒 | と\n改行を含む理由。")
+        declaration_path = self.repo.write(payload)
+        proc = self._run(
+            [
+                "--declaration",
+                str(declaration_path),
+                "--repo-root",
+                str(self.repo.root),
+                "--emit",
+                "markdown",
+            ]
+        )
+        self.assertEqual(proc.returncode, 0, proc.stderr)
+        rows = [line for line in proc.stdout.splitlines() if line.startswith("|")]
+        self.assertGreaterEqual(len(rows), 4)
+        widths = {len(_split_md_row(row)) for row in rows}
+        self.assertEqual(len(widths), 1, f"列数が揃っていない: {widths}")
+
+    def test_20_invalid_declaration_is_fail_closed(self) -> None:
+        payload = self.valid()
+        payload["entries"][0]["reason"] = "短い"
+        declaration_path = self.repo.write(payload)
+        proc = self._run(
+            ["--declaration", str(declaration_path), "--repo-root", str(self.repo.root)]
+        )
+        self.assertEqual(proc.returncode, 2)
+        self.assertEqual(proc.stdout, "")
+        self.assertTrue(proc.stderr.strip())
+        self.assertEqual(len(proc.stderr.strip().splitlines()), 1, proc.stderr)
+        self.assertNotIn("Traceback", proc.stderr)
+
+    def test_21_unknown_emit_value_is_fail_closed(self) -> None:
+        proc = self._run(["--emit", "no-such-format"])
+        self.assertEqual(proc.returncode, 2)
+        self.assertEqual(proc.stdout, "")
+        self.assertNotIn("Traceback", proc.stderr)
+
+    def test_22_wrong_repo_root_fails(self) -> None:
+        proc = self._run(["--repo-root", str(self.repo.root)])
+        self.assertEqual(proc.returncode, 2)
+        self.assertEqual(proc.stdout, "")
+
+
+if __name__ == "__main__":
+    unittest.main()
diff --git a/.claude/skills/app-bug-hunt/ledger/README.md b/.claude/skills/app-bug-hunt/ledger/README.md
index 1440145..dc78888 100644
--- a/.claude/skills/app-bug-hunt/ledger/README.md
+++ b/.claude/skills/app-bug-hunt/ledger/README.md
@@ -2,7 +2,9 @@ # Bug-hunt Finding 台帳 (Finding Ledger)
 
 app (AI UX 評価プラットフォーム) の探索的バグハント結果を捨てずに**資産化**するための台帳。
 目的は綺麗な分類ではなく **「同じ欠陥を二度見つけたとき安く扱い、重要欠陥をテストに昇格させる」**こと。
-bug-hunt の全体像・運用は `.claude/skills/app-bug-hunt/SKILL.md` と `coverage-audit.md` を参照。
+bug-hunt の全体像・運用は `.claude/skills/app-bug-hunt/SKILL.md` を参照。
+「そもそもブラウザ走行では検査できない面はどれか」の静的な棚卸しは `coverage-audit.md` にある
+(本台帳が扱うのは**見つかった欠陥**であり、あちらが扱うのは**検査できない範囲の宣言**である)。
 
 ## 構成
 | ファイル | 役割 |
diff --git a/.claude/skills/app-bug-hunt/ledger/validate_findings.py b/.claude/skills/app-bug-hunt/ledger/validate_findings.py
index db96405..4a200df 100644
--- a/.claude/skills/app-bug-hunt/ledger/validate_findings.py
+++ b/.claude/skills/app-bug-hunt/ledger/validate_findings.py
@@ -9,8 +9,8 @@ findings.jsonl を検証し、success/kill 判定に使う KPI を出力する
     python3 validate_findings.py findings.jsonl --json
     python3 validate_findings.py findings.jsonl --strict   # 必須欠損が閾値超で exit 1
 
-設計根拠: .claude/skills/app-bug-hunt/SKILL.md / coverage-audit.md
-  (最小スキーマ / success-kill 基準)。app bug-hunt は直列 :8010 (shard 0) /
+設計根拠: .claude/skills/app-bug-hunt/SKILL.md (最小スキーマ / success-kill 基準) と
+  coverage-audit.md (ブラウザ走行では検査できない面の棚卸し)。app bug-hunt は直列 :8010 (shard 0) /
   並列 :8011..8014 (shard 1..4) の専用 bughunt 環境で走る。
 """
 from __future__ import annotations
diff --git a/app/Http/Middleware/BughuntCoverageMiddleware.php b/app/Http/Middleware/BughuntCoverageMiddleware.php
index 69a14f0..00ec69c 100644
--- a/app/Http/Middleware/BughuntCoverageMiddleware.php
+++ b/app/Http/Middleware/BughuntCoverageMiddleware.php
@@ -13,9 +13,12 @@
 /**
  * コード到達カバレッジ (bug-hunt): app/ の実行された行/未到達行を bug-hunt 走行中のみ収集する観測器。
  *
- * 設計の honest 前提: 本環境/CI/本番には pcov が入っていない。よって本 middleware は
- * env (BUGHUNT_PCOV) + function_exists('\pcov\start') の **二重 guard** を通らない限り完全 no-op で、
- * pcov 未導入環境に読み込まれても安全 (handle は $next をそのまま返すだけ)。
+ * 設計の honest 前提: 開発コンテナ (docker/Dockerfile) では pcov を使えるが、収集を有効にするのは
+ * bug-hunt が serve を起動するときだけである。CI と本番でコード到達の収集を有効にする構成は
+ * 本リポジトリに存在せず (CI の workflow に pcov の導入記述は無く、デプロイ定義そのものが無い)、
+ * リポジトリの外にある本番構成がどうなっているかは分からない。よって拡張の有無に関わらず、
+ * env (BUGHUNT_PCOV) + function_exists('\pcov\start') の **二重 guard** は必要であり、
+ * どちらかが偽なら本 middleware は完全 no-op で安全である (handle は $next をそのまま返すだけ)。
  *
  * 役割分担:
  *  - handle:    per-request で pcov を初期化 (clear → start)。gate 内のみ。
@@ -34,7 +37,7 @@ final class BughuntCoverageMiddleware
 {
     /**
      * env + function_exists の二重 guard。どちらか偽なら handle/terminate は完全 no-op。
-     * pcov 未導入 (= 本環境/CI/本番) では常に false を返す。
+     * 拡張が読み込まれていない実行環境では function_exists 側が常に false を返す。
      */
     public static function enabled(): bool
     {
diff --git a/docs/template-divergence.md b/docs/template-divergence.md
index 99d6e34..9de9204 100644
--- a/docs/template-divergence.md
+++ b/docs/template-divergence.md
@@ -8,7 +8,7 @@ # テンプレート差分レジストリ
 `template-divergence-ledger` が 2026-08-15 に確定した形) に従う。形式は
 `tests/Architecture/TemplateDivergenceLedgerFormatTest.php` が機械で強制する。
 
-登録エントリ: 25 件
+登録エントリ: 26 件
 
 ## 記録の原則
 
@@ -1506,3 +1506,72 @@ ### 関連
 - 実装: `app/Support/PasskeyConfigValidator.php` / `app/Support/PasskeyOriginCanonicalizer.php`
 - 設計: `devnotes/20260815-1111-passkey-config-hardening/` /
   `devnotes/20260817-1309-todo-t216-passkey-hardening-completion/`
+
+---
+
+## D27 コード到達の対象外の宣言を、route 名の接頭辞を持たないコード軸だけの形にする
+
+| 行 | 内容 |
+|---|---|
+| 対象パス | `.claude/skills/app-bug-hunt/coverage/out-of-scope.json` / `.claude/skills/app-bug-hunt/coverage/out_of_scope.py` / `.claude/skills/app-bug-hunt/coverage-audit.md` |
+| 業務要件起因の説明 | 探索の分母は route 単位の注釈が正本であり、コード到達の未到達は `app/` のパス単位でしか説明できないため、対象外の宣言を軸で 2 本に分ける |
+| 揃え続ける不変条件と保証機構 | 対象外は理由と代替検証と実在する参照を伴う。増減は承認済み範囲のスナップショットとの完全一致で必ずレビューに出る。`BughuntCoverageToolSelfTest` から `test_out_of_scope` が実走する |
+| 再判定の条件 | 家系の正典が route 名接頭辞を必須にしたとき / 注釈側へ代替検証の欄が入ったとき / 集計器が宣言を読む形になったとき |
+| 決めた日 | 2026-08-17 |
+| 決めた人 | 開発者 |
+| 根拠 | T220 |
+| 状態 | 恒久 |
+| 見直し期限 | — |
+
+家系の参照実装は対象外の宣言に **route 名の接頭辞**を持たせ、目録のドリフト検査をそこから
+導出している。本アプリは route 単位の判断を `inventory/annotations.toml` (区分 外) が持つため、
+宣言を**コード到達の軸だけ**に絞る。
+
+| 観点 | 家系の参照実装 | 本アプリ |
+|---|---|---|
+| 宣言が持つ軸 | route 名の接頭辞と `app/` のパスの両方 | `app/` のパスだけ |
+| 目録のドリフト検査の対象外 | 宣言から導出する | 注釈 TOML の区分 外 が正本 (D20) |
+| 代替検証の実在 | 散文で書く | `verification_refs` として機械が実在を見る |
+
+### なぜ正当な差分か (logic-driven)
+
+1. **route 単位の対象外は既に別の正本を持っている**。本アプリの目録は注釈 TOML から生成する形
+   (D20) で、区分 外 の行は 30 文字以上の理由付きで目録に見える。宣言にも route 名接頭辞を
+   置くと、同じ判断が 2 か所に載って必ず食い違う。
+2. **導出先が無い**。参照実装が接頭辞を持つのは、検査スクリプトが宣言から選択の正規表現を
+   導出するためである。本アプリの検査は生成器側で判定するので、導出する相手がいない。
+   使われない出力を作らない (思考原則 2)。
+3. **代替検証は実在を機械で見るほうが腐りにくい**。散文で「別のテストが見ている」と書くと、
+   その参照先が消えても気付けない。パスとして宣言すれば、少なくとも参照先が丸ごと消えたことは
+   検出できる。
+
+### 揃えている不変条件 (これは保証し続ける)
+
+> 「対象外は理由と代替検証と実在する参照を伴い、増減は必ずレビューに出る」
+
+| 不変条件 | 担い手 |
+|---|---|
+| 理由と代替検証が中身のある文であること (30 文字以上・無内容な値でない) | `test_out_of_scope` (必須キー / 型 / 文の中身) |
+| 代替検証が実在し、追跡下にあり、自己言及でないこと | 同上 (実在・追跡・循環参照) |
+| 対象パスが実在し、幹や包含や正規形の迂回で無制限に広がらないこと | 同上 (幹の禁止・antichain・層 1 の正規形) |
+| 対象外の静かな増減が起きないこと | 同上 (承認済み範囲のスナップショットとの完全一致) |
+| 宣言不正が fail-closed であること (標準出力を汚さない) | 同上 (CLI の終了コード契約を実プロセスで検査) |
+| これらが `composer test` から実走すること | `tests/Architecture/BughuntCoverageToolSelfTest.php` |
+
+### 保証しないもの
+
+- **集計器との自動照合は持たない**。`merge_pcov.py` は宣言を読まないので、
+  まだ通れていないものの一覧と宣言の突合は人が読んで行う。
+- 機械が見るのは宣言の形式と参照先の実在までで、代替検証がその面を本当に守っているか
+  (テストの意味的十分性) は人のレビューの担当である。
+- 古い断定の再混入を見る走査 (`test_naming_no_stale`) の射程はスキル配下の `.md` / `.py` で、
+  `app/` のコメントは見ない。
+
+### 関連
+
+- 実装: `.claude/skills/app-bug-hunt/coverage/out-of-scope.json` /
+  `.claude/skills/app-bug-hunt/coverage/out_of_scope.py` /
+  `.claude/skills/app-bug-hunt/coverage-audit.md`
+- gate: `tests/Architecture/BughuntCoverageToolSelfTest.php` /
+  `.claude/skills/app-bug-hunt/coverage/test_out_of_scope.py`
+- 設計: `devnotes/20260818-0243-bughunt-coverage-audit-doc/`
diff --git a/tests/Architecture/BughuntCoverageToolSelfTest.php b/tests/Architecture/BughuntCoverageToolSelfTest.php
index 6c1e2a3..047dd05 100644
--- a/tests/Architecture/BughuntCoverageToolSelfTest.php
+++ b/tests/Architecture/BughuntCoverageToolSelfTest.php
@@ -8,10 +8,12 @@
  * Architecture invariant: bug-hunt のカバレッジ道具 (Python) の自己テストを
  * `composer test` の下で実走させる。
  *
- * 対象は 3 モジュール:
+ * 対象は 4 モジュール:
  *   - test_correlate      … 照合器の fail-closed 契約 (主入力が揃わない走行を成功にしない)
  *   - test_build_executed … 実行済み route の記録の集約器 (同上)
  *   - test_naming_no_stale … 旧 fail-open 文言・旧語彙の再混入検知
+ *   - test_out_of_scope   … コード到達で未到達でよい面の宣言の契約
+ *                            (理由と代替検証の実在・承認済み範囲との一致)
  *
  * ここに結線しないと「不変条件はテストへの登録まで含めて実装済み」を満たさない
  * (禁止事項 1)。禁止語が戻っても、照合器が fail-open へ戻っても、緑のままになるため。
@@ -49,10 +51,12 @@ function bctRunUnittest(array $modules): array
     );
 });
 
-test('カバレッジ道具の Python 自己テスト 3 本が composer test の下で通ること', function (): void {
+test('カバレッジ道具の Python 自己テスト 4 本が composer test の下で通ること', function (): void {
     expect(is_dir(bctCoverageDir()))->toBeTrue('coverage ディレクトリが見つからない: '.bctCoverageDir());
 
-    [$code, $out] = bctRunUnittest(['test_correlate', 'test_build_executed', 'test_naming_no_stale']);
+    [$code, $out] = bctRunUnittest([
+        'test_correlate', 'test_build_executed', 'test_naming_no_stale', 'test_out_of_scope',
+    ]);
 
     expect($code)->toBe(0, "bug-hunt カバレッジ道具の自己テストが失敗しました:\n".$out);
 });
diff --git a/tests/Architecture/TemplateDivergenceLedgerFormatTest.php b/tests/Architecture/TemplateDivergenceLedgerFormatTest.php
index 1ca616d..0632592 100644
--- a/tests/Architecture/TemplateDivergenceLedgerFormatTest.php
+++ b/tests/Architecture/TemplateDivergenceLedgerFormatTest.php
@@ -34,7 +34,7 @@
  * **明示件数との同期検査であって、例外を許す一覧ではない**。個別の D 番号を名指しして
  * 規則を免除する仕組みは持たない。登録を足した / 消したら同じ変更でこの値も直す。
  */
-const TEMPLATE_DIVERGENCE_ENTRY_COUNT = 25;
+const TEMPLATE_DIVERGENCE_ENTRY_COUNT = 26;
 
 /** 逸脱の登録簿の本文 (読めないことは不合格)。 */
 function templateDivergenceMarkdown(): string
```
