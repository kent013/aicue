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
5. LLM 呼び出しの Prism 直呼び(`app/Prompts/` の factory 経由のみ。PromptGuardrailTest が検出)
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

## あなたの役割

Laravel 12 + Svelte 5 (runes) + Inertia.js のアプリ **AI-CUE** の改善実装をレビューするコードレビュアー。
以下の観点で厳しくレビューし、Critical / Warning / Suggestion に分類して報告せよ。

### レビュー観点

1. **設計との一致性**: 詳細設計書の意図どおりに実装されているか。逸脱があるなら妥当か
2. **正確性**: ロジックの誤り、競合状態、境界条件の取りこぼし
3. **PHPStan level 10 適合性** (PHP 変更がある場合)
4. **DTO / JsonResource パターン** (`response()->json()` 直書き禁止)
5. **テスト網羅性**: 不変条件がテストで固定されているか。空振り (常に green になる) テストになっていないか
6. **セキュリティ**: TOTP secret の露出面、PII の持ち越し、XSS (`{@html}`)
7. **DESIGN.md 準拠**: `/DESIGN.md` が design token の canonical source。color / radius / typography は token 経由で参照し hex 直書き (`#RRGGBB`) を増やさない。token 値を変更する diff は `resources/css/tokens.css` と同一 diff 内で同期しているか
8. **Atomic Design 準拠**: `resources/js/components/` は `atoms/molecules/organisms/templates` の責務分離に従う。import は `atoms → molecules → organisms → features/{domain} → templates → pages` の単方向のみ。atom は単機能・状態を持たない。アイコンは Lucide を使い SVG 直書きを増やさない

### 出力形式

- ファイルごとに判定を書く
- 指摘は [Critical] / [Warning] / [Suggestion] で分類する
- 最後に全体判定を **APPROVED** または **CHANGES_REQUESTED** で明示する

---

## 詳細設計書

# 詳細設計: ux-small-gaps (F-4-02 2FA 手動セットアップキー / F-1-02 削除後の成功フィードバック)

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
7. 操作系 POST の応答での `redirect()->intended()`(ログイン直後フロー専用)
8. 必須条件未充足を理由にボタンを disabled にする UI(押下時にエラー表示する。DESIGN.md)
9. Artifact の使用(成果物はリポジトリ内のファイルとして出力する)

### コーディングルール

- **PHPStan level 10** 必須（`composer phpstan`）
- **Pest**テストフレームワーク（`composer test` / Browser は `composer test:browser`）
- **RefreshDatabase** + `--parallel` 並列実行（`tests/Pest.php` でグローバル適用、個別 `DatabaseTransactions` 使用禁止）
- **テストデータは必ずFactoryで生成**（`Model::create()` 手組み禁止）
- **DTO + JsonResource** パターン
- **アーリーリターン** 推奨
- **コードフォーマット**: `composer fix`（Pint）/ `pnpm lint:fix`
- PHP 8.4 + Laravel 12 + Svelte 5 + Inertia.js + TypeScript
- フロントは Svelte 5 runes + DS token/ramp のみ（`DESIGN.md` が canonical、ds-purity テストが検出）
- component 階層は `atoms → molecules → organisms → features/{domain} → templates → pages` の単方向 import

## 概念設計リファレンス

`devnotes/20260804-0021-ux-small-gaps/conceptual-design.md`
（Codex conceptual-review Round 4 で **APPROVED**）

**詳細設計レビュー**: Codex design-review Round 1〜3 (Critical 2 / Warning 9) を反映し、
**Round 4 で APPROVED**。Round 4 の Suggestion (「ページ遷移」→「layout が再初期化される遷移」)
も反映済み。対応マトリクスは `codex-history/design-review-decisions-round-{1,2,3}.md`。

**事実検証の所在**: Codex は本環境でファイル読取手段を持てなかったため、本書の `ファイル:行`
主張はすべて Claude 側が実ファイルを読んで確認したものである。

## 施策一覧

実装順序は「B-1 → C → A-1+A-2」。中間状態を作らないための順序であり、A-1 と A-2 は
同一コミットで入れる（当初の条件付き施策 B-2 は Round 3 で A-2 に統合・無条件化した）。

| # | 施策名 | 変更ファイル | 優先度 |
|---|--------|------------|--------|
| B-1 | flash → toast の end-to-end 再現テスト (実装変更なし) | `tests/Browser/FlashToastTest.php` (新規) | 最初に実施 |
| C | 2FA enrollment の手動セットアップキー + QR アクセシブルネーム | `resources/js/pages/Settings/Security.svelte`、`tests/js/pages/SettingsSecurityTwoFactorConfirm.test.ts` | 高（主施策） |
| A-1 | `GuestLayout` の flash 取り込み | `resources/js/components/templates/GuestLayout.svelte`、`tests/js/components/templates/GuestLayout.test.ts`、`tests/Feature/Auth/AccountDeletionTest.php` / `tests/Feature/Api/ApiKeyTest.php` / `tests/Feature/Organizations/OAuthSession/DestroyTest.php` | 中 |
| A-2 | toast 消去境界の一本化（3 layout の初期化時 clear → consume。`ToastContainer.onDestroy` 撤去を含む） | `resources/js/components/templates/{GuestLayout,AuthLayout,AppLayout}.svelte`、`resources/js/components/organisms/ToastContainer.svelte`、`DESIGN.md`、`tests/js/components/templates/*.test.ts`、`tests/js/components/organisms/ToastContainer.test.ts` | 中（A-1 と不可分） |

---

## 施策 B-1: flash → toast の end-to-end 再現テスト

### 変更箇所

- 新規: `tests/Browser/FlashToastTest.php`（テスト 2 本）
- **アプリコードの変更なし**（現行コードのまま実行して事実を確定させる）
  ただし 2 本目（GuestLayout 着地）は施策 A-1 を入れるまで必ず fail する
  = **A-1 の受入テスト**として先に書く（テストファースト）。F-1-02 の H-a / H-b 判定に使うのは
  **変更前に実行した 1 本目の結果**のみで、**実装内容は結果によらず変えない**
  （B-2 の条件付き適用は Round 3 で廃止し A-2 に統合した）。

### 波及変更

- TypeScript型定義: なし
- API Resource/DTO: なし
- テストファイル: 新規ファイル 1 つ（テスト 2 本）

### 背景（なぜ Browser レーンか）

flash → toast は「サーバの flash」「Inertia のページ丸ごと再生成」「module singleton の toast store」
「`ToastContainer` の mount/unmount」が絡む経路で、**現状 end-to-end のテストが 1 本も無い**
(`tests/js/lib/flash-to-toast.test.ts` / `tests/js/components/organisms/ToastContainer.test.ts` は
ストア単体、`tests/Feature/Projects/VideoManualCrudTest.php:196-199` はサーバ側 flash のみ)。
実ブラウザ以外にこの結合を観測できる層がない。

### 変更後コード

```php
<?php

declare(strict_types=1);

use App\Models\Project;
use App\Models\VideoManual;
use Pest\Browser\Api\PendingAwaitablePage;

/*
|--------------------------------------------------------------------------
| flash → toast の end-to-end (bug-hunt F-1-02 の再現/反証)
|--------------------------------------------------------------------------
|
| 破壊的操作が別画面へリダイレクトしたとき、着地先で成功 toast が可視であることを固定する。
| 2 本で 2 種類の遷移を覆う:
|   1. projects.manuals.destroy → projects.show  (AppLayout → AppLayout)
|   2. settings.account.destroy → home           (AppLayout → GuestLayout。施策 A-1 の受入)
|
| サーバ側 flash は VideoManualCrudTest が、flash→toast 変換は
| tests/js/lib/flash-to-toast.test.ts が既に固定している。本テストが担うのは
| **Inertia のページ再生成をまたいで toast が生き残るか**という結合のみ。
|
| 判定の射程 (devnotes/20260804-0021-ux-small-gaps/conceptual-design.md):
|   - 制御条件 (flash 有り / 着地ページ mount 済み / 3 秒以内に検査) を満たして
|     一度も可視にならない → H-a (ToastContainer のライフサイクル依存) を支持
|   - その他の fail → 原因判定不能。テスト条件を調査する (実装を変えない)
|   - pass → 「自動テスト条件では未再現」まで (bug-hunt 観測が artifact だったことの確定ではない)
|
| 実行: composer test:browser (Chromium / WebKit の両レーン)。前提: pnpm build 済み。
*/

/**
 * 「着地マーカー」と「成功 toast」を**同一の時間窓で同時に**観測する。
 *
 * 2 つを直列に待つと、着地判定が deadline を越えた場合に
 * 「4 秒目に着地したのに『3 秒以内に着地済み』と誤分類する」ため、必ず同一ループで見る。
 * deadline は toast の auto-dismiss (4 秒) より短く取り、「見えなかった」を
 * auto-dismiss と混同しないようにする (制御条件 (iii))。
 *
 * 存在 (querySelector != null) ではなく**実可視**で判定する (レンダ順によっては
 * 非表示のまま DOM に居る瞬間がありうる)。
 * script() 呼び出しが in-process サーバの event loop を回す (bfcache テストと同じ流儀)。
 *
 * @return array{toastVisible: bool, landedWithinDeadline: bool, elapsedMs: int}
 */
function observeLandingAndToast(PendingAwaitablePage $page, string $landingSelector, int $timeoutMs = 3000): array
{
    $startedAt = hrtime(true);
    $expression = sprintf(<<<'JS'
        (() => {
            const visible = (selector) => {
                const el = document.querySelector(selector);
                if (el === null) return false;
                const style = getComputedStyle(el);
                return style.visibility !== 'hidden'
                    && style.display !== 'none'
                    && el.getClientRects().length > 0;
            };

            return {
                landed: visible(%s),
                toast: visible('[data-testid="toast-success"]'),
            };
        })()
        JS, json_encode($landingSelector, JSON_THROW_ON_ERROR));

    $landed = false;

    while (true) {
        $state = $page->script($expression);
        $landed = $landed || (is_array($state) && ($state['landed'] ?? false) === true);

        if (is_array($state) && ($state['toast'] ?? false) === true) {
            return [
                'toastVisible' => true,
                'landedWithinDeadline' => $landed,
                'elapsedMs' => (int) ((hrtime(true) - $startedAt) / 1_000_000),
            ];
        }

        $elapsedMs = (int) ((hrtime(true) - $startedAt) / 1_000_000);
        if ($elapsedMs >= $timeoutMs) {
            return ['toastVisible' => false, 'landedWithinDeadline' => $landed, 'elapsedMs' => $elapsedMs];
        }

        usleep(50_000);
    }
}

/**
 * fail 時の分類を message に載せる (制御条件つき fail かどうかを人が判断できるように)。
 *
 * @param  array{toastVisible: bool, landedWithinDeadline: bool, elapsedMs: int}  $observed
 */
function assertToastObserved(array $observed, string $what): void
{
    if ($observed['toastVisible']) {
        expect(true)->toBeTrue();

        return;
    }

    expect($observed['landedWithinDeadline'])->toBeTrue(
        "{$what}: deadline ({$observed['elapsedMs']}ms) 内に着地マーカーが可視にならなかった "
        .'= 「その他の fail」。原因判定不能なので実装を変えずにテスト条件を調査すること',
    );

    expect($observed['toastVisible'])->toBeTrue(
        "{$what}: 着地は deadline 内に確認できたが成功 toast が可視にならなかった "
        .'= 制御条件を満たした fail → H-a を支持 (conceptual-design.md の判定表を参照)',
    );
}

test('動画マニュアル削除後、リダイレクト先 (AppLayout) で成功 toast が表示される', function (): void {
    [$organization, $owner] = createOrganizationWithOwner();
    $project = Project::factory()->forOrganization($organization)->create();
    $manual = VideoManual::factory()->forProject($project)->create(['title' => '組立手順']);

    $this->actingAs($owner);

    $page = visit("/projects/{$project->id}/manuals/{$manual->id}");
    $page->assertSee('組立手順');

    // DangerZone → 確認ダイアログ → 削除実行 (testId 指定で text の曖昧一致を避ける)
    $page->click('@delete-manual-button');
    $page->assertSee('削除する');
    $page->click('削除する');

    assertToastObserved(
        observeLandingAndToast($page, '[data-testid="project-show-heading"]'),
        'manuals.destroy → projects.show',
    );

    $page->assertPathIs("/projects/{$project->id}")
        ->assertSeeIn('[data-testid="toast-success"]', '動画マニュアルを削除しました')
        ->assertNoJavaScriptErrors();
});

test('アカウント削除後、未認証面 (GuestLayout) で成功 toast が表示される', function (): void {
    // recent-auth は Login イベントで stamp される (StampRecentAuthOnLogin)。
    // actingAs() は Login を発火しないため、**この 1 本だけ UI ログイン**から始める
    // (ハーネス内部仕様への依存を作らない)。
    // createOrganizationWithOwner は free plan を grandfather するため課金ゲートに掛からない
    [$organization, $owner] = createOrganizationWithOwner();

    $page = visit('/login');
    $page->fill('email', $owner->email)   // email は CipherSweet 暗号化だがモデル経由は平文
        ->fill('password', 'password')    // UserFactory の既定パスワード
        ->press('ログイン');

    $page->assertPathIs('/dashboard');

    $page = visit('/settings');
    $page->click('@delete-account-button');
    $page->assertSee('本当にアカウントを削除しますか？');
    $page->click('削除する');

    assertToastObserved(
        observeLandingAndToast($page, '[data-testid="landing-hero"]'), // Welcome.svelte:142
        'settings.account.destroy → home (GuestLayout)',
    );

    $page->assertPathIs('/')
        ->assertSeeIn('[data-testid="toast-success"]', 'アカウントを削除しました')
        ->assertNoJavaScriptErrors();
});
```

