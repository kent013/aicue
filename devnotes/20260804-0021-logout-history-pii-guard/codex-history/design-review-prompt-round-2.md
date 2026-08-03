# Round 2: 対応マトリクスと修正後の詳細設計

Round 1 の Warning 3 件 + Suggestion 2 件を捌きました。
1 件 (施策 2 の clearHistory 無条件実行) のみ**根拠を添えて反論**しています。

## 対応マトリクス

# 対応マトリクス: design-review Round 1

## [Warning] 施策2: 非 Inertia JSON ログアウトでも `Inertia::clearHistory()` を無条件実行するのは副作用
- 判断: **反論する（無条件実行を維持）。ただし挙動をテストで明示固定する**
- 根拠:
  1. **`X-Inertia` で分岐すると逆にセキュリティホールになる。** 非 Inertia の XHR ログアウトでも、
     そのタブには Inertia の暗号化履歴が残っている。実例が既にリポジトリ内にある:
     `tests/Browser/AuthenticatedPageBfcacheTest.php` の `bfcacheLogoutInBrowser()` は
     Inertia 画面から `fetch('/logout', { Accept: 'application/json' })` でログアウトしている。
     ここで `clearHistory()` を止めると、**Inertia 履歴が復号可能なまま残る** = F-4-01 の再発。
     「このブラウザの履歴はもう無効」という事実はクライアント種別に依存しない。
  2. **フラグが宙に浮いて悪さをするケースは実質無い。** `clearHistory()` は
     `session()->invalidate()` の**後**に走るため、フラグは**ログアウト後の guest session** に載る。
     その後 Inertia 応答が来れば消費される (`/login` でも `/` でも)。
     唯一の残余は「JSON ログアウト後、Inertia 応答を一度も描画しないまま再ログインした」場合で、
     `session()->regenerate()` はデータを引き継ぐためフラグが残り、ログイン後最初の Inertia 応答で
     `history.clear()` が走る。**これは無害かつ自己修復的**: 消えるのはログイン前 (guest) の
     履歴エントリの復号可能性だけで、以降のエントリは新しい鍵で暗号化される。
     セキュリティ的にはむしろ安全側。
  3. 分岐を入れると「どの経路で clearHistory が走るか」がクライアント種別に依存し、
     防御の成立条件が読み手に見えなくなる (原則: 条件分岐で不変条件を弱めない)。
- 対応内容: 実装は無条件のまま維持し、
  (a) `LogoutResponse` の docblock に上記 1〜3 を根拠として明記、
  (b) Feature テストに **「JSON ログアウトでも次の Inertia 応答で `clearHistory` が消費される」**
      ケースを追加して、この挙動を偶然ではなく契約として固定する。

## [Warning] 施策3: 実運用経路 (`X-Inertia` ヘッダ付きの Inertia visit) そのものの保証が弱い
- 判断: **対応する**
- 根拠: 指摘のとおり。実ブラウザのログアウトは `X-Inertia` 付き XHR で、応答は
  302 → XHR が追従 → 着地は **JSON の page オブジェクト**になる。
  現行案は root view (`viewData('page')`) 経由しか見ておらず、実経路を直接は縛れていない。
- 対応内容: Feature テストに
  「`X-Inertia` 付きで `POST /logout` → 着地 `GET /` (`X-Inertia` + `X-Inertia-Version`) の
  **JSON page に `clearHistory: true` が載る**」ケースを追加する。
  version は `Inertia::getVersion()` から取る (不一致だと 409 になるため)。

## [Suggestion] 施策3: 「1 度きり」テストの `/pricing` 依存
- 判断: **対応する**
- 根拠: `/pricing` が将来非 Inertia 化すると `inertiaPagePayload()` が落ちて意図と違う失敗になる。
- 対応内容: 2 回目の取得も `route('home')` にして、契約テストが依存する route を
  「ログアウト着地 = Inertia 応答」の 1 本に集約する。

## [Warning] 施策4: `assertDontSee()` は「一瞬表示されて消えた」を取り逃す
- 判断: **対応する**
- 根拠: 妥当。本件の要件は「PII を**一度も描画しない**」であり、
  終状態だけを見る assertion では要件を証明できない。
  設計上は「復号失敗時に swap 自体が起きない」ので瞬間露出は無いはずだが、
  **その「はず」をテストで機械的に固定する**のがテストファーストの趣旨。
