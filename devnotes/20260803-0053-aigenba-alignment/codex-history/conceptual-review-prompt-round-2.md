Round 1 のレビューを受けて概念設計を改訂しました。対応マトリクスと改訂版を提示します。
Critical 2 件は両方とも「対応する」で処理し、特に P1 は実コードで inventory を実測して確定させました。
再レビューをお願いします。全体判定 (APPROVED / CHANGES_REQUESTED) を明示してください。

## 対応マトリクス (Round 1)

# 対応マトリクス: conceptual-review Round 1

## [Critical] 観点3 — P1 の `Route::pattern` global 既定は成立条件が不足 (同名 param が数値 PK 以外の解決規約を持つ場合に破壊的)

- **判断: 対応する**（指摘は正しく、しかも実コードで裏が取れた）
- **根拠**: 概念設計後に bind param の inventory を実測したところ、Codex の懸念は**具体的に成立していた**。
  - `{organization}` は `Route::bind('organization', MembershipScopedOrganizationBinder::class)`
    (`app/Providers/AppServiceProvider.php:154`) の custom binder。しかも
    `routes/web.php` は `{organization}` と **`{organization:slug}` を両方使う**
    (L195/210 が id、L212〜234 他が slug)。数値 pattern を global に掛けると **slug route が全滅**する。
  - さらに重要な発見: この binder は**既に同じバグクラスを潰している**
    (`normalizeIntegerId()` が非数値・先頭ゼロ・bigint 範囲外を 404 に倒す。
     PHP 8.5 の範囲外文字列 cast 警告→500 まで手当て済み)。
    つまり AI-CUE は本バグクラスを `{organization}` と `{notification}` (whereUuid) の
    **2 箇所で個別に認識しているが、系統化されていない**。これは概念設計の主張を
    弱めるどころか、「deny-by-default の inventory gate が要る」という結論を補強する。
- **対応内容**: 概念設計に **bind param inventory (実測値)** を追加し、
  「数値 PK allowlist を先に確定 → その集合にのみ制約を適用 → custom binder / UUID / 非モデル param は
  明示除外」を成立条件として明記した。global `Route::pattern` を第一候補とする記述は撤回し、
  **allowlist 駆動**へ改めた。除外理由も param ごとに書いた。

## [Critical] 観点5 — P2 の「空 seed 化」は運用ガードが弱い (再び schema drift を起こしやすい)

- **判断: 対応する**
- **根拠**: 正当。現状 fail-closed で実効抑制は 0 なので空 seed 化自体に機能後退は無いが、
  「壊れた台帳を消しただけ」では**同じ事故 (spirux HARNESS-01 → aigenba → AI-CUE) の 4 度目**を招く。
  aigenba が `COND_KEYS` にコメントで理由を固定したのは、まさにこの再発防止のため。
- **対応内容**: P2 に「空 seed 化を採る条件」として、同一変更集合で
  (a) `species_key` 4 セグメント規約、(b) governed `COND_KEYS` (`mode`/`env` を含む理由)、
  (c) 新規 adjudication の登録手順、(d) spirux 由来 18 件の**削除理由**
  を `ledger/README.md` と `spec-ledger.md` に固定することを必須要件として追記した。
  P5 にあった spec-ledger 整備のうち、この 4 点は **P2 に前倒し**する
  (P5 送りにすると「機械台帳が空のまま受け皿が無い」期間ができるため)。

## [Warning] 観点1 — P2 の期待効果が強すぎる (回復するのは機構であって知見ではない)

- **判断: 対応する**
- **根拠**: 正確な指摘。空 seed 化直後に戻るのは「registry 検証と annotate 経路の再稼働」だけ。
- **対応内容**: 期待効果を **「機構の再稼働」と「運用知見の再蓄積」に分離**して書き直した。
  再蓄積側は「次回 bug-hunt run から adjudication を登録していく」と手順を明示した。

## [Warning] 観点2 / 観点6 — P1〜P5 一括は fail-first の責務が曖昧 / スコープが広すぎる

