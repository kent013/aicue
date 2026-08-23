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


## あなたの役割

Laravel 12 + Svelte 5 (Inertia) アプリの改善実装のコードレビュアーである。
下の詳細設計書と実装差分を読み、設計との一致性・正確性・安全性を審査せよ。

### レビュー観点

1. **設計との一致性** — 詳細設計書 (末尾の「実装時の設計補正」を含む) の決めごとを実装が満たしているか。
   ずれているなら、設計と実装のどちらを直すべきかも述べよ。
2. **正確性** — 分岐の抜け、fail-open になる経路、境界条件 (null / 空文字 / 書式違い / 並行) の取りこぼし。
   とくに「秘匿の解除 (開示) に到達する経路がプローブ応答ただ 1 本であること」が実装で本当に成り立つか。
3. **PHPStan level 10 適合性** — 型の緩め・暗黙 mixed・narrowing 漏れ。
4. **DTO / JsonResource パターン** — `response()->json()` の直書きが無いか。
5. **テスト網羅性** — 施策ごとにテストがあるか。負のコントロール (空振りを緑と読まない仕掛け) があるか。
   テストが実装の写経になっていて壊れても気づけない形になっていないか。
6. **セキュリティ** — cookie の属性・暗号化の除外範囲、印の導出 (鍵の用途分離)、
   画面側から書き換えられる値を判定の根拠に混ぜていないか、ログ・応答への値の漏れ。
7. **DESIGN.md 準拠** — `/DESIGN.md` が design token の canonical source。color / radius / typography は
   token 経由で参照し hex 直書き (`#RRGGBB`) を増やさない。token 値を変更する diff は
   `resources/css/tokens.css` と同一 diff 内で同期しているか。
8. **Atomic Design 準拠** — `resources/js/components/` は atoms/molecules/organisms/templates の
   責務分離に従う。階層を逆流していないか。アイコンは Lucide を使い SVG 直書きを増やさない。
9. **文書の書き方** — 保証範囲を誇張していないか。造語を作っていないか
   (初見の人が辞書どおりの意味で読んで解釈できる普通の日本語か)。

### 出力形式

- ファイルごとに判定を書く
- 指摘は **[Critical] / [Warning] / [Suggestion]** に分類する
- 最後に **全体判定: APPROVED または CHANGES_REQUESTED** を 1 行で書く


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

## 実装時の設計補正 (T178 実装で判明した差分。本書が正本)

実装 (`devnotes/20260816-0202-todo-T178/`) で本書の記述を次の 6 点に直した。

1. **印の書式に `D` 修飾子を付ける** — `VALUE_PATTERN` は
   `'/^[0-9a-f]{32}$/D'` である。PHP の `$` は**末尾の改行 1 個を許す**ため、
   付けないと `…ef\n` が書式として通ってしまい、改行を許さない画面側の JavaScript と
   判定がずれる。S7 の書式の導出も `trim(VALUE_PATTERN, '/^$D')` にする
   (導出結果を `[0-9a-f]{32}` と突き合わせてから使うので、外し方が壊れれば赤くなる)。
2. **S7 の行 3 (共有 prop のキー) のサーバ側は定数参照を見る** —
   `HandleInertiaRequests` はキーを文字列で書かず `SessionEpoch::SHARED_PROP_KEY` を
   参照するので、文字列リテラルの実在を要求すると必ず赤になる。サーバ側は
   **定数を参照していること**を、画面側は**値そのものの実在**を検査する。
3. **S7 の照合は識別子の境界で行う** — 素の部分文字列一致だと
   `session_epoch` → `session_epoch_renamed` の改名で元の語を含んだままになり、
   検査が赤くならない。前後が識別子文字でない位置に限って照合する。
4. **S7 の保証範囲を実測に合わせて書き直す** — 負のコントロールの実測は
   「宣言 1 行だけの改名では 6 通り中 2 通りしか赤にならない / 語をファイルから
   全消しすれば 6 通りとも赤になる」であった。同じ語が docblock・型宣言・許可値の配列に
   残っていると緑のままになるためで、これはファイル単位の実在検査の仕様どおりである。
   実測の記録は `devnotes/20260816-0202-todo-T178/contract-sync-negative-control.md`。
5. **救済 route のゲート目録への登録が要る (S1 の変更ファイル一覧に欠けていた)** —
   新しい middleware は救済 route (退会予約の取消) の経路にも載るため、
   `App\Enums\Security\RescueRouteGateDisposition` へ分類と根拠を足し、
   `RescueRouteGateInventoryTest` の母集団件数を 10 → 11 にする
   (deny-by-default なので登録しないと赤くなる = 無言では壊れない)。
6. **S5 の終端判定の規則を 1 つに確定する** — 「読み直しに倒れた」の終端候補が
   `guard-state-changed(state = "reloading")` で立ったときは、その `reloading` も
   **観測した状態列の一部として数える**。したがって:
   - `reloading` が状態列の先頭に来る (= 直前に `pending` を観測していない) 場合は
     `failed-transition`。
   - `page-hide` の `guardState` だけが `reloading` で状態変化を 1 つも観測できていない
     場合は、状態列が空なので先頭を問わず `stale-session-reloaded` (取りこぼし時の裏取り)。

## 実装モード

| 項目 | 内容 |
|------|------|
| 推奨モード | **standalone** |
| 判断根拠 | `bootstrap/app.php` の web グループと優先順位一覧、`TenantBoundaryOrderingTest` の完全一致列、共有 props、ガード、検証ページを 1 つの不変条件のために同時に変える。分割すると「印は出るが誰も読まない」「印が無く常に読み直し」の中途半端な状態が main に載る |
| 競合リスク | `bootstrap/app.php` (middleware 列 / priority list) と `HandleInertiaRequests::share()` は他施策も触りうる中心ファイルである。`TenantBoundaryOrderingTest` の完全一致列は、他の middleware 追加と衝突しやすい (どちらも赤で検出されるため無言の破壊にはならない) |


## 実装差分 (git diff)

