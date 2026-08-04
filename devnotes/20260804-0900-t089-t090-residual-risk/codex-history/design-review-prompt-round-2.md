# 詳細設計レビュー Round 2

Round 1 の指摘 (Critical 1 / Warning 5 / Suggestion 5) への対応マトリクスと、修正後の詳細設計 (全文) を送る。
2 点だけ**反論**しているので (施策 1 の非回帰テスト新設 / 施策 4 の `>` vs `>=`)、根拠が成立しているか判定してほしい。
残る Critical / Warning が無ければ APPROVED を返してほしい。

---

## Claude 側の対応マトリクス

# 対応マトリクス: design-review Round 1

Codex 判定: CHANGES_REQUESTED（施策 1 / 4 が REQUEST_CHANGES、他 6 施策は APPROVE）
[Critical] 1 / [Warning] 5 / [Suggestion] 5

## [Critical] 施策 4: `exceededLabels` の promoted property が `array` のままだと L10 で shape が崩れる

- 判断: **対応する**
- 根拠: 妥当。`@phpstan-type QuotaStatusShape` は `list<string>` を宣言しているのに、
  promoted property の宣言型は `array` なので、PHPStan は `array<int|string, mixed>` に
  広げて `toArray()` の戻り値が shape と一致しない可能性がある。
- 対応内容: promoted property に `/** @var list<string> */` を付け、
  コンストラクタ引数の `@param list<string>` と揃える。
  `build()` の `$exceeded` は `array_values()` で正規化してから渡す（append のみだが、
  将来 `unset`/フィルタが入っても list 契約が崩れないようにする）。

## [Warning] 施策 1-a: 既存 render callback 群の非回帰テストが不足

