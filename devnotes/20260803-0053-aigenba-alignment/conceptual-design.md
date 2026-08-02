# 概念設計: aigenba 整列 (決済ドメイン以外)

> 入力: `devnotes/20260802-1548-aigenba-alignment-audit/audit.md`
> 監査スナップショット: aigenba `63ad2aa05` / AI-CUE `6a43898`
> Codex レビュー Round 1〜5 反映済み (**Round 5 で APPROVED**)
> (`codex-history/conceptual-review-decisions-round-{1..5}.md`)

## 主便益 (先に結論)

本設計はドメイン機能を増やさない。**使命を支える土台**の 2 点に効く。

1. **現場利用時の詰まりにくさ** — 現場作業者が URL を触り損ねた / 古いブックマークを開いたとき、
   意味不明な 500 ではなく **404 という理解可能な応答**を返す (P1)。
   「思考ゼロ」の裏返しである「詰まったときに何が起きたか分かる」を守る。
2. **共用端末での安全性** — 現場の共用 PC・スマホで、**ログアウト後に前の作業者の PII が
   再表示されない**ようにする (P3)。現場運用の前提条件。
   保証範囲は**サポート対象ブラウザ**に限る (方針は現状未文書化のため P3-c で明文化する)。

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
| **P3** | ログアウト後の認証済み画面の再表示防止 (no-store baseline + bfcache 復元検知) | **セキュリティ** |
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

### P3: ログアウト後の認証済み画面の再表示防止

**サーバ (P3-a) とクライアント (P3-b) の 2 コンポーネント構成**にする。
`no-store` だけでは AI-CUE の**主要プラットフォームである iOS Safari を覆えない**ため
(理由は §P3-b)、片方だけでは主便益が達成されない。

#### P3-a: `no-store` baseline middleware (aigenba 整列分)

aigenba の `NoStoreCacheHeadersForAuthenticatedPages` を移植する。

**契約 (Round 3 で一意に確定)** — 判定キーは `Cache-Control` の存在ではなく **`no-store` directive の有無**:

| 応答の状態 | 挙動 |
|---|---|
| `Cache-Control` に **`no-store` を持つ** | **untouched**。内側で明示されたより厳格な値を尊重し、directive が縮む方向の上書きをしない (既存 4 経路 / SSE) |
| `no-store` を**持たない** | `Cache-Control` を **`no-store, private` で置換**する |

> 置換方式のため `public` / `max-age` 等の矛盾 directive は**置換によって消える**。
> 矛盾ヘッダのための別途の正規化ロジックは不要
> (Round 2 で私が書いた「既存ヘッダを上書きしない」は**誤りのため撤回**した)。

**適用判定** (aigenba 実装から取り込む細部):

- **route 列挙ではなく「認証済みか」で判定する**。path 列挙は一般認証画面を必ず取りこぼす
  (aigenba T557 の実績)。
- **認証状態は `$next()` の前に捕捉する**。logout POST は `$next` 通過後に guard 上の user が
  null になるため、先に取らないと **logout redirect 自体が対象から漏れる**。
  応答時点でも判定し、**どちらかが認証済みなら付与**する
  (login POST 応答 = 応答時点で認証済み、も保護側に倒す)。
- **判定式は `$request->hasSession() && $request->user() !== null`**。
  session を持たない stateless 公開配信は素通しする。
  AI-CUE も `routes/web.php:74,99` に `withoutMiddleware([..., StartSession::class])` の
  stateless block を持つため、**この判定はそのまま適用できる**。
- **登録位置は `web` グループの末尾 (= 最内側)**。pipeline は「配列で前 = 外側 = ヘッダ後勝ち」なので、
  より厳格な既存 middleware が後勝ちで上書きできる状態を保つ。
- header 付与判定は**小さな純粋メソッドに切り出す** (PHPStan level 10 を通しやすくするため)。