**実装時の確認事項（設計時点で未確定なもの）**:

- `[data-testid="project-show-heading"]` は `resources/js/pages/Projects/Show.svelte:304` の
  `testId="project-show-heading"` に対応する（確認済み）。
- `@delete-manual-button` は `GuessLocator`（`vendor/pestphp/pest-plugin-browser/src/Support/GuessLocator.php`）の
  `@` 記法で `[data-testid=delete-manual-button]` に解決される。
  該当 testId は `resources/js/pages/Manuals/Show.svelte:163` に存在する。
- 確認ダイアログの「削除する」は `ConfirmDialog` の `confirmLabel`
  （`resources/js/pages/Manuals/Show.svelte:187` / `resources/js/pages/Settings/Index.svelte:279`）。
  この文字列は各ページ内で一意。
- アカウント削除は recent-auth 必須。`$this->actingAs()` は `Login` イベントを発火しないため
  `StampRecentAuthOnLogin`（`app/Listeners/Auth/StampRecentAuthOnLogin.php`）が走らず、
  recent-auth が stamp されない。したがって 2 本目は **UI ログインから始める**
  （`fill('email' | 'password')` は `GuessLocator` が `[id="..."]` に解決する。
  `resources/js/pages/Auth/Login.svelte:34,47` の FormField id と一致）。
- `@delete-account-button` は `resources/js/pages/Settings/Index.svelte:269`。
- `[data-testid="landing-hero"]` は `resources/js/pages/Welcome.svelte:142`。

### PHPStan適合チェック

- [x] 戻り値の型が明示されている（helper は array shape を PHPDoc で明示）
- [x] null安全（`$page->script()` の戻りは `=== true` で厳密比較）
- [x] DTOを返している（該当なし。テストコード）
- [x] Genericsの型パラメータが正しい（該当なし）

### テスト計画

- [x] バグ修正の場合: 再現テストを先に書く（**本施策そのものが再現テスト**。アプリコードを変更せずに実行する）
- [ ] 既存テストの更新: なし
- [x] 新規テスト: `tests/Browser/FlashToastTest.php` — Inertia のページ再生成をまたいだ flash toast の可視性
- [x] 個別の `DatabaseTransactions` を使っていない（`tests/Pest.php` が Browser lane に `RefreshDatabase` を適用済み）

### リスク

- Browser レーンは `pnpm build` 済みのアセットを読むため、UI を変更する施策 C / A の**後**に
  再実行が必要（実装順序では B-1 を先に走らせるが、最終確認は全施策適用後にもう一度回す）。
- polling helper の 3 秒 deadline は auto-dismiss 4 秒より短い。CI の遅さで
  「着地自体が 3 秒を超える」ケースは `$landed` の失敗として先に検出されるため、
  toast 側の fail と混同しない。

---

## 施策 C: 2FA enrollment の手動セットアップキー + QR アクセシブルネーム

### 変更箇所

- ファイル: `resources/js/pages/Settings/Security.svelte`
  - script: L77-116（state 定義と `loadQrCode`）、L203-221（`enableTwoFactor`）、
    L223-236（`confirmTwoFactor`）、L241-272（`disableTwoFactor`）
  - markup: L353-364（confirming ブロックの QR 表示）

### 波及変更

- TypeScript型定義: `Security.svelte` 内のローカル型のみ（共有型は増やさない）
- API Resource/DTO: **なし**（サーバ変更なし。Fortify 既存 endpoint の read 利用）
- テストファイル: `tests/js/pages/SettingsSecurityTwoFactorConfirm.test.ts`
  （既存の fetch stub に `/user/two-factor-secret-key` を追加する必要があるため、
  同ファイルの `stubFetchRoutes()` を更新する = **既存テストの更新が必須**）

### 現行コード

```svelte
<!-- script -->
let qrSvg = $state<string | null>(null);

async function fetchJson<T>(url: string): Promise<T> {
    const response = await fetch(url, { headers: { Accept: "application/json" } });
    if (!response.ok) { throw new Error(`HTTP ${response.status}`); }
    return (await response.json()) as T;
}

async function loadQrCode(): Promise<void> {
    try {
        const data = await fetchJson<{ svg: string }>("/user/two-factor-qr-code");
        qrSvg = data.svg;
    } catch {
        addToast("error", "QR コードの取得に失敗しました。再読み込みしてください。");
    }
}

function enableTwoFactor(): void {
    router.post("/user/two-factor-authentication", {}, {
        preserveScroll: true,
        onStart: () => { enabling = true; },
        onSuccess: () => { confirming = true; void loadQrCode(); },
        onFinish: () => { enabling = false; },
    });
}
```

```svelte
<!-- markup (confirming ブロック) -->
{#if qrSvg}
    <!-- QR はサーバ提供の SVG をそのまま描画する -->
    <div class="self-start rounded-md border border-border bg-surface p-4">
        <!-- eslint-disable-next-line svelte/no-at-html-tags -->
        {@html qrSvg}
    </div>
{/if}
```

### 変更後コード

```svelte
<!-- script: state -->
/** enrollment 素材。QR と手動セットアップキーは独立に失敗しうる (片方でも enrollment は続行可能) */
let qrSvg = $state<string | null>(null);
let setupKey = $state<string | null>(null);
/** 両方の取得に失敗した = enrollment を続行できない (再試行導線を出す) */
let enrollmentAssetsFailed = $state(false);
let loadingEnrollmentAssets = $state(false);

/**
 * JSON レスポンスから非空文字列の field を取り出す。
 * fetchJson の generic は型 assertion にすぎないため、shape は信用せず narrowing する
 * (不正 shape は通信失敗と同じ「その手段が使えない」に畳む)。
 */
function readStringField(payload: unknown, key: string): string | null {
    if (typeof payload !== "object" || payload === null) return null;
    const value = (payload as Record<string, unknown>)[key];
    return typeof value === "string" && value.trim() !== "" ? value : null;
}

/** 単一 endpoint から文字列 field を取得する (通信失敗 / HTTP 失敗 / 不正 shape はすべて null) */
async function fetchStringField(url: string, key: string): Promise<string | null> {
    try {
        return readStringField(await fetchJson<unknown>(url), key);
    } catch {
        return null;
    }
}

/**
 * 取得世代。**後着優先**の判定に使う。
 * 破棄 (reset) と取得開始で進み、解決時に世代が変わっていれば結果を捨てる。
 * これが無いと (a) confirm/disable 成功で消したはずの secret が、遅れて解決した
 * fetch で再格納される (b) 古い run が loading を握り続けて再有効化が始まらない、
 * の 2 つの競合が起きる。
 */
let enrollmentGeneration = 0;

/**
 * enrollment 素材 (QR + 手動セットアップキー) を取得する。
 * 2 つは独立に扱い、片方が取れれば enrollment を続行できる。
 * 両方失敗したときだけ「取得失敗 (再試行可)」として提示する。
 */
async function loadEnrollmentAssets(): Promise<void> {
    const generation = ++enrollmentGeneration;
    loadingEnrollmentAssets = true;

    const [qr, secret] = await Promise.all([
        fetchStringField("/user/two-factor-qr-code", "svg"),
        fetchStringField("/user/two-factor-secret-key", "secretKey"),
    ]);

    // 世代が進んでいる = 破棄済み or 新しい取得が走っている。結果も loading も触らない
    // (finally で戻すと古い run が新しい run の loading を消してしまう)
    if (generation !== enrollmentGeneration) return;

    qrSvg = qr;
    setupKey = secret;
    enrollmentAssetsFailed = qr === null && secret === null;
    loadingEnrollmentAssets = false;
}

/**
 * enrollment 素材を画面から破棄する (開始時 / confirm 成功時 / 無効化成功時に呼ぶ)。
 * 世代を進めることで、進行中の取得結果が後から再格納されるのを防ぐ。
 */
function resetEnrollmentAssets(): void {
    enrollmentGeneration += 1;
    qrSvg = null;
    setupKey = null;
    enrollmentAssetsFailed = false;
    loadingEnrollmentAssets = false;
}

function enableTwoFactor(): void {
    // 再試行時に前回の素材・エラーを持ち越さない
    resetEnrollmentAssets();
    router.post("/user/two-factor-authentication", {}, {
        preserveScroll: true,
        onStart: () => { enabling = true; },
        onSuccess: () => { confirming = true; void loadEnrollmentAssets(); },
        onFinish: () => { enabling = false; },
    });
}
```

`confirmTwoFactor` / `disableTwoFactor` の onSuccess は
`qrSvg = null;` を **`resetEnrollmentAssets();`** に置き換える（`disableTwoFactor` は
`recoveryCodes = []` を残す）。

```svelte
<!-- markup: confirming ブロック -->
<div class="mt-4 flex flex-col gap-4">
    <p class="text-body text-text-secondary">
        認証アプリで QR コードを読み取るか、セットアップキーを手動入力し、
        表示されたコードを入力して設定を完了してください。
    </p>

    {#if loadingEnrollmentAssets}
        <!-- 取得中に「表示できませんでした」を先出ししない (失敗前に失敗文言を出さない) -->
        <p class="text-caption text-text-secondary" aria-busy="true" data-testid="enrollment-assets-loading">
            認証アプリ設定用の情報を読み込んでいます…
        </p>
    {:else if enrollmentAssetsFailed}
        <Alert type="danger" title="設定情報を取得できませんでした" testId="enrollment-assets-error">
            QR コードとセットアップキーのどちらも取得できませんでした。
            {#snippet action()}
                <Button
                    variant="ghost"
                    onclick={() => void loadEnrollmentAssets()}
                    loading={loadingEnrollmentAssets}
                    testId="retry-enrollment-assets-button"
                >
                    再試行
                </Button>
            {/snippet}
        </Alert>
    {:else}
        {#if qrSvg}
            <!-- QR はサーバ提供の SVG をそのまま描画する。svg 文字列に属性を注入せず、
                 wrapper を role="img" にしてアクセシブルネームを与える (H14) -->
            <div
                role="img"
                aria-label="2 要素認証の設定用 QR コード"
                class="self-start rounded-md border border-border bg-surface p-4"
                data-testid="two-factor-qr"
            >
                <!-- eslint-disable-next-line svelte/no-at-html-tags -->
                {@html qrSvg}
            </div>
        {:else}
            <Alert type="warning" testId="qr-unavailable">
                QR コードを表示できませんでした。下のセットアップキーを認証アプリに手動入力してください。
            </Alert>
        {/if}

        {#if setupKey}
            <div class="flex flex-col gap-2">
                <p class="text-caption text-text-secondary">
                    QR コードを読み取れない場合は、次のセットアップキーを認証アプリに手動入力してください。
                </p>
                <CodeSnippet code={setupKey} testId="two-factor-setup-key" />
            </div>
        {:else}
            <Alert type="warning" testId="setup-key-unavailable">
                セットアップキーを表示できませんでした。上の QR コードを認証アプリで読み取ってください。
            </Alert>
        {/if}
    {/if}

    <form onsubmit={confirmTwoFactor} class="flex flex-col gap-4">
        <!-- 既存のまま (FormField + Input + 送信ボタン) -->
    </form>
</div>
```

追加 import: `Alert from "@/components/atoms/Alert.svelte"`、
`CodeSnippet from "@/components/molecules/CodeSnippet.svelte"`。
いずれも page → atom/molecule の単方向 import で階層規約に適合する。

### 設計判断の根拠（レビューで問われた点）

- **secret をテキスト表示するリスク**: `two-factor.qr-code` と `two-factor.secret-key` は
  Fortify の同一 middleware（`vendor/laravel/fortify/routes/routes.php:162-168`）で
  **同じ TOTP secret** を返す。QR は既にその secret をエンコードして表示しているため、
  新しい endpoint・権限境界・保存先は増えない。可読性が上がる分の shoulder-surfing リスクは
  QR と同種で残るため、`resetEnrollmentAssets()` で残置時間を enrollment 中に限定する。
- **recent-auth を課さない**のは記録済み意思決定の踏襲
  （`devnotes/20260713-1653-twofa-recent-auth/conceptual-design.md:67`)。片方だけにゲートを
  足すと記録済みの境界を設計レビューなしに動かすことになる。