- 判断: **一部反論する / 一部対応する**
- 根拠 (反論部分): 追加する callback は
  (1) 第 1 引数の型が `AuthenticationException` なので `firstClosureParameterTypes` により
      **他の例外では一切呼ばれない**、
  (2) 常に `null` を返すので `renderViaCallbacks` は必ず次の callback へ進む
  — の 2 点により、`QuotaExceededException` / `InsufficientTicketsException` /
  catch-all / `respond()` の挙動を**構造的に変えられない**。
  それらの 3 面 (web / expectsJson / api/*) 回帰テストを新設するのは、
  変更していない経路に対するテストの水増しであり思考原則 2 に反する
  (既存テストが既にそれぞれの経路を固定している)。
- 対応内容 (対応部分): ただし**実在する 1 つの干渉点**は固定する価値がある。
  `api/*` の `AuthenticationException` は catch-all の `ApiExceptionRenderer` が JSON 化するが、
  `Accept: application/json` を伴わない `api/*` リクエストでは `expectsJson()` が偽になりうる。
  そのため guard の第 1 条件 `$request->is('api/*')` が効いていることを
  **T4b** として追加する（`api/*` の 401 でフラグが積まれないこと）。

## [Warning] 施策 1-b: T5 は Filament 実装差分に依存して不安定

- 判断: **対応する（分離する）**
- 根拠: 妥当。Filament の `Authenticate` は override で `unauthenticated()` を呼ぶが、
  将来 `abort(403)` などに変わると本テストが落ちる。契約の本体を 3rd party に依存させない。
- 対応内容: T5 を 2 本に分ける。
  - **T5（契約・素の `auth` route）**: 認証失敗で積まれたフラグは
    次の Inertia 応答で **1 度だけ**消費される（one-shot）。Filament に依存しない。
  - **T6（補助スモーク・`/admin`）**: 非 Inertia 面の認証失敗でもフラグが積まれる
    （docblock が主張する「安全側の偽陽性」の裏付け）。
    **テスト名とコメントに「Filament の挙動が変わったら docblock と一緒に更新する」**と書き、
    契約テストではなく文書の裏付けであることを明示する。

## [Warning] 施策 4-a: 超過判定を `>` にすると「上限ちょうどで新規作成不可」が警告に出ない

- 判断: **反論する**
- 根拠:
  1. `QuotaService::check()` は `current >= limit` で拒否するため、`>=` を警告条件にすると
     **starter / personal（`max_projects = 1`）の全組織が、プロジェクトを 1 つ作った時点から
     恒常的に警告を表示する**ことになる。それはプランの設計どおりの正常状態であり、
     警告の意味が失われる（本当の超過が埋もれる）。
  2. 「失敗前の気づき」は**警告ではなく使用量表示**が担う。本設計では quota カードを
     `1 / 1` 形式に変えるため、上限到達は常に読み取れる（現状は上限のみで読み取れない）。
  3. 失敗した瞬間の気づきは施策 4-3（`QuotaExceededException` の文言に回復先を追加）が担う。
  4. `atLimit` を第 3 の表示状態として増やすのは UI 状態の水増し（思考原則 2）。
- 対応内容: `>` を維持する。ただし**この判断を DTO の docblock に理由付きで固定**し
  （既に記載済み）、テストで「上限ちょうどでは `exceededLabels` が空」を
  **回帰防止として明示的に固定**する（既に T 計画に含む）。

## [Warning] 施策 4-b: DTO rename の波及漏れリスク

- 判断: **対応する**
- 根拠: 妥当。rename は typecheck / phpstan で大半は捕まるが、
  Inertia props は連想配列で渡るため**キー名の取りこぼしは静的解析で捕まらない**。
- 対応内容: `tests/Feature/Billing/BillingQuotaStatusTest.php` に
  「`/billing` の `page.quotas` が **6 キー厳密一致**であること」を assert するテストを追加する
  （`maxProjects` / `maxMembers` / `maxStorageGb` / `projectsUsed` / `storageUsedBytes` /
  `exceededLabels`）。TS 側は `pnpm typecheck` が `QuotaStatusShape` との対を保証する。

## [Warning] 施策 8: `tests/Architecture` に DB seed 依存テストを増やすと不安定

- 判断: **対応する（配置を変える）**
- 根拠: 妥当かつ、リポジトリの規約に反していた。`tests/Pest.php` のコメントは
  「**Architecture はファイル走査中心のため DB を使わない (TestCase のみ)**」と明記しており、
  `RefreshDatabase` は Feature / Unit にしか適用されない。
  Architecture に `Plan::query()` を置くと DB 前提が揃わない。
- 対応内容: テストを **`tests/Feature/Billing/PlanQuotaCoverageTest.php`（新規）** へ移す。
  `tests/Architecture/QuotaKeyConfigInvariantTest.php` は現状のまま（config 走査のみ）に保つ。

## [Suggestion] 群

- 施策 2「docs に実装参照点を併記」: **対応する**（`bootstrap/app.php` /
  `LogoutResponse` / `bfcache-guard.ts` の 3 点を経路 C の表に明記）。
- 施策 3「再検討条件に追跡先を紐づける」: **対応する**（TODO ID は本フェーズの責務外なので、
  代わりに本 devnotes ディレクトリを参照先として書く）。
- 施策 5「再開放前提の検証責務を 1 行」: **対応する**
  （`billing:ensure-portal-configuration --verify` が現状 `subscription_update` しか
  検証していないこと = 再開放時は verify の拡張も必須、と書く）。
- 施策 6「enum 側に意図コメントを寄せる」: **見送る**。`PlanCode::requiresStripeCheckout()` には
  既に「Personal は free、Enterprise は問い合わせ営業」という意図コメントがある。
  テスト側の期待値コメントと二重管理になる。


---

## 修正後の詳細設計 (全文)

# 詳細設計: T089 / T090 の残存リスク確定 (t089-t090-residual-risk)

## 使命・制約（絶対遵守）

### アプリの使命（North Star）

**AI-CUE** は、現場に既にある**作業手順書(SOP)を起点に**、AI が撮るべきカットを設計した**動画シナリオ**を生成し、そのシナリオを**スマホ(PWA)でナビゲーション撮影**することで、専門知識ゼロの現場作業者でも**標準化されたマニュアル動画**を作れるようにする。

- 「思考ゼロ・編集ゼロ」— 台本作成・撮影判断・編集の 3 ハードルを AI とナビ撮影で肩代わりする。
- 競合(OJT を撮って形式化する tebiki)と異なり、**標準作業を起点に AI が教材設計し撮影を指示する**（撮影者・教える人のスキルに品質を依存させない）。
- 熟練者の暗黙知を動画マニュアルという形式知へ変換する装置（SECI）。

> **v1 スコープ**: 字幕のみ(TTS 後回し) / 撮影は PWA(同一オリジン・セッション認証) / 動画合成は自前 ffmpeg / 単一 Default Project。

### 禁止事項

1. テストなしの実装完了報告(不変条件は対応する Architecture/Feature テストへの登録まで含めて「実装済み」)
2. PHPStan エラーの widen(型を緩めて黙らせる)・baseline 化
3. dev DB への破壊操作(`migrate:fresh` 等)をエージェント判断で実行すること
4. `response()->json()` の直書き(DTO / JsonResource / Inertia を使う。仕様固定 endpoint のみ例外)
5. LLM 呼び出しの Prism 直呼び(`app/Prompts/` の factory 経由のみ)
6. prompt 文字列のコード直書き(`resources/prompts/*.yaml` に置く)
7. 操作系 POST の応答での `redirect()->intended()`
8. 必須条件未充足を理由にボタンを disabled にする UI(押下時にエラー表示する。DESIGN.md)
9. Artifact の使用(成果物はリポジトリ内のファイルとして出力する)

### コーディングルール

- **PHPStan level 10** 必須（`composer phpstan`）
- **Pest**（`composer test`）／**RefreshDatabase** グローバル適用 + `--parallel`（個別 `DatabaseTransactions` 禁止）
- **テストデータは必ず Factory で生成**（`Model::create()` 手組み禁止）。ただし
  **参照データ (Plan / PlanPrice) は Seeder が真実源**であり Factory を作らない（施策 6 の判断）
- **DTO + JsonResource** パターン／アーリーリターン推奨
- `composer fix`（Pint）/ `pnpm lint:fix`
- PHP 8.4 + Laravel 12 + Svelte 5 + Inertia.js + TypeScript

## 概念設計リファレンス

`devnotes/20260804-0900-t089-t090-residual-risk/conceptual-design.md`（Codex 概念設計レビュー Round 3 で APPROVED）

---

## 施策一覧

| # | 施策名 | 変更ファイル | 優先度 |
|---|--------|------------|--------|
| 1 | T089-b: 認証失敗を契機に Inertia 履歴鍵を破棄する | `bootstrap/app.php` | 高 |
| 2 | T089-a / T089-b の決定を恒久ドキュメントへ固定 | `docs/supported-browsers.md` / `app/Http/Responses/Fortify/LogoutResponse.php` / `resources/js/lib/bfcache-guard.ts` / `AGENTS.md` | 高 |
| 3 | T090-a: proration 方針の確定と切替見積り | `docs/architecture.md` / `app/Services/Billing/CashierStripeGateway.php` | 中 |
| 4 | T090-b: 上限超過の可視化と失敗地点からの誘導 | `QuotaLimitsDto`→`QuotaStatusDto` / `BillingDashboardDto` / `BillingController` / `QuotaExceededException` / `resources/js/types/billing.ts` / `resources/js/lib/format-bytes.ts` / `pages/Billing/Index.svelte` / `pages/Dashboard.svelte` / `pages/Billing/Plans.svelte` / `config/quota.php` / `app/Enums/QuotaKey.php` | 中 |
| 5 | T090-c: Portal 方針の確定（再開放要件の明文化） | `app/Services/Billing/PortalConfigurationSpec.php` | 低 |
| 6 | T090-d: `PlanCode` 写像テストと Factory 非作成の明文化 | `tests/Unit/Enums/PlanCodeTest.php` / `docs/factories.md` | 中 |
| 7 | 乖離台帳 A-6 の陳腐化解消 | `devnotes/20260717-0035-aigenba-billing-parity/aigenba-divergence-ledger.md` | 低 |
| 8 | quota 定義欠落の機械的追跡 | `tests/Feature/Billing/PlanQuotaCoverageTest.php`（新規） | 中 |

---

## 施策 1: T089-b — 認証失敗を契機に Inertia 履歴鍵を破棄する

### 変更箇所

- ファイル: `bootstrap/app.php` の `->withExceptions(function (Exceptions $exceptions): void { ... })` 冒頭
  （現状 L178 付近の `QuotaExceededException` の render callback より **前**）
- import 追加: `use Illuminate\Auth\AuthenticationException;` / `use Inertia\Inertia;`
  （`Illuminate\Http\Request` / `Symfony\Component\HttpFoundation\Response` は既存 import）

### 波及変更

- TypeScript 型定義: **なし**（クライアントコードは 1 行も書かない。Inertia が `clearHistory: true` を
  受け取って `history.clear()` する既存経路をそのまま使う）
- API Resource/DTO: **なし**
- テストファイル: `tests/Feature/Security/InertiaHistoryGuardTest.php` に 7 テスト追加（T1〜T6 + T4b）
- Browser テスト: `tests/Browser/InertiaHistoryRestoreAfterLogoutTest.php` に 1 シナリオ追加

### 現行コード

```php
->withExceptions(function (Exceptions $exceptions): void {
    // api/* は Accept ヘッダに依らず常に JSON envelope を返す。(略)
    $exceptions->shouldRenderJsonWhen(
        fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
    );

    // 課金系のドメイン例外は web では back + error flash に変換する
    $exceptions->render(function (QuotaExceededException $exception, Request $request) {
        // ...
    });
```

### 変更後コード

```php
->withExceptions(function (Exceptions $exceptions): void {
    // api/* は Accept ヘッダに依らず常に JSON envelope を返す。(略・変更なし)
    $exceptions->shouldRenderJsonWhen(
        fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
    );

    /*
     | セッション終了を検知した契機で Inertia の履歴暗号鍵を捨てさせる (経路 C の拡張)。
     |
     | ログアウト (LogoutResponse) は「利用者が明示的に終わらせた」契機しか拾えない。
     | セッション期限切れと、パスワード変更による他デバイスの強制ログアウト
     | (Auth::logoutOtherDevices → web グループの AuthenticateSession) は、どちらも
     | AuthenticationException として現れる。ここでフラグを積むと、着地の /login
     | (Inertia 応答) が消費し、そのタブの sessionStorage の履歴鍵が消える。
     | = **認証失敗を契機に、以後の「戻る」による復元を無効化する**
     |   (過去に遡って無効化するのではない。docs/supported-browsers.md が正本)。
     |
     | 応答自体は既定の unauthenticated() 処理に委ねる (**null を返して素通し**)。
     | Handler::render() は renderViaCallbacks() を AuthenticationException の既定分岐より
     | 先に呼び、callback が null を返せば既定処理へ進む (Laravel 12 実装)。
     | この「null で素通し」に依存するため、**Laravel の major 更新時に再確認する**。
     |
     | 積まない条件は 2 つだけ:
     |   - api/* または expectsJson(): Inertia 応答が返らないためフラグが宙に浮く
     |   - session 不在: そもそもフラグを置けない
     | guards() では面を判別しない (web の auth は [null]、AuthenticateSession は ['web']、
     | Filament の Authenticate は [] になり、Filament の実装詳細に依存するため)。
     | その結果 /admin の認証失敗でもフラグは積まれるが、**安全側の偽陽性として許容**する
     | (影響は Inertia 面の履歴が 1 度だけ再キーされることだけ。テストで仕様として固定する)。
     */
    $exceptions->render(function (AuthenticationException $exception, Request $request): ?Response {
        if ($request->is('api/*') || $request->expectsJson() || ! $request->hasSession()) {
            return null;
        }

        Inertia::clearHistory();

        return null;
    });

    // 課金系のドメイン例外は web では back + error flash に変換する
    $exceptions->render(function (QuotaExceededException $exception, Request $request) {
        // ... 既存のまま
    });
```

### 設計上の根拠（実装者が迷わないための事実）

| 事実 | 出典 |
|---|---|
| `Inertia::clearHistory()` は `session([SessionKey::CLEAR_HISTORY => true])`（flash ではない永続 put） | `vendor/inertiajs/inertia-laravel/src/ResponseFactory.php:182-185` |
| 消費は次の Inertia 応答の `session()->pull(...)` | `vendor/inertiajs/inertia-laravel/src/Response.php:111` |
| ログイン画面は Inertia 応答 | `app/Providers/FortifyServiceProvider.php:190` |
| 例外レンダリング時点で session は生きている（`Routing\Pipeline::handleException` は middleware パイプラインの内側で走る） | `vendor/laravel/framework/src/Illuminate/Routing/Pipeline.php:40-47` |
| 強制ログアウトは `AuthenticateSession::logout()` が `session()->flush()` 後に `AuthenticationException` を投げる（flush はデータ削除のみ。以後の put は成立する） | `vendor/laravel/framework/src/Illuminate/Session/Middleware/AuthenticateSession.php:127-135` |
| リダイレクト境界を跨ぐ挙動は本リポジトリで稼働中（T089 の `LogoutResponse` と `InertiaHistoryGuardTest`） | `tests/Feature/Security/InertiaHistoryGuardTest.php:63-86,135-145` |

### PHPStan適合チェック

- [x] 戻り値の型が明示されている（`?Response`）
- [x] null 安全（分岐は全て早期 return）
- [x] DTO を返している（該当なし。副作用のみで応答は既定処理）
- [x] Generics の型パラメータが正しい（該当なし）
- [x] `$exception` は型マッチ用に必要（`firstClosureParameterTypes` が第 1 引数の型を見る）

### テスト計画

`tests/Feature/Security/InertiaHistoryGuardTest.php` に追加（既存の `inertiaPagePayload()` ヘルパを使う）:

- [ ] **T1**（正）`'セッション未確立の認証失敗でも、着地の Inertia 応答に clearHistory が載る'`
  - `$this->get('/dashboard')` → `assertRedirect(route('login'))`（**302 を自動追従させない**）
  - **別リクエスト**で `$this->get(route('login'))` → `toHaveKey('clearHistory', true)`
- [ ] **T2**（正・強制ログアウト）`'他デバイスからの強制ログアウト (AuthenticateSession) で clearHistory が積まれる'`
  - 既存 `tests/Feature/Auth/PasswordUpdateSessionInvalidationTest.php:83-90` と同じ再現手順:
    `User::factory()->create()` → `$oldHash = $user->getAuthPassword()` →
    `$user->forceFill(['password' => Hash::make(...)])->save()` →
    `$this->actingAs($user)->withSession(['password_hash_web' => $oldHash])->get('/dashboard')`
    → `assertRedirect('/login')` → 別リクエストで `/login` の payload に `clearHistory: true`
- [ ] **T3**（負）`'guest が /login を直接開いてもフラグは積まれない'`
  - `$this->get(route('login'))` の payload に `clearHistory` が無いこと
    （匿名回遊の履歴を毎回捨てない = 却下した代案の回帰防止）
- [ ] **T4**（負）`'expectsJson の 401 ではフラグを積まない'`
  - `$this->getJson('/dashboard')->assertUnauthorized()` の後、`$this->get(route('home'))` の
    payload に `clearHistory` が無いこと
- [ ] **T4b**（負・干渉点）`'api/* の 401 ではフラグを積まない'`
  - `api/*` の `AuthenticationException` は catch-all の `ApiExceptionRenderer` が JSON 化するが、
    `Accept: application/json` を伴わない `api/*` では `expectsJson()` が偽になりうる。
    guard の第 1 条件 `$request->is('api/*')` が効いていることを固定する
  - 認証必須の `api/v1/*` route を Accept 無しで叩いた後、`$this->get(route('home'))` の
    payload に `clearHistory` が無いこと
- [ ] **T5**（契約・one-shot）`'認証失敗で積まれたフラグは次の Inertia 応答で 1 度だけ消費される'`
  - **素の `auth` 保護 route**（`/dashboard`）で発生させる。Filament に依存しない
  - guest で `$this->get('/dashboard')` → `$this->get(route('home'))` に `clearHistory: true`
    → **もう一度** `route('home')` を叩くと載らない
- [ ] **T6**（補助スモーク・文書の裏付け）`'非 Inertia 面 (/admin) の認証失敗でもフラグは積まれる (安全側の偽陽性)'`
  - `$this->get(AdminPanelPath::resolve())` の後、`$this->get(route('home'))` に `clearHistory: true`
  - **契約テストではなく docblock の主張の裏付け**であることをテスト内コメントに明記し、
    「Filament の認証失敗の実装が変わったら本テストと docblock を**一緒に**更新する」と書く
- [ ] 既存 5 テストは無改変で green のままであること（負のコントロール
      `'通常の応答には clearHistory が載らない'` を壊さない）
- [ ] **既存 render callback 群の非回帰テストは新設しない**（追加 callback は
      第 1 引数の型が `AuthenticationException` のため他の例外では呼ばれず、
      常に `null` を返すため `renderViaCallbacks` の後続処理を構造的に変えられない。
      唯一の実在干渉点は T4b で固定する）

`tests/Browser/InertiaHistoryRestoreAfterLogoutTest.php` に追加（Chromium + WebKit 両レーン）:

- [ ] **B1** `'JSON 204 ログアウト後に認証済み画面へ visit すると /login に落ち、戻っても PII が出ない'`
  - 既存 `tests/Browser/AuthenticatedPageBfcacheTest.php` の `bfcacheLogoutInBrowser()` と同型の
    `fetch('/logout', { headers: { Accept: 'application/json' } })` で **鍵を残したまま**ログアウト
  - その後 Inertia visit（認証済み route）→ `/login` 着地 → `back()` → PII が **一度も DOM に出ない**
  - 正のコントロール: 204 直後（visit 前）は `window.sessionStorage.getItem('historyKey') !== null`、
    `/login` 着地後は `null` になっていること（鍵が実際に消えたことを直接観測する）

### リスク

- **フラグの宙吊り**: `expectsJson()` でない非 Inertia 着地（例: 401 `noContent`）が起きると
  フラグが後続の無関係な Inertia 応答で消費される。実運用では Fortify が login へ
  redirect するため発生しないが、発生しても影響は「履歴が 1 度再キーされる」のみ。
  T5 (one-shot 消費) と T6 (非 Inertia 面での偽陽性) がこの挙動を仕様として固定する。
- **Laravel major 更新**で `renderViaCallbacks` の順序 / null 素通しが変わると副作用が消える。
  T1 が落ちるため検出できる（コメントにも再確認条件として明記）。
- **匿名ユーザーの UX**: guest が認証済み route を踏んだ場合のみ履歴が再キーされる。
  影響は当該ブラウザセッションの過去エントリの戻るがサーバ再取得になること。T3 が
  「無条件に捨てる」実装へのドリフトを防ぐ。

---

## 施策 2: T089-a / T089-b の決定を恒久ドキュメントへ固定

### 変更箇所

1. `docs/supported-browsers.md`
   - 経路 C の表の「担当」列に **認証失敗契機**（`bootstrap/app.php` の
     `AuthenticationException` render callback）を追記。
     経路 B / C の行には**実装参照点を明記**する
     （`bootstrap/app.php` / `app/Http/Responses/Fortify/LogoutResponse.php` /
     `resources/js/lib/bfcache-guard.ts`）— 将来の差分レビューで担当実装を辿れるようにする
   - 「未対応事項」の 3 項目を書き換え（下記）
2. `app/Http/Responses/Fortify/LogoutResponse.php` docblock
   - 「clearHistory の発行契機はログアウトだけではない」ことと、
     204 経路の残存リスクが **次の認証済み Inertia visit で解消する**ことを追記
3. `resources/js/lib/bfcache-guard.ts` docblock
   - 「popstate に本 guard のプローブを接続しない」**理由**を追記
     （現状は「二重実装になる」だけ。**却下の主理由は「目的を達しないこと」と
     「通常の戻るを毎回ネットワーク往復＋秘匿オーバーレイで塞ぐこと」**）
   - **docblock のみの変更 = `docs/supported-browsers.md` の実機受入確認トリガに当たらない**
     ことを明記する（挙動変更ではない）
4. `AGENTS.md` ドメイン固有規約 #3
   - (C) の担当実装に認証失敗契機を **1 句だけ**追記。理由・代案・再検討条件は書かない

### 波及変更

- TypeScript 型定義 / DTO / テスト: **なし**（すべてコメント・文書。挙動を変えない）

### `docs/supported-browsers.md`「未対応事項」の書き換え（設計文言）

**別タブ（T089-a）— 判断済みの受容へ格上げ**

> - **別タブに残る Inertia 履歴は保証外（判断済みで受容する）**。
>   `sessionStorage` はタブ単位のため、`clearHistory` を適用していないタブの履歴は復号できる。
>   **塞がない理由**（「自前機構だから」ではない）:
>   (1) 鍵だけ捨てても**そのタブが今表示している PII は消えない**ため効果が薄い、
>   (2) 効果を出すには別タブの document を落とす必要があり、
>       presigned アップロード中の撮影成果（再ログイン後に finalize 可能）を破棄する、
>   (3) 認証失敗契機の `clearHistory`（下記）により、別タブも**次にサーバと話した時点で**鍵を失う。
>   運用上の補完として、共有端末では**使い終わったらブラウザを閉じる**運用を案内する
>   （ブラウザセッションが終われば `sessionStorage` ごと消える）。
>   **再検討条件**: セッション失効の push 経路（Reverb / Echo 等）を別目的で導入したとき /
>   「全デバイスからログアウト」を UI 機能として提供するとき /
>   bug-hunt・実機確認で複数タブ運用が実際に観測されたとき。

**セッション期限切れ・強制ログアウト（T089-b）— 「保証外」から「限定保証」へ**

> - **セッション期限切れ / 他デバイスからの強制ログアウトは、
>   「アプリが認証失敗を検知した以降」の戻るについて保証する**。
>   `bootstrap/app.php` の `AuthenticationException` render callback が
>   `Inertia::clearHistory()` を積み、着地の `/login`（Inertia 応答）が消費する。
>   **保証しない範囲**: そのタブが**一度もサーバと話さないまま**戻る場合。
>   このときタブは表示中の画面自体に PII を出しており、
>   これを塞ぐには push か polling が要るため扱わない（別タブと同じ判断）。
>   **`popstate` ごとの `session.status` プローブは採らない**:
>   (1) 表示中の PII は塞げないため目的を達しない、
>   (2) 通常の戻る/進むに毎回ネットワーク往復と秘匿オーバーレイが入り、
>       プローブ失敗時は「再試行」で操作が塞がれる（現場回線で新しい詰みを作る）。

**JSON 204 ログアウト — 残存の縮小を反映**

> - 経路 C の保証条件（`clearHistory: true` を含む Inertia page の**適用**）は不変。
>   ただし JSON 204 で完結したログアウトのタブも、
>   **次に認証を要する Inertia visit を行った時点**で認証失敗契機の `clearHistory` により鍵を失う。

### テスト計画

- [ ] ドキュメント / docblock のみ（挙動を変えないためテスト追加なし）
- [ ] ただし **施策 1 のテストが「文書が主張する保証範囲」の実体**である。
      文書の主張とテスト名が対応していることをレビュー時に確認する

### リスク

- 文書が実装より広い保証を書くと最悪（誤った安心）。**保証しない範囲を必ず対で書く**という
  T089 の書式を踏襲する。

---

## 施策 3: T090-a — proration 方針の確定と切替見積り

### 変更箇所

- `docs/architecture.md`「契約中プランの変更 (in-app swap / F-3-01)」節（L311 付近）の
  `proration_behavior=create_prorations` の箇条書き直後に **小見出しを 1 つ**追加
- `app/Services/Billing/CashierStripeGateway.php` の `buildSwapPayload()` docblock に
  参照 1 行を追加（見積り本文は複製しない = 二重管理を作らない）

### 波及変更

- TypeScript 型定義 / DTO / テスト: **なし**（金銭の挙動を変えないため既存 invariant テストも不変）

### `docs/architecture.md` 追加文（設計文言）

> **proration 方針は `create_prorations` で確定（現状維持）**。日割り差額は次回請求に反映する。
> `always_invoice`（即時徴収）へ切り替えるには以下が**セットで**必要であり、
> 「payload の 1 行」では終わらない:
> 1. `CashierStripeGateway::buildSwapPayload()` の変更 + payload invariant テストの更新
> 2. **状態機械の拡張**: `SubscriptionState` に `pending_update` 相当の表現が無い。
>    `incomplete` は現在 `Inactive` に畳まれ、`BillingAccess` →
>    `require-active-subscription` で**アプリ全体が遮断される**。
>    「アップグレードしようとして与信に失敗しただけの利用者」をロックアウトしない
>    state 設計が先に要る
> 3. **webhook の受け口**: `customer.subscription.pending_update_applied` / `..._expired` と、
>    プラン変更文脈での `invoice.payment_failed` の扱いが `StripeWebhookProcessor` に無い
> 4. **UI**: 3DS/SCA の確認導線がアプリに無い（決済 UI は Stripe hosted の
>    Checkout / Portal のみ）。要アクション状態を受ける画面が要る
> 5. **ロールバック意味論**: `pending_update` 期限切れで Stripe が巻き戻す挙動と
>    `organizations.plan_code` の projection を整合させる規約が要る
>
> **再検討条件**: 日割り差額の回収遅延がキャッシュフロー上の問題であることを
> 事業側が数値で示したとき。上記 1〜5 を同一 TODO で扱う前提で再設計する
> （**証拠なく金銭の挙動を反転させない**）。
> 判断の経緯は `devnotes/20260804-0900-t089-t090-residual-risk/` を参照。

### `buildSwapPayload()` docblock 追加行

```php
 * - `proration_behavior = create_prorations` — 日割り明細を作り、**次回請求に反映**する
 *   (`always_invoice` にしない = 即時請求 → 与信失敗の状態遷移を呼び込まない)。
 *   **この方針は確定済み**。切り替えに必要な作業一式は
 *   `docs/architecture.md` の「契約中プランの変更」節を参照 (ここに複製しない)。
```

### PHPStan適合チェック

- [x] コメントのみ（型に影響なし）

### テスト計画

- [ ] 追加なし。既存 `tests/Unit/Billing/SubscriptionSwapPayloadInvariantTest.php` が
      `proration_behavior=create_prorations` と即時請求誘発パラメータ非送信を既に固定しており、
      **この決定の機械的な守り手はそれである**ことを設計として確認する

### リスク

- なし（挙動不変）。

---

## 施策 4: T090-b — 上限超過の可視化と失敗地点からの誘導

### 4-1. `QuotaLimitsDto` → `QuotaStatusDto`（上限のみ → 上限 + 使用量 + 超過次元）

#### 変更箇所

- `app/DataTransferObjects/Billing/QuotaLimitsDto.php` を
  `app/DataTransferObjects/Billing/QuotaStatusDto.php` へ**改名**（旧クラスは残さない = 思考原則 3）

#### 波及変更

- API Resource/DTO: `app/DataTransferObjects/Billing/BillingDashboardDto.php` の
  `quotas` プロパティ型（プロパティ名 `quotas` は変えない）
- Controller: `app/Http/Controllers/Billing/BillingController.php::index()`
- TypeScript 型定義: `resources/js/types/billing.ts` の `QuotaLimitsShape` → `QuotaStatusShape`
- Svelte: `resources/js/pages/Billing/Index.svelte`
- テスト: `tests/Feature/Billing/*` のうち `quotas` を参照するもの

#### 現行コード

```php
final readonly class QuotaLimitsDto
{
    public function __construct(
        public ?int $maxProjects,
        public ?int $maxMembers,
        public ?int $maxStorageGb,
    ) {}

    /** @param  array<string, int>  $limits */
    public static function fromLimits(array $limits): self
    {
        $bytes = $limits['max_storage_bytes'] ?? null;

        return new self(
            maxProjects: $limits['max_projects'] ?? null,
            maxMembers: $limits['max_members'] ?? null,
            maxStorageGb: $bytes === null ? null : intdiv($bytes, 1024 ** 3),
        );
    }
}
```

#### 変更後コード

```php
/**
 * 課金ダッシュボードに出す現行 quota の状態 (上限 + 使用量 + 超過次元)。
 *
 * 上限の出典は QuotaService::limits() (プラン既定 + organization override)。
 * limits に key が無い = 無制限 = null。
 *
 * **超過 (`exceededLabels`) は「使用量 > 上限」の厳密超過のみ**を指す。
 * 「上限ちょうど」(1/1 等) は plan の設計どおりの正常状態なので警告に含めない
 * (starter/personal の max_projects=1 で全組織に恒常警告が出るのを避ける)。
 * 判定は**バイト等の生の単位**で行い、表示用の GiB 切り捨て値では判定しない。
 *
 * メンバー数は**上限のみ**を持つ (使用量も超過も出さない): `max_members` を
 * QuotaService::check する呼び出し元は存在せず実効的に未強制のため、
 * 「超過すると止まる」と読める表示をしない (App\Enums\QuotaKey の docblock 参照)。
 *
 * @phpstan-type QuotaStatusShape array{
 *   maxProjects: int|null,
 *   maxMembers: int|null,
 *   maxStorageGb: int|null,
 *   projectsUsed: int,
 *   storageUsedBytes: int,
 *   exceededLabels: list<string>
 * }
 */
