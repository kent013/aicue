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
5. LLM 呼び出しの Prism 直呼び(`app/Prompts/` の factory → 窓口 (`PromptDefense`) →
   実行単位 (`GuardedPrompt`) の**1 本道のみ**。`PromptGuardrailTest` が
   app/ routes/ database/ config/ bootstrap/ の 5 走査根で検出する)。
   **実行経路を持つ prompt factory は `LlmCallContextData` を必須引数で受け、
   `PromptDefense::load()` へ渡して帰属 (organization / subject) を付ける** — 付け忘れは
   PHPStan level 10 が落とす。帰属の対象を持たない見本 (`ExampleSummaryPrompt`) だけが
   `PromptDefense::loadUnattributed()` を使え、窓口 gate が**この 1 件を名指しで pin** する。
   併せて `PromptUntrustedInputContractTest` の inventory へ**帰属キーを空配列で exempt 登録**する
   (deny-by-default なので exempt にする操作がレビューで必ず見える)。
   欠けると `llm_call_logs.metadata_missing` になり組織別・対象別の費用が出せない
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
3. **cross-org 不可**: 組織を跨ぐ read/write をしない(relation / org-scoped 解決経由のみ)。
   **クラス起点の主キー同一性クエリ**(`User::find($payloadId)` /
   `User::query()->where('id', …)` / `DB::table('users')->where('id', …)`)は
   deny-by-default で分類が要る(`ModelDirectFetchInvariantTest` + `DirectFetchInventory`。
   route parameter 由来の id は `NestedRouteIdorDefenseTest` の担当で母集団が交わらない)
4. **untrusted 文字列は窓口 (`App\Support\Llm\PromptDefense`) 経由でのみ prompt に入れる**。
   窓口が無害化 (制御文字・不可視文字・長さ) → タグ境界化 (`UserInput`) → 合言葉の合流 →
   帰属の付与を行い、実行単位 (`App\Support\Llm\GuardedPrompt`) が vendor 実行と応答検査を
   1 メソッドに束ねる (合言葉が応答に出たら**応答を返さず**例外)。
   窓口の引数は生の string なので、呼び出し側が自分でタグ境界化の型を作る経路は型で消えている
   (`PromptDefenseWindowGateTest` / `PromptUntrustedInputContractTest` /
   `DefensiveInstructionsPresenceTest` / `LlmDefenseConfigGateTest`)。
   **監視条件**: 実行時に決まる値 (会話履歴・過去の出力・他利用者の入力) を prompt へ入れる形が
   生まれたら、その経路も窓口の untrusted 側を通す (trusted の入口は作っていない。
   足すときの義務は `docs/template-divergence.md` D16)。
   保証しないものの正本は `docs/architecture.md` §LLM プロンプト防御の窓口方式
5. **権限判定は常に `laratrust_team_id` を明示**(strict_check=true)
6. **PII(email/name)は CipherSweet**。検索は `whereBlind()`(平文 where は hit しない)
7. **課金の冪等性**: webhook は冪等マシン経由、チケットは reserve→commit/release の 2 フェーズ
8. **外部 URL 取得は SSRF 検査経由**: 外部 URL(特にユーザ入力由来)を取得する機能は
   必ず `Kent013\SsrfPin\UrlSafetyInspector` / `PinnedHttpClient` を通す。
   安全境界は `config/ssrf-pin.php` に pin する(`SsrfPinBoundaryTest` が pin 値を固定)
9. **変更系 route は認可を通る**: POST/PUT/PATCH/DELETE は `Gate::authorize` を通すか、
   exemption inventory へ理由付きで登録する(deny-by-default)。
   **層 2(テナント境界 = 404)は層 3(認可 = 403)より前**(逆にすると存在が漏れる)
   (`ControllerAuthorizationGateTest`)
10. **層 2 は binding の直後・FormRequest より前で閉じる**: binding とテナント境界 404 の間に
    404 以外で短絡する middleware があると **1 bit の存在オラクル**になる。実行順の正本は
    `bootstrap/app.php` の **priority list**(route の宣言順ではない)
    (`ProjectRouteCurrentOrgGuardTest` / `NestedRouteIdorDefenseTest` /
    `TenantBoundaryOrderingTest`)
11. **キャッシュに入れるのは素のデータだけ**: cache へ渡す値は配列 / 文字列 / 数値 / 真偽値に限る
    (オブジェクトを直接入れない)。読み戻しは `fromArray()` 等で**明示的に組み立て直して検査**し、
    失敗したら `forget` する(準拠実装 `FxRateService` + `FxSnapshotDto`)。
    `config/cache.php` の `serializable_classes` は **`false` 固定**でクラス許可一覧を作らず、
    **キーごと消さない**(宣言が無いと制限なしの `unserialize()` に戻る = fail-open)。
    **テストは array store で緑になり本番 database store でだけ壊れる**ため、
    書き込み経路とキャッシュに触れるファイルは deny-by-default の目録で強制する
    (`CachePayloadPlainDataGateTest` / 宣言 pin は `ConfigHardeningTest`。
    guide §7 不変条件 6 と対応)

> **採番の注意**: 本節の番号と `docs/app-integration-guide.md` §7 の番号は **1:1 対応しない**
> (本節 6 = PII CipherSweet / guide 6 = 逆シリアライズ、本節 8 = SSRF / guide 8 = 認可 gate)。
> 相互参照するときは**番号ではなく項目名**で指すこと。既存の参照
> (`docs/app-integration-guide.md` の「§7 不変条件 8」/ stripe webhook migration の「不変条件 7」)
> を壊すため、どちらの側も renumber しない。

> **運用要件 (T108)**: production は `TRUSTED_PROXIES` の**明示宣言が必須**
> (未宣言 / `*` / `REMOTE_ADDR` / 書式不正は `ProductionEnvGuard` が起動時 fail-fast する
> = **初回デプロイ前に設定が要る破壊的変更**)。`trustProxies(at: '*')` はレート制限を
> 総当りに無効化するため復活させない。実 hop 一覧・CIDR の管理主体・変更手順は
> `docs/trusted-proxies-runbook.md` が正本。

> **運用要件 (パスキー)**: production は `PASSKEYS_USER_HANDLE_SECRET` の**明示宣言が必須**
> (未宣言 / 32 文字未満 / 身元の識別子・許可する接続元の書式不正・相互不整合は
> `PasskeyConfigValidator` が `ProductionEnvGuard` 経由で起動時 fail-fast する
> = **初回デプロイ前に設定が要る破壊的変更**)。宣言しないと利用者ハンドルが `APP_KEY` 由来になり、
> **`APP_KEY` ローテートで登録済みパスキーが全件無効**になる。既にパスキーがある環境は
> 現行 `APP_KEY` の値をそのまま宣言すれば維持できる。運用手順は
> `docs/auth-security-mechanisms.md` §5。

