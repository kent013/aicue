# AGENTS.md — LLM 開発者向け規約

このリポジトリで作業するすべての LLM エージェント・開発者が従う規約。
迷ったら本書と `docs/app-integration-guide.md` に立ち返ること。

## 使命 (North Star)

<!-- app-codex-review スキルはこのセクションをレビュー使命として参照する。 -->

**AI-CUE** は、現場に既にある**作業手順書(SOP)を起点に**、AI が撮るべきカットを設計した**動画シナリオ**を生成し、そのシナリオを**スマホ(PWA)でナビゲーション撮影**することで、専門知識ゼロの現場作業者でも**標準化されたマニュアル動画**を作れるようにする。

- 「思考ゼロ・編集ゼロ」— 台本作成・撮影判断・編集の 3 ハードルを AI とナビ撮影で肩代わりする。
- 競合(OJT を撮って形式化する tebiki)と異なり、**標準作業を起点に AI が教材設計し撮影を指示する**（撮影者・教える人のスキルに品質を依存させない）。
- 熟練者の暗黙知を動画マニュアルという形式知へ変換する装置（SECI）。

> 詳細な設計は本リポジトリの `doc/`（01〜10）に置く。ドメインをテンプレート構造へどうマップしたかは `doc/08`〜`doc/10`（特に `doc/10 §10.8` に設計レビュー反映済み）。
> **v1 スコープ**: 字幕のみ(TTS 後回し) / 撮影は PWA(同一オリジン・セッション認証) / 動画合成は自前 ffmpeg / 単一 Default Project。

## 思考原則

1. **フレームワークのレンジ内でやる**。自前機構の前に Laravel / 同梱モジュールの公式作法を確認する
2. **今必要なものだけ作る**(オーバーエンジニアリング禁止。「あったら便利」は作らない)
3. **後方互換の並走を残さない**。書き換えると決めたら同じ PR で旧実装を消す
4. **別物の概念を「似ているから」で統合しない**
5. **テストファースト**。fail を確認してから実装に入る
6. **タコツボ実装を避ける**。各ステップで他要素との結合観点を確認する

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

## セキュリティ不変条件(アプリ都合で緩めない)

詳細と実装手順は `docs/app-integration-guide.md` §7。すべて Architecture テストで強制されている:

1. **tenant キー不信**: ownership/actor/tenant キーを payload から受け取らない
   (`ProhibitsProtectedKeys` + `MassAssignmentSafetyTest`)
2. **子は親に属する**: nested route の不整合は**認可より前に 404**
   (`NestedRouteIdorDefenseTest` の inventory に登録必須)
3. **cross-org 不可**: 組織を跨ぐ read/write をしない(relation / org-scoped 解決経由のみ)
4. **untrusted 文字列は UserInput 型経由でのみ prompt に入れる**
5. **権限判定は常に `laratrust_team_id` を明示**(strict_check=true)
6. **PII(email/name)は CipherSweet**。検索は `whereBlind()`(平文 where は hit しない)
7. **課金の冪等性**: webhook は冪等マシン経由、チケットは reserve→commit/release の 2 フェーズ
8. **外部 URL 取得は SSRF 検査経由**: 外部 URL(特にユーザ入力由来)を取得する機能は
   必ず `Kent013\SsrfPin\UrlSafetyInspector` / `PinnedHttpClient` を通す。
   安全境界は `config/ssrf-pin.php` に pin する(`SsrfPinBoundaryTest` が pin 値を固定)

## 実装規約

- `declare(strict_types=1)` + 日本語コメント。Controller は薄く(Service 委譲)、
  transaction は Service 内。保護キーは forceFill / relation で明示代入
- 新しいドメインリソースの追加手順は **Item リソースが見本**
  (`docs/app-integration-guide.md` §2 のチェックリスト)。
  新規モデル追加時は Factory の追加と `docs/architecture.md` / `docs/factories.md`
  への追記が必須
- フロントは Svelte 5 runes + DS token/ramp のみ(`DESIGN.md` が canonical、
  ds-purity テストが検出)。フォームは FormField / Checkbox atom 経由