- **折りたたみ (details) にしない**: QR が同じ秘密を出している以上テキストだけ隠しても
  対策にならず、支援技術利用者には一手増えて施策の目的に反する。
- **失敗を toast ではなく Alert で出す**: DESIGN.md §Toast/§Alert の
  「ページ常在の通知は Alert、一時通知は Toast」に従う。enrollment 中は常在表示が適切
  （4 秒で消える toast だと、後から画面を見た利用者に理由が伝わらない）。
- **再試行ボタンは常時活性**（禁止事項 8: 必須条件未充足で disabled にしない。
  `loading` は処理中の二重送信抑止であり条件 disabled ではない）。
- **非同期結果の世代管理**: in-flight ガード（先着優先の早期リターン）では、
  「取得中に confirm / disable が成功して画面状態を破棄した後、古い Promise が解決して
  secret を再格納する」競合を防げない。世代番号（後着優先 + reset で無効化）で解決する。
- **取得中は失敗文言を出さない**: `confirming = true` の直後は `qrSvg` / `setupKey` とも
  null のため、loading 分岐が無いと「表示できませんでした」が fetch 解決前に出てしまう。
  `loadingEnrollmentAssets` の分岐を最優先に置き、警告 Alert は**欠損が確定してから**出す。
- **`{@html qrSvg}` の信頼境界は不変**: 描画するのは従来どおり Fortify がサーバ側で生成した
  SVG のみで、本設計はユーザー入力を混ぜない。セットアップキーは `CodeSnippet` の
  テキストノードとして描画するため `{@html}` を新設しない。
- **エラー種別（通信失敗 / HTTP 失敗 / 不正 shape）を内部で区別しない**理由:
  表示文言も再試行導線も同一であり、区別した値の使い道が無い。使わない情報の保持は
  思考原則 2 に反する。秘密が絡む経路のため `console` へも出さない。

### PHPStan適合チェック

- 該当なし（PHP 変更なし）。TypeScript 側は `unknown` からの narrowing のみで
  `any` / 型 assertion を増やさない（`pnpm typecheck` で検証）。

### テスト計画

対象: `tests/js/pages/SettingsSecurityTwoFactorConfirm.test.ts`（既存の 2FA 確認テスト）

- [x] 既存テストの更新: `stubFetchRoutes()` に `/user/two-factor-secret-key` →
      `{ secretKey: "ABCDEFGH12345678" }` を追加（追加しないと `openConfirmForm()` 経路で
      未 stub の fetch が recovery-codes 用の fallback に落ちて誤検知する）
- [x] 新規テスト:
  - `有効化開始でセットアップキーを取得し画面に表示する` —
    `screen.getByTestId("two-factor-setup-key-body")` に secret が出る
    （`CodeSnippet` は `{testId}-body` を `<pre>` に付ける: `CodeSnippet.svelte:53`）
  - `QR の wrapper に role="img" とアクセシブルネームがある` —
    `screen.getByRole("img", { name: "2 要素認証の設定用 QR コード" })`
  - `QR 取得失敗でもセットアップキーで継続できる` — QR だけ 500 を返す stub。
    `qr-unavailable` Alert が出て、セットアップキーと認証コード入力は残る
  - `セットアップキー取得失敗でも QR で継続できる` — secret だけ不正 shape
    (`{}` を返す) にする。`setup-key-unavailable` Alert が出て QR は残る
    （**不正 shape が取得失敗と同経路になることの固定も兼ねる**）
  - `両方失敗したときは再試行導線を出す` — 両方 500。`enrollment-assets-error` Alert と
    `retry-enrollment-assets-button` が出る。押下で再 fetch されることまで確認する
  - `後着優先: 古い取得が後から解決しても新しい secret を上書きしない` —
    **観測可能な順序**で固定する（Codex design-review Round 3 の指摘）:
    (1) 有効化 → 取得 1 を保留したまま、(2) confirm 成功を発火して reset させ、
    (3) 再度「有効化」を押して取得 2 を開始、(4) 取得 2 を `NEWKEY` で解決して画面に表示、
    (5) **その後で**取得 1 を `OLDKEY` で解決 →
    画面は `NEWKEY` のままで `OLDKEY` は現れないこと。
    これで「reset による無効化」と「後着優先」を 1 つの振る舞いとして観測できる。
    (3) が実行できること自体が「古い run が loading を握り続けない」ことの固定にもなる。
    **実害**: 旧取得が後勝ちすると、サーバが持つ新しい secret とは違うキーを
    ユーザーが認証アプリへ登録してしまい enrollment が必ず失敗する。
  - `取得中は失敗文言を出さない` — fetch を保留する stub にして
    `enrollment-assets-loading` が出ている間 `qr-unavailable` /
    `setup-key-unavailable` / `enrollment-assets-error` が**出ないこと**を固定
    （Codex design-review Round 1 の Critical。回帰させない）
  - `confirm 成功でセットアップキーが画面から消える` — 既存 (c) のテストに
    `queryByTestId("two-factor-setup-key")` が null になる assertion を追加
- [x] 個別の `DatabaseTransactions` を使っていない（JS テストのため該当なし）

### リスク

- 既存テストの `stubFetchRoutes()` は「その他の URL は recovery codes を返す」fallback を
  持つため、secret-key の stub を足し忘れると **配列が secretKey として解釈されずに
  `setup-key-unavailable` へ落ちる**。上記の narrowing で誤表示にはならないが、
  テストの意図が壊れるため stub 追加は必須。
- `Promise.all` は両者を並列に投げる。Fortify の secret-key は
  `two_factor_secret` が null なら 404 を返す（`TwoFactorSecretKeyController::show`）が、
  `enableTwoFactor` の onSuccess 後に呼ぶため secret は必ず存在する。
  仮に 404 でも `setup-key-unavailable` に落ちるだけで enrollment は継続できる。

---

## 施策 A-1 / A-2: 未認証 layout の flash 取り込みと、消去境界の一本化

> **Round 3 で B-2 を本施策へ統合し、条件付き適用を廃止した**。
> 「条件によって最終状態が 2 通りになる」設計を排し、契約を 1 つに定める。

### 変更箇所

- `resources/js/components/templates/GuestLayout.svelte`（script 冒頭 + markup 先頭）
- `resources/js/components/templates/AuthLayout.svelte`（script に 2 行）
- `resources/js/components/templates/AppLayout.svelte`（script に 2 行）
- `resources/js/components/organisms/ToastContainer.svelte`（`onDestroy(clearToasts)` の撤去）
- `DESIGN.md` §Toast（消去境界の明文化）

### 契約（唯一の最終状態）

**toast の消去境界は「layout の初期化 (= layout が再初期化される遷移)」と
「auto-dismiss」「手動 dismiss」の 3 つ**。`preserveState` の visit / partial reload は
layout を再初期化しないため toast は残る (現行と同じ挙動)。

- 3 layout（`AppLayout` / `AuthLayout` / `GuestLayout`）すべてが
  **初期化時に `clearToasts()` → `$effect` で `consumeFlash()`** の順に実行する。
- `ToastContainer` の `onDestroy(() => clearToasts())` は**撤去**する。
  境界が二重になるうえ、その正しさが Svelte の破棄/フラッシュ順に依存するため
  （`node_modules/svelte/src/internal/client/dom/blocks/branches.js` の
  `BranchManager#ensure` / `#commit` — 新 branch 生成 → 旧 branch 破棄 → user effect flush）。
- **観測される挙動は現行と同一**（layout が再初期化される遷移で toast は消え、
  着地応答の flash は新規に出る）。
  変わるのは順序が決定的になる点だけ。

**Codex 提案（消去境界を未認証 layout の初期化だけに一本化する = 認証面では toast が遷移をまたいで
生存する）を採らない理由**: **error toast は auto-dismiss しない**（`resources/js/lib/stores/toast.ts:27`）。
一本化すると前ページの失敗メッセージが遷移後も残り続けるという**新しい後退**を生む。
今回の目的は「境界を決定的にすること」であって「toast の寿命を延ばすこと」ではない。

### 波及変更

- TypeScript型定義: なし（`FlashPayload` は既存）
- API Resource/DTO: なし
- テストファイル: `tests/js/components/templates/GuestLayout.test.ts`（更新）、
  `tests/js/components/templates/AuthLayout.test.ts`（**新規**。現状 AuthLayout のテストは無い）、
  `tests/Feature/Auth/AccountDeletionTest.php` / `tests/Feature/Api/ApiKeyTest.php` / `tests/Feature/Organizations/OAuthSession/DestroyTest.php`（assert 追加）

### 現行コード

`GuestLayout.svelte`（flash 関連の記述が一切ない）:

```svelte
<script lang="ts">
    import type { Snippet } from "svelte";
    import { Menu, X } from "@lucide/svelte";
    import Button from "@/components/atoms/Button.svelte";
    ...
    let { appName, children, nav, footerLinks }: Props = $props();
```

`AuthLayout.svelte`:

```svelte
    $effect(() => {
        consumeFlash(page.props.flash as FlashPayload | undefined);
    });
</script>

<ToastContainer />
```

### 変更後コード

`GuestLayout.svelte`:

```svelte
<script lang="ts">
    import type { Snippet } from "svelte";
    import { page } from "@inertiajs/svelte";
    import { Menu, X } from "@lucide/svelte";
    import Button from "@/components/atoms/Button.svelte";
    import ToastContainer from "@/components/organisms/ToastContainer.svelte";
    import { consumeFlash, type FlashPayload } from "@/lib/stores/flash-to-toast";
    import { clearToasts } from "@/lib/stores/toast";

    /**
     * 未認証公開ページ (LP / Pricing / Contact / Legal) 用レイアウト。
     * ...
     * Laravel flash は consumeFlash で toast に変換する (visitKey で de-dup)。
     * 未認証 layout の着地境界: 初期化時に既存 toast を破棄してから当該 visit の flash を
     * 消費する (認証済み画面の toast は氏名・組織名を含みうるため未認証面へ持ち越さない)。
     */

    let { appName, children, nav, footerLinks }: Props = $props();

    // component 初期化時の 1 回のみ。$effect に載せると partial reload 等の再評価で
    // client 側 toast まで巻き込むため、意図的に effect の外に置く。
    clearToasts();

    $effect(() => {
        consumeFlash(page.props.flash as FlashPayload | undefined);
    });
</script>

<ToastContainer />

<svelte:window onkeydown={handleKeydown} onclick={handleWindowClick} />
...
```

`AuthLayout.svelte` / `AppLayout.svelte`（`clearToasts` の import と 1 行追加のみ。3 layout 共通）:

```svelte
    import { clearToasts } from "@/lib/stores/toast";
    ...
    // 消去境界 (DESIGN.md §Toast): layout の初期化時に既存 toast を破棄してから
    // 当該 visit の flash を消費する。初期化時の 1 回のみ ($effect に載せない)。
    clearToasts();

    $effect(() => {
        consumeFlash(page.props.flash as FlashPayload | undefined);
    });
```

`ToastContainer.svelte`（境界の二重化を解消する）:

```svelte
// 消去境界は layout の初期化に一本化した (DESIGN.md §Toast)。
// 本 component は AppLayout / AuthLayout / GuestLayout の中に置かれ **全ページ遷移で
// unmount される**ため、ここで clearToasts() すると境界が二重になり、
// かつ「着地先で flash を表示する」契約の成否が Svelte の破棄/フラッシュ順に依存する。
// 自動消去タイマーは dismissToast が clearTimeout + timers.delete するため残らない。
```

（`onDestroy` の import と `clearToasts` の import を削除し、`dismissToast` / `toasts` のみ残す。）

`DESIGN.md` §Toast に 1 行追加:

```markdown
- 消去境界: **layout (AppLayout / AuthLayout / GuestLayout) の初期化時に既存 toast を破棄**してから
  当該 visit の flash を消費する。= **layout が再初期化される遷移**では toast を持ち越さない
  (認証済み文脈の toast を未認証面へ出さない)。`preserveState` の visit / partial reload は
  layout を再初期化しないため toast は残る。別タブの既表示 toast の即時消去は保証しない
```

### 保証範囲（明文化）

- 認証失効後、**次のサーバ遷移で未認証 layout に着地した時点**で認証文脈の toast を持ち越さない。
- **別タブの既表示 UI の即時消去は保証しない**（toast store はタブごとの JS 実行環境にある）。
  現行の `onDestroy(clearToasts)` も別タブ即時無効化は提供していないため後退ではない。

### PHPStan適合チェック

- 該当なし（PHP の変更は Feature テストへの assert 追加のみ）

### テスト計画

**JS component**:

- [x] `tests/js/components/templates/GuestLayout.test.ts`（更新）
  - `flash.success が toast として描画される`（`page.props` に flash を積んで render →
    `toast-success` が出る。`resetFlashConsumption()` を beforeEach で呼ぶ）
  - `着地前から存在する toast は描画されない (認証文脈の持ち越し防止)` —
    render 前に `addToast("success", "「山田太郎」の 2 段階認証を解除しました")` →
    render 後にその文言が無い / 当該 visit の flash は出る
  - `再レンダー (props 更新) では clear が走らない` — render 後に
    `addToast` → `rerender({ appName: "別名" })` → toast が残る
