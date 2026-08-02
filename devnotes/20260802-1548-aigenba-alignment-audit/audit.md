# aigenba ↔ AI-CUE 全面整列監査 (2026-08-02)

> **依頼**: 「/tmp/aigenba の実装を確認して、取り入れるべきものを取り入れる」
> **ユーザー方針**: 「基本的には、**合わせるのが重要**なので、しっかり見る」
> **進め方**: AGENTS.md 正規フロー (設計 → Codex レビュー → TODO 登録 → worktree 実装 → main マージ)
>
> 監査対象スナップショット: `/tmp/aigenba` @ `63ad2aa05` (main, clean) / AI-CUE @ `6a43898` (main)

既存の `devnotes/20260717-0035-aigenba-billing-parity/aigenba-divergence-ledger.md` は
**決済ドメイン限定**の乖離台帳。本書はそれを**決済以外の全域**へ広げた整列監査であり、
同台帳の後継ではなく**補完**である (決済分は引き続き同台帳が正本)。

---

## 0. 監査した軸と結論サマリ

| 軸 | 結論 |
|---|---|
| `.claude/skills/` | 9 スキル中 bug-hunt のみ実質差分。他 8 スキルは命名 (`app-` vs `aigenba-`) のみの差 |
| `.claude/agents/` | `bughunt-shard.md` に差 (aigenba 160 行 / AI-CUE 99 行)。他 2 件は aigenba ドメイン固有 |
| `scripts/` | **AI-CUE が優位**。`bug-hunt-shard.sh` は 1982 行 vs 1305 行 |
| `tests/Architecture/` | aigenba に**汎用 invariant が多数**。AI-CUE 33 本 / aigenba 93 本 |
| `tests/js/architecture/` | aigenba に**汎用 gate が多数**。AI-CUE 8 本 / aigenba 37 本 |
| `app/` 共通基盤 | **セキュリティ middleware に 1 件の実ギャップ** |
| `docs/` | 汎用 runbook / style guide が aigenba のみ |
| `.github/workflows/` | **完全一致**。取り入れ不要 |
| `config/` `resources/js/components/` | ドメイン分岐が支配的。整列対象ではない |
| `AGENTS.md` | aigenba 1269 行 / AI-CUE 193 行。**wholesale 移植は禁止** (下記 §5) |

---

## 1. 【最優先】実害が確認できた欠陥 — bug-hunt adjudication registry の機能停止

**AI-CUE の bug-hunt cross-run 偽陽性抑制は、現在まったく効いていない。**

### 1-1. 再現 (repo 自身のテストが赤)

```
$ cd .claude/skills/app-bug-hunt && python3 -m unittest discover -s ledger -p 'test_*.py'
FAIL: test_seed_registry_is_valid (test_validate_findings.AdjudicationBackwardCompatTest)
AssertionError: Lists differ: [(7, 'A-004', ...)] != []
First list contains 5 additional elements.
```

```
$ python3 ledger/validate_findings.py ledger/example.findings.jsonl \
    --adjudications ledger/adjudications.jsonl
  ADJ L7  [A-004]: bad species_key: 'misleading_copy:billing:starter-consent'
  ADJ L8  [A-005]: bad species_key: 'data_loss:api-rest:put-full-replace'
  ADJ L9  [A-006]: bad species_key: 'data_loss:api-rest:put-full-replace'
  ADJ L10 [A-007]: bad species_key: 'raw_error:admin-cli:retry-webhook'
  ADJ L11 [A-008]: bad condition key: 'mode'
  adjudications: 18  invalid: 5
```

### 1-2. なぜ「機能停止」なのか

`validate_findings.py:657-661` は **all-or-nothing の fail-closed** 設計:

```python
# fail-closed: registry に 1 件でも error があれば、壊れた台帳は一切信頼しない
registry = [] if adj_errors else adjs
```

