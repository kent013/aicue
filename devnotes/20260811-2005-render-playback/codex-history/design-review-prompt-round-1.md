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
まず仮説を立てろ。データに真摯に向き合え。先人の知恵を探せ。機能の名前に立ち返れ。
仕組みが機能していない段階で値を弄るな。成果が出なければ早期に見切り、次の仮説へ進め。

【ツール使用制限】
コマンド実行・ファイル書き込みは一切行わず、提供されたテキストの分析に集中すること。ファイル読み込みは許可。

【アプリのドメイン固有規約】
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

あなたは経験豊富なWebアプリケーションアーキテクトです。Laravel + Svelte アプリケーション改善の詳細設計をレビューしてください。

【前提環境】
- PHP 8.4 + Laravel 12 + Svelte 5 + Inertia.js + TypeScript / PHPStan level 10 / Pest
- DTO + JsonResource パターン / Laratrust RBAC (Organization → Team → Project 階層)
- Pest は RefreshDatabase をグローバル適用し --parallel で実行する。テストデータは Factory 必須

【レビュー観点】
1. コードの正確性（ロジックエラー、エッジケース、null 安全性）
2. 既存コードとの整合性（命名規約、パターン、API）
3. PHPStan level 10 適合性
4. テスト計画の網羅性（ファイル名・テストケース名まで。負のコントロール / mutation 手順の妥当性）
5. DTO/JsonResource パターンの遵守
6. Inertia Props vs API Response の使い分け
7. 副作用・後退リスク
8. 波及変更の網羅性（TypeScript 型定義、API Resource、テストが変更対象に含まれているか）
9. セキュリティ（認可チェック、テナント境界 404 の先行、存在秘匿、AGENTS.md のセキュリティ不変条件）
10. DESIGN.md 準拠（token 経由か、hex 直書きを増やさないか）
11. Atomic Design 準拠（層の責務分離、単方向 import、Lucide アイコン前提）
12. 「保証しないもの」の記述が実態と一致しているか（過小・過大の両方を指摘せよ）

【重要】設計者は「今必要なものだけ作る」(思考原則 2) を最優先する。一般化・将来拡張・新機構の
追加要求は、それが**今この変更の正しさに必要**であることを示せる場合のみ Critical/Warning とせよ。

【出力形式】
- 各施策ごとに判定: APPROVE / REQUEST_CHANGES
- 指摘は [Critical] [Warning] [Suggestion] で分類
- Critical/Warning には必ず修正案を添える
- 全体判定: APPROVED / CHANGES_REQUESTED
- 日本語で出力

---

## 詳細設計書

# 詳細設計: render-playback (完成動画をアプリ内で観られるようにする)

## 使命・制約(絶対遵守)

### アプリの使命(North Star)

**AI-CUE** は、現場に既にある**作業手順書(SOP)を起点に**、AI が撮るべきカットを設計した**動画シナリオ**を生成し、
そのシナリオを**スマホ(PWA)でナビゲーション撮影**することで、専門知識ゼロの現場作業者でも
**標準化されたマニュアル動画**を作れるようにする。

- 「思考ゼロ・編集ゼロ」— 台本作成・撮影判断・編集の 3 ハードルを AI とナビ撮影で肩代わりする。
- 競合(OJT を撮って形式化する tebiki)と異なり、**標準作業を起点に AI が教材設計し撮影を指示する**。
- 熟練者の暗黙知を動画マニュアルという形式知へ変換する装置(SECI)。

**本設計の位置づけ**: 制作フロー最終段の「完成物の受け取り」が **DL 1 本**しかなく、
アプリ内で観る手段が無い。**編集ゼロ**を掲げながら最後だけ外部プレイヤーを要求している状態を解消する。

### 禁止事項(AGENTS.md)

1. テストなしの実装完了報告(不変条件は Architecture/Feature テストへの登録まで含めて「実装済み」)
2. PHPStan エラーの widen・baseline 化
3. dev DB への破壊操作をエージェント判断で実行すること
4. `response()->json()` の直書き(DTO / JsonResource / Inertia を使う)
5. LLM 呼び出しの Prism 直呼び
6. prompt 文字列のコード直書き
7. 操作系 POST の応答での `redirect()->intended()`
8. 必須条件未充足を理由にボタンを disabled にする UI
9. Artifact の使用

**本設計で特に効くもの**: 1(Architecture gate まで含めて完了)/ 4(Inertia props と redirect のみ。
JSON 直書きは無い)/ 8(完成動画の再生ボタン相当を disabled にする分岐は作らない。
そもそも**出せない状態では出さない**)。

### コーディングルール

- **PHPStan level 10** 必須(`composer phpstan`)
- **Pest**(`composer test`)。**RefreshDatabase はグローバル適用**(`tests/Pest.php`)、`--parallel` 実行。
  個別 `DatabaseTransactions` 禁止
- **テストデータは必ず Factory**(`Model::create()` 手組み禁止)
- **DTO + JsonResource** パターン
- アーリーリターン推奨 / `declare(strict_types=1)` + 日本語コメント
- `composer fix`(Pint)/ `pnpm lint:fix`
- PHP 8.4 + Laravel 12 + Svelte 5 + Inertia.js + TypeScript
- フロント: DESIGN.md の token 経由のみ(hex 直書き禁止)、Atomic Design の層
  (`atoms → molecules → organisms → features/{domain} → templates → pages` の単方向 import)、
  アイコンは `@lucide/svelte`

## 概念設計リファレンス

`devnotes/20260811-2005-render-playback/conceptual-design.md`(Codex 概念レビュー **APPROVED / Round 4**)

## 施策一覧

| # | 施策名 | 変更ファイル | 優先度 |
|---|--------|------------|--------|
| 1 | 成果物選択式の集約 (`CurrentRenderArtifact`) | `app/Services/Manual/CurrentRenderArtifact.php`(新規) | High |
| 2 | playback を kind=render へ拡張 | `app/Http/Controllers/Projects/ManualRenderController.php` | High |
| 3 | download を集約式へ載せ替え | `app/Http/Controllers/Projects/ManualDownloadController.php` | High |
| 4 | props に `finishedJob` を追加 | `app/Http/Controllers/Projects/VideoManualController.php` / `resources/js/types/manual.ts` | High |
| 5 | 完成動画プレイヤー(UI) | `resources/js/components/features/manual/RenderPanel.svelte` / `resources/js/pages/Manuals/Show.svelte` | High |
| 6 | 不変条件の機械化(deny-by-default 目録) | `tests/Architecture/CurrentRenderArtifactInventoryTest.php`(新規) / `app/Support/Security/RenderArtifactSelectionInventory.php`(新規) / `app/Enums/Security/RenderArtifactSelectionKind.php`(新規) | High |
| 7 | ドキュメント | `docs/architecture.md` / `AGENTS.md`(ドメイン固有規約 13) | Medium |

**route は 1 本も増やさない**。DTO は既存 `RenderJobData` を再利用する(新 shape を作らない)。

---

## 施策 1: 成果物選択式の集約 (`CurrentRenderArtifact`)

### 変更箇所

- 新規: `app/Services/Manual/CurrentRenderArtifact.php`

### 波及変更

- TypeScript 型定義: なし
- API Resource/DTO: なし(`RenderJobData` は不変)
- テストファイル: 新規 `tests/Unit/Manual/CurrentRenderArtifactTest.php`

### 現行コード(同じ意味の式が 3 箇所に分散している)

```php
// ManualRenderController::isLatestSucceededPreview() — 「より新しい succeeded が無い」
return ! $manual->renderJobs()
    ->where('kind', RenderKind::Preview->value)
    ->where('status', JobStatus::Succeeded->value)
    ->where('id', '>', $renderJob->id)
    ->exists();

// VideoManualController::show() — 「output_path 非 NULL の succeeded のうち最新」
$playbackJob = $manual->renderJobs()
    ->where('kind', RenderKind::Preview->value)
    ->where('status', JobStatus::Succeeded->value)
    ->whereNotNull('output_path')
    ->latest('id')
    ->first();

// ManualDownloadController::show() — 同上 (kind=render)
$job = $manual->renderJobs()
    ->where('kind', RenderKind::Render->value)
    ->where('status', JobStatus::Succeeded->value)
    ->whereNotNull('output_path')
    ->latest('id')
    ->first();
```

保持ポリシーの実体はこう定義されている(`RenderJobService::newerSucceededExists()`。
`DeleteRenderOutputsJob` はこれが true の行の S3 実体を削除し `output_path` を CAS で NULL 化する):

```php
return RenderJob::query()
    ->where('video_manual_id', $job->video_manual_id)
    ->where('kind', $job->kind->value)
    ->where('status', JobStatus::Succeeded->value)
    ->where('id', '>', $job->id)
    ->exists();
```

つまり**「同 kind の最新 succeeded 以外は消える」**。`whereNotNull('output_path')` を
先に効かせる 2 箇所の式は、最新 succeeded の `output_path` が NULL のとき
**削除済みの旧世代を選ぶ**(署名 URL を出しても実体が無い / route 側は 404 にする)。

### 変更後コード

```php
<?php

declare(strict_types=1);

namespace App\Services\Manual;

use App\Enums\Manual\JobStatus;
use App\Enums\Manual\RenderKind;
use App\Models\RenderJob;
use App\Models\VideoManual;

/**
 * 「いま受け取れるレンダ成果物はどれか」の**唯一の選択式**(playback / download / 詳細画面 props)。
 *
 * 定義は保持ポリシー (RenderJobService::newerSucceededExists / DeleteRenderOutputsJob) と
 * **同じ世代定義**である: 実体が残るのは「同 manual・同 kind の最新 succeeded」だけなので、
 * 最新 succeeded の output_path が NULL(= 生成に失敗した / 掃除された)なら
 * **旧世代へフォールバックしない**(削除済みオブジェクトの署名 URL を出さないため)。
 *
 * **持たない責務**: published 判定(完成動画の公開状態)と ability 判定は呼び出し側にある。
 * ここは「どの行か」だけを答える(名前が示す役割を超えない)。読み取り専用。
 */
final class CurrentRenderArtifact
{
    /** 同 manual・同 kind で現在受け取れる succeeded job(無ければ null) */
    public static function currentSucceeded(VideoManual $manual, RenderKind $kind): ?RenderJob
    {
        $job = $manual->renderJobs()
            ->where('kind', $kind->value)
            ->where('status', JobStatus::Succeeded->value)
            ->latest('id')
            ->first();

        if ($job === null || $job->output_path === null) {
            return null; // 旧世代へフォールバックしない(実体が無い可能性がある)
        }

        return $job;
    }
}
```

### PHPStan 適合チェック

