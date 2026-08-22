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

---

あなたは Laravel 12 + Inertia + Svelte 5 (runes) のコードレビュアーである。TODO T199「撮影 PWA のアカウント確認導線」の実装差分をレビューせよ。

レビュー観点:
1. **詳細設計との一致性** — 設計から逸脱している箇所があれば、その逸脱が正当か (現行コード・規約の優先)、それとも欠陥か
2. **正確性** — ロジックの誤り、到達不能・踏破不能な導線、権限・404 境界の誤り
3. **PHPStan level 10 適合性** (型の widen / ignore は禁止)
4. **DTO / JsonResource / Inertia パターン** (`response()->json()` 直書き禁止)
5. **テスト網羅性** — 施策ごとにテストがあるか。偽陽性・空振りするテストが無いか。Factory 経由か
6. **セキュリティ** — テナント境界 (層 2 = 404) が認可 (層 3 = 403) より前か、cross-org 漏れ、PII の扱い
7. **DESIGN.md 準拠** — color / radius / typography は token 経由か。hex 直書き (`#RRGGBB`) を増やしていないか
8. **Atomic Design 準拠** — `atoms → molecules → organisms → features → templates → pages` の単方向 import。アイコンは Lucide のみ、SVG 直書きを増やしていないか

出力形式:
- ファイルごとに判定を書く
- 指摘は [Critical] / [Warning] / [Suggestion] に分類する
- 末尾に全体判定を **APPROVED** または **CHANGES_REQUESTED** の 1 語で明示する

---

## 詳細設計書

# 詳細設計: capture-account-screen (撮影 PWA のアカウント確認導線)

## 使命・制約（絶対遵守）

### アプリの使命（North Star）

**AI-CUE** は、現場に既にある**作業手順書(SOP)を起点に**、AI が撮るべきカットを設計した**動画シナリオ**を生成し、そのシナリオを**スマホ(PWA)でナビゲーション撮影**することで、専門知識ゼロの現場作業者でも**標準化されたマニュアル動画**を作れるようにする。

- 「思考ゼロ・編集ゼロ」— 台本作成・撮影判断・編集の 3 ハードルを AI とナビ撮影で肩代わりする。
- 競合(OJT を撮って形式化する tebiki)と異なり、**標準作業を起点に AI が教材設計し撮影を指示する**（撮影者・教える人のスキルに品質を依存させない）。
- 熟練者の暗黙知を動画マニュアルという形式知へ変換する装置（SECI）。

> **v1 スコープ**: 字幕のみ(TTS 後回し) / 撮影は PWA(同一オリジン・セッション認証) / 動画合成は自前 ffmpeg / 単一 Default Project。

### 禁止事項（AGENTS.md より）

1. テストなしの実装完了報告
2. PHPStan エラーの widen・baseline 化
3. dev DB への破壊操作をエージェント判断で実行すること
4. `response()->json()` の直書き(DTO / JsonResource / Inertia を使う)
5. LLM 呼び出しの Prism 直呼び
6. prompt 文字列のコード直書き
7. 操作系 POST の応答での `redirect()->intended()`
8. **必須条件未充足を理由にボタンを disabled にする UI**
9. Artifact の使用

本設計に直接効くのは **4**(Inertia を使う) と **8**(disabled を作らない) の 2 つ。
LLM / prompt / 課金 / DB 破壊のいずれにも触れない。

### コーディングルール

- **PHPStan level 10** 必須
- **Pest** + `RefreshDatabase` グローバル適用(個別 `DatabaseTransactions` 禁止)・`--parallel`
- テストデータは必ず Factory 経由(本設計は**新モデルを追加しない**ので新規 Factory も無い)
- **DTO + JsonResource** パターン(本設計は Inertia page のみで JSON endpoint を作らない)
- `declare(strict_types=1)` + 日本語コメント / Controller は薄く / アーリーリターン
- フロントは Svelte 5 runes + DS token のみ。アイコンは `@lucide/svelte` のみ。
  component 階層は `atoms → molecules → organisms → features/{domain} → templates → pages` の単方向
- 検証コマンドは **AGENTS.md の `VERIFICATION_COMMANDS` マーカー区間が正本**(ここへ写さない)

## 概念設計リファレンス

`devnotes/20260816-2359-capture-account-screen/conceptual-design.md`(conceptual-review Round 3 で APPROVED)

**要点**: 撮影 PWA (`/app/*`) から自分のログイン ID(= メールアドレス)を**省略なく**確認する手段が無い。
既存のドロワー(`AppLayout`)は 256px 幅で `truncate` + `text-caption` の 2 行しか持たず、
そこは DESIGN.md の役割マッピング上「補助情報」の場所である。よって
**`/app/account` に確認専用の画面を 1 枚作る**(表示名 / ログイン ID / 所属組織 / ログアウト / `/app` への復路)。

## 施策一覧

| # | 施策名 | 変更ファイル | 優先度 |
|---|--------|------------|--------|
| 1 | `capture.account` route の追加 | `routes/web.php` | 高 |
| 2 | 表示専用 controller の追加 | `app/Http/Controllers/Capture/CaptureAccountController.php`(新規) | 高 |
| 3 | アカウント確認画面の追加 | `resources/js/pages/Capture/Account.svelte`(新規) | 高 |
| 4 | 撮影一覧からの入口 | `resources/js/pages/Capture/Index.svelte` | 高 |
| 5 | ログアウト呼び出し箇所の目録登録 + docs 更新 | `tests/js/architecture/logout-call-site-inventory.test.ts` / `docs/supported-browsers.md` | 高 |
| 6 | bug-hunt 目録への route 注釈追加と再生成 | `.claude/skills/app-bug-hunt/inventory/annotations.toml` / `screens.md` / `operations.md` | 高 |
| 7 | テスト | `tests/Feature/Capture/CaptureAccountScreenTest.php`(新規) / `tests/js/pages/CaptureAccount.test.ts`(新規) / `tests/js/pages/CaptureIndex.test.ts` | 高 |

---

## 施策 1: `capture.account` route の追加

### 変更箇所

- ファイル: `routes/web.php`(撮影 PWA group。現状 L606-640 付近)

### 波及変更

- TypeScript 型定義: **なし**(props を増やさないため)
- API Resource/DTO: **なし**(Inertia page)
- テストファイル: 施策 7(Feature)
- bug-hunt 目録: 施策 6(**web group の route を足したので必須**)

### 現行コード

```php
    Route::middleware(['require-active-subscription', 'project.in-route-org'])
        ->prefix('app')->as('capture.')->group(function (): void {
            // PWA エントリ (manifest start_url)。current org の先頭 project へ redirect
            Route::get('/', [CaptureManualController::class, 'home'])->name('home');
            // CSRF cookie 再発行 (419 リトライ用の軽量 GET。web group を通るだけで
            // XSRF-TOKEN cookie が更新される。204 = 仕様固定 endpoint、body なし)
            Route::get('/csrf-cookie', fn (): Response => response()->noContent())
                ->name('csrf-cookie');
            Route::get('/projects/{project}/manuals', [CaptureManualController::class, 'index'])
                ->name('manuals.index');
```

### 変更後コード

```php
    Route::middleware(['require-active-subscription', 'project.in-route-org'])
        ->prefix('app')->as('capture.')->group(function (): void {
            // PWA エントリ (manifest start_url)。current org の先頭 project へ redirect
            Route::get('/', [CaptureManualController::class, 'home'])->name('home');
            // CSRF cookie 再発行 (419 リトライ用の軽量 GET。web group を通るだけで
            // XSRF-TOKEN cookie が更新される。204 = 仕様固定 endpoint、body なし)
            Route::get('/csrf-cookie', fn (): Response => response()->noContent())
                ->name('csrf-cookie');
            /*
            | 撮影 PWA のアカウント確認画面 (doc/05 §5.1 / §5.2)。表示名・ログイン ID
            | (= メールアドレス)・所属組織を省略なく読み、ログアウトするためだけの面。
            | **route parameter を持たない** — project のデータを 1 つも表示しないため、
            | project 配下 (/app/projects/{project}/account) には置かない
            | (親を持たせると nested route IDOR 目録と scopeBindings を負うだけで意味も歪む)。
            | 復路は capture.home 1 本 (start_url と同じ)。return_to / history.back() は使わない。
            | 変更操作は一切持たない (プロフィール変更・パスワード・2FA・退会は /settings の責務)。
            */
            Route::get('/account', CaptureAccountController::class)->name('account');
            Route::get('/projects/{project}/manuals', [CaptureManualController::class, 'index'])
                ->name('manuals.index');
```

`use App\Http\Controllers\Capture\CaptureAccountController;` を import 節へ追加する
(既存の `CaptureManualController` の隣。アルファベット順で先に来る)。

### 設計判断と機械的検査との関係

- **課金ゲート**(ドメイン規約 4): `require-active-subscription` group の**中**に置く。
  group の外に置けるのは「契約するために未契約組織が到達できなければならない導線」だけで、
  アカウント確認はそれに当たらない。遮断中は `/app` 全体に入れないので導線の矛盾も無い。
  遮断時の着地 `pages/Onboarding/BillingRequired.svelte` / `Checkout.svelte` はどちらも
  `AppLayout` を使うため、**遮断中もログアウトはできる**(実読確認済み)。
- **`project.in-route-org`**: `{project}` を持たない route では実質 no-op。
  同 group の既存 `capture.home` / `capture.csrf-cookie` も `{project}` を持たない前例がある。
- **`/app` へ PWA 固有の middleware を足さない**(`docs/architecture.md`: `/app/*` は PC 面と共用)。
  本施策は route を 1 本足すだけで middleware を触らない。
- **`ControllerAuthorizationGateTest`**: 母集団は POST/PUT/PATCH/DELETE。GET なので対象外。
- **`NestedRouteIdorDefenseTest`**: route parameter を持たないので母集団に入らない。
- **`ThrottleCoverageInventoryTest`**: 保護対象群(未認証で到達しうる変更系 /
  `api/`・`oauth/`・`.well-known/oauth-` / 認証面の変更系)のいずれでもないので対象外。
  **inline throttle は使わない**(T125 で全廃済み)。

### PHPStan 適合チェック

- [x] route 定義に型は現れない(単一アクション controller のクラス名指定)

### テスト計画

施策 7 を参照。

### リスク

- `/app/account` という URL が将来 `{project}` を要求するようになると設計が変わる。
  現状この画面は project のデータを 1 つも表示しないので、その要求は生じない。

---

## 施策 2: 表示専用 controller の追加

### 変更箇所

- ファイル: `app/Http/Controllers/Capture/CaptureAccountController.php`(**新規**)

### 波及変更

- TypeScript 型定義: **なし**
- API Resource/DTO: **なし**。表示する値はすべて `HandleInertiaRequests` が
  全ページへ共有済みの props(`auth.user.name` / `auth.user.email` /
  `currentOrganization.name`)であり、**ページ固有 props を 1 つも足さない**。
- テストファイル: 施策 7(Feature)

### 現行コード

存在しない(新規)。参考にする既存実装は `app/Http/Controllers/Capture/CaptureManualController.php`
の `home()` / `index()`(`ResolvesCurrentOrganization` trait の使い方と `SeoManager` の使い方)。

```php
// app/Http/Concerns/ResolvesCurrentOrganization.php (現行・抜粋)
private function resolveMemberCurrentOrganization(Request $request): Organization
{
    $organization = $this->resolveCurrentOrganization($request); // 未設定なら 404

    $user = $request->user();
    Assert::isInstanceOf($user, User::class);

    abort_unless(
        $organization->users()->whereKey($user->getKey())->exists(),
        404,
    );

    return $organization;
}
```

