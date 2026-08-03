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

あなたは経験豊富なWebアプリケーションアーキテクトです。Laravel + Svelte アプリケーション改善の詳細設計をレビューしてください。

【前提環境】
- PHP 8.4 + Laravel 12 + Svelte 5 + Inertia.js + TypeScript
- PHPStan level 10 (解析対象は app / config / database / routes。tests は対象外)
- Pestテストフレームワーク
- DTO + JsonResource パターン
- Laratrust RBAC（Organization → Team → Project階層）
- inertiajs/inertia-laravel v3.1.0 / @inertiajs/svelte 3.3.1 / @inertiajs/core 3.3.1 / laravel/fortify

【レビュー観点】
1. コードの正確性（ロジックエラー、エッジケース、null安全性）
2. 既存コードとの整合性（命名規約、パターン、API）
3. PHPStan level 10 適合性（型安全性、generics、Assert使用）
4. テスト計画の網羅性（各施策にPestテスト、RefreshDatabaseグローバル適用に従う）
5. DTO/JsonResource パターンの遵守
6. Inertia Props vs API Responseの使い分け
7. 副作用・後退リスク
8. 波及変更の網羅性（TypeScript型定義、API Resource、テストが変更対象に含まれているか）
9. セキュリティ（認可チェック、入力バリデーション、OWASP Top 10、AGENTS.md のセキュリティ不変条件）
10. DESIGN.md準拠（UI/frontend 変更を含む場合）
11. Atomic Design準拠（UI/frontend 変更を含む場合）

【本件固有に特に見てほしい点】
- Inertia の history 暗号化 + clearHistory で「ログアウト後の popstate 復元で PII を描画させない」が
  本当に成立するか（クライアント実装の挙動理解に誤りがないか）。
- `Inertia::clearHistory()` を Fortify の LogoutResponse::toResponse() で呼ぶタイミングが正しいか
  （session invalidate との順序、着地の Inertia 応答での pull）。
- 既存の bfcache guard (経路 B) と新機構 (経路 C) の共存に競合・二重実装がないか。
- Browser テストの「正のコントロール」が空振り green を本当に防げるか。
- 見落としている後退リスク（既存テスト・既存挙動を壊す箇所）。

【出力形式】
- 各施策ごとに判定: APPROVE / REQUEST_CHANGES
- 指摘は [Critical] [Warning] [Suggestion] で分類
- Critical/Warning には必ず修正案を添える
- 全体判定: APPROVED / CHANGES_REQUESTED
- 日本語で出力

なお対象リポジトリは /workspace で、事実確認のためのファイル読み込みは許可されている。
---
## 詳細設計書

# 詳細設計: logout-history-pii-guard (F-4-01)

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
5. LLM 呼び出しの Prism 直呼び(`app/Prompts/` の factory 経由のみ。PromptGuardrailTest が検出)
6. prompt 文字列のコード直書き(`resources/prompts/*.yaml` に置く)
7. 操作系 POST の応答での `redirect()->intended()`(ログイン直後フロー専用。招待送信等は `back()->with(...)` で完結させる)
8. 必須条件未充足を理由にボタンを disabled にする UI(押下時にエラー表示する。DESIGN.md)
9. Artifact の使用(Artifact ツールでの成果物公開を行わない。成果物はリポジトリ内のファイルとして出力する)

### コーディングルール

- **PHPStan level 10** 必須（`composer phpstan`。解析対象は `app` / `config` / `database` / `routes`）
- **Pest** テストフレームワーク（`composer test`）
- **RefreshDatabase** + `--parallel` 並列実行（`tests/Pest.php` でグローバル適用、個別 `DatabaseTransactions` 使用禁止）
- **テストデータは必ず Factory で生成**（`Model::create()` 手組み禁止。本件は `createOrganizationWithOwner()` を使う）
- **DTO + JsonResource** パターン（本件は Inertia / RedirectResponse のみで新規 DTO なし）
- **アーリーリターン** 推奨
- **コードフォーマット**: `composer fix`（Pint）/ `pnpm lint:fix`
- PHP 8.4 + Laravel 12 + Svelte 5 + Inertia.js + TypeScript
- `declare(strict_types=1)` + 日本語コメント

## 概念設計リファレンス

`devnotes/20260804-0021-logout-history-pii-guard/conceptual-design.md`（Codex 概念設計レビュー Round 4 で APPROVED）

保証範囲の主語（概念設計で固定済み）:
**「Inertia が描画する認証済み画面」×「ログアウトを実行したタブ」×「`popstate` による履歴復元」**。

## 依拠する外部実装の事実（実コードで裏取り済み）

| 事実 | 出典 |
|---|---|
| `Inertia\Middleware\EncryptHistory` は `Inertia\EncryptHistoryMiddleware` の空サブクラス。`handle()` は `Inertia::encryptHistory()` を呼んで `$next` へ渡すだけ | `vendor/inertiajs/inertia-laravel/src/Middleware/EncryptHistory.php` / `src/EncryptHistoryMiddleware.php` |
| `Inertia\Response::__construct` が `session()->pull('inertia.clear_history', false)` を行い、`toResponse()` が page に `clearHistory: true` / `encryptHistory: true` を載せる | `vendor/inertiajs/inertia-laravel/src/Response.php` L111 / L182-231 |
| `ResponseFactory::clearHistory()` は `session(['inertia.clear_history' => true])` のみ | `vendor/inertiajs/inertia-laravel/src/ResponseFactory.php` L180-184 |
| `encryptHistory` 既定値は `config('inertia.history.encrypt', false)`。本リポジトリに `config/inertia.php` は無いので既定 false | `ResponseFactory.php` L378 / `ls config/` |
| クライアントは `page.set()` の冒頭で `page.clearHistory` を見て `history.clear()` = `sessionStorage` の `historyKey` / `historyIv` を削除 | `@inertiajs/core` 3.3.1 `src/page.ts` L78-80 / `src/history.ts` L294-297 |
| `popstate` で `history.decrypt(state.page)` が reject すると `onMissingHistoryItem()` → `router.visit(location.href, { preserveState: true, preserveScroll: true, replace: true })`。**復号失敗時はコンポーネント swap を行わない** | `src/eventHandler.ts` L81-131 / `src/router.ts` L86-90 |
| 暗号化は `window.crypto.subtle` (AES-GCM)。`subtle` が無い環境では `console.warn` の上、平文をそのまま history に載せる | `src/encryption.ts` L37-62 |
| Fortify の `AuthenticatedSessionController::destroy()` は `guard->logout()` → `session()->invalidate()` → `session()->regenerateToken()` の**後**に `app(LogoutResponse::class)` を返す（`toResponse()` はさらに後、Router の Responsable 解決時に走る） | `vendor/laravel/fortify/src/Http/Controllers/AuthenticatedSessionController.php` L100-110 |
| `Inertia` ファサードは `@method static void clearHistory()` を宣言済み（PHPStan/larastan で解決できる） | `vendor/inertiajs/inertia-laravel/src/Inertia.php` L16 |

## 施策一覧

| # | 施策名 | 変更ファイル | 優先度 |
|---|--------|------------|--------|
| 1 | Inertia history 暗号化を web グループで有効化 | `bootstrap/app.php` | Critical |
| 2 | ログアウト応答で history 鍵を破棄する | `app/Http/Responses/Fortify/LogoutResponse.php`(新規) / `app/Providers/FortifyServiceProvider.php` | Critical |
| 3 | Feature テスト（page ペイロード契約の固定） | `tests/Feature/Security/InertiaHistoryGuardTest.php`(新規) | Critical |
| 4 | Browser テスト（F-4-01 再現手順の恒久回帰） | `tests/Browser/InertiaHistoryRestoreAfterLogoutTest.php`(新規) | Critical |
| 5 | 経路 B/C の責務分担を既存コードのコメントに反映 | `resources/js/lib/bfcache-guard.ts` / `app/Http/Middleware/NoStoreCacheHeadersForAuthenticatedPages.php` | High |
| 6 | 契約文書の更新（保証範囲と残存リスク） | `docs/supported-browsers.md` / `docs/testing-browser.md` / `AGENTS.md` | High |

---

## 施策 1: Inertia history 暗号化を web グループで有効化

### 変更箇所
- ファイル: `bootstrap/app.php`（`$middleware->web(append: [...])` ブロック / 現行 L84-97 付近）
- import 追加: `use Inertia\Middleware\EncryptHistory;`