同梱 registry が 5 件 error を持つため `registry = []` に落ち、
**18 件の adjudication すべてが無効化され、抑制ゼロ + exit 1** になる。
`--annotate` を使う運用経路は毎回 loud に失敗する。

### 1-3. 原因は 2 つ

| # | 原因 | aigenba 側の状態 |
|---|---|---|
| **1-A** | `COND_KEYS` に `mode` / `env` が無いのに、A-008 が `conditions.mode = "fake"` を使う (= schema drift) | **修正済み**。`COND_KEYS = {..., "mode", "env"}` として **governed key** に昇格し、理由をコメントで固定 |
| **1-B** | A-004〜A-007 の `species_key` が **3 セグメント** (規約は `failure_class:resource_type:operation:tenant_relation` の 4 セグメント) | aigenba の registry は 3 件すべて 4 セグメントで valid (`validate_adjudications` が空リストを返すことを確認済み) |

aigenba のコメント (`ledger/validate_findings.py`) が、まさにこの事故を教訓として記録している:

> `mode`/`env` は bug-hunt harness の第一級ディメンション (manifest.real_mode / 走行環境)。
> fake 限定の偽陽性を real モードの実退行に誤適用しないための load-bearing な条件なので、
> generic な precondition に潰さず governed key として持つ (spirux HARNESS-01 の教訓:
> 旧 COND_KEYS に mode/env が無く schema drift → fail-closed で抑制が全面停止した)。

**AI-CUE は HARNESS-01 と同じ事故を、まだ踏んだままである。**

### 1-4. 付随して見つかった第 2 の欠陥 — stdin 2-pass

`validate_findings.py` の `main()`:

```python
rep = analyze(args.path)                       # 1 回目の read
...
findings = [a for _, a, _ in load_jsonl(args.path) ...]   # 2 回目の read
```

`path == "-"` (stdin) のとき **2 回目は空**になる。`--annotate` は stdin を明示サポートして
いる (`help="findings.jsonl path, or - for stdin"`) ため、stdin 経路の annotate は静かに
「finding 0 件」を出す。aigenba は親でバッファして解決済み:

```python
stdin_text = sys.stdin.read() if args.path == "-" else None
rep = analyze(args.path, text=stdin_text)
...
findings = [... load_jsonl(args.path, text=stdin_text) ...]
```

### 1-5. 付随して見つかった第 3 の問題 — registry データが spirux のまま

AI-CUE の `ledger/adjudications.jsonl` A-001〜A-018 は、**AI-CUE に実在しない資産**を指している:

- A-012 の `rationale_ref` が `.claude/skills/**spirux**-bug-hunt/operations.md`
- A-005 / A-006 が `/api/v1/personas/*` `/api/v1/scenarios/*` (AI-CUE に persona API は無い)
- A-004 / A-008〜A-011 が `resources/js/**Pages**/Billing/Index.svelte` (AI-CUE は小文字 `pages/`)
- A-018 が `app/Filament/Resources/OrganizationResource.php` (AI-CUE に Filament admin は無い)

つまり `watch_globs` による invalidation (資産が変わったら再レビュー) も**永久に発火しない**。
registry は AI-CUE 実体に対して**空同然**であり、1-A/1-B を直しても中身は作り直しが要る。

> **判断**: 1-A / 1-B / 1-4 は機構の修正。1-5 は**データの棚卸し**。
> registry を「AI-CUE の実 run で確定した adjudication のみ」に作り直すのが正しく、
> spirux 由来の 18 件は**削除して seed を空にする**のが安全側 (誤って他アプリの
> 偽陽性判定を AI-CUE の実退行に適用するリスクを消す)。詳細設計で決める。

---

## 2. 【B: 取り入れる】汎用 Architecture テスト (PHP)

AI-CUE 33 本 / aigenba 93 本。**ドメイン固有 (Encounter/Scenario/MCP/Filament/Template) を除いた
汎用 invariant** のうち、AI-CUE に不在かつ AI-CUE でも成立するものを選定した。

