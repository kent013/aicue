# Round 4: Round 3 指摘への対応

## 対応マトリクス

### [Warning] 観点3: native fetch が 409 契約へ入る条件が設計に不足 → **対応**

指摘のとおりです。`RequireRecentAuth` は `expectsJson()` が真のときだけ 409 を返し、偽なら 302 を返すため、
ヘッダが欠けると fetch がリダイレクトを追従して HTML を取得し、409 判定が一度も成立しません。

現行コード (`resources/js/pages/Settings/Security.svelte` の `fetchJson()`) は既に
`headers: { Accept: "application/json" }` を送っており条件は満たしていますが、
**暗黙の前提のまま**でした。設計に「`Accept: application/json` を必ず送る」を明示契約として書き、
Feature テストも `getJson()` のヘルパ任せにせず
`get('/user/two-factor-qr-code', ['Accept' => 'application/json'])` で**実ヘッダ条件による 409** を固定します。

### [Suggestion] 観点3: 両方409 / 片方だけ409 / 非409エラー の3系統をテストで分ける → **採用**

通常エラーを step-up へ誤分類しないための負のコントロールとして 3 系統を JS テストに入れます。

### [Warning] 観点4: 「回帰の恒久化」の記述が保証範囲より広い → **対応**

次のとおり限定しました。

> **回帰の恒久化 (範囲を限定して書く)**: deny-by-default 目録により、
> **Fortify が `two-factor.*` を増やした場合、および route 名に `two-factor` を含む
> アプリ側 route が増えた場合**は、分類しない限り CI が赤になる。
> それ以外の命名 (`mfa.*` 等) には**沈黙する**。

### [Warning] 観点5: 「改善アイデア」冒頭の不変条件宣言も広すぎる → **対応**

宣言自体を名前ベースへ書き換えました。

> **「route 名に `two-factor` を含む route は、recent-auth 必須か理由付き exemption の
> いずれかへ分類する」を deny-by-default の目録で機械強制する。**
>
> (不変条件の宣言を意味ベースではなく名前ベースで書くのは、gate が実際に検査できる範囲と
> 宣言を一致させるためである。意味ベースの保証は命名規約の強制を要し、本 TODO には過大。)

### [Suggestion] 観点7: 409 判定関数は `unknown` を受ける型ガードに → **採用**

既存 `parseRecentAuthStatus` と同じ流儀で、`unknown` を受けて構造を絞り込む型ガードとして書くことを明記しました。

---

Round 3 で挙がった残り 2 点はいずれも反映済みです。最終判定をお願いします。

## 修正後の概念設計 (全文)

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

**「route 名に `two-factor` を含む route は、recent-auth 必須か理由付き exemption の
いずれかへ分類する」を deny-by-default の目録で機械強制する。**

(不変条件の宣言を意味ベース「2FA の秘密と第二要素の状態に触る route」ではなく
**名前ベース**で書くのは、gate が実際に検査できる範囲と宣言を一致させるためである。
意味ベースの保証は命名規約の強制を要し、本 TODO には過大 — 後述「保証範囲」節。)

1. `two-factor.qr-code` / `two-factor.secret-key` に recent-auth を後付けする (課題 1)。
2. `two-factor.enable` に recent-auth を後付けする (課題 2)。
3. **route 名に `two-factor` を含む全 route** を母集団とする **deny-by-default 目録 gate** を
   新設し、各 route を「recent-auth 必須」か「型付き enum + 30 文字以上の根拠付き exemption」の
   どちらかに分類させる (課題 3)。
4. 上記でクライアント側に新しく生じる **step-up の詰み** を塞ぐ:
   - enrollment 開始 (`enableTwoFactor`) を `guardWithRecentAuth` の precheck 経由にする
     (= step-up は enrollment の **最初の** 操作になる)。
   - enrollment 素材 fetch が 409 (`recent_auth_required`) を受けたら、
     「取得失敗」に畳まず既存の step-up モーダル + `pendingAction` 再開機構に載せる
     (素の `fetch` は Inertia の `httpException` ハンドラに乗らないため、
     ここだけは自前で 409 を見る必要がある)。
   - `RequireTwoFactorForEnforcedOrganizations::ALLOWED_ROUTE_NAMES` に
     `passkey.confirm-options` / `passkey.confirm` を追加する
     (**2FA 必須組織の passkey-only ユーザーが step-up 手段を全部失う詰みを防ぐ**。後述)。

