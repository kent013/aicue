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
5. LLM 呼び出しの Prism 直呼び(`app/Prompts/` の factory → 窓口 (`PromptDefense`) →
   実行単位 (`GuardedPrompt`) の**1 本道のみ**。`PromptGuardrailTest` が
   app/ routes/ database/ config/ bootstrap/ の 5 走査根で検出する)。
   **実行経路を持つ prompt factory は `LlmCallContextData` を必須引数で受け、
   `PromptDefense::load()` へ渡して帰属 (organization / subject) を付ける** — 付け忘れは
   PHPStan level 10 が落とす。帰属の対象を持たない見本 (`ExampleSummaryPrompt`) だけが
   `PromptDefense::loadUnattributed()` を使え、窓口 gate が**この 1 件を名指しで pin** する。
   併せて `PromptUntrustedInputContractTest` の inventory へ**帰属キーを空配列で exempt 登録**する
   (deny-by-default なので exempt にする操作がレビューで必ず見える)。
   欠けると `llm_call_logs.metadata_missing` になり組織別・対象別の費用が出せない
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



あなたは Laravel + Svelte アプリのコードレビュアーである。以下の実装差分を、詳細設計書と突き合わせてレビューせよ。

レビュー観点:
1. 設計との一致性 (差分は詳細設計の施策 1〜3 を満たしているか。設計から意図的に逸脱した箇所は妥当か)
2. 正確性 (vendor Filament v4 / Livewire v3 の挙動を踏まえて、上限到達時の表示是正が両経路で本当に成立するか)
3. PHPStan level 10 適合性 (解析対象は app config database routes)
4. テスト網羅性 (テストファーストで赤を確認済み。過剰な固定・偽緑になっていないか)
5. セキュリティ (流量制限の閾値・鍵・判定順序を変えていないか。エラー表示で情報が漏れないか)
6. アプリの禁止事項・ドメイン規約 5 (流量制限の付与規約) との整合

本件はフロントエンド (Svelte / DESIGN.md / Atomic Design) の変更を含まないため、その観点は対象外である。

出力形式: ファイルごとに判定を述べ、指摘は [Critical] / [Warning] / [Suggestion] に分類する。
最後に全体判定を **APPROVED** または **CHANGES_REQUESTED** で明示せよ。


---


## 背景 (レビューに必要な事実)

- 本タスクは家系 (複数リポジトリ) 共有の機能台帳 lctl の機能 `filament-login-throttle-display`
  (オーナー裁定 AG-017b) の追従実装である。台帳が求める保証は 2 つ:
  (1) 上限到達時に前の要求で付いた入力エラーを画面に残さないこと
  (2) 再開までの残り秒数を含む案内を入力欄の位置に示すこと
- **詳細設計は台帳へ到達できない状態で書かれており** (設計書末尾 §台帳 (lctl) 参照の状況)、
  設計自身が「台帳が読める状態になったら正典の是正形との差分を確認すること」と指示している。
  実装時に台帳を読めたため、次の 2 点を設計から意図的に変更した:
  - 設計の施策 2 は `resetValidation()` のみ (= 保証 (1) だけ) だったが、
    保証 (2) を満たすため `auth.throttle` (残り秒数入り) を表示中の入力欄へ載せる形にした
    (家系の先行実装 laravel-claude-template / aigenba も入力欄へ載せている)
  - 施策 1 のテストのうち上限到達 2 ケースは `assertHasNoFormErrors()` ではなく
    「案内 1 本だけが残っていること」を固定する形に変えた
- 実装前の実測 (赤の確認): 通常経路では上限到達後も前の試行の
  「認証に失敗しました。」が `data.email` に残り続けることを確認した。
  多要素チャレンジ経路では、上限到達の要求で error bag が空になる
  (`$this->form->getState()` の成功で前要求のエラーが消えるため) ことを確認した
  = チャレンジ画面ではトーストしか出ず、入力欄には何の案内も出ない状態だった。
- 検証コマンドはすべて green (composer test 5772 passed / composer phpstan OK /
  pint OK / pnpm lint / typecheck / test 2007 passed / build)。
- 施策 3 の「authenticate() を上書きしたら赤になる」ことは、一時的に上書きを置いて実測済み。


## 詳細設計書

# 詳細設計: filament-login-throttle-ux (機能 filament-login-throttle-display の追従)

## 使命・制約（絶対遵守）

### アプリの使命（North Star）

**AI-CUE** は、現場に既にある**作業手順書(SOP)を起点に**、AI が撮るべきカットを設計した**動画シナリオ**を生成し、そのシナリオを**スマホ(PWA)でナビゲーション撮影**することで、専門知識ゼロの現場作業者でも**標準化されたマニュアル動画**を作れるようにする。

- 「思考ゼロ・編集ゼロ」— 台本作成・撮影判断・編集の 3 ハードルを AI とナビ撮影で肩代わりする。
- 競合(OJT を撮って形式化する tebiki)と異なり、**標準作業を起点に AI が教材設計し撮影を指示する**（撮影者・教える人のスキルに品質を依存させない）。
- 熟練者の暗黙知を動画マニュアルという形式知へ変換する装置（SECI）。

> **v1 スコープ**: 字幕のみ(TTS 後回し) / 撮影は PWA(同一オリジン・セッション認証) / 動画合成は自前 ffmpeg / 単一 Default Project。

### 禁止事項 (AGENTS.md より。設計に効く核)

1. テストなしの実装完了報告 (不変条件は対応する Architecture/Feature テストへの登録まで含めて「実装済み」)
2. PHPStan エラーの widen・baseline 化
3. dev DB への破壊操作
4. `response()->json()` の直書き
5. LLM 呼び出しの Prism 直呼び / 6. prompt 文字列のコード直書き
7. 操作系 POST の応答での `redirect()->intended()`
8. 必須条件未充足を理由にボタンを disabled にする UI
9. Artifact の使用 (成果物はリポジトリ内のファイルとして出力する)

本件に直接効くのは 1 (テスト必須)。4〜7 は該当なし (LLM・JSON 応答・POST 応答を触らない)。

