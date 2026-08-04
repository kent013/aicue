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
5. LLM 呼び出しの Prism 直呼び(`app/Prompts/` の factory 経由のみ。PromptGuardrailTest が検出)
6. prompt 文字列のコード直書き(`resources/prompts/*.yaml` に置く)
7. 操作系 POST の応答での `redirect()->intended()`(ログイン直後フロー専用。
   招待送信等は `back()->with(...)` で完結させる)
8. 必須条件未充足を理由にボタンを disabled にする UI(押下時にエラー表示する。DESIGN.md)
9. Artifact の使用(Artifact ツールでの成果物公開を行わない。成果物はリポジトリ内のファイルとして出力する)

【思考原則 — 全議論に適用】
まず仮説を立てろ。何を検証したいのか、なぜそう考えるのか、どうなれば成功と判断するのかを明確にしてから手を動かせ。仮説なき改善はただの試行錯誤であり、結果から学ぶことができない。

データに真摯に向き合え。成果だけでなく、多様性の変化、構造の揺らぎ、想定外のパターン — 全てが判断材料になる。数値を見て即座に閾値を弄るな。何が起きているのかを理解し、なぜそうなったのかを考え、どの方向に進むべきかを判断してから手を動かせ。

先人の知恵を探せ。自分たちだけで登る必要はない。乗るべき巨人の肩があるなら乗れ。

機能の名前に立ち返れ。名前はその機能が果たすべき役割を示している。現在の設計がその役割を果たしているか、常に問え。

仕組みが機能していない段階で値を弄るな。閾値チューニングやフィールド追加は、設計の方向性が正しいと確認できてから行え。方向性が間違っているなら、値をいくら調整しても意味はない。設計そのものを見直せ。成果が出なければ早期に見切り、次の仮説へ進め。

【ツール使用制限】
コマンド実行・ファイル書き込みは一切行わず、提供されたテキストの分析に集中すること。ファイル読み込みは許可。

あなたはWebアプリケーション（Laravel + Svelte）の改善に関する概念設計レビュアーです。

（アプリの使命・禁止事項は app-codex-review スキルにより AGENTS.md から自動挿入済み）

【レビュー観点】
1. 使命との整合性: この改善はアプリの使命（North Star）に本質的に貢献するか
2. 禁止事項違反: 上記禁止事項に抵触していないか
3. 実現可能性: 技術的に実現可能か（Laravel 12 + Svelte 5 + Inertia.js）
4. 期待効果の妥当性: 主張している効果は合理的に期待できるか
5. リスク: 重大な副作用・後退の可能性はないか
6. スコープの適切さ: 過大または過小になっていないか
7. **型安全性**: DTO/JsonResourceパターンに沿っているか。PHPStan level 10を通せるか

【本件固有の注意】
本設計は「直前にマージ済みの 2 タスク (T089 / T090) が意図的に未解決のまま残した 6 論点について、決定を下して恒久ドキュメントに固定する」ことが主目的である。したがって「決めただけで終わり (実装が必要なのに文書化で逃げている)」と「作りすぎ (決めれば足りるのに機構を新設している)」の両方を厳しく見てほしい。特に:
- T089-a を「許容」とした判断が妥当か。塞ぐべきという反論があるなら根拠付きで示すこと
- T089-b を「popstate プローブではなく AuthenticationException 契機の Inertia::clearHistory()」で塞ぐ判断が妥当か。副作用の見落としがないか
- T090-a で金銭挙動を変えない判断と、切替見積りの粒度が妥当か
- T090-b の追加 UI が最小か (過剰ではないか / 不足ではないか)
- T090-d で Plan Factory を作らない判断が妥当か

【出力形式】
- 全体判定: APPROVED / CHANGES_REQUESTED
- 各観点ごとに [Critical] [Warning] [Suggestion] で分類して指摘
- Critical/Warning には修正提案を必ず添える
- 日本語で出力

---
## 概念設計

# 概念設計: T089 / T090 の残存リスク確定 (t089-t090-residual-risk)

