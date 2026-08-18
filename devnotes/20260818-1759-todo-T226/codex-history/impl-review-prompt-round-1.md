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

## 静的検査 (gate) と走査器の共通規約

**対象**: `tests/Support/` 配下の検出器 / gate の中に直接書かれた走査ロジック /
それらを使う gate (`tests/Architecture/` / `tests/js/architecture/`)。
次の 5 条を満たす。家系の機能台帳の正典 v1 をそのまま写したもので、5 条とも
**「検査は緑なのに穴が開いていた」実測事故**から出ている
(設計と既存の食い違いの棚卸しは `devnotes/20260818-0303-scanner-common-conventions/`)。

**条ごとの適用範囲**: (b)〜(d) は**該当するすべての走査**に適用する。
(a) は**クラス名・名前参照を解決する走査**、(e) は**語彙一致を判定する走査**にだけ適用する
(文字列だけを見る走査に (a) は無意味であり、名前を解決する走査に (e) は無関係である)。

- **(a) クラス参照は完全修飾名で突き合わせる**。`use` / group use / 別名つき取り込みを解いた
  完全修飾名で比べる。短名一致は別名つき取り込み 1 つで検査が黙り、末尾の要素だけの一致は
  同名の別クラスを拾う。**構文解析ライブラリの使用は必須ではない** (家系の裁定 AG-154 の (2))。
  字句走査 + 取り込み対応表でよく、条件は (b) と (c) を満たすことだけである
- **(b) 解決できない形は落とす (fail-closed)**。判定を拾いすぎる方向へ倒すのは可、
  見逃す方向へ倒すのは不可。ここでいう「落とす」は**見逃さない**という意味であり、
  正常なコードを違反と断定することではない。具体的には次の 3 つを守る。
  - **未解決を解決済みと同じ値へ混ぜない**。gate が保証すると宣言した範囲の中で参照を
    解決できなかったら、**未解決だと判別できる結果**か解析の失敗として利用側へ返し、
    gate を失敗させる。**無言で候補から外さない**
  - **保証範囲の外にする構文は docblock へ明記する**。明記したなら、その構文について
    **検出力を主張しない** (明記せずに落ちこぼすのは (b) 違反である)。
    ただし**保証範囲は走査器 1 本の docblock だけでは決まらない** — 利用側 gate の名前・
    守ると宣言した不変条件・検出力の主張まで含めて判定する。
    **走査器の限界を書き足すことは、既にある見逃しを規約適合へ変えない**。
    保証範囲の外にした構文で保護対象の操作を書ける場合、利用側 gate は
    **検出力の主張をその構文を除く形へ明示的に狭める**か、**未解決として失敗させる**かのどちらかにする
  - **「違反が 0 件」と「母集団が 0 件」を区別する**。落とすのは後者だけである。
    違反ゼロが正常な gate はいくらでもあるが、**判定に使う母集団が空**なのに緑になる形は、
    走査根の改名・ディレクトリ移動・抽出条件の綴り間違いで**走査が壊れても気付けない**。
    適用対象は「母集団の非空が不変条件である gate」で、**入力を受け取って候補を返し、
    母集団の非空を契約としない再利用可能な検出器は対象外**である
    (その場合は検出器を**使う側の gate** が母集団の非空を持つ)
- **(c) 検出力は負例で裏取りする**。わざと違反させた入力を検出できることと、
  規定どおりの入力を誤検出しないことの**両方向**を固定する
- **(d) 集めた走査結果を判定に使わない形を作らない**。収集するが誰も参照しない出力、
  数えるだけで比べない目録を作らない
- **(e) 語彙一致の否定形は区切り文字で分割したトークンの完全一致で判定する**。
  正規表現の語境界や素の部分文字列一致に頼らない。
  **何を区切りとするかは走査ごとに宣言する** (準拠実装: `tests/js/support/ds-purity.ts` が
  スタイル記述を class トークンへ割る文字集合を宣言し、その文字集合で割れない書き方は
  許可一覧へ登録できないことも併せて書いている)。
  負例には最低でも**接頭辞つき・打ち消しつき・接尾辞つきの 3 形**を置く
  (許可語の除去を素の部分文字列で書いたため、この 3 形まで一緒に消えて検出漏れになっていた、
  が本リポジトリの実測である)

### 走査器・gate を新設・変更するときに同じ PR で揃える 4 点

**発火条件**: 走査ロジック・走査対象・名前解決・判定条件・目録のいずれかを新設または変更するとき。
**コメントや docblock を実態に合わせて訂正するだけで検出範囲を変えない変更は発火しない**
(既知の不適合はその場で直さず、棚卸しに記録して別 TODO で追跡する)。

1. **負例と正例**。テストファーストで**先に赤くしてから**本体を書く (思考原則 5)。
   既存の抽出器を流用して最初から緑になる場合は、負例が押さえる分岐を一時的に壊して赤を確認する
2. **解決できない形を落とす分岐** ((b))
3. **走査が空振りしていないことの検査**。母集団が空でないこと / 走査根がそれぞれ生きていること
   (準拠実装: `FfmpegProcessLaunchInventoryTest` の「母集団が空でない」検査、
   `PromptGuardrailTest` の「各走査根が解決でき、いずれも空でない」検査)
4. **docblock に走査対象と保証しないものを書く**。中身の正本は docblock 側に置き、
   本書へ写さない

### 本リポジトリでの置き方

- **走査根の単一出典**: git 追跡下の PHP 全数を母集団にする走査は
  `Tests\Support\TrackedPhpSourceFiles` を使う。同じ列挙を 2 本持たない。
  母集団がそれより狭い走査は自分の根を持ってよいが、**存在しない根は fail-fast** で落とす
  (準拠実装 `PrismDirectDispatchScanner::roots()`)
- **負例の置き場は 3 通りとも認める**: 見本ファイル (`tests/Architecture/fixtures/`) /
  検出器の自己検査 (`tests/Unit/Architecture/`) / gate 内の合成入力。
  どこに置いてもよいが、**gate または検出器の docblock から辿れること**。
  1 つへ寄せる作業に見合う効果が無いため寄せない (思考原則 2)

### 検出力の主張の書き方

「検査ファイルが実在する」と「検出力が裏取りされている」は**別物**である。
後者を主張する記述は根拠を**同じ行に併記**し、併記の無い記述は**検出力未確認**と読む。
**遡及して裏取りを付ける作業は求めない** (家系の裁定 AG-154 の (1))。

> **本節の保証範囲 (誇張しない)**: 本節は**人がレビュー時に適用する規約であり、
> 機械では強制しない**。走査器の書き方を検査する仕組み (家系の先行実装が持つ走査器の索引と、
> その索引を文書へ投影して整合を見張る検査) は**作っていない**。したがって本節があっても
> 「すべての gate が 5 条を満たしている」とは読めない。**満たしていない箇所は実在し**、
> `devnotes/20260818-0303-scanner-common-conventions/divergence-survey.md` に記録してある。
> 索引の新設を再検討する条件は同ディレクトリの概念設計に書いてある
> (新設 gate のレビューで規約の適用漏れが見つかった / 走査器候補の棚卸しをもう一度やる必要が出た /
> 全数性を主張する棚卸しが必要になった、の 3 つ)。

【思考原則 — 全議論に適用】
まず仮説を立てろ。何を検証したいのか、なぜそう考えるのか、どうなれば成功と判断するのかを明確にしてから手を動かせ。仮説なき改善はただの試行錯誤であり、結果から学ぶことができない。

データに真摯に向き合え。成果だけでなく、多様性の変化、構造の揺らぎ、想定外のパターン — 全てが判断材料になる。数値を見て即座に閾値を弄るな。何が起きているのかを理解し、なぜそうなったのかを考え、どの方向に進むべきかを判断してから手を動かせ。

先人の知恵を探せ。自分たちだけで登る必要はない。乗るべき巨人の肩があるなら乗れ。

機能の名前に立ち返れ。名前はその機能が果たすべき役割を示している。現在の設計がその役割を果たしているか、常に問え。

仕組みが機能していない段階で値を弄るな。閾値チューニングやフィールド追加は、設計の方向性が正しいと確認できてから行え。方向性が間違っているなら、値をいくら調整しても意味はない。設計そのものを見直せ。成果が出なければ早期に見切り、次の仮説へ進め。

【ツール使用制限】
コマンド実行・ファイル書き込みは一切行わず、提供されたテキストの分析に集中すること。ファイル読み込みは許可。

---

あなたは Laravel + Svelte アプリのコードレビュアーである。本 diff は **TODO T226「PHP 参照走査器の部分修飾名を fail-closed へ寄せる是正」** の実装である。

レビュー観点:
- 設計との一致性 (下記「規約 5 条」と「棚卸し D1」に照らして是正になっているか)
- 正確性 (PHP の名前解決規則を正しく写しているか。取りこぼし・誤検出の残りは無いか)
- PHPStan level 10 適合性
- テスト網羅性 (負例と正例の両方向が固定されているか。**空振り**していないか)
- セキュリティ (本走査器の上に立つのは外部到達点の目録とプロンプト防御の窓口 gate である)
- 走査器・gate の共通規約 (a)〜(e) への適合
- 過剰実装になっていないか (思考原則 2「今必要なものだけ作る」)

出力形式:
- ファイルごとに判定を書く
- 指摘は [Critical] / [Warning] / [Suggestion] に分類する
- 最後に全体判定を **APPROVED** または **CHANGES_REQUESTED** の 1 語で書く

---

## 実装の狙い (T226)

`tests/Support/PhpReferenceScanner.php` が部分修飾名 (`T_NAME_QUALIFIED`) を `ltrim($text, '\\')` するだけで
名前空間相対解決も先頭要素の別名解決も行っていなかった。利用側 gate は完全修飾名で照合するため、
参照 site は emit されているのに違反候補として認識されず**無言で見逃されていた**。これを是正した。

施策:
- S1: 部分修飾名 / 名前空間相対名 (`namespace\Foo`) を PHP の規則どおり完全修飾名まで解決する
- S2: import 表を namespace ブロックごとに作り直し、**ファイルスコープの `use` だけ**を載せる
      (クラス本体の trait 取り込みが同名短縮キーで import を上書きし FQCN を失う既知の穴を塞ぐ)