> **運用要件 (route:cache)**: production は `php artisan route:cache` を**毎デプロイ再生成する**。
> vendor route への middleware 後付け(`RouteThrottleBinder` / `RouteMiddlewareBinder`)は
> **cache 生成時に焼き込まれ、cached 起動では 1 本も効かない**ため、stale cache は
> **無音で保護を外す**(実測: 2FA 秘密 GET が 409 でなく 200 を返し、passkey 削除の
> 手段保持 guard も消える)。対象は throttle だけではない(recent-auth /
> ensure-login-method / no-store も同じ前提条件)。機序と実測は
> `docs/app-integration-guide.md` §7c が正本。
> **本リポジトリにデプロイ定義は無い**(deploy/ / terraform / k8s / CI デプロイ job のいずれも無い)。
> よって現在この要件は**人手でのみ守られている**。**デプロイ基盤を作る PR は、
> 本要件と TRUSTED_PROXIES 運用要件 (T108) の 2 つを実装するまで完了にできない**。
> 存在しない基盤のための preflight 機構を先回りして作らないこと(思考原則 2)。
> 家系の正典はこの後付けを「経路の一覧が組み上がった後に走らせる専用の実行点」へ集約する形だが、
> **本リポジトリは「経路キャッシュ起動では走らせない」側を選んでいる**。この判断は
> `docs/template-divergence.md` **D19** に登録済みである。
> 判断の主前提に対するトリップワイヤとして、**追跡下に直接書かれた `route:cache`** と、
> **`artisan` と `optimize` の間が空白だけの実行記述**が無いことを
> `tests/Architecture/RouteCacheExemptionPremiseTest.php` が機械で固定する。
> **動的に組み立てた文字列・オプションを挟む書き方・リポジトリの外にある手順は対象外**である。
> 説明として `route:cache` の語を持つ既存ファイルは**件数を完全一致で pin** して扱い
> (増減のどちらでも赤になる)、走査から丸ごと外れているのは**同テスト自身の 1 件だけ**である
> (自分が検出したい語を負のコントロールの入力として持つため。その 1 ファイルの中は見えない)。
> 同テストは既知のデプロイ定義が増えたことも早期に知らせるが、そちらは網羅を主張しない
> (新しい CI 基盤やファイル名は拾えない)。**どちらかで赤くなったら D19 を読み直すこと。**
> 焼き込みの入力に後付けが載っていることと、欠けたときに保護が実際に外れることは
> `tests/Feature/Security/RouteCacheBakedProtectionTest.php` が固定する
> (同一プロセス内の実測であり、**cached 起動そのものの再現ではない**)。

## テストレーンの外部 HTTP 出口 (既定拒否)

テストレーンは Laravel HTTP client (`Http::`) 経由の外向き通信を**既定で拒否**する
(裁定 AG-105 準拠。設計は `devnotes/20260807-1235-stray-http-egress-deny/`)。

- 配線は `tests/Pest.php` の**全レーン** (Feature/Unit・Architecture・Browser) が
  `Tests\Support\StrayHttpRequestGuard::install()` / `flushAndFailIfStray()` で行う。
  **テスト内で局所的に張って外す形は既定と認めない**
  (`StrayHttpEgressLaneGateTest` が deny-by-default で強制)
- 自機宛て loopback (`127.0.0.1` / `localhost` / `[::1]`) だけが
  `StrayHttpRequestGuard::ALLOWED_URL_PATTERNS` で明示許可される。
  この定数が許可集合の唯一の正本で、`config('app.url')` の host は含めない
- **許可判定は 2 層**。framework 側の `Str::is()` glob 照合だけでは userinfo 詐称
  (`http://127.0.0.1:80@api.frankfurter.dev/` = userinfo が loopback で**実ホストは外部**) が
  `:*` パターンに一致して通ってしまうため、guard は**パース済みホスト**を見る第 2 層
  (`StrayHttpRequestGuard::isSmuggledLoopbackUrl()`) を middleware で併用する。
  `LOOPBACK_HOSTS` は `ALLOWED_URL_PATTERNS` のホスト部と 1:1 (gate が機械固定)
- 外部 URL を叩くテストは `Http::fake([...])` を書く。opt-out
  (`Http::allowStrayRequests(...)` / `preventStrayRequests(false)`) は
  型付き enum + 30 文字以上の根拠付きで exemption inventory へ登録する
- アプリ側が `catch (Throwable)` で握り潰しても検出できるよう、guard は
  `Http::globalMiddleware` で `StrayRequestException` を accumulator に記録し
  afterEach で一括判定する (LLM 側の `StrayLlmCallGuard` と同じ形。両者は**並存**する)
- **保証範囲 (誇張しない)**: 効くのは **`Http::` を呼んだプロセス内**の Laravel HTTP client
  経由の出口**だけ**。以下には**無言で効かない** —
  bug-hunt (`scripts/bug-hunt-shard.sh` の別プロセス実行) /
  Socialite (Guzzle 直) / Stripe SDK / AWS SDK /
  Browser lane で Playwright のブラウザ自身が出す外部取得。
  また許可判定は**名前解決前の URL 文字列**照合なので、`localhost` が loopback に
  解決されることは**前提であって保証ではない** (hosts / DNS の健全性は対象外)。
  この非対称を対称に書かない (「テストは外部に一切出ない」と書くのは嘘になる)

## 禁止する文 (echo / goto / global / 開始タグ付きの出力記法)

PHP の `echo` / `goto` / `global` の 3 文と、開始タグ付きの出力記法 (`<?` に `=` を続ける書き方) は
**書かない**。字句 (トークン) 単位の走査で検出する
(`tests/Architecture/ForbiddenStatementTokenInvariantTest.php`。
設計は `devnotes/20260815-1537-forbidden-statement-token-gate/`)。

- 理由: 出力する 2 つの記法は Laravel の応答制御 (Inertia / JsonResource / Response) を
  迂回して直接出力へ書き出すため、ヘッダ確定前に本文を流し得る。
  撮影 PWA が依存する 3 枚セット (no-store baseline / bfcache 秘匿 /
  Inertia 履歴暗号化。ドメイン規約 3) を壊し得る経路になる。
  `goto` は制御フローを構造から読めなくし、`global` は DI コンテナ経由の
  依存解決を迂回して差し替えられない結合を作る
- 走査対象は **git 追跡下の `*.php` 全件** (`.blade.php` を含む)。
  置き場所は「走査する / 例外の登録を許す (`scripts` `tests`) / 除外する
  (`devnotes`。理由必須)」の 3 つへ**排他的に分類**し、
  **どれにも分類していない置き場所が現れたら赤になる**
- 例外は `ForbiddenStatementExemption` + 30 文字以上の根拠 + **件数**付きで
  目録へ登録する (deny-by-default)。件数は完全一致で、増えても減っても赤になる。
  **登録の正本は目録 (`forbiddenStatementExemptions()`) だけ**で、本書には件数を写さない
  (2 か所に書くと必ず食い違う)。登録できるのは `scripts` / `tests` に限る。
  例外に登録したファイルも**全語彙を走査する** (登録の無い語彙は 1 件残らず違反になる)
- **語彙を勝手に増やさない**。`print` は正典が対象外と定めており、
  拡張は家系の機能台帳の議題として起こす決まりである
- **保証範囲を誇張しない**: 効くのは字句として現れる 4 語彙だけである。
  名前の解決が要る出力 (書式つき出力 / 変数の内容の表示 / 標準出力への書き込み)、
  Blade の `@php … @endphp` と二重波括弧の中、ヒアドキュメント本文には
  **無言で効かない** (PHP 開始タグで開いた区間は見える)。
  「この検査があれば直接出力は 1 つも無い」とは読めない

