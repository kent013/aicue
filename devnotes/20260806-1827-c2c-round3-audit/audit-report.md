# c2c 追従 第 3 周 事後監査レポート (T121 / T122 / T123)

- 監査日時: 2026-08-06 18:27 JST
- 監査対象 main: `7f562cc` (Merge branch 'todo/T123')
- 監査者: 独立監査担当 (実装担当・マージ担当の自己申告は根拠として採らない)
- 監査方針: ブリーフ / 裁定との突き合わせ → **変異 (mutation) による偽グリーン検査** →
  AGENTS.md 不変条件 → 検証コマンドの再実行 → 残タスク列挙

**総合判定: PASS (Critical 0 / Warning 0 / Info 3)**

3 件とも裁定・起票内容を満たしており、新設 gate は**すべて自作の変異で「検出すべきものを
実際に検出する」ことを実測**した。既存テストの削除・skip・アサーション緩和は無い
(削除された 3 テストはいずれも裁定に基づく置換であり、テスト総数は増えている)。

---

## 0. 与件の所在について (最初に記録する)

オーケストレータが正本として指定した `brief3-throttle-unauthenticated-get.md` /
`brief3-queue-lease-timeout.md` / `brief3-ci-schedule-removal.md` は
**リポジトリにもファイルシステムにも存在しなかった** (`find / -name 'brief3-*'` が 0 件)。
そこで裁定の正本を以下に置き換えて突き合わせた:

- c2c 台帳 (`check_inbox(aicue)` の裁定文。`supply-chain-audit-gate` /
  `queue-lease-timeout-consistency` / `path-based-throttle` の 3 件)
- 各 TODO の概念設計 / 詳細設計 (`devnotes/20260806-16{34,35}-*`)。
  設計文書はブリーフ本文を逐条で引用しており、ブリーフの要求項目は設計内で追跡できる。

feature id は台帳側では `supply-chain-audit-gate` / `queue-lease-timeout-consistency` /
`path-based-throttle` であり、TODO の識別子 (`ci-schedule-removal` /
`queue-lease-timeout` / `throttle-unauthenticated-get`) とは一致しない
(`get_feature` は後者を `unknown_feature` で拒否する)。**台帳へ書き戻すときは
feature id を取り違えないこと**。

---

## 1. T121 / throttle-unauthenticated-get

### 1-1. 要求と実態の突き合わせ