```diff
diff --git a/app/DataTransferObjects/Auth/SessionStatusDto.php b/app/DataTransferObjects/Auth/SessionStatusDto.php
index 87780c8..44c4967 100644
--- a/app/DataTransferObjects/Auth/SessionStatusDto.php
+++ b/app/DataTransferObjects/Auth/SessionStatusDto.php
@@ -15,5 +15,7 @@
 {
     public function __construct(
         public bool $authenticated,
+        /** 要求が運んだ描画世代が、現在のセッションの世代と一致するか。 */
+        public bool $sessionEpochMatches,
     ) {}
 }
diff --git a/app/Enums/Security/RescueRouteGateDisposition.php b/app/Enums/Security/RescueRouteGateDisposition.php
index 16ee3ca..b16af8d 100644
--- a/app/Enums/Security/RescueRouteGateDisposition.php
+++ b/app/Enums/Security/RescueRouteGateDisposition.php
@@ -34,6 +34,7 @@ enum RescueRouteGateDisposition: string
     case RequireTwoFactor = 'App\Http\Middleware\RequireTwoFactorForEnforcedOrganizations';
     case BlockTwoFactorDisable = 'App\Http\Middleware\BlockTwoFactorDisableForEnforcedOrganizations';
     case NoStoreCacheHeaders = 'App\Http\Middleware\NoStoreCacheHeadersForAuthenticatedPages';
+    case IssueSessionEpochCookie = 'App\Http\Middleware\IssueSessionEpochCookie';
     case NotPendingDeletion = 'App\Http\Middleware\EnsureAccountNotPendingDeletion';
     case BughuntExecutedRoute = 'App\Http\Middleware\BughuntExecutedRouteMiddleware';
 
@@ -44,7 +45,8 @@ public function disposition(): RescueRouteGateKind
             self::RequireTwoFactor, self::NotPendingDeletion => RescueRouteGateKind::PassesRescueRoute,
             self::Authenticate, self::AuthenticateSession, self::EnsureEmailIsVerified => RescueRouteGateKind::ShortCircuitsButEscapable,
             self::HandleInertiaRequests, self::SecurityHeaders, self::BlockTwoFactorDisable,
-            self::NoStoreCacheHeaders, self::BughuntExecutedRoute => RescueRouteGateKind::NeverShortCircuitsRescueRoute,
+            self::NoStoreCacheHeaders, self::IssueSessionEpochCookie,
+            self::BughuntExecutedRoute => RescueRouteGateKind::NeverShortCircuitsRescueRoute,
         };
     }
 
@@ -77,6 +79,9 @@ public function rationale(): string
                 .'無条件に素通しする。救済 route の名前とは一致しないため短絡経路が構造的に無い。',
             self::NoStoreCacheHeaders => '認証済みページに Cache-Control: no-store を付けるだけの'
                 .'応答加工であり、リクエストを短絡させる分岐を持たない。救済の到達性に影響しない。',
+            self::IssueSessionEpochCookie => 'セッション世代の印を応答に cookie として載せるだけの'
+                .'応答加工であり、リクエストを短絡させる分岐を持たない。印が導出できない要求 '
+                .'(session を持たない) では cookie を付けずにそのまま返すため、救済の到達性に影響しない。',
             self::NotPendingDeletion => '退会予約中の凍結ゲート。救済 route は '
                 .'AccountDeletionFreezeAllowance::DeletionRequestDestroy として登録済みで、'
                 .'凍結中に必ず実行できなければ猶予期間の意味が消える。**non-exemptible**。',
diff --git a/app/Http/Controllers/Auth/SessionStatusController.php b/app/Http/Controllers/Auth/SessionStatusController.php
index 0ca7100..bfd071f 100644
--- a/app/Http/Controllers/Auth/SessionStatusController.php
+++ b/app/Http/Controllers/Auth/SessionStatusController.php
@@ -7,6 +7,7 @@
 use App\DataTransferObjects\Auth\SessionStatusDto;
 use App\Http\Controllers\Controller;
 use App\Http\Resources\Auth\SessionStatusResource;
+use App\Support\Auth\SessionEpoch;
 use Illuminate\Http\Request;
 
 /**
@@ -20,13 +21,28 @@
  * guest でも 200 + `authenticated: false` を返し、判定を明示 boolean 一本にする
  * (認証状態は同一オリジンの呼び出し元が cookie で既に知りうる情報であり、
  * これ自体は新たな情報露出にならない)。
+ *
+ * **秘匿を解く唯一の根拠がこの応答である**。復元された文書が持つ描画世代の印を
+ * `X-Session-Epoch` ヘッダで受け取り、いまのセッションの世代と一致するかを
+ * `sessionEpochMatches` で返す。認証済みでも世代が違えば画面側は開示せず読み直す。
+ * **現世代の値そのものは応答に載せない** (一致か否かだけ分かればよい)。
  */
 final class SessionStatusController extends Controller
 {
     public function __invoke(Request $request): SessionStatusResource
     {
-        return SessionStatusResource::make(
-            new SessionStatusDto(authenticated: $request->user() !== null),
-        );
+        // 照合に使うのは **要求ヘッダで運ばれた描画世代** だけである。
+        // 要求の Cookie ヘッダに載る世代 cookie は画面側から書き換えられる値なので、
+        // 一致判定には一切使わない (開示の根拠に client 側の状態を混ぜない)。
+        // 受け取ったヘッダ値はログにも応答にも出さない (外部由来の可変文字列)。
+        $submitted = $request->headers->get(SessionEpoch::HEADER_NAME);
+
+        return SessionStatusResource::make(new SessionStatusDto(
+            authenticated: $request->user() !== null,
+            sessionEpochMatches: SessionEpoch::matches(
+                is_string($submitted) ? $submitted : null,
+                SessionEpoch::current($request),
+            ),
+        ));
     }
 }
diff --git a/app/Http/Middleware/HandleInertiaRequests.php b/app/Http/Middleware/HandleInertiaRequests.php
index 5170289..f91727a 100644
--- a/app/Http/Middleware/HandleInertiaRequests.php
+++ b/app/Http/Middleware/HandleInertiaRequests.php
@@ -8,9 +8,11 @@
 use App\Models\User;
 use App\Services\Marketing\ContactUrl;
 use App\Services\Organization\OrganizationMembershipService;
+use App\Support\Auth\SessionEpoch;
 use App\Support\Seo\SeoManager;
 use Illuminate\Http\Request;
 use Illuminate\Support\Str;
+use Inertia\Inertia;
 use Inertia\Middleware;
 
 class HandleInertiaRequests extends Middleware
@@ -95,6 +97,18 @@ public function share(Request $request): array
             // SeoComposer と同じ実体 (二重 SoT を作らない)。controller の set / setPrivateTitle は
             // share 評価時点 (response 構築時) で反映済み。
             'title' => fn (): string => $this->seoManager->resolveDocumentTitle($request->route()?->getName()),
+            // 描画世代: この応答の内容がどのセッション世代のものかを、内容と同じ 1 通で運ぶ。
+            // **常に載せる** (Inertia の部分再読み込みで省略されると印だけ古くなるため)。
+            // これを cookie から読む形にすると「内容は A・印は B」の取り違えが起きる。
+            //
+            // **closure で渡す (即値にしない)**。vendor の Inertia\Middleware は
+            // $next($request) の**前**に Inertia::share($this->share($request)) を呼ぶため、
+            // 即値だと「要求前のセッション ID」で固定される。AlwaysProp は callable を
+            // 応答構築時に解決する (ResolvesCallables) ので、closure なら
+            // 世代 cookie ($next の後に導出) と同じ時点のセッション ID になる。
+            SessionEpoch::SHARED_PROP_KEY => Inertia::always(
+                fn (): ?string => SessionEpoch::current($request),
+            ),
         ];
     }
 
diff --git a/app/Http/Middleware/IssueSessionEpochCookie.php b/app/Http/Middleware/IssueSessionEpochCookie.php
new file mode 100644
index 0000000..064bb32
--- /dev/null
+++ b/app/Http/Middleware/IssueSessionEpochCookie.php
@@ -0,0 +1,61 @@
+<?php
+
+declare(strict_types=1);
+
+namespace App\Http\Middleware;
+
+use App\Support\Auth\SessionEpoch;
+use Closure;
+use Illuminate\Http\Request;
+use Illuminate\Support\Facades\Cookie;
+use Symfony\Component\HttpFoundation\Response;
+
+/**
+ * セッション世代の印を「画面側から読める cookie」として応答に載せる。
+ *
+ * 用途は 1 つだけで、bfcache から復元された画面が **通信を待たずに**
+ * 「世代が変わっている」と気づけるようにすることである。
+ * 開示 (秘匿の解除) の根拠にはならない — 開示はプローブ応答だけが決める
+ * (resources/js/lib/bfcache-guard.ts / SessionStatusController)。
+ *
+ * 契約:
+ *   - `$next` の**後**に、応答時点のセッション ID から導出する
+ *     (ログイン・ログアウトでのセッション ID 再生成を同じ応答で拾うため)。
+ *   - **未認証でも発行し、削除しない**。必ず現セッション由来の値で上書きする
+ *     (「印が無い」状態を作らない = 画面側が欠落と失効を取り違えない)。
+ *   - HttpOnly にしない (画面側が読む値であるため)。
+ *     **セッション ID そのものではない**ので、読めても乗っ取りには使えない。
+ *   - **属性は session cookie と同じものを使う**。`CookieJar` の既定は
+ *     `Illuminate\Cookie\CookieServiceProvider` が session config の
+ *     path / domain / secure / same_site から設定しているので、
+ *     ここで自前に組み立てず `Cookie::make()` の既定へ委ねる
+ *     (自前で組むと framework の規則から静かにずれる)。
+ *   - 暗号化の除外登録 (bootstrap/app.php) が必須。外すと画面側は復号できない
+ *     文字列を読み、常に不一致 = 復元のたびに読み直しになる (静かな劣化)。
+ *     この配線は Feature テストが平文値そのもので固定する。
+ */
+final class IssueSessionEpochCookie
+{
+    /**
+     * @param  Closure(Request): Response  $next
+     */
+    public function handle(Request $request, Closure $next): Response
+    {
+        $response = $next($request);
+
+        // セッション ID の再生成 (ログイン・ログアウト) を同じ応答で拾うため、
+        // 印は **$next の後** に導出する。
+        $epoch = SessionEpoch::current($request);
+        if ($epoch === null) {
+            return $response;
+        }
+
+        $response->headers->setCookie(
+            // 第 3 引数 0 = ブラウザセッション限り (印は「いまの世代」の写しであり保存する値ではない)。
+            // path / domain / secure / sameSite は渡さない = session cookie と同じ既定が入る。
+            Cookie::make(SessionEpoch::COOKIE_NAME, $epoch, 0, httpOnly: false),
+        );
+
+        return $response;
+    }
+}
diff --git a/app/Http/Middleware/NoStoreCacheHeadersForAuthenticatedPages.php b/app/Http/Middleware/NoStoreCacheHeadersForAuthenticatedPages.php
index c29c927..23f598d 100644
--- a/app/Http/Middleware/NoStoreCacheHeadersForAuthenticatedPages.php
+++ b/app/Http/Middleware/NoStoreCacheHeadersForAuthenticatedPages.php
@@ -12,14 +12,15 @@
  * 認証済みリクエストの web 応答に `no-store` を保証する baseline middleware。
  *
  * 目的: ログアウト後のブラウザ「戻る」で認証済み画面 (メンバー一覧等の PII) が
- * bfcache から再表示されるのを防ぐ。`no-store` により Firefox は bfcache 格納自体を
- * 拒否し、Chrome は cookie 変更 (= ログアウト) 時に CCNS ページを bfcache から
- * eviction する。副次的に disk / proxy cache への認証済み応答残留も禁止される。
+ * 再表示されるのを防ぐ。あわせて disk / proxy cache への認証済み応答の残留も禁じる。
  *
- * **Safari は `no-store` でも bfcache に格納しうる**ため本 middleware だけでは
- * 抑止できない。AI-CUE は撮影が PWA (iOS Safari が主要プラットフォーム) であるため、
- * クライアント側の bfcache 秘匿・再検証 (resources/js/lib/bfcache-guard.ts) と
- * **セットで** 主便益を達成する。対象ブラウザは docs/supported-browsers.md。
+ * **保存禁止ヘッダは「戻る」用の一時保存 (bfcache) への格納を禁じる指示ではない。**
+ * 格納するか・いつ捨てるかはブラウザの実装判断であり、このヘッダだけで復元を止められる
+ * 保証はどのブラウザについても持っていない。ブラウザごとの観測と一次情報の日付は
+ * docs/supported-browsers.md が正本である。
+ * したがって本 middleware は復元経路 B を単独では塞げず、クライアント側の
+ * bfcache 秘匿・再検証 (resources/js/lib/bfcache-guard.ts +
+ * セッション世代の印 App\Support\Auth\SessionEpoch) と **セットで** 主便益を達成する。
  *
  * さらに Inertia SPA のクライアント履歴復元 (popstate) はサーバへリクエストが飛ばないため
  * 本 middleware も bfcache guard も発火しない。その経路は Inertia 公式機構
diff --git a/app/Http/Resources/Auth/SessionStatusResource.php b/app/Http/Resources/Auth/SessionStatusResource.php
index eb2dd71..5ac1622 100644
--- a/app/Http/Resources/Auth/SessionStatusResource.php
+++ b/app/Http/Resources/Auth/SessionStatusResource.php
@@ -10,7 +10,7 @@
 use Illuminate\Http\Resources\Json\JsonResource;
 
 /**
- * セッション有効性プローブの XHR 応答 ({ authenticated })。
+ * セッション有効性プローブの XHR 応答 ({ authenticated, sessionEpochMatches })。
  *
  * top-level (data ラップなし) にするのは、クライアント guard が JSON shape を厳密判定
  * するため (RecentAuthStatusResource と同じ作法)。
@@ -27,12 +27,13 @@ final class SessionStatusResource extends JsonResource
     public static $wrap = null;
 
     /**
-     * @return array{authenticated: bool}
+     * @return array{authenticated: bool, sessionEpochMatches: bool}
      */
     public function toArray(Request $request): array
     {
         return [
             'authenticated' => $this->resource->authenticated,
+            'sessionEpochMatches' => $this->resource->sessionEpochMatches,
         ];
     }
 
diff --git a/app/Support/Auth/SessionEpoch.php b/app/Support/Auth/SessionEpoch.php
new file mode 100644
index 0000000..ba25b42
--- /dev/null
+++ b/app/Support/Auth/SessionEpoch.php
@@ -0,0 +1,86 @@
+<?php
+
+declare(strict_types=1);
+
+namespace App\Support\Auth;
+
+use Illuminate\Http\Request;
+use Illuminate\Support\Facades\Config;
+
+/**
+ * セッション世代の印 (bfcache 秘匿・再検証で使う) の単一の出所。
+ *
+ * 「印」は *いまのセッションを一意に指す短い文字列* で、セッション ID から
+ * 鍵付きハッシュで導出する。**セッション ID そのものは画面側へ出さない**
+ * (世代 cookie は画面側から読めるため、生の ID を載せると XSS で
+ * セッションの乗っ取りに直結する)。
+ *
+ * 印が変わる契機はセッション ID が変わる契機と 1 対 1 である
+ * (ログイン・ログアウト・セッション再生成)。これが「別の利用者の画面が
+ * 復元された」ことを検出できる根拠になる。
+ *
+ * **APP_KEY を入れ替えると全ての印が一斉に変わる**。復元済み文書は
+ * すべて読み直しへ倒れるだけで、開示側へは倒れない。
+ */
+final class SessionEpoch
+{
+    /** 画面側から読める cookie の名前 (暗号化の除外登録が必須)。 */
+    public const COOKIE_NAME = 'session_epoch';
+
+    /** プローブ要求が描画世代を運ぶヘッダの名前。 */
+    public const HEADER_NAME = 'X-Session-Epoch';
+
+    /** Inertia 共有 prop のキー (描画世代の運び方)。 */
+    public const SHARED_PROP_KEY = 'sessionEpoch';
+
+    /**
+     * 印の書式 (32 文字の 16 進小文字)。空文字と null を同一視しない。
+     *
+     * 末尾の `D` は「`$` を文字列の最後だけに一致させる」指定である。付けないと
+     * PHP の `$` は**末尾の改行 1 個を許す**ため、`…ef\n` が書式として通ってしまう
+     * (画面側の JavaScript の `$` は改行を許さないので、付けないと 2 つの実装がずれる)。
+     */
+    public const VALUE_PATTERN = '/^[0-9a-f]{32}$/D';
+
+    /** 同じ鍵を他用途と共有しないための区切り (用途の分離)。 */
+    private const PURPOSE = 'bfcache-session-epoch:v1';
+
+    /** セッション ID から印を導出する。 */
+    public static function forSession(string $sessionId): string
+    {
+        $digest = hash_hmac('sha256', self::PURPOSE.'|'.$sessionId, Config::string('app.key'));
+
+        return substr($digest, 0, 32);
+    }
+
+    /** その要求の現世代。session を持たない要求では null。 */
+    public static function current(Request $request): ?string
+    {
+        if (! $request->hasSession()) {
+            return null;
+        }
+
+        return self::forSession($request->session()->getId());
+    }
+
+    /** 書式が正しいか (長さ・文字種)。 */
+    public static function isWellFormed(?string $value): bool
+    {
+        return $value !== null && preg_match(self::VALUE_PATTERN, $value) === 1;
+    }
+
+    /**
+     * 描画世代と現世代が一致するか。
+     *
+     * **どちらかが無い / 書式が違うときは一致としない** (fail-closed)。
+     * 比較は hash_equals で行い、値はログにも応答にも出さない。
+     */
+    public static function matches(?string $submitted, ?string $current): bool
+    {
+        if (! self::isWellFormed($submitted) || ! self::isWellFormed($current)) {
+            return false;
+        }
+
+        return hash_equals((string) $current, (string) $submitted);
+    }
+}
diff --git a/bootstrap/app.php b/bootstrap/app.php
index bf7c206..d656900 100644
--- a/bootstrap/app.php
+++ b/bootstrap/app.php
@@ -17,6 +17,7 @@
 use App\Http\Middleware\EnsureProjectBelongsToRouteOrganization;
 use App\Http\Middleware\HandleInertiaRequests;
 use App\Http\Middleware\IdempotentRequest;
+use App\Http\Middleware\IssueSessionEpochCookie;
 use App\Http\Middleware\LocalOnly;
 use App\Http\Middleware\McpConsentOrganizationBinder;
 use App\Http\Middleware\NoStoreCacheHeadersForAuthenticatedPages;
@@ -34,6 +35,7 @@
 use App\Http\Resources\Billing\InsufficientTicketsResource;
 use App\Http\Resources\Billing\QuotaExceededResource;
 use App\Http\Resources\NotFoundMessageResource;
+use App\Support\Auth\SessionEpoch;
 use App\Support\Http\AdminPanelPath;
 use App\Support\Http\NotFoundMessage;
 use Illuminate\Auth\AuthenticationException;
@@ -137,6 +139,9 @@
             // 契約: $next から返った (= 下流の) 応答を確認し、既に `no-store` を持つなら変更しない。
             // (位置関係ではなくこの契約が正本。実効性は Feature テストが固定する)
             NoStoreCacheHeadersForAuthenticatedPages::class,
+            // セッション世代の印を画面側へ配る (bfcache 復元時の同期判定用)。
+            // 応答加工のみで短絡しない。順序の正本は下の priority list。
+            IssueSessionEpochCookie::class,
             // Inertia の履歴 state を AES-GCM で暗号化する (Inertia 公式のグローバル適用手順)。
             // ログアウト時に LogoutResponse が Inertia::clearHistory() で鍵を捨てるため、
             // ログアウト後の「戻る」は復号に失敗し、**コンポーネントを描画しないまま**
@@ -266,7 +271,8 @@
             [SecurityHeaders::class, RequireTwoFactorForEnforcedOrganizations::class],
             [RequireTwoFactorForEnforcedOrganizations::class, BlockTwoFactorDisableForEnforcedOrganizations::class],
             [BlockTwoFactorDisableForEnforcedOrganizations::class, NoStoreCacheHeadersForAuthenticatedPages::class],
-            [NoStoreCacheHeadersForAuthenticatedPages::class, EncryptHistory::class],
+            [NoStoreCacheHeadersForAuthenticatedPages::class, IssueSessionEpochCookie::class],
+            [IssueSessionEpochCookie::class, EncryptHistory::class],
             [EncryptHistory::class, EnsureEmailIsVerified::class],
             [EnsureEmailIsVerified::class, RequireActiveSubscription::class],
             // 退会予約中の凍結。**302 で短絡する**ため、テナント境界 404
@@ -327,6 +333,14 @@
             'ses/*',
         ]);
 
+        // 世代 cookie だけは画面側 (bfcache guard) が読むため暗号化しない。
+        // 中身はセッション ID から鍵付きハッシュで導出した印であってセッション ID ではない。
+        // 除外を外すと画面側は復号できない文字列を読み、常に不一致 = 復元のたびに読み直しになる
+        // (静かな劣化)。SessionEpochCookieTest が平文値そのもので固定する。
+        $middleware->encryptCookies(except: [
+            SessionEpoch::COOKIE_NAME,
+        ]);
+
         // bug-hunt (LLM 探索的バグハント) 用コード到達カバレッジ観測器。
         // env(BUGHUNT_PCOV) と function_exists('\pcov\start') の二重 guard を通らない限り
         // 完全 no-op (handle は $next をそのまま返し、terminate は即 return)。pcov 未導入の
diff --git a/docs/supported-browsers.md b/docs/supported-browsers.md
index b94d639..1ca7f10 100644
--- a/docs/supported-browsers.md
+++ b/docs/supported-browsers.md
@@ -2,19 +2,42 @@ # サポート対象ブラウザ方針
 
 AI-CUE が「どのブラウザで、どのレベルまで動作を保証しているか」の正本。
 
+一次情報の最終確認日: 2026-08-15
+
+> 上の行は `tests/Architecture/SupportedBrowsersDocFreshnessTest.php` が機械で読む
+> (書式は `YYYY-MM-DD` 固定、行は本書に 1 行だけ)。本書はブラウザ挙動の一次情報
+> (自動化ハーネスの版と起動スイッチ / 復元が再現しない原因 / 実機受入確認の実施状況) に
+> 依存しており、時間で陳腐化する。**日付は「見直した」ことの自己申告であって、
+> 内容が正しいことの担保ではない。**
+
 **Inertia が描画する認証済み画面**が「ログアウト後に復元される」経路は 3 本あり、
 それぞれ担当が違う。本書はその保証範囲を語るための前提として置く
 (Filament 管理パネル `/admin` は Inertia でも web グループでもないため本書の対象外)。
 
 | 経路 | 担当 | 何を保証するか |
 |------|------|----------------|
-| A: HTTP / disk / proxy cache、Chrome・Firefox の bfcache | `App\Http\Middleware\NoStoreCacheHeadersForAuthenticatedPages` | `no-store, private` により格納拒否 / cookie 変更時 evict |
-| B: Safari の真の bfcache (`pagehide` / `pageshow`) | `resources/js/lib/bfcache-guard.ts` + `session.status` プローブ (`App\Http\Controllers\Auth\SessionStatusController`) | **描画前に同期秘匿**し、セッション有効なら秘匿解除のみ (hard reload しない) |
+| A: HTTP / disk / proxy cache、ブラウザの「戻る」用の一時保存 (bfcache) | `App\Http\Middleware\NoStoreCacheHeadersForAuthenticatedPages` | `no-store, private` により **disk / proxy cache への残留を禁じる**。**bfcache へ格納するか・いつ捨てるかはブラウザの実装判断**であり、このヘッダで復元が止まることは保証しない |
+| B: 真の bfcache (`pagehide` / `pageshow`) | `resources/js/lib/bfcache-guard.ts` + セッション世代の印 (`App\Support\Auth\SessionEpoch` / `App\Http\Middleware\IssueSessionEpochCookie`) + `session.status` プローブ (`App\Http\Controllers\Auth\SessionStatusController`) | **描画前に同期秘匿**し、**認証済みかつ描画世代が現世代と一致**したときだけ秘匿解除する (hard reload しない)。世代が違えば秘匿を維持したまま同じ URL を読み直す |
 | C: Inertia SPA のクライアント履歴復元 (`popstate`) | `Inertia\Middleware\EncryptHistory` (web グループ) + `Inertia::clearHistory()` の発行契機 2 つ: **ログアウト** (`App\Http\Responses\Fortify\LogoutResponse`) と **認証失敗** (`bootstrap/app.php` の `AuthenticationException` render callback) | 発行契機の後は復号不能 → **コンポーネントを描画しないまま**再問い合わせ → `/login` |
 
 > 経路 B / C の実装は上表の参照点が正本 (将来の差分レビューで担当実装を辿れるよう、
 > 本書では実装ファイルを名指しする)。
 
+経路 B の**開示 (秘匿の解除) に到達する経路はただ 1 本**である。復元直後の判定は 2 段で、
+1 段目 (通信を待たない同期判定) は「読み直す」へしか到達しない:
+
+1. **同期判定**: 描画世代 (Inertia 共有 prop `sessionEpoch`) と世代 cookie (`session_epoch`) を
+   突き合わせる。どちらかが無い / 食い違うときは、プローブを 1 度も呼ばずに
+   秘匿を維持したまま同じ URL を読み直す。一致してもここでは開示せず 2 段目へ進む。
+2. **プローブ**: 描画世代を `X-Session-Epoch` ヘッダで送り、`authenticated` と
+   `sessionEpochMatches` の両方が真のときだけ秘匿を解く。認証済みでも世代が違えば読み直し、
+   未認証なら `/login` へ置換遷移、応答が読めなければ秘匿維持 + 再試行ボタンにする。
+   **サーバは要求ヘッダの値だけを照合に使い、要求の Cookie ヘッダに載る世代 cookie は使わない。**
+
+保証するのは「読み直しが完了して新しい文書が生成された場合、その文書は復元マーカー (秘匿属性) を
+継承しない」ことまでである。読み直し自体が通信障害で完了しないことは塞がない
+(既存の `/login` 置換遷移も同じ性質)。読み直しは 1 つの文書につき高々 1 回でループにはならない。
+
 経路 C の保証条件は「**`clearHistory: true` を含む Inertia page をクライアントが適用したタブ**」。
 `Inertia::clearHistory()` はサーバ session にフラグを積むだけで、`sessionStorage` の
 履歴暗号鍵が実際に消えるのは `page.set()` 冒頭の `history.clear()` が走った瞬間だからである
@@ -56,7 +79,7 @@ ## 対象ブラウザ
 
 撮影 PWA が中核 (使命 = 現場作業者がスマホで撮る) であり、**iOS Safari が最重要**。
 bfcache 周りの設計判断はすべてこの前提から来ている
-(Safari は `Cache-Control: no-store` のページでも bfcache に格納しうる)。
+(**保存禁止ヘッダが付いていても「戻る」で復元されうる環境がある。主戦場の iOS Safari を含む**)。
 
 ## Current — マージ後に実際に保証していること
 
@@ -135,11 +158,18 @@ ### 実機受入確認の再確認条件
 - `resources/css/app.css` の秘匿オーバーレイのスタイル (`#bfcache-guard-overlay` 周辺)
 - プローブ endpoint (`routes/web.php` の `session.status` /
   `App\Http\Controllers\Auth\SessionStatusController` / `SessionStatusResource`)
+  と**その応答契約** (`authenticated` / `sessionEpochMatches` の 2 キー)
+- セッション世代の印の供給元 (`App\Support\Auth\SessionEpoch` /
+  `App\Http\Middleware\IssueSessionEpochCookie` / `HandleInertiaRequests` の
+  共有 prop `sessionEpoch`)
 - `resources/js/lib/passkeys.ts` (WebAuthn ラッパ本体。上記「パスキーの保証範囲」)
 
 **docblock / コメントのみの変更はトリガに当たらない** (挙動が変わっていないため)。
 不要な実機再確認を誘発しないよう、トリガは「挙動変更」に限る。
 
