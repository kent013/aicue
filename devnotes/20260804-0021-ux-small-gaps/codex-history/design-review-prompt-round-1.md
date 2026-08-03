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

あなたは経験豊富なWebアプリケーションアーキテクトです。Laravel + Svelte アプリケーション改善の詳細設計をレビューしてください。

【前提環境】
- PHP 8.4 + Laravel 12 + Svelte 5 (runes) + Inertia.js v3 (svelte adapter 3.3.1) + TypeScript
- PHPStan level 10 / Pest / DTO + JsonResource パターン / Laratrust RBAC
- Browser テストは pest-plugin-browser (in-process サーバ + Playwright)、Chromium + WebKit の 2 レーン
- 本アプリは Inertia SSR 未使用

【レビュー観点】
1. コードの正確性（ロジックエラー、エッジケース、null安全性）
2. 既存コードとの整合性（命名規約、パターン、API）
3. PHPStan level 10 適合性
4. テスト計画の網羅性（各施策に Pest / vitest テスト、RefreshDatabase グローバル適用に従う）
5. DTO/JsonResource パターンの遵守
6. Inertia Props vs API Responseの使い分け
7. 副作用・後退リスク
8. 波及変更の網羅性（TypeScript型定義、API Resource、テストが変更対象に含まれているか）
9. セキュリティ（認可チェック、入力バリデーション、OWASP Top 10、AGENTS.md のセキュリティ不変条件）
10. DESIGN.md準拠（UI/frontend 変更を含む）: design token 経由の参照か、hex 直書きを増やさないか
11. Atomic Design準拠: atoms/molecules/organisms/templates/pages の単方向 import と責務分離

【出力形式】
- 各施策ごとに判定: APPROVE / REQUEST_CHANGES
- 指摘は [Critical] [Warning] [Suggestion] で分類
- Critical/Warning には必ず修正案を添える
- 全体判定: APPROVED / CHANGES_REQUESTED
- 日本語で出力

【注意】
この会話ではあなたはファイルを読めない可能性があります。読めない場合は、
提示された「関連する現行コード」の抜粋のみを根拠に判断し、
推測が必要な箇所は「未検証」と明示してください。

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

**事実検証の所在**: Codex は本環境でファイル読取手段を持てなかったため、本書の `ファイル:行`
主張はすべて Claude 側が実ファイルを読んで確認したものである。

## 施策一覧

実装順序は「B-1 → C → A-1+A-2 → B-2 判断」。中間状態を作らないための順序であり、
A-1 と A-2 は同一コミットで入れる。

| # | 施策名 | 変更ファイル | 優先度 |
|---|--------|------------|--------|
| B-1 | flash → toast の end-to-end 再現テスト (実装変更なし) | `tests/Browser/FlashToastTest.php` (新規) | 最初に実施 |
| C | 2FA enrollment の手動セットアップキー + QR アクセシブルネーム | `resources/js/pages/Settings/Security.svelte`、`tests/js/pages/SettingsSecurityTwoFactorConfirm.test.ts` | 高（主施策） |
| A-1 | `GuestLayout` の flash 取り込み | `resources/js/components/templates/GuestLayout.svelte`、`tests/js/components/templates/GuestLayout.test.ts`、`tests/Feature/Auth/AccountDeletionTest.php` / `tests/Feature/Api/ApiKeyTest.php` / `tests/Feature/Organizations/OAuthSession/DestroyTest.php` | 中 |
| A-2 | 未認証 layout の「認証文脈 toast を持ち越さない」境界 | `resources/js/components/templates/GuestLayout.svelte`、`resources/js/components/templates/AuthLayout.svelte`、`DESIGN.md`、`tests/js/components/templates/{GuestLayout,AuthLayout}.test.ts` | 中（A-1 と不可分） |
| B-2 | `ToastContainer` のライフサイクル境界正規化（**条件付き**） | `resources/js/components/organisms/ToastContainer.svelte`、`tests/js/components/organisms/ToastContainer.test.ts` | B-1 の結果次第 |

---

## 施策 B-1: flash → toast の end-to-end 再現テスト

### 変更箇所

- 新規: `tests/Browser/FlashToastTest.php`
- **アプリコードの変更なし**（現行コードのまま実行して事実を確定させる）

### 波及変更

- TypeScript型定義: なし
- API Resource/DTO: なし
- テストファイル: 新規 1 本のみ

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
| 破壊的操作 (projects.manuals.destroy) が別画面へリダイレクトしたとき、
| 着地先で成功 toast が可視であることを固定する。
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
 * 指定セレクタが可視になるまで待つ。deadline は toast の auto-dismiss (4 秒) より
 * 短く取り、「見えなかった」を auto-dismiss と混同しないようにする。
 * script() 呼び出しが in-process サーバの event loop を回す (bfcache テストと同じ流儀)。
 *
 * @return array{appeared: bool, elapsedMs: int}
 */
function waitForVisibleSelector(PendingAwaitablePage $page, string $selector, int $timeoutMs = 3000): array
{
    $startedAt = hrtime(true);
    $expression = sprintf(
        'Boolean(document.querySelector(%s))',
        json_encode($selector, JSON_THROW_ON_ERROR),
    );

    while (true) {
        if ($page->script($expression) === true) {
            return ['appeared' => true, 'elapsedMs' => (int) ((hrtime(true) - $startedAt) / 1_000_000)];
        }

        $elapsedMs = (int) ((hrtime(true) - $startedAt) / 1_000_000);
        if ($elapsedMs >= $timeoutMs) {
            return ['appeared' => false, 'elapsedMs' => $elapsedMs];
        }

        usleep(50_000);
    }
}