対象は **直前に main へマージ済みの T089 / T090 が意図的に未解決のまま残した 6 論点**。
本設計の主目的は「決定を下し、次に読む人が同じ問いを再燃させない形で固定すること」であり、
コード変更は決定に必要な最小限に留める。

出典 (正本):

- `devnotes/20260804-0021-logout-history-pii-guard/` (T089)
- `devnotes/20260804-0021-plan-change-path/` (T090)
- 実装側の残存リスク記述: `app/Http/Responses/Fortify/LogoutResponse.php` docblock /
  `docs/supported-browsers.md` 「未対応事項」

---

## 背景・課題

### 6 つの未確定論点

| # | 論点 | T089/T090 が残した状態 |
|---|---|---|
| T089-a | 別タブに残る Inertia 履歴 | 「BroadcastChannel 等の自前機構が要る」として文書化のみ |
| T089-b | セッション期限切れ / 他デバイスからの強制ログアウト後の履歴復元 | 「popstate ごとのサーバ問い合わせが要る」として文書化のみ |
| T090-a | proration の方針 (`create_prorations` 維持か `always_invoice` か) | 現状維持。切替コストの見積りは未記録 |
| T090-b | ダウングレードで新プラン上限を超える場合 | ブロックせず確認ダイアログで告知のみ |
| T090-c | Portal の `subscription_update` 無効維持 | T090 が実装で宣言を満たしたが「確定」宣言は未記録 |
| T090-d | `enterprise` プランの 422 テストが無い | Plan Factory / Seeder 制約を理由に未記述 |

これらは「決めていない」ため、次の担当者が同じ調査をやり直す。
**決定と、その決定を再検討すべき条件**を恒久ドキュメント (`docs/` / docblock) に固定する。

---

## 事実確認 (すべてソースで裏取り済み。推測を設計に使わない)

### F1. Inertia の履歴鍵はクライアント側 public API で捨てられる

`@inertiajs/core` **3.3.1** (`types/router.d.ts:43`) に **`router.clearHistory(): void`** がある。
実体は `history.clear()` = `sessionStorage` の `historyKey` / `historyIv` の削除
(dist/index.js `History.clear()` L1491-1493, `historySessionStorageKeys` L167-170)。
サーバ側 `Inertia::clearHistory()` と同じ効果をクライアント単独で起こせる。

### F2. `clearHistory` の消費点と `navigate` イベント

`page.set()` は swap の**前**に `if (page.clearHistory) history.clear()` を実行し
(dist L1057-1059)、swap 後に `fireNavigateEvent(page)` を発火する (L1109)。
`navigate` の detail は `{ page: Page }` (types.d.ts L267-273) で、`Page.clearHistory?: boolean` は
公開型 (types.d.ts L142)。既存コード `resources/js/lib/document-title.ts` が
`router.on('navigate')` を使っており、購読の前例がある。

### F3. popstate 復元は非同期 swap である

`EventHandler.handlePopstateEvent` は `history.decrypt(state.page).then(...)` の**後**に
`page.setQuietly()` で swap する (dist L1557-1590)。
したがって、別途登録した `popstate` リスナが**同期的に**秘匿属性を立てれば
復元描画の前に間に合う。**T089-b を popstate プローブで塞ぐことは技術的には成立する**
(採否は後述。技術不能を理由に却下しない)。

### F4. `/session/status` プローブは再利用可能な形になっている

`probeSessionStatus(fetchImpl, url)` は `registerBfcacheGuard` から独立した export
(`resources/js/lib/bfcache-guard.ts:119-140`)。endpoint は auth グループ外で
guest でも 200 + `{authenticated:false}` を返す (`SessionStatusController`)。
2FA 強制ゲートも `RequireTwoFactorForEnforcedOrganizations::ALLOWED_ROUTE_NAMES` で免除済み。
= **プローブ機構の再利用そのものに障害は無い**。

### F5. セッション終了は例外 1 種類に収束する

