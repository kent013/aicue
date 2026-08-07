# 概念設計: inline-throttle-bucket-separation (T125)

## 背景・課題

### 実測した仕組み

`Illuminate\Routing\Middleware\ThrottleRequests::handle()` は inline 形式
(`throttle:6,1`) のとき bucket キーを次のように組む (vendor 実測):

```php
'key' => $prefix.$this->resolveRequestSignature($request),   // $prefix の既定は ''
// resolveRequestSignature(): 認証済みなら formatIdentifier($user->getAuthIdentifier())
//                            未認証なら formatIdentifier($route->getDomain().'|'.$request->ip())
```

キーに **route 名も limiter 名も入らない**。したがって
**同一 actor の全 inline throttle route は 1 つの bucket を共有**し、
route ごとに違うのは「そのカウンタと比較する `maxAttempts` の値」だけである。

### 現状の母集団 (`php artisan route:list` 実測、15 本)

| 指定 | route | 認証 | キーの種別 |
|---|---|---|---|
| `6,1` | `verification.send` / `verification.verify` / `recent-auth.password` / `settings.password.store` / `password.confirm.store` / `user-password.update` | auth 必須 | user id |
| `10,1` | `invitations.accept.store` / `onboarding.activate-personal` / `two-factor.enable` / `two-factor.confirm` / `two-factor.disable` / `two-factor.regenerate-recovery-codes` | auth 必須 | user id |
| `60,1` | `livewire.upload-file` (Livewire の controller middleware 既定) | なし (署名必須) | 認証済みなら user id / 未認証は IP |
| 既定 (パラメータなし = `60,1`) | `passport.token` / `passport.device.code` | なし (stateless) | IP |

**認証済み actor のキーで数えられる 13 本が 1 bucket を共有している。**

### 壊れ方 (仮説と、それが起きる筋道)

共有カウンタは全 13 本の合算で増え、比較値だけが route ごとに違う。したがって
**最小 `max` を持つ route が最初に死ぬ**。最小は `recent-auth.password` (= 6)。

1. **max 60 の route が max 6 の route を殺す**
   Filament 管理画面でファイルを 6 個アップロード (`livewire.upload-file`, max 60) すると、
   同一 actor の `recent-auth.password` が 429 になる。
   「60 回まで許す」と書いた面が「6 回まで」の面を潰すのは、どの設計意図にも一致しない。
2. **再認証が壊れる = step-up を要求する操作が 1 分間できなくなる**
   2FA を設定し直す (`two-factor.enable` → `.disable` → `.confirm` …) を 6 回踏むと
   `recent-auth.password` が 429。`recent-auth` が要る操作
   (`settings.password.store` / `settings.account.destroy`) は満たしようがなくなる。
   satisfier は password 再入力と再 SSO の 2 本だが、password ログインしかないユーザーには
   逃げ道が無い (回復まで最大 1 分)。
3. **理由の説明できない 429**
   認証メール再送 (`verification.send`) を数回押した直後にパスワード変更
   (`user-password.update`) が 429 になる。ユーザーにも運用者にも因果が見えない。

### なぜ規約があるのに残っているのか

AGENTS.md ドメイン固有規約 5 は既に
「レーンを分けたいときは inline ではなく named limiter を新設する」と書いており、
T121 では `two-factor-secret-read` がその方針で新設されている。
しかし **既存 13 本はこの規約が書かれる前からの残置**であり、
**規約を機械検査する gate が無い**ため、新しい inline 追加も止まらない。
規約が文章だけで守られている状態は、このリポジトリの他の不変条件
(`ThrottleCoverageInventoryTest` / `QueuedJobLeaseInventoryTest` /
`BillingGatewayFailureTaxonomyInventoryTest`) の水準に達していない。

## 改善アイデア

### 1. 「何を数える面か」でレーンを切り、named limiter へ移す (閾値は 1 つも変えない)

分割の原則を先に決める (route ごとに 1 lane にはしない):

> **同じ credential・同じ feature を数える面は同じレーンでよい。
> 無関係な面は分ける。**

route ごとに bucket を分けると、たとえば「パスワード照合面が 3 本あるので
実質 18 回/min 試せる」ことになり、総当り耐性が下がる。逆に無関係な面が
同居していることが現在のバグである。したがって切り口は「数える対象」である。