| # | テスト | 何を守るか | AI-CUE 適用性 |
|---|---|---|---|
| B-1 | `InertiaRenderPageExistsInvariantTest` (415 行) | `Inertia::render()` / `inertia()` の literal 第 1 引数が `resources/js/pages/` に実在すること。違反は**本番白画面** | **適用可**。AI-CUE の literal 参照 39 件を手検証したところ **dangling は 0 件**。= 予防 gate として導入 (aigenba では `/mypage` が半年間未検知だった実バグ F-01 の恒久解消) |
| **B-2** | `NumericRouteBindingConstraintTest` (102 行) | 数値 PK の implicit route-model binding が非数値セグメントで pgsql 22P02 → **生 500** になる drift を deny-by-default 検出 | **適用可 + 現に無防備 (§2-1 参照)**。予防ではなく**実バグの是正**を伴う |
| B-3 | `PhpstanWrapperInvariantTest` (39 行) | `composer phpstan` が `scripts/phpstan.sh` 経由であること (virtiofs で phar 並列 open が死ぬ問題の回避が外れる退行を検出) | **適用可**。AI-CUE も `composer.json:108-110` で `bash scripts/phpstan.sh` を使っており、**まさに同じ環境 (orbstack virtiofs `/workspace`)**。gate だけが無い |
| B-4 | `WorktreeRuleInvariantTest` (193 行) | `setup/teardown-worktree.sh` + AGENTS.md §worktree + `.gitignore` の整合。文書とスクリプトの乖離を検出 | **適用可**だが**要書き換え**。AI-CUE の worktree 規約は aigenba と異なる (`.claude/worktrees/tasks/<id>` vs `.claude/worktrees/T<id>`、AI-CUE は teardown でブランチ残置しない等)。検査項目の**移植ではなく再設計**が要る |
| B-5 | `BugHuntInventoryCheckInvariantTest` (91 行) | `scripts/bug-hunt-inventory-check.sh` の exit code 規約 (0=一致 / 3=ドリフト) を fixture で固定 | **適用可**。AI-CUE も同スクリプトを持つ (AGENTS.md §bug-hunt に記載) |
| B-6 | `BughuntOrchestratorGateInvariantTest` (115 行) | orchestrator gate 2 層 (`bug-hunt-shard.sh` の env token default-deny + `bughunt-shard.md` の prose) の整合。**1 worker が共有 worktree を消して run を全損させた事故**の再発防止 | **適用可**。AI-CUE の AGENTS.md §bug-hunt が `BUGHUNT_ORCHESTRATOR=1` の default-deny を「非交渉」と明記しているのに、**機械 gate が無い** |
| B-7 | `BugHuntSkillInvariantTest` (152 行) | SKILL.md が「finding は停止信号ではない」規約を保持すること (Critical 1 件で打ち切る退行の防止) | **適用可** |
| B-8 | `BughuntEnvExampleContractTest` (30 行) | `.env.bughunt.local.example` の「production 同等性の最小セット」contract | **適用可**。AI-CUE も `.env.bughunt.local.example` を持つ |
| B-9 | `NoNonCompoundGlobalUseTest` (136 行) | global namespace のファイルで `use RuntimeException;` 等の非複合 use を禁止 (PHP の "has no effect" 警告) | 適用可 (効果は小。優先度低) |

### 2-1. B-2 の裏取り — AI-CUE は数値 PK route binding が**全面的に無防備**

