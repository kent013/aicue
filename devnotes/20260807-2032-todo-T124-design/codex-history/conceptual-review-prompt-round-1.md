## アプリの使命（North Star）— AGENTS.md より転記

**AI-CUE** は、現場に既にある**作業手順書(SOP)を起点に**、AI が撮るべきカットを設計した**動画シナリオ**を生成し、そのシナリオを**スマホ(PWA)でナビゲーション撮影**することで、専門知識ゼロの現場作業者でも**標準化されたマニュアル動画**を作れるようにする。

- 「思考ゼロ・編集ゼロ」— 台本作成・撮影判断・編集の 3 ハードルを AI とナビ撮影で肩代わりする。
- 競合(OJT を撮って形式化する tebiki)と異なり、**標準作業を起点に AI が教材設計し撮影を指示する**（撮影者・教える人のスキルに品質を依存させない）。
- 熟練者の暗黙知を動画マニュアルという形式知へ変換する装置（SECI）。

> 詳細な設計は本リポジトリの `doc/`（01〜10）に置く。ドメインをテンプレート構造へどうマップしたかは `doc/08`〜`doc/10`（特に `doc/10 §10.8` に設計レビュー反映済み）。
> **v1 スコープ**: 字幕のみ(TTS 後回し) / 撮影は PWA(同一オリジン・セッション認証) / 動画合成は自前 ffmpeg / 単一 Default Project。

## 禁止事項 — AGENTS.md より転記

1. テストなしの実装完了報告(不変条件は対応する Architecture/Feature テストへの登録まで含めて「実装済み」)
2. PHPStan エラーの widen(型を緩めて黙らせる)・baseline 化
3. dev DB への破壊操作(`migrate:fresh` 等)をエージェント判断で実行すること
4. `response()->json()` の直書き(DTO / JsonResource / Inertia を使う。仕様固定 endpoint のみ例外)
5. LLM 呼び出しの Prism 直呼び(`app/Prompts/` の factory 経由のみ。PromptGuardrailTest が検出)
6. prompt 文字列のコード直書き(`resources/prompts/*.yaml` に置く)
7. 操作系 POST の応答での `redirect()->intended()`(ログイン直後フロー専用。招待送信等は `back()->with(...)` で完結させる)
8. 必須条件未充足を理由にボタンを disabled にする UI(押下時にエラー表示する。DESIGN.md)
9. Artifact の使用(Artifact ツールでの成果物公開を行わない。成果物はリポジトリ内のファイルとして出力する)

## 思考原則 — 全議論に適用

まず仮説を立てろ。何を検証したいのか、なぜそう考えるのか、どうなれば成功と判断するのかを明確にしてから手を動かせ。仮説なき改善はただの試行錯誤であり、結果から学ぶことができない。

データに真摯に向き合え。成果だけでなく、多様性の変化、構造の揺らぎ、想定外のパターン — 全てが判断材料になる。数値を見て即座に閾値を弄るな。何が起きているのかを理解し、なぜそうなったのかを考え、どの方向に進むべきかを判断してから手を動かせ。

先人の知恵を探せ。自分たちだけで登る必要はない。乗るべき巨人の肩があるなら乗れ。

機能の名前に立ち返れ。名前はその機能が果たすべき役割を示している。現在の設計がその役割を果たしているか、常に問え。

仕組みが機能していない段階で値を弄るな。閾値チューニングやフィールド追加は、設計の方向性が正しいと確認できてから行え。方向性が間違っているなら、値をいくら調整しても意味はない。設計そのものを見直せ。成果が出なければ早期に見切り、次の仮説へ進め。

## ツール使用制限

コマンド実行・ファイル書き込みは一切行わず、提供されたテキストの分析に集中すること。ファイル読み込みは許可。

---

あなたはWebアプリケーション（Laravel + Svelte）の改善に関する概念設計レビュアーです。

（アプリの使命・禁止事項は上記に挿入済み）

【レビュー観点】
1. 使命との整合性: この改善はアプリの使命（North Star）に本質的に貢献するか
2. 禁止事項違反: 上記禁止事項に抵触していないか
3. 実現可能性: 技術的に実現可能か（Laravel 12 + Svelte 5 + Inertia.js）
4. 期待効果の妥当性: 主張している効果は合理的に期待できるか
5. リスク: 重大な副作用・後退の可能性はないか
6. スコープの適切さ: 過大または過小になっていないか
7. **型安全性**: DTO/JsonResourceパターンに沿っているか。PHPStan level 10を通せるか