+> **T178 (同期判定の前置) は挙動変更である**。guard 本体・プローブ応答契約・秘匿状態の語彙が
+> 変わったため上記トリガに当たり、**T085 の実機受入確認は T178 のマージ後に実施する**。
+
 記録先: `devnotes/<日付>-<topic>/` に日時・端末・iOS バージョン・実施シナリオ・結果を残す。
 **本書には「いつ・何を確認したか」を書かない** (記録の二重管理を作らない)。
 
@@ -169,6 +199,14 @@ ### 検証ページ (`/debug/bfcache-trial`) — 手動確認の補助
 完全自動判定ではない)。この表現は `docs/TODO.md` の T085 の記述と揃えること
 (片方だけ読んだ人が自動判定と誤解しないため)。
 
+**T178 以降、失効セッション経路で検証ページが観測するのは「秘匿を維持したまま読み直す」**である
+(世代 cookie が入れ替わっているため、同期判定がプローブより前に読み直しへ倒す)。
+guard の秘匿状態には `reloading` が加わり、検証ページはこれを軸 2 の終端候補
+`stale-session-reloaded` (目視確認待ち) として扱う。**合格終端は `unauthenticated-redirected` のままで、
+T085 の完了条件は変わらない** — 目視確認の記録が入って初めて合格終端になる。
+別の利用者としてアプリ画面に着地した試行は `/login` に着かず目視確認を記録できないので、
+判定は目視確認待ちに留まり合格にならない (意図した安全側の挙動)。
+
 トンネル運用規律 (実機からの到達には HTTPS トンネルが要る。`APP_ENV=local` のまま露出する運用のため、
 誤公開時の影響を軽く見ない):
 
@@ -230,8 +268,14 @@ ## 未対応事項 (誤読を防ぐため明示列挙する)
   (1) 表示中の PII は塞げないため目的を達しない、
   (2) 通常の戻る/進むに毎回ネットワーク往復と秘匿オーバーレイが入り、プローブ失敗時は
       「再試行」で操作が塞がれる (現場の不安定な回線で**新しい詰み**を作る)。
+- **世代 cookie を画面側から読めない環境では、復元のたびに読み直しになる**。
+  同期判定は現世代を読めないと「読み直す」へ倒すためで、**開示側へは倒れない**。
+  体感は「戻ると再読込」になる。
 - **非 Inertia 面 (Filament `/admin`) は経路 B / C の保証外**。独自 middleware stack を持ち
-  web グループを経由せず、Inertia でも描画されない。
+  web グループを経由せず、Inertia でも描画されない。したがって**セッション世代の印の配布経路も
+  guard の入口も無い** (世代 cookie は web グループの middleware が発行し、guard は
+  Inertia の入口スクリプトが登録するため)。管理面はサーバ側の保存禁止ヘッダのみで受容する。
+  **スコープからの漏れではなく、受容した非対称である。**
 - **非セキュアコンテキスト (`http://` の LAN IP 等) では経路 C が degrade する**。
   `window.crypto.subtle` が無い環境で Inertia は履歴を平文で保存する (`console.warn` のみ)。
   撮影 PWA は `getUserMedia` / Service Worker のためセキュアコンテキスト必須であり、
diff --git a/resources/js/app.ts b/resources/js/app.ts
index c47a3a7..1f77c6e 100644
--- a/resources/js/app.ts
+++ b/resources/js/app.ts
@@ -1,10 +1,10 @@
 import { createInertiaApp, page } from "@inertiajs/svelte";
 import { hydrate, mount } from "svelte";
 import { resolvePage } from "./inertia";
-import { registerBfcacheGuard } from "./lib/bfcache-guard";
+import { readSessionEpochCookie, registerBfcacheGuard } from "./lib/bfcache-guard";
 import { registerDocumentTitleSync } from "./lib/document-title";
 import { registerRecentAuthRedirectHandler } from "./lib/recent-auth";
-import { hasAuthenticatedUser } from "./lib/shared-props";
+import { hasAuthenticatedUser, readSessionEpoch } from "./lib/shared-props";
 
 // SPA 遷移後の document.title 陳腐化を解消する。Svelte adapter には createInertiaApp の
 // title callback が無いため、router.on('navigate') を購読してサーバ共有 prop `title` を