レーンを決める前に、対象 route を「何を守っているか」で棚卸しする
(Round 1 レビュー指摘: レーン設計の妥当性は route の性質表がないと検証できない)。

| route | 推測可能な秘密の照合 | 外向きコスト | 永続状態の変更 | max |
|---|---|---|---|---|
| `recent-auth.password` | **あり** (password) | なし | なし (session の鮮度のみ) | 6 |
| `password.confirm.store` | **あり** (password) | なし | なし (session の鮮度のみ) | 6 |
| `user-password.update` | **あり** (current_password 必須) | なし | あり (password 更新) | 6 |
| `settings.password.store` | なし (step-up 済み・照合なし) | なし | あり (password 初回設定) | 6 |
| `verification.send` | なし | **あり** (メール送信) | なし | 6 |
| `verification.verify` | 署名検証のみ (推測不可能な signed URL) | なし | あり (verified_at) | 6 |
| `two-factor.confirm` | **あり** (TOTP 6 桁) | なし | あり | 10 |
| `two-factor.enable` / `.disable` / `.regenerate-recovery-codes` | なし | なし | あり (秘密の生成/破棄) | 10 |
| `invitations.accept.store` | **あり** (招待 token) | なし | あり (メンバー追加) | 10 |
| `onboarding.activate-personal` | なし | なし | あり (プラン確定) | 10 |

これを踏まえたレーン:

| 新 named limiter | max | 収容する route | 数える対象 |
|---|---|---|---|
| `password-verify` | 6/min | `recent-auth.password` / `password.confirm.store` / `user-password.update` | actor 自身のパスワード**照合**試行 |
| `password-set` | 6/min | `settings.password.store` | パスワードの**初回設定** (照合を伴わない credential mutation) |
| `email-verification` | 6/min | `verification.send` / `verification.verify` | メール検証フロー (送信 + 検証) |
| `two-factor-manage` | 10/min | `two-factor.enable` / `.confirm` / `.disable` / `.regenerate-recovery-codes` | 2FA 設定の変更操作 |
| `invitation-accept-submit` | 10/min | `invitations.accept.store` | 招待 token の受諾確定 |
| `plan-activate` | 10/min | `onboarding.activate-personal` | パーソナルプランの有効化 |

キーは既存規約どおり `{レーン}:{種別}:{値}`
(`password-verify:user:{id}` / 未認証フォールバックは `password-verify:ip:{ip}`)。
`passkeys` / `two-factor-secret-read` と同形にする。

**`password-verify` と `password-set` を分ける根拠** (Round 1 で修正):
`settings.password.store` は `PasswordSetupController::store()` の実装上
current_password を照合しない (パスワード**未設定**ユーザー専用の経路で、
防御は `recent-auth` middleware と `PasswordCredentialService` の fail-closed 拒否)。
「秘密の推測試行」ではなく「credential mutation」であり、照合面と数える対象が違う。
同居させると「パスワードを設定しようとして 6 回失敗した結果、
step-up 再認証 (`recent-auth.password`) が 429 になる」という
本 TODO が潰したいはずの巻き添えが 1 本だけ残る。

**統合の基準は 2 つあり、混同しない** (Round 2 で明記):

- **同じ credential を照合する面**は同レーン (`password-verify`)。
  レーンは「その秘密に対する試行予算」であり、面が増えても予算は増やさない。
- **同じ feature のフロー**は同レーン (`two-factor-manage` / `email-verification`)。
  レーンは「そのフローの操作予算」であり、フロー内の相互消費は許容する。

**`two-factor.confirm` を `two-factor-manage` に含める根拠と受容リスク**:
`two-factor-manage` は後者 (feature 単位の操作予算) として設計する。
したがって `enable` / `disable` / `regenerate` の消費によって、
秘密照合面である `confirm` が 429 になる構造が**意図的に残る**。
これを受け入れるのは、4 本とも「2FA を設定中の同一ユーザーが同一画面から踏む操作」で
1 つのフローを成し、TOTP の天井は分離しても 10 のまま変わらないためである
(分離するとレーンが増えるだけで得るものがない)。
**他 feature との巻き添えは遮断される**ことが本 TODO の目的であり、
フロー内の相互消費はそれと矛盾しない。