- [x] `tests/js/components/templates/AuthLayout.test.ts`（新規。同じ 3 ケース）
- [x] `tests/js/components/templates/AppLayout.test.ts`（既存に 1 ケース追加）
  - `layout の初期化で既存 toast が破棄され、当該 visit の flash が表示される`
- [x] `tests/js/components/organisms/ToastContainer.test.ts`（既存に 1 ケース追加）
  - `unmount → 再 mount しても toast は残る`（消去責務が container に無いことの固定）
- [x] 既存 `GuestLayout.test.ts` の nav/ハンバーガーのケースは維持（削除・上書きしない）

**Feature (PHP)** — 破壊的操作の flash 規約の回帰固定（横断確認で assert 欠落が判明した 3 経路）:

- [x] `tests/Feature/Auth/AccountDeletionTest.php`:
      `test('step-up 済みならアカウントを削除でき、関連データが掃除される')` の
      `$response->assertRedirect('/');`（L31 付近）の直後に
      `$response->assertSessionHas('success', 'アカウントを削除しました');`
      （**文言まで固定するのはこの 1 件のみ**。他 2 件はキー存在のみとし、
      文言変更の巻き添え更新を増やさない）
- [x] `tests/Feature/Api/ApiKeyTest.php`:
      `test('owner は API キーを失効できる (revoked_at + SecurityEvent)')`（L83-）の
      `->assertRedirect()` を `->assertRedirect()->assertSessionHas('success')` に
- [x] `tests/Feature/Organizations/OAuthSession/DestroyTest.php`:
      `test('owner は recent-auth 済みならセッションを失効でき、配下 token も revoke される')`（L46-）の
      `->assertStatus(302)` の後に `->assertSessionHas('success')` を追加
- [x] 個別の `DatabaseTransactions` を使っていない（`tests/Pest.php` のグローバル適用に従う）

> 実装時は各ファイルの「成功パス」テストを特定して 1 行追加する。
> 既存 assertion の削除・書き換えは行わない（禁止事項: 既存テストの削除・上書き）。

### リスク

- `clearToasts()` を layout 初期化で呼ぶため、**layout が再初期化されるすべての遷移で
  既存 toast が消える**。
  現行は `ToastContainer.onDestroy` が同じことをしており**観測挙動は不変**（順序のみ決定的になる）。
- 既存 JS テストが「unmount で toast が消える」に暗黙依存している可能性がある。
  `tests/js/components/organisms/ToastContainer.test.ts` / `tests/js/lib/*.test.ts` は
  `beforeEach`/`afterEach` で `clearToasts()` を呼ぶため影響しないが、
  toast を assert するページ系テスト（`SettingsSecurity.test.ts` / `ScenarioEditor.test.ts` /
  `NotificationListItem.test.ts`）は `pnpm test` 全 green で確認する。漏れがあれば
  各テストの `beforeEach` に `clearToasts()` を足す（実装を歪めない対処）。
- `GuestLayout` に `ToastContainer` を足しても、同時に 2 つ mount される状況
  （DESIGN.md「アプリで 1 箇所のみ mount」）は起きない。**確認済み**:
  `ToastContainer` を描画しているのは `AppLayout.svelte:190` と `AuthLayout.svelte:28` のみで、
  root（`resources/js/app.ts` / `resources/views/app.blade.php`）には無い。
  layout を 2 つ使うページも存在しない（`AuthLayout` 利用の 8 ページはいずれも
  `AppLayout` / `GuestLayout` を import していない）。

---

## 施策 B-2 について（Round 3 で廃止・A-2 へ統合）

`ToastContainer` の `onDestroy(() => clearToasts())` 撤去は、当初「B-1 が制御条件つきで
fail した場合のみ適用する条件付き施策」として設計していたが、Codex design-review Round 3 の
指摘（条件によって toast lifecycle 契約が 2 通りになる／`DESIGN.md` の記述と実装が食い違う）を
受けて **施策 A-2 に統合し、無条件適用**とした。

- 統合後の契約と変更内容は「施策 A-1 / A-2」の §契約（唯一の最終状態）を参照。
- B-1（Browser テスト 2 本）は**実装可否のゲートではなく回帰テスト**として位置づける。
  変更前に 1 度走らせた結果は F-1-02 の判定（H-a を支持 / 自動テスト条件では未再現）にのみ使い、
  実装内容は結果によらず変えない。

**「`ToastContainer` が全ページ遷移で unmount される」の根拠**（一次ソース確認済み）:
`node_modules/@inertiajs/svelte/dist/components/App.svelte` の `swapComponent` が
非 preserveState visit で `key = Date.now()` を更新し、`Render.svelte` の
`{#key children?.length === 0 ? key : null}` がページ配下を作り直す。本アプリは layout を
ページ component 内で描画するため `ToastContainer` も毎回作り直される。
ただし**それが F-1-02 の根因であることは未確定**であり、その射程は概念設計の判定表が担保する。

---

## 検証コマンド

```bash
pnpm lint && pnpm typecheck && pnpm test      # 施策 C / A-1 / A-2 (JS)
composer test                                  # 施策 A (Feature の assert 追加)
composer phpstan && vendor/bin/pint --test     # PHP 変更はテストのみだが規約どおり実行
pnpm build && composer test:browser            # 施策 B-1 (Chromium + WebKit の 2 レーン)
```

`composer test:browser` は **UI 変更を含む全施策の適用後にもう一度**実行する
（実ブラウザが `public/build` を読むため）。

## 実装モード

| 項目 | 内容 |
|------|------|
| 推奨モード | incremental |
| 判断根拠 | 各施策とも小規模で、変更ファイルが互いに重複しない（`Settings/Security.svelte` / `templates/*.svelte` + `organisms/ToastContainer.svelte`）。1 セッションで B-1 → C → A-1+A-2 まで完結でき、TODO を分割すると Browser レーンの実行コスト（2 レーン × `pnpm build`）を二重に払うことになる。 |
| 競合リスク | 低。同 bug-hunt run の他 TODO が触るのは `Billing/*` / `Auth/*` / `bfcache-guard` / `AnalysisPipeline` 系で、本 TODO の変更ファイルとは重ならない。ただし `DESIGN.md` は他 TODO も追記しうるため、マージ時に §Toast の 1 行追加が競合しないか確認する。 |

## 実装差分 (git diff)

