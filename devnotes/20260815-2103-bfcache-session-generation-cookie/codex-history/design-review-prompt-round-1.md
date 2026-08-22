【アプリの使命 (North Star) — AGENTS.md より】

**AI-CUE** は、現場に既にある**作業手順書(SOP)を起点に**、AI が撮るべきカットを設計した**動画シナリオ**を生成し、そのシナリオを**スマホ(PWA)でナビゲーション撮影**することで、専門知識ゼロの現場作業者でも**標準化されたマニュアル動画**を作れるようにする。

- 「思考ゼロ・編集ゼロ」— 台本作成・撮影判断・編集の 3 ハードルを AI とナビ撮影で肩代わりする。
- 競合(OJT を撮って形式化する tebiki)と異なり、**標準作業を起点に AI が教材設計し撮影を指示する**。
- 熟練者の暗黙知を動画マニュアルという形式知へ変換する装置（SECI）。

> **v1 スコープ**: 字幕のみ / 撮影は PWA(同一オリジン・セッション認証) / 動画合成は自前 ffmpeg / 単一 Default Project。

【禁止事項 — AGENTS.md より】

1. テストなしの実装完了報告(不変条件は対応する Architecture/Feature テストへの登録まで含めて「実装済み」)
2. PHPStan エラーの widen・baseline 化
3. dev DB への破壊操作をエージェント判断で実行すること
4. `response()->json()` の直書き(DTO / JsonResource / Inertia を使う)
5. LLM 呼び出しの Prism 直呼び
6. prompt 文字列のコード直書き
7. 操作系 POST の応答での `redirect()->intended()`
8. 必須条件未充足を理由にボタンを disabled にする UI
9. Artifact の使用

【セキュリティ不変条件 (抜粋。アプリ都合で緩めない)】
- 層 2 (テナント境界 404) は層 3 (認可 403) より前。実行順の正本は `bootstrap/app.php` の priority list であり route の宣言順ではない
- 変更系 route は認可を通るか exemption inventory へ登録 (deny-by-default)
- キャッシュに入れるのは素のデータだけ

【思考原則 — 全議論に適用】
まず仮説を立てろ。何を検証したいのか、なぜそう考えるのか、どうなれば成功と判断するのかを明確にしてから手を動かせ。

データに真摯に向き合え。想定外のパターンも判断材料になる。数値を見て即座に閾値を弄るな。

先人の知恵を探せ。乗るべき巨人の肩があるなら乗れ。

機能の名前に立ち返れ。名前はその機能が果たすべき役割を示している。

仕組みが機能していない段階で値を弄るな。方向性が間違っているなら設計そのものを見直せ。

【ツール使用制限】
コマンド実行・ファイル書き込みは一切行わず、提供されたテキストの分析に集中すること。ファイル読み込みは許可。

---

あなたは経験豊富なWebアプリケーションアーキテクトです。Laravel + Svelte アプリケーション改善の詳細設計をレビューしてください。

【前提環境】
- PHP 8.4 + Laravel 12 + Svelte 5 + Inertia.js + TypeScript
- PHPStan level 10
- Pestテストフレームワーク
- DTO + JsonResource パターン
- Laratrust RBAC（Organization → Team → Project階層）

【レビュー観点】
1. コードの正確性（ロジックエラー、エッジケース、null安全性）
2. 既存コードとの整合性（命名規約、パターン、API）
3. PHPStan level 10 適合性
4. テスト計画の網羅性
5. DTO/JsonResource パターンの遵守
6. Inertia Props vs API Responseの使い分け
7. 副作用・後退リスク
8. 波及変更の網羅性（TypeScript型定義、API Resource、テストが変更対象に含まれているか）
9. セキュリティ（認可チェック、入力バリデーション、OWASP Top 10、セキュリティ不変条件）
10. DESIGN.md準拠（UI/frontend 変更を含む場合）
11. Atomic Design準拠（UI/frontend 変更を含む場合）

【出力形式】
- 各施策ごとに判定: APPROVE / REQUEST_CHANGES
- 指摘は [Critical] [Warning] [Suggestion] で分類
- Critical/Warning には必ず修正案を添える
- 全体判定: APPROVED / CHANGES_REQUESTED
- 日本語で出力

---

## 詳細設計書

# 詳細設計: bfcache-session-generation-cookie

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
5. LLM 呼び出しの Prism 直呼び
6. prompt 文字列のコード直書き
7. 操作系 POST の応答での `redirect()->intended()`
8. 必須条件未充足を理由にボタンを disabled にする UI
9. Artifact の使用

### コーディングルール

- **PHPStan level 10** 必須（`composer phpstan`）
- **Pest**（`composer test`）/ **RefreshDatabase** はグローバル適用（個別 `DatabaseTransactions` 禁止）
- テストデータは Factory 生成
- **DTO + JsonResource** パターン
- `declare(strict_types=1)` + 日本語コメント
- フロントは Svelte 5 runes + DS token/ramp のみ、component 階層は単方向 import
- PHP 8.4 + Laravel 12 + Svelte 5 + Inertia.js + TypeScript

## 概念設計リファレンス

[conceptual-design.md](./conceptual-design.md) （Codex 概念レビュー Round 2 で APPROVED）

## 用語（本書で使う言葉の定義）

| 語 | 意味 |
|---|---|
| **セッション世代の印 (以下「印」)** | いまのセッションを一意に指す短い文字列。セッション ID から鍵付きハッシュで導出する 32 文字の 16 進文字列 |
| **描画世代** | いま画面に出ている内容が、どのセッション世代の応答で来たか。Inertia の応答が内容と同じ 1 通で運ぶ |
| **現世代** | サーバがその要求の時点のセッション ID から導出した印 |
| **世代 cookie** | 現世代の写しを画面側へ届ける cookie。画面側から読める (暗号化しない) |

## 施策一覧

| # | 施策名 | 変更ファイル | 優先度 |
|---|--------|------------|--------|
| S1 | 印の供給元と世代 cookie の発行 | `app/Support/Auth/SessionEpoch.php` (新規) / `app/Http/Middleware/IssueSessionEpochCookie.php` (新規) / `bootstrap/app.php` / `tests/Support/Routing/MiddlewareShortCircuitInventory.php` / `tests/Architecture/TenantBoundaryOrderingTest.php` | 最高 |
| S2 | 描画世代を Inertia 共有 prop で配る | `app/Http/Middleware/HandleInertiaRequests.php` / `resources/js/lib/shared-props.ts` | 最高 |
| S3 | プローブに世代の照合を足す | `app/Http/Controllers/Auth/SessionStatusController.php` / `app/DataTransferObjects/Auth/SessionStatusDto.php` / `app/Http/Resources/Auth/SessionStatusResource.php` | 最高 |
| S4 | ガードに同期判定を前置し開示条件を厳格化 | `resources/js/lib/bfcache-guard.ts` / `resources/js/app.ts` | 最高 |
| S5 | 検証ページの状態語彙を追随させる | `resources/js/lib/debug/bfcache-trial.ts` / `resources/js/pages/Debug/BfcacheTrial.svelte` | 高 |
| S6 | 理由記述の差し替えと文書の更新 | `app/Http/Middleware/NoStoreCacheHeadersForAuthenticatedPages.php` / `docs/supported-browsers.md` | 高 |
| S7 | 契約ずれの検査と文書の期限検査 | `tests/Architecture/BfcacheGuardClientContractSyncTest.php` (新規) / `tests/Architecture/SupportedBrowsersDocFreshnessTest.php` (新規) | 中 |

S1〜S4 は 1 つの不変条件を成立させるための分割であり、**部分適用してはならない**
(S1 だけ入れると印は出るが誰も読まない、S4 だけ入れると印が無く常に読み直しになる)。

## 判定の全体像（実装前に固定する仕様）

復元 (`pageshow` で秘匿属性がある) 時の判定は次の 2 段。**開示に到達する経路はただ 1 本**である。

```
[同期判定 — 通信を待たない]
  描画世代 == null もしくは 世代 cookie == null もしくは 両者不一致
      → 秘匿を維持したまま「読み直し」(location.reload)      … 開示しない
  一致
      → プローブへ進む

[プローブ — 開示の唯一の根拠]
  authenticated && sessionEpochMatches  → 秘匿解除
  authenticated && !sessionEpochMatches → 秘匿を維持したまま「読み直し」
  !authenticated                        → 秘匿を維持したまま /login へ置換遷移
  応答が読めない (通信失敗 / 非 JSON / shape 不一致 / HTTP エラー)
                                        → 秘匿維持 + 再試行ボタン (自動再試行なし)
```

**保証の言い方 (誇張しない)**:

- 保証するのは「読み直しが完了して新しい文書が生成された場合、その文書は
  bfcache 復元マーカー (秘匿属性) を継承しない」ことまでである。
  読み直し自体が通信障害で完了しないことは塞がない (既存の `/login` 置換遷移も同じ性質)。
- したがって**読み直しは 1 つの文書につき高々 1 回**で、ループにはならない
  (読み直した先は復元ではないので秘匿属性が無く、ガードは何もしない)。
- サーバ側の照合は**要求ヘッダで運ばれた描画世代と現世代だけ**を見る。
  要求の Cookie ヘッダに載ってくる世代 cookie の値は**一致判定に使わない**。

---

## S1: 印の供給元と世代 cookie の発行

### 変更箇所

