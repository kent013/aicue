Round 2 のレビューを受けて概念設計を再改訂しました。
Critical (`{oauthSession}` の再分類) は実コードで裏を取り、指摘どおり **2 件目の未防御 500 経路**でした。
inventory は total inventory (4 分類・分類漏れ禁止) へ作り直し、P3 の検証も 2 層へ分離しました。
再レビューをお願いします。全体判定 (APPROVED / CHANGES_REQUESTED) を明示してください。

## 対応マトリクス (Round 2)

# 対応マトリクス: conceptual-review Round 2

## [Critical] 観点3 — `{oauthSession}` を「UUID PK」という理由だけで B 群へ除外したのは不整合

- **判断: 対応する（指摘は正しく、実バグを 1 件追加で掘り当てた）**
- **根拠**: 実コードで確認したところ、Codex の疑いはそのまま成立していた。

  | 確認事項 | 実測 |
  |---|---|
  | `OauthSession` の PK | `use HasUuids;` (`app/Models/OauthSession.php:47`)、`oauth_sessions` は `$table->uuid()` |
  | route 定義 | `DELETE /organizations/{organization:slug}/api-keys/sessions/{oauthSession}` (`routes/web.php:278`) |
  | `whereUuid` の適用 | **無し**。`routes/web.php` の `whereUuid` は L358 / L361 の `{notification}` **2 箇所のみ** |
  | custom binder | **無し**。`Route::bind` は `organization` の 1 件のみ (`AppServiceProvider.php:154`) |

  したがって `DELETE .../api-keys/sessions/abc` は `where id = 'abc'` を uuid 列へ投げ、
  pgsql 22P02 → **404 ではなく生 500**。**課題 1 と同一のバグクラスで、2 件目の未防御経路**。
  「UUID だから除外」は私の分類誤りで、正しくは「UUID は**別種の制約が要る**」だった。

- **対応内容**:
  1. `{oauthSession}` を **P1 の修正対象へ追加**した (whereUuid 相当の制約 + 404 Feature テスト)。
  2. inventory を「数値 allowlist + 除外リスト」から、Codex 提案の **4 分類**へ作り直した:
     `bigint` / `uuid` / `custom binder` / `非モデル文字列`。
  3. 概念設計の課題 1 の記述を「数値 PK が無防備」から
     **「型付き PK の route binding が系統的に無防備 (bigint と uuid の両方)」**へ改めた。
     施策名も `NumericRouteBindingConstraintTest` から
     **`RouteBindingTypeConstraintInventoryTest`** へ改名 (数値限定でなくなったため)。

## [Warning] 観点3 — inventory gate の成立方法が曖昧 (route 定義だけでは param の PK 型を判定できない)

- **判断: 対応する**
- **根拠**: 正当。「未知 param を数値と推測する」設計は原理的に成立しない。
  Codex の「**total inventory** にして分類漏れ自体を禁止する」提案が正しい。
  AI-CUE には既に同型の precedent がある (`NestedRouteIdorDefenseTest` の
  「inventory に登録必須」、`ScenarioWritePathInventoryTest` の「新経路は登録必須」)。
- **対応内容**: gate を **total inventory 方式**に再定義した。
  - 全 binding param を 4 分類のいずれかに登録することを必須とする。
  - **未登録の param が route に現れたら fail**。「数値と推測して制約を掛ける」ことはしない。
  - fail 時のメッセージで「型・解決方式・除外理由を登録せよ」と要求する。
  - 登録済み param については、分類に応じた制約 (bigint→数値 / uuid→UUID /
    custom binder→binder の入力正規化の存在) を検証する。
  - これにより param rename・新 route 追加・新モデル追加のいずれでも gate が落ちる。

## [Warning] 観点2 — P3 の `logout → browser back` は Feature テストでは検証できない

- **判断: 対応する**
- **根拠**: 全面的に正しい。Feature テストが見られるのは応答ヘッダまでで、
  ブラウザの bfcache 復元動作ではない。ここを一括で「テスト済み」と書くのは
  **禁止事項 #1 (テストなしの実装完了報告) の実質的な違反**にあたる。
