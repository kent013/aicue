# 概念設計レビュー Round 2

Round 1 の指摘に対する対応マトリクスと、修正後の概念設計を提示する。
反論している項目は根拠 (既存コードの事実) を添えているので、事実誤認があれば指摘してほしい。
再判定 (APPROVED / CHANGES_REQUESTED) を出すこと。

# 対応マトリクス: conceptual-review Round 1

## [Critical] 初回パスワード設定 route の受入条件が未明文化 (観点 5)
- 判断: 対応する
- 根拠: 認証手段を増やす操作の脅威 (セッション奪取からの永続化) に対し、
  「recent-auth を付ける」だけでは何が保証されるか読み手に伝わらない。
- 対応内容: 概念設計に「施策 3 の受入条件 (6 項目)」を追加。既存機構が既に満たしている項目
  (TTL = `RecentAuthWindow` / session 束縛 = `RecentAuthState` が session に記録し
  `$request->user()` を対象にする) と、本批で新規に満たす項目 (lock 下の
  `hasPassword()===false` 再確認 / `SecurityEventType::PasswordSet` 記録 / 他セッション失効) を
  区別して明記した。

## [Critical] inventory gate が文字列ベースだと `status={undefined}` を見逃す (観点 7)
- 判断: 対応する
- 根拠: 妥当。「渡し忘れ」は検出できても「間違った値を渡す」は検出できない。
- 対応内容: gate の検査を 3 段から 4 段に強化。
  (a) 呼び出し側ファイル集合の deny-by-default、(b) `status={recentAuthStatus}` の
  **識別子まで固定**した完全一致 (任意式を許さない)、(c) 旧 prop 名の不在、
  (d) 各呼び出し側が `withRecentAuth` を import し `onStale` で `recentAuthStatus` に格納していること。
  加えて component 側は `status: RecentAuthStatus | null` を必須 prop として宣言する。

## [Warning] DTO / JsonResource 境界が曖昧 (観点 2)
- 判断: 反論する (+ 明記して誤読を防ぐ)
- 根拠: `/recent-auth/status` は既に `RecentAuthStatusDto` → `RecentAuthStatusResource` 経由で
  返しており `response()->json()` の直書きは無い (`ConfirmRecentAuthController::status()`)。
  TS 側 `RecentAuthStatus` (resources/js/lib/recent-auth.ts) も全 field 非 optional で、
  `fetchRecentAuthStatus()` が欠損を既定値で埋めて型を確定させている。
- 対応内容: 「そのまま渡す」の意味を「**サーバ contract の shape を分解せずに 1 個の型として運ぶ**」
  と明記し、サーバ側 DTO/Resource が正本である旨を概念設計に追記した (契約変更は無い)。

## [Warning] `settingsUrl` 削除の根拠が弱い (観点 2)
- 判断: 対応する (削除方針は維持し、根拠と波及を明記)
- 根拠: `LoginMethodRequiredResource` は `EnsureLoginMethodRemains::reject()` の
  `expectsJson()` 分岐でのみ返る内部 XHR contract であり、`routes/api.php` には露出していない。
  消費者は 0 件 (`grep settingsUrl resources/js` = 0)。しかも指す先 (`settings.security`) には
  パスワード設定 UI が無く、フロントのハードコード (`/settings`) とも食い違う = phantom 契約。
  「正しい URL に直して段階的廃止」は AGENTS.md 思考原則 3 (後方互換の並走を残さない) に反する。
- 対応内容: 概念設計に「内部 XHR 専用 contract であり公開 API ではない」ことと、
  波及テスト (`tests/Feature/Auth/LoginMethodRetentionTest.php:78`) の更新を明記した。

## [Warning] `PasswordCredentialService` の責務分離が曖昧 (観点 3)
- 判断: 対応する
- 根拠: 妥当。共有すべきは「確定後処理」であり、検証前提 (current_password の有無) は別。
- 対応内容: 公開 API を `setInitial(User, string $plain)` / `change(User, string $plain)` の 2 本にし、
  private `apply()` が hash 保存・監査記録・他セッション失効・DB session 行削除を担う設計に明記。
  `current_password` の検証は Fortify 契約側 (`UpdateUserPassword` の Validator) に残す。