### 波及変更
- TypeScript 型定義: なし（Inertia 側の page 契約はクライアントライブラリが解釈する）
- API Resource/DTO: なし
- テストファイル: 施策 3・4 で新規追加。既存の `tests/Browser/AuthenticatedPageBfcacheTest.php` は
  `pagehide` / `pageshow` を合成発火するが、Inertia 側の `pageshow` ハンドラは
  `history.decrypt()` に成功する（鍵は生存）ため `onMissingHistoryItem` に落ちず、既存期待は不変。

### 現行コード

```php
        $middleware->web(append: [
            HandleInertiaRequests::class,
            SecurityHeaders::class,
            // 組織単位 2FA 強制: (1) 未準拠ユーザーの全画面ゲート → (2) 準拠ユーザーの
            // self-disable 禁止、の順 (disable route はゲートの allowlist 外のため、
            // 未準拠者の disable は (1) が先に弾く)
            RequireTwoFactorForEnforcedOrganizations::class,
            BlockTwoFactorDisableForEnforcedOrganizations::class,
            // 認証済み応答の no-store baseline。
            // 契約: $next から返った (= 下流の) 応答を確認し、既に `no-store` を持つなら変更しない。
            // (位置関係ではなくこの契約が正本。実効性は Feature テストが固定する)
            NoStoreCacheHeadersForAuthenticatedPages::class,
        ]);
```

### 変更後コード

```php
        $middleware->web(append: [
            HandleInertiaRequests::class,
            SecurityHeaders::class,
            // 組織単位 2FA 強制: (1) 未準拠ユーザーの全画面ゲート → (2) 準拠ユーザーの
            // self-disable 禁止、の順 (disable route はゲートの allowlist 外のため、
            // 未準拠者の disable は (1) が先に弾く)
            RequireTwoFactorForEnforcedOrganizations::class,
            BlockTwoFactorDisableForEnforcedOrganizations::class,
            // 認証済み応答の no-store baseline。
            // 契約: $next から返った (= 下流の) 応答を確認し、既に `no-store` を持つなら変更しない。
            // (位置関係ではなくこの契約が正本。実効性は Feature テストが固定する)
            NoStoreCacheHeadersForAuthenticatedPages::class,
            // Inertia の履歴 state を AES-GCM で暗号化する (Inertia 公式のグローバル適用手順)。
            // ログアウト時に LogoutResponse が Inertia::clearHistory() で鍵を捨てるため、
            // ログアウト後の「戻る」は復号に失敗し、**コンポーネントを描画しないまま**
            // サーバへ再問い合わせ → /login に倒れる (bug-hunt F-4-01)。
            //
            // Inertia 面の認証済み画面が復元されうる経路と担当 (docs/supported-browsers.md が正本):
            //   A: HTTP/disk/proxy cache + Chrome/Firefox の bfcache → NoStoreCacheHeaders...
            //   B: Safari の真の bfcache (pagehide/pageshow)        → resources/js/lib/bfcache-guard.ts
            //   C: Inertia SPA の history 復元 (popstate)           → 本 middleware + Inertia::clearHistory()
            //
            // 認証済み route への限定適用にしない: 認証済み route は ['auth','verified'] グループの
            // 外にも複数あり (招待受諾 POST 等)、限定適用は inventory ドリフトを生む。
            // 公開ページの履歴も暗号化されるが PII は無く、コストはログアウト前エントリの
            // 再取得と remember/scroll 喪失に限られる。
            EncryptHistory::class,
        ]);
```

### PHPStan 適合チェック
- [x] 戻り値の型が明示されている（`bootstrap/app.php` は既存クロージャの中身に 1 要素追加するのみ）
- [x] null 安全（該当なし）
- [x] DTO を返している（該当なし）
- [x] Generics の型パラメータが正しい（該当なし）

### テスト計画
- [x] 施策 3 の Feature テストで「認証済み / 公開の Inertia 応答に `encryptHistory: true` が載る」を固定
- [x] 施策 4 の Browser テストで「実ブラウザの `window.history.state.page` が `ArrayBuffer` である」を
      正のコントロールとして固定（`crypto.subtle` が使えない環境で空振り green にならない）

### リスク
- 非セキュアコンテキストでは `crypto.subtle` が無く平文 fallback（`console.warn`）。
  撮影 PWA は `getUserMedia` / Service Worker のためセキュアコンテキスト必須であり、
  degrade するのは中核機能が既に動かない環境に限られる。文書に明記する（施策 6）。
- `history.pushState` の payload が ArrayBuffer になるため、`QuotaExceededError` の可能性は
  平文時とほぼ同等（Inertia 側に `historyQuotaExceeded` → full reload のフォールバックが既にある）。
- 公開ページ (SEO stateless block) は `HandleInertiaRequests` を `withoutMiddleware` しているが、
  `EncryptHistory` は Inertia 応答を返さない route では**何の副作用も持たない**
  （ResponseFactory のフラグを立てるだけ）。

---

## 施策 2: ログアウト応答で history 鍵を破棄する

### 変更箇所
- 新規ファイル: `app/Http/Responses/Fortify/LogoutResponse.php`
- 変更: `app/Providers/FortifyServiceProvider.php`
  - `use App\Http\Responses\Fortify\LogoutResponse;`
  - `use Laravel\Fortify\Contracts\LogoutResponse as LogoutResponseContract;`
  - `register()` に `$this->app->singleton(LogoutResponseContract::class, LogoutResponse::class);`

### 波及変更
- TypeScript 型定義: なし
- API Resource/DTO: なし（`RedirectResponse` / `JsonResponse` のみ。`response()->json()` 直書きはしない）
- テストファイル: 施策 3（Feature）/ 施策 4（Browser）

### 現行コード

`app/Http/Responses/Fortify/LogoutResponse.php` は存在しない（Fortify 既定
`Laravel\Fortify\Http\Responses\LogoutResponse` が使われる）:

```php
// vendor/laravel/fortify/src/Http/Responses/LogoutResponse.php
public function toResponse($request)
{
    return $request->wantsJson()
        ? new JsonResponse('', 204)
        : redirect(Fortify::redirects('logout', '/'));
}
```

### 変更後コード

```php
<?php

declare(strict_types=1);

namespace App\Http\Responses\Fortify;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Laravel\Fortify\Contracts\LogoutResponse as LogoutResponseContract;

/**
 * ログアウト応答 (Fortify contract bind)。
 *
 * Fortify 既定との違いは 2 点:
 *
 * 1. **`Inertia::clearHistory()` を呼ぶ**。ログアウトは Inertia の SPA visit
 *    (`AppLayout.svelte` の `router.post('/logout')`) で完結し、以降の「戻る」も
 *    `popstate` で完結するため、サーバの no-store baseline も
 *    `bfcache-guard.ts` (pagehide/pageshow) も発火しない。Inertia のクライアント履歴に
 *    残った認証済みページが PII 込みで復元される (bug-hunt F-4-01)。
 *    `clearHistory()` は `sessionStorage` の暗号鍵を捨てさせ、履歴エントリを復号不能にする。
 *    復号に失敗した Inertia は**コンポーネントを描画しないまま**サーバへ再問い合わせし、
 *    未認証なので `/login` へ倒れる。暗号化の有効化は
 *    `bootstrap/app.php` の `Inertia\Middleware\EncryptHistory` (web グループ) が担う。
 *
 * 2. **着地を `route('home')` に固定する** (`Fortify::redirects('logout')` を経由しない)。
 *    `clearHistory` フラグは session に積まれ「**次の Inertia 応答**」でしか消費されない
 *    (`Inertia\Response::__construct` の `session()->pull`)。着地が非 Inertia 応答になると
 *    フラグが宙に浮き、防御が**静かに**消える。設定 1 つで壊れる経路を残さない。
 *    着地 `/` は `HomeController` = `Inertia::render('Welcome')`。
 *    **この route を非 Inertia 化してはならない** (契約。Feature テストが固定する)。
 *
 * `wantsJson()` の 204 分岐は Fortify 既定と同値のまま残す (Inertia visit は
 * `X-Inertia` + Accept: text/html のため常に redirect 側を通る)。
 * `clearHistory()` は両分岐の前に呼ぶ — 「このブラウザの履歴はもう無効」という事実は
 * クライアント種別に依らないため。
 *
 * 呼ばれる順序: `AuthenticatedSessionController::destroy()` が
 * `guard->logout()` → `session()->invalidate()` → `session()->regenerateToken()` を終えた**後**に
 * 本クラスが解決され、`toResponse()` はさらに後 (Router の Responsable 解決時) に走る。
 * よって `clearHistory()` の session 書き込みは invalidate 後の新しい session に載り、
 * 着地の Inertia 応答まで確実に届く。
 */
final class LogoutResponse implements LogoutResponseContract
{
    /**
     * @param  Request  $request
     */
    public function toResponse($request): JsonResponse|RedirectResponse
    {
        // 認証済みページの Inertia 履歴 (暗号化済み) を復号不能にする。
        Inertia::clearHistory();

        if ($request->wantsJson()) {
            return new JsonResponse('', 204);
        }

        return redirect()->route('home');
    }
}
```

