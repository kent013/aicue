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
5. LLM 呼び出しの Prism 直呼び(`app/Prompts/` の factory 経由のみ。PromptGuardrailTest が検出)。
   **実行経路を持つ prompt factory は `LlmCallContextData` を必須引数で受け、
   `->withMetadata($context->toMetadata())` で帰属 (organization / subject) を付ける** — 付け忘れは
   PHPStan level 10 が落とす。帰属の対象を持たない見本 (`ExampleSummaryPrompt`) は
   `PromptUntrustedInputContractTest` の inventory へ**帰属キーを空配列で exempt 登録**する
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
4. **untrusted 文字列は UserInput 型経由でのみ prompt に入れる**
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

【思考原則 — 全議論に適用】
まず仮説を立てろ。何を検証したいのか、なぜそう考えるのか、どうなれば成功と判断するのかを明確にしてから手を動かせ。仮説なき改善はただの試行錯誤であり、結果から学ぶことができない。

データに真摯に向き合え。成果だけでなく、多様性の変化、構造の揺らぎ、想定外のパターン — 全てが判断材料になる。数値を見て即座に閾値を弄るな。何が起きているのかを理解し、なぜそうなったのかを考え、どの方向に進むべきかを判断してから手を動かせ。

先人の知恵を探せ。自分たちだけで登る必要はない。乗るべき巨人の肩があるなら乗れ。

機能の名前に立ち返れ。名前はその機能が果たすべき役割を示している。現在の設計がその役割を果たしているか、常に問え。

仕組みが機能していない段階で値を弄るな。閾値チューニングやフィールド追加は、設計の方向性が正しいと確認できてから行え。方向性が間違っているなら、値をいくら調整しても意味はない。設計そのものを見直せ。成果が出なければ早期に見切り、次の仮説へ進め。

【ツール使用制限】
コマンド実行・ファイル書き込みは一切行わず、提供されたテキストの分析に集中すること。ファイル読み込みは許可。

【アプリのドメイン固有規約 (抜粋・レビューに必要なため添付)】
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
   - **SSO は `SocialAuthController` 1 クラスに名指し固定**され、他クラスからの
     `Socialite::driver()` は登録も免除もできない (集約と直呼び禁止の機械化)。
     宛先集合 (`config/template.php` の `social_providers`) の増加は
     `SocialProviderTrustPolicyTest` へ委譲する。
   - **保証範囲を誇張しない**: これは**検知**であって**遮断ではない**。bug-hunt のブラウザは
     SSO ボタンから実 IdP へ遷移する。走査根は `app/` のみで `routes/` / `config/` は見ない。
     委譲先の assert の中身を弱める改変、次元そのものの数え落とし、部分修飾名、
     文字列キーの container 解決だけの経路、vendor 内部から出る通信、他種別の宛先集合、
     決済の別 API 表面、git 管理外の `.env.bughunt.local` は検出・固定できない。
     **保証しないものの完全な一覧は `docs/architecture.md` §外部到達点の目録 (標準形 v1) が正本**
     (ここは要約であり、増減はそちらで管理する)。
   - 非本番の captcha は `testing.fake_externals` で `RecaptchaVerifierTestFake` へ bind される
     (`ExternalFakeWiringInventory`)。**SSO は fake しない**。
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

あなたはWebアプリケーション（Laravel + Svelte）の改善に関する概念設計レビュアーです。

【レビュー観点】
1. 使命との整合性: この改善はアプリの使命（North Star）に本質的に貢献するか
2. 禁止事項違反: 上記禁止事項に抵触していないか
3. 実現可能性: 技術的に実現可能か（Laravel 12 + Svelte 5 + Inertia.js）
4. 期待効果の妥当性: 主張している効果は合理的に期待できるか
5. リスク: 重大な副作用・後退の可能性はないか
6. スコープの適切さ: 過大または過小になっていないか
7. **型安全性**: DTO/JsonResourceパターンに沿っているか。PHPStan level 10を通せるか
8. **セキュリティ**: テナント境界 (404 が認可より前) / 認可 / 存在秘匿を壊していないか