## [Warning] transaction 境界が未定義 (観点 3)
- 判断: 対応する
- 根拠: 妥当 (AGENTS.md 実装規約「transaction は Service 内」)。
- 対応内容: `setInitial()` が Service 内 `DB::transaction` + `lockForUpdate()` で
  再確認から保存まで完結する旨を明記した。

## [Suggestion] 6 画面の status 取得元を固定せよ (観点 4)
- 判断: 対応する
- 根拠: gate の検査対象が変わるという指摘は正しい。
- 対応内容: 6 画面すべて既に `withRecentAuth({onStale})` で受けた status を
  `recentAuthStatus` state に格納する形で統一されており、この形を gate (d) で固定する旨を明記。

## [Warning] logout と Inertia history clear 契約の整合 (観点 5)
- 判断: 対応する
- 根拠: 妥当。inventory の差し替えだけでは経路 C の保証条件の説明が落ちる。
- 対応内容: 新 molecule が `router.post("/logout")` (= Inertia visit) であること、
  既存 `logout-call-site-inventory` の第 2 不変条件 (inventory ファイルに fetch/axios を持ち込まない) と
  `InertiaHistoryGuardTest` が引き続き保証を担うこと、`docs/supported-browsers.md` の
  経路 C の呼び出し元記述を更新することを波及変更として明記した。

## [Warning] スコープが少し広い (F-4/F-7) (観点 6)
- 判断: 一部対応する
- 根拠: F-3 (提示様式) は書き換える 3 ファイルの表示契約そのもので必須。
  F-4/F-7 は同一ファイル内の局所修正で、確かに分離可能。
- 対応内容: 施策 4 を「必須 (F-3)」と「同一ファイル内の付随修正 (F-4/F-7、
  実装が膨らんだ場合に切り離せる)」に分けて明記した。

## [Warning] `availableProviders` を discriminated union にせよ (観点 7)
- 判断: 反論する
- 根拠: 現状すでに `AvailableReauthProvider { provider: string; capability: string; reauthUrl: string }`
  の interface であり文字列配列ではない。さらに**サーバが step-up satisfier 可能な provider だけを
  載せる** (`ConfirmRecentAuthController::buildStatus()` が `ProviderCapability::isStepUpSatisfier()` で
  絞る) ため、クライアントに `canStepUp` 分岐は不要 (載っている = 使える)。分岐フラグを増やすと
  「サーバで絞る」不変条件と二重管理になる。PHP 側も `RecentAuthProviderDto` +
  `RecentAuthStatusResource` で array shape が明示され PHPStan level 10 を通っている。
- 対応内容: 概念設計に上記の不変条件 (「載っている = この端末以外の条件は満たす」) を明記した。


---

## 修正後の概念設計 (全文)

# 概念設計: ui-audit-remediation (再認証 UI の「踏破可能性の契約」を型と gate で固定する)

対象監査: `devnotes/20260805-1600-audit-cycle-2/ui-consistency.md` (F-1 Critical / F-2 High / F-3〜F-7 Medium・Low)

## 背景・課題

T099〜T106 サイクル後の多角監査 (UI 一貫性) で、**passkey-only ユーザーが機微操作で詰む**
経路が検出された。機械 gate (18 files / 117 tests) は全 green であり、**現行の gate では
検出できない種類の不整合**である点が本質。

3 つの発見は同一の失敗様式 —「**アカウントに能力はあるのに、その画面からは踏破できない**」— の変種:

| ID | 深刻度 | 内容 | 失敗様式 |
|---|---|---|---|
| F-1 | Critical | `RecentAuthModal` の `passkeyAvailable` が 6 呼び出し中 5 箇所で未配線 | 契約が optional prop で、配線漏れが**型でも gate でも検出されない** |
| F-2a | High | モーダルの回復 CTA が guest 限定 `/forgot-password` へリンク | 押すと無言リダイレクト = 踏破不能 CTA |
| F-2b | High | `PasskeySection` の「パスワードを設定する」→ `/settings` は `current_password` 必須 | 遷移先に**そのユーザーが踏める操作が無い** |