### コーディングルール

- **PHPStan level 10** 必須 (`composer phpstan`。解析対象は `app` `config` `database` `routes`)
- **Pest** (`composer test`)、`RefreshDatabase` はグローバル適用・`--parallel` 実行
- テストデータは Factory 生成 (`AdminUser::factory()`)
- `declare(strict_types=1)` + 日本語コメント
- コードフォーマット: `composer fix` (Pint)
- PHP 8.4 + Laravel 12 + Filament v4 + Livewire v3

## 概念設計リファレンス

`devnotes/20260818-0258-filament-login-throttle-ux/conceptual-design.md` (Codex 概念レビュー Round 3 で APPROVED)

## 施策一覧

| # | 施策名 | 変更ファイル | 優先度 |
|---|--------|------------|--------|
| 1 | 上限到達の表示検査を先に書く (赤の確認) | `tests/Feature/Filament/AdminLoginThrottleDisplayTest.php` (新規) | 高 |
| 2 | 独自ログインページの新設と panel への配線 | `app/Filament/Auth/Login.php` (新規) / `app/Providers/Filament/AdminPanelProvider.php` | 高 |
| 3 | 流量制限免除の前提検査を実使用クラスへ追随させる | `tests/Feature/Security/ThrottleExemptionPremiseTest.php` | 高 |

---

## 施策 1: 上限到達の表示検査を先に書く (赤の確認)

### 変更箇所

- ファイル: `tests/Feature/Filament/AdminLoginThrottleDisplayTest.php` (新規)

### 波及変更

- TypeScript 型定義: なし (Filament 管理画面は Inertia/Svelte を通らない)
- API Resource/DTO: なし
- テストファイル: 本施策そのもの

### 手順 (テストファースト)

**是正後の期待値**を書く。施策 2 を入れる前に実行し、

- 「上限到達の要求で入力エラーが残っていない」を主張する 2 ケース (通常 / 多要素チャレンジ) が**赤**
- 上限前は入力エラーが出るケースは**緑** (現行でも成立)

であることを確認してから施策 2 に進む。

**振る舞いの検査では独自クラスを直接 import しない**。実装前は `App\Filament\Auth\Login` が
存在せず、import するとクラス解決の失敗になって「意図した赤」を観測できないためである。
panel に配線されているクラスを実行時に解決し (`Filament::getPanel('admin')->getLoginRouteAction()`)、
そのクラスに対して検査する。これで**実装前は vendor Login に対して不具合が再現し、
実装後は同じ検査が独自クラスに対して緑になる**。独自クラスへの配線そのものは、
配線検査 (最後のケース) が別途固定する。

### 変更後コード

```php
<?php

declare(strict_types=1);

use App\Models\AdminUser;
use Filament\Facades\Filament;
use Filament\Notifications\Notification;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Route;
use Livewire\Livewire;

/*
|--------------------------------------------------------------------------
| 管理画面ログインの上限到達時の表示 (裁定 AG-017b)
|--------------------------------------------------------------------------
|
| vendor (Filament) の Login は上限到達時に通知を出して早期 return するだけで、
| Livewire は入力エラーを次の要求へ持ち越す。結果、上限に達した画面には
| 直前の試行の入力エラーが残り、実態と食い違う理由を表示し続ける。
| 本テストは「上限に達した要求では持ち越しエラーが残らない」ことを、
| 通常の上限 (authenticate 冒頭) と多要素チャレンジ専用の上限の**両方**で固定する。
|
| 振る舞いの検査は panel に配線されたクラスを実行時に解決して行う
| (独自クラスを import すると、実装前はクラス不在の失敗になり
|  「意図した赤」を観測できないため)。
|
*/

beforeEach(function (): void {
    // panel 解決 (Filament::auth() 等) を admin panel に固定する
    Filament::setCurrentPanel('admin');
    // 上限到達通知の「あと何秒」を決定的にする (減衰は 60 秒)
    $this->freezeTime();
});

/** panel に配線されているログインページのクラス名を返す。 */
function adminLoginPageClass(): string
{
    $loginPage = Filament::getPanel('admin')->getLoginRouteAction();

    expect($loginPage)->toBeString();
    expect(class_exists((string) $loginPage))->toBeTrue();

    return (string) $loginPage;
}

test('通常の上限に達すると前の試行の入力エラーが残らず、上限到達の通知が出る', function (): void {
    AdminUser::factory()->create(['email' => 'admin@example.com']);

    $component = Livewire::test(adminLoginPageClass())
        ->fillForm(['email' => 'admin@example.com', 'password' => 'wrong-password']);

    // vendor の上限は 5 回。5 回目までは従来どおり認証失敗の入力エラーが出る
    foreach (range(1, 5) as $ignored) {
        $component->call('authenticate')->assertHasFormErrors(['email']);
    }

    // 6 回目は上限到達 = 前の試行の入力エラーを残さない
    $component->call('authenticate')->assertHasNoFormErrors();

    Notification::assertNotified(
        __('filament-panels::auth/pages/login.notifications.throttled.title', [
            'seconds' => 60,
            'minutes' => 1,
        ]),
    );
});

test('上限に達する前は従来どおり認証失敗の入力エラーが出る (消しすぎの検出)', function (): void {
    AdminUser::factory()->create(['email' => 'admin@example.com']);

    Livewire::test(adminLoginPageClass())
        ->fillForm(['email' => 'admin@example.com', 'password' => 'wrong-password'])
        ->call('authenticate')
        ->assertHasFormErrors(['email']);
});

test('多要素チャレンジ専用の上限に達しても、持ち越しエラーは残らずチャレンジ状態は保たれる', function (): void {
    $admin = AdminUser::factory()->withMfa()->create(['email' => 'admin@example.com']);

    // 1 回目: 資格情報は正しいので多要素チャレンジへ進む (通常の計上 1 回)
    $component = Livewire::test(adminLoginPageClass())
        ->fillForm(['email' => 'admin@example.com', 'password' => 'password'])
        ->call('authenticate');

    expect($component->get('userUndertakingMultiFactorAuthentication'))->not->toBeNull();

    // 2 回目: 確認コード未入力で送る = 確認コード欄に入力エラーが立つ (通常の計上 2 回)
    // (コードを詐称すると偶然正しい TOTP になりうるため、未入力で確実に落とす)
    $component->call('authenticate')->assertHasErrors(['data.multiFactor.app.code']);

    // 通常側は上限未満のまま、多要素チャレンジ専用の計数だけを上限まで積む
    // (鍵は vendor と同じく認証識別子で組み立てる。主キーとは限らない)
    $challengeKey = "filament-multi-factor-challenge:{$admin->getAuthIdentifier()}";
    while (! RateLimiter::tooManyAttempts($challengeKey, maxAttempts: 5)) {
        RateLimiter::hit($challengeKey);
    }

    // 3 回目: 多要素チャレンジ専用の上限に達する経路。
    // 持ち越しエラーは消え、チャレンジ表示と入力値は保たれる
    $component->call('authenticate')->assertHasNoErrors();

    expect($component->get('userUndertakingMultiFactorAuthentication'))->not->toBeNull();
    expect($component->get('data.email'))->toBe('admin@example.com');

    Notification::assertNotified(
        __('filament-panels::auth/pages/login.notifications.throttled.title', [
            'seconds' => 60,
            'minutes' => 1,
        ]),
    );
});

test('panel のログインページは独自クラスで、ページとして自動発見されていない', function (): void {
    expect(Filament::getPanel('admin')->getLoginRouteAction())
        ->toBe(App\Filament\Auth\Login::class);

    // 独自ログインページを app/Filament/Pages/ 配下に置くと自動発見が
    // 通常ページとして登録し、ここに login を含む route が現れる
    // (置き場所の誤りを検出する。正当なページの追加では赤くしない)
    foreach (Route::getRoutes() as $route) {
        $name = $route->getName();
        if ($name === null || ! str_starts_with($name, 'filament.admin.pages.')) {
            continue;
        }

        expect($name)->not->toContain('login');
    }
});
```

