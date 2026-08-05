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
    `fetchRecentAuthStatus()` は **strict parse により検証済みの値だけ**を `RecentAuthStatus` として返す
    (詳細は施策 1-b)。サーバ側の contract (DTO / Resource / JSON shape) 自体は本批で変えない。
  - `availableProviders` は `AvailableReauthProvider { provider, capability, reauthUrl }` の配列で、
    **サーバが step-up satisfier 可能な provider だけを載せる**
    (`ConfirmRecentAuthController::buildStatus()` が `ProviderCapability::isStepUpSatisfier()` で絞る)。
    したがってクライアント側に `canStepUp` 相当の分岐フラグは置かない
    (「載っている = 使える」が不変条件。クライアントに判定を持たせると二重管理になる)。
- **後方互換の並走を残さない** (AGENTS.md 思考原則 3): 旧 prop は同 PR で消す。
- **`status` は `RecentAuthStatus | null` (nullable) のままにし、component を入力に対し全域にする**。
  非 nullable にして呼び出し側で `{#if}` 出し分けする案は採らない: `bind:open` は component が
  mount されていないと `open=false` に戻せず、「open=true なのに何も描画されない」という
  **本批で潰そうとしているのと同じ species の無言の行き止まり**を 6 画面ぶん新規に作る。
  代わりに null は component 内で明示的な分岐にする —
  「再認証の状態を取得できませんでした。」+ **実際に押せる「再読み込み」ボタン** (`router.reload()`)
  + キャンセル (空表示にも、事実に反する「非対応」文言にもしない。
  文言だけの案内は「回復導線」と呼べないため、押せる操作にする)。
  なお施策 1-b により、通常経路で null が入ることは無い
  (取得失敗・契約不成立は `withRecentAuth` が delegated として扱いモーダルを開かない)。
  この分岐は「呼び出し側の実装ミス」に対する最後の安全網であり、JS テストで固定する
  (初期 null / 取得失敗 / 取得後に手段が出る、の 3 ケース)。
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

#### 施策 1-b: 通信境界でも「既定値による黙った補完」をやめる

call-site を固めても、**サーバ contract 側が欠けたときに同じ回帰が再現する**。
現行 `fetchRecentAuthStatus()` は `body.passkeyAvailable ?? false` の形で欠損を既定値に落とすため、
Resource から field が消えても TS 上は正常な `RecentAuthStatus` になり、gate も素通りする
(= 「能力はあるがボタンが出ない」の再演)。よって:

- **strict parse にする**: 応答の全 field を型検査し、欠損・型不一致は**契約不成立**として `null` を返す。
  検査対象は top-level 全 field に加え、**`availableProviders` が配列であること**と
  **各要素の `provider` / `capability` / `reauthUrl` の存在と型**まで含める
  (要素の欠落は「SSO ボタンが出ない」= 今回と同じ species の詰みになるため)。
  `withRecentAuth` は `null` を既に `delegated` として扱い、**モーダルを開かず元の操作をそのまま実行**して
  サーバの最終ゲート (`RequireRecentAuth`) に判定を預ける。
  黙って劣化した UI を出すより「サーバに預ける」方が安全側で、既存の delegated 経路をそのまま使える。
- **contract テストを 2 枚置く**:
  (i) Feature: `/recent-auth/status` の JSON キー集合と各値の型を固定 (`RecentAuthStatusResource` 側。
      provider 要素のキーも含む)、
  (ii) JS: 各 field (top-level / provider 要素) を 1 つずつ欠落・型不一致にした応答で
      `fetchRecentAuthStatus()` が `null` を返すこと、`availableProviders` が非配列なら `null` を返すこと。
  両者が「PHP Resource の shape ↔ TS `RecentAuthStatus`」の対応を機械的に噛み合わせる。

#### 施策 1-c: delegated の着地を実装事実に合わせて閉じる

delegated (precheck 失敗 / 契約不成立) は「元の操作をそのまま送る」ため、**鮮度切れなら
サーバが 409 + `RecentAuthRequiredResource` (`code: "recent_auth_required"`, `redirect`) を返す**
(Inertia の非 GET / `expectsJson()` の契約。通常遷移の 302 + confirm は非 XHR・GET 経路の話であり、
両者を混同しない)。ところが**この 409 を受け取って `redirect` へ遷移するクライアントが現状どこにも無い**
(`grep recent_auth_required resources/js` = 0 件)。`withRecentAuth` は
「再認証が必要な場合は確認ページへ移動します。」と toast で予告しているのに、実際には誰も移動させない
= **無言失敗**が残っている。strict parse 化は delegated への流入を増やすため、同批で閉じる。