- 新規: `app/Support/Auth/SessionEpoch.php`
- 新規: `app/Http/Middleware/IssueSessionEpochCookie.php`
- `bootstrap/app.php` — web グループの append 列 / 優先順位一覧の鎖 / cookie 暗号化の除外
- `tests/Support/Routing/MiddlewareShortCircuitInventory.php` — 短絡分類へ登録
- `tests/Architecture/TenantBoundaryOrderingTest.php` — 検査 5 の完全一致列へ追加

### 波及変更

- TypeScript 型定義: cookie 名を読む定数を S4 で追加 (本施策では PHP 側のみ)
- API Resource/DTO: なし (S3 が担当)
- テストファイル: `TenantBoundaryOrderingTest` (完全一致列) / `MiddlewareShortCircuitInventory` /
  新規 `tests/Feature/Auth/SessionEpochCookieTest.php`

### 変更後コード

```php
<?php

declare(strict_types=1);

namespace App\Support\Auth;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Config;

/**
 * セッション世代の印 (bfcache 秘匿・再検証で使う) の単一の出所。
 *
 * 「印」は *いまのセッションを一意に指す短い文字列* で、セッション ID から
 * 鍵付きハッシュで導出する。**セッション ID そのものは画面側へ出さない**
 * (世代 cookie は画面側から読めるため、生の ID を載せると XSS で
 * セッションの乗っ取りに直結する)。
 *
 * 印が変わる契機はセッション ID が変わる契機と 1 対 1 である
 * (ログイン・ログアウト・セッション再生成)。これが「別の利用者の画面が
 * 復元された」ことを検出できる根拠になる。
 *
 * **APP_KEY を入れ替えると全ての印が一斉に変わる**。復元済み文書は
 * すべて読み直しへ倒れるだけで、開示側へは倒れない。
 */
final class SessionEpoch
{
    /** 画面側から読める cookie の名前 (暗号化の除外登録が必須)。 */
    public const COOKIE_NAME = 'session_epoch';

    /** プローブ要求が描画世代を運ぶヘッダの名前。 */
    public const HEADER_NAME = 'X-Session-Epoch';

    /** Inertia 共有 prop のキー (描画世代の運び方)。 */
    public const SHARED_PROP_KEY = 'sessionEpoch';

    /** 印の書式 (32 文字の 16 進小文字)。空文字と null を同一視しない。 */
    public const VALUE_PATTERN = '/^[0-9a-f]{32}$/';

    /** 同じ鍵を他用途と共有しないための区切り (用途の分離)。 */
    private const PURPOSE = 'bfcache-session-epoch:v1';

    /** セッション ID から印を導出する。 */
    public static function forSession(string $sessionId): string
    {
        $digest = hash_hmac('sha256', self::PURPOSE.'|'.$sessionId, Config::string('app.key'));

        return substr($digest, 0, 32);
    }

    /** その要求の現世代。session を持たない要求では null。 */
    public static function current(Request $request): ?string
    {
        if (! $request->hasSession()) {
            return null;
        }

        return self::forSession($request->session()->getId());
    }

    /** 書式が正しいか (長さ・文字種)。 */
    public static function isWellFormed(?string $value): bool
    {
        return $value !== null && preg_match(self::VALUE_PATTERN, $value) === 1;
    }

    /**
     * 描画世代と現世代が一致するか。
     *
     * **どちらかが無い / 書式が違うときは一致としない** (fail-closed)。
     * 比較は hash_equals で行い、値はログにも応答にも出さない。
     */
    public static function matches(?string $submitted, ?string $current): bool
    {
        if (! self::isWellFormed($submitted) || ! self::isWellFormed($current)) {
            return false;
        }

        return hash_equals((string) $current, (string) $submitted);
    }
}
```

```php
<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Support\Auth\SessionEpoch;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Cookie;
use Symfony\Component\HttpFoundation\Response;

/**
 * セッション世代の印を「画面側から読める cookie」として応答に載せる。
 *
 * 用途は 1 つだけで、bfcache から復元された画面が **通信を待たずに**
 * 「世代が変わっている」と気づけるようにすることである。
 * 開示 (秘匿の解除) の根拠にはならない — 開示はプローブ応答だけが決める
 * (resources/js/lib/bfcache-guard.ts / SessionStatusController)。
 *
 * 契約:
 *   - `$next` の**後**に、応答時点のセッション ID から導出する
 *     (ログイン・ログアウトでのセッション ID 再生成を同じ応答で拾うため)。
 *   - **未認証でも発行し、削除しない**。必ず現セッション由来の値で上書きする
 *     (「印が無い」状態を作らない = 画面側が欠落と失効を取り違えない)。
 *   - HttpOnly にしない (画面側が読む値であるため)。
 *     **セッション ID そのものではない**ので、読めても乗っ取りには使えない。
 *   - 暗号化の除外登録 (bootstrap/app.php) が必須。外すと画面側は復号できない
 *     文字列を読み、常に不一致 = 復元のたびに読み直しになる (静かな劣化)。
 *     この配線は Feature テストが平文値そのもので固定する。
 */
final class IssueSessionEpochCookie
{
    /**
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        $epoch = SessionEpoch::current($request);
        if ($epoch === null) {
            return $response;
        }

        $domain = config('session.domain');
        $path = config('session.path');
        $sameSite = config('session.same_site');

        $response->headers->setCookie(Cookie::create(
            name: SessionEpoch::COOKIE_NAME,
            value: $epoch,
            // ブラウザセッション限りにする (印は「いまの世代」の写しであり保存する値ではない)
            expire: 0,
            path: is_string($path) ? $path : '/',
            domain: is_string($domain) ? $domain : null,
            secure: (bool) config('session.secure'),
            httpOnly: false,
            raw: false,
            sameSite: is_string($sameSite) ? $sameSite : Cookie::SAMESITE_LAX,
        ));

        return $response;
    }
}
```

`bootstrap/app.php` の 3 箇所:

```php
$middleware->web(append: [
    // …既存…
    NoStoreCacheHeadersForAuthenticatedPages::class,
    // セッション世代の印を画面側へ配る (bfcache 復元時の同期判定用)。
    // 応答加工のみで短絡しない。順序の正本は下の priority list。
    IssueSessionEpochCookie::class,
    EncryptHistory::class,
    // …既存…
]);

// 優先順位一覧の鎖 (既存の 1 リンクを 2 リンクへ分割する)
[NoStoreCacheHeadersForAuthenticatedPages::class, IssueSessionEpochCookie::class],
[IssueSessionEpochCookie::class, EncryptHistory::class],

// 世代 cookie は画面側が読むため暗号化しない (唯一の除外)。
$middleware->encryptCookies(except: [
    SessionEpoch::COOKIE_NAME,
]);
```

### PHPStan適合チェック

- [x] 戻り値の型が明示されている (`?string` / `bool` / `Response`)
- [x] `config()` の戻り値 (mixed) は `is_string()` で narrowing、`app.key` は
      `Config::string()` で型付き取得する
- [x] DTO を返す箇所は無い (本施策は middleware と Support クラスのみ)
- [x] Generics の型パラメータなし

### テスト計画

- [ ] 新規 `tests/Feature/Auth/SessionEpochCookieTest.php`
  - 認証済み応答に世代 cookie が付く。**値が平文で `SessionEpoch::forSession(セッション ID)`
    と一致する** (= 暗号化の除外が効いている。ここが本施策で最も壊れやすい配線)
  - guest 応答にも付く (「無い」状態を作らない)
  - cookie 属性: `HttpOnly` でない / `SameSite` と `path` がセッション cookie と同じ
  - **ログイン応答の後**に得られる値が、ログイン前の値と異なる (セッション ID 再生成を拾う)
  - **ログアウト応答の後**に得られる値が、ログアウト前の値と異なる (削除ではなく上書き)
  - session を持たない route (stateless block) では cookie を付けない
- [ ] 新規 `tests/Unit/Support/Auth/SessionEpochTest.php`
  - 同じセッション ID からは同じ印、違う ID からは違う印
  - 印はセッション ID を部分文字列として含まない (生の ID を出さない)
  - `matches()`: 一致で true / 不一致・null・空文字・書式違い (33 文字・大文字・非 16 進) で false
- [ ] 既存 `tests/Architecture/TenantBoundaryOrderingTest.php` の検査 5 の
      `$webAppend` へ新 middleware を追加 (削除ではなく更新)
- [ ] `MiddlewareShortCircuitInventory` へ `false` (短絡しない) で登録

### リスク

- **優先順位一覧を触るため、テナント境界 404 の順序契約に影響しうる。**
  新 middleware は短絡しない (`$next` を必ず呼ぶ) ので存在オラクルにはならないが、
  検査 5 の完全一致列を更新しないと赤になる (= 検出される。無言では壊れない)。
- **cookie 暗号化の除外を忘れると静かに劣化する** (復元のたびに読み直し)。
  Feature テストで平文値そのものを固定して塞ぐ。
- `/admin` (Filament) は web グループを通らないため cookie も印も届かない。
  既知の非対称であり `docs/supported-browsers.md` に記載済み (S6 で再掲)。

---

## S2: 描画世代を Inertia 共有 prop で配る

### 変更箇所

- `app/Http/Middleware/HandleInertiaRequests.php` — `share()` に 1 キー追加
- `resources/js/lib/shared-props.ts` — 型と読み取り関数

### 波及変更