- 期限切れ: session cookie 失効 → `auth` middleware → `AuthenticationException`
- 他デバイスからの強制ログアウト: `Auth::logoutOtherDevices()`
  (`app/Actions/Fortify/UpdateUserPassword.php:49`) の実失効は web グループの
  `AuthenticateSession` (`bootstrap/app.php:120` `$middleware->authenticateSessions()`) が担い、
  こちらも `AuthenticationException` を投げる

`Illuminate\Foundation\Exceptions\Handler::render()` は
**`renderViaCallbacks()` を `AuthenticationException` の既定分岐より先に呼び**、
callback が `null` を返せば既定処理へ素通しする (Handler.php L718-731 / L813-826)。
= `bootstrap/app.php` の `withExceptions` に副作用だけを足す公式の穴がある。

### F6. ログイン画面は Inertia 応答である

`FortifyServiceProvider:190` `Fortify::loginView(fn () => Inertia::render('Auth/Login', ...))`。
= 認証失敗の着地で `clearHistory` フラグが確実に消費される。

### F7. 押収されたタブの「現在表示中の PII」は back を待たない

期限切れ / 強制ログアウトのタブは、**画面にすでに認証済み DOM を表示したまま**である。
サーバ問い合わせが起きるまでアプリはそれを知らない。
したがって **popstate プローブは「履歴に残る過去の PII」しか塞がず、
より大きい「今表示されている PII」は塞がない**。塞ぐには push (Reverb/Echo) か polling が要るが、
`config/broadcasting.php` は存在せず、`package.json` / `composer.json` に
laravel-echo / reverb / pusher の依存も無い (= 基盤ごと新設になる)。

### T090 側の事実

| # | 事実 | 出典 |
|---|---|---|
| F8 | `max_members` を `QuotaService::check` する呼び出し元は**存在しない** (実効的に未強制。`PersonalPlanService::MAX_MEMBERS` は personal 専用の別ハードキャップ) | `grep QuotaKey::MaxMembers` の結果が enum / DTO / PricingService のみ |
| F9 | 実際に止まるのは **プロジェクト作成** (`ProjectService:34`) と **テイクのアップロード** (`TakeUploadService:61`) の 2 次元だけ | 同上 |
| F10 | `/billing` は quota の**上限のみ**表示し使用量を持たない (`QuotaLimitsDto` docblock「使用量 (current) は…持たない」) | `app/DataTransferObjects/Billing/QuotaLimitsDto.php` |
| F11 | Dashboard は容量のみ `used / limit` を表示するが `storageUsagePercent` を **0-100 に clamp** するため 100% と超過が区別できない | `DashboardService:219-236` / `pages/Dashboard.svelte:188-197` |
| F12 | 使用量の集計経路は既にある: プロジェクト数 = `$organization->projects()->count()`、容量 = `StorageUsageService::occupiedBytes()` | `ProjectService:34` / `DashboardService:222` |
| F13 | `PortalConfigurationSpec` の docblock と `docs/architecture.md:140,337` は T090 実装後に更新済みで、`PortalConfigurationTest` と `billing:ensure-portal-configuration --verify` が `subscription_update=false` を機械的に固定している | 各ファイル |
| F14 | `enterprise` の 422 は `SubscriptionService::assertStripeBillablePlan` → `PlanCode::requiresStripeCheckout()` の写像に依存するが、**この enum メソッドの直接テストが 1 本も無い** | `grep requiresStripeCheckout` |
| F15 | `always_invoice` 切替を受ける器がアプリに無い: `SubscriptionState` に `pending_update` case が無く、`incomplete` は `Inactive` に畳まれて課金ゲート遮断に直結する。webhook も `customer.subscription.pending_update_applied/expired` を扱わない | `app/Enums/Billing/SubscriptionState.php` / `StripeWebhookProcessor` |
| F16 | 乖離台帳 A-6 が「`changePlan` を非移植」のまま (T090 で移植済み = 記述が陳腐化) | `devnotes/20260717-0035-aigenba-billing-parity/aigenba-divergence-ledger.md:82` |

---

## 改善アイデア (= 6 つの決定)

### 【T089-a】別タブの Inertia 履歴 → **許容する (塞がない)**