- **対応内容**: P3 の検証を **2 層に明確分離**した。

  | 層 | 検証内容 | 手段 |
  |---|---|---|
  | Feature | 認証済み HTML/Inertia 応答の `Cache-Control` に `no-store` が付くこと / 既存 4 経路が untouched であること | Pest Feature テスト |
  | Browser (E2E) | `logout → back` で PII 画面が再表示されないこと | `scripts/run-browser-test.sh` (`docs/testing-browser.md`) または bug-hunt |

  成果指標も同じ 2 層に分けて書き直した (下記 Warning 観点4 と併せて対応)。

## [Warning] 観点4 — 「bfcache 再表示を常に保証する」は表現が強すぎる

- **判断: 対応する**
- **根拠**: 正当。`no-store` に対する bfcache の扱いはブラウザ実装依存
  (Firefox は格納自体を拒否、Chrome は cookie 変更時に CCNS ページを evict、
  **Safari は `no-store` でも格納しうる**)。HTTP ヘッダだけで全ブラウザの挙動は断定できない。
  aigenba の実装コメントも「Safari は本施策のスコープ外」と明記しており、
  私の概念設計はその但し書きを落としていた。
- **対応内容**: 成果指標を分離した。
  - (a) **保証できること**: 認証済み応答に再利用禁止ヘッダを付与する (機械的に検証可能)
  - (b) **確認すること**: サポート対象ブラウザの代表 E2E で再表示されない
  - (c) **限界として明記**: Safari の bfcache は `no-store` で抑止しきれない (スコープ外)

## [Warning] 観点5 — 「既に no-store を持つ応答は untouched」だけでは `public, no-store` のような矛盾値も温存する