- TypeScript 型定義: `SharedProps` に `sessionEpoch: string | null`
- API Resource/DTO: なし
- テストファイル: 新規 `tests/Feature/Auth/SessionEpochSharedPropTest.php` /
  `tests/js/lib/shared-props.test.ts` (既存があれば追記)

### 変更後コード

```php
// HandleInertiaRequests::share() の戻り配列へ追加
// 描画世代: この応答の内容がどのセッション世代のものかを、内容と同じ 1 通で運ぶ。
// **常に載せる** (Inertia の部分再読み込みで省略されると印だけ古くなるため)。
// これを cookie から読む形にすると「内容は A・印は B」の取り違えが起きる。
SessionEpoch::SHARED_PROP_KEY => Inertia::always(SessionEpoch::current($request)),
```

```ts
/** サーバが配る描画世代の書式 (PHP の SessionEpoch::VALUE_PATTERN と対) */
const SESSION_EPOCH_PATTERN = /^[0-9a-f]{32}$/;

/**
 * 共有 props から描画世代を読む。**書式が違えば null に倒す**
 * (「読めない」は bfcache guard 側で「開示しない」に写る)。
 */
export function readSessionEpoch(props: unknown): string | null {
    if (typeof props !== "object" || props === null) return null;
    const value = (props as { sessionEpoch?: unknown }).sessionEpoch;
    if (typeof value !== "string") return null;
    return SESSION_EPOCH_PATTERN.test(value) ? value : null;
}
```

### PHPStan適合チェック

- [x] `share()` の戻り値型は既存の `array<string, mixed>` のまま
- [x] `Inertia::always()` は `AlwaysProp` を返す (mixed 配列の要素として整合)
- [x] `SessionEpoch::current()` は `?string`

### テスト計画

- [ ] 新規 `tests/Feature/Auth/SessionEpochSharedPropTest.php`
  - 認証済みの Inertia 応答の props に `sessionEpoch` があり、
    **同じ応答の世代 cookie と同値**である (2 経路が同じ出所から出ていること)
  - 部分再読み込み (`X-Inertia-Partial-Data` で別 prop だけを要求) でも
    `sessionEpoch` が載る (`Inertia::always()` の効果)
  - guest の Inertia 応答にも載る
- [ ] `tests/js/lib/shared-props.test.ts`: 正常値 / 欠落 / 型違い / 書式違い (大文字・33 文字) で
      `null` に倒れる

### リスク

- 共有 prop のキーが増えるため、`SharedProps` 型を使う画面の型検査に影響する
  (追加のみなので破壊はしない)。
- 印は秘密ではない (画面側から読める cookie と同値) が、**PII ではない**ことを
  レビューで確認する。ページ props に載るため Inertia の履歴にも入る。

---

## S3: プローブに世代の照合を足す

### 変更箇所

- `app/Http/Controllers/Auth/SessionStatusController.php`
- `app/DataTransferObjects/Auth/SessionStatusDto.php`
- `app/Http/Resources/Auth/SessionStatusResource.php`

### 波及変更

- TypeScript 型定義: 応答パーサを S4 で更新
- API Resource/DTO: 本施策そのもの (`authenticated` + `sessionEpochMatches` の 2 キー)
- テストファイル: 既存 `tests/Feature/Auth/SessionStatusProbeTest.php` の
  `assertExactJson` を**新契約へ更新**する (削除ではなく更新)。
  `tests/Browser/AuthenticatedPageBfcacheTest.php` /
  `tests/Browser/InertiaHistoryRestoreAfterLogoutTest.php` は
  `authenticated` だけを読むため影響なし

### 変更後コード

```php
// SessionStatusDto
final readonly class SessionStatusDto
{
    public function __construct(
        public bool $authenticated,
        /** 要求が運んだ描画世代が、現在のセッションの世代と一致するか。 */
        public bool $sessionEpochMatches,
    ) {}
}
```

```php
// SessionStatusController::__invoke()
public function __invoke(Request $request): SessionStatusResource
{
    // 照合に使うのは **要求ヘッダで運ばれた描画世代** だけである。
    // 要求の Cookie ヘッダに載る世代 cookie は画面側から書き換えられる値なので、
    // 一致判定には一切使わない (開示の根拠に client 側の状態を混ぜない)。
    $submitted = $request->headers->get(SessionEpoch::HEADER_NAME);

    return SessionStatusResource::make(new SessionStatusDto(
        authenticated: $request->user() !== null,
        sessionEpochMatches: SessionEpoch::matches(
            is_string($submitted) ? $submitted : null,
            SessionEpoch::current($request),
        ),
    ));
}
```

```php
// SessionStatusResource::toArray()
/**
 * @return array{authenticated: bool, sessionEpochMatches: bool}
 */
public function toArray(Request $request): array
{
    return [
        'authenticated' => $this->resource->authenticated,
        'sessionEpochMatches' => $this->resource->sessionEpochMatches,
    ];
}
```

**現世代の値そのものは応答に載せない** (画面側は一致か否かだけ分かればよい。
値が要るときは cookie を読む)。受け取ったヘッダ値はログにも応答にも出さない
(外部由来の可変文字列)。

### PHPStan適合チェック

- [x] DTO は `final readonly`、全プロパティに型
- [x] `headers->get()` の `?string` を `is_string()` で narrowing
- [x] Resource の `toArray` に array shape の phpdoc
- [x] `response()->json()` を使わない (JsonResource 経由)

### テスト計画

- [ ] 既存 `tests/Feature/Auth/SessionStatusProbeTest.php` を更新
  - guest: `{authenticated: false, sessionEpochMatches: false}`
  - 認証済み・ヘッダ無し: `{authenticated: true, sessionEpochMatches: false}`
    (**印を運ばない要求は一致にしない** = 既定は開示しない側)
  - 認証済み・正しい印: `sessionEpochMatches: true`
  - 認証済み・別の印 / 書式違い / 空文字 / 長すぎる値: `false`
  - **世代 cookie に正しい印を積んでもヘッダが無ければ `false`**
    (Cookie ヘッダを照合に使っていないことの behavioral な固定)
  - 既存の契約 (no-store / PII 非搭載 / 2FA 強制中も 200 / recent-auth 期限切れでも 200 /
    組織未選択・メール未検証でも 200) は**そのまま維持**する
- [ ] 応答本文に印そのものが現れないこと (値を返していないことの固定)

### リスク

- 応答 shape が変わるため、**画面側パーサ (S4) と同じ PR で入れる**必要がある
  (後方互換の並走を残さない = 思考原則 3)。
- ヘッダ名 `X-Session-Epoch` は同一オリジンの `fetch` からのみ送られる。
  別オリジンからは preflight が通らず読めないため、新たな露出面にはならない。

---

## S4: ガードに同期判定を前置し開示条件を厳格化

### 変更箇所

- `resources/js/lib/bfcache-guard.ts`
- `resources/js/app.ts` (依存の配線)

### 波及変更

- TypeScript 型定義: `BfcacheGuardDeps` に 2 つの読み取り関数、
  `SessionProbeOutcome` に `stale`、状態値に `reloading`
- API Resource/DTO: S3 が対
- テストファイル: `tests/js/lib/bfcache-guard.test.ts` (分岐追加) /
  `tests/Browser/AuthenticatedPageBfcacheTest.php` (配線は不変。実行確認のみ)

### 変更後コード（差分の要点）

```ts
/** 秘匿属性の値 (状態遷移を一意に表す)。 */
export const BFCACHE_STATE_PENDING = "pending";
export const BFCACHE_STATE_VERIFYING = "verifying";
export const BFCACHE_STATE_RETRY = "retry";
/** 世代が変わっていたので、秘匿したまま同じ URL を読み直している状態。 */
export const BFCACHE_STATE_RELOADING = "reloading";

/** セッション世代の印を運ぶヘッダ (PHP の SessionEpoch::HEADER_NAME と対)。 */
export const SESSION_EPOCH_HEADER = "X-Session-Epoch";
/** 現世代の写しを運ぶ cookie (PHP の SessionEpoch::COOKIE_NAME と対)。 */
export const SESSION_EPOCH_COOKIE = "session_epoch";

/** 同期判定の結論。**開示を表す値を持たない** (開示はプローブだけが決める)。 */
export type SyncEpochDecision = "must-reload" | "undecided";

/**
 * 通信を待たない前置判定。
 *
 * 一致しても "undecided" (= プローブへ進む) にしかならない。
 * この関数が返しうる値に「開示」が無いことが、
 * 「同期判定は開示しない側にしか到達しない」という不変条件の型による表現である。
 */
export function decideBySyncEpoch(
    rendered: string | null,
    current: string | null,
): SyncEpochDecision {
    if (rendered === null || current === null) return "must-reload";
    return rendered === current ? "undecided" : "must-reload";
}

/** document.cookie から現世代の写しを読む。書式が違えば null。 */
export function readSessionEpochCookie(cookieHeader: string): string | null {
    for (const part of cookieHeader.split(";")) {
        const [name, ...rest] = part.split("=");
        if (name?.trim() !== SESSION_EPOCH_COOKIE) continue;
        const value = decodeURIComponent(rest.join("=").trim());
        return /^[0-9a-f]{32}$/.test(value) ? value : null;
    }
    return null;
}

/** プローブ応答の判定。`stale` = 認証済みだが世代が違う (別の利用者・別の世代)。 */
export type SessionProbeOutcome =
    | "authenticated"
    | "stale"
    | "unauthenticated"
    | "failed";

/** 応答 shape の厳密判定 (2 つの boolean が top-level に揃っているときだけ受理)。 */
export function readSessionStatus(
    payload: unknown,
): { authenticated: boolean; sessionEpochMatches: boolean } | null {
    if (typeof payload !== "object" || payload === null || Array.isArray(payload)) {
        return null;
    }
    const record = payload as Record<string, unknown>;
    const authenticated = record.authenticated;
    const matches = record.sessionEpochMatches;
    if (typeof authenticated !== "boolean" || typeof matches !== "boolean") {
        return null;
    }
    return { authenticated, sessionEpochMatches: matches };
}
```