### 実装上の確認点 (実読で裏取り済み)

| 前提 | 根拠 |
|---|---|
| `assertHasFormErrors(['email'])` が `data.email` に対応する | 既存 `tests/Feature/Filament/AdminUserResourceTest.php` が同形で緑 |
| `Notification::assertNotified()` は session 経由で読める | `Notification::send()` が `session()->push('filament.notifications', …)`、`Notifications::mount()` が pull する |
| 通知 title に置換子が無い (ja) | `vendor/filament/filament/resources/lang/ja/auth/pages/login.php` = `ログインの試行回数が多すぎます` |
| 上限 5 / 減衰 60 秒 / 鍵は IP 単位 | `vendor/danharrin/livewire-rate-limiting/src/WithRateLimiting.php` の既定値 + Filament の `rateLimit(5)` |
| `AdminUser::factory()->withMfa()` と既定パスワード `password` が使える | `database/factories/AdminUserFactory.php` |
| `Filament::setCurrentPanel('admin')` は文字列を受ける | `vendor/filament/filament/src/FilamentManager.php` |
| 現在 `filament.admin.pages.*` は dashboard 1 本 | panel の `->pages([Dashboard::class])` + `app/Filament/Pages/` が空 |
| 多要素チャレンジ専用の鍵と上限 | `Login::isMultiFactorChallengeRateLimited()` = `"filament-multi-factor-challenge:{認証識別子}"` (`$user->getAuthIdentifier()`。主キーとは限らないので `getKey()` を使わない) / `maxAttempts: 5` |
| 確認コード未入力で必ず入力エラーになる | `AppAuthentication::getChallengeFormComponents()` の `code` が `->required(…)` (復旧コードが空なら必須) |
| 確認コード欄の状態パス | チャレンジ様式は `statePath('data.multiFactor')` + 提供元 id (`app`) の Group なので `data.multiFactor.app.code` |
| 流量制限の計数はテスト間で共有されない | `phpunit.xml` が `CACHE_STORE=array` を `force="true"` で固定 (store はテストごとに作り直されるアプリに属する) |
| 通知 title に置換子が無い (ja / en とも) | ja `ログインの試行回数が多すぎます` / en `Too many login attempts` |

### テスト計画

- [x] バグ修正の再現テスト: 「上限到達の要求で入力エラーが残らない」を**先に**書いて赤を確認する
      (通常の上限 / 多要素チャレンジ専用の上限の 2 経路)
- [x] 消しすぎの検出 (上限前は入力エラーが出る)
- [x] 多要素チャレンジ状態と入力値の保存
- [x] 置き場所の誤り (自動発見によるページ route) の検出
- [x] 個別の `DatabaseTransactions` は使わない (グローバル `RefreshDatabase`)

### リスク

- 多要素チャレンジのケースは vendor のチャレンジ様式 (`data.multiFactor` / 専用鍵の文字列) に依存する。
  vendor 更新で様式が変わると赤くなるが、**赤くなること自体が正しい合図**である
  (上限到達時の表示が vendor 側の作りに依存しているため)。
  実装時に様式が異なっていた場合は、コードを推測で合わせず実物を読んで直す
- 確認コードを詐称すると偶然正しい TOTP になりうる (`codeWindow(1)` で前後の窓も有効) ため、
  **未入力で `required` を踏ませる**形にしている。この形なら固定 secret と時刻凍結に依存しない
- 入力エラーの検査は**キー (`data.multiFactor.app.code`) まで**固定する。
  規則名まで固定する形 (`['data.multiFactor.app.code' => 'required']`) は、Livewire の
  規則名アサーションが「テスト用の store に validator が登録されていること」に依存し
  (`TestsValidation::failedRules()`)、Filament の schema 検証経路がそれを満たすかは
  実測しないと分からない。**推測で契約を書かない** — 赤の実測時に error bag の実キーと
  failed rule を確認し、規則名まで取れることが分かったら `=> 'required'` へ強化する

---

## 施策 2: 独自ログインページの新設と panel への配線

### 変更箇所

