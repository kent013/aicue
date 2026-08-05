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
5. LLM 呼び出しの Prism 直呼び(`app/Prompts/` の factory 経由のみ。PromptGuardrailTest が検出)
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

あなたはWebアプリケーション（Laravel + Svelte）の改善に関する概念設計レビュアーです。

【本件の前提 — 議論の対象外】
- 「bug-hunt 並列実行の枠数を 8 から 4 に揃える」はオーナー裁定 (AG-048b, 2026-08-05) で**確定済みの与件**です。
  「4 が妥当か」「揃える必要があるか」の是非は蒸し返さないでください。
- レビュー対象は「aicue リポジトリでその裁定をどう実装するか」という概念設計の質のみです。
- これは小さいインフラ整備タスクです。膨らませる提案は AGENTS.md 思考原則 2 (今必要なものだけ作る) に反します。

【レビュー観点】
1. 使命との整合性 / 期待効果の主張が誇張されていないか
2. 禁止事項違反 (特に禁止事項 1: 不変条件のテスト登録、禁止事項 3: dev DB 破壊操作)
3. セキュリティ不変条件の後退がないか (bug-hunt の dev DB 防御・ポート/DB/ロール隔離・orchestrator gate)
4. 実現可能性 (bash + Pest Architecture テスト)
5. 現状把握の抜け漏れ (cap=8 が写経されている箇所の洗い出しが十分か)
6. §5.1「触れる対象は狭める / 守る対象は狭めない」という原則が正しいか。誤りなら具体的に指摘せよ
7. スコープの適切さ (過大 / 過小)。特に「cap の SSOT 化」と「散文同期の Architecture テスト新設」が
   オーバーエンジニアリングでないか、逆に不足でないか
8. リスクの見落とし

【出力形式】
- 全体判定: APPROVED / CHANGES_REQUESTED
- 各観点ごとに [Critical] [Warning] [Suggestion] で分類して指摘
- Critical/Warning には修正提案を必ず添える
- 日本語で出力

---

## 概念設計

# 概念設計: bug-hunt 並列枠数 cap 8 → 4 (c2c feature: `bug-hunt-exec-infra`)

- c2c feature id: `bug-hunt-exec-infra` / feature_revision (取得時点): `10-811e41d263a7`
- オーナー裁定: **AG-048b (2026-08-05)** — 「並列実行の枠数を **4 に揃える**」。
  同日 AG-048 のキュレーター自治判定 (揃えない) は撤回済み。
  **裁定は確定した与件**であり本設計では蒸し返さない。設計対象は「aicue でどう実装するか」だけ。
- 想定 priority: Medium / theme: infrastructure

---

## 1. 仮説

**仮説**: bug-hunt の並列枠数 cap=8 は「1 箇所の定数」ではなく、スクリプト・スキル手順書・
規約 (AGENTS.md)・env ひな型・Python ツールの散文に**同じ数字が写経で散らばっている**。
そのため片側だけを 4 に下げると必ず不整合が残る。cap を 1 箇所の SSOT にまとめ、
散文側は機械 gate で同期を強制すれば、今回の裁定追従と将来の枠数変更の双方が安全になる。

**成功判定**:
1. `scripts/bug-hunt-shard.sh self-test` が exit 0 で、shard 5 / `bug_hunt_5` / `--parallel=6|8` が
   **すべて拒否**されることを自己検証している。
2. cap を写している散文 (AGENTS.md / SKILL.md / stories README / ledger README / env ひな型 /
   coverage・validate ツール) に cap 超過の literal が 1 つも残っていないことを
   **Architecture テストが deny-by-default で検出**する。
3. 隔離の不変条件 (枠ごとの DB・ポート・DB ロール分離 / `require_orchestrator` の既定拒否 /
   `env -i` による `DB_*`・`PG*` 遮断) が**一切緩んでいない**。

**検証したいこと**: 「枠数を下げる」変更で**守りの範囲まで一緒に縮めてしまう**事故を起こさないこと
(後述の設計原則 §5.1)。

---

## 2. 現状 (実査結果。ブリーフの記述は鵜呑みにせず実コードで裏を取った)

