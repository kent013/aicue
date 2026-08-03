【アプリの使命 (North Star)】
**AI-CUE** は、現場に既にある**作業手順書(SOP)を起点に**、AI が撮るべきカットを設計した**動画シナリオ**を生成し、そのシナリオを**スマホ(PWA)でナビゲーション撮影**することで、専門知識ゼロの現場作業者でも**標準化されたマニュアル動画**を作れるようにする。

- 「思考ゼロ・編集ゼロ」— 台本作成・撮影判断・編集の 3 ハードルを AI とナビ撮影で肩代わりする。
- 競合(OJT を撮って形式化する tebiki)と異なり、**標準作業を起点に AI が教材設計し撮影を指示する**（撮影者・教える人のスキルに品質を依存させない）。
- 熟練者の暗黙知を動画マニュアルという形式知へ変換する装置（SECI）。

v1 スコープ: 字幕のみ(TTS 後回し) / 撮影は PWA(同一オリジン・セッション認証) / 動画合成は自前 ffmpeg / 単一 Default Project。

【禁止事項】
1. テストなしの実装完了報告(不変条件は対応する Architecture/Feature テストへの登録まで含めて「実装済み」)
2. PHPStan エラーの widen(型を緩めて黙らせる)・baseline 化
3. dev DB への破壊操作(`migrate:fresh` 等)をエージェント判断で実行すること
4. `response()->json()` の直書き(DTO / JsonResource / Inertia を使う。仕様固定 endpoint のみ例外)
5. LLM 呼び出しの Prism 直呼び(`app/Prompts/` の factory 経由のみ。PromptGuardrailTest が検出)
6. prompt 文字列のコード直書き(`resources/prompts/*.yaml` に置く)
7. 操作系 POST の応答での `redirect()->intended()`(ログイン直後フロー専用。招待送信等は `back()->with(...)` で完結させる)
8. 必須条件未充足を理由にボタンを disabled にする UI(押下時にエラー表示する。DESIGN.md)

【セキュリティ不変条件(アプリ都合で緩めない)】
1. tenant キー不信: ownership/actor/tenant キーを payload から受け取らない
2. 子は親に属する: nested route の不整合は認可より前に 404
3. cross-org 不可: 組織を跨ぐ read/write をしない
4. untrusted 文字列は UserInput 型経由でのみ prompt に入れる
5. 権限判定は常に `laratrust_team_id` を明示(strict_check=true)
6. PII(email/name)は CipherSweet。検索は `whereBlind()`
7. 課金の冪等性: webhook は冪等マシン経由、チケットは reserve→commit/release の 2 フェーズ
8. 外部 URL 取得は SSRF 検査経由

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

【レビュー観点】
1. 使命との整合性: この改善はアプリの使命（North Star）に本質的に貢献するか
2. 禁止事項違反: 上記禁止事項に抵触していないか
3. 実現可能性: 技術的に実現可能か（Laravel 12 + Svelte 5 + Inertia.js）
4. 期待効果の妥当性: 主張している効果は合理的に期待できるか
5. リスク: 重大な副作用・後退の可能性はないか
6. スコープの適切さ: 過大または過小になっていないか
7. **型安全性**: DTO/JsonResourceパターンに沿っているか。PHPStan level 10を通せるか

【出力形式】
- 全体判定: APPROVED / CHANGES_REQUESTED
- 各観点ごとに [Critical] [Warning] [Suggestion] で分類して指摘
- Critical/Warning には修正提案を必ず添える
- 日本語で出力

---

## 概念設計

# 概念設計: aigenba 整列 (決済ドメイン以外)

> 入力: `devnotes/20260802-1548-aigenba-alignment-audit/audit.md`
> 監査スナップショット: aigenba `63ad2aa05` / AI-CUE `6a43898`

## 背景・課題

AI-CUE と aigenba はいずれも laravel-claude-template 系譜の姉妹アプリで、
ユーザー方針は「**aigenba の実装と可能な限り揃える。乖離が起きるなら aigenba に取り込む**」。
決済ドメインは `devnotes/20260717-0035-aigenba-billing-parity/` の T072〜T081 で整列中だが、
**それ以外の全域は未整列**だった。全面監査の結果、以下が判明した。

### 実害が裏取りできた欠陥 (予防ではなく、現に壊れている)