final readonly class QuotaStatusDto
{
    /** @param  list<string>  $exceededLabels */
    public function __construct(
        public ?int $maxProjects,
        public ?int $maxMembers,
        public ?int $maxStorageGb,
        public int $projectsUsed,
        public int $storageUsedBytes,
        /** @var list<string> 超過している次元の表示名 (QuotaKey::label()) */
        public array $exceededLabels,
    ) {}

    /**
     * QuotaService::limits() の結果と実使用量から組み立てる。
     *
     * @param  array<string, int>  $limits
     */
    public static function build(array $limits, int $projectsUsed, int $storageUsedBytes): self
    {
        $projectLimit = $limits[QuotaKey::MaxProjects->value] ?? null;
        $storageLimit = $limits[QuotaKey::MaxStorageBytes->value] ?? null;

        $exceeded = [];
        if ($projectLimit !== null && $projectsUsed > $projectLimit) {
            $exceeded[] = QuotaKey::MaxProjects->label();
        }
        if ($storageLimit !== null && $storageUsedBytes > $storageLimit) {
            $exceeded[] = QuotaKey::MaxStorageBytes->label();
        }

        return new self(
            maxProjects: $projectLimit,
            maxMembers: $limits[QuotaKey::MaxMembers->value] ?? null,
            maxStorageGb: $storageLimit === null ? null : intdiv($storageLimit, 1024 ** 3),
            projectsUsed: $projectsUsed,
            storageUsedBytes: $storageUsedBytes,
            // append のみだが list 契約を構造的に保証する (将来 filter が入っても崩れない)
            exceededLabels: array_values($exceeded),
        );
    }

    /** @return QuotaStatusShape */
    public function toArray(): array
    {
        return [
            'maxProjects' => $this->maxProjects,
            'maxMembers' => $this->maxMembers,
            'maxStorageGb' => $this->maxStorageGb,
            'projectsUsed' => $this->projectsUsed,
            'storageUsedBytes' => $this->storageUsedBytes,
            'exceededLabels' => $this->exceededLabels,
        ];
    }
}
```

`BillingController::index()`（`StorageUsageService` を method injection で受ける。
`ProjectService` は使わない — 集計に service を挟む必要がないため関係経由で数える）:

```php
quotas: QuotaStatusDto::build(
    $quota->limits($organization),
    $organization->projects()->count(),      // ProjectService::create の判定と同じ数え方
    $storage->occupiedBytes($organization),  // QuotaService::checkAddition に渡すのと同じ占有量
),
```

#### PHPStan適合チェック

- [x] 戻り値の型が明示されている
- [x] null 安全（`?? null` + 明示 null 判定。`Assert` 不要な範囲）
- [x] DTO を返している（配列返却なし）
- [x] Generics: `list<string>` を `@param` / `@phpstan-type` で明示

### 4-2. `/billing` の表示（Svelte）

#### 変更箇所

- `resources/js/lib/format-bytes.ts`（**新規**）: `formatBytes()` を
  `pages/Dashboard.svelte` の private 関数から**移設**（Dashboard 側は import に置換 = 重複を残さない）
- `resources/js/pages/Billing/Index.svelte`: quota カードに使用量を併記 + 超過 Alert
- `resources/js/types/billing.ts`: `QuotaStatusShape`（PHP の `@phpstan-type` と exact 対）

#### 変更後（`Index.svelte` の該当部のみ）

```svelte
<Card padding="lg" testId="billing-quotas">
    <h2 class="text-h3">現在のプランの上限</h2>

    {#if page.quotas.exceededLabels.length > 0}
        <Alert type="warning" class="mt-4" testId="quota-exceeded-alert">
            現在のプランの上限を超えている項目があります（{page.quotas.exceededLabels.join("・")}）。
            既存のデータは削除されませんが、上限内に収まるまで新規作成とアップロードができません。
        </Alert>
    {/if}

    <dl class="mt-4 grid gap-4 sm:grid-cols-3">
        <div>
            <dt class="text-caption text-text-secondary">プロジェクト</dt>
            <dd class="mt-1 text-h3 text-text" data-testid="quota-max-projects">
                {page.quotas.maxProjects === null
                    ? `${page.quotas.projectsUsed} / 無制限`
                    : `${page.quotas.projectsUsed} / ${page.quotas.maxProjects}`}
            </dd>
        </div>
        <div>
            <dt class="text-caption text-text-secondary">メンバー</dt>
            <dd class="mt-1 text-h3 text-text" data-testid="quota-max-members">
                {formatLimit(page.quotas.maxMembers)}
            </dd>
        </div>
        <div>
            <dt class="text-caption text-text-secondary">ストレージ</dt>
            <dd class="mt-1 text-h3 text-text" data-testid="quota-max-storage">
                {page.quotas.maxStorageGb === null
                    ? `${formatBytes(page.quotas.storageUsedBytes)} / 無制限`
                    : `${formatBytes(page.quotas.storageUsedBytes)} / ${page.quotas.maxStorageGb} GB`}
            </dd>
        </div>
    </dl>
</Card>
```

- DESIGN.md 準拠: 色は `Alert` atom の `type="warning"` に委ねる（hex 直書きなし）。
  既存の `text-caption` / `text-h3` / `text-text-secondary` token をそのまま使う
- Atomic Design 準拠: 新しい component は作らない（既存 `Alert` atom + `Card` atom の組合せ）
- `data-testid` は既存の 3 つを維持（Browser / vitest の既存参照を壊さない）

### 4-3. 失敗地点からの誘導（`QuotaExceededException`）

#### 現行コード

```php
return new self("現在のプランの上限 ({$key->label()}: {$limit}) に達しています。プランのアップグレードをご検討ください。");
```

#### 変更後コード

```php
return new self(
    "現在のプランの上限 ({$key->label()}: {$limit}) に達しています。"
    .'現在のご利用状況と上限は「お支払い」画面で確認できます。プランのアップグレードをご検討ください。'
);
```

- リンク化のための構造化 flash 機構は**作らない**（flash は素の文字列。今必要ではない）
- `/billing` は課金ゲートの構造的 allowlist 内にあり、未契約組織からも到達できる
- 撮影 PWA の XHR 経路（`QuotaExceededResource` 422）にも同じ文言が載る（分岐を作らない）

### 4-4. 確認ダイアログ文言（`Plans.svelte`）

#### 現行コード

```ts
return isDowngrade
    ? base +
          "新しいプランの上限 (プロジェクト数・メンバー数・保存容量) を超えている場合、" +
          "既存のデータは削除されませんが、上限内に収まるまで新規作成とアップロードができません。"
    : base;
```

#### 変更後コード

```ts
return isDowngrade
    ? base +
          // メンバー数は quota の強制対象ではないため挙げない (QuotaKey の docblock 参照)。
          "新しいプランの上限 (プロジェクト数・保存容量) を超えている場合、" +
          "既存のデータは削除されませんが、上限内に収まるまで新規作成とアップロードができません。" +
          "超過している項目は「お支払い」画面で確認できます。"
    : base;
```

### 4-5. `max_members` が未強制であることの明文化

- `config/quota.php` の `plans` 直上コメントに 1 行追加:
  「`max_members` は現在**強制されていない**（`QuotaService::check` の呼び出し元が無い）。
   表示上の目安であり、増員をブロックしない」
- `app/Enums/QuotaKey.php` の `MaxMembers` case に同旨の inline コメント

#### 波及変更（施策 4 全体）

- TypeScript 型定義: `resources/js/types/billing.ts`（`QuotaStatusShape`）
- API Resource/DTO: `QuotaStatusDto` / `BillingDashboardDto`
- テストファイル: `tests/Feature/Billing/`（`quotas` 参照箇所）/ `tests/js/pages/Billing/`（あれば）/
  `tests/js/pages/Dashboard`（`formatBytes` 移設の影響がないことの確認）

#### テスト計画

- [ ] **Feature** `tests/Feature/Billing/BillingQuotaStatusTest.php`（新規）
  - **props shape の厳密固定**（rename 波及漏れの検出）: `/billing` の `page.quotas` の
    キー集合が `maxProjects` / `maxMembers` / `maxStorageGb` / `projectsUsed` /
    `storageUsedBytes` / `exceededLabels` の **6 キー厳密一致**であること
    （Inertia props は連想配列なので静的解析では取りこぼしを検出できない）
  - 上限内: `exceededLabels` が空 / `projectsUsed` と `storageUsedBytes` が実値
  - **上限ちょうど**（starter で project 1 件）: `exceededLabels` が**空**であること（恒常警告の回帰防止）
  - **超過**: `organization_quotas.limits` の override で `max_projects` を 0 に落として
    プロジェクト 1 件 → `exceededLabels` に「プロジェクト数」が入る
    （Plan 行を手組みせず、既存の override 機構で超過状態を作る）
  - 容量超過: `Take::factory()` で `size_bytes` を上限超に積む → 「保存容量」が入る
  - メンバー数は `exceededLabels` に**入らない**（未強制の明示）
- [ ] **Feature** 既存 `tests/Feature/Billing/QuotaTest.php` は不変（`QuotaService` は無改変）
- [ ] **Feature** quota 超過エラー文言に「お支払い」が含まれる（`QuotaExceededException` の
      既存テストがあれば更新、無ければ `tests/Feature/Billing/QuotaTest.php` に 1 本追加）
- [ ] **Vitest** `tests/js/pages/Billing/Index.test.ts`（既存があれば追記 / 無ければ新規）
  - `exceededLabels` が空なら Alert が描画されない / 非空なら描画され次元名が出る
- [ ] **Vitest** `tests/js/lib/format-bytes.test.ts`（移設した helper の境界値: B / KB / MB / GB）

#### リスク

- `/billing` の 1 render あたり **+1 count クエリ + 2 sum クエリ**。Dashboard が既に
  同じ集計をしており、`/billing` は低頻度画面のため許容する（キャッシュは作らない = 二重帳簿禁止）
- 文言変更が既存テストの文字列 assert を壊す可能性 → 施策実施時に grep して同時更新する

---

## 施策 5: T090-c — Portal 方針の確定（再開放要件の明文化）

### 変更箇所

- `app/Services/Billing/PortalConfigurationSpec.php` の class docblock

### 波及変更

- なし（**挙動を一切変えない**。`features()` の戻り値は不変）

### 変更後 docblock（追記部分）

```php
 * **この方針は確定済み**（T090 で `SubscriptionService::changePlan` が実装され、
 * 「プラン変更はアプリが所有する」という本 spec の宣言が実装で満たされた）。
 * `subscription_update` を再開放するには boolean の反転では足りず、以下が必要:
 *  1. Stripe の `subscription_update` 有効化は `products: [{product, prices: [...]}]` の
 *     列挙が必須だが、AI-CUE は **Stripe product id をどこにも保持していない**
 *     (`plan_prices` の列は stripe_price_id / lookup_key / amount / currency / is_current)
 *  2. 列挙の鮮度を保つ機構が無い。価格改定 (`PlanPriceService::replaceCurrent()`) と
 *     `plans.is_active=false` (販売停止) は Portal 列挙に効かないため、
 *     **旧価格・販売停止プランへ Portal から移行できてしまう**。
 *     既存 drift 検知 (`billing:ensure-portal-configuration --verify`) は
 *     `subscription_update.enabled === false` しか見ておらず、products 列挙の検証は無い
 *  3. 変更可否の理由 (past_due / schedule 管理下 / downgrade の上限低下) を
 *     Stripe hosted 画面に載せられない (禁止事項 #8「押下時に理由を出す」と噛み合わない)
 * 反転する場合はこの 3 点を満たす設計を先に立てること。
 * **検証責務**: 現在の drift 検知は `billing:ensure-portal-configuration --verify` が
 * `subscription_update.enabled === false` を確認するのみ。再開放するなら
 * **verify 側に products/prices 列挙の検証を足すところまでが同一作業**である。
```

### テスト計画

- [ ] 追加なし。既存 `tests/Feature/Billing/PortalConfigurationTest.php`（`subscription_update` 無効の固定）と
      `billing:ensure-portal-configuration --verify` が**この決定の機械的な守り手**である
      ことを設計として確認する

### リスク

- なし（コメントのみ）。

---

## 施策 6: T090-d — `PlanCode` 写像テストと Factory 非作成の明文化

### 変更箇所

- `tests/Unit/Enums/PlanCodeTest.php`（**新規**）
- `docs/factories.md`「Factory を持たないモデル」の記述に Plan / PlanPrice を明示

### 波及変更

- アプリコード: **なし**

### 新規テスト

```php
<?php

declare(strict_types=1);

use App\Enums\PlanCode;

/*
 * Stripe 決済対象プランの写像を全 case 網羅で固定する。
 *
 * SubscriptionService::assertStripeBillablePlan() は
 * PlanCode::requiresStripeCheckout() が false のプランを 422 に倒す。
 * 「false → 422」の変換自体は SubscriptionPlanChangeTest の personal ケースが固定済みなので、
 * ここでは**写像側**を全 case で固定し、合成として enterprise / business の穴を埋める。
 *
 * Plan Factory は作らない: Plan / PlanPrice は参照データで真実源は PlanSeeder +
 * config/quota.php + StripePriceLookupKeys。Factory を足すと
 * 「seeder と食い違うプラン定義」をテストが作れてしまう (docs/factories.md)。
 */

test('requiresStripeCheckout の写像が全 case で固定されている', function (): void {
    $expected = [
        'personal' => false,    // 無料 (PersonalPlanService::activate 経由)
        'starter' => true,
        'standard' => true,
        'business' => true,
        'enterprise' => false,  // 問い合わせ営業 (Checkout も in-app swap も通らない)
    ];

    // cases() 由来で網羅する = case 追加時に必ず落ちる
    expect(array_map(static fn (PlanCode $c): string => $c->value, PlanCode::cases()))
        ->toEqualCanonicalizing(array_keys($expected));

    foreach (PlanCode::cases() as $case) {
        expect($case->requiresStripeCheckout())
            ->toBe($expected[$case->value], "PlanCode::{$case->name} の決済対象判定");
    }
});
```

### PHPStan適合チェック

- [x] 戻り値の型が明示されている（クロージャの `: string`）
- [x] `array_keys` の型は `list<string>`（PHPStan が推論）
- [x] DB に触れない純粋な Unit テスト（`RefreshDatabase` の影響を受けない）

### テスト計画

- [ ] 上記 1 本。`composer test -- --filter=PlanCode` で単独 green を確認
- [ ] `docs/factories.md` の「Factory を持たないモデル」段落に
      「`Billing\Plan` / `Billing\PlanPrice`（参照データ。真実源は `PlanSeeder`）」を追記

### リスク

- 将来 `PlanCode` に case を追加すると必ず落ちる（**意図した設計**。写像を更新させるため）。

---

## 施策 7: 乖離台帳 A-6 の陳腐化解消

### 変更箇所

- `devnotes/20260717-0035-aigenba-billing-parity/aigenba-divergence-ledger.md:82`

### 変更後（設計文言）

> | A-6 | `SubscriptionService` の schedule lifecycle / seat / signup funding / `upgradeNow` /
>   `isMutableState` を非移植（**`changePlan` は T090 で移植済み**） |
>   席・schedule 機構が無いため。**`changePlan` を落とす根拠は成立していなかった**
>   （席にも schedule にも依存しない）ので T090 で `SubscriptionService::changePlan()` として実装した |

### テスト計画

- [ ] なし（devnotes の記録更新）

---

## 施策 8: quota 定義欠落の機械的追跡

### 変更箇所

- **`tests/Feature/Billing/PlanQuotaCoverageTest.php`（新規）** にテスト 1 本
- `tests/Architecture/QuotaKeyConfigInvariantTest.php` は**変更しない**

> **配置の根拠**: `tests/Pest.php` は「Architecture はファイル走査中心のため **DB を使わない**
> (TestCase のみ)」と明記しており、`RefreshDatabase` は Feature / Unit にしか適用されない
> (`pest()->extend(TestCase::class)->use(RefreshDatabase::class)` の適用先)。
> `Plan::query()` を含む本テストは Feature に置く。

### 変更後コード（新規ファイル）

```php
test('PlanSeeder が投入する plan code は必ず config/quota.php に limits を持つ', function (): void {
    // limits の無い plan_code が organizations.plan_code に入ると
    // QuotaService::limits() の `?? []` により **無制限扱い**になる (静かな課金事故)。
    // PlanCode enum 全 case との一致は要求しない: enterprise は問い合わせ営業で
    // Plan 行も plan_prices も持たず、plan_code が付く経路が無い。
    $this->seed(PlanSeeder::class);

    $seededCodes = Plan::query()->pluck('code')->all();
    $configured = array_keys(config()->array('quota.plans'));

    expect($seededCodes)->not->toBeEmpty();
    foreach ($seededCodes as $code) {
        expect(in_array($code, $configured, true))->toBeTrue(
            "PlanSeeder が投入する plan '{$code}' に config/quota.php の limits がありません (無制限扱いになる)",
        );
    }
});
```

- テスト全体で `$seed = true`（`tests/Pest.php`）のため `PlanSeeder` は既に走っているが、
  **このテストが seeder への依存を明示する**ために `$this->seed(PlanSeeder::class)` を書く
  （再実行安全な upsert seeder であることは `PlanSeeder` の docblock が保証）

### PHPStan適合チェック

- [x] `pluck('code')->all()` は `list<string>`（`Plan::$code` は string）
- [x] `config()->array()` を使う（`config()` の mixed を widen しない）

### テスト計画

- [ ] 現状 personal / starter / standard で green
- [ ] `business` / `enterprise` を seed した瞬間 red（= 追跡装置として機能する）

### リスク

- なし（新規テストのみ）。

---

## 実装モード

| 項目 | 内容 |
|------|------|
| 推奨モード | **standalone** |
| 判断根拠 | 施策 1（`bootstrap/app.php`）と施策 4（Billing DTO / TS / Svelte の rename を伴う波及）が独立していて相互依存が無い一方、**どちらも main の広い面（例外ハンドラ / 課金画面 DTO）に触れる**ため、他タスクと同時進行させると衝突が読みにくい。単独 worktree で通し実装し、テスト全 green を確認してからマージする |
| 競合リスク | `bootstrap/app.php`（他タスクが middleware / 例外を触ると衝突）/ `resources/js/types/billing.ts`（課金系タスクと衝突しうる）/ `docs/supported-browsers.md`（T085 の実機受入確認記録タスクと衝突しうる） |

## 実装順序（推奨）

1. 施策 6・8（テストのみ。既存挙動に触れないので先に green を確認できる）
2. 施策 1 + 施策 2（T089 の実装 → 文書。テストは実装前に fail を確認する = テストファースト）
3. 施策 4（DTO rename → Controller → TS → Svelte → テスト）
4. 施策 3・5・7（コメント / 文書のみ）
5. `composer test` / `composer phpstan` / `vendor/bin/pint --test` / `pnpm lint` /
   `pnpm typecheck` / `pnpm test` / `pnpm build` / `composer test:browser`（Chromium + WebKit 両レーン）

## 使命・禁止事項チェック

- **使命**: 施策 1 は共有端末での PII 露出（撮影 PWA の主戦場）を縮小し、施策 4 は
  「上限に達して撮影・アップロードが止まったことに気づけない」を解消する。いずれも
  「現場が標準化動画を作り続けられる」ことを守る
- **禁止事項 #1**: 全施策に対応するテスト（または既存の機械的守り手）を明示した
- **禁止事項 #4**: 追加応答はすべて Inertia / redirect + flash / 既存 JsonResource
- **禁止事項 #8**: CTA を disabled にする変更は含まない（超過は Alert で伝えるだけ）
- **思考原則 1**: 新機構を作らず Inertia / Laravel の公式 API（`Inertia::clearHistory()` /
  `$exceptions->render`）だけで塞ぐ
- **思考原則 2**: BroadcastChannel / popstate プローブ / 構造化 flash / Plan Factory は
  いずれも「作らない」と決め、理由を文書に固定した
- **思考原則 3**: `QuotaLimitsDto` は改名して旧クラスを残さない。`formatBytes` は移設して重複を残さない
- **思考原則 5**: 施策 1 は T1〜T6 を先に書いて fail を確認してから実装する