### 変更後コード

```php
<?php

declare(strict_types=1);

namespace App\Http\Controllers\Capture;

use App\Http\Concerns\ResolvesCurrentOrganization;
use App\Http\Controllers\Controller;
use App\Support\Seo\SeoManager;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * 撮影 PWA のアカウント確認画面 (doc/05 §5.1 / §5.2)。
 *
 * **表示専用**である。表示名・ログイン ID (= メールアドレス)・所属組織はすべて
 * HandleInertiaRequests が全ページへ共有している props (auth.user / currentOrganization) で
 * 賄えるため、**ページ固有 props を 1 つも返さない**。値を二重に持たない
 * (共有 prop と page prop が食い違う余地を作らない)。
 *
 * current organization は resolveMemberCurrentOrganization() で解決する。これは
 * 共有 prop 側 (HandleInertiaRequests) が「current_organization_id が指す組織に
 * **非所属**なら null に倒す」のと**同じ述語**をサーバ側に置くためで、
 * 到達した画面では currentOrganization が非 null であることが保証される
 * (未設定・非所属はどちらも認可より前に 404 = 組織の存在を露出しない)。
 */
class CaptureAccountController extends Controller
{
    use ResolvesCurrentOrganization;

    public function __invoke(Request $request, SeoManager $seo): Response
    {
        // current org 解決 + 在籍 guard (未設定 / 非所属は 404)
        $this->resolveMemberCurrentOrganization($request);

        // 撮影 PWA 内の面であることを識別可能にする (Capture/Show と同じ扱い)
        $seo->setPrivateTitle('アカウント');

        return Inertia::render('Capture/Account');
    }
}
```

### PHPStan 適合チェック

- [x] 戻り値の型が明示されている(`Inertia\Response`)
- [x] null 安全: `resolveMemberCurrentOrganization()` の中で `Assert::isInstanceOf` 済み。
      本 controller 自身は `$request->user()` を触らない(触ると `User|null` の narrowing が要る)
- [x] DTO を返している(配列返却なし): **Inertia page のため該当なし**。
      page 固有 props を持たないので配列も作らない
- [x] Generics の型パラメータ: 該当なし
- [x] `resolveMemberCurrentOrganization()` の戻り値を捨てている点:
      戻り値未使用は PHPStan level 10 でもエラーにならない(`@return` は `Organization` で void ではない)。
      **副作用(404 abort)のために呼んでいる**ことをコメントで明示する

### テスト計画

施策 7 を参照。

### リスク

- `resolveMemberCurrentOrganization()` は `$organization->users()->whereKey(...)->exists()` の
  クエリを 1 本増やす。画面 1 枚あたり 1 クエリで、既存の `/app` 系と同水準。
- **`SeoManager::setPrivateTitle()` の存在**は `CaptureManualController::show()` の
  実使用で確認済み(`$seo->setPrivateTitle($manual->title.' の撮影')`)。

---

## 施策 3: アカウント確認画面の追加

### 変更箇所

- ファイル: `resources/js/pages/Capture/Account.svelte`(**新規**)

### 波及変更

- TypeScript 型定義: **なし**(既存 `SharedProps` をそのまま使う)
- API Resource/DTO: なし
- テストファイル: 施策 7(`tests/js/pages/CaptureAccount.test.ts` 新規)
- **ログアウト呼び出し箇所の目録**: 施策 5(**必須**)

### 現行コード

存在しない(新規)。参考にする現行実装:

```svelte
<!-- resources/js/components/templates/AppLayout.svelte (現行・抜粋) -->
    // ログアウト (二重送信ガード。失敗時も onFinish で解除され再試行できる)
    function logout(): void {
        if (loggingOut) return;
        router.post(
            "/logout",
            {},
            {
                onStart: () => { loggingOut = true; },
                onFinish: () => { loggingOut = false; },
            },
        );
    }
```

```svelte
<!-- resources/js/pages/Organizations/ApiKeys/Index.svelte L183 (現行・長い識別子の既存表現) -->
class="mt-2 block rounded-sm bg-surface px-3 py-2 text-caption font-mono break-all"
```

### 変更後コード

```svelte
<script lang="ts">
    import { page, router } from "@inertiajs/svelte";
    import { ArrowLeft, LogOut, UserRound } from "@lucide/svelte";
    import Button from "@/components/atoms/Button.svelte";
    import Card from "@/components/atoms/Card.svelte";
    import TextLink from "@/components/atoms/TextLink.svelte";
    import PageHeader from "@/components/molecules/PageHeader.svelte";
    import AppLayout from "@/components/templates/AppLayout.svelte";
    import PageContainer from "@/components/templates/PageContainer.svelte";
    import PageContent from "@/components/templates/PageContent.svelte";
    import type { SharedProps } from "@/lib/shared-props";

    /**
     * 撮影 PWA のアカウント確認画面 (doc/05 §5.1 / §5.2)。
     * 表示名・ログイン ID (= メールアドレス)・所属組織を**省略なく**読み、ログアウトする。
     *
     * ページ固有 props は持たない。表示値はすべて HandleInertiaRequests の共有 props。
     * **auth.user.id は描画に使わない** — 内部 DB の主キーであり利用者にとって意味を持たない。
     * doc の言う「ユーザー ID」の実体はログイン ID = メールアドレスである。
     *
     * 変更操作 (表示名・メール・パスワード・2FA・退会) は一切持たない。それらは /settings の
     * 責務で、**この画面からリンクもしない** — /app へ戻る導線を持たない面への入口を
     * 新設しないため (概念設計 G3)。
     */
    const shared = $derived(page.props as unknown as SharedProps);
    const appName = $derived(shared.appName ?? "");
    // auth / currentOrganization はサーバ側の到達条件 (auth middleware /
    // resolveMemberCurrentOrganization) で非 null が保証されるが、型は nullable なので
    // 防御的に扱う。**偽の既定値は作らない** — 無ければその行を出さない。
    const user = $derived(shared.auth?.user ?? null);
    const organizationName = $derived(shared.currentOrganization?.name ?? null);

    // 送信中の状態 (必須条件未充足の disabled ではない。AGENTS.md 禁止事項 8)。
    // Button atom の `loading` に渡すと disabled={disabled || loading} で押下不可になる。
    let loggingOut = $state(false);

    /**
     * ログアウト。**Inertia visit (router.post) 一本**である必要がある
     * (AGENTS.md ドメイン規約 3 経路 C: clearHistory を含む Inertia page の適用が保証条件)。
     * このファイルに fetch / axios を書かないこと
     * (tests/js/architecture/logout-call-site-inventory.test.ts が機械で固定する)。
     *
     * `if (loggingOut) return;` は**多重防御**である。Button atom が loading 中は
     * disabled になるため DOM 経由ではここに再入しない (= テストで固定しない。
     * 到達不能な経路のテストを作らない)。プログラム的な再呼び出しに対する保険として残す。
     */
    function logout(): void {
        if (loggingOut) return;
        router.post(
            "/logout",
            {},
            {
                onStart: () => {
                    loggingOut = true;
                },
                onFinish: () => {
                    loggingOut = false;
                },
            },
        );
    }
</script>

<AppLayout {appName}>
    <PageContainer>
        <PageHeader title="アカウント" icon={UserRound} testId="capture-account-heading" />
        <PageContent>
            <div class="mb-4">
                <TextLink href="/app" testId="capture-account-back">
                    <ArrowLeft class="inline size-3" aria-hidden="true" />
                    撮影に戻る
                </TextLink>
            </div>

            {#if user}
                <Card>
                    <dl class="flex flex-col gap-4">
                        <div>
                            <dt class="text-caption text-text-secondary">表示名</dt>
                            <dd class="mt-1 text-body text-text" data-testid="capture-account-name">
                                {user.name}
                            </dd>
                        </div>
                        <div>
                            <dt class="text-caption text-text-secondary">
                                ログイン ID (メールアドレス)
                            </dt>
                            <!-- 識別子は省略しない: truncate を使わず break-all で折り返す
                                 (親幅を超えて横スクロールを作らない)。DESIGN.md の役割マッピングに
                                 従い主要な識別値なので text-body (text-caption ではない)。 -->
                            <dd
                                class="mt-1 text-body break-all text-text"
                                data-testid="capture-account-email"
                            >
                                {user.email}
                            </dd>
                        </div>
                        {#if organizationName}
                            <div>
                                <dt class="text-caption text-text-secondary">所属組織</dt>
                                <dd
                                    class="mt-1 text-body break-all text-text"
                                    data-testid="capture-account-organization"
                                >
                                    {organizationName}
                                </dd>
                            </div>
                        {/if}
                    </dl>
                </Card>

                <p class="mt-3 text-caption text-text-secondary">
                    表示名・メールアドレスの変更は PC の個人設定から行います。
                </p>

                <div class="mt-6">
                    <Button
                        variant="danger-outline"
                        fullWidth
                        loading={loggingOut}
                        onclick={logout}
                        testId="capture-account-logout"
                    >
                        <LogOut class="size-4" aria-hidden="true" />
                        ログアウト
                    </Button>
                </div>
            {/if}
        </PageContent>
    </PageContainer>
</AppLayout>
```

### 設計判断と機械的検査との関係

- **`page-shell-structure.test.ts` 契約 1**: `AppLayout` を import するページは
  `PageContainer` / `PageHeader`(または `PageHeaderSection`)/ `PageContent` を
  **import かつ使用**する。本ページは 3 つとも満たすので allowlist 登録は不要。
- **`atomic-import-graph.test.ts`**: pages → templates / molecules / atoms / lib は単方向で合法。
- **`lucide-scoped-import.test.ts` / `svg-inline-allowlist.test.ts`**: アイコンは
  `@lucide/svelte` からのみ。SVG 直書きをしない。
- **`ds-purity.test.ts` / `typography-invariant.test.ts` / `contrast-invariant.test.ts`**:
  色・角丸・タイポは token 経由(`text-body` / `text-caption` / `text-text` /
  `text-text-secondary`)。hex 直書きなし。`Card` atom が `rounded-lg` と border を持つ。
- **`svelte-head-no-title.test.ts`**: `<svelte:head>` にタイトルを置かない
  (サーバの `SeoManager` が正本。施策 2 で `setPrivateTitle` 済み)。
- **`form-novalidate.test.ts`**: `<form>` を持たない(button の onclick のみ)ので対象外。
- **禁止事項 8**: `disabled` は書かない。`loading={loggingOut}` は Button atom の
  「送信中は押せない」契約(`disabled={disabled || loading}`)であり、
  **必須条件未充足による disabled ではない**。
- **`logout-call-site-inventory.test.ts`**: 施策 5 で目録登録する。
  同テストは登録ファイルに `fetch(` / `axios(` が無いことも検査するため、
  **このファイルに fetch / axios を書かない**。
- **URL は直書きする (route helper を使わない)**: 本リポジトリは **Ziggy を導入しておらず**
  (`package.json` / `composer.json` / `resources/js` のいずれにも無く、`route("…")` 形式の
  呼び出しは 0 件)、URL 直書きが規約である。前例は `pages/Dashboard.svelte` L328 の
  `href="/app"` (「撮影アプリを開く」) と `pages/Capture/Show.svelte` の
  `` href={`/app/projects/${project.id}/manuals`} ``。

### PHPStan 適合チェック