- [x] 戻り値の型が明示されている(`?RenderJob`)
- [x] null 安全(`$job === null || $job->output_path === null` の早期 return。`Assert` 不要)
- [x] DTO を返す必要は無い(Eloquent モデルを返す内部 read helper。HTTP 層で `RenderJobData` に載る)
- [x] Generics: `$manual->renderJobs()` は `HasMany<RenderJob, VideoManual>` で `first()` は `RenderJob|null`

### テスト計画

`tests/Unit/Manual/CurrentRenderArtifactTest.php`(新規。RefreshDatabase はグローバル適用、Factory 必須)

- [ ] `同 kind の最新 succeeded を返す(kind をまたがない)`
- [ ] `最新 succeeded の output_path が NULL なら null(旧世代へフォールバックしない)`
- [ ] `succeeded が 1 件も無ければ null(queued / running / failed は選ばない)`
- [ ] `返した行は保持ポリシーの削除対象ではない(newerSucceededExists が false)`
      — 選択式と保持ポリシーの世代定義が一致することの固定

### リスク

- **挙動変化(意図的)**: 「最新 succeeded の `output_path` が NULL」のとき、これまで
  download が旧世代へフォールバックして 302 を返していた経路が 404 になる。
  旧世代の実体は保持ポリシーで削除済みのため、**302 の先は壊れた URL**であり後退ではない。
  ただし**実測データを持っていない**(この状態の行が本番にあるかは未確認)ため、
  「不具合を直した」とは書かず「定義を保持ポリシーに揃えた」と記録する。

---

## 施策 2: playback を kind=render へ拡張

### 変更箇所

- `app/Http/Controllers/Projects/ManualRenderController.php` L91-124(`playback()` と `isLatestSucceededPreview()`)

### 波及変更

- TypeScript 型定義: なし(URL 形は不変)
- API Resource/DTO: なし
- テストファイル: `tests/Feature/Manual/RenderPollingAndArtifactAccessTest.php`(既存 404 マトリクスの維持確認)、
  新規 `tests/Feature/Manual/FinishedVideoPlaybackTest.php`、
  新規 `tests/Feature/Manual/RenderPlaybackAbilityParityTest.php`(trip-wire)
- route: **変更なし**(`projects.manuals.render-jobs.playback` のまま。
  `Tests\Support\Routing\NestedRouteDefenseInventory` の登録も不変)

### 現行コード

```php
    public function playback(Request $request, Project $project, VideoManual $manual, RenderJob $renderJob, RenderObjectStorage $storage): RedirectResponse
    {
        $organization = $this->resolveCurrentOrganization($request);
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
    { /* 上記 */ }
```

### 変更後コード

```php
    /**
     * 成果物再生 (302 → S3 署名 URL)。preview と完成動画の**両方**を扱う。
     *
     * 層は 3 段で、**すべて認可より前に 404**(AGENTS.md セキュリティ不変条件 2/10):
     *   1. {project} ∈ current org … project.in-route-org middleware + inline guard
     *   2. {manual}  ∈ {project}   … routes 側 Route::scopeBindings()
     *   3. {renderJob} ∈ {manual}  … scopeBindings + 下の inline 再検査(二重防御)
     * その後に **成果物の性質に合う ability** を評価する:
     *   kind=preview → render ability / kind=render → download ability
     *   (現行はどちらも ProjectPolicy::update に落ちるため**可否は完全に同値**。
     *    UI 側の canManage が自動追従するという意味ではない = 誇張しない)
     * 完成動画だけ published を要求するのは download と同一条件にするため(順序も download と同じ
     * = authorize の後)。最後に「いま受け取れる成果物」と同一行かを照合する
     * (旧世代 job id の直叩き・未完了・実体削除済みはここで 404)。
     */
    public function playback(Request $request, Project $project, VideoManual $manual, RenderJob $renderJob, RenderObjectStorage $storage): RedirectResponse
    {
        $organization = $this->resolveCurrentOrganization($request);
        // URL 整合 guard: 認可より前に 404
        $this->resolveOrganizationProject($organization, $project);
        if ($renderJob->video_manual_id !== $manual->id) {
            abort(404);
        }
        // 2 値 enum の網羅 match(到達不能な fallback を作らない)
        Gate::authorize(match ($renderJob->kind) {
            RenderKind::Preview => 'render',
            RenderKind::Render => 'download',
        }, $manual);

        // 完成動画は「公開中のマニュアルの現行版」だけ(download と同条件・同順序)
        if ($renderJob->kind === RenderKind::Render && $manual->status !== VideoManualStatus::Published) {
            abort(404);
        }

        $current = CurrentRenderArtifact::currentSucceeded($manual, $renderJob->kind);
        if ($current === null || $current->id !== $renderJob->id) {
            abort(404); // 未完了 / 旧世代 / 実体削除済み
        }
        $path = $current->output_path;
        if ($path === null) {
            abort(404); // currentSucceeded の契約上到達しないが、型を締めるため明示する
        }

        return redirect()->away($storage->temporaryPlaybackUrl($path));
    }
```

- `isLatestSucceededPreview()` は**削除する**(後方互換の並走を残さない = 思考原則 3)。
- `use App\Enums\Manual\JobStatus;` は他で使わなくなるため削除、
  `use App\Enums\Manual\VideoManualStatus;` と `use App\Services\Manual\CurrentRenderArtifact;` を追加。
  `JobStatus` は `store()/preview()/show()` では使っていないことを確認済み(現行の参照は L106-107 と L121 のみ)。

### PHPStan 適合チェック

- [x] `match` は `RenderKind` の 2 case を網羅(未処理 case があれば level 10 が落とす)
- [x] `$current->output_path` は `string|null` のためローカル変数へ束ねて null 検査してから使う
- [x] `Gate::authorize(string $ability, mixed $arguments)` の第 1 引数は string(match の戻り値)

### テスト計画

**A. `tests/Feature/Manual/FinishedVideoPlaybackTest.php`(新規)**

- [ ] `playback: published + 最新 succeeded render は 302(S3 署名 URL へ redirect)`
- [ ] `playback: published でない manual の完成動画は 404(シナリオ編集で ready へ戻った旧完成動画も 404)`
- [ ] `playback: 旧世代 render は 404(実体削除済みの世代へ署名 URL を出さない)`
- [ ] `playback: 最新 succeeded render の output_path が NULL なら旧世代へフォールバックせず 404`
- [ ] `playback: queued / running / failed の render は 404`
- [ ] `playback: 撮影者は 403(download ability = 編集者専用。層 2 の 404 より後に評価される)`
- [ ] `playback: cross-org / cross-manual の render job は 404(存在オラクル封じ)`
- [ ] `playback: kind=preview の 302 条件と ability は本変更で変わらない(回帰の明示固定)`

**B. `tests/Feature/Manual/RenderPollingAndArtifactAccessTest.php`(既存の更新)**

- [ ] 既存テスト `playback の 404 マトリクス: kind=render / 未完了 / output_path NULL / 旧世代` を
      **`kind=render` の行だけ差し替える**(「kind=render の succeeded は 404」→
      「**published でない** manual の kind=render succeeded は 404」)。
      他の行(未完了 preview / output_path NULL / 旧世代 preview)は**変更しない**
      = preview 側の契約が動いていないことの回帰になる
- [ ] 既存テスト `download / playback: cross-org は 404` は不変(通ることを確認)

**C. `tests/Feature/Manual/RenderPlaybackAbilityParityTest.php`(新規・trip-wire)**

- [ ] `trip-wire: VideoManualPolicy の render と download は現在同値である`
      — 同じ user / manual に対して両 ability の結果が一致することを、編集者・撮影者・
      他組織ユーザーの 3 者で確認する。**失敗メッセージに次を書く**:
      「policy が分岐した。playback の kind→ability 写像、`show` props の `finishedJob` 条件、
      RenderPanel の `canManage` 条件の 3 点を設計ごと見直すこと」。
      これは**写像が効いていることの証明ではない**(現行 policy が同値である限り観測差は出ない)。
      観測差が出るようになった瞬間に気づくための警報である。

### リスク

- **署名 URL の disposition が inline になる経路が増える**(`temporaryPlaybackUrl` は
  `ResponseContentDisposition` を付けない)。認可条件は download と同一なので**到達できる主体は増えない**が、
  同じオブジェクトをブラウザで開ける経路が 1 本増えるのは事実。Feature テストで条件を固定する。
- `match` に将来 `RenderKind` の case が増えたとき、ability 写像の追加を忘れると **level 10 が落ちる**
  (fail-fast 側に倒れる = 意図どおり)。

---

## 施策 3: download を集約式へ載せ替え

### 変更箇所

- `app/Http/Controllers/Projects/ManualDownloadController.php` L36-54

### 波及変更

- TypeScript 型定義 / DTO: なし
- テストファイル: `tests/Feature/Manual/RenderPollingAndArtifactAccessTest.php`(既存 download テスト群は不変)
  + 新規ケース(下記)

### 現行コード

```php
        if ($manual->status !== VideoManualStatus::Published) {
            abort(404);
        }
        /** @var RenderJob|null $job */
        $job = $manual->renderJobs()
            ->where('kind', RenderKind::Render->value)
            ->where('status', JobStatus::Succeeded->value)
            ->whereNotNull('output_path')
            ->latest('id')
            ->first();
        if ($job === null || $job->output_path === null) {
            abort(404);
        }
```

### 変更後コード

```php
        if ($manual->status !== VideoManualStatus::Published) {
            abort(404);
        }
        // 「いま受け取れる完成動画」の選択は CurrentRenderArtifact ただ 1 箇所(playback と同一式)
        $job = CurrentRenderArtifact::currentSucceeded($manual, RenderKind::Render);
        if ($job === null || $job->output_path === null) {
            abort(404); // 完成物が無い / 実体が消えている
        }
```

- `use App\Enums\Manual\JobStatus;` を削除(他で使っていないことを確認済み)、
  `use App\Services\Manual\CurrentRenderArtifact;` を追加。`RenderJob` の use は
  `/** @var */` が不要になるため削除する。

### PHPStan 適合チェック

- [x] `currentSucceeded()` の戻り型が `?RenderJob` なので `@var` 注釈が不要になる(型の後退なし)
- [x] `$job->output_path` は null 検査後に `temporaryDownloadUrl(string, string)` へ渡る

### テスト計画

`tests/Feature/Manual/FinishedVideoPlaybackTest.php` に同居させる(同じ選択式の話であるため):

- [ ] `download: 最新 succeeded render の output_path が NULL なら旧世代へフォールバックせず 404`
- [ ] `download: published + 最新 succeeded render は 302(既存契約の維持)` — 既存テストで担保済みのため
      **新規には書かない**(重複を作らない。既存 `RenderPollingAndArtifactAccessTest` が緑であることで確認)

### リスク