【重要】設計者は「過剰に作らない」(思考原則 2) を最優先する。一般化・将来拡張・新機構の追加を
求める指摘は、それが**今この変更の正しさに必要**であることを示せる場合のみ Critical/Warning とせよ。

【出力形式】
- 全体判定: APPROVED / CHANGES_REQUESTED
- 各観点ごとに [Critical] [Warning] [Suggestion] で分類して指摘
- Critical/Warning には修正提案を必ず添える
- 日本語で出力

---

## 実査ブリーフ (この設計の一次入力)

# 実査ブリーフ: 完成動画をアプリ内で観られるようにする

> 要件と実装の突き合わせ (2026-08-10 実査) で **中核体験の最後の一歩が片道で切れている**と判定した項目。
> 統合評価: `devnotes/` の requirements-gap 調査 (biggest_gaps の 1 番目)。

## 実コードで確認した事実

`app/Http/Controllers/Projects/ManualRenderController.php` L106-110:

```php
if ($renderJob->kind !== RenderKind::Preview
    || …
    || ! $this->isLatestSucceededPreview($manual, $renderJob)) {
    abort(404);
}
```

**`kind=Render` (完成動画) の playback は 404 になる。** アプリ内で観られるのは
プレビューだけで、**完成動画の受け取り口は `projects.manuals.download` の 1 本しかない**。

route も実在を確認済み:
- `GET projects/{project}/manuals/{manual}/render-jobs/{renderJob}/playback` (= プレビュー専用)
- `GET projects/{project}/manuals/{manual}/download` (= 完成動画の唯一の受け取り)

## 阻害されているユーザージョブ

**撮った人が結果に到達する**。制作フロー (doc/02 §2.2) の第 7 段は「プレビュー / DL」だが、
**完成物の視聴が欠けている**。ダウンロードして外部プレイヤーで開くしかない。

これは「思考ゼロ・編集ゼロ」を掲げる使命 (AGENTS.md North Star) に対して、
**最後に一番手間のかかる操作を要求している**ことになる。

## 設計で決めるべきこと

1. **どの render job を再生可能にするか**。既存は
   `isLatestSucceededPreview()` で「最新の成功したプレビュー」に限っている。
   完成動画も同じ考え方 (最新の成功した Render) でよいか。
   **published なマニュアルの現行版と、過去バージョンの扱い**を決めること
   (`scenario_version` のスナップショットが固定されている点に注意)。
2. **既存の 404 の意図を壊さないこと**。現在 404 にしているのは**存在秘匿**の意味もありうる。
   実コードとテストを読み、**何を守るための 404 か**を確認してから緩めること。
   他組織・他プロジェクトの job が見えてはならない (AGENTS.md セキュリティ不変条件 2/3)。
3. **UI の導線**。`RenderPanel.svelte` に完成動画の再生を足すのか、別の場所か。
   aicue:T148 で `playbackJobId` → `playbackJob: RenderJobProps` に変わっており、
   **動画 URL と注記を同一オブジェクトから出す**形になっている。この形に乗せられるか。
4. **撮影 PWA からの戻り導線**。統合評価は
   「撮影 PWA (`Capture/Show` のヘッダーは『一覧へ戻る』だけ) から PC 側マニュアル詳細への戻り導線が無い」
   とも指摘している。**本 TODO に含めるかは設計者が判断**してよい
   (含めるなら往復が閉じる。含めないなら別 TODO として明記する)。
5. **T148 の告知契約との整合**。完成動画には `placeholder_cut_count` が記録されている
   (aicue:T148)。完成動画を再生できるようにするなら、**プレビューと同じく注記を出すか**を決める。
   なお完成動画は未撮影があると 422 でブロックされるので `placeholder_cut_count=0` のはずである
   (値契約は aicue:T148 の設計を参照)。

## 読むべき現行コード

