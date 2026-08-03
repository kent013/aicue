# 詳細設計レビュー Round 2

Round 1 の指摘 (Critical 1 / Warning 5 / Suggestion 3) への対応を反映しました。
対応マトリクスと改訂全文を添付します。

未対応・反論した点は対応マトリクスに根拠を明記しています。特に:
- C-2 (競合) は requestId ではなく in-flight 早期リターンで解決しました (理由も記載)
- B-1 の 3 秒閾値は緩和せず、**待機順序を変えて猶予を増やす**方針にしました
- B-2-1 の「全ページ遷移で unmount される」は Claude 側で一次ソース確認済みのため
  事実として維持し、「根因である」という射程だけを外しました

残る Critical / Warning があれば指摘してください。無ければ APPROVED を明示してください。

## Round 1 指摘への対応マトリクス

# 対応マトリクス: design-review Round 1

判定: B-1 APPROVE / C REQUEST_CHANGES / A-1・A-2 APPROVE / B-2 REQUEST_CHANGES
→ 全体 CHANGES_REQUESTED。以下すべてに対応または根拠つきで反論する。

## [Critical] C-1: 取得中でも `qr-unavailable` / `setup-key-unavailable` が先に表示される

- 判断: **対応する**
- 根拠: 決定的に正しい。`enableTwoFactor` の onSuccess で `confirming = true` にした直後は
  `qrSvg` / `setupKey` とも null で、fetch 解決前に「表示できませんでした」が出てしまう
  (失敗前に失敗文言が出る = 明確な UX バグ)。
- 対応内容: `loadingEnrollmentAssets === true` の間は取得中プレースホルダのみを描画し、
  警告 Alert は**取得完了後に欠損が確定した場合のみ**表示する分岐に変更。
  取得中コンテナに `aria-busy="true"` を付ける (Suggestion C-3 も同時採用)。

## [Warning] C-2: 再試行連打時のレスポンス逆転で古い結果が上書きされうる

- 判断: **対応する (ただし requestId ではなく in-flight ガードで)**
- 根拠: 競合の指摘は妥当。ただし本画面で並行実行が起きうる経路は
  「再試行ボタン連打」と「取得中に有効化を再実行」の 2 つだけで、
  どちらも**同時に 1 本しか走らせない**ことで解消できる。requestId による
  「後着優先」の一般化は今必要ない (思考原則 2)。
- 対応内容: `loadEnrollmentAssets()` の冒頭で `if (loadingEnrollmentAssets) return;` の
  早期リターンを置く (アーリーリターン推奨にも合致)。再試行ボタンは `loading` 表示で
  進行中を示す (必須条件による disabled ではないため禁止事項 8 に抵触しない)。

## [Warning] B-1-1: `querySelector` の存在判定は「可視」を担保しない

- 判断: **対応する**
- 対応内容: 判定式を実可視 (`getClientRects().length > 0` かつ
  `getComputedStyle` の `visibility !== "hidden"` / `display !== "none"`) に変更する。

## [Suggestion] B-1-2: 3 秒固定は flaky になりうる (3500ms へ緩和を検討)

- 判断: **別の形で対応する (待機順序を変えて余裕を作る)**
- 根拠: 上限は auto-dismiss の 4 秒で頭打ちなので、閾値を 3500ms に上げても
  余裕は 500ms しか増えない。真の対策は**計測開始を早めること**。
- 対応内容: 待機順序を「着地 → toast」から**「toast → (失敗時のみ) 着地の確認」**へ変更する。
  toast は着地ページの mount と同時に enqueue されるため、先に toast を待てば
  auto-dismiss までの猶予を最大化できる。着地確認は fail の**分類**にのみ使う
  (制御条件 (ii) の判定材料)。閾値は 3000ms を維持する。

## [Warning] A-2-1: 「ToastContainer はアプリで 1 箇所のみ mount」との整合が未検証

- 判断: **事実確認のうえ対応 (整合する)**
- 根拠: 実ファイルを確認した。`ToastContainer` を描画しているのは
  `AppLayout.svelte:190` と `AuthLayout.svelte:28` のみで、root
  (`resources/js/app.ts` / `resources/views/app.blade.php`) には無い。
  layout を 2 つ使うページも存在しない (`AuthLayout` 利用 8 ページのいずれも
  `AppLayout` / `GuestLayout` を import していない)。したがって GuestLayout に足しても
  同時 mount は発生しない。
- 対応内容: この確認結果を詳細設計のリスク節に事実として記載する。

## [Suggestion] A-1-1: Feature テストで文言も 1 件固定する

- 判断: **対応する**
- 対応内容: `AccountDeletionTest` の 1 件のみ
  `assertSessionHas('success', 'アカウントを削除しました')` と文言まで固定する
  (他 2 件はキー存在のみ = 文言変更の巻き添え更新を増やさない)。

## [Warning] B-2-1: 「全ページ遷移で unmount される」が未検証で根因仮説として弱い

- 判断: **一部反論 + 一部対応**
- 反論 (事実): 「全ページ遷移で unmount される」は Claude 側が一次ソースで確認済み。
  `node_modules/@inertiajs/svelte/dist/components/App.svelte` の `swapComponent` が
  非 preserveState visit で `key = Date.now()` を更新し、`Render.svelte` が
  `{#key children?.length === 0 ? key : null}` でページ配下を丸ごと作り直す。
  本アプリは layout をページ component 内で描画するため、`ToastContainer` も毎回作り直される。
- 対応 (射程): ただし Codex の言うとおり「unmount が F-1-02 の**根因**である」は未確定であり、
  そこは概念設計の判定表 (制御条件つき fail のときだけ H-a を支持) がすでに担保している。
  B-2 の見出しと本文に「根因確定ではなく、制御条件つき fail のときのみ適用」を再掲する。