### F-1 の具体的な詰み (実在する母集団)

T106 の phantom password 撤去 (`Str::password(32)` の廃止) により
`User::hasPassword()` が正直になり、**password 無し / SSO 無し / passkey あり**のユーザーが実在する。
このユーザーが Settings/Index・Organizations/Settings・ApiKeys/Index・ApiKeys/Sessions・Admin/Users の
5 画面で機微操作を行うと:

1. サーバは `canSatisfy=true` / `passkeyAvailable=true` を返す
2. モーダルは `passkeyAvailable` の既定値 false でパスキーボタンを**描画しない**
3. `canSatisfy=true` なので回復導線ブロックにも入らない
4. 結果、`executableHere=false` の文言「このブラウザはパスキーに対応していません」が
   **対応ブラウザでも**出て、実行手段は 0、出口はキャンセルのみ

Codex 実装レビュー Round 1 の Critical「**全モーダル利用箇所で同一契約にすべき**」の
後半が未対応のまま APPROVED になった回帰であり、**再発防止の機械化が本設計の主眼**。

### F-2 の根因

「パスワード未設定ユーザーがパスワードを設定する UI 経路がアプリに存在しない」。
`EnsureLoginMethodRemains` の拒否文言は「先に別のログイン手段（**パスワードの設定**、
ソーシャル連携、他のパスキー）を追加してください」と案内しているが、
アプリ内にその操作が無い (= サーバの契約文がアプリの能力と食い違っている)。
`LoginMethodRequiredDto.settingsUrl` (= `settings.security`) はどのクライアントからも
消費されておらず (`grep settingsUrl resources/js` = 0 件)、フロントは別 URL (`/settings`) を
ハードコードしている。

## 改善アイデア

**「踏破可能な導線しか出さない」を、文言や個別修正ではなく契約・型・gate の 3 層で固定する。**

### 施策 1 [Critical] RecentAuthModal の契約を「status オブジェクト 1 個」に変える + call-site inventory gate

- `passwordSet` / `availableProviders` / `canSatisfy` / `passkeyAvailable` の 4 つの optional prop を
  廃し、**`/recent-auth/status` の応答 (`RecentAuthStatus`) をそのまま受ける必須 prop `status` 1 本**にする。
  分解して手渡す形である限り、フィールドが増えるたびに同じ配線漏れが再発する
  (今回まさに `passkeyAvailable` の追加で再発した)。
  - ここで言う「そのまま」は **サーバ contract の shape を分解せずに 1 個の型として運ぶ**という意味で、
    契約の正本は従来どおりサーバの `RecentAuthStatusDto` → `RecentAuthStatusResource`
    (`response()->json()` の直書きは無い / 禁止事項 4 準拠)。クライアント側の写像
    `RecentAuthStatus` (`resources/js/lib/recent-auth.ts`) は**全 field 非 optional**で、
    `fetchRecentAuthStatus()` が欠損を既定値で埋めて型を確定させる。本批で contract 自体は変えない。
  - `availableProviders` は `AvailableReauthProvider { provider, capability, reauthUrl }` の配列で、
    **サーバが step-up satisfier 可能な provider だけを載せる**
    (`ConfirmRecentAuthController::buildStatus()` が `ProviderCapability::isStepUpSatisfier()` で絞る)。
    したがってクライアント側に `canStepUp` 相当の分岐フラグは置かない
    (「載っている = 使える」が不変条件。クライアントに判定を持たせると二重管理になる)。
- **後方互換の並走を残さない** (AGENTS.md 思考原則 3): 旧 prop は同 PR で消す。
- `tests/js/architecture/recent-auth-modal-call-site-inventory.test.ts` を新設し、
  既存 `logout-call-site-inventory.test.ts` と**同型の deny-by-default** で固定する。
  「渡し忘れ」だけでなく「**間違った値を渡す**」(`status={undefined}` / 別 shape の即席オブジェクト) も
  落とすため、検査は 4 段:
  1. `RecentAuthModal` を import するファイル集合 == inventory (未登録の呼び出し側を fail)
  2. 各呼び出しが **`status={recentAuthStatus}` を識別子まで完全一致**で渡している (任意式を許さない)
  3. 旧 prop 名 (`passwordSet=` / `availableProviders=` / `canSatisfy=` / `passkeyAvailable=`) を
     `<RecentAuthModal ...>` タグに渡していない
  4. 各呼び出し側が `withRecentAuth` を import し、`onStale` の引数を `recentAuthStatus` に格納している
     (= status の**出所**を `/recent-auth/status` 1 本に固定する。画面ごとの独自判定を作らせない)