**`email-verification` の 2 本同居は Fortify 制約を優先した暫定判断** (Round 1 で明記):
Fortify は `config('fortify.limiters.verification')` という **1 つの knob** で
`verification.send` と `verification.verify` の両方に throttle を貼る。
第 2 段 (package の設定) で貼れるものを第 3 段 (`RouteThrottleBinder`) へ落とさないのが
既存規約 (`docs/app-integration-guide.md` §7b) であり、それに従う。
概念的には「外向きメール送信」と「署名付き GET の検証」は数える対象が違う。
**将来分離する条件**: (a) メールクライアント / セキュリティ製品によるリンク先読みで
`verification.verify` が想定外に消費され、再送 (`verification.send`) が 429 になる事象が
観測されたとき、または (b) Fortify が 2 つの knob を持つようになったとき。
そのときは `verification.verify` のみ第 3 段で別レーンへ移す
(送信側の 6/min は現行値のまま維持する)。

### 2. 「巻き添え 429 については単調緩和」+「コスト軸ごとの天井は維持」を分けて主張する

新しい各レーンの route 集合は、いずれも**現在の共有 bucket の部分集合**である。
したがってどの route も **従来 429 になっていた条件の部分集合でしか 429 にならない**
= **巻き添え 429 の経路は 1 つも増えない**。

ただし「後退リスクは構造的にゼロ」とは**言えない** (Round 1 で修正)。
同一 actor が 1 分間に実行できる認証関連操作の**合算量は増える**ため、
コスト軸ごとに天井を個別に確認する。

まず**現状の天井を正確に書く** (Round 2 で修正)。共有カウンタは 1 本なので、
`max` が小さい route から順に死ぬだけで「全体で 6 回」ではない:
counter が 6 に達すると `max=6` の 6 本が 429、10 に達すると `max=10` の 6 本も 429。
したがって**認証面 12 本の受理リクエスト総数の天井は 10/min**である
(その先も `livewire.upload-file` は `max=60` なので 60 まで通り続ける)。

| コスト軸 | 現状の天井 | 変更後の天井 | 判定 |
|---|---|---|---|
| パスワード照合試行 (`recent-auth.password` / `password.confirm.store` / `user-password.update`) | 6/min (共有カウンタの最小比較値) | `password-verify` 6/min (同じ 3 本) | **不変** |
| TOTP コード試行 (`two-factor.confirm`) | 10/min | `two-factor-manage` 10/min | **不変** |
| 招待 token 試行 (`invitations.accept.store`) | 10/min | `invitation-accept-submit` 10/min | **不変** |
| 外向きメール送信 (`verification.send`) | 6/min | `email-verification` 6/min | **不変** |
| **認証面 12 本の受理リクエスト総数** | 10/min | **48/min** (6+6+6+10+10+10 の単純合算) | **増える** |
| throttle 通過後に生成されるアプリケーション処理量・ログ | 上に同じ (10/min) | 上に同じ (48/min) | **増える** |

- 48/min は **throttle だけから導ける保守的上限** (受理されるリクエスト数) である。
  **実際に成功する永続状態遷移の数はこれより少ない**が、その差を作るのは
  各操作の事前条件と一回性 (`plan-activate` は契約済み組織では常に失敗、
  `password-set` はパスワード設定済みユーザーでは `PasswordCredentialService` が
  fail-closed 拒否、`two-factor.confirm` は正しい TOTP が要る) であって
  **throttle の保証ではない**。したがって throttle の議論では 48/min を上限として扱う。
- 状態変更に**付随する副作用** (監査ログ・通知イベント・セッション失効・password rehash・
  ジョブ dispatch) は上記の成功条件に強く制約されるため独立した軸としては数えず、
  「状態変更を**試行できる受理リクエスト**の上限が 48/min に上がることに付随するもの」
  として同じ枠で確認する。
- `livewire.upload-file` (60/min) は本変更で一切動かないため軸に含めない。

増えるのは「秘密の推測を伴わない操作を並行して踏める回数」とそれに伴うログ量である。
1 actor あたり数十/min の水準であり、
**推測可能な秘密への試行回数の上限は 1 軸も緩まない**。
これが本設計が受け入れるトレードオフであり、「リスクゼロ」ではない。