### 2.1 cap=8 が実装として効いている箇所 (= 割り当ての範囲)

| # | ファイル | 行 | 内容 |
|---|---------|---|------|
| A1 | `scripts/bug-hunt-shard.sh` | 66 | `SHARD_RE='^[0-8]$'` (`validate_shard` の受理範囲) |
| A2 | 同 | 67 | `SHARD_DB_RE="^${BUGHUNT_DB_PREFIX}(_[1-8])?$"` (★ dev DB 防御の核) |
| A3 | 同 | 124-129 | `valid_parallel_n()` = `2\|4\|6\|8` |
| A4 | 同 | 393 | `manifest_valid_shards()` の shard key 正規表現 `[0-8]` |
| A5 | 同 | 1160-1186 | `stories_for_shard()` の固定マップ (`6-*` / `8-*` の 12 分岐) |
| A6 | 同 | 1216-1246, 1380-1401 | self-test の cap=8 アサーション群 (`shard_db 8` / `shard_port 8` / `bug_hunt_8` 通過 / `--parallel=8` 受理) |

`BASE_PORT=8010` と `shard_port()`/`shard_url()`/`shard_db()` は **N から導出**しており、
cap そのものは持たない (= 受理範囲さえ絞れば :8015..:8018 と `bug_hunt_5..8` は到達不能になる)。

### 2.2 cap=8 が散文として写経されている箇所 (= 実行はしないが人間/LLM が読む正本)

| # | ファイル | 行 | 内容 |
|---|---------|---|------|
| B1 | `scripts/bug-hunt-shard.sh` | 18, 124, 1160 | ヘッダ/コメントの「shard 1..8 (cap=8、--parallel は 2/4/6/8)」 |
| B2 | 同 | 445, 1012, 1947 | die メッセージ「--parallel は 2/4/6/8 のみ (cap=8)」 |
| B3 | `AGENTS.md` | 210-211, 216 | 「並列 shard `:8011..8018`」「`^bug_hunt(_[1-8])?$`」 |
| B4 | `.claude/skills/app-bug-hunt/SKILL.md` | 3, 41, 98, 126 | description / `--parallel` 表 / provision 手順 / 固定マップ説明 |
| B5 | `.claude/skills/app-bug-hunt/stories/README.md` | 30 | 「cap=8、`--parallel` は 2/4/6/8」 |
| B6 | `.claude/skills/app-bug-hunt/ledger/README.md` | 24, 26 | 「shard 1..8 / `:8011..8018`」「`shard_id` に 0-8」 |
| B7 | `.claude/skills/app-bug-hunt/ledger/findings.schema.json` | 27 | `shard_id` の description「0-8 / :8011..8018」(**値制約ではない**) |
| B8 | `.claude/skills/app-bug-hunt/ledger/validate_findings.py` | 13-14 | docstring「並列 :8011..8018 (shard 1..8)」 |
| B9 | `.claude/skills/app-bug-hunt/coverage/merge_pcov.py` | 11-12, 223 | docstring「shard は 0-8」 |
| B10 | `.env.bughunt.local.example` | 6, 41 | 「DB=bug_hunt_{1..8} / :8011..8018」「`^bug_hunt(_[1-8])?$` のみ許可」 |
| B11 | `docs/worktree-isolation-strategy.md` | 205 | 「bughunt 環境の DB (`bug_hunt(_1..8)`)」 |
| B12 | `tests/Support/Ci/TestDatabaseEnv.php` | 42-53 | `DEV_DB_DENYLIST` に `bug_hunt_1`..`bug_hunt_8` (**守りの範囲**。§5.1 参照) |
| B13 | `tests/Unit/Ci/TestDatabaseEnvTest.php` | 107-114 | denylist を 1..8 で機械照合 (コメント「shard は :8011..:8018」) |
| B14 | `tests/Architecture/BughuntEnvExampleContractTest.php` | 18, 122 | コメント中の `^bug_hunt(_[1-8])?$` |
| B15 | `docs/testing-browser.md` | 38-40 | Browser lane pre-flight guard「`127.0.0.1:8010..8018`」「8 並列基盤」 |
| B16 | `scripts/run-browser-test.sh` | 47, 61 | 「8 並列」コメント / `for port in {8010..8018}` (**守りの範囲**) |
| B17 | `scripts/run-browser-test.contract.test.ts` | 139, 144 | 同 guard のテスト側ミラー (**守りの範囲**) |
| B18 | `scripts/verify-global-test-lock.sh` | 1096, 1106 | ポート占有 fixture の候補列挙 (**守りの範囲**) |
| B19 | `scripts/README.md` | 31 | 「bughunt ポート `:8010..8018` の pre-flight guard」(**守りの範囲**) |