- 対応内容: `back()` の**前**に `MutationObserver` を仕込み、
  DOM 変化のたびに PII 文字列の出現を監視して `window.__piiSeen` に記録する。
  遷移完了後に `__piiSeen === false` を検証する (初期状態も 1 度チェックする)。

## [Suggestion] 施策4: 暗号化検知をヘルパへ抽象化
- 判断: **一部対応する**
- 根拠: 過度な抽象化は不要だが、判定式が 2 箇所以上に出るならヘルパ化の価値はある。
- 対応内容: 判定式は 1 箇所のみなのでヘルパは作らず、
  「Inertia が history state を ArrayBuffer で保存するという前提に依存している」ことを
  コメントで明示し、Inertia 更新時にここを見直す旨を書く。

## [Suggestion] 施策2/5/6 のその他
- 着地の Inertia 契約テストは既に施策 3 に含まれている (追加対応なし)。
- 施策 1・5・6 は APPROVE。変更なし。


---

## 修正後の詳細設計 (全文)

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
 * `clearHistory()` は **両分岐の前に無条件で**呼ぶ。`X-Inertia` の有無で分岐しない:
 *   1. 非 Inertia の XHR ログアウトでも、そのタブには Inertia の暗号化履歴が残っている
 *      (実例: tests/Browser/AuthenticatedPageBfcacheTest.php の bfcacheLogoutInBrowser() は
 *      Inertia 画面から fetch('/logout', { Accept: 'application/json' }) でログアウトする)。
 *      分岐すると**履歴が復号可能なまま残り F-4-01 が再発する**。
 *   2. フラグは session()->invalidate() の後に積まれるため、載るのは**ログアウト後の
 *      guest session**。次の Inertia 応答 (/login でも / でも) が pull して消費する。
 *      「Inertia 応答を一度も描画しないまま再ログイン」した場合だけフラグが持ち越されるが、
 *      その場合に失われるのは**ログイン前 (guest) の履歴エントリの復号可能性だけ**で無害
 *      (以降のエントリは新しい鍵で暗号化される)。
 *   3. 防御の成立条件をクライアント種別に依存させない (条件分岐で不変条件を弱めない)。
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

    // pull 済みなので 2 度目には載らない (無関係なページで履歴が飛ぶ事故を防ぐ)。
    // 依存 route は「ログアウト着地 = Inertia 応答」の 1 本に集約する
    // (他 route の Inertia 性に依存すると、その route の変更で false negative になる)。
    expect(inertiaPagePayload($this->get(route('home'))))->not->toHaveKey('clearHistory');
});

test('実運用経路 (X-Inertia visit) でも着地の page JSON に clearHistory が載る', function (): void {
    // 実ブラウザのログアウトは router.post('/logout') = X-Inertia 付き XHR。
    // 302 を XHR が追従し、着地は **JSON の page オブジェクト**になる。
    // root view 経由 (viewData) だけでなく、この実経路も直接固定する。
    [, $owner] = createOrganizationWithOwner();

    $this->actingAs($owner)
        ->withHeaders(['X-Inertia' => 'true', 'X-Inertia-Version' => Inertia::getVersion()])
        ->post('/logout')
        ->assertRedirect(route('home'));

    $this->withHeaders(['X-Inertia' => 'true', 'X-Inertia-Version' => Inertia::getVersion()])
        ->get(route('home'))
        ->assertOk()
        ->assertHeader('X-Inertia', 'true')
        ->assertJson(['clearHistory' => true]);
});

test('JSON クライアントのログアウトは 204 のまま (既定挙動の維持)', function (): void {
    [, $owner] = createOrganizationWithOwner();

    $this->actingAs($owner)
        ->postJson('/logout')
        ->assertNoContent();

    $this->assertGuest();
});