**決定**: 塞がない。`docs/supported-browsers.md` の「未対応事項」を
「未整理の残存リスク」から **「判断済みの受容 + 再検討条件」** へ格上げして固定する。

根拠 (「自前機構だから」ではなく、**塞いでも目的を達しないから**):

1. **非破壊な半分は効果が無い**。F1 の `router.clearHistory()` を別タブで呼んでも、
   そのタブが**今表示している** PII は画面に残る (F7 と同じ構図)。
   別タブの脅威の主部は「戻るで出る過去の PII」ではなく「今出ている PII」である。
2. **効果を出すには別タブの document を落とす必要があり、それは回収可能な作業を壊す**。
   テイクのアップロードは presigned URL で S3 へ直接送るため、セッションが切れていても
   **アップロード自体は継続でき、再ログイン後に finalize できる** (同一 cookie jar)。
   別タブを `location.replace('/login')` で落とすと、この回収可能な撮影成果を破棄する。
   撮影を落とさないことは使命に直結する。
3. **T089-b の決定 (下記) が別タブを自然治癒させる**。サーバがセッション終了を検知した
   すべての契機で `clearHistory` を出すようになるため、別タブは**次にサーバと話した瞬間に**
   鍵を失う。残る露出は「二度と触られない放置タブ」に限られ、それはブラウザを閉じれば消える。