### 母集団セレクタの決定 (Codex Round 1 [Warning] 観点 3 を受けて拡大)
当初案は母集団を Fortify の `two-factor.` 名前空間 (9 本) に限っていたが、それでは
「2FA の秘密と第二要素の状態に触る route」という不変条件名に対して機械保証が狭い。
`php artisan route:list --json` で **実測**したところ、route 名に `two-factor` を含む route は
**ちょうど 11 本**で、Fortify の 9 本に加えてアプリ側の 2 本
(`organizations.members.two-factor.reset` / `organizations.two-factor-requirement.update`) がある。
アプリ側 2 本は既に recent-auth 済みのため、**母集団を広げても配線変更は 1 本も増えない**。
コストゼロで設計名と機械保証が一致するので広げる。

分類の結論 (母集団 11 本 = 実測値):

| route | 措置 | 根拠 |
|---|---|---|
| `two-factor.qr-code` | **recent-auth 追加** | 秘密 (otpauth URL / QR) を返す |
| `two-factor.secret-key` | **recent-auth 追加** | 秘密 (平文 seed) を返す |
| `two-factor.enable` | **recent-auth 追加** | `force=true` が seed とリカバリコードを回す |
| `two-factor.recovery-codes` | 既存のまま (配線済み) | 第二要素の bypass 手段の露出 |
| `two-factor.regenerate-recovery-codes` | 既存のまま (配線済み) | 同上の更新 |
| `two-factor.disable` | 既存のまま (配線済み) | 第二要素そのものの除去 |
| `organizations.members.two-factor.reset` | 既存のまま (配線済み) | 他メンバーの第二要素の除去 |
| `organizations.two-factor-requirement.update` | 既存のまま (配線済み) | 組織のセキュリティ方針変更 |
| `two-factor.confirm` | **exemption** | 成立にその場で生成不能な TOTP コード提示が要り、秘密の開示も第二要素の除去も伴わない |
| `two-factor.login` | **exemption** | guest 面。session に認証主体が無く step-up の概念が成立しない |
| `two-factor.login.store` | **exemption** | 同上 (これ自体が第二要素の検証 = satisfier 側) |

### gate が検査すること (空振り防止を含む)
1. 母集団件数が **exact-fit** (11)。増減すれば必ず fail し、分類の再検討を強制する
   (下限だけでは「セレクタが壊れて 0 件」を検出できても「Fortify が 1 本足した」を見逃す)。
2. 母集団の各 route は recent-auth を持つか、exemption inventory に登録済みか。
   **未知は fail** (deny-by-default)。
3. exemption inventory の key が現存 route であること (stale entry の検出)。
4. exemption 登録された route は **recent-auth を持たない**こと (死んだ exemption の検出 = 負のコントロール)。
5. exemption の値が型付き enum + **30 文字以上**の根拠であること。
6. exemption 件数の **exact-fit cap** (3)。1 本でも余裕を持たせない。
7. **秘密開示 3 本 (`qr-code` / `secret-key` / `recovery-codes`) が required 側にある**ことの名指し固定。
   目録の分類が将来 exemption 側へ移されたら fail する (この gate の存在理由そのものを守る)。

### enum の case 数と exemption 件数は別次元 (Codex Round 2 [Critical] への回答)
- **enum の case = 免除してよい「理由の型」**であって route の識別子ではない。
  本設計では **2 case**: `PreAuthChallengeSurface` / `ProofOfSecretPossessionRequired`。
- **exemption 件数 = 分類された route の数**であって、本設計では **3 件**
  (`two-factor.login` / `two-factor.login.store` → 前者、`two-factor.confirm` → 後者)。
- 両者が一致しないのは正常である。見本の `ThrottleCoverageExemption` は
  **8 case で 25 件**を分類しており、`ThrottleCoverageInventoryTest` は
  **全体 cap** と **case 別 cap** を独立に検査して「どのカテゴリが膨らんだか」を見る。
  1 route 1 case にすると enum が route 名の写しになり、
  「同じ理由の免除が増えていないか」という目録の主目的が失われる。