- 該当なし(TypeScript / Svelte)。`pnpm typecheck` と `pnpm lint` で担保する。
  `SharedProps` の nullable(`auth?.user` / `currentOrganization`)は
  `?? null` と `{#if}` で narrowing 済み。

### テスト計画

施策 7 を参照。

### リスク

- `user` が null のとき Card ごと描画されず、画面がほぼ空になる。ただし `/app/account` は
  `auth` middleware の中にあり、この状態は**到達不能**である。
  偽の既定値(「ユーザー」等)を出すよりも、空にして異常が見えるほうがよい。
- `break-all` は日本語の組織名でも任意位置で折り返す。組織名は識別のための表示であり、
  途中で切れるより折り返すほうが目的に合う。

---

## 施策 4: 撮影一覧からの入口

### 変更箇所

- ファイル: `resources/js/pages/Capture/Index.svelte`(L51-58 付近の見出し)

### 波及変更

- TypeScript 型定義: **なし**
- API Resource/DTO: なし
- テストファイル: 施策 7(`tests/js/pages/CaptureIndex.test.ts` に 1 ケース追加)

### 現行コード

```svelte
    import PageHeader from "@/components/molecules/PageHeader.svelte";
```

```svelte
<AppLayout {appName}>
    <PageContainer>
        <PageHeader
            title="撮影するマニュアルを選ぶ"
            description={project.name}
            icon={Camera}
            testId="capture-heading"
        />
```

### 変更後コード

```svelte
    import { Camera, Search, UserRound } from "@lucide/svelte";
    ...
    import TextLink from "@/components/atoms/TextLink.svelte";
    import PageHeaderSection from "@/components/molecules/PageHeaderSection.svelte";
```

```svelte
<AppLayout {appName}>
    <PageContainer>
        <!-- actions を持つため PageHeader (shorthand) ではなく PageHeaderSection を使う。
             アカウント確認導線は**この一覧画面にだけ**置く (Capture/Show には置かない —
             既に「一覧へ戻る」「マニュアル詳細へ」の 2 本があり狭幅で 3 本目が折り返す。
             撮影中にアカウントを確かめる場面は想定しない)。 -->
        <PageHeaderSection
            title="撮影するマニュアルを選ぶ"
            description={project.name}
            icon={Camera}
            testId="capture-heading"
        >
            <TextLink href="/app/account" testId="capture-account-link">
                <UserRound class="inline size-3" aria-hidden="true" />
                アカウント
            </TextLink>
        </PageHeaderSection>
```

`PageHeader` の import は削除する(**後方互換の並走を残さない**: 使わなくなった import を残さない)。

### 設計判断

- `PageHeader` は `PageHeaderSection` の薄い shorthand(`title` / `description` / `icon` /
  `testId` をそのまま渡すだけ)なので、置き換えても既存の見出しの見た目と `testId` は変わらない。
  既存の `capture-heading` testId をそのまま保つため、`CaptureIndex.test.ts` の既存ケースは壊れない。
- `PageHeaderSection` の `children` は `flex flex-wrap justify-end` の actions 領域で、
  `Capture/Show.svelte` が既に同じ形(`TextLink` を並べる)で使っている前例がある。
- **既存テストが壊れないことの確認**: `PageHeader.svelte` の中身は
  `<PageHeaderSection {title} {description} {icon} {testId} />` の 1 行だけで、置換してもマークアップは同一。
  既存 `tests/js/pages/CaptureIndex.test.ts` の 4 ケースは
  テキスト照合 / `capture-mine` の checked / `router.get` の引数検証のみで、
  **スナップショットも role/name 照合も使っていない**(実読確認済み)。
- **URL 直書き**: 施策 3 と同じ理由(Ziggy 不採用)。

### PHPStan 適合チェック

- 該当なし(TypeScript / Svelte)

### テスト計画

施策 7 を参照。

### リスク

- 見出しの右側に要素が増える。狭幅では `flex-wrap` で折り返す(既存 `Capture/Show` と同じ挙動)。
  **Vitest は jsdom でクラス名しか見ないため実レイアウトの折り返しは保証しない**
  (`docs/architecture.md` が既に同種の限界を明記している)。

---

## 施策 5: ログアウト呼び出し箇所の目録登録 + docs 更新

### 変更箇所

- ファイル: `tests/js/architecture/logout-call-site-inventory.test.ts`(inventory 定数 + 説明コメント)
- ファイル: `docs/supported-browsers.md`(L44-47 付近 / L237 付近の「3 箇所」表記)

### 波及変更

- TypeScript 型定義: なし
- API Resource/DTO: なし
- テストファイル: 本施策自体が既存 Architecture テストの更新である

### 現行コード

```ts
/**
 * `/logout` を参照してよいファイル (resources/js からの相対パス)。
 * 現状 3 箇所あり、いずれも router.post = Inertia visit
 * (AppLayout: 通常画面のユーザーメニュー / VerifyEmail: メール認証待ち画面の離脱導線 /
 *  RecentAuthRecoveryNotice: 再認証手段が無いユーザーの回復導線 = ログアウトして guest として
 *  パスワードを再設定する。/forgot-password は guest middleware 付きで直リンクできない。
 *  全画面 confirm (pages/Auth/ConfirmRecentAuth) とインラインモーダル
 *  (organisms/RecentAuthModal) の双方が本 molecule を使う)。
 */
const LOGOUT_CALL_SITE_INVENTORY: readonly string[] = [
  "components/templates/AppLayout.svelte",
  "pages/Auth/VerifyEmail.svelte",
  "components/molecules/RecentAuthRecoveryNotice.svelte",
] as const;
```

```markdown
アプリの `/logout` 導線は 3 箇所 (`AppLayout.svelte` / `pages/Auth/VerifyEmail.svelte` /
`components/molecules/RecentAuthRecoveryNotice.svelte`) で
いずれも `router.post` = Inertia visit のため、正常完了時にこの条件を満たす
```

```markdown
  現行の `/logout` 導線は 3 箇所ともに Inertia visit のため実運用では条件を満たすが、
```

### 変更後コード

```ts
/**
 * `/logout` を参照してよいファイル (resources/js からの相対パス)。
 * 現状 4 箇所あり、いずれも router.post = Inertia visit
 * (AppLayout: 通常画面のユーザーメニュー / VerifyEmail: メール認証待ち画面の離脱導線 /
 *  RecentAuthRecoveryNotice: 再認証手段が無いユーザーの回復導線 = ログアウトして guest として
 *  パスワードを再設定する。/forgot-password は guest middleware 付きで直リンクできない。
 *  全画面 confirm (pages/Auth/ConfirmRecentAuth) とインラインモーダル
 *  (organisms/RecentAuthModal) の双方が本 molecule を使う /
 *  Capture/Account: 撮影 PWA のアカウント確認画面。共有端末の引き渡し時に
 *  「自分のアカウントか確認してログアウトする」だけを行う面で、doc/05 §5.2 が要求する
 *  ログアウトをこの画面自身が持つ)。
 */
const LOGOUT_CALL_SITE_INVENTORY: readonly string[] = [
  "components/templates/AppLayout.svelte",
  "pages/Auth/VerifyEmail.svelte",
  "components/molecules/RecentAuthRecoveryNotice.svelte",
  "pages/Capture/Account.svelte",
] as const;
```

```markdown
アプリの `/logout` 導線は 4 箇所 (`AppLayout.svelte` / `pages/Auth/VerifyEmail.svelte` /
`components/molecules/RecentAuthRecoveryNotice.svelte` / `pages/Capture/Account.svelte`) で
いずれも `router.post` = Inertia visit のため、正常完了時にこの条件を満たす
```

```markdown
  現行の `/logout` 導線は 4 箇所ともに Inertia visit のため実運用では条件を満たすが、
```

### 設計判断

- 目録は deny-by-default なので、**登録しないと `pnpm test` が落ちる**(検出は文字列リテラル
  `"/logout"`)。同テストの説明コメント自身が「新しいログアウト導線を足したい場合は、それが
  Inertia visit (router.post) であることを確認した上で inventory に登録すること。
  `docs/supported-browsers.md` の経路 C の記述も更新する」と指示しており、本施策はその手順である。
- 2 本目の検査(登録ファイルは `router.post("/logout")` を使い、`fetch(` / `axios(` を持たない)は
  施策 3 の実装がそのまま満たす。
- **`docs/supported-browsers.md` の「3 箇所」は 2 か所に書かれている**(L44 付近と L237 付近)。
  **両方**直す(片方だけ直すと文書内で食い違う)。実装時に `rg '3 箇所' docs/supported-browsers.md`
  で残りが無いことを確認する。
- **docs は目録の走査対象ではない**。`logout-call-site-inventory.test.ts` の走査根は
  `resources/js` 配下の `.svelte` / `.ts` だけで `docs/` を見ない。よって docs に `/logout` の語を
  増やしても目録は反応せず、**docs の同期は人が守る約束**である
  (だからこそ目録テストの説明コメント自身が docs 更新を指示している)。

### PHPStan 適合チェック

- 該当なし

### テスト計画

- 既存 `logout-call-site-inventory.test.ts` が 2 ケースとも緑になること
  (`pnpm test` で確認。**新しいテストは足さない** — 既存 gate がそのまま契約である)

### リスク

- 経路 C の保証条件(`clearHistory: true` を含む Inertia page をクライアントが適用したタブ)は
  新導線でも同じ `router.post("/logout")` なので変わらない。
  Browser lane の `InertiaHistoryRestoreAfterLogoutTest` は `AppLayout` 経由の導線を使っており、
  **本施策では変更しない**(新導線用の Browser テストは足さない — 同一の `router.post("/logout")` で
  あることは Architecture テストが機械で固定しており、ブラウザ実測を二重化する価値が無い)。

---

## 施策 6: bug-hunt 目録への route 注釈追加と再生成

### 変更箇所

- ファイル: `.claude/skills/app-bug-hunt/inventory/annotations.toml`
- 生成物: `.claude/skills/app-bug-hunt/screens.md` / `operations.md`
  (**手で書かない**。`python3 scripts/bug-hunt-inventory.py generate` で再生成する)

### 波及変更

- TypeScript 型定義 / DTO / テスト: なし

### 現行コード

```toml
[routes."capture.home"]
kind = "画面"
story = "S3"
kubun = "通常"

[routes."capture.manuals.index"]
kind = "画面"
story = "S3"
kubun = "通常"
```

### 変更後コード

```toml
[routes."capture.account"]
kind = "画面"
story = "S3"
kubun = "通常"

[routes."capture.home"]
kind = "画面"
story = "S3"
kubun = "通常"
```

(TOML 内の並びは既存のキー順に合わせる。`capture.account` は `capture.csrf-cookie` の前。)

### 設計判断

- AGENTS.md §bug-hunt: 「route を足したら**注釈を 1 行足して再生成する**(表の行は手で書かない)」。
  `web` group を宣言した面なので目録に必ず入る。足さないと
  `scripts/bug-hunt-inventory-check.sh` が段 2(意味の欠落 = 新しい route に割当も対象外理由も無い)
  で落ちる。
- `story = "S3"` は既存の `capture.*` と同じ撮影ストーリー。`kubun = "通常"`
  (探索の分母に載せる。`外` にしない)。

### PHPStan 適合チェック

- 該当なし

### テスト計画

- `scripts/bug-hunt-inventory-check.sh` が exit 0(一致)になること。
  ⚠ 実行時は bug-hunt の provision / teardown を**行わない**(生成とドリフト検査のみ)。

### リスク