| # | 要求 (裁定 AG-096 / 概念設計 §4) | 実装の実態 | 判定 | 根拠 |
|---|---|---|---|---|
| 1 | S3 セレクタから `$isMutating` を外し認証面 GET を母集団へ | 除去済み。母集団 47 → **70** (実測) | ✅ | `tests/Architecture/ThrottleCoverageInventoryTest.php:301` / 実測 `APP_ENV=testing php devnotes/…/measure-population.php` → 「現行 47 / 拡張後 70 / 増分 23」 |
| 2 | S1 / S2 は変更しない | 変更なし | ✅ | 同 `:288-297` |
| 3 | route floor 40 → 60 | 60 | ✅ | 同 `:45-49` |
| 4 | throttle を 5 本新規付与 | `social.callback` / `invitations.accept` (第 1 段) + `two-factor.{qr-code,secret-key,recovery-codes}` (第 3 段) の計 5 本 | ✅ | `routes/web.php:170,610` / `app/Providers/FortifyServiceProvider.php:173-178` / 実測 (measure-population 出力に 5 本の `ThrottleRequests:*` が現れる) |
| 5 | 閾値を発明しない (既存同性質と同値) | 新設 3 limiter とも **10/min** (`passkeys` guest 分岐 / `invitations.accept.store` の `10,1` / 2FA 管理操作の `10,1` と同値)。既存閾値の変更ゼロ | ✅ | `app/Providers/AppServiceProvider.php:275-285` / `FortifyServiceProvider.php:305-313` |
| 6 | 未認証面は named limiter・キーは `{レーン}:{種別}:{値}` | `social-callback:ip:` / `invitation-accept:ip:` / `two-factor-secret-read:{user,ip}:` | ✅ | 同上 + `tests/Architecture/RateLimiterKeyConventionTest.php:198-217` |
| 7 | キーに route parameter / query token を入れない | behavioral に固定 (provider 差し替え・token 差し替えで残数が連続することを assert) | ✅ | `tests/Feature/Security/AuthThrottleCoverageTest.php:252-268, 296-306` |
| 8 | 残り 14 本を新 case 2 つで exemption 分類 | `AuthViewRenderOnly` 13 件 + `AuthFlowInitiationWithoutOutboundCall` 1 件 | ✅ | `app/Enums/Security/ThrottleCoverageExemption.php:66-95` / `ThrottleCoverageInventoryTest.php:158-235` |
| 9 | 既存 11 件の分類を触らない | 差分は追記のみ (既存 entry の変更 0 行) | ✅ | `git diff 4ac7b2c 996f121 -- tests/Architecture/ThrottleCoverageInventoryTest.php` |
| 10 | 全体 cap を exact fit (25) + case 別上限 8 件 | `throttleCoverageExemptionCap()=25` / `throttleCoverageExemptionCapByCase()` が 8 case (合計 25、`array_sum` を使わず独立検査) | ✅ | 同 `:51-79` |
| 11 | exemption 側の検査を 3 点追加 | (a) exemption key が throttle を持たない (b) GET 専用 case が変更系に使われない (c) case 別上限 + **enum 全 case 走査**による「上限未登録の新 case」検出 | ✅ | 同 `:402-494` |
| 12 | 新 case の前提を behavioral に固定 | 外向き HTTP 0 / メール 0 / **DB 書込 0** / `social.callback` が `throttle:social-callback` を**ちょうど 1 本**持つ、を実挙動で固定 | ✅ | `tests/Feature/Security/ThrottleExemptionPremiseTest.php:280-600` |
| 13 | ドキュメント (§7b) にセレクタ非対称と分類方針を追記 | セレクタ表 / 分類方針 / cap の運用 / 監視項目 (429 発生率に 2 レーン追加) を追記 | ✅ | `docs/app-integration-guide.md` §7b (diff +61 行) |
| 14 | スコープ外の明記 | 概念設計 §6 に 12 項目を理由付きで列挙 (B2・429 契約・閾値統一・S1 拡張 ほか) | ✅ | `devnotes/20260806-1634-throttle-unauthenticated-get/conceptual-design.md:§6` |

**設計からの意図的逸脱 (妥当と判定)**: 概念設計は 2FA 秘密 GET 3 本へ inline `10,1` を
充てる案だったが、実装は named limiter `two-factor-secret-read` を新設した。
理由 (`ThrottleRequests::resolveRequestSignature()` の既定キーは `sha1(user id)` のみで
**同一 actor の全 inline route が 1 bucket を共有**し、描画で 2 発飛ぶ GET を足すと
最小 max の `recent-auth.password` (6) を先に食い潰して**再認証が壊れる**) は
実コードで裏が取れる。**詳細設計に反映済み** (`detailed-design.md:349-407`) であり
黙って落とした逸脱ではない。恒久回帰テストも置かれている
(`AuthThrottleCoverageTest`「2FA 秘密 GET のレーンは独立している」)。

### 1-2. 偽グリーン検査 (自作変異)

| 変異 | 期待 | 実測 |
|---|---|---|
| M1: `routes/web.php` から `->middleware('throttle:social-callback')` を削除 | inventory gate + 前提テスト + behavioral が落ちる | **3 ファイル 4 テストが fail**。`ThrottleCoverageInventoryTest`「social.callback: throttle が 1 本も無く exemption inventory にも未登録」/ `ThrottleExemptionPremiseTest`「対になる social.callback が…ちょうど 1 本持つ」が size 0 で fail / `AuthThrottleCoverageTest` の 429 境界・bucket 同一性が fail |
| M2: 認証面 GET を 1 本新設 (`->name('recent-auth.probe')`, throttle 無し) | deny-by-default で fail | **fail**「recent-auth.probe: throttle が 1 本も無く exemption inventory にも未登録」= 将来の新規 route も確実に捕まる |
| M3: S3 に `$isMutating` を戻す (拡張の巻き戻し) | floor と stale 検出が落ちる | **2 テスト fail**。「47 件しか検出されませんでした」+ exemption 14 件が stale として列挙 |

→ **母集団拡張・付与・免除台帳のいずれも空洞化していない**。
`ThrottleCoverageExemption` を allowlist 全入りで無害化した形跡も無い
(exemption 25 件は全体 cap = case 別 cap の合計 = 実件数で **exact fit**。1 本足すには
必ず 2 か所の数値を動かす差分が要る)。