## 実装規約

- `declare(strict_types=1)` + 日本語コメント。**宣言は git 追跡下の PHP 全数が対象**で、
  免除の登録簿は持たない(`StrictTypesDeclarationGateTest` が deny-by-default で強制。
  `*.blade.php` は PHP ソースではないため対象外)。Controller は薄く(Service 委譲)、
  transaction は Service 内。保護キーは forceFill / relation で明示代入
- 月 / 年 / 四半期の加減算は**暗黙 overflow メソッドを禁止**する。既定は
  `addMonthNoOverflow` / `subYearNoOverflow` 等の `*NoOverflow`、overflow が要件なら
  `*WithOverflow` を明示して意図をコードに残す(`addMonth()` は 1/31 → 3/3 と溢れる。
  `CarbonOverflowArithmeticGateTest` が検出)
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
<!-- VERIFICATION_COMMANDS:BEGIN -->
- 検証コマンド: `composer test` / `composer phpstan` / `vendor/bin/pint --test` /
  `pnpm lint` / `pnpm typecheck` / `pnpm test` / `pnpm build` /
  `pnpm typecheck:packages` / `pnpm build:packages` / `pnpm test:packages`
  (全 green でコミット。`tests/js/architecture/verification-commands-doc-sync.test.ts` が
  package.json の検証系 script との同期を deny-by-default で強制する。マーカーごと消さないこと)
<!-- VERIFICATION_COMMANDS:END -->

## コードベース探索

- **自プロジェクトのコードベース探索は code-review-graph MCP を優先**する。
  Tree-sitter 由来のグラフ DB(`.code-review-graph/graph.db`)で
  blast-radius・呼び出し関係・依存関係を取得できる
- PR レビュー / 大規模変更前の影響調査 / 「この関数の呼び出し元は?」
  「この変更で壊れる可能性のあるテストは?」のような構造的問いには、
  grep / 全ファイル read より先に code-review-graph の MCP tools を試す
- ただし機械的な文字列検索(TODO コメント抽出、特定リテラル探索など)は
  そのまま `rg` / `grep` を使う方が速い。code-review-graph はあくまで構造把握用
- セットアップ: 開発コンテナには `docker/Dockerfile` が版を固定して導入済み
  (`code-review-graph==2.3.7`)。コンテナを作り直していない環境だけ手で
  `uv tool install code-review-graph==2.3.7` を 1 度実行する。索引の初回ビルドは
  `code-review-graph build`(中規模アプリで ~15 秒)
- 以降の差分更新は **`.claude/settings.json` の PostToolUse hook が自動で回す**
  (§常設 hook 配線)。実行環境の前提は `flock` と `timeout` の 2 つで、
  どちらか欠けると更新は走らず**セッションごとに 1 行だけ**告知する
  (手で回すときは `code-review-graph update`。~0.5 秒)
- SQLite キャッシュ(`.code-review-graph/`)は `.gitignore` 済みでクローン毎に各自再生成。
  hook の作業ファイル置き場(`.claude/code-review-graph-update-hook/`)も同様で、
  中身はロックと告知の目印だけなので消して構わない(消せば次のセッションで再告知される)

<!-- CLAUDE_HOOKS_WIRING:BEGIN -->
## 常設 hook 配線

`.claude/settings.json` は git 追跡下の**配線の正本**である。配線されている hook は 2 本:

| イベント | 対象 | スクリプト | 役割 |
|---|---|---|---|
| PreToolUse | Bash | `scripts/bughunt-worktree-hook.sh` | bug-hunt provision の main 直叩きを止める |
| PostToolUse | Write / Edit | `scripts/code-review-graph-update-hook.sh` | コード索引の差分更新 |

- 対象は **`Write` と `Edit` の 2 つだけ**である。matcher が英数字・下線・`|` だけで
  出来ているときは正規表現にされず、`|` で分割して**完全一致**で比べられるためで、
  `NotebookEdit` のような派生ツールには一致しない。これは **Claude Code 2.1.233 で
  本体を実読して確かめた挙動**であり(記録は
  `devnotes/20260815-2015-todo-T172/matcher-semantics-evidence.md`)、
  **Claude Code を更新したら人手で再確認する**。
  台帳テスト(`ClaudeHooksWiringTest`)が固定するのは**設定に書かれた matcher 文字列だけ**で、
  本体側の判定機序が変わったことは**検出しない**(文字列が同じまま意味だけ変われば緑のままである)。
  `^(…)$` のようなアンカーは足さない(文字集合から外れて正規表現の経路へ移るだけで、
  意味論の変化を防げるわけではない)。
- 起動子は終了コードの写像器を兼ねる。**PreToolUse をブロックできるのはスクリプトが
  意図して返す 97 だけ**で、構文エラー・ファイル不在・実行不能はすべて 0 に畳まれる
  (hook の故障がセッションの Bash 操作を止めない)。
- 前提コマンド: `flock` / `timeout`(どちらも欠けると索引更新は走らず、セッションごとに
  1 行だけ告知する)。
- **`code-review-graph install` / `init` / `uninstall` を実行しないこと**。これらは MCP 設定・
  hook 配線・本ファイルへの指示注入まで行い、**配線の正本が二重化する**。配線を変えるときは
  `.claude/settings.json` と `tests/Architecture/ClaudeHooksWiringTest.php` の台帳を同じ
  変更で直す。
- 配線を変えたら**新しいセッションを開始するまで反映されない**(設定はセッション開始時に
  1 度だけ読まれる)。
<!-- CLAUDE_HOOKS_WIRING:END -->

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
- gate は CI (`supply-chain-audit` job) の **push / pull_request** で **blocking** 実行される。
  `continue-on-error` は付けない (soft-fail = 偽グリーン)。取得失敗は advisory 0 件扱いにせず
  fail-closed で止まる。**定期実行 (schedule) は持たない** — CI の責務を同期検査に限る裁定で、
  帰結として新しい advisory の検知と accept-risk の expiry 切れは**次の push まで起きない**
  (受容済み。埋め合わせに schedule を戻さない)。運用責任 (owner / 初動 SLA) と
  受容の詳細は `docs/supply-chain/review-checklist.md` §6
- 判断基準・0day 緊急時フロー・新規 npm 依存の審査観点は
  `docs/supply-chain/review-checklist.md` を参照

## worktree 運用ルール

実装は必ず worktree で行う(main 直接実装禁止)。セットアップ・破棄は
`scripts/setup-worktree.sh` / `scripts/teardown-worktree.sh` で機械的に運用する。

