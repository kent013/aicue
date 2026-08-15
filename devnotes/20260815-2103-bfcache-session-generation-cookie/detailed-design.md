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
use Illuminate\Support\Facades\Cookie;
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
 *   - **属性は session cookie と同じものを使う**。`CookieJar` の既定は
 *     `Illuminate\Cookie\CookieServiceProvider` が session config の
 *     path / domain / secure / same_site から設定しているので、
 *     ここで自前に組み立てず `Cookie::make()` の既定へ委ねる
 *     (自前で組むと framework の規則から静かにずれる)。
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

        // セッション ID の再生成 (ログイン・ログアウト) を同じ応答で拾うため、
        // 印は **$next の後** に導出する。
        $epoch = SessionEpoch::current($request);
        if ($epoch === null) {
            return $response;
        }

        $response->headers->setCookie(
            // 第 3 引数 0 = ブラウザセッション限り (印は「いまの世代」の写しであり保存する値ではない)。
            // path / domain / secure / sameSite は渡さない = session cookie と同じ既定が入る。
            Cookie::make(SessionEpoch::COOKIE_NAME, $epoch, 0, httpOnly: false),
        );

        return $response;
    }
}
```

`bootstrap/app.php` の 4 箇所 (**import の追加を忘れない**):

```php
use App\Http\Middleware\IssueSessionEpochCookie;
use App\Support\Auth\SessionEpoch;

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
- [x] `app.key` は `Config::string()` で型付き取得する
      (cookie 属性は `Cookie::make()` の既定に委ねるので `config()` の mixed を扱わない)
- [x] DTO を返す箇所は無い (本施策は middleware と Support クラスのみ)
- [x] Generics の型パラメータなし

### テスト計画

- [ ] 新規 `tests/Feature/Auth/SessionEpochCookieTest.php`
  - 認証済み応答に世代 cookie が付く。**値が平文で `SessionEpoch::forSession(セッション ID)`
    と一致する** (= 暗号化の除外が効いている。ここが本施策で最も壊れやすい配線)
  - guest 応答にも付く (「無い」状態を作らない)
  - cookie 属性: `HttpOnly` でないこと以外は**同じ応答の session cookie と同一**
    (期待値を直書きせず session cookie の属性と突き合わせる。
    `path` / `domain` / `secure` / `SameSite` を比較する)。
    session cookie は毎応答に出るとは限らないため、**セッションを書き込む応答**
    (ログイン応答など) を使って両方が同じ応答に載る状況を作る
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
//
// **closure で渡す (即値にしない)**。vendor の HandleInertiaRequests は
// `$next($request)` の**前**に `Inertia::share($this->share($request))` を呼ぶため、
// 即値だと「要求前のセッション ID」で固定される。AlwaysProp は callable を
// 応答構築時に解決する (ResolvesCallables) ので、closure なら
// 世代 cookie ($next の後に導出) と同じ時点のセッション ID になる。
SessionEpoch::SHARED_PROP_KEY => Inertia::always(
    fn (): ?string => SessionEpoch::current($request),
),
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
- [x] closure の戻り型を `?string` で明示する (`fn (): ?string => …`)
- [x] `SessionEpoch::current()` は `?string`

### テスト計画

- [ ] 新規 `tests/Feature/Auth/SessionEpochSharedPropTest.php`
  - 認証済みの Inertia 応答の props に `sessionEpoch` があり、
    **同じ応答の世代 cookie と同値**である (2 経路が同じ出所から出ていること)
  - **セッション ID が要求中に再生成される経路** (ログイン直後に Inertia 画面を返す経路 /
    `Auth::logoutOtherDevices` 相当) でも prop と cookie が同値である
    (= 遅延評価が効いていることの behavioral な固定。即値へ戻すと赤になる)
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
  (**クラス docblock の `{ authenticated }` も `{ authenticated, sessionEpochMatches }` へ更新する。
  差分で見落とされやすい**)

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

/**
 * document.cookie から現世代の写しを読む。書式が違えば null。
 *
 * **例外を投げない**。壊れた百分率エンコード (`%E0%A4%A` 等) で
 * `decodeURIComponent` は例外を投げるが、ここで落ちると秘匿属性が `pending` のまま
 * 誰も先へ進めない画面ができる。読めないものは null (= 読み直し) に倒す。
 */