### 1-3. 既存テストの削除 / 緩和

削除 1 件のみ:「2FA 管理 route は throttle が recent-auth より先に走る」を
**データセット化して対象を 2 本に増やした** (`two-factor.disable` + `two-factor.recovery-codes`)。
緩和ではなく強化。skip / todo / assertion 削除は 0 件
(`git diff 4ac7b2c 7f562cc | rg '^\+.*(->skip\(|markTestSkipped|\.skip\()'` が 0 hit)。

---

## 2. T122 / queue-lease-timeout

### 2-1. 要求と実態の突き合わせ

| # | 要求 (裁定 AG-084 / AG-080 / 概念設計) | 実装の実態 | 判定 | 根拠 |
|---|---|---|---|---|
| 1 | mprocs 4 ペインの「制限なし」を是正 | `queue` 540 / `queue-analysis` 1620 / `queue-render` 1620 / `queue-media` 240。`--timeout=0` は 0 件 | ✅ | `mprocs.yaml:20-33` |
| 2 | `queue` ペインに接続名を明示 | `queue:listen database …` | ✅ | 同 `:20` |
| 3 | 台帳が名指ししていない面 (bug-hunt) も是正 | `BUGHUNT_WORKER_TIMEOUTS` を新設し接続別 (1620/1620/240)。一律 1800 を廃止 | ✅ | `scripts/bug-hunt-shard.sh:712-726, 758-772` |
| 4 | `database.retry_after` を実測に基づき是正 | 90 → **600 のリテラル** (env 上書きを畳む) | ✅ | `config/queue.php:38-50` |
| 5 | 規則 1 の静的検査を新設 | `QueueWorkerLeaseInvariantTest` (mprocs / bug-hunt の 2 面 + 接続網羅 + 非ワーカーの理由付き除外 + env 上書き禁止) | ✅ | `tests/Architecture/QueueWorkerLeaseInvariantTest.php` (423 行) |
| 6 | 規則 2 + 配線網羅の検査を新設 | `QueuedJobLeaseInventoryTest`。母集団は `implementsInterface(ShouldQueue)` の全 18 クラス、接続指定は `token_get_all()` 解析で `$this->onConnection('リテラル')` かつ **自クラス constructor 内の実行文**に限定 | ✅ | `tests/Architecture/QueuedJobLeaseInventoryTest.php` (642 行) |
| 7 | `pail` を黙って除外しない | `MPROCS_NON_WORKER_TIMEOUT_PROCS` に理由付き登録 + 理由 20 字以上を機械強制 | ✅ | 同 `:39-41, 328-355` |
| 8 | 運用契約の明文化 | `docs/architecture.md` に §キューのリース期間とワーカー制限時間の規約 (接続ごとの値表 / 本番 supervisor が正本外である旨 / `queue:listen` ではジョブ側 `$timeout` が効かない旨) | ✅ | `docs/architecture.md` (+49 行) |
| 9 | timeout 到達時の遷移を固定 | `WorkerTimeoutTransitionTest` (`$tries=1` → JobFailed 発火 + jobs 0 件 / `$tries=3` → 予約残置 1 件) | ✅ | `tests/Feature/Queue/WorkerTimeoutTransitionTest.php` |
| 10 | スコープ外の明記 | 概念設計に 11 項目 (予約 TTL 連鎖 / 冪等化 / outbox / 実行時 fail-fast / 本番 supervisor gate 化 / SDK client timeout / `database-billing` 分割 ほか) を理由付きで列挙 | ✅ | `devnotes/20260806-1635-queue-lease-timeout/conceptual-design.md` |

**値の整合を独立に検算**: 540 < 600 / 1620 < 1680 / 1620 < 1680 / 240 < 300 —— 規則 1 は
4 接続すべてで成立。ジョブ側 `$timeout` (1560 / 1500) も規則 2 を満たす。
`database` 接続に載る 12 クラスは `$timeout` 未宣言で、目録が `null` (既定接続) と宣言し
`$timeout` 宣言自体を禁止しているため、静的に比較できない状態を作れない。

### 2-2. 偽グリーン検査 (自作変異)

