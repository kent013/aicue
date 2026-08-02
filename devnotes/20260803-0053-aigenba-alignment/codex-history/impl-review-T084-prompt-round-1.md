# AI-CUE 実装レビュー依頼 (T084 / トラック T-c: 施策 11-14)

## 使命 (North Star) — AGENTS.md より

**AI-CUE** は、現場に既にある**作業手順書(SOP)を起点に**、AI が撮るべきカットを設計した**動画シナリオ**を生成し、そのシナリオを**スマホ(PWA)でナビゲーション撮影**することで、専門知識ゼロの現場作業者でも**標準化されたマニュアル動画**を作れるようにする。

- 「思考ゼロ・編集ゼロ」— 台本作成・撮影判断・編集の 3 ハードルを AI とナビ撮影で肩代わりする。
- 競合(OJT を撮って形式化する tebiki)と異なり、**標準作業を起点に AI が教材設計し撮影を指示する**（撮影者・教える人のスキルに品質を依存させない）。
- 熟練者の暗黙知を動画マニュアルという形式知へ変換する装置（SECI）。

> **v1 スコープ**: 字幕のみ(TTS 後回し) / 撮影は PWA(同一オリジン・セッション認証) / 動画合成は自前 ffmpeg / 単一 Default Project。

## 禁止事項 — AGENTS.md より

1. テストなしの実装完了報告(不変条件は対応する Architecture/Feature テストへの登録まで含めて「実装済み」)
2. PHPStan エラーの widen(型を緩めて黙らせる)・baseline 化
3. dev DB への破壊操作(`migrate:fresh` 等)をエージェント判断で実行すること
4. `response()->json()` の直書き(DTO / JsonResource / Inertia を使う。仕様固定 endpoint のみ例外)
5. LLM 呼び出しの Prism 直呼び(`app/Prompts/` の factory 経由のみ。PromptGuardrailTest が検出)
6. prompt 文字列のコード直書き(`resources/prompts/*.yaml` に置く)
7. 操作系 POST の応答での `redirect()->intended()`(ログイン直後フロー専用。招待送信等は `back()->with(...)` で完結させる)
8. 必須条件未充足を理由にボタンを disabled にする UI(押下時にエラー表示する。DESIGN.md)

## セキュリティ不変条件(アプリ都合で緩めない) — AGENTS.md より

1. **tenant キー不信**: ownership/actor/tenant キーを payload から受け取らない(`ProhibitsProtectedKeys` + `MassAssignmentSafetyTest`)
2. **子は親に属する**: nested route の不整合は**認可より前に 404**(`NestedRouteIdorDefenseTest` の inventory に登録必須)
3. **cross-org 不可**: 組織を跨ぐ read/write をしない(relation / org-scoped 解決経由のみ)
4. **untrusted 文字列は UserInput 型経由でのみ prompt に入れる**
5. **権限判定は常に `laratrust_team_id` を明示**(strict_check=true)
6. **PII(email/name)は CipherSweet**。検索は `whereBlind()`(平文 where は hit しない)
7. **課金の冪等性**: webhook は冪等マシン経由、チケットは reserve→commit/release の 2 フェーズ
8. **外部 URL 取得は SSRF 検査経由**: `Kent013\SsrfPin\UrlSafetyInspector` / `PinnedHttpClient` を通す。安全境界は `config/ssrf-pin.php` に pin する(`SsrfPinBoundaryTest` が pin 値を固定)

## 思考原則 — 全議論に適用

まず仮説を立てろ。何を検証したいのか、なぜそう考えるのか、どうなれば成功と判断するのかを明確にしてから手を動かせ。仮説なき改善はただの試行錯誤であり、結果から学ぶことができない。

データに真摯に向き合え。成果だけでなく、多様性の変化、構造の揺らぎ、想定外のパターン — 全てが判断材料になる。数値を見て即座に閾値を弄るな。何が起きているのかを理解し、なぜそうなったのかを考え、どの方向に進むべきかを判断してから手を動かせ。

先人の知恵を探せ。自分たちだけで登る必要はない。乗るべき巨人の肩があるなら乗れ。

機能の名前に立ち返れ。名前はその機能が果たすべき役割を示している。現在の設計がその役割を果たしているか、常に問え。

仕組みが機能していない段階で値を弄るな。閾値チューニングやフィールド追加は、設計の方向性が正しいと確認できてから行え。方向性が間違っているなら、値をいくら調整しても意味はない。設計そのものを見直せ。成果が出なければ早期に見切り、次の仮説へ進め。

## ツール使用制限

コマンド実行・ファイル書き込みは一切行わず、提供されたテキストの分析に集中すること。ファイル読み込みは許可。

---

# あなたの役割

あなたは AI-CUE リポジトリの **実装レビュアー** である。以下のブランチ差分 (`todo/T084`, main からの差分 2 commit) を、**詳細設計書どおりに実装されているか**という観点でレビューせよ。

## レビュー観点 (優先順)

1. **設計乖離**: 詳細設計書 (施策 11-14) の記述と実装がずれていないか。設計が求めた不変条件を実際に固定しているか。
2. **禁止事項・セキュリティ不変条件への抵触**: 特に禁止事項 #1 (テストなしの完了報告)・#2 (PHPStan widen/baseline)・#3 (dev DB 破壊操作)。
   - 本 diff は `tests/Architecture/*.php` + `tests/js/architecture/*.ts` + `docs/*.md` + `.claude/skills/**` + `AGENTS.md` のみを触る。アプリ本体 (`app/` `routes/` `resources/js/`) は一切変更していない。
   - ただし **`BugHuntInventoryCheckInvariantTest` は sandbox を掘って実際に bash スクリプトを走らせる**。ここに dev DB / 実リポジトリを壊すリスクが無いかを厳しく見よ (PATH shim, `sys_get_temp_dir()`, 再帰 rmdir の対象範囲, `bhicRemoveSandbox` の symlink 追従など)。
3. **テストが空振りしていないか (最重要)**: 施策 11/12 の中心は「gate が本当に事故を検出するか」である。
   - 各テストが **常に green になるだけの飾り** になっていないか。
   - 負のコントロール (fixture) が、**正の gate と同一の判定器**を通っているか。fixture 専用の緩い判定器を作って「検出できた」と偽装していないか。
   - 正規表現・token 走査に **silent hole** (すり抜け) が無いか。すり抜けた場合に gate が黙って通るのか、fail するのか。
   - 「走査結果が 0 件でも green」になる構造 (走査が壊れても気づけない) が残っていないか。
4. **後退リスク・偽陽性リスク**: 文言 pin (SKILL.md / AGENTS.md / bughunt-shard.md の日本語文字列一致) は、正当な文書改善で誤爆する。誤爆時に開発者が「何を直せばよいか」判断できる失敗メッセージになっているか。pin の粒度は妥当か。
5. **PHPStan level 10 適合**: なお本リポジトリの `phpstan.neon` の `paths` は `app` `config` `database` `routes` のみで **`tests/` は解析対象外** (既存規約)。したがって新規テストコードは PHPStan では守られていない。その前提で、型注釈 (`@var` / `@return`) が**実際の値と食い違っていないか**を目視で確認せよ (食い違いは PHPStan では検出されない)。
6. **PHP / TypeScript の実装上の欠陥**: 境界条件、null 安全、正規表現の誤り、パフォーマンス (全ファイル走査を 1 テストにつき毎回やり直していないか) など。

## 出力形式

指摘は **[Critical] / [Warning] / [Suggestion]** の 3 段階でラベル付けし、各指摘に「該当ファイル:行」「なぜ問題か」「どう直すか」を書け。

- **[Critical]** = マージを止めるべきもの。設計乖離・禁止事項抵触・**gate が空振りしていて不変条件を実際には固定できていない**もの・破壊リスク。
- **[Warning]** = マージはできるが直すべきもの。
- **[Suggestion]** = 任意。

最後に **verdict: APPROVED / CHANGES_REQUESTED** を明記せよ。
指摘が無い箇所を無理に埋めなくてよい。**「設計どおりで問題なし」なら、そう書くこと。**

---

# 参照: 詳細設計書の該当施策 (施策 11-14)

以下は `devnotes/20260803-0053-aigenba-alignment/detailed-design.md` の抜粋である。**これが正本**であり、Codex 合議 11 ラウンドで APPROVED 済みである。実装がこれから逸脱していないかを見よ (逆に、設計そのものへの疑義があれば [Warning] 以下で述べてよい)。

```markdown
# 施策 11: 汎用 Architecture gate 移植 (6 本)

### 横断原則（概念設計 Round 1 Warning）

各 invariant の source of truth は **AI-CUE の `AGENTS.md` / `docs` / 実スクリプト**に置く。
**aigenba の文言・path を比較対象にしない**（verbatim 移植は禁止）。

### 対象

| # | テスト | **固定する事故 / 不変条件** | AI-CUE 側の SoT |
|---|---|---|---|
| 11-1 | `PhpstanWrapperInvariantTest` | orbstack virtiofs で phar 並列 open が死ぬ回避策が外れる退行 | `composer.json:108-110` / `scripts/phpstan.sh` |
| 11-2 | `BughuntOrchestratorGateInvariantTest` | AGENTS.md が「非交渉」と書く `BUGHUNT_ORCHESTRATOR=1` default-deny の 2 層 gate 崩れ | AGENTS.md §bug-hunt / `scripts/bug-hunt-shard.sh` / `.claude/agents/bughunt-shard.md` |
| 11-3 | `BugHuntInventoryCheckInvariantTest` | `bug-hunt-inventory-check.sh` の exit code 規約（0=一致 / 3=ドリフト）崩れ | `scripts/bug-hunt-inventory-check.sh` |
| 11-4 | `BugHuntSkillInvariantTest` | 「finding は停止信号ではない」規約の喪失 | `.claude/skills/app-bug-hunt/SKILL.md` |
| 11-5 | `BughuntEnvExampleContractTest` | `.env.bughunt.local.example` の production 同等性最小セット欠落 | `.env.bughunt.local.example` |
| 11-6 | `InertiaRenderPageExistsInvariantTest` | `Inertia::render` の literal 参照先ページ不在 → **本番白画面** | `app/` の literal 参照 / `resources/js/pages/` |

> **`WorktreeRuleInvariantTest` は本施策に含めない**。AI-CUE の worktree 規約
> （`.claude/worktrees/tasks/<id>`・ブランチ削除責務が呼び出し側）は aigenba と異なり、
> 検査項目の**再設計**が必要。別 TODO へ切り出す。

### テスト計画（全 gate 共通）

- [x] **負のコントロール必須**: 各 gate について「AI-CUE で意図どおり fail する状態」を
      手元で作って確認する。空振り gate を green として扱わない
- [x] 11-6 は現時点で **dangling 0 件**（literal 参照 39 件を手検証済み）= 予防 gate
- [x] DB 不使用の静的検査に寄せる（既存 Architecture テストと同じ作法）

### リスク

| リスク | 対策 |
|---|---|
| 11-6 の PhpToken 走査が AI-CUE の記法（変数展開・定数）で誤検出する | **literal 引数のみ**を対象にする（aigenba も同方針）。非 literal は検査対象外として明記 |
| 11-2〜11-5 が AI-CUE の bug-hunt 実装と細部で合わない（AI-CUE の `bug-hunt-shard.sh` は 1982 行で aigenba の 1305 行より進んでいる） | **AI-CUE の実スクリプトを読んで検査項目を書き直す**。aigenba のテスト本文を写さない |

---

# 施策 12: JS gate 移植 (1 本)

### 変更箇所

- `tests/js/architecture/pages-path-case-invariant.test.ts` — **新規**

### 固定する不変条件

大文字 `./Pages/` 参照の禁止（case-sensitive CI で解決不能になる）。
**施策 10 で spirux 由来の `resources/js/Pages/` 参照が実際に混入していた**ことから実効性がある。

検査対象は静的 import / glob に加え、**dynamic import の文字列リテラル**も含める
（design-review R1 Suggestion。`import('./Pages/...')` の漏れを防ぐ）。

### テスト計画

- [x] 負のコントロール: `'./Pages/Foo.svelte'` を含むダミー文字列で fail することを確認
- [x] `pnpm test` green

---

# 施策 13: bug-hunt 文書 + docs 整備

### 変更箇所

| ファイル | 内容 |
|---|---|
| `.claude/skills/app-bug-hunt/capability-catalog.md`（新規） | `capability_tag` 語彙の正本。**枠組みのみ移植**し、語彙は AI-CUE ドメインで作る。**先に `SOP → scenario → capture → render` の責務境界を定義**してから capability_id を割り当てる（design-review R1 Suggestion。境界を後決めすると語彙がブレる） |
| `docs/pnpm-global-virtual-store-runbook.md`（新規） | AGENTS.md §worktree が依存する `enableGlobalVirtualStore` の背景・障害対応 |
| `docs/worktree-isolation-strategy.md`（新規） | 同上。worktree 分離設計の背景 |
| `AGENTS.md` | 上記への参照追記 |

> `coverage-audit.md` は **本施策に含めない**。AI-CUE では route 全面監査が未実施であり、
> 「空の枠組み」を作っても意味がない。実監査を伴う別 TODO とする。

### テスト計画

- [x] 文書のため自動テストなし。`AGENTS.md` からの参照切れが無いことを確認

---

# 施策 14: aigenba へ返す handoff 文書

### 変更箇所

- `devnotes/20260803-0053-aigenba-alignment/aigenba-handoff.md` — **新規**

### 内容（「合わせる」は双方向）

| # | 差分 | 提案理由 |
|---|---|---|
| F-1 | `scripts/bug-hunt-shard.sh` の `guard_shard_db_name` / `guard_bughunt_runtime` / `guard_admin_provision` の 3 段 DB guard、`secret_xtrace_off` / `secret_xtrace_restore` | `secret_xtrace_off` は `set -x` 下で API key が漏れるのを防ぐ。安全性に直結 |
| F-2 | `coverage/correlate.py` のヘッダ列 index 動的決定（5 列 / 6 列の両節対応）+ backtick 剥がし | aigenba の operations.md が将来 6 列節を持つと誤 join する |
| F-3 | `scripts/audit-gate.test.ts` | supply-chain gate 自体は両者にあるが、gate のテストは AI-CUE のみ |
| **F-4** | **施策 6 の bfcache 秘匿・再検証（P3-b）** | aigenba は Safari の bfcache を「スコープ外」としているが、**PWA を持つなら同じ穴がある**。AI-CUE 固有の追加として実装した内容を返す |
| **F-5** | `validate_findings.py` の `import io` モジュール先頭化 / `open()` の context manager 化 | 可読性・リソース解放の改善（design-review R1 Suggestion）。**AI-CUE 側では整列優先で見送った**ため、aigenba が直したら双方で追随する |

各項目に**受け手側の採否結果欄（adopt / reject / defer）**を用意し、往復管理できる形にする
（design-review R1 Suggestion）。

既存の `devnotes/20260717-0035-aigenba-billing-parity/aigenba-divergence-ledger.md` と
同じカテゴリ運用（B = 返す）に従う。

---

## 実装モード

| 項目 | 内容 |
```

---

# 参照: 施策 9-10 (直前トラック T-b。T084 の前提となる skill ディレクトリ変更)

```markdown
さらに、分岐ロジック自体は **vitest のユニットテストで固定**する（施策 6）。
E2E は統合挙動の確認に絞る（`pageshow(persisted)` 分岐は E2E 単体だと不安定なため）。

### fail-first の置き場所（design-review R2 Warning）

Chromium では施策 4 適用後に bfcache 復元が起きなくなるため、**シナリオ 4 の fail-first を
Chromium で再現できない**。したがって:

1. **WebKit レーンで fail-first を確認**する（第一）
2. 併せて **guard の vitest で「秘匿しなければ復元後に旧 DOM が可視のまま」という負のコントロール**を
   先行させ、秘匿ロジックの必要性をユニット層で先に固定する（施策 6）

### リスク

| リスク | 対策 |
|---|---|
| Chromium で bfcache 復元が再現できず**テストが空振りする** | 上記の完了条件で対応。**空振りテストを green として扱わない**（負のコントロールを必ず置き、「復元が起きていない」ことを検出できるようにする） |
| WebKit レーン追加で CI 実行時間が増える | 既存 SmokeTest と同じ排他レーンに乗せる。**実行時間を理由に WebKit を落とすことはしない**（落とすと恒久自動回帰が消えるため。R2 Critical） |
| Browser テストは実行が重く CI で不安定 | `run-browser-test.sh` が排他 + 並列上限を管理済み |

---

# 施策 9: adjudication registry の機構修復

### 変更箇所

- `.claude/skills/app-bug-hunt/ledger/validate_findings.py`
  - `COND_KEYS`（L197）
  - `analyze()`（L139-141）
  - `main()`（L643-668）
- `.claude/skills/app-bug-hunt/ledger/test_validate_findings.py` — 回帰テスト追加

### 現行コード

```python
COND_KEYS = {"viewport", "auth_role", "browser", "feature_flag", "precondition"}
...
def analyze(path) -> Report:
    ...
    lines = sys.stdin if path == "-" else open(path, encoding="utf-8")
...
    rep = analyze(args.path)
    ...
            findings = [a for _, a, _ in load_jsonl(args.path) if isinstance(a, dict)]
```

### 変更後コード

**(1) `COND_KEYS` に `mode` / `env` を governed key として追加**（aigenba 整列）:

```python
# mode/env は bug-hunt harness の第一級ディメンション (manifest.real_mode / 走行環境)。
# fake 限定の偽陽性を real モードの実退行に誤適用しないための load-bearing な条件なので、
# generic な precondition に潰さず governed key として持つ (spirux HARNESS-01 の教訓:
# 旧 COND_KEYS に mode/env が無く schema drift → fail-closed で抑制が全面停止した。
# AI-CUE も同じ状態だった = 2026-08-02 監査で A-008 が bad condition key で fail)。
COND_KEYS = {"viewport", "auth_role", "browser", "feature_flag", "precondition", "mode", "env"}
```

**(2) stdin 2-pass の修正**（aigenba 整列）:

```

---

# レビュー対象: `git diff main...HEAD` (todo/T084)

2 commit:
- `4493f44 test: T084 施策 11/12 汎用 gate 移植 (Architecture 6 本 + JS 1 本)`
- `50f9067 docs: T084 施策 13/14 文書整備 + aigenba handoff`