**response class による除外は設けない** (aigenba と揃える)。AI-CUE の実測でも
`StreamedResponse` は 0 件、`BinaryFileResponse` は
`app/Http/Controllers/Testing/GetFakeStorageObjectController.php` の 1 件 (非 production の
fake storage gate) のみで、クラス判定を足す必要性が現時点で無い (思考原則 #2)。
`Testing/` の 1 件の挙動は詳細設計で確認する。

**既存 4 経路はヘッダ完全値でピンする** (Round 3 Warning への対応)。
`no-store` の存在チェックだけでは `public, no-store` のような矛盾値を検出できないため、
実測値を詳細設計で確定して**完全一致**で固定する
(`FortifyServiceProvider:199` / `RequireRecentAuth:57` /
 `RequireTwoFactorForEnforcedOrganizations:93` / `CaptureTakeController:177`)。

guest / 公開ページを対象外にすることで bfcache と共有キャッシュの恩恵を維持する。
認証済み画面は Inertia SPA でアプリ内の戻る/進むが client-side navigation のため UX 後退はない。

#### P3-b: `pageshow` persisted 検知による再検証 (AI-CUE 固有の追加)

**なぜ必要か**: `no-store` に対する bfcache の扱いはブラウザ実装依存である。
Firefox は `no-store` で bfcache 格納自体を拒否し、Chrome は cookie 変更 (= ログアウト) 時に
CCNS ページを evict するが、**Safari は `no-store` でも bfcache へ格納しうる**。

aigenba はこれを「スコープ外」としているが、**AI-CUE では同じ判断ができない**:

- AGENTS.md の使命が「**撮影は PWA (同一オリジン・セッション認証)**」「**スマホ (PWA) で
  ナビゲーション撮影**」と定めており、**iOS Safari は主要プラットフォーム**である。
- P3 の想定シナリオ (現場の共用端末) は、まさにスマホ / タブレット共用を含む。
- リポジトリを調べたが**サポート対象ブラウザ方針はどこにも文書化されていない**
  (`DESIGN.md` / `docs/*.md` / `package.json` の browserslist をいずれも確認)。
  = 「方針に基づく除外」という逃げ道が存在しない。

**成立条件 (Round 4 Critical への対応)**: 「復元後に検証」では不十分。
`pageshow` を受けてから非同期に検証する構造だと、**検証完了までの間、復元済みの古い DOM が
表示され PII が一瞬露出する**。「無効なら遷移する」は「**再表示しない**」と同義ではない。

したがって **「検証完了まで復元内容を秘匿する」**構造にする。

**第一候補 (hard reload) の状態遷移**:

| # | 契機 | 動作 |
|---|---|---|
| 1 | `pagehide` | 画面を**同期的に秘匿**する。**この秘匿状態が bfcache snapshot に入る**ことが要点 |
| 2 | `pageshow` (`persisted === true`) | **秘匿状態のまま** hard reload する |
| 3 | reload 後 | 認証済みなら**新しい Document** を表示 / 未認証なら既存の `auth` middleware が **login へ redirect** |

> hard reload は**新しい Document へ遷移する**ため、「旧 DOM を検証結果に応じて出し直す」経路は
> **通らない**。秘匿は「reload が効くまでの目隠し」という単純な役割に閉じ、難しい状態管理が要らない。
> 「表示を戻す」遷移が必要になるのは、下記の代替案を採る場合だけ。

**代替案 (専用再検証 endpoint) を採る場合の状態遷移** — 第一候補が成立しないときのみ:

| # | 契機 | 動作 |
|---|---|---|
| 1 | `pagehide` | 同期的に秘匿 |
| 2 | `pageshow` (`persisted === true`) | 秘匿状態のまま endpoint でセッション再検証 |
| 3 | セッション**有効** | 旧 DOM の表示を戻す |
| 4 | セッション**無効** | login へ hard navigation |

**「単純な `pageshow` → 非同期検証では不十分」という不変条件自体**を、
詳細設計と Browser E2E の契約として固定する (将来この構造が崩れたら E2E が落ちるように)。

**再検証の通信契約 (Round 4 Warning への対応)**: **専用 endpoint を追加しない**のを第一候補とする。
**hard reload で既存の session middleware に再判定させる**。
hard reload なら P3-a の `no-store` によって bfcache ではなくネットワークから取り直され、
未認証なら既存の `auth` middleware が login へリダイレクトする = **新しい経路を増やさない**
(思考原則 #1「フレームワークのレンジ内でやる」/ #2「今必要なものだけ作る」)。
専用 endpoint が必要になった場合の必須条件: 同一オリジン / `no-store` / セッション認証 /
DTO・JsonResource 応答 / PHPStan level 10 の対象に含める。

**設計制約 (Round 4 Warning への対応)**: **秘匿処理は DOM 表示に限定する**。
**撮影中の media stream・未送信フォーム状態・Inertia 履歴状態は破棄しない**。
撮影 PWA が中核である以上、ここを壊すと使命に直撃する。

**型方針**: イベントは `PageTransitionEvent` を明示する。

**詳細設計での決定事項 (Round 5 Warning)**: `pagehide` は**通常遷移でも発火する**ため、
無条件秘匿は「ページを離れる瞬間に画面が隠れる」ちらつきを生む。
- `pagehide` の `PageTransitionEvent.persisted` が利用できる環境では **bfcache 対象時だけ秘匿**する。
- 利用できない環境では**安全側 (秘匿する)** へ倒す。
- 通常遷移への副作用は Browser E2E シナリオ 1 (撮影画面からの通常遷移) が固定する。

> **これは aigenba に無い AI-CUE 固有の追加**であり、乖離台帳に記録して
> **aigenba へ返す候補**とする (aigenba も PWA を持つなら同じ穴がある)。

#### P3-c: サポート対象ブラウザ方針の明文化と検証レベルの区分

サポート対象ブラウザ方針は現状どこにも無いため、**本施策の成果物として記載する**
(置き場所は `DESIGN.md` か `docs/` 配下。詳細設計で決める)。

**検証レベルを区分する (Round 4 Warning への対応)**。
Playwright WebKit と実機 iOS Safari は同一ではない (bfcache 挙動・PWA standalone モード・
iOS 固有の WebKit ビルド差)。前者の green を「iOS Safari 対応を実証した」と言い換えない。

| 区分 | 対象 | 扱い |
|---|---|---|
| **自動回帰テスト (恒久)** | Chromium / WebKit (Playwright) | **完了条件**。反復実行する |
| **実機受入確認 (手動・一度きり)** | iOS Safari 実機 (PWA standalone 含む) | **「恒久テスト済み」とは表現しない**。確認記録を devnotes に残す |

#### 検証は 2 層。**完了条件は自動 Browser E2E の成立** (Round 3 Critical への対応)

Feature テストで見られるのは**応答ヘッダまで**であり、ブラウザの bfcache 復元動作ではない。
また **bug-hunt は自由探索型で、一度の確認は恒久的な回帰テストの代替にならない**
(AGENTS.md 禁止事項 #1 は「対応する Architecture/Feature テストへの登録まで含めて『実装済み』」と定義)。

| 層 | 検証内容 | 手段 |
|---|---|---|
| **Feature** | 認証済み HTML/Inertia 応答の `Cache-Control` が `no-store, private` になること / 既存 4 経路がヘッダ完全値で untouched であること / guest・公開ページが対象外であること | Pest Feature テスト |
| **Browser E2E (必須。完了条件)** | 下記 4 シナリオを**分けて**登録する | 配置: `tests/Browser/` / 実行: `scripts/run-browser-test.sh` (`docs/testing-browser.md` が運用契約)。対象は Chromium / WebKit (Playwright) |

**Browser E2E の 4 シナリオ** (Round 4 Warning への対応。秘匿処理の誤発火と副作用を切り分ける):

| # | シナリオ | 確認内容 |
|---|---|---|
| 1 | 撮影画面からの通常遷移 | 秘匿処理が**誤発火しない**こと。media stream / 未送信フォーム / Inertia 履歴が壊れないこと |
| 2 | bfcache 復元 (一般) | 復元時に秘匿 → 検証 → 復帰の状態遷移が成立すること |
| 3 | **未ログアウト**での復元 | 表示が正しく**戻る**こと (= 誤検知しない) |
| 4 | **ログアウト後**の復元 | **PII が出ない**こと (= 本来の目的) |

**bug-hunt は追加の探索確認としてのみ**扱い、完了条件には含めない。

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
| **T-a** | P1 + P3 (ランタイム挙動の是正) | P1: 非適合セグメント → 404 の Feature テスト (bigint `/projects/abc` / uuid `DELETE .../sessions/abc` — 現状いずれも 500 で fail) / P1: total inventory gate (現状 未制約 param があり fail) / P3-a: 認証済み応答の `Cache-Control` Feature テスト (現状 no-store 無しで fail) / P3 完了条件: `logout → back` の Browser E2E (現状 再表示されて fail) |
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
| **P3** | (a) **Feature 層**: 認証済み応答の `Cache-Control` が `no-store, private` / 既存 4 経路がヘッダ完全値で untouched / guest・公開ページが対象外 (b) **Browser E2E 層 (完了条件)**: サポート対象ブラウザの代表で `logout → back` の再表示が起きない / capture 系のアプリ内遷移 UX が維持される (c) **P3-c**: サポート対象ブラウザ方針が文書化され、自動回帰 (Chromium/WebKit) と実機受入確認 (iOS Safari) が区分される。実機確認を「恒久テスト済み」と表現しない |
| **P4** | 各 gate が「固定する事故 / 不変条件」(§P4 の表) を実際に検出すること (負のコントロールで確認)。本数は副次 |
| **P5** | AGENTS.md §worktree から runbook への参照が繋がり、`enableGlobalVirtualStore` の障害時に手順が引けること |

---

## 実装方針（概要）

| 群 | 主な変更対象 |
|---|---|
| P1 | `app/Providers/AppServiceProvider.php` または `routes/web.php` (適用手段は詳細設計)、`tests/Architecture/RouteBindingTypeConstraintInventoryTest.php` (新規)、`tests/Feature/Routing/` (新規) |
| P2 | `.claude/skills/app-bug-hunt/ledger/validate_findings.py` / `adjudications.jsonl` / `test_validate_findings.py` / `README.md`、`spec-ledger.md` (新規) |
| P3 | **P3-a**: `app/Http/Middleware/NoStoreCacheHeadersForAuthenticatedPages.php` (新規)、`bootstrap/app.php`、`tests/Feature/` (新規) / **P3-b**: `resources/js/` の bfcache 秘匿・復元検知 (`pagehide`/`pageshow`。配置は詳細設計) / **P3-c**: `DESIGN.md` または `docs/` (ブラウザ方針) / **完了条件**: `tests/Browser/` (新規、`scripts/run-browser-test.sh`) |
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
- **P3-a の契約は「`no-store` を持つなら untouched / 持たないなら `no-store, private` で置換」**
  (aigenba 実装に整列。Round 2 の「上書きしない」記述は撤回済み)。
  既存 4 経路の期待値は**ヘッダ完全値**で固定する。
  検証は Feature (ヘッダ) と Browser E2E (bfcache 実挙動) の 2 層に分け、**過大申告しない**。
  **完了条件は自動 Browser E2E の成立**であり、bug-hunt はこれを代替しない (禁止事項 #1)。
- **P3-b は aigenba に無い AI-CUE 固有の追加**。iOS Safari が撮影 PWA の主要プラットフォームである以上、
  `no-store` だけでは主便益が達成されないため必須とする。乖離台帳へ記録し aigenba へ返す候補とする。
  構造は「**検証完了まで秘匿**」(`pagehide` で同期秘匿 → `pageshow` で秘匿のまま検証 → 有効なら復帰 /
  無効なら hard navigation)。「復元後に非同期検証」では PII が一瞬露出するため**不可**。
  再検証は**専用 endpoint を追加せず hard reload** を第一候補とする。
  秘匿は **DOM 表示に限定**し、media stream・未送信フォーム・Inertia 履歴は破棄しない。
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