| 確認事項 | 実測 |
|---|---|
| `projects` の PK | `$table->id()` = **bigint auto-increment** (`database/migrations/2026_06_11_074000_create_organizations_tables.php:61`) |
| `routes/web.php` の bind される `{param}` 出現数 | 約 120 (`{project}` 47 / `{manual}` 27 / `{cut}` 9 / `{user}` 8 / `{take}` 6 / `{category}` 4 / `{apiKey}` 4 / `{renderJob}` 3 / `{item}` 3 / `{analysisJob}` 2 …) |
| 数値制約 (`whereNumber` / `where('[0-9]+')`) の適用数 | **0** |
| `whereUuid` の適用数 | **3** (`{notification}` のみ。`routes/web.php:350` に「pgsql uuid 比較の 22P02 防止」と明記) |
| `Route::pattern` による global 制約 | **無し** (`grep -rn "Route::pattern" app/ bootstrap/ routes/` が 0 件) |
| 22P02 を意識した既存テスト | `tests/Feature/Notifications/NotificationCenterTest.php` **のみ** (= UUID 面だけ対処済み) |

**結論**: AI-CUE は「pgsql の型不一致で生 500 になる」という**同一のバグクラスを UUID 面でだけ
認識・対処しており、数値 PK 面は完全に素通し**になっている。
`/projects/abc` のような非数値セグメントは `where id = 'abc'` を bigint 列に投げ、
pgsql 22P02 (`invalid input syntax for type bigint`) → `QueryException` → **404 ではなく生 500**。

したがって B-2 は「aigenba にある gate を予防的に入れる」話ではなく、
**AI-CUE に現存する未防御の 500 経路を塞ぐ**話であり、§1 と並ぶ最優先項目。
移植物は (a) 実挙動を 404 にする route 制約、(b) その drift を deny-by-default で
検出する Architecture テスト、(c) 非数値→404 を固定する Feature テスト の 3 点セット。

> **注意**: `ModelDirectFetchInvariantTest` / `WebGuardLoginPathInvariantTest` /
> `WebhookAsyncDispatchInvariantTest` / `PolicyResolutionInvariantTest` は**思想は汎用だが
> inventory がドメイン固有**。AI-CUE には既に等価物がある (`NestedRouteIdorDefenseTest` /
> `ManageRouteAuthGuardTest` / `BillingSyncDispatchInvariantTest`)。**重複導入しない**。

---

## 3. 【B: 取り入れる】汎用 Architecture テスト (JS) と付随スクリプト

AI-CUE 8 本 / aigenba 37 本。aigenba 側は `t63x-*` `r1-`〜`r6-` 等の**一過性リファクタ回帰テスト**が
大半で、これらは移植対象外。汎用は以下:

| # | 資産 | 何を守るか | 備考 |
|---|---|---|---|
| C-1 | `pages-path-case-invariant.test.ts` | `./Pages/` のような**大文字パス参照**を禁止。case-sensitive CI で解決不能になる | AI-CUE は小文字 `pages/` 規約。**§1-5 で spirux 由来の `Pages/` 参照が実際に混入していた**ことからも、この gate は効く |
| C-2 | `comment-literal-purity.test.ts` + `scripts/check-comment-literal-purity.sh` | コメント/md 内の raw class literal を repo 全体 (vitest) と diff-scoped (sh) の 2 層で検出 | DS token 規約 (AI-CUE の `ds-purity.test.ts`) の**コメント面への拡張**。AI-CUE は本体のみ gate 済み |
| C-3 | `microcopy-style.test.ts` + `docs/copy-style-guide.md` | 文言スタイル (敬体/句読点/禁止語) の機械 gate | AI-CUE に文言規約が無い。**セットで導入しないと意味を持たない** |
| C-4 | ds-purity の**層別分割** (`atoms-` / `molecules-` / `features-` / `pages-` / `templates-`) | AI-CUE は単一 `ds-purity.test.ts` | 分割の利得は「どの層が違反したか」の即時特定。**優先度低**。今の単一 gate で不変条件自体は守れている |
| C-5 | `dynamic-page-titles.test.ts` / `title-via-page-title-helper.test.ts` | ページタイトルを helper 経由に強制 | AI-CUE は `resources/js/lib/document-title.ts` を持つ。gate の有無は詳細設計で確認 |
| C-6 | `seo-svelte-head-no-description.test.ts` | `<svelte:head>` に description を直書きさせない | AI-CUE は `docs/seo.md` を持つ。適用性は要確認 |