`FortifyServiceProvider::register()` への追記（既存 bind 群の末尾付近）:

```php
        // ログアウト着地で Inertia::clearHistory() を発火させる (bug-hunt F-4-01)。
        // 着地 route を固定する理由と順序の前提は LogoutResponse の docblock を参照。
        $this->app->singleton(LogoutResponseContract::class, LogoutResponse::class);
```

### PHPStan 適合チェック
- [x] 戻り値の型が明示されている（`JsonResponse|RedirectResponse`。既存 `LoginResponse` と同形）
- [x] null 安全（`Assert` 不要。`route('home')` は名前付き route が存在することを Feature テストが固定）
- [x] DTO を返している（Fortify contract の性質上 Response を返す。`response()->json()` 直書きなし）
- [x] Generics の型パラメータが正しい（該当なし）

### テスト計画
- [x] 施策 3 の Feature テストで再現側から固定（`POST /logout` → 着地 `GET /` の page に `clearHistory: true`）
- [x] `wantsJson()` の 204 分岐が Fortify 既定と同値であることを回帰として固定
      （既存 `tests/Browser/AuthenticatedPageBfcacheTest.php` の `bfcacheLogoutInBrowser()` が
      `Accept: application/json` で `/logout` を叩いているため、崩すと既存テストが壊れる）

### リスク
- `config('fortify.redirects.logout')` を無視するようになる（現状未設定のため実挙動は不変）。
  将来ログアウト着地を変えたくなったら **本クラスを直す**（設定と実装の二重管理を作らない）。
- ログアウト後に `/` を非 Inertia 化すると防御が静かに消える → Feature テストで検出する。

---

## 施策 3: Feature テスト（page ペイロード契約の固定）

### 変更箇所
- 新規ファイル: `tests/Feature/Security/InertiaHistoryGuardTest.php`

### 波及変更
- なし（新規テストのみ）

### テスト内容（Pest）

```php
<?php

declare(strict_types=1);

use Illuminate\Testing\TestResponse;

/*
 * Inertia history guard (bug-hunt F-4-01) の契約検証。
 *
 * 契約:
 *  - web グループの Inertia\Middleware\EncryptHistory により、Inertia 応答の page に
 *    `encryptHistory: true` が載る (認証済み / 公開の区別なくグローバル適用)。
 *  - ログアウト応答は Inertia::clearHistory() を発火し、**着地の Inertia 応答**の page に
 *    `clearHistory: true` が載る (着地が非 Inertia 化するとフラグが宙に浮き防御が消える)。
 *  - 通常の応答には `clearHistory` が載らない (負のコントロール)。
 *
 * 目的は「ログアウト後の戻る」で Inertia のクライアント履歴から認証済み画面 (PII) が
 * 復元されるのを防ぐこと。経路 A (HTTP キャッシュ / bfcache evict) は NoStoreCacheHeadersTest、
 * 経路 B (Safari の真の bfcache) は tests/js/lib/bfcache-guard.test.ts が受け持つ。
 */

/** Inertia の root view から page オブジェクトを取り出す (Inertia 応答でなければ失敗させる)。 */
function inertiaPagePayload(TestResponse $response): array
{
    $page = $response->viewData('page');

    expect($page)->toBeArray('Inertia 応答ではない (clearHistory / encryptHistory を消費できない)');

    return $page;
}

test('認証済み Inertia 応答の page に encryptHistory が載る', function (): void {
    [, $owner] = createOrganizationWithOwner();

    $response = $this->actingAs($owner)->get('/dashboard');

    $response->assertOk();
    expect(inertiaPagePayload($response))->toHaveKey('encryptHistory', true);
});

test('公開ページの Inertia 応答にも encryptHistory が載る (グローバル適用)', function (): void {
    // 認証済み route への限定適用にしない設計判断をテストに刻む
    // (限定適用に変えるなら inventory と Architecture テストをセットで作ること)。
    $response = $this->get('/');

    $response->assertOk();
    expect(inertiaPagePayload($response))->toHaveKey('encryptHistory', true);
});

test('通常の応答には clearHistory が載らない (負のコントロール)', function (): void {
    [, $owner] = createOrganizationWithOwner();

    $response = $this->actingAs($owner)->get('/dashboard');

    expect(inertiaPagePayload($response))->not->toHaveKey('clearHistory');
});

test('ログアウトの着地 Inertia 応答に clearHistory が載る', function (): void {
    [, $owner] = createOrganizationWithOwner();

    $logout = $this->actingAs($owner)->post('/logout');
    $logout->assertRedirect(route('home'));

    $landing = $this->get($logout->headers->get('Location'));

    $landing->assertOk();
    // 着地が Inertia 応答でなければ inertiaPagePayload が失敗する = 契約違反を検出
    expect(inertiaPagePayload($landing))->toHaveKey('clearHistory', true);
    $this->assertGuest();
});

test('clearHistory は 1 度きりで、次の Inertia 応答には持ち越さない', function (): void {
    [, $owner] = createOrganizationWithOwner();

    $this->actingAs($owner)->post('/logout');
    $this->get(route('home'));

    // pull 済みなので 2 度目には載らない (無関係なページで履歴が飛ぶ事故を防ぐ)
    expect(inertiaPagePayload($this->get('/pricing')))->not->toHaveKey('clearHistory');
});

test('JSON クライアントのログアウトは 204 のまま (既定挙動の維持)', function (): void {
    [, $owner] = createOrganizationWithOwner();

    $this->actingAs($owner)
        ->postJson('/logout')
        ->assertNoContent();

    $this->assertGuest();
});
```

### PHPStan 適合チェック
- tests/ は PHPStan の解析対象外（`phpstan.neon` の paths は `app` / `config` / `database` / `routes`）。
  それでも `viewData('page')` の `mixed` は `expect()->toBeArray()` で明示的に絞る。

### テスト計画
- [x] バグ修正の再現テストを先に書く（**施策 4 の Browser テストが F-4-01 の再現そのもの**。
      施策 1・2 を入れる前に走らせて **fail することを確認**してから実装に入る = テストファースト）
- [x] 既存テストの更新: 不要（`NoStoreCacheHeadersTest` / `ExistingNoStoreContractTest` /
      `SessionStatusProbeTest` の契約は不変）
- [x] 新規テスト: 上記 6 本
- [x] 個別の `DatabaseTransactions` を使っていない

### リスク
- `viewData('page')` は root view 経由の応答にのみ存在する。`X-Inertia` ヘッダ付きの XHR では
  JSON になるため、本テストは**ヘッダを付けない**（= 実ブラウザの初回ロードと同じ経路）。

---

## 施策 4: Browser テスト（F-4-01 再現手順の恒久回帰）

### 変更箇所
- 新規ファイル: `tests/Browser/InertiaHistoryRestoreAfterLogoutTest.php`

### なぜ既存 `AuthenticatedPageBfcacheTest.php` に足さないか
- 経路 B（真の bfcache）と経路 C（Inertia SPA history）は**再現可否が正反対**。
  経路 B はハーネスが再現できず毎回 skip 判定が要るが、
  **経路 C は bfcache と無関係の Inertia 内部機構であり Chromium / WebKit の両レーンで再現する**
  （bug-hunt shard-4 が Chromium で実証済み）。skip 前提のファイルに混ぜると
  「担保されていない」ことの表明が薄まる。
- ファイルを分けることで、Pest のグローバル関数名の衝突も避ける。

### テスト内容（Pest / 両レーン）