- **セットアップ**: `scripts/setup-worktree.sh <task-id>` が
  `.claude/worktrees/tasks/<task-id>` に worktree を作成し `todo/<task-id>` ブランチを切る
  (main 起点・ブランチ名固定、custom branch 非対応)。実行時ファイル
  (`.env` / `storage/oauth-*.key` / `.env.bughunt.local` / `public/build`)の供給、worktree 内
  `composer install --no-scripts`、`pnpm install --frozen-lockfile`、
  post-setup health check、pgsql テスト DB の ensure まで自動で行う。
  失敗時は EXIT trap が作成途中の worktree とブランチを自動削除する
  - **秘密ファイル 4 本 (`.env` / `storage/oauth-*.key` / `.env.bughunt.local`) は
    作成時点で mode 0600 に確定**して供給する(供給元の mode に追随させない)。
    `.env` は必須で、親のチェックアウトに無ければ**worktree を作らずに停止**する
    (見本ファイルでの代替はしない)。**既存の worktree には遡及しない**(新規作成分だけ 0600)。
    契約の正本は `tests/Architecture/SetupWorktreeRuntimeFilesContractTest.php`
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
- **強制撤去したときのテスト DB 回収**: `git worktree remove --force` で teardown を迂回すると
  `drop-test-db.php` を通らず**孤児テスト DB が残る**。回収は
  `php scripts/ci/drop-test-db.php --orphans`(既定 **dry-run**。列挙は SELECT のみ)。
  分類は **`Protected → Live → Foreign → Orphan → Unlabeled`** の順で確定し、
  **`Orphan` / `Unlabeled` も `--include-hash=<hash>` で 1 つずつ名指ししない限り 1 件も落ちない**
  (一括フラグは意図的に用意していない)。
  - ⚠️ **`--apply`(実 DROP)は LLM / エージェントが実行してはならない**。
    **ユーザー自身が実行するか、ユーザーが明示的に承認した場合のみ**実行できる(禁止事項 3)。
    LLM が用意してよいのは dry-run の出力までである
  - 排他の適用範囲を誇張しない: `.setup.lock` が閉じるのは**同一クローンの協調スクリプト
    (setup / teardown / sweep)間の TOCTOU だけ**。cross-clone は
    `Foreign` 分類 + `--protect-hash` + 人間承認で守る
  - 背景(NFC/NFD 重複で dirty チェックが常時失敗 → 迂回 → 孤児 DB の単調増加)と
    恒久対策(`GitIndexNormalizationTest`)は `docs/worktree-isolation-strategy.md`
- **テストレーンのグローバルロック (T099)**: `composer test` / `composer test:browser` /
  `pnpm test` / `pnpm test:packages` / `pnpm test:coverage` は**ホスト全体で 1 本ずつ**しか
  走らない (worktree 横断で直列化し、テスト DB とポートの衝突を構造的に防ぐ)
  - **待ち時間が出るのは正常**。他レーンが走っていると**エラーにはならず待つ**。
    待機中は **30 秒ごとに heartbeat** が stderr に出るので、出ている間はハングではない
  - **kill しない / ロックファイルを消さない**。中断が必要なら**ロック保持者の pid に
    `kill -TERM`** を送る (プロセスグループが空になるまで解放されない)。
    ロックファイルの手動削除は二重実行を生む
  - 手動復旧の runbook は `docs/testing-browser.md` §グローバルテストロックの手動復旧
- **背景と障害対応**: 分離設計は「**リソース名前空間** (vendor / node_modules / テスト DB /
  実行時ファイル) と **実行そのもの** (グローバルテストロック)」の 2 層構造。意図は
  `docs/worktree-isolation-strategy.md`、`enableGlobalVirtualStore` の前提・落とし穴・
  復旧手順は `docs/pnpm-global-virtual-store-runbook.md`(GVS 無効化・暗黙 peer・ENOMEM 等)

## bug-hunt (LLM 探索的バグハント、オプトイン)

`.claude/skills/app-bug-hunt/` は自由探索型の UX バグハント基盤。回帰テストでは見つからない
説明なしリダイレクト・操作詰み・IDOR・UX 破綻を、隔離 bughunt 環境 (直列 `:8010` / 並列 shard
`:8011..8014` (cap=4)、DB `bug_hunt(_N)`) で実ブラウザ走行して発見する (修正はしない)。起動は `/app-bug-hunt`。

- **オプトイン・完全 no-op**: 未使用時はアプリ実行に一切影響しない。`config/bughunt.php` と
  `BughuntCoverageMiddleware` は `env(BUGHUNT_PCOV)` + `function_exists('\pcov\start')` の二重 guard で
  pcov 未導入の本番/CI/dev では常に no-op。`BughuntOAuthSeeder` は fake_externals + bughunt.local +
  `DetectsBughuntDatabase` の DB 名判定を含む三重 fail-secure ガードで、条件不成立なら no-op
  (dev DB に認証状態をばら撒かない)。判定側の regex は残留 DB も検出するため cap より広い。
- **実行済み route の記録 (毎回 ON・fail-closed)**: 「どの操作を実際に叩けたか」は走行中に
  `BughuntExecutedRouteMiddleware` が JSONL へ機械記録する (`config/bughunt.php` の `executed.*`。
  env 既定 false + production 除外で**既定 no-op**)。web グループの**末尾**かつ priority list の
  鎖の最後に固定してあるため、記録に現れることが「遮断 middleware をすべて通過した」証拠になる
  (`BughuntExecutedRouteOrderingTest` が deny-by-default で位置を強制)。集約は
  `coverage/build_executed.py`、突合は `coverage/correlate.py` で、**主入力が揃わない走行は
  終了コード 3 で落ちる** (未実行 worklist を出さない)。テンプレートとの逸脱理由は
  `docs/template-divergence.md` D14。
- **dev DB 防御 (非交渉)**: 全 DB 操作は `scripts/bug-hunt-shard.sh` の用途別 wrapper (`env -i` で
  shell の `DB_*`/`PG*` を遮断 + DB名 regex + role guard) 経由のみ。生 artisan/psql/tinker/createdb/dropdb 禁止。
  `provision`/`teardown` は `BUGHUNT_ORCHESTRATOR=1` を持つ親のみ (worker は default-deny)。
- **パイプライン通し確認 (`pipeline-smoke`)**: `scripts/bug-hunt-shard.sh pipeline-smoke --shard I --run-id TS`
  が `dev:pipeline-smoke` を走らせ、SOP 投入 → AI 解析 → 撮影テイク → ffmpeg 合成 → mp4 の
  **全段が通ること**だけを確認する (生成物の品質は判定しない)。**LLM を 3 段とも実呼び出しするため
  実行そのものが課金である** (`--check` は preflight のみ = 費用ゼロ)。`provision`/`teardown` と同じく
  **`BUGHUNT_ORCHESTRATOR=1` を持つ親のみ**が実行でき (費用の防壁)、子 (探索エージェント) 用の
  wrapper `tmp/bug-hunt/shard-{i}-cmd.sh` には**露出しない**。段の定義・合否条件・失敗分類の語彙・
  **保証しないもの**は `docs/architecture.md` §パイプライン通し確認 が正本。
