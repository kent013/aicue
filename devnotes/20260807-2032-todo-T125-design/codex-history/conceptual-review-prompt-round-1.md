# 概念設計レビュー依頼 (Round 1): inline throttle 群の bucket 共有の見直し (T125)

## アプリの使命 (North Star) — AGENTS.md より転記

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
7. 操作系 POST の応答での `redirect()->intended()`(ログイン直後フロー専用。
   招待送信等は `back()->with(...)` で完結させる)
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

## あなたの役割

あなたは Web アプリケーション（Laravel + Svelte）の改善に関する概念設計レビュアーです。

【レビュー観点】
1. 使命との整合性: この改善はアプリの使命（North Star）に本質的に貢献するか
2. 禁止事項違反: 上記禁止事項に抵触していないか
3. 実現可能性: 技術的に実現可能か（Laravel 12 + Svelte 5 + Inertia.js）
4. 期待効果の妥当性: 主張している効果は合理的に期待できるか
5. リスク: 重大な副作用・後退の可能性はないか
6. スコープの適切さ: 過大または過小になっていないか
7. **型安全性**: DTO/JsonResource パターンに沿っているか。PHPStan level 10 を通せるか

【出力形式】
- 全体判定: APPROVED / CHANGES_REQUESTED
- 各観点ごとに [Critical] [Warning] [Suggestion] で分類して指摘
- Critical/Warning には修正提案を必ず添える
- 日本語で出力

【このリポジトリの追加コンテキスト】
- ドメイン固有規約 5 (流量制限): 保護対象群は throttle をちょうど 1 本持つか、型付き enum + 30 文字以上の根拠で exemption 目録へ登録する (`ThrottleCoverageInventoryTest` が deny-by-default で強制)。named limiter のキーは `{レーン}:{種別}:{値}` で `RateLimiterKeyConventionTest` が全 limiter を実評価して検査する。**閾値は既存値を変えない。新しい面には既に本番稼働中の同性質エンドポイントと同値を充てる**。inline throttle は「認証済みかつ actor 自身に閉じる操作」限定で、**レーンを分けたいときは inline ではなく named limiter を新設する**。
- 不変条件は `tests/Architecture/` の deny-by-default 目録型 gate で機械強制するのが本リポジトリの流儀 (`ThrottleCoverageInventoryTest` / `QueuedJobLeaseInventoryTest` / `BillingGatewayFailureTaxonomyInventoryTest` が見本)。
- テストは Pest + `RefreshDatabase` グローバル適用 + `--parallel`。個別 `DatabaseTransactions` は禁止。テストデータは Factory。

---

## 概念設計

<!-- ここから devnotes/20260807-2032-todo-T125-design/conceptual-design.md 全文 -->

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

| 新 named limiter | max | 収容する route | 数える対象 |
|---|---|---|---|
| `password-credential` | 6/min | `recent-auth.password` / `password.confirm.store` / `user-password.update` / `settings.password.store` | actor 自身のパスワード credential を照合 or 設定する面 |
| `email-verification` | 6/min | `verification.send` / `verification.verify` | メール検証フロー (送信 + 検証) |
| `two-factor-manage` | 10/min | `two-factor.enable` / `.confirm` / `.disable` / `.regenerate-recovery-codes` | 2FA 設定の変更操作 |
| `invitation-accept-submit` | 10/min | `invitations.accept.store` | 招待 token の受諾確定 |
| `plan-activate` | 10/min | `onboarding.activate-personal` | パーソナルプランの有効化 |

キーは既存規約どおり `{レーン}:{種別}:{値}`
(`password-credential:user:{id}` / 未認証フォールバックは `password-credential:ip:{ip}`)。
`passkeys` / `two-factor-secret-read` と同形にする。

`email-verification` の 2 本を 1 レーンにするのは設計判断であると同時に**構造的な帰結**でもある。
Fortify は `config('fortify.limiters.verification')` という **1 つの knob** で
`verification.send` と `verification.verify` の両方に throttle を貼るため、
package の設定 (貼る仕組みの第 2 段) を使う限りこの 2 本は必ず同レーンになる。
第 2 段で貼れるものを第 3 段 (`RouteThrottleBinder`) に落とさないのが既存規約
(`docs/app-integration-guide.md` §7b) であり、それに従う。

### 2. 本変更が「単調緩和」であることを設計上の安全性の根拠にする

新しい各レーンの route 集合は、いずれも**現在の共有 bucket の部分集合**である。
したがってどの route も **従来 429 になっていた条件の部分集合でしか 429 にならない**
(新たに 429 になる状況は 1 つも増えない)。後退リスクは構造的にゼロ。

一方で「同一 actor が 1 分間に踏める inline 操作の合計」は 6 → レーン別に分散する
ぶんだけ増える。ここで確認すべきは**総当り耐性の天井が上がらないこと**である:

- パスワード照合面 (`recent-auth.password` / `password.confirm.store` / `user-password.update`)
  → 3 本まとめて 6/min。**現状と同じ** (現状も合算 6 で頭打ち)。
- TOTP コード確認 (`two-factor.confirm`) → 10/min。現状の比較値も 10 で**同じ**。
- 招待 token 総当り (`invitations.accept.store`) → 10/min。**同じ**。

増えるのは「無関係な操作を並行して踏める回数」だけであり、
**推測可能な秘密に対する試行回数の上限は 1 つも緩まない**。

### 3. 規約を機械検査へ昇格させる (deny-by-default 目録)