- ファイル: `app/Filament/Auth/Login.php` (新規)
- ファイル: `app/Providers/Filament/AdminPanelProvider.php` (`->login()` の引数、import 1 行)

### 波及変更

- TypeScript 型定義: なし
- API Resource/DTO: なし
- テストファイル: 施策 1・施策 3
- ドキュメント: なし。`docs/template-divergence.md` への登録も**しない**
  (テンプレート構造からの逸脱ではなく、家系の裁定 AG-017b で採用済みの是正の追従であるため)

### 現行コード

```php
// app/Providers/Filament/AdminPanelProvider.php
            ->authGuard('admin')
            ->login()
            ->profile()
```

```php
// vendor/filament/filament/src/Auth/Pages/Login.php (抜粋。変更しない)
    public function authenticate(): ?LoginResponse
    {
        try {
            $this->rateLimit(5);
        } catch (TooManyRequestsException $exception) {
            $this->getRateLimitedNotification($exception)?->send();

            return null;
        }
        …
```

### 変更後コード

```php
<?php

declare(strict_types=1);

namespace App\Filament\Auth;

use DanHarrin\LivewireRateLimiting\Exceptions\TooManyRequestsException;
use Filament\Auth\Pages\Login as BaseLogin;
use Filament\Notifications\Notification;

/**
 * 管理画面 (/admin) のログインページ。
 *
 * vendor (Filament) の Login をそのまま使うと、流量制限の上限に達したときに
 * 「通知を出して早期 return」するだけで済ませるため、直前の試行で入った入力エラー
 * (「認証に失敗しました。」) が Livewire の errors memo 経由で持ち越され、
 * 上限到達と食い違う理由を画面に残し続ける (裁定 AG-017b)。
 *
 * 本クラスは vendor が上限到達時にだけ通す拡張点で、その持ち越しを消すだけを行う。
 * 対象は**通常のログインの上限と、多要素チャレンジ専用の上限の両方**である
 * (vendor はどちらからも同じ拡張点を呼ぶ)。
 * 認証処理・上限値 (5) ・判定順序は vendor 側にそのまま残す (複写しない)。
 *
 * 置き場所を app/Filament/Pages/ にしないのは、panel の自動発見 (discoverPages) が
 * 通常ページとして登録し、/admin 配下に余分なページ route と操作メニュー項目が
 * 生えるためである (自動発見の対象は Resources / Pages / Widgets の 3 つ)。
 */
class Login extends BaseLogin
{
    /**
     * 流量制限の上限に達したときの通知。
     *
     * `get〜` という名前だが、vendor が上限到達時に用意している拡張点はここだけであり、
     * 「上限に達したときにだけ通る」という意味は名前ではなく呼ばれる位置が担っている。
     * 呼び出し元は 2 か所 = authenticate() 冒頭の通常の上限と、
     * 多要素チャレンジ専用の上限 (isMultiFactorChallengeRateLimited) である。
     *
     * `resetValidation()` は error bag を**丸ごと**空にする。どちらの経路でも、
     * ここに達した時点でこの要求が新たに立てたエラーは無いため
     * (通常側は上限評価が検証より前、多要素側はログイン欄の検証を通過して到達する)、
     * 消えるのは前の試行から持ち越された説明だけである。
     */
    protected function getRateLimitedNotification(TooManyRequestsException $exception): ?Notification
    {
        $this->resetValidation();

        return parent::getRateLimitedNotification($exception);
    }
}
```

```php
// app/Providers/Filament/AdminPanelProvider.php
use App\Filament\Auth\Login;
…
            ->authGuard('admin')
            // 上限到達時に前の試行の入力エラーを残さないための独自ログインページ
            ->login(Login::class)
            ->profile()
```

### PHPStan 適合チェック

- [x] 親と**完全一致**のシグネチャ (可視性 `protected` / 引数 `TooManyRequestsException` / 戻り値 `?Notification`)
- [x] 戻り値は `parent::getRateLimitedNotification()` の結果をそのまま返す (自前で組み立てない)
- [x] null 安全: 戻り値の null は vendor 側の `?->send()` が扱う (こちらでは触らない)
- [x] DTO 返却の対象外 (Livewire コンポーネントの vendor 契約)
- [x] `TooManyRequestsException` は Filament の公開メソッドのシグネチャに現れる型なので取り込む。
      `composer.json` へ直接依存としては足さない (Filament が固定する API 面である)

### テスト計画

施策 1 のテストが緑になることをもって確認する (新規テストは足さない)。

### リスク

- **流量制限の鍵が変わる**: 鍵は `livewire-rate-limiter:sha1(コンポーネント名｜メソッド名｜IP)` で、
  コンポーネント名が独自クラス名になる。帰結は反映時に**高々 60 秒ぶんの計数が 1 度 0 に戻る**ことだけ
  (減衰 60 秒・IP 単位)。閾値・鍵の書式規約は変わらず、`RateLimiter::for()` の名前付き制限ではないため
  `RateLimiterKeyConventionTest` の母集団外である。受容する
- **vendor 追随**: Filament が `getRateLimitedNotification()` を廃止 / シグネチャ変更した場合は
  読み込み時に落ちる (無音の劣化にならない)。`authenticate()` 側の上限が消えた場合は
  施策 3 の前提検査が赤で知らせる
- **Livewire の消去 API**: `resetValidation()` は `resetErrorBag()` の別名で、引数なしなら
  error bag を空にする (`vendor/livewire/livewire/src/Features/SupportValidation/HandlesValidation.php`)

---

## 施策 3: 流量制限免除の前提検査を実使用クラスへ追随させる

### 変更箇所

- ファイル: `tests/Feature/Security/ThrottleExemptionPremiseTest.php` (L169-193 付近)

### 背景

`ThrottleCoverageInventoryTest` は `default-livewire.update` (Filament の全 Livewire 操作が
相乗りする単一 endpoint) を「防御は route ではなく component 内にある」という根拠で免除している。
その**前提**を固定しているのが本テストで、現在は **vendor クラスの** `Login::authenticate()` に
`->rateLimit(` があることを走査している。panel が使うクラスが独自クラスへ変わるため、
走査対象を**実際に使われるクラス**へ移さないと、独自クラス側で上限を外しても検査は緑のままになる。