- 施策 1 のリスク欄と同じ(挙動変化は「NULL 最新世代のときフォールバックしない」1 点のみ)。

---

## 施策 4: props に `finishedJob` を追加

### 変更箇所

- `app/Http/Controllers/Projects/VideoManualController.php` L104-160(`show()`)
- `resources/js/types/manual.ts`(`RenderProps`)

### 波及変更

- **TypeScript 型定義**: `RenderProps` に `finishedJob: RenderJobProps | null` を追加(必須キー)
- **Inertia Props インターフェース**: `Manuals/Show.svelte` の `render: RenderProps` 経由で
  `RenderPanel.svelte` の `Props` に `finishedJob` を追加(施策 5)
- **API Resource / DTO**: なし(`RenderJobData` を再利用)
- **テストファイル**: `tests/Feature/Manual/FinishedVideoPlaybackTest.php`(props 群)、
  `tests/js/pages/ManualsShow.test.ts`、`tests/js/components/features/manual/RenderPanel.test.ts`

### 現行コード

```php
        $playbackJob = $manual->renderJobs()
            ->where('kind', RenderKind::Preview->value)
            ->where('status', JobStatus::Succeeded->value)
            ->whereNotNull('output_path')
            ->latest('id')
            ->first();
        // ...
            'render' => [
                'job' => $renderJob === null ? null : RenderJobData::fromJob($renderJob, $manual)->toArray(),
                'previewJob' => $previewJob === null ? null : RenderJobData::fromJob($previewJob, $manual)->toArray(),
                'playbackJob' => $playbackJob === null ? null : RenderJobData::fromJob($playbackJob, $manual)->toArray(),
                'coverage' => AdoptedReadyTakeCoverage::for($manual)->toProps(),
            ],
```

### 変更後コード

```php
        // 再生できるプレビュー(選択式は CurrentRenderArtifact に集約。route 側と同一の行を指す)
        $playbackJob = CurrentRenderArtifact::currentSucceeded($manual, RenderKind::Preview);
        // 受け取れる完成動画。**endpoint が 302 を返す条件と 1 対 1**にする:
        // published + download ability + 現行世代。UI の canManage は表示制御であって
        // 秘匿境界ではないため、ここで ability を評価する(条件を UI 側に持たせない)。
        $finishedJob = $manual->status === VideoManualStatus::Published && $user->can('download', $manual)
            ? CurrentRenderArtifact::currentSucceeded($manual, RenderKind::Render)
            : null;
        // ...
            'render' => [
                'job' => ...,
                'previewJob' => ...,
                'playbackJob' => $playbackJob === null ? null : RenderJobData::fromJob($playbackJob, $manual)->toArray(),
                // 完成動画 (再生 + DL の唯一の出し分け根拠)。null = 出さない
                'finishedJob' => $finishedJob === null ? null : RenderJobData::fromJob($finishedJob, $manual)->toArray(),
                'coverage' => AdoptedReadyTakeCoverage::for($manual)->toProps(),
            ],
```

- `use App\Enums\Manual\JobStatus;` を削除、`use App\Enums\Manual\VideoManualStatus;` と
  `use App\Services\Manual\CurrentRenderArtifact;` を追加。`$user` は L104-105 で
  `Assert::isInstanceOf($user, User::class)` 済み(既存)。

TypeScript 側:

```ts
export interface RenderProps {
    job: RenderJobProps | null;
    previewJob: RenderJobProps | null;
    playbackJob: RenderJobProps | null;
    /**
     * 受け取れる完成動画の job(無ければ null)。
     * サーバが「published + download ability + 現行世代」を判定した結果そのものであり、
     * **UI 側で条件を再判定しない**(判断は props で 1 回)。
     */
    finishedJob: RenderJobProps | null;
    coverage: TakeCoverageProps;
}
```

### PHPStan 適合チェック

- [x] `$user->can('download', $manual)` は `bool`(`User` は `Authorizable`)
- [x] 三項の両枝が `RenderJob|null`
- [x] props 配列は既存 `toArray()` の shape をそのまま使う(新 shape なし)

### テスト計画

`tests/Feature/Manual/FinishedVideoPlaybackTest.php`(Inertia assert):

- [ ] `props: published + download 権限保持者には finishedJob が最新 succeeded render を指す`
- [ ] `props: ready へ戻った manual では finishedJob=null(押すと 404 になる導線を出さない)`
- [ ] `props: 詳細を閲覧できるが download 権限のない撮影者には finishedJob=null`
- [ ] `props: finishedJob は output_path も署名 URL も含まない(ポーリングと同じ権限分離の維持)`
- [ ] `props: 最新 succeeded render の output_path が NULL なら finishedJob=null(route と同じ判断)`

### リスク

- props に 1 キー増えるため、`RenderProps` を構築する既存テスト
  (`tests/js/pages/ManualsShow.test.ts` / `RenderPanel.test.ts`)が型エラーになる。
  **これは意図した波及**であり、両方を同 PR で更新する(旧キーの後方互換は残さない)。

---

## 施策 5: 完成動画プレイヤー(UI)

### 変更箇所

- `resources/js/components/features/manual/RenderPanel.svelte`(Props / published 枝 / 注記方針)
- `resources/js/pages/Manuals/Show.svelte` L131-140(`finishedJob={render.finishedJob}` の受け渡し)

### 波及変更

- TypeScript 型定義: 施策 4 で追加済みの `RenderProps.finishedJob`
- Atomic Design: 変更は `components/features/manual/` 配下のみ(層をまたぐ新規 component を作らない)。
  import 方向は `features → atoms`(既存 `Card` / `Button` / `Alert`)のままで単方向を守る
- DESIGN.md: 追加するのは既存 utility class の組み合わせのみ
  (`w-full` / `rounded-md` / `bg-neutral` / `text-caption` / `text-text-secondary`)。
  **hex 直書き・新規 token は増やさない**。アイコンは既存の `@lucide/svelte`(`Download` / `Play`)のみ

### 現行コード

```svelte
        {#if status === "published" && canManage}
            <div class="mt-4">
                <Button variant="secondary" href={`/projects/${projectId}/manuals/${manualId}/download`} testId="download-button">
                    <Download class="size-4" />
                    完成動画をダウンロード
                </Button>
            </div>
        {/if}
```

### 変更後コード

```svelte
        <!-- 完成動画 (再生 + DL)。表示の可否はサーバが決めた finishedJob だけで判断する
             (published / ability を UI 側で再判定しない = 判断を 2 箇所に持たない)。
             書き出し中に出ないことは、この枝が {#if rendering}…{:else} の else 側にある
             ことで構造的に保証される。 -->
        {#if finishedJob !== null && canManage}
            <div class="mt-4 flex flex-col gap-3" data-testid="final-video-block">
                <!-- svelte-ignore a11y_media_has_caption (完成動画の字幕は焼き込み済み) -->
                <!-- preload="none": 詳細画面を開くたびに署名 URL 発行と本体取得が走るのを避ける
                     (完成動画は尺が長い)。src に job id を含むため、再レンダで URL が変わり
                     古い世代が再生され続けることが起きない。 -->
                <video
                    controls
                    preload="none"
                    class="w-full rounded-md bg-neutral"
                    src={`/projects/${projectId}/manuals/${manualId}/render-jobs/${finishedJob.id}/playback`}
                    aria-label="完成動画"
                    data-testid="final-video"
                ></video>
                <div>
                    <Button
                        variant="secondary"
                        href={`/projects/${projectId}/manuals/${manualId}/download`}
                        testId="download-button"
                    >
                        <Download class="size-4" />
                        完成動画をダウンロード
                    </Button>
                </div>
            </div>
        {/if}
```

- `Props` に `finishedJob: RenderJobProps | null` を追加する。
  **local state にはしない**: 完成動画は render 成功時の `router.reload()` で props ごと入れ替わる
  (ポーリングの render 分岐は既に `stop(); router.reload();` を行う)。
  poll 応答から `finishedJob` を組み立てる経路は**作らない**(ポーリング応答は
  「published + ability + 現行世代」を判定していないため。判断はサーバの props で 1 回)。
- **黒背景の注記は完成動画に出さない**。`placeholder_cut_count` の値契約では succeeded render は
  `0`(本列追加以前の行は `null`)であり、既存の `> 0` 条件では何も表示されない。
  **完成動画用の注記分岐を新設しない**(T148 の値契約をそのまま使う)。
- 既存のプレビュー再生ブロック(`playbackJob`)は**変更しない**。`aria-label="プレビュー動画"` が
  固定文言でよい根拠(「playbackJob に render job が入る経路が無い」)は本変更後も成立する
  — `finishedJob` は別変数であり、poll の preview 分岐も従来どおり `preview` のみを入れる。
  この根拠コメントに「`finishedJob` は別枠で持つ」ことを追記する。

### PHPStan 適合チェック

- N/A(フロント)。代わりに `pnpm typecheck` / `pnpm lint` / `pnpm test` が対象。

### テスト計画

**vitest `tests/js/components/features/manual/RenderPanel.test.ts`(既存へ追加)**

- [ ] `finishedJob があると完成動画プレイヤーと DL ボタンの両方が出る`
- [ ] `完成動画プレイヤーの src は playback route を job id 込みで指す(再レンダで URL が変わる)`
- [ ] `finishedJob が null なら完成動画プレイヤーも DL ボタンも出ない(published でも)`
      — 「押すと 404」の導線を UI から消したことの固定
- [ ] `完成動画には黒背景の注記を出さない(placeholder_cut_count=0 / null の両方)`
- [ ] `書き出し中(rendering)は完成動画ブロックを描画しない`
- [ ] 既存 `baseProps` に `finishedJob: null` を足す(全既存ケースの回帰維持)

**vitest `tests/js/pages/ManualsShow.test.ts`(既存の更新)**

- [ ] `render.finishedJob が RenderPanel へそのまま渡る`(props pass-through の固定)

**Browser lane `tests/Browser/FinishedVideoPlaybackTest.php`(新規。Chromium + WebKit の 2 レーン契約)**

- [ ] `E-1: published マニュアルの詳細画面に完成動画プレイヤーが見える(src が playback route を指す)`
- [ ] `E-2: 再生を足しても DL 導線は残っている(同じブロックに両方見える)`
- [ ] `E-3: ready へ戻った manual では完成動画プレイヤーも DL ボタンも出ない`

Browser lane の作法(既存 `PreviewCoverageNoticeTest` に倣う):
`contractPaidPlan($organization)` を通す(業務 route は `require-active-subscription` group 内)、
`assertNoJavaScriptErrors()`、UI 変更後は先に `pnpm build`。
**クリックしない**(Browser lane には object storage が無く、`/playback` は実 S3 の署名 URL 生成へ進む)。
`preload="none"` により要素描画だけでは媒体取得が走らないが、これは**ヒント**であり
ブラウザが先読みしても検査は DOM 属性の照合なので結果は変わらない(過度な保証を主張しない)。