```ts
// probeSessionStatus: 描画世代をヘッダで運ぶ
const headers: Record<string, string> = { Accept: "application/json" };
if (renderedEpoch !== null) headers[SESSION_EPOCH_HEADER] = renderedEpoch;
// …fetch 後…
const status = readSessionStatus(await response.json());
if (status === null) return "failed";
if (!status.authenticated) return "unauthenticated";
return status.sessionEpochMatches ? "authenticated" : "stale";
```

```ts
// registerBfcacheGuard の依存 (既定は安全側)
const readRenderedEpoch = deps.readRenderedEpoch ?? (() => null);
const readCurrentEpoch =
    deps.readCurrentEpoch ?? (() => readSessionEpochCookie(doc.cookie));

// **描画世代の既定を cookie にしてはならない**。両方が同じ出所になると
// 常に一致し、同期判定が素通しになる (前置がある振りだけになる)。

const reloadHidden = (): void => {
    // 秘匿を維持したまま同じ URL を読み直す。読み直した文書には秘匿属性が無いので
    // ガードは何もせず、1 文書につき読み直しは高々 1 回でループにならない。
    setHiddenState(doc, BFCACHE_STATE_RELOADING);
    win.location.reload();
};

const verify = async (): Promise<void> => {
    setHiddenState(doc, BFCACHE_STATE_VERIFYING);

    const outcome = await probeSessionStatus(fetchImpl, readRenderedEpoch(), SESSION_STATUS_PATH);
    if (outcome === "authenticated") {
        clearHiddenState(doc);
        return;
    }
    if (outcome === "unauthenticated") {
        win.location.replace(LOGIN_PATH);
        return;
    }
    if (outcome === "stale") {
        reloadHidden();
        return;
    }
    setHiddenState(doc, BFCACHE_STATE_RETRY);
};

const onPageShow = (): void => {
    if (!isHidden(doc)) return;
    if (decideBySyncEpoch(readRenderedEpoch(), readCurrentEpoch()) === "must-reload") {
        reloadHidden();
        return;
    }
    void verify();
};
```

```ts
// resources/js/app.ts の配線
const disposeBfcacheGuard = registerBfcacheGuard({
    isAuthenticated: () => hasAuthenticatedUser(page.props),
    // 描画世代は **いま画面に出ている内容と同じ応答で来た値**を使う
    // (cookie から読むと「内容は A・印は B」の取り違えが起きる)
    readRenderedEpoch: () => readSessionEpoch(page.props),
});
```

`resources/css/app.css` は変更しない。`reloading` は既定の秘匿表示
(「セッションを確認しています…」) にそのまま乗る (`retry` だけが別表示という現行構造を保つ)。
読み直しはサーバへ確認しに行く動作であり、この文言と矛盾しない。

### テスト計画（vitest: `tests/js/lib/bfcache-guard.test.ts`）

- [ ] `decideBySyncEpoch`: 一致 → `undecided` / 不一致 → `must-reload` /
      描画世代 null → `must-reload` / cookie null → `must-reload`
- [ ] **同期判定が開示に到達しないことの負のコントロール**:
      cookie と描画世代を一致させても、プローブを呼ばずに秘匿が解けることは無い
- [ ] `readSessionEpochCookie`: 他 cookie との混在 / 前後空白 / URL エンコード /
      書式違い → null / 不在 → null
- [ ] 不一致のとき **プローブを 1 度も呼ばずに** `reloading` になり `reload()` が呼ばれる
      (`fetchImpl` が呼ばれていないことを検証 = 「通信を待たない」の実証)
- [ ] 一致のとき描画世代が `X-Session-Epoch` ヘッダで送られる
- [ ] 描画世代が null のときヘッダを付けない (空文字を送らない)
- [ ] `authenticated:true, sessionEpochMatches:true` → 秘匿解除 (遷移も reload もしない)
- [ ] `authenticated:true, sessionEpochMatches:false` → 秘匿維持のまま `reload()`
      (**/login へ倒さない**: いまの利用者は認証済みであり、嘘の着地を作らない)
- [ ] `authenticated:false` → 秘匿維持のまま `/login` へ置換遷移 (現行どおり)
- [ ] 応答が旧 shape (`sessionEpochMatches` 欠落) → `failed` = 秘匿維持 + 再試行
      (契約ずれが開示に倒れないことの固定)
- [ ] 通信失敗 / 非 JSON / HTTP エラー → 秘匿維持 + 再試行 (現行の分岐を維持)
- [ ] **cookie を偽の値へ書き換えても開示に至らない** (偽装で `undecided` に持ち込めても、
      プローブの `sessionEpochMatches` が false なら読み直しに倒れる)
- [ ] 読み直し後の文書 (秘匿属性なし) では `pageshow` で何も起きない (ループしない)
- [ ] 既存の分岐 (未認証ページで発火しない / persisted 判定 / 再試行ボタン /
      オーバーレイ二重生成なし) は**そのまま維持**する

### リスク

- **既定値の取り違えが最大のリスク**。`readRenderedEpoch` の既定を cookie にすると
  同期判定が素通しになる。既定は `() => null` (= 安全側で読み直し) とし、
  実配線があることを S7 の契約検査で固定する。
- 世代 cookie が読めない環境 (cookie を画面側から読めないブラウザ設定) では
  復元のたびに読み直しになる。開示側へは倒れないが、体感は「戻ると再読込」になる。
  この degrade は `docs/supported-browsers.md` の未対応事項へ明記する。
- 復元直後に `reload()` する経路が増えるため、**未送信フォームは失われる**。
  失われるのは「世代が変わった = 別のセッションの文書」に限られ、
  同一セッションの復元 (通常の戻る) は現行どおり秘匿解除だけで済む。

---

## S5: 検証ページの状態語彙を追随させる

T161 で着地した実機受入確認の検証ページは、ガードの秘匿属性の遷移を
`pending → verifying → (null | retry)` として検証する。S4 で `reloading` が増えるため、
**同じ PR で追随させないと T085 の実機確認で記録が拒否される**。

### 変更箇所

- `resources/js/lib/debug/bfcache-trial.ts`
- `resources/js/pages/Debug/BfcacheTrial.svelte` (判定ラベルの表示文言)

### 変更後コード（要点）

```ts
/** 記録の書式版。状態語彙が増えたので上げる (旧記録は読み捨てる)。 */
export const TRIAL_SCHEMA_VERSION = 2;

export type GuardState = "pending" | "verifying" | "retry" | "reloading" | null;

const GUARD_STATES: readonly GuardState[] = ["pending", "verifying", "retry", "reloading", null];

export type GuardVerdict =
    | "in-progress"
    | "authenticated-unhidden"
    | "unauthenticated-redirected"
    | "hidden-then-left"
    /** 秘匿を維持したまま読み直しに倒れた (目視確認待ち)。 */
    | "stale-session-reloaded"
    | "retry-hidden"
    | "failed-transition"
    | "not-observed";
```

`deriveGuardVerdict` の追加規則 (既存の走査構造は変えない):

1. 走査中に `guard-state-changed(state = "reloading")` を観測したら、その時点で終端候補
   「読み直しに倒れた」として走査を打ち切る。
2. `page-hide` の `guardState` が `"reloading"` の場合も同じ終端候補として扱う
   (属性変化の観測が取りこぼされても、離脱時点のスナップショットが裏取りになる)。
3. 終端候補が立ったとき、記録済みの状態列が空でなければ先頭は `pending` でなければならない
   (そうでなければ `failed-transition`)。
4. `redirect-observed` (利用者の目視確認) があれば **`unauthenticated-redirected`**、
   無ければ `stale-session-reloaded`。

- `deriveOverallVerdict`: `stale-session-reloaded` は `hidden-then-left` と同じく
  `undetermined` に落とす (目視確認が入るまで終端しない)。
- `deriveTrialPhase`: `stale-session-reloaded` → `awaiting-manual-confirmation`。
- `expectedGuardVerdict` は変更しない (失効セッション経路の合格終端は
  `unauthenticated-redirected` のまま = **T085 の完了条件は変わらない**)。

### テスト計画（vitest: `tests/js/lib/debug/bfcache-trial.test.ts`）

- [ ] `pending → reloading` で `stale-session-reloaded`
- [ ] 上に `redirect-observed` が付くと `unauthenticated-redirected`
- [ ] `pending → verifying → reloading` でも同じ終端 (プローブ経由の読み直し)
- [ ] `page-hide.guardState = "reloading"` 単独でも同じ終端 (取りこぼし時の裏取り)
- [ ] `reloading` から始まる列 (先頭が `pending` でない) は `failed-transition`
- [ ] `stale-session-reloaded` の総合判定は `undetermined`、phase は
      `awaiting-manual-confirmation` (自動イベントの追記が止まる)
- [ ] `schemaVersion = 1` の記録は読み捨てられる (旧記録の混入で誤判定しない)
- [ ] 有効セッション経路 (`pending → verifying → null`) の判定は**変わらない**