- したがって gate の cap も 2 系統置く: 全体 = 3 (exact-fit) /
  case 別 = `PreAuthChallengeSurface` 2、`ProofOfSecretPossessionRequired` 1 (いずれも exact-fit)。
- `two-factor.login` (GET) と `two-factor.login.store` (POST) は同一 URI の表示/検証ペアで
  免除理由が完全に同一のため、別 case に割る根拠が無い。

### 保証範囲 (誇張しない)
この gate が保証するのは **「route 名に `two-factor` を含む route」に限る**。
`mfa.*` / `security.*` のような**別名で第二要素の状態に触る route** を将来足した場合、
本 gate は**沈黙する**。命名規約そのものを Architecture テストで強制する仕組みは
本 TODO には過大なので作らない。代わりに gate のファイル冒頭コメントと本節に
「別名で第二要素へ触る route を足すときは、この inventory の母集団設計も同時に見直す」と
明記して、次に触る人へ申し送る。

## 期待効果

- **使命への貢献**: AI-CUE は現場の SOP と撮影素材という業務資産を預かる。アカウント乗っ取りは
  組織の資産全体の喪失に直結する。「セッション 1 枚で第二要素をクローン / 破壊できる」状態を
  塞ぐことは、使命 (現場が安心して手順書と動画を預けられること) の前提条件である。
- **具体的な改善**: 秘密の読み出しと第二要素の再生成に、直近 15 分以内の再認証
  (password / 再SSO / passkey) を要求する。奪取済み session だけでは成立しなくなる。
- **回帰の恒久化 (範囲を限定して書く)**: deny-by-default 目録により、
  **Fortify が `two-factor.*` を増やした場合、および route 名に `two-factor` を含む
  アプリ側 route が増えた場合**は、分類しない限り CI が赤になる。
  それ以外の命名 (`mfa.*` 等) には**沈黙する** (後述「保証範囲」節)。

## 実装方針（概要）

### backend
- `FortifyServiceProvider::RECENT_AUTH_ROUTE_NAMES` に 3 本追加
  (`two-factor.qr-code` / `two-factor.secret-key` / `two-factor.enable`)。
  後付け機構 (`attachRecentAuthToSensitiveRoutes()` の booted callback) は既存のまま流用する
  (新機構を作らない)。
- `app/Enums/Security/TwoFactorStepUpExemption.php` を新設
  (**2 case** = 免除理由の型。分類される route は 3 本。上記「enum の case 数と exemption 件数は
  別次元」参照)。
- `RequireTwoFactorForEnforcedOrganizations::ALLOWED_ROUTE_NAMES` に passkey satisfier 2 本を追加。

### frontend
- `resources/js/pages/Settings/Security.svelte`
  - `enableTwoFactor()` を `guardWithRecentAuth(...)` で包む。
  - enrollment 素材の再試行ボタンを `guardWithRecentAuth(() => void loadEnrollmentAssets())` にする。
  - enrollment 素材 fetch が 409 `recent_auth_required` を返したら
    `guardWithRecentAuth(() => void loadEnrollmentAssets())` で既存のモーダル + 再開機構に載せる。
    **各 fetch は「値 + `recentAuthRequired` フラグ」を返すだけ**にし、
    `guardWithRecentAuth` の呼び出しは `loadEnrollmentAssets()` の集約地点で **1 回だけ**行う
    (QR と secret-key は同一 session の同一鮮度判定なので**両方が 409 になるのが通常**であり、
    個別に呼ぶとモーダル 2 重起動と `pendingAction` 上書きが常時発生する)。
  - **409 契約へ入るためのリクエストヘッダを設計として固定する**。`RequireRecentAuth` は
    `expectsJson()` が真のときだけ 409 を返し、偽なら 302 を返す。素の `fetch` は既定では
    XHR とみなされないため、`Accept: application/json` を**必ず**送る
    (現行の `fetchJson()` は既に送っている = 変更不要だが、明示的な契約として設計に書き、
    Feature テストでも `getJson()` ではなく `get(..., ['Accept' => 'application/json'])` で
    実ヘッダ条件による 409 を固定する)。これが崩れると 302 に落ち、
    fetch がリダイレクトを追従して HTML を取得し、409 判定が**一度も成立しない**。
  - 409 の判定は status だけでは行わない。`RequireTwoFactorForEnforcedOrganizations` も
    409 を返す (`code: two_factor_required`) ため、**`code === 'recent_auth_required'` の
    厳格一致**まで見る。判定関数は `lib/recent-auth.ts` に置き、既存の
    `RECENT_AUTH_REQUIRED_CODE` 定数を共有する (判定点を 2 つ作らない)。
    判定関数は `unknown` を受けて構造を絞り込む **型ガード**として書く
    (body の形状を信用しない。既存 `parseRecentAuthStatus` と同じ流儀)。
  - `RecentAuthModal` は既に配線済み (`recent-auth-modal-call-site-inventory` 登録済み) のため
    新しい呼び出し側は増えない。