---

## 4. 【B: 取り入れる】app/ 共通基盤のセキュリティギャップ

| # | 資産 | 内容 | AI-CUE の状態 |
|---|---|---|---|
| **D-1** | `app/Http/Middleware/NoStoreCacheHeadersForAuthenticatedPages.php` | 認証済み web レスポンスに `no-store` を baseline 保証。**ログアウト後の「戻る」で認証済み画面 (メンバー一覧等の PII) が bfcache から再表示されるのを防ぐ**。適用判定を route 列挙ではなく「認証済みか」で行う (path 列挙が一般認証画面を取りこぼした T557 の再発防止) | **不在**。`grep -rl "no-store\|Cache-Control" app/Http/Middleware app/Providers` は `RequireTwoFactorForEnforcedOrganizations` / `RequireRecentAuth` / `FortifyServiceProvider` のみ = **個別 route の対処はあるが baseline が無い**。aigenba では bug-hunt F-2-02 として実際に検出された実バグ |
| D-2 | `NoStoreCacheHeadersForTwoFactor.php` | 2FA 画面専用の no-store | **AI-CUE は個別対処済み**。`FortifyServiceProvider.php:199` / `RequireRecentAuth.php:57` / `RequireTwoFactorForEnforcedOrganizations.php:93` / `Capture/CaptureTakeController.php:177` が各々 `no-store` を付与。**= 点の対処はあるが面の baseline が無い** (D-1 がまさにこの構図への対策)。D-2 単体は移植不要、D-1 導入時に既存 4 箇所と重複しないこと (後勝ち順序) を確認する |
| D-3 | `PathBasedThrottle.php` / `EmailChangeThrottle.php` | path 単位 throttle / メール変更 throttle | AI-CUE の throttle 構成を確認のうえ要否判断。**優先度低** |

> D-1 は AGENTS.md セキュリティ不変条件 **#6 (PII は CipherSweet)** と目的が地続き
> (保存時の PII 保護に対する**表示・キャッシュ面**の保護)。取り入れ推奨度は本監査中で
> §1 に次いで高い。

---

## 5. 【取り入れる (要 AI-CUE 化)】docs / bug-hunt 文書

| # | 資産 | 行数 | 判断 |
|---|---|---|---|
| E-1 | `.claude/skills/*/spec-ledger.md` | 273 | **取り入れる**。「過去 run で SPEC/DOC と確定した事象を再起票しない」申し送り台帳。adjudication registry (機械) に対する**人間可読の対** 。中身は AI-CUE の実 run から書き起こす (aigenba の項目は移植しない) |
| E-2 | `.claude/skills/*/capability-catalog.md` | 80 | **取り入れる**。`findings.jsonl` の `capability_tag` 語彙の正本。**枠組みのみ移植**し語彙は AI-CUE ドメイン (SOP / シナリオ / 撮影 / レンダ) で作る |
| E-3 | `.claude/skills/*/coverage-audit.md` | 96 | **取り入れる**。route 全面監査の結果を次回監査の起点として残す形式。AI-CUE では未実施なので**空の枠組み + 初回監査**が要る |
| E-4 | `docs/copy-style-guide.md` | 65 | **取り入れる** (C-3 とセット)。単独導入は無意味 |
| E-5 | `docs/pnpm-global-virtual-store-runbook.md` | 348 | **取り入れる**。AI-CUE の AGENTS.md §worktree が `enableGlobalVirtualStore` に**依存している**のに、その運用 runbook が無い (「`--config.ci=false --config.enableGlobalVirtualStore=true --config.nodeLinker=isolated` を付ける」という結論だけがあり、なぜ・壊れたらどうするかが無い) |
| E-6 | `docs/worktree-isolation-strategy.md` | 319 | **取り入れる**。同上。AGENTS.md §worktree の背景設計 |
| E-7 | `docs/aigenba-spirux-divergence.md` | 233 | **取り入れない (参考のみ)**。aigenba↔spirux の台帳。ただし**書式は本監査の後継台帳の手本にする** |
| E-8 | `docs/design-decisions.md` / `template-authoring.md` / `docs/operations/*` / `docs/help/*` | — | **取り入れない**。aigenba ドメイン / 運用固有 |