### リスク

- 書式版を上げるため、検証途中の記録があれば失われる。**T085 は未実施で
  実記録が 1 件も無い**ため実害はない (`docs/supported-browsers.md` の
  「記録はまだ 1 件も無い」が根拠)。
- 判定関数の分岐が増える。T161 の設計原則 (観測できないことを推論しない /
  空振りを合格と読まない) を崩さないよう、**新終端は単独では PASS にならない**
  (目視確認が要る) 形を保つ。

---

## S6: 理由記述の差し替えと文書の更新

### 変更箇所

- `app/Http/Middleware/NoStoreCacheHeadersForAuthenticatedPages.php` (docblock のみ。**挙動は変えない**)
- `docs/supported-browsers.md`

### 変更後コード（docblock の該当部）

```php
/**
 * 認証済みリクエストの web 応答に `no-store` を保証する baseline middleware。
 *
 * 目的: ログアウト後のブラウザ「戻る」で認証済み画面 (メンバー一覧等の PII) が
 * 再表示されるのを防ぐ。あわせて disk / proxy cache への認証済み応答の残留も禁じる。
 *
 * **保存禁止ヘッダは「戻る」用の一時保存 (bfcache) への格納を禁じる指示ではない。**
 * 格納するか・いつ捨てるかはブラウザの実装判断であり、このヘッダだけで復元を止められる
 * 保証はどのブラウザについても持っていない。ブラウザごとの観測と一次情報の日付は
 * docs/supported-browsers.md が正本である。
 * したがって本 middleware は復元経路 B を単独では塞げず、クライアント側の
 * bfcache 秘匿・再検証 (resources/js/lib/bfcache-guard.ts +
 * セッション世代の印 App\Support\Auth\SessionEpoch) と **セットで** 主便益を達成する。
 * …(以降の popstate / 適用判定に関する記述は現行のまま)
 */
```

**ブラウザ名で例外を作る書き方をやめる**のが本施策の要点である
(「他は塞げるが 1 つだけ例外」という枠組みは対処の方向を誤らせる)。
同時に、否定形で「〜という説は誤り」と書き足すこともしない
(打ち消しの文言は読み手を元の誤解へ引き戻すため、事実だけを書く)。

### `docs/supported-browsers.md` の更新

1. 経路の表の**経路 A** の「何を保証するか」を、ヘッダの効果を断定しない表現へ直す
   (残留の禁止は保証、bfcache 格納の可否はブラウザ判断)。
2. 経路の表の**経路 B** に、開示の条件が
   「認証済み **かつ** 描画世代が現世代と一致」へ厳格化されたことを書く。
3. 「対象ブラウザ」節の前提文を、特定ブラウザの例外という枠組みから
   「保存禁止ヘッダが付いていても復元されうる環境がある (主戦場の iOS Safari を含む)」へ直す。
4. **未対応事項**へ追記: 世代 cookie を画面側から読めない環境では復元のたびに読み直しになる
   (開示側へは倒れない) / `/admin` には印もガードも届かない。
5. **実機受入確認の再確認条件**の一覧へ、世代 cookie の供給元 (`SessionEpoch` /
   `IssueSessionEpochCookie`) とプローブの応答契約を追加する。
   **本設計は挙動変更なので、この条件により T085 の実施は本件マージ後になる**。
6. **検証ページ**節へ、失効セッション経路の観測が「秘匿を維持したまま読み直す」に変わり、
   合格終端 (`unauthenticated-redirected` = 目視確認込み) は変わらないことを書く。
7. 期限検査のための**一次情報の最終確認日**の行を追加する (S7 と対)。

### テスト計画

- [ ] docblock は挙動を変えないため、既存の
      `tests/Feature/Security/NoStoreCacheHeadersTest.php` /
      `ExistingNoStoreContractTest.php` が**変更なしで緑のまま**であることを確認する
      (これがリグレッションの唯一の判定基準)
- [ ] 文書の更新は S7 の期限検査 (確認日の行が読めること) で機械的に固定する

### リスク

- 文書の断定を弱めると「何も保証していない」と読まれうる。
  保証する部分 (残留の禁止 / 秘匿と再検証で塞ぐ) は明示のまま残す。

---

## S7: 契約ずれの検査と文書の期限検査

### 変更箇所

- 新規 `tests/Architecture/BfcacheGuardClientContractSyncTest.php`
- 新規 `tests/Architecture/SupportedBrowsersDocFreshnessTest.php`

### 契約ずれの検査（何を固定するか）

PHP 側の定数を正本として、画面側のファイルに同じ文字列が実在することを確かめる。
言語をまたぐ名前は型検査が届かず、**片側だけ変えると静かに壊れる** (常に読み直し、
または常に不一致) ため、機械で固定する。

| # | 正本 (PHP) | 出現を要求する先 |
|---|---|---|
| 1 | `SessionEpoch::COOKIE_NAME` | `resources/js/lib/bfcache-guard.ts` |
| 2 | `SessionEpoch::HEADER_NAME` | `resources/js/lib/bfcache-guard.ts` |
| 3 | `SessionEpoch::SHARED_PROP_KEY` | `app/Http/Middleware/HandleInertiaRequests.php` / `resources/js/lib/shared-props.ts` |
| 4 | 応答キー `authenticated` / `sessionEpochMatches` (`SessionStatusResource` の実出力から取得) | `resources/js/lib/bfcache-guard.ts` |
| 5 | 書式 `[0-9a-f]{32}` (`SessionEpoch::VALUE_PATTERN` から機械的に導出) | `resources/js/lib/bfcache-guard.ts` / `resources/js/lib/shared-props.ts` |
| 6 | ガードの状態値 `reloading` | `resources/js/lib/debug/bfcache-trial.ts` (検証ページの許可語彙) |
| 7 | 配線 `readRenderedEpoch` / `readCurrentEpoch` | `resources/js/app.ts` (既定のままにしない) |

- **監視対象のファイルがすべて実在することを別ケースで検査する**
  (パス変更で検査が無言で空になる事故を塞ぐ。
  `tests/js/architecture/no-unload-listener.test.ts` と同じ規律)。
- 応答キーは `SessionStatusResource` を実際に `toArray()` して得た配列のキーから取る
  (文字列を検査側にも書くと 2 か所の正本になる)。
- **保証範囲を誇張しない**: これは文字列の実在検査であり、
  **使われ方が正しいことは保証しない** (テストの docblock に明記する)。
  意味の正しさは vitest (分岐) と Feature (応答契約) が担う。

### 文書の期限検査（何のためか）

目的は「**実機再確認と一次情報の再確認が、未実施のまま忘れられるのを防ぐ**」ことである。
`docs/supported-browsers.md` にはブラウザ挙動の一次情報 (自動化ハーネスの版と起動スイッチ、
原因未特定の宿題) が載っており、これは時間で陳腐化する。

- 文書に**一次情報の最終確認日**の行を 1 行だけ持ち、日付を機械で読む。
- 行が無い / 日付が読めない / **400 日より古い**とき赤にする。
- 失敗メッセージには「何を再確認するのか」(自動化ハーネスの版と起動スイッチの状況 /
  復元が再現しない原因の調査 / iOS 実機受入確認 (T085) の実施状況) を並べる。
- **保証しないもの**をテストの docblock に書く:
  確認日は自己申告であり、**日付を新しくしても内容が正しいことは担保しない**。
  この検査が担うのは「見直す機会を強制的に作る」ことだけである。

### テスト計画

- [ ] 契約ずれの検査: 上の 7 行がすべて緑 / 監視対象ファイルの実在検査 /
      **わざと片側だけ変えると赤になる**ことを実装時に手で 1 度確かめ、結果を devnotes に記す
- [ ] 期限検査: 現在日で緑 / 確認日の行を消すと赤 / 未来日付や書式違いで赤

### リスク

- **期限検査は将来のある日に、コードを 1 行も変えていないのに赤くなる**。
  これは意図した設計 (見直しの強制) だが、失敗メッセージが不親切だと
  「日付だけ更新して黙らせる」運用になる。メッセージに再確認項目を列挙して防ぐ。
- 文字列の実在検査は、**意味の一致までは保証しない**。誇張しない書き方をする。

---

## 施策間の依存と実装順序

1. S1 (印の供給元と cookie) → 2. S2 (共有 prop) → 3. S3 (プローブの照合)
   → 4. S4 (ガード) → 5. S5 (検証ページ) → 6. S6 (文書) → 7. S7 (検査)

S3 と S4 は**同じ PR に入れる** (応答 shape の変更と読み手の変更を分けない。
後方互換の並走を残さない = 思考原則 3)。

## 検証コマンド

`composer test` / `composer phpstan` / `vendor/bin/pint --test` /
`pnpm lint` / `pnpm typecheck` / `pnpm test` / `pnpm build`
(すべて green を実装完了の条件とする)

## 申し送り (本設計のスコープ外だが、後段で必ず扱うこと)

- **T085 (bfcache 実復元の iOS 実機受入確認) は本件のマージ後に実施する。**
  本件はガード本体・プローブ契約・秘匿状態の語彙を変えるため、
  `docs/supported-browsers.md` の「実機受入確認の再確認条件」のトリガに当たる。
- **実機受入の手引き (runbook) は本件では新設しない。** 手順の正本は
  `docs/supported-browsers.md` の「実機受入確認の再確認条件」と「検証ページ」節にあり、
  実施は T085 が持つ。別ファイルを作ると同じ手順が 2 か所になり、
  同書の「記録の二重管理を作らない」方針に反する。