- ⚠ 型だけでは強制できない: 本リポジトリの `pnpm typecheck` は `tsc --noEmit` であり
  **`.svelte` テンプレートの props は型検査されない** (svelte-check は未導入)。
  「必須 prop 化」は契約の宣言であって強制ではないため、**強制の実体は inventory gate** に置く。
  svelte-check 導入は別タスク (スコープ外・後述)。

### 施策 2 [High] 回復導線を単一の molecule に集約する (F-2a)

`RecentAuthModal` と `ConfirmRecentAuth` は「再認証手段が無い / この端末では実行できない」の
2 分岐を**別々に持ち、片方だけ旧作法 (`/forgot-password` 直リンク) のまま残った**。
文言と導線を `components/molecules/RecentAuthRecoveryNotice.svelte` に一本化し、両者が composition する。

- 配置が molecule なのは**構造的制約**: `RecentAuthModal` は organism であり、
  atomic-import-graph gate 上 organism は features を import できない (単方向 import)。
- ログアウト導線 (`router.post("/logout")` = **Inertia visit**) はこの molecule が持つ。
  `logout-call-site-inventory` の inventory を molecule へ差し替える
  (ConfirmRecentAuth からは `/logout` リテラルが消える)。既存 gate の 2 つ目の不変条件
  「inventory 登録ファイルに fetch/axios を持ち込まない」は molecule で自然に満たされる
  (モーダル本体に `router.post("/logout")` を置くと fetch と同居して gate に触れる)。
- **経路 C (Inertia history 暗号化 + 履歴鍵破棄) の保証は本変更で変わらない**:
  molecule が Inertia visit で `/logout` を叩き、`LogoutResponse` が `Inertia::clearHistory()` を
  発行する既存経路をそのまま通る。契約は `InertiaHistoryGuardTest` (サーバ側) と
  logout inventory (クライアント側) の 2 枚で従来どおり固定され、
  `docs/supported-browsers.md` の経路 C の呼び出し元記述を同 PR で更新する。

### 施策 3 [High] パスワード**初回設定**経路を新設する (F-2b の根因解消)

**判断: 新設する。** 根拠と設計は「主要な判断」節に詳述。

- サーバ: `POST /settings/password` (`settings.password.store`)。**`recent-auth` middleware で保護**し、
  `RecentAuthRouteTest` の allowlist に登録する。`hasPassword()` が true なら fail-closed で拒否
  (`current_password` 必須の変更経路を迂回させない)。
- **受入条件 (この route が成立するために満たすもの)**:

  | # | 条件 | 担保 |
  |---|---|---|
  | 1 | recent-auth が成立済み | `RequireRecentAuth` middleware (未成立は 302 confirm / XHR は 409 相当の既存契約) |
  | 2 | 成立時刻が TTL 内 | `RecentAuthWindow::isFresh()` (既存。session の `recent_auth_at`) |
  | 3 | 対象 user が現在の session user | `$request->user()` のみを対象にする (payload から user を受け取らない = セキュリティ不変条件 1) |
  | 4 | `hasPassword() === false` を **lock 下で再確認** | Service 内 `DB::transaction` + `User::lockForUpdate()` (新規) |
  | 5 | 監査記録 | `SecurityEventType::PasswordSet` を追加し `SecurityEventRecorder` で記録 (新規) |
  | 6 | 他セッション失効 | `Auth::logoutOtherDevices()` + DB session 行削除 (既存 `UpdateUserPassword` と同一実装を共有) |

- Service の責務分離: `PasswordCredentialService` の公開 API は
  `setInitial(User $user, string $plain): void` と `change(User $user, string $plain): void` の 2 本。
  **`current_password` の検証は Fortify 契約側 (`UpdateUserPassword` の Validator) に残し**、
  Service が共有するのは「hash 保存 → 監査記録 → 他セッション失効 → DB session 行削除」の
  **確定後処理 (private `apply()`)** のみ。transaction は Service 内 (AGENTS.md 実装規約)。