### リスク

- `preload="none"` はポスター画像が無いため、再生前は黒い矩形になる(`bg-neutral`)。
  プレビュー側(`preload="metadata"`)と見た目が僅かに変わるが、**プレビュー側は変更しない**。
- iOS Safari の inline 再生は `playsinline` 属性が無いと全画面化することがある。
  既存プレビュー `<video>` も付けていないため**本 TODO では揃えない**(挙動を変えるなら
  プレビューと同時に扱う別件。ここで片側だけ変えると差分の意味が濁る)。

---

## 施策 6: 不変条件の機械化(deny-by-default 目録)

### 守る不変条件

> 「いま受け取れるレンダ成果物はどれか」を `render_jobs` から選ぶ式を書いてよいのは
> `app/Services/Manual/CurrentRenderArtifact.php` ただ 1 ファイルである。

### 変更箇所(新規 3 ファイル)

- `app/Enums/Security/RenderArtifactSelectionKind.php`
- `app/Support/Security/RenderArtifactSelectionInventory.php`
- `tests/Architecture/CurrentRenderArtifactInventoryTest.php`

(配置・命名は T148 の `AdoptedTakeReferenceKind` / `AdoptedTakeReferenceInventory` /
`AdoptedReadyTakeCriterionInventoryTest` の 3 点セットと同型にする。新機構を作らない)

### 検出の定義(母集団)

`Tests\Support\PhpTokenScan::normalize()`(コメント / docblock を数えない)で `app/**/*.php` を走査し、
**同一ファイル内に次の 2 つが同居する**ものを母集団とする:

1. `JobStatus::Succeeded` の参照
2. `renderJobs(` または `RenderJob::query(` の参照

### 区分(`RenderArtifactSelectionKind`)

| case | 意味 |
|---|---|
| `Canonical` | 選択式の実体。`CurrentRenderArtifact` 1 ファイルのみ |
| `SupersessionCriterion` | 世代交代(より新しい succeeded が在るか / 旧世代の収集)の判定。**選択ではない** |

### 目録(実装時点で成立する内容。実コードで確認済み)

```php
'Services/Manual/CurrentRenderArtifact.php' => [
    'kind' => RenderArtifactSelectionKind::Canonical,
    'rationale' => '「いま受け取れる成果物はどれか」の唯一の選択式。playback / download / 詳細画面 props の'
        .'3 消費者が同じ行を指すための場所であり、保持ポリシーと同じ世代定義を持つ。',
],
'Services/Manual/RenderJobService.php' => [
    'kind' => RenderArtifactSelectionKind::SupersessionCriterion,
    'rationale' => 'newerSucceededExists() は「より新しい succeeded が在るか」の世代交代判定であり、'
        .'受け取り対象を 1 件選ぶ式ではない (削除 job と reconcile の前提条件)。',
],
'Services/Manual/RenderPipeline.php' => [
    'kind' => RenderArtifactSelectionKind::SupersessionCriterion,
    'rationale' => 'finalize が自分より古い succeeded 行を集めて削除 job を投入するための収集であり、'
        .'最新 1 件を選ぶ式ではない (id の大小比較のみで latest を使わない)。',
],
```

> 変更前の母集団は 5 ファイル(上記 3 つの代わりに `ManualRenderController` /
> `ManualDownloadController` / `VideoManualController` / `RenderJobService` / `RenderPipeline`)であり、
> 施策 1-4 の適用後に controller 3 本が母集団から**外れる**(`JobStatus::Succeeded` を持たなくなる)。
> `git grep` で実測済み。

### テスト計画(`tests/Architecture/CurrentRenderArtifactInventoryTest.php`)

- [ ] `母集団が空でない(走査が壊れたら fail = 検査が空振りしないことの保証)`
- [ ] `母集団の全ファイルが inventory に登録されている(未登録は fail)`
- [ ] `inventory に走査で見つからない stale entry が無い(exact-fit)`
- [ ] `Canonical は Services/Manual/CurrentRenderArtifact.php ただ 1 ファイル`
- [ ] `各 entry の根拠は 30 文字以上`
- [ ] `SupersessionCriterion の前提: latest( / orderByDesc( を持たず、id の大小比較を持つ`
      — 免除区分に「実は選択式」が紛れ込むのを機械的に防ぐ(前提の機械検査)。
      前提が崩れた瞬間に区分ごと再審査になる

### 検査が空振りしないことの保証(3 点セット)

1. **負のコントロール**: 母集団 0 件で fail する(走査の壊れ・パス変更・token 正規化の変更を検出)
2. **exact-fit**: 未登録も stale も fail(片方向 allowlist にしない)
3. **cap**: `Canonical` は 1 件ちょうど。`SupersessionCriterion` は前提(`latest(`/`orderByDesc(` 不在 +
   id 大小比較の存在)を満たすときだけ有効

### mutation で赤化を確認する手順(実装時に必ず実行し、結果をコミットメッセージに残す)

| # | 変異 | 期待される赤 |
|---|---|---|
| M1 | `VideoManualController::show()` に旧クエリ(`renderJobs()->where('status', JobStatus::Succeeded->value)->latest('id')`)を書き戻す | `CurrentRenderArtifactInventoryTest`「未登録は fail」 |
| M2 | inventory から `Services/Manual/RenderJobService.php` の entry を削除 | 同上(未登録) |
| M3 | inventory に実在しないパスの entry を足す | 「stale entry が無い」 |
| M4 | 走査根を存在しないディレクトリへ差し替える | 「母集団が空でない」(負のコントロール) |
| M5 | `RenderJobService::newerSucceededExists()` を `latest('id')` を使う形へ書き換える | 「SupersessionCriterion の前提」 |
| M6 | `playback()` の published 判定を削除 | Feature「published でない manual の完成動画は 404」 |
| M7 | `playback()` の ability 写像を `'render'` 固定にする | **赤にならない**(現行 policy が同値のため)。だから trip-wire を置く。この事実を「保証しないもの」に明記する |
| M8 | `CurrentRenderArtifact` に `whereNotNull('output_path')` を足す(旧挙動へ戻す) | Unit「最新 succeeded の output_path が NULL なら null」/ Feature「フォールバックせず 404」 |
| M9 | `show()` props の `download` ability 判定を外す | Feature「撮影者には finishedJob=null」 |
| M10 | `RenderPanel` の表示条件を `status === "published"` へ戻す | vitest「finishedJob が null なら出ない」 |

---

## 施策 7: ドキュメント

- `docs/architecture.md`:
  - Services 一覧表に `Manual/CurrentRenderArtifact` を追加
  - 新節 **§完成レンダ成果物の選択と受け取り口** を追加(選択式の定義 = 保持ポリシーと同じ世代定義 /
    playback の 3 層 404 と kind→ability 写像 / props と endpoint の条件が 1 対 1 であること /
    **保証しないもの**)
- `AGENTS.md` ドメイン固有規約に **13. レンダ成果物の選択式の単一化** を追加
  (12 = T148 と同じ書式。gate 名と「保証しないもの」を 1 行で示す)

---

## 保証しないもの(誇張しない)

- **撮影者(project_member)は完成動画を観られない**。`download` ability は編集者のみのままで、
  本 TODO はそれを緩めない。「撮った人が結果に到達する」は**編集者について**成立する。
- **kind→ability 写像が効いていることは behavioral に固定できない**。`VideoManualPolicy::render` と
  `::download` はどちらも `ProjectPolicy::update` に落ちるため、現行では観測差が出ない
  (mutation M7 は赤にならない)。trip-wire は「同値が崩れたことに気づく」ためのものであり、
  写像の正しさの証明ではない。
- **シナリオ編集で `ready` に戻った manual の旧完成動画は、再生も DL もできない**。
  これは既存 download の挙動であり、本 TODO は**揃えるだけで改善しない**。
- **既存 `playbackJob`(preview)の props 露出条件は変えない**。`render` ability を持たない撮影者にも
  (UI では隠れているが)job の存在が渡る。`RenderJobData` は `output_path` も署名 URL も含まないため
  露出は「preview job が在ること」に留まる。これを「直した」と書かない。
- **Architecture gate は静的走査**であり、`JobStatus::Succeeded` の字面を経由しない別基準の選択
  (例: `VideoManualService::latestRenderJobForDisplay` の表示用最新 job)には**沈黙する**。
  文字列変数経由・動的呼び出し・別ファイルへ切り出した同義式も検出しない。
- **署名 URL の TTL とその先の再生可否は保証しない**(`manual.render_playback_url_ttl_minutes`。
  長尺動画で TTL 切れの途中失敗が起きうるかは本 TODO では測っていない)。
- **Browser lane は DOM 契約だけを検査する**。実際に mp4 が再生されること、S3 の CORS 設定、
  iOS Safari のインライン再生挙動は Browser lane では**確認していない**。

---

## 実装順序(fail-first)

1. 施策 6 の gate を**先に**書き、変更前の母集団 5 ファイルで**赤**になることを確認する
   (= 走査が実在の式を捉えていることの確認。負のコントロール)
2. 施策 1(service)+ Unit テスト → 緑
3. 施策 2/3/4(サーバ)+ Feature テスト。**先にテストを書いて赤を確認**してから実装する
4. 施策 5(UI)+ vitest → `pnpm build` → Browser lane
5. inventory を最終形へ更新し、gate を緑にする。M1-M10 の mutation を実行し記録する
6. 施策 7(ドキュメント)

検証コマンド: `composer test` / `composer phpstan` / `vendor/bin/pint --test` /
`pnpm lint` / `pnpm typecheck` / `pnpm test` / `composer test:browser` / `pnpm build`

## 実装モード

| 項目 | 内容 |
|------|------|
| 推奨モード | **standalone** |
| 判断根拠 | 変更が `Manual` ドメインの controller 3 本 + `RenderPanel.svelte` に集中し、`RenderProps` という**共有インターフェースを破壊的に変更**する(旧キーを残さない)。同じファイル群に触れる TODO が並走すると衝突が確実に出るため、単独の worktree で完結させる |
| 競合リスク | `RenderPanel.svelte` / `types/manual.ts` / `VideoManualController` は T148 系の面と同一。T148 は完了済みだが、同面を触る別 TODO が走っている場合は本 TODO を後に回す |


---

## 概念設計 (APPROVED 済み・前提)

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
   (`App\Services\Manual\CurrentRenderArtifact`)。**メソッドは 1 本だけ**:
   `currentSucceeded(VideoManual $manual, RenderKind $kind): ?RenderJob`
   =「同 manual・同 kind の最新 succeeded を 1 件取り、その `output_path` が NULL なら `null`」。
   保持ポリシー (`newerSucceededExists` / `DeleteRenderOutputsJob`) と**同じ世代定義**である。
   **published 判定も ability 判定もこの service に入れない** (Round 1 [Warning] 3)。
   消費者は 3 つ: playback / download / 詳細画面 props。