- `app/Http/Controllers/Projects/ManualRenderController.php` (L84-130 付近。playback と 404 の条件)
- `app/Http/Controllers/Projects/VideoManualController.php` (L100-130 付近。props の組み立て)
- `resources/js/components/features/manual/RenderPanel.svelte`
- `app/Enums/Manual/RenderKind.php` / `app/Models/RenderJob.php`
- `app/Services/Manual/RenderPipeline.php` (成果物の保存先と署名 URL)
- `tests/Feature/Manual/` の playback / download 関連テスト
- aicue:T148 の設計 (`devnotes/20260811-0146-preview-render-parity/detailed-design.md`)

## やらないこと

- **ダウンロード経路を消さない** (mp4 を手元に落とす需要は別にある)。
- **認可・テナント境界を緩めない**。他組織の job が見えるようにしてはならない。
- 多言語 (`?lang=`) の扱いは本 TODO では触らない (v1 の扱いが未確定)。


---

## 概念設計

# 概念設計: render-playback (完成動画をアプリ内で観られるようにする)

## 背景・課題

実査ブリーフ (`recon-brief.md`) が「中核体験の最後の一歩が片道で切れている」と判定した項目。
実コードで確認した事実:

- `ManualRenderController::playback()` は `kind !== RenderKind::Preview` を **404** にする
  (L106-110)。**アプリ内で観られるのはプレビューだけ**。
- 完成動画の受け取り口は `projects.manuals.download` の 1 本だけで、署名 URL は
  `attachment` disposition (`RenderObjectStorage::temporaryDownloadUrl`)。**手元に落として
  外部プレイヤーで開く**しか結果に到達する手段がない。
- `RenderPanel.svelte` も `status === "published" && canManage` のときに DL ボタンを出すだけで、
  再生要素 (`<video>`) はプレビュー用の 1 つしかない。

「思考ゼロ・編集ゼロ」(AGENTS.md 使命) を掲げながら、**制作フローの最終段だけが
アプリ外の手作業**になっている。

### 併せて見つけた不整合 (副次課題)

「今どの成果物を受け取れるか」の判定式が **3 箇所に複製**されており、しかも**同一でない**:

| 場所 | 式 |
|---|---|
| `ManualRenderController::isLatestSucceededPreview()` | 「より新しい succeeded preview が存在しない」 |
| `VideoManualController::show()` の `$playbackJob` | 「`output_path` 非 NULL の succeeded preview のうち最新」 |
| `ManualDownloadController::show()` | 「`output_path` 非 NULL の succeeded render のうち最新」+ published |

保持ポリシーの実体 (`RenderJobService::newerSucceededExists()` / `DeleteRenderOutputsJob`) は
**「同 manual・同 kind でより新しい succeeded が 1 件でもあれば実体を消す」**である。
よって「最新 succeeded の `output_path` が NULL」という異常データでは、props 側の式だけが
**削除済みの旧世代**を選び、`<video>` が 404 を踏む。頻度は低いが、式が 3 本ある限り
今回の追加でさらに 1 本増える。

## 改善アイデア

1. **「今受け取れるレンダ成果物」を決める式を 1 ファイルに集約する**
   (`App\Services\Manual\CurrentRenderArtifact`)。保持ポリシーと同じ定義
   =「同 kind の最新 succeeded を取り、その `output_path` が NULL なら**無い**」に統一する。
   消費者は 3 つ: playback / download / 詳細画面 props。
2. **既存 playback route を kind=render にも開く** (新 route を増やさない)。
   完成動画の再生条件は **download と同一**にする (published + 現行の完成成果物 + `download` ability)。
   preview 側の 404 条件・ability は**一切変えない**。
3. **詳細画面に完成動画のプレイヤーを出す** (`RenderPanel.svelte`)。props は T148 で導入済みの
   `playbackJob: RenderJobProps` と**同じ形**の `finishedJob: RenderJobProps | null` を足す
   (独自形を作らない)。DL ボタンは残し、表示条件を `status === "published"` から
   `finishedJob !== null` へ変える (押すと 404 になる異常データの穴も同時に閉じる)。