### 3. 規約を機械検査へ昇格させる (deny-by-default 目録)

移行しただけでは「次に inline を足す人」を止められない。以下を新設する。

- **`InlineThrottleInventoryTest`** (Architecture, deny-by-default)
  inline throttle (`{max},{decay}` 形式 + パラメータなし) を持つ route は、
  型付き enum `InlineThrottleBucketRationale` + 30 文字以上の根拠で目録登録が必須。
  **分類は route 単位ではなく「bucket signature の性質」で定義する** (Round 1 で修正)。
  - `VendorStatelessIpBucket` — session を持たず `resolveRequestSignature()` が
    常に IP へ倒れる vendor route (cap 2 = 現在値ちょうど)
  - `VendorMixedUserOrIpBucket` — 認証状態によって user id / IP のどちらにもなる
    vendor route (**cap 1 = 現在値ちょうど**)
  **自前 route 向けの case は 1 つも定義しない**。したがって自前 route に inline を
  足すと「当てはまる case が無い」= 目録に登録できず必ず fail する。
  これは AGENTS.md 規約 5 の後半をそのまま機械化したものである
  (足そうとした瞬間に fail し、named limiter を作るしかなくなる)。
- **レーン割当の目録**: 本設計が新設した **6 レーン**
  (`password-verify` / `password-set` / `email-verification` / `two-factor-manage` /
  `invitation-accept-submit` / `plan-activate`) を使う route 集合が宣言と完全一致すること
  (未宣言の route が既存レーンへ相乗りするのを deny-by-default で止める)。
  「描画のたびに飛ぶ GET を `password-verify` に足す」事故はここで止まる。
- **`RateLimiterKeyConventionTest` の拡張**: 6 レーンのキー検証シナリオ追加に加え、
  **limiter 間でキーが衝突しないこと** (= レーンが実際に分かれていること) を実評価で検査する。
  意図的に同一キーを共有している既存の組 (`api-read` / `api-write` / `api-status`) は
  「共有グループ」として目録に明示登録する (挙動は変えない。§スコープ外)。
- **`AuthThrottleCoverageTest` への behavioral proof 追加**:
  「A レーンを使い切っても B レーンが 429 にならない」「各レーンの上限は維持されている」を
  実 HTTP で固定する。T121 で追加された
  「2FA 秘密 GET のレーンは独立している」テストと同じ形式・同じ意図。

### 4. 残す inline 3 本は「据え置き」ではなく「裁定して目録に載せる」

- `livewire.upload-file` — throttle は Livewire の controller middleware 既定
  (`config('livewire.temporary_file_upload.middleware') ?: 'throttle:60,1'`)。
  上書きには `config/livewire.php` の公開が要るが、Livewire は `mergeConfigFrom` (浅い merge) の
  ため部分定義では `temporary_file_upload` 配下の他キー (disk / rules / cleanup 等) を
  丸ごと落とす。全体公開は upgrade 追従コストを恒常的に負う。
  **移行後はこれが「認証済みキーで inline を使う唯一の route」になり、bucket は事実上専有**になる。
- `passport.token` / `passport.device.code` — Passport がハードコードした `throttle` (既定 60/min)。
  stateless (session なし) のため常に IP キー。2 本 + `livewire.upload-file` の未認証分岐が
  同一 IP の 60/min bucket を共有する。
  **これは残存リスクであって「無害」ではない** (Round 1 で修正):
  OAuth token endpoint の 429 は MCP / API クライアントのトークン更新失敗に直結し、
  API 利用不能を引き起こしうる。ただし (a) 影響は同一 IP に閉じる、
  (b) 本 TODO の主障害である「認証済み actor の step-up 巻き添え」とは**別問題**であり、
  (c) named 化には vendor 側の非対応 (Passport は throttle をハードコードし、
  後付けすると二重付与になる) という構造的障壁がある。
  今回は**目録へ根拠付きで登録し、unresolved risk として明示する**に留める。

### 5. gate の責務境界を明文化する (重複検査を作らない)

