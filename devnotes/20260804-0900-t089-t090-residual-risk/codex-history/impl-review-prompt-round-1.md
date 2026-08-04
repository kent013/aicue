【アプリの使命 (North Star)】

**AI-CUE** は、現場に既にある**作業手順書(SOP)を起点に**、AI が撮るべきカットを設計した**動画シナリオ**を生成し、そのシナリオを**スマホ(PWA)でナビゲーション撮影**することで、専門知識ゼロの現場作業者でも**標準化されたマニュアル動画**を作れるようにする。

- 「思考ゼロ・編集ゼロ」— 台本作成・撮影判断・編集の 3 ハードルを AI とナビ撮影で肩代わりする。
- 競合(OJT を撮って形式化する tebiki)と異なり、**標準作業を起点に AI が教材設計し撮影を指示する**（撮影者・教える人のスキルに品質を依存させない）。
- 熟練者の暗黙知を動画マニュアルという形式知へ変換する装置（SECI）。

> **v1 スコープ**: 字幕のみ(TTS 後回し) / 撮影は PWA(同一オリジン・セッション認証) / 動画合成は自前 ffmpeg / 単一 Default Project。

【禁止事項】

1. テストなしの実装完了報告(不変条件は対応する Architecture/Feature テストへの登録まで含めて「実装済み」)
2. PHPStan エラーの widen(型を緩めて黙らせる)・baseline 化
3. dev DB への破壊操作(`migrate:fresh` 等)をエージェント判断で実行すること
4. `response()->json()` の直書き(DTO / JsonResource / Inertia を使う。仕様固定 endpoint のみ例外)
5. LLM 呼び出しの Prism 直呼び(`app/Prompts/` の factory 経由のみ。PromptGuardrailTest が検出)
6. prompt 文字列のコード直書き(`resources/prompts/*.yaml` に置く)
7. 操作系 POST の応答での `redirect()->intended()`(ログイン直後フロー専用。招待送信等は `back()->with(...)` で完結させる)
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

---

# system: 実装レビュー (Laravel 12 + Svelte 5 + Inertia)

あなたは Laravel + Svelte の実装レビュアーである。以下の詳細設計書と実装差分をレビューせよ。

## レビュー観点

1. **設計との一致性**: 詳細設計書の施策 1〜8 が実装されているか。設計から逸脱している箇所は根拠が妥当か
2. **正確性**: ロジックの誤り、境界値、null 安全、race condition
3. **PHPStan level 10 適合性**: 型の緩め・baseline・@phpstan-ignore が無いか
4. **DTO / JsonResource パターン**: `response()->json()` の直書きが無いか
5. **テスト網羅性**: 各施策に対応するテストがあるか。負のコントロールが張られているか
6. **セキュリティ**: 特に施策 1 (AuthenticationException 契機の `Inertia::clearHistory()`) の副作用・偽陽性・回帰リスク。フラグの宙吊り (session に積まれたまま無関係な Inertia 応答で消費される) の実害評価
7. **DESIGN.md 準拠**: color / radius / typography は design token 経由。hex 直書き (`#RRGGBB`) を増やしていないか
8. **Atomic Design 準拠**: `resources/js/components/` の `atoms → molecules → organisms → features/{domain} → templates → pages` 単方向 import。今回は新規 component を作らず既存 `Alert` / `Card` atom を pages から使う方針
9. **文書としての固定**: 本タスクの主目的は「決定を恒久文書 (docs/ / docblock) へ固定し、次に読む人が同じ問いを再燃させないこと」である。決定が「決めただけ」で終わっていないか、逆に「作りすぎ (オーバーエンジニアリング)」になっていないか

## 出力形式

- ファイルごとに判定と指摘
- 指摘は **[Critical] / [Warning] / [Suggestion]** に分類する
- 最後に全体判定 **APPROVED** または **CHANGES_REQUESTED** を明示する
- 憶測ではなく差分に現れている事実に基づいて指摘すること

---

# user