- `docs/TODO.md` の T085 の備考に「本件マージ後に実施」の一文を足す作業は、
  後段の TODO 採番登録でまとめて行う (本設計では TODO.md を編集しない)。

## 実装モード

| 項目 | 内容 |
|------|------|
| 推奨モード | **standalone** |
| 判断根拠 | `bootstrap/app.php` の web グループと優先順位一覧、`TenantBoundaryOrderingTest` の完全一致列、共有 props、ガード、検証ページを 1 つの不変条件のために同時に変える。分割すると「印は出るが誰も読まない」「印が無く常に読み直し」の中途半端な状態が main に載る |
| 競合リスク | `bootstrap/app.php` (middleware 列 / priority list) と `HandleInertiaRequests::share()` は他施策も触りうる中心ファイルである。`TenantBoundaryOrderingTest` の完全一致列は、他の middleware 追加と衝突しやすい (どちらも赤で検出されるため無言の破壊にはならない) |


---

## 関連する現行コード

### `resources/js/lib/bfcache-guard.ts` (L64-283)

```ts
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
        const response = await fetchImpl(url, {
            credentials: "same-origin",
            cache: "no-store",
            headers: { Accept: "application/json" },
        });

        if (!response.ok) return "failed";
        if (!isJsonMediaType(response.headers.get("Content-Type"))) return "failed";

        const authenticated = readAuthenticatedFlag(await response.json());
        if (authenticated === null) return "failed";

        return authenticated ? "authenticated" : "unauthenticated";
    } catch {
        return "failed";
    }
}

/**
 * 秘匿オーバーレイを (無ければ) 生成する。Atomic Design 階層には component を足さない
 * (app 起動時のグローバル要素 + CSS で完結させる = atoms/molecules の責務ではない)。
 */
function ensureOverlay(doc: Document): HTMLElement {
    const existing = doc.getElementById(BFCACHE_OVERLAY_ID);
    if (existing !== null) return existing;

    const overlay = doc.createElement("div");
    overlay.id = BFCACHE_OVERLAY_ID;
    overlay.setAttribute("role", "status");
    overlay.setAttribute("aria-live", "polite");
    overlay.dataset.testid = BFCACHE_OVERLAY_ID;

    const panel = doc.createElement("div");
    panel.className = "bfcache-guard__panel";

    const verifying = doc.createElement("p");
    verifying.className = "text-body";
    verifying.dataset.bfcacheGuardVerifying = "";
    verifying.textContent = "セッションを確認しています…";

    const failure = doc.createElement("p");
    failure.className = "text-body";
    failure.dataset.bfcacheGuardFailure = "";
    failure.textContent =
        "セッションを確認できませんでした。通信状況を確認して、もう一度お試しください。";

    const retry = doc.createElement("button");
    retry.type = "button";
    retry.id = BFCACHE_RETRY_BUTTON_ID;
    retry.className = "bfcache-guard__retry";
    retry.dataset.testid = BFCACHE_RETRY_BUTTON_ID;
    retry.textContent = "再試行";

    panel.append(verifying, failure, retry);
    overlay.append(panel);
    doc.body.append(overlay);

    return overlay;
}

function setHiddenState(doc: Document, state: string): void {
    doc.documentElement.setAttribute(BFCACHE_HIDDEN_ATTRIBUTE, state);
}

function clearHiddenState(doc: Document): void {
    doc.documentElement.removeAttribute(BFCACHE_HIDDEN_ATTRIBUTE);
}

function isHidden(doc: Document): boolean {
    return doc.documentElement.hasAttribute(BFCACHE_HIDDEN_ATTRIBUTE);
}

/**
 * pagehide 時に秘匿すべきか。`PageTransitionEvent.persisted` が使える環境では
 * bfcache 対象 (persisted) のときだけ秘匿し、通常遷移のちらつきを避ける。
 * 取得できない環境では安全側 (秘匿する) へ倒す
 * (通常遷移では直後に新しい Document へ移るため実害はほぼ無い)。
 */
function shouldHideOnPageHide(event: Event): boolean {
    const persisted: unknown = (event as PageTransitionEvent).persisted;
    return typeof persisted === "boolean" ? persisted : true;
}

/**
 * guard を登録し、購読解除 disposer を返す (HMR / テストの二重登録防止)。
 *
 * 秘匿・プローブは `isAuthenticated()` が true のページでのみ作動する
 * (公開ページでは不要なちらつき・プローブを起こさない)。
 */
export function registerBfcacheGuard(deps: BfcacheGuardDeps = {}): () => void {
    const doc = deps.doc ?? document;
    const win = deps.win ?? window;
    const fetchImpl: ProbeFetch =
        deps.fetchImpl ?? ((input, init) => fetch(input, init) as Promise<ProbeResponseLike>);
    const isAuthenticated = deps.isAuthenticated ?? (() => false);

    const overlay = ensureOverlay(doc);
    const retryButton = overlay.querySelector<HTMLButtonElement>(`#${BFCACHE_RETRY_BUTTON_ID}`);

    const onRetry = (): void => {
        // 自動再試行はしない。押下時に現在 URL を hard reload し、サーバに再判定させる。
        win.location.reload();
    };
    retryButton?.addEventListener("click", onRetry);

    const verify = async (): Promise<void> => {
        setHiddenState(doc, BFCACHE_STATE_VERIFYING);

        const outcome = await probeSessionStatus(fetchImpl, SESSION_STATUS_PATH);
        if (outcome === "authenticated") {
            clearHiddenState(doc);
            return;
        }
        if (outcome === "unauthenticated") {
            // 秘匿したまま login へ。replace で秘匿済み履歴エントリを残さない。
            win.location.replace(LOGIN_PATH);
            return;
        }
        setHiddenState(doc, BFCACHE_STATE_RETRY);
    };

    const onPageHide = (event: Event): void => {
        if (!isAuthenticated()) return;
        if (!shouldHideOnPageHide(event)) return;
        setHiddenState(doc, BFCACHE_STATE_PENDING);
    };

    const onPageShow = (): void => {
        // 復元マーカーは秘匿属性そのもの。通常ロードではサーバ由来の新しい HTML に
        // 属性が無いため、ここで抜ける。
        if (!isHidden(doc)) return;
        void verify();
    };

    win.addEventListener("pagehide", onPageHide);
    win.addEventListener("pageshow", onPageShow);

    return () => {
        win.removeEventListener("pagehide", onPageHide);
        win.removeEventListener("pageshow", onPageShow);
        retryButton?.removeEventListener("click", onRetry);
    };
}

```

### `resources/js/app.ts` (L1-33)

```ts
import { createInertiaApp, page } from "@inertiajs/svelte";
import { hydrate, mount } from "svelte";
import { resolvePage } from "./inertia";
import { registerBfcacheGuard } from "./lib/bfcache-guard";
import { registerDocumentTitleSync } from "./lib/document-title";
import { registerRecentAuthRedirectHandler } from "./lib/recent-auth";
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

    // recent-auth 鮮度切れの 409 (recent_auth_required) を confirm 画面へ着地させる単一ハンドラ。
    // precheck (withRecentAuth) を通れない delegated 経路の受け皿であり、これが無いと
    // 409 が Inertia の既定エラーモーダルに落ちて無言の行き止まりになる (詳細設計 施策 4)。
    const disposeRecentAuthRedirect = registerRecentAuthRedirectHandler();
    import.meta.hot?.dispose(disposeRecentAuthRedirect);
}
```

### `app/Http/Controllers/Auth/SessionStatusController.php`

```php
<?php

declare(strict_types=1);

namespace App\Http\Controllers\Auth;

use App\DataTransferObjects\Auth\SessionStatusDto;
use App\Http\Controllers\Controller;
use App\Http\Resources\Auth\SessionStatusResource;
use Illuminate\Http\Request;

/**
 * セッション有効性の軽量プローブ (詳細設計 施策 6)。
 *
 * bfcache から復元された認証済み画面を「秘匿したまま」再検証するために、
 * クライアント guard (resources/js/lib/bfcache-guard.ts) が pageshow 直後に叩く。
 *
 * auth グループの **外**に置く: auth 配下だと未認証時に 302/401 になり、guard 側で
 * 「セッション無効」と「endpoint 不在 / ネットワーク障害」を区別しにくくなる。
 * guest でも 200 + `authenticated: false` を返し、判定を明示 boolean 一本にする
 * (認証状態は同一オリジンの呼び出し元が cookie で既に知りうる情報であり、
 * これ自体は新たな情報露出にならない)。
 */
final class SessionStatusController extends Controller
{
    public function __invoke(Request $request): SessionStatusResource
    {
        return SessionStatusResource::make(
            new SessionStatusDto(authenticated: $request->user() !== null),
        );
    }
}

```

### `app/DataTransferObjects/Auth/SessionStatusDto.php`

```php
<?php

declare(strict_types=1);

namespace App\DataTransferObjects\Auth;

/**
 * bfcache 秘匿・再検証 guard (resources/js/lib/bfcache-guard.ts) の軽量プローブ応答 DTO。
 *
 * セッションが「今も有効か」だけを伝える最小 DTO。recent-auth (step-up 鮮度) とは
 * 意味が異なるため RecentAuthStatusDto を流用しない。PII / 権限 / 組織情報は載せない
 * (bfcache 復元直後の未検証状態で叩かれる endpoint であり、露出面を最小に保つ)。
 */