### 現行コード

```php
    $targets = [
        [FilamentLogin::class, 'authenticate'],
        [FilamentEditProfile::class, 'save'],
        …
    ];
```

### 変更後コード

```php
test('default-livewire.update の前提: Filament の credential 操作が component 内で rateLimit を掛けている', function (): void {
    // panel が実際に使うログインページを対象にする (独自クラスへ差し替えても
    // 前提が保たれていることを確かめるため。vendor クラス固定だと、独自クラス側で
    // 上限を外しても緑のままになる)
    $loginPage = Filament::getPanel('admin')->getLoginRouteAction();
    expect($loginPage)->toBeString()->and(class_exists((string) $loginPage))->toBeTrue();

    $targets = [
        [(string) $loginPage, 'authenticate'],
        [FilamentEditProfile::class, 'save'],
        [SetUpAppAuthenticationAction::class, 'make'],
        [DisableAppAuthenticationAction::class, 'make'],
        [RegenerateAppAuthenticationRecoveryCodesAction::class, 'make'],
    ];

    foreach ($targets as [$class, $method]) {
        expect(throttlePremiseMethodRateLimits($class, $method))->toBeTrue(…);
    }

    // ログインページが独自クラスでも、認証処理そのものは vendor の宣言のままであること。
    // 上書きされた瞬間に赤くなる = 閾値・判定順序の複写と、上限を外す改変を検出する
    expect((new ReflectionMethod((string) $loginPage, 'authenticate'))->getDeclaringClass()->getName())
        ->toBe(FilamentLogin::class,
            'ログインページが authenticate() を上書きしています。'
            .'上限値 (5) と判定順序が vendor から複写されていないか確認し、'
            .'複写するなら default-livewire.update の免除根拠を設計し直すこと。');

    // negative control (既存のまま): 走査器が「どのメソッドでも true」になっていないこと
    expect(throttlePremiseMethodRateLimits(FilamentLogin::class, 'mount'))->toBeFalse(…);
});
```

補足: 走査器 `throttlePremiseMethodRateLimits()` は `ReflectionMethod` で本体の
**宣言元ファイルと行**を切り出すため、継承メソッドを子クラス経由で渡しても
vendor の `authenticate()` 本文が走査される (走査器自体は変更しない)。

### 波及変更

- TypeScript 型定義 / API Resource / DTO: なし
- 既存の `use Filament\Auth\Pages\Login as FilamentLogin;` は**残す**
  (negative control と宣言元の期待値で使う)。`Filament\Facades\Filament` の import を足す

### PHPStan 適合チェック

- 本ファイルは `tests/` 配下で PHPStan の解析対象外 (`phpstan.neon` の paths は
  `app` `config` `database` `routes`)。それでも `getLoginRouteAction()` の戻り値
  (`string|Closure|array|null`) は文字列であることを**アサーションで先に確定**してから使う

### テスト計画

- [x] 施策 2 の適用後に緑であること
- [x] negative control (`mount` は false) を残すこと
- [x] 宣言元アサーションが「`authenticate()` を上書きしたら赤」になること
      (実装時に一時的に空の上書きを置いて赤を実測し、消してから完了とする)

### リスク

- `getLoginRouteAction()` が将来 Closure を返す形へ設計変更されたら、このテストは
  アサーションで落ちる (=「実使用クラスを特定できない」ことに気づける。無音にはならない)

---

## セキュリティ不変条件との関係

| 不変条件 | 影響 |
|---|---|
| ドメイン規約 5 (流量制限の付与規約) | **閾値・レーン・鍵の書式規約を変更しない**。`ThrottleCoverageInventoryTest` の目録も変更なし (route 集合が変わらないため) |
| 同 (免除の前提) | `default-livewire.update` の免除**根拠は変わらない**が、前提検査の対象を実使用クラスへ移して強化する (施策 3) |
| ドメイン規約 3 (3 枚セット) | `/admin` は Inertia でも `web` グループでもないため対象外。境界は動かさない |
| セキュリティ不変条件 9 (変更系 route は認可を通る) | route を 1 本も足さない (施策 1 のテストが固定) |
| bug-hunt 目録 (T176) | `web` を宣言しない面なので目録の母集団外。再生成不要 |

## 実装モード

| 項目 | 内容 |
|------|------|
| 推奨モード | standalone |
| 判断根拠 | 変更は新規 1 ファイル + panel の 1 行 + テスト 2 ファイルに閉じ、他施策と依存関係が無い |
| 競合リスク | `AdminPanelProvider` と `ThrottleExemptionPremiseTest` を同時に触る作業があれば行競合の可能性があるが、現在の Open TODO には無い |

## 完了条件

- AGENTS.md の検証コマンドを**すべて**緑にする (フロント無変更は省略の理由にしない):
  `composer test` / `composer phpstan` / `vendor/bin/pint --test` /
  `pnpm lint` / `pnpm typecheck` / `pnpm test` / `pnpm build` /
  `pnpm typecheck:packages` / `pnpm build:packages` / `pnpm test:packages`
- 施策 1 の上限到達 2 ケースが**施策 2 の適用前に赤**であったことを実測して記録する
- 施策 3 の宣言元アサーションが「`authenticate()` を上書きしたら赤」になることを実測する

## 台帳 (lctl) 参照の状況

`get_feature("filament-login-throttle-display")` を 5 回試行したが、いずれも MCP サーバーからの
応答が無く 300 秒で中断した (環境要因)。本設計は裁定 AG-017b の要約と、本リポジトリの HEAD および
vendor の実読だけを根拠にしている。台帳が読める状態になったら正典の是正形との差分を確認すること。

## レビュー記録

| フェーズ | モデル | 結果 |
|---|---|---|
| 概念設計 | `gpt-5.6-terra` (medium) | Round 3 で **APPROVED** (`conceptual-review-round-{1,2,3}.md`) |
| 詳細設計 | `gpt-5.6-sol` (high) | Round 3 で **APPROVED** (`detailed-review-round-{1,2,3}.md`) |