2. **既存 playback route を kind=render にも開く** (新 route を増やさない)。
   完成動画の再生条件は **download と同一**にする (published + 現行の完成成果物 + `download` ability)。
   preview 側の 404 条件・ability は**一切変えない**。
   認可・404 の順序は既存 download と揃える (Round 1 [Critical] 5 / [Warning] 5):
   ① 層 2 の 404 三段 (project ∈ current org / manual ∈ project / renderJob ∈ manual)
   → ② `kind` から ability を写して `Gate::authorize` (preview→`render` / render→`download`)
   → ③ kind=render のみ `status !== Published` を 404
   → ④ `currentSucceeded($manual, $renderJob->kind)` と**同一行か**を照合し、違えば 404。
   ④ が「旧世代 job id の直叩き」も同時に閉じる。
3. **詳細画面に完成動画のプレイヤーを出す** (`RenderPanel.svelte`)。props は T148 で導入済みの
   `playbackJob: RenderJobProps` と**同じ形**の `finishedJob: RenderJobProps | null` を足す
   (独自形を作らない)。DL ボタンは残し、表示条件を `status === "published" && canManage` から
   **`finishedJob !== null && canManage`** へ変える (`canManage` は外さない。Round 1 [Warning] 2。
   押すと 404 になる異常データの穴も同時に閉じる)。完成動画プレイヤーの表示条件も同じ。

### なぜ job 単位の URL のままにするか

manual 単位の URL (`.../manuals/{manual}/watch`) にすると、再レンダ後も URL 文字列が
変わらないため、**ブラウザが古いメディアを再生し続けうる** (`router.reload()` は
`src` 文字列を変えない)。job id を URL に含める既存の形 (`render-jobs/{renderJob}/playback`) は
世代が変われば URL が変わるので、この問題が構造的に起きない。既存 route を使う根拠でもある。

## 期待効果

- **使命**: **編集者 (project_admin / 組織管理者)** が完成動画をアプリ内で確認できるようになり、
  「DL → 外部プレイヤーで開く」という最後の手作業が消える。
  - **誇張しない**: 本 TODO の完了条件に「撮影者 (project_member) 本人が完成物を観られる」ことは
    **含まない**。`download` ability は編集者のみのままであり、撮影者への視聴開放は別 TODO である
    (Round 1 [Warning] 1 を受けて限定)。
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

施策 6 の**機械化対象は 1 つに絞る** (Round 1 [Warning] 6): 「`app/` 配下で
`JobStatus::Succeeded` と最新 1 件取得 (`latest('id')` / `orderByDesc('id')`) を併用して
`render_jobs` から成果物を選ぶファイル」を deny-by-default の目録で固定し、
登録できるのは `CurrentRenderArtifact` と根拠付き例外だけにする (exact-fit)。
「曖昧な広い目録」にはしない。

route は**増やさない**。DTO は既存 `RenderJobData` をそのまま使う (新 shape を作らない)。

### props の `finishedJob` は endpoint と**同じ条件**で組み立てる (Round 2 [Warning] 1)

`CurrentRenderArtifact` は published 判定を持たないため、props 側で
**`$manual->status === VideoManualStatus::Published` かつ `$user->can('download', $manual)` の
ときだけ** `finishedJob` を組み立て、それ以外は `null` にする
(= endpoint (playback / download) が 302 を返す条件と 1 対 1。Round 3 [Warning])。
`canManage` は UI の表示制御であって秘匿境界ではないため、props 側で ability を評価する。
新しい props (`canDownload` 等) は増やさない (`finishedJob` の null / 非 null が
そのまま判定結果を運ぶ)。これを守らないと、シナリオ編集で `ready` に戻った manual で
「プレイヤーと DL ボタンは出るが押すと 404」が**再発する** (本設計が閉じると言った穴と同種)。
UI 側で `status === "published"` を再判定する二重管理はしない
(判断は props で 1 回。UI 条件は `finishedJob !== null && canManage`)。
書き出し中 (`rendering`) は、完成動画ブロックを既存 DL ボタンと同じ
`{#if rendering}…{:else}` の else 枝に置くことで**構造的に**除外する。

## 制約・前提

- **テナント境界・認可を緩めない** (AGENTS.md セキュリティ不変条件 2/3/9)。
  層 2 の 404 は**三段すべて authorize より前**のまま (Round 1 [Critical] 8):
  ① `{project}` ∈ current organization = `project.in-route-org` middleware +
  `resolveOrganizationProject()` の inline guard、② `{manual}` ∈ `{project}` =
  `routes/web.php` の `Route::scopeBindings()` (`$project->manuals()` 経由)、
  ③ `{renderJob}` ∈ `{manual}` = scopeBindings + controller の
  `$renderJob->video_manual_id !== $manual->id` inline 再検査。
  `projects.manuals.render-jobs.playback` は `NestedRouteDefenseInventory` に登録済み
  (route を増やさないので inventory も不変)。
- **ability は成果物の性質に従う**: kind=preview は `render`、kind=render は `download`。
  現状どちらも `ProjectPolicy::update` に落ちるため**現時点の可否は完全に同値**である。
  - **誇張しない** (Round 2 [Warning] 2): これは「将来 download を視聴者へ開けば UI も
    自動追従する」という保証**ではない**。UI は既存の `canManage` (= `update` ability) を
    使い続けるため、policy が分岐した日には props と UI も併せて変える必要がある。
    ここで正しい ability 名を使うのは、サーバ側の意味を実装に残すためである。
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
- **既存 `playbackJob` (preview) の props 露出条件**: 現状 `render` ability を持たない撮影者にも
  (UI では隠れているが) 渡っている。**本 TODO では変えない** — 今回**新たに増やす**露出
  (`finishedJob`) だけを最初から ability に揃える。`RenderJobData` は `output_path` も署名 URL も
  含まないため実害は「preview job が存在すること」の露出に留まり、挙動変更の回帰面は
  本 TODO の主張 (完成物の視聴) と無関係である。


---

## 関連する現行コード

### app/Http/Controllers/Projects/ManualRenderController.php (全文)

```php
<?php

declare(strict_types=1);

namespace App\Http\Controllers\Projects;

use App\DataTransferObjects\Manual\RenderJobData;
use App\Enums\Manual\JobStatus;
use App\Enums\Manual\RenderKind;
use App\Http\Concerns\ResolvesCurrentOrganization;
use App\Http\Controllers\Controller;
use App\Http\Requests\Projects\TriggerRenderRequest;
use App\Http\Resources\Manual\RenderJobResource;
use App\Models\Project;
use App\Models\RenderJob;
use App\Models\User;
use App\Models\VideoManual;
use App\Services\Manual\RenderJobService;
use App\Services\Render\RenderObjectStorage;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

/**
 * レンダ/プレビューのトリガー (store / preview)、job 状態ポーリング (show)、
 * preview 再生 (playback)。doc/10 §10.3 / 概念設計 §2。
 * トリガーは同一オリジン XHR (JSON 応答)。409/402/422 契約のため JsonResource を返す。
 *
 * 権限分離 (概念設計 Round 1 Critical): ポーリング (view = 撮影者も可) は進捗のみで
 * **署名 URL を一切含めない**。preview 再生は playback route (render ability = 編集者専用)。
 *
 * nested route の URL 整合は ManualAnalysisController と同じ 2 層 (認可より前に 404):
 * 1. {project} ∈ current org (resolveOrganizationProject = inline guard)
 * 2. {manual} ∈ {project}、{renderJob} ∈ {manual} (routes 側の Route::scopeBindings())
 */
class ManualRenderController extends Controller
{
    use ResolvesCurrentOrganization;

    /** 完成レンダトリガー (201 + RenderJobResource)。編集者のみ。保護キー直送は 422 */
    public function store(TriggerRenderRequest $request, Project $project, VideoManual $manual, RenderJobService $render): JsonResponse
    {
        $organization = $this->resolveCurrentOrganization($request);
        // URL 整合 guard: 認可より前に 404 ({manual} ∈ {project} は scopeBindings が担保済み)
        $this->resolveOrganizationProject($organization, $project);
        Gate::authorize('render', $manual);

        // actor = ジョブ実行者 (通知宛先の導出用。Auth から明示的に渡す = payload 不信任)
        $actor = $request->user();
        $job = $render->trigger($project, $manual, $actor instanceof User ? $actor : null);
        $manual->refresh(); // trigger で rendering へ遷移済み

        return RenderJobResource::make(RenderJobData::fromJob($job, $manual))
            ->response($request)
            ->setStatusCode(201);
    }

    /** プレビュートリガー (201 + RenderJobResource)。チケット非消費・status 遷移なし */
    public function preview(TriggerRenderRequest $request, Project $project, VideoManual $manual, RenderJobService $render): JsonResponse
    {
        $organization = $this->resolveCurrentOrganization($request);
        // URL 整合 guard: 認可より前に 404
        $this->resolveOrganizationProject($organization, $project);
        Gate::authorize('render', $manual); // preview も編集者専用 (§10.5)

        $actor = $request->user();
        $job = $render->triggerPreview($project, $manual, $actor instanceof User ? $actor : null);
        $manual->refresh();

        return RenderJobResource::make(RenderJobData::fromJob($job, $manual))
            ->response($request)
            ->setStatusCode(201);
    }

    /** job 状態ポーリング (撮影者も read 可。成果物 URL は含めない = 権限分離) */
    public function show(Request $request, Project $project, VideoManual $manual, RenderJob $renderJob): RenderJobResource
    {
        $organization = $this->resolveCurrentOrganization($request);
        // URL 整合 guard: 認可より前に 404
        $this->resolveOrganizationProject($organization, $project);
        // {renderJob} ∈ {manual} は scopeBindings が担保済み。inline 再検査は二重防御
        if ($renderJob->video_manual_id !== $manual->id) {
            abort(404);
        }
        Gate::authorize('view', $manual);

        return RenderJobResource::make(RenderJobData::fromJob($renderJob, $manual));
    }

    /**
     * preview 再生 (302 → S3 署名 URL)。編集者専用 (render ability)。
     * 404 条件: kind!=preview / succeeded でない / output_path NULL / 最新 succeeded でない
     * (旧世代は実体削除済みの可能性があるため。世代 1 保持の契約と整合)。
     */
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
}

```

### app/Http/Controllers/Projects/ManualDownloadController.php (全文)