- component 階層は `atoms → molecules → organisms → features/{domain} → templates → pages`
  の単方向 import のみ(下層から上層・features の domain 間横参照・component 層から pages は
  禁止。`tests/js/architecture/atomic-import-graph.test.ts` が強制)。アイコンは
  `@lucide/svelte` のみ。Lucide に無いブランド/SSO ロゴの SVG 内包は
  `components/atoms/icons/` 配下に限る(`svg-inline-allowlist.test.ts` が強制)
- 検証コマンド: `composer test` / `composer phpstan` / `vendor/bin/pint --test` /
  `pnpm lint` / `pnpm typecheck` / `pnpm test` / `pnpm build`(全 green でコミット)

## コードベース探索

- **自プロジェクトのコードベース探索は code-review-graph MCP を優先**する。
  Tree-sitter 由来のグラフ DB(`.code-review-graph/graph.db`)で
  blast-radius・呼び出し関係・依存関係を取得できる
- PR レビュー / 大規模変更前の影響調査 / 「この関数の呼び出し元は?」
  「この変更で壊れる可能性のあるテストは?」のような構造的問いには、
  grep / 全ファイル read より先に code-review-graph の MCP tools を試す
- ただし機械的な文字列検索(TODO コメント抽出、特定リテラル探索など)は
  そのまま `rg` / `grep` を使う方が速い。code-review-graph はあくまで構造把握用
- セットアップ: `uv tool install code-review-graph` → `code-review-graph build` で
  初回ビルド(中規模アプリで ~50 秒)。以降は hook で自動更新されない場合
  `code-review-graph update` で差分更新(~2 秒)
- SQLite キャッシュ(`.code-review-graph/`)は `.gitignore` 済みでクローン毎に各自再生成

## 設計・TODO・devnotes の運用

- 設計フロー: 概念設計 → レビュー → 詳細設計 → レビュー(`app-design` スキル)。
  設計ドキュメントは `devnotes/YYYYMMDD-HHMM-{topic}/`、レビュー機械出力は同 `codex-history/`
- TODO: `docs/TODO.md`(Open)と `docs/TODO-closed.md`(Closed/Obsoleted)。
  登録は `app-todo-add`、クローズは `app-todo-close` スキル経由
- 実装は worktree(`.claude/worktrees/tasks/<id>`)で行い、テスト green + レビュー後に main へ
  (§worktree 運用ルール)
- 一時スクリプトは devnotes へ、恒久スクリプトのみ `scripts/` へ
  (昇格時は `scripts/README.md` の台帳に追記する)
- 外部 skill (Stripe 公式) は `skills-lock.json` で管理する。
  `npx skills add docs.stripe.com` で `.claude/skills/` 配下に再導入できる(git 管理外)

## 依存脆弱性 (supply-chain) の運用

- `pnpm run audit:gate`(`scripts/audit-gate.sh` → `scripts/audit-gate.ts`)が
  composer / pnpm(pyproject.toml があるリポジトリでは PyPI も)の audit を統合判定する。
  未受容の high/critical で fail、moderate は warn
- advisory 検出時は **upgrade で解消が原則**。accept-risk は最終手段で、
  `docs/supply-chain/accepted-advisories.yaml` に owner / approved_at / expiry /
  rationale 付きで登録する(high/critical は approved_by / compensating_controls /
  tracking_issue も必須)。severity 別の expiry 上限(low/moderate 90 日・high 30 日・
  critical 14 日)、期限切れ・解消済み entry の残置は gate が機械的に fail させる
- 判断基準・0day 緊急時フロー・新規 npm 依存の審査観点は
  `docs/supply-chain/review-checklist.md` を参照

## worktree 運用ルール

実装は必ず worktree で行う(main 直接実装禁止)。セットアップ・破棄は
`scripts/setup-worktree.sh` / `scripts/teardown-worktree.sh` で機械的に運用する。

- **セットアップ**: `scripts/setup-worktree.sh <task-id>` が
  `.claude/worktrees/tasks/<task-id>` に worktree を作成し `todo/<task-id>` ブランチを切る
  (main 起点・ブランチ名固定、custom branch 非対応)。実行時ファイル
  (`.env` / `storage/oauth-*.key` / `public/build`)のコピー、worktree 内
  `composer install --no-scripts`、`pnpm install --frozen-lockfile`、
  post-setup health check、pgsql テスト DB の ensure まで自動で行う。
  失敗時は EXIT trap が作成途中の worktree とブランチを自動削除する