final readonly class SessionStatusDto
{
    public function __construct(
        public bool $authenticated,
    ) {}
}

```

### `app/Http/Resources/Auth/SessionStatusResource.php`

```php
<?php

declare(strict_types=1);

namespace App\Http\Resources\Auth;

use App\DataTransferObjects\Auth\SessionStatusDto;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * セッション有効性プローブの XHR 応答 ({ authenticated })。
 *
 * top-level (data ラップなし) にするのは、クライアント guard が JSON shape を厳密判定
 * するため (RecentAuthStatusResource と同じ作法)。
 *
 * `no-store, private` は controller ではなく本 Resource (withResponse) で付ける:
 * 本 endpoint は **guest 応答も対象**であり、認証済み限定の baseline middleware
 * (NoStoreCacheHeadersForAuthenticatedPages) では guest 分を取りこぼすため。
 *
 * @property-read SessionStatusDto $resource
 */
final class SessionStatusResource extends JsonResource
{
    /** @var string|null */
    public static $wrap = null;

    /**
     * @return array{authenticated: bool}
     */
    public function toArray(Request $request): array
    {
        return [
            'authenticated' => $this->resource->authenticated,
        ];
    }

    public function withResponse(Request $request, JsonResponse $response): void
    {
        $response->headers->set('Cache-Control', 'no-store, private');
    }
}

```

### `app/Http/Middleware/NoStoreCacheHeadersForAuthenticatedPages.php`

```php
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
 * さらに Inertia SPA のクライアント履歴復元 (popstate) はサーバへリクエストが飛ばないため
 * 本 middleware も bfcache guard も発火しない。その経路は Inertia 公式機構
 * (bootstrap/app.php の Inertia\Middleware\EncryptHistory +
 * App\Http\Responses\Fortify\LogoutResponse の Inertia::clearHistory()) が担当する
 * (bug-hunt F-4-01)。**3 経路 × 3 枚の網の全体像は docs/supported-browsers.md が正本**。
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

### `bootstrap/app.php` (L130-165)

```php
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
            // bug-hunt: 実行済み route の記録。**列の最後**に置き、priority list でも鎖の最後に固定する
            // (= ここへ到達したことが「遮断 middleware をすべて通過した」証拠になる)。
            // 既定 no-op (config('bughunt.executed.enabled') 既定 false + production 除外)。
            BughuntExecutedRouteMiddleware::class,
        ]);

        // パスワード変更/リセット時に他デバイスのセッション・remember-me を確実に失効させるため、
        // web グループで AuthenticateSession (alias 'auth.session') を有効化する。
        // 各認証済みリクエストで session 保存の password_hash と現在ハッシュを照合し、不一致なら
        // 現在デバイスを logout する (guest は no-op)。Auth::logoutOtherDevices() の実効性はこの
        // middleware が担保する (Laravel 標準の "Log Out Other Browser Sessions" 構成)。
```

### `bootstrap/app.php` (L253-290)

```php
        $middleware->appendToPriorityList(
            SubstituteBindings::class,
            EnsureProjectBelongsToApiOrganization::class,
        );
        $middleware->appendToPriorityList(
            EnsureProjectBelongsToApiOrganization::class,
            EnsureProjectBelongsToRouteOrganization::class,
        );
        // テナント guard より後に走ることを確定させる web グループの鎖
        // (guard を binding 直後まで引き上げるための「後続」宣言)。
        foreach ([
            [EnsureProjectBelongsToRouteOrganization::class, HandleInertiaRequests::class],
            [HandleInertiaRequests::class, SecurityHeaders::class],
            [SecurityHeaders::class, RequireTwoFactorForEnforcedOrganizations::class],
            [RequireTwoFactorForEnforcedOrganizations::class, BlockTwoFactorDisableForEnforcedOrganizations::class],
            [BlockTwoFactorDisableForEnforcedOrganizations::class, NoStoreCacheHeadersForAuthenticatedPages::class],
            [NoStoreCacheHeadersForAuthenticatedPages::class, EncryptHistory::class],
            [EncryptHistory::class, EnsureEmailIsVerified::class],
            [EnsureEmailIsVerified::class, RequireActiveSubscription::class],
            // 退会予約中の凍結。**302 で短絡する**ため、テナント境界 404
            // (EnsureProjectBelongsToRouteOrganization) より必ず後に置く。前に置くと
            // 「他組織に実在 = 302 / 不在 = 404」の 1 bit 存在オラクルになる
            // (AGENTS.md セキュリティ不変条件 10)。課金ゲートの直後に置き、未契約組織の
            // ユーザーは 課金ゲート → onboarding → 凍結 → /settings の 2 hop で取消 UI に着く。
            [RequireActiveSubscription::class, EnsureAccountNotPendingDeletion::class],
            // bug-hunt の記録器は鎖の最後 (遮断 middleware より内側) に固定する。
            // 「短絡しうる middleware はすべて記録器より前」は
            // BughuntExecutedRouteOrderingTest が deny-by-default で強制する。
            [EnsureAccountNotPendingDeletion::class, BughuntExecutedRouteMiddleware::class],
        ] as [$after, $append]) {
            $middleware->appendToPriorityList($after, $append);
        }
        $middleware->prependToPriorityList(
            SubstituteBindings::class,
            ResolveApiActor::class,
        );

        /*
```

### `app/Http/Middleware/HandleInertiaRequests.php` (L40-101)

```php
    public function share(Request $request): array
    {
        // admin guard (AdminUser) 追加により user() は union 型になるため、
        // Inertia (web guard) の共有 props は User のみを対象に narrowing する
        $user = $request->user();
        if (! $user instanceof User) {
            $user = null;
        }

        return [
            ...parent::share($request),
            'appName' => config('app.name'),
            'auth' => [
                'user' => $user === null ? null : [
                    'id' => $user->id,
                    'name' => $user->name,
                    'email' => $user->email,
                    'emailVerified' => $user->hasVerifiedEmail(),
                    'twoFactorEnabled' => $user->hasEnabledTwoFactorAuthentication(),
                ],
            ],
            'organizations' => $this->organizationsProp($user),
            'currentOrganization' => $this->currentOrganizationProp($user),
            // 通知センターの未読数 (全 org 横断・自分宛のみ)。closure = Inertia partial reload で
            // 省略可能 (将来の router.reload({ only: ['notifications'] }) ポーリング拡張にも使える)
            'notifications' => [
                'unreadCount' => fn (): int => $user === null ? 0 : $user->unreadNotifications()->count(),
            ],
            // 自分宛の受諾可能な招待の件数 (全画面横断の気づき。裁定 AG-113 必須要素 (b)(c))。
            // ★件数は受諾の解決・一覧と**同一 scope** から算出する
            //   (ずれると「件数は出るのに受諾できない」が起きる)。
            // ★未ログイン・未 verified・email 空は pendingInvitationCountFor が
            //   DB を一切引かずに 0 を返す (全リクエストで評価されるため実効的な負荷契約)。
            // app() 解決にするのはコンストラクタ注入を増やさないため (contact prop と同じ流儀)。
            // ★キー名を 'invitations' にしない: ページ prop 'invitations' (Admin/Users の
            //   招待一覧) と衝突し、その画面だけ共有 prop が配列で上書きされて
            //   横断の気づきが黙って消える (通知の unreadCount と同じ衝突クラス)。
            'invitationInbox' => [
                'pendingCount' => fn (): int => app(OrganizationMembershipService::class)
                    ->pendingInvitationCountFor($user),
            ],
            'flash' => [
                'success' => $request->session()->get('success'),
                'error' => $request->session()->get('error'),
                'info' => $request->session()->get('info'),
                'warning' => $request->session()->get('warning'),
                'visitKey' => Str::uuid()->toString(),
            ],
            // 問い合わせ CTA の宛先 (内部 /contact / 外部 URL / mailto を config 駆動で切替)。
            'contact' => fn (): array => [
                'url' => app(ContactUrl::class)->resolve(),
                'kind' => app(ContactUrl::class)->kind()->value,
            ],
            // サーバ描画 <title> と同一文字列を共有し、SPA 遷移後の document.title 陳腐化を解消する
            // (resources/js/lib/document-title.ts が同期)。SeoManager は request-scoped で
            // SeoComposer と同じ実体 (二重 SoT を作らない)。controller の set / setPrivateTitle は
            // share 評価時点 (response 構築時) で反映済み。
            'title' => fn (): string => $this->seoManager->resolveDocumentTitle($request->route()?->getName()),
        ];
    }

    /**
```

### `tests/Architecture/TenantBoundaryOrderingTest.php` (L378-438)