### 2.3 cap=8 は一度も使われていない (実測)

- `devnotes/*-bug-hunt/` の全 run (7 件の並列 run) の manifest は **`"parallel": 4` のみ**。
- 実在する shard ディレクトリは `shard-0`..`shard-4` のみ (shard-5 以降は 0 件)。
- ledger の `shard_id` 実値も 1..4 のみ。

→ **cap=8 (`--parallel=6|8` と shard 5..8) は本リポジトリでは一度も実行されたことがない
デッドキャパシティ**。既定値は既に `parallel=4` (`scripts/bug-hunt-shard.sh:1904`) であり、
今回の変更で**既定の走行挙動は変わらない**。

### 2.4 既存の機械 gate と、今回効かない理由

- `BughuntOrchestratorGateInvariantTest` — `require_orchestrator` の既定拒否と
  AGENTS.md / `bughunt-shard.md` の散文 gate を pin する。pin している needle は
  `BUGHUNT_ORCHESTRATOR=1` / `default-deny` / `` `provision`/`teardown` `` 等で、
  **枠数は 1 つも pin していない** → 8 のまま残っても赤くならない。
- `BughuntEnvExampleContractTest` — `.env.bughunt.local.example` の `DB_DATABASE` と
  スクリプトの `BUGHUNT_DB_PREFIX` 既定の一致を pin する。**接頭辞だけで、枠数は見ていない**。
- `TestDatabaseEnvTest` — denylist を 1..8 で機械照合する。ここは唯一 8 を pin している箇所だが、
  対象は「守りの denylist」であって割り当て範囲ではない (§5.1)。

→ ブリーフの「Architecture テストが同じ文字列を持っている可能性がある。片側だけ直すと
gate が赤くなる」という懸念は、**実査の結果ほぼ外れ**だった。実際には
**枠数を守る gate が 1 つも無い**のが現状で、これが「数字が散らばったまま腐る」根本原因である。

---

## 3. 課題

1. **裁定追従ができていない** — 家系で 4 に揃える裁定 (AG-048b) に対し aicue は cap=8 のまま。
2. **SSOT が無い** — cap という 1 つの概念が実装 6 箇所 + 散文 19 箇所に写経されている。
   人手で直す限り必ず取りこぼす。
3. **枠数を守る機械 gate が無い** — 上記 §2.4 の通り、ずれても `composer test` は緑のまま。
   AGENTS.md 禁止事項 1 (不変条件はテスト登録まで含めて「実装済み」) の観点で欠落している。
4. **デッドキャパシティの維持コスト** — 一度も使われていない `6-*`/`8-*` のストーリーマップ
   12 分岐と、それに対応する self-test を保守し続けている。

---

## 4. 方針

### 4.1 cap を SSOT 化する

`scripts/bug-hunt-shard.sh` に **ハードコード定数** `BUGHUNT_SHARD_CAP=4` を置き、
`SHARD_RE` / `SHARD_DB_RE` / manifest key 正規表現をここから導出する。

- **env 上書き可能にしない** (`${BUGHUNT_SHARD_CAP:-4}` にしない)。`SHARD_DB_RE` は
  dev DB 防御の核であり、外から広げられる余地を作ることは**ガードの緩和**にあたる
  (裁定 rationale の「不変条件は従来どおり維持」に反する)。`BUGHUNT_DB_PREFIX` が
  env 可変なのは既存挙動なので触らない。