**課題 1: 数値 PK の route-model binding が全面的に無防備 (生 500 経路)**

`projects` の PK は `$table->id()` = bigint (`database/migrations/2026_06_11_074000_create_organizations_tables.php:61`)。
`routes/web.php` には bind される `{param}` が約 120 箇所あるが、数値制約の適用は **0 件**、
`Route::pattern` による global 制約も **無し**。一方 `whereUuid` は `{notification}` の 3 箇所だけに
適用され、そこには「pgsql uuid 比較の 22P02 防止」と明記されている (`routes/web.php:350`)。

つまり **同一のバグクラス (pgsql の型不一致で生 500) を UUID 面でだけ認識・対処し、
数値 PK 面は素通し**になっている。`/projects/abc` は `where id = 'abc'` を bigint 列へ投げ、
pgsql 22P02 (`invalid input syntax for type bigint`) → `QueryException` → **404 ではなく生 500**。

aigenba は同じ問題を bug-hunt (run 20260629-170143, G1-route-binding-500) で実際に踏み、
`NumericRouteBindingConstraintTest` で deny-by-default の gate を張っている。

**課題 2: bug-hunt の adjudication registry が機能停止している**

repo 自身のテストが赤:

```
$ python3 -m unittest discover -s ledger -p 'test_*.py'   # .claude/skills/app-bug-hunt/
FAIL: test_seed_registry_is_valid — First list contains 5 additional elements.
```

`validate_findings.py` の registry 検証が 5 件のエラーを返す:

| 件 | 内容 |
|---|---|
| A-004〜A-007 | `species_key` が 3 セグメント (規約は `failure_class:resource_type:operation:tenant_relation` の 4 セグメント) |
| A-008 | `conditions.mode` を使うが `COND_KEYS` に `mode` が無い (= schema drift) |

`main()` は **all-or-nothing の fail-closed** (`registry = [] if adj_errors else adjs`) なので、
**18 件の adjudication が全て無効化され、cross-run 偽陽性抑制がゼロ + `--annotate` が毎回 exit 1**。

aigenba は同じ事故 (spirux HARNESS-01) を踏んで `COND_KEYS` に `mode` / `env` を
**governed key** として昇格させ、理由をコード内に固定している。**AI-CUE はこの教訓の伝播で最後尾**。

さらに registry のデータ自体が **spirux のもののまま**で、AI-CUE に実在しない資産を指す:
`.claude/skills/spirux-bug-hunt/operations.md` (A-012)、`/api/v1/personas/*` (A-005)、
`resources/js/Pages/` の大文字パス (A-004 他)、`app/Filament/` (A-018)。
`watch_globs` による invalidation も永久に発火しない = registry は AI-CUE に対して**空同然**。

**課題 3: 認証済みページに no-store の baseline が無い (bfcache 経由の PII 再表示)**

AI-CUE の `no-store` は 4 箇所の**点対応のみ** (`FortifyServiceProvider.php:199` /
`RequireRecentAuth.php:57` / `RequireTwoFactorForEnforcedOrganizations.php:93` /
`Capture/CaptureTakeController.php:177`)。認証済み Inertia ページ全体を覆う baseline が無い。

ログアウト後にブラウザの「戻る」で、メンバー一覧等の PII を含む認証済み画面が
bfcache から再表示されうる。aigenba は bug-hunt F-2-02 (run 20260704-000837) で実際に検出し、
**route 列挙ではなく「認証済みか」で判定する** middleware を導入している
(path 列挙が一般認証画面を取りこぼした T557 の再発防止という経緯まで含めて)。

### 退行検出 gate の欠落 (現時点で違反は無いが、守りが無い)

AI-CUE の Architecture テストは 33 本 / aigenba は 93 本。ドメイン固有を除いた**汎用 invariant** のうち、
AI-CUE に不在かつ AI-CUE でも成立するものが複数ある。特に:

- **`PhpstanWrapperInvariantTest`**: AI-CUE も `composer.json:108-110` で `bash scripts/phpstan.sh` を
  使っており、**同じ orbstack virtiofs `/workspace` 環境**で同じ回避策を必要としているのに、
  それが外れる退行を検出する gate だけが無い。