```diff
diff --git a/DESIGN.md b/DESIGN.md
index cfe7cc6..9408609 100644
--- a/DESIGN.md
+++ b/DESIGN.md
@@ -297,7 +297,12 @@ ### Toast
 Laravel flash の取り込みは `lib/stores/flash-to-toast.ts` の `consumeFlash`(visitKey で de-dup)。
 
 - 上部中央 fixed(`top-6 left-1/2 -translate-x-1/2 z-50`)に縦 stack 表示。アプリで 1 箇所のみ mount する
+  (mount するのは layout: AppLayout / AuthLayout / GuestLayout の 3 種。ページ側では mount しない)
 - 自動消去: **success / info / warning = 4 秒、error = 手動閉じのみ**
+- 消去境界: **layout(AppLayout / AuthLayout / GuestLayout)の初期化時に既存 toast を破棄**してから
+  当該 visit の flash を消費する。= **layout が再初期化される遷移**では toast を持ち越さない
+  (認証済み文脈の toast を未認証面へ出さない)。`preserveState` の visit / partial reload は
+  layout を再初期化しないため toast は残る。別タブの既表示 toast の即時消去は保証しない
 - 各 toast は `bg-surface` + type 別 border / アイコン色(success / primary(info)/ warning / danger)。
   アイコンは CircleCheck / Info / TriangleAlert / CircleX(@lucide/svelte)
 - a11y: `role="status"`(error のみ `role="alert"`)
diff --git a/resources/js/components/organisms/ToastContainer.svelte b/resources/js/components/organisms/ToastContainer.svelte
index 6139aea..0dc8e71 100644
--- a/resources/js/components/organisms/ToastContainer.svelte
+++ b/resources/js/components/organisms/ToastContainer.svelte
@@ -1,10 +1,15 @@
 <script lang="ts">
-    import { onDestroy } from "svelte";
     import { CircleCheck, CircleX, Info, TriangleAlert, X } from "@lucide/svelte";
-    import { clearToasts, dismissToast, toasts, type ToastType } from "@/lib/stores/toast";
+    import { dismissToast, toasts, type ToastType } from "@/lib/stores/toast";
 
     // toast ストアは singleton。本 component はアプリで 1 箇所のみ mount すること
     // (複数 mount すると同一 toast が重複描画される)。
+    //
+    // 消去境界は layout の初期化に一本化してある (DESIGN.md §Toast)。本 component は
+    // AppLayout / AuthLayout / GuestLayout の中に置かれ **layout が再初期化される遷移で
+    // 毎回 unmount される**ため、ここで clearToasts() すると境界が二重になり、かつ
+    // 「着地先で flash を表示する」契約の成否が Svelte の破棄/フラッシュ順に依存する。
+    // 自動消去タイマーは dismissToast が clearTimeout + timers.delete するため残らない。
 
     /** type 別アイコン (Lucide) */
     const TYPE_ICONS = {
@@ -21,9 +26,6 @@
         warning: { border: "border-warning", icon: "text-warning" },
         error: { border: "border-danger", icon: "text-danger" },
     } as const satisfies Record<ToastType, { border: string; icon: string }>;
-
-    // unmount 時 (SPA 全体破棄等の稀ケース) に残存タイマーを解除する (リーク防止)
-    onDestroy(() => clearToasts());
 </script>
 
 <!-- 上部中央 fixed。複数 toast は縦 stack 表示する -->
diff --git a/resources/js/components/templates/AppLayout.svelte b/resources/js/components/templates/AppLayout.svelte
index 3c1e006..76e6f10 100644
--- a/resources/js/components/templates/AppLayout.svelte
+++ b/resources/js/components/templates/AppLayout.svelte
@@ -26,6 +26,7 @@
     import SidebarUserMenu from "@/components/templates/_helpers/SidebarUserMenu.svelte";
     import type { SharedProps } from "@/lib/shared-props";
     import { consumeFlash } from "@/lib/stores/flash-to-toast";
+    import { clearToasts } from "@/lib/stores/toast";
 
     /**
      * 認証済み画面用レイアウト (左サイドバー型。参照アプリ aigenba 準拠)。
@@ -44,6 +45,10 @@
     // shared props は backend (HandleInertiaRequests) が真実。lib/shared-props.ts の型で読む
     const shared = $derived(page.props as unknown as SharedProps);
 
+    // 消去境界 (DESIGN.md §Toast): layout の初期化時に既存 toast を破棄してから
+    // 当該 visit の flash を消費する。初期化時の 1 回のみ ($effect に載せない)。
+    clearToasts();
+
     $effect(() => {
         consumeFlash(shared.flash);
     });
diff --git a/resources/js/components/templates/AuthLayout.svelte b/resources/js/components/templates/AuthLayout.svelte
index e212747..b61a14a 100644
--- a/resources/js/components/templates/AuthLayout.svelte
+++ b/resources/js/components/templates/AuthLayout.svelte
@@ -3,6 +3,7 @@
     import { page } from "@inertiajs/svelte";
     import ToastContainer from "@/components/organisms/ToastContainer.svelte";
     import { consumeFlash, type FlashPayload } from "@/lib/stores/flash-to-toast";
+    import { clearToasts } from "@/lib/stores/toast";
 
     /**
      * 認証画面 (login / register / reset 等) 用レイアウト。
@@ -20,6 +21,10 @@
 
     let { title, appName, children, footer }: Props = $props();
 
+    // 消去境界 (DESIGN.md §Toast): layout の初期化時に既存 toast を破棄してから
+    // 当該 visit の flash を消費する。初期化時の 1 回のみ ($effect に載せない)。
+    clearToasts();
+
     $effect(() => {
         consumeFlash(page.props.flash as FlashPayload | undefined);
     });
diff --git a/resources/js/components/templates/GuestLayout.svelte b/resources/js/components/templates/GuestLayout.svelte
index f925bcc..cc5f652 100644
--- a/resources/js/components/templates/GuestLayout.svelte
+++ b/resources/js/components/templates/GuestLayout.svelte
@@ -1,13 +1,20 @@
 <script lang="ts">
     import type { Snippet } from "svelte";
+    import { page } from "@inertiajs/svelte";
     import { Menu, X } from "@lucide/svelte";
     import Button from "@/components/atoms/Button.svelte";
+    import ToastContainer from "@/components/organisms/ToastContainer.svelte";
+    import { consumeFlash, type FlashPayload } from "@/lib/stores/flash-to-toast";
+    import { clearToasts } from "@/lib/stores/toast";
 
     /**
      * 未認証公開ページ (LP / Pricing / Contact / Legal) 用レイアウト。
      * ヘッダーのナビとフッターのリンク群は snippet で差し込む。
      * nav は「単純なリンク群 (<a>)」を想定する契約: 広幅ナビと狭幅パネルで二重に
      * @render するため、状態を持つ要素・複雑な構造を snippet に入れないこと。
+     *
+     * Laravel flash は consumeFlash で toast に変換する (visitKey で de-dup)。
+     * これが無いと settings.account.destroy の成功メッセージが誰にも消費されずに捨てられる。
      */
     interface Props {
         appName: string;
@@ -18,6 +25,17 @@
 
     let { appName, children, nav, footerLinks }: Props = $props();
 
+    // 消去境界 (DESIGN.md §Toast): layout の初期化時に既存 toast を破棄してから
+    // 当該 visit の flash を消費する。初期化時の 1 回のみ ($effect に載せると
+    // partial reload 等の再評価で client 側 toast まで巻き込む)。
+    // 未認証面では加えて「認証済み文脈の toast (氏名・組織名を含みうる) を持ち越さない」
+    // 役割も持つ。境界は操作 (ログアウト) ではなく着地に置く (経路の列挙漏れを構造的に防ぐ)。
+    clearToasts();
+
+    $effect(() => {
+        consumeFlash(page.props.flash as FlashPayload | undefined);
+    });
+
     // 狭幅 (sm 未満) のハンバーガー開閉。sm 以上は広幅ナビ表示のため未使用。
     let menuOpen = $state(false);
     // Escape close 時のフォーカス復帰用にトグルボタン DOM を保持
@@ -54,6 +72,8 @@
     }
 </script>
 
+<ToastContainer />
+
 <svelte:window onkeydown={handleKeydown} onclick={handleWindowClick} />
 
 <div class="flex min-h-screen flex-col bg-neutral text-text">
diff --git a/resources/js/pages/Settings/Security.svelte b/resources/js/pages/Settings/Security.svelte
index 96199b7..f16a54f 100644
--- a/resources/js/pages/Settings/Security.svelte
+++ b/resources/js/pages/Settings/Security.svelte
@@ -1,11 +1,13 @@
 <script lang="ts">
     import { tick } from "svelte";
     import { page, router } from "@inertiajs/svelte";
+    import Alert from "@/components/atoms/Alert.svelte";
     import Badge from "@/components/atoms/Badge.svelte";
     import Button from "@/components/atoms/Button.svelte";
     import Card from "@/components/atoms/Card.svelte";
     import Input from "@/components/atoms/Input.svelte";
     import TextLink from "@/components/atoms/TextLink.svelte";
+    import CodeSnippet from "@/components/molecules/CodeSnippet.svelte";
     import FormField from "@/components/molecules/FormField.svelte";
     import ConfirmDialog from "@/components/organisms/ConfirmDialog.svelte";
     import RecentAuthModal from "@/components/organisms/RecentAuthModal.svelte";
@@ -77,7 +79,15 @@
     /** QR 確認待ち (有効化開始済みだが未確認) */
     let confirming = $state(false);
     let enabling = $state(false);
+    /**
+     * enrollment 素材。QR と手動セットアップキーは独立に失敗しうる
+     * (片方でも enrollment は続行できる = カメラ不可端末 / QR 非対応アプリ / 支援技術利用者を詰ませない)。
+     */
     let qrSvg = $state<string | null>(null);
+    let setupKey = $state<string | null>(null);
+    /** 両方の取得に失敗した = enrollment を続行できない (再試行導線を出す) */
+    let enrollmentAssetsFailed = $state(false);
+    let loadingEnrollmentAssets = $state(false);
     let recoveryCodes = $state<string[]>([]);
     let loadingRecoveryCodes = $state(false);
     /** 新コード一覧へのフォーカス移動用 (再生成成功時に再保管を促す) */
@@ -106,15 +116,73 @@
         return (await response.json()) as T;
     }
 
-    async function loadQrCode(): Promise<void> {
+    /**
+     * JSON レスポンスから非空文字列の field を取り出す。
+     * fetchJson の generic は型 assertion にすぎないため shape は信用せず narrowing する
+     * (不正 shape は通信失敗と同じ「その手段が使えない」に畳む)。
+     */
+    function readStringField(payload: unknown, key: string): string | null {
+        if (typeof payload !== "object" || payload === null) return null;
+        const value = (payload as Record<string, unknown>)[key];
+        return typeof value === "string" && value.trim() !== "" ? value : null;
+    }
+
+    /** 単一 endpoint から文字列 field を取得する (通信失敗 / HTTP 失敗 / 不正 shape はすべて null)。
+        表示文言も再試行導線も同一のため種別は区別しない。秘密が絡む経路なので console にも出さない。 */
+    async function fetchStringField(url: string, key: string): Promise<string | null> {
         try {
-            const data = await fetchJson<{ svg: string }>("/user/two-factor-qr-code");
-            qrSvg = data.svg;
+            return readStringField(await fetchJson<unknown>(url), key);
         } catch {
-            addToast("error", "QR コードの取得に失敗しました。再読み込みしてください。");
+            return null;
         }
     }
 
+    /**
+     * 取得世代。**後着優先**の判定に使う。
+     * 破棄 (reset) と取得開始で進み、解決時に世代が変わっていれば結果を捨てる。
+     * これが無いと (a) confirm/disable 成功で消したはずの secret が、遅れて解決した
+     * fetch で再格納される (= サーバの新しい secret とは違うキーを認証アプリに登録させてしまう)
+     * (b) 古い run が loading を握り続けて再有効化が始まらない、の 2 つの競合が起きる。
+     */
+    let enrollmentGeneration = 0;
+
+    /**
+     * enrollment 素材 (QR + 手動セットアップキー) を取得する。
+     * 2 つは独立に扱い、片方が取れれば enrollment を続行できる。
+     * 両方失敗したときだけ「取得失敗 (再試行可)」として提示する。
+     */
+    async function loadEnrollmentAssets(): Promise<void> {
+        const generation = ++enrollmentGeneration;
+        loadingEnrollmentAssets = true;
+
+        const [qr, secret] = await Promise.all([
+            fetchStringField("/user/two-factor-qr-code", "svg"),
+            fetchStringField("/user/two-factor-secret-key", "secretKey"),
+        ]);
+
+        // 世代が進んでいる = 破棄済み or 新しい取得が走っている。結果も loading も触らない
+        // (finally で戻すと古い run が新しい run の loading を消してしまう)
+        if (generation !== enrollmentGeneration) return;
+
+        qrSvg = qr;
+        setupKey = secret;
+        enrollmentAssetsFailed = qr === null && secret === null;
+        loadingEnrollmentAssets = false;
+    }
+
+    /**
+     * enrollment 素材を画面から破棄する (開始時 / confirm 成功時 / 無効化成功時に呼ぶ)。
+     * 世代を進めることで、進行中の取得結果が後から再格納されるのを防ぐ。
+     * TOTP secret の残置時間を enrollment 中に限定する目的も兼ねる。
+     */
+    function resetEnrollmentAssets(): void {
+        enrollmentGeneration += 1;
+        qrSvg = null;
+        setupKey = null;
+        enrollmentAssetsFailed = false;
+        loadingEnrollmentAssets = false;
+    }
+
     /**
      * リカバリコードを取得する。成否を返し、失敗時の文言は呼び出し側が文脈に応じて出す
      * (通常表示: 単純な取得失敗 / 再生成直後: 旧コード失効済みの注意)。
@@ -201,6 +269,8 @@
     }
 
     function enableTwoFactor(): void {
+        // 再試行時に前回の素材・エラーを持ち越さない
+        resetEnrollmentAssets();
         router.post(
             "/user/two-factor-authentication",
             {},
@@ -211,7 +281,7 @@
                 },
                 onSuccess: () => {
                     confirming = true;
-                    void loadQrCode();
+                    void loadEnrollmentAssets();
                 },
                 onFinish: () => {
                     enabling = false;
@@ -228,7 +298,7 @@
             errorBag: CONFIRM_TWO_FACTOR_ERROR_BAG,
             onSuccess: () => {
                 confirming = false;
-                qrSvg = null;
+                resetEnrollmentAssets();
                 confirmForm.reset();
                 showRecoveryCodes();
             },
@@ -249,7 +319,7 @@
                 onSuccess: () => {
                     disableDialogOpen = false;
                     confirming = false;
-                    qrSvg = null;
+                    resetEnrollmentAssets();
                     recoveryCodes = [];
                 },
                 onFinish: () => {
@@ -353,14 +423,66 @@
                 {:else if confirming}
                     <div class="mt-4 flex flex-col gap-4">
                         <p class="text-body text-text-secondary">
-                            認証アプリで以下の QR コードを読み取り、表示されたコードを入力して設定を完了してください。
+                            認証アプリで QR コードを読み取るか、セットアップキーを手動入力し、表示されたコードを入力して設定を完了してください。
                         </p>
-                        {#if qrSvg}
-                            <!-- QR はサーバ提供の SVG をそのまま描画する -->
-                            <div class="self-start rounded-md border border-border bg-surface p-4">
-                                <!-- eslint-disable-next-line svelte/no-at-html-tags -->
-                                {@html qrSvg}
-                            </div>
+                        {#if loadingEnrollmentAssets}
+                            <!-- 取得中に「表示できませんでした」を先出ししない (失敗前に失敗文言を出さない) -->
+                            <p
+                                class="text-caption text-text-secondary"
+                                aria-busy="true"
+                                data-testid="enrollment-assets-loading"
+                            >
+                                認証アプリ設定用の情報を読み込んでいます…
+                            </p>
+                        {:else if enrollmentAssetsFailed}
+                            <Alert
+                                type="danger"
+                                title="設定情報を取得できませんでした"
+                                testId="enrollment-assets-error"
+                            >
+                                QR コードとセットアップキーのどちらも取得できませんでした。
+                                {#snippet action()}
+                                    <Button
+                                        variant="ghost"
+                                        onclick={() => void loadEnrollmentAssets()}
+                                        loading={loadingEnrollmentAssets}
+                                        testId="retry-enrollment-assets-button"
+                                    >
+                                        再試行
+                                    </Button>
+                                {/snippet}
+                            </Alert>
+                        {:else}
+                            {#if qrSvg}
+                                <!-- QR はサーバ提供の SVG をそのまま描画する。svg 文字列に属性を注入せず、
+                                     wrapper を role="img" にしてアクセシブルネームを与える (H14) -->
+                                <div
+                                    role="img"
+                                    aria-label="2 要素認証の設定用 QR コード"
+                                    class="self-start rounded-md border border-border bg-surface p-4"
+                                    data-testid="two-factor-qr"
+                                >
+                                    <!-- eslint-disable-next-line svelte/no-at-html-tags -->
+                                    {@html qrSvg}
+                                </div>
+                            {:else}
+                                <Alert type="warning" testId="qr-unavailable">
+                                    QR コードを表示できませんでした。下のセットアップキーを認証アプリに手動入力してください。
+                                </Alert>
+                            {/if}
+
+                            {#if setupKey}
+                                <div class="flex flex-col gap-2">
+                                    <p class="text-caption text-text-secondary">
+                                        QR コードを読み取れない場合は、次のセットアップキーを認証アプリに手動入力してください。
+                                    </p>
+                                    <CodeSnippet code={setupKey} testId="two-factor-setup-key" />
+                                </div>
+                            {:else}
+                                <Alert type="warning" testId="setup-key-unavailable">
+                                    セットアップキーを表示できませんでした。上の QR コードを認証アプリで読み取ってください。
+                                </Alert>
+                            {/if}
                         {/if}
                         <form novalidate onsubmit={confirmTwoFactor} class="flex flex-col gap-4">
                             <FormField
diff --git a/tests/Browser/FlashToastTest.php b/tests/Browser/FlashToastTest.php
new file mode 100644
index 0000000..094c01b
--- /dev/null
+++ b/tests/Browser/FlashToastTest.php
@@ -0,0 +1,191 @@
+<?php
+
+declare(strict_types=1);
+
+use App\Models\Project;
+use App\Models\VideoManual;
+use Pest\Browser\Api\PendingAwaitablePage;
+
+/*
+|--------------------------------------------------------------------------
+| flash → toast の end-to-end (bug-hunt F-1-02 の再現/反証)
+|--------------------------------------------------------------------------
+|
+| 破壊的操作が別画面へリダイレクトしたとき、着地先で成功 toast が可視であることを固定する。
+| 2 本で 2 種類の遷移を覆う:
+|   1. projects.manuals.destroy → projects.show  (AppLayout → AppLayout)
+|   2. settings.account.destroy → home           (AppLayout → GuestLayout。施策 A-1 の受入)
+|
+| サーバ側 flash は VideoManualCrudTest / AccountDeletionTest が、flash→toast 変換は
+| tests/js/lib/flash-to-toast.test.ts が既に固定している。本テストが担うのは
+| **Inertia のページ再生成をまたいで toast が生き残るか**という結合のみ。
+|
+| 判定の射程 (devnotes/20260804-0021-ux-small-gaps/conceptual-design.md):
+|   - 制御条件 (flash 有り / 着地ページ mount 済み / 3 秒以内に検査) を満たして
+|     一度も可視にならない → H-a (ToastContainer のライフサイクル依存) を支持
+|   - その他の fail → 原因判定不能。テスト条件を調査する (実装を変えない)
+|   - pass → 「自動テスト条件では未再現」まで (bug-hunt 観測が artifact だったことの確定ではない)
+|
+| 実行: composer test:browser (Chromium / WebKit の両レーン)。前提: pnpm build 済み。
+*/
+
+/**
+ * 「着地マーカー」と「成功 toast」を**同一の時間窓で同時に**観測する。
+ *
+ * 2 つを直列に待つと、着地判定が deadline を越えた場合に
+ * 「4 秒目に着地したのに『3 秒以内に着地済み』と誤分類する」ため、必ず同一ループで見る。
+ * deadline は toast の auto-dismiss (4 秒) より短く取り、「見えなかった」を
+ * auto-dismiss と混同しないようにする (制御条件 (iii))。
+ *
+ * 存在 (querySelector != null) ではなく**実可視**で判定する (レンダ順によっては
+ * 非表示のまま DOM に居る瞬間がありうる)。
+ * script() 呼び出しが in-process サーバの event loop を回す (bfcache テストと同じ流儀)。
+ *
+ * @return array{toastVisible: bool, landedWithinDeadline: bool, elapsedMs: int}
+ */
+function observeLandingAndToast(PendingAwaitablePage $page, string $landingSelector, int $timeoutMs = 3000): array
+{
+    $startedAt = hrtime(true);
+    $expression = sprintf(<<<'JS'
+        (() => {
+            const visible = (selector) => {
+                const el = document.querySelector(selector);
+                if (el === null) return false;
+                const style = getComputedStyle(el);
+                return style.visibility !== 'hidden'
+                    && style.display !== 'none'
+                    && el.getClientRects().length > 0;
+            };
+
+            return {
+                landed: visible(%s),
+                toast: visible('[data-testid="toast-success"]'),
+            };
+        })()
+        JS, json_encode($landingSelector, JSON_THROW_ON_ERROR));
+
+    $landed = false;
+
+    while (true) {
+        $state = $page->script($expression);
+        $landed = $landed || (is_array($state) && ($state['landed'] ?? false) === true);
+
+        if (is_array($state) && ($state['toast'] ?? false) === true) {
+            return [
+                'toastVisible' => true,
+                'landedWithinDeadline' => $landed,
+                'elapsedMs' => (int) ((hrtime(true) - $startedAt) / 1_000_000),
+            ];
+        }
+
+        $elapsedMs = (int) ((hrtime(true) - $startedAt) / 1_000_000);
+        if ($elapsedMs >= $timeoutMs) {
+            return ['toastVisible' => false, 'landedWithinDeadline' => $landed, 'elapsedMs' => $elapsedMs];
+        }
+
+        usleep(50_000);
+    }
+}
+
+/**
+ * ブラウザ側の条件が満たされるまで待つ (plugin の assertion は auto-retry するが、
+ * in-process サーバは script() 呼び出しで event loop を回さないと保留リクエストを
+ * 処理できないため、遷移待ちは script() polling で行う)。
+ */
+function waitForBrowserCondition(PendingAwaitablePage $page, string $expression, string $message, int $attempts = 100): void
+{
+    for ($i = 0; $i < $attempts; $i++) {
+        if ($page->script("Boolean({$expression})") === true) {
+            expect(true)->toBeTrue();
+
+            return;
+        }
+        usleep(50_000);
+    }
+
+    throw new RuntimeException("条件が満たされませんでした: {$message} (式: {$expression})");
+}
+
+/**
+ * fail 時の分類を message に載せる (制御条件つき fail かどうかを人が判断できるように)。
+ *
+ * @param  array{toastVisible: bool, landedWithinDeadline: bool, elapsedMs: int}  $observed
+ */
+function assertToastObserved(array $observed, string $what): void
+{
+    if ($observed['toastVisible']) {
+        expect(true)->toBeTrue();
+
+        return;
+    }
+
+    expect($observed['landedWithinDeadline'])->toBeTrue(
+        "{$what}: deadline ({$observed['elapsedMs']}ms) 内に着地マーカーが可視にならなかった "
+        .'= 「その他の fail」。原因判定不能なので実装を変えずにテスト条件を調査すること',
+    );
+
+    expect($observed['toastVisible'])->toBeTrue(
+        "{$what}: 着地は deadline 内に確認できたが成功 toast が可視にならなかった "
+        .'= 制御条件を満たした fail → H-a を支持 (conceptual-design.md の判定表を参照)',
+    );
+}
+
+test('動画マニュアル削除後、リダイレクト先 (AppLayout) で成功 toast が表示される', function (): void {
+    [$organization, $owner] = createOrganizationWithOwner();
+    $project = Project::factory()->forOrganization($organization)->create();
+    $manual = VideoManual::factory()->forProject($project)->create(['title' => '組立手順']);
+
+    $this->actingAs($owner);
+
+    $page = visit("/projects/{$project->id}/manuals/{$manual->id}");
+    $page->assertSee('組立手順');
+
+    // DangerZone → 確認ダイアログ → 削除実行 (testId 指定で text の曖昧一致を避ける)
+    $page->click('@delete-manual-button');
+    $page->assertSee('削除する');
+    $page->click('削除する');
+
+    assertToastObserved(
+        observeLandingAndToast($page, '[data-testid="project-show-heading"]'),
+        'manuals.destroy → projects.show',
+    );
+
+    $page->assertPathIs("/projects/{$project->id}")
+        ->assertSeeIn('[data-testid="toast-success"]', '動画マニュアルを削除しました')
+        ->assertNoJavaScriptErrors();
+});
+
+test('アカウント削除後、未認証面 (GuestLayout) で成功 toast が表示される', function (): void {
+    // recent-auth は Login イベントで stamp される (StampRecentAuthOnLogin)。
+    // actingAs() は Login を発火しないため、**この 1 本だけ UI ログイン**から始める
+    // (ハーネス内部仕様への依存を作らない)。
+    // createOrganizationWithOwner は free plan を grandfather するため課金ゲートに掛からない
+    [, $owner] = createOrganizationWithOwner();
+
+    $page = visit('/login');
+    // 「ログイン」という文言は AuthLayout の見出し h1 とも一致するため text locator は使わない。
+    // login フォームの submit ボタンは 1 つだけ (SSO 導線は <a href>) なので構造 selector で指す。
+    $page->fill('email', $owner->email)   // email は CipherSweet 暗号化だがモデル経由は平文
+        ->fill('password', 'password')    // UserFactory の既定パスワード
+        ->click('form button[type="submit"]');
+
+    waitForBrowserCondition(
+        $page,
+        "window.location.pathname === '/dashboard'",
+        'ログイン後に /dashboard へ着地しない',
+    );
+
+    $page = visit('/settings');
+    $page->click('@delete-account-button');
+    $page->assertSee('本当にアカウントを削除しますか？');
+    $page->click('削除する');
+
+    assertToastObserved(
+        observeLandingAndToast($page, '[data-testid="landing-hero"]'), // Welcome.svelte:142
+        'settings.account.destroy → home (GuestLayout)',
+    );
+
+    $page->assertPathIs('/')
+        ->assertSeeIn('[data-testid="toast-success"]', 'アカウントを削除しました')
+        ->assertNoJavaScriptErrors();
+});
diff --git a/tests/Feature/Api/ApiKeyTest.php b/tests/Feature/Api/ApiKeyTest.php
index e42224f..deefc30 100644
--- a/tests/Feature/Api/ApiKeyTest.php
+++ b/tests/Feature/Api/ApiKeyTest.php
@@ -87,7 +87,8 @@
     $this->actingAs($owner)
         ->withSession(['recent_auth_at' => time()])
         ->delete("/organizations/{$organization->slug}/api-keys/{$apiKey->id}")
-        ->assertRedirect();
+        ->assertRedirect()
+        ->assertSessionHas('success'); // 破壊的操作の flash 規約 (着地先で toast 化される)
 
     expect($apiKey->refresh()->isRevoked())->toBeTrue();
     expect(SecurityAuditEvent::query()->where('event_type', 'api_key_revoked')->exists())->toBeTrue();
diff --git a/tests/Feature/Auth/AccountDeletionTest.php b/tests/Feature/Auth/AccountDeletionTest.php
index ea38255..49a33a3 100644
--- a/tests/Feature/Auth/AccountDeletionTest.php
+++ b/tests/Feature/Auth/AccountDeletionTest.php
@@ -29,6 +29,8 @@
         ->delete('/settings/account');
 
     $response->assertRedirect('/');
+    // 破壊的操作の flash 規約: 着地先 (未認証面 = GuestLayout) で toast として表示される
+    $response->assertSessionHas('success', 'アカウントを削除しました');
     $this->assertGuest();
     expect(User::query()->whereKey($user->id)->exists())->toBeFalse();
     expect(SocialAccount::query()->whereKey($social->id)->exists())->toBeFalse();
diff --git a/tests/Feature/Organizations/OAuthSession/DestroyTest.php b/tests/Feature/Organizations/OAuthSession/DestroyTest.php
index e7a1568..e204030 100644
--- a/tests/Feature/Organizations/OAuthSession/DestroyTest.php
+++ b/tests/Feature/Organizations/OAuthSession/DestroyTest.php
@@ -49,7 +49,8 @@ function makeCliSessionWithToken(Organization $organization, User $user): OauthS
 
     $this->actingAs($owner)->withSession(['recent_auth_at' => time()])
         ->delete("/organizations/{$organization->slug}/api-keys/sessions/{$session->id}")
-        ->assertStatus(302);
+        ->assertStatus(302)
+        ->assertSessionHas('success'); // 破壊的操作の flash 規約 (着地先で toast 化される)
 
     expect(OauthSession::query()->findOrFail($session->id)->isRevoked())->toBeTrue();
 
diff --git a/tests/js/components/organisms/ToastContainer.test.ts b/tests/js/components/organisms/ToastContainer.test.ts
index 476eef1..4dd7e4e 100644
--- a/tests/js/components/organisms/ToastContainer.test.ts
+++ b/tests/js/components/organisms/ToastContainer.test.ts
@@ -40,6 +40,23 @@ describe("ToastContainer", () => {
         expect(screen.getByTestId("toast-warning")).toBeInTheDocument();
     });
 
+    it("unmount → 再 mount しても toast は残る (消去責務は container に無い)", async () => {
+        // 消去境界は layout の初期化に一本化してある (DESIGN.md §Toast)。container が
+        // unmount で clearToasts() すると、着地先で flash を表示する契約の成否が
+        // Svelte の破棄/フラッシュ順に依存してしまう。
+        const first = render(ToastContainer);
+        addToast("success", "リダイレクト前に積んだ通知");
+        expect(await screen.findByTestId("toast-success")).toBeInTheDocument();
+
+        first.unmount();
+        expect(get(toasts)).toHaveLength(1);
+
+        render(ToastContainer);
+        expect(await screen.findByTestId("toast-success")).toHaveTextContent(
+            "リダイレクト前に積んだ通知",
+        );
+    });
+
     it("閉じるボタンで toast が消える", async () => {
         render(ToastContainer);
         addToast("error", "手動で閉じる");
diff --git a/tests/js/components/templates/AppLayout.test.ts b/tests/js/components/templates/AppLayout.test.ts
index 68163a8..9156dba 100644
--- a/tests/js/components/templates/AppLayout.test.ts
+++ b/tests/js/components/templates/AppLayout.test.ts
@@ -4,6 +4,8 @@ import { createRawSnippet } from "svelte";
 import { page } from "@inertiajs/svelte";
 import AppLayout from "@/components/templates/AppLayout.svelte";
 import type { AuthUser, CurrentOrganization } from "@/lib/shared-props";
+import { resetFlashConsumption } from "@/lib/stores/flash-to-toast";
+import { addToast, clearToasts } from "@/lib/stores/toast";
 
 // router をモックし page state は実物を使う (テスト毎に props を差し替える)
 const { routerMock } = vi.hoisted(() => ({
@@ -71,6 +73,8 @@ afterEach(() => {
     cleanup();
     routerMock.post.mockReset();
     localStorage.clear();
+    clearToasts();
+    resetFlashConsumption();
     setPageProps({});
 });
 
@@ -300,6 +304,26 @@ describe("templates/AppLayout", () => {
         expect(screen.queryByTestId("app-user-menu")).toBeNull();
     });
 
+    // --- toast の消去境界 (T095 / DESIGN.md §Toast) ---
+
+    it("layout の初期化で既存 toast を破棄し、当該 visit の flash を表示する", async () => {
+        // 遷移前ページで積まれた toast は layout の再初期化で消え、着地応答の flash が新規に出る。
+        // 消去責務は ToastContainer.onDestroy ではなく layout 初期化にある (順序が決定的)。
+        addToast("error", "前のページのエラー");
+        setPageProps({
+            auth: { user: authUser() },
+            notifications: { unreadCount: 0 },
+            currentOrganization: org(),
+            flash: { success: "動画マニュアルを削除しました", visitKey: "visit-1" },
+        });
+        renderApp();
+
+        expect(await screen.findByTestId("toast-success")).toHaveTextContent(
+            "動画マニュアルを削除しました",
+        );
+        expect(screen.queryByText("前のページのエラー")).toBeNull();
+    });
+
     it("サイドバー折りたたみ状態を localStorage から復元する", () => {
         localStorage.setItem("aicue:layout:sidebarOpen", "false");
         setPageProps({
diff --git a/tests/js/components/templates/AuthLayout.test.ts b/tests/js/components/templates/AuthLayout.test.ts
new file mode 100644
index 0000000..cfc2587
--- /dev/null
+++ b/tests/js/components/templates/AuthLayout.test.ts
@@ -0,0 +1,66 @@
+import { afterEach, beforeEach, describe, expect, it } from "vitest";
+import { createRawSnippet } from "svelte";
+import { cleanup, render, screen } from "@testing-library/svelte";
+import { page } from "@inertiajs/svelte";
+import AuthLayout from "@/components/templates/AuthLayout.svelte";
+import { resetFlashConsumption } from "@/lib/stores/flash-to-toast";
+import { addToast, clearToasts } from "@/lib/stores/toast";
+
+/*
+ * AuthLayout の flash 取り込みと toast の消去境界 (T095 / DESIGN.md §Toast)。
+ * 未認証 layout は「初期化時に既存 toast を破棄 → 当該 visit の flash を消費」の順で動く
+ * (認証済み文脈の toast は氏名・組織名を含みうるため未認証面へ持ち越さない)。
+ */
+
+const children = createRawSnippet(() => ({ render: () => `<p>content</p>` }));
+
+function setPageProps(props: Record<string, unknown>): void {
+    page.props = props as typeof page.props;
+}
+
+beforeEach(() => {
+    clearToasts();
+    resetFlashConsumption();
+    setPageProps({});
+});
+
+afterEach(() => {
+    cleanup();
+    clearToasts();
+    setPageProps({});
+});
+
+describe("templates/AuthLayout", () => {
+    it("flash.success を toast として描画する", async () => {
+        setPageProps({ flash: { success: "パスワードを再設定しました", visitKey: "visit-1" } });
+
+        render(AuthLayout, { props: { title: "ログイン", children } });
+
+        expect(await screen.findByTestId("toast-success")).toHaveTextContent(
+            "パスワードを再設定しました",
+        );
+    });
+
+    it("着地前から存在する toast は描画しない (認証文脈の持ち越し防止)", async () => {
+        addToast("success", "「アクメ社」に切り替えました");
+        setPageProps({ flash: { success: "パスワードを再設定しました", visitKey: "visit-2" } });
+
+        render(AuthLayout, { props: { title: "ログイン", children } });
+
+        expect(await screen.findByTestId("toast-success")).toHaveTextContent(
+            "パスワードを再設定しました",
+        );
+        expect(screen.queryByText("「アクメ社」に切り替えました")).toBeNull();
+    });
+
+    it("再レンダー (props 更新) では clear が走らない (初期化時の 1 回のみ)", async () => {
+        const { rerender } = render(AuthLayout, { props: { title: "ログイン", children } });
+
+        addToast("info", "レンダー後に積んだ通知");
+        expect(await screen.findByTestId("toast-info")).toBeInTheDocument();
+
+        await rerender({ title: "パスワード再設定", children });
+
+        expect(screen.getByTestId("toast-info")).toHaveTextContent("レンダー後に積んだ通知");
+    });
+});
diff --git a/tests/js/components/templates/GuestLayout.test.ts b/tests/js/components/templates/GuestLayout.test.ts
index 3948e2f..9f2503c 100644
--- a/tests/js/components/templates/GuestLayout.test.ts
+++ b/tests/js/components/templates/GuestLayout.test.ts
@@ -1,12 +1,19 @@
-import { describe, expect, it } from "vitest";
+import { afterEach, beforeEach, describe, expect, it } from "vitest";
 import { createRawSnippet } from "svelte";
-import { fireEvent, render, screen, within } from "@testing-library/svelte";
+import { cleanup, fireEvent, render, screen, within } from "@testing-library/svelte";
+import { page } from "@inertiajs/svelte";
 import GuestLayout from "@/components/templates/GuestLayout.svelte";
+import { resetFlashConsumption } from "@/lib/stores/flash-to-toast";
+import { addToast, clearToasts } from "@/lib/stores/toast";
 
 /*
  * GuestLayout の狭幅ハンバーガー化 (T027)。nav 未指定でトグル・パネルが出ないこと、
  * nav 指定でトグルが出ることを固定する。実挙動 (Escape / パネル内リンク) の主検証は
  * Welcome.test.ts が担う。
+ *
+ * 併せて flash → toast の取り込みと消去境界 (T095 / DESIGN.md §Toast) を固定する:
+ * 未認証面に着地する破壊的操作 (settings.account.destroy) の成功メッセージが表示され、
+ * かつ認証済み文脈の toast (氏名・組織名を含みうる) を持ち越さないこと。
  */
 
 const children = createRawSnippet(() => ({ render: () => `<p>content</p>` }));
@@ -14,6 +21,22 @@ const nav = createRawSnippet(() => ({
     render: () => `<a href="/pricing">料金プラン</a>`,
 }));
 
+function setPageProps(props: Record<string, unknown>): void {
+    page.props = props as typeof page.props;
+}
+
+beforeEach(() => {
+    clearToasts();
+    resetFlashConsumption();
+    setPageProps({});
+});
+
+afterEach(() => {
+    cleanup();
+    clearToasts();
+    setPageProps({});
+});
+
 describe("GuestLayout", () => {
     it("nav を渡さないとハンバーガー・パネルを描画しない (Contact 相当)", () => {
         render(GuestLayout, { props: { appName: "AI-CUE", children } });
@@ -31,4 +54,43 @@ describe("GuestLayout", () => {
         const panel = screen.getByTestId("guest-nav-panel");
         expect(within(panel).getByRole("link", { name: "料金プラン" })).toBeInTheDocument();
     });
+
+    it("flash.success を toast として描画する (未認証面に着地する破壊的操作のフィードバック)", async () => {
+        setPageProps({
+            flash: { success: "アカウントを削除しました", visitKey: "visit-1" },
+        });
+
+        render(GuestLayout, { props: { appName: "AI-CUE", children } });
+
+        expect(await screen.findByTestId("toast-success")).toHaveTextContent(
+            "アカウントを削除しました",
+        );
+    });
+
+    it("着地前から存在する toast は描画しない (認証文脈の持ち越し防止)", async () => {
+        addToast("success", "「山田太郎」の 2 段階認証を解除しました");
+        setPageProps({
+            flash: { success: "アカウントを削除しました", visitKey: "visit-2" },
+        });
+
+        render(GuestLayout, { props: { appName: "AI-CUE", children } });
+
+        // 当該 visit の flash は出る
+        expect(await screen.findByTestId("toast-success")).toHaveTextContent(
+            "アカウントを削除しました",
+        );
+        // 認証済み画面で積まれた PII 入り toast は消えている
+        expect(screen.queryByText("「山田太郎」の 2 段階認証を解除しました")).toBeNull();
+    });
+
+    it("再レンダー (props 更新) では clear が走らない (初期化時の 1 回のみ)", async () => {
+        const { rerender } = render(GuestLayout, { props: { appName: "AI-CUE", children } });
+
+        addToast("info", "レンダー後に積んだ通知");
+        expect(await screen.findByTestId("toast-info")).toBeInTheDocument();
+
+        await rerender({ appName: "別名", children });
+
+        expect(screen.getByTestId("toast-info")).toHaveTextContent("レンダー後に積んだ通知");
+    });
 });
diff --git a/tests/js/pages/SettingsSecurityTwoFactorConfirm.test.ts b/tests/js/pages/SettingsSecurityTwoFactorConfirm.test.ts
index 06912ce..0d8e394 100644
--- a/tests/js/pages/SettingsSecurityTwoFactorConfirm.test.ts
+++ b/tests/js/pages/SettingsSecurityTwoFactorConfirm.test.ts
@@ -51,13 +51,16 @@ function jsonResponse(ok: boolean, status: number, body: unknown): unknown {
     return { ok, status, json: () => Promise.resolve(body) };
 }
 
-/** 確認フロー描画に必要な fetch (QR / recent-auth / recovery codes) を stub する */
+/** 確認フロー描画に必要な fetch (QR / secret key / recent-auth / recovery codes) を stub する */
 function stubFetchRoutes(): void {
     fetchMock.mockImplementation((input: RequestInfo | URL) => {
         const url = String(input);
         if (url.includes("/user/two-factor-qr-code")) {
             return Promise.resolve(jsonResponse(true, 200, { svg: "<svg></svg>" }));
         }
+        if (url.includes("/user/two-factor-secret-key")) {
+            return Promise.resolve(jsonResponse(true, 200, { secretKey: "ABCDEFGH12345678" }));
+        }
         if (url.includes("/recent-auth/status")) {
             return Promise.resolve(
                 jsonResponse(true, 200, {
@@ -194,5 +197,257 @@ describe("Settings/Security 2FA 確認 (F-2-02: 誤コードエラー表示)", (
         expect(form.reset).toHaveBeenCalled();
         // 有効化ボタンに戻る (twoFactorEnabled は依然 false のため)
         expect(screen.getByTestId("enable-two-factor-button")).toBeInTheDocument();
+        // enrollment 素材 (TOTP secret) は画面から破棄される (残置時間を enrollment 中に限定する)
+        expect(screen.queryByTestId("two-factor-setup-key")).toBeNull();
+    });
+});
+
+/*
+ * 施策 C (bug-hunt F-4-02 / a11y H14): 2FA enrollment に手動セットアップキーを出し、
+ * QR にアクセシブルネームを与える。カメラ不可端末 / QR 非対応の認証アプリ /
+ * スクリーンリーダー利用者が enrollment を完了できないことを防ぐ。
+ */
+
+/** 解決タイミングを外から制御する promise */
+interface Deferred {
+    promise: Promise<unknown>;
+    resolve: (value: unknown) => void;
+}
+
+function createDeferred(): Deferred {
+    let resolve: (value: unknown) => void = () => undefined;
+    const promise = new Promise<unknown>((res) => {
+        resolve = res;
+    });
+
+    return { promise, resolve };
+}
+
+/** enrollment 素材の fetch だけを保留させる stub (解決順序を検証するため) */
+function stubDeferredEnrollmentFetch(): { qr: Deferred[]; secret: Deferred[] } {
+    const qr: Deferred[] = [];
+    const secret: Deferred[] = [];
+
+    fetchMock.mockImplementation((input: RequestInfo | URL) => {
+        const url = String(input);
+        if (url.includes("/user/two-factor-qr-code")) {
+            const deferred = createDeferred();
+            qr.push(deferred);
+            return deferred.promise;
+        }
+        if (url.includes("/user/two-factor-secret-key")) {
+            const deferred = createDeferred();
+            secret.push(deferred);
+            return deferred.promise;
+        }
+        if (url.includes("/recent-auth/status")) {
+            return Promise.resolve(
+                jsonResponse(true, 200, {
+                    recent: true,
+                    passwordSet: true,
+                    availableProviders: [],
+                    canSatisfy: true,
+                    confirmedAt: 1,
+                }),
+            );
+        }
+        return Promise.resolve(jsonResponse(true, 200, ["code-a", "code-b"]));
+    });
+
+    return { qr, secret };
+}
+
+/** enrollment 素材の応答を個別に差し替える (未指定は既定の成功応答) */
+function stubEnrollmentFetch(overrides: { qr?: unknown; secret?: unknown }): void {
+    fetchMock.mockImplementation((input: RequestInfo | URL) => {
+        const url = String(input);
+        if (url.includes("/user/two-factor-qr-code")) {
+            return Promise.resolve(overrides.qr ?? jsonResponse(true, 200, { svg: "<svg></svg>" }));
+        }
+        if (url.includes("/user/two-factor-secret-key")) {
+            return Promise.resolve(
+                overrides.secret ?? jsonResponse(true, 200, { secretKey: "ABCDEFGH12345678" }),
+            );
+        }
+        if (url.includes("/recent-auth/status")) {
+            return Promise.resolve(
+                jsonResponse(true, 200, {
+                    recent: true,
+                    passwordSet: true,
+                    availableProviders: [],
+                    canSatisfy: true,
+                    confirmedAt: 1,
+                }),
+            );
+        }
+        return Promise.resolve(jsonResponse(true, 200, ["code-a", "code-b"]));
+    });
+}
+
+/** 有効化ボタンを押し、router.post の onSuccess を発火して confirming 状態にする */
+async function startEnrollment(): Promise<void> {
+    await fireEvent.click(screen.getByTestId("enable-two-factor-button"));
+    await waitFor(() => {
+        expect(routerPostMock).toHaveBeenCalled();
+    });
+    lastRouterVisitOptions().onSuccess?.();
+}
+
+describe("Settings/Security 2FA enrollment 素材 (F-4-02: 手動セットアップキー / H14: QR の a11y)", () => {
+    it("有効化開始でセットアップキーを取得し画面に表示する", async () => {
+        render(Security, { props: {} });
+
+        await openConfirmForm();
+
+        expect(fetchMock).toHaveBeenCalledWith(
+            "/user/two-factor-secret-key",
+            expect.objectContaining({ headers: { Accept: "application/json" } }),
+        );
+        await waitFor(() => {
+            expect(screen.getByTestId("two-factor-setup-key-body")).toHaveTextContent(
+                "ABCDEFGH12345678",
+            );
+        });
+    });
+
+    it("QR の wrapper に role=img とアクセシブルネームがある", async () => {
+        render(Security, { props: {} });
+
+        await openConfirmForm();
+
+        await waitFor(() => {
+            expect(
+                screen.getByRole("img", { name: "2 要素認証の設定用 QR コード" }),
+            ).toBeInTheDocument();
+        });
+    });
+
+    it("QR 取得失敗でもセットアップキーで継続できる", async () => {
+        stubEnrollmentFetch({ qr: jsonResponse(false, 500, null) });
+        render(Security, { props: {} });
+
+        await openConfirmForm();
+
+        await waitFor(() => {
+            expect(screen.getByTestId("qr-unavailable")).toBeInTheDocument();
+        });
+        expect(screen.getByTestId("two-factor-setup-key-body")).toHaveTextContent(
+            "ABCDEFGH12345678",
+        );
+        // 認証コード入力は残る = enrollment を続行できる
+        expect(screen.getByLabelText("認証コード")).toBeInTheDocument();
+        expect(screen.queryByTestId("enrollment-assets-error")).toBeNull();
+    });
+
+    it("セットアップキーが不正 shape でも QR で継続できる (不正 shape = 取得失敗と同経路)", async () => {
+        stubEnrollmentFetch({ secret: jsonResponse(true, 200, {}) });
+        render(Security, { props: {} });
+
+        await openConfirmForm();
+
+        await waitFor(() => {
+            expect(screen.getByTestId("setup-key-unavailable")).toBeInTheDocument();
+        });
+        expect(screen.getByTestId("two-factor-qr")).toBeInTheDocument();
+        expect(screen.getByLabelText("認証コード")).toBeInTheDocument();
+        expect(screen.queryByTestId("enrollment-assets-error")).toBeNull();
+    });
+
+    it("両方失敗したときは再試行導線を出し、押下で再取得する", async () => {
+        stubEnrollmentFetch({
+            qr: jsonResponse(false, 500, null),
+            secret: jsonResponse(false, 500, null),
+        });
+        render(Security, { props: {} });
+
+        await openConfirmForm();
+
+        await waitFor(() => {
+            expect(screen.getByTestId("enrollment-assets-error")).toBeInTheDocument();
+        });
+        const retry = screen.getByTestId("retry-enrollment-assets-button");
+        expect(retry).not.toBeDisabled(); // 禁止事項 8: 条件未充足で disabled にしない
+
+        // 再試行で取得できるようにしてから押す
+        stubEnrollmentFetch({});
+        await fireEvent.click(retry);
+
+        await waitFor(() => {
+            expect(screen.getByTestId("two-factor-setup-key-body")).toHaveTextContent(
+                "ABCDEFGH12345678",
+            );
+        });
+        expect(screen.queryByTestId("enrollment-assets-error")).toBeNull();
+    });
+
+    it("取得中は失敗文言を出さない", async () => {
+        const deferred = stubDeferredEnrollmentFetch();
+        render(Security, { props: {} });
+
+        await startEnrollment();
+
+        await waitFor(() => {
+            expect(screen.getByTestId("enrollment-assets-loading")).toBeInTheDocument();
+        });
+        expect(screen.queryByTestId("qr-unavailable")).toBeNull();
+        expect(screen.queryByTestId("setup-key-unavailable")).toBeNull();
+        expect(screen.queryByTestId("enrollment-assets-error")).toBeNull();
+
+        deferred.qr[0].resolve(jsonResponse(true, 200, { svg: "<svg></svg>" }));
+        deferred.secret[0].resolve(jsonResponse(true, 200, { secretKey: "ABCDEFGH12345678" }));
+
+        await waitFor(() => {
+            expect(screen.queryByTestId("enrollment-assets-loading")).toBeNull();
+        });
+        expect(screen.getByTestId("two-factor-setup-key-body")).toHaveTextContent(
+            "ABCDEFGH12345678",
+        );
+    });
+
+    it("後着優先: 古い取得が後から解決しても新しいセットアップキーを上書きしない", async () => {
+        // 旧取得が後勝ちすると、サーバが持つ新しい secret とは違うキーを認証アプリへ登録させてしまい
+        // enrollment が必ず失敗する。観測可能な順序 (reset → 新取得の表示 → 旧取得の解決) で固定する。
+        const deferred = stubDeferredEnrollmentFetch();
+        render(Security, { props: {} });
+
+        // (1) 有効化 → 取得 1 は保留のまま
+        await startEnrollment();
+        await waitFor(() => {
+            expect(screen.getByTestId("enrollment-assets-loading")).toBeInTheDocument();
+        });
+
+        // (2) confirm 成功で enrollment 素材を破棄 (世代が進む)
+        await submitConfirm("123456");
+        lastConfirmPostOptions().onSuccess?.();
+        await waitFor(() => {
+            expect(screen.getByTestId("enable-two-factor-button")).toBeInTheDocument();
+        });
+
+        // (3) 再度有効化 → 取得 2 を開始 (古い run が loading を握っていないことの固定も兼ねる)
+        await startEnrollment();
+        await waitFor(() => {
+            expect(screen.getByTestId("enrollment-assets-loading")).toBeInTheDocument();
+        });
+
+        // (4) 取得 2 を NEWKEY で解決して画面に出す
+        deferred.qr[1].resolve(jsonResponse(true, 200, { svg: "<svg></svg>" }));
+        deferred.secret[1].resolve(jsonResponse(true, 200, { secretKey: "NEWKEY0987654321" }));
+        await waitFor(() => {
+            expect(screen.getByTestId("two-factor-setup-key-body")).toHaveTextContent(
+                "NEWKEY0987654321",
+            );
+        });
+
+        // (5) その後で取得 1 を OLDKEY で解決 → 画面は NEWKEY のまま
+        deferred.qr[0].resolve(jsonResponse(true, 200, { svg: "<svg></svg>" }));
+        deferred.secret[0].resolve(jsonResponse(true, 200, { secretKey: "OLDKEY1234567890" }));
+        // マクロタスク境界まで進めて、旧取得の promise チェーンと Svelte の反映を完全に流す
+        // (「出ないこと」の検証なので waitFor では待ちきれない)
+        await new Promise((resolve) => setTimeout(resolve, 0));
+
+        expect(screen.getByTestId("two-factor-setup-key-body")).toHaveTextContent(
+            "NEWKEY0987654321",
+        );
+        expect(screen.queryByText("OLDKEY1234567890")).toBeNull();
     });
 });
```

