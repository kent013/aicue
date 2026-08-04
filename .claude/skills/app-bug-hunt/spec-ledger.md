# bug-hunt 仕様台帳 (spec-ledger) — 既知仕様 / 誤検知の申し送り

このファイルは、過去の bug-hunt run で挙がった finding のうち **実コード裏取り + 敵対的検証の結果
「仕様 (SPEC)」「ドキュメント側対応 (DOC)」「誤検知 (FALSE_POSITIVE)」と確定したもの**を記録する、
人間可読の申し送り台帳。

機械 registry (`ledger/adjudications.jsonl`) の**対**である:

| | 正本 | 読み手 | 効果 |
|---|---|---|---|
| `ledger/adjudications.jsonl` | cross-session の**機械判定** | validator (`--annotate`) | 4-gate 一致で annotate + downrank |
| `spec-ledger.md` (本ファイル) | cross-session の**人間向け申し送り** | bug-hunt 実行者 (親 / 子 shard) | 「再起票しない」判断の根拠を渡す |

同じ説明文を両方に重複させない。機械照合が要るものは registry に、
「なぜ SPEC と確定したか」の物語は本ファイルに書く。

> 旧 registry の spirux 由来 18 件は AI-CUE に実在しない資産を指していたため削除済み
> (理由は `ledger/README.md` 運用ガード (d))。**他アプリの申し送りを写さない**。

---

## 使い方 (bug-hunt 実行者へ)

- finding を起票する前に本台帳を検索すること。**ここに SPEC として載っている事象は再起票しない**
  (「既知仕様」と一行記録して次へ)。
- 同一事象が再発したと感じたら、台帳の**根拠 (file:line)** を実コードで確認する。
  コードが台帳と乖離していれば **regression** の可能性があるので、その差分を根拠に新規 finding を起票してよい。
- DOC 項目は「コード正本は正しく、bug-hunt 側カード / 正本ドキュメントの記述が陳腐化していた」もの。
  該当カードが修正済みかを確認する。
- 「要確認」を SPEC に確定する判断は、**設計文書 (devnotes/docs)・実コード・テストの三点**で
  裏が取れた場合のみ。取れないものは台帳に載せず「要確認」のまま残す。
- **SPEC / DOC 確定項目には根拠 (file:line) を必ず併記する**こと。後続実装で仕様が変わった場合、
  記述と実コードが乖離するため、台帳の腐りを早期に発見できる。
- 機械照合させたい (次 run で自動 downrank したい) 項目は、本ファイルに書いたうえで
  `ledger/adjudications.jsonl` にも 1 行足す。手順は `ledger/README.md` 運用ガード (c)。

## 書式ルール

- **append-only + supersede**。既存の確定項目を黙って書き換えない。撤回するときは
  「実装で解消 (旧 SPEC を撤回)」節を作り、**撤回した事実と根拠**を残す。
- run 単位の節 (`## run {run_id} 申し送り ({date})`) を**新しい run が上**になるよう積む。
- 節の中は `### SPEC 確定 (再起票しない)` / `### 誤検知確定 (再起票しない)` / `### DOC 確定`
  / `### 実装で解消 (旧 SPEC / accepted を撤回)` / `### CLOSED (非再発を確認)` に分ける。
  節見出しは機械 registry の `verdict` 語彙に対応させる
  (`誤検知確定` = `false_positive` / `SPEC 確定` = `intentional`)。
  `wont_fix` は現時点で該当項目が無いため節を作らない。必要になったら
  `### wont_fix 確定 (再起票しない)` を追加する (節の追加は書式ルールの更新を伴う)。

---

## 初回登録テンプレート

新しい run の申し送りを書くときは、以下をコピーして埋める。**欄を削らない**
(埋められない欄がある = 三点裏取りが済んでいない ので、その項目は台帳に載せない)。

```markdown
## run {run_id} 申し送り ({YYYY-MM-DD})

### SPEC 確定 (再起票しない)

#### {finding_id} — {事象を 1 行で。何が「バグに見えた」か}
- **判定**: SPEC (意図仕様) | DOC (ドキュメント側の陳腐化) | FALSE_POSITIVE (観測 artifact)
- **根拠 (file:line)**: `path/to/File.php:123` (何をしているか) /
  `resources/js/pages/Foo/Bar.svelte:45` / `AGENTS.md#anchor` / `tests/Feature/FooTest.php`
  ※ 設計文書・実コード・テストの三点。**実在するパスのみ**書く