- 再生成で `screens.md` / `operations.md` の差分が本 route の行以外にも出る場合、
  それは既存のドリフトである。**判定基準**:
  - 生成差分が **`capture.account` 由来の行だけ**なら本タスクに含めてコミットする。
  - **それ以外の差分が出たら実装を止め、別タスク化して設計レビューへ戻す**。
    本タスクで巻き取らない(無関係な行を混ぜるとレビュー不能になる)。
    「報告して先へ進む」ではなく**止める**のが判定である
    (先へ進めるとドリフト検査が赤のまま PR を出すことになる)。

---

## 施策 7: テスト

### 変更箇所

- `tests/Feature/Capture/CaptureAccountScreenTest.php`(**新規**)
- `tests/js/pages/CaptureAccount.test.ts`(**新規**)
- `tests/js/pages/CaptureIndex.test.ts`(1 ケース追加)

### 波及変更

- なし(テストのみ)

### 現行コード(参考にする既存の形)

```php
// tests/Feature/Capture/CaptureManualBrowsingTest.php (現行・抜粋)
test('/app は current org の先頭 project の撮影一覧へ redirect する', function (): void {
    [, $owner, $project] = browsingContext();

    $this->actingAs($owner)->get('/app')
        ->assertRedirect("/app/projects/{$project->id}/manuals");
});
```

```ts
// tests/js/pages/CaptureIndex.test.ts (現行・抜粋)
it("自作トグルで GET クエリに mine=1 が載る", async () => {
    const getSpy = vi.spyOn(router, "get").mockImplementation(() => {});
    render(CaptureIndex, { props: baseProps });
    ...
});
```

### 変更後コード

#### `tests/Feature/Capture/CaptureAccountScreenTest.php`(新規)

```php
<?php

declare(strict_types=1);

use App\Enums\ProjectRole;
use App\Models\Project;
use App\Models\User;
use Inertia\Testing\AssertableInertia as Assert;

/*
 * 撮影 PWA のアカウント確認画面 (doc/05 §5.1 / §5.2)。
 * 到達条件 (current org 所属 = 200 / 未設定・非所属 = 認可より前に 404) と、
 * 撮影者ロールでも 200 になることを固定する。
 * 表示値は共有 props (auth.user / currentOrganization) なので、
 * 「画面がその共有 props を伴って返る」ことまでをサーバ側の契約とする。
 */

test('current org 所属なら 200 で Capture/Account を返す', function (): void {
    [$organization, $owner] = createOrganizationWithOwner();

    $this->actingAs($owner)->get('/app/account')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Capture/Account')
            ->where('auth.user.email', $owner->email)
            ->where('auth.user.name', $owner->name)
            ->where('currentOrganization.name', $organization->name)
        );
});

/*
 * この route は project 非依存なので、到達条件は「current org に在籍していること」だけである。
 * project role (撮影者 = project_member) は**この route の認可条件ではない**。
 * それでも撮影者を作るのは、現場で実際にこの画面へ来る人物像で 200 を確かめるためである。
 */
test('組織メンバー (撮影者ロールの利用者) でも 200 — project role は条件ではない', function (): void {
    [$organization, ] = createOrganizationWithOwner();
    $project = Project::factory()->forOrganization($organization)->create();
    $member = attachOrganizationMember($organization);
    // attachOrganizationMember は current_organization_id を設定しない
    // (既存 TakeUploadUrlTest と同じ手順で明示代入する)
    $member->forceFill(['current_organization_id' => $organization->id])->save();
    attachProjectMember($project, $member, ProjectRole::Member);

    $this->actingAs($member)->get('/app/account')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->component('Capture/Account'));
});

test('current org 未設定なら 404 (組織の有無を露出しない)', function (): void {
    $user = User::factory()->create(); // 組織に属さない

    $this->actingAs($user)->get('/app/account')->assertNotFound();
});

test('current org に非所属なら 404 (認可より前)', function (): void {
    [$organization, ] = createOrganizationWithOwner();
    [, $stranger] = createOrganizationWithOwner('別組織');

    // 他組織の owner の current org を、**非所属の**組織に向ける
    // (current_organization_id が退会後も残存する不整合を模す)
    $stranger->forceFill(['current_organization_id' => $organization->id])->save();

    // 前提: stranger は $organization に在籍していない (この前提が崩れるとテストが空振りする)
    expect($organization->users()->whereKey($stranger->getKey())->exists())->toBeFalse();

    $this->actingAs($stranger)->get('/app/account')->assertNotFound();
});

test('未認証はログインへ redirect する', function (): void {
    $this->get('/app/account')->assertRedirect('/login');
});
```

#### `tests/js/pages/CaptureAccount.test.ts`(新規)

```ts
import { afterEach, describe, expect, it, vi } from "vitest";
import { cleanup, fireEvent, render, screen } from "@testing-library/svelte";

/*
 * 撮影 PWA アカウント確認画面 Capture/Account。
 * - 表示名 / ログイン ID (メール) / 所属組織を共有 props から描画する
 * - メールは truncate せず break-all で全文が DOM に載る (省略した識別子では確認にならない)
 * - auth.user.id は描画に使わない (内部主キー。props には存在するが画面には出さない)
 * - ログアウトは router.post("/logout") = Inertia visit (経路 C の保証条件)
 * - ログアウト送信中はボタンが押下不可になる (必須条件未充足の disabled ではない)
 * - 復路リンクは /app (capture.home)
 *
 * mock 方式は既存 tests/js/pages/SettingsIndex.test.ts と同一
 * (vi.hoisted で plain object を作り vi.mock で page / router を差し替える)。
 */

const { pageState, routerPostMock } = vi.hoisted(() => ({
    pageState: { props: {} as Record<string, unknown>, url: "/app/account" },
    routerPostMock: vi.fn(),
}));

vi.mock("@inertiajs/svelte", async (importOriginal) => ({
    ...(await importOriginal<typeof import("@inertiajs/svelte")>()),
    router: { post: routerPostMock },
    page: pageState,
}));

const CaptureAccount = (await import("@/pages/Capture/Account.svelte")).default;

function seed(overrides: Record<string, unknown> = {}): void {
    pageState.props = {
        appName: "AI-CUE",
        auth: {
            user: {
                // 他の表示値 (組織名・アプリ名・メール) と絶対に衝突しない値にする。
                // 「描画に使っていない」を container.textContent の非包含で確かめるため、
                // 短い数値 (42 等) だと偶然一致して偽陽性になる。
                id: 987654321,
                name: "撮影 太郎",
                email: "shooting.taro.very.long.local.part@example.co.jp",
                emailVerified: true,
                twoFactorEnabled: false,
            },
        },
        currentOrganization: {
            id: 1,
            name: "サンプル組織",
            slug: "sample",
            role: "organization_member",
            canManageMembers: false,
            canManageApiKeys: false,
        },
        ...overrides,
    };
}

describe("Capture/Account", () => {
    afterEach(() => {
        cleanup();
        vi.clearAllMocks();
    });

    it("表示名・ログイン ID・所属組織を描画する", () => {
        seed();
        render(CaptureAccount);

        expect(screen.getByTestId("capture-account-name")).toHaveTextContent("撮影 太郎");
        expect(screen.getByTestId("capture-account-email")).toHaveTextContent(
            "shooting.taro.very.long.local.part@example.co.jp",
        );
        expect(screen.getByTestId("capture-account-organization")).toHaveTextContent(
            "サンプル組織",
        );
    });

    it("メールは truncate せず break-all で全文を出す", () => {
        seed();
        render(CaptureAccount);

        const email = screen.getByTestId("capture-account-email");
        expect(email.className).toContain("break-all");
        expect(email.className).not.toContain("truncate");
    });

    it("auth.user.id を描画に使わない", () => {
        seed();
        const { container } = render(CaptureAccount);

        expect(container.textContent).not.toContain("987654321");
    });

    it("復路リンクは /app (capture.home)", () => {
        seed();
        render(CaptureAccount);

        expect(screen.getByTestId("capture-account-back")).toHaveAttribute("href", "/app");
    });

    it("ログアウトは router.post('/logout') を呼ぶ", async () => {
        seed();
        render(CaptureAccount);

        await fireEvent.click(screen.getByTestId("capture-account-logout"));

        expect(routerPostMock).toHaveBeenCalledTimes(1);
        expect(routerPostMock.mock.calls[0][0]).toBe("/logout");
    });

    /*
     * 固定するのは **Button atom の loading 契約** (disabled={disabled || loading}) である。
     * Svelte 側の `if (loggingOut) return;` は DOM 経由では到達しない多重防御なので
     * ここでは固定しない (到達不能な経路のテストを作らない)。
     */
    it("ログアウト送信中はボタンが押下不可になる", async () => {
        seed();
        // onStart を呼んで loggingOut=true にしたまま応答を返さない (送信中を再現する)
        routerPostMock.mockImplementation(
            (_url: string, _data: unknown, options: { onStart?: () => void }) => {
                options.onStart?.();
            },
        );
        render(CaptureAccount);

        const button = screen.getByTestId("capture-account-logout");
        await fireEvent.click(button);

        expect(button).toBeDisabled();

        await fireEvent.click(button);
        expect(routerPostMock).toHaveBeenCalledTimes(1);
    });

    it("currentOrganization が null なら組織行を出さない (偽の既定値を作らない = 補助テスト)", () => {
        seed({ currentOrganization: null });
        render(CaptureAccount);

        expect(screen.queryByTestId("capture-account-organization")).toBeNull();
    });
});
```

#### `tests/js/pages/CaptureIndex.test.ts`(追加ケース)

```ts
    it("見出しにアカウント確認画面への導線がある", () => {
        render(CaptureIndex, { props: baseProps });

        expect(screen.getByTestId("capture-account-link")).toHaveAttribute(
            "href",
            "/app/account",
        );
    });
```

### PHPStan 適合チェック

- [x] Feature テストの closure は `function (): void` で戻り値型を明示
- [x] `Assert $page` の型を明示(既存 `CaptureManualBrowsingTest` と同じ形)
- [x] `forceFill(['current_organization_id' => ...])` は保護キーの明示代入
      (payload 由来ではなくテストが直接組み立てる状態)

### テスト計画(この施策自体)

- [x] バグ修正ではないので再現テストは不要
- [x] 既存テスト `tests/js/pages/CaptureIndex.test.ts` の更新(導線 1 ケース追加。
      既存 4 ケースは `capture-heading` testId が保たれるので壊れない)
- [x] 既存テスト `tests/js/architecture/logout-call-site-inventory.test.ts` の更新(施策 5)
- [x] 新規 `tests/Feature/Capture/CaptureAccountScreenTest.php` — 到達条件 5 ケース
- [x] 新規 `tests/js/pages/CaptureAccount.test.ts` — 描画・導線・ログアウト 7 ケース
      (mock 方式は既存 `SettingsIndex.test.ts` と同一)
- [x] 個別の `DatabaseTransactions` を使っていない(`tests/Pest.php` の `RefreshDatabase` 任せ)
- [x] テストデータは Factory 経由(`User::factory()` / `Project::factory()` /
      `createOrganizationWithOwner()` / `attachOrganizationMember()` / `attachProjectMember()`)

### リスク

- ヘルパのシグネチャは `tests/Pest.php` を実読して確認済み:
  `attachOrganizationMember(Organization $organization, OrganizationRole $role = Member): User` /
  `attachProjectMember(Project $project, User $user, ProjectRole $role = Member): void` /
  `createOrganizationWithOwner(string $name = 'テスト組織', bool $grandfatherFreePlan = true): array`。
  enum の名前空間は `App\Enums\ProjectRole`(`App\Enums\Project\ProjectRole` **ではない**)。
