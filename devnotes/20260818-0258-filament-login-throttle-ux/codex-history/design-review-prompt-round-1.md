## アプリの使命・禁止事項 (AGENTS.md より)

## 使命 (North Star)

<!-- app-codex-review スキルはこのセクションをレビュー使命として参照する。 -->

**AI-CUE** は、現場に既にある**作業手順書(SOP)を起点に**、AI が撮るべきカットを設計した**動画シナリオ**を生成し、そのシナリオを**スマホ(PWA)でナビゲーション撮影**することで、専門知識ゼロの現場作業者でも**標準化されたマニュアル動画**を作れるようにする。

- 「思考ゼロ・編集ゼロ」— 台本作成・撮影判断・編集の 3 ハードルを AI とナビ撮影で肩代わりする。
- 競合(OJT を撮って形式化する tebiki)と異なり、**標準作業を起点に AI が教材設計し撮影を指示する**（撮影者・教える人のスキルに品質を依存させない）。
- 熟練者の暗黙知を動画マニュアルという形式知へ変換する装置（SECI）。

> 詳細な設計は本リポジトリの `doc/`（01〜10）に置く。ドメインをテンプレート構造へどうマップしたかは `doc/08`〜`doc/10`（特に `doc/10 §10.8` に設計レビュー反映済み）。
> **v1 スコープ**: 字幕のみ(TTS 後回し) / 撮影は PWA(同一オリジン・セッション認証) / 動画合成は自前 ffmpeg / 単一 Default Project。

## 思考原則

1. **フレームワークのレンジ内でやる**。自前機構の前に Laravel / 同梱モジュールの公式作法を確認する
2. **今必要なものだけ作る**(オーバーエンジニアリング禁止。「あったら便利」は作らない)
3. **後方互換の並走を残さない**。書き換えると決めたら同じ PR で旧実装を消す
4. **別物の概念を「似ているから」で統合しない**
5. **テストファースト**。fail を確認してから実装に入る
6. **タコツボ実装を避ける**。各ステップで他要素との結合観点を確認する

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

---

あなたは経験豊富なWebアプリケーションアーキテクトです。Laravel + Filament アプリケーション改善の詳細設計をレビューしてください。

【前提環境】
- PHP 8.4 + Laravel 12 + Filament v4 (管理画面 /admin) + Livewire v3 + Inertia.js/Svelte 5 (一般利用者面)
- PHPStan level 10 (解析対象は app/ config/ database/ routes/。tests/ は対象外)
- Pest テストフレームワーク (RefreshDatabase グローバル適用・--parallel)
- DTO + JsonResource パターン
- Laratrust RBAC

【レビュー観点】
1. コードの正確性（ロジックエラー、エッジケース、null 安全性）
2. 既存コードとの整合性（命名規約、パターン、API）
3. PHPStan level 10 適合性
4. テスト計画の網羅性（各施策に Pest テスト）
5. DTO/JsonResource パターンの遵守
6. Inertia Props vs API Response の使い分け
7. 副作用・後退リスク
8. 波及変更の網羅性
9. セキュリティ（認可チェック、入力バリデーション、OWASP Top 10、AGENTS.md のセキュリティ不変条件・流量制限の付与規約）
10. UI/frontend 変更を含む場合の DESIGN.md / Atomic Design 準拠（本件は Filament 管理画面のみで Svelte 側の変更は無い想定）

【出力形式】
- 各施策ごとに判定: APPROVE / REQUEST_CHANGES
- 指摘は [Critical] [Warning] [Suggestion] で分類
- Critical/Warning には必ず修正案を添える
- 全体判定: APPROVED / CHANGES_REQUESTED
- 日本語で出力

---

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

- 「6 回目 (上限到達) で入力エラーが残っていない」を主張するケースが**赤**
- 上限前は入力エラーが出る / 多要素チャレンジの状態が保たれる、の各ケースは**緑** (現行でも成立)