- 方式は **`router.on("invalid", handler)` に確定**する (Inertia が非 Inertia 応答を受けたときのイベント)。
  汎用 axios interceptor は Inertia 内部通信への配線が保証されないため採らない。
- ハンドラは `resources/js/lib/recent-auth.ts` に置き、**アプリ初期化 1 箇所で 1 回だけ登録**する
  (画面ごとのハンドラを作らない = 施策 1 と同じ原則。登録が 1 回であることもテストする)。
- **fail-closed の受入条件** (すべて満たしたときだけ `event.preventDefault()` +
  `router.visit(redirect)`。1 つでも欠けたら preventDefault せず **Inertia 既定処理へ渡す**):
  1. `response.status === 409`
  2. body の `code` が `"recent_auth_required"` に**厳格一致**
     (他の 409 契約 `scenario_conflict` / `two_factor_required` 等を誤食しない)
  3. `redirect` が string である
  4. `redirect` が **same-origin** に解決され、その pathname が
     **recent-auth confirm の既知 path と一致**する
     (グローバルなナビゲーション境界なので、サーバ由来 URL をそのまま信用しない。
      外部 URL / 別 route への誘導を構造的に不能にする)
- テスト: malformed status → delegated → 409 → confirm 画面へ visit する一連に加え、
  外部 URL / 別 route / `redirect` 欠損 / 他の 409 code / 409 以外 の各ケースで
  **遷移せず既定処理に渡す**ことを固定する。
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
  | 1 | recent-auth が成立済み | `RequireRecentAuth` middleware。未成立時の応答は既存契約どおり **Inertia の非 GET / `expectsJson()` は 409 + `RecentAuthRequiredResource` (`{code, message, redirect}`, `Cache-Control: no-store`)**、通常遷移は 302 + `url.intended` 保持。本 route は Inertia POST なので前者に該当し、クライアントは他の機微操作と同じく `guardWithRecentAuth` の precheck で 409 に到達しないようにする (到達した場合の扱いは既存の機微操作 route と同一) |
  | 2 | 成立時刻が TTL 内 | `RecentAuthWindow::isFresh()` (既存。session の `recent_auth_at`) |
  | 3 | 対象 user が現在の session user | `$request->user()` のみを対象にする (payload から user を受け取らない = セキュリティ不変条件 1) |
  | 4 | `hasPassword() === false` を **lock 下で再確認** | Service 内 `DB::transaction` + `User::lockForUpdate()` (新規) |
  | 5 | 監査記録 | `SecurityEventType::PasswordSet` を追加し `SecurityEventRecorder` で記録 (新規) |
  | 6 | 他セッション失効 | `Auth::logoutOtherDevices()` + DB session 行削除 (既存 `UpdateUserPassword` と同一実装を共有) |

- Service の責務分離: `PasswordCredentialService` の公開 API は
  `setInitial(User $user, string $plain): void` と `change(User $user, string $plain): void` の 2 本。
  **`current_password` の検証は Fortify 契約側 (`UpdateUserPassword` の Validator) に残し**、
  Service が共有するのは「hash 保存 → 監査記録 → 他セッション失効 → DB session 行削除」の
  **確定後処理 (private `apply()`)** のみ。
  - **transaction 境界は公開 API の 2 本**が持つ (AGENTS.md 実装規約「transaction は Service 内」)。
    `apply()` は「**transaction 内でのみ呼ばれる private 処理**」であり、自分では transaction を開かない。
  - `setInitial()` は `DB::transaction` 内で `User::query()->whereKey(...)->lockForUpdate()->firstOrFail()`
    を取り、**ロック取得後に `hasPassword()===false` を再確認**し、
    **その locked インスタンスを `apply()` へ渡す** (再取得しない = ロック外の実体を触らない)。
  - DB session 行の削除は**現在セッション ID を除外**する (既存 `UpdateUserPassword` の
    `deleteOtherSessionRecords()` と同一実装を移設。session driver=database 時のみ・best-effort)。
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
| Svelte lib | `lib/recent-auth.ts` (strict parse 化 = 施策 1-b / 409 `recent_auth_required` 単一ハンドラ = 施策 1-c) |
| Svelte components | `RecentAuthModal` (props 契約変更 + エラー分離 + null 分岐) / 新 `RecentAuthRecoveryNotice` molecule / `PasskeySection` (Alert 統一・`$derived` 化・登録フロー) / `ConfirmRecentAuth` (molecule 利用・Alert 化) |
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