@@ -20,8 +20,15 @@ if (typeof document !== "undefined") {
     // login は Inertia の client-side 遷移で完了するため、「起動時 guest だった document が
     // そのまま認証済み画面になる」経路があり、起動時 1 回の判定では取りこぼす。
     // 公開ページ (LP / login / SEO) では秘匿もプローブも起こらない点は同じ。
+    // 2 つの世代の出所は呼び出し側で名前付きで明示する (既定任せにすると、読み手が
+    // 「描画世代と現世代がどこから来るか」を追えない)。
     const disposeBfcacheGuard = registerBfcacheGuard({
         isAuthenticated: () => hasAuthenticatedUser(page.props),
+        // 描画世代は **いま画面に出ている内容と同じ応答で来た値**を使う
+        // (cookie から読むと「内容は A・印は B」の取り違えが起きる)
+        readRenderedEpoch: () => readSessionEpoch(page.props),
+        // 現世代は cookie の写し。同期判定でしか使わない (開示の根拠にはしない)
+        readCurrentEpoch: () => readSessionEpochCookie(document.cookie),
     });
     import.meta.hot?.dispose(disposeBfcacheGuard);
 
diff --git a/resources/js/lib/bfcache-guard.ts b/resources/js/lib/bfcache-guard.ts
index 3c61b8c..657e407 100644
--- a/resources/js/lib/bfcache-guard.ts
+++ b/resources/js/lib/bfcache-guard.ts
@@ -13,14 +13,24 @@
  * ただし hard reload は常用しない。撮影中の media stream・未送信フォーム・Inertia 履歴を
  * 破棄してしまい、撮影 PWA という使命に直撃するため。有効なら **秘匿を外すだけ**にする。
  *
- * | # | 契機                | 動作                                                        |
- * |---|---------------------|-------------------------------------------------------------|
- * | 1 | pagehide            | documentElement に秘匿属性を同期付与 (この DOM ごと bfcache へ) |
- * | 2 | pageshow (属性あり) | 秘匿のまま軽量プローブ (/session/status)                      |
- * | 3 | セッション有効       | 秘匿属性を外すだけ (DOM / フォーム / Inertia 履歴は温存)       |
- * | 4 | セッション無効       | login へ hard navigation (遷移先は固定の相対パス)             |
- * | 5 | プローブ失敗         | 秘匿維持 + 再試行ボタン表示 (自動再試行はしない)              |
- * | 6 | 再試行押下           | 現在 URL を hard reload (サーバに再判定させる)                |
+ * | # | 契機                     | 動作                                                        |
+ * |---|--------------------------|-------------------------------------------------------------|
+ * | 1 | pagehide                 | documentElement に秘匿属性を同期付与 (この DOM ごと bfcache へ) |
+ * | 2 | pageshow (属性あり)      | まず同期判定 → 一致したときだけ軽量プローブ (/session/status)  |
+ * | 3 | 同期判定が不一致・不明   | 秘匿を維持したまま同じ URL を読み直す (通信を待たない)         |
+ * | 4 | 認証済み + 世代が一致    | 秘匿属性を外すだけ (DOM / フォーム / Inertia 履歴は温存)       |
+ * | 5 | 認証済み + 世代が不一致  | 秘匿を維持したまま読み直す (別のセッションの文書だった)        |
+ * | 6 | セッション無効           | login へ hard navigation (遷移先は固定の相対パス)             |
+ * | 7 | プローブ失敗             | 秘匿維持 + 再試行ボタン表示 (自動再試行はしない)              |
+ * | 8 | 再試行押下               | 現在 URL を hard reload (サーバに再判定させる)                |
+ *
+ * **開示 (秘匿の解除) に到達する経路はただ 1 本** — プローブが「認証済み **かつ**
+ * 描画世代が現世代と一致」と答えたときだけである。同期判定は通信を待たずに
+ * 「読み直す」へ倒すためだけにあり、開示を表す結論を持たない (型で表現している)。
+ *
+ * 読み直しは 1 つの文書につき高々 1 回で、ループにはならない (読み直した先の文書は
+ * 復元ではないので秘匿属性を持たず、ガードは何もしない)。保証するのはここまでで、
+ * 読み直し自体が通信障害で完了しないことは塞がない (既存の /login 置換遷移も同じ性質)。
  *
  * 復元マーカーは **documentElement の秘匿属性そのもの**。sessionStorage は使わない
  * (タブ単位で共有されるため、ページ A の pagehide が立てたフラグを通常遷移先のページ B が
@@ -68,6 +78,16 @@ export const BFCACHE_HIDDEN_ATTRIBUTE = "data-bfcache-hidden";
 export const BFCACHE_STATE_PENDING = "pending";
 export const BFCACHE_STATE_VERIFYING = "verifying";
 export const BFCACHE_STATE_RETRY = "retry";
+/** 世代が変わっていたので、秘匿したまま同じ URL を読み直している状態。 */
+export const BFCACHE_STATE_RELOADING = "reloading";
+
+/** セッション世代の印を運ぶヘッダ (PHP の SessionEpoch::HEADER_NAME と対)。 */
+export const SESSION_EPOCH_HEADER = "X-Session-Epoch";
+/** 現世代の写しを運ぶ cookie (PHP の SessionEpoch::COOKIE_NAME と対)。 */
+export const SESSION_EPOCH_COOKIE = "session_epoch";
+
+/** 印の書式 (PHP の SessionEpoch::VALUE_PATTERN と対)。 */
+const SESSION_EPOCH_PATTERN = /^[0-9a-f]{32}$/;
 
 /** プローブ endpoint。サーバ側は routes/web.php の `session.status` (auth グループ外)。 */
 export const SESSION_STATUS_PATH = "/session/status";
@@ -103,10 +123,64 @@ export interface BfcacheGuardDeps {
      * 公開ページ (LP / login / SEO) では秘匿もプローブも行わない。
      */
     isAuthenticated?: () => boolean;
+    /**
+     * いま画面に出ている内容がどのセッション世代の応答で来たか (描画世代)。
+     * 出所は Inertia 共有 prop `sessionEpoch` であり、**cookie ではない**。
+     */
+    readRenderedEpoch?: () => string | null;
+    /** 現世代の写し (世代 cookie)。同期判定でしか使わない。 */
+    readCurrentEpoch?: () => string | null;
+}
+
+/** 同期判定の結論。**開示を表す値を持たない** (開示はプローブだけが決める)。 */
+export type SyncEpochDecision = "must-reload" | "undecided";
+
+/**
+ * 通信を待たない前置判定。
+ *
+ * 一致しても "undecided" (= プローブへ進む) にしかならない。
+ * この関数が返しうる値に「開示」が無いことが、
+ * 「同期判定は開示しない側にしか到達しない」という不変条件の型による表現である。
+ */
+export function decideBySyncEpoch(
+    rendered: string | null,
+    current: string | null,
+): SyncEpochDecision {
+    if (rendered === null || current === null) return "must-reload";
+    return rendered === current ? "undecided" : "must-reload";
+}
+
+/**
+ * document.cookie から現世代の写しを読む。書式が違えば null。
+ *
+ * **例外を投げない**。壊れた百分率エンコード (`%E0%A4%A` 等) で
+ * `decodeURIComponent` は例外を投げるが、ここで落ちると秘匿属性が `pending` のまま
+ * 誰も先へ進めない画面ができる。読めないものは null (= 読み直し) に倒す。
+ */
+export function readSessionEpochCookie(cookieHeader: string): string | null {
+    for (const part of cookieHeader.split(";")) {
+        const [name, ...rest] = part.split("=");
+        if (name?.trim() !== SESSION_EPOCH_COOKIE) continue;
+        let value: string;
+        try {
+            value = decodeURIComponent(rest.join("=").trim());
+        } catch {
+            return null;
+        }
+        return SESSION_EPOCH_PATTERN.test(value) ? value : null;
+    }
+    return null;
 }
 
-/** プローブの判定結果。`failed` は「セッション無効」ではなく「判定不能」。 */
-export type SessionProbeOutcome = "authenticated" | "unauthenticated" | "failed";
+/**
+ * プローブの判定結果。`failed` は「セッション無効」ではなく「判定不能」。
+ * `stale` = 認証済みだが世代が違う (別の利用者・別の世代の文書だった)。
+ */
+export type SessionProbeOutcome =
+    | "authenticated"
+    | "stale"
+    | "unauthenticated"
+    | "failed";
 
 /** Content-Type の media type 判定 (charset 等のパラメータは許容する)。 */
 export function isJsonMediaType(contentType: string | null): boolean {
@@ -116,40 +190,57 @@ export function isJsonMediaType(contentType: string | null): boolean {
 }
 
 /**
- * プローブ応答の shape 厳密判定。top-level に boolean の `authenticated` を持つ
- * plain object のみ受理する (data ラップ・型違いは判定不能として弾く)。
+ * プローブ応答の shape 厳密判定。top-level に boolean の `authenticated` と
+ * `sessionEpochMatches` が**両方**揃った plain object のみ受理する
+ * (data ラップ・型違い・旧 shape は判定不能として弾く = 契約ずれが開示に倒れない)。
  */
-export function readAuthenticatedFlag(payload: unknown): boolean | null {
+export function readSessionStatus(
+    payload: unknown,
+): { authenticated: boolean; sessionEpochMatches: boolean } | null {
     if (typeof payload !== "object" || payload === null || Array.isArray(payload)) {
         return null;
     }
-    const value = (payload as Record<string, unknown>).authenticated;
-    return typeof value === "boolean" ? value : null;
+    const record = payload as Record<string, unknown>;
+    const authenticated = record.authenticated;
+    const matches = record.sessionEpochMatches;
+    if (typeof authenticated !== "boolean" || typeof matches !== "boolean") {
+        return null;
+    }
+    return { authenticated, sessionEpochMatches: matches };
 }
 
 /**
- * セッション有効性を問い合わせる。
+ * セッション有効性と世代の一致を問い合わせる。
  * (1) response.ok (2) Content-Type が JSON (3) JSON shape が厳密 — の全てを満たした時のみ
  * 結果を採用し、1 つでも崩れたら `failed` (秘匿維持) に倒す。
+ *
+ * 描画世代は `X-Session-Epoch` ヘッダで運ぶ。**サーバはこのヘッダだけを照合に使う**
+ * (要求の Cookie ヘッダに載る世代 cookie は一致判定に使わない)。
+ * 描画世代が無いときはヘッダを付けない (空文字を送らない = サーバ側は不一致に倒す)。
  */
 export async function probeSessionStatus(
     fetchImpl: ProbeFetch,
+    renderedEpoch: string | null,
     url: string = SESSION_STATUS_PATH,
 ): Promise<SessionProbeOutcome> {
+    const headers: Record<string, string> = { Accept: "application/json" };
+    if (renderedEpoch !== null) headers[SESSION_EPOCH_HEADER] = renderedEpoch;
+
     try {
         const response = await fetchImpl(url, {
             credentials: "same-origin",
             cache: "no-store",
-            headers: { Accept: "application/json" },
+            headers,
         });
 
         if (!response.ok) return "failed";
         if (!isJsonMediaType(response.headers.get("Content-Type"))) return "failed";
 
-        const authenticated = readAuthenticatedFlag(await response.json());
-        if (authenticated === null) return "failed";
+        const status = readSessionStatus(await response.json());
+        if (status === null) return "failed";
+        if (!status.authenticated) return "unauthenticated";
 
-        return authenticated ? "authenticated" : "unauthenticated";
+        return status.sessionEpochMatches ? "authenticated" : "stale";
     } catch {
         return "failed";
     }
@@ -232,6 +323,11 @@ export function registerBfcacheGuard(deps: BfcacheGuardDeps = {}): () => void {
     const fetchImpl: ProbeFetch =
         deps.fetchImpl ?? ((input, init) => fetch(input, init) as Promise<ProbeResponseLike>);
     const isAuthenticated = deps.isAuthenticated ?? (() => false);
+    // **描画世代の既定を cookie にしてはならない**。両方が同じ出所になると常に一致し、
+    // 同期判定が素通しになる (前置がある振りだけになる)。既定は安全側 (= 読み直し)。
+    const readRenderedEpoch = deps.readRenderedEpoch ?? (() => null);
+    const readCurrentEpoch =
+        deps.readCurrentEpoch ?? (() => readSessionEpochCookie(doc.cookie));
 
     const overlay = ensureOverlay(doc);
     const retryButton = overlay.querySelector<HTMLButtonElement>(`#${BFCACHE_RETRY_BUTTON_ID}`);
@@ -242,10 +338,21 @@ export function registerBfcacheGuard(deps: BfcacheGuardDeps = {}): () => void {
     };
     retryButton?.addEventListener("click", onRetry);
 
+    const reloadHidden = (): void => {
+        // 秘匿を維持したまま同じ URL を読み直す。読み直した文書には秘匿属性が無いので
+        // ガードは何もせず、1 文書につき読み直しは高々 1 回でループにならない。
+        setHiddenState(doc, BFCACHE_STATE_RELOADING);
+        win.location.reload();
+    };
+
     const verify = async (): Promise<void> => {
         setHiddenState(doc, BFCACHE_STATE_VERIFYING);
 
-        const outcome = await probeSessionStatus(fetchImpl, SESSION_STATUS_PATH);
+        const outcome = await probeSessionStatus(
+            fetchImpl,
+            readRenderedEpoch(),
+            SESSION_STATUS_PATH,
+        );
         if (outcome === "authenticated") {
             clearHiddenState(doc);
             return;
@@ -255,6 +362,11 @@ export function registerBfcacheGuard(deps: BfcacheGuardDeps = {}): () => void {
             win.location.replace(LOGIN_PATH);
             return;
         }
+        if (outcome === "stale") {
+            // いまの利用者は認証済みなので /login へは倒さない (嘘の着地を作らない)。
+            reloadHidden();
+            return;
+        }
         setHiddenState(doc, BFCACHE_STATE_RETRY);
     };
 
@@ -268,6 +380,12 @@ export function registerBfcacheGuard(deps: BfcacheGuardDeps = {}): () => void {
         // 復元マーカーは秘匿属性そのもの。通常ロードではサーバ由来の新しい HTML に
         // 属性が無いため、ここで抜ける。
         if (!isHidden(doc)) return;
+        // 通信を待たない前置判定。描画世代と世代 cookie が食い違う (または読めない) なら
+        // プローブを 1 度も呼ばずに読み直す。一致しても開示はせず、プローブへ進むだけ。
+        if (decideBySyncEpoch(readRenderedEpoch(), readCurrentEpoch()) === "must-reload") {
+            reloadHidden();
+            return;
+        }
         void verify();
     };
 
diff --git a/resources/js/lib/debug/bfcache-trial.ts b/resources/js/lib/debug/bfcache-trial.ts
index 1feab65..b97a4cc 100644
--- a/resources/js/lib/debug/bfcache-trial.ts
+++ b/resources/js/lib/debug/bfcache-trial.ts
@@ -20,8 +20,11 @@
  * 全体設計は devnotes/20260812-1931-bfcache-device-verification-page/detailed-design.md。
  */
 
-/** schema 変更時に必ず上げる。復元時に不一致なら破棄する。 */
-export const TRIAL_SCHEMA_VERSION = 1;
+/**
+ * schema 変更時に必ず上げる。復元時に不一致なら破棄する。
+ * 2 = guard の状態語彙に `reloading` が増えた版 (旧記録は読み捨てる)。
+ */
+export const TRIAL_SCHEMA_VERSION = 2;
 
 /** sessionStorage のキー。 */
 export const TRIAL_STORAGE_KEY = "bfcache-trial:v1";
@@ -30,7 +33,7 @@ export const TRIAL_STORAGE_KEY = "bfcache-trial:v1";
 export type TrialScenario = "expired-session" | "active-session";
 
 /** guard の秘匿属性がとりうる値 (属性削除は null で表す)。 */
-export type GuardState = "pending" | "verifying" | "retry" | null;
+export type GuardState = "pending" | "verifying" | "retry" | "reloading" | null;
 
 /** 利用者申告フィールドの制約 (自由記述の抜け道にしない)。 */
 export const DEVICE_MODEL_MAX_LENGTH = 40;
@@ -177,6 +180,8 @@ export type GuardVerdict =
     | "authenticated-unhidden"
     | "unauthenticated-redirected"
     | "hidden-then-left"
+    /** 秘匿を維持したまま読み直しに倒れた (目視確認待ち)。 */
+    | "stale-session-reloaded"
     | "retry-hidden"
     | "failed-transition"
     | "not-observed";
@@ -238,6 +243,7 @@ const GUARD_STATES: readonly GuardState[] = [
     "pending",
     "verifying",
     "retry",
+    "reloading",
     null,
 ] as const;
 
@@ -272,7 +278,7 @@ function isEventType(value: unknown): value is TrialEventType {
 /**
  * 1 イベントを厳密に検証する。shape が少しでも崩れていたら `null` を返す。
  *
- * `bfcache-guard.ts` の `readAuthenticatedFlag()` と同じ
+ * `bfcache-guard.ts` の `readSessionStatus()` と同じ
  * 「shape を厳密判定し、崩れていたら採用しない」idiom に揃えている。
  */
 export function parseTrialEvent(value: unknown): TrialEvent | null {
@@ -531,31 +537,57 @@ export function deriveGuardVerdict(events: TrialEvent[]): GuardVerdict {
     const states: GuardState[] = [];
     let hiddenThenLeft = false;
     let contradiction = false;
+    let reloaded = false;
 
     for (const event of after) {
         if (event.type === "guard-state-changed") {
             states.push(event.state);
+            // 読み直しは終端候補。ここで走査を打ち切る (読み直し後の fresh load の
+            // イベントが追記されても判定が崩れないようにするため)
+            if (event.state === "reloading") {
+                reloaded = true;
+                break;
+            }
             if (states.length === 3) break; // 3 つ目で終端か異常かが決まる
             continue;
         }
-        if (
-            event.type === "page-hide" &&
-            states.length === 2 &&
-            states[0] === "pending" &&
-            states[1] === "verifying"
-        ) {
-            // **離脱時点で実際に秘匿されていたか**を page-hide のスナップショットで確かめる。
-            // guardState が null (= 秘匿解除済み) の離脱は「秘匿維持のまま離脱した」証拠に
-            // ならない。証跡どうしの矛盾なので合格側へ倒さない
-            if (event.guardState === "verifying") {
-                hiddenThenLeft = true;
-            } else {
-                contradiction = true;
+        if (event.type === "page-hide") {
+            // 属性変化の観測を取りこぼしても、離脱時点のスナップショットが裏取りになる
+            if (event.guardState === "reloading") {
+                reloaded = true;
+                break;
+            }
+            if (
+                states.length === 2 &&
+                states[0] === "pending" &&
+                states[1] === "verifying"
+            ) {
+                // **離脱時点で実際に秘匿されていたか**を page-hide のスナップショットで確かめる。
+                // guardState が null (= 秘匿解除済み) の離脱は「秘匿維持のまま離脱した」証拠に
+                // ならない。証跡どうしの矛盾なので合格側へ倒さない
+                if (event.guardState === "verifying") {
+                    hiddenThenLeft = true;
+                } else {
+                    contradiction = true;
+                }
+                break;
             }
-            break;
         }
     }
 
+    if (reloaded) {
+        // 正常遷移は必ず pending から始まる。状態変化を 1 つも観測できていない
+        // (page-hide の裏取りだけ) 場合は先頭を問わない
+        if (states.length > 0 && states[0] !== "pending") return "failed-transition";
+
+        // `redirect-observed` は「利用者が /login 到達を確認して記録する」手入力イベントである。
+        // 別の利用者としてアプリ画面へ着地した試行では /login に着かないので記録できず、
+        // 判定は目視確認待ちに留まる (= 合格にならない安全側の挙動)
+        return events.some((event) => event.type === "redirect-observed")
+            ? "unauthenticated-redirected"
+            : "stale-session-reloaded";
+    }
+
     if (contradiction) return "failed-transition";
 
     const aborted = events.some((event) => event.type === "trial-aborted");
@@ -593,10 +625,13 @@ export function expectedGuardVerdict(scenario: TrialScenario): GuardVerdict {
 /**
  * 総合判定。**軸 1 と軸 2 から導出するだけで、保存しない**。
  *
- * `in-progress` / `not-observed` / `hidden-then-left` を `undetermined` に落とすのが要点。
+ * `in-progress` / `not-observed` / `hidden-then-left` / `stale-session-reloaded` を
+ * `undetermined` に落とすのが要点。
  * - `in-progress`: 観測途中。ここを fail にすると復元直後の正常な状態が FAIL 表示になる
  * - `not-observed`: guard が発火しなかったのか利用者が早く中止したのか**区別できない**
  * - `hidden-then-left`: `redirect-observed` が入るまで終端していない
+ * - `stale-session-reloaded`: 読み直しに倒れただけでは着地先が分からない
+ *   (未認証で /login に着いたのか、別の利用者としてアプリ画面に着いたのかを A から観測できない)
  */
 export function deriveOverallVerdict(
     scenario: TrialScenario,
@@ -607,7 +642,8 @@ export function deriveOverallVerdict(
     if (
         guard === "in-progress" ||
         guard === "not-observed" ||
-        guard === "hidden-then-left"
+        guard === "hidden-then-left" ||
+        guard === "stale-session-reloaded"
     ) {
         return "undetermined";
     }
@@ -633,7 +669,9 @@ export function deriveTrialPhase(events: TrialEvent[]): TrialPhase {
 
     const guard = deriveGuardVerdict(events);
     if (guard === "in-progress") return "collecting-axis2";
-    if (guard === "hidden-then-left") return "awaiting-manual-confirmation";
+    if (guard === "hidden-then-left" || guard === "stale-session-reloaded") {
+        return "awaiting-manual-confirmation";
+    }
     return "complete";
 }
 
diff --git a/resources/js/lib/shared-props.ts b/resources/js/lib/shared-props.ts
index 42c1a16..b4ee46b 100644
--- a/resources/js/lib/shared-props.ts
+++ b/resources/js/lib/shared-props.ts
@@ -54,6 +54,20 @@ export function hasAuthenticatedUser(props: unknown): boolean {
     return typeof user === "object" && user !== null;
 }
 
+/** サーバが配る描画世代の書式 (PHP の SessionEpoch::VALUE_PATTERN と対)。 */
+const SESSION_EPOCH_PATTERN = /^[0-9a-f]{32}$/;
+
+/**
+ * 共有 props から描画世代を読む。**書式が違えば null に倒す**
+ * (「読めない」は bfcache guard 側で「開示しない」に写る)。
+ */
+export function readSessionEpoch(props: unknown): string | null {
+    if (typeof props !== "object" || props === null) return null;
+    const value = (props as { sessionEpoch?: unknown }).sessionEpoch;
+    if (typeof value !== "string") return null;
+    return SESSION_EPOCH_PATTERN.test(value) ? value : null;
+}
+
 export interface SharedProps {
     appName: string;
     auth: { user: AuthUser | null };
@@ -70,4 +84,9 @@ export interface SharedProps {
     invitationInbox: InvitationSharedProps;
     /** サーバ描画 <title> と同一の完成タイトル (document-title.ts が SPA 遷移時に同期する) */
     title: string;
+    /**
+     * この応答を作ったセッションの世代の印 (32 文字の 16 進)。bfcache 復元時の同期判定で
+     * 世代 cookie と突き合わせる。session を持たない要求では null。
+     */
+    sessionEpoch: string | null;
 }
diff --git a/resources/js/pages/Debug/BfcacheTrial.svelte b/resources/js/pages/Debug/BfcacheTrial.svelte
index 311136b..248b27c 100644
--- a/resources/js/pages/Debug/BfcacheTrial.svelte
+++ b/resources/js/pages/Debug/BfcacheTrial.svelte
@@ -368,7 +368,14 @@
 
     function guardStateOf(): GuardState {
         const value = document.documentElement.getAttribute(BFCACHE_HIDDEN_ATTRIBUTE);
-        if (value === "pending" || value === "verifying" || value === "retry") return value;
+        if (
+            value === "pending" ||
+            value === "verifying" ||
+            value === "retry" ||
+            value === "reloading"
+        ) {
+            return value;
+        }
         return null;
     }
 
@@ -669,6 +676,14 @@
                                         >すると判定が確定します。
                                     </p>
                                 {/if}
+                                {#if guardVerdict === "stale-session-reloaded"}
+                                    <p class="mt-3 text-caption text-text-secondary">
+                                        秘匿を維持したまま同じ URL を読み直しました。<strong
+                                            >/login に着地したことを確認して記録</strong
+                                        >すると判定が確定します
+                                        (読み直しの着地先は A から観測できません)。
+                                    </p>
+                                {/if}
 
                                 <h3 class="mt-4 text-h3">自動観測</h3>
                                 <ul class="mt-1 text-caption text-text-secondary">
diff --git a/tests/Architecture/BfcacheGuardClientContractSyncTest.php b/tests/Architecture/BfcacheGuardClientContractSyncTest.php
new file mode 100644
index 0000000..073d01b
--- /dev/null
+++ b/tests/Architecture/BfcacheGuardClientContractSyncTest.php
@@ -0,0 +1,135 @@
+<?php
+
+declare(strict_types=1);
+
+use App\DataTransferObjects\Auth\SessionStatusDto;
+use App\Http\Resources\Auth\SessionStatusResource;
+use App\Support\Auth\SessionEpoch;
+
+/*
+ * bfcache 秘匿・再検証の「言語をまたぐ名前」の契約ずれ検査。
+ *
+ * PHP 側 (App\Support\Auth\SessionEpoch / SessionStatusResource) を正本として、
+ * 画面側のファイルに同じ文字列が実在することを確かめる。cookie 名・ヘッダ名・
+ * 共有 prop のキー・応答キー・印の書式は型検査が届かないため、片側だけ変えると
+ * 静かに壊れる (常に読み直し、または常に不一致) 。
+ *
+ * **保証範囲を誇張しない**: これは**ファイル単位の語の実在検査**であり、
+ * **使われ方が正しいことは保証しない**。同じ語がコメントや型宣言に残っていれば、
+ * 実際に使う箇所だけを別名へ変えても緑のままである (実測: 宣言 1 行だけを改名した
+ * 6 通りのうち赤くなったのは 2 通り。語をファイルから全消しすれば 6 通りとも赤くなる。
+ * 記録は devnotes/20260816-0202-todo-T178/contract-sync-negative-control.md)。
+ * 意味の正しさは vitest (tests/js/lib/bfcache-guard.test.ts の分岐) と Feature テスト
+ * (tests/Feature/Auth/SessionStatusProbeTest.php の応答契約) が担う。
+ */
+
+/**
+ * 監視対象ファイル (リポジトリルート相対)。
+ *
+ * @return array<string, string>
+ */
+function bfcacheContractWatchedFiles(): array
+{
+    return [
+        'guard' => 'resources/js/lib/bfcache-guard.ts',
+        'sharedProps' => 'resources/js/lib/shared-props.ts',
+        'inertiaMiddleware' => 'app/Http/Middleware/HandleInertiaRequests.php',
+        'trial' => 'resources/js/lib/debug/bfcache-trial.ts',
+        'entrypoint' => 'resources/js/app.ts',
+    ];
+}
+
+/**
+ * その語が**識別子として**現れるか。
+ *
+ * 単なる部分文字列一致だと、片側だけを別名へ変えても (`session_epoch` →
+ * `session_epoch_renamed`) 元の語を含んでしまい検査が赤くならない。前後を
+ * 識別子文字でない位置に限ることで、名前の変更が必ず検出される。
+ */
+function bfcacheContractHasToken(string $haystack, string $token): bool
+{
+    $pattern = '/(?<![A-Za-z0-9_])'.preg_quote($token, '/').'(?![A-Za-z0-9_])/u';
+
+    return preg_match($pattern, $haystack) === 1;
+}
+
+function bfcacheContractFileContents(string $key): string
+{
+    $path = base_path(bfcacheContractWatchedFiles()[$key]);
+    $contents = file_get_contents($path);
+
+    expect($contents)->toBeString();
+
+    return (string) $contents;
+}
+
+test('監視対象ファイルがすべて実在する (パス変更で検査が無言で空にならない)', function (): void {
+    foreach (bfcacheContractWatchedFiles() as $key => $relative) {
+        expect(file_exists(base_path($relative)))
+            ->toBeTrue("監視対象 '{$key}' ({$relative}) が存在しない。パスを変えたなら本テストの一覧も直すこと");
+    }
+});
+
+test('世代 cookie 名とヘッダ名が画面側の guard に実在する', function (): void {
+    $guard = bfcacheContractFileContents('guard');
+
+    expect(bfcacheContractHasToken($guard, SessionEpoch::COOKIE_NAME))
+        ->toBeTrue('cookie 名 '.SessionEpoch::COOKIE_NAME.' が guard に無い')
+        ->and(bfcacheContractHasToken($guard, SessionEpoch::HEADER_NAME))
+        ->toBeTrue('ヘッダ名 '.SessionEpoch::HEADER_NAME.' が guard に無い');
+});
+
+test('共有 prop のキーがサーバ側 middleware と画面側の読み取りの両方に実在する', function (): void {
+    // サーバ側は定数を参照する (文字列を書き写さない = ずれる余地を型で消す)。
+    // 画面側は文字列でしか書けないので、こちらは値そのものの実在を見る。
+    expect(bfcacheContractFileContents('inertiaMiddleware'))->toContain('SessionEpoch::SHARED_PROP_KEY')
+        ->and(bfcacheContractHasToken(
+            bfcacheContractFileContents('sharedProps'),
+            SessionEpoch::SHARED_PROP_KEY,
+        ))->toBeTrue('共有 prop のキーが画面側の読み取りに無い');
+});
+
+test('プローブ応答のキーがすべて画面側の guard に実在する', function (): void {
+    // 応答キーは Resource を実際に toArray() して得る (文字列を検査側にも書くと
+    // 正本が 2 か所になる)。キーが増えたら検査対象も自動で増える。
+    $keys = array_keys(SessionStatusResource::make(new SessionStatusDto(
+        authenticated: true,
+        sessionEpochMatches: true,
+    ))->toArray(request()));
+
+    expect($keys)->not->toBeEmpty();
+
+    $guard = bfcacheContractFileContents('guard');
+    foreach ($keys as $key) {
+        expect(bfcacheContractHasToken($guard, (string) $key))
+            ->toBeTrue("応答キー '{$key}' が guard に無い");
+    }
+});
+
+test('印の書式が画面側の 2 ファイルに実在する', function (): void {
+    // PHP の正規表現から区切り・アンカー・修飾子を外して素の書式を得る。
+    // 期待値と突き合わせてから使うので、外し方が壊れれば degenerate PASS にならず赤くなる。
+    $pattern = trim(SessionEpoch::VALUE_PATTERN, '/^$D');
+
+    expect($pattern)->toBe('[0-9a-f]{32}')
+        ->and(bfcacheContractFileContents('guard'))->toContain($pattern)
+        ->and(bfcacheContractFileContents('sharedProps'))->toContain($pattern);
+});
+
+test('ガードの状態値 reloading が検証ページの許可語彙に実在する', function (): void {
+    // 検証ページの状態語彙が追随していないと、実機受入確認 (T085) で記録が拒否される。
+    expect(bfcacheContractHasToken(bfcacheContractFileContents('trial'), 'reloading'))
+        ->toBeTrue('検証ページの許可語彙に reloading が無い');
+});
+
+test('入口スクリプトが描画世代と現世代の読み取りを明示的に配線している', function (): void {
+    // 既定任せ (readRenderedEpoch を渡さない) にすると常に読み直しになる。
+    // 逆に描画世代の既定を cookie にすると同期判定が素通しになるため、
+    // 2 つの出所を呼び出し側で名前付きで見せることを固定する。
+    $entrypoint = bfcacheContractFileContents('entrypoint');
+
+    expect(bfcacheContractHasToken($entrypoint, 'readRenderedEpoch'))
+        ->toBeTrue('入口スクリプトが readRenderedEpoch を渡していない')
+        ->and(bfcacheContractHasToken($entrypoint, 'readCurrentEpoch'))
+        ->toBeTrue('入口スクリプトが readCurrentEpoch を渡していない');
+});
diff --git a/tests/Architecture/RescueRouteGateInventoryTest.php b/tests/Architecture/RescueRouteGateInventoryTest.php
index 760aefb..b660b6d 100644
--- a/tests/Architecture/RescueRouteGateInventoryTest.php
+++ b/tests/Architecture/RescueRouteGateInventoryTest.php
@@ -40,7 +40,7 @@
  */
 
 /** 母集団 `U` の件数 (exact。middleware の増減を必ずレビューに出す)。 */
-const RESCUE_GATE_POPULATION_COUNT = 10;
+const RESCUE_GATE_POPULATION_COUNT = 11;
 
 /**
  * 母集団に名指しで加える vendor 認証ゲート。
diff --git a/tests/Architecture/SupportedBrowsersDocFreshnessTest.php b/tests/Architecture/SupportedBrowsersDocFreshnessTest.php
new file mode 100644
index 0000000..40d3db6
--- /dev/null
+++ b/tests/Architecture/SupportedBrowsersDocFreshnessTest.php
@@ -0,0 +1,80 @@
+<?php
+
+declare(strict_types=1);
+
+use Carbon\CarbonImmutable;
+use Tests\Support\Docs\PrimarySourceReviewDate;
+
+/*
+ * 対応ブラウザ方針の文書 (docs/supported-browsers.md) の期限検査。
+ *
+ * 同書はブラウザ挙動の一次情報 (自動化ハーネスの版と起動スイッチ / 復元が再現しない原因 /
+ * 実機受入確認の実施状況) に依存しており、時間で陳腐化する。**未実施のまま忘れられるのを防ぐ**
+ * ために、確認日の行を 1 行だけ持たせて機械で読む。
+ *
+ * **保証しないもの**: 確認日は自己申告であり、**日付を新しくしても内容が正しいことは
+ * 担保しない**。この検査が担うのは「見直す機会を強制的に作る」ことだけである。
+ * 本テストはある日、コードを 1 行も変えていないのに赤くなる。それは意図した設計である。
+ */
+
+const SUPPORTED_BROWSERS_DOC = 'docs/supported-browsers.md';
+
+/** 再確認すべき項目 (失敗メッセージに並べて「日付だけ更新して黙らせる」運用を防ぐ)。 */
+const SUPPORTED_BROWSERS_REVIEW_ITEMS = <<<'TEXT'
+再確認する項目:
+  - 自動化ハーネス (Playwright / pest-plugin-browser) の版と起動スイッチの状況
+  - 復元が再現しない原因の調査 (Chromium は起動スイッチで特定済み / WebKit は未特定)
+  - iOS 実機受入確認 (T085) の実施状況
+確認したら docs/supported-browsers.md の確認日の行を更新すること
+(日付だけ更新して内容を見直さないのは、この検査の目的に反する)。
+TEXT;
+
+test('確認日の行が 1 行だけ存在し、読めて、期限内である', function (): void {
+    $contents = file_get_contents(base_path(SUPPORTED_BROWSERS_DOC));
+    expect($contents)->toBeString();
+
+    $found = PrimarySourceReviewDate::extractAll((string) $contents);
+
+    expect($found)->toHaveCount(
+        1,
+        '確認日の行は '.SUPPORTED_BROWSERS_DOC.' に 1 行だけ置くこと (見つかった数: '.count($found).")\n"
+        .SUPPORTED_BROWSERS_REVIEW_ITEMS,
+    );
+
+    // 基準日は UTC の今日に固定する (実行環境のタイムゾーンで境界が動かないように)。
+    $problem = PrimarySourceReviewDate::problem($found[0], CarbonImmutable::today('UTC'));
+
+    expect($problem)->toBeNull(
+        SUPPORTED_BROWSERS_DOC.' の確認日が使えない: '.($problem ?? '')."\n"
+        .SUPPORTED_BROWSERS_REVIEW_ITEMS,
+    );
+});
+
+test('日付判定の境界 (純粋関数を直接呼ぶ。文書は書き換えない)', function (): void {
+    $today = CarbonImmutable::parse('2026-08-15', 'UTC')->startOfDay();
+
+    // 行が無い / 書式違い / 実在しない日付は「読めない」として赤にする。
+    expect(PrimarySourceReviewDate::problem(null, $today))->not->toBeNull()
+        ->and(PrimarySourceReviewDate::problem('2026/08/15', $today))->not->toBeNull()
+        ->and(PrimarySourceReviewDate::problem('2026-8-15', $today))->not->toBeNull()
+        ->and(PrimarySourceReviewDate::problem('未確認', $today))->not->toBeNull()
+        ->and(PrimarySourceReviewDate::problem('2026-02-30', $today))->not->toBeNull();
+
+    // 未来の日付は記入ミスとして赤にする (黙って通さない)。
+    expect(PrimarySourceReviewDate::problem('2026-08-16', $today))->not->toBeNull();
+
+    // 当日は緑。ちょうど上限日数前も緑、1 日超えると赤。
+    expect(PrimarySourceReviewDate::problem('2026-08-15', $today))->toBeNull()
+        ->and(PrimarySourceReviewDate::problem(
+            $today->subDays(PrimarySourceReviewDate::MAX_AGE_DAYS)->format('Y-m-d'),
+            $today,
+        ))->toBeNull()
+        ->and(PrimarySourceReviewDate::problem(
+            $today->subDays(PrimarySourceReviewDate::MAX_AGE_DAYS + 1)->format('Y-m-d'),
+            $today,
+        ))->not->toBeNull();
+});
+
+test('確認日の行を抽出できないときは空を返す (degenerate PASS 防止の自己検証)', function (): void {
+    expect(PrimarySourceReviewDate::extractAll("# 見出し\n本文だけの文書\n"))->toBe([]);
+});
diff --git a/tests/Architecture/TenantBoundaryOrderingTest.php b/tests/Architecture/TenantBoundaryOrderingTest.php
index 782e8c0..0445246 100644
--- a/tests/Architecture/TenantBoundaryOrderingTest.php
+++ b/tests/Architecture/TenantBoundaryOrderingTest.php
@@ -10,6 +10,7 @@
 use App\Http\Middleware\EnsureProjectBelongsToRouteOrganization;
 use App\Http\Middleware\HandleInertiaRequests;
 use App\Http\Middleware\IdempotentRequest;
+use App\Http\Middleware\IssueSessionEpochCookie;
 use App\Http\Middleware\NoStoreCacheHeadersForAuthenticatedPages;
 use App\Http\Middleware\RequireActiveSubscription;
 use App\Http\Middleware\RequireApiKeyAbility;
@@ -392,6 +393,7 @@ function tenantBoundaryHasMode(string $routeName, NestedRouteDefenseMode $mode):
         RequireTwoFactorForEnforcedOrganizations::class,
         BlockTwoFactorDisableForEnforcedOrganizations::class,
         NoStoreCacheHeadersForAuthenticatedPages::class,
+        IssueSessionEpochCookie::class,
         EncryptHistory::class,
         EnsureEmailIsVerified::class,
     ];
diff --git a/tests/Feature/Auth/SessionEpochCookieTest.php b/tests/Feature/Auth/SessionEpochCookieTest.php
new file mode 100644
index 0000000..f40fab0
--- /dev/null
+++ b/tests/Feature/Auth/SessionEpochCookieTest.php
@@ -0,0 +1,135 @@
+<?php
+
+declare(strict_types=1);
+
+use App\Models\User;
+use App\Support\Auth\SessionEpoch;
+use Illuminate\Support\Str;
+use Illuminate\Testing\TestResponse;
+use Symfony\Component\HttpFoundation\Cookie;
+
+/*
+ * セッション世代の印を運ぶ cookie (App\Http\Middleware\IssueSessionEpochCookie)。
+ *
+ * 契約:
+ *   - 応答時点のセッション ID から導出する ($next の後) = ログイン・ログアウトでの
+ *     セッション ID 再生成を同じ応答で拾う。
+ *   - 未認証でも発行し、削除しない (「印が無い」状態を作らない)。
+ *   - **画面側から読める** = 暗号化の除外が効いていること。ここが本 middleware で最も
+ *     壊れやすい配線なので、平文値そのものを固定する (除外を外すと画面側は復号できない
+ *     文字列を読み、常に不一致 = 復元のたびに読み直しになる = 静かな劣化)。
+ *   - 属性は同じ応答の session cookie と同一 (HttpOnly を除く)。
+ */
+
+/** 応答から指定 cookie を取り出す (無ければ null)。 */
+function cookieFromResponse(TestResponse $response, string $name): ?Cookie
+{
+    foreach ($response->headers->getCookies() as $cookie) {
+        if ($cookie->getName() === $name) {
+            return $cookie;
+        }
+    }
+
+    return null;
+}
+
+/** セッション ID を固定して要求する (印の期待値を計算できるようにする)。 */
+function pinnedSessionId(): string
+{
+    return Str::random(40);
+}
+
+test('認証済み応答の世代 cookie が平文の印そのものである (暗号化の除外が効いている)', function (): void {
+    [, $owner] = createOrganizationWithOwner();
+    $sessionId = pinnedSessionId();
+
+    $response = $this->actingAs($owner)
+        ->withCookie((string) config('session.cookie'), $sessionId)
+        ->get('/dashboard');
+
+    $cookie = cookieFromResponse($response, SessionEpoch::COOKIE_NAME);
+
+    expect($cookie)->not->toBeNull('認証済み応答に世代 cookie が無い');
+    expect($cookie?->getValue())->toBe(SessionEpoch::forSession($sessionId));
+});
+
+test('guest 応答にも世代 cookie が付く (「印が無い」状態を作らない)', function (): void {
+    $sessionId = pinnedSessionId();
+
+    $response = $this->withCookie((string) config('session.cookie'), $sessionId)->get('/login');
+
+    $cookie = cookieFromResponse($response, SessionEpoch::COOKIE_NAME);
+
+    expect($cookie)->not->toBeNull('guest 応答に世代 cookie が無い');
+    expect($cookie?->getValue())->toBe(SessionEpoch::forSession($sessionId));
+});
+
+test('世代 cookie は画面側から読める (HttpOnly でない)', function (): void {
+    [, $owner] = createOrganizationWithOwner();
+
+    $cookie = cookieFromResponse(
+        $this->actingAs($owner)->get('/dashboard'),
+        SessionEpoch::COOKIE_NAME,
+    );
+
+    expect($cookie?->isHttpOnly())->toBeFalse();
+});
+
+test('世代 cookie の属性は同じ応答の session cookie と同じ (HttpOnly を除く)', function (): void {
+    [, $owner] = createOrganizationWithOwner();
+
+    $response = $this->actingAs($owner)->get('/dashboard');
+
+    $epochCookie = cookieFromResponse($response, SessionEpoch::COOKIE_NAME);
+    $sessionCookie = cookieFromResponse($response, (string) config('session.cookie'));
+
+    expect($sessionCookie)->not->toBeNull('比較対象の session cookie が同じ応答に無い');
+    expect($epochCookie?->getPath())->toBe($sessionCookie?->getPath())
+        ->and($epochCookie?->getDomain())->toBe($sessionCookie?->getDomain())
+        ->and($epochCookie?->isSecure())->toBe($sessionCookie?->isSecure())
+        ->and($epochCookie?->getSameSite())->toBe($sessionCookie?->getSameSite());
+});
+
+test('ログイン応答の後の印はログイン前と異なる (セッション ID 再生成を拾う)', function (): void {
+    $user = User::factory()->create(['email' => 'epoch-login@example.com']);
+
+    $before = cookieFromResponse($this->get('/login'), SessionEpoch::COOKIE_NAME);
+
+    $after = cookieFromResponse($this->post('/login', [
+        'email' => 'epoch-login@example.com',
+        'password' => 'password',
+    ]), SessionEpoch::COOKIE_NAME);
+
+    $this->assertAuthenticatedAs($user);
+    expect($before?->getValue())->not->toBeNull()
+        ->and($after?->getValue())->not->toBeNull()
+        ->and($after?->getValue())->not->toBe($before?->getValue());
+});
+
+test('ログアウト応答の後の印はログアウト前と異なる (削除ではなく上書き)', function (): void {
+    [, $owner] = createOrganizationWithOwner();
+    $sessionId = pinnedSessionId();
+
+    $before = cookieFromResponse(
+        $this->actingAs($owner)
+            ->withCookie((string) config('session.cookie'), $sessionId)
+            ->get('/dashboard'),
+        SessionEpoch::COOKIE_NAME,
+    );
+
+    $after = cookieFromResponse(
+        $this->actingAs($owner)
+            ->withCookie((string) config('session.cookie'), $sessionId)
+            ->post('/logout'),
+        SessionEpoch::COOKIE_NAME,
+    );
+
+    expect($after?->getValue())->not->toBeNull('ログアウト応答で世代 cookie が消えている')
+        ->and($after?->getValue())->not->toBe($before?->getValue());
+});
+
+test('session を持たない route (stateless block) には世代 cookie を付けない', function (): void {
+    $response = $this->get('/robots.txt');
+
+    expect(cookieFromResponse($response, SessionEpoch::COOKIE_NAME))->toBeNull();
+});
diff --git a/tests/Feature/Auth/SessionEpochSharedPropTest.php b/tests/Feature/Auth/SessionEpochSharedPropTest.php
new file mode 100644
index 0000000..393cbdc
--- /dev/null
+++ b/tests/Feature/Auth/SessionEpochSharedPropTest.php
@@ -0,0 +1,99 @@
+<?php
+
+declare(strict_types=1);
+
+use App\Models\User;
+use App\Support\Auth\SessionEpoch;
+use Illuminate\Support\Str;
+use Illuminate\Testing\TestResponse;
+
+/*
+ * 描画世代 (Inertia 共有 prop `sessionEpoch`)。
+ *
+ * 「いま画面に出ている内容がどのセッション世代の応答で来たか」を、内容と同じ 1 通で運ぶ。
+ * 世代 cookie とは**同じ出所から出た同じ値**でなければならない (ずれると
+ * 「内容は A・印は B」の取り違えが起きる)。
+ *
+ * prop は closure で共有する。vendor の Inertia\Middleware は $next の**前**に
+ * share() を呼ぶため、即値にするとセッション ID 再生成 (ログイン等) を拾えず
+ * cookie と食い違う。下の「ログイン応答」のケースがその behavioral な固定である。
+ */
+
+/** Inertia 応答の props から描画世代を取り出す。 */
+function renderedSessionEpoch(TestResponse $response): mixed
+{
+    $page = $response->viewData('page');
+
+    expect($page)->toBeArray();
+
+    /** @var array{props: array<string, mixed>} $page */
+    return $page['props'][SessionEpoch::SHARED_PROP_KEY] ?? null;
+}
+
+/** 応答に載った世代 cookie の値。 */
+function issuedSessionEpochCookie(TestResponse $response): ?string
+{
+    foreach ($response->headers->getCookies() as $cookie) {
+        if ($cookie->getName() === SessionEpoch::COOKIE_NAME) {
+            return $cookie->getValue();
+        }
+    }
+
+    return null;
+}
+
+test('認証済みの Inertia 応答で描画世代と世代 cookie が同値である', function (): void {
+    [, $owner] = createOrganizationWithOwner();
+
+    $response = $this->actingAs($owner)->get('/dashboard');
+
+    $epoch = renderedSessionEpoch($response);
+
+    expect($epoch)->toBeString()
+        ->and($epoch)->toBe(issuedSessionEpochCookie($response));
+});
+
+test('guest の Inertia 応答にも描画世代が載る', function (): void {
+    $response = $this->get('/login');
+
+    expect(renderedSessionEpoch($response))->toBeString();
+});
+
+test('セッション ID が要求中に再生成される経路でも描画世代と世代 cookie が同値になる', function (): void {
+    // ログイン成功時に Fortify がセッションを再生成する。共有 prop を即値にすると
+    // 「要求前のセッション ID」で固定され、cookie ($next の後に導出) とずれる。
+    $sessionId = Str::random(40);
+    User::factory()->create(['email' => 'epoch-prop@example.com']);
+
+    $login = $this->withCookie((string) config('session.cookie'), $sessionId)->post('/login', [
+        'email' => 'epoch-prop@example.com',
+        'password' => 'password',
+    ]);
+
+    // ログイン応答は redirect なので、印が再生成後のセッション ID 由来であることを
+    // cookie 側で確かめる (redirect には Inertia の props が無い)。
+    $issued = issuedSessionEpochCookie($login);
+
+    expect($issued)->not->toBeNull()
+        ->and($issued)->not->toBe(SessionEpoch::forSession($sessionId));
+});
+
+test('部分再読み込みで別 prop だけを要求しても描画世代は載る', function (): void {
+    [, $owner] = createOrganizationWithOwner();
+
+    $response = $this->actingAs($owner)->get('/dashboard');
+    $component = $response->viewData('page')['component'];
+
+    $partial = $this->actingAs($owner)->get('/dashboard', [
+        'X-Inertia' => 'true',
+        'X-Inertia-Version' => (string) $response->viewData('page')['version'],
+        'X-Inertia-Partial-Component' => $component,
+        'X-Inertia-Partial-Data' => 'title',
+    ]);
+
+    $props = $partial->json('props');
+
+    expect($props)->toBeArray()
+        ->and($props)->toHaveKey(SessionEpoch::SHARED_PROP_KEY)
+        ->and($props[SessionEpoch::SHARED_PROP_KEY])->toBeString();
+});
diff --git a/tests/Feature/Auth/SessionStatusProbeTest.php b/tests/Feature/Auth/SessionStatusProbeTest.php
index 0015034..10a2f3e 100644
--- a/tests/Feature/Auth/SessionStatusProbeTest.php
+++ b/tests/Feature/Auth/SessionStatusProbeTest.php
@@ -4,15 +4,20 @@
 
 use App\Http\Middleware\RequireTwoFactorForEnforcedOrganizations;
 use App\Models\User;
+use App\Support\Auth\SessionEpoch;
+use Illuminate\Support\Str;
 
 /*
  * bfcache 秘匿・再検証 (詳細設計 施策 6) の軽量プローブ endpoint。
  *
  * 契約:
- *   - auth グループの外。guest でも 200 + { "authenticated": false } (top-level / $wrap = null)。
- *     ステータスコードではなく明示 boolean を見せることで、クライアント guard が
- *     「セッション無効」と「endpoint 不在 / エラー」を取り違えないようにする。
- *   - 応答は `{ "authenticated": bool }` のみ = PII を一切含まない。
+ *   - auth グループの外。guest でも 200 + { "authenticated": false, "sessionEpochMatches": false }
+ *     (top-level / $wrap = null)。ステータスコードではなく明示 boolean を見せることで、
+ *     クライアント guard が「セッション無効」と「endpoint 不在 / エラー」を取り違えないようにする。
+ *   - 応答は `{ "authenticated": bool, "sessionEpochMatches": bool }` のみ = PII を一切含まない。
+ *   - 世代の照合に使うのは **要求ヘッダ X-Session-Epoch だけ**。要求の Cookie ヘッダに載る
+ *     世代 cookie は画面側から書き換えられる値なので一致判定に使わない。
+ *   - 印を運ばない要求は一致にしない (既定は開示しない側)。
  *   - `no-store, private` を Resource 側 (withResponse) で付与する (guest 応答も対象のため
  *     認証済み限定の baseline middleware には委ねない)。
  *   - 2FA 強制中 / recent-auth 期限切れ / 組織未選択でも必ず 200 + boolean。
@@ -22,15 +27,15 @@
 test('guest でも 200 で authenticated:false を返す', function (): void {
     $this->get('/session/status')
         ->assertOk()
-        ->assertExactJson(['authenticated' => false]);
+        ->assertExactJson(['authenticated' => false, 'sessionEpochMatches' => false]);
 });
 
-test('認証済みは 200 で authenticated:true を返す (top-level / data ラップなし)', function (): void {
+test('認証済み・印を運ばない要求は authenticated:true / sessionEpochMatches:false (既定は開示しない側)', function (): void {
     [, $owner] = createOrganizationWithOwner();
 
     $this->actingAs($owner)->get('/session/status')
         ->assertOk()
-        ->assertExactJson(['authenticated' => true]);
+        ->assertExactJson(['authenticated' => true, 'sessionEpochMatches' => false]);
 });
 
 test('応答に no-store と private が付く', function (): void {
@@ -72,7 +77,7 @@
 
     $this->actingAs($owner)->get('/session/status')
         ->assertOk()
-        ->assertExactJson(['authenticated' => true]);
+        ->assertExactJson(['authenticated' => true, 'sessionEpochMatches' => false]);
 });
 
 test('プローブ route は 2FA ゲートの allowlist に理由付きで登録されている', function (): void {
@@ -90,7 +95,7 @@
         ->withSession(['recent_auth_at' => now()->subDay()->timestamp])
         ->get('/session/status')
         ->assertOk()
-        ->assertExactJson(['authenticated' => true]);
+        ->assertExactJson(['authenticated' => true, 'sessionEpochMatches' => false]);
 });
 
 test('組織未選択 (current_organization_id が null) でも 200 + boolean を返す', function (): void {
@@ -99,7 +104,7 @@
 
     $this->actingAs($user)->get('/session/status')
         ->assertOk()
-        ->assertExactJson(['authenticated' => true]);
+        ->assertExactJson(['authenticated' => true, 'sessionEpochMatches' => false]);
 });
 
 test('メール未検証ユーザーでも 200 + boolean を返す (verified ゲート外)', function (): void {
@@ -107,5 +112,73 @@
 
     $this->actingAs($user)->get('/session/status')
         ->assertOk()
-        ->assertExactJson(['authenticated' => true]);
+        ->assertExactJson(['authenticated' => true, 'sessionEpochMatches' => false]);
+});
+
+test('正しい印をヘッダで運ぶと sessionEpochMatches:true になる', function (): void {
+    [, $owner] = createOrganizationWithOwner();
+    $sessionId = Str::random(40);
+
+    $this->actingAs($owner)
+        ->withCookie((string) config('session.cookie'), $sessionId)
+        ->withHeader(SessionEpoch::HEADER_NAME, SessionEpoch::forSession($sessionId))
+        ->get('/session/status')
+        ->assertOk()
+        ->assertExactJson(['authenticated' => true, 'sessionEpochMatches' => true]);
+});
+
+test('別の印・書式違い・空文字・長すぎる値は sessionEpochMatches:false', function (string $submitted): void {
+    [, $owner] = createOrganizationWithOwner();
+    $sessionId = Str::random(40);
+
+    $this->actingAs($owner)
+        ->withCookie((string) config('session.cookie'), $sessionId)
+        ->withHeader(SessionEpoch::HEADER_NAME, $submitted)
+        ->get('/session/status')
+        ->assertOk()
+        ->assertExactJson(['authenticated' => true, 'sessionEpochMatches' => false]);
+})->with([
+    '別の印' => '0123456789abcdef0123456789abcdef',
+    '空文字' => '',
+    '大文字' => '0123456789ABCDEF0123456789ABCDEF',
+    '長すぎる' => '0123456789abcdef0123456789abcdef0',
+]);
+
+test('世代 cookie に正しい印を積んでもヘッダが無ければ sessionEpochMatches:false', function (): void {
+    // Cookie ヘッダを照合に使っていないことの behavioral な固定
+    // (画面側から書き換えられる値を開示の根拠に混ぜない)。
+    [, $owner] = createOrganizationWithOwner();
+    $sessionId = Str::random(40);
+
+    $this->actingAs($owner)
+        ->withCookie((string) config('session.cookie'), $sessionId)
+        ->withUnencryptedCookie(SessionEpoch::COOKIE_NAME, SessionEpoch::forSession($sessionId))
+        ->get('/session/status')
+        ->assertOk()
+        ->assertExactJson(['authenticated' => true, 'sessionEpochMatches' => false]);
+});
+
+test('guest が正しい印を運んでも authenticated は false のまま', function (): void {
+    $sessionId = Str::random(40);
+
+    $this->withCookie((string) config('session.cookie'), $sessionId)
+        ->withHeader(SessionEpoch::HEADER_NAME, SessionEpoch::forSession($sessionId))
+        ->get('/session/status')
+        ->assertOk()
+        ->assertExactJson(['authenticated' => false, 'sessionEpochMatches' => true]);
+});
+
+test('応答本文に印そのものが現れない (値を返していないことの固定)', function (): void {
+    [, $owner] = createOrganizationWithOwner();
+    $sessionId = Str::random(40);
+    $epoch = SessionEpoch::forSession($sessionId);
+
+    $body = $this->actingAs($owner)
+        ->withCookie((string) config('session.cookie'), $sessionId)
+        ->withHeader(SessionEpoch::HEADER_NAME, $epoch)
+        ->get('/session/status')
+        ->getContent();
+
+    expect($body)->toBeString()
+        ->and($body)->not->toContain($epoch);
 });
diff --git a/tests/Support/Docs/PrimarySourceReviewDate.php b/tests/Support/Docs/PrimarySourceReviewDate.php
new file mode 100644
index 0000000..ffba935
--- /dev/null
+++ b/tests/Support/Docs/PrimarySourceReviewDate.php
@@ -0,0 +1,82 @@
+<?php
+
+declare(strict_types=1);
+
+namespace Tests\Support\Docs;
+
+use Carbon\CarbonImmutable;
+
+/**
+ * 対応ブラウザ方針の文書が持つ「一次情報の最終確認日」を読み、期限を判定する純粋関数群。
+ *
+ * 目的は「**実機再確認と一次情報の再確認が、未実施のまま忘れられるのを防ぐ**」ことである。
+ * 判定を純粋関数として切り出しているのは、境界 (ちょうど 400 日前 / 401 日前 / 未来日) を
+ * 文書を書き換えずにテストできるようにするためである。
+ *
+ * **保証しないもの**: 確認日は自己申告であり、**日付を新しくしても内容が正しいことは
+ * 担保しない**。ここが担うのは「見直す機会を強制的に作る」ことだけである。
+ */
+final class PrimarySourceReviewDate
+{
+    /** 文書に 1 行だけ置く見出し語。 */
+    public const LABEL = '一次情報の最終確認日';
+
+    /** 確認日から基準日までの経過日数の上限 (これを超えたら赤)。 */
+    public const MAX_AGE_DAYS = 400;
+
+    /**
+     * 本文から確認日の記述をすべて取り出す (行が 1 行だけであることは呼び出し側が確かめる)。
+     *
+     * 走査するのは「行頭が見出し語で始まる行」だけである。引用や説明文の中で見出し語に
+     * 触れている行を拾わないため、行頭一致にしている。
+     *
+     * @return list<string> 見出し語の後ろに書かれていた値 (前後の空白は落とす)
+     */
+    public static function extractAll(string $contents): array
+    {
+        $found = [];
+        foreach (preg_split('/\R/u', $contents) ?: [] as $line) {
+            if (! str_starts_with($line, self::LABEL)) {
+                continue;
+            }
+            $found[] = trim(mb_substr($line, mb_strlen(self::LABEL)), " \t:：");
+        }
+
+        return $found;
+    }
+
+    /**
+     * 確認日の値を判定する。問題があれば理由を、無ければ null を返す。
+     *
+     * 基準日は呼び出し側が渡す (実行環境のタイムゾーンで境界が動かないよう、
+     * 呼び出し側は UTC の今日を渡すこと)。
+     */
+    public static function problem(?string $value, CarbonImmutable $today): ?string
+    {
+        if ($value === null) {
+            return '確認日の行が見つからない';
+        }
+
+        // 書式は YYYY-MM-DD のみ。他の書式は「読めない」として扱う。
+        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) !== 1) {
+            return "確認日 '{$value}' が YYYY-MM-DD の書式ではない";
+        }
+
+        $date = CarbonImmutable::createFromFormat('!Y-m-d', $value, 'UTC');
+        if ($date === false || $date->format('Y-m-d') !== $value) {
+            return "確認日 '{$value}' が実在する日付ではない";
+        }
+
+        if ($date->greaterThan($today)) {
+            return "確認日 '{$value}' が未来になっている (記入ミス)";
+        }
+
+        // 双方 UTC の 0 時なので日数は整数になる (符号は上の未来判定で正に確定している)。
+        $elapsed = (int) $date->diffInDays($today);
+        if ($elapsed > self::MAX_AGE_DAYS) {
+            return "確認日 '{$value}' から {$elapsed} 日が経過している (上限 ".self::MAX_AGE_DAYS.' 日)';
+        }
+
+        return null;
+    }
+}
diff --git a/tests/Support/Routing/MiddlewareShortCircuitInventory.php b/tests/Support/Routing/MiddlewareShortCircuitInventory.php
index 9173834..f857613 100644
--- a/tests/Support/Routing/MiddlewareShortCircuitInventory.php
+++ b/tests/Support/Routing/MiddlewareShortCircuitInventory.php
@@ -15,6 +15,7 @@
 use App\Http\Middleware\EnsureProjectBelongsToRouteOrganization;
 use App\Http\Middleware\HandleInertiaRequests;
 use App\Http\Middleware\IdempotentRequest;
+use App\Http\Middleware\IssueSessionEpochCookie;
 use App\Http\Middleware\LocalOnly;
 use App\Http\Middleware\McpConsentOrganizationBinder;
 use App\Http\Middleware\NoIndex;
@@ -107,6 +108,8 @@ public static function classification(): array
             SecurityHeaders::class => false,
             NoStoreCacheHeadersForAuthenticatedPages::class => false,
             NoStoreResponse::class => false,
+            // セッション世代の印を応答に載せるだけ (必ず $next を呼ぶ)
+            IssueSessionEpochCookie::class => false,
             // X-Robots-Tag: noindex を足すだけ
             NoIndex::class => false,
             BughuntCoverageMiddleware::class => false,
diff --git a/tests/Unit/Support/Auth/SessionEpochTest.php b/tests/Unit/Support/Auth/SessionEpochTest.php
new file mode 100644
index 0000000..78976f2
--- /dev/null
+++ b/tests/Unit/Support/Auth/SessionEpochTest.php
@@ -0,0 +1,58 @@
+<?php
+
+declare(strict_types=1);
+
+use App\Support\Auth\SessionEpoch;
+
+/*
+ * セッション世代の印の導出と照合 (App\Support\Auth\SessionEpoch)。
+ *
+ * 印は「いまのセッションを一意に指す短い文字列」で、**セッション ID そのものは
+ * 画面側へ出さない** (世代 cookie は画面側から読めるため)。
+ * 照合は fail-closed = どちらかが無い / 書式が違うときは一致としない。
+ */
+
+test('同じセッション ID からは同じ印、違う ID からは違う印になる', function (): void {
+    $first = SessionEpoch::forSession('session-id-one');
+    $second = SessionEpoch::forSession('session-id-two');
+
+    expect($first)->toBe(SessionEpoch::forSession('session-id-one'))
+        ->and($first)->not->toBe($second);
+});
+
+test('印は 32 文字の 16 進小文字である', function (): void {
+    expect(SessionEpoch::isWellFormed(SessionEpoch::forSession('any-session-id')))->toBeTrue();
+});
+
+test('印はセッション ID を部分文字列として含まない (生の ID を出さない)', function (): void {
+    $sessionId = 'ZGxCa1ppTm9vUlR4a2RvVWJoWkVFeXBnRlRyd2NkVGs';
+
+    expect(SessionEpoch::forSession($sessionId))->not->toContain($sessionId);
+});
+
+test('matches は一致で true', function (): void {
+    $epoch = SessionEpoch::forSession('session-id-one');
+
+    expect(SessionEpoch::matches($epoch, $epoch))->toBeTrue();
+});
+
+test('matches は不一致・欠落・書式違いで false (fail-closed)', function (string $description, ?string $submitted, ?string $current): void {
+    expect(SessionEpoch::matches($submitted, $current))->toBeFalse($description);
+})->with([
+    ['別の印', 'a1b2c3d4e5f60718293a4b5c6d7e8f90', '0123456789abcdef0123456789abcdef'],
+    ['提出側が null', null, '0123456789abcdef0123456789abcdef'],
+    ['現世代が null', '0123456789abcdef0123456789abcdef', null],
+    ['両方 null', null, null],
+    ['提出側が空文字', '', '0123456789abcdef0123456789abcdef'],
+    ['33 文字', '0123456789abcdef0123456789abcdef0', '0123456789abcdef0123456789abcdef0'],
+    ['31 文字', '0123456789abcdef0123456789abcde', '0123456789abcdef0123456789abcde'],
+    ['大文字', '0123456789ABCDEF0123456789ABCDEF', '0123456789ABCDEF0123456789ABCDEF'],
+    ['非 16 進', '0123456789abcdefg123456789abcdef', '0123456789abcdefg123456789abcdef'],
+]);
+
+test('isWellFormed は書式違いを拒否する', function (): void {
+    expect(SessionEpoch::isWellFormed(null))->toBeFalse()
+        ->and(SessionEpoch::isWellFormed(''))->toBeFalse()
+        ->and(SessionEpoch::isWellFormed('0123456789abcdef0123456789abcdef'))->toBeTrue()
+        ->and(SessionEpoch::isWellFormed("0123456789abcdef0123456789abcdef\n"))->toBeFalse();
+});
diff --git a/tests/js/lib/bfcache-guard.test.ts b/tests/js/lib/bfcache-guard.test.ts
index 208b6d1..6ff26eb 100644
--- a/tests/js/lib/bfcache-guard.test.ts
+++ b/tests/js/lib/bfcache-guard.test.ts
@@ -1,19 +1,23 @@
 /**
  * Tests for resources/js/lib/bfcache-guard.ts
  *
- * 公開契約 (詳細設計 施策 6 の状態遷移表):
- *   1. pagehide            → documentElement に秘匿属性を同期付与 (この DOM ごと bfcache に入る)
- *   2. pageshow (属性あり) → 秘匿のまま軽量プローブ (/session/status)
- *   3. セッション有効       → 秘匿属性を外すだけ (DOM / フォーム / Inertia 履歴は温存)
- *   4. セッション無効       → login へ hard navigation
- *   5. プローブ失敗         → 秘匿維持 + 再試行ボタン表示 (自動再試行しない)
- *   6. 再試行押下           → 現在 URL を hard reload
+ * 公開契約 (T178 で同期判定を前置した後の状態遷移表):
+ *   1. pagehide                     → documentElement に秘匿属性を同期付与 (この DOM ごと bfcache に入る)
+ *   2. pageshow (属性あり)          → まず同期判定 (通信を待たない)
+ *   3. 同期判定が不一致・不明        → 秘匿のまま読み直し (プローブを呼ばない)
+ *   4. 認証済み + 世代が一致        → 秘匿属性を外すだけ (DOM / フォーム / Inertia 履歴は温存)
+ *   5. 認証済み + 世代が不一致      → 秘匿のまま読み直し (/login へは倒さない)
+ *   6. セッション無効                → login へ hard navigation
+ *   7. プローブ失敗                  → 秘匿維持 + 再試行ボタン表示 (自動再試行しない)
+ *   8. 再試行押下                    → 現在 URL を hard reload
  *
  * 復元マーカーは documentElement の秘匿属性そのもの (sessionStorage は使わない:
  * タブ単位共有で別ページに漏れるため)。
  *
- * 負のコントロール: 「秘匿ロジックを外す (guard 未登録 / dispose 済み) と pagehide 後に
- * 秘匿属性が付かない」。vitest では実描画の露出は検証できないため属性の有無で責務を閉じる
+ * 負のコントロール 2 本:
+ *   - 「秘匿ロジックを外す (guard 未登録 / dispose 済み) と pagehide 後に秘匿属性が付かない」
+ *   - 「同期判定が一致しても、プローブを通さずに秘匿が解けることは無い」(開示の唯一の根拠)
+ * vitest では実描画の露出は検証できないため属性の有無で責務を閉じる
  * (実描画は Browser E2E の責務)。
  */
 import { beforeEach, describe, expect, it, vi } from "vitest";
@@ -22,14 +26,23 @@ import {
     BFCACHE_HIDDEN_ATTRIBUTE,
     BFCACHE_OVERLAY_ID,
     BFCACHE_RETRY_BUTTON_ID,
+    BFCACHE_STATE_RELOADING,
     LOGIN_PATH,
+    SESSION_EPOCH_HEADER,
     SESSION_STATUS_PATH,
+    decideBySyncEpoch,
+    probeSessionStatus,
+    readSessionEpochCookie,
     registerBfcacheGuard,
     type GuardWindow,
     type ProbeFetch,
     type ProbeResponseLike,
 } from "@/lib/bfcache-guard";
 
+/** 試験で使う印 (32 文字の 16 進)。 */
+const EPOCH = "0123456789abcdef0123456789abcdef";
+const OTHER_EPOCH = "fedcba9876543210fedcba9876543210";
+
 /** location を呼び出し記録可能にした最小 window スタブ (jsdom は実 navigation を持たない)。 */
 function createWindowStub(): {
     win: GuardWindow;
@@ -75,6 +88,17 @@ function probeResponse(
     };
 }
 
+/** 世代が一致している既定の配線 (同期判定を通過させる)。 */
+function matchingEpochDeps(): {
+    readRenderedEpoch: () => string | null;
+    readCurrentEpoch: () => string | null;
+} {
+    return {
+        readRenderedEpoch: () => EPOCH,
+        readCurrentEpoch: () => EPOCH,
+    };
+}
+
 function hiddenAttribute(): string | null {
     return document.documentElement.getAttribute(BFCACHE_HIDDEN_ATTRIBUTE);
 }
@@ -110,6 +134,95 @@ describe("負のコントロール (秘匿ロジックが無いとき)", () => {
 
         expect(hiddenAttribute()).toBeNull();
     });
+
+    it("同期判定が一致してもプローブ抜きに秘匿は解けない (開示の唯一の根拠はプローブ)", async () => {
+        const { win, dispatch } = createWindowStub();
+        // 応答を返さない fetch = プローブが決着していない状態
+        const fetchImpl = vi.fn<ProbeFetch>(() => new Promise<ProbeResponseLike>(() => {}));
+        registerBfcacheGuard({
+            win,
+            fetchImpl,
+            isAuthenticated: () => true,
+            ...matchingEpochDeps(),
+        });
+
+        dispatch(transitionEvent("pagehide", true));
+        dispatch(transitionEvent("pageshow", true));
+        await flushProbe();
+
+        expect(fetchImpl).toHaveBeenCalledTimes(1);
+        expect(hiddenAttribute()).not.toBeNull();
+    });
+});
+
+describe("decideBySyncEpoch (通信を待たない前置判定)", () => {
+    it("一致なら undecided (= プローブへ進む。開示ではない)", () => {
+        expect(decideBySyncEpoch(EPOCH, EPOCH)).toBe("undecided");
+    });
+
+    it("不一致・描画世代なし・現世代なしはすべて must-reload", () => {
+        expect(decideBySyncEpoch(EPOCH, OTHER_EPOCH)).toBe("must-reload");
+        expect(decideBySyncEpoch(null, EPOCH)).toBe("must-reload");
+        expect(decideBySyncEpoch(EPOCH, null)).toBe("must-reload");
+        expect(decideBySyncEpoch(null, null)).toBe("must-reload");
+    });
+});
+
+describe("readSessionEpochCookie", () => {
+    it("他 cookie と混在していても読める (前後の空白を許容)", () => {
+        expect(readSessionEpochCookie(`foo=bar; session_epoch=${EPOCH}; baz=qux`)).toBe(EPOCH);
+        expect(readSessionEpochCookie(`  session_epoch = ${EPOCH} `)).toBe(EPOCH);
+    });
+
+    it("URL エンコードされていても復号して読む", () => {
+        expect(readSessionEpochCookie(`session_epoch=${encodeURIComponent(EPOCH)}`)).toBe(EPOCH);
+    });
+
+    it("不在・書式違いは null", () => {
+        expect(readSessionEpochCookie("foo=bar")).toBeNull();
+        expect(readSessionEpochCookie("session_epoch=")).toBeNull();
+        expect(readSessionEpochCookie("session_epoch=NOT-HEX")).toBeNull();
+        expect(readSessionEpochCookie(`session_epoch=${EPOCH}0`)).toBeNull();
+        expect(readSessionEpochCookie(`session_epoch=${EPOCH.toUpperCase()}`)).toBeNull();
+    });
+
+    it("壊れた百分率エンコードでも例外を投げず null を返す", () => {
+        expect(() => readSessionEpochCookie("session_epoch=%E0%A4%A")).not.toThrow();
+        expect(readSessionEpochCookie("session_epoch=%E0%A4%A")).toBeNull();
+    });
+});
+
+describe("probeSessionStatus (描画世代の運び方)", () => {
+    it("描画世代が null のときはヘッダを付けない (空文字を送らない)", async () => {
+        const fetchImpl = vi.fn<ProbeFetch>(() =>
+            Promise.resolve(probeResponse({ authenticated: true, sessionEpochMatches: false })),
+        );
+
+        await probeSessionStatus(fetchImpl, null);
+
+        expect(fetchImpl).toHaveBeenCalledWith(SESSION_STATUS_PATH, {
+            credentials: "same-origin",
+            cache: "no-store",
+            headers: { Accept: "application/json" },
+        });
+    });
+
+    it("応答の 2 つの boolean から 3 つの結論を作る", async () => {
+        const outcomeFor = (body: unknown): Promise<string> =>
+            probeSessionStatus(
+                vi.fn<ProbeFetch>(() => Promise.resolve(probeResponse(body))),
+                EPOCH,
+            );
+
+        expect(await outcomeFor({ authenticated: true, sessionEpochMatches: true })).toBe(
+            "authenticated",
+        );
+        expect(await outcomeFor({ authenticated: true, sessionEpochMatches: false })).toBe("stale");
+        expect(await outcomeFor({ authenticated: false, sessionEpochMatches: true })).toBe(
+            "unauthenticated",
+        );
+        expect(await outcomeFor({ authenticated: true })).toBe("failed");
+    });
 });
 
 describe("pagehide の秘匿判定", () => {
@@ -141,7 +254,7 @@ describe("pagehide の秘匿判定", () => {
     });
 
     it("未認証ページ (auth.user なし) では秘匿もプローブもしない", async () => {
-        const { win, dispatch } = createWindowStub();
+        const { win, dispatch, reload } = createWindowStub();
         const fetchImpl = vi.fn<ProbeFetch>();
         registerBfcacheGuard({ win, fetchImpl, isAuthenticated: () => false });
 
@@ -151,27 +264,58 @@ describe("pagehide の秘匿判定", () => {
 
         expect(hiddenAttribute()).toBeNull();
         expect(fetchImpl).not.toHaveBeenCalled();
+        expect(reload).not.toHaveBeenCalled();
     });
 });
 
 describe("pageshow の復元マーカー判定", () => {
-    it("秘匿属性が無ければ (通常ロード) プローブしない", async () => {
-        const { win, dispatch } = createWindowStub();
+    it("秘匿属性が無ければ (通常ロード) 何もしない", async () => {
+        const { win, dispatch, reload } = createWindowStub();
         const fetchImpl = vi.fn<ProbeFetch>();
-        registerBfcacheGuard({ win, fetchImpl, isAuthenticated: () => true });
+        registerBfcacheGuard({
+            win,
+            fetchImpl,
+            isAuthenticated: () => true,
+            ...matchingEpochDeps(),
+        });
 
         dispatch(transitionEvent("pageshow", true));
         await flushProbe();
 
         expect(fetchImpl).not.toHaveBeenCalled();
+        expect(reload).not.toHaveBeenCalled();
+    });
+
+    it("読み直し後の文書 (秘匿属性なし) では pageshow で何も起きない (ループしない)", async () => {
+        const { win, dispatch, reload } = createWindowStub();
+        const fetchImpl = vi.fn<ProbeFetch>();
+        registerBfcacheGuard({
+            win,
+            fetchImpl,
+            isAuthenticated: () => true,
+            readRenderedEpoch: () => EPOCH,
+            readCurrentEpoch: () => OTHER_EPOCH,
+        });
+
+        // 読み直した先の文書はサーバから来た新しい HTML なので秘匿属性を持たない
+        dispatch(transitionEvent("pageshow", true));
+        await flushProbe();
+
+        expect(reload).not.toHaveBeenCalled();
+        expect(fetchImpl).not.toHaveBeenCalled();
     });
 
     it("秘匿属性があれば persisted の値に依らずプローブする (属性が唯一のマーカー)", async () => {
         const { win, dispatch } = createWindowStub();
         const fetchImpl = vi.fn<ProbeFetch>(() =>
-            Promise.resolve(probeResponse({ authenticated: true })),
+            Promise.resolve(probeResponse({ authenticated: true, sessionEpochMatches: true })),
         );
-        registerBfcacheGuard({ win, fetchImpl, isAuthenticated: () => true });
+        registerBfcacheGuard({
+            win,
+            fetchImpl,
+            isAuthenticated: () => true,
+            ...matchingEpochDeps(),
+        });
 
         dispatch(transitionEvent("pagehide", true));
         dispatch(transitionEvent("pageshow", false));
@@ -180,12 +324,17 @@ describe("pageshow の復元マーカー判定", () => {
         expect(fetchImpl).toHaveBeenCalledTimes(1);
     });
 
-    it("プローブは same-origin / no-store / Accept: application/json で叩く", async () => {
+    it("プローブは same-origin / no-store / Accept + 描画世代ヘッダで叩く", async () => {
         const { win, dispatch } = createWindowStub();
         const fetchImpl = vi.fn<ProbeFetch>(() =>
-            Promise.resolve(probeResponse({ authenticated: true })),
+            Promise.resolve(probeResponse({ authenticated: true, sessionEpochMatches: true })),
         );
-        registerBfcacheGuard({ win, fetchImpl, isAuthenticated: () => true });
+        registerBfcacheGuard({
+            win,
+            fetchImpl,
+            isAuthenticated: () => true,
+            ...matchingEpochDeps(),
+        });
 
         dispatch(transitionEvent("pagehide", true));
         dispatch(transitionEvent("pageshow", true));
@@ -194,21 +343,94 @@ describe("pageshow の復元マーカー判定", () => {
         expect(fetchImpl).toHaveBeenCalledWith(SESSION_STATUS_PATH, {
             credentials: "same-origin",
             cache: "no-store",
-            headers: { Accept: "application/json" },
+            headers: { Accept: "application/json", [SESSION_EPOCH_HEADER]: EPOCH },
         });
     });
 });
 
+describe("同期判定の前置 (通信を待たない)", () => {
+    /** 秘匿状態から pageshow を 1 回起こす。 */
+    function restoreWithEpochs(
+        rendered: string | null,
+        current: string | null,
+    ): { fetchImpl: ReturnType<typeof vi.fn>; reload: ReturnType<typeof vi.fn> } {
+        const { win, dispatch, reload } = createWindowStub();
+        const fetchImpl = vi.fn<ProbeFetch>();
+        registerBfcacheGuard({
+            win,
+            fetchImpl,
+            isAuthenticated: () => true,
+            readRenderedEpoch: () => rendered,
+            readCurrentEpoch: () => current,
+        });
+
+        dispatch(transitionEvent("pagehide", true));
+        dispatch(transitionEvent("pageshow", true));
+
+        return { fetchImpl, reload };
+    }
+
+    it("世代が不一致ならプローブを 1 度も呼ばずに秘匿のまま読み直す", () => {
+        const { fetchImpl, reload } = restoreWithEpochs(EPOCH, OTHER_EPOCH);
+
+        expect(fetchImpl).not.toHaveBeenCalled();
+        expect(reload).toHaveBeenCalledTimes(1);
+        expect(hiddenAttribute()).toBe(BFCACHE_STATE_RELOADING);
+    });
+
+    it("描画世代が読めないときも読み直す (安全側)", () => {
+        const { fetchImpl, reload } = restoreWithEpochs(null, EPOCH);
+
+        expect(fetchImpl).not.toHaveBeenCalled();
+        expect(reload).toHaveBeenCalledTimes(1);
+        expect(hiddenAttribute()).toBe(BFCACHE_STATE_RELOADING);
+    });
+
+    it("世代 cookie が読めないときも読み直す (開示側へは倒れない)", () => {
+        const { fetchImpl, reload } = restoreWithEpochs(EPOCH, null);
+
+        expect(fetchImpl).not.toHaveBeenCalled();
+        expect(reload).toHaveBeenCalledTimes(1);
+        expect(hiddenAttribute()).toBe(BFCACHE_STATE_RELOADING);
+    });
+
+    it("描画世代の既定は null = 配線を忘れると読み直しに倒れる (素通ししない)", () => {
+        const { win, dispatch, reload } = createWindowStub();
+        const fetchImpl = vi.fn<ProbeFetch>();
+        registerBfcacheGuard({
+            win,
+            fetchImpl,
+            isAuthenticated: () => true,
+            readCurrentEpoch: () => EPOCH,
+        });
+
+        dispatch(transitionEvent("pagehide", true));
+        dispatch(transitionEvent("pageshow", true));
+
+        expect(fetchImpl).not.toHaveBeenCalled();
+        expect(reload).toHaveBeenCalledTimes(1);
+    });
+});
+
 describe("プローブ結果ごとの遷移", () => {
-    /** 秘匿状態から 1 回プローブを走らせる。 */
-    async function restoreWith(response: () => Promise<ProbeResponseLike>): Promise<{
+    /** 秘匿状態から 1 回プローブを走らせる (同期判定は一致させて通す)。 */
+    async function restoreWith(
+        response: () => Promise<ProbeResponseLike>,
+        renderedEpoch: string | null = EPOCH,
+    ): Promise<{
         fetchImpl: ReturnType<typeof vi.fn>;
         replace: ReturnType<typeof vi.fn>;
         reload: ReturnType<typeof vi.fn>;
     }> {
         const { win, dispatch, replace, reload } = createWindowStub();
         const fetchImpl = vi.fn<ProbeFetch>(response);
-        registerBfcacheGuard({ win, fetchImpl, isAuthenticated: () => true });
+        registerBfcacheGuard({
+            win,
+            fetchImpl,
+            isAuthenticated: () => true,
+            readRenderedEpoch: () => renderedEpoch,
+            readCurrentEpoch: () => renderedEpoch,
+        });
 
         dispatch(transitionEvent("pagehide", true));
         dispatch(transitionEvent("pageshow", true));
@@ -217,9 +439,9 @@ describe("プローブ結果ごとの遷移", () => {
         return { fetchImpl, replace, reload };
     }
 
-    it("authenticated:true なら秘匿を外すだけ (遷移も reload もしない)", async () => {
+    it("認証済み + 世代一致なら秘匿を外すだけ (遷移も reload もしない)", async () => {
         const { replace, reload } = await restoreWith(() =>
-            Promise.resolve(probeResponse({ authenticated: true })),
+            Promise.resolve(probeResponse({ authenticated: true, sessionEpochMatches: true })),
         );
 
         expect(hiddenAttribute()).toBeNull();
@@ -227,15 +449,59 @@ describe("プローブ結果ごとの遷移", () => {
         expect(reload).not.toHaveBeenCalled();
     });
 
+    it("認証済みだが世代が不一致なら秘匿のまま読み直す (/login へ倒さない)", async () => {
+        const { replace, reload } = await restoreWith(() =>
+            Promise.resolve(probeResponse({ authenticated: true, sessionEpochMatches: false })),
+        );
+
+        expect(hiddenAttribute()).toBe(BFCACHE_STATE_RELOADING);
+        expect(reload).toHaveBeenCalledTimes(1);
+        expect(replace).not.toHaveBeenCalled();
+    });
+
     it("authenticated:false なら秘匿のまま login へ hard navigation する", async () => {
         const { replace } = await restoreWith(() =>
-            Promise.resolve(probeResponse({ authenticated: false })),
+            Promise.resolve(probeResponse({ authenticated: false, sessionEpochMatches: false })),
         );
 
         expect(replace).toHaveBeenCalledWith(LOGIN_PATH);
         expect(hiddenAttribute()).not.toBeNull();
     });
 
+    it("cookie を偽の値へ書き換えても開示に至らない (プローブが最後の関門)", async () => {
+        // 同期判定は client 側の値だけで通せるが、サーバが世代不一致と答えれば読み直しになる
+        const { win, dispatch, reload } = createWindowStub();
+        const fetchImpl = vi.fn<ProbeFetch>(() =>
+            Promise.resolve(probeResponse({ authenticated: true, sessionEpochMatches: false })),
+        );
+        registerBfcacheGuard({
+            win,
+            fetchImpl,
+            isAuthenticated: () => true,
+            readRenderedEpoch: () => EPOCH,
+            // 攻撃者が cookie を描画世代と同じ値へ書き換えた状況
+            readCurrentEpoch: () => EPOCH,
+        });
+
+        dispatch(transitionEvent("pagehide", true));
+        dispatch(transitionEvent("pageshow", true));
+        await flushProbe();
+
+        expect(hiddenAttribute()).toBe(BFCACHE_STATE_RELOADING);
+        expect(reload).toHaveBeenCalledTimes(1);
+    });
+
+    it("旧 shape (sessionEpochMatches 欠落) は秘匿維持 + 再試行 (契約ずれが開示に倒れない)", async () => {
+        const { replace, reload } = await restoreWith(() =>
+            Promise.resolve(probeResponse({ authenticated: true })),
+        );
+
+        expect(hiddenAttribute()).not.toBeNull();
+        expect(hiddenAttribute()).not.toBe(BFCACHE_STATE_RELOADING);
+        expect(replace).not.toHaveBeenCalled();
+        expect(reload).not.toHaveBeenCalled();
+    });
+
     it("fetch が reject したら秘匿維持 + 再試行 (自動再試行はしない)", async () => {
         const { fetchImpl, replace } = await restoreWith(() =>
             Promise.reject(new Error("network down")),
@@ -251,7 +517,9 @@ describe("プローブ結果ごとの遷移", () => {
 
     it("HTTP エラー応答 (ok=false) は秘匿維持 (login へ倒さない)", async () => {
         const { replace } = await restoreWith(() =>
-            Promise.resolve(probeResponse({ authenticated: false }, { ok: false })),
+            Promise.resolve(
+                probeResponse({ authenticated: false, sessionEpochMatches: false }, { ok: false }),
+            ),
         );
 
         expect(hiddenAttribute()).not.toBeNull();
@@ -261,7 +529,10 @@ describe("プローブ結果ごとの遷移", () => {
     it("Content-Type が JSON でなければ秘匿維持", async () => {
         const { replace } = await restoreWith(() =>
             Promise.resolve(
-                probeResponse({ authenticated: false }, { contentType: "text/html; charset=utf-8" }),
+                probeResponse(
+                    { authenticated: false, sessionEpochMatches: false },
+                    { contentType: "text/html; charset=utf-8" },
+                ),
             ),
         );
 
@@ -272,7 +543,10 @@ describe("プローブ結果ごとの遷移", () => {
     it("Content-Type の charset パラメータは許容する", async () => {
         const { replace } = await restoreWith(() =>
             Promise.resolve(
-                probeResponse({ authenticated: false }, { contentType: "application/json; charset=UTF-8" }),
+                probeResponse(
+                    { authenticated: false, sessionEpochMatches: false },
+                    { contentType: "application/json; charset=UTF-8" },
+                ),
             ),
         );
 
@@ -281,7 +555,7 @@ describe("プローブ結果ごとの遷移", () => {
 
     it("shape 不一致 (authenticated が boolean でない) は秘匿維持", async () => {
         const { replace } = await restoreWith(() =>
-            Promise.resolve(probeResponse({ authenticated: "false" })),
+            Promise.resolve(probeResponse({ authenticated: "false", sessionEpochMatches: false })),
         );
 
         expect(hiddenAttribute()).not.toBeNull();
@@ -290,7 +564,9 @@ describe("プローブ結果ごとの遷移", () => {
 
     it("data ラップ (top-level でない) は秘匿維持", async () => {
         const { replace } = await restoreWith(() =>
-            Promise.resolve(probeResponse({ data: { authenticated: true } })),
+            Promise.resolve(
+                probeResponse({ data: { authenticated: true, sessionEpochMatches: true } }),
+            ),
         );
 
         expect(hiddenAttribute()).not.toBeNull();
@@ -302,7 +578,12 @@ describe("再試行 UI", () => {
     it("再試行押下で現在 URL を hard reload する", async () => {
         const { win, dispatch, reload } = createWindowStub();
         const fetchImpl = vi.fn<ProbeFetch>(() => Promise.reject(new Error("network down")));
-        registerBfcacheGuard({ win, fetchImpl, isAuthenticated: () => true });
+        registerBfcacheGuard({
+            win,
+            fetchImpl,
+            isAuthenticated: () => true,
+            ...matchingEpochDeps(),
+        });
 
         dispatch(transitionEvent("pagehide", true));
         dispatch(transitionEvent("pageshow", true));
diff --git a/tests/js/lib/debug/bfcache-trial.test.ts b/tests/js/lib/debug/bfcache-trial.test.ts
index 13fa94a..60c2e6c 100644
--- a/tests/js/lib/debug/bfcache-trial.test.ts
+++ b/tests/js/lib/debug/bfcache-trial.test.ts
@@ -880,3 +880,116 @@ describe("storage", () => {
         expect(loadTrials().size).toBe(0);
     });
 });
+
+// ---------------------------------------------------------------------------
+
+/*
+ * T178: guard に「秘匿を維持したまま読み直す」状態 (reloading) が増えた。
+ * 検証ページはこれを軸 2 の終端候補 stale-session-reloaded として扱う。
+ * **新終端は単独では PASS にならない** (目視確認の記録が要る)。
+ */
+describe("軸 2: 読み直しに倒れた終端 (reloading)", () => {
+    /** 軸 1 window を成立させたうえで、復元後のイベントを足す (thunk で sequence を保つ)。 */
+    function afterRestore(...makeAfter: Array<() => TrialEvent>): TrialEvent[] {
+        const events: TrialEvent[] = [started(), away(), hide(true), show(true)];
+        for (const make of makeAfter) events.push(make());
+        return events;
+    }
+
+    it("pending → reloading は stale-session-reloaded (同期判定で読み直した)", () => {
+        expect(
+            deriveGuardVerdict(afterRestore(() => guard("pending"), () => guard("reloading"))),
+        ).toBe("stale-session-reloaded");
+    });
+
+    it("同じ列に redirect-observed が付くと unauthenticated-redirected", () => {
+        expect(
+            deriveGuardVerdict(
+                afterRestore(
+                    () => guard("pending"),
+                    () => guard("reloading"),
+                    () => redirect(),
+                ),
+            ),
+        ).toBe("unauthenticated-redirected");
+    });
+
+    it("pending → verifying → reloading でも同じ終端 (プローブ経由の読み直し)", () => {
+        expect(
+            deriveGuardVerdict(
+                afterRestore(
+                    () => guard("pending"),
+                    () => guard("verifying"),
+                    () => guard("reloading"),
+                ),
+            ),
+        ).toBe("stale-session-reloaded");
+    });
+
+    it("page-hide の guardState が reloading なら単独でも同じ終端 (取りこぼし時の裏取り)", () => {
+        expect(
+            deriveGuardVerdict(afterRestore(() => hide(true, TRIAL, "reloading"))),
+        ).toBe("stale-session-reloaded");
+    });
+
+    it("reloading から始まる列 (先頭が pending でない) は failed-transition", () => {
+        expect(deriveGuardVerdict(afterRestore(() => guard("reloading")))).toBe(
+            "failed-transition",
+        );
+        expect(
+            deriveGuardVerdict(afterRestore(() => guard("verifying"), () => guard("reloading"))),
+        ).toBe("failed-transition");
+    });
+
+    it("読み直し終端の後に guard イベントが追記されても崩れない", () => {
+        const events = afterRestore(
+            () => guard("pending"),
+            () => guard("reloading"),
+            // 読み直した先の fresh load で観測される列
+            () => guard("pending"),
+            () => guard("verifying"),
+            () => guard(null),
+        );
+        expect(deriveGuardVerdict(events)).toBe("stale-session-reloaded");
+    });
+
+    it("総合判定は undetermined、phase は awaiting-manual-confirmation (自動追記が止まる)", () => {
+        const events = afterRestore(() => guard("pending"), () => guard("reloading"));
+
+        expect(
+            deriveOverallVerdict("expired-session", "valid-bfcache", "stale-session-reloaded"),
+        ).toBe("undetermined");
+        expect(deriveTrialPhase(events)).toBe("awaiting-manual-confirmation");
+        expect(canAppend("awaiting-manual-confirmation", "guard-state-changed")).toBe(false);
+        expect(canAppend("awaiting-manual-confirmation", "redirect-observed")).toBe(true);
+    });
+
+    it("合格終端は unauthenticated-redirected のまま (T085 の完了条件は変わらない)", () => {
+        expect(expectedGuardVerdict("expired-session")).toBe("unauthenticated-redirected");
+    });
+
+    it("有効セッション経路 (pending → verifying → null) の判定は変わらない", () => {
+        expect(
+            deriveGuardVerdict(
+                afterRestore(
+                    () => guard("pending"),
+                    () => guard("verifying"),
+                    () => guard(null),
+                ),
+            ),
+        ).toBe("authenticated-unhidden");
+    });
+
+    it("schemaVersion 1 の旧記録は読み捨てられる (状態語彙が違うため)", () => {
+        expect(TRIAL_SCHEMA_VERSION).toBe(2);
+        expect(parseTrialEvent({ ...started(), schemaVersion: 1 })).toBeNull();
+        expect(
+            parseTrialLog(JSON.stringify([{ ...started(), schemaVersion: 1 }])),
+        ).toBeNull();
+    });
+
+    it("reloading は guard-state-changed / page-hide の許可値である", () => {
+        expect(parseTrialEvent(guard("reloading"))).not.toBeNull();
+        expect(parseTrialEvent(hide(true, TRIAL, "reloading"))).not.toBeNull();
+    });
+});
diff --git a/tests/js/lib/shared-props.test.ts b/tests/js/lib/shared-props.test.ts
new file mode 100644
index 0000000..f854c23
--- /dev/null
+++ b/tests/js/lib/shared-props.test.ts
@@ -0,0 +1,45 @@
+/**
+ * Tests for resources/js/lib/shared-props.ts
+ *
+ * 描画世代 (共有 prop `sessionEpoch`) の読み取りは、**書式が違えば null に倒す**。
+ * 「読めない」は bfcache guard 側で「開示しない (読み直す)」に写るため、
+ * ここを緩めると同期判定の前置が意味を失う。
+ */
+import { describe, expect, it } from "vitest";
+
+import { hasAuthenticatedUser, readSessionEpoch } from "@/lib/shared-props";
+
+const EPOCH = "0123456789abcdef0123456789abcdef";
+
+describe("readSessionEpoch", () => {
+    it("正しい書式の値をそのまま返す", () => {
+        expect(readSessionEpoch({ sessionEpoch: EPOCH })).toBe(EPOCH);
+    });
+
+    it("欠落・null・型違いは null", () => {
+        expect(readSessionEpoch({})).toBeNull();
+        expect(readSessionEpoch({ sessionEpoch: null })).toBeNull();
+        expect(readSessionEpoch({ sessionEpoch: 12345 })).toBeNull();
+        expect(readSessionEpoch(null)).toBeNull();
+        expect(readSessionEpoch("string")).toBeNull();
+        expect(readSessionEpoch(undefined)).toBeNull();
+    });
+
+    it("書式違い (大文字 / 33 文字 / 31 文字 / 非 16 進) は null", () => {
+        expect(readSessionEpoch({ sessionEpoch: EPOCH.toUpperCase() })).toBeNull();
+        expect(readSessionEpoch({ sessionEpoch: `${EPOCH}0` })).toBeNull();
+        expect(readSessionEpoch({ sessionEpoch: EPOCH.slice(0, 31) })).toBeNull();
+        expect(readSessionEpoch({ sessionEpoch: `${EPOCH.slice(0, 31)}g` })).toBeNull();
+        expect(readSessionEpoch({ sessionEpoch: "" })).toBeNull();
+    });
+});
+
+describe("hasAuthenticatedUser", () => {
+    it("auth.user がオブジェクトのときだけ true", () => {
+        expect(hasAuthenticatedUser({ auth: { user: { id: 1 } } })).toBe(true);
+        expect(hasAuthenticatedUser({ auth: { user: null } })).toBe(false);
+        expect(hasAuthenticatedUser({ auth: {} })).toBe(false);
+        expect(hasAuthenticatedUser({})).toBe(false);
+        expect(hasAuthenticatedUser(null)).toBe(false);
+    });
+});

```

## テスト結果

全 10 本の検証コマンドが green:
- composer test  : 5179 tests / 5177 passed / 2 skipped / 22136 assertions
- composer phpstan: No errors (level 10)
- vendor/bin/pint --test: passed
- pnpm lint / pnpm typecheck: passed
- pnpm test: 137 files / 1533 tests passed
- pnpm build / pnpm typecheck:packages / pnpm build:packages / pnpm test:packages: passed

契約ずれ検査の負のコントロール実測 (devnotes/20260816-0202-todo-T178/contract-sync-negative-control.md):
- 語をファイルから全消しした 6 通りはすべて赤になる
- 宣言 1 行だけを改名した 6 通りのうち赤になるのは 2 通り (同じ語が docblock や型宣言に残るため)。
  この限界はテスト本体の docblock に明記済み。