【出力形式】
- 全体判定: APPROVED / CHANGES_REQUESTED
- 各観点ごとに [Critical] [Warning] [Suggestion] で分類して指摘
- Critical/Warning には修正提案を必ず添える
- 日本語で出力

【このアプリ固有の重要な既知制約 — レビュー時に必ず考慮すること】
- inline throttle (`throttle:6,1` 形式) のキーは `sha1(actor id)` だけで route 名も limiter 名も入らない。
  そのため **同一 actor の inline throttle route は全て 1 bucket を共有**する (T121 実測・AGENTS.md 明記)。
  max が最小の route は `recent-auth.password` の 6。描画のたびに発火する GET を inline へ足すと、
  この bucket を食い潰して **再認証そのものを 429 で壊す**。
- 「閾値は既存値を変えない。新しい面には既に本番稼働中の同性質エンドポイントと同値を充てる」が規約。
- 新しい不変条件は `tests/Architecture/` の deny-by-default 目録型 gate に登録するのが規約
  (免除は型付き enum + 30 文字以上の根拠)。

---

## 概念設計

（以下、devnotes/20260807-2032-todo-T124-design/conceptual-design.md 全文）

# 概念設計: 2fa-secret-get-recent-auth (T124)

## 背景・課題

### 発端
T121 (docs/TODO-closed.md) で「2FA 秘密を返す GET 3 本」(`two-factor.qr-code` /
`two-factor.secret-key` / `two-factor.recovery-codes`) に named limiter
`two-factor-secret-read` (10/min) を貼った。しかし `FortifyServiceProvider::configureRateLimiters()`
の docblock 自身が明記しているとおり、これは **連続取得の回数上限であって step-up の代替ではない**。
認証強度の話は未着手のまま T124 として残っている。

### 現行コードの実査 (推測でなく確認した事実)

| route | HTTP | throttle | recent-auth | 出典 |
|---|---|---|---|---|
| `two-factor.login` | GET (guest) | `two-factor` limiter | なし | vendor/laravel/fortify/routes/routes.php L135 |
| `two-factor.login.store` | POST (guest) | `two-factor` limiter | なし | 同 L140 |
| `two-factor.enable` | POST | `10,1` (inline) | **なし** | FortifyServiceProvider::throttledFortifyRoutes() |
| `two-factor.confirm` | POST | `10,1` (inline) | **なし** | 同 |
| `two-factor.disable` | DELETE | `10,1` (inline) | あり | RECENT_AUTH_ROUTE_NAMES |
| `two-factor.qr-code` | GET | `two-factor-secret-read` | **なし** | — |
| `two-factor.secret-key` | GET | `two-factor-secret-read` | **なし** | — |
| `two-factor.recovery-codes` | GET | `two-factor-secret-read` | **あり** | RECENT_AUTH_ROUTE_NAMES |
| `two-factor.regenerate-recovery-codes` | POST | `10,1` (inline) | あり | RECENT_AUTH_ROUTE_NAMES |

**TODO 行の「秘密 GET 3 本」は不正確である**。`two-factor.recovery-codes` は既に
`FortifyServiceProvider::RECENT_AUTH_ROUTE_NAMES` に登録され recent-auth 済みで、
`RecentAuthRouteTest` と `TwoFactorRecoveryCodesStepUpTest` が固定している。
**未保護の秘密 GET は 2 本** (`two-factor.qr-code` / `two-factor.secret-key`) である。