- S3: 静的呼び出しの受け手を `?string` から解決状態つきの値 `ReceiverName` へ変え、
      確定できない形 (`$var::` / `static::` / `parent::` / 式) を **Unresolved** として返す。
      利用側 gate 3 本 (外部到達点の目録 2 本 + プロンプト窓口) は未解決を**拾う側**へ倒す
- S4: 波及先の追随と、負例 / 正例の両方向のテスト
- S5: 棚卸し `divergence-survey.md` の追跡先 TODO ID 欄と `docs/architecture.md` の記述の更新

補足の実測 (php 8.4 で確認済み):
- `use` は宣言より前の参照には効かない → 走査順のまま解決してよい
- import 表は namespace ブロックごとに作り直される
- `app/` には `use` / `namespace` 宣言の外に現れる部分修飾名が **0 件**、
  受け手が未解決で対象メソッド名を呼ぶ静的呼び出しも **0 件**。よって現行の目録の中身は変わらない
  (= 是正は将来の書き方に対して効く)

## 規約 5 条 (AGENTS.md の該当節)

## 静的検査 (gate) と走査器の共通規約

**対象**: `tests/Support/` 配下の検出器 / gate の中に直接書かれた走査ロジック /
それらを使う gate (`tests/Architecture/` / `tests/js/architecture/`)。
次の 5 条を満たす。家系の機能台帳の正典 v1 をそのまま写したもので、5 条とも
**「検査は緑なのに穴が開いていた」実測事故**から出ている
(設計と既存の食い違いの棚卸しは `devnotes/20260818-0303-scanner-common-conventions/`)。

**条ごとの適用範囲**: (b)〜(d) は**該当するすべての走査**に適用する。
(a) は**クラス名・名前参照を解決する走査**、(e) は**語彙一致を判定する走査**にだけ適用する
(文字列だけを見る走査に (a) は無意味であり、名前を解決する走査に (e) は無関係である)。

- **(a) クラス参照は完全修飾名で突き合わせる**。`use` / group use / 別名つき取り込みを解いた
  完全修飾名で比べる。短名一致は別名つき取り込み 1 つで検査が黙り、末尾の要素だけの一致は
  同名の別クラスを拾う。**構文解析ライブラリの使用は必須ではない** (家系の裁定 AG-154 の (2))。
  字句走査 + 取り込み対応表でよく、条件は (b) と (c) を満たすことだけである
- **(b) 解決できない形は落とす (fail-closed)**。判定を拾いすぎる方向へ倒すのは可、
  見逃す方向へ倒すのは不可。ここでいう「落とす」は**見逃さない**という意味であり、
  正常なコードを違反と断定することではない。具体的には次の 3 つを守る。
  - **未解決を解決済みと同じ値へ混ぜない**。gate が保証すると宣言した範囲の中で参照を
    解決できなかったら、**未解決だと判別できる結果**か解析の失敗として利用側へ返し、
    gate を失敗させる。**無言で候補から外さない**
  - **保証範囲の外にする構文は docblock へ明記する**。明記したなら、その構文について
    **検出力を主張しない** (明記せずに落ちこぼすのは (b) 違反である)。
    ただし**保証範囲は走査器 1 本の docblock だけでは決まらない** — 利用側 gate の名前・
    守ると宣言した不変条件・検出力の主張まで含めて判定する。
    **走査器の限界を書き足すことは、既にある見逃しを規約適合へ変えない**。
    保証範囲の外にした構文で保護対象の操作を書ける場合、利用側 gate は
    **検出力の主張をその構文を除く形へ明示的に狭める**か、**未解決として失敗させる**かのどちらかにする
  - **「違反が 0 件」と「母集団が 0 件」を区別する**。落とすのは後者だけである。
    違反ゼロが正常な gate はいくらでもあるが、**判定に使う母集団が空**なのに緑になる形は、
    走査根の改名・ディレクトリ移動・抽出条件の綴り間違いで**走査が壊れても気付けない**。
    適用対象は「母集団の非空が不変条件である gate」で、**入力を受け取って候補を返し、
    母集団の非空を契約としない再利用可能な検出器は対象外**である
    (その場合は検出器を**使う側の gate** が母集団の非空を持つ)
- **(c) 検出力は負例で裏取りする**。わざと違反させた入力を検出できることと、
  規定どおりの入力を誤検出しないことの**両方向**を固定する
- **(d) 集めた走査結果を判定に使わない形を作らない**。収集するが誰も参照しない出力、
  数えるだけで比べない目録を作らない
- **(e) 語彙一致の否定形は区切り文字で分割したトークンの完全一致で判定する**。
  正規表現の語境界や素の部分文字列一致に頼らない。
  **何を区切りとするかは走査ごとに宣言する** (準拠実装: `tests/js/support/ds-purity.ts` が
  スタイル記述を class トークンへ割る文字集合を宣言し、その文字集合で割れない書き方は
  許可一覧へ登録できないことも併せて書いている)。
  負例には最低でも**接頭辞つき・打ち消しつき・接尾辞つきの 3 形**を置く
  (許可語の除去を素の部分文字列で書いたため、この 3 形まで一緒に消えて検出漏れになっていた、
  が本リポジトリの実測である)

### 走査器・gate を新設・変更するときに同じ PR で揃える 4 点

**発火条件**: 走査ロジック・走査対象・名前解決・判定条件・目録のいずれかを新設または変更するとき。
**コメントや docblock を実態に合わせて訂正するだけで検出範囲を変えない変更は発火しない**
(既知の不適合はその場で直さず、棚卸しに記録して別 TODO で追跡する)。

1. **負例と正例**。テストファーストで**先に赤くしてから**本体を書く (思考原則 5)。
   既存の抽出器を流用して最初から緑になる場合は、負例が押さえる分岐を一時的に壊して赤を確認する
2. **解決できない形を落とす分岐** ((b))
3. **走査が空振りしていないことの検査**。母集団が空でないこと / 走査根がそれぞれ生きていること
   (準拠実装: `FfmpegProcessLaunchInventoryTest` の「母集団が空でない」検査、
   `PromptGuardrailTest` の「各走査根が解決でき、いずれも空でない」検査)
4. **docblock に走査対象と保証しないものを書く**。中身の正本は docblock 側に置き、
   本書へ写さない

### 本リポジトリでの置き方

- **走査根の単一出典**: git 追跡下の PHP 全数を母集団にする走査は
  `Tests\Support\TrackedPhpSourceFiles` を使う。同じ列挙を 2 本持たない。
  母集団がそれより狭い走査は自分の根を持ってよいが、**存在しない根は fail-fast** で落とす
  (準拠実装 `PrismDirectDispatchScanner::roots()`)
- **負例の置き場は 3 通りとも認める**: 見本ファイル (`tests/Architecture/fixtures/`) /
  検出器の自己検査 (`tests/Unit/Architecture/`) / gate 内の合成入力。
  どこに置いてもよいが、**gate または検出器の docblock から辿れること**。
  1 つへ寄せる作業に見合う効果が無いため寄せない (思考原則 2)

### 検出力の主張の書き方

「検査ファイルが実在する」と「検出力が裏取りされている」は**別物**である。
後者を主張する記述は根拠を**同じ行に併記**し、併記の無い記述は**検出力未確認**と読む。
**遡及して裏取りを付ける作業は求めない** (家系の裁定 AG-154 の (1))。

> **本節の保証範囲 (誇張しない)**: 本節は**人がレビュー時に適用する規約であり、
> 機械では強制しない**。走査器の書き方を検査する仕組み (家系の先行実装が持つ走査器の索引と、
> その索引を文書へ投影して整合を見張る検査) は**作っていない**。したがって本節があっても
> 「すべての gate が 5 条を満たしている」とは読めない。**満たしていない箇所は実在し**、
> `devnotes/20260818-0303-scanner-common-conventions/divergence-survey.md` に記録してある。
> 索引の新設を再検討する条件は同ディレクトリの概念設計に書いてある
> (新設 gate のレビューで規約の適用漏れが見つかった / 走査器候補の棚卸しをもう一度やる必要が出た /
> 全数性を主張する棚卸しが必要になった、の 3 つ)。

## 棚卸し D1 (devnotes/20260818-0303-scanner-common-conventions/divergence-survey.md)

## D1: 部分修飾名を解決しないまま通す (`PhpReferenceScanner`) — (a) と (b) の両方に抵触

`tests/Support/PhpReferenceScanner.php` は `T_NAME_QUALIFIED` (`Foo\Bar` のような部分修飾名) を
`ltrim($text, '\\')` するだけで、**現在の名前空間への相対解決も先頭要素の別名解決も行わない**。
docblock (48-54 行) が自らこう書いている:

> `use Illuminate\Support\Facades; … Facades\Http::get()` は解決できない。
> これは既存 gate と同じ非対称であり、抽出は**振る舞い保存**が目的なのでここを直さない。

規約の観点では 2 つ問題がある。

1. (a) に反する。解決できていない名前を、あたかも解決済みの名前として emit している。
2. (b) に反する。解決できない形なのに**落とさず通す**。利用側の完全修飾名の一覧に一致しないため、
   参照 site は emit されていても**違反候補として認識されず、無言で見逃される**
   (走査器の母集団から消えるのではなく、利用側の照合の段で落ちる)。

**「docblock へ限界を書けば済む」ではない**。本走査器の上に立つ gate
(外部到達点の目録 / プロンプト防御の窓口) は、**部分修飾名を除外する形で検出力の主張を狭めていない**。
保護対象の操作は部分修飾名でも書けるので、走査器側の但し書きだけでは不変条件の穴は塞がらない。
したがって D1 は引き続き (a)・(b) 違反である。