- **`BughuntOrchestratorGateInvariantTest`**: AGENTS.md §bug-hunt が `BUGHUNT_ORCHESTRATOR=1` の
  default-deny を「**非交渉**」と明記しているのに、**機械 gate が無い**。
  aigenba では 1 worker が共有 worktree を消して run を全損させた事故 (run 20260619-163010) の対策。
- **`InertiaRenderPageExistsInvariantTest`**: aigenba では `/mypage` の白画面が半年間未検知だった
  実バグ (F-01) の恒久解消。AI-CUE の literal 参照 39 件は現時点で dangling 0 件 = 予防。
- **`pages-path-case-invariant.test.ts`**: 大文字 `./Pages/` 参照の禁止。
  **課題 2 で spirux 由来の `Pages/` 参照が実際に混入していた**ことから、実効性がある。

### 運用文書の欠落

AGENTS.md §worktree が `enableGlobalVirtualStore` に依存しているのに、その運用 runbook が無い
(「`--config.ci=false --config.enableGlobalVirtualStore=true --config.nodeLinker=isolated` を付ける」
という**結論だけ**があり、なぜ・壊れたらどうするかが無い)。
bug-hunt 側にも、機械 registry (adjudication) に対する**人間可読の対**である申し送り台帳が無い。

## 改善アイデア

監査結果を 5 施策群に分ける。**実害の裏取りがある順**に並べる。

| 群 | 施策 | 性質 |
|---|---|---|
| **P1** | 数値 PK route binding の 404 化 + deny-by-default gate | **実バグ修正** |
| **P2** | bug-hunt adjudication registry の修復 (COND_KEYS / stdin 2-pass / registry 棚卸し) | **実バグ修正** |
| **P3** | 認証済みページの no-store baseline middleware | **セキュリティ** |
| **P4** | 汎用 Architecture / JS gate の移植 | 退行検出 |
| **P5** | bug-hunt 文書 + docs 運用整備 | 運用 |

### P1: 数値 PK route binding の生 500 防御

3 点セットで入れる (aigenba と同じ構成)。

1. **実挙動の是正**: 数値 PK を bind する全 route param に数値制約を付け、非数値セグメントを
   **route 不一致 = 404** に落とす。既存の `whereUuid` (`{notification}`) と同じ思想を数値面へ広げる。
   個別 `->whereNumber()` の列挙は約 120 箇所で漏れが必ず出るため、**`Route::pattern` による
   global 既定**を軸にし、例外 param のみ個別に上書きする方針を第一候補とする。
2. **gate**: `NumericRouteBindingConstraintTest` 相当を移植。
   「binding param ∈ 数値 PK param 集合を持つ全 route は数値制約を持つ」を deny-by-default で検証。
   未制約 param が増えたら落ちる = 新 route の追加時に必ず気づく。
3. **実挙動テスト**: 非数値セグメント → 404 を Feature テストで固定 (500 でないこと)。

### P2: bug-hunt adjudication registry の修復

1. **`COND_KEYS` に `mode` / `env` を governed key として追加**。
   aigenba と同じく「なぜ generic な `precondition` に潰さないのか」の理由をコードに固定する
   (fake 限定の偽陽性を real モードの実退行へ誤適用しないための load-bearing な条件だから)。
2. **stdin 2-pass の修正**。`analyze(path, text=None)` + 親で `sys.stdin.read()` をバッファ。
   現状 `--annotate` の stdin 経路は 2 回目の read が空になり、静かに「finding 0 件」を出す。
3. **registry データの棚卸し**。spirux 由来の 18 件は AI-CUE に実在しない資産を指しており、
   `watch_globs` invalidation も発火しない。**seed を空にする**のを第一候補とする
   (他アプリの偽陽性判定を AI-CUE の実退行へ誤適用するリスクを構造的に消すため)。
   併せて `species_key` 4 セグメント規約を満たさない A-004〜A-007 の問題も消える。
4. 上記により repo 自身の赤テスト `test_seed_registry_is_valid` が green に戻る。

### P3: 認証済みページの no-store baseline

aigenba の `NoStoreCacheHeadersForAuthenticatedPages` を移植する。要点は 2 つ:

