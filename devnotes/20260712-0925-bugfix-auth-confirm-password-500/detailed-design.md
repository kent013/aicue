# 詳細設計: bugfix-auth-confirm-password-500 (F-11)

## 使命・制約（絶対遵守）

### アプリの使命（North Star）

**AI-CUE** は、現場に既にある**作業手順書(SOP)を起点に**、AI が撮るべきカットを設計した**動画シナリオ**を生成し、そのシナリオを**スマホ(PWA)でナビゲーション撮影**することで、専門知識ゼロの現場作業者でも**標準化されたマニュアル動画**を作れるようにする。

- 「思考ゼロ・編集ゼロ」— 台本作成・撮影判断・編集の 3 ハードルを AI とナビ撮影で肩代わりする。
- 競合(OJT を撮って形式化する tebiki)と異なり、**標準作業を起点に AI が教材設計し撮影を指示する**（撮影者・教える人のスキルに品質を依存させない）。
- 熟練者の暗黙知を動画マニュアルという形式知へ変換する装置（SECI）。

### 禁止事項

1. テストなしの実装完了報告(不変条件は対応する Architecture/Feature テストへの登録まで含めて「実装済み」)
2. PHPStan エラーの widen(型を緩めて黙らせる)・baseline 化
3. dev DB への破壊操作(`migrate:fresh` 等)をエージェント判断で実行すること
4. `response()->json()` の直書き(DTO / JsonResource / Inertia を使う。仕様固定 endpoint のみ例外)
5. LLM 呼び出しの Prism 直呼び(`app/Prompts/` の factory 経由のみ。PromptGuardrailTest が検出)
6. prompt 文字列のコード直書き(`resources/prompts/*.yaml` に置く)
7. 操作系 POST の応答での `redirect()->intended()`(ログイン直後フロー専用。招待送信等は `back()->with(...)` で完結させる)
8. 必須条件未充足を理由にボタンを disabled にする UI(押下時にエラー表示する。DESIGN.md)

### コーディングルール

- **PHPStan level 10** 必須（`composer phpstan`）
- **Pest**テストフレームワーク（`composer test`）
- **RefreshDatabase** + `--parallel` 並列実行（`tests/Pest.php` でグローバル適用、個別 `DatabaseTransactions` 使用禁止）
- **テストデータは必ずFactoryで生成**（`Model::create()` 手組み禁止）
- 新モデルを追加する設計では **対応するFactoryの作成も施策に含める** こと
- **DTO + JsonResource** パターン（AGENTS.md参照）
- **アーリーリターン** 推奨
- **コードフォーマット**: `composer fix`（Pint）/ `pnpm lint:fix`
- PHP 8.4 + Laravel 12 + Svelte 5 + Inertia.js + TypeScript

## 概念設計リファレンス

[devnotes/20260712-0925-bugfix-auth-confirm-password-500/conceptual-design.md](./conceptual-design.md)
（Codex 概念レビュー Round 1 で APPROVED。Warning 3 件対応済み）

## 根本原因（確定・再現済み）

- Fortify は `config('fortify.views') === true` のとき、feature フラグ
  (`twoFactorAuthentication.confirmPassword => false`) に関係なく
  `GET /user/confirm-password`（`password.confirm`）を無条件登録する
  （`vendor/laravel/fortify/routes/routes.php` L118-121）。
- `ConfirmablePasswordController::show()` は `app(ConfirmPasswordViewResponse::class)` を解決するが、
  この contract は `Fortify::confirmPasswordView()` を呼んだときにのみ bind され、
  Fortify の `registerResponseBindings()` に default binding は存在しない。
- 本アプリは step-up を generic recent-auth（`/recent-auth/confirm`）へ統一した際に
  `confirmPasswordView` を意図的に未登録とした（`FortifyServiceProvider.php` L107-108 のコメント）。
- 結果、直アクセスで
  `BindingResolutionException: Target [Laravel\Fortify\Contracts\ConfirmPasswordViewResponse] is not instantiable`
  → 500。tinker で contract 解決を実行し例外を確認済み（セッション状態に依存しない決定的クラッシュ。
  shard-report の「intended URL 未設定」仮説は誤り）。

## 施策一覧

| # | 施策名 | 変更ファイル | 優先度 |
|---|--------|------------|--------|
| 1 | 再現 Feature テスト追加（テストファースト） | `tests/Feature/Auth/RecentAuthTest.php` | High |
| 2 | `confirmPasswordView` に recent-auth への救済 redirect を登録 | `app/Providers/FortifyServiceProvider.php` | High |

## 施策1: 再現 Feature テスト追加（テストファースト）