## テスト結果 (全 green)

- `composer test`: 2591 tests, 2589 passed, 2 skipped, 10408 assertions
- `composer phpstan`: No errors (level 10, 747 files)
- `vendor/bin/pint --test`: passed
- `pnpm lint` / `pnpm typecheck`: passed
- `pnpm test`: 101 files, 934 tests passed
- `pnpm build`: OK
- `composer test:browser`: Chromium 13 tests (10 passed / 3 skipped)、WebKit 同じ (skip は既存の bfcache 再現不能シナリオ)

### 施策 B-1 の「変更前」実行結果 (F-1-02 の判定に使用)

現行コード (実装前) のまま `composer test:browser -- --filter=FlashToast` を両レーンで実行した結果:

- テスト 1 (`projects.manuals.destroy` → `projects.show`、AppLayout→AppLayout): **PASS** (Chromium / WebKit とも)
  → 概念設計の判定表に従い「自動テスト条件では未再現」。H-a (ToastContainer のライフサイクル依存) は支持されない。
    H-b (観測 artifact) の確定でもない。
- テスト 2 (`settings.account.destroy` → `/`、AppLayout→GuestLayout): **制御条件を満たして FAIL** (両レーン)
  → 着地マーカー (`landing-hero`) は deadline 内に可視になったが `toast-success` は一度も可視にならなかった。
    = GuestLayout に flash 取り込みが無いという横断確認どおりの構造欠落を実測で確認。施策 A-1 の受入テストとして機能した。