```php
<?php

declare(strict_types=1);

namespace App\Http\Controllers\Projects;

use App\Enums\Manual\JobStatus;
use App\Enums\Manual\RenderKind;
use App\Enums\Manual\VideoManualStatus;
use App\Http\Concerns\ResolvesCurrentOrganization;
use App\Http\Controllers\Controller;
use App\Http\Requests\Projects\DownloadManualRequest;
use App\Models\Project;
use App\Models\RenderJob;
use App\Models\VideoManual;
use App\Services\Render\RenderObjectStorage;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Gate;

/**
 * 完成 mp4 のダウンロード (302 → S3 署名 URL。attachment disposition)。download ability。
 * v1 の完成動画取得は本 route のみ (published のインライン再生はスコープ外 = 概念設計 §2)。
 * JSON を返さないため DTO/JsonResource 規約の対象外 (redirect のみ)。
 */
class ManualDownloadController extends Controller
{
    use ResolvesCurrentOrganization;

    public function show(DownloadManualRequest $request, Project $project, VideoManual $manual, RenderObjectStorage $storage): RedirectResponse
    {
        $organization = $this->resolveCurrentOrganization($request);
        // URL 整合 guard: 認可より前に 404 ({manual} ∈ {project} は scopeBindings が担保済み)
        $this->resolveOrganizationProject($organization, $project);
        Gate::authorize('download', $manual);

        // 完成物が存在しない (published でない / succeeded render なし) は 404 (409 系ではない)
        if ($manual->status !== VideoManualStatus::Published) {
            abort(404);
        }
        /** @var RenderJob|null $job */
        $job = $manual->renderJobs()
            ->where('kind', RenderKind::Render->value)
            ->where('status', JobStatus::Succeeded->value)
            ->whereNotNull('output_path')
            ->latest('id')
            ->first();
        if ($job === null || $job->output_path === null) {
            abort(404);
        }

        // filename の sanitize (CR/LF 除去・RFC 5987 + ASCII fallback) は Storage 側 helper が担う
        $filename = $manual->title.'.mp4';

        return redirect()->away($storage->temporaryDownloadUrl($job->output_path, $filename));
    }
}

```

### app/Http/Controllers/Projects/VideoManualController.php (show のみ抜粋)

```php
oject $project, VideoManualService $manuals): RedirectResponse
    {
        $organization = $this->resolveCurrentOrganization($request);
        // URL 整合 guard: 認可より前に 404
        $this->resolveOrganizationProject($organization, $project);
        Gate::authorize('create', [VideoManual::class, $project]);

        $user = $request->user();
        Assert::isInstanceOf($user, User::class);

        $title = $request->validated('title');
        Assert::string($title);
        // 入力名は category (保護キー category_id とは別名)。null = 未分類
        $category = $request->validated('category');
        Assert::nullOrIntegerish($category);
        // SOP 同時アップロード (任意)
        $document = $request->validated('document');
        Assert::nullOrIsInstanceOf($document, UploadedFile::class);

        $manual = $manuals->create($project, $title, $category === null ? null : (int) $category, $user->id, $document);

        return redirect()
            ->route('projects.manuals.show', [$project, $manual])
            ->with('success', '動画マニュアルを作成しました');
    }

    /** 詳細 (撮影者も閲覧可) */
    public function show(Request $request, Project $project, VideoManual $manual, SeoManager $seo, VideoManualService $manuals): Response
    {
        $organization = $this->resolveCurrentOrganization($request);
        // URL 整合 guard: 認可より前に 404 ({manual} ∈ {project} は scopeBindings が担保済み)
        $this->resolveOrganizationProject($organization, $project);
        Gate::authorize('view', $manual);

        // 動的固有名の per-page タイトル (noindex 維持。projects.show の参考実装踏襲)
        $seo->setPrivateTitle($manual->title);

        $user = $request->user();
        Assert::isInstanceOf($user, User::class);

        $category = $manual->category;

        // stale な失敗 (失敗確定後に scenario 保存が成立) は job=null で抑制する (T032 / F-1-1)
        $analysisJob = $manuals->displayAnalysisJob($manual);
        $renderJob = $manuals->displayRenderJob($manual);
        $previewJob = $manuals->displayPreviewJob($manual);
        // 再生できるプレビュー (最新 succeeded preview)。**id だけでなく行そのもの**を props に載せる:
        // 動画 URL と「黒背景が何カット分か」の注記が同一オブジェクトから出るため、
        // 最新 preview job と再生対象が別世代になる穴が構造的に消える (T148)。
        // succeeded preview のみを見るため staleness 抑制の対象外 (不変)。
        $playbackJob = $manual->renderJobs()
            ->where('kind', RenderKind::Preview->value)
            ->where('status', JobStatus::Succeeded->value)
            ->whereNotNull('output_path')
            ->latest('id')
            ->first();

        return Inertia::render('Manuals/Show', [
            'project' => [
                'id' => $project->id,
                'name' => $project->name,
            ],
            'manual' => [
                'id' => $manual->id,
                'title' => $manual->title,
                'status' => $manual->status->value,
                'category' => $category === null
                    ? null
                    : ['id' => $category->id, 'name' => $category->name],
                'created_at' => $manual->created_at?->format('Y-m-d H:i') ?? '',
            ],
            // AI 解析パネル (最新 job + 手順書有無)。AnalysisJobData::toArray() と対
            'analysis' => [
                'job' => $analysisJob === null
                    ? null
                    : AnalysisJobData::fromJob($analysisJob, $manual)->toArray(),
                'hasDocument' => $manual->sourceDocuments()->exists(),
            ],
            // レンダパネル (最新 render job / 最新 preview job / 再生可能 preview)。RenderProps と対
            'render' => [
                'job' => $renderJob === null
                    ? null
                    : RenderJobData::fromJob($renderJob, $manual)->toArray(),
                'previewJob' => $previewJob === null
                    ? null
                    : RenderJobData::fromJob($previewJob, $manual)->
```

### app/DataTransferObjects/Manual/RenderJobData.php

```php
<?php

declare(strict_types=1);

namespace App\DataTransferObjects\Manual;

use App\Enums\Manual\JobStatus;
use App\Enums\Manual\RenderErrorCode;
use App\Enums\Manual\RenderKind;
use App\Enums\Manual\RenderStep;
use App\Enums\Manual\VideoManualStatus;
use App\Models\RenderJob;
use App\Models\VideoManual;

/**
 * RenderJob の表示 shape (show props / ポーリング応答 / トリガー 201 の共通 DTO)。
 * **成果物 URL / output_path は持たない** (ポーリングと成果物アクセスの権限分離 = 概念設計 §7)。
 * TS 側 types/manual.ts の RenderJobProps と対で保守する。
 */
final readonly class RenderJobData
{
    public function __construct(
        public int $id,
        public RenderKind $kind,
        public JobStatus $status,
        public ?RenderStep $step,
        public ?int $progress,
        public ?string $error,
        public ?RenderErrorCode $errorCode,
        public VideoManualStatus $manualStatus,
        /**
         * 生成物に含まれたプレースホルダ (黒背景) クリップ数。
         * null = その動画について言えることが無い (未完了 / T148 以前の succeeded 行)。
         * **null を 0 と同一視しない** (0 は「黒背景ゼロで生成された」という積極的な事実)。
         */
        public ?int $placeholderCutCount,
    ) {}

    public static function fromJob(RenderJob $job, VideoManual $manual): self
    {
        return new self(
            id: $job->id,
            kind: $job->kind,
            status: $job->status,
            step: $job->step,
            progress: $job->progress,
            error: $job->error,
            errorCode: $job->error_code,
            manualStatus: $manual->status,
            placeholderCutCount: $job->placeholder_cut_count,
        );
    }

    /**
     * @return array{id: int, kind: string, status: string, step: string|null, progress: int|null,
     *   error: string|null, error_code: string|null, manual_status: string,
     *   placeholder_cut_count: int|null}
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'kind' => $this->kind->value,
            'status' => $this->status->value,
            'step' => $this->step?->value,
            'progress' => $this->progress,
            'error' => $this->error,
            'error_code' => $this->errorCode?->value,
            'manual_status' => $this->manualStatus->value,
            'placeholder_cut_count' => $this->placeholderCutCount,
        ];
    }
}

```

### app/Services/Render/RenderObjectStorage.php (署名 URL の 2 種)