### 変更箇所
- ファイル: `tests/Feature/Auth/RecentAuthTest.php`（`/* ---- middleware */` セクション末尾、
  L98 付近の confirm 画面テスト群の直前に「fortify password.confirm 互換」ブロックとして追加）

### 波及変更
- TypeScript型定義: なし
- API Resource/DTO: なし
- テストファイル: 本施策自体がテスト追加。既存テストの変更なし

### 追加テストコード

```php
/* ------------------------------------------- fortify password.confirm 救済 redirect */

test('GET /user/confirm-password 直アクセスは recent-auth confirm へ 302 (500 にしない)', function (): void {
    $user = User::factory()->create();

    $response = $this->actingAs($user)->get('/user/confirm-password');

    $response->assertRedirect(route('recent-auth.confirm'));
});

test('GET /user/confirm-password は追従すると 200 で ConfirmRecentAuth フォームが出る', function (): void {
    $user = User::factory()->create();

    $response = $this->actingAs($user)->followingRedirects()->get('/user/confirm-password');

    $response->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('Auth/ConfirmRecentAuth')
            ->where('passwordSet', true)
            ->where('canSatisfy', true));
});

test('GET /user/confirm-password は未ログインなら login へ redirect (既存 auth ガード)', function (): void {
    $this->get('/user/confirm-password')->assertRedirect(route('login'));
});

test('GET /user/confirm-password の救済 redirect は再認証の stamp をしない', function (): void {
    // 誤用防止の回帰ガード: この redirect は「画面への誘導」であり、password.confirm
    // middleware 互換 (auth.password_confirmed_at) も recent-auth 鮮度 (recent_auth_at) も
    // 付与しない (Codex 詳細レビュー Round 1 Warning 対応)。
    $user = User::factory()->create();

    $response = $this->actingAs($user)->get('/user/confirm-password');

    $response->assertRedirect(route('recent-auth.confirm'))
        ->assertSessionMissing('auth.password_confirmed_at')
        ->assertSessionMissing('recent_auth_at');
});
```

- 実装前に 1・2 本目が **500 で fail** することを確認してから施策2に着手する（fail-first）。
- 3 本目は Fortify の `auth` middleware による既存挙動の固定（回帰ガード）で、実装前から green の想定。
- 4 本目は「GET は救済 redirect であり stamp をしない」ことの固定（middleware 互換と
  誤認した実装が入った場合に fail する誤用防止ガード）。
- SSO-only ユーザーの詰み回避（`availableProviders` 提示）は既存テスト
  「confirm 画面は passwordSet / availableProviders / canSatisfy を返す」（同ファイル L100）が
  誘導先画面を保証済みのため、本施策では redirect 先の同一性のみ検証し重複させない。

### PHPStan適合チェック
- [x] テストは既存 Pest スタイル（closure + `$this->actingAs()`）に従う
- [x] Factory 使用（`User::factory()->create()`）、`Model::create()` 手組みなし
- [x] 個別 `DatabaseTransactions` 不使用（Pest.php のグローバル RefreshDatabase）

### テスト計画
- [x] バグ修正の再現テストを先に書く（上記 1・2 本目。500 fail を確認）
- [x] 既存テスト `tests/Feature/Auth/RecentAuthTest.php` への追記（削除・上書きなし）
- [x] 新規テスト4本 — 直アクセス302 / 追従200フォーム表示 / 未ログインガード /
      救済 redirect が stamp しないこと（誤用防止）

### リスク
- なし（テスト追加のみ）

## 施策2: `confirmPasswordView` に recent-auth への救済 redirect を登録

### 変更箇所
- ファイル: `app/Providers/FortifyServiceProvider.php`
  - use 節（L16 付近）: `Illuminate\Http\RedirectResponse` を追加
  - `configureViews()` L107-109: 「confirmPasswordView は登録しない」コメントを
    redirect 登録 + 意図説明コメントに置換

### 波及変更
- TypeScript型定義: なし（新規ページ・props なし。誘導先 `Auth/ConfirmRecentAuth` は既存のまま）
- API Resource/DTO: なし
- テストファイル: 施策1で追加（本施策とセット）
- ルート定義: 変更なし（Fortify 登録ルートをそのまま使い、view response のみ差し替え）

### 現行コード

```php
// app/Providers/FortifyServiceProvider.php L107-110
        // password.confirm (Fortify 生 step-up) は generic recent-auth に置換済みのため
        // confirmPasswordView は登録しない (確認画面は Auth/ConfirmRecentAuth)。

        Fortify::twoFactorChallengeView(static fn (): InertiaResponse => Inertia::render('Auth/TwoFactorChallenge'));
```

### 変更後コード

```php
// use 節に追加
use Illuminate\Http\RedirectResponse;
```