- 画面: `/settings` のパスワードカードを `hasPassword` で出し分ける
  (true = 従来の「パスワード変更」、false = 「パスワードを設定」)。
  現状は password 無しユーザーにも `current_password` 必須フォームが出ており、
  **カード丸ごとが踏破不能** = F-2 と同species。
- `PasskeySection` の CTA は据え置き先 (`/settings`) が**踏破可能になる**ことで解消する。
  あわせて Alert 本文で同一ページ内の代替 (SSO 連携カード / 別パスキー登録) を案内する。
- `LoginMethodRequiredDto.settingsUrl` は**削除**する。根拠:
  `LoginMethodRequiredResource` は `EnsureLoginMethodRemains::reject()` の `expectsJson()` 分岐でのみ返る
  **内部 XHR 専用 contract** で `routes/api.php` には露出していない (公開 API ではない)。
  消費者は 0 件 (`grep settingsUrl resources/js`)、しかも指す先 `settings.security` には
  パスワード設定 UI が無くフロントのハードコード (`/settings`) とも食い違う phantom 契約。
  「正しい URL に直して段階的廃止」は思考原則 3 (後方互換の並走を残さない) に反するため取らない。
  波及: `tests/Feature/Auth/LoginMethodRetentionTest.php:78` の `assertJsonPath('settingsUrl', ...)` を削除。

### 施策 4 [Medium] 同時に閉じる 3 件 (同一ファイルを触るため)

内訳を「必須」と「付随」に分ける。**F-3 は必須** (書き換える 3 ファイルの表示契約そのもの)。
**F-4 / F-7 は同一ファイル内の局所修正**であり、実装が膨らんだ場合は本批から切り離して
次サイクルへ送れる (切り離しても F-1〜F-3 の完結性は損なわれない)。

- **F-3 (提示様式の分裂) — 必須**: 「**非フィールド起因の操作失敗は Alert**」を DESIGN.md に規約化し、
  本批で書き換える 3 ファイル (`PasskeySection` / `RecentAuthModal` / `ConfirmRecentAuth`) を揃える。
  `Login.svelte` は既に Alert で準拠済み = **追加ファイルは 0**。
  併せて `RecentAuthModal` が password エラーと passkey ceremony エラーで
  **同一の `error` state を共有している**バグ (passkey 失敗がパスワード欄のエラーとして出る) を分離する。
- **F-4 (`nameError` が入力に追随しない) — 付随**: DESIGN.md §FormField の canonical 不変条件違反
  (T106 が唯一の逸脱)。`提示開始 boolean + $derived` 形へ書き換える。
- **F-7 (登録フローの細部) — 付随**: `registering` を `onStart`/`onFinish` で握る (連打で ceremony 多重を防ぐ) /
  サーバ validation を FormField へ流す / 拒否 Alert へフォーカス移動。

### スコープから外す (次サイクル送り)

- **F-5 (設定タブナビの molecule 化 + `aria-current`)**: 本批の主題は「踏破可能性の契約」であり、
  F-5 は共通化リファクタ。`ApiKeyTabNav` の汎用化 (改名) は ApiKeys 3 ページとそのテストへ波及し、
  本批の変更面 (認証・再認証) と交差しない。詰みも発生しない (現在地が分かりにくいだけ)。
- **F-6 (Login の非対応ブラウザ caption)**: Login 画面は本批で触らない唯一の passkey 面。
  かつ当該ユーザーにも「パスワードをお忘れの方」= 踏破可能な出口が**同一画面に見えている**ため詰みではない。
- **F-9 (contrast gate の PENDING ペア)**: token 逸脱ゼロの現状に影響しない既知の宣言済み範囲外。
- **svelte-check の導入**: 施策 1 を「型でも」強制できるが、全 `.svelte` の一括型検査は
  既存エラー量が未知で本批の 3 倍規模になりうる。gate で強制できている以上、独立タスクにする。

## 期待効果