## design system 参照 (DESIGN.md の関連節)

- footer は Button atom(cancel=`ghost` / confirm=`confirmVariant`、processing 中は loading)
- confirm で自動 close しない(処理完了後に呼び出し側が `open=false` にする)。
  cancel / ESC / overlay / X は `onCancel` を発火して close
- `banner?: Snippet` は message 直上の任意スロット(サーバ validation エラーの Alert 等)。
  未指定なら描画されない(既存の出力は不変)

### Toast

実装: `components/organisms/ToastContainer.svelte` + `lib/stores/toast.ts`(addToast / dismissToast)。
Laravel flash の取り込みは `lib/stores/flash-to-toast.ts` の `consumeFlash`(visitKey で de-dup)。

- 上部中央 fixed(`top-6 left-1/2 -translate-x-1/2 z-50`)に縦 stack 表示。アプリで 1 箇所のみ mount する
  (mount するのは layout: AppLayout / AuthLayout / GuestLayout の 3 種。ページ側では mount しない)
- 自動消去: **success / info / warning = 4 秒、error = 手動閉じのみ**
- 消去境界: **layout(AppLayout / AuthLayout / GuestLayout)の初期化時に既存 toast を破棄**してから
  当該 visit の flash を消費する。= **layout が再初期化される遷移**では toast を持ち越さない
  (認証済み文脈の toast を未認証面へ出さない)。`preserveState` の visit / partial reload は
  layout を再初期化しないため toast は残る。別タブの既表示 toast の即時消去は保証しない