であることを確認してから施策 2 に進む。

### 変更後コード

```php
<?php

declare(strict_types=1);

use App\Filament\Auth\Login;
use App\Models\AdminUser;
use Filament\Facades\Filament;
use Filament\Notifications\Notification;
use Illuminate\Support\Facades\Route;
use Livewire\Livewire;

/*
|--------------------------------------------------------------------------
| 管理画面ログインの上限到達時の表示 (裁定 AG-017b)
|--------------------------------------------------------------------------
|
| vendor (Filament) の Login は上限到達時に通知を出して早期 return するだけで、
| Livewire は入力エラーを次の要求へ持ち越す。結果、上限に達した画面には
| 直前の試行の「認証に失敗しました。」が残り、実態と食い違う理由を表示し続ける。
| 本テストは「上限に達した要求では持ち越しエラーが残らない」ことを固定する。
|
*/

beforeEach(function (): void {
    // panel 解決 (Filament::auth() 等) を明示的に admin panel に固定する
    Filament::setCurrentPanel('admin');
    // 上限到達通知の「あと何秒」を決定的にする (減衰は 60 秒)
    $this->freezeTime();
});

test('上限に達すると前の試行の入力エラーが残らず、上限到達の通知が出る', function (): void {
    AdminUser::factory()->create(['email' => 'admin@example.com']);

    $component = Livewire::test(Login::class)
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

    Livewire::test(Login::class)
        ->fillForm(['email' => 'admin@example.com', 'password' => 'wrong-password'])
        ->call('authenticate')
        ->assertHasFormErrors(['email']);
});

test('多要素チャレンジ表示中に上限へ達しても、チャレンジ表示と入力値は保たれる', function (): void {
    AdminUser::factory()->withMfa()->create(['email' => 'admin@example.com']);

    // 1 回目: 資格情報は正しいので多要素チャレンジへ進む (この時点で計上 1 回)
    $component = Livewire::test(Login::class)
        ->fillForm(['email' => 'admin@example.com', 'password' => 'password'])
        ->call('authenticate');

    expect($component->get('userUndertakingMultiFactorAuthentication'))->not->toBeNull();

    // 2〜5 回目: 誤った確認コードを送る (毎回 authenticate() = 計上される)
    $component->set('data.multiFactor.app.code', '000000');
    foreach (range(2, 5) as $ignored) {
        $component->call('authenticate')->assertHasErrors();
    }

    // 6 回目: 上限到達。持ち越しエラーは消え、チャレンジ表示と入力値は保たれる
    $component->call('authenticate')->assertHasNoErrors();

    expect($component->get('userUndertakingMultiFactorAuthentication'))->not->toBeNull();
    expect($component->get('data.email'))->toBe('admin@example.com');
});

test('panel のログインページは独自クラスで、余分なページ route を生やしていない', function (): void {
    expect(Filament::getPanel('admin')->getLoginRouteAction())->toBe(Login::class);

    $pageRoutes = [];
    foreach (Route::getRoutes() as $route) {
        $name = $route->getName();
        if ($name !== null && str_starts_with($name, 'filament.admin.pages.')) {
            $pageRoutes[$name] = true;
        }
    }
    $pageRoutes = array_keys($pageRoutes);
    sort($pageRoutes);

    // 独自ログインページを app/Filament/Pages/ 配下に置くと自動発見が
    // 通常ページとして登録し、ここに login が増える (= 置き場所の誤りを検出する)
    expect($pageRoutes)->toBe(['filament.admin.pages.dashboard']);
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

### テスト計画

- [x] バグ修正の再現テスト: 「6 回目に入力エラーが残らない」を**先に**書いて赤を確認する
- [x] 消しすぎの検出 (上限前は入力エラーが出る)
- [x] 多要素チャレンジ状態の保存
- [x] 置き場所の誤り (自動発見による余分な route) の検出
- [x] 個別の `DatabaseTransactions` は使わない (グローバル `RefreshDatabase`)

### リスク

- 多要素チャレンジのケースは vendor のチャレンジ様式 (`data.multiFactor.app.code`) に依存する。
  vendor 更新で様式が変わると赤くなるが、**赤くなること自体が正しい合図**である
  (上限到達時の表示が vendor 側の作りに依存しているため)。
  実装時に様式が異なっていた場合は、コードを推測で合わせず実物を読んで直す

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
     * vendor は authenticate() の**先頭**で上限を評価するため、この要求ではまだ
     * 検証を 1 つも走らせていない = 消えるのは前の試行から持ち越された説明だけである。
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

- `composer test` / `composer phpstan` / `vendor/bin/pint --test` が緑
  (フロントは変更しないが、規約どおり `pnpm lint` / `pnpm typecheck` / `pnpm test` も通す)
- 施策 1 のテストが**施策 2 の適用前に赤**であったことを実測して記録する

## 台帳 (lctl) 参照の状況

`get_feature("filament-login-throttle-display")` を 5 回試行したが、いずれも MCP サーバーからの
応答が無く 300 秒で中断した (環境要因)。本設計は裁定 AG-017b の要約と、本リポジトリの HEAD および
vendor の実読だけを根拠にしている。台帳が読める状態になったら正典の是正形との差分を確認すること。


## 関連する現行コード

### app/Providers/Filament/AdminPanelProvider.php

```php
<?php