- 導出は 1 桁の文字クラス (`^[0-${CAP}]$`) を使うため **cap ≤ 9 が前提**。
  ポート採番が `8010+N` である以上 cap ≤ 9 は元から構造的制約なので、その旨をコメントに残し、
  self-test で「cap が 1..9 の 1 文字であること」を assert する。

### 4.2 割り当ての範囲を 4 に下げる

- `valid_parallel_n` の受理集合: `2|4|6|8` → **`2|4`**。
- `stories_for_shard` から `6-*` / `8-*` の分岐を**削除**する (AGENTS.md 思考原則 3
  「後方互換の並走を残さない」)。`2-*` / `4-*` は現行のまま変更しない。
- self-test の cap=8 アサーションを cap=4 版に置換し、**5 / `bug_hunt_5` / `--parallel=8` が
  拒否されること**を正のアサーションとして追加する (境界が下がったことを機械で固定する)。

### 4.3 散文を 4 に揃え、散文同期を機械 gate 化する

新規 Architecture テスト **`BughuntShardCapInvariantTest`** を追加し、以下を deny-by-default で固定する:

1. スクリプトから抽出した `BUGHUNT_SHARD_CAP` が **4** であること。
2. `SHARD_RE` / `SHARD_DB_RE` / manifest key 正規表現が cap から導出されており、
   cap を写した literal (`[0-8]` / `_[1-8]`) が残っていないこと。
3. `valid_parallel_n` の受理集合が `{2,4}` (= cap 以下の偶数) であること。
4. **cap 散文セット** (下記の固定リスト) に cap 超過 literal
   (`8015`〜`8018` / `8011..8018` / `_[1-8]` / `1..8` / `0-8` / `cap=8` / `2/4/6/8`) が
   1 つも無いこと。
5. 負のコントロール: 上記を混入させた fixture 文字列を実際に検出すること。

**cap 散文セット (この gate の走査対象)**: `AGENTS.md`, `.claude/skills/app-bug-hunt/SKILL.md`,
`.claude/skills/app-bug-hunt/stories/README.md`, `.claude/skills/app-bug-hunt/ledger/README.md`,
`.claude/skills/app-bug-hunt/ledger/findings.schema.json`,
`.claude/skills/app-bug-hunt/ledger/validate_findings.py`,
`.claude/skills/app-bug-hunt/coverage/merge_pcov.py`, `.env.bughunt.local.example`,
`scripts/bug-hunt-shard.sh`, `docs/worktree-isolation-strategy.md`。

**走査対象に入れないファイル**は §5.1 の「守りの範囲」なので、テスト内にコメントで
除外理由を明記する (allowlist ではなく、走査集合を明示列挙する形にして「なんとなく漏れた」
を作らない)。

---

## 5. 設計原則と代替案

### 5.1 governing principle: 「触れる対象は狭める / 守る対象は狭めない」

枠数を下げる変更では、**同じ数字 8 でも意味が 2 種類ある**。両方を機械的に 4 にすると
防御が弱くなる。したがって方向を分けて扱う:

| 種別 | 対象 | 今回の扱い | 理由 |
|---|---|---|---|
| 割り当て (触れる対象) | `SHARD_RE`, `SHARD_DB_RE`, `valid_parallel_n`, `stories_for_shard`, manifest key | **4 に狭める** | 狭める = スクリプトが触れる DB / ポートが減る = より安全側 |
| 守り (守る対象) | `TestDatabaseEnv::DEV_DB_DENYLIST` の `bug_hunt_5..8`, `run-browser-test.sh` の `{8010..8018}` pre-flight, その contract test / `verify-global-test-lock.sh` の候補列挙 | **8 のまま維持し、理由をコメントで明示** | 狭める = 守る範囲が減る。過去 cap=8 期に作られ得る残留 DB / 残留 serve を検出できなくなる |

- `DEV_DB_DENYLIST` の `bug_hunt_5..8` を残すコストはゼロ (定数 4 行) で、
  `isAllowedTestDatabase()` の allowlist regex が構造的に除外しているため機能重複だが、
  「bug-hunt DB は絶対に触らない」という**意図の二重防御**という既存の設計意図
  (`TestDatabaseEnv` のクラス docblock) を壊さない。