**波及**: 本走査器を直接使う gate 6 本
(`PastDueSinceWriteInvariantTest` / `NoMessageCarrying404Test` / `LlmDefenseConfigGateTest` /
`PromptDefenseWindowGateTest` / `PromptGuardrailTest` / `AccountDeletionPathGateTest`) と、
上に乗る検出器 2 本 (`ExternalSeam\ExternalSeamScanner` / `ExternalClientBoundaryScanner`)、
さらにその先の目録 gate。**セキュリティ不変条件に直結する経路を含む**
(外部到達点の目録 / プロンプト防御の窓口)。

**扱い**: 判定の是正は本 TODO では行わない (波及が広く、規約の成文化とは別の作業量になる)。
ただし**現 docblock を放置すると、規約導入後に「規約に照らして是認済みの限界」と誤読される**。
そこで本 TODO では **docblock の文面だけ**を
「規約 **(a)・(b)** を満たしていない既知の穴であり、是正は別 TODO」と読める形へ直す (概念設計 施策 2)。

- **追跡先 TODO ID**: T226 (是正済み)
- **是正するときの設計条件** (Codex Round 1 観点 7): 未解決を通常の完全修飾名文字列へ**混ぜない**。
  判別できる値 (専用の種別を持つ site / 専用の戻り値) か明示的な例外で表す。
  `string|null` へ潰すと PHPStan level 10 と fail-closed の意図の両方を損ねる。

## 実装差分 (git diff)