- **使命への貢献**: 現場作業者が使う撮影 PWA は「スマホ + パスキー / SSO」が主戦場であり、
  パスワードを持たないユーザーが**本アプリの想定 mainstream**。その母集団が機微操作
  (API キー・組織設定・アカウント削除) で詰む状態は「思考ゼロ」を掲げる製品の前提を壊す。
- **具体的な改善見込み**:
  - passkey-only ユーザーの step-up 成功経路が 1 画面 → 6 画面 (全画面) に回復
  - 踏破不能 CTA 2 本 → 0 本。表示条件と踏破条件が一致する
  - 「配線漏れ」型の回帰が **CI で機械検出**される (現状は多角監査でしか出ない)
  - サーバの拒否文言「パスワードの設定」がアプリの実能力と一致する

## 実装方針（概要）

| 層 | 変更 |
|---|---|
| Svelte components | `RecentAuthModal` (props 契約変更 + エラー分離) / 新 `RecentAuthRecoveryNotice` molecule / `PasskeySection` (Alert 統一・`$derived` 化・登録フロー) / `ConfirmRecentAuth` (molecule 利用・Alert 化) |
| Svelte pages | RecentAuthModal 呼び出し 6 ページの prop 差し替え / `Settings/Index` のパスワードカード分岐 / `Settings/Security` の `hasPassword` 受け渡し |
| Laravel | `PasswordSetupController` + `PasswordCredentialService` (パスワード確定後処理の単一化) / route 追加 / `ProfileController`・`SecurityController` の prop 追加 / `LoginMethodRequiredDto` から `settingsUrl` 削除 / `SecurityEventType::PasswordSet` 追加 |
| gate / テスト | `recent-auth-modal-call-site-inventory.test.ts` 新設 / `logout-call-site-inventory.test.ts` の inventory 更新 / `RecentAuthRouteTest` allowlist 追加 / Feature テスト (初回設定の成功・二重設定拒否・step-up 必須) / JS テスト (モーダル分岐・入力追随・未配線画面の回帰) |
| ドキュメント | DESIGN.md (RecentAuthModal 契約 / 新 molecule / 非フィールド起因エラーの規約) / `docs/supported-browsers.md` (経路 C の logout 呼び出し元) |

## 主要な判断: パスワード設定経路を「新設する」理由

課題文が要求する判断項目に沿って明示する。

### (1) 新設 vs CTA 除去

CTA を踏破可能な先 (SSO 連携 / 別パスキー / ログアウト → リセット) に**差し替えるだけでも詰みは消える**。
それでも新設する理由は 3 点:

1. **踏破不能なのは CTA だけでなく `/settings` のパスワードカード丸ごと**。password 無しユーザーに
   `current_password` 必須フォームを出し続ける限り、押せば必ず失敗する UI が残る。
   これを「隠す」で解決すると、そのユーザーにはパスワードを得る手段が**アプリ内に一切見えなくなる**。
2. **サーバの契約文が既に「パスワードの設定」を約束している** (`EnsureLoginMethodRemains`)。
   文言を実態に合わせて削るか、実態を文言に合わせるかの二択で、
   後者の方が母集団 (SSO/passkey 主体) の増加方向と一致する。
3. **ログアウト経由の回復は「最後の砦」としては正しいが、唯一の手段としては弱い**。
   ログアウト → メール → リセットリンクは、現場スマホ (会社メールを開けない端末) で切れやすい。
   ただし step-up 不能ユーザー (`canSatisfy=false`) には**この経路しか無い**ため、
   施策 2 の molecule で明示的に残す (2 層構成)。

### (2) `current_password` 不要な「初回設定」のセキュリティ保護

**step-up 再認証 (`recent-auth` middleware) を必須にする。**

- 認証手段を**増やす**操作であり `EnsureLoginMethodRemains` (減らす操作の関門) とは逆方向。
  減らす側の不変条件 (「最低 1 手段が残る」) は増加操作では自明に満たされるため、
  同 middleware は付けない (付けると `removalFor()` が fail-closed で例外を投げる設計と衝突する)。