移行しただけでは「次に inline を足す人」を止められない。以下を新設する。

- **`InlineThrottleInventoryTest`** (Architecture, deny-by-default)
  inline throttle (`{max},{decay}` 形式 + パラメータなし) を持つ route は、
  型付き enum `InlineThrottleBucketRationale` + 30 文字以上の根拠で目録登録が必須。
  **case 別 cap で「認証済み actor のキーで数えられる inline は 1 本まで」を固定**する。
  これは AGENTS.md 規約 5 の後半をそのまま機械化したものである
  (2 本目を足そうとした瞬間に fail し、named limiter を作るしかなくなる)。
- **レーン割当の目録**: 本設計が新設した 5 レーンを使う route 集合が宣言と完全一致すること
  (未宣言の route が既存レーンへ相乗りするのを deny-by-default で止める)。
  「描画のたびに飛ぶ GET を `password-credential` に足す」事故はここで止まる。
- **`RateLimiterKeyConventionTest` の拡張**: 5 レーンのキー検証シナリオ追加に加え、
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
  同一 IP の 60/min bucket を共有するが、いずれも step-up / 回復導線ではなく、
  巻き添えが「詰み」を作らない (1 分で回復)。

## 期待効果

- **使命への貢献**: 再認証・パスワード設定・招待受諾は「現場作業者が撮影に到達するための導線」である。
  無関係な操作の巻き添えで 1 分間止まり、しかも理由が画面に出ないのは
  「思考ゼロ・編集ゼロ」の対極にある。撮影 PWA の利用者は現場でサポートを呼べない。
- **巻き添え 429 の構造的除去**: 上記 3 つの壊れ方が設計上起こりえなくなる。
- **規約の機械化**: 「レーンを分けたいときは named limiter」が文章から gate になる。
  T121 の実測 (AGENTS.md に記載済み) が、次の実装者を実際に止められるようになる。
- 後退リスクは構造的にゼロ (単調緩和)。総当り耐性の天井は不変。

## 実装方針（概要）

| 変えるもの | 内容 |
|---|---|
| `app/Providers/FortifyServiceProvider.php` | 認証面 3 レーン (`password-credential` / `email-verification` / `two-factor-manage`) の `RateLimiter::for()` 登録。`throttledFortifyRoutes()` の値を `6,1` / `10,1` から limiter 名へ差し替え |
| `app/Providers/AppServiceProvider.php` | 業務面 2 レーン (`invitation-accept-submit` / `plan-activate`) の登録 |
| `config/fortify.php` | `limiters.verification` に `email-verification` を追加 (現在は未設定で Fortify 既定 `6,1`) |
| `routes/web.php` | `recent-auth.password` / `settings.password.store` / `invitations.accept.store` / `onboarding.activate-personal` の inline 指定を limiter 名へ |
| `app/Enums/Security/InlineThrottleBucketRationale.php` | 新設 (inline 据え置き理由の型付き分類) |
| `tests/Architecture/InlineThrottleInventoryTest.php` | 新設 (deny-by-default 目録 + case 別 cap + 空振り検出 + 負のコントロール) |
| `tests/Architecture/RateLimiterKeyConventionTest.php` | 5 レーン追加 + limiter 間キー衝突検査 + 共有グループ目録 |
| `tests/Feature/Security/AuthThrottleCoverageTest.php` | レーン独立と各レーン上限の behavioral proof |
| 既存テストの追随 | `ActivatePersonalTest` / `PasswordSetupTest` の throttle テスト名・意図コメント、`ControllerAuthorizationGateTest` / `ThrottleCoverageInventoryTest` の exemption 理由文中の `throttle:6,1` 表記 |
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


---

## 特に判断を求めたい論点

1. **レーンの切り方**: 「route ごとに 1 bucket」ではなく「数える対象ごとに 1 レーン」を採用し、
   パスワード照合 3 本 + パスワード設定 1 本を `password-credential` に集約した。
   AGENTS.md 思考原則 4「別物の概念を『似ているから』で統合しない」に照らして妥当か。
   特に「照合 (current_password 検証あり)」と「設定 (step-up 済みで照合なし)」を
   同レーンに入れた判断はどうか。
2. **`email-verification` の 2 本同居**: Fortify の config が 1 knob しか持たないため
   `verification.send` (メール送信 = 外向きコストあり) と `verification.verify` (署名付き GET) が
   同レーンになる。コスト非対称を理由に第 3 段 (`RouteThrottleBinder`) で分けるべきか、
   package の設定 (第 2 段) に留めるべきか。
3. **単調緩和という安全性の主張**: 「新レーンの route 集合は現共有 bucket の部分集合なので
   新たに 429 になる状況は増えない」「推測可能な秘密への試行回数の天井は不変」という
   2 つの主張に穴はないか。
4. **inline を 3 本残す判断**: `livewire.upload-file` / `passport.token` / `passport.device.code` を
   named 化せず目録登録に留めた。特に「認証済みキーで inline を使う route は 1 本まで」を
   case 別 cap で機械固定する案が、規約の機械化として十分か / やり過ぎか。
5. **スコープ外にした `api-read` / `api-write` / `api-status` の同一キー共有**:
   本件と同型の課題だが分離は総量上限の緩和を伴うため今回は目録に記録するだけとした。
   今回まとめてやるべきか、分けるのが正しいか。
6. 過大 / 過小: 新設する gate (Architecture 目録 1 本 + キー衝突検査 + behavioral proof) は
   「今必要なものだけ作る」に照らして適量か。