```php
test('検査5: 代表 route の解決後 middleware 列を完全一致で固定する', function (): void {
    $webHead = [
        EncryptCookies::class,
        AddQueuedCookiesToResponse::class,
        StartSession::class,
        ShareErrorsFromSession::class,
        PreventRequestForgery::class,
        Authenticate::class,
        AuthenticateSession::class,
        SubstituteBindings::class,
    ];
    $webAppend = [
        HandleInertiaRequests::class,
        SecurityHeaders::class,
        RequireTwoFactorForEnforcedOrganizations::class,
        BlockTwoFactorDisableForEnforcedOrganizations::class,
        NoStoreCacheHeadersForAuthenticatedPages::class,
        EncryptHistory::class,
        EnsureEmailIsVerified::class,
    ];
    // bug-hunt の実行済み route 記録器は web 鎖の**最後** (遮断 middleware より内側)。
    $recorder = BughuntExecutedRouteMiddleware::class;
    $guard = EnsureProjectBelongsToRouteOrganization::class;
    $billing = RequireActiveSubscription::class;
    // 退会予約中の凍結は**課金ゲートの直後**。テナント境界 404 より必ず後 (302 短絡のため)。
    $freeze = EnsureAccountNotPendingDeletion::class;

    $apiHead = [
        Authenticate::class,
        ThrottleRequests::class,
        ResolveApiActor::class,
        SubstituteBindings::class,
        EnsureProjectBelongsToApiOrganization::class,
        RequireApiKeyAbility::class,
    ];

    $expected = [
        // API: actor 解決 → binding → テナント境界 404 → ability 403 → idempotency
        'api.v1.projects.items.store' => [...$apiHead, IdempotentRequest::class],
        'api.v1.projects.items.index' => $apiHead,
        // {project} を持たない route でも guard は列に載る (no-op。group 一括付与の許容)
        'api.v1.me' => $apiHead,
        // web: テナント境界 404 が Inertia / 2FA / verified / 課金ゲートより前。
        // 記録器は列の最後 (= 到達が「遮断をすべて通過した」証拠になる)
        'projects.update' => [...$webHead, $guard, ...$webAppend, $billing, $freeze, $recorder],
        'capture.manuals.show' => [...$webHead, $guard, ...$webAppend, $billing, $freeze, $recorder],
        // guard を持たない web route の列は変化しない (priority 追加の副作用が無いことの pin)
        'organizations.settings' => [...$webHead, ...$webAppend, $freeze, $recorder],
    ];

    $routes = app('router')->getRoutes();
    $routes->refreshNameLookups();

    foreach ($expected as $name => $expectedChain) {
        $route = $routes->getByName($name);
        expect($route)->not->toBeNull("route '{$name}' が存在しない");
        expect(NestedRouteDefenseInventory::resolvedMiddleware($route))
            ->toBe($expectedChain, "route '{$name}' の解決後 middleware 列");
    }
});

```

### `resources/js/lib/debug/bfcache-trial.ts` (L505-640)

```ts
        (event) =>
            event.type === "away-navigation-failed" &&
            event.sequence > away.sequence,
    );
}

// ---------------------------------------------------------------------------
// 軸 2: guard 結果判定
// ---------------------------------------------------------------------------

/**
 * 軸 2 は**軸 1 window の `page-show` より後**のイベントだけを見る。
 * 往路 (A → B) の `page-hide` をリダイレクト離脱として拾ってはならない。
 */
export function deriveGuardVerdict(events: TrialEvent[]): GuardVerdict {
    if (!hasSingleTrialId(events)) return "failed-transition";

    const window = findAxis1Window(events);
    const boundary = window?.show.sequence ?? Number.POSITIVE_INFINITY;
    const after = bySequence(events).filter(
        (event) => event.sequence > boundary,
    );

    // **最初の終端まででフィルタを閉じる**。終端後に fresh load の guard イベントが
    // 追記されても判定が崩れないようにするため (失効セッション経路では再ログイン後に
    // A を開き直すので、これが無いと確実に崩れる)。
    const states: GuardState[] = [];
    let hiddenThenLeft = false;
    let contradiction = false;

    for (const event of after) {
        if (event.type === "guard-state-changed") {
            states.push(event.state);
            if (states.length === 3) break; // 3 つ目で終端か異常かが決まる
            continue;
        }
        if (
            event.type === "page-hide" &&
            states.length === 2 &&
            states[0] === "pending" &&
            states[1] === "verifying"
        ) {
            // **離脱時点で実際に秘匿されていたか**を page-hide のスナップショットで確かめる。
            // guardState が null (= 秘匿解除済み) の離脱は「秘匿維持のまま離脱した」証拠に
            // ならない。証跡どうしの矛盾なので合格側へ倒さない
            if (event.guardState === "verifying") {
                hiddenThenLeft = true;
            } else {
                contradiction = true;
            }
            break;
        }
    }

    if (contradiction) return "failed-transition";

    const aborted = events.some((event) => event.type === "trial-aborted");

    if (states.length === 0) return aborted ? "not-observed" : "in-progress";

    // 正常遷移は pending → verifying → (null | retry)。prefix を異常扱いしない
    if (states[0] !== "pending") return "failed-transition";
    if (states.length === 1) return "in-progress";
    if (states[1] !== "verifying") return "failed-transition";

    if (states.length === 2) {
        if (!hiddenThenLeft) return "in-progress";
        return events.some((event) => event.type === "redirect-observed")
            ? "unauthenticated-redirected"
            : "hidden-then-left";
    }

    if (states[2] === null) return "authenticated-unhidden";
    if (states[2] === "retry") return "retry-hidden";
    return "failed-transition";
}

// ---------------------------------------------------------------------------
// 軸 3: 総合判定 / 進行状態
// ---------------------------------------------------------------------------

/** シナリオごとに期待される guard 結果。 */
export function expectedGuardVerdict(scenario: TrialScenario): GuardVerdict {
    return scenario === "expired-session"
        ? "unauthenticated-redirected"
        : "authenticated-unhidden";
}

/**
 * 総合判定。**軸 1 と軸 2 から導出するだけで、保存しない**。
 *
 * `in-progress` / `not-observed` / `hidden-then-left` を `undetermined` に落とすのが要点。
 * - `in-progress`: 観測途中。ここを fail にすると復元直後の正常な状態が FAIL 表示になる
 * - `not-observed`: guard が発火しなかったのか利用者が早く中止したのか**区別できない**
 * - `hidden-then-left`: `redirect-observed` が入るまで終端していない
 */
export function deriveOverallVerdict(
    scenario: TrialScenario,
    trial: TrialVerdict,
    guard: GuardVerdict,
): OverallVerdict {
    if (trial !== "valid-bfcache") return "undetermined";
    if (
        guard === "in-progress" ||
        guard === "not-observed" ||
        guard === "hidden-then-left"
    ) {
        return "undetermined";
    }
    if (guard === expectedGuardVerdict(scenario)) return "pass";
    if (guard === "failed-transition") return "fail";
    return "expectation-mismatch";
}

/**
 * 試行の進行状態。listener の追記可否をこの結果で決める。
 *
 * `in-progress` が `collecting-axis2` に写ることが要点である。これが無いと
 * 正常な `pending` / `pending → verifying` の途中で `complete` に落ちて
 * 自動追記が止まり、`null` / `retry` / 復元後 `page-hide` を記録できなくなる。
 */
export function deriveTrialPhase(events: TrialEvent[]): TrialPhase {
    if (!hasSingleTrialId(events)) return "invalid";
    if (events.some((event) => event.type === "trial-aborted")) return "aborted";

    const trial = deriveTrialVerdict(events);
    if (trial === "incomplete") return "collecting-axis1";
    if (trial !== "valid-bfcache") return "complete";

    const guard = deriveGuardVerdict(events);
    if (guard === "in-progress") return "collecting-axis2";
    if (guard === "hidden-then-left") return "awaiting-manual-confirmation";
    return "complete";
}

/** phase ごとに追記を許可するイベント種別。 */
```

### `resources/css/app.css` (L12-80)

```css
/* ===== bfcache 秘匿オーバーレイ (resources/js/lib/bfcache-guard.ts が制御) =====
   documentElement の秘匿属性 (data-bfcache-hidden) が bfcache 復元マーカー兼スイッチ。
   秘匿は「表示を止める」だけに限定し、DOM ツリー・media stream・未送信フォーム状態・
   Inertia 履歴は一切壊さない (visibility は要素を残したまま描画だけ止める)。
   色は DS token (tokens.css) 経由。hex は書かない。 */

#bfcache-guard-overlay {
    display: none;
}

html[data-bfcache-hidden] > body > *:not(#bfcache-guard-overlay) {
    visibility: hidden;
}

html[data-bfcache-hidden] > body > #bfcache-guard-overlay {
    visibility: visible;
    display: flex;
    position: fixed;
    inset: 0;
    z-index: 50;
    align-items: center;
    justify-content: center;
    padding: 24px;
    background-color: var(--color-neutral);
    color: var(--color-text);
}

.bfcache-guard__panel {
    display: flex;
    flex-direction: column;
    align-items: center;
    gap: 16px;
    max-width: 480px;
    text-align: center;
}

/* 既定は「確認中」表示。失敗時のみ失敗文言 + 再試行ボタンへ切り替える
   (押せない状態で放置しない = DESIGN.md の禁止事項 #8 の精神)。 */
#bfcache-guard-overlay [data-bfcache-guard-failure],
#bfcache-guard-overlay .bfcache-guard__retry {
    display: none;
}

html[data-bfcache-hidden='retry'] #bfcache-guard-overlay [data-bfcache-guard-verifying] {
    display: none;
}

html[data-bfcache-hidden='retry'] #bfcache-guard-overlay [data-bfcache-guard-failure] {
    display: block;
}

html[data-bfcache-hidden='retry'] #bfcache-guard-overlay .bfcache-guard__retry {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    min-height: 44px;
    padding: 0 16px;
    border-radius: var(--radius-md);
    background-color: var(--color-primary);
    color: var(--color-surface);
}

html[data-bfcache-hidden='retry'] #bfcache-guard-overlay .bfcache-guard__retry:hover {
    background-color: var(--color-primary-hover);
}

```