- **worktree 既定**: bug-hunt は worktree から走る (`scripts/bughunt-worktree-hook.sh` の PreToolUse ガードが
  main 直叩きを早期に止める。配線は `.claude/settings.json` に常設済み。§常設 hook 配線)。
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
   `video_manuals.status` を書き込む経路は、次の 2 分類のいずれかに属する。
   - **(i) 更新経路** (既存行の書き換え): 対象 VideoManual 行を `lockForUpdate()` で取得した
     同一トランザクション内で反映する (準拠実装: `Manual/ScenarioService::save()` /
     `Manual/ScenarioService::materializeIntoLockedManual()` /
     `Manual/AnalysisJobService::trigger()` / `Manual/AnalysisJobService::failJob()` /
     `Manual/RenderJobService::trigger()` / `Manual/RenderJobService::failJob()` /
     `Manual/RenderJobService::completeRenderIntoLockedManual()` /
     `Capture/CaptureTakeService::adopt()`・`delete()` (cuts.adopted_take_id))
   - **(ii) 生成経路** (新規 INSERT): 対象行は未存在のため、**所有元 Project 行を
     `lockForUpdate()` した同一トランザクション内で INSERT** し、初期状態
     (`status` / `scenario_version`) を **INSERT 時に明示代入する**
     (DB カラム default に依存しない = migration default 変更による silent break と、
     戻り値インスタンスの属性欠落の両方を防ぐ)。
     準拠実装: `Manual/VideoManualService::create()` / `::duplicate()`
     - **免除の範囲を広げない**: (ii) が `lockForUpdate()` を免除されるのは
       **その tx が生成した新規行の初期値 (`status` / `scenario_version`) の INSERT のみ**である。
       **生成後の行に対する後続の書き込み (`cuts` 等) は (i) 更新経路として扱い**、
       保存済みの新 manual を `lockForUpdate()` で**再取得した**同一 tx 内で行う
       (準拠実装: `duplicate()` は新 manual を save 後に `lockForUpdate()` で再取得してから
       `copyCuts()` を呼ぶ)
   経路 inventory は **`ScenarioWritePathInventoryTest` (Architecture テスト) へ昇格済み** =
   新しい書き込み経路は inventory 登録が必須。**ただし allowlist はファイル粒度**であり、
   同一ファイル内のメソッド追加は検出しない (メソッド単位の fail-first は behavioral テストが担う)。
   テイク採用 API は検出 4 (`adopted_take_id` の deny-by-default 走査) で inventory 準拠済み。
   詳細は `docs/architecture.md` §シナリオ整合の共有不変条件
2. **容量 Quota (max_storage_bytes) の予約規約**: presigned アップロードの容量判定は
   `Billing/QuotaService::checkAddition` + `Capture/StorageUsageService::occupiedBytes`
   (bytes_used + bytes_pending) 経由のみ。予約 (`take_upload_reservations`) の状態遷移は
   pending→verifying (claim)→completed/released の CAS で行い、直接 UPDATE を書かない。
   **初期状態 `pending` は INSERT 時に明示代入する** (`TakeUploadService::issue()`。
   DB カラム default に依存しない = migration default 変更による silent break と、
   `save()` 直後の in-memory instance の属性欠落の両方を防ぐ。ドメイン規約 1 (ii) と同じ理由)。
   **これは状態遷移ではないので上の CAS 規約とは独立である**。
   migration の `default('pending')` は既存行と Factory 以外の INSERT 経路のために残す。
   運用契約 (media queue worker / 孤児掃除 cron) は `docs/architecture.md` §撮影 PWA
3. **サポート対象ブラウザと履歴復元の扱い**: 「どのブラウザで何をどこまで保証しているか」の
   正本は **`docs/supported-browsers.md`**。**Inertia が描画する認証済み画面**が
   ログアウト後に復元される経路は 3 本あり、**3 枚セット**で守る
   (Filament `/admin` など非 Inertia 面は本規約の対象外):
   (A) サーバ no-store baseline (`NoStoreCacheHeadersForAuthenticatedPages`)、
   (B) クライアント bfcache 秘匿・再検証 (`resources/js/lib/bfcache-guard.ts` +
       `session.status` プローブ。撮影 PWA の主戦場 iOS Safari は
       `Cache-Control: no-store` でも bfcache に格納しうるため必須)、
   (C) Inertia history 暗号化 + 履歴鍵破棄
       (`bootstrap/app.php` の `Inertia\Middleware\EncryptHistory` +
        `Inertia::clearHistory()` の発行契機 2 つ =
        `App\Http\Responses\Fortify\LogoutResponse` (ログアウト) と
        `bootstrap/app.php` の `AuthenticationException` render callback (認証失敗))。
   (C) の保証条件は「**`clearHistory: true` を含む Inertia page をクライアントが適用したタブ**」。
   ログアウト着地 route を非 Inertia 化しない (`InertiaHistoryGuardTest` が固定) /
   ログアウト導線を非 Inertia 経路 (JSON 204 完結の XHR 等) で新設しない
   (`tests/js/architecture/logout-call-site-inventory.test.ts` が deny-by-default で固定)。
   (B) の guard / 秘匿スタイル / プローブ endpoint に**挙動変更**を入れたら、
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
5. **流量制限 (throttle) の付与規約**: 保護対象群 (未認証で到達しうる変更系 /
   ステートレスな機械向け経路 `api/`・`oauth/`・`.well-known/oauth-` / 認証面の変更系) は
   **throttle をちょうど 1 本**持つか、`ThrottleCoverageExemption` + 30 文字以上の根拠付きで
   exemption inventory へ登録する (`ThrottleCoverageInventoryTest` が deny-by-default で強制。
   exemption の**前提**は `ThrottleExemptionPremiseTest` が behavioral に固定する)。
   - named limiter のキーは **`{レーン}:{種別}:{値}`** (`RateLimiterKeyConventionTest` が
     全 limiter を実評価して検査)。email は `EmailNormalizer` → `EmailHash` を通し、
     平文をキャッシュキーに残さない。**`Str::transliterate()` は使わない**
     (legitimate な Unicode email を別 user へ collapse させ巻き添えロックアウトになる)。
     **inline throttle (`throttle:6,1`) は自前 route では使えない** (T125 で全廃)。
     inline のキーは actor id だけで route 名も limiter 名も入らないため、
     同一 actor の inline throttle route は**すべて 1 bucket を共有する** (T121 実測)。
     描画のたびに発火する GET を足すと、max が最小の route
     (`recent-auth.password` = 6) を巻き添えで 429 にして**再認証を壊す**。
     レーンを分けたいときは inline ではなく named limiter を新設する
   - **inline の残置は目録制** (T125): inline を持つ route は
     `InlineThrottleBucketRationale` + 30 文字以上の根拠で
     `InlineThrottleInventoryTest` の目録へ登録が必須 (deny-by-default)。
     残っているのは vendor 3 本のみ (`passport.token` / `passport.device.code` /
     `livewire.upload-file`)。**enum に自前 route 向けの case は 1 つも無く**、
     各 case の premise が action class の vendor 名前空間を機械検査するため、
     自前 route の inline は**登録できない** = 上の規約の機械化になっている。
     新レーンへの route 割当 (相乗り禁止) は `ThrottleLaneAssignmentTest`、
     レーンをまたぐキー衝突は `RateLimiterKeyConventionTest`、
     巻き添え 429 が消えたことの実挙動は `AuthThrottleCoverageTest` が固定する
   - vendor 登録 route への後付けは **`RouteThrottleBinder::attachOnBooted()`** 経由
     (route 名が消えたら fail-fast。効くのは**後付けが実際に走る起動すべて** =
     route cache が無い起動であり、**cached 起動では後付けごと skip されるので効かない**
     (そこで例外を投げると `route:list` が必ず落ちるため = T120)。
     cached 運用の本番で意味を持つ検出点は `route:cache` **生成時**である)。
     **`php artisan route:cache` は毎デプロイ再生成する**
     (後付けは cache 生成時に焼き込まれ cached 起動では skip されるため、stale cache は
     古い付与状態のまま起動する)。
     throttle 以外の alias 後付け(recent-auth / ensure-login-method / no-store)は
     **`RouteMiddlewareBinder::attachOnBooted()`** 経由で、**同じ前提条件に乗っている**。
     後付け経路を新設するときの契約と、入口を 2 binder に絞る
     `PostBootRouteMutationInventoryTest` の説明は
     `docs/app-integration-guide.md` **§7c** が正本
   - **閾値は既存値を変えない**。新しい面には既に本番稼働中の同性質エンドポイントと同値を充てる
   - 未認証 webhook に**固定キーの全体天井を置かない** (throttle は署名検証より前に走るため、
     無効 body の連打で正当通知を 429 にできる = 攻撃者が業務を止められる口になる)。
     IP 単位は署名検証コストの上限であり正当通知の保護ではない (429 発生率を監視する)
   - 詳細は `docs/app-integration-guide.md` §7b