- **`attachOrganizationMember()` は `current_organization_id` を設定しない**ため、
  HTTP 経由のテストでは `forceFill(['current_organization_id' => …])->save()` が必須である
  (既存 `tests/Feature/Capture/TakeUploadUrlTest.php` L208-210 と同じ手順)。
  忘れると `resolveCurrentOrganization()` が 404 を返してテストが誤って赤くなる。
- Vitest の `vi.mock("@inertiajs/svelte", …)` は `AppLayout` も同じモックを見る。
  `AppLayout` は `router.post` / `page` に加えて `router` の他メソッドを使わないため
  (`selectOrganization` は `router.post`、ログアウトも `router.post`)、`post` だけの
  差し替えで足りる。もし `AppLayout` 側が他メソッドを要求して落ちるなら、
  `SettingsIndex.test.ts` と同じく必要なメソッドを追加した double にする。

---

## 使命・禁止事項の最終確認

- **使命への寄与**: 共有端末を使う現場作業者が、管理者向けメニューを読み解かずに
  「自分のアカウントか」を省略なく確認し、そのままログアウトできる。
  「専門知識ゼロの現場作業者でも」に直接効く。
- **禁止事項 4**(`response()->json()` 直書き): 使わない。Inertia page のみ。
- **禁止事項 8**(条件未充足の disabled): 作らない。`loading` は送信中の再送防止。
- **ドメイン規約 3**(ログアウト導線): `router.post` の Inertia visit + 目録登録 + docs 更新。
- **ドメイン規約 4**(課金ゲート): `require-active-subscription` group の中に置く。
- **テストなしの実装完了報告**: 施策 5・6 を含め全施策にテスト / 機械検査を対応させた。

## 実装モード

| 項目 | 内容 |
|------|------|
| 推奨モード | **standalone** |
| 判断根拠 | 施策 1〜7 は 1 つの画面を成立させるために相互依存する(route が無ければ画面に到達できず、画面が無ければ目録登録が落ち、導線が無ければ到達できない)。分割してマージすると中間状態で `pnpm test` が赤になる(目録が deny-by-default のため、画面だけ先に入れると即落ちる)。全体で 7 ファイル程度と小さく、1 本の worktree で完結する |
| 競合リスク | `routes/web.php` は他タスクも触りやすい。撮影 PWA group への追記なので衝突時は行単位で解消できる。`docs/supported-browsers.md` と `.claude/skills/app-bug-hunt/inventory/annotations.toml` も並行タスクが触る可能性がある。`resources/js/pages/Capture/Index.svelte` は撮影系の他タスクと競合しうる |


---

## design system 参照 (DESIGN.md 抜粋)

## Colors

色は意味で割り当てる。順序や見た目の好みで使い分けない。