- 増加操作の脅威は「**放置端末・セッション奪取からの永続化**」(攻撃者が自分の知るパスワードを
  設定し、以後 passkey 無しで入れるようにする)。これは step-up が直接に潰す脅威であり、
  API キー発行・オーナー移譲と同機微度。よって `recent-auth` allowlist に登録する。
- `hasPassword()` が true の場合は **fail-closed で拒否**し、`current_password` 必須の変更経路へ誘導する
  (初回設定 route を「current_password を省略できる変更 route」に転用させない)。
  判定は対象 User 行の `lockForUpdate()` 下で行う (同時 2 リクエストの TOCTOU 回避。
  `EnsureLoginMethodRemains` と同じ作法)。
- パスワード確定後の後処理 (他デバイスのセッション失効・監査記録) は
  既存の `UpdateUserPassword` と**同一実装を共有**する (2 箇所に書くと片方だけ劣化する)。

### (3) SSO のみ / passkey のみユーザーの回復シナリオ

| 母集団 | step-up 可否 | 本設計での経路 |
|---|---|---|
| passkey あり (対応ブラウザ) | 可 (passkey satisfier) | **アプリ内でパスワード設定** (施策 3)。施策 1 で全 6 画面のモーダルが機能する |
| passkey あり (非対応ブラウザ) | 不可 | `RecentAuthRecoveryNotice` が「対応端末で開き直す or ログアウト → リセット」を提示 |
| passkey 端末を紛失 (セッションのみ生存) | 不可 (ceremony が失敗) | 同上。ログアウト → guest としてリセット (`RecentAuthPasswordRecoveryTest` が端まで固定済み) |
| SSO のみ (step-up 可能 provider) | 可 | 再 SSO で step-up → アプリ内でパスワード設定 |
| SSO のみ (identity_only provider) | 不可 (`canSatisfy=false`) | `RecentAuthRecoveryNotice` のログアウト経路 |

### (4) AGENTS.md 禁止事項 8 / 監査の Don't の両立

- **disabled にしない**: 新設する「パスワードを設定」ボタンは常時活性で、
  失敗理由 (強度不足・step-up 未成立) は押下後に提示する。
- **表示条件と踏破条件を一致させる**: `hasPassword=false` のときだけ初回設定フォームを出し、
  `hasPassword=true` のときだけ変更フォームを出す。CTA は「その状態のユーザーが実際に踏める先」だけを指す。
  step-up が成立しない端末では、モーダルが**手段を出さない代わりに必ず回復導線を出す**
  (無表示の行き止まりを作らない)。

## 制約・前提

- `resources/js` は TypeScript 必須 (禁止事項 7)。T102 の eslint `noInlineConfig` により
  inline `eslint-disable` 不可。svelte `no-undef` は error。
- `pnpm typecheck` = `tsc --noEmit` (svelte テンプレートは型検査対象外) → **強制は gate で行う**。
- DESIGN.md の token 体系・Atomic Design の単方向 import を維持する
  (hex 直書き 0 件・`ds-purity` / `typography-invariant` / `shape-ramp-purity` / `contrast-invariant` 維持)。
- 新 gate は既存 `logout-call-site-inventory.test.ts` の作法 (deny-by-default + 理由 docblock +
  既知の限界の明記) を踏襲する。
- 検証は `composer test` (2865 passed) / `pnpm test` (1130 passed) を起点に、
  T099 のグローバルロック経由で実行する。

## スコープ外

- F-5 (設定タブナビの molecule 化 / `aria-current`)、F-6 (Login の非対応 caption)、
  F-9 (contrast gate の PENDING ペア解消) — いずれも詰みを生まない既存負債。次サイクル送り。
- svelte-check の導入 (施策 1 の型強制を「宣言」から「検査」へ上げる後続タスク)。
- パスワード設定時のユーザー宛メール通知 (security notification)。監査記録
  (`SecurityEventType::PasswordSet`) は本批で入れる。
- `page-shell-structure.test.ts` の「踏破可能な離脱導線」検査をモーダル/Alert へ拡張する案
  (監査 TODO #2 の後半)。CTA の href 到達可能性を静的に判定する一般解が無く、
  本批は「回復導線を単一 molecule に集約する」ことで同じ再発を構造的に防ぐ。