6. **ジョブの重複実行と結果の一回性**: 入口の排他 (`ShouldBeUnique` / `Cache::lock`) は
   **best-effort であり保証を担わない**。結果の一回性は永続状態遷移 (条件付き UPDATE /
   悲観ロック + status guard / 予約 CAS) と外部側の冪等キーが担う。
   **取り消せない外部副作用 (LLM 呼び出し / S3 PUT / Stripe 課金) の直前には
   所有権の再検証 (preflight) を置く**。検証と外部呼び出しの間に自前の書き込みを挟まない
   (挟んだら書き込みの後にもう一度置く)。terminal 化された後に旧ワーカーが**ジョブ行**の
   状態・進捗を書き戻さないよう、進捗更新は `where status=…` の条件付き UPDATE にする。
   キューに載る全クラス (`ShouldQueue` 実装) は `JobExecutionDedupInventoryTest` の目録へ
   「保証側 (`JobDedupGuarantee` + preflight)」か「免除 (`JobDedupExemption` +
   30 文字以上の根拠)」で登録が必須 (deny-by-default)。排他 TTL / `uniqueFor` は
   保証を代替できる長さに伸ばさない (`JobExclusionOrderingInvariantTest` が
   `retry_after` 未満を固定)。**閉じない窓と運用上の所有者**は `docs/architecture.md`
   §ジョブの重複実行と結果の一回性 が正本。
7. **決済 gateway 失敗の観測語彙**: `AutoRechargeGatewayInterface` を注入されるクラスは、
   gateway 例外を **観測する (`GatewayFailureClassifier::context()` の
   `failure_class` / `error_class` の 2 キーだけをログへ載せる)** か、
   **伝播させる (`GatewayFailureObservationExemption` + 30 文字以上の根拠で免除登録)** かの
   どちらかに目録登録が必須 (`BillingGatewayFailureTaxonomyInventoryTest` が
   deny-by-default で強制)。**例外 message はログに載せない** (外部生成の可変文字列)。
   分類は**観測のためであり制御フローを変えない**。`unknown` は「写像表に一致が無かった」
   ことを意味し、写像表の値としては禁止。詳細と運用契約は
   `docs/architecture.md` §オートリチャージの失敗分類。
8. **2FA 面の step-up (recent-auth) 規約**: route 名に `two-factor` を含む route は
   **recent-auth 系 middleware をちょうど 1 種類持つ**か、`TwoFactorStepUpExemption` +
   30 文字以上の根拠付きで exemption inventory へ登録する
   (`TwoFactorStepUpInventoryTest` が deny-by-default で強制。母集団は **exact-fit**)。
   - 「1 種類」は `recent-auth` (無条件) と `recent-auth.on-email-change` (条件付き) の
     **同居**を禁じる意味である。同一 alias の重複登録は `Router::uniqueMiddleware()` が
     畳むため実行時に観測できず、検査対象にしていない (誇張しない)。
   - **exemption にできない 6 本**が gate に名指しで固定されている:
     (a) 秘密の開示 3 本 = `two-factor.qr-code` / `two-factor.secret-key` /
     `two-factor.recovery-codes`、
     (b) 第二要素の除去・差し替え 3 本 = `two-factor.enable` / `two-factor.disable` /
     `two-factor.regenerate-recovery-codes`。
     throttle (`two-factor-secret-read`) は**連続取得の回数上限**であって step-up の
     代替ではない。
   - (b) に `two-factor.enable` が入るのは、Fortify の `force=true` が seed とリカバリコードを
     再生成する一方で `two_factor_confirmed_at` を触らないためである。開けたままにすると
     **奪取セッションから永久ロックアウトを作れる**。
   - 組織管理側の 2 本 (`organizations.members.two-factor.reset` /
     `organizations.two-factor-requirement.update`) は目録の母集団には入るが
     non-exemptible 名指しには入れない (脅威系統が違い、`RecentAuthRouteTest` の
     allowlist が既に固定している)。
   - **保証範囲を誇張しない**: セレクタは名前ベースであり、`mfa.*` 等の別名で
     第二要素へ触る route には**沈黙する**。別名の route を足すときは inventory の
     母集団設計も同時に見直すこと。
   - step-up を新しい面に課すときは **satisfier の到達性**を必ず確認する。
     2FA 必須組織のゲート (`RequireTwoFactorForEnforcedOrganizations::ALLOWED_ROUTE_NAMES`) は
     password / 再SSO / **passkey** の 3 satisfier をすべて通す (どれか 1 つでも欠けると
     その手段しか持たないユーザーが詰む)。詳細は `docs/architecture.md`
     §2FA 面の step-up (recent-auth) 契約。