```diff
diff --git a/devnotes/20260818-0303-scanner-common-conventions/divergence-survey.md b/devnotes/20260818-0303-scanner-common-conventions/divergence-survey.md
index b9688c1d..acd41bf4 100644
--- a/devnotes/20260818-0303-scanner-common-conventions/divergence-survey.md
+++ b/devnotes/20260818-0303-scanner-common-conventions/divergence-survey.md
@@ -53,7 +53,7 @@ ## D1: 部分修飾名を解決しないまま通す (`PhpReferenceScanner`) —
 そこで本 TODO では **docblock の文面だけ**を
 「規約 **(a)・(b)** を満たしていない既知の穴であり、是正は別 TODO」と読める形へ直す (概念設計 施策 2)。
 
-- **追跡先 TODO ID**: _(未採番。監督者が起票・採番し、実装者がここへ追記する。本 TODO の完了条件の 1 つ)_
+- **追跡先 TODO ID**: T226 (是正済み)
 - **是正するときの設計条件** (Codex Round 1 観点 7): 未解決を通常の完全修飾名文字列へ**混ぜない**。
   判別できる値 (専用の種別を持つ site / 専用の戻り値) か明示的な例外で表す。
   `string|null` へ潰すと PHPStan level 10 と fail-closed の意図の両方を損ねる。
@@ -149,7 +149,7 @@ ## 別 TODO として起票を申し送るもの
 
 | # | 内容 | 根拠 | 追跡先 TODO ID |
 |---|---|---|---|
-| 1 | `PhpReferenceScanner` の部分修飾名を落とす形へ寄せる (波及 6 gate + 2 検出器)。未解決は判別できる値か例外で表し、完全修飾名の文字列へ混ぜない | D1 | _(未採番)_ |
+| 1 | `PhpReferenceScanner` の部分修飾名を落とす形へ寄せる (波及 6 gate + 2 検出器)。未解決は判別できる値か例外で表し、完全修飾名の文字列へ混ぜない | D1 | T226 |
 | 2 | 空振り検査を持たない走査 gate 12 本の分類と付与 | D2 | _(未採番)_ |
 
 いずれも本 TODO のスコープ外である (`conceptual-design.md` スコープ外節)。
diff --git a/docs/architecture.md b/docs/architecture.md
index 58400c30..78b3263d 100644
--- a/docs/architecture.md
+++ b/docs/architecture.md
@@ -2116,11 +2116,15 @@ ### 保証しないもの (誇張しない。**本節が正本**)
 8. **`.env.bughunt.local` (git 管理外) の内容**。pin できるのは `.env.bughunt.local.example` まで
 9. **決済の別 API 表面**。検出は「client の取得・構築」に限り、新しい静的 helper が増えたときは
    規則の追加が要る
-10. **部分修飾名の解決**。`T_NAME_QUALIFIED` (`Facades\Http::get()` のような書き方) は
-    現在の namespace への相対解決も先頭 segment の alias 解決も行わない
-    (`ExternalClientBoundaryScanner` と同じ限界)。この限界は
-    `tests/Unit/Architecture/ExternalSeamScannerTest.php` が**テストとして明示的に固定**しており、
-    将来直すときは必ず差分が出る
+10. **import の無い短縮名**。`Thing::go()` のように `\` を含まない名前は
+    (PHP の規則では同じ名前空間の下に解決されるが) 名前参照として走査しない。
+    定数や関数名まで同じ token 種別で現れるためである。決済 / ストレージの対象は
+    いずれも `\` を含む完全修飾名なので、この制限で見逃しは起きない
+    (`PhpReferenceScanner` の docblock が正本)。
+    なお**部分修飾名** (`Facades\Http::get()`) と**受け手が未解決の静的呼び出し**
+    (`$gateway::stripe()`) は T226 で解決 / fail-closed 化済みで、
+    `tests/Unit/Architecture/PhpReferenceScannerTest.php` と
+    `tests/Unit/Architecture/ExternalSeamScannerTest.php` が両方向を固定している
 ## 冪等キーの claim と保持期間 (REST API v1 / MCP)
 
 REST API v1 の `Idempotency-Key` は **本処理の前に claim する**方式で、契約の正本は
diff --git a/tests/Architecture/AccountDeletionPathGateTest.php b/tests/Architecture/AccountDeletionPathGateTest.php
index 27ea79e6..136a20a2 100644
--- a/tests/Architecture/AccountDeletionPathGateTest.php
+++ b/tests/Architecture/AccountDeletionPathGateTest.php
@@ -416,8 +416,8 @@ function deletionPathEdges(ReferenceScanResult $result, array $tokens): array
         if ($site->kind === ReferenceKind::NameReference || $site->kind === ReferenceKind::Construction) {
             $names[] = $site->name;
         }
-        if ($site->kind === ReferenceKind::StaticCall && $site->receiver !== null) {
-            $names[] = $site->receiver;
+        if ($site->kind === ReferenceKind::StaticCall && $site->receiver->isResolved()) {
+            $names[] = $site->receiver->fqcn();
         }
     }
     foreach ($tokens as $token) {
@@ -516,10 +516,10 @@ function deletionPathClassifySite(ReferenceSite $site, array $apiMethods): ?stri
         return $site->name;
     }
 
-    if ($site->kind === ReferenceKind::StaticCall && $site->receiver !== null
-        && deletionPathIsPaymentNamespace($site->receiver)
+    if ($site->kind === ReferenceKind::StaticCall && $site->receiver->isResolved()
+        && deletionPathIsPaymentNamespace($site->receiver->fqcn())
     ) {
-        return $site->receiver.'::'.$site->name.'()';
+        return $site->receiver->fqcn().'::'.$site->name.'()';
     }
 
     if ($site->kind !== ReferenceKind::MethodCall && $site->kind !== ReferenceKind::StaticCall) {
@@ -1195,10 +1195,13 @@ class Fixture {
     expect($scan['edges'])->toContain('App\Support\Billing\SomeBillingTrait');
 });
 
-test('負のコントロール 5 形目 (b): クラス本体の use が先頭 import を上書きしても辺を失わない', function (): void {
-    // ★`PhpReferenceScanner` の alias マップは `use App\...\Foo;` と クラス本体の `use Foo;` を
-    //   同じ短縮キーで扱うため、後者が前者を上書きして FQCN を失う。alias マップを辺に使うと
-    //   **trait 経由の到達が丸ごと消える** (fail-open)。トークン直読みでこれを防いでいることを固定する。
+test('負のコントロール 5 形目 (b): クラス本体の use があっても trait 経由の辺を失わない', function (): void {
+    // ★かつては `PhpReferenceScanner` の alias マップが `use App\...\Foo;` と
+    //   クラス本体の `use Foo;` を同じ短縮キーで扱い、後者が前者を上書きして FQCN を失っていた
+    //   (alias マップを辺に使うと trait 経由の到達が丸ごと消える fail-open)。
+    //   T226 で走査器側が**ファイルスコープの import だけ**を表に載せるようになったので
+    //   上書きは起きない。本 gate はもともとトークン直読みで辺を取るため、
+    //   **どちらの前提でも辺を失わない**ことを両側から固定する。
     $fixture = <<<'PHP'
     <?php
     namespace App\Models;
@@ -1209,8 +1212,7 @@ class Fixture {
     PHP;
 
     $result = PhpReferenceScanner::references('app/Models/Fixture.php', $fixture);
-    // 前提の実測: alias マップ側は上書きで短縮名に潰れている (この前提が崩れたら本テストは不要になる)。
-    expect($result->imports['shadowedtrait'] ?? null)->toBe('ShadowedTrait');
+    expect($result->imports['shadowedtrait'] ?? null)->toBe('App\Models\Concerns\ShadowedTrait');
 
     $scan = deletionPathScanSource('app/Models/Fixture.php', $fixture);
     expect($scan['edges'])->toContain('App\Models\Concerns\ShadowedTrait');
diff --git a/tests/Architecture/PromptDefenseWindowGateTest.php b/tests/Architecture/PromptDefenseWindowGateTest.php
index 14248268..630d73ad 100644
--- a/tests/Architecture/PromptDefenseWindowGateTest.php
+++ b/tests/Architecture/PromptDefenseWindowGateTest.php
@@ -429,6 +429,38 @@ public static function make(string $key, string $value, string $name): mixed
     expect($dynamicCalls[0]->template)->toBeNull();
     expect($dynamicCalls[0]->untrustedKeys)->toBeNull();
 
+    // (f) 先頭要素を import した部分修飾名で vendor prompt を読む形
+    //     (部分修飾名を解決しなかった頃は `PrismPrompt\Prompt` のまま照合され見逃していた)
+    $partiallyQualified = <<<'PHP'
+<?php
+namespace App\Services;
+use Kent013\PrismPrompt;
+class Sneaky { public function go(): mixed { return PrismPrompt\Prompt::load('sop-extract', []); } }
+PHP;
+    expect(PromptWindowScanner::pathsOf(
+        PromptWindowScanner::scan('app/Services/Sneaky.php', $partiallyQualified),
+        PromptWindowRule::VendorPromptLoad,
+    ))->toBe(['app/Services/Sneaky.php']);
+
+    // (g) 受け手を変数にして読み込み元を隠す形 = 未解決。**fail-closed で拾う** (規約 (b))
+    $unresolvedReceiver = <<<'PHP'
+<?php
+namespace App\Services;
+class Sneaky { public function go(string $prompt): mixed { return $prompt::load('sop-extract', []); } }
+PHP;
+    expect(PromptWindowScanner::pathsOf(
+        PromptWindowScanner::scan('app/Services/Sneaky.php', $unresolvedReceiver),
+        PromptWindowRule::VendorPromptLoad,
+    ))->toBe(['app/Services/Sneaky.php']);
+
+    // 正例: 名前空間相対の同名クラス (`App\Services\PrismPrompt\Prompt`) は vendor ではない
+    $sameNamespace = <<<'PHP'
+<?php
+namespace App\Services;
+class Innocent { public function go(): mixed { return PrismPrompt\Prompt::load('note', []); } }
+PHP;
+    expect(PromptWindowScanner::scan('app/Services/Innocent.php', $sameNamespace))->toBe([]);
+
     // 正例: コメント / 文字列リテラル中の記述には反応しない (gate 自身の説明文を数えない)
     $benign = <<<'PHP'
 <?php
diff --git a/tests/Support/ExternalClientBoundaryScanner.php b/tests/Support/ExternalClientBoundaryScanner.php
index 02e60361..28286f27 100644
--- a/tests/Support/ExternalClientBoundaryScanner.php
+++ b/tests/Support/ExternalClientBoundaryScanner.php
@@ -22,6 +22,10 @@
  *   これらの token をまったく出さない経路 (`app('filesystem')` の戻りを別メソッドへ渡す等) は
  *   **検出できない**。この非対称は docs/architecture.md §外部 SDK の待ち上限の規約に明記する。
  *
+ * ★**受け手が未解決の静的呼び出しは拾う側へ倒す** (共通規約 (b))。`$requestor::setHttpClient()` の
+ *   ように FQCN を静的に決められない書き方でも大域 setter として検出する。
+ *   偽陽性が出たら目録へ登録して理由を残す形にし、**無言で候補から外さない**。
+ *
  * ★検出理由コード: `fqn_reference` / `imported_name_reference` (クラス名の参照) /
  *   `new_external_object` (**構築点**。DI で受け取るだけの消費点と区別するために種別を分ける) /
  *   `disk_call` / `get_client_call` / `stripe_global_setter`。
@@ -136,10 +140,13 @@ public static function scan(string $relativePath, string $phpSource): array
                 ),
 
                 // R6: Stripe のプロセス大域 setter
+                // ★受け手を静的に決められない形 (`$requestor::setHttpClient()` /
+                //   `static::setMaxNetworkRetries()`) も検出する = fail-closed。
+                //   完全修飾名だけを見て落とすと、変数経由に書き換えるだけで
+                //   プロセス大域状態への到達が目録から消える (共通規約 (b))。
                 $reference->kind === ReferenceKind::StaticCall
                     && in_array($reference->name, self::STRIPE_GLOBAL_SYMBOLS, true)
-                    && $reference->receiver !== null
-                    && str_starts_with($reference->receiver, 'Stripe\\') => self::fromReference($reference, 'stripe_global_setter', $reference->name, null),
+                    && ($reference->receiver->startsWith('Stripe\\') || $reference->receiver->isUnresolved()) => self::fromReference($reference, 'stripe_global_setter', $reference->name, null),
 
                 // R7: `new Aws\…` は「構築点」であり、DI で受け取るだけの消費点と区別する
                 $reference->kind === ReferenceKind::Construction && self::isTargetName($reference->name) => self::fromReference($reference, 'new_external_object', $reference->name, null),
diff --git a/tests/Support/ExternalSeam/ExternalSeamScanner.php b/tests/Support/ExternalSeam/ExternalSeamScanner.php
index d8516e3e..9fa6b93a 100644
--- a/tests/Support/ExternalSeam/ExternalSeamScanner.php
+++ b/tests/Support/ExternalSeam/ExternalSeamScanner.php
@@ -29,6 +29,10 @@
  * ★**保証範囲を誇張しない**: 検出できるのは下記 5 規則の**静的な出現**だけである。
  *   文字列キーの container 解決だけで型名も呼び出しも出さない経路は検出できない。
  *   走査根は `app/` のみで、`routes/` / `config/` は見ない。
+ *
+ * ★**受け手が未解決の静的呼び出しは採用する側へ倒す** (共通規約 (b))。`$gateway::stripe()` の
+ *   ように FQCN を静的に決められない書き方でも決済 client の取得として採用する。
+ *   採用しすぎたら目録へ登録して理由を残す形にし、**無言で候補から外さない**。
  */
 final class ExternalSeamScanner
 {
@@ -126,8 +130,12 @@ public static function scanDirectory(string $absoluteRoot, string $relativeRoot)
     private static function classify(ReferenceSite $reference): ?ExternalSeamSite
     {
         // 決済: client の取得 (static / method の両方)
+        // ★受け手を静的に決められない静的呼び出し (`$gateway::stripe()`) も採用する
+        //   = fail-closed。完全修飾名だけを見て落とすと、変数経由に書き換えるだけで
+        //   目録登録の要求を抜けられる (共通規約 (b))。
         if ($reference->name === self::CLIENT_ACCESSOR
-            && (($reference->kind === ReferenceKind::StaticCall && $reference->receiver === self::CASHIER_FACADE)
+            && (($reference->kind === ReferenceKind::StaticCall
+                && ($reference->receiver->is(self::CASHIER_FACADE) || $reference->receiver->isUnresolved()))
                 || $reference->kind === ReferenceKind::MethodCall)
         ) {
             return self::site($reference, ExternalSeamRule::PaymentClientCall, self::CLIENT_ACCESSOR);
diff --git a/tests/Support/Llm/PromptWindowScanner.php b/tests/Support/Llm/PromptWindowScanner.php
index bc8ca4e3..e7c365e4 100644
--- a/tests/Support/Llm/PromptWindowScanner.php
+++ b/tests/Support/Llm/PromptWindowScanner.php
@@ -13,6 +13,7 @@
 use Kent013\PrismPrompt\TextPrompt;
 use Kent013\PrismPrompt\Values\UserInput;
 use Tests\Support\PhpReferenceScanner;
+use Tests\Support\ReceiverName;
 use Tests\Support\ReferenceKind;
 use Tests\Support\ReferenceSite;
 use Tests\Support\ScanScopeKind;
@@ -74,8 +75,9 @@ public static function scan(string $relativePath, string $phpSource): array
     /**
      * **同じ名前空間の短縮名**を補って参照 site にする。
      *
-     * `PhpReferenceScanner` は import (`use`) が無い短縮名を解決しない (同クラスの
-     * 「名前解決の限界」。既存 gate との振る舞い保存のため中立走査器側は直さない)。
+     * `PhpReferenceScanner` は import (`use`) が無い短縮名を名前参照 site にしない
+     * (同クラスの「保証しないもの」。`true` や定数まで同じ `T_STRING` で現れるため、
+     * 短縮名を一律に site 化すると母集団が意味を失う)。
      * しかし窓口一式は `App\Support\Llm` に同居しているため、そのままでは
      * `PromptDefense.php` 内の `new GuardedPrompt(...)` や `UntrustedTextSanitizer::sanitize(...)` が
      * 1 件も見えず、**所有権の検査が空振りしたまま緑になる**。ここを補って穴を塞ぐ。
@@ -194,7 +196,7 @@ private static function reference(
             tokenIndex: $tokenIndex,
             kind: $kind,
             name: $name,
-            receiver: $receiver,
+            receiver: $receiver === null ? ReceiverName::absent() : ReceiverName::resolved($receiver),
             qualified: false,
             scopeKind: ScanScopeKind::NamedClass,
             class: null,
@@ -419,15 +421,27 @@ private static function literalValue(string $literal): string
     private static function classify(ReferenceSite $reference): ?PromptWindowSite
     {
         // `Prompt::load(` / `TextPrompt::load(` / `EmbeddingPrompt::load(`
+        // ★受け手を静的に決められない `::load(` も vendor 読み込みとして扱う = fail-closed。
+        //   窓口を迂回する経路を変数経由の書き方で隠せてはならない (共通規約 (b))。
         if ($reference->kind === ReferenceKind::StaticCall
             && $reference->name === self::VENDOR_LOAD_METHOD
-            && $reference->receiver !== null
-            && in_array($reference->receiver, self::VENDOR_PROMPT_CLASSES, true)) {
+            && $reference->receiver->isUnresolved()) {
             return new PromptWindowSite(
                 $reference->path,
                 $reference->line,
                 PromptWindowRule::VendorPromptLoad,
-                $reference->receiver.'::load',
+                '(受け手が未解決)::load',
+            );
+        }
+        if ($reference->kind === ReferenceKind::StaticCall
+            && $reference->name === self::VENDOR_LOAD_METHOD
+            && $reference->receiver->isResolved()
+            && in_array($reference->receiver->fqcn(), self::VENDOR_PROMPT_CLASSES, true)) {
+            return new PromptWindowSite(
+                $reference->path,
+                $reference->line,
+                PromptWindowRule::VendorPromptLoad,
+                $reference->receiver->fqcn().'::load',
             );
         }
 
@@ -442,7 +456,7 @@ private static function classify(ReferenceSite $reference): ?PromptWindowSite
         }
 
         // `PromptDefense::load(` / `PromptDefense::loadUnattributed(`
-        if ($reference->kind === ReferenceKind::StaticCall && $reference->receiver === PromptDefense::class) {
+        if ($reference->kind === ReferenceKind::StaticCall && $reference->receiver->is(PromptDefense::class)) {
             $rule = match ($reference->name) {
                 'load' => PromptWindowRule::WindowLoad,
                 'loadUnattributed' => PromptWindowRule::WindowLoadUnattributed,
diff --git a/tests/Support/PhpReferenceScanner.php b/tests/Support/PhpReferenceScanner.php
index a6e93f77..6fd918f6 100644
--- a/tests/Support/PhpReferenceScanner.php
+++ b/tests/Support/PhpReferenceScanner.php
@@ -47,21 +47,35 @@ public static function tokens(string $phpSource): array
      *   emit される。すなわち **1 つの静的呼び出しは NameReference と StaticCall の 2 site を生む**。
      *   利用側はどちらか一方だけを canonical にすること (両方を見ると二重検出になる)。
      *
-     * ★**名前解決の限界 = 共通規約 (a)・(b) を満たしていない既知の穴**
-     *   (`AGENTS.md` の「静的検査 (gate) と走査器の共通規約」):
-     *   `T_NAME_QUALIFIED` (`Foo\Bar` のような部分修飾名) は `ltrim($text, '\\')` するだけで、
-     *   「現在の namespace への相対解決」も「先頭 segment の alias 解決」も**行わない**。
-     *   したがって `use Illuminate\Support\Facades; … Facades\Http::get()` は解決できず、
-     *   **未解決であることを区別できない名前文字列として参照 site が emit される**。
-     *   **現在の**利用側 (対象クラスを完全修飾名で照合するもの) では、この文字列が対象一覧に
-     *   一致しないため、参照 site は存在しているのに違反候補として認識されず**無言で見逃される**
-     *   (= 見逃す側へ倒れている)。
-     *   抽出したときは**振る舞い保存**が目的でここを触らなかったが、
-     *   これは**規約に照らして是認された限界ではなく、是正待ちの穴**である
-     *   (是正すると本走査器を使う gate と派生検出器の判定結果が変わり、従来見逃していた参照の
-     *   顕在化、または未解決エラーによる新しい失敗が起こり得るため別 TODO で扱う。
-     *   棚卸しは `devnotes/20260818-0303-scanner-common-conventions/divergence-survey.md` の D1)。
-     *   **したがって部分修飾名で書かれた参照について本走査器は検出力を主張しない。**
+     * ★**名前解決の規則** (`AGENTS.md` の「静的検査 (gate) と走査器の共通規約」(a)):
+     *   emit する `name` は**必ず完全修飾名まで解決済み**である。PHP の名前解決規則をそのまま写す。
+     *   - `T_NAME_FULLY_QUALIFIED` (`\Foo\Bar`): 先頭の `\` を落とす
+     *   - `T_NAME_QUALIFIED` (`Foo\Bar` のような部分修飾名): **先頭要素を import 表で置き換える**。
+     *     一致する import が無ければ**現在の名前空間の下**に置く
+     *     (`use Illuminate\Support\Facades;` + `Facades\Http` => `Illuminate\Support\Facades\Http`、
+     *      `namespace App\Services;` + `Support\Thing` => `App\Services\Support\Thing`)
+     *   - `T_NAME_RELATIVE` (`namespace\Foo`): 現在の名前空間の下に置く
+     *   - import 済みの短縮名 / 別名: import 表で置き換える
+     *   import 表は**namespace 宣言ごとに作り直し**、**ファイルスコープの `use` だけ**を登録する
+     *   (クラス本体の `use SomeTrait;` は取り込みであって import ではない。混ぜると同名の
+     *    短縮キーでファイル先頭の import を上書きし FQCN を失う)。
+     *   `use` は宣言より前の参照には効かない (PHP 実測) ため、走査順のまま解決してよい。
+     *
+     * ★**解決できない形の扱い ((b) fail-closed)**: 静的呼び出しの受け手が変数 (`$gateway::`) /
+     *   遅延静的束縛 (`static::`) / 親クラス (`parent::`) / 式のときは FQCN を確定できない。
+     *   これを「受け手なし」と同じ値へ潰さず、`ReceiverName` が
+     *   `ReceiverResolution::Unresolved` として返す。利用側 gate は**拾いすぎる方向**へ倒して
+     *   扱うこと (完全修飾名だけを見て黙って落とすと、変数経由に書き換えるだけで検査を抜けられる)。
+     *
+     * ★**保証しないもの**: import の無い**短縮名**の参照 (`namespace App\Services;` の中の
+     *   `Thing::go()` の `Thing` のような、`\` を含まない名前) は名前参照 site として emit しない。
+     *   PHP の規則では現在の名前空間の下に解決されるが、`true` / 定数 / 関数名まで同じ
+     *   `T_STRING` で現れるため、名前参照として emit すると母集団が意味を失う。
+     *   このため**完全修飾名に `\` を含む対象** (vendor SDK / facade など) を照合する利用側は
+     *   影響を受けない (短縮名は同じファイルの名前空間の下にしか解決されず、`\` を含む対象と
+     *   一致し得ない) が、**同じ名前空間に居る対象**を照合したい利用側は自分で補うこと
+     *   (準拠実装: `Tests\Support\Llm\PromptWindowScanner::sameNamespaceReferences()`)。
+     *   なお静的呼び出しの**受け手**は必ずクラス名なので、この制限は掛からず解決する。
      */
     public static function references(string $relativePath, string $phpSource): ReferenceScanResult
     {
@@ -69,8 +83,10 @@ public static function references(string $relativePath, string $phpSource): Refe
         $count = count($tokens);
 
         $namespace = '';
-        /** @var array<string, string> $aliases short name (小文字) => FQCN */
+        /** @var array<string, string> $aliases 現在の namespace ブロックの import 表 (小文字 short name => FQCN) */
         $aliases = [];
+        /** @var array<string, string> $imports ファイル全体の import (返却用。namespace ブロックをまたいで積む) */
+        $imports = [];
 
         $braceDepth = 0;
         /** @var list<array{kind: ScanScopeKind, class: string|null, bodyDepth: int}> $scopes */
@@ -90,7 +106,12 @@ public static function references(string $relativePath, string $phpSource): Refe
             $text = $token['text'];
 
             // --- namespace 宣言 ---
+            // ★import 表は namespace ブロックごとに作り直される (PHP 実測: 前のブロックの
+            //   `use A as Sub;` は次のブロックの `Sub\Y` を解決しない)。捨てないと
+            //   別ブロックの別名で解決してしまう。
             if ($id === T_NAMESPACE) {
+                $namespace = '';
+                $aliases = [];
                 $next = $tokens[$i + 1] ?? null;
                 if ($next !== null && ($next['id'] === T_NAME_QUALIFIED || $next['id'] === T_STRING)) {
                     $namespace = $next['text'];
@@ -107,7 +128,17 @@ public static function references(string $relativePath, string $phpSource): Refe
                 if ($next !== null && $next['text'] === '(') {
                     continue;
                 }
-                $i = self::collectUseStatement($tokens, $i, $aliases);
+                /** @var array<string, string> $collected */
+                $collected = [];
+                $i = self::collectUseStatement($tokens, $i, $collected);
+                // ★クラス本体の `use SomeTrait;` は**取り込みであって import ではない**。
+                //   import 表へ混ぜると同名の短縮キーでファイル先頭の import を上書きし、
+                //   FQCN を失う (`use App\Concerns\Foo;` + クラス本体の `use Foo;` で
+                //   `foo => 'Foo'` になる)。名前解決の土台なのでファイルスコープに限る。
+                if ($scopes === []) {
+                    $aliases = array_merge($aliases, $collected);
+                    $imports = array_merge($imports, $collected);
+                }
 
                 continue;
             }
@@ -189,8 +220,8 @@ public static function references(string $relativePath, string $phpSource): Refe
             $scopeClass = $scopes === [] ? null : $scopes[count($scopes) - 1]['class'];
             $callableName = $callables === [] ? null : $callables[count($callables) - 1]['name'];
 
-            // --- 完全修飾 / 修飾名による参照 ---
-            if ($id === T_NAME_FULLY_QUALIFIED || $id === T_NAME_QUALIFIED) {
+            // --- 完全修飾 / 部分修飾 / 名前空間相対の名前による参照 ---
+            if ($id === T_NAME_FULLY_QUALIFIED || $id === T_NAME_QUALIFIED || $id === T_NAME_RELATIVE) {
                 $kind = ($tokens[$i - 1]['id'] ?? null) === T_NEW
                     ? ReferenceKind::Construction
                     : ReferenceKind::NameReference;
@@ -199,8 +230,8 @@ public static function references(string $relativePath, string $phpSource): Refe
                     line: $token['line'],
                     tokenIndex: $i,
                     kind: $kind,
-                    name: ltrim($text, '\\'),
-                    receiver: null,
+                    name: self::resolveWrittenName($id, $text, $namespace, $aliases),
+                    receiver: ReceiverName::absent(),
                     qualified: true,
                     scopeKind: $scopeKind,
                     class: $scopeClass,
@@ -230,7 +261,9 @@ class: $scopeClass,
                     tokenIndex: $i,
                     kind: ReferenceKind::StaticCall,
                     name: $text,
-                    receiver: $receiverToken === null ? null : self::resolveName($receiverToken, $aliases),
+                    receiver: $receiverToken === null
+                        ? ReceiverName::unresolved()
+                        : self::resolveReceiver($receiverToken, $namespace, $scopeClass, $aliases),
                     qualified: false,
                     scopeKind: $scopeKind,
                     class: $scopeClass,
@@ -248,7 +281,7 @@ class: $scopeClass,
                     tokenIndex: $i,
                     kind: ReferenceKind::MethodCall,
                     name: $text,
-                    receiver: null,
+                    receiver: ReceiverName::absent(),
                     qualified: false,
                     scopeKind: $scopeKind,
                     class: $scopeClass,
@@ -278,7 +311,7 @@ class: $scopeClass,
                 tokenIndex: $i,
                 kind: $previousId === T_NEW ? ReferenceKind::Construction : ReferenceKind::NameReference,
                 name: $resolved,
-                receiver: null,
+                receiver: ReceiverName::absent(),
                 qualified: false,
                 scopeKind: $scopeKind,
                 class: $scopeClass,
@@ -286,7 +319,7 @@ class: $scopeClass,
             );
         }
 
-        return new ReferenceScanResult($sites, $aliases);
+        return new ReferenceScanResult($sites, $imports);
     }
 
     /**
@@ -395,22 +428,77 @@ private static function groupPrefix(array $tokens, int $useIndex, int $braceInde
     }
 
     /**
-     * トークンをクラス名 (FQCN) として解決する。解決できなければ null。
+     * ソースに書かれた名前を PHP の名前解決規則どおり FQCN へ解決する。
+     *
+     * 部分修飾名 (`Foo\Bar`) は**先頭要素**だけが import 表の対象である
+     * (`use A\B\Foo;` + `Foo\Bar` => `A\B\Foo\Bar`)。一致する import が無ければ
+     * 現在の名前空間の下に置く (`namespace App;` + `Foo\Bar` => `App\Foo\Bar`)。
+     *
+     * @param  array<string, string>  $aliases
+     */
+    private static function resolveWrittenName(?int $id, string $text, string $namespace, array $aliases): string
+    {
+        if ($id === T_NAME_FULLY_QUALIFIED) {
+            return ltrim($text, '\\');
+        }
+
+        $separator = strpos($text, '\\');
+
+        if ($id === T_NAME_RELATIVE) {
+            // `namespace\Foo\Bar` は現在の名前空間の下を指す
+            $rest = $separator === false ? '' : substr($text, $separator + 1);
+
+            return $namespace === '' ? $rest : $namespace.'\\'.$rest;
+        }
+
+        $head = $separator === false ? $text : substr($text, 0, $separator);
+        $resolvedHead = $aliases[mb_strtolower($head)] ?? null;
+        if ($resolvedHead !== null) {
+            return $separator === false ? $resolvedHead : $resolvedHead.substr($text, $separator);
+        }
+
+        return $namespace === '' ? $text : $namespace.'\\'.$text;
+    }
+
+    /**
+     * 静的呼び出しの受け手を解決する。**確定できない形は `Unresolved` として返す** ((b) fail-closed)。
+     *
+     * `self::` は囲みのクラスが分かるので解決する。`static::` は遅延静的束縛、
+     * `parent::` は継承関係を追わないと決まらないため未解決にする。
      *
      * @param  array{id: int|null, text: string, line: int}  $token
      * @param  array<string, string>  $aliases
      */
-    private static function resolveName(array $token, array $aliases): ?string
+    private static function resolveReceiver(array $token, string $namespace, ?string $scopeClass, array $aliases): ReceiverName
     {
         $id = $token['id'];
-        if ($id === T_NAME_FULLY_QUALIFIED || $id === T_NAME_QUALIFIED) {
-            return ltrim($token['text'], '\\');
+
+        if ($id === T_NAME_FULLY_QUALIFIED || $id === T_NAME_QUALIFIED || $id === T_NAME_RELATIVE) {
+            return ReceiverName::resolved(self::resolveWrittenName($id, $token['text'], $namespace, $aliases));
         }
+
         if ($id === T_STRING) {
-            return $aliases[mb_strtolower($token['text'])] ?? null;
+            $lower = mb_strtolower($token['text']);
+            if ($lower === 'self') {
+                return $scopeClass === null ? ReceiverName::unresolved() : ReceiverName::resolved($scopeClass);
+            }
+            if ($lower === 'parent') {
+                return ReceiverName::unresolved();
+            }
+            $imported = $aliases[$lower] ?? null;
+            if ($imported !== null) {
+                return ReceiverName::resolved($imported);
+            }
+
+            // ★受け手の位置に来る短縮名は**必ずクラス名**なので、import が無ければ
+            //   現在の名前空間の下に解決してよい (定数や関数名と混ざる余地が無い)。
+            return ReceiverName::resolved(
+                $namespace === '' ? $token['text'] : $namespace.'\\'.$token['text'],
+            );
         }
 
-        return null;
+        // 変数 / `static` / 式の結果など。**null へ潰さず未解決として返す**。
+        return ReceiverName::unresolved();
     }
 
     private static function shortName(string $fqcn): string
diff --git a/tests/Support/ReceiverName.php b/tests/Support/ReceiverName.php
new file mode 100644
index 00000000..9ea5e017
--- /dev/null
+++ b/tests/Support/ReceiverName.php
@@ -0,0 +1,77 @@
+<?php
+
+declare(strict_types=1);
+
+namespace Tests\Support;
+
+use LogicException;
+
+/**
+ * 静的呼び出しの受け手 (receiver) の解決結果。
+ *
+ * ★**`string|null` にしない**。null は「受け手が無い」とも「解決できなかった」とも読めるため、
+ *   利用側が `!== null` だけを見て未解決を落とす形 (= 無言の見逃し) を許してしまう。
+ *   完全修飾名は `fqcn()` からしか取り出せず、未解決のまま取り出すと例外になるので、
+ *   **解決状態を見ずに照合へ進むことが構造的にできない**。
+ */
+final readonly class ReceiverName
+{
+    private function __construct(
+        public ReceiverResolution $resolution,
+        private ?string $value,
+    ) {}
+
+    /** 完全修飾名まで解決できた受け手。 */
+    public static function resolved(string $fqcn): self
+    {
+        return new self(ReceiverResolution::Resolved, $fqcn);
+    }
+
+    /** 受け手は書かれているが静的に確定できない (変数 / `static` / `parent` / 式)。 */
+    public static function unresolved(): self
+    {
+        return new self(ReceiverResolution::Unresolved, null);
+    }
+
+    /** 受け手を持たない種別。 */
+    public static function absent(): self
+    {
+        return new self(ReceiverResolution::Absent, null);
+    }
+
+    public function isResolved(): bool
+    {
+        return $this->resolution === ReceiverResolution::Resolved;
+    }
+
+    public function isUnresolved(): bool
+    {
+        return $this->resolution === ReceiverResolution::Unresolved;
+    }
+
+    /** 解決済みの完全修飾名。未解決 / 受け手なしで呼ぶのは利用側の誤りなので例外にする。 */
+    public function fqcn(): string
+    {
+        if ($this->value === null) {
+            throw new LogicException(
+                '受け手が解決できていない site から完全修飾名を取り出そうとしました '
+                .'(解決状態: '.$this->resolution->name.')。'
+                .'照合の前に isResolved() / isUnresolved() で分岐してください。',
+            );
+        }
+
+        return $this->value;
+    }
+
+    /** 解決済みで、かつ指定の完全修飾名と一致するか。 */
+    public function is(string $fqcn): bool
+    {
+        return $this->value === $fqcn;
+    }
+
+    /** 解決済みで、かつ指定の名前空間接頭辞の下にあるか。 */
+    public function startsWith(string $prefix): bool
+    {
+        return $this->value !== null && str_starts_with($this->value, $prefix);
+    }
+}
diff --git a/tests/Support/ReceiverResolution.php b/tests/Support/ReceiverResolution.php
new file mode 100644
index 00000000..74bb428d
--- /dev/null
+++ b/tests/Support/ReceiverResolution.php
@@ -0,0 +1,30 @@
+<?php
+
+declare(strict_types=1);
+
+namespace Tests\Support;
+
+/**
+ * 静的呼び出しの受け手 (receiver) を完全修飾名まで解決できたか。
+ *
+ * ★**未解決を「受け手が無い」と同じ値へ潰さない**。潰すと利用側は
+ *   「見なくてよい site」と「解決できなかった site」を区別できず、
+ *   `$client::setHttpClient()` のような書き方が**無言で候補から外れる**
+ *   (`AGENTS.md` の共通規約 (b) が禁じる形)。
+ */
+enum ReceiverResolution
+{
+    /** 完全修飾名まで解決できた。 */
+    case Resolved;
+
+    /**
+     * 受け手は書かれているが、静的には確定できない。
+     *
+     * 変数 (`$gateway::`) / 遅延静的束縛 (`static::`) / 親クラス (`parent::`) /
+     * 式の結果 (`foo()::`) など。利用側は**拾いすぎる方向**へ倒して扱う。
+     */
+    case Unresolved;
+
+    /** そもそも受け手を持たない種別 (`NameReference` / `Construction` / `MethodCall`)。 */
+    case Absent;
+}
diff --git a/tests/Support/ReferenceSite.php b/tests/Support/ReferenceSite.php
index e6ab1373..c557a842 100644
--- a/tests/Support/ReferenceSite.php
+++ b/tests/Support/ReferenceSite.php
@@ -10,6 +10,8 @@
  * ★`tokenIndex` を持たせるのは、呼び出し引数の分類 (`ExternalClientBoundaryScanner` の
  *   disk 名判定) のように「site の直後のトークン列」を見たい利用者があるため。
  *   走査器の内部表現を漏らさずに済ませる唯一の実用的な逃げ道である。
+ * ★`receiver` は**解決状態つきの値** (`ReceiverName`) である。完全修飾名を取り出すには
+ *   解決済みかどうかを見るしかなく、未解決を黙って候補から外す書き方ができない。
  */
 final readonly class ReferenceSite
 {
@@ -20,8 +22,8 @@ public function __construct(
         public ReferenceKind $kind,
         /** 名前参照 / 構築なら解決済み FQCN、呼び出しならメソッド名 */
         public string $name,
-        /** 呼び出しの receiver を解決できた場合の FQCN (できなければ null) */
-        public ?string $receiver,
+        /** 静的呼び出しの受け手 (解決結果。受け手を持たない種別は `ReceiverName::absent()`) */
+        public ReceiverName $receiver,
         /** 名前が完全修飾 / 修飾名として書かれていたか (alias 経由なら false) */
         public bool $qualified,
         public ScanScopeKind $scopeKind,
diff --git a/tests/Unit/Architecture/ExternalClientBoundaryScannerTest.php b/tests/Unit/Architecture/ExternalClientBoundaryScannerTest.php
index d1c8f948..0cbd8bad 100644
--- a/tests/Unit/Architecture/ExternalClientBoundaryScannerTest.php
+++ b/tests/Unit/Architecture/ExternalClientBoundaryScannerTest.php
@@ -285,3 +285,62 @@ class Sample { public function f(SnsClient $s): S3Client { return new S3Client([
         ['rule' => 'new_external_object', 'name' => 'Aws\S3\S3Client', 'class' => 'App\Gate\Sample', 'scope' => 'NamedClass'],
     ]);
 });
+
+test('先頭要素を import した部分修飾名 (S3\S3Client) を解決して検出する', function (): void {
+    // T226: 部分修飾名を解決しなかった頃は `S3\S3Client` のまま照合され、
+    // 到達境界の接頭辞 `Aws\` に一致せず**無言で見逃されていた**。
+    $source = <<<'PHP'
+    <?php
+    namespace App\Gate;
+    use Aws\S3;
+    class Sample { public function f(): void { $client = new S3\S3Client([]); } }
+    PHP;
+
+    expect(scannerSummary(ExternalClientBoundaryScanner::scan('app/Gate/Sample.php', $source)))->toBe([
+        ['rule' => 'new_external_object', 'name' => 'Aws\S3\S3Client', 'class' => 'App\Gate\Sample', 'scope' => 'NamedClass'],
+    ]);
+});
+
+test('名前空間相対の部分修飾名を到達境界と取り違えない', function (): void {
+    // 先頭要素の import が無い部分修飾名は**現在の名前空間の下**に解決される。
+    // 解決しなかった頃は字面 `Aws\Bridge` が接頭辞 `Aws\` に一致し、
+    // 自前クラスを到達境界として**誤検出**していた。
+    $source = <<<'PHP'
+    <?php
+    namespace App\Gate;
+    class Sample { public function f(): void { $bridge = new Aws\Bridge(); } }
+    PHP;
+
+    expect(scannerSummary(ExternalClientBoundaryScanner::scan('app/Gate/Sample.php', $source)))->toBe([]);
+});
+
+test('受け手を静的に決められない大域 setter は fail-closed で検出する', function (): void {
+    // 受け手が変数 / 遅延静的束縛の静的呼び出しは FQCN を確定できない。
+    // **未解決を黙って候補から外さない** (規約 (b))。変数経由に書き換えるだけで
+    // プロセス大域状態への到達が目録から消えては困る。
+    $source = <<<'PHP'
+    <?php
+    namespace App\Gate;
+    class Sample {
+        public function f(string $requestor): void {
+            $requestor::setHttpClient($this->client);
+            static::setMaxNetworkRetries(0);
+        }
+    }
+    PHP;
+
+    expect(array_column(ExternalClientBoundaryScanner::stripeGlobalSites('app/Gate/Sample.php', $source), 'name'))
+        ->toBe(['setHttpClient', 'setMaxNetworkRetries']);
+});
+
+test('同じ名前空間の裸の受け手は解決され、大域 setter と取り違えない', function (): void {
+    // import の無い短縮名の受け手は現在の名前空間の下に解決される
+    // (`App\Gate\Registry`)。Stripe 名前空間ではないので検出しない。
+    $source = <<<'PHP'
+    <?php
+    namespace App\Gate;
+    class Sample { public function f(): void { Registry::instance(); } }
+    PHP;
+
+    expect(ExternalClientBoundaryScanner::stripeGlobalSites('app/Gate/Sample.php', $source))->toBe([]);
+});
diff --git a/tests/Unit/Architecture/ExternalSeamScannerTest.php b/tests/Unit/Architecture/ExternalSeamScannerTest.php
index 0661a413..4fce4acf 100644
--- a/tests/Unit/Architecture/ExternalSeamScannerTest.php
+++ b/tests/Unit/Architecture/ExternalSeamScannerTest.php
@@ -106,22 +106,34 @@ public function go(object $client): mixed
 });
 
 test('走査器: new Stripe\StripeClient を payment_client_construction として検出する', function (): void {
+    // ★見本は完全修飾と import の 2 形で書く。`namespace App\Services\Billing;` の中で
+    //   `new Stripe\StripeClient(...)` と書くと PHP は
+    //   `App\Services\Billing\Stripe\StripeClient` を指すので、決済 client の見本にならない
+    //   (部分修飾名を解決するようになって初めてこの取り違えが見える)。
     $source = <<<'PHP'
     <?php
     namespace App\Services\Billing;
+    use Stripe\StripeClient;
     final class Probe
     {
         public function go(): mixed
         {
-            return new Stripe\StripeClient(['api_key' => 'sk_test']);
+            return new StripeClient(['api_key' => 'sk_test']);
+        }
+
+        public function goQualified(): mixed
+        {
+            return new \Stripe\StripeClient(['api_key' => 'sk_test']);
         }
     }
     PHP;
 
     $result = ExternalSeamScanner::scan('probe.php', $source);
 
-    expect(externalSeamRuleValues(...$result->adopted))
-        ->toBe([ExternalSeamRule::PaymentClientConstruction->value]);
+    expect(externalSeamRuleValues(...$result->adopted))->toBe([
+        ExternalSeamRule::PaymentClientConstruction->value,
+        ExternalSeamRule::PaymentClientConstruction->value,
+    ]);
 });
 
 test('走査器: Stripe\HttpClient\CurlClient の new は検出しない', function (): void {
@@ -430,10 +442,9 @@ public function go(): mixed
         ->and($result->adopted[0]->callable)->toBe('go');
 });
 
-test('走査器: 部分修飾名は解決しない (既存 gate と同じ限界を固定する)', function (): void {
-    // T_NAME_QUALIFIED は現在の namespace への相対解決も先頭 segment の alias 解決も
-    // 行わない。既存 ExternalClientBoundaryScanner と同じ限界であり、抽出は
-    // 振る舞い保存が目的なので直さない (直すと T126 の母集団が変わる)。
+test('走査器: 先頭要素を import した部分修飾名 (Facades\Http) を検出する', function (): void {
+    // T_NAME_QUALIFIED は先頭要素を import 表で置き換えて解決する。
+    // 解決しなかった頃はこの形が目録に出ず、外部到達点が無言で見逃されていた (T226 で是正)。
     $source = <<<'PHP'
     <?php
     namespace App\Services;
@@ -449,7 +460,72 @@ public function go(): mixed
 
     $result = ExternalSeamScanner::scan('probe.php', $source);
 
-    expect($result->adopted)->toBe([]);
+    expect(externalSeamRuleValues(...$result->adopted))->toBe([ExternalSeamRule::HttpFacadeReference->value])
+        ->and($result->adopted[0]->class)->toBe('App\Services\Probe')
+        ->and($result->adopted[0]->callable)->toBe('go');
+});
+
+test('走査器: 先頭要素を import した部分修飾名の Cashier\Cashier::stripe() を検出する', function (): void {
+    $source = <<<'PHP'
+    <?php
+    namespace App\Services\Billing;
+    use Laravel\Cashier;
+    final class Probe
+    {
+        public function go(): mixed
+        {
+            return Cashier\Cashier::stripe();
+        }
+    }
+    PHP;
+
+    $result = ExternalSeamScanner::scan('probe.php', $source);
+
+    expect(externalSeamRuleValues(...$result->adopted))->toBe([ExternalSeamRule::PaymentClientCall->value])
+        ->and($result->suppressed)->toBe([]);
+});
+
+test('走査器: 受け手を静的に決められない ::stripe() は fail-closed で採用する', function (): void {
+    // 受け手が変数の静的呼び出しは FQCN を確定できない。**未解決を黙って候補から外さない**
+    // (規約 (b))。決済 client の取り出しを変数経由に書き換えるだけで目録を抜けられては困る。
+    $source = <<<'PHP'
+    <?php
+    namespace App\Services\Billing;
+    final class Probe
+    {
+        public function go(string $gateway): mixed
+        {
+            return $gateway::stripe();
+        }
+    }
+    PHP;
+
+    $result = ExternalSeamScanner::scan('probe.php', $source);
+
+    expect(externalSeamRuleValues(...$result->adopted))->toBe([ExternalSeamRule::PaymentClientCall->value])
+        ->and($result->suppressed)->toBe([]);
+});
+
+test('走査器: 名前空間相対の部分修飾名を外部到達点と取り違えない', function (): void {
+    // 先頭要素の import が無い部分修飾名は**現在の名前空間の下**に解決される。
+    // `App\Services\Billing\Cashier\Cashier` は決済 facade ではないので採用しない。
+    $source = <<<'PHP'
+    <?php
+    namespace App\Services\Billing;
+    final class Probe
+    {
+        public function go(): mixed
+        {
+            return Cashier\Cashier::stripe();
+        }
+    }
+    PHP;
+
+    $result = ExternalSeamScanner::scan('probe.php', $source);
+
+    // `stripe` はメソッド名一致でも拾う規則を持たない (静的呼び出しは receiver 一致が要る)。
+    expect($result->adopted)->toBe([])
+        ->and($result->suppressed)->toBe([]);
 });
 
 test('走査器: 同名 alias (use ... as Http) を解決する', function (): void {
diff --git a/tests/Unit/Architecture/PhpReferenceScannerTest.php b/tests/Unit/Architecture/PhpReferenceScannerTest.php
new file mode 100644
index 00000000..903cb3b4
--- /dev/null
+++ b/tests/Unit/Architecture/PhpReferenceScannerTest.php
@@ -0,0 +1,294 @@
+<?php
+
+declare(strict_types=1);
+
+use Tests\Support\PhpReferenceScanner;
+use Tests\Support\ReceiverResolution;
+use Tests\Support\ReferenceKind;
+use Tests\Support\ReferenceSite;
+
+/*
+ * 中立走査器 `PhpReferenceScanner` の**名前解決**を合成ソースで固定する (T226)。
+ *
+ * ★負例 (わざと部分修飾で書いた参照を解決できること) と
+ *   正例 (名前空間相対の同名クラスを外部クラスと取り違えないこと) の**両方向**を置く
+ *   (`AGENTS.md` の共通規約 (c))。
+ * ★受け手を静的に決められない静的呼び出しは `ReceiverResolution::Unresolved` として返る。
+ *   **無言で候補から外さない**ことがこの走査器の契約である (同 (b))。
+ *   利用側でそれがどう効くかは `ExternalSeamScannerTest` /
+ *   `ExternalClientBoundaryScannerTest` が押さえている。
+ * ★期待値は PHP 自身の名前解決規則と同じである (`namespace` ブロックごとの import 表の
+ *   作り直し / `use` は宣言より前の参照に効かないこと、はいずれも php 8.4 で実測した)。
+ */
+
+/**
+ * 名前参照 / 構築の site 名だけを取り出す。
+ *
+ * @param  list<ReferenceSite>  $sites
+ * @return list<string>
+ */
+function referenceNames(array $sites): array
+{
+    return array_values(array_map(
+        static fn (ReferenceSite $site): string => $site->name,
+        array_filter(
+            $sites,
+            static fn (ReferenceSite $site): bool => $site->kind === ReferenceKind::NameReference
+                || $site->kind === ReferenceKind::Construction,
+        ),
+    ));
+}
+
+/**
+ * 静的呼び出しの site を「メソッド名 => 受け手の解決状態」で取り出す。
+ *
+ * @param  list<ReferenceSite>  $sites
+ * @return list<array{name: string, resolution: string, receiver: string|null}>
+ */
+function staticCallReceivers(array $sites): array
+{
+    return array_values(array_map(
+        static fn (ReferenceSite $site): array => [
+            'name' => $site->name,
+            'resolution' => $site->receiver->resolution->name,
+            'receiver' => $site->receiver->isResolved() ? $site->receiver->fqcn() : null,
+        ],
+        array_filter($sites, static fn (ReferenceSite $site): bool => $site->kind === ReferenceKind::StaticCall),
+    ));
+}
+
+// ── 部分修飾名の解決 (負例: 従来は解決できず見逃していた形) ─────────────
+
+test('先頭要素を import した部分修飾名を完全修飾名まで解決する', function (): void {
+    $source = <<<'PHP'
+    <?php
+    namespace App\Services;
+    use Illuminate\Support\Facades;
+    final class Probe
+    {
+        public function go(): mixed
+        {
+            return Facades\Http::get('https://example.test');
+        }
+    }
+    PHP;
+
+    $result = PhpReferenceScanner::references('app/Services/Probe.php', $source);
+
+    expect(referenceNames($result->sites))->toBe(['Illuminate\Support\Facades\Http'])
+        ->and(staticCallReceivers($result->sites))->toBe([
+            ['name' => 'get', 'resolution' => 'Resolved', 'receiver' => 'Illuminate\Support\Facades\Http'],
+        ]);
+});
+
+test('別名で import した先頭要素の部分修飾名を解決する', function (): void {
+    $source = <<<'PHP'
+    <?php
+    namespace App\Services;
+    use Illuminate\Support\Facades as F;
+    final class Probe
+    {
+        public function go(): mixed
+        {
+            return new F\Http();
+        }
+    }
+    PHP;
+
+    expect(referenceNames(PhpReferenceScanner::references('app/Services/Probe.php', $source)->sites))
+        ->toBe(['Illuminate\Support\Facades\Http']);
+});
+
+test('グループ use で取り込んだ先頭要素に部分修飾名を続ける形を解決する', function (): void {
+    $source = <<<'PHP'
+    <?php
+    namespace App\Services;
+    use Aws\{S3, Sns};
+    final class Probe
+    {
+        public function go(): mixed
+        {
+            return new S3\S3Client([]);
+        }
+    }
+    PHP;
+
+    expect(referenceNames(PhpReferenceScanner::references('app/Services/Probe.php', $source)->sites))
+        ->toBe(['Aws\S3\S3Client']);
+});
+
+test('import の無い部分修飾名は現在の名前空間の下に解決する (正例: 取り違えない)', function (): void {
+    $source = <<<'PHP'
+    <?php
+    namespace App\Services;
+    final class Probe
+    {
+        public function go(): mixed
+        {
+            return new Aws\Bridge();
+        }
+    }
+    PHP;
+
+    expect(referenceNames(PhpReferenceScanner::references('app/Services/Probe.php', $source)->sites))
+        ->toBe(['App\Services\Aws\Bridge']);
+});
+
+test('名前空間を持たないファイルの部分修飾名はそのまま大域の名前になる', function (): void {
+    $source = <<<'PHP'
+    <?php
+    $client = new Aws\Bridge();
+    PHP;
+
+    expect(referenceNames(PhpReferenceScanner::references('routes/web.php', $source)->sites))
+        ->toBe(['Aws\Bridge']);
+});
+
+test('名前空間相対の名前 (namespace\Foo) を解決して site にする', function (): void {
+    // 従来は `T_NAME_RELATIVE` を 1 件も emit していなかった = 無言の取りこぼし。
+    $source = <<<'PHP'
+    <?php
+    namespace App\Services;
+    final class Probe
+    {
+        public function go(): mixed
+        {
+            return new namespace\Helper();
+        }
+    }
+    PHP;
+
+    expect(referenceNames(PhpReferenceScanner::references('app/Services/Probe.php', $source)->sites))
+        ->toBe(['App\Services\Helper']);
+});
+
+test('完全修飾名は先頭の区切りだけを落とす (従来どおり)', function (): void {
+    $source = <<<'PHP'
+    <?php
+    namespace App\Services;
+    final class Probe
+    {
+        public function go(): mixed
+        {
+            return new \Aws\S3\S3Client([]);
+        }
+    }
+    PHP;
+
+    expect(referenceNames(PhpReferenceScanner::references('app/Services/Probe.php', $source)->sites))
+        ->toBe(['Aws\S3\S3Client']);
+});
+
+// ── import 表の作り方 ─────────────────────────────────────────────────
+
+test('import 表は namespace ブロックごとに作り直す', function (): void {
+    // php 8.4 実測: 前のブロックの `use ... as Sub;` は次のブロックの `Sub\Y` を解決しない。
+    $source = <<<'PHP'
+    <?php
+    namespace First { use Aws\S3 as Sub; }
+    namespace Second {
+        final class Probe
+        {
+            public function go(): mixed
+            {
+                return new Sub\S3Client([]);
+            }
+        }
+    }
+    PHP;
+
+    expect(referenceNames(PhpReferenceScanner::references('app/Probe.php', $source)->sites))
+        ->toBe(['Second\Sub\S3Client']);
+});
+
+test('クラス本体の use (trait 取り込み) は import 表を上書きしない', function (): void {
+    // 上書きすると `billable => 'Billable'` になり、ファイル先頭の import が持つ FQCN を失う
+    // (= trait 経由の参照が丸ごと消える fail-open)。
+    $source = <<<'PHP'
+    <?php
+    namespace App\Models;
+    use Laravel\Cashier\Billable;
+    final class Organization
+    {
+        use Billable;
+
+        public function go(): Billable
+        {
+            return $this;
+        }
+    }
+    PHP;
+
+    $result = PhpReferenceScanner::references('app/Models/Organization.php', $source);
+
+    expect($result->imports)->toBe(['billable' => 'Laravel\Cashier\Billable'])
+        ->and(referenceNames($result->sites))->toBe(['Laravel\Cashier\Billable']);
+});
+
+// ── 静的呼び出しの受け手 (fail-closed) ────────────────────────────────
+
+test('受け手を静的に決められない静的呼び出しは未解決として返す', function (): void {
+    $source = <<<'PHP'
+    <?php
+    namespace App\Services;
+    final class Probe extends Base
+    {
+        public function go(string $gateway): void
+        {
+            $gateway::make();
+            static::make();
+            parent::make();
+        }
+    }
+    PHP;
+
+    expect(staticCallReceivers(PhpReferenceScanner::references('app/Services/Probe.php', $source)->sites))->toBe([
+        ['name' => 'make', 'resolution' => 'Unresolved', 'receiver' => null],
+        ['name' => 'make', 'resolution' => 'Unresolved', 'receiver' => null],
+        ['name' => 'make', 'resolution' => 'Unresolved', 'receiver' => null],
+    ]);
+});
+
+test('self:: は囲みのクラスへ、import の無い短縮名は現在の名前空間の下へ解決する', function (): void {
+    $source = <<<'PHP'
+    <?php
+    namespace App\Services;
+    final class Probe
+    {
+        public function go(): void
+        {
+            self::make();
+            Registry::make();
+        }
+    }
+    PHP;
+
+    expect(staticCallReceivers(PhpReferenceScanner::references('app/Services/Probe.php', $source)->sites))->toBe([
+        ['name' => 'make', 'resolution' => 'Resolved', 'receiver' => 'App\Services\Probe'],
+        ['name' => 'make', 'resolution' => 'Resolved', 'receiver' => 'App\Services\Registry'],
+    ]);
+});
+
+test('受け手を持たない種別の receiver は Absent で、完全修飾名を取り出すと例外になる', function (): void {
+    $source = <<<'PHP'
+    <?php
+    namespace App\Services;
+    final class Probe
+    {
+        public function go(object $client): mixed
+        {
+            return $client->send();
+        }
+    }
+    PHP;
+
+    $sites = PhpReferenceScanner::references('app/Services/Probe.php', $source)->sites;
+    $methodCalls = array_values(array_filter(
+        $sites,
+        static fn (ReferenceSite $site): bool => $site->kind === ReferenceKind::MethodCall,
+    ));
+
+    expect($methodCalls)->toHaveCount(1)
+        ->and($methodCalls[0]->receiver->resolution)->toBe(ReceiverResolution::Absent)
+        ->and(static fn (): string => $methodCalls[0]->receiver->fqcn())->toThrow(LogicException::class);
+});

```

## テスト結果

- `vendor/bin/pest tests/Architecture/` : 1102 passed / 0 failed (assertions 8066)
- `vendor/bin/pest tests/Unit/Architecture/` : 279 passed / 0 failed
- `composer phpstan` (level 10): No errors
- `vendor/bin/pint --test`: passed
- 赤の確認: 変更前の走査器に対して新規負例 6 件が失敗することを実測済み
  (部分修飾名 5 件 + 名前空間相対の誤検出 1 件)。
  さらに解決処理を一時的に旧挙動へ戻して `PhpReferenceScannerTest` が 5 件赤くなることも確認した