- Browser lane の pre-flight guard を狭めると、`:8015` を掴んだ残留 bughunt serve を
  検出できなくなる。guard は TOCTOU 込みで「偽赤に留まる範囲で受容」と明記されている
  (`docs/testing-browser.md`) ので、広いままが正しい。
- ただし**散文の表現は直す**。「意図的に隔離された 8 並列基盤」→「4 並列基盤 (guard は
  残留検出のため :8018 まで広く見る)」のように、**数字ではなく理由を書く**。

### 5.2 代替案と却下理由

| 案 | 内容 | 却下理由 |
|---|------|---------|
| Alt-1 | 実装は据え置き (`SHARD_RE` は 8 のまま) で、既定 4 の運用ルールだけ文書化 | 裁定は「枠数を 4 に揃える」。既定は既に 4 なので**何も変わらない** = 追従にならない。デッドキャパシティも残る |
| Alt-2 | cap を env 可変 (`BUGHUNT_SHARD_CAP=${BUGHUNT_SHARD_CAP:-4}`) にして環境ごとに選べるようにする | 家系で揃える裁定の趣旨に反する。かつ `SHARD_DB_RE` を外から広げられる = dev DB 防御の緩和 (禁止) |
| Alt-3 | `stories_for_shard` の `6-*`/`8-*` を残し `valid_parallel_n` だけ絞る | 到達不能コードの温存。AGENTS.md 思考原則 3 (後方互換の並走を残さない) 違反 |
| Alt-4 | 散文同期テストを作らず、今回だけ手で全部直す | ずれても緑のまま = 今回と同じ腐り方を繰り返す。AGENTS.md 禁止事項 1 の趣旨に反する |
| Alt-5 | 守りの範囲 (denylist / port guard) も一律 4 に縮める | §5.1 の通り防御の後退。裁定 rationale「不変条件は従来どおり維持する」に反する |
| Alt-6 | cap 散文同期を**リポジトリ全文**の grep で強制する | `docs/TODO-closed.md` の過去ログや `devnotes/` の過去 run など**歴史記録**まで赤くなる。走査集合は明示列挙にする |

---

## 6. スコープ境界

### 6.1 スコープに入れるもの

1. `scripts/bug-hunt-shard.sh` の cap SSOT 化・受理範囲 4 化・ストーリーマップ整理・self-test 更新。
2. cap を写した散文 (§2.2 の B1〜B11, B13, B14) の 4 化。
   ただし B12 (denylist の値) は据え置き、コメントのみ更新。
3. 新規 `tests/Architecture/BughuntShardCapInvariantTest.php` の追加。
4. `docs/testing-browser.md` / `scripts/run-browser-test.sh` / `scripts/README.md` の
   **説明文のみ**更新 (guard のポート範囲そのものは維持し、「なぜ広いか」を書く)。

### 6.2 スコープに入れないもの (と、その理由)

| 対象 | 理由 |
|---|---|
| `scripts/bug-hunt-inventory-check.sh` (目録ドリフト検査) | c2c 台帳の boundary で明示的に別 feature (`bughunt-inventory-generation`)。枠数と無関係 |
| `config/bughunt.php` / `BughuntCoverageMiddleware` / seeder 群 | 別 feature (`bughunt-runtime`)。枠数に依存しない (DB 名判定は `DetectsBughuntDatabase` の `^bug_hunt(_[1-8])?$` だが、これは**守りの regex** = §5.1 により据え置き。コメント同期のみ scope 内としない — §6.3 参照) |
| bug-hunt スキルの探索手順・ストーリー本文の改訂 | 別 feature (`skill-bug-hunt`)。今回は枠数記述の同期のみ |
| `TestDatabaseEnv::DEV_DB_DENYLIST` の値の変更 | §5.1。守りの範囲は狭めない |
| Browser lane pre-flight guard のポート範囲の変更 | §5.1。同上 |
| ストーリー割り当ての内容 (どの shard に S いくつを割るか) | 裁定は枠数のみ。`2-*`/`4-*` マップは実績があり触らない |
| 実 bug-hunt 環境の provision / teardown 実行 | 検証は `self-test` (実資源に触れない) で足りる。ブリーフでも明示的に不要 |
| 残留 `bug_hunt_5`..`bug_hunt_8` DB の削除 | dev DB への破壊操作は LLM 判断で実行しない (AGENTS.md 禁止事項 3)。実測上そもそも作られたことがない (§2.3)。存在確認の read-only 手順のみ申し送る |
| 他リポジトリ (テンプレート / spirux / motivation) への展開 | 本タスクは aicue の追従のみ。c2c への `status_reported` 追記は実装完了後の別手順 |