- **依存は worktree-local**: `vendor/` は worktree 内 `composer install` の独立ディレクトリ。
  `node_modules` は `pnpm-workspace.yaml#enableGlobalVirtualStore` で実体を共有 store
  (`<store-path>/links/`)に置き、worktree 内 `pnpm install`/`pnpm add` の影響を
  自 worktree に局所化する(main / 他 worktree を汚さない)
- **worktree 内のコマンド規則**: `pnpm install` / `composer install` は許可(worktree-local)。
  `pnpm add/remove/update`・`composer require/remove` は task branch 上で実行可だが、変更した
  `package.json` / `pnpm-lock.yaml` / `composer.json` / `composer.lock` を必ずコミットすること
  (未コミットのまま teardown すると失われる)。手動で worktree 内 `pnpm install` する際は
  `--config.ci=false --config.enableGlobalVirtualStore=true --config.nodeLinker=isolated` を
  付ける(`CI` 等の環境変数で GVS が自動無効化されるのを CLI 明示で防ぐ)
- **後片付け**: `scripts/teardown-worktree.sh <task-id>` が dirty チェック
  (未コミット/untracked があれば fail)→ テスト DB の best-effort 回収 →
  `git worktree remove --force` を行う。ブランチ `todo/<task-id>` の削除/マージは
  呼び出し側の責務(main マージ後に `git branch -d todo/<task-id>`)
- **orphan 化した worktree**(teardown を経ず破棄)は `git worktree prune` で整理。
  検証なしの強制削除は
  `git worktree remove --force .claude/worktrees/tasks/<task-id> && git worktree prune`
- **背景と障害対応**: 分離設計 (vendor / node_modules / テスト DB / 実行時ファイルの 4 軸) の
  意図は `docs/worktree-isolation-strategy.md`、`enableGlobalVirtualStore` の前提・落とし穴・
  復旧手順は `docs/pnpm-global-virtual-store-runbook.md`(GVS 無効化・暗黙 peer・ENOMEM 等)

## bug-hunt (LLM 探索的バグハント、オプトイン)

`.claude/skills/app-bug-hunt/` は自由探索型の UX バグハント基盤。回帰テストでは見つからない
説明なしリダイレクト・操作詰み・IDOR・UX 破綻を、隔離 bughunt 環境 (直列 `:8010` / 並列 shard
`:8011..8018`、DB `bug_hunt(_N)`) で実ブラウザ走行して発見する (修正はしない)。起動は `/app-bug-hunt`。

- **オプトイン・完全 no-op**: 未使用時はアプリ実行に一切影響しない。`config/bughunt.php` と
  `BughuntCoverageMiddleware` は `env(BUGHUNT_PCOV)` + `function_exists('\pcov\start')` の二重 guard で
  pcov 未導入の本番/CI/dev では常に no-op。`BughuntOAuthSeeder` は fake_externals + bughunt.local +
  `^bug_hunt(_[1-8])?$` の三重 fail-secure ガードで、条件不成立なら no-op (dev DB に認証状態をばら撒かない)。
- **dev DB 防御 (非交渉)**: 全 DB 操作は `scripts/bug-hunt-shard.sh` の用途別 wrapper (`env -i` で
  shell の `DB_*`/`PG*` を遮断 + DB名 regex + role guard) 経由のみ。生 artisan/psql/tinker/createdb/dropdb 禁止。
  `provision`/`teardown` は `BUGHUNT_ORCHESTRATOR=1` を持つ親のみ (worker は default-deny)。
- **worktree 既定**: bug-hunt は worktree から走る (`scripts/bughunt-worktree-hook.sh` の PreToolUse ガードが
  main 直叩きを早期に止める。配線は `.claude/settings.bughunt-hook.example.json` を `.claude/settings.json` にマージ)。
- **スケルトン**: `screens.md` / `operations.md` / `stories/` はテンプレートでは空スケルトン。初回に
  `php artisan route:list` から生成する (SKILL.md Phase 1)。ドリフト検知は `scripts/bug-hunt-inventory-check.sh`。
- **capability 語彙**: finding の `capability_tag` の正本は
  `.claude/skills/app-bug-hunt/capability-catalog.md`(SOP→シナリオ→撮影→レンダの責務境界を
  先に定義し、その上に capability_id を割り当てる。未割当は `unmapped`・tag 不能は `unknown`)。