```diff
diff --git a/.claude/skills/app-bug-hunt/capability-catalog.md b/.claude/skills/app-bug-hunt/capability-catalog.md
new file mode 100644
index 0000000..7e917c4
--- /dev/null
+++ b/.claude/skills/app-bug-hunt/capability-catalog.md
@@ -0,0 +1,131 @@
+# Capability Catalog (機能一覧・bug-hunt 正本) — AI-CUE
+
+`ledger/findings.schema.json` の必須フィールド `capability_tag` と、stories/ カードが消化する
+機能単位を指す **capability_id の語彙正本**。
+
+- これは「機構 (route / job / CLI) を **user-value で grouping した overlay**」であり **MECE ではない**
+  (完全性を主張しない)。分母の正本は `screens.md` (画面) と `operations.md` (書き込み操作) の 2 つで、
+  本表はその上に「利用者にとって何が達成できるか」を重ねたもの。
+- `coverage/correlate.py` は route 名を持たない finding を `capability_tag` 経由で機構群へ
+  ブロードキャストする (`via_capability`)。**id の粒度がそのまま相関の粒度**になる。
+
+## 責務境界 (先に決める。id はこの境界に従って割り当てる)
+
+AI-CUE の中核は **SOP → シナリオ → 撮影 → レンダ** の 4 段パイプライン (North Star: AGENTS.md §使命)。
+capability_id を先に切ると「どの段の機能か」が run ごとにブレるため、**まず段の責務境界を固定する**。
+
+| 段 | 責務 (この段が達成すること) | 主な書き込み対象 | 段の終端 (次段へ渡せる状態) |
+|---|---|---|---|
+| **SOP** (手順書取り込み) | 現場の作業手順書 (PDF/Excel) と最小メタ (タイトル / カテゴリ) を受け取り、AI 解析にかけられる器を用意する | `video_manuals` (status=draft) / `source_documents` | 解析可能 (手順書 ≥1 の draft) |
+| **SCEN** (シナリオ設計) | 手順書を AI が分解し Cut (手順=step / 急所=point) ツリーを生成、人が編集して**撮影可能**にする | `cuts` / `video_manuals.scenario_version` / `status` (共有ロック規約) | status=ready (撮影に渡せる) |
+| **CAP** (ナビ撮影 / PWA) | シナリオに従って cut ごとにテイクを撮影・アップロード・比較し、**cut ごとに 1 本採用する** | `takes` / `take_upload_reservations` / `cuts.adopted_take_id` | 必要な cut に採用テイクが揃う |
+| **REN** (合成・配信) | 採用テイクとシナリオから ffmpeg で動画を合成し、完成物を再生・DL できる形で配信する | `render_jobs` / `video_manuals.status` (published) / 成果物 storage | published + 完成 mp4 の再生・DL 成立 |
+
+> 実装側の不変条件は `docs/architecture.md` §シナリオ整合の共有不変条件 / §撮影 PWA、
+> 思想の正本は `doc/03_AI解析とシナリオ生成.md` / `doc/06_撮影シナリオの設計思想.md`。
+
+### 境界が曖昧になる機構の割当規則 (判断を run ごとにやり直さない)
+
+| 機構 | 割当 | 根拠 |
+|---|---|---|
+| マニュアル複製 (`projects.manuals.duplicate`) | **SCEN-05** | cuts を作る = シナリオの**第 2 の起点**。SOP 段 (解析入力の用意) を経由しない |
+| テイク採用 / テイク削除 (`capture.takes.adopt` / `destroy`) | **CAP-04** | 書く列は `cuts.adopted_take_id` だが、決めているのは「どのテイクを使うか」= 撮影の成果。シナリオ本文の意味は変えない |
+| 撮影画面の字幕オーバーレイ | **CAP-02** | 撮影ガイド (焼き込みではない)。字幕**文言**の編集は SCEN-03 |
+| チケット消費 (analyze 1 枚 / render N 枚) | 操作は **SCEN-01** / **REN-02**、会計不変条件は **BILL-06** | 「消費する操作の UX」と「残高の正しさ (reserve→commit/release)」は別の失敗モード。preview は非消費 = REN-01 |
+| 容量 Quota (`max_storage_bytes`) | 予約時の拒否は **CAP-02**、残量の可視化は **QUO-01** | 予約判定はアップロードに内在、使用率表示は横断機能 (Dashboard) |
+| 署名 URL | 発行した段に属す: upload=**CAP-02** / テイク再生=**CAP-03** / 完成動画 DL・再生=**REN-04** | 署名の失効・差し替え耐性は発行元の責務 |
+| 編集画面 → 撮影画面の文脈リンク | **CAP-01** | 「撮影に入れるか」は撮影段の入口 (到達性) の問題 |
+| PWA のセッション基盤 (`capture.csrf-cookie` / 復帰時の再表示) | **CAP-06** | 同一オリジン・セッション認証の PWA 固有面。個々の撮影操作とは別の失敗モード |
+
+**段間の handoff は独立した観察点**である。各段の終端条件 (上表) が
+**UI から読み取れず「次に何をすべきか」が分からない**なら、それは前段側の capability の finding として扱う
+(例: ready なのに撮影導線が見えない → CAP-01 ではなく SCEN-03/SOP-05 側の提示不足)。
+
+## 運用ルール
+
+- 新機構が出たら (`scripts/bug-hunt-inventory-check.sh` のドリフト検知 / `php artisan route:list` の差分)、
+  **既存 id へ紐付け / 新 id 追加 / dead-code 判定**のいずれかに落とす (LLM が案を出し、人が境界を確認する)。
+- tag できない finding は `capability_tag=unknown`、機構はあるが未割当なら `unmapped`。**隠さない**
+  (この 2 語は本表に**載せない**。finding 側の値としてのみ使い、triage で実 id へ解決する)。
+- `unknown` が 2 run 連続で 20% を超えたら、探索ではなく**本表の整備**を優先する (add-back トリガ)。
+- 1 finding に複数 capability が絡む場合は**主たる失敗が起きた段**の id を選ぶ (複数 tag は付けない)。
+- フロント専用の機能 (クライアント状態・UI フィルタ) は、探索で実際に触れたものだけ追加する。
+
+## capability_id 索引
+
+### パイプライン (中核。S3 / S7 が主に消化する)
+
+| id | 機能 (actor→outcome) | 代表機構 (route name) |
+|---|---|---|
+| SOP-01 | 編集者→動画マニュアル一覧の把握 (カテゴリ/状態/検索の絞り込み・並べ替え・自作フィルタ) | `projects.show` |
+| SOP-02 | 編集者→マニュアル新規作成 (タイトル・カテゴリ + 手順書アップロード) | `projects.manuals.create` / `projects.manuals.store` |
+| SOP-03 | 編集者→手順書の追加アップロード (抽出不可・短文の理由提示) | `projects.manuals.source-documents.store` |
+| SOP-04 | 編集者→マニュアルのメタ更新・削除 | `projects.manuals.update` / `projects.manuals.destroy` |
+| SOP-05 | 編集者→マニュアル詳細で現在地 (status) と次操作を把握 | `projects.manuals.show` |
+| SCEN-01 | 編集者→AI 解析のトリガー (チケット予約 → status=analyzing) | `projects.manuals.analyze` |
+| SCEN-02 | 編集者→解析ジョブの進捗追跡と失敗時の draft 復帰・理由提示 | `projects.manuals.jobs.show` |
+| SCEN-03 | 編集者→シナリオ (Cut ツリー・本文・字幕) の編集と保存 (楽観ロック / 409 差分再取得) | `projects.manuals.edit` / `projects.manuals.scenario.update` |
+| SCEN-04 | 編集者→編集中の Undo / Redo (保存前のローカル状態のみ) | (クライアント状態。route なし) |
+| SCEN-05 | 編集者→既存シナリオを雛形に複製 (別名保存。takes は空・draft) | `projects.manuals.duplicate` |
+| CAP-01 | 撮影者→撮影対象の一覧・入室 (進捗バッジ・自作フィルタ・編集画面からの文脈リンク) | `capture.home` / `capture.manuals.index` / `capture.manuals.show` |
+| CAP-02 | 撮影者→テイクの撮影とアップロード (容量 Quota 予約・カメラ不可時のファイル選択フォールバック) | `capture.takes.upload-url` / `capture.takes.store` |
+| CAP-03 | 撮影者→テイクの確認と整理 (インライン再生・字幕トグル・並べ替え・コメント) | `capture.takes.playback` / `capture.takes.update` |
+| CAP-04 | 撮影者→テイクの採用・削除 (cut ごとに 1 本を確定する) | `capture.takes.adopt` / `capture.takes.destroy` |
+| CAP-05 | 撮影者→採用済みテイクの端末ダウンロードと ACK | `capture.takes.downloaded` |
+| CAP-06 | 撮影者→PWA セッション基盤 (同一オリジン・セッション認証、離脱/復帰時の表示と再認証) | `capture.csrf-cookie` |
+| REN-01 | 編集者→プレビュー生成 (チケット非消費) で仕上がりを確認 | `projects.manuals.preview` |
+| REN-02 | 編集者→本レンダ実行 (チケット N 消費 → rendering → published) | `projects.manuals.render` |
+| REN-03 | 編集者→レンダジョブの進捗追跡と失敗理由の帰属提示 | `projects.manuals.render-jobs.show` |
+| REN-04 | 編集者/撮影者→完成動画の再生・ダウンロード (署名 URL) | `projects.manuals.render-jobs.playback` / `projects.manuals.download` |
+
+### 支援 (パイプライン外。S1/S2/S4/S5/S6 が主に消化する)
+
+| id | 機能 (actor→outcome) | 代表機構 (route name) |
+|---|---|---|
+| PUB-01 | guest→公開面の閲覧と CTA 到達 (トップ / 料金 / 法務) | `home` / `pricing` / `legal.*` |
+| PUB-02 | guest→問い合わせ送信 | `contact` / `contact.store` / `contact.thanks` |
+| AUTH-01 | guest→新規登録 | `register` / `register.store` |
+| AUTH-02 | guest→メール認証で verified 化 | `verification.notice` / `verification.send` / `verification.verify` |
+| AUTH-03 | user→ログイン / ログアウト (2FA チャレンジ経由を含む) | `login.store` / `two-factor.login.store` / `logout` |
+| AUTH-04 | guest→パスワードリセット完走 | `password.request` / `password.email` / `password.update` |
+| AUTH-05 | guest→ソーシャルログイン / 連携 | `social.redirect` / `social.callback` |
+| ORG-01 | user→組織作成 | `organizations.create` / `organizations.store` |
+| ORG-02 | owner/admin→組織情報の閲覧・更新 | `organizations.settings` / `organizations.update` |
+| ORG-03 | user→組織切替 (切替後のスコープ整合) | `organizations.switch` |
+| ORG-04 | owner→オーナー移譲 | `organizations.transfer-ownership` |
+| ORG-05 | owner→組織の 2FA 必須トグル | `organizations.two-factor-requirement.update` |
+| MEM-01 | owner/admin→メール招待の送信 | `organizations.invitations.store` |
+| MEM-02 | owner/admin→未受諾招待の取消 | `organizations.invitations.revoke` |
+| MEM-03 | guest/user→招待受諾の完走 (未ログイン時の登録合流・所属組織の確定) | `invitations.accept` / `invitations.accept.store` |
+| MEM-04 | owner/admin→ロール変更 (編集者 / 撮影者) | `organizations.members.update` |
+| MEM-05 | owner/admin→メンバー除名 | `organizations.members.destroy` |
+| MEM-06 | owner/admin→メンバーの 2FA 解除 | `organizations.members.two-factor.reset` |
+| MEM-07 | owner/admin→メンバー一覧の閲覧・管理導線 | `manage.users.index` |
+| PROJ-01 | owner/admin→プロジェクト CRUD | `projects.index` / `projects.store` / `projects.update` / `projects.destroy` |
+| PROJ-02 | owner/admin→プロジェクトメンバーの追加・除外 | `projects.members.store` / `projects.members.destroy` |
+| PROJ-03 | owner/admin→カテゴリの CRUD と並べ替え (一覧の並びに反映) | `projects.categories.*` |
+| PROJ-04 | owner/admin→サンプルリソース Item の CRUD (テンプレート見本。顧客価値ではない) | `projects.items.*` |
+| BILL-01 | guest/user→料金表の閲覧 (表示と実課金の一致) | `pricing` |
+| BILL-02 | owner→プラン申込 checkout (二重送信で二重課金しない) | `billing.checkout` |
+| BILL-03 | owner→カスタマーポータルへの遷移 | `billing.portal` |
+| BILL-04 | owner→チケットのスポット購入 (枚数入力 → 合計再計算) | `billing.tickets.show` / `billing.tickets.checkout` |
+| BILL-05 | owner→プラン・チケット残高の閲覧 | `billing.index` |
+| BILL-06 | (会計不変条件) チケット reserve→commit/release の 2 フェーズ整合 ※消費操作自体は SCEN-01 / REN-02 | (機構横断) |
+| QUO-01 | user→容量 Quota の使用率把握と超過時の明示 ※予約時の拒否は CAP-02 | `dashboard` |
+| SEC-01 | user→プロフィール更新 (メール変更は step-up 要求) | `user-profile-information.update` |
+| SEC-02 | user→パスワード変更 | `user-password.update` |
+| SEC-03 | user→2FA の有効化・確認 | `two-factor.enable` / `two-factor.confirm` |
+| SEC-04 | user→2FA の無効化 (必須組織では拒否) | `two-factor.disable` |
+| SEC-05 | user→リカバリコード再生成 | `two-factor.regenerate-recovery-codes` |
+| SEC-06 | user→機微操作前の再認証 (confirm-password / recent-auth) | `password.confirm.store` / `recent-auth.confirm` / `recent-auth.password` |
+| SEC-07 | user→アカウント削除 | `settings.account.destroy` |
+| NOTI-01 | user→通知の閲覧・既読化・開封遷移 | `notifications.index` / `notifications.read` / `notifications.read-all` / `notifications.open` |
+| AK-01 | owner/admin→API キーの発行・失効 | `organizations.api-keys.store` / `organizations.api-keys.revoke` |
+| AK-02 | owner/admin→OAuth セッションの失効 | `organizations.api-keys.sessions.index` / `organizations.api-keys.sessions.revoke` |
+| AK-03 | owner/admin→CLI / MCP セットアップ手順の取得 | `organizations.onboarding.cli` / `organizations.onboarding.mcp` |
+| AK-04 | automation→REST API v1 / MCP 経由の操作 ※browser story 未整備 (現状は finding 側で `unmapped` になり得る) | `routes/api.php` / `routes/ai.php` |
+| PLAT-01 | platform→管理パネル (Filament)。顧客 UX の対象外 | (admin panel) |
+| PLAT-02 | dev→debug ログイン (非 production 専用。story の前提構築に使う) | `debug.login` / `debug.login-as` |
+
+> **本表に載せないもの**: `unknown` / `unmapped` (finding 側の値)、テスト専用機構
+> (fake storage 配信等)、SEO / robots 等の非対話面 (`screens.md` の OUT_OF_SCOPE で除外済み)。
diff --git a/AGENTS.md b/AGENTS.md
index d58bc59..4eef6f5 100644
--- a/AGENTS.md
+++ b/AGENTS.md
@@ -142,6 +142,9 @@ ## worktree 運用ルール
 - **orphan 化した worktree**(teardown を経ず破棄)は `git worktree prune` で整理。
   検証なしの強制削除は
   `git worktree remove --force .claude/worktrees/tasks/<task-id> && git worktree prune`
+- **背景と障害対応**: 分離設計 (vendor / node_modules / テスト DB / 実行時ファイルの 4 軸) の
+  意図は `docs/worktree-isolation-strategy.md`、`enableGlobalVirtualStore` の前提・落とし穴・
+  復旧手順は `docs/pnpm-global-virtual-store-runbook.md`(GVS 無効化・暗黙 peer・ENOMEM 等)
 
 ## bug-hunt (LLM 探索的バグハント、オプトイン)
 
@@ -160,6 +163,9 @@ ## bug-hunt (LLM 探索的バグハント、オプトイン)
   main 直叩きを早期に止める。配線は `.claude/settings.bughunt-hook.example.json` を `.claude/settings.json` にマージ)。
 - **スケルトン**: `screens.md` / `operations.md` / `stories/` はテンプレートでは空スケルトン。初回に
   `php artisan route:list` から生成する (SKILL.md Phase 1)。ドリフト検知は `scripts/bug-hunt-inventory-check.sh`。
+- **capability 語彙**: finding の `capability_tag` の正本は
+  `.claude/skills/app-bug-hunt/capability-catalog.md`(SOP→シナリオ→撮影→レンダの責務境界を
+  先に定義し、その上に capability_id を割り当てる。未割当は `unmapped`・tag 不能は `unknown`)。
 - 検証: `scripts/bug-hunt-shard.sh self-test` (実資源に触れず guard/資源導出/env 隔離/asset 鮮度を検証)。
   Python ツール (`coverage/` `ledger/`) は `python3 -m unittest` (stdlib のみ)。
 
diff --git a/devnotes/20260803-0053-aigenba-alignment/aigenba-handoff.md b/devnotes/20260803-0053-aigenba-alignment/aigenba-handoff.md
new file mode 100644
index 0000000..db7669c
--- /dev/null
+++ b/devnotes/20260803-0053-aigenba-alignment/aigenba-handoff.md
@@ -0,0 +1,98 @@
+# aigenba への引き継ぎ: AI-CUE 側が優位な差分 (決済ドメイン以外)
+
+> **目的**: 「aigenba に合わせる」は**双方向**である。AI-CUE が aigenba へ整列する過程
+> (`devnotes/20260803-0053-aigenba-alignment/`、監査台帳 `../20260802-1548-aigenba-alignment-audit/audit.md`)
+> で見つかった、**AI-CUE 側が優れている / 安全な差分**を aigenba へ返す。
+>
+> **分類**: 既存の `devnotes/20260717-0035-aigenba-billing-parity/aigenba-divergence-ledger.md` の
+> カテゴリ運用に従い、本文書は全件 **カテゴリ B (AI-CUE 側が優れている / 安全 = 返す)** である。
+> A (対象が存在しない) / C (既存契約への適合) / D (ドメイン要件の差) / E (一時的措置) は返さないため
+> 本文書には載せない。
+>
+> **確度の書き方**: aigenba 側の実コード (`/tmp/aigenba` の working copy、2026-08-02 時点) を読んで
+> 確認した事実と、未検証の推測を分けて書く。**実行による再現はしていない**。
+
+## 採否結果 (受け手 = aigenba 側が記入する)
+
+| # | 差分 | 種別 | 提案の要旨 | 採否 (adopt / reject / defer) | 判断日 | 備考 |
+|---|---|---|---|---|---|---|
+| F-1 | bug-hunt シェルの 3 段 DB guard + xtrace 秘匿 | 安全性 | 破壊操作の直前に DB 名 regex / role を hard-deny。`set -x` 下で API key を trace に出さない | | | |
+| F-2 | `correlate.py` のヘッダ列 index 動的決定 + backtick 剥がし | 正確性 | 列構成の違う節を**誤 join せず skip** する。backtick 付き route 名が分母から静かに落ちるのを防ぐ | | | |
+| F-3 | ~~`audit-gate` のテスト~~ | — | **取り下げ (前提誤り)**。下記 F-3 節参照。**aigenba 側の対応は不要** | — | 2026-08-02 | 提案側で撤回 |
+| F-4 | bfcache の秘匿・再検証 (Safari を含む) | 安全性 | aigenba は Safari bfcache を「別施策」としているが、**installable PWA を持つ以上同じ穴がある** | | | |
+| F-5 | `validate_findings.py` の `import io` 位置 / `open()` の context manager 化 | 可読性・資源解放 | AI-CUE は**整列優先で意図的に見送った**。aigenba が直したら AI-CUE も追随する | | | |
+
+> 記入方法: 採否欄に `adopt` / `reject` / `defer` と判断日を書き、理由は備考か aigenba 側 devnotes へ。
+> **reject でよい**。目的は往復管理であって採用の強制ではない (前例: 同ディレクトリの
+> `../20260717-0035-aigenba-billing-parity/aigenba-handoff.md` は先方検証で「指摘不成立」として CLOSED)。
+
+---
+
+## F-1. bug-hunt シェルの 3 段 DB guard と xtrace 秘匿
+
+| | |
+|---|---|
+| **aigenba** | `scripts/bug-hunt-shard.sh` は `require_orchestrator`(親専用 gate、default-deny) を持つが、**DB 名の hard-deny guard が無い**。`shard_db()` が返した名前をそのまま `createdb "${db}"` / `dropdb --if-exists "$(shard_db "${shard}")"` / `migrate:fresh --seed --force` に渡す。API キーは環境変数から読み `real_llm_env+=("ANTHROPIC_API_KEY=${ANTHROPIC_API_KEY}")` の形で配列へ入れる |
+| **AI-CUE** | 破壊的操作の**同一プロセス・直前**に用途別 3 段 guard を通す: `guard_shard_db_name`(DB 名が `^<prefix>(_[1-8])?$` に一致しなければ abort) / `guard_bughunt_runtime`(+ `APP_ENV=bughunt.local` + DB user が `bughunt`) / `guard_admin_provision`(+ admin role の明示必須)。秘密取扱区間は `secret_xtrace_off` / `secret_xtrace_restore` で囲む |
+| **理由 (安全性)** | (a) **DB 名 guard**: 変数の取り違え・env leak・引数ミスが起きても、dev DB 名は regex に一致しないため `createdb` / `dropdb` / `migrate:fresh` に到達しない。「呼び出し側が正しい名前を渡す」前提を**構造で置き換える**。(b) **xtrace 秘匿**: `bash -x scripts/bug-hunt-shard.sh ...` でデバッグすると、**キーを含む代入行がそのまま trace に出て CI ログ・端末履歴に残る**。`set -x` は障害調査時にこそ使われるため、露出は「めったに起きない」ではなく「困っている時に起きる」 |
+| **aigenba への提案** | 1) `createdb` / `dropdb` / `migrate:fresh` を呼ぶ関数の先頭で DB 名 regex を hard-deny する (dev DB 名の大小・前後空白バリアントも regex 不一致で落ちる)。2) キーを扱う区間を `set +x` / 復元で囲む (AI-CUE の 2 行実装がそのまま流用できる)。3) guard 自体の回帰テストを `self-test` に足す (AI-CUE は `[c] [d] [e]` で dev DB 名 abort / user≠bughunt abort / admin 未設定 abort を検証している) |
+| **確度** | **高** (aigenba 側の実コードで guard 関数の不在と `createdb` / `dropdb` の直呼びを確認済み)。ただし aigenba の運用で実際に事故が起きたかは未確認。**影響は「起きたときの被害」側**にある |
+| **検出元** | 全面監査 2026-08-02 (`../20260802-1548-aigenba-alignment-audit/audit.md`)。AI-CUE 側は実装済み (`scripts/bug-hunt-shard.sh` + `scripts/bug-hunt-shard.sh self-test`) |
+
+## F-2. `coverage/correlate.py` のヘッダ列 index 動的決定と backtick 剥がし
+
+| | |
+|---|---|
+| **aigenba** | `load_operations()` が `cols[0], cols[1], cols[2], cols[3]` の**固定 index** で `route name / 操作 / story / 区分` を読む。ヘッダ行は「col0 が `route name`」または「col3 が `区分`」で判定して skip する。`_parse_route_cell()` は **backtick を剥がさない** |
+| **AI-CUE** | `_header_indices()` が**ヘッダ行から name / story / 区分 の列 index を動的に決め**、以降の行に適用する (節ごとに更新)。ヘッダを検出できない表は **skip する** (誤 join しない)。`_parse_route_cell()` は backtick を除去する |
+| **理由 (正確性)** | aigenba の `operations.md` には**既に**列構成の違う節がある: `| route name (api.v1) | CLI コマンド | story | 区分 |` (S8) と `| 操作 | 内容 | story | 区分 |` (S10, Filament)。後者は **col0 が route 名ではない**ため、固定 index では `admin.login` / `admin.users.crud` 等の**操作ラベルが route 名として分母に混入**する (route:list と join できない幽霊エントリになる)。また 4 列でない行 (5 列 / 8 列) が実在し、`len(cols) < 4` を通過した行は**ずれた列**で解釈される。backtick については、name セルが `` `api.v1.x` `` 形式だと route 名らしさ判定 (`[A-Za-z0-9_.\-]+` の完全一致) を通らず、**分母から静かに脱落**する |
+| **aigenba への提案** | `load_operations()` をヘッダ駆動にする (name/story/区分 のヘッダ語彙は既存表記をそのまま許容リストにする)。**ヘッダを認識できない表は分母に入れない**方針にすると、S10 のような「操作で数える節」を混入させずに済む (数えたいなら専用の列見出しを付ける)。あわせて `_parse_route_cell()` の先頭で backtick を除去する。AI-CUE 側の実装と単体テスト (`coverage/test_correlate.py`) がそのまま参考になる |
+| **確度** | **中〜高**。列構成の違う節・非 4 列行・backtick 行が aigenba の `operations.md` に実在することは確認済み (grep)。ただし**実際に分母がいくつずれているかは未計測**なので、まず現行 `correlate.py` の出力で `unmapped` / 幽霊 route の有無を見てほしい |
+| **検出元** | 全面監査 2026-08-02。AI-CUE 側は実装済み |
+
+## F-3. `scripts/audit-gate.test.ts` — **取り下げ (提案側の前提誤り)**
+
+詳細設計 (`detailed-design.md` 施策 14) は「supply-chain gate 自体は両者にあるが、**gate のテストは AI-CUE のみ**」を
+根拠に F-3 を挙げていた。**この前提は誤りである**。2026-08-02 に確認したところ、aigenba にも
+`tests/js/scripts/audit-gate.test.ts` (327 行) が実在し、テスト項目は AI-CUE 版とほぼ同一だった
+(配置が `tests/js/scripts/` か `scripts/` 併置かの違いのみ)。
+
+実際に残る差分は **AI-CUE が PyPI (pip-audit) にも対応している**点だけで、
+aigenba は `EcosystemEnum` から `pypi` を意図的に除外し「Python ecosystem は aigenba に存在しない」と
+コメントで明記している。**aigenba に Python 依存が入るまでは移植の価値が無い**ため、本項目は取り下げる。
+
+> 記録の意図: 「調べたら前提が誤りだった」を消さずに残す (`aigenba-divergence-ledger.md` の
+> 「乖離ではないと判定したもの」と同じ扱い)。**aigenba 側の対応は不要**。
+
+## F-4. bfcache の秘匿・再検証 (Safari / PWA)
+
+| | |
+|---|---|
+| **aigenba** | `NoStoreCacheHeadersForAuthenticatedPages` で認証済み応答に `no-store, private` を baseline 付与済み。ただし `docs/architecture.md` は **「Chrome・Firefox 対象、Safari は既知の残余リスクで別施策」**と明記しており、**クライアント側の対策は無い**。一方で `public/site.webmanifest` は `"display": "standalone"` を宣言し `app.blade.php` から link されている = **installable PWA である** |
+| **AI-CUE** | 同じ baseline middleware (施策 4) に加えて、**クライアント側の bfcache 秘匿・再検証** (施策 6) を設計している: `pagehide` で `documentElement` に秘匿属性を付け**その DOM 状態ごと bfcache snapshot に入れる** → `pageshow` で秘匿属性があれば**秘匿したまま**軽量プローブでセッション有効性を確認 → 有効なら秘匿解除のみ (フォーム状態・履歴を壊さない) / 無効なら login へ hard navigation / プローブ失敗時は秘匿維持 + 再試行ボタン |
+| **理由 (安全性)** | **Safari は `no-store` でも bfcache に格納しうる**。したがってサーバヘッダだけでは「ログアウト後に戻るで認証済み画面 (メンバー一覧等の PII) が復元される」を防げない。**PWA として standalone 起動される環境では iOS Safari (WebKit) が主要な実行系**になるため、「Safari は別施策」の残余リスクは**アプリの主要導線にそのまま残る**。また「復元後に非同期検証して遷移する」実装だと**検証完了までの間 PII が実際に表示される**ため、「秘匿してから検証」でなければ穴が塞がらない (AI-CUE の設計レビューでこの点が Critical 指摘になった) |
+| **aigenba への提案** | (1) まず **実機確認**: iOS Safari (できれば PWA としてホーム画面から起動した状態) で「認証済み画面 → ログアウト → 戻る」を実行し、PII が再表示されるかを見る。(2) 再現するなら「pagehide で同期秘匿 → pageshow で秘匿のまま検証 → 有効なら解除のみ」の形を検討してほしい。**hard reload を常用しない**のが要点で、未送信フォームや復元済み状態を巻き添えにしないため。(3) 検証用の軽量プローブは既存の step-up 系 endpoint を流用せず**セッション有効性専用**を用意する (意味が違うものを兼用すると鮮度と有効性が混ざる) |
+| **確度** | **中**。aigenba の webmanifest が standalone であること・architecture.md が Safari を対象外としていることは確認済みだが、**iOS Safari での実再現は未実施**。AI-CUE 側も本 handoff 執筆時点では**別トラック (T082) で実装中**であり、本ブランチ (`todo/T084`) にはコードが無い。**実装後に具体的な差分を添えて再度渡すのが望ましい** |
+| **検出元** | 監査 2026-08-02 の P3-b。設計は `detailed-design.md` 施策 6 / 施策 8 (WebKit レーンの Browser E2E) |
+
+## F-5. `ledger/validate_findings.py` の `import io` 位置と `open()` の context manager 化
+
+| | |
+|---|---|
+| **aigenba** | `analyze()` の**関数内**に `import io as _io` を置く。`load_jsonl()` は `fh = open(path, encoding="utf-8")` を裸で開き `try/finally` で閉じる |
+| **AI-CUE** | **同じ形へ揃える予定** (施策 9 で aigenba の 2-pass 対応 `text=` 引数ごと verbatim 移植する)。**AI-CUE 側では改善しない** |
+| **理由** | `import` はモジュール先頭に置くのが標準 (PEP 8)、`open()` は context manager (`with`) の方が例外経路でも確実に閉じる。ただし**ここで AI-CUE だけ直すと新しい乖離を作る**ため、整列を優先して意図的に見送った (AI-CUE の詳細設計レビュー R1 Suggestion への回答として記録済み) |
+| **aigenba への提案** | `import io` をモジュール先頭へ移し、`load_jsonl()` の分岐を `with` で書けるよう整える (`text` 指定時は `io.StringIO(text)` を `with` に通せば分岐が 1 本化できる)。**優先度は低い** (現行実装にバグは無い) |
+| **確度** | **高** (差分は明確)。ただし**挙動の欠陥ではなくスタイル・資源解放の堅牢性**の話であり、reject でも実害は無い |
+| **検出元** | AI-CUE 詳細設計レビュー R1 Suggestion。**aigenba が直したら AI-CUE も追随する** (先回りで独自修正しない) |
+
+---
+
+## 往復の運用
+
+1. aigenba 側は上の採否表に `adopt` / `reject` / `defer` と判断日を記入する (理由は備考欄か aigenba 側 devnotes)。
+2. **adopt された項目**は、aigenba 側で実装され次第 AI-CUE が追随する (F-5 は特に「aigenba が先、AI-CUE が後」)。
+3. **reject された項目**は理由ごと本文書に残す。AI-CUE 側は現行実装を維持し、
+   `../20260717-0035-aigenba-billing-parity/aigenba-divergence-ledger.md` に
+   「返したが reject された乖離」として記録する。
+4. **defer** は再検討の契機 (条件・時期) を備考に書く。
diff --git a/docs/pnpm-global-virtual-store-runbook.md b/docs/pnpm-global-virtual-store-runbook.md
new file mode 100644
index 0000000..29898e7
--- /dev/null
+++ b/docs/pnpm-global-virtual-store-runbook.md
@@ -0,0 +1,138 @@
+# pnpm Global Virtual Store (GVS) runbook
+
+`AGENTS.md` §worktree 運用ルールは「node_modules は `pnpm-workspace.yaml#enableGlobalVirtualStore` で
+実体を共有 store に置き、worktree 内 `pnpm install` / `pnpm add` の影響を自 worktree に局所化する」に
+依存している。**その依存が何を守っているのか / 壊れたときにどう直すのか**を書く。
+
+> 分離戦略の全体像 (vendor 側を含む) は [`docs/worktree-isolation-strategy.md`](worktree-isolation-strategy.md)。
+> 本書は node_modules (pnpm) の深堀りと障害対応に絞る。
+
+## なぜ GVS なのか (捨てた選択肢)
+
+worktree ごとに node_modules を持たせる方式は 3 つあり、AI-CUE は 3 番目を採っている。
+
+| 方式 | 採否 | 理由 |
+|---|---|---|
+| main の `node_modules` を symlink 共有 | **不採用** | worktree 内で `pnpm install` / `pnpm add` を打つと **main の実体を直接書き換える**。LLM が反射的に install を打つ運用では事故が確定する |
+| worktree ごとに完全独立な `node_modules` | **不採用** | 同一 version の package が main と worktree で別 path に実体化し、`tsc` が**別型として扱う** (`packages/*` と root を跨ぐ型で `TS2345`)。install 時間・ディスクも線形に増える |
+| **worktree-local install + GVS で実体共有** | **採用** | install/add の影響は自 worktree の `node_modules/` (symlink 群) に閉じ、実体は共有 store の `links/` に収束するため **realpath が一致 = 型 identity も一致**する |
+
+## AI-CUE の現行設定 (正本は `pnpm-workspace.yaml`)
+
+pnpm は **11.9.0** (`package.json#packageManager` で固定。GVS は v10.12.1+ の機能)。
+
+| 設定 | 値 | 役割 |
+|---|---|---|
+| `packages` | `'.'` / `'packages/*'` | root 自身も workspace member (glob 派。明示列挙はしない) |
+| `enableGlobalVirtualStore` | `true` | 実体を `<store-path>/links/` に置き main / 全 worktree で共有する |
+| `nodeLinker` | `isolated` | GVS の前提。default 変更や `.npmrc` 復活で hoisted に倒れるのを防ぐ明示固定 |
+| `linkWorkspacePackages` | `true` | `packages/*` と同名 package が registry にあった場合の silent misresolve を防ぐ safety net |
+| `packageExtensions` | jest-dom → `vitest` / vite-plugin-full-reload → `vite` | **暗黙 peer** の補完 (下記「落とし穴 1・2」) |
+| `allowBuilds` | `esbuild` | pnpm 11 の build-script gating。未許可だと `ERR_PNPM_IGNORED_BUILDS` |
+| `overrides` | `{}` | 脆弱性対応の patched 版強制枠 (運用規約は同ファイルのコメント。`pnpm run audit:gate` と対で使う) |
+
+**`package.json` に `workspaces` フィールドを置かない** (npm syntax。pnpm は読まず WARN を出し続ける)。
+workspace の正本は `pnpm-workspace.yaml` 一本。
+
+## install の作法 (CLI で config を強制する)
+
+`scripts/setup-worktree.sh` の `[5/7]` は必ずこの形で実行する。手動 install も同じ形にする
+(AGENTS.md §worktree の記述と同一)。
+
+```bash
+pnpm install --frozen-lockfile \
+    --config.ci=false \
+    --config.enableGlobalVirtualStore=true \
+    --config.nodeLinker=isolated \
+    --config.confirmModulesPurge=false
+```
+
+| flag | なぜ必要か |
+|---|---|
+| `--config.ci=false` | **`CI=true` 等が立っていると pnpm は GVS を自動で無効化する**。yaml で `true` にしていても効かない |
+| `--config.enableGlobalVirtualStore=true` | yaml と冗長だが、env / `.npmrc` で殺された場合の保険 |
+| `--config.nodeLinker=isolated` | 同上 (hoisted に倒れると GVS の前提が崩れる) |
+| `--config.confirmModulesPurge=false` | TTY 無しでも modules-dir purge の確認を通す (旧来 `CI=true` でやっていたことの正規置換) |
+
+pnpm の設定 precedence は **CLI > env > `PNPM_CONFIG_*` > yaml**。CLI 明示が最も頑健。
+
+## GVS が効いているかの確認
+
+`setup-worktree.sh` の post-setup health check #4 が、代表 direct dependency (**`svelte`**) の realpath が
+共有 store の `links/` 配下に解決されることを assert する (`.modules.yaml` の存在だけでは GVS 無効な
+layout も成立するため、実効を直接見る)。手で確認するなら:
+
+```bash
+pnpm store path --config.enableGlobalVirtualStore=true --config.nodeLinker=isolated
+readlink -f node_modules/svelte            # worktree 内
+readlink -f /workspace/node_modules/svelte # main
+```
+
+両者が同一の `<store-path>/links/...` を指せば OK (2026-08-02 実測: main / worktree ともに
+`/workspace/.pnpm-store/v11/links/@/svelte/<ver>/<hash>/node_modules/svelte` に収束)。
+`node_modules/.pnpm/...` を指していたら **GVS が無効化されている**。
+
+代表 dep を変えるときは (1) root の direct external dependency であること (安定して
+`node_modules/` 直下に symlink される) (2) ESM の暗黙 peer 問題を持たない package を選ぶこと、
+の 2 点を満たしてから `scripts/setup-worktree.sh` の health check を更新し、本書も直す。
+
+## 落とし穴と対処
+
+### 1. jest-dom の暗黙 peer — `Invalid Chai property: toBeInTheDocument`
+
+`@testing-library/jest-dom` の `vitest.js` は `vitest` を peerDependencies に宣言せずに `import 'vitest'` する。
+GVS 有効時、jest-dom の実体は共有 store 内にあり、**ESM では `NODE_PATH` が効かない**ため
+host 側の vitest を解決できず、`tests/js/setup.ts` の matcher 拡張が丸ごと無効になる。
+
+**対処**: `pnpm-workspace.yaml#packageExtensions` で peer を補完する (設定済み)。
+同種の「peer を宣言しない ESM 依存」を持つ package が増えたら同じ形で追加する。
+
+### 2. `vite build` が `ERR_MODULE_NOT_FOUND` (vite-plugin-full-reload)
+
+`vite-plugin-full-reload` (laravel-vite-plugin の依存) も `vite` を peer 宣言せずに import する。
+hoisted linker では偶然解決できていたが、isolated + GVS では解決できない。
+**対処**: 同じく `packageExtensions` で `vite` peer を補完する (設定済み)。
+
+### 3. `ERR_PNPM_IGNORED_BUILDS` (esbuild)
+
+pnpm 11 は postinstall script をデフォルトで実行しない。Vite が引き込む esbuild は
+postinstall で platform binary を配置するため、`allowBuilds: { esbuild: true }` が無いと
+install / build が落ちる (設定済み)。新たに native binary を配る依存が入ったら同節に追加する。
+
+### 4. install が `ENOMEM` / "Cannot allocate memory" で落ちる
+
+OrbStack / virtiofs の共有 FS で大量小ファイルを展開すると一過性で落ちることがある。
+`setup-worktree.sh` は composer / pnpm とも**最大 3 回リトライ**する (`--config.*` 強制と
+`--frozen-lockfile` はリトライ各回で維持)。手動時は間に `sync` を挟んで再実行する。
+
+### 5. `mise: Config not trusted`
+
+Node / pnpm を mise で管理している環境では、新規 worktree の `mise.toml` が untrusted のため
+pnpm shim が起動できない。`setup-worktree.sh` が worktree 作成直後に `mise trust <worktree>` を
+実行する (mise 非導入環境では skip)。手動なら同コマンドを打つ。
+
+### 6. `health-check FAIL: GVS 無効の疑い`
+
+setup が上記メッセージで落ちたときの手順:
+
+1. `env | grep -i '^CI='` — CI 変数が立っていないか (立っていても CLI 強制で防げるはずだが、
+   `pnpm install` を素で打っていた場合は無効化される)
+2. `pnpm config get enableGlobalVirtualStore` / `pnpm config get nodeLinker` を確認
+3. `rm -rf node_modules` して上記「install の作法」の形で再 install
+4. `readlink -f node_modules/svelte` が `links/` 配下に戻ったか確認
+
+### 7. lockfile を壊さない
+
+`pnpm add/remove/update` は task branch 上でのみ実行し、**変更した `package.json` /
+`pnpm-lock.yaml` を必ずコミットする** (未コミットのまま teardown すると失われる)。
+GVS 導入に伴って lockfile に期待される差分は importers 表記のみで、
+**transitive dep の major 上げ・新規追加・消滅が出たら中止して原因を調べる**。
+
+## 参考
+
+- 内部: `AGENTS.md` §worktree 運用ルール / `scripts/setup-worktree.sh` (`[5/7]` と health check) /
+  [`docs/worktree-isolation-strategy.md`](worktree-isolation-strategy.md)
+- pnpm 公式: [Global Virtual Store](https://pnpm.io/global-virtual-store) (ESM の `NODE_PATH` caveat も同ページ) /
+  [Git Worktrees for Multi-Agent Development](https://pnpm.io/next/git-worktrees) /
+  [Settings](https://pnpm.io/settings)
+- pnpm Issue [#11221](https://github.com/pnpm/pnpm/issues/11221): global virtual store が peer を解決できない
diff --git a/docs/worktree-isolation-strategy.md b/docs/worktree-isolation-strategy.md
new file mode 100644
index 0000000..8b3e87a
--- /dev/null
+++ b/docs/worktree-isolation-strategy.md
@@ -0,0 +1,131 @@
+# worktree 分離戦略 (依存・DB・実行時ファイル)
+
+`AGENTS.md` §worktree 運用ルールが「何をどう分離しているのか」「なぜその形なのか」の背景。
+**運用ルールそのものの正本は AGENTS.md、実装の正本は `scripts/setup-worktree.sh` /
+`scripts/teardown-worktree.sh`** で、本書はその設計意図と落とし穴を記録する。
+
+## North Star
+
+> **worktree は「依存・DB・実行時ファイル」を workspace (main) と共有しない。**
+> LLM が worktree 内で `pnpm install` / `composer install` / `composer test` を反射的に打っても、
+> main や他の worktree が壊れない状態を**構造的に**保証する。
+
+分離の意義は disk 節約ではなく **事故耐性**である。「注意して運用する」ではなく
+「共有していないから壊せない」に倒す。
+
+## 分離の 4 軸
+
+| 軸 | 戦略 | 実装 |
+|---|---|---|
+| **vendor (composer)** | worktree-local に独立 install | `setup-worktree.sh [4/7]` の `composer install --no-progress --no-interaction --no-scripts` (最大 3 回リトライ) |
+| **node_modules (pnpm)** | worktree-local install + GVS で実体共有 | `setup-worktree.sh [5/7]` の `pnpm install --frozen-lockfile --config.*` 強制 (同 3 回リトライ)。詳細は [`docs/pnpm-global-virtual-store-runbook.md`](pnpm-global-virtual-store-runbook.md) |
+| **テスト DB (pgsql)** | worktree ごとに別 DB (`<slug>_test_<worktree-hash>`) | `tests/Support/Ci/TestDatabaseEnv::workrootHash()` = worktree root realpath の sha1 先頭 8 桁。`scripts/ci/ensure-test-db.php` が冪等 CREATE |
+| **実行時ファイル** | 親から実コピー (共有しない) | `setup-worktree.sh [2/7]` が `.env` (無ければ `.env.example`) / `storage/oauth-*.key` / `public/build` をコピー |
+
+### なぜ vendor を hardlink 共有しないのか
+
+`cp -al` による hardlink 共有は、Docker named volume (btrfs) と worktree (virtiofs) が
+**別デバイスになる構成で cross-device link エラーになる**。環境依存の最適化のために
+セットアップが壊れる方が高くつくため、素直に worktree-local install に統一している。
+
+### なぜテスト DB を worktree ごとに分けるのか
+
+複数 worktree で `composer test` が同時に走ると、`RefreshDatabase` の `migrate:fresh` と
+paratest の per-worker DB が衝突して**不可解な failure**になる。DB 名を worktree path の hash で
+分けることで、別 worktree のテストは互いに止めない (同一 worktree 内の二重起動だけは
+`scripts/run-test.sh` の flock で直列化する)。
+
+**dev DB 防御は分離とは別レイヤで多重化されている** (AGENTS.md 禁止事項 #3):
+`TestDatabaseEnv` の allowlist (`<slug>_test_` + 8 桁 hash + paratest token のみ) と dev DB denylist を
+`tests/bootstrap.php` の単一点ガード (Laravel boot 前 fail-closed) と `ensure-test-db.php` /
+`drop-test-db.php` 側の再検査という二重防御で使う。**shell や docker-compose から `DB_DATABASE=<dev DB>` が
+leak しても dev DB には到達しない**。
+
+## setup の 7 step (`scripts/setup-worktree.sh <task-id>`)
+
+```
+[0/7] 事前条件チェック + lock 排他 (flock、無ければ mkdir lock)
+[1/7] git worktree add .claude/worktrees/tasks/<task-id> -b todo/<task-id> main
+      (+ mise trust。mise 環境で新規 worktree が untrusted だと pnpm が起動できない)
+[2/7] 実行時ファイルの provision (.env 必須 / storage/oauth-*.key・public/build は存在すれば)
+[3/7] git submodule update --init --recursive (.gitmodules がある場合のみ)
+[4/7] vendor:       composer install --no-scripts (最大 3 回リトライ)
+[5/7] node_modules: pnpm install --frozen-lockfile --config.* 強制 (最大 3 回リトライ)
+[6/7] post-setup health check
+[7/7] pgsql test base DB の冪等 ensure (失敗は warning。test 実行時に再 ensure される)
+```
+
+- **ブランチ名は `todo/<task-id>` 固定** (custom branch 非対応)。teardown 側の前提と一致させるため。
+- **worktree の置き場は `.claude/worktrees/tasks/<task-id>`** (`tasks/` 階層を含む)。
+- 失敗時は EXIT trap (`cleanup_on_exit`) が**作成途中の worktree とブランチを自動削除**するため、
+  中途半端な worktree が残らない。
+- 工程ごとに `[timing] step=... elapsed=...s` を stderr に出す (遅い工程の切り分け用)。
+
+### post-setup health check ( `[6/7]` )
+
+| # | 検査 | 何を守るか |
+|---|---|---|
+| 1 | `.env` と provision したパスの存在 | コピー漏れで後段が謎エラーになるのを防ぐ |
+| 2 | `vendor/autoload.php` 経由で `App\Models\User` が解決できる | composer install の完整性 |
+| 3 | `node_modules` が実ディレクトリ (symlink でない) + `.modules.yaml` あり | pnpm install の完了 |
+| 4 | `readlink -f node_modules/svelte` が `<store-path>/links/` 配下 | **GVS の実効** (無効化されると型 identity 衝突が再発する) |
+| 5 | `php artisan --version` / `vendor/bin/pest --version` / `vendor/bin/phpstan --version` | cold 状態でツールが動くかの fail-fast |
+
+`--no-scripts` を付けているため composer の post-autoload-dump (artisan `package:discover`) は走らない。
+dev DB の cache テーブル不在等で install 自体が落ちるのを避けるためで、Laravel 12 は
+`bootstrap/cache/packages.php` 不在時に runtime auto-discovery するので機能影響はない。
+
+## teardown (`scripts/teardown-worktree.sh <task-id>`)
+
+```
+1) 入力バリデーション + lock 排他 (setup と同一 lock)
+2) dirty チェック — 未コミット / untracked があれば fail (依存 lockfile の取りこぼし防止)
+3) main マージ済みかチェック (警告のみ。処理は止めない)
+4) pgsql テスト DB の best-effort 回収 (scripts/ci/drop-test-db.php)
+5) git worktree remove --force → git worktree prune
+```
+
+**ブランチ `todo/<task-id>` は teardown の責務外** — 削除もマージもしない。
+worktree だけを消し、ブランチの扱い (main へのマージ / `git branch -d`) は呼び出し側が決める。
+「掃除のつもりが未マージの成果を消す」事故を構造的に避けるための線引きである。
+
+vendor / node_modules は worktree-local なので、**worktree を消せば依存も一緒に消えるだけ**。
+main 側を汚す経路が存在しないため、teardown 時の汚染検証 (fingerprint 比較等) は要らない。
+
+orphan 化した worktree (teardown を経ずに削除) は `git worktree prune` で整理する。
+検証なしの強制削除は `git worktree remove --force <path> && git worktree prune`。
+
+## worktree 内のコマンド規則 (2 層)
+
+| 層 | コマンド | 条件 |
+|---|---|---|
+| **許可** | `composer install` / `pnpm install` | lockfile に従う再構成。worktree-local に閉じる |
+| **依存変更タスク時のみ** | `composer require/remove` / `pnpm add/remove/update` | task branch 上で実行し、変更した `composer.json` / `composer.lock` / `package.json` / `pnpm-lock.yaml` を**必ずコミット** (未コミットのまま teardown すると失われる) |
+
+手動 `pnpm install` では `--config.ci=false --config.enableGlobalVirtualStore=true
+--config.nodeLinker=isolated` を明示する (`CI` 等の env で GVS が自動無効化されるのを CLI で防ぐ)。
+
+## bug-hunt との関係
+
+`.claude/skills/app-bug-hunt/` は **worktree から走ることを既定**とし、
+`scripts/bughunt-worktree-hook.sh` の PreToolUse ガードが main 直叩きを早期に止める。
+bughunt 環境の DB (`bug_hunt(_1..8)`) は本書のテスト DB 分離とは**別系統の隔離**で、
+`scripts/bug-hunt-shard.sh` の用途別 wrapper (`env -i` + DB 名 regex + role guard) が担う
+(AGENTS.md §bug-hunt)。
+
+## 既知のギャップ
+
+- **worktree 規約の自動検証テストが無い**。setup / teardown / AGENTS.md の記述がずれても
+  `composer test` では落ちない (参照実装の aigenba は `WorktreeRuleInvariantTest` で
+  regex 固定している)。導入するなら「ブランチ名固定」「teardown がブランチを触らない」
+  「install 系 2 層規則」あたりが pin 対象になる。
+- `.env` は親から**実コピー**するため、親の `.env` を後から変えても worktree には反映されない
+  (worktree ごとに直す)。
+
+## 参考
+
+- `AGENTS.md` §worktree 運用ルール (運用ルールの正本) / §bug-hunt
+- `scripts/setup-worktree.sh` / `scripts/teardown-worktree.sh` / `scripts/run-test.sh`
+- `tests/Support/Ci/TestDatabaseEnv.php` / `scripts/ci/ensure-test-db.php` / `scripts/ci/drop-test-db.php`
+- [`docs/pnpm-global-virtual-store-runbook.md`](pnpm-global-virtual-store-runbook.md) (node_modules 側の深堀り)
+- pnpm 公式: [Git Worktrees for Multi-Agent Development](https://pnpm.io/next/git-worktrees)
diff --git a/tests/Architecture/BugHuntInventoryCheckInvariantTest.php b/tests/Architecture/BugHuntInventoryCheckInvariantTest.php
new file mode 100644
index 0000000..bc0fec2
--- /dev/null
+++ b/tests/Architecture/BugHuntInventoryCheckInvariantTest.php
@@ -0,0 +1,194 @@
+<?php
+
+declare(strict_types=1);
+
+use Symfony\Component\Process\Process;
+
+/*
+ * Architecture invariant: bug-hunt インベントリ ドリフト検出器の最小契約。
+ *
+ * SoT = scripts/bug-hunt-inventory-check.sh 本体 + AGENTS.md §bug-hunt
+ * (「ドリフト検知は scripts/bug-hunt-inventory-check.sh」)。
+ *
+ * 固定する不変条件:
+ *   - スクリプトが存在し実行可能で、fail-closed (set -euo pipefail) であること
+ *   - インベントリ正本が `.claude/skills/app-bug-hunt/{screens.md,operations.md}` であること
+ *   - **exit code 規約 0=一致 / 3=ドリフト** を実際に満たすこと
+ *   - route:list 取得に失敗したときに exit 0 (fail-open) を返さないこと
+ *
+ * exit code 規約は「静的に読める宣言」ではなく **実走で** 検証する。ただし実 route:list
+ * (artisan boot + DB) には依存させない: スクリプトを一時 sandbox へ複製し、`php` を
+ * 固定 JSON を吐く shim に差し替えて走らせる (決定論・DB 不使用)。
+ * これが本 gate の負のコントロール (drift fixture で実際に exit 3 になること) も兼ねる。
+ */
+
+function bhicScriptPath(): string
+{
+    return base_path('scripts/bug-hunt-inventory-check.sh');
+}
+
+/**
+ * sandbox を組み立てる。scripts/ にスクリプト複製、.claude/skills/app-bug-hunt/ に
+ * インベントリ fixture、bin/ に `php` shim (固定 route:list JSON を吐く) を置く。
+ *
+ * @param  list<array{method: string, uri: string, middleware: list<string>, name: string}>  $routes
+ * @param  bool  $phpFails  true なら shim が失敗する (route:list 取得失敗の再現)
+ */
+function bhicMakeSandbox(array $routes, string $screensMd, string $operationsMd, bool $phpFails = false): string
+{
+    $sandbox = sys_get_temp_dir().'/bhic-'.bin2hex(random_bytes(6));
+    mkdir($sandbox.'/scripts', 0o755, true);
+    mkdir($sandbox.'/.claude/skills/app-bug-hunt', 0o755, true);
+    mkdir($sandbox.'/bin', 0o755, true);
+
+    copy(bhicScriptPath(), $sandbox.'/scripts/bug-hunt-inventory-check.sh');
+    file_put_contents($sandbox.'/.claude/skills/app-bug-hunt/screens.md', $screensMd);
+    file_put_contents($sandbox.'/.claude/skills/app-bug-hunt/operations.md', $operationsMd);
+
+    $json = json_encode($routes, JSON_UNESCAPED_SLASHES);
+    file_put_contents($sandbox.'/routes.json', is_string($json) ? $json : '[]');
+
+    $shim = $phpFails
+        ? "#!/usr/bin/env bash\necho 'route:list failed' >&2\nexit 1\n"
+        : "#!/usr/bin/env bash\ncat \"\$(dirname \"\$0\")/../routes.json\"\n";
+    file_put_contents($sandbox.'/bin/php', $shim);
+    chmod($sandbox.'/bin/php', 0o755);
+
+    return $sandbox;
+}
+
+function bhicRemoveSandbox(string $sandbox): void
+{
+    $it = new RecursiveIteratorIterator(
+        new RecursiveDirectoryIterator($sandbox, FilesystemIterator::SKIP_DOTS),
+        RecursiveIteratorIterator::CHILD_FIRST
+    );
+    foreach ($it as $entry) {
+        if (! $entry instanceof SplFileInfo) {
+            continue;
+        }
+        $entry->isDir() ? rmdir($entry->getPathname()) : unlink($entry->getPathname());
+    }
+    rmdir($sandbox);
+}
+
+/**
+ * sandbox 内でスクリプトを走らせ [exitCode, output] を返す。
+ *
+ * @return array{0: int|null, 1: string}
+ */
+function bhicRunSandbox(string $sandbox): array
+{
+    $process = new Process(
+        ['bash', $sandbox.'/scripts/bug-hunt-inventory-check.sh'],
+        $sandbox,
+        ['PATH' => $sandbox.'/bin:'.(getenv('PATH') ?: '/usr/bin:/bin')]
+    );
+    $process->run();
+
+    return [$process->getExitCode(), $process->getOutput().$process->getErrorOutput()];
+}
+
+test('scripts/bug-hunt-inventory-check.sh が存在し実行可能で fail-closed であること', function (): void {
+    $path = bhicScriptPath();
+    expect(file_exists($path))->toBeTrue('bug-hunt-inventory-check.sh が見つからない');
+    expect(is_executable($path))->toBeTrue('bug-hunt-inventory-check.sh に実行権が無い');
+
+    $src = file_get_contents($path);
+    expect($src)->toBeString();
+    /** @var string $src */
+    expect($src)->toContain('set -euo pipefail');
+});
+
+test('インベントリ正本が .claude/skills/app-bug-hunt/{screens,operations}.md であること', function (): void {
+    $src = file_get_contents(bhicScriptPath());
+    expect($src)->toBeString();
+    /** @var string $src */
+    expect($src)->toContain('.claude/skills/app-bug-hunt');
+    expect($src)->toMatch('/SCREENS="\$\{SKILL_DIR\}\/screens\.md"/');
+    expect($src)->toMatch('/OPS="\$\{SKILL_DIR\}\/operations\.md"/');
+    // exit 規約の宣言がスクリプト冒頭に残ること (運用者向けの契約表明)。
+    expect($src)->toMatch('/exit 0=.*3=/u');
+});
+
+test('exit code 規約 0=一致 / 3=ドリフト を実走で満たすこと (sandbox / DB 不使用)', function (): void {
+    if ((new Process(['which', 'python3']))->run() !== 0) {
+        $this->markTestSkipped('python3 が PATH に無いため exit code 実走検証を skip');
+    }
+
+    $screens = "# screens\n\n| ルート名 | URI |\n|---|---|\n| dashboard | /dashboard |\n";
+    $operations = "# operations\n\n| ルート名 | 操作 |\n|---|---|\n| projects.store | 作成 |\n";
+
+    // (1) 一致: route:list の全ルートがインベントリに載っている → exit 0
+    $match = bhicMakeSandbox([
+        ['method' => 'GET|HEAD', 'uri' => 'dashboard', 'middleware' => ['web'], 'name' => 'dashboard'],
+        ['method' => 'POST', 'uri' => 'projects', 'middleware' => ['web'], 'name' => 'projects.store'],
+    ], $screens, $operations);
+    try {
+        [$code, $out] = bhicRunSandbox($match);
+        expect($code)->toBe(0, "一致 fixture は exit 0 であるべき:\n".$out);
+        expect($out)->toContain('drift なし');
+    } finally {
+        bhicRemoveSandbox($match);
+    }
+});
+
+/*
+ * 負のコントロール: 未追記ルート (screens / operations 双方) で実際に exit 3 になること。
+ * gate が空振り (常に 0) でないことをここで担保する。
+ */
+test('負のコントロール: 未追記ルートがあれば exit 3 (ドリフト) になること', function (): void {
+    if ((new Process(['which', 'python3']))->run() !== 0) {
+        $this->markTestSkipped('python3 が PATH に無いため exit code 実走検証を skip');
+    }
+
+    $screens = "# screens\n\n| ルート名 | URI |\n|---|---|\n| dashboard | /dashboard |\n";
+    $operations = "# operations\n\n| ルート名 | 操作 |\n|---|---|\n| projects.store | 作成 |\n";
+
+    // screens 側ドリフト: 未追記の GET×web ルート
+    $screenDrift = bhicMakeSandbox([
+        ['method' => 'GET|HEAD', 'uri' => 'dashboard', 'middleware' => ['web'], 'name' => 'dashboard'],
+        ['method' => 'GET|HEAD', 'uri' => 'reports', 'middleware' => ['web'], 'name' => 'reports.index'],
+        ['method' => 'POST', 'uri' => 'projects', 'middleware' => ['web'], 'name' => 'projects.store'],
+    ], $screens, $operations);
+    try {
+        [$code, $out] = bhicRunSandbox($screenDrift);
+        expect($code)->toBe(3, "screens 未追記は exit 3 であるべき:\n".$out);
+        expect($out)->toContain('reports.index');
+    } finally {
+        bhicRemoveSandbox($screenDrift);
+    }
+
+    // operations 側ドリフト: 未追記の 非GET×web ルート
+    $opDrift = bhicMakeSandbox([
+        ['method' => 'GET|HEAD', 'uri' => 'dashboard', 'middleware' => ['web'], 'name' => 'dashboard'],
+        ['method' => 'POST', 'uri' => 'projects', 'middleware' => ['web'], 'name' => 'projects.store'],
+        ['method' => 'DELETE', 'uri' => 'projects/{project}', 'middleware' => ['web'], 'name' => 'projects.destroy'],
+    ], $screens, $operations);
+    try {
+        [$code, $out] = bhicRunSandbox($opDrift);
+        expect($code)->toBe(3, "operations 未追記は exit 3 であるべき:\n".$out);
+        expect($out)->toContain('projects.destroy');
+    } finally {
+        bhicRemoveSandbox($opDrift);
+    }
+});
+
+test('負のコントロール: route:list 取得に失敗したとき exit 0 (fail-open) を返さないこと', function (): void {
+    if ((new Process(['which', 'python3']))->run() !== 0) {
+        $this->markTestSkipped('python3 が PATH に無いため exit code 実走検証を skip');
+    }
+
+    $sandbox = bhicMakeSandbox(
+        [['method' => 'GET|HEAD', 'uri' => 'dashboard', 'middleware' => ['web'], 'name' => 'dashboard']],
+        "| dashboard | /dashboard |\n",
+        "| projects.store | 作成 |\n",
+        phpFails: true
+    );
+    try {
+        [$code, $out] = bhicRunSandbox($sandbox);
+        expect($code)->not->toBe(0, "route:list 失敗時に exit 0 を返してはならない (fail-open):\n".$out);
+    } finally {
+        bhicRemoveSandbox($sandbox);
+    }
+});
diff --git a/tests/Architecture/BugHuntSkillInvariantTest.php b/tests/Architecture/BugHuntSkillInvariantTest.php
new file mode 100644
index 0000000..67184a8
--- /dev/null
+++ b/tests/Architecture/BugHuntSkillInvariantTest.php
@@ -0,0 +1,185 @@
+<?php
+
+declare(strict_types=1);
+
+/*
+ * Architecture invariant: bug-hunt SKILL が「finding は停止信号ではない」規約を保持すること。
+ *
+ * SoT = .claude/skills/app-bug-hunt/SKILL.md (AI-CUE の文言が正本。aigenba の文言は参照しない)。
+ *
+ * 固定する事故: finding (特に Critical/High) を 1 件拾った時点でそのエリアの検証を畳んでしまう
+ * 打ち切り。SKILL の継続規定が「続行できるなら続行」程度の任意に薄まると、カバレッジ分母が
+ * finding 件数で縮み、run の比較可能性が失われる。
+ *
+ * 併せて、走行の再現性に直結する以下も固定する:
+ *   - 既定 (引数なし) の走行モード集合
+ *   - UI/UX ヒューリスティクス H11-H14 の存在と H13 viewport sweep 手順
+ *   - shard-report.md の逐次書き出し規約 (budget 超過で結果を全損しないため)
+ *
+ * DB 不使用の静的検査 (既存 Architecture テストと同じ作法)。
+ */
+
+function bhSkillSource(): string
+{
+    $contents = file_get_contents(base_path('.claude/skills/app-bug-hunt/SKILL.md'));
+    expect($contents)->toBeString('bug-hunt SKILL.md が読めない');
+    /** @var string $contents */
+    expect($contents)->not->toBe('', 'bug-hunt SKILL.md が空');
+
+    return $contents;
+}
+
+/**
+ * H13 の viewport sweep 手順が「H13 の文脈で」残っていることを検査するパターン。
+ *
+ * H13 見出しに係留し (文脈外の resize/寸法での誤通過を防ぐ)、mobile 375×667 / tablet 768×1024 /
+ * resize の 3 要素が近傍に揃うことを要求する。さらに resize と mobile 寸法の双方向近接で
+ * 「同一 sweep 手順であること」を担保する (寸法だけ別所に書かれた状態を通さない)。
+ * CLI 名 (playwright-cli) は厳密一致させない (ラッパ改名で再 drift しないため)。
+ */
+function bhSkillH13SweepPattern(): string
+{
+    return '/H13(?=[\s\S]{0,1200}?768[x×]1024)'
+        .'(?=[\s\S]{0,1200}?375[x×]667)'
+        .'(?=[\s\S]{0,1200}?resize)'
+        .'(?=[\s\S]{0,1400}?(?:resize[\s\S]{0,200}?375[x×]667|375[x×]667[\s\S]{0,200}?resize))/u';
+}
+
+/**
+ * 「finding は停止信号ではない」系の継続規約の違反一覧 (純関数)。
+ * 正の gate と負のコントロールが**同一の判定器**を使うようにするための分離。
+ *
+ * @return list<string> 違反一覧 (空 = 合格)
+ */
+function bhSkillContinuityViolations(string $contents): array
+{
+    /** @var array<string, string> $required key = 規約名, value = 検出パターン */
+    $required = [
+        'finding は停止信号ではない' => '/finding は停止信号ではない/u',
+        'ストーリー完走まで検証を終えない' => '/完走するまで検証は終わらない/u',
+        'blocker でも「詰みの先」を取りに行く' => '/詰みの先/u',
+        '到達不能な分だけ skip (理由必須)' => '/到達不能な分だけ\s*skip/u',
+        'finding 1 件で満足しない' => '/finding を 1 件で満足しない/u',
+        'Critical/High のエリアは深掘り' => '/Critical\/High[\s\S]{0,80}?深掘り/u',
+        'カバレッジ完了条件が finding と独立' => '/カバレッジ完了条件/u',
+        'finding 件数で分母を縮めない' => '/finding 件数で分母を縮めない/u',
+        'shard-report.md の逐次書き出し' => '/shard-report\.md は逐次書き出しする/u',
+        '最後にまとめて書く方式の禁止' => '/最後にまとめて書く方式は禁止/u',
+        'finding を見つけ次第の即追記' => '/finding を 1 件見つけるたびに即追記/u',
+    ];
+
+    $violations = [];
+    foreach ($required as $label => $pattern) {
+        if (preg_match($pattern, $contents) !== 1) {
+            $violations[] = "SKILL.md から規約が失われている: {$label}";
+        }
+    }
+
+    return $violations;
+}
+
+test('SKILL が「finding は停止信号ではない」系の継続規約を保持すること', function (): void {
+    expect(bhSkillContinuityViolations(bhSkillSource()))->toBe([]);
+});
+
+test('SKILL の既定 (引数なし) が全走行モード集合であること', function (): void {
+    $contents = bhSkillSource();
+
+    $m = [];
+    $matched = preg_match('/\*\*既定 \(引数なし\) = `([^`]+)`\*\*/u', $contents, $m);
+    expect($matched)->toBe(1, '既定 (引数なし) の宣言行が SKILL.md に無い');
+    /** @var array{0: string, 1: string} $m */
+    $declared = $m[1];
+
+    // 語順は将来変わりうるため集合として固定する (退行させたくないのはモードの欠落)。
+    foreach (['--all', '--coverage', '--parallel', '--deviate', '--real-llm'] as $flag) {
+        expect($declared)->toContain($flag);
+    }
+
+    // argument-hint (slash command の案内) も同じ集合を案内していること。
+    $hint = [];
+    expect(preg_match('/^argument-hint:\s*"([^"]*)"/mu', $contents, $hint))->toBe(1);
+    /** @var array{0: string, 1: string} $hint */
+    foreach (['--all', '--coverage', '--parallel', '--deviate', '--real-llm'] as $flag) {
+        expect($hint[1])->toContain($flag);
+    }
+});
+
+test('SKILL が UI/UX ヒューリスティクス H11-H14 を定義すること', function (): void {
+    $contents = bhSkillSource();
+
+    foreach (['H11', 'H12', 'H13', 'H14'] as $h) {
+        expect($contents)->toContain("| {$h} |");
+    }
+    expect($contents)->toMatch('/視覚破綻/u');
+    expect($contents)->toMatch('/アフォーダンス/u');
+    expect($contents)->toMatch('/レスポンシブ/u');
+    expect($contents)->toMatch('/アクセシビリティ基礎/u');
+});
+
+test('SKILL が H13 の viewport sweep 手順 (resize / mobile・tablet) を含むこと', function (): void {
+    $contents = bhSkillSource();
+
+    expect($contents)->toMatch(bhSkillH13SweepPattern());
+    // 失敗時にどの寸法が欠けたか読みやすくするための補助 assertion。
+    expect($contents)->toMatch('/375[x×]667/u');
+    expect($contents)->toMatch('/768[x×]1024/u');
+});
+
+test('Phase 4 の report テンプレートが UI/UX 検証行を含むこと', function (): void {
+    $contents = bhSkillSource();
+
+    expect($contents)->toMatch('/UI\/UX 検証:/u');
+    // H11-H14 の所見欄が report 骨子に残っていること。
+    expect($contents)->toMatch('/UI\/UX 検証:[\s\S]{0,200}?H14/u');
+});
+
+/*
+ * 負のコントロール: 各 pin が「規約が失われた状態」を実際に検出することを fixture で確認する
+ * (実 SKILL.md は書き換えない)。空振り gate を green として扱わないため。
+ */
+test('負のコントロール: 継続規約が薄まった SKILL 文面を検出する', function (): void {
+    // 「続行できるなら続行」程度の任意規定に薄まり、逐次書き出し規約も消えた状態。
+    $weakened = <<<'MD'
+    ## 走行プロトコル
+
+    5. 異常を見たら screenshot で証跡保存 → finding 記録 → 続行できるなら続行する。
+    6. finding を見つけたらそのエリアは十分とみなしてよい。
+
+    ## Phase 4: レポート
+
+    走行が終わったら report を書く。
+    MD;
+
+    $violations = bhSkillContinuityViolations($weakened);
+    $joined = implode("\n", $violations);
+    expect($violations)->toHaveCount(11);
+    expect($joined)->toContain('finding は停止信号ではない');
+    expect($joined)->toContain('finding 件数で分母を縮めない');
+    expect($joined)->toContain('最後にまとめて書く方式の禁止');
+
+    // 正のコントロール: 実 SKILL.md は違反ゼロ (正 gate と同一判定器であることの確認)。
+    expect(bhSkillContinuityViolations(bhSkillSource()))->toBe([]);
+});
+
+test('負のコントロール: H13 sweep の非近接 (寸法だけ別所) を検出する', function (): void {
+    // H13 行と各寸法はあるが、resize が mobile 寸法から 200 字超離れている = 同一 sweep 手順でない。
+    $withoutSweep = "| H13 | レスポンシブ | High |\n"
+        .'375×667 という寸法だけ先に書き'.str_repeat('x', 250)."ここで resize の語が出る\n"
+        ."768×1024 も別所にある\n";
+    expect($withoutSweep)->not->toMatch(bhSkillH13SweepPattern());
+
+    // 正のコントロール: sweep 行として書かれていれば掛かる。
+    $withSweep = "| H13 | レスポンシブ | High |\n"
+        .'`playwright-cli resize` を mobile 375×667 と tablet 768×1024 に変えて確認する';
+    expect($withSweep)->toMatch(bhSkillH13SweepPattern());
+});
+
+test('負のコントロール: 既定モード集合の欠落を検出する', function (): void {
+    // --real-llm が既定から落ちた宣言行。
+    $declaration = '> **既定 (引数なし) = `--all --coverage --parallel --deviate`**';
+    $m = [];
+    expect(preg_match('/\*\*既定 \(引数なし\) = `([^`]+)`\*\*/u', $declaration, $m))->toBe(1);
+    /** @var array{0: string, 1: string} $m */
+    expect($m[1])->not->toContain('--real-llm');
+});
diff --git a/tests/Architecture/BughuntEnvExampleContractTest.php b/tests/Architecture/BughuntEnvExampleContractTest.php
new file mode 100644
index 0000000..3ca5035
--- /dev/null
+++ b/tests/Architecture/BughuntEnvExampleContractTest.php
@@ -0,0 +1,172 @@
+<?php
+
+declare(strict_types=1);
+
+/*
+ * Architecture invariant: .env.bughunt.local.example が bug-hunt 環境の
+ * 「production 同等性の最小セット」を保持すること。
+ *
+ * SoT = .env.bughunt.local.example 本体 + AGENTS.md §bug-hunt (隔離方針) +
+ *       .claude/skills/app-bug-hunt/SKILL.md (走行前提)。example が手元 .env の正本。
+ *
+ * 最小セット = プロセス間契約・検証忠実度・dev DB 隔離に影響する env:
+ *   - APP_ENV=bughunt.local      : 環境判定 (fake_externals / seeder の fail-secure guard の軸)
+ *   - APP_NAME                   : 画面のタイトル/ロゴ/フッターに露出。単独ロードされるファイルなので
+ *                                  `${APP_NAME}` の自己参照はリテラル露出事故になる (実際に発生した)
+ *   - APP_LOCALE=ja              : bug-hunt はユーザー向け文言 (日本語) の検証環境。en のままだと
+ *                                  production と異なる文言を検証してしまう
+ *   - DB_DATABASE=bug_hunt       : dev DB 隔離の核 (^bug_hunt(_[1-8])?$ のみ許可)
+ *   - TESTING_FAKE_EXTERNALS=true: 決済等の外部を fake に落とす (実課金を踏まない)
+ *   - ADMIN_MFA_REQUIRED=false   : true だと admin ログイン後 TOTP 強制で探索が詰む
+ *
+ * 併せて「秘密値を example に焼き込まない」ことも固定する (APP_KEY / CIPHERSWEET_KEY /
+ * DB_PASSWORD / BUGHUNT_ADMIN_PASSWORD は空 = 手元で上書き必須)。
+ *
+ * 注: `${VAR}` 未解決参照の一般則は EnvExampleInvariantTest が本ファイルも含めて検査済み。
+ * 本テストは「最小セットの存在と値」の契約に限定する。
+ * DB 不使用の静的検査 (既存 Architecture テストと同じ作法)。
+ */
+
+/**
+ * 最小セット契約の違反一覧を返す純関数 (負のコントロール用に content を受け取る)。
+ *
+ * @return list<string> 違反一覧 (空 = 合格)
+ */
+function bughuntEnvExampleViolations(string $content): array
+{
+    $violations = [];
+
+    /** @var array<string, string> $exactValues */
+    $exactValues = [
+        'APP_ENV' => 'bughunt.local',
+        'APP_LOCALE' => 'ja',
+        'DB_DATABASE' => 'bug_hunt',
+        'TESTING_FAKE_EXTERNALS' => 'true',
+        'ADMIN_MFA_REQUIRED' => 'false',
+    ];
+    foreach ($exactValues as $key => $expected) {
+        $m = [];
+        // 行末コメント (`DB_DATABASE=bug_hunt   # 説明`) を許容して値だけを取り出す。
+        if (preg_match('/^'.preg_quote($key, '/').'=([^\s#]*)/m', $content, $m) !== 1) {
+            $violations[] = "{$key} が定義されていない";
+
+            continue;
+        }
+        /** @var array{0: string, 1: string} $m */
+        if (trim($m[1], "\"'") !== $expected) {
+            $violations[] = "{$key} は {$expected} であること (実際: {$m[1]})";
+        }
+    }
+
+    // APP_NAME は実値リテラル必須 (空 / 自己参照は画面露出事故)。
+    $appName = [];
+    if (preg_match('/^APP_NAME=(.*)$/m', $content, $appName) !== 1) {
+        $violations[] = 'APP_NAME が定義されていない';
+    } else {
+        /** @var array{0: string, 1: string} $appName */
+        $value = trim(trim($appName[1]), "\"'");
+        if ($value === '') {
+            $violations[] = 'APP_NAME が空 (bug-hunt 環境は単独ロードのため実値リテラルが必要)';
+        }
+        if (str_contains($value, '${')) {
+            $violations[] = 'APP_NAME に ${...} 参照 (単独ロードでは解決されずリテラル露出する)';
+        }
+    }
+
+    // 重複定義 (後勝ちで意図しない値になる) の禁止。
+    foreach (['APP_ENV', 'APP_NAME', 'APP_LOCALE', 'DB_DATABASE'] as $key) {
+        $count = preg_match_all('/^'.preg_quote($key, '/').'=/m', $content);
+        if ($count > 1) {
+            $violations[] = "{$key} が {$count} 回定義されている (重複禁止)";
+        }
+    }
+
+    // 秘密値は example に焼き込まない (手元で上書き必須)。
+    foreach (['APP_KEY', 'CIPHERSWEET_KEY', 'DB_PASSWORD', 'BUGHUNT_ADMIN_PASSWORD'] as $key) {
+        $m = [];
+        if (preg_match('/^'.preg_quote($key, '/').'=([^\s#]*)/m', $content, $m) !== 1) {
+            $violations[] = "{$key} のプレースホルダ行が無い";
+
+            continue;
+        }
+        /** @var array{0: string, 1: string} $m */
+        if (trim($m[1], "\"'") !== '') {
+            $violations[] = "{$key} に値が焼き込まれている (example は空で手元上書き必須)";
+        }
+    }
+
+    return $violations;
+}
+
+test('.env.bughunt.local.example が production 同等性の最小セットを満たすこと', function (): void {
+    $content = file_get_contents(base_path('.env.bughunt.local.example'));
+    expect($content)->toBeString('.env.bughunt.local.example が読めない');
+    /** @var string $content */
+    expect(bughuntEnvExampleViolations($content))->toBe([]);
+});
+
+test('.env.bughunt.local.example の DB_DATABASE が shard script の DB 接頭辞既定と一致すること', function (): void {
+    $content = file_get_contents(base_path('.env.bughunt.local.example'));
+    expect($content)->toBeString();
+    /** @var string $content */
+    $env = [];
+    expect(preg_match('/^DB_DATABASE=([^\s#]*)/m', $content, $env))->toBe(1);
+    /** @var array{0: string, 1: string} $env */
+    $script = file_get_contents(base_path('scripts/bug-hunt-shard.sh'));
+    expect($script)->toBeString();
+    /** @var string $script */
+    $prefix = [];
+    expect(preg_match('/^BUGHUNT_DB_PREFIX="\$\{BUGHUNT_DB_PREFIX:-([a-z_]+)\}"/m', $script, $prefix))->toBe(1);
+    /** @var array{0: string, 1: string} $prefix */
+
+    // 乖離すると直列走行 (shard 0) の DB 名が guard regex (^bug_hunt(_[1-8])?$) を外れて abort する。
+    expect(trim($env[1], "\"'"))->toBe($prefix[1]);
+});
+
+/*
+ * 負のコントロール: 契約が破れた fixture を実際に検出することを確認する (実ファイルは書き換えない)。
+ */
+test('負のコントロール: 最小セット欠落 / 自己参照 / 秘密値焼き込みを検出する', function (): void {
+    $broken = <<<'ENV'
+    APP_ENV=bughunt.local
+    APP_NAME="${APP_NAME}"
+    DB_DATABASE=bug_hunt
+    DB_PASSWORD=hunter2
+    APP_KEY=base64:AAAA
+    CIPHERSWEET_KEY=deadbeef
+    BUGHUNT_ADMIN_PASSWORD=
+    TESTING_FAKE_EXTERNALS=true
+    ADMIN_MFA_REQUIRED=true
+    ENV;
+
+    $violations = bughuntEnvExampleViolations($broken);
+    $joined = implode("\n", $violations);
+
+    expect($joined)->toContain('APP_LOCALE が定義されていない');          // 最小セット欠落
+    expect($joined)->toContain('APP_NAME に ${...} 参照');                 // 自己参照 (リテラル露出事故)
+    expect($joined)->toContain('DB_PASSWORD に値が焼き込まれている');       // 秘密値
+    expect($joined)->toContain('APP_KEY に値が焼き込まれている');
+    expect($joined)->toContain('ADMIN_MFA_REQUIRED は false であること');   // TOTP 詰み
+});
+
+test('負のコントロール: 重複定義 (後勝ち事故) を検出する', function (): void {
+    $duplicated = <<<'ENV'
+    APP_ENV=bughunt.local
+    APP_NAME="AI-CUE"
+    APP_LOCALE=ja
+    APP_LOCALE=en
+    DB_DATABASE=bug_hunt
+    DB_DATABASE=app_dev
+    DB_PASSWORD=
+    APP_KEY=
+    CIPHERSWEET_KEY=
+    BUGHUNT_ADMIN_PASSWORD=
+    TESTING_FAKE_EXTERNALS=true
+    ADMIN_MFA_REQUIRED=false
+    ENV;
+
+    $joined = implode("\n", bughuntEnvExampleViolations($duplicated));
+    expect($joined)->toContain('DB_DATABASE が 2 回定義されている');
+    // 先勝ち抽出なので APP_LOCALE の値自体は ja に見えるが、重複そのものを違反として検出する。
+    expect($joined)->toContain('DB_DATABASE');
+});
diff --git a/tests/Architecture/BughuntOrchestratorGateInvariantTest.php b/tests/Architecture/BughuntOrchestratorGateInvariantTest.php
new file mode 100644
index 0000000..77115ed
--- /dev/null
+++ b/tests/Architecture/BughuntOrchestratorGateInvariantTest.php
@@ -0,0 +1,261 @@
+<?php
+
+declare(strict_types=1);
+
+/*
+ * Architecture invariant (B-HARNESS-01): bug-hunt の orchestrator gate 2 層が構造的に整合すること。
+ *
+ * SoT:
+ *   - AGENTS.md §bug-hunt「dev DB 防御 (非交渉)」= provision/teardown は
+ *     BUGHUNT_ORCHESTRATOR=1 を持つ親のみ (worker は default-deny)
+ *   - scripts/bug-hunt-shard.sh  = MECHANICAL gate (require_orchestrator)
+ *   - .claude/agents/bughunt-shard.md = AGENT prose gate (worker への禁止規律)
+ *
+ * 固定する事故: 環境障害に直面した shard worker が自走復旧を試み、共有 worktree / serve / DB を
+ * 壊して run 全体を巻き添えにすること。機械 gate (env token の default-deny) と散文 gate
+ * (worker への明示禁止) の 2 層が呼応していることを、ファイル静的検査で固定する。
+ *
+ * 実行配線 (副作用の前に die すること) は `scripts/bug-hunt-shard.sh self-test` の
+ * end-to-end ケースが担う (二段防御): Architecture = 静的配線、self-test = 実行配線。
+ * DB 不使用の静的検査 (既存 Architecture テストと同じ作法)。
+ */
+
+function bughuntGateReadSource(string $relativePath): string
+{
+    $contents = file_get_contents(base_path($relativePath));
+    expect($contents)->toBeString("{$relativePath} が読めない");
+    /** @var string $contents */
+    expect($contents)->not->toBe('', "{$relativePath} が空");
+
+    return $contents;
+}
+
+/**
+ * `^name()` 行から次の `^cmd_` 定義 (または EOF) までの関数窓を切り出す。
+ *
+ * 非貪欲 `\n\}` 終端は使わない: 関数本体が heredoc (`<<'PY'` 等) 内に行頭 `}` を持つと
+ * 最短マッチがそこで止まり真の末尾を取り逃す。`/m` + 先読みで「次の cmd_ 定義の直前まで」
+ * を取れば heredoc 持ち関数でも安全側に切り出せる。
+ */
+function bughuntGateFunctionWindow(string $source, string $name): string
+{
+    $m = [];
+    // cmd_provision と cmd_provision_all を取り違えないよう `()` まで含めてアンカーする。
+    $matched = preg_match('/^'.preg_quote($name, '/').'\(\)[\s\S]*?(?=^cmd_|\z)/m', $source, $m);
+    expect($matched)->toBe(1, "関数窓が見つからない: {$name}");
+
+    /** @var array{0: string} $m */
+    return $m[0];
+}
+
+/**
+ * heredoc を持たない関数 (require_orchestrator) 用の窓。行頭 `}` で終端する。
+ * cmd_ 窓 (次の cmd_ 定義まで) は他関数を巻き込むため、gate 本体の検査には使わない。
+ */
+function bughuntGateBraceWindow(string $source, string $name): string
+{
+    $m = [];
+    $matched = preg_match('/^'.preg_quote($name, '/').'\(\)\s*\{[\s\S]*?^\}/m', $source, $m);
+    expect($matched)->toBe(1, "関数窓が見つからない: {$name}");
+
+    /** @var array{0: string} $m */
+    return $m[0];
+}
+
+/**
+ * 関数窓から「最初の実効文」を返す。関数定義行・`{`・コメント・空行・引数束縛のみの
+ * `local ...` 宣言は読み飛ばす (= 副作用を持たない前置き)。
+ *
+ * aigenba 版の「gate が特定の呼び出しより前に現れる」より強く、「gate が最初の実効文である」
+ * ことを直接固定する (AI-CUE の cmd_teardown は aigenba と本体構造が異なり、
+ * 特定呼び出しへのアンカーが脆いため)。
+ */
+function bughuntGateFirstEffectiveStatement(string $window): string
+{
+    foreach (preg_split('/\R/', $window) ?: [] as $line) {
+        $trimmed = trim($line);
+        if ($trimmed === '' || str_starts_with($trimmed, '#') || $trimmed === '{') {
+            continue;
+        }
+        if (preg_match('/^[A-Za-z_][A-Za-z0-9_]*\(\)\s*\{?$/', $trimmed) === 1) {
+            continue; // 関数定義行
+        }
+        if (preg_match('/^local\s/', $trimmed) === 1) {
+            continue; // 引数束縛 (副作用なし)
+        }
+
+        return $trimmed;
+    }
+
+    return '';
+}
+
+test('bug-hunt-shard.sh の require_orchestrator が default-deny (token 無し → die 1) であること', function (): void {
+    $sh = bughuntGateReadSource('scripts/bug-hunt-shard.sh');
+
+    expect($sh)->toMatch('/require_orchestrator\s*\(\)\s*\{/');
+    $window = bughuntGateBraceWindow($sh, 'require_orchestrator');
+    // token があれば通し、無ければ die 1 (default-deny)。
+    expect($window)->toMatch('/BUGHUNT_ORCHESTRATOR/');
+    expect($window)->toMatch('/die\s+1/');
+    // self-test (dryrun) は実資源に触れないため素通りさせる例外。
+    expect($window)->toMatch('/is_dryrun\s*&&\s*return\s*0/');
+});
+
+test('provision / provision-all / teardown が最初の実効文で require_orchestrator を呼ぶこと', function (): void {
+    $sh = bughuntGateReadSource('scripts/bug-hunt-shard.sh');
+
+    $expectations = [
+        'cmd_provision' => 'provision',
+        'cmd_provision_all' => 'provision-all',
+        'cmd_teardown' => 'teardown',
+    ];
+    foreach ($expectations as $function => $label) {
+        $window = bughuntGateFunctionWindow($sh, $function);
+        $first = bughuntGateFirstEffectiveStatement($window);
+        expect($first)->toMatch(
+            '/^require_orchestrator\s+"'.preg_quote($label, '/').'"/',
+            "{$function} の最初の実効文が require_orchestrator \"{$label}\" でない (実際: {$first})"
+        );
+    }
+});
+
+/**
+ * AGENTS.md §bug-hunt が規約側に持つべき記述の違反一覧 (純関数 = 負のコントロール用)。
+ *
+ * @return list<string>
+ */
+function bughuntAgentsMdViolations(string $content): array
+{
+    $violations = [];
+    if (! str_contains($content, 'BUGHUNT_ORCHESTRATOR=1')) {
+        $violations[] = 'AGENTS.md に BUGHUNT_ORCHESTRATOR=1 の記述が無い';
+    }
+    if (! str_contains($content, 'default-deny')) {
+        $violations[] = 'AGENTS.md に default-deny の明記が無い';
+    }
+    // 機械 gate の対象コマンドが規約側にも書かれていること。
+    if (preg_match('/`provision`\/`teardown`/', $content) !== 1) {
+        $violations[] = 'AGENTS.md に gate 対象コマンド (provision/teardown) の明記が無い';
+    }
+
+    return $violations;
+}
+
+/**
+ * bughunt-shard.md (worker への散文 gate) が持つべき記述の違反一覧 (純関数)。
+ *
+ * @return list<string>
+ */
+function bughuntShardAgentViolations(string $content): array
+{
+    $violations = [];
+    foreach (['B-HARNESS-01', '環境障害時の鉄則'] as $needle) {
+        if (! str_contains($content, $needle)) {
+            $violations[] = "bughunt-shard.md に「{$needle}」が無い";
+        }
+    }
+    // 復旧を試みず報告して終了する規律。
+    if (preg_match('/復旧を絶対に試みない/u', $content) !== 1) {
+        $violations[] = 'bughunt-shard.md に「復旧を絶対に試みない」規律が無い';
+    }
+    // 禁止コマンド列 (worktree / provision 系)。
+    foreach (['teardown-worktree.sh', 'setup-worktree.sh', 'provision-all'] as $needle) {
+        if (! str_contains($content, $needle)) {
+            $violations[] = "bughunt-shard.md の禁止コマンド列に {$needle} が無い";
+        }
+    }
+    if (preg_match('/git worktree (add|remove|prune)/', $content) !== 1) {
+        $violations[] = 'bughunt-shard.md の禁止コマンド列に git worktree 操作が無い';
+    }
+    // 散文 gate 単独ではなく「機械的にも拒否される」ことを worker に伝える = 2 層の呼応。
+    if (! str_contains($content, 'BUGHUNT_ORCHESTRATOR')) {
+        $violations[] = 'bughunt-shard.md が機械 gate (BUGHUNT_ORCHESTRATOR) に言及していない';
+    }
+    // 環境ハザードは自分の shard-report.md に記録して終了する。
+    if (preg_match('/shard-report\.md/', $content) !== 1) {
+        $violations[] = 'bughunt-shard.md に shard-report.md への記録指示が無い';
+    }
+
+    return $violations;
+}
+
+test('AGENTS.md §bug-hunt が BUGHUNT_ORCHESTRATOR の default-deny を規約として持つこと', function (): void {
+    expect(bughuntAgentsMdViolations(bughuntGateReadSource('AGENTS.md')))->toBe([]);
+});
+
+test('bughunt-shard.md が環境障害鉄則・禁止コマンド列・機械 gate との呼応を持つこと', function (): void {
+    expect(bughuntShardAgentViolations(bughuntGateReadSource('.claude/agents/bughunt-shard.md')))->toBe([]);
+});
+
+/*
+ * 負のコントロール: gate が「壊れた配線」を実際に検出することを fixture で確認する
+ * (実スクリプトは書き換えない)。
+ */
+test('負のコントロール: gate が副作用の後ろに落ちた配線を検出する', function (): void {
+    $broken = <<<'SH'
+    cmd_provision() {
+        local shard=$1 run_id=$2
+        assert_worktree_context
+        require_orchestrator "provision"
+    }
+    cmd_teardown() {
+        :
+    }
+    SH;
+
+    $first = bughuntGateFirstEffectiveStatement(bughuntGateFunctionWindow($broken, 'cmd_provision'));
+    expect($first)->toBe('assert_worktree_context');
+    expect($first)->not->toMatch('/^require_orchestrator/');
+
+    // 正のコントロール: gate が先頭なら検出しない。
+    $good = <<<'SH'
+    cmd_provision() {
+        local shard=$1 run_id=$2
+        require_orchestrator "provision"
+        assert_worktree_context
+    }
+    cmd_teardown() {
+        :
+    }
+    SH;
+    expect(bughuntGateFirstEffectiveStatement(bughuntGateFunctionWindow($good, 'cmd_provision')))
+        ->toMatch('/^require_orchestrator\s+"provision"/');
+});
+
+test('負のコントロール: default-deny を fail-open にした require_orchestrator を検出する', function (): void {
+    // token 未設定でも return 0 する (= 誰でも通る) 実装。die 1 が消えている。
+    $broken = <<<'SH'
+    require_orchestrator() {
+        is_dryrun && return 0
+        return 0
+    }
+    cmd_provision() {
+        :
+    }
+    SH;
+
+    $window = bughuntGateBraceWindow($broken, 'require_orchestrator');
+    expect($window)->not->toMatch('/BUGHUNT_ORCHESTRATOR/');
+    expect($window)->not->toMatch('/die\s+1/');
+});
+
+test('負のコントロール: 散文 gate (規約文書) の喪失を検出する', function (): void {
+    // AGENTS.md から default-deny 規約が消え、worker 向け禁止コマンド列も薄まった状態。
+    $weakenedAgents = "## bug-hunt\n\nDB 操作は wrapper 経由で行う。provision は親が実行する。\n";
+    $agentsViolations = bughuntAgentsMdViolations($weakenedAgents);
+    expect($agentsViolations)->not->toBe([]);
+    expect(implode("\n", $agentsViolations))->toContain('default-deny');
+
+    $weakenedShardAgent = "あなたはバグハントの 1 シャードワーカーである。\n"
+        ."環境が壊れたら状況に応じて復旧してよい。\n";
+    $shardViolations = bughuntShardAgentViolations($weakenedShardAgent);
+    expect($shardViolations)->not->toBe([]);
+    expect(implode("\n", $shardViolations))->toContain('B-HARNESS-01');
+    expect(implode("\n", $shardViolations))->toContain('teardown-worktree.sh');
+    expect(implode("\n", $shardViolations))->toContain('BUGHUNT_ORCHESTRATOR');
+
+    // 正のコントロール: 実ファイルは違反ゼロ (上の正 gate と同じ判定器であることの確認)。
+    expect(bughuntAgentsMdViolations(bughuntGateReadSource('AGENTS.md')))->toBe([]);
+    expect(bughuntShardAgentViolations(bughuntGateReadSource('.claude/agents/bughunt-shard.md')))->toBe([]);
+});
diff --git a/tests/Architecture/InertiaRenderPageExistsInvariantTest.php b/tests/Architecture/InertiaRenderPageExistsInvariantTest.php
new file mode 100644
index 0000000..c266355
--- /dev/null
+++ b/tests/Architecture/InertiaRenderPageExistsInvariantTest.php
@@ -0,0 +1,521 @@
+<?php
+
+declare(strict_types=1);
+
+/*
+ * Architecture invariant: `Inertia::render` / `inertia()` が **literal で** 参照するページ
+ * コンポーネントが `resources/js/pages/` に実在すること。
+ *
+ * SoT = app/ + routes/ の literal 参照 と resources/js/pages/ の実体、および
+ * resources/js/inertia.ts の resolver 規約 (`./pages/{name}.svelte` を glob 解決し、
+ * 未解決なら throw する)。参照先が無いページは **本番で白画面** になり、しかも
+ * その画面へ入るまで誰も気づかない。
+ *
+ * 検出方式: PhpToken::tokenize で `Inertia::render(` / `inertia(` の第 1 引数を抽出する
+ * (コメント・改行・named 引数 `component:` に対して regex より頑健)。
+ *
+ * **検査対象は literal 引数のみ**。変数・定数・連結など非 literal の第 1 引数は
+ * 静的にページ名を決定できないため **存在検査の対象外**とする。ただし黙って穴が開かないよう、
+ * 非 literal 呼び出し・`Route::inertia`・非正準形の facade 参照は「出現したら fail」させ、
+ * 必要になった時点で本テストの拡張 (or allowlist 登録) を強制する (deny-by-default)。
+ *
+ * DB 不使用の静的検査 (既存 Architecture テストと同じ作法)。
+ */
+
+/**
+ * 非 literal ページ名の明示 allowlist (現状ゼロ)。
+ *
+ * 行番号非依存キー「相対パス::包囲関数/メソッド::呼出形::第1引数の正規化表現」形式。
+ * 例: 'app/Http/Controllers/FooController.php::index::Inertia::render::$page'
+ * 新規追加は「なぜ静的に決定できないか」の理由コメントとセットでのみ許可する。
+ *
+ * @var list<string>
+ */
+const INERTIA_DYNAMIC_ALLOWLIST = [];
+
+/**
+ * ページ名 → ページコンポーネント絶対パス (純関数)。
+ */
+function inertiaPageComponentPath(string $pageName): string
+{
+    return base_path('resources/js/pages/'.str_replace('\\', '/', $pageName).'.svelte');
+}
+
+/**
+ * 走査対象 (app/ + routes/) の PHP ファイル一覧。
+ *
+ * @return list<array{absolute: string, relative: string}>
+ */
+function inertiaScanTargets(): array
+{
+    $root = base_path();
+    $files = [];
+    foreach (['app', 'routes'] as $dir) {
+        $iterator = new RecursiveIteratorIterator(
+            new RecursiveDirectoryIterator($root.'/'.$dir, FilesystemIterator::SKIP_DOTS)
+        );
+        foreach ($iterator as $file) {
+            if (! $file instanceof SplFileInfo || $file->getExtension() !== 'php') {
+                continue;
+            }
+            $absolute = $file->getRealPath();
+            if (! is_string($absolute)) {
+                continue;
+            }
+            $files[] = [
+                'absolute' => $absolute,
+                'relative' => ltrim(str_replace($root, '', $absolute), '/'),
+            ];
+        }
+    }
+
+    return $files;
+}
+
+/**
+ * index 以降で最初の significant token (whitespace / comment 以外) の index。
+ *
+ * @param  list<PhpToken>  $tokens
+ */
+function inertiaNextSignificant(array $tokens, int $index): ?int
+{
+    $count = count($tokens);
+    for ($i = $index; $i < $count; $i++) {
+        if (! $tokens[$i]->is([T_WHITESPACE, T_COMMENT, T_DOC_COMMENT])) {
+            return $i;
+        }
+    }
+
+    return null;
+}
+
+/**
+ * index 以前で直近の significant token の index。
+ *
+ * @param  list<PhpToken>  $tokens
+ */
+function inertiaPrevSignificant(array $tokens, int $index): ?int
+{
+    for ($i = $index; $i >= 0; $i--) {
+        if (! $tokens[$i]->is([T_WHITESPACE, T_COMMENT, T_DOC_COMMENT])) {
+            return $i;
+        }
+    }
+
+    return null;
+}
+
+/**
+ * 第 1 引数の正規化表現 (値開始から depth 0 の `,` または対応する閉じ括弧まで)。
+ *
+ * @param  list<PhpToken>  $tokens
+ */
+function inertiaNormalizeFirstArg(array $tokens, int $startIndex): string
+{
+    $depth = 0;
+    $parts = [];
+    $count = count($tokens);
+    for ($i = $startIndex; $i < $count; $i++) {
+        $token = $tokens[$i];
+        $text = $token->text;
+        if ($text === '(' || $text === '[' || $text === '{') {
+            $depth++;
+        } elseif ($text === ')' || $text === ']' || $text === '}') {
+            if ($depth === 0) {
+                break;
+            }
+            $depth--;
+        } elseif ($text === ',' && $depth === 0) {
+            break;
+        }
+        if ($token->is([T_WHITESPACE, T_COMMENT, T_DOC_COMMENT])) {
+            continue;
+        }
+        $parts[] = $text;
+    }
+
+    return implode('', $parts);
+}
+
+/**
+ * index の token を包囲する function / method 名 (allowlist キー用)。
+ *
+ * @param  list<PhpToken>  $tokens
+ */
+function inertiaEnclosingFunction(array $tokens, int $index): string
+{
+    for ($i = $index; $i >= 0; $i--) {
+        if (! $tokens[$i]->is(T_FUNCTION)) {
+            continue;
+        }
+        $nameIndex = inertiaNextSignificant($tokens, $i + 1);
+        if ($nameIndex !== null && $tokens[$nameIndex]->is(T_STRING)) {
+            return $tokens[$nameIndex]->text;
+        }
+        // 無名関数はさらに外側を探す
+    }
+
+    return '(top-level)';
+}
+
+/**
+ * quote / b-prefix を除去して literal 文字列の中身を返す。ページ名として扱えないものは null。
+ */
+function inertiaLiteralValue(string $raw): ?string
+{
+    if (preg_match('/\A[bB]?([\'"])(.*)\1\z/s', $raw, $m) !== 1) {
+        return null;
+    }
+    /** @var array{0: string, 1: string, 2: string} $m */
+    $inner = $m[2];
+    if (str_contains($inner, '\\') && $m[1] === "'") {
+        $inner = str_replace(['\\\\', "\\'"], ['\\', "'"], $inner);
+    } elseif (str_contains($inner, '\\')) {
+        return null; // double quote の escape sequence はページ名として扱わない
+    }
+    if (str_contains($inner, '$')) {
+        return null;
+    }
+
+    return $inner;
+}
+
+/**
+ * 1 ファイル分の PHP ソースを token 走査し、Inertia ページ参照を収集する (純関数)。
+ *
+ * @return array{
+ *     literals: list<array{page: string, location: string}>,
+ *     dynamics: list<string>,
+ *     routeInertia: list<string>,
+ *     nonCanonical: list<string>,
+ * }
+ */
+function inertiaCollectFromSource(string $source, string $relative): array
+{
+    $literals = [];
+    $dynamics = [];
+    $routeInertia = [];
+    $nonCanonical = [];
+
+    /** @var list<PhpToken> $tokens */
+    $tokens = PhpToken::tokenize($source);
+    $count = count($tokens);
+
+    for ($i = 0; $i < $count; $i++) {
+        $token = $tokens[$i];
+
+        // ---- 非正準形の Inertia facade 参照 (走査をすり抜けるため禁止) ----
+        // 走査は正準形 `Inertia::render` を前提にする。FQCN (`\Inertia\Inertia::render`) /
+        // qualified / alias import (`use Inertia\Inertia as X`) が増えると silent hole になる。
+        if ($token->is([T_NAME_FULLY_QUALIFIED, T_NAME_QUALIFIED])
+            && str_ends_with($token->text, 'Inertia\\Inertia')) {
+            $colonIndex = inertiaNextSignificant($tokens, $i + 1);
+            if ($colonIndex !== null && $tokens[$colonIndex]->is(T_DOUBLE_COLON)) {
+                $nonCanonical[] = "{$relative}:{$token->line} ({$token->text}:: 形は正準形 Inertia:: に統一)";
+
+                continue;
+            }
+        }
+        if ($token->is(T_USE)) {
+            $nameIndex = inertiaNextSignificant($tokens, $i + 1);
+            if ($nameIndex !== null
+                && $tokens[$nameIndex]->is([T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED, T_STRING])
+                && str_ends_with($tokens[$nameIndex]->text, 'Inertia\\Inertia')) {
+                $asIndex = inertiaNextSignificant($tokens, $nameIndex + 1);
+                if ($asIndex !== null && $tokens[$asIndex]->is(T_AS)) {
+                    $nonCanonical[] = "{$relative}:{$token->line} (use Inertia\\Inertia as ... の alias import 禁止)";
+
+                    continue;
+                }
+            }
+        }
+
+        // ---- Route::inertia 検出 (ページ名が走査対象外になるため禁止) ----
+        $isRouteFacade = ($token->is(T_STRING) && $token->text === 'Route')
+            || ($token->is([T_NAME_FULLY_QUALIFIED, T_NAME_QUALIFIED])
+                && str_ends_with($token->text, 'Facades\\Route'));
+        if ($isRouteFacade) {
+            $colonIndex = inertiaNextSignificant($tokens, $i + 1);
+            if ($colonIndex !== null && $tokens[$colonIndex]->is(T_DOUBLE_COLON)) {
+                $methodIndex = inertiaNextSignificant($tokens, $colonIndex + 1);
+                if ($methodIndex !== null && $tokens[$methodIndex]->is(T_STRING)
+                    && $tokens[$methodIndex]->text === 'inertia') {
+                    $routeInertia[] = "{$relative}:{$token->line}";
+
+                    continue;
+                }
+            }
+        }
+
+        $callKind = null;
+        $openIndex = null;
+
+        // ---- Inertia::render( 検出 ----
+        if ($token->is(T_STRING) && $token->text === 'Inertia') {
+            $colonIndex = inertiaNextSignificant($tokens, $i + 1);
+            if ($colonIndex === null || ! $tokens[$colonIndex]->is(T_DOUBLE_COLON)) {
+                continue;
+            }
+            $methodIndex = inertiaNextSignificant($tokens, $colonIndex + 1);
+            if ($methodIndex === null || ! $tokens[$methodIndex]->is(T_STRING)
+                || $tokens[$methodIndex]->text !== 'render') {
+                continue;
+            }
+            $parenIndex = inertiaNextSignificant($tokens, $methodIndex + 1);
+            if ($parenIndex === null || $tokens[$parenIndex]->text !== '(') {
+                continue;
+            }
+            $callKind = 'Inertia::render';
+            $openIndex = $parenIndex;
+        }
+
+        // ---- inertia( / \inertia( helper 検出 ----
+        $isHelperName = ($token->is(T_STRING) && $token->text === 'inertia')
+            || ($token->is(T_NAME_FULLY_QUALIFIED) && $token->text === '\\inertia');
+        if ($callKind === null && $isHelperName) {
+            $prevIndex = inertiaPrevSignificant($tokens, $i - 1);
+            if ($prevIndex !== null) {
+                $prev = $tokens[$prevIndex];
+                // メソッド呼び出し・定義・static 参照は対象外
+                if ($prev->is([T_OBJECT_OPERATOR, T_NULLSAFE_OBJECT_OPERATOR, T_DOUBLE_COLON, T_FUNCTION, T_NEW, T_CONST])) {
+                    continue;
+                }
+                // 直前が識別子付きの `\` なら namespace 参照 (Foo\inertia)
+                if ($prev->is(T_NS_SEPARATOR)) {
+                    $beforeNs = inertiaPrevSignificant($tokens, $prevIndex - 1);
+                    if ($beforeNs !== null && $tokens[$beforeNs]->is([T_STRING, T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED])) {
+                        continue;
+                    }
+                }
+            }
+            $parenIndex = inertiaNextSignificant($tokens, $i + 1);
+            if ($parenIndex === null || $tokens[$parenIndex]->text !== '(') {
+                continue;
+            }
+            $callKind = 'inertia';
+            $openIndex = $parenIndex;
+        }
+
+        if ($callKind === null || $openIndex === null) {
+            continue;
+        }
+
+        // ---- 第 1 引数の判定 ----
+        $argIndex = inertiaNextSignificant($tokens, $openIndex + 1);
+        if ($argIndex === null) {
+            continue;
+        }
+        $argToken = $tokens[$argIndex];
+
+        // 引数なし `inertia()` (= ResponseFactory 取得) はページ参照ではない
+        if ($argToken->text === ')') {
+            continue;
+        }
+
+        // named 引数 `component: '...'`
+        if ($argToken->is(T_STRING) && $argToken->text === 'component') {
+            $colonIndex = inertiaNextSignificant($tokens, $argIndex + 1);
+            if ($colonIndex !== null && $tokens[$colonIndex]->text === ':') {
+                $valueIndex = inertiaNextSignificant($tokens, $colonIndex + 1);
+                if ($valueIndex !== null) {
+                    $argIndex = $valueIndex;
+                    $argToken = $tokens[$valueIndex];
+                }
+            }
+        }
+
+        $literal = null;
+        if ($argToken->is(T_CONSTANT_ENCAPSED_STRING)) {
+            // literal 直後が `.` (連結) なら非 literal 扱い
+            $afterIndex = inertiaNextSignificant($tokens, $argIndex + 1);
+            $isConcatenated = $afterIndex !== null && $tokens[$afterIndex]->text === '.';
+            if (! $isConcatenated) {
+                $literal = inertiaLiteralValue($argToken->text);
+            }
+        }
+
+        if ($literal !== null) {
+            $literals[] = ['page' => $literal, 'location' => "{$relative}:{$token->line}"];
+
+            continue;
+        }
+
+        $dynamics[] = implode('::', [
+            $relative,
+            inertiaEnclosingFunction($tokens, $i),
+            $callKind,
+            inertiaNormalizeFirstArg($tokens, $argIndex),
+        ]);
+    }
+
+    return [
+        'literals' => $literals,
+        'dynamics' => $dynamics,
+        'routeInertia' => $routeInertia,
+        'nonCanonical' => $nonCanonical,
+    ];
+}
+
+/**
+ * app/ + routes/ 全体の収集結果。
+ *
+ * @return array{
+ *     literals: list<array{page: string, location: string}>,
+ *     dynamics: list<string>,
+ *     routeInertia: list<string>,
+ *     nonCanonical: list<string>,
+ * }
+ */
+function inertiaCollectAll(): array
+{
+    $result = ['literals' => [], 'dynamics' => [], 'routeInertia' => [], 'nonCanonical' => []];
+
+    foreach (inertiaScanTargets() as $target) {
+        $source = file_get_contents($target['absolute']);
+        if (! is_string($source)) {
+            continue;
+        }
+        $collected = inertiaCollectFromSource($source, $target['relative']);
+        $result['literals'] = array_merge($result['literals'], $collected['literals']);
+        $result['dynamics'] = array_merge($result['dynamics'], $collected['dynamics']);
+        $result['routeInertia'] = array_merge($result['routeInertia'], $collected['routeInertia']);
+        $result['nonCanonical'] = array_merge($result['nonCanonical'], $collected['nonCanonical']);
+    }
+
+    return $result;
+}
+
+test('Inertia render の literal 参照先ページが全て実在する', function (): void {
+    $refs = inertiaCollectAll();
+
+    // 走査自体が壊れて 0 件になる退行を検知する。
+    expect(count($refs['literals']))->toBeGreaterThan(0);
+
+    $missing = [];
+    foreach ($refs['literals'] as $ref) {
+        if (! is_file(inertiaPageComponentPath($ref['page']))) {
+            $missing[] = "{$ref['location']} → resources/js/pages/{$ref['page']}.svelte (不存在)";
+        }
+    }
+
+    expect($missing)->toBe([]);
+});
+
+test('非 literal のページ名は存在検査できないため allowlist 必須 (1 エントリ 1 呼び出し)', function (): void {
+    $refs = inertiaCollectAll();
+
+    $unlisted = array_values(array_diff($refs['dynamics'], INERTIA_DYNAMIC_ALLOWLIST));
+    expect($unlisted)->toBe([]);
+
+    // 同一キーへの複数マッチ (= 巻き込み許可) を禁止。
+    $counts = array_count_values($refs['dynamics']);
+    foreach (INERTIA_DYNAMIC_ALLOWLIST as $key) {
+        expect($counts[$key] ?? 0)->toBeLessThanOrEqual(1);
+    }
+});
+
+test('Route::inertia は本 gate の対象外になるため使用禁止', function (): void {
+    expect(inertiaCollectAll()['routeInertia'])->toBe([]);
+});
+
+test('Inertia facade の非正準形 (FQCN / alias import) は走査をすり抜けるため禁止', function (): void {
+    expect(inertiaCollectAll()['nonCanonical'])->toBe([]);
+});
+
+/*
+ * 負のコントロール: 実ファイルを書き換えず fixture ソースに対して gate が点灯することを確認する。
+ * 現時点で dangling は 0 件 (= 予防 gate) のため、ここが空振りでないことの唯一の担保になる。
+ */
+test('負のコントロール: 実在しないページ名の literal を検出する', function (): void {
+    $fixture = <<<'PHP'
+    <?php
+    use Inertia\Inertia;
+    class FixtureController {
+        public function index() {
+            return Inertia::render('Totally/Missing/Page', []);
+        }
+        public function named() {
+            return Inertia::render(component: 'Another/Missing/Page');
+        }
+    }
+    PHP;
+
+    $refs = inertiaCollectFromSource($fixture, 'fixture.php');
+    expect(array_column($refs['literals'], 'page'))->toBe(['Totally/Missing/Page', 'Another/Missing/Page']);
+
+    $missing = [];
+    foreach ($refs['literals'] as $ref) {
+        if (! is_file(inertiaPageComponentPath($ref['page']))) {
+            $missing[] = $ref['page'];
+        }
+    }
+    expect($missing)->toHaveCount(2);
+});
+
+test('正のコントロール: 実在するページ名の literal は検出しない', function (): void {
+    // 実在ページ (resources/js/pages/Dashboard.svelte) を参照する fixture。
+    $fixture = <<<'PHP'
+    <?php
+    use Inertia\Inertia;
+    class FixtureController {
+        public function index() {
+            return Inertia::render('Dashboard', []);
+        }
+    }
+    PHP;
+
+    $refs = inertiaCollectFromSource($fixture, 'fixture.php');
+    expect(array_column($refs['literals'], 'page'))->toBe(['Dashboard']);
+    expect(is_file(inertiaPageComponentPath('Dashboard')))->toBeTrue();
+});
+
+test('負のコントロール: 非 literal / Route::inertia / 非正準形を検出する', function (): void {
+    $dynamic = <<<'PHP'
+    <?php
+    use Inertia\Inertia;
+    class FixtureController {
+        public function show(string $page) {
+            return Inertia::render($page, []);
+        }
+        public function concat(string $suffix) {
+            return inertia('Prefix/'.$suffix);
+        }
+    }
+    PHP;
+    $refs = inertiaCollectFromSource($dynamic, 'fixture.php');
+    expect($refs['literals'])->toBe([]);
+    expect($refs['dynamics'])->toBe([
+        'fixture.php::show::Inertia::render::$page',
+        "fixture.php::concat::inertia::'Prefix/'.\$suffix",
+    ]);
+    // allowlist 未登録なので gate が点灯する。
+    expect(array_values(array_diff($refs['dynamics'], INERTIA_DYNAMIC_ALLOWLIST)))->toHaveCount(2);
+
+    $routeInertia = <<<'PHP'
+    <?php
+    use Illuminate\Support\Facades\Route;
+    Route::inertia('/static', 'Static/Page');
+    PHP;
+    expect(inertiaCollectFromSource($routeInertia, 'fixture.php')['routeInertia'])->toHaveCount(1);
+
+    $fqcn = <<<'PHP'
+    <?php
+    class FixtureController {
+        public function index() {
+            return \Inertia\Inertia::render('Dashboard', []);
+        }
+    }
+    PHP;
+    expect(inertiaCollectFromSource($fqcn, 'fixture.php')['nonCanonical'])->toHaveCount(1);
+
+    $alias = <<<'PHP'
+    <?php
+    use Inertia\Inertia as Ia;
+    class FixtureController {
+        public function index() {
+            return Ia::render('Dashboard', []);
+        }
+    }
+    PHP;
+    expect(inertiaCollectFromSource($alias, 'fixture.php')['nonCanonical'])->toHaveCount(1);
+});
diff --git a/tests/Architecture/PhpstanWrapperInvariantTest.php b/tests/Architecture/PhpstanWrapperInvariantTest.php
new file mode 100644
index 0000000..7b0f33e
--- /dev/null
+++ b/tests/Architecture/PhpstanWrapperInvariantTest.php
@@ -0,0 +1,134 @@
+<?php
+
+declare(strict_types=1);
+
+/*
+ * Architecture invariant: `composer phpstan` が `scripts/phpstan.sh` 経由で実行されること。
+ *
+ * 背景 (SoT = scripts/phpstan.sh のヘッダコメント + composer.json の phpstan script):
+ * この worktree は virtiofs マウント (OrbStack / Docker Desktop の host share) 上にあり、
+ * phpstan.phar を並列 worker が同時に open/mmap するとレースで "Cannot open phar archive" が
+ * 出て PHPStan が "Result is incomplete" で落ちることがある。scripts/phpstan.sh は phar を
+ * 実 fs (${TMPDIR:-/tmp}) に内容ハッシュ鍵で複製してから実行することでこれを回避する。
+ *
+ * composer.json の phpstan script を直 phar 呼び出し (vendor/bin/phpstan ... /
+ * php vendor/.../phpstan.phar ...) に戻すと virtiofs 並列クラッシュが再発するため、
+ * 「wrapper 経由」+「wrapper が実 fs 複製を exec する」の 2 点を不変条件として固定する。
+ *
+ * DB 不使用の静的検査 (既存 Architecture テストと同じ作法)。
+ */
+
+/**
+ * composer.json の phpstan script が wrapper 経由かを検査する (純関数 = 負のコントロール用)。
+ *
+ * @return list<string> 違反一覧 (空 = 合格)
+ */
+function phpstanComposerScriptViolations(string $composerJson): array
+{
+    $violations = [];
+
+    /** @var mixed $decoded */
+    $decoded = json_decode($composerJson, true);
+    if (! is_array($decoded)) {
+        return ['composer.json が JSON object として読めない'];
+    }
+
+    $scripts = $decoded['scripts'] ?? null;
+    if (! is_array($scripts) || ! isset($scripts['phpstan'])) {
+        return ['composer.json の scripts.phpstan が存在しない'];
+    }
+
+    /** @var mixed $phpstan */
+    $phpstan = $scripts['phpstan'];
+    $lines = is_array($phpstan) ? $phpstan : [$phpstan];
+    $joined = implode("\n", array_map(strval(...), $lines));
+
+    if (! str_contains($joined, 'scripts/phpstan.sh')) {
+        $violations[] = 'scripts.phpstan が scripts/phpstan.sh 経由でない (virtiofs 並列クラッシュが再発する)';
+    }
+    // 直 phar 呼び出しへの逆戻りを明示的に弾く。
+    if (preg_match('#(vendor/bin/phpstan|vendor/phpstan/phpstan/phpstan\.phar)#', $joined) === 1) {
+        $violations[] = 'scripts.phpstan が phar を直接呼んでいる (wrapper 経由に戻すこと)';
+    }
+
+    return $violations;
+}
+
+/**
+ * wrapper 本体が「実 fs へ複製した phar を実行する」形を保っているかを検査する (純関数)。
+ *
+ * @return list<string> 違反一覧 (空 = 合格)
+ */
+function phpstanWrapperSourceViolations(string $shellSource): array
+{
+    $violations = [];
+
+    if (preg_match('/TMPDIR:-\/tmp/', $shellSource) !== 1) {
+        $violations[] = 'wrapper が ${TMPDIR:-/tmp} (実 fs) の複製先を持たない';
+    }
+    if (preg_match('/^\s*cp\s/m', $shellSource) !== 1) {
+        $violations[] = 'wrapper が phar を複製 (cp) していない';
+    }
+    // 複製物ではなく元 phar ($SRC) をそのまま実行するのは回避策の無効化。
+    if (preg_match('/exec\s+php\s+"\$\{?SRC\}?"/', $shellSource) === 1) {
+        $violations[] = 'wrapper が複製せず元 phar ($SRC) を直接 exec している';
+    }
+    if (preg_match('/exec\s+php\s+"\$\{?CACHED\}?"/', $shellSource) !== 1) {
+        $violations[] = 'wrapper が複製済み phar ($CACHED) を exec していない';
+    }
+
+    return $violations;
+}
+
+test('scripts/phpstan.sh が存在し実行可能であること', function (): void {
+    $path = base_path('scripts/phpstan.sh');
+    expect(file_exists($path))->toBeTrue('scripts/phpstan.sh が見つからない');
+    expect(is_executable($path))->toBeTrue('scripts/phpstan.sh に実行権が無い');
+});
+
+test('composer.json の phpstan script が scripts/phpstan.sh 経由であること', function (): void {
+    $composerJson = file_get_contents(base_path('composer.json'));
+    expect($composerJson)->toBeString();
+    /** @var string $composerJson */
+    expect(phpstanComposerScriptViolations($composerJson))->toBe([]);
+});
+
+test('scripts/phpstan.sh が phar を実 fs へ複製してから実行すること', function (): void {
+    $source = file_get_contents(base_path('scripts/phpstan.sh'));
+    expect($source)->toBeString();
+    /** @var string $source */
+    expect(phpstanWrapperSourceViolations($source))->toBe([]);
+});
+
+/*
+ * 負のコントロール: gate が「壊れた状態」を実際に検出することを fixture で確認する
+ * (実ファイルは書き換えない)。空振り gate を green として扱わないため。
+ */
+test('負のコントロール: 直 phar 呼び出しへ戻した composer.json を検出する', function (): void {
+    $broken = json_encode([
+        'scripts' => ['phpstan' => ['vendor/bin/phpstan analyse --memory-limit=2G']],
+    ]);
+    expect($broken)->toBeString();
+    /** @var string $broken */
+    $violations = phpstanComposerScriptViolations($broken);
+    expect($violations)->not->toBe([]);
+    expect(implode("\n", $violations))->toContain('scripts/phpstan.sh');
+
+    $missing = json_encode(['scripts' => ['fix' => ['vendor/bin/pint']]]);
+    expect($missing)->toBeString();
+    /** @var string $missing */
+    expect(phpstanComposerScriptViolations($missing))->not->toBe([]);
+});
+
+test('負のコントロール: 複製せず元 phar を exec する wrapper を検出する', function (): void {
+    $broken = <<<'SH'
+    #!/usr/bin/env bash
+    set -euo pipefail
+    SRC="${PHPSTAN_PHAR:-vendor/phpstan/phpstan/phpstan.phar}"
+    exec php "$SRC" "$@"
+    SH;
+
+    $violations = phpstanWrapperSourceViolations($broken);
+    expect($violations)->not->toBe([]);
+    expect(implode("\n", $violations))->toContain('$SRC');
+});
diff --git a/tests/js/architecture/pages-path-case-invariant.test.ts b/tests/js/architecture/pages-path-case-invariant.test.ts
new file mode 100644
index 0000000..43d302c
--- /dev/null
+++ b/tests/js/architecture/pages-path-case-invariant.test.ts
@@ -0,0 +1,119 @@
+import { describe, it, expect } from "vitest";
+import fs from "node:fs/promises";
+import path from "node:path";
+import { execFileSync } from "node:child_process";
+import { fileURLToPath } from "node:url";
+
+/*
+ * pages-path-case-invariant — ページ参照 path の大文字/小文字を固定する。
+ *
+ * SoT = resources/js/inertia.ts の resolver 規約: import.meta.glob で "./pages" 配下の
+ * .svelte を集め、`./pages/${name}.svelte` というキーで引く。
+ * つまり pages ディレクトリは **小文字 `pages/` 固定**。大文字 `Pages/` を参照する
+ * import / glob / dynamic import は、case-insensitive な開発 FS では偶然動いても
+ * case-sensitive な CI / 本番コンテナで解決不能になり白画面/ビルド失敗になる。
+ *
+ * 実効性: 他アプリからのコード移植で `resources/js/Pages/` 参照が実際に混入したことがある。
+ *
+ * 検査対象:
+ *   A. resources/js 配下 (.ts / .svelte) の **path 文字列リテラル**中の大文字 `Pages/` セグメント。
+ *      静的 import・`import.meta.glob`・**dynamic import の文字列リテラル**を等しく拾う
+ *      (どれも「引用符で囲まれた path リテラル」として現れるため、単一の検出器で足りる)。
+ *   B. git tracked path に `resources/js/Pages/` で始まるものが無いこと
+ *      (case-insensitive FS の case-fold エイリアスを誤って git add した事故の検出)。
+ */
+
+const HERE = path.dirname(fileURLToPath(import.meta.url));
+const REPO_ROOT = path.resolve(HERE, "../../../");
+const RESOURCES_JS = path.join(REPO_ROOT, "resources/js");
+
+/**
+ * 引用符で囲まれた path リテラル中の大文字 `Pages/` セグメントを検出する。
+ * `./Pages/` `../Pages/` `/Pages/` `@/Pages/` `resources/js/Pages/` などを等しく拾う。
+ * 単語の一部 (例: `SubPages/`) は前方境界で除外する。
+ */
+const UPPERCASE_PAGES_REF = /(["'`])(?:Pages\/|[^"'`]*[./@]Pages\/)/;
+
+function findUppercasePagesRefs(source: string): string[] {
+    return source
+        .split(/\r?\n/)
+        .filter((line) => UPPERCASE_PAGES_REF.test(line))
+        .map((line) => line.trim());
+}
+
+async function sourceFiles(dir: string): Promise<string[]> {
+    const out: string[] = [];
+    for (const e of await fs.readdir(dir, { recursive: true, withFileTypes: true })) {
+        if (e.isFile() && /\.(svelte|ts)$/.test(e.name)) out.push(path.join(e.parentPath, e.name));
+    }
+    return out;
+}
+
+describe("architecture/pages-path-case-invariant", () => {
+    it("resources/js に大文字 Pages/ を参照する import / glob / dynamic import が存在しない", async () => {
+        const files = await sourceFiles(RESOURCES_JS);
+        const offenders: string[] = [];
+        for (const file of files) {
+            const hits = findUppercasePagesRefs(await fs.readFile(file, "utf8"));
+            for (const hit of hits) offenders.push(`${path.relative(REPO_ROOT, file)}: ${hit}`);
+        }
+        expect(
+            offenders.sort(), // 失敗メッセージを走査順の環境差で揺らさない
+            `大文字 'Pages/' path 参照を検出。resolver 規約は小文字 './pages/' 固定 ` +
+                `(resources/js/inertia.ts の import.meta.glob と一致させること): ${offenders.join(", ")}`,
+        ).toEqual([]);
+    });
+
+    it("git tracked path に大文字 resources/js/Pages/ で始まるものが存在しない", () => {
+        // architecture invariant: git 不在は環境不備。silent skip せず明瞭に fail させる。
+        let tracked: string;
+        try {
+            tracked = execFileSync("git", ["ls-files", "resources/js/"], {
+                cwd: REPO_ROOT,
+                encoding: "utf8",
+            });
+        } catch (e) {
+            throw new Error(
+                `git ls-files の実行に失敗 (git worktree 前提の architecture invariant): ${String(e)}`,
+            );
+        }
+        const offenders = tracked.split("\n").filter((p) => p.startsWith("resources/js/Pages/"));
+        expect(
+            offenders,
+            `大文字 'resources/js/Pages/' で始まる tracked file を検出。case-insensitive FS の ` +
+                `case-fold エイリアスを誤って git add したもの。小文字 'resources/js/pages/' に統一すること: ` +
+                `${offenders.join(", ")}`,
+        ).toEqual([]);
+    });
+
+    /*
+     * 負のコントロール: 検出器が実際に点灯することを fixture 文字列で確認する
+     * (実ファイルは書き換えない)。空振り gate を green として扱わないため。
+     */
+    it("負のコントロール: 静的 import / glob / dynamic import の大文字 Pages を検出する", () => {
+        const violations = [
+            `import Dashboard from "./Pages/Dashboard.svelte";`,
+            `import Foo from '@/Pages/Foo.svelte';`,
+            "const pages = import.meta.glob('./Pages/**/*.svelte');",
+            `const mod = await import("./Pages/Lazy.svelte");`,
+            "const mod = await import(`../Pages/Lazy.svelte`);",
+            `const entry = "resources/js/Pages/Dashboard.svelte";`,
+        ];
+        for (const line of violations) {
+            expect(findUppercasePagesRefs(line), line).toHaveLength(1);
+        }
+    });
+
+    it("正のコントロール: 小文字 pages / 無関係な Pages 語は検出しない", () => {
+        const allowed = [
+            `import Dashboard from "./pages/Dashboard.svelte";`,
+            "const pages = import.meta.glob('./pages/**/*.svelte');",
+            `const mod = await import("@/pages/Lazy.svelte");`,
+            "// Pages/ という語をコメントに書いても引用符外なら対象外",
+            `const label = "SubPages/foo";`, // 単語境界の無い Pages は誤検出しない
+        ];
+        for (const line of allowed) {
+            expect(findUppercasePagesRefs(line), line).toHaveLength(0);
+        }
+    });
+});
```