対応マトリクスと送信プロンプトは `codex-history/` 配下。


## 実装差分 (git diff)

```diff
diff --git a/app/Filament/Auth/Login.php b/app/Filament/Auth/Login.php
new file mode 100644
index 0000000..a70d939
--- /dev/null
+++ b/app/Filament/Auth/Login.php
@@ -0,0 +1,98 @@
+<?php
+
+declare(strict_types=1);
+
+namespace App\Filament\Auth;
+
+use DanHarrin\LivewireRateLimiting\Exceptions\TooManyRequestsException;
+use Filament\Auth\Pages\Login as BaseLogin;
+use Filament\Notifications\Notification;
+use Webmozart\Assert\Assert;
+
+/**
+ * 管理画面 (/admin) のログインページ。
+ *
+ * vendor (Filament) の Login をそのまま使うと、試行の上限に達したときに
+ * 「通知を出して早期 return」するだけで済ませる。Livewire は入力エラーを次の要求へ
+ * 持ち越すため、上限に達した画面には直前の試行の理由 (「認証に失敗しました。」) が
+ * 残り続け、運営者には「正しい資格情報なのに違うと言われ続ける」と見える。
+ * 通知 (トースト) も、上限到達がいつ明けるのかを入力欄の位置では示さない。
+ *
+ * 本クラスは vendor が上限到達時にだけ通す拡張点で、次の 2 つだけを行う
+ * (機能台帳 filament-login-throttle-display / 裁定 AG-017b が求める保証):
+ *   (1) 前の要求で付いた入力エラーを捨てる
+ *   (2) 再開までの残り秒数を含む案内を、いま表示されている入力欄の位置に出す
+ *
+ * 対象は**通常のログインの上限と、多要素チャレンジ専用の上限の両方**である
+ * (vendor はどちらからも同じ拡張点を呼ぶ)。認証処理・上限値 (5 回) ・減衰 (60 秒) ・
+ * 判定順序・鍵は vendor 側にそのまま残す (複写しない)。
+ *
+ * 置き場所を app/Filament/Pages/ にしないのは、panel の自動発見 (discoverPages) が
+ * 通常ページとして登録し、/admin 配下に余分なページ route が生えるためである。
+ *
+ * 注意: 本クラスを配線すると流量制限の鍵 (`livewire-rate-limiter:sha1(クラス名|メソッド名|IP)`)
+ * のクラス名部分が変わる。帰結は反映時に高々 60 秒ぶんの計数が 1 度 0 に戻ることだけで、
+ * 閾値・鍵の書式規約は変わらない (`RateLimiter::for()` の名前付き制限ではない)。
+ */
+class Login extends BaseLogin
+{
+    /**
+     * 上限到達の案内を載せる入力欄 (資格情報の画面)。
+     */
+    private const EMAIL_FIELD = 'data.email';
+
+    /**
+     * 上限到達の案内を載せる入力欄 (多要素チャレンジの画面)。
+     *
+     * vendor と同じ状態パスの規約: チャレンジ様式は `data.multiFactor` を statePath に持ち、
+     * その下に提供元 id (`app` = AppAuthentication) の Group が入る。
+     * admin panel の多要素の提供元は AppAuthentication 1 つだけである
+     * (AdminPanelProvider::panel())。提供元を増やすときは本定数の選び方を見直すこと。
+     */
+    private const CHALLENGE_CODE_FIELD = 'data.multiFactor.app.code';
+
+    /**
+     * 流量制限の上限に達したときの通知。
+     *
+     * `get〜` という名前だが、vendor が上限到達時に用意している拡張点はここだけであり、
+     * 「上限に達したときにだけ通る」という意味は名前ではなく呼ばれる位置が担っている。
+     * 呼び出し元は 2 か所 = `authenticate()` 冒頭の通常の上限と、
+     * 多要素チャレンジ専用の上限 (`isMultiFactorChallengeRateLimited()`) である。
+     *
+     * `resetErrorBag()` は error bag を**丸ごと**空にする。どちらの経路でも、ここに達した
+     * 時点でこの要求が新たに立てたエラーは無いため (通常側は上限の評価が検証より前、
+     * 多要素側はログイン欄の検証を通過してから上限の評価に入る)、消えるのは前の試行から
+     * 持ち越された説明だけである。
+     */
+    protected function getRateLimitedNotification(TooManyRequestsException $exception): ?Notification
+    {
+        // vendor の TooManyRequestsException は残り秒数を型宣言の無い public プロパティで渡す。
+        // 呼び出し元 2 か所はどちらも RateLimiter::availableIn() (int) を渡すが、表示のためだけの
+        // 値でここを例外にすると、上限到達中の画面が 500 になって表示の不具合が可用性の事故へ
+        // 化ける。数値でなければ 0 秒として扱う (fail-soft)。
+        $seconds = is_numeric($exception->secondsUntilAvailable)
+            ? max(0, (int) $exception->secondsUntilAvailable)
+            : 0;
+
+        $message = __('auth.throttle', ['seconds' => $seconds]);
+        Assert::string($message);
+
+        $this->resetErrorBag();
+        $this->addError($this->throttledFieldKey(), $message);
+
+        return parent::getRateLimitedNotification($exception);
+    }
+
+    /**
+     * いま表示されている様式に合わせて、案内を載せる入力欄を選ぶ。
+     *
+     * vendor は多要素チャレンジ中 (`userUndertakingMultiFactorAuthentication` が埋まっている間)
+     * はログイン様式を隠してチャレンジ様式を出すため、同じ判定で「利用者が見ている欄」を選べる。
+     */
+    private function throttledFieldKey(): string
+    {
+        return filled($this->userUndertakingMultiFactorAuthentication)
+            ? self::CHALLENGE_CODE_FIELD
+            : self::EMAIL_FIELD;
+    }
+}
diff --git a/app/Providers/Filament/AdminPanelProvider.php b/app/Providers/Filament/AdminPanelProvider.php
index ca4bad0..4e8bf7e 100644
--- a/app/Providers/Filament/AdminPanelProvider.php
+++ b/app/Providers/Filament/AdminPanelProvider.php
@@ -4,6 +4,7 @@
 
 namespace App\Providers\Filament;
 
+use App\Filament\Auth\Login;
 use App\Filament\Support\LocalInitialsAvatarProvider;
 use Filament\Auth\MultiFactor\App\AppAuthentication;
 use Filament\Http\Middleware\Authenticate;
@@ -39,7 +40,8 @@ public function panel(Panel $panel): Panel
             ->id('admin')
             ->path('admin')
             ->authGuard('admin')
-            ->login()
+            // 上限到達時に前の試行の入力エラーを残さず、残り秒数の案内を出す独自ログインページ
+            ->login(Login::class)
             ->profile()
             // MFA (TOTP)。運用者アカウントは既定で必須 (config/admin.php)
             ->multiFactorAuthentication([
diff --git a/tests/Feature/Filament/AdminLoginThrottleDisplayTest.php b/tests/Feature/Filament/AdminLoginThrottleDisplayTest.php
new file mode 100644
index 0000000..41d6703
--- /dev/null
+++ b/tests/Feature/Filament/AdminLoginThrottleDisplayTest.php
@@ -0,0 +1,166 @@
+<?php
+
+declare(strict_types=1);
+
+use App\Filament\Auth\Login;
+use App\Models\AdminUser;
+use Filament\Facades\Filament;
+use Filament\Notifications\Notification;
+use Illuminate\Support\Facades\RateLimiter;
+use Illuminate\Support\Facades\Route;
+use Livewire\Livewire;
+
+/*
+|--------------------------------------------------------------------------
+| 管理画面ログインの上限到達時の表示 (裁定 AG-017b)
+|--------------------------------------------------------------------------
+|
+| vendor (Filament) の Login は上限に達すると通知を出して早期 return するだけで、
+| Livewire は入力エラーを次の要求へ持ち越す。結果、上限に達した画面には
+| 直前の試行の入力エラー (「認証に失敗しました。」) が残り、実態と食い違う理由を
+| 表示し続ける。
+|
+| 台帳 (機能 filament-login-throttle-display) が求める保証は 2 つで、
+| 本テストはその両方を、通常の上限 (authenticate 冒頭) と
+| 多要素チャレンジ専用の上限の**両方の経路**で固定する:
+|   (1) 前の要求で付いた入力エラーを画面に残さないこと
+|   (2) 再開までの残り秒数を含む案内を入力欄の位置に示すこと
+|
+| 振る舞いの検査は、独自クラスを名指しせず panel に配線されたクラスを実行時に解決して行う
+| (実装前は vendor の Login に対して不具合が再現し、実装後は同じ検査が独自クラスに対して
+|  緑になる = 「意図した赤」を観測できる)。配線そのものは最後の検査が別途固定する
+| (`Login::class` は名前を字句として解決するだけでクラスの読み込みを起こさないため、
+|  実装前でも「配線されていない」という赤として観測できる)。
+|
+*/
+
+beforeEach(function (): void {
+    // panel 解決 (Filament::auth() 等) を admin panel に固定する
+    Filament::setCurrentPanel('admin');
+    // 上限到達の「あと何秒」を決定的にする (減衰は 60 秒)
+    $this->freezeTime();
+});
+
+/** panel に配線されているログインページのクラス名を返す。 */
+function adminLoginPageClass(): string
+{
+    $loginPage = Filament::getPanel('admin')->getLoginRouteAction();
+
+    expect($loginPage)->toBeString();
+    expect(class_exists((string) $loginPage))->toBeTrue();
+
+    return (string) $loginPage;
+}
+
+/** 上限到達時に入力欄へ出す案内 (残り秒数入り)。 */
+function adminLoginThrottleMessage(int $seconds = 60): string
+{
+    $message = __('auth.throttle', ['seconds' => $seconds]);
+
+    expect($message)->toBeString();
+
+    return (string) $message;
+}
+
+test('通常の上限に達すると、前の試行の入力エラーが残り秒数入りの案内へ差し替わる', function (): void {
+    AdminUser::factory()->create(['email' => 'admin@example.com']);
+
+    $component = Livewire::test(adminLoginPageClass())
+        ->fillForm(['email' => 'admin@example.com', 'password' => 'wrong-password']);
+
+    // vendor の上限は 5 回。5 回目までは従来どおり認証失敗の入力エラーが出る
+    foreach (range(1, 5) as $ignored) {
+        $component->call('authenticate')->assertHasErrors(['data.email']);
+    }
+
+    // 6 回目は上限到達 = 前の試行の入力エラーを捨て、残り秒数の案内へ差し替える
+    $component->call('authenticate')
+        ->assertHasErrors(['data.email' => adminLoginThrottleMessage()]);
+
+    $errors = $component->errors();
+
+    // (1) 前の試行の理由 (認証に失敗しました。) が残っていないこと
+    expect($errors->get('data.email'))
+        ->not->toContain(__('filament-panels::auth/pages/login.messages.failed'));
+    // 案内は 1 本だけ (差し替えであって積み増しではない)
+    expect($errors->get('data.email'))->toHaveCount(1);
+    expect(array_keys($errors->toArray()))->toBe(['data.email']);
+
+    Notification::assertNotified(
+        __('filament-panels::auth/pages/login.notifications.throttled.title', [
+            'seconds' => 60,
+            'minutes' => 1,
+        ]),
+    );
+});
+
+test('上限に達する前は従来どおり認証失敗の入力エラーが出る (消しすぎの検出)', function (): void {
+    AdminUser::factory()->create(['email' => 'admin@example.com']);
+
+    $component = Livewire::test(adminLoginPageClass())
+        ->fillForm(['email' => 'admin@example.com', 'password' => 'wrong-password'])
+        ->call('authenticate')
+        ->assertHasErrors(['data.email']);
+
+    expect($component->errors()->get('data.email'))
+        ->toContain(__('filament-panels::auth/pages/login.messages.failed'));
+});
+
+test('多要素チャレンジ専用の上限でも、持ち越しエラーが案内へ差し替わりチャレンジ状態は保たれる', function (): void {
+    $admin = AdminUser::factory()->withMfa()->create(['email' => 'admin@example.com']);
+
+    // 1 回目: 資格情報は正しいので多要素チャレンジへ進む (通常の計上 1 回)
+    $component = Livewire::test(adminLoginPageClass())
+        ->fillForm(['email' => 'admin@example.com', 'password' => 'password'])
+        ->call('authenticate');
+
+    expect($component->get('userUndertakingMultiFactorAuthentication'))->not->toBeNull();
+
+    // 2 回目: 確認コード未入力で送る = 確認コード欄に入力エラーが立つ (通常の計上 2 回)
+    // (コードを詐称すると偶然正しい TOTP になりうるため、未入力で確実に落とす)
+    $component->call('authenticate')->assertHasErrors(['data.multiFactor.app.code']);
+
+    // 通常側は上限未満のまま、多要素チャレンジ専用の計数だけを上限まで積む
+    // (鍵は vendor と同じく認証識別子で組み立てる。主キーとは限らない)
+    $challengeKey = "filament-multi-factor-challenge:{$admin->getAuthIdentifier()}";
+    while (! RateLimiter::tooManyAttempts($challengeKey, maxAttempts: 5)) {
+        RateLimiter::hit($challengeKey);
+    }
+
+    // 3 回目: 多要素チャレンジ専用の上限に達する経路。
+    // 持ち越しエラーは案内へ差し替わり、チャレンジ表示と入力値は保たれる
+    $component->call('authenticate')
+        ->assertHasErrors(['data.multiFactor.app.code' => adminLoginThrottleMessage()]);
+
+    $errors = $component->errors();
+
+    expect($errors->get('data.multiFactor.app.code'))->toHaveCount(1);
+    expect(array_keys($errors->toArray()))->toBe(['data.multiFactor.app.code']);
+
+    expect($component->get('userUndertakingMultiFactorAuthentication'))->not->toBeNull();
+    expect($component->get('data.email'))->toBe('admin@example.com');
+
+    Notification::assertNotified(
+        __('filament-panels::auth/pages/login.notifications.throttled.title', [
+            'seconds' => 60,
+            'minutes' => 1,
+        ]),
+    );
+});
+
+test('panel のログインページは独自クラスで、ページとして自動発見されていない', function (): void {
+    expect(Filament::getPanel('admin')->getLoginRouteAction())
+        ->toBe(Login::class);
+
+    // 独自ログインページを app/Filament/Pages/ 配下に置くと自動発見が
+    // 通常ページとして登録し、ここに login を含む route が現れる
+    // (置き場所の誤りを検出する。正当なページの追加では赤くしない)
+    foreach (Route::getRoutes() as $route) {
+        $name = $route->getName();
+        if ($name === null || ! str_starts_with($name, 'filament.admin.pages.')) {
+            continue;
+        }
+
+        expect($name)->not->toContain('login');
+    }
+});
diff --git a/tests/Feature/Security/ThrottleExemptionPremiseTest.php b/tests/Feature/Security/ThrottleExemptionPremiseTest.php
index 1dc006d..d55bc90 100644
--- a/tests/Feature/Security/ThrottleExemptionPremiseTest.php
+++ b/tests/Feature/Security/ThrottleExemptionPremiseTest.php
@@ -10,6 +10,7 @@
 use Filament\Auth\MultiFactor\App\Actions\SetUpAppAuthenticationAction;
 use Filament\Auth\Pages\EditProfile as FilamentEditProfile;
 use Filament\Auth\Pages\Login as FilamentLogin;
+use Filament\Facades\Filament;
 use GuzzleHttp\Handler\MockHandler;
 use GuzzleHttp\HandlerStack;
 use GuzzleHttp\Middleware;
@@ -172,8 +173,16 @@ function throttlePremiseMethodRateLimits(string $class, string $method): bool
 test('default-livewire.update の前提: Filament の credential 操作が component 内で rateLimit を掛けている', function (): void {
     // panel が公開する credential 面 (login / profile / MFA 管理) の**実行メソッド**に
     // rate limit があること。1 つでも消えたら route 側の防御を設計し直す必要がある。
+    //
+    // login は panel が実際に使うクラスを実行時に解決して対象にする (独自ログインページへ
+    // 差し替えても前提が保たれていることを確かめるため。vendor クラス固定だと、独自クラス側で
+    // 上限を外しても緑のままになる)。
+    $loginPage = Filament::getPanel('admin')->getLoginRouteAction();
+    expect($loginPage)->toBeString();
+    expect(class_exists((string) $loginPage))->toBeTrue();
+
     $targets = [
-        [FilamentLogin::class, 'authenticate'],
+        [(string) $loginPage, 'authenticate'],
         [FilamentEditProfile::class, 'save'],
         [SetUpAppAuthenticationAction::class, 'make'],
         [DisableAppAuthenticationAction::class, 'make'],
@@ -187,6 +196,16 @@ function throttlePremiseMethodRateLimits(string $class, string $method): bool
         );
     }
 
+    // ログインページが独自クラスでも、認証処理そのものは vendor の宣言のままであること。
+    // 上書きされた瞬間に赤くなる = 上限値 (5) と判定順序の複写や、上限を外す改変を検出する。
+    expect((new ReflectionMethod((string) $loginPage, 'authenticate'))->getDeclaringClass()->getName())
+        ->toBe(
+            FilamentLogin::class,
+            'ログインページが authenticate() を上書きしています。'
+            .'上限値 (5) と判定順序が vendor から複写されていないか確認し、'
+            .'複写するなら default-livewire.update の免除根拠を設計し直すこと。',
+        );
+
     // negative control: 走査器が「どのメソッドでも true」になっていないこと
     // (常に true を返す検査は deny-by-default を無意味にする)
     expect(throttlePremiseMethodRateLimits(FilamentLogin::class, 'mount'))->toBeFalse(

```