```php
<?php

declare(strict_types=1);

use Pest\Browser\Api\PendingAwaitablePage;

/*
|--------------------------------------------------------------------------
| Inertia SPA の履歴復元に対する PII 秘匿 (bug-hunt F-4-01) の Browser E2E
|--------------------------------------------------------------------------
|
| bug-hunt shard-4 の再現手順そのもの:
|   ログイン → 認証済み画面 → SPA でログアウト → ブラウザバック
|   → 期待: 認証済み画面 (PII) が **一度も描画されず** /login に倒れる
|
| 経路の区別 (docs/supported-browsers.md が正本):
|   - 経路 B (Safari の真の bfcache) は本ハーネスでは再現できない
|     → tests/Browser/AuthenticatedPageBfcacheTest.php が skip 判定付きで扱う
|   - 経路 C (Inertia の popstate 履歴復元) は **bfcache とは無関係**の Inertia 内部機構であり、
|     Chromium / WebKit の両レーンで再現する → **skip しない。恒久回帰である**
|
| 正のコントロール (空振り green を作らない):
|   1. ログアウト前に window.history.state.page が ArrayBuffer であること
|      = history 暗号化が実際に効いている (crypto.subtle が無い環境なら平文になり fail)
|   2. 一連の操作の間 JS 実行コンテキストが生存していること
|      = 本当に same-document の SPA popstate であり、フルリロードで空振りしていない
|
| 実行: composer test:browser (Chromium / WebKit の両レーン)。
| 前提: pnpm build 済み。
*/

/** ブラウザ側の条件が満たされるまで待つ (plugin の assertion は auto-retry しない)。 */
function inertiaHistoryWaitUntil(
    PendingAwaitablePage $page,
    string $expression,
    string $message,
    int $attempts = 100,
): void {
    for ($i = 0; $i < $attempts; $i++) {
        if ($page->script("Boolean({$expression})") === true) {
            expect(true)->toBeTrue();

            return;
        }
        usleep(50_000);
    }

    throw new RuntimeException("条件が満たされませんでした: {$message} (式: {$expression})");
}

test('ログアウト後のブラウザバックで Inertia 履歴から PII が復元されない', function (): void {
    [, $owner] = createOrganizationWithOwner();
    $this->actingAs($owner);

    // bug-hunt F-4-01 の再現手順: /dashboard → /manage/users (SPA) → ログアウト → 戻る
    $page = visit('/dashboard');
    $page->assertSee($owner->name);
    $page->click('メンバー'); // サイドバーの Inertia link (SPA 遷移 = pushState エントリ生成)
    inertiaHistoryWaitUntil($page, "window.location.pathname === '/manage/users'", 'メンバーへ SPA 遷移しない');
    $page->assertSee($owner->name); // メンバー一覧に PII (氏名) が出ている

    // 正のコントロール 1: history 暗号化が実際に効いている
    inertiaHistoryWaitUntil(
        $page,
        'window.history.state?.page instanceof ArrayBuffer',
        'history state が暗号化されていない (EncryptHistory 未適用、または crypto.subtle 不在)',
    );

    // 正のコントロール 2: JS 実行コンテキストの生存マーカー (フルリロードで消える)
    $page->script("window.__inertiaHistoryProbe = 'alive'; true");

    // SPA でログアウト (AppLayout の router.post('/logout') = F-4-01 の再現手順)
    $page->click('@app-user-menu-toggle');
    $page->click('@logout-button');
    inertiaHistoryWaitUntil($page, "window.location.pathname === '/'", 'ログアウト後に LP へ着地しない');
    $page->assertScript('window.__inertiaHistoryProbe', 'alive'); // ここまで same-document

    // ブラウザバック = Inertia の popstate 履歴復元
    $page->back();

    // 復元直後に PII が描画されていないこと (復号失敗時は swap 自体が起きない)
    $page->assertDontSee($owner->name);

    inertiaHistoryWaitUntil(
        $page,
        "window.location.pathname === '/login'",
        'ログアウト後の戻るで /login に倒れない',
    );

    // popstate → 再問い合わせ → login まで same-document で完結している
    // (= 本当に SPA 履歴復元経路を通った。フルリロードなら消えている)
    $page->assertScript('window.__inertiaHistoryProbe', 'alive');
    $page->assertDontSee($owner->name)->assertNoJavaScriptErrors();
});

test('ログイン中の戻るは従来どおり client-side で完結する (誤発火しない)', function (): void {
    [, $owner] = createOrganizationWithOwner();
    $this->actingAs($owner);

    $page = visit('/dashboard');
    $page->assertSee($owner->name);
    $page->script("window.__inertiaHistoryProbe = 'alive'; true");

    $page->click('メンバー'); // サイドバーの Inertia link (SPA 遷移)
    inertiaHistoryWaitUntil($page, "window.location.pathname === '/manage/users'", 'メンバーへ SPA 遷移しない');

    $page->back();

    inertiaHistoryWaitUntil($page, "window.location.pathname === '/dashboard'", '戻るで dashboard に戻らない');
    // 復号に成功する = 再取得も hard reload も起きない (撮影 PWA の制約を壊さない)
    $page->assertScript('window.__inertiaHistoryProbe', 'alive');
    $page->assertSee($owner->name)->assertNoJavaScriptErrors();
});
```

> 実装時の注意: `click()` は `guessLocator()` 経由で `@testid` / リンクテキストを解決する。
> サイドバーのリンク文言「メンバー」は `AppLayout.svelte` の `navItems`
> (`{ href: "/manage/users", label: "メンバー" }`、`canManageMembers` でゲート) に実在する。
> 文言が変わった場合は **UI を変えるのではなくテスト側を実装に合わせる**こと。

### PHPStan 適合チェック
- tests/ は解析対象外。

### テスト計画
- [x] **バグ修正の再現テストを先に書く**: 1 本目は施策 1・2 の実装前に走らせて
      「`window.history.state.page` が ArrayBuffer でない」で fail することを確認する
      （現状は平文のため正のコントロールが先に落ちる）。実装後に green になる。
- [x] 2 本目は「ログイン中の戻るを壊していない」ことの負のコントロール（後退検出）
- [x] `--parallel` / `RefreshDatabase` は Browser lane の `tests/Pest.php` 配線に従う

### リスク
- WebKit レーンでの `crypto.subtle` 可用性: テストサーバは `127.0.0.1` (potentially trustworthy origin)
  のためセキュアコンテキスト扱いとなり `subtle` は利用可能。
  仮に利用できない環境が現れた場合、**正のコントロール 1 が fail する**ため
  「防御が効いていないのに green」にはならない（沈黙しない設計）。
- `@app-user-menu-toggle` / `@logout-button` は `AppLayout.svelte` に実在
  （L264 / L356）。testid を変えるときは本テストも同時に直す。

---

## 施策 5: 経路 B/C の責務分担を既存コードのコメントに反映

### 変更箇所
- `resources/js/lib/bfcache-guard.ts`（docblock 冒頭。**挙動は変更しない**）
- `app/Http/Middleware/NoStoreCacheHeadersForAuthenticatedPages.php`（docblock）

### 波及変更
- TypeScript 型定義: なし / API Resource: なし
- テストファイル: なし（既存 `tests/js/lib/bfcache-guard.test.ts` は不変）

### 変更内容

`bfcache-guard.ts` の docblock に「担当は経路 B のみ」であることを追記する:

```
 * **担当範囲**: 本 guard が守るのは **Safari の真の bfcache 復元 (pagehide/pageshow)** だけ。
 * Inertia SPA のクライアント履歴復元 (popstate) は本 guard を発火させないため、
 * そちらは Inertia 公式機構 (bootstrap/app.php の EncryptHistory +
 * LogoutResponse の Inertia::clearHistory()) が担当する (bug-hunt F-4-01)。
 * ここに popstate フックを足さないこと — 同一問題の二重実装になる。
 *
 * なお Inertia も pageshow(persisted) で history を復号し直すが、それは**非同期**であり、
 * 復元 DOM は既に描画されている。「検証完了まで秘匿する」という本 guard の要件は
 * pagehide で**同期的に**秘匿属性を立てる本実装でしか満たせない (公式機構に pagehide は無い)。
 * ログアウト後の真の bfcache 復元では両者が同時に走るが、着地はどちらも /login で一致する
 * (guard の hard navigation が Inertia の XHR 再訪問を打ち切るだけ)。
```