## [Warning] B-2-2: cleanup 境界が未認証 layout 初期化に偏る / 明文化して固定せよ

- 判断: **対応する**
- 対応内容: 「消去境界の正本 = 未認証 layout の初期化」を
  `DESIGN.md` §Toast に明記 (既に A-2 で計画済み) し、
  **JS テストで固定**することを B-2 の受入条件にも明示する
  (`GuestLayout.test.ts` / `AuthLayout.test.ts` の「着地前の toast は描画されない」ケース)。

## [Suggestion] C-3 / 補足 (PHPStan / Inertia / DESIGN / Atomic)

- C-3 (`aria-busy`) は C-1 の対応に含めて採用。
- 補足の各観点は「逸脱なし」の確認であり対応不要。
- `{@html qrSvg}` の信頼境界は既存実装のまま (サーバ生成 SVG)。本設計で新たな
  ユーザー入力を混ぜないため信頼境界は変わらない旨を明記する。

---

## 改訂版 詳細設計 (全文)

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
 * 指定セレクタの要素が**実際に可視**になるまで待つ。
 * deadline は toast の auto-dismiss (4 秒) より短く取り、「見えなかった」を
 * auto-dismiss と混同しないようにする (制御条件 (iii))。
 * script() 呼び出しが in-process サーバの event loop を回す (bfcache テストと同じ流儀)。
 *
 * 存在 (querySelector != null) ではなく可視で判定する: レンダ順によっては
 * 非表示のまま DOM に居る瞬間がありうるため。
 *
 * @return array{appeared: bool, elapsedMs: int}
 */
function waitForVisibleSelector(PendingAwaitablePage $page, string $selector, int $timeoutMs = 3000): array
{
    $startedAt = hrtime(true);
    $expression = sprintf(<<<'JS'
        (() => {
            const el = document.querySelector(%s);
            if (el === null) return false;
            const style = getComputedStyle(el);
            return style.visibility !== 'hidden'
                && style.display !== 'none'
                && el.getClientRects().length > 0;
        })()
        JS, json_encode($selector, JSON_THROW_ON_ERROR));

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

    // toast を**先に**待つ。toast は着地ページの mount と同時に enqueue されるため、
    // 先に待つほど auto-dismiss (4 秒) までの猶予が大きい。着地の確認は fail の分類に使う。
    $toast = waitForVisibleSelector($page, '[data-testid="toast-success"]');

    if (! $toast['appeared']) {
        // 制御条件 (ii) の判定材料: そもそも着地していないなら「その他の fail」= 原因判定不能
        $landed = waitForVisibleSelector($page, '[data-testid="project-show-heading"]', 2000);
        expect($landed['appeared'])->toBeTrue(
            '削除後に projects.show へ着地しなかった (テスト条件の問題。実装を変えずに調査すること)',
        );
    }

    expect($toast['appeared'])->toBeTrue(
        "削除成功 toast が {$toast['elapsedMs']}ms 以内に可視にならなかった "
        .'(着地は確認済み = 制御条件を満たした fail → H-a を支持。conceptual-design.md の判定表を参照)',
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
 *
 * 同時実行は行わない (再試行連打・取得中の再有効化でレスポンス逆転が起きると
 * 古い結果で上書きされるため)。in-flight 中の呼び出しは早期リターンで捨てる。
 */
async function loadEnrollmentAssets(): Promise<void> {
    if (loadingEnrollmentAssets) return;
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

- `clearToasts()` を layout 初期化で呼ぶため、**guest → guest / auth → auth のページ遷移でも
  既存 toast が消える**。現行は `ToastContainer.onDestroy` が同じことをしており挙動は不変。
  B-2 適用後も未認証面ではこの挙動が維持される（認証面では維持されない = 意図どおり）。
- `GuestLayout` に `ToastContainer` を足しても、同時に 2 つ mount される状況
  （DESIGN.md「アプリで 1 箇所のみ mount」）は起きない。**確認済み**:
  `ToastContainer` を描画しているのは `AppLayout.svelte:190` と `AuthLayout.svelte:28` のみで、
  root（`resources/js/app.ts` / `resources/views/app.blade.php`）には無い。
  layout を 2 つ使うページも存在しない（`AuthLayout` 利用の 8 ページはいずれも
  `AppLayout` / `GuestLayout` を import していない）。

---

## 施策 B-2（条件付き）: `ToastContainer` のライフサイクル境界正規化

**適用条件**: B-1 が**制御条件を満たして** fail した場合のみ
（制御条件 = flash が応答に存在 / 着地ページ mount 済み / 3 秒以内に検査）。
その他の fail では適用せず、テスト条件を調査する。pass の場合も適用しない。

**射程の明示**: 「`ToastContainer` が全ページ遷移で unmount される」ことは一次ソースで
確認済み（`node_modules/@inertiajs/svelte/dist/components/App.svelte` の `swapComponent` が
非 preserveState visit で `key = Date.now()` を更新し、`Render.svelte` の
`{#key children?.length === 0 ? key : null}` がページ配下を作り直す。本アプリは layout を
ページ component 内で描画するため `ToastContainer` も毎回作り直される）。
ただし**それが F-1-02 の根因であることは未確定**であり、だからこそ適用を
「制御条件つき fail」に限定する。

**受入条件**: (1) B-1 の Browser テストが green になること、(2) 消去境界の正本
（未認証 layout の初期化）が `GuestLayout.test.ts` / `AuthLayout.test.ts` の
「着地前の toast は描画されない」ケースで固定されていること。

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