test('JSON ログアウトでも次の Inertia 応答で clearHistory が消費される', function (): void {
    // clearHistory は X-Inertia の有無で分岐しない (LogoutResponse docblock の根拠 1)。
    // Inertia 画面から fetch('/logout', { Accept: 'application/json' }) する経路
    // (既存 Browser テストの bfcacheLogoutInBrowser() が実際にこれ) でも履歴鍵を捨てさせる。
    [, $owner] = createOrganizationWithOwner();

    $this->actingAs($owner)->postJson('/logout')->assertNoContent();

    expect(inertiaPagePayload($this->get(route('home'))))->toHaveKey('clearHistory', true);
});
```

> `Inertia::getVersion()` を使うため、テスト冒頭に `use Inertia\Inertia;` を書く。
> version 不一致だと Inertia は 409 を返すため、値をハードコードしない。

### PHPStan 適合チェック
- tests/ は PHPStan の解析対象外（`phpstan.neon` の paths は `app` / `config` / `database` / `routes`）。
  それでも `viewData('page')` の `mixed` は `expect()->toBeArray()` で明示的に絞る。

### テスト計画
- [x] バグ修正の再現テストを先に書く（**施策 4 の Browser テストが F-4-01 の再現そのもの**。
      施策 1・2 を入れる前に走らせて **fail することを確認**してから実装に入る = テストファースト）
- [x] 既存テストの更新: 不要（`NoStoreCacheHeadersTest` / `ExistingNoStoreContractTest` /
      `SessionStatusProbeTest` の契約は不変）
- [x] 新規テスト: 上記 8 本
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
|   3. 「戻る」の前に仕込んだ MutationObserver が PII 文字列の出現を一度も記録しないこと
|      = 終状態だけでなく **途中フレームでも露出していない** ことの機械的保証
|
| 実行: composer test:browser (Chromium / WebKit の両レーン)。
| 前提: pnpm build 済み。
*/

/**
 * PII 文字列が **一瞬でも** DOM に現れたかを記録する監視を仕込む。
 *
 * 要件は「復元時に PII を一度も描画しない」であり、終状態だけを見る assertDontSee では
 * 「一瞬出て消えた」を取り逃す。MutationObserver で DOM 変化のたびに走査し、
 * 初期状態も 1 度チェックする (観測開始時点で既に出ている場合を拾う)。
 */
function inertiaHistoryWatchForPii(PendingAwaitablePage $page, string $needle): void
{
    $encoded = json_encode($needle, JSON_THROW_ON_ERROR);

    $page->script(<<<JS
        (() => {
            const needle = {$encoded};
            window.__piiSeen = document.body.innerText.includes(needle);
            const observer = new MutationObserver(() => {
                if (document.body.innerText.includes(needle)) window.__piiSeen = true;
            });
            observer.observe(document.body, {
                childList: true, subtree: true, characterData: true,
            });
            return true;
        })()
    JS);
}

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

    // 正のコントロール 1: history 暗号化が実際に効いている。
    // ※ 「Inertia は暗号化した page を ArrayBuffer で history state に入れる」という
    //    @inertiajs/core の実装前提に依存する。Inertia を更新したらここを見直すこと。
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

    // 「戻る」の前に瞬間露出の監視を仕込む (終状態の assertDontSee では
    // 「一瞬表示されて消えた」を取り逃すため)
    inertiaHistoryWatchForPii($page, $owner->name);

    // ブラウザバック = Inertia の popstate 履歴復元
    $page->back();

    inertiaHistoryWaitUntil(
        $page,
        "window.location.pathname === '/login'",
        'ログアウト後の戻るで /login に倒れない',
    );

    // 本丸: 復元 → login までの間、PII が **一度も** 描画されていない
    // (復号失敗時はコンポーネント swap 自体が起きない、という設計の機械的な証明)
    $page->assertScript('window.__piiSeen', false);

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
   （現状は `window.history.state.page` が平文のため正のコントロール 1 が落ちる。
   併せて `window.__piiSeen` が true になる = F-4-01 の実挙動そのものが再現することを確認する）
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

## 再レビュー依頼

1. 施策 2 への反論 (X-Inertia で分岐すると、Inertia 画面から fetch で叩く JSON ログアウト経路で
   履歴が復号可能なまま残り F-4-01 が再発する) が成立しているか。
   成立しないなら、無条件実行で実害が出る具体シナリオを示してください。
2. 施策 3 に追加した実運用経路テスト (X-Inertia visit の 302 追従先 JSON に clearHistory) が
   実際に Laravel のテストクライアントで成立するか (version ヘッダの扱いを含む)。
3. 施策 4 の MutationObserver による瞬間露出検知が、狙いどおり「途中フレームの露出」を
   検出できるか (取り逃す条件があれば指摘してください)。

各施策の判定と全体判定 (APPROVED / CHANGES_REQUESTED) を明示してください。