| 変異 | 実測 |
|---|---|
| `mprocs.yaml` の `queue-media` を `--timeout=400` (>= retry_after 300) | **fail**「規則 1: mprocs の proc queue-media の --timeout (400) が接続 database-media の retry_after (300) 以上」 |
| `BUGHUNT_WORKER_TIMEOUTS[database-media]` を 1800 へ | **fail**「規則 1: bug-hunt の listener timeout (1800) が… retry_after (300) 以上」 |
| `config/queue.php` を `(int) env('DB_QUEUE_RETRY_AFTER', 600)` へ戻す | **fail**「database.retry_after が env で上書きされた」(helper が config を `require` で直読みするため、env repository への注入が実際に効く = この検査は空振りしていない) |
| `app/Jobs/ProbeAuditJob.php` を新設 (`ShouldQueue` / `$timeout=9999` / `onConnection($変数)`) | **2 テスト fail**「目録未登録の ShouldQueue 実装がある」+「接続の指定は `$this->onConnection('リテラル')` に限る」 |

→ 規則 1 / 規則 2 / 接続経路のいずれも deny-by-default が実効。
`scripts/bug-hunt-shard.sh self-test` も `all passed` (二段目の (y3b) が生きている)。

### 2-3. 台帳との食い違い (実装担当の申告を実コードで再確認)

台帳 `queue-lease-timeout-consistency` は「**開発用プロセス定義の 4 ペイン**がすべて制限なし」
としか書いていないが、実査すると `scripts/bug-hunt-shard.sh` にも同じ規則 1 違反が
**実在した** (3 接続に一律 `--timeout=1800`。`database-media` は retry_after 300 に対し **6 倍**)。
実装はここも是正しており、**台帳の記述より広い**。この事実は設計文書に明記されている
(台帳を鵜呑みにしない運用が機能している)。

---

## 3. T123 / ci-schedule-removal

### 3-1. 要求と実態の突き合わせ

| # | 要求 (オーナー裁定 AG-030b / AG-030c 再周知) | 実装の実態 | 判定 | 根拠 |
|---|---|---|---|---|
| 1 | `on.schedule` を例外なく除去 | `on:` は `push.branches:[main]` と `pull_request` の 2 つのみ | ✅ | `.github/workflows/ci.yml:15-18` |
| 2 | schedule 前提の死んだ条件を残さない | 3 job の `if: github.event_name != 'schedule'` を全廃 (`rg 'github\.event_name' .github/workflows` が 0 hit) | ✅ | 実測 |
| 3 | 除去理由を ci.yml のコメントに残す | 「なぜ戻してはいけないか」を 4 点 (裁定 / 受容済みの損失 / 代替は CI の外 / 機械的歯止め) で記載 | ✅ | 同 `:3-14` |
| 4 | 供給網監査の同梱と push / PR 実行は維持 | `supply-chain-audit` job は残置。`if` なし・`continue-on-error` なし・`pnpm run audit:gate` を実行 | ✅ | 同 `:215-239` |
| 5 | セキュリティ低下を理由に拒否しない | 拒否せず実装。失うものを docs に**表として明記**し受容を記録 | ✅ | `docs/supply-chain/review-checklist.md` §6 |
| 6 | 再導入の機械的抑止 | W12 (トリガー集合の完全一致) / W15 (job-level `if` の不在) / **W17 (全 workflow の schedule 不在)** | ✅ | `tests/js/architecture/ci-workflow-inventory.test.ts:276-309` |
| 7 | 文書の同期 | AGENTS.md §依存脆弱性 / review-checklist §6 / 一次対応表 / `verification-commands-doc-sync.test.ts` の EXEMPT 理由文から nightly 記述を除去 (`rg -i nightly` は TODO-closed / devnotes を除き **0 hit**) | ✅ | 実測 |
| 8 | スコープ外の明記 | 概念設計 §6「やらない」に 8 項目 (audit-gate 判定ロジック / accepted-advisories.yaml / 独立 workflow / `workflow_dispatch` / CI 外の受け皿 / TODO-closed の履歴 ほか) | ✅ | `devnotes/20260806-1634-ci-schedule-removal/conceptual-design.md:§6` |

### 3-2. 偽グリーン検査 (自作変異。実ファイルを壊して復元)

| 変異 | 実測 |
|---|---|
| `ci.yml` に `schedule: - cron: "0 20 * * *"` を復活 + `php` job に `if:` を復活 | **W12 / W15 / W17 の 3 本が fail** (32 passed / 3 failed) |
| `ci.yml` を戻し、`schedule` だけを持つ**別 workflow** (`zz-audit-probe.yml`) を新設 | **W17 のみ fail** (34 passed / 1 failed) = W17 が無ければこの迂回路は完全に素通りしていた |