`NoStoreCacheHeadersForAuthenticatedPages` の docblock の
「クライアント側の bfcache 秘匿・再検証とセットで」の一文に、経路 C の担当を 1 行追記する
（3 枚の網の全体像は `docs/supported-browsers.md` が正本であることも明記）。

### PHPStan 適合チェック
- コメントのみ。挙動不変。

### テスト計画
- [x] 既存 `tests/js/lib/bfcache-guard.test.ts` が green のままであること（挙動を変えていない証明）

### リスク
- なし（コメントのみ）。ただし**挙動を変えないこと**が要件。
  `docs/supported-browsers.md` の「実機受入確認の再確認条件」は
  `bfcache-guard.ts` への変更をトリガにしているため、
  **docblock のみの変更は再確認トリガに当たらない**ことを施策 6 で明記する
  （さもないと不要な実機再確認を誘発する）。

---

## 施策 6: 契約文書の更新（保証範囲と残存リスク）

### 変更箇所

| ファイル | 変更内容 |
|---|---|
| `docs/supported-browsers.md` | 冒頭の前提を「2 枚のセット」から「**Inertia 面の 3 経路 × 3 枚の網**」へ。Current の表に経路 C の自動回帰（Chromium/WebKit 両レーンで再現、skip しない）を追加。未対応事項に残存リスク 4 件を分離記載。実機受入確認の再確認条件に「docblock のみの変更はトリガに当たらない」を明記 |
| `docs/testing-browser.md` | 「bfcache 復元は再現できない」節に、**Inertia SPA history 復元は両レーンで再現できる**（`tests/Browser/InertiaHistoryRestoreAfterLogoutTest.php` は skip しない恒久回帰）という差を追記 |
| `AGENTS.md` ドメイン固有規約 #3 | セット構成を 3 枚に更新。主語を「**Inertia が描画する認証済み画面**」に限定し、非 Inertia 面（Filament `/admin`）が対象外であることを明記 |

### `docs/supported-browsers.md` 冒頭の書き換え（案）

```markdown
AI-CUE が「どのブラウザで、どのレベルまで動作を保証しているか」の正本。

**Inertia が描画する認証済み画面**が「ログアウト後に復元される」経路は 3 本あり、
それぞれ担当が違う。本書はその保証範囲を語るための前提として置く
（Filament 管理パネル `/admin` は Inertia でも web グループでもないため本書の対象外）。

| 経路 | 担当 | 何を保証するか |
|------|------|----------------|
| A: HTTP / disk / proxy cache、Chrome・Firefox の bfcache | `NoStoreCacheHeadersForAuthenticatedPages` | `no-store, private` により格納拒否 / cookie 変更時 evict |
| B: Safari の真の bfcache (`pagehide` / `pageshow`) | `resources/js/lib/bfcache-guard.ts` + `session.status` プローブ | **描画前に同期秘匿**し、セッション有効なら秘匿解除のみ（hard reload しない） |
| C: Inertia SPA のクライアント履歴復元 (`popstate`) | `Inertia\Middleware\EncryptHistory`（web グループ）+ `LogoutResponse` の `Inertia::clearHistory()` | ログアウト後は復号不能 → **コンポーネントを描画しないまま**再問い合わせ → `/login` |
```

### `docs/supported-browsers.md` 未対応事項への追記（案）

```markdown
- **経路 C は「ログアウトを実行したタブ」のみを保証する**。Inertia の履歴暗号鍵は
  `sessionStorage` = タブ単位のため、同一ブラウザの**別タブ**に残った履歴は復号できてしまう。
  すなわち **別タブでは、現在表示されていない過去の PII が履歴から再表示され得る**
  （例: タブ B でメンバー一覧を見た後に公開ページへ遷移 → タブ A でログアウト →
  端末を引き継いだ第三者がタブ B で「戻る」）。塞ぐには全タブへのセッション失効伝播
  （BroadcastChannel 等）が要るため本件では扱わない。**既知の残存リスク**。
- **セッション期限切れ / 他デバイスからの強制ログアウトは経路 C の保証外**。
  ブラウザに `clearHistory` が届かないため鍵が残り、履歴は復号できる。
- **非 Inertia 面（Filament `/admin`）は経路 B / C の保証外**。
- **非セキュアコンテキスト（`http://` の LAN IP 等）では経路 C が degrade する**。
  `window.crypto.subtle` が無い環境で Inertia は履歴を平文で保存する（`console.warn` のみ）。
  撮影 PWA は `getUserMedia` / Service Worker のためセキュアコンテキスト必須であり、
  degrade するのは中核機能が既に動かない環境に限られる。
```

### `AGENTS.md` ドメイン固有規約 #3 の書き換え（案）

```markdown
3. **サポート対象ブラウザと履歴復元の扱い**: 「どのブラウザで何をどこまで保証しているか」の
   正本は **`docs/supported-browsers.md`**。**Inertia が描画する認証済み画面**が
   ログアウト後に復元される経路は 3 本あり、**3 枚セット**で守る
   (Filament `/admin` など非 Inertia 面は本規約の対象外):
   (A) サーバ no-store baseline (`NoStoreCacheHeadersForAuthenticatedPages`)、
   (B) クライアント bfcache 秘匿・再検証 (`resources/js/lib/bfcache-guard.ts` + `session.status` プローブ)、
   (C) Inertia history 暗号化 + ログアウト時の履歴鍵破棄
       (`bootstrap/app.php` の `Inertia\Middleware\EncryptHistory` +
        `App\Http\Responses\Fortify\LogoutResponse` の `Inertia::clearHistory()`)。
   (C) は着地が **Inertia 応答であること**に依存する — ログアウト着地 route を
   非 Inertia 化しない (`InertiaHistoryGuardTest` が固定)。
   (B) の guard / 秘匿スタイル / プローブ endpoint に**挙動変更**を入れたら、
   `docs/supported-browsers.md` の**実機受入確認の再確認条件**に従って再確認する。
   Browser テストは **Chromium + WebKit の 2 レーン**が契約 (`docs/testing-browser.md`)。
   実行時間を理由に WebKit レーンを落とさない (復元シナリオの恒久回帰が消えるため)
```

### テスト計画
- [x] 文書のみ。テストは施策 3・4 が担う
- [x] `AGENTS.md` の記述と実装の対応関係が施策 3・4 のテスト名で追える状態にする

### リスク
- 文書が実装より広い保証を書くと契約誤記になる（Codex 概念設計レビュー Round 1・2 の Critical）。
  **主語を Inertia 面 / 同一タブ / popstate に限定する**ことを最優先で守る。

---

## 実装順序（テストファースト）

1. 施策 4 の Browser テスト 1 本目を書き、**fail を確認**する
   （現状は `window.history.state.page` が平文のため正のコントロールが落ちる。
   併せて `$page->assertDontSee($owner->name)` が F-4-01 の実挙動で落ちることを確認する）
2. 施策 3 の Feature テストを書き、**fail を確認**する
3. 施策 1（`bootstrap/app.php`）→ 施策 2（`LogoutResponse` + bind）を実装し green にする
4. 施策 4 の 2 本目（後退検出）を追加し green を確認
5. 施策 5（コメント）・施策 6（文書）
6. 検証コマンド全 green: `composer test` / `composer phpstan` / `vendor/bin/pint --test` /
   `pnpm lint` / `pnpm typecheck` / `pnpm test` / `pnpm build` / `composer test:browser`
   （Browser lane は `pnpm build` 済みが前提）

## 実装モード

| 項目 | 内容 |
|------|------|
| 推奨モード | **standalone** |
| 判断根拠 | `bootstrap/app.php` の web middleware group と `FortifyServiceProvider::register()` という**アプリ全体に効く配線**を触るため、他タスクと同一 worktree で並行させると影響切り分けが困難になる。変更点数は少ないが影響半径は全 Inertia 応答に及ぶ。また `AGENTS.md` / `docs/*` の契約文書を更新するため、他タスクの文書変更と衝突しやすい |
| 競合リスク | `bootstrap/app.php`（middleware 追加）/ `AGENTS.md`（規約 #3）/ `docs/supported-browsers.md` を触る他タスクと競合しうる。`resources/js/lib/bfcache-guard.ts` は docblock のみで挙動は不変 |


---

## 関連する現行コード

### bootstrap/app.php (web group append の該当部のみ抜粋)

```
        $middleware->web(append: [
            HandleInertiaRequests::class,
            SecurityHeaders::class,
            RequireTwoFactorForEnforcedOrganizations::class,
            BlockTwoFactorDisableForEnforcedOrganizations::class,
            NoStoreCacheHeadersForAuthenticatedPages::class,
        ]);
```

### app/Http/Responses/Fortify/LoginResponse.php (既存の差し替えパターン見本)

```
<?php

declare(strict_types=1);

namespace App\Http\Responses\Fortify;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Laravel\Fortify\Contracts\LoginResponse as LoginResponseContract;
use Webmozart\Assert\Assert;

/**
 * ログイン成功レスポンス (Fortify contract bind)。
 *
 * 挙動は Fortify 既定 (XHR=204 / web=intended → fortify.home) と同一だが、
 * アプリ側でリダイレクト先・flash を拡張する差し替え点として明示 bind する
 * (例: admin パス隔離、オンボーディング誘導)。
 */
