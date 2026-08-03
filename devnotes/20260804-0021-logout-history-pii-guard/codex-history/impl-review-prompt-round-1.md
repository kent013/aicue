【アプリの使命 (North Star)】
## 使命 (North Star)

<!-- app-codex-review スキルはこのセクションをレビュー使命として参照する。 -->

**AI-CUE** は、現場に既にある**作業手順書(SOP)を起点に**、AI が撮るべきカットを設計した**動画シナリオ**を生成し、そのシナリオを**スマホ(PWA)でナビゲーション撮影**することで、専門知識ゼロの現場作業者でも**標準化されたマニュアル動画**を作れるようにする。

- 「思考ゼロ・編集ゼロ」— 台本作成・撮影判断・編集の 3 ハードルを AI とナビ撮影で肩代わりする。
- 競合(OJT を撮って形式化する tebiki)と異なり、**標準作業を起点に AI が教材設計し撮影を指示する**（撮影者・教える人のスキルに品質を依存させない）。
- 熟練者の暗黙知を動画マニュアルという形式知へ変換する装置（SECI）。

> 詳細な設計は本リポジトリの `doc/`（01〜10）に置く。ドメインをテンプレート構造へどうマップしたかは `doc/08`〜`doc/10`（特に `doc/10 §10.8` に設計レビュー反映済み）。
> **v1 スコープ**: 字幕のみ(TTS 後回し) / 撮影は PWA(同一オリジン・セッション認証) / 動画合成は自前 ffmpeg / 単一 Default Project。


【禁止事項】
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

## system: 役割

あなたはコードレビュアーとして、Laravel 12 + Svelte 5 (runes) + Inertia.js のアプリ AI-CUE の
改善実装 (TODO T089 / bug-hunt F-4-01: ログアウト後のブラウザバックで認証済み画面が PII 込みで復元される)
をレビューする。

### レビュー観点
1. **設計との一致性**: 添付の詳細設計書の 7 施策が過不足なく実装されているか。設計から逸脱している箇所は妥当か
2. **正確性**: Inertia の history 暗号化 / clearHistory の機構理解が正しいか。ログアウト経路・middleware 順序・session ライフサイクルの前提に誤りがないか
3. **PHPStan 適合性** (level 10。widen / baseline / @phpstan-ignore は禁止)
4. **DTO / JsonResource パターン** (`response()->json()` 直書き禁止。仕様固定 endpoint のみ例外)
5. **テスト網羅性**: 不変条件が Architecture / Feature テストへ登録されているか。空振り green にならない正のコントロールが効いているか
6. **セキュリティ**: 保証範囲の主語が実装より広く書かれていないか (契約誤記)。残存リスクが誤魔化されていないか
7. **DESIGN.md 準拠**: color/radius/typography は token 経由。hex 直書きを増やしていないか
8. **Atomic Design 準拠**: `resources/js/components/` の階層 (atoms→molecules→organisms→features→templates→pages) を逆流していないか。アイコンは Lucide

### 出力形式
- ファイルごとに判定を書く
- 指摘は **[Critical] / [Warning] / [Suggestion]** に分類する
- 最後に全体判定 **APPROVED** または **CHANGES_REQUESTED** を書く

---

## user

### 実装の要旨