復元後は 35 passed。`triggerNames()` が map / 配列 / scalar / 未定義 / boolean `true` の
5 形式を正規化し、`Object.keys()` だけの実装で起きる「配列形式の schedule 素通り」を
負のコントロールで塞いでいることも確認した。

### 3-3. 期待値の変更について

W12 / W15 は**期待値が反転**しているが、これはオーナー裁定によって守るべき不変条件が
反転した (schedule を持つ → 持たない) ことの帰結であり、**変更後の期待値が裁定と一致している**。
かつ「不在」ではなく「トリガー集合の完全一致」「job-level `if` の有無」という
**より強い形**に置き換わっており、緩和ではない。テスト総数は 30 → 35 に増加。

---

## 4. AGENTS.md 不変条件との突き合わせ

| 観点 | 判定 | 備考 |
|---|---|---|
| セキュリティ不変条件 2 / 10 (層 2 のテナント境界 404 は層 3 より前 / binding 直後に閉じる) | 影響なし | 3 件とも route binding・テナント境界の実行順に触れていない。T121 が足したのは `ThrottleRequests` のみで、`AuthThrottleCoverageTest` が **throttle が `RequireRecentAuth` より前**であることを実効 middleware 列で固定している |
| 不変条件 3 (クラス起点の主キー同一性クエリ) | 影響なし | 直 fetch を新設していない |
| 不変条件 9 (変更系 route は認可を通る) | 影響なし | 新規 route は T121 の監査用変異のみ (復元済み) |
| ドメイン規約 5 (throttle 付与規約) | **遵守** | named limiter のキー規約 / 閾値を発明しない / inline は認証済み actor 限定 / 未認証 webhook への固定キー天井なし、をすべて満たす |
| 禁止事項 1 (テストなしの実装完了報告) | 遵守 | 3 件とも Architecture / Feature テストへの登録まで完了 |
| 禁止事項 2 (PHPStan の widen / baseline) | 遵守 | `phpstan.neon` に baseline 追加なし。level 10 で 793 files clean (**本監査で再実行**) |
| 禁止事項 3 (dev DB への破壊操作) | 遵守 | `drop-test-db.php --apply` は実行していない (監査側も未実行) |
| 思考原則 3 (後方互換の並走を残さない) | 遵守 | `if: github.event_name != 'schedule'` / `DB_QUEUE_RETRY_AFTER` env / 一律 `--timeout=1800` をいずれも**消して**いる |

---

## 5. 検証コマンドの再実行 (監査者自身の実行結果)

| コマンド | 結果 |
|---|---|
| `composer phpstan` | **793/793 [OK] No errors** |
| `vendor/bin/pint --test` | **passed** |
| `composer test -- --filter='ThrottleCoverageInventory\|ThrottleExemptionPremise\|AuthThrottleCoverage\|RateLimiterKeyConvention\|NamedRateLimiterKey\|QueueWorkerLease\|QueuedJobLease\|WorkerTimeoutTransition'` | **83 tests / 83 passed / 373 assertions** |
| `pnpm exec vitest run tests/js/architecture/ci-workflow-inventory.test.ts` | **35 passed** |
| `bash scripts/bug-hunt-shard.sh self-test` | **all passed** |
| `APP_ENV=testing php devnotes/…/measure-population.php` | 現行 47 / 拡張後 **70** / 増分 23 (throttle 済み 9 / 免除 14) = 設計の数値と一致 |
| `git status --porcelain` (変異復元後) | 空 |

`composer test` 全量はマージ担当が実行済み (3435 tests / 0 failed) のため再実行せず、
疑わしい箇所を名指しで実行する方針に従った。

---

## 6. 所見 (Info)

いずれも **Critical / Warning ではない**。指摘を捻り出したものではなく、
次のセッションが踏みうる箇所として記録する。

### Info-1. 後続 TODO 候補が 1 件も起票されていない