final class LoginResponse implements LoginResponseContract
{
    /**
     * @param  Request  $request
     */
    public function toResponse($request): JsonResponse|RedirectResponse
    {
        if ($request->wantsJson()) {
            return new JsonResponse('', 204);
        }

        $home = config('fortify.home');
        Assert::string($home);

        return redirect()->intended($home);
    }
}

```

### app/Http/Middleware/NoStoreCacheHeadersForAuthenticatedPages.php

```
<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * 認証済みリクエストの web 応答に `no-store` を保証する baseline middleware。
 *
 * 目的: ログアウト後のブラウザ「戻る」で認証済み画面 (メンバー一覧等の PII) が
 * bfcache から再表示されるのを防ぐ。`no-store` により Firefox は bfcache 格納自体を
 * 拒否し、Chrome は cookie 変更 (= ログアウト) 時に CCNS ページを bfcache から
 * eviction する。副次的に disk / proxy cache への認証済み応答残留も禁止される。
 *
 * **Safari は `no-store` でも bfcache に格納しうる**ため本 middleware だけでは
 * 抑止できない。AI-CUE は撮影が PWA (iOS Safari が主要プラットフォーム) であるため、
 * クライアント側の bfcache 秘匿・再検証 (resources/js/lib/bfcache-guard.ts) と
 * **セットで** 主便益を達成する。対象ブラウザは docs/supported-browsers.md。
 *
 * 適用判定は route 列挙ではなく「認証済みか」で行う (path 列挙は一般認証画面を
 * 取りこぼす)。guest / 公開ページ (login・LP・SEO) は対象外のままにし bfcache /
 * 共有キャッシュの恩恵を維持する。認証済み画面は Inertia SPA でアプリ内の戻る/進むは
 * client-side navigation のため bfcache 喪失による UX 後退はない。
 */
final class NoStoreCacheHeadersForAuthenticatedPages
{
    /**
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        // logout POST は $next 通過後に guard 上の user が null になるため、
        // リクエスト時点の認証状態を先に捕捉する (= logout redirect も対象に含める)。
        $wasAuthenticated = $this->isAuthenticated($request);

        $response = $next($request);

        // リクエスト時点 or 応答時点のどちらかで認証済みなら付与対象
        // (login POST 応答 = 応答時点で認証済み、も保護側に倒す)。
        if (! $wasAuthenticated && ! $this->isAuthenticated($request)) {
            return $response;
        }

        // 既に no-store を持つ応答 (recent-auth 409 / 2FA 409 / 署名 URL redirect 等、
        // 内側で明示されたより厳格な値) は書き換えず維持する。
        // directive が縮む方向の上書きをしない。
        if ($response->headers->hasCacheControlDirective('no-store')) {
            return $response;
        }

        $response->headers->set('Cache-Control', 'no-store, private');

        return $response;
    }

    /**
     * 本 middleware の対象は session-backed な web 認証画面。session を持たない
     * リクエスト (routes/web.php の stateless block: SEO/robots/公開ページは
     * StartSession を withoutMiddleware 済) は stateless 公開配信であり対象外。
     */
    private function isAuthenticated(Request $request): bool
    {
        return $request->hasSession() && $request->user() !== null;
    }
}

```

### resources/js/lib/bfcache-guard.ts (docblock と登録部)

```
/**
 * bfcache 秘匿・再検証 guard (詳細設計 施策 6 / P3-b)。
 *
 * 問題: Safari (撮影 PWA の主要プラットフォーム) は `Cache-Control: no-store` でも
 * ページを bfcache に格納しうる。ログアウト後に「戻る」で認証済み画面が復元されると
 * PII が再表示される。サーバ側の no-store baseline
 * (NoStoreCacheHeadersForAuthenticatedPages) だけでは塞げない。
 *
 * 方針: 「復元後に検証」ではなく **検証完了まで復元内容を秘匿**する。
 * 復元してから非同期検証すると、検証完了までの間 復元済みの古い DOM が表示され PII が
 * 一瞬露出する (「無効なら遷移する」は「再表示しない」と同義ではない)。
 *
 * ただし hard reload は常用しない。撮影中の media stream・未送信フォーム・Inertia 履歴を
 * 破棄してしまい、撮影 PWA という使命に直撃するため。有効なら **秘匿を外すだけ**にする。
 *
 * | # | 契機                | 動作                                                        |
 * |---|---------------------|-------------------------------------------------------------|
 * | 1 | pagehide            | documentElement に秘匿属性を同期付与 (この DOM ごと bfcache へ) |
 * | 2 | pageshow (属性あり) | 秘匿のまま軽量プローブ (/session/status)                      |
 * | 3 | セッション有効       | 秘匿属性を外すだけ (DOM / フォーム / Inertia 履歴は温存)       |
 * | 4 | セッション無効       | login へ hard navigation (遷移先は固定の相対パス)             |
 * | 5 | プローブ失敗         | 秘匿維持 + 再試行ボタン表示 (自動再試行はしない)              |
 * | 6 | 再試行押下           | 現在 URL を hard reload (サーバに再判定させる)                |
 *
 * 復元マーカーは **documentElement の秘匿属性そのもの**。sessionStorage は使わない
 * (タブ単位で共有されるため、ページ A の pagehide が立てたフラグを通常遷移先のページ B が
 * 読んで誤って秘匿・プローブする)。属性なら bfcache 復元時だけ DOM ごと戻り、通常遷移では
 * サーバから来た新しい HTML に存在しない = 本質的に履歴エントリ単位のマーカーになる。
 *
 * 秘匿は DOM 表示に限定する (属性付与 + CSS)。DOM ツリーの破棄・再構築はしない。
 * 見た目 (オーバーレイ / 非表示) のスタイルは resources/css/app.css 側に置く (DS token 経由)。
 */

/** documentElement に付ける秘匿属性 = bfcache 復元マーカー兼 CSS スイッチ。 */
export const BFCACHE_HIDDEN_ATTRIBUTE = "data-bfcache-hidden";

/** 秘匿属性の値 (状態遷移を一意に表す)。 */
export const BFCACHE_STATE_PENDING = "pending";
export const BFCACHE_STATE_VERIFYING = "verifying";
export const BFCACHE_STATE_RETRY = "retry";

/** プローブ endpoint。サーバ側は routes/web.php の `session.status` (auth グループ外)。 */
export const SESSION_STATUS_PATH = "/session/status";

/** セッション無効時の遷移先。任意 URL は受け取らない (固定の相対パスのみ)。 */
export const LOGIN_PATH = "/login";

export const BFCACHE_OVERLAY_ID = "bfcache-guard-overlay";
export const BFCACHE_RETRY_BUTTON_ID = "bfcache-guard-retry";

/** プローブが必要とする最小 Response 契約 (テスト差替のため fetch 全体に依存しない)。 */
export interface ProbeResponseLike {
    ok: boolean;
    headers: { get(name: string): string | null };
    json(): Promise<unknown>;
}

export type ProbeFetch = (input: string, init: RequestInit) => Promise<ProbeResponseLike>;

/** guard が使う window の最小契約 (jsdom は実 navigation を持たないため差替可能にする)。 */
export interface GuardWindow {
    addEventListener(type: string, listener: (event: Event) => void): void;
    removeEventListener(type: string, listener: (event: Event) => void): void;
    location: { replace(url: string): void; reload(): void };
}