```php
his->disk()->writeStream($key, $stream);
        } finally {
            if (is_resource($stream)) {
                fclose($stream);
            }
        }
    }

    /** preview 再生用の署名 GET URL (TTL は config manual.render_playback_url_ttl_minutes) */
    public function temporaryPlaybackUrl(string $key): string
    {
        return $this->disk()->temporaryUrl(
            $key,
            now()->addMinutes(config()->integer('manual.render_playback_url_ttl_minutes')),
        );
    }

    /**
     * DL 用署名 URL (attachment disposition)。filename 契約 (詳細レビュー Round 1 で明文化):
     * - filename は CR/LF・制御文字を除去し、Content-Disposition は
     *   RFC 5987 (filename*=UTF-8''...) + ASCII fallback (filename="...") の両建てで署名に含める
     * - ヘッダ注入 (改行) 不能であることを Unit テストで固定
     */
    public function temporaryDownloadUrl(string $key, string $filename): string
    {
        return $this->disk()->temporaryUrl(
            $key,
            now()->addMinutes(config()->integer('manual.render_playback_url_ttl_minutes')),
            ['ResponseContentDisposition' => $this->contentDisposition($filename)],
        );
    }

    /** オブジェクト削除 (存在しないキーは no-op = 冪等) */
    public function delete(string $key): void
    {
        $this->disk()->delete($key);
    }

    /** manual 配下のレンダ出力 prefix (DeleteRenderOutputsJob の過大削除防止に使う) */
    public function keyPrefixFor(VideoManual $manual): string
    {
        return "projects/{$manual->project_id}/manuals/{$manual->id}/";
    }

    /**
     * Content-Disposition 値の構築 (attachment 固定・ヘッダ注入不能)。
     * - 制御文字 (CR/LF 含む)・DEL を除去
     * - ASCII fallback: 非 ASCII を '_' 化し、quoted-string を壊す `"` `\` も '_' 化
     * - RFC 5987: UTF-8 percent-encoding (rawurlencode)
     */
    public function contentDisposition(string $filename): string
    {
        $sanitized = 
```

### app/Services/Manual/RenderJobService.php (世代交代判定)

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

### app/Policies/VideoManualPolicy.php (render / download)

```php
    /** レンダ/プレビューの実行: プロジェクトを操作できる人 (編集者)。撮影者は不可 (§10.5) */
    public function render(User $user, VideoManual $manual): bool
    {
        $project = $manual->project;

        return $project !== null && $this->projectPolicy->update($user, $project);
    }

    /** 完成動画のダウンロード: 編集者のみ (§10.5。ポーリングは view = 撮影者も可) */
    public function download(User $user, VideoManual $manual): bool
    {
        $project = $manual->project;

        return $project !== null && $this->projectPolicy->update($user, $project);
    }
```

### resources/js/components/features/manual/RenderPanel.svelte (関連部分)

```svelte
  import type { RenderJobProps, TakeCoverageProps, VideoManualStatus } from "@/types/manual";
    import { RENDER_STEP_LABELS } from "@/types/manual";

    /**
     * レンダパネル (完成動画生成・プレビュー生成・進捗ポーリング・DL 導線)。概念設計 §8。
     * - 起動は POST .../render / .../preview (XHR)。402/409/422 は押下時にサーバの
     *   メッセージを表示 (必須未充足でもボタンは disabled にしない = DESIGN.md)
     * - ポーリングは 1 コンポーネント内で scheduler 1 本 (render/preview を別タイマーで
     *   追わない)。単一 interval が追跡中 job id 集合を順に fetch し、終端条件のみ kind 別分岐
     *   (render: succeeded → router.reload() / preview: succeeded → <video> 表示)
     * - failed + error_code=scenario_version_changed は「作り直す」CTA (preview 再 POST)
     * - 事前告知 (coverage) / 事後説明 (playbackJob.placeholder_cut_count) は**別概念**:
     *   前者は「今から作ると黒背景が出る」、後者は「今再生している動画に黒背景があった」。
     *   どちらもボタンを止めない (必須条件未充足を理由に disabled にしない = 禁止事項 8)
     */
    interface Props {
        projectId: number;
        manualId: number;
        manualStatus: VideoManualStatus;
        job: RenderJobProps | null;
        previewJob: RenderJobProps | null;
        playbackJob: RenderJobProps | null;
        coverage: TakeCoverageProps;
        canManage: boolean;
    }

    let {
        projectId,
        manualId,
        manualStatus,
        job,
        previewJob,
        playbackJob: playbackJobProp,
        coverage,
        canManage,
    }: Props = $props();

    // 作業状態 (props から一度だけ seed し、以後は XHR 応答で更新する)
    // svelte-ignore state_referenced_locally
    let renderJob = $state<RenderJobProps | null>(job);
    // svelte-ignore state_referenced_locally
    let preview = $state<RenderJobProps | null>(previewJob);
    // svelte-ignore state_referenced_locally
    let playbackJob = $state<RenderJobProps | null>(playbackJobProp);
    // svelte-ignore state_referenced_locally
    let status = $state<VideoManualStatus>(manualStatus);
    let starting = $state(false);
    // 起動失敗の表示モデル (message + 402 残高不足時の購入導線)。402 (残高不足) のときのみ
    // showPurchaseLink=true (code 厳格一致。他エラーで誤表示しない)。
    type StartError 
...
      const jobBody = body as RenderJobProps;
            if (kind === "render") {
                renderJob = jobBody;
                status = jobBody.manual_status;
            } else {
                preview = jobBody;
            }
            return;
        }
        // 402 (残高不足) / 409 (競合) / 422 (採用テイク欠落・尺超過) はサーバのメッセージを表示。
        // 起動失敗は source 別 state に積む (完成動画/プレビューの帰属を保つ)。
        const failure: StartError = {
            message:
                extractMessage(body) ??
                "書き出しを開始できませんでした。時間をおいて再度お試しください。",
            showPurchaseLink: res.status === 402 && isInsufficientTickets(body),
        };
        if (kind === "render") renderStartError = failure;
        else previewStartError = failure;
    }

    /** 402/409 の { message } と 422 の { message, errors } からユーザー向け文言を取り出す */
    function extractMessage(body: unknown): string | null {
        if (body === null || typeof body !== "object") return null;
        const message = (body as { message?: unknown }).message;
        return typeof message === "string" && message !== "" ? message : null;
    }
</script>