declare(strict_types=1);

namespace App\Providers\Filament;

use App\Filament\Support\LocalInitialsAvatarProvider;
use Filament\Auth\MultiFactor\App\AppAuthentication;
use Filament\Http\Middleware\Authenticate;
use Filament\Http\Middleware\AuthenticateSession;
use Filament\Http\Middleware\DisableBladeIconComponents;
use Filament\Http\Middleware\DispatchServingFilamentEvent;
use Filament\Pages\Dashboard;
use Filament\Panel;
use Filament\PanelProvider;
use Filament\Support\Colors\Color;
use Filament\Widgets\AccountWidget;
use Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\View\Middleware\ShareErrorsFromSession;

/**
 * Filament 管理画面 (/admin)。
 *
 * - 認証は admin guard (AdminUser モデル)。一般ユーザーの web guard とは分離する
 * - MFA (TOTP) は既定で必須 (ADMIN_MFA_REQUIRED=false で local / CI のみ無効化可)
 * - リソース / ページ / ウィジェットは app/Filament/ 配下から自動発見
 * - ダッシュボードは既定 widget のみ (アプリ固有の集計はアプリ側で追加する)
 */
class AdminPanelProvider extends PanelProvider
{
    public function panel(Panel $panel): Panel
    {
        return $panel
            ->default()
            ->id('admin')
            ->path('admin')
            ->authGuard('admin')
            ->login()
            ->profile()
            // MFA (TOTP)。運用者アカウントは既定で必須 (config/admin.php)
            ->multiFactorAuthentication([
                AppAuthentication::make()
                    ->recoverable()
                    ->recoveryCodeCount(8)
                    ->codeWindow(1),
            ], isRequired: (bool) config('admin.mfa_required', true))
            ->brandName(static fn (): string => config()->string('app.name'))
            ->colors([
                'primary' => Color::Slate,
            ])
            // 既定 UiAvatarsProvider の外部 ui-avatars.com への氏名送出を避け、ローカル initials avatar を使う
            ->defaultAvatarProvider(LocalInitialsAvatarProvider::class)
            ->discoverResources(in: app_path('Filament/Resources'), for: 'App\Filament\Resources')
            ->discoverPages(in: app_path('Filament/Pages'), for: 'App\Filament\Pages')
            ->pages([
                Dashboard::class,
            ])
            ->discoverWidgets(in: app_path('Filament/Widgets'), for: 'App\Filament\Widgets')
            ->widgets([
                AccountWidget::class,
            ])
            ->middleware([
                EncryptCookies::class,
                AddQueuedCookiesToResponse::class,
                StartSession::class,
                AuthenticateSession::class,
                ShareErrorsFromSession::class,
                PreventRequestForgery::class,
                SubstituteBindings::class,
                DisableBladeIconComponents::class,
                DispatchServingFilamentEvent::class,
            ])
            ->authMiddleware([
                Authenticate::class,
            ]);
    }
}
```

### vendor/filament/filament/src/Auth/Pages/Login.php (抜粋: authenticate / MFA 上限 / 通知)

```php
        $this->form->fill();
    }

    public function authenticate(): ?LoginResponse
    {
        try {
            $this->rateLimit(5);
        } catch (TooManyRequestsException $exception) {
            $this->getRateLimitedNotification($exception)?->send();

            return null;
        }

        $data = $this->form->getState();

        /** @var SessionGuard $authGuard */
        $authGuard = Filament::auth();

        $authProvider = $authGuard->getProvider(); /** @phpstan-ignore-line */
        $credentials = $this->getCredentialsFromFormData($data);
        $remember = $data['remember'] ?? false;
        $timeboxDuration = (int) config('auth.timebox_duration', 200_000);

        $user = app(Timebox::class)->call(function (Timebox $timebox) use ($authProvider, $authGuard, $credentials, $remember): Authenticatable {
            $this->fireAttemptingEvent($authGuard, $credentials, $remember);

            $user = $authProvider->retrieveByCredentials($credentials);

            if ((! $user) || (! $authProvider->validateCredentials($user, $credentials))) {
                $this->userUndertakingMultiFactorAuthentication = null;

                $this->fireFailedEvent($authGuard, $user, $credentials);
                $this->throwFailureValidationException();
            }

            $timebox->returnEarly();

            return $user;
        }, $timeboxDuration);

        $needsMultiFactorChallenge = app(Timebox::class)->call(function (Timebox $timebox) use ($user): bool {
            if (
                filled($this->userUndertakingMultiFactorAuthentication) &&
                (decrypt($this->userUndertakingMultiFactorAuthentication) === $user->getAuthIdentifier())
            ) {
                if ($this->isMultiFactorChallengeRateLimited($user)) {
                    return true;
                }

                $this->multiFactorChallengeForm->validate();

                return false;
            }

            foreach (Filament::getMultiFactorAuthenticationProviders() as $multiFactorAuthenticationProvider) {
                if (! $multiFactorAuthenticationProvider->isEnabled($user)) {
                    continue;
                }

                $this->userUndertakingMultiFactorAuthentication = encrypt($user->getAuthIdentifier());

                if ($multiFactorAuthenticationProvider instanceof HasBeforeChallengeHook) {
                    $multiFactorAuthenticationProvider->beforeChallenge($user);
                }

                break;
            }

            if (filled($this->userUndertakingMultiFactorAuthentication)) {
                $this->multiFactorChallengeForm->fill();

                return true;
            }

            return false;
        }, $timeboxDuration);

        if ($needsMultiFactorChallenge) {
            return null;
        }

        if (! $authGuard->attemptWhen($credentials, function (Authenticatable $user): bool {
            if (! ($user instanceof FilamentUser)) {
                return true;
            }

            return $user->canAccessPanel(Filament::getCurrentOrDefaultPanel());
        }, $remember)) {
            $this->fireFailedEvent($authGuard, $user, $credentials);
            $this->throwFailureValidationException();
        }

        session()->regenerate();

        return app(LoginResponse::class);
    }

    protected function isMultiFactorChallengeRateLimited(Authenticatable $user): bool
    {
        $rateLimitingKey = "filament-multi-factor-challenge:{$user->getAuthIdentifier()}";

        if (RateLimiter::tooManyAttempts($rateLimitingKey, maxAttempts: 5)) {
            $this->getRateLimitedNotification(new TooManyRequestsException(
                static::class,
                'authenticate',
                request()->ip(),
                RateLimiter::availableIn($rateLimitingKey),
            ))?->send();

            return true;
        }

        RateLimiter::hit($rateLimitingKey);

        return false;
    }

    protected function getRateLimitedNotification(TooManyRequestsException $exception): ?Notification
    {
        return Notification::make()
            ->title(__('filament-panels::auth/pages/login.notifications.throttled.title', [
```

### vendor/danharrin/livewire-rate-limiting/src/WithRateLimiting.php

```php
<?php

namespace DanHarrin\LivewireRateLimiting;

use DanHarrin\LivewireRateLimiting\Exceptions\TooManyRequestsException;
use Illuminate\Support\Facades\RateLimiter;

trait WithRateLimiting
{
    protected function clearRateLimiter($method = null, $component = null)
    {
        $method ??= debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, limit: 2)[1]['function'];

        $component ??= static::class;

        $key = $this->getRateLimitKey($method, $component);

        RateLimiter::clear($key);
    }

    protected function getRateLimitKey($method, $component = null)
    {
        $method ??= debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, limit: 2)[1]['function'];

        $component ??= static::class;

        return 'livewire-rate-limiter:'.sha1($component.'|'.$method.'|'.request()->ip());
    }

    protected function hitRateLimiter($method = null, $decaySeconds = 60, $component = null)
    {
        $method ??= debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, limit: 2)[1]['function'];

        $component ??= static::class;

        $key = $this->getRateLimitKey($method, $component);

        RateLimiter::hit($key, $decaySeconds);
    }

    protected function rateLimit($maxAttempts, $decaySeconds = 60, $method = null, $component = null)
    {
        $method ??= debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, limit: 2)[1]['function'];

        $component ??= static::class;

        $key = $this->getRateLimitKey($method, $component);

        if (RateLimiter::tooManyAttempts($key, $maxAttempts)) {
            $ip = request()->ip();
            $secondsUntilAvailable = RateLimiter::availableIn($key);

            throw new TooManyRequestsException($component, $method, $ip, $secondsUntilAvailable);
        }

        $this->hitRateLimiter($method, $decaySeconds, $component);
    }
}
```

### vendor/livewire/livewire/src/Features/SupportValidation/SupportValidation.php

```php
<?php