9. **外部到達点の目録 (標準形 v1 / 検知 v1)**: app/ から外部へ出るコード到達点は、
   種別 (`ExternalSeamKind`) を宣言して `ExternalSeamInventory::entries()` へ登録する
   (`ExternalSeamInventoryTest` が deny-by-default で強制)。対象規則は
   **決済 client の取得・構築** (`Cashier::stripe()` / `->stripe()` / `new Stripe\StripeClient`) /
   **Socialite facade** / **Http facade** / **Mail・Notification facade** の 5 種で、
   Stripe 例外クラスや値オブジェクトの参照は**規則の段階で母集団に入れない** (偽陽性を作らない)。
   - **ファイル保存 (AWS / Flysystem) と LLM (Prism) は本目録に載せない**。前者は
     `ExternalClientTimeoutInventoryTest` の到達境界目録、後者は `PromptGuardrailTest` の
     Prism 直呼び禁止が正本で、`ExternalSeamInventory::delegations()` が機械的に結線する
     (同じ到達事実を 2 箇所で宣言しない)。走査基盤は `Tests\Support\PhpReferenceScanner` に
     一本化されており、両目録は同じ namespace 解決 / alias / scope 追跡の上に立つ。
   - **SSO は `SocialiteDriverResolver` 1 クラスに名指し固定**され、他クラスからの
     `Socialite::driver()` は登録も免除もできない (集約と直呼び禁止の機械化)。
     宛先集合 (`config/template.php` の `social_providers`) の増加は
     `SocialProviderTrustPolicyTest` へ委譲する。
   - **保証範囲を誇張しない**: これは**検知**であって**遮断ではない**。
     SSO だけは別途 fake 配線 (testing / bughunt.local) で実 IdP への遷移を塞いでいるが、
     それは**本目録の効果ではない** (`ExternalFakeWiringInventory` が正本)。
     走査根は `app/` のみで `routes/` / `config/` は見ない。
     委譲先の assert の中身を弱める改変、次元そのものの数え落とし、部分修飾名、
     文字列キーの container 解決だけの経路、vendor 内部から出る通信、他種別の宛先集合、
     決済の別 API 表面、git 管理外の `.env.bughunt.local` は検出・固定できない。
     **保証しないものの完全な一覧は `docs/architecture.md` §外部到達点の目録 (標準形 v1) が正本**
     (ここは要約であり、増減はそちらで管理する)。
   - 非本番の captcha は `testing.fake_externals` で `RecaptchaVerifierTestFake` へ bind される
     (`ExternalFakeWiringInventory`)。**SSO も同じ flag で fake する**が、env allowlist は
     `testing` / `bughunt.local` のみで **`local` を除く** (認証バイパス面の最小化と
     実 IdP 連携の確認手段の温存)。
   - 詳細は `docs/architecture.md` §外部到達点の目録 (標準形 v1)。
10. **冪等キーの配線と決着規約**: `api/v1/*` の変更系 route は `idempotent` middleware を
    **ちょうど 1 本**持つか、`IdempotencyWiringExemption` + 30 文字以上の根拠で
    `IdempotentRouteCoverageTest` の目録へ登録する (deny-by-default。免除の**前提**は
    `IdempotencyExemptionPremiseTest` が behavioral に固定する)。
    - **決着は `completed` / `indeterminate` の 2 つだけ**で、release (再実行を許す) 経路を
      持たない。claim は本処理の**前**に `insertOrIgnore` で行い、調停者は
      `idempotency_keys` の既存 unique 2 本**だけ**である (cache ロックを併用しない =
      best-effort の二重機構を作らない)。帰結として **4xx/5xx の後に同じキーは
      再利用できない** (409 `idempotency_indeterminate`。破壊的契約変更)
    - **保持期間の SoT は `config/idempotency.php`** (`retention_hours`)。**env は使わない**
      (環境ごとに変えてよい運用値ではない)。クラス定数での二重管理へ戻さないこと
      (`IdempotencyContractParityTest` が `TTL_HOURS` の不在を機械固定する)
    - `Idempotent-Replayed` は**外部標準 (IETF の Idempotency-Key draft) には無い拡張**で、
      **再生応答にのみ**付与する。名前と付与条件の正本は `docs/api-idempotency.md`
    - **middleware を terminable にしない**。順序契約
      `api.project-in-org < api-key.ability < idempotent` は不変
    - **MCP 側は据え置き** (`McpIdempotencyService::store()` の unique 握り潰しは残る)。
      write tool が 0 本で到達不能なため実害は無いが「MCP も並行安全になった」とは書かない。
      最初の write tool 追加時に `McpWriteToolIdempotencyEnforcementTest` の trip-wire が
      赤くなり、必要作業 (状態機械化 / T109 解消 / behavioral テスト) を失敗メッセージで提示する
    - **保証範囲を誇張しない**: gate は `api/v1/` 配下しか見ず、web の書込 route・`oauth/*`・
      将来別 prefix の機械向け API には**沈黙する**。fatal error で `processing` が残る窓も
      閉じない (保持期間満了まで 409 が続く)。詳細は `docs/api-idempotency.md` と
      `docs/architecture.md` §冪等キーの claim と保持期間
11. **キュー投入の原子性**: 業務状態の保存とキュー投入は**同一トランザクション内**で行う
    (`afterCommit` に依存しない)。`->afterCommit()` / `DB::afterCommit` /
    `ShouldQueueAfterCommit` / `ShouldHandleEventsAfterCommit` /
    `ShouldDispatchAfterCommit` (event 側。母集団は `app/` の全クラス) /
    `$afterCommit` の truthy な既定値・promoted parameter・`= true` 代入
    (**`ShouldQueue` 実装だけでなく Mailable も** —
    Mailable は `ShouldQueue` なしでも `Mail::queue()` でキューに載る) /
    config の `after_commit => true` (sync 以外) は **すべて 0 件で pin** されている
    (`QueueDispatchAtomicityInventoryTest` が deny-by-default。allow-list は持たない =
    免除機構そのものが無い)。原子性の前提 (driver=database / キュー DB 接続 = 業務 DB /
    after_commit=false / production の既定接続が sync でない) は
    `QueueDispatchAtomicityGuard` が **全環境の起動時**に fail-closed 検査する。
    - `config/queue.php` の `sync` は **`after_commit => true` が必須**。これが無いと
      tx 内 dispatch がテストレーンで即時インライン実行され、pipeline の `startJob` が
      自分自身のロック下で成立してしまう
    - **`Queue::fake()` では原子性を検証できない** (`QueueFake::push()` は
      `enqueueUsing` を通らない)。原子性の検証は実 `jobs` 表と
      `JobQueueing` の `DB::transactionLevel()` 観測で行う (主契約は
      「action 直前の level + 1 以上」。**rollback テストは移設を検出しない**)
    - `ShouldBeUnique` は業務 tx 内 dispatch と両立しない (unique lock は dispatch 時に取得され
      rollback で解放されない)。`AutoRechargeTriggerJob` からは撤去済みで、一回性は
      永続状態遷移が担う (ドメイン規約 6)
    - **保証しないもの**: 検出は token 走査 (D1/D2/D5 の代入形) とリフレクション
      (D3/D5 の既定値) の併用で、動的な迂回 (`$m = 'afterCommit'` /
      `$this->afterCommit = $flag;`) や helper 経由の呼び出しには沈黙する。
      guard は config の値だけを見るため、`connection` 名の一致は
      「同一トランザクションに乗る」ことの**代理検査**にすぎない。
      また **「dispatch が業務 tx の内側にあること」の静的完全性は保証しない** —
      gate が固定するのは「commit 後ずらしの機構を使っていないこと」までで、
      既知経路が実際に tx 内で投入していることは behavioral test が固定する
    - 詳細は `docs/architecture.md` §キュー投入の原子性