---

## 6. 【取り入れない】AI-CUE 側が優位 — aigenba へ返す候補

「合わせる」は双方向。以下は**逆に aigenba へ返すべき**差分。

| # | 資産 | AI-CUE | aigenba | 提案 |
|---|---|---|---|---|
| F-1 | `scripts/bug-hunt-shard.sh` | **1982 行**。`guard_shard_db_name` / `guard_bughunt_runtime` / `guard_admin_provision` の 3 段 guard、`ensure_fresh_assets` / `compute_build_fingerprint` の asset 鮮度検査、`start/stop_shard_workers` の worker 管理、`assert_llm_key_present` / `prepare_mode_and_preflight`、`secret_xtrace_off/restore` (秘密の xtrace 漏れ防止) | 1305 行。相当機構が無い | **返す**。特に `secret_xtrace_off` (`set -x` 下で API key が漏れる) と 3 段 DB guard は安全性に直結 |
| F-2 | `coverage/correlate.py` | ヘッダ行から name/story/区分 列 index を**動的決定** (5 列/6 列の両節に対応)、backtick 剥がし | 列位置固定 | **返す**。aigenba の operations.md が将来 6 列節を持つと誤 join する |
| F-3 | `scripts/audit-gate.test.ts` | あり | **無し** | **返す**。supply-chain gate 自体は両者にあるが、gate のテストは AI-CUE のみ |
| F-4 | `scripts/ci/pgsql_test_conn.php` | あり | 無し | 要確認 (AI-CUE 固有の可能性) |
| F-5 | `.claude/skills/app-bug-hunt/stories/README.md` | あり | 無し | 軽微 |

> 加えて、§1 で確認した `COND_KEYS` の教訓は **spirux → aigenba → AI-CUE** と伝播しており、
> AI-CUE が最後尾。既存の `aigenba-divergence-ledger.md` と同じ運用 (カテゴリ B は返す) を
> 本監査の対象範囲にも適用し、F-1〜F-3 は aigenba へ引き継ぎを出す。

---

## 7. 【取り入れない】理由付き

| # | 対象 | 理由 |
|---|---|---|
| G-1 | `AGENTS.md` の wholesale 移植 (1269 行 → 193 行) | AI-CUE の AGENTS.md は**テンプレート共通部を薄く保つ**方針が明文化されている (§ドメイン固有規約 の TEMPLATE-MARKER: 「テンプレート共通部は、テンプレート更新の取り込みを容易にするため、できるだけ書き換えない」)。aigenba の規約は**アプリ固有節へ選択的に**取り込む。ただし下記 G-1a は例外候補 |
| G-1a | AGENTS.md「worktree でのコマンド実行: CWD を毎回明示する」 | **取り入れ候補**。汎用の運用規律 (前コマンドの `cd` を前提にしない)。本セッションでも `cd /tmp/aigenba && ...` の後に cwd が戻る挙動を踏んでおり、実効性がある |
| G-2 | `.github/workflows/` | **完全一致**。差分なし |
| G-3 | `config/` `resources/js/components/atoms|molecules` | ドメイン分岐が支配的。AI-CUE の atoms はむしろ充実 (`Toggle` / `TextLink` / `icons/` / `input-state.ts` / `Spinner` / `Card` / `FormError` は AI-CUE のみ) |
| G-4 | aigenba の `t63x-*` / `r1-`〜`r6-` JS 回帰テスト | aigenba の一過性リファクタに紐づく回帰 pin。AI-CUE に対応する変更が無い |
| G-5 | `app/` のドメイン機構 (Encounter / Scenario / MCP / Filament / SharedResource / CLI) | ドメイン要件の差 |
| G-6 | `.claude/skills/aigenba-bug-hunt/SKILL.md` の全面移植 (778 行 vs 362 行) | 大半が aigenba 固有 (実 LLM モード / コスト集計 / 7 shard 構成)。AI-CUE は 8 shard 構成で `bug-hunt-shard.sh` 側が既に上位互換。**SKILL.md の構成だけ**を参考にする |