## 詳細設計書

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
- テストファイル: `tests/Feature/Security/InertiaHistoryGuardTest.php` に 6 テスト追加（T1〜T6）
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
     |   - expectsJson(): Inertia 応答が返らないためフラグが宙に浮く
     |   - session 不在: そもそもフラグを置けない
     | `api/*` の明示判定は**置かない**。api グループ (withRouting の api:) は
     | StartSession を含まないため hasSession() が偽で既に抑止され、到達不能条件になる。
     | guards() では面を判別しない (web の auth は [null]、AuthenticateSession は ['web']、
     | Filament の Authenticate は [] になり、Filament の実装詳細に依存するため)。
     | その結果 /admin の認証失敗でもフラグは積まれるが、**安全側の偽陽性として許容**する
     | (影響は Inertia 面の履歴が 1 度だけ再キーされることだけ。テストで仕様として固定する)。
     */
    $exceptions->render(function (AuthenticationException $exception, Request $request): ?Response {
        if ($request->expectsJson() || ! $request->hasSession()) {
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

- [ ] **T1**（正）`'未認証 guest の認証失敗でも、着地の Inertia 応答に clearHistory が載る'`
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
      常に `null` を返すため `renderViaCallbacks` の後続処理と `respond()` の順序を
      構造的に変えられない）
- [ ] **`api/*` 専用の負テストも書かない**（api グループは `StartSession` を持たないため
      `hasSession()` で抑止される = 専用条件も専用テストも dead になる）

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
            既存のデータは削除されませんが、<strong>超えている項目に関わる操作</strong>
            （プロジェクト数ならプロジェクトの新規作成、保存容量なら動画のアップロード）が、
            上限内に収まるまでできません。
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
          // quota は次元ごとに独立して効くため、「両方が止まる」と読める書き方をしない。
          "新しいプランの上限を超えている場合、既存のデータは削除されませんが、" +
          "超えている項目に関わる操作が上限内に収まるまでできません " +
          "(プロジェクト数ならプロジェクトの新規作成、保存容量なら動画のアップロード)。" +
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

## 実装差分 (git diff HEAD — アプリコード / テスト)

```diff
diff --git a/app/DataTransferObjects/Billing/BillingDashboardDto.php b/app/DataTransferObjects/Billing/BillingDashboardDto.php
index 843ebdc..36df8ca 100644
--- a/app/DataTransferObjects/Billing/BillingDashboardDto.php
+++ b/app/DataTransferObjects/Billing/BillingDashboardDto.php
@@ -11,7 +11,7 @@
  * 課金ダッシュボード (/billing) の Inertia page prop (P8b / bs-14)。
  *
  * プラン一覧は /billing/plans へ移設済み。ここは「現在のプラン / per-bucket 残高 /
- * 現行 quota 上限 / 導線」に絞る。plan は表示用の解決結果 (ActiveFreePlan なら
+ * 現在の quota 状態 (上限 + 使用量 + 超過次元) / 導線」に絞る。plan は表示用の解決結果 (ActiveFreePlan なら
  * free_plan_code、それ以外は plan_code。gate 判定には使わない)。
  *
  * P9: 着地 feedback (one-shot) と請求先連絡先を additive に足した。
@@ -20,7 +20,7 @@
  *
  * @phpstan-import-type PricingPlanShape from PricingPlanDto
  * @phpstan-import-type TicketBalanceShape from TicketBalanceDto
- * @phpstan-import-type QuotaLimitsShape from QuotaLimitsDto
+ * @phpstan-import-type QuotaStatusShape from QuotaStatusDto
  * @phpstan-import-type AutoRechargeShape from AutoRechargeSettingsDto
  * @phpstan-import-type BillingFeedbackShape from BillingFeedbackDto
  * @phpstan-import-type BillingContactShape from BillingContactDto
@@ -30,7 +30,7 @@
  *   billingState: string,
  *   currentPeriodEnd: string|null,
  *   balance: TicketBalanceShape,
- *   quotas: QuotaLimitsShape,
+ *   quotas: QuotaStatusShape,
  *   canManageBilling: bool,
  *   continueUrl: string|null,
  *   autoRecharge: AutoRechargeShape,
@@ -46,7 +46,7 @@ public function __construct(
         public OnboardingBillingState $billingState,
         public ?string $currentPeriodEnd,
         public TicketBalanceDto $balance,
-        public QuotaLimitsDto $quotas,
+        public QuotaStatusDto $quotas,
         public bool $canManageBilling,
         /**
          * 課金ゲートで中断された「元の画面」への復帰先。契約成立着地でのみ 1 回だけ非 null
diff --git a/app/DataTransferObjects/Billing/QuotaLimitsDto.php b/app/DataTransferObjects/Billing/QuotaLimitsDto.php
deleted file mode 100644
index 11bf0e5..0000000
--- a/app/DataTransferObjects/Billing/QuotaLimitsDto.php
+++ /dev/null
@@ -1,57 +0,0 @@
-<?php
-
-declare(strict_types=1);
-
-namespace App\DataTransferObjects\Billing;
-
-/**
- * 課金ダッシュボードに出す現行 quota 上限 (override 反映済み)。
- *
- * 値の出典は QuotaService::limits() (プラン既定 + organization override のマージ結果)。
- * limits に key が無い = 無制限 = null。maxStorageGb は GiB 換算の表示値で、換算規則は
- * PricingService::storageGb と同一 (intdiv(bytes, 1024**3) 切り捨て)。
- *
- * 使用量 (current) は AI-CUE に横断集計経路が無いため持たない (上限の提示のみ)。
- *
- * @phpstan-type QuotaLimitsShape array{
- *   maxProjects: int|null,
- *   maxMembers: int|null,
- *   maxStorageGb: int|null
- * }
- */
-final readonly class QuotaLimitsDto
-{
-    public function __construct(
-        public ?int $maxProjects,
-        public ?int $maxMembers,
-        public ?int $maxStorageGb,
-    ) {}
-
-    /**
-     * QuotaService::limits() の結果から組み立てる。
-     *
-     * @param  array<string, int>  $limits
-     */
-    public static function fromLimits(array $limits): self
-    {
-        $bytes = $limits['max_storage_bytes'] ?? null;
-
-        return new self(
-            maxProjects: $limits['max_projects'] ?? null,
-            maxMembers: $limits['max_members'] ?? null,
-            maxStorageGb: $bytes === null ? null : intdiv($bytes, 1024 ** 3),
-        );
-    }
-
-    /**
-     * @return QuotaLimitsShape
-     */
-    public function toArray(): array
-    {
-        return [
-            'maxProjects' => $this->maxProjects,
-            'maxMembers' => $this->maxMembers,
-            'maxStorageGb' => $this->maxStorageGb,
-        ];
-    }
-}
diff --git a/app/DataTransferObjects/Billing/QuotaStatusDto.php b/app/DataTransferObjects/Billing/QuotaStatusDto.php
new file mode 100644
index 0000000..9b57fe9
--- /dev/null
+++ b/app/DataTransferObjects/Billing/QuotaStatusDto.php
@@ -0,0 +1,95 @@
+<?php
+
+declare(strict_types=1);
+
+namespace App\DataTransferObjects\Billing;
+
+use App\Enums\QuotaKey;
+
+/**
+ * 課金ダッシュボードに出す現行 quota の状態 (上限 + 使用量 + 超過次元)。
+ *
+ * 上限の出典は QuotaService::limits() (プラン既定 + organization override のマージ結果)。
+ * limits に key が無い = 無制限 = null。maxStorageGb は GiB 換算の表示値で、換算規則は
+ * PricingService::storageGb と同一 (intdiv(bytes, 1024**3) 切り捨て)。
+ *
+ * **超過 (exceededLabels) は「使用量 > 上限」の厳密超過のみ**を指す。
+ * 「上限ちょうど」(1/1 等) は plan の設計どおりの正常状態なので警告に含めない
+ * (>= にすると max_projects=1 の starter / personal の全組織にプロジェクトを 1 つ作った
+ *  時点から恒常警告が出て、本当の超過が埋もれる)。「上限に達した」ことへの気づきは
+ * 警告ではなく **使用量 / 上限の併記表示**が担う。
+ * 判定は**バイト等の生の単位**で行い、表示用の GiB 切り捨て値では判定しない。
+ *
+ * メンバー数は**上限のみ**を持つ (使用量も超過も出さない): max_members を
+ * QuotaService::check する呼び出し元は存在せず実効的に未強制のため、
+ * 「超過すると止まる」と読める表示をしない (App\Enums\QuotaKey の docblock 参照)。
+ *
+ * @phpstan-type QuotaStatusShape array{
+ *   maxProjects: int|null,
+ *   maxMembers: int|null,
+ *   maxStorageGb: int|null,
+ *   projectsUsed: int,
+ *   storageUsedBytes: int,
+ *   exceededLabels: list<string>
+ * }
+ */
+final readonly class QuotaStatusDto
+{
+    /**
+     * @param  list<string>  $exceededLabels  超過している次元の表示名 (QuotaKey::label())
+     */
+    public function __construct(
+        public ?int $maxProjects,
+        public ?int $maxMembers,
+        public ?int $maxStorageGb,
+        public int $projectsUsed,
+        public int $storageUsedBytes,
+        /** @var list<string> */
+        public array $exceededLabels,
+    ) {}
+
+    /**
+     * QuotaService::limits() の結果と実使用量から組み立てる。
+     *
+     * @param  array<string, int>  $limits
+     */
+    public static function build(array $limits, int $projectsUsed, int $storageUsedBytes): self
+    {
+        $projectLimit = $limits[QuotaKey::MaxProjects->value] ?? null;
+        $storageLimit = $limits[QuotaKey::MaxStorageBytes->value] ?? null;
+
+        // append のみで組み立てるため list<string> のまま (PHPStan が推論する)。
+        // 将来 filter 等でキーが飛ぶ操作を挟むなら、その時点で array_values を足すこと。
+        $exceeded = [];
+        if ($projectLimit !== null && $projectsUsed > $projectLimit) {
+            $exceeded[] = QuotaKey::MaxProjects->label();
+        }
+        if ($storageLimit !== null && $storageUsedBytes > $storageLimit) {
+            $exceeded[] = QuotaKey::MaxStorageBytes->label();
+        }
+
+        return new self(
+            maxProjects: $projectLimit,
+            maxMembers: $limits[QuotaKey::MaxMembers->value] ?? null,
+            maxStorageGb: $storageLimit === null ? null : intdiv($storageLimit, 1024 ** 3),
+            projectsUsed: $projectsUsed,
+            storageUsedBytes: $storageUsedBytes,
+            exceededLabels: $exceeded,
+        );
+    }
+
+    /**
+     * @return QuotaStatusShape
+     */
+    public function toArray(): array
+    {
+        return [
+            'maxProjects' => $this->maxProjects,
+            'maxMembers' => $this->maxMembers,
+            'maxStorageGb' => $this->maxStorageGb,
+            'projectsUsed' => $this->projectsUsed,
+            'storageUsedBytes' => $this->storageUsedBytes,
+            'exceededLabels' => $this->exceededLabels,
+        ];
+    }
+}
diff --git a/app/Enums/QuotaKey.php b/app/Enums/QuotaKey.php
index 1c4b101..496a05e 100644
--- a/app/Enums/QuotaKey.php
+++ b/app/Enums/QuotaKey.php
@@ -13,8 +13,20 @@
  */
 enum QuotaKey: string
 {
+    /** ProjectService::create が QuotaService::check で強制する (超過するとプロジェクトを作れない) */
     case MaxProjects = 'max_projects';
+
+    /**
+     * **現在どこからも強制されていない** (QuotaService::check / checkAddition の呼び出し元が無い)。
+     * config/quota.php の値は表示上の目安であり、増員はブロックされない。
+     * (personal プランの人数上限は PersonalPlanService::MAX_MEMBERS という別のハードキャップで、
+     *  本 quota とは別機構である。)
+     * したがって UI で「超えると止まる」と読める表示をしないこと。強制するなら
+     * 招待・メンバー追加経路への配線と Feature テストまでが同一作業になる。
+     */
     case MaxMembers = 'max_members';
+
+    /** TakeUploadService が QuotaService::checkAddition で強制する (超過するとアップロードできない) */
     case MaxStorageBytes = 'max_storage_bytes';
 
     /** 上限超過エラー等でユーザーに見せる表示名 */
diff --git a/app/Exceptions/Billing/QuotaExceededException.php b/app/Exceptions/Billing/QuotaExceededException.php
index 50659a9..8803ef0 100644
--- a/app/Exceptions/Billing/QuotaExceededException.php
+++ b/app/Exceptions/Billing/QuotaExceededException.php
@@ -14,8 +14,17 @@
  */
 class QuotaExceededException extends RuntimeException
 {
+    /**
+     * 文言には**回復先の画面名**を含める。失敗するのは撮影・プロジェクト作成の現場であり、
+     * そこから「どこを見れば現状と上限が分かるか」が分からないと詰みになるため
+     * (/billing は課金ゲートの構造的 allowlist 内で未契約組織からも到達できる)。
+     * flash は素の文字列なので、リンク化のための構造化 flash 機構は作らない。
+     */
     public static function forLimit(QuotaKey $key, int $limit): self
     {
-        return new self("現在のプランの上限 ({$key->label()}: {$limit}) に達しています。プランのアップグレードをご検討ください。");
+        return new self(
+            "現在のプランの上限 ({$key->label()}: {$limit}) に達しています。"
+            .'現在のご利用状況と上限は「お支払い」画面で確認できます。プランのアップグレードをご検討ください。'
+        );
     }
 }
diff --git a/app/Http/Controllers/Billing/BillingController.php b/app/Http/Controllers/Billing/BillingController.php
index e693bdb..7592325 100644
--- a/app/Http/Controllers/Billing/BillingController.php
+++ b/app/Http/Controllers/Billing/BillingController.php
@@ -10,7 +10,7 @@
 use App\DataTransferObjects\Billing\BillingDashboardDto;
 use App\DataTransferObjects\Billing\BillingFeedbackDto;
 use App\DataTransferObjects\Billing\BillingPlansPageDto;
-use App\DataTransferObjects\Billing\QuotaLimitsDto;
+use App\DataTransferObjects\Billing\QuotaStatusDto;
 use App\DataTransferObjects\Billing\UpdateBillingContactData;
 use App\DataTransferObjects\Marketing\PricingPlanDto;
 use App\Enums\Billing\BillingFeedbackKind;
@@ -43,6 +43,7 @@
 use App\Services\Billing\QuotaService;
 use App\Services\Billing\SubscriptionService;
 use App\Services\Billing\TicketLedgerService;
+use App\Services\Capture\StorageUsageService;
 use App\Services\Marketing\PricingService;
 use App\Services\Onboarding\IntendedPlanResolver;
 use App\Services\Onboarding\OnboardingReturnResolver;
@@ -96,6 +97,7 @@ public function index(
         TicketLedgerService $tickets,
         QuotaService $quota,
         PricingService $pricing,
+        StorageUsageService $storage,
     ): Response|RedirectResponse {
         $organization = $this->resolveCurrentOrganization($request);
         Gate::authorize('view', $organization);
@@ -134,7 +136,14 @@ public function index(
                 ? $subscription->current_period_end?->toIso8601String()
                 : null,
             balance: $tickets->balance($organization),
-            quotas: QuotaLimitsDto::fromLimits($quota->limits($organization)),
+            // 使用量の数え方は「実際に止まる判定」と同じ経路に揃える
+            // (プロジェクト数 = ProjectService::create の判定、容量 = checkAddition に渡す占有量)。
+            // 新しい集計機構やキャッシュは作らない (二重帳簿禁止)。
+            quotas: QuotaStatusDto::build(
+                $quota->limits($organization),
+                $organization->projects()->count(),
+                $storage->occupiedBytes($organization),
+            ),
             canManageBilling: $canManageBilling,
             continueUrl: $this->resolveOnboardingContinue($organization),
             // P8a: オートリチャージ設定カード。subscription 有無に依存せず常に非 null
diff --git a/app/Http/Responses/Fortify/LogoutResponse.php b/app/Http/Responses/Fortify/LogoutResponse.php
index 656fde8..6c692e9 100644
--- a/app/Http/Responses/Fortify/LogoutResponse.php
+++ b/app/Http/Responses/Fortify/LogoutResponse.php
@@ -50,6 +50,15 @@
  * 「**`clearHistory: true` を含む Inertia page をクライアントが適用したタブ**」に限られる
  * (受信ではなく適用。通信断や JS 例外で適用前に中断すれば鍵は残る)。
  *
+ * **`clearHistory` の発行契機は本クラスだけではない。** セッション期限切れと
+ * 他デバイスからの強制ログアウトは「利用者が明示的に終わらせた」契機を持たないため
+ * 本クラスを通らないが、どちらも `AuthenticationException` として現れ、
+ * `bootstrap/app.php` の render callback が同じフラグを積む。
+ * その結果、上記 204 経路の残存リスク (画面遷移しないまま戻る) も、
+ * **そのタブが次に認証を要する Inertia visit を行った時点で解消する**
+ * (一度もサーバと話さないまま戻る場合だけが残る)。保証範囲の正本は
+ * `docs/supported-browsers.md`。
+ *
  * このアプリでは実運用上その条件を満たす: `/logout` を叩く導線は
  * `AppLayout.svelte` (通常画面のユーザーメニュー) と `pages/Auth/VerifyEmail.svelte`
  * (メール認証待ち画面の離脱導線) の 2 箇所で、**いずれも `router.post('/logout')` =
diff --git a/app/Services/Billing/CashierStripeGateway.php b/app/Services/Billing/CashierStripeGateway.php
index cad1f93..56432aa 100644
--- a/app/Services/Billing/CashierStripeGateway.php
+++ b/app/Services/Billing/CashierStripeGateway.php
@@ -93,7 +93,10 @@ public function swapSubscriptionPrices(
      * invariant (gateway 単体テストで固定):
      * - **既存 item id を指定**して price を差し替える (id 無指定は item の二重化を招く)
      * - `proration_behavior = create_prorations` — 日割り明細を作り、**次回請求に反映**する
-     *   (`always_invoice` にしない = 即時請求 → 与信失敗の状態遷移を呼び込まない)
+     *   (`always_invoice` にしない = 即時請求 → 与信失敗の状態遷移を呼び込まない)。
+     *   **この方針は確定済み**。切り替えに必要な作業一式 (state 機械 / webhook / UI /
+     *   ロールバック意味論) は `docs/architecture.md` の「契約中プランの変更」節を参照
+     *   (ここに複製しない = 二重管理を作らない)
      * - `billing_cycle_anchor` / `trial_end` / `payment_behavior` は **送らない**
      *   (即時請求・trial 再開の誘発を構造的に避ける)
      *
diff --git a/app/Services/Billing/PortalConfigurationSpec.php b/app/Services/Billing/PortalConfigurationSpec.php
index b64a762..5d78143 100644
--- a/app/Services/Billing/PortalConfigurationSpec.php
+++ b/app/Services/Billing/PortalConfigurationSpec.php
@@ -13,6 +13,22 @@
  * env はこの spec から生成された
  * configuration id を保持するのみで、ポリシー切替先ではない。
  *
+ * **この方針は確定済み** (T090 で `SubscriptionService::changePlan` が実装され、
+ * 「プラン変更はアプリが所有する」という本 spec の宣言が実装で満たされた)。
+ * `subscription_update` を再開放するには boolean の反転では足りず、以下が必要:
+ *  1. Stripe の `subscription_update` 有効化は `products: [{product, prices: [...]}]` の
+ *     列挙が必須だが、AI-CUE は **Stripe product id をどこにも保持していない**
+ *     (`plan_prices` の列は stripe_price_id / lookup_key / amount / currency / is_current)
+ *  2. 列挙の鮮度を保つ機構が無い。価格改定 (`PlanPriceService::replaceCurrent()`) と
+ *     `plans.is_active=false` (販売停止) は Portal 列挙に効かないため、
+ *     **旧価格・販売停止プランへ Portal から移行できてしまう**
+ *  3. 変更可否の理由 (past_due / schedule 管理下 / downgrade の上限低下) を
+ *     Stripe hosted 画面に載せられない (禁止事項 #8「押下時に理由を出す」と噛み合わない)
+ * 反転する場合はこの 3 点を満たす設計を先に立てること。
+ * **検証責務**: 現在の drift 検知は `billing:ensure-portal-configuration --verify` が
+ * `subscription_update.enabled === false` を確認するのみで、products/prices 列挙の検証は無い。
+ * 再開放するなら **verify 側に列挙の検証を足すところまでが同一作業**である。
+ *
  * 公式 API ref: POST /v1/billing_portal/configurations の features 集合に対応。
  *
  * @phpstan-type PortalConfigurationFeatures array{
diff --git a/bootstrap/app.php b/bootstrap/app.php
index 8cda392..b226506 100644
--- a/bootstrap/app.php
+++ b/bootstrap/app.php
@@ -25,11 +25,13 @@
 use App\Http\Resources\Billing\InsufficientTicketsResource;
 use App\Http\Resources\Billing\QuotaExceededResource;
 use App\Support\Http\AdminPanelPath;
+use Illuminate\Auth\AuthenticationException;
 use Illuminate\Contracts\Auth\Middleware\AuthenticatesRequests;
 use Illuminate\Foundation\Application;
 use Illuminate\Foundation\Configuration\Exceptions;
 use Illuminate\Foundation\Configuration\Middleware;
 use Illuminate\Http\Request;
+use Inertia\Inertia;
 use Inertia\Middleware\EncryptHistory;
 use Symfony\Component\HttpFoundation\Response;
 
@@ -175,6 +177,46 @@
             fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
         );
 
+        /*
+         | セッション終了を検知した契機で Inertia の履歴暗号鍵を捨てさせる (経路 C の拡張)。
+         |
+         | ログアウト (App\Http\Responses\Fortify\LogoutResponse) は「利用者が明示的に
+         | 終わらせた」契機しか拾えない。セッション期限切れと、パスワード変更による
+         | 他デバイスの強制ログアウト (Auth::logoutOtherDevices → web グループの
+         | AuthenticateSession) は、どちらも AuthenticationException として現れる。
+         | ここでフラグを積むと、着地の /login (Inertia 応答) が
+         | session()->pull で消費し、そのタブの sessionStorage の履歴鍵が消える。
+         | = **認証失敗を契機に、以後の「戻る」による復元を無効化する**
+         |   (過去に遡って無効化するのではない。docs/supported-browsers.md が正本)。
+         |
+         | 応答自体は既定の unauthenticated() 処理に委ねる (**null を返して素通し**)。
+         | Handler::render() は renderViaCallbacks() を AuthenticationException の既定分岐より
+         | 先に呼び、callback が null を返せば既定処理へ進む (Laravel 12 実装)。
+         | この「null で素通し」に依存するため、**Laravel の major 更新時に再確認する**
+         | (壊れた場合は InertiaHistoryGuardTest が落ちる)。
+         |
+         | 積まない条件は 2 つだけ:
+         |   - expectsJson(): Inertia 応答が返らないためフラグが宙に浮く
+         |   - session 不在: そもそもフラグを置けない
+         | `api/*` の明示判定は**置かない**。api グループ (withRouting の api:) は
+         | StartSession を含まないため hasSession() が偽で既に抑止され、到達不能条件になる。
+         | guards() では面を判別しない (web の auth は [null]、AuthenticateSession は ['web']、
+         | Filament の Authenticate は override により [] になり、実装詳細に依存するため)。
+         | その結果 /admin の認証失敗でもフラグは積まれるが、**安全側の偽陽性として許容**する
+         | (影響は Inertia 面の履歴が 1 度だけ再キーされることだけ)。この偽陽性は
+         | InertiaHistoryGuardTest が仕様として固定しており、Filament の認証失敗の実装が
+         | 変わったら本コメントとテストを**一緒に**更新する。
+         */
+        $exceptions->render(function (AuthenticationException $exception, Request $request): ?Response {
+            if ($request->expectsJson() || ! $request->hasSession()) {
+                return null;
+            }
+
+            Inertia::clearHistory();
+
+            return null;
+        });
+
         // 課金系のドメイン例外は web では back + error flash に変換する
         // (API 経路では null を返して下の ApiExceptionRenderer に委ねる)
         $exceptions->render(function (QuotaExceededException $exception, Request $request) {
diff --git a/config/quota.php b/config/quota.php
index 80953b3..e8a8065 100644
--- a/config/quota.php
+++ b/config/quota.php
@@ -28,7 +28,12 @@
     'fallback_plan' => 'personal',
 
     /*
-    | plan_code → limits。プラン追加時は PlanSeeder と合わせてここに limits を定義する。
+    | plan_code → limits。プラン追加時は PlanSeeder と合わせてここに limits を定義する
+    | (定義漏れは無制限扱いになる。tests/Feature/Billing/PlanQuotaCoverageTest が検出する)。
+    |
+    | 注意: max_members は現在**強制されていない** (QuotaService::check の呼び出し元が無い)。
+    | 表示上の目安であり、増員をブロックしない (実際に止まるのは max_projects と
+    | max_storage_bytes の 2 次元だけ)。詳細は App\Enums\QuotaKey の docblock。
     */
     'plans' => [
         'personal' => [
diff --git a/resources/js/lib/bfcache-guard.ts b/resources/js/lib/bfcache-guard.ts
index 97c038e..3c61b8c 100644
--- a/resources/js/lib/bfcache-guard.ts
+++ b/resources/js/lib/bfcache-guard.ts
@@ -32,9 +32,25 @@
  *
  * **担当範囲**: 本 guard が守るのは **Safari の真の bfcache 復元 (pagehide/pageshow)** だけ。
  * Inertia SPA のクライアント履歴復元 (popstate) は本 guard を発火させないため、
- * そちらは Inertia 公式機構 (bootstrap/app.php の EncryptHistory +
- * LogoutResponse の Inertia::clearHistory()) が担当する (bug-hunt F-4-01)。
- * ここに popstate フックを足さないこと — 同一問題の二重実装になる。
+ * そちらは Inertia 公式機構 (bootstrap/app.php の EncryptHistory + Inertia::clearHistory() の
+ * 発行契機 2 つ = LogoutResponse と bootstrap/app.php の AuthenticationException callback)
+ * が担当する (bug-hunt F-4-01)。
+ *
+ * **ここに popstate フックを足さないこと。** 二重実装になるから、だけではない。
+ * 「popstate のたびに /session/status をプローブして、無効なら秘匿する」案は
+ * 技術的には成立する (popstate リスナは Inertia の非同期 swap より先に同期実行できる) が、
+ * 設計フェーズで**却下済み**である:
+ *   1. **目的を達しない**。塞げるのは履歴の過去 PII だけで、そのタブが**表示中**の PII は残る
+ *      (セッションが切れたタブは既に認証済み DOM を描画したままである)。
+ *   2. **通常の戻る/進むを毎回ネットワーク往復 + 秘匿オーバーレイで塞ぐ**。本 guard は
+ *      プローブ失敗を「秘匿維持 + 再試行ボタン」に倒す設計 (fail-closed) なので、
+ *      現場の不安定な回線では**通常の戻る操作が再試行画面で塞がれる = 新しい詰み**になる。
+ *      撮影 PWA (/app/*) の戻るは主要導線であり、ここを重くするのは使命に反する。
+ * セッション終了後の履歴復元は「認証失敗を契機にサーバが clearHistory を出す」側で塞ぐ
+ * (docs/supported-browsers.md が正本)。
+ *
+ * ※ 本 docblock の更新は**挙動変更ではない**ため、docs/supported-browsers.md の
+ *   「実機受入確認の再確認条件」のトリガには当たらない (トリガは挙動変更に限る)。
  *
  * なお Inertia も pageshow(persisted) で history を復号し直すが、それは**非同期**であり、
  * 復元 DOM は既に描画されている。「検証完了まで秘匿する」という本 guard の要件は
diff --git a/resources/js/lib/format-bytes.ts b/resources/js/lib/format-bytes.ts
new file mode 100644
index 0000000..f764a74
--- /dev/null
+++ b/resources/js/lib/format-bytes.ts
@@ -0,0 +1,13 @@
+/**
+ * バイト数の可読表記 (Dashboard の残容量タイル / 課金画面の quota カードで共有)。
+ *
+ * 1024 進法 (KB/MB/GB は KiB/MiB/GiB の意) で、サーバ側の GiB 換算
+ * (PricingService::storageGb = intdiv(bytes, 1024**3)) と同じ基数を使う。
+ * 表示専用であり、上限判定には使わない (判定は常に生のバイト数で行う)。
+ */
+export function formatBytes(bytes: number): string {
+    if (bytes >= 1024 ** 3) return `${(bytes / 1024 ** 3).toFixed(1)} GB`;
+    if (bytes >= 1024 ** 2) return `${(bytes / 1024 ** 2).toFixed(1)} MB`;
+    if (bytes >= 1024) return `${(bytes / 1024).toFixed(1)} KB`;
+    return `${bytes} B`;
+}
diff --git a/resources/js/pages/Billing/Index.svelte b/resources/js/pages/Billing/Index.svelte
index 132ad7b..395ae77 100644
--- a/resources/js/pages/Billing/Index.svelte
+++ b/resources/js/pages/Billing/Index.svelte
@@ -13,13 +13,15 @@
     import PageHeader from "@/components/molecules/PageHeader.svelte";
     import AutoRechargeCard from "@/components/features/billing/AutoRechargeCard.svelte";
     import BillingContactForm from "@/components/features/billing/BillingContactForm.svelte";
+    import { formatBytes } from "@/lib/format-bytes";
     import { formatDate } from "@/lib/date-format";
     import type { SharedProps } from "@/lib/shared-props";
     import type { BillingDashboardProps, BillingFeedbackKind } from "@/types/billing";
 
     /**
-     * 課金ダッシュボード (/billing)。現在のプラン / per-bucket チケット残高 / 現行 quota 上限 /
-     * オートリチャージ設定 と、プラン比較・チケット購入への導線を持つ。
+     * 課金ダッシュボード (/billing)。現在のプラン / per-bucket チケット残高 /
+     * quota の利用状況 (使用量 / 上限 + 超過警告) / オートリチャージ設定 と、
+     * プラン比較・チケット購入への導線を持つ。
      *
      * プラン一覧は /billing/plans (Billing/Plans.svelte) へ移設済み。
      * 支払い方法・解約は Customer Portal (POST → Inertia::location で Stripe へ) 経由。
@@ -234,16 +236,30 @@
                 />
 
                 <Card padding="lg" testId="billing-quotas">
-                    <h2 class="text-h3">現在のプランの上限</h2>
+                    <h2 class="text-h3">ご利用状況と上限</h2>
+
+                    {#if page.quotas.exceededLabels.length > 0}
+                        <Alert type="warning" class="mt-4" testId="quota-exceeded-alert">
+                            現在のプランの上限を超えている項目があります（{page.quotas.exceededLabels.join(
+                                "・",
+                            )}）。 既存のデータは削除されませんが、<strong
+                                >超えている項目に関わる操作</strong
+                            >
+                            （プロジェクト数ならプロジェクトの新規作成、保存容量なら動画のアップロード）が、上限内に収まるまでできません。
+                        </Alert>
+                    {/if}
+
                     <dl class="mt-4 grid gap-4 sm:grid-cols-3">
                         <div>
                             <dt class="text-caption text-text-secondary">プロジェクト</dt>
                             <dd class="mt-1 text-h3 text-text" data-testid="quota-max-projects">
-                                {formatLimit(page.quotas.maxProjects)}
+                                {page.quotas.projectsUsed} / {formatLimit(page.quotas.maxProjects)}
                             </dd>
                         </div>
                         <div>
-                            <dt class="text-caption text-text-secondary">メンバー</dt>
+                            <!-- メンバー数は quota として強制されていないため使用量を併記しない
+                                 (「超えると止まる」と読める表示をしない。QuotaKey の docblock 参照) -->
+                            <dt class="text-caption text-text-secondary">メンバー (上限)</dt>
                             <dd class="mt-1 text-h3 text-text" data-testid="quota-max-members">
                                 {formatLimit(page.quotas.maxMembers)}
                             </dd>
@@ -251,7 +267,8 @@
                         <div>
                             <dt class="text-caption text-text-secondary">ストレージ</dt>
                             <dd class="mt-1 text-h3 text-text" data-testid="quota-max-storage">
-                                {page.quotas.maxStorageGb === null
+                                {formatBytes(page.quotas.storageUsedBytes)} / {page.quotas
+                                    .maxStorageGb === null
                                     ? "無制限"
                                     : `${page.quotas.maxStorageGb} GB`}
                             </dd>
diff --git a/resources/js/pages/Billing/Plans.svelte b/resources/js/pages/Billing/Plans.svelte
index 5ad24dd..5016b31 100644
--- a/resources/js/pages/Billing/Plans.svelte
+++ b/resources/js/pages/Billing/Plans.svelte
@@ -92,8 +92,13 @@
             `(画面表示への反映は数分かかる場合があります)、差額は日割りで次回のご請求に調整されます。`;
         return isDowngrade
             ? base +
-                  "新しいプランの上限 (プロジェクト数・メンバー数・保存容量) を超えている場合、" +
-                  "既存のデータは削除されませんが、上限内に収まるまで新規作成とアップロードができません。"
+                  // メンバー数は quota として強制されていないため挙げない
+                  // (起きないことを起きると言わない。App\Enums\QuotaKey の docblock 参照)。
+                  // quota は次元ごとに独立して効くため「両方が止まる」と読める書き方もしない。
+                  "新しいプランの上限を超えている場合、既存のデータは削除されませんが、" +
+                  "超えている項目に関わる操作が上限内に収まるまでできません " +
+                  "(プロジェクト数ならプロジェクトの新規作成、保存容量なら動画のアップロード)。" +
+                  "超過している項目は「お支払い」画面で確認できます。"
             : base;
     });
 
diff --git a/resources/js/pages/Dashboard.svelte b/resources/js/pages/Dashboard.svelte
index 9f91b58..f8b8ddc 100644
--- a/resources/js/pages/Dashboard.svelte
+++ b/resources/js/pages/Dashboard.svelte
@@ -20,6 +20,7 @@
     import AppLayout from "@/components/templates/AppLayout.svelte";
     import PageContainer from "@/components/templates/PageContainer.svelte";
     import PageContent from "@/components/templates/PageContent.svelte";
+    import { formatBytes } from "@/lib/format-bytes";
     import type { SharedProps } from "@/lib/shared-props";
     import type { DashboardProps } from "@/types/dashboard";
     import { STATUS_TONES, VIDEO_MANUAL_STATUS_LABELS } from "@/types/manual";
@@ -42,13 +43,6 @@
     const isEditor = $derived(dashboard.role === "editor");
     const isShooter = $derived(dashboard.role === "shooter");
 
-    /** バイト数の可読表記 (残容量タイルの subtext 用) */
-    function formatBytes(bytes: number): string {
-        if (bytes >= 1024 ** 3) return `${(bytes / 1024 ** 3).toFixed(1)} GB`;
-        if (bytes >= 1024 ** 2) return `${(bytes / 1024 ** 2).toFixed(1)} MB`;
-        if (bytes >= 1024) return `${(bytes / 1024).toFixed(1)} KB`;
-        return `${bytes} B`;
-    }
 </script>
 
 {#snippet shootingCard()}
diff --git a/resources/js/types/billing.ts b/resources/js/types/billing.ts
index e137132..d34112f 100644
--- a/resources/js/types/billing.ts
+++ b/resources/js/types/billing.ts
@@ -29,11 +29,20 @@ export interface TicketBalanceShape {
     readonly nextExpireAt: string | null;
 }
 
-/** PHP: QuotaLimitsDto (QuotaLimitsShape) と対 (null = 無制限) */
-export interface QuotaLimitsShape {
+/**
+ * PHP: QuotaStatusDto (QuotaStatusShape) と対 (上限の null = 無制限)。
+ *
+ * exceededLabels は「使用量 > 上限」の**厳密超過**次元の表示名だけを含む
+ * (上限ちょうどは正常状態なので含まない)。空配列 = 超過なし。
+ * メンバー数は上限のみで使用量・超過を持たない (quota として未強制のため)。
+ */
+export interface QuotaStatusShape {
     readonly maxProjects: number | null;
     readonly maxMembers: number | null;
     readonly maxStorageGb: number | null;
+    readonly projectsUsed: number;
+    readonly storageUsedBytes: number;
+    readonly exceededLabels: readonly string[];
 }
 
 /** 購入フォームの状態 (PHP: PurchaseFormState) */
@@ -112,7 +121,7 @@ export interface BillingDashboardProps {
     readonly billingState: BillingStateValue;
     readonly currentPeriodEnd: string | null;
     readonly balance: TicketBalanceShape;
-    readonly quotas: QuotaLimitsShape;
+    readonly quotas: QuotaStatusShape;
     readonly canManageBilling: boolean;
     /**
      * 課金ゲートで中断された「元の画面」への復帰先 (same-origin 内部 path)。
diff --git a/tests/Browser/InertiaHistoryRestoreAfterLogoutTest.php b/tests/Browser/InertiaHistoryRestoreAfterLogoutTest.php
index da20a5d..c19fac0 100644
--- a/tests/Browser/InertiaHistoryRestoreAfterLogoutTest.php
+++ b/tests/Browser/InertiaHistoryRestoreAfterLogoutTest.php
@@ -202,3 +202,101 @@ function inertiaHistoryWaitUntil(
     $page->assertScript('window.__inertiaHistoryProbe', 'alive');
     $page->assertSee($owner->name)->assertNoJavaScriptErrors();
 });
+
+/**
+ * ブラウザ側で **JSON 204 のログアウト**を行う (画面遷移を起こさない = 履歴鍵を残したまま)。
+ *
+ * 実運用のログアウト導線 (router.post) は着地の Inertia page を適用して鍵を捨てるが、
+ * ここでは「セッションだけ切れて、そのタブは何も知らない」状態
+ * (= 期限切れ / 他デバイスからの強制ログアウトと同じ形) を決定的に作る。
+ *
+ * ※ tests/Browser/AuthenticatedPageBfcacheTest.php の bfcacheLogoutInBrowser() と同型だが、
+ *   Pest のグローバル関数は再宣言できないため本ファイル専用の名前で持つ。
+ */
+function inertiaHistoryLogoutWithoutNavigation(PendingAwaitablePage $page): void
+{
+    $authenticated = $page->script(<<<'JS'
+        (async () => {
+            const match = document.cookie.match(/(?:^|;\s*)XSRF-TOKEN=([^;]*)/);
+            const token = match ? decodeURIComponent(match[1]) : '';
+            await fetch('/logout', {
+                method: 'POST',
+                credentials: 'same-origin',
+                headers: {
+                    'X-XSRF-TOKEN': token,
+                    'X-Requested-With': 'XMLHttpRequest',
+                    'Accept': 'application/json',
+                },
+            });
+            const status = await fetch('/session/status', {
+                credentials: 'same-origin',
+                cache: 'no-store',
+                headers: { 'Accept': 'application/json' },
+            }).then((response) => response.json());
+            return status.authenticated;
+        })()
+    JS);
+
+    expect($authenticated)->toBeFalse('前提条件失敗: ブラウザ側のログアウトでセッションが無効化されていない');
+}
+
+test('セッションが切れたタブは次の Inertia visit で履歴鍵を失い、戻っても PII が出ない', function (): void {
+    // T089-b: 認証失敗 (AuthenticationException) 契機の Inertia::clearHistory() を
+    // 実ブラウザで一気通貫に固定する。JSON 204 のログアウトで「セッションだけ切れて
+    // 画面遷移していないタブ」を作り、次の Inertia visit で鍵が実際に消えることを観測する。
+    [, $owner] = createOrganizationWithOwner();
+    $this->actingAs($owner);
+
+    $page = visit('/dashboard');
+    $page->assertSee($owner->name);
+
+    // 正のコントロール (1): 認証済み履歴が暗号化されている = 捨てるべき鍵が存在する
+    inertiaHistoryWaitUntil(
+        $page,
+        'window.history.state?.page instanceof ArrayBuffer',
+        'history state が暗号化されていない (EncryptHistory 未適用、または crypto.subtle 不在)',
+    );
+
+    // JS 実行コンテキストの生存マーカー (フルリロードで消える)
+    $page->script("window.__inertiaHistoryProbe = 'alive'; true");
+
+    inertiaHistoryLogoutWithoutNavigation($page);
+
+    // 正のコントロール (2): 204 直後は鍵が **残っている** (= このあと消えることに意味がある)
+    expect($page->script("window.sessionStorage.getItem('historyKey') !== null"))
+        ->toBeTrue('204 ログアウト直後に履歴鍵が既に消えている (前提が崩れ、以降の観測が空振りする)');
+
+    // Inertia Link (Dashboard.svelte の TextLink「通知を確認」) で Inertia visit を起こす。
+    // 認証が切れているのでサーバは /login へ倒し、その Inertia 応答が clearHistory を消費する。
+    // 文言は Dashboard.svelte 由来 (testId 未付与)。変わったら本テストを追随させること。
+    $page->click('通知を確認');
+    inertiaHistoryWaitUntil(
+        $page,
+        "window.location.pathname === '/login'",
+        'セッション切れの Inertia visit で /login に倒れない',
+    );
+
+    // 本丸 (1): 鍵が実際に消えている = 以降の「戻る」で過去エントリを復号できない
+    inertiaHistoryWaitUntil(
+        $page,
+        "window.sessionStorage.getItem('historyKey') === null",
+        '/login 着地後も履歴鍵が残っている (clearHistory が消費されていない)',
+    );
+
+    // 「戻る」の前に瞬間露出の監視を仕込む (終状態の assertDontSee では取り逃す)
+    inertiaHistoryWatchForPii($page, $owner->name);
+
+    $page->back();
+
+    inertiaHistoryWaitUntil(
+        $page,
+        "window.location.pathname === '/login'",
+        'セッション切れ後の戻るで /login に倒れない',
+    );
+
+    // 本丸 (2): 復元 → login までの間、PII が **一度も** 描画されていない
+    $page->assertScript('window.__piiSeen', false);
+    // same-document で完結している (= 本当に SPA 履歴復元経路を通った)
+    $page->assertScript('window.__inertiaHistoryProbe', 'alive');
+    $page->assertDontSee($owner->name)->assertNoJavaScriptErrors();
+});
diff --git a/tests/Feature/Billing/BillingQuotaStatusTest.php b/tests/Feature/Billing/BillingQuotaStatusTest.php
new file mode 100644
index 0000000..6a0657c
--- /dev/null
+++ b/tests/Feature/Billing/BillingQuotaStatusTest.php
@@ -0,0 +1,111 @@
+<?php
+
+declare(strict_types=1);
+
+use App\Models\Cut;
+use App\Models\Project;
+use App\Models\Take;
+use App\Models\VideoManual;
+use Inertia\Testing\AssertableInertia as Assert;
+
+/*
+ * /billing の quota カード (T090-b): 上限だけでなく **使用量と超過次元**を届ける。
+ *
+ * 実際に止まるのは max_projects (ProjectService::create) と
+ * max_storage_bytes (TakeUploadService) の 2 次元だけなので、使用量と超過判定も
+ * その 2 次元に閉じる。max_members は QuotaService::check の呼び出し元が無く
+ * 実効的に未強制のため、上限のみを出し「超えると止まる」と読める表示をしない。
+ *
+ * 超過判定は **`>` (厳密超過)**。`>=` にすると max_projects=1 の starter / personal で
+ * 全組織に恒常警告が出て、本当の超過が埋もれる (「上限に達した」ことへの気づきは
+ * 警告ではなく「使用量 / 上限」の併記が担う)。
+ */
+
+test('/billing の quotas は 6 キー厳密一致で届く', function (): void {
+    // Inertia props は連想配列なので、DTO rename の波及漏れ (キー名の取りこぼし) は
+    // phpstan / typecheck では捕まらない。キー集合そのものをここで固定する。
+    [, $owner] = createOrganizationWithOwner();
+
+    $this->actingAs($owner)->get('/billing')
+        ->assertOk()
+        ->assertInertia(fn (Assert $page) => $page
+            // 過不足の両方を見る: hasAll で不足を、count で余剰を検出する
+            ->hasAll([
+                'page.quotas.maxProjects',
+                'page.quotas.maxMembers',
+                'page.quotas.maxStorageGb',
+                'page.quotas.projectsUsed',
+                'page.quotas.storageUsedBytes',
+                'page.quotas.exceededLabels',
+            ])
+            ->count('page.quotas', 6));
+});
+
+test('上限内なら exceededLabels は空で、使用量が実値で届く', function (): void {
+    [$organization, $owner] = createOrganizationWithOwner();
+    $project = Project::factory()->forOrganization($organization)->create();
+    $manual = VideoManual::factory()->forProject($project)->create();
+    $cut = Cut::factory()->forManual($manual)->create();
+    Take::factory()->forCut($cut)->create(['size_bytes' => 1_024]);
+
+    $this->actingAs($owner)->get('/billing')
+        ->assertOk()
+        ->assertInertia(fn (Assert $page) => $page
+            ->where('page.quotas.projectsUsed', 1)
+            ->where('page.quotas.storageUsedBytes', 1_024)
+            ->where('page.quotas.exceededLabels', []));
+});
+
+test('上限ちょうど (1/1) では警告を出さない (恒常警告の回帰防止)', function (): void {
+    // personal / starter の max_projects は 1。プロジェクトを 1 つ作った状態は
+    // プランの設計どおりの正常状態であり、超過ではない。
+    [$organization, $owner] = createOrganizationWithOwner();
+    Project::factory()->forOrganization($organization)->create();
+
+    $this->actingAs($owner)->get('/billing')
+        ->assertOk()
+        ->assertInertia(fn (Assert $page) => $page
+            ->where('page.quotas.maxProjects', 1)
+            ->where('page.quotas.projectsUsed', 1)
+            ->where('page.quotas.exceededLabels', []));
+});
+
+test('プロジェクト数が上限を超えていれば exceededLabels に載る', function (): void {
+    // Plan 行を手組みせず、既存の organization_quotas override で超過状態を作る。
+    [$organization, $owner] = createOrganizationWithOwner();
+    Project::factory()->forOrganization($organization)->create();
+    $organization->quota()->create(['limits' => ['max_projects' => 0]]);
+
+    $this->actingAs($owner)->get('/billing')
+        ->assertOk()
+        ->assertInertia(fn (Assert $page) => $page
+            ->where('page.quotas.exceededLabels', ['プロジェクト数']));
+});
+
+test('保存容量が上限を超えていれば exceededLabels に載る', function (): void {
+    [$organization, $owner] = createOrganizationWithOwner();
+    $project = Project::factory()->forOrganization($organization)->create();
+    $manual = VideoManual::factory()->forProject($project)->create();
+    $cut = Cut::factory()->forManual($manual)->create();
+    Take::factory()->forCut($cut)->create(['size_bytes' => 2_000]);
+    $organization->quota()->create(['limits' => ['max_storage_bytes' => 1_000]]);
+
+    $this->actingAs($owner)->get('/billing')
+        ->assertOk()
+        ->assertInertia(fn (Assert $page) => $page
+            ->where('page.quotas.storageUsedBytes', 2_000)
+            ->where('page.quotas.exceededLabels', ['保存容量']));
+});
+
+test('メンバー数は上限のみで、超過次元には決して現れない (未強制の明示)', function (): void {
+    // max_members を 0 に落としてもメンバーは存在する。それでも exceededLabels に
+    // 載らないことで「表示があるのに強制が無い」次元を UI が警告しないことを固定する。
+    [$organization, $owner] = createOrganizationWithOwner();
+    $organization->quota()->create(['limits' => ['max_members' => 0]]);
+
+    $this->actingAs($owner)->get('/billing')
+        ->assertOk()
+        ->assertInertia(fn (Assert $page) => $page
+            ->where('page.quotas.maxMembers', 0)
+            ->where('page.quotas.exceededLabels', []));
+});
diff --git a/tests/Feature/Billing/PlanQuotaCoverageTest.php b/tests/Feature/Billing/PlanQuotaCoverageTest.php
new file mode 100644
index 0000000..51ca92b
--- /dev/null
+++ b/tests/Feature/Billing/PlanQuotaCoverageTest.php
@@ -0,0 +1,36 @@
+<?php
+
+declare(strict_types=1);
+
+use App\Models\Billing\Plan;
+use Database\Seeders\PlanSeeder;
+
+/*
+ * quota 定義欠落の機械的追跡。
+ *
+ * limits の無い plan_code が organizations.plan_code に入ると QuotaService::limits() の
+ * `?? []` により **無制限扱い**になる (静かな課金事故)。現状 config/quota.php には
+ * business / enterprise の entry が無いため、その 2 つを PlanSeeder に足した瞬間に
+ * 本テストが red になり、quota 定義の追加を強制する。
+ *
+ * PlanCode enum 全 case との一致は要求しない: enterprise は問い合わせ営業で
+ * Plan 行も plan_prices も持たず、organizations.plan_code が付く経路が無い。
+ * 追跡すべきは「seed されて実際に plan_code になりうるプラン」だけである。
+ */
+
+test('PlanSeeder が投入する plan code は必ず config/quota.php に limits を持つ', function (): void {
+    // テスト全体で $seed = true のため PlanSeeder は既に走っているが、
+    // 本テストが seeder に依存していることを明示するために明示的に走らせる
+    // (再実行安全な upsert seeder であることは PlanSeeder の docblock が保証する)。
+    $this->seed(PlanSeeder::class);
+
+    $seededCodes = Plan::query()->pluck('code')->all();
+    $configured = array_keys(config()->array('quota.plans'));
+
+    expect($seededCodes)->not->toBeEmpty();
+    foreach ($seededCodes as $code) {
+        expect(in_array($code, $configured, true))->toBeTrue(
+            "PlanSeeder が投入する plan '{$code}' に config/quota.php の limits がありません (無制限扱いになる)",
+        );
+    }
+});
diff --git a/tests/Feature/Billing/QuotaTest.php b/tests/Feature/Billing/QuotaTest.php
index e5f7a53..76632cd 100644
--- a/tests/Feature/Billing/QuotaTest.php
+++ b/tests/Feature/Billing/QuotaTest.php
@@ -64,6 +64,19 @@
     expect($organization->projects()->count())->toBe(1);
 });
 
+test('quota 超過の error flash に回復先の画面名が含まれる', function (): void {
+    // 失敗するのは撮影・プロジェクト作成の現場であり、そこから「どこを見れば現状と上限が
+    // 分かるか」が示されないと詰みになる (/billing は課金ゲートの allowlist 内で到達できる)。
+    [$organization, $owner] = createOrganizationWithOwner();
+    Project::factory()->forOrganization($organization)->create();
+
+    $this->actingAs($owner)
+        ->from('/projects/create')
+        ->post('/projects', ['name' => '2 つ目']);
+
+    expect(session('error'))->toBeString()->toContain('「お支払い」画面');
+});
+
 test('override で上限を上げると超過していた作成が可能になる', function (): void {
     [$organization, $owner] = createOrganizationWithOwner();
     Project::factory()->forOrganization($organization)->create();
diff --git a/tests/Feature/Security/InertiaHistoryGuardTest.php b/tests/Feature/Security/InertiaHistoryGuardTest.php
index f8908a8..388cd09 100644
--- a/tests/Feature/Security/InertiaHistoryGuardTest.php
+++ b/tests/Feature/Security/InertiaHistoryGuardTest.php
@@ -2,6 +2,9 @@
 
 declare(strict_types=1);
 
+use App\Models\User;
+use App\Support\Http\AdminPanelPath;
+use Illuminate\Support\Facades\Hash;
 use Illuminate\Testing\TestResponse;
 
 /*
@@ -12,6 +15,10 @@
  *    `encryptHistory: true` が載る (認証済み / 公開の区別なくグローバル適用)。
  *  - ログアウト応答は Inertia::clearHistory() を発火し、**着地の Inertia 応答**の page に
  *    `clearHistory: true` が載る (着地が非 Inertia 化するとフラグが宙に浮き防御が消える)。
+ *  - **認証失敗 (AuthenticationException) も clearHistory の発行契機である**
+ *    (bootstrap/app.php の render callback)。セッション期限切れ / 他デバイスからの
+ *    強制ログアウトは「利用者が明示的に終わらせた」契機を持たないため、ログアウト応答だけでは
+ *    履歴鍵が残る。積むのは `expectsJson()` 偽 かつ `hasSession()` 真 のときだけ。
  *  - 通常の応答には `clearHistory` が載らない (負のコントロール)。
  *
  * 目的は「ログアウト後の戻る」で Inertia のクライアント履歴から認証済み画面 (PII) が
@@ -144,3 +151,86 @@ function inertiaPagePayload(TestResponse $response): array
 
     expect(inertiaPagePayload($this->get(route('home'))))->toHaveKey('clearHistory', true);
 });
+
+/*
+|--------------------------------------------------------------------------
+| 認証失敗 (AuthenticationException) を契機とする clearHistory (T089-b)
+|--------------------------------------------------------------------------
+|
+| ログアウト応答 (LogoutResponse) が拾えるのは「利用者が明示的に終わらせた」契機だけで、
+| セッション期限切れと、パスワード変更による他デバイスの強制ログアウト
+| (Auth::logoutOtherDevices → web グループの AuthenticateSession) は拾えない。
+| どちらも AuthenticationException として現れるため、bootstrap/app.php の render callback で
+| Inertia::clearHistory() を積み、着地の /login (Inertia 応答) に消費させる。
+|
+| 保証するのは「**認証失敗を契機に、以後の戻るによる復元を無効化する**」ことであり、
+| 過去に遡って無効化するものではない (docs/supported-browsers.md が正本)。
+|
+| フラグは session に積まれ、消費は**次の Inertia 応答**なので、テストは
+| **302 を自動追従させず、別リクエストとして着地を叩く**形でリダイレクト境界ごと固定する
+| (既存のログアウト系テストと同じ書き方)。
+*/
+
+test('未認証 guest の認証失敗でも、着地の Inertia 応答に clearHistory が載る', function (): void {
+    // セッション期限切れ後のリクエストと同じ形 (guest が auth 保護 route を踏む)。
+    $response = $this->get('/dashboard');
+    $response->assertRedirect(route('login'));
+
+    // 別リクエストとして着地を叩く (302 を自動追従させない = 境界そのものを固定する)。
+    $landing = $this->get(route('login'));
+
+    $landing->assertOk();
+    expect(inertiaPagePayload($landing))->toHaveKey('clearHistory', true);
+});
+
+test('他デバイスからの強制ログアウト (AuthenticateSession) で clearHistory が積まれる', function (): void {
+    // 再現手順は tests/Feature/Auth/PasswordUpdateSessionInvalidationTest と同型:
+    // 旧 password_hash を持つセッションのまま保護 route を踏むと AuthenticateSession が logout する。
+    $user = User::factory()->create();
+    $oldHash = $user->getAuthPassword();
+
+    $user->forceFill(['password' => Hash::make('NewPassword12345')])->save();
+
+    $this->actingAs($user)
+        ->withSession(['password_hash_web' => $oldHash])
+        ->get('/dashboard')
+        ->assertRedirect('/login');
+
+    expect(inertiaPagePayload($this->get(route('login'))))->toHaveKey('clearHistory', true);
+});
+
+test('guest が /login を直接開いてもフラグは積まれない (負のコントロール)', function (): void {
+    // 「guest 向け Inertia 応答すべてに clearHistory を載せる」代案は却下済み。
+    // 匿名回遊の戻るが毎回サーバ再取得になり、認証と無関係のトラフィックを恒久的に劣化させる。
+    expect(inertiaPagePayload($this->get(route('login'))))->not->toHaveKey('clearHistory');
+});
+
+test('expectsJson の 401 ではフラグを積まない (負のコントロール)', function (): void {
+    // API / MCP など Inertia 応答が返らない経路で積むと、フラグが宙に浮いて
+    // 後続の無関係な Inertia 応答で消費される。
+    $this->getJson('/dashboard')->assertUnauthorized();
+
+    expect(inertiaPagePayload($this->get(route('home'))))->not->toHaveKey('clearHistory');
+});
+
+test('認証失敗で積まれたフラグは次の Inertia 応答で 1 度だけ消費される', function (): void {
+    // 素の auth 保護 route で発生させる (3rd party の実装差分に契約を依存させない)。
+    $this->get('/dashboard')->assertRedirect(route('login'));
+
+    expect(inertiaPagePayload($this->get(route('home'))))->toHaveKey('clearHistory', true);
+    // pull 済みなので 2 度目には載らない (無関係なページで履歴が飛ぶ事故を防ぐ)。
+    expect(inertiaPagePayload($this->get(route('home'))))->not->toHaveKey('clearHistory');
+});
+
+test('非 Inertia 面 (/admin) の認証失敗でもフラグは積まれる (安全側の偽陽性)', function (): void {
+    // ※ これは**契約テストではなく docblock の主張の裏付け**である。
+    //   bootstrap/app.php の callback は guards() で面を判別しない (Filament の Authenticate は
+    //   override により guards が [] になり、実装詳細に依存する判別になるため)。その結果
+    //   /admin の認証失敗でもフラグが積まれるが、影響は「Inertia 面の履歴が 1 度だけ
+    //   再キーされる」ことだけなので安全側の偽陽性として許容している。
+    //   **Filament の認証失敗の実装が変わったら、本テストと bootstrap/app.php の docblock を
+    //   一緒に更新すること** (テストだけ直して docblock を放置しない)。
+    $this->get('/'.AdminPanelPath::resolve());
+
+    expect(inertiaPagePayload($this->get(route('home'))))->toHaveKey('clearHistory', true);
+});
diff --git a/tests/Unit/Enums/PlanCodeTest.php b/tests/Unit/Enums/PlanCodeTest.php
new file mode 100644
index 0000000..08e1f8a
--- /dev/null
+++ b/tests/Unit/Enums/PlanCodeTest.php
@@ -0,0 +1,38 @@
+<?php
+
+declare(strict_types=1);
+
+use App\Enums\PlanCode;
+
+/*
+ * Stripe 決済対象プランの写像を全 case 網羅で固定する。
+ *
+ * SubscriptionService::assertStripeBillablePlan() は
+ * PlanCode::requiresStripeCheckout() が false のプランを 422 (ValidationException) に倒す。
+ * 「false → 422」という変換自体は SubscriptionPlanChangeTest の personal ケースが固定済みなので、
+ * ここでは**写像側**を全 case で固定し、合成として enterprise / business の穴を埋める。
+ *
+ * Plan Factory は作らない: Plan / PlanPrice は参照データで、真実源は PlanSeeder +
+ * config/quota.php + StripePriceLookupKeys の三点セットである。Factory を足すと
+ * 「seeder と食い違うプラン定義」(quota 定義の無い plan_code、価格の無い有償プラン) を
+ * テストが作れてしまう (docs/factories.md)。
+ */
+
+test('requiresStripeCheckout の写像が全 case で固定されている', function (): void {
+    $expected = [
+        'personal' => false,    // 無料 (PersonalPlanService::activate 経由)
+        'starter' => true,
+        'standard' => true,
+        'business' => true,
+        'enterprise' => false,  // 問い合わせ営業 (Checkout も in-app swap も通らない)
+    ];
+
+    // cases() 由来で網羅する = case 追加時に必ず落ちる
+    expect(array_map(static fn (PlanCode $case): string => $case->value, PlanCode::cases()))
+        ->toEqualCanonicalizing(array_keys($expected));
+
+    foreach (PlanCode::cases() as $case) {
+        expect($case->requiresStripeCheckout())
+            ->toBe($expected[$case->value], "PlanCode::{$case->name} の決済対象判定");
+    }
+});
diff --git a/tests/js/lib/format-bytes.test.ts b/tests/js/lib/format-bytes.test.ts
new file mode 100644
index 0000000..3069ac1
--- /dev/null
+++ b/tests/js/lib/format-bytes.test.ts
@@ -0,0 +1,25 @@
+import { describe, expect, it } from "vitest";
+import { formatBytes } from "@/lib/format-bytes";
+
+/*
+ * バイト数の可読表記 (Dashboard の残容量タイル / 課金画面の quota カードで共有)。
+ * 1024 進法の境界で単位が切り替わることを固定する (表示専用。判定には使わない)。
+ */
+
+describe("formatBytes", () => {
+    it("1 KB 未満はバイトのまま出す", () => {
+        expect(formatBytes(0)).toBe("0 B");
+        expect(formatBytes(1023)).toBe("1023 B");
+    });
+
+    it("1024 の各境界で単位が切り替わる", () => {
+        expect(formatBytes(1024)).toBe("1.0 KB");
+        expect(formatBytes(1024 ** 2)).toBe("1.0 MB");
+        expect(formatBytes(1024 ** 3)).toBe("1.0 GB");
+    });
+
+    it("小数第 1 位まで丸めて表示する", () => {
+        expect(formatBytes(1536)).toBe("1.5 KB");
+        expect(formatBytes(Math.round(2.25 * 1024 ** 3))).toBe("2.3 GB");
+    });
+});
diff --git a/tests/js/pages/Billing/Index.test.ts b/tests/js/pages/Billing/Index.test.ts
index b73812d..ffa3de2 100644
--- a/tests/js/pages/Billing/Index.test.ts
+++ b/tests/js/pages/Billing/Index.test.ts
@@ -37,7 +37,14 @@ const basePage: BillingDashboardProps = {
         activeReservations: 0,
         nextExpireAt: null,
     },
-    quotas: { maxProjects: 1, maxMembers: 3, maxStorageGb: 1 },
+    quotas: {
+        maxProjects: 1,
+        maxMembers: 3,
+        maxStorageGb: 1,
+        projectsUsed: 1,
+        storageUsedBytes: 1024 ** 2,
+        exceededLabels: [],
+    },
     canManageBilling: true,
     continueUrl: null,
     autoRecharge: {
@@ -132,12 +139,55 @@ describe("Billing/Index", () => {
         expect(screen.getByTestId("no-plan-note")).toHaveTextContent("まだプランに契約していません");
     });
 
-    it("quota 上限 (プロジェクト / メンバー / ストレージ) を描画する", () => {
+    it("quota を「使用量 / 上限」で描画する (メンバーは上限のみ)", () => {
         render(Index, { props: { page: basePage } });
 
-        expect(screen.getByTestId("quota-max-projects")).toHaveTextContent("1");
+        expect(screen.getByTestId("quota-max-projects")).toHaveTextContent("1 / 1");
+        // メンバー数は quota として強制されていないため使用量を出さない
         expect(screen.getByTestId("quota-max-members")).toHaveTextContent("3");
-        expect(screen.getByTestId("quota-max-storage")).toHaveTextContent("1 GB");
+        expect(screen.getByTestId("quota-max-storage")).toHaveTextContent("1.0 MB / 1 GB");
+    });
+
+    it("上限が null なら「無制限」と併記する", () => {
+        render(Index, {
+            props: {
+                page: {
+                    ...basePage,
+                    quotas: {
+                        ...basePage.quotas,
+                        maxProjects: null,
+                        maxMembers: null,
+                        maxStorageGb: null,
+                    },
+                },
+            },
+        });
+
+        expect(screen.getByTestId("quota-max-projects")).toHaveTextContent("1 / 無制限");
+        expect(screen.getByTestId("quota-max-members")).toHaveTextContent("無制限");
+        expect(screen.getByTestId("quota-max-storage")).toHaveTextContent("1.0 MB / 無制限");
+    });
+
+    it("exceededLabels が空なら超過 Alert を出さない", () => {
+        render(Index, { props: { page: basePage } });
+
+        expect(screen.queryByTestId("quota-exceeded-alert")).toBeNull();
+    });
+
+    it("exceededLabels が非空なら超過 Alert に次元名と結果を出す", () => {
+        render(Index, {
+            props: {
+                page: {
+                    ...basePage,
+                    quotas: { ...basePage.quotas, exceededLabels: ["プロジェクト数", "保存容量"] },
+                },
+            },
+        });
+
+        const alert = screen.getByTestId("quota-exceeded-alert");
+        expect(alert).toHaveTextContent("プロジェクト数・保存容量");
+        expect(alert).toHaveTextContent("既存のデータは削除されませんが");
+        expect(alert).toHaveTextContent("上限内に収まるまでできません");
     });
 
     it("auto-recharge カードの差し込み位置を持つ (実体は P8a 所管)", () => {
diff --git a/tests/js/pages/Billing/Plans.test.ts b/tests/js/pages/Billing/Plans.test.ts
index cb2868b..295ae59 100644
--- a/tests/js/pages/Billing/Plans.test.ts
+++ b/tests/js/pages/Billing/Plans.test.ts
@@ -162,14 +162,17 @@ describe("Billing/Plans", () => {
 
         await fireEvent.click(screen.getByTestId("plan-change-starter"));
         const dialog = await screen.findByTestId("plan-change-confirm");
-        expect(dialog).toHaveTextContent("上限内に収まるまで新規作成とアップロードができません");
+        expect(dialog).toHaveTextContent("超えている項目に関わる操作が上限内に収まるまでできません");
+        // メンバー数は quota として未強制なので告知に含めない (起きないことを言わない)
+        expect(dialog).not.toHaveTextContent("メンバー数");
+        expect(dialog).toHaveTextContent("「お支払い」画面");
         cleanup();
 
         render(Plans, { props: { page: contractedPage } });
         await fireEvent.click(screen.getByTestId("plan-change-standard"));
         const upgradeDialog = await screen.findByTestId("plan-change-confirm");
         expect(upgradeDialog).not.toHaveTextContent(
-            "上限内に収まるまで新規作成とアップロードができません",
+            "超えている項目に関わる操作が上限内に収まるまでできません",
         );
         expect(upgradeDialog).toHaveTextContent("日割り");
     });
```

## 実装差分 (git diff HEAD — 文書・docblock 側。本タスクの主成果物)

```diff
diff --git a/AGENTS.md b/AGENTS.md
index 3c9715e..04caf31 100644
--- a/AGENTS.md
+++ b/AGENTS.md
@@ -206,9 +206,11 @@ ## ドメイン固有規約
    (B) クライアント bfcache 秘匿・再検証 (`resources/js/lib/bfcache-guard.ts` +
        `session.status` プローブ。撮影 PWA の主戦場 iOS Safari は
        `Cache-Control: no-store` でも bfcache に格納しうるため必須)、
-   (C) Inertia history 暗号化 + ログアウト時の履歴鍵破棄
+   (C) Inertia history 暗号化 + 履歴鍵破棄
        (`bootstrap/app.php` の `Inertia\Middleware\EncryptHistory` +
-        `App\Http\Responses\Fortify\LogoutResponse` の `Inertia::clearHistory()`)。
+        `Inertia::clearHistory()` の発行契機 2 つ =
+        `App\Http\Responses\Fortify\LogoutResponse` (ログアウト) と
+        `bootstrap/app.php` の `AuthenticationException` render callback (認証失敗))。
    (C) の保証条件は「**`clearHistory: true` を含む Inertia page をクライアントが適用したタブ**」。
    ログアウト着地 route を非 Inertia 化しない (`InertiaHistoryGuardTest` が固定) /
    ログアウト導線を非 Inertia 経路 (JSON 204 完結の XHR 等) で新設しない
diff --git a/devnotes/20260717-0035-aigenba-billing-parity/aigenba-divergence-ledger.md b/devnotes/20260717-0035-aigenba-billing-parity/aigenba-divergence-ledger.md
index 2a06dd1..b5ff2f8 100644
--- a/devnotes/20260717-0035-aigenba-billing-parity/aigenba-divergence-ledger.md
+++ b/devnotes/20260717-0035-aigenba-billing-parity/aigenba-divergence-ledger.md
@@ -79,7 +79,7 @@ ## A: AI-CUE に対象が存在しない（返さない）
 | A-3 | `CheckoutIntent` の `CreditPurchase` / `SignupFunding` case を非移植 | チケット決済は AI-CUE では**別テーブル**（`ticket_checkout_sessions`）が担う。campaign 機構が無い |
 | A-4 | `SubscriptionSnapshot` に `currentPeriodStart` を持たない + period 巻き戻し guard 非移植 | **`subscriptions.current_period_start` 列が AI-CUE に無い** |
 | A-5 | `assertCheckoutReady()` 非移植 | AI-CUE の `Organization` に**請求先メール列が無く** Cashier 既定の `stripeEmail()` が常に null → 移植すると checkout/portal が**全 org で throw** する。**P9 で請求先列が入った後に再検討する** |
-| A-6 | `SubscriptionService` の schedule lifecycle / seat / signup funding / `changePlan` / `upgradeNow` / `isMutableState` を非移植 | 設計スコープ外（席・schedule 機構が無い） |
+| A-6 | `SubscriptionService` の schedule lifecycle / seat / signup funding / `upgradeNow` / `isMutableState` を非移植（**`changePlan` は T090 で移植済み**） | 席・schedule 機構が無いため。ただし **`changePlan` を落とす根拠は成立していなかった**（席にも schedule にも依存しない）ので、T090 で `SubscriptionService::changePlan()` として実装した |
 | A-7 | `getStatus()` / `BillingStatusDto` を P2 で作らない | 呼び出し側 UI が P8b 所管 = **dead code を作らない** |
 | A-8 | aigenba の fallback 文言「現在パーソナルプランは選択できません」を非移植 | **D4（禁止事項 #8）とセット**。AI-CUE は disabled にせず**サーバ由来の `reasonLabel` を常時 caption 表示**するため、クライアント側の fallback 文言は不要（文言をフロントで組み立てない） |
 | A-9 | `Onboarding/Checkout.svelte` の `showAllPlans` / 折りたたみ確認画面を非移植 | `preselectFunding` が無い P3 では `showAllPlans` が常に true = **dead code**（intended バッジ・`?choose` は P7 所管） |
diff --git a/docs/architecture.md b/docs/architecture.md
index 7a7a534..fbd5b97 100644
--- a/docs/architecture.md
+++ b/docs/architecture.md
@@ -328,13 +328,35 @@ ## サブスク契約 Checkout とオンボーディング着地 (P7/P9) の運
     (ActiveFreePlan では `free_plan_code` を返す projection) とは別物で、混ぜると
     grace period 契約で恒常 422 になる
   - Stripe への更新は `proration_behavior=create_prorations` (日割りは**次回請求に反映**。
-    `always_invoice` は使わない = 即時請求の与信失敗遷移を持ち込まない)
+    `always_invoice` は使わない = 即時請求の与信失敗遷移を持ち込まない)。
+    **この方針は確定済み**であり、切り替えに必要な作業一式は下記「proration 方針」を参照
+    (機械的な守り手は `tests/Unit/Billing/SubscriptionSwapPayloadInvariantTest`)
   - 冪等は 2 層: 同一 render の二重送信は idempotency key `change-plan:{token}:{planCode}`、
     別 render からの再操作は **gateway の remote Price 照合** (`AlreadyOnTargetPrice` =
     update を送らない)
   - **`organizations.plan_code` は書かない**。反映 (projection_synced) は
     `customer.subscription.updated` → `applySubscriptionSnapshot` が唯一の writer
-  - Customer Portal の `subscription_update` は **無効のまま** (プラン変更はアプリが所有する)
+  - Customer Portal の `subscription_update` は **無効のまま** (プラン変更はアプリが所有する)。
+    再開放に必要な条件は `App\Services\Billing\PortalConfigurationSpec` の docblock が正本
+  - **proration 方針** (確定): `create_prorations` を既定とし、日割り差額は次回請求に反映する。
+    `always_invoice` (即時徴収) へ切り替えるには以下が**セットで**必要であり、
+    「payload の 1 行」では終わらない:
+    1. `CashierStripeGateway::buildSwapPayload()` の変更 + payload invariant テストの更新
+    2. **状態機械の拡張**: `SubscriptionState` に `pending_update` 相当の表現が無い。
+       `incomplete` は現在 `Inactive` に畳まれ、`BillingAccess` → `require-active-subscription` で
+       **アプリ全体が遮断される**。「アップグレードしようとして与信に失敗しただけの利用者」を
+       ロックアウトしない state 設計が先に要る
+    3. **webhook の受け口**: `customer.subscription.pending_update_applied` / `..._expired` と、
+       プラン変更文脈での `invoice.payment_failed` の扱いが `StripeWebhookProcessor` に無い
+    4. **UI**: 3DS/SCA の確認導線がアプリに無い (決済 UI は Stripe hosted の Checkout / Portal のみ)。
+       要アクション状態を受ける画面が要る
+    5. **ロールバック意味論**: `pending_update` 期限切れで Stripe が巻き戻す挙動と
+       `organizations.plan_code` の projection を整合させる規約が要る
+
+    **再検討条件**: 日割り差額の回収遅延がキャッシュフロー上の問題であることを事業側が
+    数値で示したとき。上記 1〜5 を同一 TODO で扱う前提で再設計する
+    (**証拠なく金銭の挙動を反転させない**)。判断の経緯は
+    `devnotes/20260804-0900-t089-t090-residual-risk/` を参照
 - **着地 feedback (P9)**: `Inertia::location()` の full page redirect を跨いだ後、
   `/billing` 着地で one-shot バナーを出す (`BillingFeedbackKind`: purchase_received /
   purchase_processing / purchase_already_received / checkout_retry_required / portal_returned)。
diff --git a/docs/factories.md b/docs/factories.md
index e87c7e3..38968bb 100644
--- a/docs/factories.md
+++ b/docs/factories.md
@@ -46,6 +46,13 @@ ## Factory 一覧 (テンプレート同梱)
 
 Factory を持たないモデル (Role / Permission / Team 等) は seed 固定値
 または Service (`OrganizationProvisioningService` 等) 経由で作る。
+**`Billing\Plan` / `Billing\PlanPrice` も Factory を持たない** — これらは参照データであり、
+真実源は `PlanSeeder` + `config/quota.php` + `StripePriceLookupKeys` の三点セットである。
+Factory を足すとプラン定義の第 2 の真実源ができ、seeder と食い違う組み合わせ
+(quota 定義の無い plan_code、価格の無い有償プラン) をテストが作れてしまう。
+プラン依存の分岐を固定したいときは enum の写像テスト
+(`tests/Unit/Enums/PlanCodeTest.php`) と seeder の網羅テスト
+(`tests/Feature/Billing/PlanQuotaCoverageTest.php`) を使う。
 アプリ内通知 (`notifications` テーブル) は Eloquent 標準 `DatabaseNotification` を使うため
 新規モデル / Factory は作らない (テストでは `$user->notify(new ManualAnalyzedNotification(...))`
 の実発火で行を作る。`AnalysisJob` / `RenderJob` の `triggered_by` は nullable のため
diff --git a/docs/supported-browsers.md b/docs/supported-browsers.md
index d93484b..b87b343 100644
--- a/docs/supported-browsers.md
+++ b/docs/supported-browsers.md
@@ -8,9 +8,12 @@ # サポート対象ブラウザ方針
 
 | 経路 | 担当 | 何を保証するか |
 |------|------|----------------|
-| A: HTTP / disk / proxy cache、Chrome・Firefox の bfcache | `NoStoreCacheHeadersForAuthenticatedPages` | `no-store, private` により格納拒否 / cookie 変更時 evict |
-| B: Safari の真の bfcache (`pagehide` / `pageshow`) | `resources/js/lib/bfcache-guard.ts` + `session.status` プローブ | **描画前に同期秘匿**し、セッション有効なら秘匿解除のみ (hard reload しない) |
-| C: Inertia SPA のクライアント履歴復元 (`popstate`) | `Inertia\Middleware\EncryptHistory` (web グループ) + `App\Http\Responses\Fortify\LogoutResponse` の `Inertia::clearHistory()` | ログアウト後は復号不能 → **コンポーネントを描画しないまま**再問い合わせ → `/login` |
+| A: HTTP / disk / proxy cache、Chrome・Firefox の bfcache | `App\Http\Middleware\NoStoreCacheHeadersForAuthenticatedPages` | `no-store, private` により格納拒否 / cookie 変更時 evict |
+| B: Safari の真の bfcache (`pagehide` / `pageshow`) | `resources/js/lib/bfcache-guard.ts` + `session.status` プローブ (`App\Http\Controllers\Auth\SessionStatusController`) | **描画前に同期秘匿**し、セッション有効なら秘匿解除のみ (hard reload しない) |
+| C: Inertia SPA のクライアント履歴復元 (`popstate`) | `Inertia\Middleware\EncryptHistory` (web グループ) + `Inertia::clearHistory()` の発行契機 2 つ: **ログアウト** (`App\Http\Responses\Fortify\LogoutResponse`) と **認証失敗** (`bootstrap/app.php` の `AuthenticationException` render callback) | 発行契機の後は復号不能 → **コンポーネントを描画しないまま**再問い合わせ → `/login` |
+
+> 経路 B / C の実装は上表の参照点が正本 (将来の差分レビューで担当実装を辿れるよう、
+> 本書では実装ファイルを名指しする)。
 
 経路 C の保証条件は「**`clearHistory: true` を含む Inertia page をクライアントが適用したタブ**」。
 `Inertia::clearHistory()` はサーバ session にフラグを積むだけで、`sessionStorage` の
@@ -23,6 +26,13 @@ # サポート対象ブラウザ方針
 **ログアウト導線を非 Inertia 経路 (JSON 204 で完結する XHR 等) で新設すると、
 この条件が崩れて経路 C の保証が外れる。**
 
+`clearHistory` の発行契機は**ログアウトだけではない**。セッション期限切れと
+他デバイスからの強制ログアウトはどちらも `AuthenticationException` として現れ、
+`bootstrap/app.php` の render callback がそこでもフラグを積む
+(着地の `/login` が Inertia 応答なので確実に消費される)。
+これが保証するのは「**認証失敗を契機に、以後の戻るによる復元を無効化する**」ことであり、
+**過去に遡って無効化するものではない** (保証範囲と保証外は「未対応事項」節に対で書く)。
+
 「対応している」という言葉を検証レベルと切り離さないこと。
 本書では **Current (実際に回っている検証)** と **Target (到達目標)** を分けて書く。
 
@@ -113,14 +123,37 @@ ## 未対応事項 (誤読を防ぐため明示列挙する)
   現行の `/logout` 導線は 3 箇所ともに Inertia visit のため実運用では条件を満たすが、
   非 Inertia のログアウト導線を新設すると保証が外れる
   (`tests/js/architecture/logout-call-site-inventory.test.ts` が deny-by-default で固定)。
-- **上記を満たしたタブ以外は保証外**。Inertia の履歴暗号鍵は
-  `sessionStorage` = タブ単位のため、同一ブラウザの**別タブ**に残った履歴は復号できてしまう。
-  すなわち **別タブでは、現在表示されていない過去の PII が履歴から再表示され得る**
+  ただし **204 で完結したタブも、次に認証を要する Inertia visit を行った時点**で
+  認証失敗契機の `clearHistory` により鍵を失う (保証条件そのものは不変。残存が縮んだだけ)。
+- **別タブに残る Inertia 履歴は保証外 (判断済みで受容する)**。Inertia の履歴暗号鍵は
+  `sessionStorage` = タブ単位のため、同一ブラウザの**別タブ**に残った履歴は復号できてしまう
   (例: タブ B でメンバー一覧を見た後に公開ページへ遷移 → タブ A でログアウト →
-  端末を引き継いだ第三者がタブ B で「戻る」)。塞ぐには全タブへのセッション失効伝播
-  (BroadcastChannel 等) が要るため本件では扱わない。**既知の残存リスク**。
-- **セッション期限切れ / 他デバイスからの強制ログアウトは経路 C の保証外**。
-  ブラウザに `clearHistory` が届かないため鍵が残り、履歴は復号できる。
+  端末を引き継いだ第三者がタブ B で「戻る」)。
+  **塞がない理由**は「自前機構が要るから」ではなく、以下の 3 点:
+  1. 鍵だけ捨てても**そのタブが今表示している PII は消えない**ため効果が薄い
+     (別タブの脅威の主部は「戻るで出る過去の PII」ではなく「今出ている PII」)。
+  2. 効果を出すには別タブの document を落とす必要があり、それは**回収可能な撮影成果を破棄する**。
+     テイクのアップロードは presigned URL で S3 へ直接送るため、セッションが切れていても
+     アップロードは継続でき再ログイン後に finalize できる。撮影を落とさないことは使命に直結する。
+  3. 下記「認証失敗契機の `clearHistory`」により、別タブも**次にサーバと話した時点で**鍵を失う。
+     残る露出は「二度と触られない放置タブ」に限られる。
+  **運用上の補完**: 共有端末では「使い終わったらブラウザを閉じる」運用を案内する
+  (ブラウザセッションが終われば `sessionStorage` ごと消える)。
+  **再検討条件**: セッション失効の push 経路 (Reverb / Echo 等) を別目的で導入したとき /
+  「全デバイスからログアウト」を UI 機能として提供するとき /
+  bug-hunt・実機受入確認で複数タブ運用が実際に観測されたとき。
+- **セッション期限切れ / 他デバイスからの強制ログアウトは、
+  「アプリが認証失敗を検知した以降」の戻るについて保証する** (限定保証)。
+  `bootstrap/app.php` の `AuthenticationException` render callback が `Inertia::clearHistory()` を
+  積み、着地の `/login` (Inertia 応答) が消費する。契約は
+  `tests/Feature/Security/InertiaHistoryGuardTest.php` が固定する。
+  **保証しない範囲**: そのタブが**一度もサーバと話さないまま**戻る場合。
+  このときタブは表示中の画面自体に PII を出しており、塞ぐには push か polling が要るため
+  扱わない (別タブと同じ判断)。
+  **`popstate` ごとの `session.status` プローブは採らない**:
+  (1) 表示中の PII は塞げないため目的を達しない、
+  (2) 通常の戻る/進むに毎回ネットワーク往復と秘匿オーバーレイが入り、プローブ失敗時は
+      「再試行」で操作が塞がれる (現場の不安定な回線で**新しい詰み**を作る)。
 - **非 Inertia 面 (Filament `/admin`) は経路 B / C の保証外**。独自 middleware stack を持ち
   web グループを経由せず、Inertia でも描画されない。
 - **非セキュアコンテキスト (`http://` の LAN IP 等) では経路 C が degrade する**。
```

## テスト結果

- `composer phpstan`: **No errors** (747 files, level 10)
- `pnpm typecheck`: green
- `pnpm lint`: green
- `vendor/bin/pint --test`: main 由来の既存 fail のみ (devnotes/20260804-0900-sop-pdf-mojibake/probe/*.php。本変更と無関係)
- `composer test` / `pnpm test`: 実行中 (他 worktree と同時実行のため load average 39/10core)

## design system 参照 (差分が resources/js/ を含むため)

```
---
version: "1.0"
name: Slate × Blue (Neutral)
description: テンプレート既定のニュートラルテーマ。中立的な青を主役に、無彩のスレートを支配色とする。アプリはこのファイルと tokens.css の値を差し替えてテーマを定義する。
colors:
    primary: "#2563EB"
    primary-hover: "#1D4ED8"
    tertiary: "#0F766E"
    tertiary-hover: "#115E59"
    neutral: "#F4F4F5"
    surface: "#FFFFFF"
    border: "#E4E4E7"
    border-strong: "#A1A1AA"
    text-primary: "#18181B"
    text-secondary: "#52525B"
    success: "#15803D"
    warning: "#B45309"
    danger: "#DC2626"
typography:
    display:
        fontFamily: "Noto Sans JP, sans-serif"
        fontSize: 48px
        fontWeight: 500
        lineHeight: 1.2
        letterSpacing: 0.02em
    h1:
        fontFamily: "Noto Sans JP, sans-serif"
        fontSize: 32px
        fontWeight: 500
        lineHeight: 1.3
        letterSpacing: 0.02em
    h2:
        fontFamily: "Noto Sans JP, sans-serif"
        fontSize: 24px
        fontWeight: 500
        lineHeight: 1.4
    h3:
        fontFamily: "Noto Sans JP, sans-serif"
        fontSize: 18px
        fontWeight: 500
        lineHeight: 1.5
    body:
        fontFamily: "Noto Sans JP, sans-serif"
        fontSize: 16px
        fontWeight: 400
        lineHeight: 1.7
    caption:
        fontFamily: "Noto Sans JP, sans-serif"
        fontSize: 12px
        fontWeight: 400
        lineHeight: 1.5
rounded:
    sm: 4px
    md: 6px
    lg: 8px
spacing:
    xs: 4px
    sm: 8px
    md: 16px
    lg: 24px
```

触れた component 階層: pages/Billing/Index.svelte, pages/Billing/Plans.svelte, pages/Dashboard.svelte (いずれも pages 層)。新規 component は作らず atoms/Alert.svelte + atoms/Card.svelte を組み合わせている。新規 helper は resources/js/lib/format-bytes.ts (component ではない純関数)。