test('動画マニュアル削除後、リダイレクト先で成功 toast が表示される', function (): void {
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

    // 制御条件 (ii): 着地ページが mount 済みであること
    $landed = waitForVisibleSelector($page, '[data-testid="project-show-heading"]');
    expect($landed['appeared'])->toBeTrue('削除後に projects.show へ着地しなかった');

    // 制御条件 (iii): auto-dismiss (4 秒) より前に検査する
    $toast = waitForVisibleSelector($page, '[data-testid="toast-success"]');
    expect($toast['appeared'])->toBeTrue(
        "削除成功 toast が {$toast['elapsedMs']}ms 以内に出現しなかった "
        .'(制御条件を満たした fail = H-a を支持。conceptual-design.md の判定表を参照)',
    );

    $page->assertPathIs("/projects/{$project->id}")
        ->assertSeeIn('[data-testid="toast-success"]', '動画マニュアルを削除しました')
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
  （`resources/js/pages/Manuals/Show.svelte:187`）。この文字列はページ内で一意。

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
 * enrollment 素材 (QR + 手動セットアップキー) を取得する。
 * 2 つは独立に扱い、片方が取れれば enrollment を続行できる。
 * 両方失敗したときだけ「取得失敗 (再試行可)」として提示する。
 */
async function loadEnrollmentAssets(): Promise<void> {
    loadingEnrollmentAssets = true;
    try {
        const [qr, secret] = await Promise.all([
            fetchStringField("/user/two-factor-qr-code", "svg"),
            fetchStringField("/user/two-factor-secret-key", "secretKey"),
        ]);
        qrSvg = qr;
        setupKey = secret;
        enrollmentAssetsFailed = qr === null && secret === null;
    } finally {
        loadingEnrollmentAssets = false;
    }
}

/** enrollment 素材を画面から破棄する (開始時 / confirm 成功時 / 無効化成功時に呼ぶ) */
function resetEnrollmentAssets(): void {
    qrSvg = null;
    setupKey = null;
    enrollmentAssetsFailed = false;
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

    {#if enrollmentAssetsFailed}
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

## 施策 A-1 / A-2: 未認証 layout の flash 取り込みと持ち越し境界

### 変更箇所

- `resources/js/components/templates/GuestLayout.svelte`（script 冒頭 + markup 先頭）
- `resources/js/components/templates/AuthLayout.svelte`（script L23-25 の直前に 1 行）
- `DESIGN.md` §Toast（消去境界の明文化）

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

`AuthLayout.svelte`（`clearToasts` の import と 1 行追加のみ）:

```svelte
    import { clearToasts } from "@/lib/stores/toast";
    ...
    // 未認証 layout の着地境界 (GuestLayout と同一契約)。初期化時の 1 回のみ。
    clearToasts();

    $effect(() => {
        consumeFlash(page.props.flash as FlashPayload | undefined);
    });
```

`DESIGN.md` §Toast に 1 行追加:

```markdown
- 消去境界: 未認証 layout (AuthLayout / GuestLayout) は**初期化時に既存 toast を破棄**してから
  当該 visit の flash を消費する(認証済み文脈の toast を未認証面へ持ち越さない)。
  別タブの既表示 toast の即時消去は保証しない
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
- [x] 既存 `GuestLayout.test.ts` の nav/ハンバーガーのケースは維持（削除・上書きしない）

**Feature (PHP)** — 破壊的操作の flash 規約の回帰固定（横断確認で assert 欠落が判明した 3 経路）:

- [x] `tests/Feature/Auth/AccountDeletionTest.php`:
      `test('step-up 済みならアカウントを削除でき、関連データが掃除される')` の
      `$response->assertRedirect('/');`（L31 付近）の直後に `$response->assertSessionHas('success');`
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

- `clearToasts()` を layout 初期化で呼ぶため、**guest → guest / auth → auth のページ遷移でも
  既存 toast が消える**。現行は `ToastContainer.onDestroy` が同じことをしており挙動は不変。
  B-2 適用後も未認証面ではこの挙動が維持される（認証面では維持されない = 意図どおり）。
- `GuestLayout` に `ToastContainer` を足すことで、同時に 2 つの `ToastContainer` が
  mount される状況（DESIGN.md「アプリで 1 箇所のみ mount」）が起きないことを確認する。
  layout は排他的に使われるため同時 mount は発生しない。

---

## 施策 B-2（条件付き）: `ToastContainer` のライフサイクル境界正規化

**適用条件**: B-1 が**制御条件を満たして** fail した場合のみ
（制御条件 = flash が応答に存在 / 着地ページ mount 済み / 3 秒以内に検査）。
その他の fail では適用せず、テスト条件を調査する。pass の場合も適用しない。

### 変更箇所

- `resources/js/components/organisms/ToastContainer.svelte:2, 25-26`

### 現行コード

```svelte
import { onDestroy } from "svelte";
...
// unmount 時 (SPA 全体破棄等の稀ケース) に残存タイマーを解除する (リーク防止)
onDestroy(() => clearToasts());
```

### 変更後コード

```svelte
// toast ストアは module singleton で、ページ遷移をまたいで生存する
// (flash-after-redirect の前提)。本 component は AppLayout / AuthLayout / GuestLayout の
// 中に置かれるため **全ページ遷移で unmount される**。ここで clearToasts() すると
// 「着地先で flash を表示する」契約と競合し、正しさが Svelte の破棄/フラッシュ順に依存する。
// 消去境界は未認証 layout の初期化 (DESIGN.md §Toast) に一本化する。
// 自動消去タイマーは dismissToast が clearTimeout + timers.delete するため残らない。
```

（`onDestroy` の import も削除する。`clearToasts` の import は不要になるため削除し、
`dismissToast` / `toasts` のみ残す。）

### PHPStan適合チェック

- 該当なし

### テスト計画

- [x] `tests/js/components/organisms/ToastContainer.test.ts` に追加:
      `unmount → 再 mount しても toast が残る` — `addToast` → `cleanup()` →
      `render(ToastContainer)` → 文言が見える
- [x] B-1 の Browser テストが green になることを確認（これが本施策の受入条件）
- [x] `timers` Map の残存は直接 assert しない（module private。内部状態を export するのは
      テストのために設計を緩める行為）。観測可能な振る舞いは
      `tests/js/lib/toast.test.ts:42-73` が既に固定している

### リスク

- 既存 JS テストが「unmount で toast が消える」ことに暗黙依存している可能性がある。
  `tests/js/components/organisms/ToastContainer.test.ts` と `tests/js/lib/*.test.ts` は
  `beforeEach`/`afterEach` で `clearToasts()` を呼んでいるため影響しないが、
  **ページ系テストで toast を assert しているもの**（`SettingsSecurity.test.ts` /
  `ScenarioEditor.test.ts` / `NotificationListItem.test.ts`）は `pnpm test` 全 green で確認する。
  漏れがあれば各テストの `beforeEach` に `clearToasts()` を足す（実装を歪めない対処）。

---

## 検証コマンド

```bash
pnpm lint && pnpm typecheck && pnpm test      # 施策 C / A / B-2 (JS)
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
| 判断根拠 | 3 施策とも小規模で、変更ファイルが互いに重複しない（`Settings/Security.svelte` / `templates/*.svelte` / `organisms/ToastContainer.svelte`）。1 セッションで B-1 の判定 → C → A → B-2 判断まで完結でき、TODO を分割すると Browser レーンの実行コスト（2 レーン × `pnpm build`）を二重に払うことになる。 |
| 競合リスク | 低。同 bug-hunt run の他 TODO が触るのは `Billing/*` / `Auth/*` / `bfcache-guard` / `AnalysisPipeline` 系で、本 TODO の変更ファイルとは重ならない。ただし `DESIGN.md` は他 TODO も追記しうるため、マージ時に §Toast の 1 行追加が競合しないか確認する。 |

---

## 関連する現行コード

### resources/js/pages/Settings/Security.svelte (script 抜粋 L77-236)

```svelte
    /** QR 確認待ち (有効化開始済みだが未確認) */
    let confirming = $state(false);
    let enabling = $state(false);
    let qrSvg = $state<string | null>(null);
    let recoveryCodes = $state<string[]>([]);
    let loadingRecoveryCodes = $state(false);
    /** 新コード一覧へのフォーカス移動用 (再生成成功時に再保管を促す) */
    let recoveryCodesPanel = $state<HTMLDivElement | null>(null);

    /**
     * Fortify の 2FA 確認アクション (ConfirmTwoFactorAuthentication) は検証失敗を
     * 名前付き error bag "confirmTwoFactorAuthentication" に投げる
     * (login チャレンジ側は default bag)。Inertia は default bag が無いと named bag を
     * ネストしたまま共有するため、client 側で同名の errorBag を指定しないと
     * confirmForm.errors.code が解決されず、誤コード時に無言失敗する (F-2-02)。
     */
    const CONFIRM_TWO_FACTOR_ERROR_BAG = "confirmTwoFactorAuthentication" as const;

    const confirmForm = useForm({
        code: "",
    });

    async function fetchJson<T>(url: string): Promise<T> {
        const response = await fetch(url, {
            headers: { Accept: "application/json" },
        });
        if (!response.ok) {
            throw new Error(`HTTP ${response.status}`);
        }
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

    /**
     * リカバリコードを取得する。成否を返し、失敗時の文言は呼び出し側が文脈に応じて出す
     * (通常表示: 単純な取得失敗 / 再生成直後: 旧コード失効済みの注意)。
     */
    async function loadRecoveryCodes(): Promise<boolean> {
        loadingRecoveryCodes = true;
        try {
            recoveryCodes = await fetchJson<string[]>("/user/two-factor-recovery-codes");
            return true;
        } catch {
            return false;
        } finally {
            loadingRecoveryCodes = false;
        }
    }

    /**
     * 「リカバリコードを表示」押下時 (失敗は取得失敗トースト)。
     * GET も recent-auth 配線済みのため precheck を通す (stale なら再認証モーダル→再開)。
     */
    function showRecoveryCodes(): void {
        guardWithRecentAuth(() => {
            void (async () => {
                if (!(await loadRecoveryCodes())) {
                    addToast("error", "リカバリコードの取得に失敗しました。");
                }
            })();
        });
    }

    /* ---- リカバリコード再生成 (F-10) ----
       POST 成功 = 旧コードは既に失効。表示中の旧コードを即クリアしてから GET で
       新コードを取得し、成功時は一覧へフォーカスする (再保管を促す)。成功 toast は
       サーバ flash (RecoveryCodesGeneratedResponse) を単一の源とし client では出さない
       (二重発火 F-L1 の解消)。GET 失敗時は「再生成は成功／表示取得が失敗」を明示し、
       既存の「リカバリコードを表示」ボタンが再試行導線になる (recoveryCodes が空に戻る)。 */
    let regenerateDialogOpen = $state(false);
    let regenerating = $state(false);

    /** POST 成功後の後処理 (旧コードは既に失効している前提)。 */
    async function handleRegenerateSuccess(): Promise<void> {
        regenerateDialogOpen = false;
        // 旧コードは失効済み。誤保管を防ぐため画面から即クリアする。
        // 成功 toast はサーバ flash (RecoveryCodesGeneratedResponse) が単一の源として出す
        // (二重発火 F-L1 の解消)。ここでは client 楽観 toast を出さない。
        recoveryCodes = [];
        if (await loadRecoveryCodes()) {
            await tick();
            recoveryCodesPanel?.focus();
            return;
        }
        // GET 失敗は「表示取得の失敗」= 再生成成功とは別事象。成功 toast と並んでも
        // 矛盾しないよう対象を明示する。
        addToast(
            "error",
            "リカバリコードは再生成されましたが、新しいコードの表示取得に失敗しました。旧コードは既に無効です。「リカバリコードを表示」から再取得してください。",
        );
    }

    /** 再生成は recent-auth 必須 (サーバが最終ゲート)。stale なら再認証モーダル→再開 */
    function regenerateRecoveryCodes(): void {
        guardWithRecentAuth(() => {
            router.post(
                "/user/two-factor-recovery-codes",
                {},
                {
                    preserveScroll: true,
                    onStart: () => {
                        regenerating = true;
                    },
                    onSuccess: () => {
                        void handleRegenerateSuccess();
                    },
                    onError: () => {
                        regenerateDialogOpen = false;
                        addToast("error", "リカバリコードの再生成に失敗しました。");
                    },
                    onFinish: () => {
                        regenerating = false;
                    },
                },
            );
        });
    }

    function enableTwoFactor(): void {
        router.post(
            "/user/two-factor-authentication",
            {},
            {
                preserveScroll: true,
                onStart: () => {
                    enabling = true;
                },
                onSuccess: () => {
                    confirming = true;
                    void loadQrCode();
                },
                onFinish: () => {
                    enabling = false;
                },
            },
        );
    }

    function confirmTwoFactor(event: SubmitEvent): void {
        event.preventDefault();
        confirmForm.post("/user/confirmed-two-factor-authentication", {
            preserveScroll: true,
            // Fortify の named error bag からエラーをスコープする (未指定だと errors.code が解決されない)
            errorBag: CONFIRM_TWO_FACTOR_ERROR_BAG,
            onSuccess: () => {
                confirming = false;
                qrSvg = null;
                confirmForm.reset();
                showRecoveryCodes();
            },
        });
    }
```

### resources/js/pages/Settings/Security.svelte (markup 抜粋 L350-400)

```svelte
                            </Button>
                        </div>
                    </div>
                {:else if confirming}
                    <div class="mt-4 flex flex-col gap-4">
                        <p class="text-body text-text-secondary">
                            認証アプリで以下の QR コードを読み取り、表示されたコードを入力して設定を完了してください。
                        </p>
                        {#if qrSvg}
                            <!-- QR はサーバ提供の SVG をそのまま描画する -->
                            <div class="self-start rounded-md border border-border bg-surface p-4">
                                <!-- eslint-disable-next-line svelte/no-at-html-tags -->
                                {@html qrSvg}
                            </div>
                        {/if}
                        <form onsubmit={confirmTwoFactor} class="flex flex-col gap-4">
                            <FormField
                                label="認証コード"
                                id="two-factor-code"
                                error={confirmForm.errors.code}
                            >
                                {#snippet children({ id, describedBy, invalid })}
                                    <Input
                                        {id}
                                        type="text"
                                        inputmode="numeric"
                                        bind:value={confirmForm.code}
                                        error={invalid}
                                        aria-describedby={describedBy}
                                        autocomplete="one-time-code"
                                    />
                                {/snippet}
                            </FormField>
                            <div>
                                <Button type="submit" loading={confirmForm.processing}>
                                    確認して有効化
                                </Button>
                            </div>
                        </form>
                    </div>
                {:else}
                    <div class="mt-4">
                        <Button
                            onclick={enableTwoFactor}
                            loading={enabling}
                            testId="enable-two-factor-button"
                        >
                            有効化
                        </Button>
                    </div>
                {/if}
```

### resources/js/components/templates/AuthLayout.svelte (全文)

```svelte
<script lang="ts">
    import type { Snippet } from "svelte";
    import { page } from "@inertiajs/svelte";
    import ToastContainer from "@/components/organisms/ToastContainer.svelte";
    import { consumeFlash, type FlashPayload } from "@/lib/stores/flash-to-toast";

    /**
     * 認証画面 (login / register / reset 等) 用レイアウト。
     * 中央寄せの surface カード 1 枚構成。
     * Laravel flash は consumeFlash で toast に変換する (visitKey で de-dup)。
     */
    interface Props {
        /** カード上部の見出し */
        title: string;
        appName?: string;
        children: Snippet;
        /** カード下部の補助導線 (別画面へのリンク等) */
        footer?: Snippet;
    }

    let { title, appName, children, footer }: Props = $props();

    $effect(() => {
        consumeFlash(page.props.flash as FlashPayload | undefined);
    });
</script>

<ToastContainer />

<div class="flex min-h-screen flex-col items-center justify-center bg-neutral px-4 py-10 text-text">
    {#if appName}
        <p class="mb-6 text-h3 text-primary">{appName}</p>
    {/if}
    <main class="w-full max-w-md rounded-lg border border-border bg-surface p-6">
        <h1 class="mb-6 text-h2">{title}</h1>
        {@render children()}
    </main>
    {#if footer}
        <div class="mt-4 text-center text-caption text-text-secondary">
            {@render footer()}
        </div>
    {/if}
</div>
```

### resources/js/components/templates/GuestLayout.svelte (script 部 L1-56)

```svelte
<script lang="ts">
    import type { Snippet } from "svelte";
    import { Menu, X } from "@lucide/svelte";
    import Button from "@/components/atoms/Button.svelte";

    /**
     * 未認証公開ページ (LP / Pricing / Contact / Legal) 用レイアウト。
     * ヘッダーのナビとフッターのリンク群は snippet で差し込む。
     * nav は「単純なリンク群 (<a>)」を想定する契約: 広幅ナビと狭幅パネルで二重に
     * @render するため、状態を持つ要素・複雑な構造を snippet に入れないこと。
     */
    interface Props {
        appName: string;
        children: Snippet;
        nav?: Snippet;
        footerLinks?: Snippet;
    }

    let { appName, children, nav, footerLinks }: Props = $props();

    // 狭幅 (sm 未満) のハンバーガー開閉。sm 以上は広幅ナビ表示のため未使用。
    let menuOpen = $state(false);
    // Escape close 時のフォーカス復帰用にトグルボタン DOM を保持
    let toggleEl = $state<HTMLButtonElement>();

    function closeMenu(): void {
        menuOpen = false;
    }

    // Escape で閉じてトグルへフォーカスを戻す (open 時のみ作用)。
    // 入力要素起点 (input/textarea/contenteditable) の Escape は誤クローズ防止のため無視する
    // (nav は単純リンク群契約だが将来 snippet 逸脱に対する防御)。
    function handleKeydown(event: KeyboardEvent): void {
        // defaultPrevented: 他ハンドラが Escape を処理済みなら二重処理しない
        if (event.defaultPrevented || event.key !== "Escape" || !menuOpen) return;
        const target = event.target;
        if (
            target instanceof HTMLElement &&
            target.closest("input, textarea, [contenteditable='true']")
        ) {
            return;
        }
        closeMenu();
        toggleEl?.focus();
    }

    // パネル内リンク押下で閉じる。委譲は window 側で受ける (パネル <nav> 自体には
    // イベントリスナを付けず a11y_click_events_have_key_events を発生させない)。
    // リンクの Enter 押下も既定で click を発火するためキーボード操作でも閉じる。
    function handleWindowClick(event: MouseEvent): void {
        if (!menuOpen) return;
        const target = event.target;
        if (target instanceof Element && target.closest("#guest-nav-panel a")) closeMenu();
    }
</script>

```

### resources/js/components/organisms/ToastContainer.svelte (全文)

```svelte
<script lang="ts">
    import { onDestroy } from "svelte";
    import { CircleCheck, CircleX, Info, TriangleAlert, X } from "@lucide/svelte";
    import { clearToasts, dismissToast, toasts, type ToastType } from "@/lib/stores/toast";

    // toast ストアは singleton。本 component はアプリで 1 箇所のみ mount すること
    // (複数 mount すると同一 toast が重複描画される)。

    /** type 別アイコン (Lucide) */
    const TYPE_ICONS = {
        success: CircleCheck,
        info: Info,
        warning: TriangleAlert,
        error: CircleX,
    } as const satisfies Record<ToastType, unknown>;

    /** type 別の border / アイコン色 (info は primary を流用する) */
    const TYPE_CLASSES = {
        success: { border: "border-success", icon: "text-success" },
        info: { border: "border-primary", icon: "text-primary" },
        warning: { border: "border-warning", icon: "text-warning" },
        error: { border: "border-danger", icon: "text-danger" },
    } as const satisfies Record<ToastType, { border: string; icon: string }>;

    // unmount 時 (SPA 全体破棄等の稀ケース) に残存タイマーを解除する (リーク防止)
    onDestroy(() => clearToasts());
</script>

<!-- 上部中央 fixed。複数 toast は縦 stack 表示する -->
<div class="pointer-events-none fixed top-6 left-1/2 z-50 flex -translate-x-1/2 flex-col items-center gap-2">
    {#each $toasts as toast (toast.id)}
        {@const TypeIcon = TYPE_ICONS[toast.type]}
        <!-- error は即時性が要るため role=alert (aria-live: assertive 相当)、他は role=status -->
        <div
            role={toast.type === "error" ? "alert" : "status"}
            class="pointer-events-auto flex items-center gap-2 rounded-md border bg-surface px-4 py-2 text-body text-text {TYPE_CLASSES[toast.type].border}"
            data-testid="toast-{toast.type}"
        >
            <TypeIcon class="size-4 shrink-0 {TYPE_CLASSES[toast.type].icon}" aria-hidden="true" />
            <span>{toast.message}</span>
            <button
                type="button"
                class="shrink-0 rounded-sm text-text-secondary transition-colors duration-150 hover:text-text focus-visible:ring-3 focus-visible:ring-primary/35 focus-visible:outline-none"
                aria-label="閉じる"
                onclick={() => dismissToast(toast.id)}
            >
                <X class="size-4" aria-hidden="true" />
            </button>
        </div>
    {/each}
</div>
```

### resources/js/lib/stores/toast.ts (全文)

```ts
import { writable, type Readable } from "svelte/store";

/**
 * Toast 通知ストア (singleton)。
 *
 * - success / info / warning: 4 秒で自動消去
 * - error: 自動消去しない (手動閉じのみ。ユーザーが読み終える前に消さない)
 *
 * 表示は ToastContainer organism が担い、画面には 1 箇所のみ mount すること。
 * Svelte component 外 (Inertia callback 等) からも呼べるよう svelte/store で実装する
 * (テスト容易性: get(toasts) でスナップショット取得できる)。
 */

export type ToastType = "success" | "info" | "warning" | "error";

export interface Toast {
    id: number;
    type: ToastType;
    message: string;
}

/** type 別の自動消去時間 (ms)。null は自動消去しない */
const AUTO_DISMISS_MS: Record<ToastType, number | null> = {
    success: 4000,
    info: 4000,
    warning: 4000,
    error: null,
};

const store = writable<Toast[]>([]);

let nextId = 1;

// 自動消去タイマーは id → handle で管理し、手動 dismiss 時にも確実に解除する (リーク防止)
const timers = new Map<number, ReturnType<typeof setTimeout>>();

/**
 * toast を追加する。success / info / warning は 4 秒後に自動消去される。
 * @returns 追加した toast の id (空メッセージは追加せず -1 を返す)
 */
export function addToast(type: ToastType, message: string): number {
    const trimmed = message.trim();
    if (!trimmed) return -1; // 空メッセージは積まない
    const id = nextId++;
    store.update((items) => [...items, { id, type, message: trimmed }]);
    const ttl = AUTO_DISMISS_MS[type];
    if (ttl !== null) {
        timers.set(
            id,
            setTimeout(() => dismissToast(id), ttl),
        );
    }
    return id;
}

/** 指定 id の toast を消去する (自動消去タイマーも解除) */
export function dismissToast(id: number): void {
    const handle = timers.get(id);
    if (handle !== undefined) {
        clearTimeout(handle);
        timers.delete(id);
    }
    store.update((items) => items.filter((t) => t.id !== id));
}

/** 全 toast とタイマーを破棄する (ToastContainer unmount 時の cleanup / テスト用) */
export function clearToasts(): void {
    timers.forEach((handle) => clearTimeout(handle));
    timers.clear();
    store.set([]);
}

/** 購読専用 view (外部から set させない) */
export const toasts: Readable<Toast[]> = { subscribe: store.subscribe };
```

### resources/js/lib/stores/flash-to-toast.ts (全文)

```ts
import { addToast } from "@/lib/stores/toast";

/**
 * Laravel flash → toast 変換。
 *
 * Inertia の shared props (flash) は Layout の再評価ごとに同じ値で再注入されるため、
 * visit ごとに一意な visitKey で de-dup し、同一 visit の flash は一度だけ消費する。
 */

export interface FlashPayload {
    success?: string | null;
    error?: string | null;
    info?: string | null;
    warning?: string | null;
    /** visit ごとに一意なキー (de-dup 用)。backend が flash と一緒に発行する */
    visitKey?: string | null;
}

/** 最後に消費した visitKey (モジュール変数で保持し、同一 visit の再評価を抑止する) */
let lastVisitKey: string | null = null;

/** flash の各キーと toast type の対応 (キーが入っていれば対応する type で addToast する) */
const FLASH_KEYS = ["success", "error", "info", "warning"] as const;

/**
 * flash payload を toast に変換して enqueue する。
 * 同じ visitKey は一度だけ消費する。visitKey 不在時は de-dup 不能のため消費しない
 * (stale props の再評価で同じ通知を二重表示しないことを優先する)。
 */
export function consumeFlash(flash: FlashPayload | null | undefined): void {
    const key = flash?.visitKey ?? null;
    if (!key || key === lastVisitKey) return;
    lastVisitKey = key;
    for (const flashKey of FLASH_KEYS) {
        const message = flash?.[flashKey];
        if (message) {
            addToast(flashKey, message);
        }
    }
}

/** de-dup 状態をリセットする (テスト用。アプリコードからは呼ばない) */
export function resetFlashConsumption(): void {
    lastVisitKey = null;
}
```

### resources/js/components/molecules/CodeSnippet.svelte (全文)

```svelte
<script lang="ts">
    import { onDestroy } from "svelte";
    import Button from "@/components/atoms/Button.svelte";

    /**
     * コピー付きコードブロック molecule (API キー・リカバリコード・CLI コマンド等の表示用)。
     *
     * コピー処理は component 内に内包する (navigator.clipboard 直呼び。
     * 非対応環境・拒否時は「コピー失敗」を表示して手動コピーを促す)。
     * `<pre>` は中間 box なので rounded-md、コードは font-mono (DESIGN.md §Shapes / §Typography)。
     */
    interface Props {
        code: string;
        language?: string;
        testId?: string;
        class?: string;
    }

    let { code, language = "plaintext", testId, class: extraClass = "" }: Props = $props();

    let copied = $state(false);
    let failed = $state(false);
    let timeoutId: ReturnType<typeof setTimeout> | undefined;

    async function copy(): Promise<void> {
        if (timeoutId) clearTimeout(timeoutId);
        try {
            // clipboard 非対応環境 (insecure context 等) は writeText が無いため失敗扱い
            if (!navigator.clipboard?.writeText) {
                throw new Error("clipboard unavailable");
            }
            await navigator.clipboard.writeText(code);
            copied = true;
            failed = false;
        } catch {
            copied = false;
            failed = true;
        }
        timeoutId = setTimeout(() => {
            copied = false;
            failed = false;
        }, 2000);
    }

    onDestroy(() => {
        if (timeoutId) clearTimeout(timeoutId);
    });
</script>

<div class={["relative", extraClass].filter(Boolean).join(" ")} data-testid={testId}>
    <!-- pr-24 でコピー UI 分の余白を確保する -->
    <pre
        data-testid={testId ? `${testId}-body` : undefined}
        data-language={language}
        class="overflow-x-auto rounded-md border border-border bg-neutral p-4 pr-24 text-caption font-mono text-text"><code
        >{code}</code></pre>
    <div class="absolute top-2 right-2 flex items-center gap-2">
        {#if copied}
            <span role="status" class="text-caption text-success">コピー完了</span>
        {:else if failed}
            <span role="status" class="text-caption text-danger">コピー失敗</span>
        {/if}
        <Button
            variant="neutral"
            size="sm"
            onclick={copy}
            testId={testId ? `${testId}-copy` : undefined}
        >
            コピー
        </Button>
    </div>
</div>
```

### tests/js/pages/SettingsSecurityTwoFactorConfirm.test.ts (全文)

```ts
import { afterEach, beforeEach, describe, expect, it, vi } from "vitest";
import { cleanup, fireEvent, render, screen, waitFor } from "@testing-library/svelte";
import { reactiveUseForm } from "../support/reactiveUseForm.svelte";

/*
 * Settings/Security 2FA セットアップ確認 (F-2-02 / T059)。
 * Fortify の ConfirmTwoFactorAuthentication は検証失敗を名前付き error bag
 * "confirmTwoFactorAuthentication" に投げる。client が同名の errorBag を指定しないと
 * Inertia が named bag をネストしたまま共有し confirmForm.errors.code が解決されず、
 * 誤コード時に無言失敗する。本テストは以下を回帰固定する:
 *   (a) 確認 POST に errorBag: "confirmTwoFactorAuthentication" が付く
 *   (b) レスポンスの errors 反映で入力直下にエラーが表示され Input が aria-invalid になる
 *   (c) 正コード成功で確認フォームが閉じ reset される
 *
 * useForm を reactiveUseForm フェイクへ差し替え「post の visit options 検証」と
 * 「named bag エラーからの表示」を分離して検証する。router.post / page は既存テスト同様モック。
 */

const { routerPostMock, pageState, addToastMock, holder } = vi.hoisted(() => ({
    routerPostMock: vi.fn(),
    pageState: {
        props: {} as Record<string, unknown>,
        url: "/settings/security",
    },
    addToastMock: vi.fn(),
    holder: { form: null as ReturnType<typeof reactiveUseForm> | null },
}));

vi.mock("@inertiajs/svelte", async (importOriginal) => ({
    ...(await importOriginal<typeof import("@inertiajs/svelte")>()),
    router: { post: routerPostMock },
    page: pageState,
    useForm: (init: Record<string, unknown>) => {
        const form = reactiveUseForm(init);
        holder.form = form;
        return form;
    },
}));

vi.mock("@/lib/stores/toast", async (importOriginal) => ({
    ...(await importOriginal<typeof import("@/lib/stores/toast")>()),
    addToast: addToastMock,
}));

import Security from "@/pages/Settings/Security.svelte";

const fetchMock = vi.fn();

/** JSON レスポンス風オブジェクト (fetch mock 用) */
function jsonResponse(ok: boolean, status: number, body: unknown): unknown {
    return { ok, status, json: () => Promise.resolve(body) };
}

/** 確認フロー描画に必要な fetch (QR / recent-auth / recovery codes) を stub する */
function stubFetchRoutes(): void {
    fetchMock.mockImplementation((input: RequestInfo | URL) => {
        const url = String(input);
        if (url.includes("/user/two-factor-qr-code")) {
            return Promise.resolve(jsonResponse(true, 200, { svg: "<svg></svg>" }));
        }
        if (url.includes("/recent-auth/status")) {
            return Promise.resolve(
                jsonResponse(true, 200, {
                    recent: true,
                    passwordSet: true,
                    availableProviders: [],
                    canSatisfy: true,
                    confirmedAt: 1,
                }),
            );
        }
        // /user/two-factor-recovery-codes (成功 callback 後の showRecoveryCodes)
        return Promise.resolve(jsonResponse(true, 200, ["code-a", "code-b"]));
    });
}

/** Inertia visit options (第3引数) の検証対象部分 */
interface InertiaVisitOptions {
    onStart?: () => void;
    onSuccess?: () => void;
    onError?: () => void;
    onFinish?: () => void;
}

/** router.post (enableTwoFactor) の第3引数を取り出す */
function lastRouterVisitOptions(): InertiaVisitOptions {
    const call = routerPostMock.mock.calls.at(-1);
    if (!call) throw new Error("router.post が呼ばれていない");
    return call[2] as InertiaVisitOptions;
}

function currentForm(): ReturnType<typeof reactiveUseForm> {
    if (!holder.form) throw new Error("confirmForm フェイクが未生成");
    return holder.form;
}

/** confirmForm.post の第2引数 (visit options) を取り出す */
function lastConfirmPostOptions(): InertiaVisitOptions {
    const call = currentForm().post.mock.calls.at(-1);
    if (!call) throw new Error("confirmForm.post が呼ばれていない");
    return call[1] as InertiaVisitOptions;
}

/**
 * 2FA 無効状態から確認フォームを表示させる。
 * 有効化ボタン押下 → router.post onSuccess で confirming=true にして QR/確認フォームを描画する。
 */
async function openConfirmForm(): Promise<void> {
    await fireEvent.click(screen.getByTestId("enable-two-factor-button"));
    await waitFor(() => {
        expect(routerPostMock).toHaveBeenCalledWith(
            "/user/two-factor-authentication",
            {},
            expect.objectContaining({ preserveScroll: true }),
        );
    });
    lastRouterVisitOptions().onSuccess?.();
    await waitFor(() => {
        expect(screen.getByLabelText("認証コード")).toBeInTheDocument();
    });
}

/** 認証コードを入力して確認フォームを submit する */
async function submitConfirm(code = "123456"): Promise<void> {
    await fireEvent.input(screen.getByLabelText("認証コード"), { target: { value: code } });
    await fireEvent.click(screen.getByRole("button", { name: "確認して有効化" }));
}

beforeEach(() => {
    holder.form = null;
    pageState.props = {
        appName: "AI-CUE",
        auth: { user: { id: 1, name: "テスト太郎", twoFactorEnabled: false } },
    };
    stubFetchRoutes();
    vi.stubGlobal("fetch", fetchMock);
});

afterEach(() => {
    cleanup();
    vi.unstubAllGlobals();
    routerPostMock.mockReset();
    addToastMock.mockReset();
    fetchMock.mockReset();
});

describe("Settings/Security 2FA 確認 (F-2-02: 誤コードエラー表示)", () => {
    it("(a) 確認 POST に errorBag: confirmTwoFactorAuthentication を指定する", async () => {
        render(Security, { props: {} });

        await openConfirmForm();
        await submitConfirm();

        expect(currentForm().post).toHaveBeenCalledWith(
            "/user/confirmed-two-factor-authentication",
            expect.objectContaining({ errorBag: "confirmTwoFactorAuthentication" }),
        );
    });

    it("(b) 誤コードのレスポンス errors 反映で入力直下にエラーを表示し Input を aria-invalid にする", async () => {
        render(Security, { props: {} });

        await openConfirmForm();
        await submitConfirm("000000");

        // Inertia がレスポンス受領後に form.errors を更新する挙動を模倣 (named bag からスコープ済み)
        currentForm().respondWithErrors({ code: "認証コードが無効です" });

        await waitFor(() => {
            expect(screen.getByText("認証コードが無効です")).toBeInTheDocument();
        });
        // 入力直下 (#two-factor-code-error) に文言が紐づく
        expect(screen.getByText("認証コードが無効です")).toHaveAttribute(
            "id",
            "two-factor-code-error",
        );
        // Input が error 状態 (赤枠 class は実装詳細のため aria-invalid で固定する)
        expect(screen.getByLabelText("認証コード")).toHaveAttribute("aria-invalid", "true");
    });

    it("(c) 正コード成功で確認フォームが閉じ reset される", async () => {
        render(Security, { props: {} });

        await openConfirmForm();
        await submitConfirm("123456");

        const form = currentForm();
        // 成功 callback を発火 (Inertia visit 成功時の onSuccess)
        lastConfirmPostOptions().onSuccess?.();

        await waitFor(() => {
            expect(screen.queryByLabelText("認証コード")).toBeNull();
        });
        expect(form.reset).toHaveBeenCalled();
        // 有効化ボタンに戻る (twoFactorEnabled は依然 false のため)
        expect(screen.getByTestId("enable-two-factor-button")).toBeInTheDocument();
    });
});
```

### tests/js/components/templates/GuestLayout.test.ts (全文)

```ts
import { describe, expect, it } from "vitest";
import { createRawSnippet } from "svelte";
import { fireEvent, render, screen, within } from "@testing-library/svelte";
import GuestLayout from "@/components/templates/GuestLayout.svelte";

/*
 * GuestLayout の狭幅ハンバーガー化 (T027)。nav 未指定でトグル・パネルが出ないこと、
 * nav 指定でトグルが出ることを固定する。実挙動 (Escape / パネル内リンク) の主検証は
 * Welcome.test.ts が担う。
 */

const children = createRawSnippet(() => ({ render: () => `<p>content</p>` }));
const nav = createRawSnippet(() => ({
    render: () => `<a href="/pricing">料金プラン</a>`,
}));

describe("GuestLayout", () => {
    it("nav を渡さないとハンバーガー・パネルを描画しない (Contact 相当)", () => {
        render(GuestLayout, { props: { appName: "AI-CUE", children } });

        expect(screen.queryByTestId("guest-nav-toggle")).not.toBeInTheDocument();
        expect(screen.queryByTestId("guest-nav-panel")).not.toBeInTheDocument();
    });

    it("nav を渡すとトグルが出て、押下でパネルが開く", async () => {
        render(GuestLayout, { props: { appName: "AI-CUE", children, nav } });

        const toggle = screen.getByTestId("guest-nav-toggle");
        expect(screen.queryByTestId("guest-nav-panel")).not.toBeInTheDocument();
        await fireEvent.click(toggle);
        const panel = screen.getByTestId("guest-nav-panel");
        expect(within(panel).getByRole("link", { name: "料金プラン" })).toBeInTheDocument();
    });
});
```

### Alert atom の Props (resources/js/components/atoms/Alert.svelte L19-45)

```svelte
    interface Props {
        type: AlertType;
        /** 状態色の見出し行 (任意)。省略時は見出しを描画しない */
        title?: string;
        /** 本文 (必須) */
        children: Snippet;
        /** CTA ボタン等を本文の下 (mt-4) に描画するスロット */
        action?: Snippet;
        /** true + onDismiss で右上に閉じるボタンを描画する */
        dismissible?: boolean;
        onDismiss?: () => void;
        class?: string;
        testId?: string;
    }

    let {
        type,
        title,
        children,
        action,
        dismissible = false,
        onDismiss,
        class: extraClass = "",
        testId,
    }: Props = $props();

    // dynamic class 生成は Tailwind に静的検出されないため、type ごとの static class を map で持つ
```

### tests/Browser/SmokeTest.php (全文。Browser レーンの流儀の参考)

```php
<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Browser スモークテスト (pest-plugin-browser / Playwright)
|--------------------------------------------------------------------------
|
| Browser lane の共通基盤 (in-process サーバ + 実ブラウザ + RefreshDatabase +
| actingAs) が機能することの最小検証。実行は `composer test:browser`
| (scripts/run-browser-test.sh 経由。前提・規約は docs/testing-browser.md)。
|
| 実ブラウザは public/build のビルド済アセットを読むため、UI 変更後は
| `pnpm build` を先に実行すること。
|
*/

test('ゲストがトップページを JS エラーなしで表示できる', function (): void {
    $page = visit('/');

    $page->assertPathIs('/')
        ->assertSee(config()->string('app.name'))
        ->assertNoJavaScriptErrors();
});

test('ゲストは /dashboard に到達できず /login へリダイレクトされる', function (): void {
    visit('/dashboard')->assertPathIs('/login');
});

test('actingAs が実ブラウザの session で効き dashboard を表示できる', function (): void {
    // 組織 provisioning 済みの owner を使う (dashboard は current org 前提の共有 props を読む)
    [, $owner] = createOrganizationWithOwner();

    $this->actingAs($owner);

    visit('/dashboard')
        ->assertPathIs('/dashboard')
        ->assertNoJavaScriptErrors();
});
```