---

## 8. 承認済み 4 スコープへの割り付け

| ユーザー選択スコープ | 本監査での該当 | 推定規模 |
|---|---|---|
| **bug-hunt ハーネス修正 (実害あり)** | §1 (1-A / 1-B / 1-4 / 1-5) | 小〜中。ただし 1-5 (registry 棚卸し) は判断を要する |
| **汎用 Architecture テスト移植** | §2 (B-1〜B-9) + §3 (C-1〜C-6) | 中〜大。B-4 は再設計、C-3 は文書とセット |
| **bug-hunt 文書 + docs 移植** | §5 (E-1〜E-6) | 中。E-1/E-2/E-3 は「枠組み移植 + 中身は AI-CUE で作成」 |
| **決済 parity T075 (P4) 継続** | 既存 `devnotes/20260717-0035-aigenba-billing-parity/` (本監査の対象外) | 大 (Critical) |

**加えて本監査で新たに見つかった、選択スコープに明示されていない項目**:

- §4 D-1 (`NoStoreCacheHeadersForAuthenticatedPages`) — **セキュリティ実ギャップ**。
  「汎用 Architecture テスト移植」にも「文書移植」にも属さないため、**別途 TODO 化を要判断**。
- §6 F-1〜F-3 — **aigenba へ返す**分。実装ではなく引き継ぎ文書の作成。

### 優先度 (実害の裏取り有無で並べる)

| 順 | 項目 | 実害 | 裏取り |
|---|---|---|---|
| 1 | §2-1 **B-2 数値 PK route binding の生 500** | **現存する 500 経路**。約 120 の bind param が無制約 | 実測 (PK 型 / whereUuid 3 件のみ / Route::pattern 0 件) |
| 2 | §1 **adjudication registry の機能停止** | bug-hunt の偽陽性抑制が全面停止 + `--annotate` が毎回 exit 1 | repo 自身のテストが赤 (`test_seed_registry_is_valid`) |
| 3 | §4 **D-1 認証済みページの bfcache PII 再表示** | ログアウト後「戻る」で PII 画面が再表示されうる | baseline 不在を実測 (点の対処 4 箇所のみ) |
| 4 | §2 B-3/B-5〜B-8, §3 C-1 | 退行検出 gate の欠落 (現時点で違反は無い) | 各 gate の対象機構が AI-CUE に実在することを確認済み |
| 5 | §5 E-1〜E-6, §2 B-1/B-4/B-9, §3 C-2〜C-6 | 予防・運用整備 | — |

---

## 9. 次アクション (AGENTS.md 正規フロー)

1. 本監査を入力として `app-design` スキルで**概念設計** → Codex レビュー → **詳細設計** → Codex レビュー
2. `app-todo-add` で TODO 登録 (フェーズ分割: §1 / §2+§3 / §5 / §4-D1)
3. `scripts/setup-worktree.sh <id>` で worktree 実装 → テスト green → main マージ
4. T075 (P4) は既存設計・既存 worktree があるため独立トラックで進行
5. §6 の aigenba 引き継ぎ文書を `aigenba-handoff` として作成