### なぜ job 単位の URL のままにするか

manual 単位の URL (`.../manuals/{manual}/watch`) にすると、再レンダ後も URL 文字列が
変わらないため、**ブラウザが古いメディアを再生し続けうる** (`router.reload()` は
`src` 文字列を変えない)。job id を URL に含める既存の形 (`render-jobs/{renderJob}/playback`) は
世代が変われば URL が変わるので、この問題が構造的に起きない。既存 route を使う根拠でもある。

## 期待効果

- **使命**: 「撮ったら完成物に到達する」が**アプリ内で閉じる**。最後の一歩の手作業 (DL →
  外部プレイヤー) が消える。
- **副次**: 成果物選択式が 1 本になり、props と route が別世代を指す穴が構造的に消える
  (T148 が「注記と動画 URL は同一オブジェクトから出す」でやったことの、成果物選択側の対応物)。
- **回帰の縮小**: DL ボタンが「押すと 404」になる状態 (published だが succeeded render 無し) が
  UI から消える。

## 実装方針 (概要)

| # | 変更 | 対象 |
|---|---|---|
| 1 | 成果物選択式の集約 | `app/Services/Manual/CurrentRenderArtifact.php` (新規) |
| 2 | playback を kind=render へ拡張 | `ManualRenderController::playback()` |
| 3 | download を集約式へ載せ替え | `ManualDownloadController::show()` |
| 4 | props に `finishedJob` 追加 | `VideoManualController::show()` / `types/manual.ts` |
| 5 | 完成動画プレイヤー | `RenderPanel.svelte` / `Manuals/Show.svelte` |
| 6 | 不変条件の機械化 | `tests/Architecture/CurrentRenderArtifactInventoryTest.php` (新規) |

route は**増やさない**。DTO は既存 `RenderJobData` をそのまま使う (新 shape を作らない)。

## 制約・前提

- **テナント境界・認可を緩めない** (AGENTS.md セキュリティ不変条件 2/3/9)。
  層 2 (`resolveOrganizationProject` + `scopeBindings` + `video_manual_id` 再検査 = 404) は
  authorize より前のまま。`projects.manuals.render-jobs.playback` は
  `NestedRouteDefenseInventory` に登録済み (route を増やさないので inventory も不変)。
- **ability は成果物の性質に従う**: kind=preview は `render`、kind=render は `download`。
  現状どちらも `ProjectPolicy::update` に落ちるため**現時点の可否は不変**だが、将来
  download を視聴者へ開くときに再生が自動追従する結線にしておく。
- **保持ポリシーと矛盾しない**: 署名 URL を出してよいのは「実体が消されない世代」だけ
  (`newerSucceededExists` が false の行) である。
- **T148 の告知契約**: 完成動画の `placeholder_cut_count` は値契約上 `0` (または本列以前の
  行の `null`)。UI の既存規則 (`> 0` のときだけ注記) をそのまま適用すれば**完成動画には
  注記が出ない**ため、分岐を足さない。

## スコープ外 (今回やらないこと)

- **ダウンロード経路の削除**: 残す (手元に落とす需要は別)。
- **撮影者 (project_member) への視聴開放**: `download` ability は編集者のみのままにする。
  視聴面 (配信・受講) は別機能であり、本 TODO で認可を緩めない。
- **published でない manual の旧完成動画の再生**: シナリオを編集すると `status` が `ready` に
  戻り、既存 DL も 404 になる。**この非対称は変えない** (DL と同じ条件に揃えるだけ)。
- **撮影 PWA からの戻り導線**: 別ユーザージョブ (ナビゲーション) であり変更ファイルも
  検証レーンも別。**別 TODO とする** (本設計には含めない)。
- **多言語 (`?lang=`)**: v1 の扱いが未確定のため触らない。


---

## レビューに必要な現行コードの抜粋 (実在確認済み)

### app/Http/Controllers/Projects/ManualRenderController.php (playback 部分)