export interface BfcacheGuardDeps {
    doc?: Document;
    win?: GuardWindow;
    fetchImpl?: ProbeFetch;
    /**
     * 認証済みページか (Inertia 共有 props の `auth.user` を起点にする)。
     * 公開ページ (LP / login / SEO) では秘匿もプローブも行わない。
     */
    isAuthenticated?: () => boolean;
}

/** プローブの判定結果。`failed` は「セッション無効」ではなく「判定不能」。 */
export type SessionProbeOutcome = "authenticated" | "unauthenticated" | "failed";

/** Content-Type の media type 判定 (charset 等のパラメータは許容する)。 */
export function isJsonMediaType(contentType: string | null): boolean {
    if (contentType === null) return false;
    const mediaType = contentType.split(";")[0]?.trim().toLowerCase() ?? "";
    return mediaType === "application/json";
}

/**
 * プローブ応答の shape 厳密判定。top-level に boolean の `authenticated` を持つ
 * plain object のみ受理する (data ラップ・型違いは判定不能として弾く)。
 */
export function readAuthenticatedFlag(payload: unknown): boolean | null {
    if (typeof payload !== "object" || payload === null || Array.isArray(payload)) {
        return null;
    }
    const value = (payload as Record<string, unknown>).authenticated;
    return typeof value === "boolean" ? value : null;
}

/**
 * セッション有効性を問い合わせる。
 * (1) response.ok (2) Content-Type が JSON (3) JSON shape が厳密 — の全てを満たした時のみ
 * 結果を採用し、1 つでも崩れたら `failed` (秘匿維持) に倒す。
 */