- **適用判定は route 列挙ではなく「認証済みか」**。path 列挙は一般認証画面を必ず取りこぼす。
- **既存値を untouched で維持**。`no-store` directive を持たない応答にのみ `no-store, private` を set。
  既に持つ応答 (課題 3 に挙げた 4 箇所 / SSE 等) は触らない。
- **登録位置は `web` グループの末尾 (= 最内側)**。pipeline は「配列で前 = 外側 = ヘッダ後勝ち」なので、
  より厳格な既存 middleware が後勝ちで上書きできる状態を保つ。

guest / 公開ページ (login・LP・SEO) は対象外にして bfcache と共有キャッシュの恩恵を維持する。
認証済み画面は Inertia SPA でアプリ内の戻る/進むが client-side navigation のため UX 後退はない。

### P4: 汎用 gate の移植

| 資産 | AI-CUE 側の状況 |
|---|---|
| `PhpstanWrapperInvariantTest` | `scripts/phpstan.sh` は在るが gate が無い。**ほぼそのまま移植可** |
| `BughuntOrchestratorGateInvariantTest` | AGENTS.md が「非交渉」と書く規律に機械 gate が無い |
| `BugHuntInventoryCheckInvariantTest` | `scripts/bug-hunt-inventory-check.sh` は在るが exit code 規約の固定が無い |
| `BugHuntSkillInvariantTest` | 「finding は停止信号ではない」規約の保持 |
| `BughuntEnvExampleContractTest` | `.env.bughunt.local.example` は在るが contract の固定が無い |
| `InertiaRenderPageExistsInvariantTest` | 予防 gate (現時点 dangling 0 件) |
| `pages-path-case-invariant.test.ts` | 大文字パス参照の禁止 |
| `WorktreeRuleInvariantTest` | **verbatim 移植不可**。AI-CUE の worktree 規約は aigenba と異なる (`tasks/<id>` 階層・ブランチ削除責務) ため**検査項目の再設計**が要る |

### P5: 文書整備

| 資産 | 方針 |
|---|---|
| `.claude/skills/app-bug-hunt/spec-ledger.md` | **枠組みを移植し、中身は AI-CUE の実 run から書き起こす**。aigenba の項目は移さない |
| `capability-catalog.md` | 枠組みのみ移植。語彙は AI-CUE ドメイン (SOP / シナリオ / 撮影 / レンダ) で作る |
| `docs/pnpm-global-virtual-store-runbook.md` | AGENTS.md §worktree が依存する機構の背景・障害対応 |
| `docs/worktree-isolation-strategy.md` | 同上 |

## 期待効果

### 使命への貢献

使命は「専門知識ゼロの現場作業者でも標準化されたマニュアル動画を作れるようにする」。
本設計はドメイン機能を増やさないが、**その使命を支える土台**に効く:

- **P1** は現場作業者が URL を触り損ねた / 古いブックマークを開いた際に、
  意味不明な 500 ではなく **404 という理解可能な応答**を返す。「思考ゼロ」の裏返しである
  「詰まったときに何が起きたか分かる」を守る。
- **P3** は共用 PC・現場端末での利用を想定したとき、**ログアウト後に前の作業者の PII が
  見えない**ことを保証する。現場運用の前提条件。
- **P2 / P4** は bug-hunt (UX 破綻・詰み・IDOR を実ブラウザで発見する装置) の**検出能力そのもの**を
  回復・維持する。P2 は現に停止している機能の復旧。

### 具体的な改善見込み

- 約 120 の route bind param に対する生 500 経路の解消 (P1)
- repo 自身の赤テスト 1 件の解消 + bug-hunt 偽陽性抑制の復旧 (P2)
- 認証済み全画面への no-store baseline 適用 (P3)
- Architecture テスト 33 本 → 40 本前後、JS gate 8 本 → 9 本 (P4)

## 実装方針（概要）

| 群 | 主な変更対象 |
|---|---|
| P1 | `app/Providers/AppServiceProvider.php` (`Route::pattern`) または `routes/web.php`、`tests/Architecture/NumericRouteBindingConstraintTest.php` (新規)、`tests/Feature/Routing/` (新規) |
| P2 | `.claude/skills/app-bug-hunt/ledger/validate_findings.py`、`ledger/adjudications.jsonl`、`ledger/test_validate_findings.py`、`ledger/README.md` |
| P3 | `app/Http/Middleware/NoStoreCacheHeadersForAuthenticatedPages.php` (新規)、`bootstrap/app.php`、`tests/Feature/` (新規) |
| P4 | `tests/Architecture/*.php` (新規 6 本前後)、`tests/js/architecture/pages-path-case-invariant.test.ts` (新規) |
| P5 | `.claude/skills/app-bug-hunt/spec-ledger.md` / `capability-catalog.md` (新規)、`docs/*.md` (新規 2 本)、`AGENTS.md` の該当節への参照追記 |