### 課題 1: 確立済み第二要素の秘密が session だけで読める
`TwoFactorSecretKeyController::show()` は `two_factor_secret` を **復号して平文で返す**。
`TwoFactorQrCodeController::show()` は `svg` と **`url` (otpauth:// = 秘密を内包)** を返す。
どちらも `two_factor_confirmed_at` の有無を見ない。つまり **確立済み (confirmed) の
第二要素の seed** が、通常セッション認証だけで読み出せる。

- 帰結: セッション奪取 (共有端末・放置ブラウザ・cookie 窃取) の攻撃者が TOTP を
  **無期限にクローン**できる。パスワード変更では `two_factor_secret` は回らないため、
  **被害者が気づいて password を変えても攻撃者の第二要素は生き残る**。
- これは「第二要素の bypass 経路」として既に recent-auth 済みのリカバリコード表示と
  **同じ機微度**であり、片方だけ開いているのは配線漏れである。

### 課題 2 (実査中に発見): `two-factor.enable` の `force=true` がロックアウト兵器になる
`TwoFactorAuthenticationController::store()` は `$enable($request->user(),
$request->boolean('force', false))` を呼ぶ。**`force` はリクエストボディ由来**である。
`EnableTwoFactorAuthentication::__invoke()` は `force === true` なら
`two_factor_secret` と `two_factor_recovery_codes` を **無条件で再生成**するが、
`two_factor_confirmed_at` は **触らない** (vendor v1.37.2 実査済み)。

- 帰結: セッションを奪った攻撃者が `POST /user/two-factor-authentication {force: true}` を
  1 回投げると、被害者の TOTP seed とリカバリコード 8 本が同時に差し替わり、
  `two_factor_confirmed_at` は残るのでログインは TOTP を要求し続ける。
  **誰も知らない秘密で永久ロックアウト**が成立する (復旧は組織 Owner の
  `organizations.members.two-factor.reset` 頼み)。
- 秘密 GET に step-up を掛けても、この書き込み経路が開いていれば
  「第二要素を session 一枚で壊せる」性質は残る。**同じ route 族の同じ穴**である。

### 課題 3: 不変条件を守る機械がない
`RecentAuthRouteTest` は **allowlist 型** (「この名前の route に recent-auth があること」)
であり、母集団を持たない。Fortify が 2FA route を 1 本足しても、アプリが 2FA 面を
1 本足しても、**沈黙して素通りする**。T121 が throttle 側で deny-by-default 目録
(`ThrottleCoverageInventoryTest`) を作ったのと同じ形が、step-up 側には無い。

## 改善アイデア

**「2FA の秘密と第二要素の状態に触る route は、step-up 済みでなければ通さない」を
deny-by-default の目録で機械強制する。**

1. `two-factor.qr-code` / `two-factor.secret-key` に recent-auth を後付けする (課題 1)。
2. `two-factor.enable` に recent-auth を後付けする (課題 2)。
3. `two-factor.` 名前空間の全 route を母集団とする **deny-by-default 目録 gate** を新設し、
   各 route を「recent-auth 必須」か「型付き enum + 30 文字以上の根拠付き exemption」の
   どちらかに分類させる (課題 3)。
4. 上記でクライアント側に新しく生じる **step-up の詰み** を塞ぐ:
   - enrollment 開始 (`enableTwoFactor`) を `guardWithRecentAuth` の precheck 経由にする
     (= step-up は enrollment の **最初の** 操作になる)。
   - enrollment 素材取得の再試行ボタンも precheck 経由にする
     (素の `fetch` は 409 を「取得失敗」に畳むため、再試行が無限に失敗する詰みを防ぐ)。
   - `RequireTwoFactorForEnforcedOrganizations::ALLOWED_ROUTE_NAMES` に
     `passkey.confirm-options` / `passkey.confirm` を追加する
     (**2FA 必須組織の passkey-only ユーザーが step-up 手段を全部失う詰みを防ぐ**。後述)。

分類の結論 (母集団 9 本):

| route | 措置 | 根拠 |
|---|---|---|
| `two-factor.qr-code` | recent-auth 追加 | 秘密 (otpauth URL / QR) を返す |
| `two-factor.secret-key` | recent-auth 追加 | 秘密 (平文 seed) を返す |
| `two-factor.enable` | recent-auth 追加 | `force=true` が seed とリカバリコードを回す |
| `two-factor.recovery-codes` | 既存のまま | 既に配線済み |
| `two-factor.regenerate-recovery-codes` | 既存のまま | 既に配線済み |
| `two-factor.disable` | 既存のまま | 既に配線済み |
| `two-factor.confirm` | **exemption** | 成立にその場で生成不能な TOTP コード提示が要り、秘密の開示も第二要素の除去も伴わない |
| `two-factor.login` | **exemption** | guest 面。session に認証主体が無く step-up の概念が成立しない |
| `two-factor.login.store` | **exemption** | 同上 (これ自体が第二要素の検証 = satisfier 側) |

## 期待効果

- **使命への貢献**: AI-CUE は現場の SOP と撮影素材という業務資産を預かる。アカウント乗っ取りは
  組織の資産全体の喪失に直結する。「セッション 1 枚で第二要素をクローン / 破壊できる」状態を
  塞ぐことは、使命 (現場が安心して手順書と動画を預けられること) の前提条件である。
- **具体的な改善**: 秘密の読み出しと第二要素の再生成に、直近 15 分以内の再認証
  (password / 再SSO / passkey) を要求する。奪取済み session だけでは成立しなくなる。
- **回帰の恒久化**: deny-by-default 目録により、Fortify の update で route が増えても、
  アプリが 2FA 面を足しても、**分類しない限り CI が赤**になる。

## 実装方針（概要）

### backend
- `FortifyServiceProvider::RECENT_AUTH_ROUTE_NAMES` に 3 本追加
  (`two-factor.qr-code` / `two-factor.secret-key` / `two-factor.enable`)。
  後付け機構 (`attachRecentAuthToSensitiveRoutes()` の booted callback) は既存のまま流用する
  (新機構を作らない)。
- `app/Enums/Security/TwoFactorStepUpExemption.php` を新設 (2 case)。
- `RequireTwoFactorForEnforcedOrganizations::ALLOWED_ROUTE_NAMES` に passkey satisfier 2 本を追加。

### frontend
- `resources/js/pages/Settings/Security.svelte`
  - `enableTwoFactor()` を `guardWithRecentAuth(...)` で包む。
  - enrollment 素材の再試行ボタンを `guardWithRecentAuth(() => void loadEnrollmentAssets())` にする。
  - `RecentAuthModal` は既に配線済み (`recent-auth-modal-call-site-inventory` 登録済み) のため
    新しい呼び出し側は増えない。

### tests
- 新設 Architecture gate `TwoFactorStepUpInventoryTest`(deny-by-default 目録 + 空振り防止)。
- 新設 Feature: 秘密 GET の遮断/通過、`force=true` rotate の遮断/通過 (負のコントロール込み)。
- 更新: `RecentAuthRouteTest` の allowlist、`TwoFactorEnforcementTest` の dataset (表駆動のため自動)。
- 更新: `tests/js/pages/SettingsSecurity.test.ts` に enrollment precheck のケースを追加。

## 制約・前提

### 前提 1: inline throttle の 1 bucket 共有 (AGENTS.md §流量制限 / T121 実測) との衝突検討
inline throttle のキーは `sha1(actor id)` だけで route 名も limiter 名も入らないため、
**同一 actor の inline throttle route は全て 1 bucket を共有**する。max が最小なのは
`recent-auth.password` の 6 であり、ここを巻き添えで 429 にすると **再認証そのものが壊れる**。

本設計がこの性質と衝突しないと言える根拠:

1. **追加する保護対象は 1 本も inline を増やさない**。`qr-code` / `secret-key` は named limiter
   (`two-factor-secret-read`) 側で、`enable` は既存の inline `10,1` のままである。
   throttle の付与も閾値も **1 文字も変えない** (AGENTS.md「閾値は既存値を変えない」)。
2. **新規に増える inline 消費は「1 enrollment あたり最大 1 回の `recent-auth.password` POST」**
   だけであり、**描画のたびに発火する GET は 1 本も増やさない**
   (AGENTS.md が名指しで禁じている失敗形はここでは起きない)。
   `qr-code` / `secret-key` は「有効化」押下後にのみ 1 回ずつ飛ぶ click-driven な fetch であり、
   `recent-auth.status` (precheck) は throttle 対象外である。
3. **消費順序を設計で固定する**。precheck を `enableTwoFactor()` の **前段**に置くことで、
   step-up は enrollment で **最初に**消費される inline 操作になる
   (bucket 残量が最大の時点で通る)。逆に「enable → 素材取得で 409 → そこから step-up」に
   すると、step-up が `enable` + `confirm` リトライの **後ろ**に回り、
   TOTP を数回打ち間違えた利用者が `recent-auth.password` (max 6) で 429 になる。
   この順序は frontend テストで固定する (単なるコメントにしない)。
4. **残余リスク (誇張しない)**: 同一 60 秒内に inline 操作を 6 回以上消費した直後に
   step-up が必要になると `recent-auth.password` は 429 になる。これは本設計以前から
   存在する性質で、本設計は enrollment 1 回あたり 1 hit 増やすだけである。
   decay は 60 秒で自己回復する。**`recent-auth.password` を named limiter へ移す**
   のが構造的な解だが、それは全 step-up 面に波及する横断変更であり本 TODO の範囲外
   (今必要なものだけ作る)。

### 前提 2: step-up の satisfier が 2FA 必須ゲート下でも到達できること
`RequireTwoFactorForEnforcedOrganizations` は未準拠ユーザーを ALLOWED_ROUTE_NAMES 以外から
締め出す。現在の allowlist には `recent-auth.confirm` / `recent-auth.status` /
`recent-auth.password` / `social.redirect` / `social.callback` は入っているが、
**passkey の satisfier (`passkey.confirm-options` / `passkey.confirm`) は入っていない**。

今日はこの欠落が刺さらない。未準拠ユーザーが到達できる 2FA route のうち recent-auth を
持つのは `two-factor.recovery-codes` 等だけで、UI 上その導線は 2FA 有効後にしか出ないためである。
本設計で `enable` / `qr-code` / `secret-key` に step-up を課すと、
**2FA 必須組織の passkey-only (password 未設定・SSO 未連携) ユーザーが、enrollment の
入口で step-up を求められ、その手段を全部塞がれて詰む**。したがって allowlist への
passkey satisfier 追加は本設計の**必須の波及変更**であり、任意の改善ではない。

### 前提 3: 既存機構をそのまま使う
- 後付けは `FortifyServiceProvider::attachRecentAuthToSensitiveRoutes()`
  (booted callback + `refreshNameLookups()`) をそのまま使う。新しい binder を作らない。
- `route:cache` の運用要件 (毎デプロイ再生成) は throttle 後付けと同じで、
  `attachRecentAuthToSensitiveRoutes` は cached 起動でも `CompiledRouteCollection` の
  nameCache 経由で同一 instance に効く (既存 docblock の主張)。本設計で前提を増やさない。
- クライアントの step-up 再開機構 (`withRecentAuth` / `RecentAuthModal` / `pendingAction`)
  は既存のものを使う。新しいモーダルも新しい状態機械も作らない。

### 前提 4: PHPStan / テスト規約
- PHPStan level 10。解析対象は `app` / `config` / `database` / `routes` (`phpstan.neon` 実査)
  であり、新設 enum が対象、テストは対象外。
- Pest + `RefreshDatabase` グローバル適用 + `--parallel`。個別 `DatabaseTransactions` は使わない。
  テストデータは `User::factory()->withTwoFactor()` 等の Factory で作る。

## スコープ外

- **`recent-auth.password` の named limiter 化** (inline bucket 共有の構造的解決)。
  全 step-up 面に波及する横断変更のため別 TODO。本設計では順序固定で回避する。
- **2FA 秘密の読み出しに対する監査イベント発行** (`SecurityEventCoverageTest` 系)。
  step-up を課すこと自体とは独立の観測強化であり、今回は作らない。
- **`two-factor.enable` の `force` パラメータそのものの封殺** (FormRequest / 自前 controller 化)。
  step-up を課せば「奪取 session 単体では成立しない」まで下がる。vendor controller の
  差し替えは後方互換の並走を生むため、必要になったら独立の設計で行う。
- **`two_factor_confirmed_at` の有無で保護を出し分ける条件付き middleware**。
  enrollment 中 (未 confirm) の秘密も、confirm 後の秘密も、同じ秘密である。
  条件分岐を足す価値より `RequireRecentAuthOnEmailChange` 的な条件付き middleware を
  もう 1 種増やす複雑さの方が大きい (今必要なものだけ作る)。
- **Fortify の 2FA challenge 面 (`two-factor.login*`) の強化**。未認証面であり別問題。
- **`two-factor-secret-read` limiter の閾値変更**。既存値を変えない。