namespace Livewire\Features\SupportValidation;

use Livewire\Drawer\Utils;
use Illuminate\Validation\ValidationException;
use Illuminate\Support\ViewErrorBag;
use Livewire\ComponentHook;

class SupportValidation extends ComponentHook
{
    function hydrate($memo)
    {
        $this->component->setErrorBag(
            $memo['errors'] ?? []
        );
    }

    function render($view, $data)
    {
        $errors = (new ViewErrorBag)->put('default', $this->component->getErrorBag());

        $revert = Utils::shareWithViews('errors', $errors);

        return function () use ($revert) {
            // After the component has rendered, let's revert our global
            // sharing of the "errors" variable with blade views...
            $revert();
        };
    }

    function renderIsland($name, $view, $data)
    {
        $errors = (new ViewErrorBag)->put('default', $this->component->getErrorBag());

        $revert = Utils::shareWithViews('errors', $errors);

        return function () use ($revert) {
            $revert();
        };
    }

    function dehydrate($context)
    {
        $errors = $this->component->getErrorBag()->toArray();

        // Only persist errors that were born from properties on the component
        // and not from custom validators (Validator::make) that were run.
        $context->addMemo('errors', collect($errors)
            ->filter(function ($value, $key) {
                return Utils::hasProperty($this->component, $key);
            })
            ->toArray()
        );
    }

