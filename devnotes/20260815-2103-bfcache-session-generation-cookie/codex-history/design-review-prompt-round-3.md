# Round 3: Round 2 指摘への対応

Round 2 の 2 件 (S7 の日付境界仕様 / 検証コマンドの同期) と Suggestion 1 件に対応した。
対応マトリクスと、修正した箇所の抜粋を送る。全体判定を返してほしい。

## 対応マトリクス

# 対応マトリクス: design-review Round 2

## [Warning] S7: 期限検査の日付境界とタイムゾーンが未定義
- 判断: 対応する
- 根拠: 指摘のとおり。`diffInDays()` の符号や実行環境のタイムゾーン次第で、
  同じ文書が環境によって赤くも緑にもなりうる。期限検査は**将来のある日に
  自動で赤くなる**性質の検査なので、境界が曖昧だと「なぜ今日赤いのか」を
  説明できない検査になる。
- 対応内容: 判定仕様を表で固定した。基準日 `CarbonImmutable::today('UTC')` /
  書式は `YYYY-MM-DD` のみ / 未来日は赤 / 符号付き経過日数が 400 を超えたら赤 /
  ちょうど 400 日前は緑・401 日前は赤。テスト計画にも未来日・400 日前・401 日前を追加し、
  文書を書き換えずに境界を検査できるよう**日付判定を純粋関数に切り出す**とした。

## [Warning] 横断: 検証コマンドが AGENTS.md と一致していない
- 判断: 対応する
- 根拠: `AGENTS.md` の検証コマンド節は
  `tests/js/architecture/verification-commands-doc-sync.test.ts` が
  package.json と同期を強制している正本であり、設計書だけ 7 本に省略すると
  実装者が 3 本を回さないまま完了報告する。
- 対応内容: 検証コマンド節を AGENTS.md と完全一致 (全 10 本) にし、
  「全 10 本が green で実装完了」と書いた。

## [Suggestion] S1: session cookie が応答に出る状況をテストで作る
- 判断: 対応する
- 対応内容: 「session cookie は毎応答に出るとは限らないため、セッションを書き込む応答
  (ログイン応答など) を使って両方が同じ応答に載る状況を作る」をテスト計画へ明記した。

---

## 修正箇所の抜粋 (詳細設計より)

### S7 期限検査の判定仕様

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

### S7 テスト計画

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

### 検証コマンド節

## 検証コマンド

`AGENTS.md` の検証コマンド一覧と完全一致させる (全 10 本が green で実装完了):

`composer test` / `composer phpstan` / `vendor/bin/pint --test` /
`pnpm lint` / `pnpm typecheck` / `pnpm test` / `pnpm build` /
`pnpm typecheck:packages` / `pnpm build:packages` / `pnpm test:packages`

## 申し送り (本設計のスコープ外だが、後段で必ず扱うこと)

### S1 テスト計画 (cookie 属性の比較)

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