export async function probeSessionStatus(
    fetchImpl: ProbeFetch,
    url: string = SESSION_STATUS_PATH,
): Promise<SessionProbeOutcome> {
    try {
        const
```

### resources/js/app.ts

```
import { createInertiaApp, page } from "@inertiajs/svelte";
import { hydrate, mount } from "svelte";
import { resolvePage } from "./inertia";
import { registerBfcacheGuard } from "./lib/bfcache-guard";
import { registerDocumentTitleSync } from "./lib/document-title";
import { hasAuthenticatedUser } from "./lib/shared-props";

// SPA 遷移後の document.title 陳腐化を解消する。Svelte adapter には createInertiaApp の
// title callback が無いため、router.on('navigate') を購読してサーバ共有 prop `title` を
// document.title へ同期する (= title callback の等価機構)。document 不在 (SSR) では no-op。
if (typeof document !== "undefined") {
    const disposeTitleSync = registerDocumentTitleSync();
    // HMR 二重登録防止: dev の hot reload で app.ts が再評価される際に前回の
    // router.on('navigate') 購読を解除する。本番ビルドでは import.meta.hot は undefined。
    import.meta.hot?.dispose(disposeTitleSync);

    // bfcache 復元時の PII 再表示を塞ぐ (詳細設計 施策 6)。作動条件は Inertia 共有 props の
    // auth.user (= 認証済みページのみ)。判定は登録時に固定せず pagehide のたびに評価する:
    // login は Inertia の client-side 遷移で完了するため、「起動時 guest だった document が
    // そのまま認証済み画面になる」経路があり、起動時 1 回の判定では取りこぼす。
    // 公開ページ (LP / login / SEO) では秘匿もプローブも起こらない点は同じ。
    const disposeBfcacheGuard = registerBfcacheGuard({
        isAuthenticated: () => hasAuthenticatedUser(page.props),
    });
    import.meta.hot?.dispose(disposeBfcacheGuard);
}

createInertiaApp({
    resolve: resolvePage,
    setup({ el, App, props }) {
        if (!el) {
            throw new Error("Inertia root element not found");
        }
        if (el.dataset.serverRendered === "true") {
            hydrate(App, { target: el, props });
        } else {
            mount(App, { target: el, props });
        }
    },
});

```

### resources/js/components/templates/AppLayout.svelte (logout ハンドラ部)

```
    // ログアウト (二重送信ガード。失敗時も onFinish で解除され再試行できる)
    function logout(): void {
        if (loggingOut) return;
        router.post("/logout", {}, {
            onStart: () => { loggingOut = true; },
            onFinish: () => { loggingOut = false; },
        });
    }
    // navItems: [{href:'/dashboard',label:'ダッシュボード'}, {href:'/projects',label:'プロジェクト'},
    //            {href:'/manage/users',label:'メンバー'(canManageMembers)}, ... ]
    // data-testid: app-user-menu-toggle (L264) / logout-button (L356 logoutTestId)
```

### vendor/inertiajs/inertia-laravel/src/EncryptHistoryMiddleware.php

```
<?php

namespace Inertia;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EncryptHistoryMiddleware
{
    /**
     * Handle the incoming request and enable history encryption. This middleware
     * enables encryption of the browser history state, providing additional
     * security for sensitive data in Inertia responses.
     *
     * @return Response
     */
    public function handle(Request $request, Closure $next)
    {
        Inertia::encryptHistory();

        return $next($request);
    }
}

```

### vendor/laravel/fortify/src/Http/Controllers/AuthenticatedSessionController.php::destroy

```
    public function destroy(Request $request): LogoutResponse
    {
        $this->guard->logout();
        if ($request->hasSession()) {
            $request->session()->invalidate();
            $request->session()->regenerateToken();
        }
        return app(LogoutResponse::class);
    }
```

### @inertiajs/core 3.3.1 src/eventHandler.ts (popstate / pageshow)

```
  protected handlePageshowEvent(event: PageTransitionEvent): void {
    if (event.persisted) { history.decrypt().catch(() => this.onMissingHistoryItem()) }
  }
  protected handlePopstateEvent(event: PopStateEvent): void {
    const state = event.state || null
    if (state === null) { /* replaceState + Scroll.reset */ return }
    if (!history.isValidState(state)) { return this.onMissingHistoryItem() }
    history.decrypt(state.page)
      .then((data) => { /* version 不一致なら onMissingHistoryItem。一致なら currentPage.setQuietly(swap) */ })
      .catch(() => { this.onMissingHistoryItem() })
  }
  public onMissingHistoryItem() { currentPage.clear(); this.fireInternalEvent('missingHistoryItem') }
// router.init: eventHandler.on('missingHistoryItem', () => this.visit(window.location.href, { preserveState: true, preserveScroll: true, replace: true }))
// history.clear(): SessionStorage.remove('historyKey'); SessionStorage.remove('historyIv')
// page.set(): if (page.clearHistory) history.clear()   ← component resolve/swap より前
// encryption.ts encryptData(): crypto.subtle 不在なら console.warn + 平文をそのまま返す
```

### tests/Browser/AuthenticatedPageBfcacheTest.php (既存。ヘルパ名の衝突回避のため参照)

```
<?php

declare(strict_types=1);

use App\Models\Project;
use App\Models\User;
use App\Models\VideoManual;
use Pest\Browser\Api\PendingAwaitablePage;
use Pest\Browser\Enums\BrowserType;
use Pest\Browser\Playwright\Playwright;

/*
|--------------------------------------------------------------------------
| bfcache 秘匿・再検証の Browser E2E (詳細設計 施策 8)
|--------------------------------------------------------------------------
|
| 4 シナリオ:
|   1. 撮影画面からの通常遷移   — 秘匿が誤発火しないこと (両レーン)
|   2. bfcache 復元 (一般)      — 秘匿 → 検証 → 復帰の状態遷移 (WebKit レーンが正本)
|   3. 未ログアウトでの復元      — 表示と未送信フォーム状態が正しく戻ること (同上)
|   4. ログアウト後の復元        — PII が出ないこと = 本来の目的 (同上)
|
| レーンの位置づけ (docs/supported-browsers.md):
|   - 復元シナリオ (2/3/4) の正本は **WebKit レーン**。Chromium は `no-store` ページを
|     bfcache から evict するため、そもそも復元を再現できない。
|   - **ただし実測結果**: **Chromium / WebKit のどちらのレーンでも bfcache 復元が起きない**
|     (`no-store` の無い公開ページ間ですら復元されないことを実測)。
|     Chromium の原因は特定済みで、**Playwright が既定の起動スイッチに
|     `--disable-back-forward-cache` を渡している**ため (pest-plugin-browser が launch-options を
|     ハードコードしており外せない)。WebKit 側の原因は未特定。
|     詳細と対処方針は docs/supported-browsers.md。復元シナリオはこのハーネスでは成立しない。
|   - そのため 2/3/4 は **ハーネスの bfcache 再現能力を毎回実測**し、再現できない環境では
|     skip する (= その環境では**自動回帰で担保されていない**ことを出力に明示する)。
|     再現できる環境では下記の正のコントロールが厳格に効く。
|
| **正のコントロール**: シナリオ 2/3/4 は `pageshow.persisted === true` を実際に観測できた
| 場合のみ有効。観測できなければテストを失敗させる (空振りを green にしない)。
| 分岐ロジック自体の網羅は tests/js/lib/bfcache-guard.test.ts (vitest) が担う。
|
| 実行: composer test:browser (Chromium / WebKit の両レーン)。
| 前提: pnpm build 済み + `pnpm exec playwright install chromium webkit` 済み。
*/

/** WebKit (Playwright の safari) レーンで走っているか。 */
function bfcacheLaneIsWebKit(): bool
{
    return Playwright::defaultBrowserType() === BrowserType::SAFARI;
}

/**
 * このハーネスで bfcache 復元が起きるかを**実測**する (プロセス内 1 回)。
 *
 * `no-store` の無い公開ページ間で「戻る」を行い、JS 実行コンテキストが生き残るか
 * (= bfcache 復元か、通常の再取得か) を見る。ブラウザ種別の決め打ちにしないのは、
 * 「このレーンなら再現できるはず」という**見込み**を成功条件にしないため。
 */
function bfcacheRestoreIsReproducible(): bool
{
    static $reproducible = null;

    if ($reproducible === null) {
        $page = visit('/pricing');
        $page->script("window.__bfcacheHarnessProbe = 'alive'; true");
        $page->navigate('/terms');
        $page->back();
        $reproducible = $page->script('window.__bfcacheHarnessProbe ?? null') === 'alive';
    }

    return $reproducible;
}

/**
 * 復元シナリオの前提チェック。再現できない環境では **skip し、その旨を明示**する
 * (green を装わない。skip は「担保されていない」の表明であり、合格ではない)。
 */
function bfcacheSkipUnlessRestoreIsReproducible(): void
{
    if (bfcacheRestoreIsReproducible()) {
        return;
    }

    $lane = bfcacheLaneIsWebKit() ? 'webkit' : 'chromium';

    test()->markTestSkipped(
        "このハーネス (lane={$lane}) は bfcache 復元を再現できない "
        .'(no-store の無い公開ページですら「戻る」で復元されないことを実測。'
        .'Chromium は Playwright 既定の --disable-back-forward-cache が原因、'
        .'WebKit は原因未特定。docs/supported-browsers.md 参照)。'
        .'=> このシナリオは自動回帰で担保されていない。分岐ロジックは '
        .'tests/js/lib/bfcache-
```

### docs/supported-browsers.md (冒頭)

```
# サポート対象ブラウザ方針

AI-CUE が「どのブラウザで、どのレベルまで動作を保証しているか」の正本。
`no-store` baseline (`NoStoreCacheHeadersForAuthenticatedPages`) と bfcache 秘匿・再検証
(`resources/js/lib/bfcache-guard.ts`) の**保証範囲を語るための前提**として置く。

「対応している」という言葉を検証レベルと切り離さないこと。
本書では **Current (実際に回っている検証)** と **Target (到達目標)** を分けて書く。

## 対象ブラウザ

撮影 PWA と管理画面はプラットフォーム前提が違うため分けて定義する。

| 面 | URL 空間 | 主要ブラウザ |
|----|----------|--------------|
| **撮影 PWA** | `/app/*` (`manifest.webmanifest`, ホーム画面追加) | **iOS Safari** (standalone 含む) / Android Chrome |
| **管理画面** | 上記以外 | デスクトップ Chrome / Edge / Firefox / Safari |

撮影 PWA が中核 (使命 = 現場作業者がスマホで撮る) であり、**iOS Safari が最重要**。
bfcache 周りの設計判断はすべてこの前提から来ている
(Safari は `Cache-Control: no-store` のページでも bfcache に格納しうる)。

## Current — マージ後に実際に保証していること

| 区分 | 対象 | 扱い |
|------|------|------|
| **自動回帰テスト (恒久)** | **Chromium + WebKit** (Playwright / pest-plugin-browser) | `composer test:browser` が両レーンを実行する。カバーしているのは**秘匿の配線** (pagehide で秘匿属性が付き実描画が止まる / pageshow でプローブが走り秘匿が解ける) と**通常遷移で誤発火しないこと**。**bfcache 復元そのものは下記の理由でカバーできていない** |
| **ユニット (vitest)** | `tests/js/lib/bfcache-guard.test.ts` | guard の分岐 (persisted 有無 / 秘匿属性 有無 / プローブ成功・失敗・エラー / 再試行) と負のコントロールを固定。**復元シナリオの分岐ロジックはここが唯一の恒久回帰** |
| **実機受入確認 (手動)** | **iOS Safari 実機** (PWA standalone 含む) | **「恒久テスト済み」とは表現しない**。実施したら**日時・端末・OS バージョン・結果**を devnotes に記録する |

レーンの実行方法・前提は `docs/testing-browser.md`。

### bfcache 復元が自動回帰でカバーできていない理由 (実測)

**Chromium / WebKit のどちらのレーンでも「戻る」で bfcache 復元が起きない**。
`Cache-Control: no-store` の付かない公開ページ間ですら、戻ると JS 実行コンテキストごと
作り直される (= 通常の再取得) ことを実測している。

原因はレーンごとに異なり、**片方は原因が特定できている**:

| レーン | 原因 | 状態 |
|--------|------|------|
| **Chromium** | **Playwright が既定の起動スイッチに `--disable-back-forward-cache` を渡している** (`playwright-core` の chromium switches に固定で含まれる。playwright 1.61.1 で確認)。`no-store` による evict 以前に、**bfcache 機構そのものがブラウザ起動時点で無効**になっている | **原因特定済み**。`launch` に `ignoreDefaultArgs: ['--disable-back-forward-cache']` を渡せば有効化できるが、`pest-plugin-browser` (`Playwright/Client.php::connectTo()`) が launch-options を **ハードコード**しており、プラグイン側の対応か vendor patch が要る |
| **WebKit** | **未特定**。Playwright の WebKit ビルド / automation セッションで page cache が使われない可能性があるが、確証は取れていない | **要調査**。復元シナリオの正本レーンなのでここが本丸 |

> 「Playwright は自動化インスペクタを接続しているから bfcache が効かない」という説明は
> **Chromium については誤り**である (原因は上記の起動スイッチ)。誤った原因を残すと
> 対処の方向を誤らせるため、判明した事実だけを書く。

そのため `tests/Browser/AuthenticatedPageBfcacheTest.php` のシナリオ 2〜4 は、
**ハーネスの bfcache 再現能力を毎回実測**し、再現できない環境では理由付きで skip する。
再現できる環境 (将来ツール側が対応した場合) では、
`pageshow.persisted === true` を観測できなければ**失敗する**正のコントロールが効く。

**skip は合格ではない**。現時点で復元シナリオを担保しているのは
vitest のユニットテスト (分岐ロジック) と実機受入確認 (
```

### AGENTS.md ドメイン固有規約 #3 (現行)

```
3. **サポート対象ブラウザと bfcache の扱い**: 「どのブラウザで何をどこまで保証しているか」の
   正本は **`docs/supported-browsers.md`**。撮影 PWA の主戦場は iOS Safari であり、Safari は
   `Cache-Control: no-store` でも bfcache に格納しうるため、認証済み画面は
   サーバ側 no-store baseline (`NoStoreCacheHeadersForAuthenticatedPages`) と
   クライアント側の bfcache 秘匿・再検証 (`resources/js/lib/bfcache-guard.ts` +
   `session.status` プローブ) の **セット**で守る。
   bfcache guard / 秘匿スタイル / プローブ endpoint に手を入れたら、
   `docs/supported-browsers.md` の**実機受入確認の再確認条件**に従って再確認する。
   Browser テストは **Chromium + WebKit の 2 レーン**が契約 (`docs/testing-browser.md`)。
   実行時間を理由に WebKit レーンを落とさない (復元シナリオの恒久回帰が消えるため)
```