| gate | 責務 |
|---|---|
| `ThrottleCoverageInventoryTest` (既存) | 保護対象 route が throttle を**ちょうど 1 本**持つこと |
| `InlineThrottleInventoryTest` (新設) | inline throttle の**残存理由**と bucket 共有の**上限** |
| `RateLimiterKeyConventionTest` (拡張) | named limiter の**キー形式**と **limiter 間の衝突** |
| `AuthThrottleCoverageTest` (拡張) | 実 HTTP での**巻き添え 429 の消滅**と各レーンの上限維持 |

本設計は既存 gate の責務に触れない (`ThrottleCoverageInventoryTest` の
母集団・exemption 台帳は本数が変わらないため無変更で通る)。

## 期待効果

- **到達不能・回復不能な認証導線の除去**: 再認証・パスワード設定・招待受諾は
  「現場作業者が撮影に到達するための前提」である。無関係な操作の巻き添えで 1 分間止まり、
  しかも理由が画面に出ないのは「思考ゼロ・編集ゼロ」の対極にある
  (撮影 PWA の利用者は現場でサポートを呼べない)。
  本改善は撮影機能そのものを良くするのではなく、**そこへ至る導線が塞がる経路を消す**。
- **巻き添え 429 の構造的除去**: 上記 3 つの壊れ方が設計上起こりえなくなる。
- **規約の機械化**: 「レーンを分けたいときは named limiter」が文章から gate になる。
  T121 の実測 (AGENTS.md に記載済み) が、次の実装者を実際に止められるようになる。
- 巻き添え 429 に関しては単調緩和 (新たに 429 になる経路は増えない)。
  推測可能な秘密への試行回数の天井は全軸で不変 (§改善アイデア 2 の表)。

## 実装方針（概要）

**変更の範囲は「throttle middleware の指定」と「RateLimiter 登録」に閉じる。**
controller の応答 (DTO / JsonResource / Inertia / redirect) には一切触らない。
vendor route の応答や middleware stack も上書きしない (Round 1 で明記)。

| 変えるもの | 内容 |
|---|---|
| `app/Support/Http/RateLimiterKeys.php` | 新設。`{レーン}:user:{id}` / `{レーン}:ip:{ip}` を組む唯一の helper (PHPStan level 10 の null 分岐をここへ集約)。既存の `passkeys` / `two-factor-secret-read` も同 helper へ寄せる (キー文字列は不変) |
| `app/Providers/FortifyServiceProvider.php` | 認証面 4 レーン (`password-verify` / `password-set` / `email-verification` / `two-factor-manage`) の `RateLimiter::for()` 登録。`throttledFortifyRoutes()` の値を `6,1` / `10,1` から limiter 名へ差し替え |
| `app/Providers/AppServiceProvider.php` | 業務面 2 レーン (`invitation-accept-submit` / `plan-activate`) の登録 |
| `config/fortify.php` | `limiters.verification` に `email-verification` を追加 (現在は未設定で Fortify 既定 `6,1`) |
| `routes/web.php` | `recent-auth.password` / `settings.password.store` / `invitations.accept.store` / `onboarding.activate-personal` の inline 指定を limiter 名へ |
| `app/Enums/Security/InlineThrottleBucketRationale.php` | 新設 (inline 据え置き理由の型付き分類) |
| `tests/Architecture/InlineThrottleInventoryTest.php` | 新設 (deny-by-default 目録 + case 別 cap + 空振り検出 + 負のコントロール) |
| `tests/Architecture/RateLimiterKeyConventionTest.php` | 6 レーン追加 + limiter 間キー衝突検査 + 共有グループ目録 |
| `tests/Feature/Security/AuthThrottleCoverageTest.php` | レーン独立と各レーン上限の behavioral proof |
| 既存テストの追随 | `ActivatePersonalTest` / `PasswordSetupTest` の throttle テスト名・意図コメント、`ControllerAuthorizationGateTest` / `ThrottleCoverageInventoryTest` の exemption 理由文中の `throttle:6,1` 表記、`AuthThrottleCoverageTest` の既存レーン独立テストが叩く `two-factor.confirm` の位置づけ |
| docblock の誤記訂正 | `FortifyServiceProvider` の `two-factor-secret-read` docblock「throttle は auth middleware より先に走る」は事実と逆 (priority list では `AuthenticatesRequests` → `ThrottleRequests`)。誤った前提を新レーンへ複製しないため同 PR で訂正し、実効順を behavioral テストで固定する |
| ドキュメント | `AGENTS.md` ドメイン規約 5 / `docs/app-integration-guide.md` §7b を「移行済み + gate で強制」の状態に更新 |