12. **採用テイク充足判定の単一化 (T148)**: 「採用済みかつ ready のテイクを持つか」の判定式を
    書いてよいのは `Services/Manual/AdoptedReadyTakeCoverage` **ただ 1 ファイル**である
    (述語 `isMissing(Cut)`)。`adoptedTake` を参照する `app/` 配下のファイルは
    `AdoptedTakeReferenceInventory` へ区分 (`AdoptedTakeReferenceKind`) と 30 文字以上の根拠付きで
    登録する (`AdoptedReadyTakeCriterionInventoryTest` が deny-by-default + exact-fit で強制)。
    - **制裁だけが非対称で基準は同じ**: render は 422 でブロックし、preview は**ブロックしない**
      (未撮影は制作途中の正常な状態)。代わりに詳細画面 props が押す前に同じ件数を告知する。
      **必須条件未充足を理由にボタンを disabled にしない / 確認ダイアログも足さない** (禁止事項 8)
    - **告知文は述語の意味をそのまま言う**。`TakeStatus` は 4 値あるため「未撮影」と断定せず
      「撮影・処理が完了した採用テイクがありません」と書く
    - **`render_jobs.placeholder_cut_count` は生成物の説明**であり現在状態から再計算しない
      (出所は buildManifest の clips)。既存行/queued/running/failed=null、succeeded preview=実数、
      succeeded render=0。**`null` を `0` と同一視しない / backfill しない**
    - 値契約・ロック順序上の書き込み位置・保証しないものは
      `docs/architecture.md` §採用テイク充足判定の単一化と告知契約 が正本
13. **レンダ成果物の選択式の単一化 (T154)**: 「いま受け取れるレンダ成果物はどれか」を
    **1 件選ぶ**式を書いてよいのは `Services/Manual/CurrentRenderArtifact` **ただ 1 ファイル**である
    (`currentSucceeded(manual, kind)`)。定義は保持ポリシー
    (`RenderJobService::newerSucceededExists` / `DeleteRenderOutputsJob`) と**同じ世代定義**で、
    最新 succeeded の `output_path` が NULL なら**旧世代へフォールバックしない**
    (実体が削除済みのため = 壊れた署名 URL を返さない)。`app/` 配下で `render_jobs` に対する
    succeeded 条件つきの直接クエリを持つファイルは `RenderArtifactSelectionInventory` へ
    区分 (`RenderArtifactSelectionKind`) と 30 文字以上の根拠付きで登録する
    (`CurrentRenderArtifactInventoryTest` が deny-by-default + exact-fit で強制。
    `SupersessionCriterion` 区分には「`latest(`/`orderByDesc(` を持たず `where('id','>'|'<',…)` の
    連続 token 列を持つ」前提の機械検査が付く)。
    - **成果物の受け取り口は route を増やさない**。`playback` が preview と完成動画の両方を扱い、
      **kind→ability 写像は網羅 `match`** で書く (`preview` → `render` / `render` → `download`。
      到達不能な `else` を作らない)。完成動画の再生条件は download と**完全同一**
      (published + 現行世代 + download ability + **同じ評価順序**)。preview 側の 404 条件と
      ability は変えない
    - **秘匿境界は props 側**に置く。詳細画面の `render.finishedJob` は endpoint が 302 を返す条件と
      1 対 1 で、UI は `finishedJob !== null` **だけ**で判断する (`canManage` を積まない =
      判断を 2 箇所に持たない)
    - **写像は本番 policy では観測差が出ない** (`render` と `download` はどちらも
      `ProjectPolicy::update`)。テスト専用 policy を `Gate::policy` で差し込んで behavioral に固定する。
      「本番で意味のある権限差が既に存在する」とは書かない
    - 保証しないもの (撮影者は完成動画を観られない / gate はファイル粒度 / Browser lane は
      DOM 契約のみ 等) は `docs/architecture.md` §完成レンダ成果物の選択と受け取り口 が正本
14. **滞留回収の単一入口と目録 (T171 / 家系の裁定 AG-083 標準形 v1)**: 止まったまま進まない
    処理・予約を前へ進める入口は **`work:recover-stuck` ただ 1 本**で、対象は
    `--stream=<key>` で指定する (`App\Enums\Recovery\RecoveryStream` が系列と実行間隔の正本)。
    - **候補は主キーだけを返し、回収は主キーと掃引開始時刻しか受け取らない**
      (`App\Contracts\Recovery\StuckWorkStream`)。行の内容を持ち回れないので、回収側は必ず
      行を取り直して**候補列挙と同じ述語**を行ロック下で再評価することになる (誤回収の防止)。
      述語は各ドメインの Service の private に 1 か所だけ置き、系列側へ複製しない
    - 系列を増やすときは **enum の case / registry / 目録 / Schedule の 4 つを同時に**更新する
      (`StuckWorkRecoveryInventoryTest` が deny-by-default で集合一致を強制)。
      Schedule に載る他のコマンドは `NonRecoveryScheduleEntry` + 区分 + 30 文字以上の理由で
      「回収ではない定期実行」として登録が必須 = **6 本目の独自回収を素通しで足せない**
    - **既定は実行しない (数えるだけ)**。定期実行は `--apply` を明示する。付け忘れは回収が
      全面停止しても無音なので、`--apply` / `onOneServer` / `withoutOverlapping` の**有効期限** /
      `onFailure` の 4 点を上記 gate が機械固定する
    - 撤去した旧実装 (5 コマンド / 2 クラス / 2 メソッド宣言) の再流入は
      `RetiredRecoveryReferenceGateTest` が止める。**保証範囲を誇張しない** —
      変数経由の呼び出し (`$service->recoverStale()`) は字句だけでは受信側クラスを確定できないため
      対象外である
    - 監視対象は 5 つ (`errors` / `deferred` / `escalated` / `cleanup-failed` / `limit-reached`)。
      とくに **`deferred` は `errors` に出ない** (失敗を行に書き戻して次回へ回すため) ので
      独立した監視対象である。保証しないもの (500 件上限は公平性を保証しない / S3 削除に
      失敗した孤児は自動では拾えない / 実行しない指定の候補件数は上界にすぎない) は
      `docs/architecture.md` §滞留回収の共通基盤 が正本
15. **組織アクセスの失効の窓口と目録 (T174 / 家系の正典 v2)**: 組織の役割を書き込む経路は、
    **その変更と同じトランザクションの中で** `Services/OAuth/OrganizationAccessRevoker` を呼ぶか、
    `OrgAccessRevocationExemption` + 30 文字以上の根拠で免除目録へ登録する
    (`OrganizationAccessRevocationChokePointTest` が deny-by-default で強制。
    免除の件数は完全一致で pin する)。
    - **境界は「役割を変える操作が成功したこと」**で、役割の集合の差分は取らない
      (差分は役割キャッシュ依存になり、取りこぼすと通してしまう側へ倒れる)。
      帰結として **昇格でも接続はやり直しになる** (既知の仕様)。
    - 失効するのは 3 家族 (`oauth_sessions` / `oauth_access_tokens` と紐づく
      `oauth_refresh_tokens` / 未交換の `oauth_auth_codes`) で**途中で打ち切らない**。
      失効させないのは**組織の API キー**と**プロジェクト単位の役割**。
    - 監査は握り潰さない (`SecurityEventRecorder::recordOrFail`)。書けなければ役割の変更ごと
      巻き戻る。**失効 0 件でも 1 行残す**。`record()` (best-effort) と書き分け、
      失効以外に `recordOrFail()` を使わない (監査の失敗でログインを落とすことになる)。
    - **理由は観測であって制御ではない**。窓口が `$reason` を分岐に使っていないことを
      同 gate が字句で固定する。
    - 保証しないもの (発行との隙間 / API キーの読み取りが残ること / 静的検査の限界) は
      `docs/architecture.md` §組織アクセスの失効 が正本。運用向けの説明は `docs/mcp-oauth.md`