- **なぜ誤検知に見えたか**: {fake mode / 観測窓 / viewport 等、bug-hunt 側の事情}
- **driver 側の再発防止**: {この誤検知を機構で防ぐ手立て。SKILL.md のどの規約か / 「なし (人手注意のみ)」}
  ※ 人手の心構えで終わらせないための必須欄
- **watch_globs (機械 registry に載せる場合)**: `path/to/File.php`, `resources/js/pages/Foo/Bar.svelte`
  ※ この判定を無効化しうる実在ファイルのみ。過広 (`app/**` 等) 禁止
  ※ **既に registry に登録済なら glob を書き写さず「`A-NNN` に登録済 (正本は registry)」とだけ書く**
  (照合条件の正本は registry。二重管理は腐りの温床)
- **review_after_days**: {int > 0。仕様の揺れやすさで決める。例 120 / 180}
- **確定した run_id**: {run_id} (commit {short_sha})
- **再オープン条件**: {どうなったら再び finding として起票してよいか}
- **機械 registry**: `ledger/adjudications.jsonl` の `A-NNN` に登録済 / 未登録 (理由: …)
```

---

## run 20260803-203721 申し送り (2026-08-04)

### 誤検知確定 (再起票しない)

#### F-1-02 — 動画マニュアル削除後に「成功 flash が出ない」ように見えた
- **判定**: FALSE_POSITIVE (観測 artifact)
- **根拠 (file:line)**: `app/Http/Controllers/Projects/VideoManualController.php:230-232`
  (削除後 `projects.show` へ redirect し `->with('success', '動画マニュアルを削除しました')`) /
  `resources/js/lib/stores/toast.ts:23-29` (success/info/warning は **4000ms で auto-dismiss**、
  error のみ `null` = 自動消去しない) /
  `resources/js/components/organisms/ToastContainer.svelte`
  (`role="status"` + `data-testid="toast-{type}"` で描画) /
  `tests/Browser/FlashToastTest.php` (着地マーカーと**同一時間窓**で `toast-success` が可視になることを
  Chromium / WebKit の 2 レーンで pin)
- **なぜ誤検知に見えたか**: bug-hunt driver の観測は「操作 → 事後 snapshot」の 1 点サンプリングで、
  Bash 1 往復ぶん (数百 ms〜数秒、並列 shard ではさらに遅延) 後ろにずれる。可視窓 4000ms の後に
  snapshot が来れば「flash 無し」に見える。T095 の実装フェーズで **現行コードのまま** Browser テストを
  両レーンで走らせて PASS したため、アプリ側は正しいと確定した。**アプリコードは変更していない。**
- **driver 側の再発防止**: `SKILL.md` §一過性フィードバックの観測 — 書き込み操作の**直前**に
  feedback probe (`.claude/skills/app-bug-hunt/probes/feedback-probe.js`) を仕込み、直後に読む。
  「事後 snapshot に無い」を根拠に H7 を起票することを禁止した。
  回帰は `tests/js/bughunt/feedback-probe.test.ts` が固定する。
- **watch_globs (機械 registry に載せる場合)**: `ledger/adjudications.jsonl` の A-001 に登録済。
  **本ファイルには重複させない** (正本は registry)。
- **review_after_days**: 180 (A-001 と同値)
- **確定した run_id**: 20260803-203721 (commit 22d6d30)
- **再オープン条件**: 次のいずれか。
  (a) `VideoManualController::destroy` が `->with('success', ...)` を落とした、
  (b) `toast.ts` の success 用 `AUTO_DISMISS_MS` が大幅に短縮された、
  (c) feedback probe が `installed_now:false` かつ `seen`(visible:true) / `present_new` ともに空を返した。
  **probe を使わない事後 snapshot 単独の観察は再オープン根拠にならない。**
- **機械 registry**: `ledger/adjudications.jsonl` の `A-001` に登録済 (verdict=false_positive)