- 検証: `scripts/bug-hunt-shard.sh self-test` (実資源に触れず guard/資源導出/env 隔離/asset 鮮度を検証)。
  Python ツール (`coverage/` `ledger/`) は `python3 -m unittest` (stdlib のみ)。

## テンプレートとの関係

このリポジトリは laravel-claude-template から生成されている
(バージョンは `config/template.php` の `template_version`)。
テンプレート構造からの**意図的な逸脱**は `docs/template-divergence.md` に
logic-driven な理由と「保証し続ける不変条件」を記録してから行う。

## ドメイン固有規約

<!-- TEMPLATE-MARKER: アプリ固有の規約 (ドメインモデルの不変条件、外部 API、
     固有のテスト規約等) をここに追記していく。テンプレート共通部 (上記) は
     テンプレート更新の取り込みを容易にするため、できるだけ書き換えない。 -->

1. **シナリオ整合の共有ロック規約**: `cuts` / `video_manuals.scenario_version` /
   `video_manuals.status` を書き込む全経路は、対象 VideoManual 行を `lockForUpdate()` で
   取得した同一トランザクション内で反映する (準拠実装: `Manual/ScenarioService::save()` /
   `Manual/ScenarioService::materializeIntoLockedManual()` / `Manual/AnalysisJobService::trigger()` /
   `Manual/AnalysisJobService::failJob()` / `Capture/CaptureTakeService::adopt()`・`delete()`
   (cuts.adopted_take_id)。経路 inventory は **`ScenarioWritePathInventoryTest`
   (Architecture テスト) へ昇格済み** = 新しい書き込み経路は inventory 登録が必須。
   テイク採用 API は検出 4 (`adopted_take_id` の deny-by-default 走査) で inventory 準拠済み。
   後続の RenderJob 状態遷移も同規約に従う。
   詳細は `docs/architecture.md` §シナリオ整合の共有不変条件)
2. **容量 Quota (max_storage_bytes) の予約規約**: presigned アップロードの容量判定は
   `Billing/QuotaService::checkAddition` + `Capture/StorageUsageService::occupiedBytes`
   (bytes_used + bytes_pending) 経由のみ。予約 (`take_upload_reservations`) の状態遷移は
   pending→verifying (claim)→completed/released の CAS で行い、直接 UPDATE を書かない。
   運用契約 (media queue worker / 孤児掃除 cron) は `docs/architecture.md` §撮影 PWA
3. **サポート対象ブラウザと bfcache の扱い**: 「どのブラウザで何をどこまで保証しているか」の
   正本は **`docs/supported-browsers.md`**。撮影 PWA の主戦場は iOS Safari であり、Safari は
   `Cache-Control: no-store` でも bfcache に格納しうるため、認証済み画面は
   サーバ側 no-store baseline (`NoStoreCacheHeadersForAuthenticatedPages`) と
   クライアント側の bfcache 秘匿・再検証 (`resources/js/lib/bfcache-guard.ts` +
   `session.status` プローブ) の **セット**で守る。
   bfcache guard / 秘匿スタイル / プローブ endpoint に手を入れたら、
   `docs/supported-browsers.md` の**実機受入確認の再確認条件**に従って再確認する。
   Browser テストは **Chromium + WebKit の 2 レーン**が契約 (`docs/testing-browser.md`)。
   実行時間を理由に WebKit レーンを落とさない (復元シナリオの恒久回帰が消えるため)
4. **課金ゲート (P4 反転) の route 配置規約**: 新しい業務ドメインの route は
   `routes/web.php` の `require-active-subscription` group **の中**に追加する。
   group の外に置いてよいのは「契約するために未契約組織が到達できなければならない導線」
   (`billing.*` / `billing.tickets.*` / `billing.auto-recharge.*` / `billing.contact.update` /
   `onboarding.*` / `notifications.*`) だけで、これは**構造的 allowlist** として
   `routes/web.php` のコメントに明記する。遮断時の着地は `manageBilling` 保持者 →
   `onboarding.checkout` / 非保持者 → `onboarding.billing-required` で、**403 で突き放さず
   専用画面で受ける** (行き先のない詰みを作らない)。運用契約は `docs/architecture.md`
   §サブスク契約 Checkout とオンボーディング着地、デプロイ順序は
   `docs/billing-gate-inversion-runbook.md`