- 各 toast は `bg-surface` + type 別 border / アイコン色(success / primary(info)/ warning / danger)。
  アイコンは CircleCheck / Info / TriangleAlert / CircleX(@lucide/svelte)
- a11y: `role="status"`(error のみ `role="alert"`)

### Alert

実装: `components/atoms/Alert.svelte`。ページ内に常在するインライン通知ボックス
(一時通知は Toast、フィールド単位のエラーは FormField/FormError を使う)。

- type: `success` / `warning` / `danger` / `info`(info は primary を流用。Toast と同じ規約)
- 配色: ボーダー=状態色、見出し(title 任意)=状態色、本文=`text-text`、背景=`bg-surface`。
  テーマ色を面塗りに使わない。中間 box なので `rounded-md`
- `action` snippet(本文下の CTA)、`dismissible` + `onDismiss`(右上の X)を持つ
- a11y: **danger のみ `role="alert"`(assertive)**、他は `role="status"`(polite)

### FormField

実装: `components/molecules/FormField.svelte`。ラベル + 入力 + エラー(FormError)+
ヘルプの複合 molecule。入力 atom を最小責務に保つため、ラベル・エラー文言・
`aria-describedby` の配線は本 molecule が担う(関心分離)。children snippet に

### 触れた atomic ディレクトリ

- `resources/js/components/atoms/Alert.svelte` (既存 atom を利用。変更なし)
- `resources/js/components/molecules/CodeSnippet.svelte` (既存 molecule を利用。変更なし)
- `resources/js/components/organisms/ToastContainer.svelte` (変更: onDestroy(clearToasts) 撤去)
- `resources/js/components/templates/{AppLayout,AuthLayout,GuestLayout}.svelte` (変更: 初期化時 clearToasts)
- `resources/js/pages/Settings/Security.svelte` (page から atom/molecule を import)

## 補足 (実装時に設計から逸脱した点。妥当性を判定してほしい)

1. Browser テスト 2 本目の UI ログイン手順で、設計の `press('ログイン')` は
   AuthLayout の見出し `<h1>ログイン</h1>` にも一致してしまい submit されなかった (実測)。
   構造 selector `form button[type="submit"]` に変更した。
2. 同じく、pest-plugin-browser の in-process サーバは PHP テストプロセス自身なので
   `usleep` 中は保留リクエストを処理できない。設計の `assertPathIs('/dashboard')` では
   ログイン遷移を待てなかったため、`script()` polling の `waitForBrowserCondition()` ヘルパで待つようにした
   (既存 `InertiaHistoryRestoreAfterLogoutTest` と同じ流儀)。
3. `loadQrCode()` にあった「QR コードの取得に失敗しました」の error toast は撤去した
   (設計どおり Alert 表示へ置換。DESIGN.md §Toast/§Alert の「ページ常在の通知は Alert」に従う)。