### 6.3 判断が必要だった境界: `DetectsBughuntDatabase`

`database/seeders/Concerns/DetectsBughuntDatabase.php` の `BUGHUNT_DB_REGEX = '/^bug_hunt(_[1-8])?$/'`
は c2c boundary 上は `bughunt-runtime` (対象外) だが、**同じ数字**である。

判断: **据え置く**。理由は 2 つ —
(a) これは「この DB に限ってシードしてよい」という **fail-secure ガード** = 守りの範囲であり、
広いままでも危険側に倒れない (むしろ狭めると残留 `bug_hunt_5` に対して seeder が
no-op ではなく「bughunt でない」と誤判定する)。
(b) feature boundary が別。台帳上の責務境界を跨いで直すと c2c の実装状況報告が濁る。

代わりに**コメントに「cap は 4 だが本 regex は守りの範囲なので 1..8 を維持する」と明記**する。
これは §6.1-2 の散文更新に含める (走査対象セットには入れない)。

---

## 7. 期待効果

- 家系 (テンプレート / aigenba / spirux / aicue / motivation) で bug-hunt の実行時間・資源消費が
  比較可能になり、移植時の判断が不要になる (裁定 rationale そのもの)。
- 一度も使われていないデッドキャパシティ (12 分岐 + 対応 self-test) が消え、
  bug-hunt 基盤の読解コストが下がる。
- cap が SSOT + 機械 gate になり、次に枠数を動かすときは **1 定数 + テスト 1 本**で済む。
- **使命への貢献は間接的**である点を正直に書く: bug-hunt は「思考ゼロ・編集ゼロ」を
  実際に成立させているかを実ブラウザで検査する装置であり、本変更はその基盤の
  保守性・可搬性の改善であって、エンドユーザー体験を直接変えるものではない。

## 8. リスクと後退の可能性

| リスク | 影響 | 緩和 |
|---|---|---|
| 過去 run (parallel=6/8) の `verify-run` が manifest 不整合で落ちる | 低 (実測 0 件。全過去 run は parallel=4) | 受容。§2.3 の実測を根拠に記録する |
| `manifest_valid_shards` が shard key 5..8 を warning+skip する | 低 (同上、実在しない) | 受容 |
| cap 導出の文字クラス (`^[0-4]$`) が cap ≥ 10 で壊れる | 中 (将来) | cap ≤ 9 をコメントで明示 + self-test で 1 桁を assert |
| 散文同期 gate が過検出して無関係な変更を赤くする | 中 | 走査対象を明示列挙 (§4.3)。歴史記録 (`devnotes/` / `TODO-closed.md`) は対象外 |
| 守りの範囲 (denylist / port guard) を「揃っていない」と後続が誤って縮める | 中 | §5.1 の原則を各所コメントに残し、`BughuntShardCapInvariantTest` の docblock にも書く |

## 9. 検証方法

1. `scripts/bug-hunt-shard.sh self-test` → `self-test: all passed` (実資源に触れない)。
2. `composer test` の Architecture / Unit 群 (特に `BughuntShardCapInvariantTest`,
   `BughuntEnvExampleContractTest`, `BughuntOrchestratorGateInvariantTest`,
   `TestDatabaseEnvTest`) が green。
3. `vendor/bin/pint --test` / `composer phpstan` (新規テストファイル分)。
4. `python3 -m unittest` (bug-hunt の Python ツール群。docstring 変更のみだが回帰確認)。
5. 実 bug-hunt の provision/teardown は**行わない**。