export function readSessionEpochCookie(cookieHeader: string): string | null {
    for (const part of cookieHeader.split(";")) {
        const [name, ...rest] = part.split("=");
        if (name?.trim() !== SESSION_EPOCH_COOKIE) continue;
        let value: string;
        try {
            value = decodeURIComponent(rest.join("=").trim());
        } catch {
            return null;
        }
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
// probeSessionStatus: 描画世代をヘッダで運ぶ。
// **シグネチャが変わる** (第 2 引数を挿入する。既存呼び出しは verify() の 1 か所だけ):
//   probeSessionStatus(
//       fetchImpl: ProbeFetch,
//       renderedEpoch: string | null,
//       url: string = SESSION_STATUS_PATH,
//   ): Promise<SessionProbeOutcome>
const headers: Record<string, string> = { Accept: "application/json" };
if (renderedEpoch !== null) headers[SESSION_EPOCH_HEADER] = renderedEpoch;
// …fetch 後…
const status = readSessionStatus(await response.json());
if (status === null) return "failed";
if (!status.authenticated) return "unauthenticated";
return status.sessionEpochMatches ? "authenticated" : "stale";
```

```ts
// registerBfcacheGuard の依存 (既定は安全側。実運用は app.ts が両方を明示配線する)
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
// resources/js/app.ts の配線 (**2 つの出所を呼び出し側で名前付きで見せる**。
// 既定任せにすると、読み手が「描画世代と現世代がどこから来るか」を追えない)
const disposeBfcacheGuard = registerBfcacheGuard({
    isAuthenticated: () => hasAuthenticatedUser(page.props),
    // 描画世代は **いま画面に出ている内容と同じ応答で来た値**を使う
    // (cookie から読むと「内容は A・印は B」の取り違えが起きる)
    readRenderedEpoch: () => readSessionEpoch(page.props),
    // 現世代は cookie の写し。同期判定でしか使わない (開示の根拠にはしない)
    readCurrentEpoch: () => readSessionEpochCookie(document.cookie),
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
      書式違い → null / 不在 → null /
      **壊れた百分率エンコード (`session_epoch=%E0%A4%A`) で例外を投げず null**
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
  実配線 (`app.ts` が 2 つとも明示的に渡していること) を S7 の契約検査で固定する。
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

**なぜ終端名を分けないか (`reloading` は未認証とは限らないのに)**:
`redirect-observed` は T161 の定義で「**利用者が `/login` 到達を確認して記録する**手入力イベント」
であり、「何か起きた」の目視ではない。したがって
「読み直しに倒れた + `/login` 到達を目視確認した」= 未認証で `/login` に着いた、で意味は合う。
別の利用者としてアプリ画面に着地した試行では `/login` に着かないので利用者はこのイベントを
記録できず、判定は `stale-session-reloaded` (= 総合 `undetermined`) に留まる = **合格にならない**。
これは望ましい安全側の挙動なので、検証ページの目視確認の文言 (現行の `/login` 到達を問う形) は
**変えない**。`expectedGuardVerdict` も変えないため、**T085 の完了条件は動かない**。

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
   (開示側へは倒れない) / `/admin` (Filament) には印もガードも届かない —
   **受容の理由**は「独自 middleware stack を持ち web グループを通らず、Inertia でも
   描画されないため、印の配布経路もガードの入口も無い。管理面はサーバ側の保存禁止ヘッダのみで
   受容する」であり、スコープ漏れではないことを明記する。
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
  (文字列を検査側にも書くと 2 か所の正本になる)。手順は
  `array_keys(SessionStatusResource::make(new SessionStatusDto(authenticated: true,
  sessionEpochMatches: true))->toArray(request()))` で得たキー**すべて**が
  画面側ファイルに現れることを確かめる (キーが増えたら検査対象も自動で増える)。
- **保証範囲を誇張しない**: これは文字列の実在検査であり、
  **使われ方が正しいことは保証しない** (テストの docblock に明記する)。
  意味の正しさは vitest (分岐) と Feature (応答契約) が担う。

### 文書の期限検査（何のためか）

目的は「**実機再確認と一次情報の再確認が、未実施のまま忘れられるのを防ぐ**」ことである。
`docs/supported-browsers.md` にはブラウザ挙動の一次情報 (自動化ハーネスの版と起動スイッチ、
原因未特定の宿題) が載っており、これは時間で陳腐化する。

- 文書に**一次情報の最終確認日**の行を 1 行だけ持ち、日付を機械で読む。
- 行が無い / 日付が読めない / **未来の日付** / **経過日数が 400 を超える**とき赤にする。

**日付判定の仕様 (実装者ごとにぶれないよう固定する)**:

| 項目 | 決め |
|---|---|
| 基準日 | `CarbonImmutable::today('UTC')` (実行環境のタイムゾーンで境界が動かないように固定する) |
| 書式 | `YYYY-MM-DD` のみ。他の書式は「読めない」として赤 |
| 未来 | 確認日が基準日より後なら赤 (記入ミスを黙って通さない) |
| 経過 | 確認日から基準日までの**符号付き**経過日数が `400` を**超えたら**赤 |
| 境界 | ちょうど 400 日前は緑、401 日前は赤 |

- 失敗メッセージには「何を再確認するのか」(自動化ハーネスの版と起動スイッチの状況 /
  復元が再現しない原因の調査 / iOS 実機受入確認 (T085) の実施状況) を並べる。
- **保証しないもの**をテストの docblock に書く:
  確認日は自己申告であり、**日付を新しくしても内容が正しいことは担保しない**。
  この検査が担うのは「見直す機会を強制的に作る」ことだけである。

### テスト計画

- [ ] 契約ずれの検査: 上の 7 行がすべて緑 / 監視対象ファイルの実在検査 /
      **わざと片側だけ変えると赤になる**ことを実装時に手で 1 度確かめ、結果を devnotes に記す
- [ ] 期限検査: 現在日で緑 / 確認日の行を消すと赤 / 書式違いで赤 /
      **未来日で赤** / **ちょうど 400 日前で緑** / **401 日前で赤**
      (境界は文書を書き換えずに検査できるよう、日付判定を純粋関数に切り出して直接呼ぶ)

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

`AGENTS.md` の検証コマンド一覧と完全一致させる (全 10 本が green で実装完了):

`composer test` / `composer phpstan` / `vendor/bin/pint --test` /
`pnpm lint` / `pnpm typecheck` / `pnpm test` / `pnpm build` /
`pnpm typecheck:packages` / `pnpm build:packages` / `pnpm test:packages`

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