- **判断: 対応する**
- **根拠**: 妥当。実バグ修正・セキュリティ・gate 移植・文書整備を同一変更集合に載せると
  「先に落ちるテストを確認してから実装」(思考原則 #5 テストファースト) の確認単位がぼやける。
- **対応内容**: 概念設計に**段階リリース**節を追加し、`P1+P3` / `P2` / `P4+P5` の 3 トラックに分割。
  各トラックの「先に落ちることを確認するテスト」を明記した。TODO 登録も同じ粒度で分ける。
  ※ Codex 提案の並びをそのまま採用（P1 と P3 はどちらもランタイム挙動の是正で、
    Feature テストで fail-first を確認できる点が共通）。

## [Warning] 観点3 — P3 middleware が StreamedResponse / BinaryFileResponse / 署名 URL redirect を巻き込む

- **判断: 対応する**
- **根拠**: 正当。AI-CUE には実際に該当がある
  (`Capture/CaptureTakeController.php:177` が署名 URL への 302 に `no-store, private` を付与)。
  こちらは既存ヘッダありなので untouched で済むが、**ヘッダ未設定のストリーミング応答**は巻き込む。
- **対応内容**: 適用条件を **`response class` と `既存 Cache-Control の有無` の両面**で明文化した。
  除外: `StreamedResponse` / `BinaryFileResponse` / 既に `no-store` を持つ応答。
  対象: 通常の HTML / Inertia 応答で `Cache-Control` に `no-store` directive を持たないもの。

## [Warning] 観点4 — P1 の効果指標「約120 param」は粗い

- **判断: 対応する**
- **根拠**: 正当。param 出現数と到達可能な障害経路は別物 (同一 param が複数 route に出る)。
- **対応内容**: 成果指標を **「数値 PK binding route の未制約数 = 0」** と
  **「非数値セグメント → 404 を固定する Feature テストの成立」** に置き換えた。
  出現数 (実測 web 約120 + api 7) は**規模感の参考値**として残すが、指標からは外した。

## [Warning] 観点4 — P4 の「33本→40本前後」は本数指標に寄りすぎ

- **判断: 対応する**
- **根拠**: 正当。「本数が増えても load-bearing invariant を外すと価値がない」はそのとおり。
- **対応内容**: P4 の表に **「どの事故 / 不変条件を固定するか」列**を主指標として追加し、
  本数は副次指標に降格した。

## [Warning] 観点5 — P4 の gate 移植は aigenba 起源の前提を持ち込み brittle になる

- **判断: 対応する**
- **根拠**: 正当。特に `WorktreeRuleInvariantTest` は AI-CUE と aigenba で worktree 規約が違う
  (`.claude/worktrees/tasks/<id>` vs `T<id>`、ブランチ削除責務)。既に概念設計で verbatim 禁止と
  書いていたが、原則として全 gate に広げるべき。
- **対応内容**: P4 に横断原則として
  **「各 invariant の source of truth は AI-CUE の `AGENTS.md` / `docs` / 実スクリプトに置き、
  aigenba の文言・path を比較対象にしない」**を明記した。

## [Warning] 観点5 — P3 の bfcache 抑止が撮影フローに影響しないか

- **判断: 対応する**
- **根拠**: 妥当な確認要求。AI-CUE の中核は撮影 PWA なので、ここが壊れると使命に直撃する。
- **対応内容**: P3 のテスト観点に「capture 系代表画面でアプリ内遷移 (Inertia client-side
  navigation) の UX が維持される」ことの確認を追加した。

## [Warning] 観点7 — P1 の param 集合を ad-hoc な文字列配列で持つと rename に弱い

- **判断: 対応する**
- **根拠**: 正当。PHPStan level 10 は文字列配列の中身までは守れない。
- **対応内容**: **Architecture テストが「route 定義」と「inventory」を突合する構成**を採る
  (Codex 提案の後者)。inventory を単一の定数に置き、route 側に未知の数値 PK param が現れたら
  gate が落ちる = 文字列散在を避けつつ rename も検出できる。
  既存の `NestedRouteIdorDefenseTest` / `ScenarioWritePathInventoryTest` が同じ
  「inventory 登録必須」作法を採っており、precedent がある。

## [Suggestion] 観点1 — 冒頭で「現場利用時の詰まりにくさ」「共用端末での安全性」を先に出す

- **判断: 対応する**（低コストで読み手の優先順位理解が上がる）
- **対応内容**: 「期待効果 > 使命への貢献」を冒頭寄りに再構成した。

## [Suggestion] 観点4 — P3 の代表テストを `logout → back → 再表示されない` に据える

- **判断: 対応する**
- **対応内容**: P3 の代表シナリオとして明記した。

## [Suggestion] 観点7 — P3 middleware は Response 型を明示し判定を純粋メソッドに分ける

- **判断: 対応する**（詳細設計で反映）
- **対応内容**: 概念設計に「header 付与判定を小さな純粋メソッドに切り出す」と方針として記載。

## [Suggestion] 観点2 / 観点6 — 禁止事項違反なし / handoff 分離は適切

- **判断: 対応不要**（肯定的評価）

---

## 改訂版 概念設計

# 概念設計: aigenba 整列 (決済ドメイン以外)

> 入力: `devnotes/20260802-1548-aigenba-alignment-audit/audit.md`
> 監査スナップショット: aigenba `63ad2aa05` / AI-CUE `6a43898`
> Round 1 Codex レビュー反映済み (`codex-history/conceptual-review-decisions-round-1.md`)

## 主便益 (先に結論)

本設計はドメイン機能を増やさない。**使命を支える土台**の 2 点に効く。

1. **現場利用時の詰まりにくさ** — 現場作業者が URL を触り損ねた / 古いブックマークを開いたとき、
   意味不明な 500 ではなく **404 という理解可能な応答**を返す (P1)。
   「思考ゼロ」の裏返しである「詰まったときに何が起きたか分かる」を守る。
2. **共用端末での安全性** — 現場の共用 PC・端末で、**ログアウト後に前の作業者の PII が
   見えない**ことを保証する (P3)。現場運用の前提条件。

残る P2 / P4 / P5 は、この 2 点を**継続的に守る装置**(bug-hunt と退行検出 gate) の
回復・維持であり、優先順位はこの順に従う。

---

## 背景・課題

AI-CUE と aigenba はいずれも laravel-claude-template 系譜の姉妹アプリで、
ユーザー方針は「**aigenba の実装と可能な限り揃える。乖離が起きるなら aigenba に取り込む**」。
決済ドメインは `devnotes/20260717-0035-aigenba-billing-parity/` の T072〜T081 で整列中だが、
**それ以外の全域は未整列**だった。全面監査の結果、以下が判明した。

### 課題 1: 数値 PK の route-model binding が系統的に無防備 (生 500 経路)

AI-CUE は本バグクラス (**pgsql の型不一致で生 500**) を**既に 2 箇所で個別に認識・対処している**:

| 箇所 | 対処 |
|---|---|
| `{notification}` (`routes/web.php:358,361`) | `whereUuid`。コメントに「pgsql uuid 比較の 22P02 防止」と明記 (L350) |
| `{organization}` (`app/Http/Routing/MembershipScopedOrganizationBinder.php:106-131`) | `normalizeIntegerId()` が非数値・先頭ゼロ・bigint 範囲外を 404 に倒す。PHP 8.5 の範囲外文字列 cast 警告→500 まで手当て済み |

**しかし系統化されていない。** 残る数値 PK bind param は Laravel 既定の implicit binding のまま無防備で、
`Route::pattern` による global 制約も無い (`grep -rn "Route::pattern" app/ bootstrap/ routes/` が 0 件)。

`/projects/abc` は `where id = 'abc'` を bigint 列へ投げ、
pgsql 22P02 (`invalid input syntax for type bigint`) → `QueryException` → **404 ではなく生 500**。

aigenba は同じ問題を bug-hunt (run 20260629-170143, G1-route-binding-500) で実際に踏み、
`NumericRouteBindingConstraintTest` で deny-by-default の gate を張っている。

#### bind param inventory (実測。2026-08-03 時点)

PK 型は `database/migrations/` の `Schema::create` から実測した。

**A. 数値 PK (bigint) で制約が必要 — 現状すべて無防備**

| param | 出現数 (web/api) | テーブル | PK |
|---|---|---|---|
| `{project}` | 45 / 5 | `projects` | `$table->id()` |
| `{manual}` | 26 / — | `video_manuals` | `$table->id()` |
| `{cut}` | 8 / — | `cuts` | `$table->id()` |
| `{user}` | 8 / — | `users` | `$table->id()` |
| `{take}` | 6 / — | `takes` | `$table->id()` |
| `{category}` | 4 / — | `categories` | `$table->id()` |
| `{apiKey}` | 4 / — | `api_keys` | `$table->id()` |
| `{renderJob}` | 3 / — | `render_jobs` | `$table->id()` |
| `{item}` | 3 / 2 | `items` | `$table->id()` |
| `{analysisJob}` | 2 / — | `analysis_jobs` | `$table->id()` |
| `{invitation}` | 2 / — | `organization_invitations` | `$table->id()` |

**B. 明示除外 — 理由付き**

| param | 除外理由 |
|---|---|
| `{organization}` | **custom binder** (`Route::bind`, `AppServiceProvider.php:154`)。かつ `routes/web.php` は `{organization}` (L195,210) と **`{organization:slug}` (L212〜234 他) を両方使う**。数値 pattern を掛けると **slug route が全滅する**。既に binder 内で 404 化済み |
| `{notification}` | UUID PK (`$table->uuid()`)。既に `whereUuid` 適用済み |
| `{oauthSession}` | UUID PK (`$table->uuid()`) |
| `{provider}` / `{intent}` | モデル bind ではない (`/auth/{provider}/redirect/{intent}`、SocialAuth の enum 文字列) |
| `{userId}` | モデル bind ではない (`/debug/login/{userId}`、非 production 限定の debug route) |
| `{resource}` / `{bucket}` / `{action}` / `{ability}` | `routes/api.php` の非モデル param (文字列キー) |

> 出現数の合計 (web 約 120 + api 7) は**規模感の参考値**であり、成果指標ではない (下記 §期待効果)。

### 課題 2: bug-hunt の adjudication registry が機能停止している

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

### 課題 3: 認証済みページに no-store の baseline が無い (bfcache 経由の PII 再表示)

AI-CUE の `no-store` は 4 箇所の**点対応のみ** (`FortifyServiceProvider.php:199` /
`RequireRecentAuth.php:57` / `RequireTwoFactorForEnforcedOrganizations.php:93` /
`Capture/CaptureTakeController.php:177`)。認証済み Inertia ページ全体を覆う baseline が無い。

ログアウト後にブラウザの「戻る」で、メンバー一覧等の PII を含む認証済み画面が
bfcache から再表示されうる。aigenba は bug-hunt F-2-02 (run 20260704-000837) で実際に検出し、
**route 列挙ではなく「認証済みか」で判定する** middleware を導入している
(path 列挙が一般認証画面を取りこぼした T557 の再発防止という経緯まで含めて)。

### 課題 4: 退行検出 gate の欠落 (現時点で違反は無いが、守りが無い)

AI-CUE の Architecture テストは 33 本 / aigenba は 93 本。特に:

- **`PhpstanWrapperInvariantTest`**: AI-CUE も `composer.json:108-110` で `bash scripts/phpstan.sh` を
  使っており、**同じ orbstack virtiofs `/workspace` 環境**で同じ回避策を必要としているのに、
  それが外れる退行を検出する gate だけが無い。
- **`BughuntOrchestratorGateInvariantTest`**: AGENTS.md §bug-hunt が `BUGHUNT_ORCHESTRATOR=1` の
  default-deny を「**非交渉**」と明記しているのに、**機械 gate が無い**。

### 課題 5: 運用文書の欠落

AGENTS.md §worktree が `enableGlobalVirtualStore` に依存しているのに、その運用 runbook が無い
(「`--config.ci=false --config.enableGlobalVirtualStore=true --config.nodeLinker=isolated` を付ける」
という**結論だけ**があり、なぜ・壊れたらどうするかが無い)。
bug-hunt 側にも、機械 registry (adjudication) に対する**人間可読の対**である申し送り台帳が無い。

---

## 改善アイデア

監査結果を 5 施策群に分け、**3 トラックで段階リリース**する (§段階リリース)。

| 群 | 施策 | 性質 |
|---|---|---|
| **P1** | 数値 PK route binding の 404 化 + deny-by-default inventory gate | **実バグ修正** |
| **P2** | bug-hunt adjudication registry の修復 + 運用ガードの固定 | **実バグ修正** |
| **P3** | 認証済みページの no-store baseline middleware | **セキュリティ** |
| **P4** | 汎用 Architecture / JS gate の移植 | 退行検出 |
| **P5** | bug-hunt 文書 + docs 運用整備 | 運用 |

### P1: 数値 PK route binding の生 500 防御

**成立条件 (Round 1 Critical への対応)**: global な `Route::pattern` 一律適用は**採らない**。
上記 inventory の **A 群 (数値 PK) だけを allowlist として明示し、B 群は理由付きで除外する**。

3 点セットで入れる。

1. **実挙動の是正**: A 群の param に数値制約を付け、非数値セグメントを
   **route 不一致 = 404** に落とす。既存の `{notification}` (`whereUuid`) と
   `{organization}` (binder の `normalizeIntegerId`) と同じ思想を A 群へ広げる。
   適用手段 (param 単位の `Route::pattern` / route ごとの `whereNumber` / 共通 helper) は
   詳細設計で決めるが、**inventory を単一の source of truth に置く**ことは決定事項。
2. **inventory gate**: `NumericRouteBindingConstraintTest` 相当を移植。
   「A 群 param を binding に持つ全 route は数値制約を持つ」を deny-by-default で検証する。
   **route 定義と inventory を突合する構成**にして、param rename や新 route 追加で落ちるようにする
   (文字列配列を各所に散らさない。Round 1 観点7 への対応)。
   既存の `NestedRouteIdorDefenseTest` / `ScenarioWritePathInventoryTest` が同じ
   「inventory 登録必須」作法を採っており precedent がある。
3. **実挙動テスト**: 非数値セグメント → 404 (500 でないこと) を Feature テストで固定する。

### P2: bug-hunt adjudication registry の修復

1. **`COND_KEYS` に `mode` / `env` を governed key として追加**。
   aigenba と同じく「なぜ generic な `precondition` に潰さないのか」の理由をコードに固定する
   (fake 限定の偽陽性を real モードの実退行へ誤適用しないための load-bearing な条件だから)。
2. **stdin 2-pass の修正**。`analyze(path, text=None)` + 親で `sys.stdin.read()` をバッファ。
   現状 `--annotate` の stdin 経路は 2 回目の read が空になり、静かに「finding 0 件」を出す。
3. **registry データの棚卸し**。spirux 由来の 18 件は AI-CUE に実在しない資産を指しており、
   `watch_globs` invalidation も発火しない。**seed を空にする**。
4. **空 seed 化の必須随伴要件 (Round 1 Critical への対応)**。
   「壊れた台帳を消しただけ」で終わらせると、同じ事故 (spirux → aigenba → AI-CUE) の 4 度目を招く。
   **同一変更集合**で以下を `ledger/README.md` および `spec-ledger.md` に固定する:
   - (a) `species_key` の 4 セグメント規約 (`failure_class:resource_type:operation:tenant_relation`)
   - (b) governed `COND_KEYS` の一覧と、`mode` / `env` を含める理由
   - (c) **新規 adjudication の登録手順** (どの run で・何を根拠に・`watch_globs` に何を書くか)
   - (d) spirux 由来 18 件の**削除理由**
   > 元は P5 に置いていた spec-ledger 整備のうち、この 4 点は **P2 へ前倒しする**。
   > P5 送りにすると「機械台帳が空のまま受け皿が無い」期間ができるため。
5. 上記により repo 自身の赤テスト `test_seed_registry_is_valid` が green に戻る。

### P3: 認証済みページの no-store baseline

aigenba の `NoStoreCacheHeadersForAuthenticatedPages` を移植する。

**適用条件 (Round 1 Warning への対応)** — `response class` と `既存ヘッダ` の両面で判定する:

| | 条件 |
|---|---|
| **対象** | 認証済み (`Auth::guard('web')->check()`) かつ、通常の HTML / Inertia 応答で `Cache-Control` に `no-store` directive を持たないもの |
| **除外** | `StreamedResponse` / `BinaryFileResponse` (ストリーミング・ファイル配信)、既に `no-store` を持つ応答 (既存 4 箇所 / SSE)、guest / 公開ページ (login・LP・SEO) |

- **適用判定は route 列挙ではなく「認証済みか」**。path 列挙は一般認証画面を必ず取りこぼす
  (aigenba T557 の実績)。
- **登録位置は `web` グループの末尾 (= 最内側)**。pipeline は「配列で前 = 外側 = ヘッダ後勝ち」なので、
  より厳格な既存 middleware が後勝ちで上書きできる状態を保つ。
- header 付与判定は**小さな純粋メソッドに切り出す** (PHPStan level 10 を通しやすくするため)。

guest / 公開ページを対象外にすることで bfcache と共有キャッシュの恩恵を維持する。
認証済み画面は Inertia SPA でアプリ内の戻る/進むが client-side navigation のため UX 後退はない。

### P4: 汎用 gate の移植

**横断原則 (Round 1 Warning への対応)**: 各 invariant の source of truth は
**AI-CUE の `AGENTS.md` / `docs` / 実スクリプト**に置く。aigenba の文言・path を比較対象にしない。

| 資産 | **固定する事故 / 不変条件 (主指標)** |
|---|---|
| `PhpstanWrapperInvariantTest` | orbstack virtiofs で phar 並列 open が死ぬ回避策 (`scripts/phpstan.sh` 経由) が外れる退行 |
| `BughuntOrchestratorGateInvariantTest` | AGENTS.md が「非交渉」と書く `BUGHUNT_ORCHESTRATOR=1` default-deny の 2 層 gate 崩れ (worker の自走復旧が共有 worktree を消す事故) |
| `BugHuntInventoryCheckInvariantTest` | `scripts/bug-hunt-inventory-check.sh` の exit code 規約 (0=一致 / 3=ドリフト) 崩れ |
| `BugHuntSkillInvariantTest` | 「finding は停止信号ではない」規約の喪失 (Critical 1 件で探索を打ち切る退行) |
| `BughuntEnvExampleContractTest` | `.env.bughunt.local.example` の production 同等性最小セット欠落 |
| `InertiaRenderPageExistsInvariantTest` | `Inertia::render` の literal 参照先ページ不在 → **本番白画面** (現時点 dangling 0 件 = 予防) |
| `pages-path-case-invariant.test.ts` | 大文字 `./Pages/` 参照 → case-sensitive CI で解決不能 (**課題 2 で実際に混入していた**) |
| `WorktreeRuleInvariantTest` 相当 | AI-CUE の worktree 規約 (`.claude/worktrees/tasks/<id>`・ブランチ削除責務) と実スクリプトの乖離。**verbatim 移植禁止。検査項目を再設計する** |

> テスト本数 (33 → 40 本前後) は**副次指標**。

### P5: 文書整備

| 資産 | 方針 |
|---|---|
| `.claude/skills/app-bug-hunt/spec-ledger.md` | **枠組みを移植し、中身は AI-CUE の実 run から書き起こす**。aigenba の項目は移さない。※ P2 の随伴要件 (a)〜(d) は P2 で先に入る |
| `capability-catalog.md` | 枠組みのみ移植。語彙は AI-CUE ドメイン (SOP / シナリオ / 撮影 / レンダ) で作る |
| `docs/pnpm-global-virtual-store-runbook.md` | AGENTS.md §worktree が依存する機構の背景・障害対応 |
| `docs/worktree-isolation-strategy.md` | 同上 |

---

## 段階リリース (Round 1 Warning への対応)

1 本の「整列」テーマに全部載せると fail-first の確認単位がぼやけるため、**3 トラック**に分ける。
各トラックで「先に落ちることを確認するテスト」を固定してから実装に入る (思考原則 #5)。

| トラック | 内容 | **先に落ちることを確認するテスト** |
|---|---|---|
| **T-a** | P1 + P3 (ランタイム挙動の是正) | P1: 非数値セグメント → 404 の Feature テスト (現状 500 で fail) / P1: inventory gate (現状 未制約 param があり fail) / P3: `logout → back → 認証済み画面が再表示されない` の Feature テスト |
| **T-b** | P2 (bug-hunt ハーネス) | `python3 -m unittest` の `test_seed_registry_is_valid` (**現状すでに赤**) + stdin `--annotate` の 2-pass 回帰テスト (新規、現状 fail) |
| **T-c** | P4 + P5 (gate 移植・文書) | 各 gate 自身が成果物。移植前に「AI-CUE で意図どおり fail する負のコントロール」を確認する |

---

## 期待効果

### 使命への貢献

冒頭 §主便益 のとおり。P1 = 詰まりにくさ、P3 = 共用端末での安全性が主便益で、
P2 / P4 / P5 はそれを継続的に守る装置の回復・維持。

### 成果指標

| 群 | **指標** |
|---|---|
| **P1** | (a) 数値 PK binding route の**未制約数 = 0** / (b) 非数値セグメント → 404 を固定する Feature テストの成立 |
| **P2** | (a) **機構の再稼働**: `test_seed_registry_is_valid` が green / `--annotate` が exit 0 / stdin 経路が finding を落とさない (b) **運用知見の再蓄積**: 次回 bug-hunt run 以降、確定した偽陽性を登録手順に従って adjudication として積む (初期値 0 件は**想定どおり**) |
| **P3** | `logout → browser back → 認証済み画面が再表示されない` シナリオの成立。既存 4 箇所の `no-store` が untouched であること |
| **P4** | 各 gate が「固定する事故 / 不変条件」(§P4 の表) を実際に検出すること (負のコントロールで確認)。本数は副次 |
| **P5** | AGENTS.md §worktree から runbook への参照が繋がり、`enableGlobalVirtualStore` の障害時に手順が引けること |

---

## 実装方針（概要）

| 群 | 主な変更対象 |
|---|---|
| P1 | `app/Providers/AppServiceProvider.php` または `routes/web.php` (適用手段は詳細設計)、`tests/Architecture/NumericRouteBindingConstraintTest.php` (新規)、`tests/Feature/Routing/` (新規) |
| P2 | `.claude/skills/app-bug-hunt/ledger/validate_findings.py` / `adjudications.jsonl` / `test_validate_findings.py` / `README.md`、`spec-ledger.md` (新規) |
| P3 | `app/Http/Middleware/NoStoreCacheHeadersForAuthenticatedPages.php` (新規)、`bootstrap/app.php`、`tests/Feature/` (新規) |
| P4 | `tests/Architecture/*.php` (新規 6 本前後)、`tests/js/architecture/pages-path-case-invariant.test.ts` (新規) |
| P5 | `.claude/skills/app-bug-hunt/capability-catalog.md` (新規)、`docs/*.md` (新規 2 本)、`AGENTS.md` の該当節への参照追記 |

## 制約・前提

- **P1 の allowlist は上記 inventory を正本とする**。B 群 (custom binder / UUID / 非モデル param) は
  **明示除外**。特に `{organization}` は `{organization:slug}` を併用するため数値制約を掛けてはならない。
- **P2 の registry 空 seed 化は「機能を消す」ように見えるが逆**。現状 fail-closed で
  18 件全てが無効なので、実効的な抑制件数は 0 → 0 で不変。壊れた台帳を除去して
  **機構を使える状態に戻す**のが目的。ただし §P2-4 の随伴要件を同一変更集合で満たすことが条件。
- **P3 は既存 4 箇所の `no-store` と重複しない**こと (後勝ち順序) を設計で確認する。
  ストリーミング応答・署名 URL リダイレクトへの影響を response class 判定で除外する。
  **capture 系代表画面でアプリ内遷移 UX が維持される**ことをテスト観点に含める。
- **P4 は verbatim 移植禁止**。各 invariant の SoT は AI-CUE 側に置く。
- 全施策で AGENTS.md 禁止事項 #1 (テストなしの実装完了) を守る。
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