```php
// app/Providers/FortifyServiceProvider.php configureViews() 内
        // password.confirm (Fortify 生 step-up) は generic recent-auth に置換済み。
        // ただし fortify.views=true の間は GET /user/confirm-password が Fortify により
        // 無条件登録され、ConfirmPasswordViewResponse 未 bind だと直アクセスが
        // BindingResolutionException で 500 になる (bug-hunt F-11)。正規の確認画面
        // (recent-auth.confirm、password or 再SSO) へ 302 で誘導する。
        // 注意: これは GET view の救済 redirect であり、`password.confirm` middleware 互換
        // (auth.password_confirmed_at の充足) は提供しない。middleware 互換が必要になったら
        // 別途設計すること (config/fortify.php の TODO(template) 参照)。
        Fortify::confirmPasswordView(
            static fn (): RedirectResponse => redirect()->route('recent-auth.confirm'),
        );

        Fortify::twoFactorChallengeView(static fn (): InertiaResponse => Inertia::render('Auth/TwoFactorChallenge'));
```

### 設計メモ（機構の確認結果）

- `Fortify::confirmPasswordView($view)` は `ConfirmPasswordViewResponse` contract を
  `SimpleViewResponse($view)` として singleton bind する（`vendor/laravel/fortify/src/Fortify.php` L198）。
- `SimpleViewResponse::toResponse()` は `$view` が callable の場合それを呼び、戻り値が
  `Responsable` でなければそのまま返す。`RedirectResponse` は Symfony Response 派生のため
  そのまま HTTP 302 として返る（vendor 実装確認済み）。
- closure は **リクエスト時に評価**されるため、`redirect()->route('recent-auth.confirm')` の
  route 解決が boot 順序に依存する問題はない。
- 誘導先 `recent-auth.confirm`（`ConfirmRecentAuthController::show()`）は `auth` +
  `EnsureEmailIsVerified` 等の既存 middleware 構成のもとで Inertia ページ
  `Auth/ConfirmRecentAuth` を 200 で返す（既存テストで保証済み）。
  直アクセス時は `url.intended` 未設定のため、再認証完了後は既存契約どおり
  `redirect()->intended(route('dashboard'))` で dashboard へ遷移する（新規実装不要）。
- 禁止事項7（操作系 POST での `redirect()->intended()`）は本件に非該当:
  追加するのは GET view response の 302 であり intended を消費しない。

### PHPStan適合チェック
- [x] closure の戻り値型 `RedirectResponse` を明示（level 10 で closure 型推論が閉じる）
- [x] null 安全: 引数なし・セッション非依存のため該当分岐なし
- [x] DTO 返却の論点なし（HTML 302 redirect。JSON 応答なし）
- [x] Generics の型パラメータ: 該当なし

### テスト計画
- [x] 施策1のテスト3本が green になること（fail→green の遷移を確認）
- [x] 既存の `tests/Feature/Auth/RecentAuthTest.php` 全テスト green（recent-auth 本体の回帰なし）
- [x] 既存の `tests/Feature/Auth/FortifyResponseTest.php` green（他 Fortify response 差し替えの回帰なし）
- [x] `composer test`（並列全件）/ `composer phpstan` / `vendor/bin/pint --test` green
- [x] フロント変更なしのため `pnpm test` / `pnpm typecheck` は既存 green の維持確認のみ

### リスク
- **`password.confirm` middleware への影響**: 本アプリのルート・middleware alias に
  `password.confirm` の利用箇所はない（grep 確認済み。passkeys feature も未有効のため
  `passkeys.management_middleware` 経由の利用もなし）。将来利用時の誤認はコード内コメントで防止。
- **`POST /user/confirm-password`（`password.confirm.store`）**: 本修正の対象外。既存どおり
  password 検証のうえ Fortify 独自の `auth.password_confirmed_at` を stamp するのみで、
  本アプリの gate（`recent_auth_at`）へは影響しない（`RecentAuthWindow` は
  `recent_auth_at` のみ参照）。挙動変更なし。
- **回帰面**: 変更は「未 bind だった contract を bind する」だけで、既存の bind 済み
  contract・ルート・画面に触れない。回帰面は実質ゼロ。

## 実装モード

| 項目 | 内容 |
|------|------|
| 推奨モード | incremental |
| 判断根拠 | 変更が Provider 1 ファイル + テスト 1 ファイルの極小差分で、他施策・他画面との結合がない。standalone にする理由（大規模リファクタ・長期並走）がない |
| 競合リスク | `FortifyServiceProvider::configureViews()` を触る他タスクが同時進行しない限りなし。config/fortify.php の TODO(template)（2FA 管理ルートの recent-auth 後付け配線）を別タスクで実施する場合も本変更とは独立 |