3 件の設計が「後続 TODO 候補」と書いた項目は、`docs/TODO.md` の Open / Conditional に
**1 件も存在しない** (Open は T085 / T110 の 2 件のみ)。とくに
**B2 (2FA 秘密 GET の recent-auth 化)** は `devnotes/20260806-1403-throttle-coverage-inventory/detailed-design.md:1031`
に「throttle だけ貼ると本質 (step-up 不足) が隠れる」と書かれた項目であり、
T121 がまさにその 3 本へ throttle を貼った。T121 は誤読防止のコメントとテスト名を
2 か所に置いて手当てしているが、**台帳上の追跡点が devnotes の散文しかない**状態である。
(起票には概念設計 + 詳細設計が必須という運用制約があるため、機械的な漏れではなく
運用の順序の問題である。)

### Info-2. AGENTS.md の inline throttle 規約に、今回判明した重要な但し書きが載っていない

T121 は「inline throttle のキーは `sha1(user id)` のみで **同一 actor の全 inline route が
1 bucket を共有する**」という実挙動を突き止め、`docs/app-integration-guide.md` §7b に
⚠ 付きで追記した。一方 AGENTS.md ドメイン規約 5 は
「inline throttle (`throttle:6,1`) は『認証済みかつ actor 自身に閉じる操作』限定」のままで、
この条件だけを読むと **2FA 秘密 GET に inline を貼ってよい**と読めてしまう
(それをやると `recent-auth.password` を巻き添えで 429 にする)。
AGENTS.md は「詳細は §7b」と参照を張っているため実害は小さいが、
1 行の追記で塞げる誤読である。

### Info-3. `DB_QUEUE_RETRY_AFTER` を設定している環境では値が黙って無視される

`config/queue.php` の `retry_after` をリテラル化した判断自体は正当 (gate が嘘をつくのを防ぐ)。
`.env.example` / `.env.testing` に定義は無く、リポジトリ内の参照も 0 件であることは確認済み。
ただし**既存の実行環境の `.env` に同名の変数が残っている場合、警告なく無視される**。
`docs/architecture.md` に「env で上書きできない」旨は書かれているので、
デプロイ時にこの 1 点を確認すれば足りる。

---

## 7. 残タスク (今回の 3 件に入らなかったもの)

| # | 項目 | 出所 | 現状 |
|---|---|---|---|
| 1 | **B2: 2FA 秘密 GET (`two-factor.{qr-code,secret-key,recovery-codes}`) の recent-auth 化** | T120 詳細設計 / T121 概念設計 §6 | 未起票 (Info-1)。T121 は回数上限のみで認証強度は未対応 |
| 2 | 429 応答の経路別契約 (Inertia / XHR / API) | T121 §6 | c2c `error-response-contract` の担当。未着手 |
| 3 | `workflow_dispatch` の追加 (裁定は「残してよい」= 任意) | T123 §5 案 E | 未起票。オーナーの CI 外枠組みが決まってから |
| 4 | CI 外の定期実行の枠組み (advisory 先行検知 / expiry 切れ検出) | AG-030b | **オーナー責務**。リポジトリ側の宿題ではない (docs に受容を明記済み) |
| 5 | Stripe / AWS SDK (SES・S3) の client timeout の pin | T122 概念設計 | 未起票。現状 Mail / S3 はワーカー `--timeout` が唯一の上限 |
| 6 | 既定接続の分割 (`database-billing`) | T122 概念設計 | 未起票。回収遅延 510 秒が実害になった時点で |
| 7 | 本番/ステージング supervisor 定義への `--timeout` 設定 (540 / 1620 / 1620 / 240) | T122 `docs/architecture.md` の値表 | **リポジトリ外**。CI では検知できないと gate 自身が明記 |
| 8 | `php artisan route:cache` の毎デプロイ再生成 | T121 運用要件 | 既存規約だが、2FA 秘密 GET 3 本が新たに後付け経路 (`RouteThrottleBinder`) に依存したため重要度が上がった |

---

## 8. 結論

- **T121 / T122 / T123 の 3 件とも、裁定・起票の要求を満たしている。**
- 新設 gate は **10 種類の自作変異**をすべて検出した。deny-by-default を謳う gate が
  allowlist 全入りで無害化されている箇所は見つからなかった。
- 既存テストの削除・skip・アサーション緩和は無い (置換 3 件はいずれも強化または裁定に基づく反転)。
- `composer phpstan` / `vendor/bin/pint --test` は監査者の再実行でも green。
- 是正を要する Critical / Warning は無い。Info 3 件は次周回の運用で拾えば足りる。