- 経路 C (Inertia SPA のクライアント履歴復元 = popstate) を Inertia 公式機構だけで塞ぐ。
- `bootstrap/app.php` の web グループに `Inertia\Middleware\EncryptHistory` を append。
- Fortify の `LogoutResponse` contract をアプリ実装に差し替え、`Inertia::clearHistory()` を無条件で呼び、着地を `route('home')` に固定。
- `bfcache-guard.ts` は捨てず、経路 B (Safari の真の bfcache) 担当として docblock で責務分割を明記 (挙動は不変)。
- Browser テスト (Chromium + WebKit 両レーン) / Feature テスト / Architecture (vitest) inventory テストを新設。
- 契約文書 (`docs/supported-browsers.md` / `docs/testing-browser.md` / `AGENTS.md` #3) を 3 経路 × 3 枚の網へ更新。

### 設計からの逸脱 (実装時に判明した実コードとの食い違い)

1. **サイドバー nav item は Inertia Link ではなく素の `<a href>`** (`SidebarNavItems.svelte`)。
   詳細設計の Browser テスト案は「サイドバーの Inertia link (SPA 遷移)」と書いていたが、実際は cross-document 遷移。
   - 再現テスト (2 本目) は影響なし: SPA の pushState エントリを作るのは **ログアウト自身**
     (`router.post('/logout')` → 302 追従 → `/` へ pushState) であり、そこから 1 つ前の
     `/manage/users` document エントリへ戻るのが本件の popstate 復元。実測で red → green を確認済み。
   - 負のコントロール (3 本目「ログイン中の戻るは client-side で完結する」) は、
     サイドバーリンクだと必ず cross-document になり JS 実行コンテキストが失われて意味をなさないため、
     **Dashboard.svelte の Inertia `Link` (TextLink「通知を確認」→ `/notifications`)** に差し替えた。

### red → green の実測ログ

- 実装前 Feature: 8 tests / 3 passed / 5 failed (encryptHistory・clearHistory がすべて未載)
- 実装前 Browser (chromium): 3 tests / 1 passed / 2 errored
  - 「history state が暗号化されている」→ 平文で fail
  - 「ログアウト後の戻るで PII が復元されない」→ `/login` に倒れず fail (= F-4-01 の再現)
- 実装後 Feature: 8 passed / 35 assertions
- 実装後 Browser: chromium 3 passed / webkit 3 passed
- inventory を空にすると Architecture テストが fail することも実測 (deny-by-default の実証)

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

保証範囲の主語（概念設計で固定し、詳細設計レビュー Round 2・3 で厳密化）:
**「Inertia が描画する認証済み画面」×「`clearHistory: true` を含む Inertia page を
クライアントが適用したタブ」×「`popstate` による履歴復元」**。
（「ログアウトを実行したタブ」でも「応答を受信したタブ」でもない。`Inertia::clearHistory()` は
サーバ session にフラグを積むだけで、実装上の境界は `page.set()` 冒頭の `history.clear()` の
完了（`@inertiajs/core` `src/page.ts` L78-80）。通信断や JS 例外で適用前に中断すれば鍵は残る。
現行の `/logout` 導線は 2 箇所ともに Inertia visit (`router.post`) であり、
正常完了時にこの条件を満たす。この「すべて Inertia visit である」不変条件は
施策 7 の Architecture テストが deny-by-default で機械的に固定する）

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
| 7 | ログアウト導線が Inertia visit 一本である不変条件の機械的固定 | `tests/js/architecture/logout-call-site-inventory.test.ts`(新規) | Critical |

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
 *   2. 防御の成立条件をクライアント種別に依存させない (条件分岐で不変条件を弱めない)。
 *
 * **無条件実行は必要条件であって十分条件ではない。** `clearHistory()` がやるのは
 * session にフラグを積むことだけで、`sessionStorage` の鍵が実際に消えるのは
 * **クライアントが `clearHistory: true` を含む Inertia page を適用した瞬間**
 * (`page.set()` 冒頭の `history.clear()`)。
 * 204 を受けて画面遷移しないままブラウザバックすると、鍵は生きており履歴は復号できる。
 * 経路 C が保証するのは
 * 「**`clearHistory: true` を含む Inertia page をクライアントが適用したタブ**」に限られる
 * (受信ではなく適用。通信断や JS 例外で適用前に中断すれば鍵は残る)。
 *
 * このアプリでは実運用上その条件を満たす: `/logout` を叩く導線は
 * `AppLayout.svelte` (通常画面のユーザーメニュー) と `pages/Auth/VerifyEmail.svelte`
 * (メール認証待ち画面の離脱導線) の 2 箇所で、**いずれも `router.post('/logout')` =
 * Inertia visit**。302 を XHR が追従し、**正常完了時に**着地の Inertia page を適用する。
 * JSON 204 経路はリポジトリ内では Browser テストの補助 (経路 B の再現) にしか使われていない。
 * **ログアウト導線を非 Inertia 経路で新設すると経路 C の保証条件が崩れる**。
 * この「一本である」不変条件は
 * `tests/js/architecture/logout-call-site-inventory.test.ts` が deny-by-default で固定する。
 *
 * なお「Inertia 応答を一度も描画しないまま再ログイン」した場合はフラグが持ち越されるが
 * (`session()->regenerate()` はデータを引き継ぐ)、その場合に失われるのは
 * ログイン前 (guest) の履歴エントリの復号可能性だけで無害
 * (以降のエントリは新しい鍵で暗号化される)。
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
    // ※ 「XHR が 302 を追従すること」自体はブラウザ / axios の責務であり、
    //    ここでは追従後の最終リクエストが実ブラウザと同じ形になることを検証する
    //    (追従を含む一気通貫は施策 4 の Browser テストが担う)。
    [, $owner] = createOrganizationWithOwner();
    $this->actingAs($owner);

    // Inertia の asset version は HandleInertiaRequests::version() が
    // **リクエスト処理中に**設定する (Middleware.php L112-114) ため、
    // リクエスト前に Inertia::getVersion() を読むと空になり得て 409 (version mismatch) を招く。
    // サーバ応答が自己申告した version をそのまま使う。
    // ※ ResponseFactory::render() は Response に getVersion(): string を渡すため
    //    page.version は常に string (空文字はあり得る)。前提を明示 assert する。
    $version = inertiaPagePayload($this->get('/dashboard'))['version'];
    expect($version)->toBeString();

    $inertiaHeaders = ['X-Inertia' => 'true'];
    if ($version !== '') {
        // 空のときはヘッダ自体を付けない (実ブラウザの挙動に揃える)
        $inertiaHeaders['X-Inertia-Version'] = $version;
    }

    $this->withHeaders($inertiaHeaders)
        ->post('/logout')
        ->assertRedirect(route('home'));

    $this->withHeaders($inertiaHeaders)
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

test('JSON ログアウトでもフラグは積まれ、次の Inertia 応答で clearHistory が消費される', function (): void {
    // clearHistory は X-Inertia の有無で分岐しない (LogoutResponse docblock の根拠 1)。
    // ※ これは「JSON logout 経路の履歴復元が安全」であることの証明**ではない**。
    //    204 応答ではクライアント鍵は消えず、次の Inertia page を適用するまで残る。
    //    経路 C の保証条件は
    //    「clearHistory: true を含む Inertia page をクライアントが適用したタブ」。
    [, $owner] = createOrganizationWithOwner();

    $this->actingAs($owner)->postJson('/logout')->assertNoContent();

    expect(inertiaPagePayload($this->get(route('home'))))->toHaveKey('clearHistory', true);
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
| テストは 3 本に分ける (実装前の red 確認をコード改変なしで行うため):
|   1. 「history state が暗号化されている」— 施策 1 の単独検証。実装前は平文で fail
|   2. 「ログアウト後の戻るで PII が復元されない」— F-4-01 の再現。実装前は PII 復元で fail
|      (暗号化が degrade しても PII が復元されて落ちるため、単体でも空振りしない)
|   3. 「ログイン中の戻るは client-side で完結する」— 後退検出 (負のコントロール)
|
| 正のコントロール (空振り green を作らない):
|   a. 一連の操作の間 JS 実行コンテキストが生存していること
|      = 本当に same-document の SPA popstate であり、フルリロードで空振りしていない
|   b. 「戻る」の前に仕込んだ MutationObserver が PII 文字列の DOM 出現を一度も記録しないこと
|      = 終状態だけでなく **途中フレームでも DOM に現れていない** ことの機械的保証
|      (「ペイントされていない」ではなく「DOM に出現していない」の検証。
|       本件の PII は Svelte の通常テキストノードとして描画されるため実用上十分)
|
| 実行: composer test:browser (Chromium / WebKit の両レーン)。
| 前提: pnpm build 済み。
*/

/**
 * PII 文字列が **途中フレームでも** DOM に現れたかを記録する監視を仕込む。
 *
 * 要件は「復元時に PII を一度も描画しない」であり、終状態だけを見る assertDontSee では
 * 「一瞬出て消えた」を取り逃す。
 *
 * 検出するのは正確には「**DOM に PII 文字列が出現したか**」であり、
 * 「ペイントされたか」ではない。本件の PII は Svelte が通常のテキストノードとして描画するため、
 * DOM 出現の検出で実用上十分 (DOM に一度も現れなければペイントもされない)。
 *
 * 同一タスク内で「追加 → 削除」されると callback 時点の `innerText` には残らないため、
 * 現在の DOM だけでなく **MutationRecord 自体** も検査する:
 *   (1) 現在の document.body.innerText
 *   (2) 各 record の addedNodes[].textContent
 *   (3) characterDataOldValue: true を指定した上での record.oldValue (テキスト置換)
 * 観測開始時点の初期状態も 1 度チェックする。
 */
function inertiaHistoryWatchForPii(PendingAwaitablePage $page, string $needle): void
{
    $encoded = json_encode($needle, JSON_THROW_ON_ERROR);

    $page->script(<<<JS
        (() => {
            const needle = {$encoded};
            const hit = (text) => typeof text === 'string' && text.includes(needle);

            window.__piiSeen = hit(document.body?.innerText);

            const observer = new MutationObserver((records) => {
                if (hit(document.body?.innerText)) { window.__piiSeen = true; return; }
                for (const record of records) {
                    if (hit(record.oldValue)) { window.__piiSeen = true; return; }
                    for (const node of record.addedNodes) {
                        if (hit(node.textContent)) { window.__piiSeen = true; return; }
                    }
                }
            });
            // 監視対象は documentElement (body 自体が置換されても observer が外れない)。
            // 判定側は live 参照の document.body?.innerText を使う
            // (documentElement.textContent にすると <script> 等の非表示テキストまで拾い
            //  偽陽性で flaky になるため、監視対象と判定対象は分ける)。
            observer.observe(document.documentElement, {
                childList: true,
                subtree: true,
                characterData: true,
                characterDataOldValue: true,
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

test('認証済み画面の history state が暗号化されている', function (): void {
    // 施策 1 (EncryptHistory) が実ブラウザで効いていることの単独検証。
    // ※ 「Inertia は暗号化した page を ArrayBuffer で history state に入れる」という
    //    @inertiajs/core の実装前提に依存する。Inertia を更新したらここを見直すこと。
    // ※ 再現テスト側から分離してあるので、実装前の red 確認を
    //    「一時的にコメントアウトする」手作業なしに行える。
    [, $owner] = createOrganizationWithOwner();
    $this->actingAs($owner);

    $page = visit('/dashboard');
    $page->assertSee($owner->name);

    inertiaHistoryWaitUntil(
        $page,
        'window.history.state?.page instanceof ArrayBuffer',
        'history state が暗号化されていない (EncryptHistory 未適用、または crypto.subtle 不在)',
    );
});

test('ログアウト後のブラウザバックで Inertia 履歴から PII が復元されない', function (): void {
    [, $owner] = createOrganizationWithOwner();
    $this->actingAs($owner);

    // bug-hunt F-4-01 の再現手順: /dashboard → /manage/users (SPA) → ログアウト → 戻る
    $page = visit('/dashboard');
    $page->assertSee($owner->name);
    $page->click('メンバー'); // サイドバーの Inertia link (SPA 遷移 = pushState エントリ生成)
    inertiaHistoryWaitUntil($page, "window.location.pathname === '/manage/users'", 'メンバーへ SPA 遷移しない');
    $page->assertSee($owner->name); // メンバー一覧に PII (氏名) が出ている

    // 正のコントロール: JS 実行コンテキストの生存マーカー (フルリロードで消える)
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
| `AGENTS.md` ドメイン固有規約 #3 | セット構成を 3 枚に更新。主語を「**Inertia が描画する認証済み画面**」に限定し、非 Inertia 面（Filament `/admin`）が対象外であることを明記。経路 C の保証条件と、それを固定する 2 つのテスト（`InertiaHistoryGuardTest` / `logout-call-site-inventory.test.ts`）を明記 |

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

経路 C の保証条件は「**`clearHistory: true` を含む Inertia page をクライアントが適用したタブ**」。
`Inertia::clearHistory()` はサーバ session にフラグを積むだけで、`sessionStorage` の
履歴暗号鍵が実際に消えるのは `page.set()` 冒頭の `history.clear()` が走った瞬間だからである
(受信ではなく適用。通信断や JS 例外で適用前に中断すれば鍵は残る)。
アプリの `/logout` 導線は 2 箇所 (`AppLayout.svelte` / `pages/Auth/VerifyEmail.svelte`) で
いずれも `router.post` = Inertia visit のため、正常完了時にこの条件を満たす
(この不変条件は `tests/js/architecture/logout-call-site-inventory.test.ts` が固定する)。
**ログアウト導線を非 Inertia 経路 (JSON 204 で完結する XHR 等) で新設すると、
この条件が崩れて経路 C の保証が外れる。**
```

### `docs/supported-browsers.md` 未対応事項への追記（案）

```markdown
- **経路 C は「`clearHistory: true` を含む Inertia page をクライアントが適用したタブ」のみを保証する**
  (受信ではなく適用)。JSON 204 で完結するログアウト (Fortify 既定の `wantsJson()` 分岐) では、
  次の Inertia page を適用するまでクライアントの履歴暗号鍵は残る。
  現行の `/logout` 導線は 2 箇所ともに Inertia visit のため実運用では条件を満たすが、
  非 Inertia のログアウト導線を新設すると保証が外れる
  (`tests/js/architecture/logout-call-site-inventory.test.ts` が deny-by-default で固定)。
- **上記を満たしたタブ以外は保証外**。Inertia の履歴暗号鍵は
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
   (C) の保証条件は「**`clearHistory: true` を含む Inertia page をクライアントが適用したタブ**」。
   ログアウト着地 route を非 Inertia 化しない (`InertiaHistoryGuardTest` が固定) /
   ログアウト導線を非 Inertia 経路 (JSON 204 完結の XHR 等) で新設しない
   (`tests/js/architecture/logout-call-site-inventory.test.ts` が deny-by-default で固定)。
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

## 施策 7: ログアウト導線が Inertia visit 一本である不変条件の機械的固定

### 変更箇所
- 新規ファイル: `tests/js/architecture/logout-call-site-inventory.test.ts`

### なぜ必要か
経路 C の保証は「`clearHistory: true` を含む Inertia page をクライアントが**適用**する」ことに
乗っている。現状 `/logout` を叩くのは 2 箇所（`components/templates/AppLayout.svelte` と
`pages/Auth/VerifyEmail.svelte`）で、**いずれも `router.post` = Inertia visit** であることを
実コードで確認済み。将来 JSON 204 で完結する logout 導線（`fetch('/logout')` 等）が増えると
**施策 3・4 のテストは green のまま防御が抜ける**。
AGENTS.md 禁止事項 1（不変条件は Architecture/Feature テストへの登録まで含めて「実装済み」）を
満たすため、この前提を deny-by-default の inventory テストで固定する。

既存の同型テスト（`tests/js/architecture/svg-inline-allowlist.test.ts` /
`lucide-scoped-import.test.ts`）と同じ様式にする。

### 波及変更
- TypeScript 型定義: なし / API Resource: なし
- テストファイル: 新規のみ

### テスト内容（Vitest）

```ts
import { describe, it, expect } from "vitest";
import fs from "fs/promises";
import path from "path";

/**
 * ログアウト導線が Inertia visit 一本であることを deny-by-default で固定する。
 *
 * 経路 C (Inertia history 暗号化 + ログアウト時の履歴鍵破棄。bug-hunt F-4-01) の保証は
 * 「clearHistory: true を含む Inertia page をクライアントが適用すること」に乗っている。
 * JSON 204 で完結する logout (fetch/axios) を足すと、鍵が消えないまま画面が残り、
 * ブラウザバックで PII が復元されうる。
 *
 * 新しいログアウト導線を足したい場合は、それが Inertia visit (router.post) であることを
 * 確認した上で inventory に登録すること。docs/supported-browsers.md の経路 C の記述も更新する。
 */

const JS_ROOT = path.resolve(__dirname, "../../../resources/js");

/**
 * `/logout` を参照してよいファイル (resources/js からの相対パス)。
 * 現状 2 箇所あり、いずれも router.post = Inertia visit
 * (AppLayout: 通常画面のユーザーメニュー / VerifyEmail: メール認証待ち画面の離脱導線)。
 */
const LOGOUT_CALL_SITE_INVENTORY: readonly string[] = [
  "components/templates/AppLayout.svelte",
  "pages/Auth/VerifyEmail.svelte",
] as const;

const LOGOUT_PATH_PATTERN = /["'`]\/logout["'`]/;
/** 非 Inertia 経路 (これが同一ファイルにあると 204 完結の logout になりうる)。 */
const NON_INERTIA_CLIENT_PATTERN = /\b(fetch|axios)\s*\(/;

// (listSourceFiles は .svelte / .ts を再帰列挙する。svg-inline-allowlist.test.ts と同じ実装様式)

describe("logout call site inventory", () => {
  it("resources/js 配下で /logout を叩くのは inventory 登録分のみ", async () => {
    const files = await listSourceFiles(JS_ROOT);

    const offenders: string[] = [];
    for (const file of files) {
      const content = await fs.readFile(file, "utf8");
      if (!LOGOUT_PATH_PATTERN.test(content)) continue;

      const rel = path.relative(JS_ROOT, file).split(path.sep).join("/");
      if (!LOGOUT_CALL_SITE_INVENTORY.includes(rel)) offenders.push(rel);
    }

    expect(offenders).toEqual([]);
  });

  it("inventory 登録ファイルは Inertia visit (router.post) でログアウトする", async () => {
    for (const rel of LOGOUT_CALL_SITE_INVENTORY) {
      const content = await fs.readFile(path.join(JS_ROOT, rel), "utf8");

      // Inertia visit であること (= 着地の Inertia page を適用し clearHistory が効く)
      expect(content).toMatch(/router\.post\(\s*["'`]\/logout["'`]/);
      // 同一ファイルに fetch/axios による非 Inertia 経路を持ち込まない
      expect(NON_INERTIA_CLIENT_PATTERN.test(content)).toBe(false);
    }
  });
});
```

> 実装時の注意:
> - `listSourceFiles` は `svg-inline-allowlist.test.ts` の `listSvelteFiles` を
>   `.svelte` + `.ts` 対応にしたもの。共通化はしない（1 テストのためのユーティリティを
>   横断モジュール化しない。既存テストも各自で持っている）。
> - 現状の 2 ファイルには `fetch(` / `axios` は存在しない（確認済み）ため 2 本目は green で入る。
>   将来落ちる場合は、**inventory を緩めるのではなく**、なぜそのファイルに fetch があるのかを
>   確認し、検査対象を「logout ハンドラ関数の本体」に絞るなどして精度を上げること。

### PHPStan 適合チェック
- 該当なし（TypeScript / Vitest）

### テスト計画
- [x] inventory を意図的に空にすると fail することを確認する（deny-by-default の実証）
- [x] `pnpm test` の既存レーンに含まれる（`tests/js/architecture/` 配下）

### リスク
- `/logout` を含む無関係な文字列（コメント等）が誤検知される可能性。
  その場合も「登録するか、書き方を変えるか」を人間が判断する形になり、fail-secure。
- 検出パターンは**文字列リテラル `"/logout"`** に限定される。将来 `route("logout")` の
  ような名前解決ヘルパを導入すると検出外になる。現行のコード規約（path 直書き）では十分だが、
  ヘルパ導入時は本テストのパターンも同時に更新すること（設計上の既知の限界として明記する）。

---

## 実装順序（テストファースト）

1. 施策 4 の Browser テストを 2 本（暗号化の単独検証 / F-4-01 再現）書き、**red を確認**する。
   分離してあるため一時的なコード改変は不要:
   1-a. 「history state が暗号化されている」→ 現状は平文なので fail。
   1-b. 「ログアウト後の戻るで PII が復元されない」→ 現状は `window.__piiSeen === true` /
        `/login` に倒れないので fail = **F-4-01 の再現**。
2. 施策 3 の Feature テストを書き、**fail を確認**する
3. 施策 1（`bootstrap/app.php`）→ 施策 2（`LogoutResponse` + bind）を実装し green にする
4. 施策 4 の 3 本目（後退検出）を追加し green を確認
5. 施策 7（Architecture テスト）を追加し green を確認
   （inventory を意図的に空にすると fail することも確認する = deny-by-default の実証）
6. 施策 5（コメント）・施策 6（文書）
7. 検証コマンド全 green: `composer test` / `composer phpstan` / `vendor/bin/pint --test` /
   `pnpm lint` / `pnpm typecheck` / `pnpm test` / `pnpm build` / `composer test:browser`
   （Browser lane は `pnpm build` 済みが前提）

## 実装モード

| 項目 | 内容 |
|------|------|
| 推奨モード | **standalone** |
| 判断根拠 | `bootstrap/app.php` の web middleware group と `FortifyServiceProvider::register()` という**アプリ全体に効く配線**を触るため、他タスクと同一 worktree で並行させると影響切り分けが困難になる。変更点数は少ないが影響半径は全 Inertia 応答に及ぶ。また `AGENTS.md` / `docs/*` の契約文書を更新するため、他タスクの文書変更と衝突しやすい |
| 競合リスク | `bootstrap/app.php`（middleware 追加）/ `AGENTS.md`（規約 #3）/ `docs/supported-browsers.md` を触る他タスクと競合しうる。`resources/js/lib/bfcache-guard.ts` は docblock のみで挙動は不変。施策 7 の inventory は `resources/js` にログアウト導線を足す他タスクと競合しうる（その場合は inventory 追加が必要）|

---

## 最終確認（使命・禁止事項チェック）

| 観点 | 確認 |
|---|---|
| 使命への寄与 | 撮影 PWA は現場の共有端末 (iOS Safari) が主戦場。「ログアウト後に自分の業務データを他人に見られない」は導入の最低条件であり、Critical のセキュリティ後退を塞ぐ本件は使命の前提を守る |
| 禁止事項 1（テストなしの完了報告） | 施策 3（Feature 8 本）/ 施策 4（Browser 3 本）/ 施策 7（Architecture 2 本）で不変条件を登録。実装前の red 確認手順も明記 |
| 禁止事項 2（PHPStan widen / baseline） | 型を緩める変更は無い。`LogoutResponse` は `JsonResponse|RedirectResponse` を明示 |
| 禁止事項 3（dev DB 破壊操作） | 該当なし |
| 禁止事項 4（`response()->json()` 直書き） | 該当なし（`JsonResponse('', 204)` は Fortify 既定の contract 実装を踏襲した空 body の 204。DTO 化する payload が無い） |
| 禁止事項 5・6（Prism 直呼び / prompt 直書き） | 該当なし |
| 禁止事項 7（`redirect()->intended()`） | 使わない（`redirect()->route('home')`） |
| 禁止事項 8（disabled ボタン） | UI 変更なし |
| 禁止事項 9（Artifact） | 成果物は本 devnotes 配下のファイルのみ |
| 思考原則 1（フレームワークのレンジ内） | 自前 popstate フックを作らず、Inertia 公式の `EncryptHistory` + `clearHistory()` と Fortify の `LogoutResponse` contract を使う |
| 思考原則 2（今必要なものだけ） | 別タブ伝播（BroadcastChannel）・セッション失効時の popstate 問い合わせは作らず、残存リスクとして文書化 |
| 思考原則 3（後方互換の並走を残さない） | `bfcache-guard.ts` は「経路 B 担当」として責務を明確化し、popstate フックを足さない（二重実装を作らない）。`Fortify::redirects('logout')` 経由の着地も残さず `route('home')` に一本化 |
| 思考原則 5（テストファースト） | 実装順序に red 確認を明記（コード改変なしで red を出せるようテストを分割済み） |
| 思考原則 6（タコツボ回避） | 経路 A/B/C の担当を `bootstrap/app.php` コメント・docblock・`docs/supported-browsers.md`・`AGENTS.md` #3 で一貫させる |
| コーディングルール | `declare(strict_types=1)` + 日本語コメント / Factory 経由のテストデータ（`createOrganizationWithOwner()`）/ 個別 `DatabaseTransactions` 不使用 |

---

## 実装差分 (git diff)

```diff
diff --git a/AGENTS.md b/AGENTS.md
index 500424f..3c9715e 100644
--- a/AGENTS.md
+++ b/AGENTS.md
@@ -198,13 +198,22 @@ ## ドメイン固有規約
    (bytes_used + bytes_pending) 経由のみ。予約 (`take_upload_reservations`) の状態遷移は
    pending→verifying (claim)→completed/released の CAS で行い、直接 UPDATE を書かない。
    運用契約 (media queue worker / 孤児掃除 cron) は `docs/architecture.md` §撮影 PWA
-3. **サポート対象ブラウザと bfcache の扱い**: 「どのブラウザで何をどこまで保証しているか」の
-   正本は **`docs/supported-browsers.md`**。撮影 PWA の主戦場は iOS Safari であり、Safari は
-   `Cache-Control: no-store` でも bfcache に格納しうるため、認証済み画面は
-   サーバ側 no-store baseline (`NoStoreCacheHeadersForAuthenticatedPages`) と
-   クライアント側の bfcache 秘匿・再検証 (`resources/js/lib/bfcache-guard.ts` +
-   `session.status` プローブ) の **セット**で守る。
-   bfcache guard / 秘匿スタイル / プローブ endpoint に手を入れたら、
+3. **サポート対象ブラウザと履歴復元の扱い**: 「どのブラウザで何をどこまで保証しているか」の
+   正本は **`docs/supported-browsers.md`**。**Inertia が描画する認証済み画面**が
+   ログアウト後に復元される経路は 3 本あり、**3 枚セット**で守る
+   (Filament `/admin` など非 Inertia 面は本規約の対象外):
+   (A) サーバ no-store baseline (`NoStoreCacheHeadersForAuthenticatedPages`)、
+   (B) クライアント bfcache 秘匿・再検証 (`resources/js/lib/bfcache-guard.ts` +
+       `session.status` プローブ。撮影 PWA の主戦場 iOS Safari は
+       `Cache-Control: no-store` でも bfcache に格納しうるため必須)、
+   (C) Inertia history 暗号化 + ログアウト時の履歴鍵破棄
+       (`bootstrap/app.php` の `Inertia\Middleware\EncryptHistory` +
+        `App\Http\Responses\Fortify\LogoutResponse` の `Inertia::clearHistory()`)。
+   (C) の保証条件は「**`clearHistory: true` を含む Inertia page をクライアントが適用したタブ**」。
+   ログアウト着地 route を非 Inertia 化しない (`InertiaHistoryGuardTest` が固定) /
+   ログアウト導線を非 Inertia 経路 (JSON 204 完結の XHR 等) で新設しない
+   (`tests/js/architecture/logout-call-site-inventory.test.ts` が deny-by-default で固定)。
+   (B) の guard / 秘匿スタイル / プローブ endpoint に**挙動変更**を入れたら、
    `docs/supported-browsers.md` の**実機受入確認の再確認条件**に従って再確認する。
    Browser テストは **Chromium + WebKit の 2 レーン**が契約 (`docs/testing-browser.md`)。
    実行時間を理由に WebKit レーンを落とさない (復元シナリオの恒久回帰が消えるため)
diff --git a/app/Http/Middleware/NoStoreCacheHeadersForAuthenticatedPages.php b/app/Http/Middleware/NoStoreCacheHeadersForAuthenticatedPages.php
index 253776b..c29c927 100644
--- a/app/Http/Middleware/NoStoreCacheHeadersForAuthenticatedPages.php
+++ b/app/Http/Middleware/NoStoreCacheHeadersForAuthenticatedPages.php
@@ -21,6 +21,12 @@
  * クライアント側の bfcache 秘匿・再検証 (resources/js/lib/bfcache-guard.ts) と
  * **セットで** 主便益を達成する。対象ブラウザは docs/supported-browsers.md。
  *
+ * さらに Inertia SPA のクライアント履歴復元 (popstate) はサーバへリクエストが飛ばないため
+ * 本 middleware も bfcache guard も発火しない。その経路は Inertia 公式機構
+ * (bootstrap/app.php の Inertia\Middleware\EncryptHistory +
+ * App\Http\Responses\Fortify\LogoutResponse の Inertia::clearHistory()) が担当する
+ * (bug-hunt F-4-01)。**3 経路 × 3 枚の網の全体像は docs/supported-browsers.md が正本**。
+ *
  * 適用判定は route 列挙ではなく「認証済みか」で行う (path 列挙は一般認証画面を
  * 取りこぼす)。guest / 公開ページ (login・LP・SEO) は対象外のままにし bfcache /
  * 共有キャッシュの恩恵を維持する。認証済み画面は Inertia SPA でアプリ内の戻る/進むは
diff --git a/app/Http/Responses/Fortify/LogoutResponse.php b/app/Http/Responses/Fortify/LogoutResponse.php
new file mode 100644
index 0000000..656fde8
--- /dev/null
+++ b/app/Http/Responses/Fortify/LogoutResponse.php
@@ -0,0 +1,89 @@
+<?php
+
+declare(strict_types=1);
+
+namespace App\Http\Responses\Fortify;
+
+use Illuminate\Http\JsonResponse;
+use Illuminate\Http\RedirectResponse;
+use Illuminate\Http\Request;
+use Inertia\Inertia;
+use Laravel\Fortify\Contracts\LogoutResponse as LogoutResponseContract;
+
+/**
+ * ログアウト応答 (Fortify contract bind)。
+ *
+ * Fortify 既定との違いは 2 点:
+ *
+ * 1. **`Inertia::clearHistory()` を呼ぶ**。ログアウトは Inertia の SPA visit
+ *    (`AppLayout.svelte` の `router.post('/logout')`) で完結し、以降の「戻る」も
+ *    `popstate` で完結するため、サーバの no-store baseline も
+ *    `bfcache-guard.ts` (pagehide/pageshow) も発火しない。Inertia のクライアント履歴に
+ *    残った認証済みページが PII 込みで復元される (bug-hunt F-4-01)。
+ *    `clearHistory()` は `sessionStorage` の暗号鍵を捨てさせ、履歴エントリを復号不能にする。
+ *    復号に失敗した Inertia は**コンポーネントを描画しないまま**サーバへ再問い合わせし、
+ *    未認証なので `/login` へ倒れる。暗号化の有効化は
+ *    `bootstrap/app.php` の `Inertia\Middleware\EncryptHistory` (web グループ) が担う。
+ *
+ * 2. **着地を `route('home')` に固定する** (`Fortify::redirects('logout')` を経由しない)。
+ *    `clearHistory` フラグは session に積まれ「**次の Inertia 応答**」でしか消費されない
+ *    (`Inertia\Response::__construct` の `session()->pull`)。着地が非 Inertia 応答になると
+ *    フラグが宙に浮き、防御が**静かに**消える。設定 1 つで壊れる経路を残さない。
+ *    着地 `/` は `HomeController` = `Inertia::render('Welcome')`。
+ *    **この route を非 Inertia 化してはならない** (契約。Feature テストが固定する)。
+ *
+ * `wantsJson()` の 204 分岐は Fortify 既定と同値のまま残す (Inertia visit は
+ * `X-Inertia` + Accept: text/html のため常に redirect 側を通る)。
+ * `clearHistory()` は **両分岐の前に無条件で**呼ぶ。`X-Inertia` の有無で分岐しない:
+ *   1. 非 Inertia の XHR ログアウトでも、そのタブには Inertia の暗号化履歴が残っている
+ *      (実例: tests/Browser/AuthenticatedPageBfcacheTest.php の bfcacheLogoutInBrowser() は
+ *      Inertia 画面から fetch('/logout', { Accept: 'application/json' }) でログアウトする)。
+ *      分岐すると**履歴が復号可能なまま残り F-4-01 が再発する**。
+ *   2. 防御の成立条件をクライアント種別に依存させない (条件分岐で不変条件を弱めない)。
+ *
+ * **無条件実行は必要条件であって十分条件ではない。** `clearHistory()` がやるのは
+ * session にフラグを積むことだけで、`sessionStorage` の鍵が実際に消えるのは
+ * **クライアントが `clearHistory: true` を含む Inertia page を適用した瞬間**
+ * (`page.set()` 冒頭の `history.clear()`)。
+ * 204 を受けて画面遷移しないままブラウザバックすると、鍵は生きており履歴は復号できる。
+ * 経路 C が保証するのは
+ * 「**`clearHistory: true` を含む Inertia page をクライアントが適用したタブ**」に限られる
+ * (受信ではなく適用。通信断や JS 例外で適用前に中断すれば鍵は残る)。
+ *
+ * このアプリでは実運用上その条件を満たす: `/logout` を叩く導線は
+ * `AppLayout.svelte` (通常画面のユーザーメニュー) と `pages/Auth/VerifyEmail.svelte`
+ * (メール認証待ち画面の離脱導線) の 2 箇所で、**いずれも `router.post('/logout')` =
+ * Inertia visit**。302 を XHR が追従し、**正常完了時に**着地の Inertia page を適用する。
+ * JSON 204 経路はリポジトリ内では Browser テストの補助 (経路 B の再現) にしか使われていない。
+ * **ログアウト導線を非 Inertia 経路で新設すると経路 C の保証条件が崩れる**。
+ * この「一本である」不変条件は
+ * `tests/js/architecture/logout-call-site-inventory.test.ts` が deny-by-default で固定する。
+ *
+ * なお「Inertia 応答を一度も描画しないまま再ログイン」した場合はフラグが持ち越されるが
+ * (`session()->regenerate()` はデータを引き継ぐ)、その場合に失われるのは
+ * ログイン前 (guest) の履歴エントリの復号可能性だけで無害
+ * (以降のエントリは新しい鍵で暗号化される)。
+ *
+ * 呼ばれる順序: `AuthenticatedSessionController::destroy()` が
+ * `guard->logout()` → `session()->invalidate()` → `session()->regenerateToken()` を終えた**後**に
+ * 本クラスが解決され、`toResponse()` はさらに後 (Router の Responsable 解決時) に走る。
+ * よって `clearHistory()` の session 書き込みは invalidate 後の新しい session に載り、
+ * 着地の Inertia 応答まで確実に届く。
+ */
+final class LogoutResponse implements LogoutResponseContract
+{
+    /**
+     * @param  Request  $request
+     */
+    public function toResponse($request): JsonResponse|RedirectResponse
+    {
+        // 認証済みページの Inertia 履歴 (暗号化済み) を復号不能にする。
+        Inertia::clearHistory();
+
+        if ($request->wantsJson()) {
+            return new JsonResponse('', 204);
+        }
+
+        return redirect()->route('home');
+    }
+}
diff --git a/app/Providers/FortifyServiceProvider.php b/app/Providers/FortifyServiceProvider.php
index 3544515..c258281 100644
--- a/app/Providers/FortifyServiceProvider.php
+++ b/app/Providers/FortifyServiceProvider.php
@@ -10,6 +10,7 @@
 use App\Actions\Fortify\UpdateUserProfileInformation;
 use App\Http\Responses\Fortify\EnumerationSafePasswordResetLinkResponse;
 use App\Http\Responses\Fortify\LoginResponse;
+use App\Http\Responses\Fortify\LogoutResponse;
 use App\Http\Responses\Fortify\PasswordResetResponse;
 use App\Http\Responses\Fortify\PasswordUpdatedResponse;
 use App\Http\Responses\Fortify\ProfileUpdatedResponse;
@@ -37,6 +38,7 @@
 use Laravel\Fortify\Contracts\EmailVerificationNotificationSentResponse as EmailVerificationNotificationSentResponseContract;
 use Laravel\Fortify\Contracts\FailedPasswordResetLinkRequestResponse as FailedPasswordResetLinkRequestResponseContract;
 use Laravel\Fortify\Contracts\LoginResponse as LoginResponseContract;
+use Laravel\Fortify\Contracts\LogoutResponse as LogoutResponseContract;
 use Laravel\Fortify\Contracts\PasswordResetResponse as PasswordResetResponseContract;
 use Laravel\Fortify\Contracts\PasswordUpdateResponse as PasswordUpdateResponseContract;
 use Laravel\Fortify\Contracts\ProfileInformationUpdatedResponse as ProfileInformationUpdatedResponseContract;
@@ -101,6 +103,9 @@ public function register(): void
         // Fortify は constructor に status を渡して make するため bind (非 singleton)
         $this->app->bind(SuccessfulPasswordResetLinkRequestResponseContract::class, EnumerationSafePasswordResetLinkResponse::class);
         $this->app->bind(FailedPasswordResetLinkRequestResponseContract::class, EnumerationSafePasswordResetLinkResponse::class);
+        // ログアウト着地で Inertia::clearHistory() を発火させる (bug-hunt F-4-01)。
+        // 着地 route を固定する理由と順序の前提は LogoutResponse の docblock を参照。
+        $this->app->singleton(LogoutResponseContract::class, LogoutResponse::class);
     }
 
     public function boot(): void
diff --git a/bootstrap/app.php b/bootstrap/app.php
index 2647d35..8cda392 100644
--- a/bootstrap/app.php
+++ b/bootstrap/app.php
@@ -30,6 +30,7 @@
 use Illuminate\Foundation\Configuration\Exceptions;
 use Illuminate\Foundation\Configuration\Middleware;
 use Illuminate\Http\Request;
+use Inertia\Middleware\EncryptHistory;
 use Symfony\Component\HttpFoundation\Response;
 
 return Application::configure(basePath: dirname(__DIR__))
@@ -92,6 +93,21 @@
             // 契約: $next から返った (= 下流の) 応答を確認し、既に `no-store` を持つなら変更しない。
             // (位置関係ではなくこの契約が正本。実効性は Feature テストが固定する)
             NoStoreCacheHeadersForAuthenticatedPages::class,
+            // Inertia の履歴 state を AES-GCM で暗号化する (Inertia 公式のグローバル適用手順)。
+            // ログアウト時に LogoutResponse が Inertia::clearHistory() で鍵を捨てるため、
+            // ログアウト後の「戻る」は復号に失敗し、**コンポーネントを描画しないまま**
+            // サーバへ再問い合わせ → /login に倒れる (bug-hunt F-4-01)。
+            //
+            // Inertia 面の認証済み画面が復元されうる経路と担当 (docs/supported-browsers.md が正本):
+            //   A: HTTP/disk/proxy cache + Chrome/Firefox の bfcache → NoStoreCacheHeaders...
+            //   B: Safari の真の bfcache (pagehide/pageshow)        → resources/js/lib/bfcache-guard.ts
+            //   C: Inertia SPA の history 復元 (popstate)           → 本 middleware + Inertia::clearHistory()
+            //
+            // 認証済み route への限定適用にしない: 認証済み route は ['auth','verified'] グループの
+            // 外にも複数あり (招待受諾 POST 等)、限定適用は inventory ドリフトを生む。
+            // 公開ページの履歴も暗号化されるが PII は無く、コストはログアウト前エントリの
+            // 再取得と remember/scroll 喪失に限られる。
+            EncryptHistory::class,
         ]);
 
         // パスワード変更/リセット時に他デバイスのセッション・remember-me を確実に失効させるため、
diff --git a/docs/supported-browsers.md b/docs/supported-browsers.md
index 03d2a41..3a31ad9 100644
--- a/docs/supported-browsers.md
+++ b/docs/supported-browsers.md
@@ -1,8 +1,26 @@
 # サポート対象ブラウザ方針
 
 AI-CUE が「どのブラウザで、どのレベルまで動作を保証しているか」の正本。
-`no-store` baseline (`NoStoreCacheHeadersForAuthenticatedPages`) と bfcache 秘匿・再検証
-(`resources/js/lib/bfcache-guard.ts`) の**保証範囲を語るための前提**として置く。
+
+**Inertia が描画する認証済み画面**が「ログアウト後に復元される」経路は 3 本あり、
+それぞれ担当が違う。本書はその保証範囲を語るための前提として置く
+(Filament 管理パネル `/admin` は Inertia でも web グループでもないため本書の対象外)。
+
+| 経路 | 担当 | 何を保証するか |
+|------|------|----------------|
+| A: HTTP / disk / proxy cache、Chrome・Firefox の bfcache | `NoStoreCacheHeadersForAuthenticatedPages` | `no-store, private` により格納拒否 / cookie 変更時 evict |
+| B: Safari の真の bfcache (`pagehide` / `pageshow`) | `resources/js/lib/bfcache-guard.ts` + `session.status` プローブ | **描画前に同期秘匿**し、セッション有効なら秘匿解除のみ (hard reload しない) |
+| C: Inertia SPA のクライアント履歴復元 (`popstate`) | `Inertia\Middleware\EncryptHistory` (web グループ) + `App\Http\Responses\Fortify\LogoutResponse` の `Inertia::clearHistory()` | ログアウト後は復号不能 → **コンポーネントを描画しないまま**再問い合わせ → `/login` |
+
+経路 C の保証条件は「**`clearHistory: true` を含む Inertia page をクライアントが適用したタブ**」。
+`Inertia::clearHistory()` はサーバ session にフラグを積むだけで、`sessionStorage` の
+履歴暗号鍵が実際に消えるのは `page.set()` 冒頭の `history.clear()` が走った瞬間だからである
+(受信ではなく適用。通信断や JS 例外で適用前に中断すれば鍵は残る)。
+アプリの `/logout` 導線は 2 箇所 (`AppLayout.svelte` / `pages/Auth/VerifyEmail.svelte`) で
+いずれも `router.post` = Inertia visit のため、正常完了時にこの条件を満たす
+(この不変条件は `tests/js/architecture/logout-call-site-inventory.test.ts` が固定する)。
+**ログアウト導線を非 Inertia 経路 (JSON 204 で完結する XHR 等) で新設すると、
+この条件が崩れて経路 C の保証が外れる。**
 
 「対応している」という言葉を検証レベルと切り離さないこと。
 本書では **Current (実際に回っている検証)** と **Target (到達目標)** を分けて書く。
@@ -24,7 +42,9 @@ ## Current — マージ後に実際に保証していること
 
 | 区分 | 対象 | 扱い |
 |------|------|------|
-| **自動回帰テスト (恒久)** | **Chromium + WebKit** (Playwright / pest-plugin-browser) | `composer test:browser` が両レーンを実行する。カバーしているのは**秘匿の配線** (pagehide で秘匿属性が付き実描画が止まる / pageshow でプローブが走り秘匿が解ける) と**通常遷移で誤発火しないこと**。**bfcache 復元そのものは下記の理由でカバーできていない** |
+| **自動回帰テスト (恒久) — 経路 B** | **Chromium + WebKit** (Playwright / pest-plugin-browser) | `composer test:browser` が両レーンを実行する。カバーしているのは**秘匿の配線** (pagehide で秘匿属性が付き実描画が止まる / pageshow でプローブが走り秘匿が解ける) と**通常遷移で誤発火しないこと**。**bfcache 復元そのものは下記の理由でカバーできていない** |
+| **自動回帰テスト (恒久) — 経路 C** | **Chromium + WebKit** (`tests/Browser/InertiaHistoryRestoreAfterLogoutTest.php`) | 経路 C は bfcache と無関係の Inertia 内部機構であり、**両レーンで実際に再現する = skip しない恒久回帰**。ログイン → ログアウト → 戻る で PII が **一度も DOM に出現せず** `/login` に倒れることを固定する。空振り防止の正のコントロールとして (i) `window.history.state.page instanceof ArrayBuffer` (暗号化が実際に効いている) (ii) 一連の操作で JS 実行コンテキストが生存 (= 本当に same-document の popstate 復元) を必須検証にしている |
+| **契約テスト (Feature/Architecture) — 経路 C** | `tests/Feature/Security/InertiaHistoryGuardTest.php` / `tests/js/architecture/logout-call-site-inventory.test.ts` | page ペイロードの `encryptHistory` / `clearHistory` 契約と、ログアウト着地が Inertia 応答であること、ログアウト導線が Inertia visit 一本であること (deny-by-default) を固定 |
 | **ユニット (vitest)** | `tests/js/lib/bfcache-guard.test.ts` | guard の分岐 (persisted 有無 / 秘匿属性 有無 / プローブ成功・失敗・エラー / 再試行) と負のコントロールを固定。**復元シナリオの分岐ロジックはここが唯一の恒久回帰** |
 | **実機受入確認 (手動)** | **iOS Safari 実機** (PWA standalone 含む) | **「恒久テスト済み」とは表現しない**。実施したら**日時・端末・OS バージョン・結果**を devnotes に記録する |
 
@@ -57,13 +77,16 @@ ### bfcache 復元が自動回帰でカバーできていない理由 (実測)
 
 ### 実機受入確認の再確認条件
 
-一度きりの確認では陳腐化する。**以下のいずれかに変更が入ったら再実施する**:
+一度きりの確認では陳腐化する。**以下のいずれかに挙動変更が入ったら再実施する**:
 
 - `resources/js/lib/bfcache-guard.ts` (bfcache guard 本体)
 - `resources/css/app.css` の秘匿オーバーレイのスタイル (`#bfcache-guard-overlay` 周辺)
 - プローブ endpoint (`routes/web.php` の `session.status` /
   `App\Http\Controllers\Auth\SessionStatusController` / `SessionStatusResource`)
 
+**docblock / コメントのみの変更はトリガに当たらない** (挙動が変わっていないため)。
+不要な実機再確認を誘発しないよう、トリガは「挙動変更」に限る。
+
 記録先: `devnotes/<日付>-<topic>/` に日時・端末・iOS バージョン・実施シナリオ・結果を残す。
 **本書には「いつ・何を確認したか」を書かない** (記録の二重管理を作らない)。
 
@@ -83,6 +106,26 @@ ## 未対応事項 (誤読を防ぐため明示列挙する)
   **「iOS Safari 対応を実証した」と言い換えない**。
 - **Firefox / Edge のブラウザ自動テストレーンは持たない** (Firefox は `no-store` で
   bfcache 格納自体を拒否するため、本件のリスク面では最も安全側)。
+- **経路 C は「`clearHistory: true` を含む Inertia page をクライアントが適用したタブ」のみを保証する**
+  (受信ではなく適用)。JSON 204 で完結するログアウト (Fortify 既定の `wantsJson()` 分岐) では、
+  次の Inertia page を適用するまでクライアントの履歴暗号鍵は残る。
+  現行の `/logout` 導線は 2 箇所ともに Inertia visit のため実運用では条件を満たすが、
+  非 Inertia のログアウト導線を新設すると保証が外れる
+  (`tests/js/architecture/logout-call-site-inventory.test.ts` が deny-by-default で固定)。
+- **上記を満たしたタブ以外は保証外**。Inertia の履歴暗号鍵は
+  `sessionStorage` = タブ単位のため、同一ブラウザの**別タブ**に残った履歴は復号できてしまう。
+  すなわち **別タブでは、現在表示されていない過去の PII が履歴から再表示され得る**
+  (例: タブ B でメンバー一覧を見た後に公開ページへ遷移 → タブ A でログアウト →
+  端末を引き継いだ第三者がタブ B で「戻る」)。塞ぐには全タブへのセッション失効伝播
+  (BroadcastChannel 等) が要るため本件では扱わない。**既知の残存リスク**。
+- **セッション期限切れ / 他デバイスからの強制ログアウトは経路 C の保証外**。
+  ブラウザに `clearHistory` が届かないため鍵が残り、履歴は復号できる。
+- **非 Inertia 面 (Filament `/admin`) は経路 B / C の保証外**。独自 middleware stack を持ち
+  web グループを経由せず、Inertia でも描画されない。
+- **非セキュアコンテキスト (`http://` の LAN IP 等) では経路 C が degrade する**。
+  `window.crypto.subtle` が無い環境で Inertia は履歴を平文で保存する (`console.warn` のみ)。
+  撮影 PWA は `getUserMedia` / Service Worker のためセキュアコンテキスト必須であり、
+  degrade するのは中核機能が既に動かない環境に限られる。
 
 ## Target — 到達目標 (未達)
 
diff --git a/docs/testing-browser.md b/docs/testing-browser.md
index 8503330..c9febbd 100644
--- a/docs/testing-browser.md
+++ b/docs/testing-browser.md
@@ -49,6 +49,17 @@ ### ブラウザレーン (Chromium + WebKit)
 再現できる環境では `pageshow.persisted === true` を観測できない限り**失敗する**
 正のコントロールが効く。保証範囲の全体像は `docs/supported-browsers.md`。
 
+**一方 Inertia SPA のクライアント履歴復元 (`popstate`) は両レーンで再現できる。**
+これは bfcache とは無関係の Inertia 内部機構 (history 暗号化 + `clearHistory`) であり、
+ブラウザの page cache を必要としないためである。
+`tests/Browser/InertiaHistoryRestoreAfterLogoutTest.php` は
+**skip 判定を持たない恒久回帰**で、ログアウト後の「戻る」で PII が一度も DOM に出現せず
+`/login` に倒れることを Chromium / WebKit の両レーンで固定する
+(空振り防止の正のコントロール: history state が `ArrayBuffer` であること /
+一連の操作で JS 実行コンテキストが生存していること)。
+bfcache 側 (`AuthenticatedPageBfcacheTest`) と同じファイルに混ぜないのは、
+**再現可否が正反対**で「担保されていない」ことの表明が薄まるため。
+
 pest 終了後に orphan 化した `playwright run-server` (node) はスクリプトが実行前後に掃除する。
 
 ### 前提
diff --git a/resources/js/lib/bfcache-guard.ts b/resources/js/lib/bfcache-guard.ts
index 19c0e04..97c038e 100644
--- a/resources/js/lib/bfcache-guard.ts
+++ b/resources/js/lib/bfcache-guard.ts
@@ -29,6 +29,20 @@
  *
  * 秘匿は DOM 表示に限定する (属性付与 + CSS)。DOM ツリーの破棄・再構築はしない。
  * 見た目 (オーバーレイ / 非表示) のスタイルは resources/css/app.css 側に置く (DS token 経由)。
+ *
+ * **担当範囲**: 本 guard が守るのは **Safari の真の bfcache 復元 (pagehide/pageshow)** だけ。
+ * Inertia SPA のクライアント履歴復元 (popstate) は本 guard を発火させないため、
+ * そちらは Inertia 公式機構 (bootstrap/app.php の EncryptHistory +
+ * LogoutResponse の Inertia::clearHistory()) が担当する (bug-hunt F-4-01)。
+ * ここに popstate フックを足さないこと — 同一問題の二重実装になる。
+ *
+ * なお Inertia も pageshow(persisted) で history を復号し直すが、それは**非同期**であり、
+ * 復元 DOM は既に描画されている。「検証完了まで秘匿する」という本 guard の要件は
+ * pagehide で**同期的に**秘匿属性を立てる本実装でしか満たせない (公式機構に pagehide は無い)。
+ * ログアウト後の真の bfcache 復元では両者が同時に走るが、着地はどちらも /login で一致する
+ * (guard の hard navigation が Inertia の XHR 再訪問を打ち切るだけ)。
+ *
+ * 3 経路 × 3 枚の網の全体像は docs/supported-browsers.md が正本。
  */
 
 /** documentElement に付ける秘匿属性 = bfcache 復元マーカー兼 CSS スイッチ。 */
diff --git a/tests/Browser/InertiaHistoryRestoreAfterLogoutTest.php b/tests/Browser/InertiaHistoryRestoreAfterLogoutTest.php
new file mode 100644
index 0000000..73ae595
--- /dev/null
+++ b/tests/Browser/InertiaHistoryRestoreAfterLogoutTest.php
@@ -0,0 +1,200 @@
+<?php
+
+declare(strict_types=1);
+
+use Pest\Browser\Api\PendingAwaitablePage;
+
+/*
+|--------------------------------------------------------------------------
+| Inertia SPA の履歴復元に対する PII 秘匿 (bug-hunt F-4-01) の Browser E2E
+|--------------------------------------------------------------------------
+|
+| bug-hunt shard-4 の再現手順そのもの:
+|   ログイン → 認証済み画面 → SPA でログアウト → ブラウザバック
+|   → 期待: 認証済み画面 (PII) が **一度も描画されず** /login に倒れる
+|
+| 経路の区別 (docs/supported-browsers.md が正本):
+|   - 経路 B (Safari の真の bfcache) は本ハーネスでは再現できない
+|     → tests/Browser/AuthenticatedPageBfcacheTest.php が skip 判定付きで扱う
+|   - 経路 C (Inertia の popstate 履歴復元) は **bfcache とは無関係**の Inertia 内部機構であり、
+|     Chromium / WebKit の両レーンで再現する → **skip しない。恒久回帰である**
+|
+| テストは 3 本に分ける (実装前の red 確認をコード改変なしで行うため):
+|   1. 「history state が暗号化されている」— 施策 1 の単独検証。実装前は平文で fail
+|   2. 「ログアウト後の戻るで PII が復元されない」— F-4-01 の再現。実装前は PII 復元で fail
+|      (暗号化が degrade しても PII が復元されて落ちるため、単体でも空振りしない)
+|   3. 「ログイン中の戻るは client-side で完結する」— 後退検出 (負のコントロール)
+|
+| 正のコントロール (空振り green を作らない):
+|   a. 一連の操作の間 JS 実行コンテキストが生存していること
+|      = 本当に same-document の SPA popstate であり、フルリロードで空振りしていない
+|   b. 「戻る」の前に仕込んだ MutationObserver が PII 文字列の DOM 出現を一度も記録しないこと
+|      = 終状態だけでなく **途中フレームでも DOM に現れていない** ことの機械的保証
+|      (「ペイントされていない」ではなく「DOM に出現していない」の検証。
+|       本件の PII は Svelte の通常テキストノードとして描画されるため実用上十分)
+|
+| 実行: composer test:browser (Chromium / WebKit の両レーン)。
+| 前提: pnpm build 済み。
+*/
+
+/**
+ * PII 文字列が **途中フレームでも** DOM に現れたかを記録する監視を仕込む。
+ *
+ * 要件は「復元時に PII を一度も描画しない」であり、終状態だけを見る assertDontSee では
+ * 「一瞬出て消えた」を取り逃す。
+ *
+ * 検出するのは正確には「**DOM に PII 文字列が出現したか**」であり、
+ * 「ペイントされたか」ではない。本件の PII は Svelte が通常のテキストノードとして描画するため、
+ * DOM 出現の検出で実用上十分 (DOM に一度も現れなければペイントもされない)。
+ *
+ * 同一タスク内で「追加 → 削除」されると callback 時点の `innerText` には残らないため、
+ * 現在の DOM だけでなく **MutationRecord 自体** も検査する:
+ *   (1) 現在の document.body.innerText
+ *   (2) 各 record の addedNodes[].textContent
+ *   (3) characterDataOldValue: true を指定した上での record.oldValue (テキスト置換)
+ * 観測開始時点の初期状態も 1 度チェックする。
+ */
+function inertiaHistoryWatchForPii(PendingAwaitablePage $page, string $needle): void
+{
+    $encoded = json_encode($needle, JSON_THROW_ON_ERROR);
+
+    $page->script(<<<JS
+        (() => {
+            const needle = {$encoded};
+            const hit = (text) => typeof text === 'string' && text.includes(needle);
+
+            window.__piiSeen = hit(document.body?.innerText);
+
+            const observer = new MutationObserver((records) => {
+                if (hit(document.body?.innerText)) { window.__piiSeen = true; return; }
+                for (const record of records) {
+                    if (hit(record.oldValue)) { window.__piiSeen = true; return; }
+                    for (const node of record.addedNodes) {
+                        if (hit(node.textContent)) { window.__piiSeen = true; return; }
+                    }
+                }
+            });
+            // 監視対象は documentElement (body 自体が置換されても observer が外れない)。
+            // 判定側は live 参照の document.body?.innerText を使う
+            // (documentElement.textContent にすると <script> 等の非表示テキストまで拾い
+            //  偽陽性で flaky になるため、監視対象と判定対象は分ける)。
+            observer.observe(document.documentElement, {
+                childList: true,
+                subtree: true,
+                characterData: true,
+                characterDataOldValue: true,
+            });
+            return true;
+        })()
+    JS);
+}
+
+/** ブラウザ側の条件が満たされるまで待つ (plugin の assertion は auto-retry しない)。 */
+function inertiaHistoryWaitUntil(
+    PendingAwaitablePage $page,
+    string $expression,
+    string $message,
+    int $attempts = 100,
+): void {
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
+test('認証済み画面の history state が暗号化されている', function (): void {
+    // 施策 1 (EncryptHistory) が実ブラウザで効いていることの単独検証。
+    // ※ 「Inertia は暗号化した page を ArrayBuffer で history state に入れる」という
+    //    @inertiajs/core の実装前提に依存する。Inertia を更新したらここを見直すこと。
+    // ※ 再現テスト側から分離してあるので、実装前の red 確認を
+    //    「一時的にコメントアウトする」手作業なしに行える。
+    [, $owner] = createOrganizationWithOwner();
+    $this->actingAs($owner);
+
+    $page = visit('/dashboard');
+    $page->assertSee($owner->name);
+
+    inertiaHistoryWaitUntil(
+        $page,
+        'window.history.state?.page instanceof ArrayBuffer',
+        'history state が暗号化されていない (EncryptHistory 未適用、または crypto.subtle 不在)',
+    );
+});
+
+test('ログアウト後のブラウザバックで Inertia 履歴から PII が復元されない', function (): void {
+    [, $owner] = createOrganizationWithOwner();
+    $this->actingAs($owner);
+
+    // bug-hunt F-4-01 の再現手順: /dashboard → /manage/users → ログアウト → 戻る
+    //
+    // ※ サイドバーの nav item は素の <a href> (SidebarNavItems.svelte) であり
+    //   Inertia Link ではない = ここは cross-document 遷移。SPA の pushState エントリを
+    //   作るのは **ログアウト自身** (router.post('/logout') → 302 追従 → '/' へ pushState) で、
+    //   その 1 つ前 (= /manage/users の document エントリ) へ戻るのが本件の popstate 復元。
+    $page = visit('/dashboard');
+    $page->assertSee($owner->name);
+    $page->click('メンバー');
+    inertiaHistoryWaitUntil($page, "window.location.pathname === '/manage/users'", 'メンバーへ遷移しない');
+    $page->assertSee($owner->name); // メンバー一覧に PII (氏名) が出ている
+
+    // 正のコントロール: JS 実行コンテキストの生存マーカー (フルリロードで消える)
+    $page->script("window.__inertiaHistoryProbe = 'alive'; true");
+
+    // SPA でログアウト (AppLayout の router.post('/logout') = F-4-01 の再現手順)
+    $page->click('@app-user-menu-toggle');
+    $page->click('@logout-button');
+    inertiaHistoryWaitUntil($page, "window.location.pathname === '/'", 'ログアウト後に LP へ着地しない');
+    $page->assertScript('window.__inertiaHistoryProbe', 'alive'); // ここまで same-document
+
+    // 「戻る」の前に瞬間露出の監視を仕込む (終状態の assertDontSee では
+    // 「一瞬表示されて消えた」を取り逃すため)
+    inertiaHistoryWatchForPii($page, $owner->name);
+
+    // ブラウザバック = Inertia の popstate 履歴復元
+    $page->back();
+
+    inertiaHistoryWaitUntil(
+        $page,
+        "window.location.pathname === '/login'",
+        'ログアウト後の戻るで /login に倒れない',
+    );
+
+    // 本丸: 復元 → login までの間、PII が **一度も** 描画されていない
+    // (復号失敗時はコンポーネント swap 自体が起きない、という設計の機械的な証明)
+    $page->assertScript('window.__piiSeen', false);
+
+    // popstate → 再問い合わせ → login まで same-document で完結している
+    // (= 本当に SPA 履歴復元経路を通った。フルリロードなら消えている)
+    $page->assertScript('window.__inertiaHistoryProbe', 'alive');
+    $page->assertDontSee($owner->name)->assertNoJavaScriptErrors();
+});
+
+test('ログイン中の戻るは従来どおり client-side で完結する (誤発火しない)', function (): void {
+    [, $owner] = createOrganizationWithOwner();
+    $this->actingAs($owner);
+
+    $page = visit('/dashboard');
+    $page->assertSee($owner->name);
+    $page->script("window.__inertiaHistoryProbe = 'alive'; true");
+
+    // Inertia Link (Dashboard.svelte の TextLink「通知を確認」) = SPA visit。
+    // サイドバーの nav item は素の <a href> で cross-document のため、
+    // 「client-side で完結する」ことを検証する本テストでは使えない。
+    $page->click('通知を確認');
+    inertiaHistoryWaitUntil($page, "window.location.pathname === '/notifications'", '通知へ SPA 遷移しない');
+    // SPA visit なので実行コンテキストは維持されている (前提の確認)
+    $page->assertScript('window.__inertiaHistoryProbe', 'alive');
+
+    $page->back();
+
+    inertiaHistoryWaitUntil($page, "window.location.pathname === '/dashboard'", '戻るで dashboard に戻らない');
+    // 復号に成功する = 再取得も hard reload も起きない (撮影 PWA の制約を壊さない)
+    $page->assertScript('window.__inertiaHistoryProbe', 'alive');
+    $page->assertSee($owner->name)->assertNoJavaScriptErrors();
+});
diff --git a/tests/Feature/Security/InertiaHistoryGuardTest.php b/tests/Feature/Security/InertiaHistoryGuardTest.php
new file mode 100644
index 0000000..f8908a8
--- /dev/null
+++ b/tests/Feature/Security/InertiaHistoryGuardTest.php
@@ -0,0 +1,146 @@
+<?php
+
+declare(strict_types=1);
+
+use Illuminate\Testing\TestResponse;
+
+/*
+ * Inertia history guard (bug-hunt F-4-01) の契約検証。
+ *
+ * 契約:
+ *  - web グループの Inertia\Middleware\EncryptHistory により、Inertia 応答の page に
+ *    `encryptHistory: true` が載る (認証済み / 公開の区別なくグローバル適用)。
+ *  - ログアウト応答は Inertia::clearHistory() を発火し、**着地の Inertia 応答**の page に
+ *    `clearHistory: true` が載る (着地が非 Inertia 化するとフラグが宙に浮き防御が消える)。
+ *  - 通常の応答には `clearHistory` が載らない (負のコントロール)。
+ *
+ * 目的は「ログアウト後の戻る」で Inertia のクライアント履歴から認証済み画面 (PII) が
+ * 復元されるのを防ぐこと。経路 A (HTTP キャッシュ / bfcache evict) は NoStoreCacheHeadersTest、
+ * 経路 B (Safari の真の bfcache) は tests/js/lib/bfcache-guard.test.ts が受け持つ。
+ */
+
+/**
+ * Inertia の root view から page オブジェクトを取り出す (Inertia 応答でなければ失敗させる)。
+ *
+ * @return array<string, mixed>
+ */
+function inertiaPagePayload(TestResponse $response): array
+{
+    $page = $response->viewData('page');
+
+    expect($page)->toBeArray('Inertia 応答ではない (clearHistory / encryptHistory を消費できない)');
+
+    /** @var array<string, mixed> $page */
+    return $page;
+}
+
+test('認証済み Inertia 応答の page に encryptHistory が載る', function (): void {
+    [, $owner] = createOrganizationWithOwner();
+
+    $response = $this->actingAs($owner)->get('/dashboard');
+
+    $response->assertOk();
+    expect(inertiaPagePayload($response))->toHaveKey('encryptHistory', true);
+});
+
+test('公開ページの Inertia 応答にも encryptHistory が載る (グローバル適用)', function (): void {
+    // 認証済み route への限定適用にしない設計判断をテストに刻む
+    // (限定適用に変えるなら inventory と Architecture テストをセットで作ること)。
+    $response = $this->get('/');
+
+    $response->assertOk();
+    expect(inertiaPagePayload($response))->toHaveKey('encryptHistory', true);
+});
+
+test('通常の応答には clearHistory が載らない (負のコントロール)', function (): void {
+    [, $owner] = createOrganizationWithOwner();
+
+    $response = $this->actingAs($owner)->get('/dashboard');
+
+    expect(inertiaPagePayload($response))->not->toHaveKey('clearHistory');
+});
+
+test('ログアウトの着地 Inertia 応答に clearHistory が載る', function (): void {
+    [, $owner] = createOrganizationWithOwner();
+
+    $logout = $this->actingAs($owner)->post('/logout');
+    $logout->assertRedirect(route('home'));
+
+    $landing = $this->get((string) $logout->headers->get('Location'));
+
+    $landing->assertOk();
+    // 着地が Inertia 応答でなければ inertiaPagePayload が失敗する = 契約違反を検出
+    expect(inertiaPagePayload($landing))->toHaveKey('clearHistory', true);
+    $this->assertGuest();
+});
+
+test('clearHistory は 1 度きりで、次の Inertia 応答には持ち越さない', function (): void {
+    [, $owner] = createOrganizationWithOwner();
+
+    $this->actingAs($owner)->post('/logout');
+    $this->get(route('home'));
+
+    // pull 済みなので 2 度目には載らない (無関係なページで履歴が飛ぶ事故を防ぐ)。
+    // 依存 route は「ログアウト着地 = Inertia 応答」の 1 本に集約する
+    // (他 route の Inertia 性に依存すると、その route の変更で false negative になる)。
+    expect(inertiaPagePayload($this->get(route('home'))))->not->toHaveKey('clearHistory');
+});
+
+test('実運用経路 (X-Inertia visit) でも着地の page JSON に clearHistory が載る', function (): void {
+    // 実ブラウザのログアウトは router.post('/logout') = X-Inertia 付き XHR。
+    // 302 を XHR が追従し、着地は **JSON の page オブジェクト**になる。
+    // root view 経由 (viewData) だけでなく、この実経路も直接固定する。
+    // ※ 「XHR が 302 を追従すること」自体はブラウザ / axios の責務であり、
+    //    ここでは追従後の最終リクエストが実ブラウザと同じ形になることを検証する
+    //    (追従を含む一気通貫は Browser テストが担う)。
+    [, $owner] = createOrganizationWithOwner();
+    $this->actingAs($owner);
+
+    // Inertia の asset version は HandleInertiaRequests::version() が
+    // **リクエスト処理中に**設定する (Middleware.php L112-114) ため、
+    // リクエスト前に Inertia::getVersion() を読むと空になり得て 409 (version mismatch) を招く。
+    // サーバ応答が自己申告した version をそのまま使う。
+    // ※ ResponseFactory::render() は Response に getVersion(): string を渡すため
+    //    page.version は常に string (空文字はあり得る)。前提を明示 assert する。
+    $version = inertiaPagePayload($this->get('/dashboard'))['version'];
+    expect($version)->toBeString();
+
+    $inertiaHeaders = ['X-Inertia' => 'true'];
+    if ($version !== '') {
+        // 空のときはヘッダ自体を付けない (実ブラウザの挙動に揃える)
+        $inertiaHeaders['X-Inertia-Version'] = $version;
+    }
+
+    $this->withHeaders($inertiaHeaders)
+        ->post('/logout')
+        ->assertRedirect(route('home'));
+
+    $this->withHeaders($inertiaHeaders)
+        ->get(route('home'))
+        ->assertOk()
+        ->assertHeader('X-Inertia', 'true')
+        ->assertJson(['clearHistory' => true]);
+});
+
+test('JSON クライアントのログアウトは 204 のまま (既定挙動の維持)', function (): void {
+    [, $owner] = createOrganizationWithOwner();
+
+    $this->actingAs($owner)
+        ->postJson('/logout')
+        ->assertNoContent();
+
+    $this->assertGuest();
+});
+
+test('JSON ログアウトでもフラグは積まれ、次の Inertia 応答で clearHistory が消費される', function (): void {
+    // clearHistory は X-Inertia の有無で分岐しない (LogoutResponse docblock の根拠 1)。
+    // ※ これは「JSON logout 経路の履歴復元が安全」であることの証明**ではない**。
+    //    204 応答ではクライアント鍵は消えず、次の Inertia page を適用するまで残る。
+    //    経路 C の保証条件は
+    //    「clearHistory: true を含む Inertia page をクライアントが適用したタブ」。
+    [, $owner] = createOrganizationWithOwner();
+
+    $this->actingAs($owner)->postJson('/logout')->assertNoContent();
+
+    expect(inertiaPagePayload($this->get(route('home'))))->toHaveKey('clearHistory', true);
+});
diff --git a/tests/js/architecture/logout-call-site-inventory.test.ts b/tests/js/architecture/logout-call-site-inventory.test.ts
new file mode 100644
index 0000000..42bdc5a
--- /dev/null
+++ b/tests/js/architecture/logout-call-site-inventory.test.ts
@@ -0,0 +1,89 @@
+import { describe, it, expect } from "vitest";
+import fs from "fs/promises";
+import path from "path";
+
+/**
+ * ログアウト導線が Inertia visit 一本であることを deny-by-default で固定する。
+ *
+ * 経路 C (Inertia history 暗号化 + ログアウト時の履歴鍵破棄。bug-hunt F-4-01) の保証は
+ * 「clearHistory: true を含む Inertia page をクライアントが適用すること」に乗っている。
+ * JSON 204 で完結する logout (fetch/axios) を足すと、鍵が消えないまま画面が残り、
+ * ブラウザバックで PII が復元されうる。
+ *
+ * 新しいログアウト導線を足したい場合は、それが Inertia visit (router.post) であることを
+ * 確認した上で inventory に登録すること。docs/supported-browsers.md の経路 C の記述も更新する。
+ *
+ * 既知の限界: 検出は **文字列リテラル `"/logout"`** に限定される。将来 `route("logout")` の
+ * ような名前解決ヘルパを導入すると検出外になるため、その際は本テストのパターンも同時に更新する。
+ */
+
+const JS_ROOT = path.resolve(__dirname, "../../../resources/js");
+
+/**
+ * `/logout` を参照してよいファイル (resources/js からの相対パス)。
+ * 現状 2 箇所あり、いずれも router.post = Inertia visit
+ * (AppLayout: 通常画面のユーザーメニュー / VerifyEmail: メール認証待ち画面の離脱導線)。
+ */
+const LOGOUT_CALL_SITE_INVENTORY: readonly string[] = [
+  "components/templates/AppLayout.svelte",
+  "pages/Auth/VerifyEmail.svelte",
+] as const;
+
+const LOGOUT_PATH_PATTERN = /["'`]\/logout["'`]/;
+/** 非 Inertia 経路 (これが同一ファイルにあると 204 完結の logout になりうる)。 */
+const NON_INERTIA_CLIENT_PATTERN = /\b(fetch|axios)\s*\(/;
+
+const SOURCE_EXTENSIONS: readonly string[] = [".svelte", ".ts"] as const;
+
+/** resources/js 配下の .svelte / .ts を再帰列挙する (svg-inline-allowlist.test.ts と同じ様式)。 */
+const listSourceFiles = async (dir: string): Promise<string[]> => {
+  const entries = await fs.readdir(dir, {
+    recursive: true,
+    withFileTypes: true,
+  });
+  const files: string[] = [];
+  for (const entry of entries) {
+    if (!entry.isFile()) continue;
+    if (!SOURCE_EXTENSIONS.includes(path.extname(entry.name))) continue;
+    const parent =
+      (entry as unknown as { parentPath?: string }).parentPath ?? dir;
+    files.push(path.join(parent, entry.name));
+  }
+  return files;
+};
+
+describe("logout call site inventory", () => {
+  it("resources/js 配下で /logout を叩くのは inventory 登録分のみ", async () => {
+    const files = await listSourceFiles(JS_ROOT);
+
+    const offenders: string[] = [];
+    for (const file of files) {
+      const content = await fs.readFile(file, "utf8");
+      if (!LOGOUT_PATH_PATTERN.test(content)) continue;
+
+      const rel = path.relative(JS_ROOT, file).split(path.sep).join("/");
+      if (!LOGOUT_CALL_SITE_INVENTORY.includes(rel)) offenders.push(rel);
+    }
+
+    expect(
+      offenders,
+      `未登録のログアウト導線が見つかりました。Inertia visit (router.post) であることを確認して inventory へ登録し、docs/supported-browsers.md の経路 C も更新してください:\n${offenders.join("\n")}`,
+    ).toEqual([]);
+  });
+
+  it("inventory 登録ファイルは Inertia visit (router.post) でログアウトする", async () => {
+    for (const rel of LOGOUT_CALL_SITE_INVENTORY) {
+      const content = await fs.readFile(path.join(JS_ROOT, rel), "utf8");
+
+      // Inertia visit であること (= 着地の Inertia page を適用し clearHistory が効く)
+      expect(content, `${rel} が router.post('/logout') を使っていない`).toMatch(
+        /router\.post\(\s*["'`]\/logout["'`]/,
+      );
+      // 同一ファイルに fetch/axios による非 Inertia 経路を持ち込まない
+      expect(
+        NON_INERTIA_CLIENT_PATTERN.test(content),
+        `${rel} に fetch/axios がある。logout が 204 完結の非 Inertia 経路になっていないか確認すること`,
+      ).toBe(false);
+    }
+  });
+});
```