### tests
- 新設 Architecture gate `TwoFactorStepUpInventoryTest`(deny-by-default 目録 + 空振り防止 7 項目)。
- 新設 Feature: 秘密 GET の遮断/通過 (XHR 409 / 非 XHR 302 / fresh 通過)、
  `force=true` rotate の遮断/通過 (負のコントロール込み)。
- 更新: `RecentAuthRouteTest` の allowlist、`TwoFactorEnforcementTest` の dataset (表駆動のため自動)
  + passkey-only ユーザーが 2FA 必須ゲート下で passkey satisfier に到達できるケース。
- 更新: `tests/js/pages/SettingsSecurity.test.ts` に enrollment precheck / 409 再開のケースを追加。

## 制約・前提

### 前提 1: inline throttle の 1 bucket 共有 (AGENTS.md §流量制限 / T121 実測) との衝突検討
inline throttle のキーは `sha1(actor id)` だけで route 名も limiter 名も入らないため、
**同一 actor の inline throttle route は全て 1 bucket を共有**する。max が最小なのは
`recent-auth.password` の 6 であり、ここを巻き添えで 429 にすると **再認証そのものが壊れる**。

本設計がこの性質と衝突しないと言える根拠:

1. **追加する保護対象は 1 本も inline を増やさない**。`qr-code` / `secret-key` は named limiter
   (`two-factor-secret-read`) 側で、`enable` は既存の inline `10,1` のままである。
   throttle の付与も閾値も **1 文字も変えない** (AGENTS.md「閾値は既存値を変えない」)。
2. **新規に増える inline 消費は「1 enrollment あたり最大 1 回」**であり、しかも
   **password satisfier を選んだ場合に限る**。`recent-auth.password` は `throttle:6,1` (inline) だが、
   passkey satisfier (`passkey.confirm-options` / `passkey.confirm` = `throttle:passkeys`) と
   再SSO satisfier (`social.redirect` / `social.callback` = named limiter) は
   **inline bucket を 1 も消費しない**。
   そして **描画のたびに発火する GET は 1 本も増やさない**
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

### 前提 2b: satisfier が 1 つも無いユーザーの着地
`canSatisfy=false` (password 未設定・SSO 未連携・passkey 未登録) の着地は既存の
`RecentAuthModal` + `RecentAuthRecoveryNotice` が担当し、
`tests/js/components/organisms/RecentAuthModal.test.ts` が固定している。
本設計は step-up を要求する route を増やすだけで、**satisfier 側の経路も着地も増やさない**。
新しい復旧 UI は作らない (今必要なものだけ作る)。

### 前提 3: 既存機構をそのまま使う
- 後付けは `FortifyServiceProvider::attachRecentAuthToSensitiveRoutes()`
  (booted callback + `refreshNameLookups()`) をそのまま使う。新しい binder を作らない。
- `route:cache` の運用要件 (毎デプロイ再生成) は throttle 後付けと同じで、
  `attachRecentAuthToSensitiveRoutes` は cached 起動でも `CompiledRouteCollection` の
  nameCache 経由で同一 instance に効く (既存 docblock の主張)。本設計で前提を増やさない。
- クライアントの step-up 再開機構 (`withRecentAuth` / `RecentAuthModal` / `pendingAction`)
  は既存のものを使う。新しいモーダルも新しい状態機械も作らない。
- **Fortify の controller / レスポンス契約は 1 行も変更しない**。遮断応答は
  `RequireRecentAuth` が既に返している既存契約 (XHR = 409 + `RecentAuthRequiredResource`、
  通常遷移 = 302 `recent-auth.confirm`) をそのまま使う。新しい `response()->json()` は
  1 本も書かない (禁止事項 4)。

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