```php
    public function playback(Request $request, Project $project, VideoManual $manual, RenderJob $renderJob, RenderObjectStorage $storage): RedirectResponse
    {
        $organization = $this->resolveCurrentOrganization($request);
        // URL 整合 guard: 認可より前に 404
        $this->resolveOrganizationProject($organization, $project);
        if ($renderJob->video_manual_id !== $manual->id) {
            abort(404);
        }
        Gate::authorize('render', $manual); // preview は編集者専用機能

        if ($renderJob->kind !== RenderKind::Preview
            || $renderJob->status !== JobStatus::Succeeded
            || $renderJob->output_path === null
            || ! $this->isLatestSucceededPreview($manual, $renderJob)) {
            abort(404);
        }

        return redirect()->away($storage->temporaryPlaybackUrl($renderJob->output_path));
    }

    /** 当該 job が同 manual の preview の最新 succeeded job か */
    private function isLatestSucceededPreview(VideoManual $manual, RenderJob $renderJob): bool
    {
        return ! $manual->renderJobs()
            ->where('kind', RenderKind::Preview->value)
            ->where('status', JobStatus::Succeeded->value)
            ->where('id', '>', $renderJob->id)
            ->exists();
    }
```

### app/Http/Controllers/Projects/ManualDownloadController.php

```php
        Gate::authorize('download', $manual);
        if ($manual->status !== VideoManualStatus::Published) {
            abort(404);
        }
        $job = $manual->renderJobs()
            ->where('kind', RenderKind::Render->value)
            ->where('status', JobStatus::Succeeded->value)
            ->whereNotNull('output_path')
            ->latest('id')
            ->first();
        if ($job === null || $job->output_path === null) {
            abort(404);
        }
        $filename = $manual->title.'.mp4';

        return redirect()->away($storage->temporaryDownloadUrl($job->output_path, $filename));
```

### app/Http/Controllers/Projects/VideoManualController.php (show props の該当部分)

```php
        $playbackJob = $manual->renderJobs()
            ->where('kind', RenderKind::Preview->value)
            ->where('status', JobStatus::Succeeded->value)
            ->whereNotNull('output_path')
            ->latest('id')
            ->first();
        // ...
            'render' => [
                'job' => ..., 'previewJob' => ..., 'playbackJob' => ...,
                'coverage' => AdoptedReadyTakeCoverage::for($manual)->toProps(),
            ],
```

### app/Services/Manual/RenderJobService.php (保持ポリシーの判定)

```php
    /** 同 manual・同 kind でより新しい succeeded job が存在するか (世代交代済み判定) */
    public function newerSucceededExists(RenderJob $job): bool
    {
        return RenderJob::query()
            ->where('video_manual_id', $job->video_manual_id)
            ->where('kind', $job->kind->value)
            ->where('status', JobStatus::Succeeded->value)
            ->where('id', '>', $job->id)
            ->exists();
    }
```
DeleteRenderOutputsJob は `newerSucceededExists` が true の行の S3 実体を削除し `output_path` を CAS で NULL 化する。

### app/Policies/VideoManualPolicy.php (抜粋)

```php
    /** レンダ/プレビューの実行: プロジェクトを操作できる人 (編集者)。撮影者は不可 */
    public function render(User $user, VideoManual $manual): bool { ... ProjectPolicy::update ... }
    /** 完成動画のダウンロード: 編集者のみ */
    public function download(User $user, VideoManual $manual): bool { ... ProjectPolicy::update ... }
```

### resources/js/components/features/manual/RenderPanel.svelte (該当部分)

```svelte
{#if status === "published" && canManage}
    <Button variant="secondary" href={`/projects/${projectId}/manuals/${manualId}/download`} testId="download-button">
        <Download class="size-4" /> 完成動画をダウンロード
    </Button>
{/if}
...
{#if playbackJob !== null && !previewInFlight}
    ... <video controls src={`/projects/${projectId}/manuals/${manualId}/render-jobs/${playbackJob.id}/playback`} aria-label="プレビュー動画" /> ...
{/if}
```

---

上記の概念設計をレビューせよ。