4. **恒久回帰の器が無い**。2 browsing context をまたぐ Browser テストの前例が
   リポジトリに無く、検証できない防御を security 機構として入れることになる (禁止事項 #1)。

**再検討条件** (これが起きたら再度検討する):

- セッション失効の push 経路 (Reverb / Echo 等) を別目的で導入したとき
  (= 全 document への通知が既存機構の範囲内になる)
- 「全デバイスからログアウト」を UI 機能として提供するとき (別タブ即時失効が機能要件になる)
- bug-hunt / 実機受入確認で複数タブ運用が実際に観測されたとき

### 【T089-b】期限切れ / 強制ログアウト後の履歴復元 → **塞ぐ (ただし popstate プローブは採らない)**

#### 提示された案 (`/session/status` プローブを popstate に接続) の検証結果 = **却下**

技術的には成立する (F3 / F4)。しかし採らない:

1. **目的を達しない**。塞げるのは履歴の過去 PII だけで、そのタブが表示中の PII は残る (F7)。
2. **通常の戻る/進むに毎回ネットワーク往復と秘匿オーバーレイのちらつきを入れる**。
   `bfcache-guard` はプローブ失敗を `failed` = **秘匿維持 + 再試行ボタン**に倒す設計であり
   (`bfcache-guard.ts:229-243`)、現場の不安定な回線では
   **通常の戻る操作が再試行オーバーレイで塞がれる = 新しい詰み**を作る。
   撮影 PWA (`/app/*`) の戻る操作は主要導線であり、ここを重くするのは使命に反する。
3. `bfcache-guard.ts` の docblock と AGENTS.md #3 が「ここに popstate フックを足さない」を
   契約として書いた直後に、その契約を自分で破ることになる。

#### 採る案: **サーバがセッション終了を検知した契機で `Inertia::clearHistory()` を出す**

`bootstrap/app.php` の `withExceptions` に `AuthenticationException` の render callback を足し、
**副作用として `Inertia::clearHistory()` を呼んで `null` を返す** (既定の
`unauthenticated()` 応答はそのまま使う。F5)。着地の `/login` は Inertia なので
フラグは確実に消費される (F6)。

これで塞がるもの:

- セッション期限切れ後、ユーザー (または第三者) が何か操作して `/login` に落ちた**以降**の戻る
- パスワード変更による他デバイス強制ログアウト後、そのデバイスが次にサーバと話した以降の戻る
- **JSON 204 ログアウト経路の残存リスク** (`LogoutResponse` docblock / `docs/supported-browsers.md`
  が明記していた「204 を受けて画面遷移しないままの戻る」) も、次の認証済み Inertia visit で解消する
- 別タブ (T089-a) も、そのタブが次にサーバと話した時点で解消する

塞がらないもの (文書に残す): **一度もサーバと話さないまま戻る**場合。
そのタブは表示中の PII も残っているため、いずれにせよ本設計の射程外 (F7)。

**採らなかった代案**: 「guest 向け Inertia 応答すべてに `clearHistory` を載せる」。
匿名ユーザーの公開ページ回遊で戻るたびに復号失敗 → サーバ再取得になり、
**認証と無関係のトラフィックを恒久的に劣化させる**。契機を「認証失敗」に限定する方が
狙いに一致し、副作用も小さい。

### 【T090-a】proration → **`create_prorations` を既定として確定**

**決定**: 現状維持。**証拠なく金銭の挙動を反転させない**。
そのうえで「即時徴収 (`always_invoice`) に切り替えるなら何が必要か」を
`docs/architecture.md` のプラン変更節に**見積りとして 1 箇所だけ**書き、
`CashierStripeGateway::buildSwapPayload()` の docblock からそこを指す (二重管理を作らない)。

見積り (F15 に基づく。「大変そう」ではなく欠けている器を名指しする):

1. `buildSwapPayload()` の `proration_behavior` 変更 + payload invariant テスト更新
2. **状態機械の拡張**: `SubscriptionState` に `pending_update` 相当の表現が無い。
   `incomplete` は現在 `Inactive` に畳まれ、`BillingAccess` → `require-active-subscription` で
   **アプリ全体が遮断される**。与信失敗した「アップグレードしようとしただけの利用者」を
   ロックアウトしない state 設計が先に要る
3. **webhook の受け口**: `customer.subscription.pending_update_applied` /
   `..._expired` / 与信失敗時の `invoice.payment_failed` の**プラン変更文脈での**扱いが無い
4. **UI**: 3DS/SCA の確認導線がアプリに無い (現状の決済 UI は Stripe hosted の Checkout / Portal のみ)。
   `payment_intent` の要アクション状態を受ける画面が要る
5. **ロールバック意味論**: `pending_update` の期限切れで Stripe 側が巻き戻す挙動を
   `organizations.plan_code` の projection と整合させる規約が要る

**再検討条件**: 「日割り差額の回収遅延がキャッシュフロー上問題になった」と
事業側が数値で示したとき。UI/webhook/state の 4 点を同一 TODO で扱う前提で再設計する。

### 【T090-b】ダウングレードの上限超過 → **ブロックしない方針は維持。認知導線だけ最小で足す**

現状の認知導線を実コードで確認した結果 (F10 / F11):

- `/billing` の quota カードは**上限のみ**。プロジェクト数の超過は**どこにも出ない**
- Dashboard の容量タイルは clamp のため 100% と超過を区別できない
- = 「気づいたら新規作成できなくなっていた」体験は**実際に起こりうる**

**決定**:

1. **ブロックしない方針は維持**する (解約経路と非対称なルールを増やさない。T090 の判断を踏襲)
2. **`/billing` に「上限超過」の明示を足す**。プラン変更の着地点であり、契約状態を所有する画面。
   実際に止まる 2 次元 (F9) — プロジェクト数・保存容量 — について
   **使用量 / 上限**を併記し、超過している次元があれば**結果まで書いた Alert**を出す
   (「既存データは削除されませんが、上限内に収まるまで新規作成・アップロードができません」)。
   使用量の集計経路は既存 (F12) で、新しい集計機構は作らない
3. **確認ダイアログの文言から「メンバー数」を外す**。`max_members` は実効的に未強制 (F8) であり、
   現在の文言は**起きないことを起きると言っている**。未強制であることを
   `config/quota.php` / `QuotaKey::MaxMembers` の docblock に事実として書き、
   「表示があるのに強制が無い」を次の読者が誤解しないようにする

**やらないこと**: Dashboard への同種 UI の追加 (二重管理)。メンバー数ゲートの新設 (製品判断であり
今必要ではない。open question に残す)。超過時の一括削除・自動アーカイブ (使命に反する)。

### 【T090-c】Portal の `subscription_update` 無効維持 → **確定 (コード変更なし)**

F13 のとおり、docblock / `docs/architecture.md` / テスト / verify コマンドは
**すでに「実装済みの契約」として整合している**。したがって新たな更新は不要。

ただし**「なぜ再開放しないか」の理由が恒久ドキュメントに無い** (概念設計 `plan-change-path` の
中にしかない)。理由が失われると「boolean を反転すれば済む」と読まれて再燃する。
`PortalConfigurationSpec` の docblock に**再開放に必要なもの**を 3 点だけ追記する:

1. Portal の `subscription_update` 有効化は `products: [{product, prices: [...]}]` の列挙が必須で、
   AI-CUE は **Stripe product id を保持していない** (`plan_prices` の列は
   `stripe_price_id` / `lookup_key` / `amount` / `currency` / `is_current`)
2. 列挙の鮮度を保つ機構が無い (価格改定 `PlanPriceService::replaceCurrent()` /
   `plans.is_active=false` は Portal 列挙に効かない = **旧価格・販売停止プランへ移行できてしまう**)
3. 変更可否の理由説明 (`past_due` / schedule 管理下 / downgrade の上限低下) を
   Stripe hosted 画面には載せられない (禁止事項 #8 と噛み合わない)

### 【T090-d】`enterprise` の 422 テスト → **enum の写像テストで埋める (Plan Factory は作らない)**

穴の正体は「`enterprise` の Plan 行が無いこと」ではなく、
**`PlanCode::requiresStripeCheckout()` の写像に 1 本もテストが無いこと** (F14)。
`assertStripeBillablePlan` の「false → 422 ValidationException」という**変換**は
`personal` のテストで既に固定済みなので、写像側を全 case 網羅で固定すれば合成として穴が埋まる。

**決定**: `tests/Unit/Enums/PlanCodeTest.php` (Pest, DB 不要) で
`PlanCode::cases()` を**網羅**して `requiresStripeCheckout()` を固定する
(`enterprise` / `personal` = false、`starter` / `standard` / `business` = true)。
case 追加時に必ず落ちる形 (cases() 由来の網羅) にする。

**Plan Factory を作らない理由**: `Plan` / `PlanPrice` は**参照データ**であり、
真実源は `PlanSeeder` + `config/quota.php` + `StripePriceLookupKeys` の三点セットである。
Factory を足すと**プラン定義の第 2 の真実源**ができ、seeder と食い違う組み合わせ
(quota 定義の無い plan_code、価格の無い有償プラン) をテストが作れてしまう。
`docs/factories.md` の「新規モデルは Factory 必須」は**新規モデル追加時**の規約であり、
既存の参照データモデルを Factory 化する要求ではない (同書は Role / Permission / Team を
「seed 固定値または Service 経由で作る」と明示している)。
`PlanSeeder` に `enterprise` を足すのは製品面 (公開プラン一覧) を変えるため採らない。

**この判断自体を `docs/factories.md` に 1 行で固定する** — 「Plan / PlanPrice は参照データのため
Factory を持たない (真実源は PlanSeeder)」。次の読者が同じ問いを立てないようにする。

---

## 期待効果

- **使命への貢献**:
  - T089-b により「共有端末でセッションが切れた後に第三者が戻るで PII を見る」経路が、
    アプリがセッション終了を認識した以降について塞がる。現場導入時に必ず問われる条件を守る
  - T090-b により「上限を超えたまま気づかず、撮影・アップロードだけが静かに失敗する」を防ぐ。
    マニュアル動画を作り続けられる状態をユーザー自身が把握・回復できる
- **決定の固定**: 6 論点すべてに「決定 + 根拠 + 再検討条件」が恒久ドキュメントに載る。
  再燃コスト (毎回の再調査) が消える
- **文書と実装の一致**: 乖離台帳 A-6 の陳腐化 (F16) を解消する

---

## 実装方針（概要）

| # | 施策 | 主な変更 |
|---|---|---|
| 1 | T089-b: 認証失敗契機の履歴鍵破棄 | `bootstrap/app.php` (`withExceptions` に `AuthenticationException` の副作用 callback) |
| 2 | T089-a / T089-b の決定固定 | `docs/supported-browsers.md` 「未対応事項」の書き換え (受容 + 再検討条件 + 新しく塞がる範囲) / `LogoutResponse` docblock の残存リスク記述の更新 / `resources/js/lib/bfcache-guard.ts` docblock に「popstate プローブを採らない理由」 / `AGENTS.md` #3 の追補 |
| 3 | T090-a: proration 決定と切替見積り | `docs/architecture.md` (プラン変更節) + `CashierStripeGateway::buildSwapPayload()` docblock からの参照 |
| 4 | T090-b: 上限超過の可視化 | `QuotaLimitsDto` の拡張 (使用量 + 超過次元) / `BillingController::index` / `resources/js/types/billing.ts` / `pages/Billing/Index.svelte` / `pages/Billing/Plans.svelte` の確認ダイアログ文言 / `config/quota.php` + `QuotaKey` docblock に `max_members` 未強制の明記 |
| 5 | T090-c: Portal 方針の確定 | `PortalConfigurationSpec` docblock に再開放要件 3 点を追記 (挙動変更なし) |
| 6 | T090-d: enum 写像テスト | `tests/Unit/Enums/PlanCodeTest.php` 新規 + `docs/factories.md` に Plan/PlanPrice 非 Factory の明記 |
| 7 | 台帳の陳腐化解消 | `devnotes/20260717-0035-aigenba-billing-parity/aigenba-divergence-ledger.md` A-6 の更新 |

---

## テスト方針（概要 — 詳細は詳細設計）

| 層 | 何を固定するか |
|---|---|
| Feature (Pest) | 認証失敗 (guest の認証済み route アクセス / `AuthenticateSession` による強制ログアウト) の着地 `/login` の Inertia payload に `clearHistory: true` が載る。**負のコントロール**: 通常の guest の `/login` 直接アクセス・認証済み応答には載らない |
| Feature (Pest) | `/billing` の Inertia props に使用量と超過次元が載る / 超過していなければ空 |
| Unit (Pest) | `PlanCode::requiresStripeCheckout()` の全 case 写像 |
| Browser (Chromium + WebKit) | JSON 204 ログアウト → 認証済み画面へ Inertia visit → `/login` 着地 → 戻る で PII が描画されない (既存 `AuthenticatedPageBfcacheTest::bfcacheLogoutInBrowser()` の 204 経路を再利用して T089-b の形を決定的に再現する) |
| Vitest | `/billing` 超過 Alert の表示分岐 |

---

## 制約・前提

- **Inertia 3.1.0 (laravel) / `@inertiajs/core` 3.3.1**。F1〜F3 はこのバージョンの実測。
- `Handler::renderViaCallbacks` は callback が `null` を返すと既定処理へ素通す (F5)。
  この性質に依存するため、**Laravel の major 更新時に再確認する**ことを設計上の前提として書く。
- `$request->expectsJson()` が真のリクエスト (API / MCP) では `clearHistory` を積まない
  (Inertia 応答が来ないままフラグが宙に浮くのを避ける)。
- `organizations.plan_code` の writer は webhook 1 本のまま (T090 の非交渉事項)。本設計は触らない。
- 金銭の挙動 (proration) は**変更しない**。
- PHPStan level 10 / Pest / `RefreshDatabase` グローバル適用。

---

## スコープ外

- **BroadcastChannel による全タブ伝播** (T089-a の決定どおり。再検討条件を文書に固定する)
- **popstate ごとのセッションプローブ** (T089-b で明示的に却下。理由を docblock に残す)
- **セッション失効の push 基盤 (Reverb / Echo)** — 依存自体が無い。本設計では導入しない
- **`always_invoice` への切替** (T090-a の決定どおり。見積りのみ)
- **メンバー数 quota の強制** (F8。製品判断が要るため open question に残す)
- **`business` / `enterprise` の quota 定義追加** (`config/quota.php` に両者の entry が無く、
  万一 plan_code が付くと無制限扱いになる。今回の 6 論点の外だが open question に残す)
- **Dashboard 側の超過表示** (`/billing` に一本化する)
- **iOS 実機受入確認** (既存 T085 の責務)