    function exception($e, $stopPropagation)
    {
        if (! $e instanceof ValidationException) return;

        $this->component->setErrorBag($e->validator->errors());

        $stopPropagation();
    }
}
```

### tests/Feature/Security/ThrottleExemptionPremiseTest.php (抜粋: 前提検査と走査器)

```php

/*
 * `default-livewire.update` (ComponentLevelLimiter) の前提。
 *
 * 「防御は route ではなく component 内にある」という主張は、Filament 側の
 * `$this->rateLimit(...)` が実在することに全面的に依存している。vendor 更新で消えると
 * **広い Livewire POST が無防備なまま inventory は通り続ける** (deny-by-default の最悪失敗)。
 */
/**
 * 指定メソッドの**本体**に `->rateLimit(...)` 呼び出しがあるか (token 走査)。
 *
 * ファイル全体の文字列検索では、コメント化 / 別メソッドへの移動 / 文字列リテラル中の記述でも
 * 合格してしまう (deny-by-default では誤合格が最悪の失敗モード)。
 * ReflectionMethod で**対象メソッドの本体だけ**を切り出し、コメント / 文字列を
 * token 段階で除去してから `-> rateLimit (` の並びを探す。
 *
 * @param  class-string  $class
 */
function throttlePremiseMethodRateLimits(string $class, string $method): bool
{
    $reflection = new ReflectionMethod($class, $method);
    $file = $reflection->getFileName();
    if ($file === false) {
        return false;
    }
    $lines = file($file);
    if ($lines === false) {
        return false;
    }

    $start = $reflection->getStartLine();
    $end = $reflection->getEndLine();
    if ($start === false || $end === false) {
        return false;
    }

    $ignored = [T_COMMENT, T_DOC_COMMENT, T_CONSTANT_ENCAPSED_STRING, T_ENCAPSED_AND_WHITESPACE, T_WHITESPACE];
    $tokens = [];
    foreach (token_get_all('<?php '.implode('', array_slice($lines, $start - 1, $end - $start + 1))) as $token) {
        if (is_array($token)) {
            if (! in_array($token[0], $ignored, true)) {
                $tokens[] = $token[1];
            }

            continue;
        }
        $tokens[] = $token;
    }

    $count = count($tokens);
    for ($i = 0; $i < $count - 2; $i++) {
        if ($tokens[$i] === '->' && $tokens[$i + 1] === 'rateLimit' && $tokens[$i + 2] === '(') {
            return true;
        }
    }

    return false;
}