## 制約・前提

- **閾値は 1 つも変えない** (AGENTS.md ドメイン規約 5)。新レーンの max は移行元の inline 値そのまま。
- named limiter のキーは `{レーン}:{種別}:{値}` (`RateLimiterKeyConventionTest` が全 limiter を実評価)。
  本件では email をキーに入れるレーンが無いため `EmailNormalizer` / `EmailHash` は使わない
  (`Str::transliterate()` も当然使わない)。
- 貼る仕組みは 3 段優先順 (`docs/app-integration-guide.md` §7b) に従う:
  自前 route は直書き / Fortify の `verification` は config / それ以外の Fortify route は
  `RouteThrottleBinder::attachOnBooted()`。**第 2 段で貼れるものを第 3 段へ落とさない**。
- **`Authenticate` は `ThrottleRequests` より先に走る** (framework 既定 priority list を
  `route:list` の解決後 middleware 列で実測)。よって対象 13 本の未認証分岐は HTTP 経路では
  到達しない。それでも IP フォールバックを持つのは PHPStan の null 安全と
  「priority list への依存を単一障害点にしない」ためであり、
  `passkeys` / `two-factor-secret-read` と同形。
  現行 docblock の「throttle は auth middleware より先に走るため未認証でも closure が評価される」
  という記述は事実と逆なので、同 PR で訂正する (誤った前提を新レーンへ複製しない)。
- `RouteThrottleBinder` は「既存 throttle と期待値が一致しないなら起動を止める」設計であり、
  vendor がハードコードした throttle を**置換できない** (Passport がこれに当たる)。
- PHPStan level 10: limiter closure の戻り値は `Limit` を明示、`$request->user()` は
  null 前提で分岐、`$request->ip()` の nullable も明示的に扱う。
  キー組み立ては `RateLimiterKeys` helper に集約し、
  引数 `$lane` / 戻り値を `non-empty-string` で閉じる。
  user identifier は `getAuthIdentifier()` が `int|string|null` を返しうるため
  `is_int()` / `is_string()` で明示的に絞り込む (既存 `passkeys` は `is_scalar()` を使うが、
  これは `bool` / `float` まで許してしまい契約と一致しないため helper 側では採らない)。
  **lane を enum 化はしない**: `RateLimiter::for()` の第 1 引数は
  `RateLimiterRegistrationScanner` の要求でリテラル文字列でなければならず
  (解析不能な登録は `RateLimiterKeyConventionTest` が unresolved として fail させる)、
  enum を導入すると「`for()` にはリテラル / helper には enum」の二重管理になる。
- `ThrottleCoverageInventoryTest` の「throttle ちょうど 1 本」は維持する
  (named 化しても本数は変わらないため exemption 台帳への影響なし)。

## スコープ外

- **`api-read` / `api-write` / `api-status` の同一キー共有**。3 本とも `apiRateKey()` を返すため
  1 つの API キーの read / write / status は 1 bucket を共有しており、本件と同型の課題がある。
  ただし分離は「1 クライアントの総量上限を実質 120/min から 210/min へ緩める」変更であり、
  API の abuse 耐性の設計判断を伴う。今回は**目録に共有グループとして明示するだけ**で
  挙動は変えない (後続 TODO 候補として unresolved に残す)。
- `livewire.upload-file` / `passport.*` の named 化 (上記の理由で費用対効果が合わない)。
- 閾値そのものの見直し / 新しい閾値の発明。
- `recent-auth` の satisfier 追加、2FA 秘密 GET の step-up 化 (T124 の担当)。
- 429 時の UI 文言・リトライ導線の改善 (本件は bucket の分離のみ)。
- `passport.token` / `passport.device.code` / `livewire.upload-file` の未認証分岐が
  共有する IP bucket の分離 (§改善アイデア 4 の残存リスク。vendor 側の構造的障壁があり、
  本 TODO の主障害とは別問題)。
- controller / FormRequest / 応答形式の変更 (本件は throttle 指定と limiter 登録のみ)。