<Card padding="lg">
    <div class="flex items-center justify-between gap-3">
        <h2 class="text-h3">完成動画</h2>
        {#if canManage && !rendering}
            <div class="flex items-center gap-2">
                <Button
                    variant="secondary"
                    onclick={() => void start("preview")}
                    loading={starting && !confirmingRender}
                    testId="preview-button"
                >
                    <Play class="size-4" />
                    プレビュー生成
                </Button>
                {#if status === "ready"}
                    <Button onclick={requestRender} testId="render-button">
                        <Clapperboard class="size-4" />
                        完成動画を生成
                    </Button>
                {/if}
            </div>
        {/if}
    </div>

    {#if rendering}
        <div class="mt-4 flex flex-col gap-2" data-testid="render-progress">
            <div class="flex items-center gap-2 text-body text-text-secondary">
                <LoaderCircle class="size-4 animate-spin" />
                <span data-testid="render-step-label">{stepLabel}</span>
            </div>
            <div
                class="h-2 w-full overflow-hidden rounded-md bg-neutral"
                role="progressbar"
                aria-valuenow={renderJob?.progress ?? 0}
                aria-valuemin={0}
                aria-valuemax={100}
            >
                <div
                    class="h-full rounded-md bg-primary transition-all"
                    style={`width: ${renderJob?.progress ?? 0}%`}
                ></div>
            </div>
            <p class="text-caption text-text-
...
secondary"
                            onclick={() => void start("preview")}
                            testId="preview-retry-button"
                        >
                            <Play class="size-4" />
                            プレビューを作り直す
                        </Button>
                    </div>
                {/if}
            {/if}
            {#if previewStartError}
                <div data-testid="preview-start-error">
                    <Alert type="danger" title="プレビューの生成を開始できませんでした">
                        {previewStartError.message}
                        {#if previewStartError.showPurchaseLink}
                            <span class="ml-1">
                                <TextLink href="/purchase-tickets" testId="preview-purchase-link">
                                    チケットを購入する
                                </TextLink>
                            </span>
                        {/if}
                    </Alert>
                </div>
            {/if}
            {#if playbackJob !== null && !previewInFlight}
                {#if playbackNote !== null}
                    <!-- 事後説明: 注記と動画 URL は同一の playbackJob から出る (別世代の値で説明しない) -->
                    <p
                        class="text-caption text-text-secondary"
                        data-testid="preview-placeholder-note"
                    >
                        このプレビューは {playbackNote}
                        件のカットに使用できる採用テイクがないため、その区間が黒背景になっています。
                    </p>
                {/if}
                <!-- svelte-ignore a11y_media_has_caption (プレビュー動画の字幕は焼き込み済み) -->
                <!-- aria-label は固定文言でよい: playbackJob の供給源は初期値 (Controller が
                     kind=Preview ∧ status=Succeeded で抽出) と poll の preview 分岐だけで、
                     render job が入る経路が無い (完成動画と取り違わない)。 -->
                <video
                    controls
                    preload="metadata"
                    class="w-full rounded-md bg-neutral"
                    src={`/projects/${projectId}/manuals/${manualId}/render-jobs/${playbackJob.id}/playback`}
                    aria-label="プレビュー動画"
                    data-testid="preview-video"
                ></video>
            {/if}
        </div>
    {/if}
</Card>

<ConfirmDialog
    bind:open={confirmingRender}
    title="完成動画の生成"
    message="チケットを消費して完成動画を書き出します。書き出し中はシナリオ編集・撮影ができません。実行しますか？"
    confirmLabel="生成する"
    processing={starting}
    onConfirm={() => void start("render")}
    testId="render-dialog"
/>

```

### resources/js/types/manual.ts (RenderProps 周辺)

```ts
ll;
    error: string | null;
    error_code: RenderErrorCode | null;
    manual_status: VideoManualStatus;
    /**
     * 生成物に含まれたプレースホルダ (黒背景) クリップ数。
     * null = その動画について言えることが無い (未完了 / T148 以前の succeeded 行)。
     * **null を 0 と同一視しない** (0 は「黒背景ゼロで生成された」という積極的な事実)。
     */
    placeholder_cut_count: number | null;
}

/** PHP: App\DataTransferObjects\Manual\TakeCoverageData::toProps() と対 */
export interface TakeCoverageProps {
    /** カット総数 */
    total_cuts: number;
    /** 使用できる採用テイクがないカット数 (**打ち切らない全件数**) */
    missing_count: number;
    /** 該当カットの表示ラベル (先頭 10 件で打ち切られる。件数は missing_count が正) */
    missing_labels: string[];
}

/** PHP: App\Enums\Manual\RenderConflictType と対 (値集合同期テストあり) */
export type RenderConflictType =
    | "in_flight"
    | "status_not_renderable"
    | "status_not_previewable"
    | "org_preview_limit";

/** PHP: RenderConflictResource と対 (render/preview 409 ボディ。code 厳格一致) */
export interface RenderConflictBody {
    code: "render_conflict";
    conflict_type: RenderConflictType;
    message: string;
}

/** PHP: VideoManualController::show の render props と対 */
export interface RenderProps {
    /** 最新 kind=render の job (無ければ null) */
    job: RenderJobProps | null;
    /** 最新 kind=preview の job (無ければ null) */
    previewJob: RenderJobProps | null;
    /**
     * 再生可能な最新 succeeded preview の job (無ければ null)。
     * 動画 URL と黒背景の注記が同一オブジェクトから出る (別世代の値で説明しないため)。
     */
    playbackJob: RenderJobProps | null;
    /**
     * 採用テイクの充足状況 (描画時点のスナップショット。常に最新ではない)。
     * 生成物の実績は playbackJob.placeholder_cut_count が語る (別概念なので混ぜない)。
     */
    coverage: TakeCoverageProps;
}

/** PHP: App\Enums\Manual\ScenarioConflictType と対 (discriminated union) */
export type ScenarioConflictType = "version_mismatch" | "rendering" | "analyzing";

/** PHP: ScenarioConflictResource と対 (409 ボディ。code 厳格一致で自分宛て応答のみ処理する) */
export interface ScenarioConflictBody {
    code: "scenario_conflict";
    conflict_type: ScenarioConflictType;
    message: string;
    current_version: number;
}

```

### database/factories/RenderJobFactory.php (state 一覧)

```php
ual_id' => VideoManual::factory(),
            'kind' => RenderKind::Render->value,
            'status' => JobStatus::Queued->value,
            'step' => null,
            'progress' => null,
            'scenario_version' => 0,
            'ticket_reservation_id' => null,
            'output_path' => null,
            'placeholder_cut_count' => null,
            'error' => null,
            'error_code' => null,
        ];
    }

    /** プレビュージョブとして作る (チケット非消費種別) */
    public function preview(): static
    {
        return $this->state(fn () => ['kind' => RenderKind::Preview->value]);
    }

    /** 指定マニュアル配下に作る */
    public function forManual(VideoManual $manual): static
    {
        return $this->state(fn () => ['video_manual_id' => $manual->id]);
    }

    /** 実行中 (compose 段) の状態 */
    public function running(): static
    {
        return $this->state(fn () => [
            'status' => JobStatus::Running->value,
            'step' => RenderStep::Compose->value,
            'progress' => 5,
        ]);
    }

    /**
     * 成功確定の状態 (output_path 付き)。
     * アプリが生成した succeeded 行は必ず件数を持つため既定は 0 (黒背景なしで生成された)。
     */
    public function succeeded(string $outputPath, int $placeholderCutCount = 0): static
    {
        return $this->state(fn () => [
            'status' => JobStatus::Succeeded->value,
            'progress' => 100,
            'output_path' => $outputPath,
            'placeholder_cut_count' => $placeholderCutCount,
        ]);
    }

    /**
     * T148 **以前**から在る succeeded 行の再現 (placeholder_cut_count は null)。
     * backfill しない契約 (null は 0 と同一視しない) の UI 分岐を検証するための fixture。
     */
    public function legacySucceeded(string $outputPath): static
    {
        return $this->succeeded($outputPath)->state(fn () => ['placeholder_cut_count' => null]);
    }

    /** 失敗確定の状態 */
    public function failed(
        RenderErrorCode $code = RenderErrorCode::Internal,
        string $error = '書き出しに失敗しました',
    ): static {
        return $this->state(fn 
```

### tests/Feature/Manual/RenderPollingAndArtifactAccessTest.php (既存の playback/download テスト)

```php
も 200 (view 権限)', function (): void {
    [$organization, , $project, $manual] = artifactAccessContext();
    $member = artifactAccessMember($organization, $project);
    $job = RenderJob::factory()->forManual($manual)->create();

    $this->actingAs($member)->getJson(
        "/projects/{$project->id}/manuals/{$manual->id}/render-jobs/{$job->id}",
    )->assertOk();
});

test('ポーリング: cross-manual の renderJob は 404 (scopeBindings + inline 再検査)', function (): void {
    [, $owner, $project, $manual] = artifactAccessContext();
    $otherManual = VideoManual::factory()->forProject($project)->create();
    $job = RenderJob::factory()->forManual($otherManual)->create();

    $this->actingAs($owner)->getJson(
        "/projects/{$project->id}/manuals/{$manual->id}/render-jobs/{$job->id}",
    )->assertNotFound();
});

test('ポーリング: cross-org は 404', function (): void {
    [, , $project, $manual] = artifactAccessContext();
    $job = RenderJob::factory()->forManual($manual)->create();
    [, $stranger] = createOrganizationWithOwner('別組織');

    $this->actingAs($stranger)->getJson(
        "/projects/{$project->id}/manuals/{$manual->id}/render-jobs/{$job->id}",
    )->assertNotFound();
});

test('playback: 最新 succeeded preview は 302 (S3 署名 URL へ redirect)', function (): void {
    [, $owner, $project, $manual] = artifactAccessContext();
    $key = "projects/{$project->id}/manuals/{$manual->id}/previews/v2-9.mp4";
    $job = RenderJob::factory()->forManual($manual)->preview()->succeeded($key)->create();

    $response = $this->actingAs($owner)->get(
        "/projects/{$project->id}/manuals/{$manual->id}/render-jobs/{$job->id}/playback",
    );

    $response->assertRedirect("https://signed.example/{$key}");
});

test('playback の 404 マトリクス: kind=render / 未完了 / output_path NULL / 旧世代', function (): void {
    [, $owner, $project, $manual] = artifactAccessContext();
    $base = "/projects/{$project->id}/manuals/{$manual->id}/render-jobs";

    // kind=render の succeeded (download route が正)
    $renderJob = RenderJob::factory()->forManual($manual)->succeeded('projects/x/renders/v2-1.mp4')->create();
    $this->actingAs($owner)->get("{$base}/{$renderJob->id}/playback")->assertNotFound();

    // 未完了 preview
    $runningPreview = RenderJob::factory()->forManual($manual)->preview()->running()->create();
    $this->actingAs($owner)->get("{$base}/{$runningPreview->id}/playback")->assertNotFound();

    // output_path NULL (世代交代で削除済み)
    $nullPathPreview = RenderJob::factory()->forManual($manual)->preview()->create([
        'status' => 'succeeded',
        'output_path' => null,
    ]);
    $this->actingAs($owner)->get("{$base}/{$nullPathPreview->id}/playback")->assertNotFound();

    // 旧世代 (より新しい succeeded preview が存在する)
    $old = RenderJob::factory()->forManual($manual)->preview()->succeeded('projects/x/previews/v1-1.mp4')->create();
    RenderJob::factory()->forManual($manual)->preview()->succeeded('projects/x/previews/v2-2.mp4')->create();
    $this->actingAs($owner)->get("{$base}/{$old->id}/playback")->assertNotFound();
});

test('playback: 撮影者は 403 (render ability = 編集者専用)', function (): void {
    [$organization, , $project, $manual] = artifactAccessContext();
    $member = artifactAccessMember($organization, $project);
    $job = RenderJob::factory()->forManual($manual)->preview()->succeeded('projects/x/previews/v2-1.mp4')->create();

    $this->actingAs($member)->get(
        "/projects/{$project->id}/manuals/{$manual->id}/render-jobs/{$job->id}/playback",
    )->assertForbidden();
});

test('download: published + 最新 succeeded render は 302 (署名 URL へ redirect)', function (): void {
    [, $owner, $project, $manual] = artifactAccessContext();
    $manual->forceFill(['status' => VideoManualStatus::Published])->save();
    $key = "projects/{$project->id}/manuals/{$manual->id}/renders/v2-1.mp4";
    RenderJob::factory()->forManual($manual)->succeeded($key)->create();

    $response = $this->actingAs($owner)->get(
        "/projects/{$project->id}/manuals/{$manual->id}/download",
    );

    $response->assertRedirect("https://signed.example/{$key}");
});

test('download: lang=ja は 302 / lang=en は 422 / lang 省略は 302', function (): void {
    [, $owner, $project, $manual] = artifactAccessContext();
    $manual->forceFill(['status' => VideoManualStatus::Published])->save();
    RenderJob::factory()->forManual($manual)->succeeded('projects/x/renders/v2-1.mp4')->create();
    $url = "/projects/{$project->id}/manuals/{$manual->id}/download";

    $this->actingAs($owner)->get("{$url}?lang=ja")->assertRedirect();
    $this->actingAs($owner)->getJson("{$url}?lang=en")->assertUnprocessable();
    $this->actingAs($owner)->get($url)->assertRedirect();
});

test('download: published でない / succeeded render なしは 404 (完成物が存在しない)', function (): void {
    [, $owner, $project, $manual] = artifactAccessContext();
    $url = "/projects/{$project->id}/manuals/{$manual->id}/download";

    // ready (完成物なし)
    $this->actingAs($owner)->get($url)->assertNotFound();

    // published だが succeeded render がない (異常データ防御)
    $manual->forceFill(['status' => VideoManualStatus::Published])->save();
    $this->actingAs($owner)->get($url)->assertNotFound();

    // succeeded だが output_path NULL
    RenderJob::factory()->forManual($manual)->create(['status' => 'succeeded', 'output_path' => null]);
    $this->actingAs($owner)->get($url)->assertNotFound();
});

test('download: 撮影者は 403 (download ability = 編集者専用)', function (): void {
    [$organization, , $project, $manual] = artifactAccessContext();
    $member = artifactAccessMember($organization, $project);
    $manual->forceFill(['status' => VideoManualStatus::Published])->save();
    RenderJob::factory()->forManual($manual)->succeeded('projects/x/renders/v2-1.mp4')->create();

    $this->actingAs($member)->get(
        "/projects/{$project->id}/manuals/{$manual->id}/download",
    )->assertForbidden();
});

test('download / playback: cross-org は 404 (存在オラクル封じ)', function (): void {
    [, , $project, $manual] = artifactAccessContext();
    $manual->forceFill(['status' => VideoManualStatus::Published])->save();
    $job = RenderJob::factory()->forManual($manual)->preview()->succeeded('projects/x/previews/v2-1.mp4')->create();
    [, $stranger] = createOrganizationWithOwner('別組織');

    $this->actingAs($stranger)->get(
        "/projects/{$project->id}/manuals/{$manual->id}/download",
    )->assertNotFound();
    $this->actingAs($stranger)->get(
        "/projects/{$project->id}/manuals/{$manual->id}/render-jobs/{$job->id}/playback",
    )->assertNotFound();
});

```

### 既存の deny-by-default 目録の見本 (T148)

```php
ferenceKind, rationale: string}>
     */
    public static function entries(): array
    {
        return [
            'Services/Manual/AdoptedReadyTakeCoverage.php' => [
                'kind' => AdoptedTakeReferenceKind::Canonical,
                'rationale' => '判定式の実体。render の 422 と preview の事前告知・Placeholder 分岐が'
                    .'同じ述語 isMissing() を通るための唯一の場所 (bug-hunt F-1-01 の再発防止)。',
            ],
            'Services/Manual/CutSequencer.php' => [
                'kind' => AdoptedTakeReferenceKind::RelationWiring,
                'rationale' => '表示順カット列の取得で with(adoptedTake) の eager load を張るだけで、'
                    .'ready 判定も採用有無の判定も持たない (N+1 回避のための構造上の参照)。',
            ],
            'Services/Manual/RenderJobService.php' => [
                'kind' => AdoptedTakeReferenceKind::DelegatedToCoverage,
                'rationale' => '充足判定は AdoptedReadyTakeCoverage へ委譲済みで、残る参照は'
                    .'尺上限ソフトゲートが採用テイクの duration_ms を読む 1 箇所だけである。',
            ],
            'Services/Manual/RenderPipeline.php' => [
                'kind' => AdoptedTakeReferenceKind::DelegatedToCoverage,
                'rationale' => 'clipSpecFor が isMissing() を呼んで Placeholder 分岐を決め、'
                    .'非欠落側でのみ素材パス (video_path) 取得のため take 実体を読む。',
            ],
            'Models/Cut.php' => [
                'kind' => AdoptedTakeReferenceKind::RelationWiring,
                'rationale' => 'adoptedTake の belongsTo relation 宣言そのもの。'
                    .'判定式は一切持たず、参照の起点を提供するだけのモデル定義である。',
            ],
            'DataTransferObjects/Capture/CaptureManualDetailData.php' => [
                'kind' => AdoptedTakeReferenceKind::DifferentCriterion,
                'rationale' => '撮影ナビの表示用に採用テイクの実体を読むだけで ready 判定はしない。'
                    .'撮影中の端末に「今どれを採用しているか」を見せる別概念の面である。
```

---

上記の詳細設計書をレビューせよ。