test('default-livewire.update の前提: Filament の credential 操作が component 内で rateLimit を掛けている', function (): void {
    // panel が公開する credential 面 (login / profile / MFA 管理) の**実行メソッド**に
    // rate limit があること。1 つでも消えたら route 側の防御を設計し直す必要がある。
    $targets = [
        [FilamentLogin::class, 'authenticate'],
        [FilamentEditProfile::class, 'save'],
        [SetUpAppAuthenticationAction::class, 'make'],
        [DisableAppAuthenticationAction::class, 'make'],
        [RegenerateAppAuthenticationRecoveryCodesAction::class, 'make'],
    ];

    foreach ($targets as [$class, $method]) {
        expect(throttlePremiseMethodRateLimits($class, $method))->toBeTrue(
            "{$class}::{$method}() から component 内 rate limit が消えています。"
            .'default-livewire.update の exemption 根拠が崩れているため、route 側の防御を設計し直すこと。',
        );
    }

    // negative control: 走査器が「どのメソッドでも true」になっていないこと
    // (常に true を返す検査は deny-by-default を無意味にする)
    expect(throttlePremiseMethodRateLimits(FilamentLogin::class, 'mount'))->toBeFalse(
        '走査器がメソッド本体を絞れていません (ファイル全体を見ている可能性)',
    );
});