- **Primary(#2563EB)**: ブランドの中核。プライマリボタン、リンク、選択中のナビゲーション。
  1 画面の主要 CTA 以外には濫用しない。
  - tailwind: `bg-primary`, `text-primary`, `border-primary`、hover は `hover:bg-primary-hover`
- **Tertiary(#0F766E)**: 強いアクセント。緊急性・重要性のある前向き CTA、特別なバッジに限定。
  1 画面に 1 箇所が原則。
  - tailwind: `bg-tertiary`, `text-tertiary`, `border-tertiary`、hover は `hover:bg-tertiary-hover`
- **Neutral(#F4F4F5)**: 主要な背景色。画面全体はこの色で塗る。
  - tailwind: `bg-neutral`
- **Surface(#FFFFFF)**: カード・モーダル・浮いた要素の背景。Neutral との明度差で奥行きを出す。
  - tailwind: `bg-surface`
- **Border(#E4E4E7)**: 区切り線、入力欄の枠。常に細く(1px)。
  - tailwind: `border-border`
- **Border Strong(#A1A1AA)**: 区切りの強調、ghost ボタンの枠。
  - tailwind: `border-border-strong`
- **Text Primary(#18181B)**: 本文・見出しの主たる色。純黒は使わない。
  - tailwind: `text-text`(`--color-text` を参照)
- **Text Secondary(#52525B)**: 補足文、キャプション、ラベル。
  - tailwind: `text-text-secondary`

### 状態色

- **Success(#15803D)**: 完了・正常・公開済み。
  - tailwind: `text-success`, `bg-success`, `border-success`
- **Warning(#B45309)**: 注意・確認が必要・保留。
  - tailwind: `text-warning`, `bg-warning`, `border-warning`
- **Danger(#B91C1C)**: 失敗・破壊的操作・エラー。Tertiary とは別物
  (Tertiary は前向きな強調、Danger は否定的なシグナル)。
  - tailwind: `text-danger`, `bg-danger`, `border-danger`

状態色・アクセントは Tailwind の **-700 段**で揃える(`tertiary` teal-700 / `success` green-700 /
`warning` amber-700 / `danger` red-700)。`neutral`(#F4F4F5)や `surface`(#FFFFFF)の上で
**本文コントラスト 4.5:1** を確保するための下限であり、これより明るい段は使わない
(`tests/js/architecture/contrast-invariant.test.ts` が機械検証する)。

ソフト背景は状態色の opacity 修飾で表現する(`bg-success/10`, `bg-danger/10`,
`bg-primary-soft` 等)。**新しい色トークンを足す前に opacity 修飾と atom 化で表現できないか
検討すること**(追加条件は `docs/design-system.md` の 4 条件)。

## Typography

全ランプ Noto Sans JP。フォントウェイトは **400 と 500 の 2 階層のみ**(700 は使わない)。
コード・識別子・数値整列には `font-mono` を許可する(日本語 prose には使わない)。

### Typography ramp utility

各 ramp は `resources/css/tokens.css` の `@utility` で定義済。実装はこの utility を
そのまま class として適用する。**raw の `text-sm` / `font-bold` 等は禁止**(ds-purity が検出)。

- **text-display**: 48px / 500 / lh 1.2 / ls 0.02em — tailwind: `text-display`
- **text-h1**: 32px / 500 / lh 1.3 / ls 0.02em — tailwind: `text-h1`
- **text-h2**: 24px / 500 / lh 1.4 — tailwind: `text-h2`
- **text-h3**: 18px / 500 / lh 1.5 — tailwind: `text-h3`
- **text-body**: 16px / 400 / lh 1.7 — tailwind: `text-body`
- **text-caption**: 12px / 400 / lh 1.5 — tailwind: `text-caption`

役割マッピング: 本文/入力値/主要数値 → `text-body`、ラベル/補助情報/日時 → `text-caption`、
page タイトル → `text-h1`/`text-h2`、section/card 見出し → `text-h3`。
強調は `font-medium`(500)を上限とし、足りなければ weight を上げず ramp 昇格+余白+
色階層(text vs text-secondary)でコントラストを作る。

### Button

実装: `components/atoms/Button.svelte`(仕様の真実は `Button.types.ts`)。

| variant | 用途 | スタイル要旨 |
|---------|------|------------|
| `primary` | 主要 CTA(1 画面 1 つ目安) | bg-primary + text-neutral |
| `tertiary` | 真に重要な前向き CTA(1 画面 1 箇所) | bg-tertiary + text-neutral |
| `ghost` | 補助・キャンセル | 透明 + border-border-strong、hover で primary 化 |
| `neutral` | 取消可能・UI-only の補助操作(一時停止等) | bg-neutral + 常時 border(境界確保) |
| `success` | 肯定操作(追加・承認・付与) | bg-success + text-neutral |
| `danger` | dialog/form の主破壊 CTA | bg-danger + text-neutral |
| `danger-outline` | section 単位の破壊(card 内の削除) | border-danger、hover で塗り |
| `danger-ghost` | dense な row/list 内の破壊アクション | text-danger + 透明、hover で淡い tint |

- **全 variant が border(透明 or 色)を持ち外形高さを統一する**
- danger 系は irreversible / destructive 操作専用(削除・revoke・移譲・再開不可の中断)。
  危険度ではなく**配置文脈**で 3 重みを選ぶ
- **anchor 対応**: `href` 指定で `<a>`(`inertia` 指定で Inertia Link)。anchor モードでは
  `type`/`disabled` は型レベルで禁止。`target="_blank"` には `rel="noopener noreferrer"` を自動補完
- **iconOnly**: `ghost` / `neutral` / `danger-ghost` のみ許可。`ariaLabel` が型で必須
- **disclosure**: button モード限定で `ariaExpanded` / `ariaControls` / `element`(bindable な
  `HTMLButtonElement` 参照)を受ける。ハンバーガー等のトグルはこれを使い素の `<button>` を書かない
- size: `sm`(caption)/ `md`(既定)/ `lg`(form 入力面との高さ整合限定)

### Card

実装: `components/atoms/Card.svelte`。浮いた要素の基本サーフェス
(`bg-surface border border-border rounded-lg`。影を使わず明度差 + 1px border で階層を
表現する — §Elevation & Depth)。padding: `none`(table/list 等を内包し内側で個別に
padding を制御する箱用)/ `sm` / `md`(既定)/ `lg`。

### TextLink

実装: `components/atoms/TextLink.svelte`(仕様の真実は `TextLink.types.ts`)。
リンク風 `<a>` / `<button>` の手書きは禁止(§Do's and Don'ts)、本 atom を使う。
3 モードの discriminated union: (a) `href` のみ = Inertia Link(SPA 遷移)、
(b) `href` + `external` = ネイティブ `<a>` + 別タブ + `rel="noopener noreferrer"` +
末尾 ExternalLink アイコン(`icon` で差し替え可)、(c) `onclick` のみ = リンク風
`<button type="button">`。様式は `text-primary` + 下線(hover で下線が濃くなる)で 3 モード共通。

### PageHeader / PageHeaderSection

実装: `components/molecules/PageHeaderSection.svelte`(full feature)と
`components/molecules/PageHeader.svelte`(shorthand)。

- **PageHeaderSection**: `title` / `breadcrumbs` / `description` / `icon`(Lucide 互換
  `Component`)/ actions(`children` Snippet)を持つ詳細画面用ヘッダ。全幅バーは
  PageContainer の padding を打ち消す**負マージン契約**で敷き、サイドバーのロゴブロックと
  同じ高さに揃える。**パンくずは 2 件以上のときだけ出す**(1 件は h1 と二重提示になるため)。
- **PageHeader**: breadcrumbs / actions を使わないルート画面用の薄いラッパー。
  内部で PageHeaderSection を呼ぶだけ。**actions や breadcrumbs が要るなら
  PageHeaderSection を直接使う**(PageHeader に prop を足さない)。
- actions は children Snippet で渡す(旧 slot API は使わない)。

## Do's and Don'ts

**Do**

- 背景は常に neutral、浮いた要素は surface(逆に使わない)
- 余白を多めにとる。色は Primary / Tertiary / 状態色 1 種までを目安に
- 操作の可否は**押した後のフィードバック**で伝える(バリデーションエラー表示+フォーカス移動)
- **認証フロー画面(`AuthLayout`)には離脱導線を footer に必ず置く**。その手順を完了できない
  ユーザー(リンク期限切れ・コード紛失・再認証手段なし)が別の入口へ抜けられる `TextLink` を
  `{#snippet footer()}` に 1 つ以上持つ。行き先は**その画面のユーザーの認証状態で実際に
  踏破できる先**に限る(`tests/js/architecture/page-shell-structure.test.ts` が機械強制。
  例外は理由付き allowlist)

**Don't**

- グラデーション・ドロップシャドウ・scale 効果を使わない
- Danger と Tertiary を同一 action cluster・隣接 CTA 群で併置しない(赤系・強調系の意味が混ざる)
- **必須条件未充足を理由にボタンを disabled でブロックしない**。ボタンは活性のまま、
  押下時に何が足りないかをエラー表示する(例: 利用規約同意チェック。
  disabled はユーザーに「なぜ押せないか」を伝えられない)
- **表示条件と踏破条件が食い違う導線を出さない**。押しても必ず失敗するボタン・リンク
  (認証・権限・ゲートで確実に弾かれる先を指すもの)は**出さずに、なぜ今は進めないかを
  文章で説明する**。disabled 化でも代替しない(上の Don't と同根。例: メール未認証画面から
  `verified` ゲート内の checkout へ進む CTA)
- ページ内で素の `<input>` / `<table>` / リンク風 `<a>` 手書きをしない(対応する atom/molecule を使う)
- **native の constraint validation に検証を任せない**。`<form>` には `novalidate` を付け、
  検証文言はサーバ(日本語)と押下時の client エラーに一本化する。
  native validation は submit より先に発火してブラウザロケール依存の文言で送信を止めるため、
  日本語 UI の検証経路に到達できなくなる(`tests/js/architecture/form-novalidate.test.ts` が機械検証)

触れた atomic ディレクトリ:
- `resources/js/pages/Capture/Account.svelte` (新規 / pages 層)
- `resources/js/pages/Capture/Index.svelte` (pages 層)
- 参照した既存 component: `atoms/Button.svelte` (loading→disabled 契約) / `atoms/Card.svelte` /
  `atoms/TextLink.svelte` (href のみ = Inertia Link) / `molecules/PageHeader.svelte` /
  `molecules/PageHeaderSection.svelte` (children = actions 領域) /
  `templates/AppLayout.svelte` / `templates/PageContainer.svelte` / `templates/PageContent.svelte`

---

## 実装差分 (git diff)

```diff
diff --git a/.claude/skills/app-bug-hunt/inventory/annotations.toml b/.claude/skills/app-bug-hunt/inventory/annotations.toml
index 1285774..ba4e1e6 100644
--- a/.claude/skills/app-bug-hunt/inventory/annotations.toml
+++ b/.claude/skills/app-bug-hunt/inventory/annotations.toml
@@ -55,6 +55,11 @@ kind = "画面"
 story = "S5"
 kubun = "通常"
 
+[routes."capture.account"]
+kind = "画面"
+story = "S3"
+kubun = "通常"
+
 [routes."capture.csrf-cookie"]
 kind = "JSON"
 story = "S3"
diff --git a/.claude/skills/app-bug-hunt/screens.md b/.claude/skills/app-bug-hunt/screens.md
index b78db24..acbf42a 100644
--- a/.claude/skills/app-bug-hunt/screens.md
+++ b/.claude/skills/app-bug-hunt/screens.md
@@ -6,7 +6,7 @@ # 画面インベントリ (screens.md) — AI-CUE
 > 抽出条件: 開発環境 (local) またはテスト実行中に登録される route 集合。
 > ドリフト検査: `scripts/bug-hunt-inventory-check.sh` (exit 3 = ドリフト)。
 
-bug-hunt カバレッジの分母となる「画面」(GET × web セッション面) の一覧。全 70 件 (うち対象外 13 件)。
+bug-hunt カバレッジの分母となる「画面」(GET × web セッション面) の一覧。全 71 件 (うち対象外 13 件)。
 
 ## GET × web 一覧 (画面 + 画面に付随する JSON GET)
 
@@ -15,6 +15,7 @@ ## GET × web 一覧 (画面 + 画面に付随する JSON GET)
 | billing | billing.index | 画面 | プランとお支払い | S5 | 通常 |
 | billing/plans | billing.plans | 画面 | プラン比較 | S5 | 通常 |
 | purchase-tickets | billing.tickets.show | 画面 | チケットを購入 | S5 | 通常 |
+| app/account | capture.account | 画面 | アカウント | S3 | 通常 |
 | app/csrf-cookie | capture.csrf-cookie | JSON | - | S3 | 通常 |
 | app | capture.home | 画面 | - | S3 | 通常 |
 | app/projects/{project}/manuals | capture.manuals.index | 画面 | 撮影するマニュアルを選ぶ | S3 | 通常 |
diff --git a/app/Http/Controllers/Capture/CaptureAccountController.php b/app/Http/Controllers/Capture/CaptureAccountController.php
new file mode 100644
index 0000000..995eb84
--- /dev/null
+++ b/app/Http/Controllers/Capture/CaptureAccountController.php
@@ -0,0 +1,42 @@
+<?php
+
+declare(strict_types=1);
+
+namespace App\Http\Controllers\Capture;
+
+use App\Http\Concerns\ResolvesCurrentOrganization;
+use App\Http\Controllers\Controller;
+use Illuminate\Http\Request;
+use Inertia\Inertia;
+use Inertia\Response;
+
+/**
+ * 撮影 PWA のアカウント確認画面 (doc/05 §5.1 / §5.2)。
+ *
+ * **表示専用**である。表示名・ログイン ID (= メールアドレス)・所属組織はすべて
+ * HandleInertiaRequests が全ページへ共有している props (auth.user / currentOrganization) で
+ * 賄えるため、**ページ固有 props を 1 つも返さない**。値を二重に持たない
+ * (共有 prop と page prop が食い違う余地を作らない)。
+ *
+ * タイトルは**静的**なので `config('seo.app_titles')` の route 既定に置く
+ * (`SeoManager::setPrivateTitle()` は動的な固有名 = マニュアル名等を controller から
+ * 供給する用途。静的名をそちらへ置くと bug-hunt 目録の画面名も空欄になる)。
+ *
+ * current organization は resolveMemberCurrentOrganization() で解決する。これは
+ * 共有 prop 側 (HandleInertiaRequests) が「current_organization_id が指す組織に
+ * **非所属**なら null に倒す」のと**同じ述語**をサーバ側に置くためで、
+ * 到達した画面では currentOrganization が非 null であることが保証される
+ * (未設定・非所属はどちらも認可より前に 404 = 組織の存在を露出しない)。
+ */
+class CaptureAccountController extends Controller
+{
+    use ResolvesCurrentOrganization;
+
+    public function __invoke(Request $request): Response
+    {
+        // current org 解決 + 在籍 guard。**戻り値は使わず副作用 (未設定 / 非所属で 404) のために呼ぶ**
+        $this->resolveMemberCurrentOrganization($request);
+
+        return Inertia::render('Capture/Account');
+    }
+}
diff --git a/config/seo.php b/config/seo.php
index 95397ab..569bf94 100644
--- a/config/seo.php
+++ b/config/seo.php
@@ -142,9 +142,11 @@
         'notifications.index' => '通知',
         /*
         | 撮影 PWA (/app/*)。manuals.show は controller が setPrivateTitle で
-        | マニュアル名を供給するため、静的名が必要なのは一覧 (index) のみ。
+        | マニュアル名を供給するため、静的名が必要なのは一覧 (index) と
+        | アカウント確認画面 (account) の 2 つ。
         | スマホで複数タブ / ホーム画面から戻る現場ユースケースではタブ名が唯一の識別子。
         */
+        'capture.account' => 'アカウント',
         'capture.manuals.index' => '撮影するマニュアルを選ぶ',
     ],
 
diff --git a/docs/supported-browsers.md b/docs/supported-browsers.md
index 3c276e2..204ec15 100644
--- a/docs/supported-browsers.md
+++ b/docs/supported-browsers.md
@@ -42,8 +42,8 @@ # サポート対象ブラウザ方針
 `Inertia::clearHistory()` はサーバ session にフラグを積むだけで、`sessionStorage` の
 履歴暗号鍵が実際に消えるのは `page.set()` 冒頭の `history.clear()` が走った瞬間だからである
 (受信ではなく適用。通信断や JS 例外で適用前に中断すれば鍵は残る)。
-アプリの `/logout` 導線は 3 箇所 (`AppLayout.svelte` / `pages/Auth/VerifyEmail.svelte` /
-`components/molecules/RecentAuthRecoveryNotice.svelte`) で
+アプリの `/logout` 導線は 4 箇所 (`AppLayout.svelte` / `pages/Auth/VerifyEmail.svelte` /
+`components/molecules/RecentAuthRecoveryNotice.svelte` / `pages/Capture/Account.svelte`) で
 いずれも `router.post` = Inertia visit のため、正常完了時にこの条件を満たす
 (この不変条件は `tests/js/architecture/logout-call-site-inventory.test.ts` が固定する)。
 **ログアウト導線を非 Inertia 経路 (JSON 204 で完結する XHR 等) で新設すると、
@@ -234,7 +234,7 @@ ## 未対応事項 (誤読を防ぐため明示列挙する)
 - **経路 C は「`clearHistory: true` を含む Inertia page をクライアントが適用したタブ」のみを保証する**
   (受信ではなく適用)。JSON 204 で完結するログアウト (Fortify 既定の `wantsJson()` 分岐) では、
   次の Inertia page を適用するまでクライアントの履歴暗号鍵は残る。
-  現行の `/logout` 導線は 3 箇所ともに Inertia visit のため実運用では条件を満たすが、
+  現行の `/logout` 導線は 4 箇所ともに Inertia visit のため実運用では条件を満たすが、
   非 Inertia のログアウト導線を新設すると保証が外れる
   (`tests/js/architecture/logout-call-site-inventory.test.ts` が deny-by-default で固定)。
   ただし **204 で完結したタブも、次に認証を要する Inertia visit を行った時点**で
diff --git a/resources/js/pages/Capture/Account.svelte b/resources/js/pages/Capture/Account.svelte
new file mode 100644
index 0000000..85326c4
--- /dev/null
+++ b/resources/js/pages/Capture/Account.svelte
@@ -0,0 +1,131 @@
+<script lang="ts">
+    import { page, router } from "@inertiajs/svelte";
+    import { ArrowLeft, LogOut, UserRound } from "@lucide/svelte";
+    import Button from "@/components/atoms/Button.svelte";
+    import Card from "@/components/atoms/Card.svelte";
+    import TextLink from "@/components/atoms/TextLink.svelte";
+    import PageHeader from "@/components/molecules/PageHeader.svelte";
+    import AppLayout from "@/components/templates/AppLayout.svelte";
+    import PageContainer from "@/components/templates/PageContainer.svelte";
+    import PageContent from "@/components/templates/PageContent.svelte";
+    import type { SharedProps } from "@/lib/shared-props";
+
+    /**
+     * 撮影 PWA のアカウント確認画面 (doc/05 §5.1 / §5.2)。
+     * 表示名・ログイン ID (= メールアドレス)・所属組織を**省略なく**読み、ログアウトする。
+     *
+     * ページ固有 props は持たない。表示値はすべて HandleInertiaRequests の共有 props。
+     * **auth.user.id は描画に使わない** — 内部 DB の主キーであり利用者にとって意味を持たない。
+     * doc の言う「ユーザー ID」の実体はログイン ID = メールアドレスである。
+     *
+     * 変更操作 (表示名・メール・パスワード・2FA・退会) は一切持たない。それらは /settings の
+     * 責務で、**この画面からリンクもしない** — /app へ戻る導線を持たない面への入口を
+     * 新設しないため (概念設計 G3)。
+     */
+    const shared = $derived(page.props as unknown as SharedProps);
+    const appName = $derived(shared.appName ?? "");
+    // auth / currentOrganization はサーバ側の到達条件 (auth middleware /
+    // resolveMemberCurrentOrganization) で非 null が保証されるが、型は nullable なので
+    // 防御的に扱う。**偽の既定値は作らない** — 無ければその行を出さない。
+    const user = $derived(shared.auth?.user ?? null);
+    const organizationName = $derived(shared.currentOrganization?.name ?? null);
+
+    // 送信中の状態 (必須条件未充足の disabled ではない。AGENTS.md 禁止事項 8)。
+    // Button atom の `loading` に渡すと disabled={disabled || loading} で押下不可になる。
+    let loggingOut = $state(false);
+
+    /**
+     * ログアウト。**Inertia visit (router.post) 一本**である必要がある
+     * (AGENTS.md ドメイン規約 3 経路 C: clearHistory を含む Inertia page の適用が保証条件)。
+     * このファイルに fetch / axios を書かないこと
+     * (tests/js/architecture/logout-call-site-inventory.test.ts が機械で固定する)。
+     *
+     * `if (loggingOut) return;` は**多重防御**である。Button atom が loading 中は
+     * disabled になるため DOM 経由ではここに再入しない (= テストで固定しない。
+     * 到達不能な経路のテストを作らない)。プログラム的な再呼び出しに対する保険として残す。
+     */
+    function logout(): void {
+        if (loggingOut) return;
+        router.post(
+            "/logout",
+            {},
+            {
+                onStart: () => {
+                    loggingOut = true;
+                },
+                onFinish: () => {
+                    loggingOut = false;
+                },
+            },
+        );
+    }
+</script>
+
+<AppLayout {appName}>
+    <PageContainer>
+        <PageHeader title="アカウント" icon={UserRound} testId="capture-account-heading" />
+        <PageContent>
+            <div class="mb-4">
+                <TextLink href="/app" testId="capture-account-back">
+                    <ArrowLeft class="inline size-3" aria-hidden="true" />
+                    撮影に戻る
+                </TextLink>
+            </div>
+
+            {#if user}
+                <Card>
+                    <dl class="flex flex-col gap-4">
+                        <div>
+                            <dt class="text-caption text-text-secondary">表示名</dt>
+                            <dd class="mt-1 text-body text-text" data-testid="capture-account-name">
+                                {user.name}
+                            </dd>
+                        </div>
+                        <div>
+                            <dt class="text-caption text-text-secondary">
+                                ログイン ID (メールアドレス)
+                            </dt>
+                            <!-- 識別子は省略しない: truncate を使わず break-all で折り返す
+                                 (親幅を超えて横スクロールを作らない)。DESIGN.md の役割マッピングに
+                                 従い主要な識別値なので text-body (text-caption ではない)。 -->
+                            <dd
+                                class="mt-1 text-body break-all text-text"
+                                data-testid="capture-account-email"
+                            >
+                                {user.email}
+                            </dd>
+                        </div>
+                        {#if organizationName}
+                            <div>
+                                <dt class="text-caption text-text-secondary">所属組織</dt>
+                                <dd
+                                    class="mt-1 text-body break-all text-text"
+                                    data-testid="capture-account-organization"
+                                >
+                                    {organizationName}
+                                </dd>
+                            </div>
+                        {/if}
+                    </dl>
+                </Card>
+
+                <p class="mt-3 text-caption text-text-secondary">
+                    表示名・メールアドレスの変更は PC の個人設定から行います。
+                </p>
+
+                <div class="mt-6">
+                    <Button
+                        variant="danger-outline"
+                        fullWidth
+                        loading={loggingOut}
+                        onclick={logout}
+                        testId="capture-account-logout"
+                    >
+                        <LogOut class="size-4" aria-hidden="true" />
+                        ログアウト
+                    </Button>
+                </div>
+            {/if}
+        </PageContent>
+    </PageContainer>
+</AppLayout>
diff --git a/resources/js/pages/Capture/Index.svelte b/resources/js/pages/Capture/Index.svelte
index bf4fd1e..1c97476 100644
--- a/resources/js/pages/Capture/Index.svelte
+++ b/resources/js/pages/Capture/Index.svelte
@@ -1,14 +1,15 @@
 <script lang="ts">
     import { page, router } from "@inertiajs/svelte";
-    import { Camera, Search } from "@lucide/svelte";
+    import { Camera, Search, UserRound } from "@lucide/svelte";
     import Badge from "@/components/atoms/Badge.svelte";
     import Card from "@/components/atoms/Card.svelte";
     import Checkbox from "@/components/atoms/Checkbox.svelte";
     import Input from "@/components/atoms/Input.svelte";
     import Select from "@/components/atoms/Select.svelte";
+    import TextLink from "@/components/atoms/TextLink.svelte";
     import ManualCoverThumbnail from "@/components/features/capture/ManualCoverThumbnail.svelte";
     import EmptyState from "@/components/molecules/EmptyState.svelte";
-    import PageHeader from "@/components/molecules/PageHeader.svelte";
+    import PageHeaderSection from "@/components/molecules/PageHeaderSection.svelte";
     import AppLayout from "@/components/templates/AppLayout.svelte";
     import PageContainer from "@/components/templates/PageContainer.svelte";
     import PageContent from "@/components/templates/PageContent.svelte";
@@ -69,12 +70,21 @@
 
 <AppLayout {appName}>
     <PageContainer>
-        <PageHeader
+        <!-- actions を持つため PageHeader (shorthand) ではなく PageHeaderSection を使う。
+             アカウント確認導線は**この一覧画面にだけ**置く (Capture/Show には置かない —
+             既に「一覧へ戻る」「マニュアル詳細へ」の 2 本があり狭幅で 3 本目が折り返す。
+             撮影中にアカウントを確かめる場面は想定しない)。 -->
+        <PageHeaderSection
             title="撮影するマニュアルを選ぶ"
             description={project.name}
             icon={Camera}
             testId="capture-heading"
-        />
+        >
+            <TextLink href="/app/account" testId="capture-account-link">
+                <UserRound class="inline size-3" aria-hidden="true" />
+                アカウント
+            </TextLink>
+        </PageHeaderSection>
         <PageContent>
             <div class="flex flex-col gap-2 sm:flex-row">
                 <form
diff --git a/routes/web.php b/routes/web.php
index edd343d..0feb976 100644
--- a/routes/web.php
+++ b/routes/web.php
@@ -8,6 +8,7 @@
 use App\Http\Controllers\Auth\SocialAuthController;
 use App\Http\Controllers\Billing\BillingController;
 use App\Http\Controllers\Billing\TicketPurchaseController;
+use App\Http\Controllers\Capture\CaptureAccountController;
 use App\Http\Controllers\Capture\CaptureManualController;
 use App\Http\Controllers\Capture\CaptureTakeController;
 use App\Http\Controllers\Capture\TakeUploadUrlController;
@@ -613,6 +614,16 @@
             // XSRF-TOKEN cookie が更新される。204 = 仕様固定 endpoint、body なし)
             Route::get('/csrf-cookie', fn (): Response => response()->noContent())
                 ->name('csrf-cookie');
+            /*
+            | 撮影 PWA のアカウント確認画面 (doc/05 §5.1 / §5.2)。表示名・ログイン ID
+            | (= メールアドレス)・所属組織を省略なく読み、ログアウトするためだけの面。
+            | **route parameter を持たない** — project のデータを 1 つも表示しないため、
+            | project 配下 (/app/projects/{project}/account) には置かない
+            | (親を持たせると nested route IDOR 目録と scopeBindings を負うだけで意味も歪む)。
+            | 復路は capture.home 1 本 (start_url と同じ)。return_to / history.back() は使わない。
+            | 変更操作は一切持たない (プロフィール変更・パスワード・2FA・退会は /settings の責務)。
+            */
+            Route::get('/account', CaptureAccountController::class)->name('account');
             Route::get('/projects/{project}/manuals', [CaptureManualController::class, 'index'])
                 ->name('manuals.index');
             Route::scopeBindings()->group(function (): void {
diff --git a/tests/Feature/Capture/CaptureAccountScreenTest.php b/tests/Feature/Capture/CaptureAccountScreenTest.php
new file mode 100644
index 0000000..cad9bf2
--- /dev/null
+++ b/tests/Feature/Capture/CaptureAccountScreenTest.php
@@ -0,0 +1,88 @@
+<?php
+
+declare(strict_types=1);
+
+use App\Enums\ProjectRole;
+use App\Models\Project;
+use App\Models\User;
+use App\Support\Seo\SeoTitle;
+use Inertia\Testing\AssertableInertia as Assert;
+
+/*
+ * 撮影 PWA のアカウント確認画面 (doc/05 §5.1 / §5.2)。
+ * 到達条件 (current org 所属 = 200 / 未設定・非所属 = 認可より前に 404) と、
+ * 撮影者ロールでも 200 になることを固定する。
+ * 表示値は共有 props (auth.user / currentOrganization) なので、
+ * 「画面がその共有 props を伴って返る」ことまでをサーバ側の契約とする。
+ */
+
+test('current org 所属なら 200 で Capture/Account を返す', function (): void {
+    [$organization, $owner] = createOrganizationWithOwner();
+
+    $this->actingAs($owner)->get('/app/account')
+        ->assertOk()
+        ->assertInertia(fn (Assert $page) => $page
+            ->component('Capture/Account')
+            ->where('auth.user.email', $owner->email)
+            ->where('auth.user.name', $owner->name)
+            ->where('currentOrganization.name', $organization->name)
+        );
+});
+
+/*
+ * この route は project 非依存なので、到達条件は「current org に在籍していること」だけである。
+ * project role (撮影者 = project_member) は**この route の認可条件ではない**。
+ * それでも撮影者を作るのは、現場で実際にこの画面へ来る人物像で 200 を確かめるためである。
+ */
+test('組織メンバー (撮影者ロールの利用者) でも 200 — project role は条件ではない', function (): void {
+    [$organization] = createOrganizationWithOwner();
+    $project = Project::factory()->forOrganization($organization)->create();
+    $member = attachOrganizationMember($organization);
+    // attachOrganizationMember は current_organization_id を設定しない
+    // (既存 TakeUploadUrlTest と同じ手順で明示代入する)
+    $member->forceFill(['current_organization_id' => $organization->id])->save();
+    attachProjectMember($project, $member, ProjectRole::Member);
+
+    $this->actingAs($member)->get('/app/account')
+        ->assertOk()
+        ->assertInertia(fn (Assert $page) => $page->component('Capture/Account'));
+});
+
+test('current org 未設定なら 404 (組織の有無を露出しない)', function (): void {
+    $user = User::factory()->create(); // 組織に属さない
+
+    $this->actingAs($user)->get('/app/account')->assertNotFound();
+});
+
+test('current org に非所属なら 404 (認可より前)', function (): void {
+    [$organization] = createOrganizationWithOwner();
+    [, $stranger] = createOrganizationWithOwner('別組織');
+
+    // 他組織の owner の current org を、**非所属の**組織に向ける
+    // (current_organization_id が退会後も残存する不整合を模す)
+    $stranger->forceFill(['current_organization_id' => $organization->id])->save();
+
+    // 前提: stranger は $organization に在籍していない (この前提が崩れるとテストが空振りする)
+    expect($organization->users()->whereKey($stranger->getKey())->exists())->toBeFalse();
+
+    $this->actingAs($stranger)->get('/app/account')->assertNotFound();
+});
+
+test('未認証はログインへ redirect する', function (): void {
+    $this->get('/app/account')->assertRedirect('/login');
+});
+
+/*
+ * タブ名は共有端末で「今どの画面を開いているか」の唯一の識別子になる (capture.manuals.index と
+ * 同じ理由)。静的名なので config('seo.app_titles') が正本で、controller は何も供給しない。
+ * ここが空欄に戻るとサイト名だけのタイトルになり、bug-hunt 目録の画面名も空になる。
+ */
+test('タブ名は config の静的名から解決される', function (): void {
+    [, $owner] = createOrganizationWithOwner();
+
+    $this->actingAs($owner)->get('/app/account')
+        ->assertOk()
+        ->assertInertia(fn (Assert $page) => $page
+            ->where('title', SeoTitle::compose('アカウント'))
+        );
+});
diff --git a/tests/js/architecture/logout-call-site-inventory.test.ts b/tests/js/architecture/logout-call-site-inventory.test.ts
index eb63d8e..ec0d36b 100644
--- a/tests/js/architecture/logout-call-site-inventory.test.ts
+++ b/tests/js/architecture/logout-call-site-inventory.test.ts
@@ -21,17 +21,21 @@ const JS_ROOT = path.resolve(__dirname, "../../../resources/js");
 
 /**
  * `/logout` を参照してよいファイル (resources/js からの相対パス)。
- * 現状 3 箇所あり、いずれも router.post = Inertia visit
+ * 現状 4 箇所あり、いずれも router.post = Inertia visit
  * (AppLayout: 通常画面のユーザーメニュー / VerifyEmail: メール認証待ち画面の離脱導線 /
  *  RecentAuthRecoveryNotice: 再認証手段が無いユーザーの回復導線 = ログアウトして guest として
  *  パスワードを再設定する。/forgot-password は guest middleware 付きで直リンクできない。
  *  全画面 confirm (pages/Auth/ConfirmRecentAuth) とインラインモーダル
- *  (organisms/RecentAuthModal) の双方が本 molecule を使う)。
+ *  (organisms/RecentAuthModal) の双方が本 molecule を使う /
+ *  Capture/Account: 撮影 PWA のアカウント確認画面。共有端末の引き渡し時に
+ *  「自分のアカウントか確認してログアウトする」だけを行う面で、doc/05 §5.2 が要求する
+ *  ログアウトをこの画面自身が持つ)。
  */
 const LOGOUT_CALL_SITE_INVENTORY: readonly string[] = [
   "components/templates/AppLayout.svelte",
   "pages/Auth/VerifyEmail.svelte",
   "components/molecules/RecentAuthRecoveryNotice.svelte",
+  "pages/Capture/Account.svelte",
 ] as const;
 
 const LOGOUT_PATH_PATTERN = /["'`]\/logout["'`]/;
diff --git a/tests/js/pages/CaptureAccount.test.ts b/tests/js/pages/CaptureAccount.test.ts
new file mode 100644
index 0000000..e5722a0
--- /dev/null
+++ b/tests/js/pages/CaptureAccount.test.ts
@@ -0,0 +1,146 @@
+import { afterEach, describe, expect, it, vi } from "vitest";
+import { cleanup, fireEvent, render, screen } from "@testing-library/svelte";
+
+/*
+ * 撮影 PWA アカウント確認画面 Capture/Account。
+ * - 表示名 / ログイン ID (メール) / 所属組織を共有 props から描画する
+ * - メールは truncate せず break-all で全文が DOM に載る (省略した識別子では確認にならない)
+ * - auth.user.id は描画に使わない (内部主キー。props には存在するが画面には出さない)
+ * - ログアウトは router.post("/logout") = Inertia visit (経路 C の保証条件)
+ * - ログアウト送信中はボタンが押下不可になる (必須条件未充足の disabled ではない)
+ * - 復路リンクは /app (capture.home)
+ *
+ * mock 方式は既存 tests/js/pages/SettingsIndex.test.ts と同一
+ * (vi.hoisted で plain object を作り vi.mock で page / router を差し替える)。
+ */
+
+const { pageState, routerPostMock } = vi.hoisted(() => ({
+    pageState: { props: {} as Record<string, unknown>, url: "/app/account" },
+    routerPostMock: vi.fn(),
+}));
+
+vi.mock("@inertiajs/svelte", async (importOriginal) => ({
+    ...(await importOriginal<typeof import("@inertiajs/svelte")>()),
+    router: { post: routerPostMock },
+    page: pageState,
+}));
+
+// eslint-disable-next-line import/first
+import CaptureAccount from "@/pages/Capture/Account.svelte";
+
+function seed(overrides: Record<string, unknown> = {}): void {
+    pageState.props = {
+        appName: "AI-CUE",
+        auth: {
+            user: {
+                // 他の表示値 (組織名・アプリ名・メール) と絶対に衝突しない値にする。
+                // 「描画に使っていない」を container.textContent の非包含で確かめるため、
+                // 短い数値 (42 等) だと偶然一致して偽陽性になる。
+                id: 987654321,
+                name: "撮影 太郎",
+                email: "shooting.taro.very.long.local.part@example.co.jp",
+                emailVerified: true,
+                twoFactorEnabled: false,
+            },
+        },
+        currentOrganization: {
+            id: 1,
+            name: "サンプル組織",
+            slug: "sample",
+            role: "organization_member",
+            canManageMembers: false,
+            canManageApiKeys: false,
+        },
+        ...overrides,
+    };
+}
+
+describe("Capture/Account", () => {
+    afterEach(() => {
+        cleanup();
+        vi.clearAllMocks();
+    });
+
+    it("表示名・ログイン ID・所属組織を描画する", () => {
+        seed();
+        render(CaptureAccount);
+
+        expect(screen.getByTestId("capture-account-name")).toHaveTextContent("撮影 太郎");
+        expect(screen.getByTestId("capture-account-email")).toHaveTextContent(
+            "shooting.taro.very.long.local.part@example.co.jp",
+        );
+        expect(screen.getByTestId("capture-account-organization")).toHaveTextContent(
+            "サンプル組織",
+        );
+    });
+
+    it("メールは truncate せず break-all で全文を出す", () => {
+        seed();
+        render(CaptureAccount);
+
+        const email = screen.getByTestId("capture-account-email");
+        expect(email.className).toContain("break-all");
+        expect(email.className).not.toContain("truncate");
+    });
+
+    it("auth.user.id を描画に使わない", () => {
+        seed();
+        const { container } = render(CaptureAccount);
+
+        expect(container.textContent).not.toContain("987654321");
+    });
+
+    /*
+     * Inertia の Link は jsdom で href を絶対 URL に解決する (http://localhost:3000/app) ため、
+     * 完全一致ではなく末尾一致で固定する (既存 SettingsIndex.test.ts と同じ様式)。
+     */
+    it("復路リンクは /app (capture.home)", () => {
+        seed();
+        render(CaptureAccount);
+
+        expect(screen.getByTestId("capture-account-back").getAttribute("href")).toMatch(
+            /\/app$/,
+        );
+    });
+
+    it("ログアウトは router.post('/logout') を呼ぶ", async () => {
+        seed();
+        render(CaptureAccount);
+
+        await fireEvent.click(screen.getByTestId("capture-account-logout"));
+
+        expect(routerPostMock).toHaveBeenCalledTimes(1);
+        expect(routerPostMock.mock.calls[0][0]).toBe("/logout");
+    });
+
+    /*
+     * 固定するのは **Button atom の loading 契約** (disabled={disabled || loading}) である。
+     * Svelte 側の `if (loggingOut) return;` は DOM 経由では到達しない多重防御なので
+     * ここでは固定しない (到達不能な経路のテストを作らない)。
+     */
+    it("ログアウト送信中はボタンが押下不可になる", async () => {
+        seed();
+        // onStart を呼んで loggingOut=true にしたまま応答を返さない (送信中を再現する)
+        routerPostMock.mockImplementation(
+            (_url: string, _data: unknown, options: { onStart?: () => void }) => {
+                options.onStart?.();
+            },
+        );
+        render(CaptureAccount);
+
+        const button = screen.getByTestId("capture-account-logout");
+        await fireEvent.click(button);
+
+        expect(button).toBeDisabled();
+
+        await fireEvent.click(button);
+        expect(routerPostMock).toHaveBeenCalledTimes(1);
+    });
+
+    it("currentOrganization が null なら組織行を出さない (偽の既定値を作らない = 補助テスト)", () => {
+        seed({ currentOrganization: null });
+        render(CaptureAccount);
+
+        expect(screen.queryByTestId("capture-account-organization")).toBeNull();
+    });
+});
diff --git a/tests/js/pages/CaptureIndex.test.ts b/tests/js/pages/CaptureIndex.test.ts
index ce7d7a6..acaaa51 100644
--- a/tests/js/pages/CaptureIndex.test.ts
+++ b/tests/js/pages/CaptureIndex.test.ts
@@ -116,6 +116,19 @@ describe("Capture/Index 自作フィルタ・作成者表示", () => {
         );
     });
 
+    /*
+     * T199: 撮影 PWA からアカウント確認画面への入口。共有端末で「今このアプリに
+     * ログインしているのは誰か」を確かめる導線がここにしか無いので、リンク先を固定する。
+     */
+    it("見出しにアカウント確認画面への導線がある", () => {
+        render(CaptureIndex, { props: baseProps });
+
+        // Inertia の Link は jsdom で href を絶対 URL に解決するため末尾一致で固定する
+        expect(screen.getByTestId("capture-account-link").getAttribute("href")).toMatch(
+            /\/app\/account$/,
+        );
+    });
+
     it("cover が null ならプレースホルダを描く", () => {
         render(CaptureIndex, { props: baseProps });
 

```

---

## 実装時に設計から逸脱した点 (レビュー対象)

1. **タイトルの供給元**: 設計 (施策 2) は controller で `SeoManager::setPrivateTitle('アカウント')` を
   呼ぶ形だったが、実装では `config('seo.app_titles')` に `'capture.account' => 'アカウント'` を
   置き、controller から SeoManager を外した。根拠は現行コードの `SeoManager::setPrivateTitle()` の
   docblock (「**動的な固有名** (プロジェクト名等) を controller から供給する用途」) と、
   bug-hunt 目録の画面名カラムが `config('seo.app_titles')` を読む実装
   (`app/Console/Commands/Bughunt/InventoryScanCommand.php`) であること。
   静的名を setPrivateTitle に置くと目録の画面名が空欄 (`-`) になる。
   併せて Feature テストに「タブ名が config の静的名から解決される」1 ケースを足した。
2. **リンク href の assert 方法**: 設計のテストは `toHaveAttribute("href", "/app")` の完全一致
   だったが、Inertia の `Link` は jsdom で href を絶対 URL (`http://localhost:3000/app`) に
   解決するため、既存 `SettingsIndex.test.ts` と同じ末尾一致 (`toMatch(/\/app$/)`) に変えた。

---

## テスト結果

- `composer test`: 5526 tests / 5524 passed / 0 failed / 2 skipped (24009 assertions)
- `composer phpstan`: No errors (level 10, 972 files)
- `vendor/bin/pint --test`: passed
- `pnpm lint` / `pnpm typecheck`: passed
- `pnpm test`: 159 files / 1952 tests passed
- `pnpm build`: built
- `pnpm typecheck:packages` / `pnpm build:packages`: passed
- `pnpm test:packages`: 10 files / 106 tests passed
- `scripts/bug-hunt-inventory-check.sh`: exit 0 (一致: 画面 71 件 / 操作 79 件)