- **判断: 対応する（ただしスコープは広げない）**
- **根拠**: 正当な指摘。ただし Codex 自身が「矛盾ヘッダの一般的な正規化まで今回のスコープへ
  広げる必要はない」と添えており、これに同意する (思考原則 #2「今必要なものだけ作る」)。
- **対応内容**:
  - baseline middleware の契約を **「既存ヘッダを上書きしない」と明示**した。
  - **既存 4 経路の期待値を個別 Feature テストで固定**することを施策に追加した
    (`FortifyServiceProvider:199` / `RequireRecentAuth:57` /
     `RequireTwoFactorForEnforcedOrganizations:93` / `CaptureTakeController:177`)。
    これにより矛盾値が現存しないことを実測で確認でき、将来混入したら落ちる。
  - 矛盾ヘッダの一般正規化は**スコープ外**として明記した。

## [Suggestion] 観点1 / 観点6 / 観点7 — 主便益の整理・トラック分割・型安全性方針は妥当

- **判断: 対応不要**（肯定的評価）

---

## 再改訂版 概念設計

# 概念設計: aigenba 整列 (決済ドメイン以外)

> 入力: `devnotes/20260802-1548-aigenba-alignment-audit/audit.md`
> 監査スナップショット: aigenba `63ad2aa05` / AI-CUE `6a43898`
> Codex レビュー Round 1 / Round 2 反映済み
> (`codex-history/conceptual-review-decisions-round-{1,2}.md`)

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

### 課題 1: 型付き PK の route-model binding が系統的に無防備 (生 500 経路)

AI-CUE は本バグクラス (**pgsql の型不一致で生 500**) を**既に 2 箇所で個別に認識・対処している**:

| 箇所 | 対処 |
|---|---|
| `{notification}` (`routes/web.php:358,361`) | `whereUuid`。コメントに「pgsql uuid 比較の 22P02 防止」と明記 (L350) |
| `{organization}` (`app/Http/Routing/MembershipScopedOrganizationBinder.php:106-131`) | `normalizeIntegerId()` が非数値・先頭ゼロ・bigint 範囲外を 404 に倒す。PHP 8.5 の範囲外文字列 cast 警告→500 まで手当て済み |

**しかし系統化されていない。** 残る bind param は Laravel 既定の implicit binding のまま無防備で、
`Route::pattern` による global 制約も無い (`grep -rn "Route::pattern" app/ bootstrap/ routes/` が 0 件)。

- `/projects/abc` → `where id = 'abc'` を **bigint** 列へ → 22P02 (`invalid input syntax for type bigint`)
- `DELETE /organizations/{slug}/api-keys/sessions/abc` → `where id = 'abc'` を **uuid** 列へ → 22P02

いずれも `QueryException` → **404 ではなく生 500**。

aigenba は同じ問題を bug-hunt (run 20260629-170143, G1-route-binding-500) で実際に踏み、
`NumericRouteBindingConstraintTest` で deny-by-default の gate を張っている。
ただし AI-CUE は **bigint と uuid の両方**に未防御があるため、gate も型を跨いで設計する
(施策名は **`RouteBindingTypeConstraintInventoryTest`**)。

#### bind param total inventory (実測。2026-08-03 時点)

**分類漏れを禁止する total inventory** とする (Round 2 対応)。
「未知 param を数値と推測する」ことはしない。全 21 param を 4 分類のいずれかへ登録する。

PK 型は `database/migrations/` の `Schema::create` と `app/Models/` の trait から実測した。

**分類 1: bigint PK — 数値制約が必要。現状すべて無防備**

| param | 出現数 (web/api) | テーブル |
|---|---|---|
| `{project}` | 45 / 5 | `projects` |
| `{manual}` | 26 / — | `video_manuals` |
| `{cut}` | 8 / — | `cuts` |
| `{user}` | 8 / — | `users` |
| `{take}` | 6 / — | `takes` |
| `{category}` | 4 / — | `categories` |
| `{apiKey}` | 4 / — | `api_keys` |
| `{renderJob}` | 3 / — | `render_jobs` |
| `{item}` | 3 / 2 | `items` |
| `{analysisJob}` | 2 / — | `analysis_jobs` |
| `{invitation}` | 2 / — | `organization_invitations` |

**分類 2: UUID PK — UUID 制約または安全な binder が必要**

| param | 状況 |
|---|---|
| `{notification}` | **対策済み**。`whereUuid('notification')` (`routes/web.php:358,361`) |
| **`{oauthSession}`** | **未対策 = P1 の修正対象**。`OauthSession` は `use HasUuids;` (`app/Models/OauthSession.php:47`) / `oauth_sessions` は `$table->uuid()`。route は `DELETE /organizations/{organization:slug}/api-keys/sessions/{oauthSession}` (`routes/web.php:278`) だが **`whereUuid` も custom binder も無い** (`Route::bind` は `organization` の 1 件のみ) |

**分類 3: custom binder — binder 内の入力正規化を gate で保証**

| param | 内容 |
|---|---|
| `{organization}` | `Route::bind('organization', MembershipScopedOrganizationBinder::class)` (`AppServiceProvider.php:154`)。binder の `normalizeIntegerId()` が非数値・先頭ゼロ・bigint 範囲外を 404 に倒す。**`{organization}` (L195,210) と `{organization:slug}` (L212〜234 他) を両方使う**ため、数値 pattern を掛けると **slug route が全滅する** → pattern 適用は禁止。gate は「binder が存在し入力正規化を持つこと」を検証する |

**分類 4: 非モデル文字列 — 型制約の対象外**

| param | 理由 |
|---|---|
| `{provider}` / `{intent}` | `/auth/{provider}/redirect/{intent}` (SocialAuth の enum 文字列) |
| `{userId}` | `/debug/login/{userId}` (非 production 限定の debug route) |
| `{resource}` / `{bucket}` / `{action}` / `{ability}` | `routes/api.php` の非モデル param (文字列キー) |

> 合計 11 + 2 + 1 + 7 = **21 param** で、`routes/web.php` + `routes/api.php` の全 binding param を網羅する。
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
| **P1** | 型付き PK route binding (bigint / uuid) の 404 化 + total inventory gate | **実バグ修正** |
| **P2** | bug-hunt adjudication registry の修復 + 運用ガードの固定 | **実バグ修正** |
| **P3** | 認証済みページの no-store baseline middleware | **セキュリティ** |
| **P4** | 汎用 Architecture / JS gate の移植 | 退行検出 |
| **P5** | bug-hunt 文書 + docs 運用整備 | 運用 |

### P1: 型付き PK route binding の生 500 防御

**成立条件**: global な `Route::pattern` 一律適用は**採らない** (Round 1 Critical)。
上記 total inventory の**分類ごとに要求する制約を変える**。

3 点セットで入れる。

1. **実挙動の是正**:
   - **分類 1 (bigint)** の 11 param に数値制約を付ける。
   - **分類 2 (uuid)** の未対策 `{oauthSession}` に UUID 制約を付ける。
   - いずれも非適合セグメントを **route 不一致 = 404** に落とす。
     既存の `{notification}` (`whereUuid`) と `{organization}` (binder の `normalizeIntegerId`) と
     同じ思想を、型を跨いで系統化する。
   - 適用手段 (param 単位の `Route::pattern` / route ごとの `whereNumber`・`whereUuid` / 共通 helper) は
     詳細設計で決めるが、**inventory を単一の source of truth に置く**ことは決定事項。
2. **total inventory gate** (`RouteBindingTypeConstraintInventoryTest`):
   - **全 binding param が 4 分類のいずれかに登録されていること**を要求する。
     **未登録 param が route に現れたら fail** し、「型・解決方式・除外理由を登録せよ」と促す。
     未知 param を数値と推測することはしない (Round 2 Warning への対応)。
   - 登録済み param は分類に応じた制約を検証する
     (bigint→数値制約 / uuid→UUID 制約 / custom binder→binder の入力正規化の存在 /
      非モデル文字列→検証しない)。
   - これにより param rename・新 route 追加・新モデル追加のいずれでも gate が落ちる。
   - 既存の `NestedRouteIdorDefenseTest` / `ScenarioWritePathInventoryTest` が同じ
     「inventory 登録必須」作法を採っており precedent がある。
3. **実挙動テスト**: 非適合セグメント → 404 (500 でないこと) を Feature テストで固定する
   (bigint 代表 = `/projects/abc`、uuid 代表 = `DELETE .../api-keys/sessions/abc`)。

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
- **契約: 既存ヘッダを上書きしない**。`no-store` を持たない応答にのみ付与する。
  既存 4 経路の期待値は**個別 Feature テストで固定**する
  (`FortifyServiceProvider:199` / `RequireRecentAuth:57` /
   `RequireTwoFactorForEnforcedOrganizations:93` / `CaptureTakeController:177`)。
  `public, no-store` のような**矛盾ヘッダの一般正規化はスコープ外** (思考原則 #2)。
  上記 4 経路に矛盾値が現存しないことは個別テストで実測し、将来の混入は同テストが検出する。
- header 付与判定は**小さな純粋メソッドに切り出す** (PHPStan level 10 を通しやすくするため)。

guest / 公開ページを対象外にすることで bfcache と共有キャッシュの恩恵を維持する。
認証済み画面は Inertia SPA でアプリ内の戻る/進むが client-side navigation のため UX 後退はない。

#### 検証は 2 層に分ける (Round 2 Warning への対応)

Feature テストで見られるのは**応答ヘッダまで**であり、ブラウザの bfcache 復元動作ではない。
ここを一括で「テスト済み」と書くのは禁止事項 #1 の実質的な違反になるため、責務を分離する。

| 層 | 検証内容 | 手段 |
|---|---|---|
| **Feature** | 認証済み HTML/Inertia 応答の `Cache-Control` に `no-store` が付くこと / 既存 4 経路が untouched であること / guest・公開ページが対象外であること | Pest Feature テスト |
| **Browser (E2E)** | `logout → back` で PII 画面が再表示されないこと / **capture 系代表画面でアプリ内遷移 UX が維持される**こと | `scripts/run-browser-test.sh` (`docs/testing-browser.md`) または bug-hunt |

#### 限界の明記 (過大申告しない)

`no-store` に対する bfcache の扱いは**ブラウザ実装依存**である。
Firefox は `no-store` で bfcache 格納自体を拒否し、Chrome は cookie 変更 (= ログアウト) 時に
CCNS ページを evict するが、**Safari は `no-store` でも格納しうるため本施策で抑止しきれない**。
これは aigenba の実装コメントでも「本施策のスコープ外」と明記されている前提であり、
AI-CUE でも同じ限界として記録する。

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
| **T-a** | P1 + P3 (ランタイム挙動の是正) | P1: 非適合セグメント → 404 の Feature テスト (bigint `/projects/abc` / uuid `DELETE .../sessions/abc` — 現状いずれも 500 で fail) / P1: total inventory gate (現状 未制約 param があり fail) / P3: 認証済み応答の `Cache-Control` Feature テスト (現状 no-store 無しで fail)。**bfcache 実挙動は Browser E2E 層で別途確認** |
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
| **P1** | (a) **分類漏れ param = 0** (total inventory の網羅) / (b) bigint・uuid の**未制約 binding param = 0** / (c) 非適合セグメント → 404 を固定する Feature テストの成立 (bigint / uuid の両代表) |
| **P2** | (a) **機構の再稼働**: `test_seed_registry_is_valid` が green / `--annotate` が exit 0 / stdin 経路が finding を落とさない (b) **運用知見の再蓄積**: 次回 bug-hunt run 以降、確定した偽陽性を登録手順に従って adjudication として積む (初期値 0 件は**想定どおり**) |
| **P3** | (a) **保証できること**: 認証済み応答に再利用禁止ヘッダが付与される / 既存 4 経路が untouched / guest・公開ページが対象外 (いずれも Feature テストで機械検証) (b) **確認すること**: サポート対象ブラウザの代表 E2E で `logout → back` の再表示が起きない (c) **限界**: Safari の bfcache は `no-store` で抑止しきれない (スコープ外として明記) |
| **P4** | 各 gate が「固定する事故 / 不変条件」(§P4 の表) を実際に検出すること (負のコントロールで確認)。本数は副次 |
| **P5** | AGENTS.md §worktree から runbook への参照が繋がり、`enableGlobalVirtualStore` の障害時に手順が引けること |

---

## 実装方針（概要）

| 群 | 主な変更対象 |
|---|---|
| P1 | `app/Providers/AppServiceProvider.php` または `routes/web.php` (適用手段は詳細設計)、`tests/Architecture/RouteBindingTypeConstraintInventoryTest.php` (新規)、`tests/Feature/Routing/` (新規) |
| P2 | `.claude/skills/app-bug-hunt/ledger/validate_findings.py` / `adjudications.jsonl` / `test_validate_findings.py` / `README.md`、`spec-ledger.md` (新規) |
| P3 | `app/Http/Middleware/NoStoreCacheHeadersForAuthenticatedPages.php` (新規)、`bootstrap/app.php`、`tests/Feature/` (新規)、Browser E2E (`tests/Browser/` 相当) |
| P4 | `tests/Architecture/*.php` (新規 6 本前後)、`tests/js/architecture/pages-path-case-invariant.test.ts` (新規) |
| P5 | `.claude/skills/app-bug-hunt/capability-catalog.md` (新規)、`docs/*.md` (新規 2 本)、`AGENTS.md` の該当節への参照追記 |

## 制約・前提

- **P1 は上記 total inventory を正本とする**。分類ごとに要求する制約が異なる
  (bigint→数値 / uuid→UUID / custom binder→binder の入力正規化 / 非モデル文字列→対象外)。
  特に `{organization}` は `{organization:slug}` を併用するため**数値制約を掛けてはならない**。
  `{oauthSession}` は UUID PK かつ未対策のため**修正対象に含める**。
- **P2 の registry 空 seed 化は「機能を消す」ように見えるが逆**。現状 fail-closed で
  18 件全てが無効なので、実効的な抑制件数は 0 → 0 で不変。壊れた台帳を除去して
  **機構を使える状態に戻す**のが目的。ただし §P2-4 の随伴要件を同一変更集合で満たすことが条件。
- **P3 は既存ヘッダを上書きしない契約**とし、既存 4 経路の期待値を個別 Feature テストで固定する。
  ストリーミング応答・署名 URL リダイレクトへの影響を response class 判定で除外する。
  検証は Feature (ヘッダ) と Browser E2E (bfcache 実挙動) の 2 層に分け、**過大申告しない**。
  Safari の bfcache は `no-store` で抑止しきれない点を限界として記録する。
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