## 制約・前提

- **P1 の第一候補 (`Route::pattern` global 既定) は要検証**。AI-CUE には
  `Route::bind('organization', MembershipScopedOrganizationBinder::class)` (`AppServiceProvider.php:154`)
  というカスタム binder があり、`{organization}` が数値 PK かどうか・slug bind かどうかで扱いが変わる。
  詳細設計で全 bind param の PK 型を確定させる。
- **P2 の registry 空 seed 化は「機能を消す」ように見えるが逆**。現状 fail-closed で
  18 件全てが無効なので、実効的な抑制件数は 0 → 0 で不変。壊れた台帳を除去して
  **機構を使える状態に戻す**のが目的。
- **P3 は既存 4 箇所の `no-store` と重複しない**こと (後勝ち順序) を設計で確認する。
  SSE / ストリーミング応答・署名 URL リダイレクトへの影響を検証する。
- **P4 の `WorktreeRuleInvariantTest` は verbatim 移植禁止**。AI-CUE の worktree 規約
  (AGENTS.md §worktree 運用ルール) に対する検査項目として作り直す。
- 全施策で AGENTS.md 禁止事項 #2 (テストなしの実装完了) を守る。
  P1/P3 は Feature テスト、P2 は `python3 -m unittest`、P4 は Architecture テスト自身が成果物。
- **後方互換の並走を残さない** (思考原則 #3)。P2 の registry 差し替えは同一 PR で旧データを消す。

## スコープ外

| 対象 | 理由 |
|---|---|
| 決済 parity T072〜T081 | 既存の `devnotes/20260717-0035-aigenba-billing-parity/` が正本。**独立トラック**で進行 |
| `AGENTS.md` の wholesale 移植 (aigenba 1269 行 / AI-CUE 193 行) | AI-CUE はテンプレート共通部を薄く保つ方針を明文化済み。選択的取り込みのみ |
| aigenba のドメイン機構 (Encounter / MCP / Filament / SharedResource / CLI) | ドメイン要件の差 |
| aigenba の `t63x-*` / `r1-`〜`r6-` JS 回帰テスト | aigenba の一過性リファクタに紐づく pin。AI-CUE に対応する変更が無い |
| ds-purity の層別分割 (`atoms-` / `molecules-` …) | 現行の単一 gate で不変条件自体は守れている。優先度低 |
| `.github/workflows/` | **完全一致**。差分なし |
| `config/` / `resources/js/components/` の整列 | ドメイン分岐が支配的。AI-CUE の atoms はむしろ充実 (`Toggle` / `TextLink` / `icons/` / `input-state.ts` は AI-CUE のみ) |
| **aigenba へ返す差分の実装** | 本設計は AI-CUE 側の変更のみ。返却分 (下記) は引き継ぎ文書の作成に留める |

### aigenba へ返す差分 (別途 handoff 文書)

「合わせる」は双方向であり、**AI-CUE 側が優位な箇所は aigenba へ返す**。

| # | 差分 | 提案理由 |
|---|---|---|
| F-1 | `scripts/bug-hunt-shard.sh` (AI-CUE 1982 行 / aigenba 1305 行) の `guard_shard_db_name` / `guard_bughunt_runtime` / `guard_admin_provision` の 3 段 DB guard、`secret_xtrace_off` / `secret_xtrace_restore` | 特に `secret_xtrace_off` は `set -x` 下で API key が漏れるのを防ぐ。安全性に直結 |
| F-2 | `coverage/correlate.py` のヘッダ列 index 動的決定 (5 列 / 6 列の両節対応) + backtick 剥がし | aigenba の operations.md が将来 6 列節を持つと誤 join する |
| F-3 | `scripts/audit-gate.test.ts` | supply-chain gate 自体は両者にあるが、gate のテストは AI-CUE のみ |