test('default-livewire.update の前提: panel が公開する auth ページの集合が変わっていない', function (): void {
    // 新しい credential ページ (register / password-reset 等) が有効化されると
    // exemption の射程が黙って広がる。集合を固定して再検討を強制する。
    // multi-factor-authentication.set-up-required は AppAuthentication (TOTP) の
    // セットアップ画面で、実操作は SetUp/Disable/Regenerate の各 Action が担う
    // (それらの rateLimit は上のテストが固定している)。
    $expected = [
        'filament.admin.auth.login',
        'filament.admin.auth.logout',
        'filament.admin.auth.multi-factor-authentication.set-up-required',
        'filament.admin.auth.profile',
    ];

    $actual = [];
    foreach (Route::getRoutes() as $route) {
        $name = $route->getName();
        if ($name !== null && str_starts_with($name, 'filament.admin.auth.')) {
            $actual[$name] = true;
        }
    }
    $actual = array_keys($actual);
    sort($actual);
    sort($expected);

    expect($actual)->toBe($expected,
        'Filament panel が公開する auth ページの集合が変わりました。'
        .'default-livewire.update の exemption は「公開される credential 面が component 内で'
        .'有界化されている」ことに依存するため、増えたページの rate limit を確認してから集合を更新すること。');
});
```

### tests/Architecture/ThrottleCoverageInventoryTest.php (抜粋: default-livewire.update の免除理由)

```php
            .'表明であり本体処理へ到達しない。'],

        'logout' => [$teardown,
            'auth:web 必須。セッション破棄と Inertia::clearHistory() のみを行い、'
            .'推測可能な秘密を一切扱わないため失敗しても攻撃者が得る情報が無い。'],

        'filament.admin.auth.logout' => [$teardown,
            'Filament panel の logout。認証済みでのみ到達でき、セッション破棄以外の副作用が無い。'
            .'秘密の推測に使えないため連打しても攻撃者の利得が無い。'],

        'debug.login-as' => [$localOnly,
            'routes/web.php の if (app()->isLocal() || app()->runningUnitTests()) により'
            .'**production では route 登録自体が起きない** (testing では登録されるため母集団に現れる)。'
            .'加えて LocalOnly middleware (local 以外 404 + Basic 認証 + 未設定 404) が二重防御。'],

        'default-livewire.update' => [$component,
            'Filament 管理画面の全 Livewire 操作が相乗りする単一 endpoint。route 単位の bucket を貼ると'
            .'無関係な管理操作を巻き添えにする。実際の制限は component 内にあり'
            .'(Auth/Pages/Login.php の $this->rateLimit(5) / Auth/Pages/EditProfile.php の同 5)、'
            .'panel が公開する credential 面はそこで有界化されている。'
            .'この前提 (rateLimit の実在 + 公開される auth ページの集合) は'
```

### database/factories/AdminUserFactory.php

```php
<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\AdminUser;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * @extends Factory<AdminUser>
 */
class AdminUserFactory extends Factory
{
    /**
     * factory 共通パスワードのハッシュキャッシュ (毎回の bcrypt を避ける)。
     */
    protected static ?string $password = null;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => fake()->name(),
            'email' => fake()->unique()->safeEmail(),
            'password' => static::$password ??= Hash::make('password'),
            'remember_token' => Str::random(10),
        ];
    }

    /**
     * MFA (TOTP) 設定済み state。
     *
     * `app_authentication_secret` は 16 文字 Base32 (Filament の `generateSecret()` と同等の固定値)。
     * `app_authentication_recovery_codes` は plain code を `Hash::make()` した hash 配列
     * (Filament の保存形式と同じ) を recoveryCodeCount(8) に合わせて 8 個格納する。
     * cast (`encrypted` / `encrypted:array`) は AdminUser の casts() で適用されるため plain 値を渡す。
     */
    public function withMfa(): static
    {
        return $this->state(fn (array $attributes): array => [
            'app_authentication_secret' => 'JBSWY3DPEHPK3PXP',
            'app_authentication_recovery_codes' => collect(range(1, 8))
                ->map(fn (int $i): string => Hash::make(sprintf('test-recovery-code-%02d', $i)))
                ->all(),
        ]);
    }
}
```
