# 詳細設計レビュー依頼 (Round 1): inline throttle 群の bucket 共有の見直し (T125)

## アプリの使命 (North Star) — AGENTS.md より転記

**AI-CUE** は、現場に既にある**作業手順書(SOP)を起点に**、AI が撮るべきカットを設計した**動画シナリオ**を生成し、そのシナリオを**スマホ(PWA)でナビゲーション撮影**することで、専門知識ゼロの現場作業者でも**標準化されたマニュアル動画**を作れるようにする。

- 「思考ゼロ・編集ゼロ」— 台本作成・撮影判断・編集の 3 ハードルを AI とナビ撮影で肩代わりする。
- 競合(OJT を撮って形式化する tebiki)と異なり、**標準作業を起点に AI が教材設計し撮影を指示する**（撮影者・教える人のスキルに品質を依存させない）。
- 熟練者の暗黙知を動画マニュアルという形式知へ変換する装置（SECI）。

> 詳細な設計は本リポジトリの `doc/`（01〜10）に置く。ドメインをテンプレート構造へどうマップしたかは `doc/08`〜`doc/10`（特に `doc/10 §10.8` に設計レビュー反映済み）。
> **v1 スコープ**: 字幕のみ(TTS 後回し) / 撮影は PWA(同一オリジン・セッション認証) / 動画合成は自前 ffmpeg / 単一 Default Project。

## 禁止事項 — AGENTS.md より転記

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

## 思考原則 — 全議論に適用

まず仮説を立てろ。何を検証したいのか、なぜそう考えるのか、どうなれば成功と判断するのかを明確にしてから手を動かせ。仮説なき改善はただの試行錯誤であり、結果から学ぶことができない。

データに真摯に向き合え。成果だけでなく、多様性の変化、構造の揺らぎ、想定外のパターン — 全てが判断材料になる。数値を見て即座に閾値を弄るな。何が起きているのかを理解し、なぜそうなったのかを考え、どの方向に進むべきかを判断してから手を動かせ。

先人の知恵を探せ。自分たちだけで登る必要はない。乗るべき巨人の肩があるなら乗れ。

機能の名前に立ち返れ。名前はその機能が果たすべき役割を示している。現在の設計がその役割を果たしているか、常に問え。

仕組みが機能していない段階で値を弄るな。閾値チューニングやフィールド追加は、設計の方向性が正しいと確認できてから行え。方向性が間違っているなら、値をいくら調整しても意味はない。設計そのものを見直せ。成果が出なければ早期に見切り、次の仮説へ進め。

## ツール使用制限

コマンド実行・ファイル書き込みは一切行わず、提供されたテキストの分析に集中すること。ファイル読み込みは許可。

---

## あなたの役割

あなたは経験豊富な Web アプリケーションアーキテクトです。Laravel + Svelte アプリケーション改善の詳細設計をレビューしてください。

【前提環境】
- PHP 8.4 + Laravel 12 + Svelte 5 + Inertia.js + TypeScript
- PHPStan level 10
- Pest テストフレームワーク (RefreshDatabase グローバル適用 + --parallel、個別 DatabaseTransactions 禁止、テストデータは Factory)
- DTO + JsonResource パターン
- Laratrust RBAC (Organization → Team → Project 階層)

【レビュー観点】
1. コードの正確性（ロジックエラー、エッジケース、null 安全性）
2. 既存コードとの整合性（命名規約、パターン、API）
3. PHPStan level 10 適合性（型安全性、generics、Assert 使用）
4. テスト計画の網羅性（各施策に Pest テスト、RefreshDatabase グローバル適用に従う）
5. DTO/JsonResource パターンの遵守
6. Inertia Props vs API Response の使い分け
7. 副作用・後退リスク
8. 波及変更の網羅性（TypeScript 型定義、API Resource、テストが変更対象に含まれているか）
9. セキュリティ（認可チェック、入力バリデーション、OWASP Top 10、AGENTS.md のセキュリティ不変条件）
10. DESIGN.md 準拠（UI/frontend 変更を含む場合）
11. Atomic Design 準拠（UI/frontend 変更を含む場合）

【本設計に固有の重点観点】
- deny-by-default 目録型 gate として成立しているか（母集団の取り方 / 空振り / 免除の形骸化 / exact-fit cap）
- gate が本当に赤くなるか（mutation 手順が「そのテストだけを赤にする」ものになっているか）
- 閾値を 1 つも変えていないか（AGENTS.md ドメイン規約 5「閾値は既存値を変えない」）
- 新設テストが `--parallel` + `RefreshDatabase` で安定するか（RateLimiter は array store）

【出力形式】
- 各施策ごとに判定: APPROVE / REQUEST_CHANGES
- 指摘は [Critical] [Warning] [Suggestion] で分類
- Critical/Warning には必ず修正案を添える
- 全体判定: APPROVED / CHANGES_REQUESTED
- 日本語で出力

---

## 詳細設計書

# 詳細設計: inline-throttle-bucket-separation (T125)

## 使命・制約（絶対遵守）

### アプリの使命（North Star）— AGENTS.md より転記

**AI-CUE** は、現場に既にある**作業手順書(SOP)を起点に**、AI が撮るべきカットを設計した**動画シナリオ**を生成し、そのシナリオを**スマホ(PWA)でナビゲーション撮影**することで、専門知識ゼロの現場作業者でも**標準化されたマニュアル動画**を作れるようにする。

- 「思考ゼロ・編集ゼロ」— 台本作成・撮影判断・編集の 3 ハードルを AI とナビ撮影で肩代わりする。
- 競合(OJT を撮って形式化する tebiki)と異なり、**標準作業を起点に AI が教材設計し撮影を指示する**（撮影者・教える人のスキルに品質を依存させない）。
- 熟練者の暗黙知を動画マニュアルという形式知へ変換する装置（SECI）。

> **v1 スコープ**: 字幕のみ(TTS 後回し) / 撮影は PWA(同一オリジン・セッション認証) / 動画合成は自前 ffmpeg / 単一 Default Project。

### 禁止事項 — AGENTS.md より転記

1. テストなしの実装完了報告(不変条件は対応する Architecture/Feature テストへの登録まで含めて「実装済み」)
2. PHPStan エラーの widen(型を緩めて黙らせる)・baseline 化
3. dev DB への破壊操作(`migrate:fresh` 等)をエージェント判断で実行すること
4. `response()->json()` の直書き(DTO / JsonResource / Inertia を使う。仕様固定 endpoint のみ例外)
5. LLM 呼び出しの Prism 直呼び(`app/Prompts/` の factory 経由のみ。PromptGuardrailTest が検出)
6. prompt 文字列のコード直書き(`resources/prompts/*.yaml` に置く)
7. 操作系 POST の応答での `redirect()->intended()`(ログイン直後フロー専用。招待送信等は `back()->with(...)` で完結させる)
8. 必須条件未充足を理由にボタンを disabled にする UI(押下時にエラー表示する。DESIGN.md)
9. Artifact の使用(Artifact ツールでの成果物公開を行わない。成果物はリポジトリ内のファイルとして出力する)

### ドメイン固有規約 5（流量制限）— 本設計が直接従う規約

- 保護対象群は throttle を**ちょうど 1 本**持つか、`ThrottleCoverageExemption` + 30 文字以上の根拠で exemption 目録へ登録する（`ThrottleCoverageInventoryTest` が deny-by-default で強制）
- named limiter のキーは **`{レーン}:{種別}:{値}`**（`RateLimiterKeyConventionTest` が全 limiter を実評価して検査）
- **閾値は既存値を変えない。新しい面には既に本番稼働中の同性質エンドポイントと同値を充てる**
- inline throttle は「認証済みかつ actor 自身に閉じる操作」限定。**レーンを分けたいときは inline ではなく named limiter を新設する**
- 貼る仕組みの 3 段優先順（`docs/app-integration-guide.md` §7b）: 1. route 定義に直書き / 2. package の設定 / 3. `RouteThrottleBinder::attachOnBooted()`。**上で貼れるなら下は使わない**

### コーディングルール

- **PHPStan level 10** 必須（`composer phpstan`）
- **Pest**（`composer test`）。**RefreshDatabase** は `tests/Pest.php` でグローバル適用、`--parallel` 実行。個別 `DatabaseTransactions` 使用禁止
- テストデータは必ず **Factory** で生成
- `declare(strict_types=1)` + 日本語コメント。アーリーリターン推奨
- `composer fix`（Pint）/ `pnpm lint:fix`
- PHP 8.4 + Laravel 12

## 概念設計リファレンス

`devnotes/20260807-2032-todo-T125-design/conceptual-design.md`（conceptual-review Round 3 で APPROVED）

## 施策一覧

| # | 施策名 | 変更ファイル | 優先度 |
|---|--------|------------|--------|
| S1 | `RateLimiterKeys` helper 新設（キー組み立ての一点集約） | `app/Support/Http/RateLimiterKeys.php`（新規）/ `app/Providers/FortifyServiceProvider.php` | High |
| S2 | 認証面 4 レーンの named limiter 登録 | `app/Providers/FortifyServiceProvider.php` | Critical |
| S3 | 業務面 2 レーンの named limiter 登録 | `app/Providers/AppServiceProvider.php` | Critical |
| S4 | route への適用（直書き 4 / config 1 / binder 6） | `routes/web.php` / `config/fortify.php` / `app/Providers/FortifyServiceProvider.php` | Critical |
| S5 | inline 残置の目録 gate（deny-by-default） | `app/Enums/Security/InlineThrottleBucketRationale.php`（新規）/ `tests/Architecture/InlineThrottleInventoryTest.php`（新規） | High |
| S6 | レーン割当の目録 gate（相乗り禁止） | `tests/Architecture/ThrottleLaneAssignmentTest.php`（新規） | High |
| S7 | キー規約検査の拡張（6 レーン + 衝突検査 + 共有グループ目録） | `tests/Architecture/RateLimiterKeyConventionTest.php` | High |
| S8 | behavioral proof（レーン独立 + 上限維持 + 実効順） | `tests/Feature/Security/AuthThrottleCoverageTest.php` | Critical |
| S9 | 既存テスト・ドキュメント・docblock の追随 | 6 ファイル | High |

> **変更範囲の宣言**: 本設計が触るのは **throttle middleware の指定** と **RateLimiter 登録**、およびそれらのテスト・ドキュメントのみ。
> controller / FormRequest / 応答形式（DTO / JsonResource / Inertia / redirect）には一切触らない。
> vendor route の middleware stack も上書きしない。

---

## S1: `RateLimiterKeys` helper 新設

### 変更箇所

- 新規: `app/Support/Http/RateLimiterKeys.php`
- 既存: `app/Providers/FortifyServiceProvider.php` L283-291（`passkeys`）/ L309-316（`two-factor-secret-read`）をこの helper へ寄せる

### 波及変更

- TypeScript 型定義: なし（サーバ内部のキー生成）
- API Resource/DTO: なし
- テストファイル: `tests/Architecture/RateLimiterKeyConventionTest.php` は**変更不要**（キー文字列が変わらないことを既存の exact-fit 検査が保証する = これが移行の安全網になる）

### 現行コード

```php
// app/Providers/FortifyServiceProvider.php
RateLimiter::for('passkeys', function (Request $request): Limit {
    $identifier = $request->user()?->getAuthIdentifier();

    return is_scalar($identifier)
        ? Limit::perMinute(10)->by('passkeys:user:'.$identifier)
        : Limit::perMinute(10)->by('passkeys:ip:'.($request->ip() ?? 'unknown'));
});

RateLimiter::for('two-factor-secret-read', function (Request $request): Limit {
    $identifier = $request->user()?->getAuthIdentifier();

    return is_scalar($identifier)
        ? Limit::perMinute(10)->by('two-factor-secret-read:user:'.$identifier)
        : Limit::perMinute(10)->by('two-factor-secret-read:ip:'.($request->ip() ?? 'unknown'));
});
```

### 変更後コード

```php
<?php

declare(strict_types=1);

namespace App\Support\Http;

use Illuminate\Http\Request;

/**
 * named limiter のキー `{レーン}:{種別}:{値}` を組む唯一の入口（actor か IP で数えるレーン用）。
 *
 * ★存在理由: 「認証済みなら actor / 未認証なら IP」という同じ分岐を 8 個の limiter closure に
 *   ベタ書きすると、レーン名の typo・分岐の取り違え・null 扱いの差異が入り込む。
 *   キー規約 (`{レーン}:{種別}:{値}`) の実装点を 1 つにする。
 *
 * ★`is_scalar()` を使わない理由: `getAuthIdentifier()` の契約は `int|string|null` であり、
 *   `is_scalar()` は `bool` / `float` まで通してしまう（`true` が `:user:1` へ潰れる）。
 *   契約どおり `is_int()` / `is_string()` で明示的に絞り込む。
 *
 * ★lane を enum にしない: `RateLimiter::for()` の第 1 引数は
 *   `Tests\Support\RateLimiterRegistrationScanner` の要求で**リテラル文字列**でなければならず
 *   （解析できない登録は `RateLimiterKeyConventionTest` の unresolved 検査が fail させる）、
 *   enum を入れると「`for()` にはリテラル / helper には enum」の二重管理になる。
 *
 * ★これは**数える単位**を決めるだけで、認可でも認証でもない。
 */
final class RateLimiterKeys
{
    /** 未認証で IP も取れないときの終端値（キーを空にしない）。 */
    private const UNKNOWN_IP = 'unknown';

    /**
     * 認証済みなら `{lane}:user:{id}`、未認証なら `{lane}:ip:{ip}`。
     *
     * throttle middleware は route によっては auth より後に走る（現行の priority list では
     * `AuthenticatesRequests` → `ThrottleRequests`）。したがって auth 必須 route では
     * user 分岐しか通らないが、**auth を持たない route でも同じ helper が使える**ように
     * IP 分岐を常に持つ（priority list への依存を単一障害点にしない）。
     *
     * @param  non-empty-string  $lane
     * @return non-empty-string
     */
    public static function actorOrIp(Request $request, string $lane): string
    {
        $identifier = $request->user()?->getAuthIdentifier();

        if (is_int($identifier)) {
            return $lane.':user:'.$identifier;
        }

        if (is_string($identifier) && $identifier !== '') {
            return $lane.':user:'.$identifier;
        }

        $ip = $request->ip();

        return $lane.':ip:'.($ip === null || $ip === '' ? self::UNKNOWN_IP : $ip);
    }
}
```

`FortifyServiceProvider` 側は既存 2 本を書き換える（キー文字列は不変）:

```php
RateLimiter::for('passkeys', fn (Request $request): Limit => Limit::perMinute(10)
    ->by(RateLimiterKeys::actorOrIp($request, 'passkeys')));

RateLimiter::for('two-factor-secret-read', fn (Request $request): Limit => Limit::perMinute(10)
    ->by(RateLimiterKeys::actorOrIp($request, 'two-factor-secret-read')));
```

（`use App\Support\Http\RateLimiterKeys;` を追加。既存 docblock の説明はそのまま残す。）

### PHPStan 適合チェック

- [x] 戻り値の型が明示されている（`non-empty-string` を phpdoc で明示、`string` を signature に）
- [x] null 安全（`?->` + `is_int()` / `is_string()` + `$request->ip()` の null 分岐）
- [x] DTO を返している（該当なし。キー文字列生成の helper）
- [x] Generics の型パラメータ（該当なし）
- 追加確認: `$request->user()` は `Authenticatable|null`、`getAuthIdentifier()` は `mixed` を返すため
  **narrowing なしで文字列連結すると level 10 で落ちる**。上記の 2 段分岐で閉じる。

### テスト計画

- [x] 既存テスト `tests/Architecture/RateLimiterKeyConventionTest.php` の
      「produce された `{レーン}:{種別}` 集合が expectedKeyPrefixes と完全一致する」が
      **無変更で green のまま**であること = キー文字列が変わっていないことの証明
      （`passkeys` の `passkeys:user` / `passkeys:ip`、`two-factor-secret-read` の 2 つ）
- [ ] 新規 `tests/Unit/Support/Http/RateLimiterKeysTest.php`
  - `actorOrIp() は認証済みユーザーに {lane}:user:{id} を返す`
  - `actorOrIp() は未認証に {lane}:ip:{ip} を返す`
  - `actorOrIp() は IP が取れないとき {lane}:ip:unknown を返す（キーを空にしない）`
  - `actorOrIp() は identifier が bool / float のとき user 分岐へ落ちない（is_scalar 相当の誤受理の負のコントロール）`
    — `Authenticatable` の匿名実装で `getAuthIdentifier()` に `true` / `1.5` を返させ、`:ip:` になることを検査
- [ ] 個別の `DatabaseTransactions` を使っていないことを確認（Unit テストで DB 非依存）

### リスク

- **既存 2 limiter のキーが変わると passkey / 2FA 秘密読み取りの bucket がリセットされる**（デプロイ直後の 1 分だけ枠が復活する）。
  キー文字列は完全に同一に保つ設計であり、`RateLimiterKeyConventionTest` の exact-fit 検査が差分を検出する。
- `is_scalar` → `is_int`/`is_string` の変更で挙動が変わるのは identifier が `bool`/`float` の場合のみ。
  本アプリの `User::getAuthIdentifier()` は `int` を返すため実挙動は不変（Unit テストで両方向を固定）。

---

## S2: 認証面 4 レーンの named limiter 登録

### 変更箇所

- `app/Providers/FortifyServiceProvider.php` `configureRateLimiters()` の末尾（`configureAuthFormRateLimiters()` 呼び出しの直前）に新メソッド `configureStepUpAndCredentialRateLimiters()` を追加して呼ぶ

### 波及変更

- TypeScript 型定義: なし
- API Resource/DTO: なし
- テストファイル: `tests/Architecture/RateLimiterKeyConventionTest.php`（S7 で 4 レーン分の検証シナリオを追加。**追加しないと「scan で検出した limiter 名の集合が inventory と完全一致する」が fail する** = 登録漏れを既存 gate が捕まえる）

### 現行コード

該当 limiter は存在しない（対象 route は inline `throttle:6,1` / `throttle:10,1`）。

### 変更後コード

```php
/**
 * inline throttle から移行した「actor 自身に閉じる認証面」のレーン群（T125）。
 *
 * ★なぜ inline から移すのか:
 *   `ThrottleRequests::handle()` の inline 経路が組むキーは
 *   `$prefix`（既定 `''`）+ `resolveRequestSignature()` で、後者は認証済みなら
 *   **user id だけ**を返す（route 名も limiter 名も入らない）。つまり
 *   **同一 actor の全 inline throttle route が 1 bucket を共有**し、
 *   最小 max を持つ `recent-auth.password`（6）が他 route の連打で先に潰れて
 *   **再認証ができなくなる**。named limiter はキーにレーン名が入るため独立する。
 *
 * ★閾値は移行元の inline 値そのまま（新しい値を発明しない。AGENTS.md ドメイン規約 5）。
 *
 * ★レーンの切り方は 2 基準あり、混同しない:
 *   - **同じ credential を照合する面** = その秘密に対する「試行予算」（password-verify）
 *   - **同じ feature のフロー** = そのフローの「操作予算」（two-factor-manage / email-verification）
 *   フロー内の相互消費は許容し、**別 feature との巻き添えを遮断する**のが本設計の目的。
 */
private function configureStepUpAndCredentialRateLimiters(): void
{
    // パスワード**照合**の試行予算。3 本 (recent-auth.password / password.confirm.store /
    // user-password.update) が 1 つの秘密を数えるため、合算 6/min を維持する
    // （面ごとに分けると同じ秘密に 18 回/min 試せることになり総当り耐性が下がる）。
    RateLimiter::for('password-verify', fn (Request $request): Limit => Limit::perMinute(6)
        ->by(RateLimiterKeys::actorOrIp($request, 'password-verify')));

    // パスワードの**初回設定** (settings.password.store)。current_password の照合を伴わない
    // credential mutation であり数える対象が違うため password-verify とはレーンを分ける。
    // 同居させると「設定に 6 回失敗 → step-up 再認証が 429」という巻き添えが残る。
    RateLimiter::for('password-set', fn (Request $request): Limit => Limit::perMinute(6)
        ->by(RateLimiterKeys::actorOrIp($request, 'password-set')));

    // メール検証フロー (verification.send / verification.verify)。
    // ★2 本が同レーンなのは Fortify が config('fortify.limiters.verification') という
    //   **1 つの knob** で両方に貼るためで、第 2 段（package の設定）で貼る限り構造的にそうなる。
    //   概念的には「外向きメール送信」と「署名付き GET の検証」は数える対象が違うため、
    //   これは**暫定判断**である（分離条件は conceptual-design.md §改善アイデア 1）。
    RateLimiter::for('email-verification', fn (Request $request): Limit => Limit::perMinute(6)
        ->by(RateLimiterKeys::actorOrIp($request, 'email-verification')));

    // 2FA 設定フローの操作予算 (enable / confirm / disable / regenerate-recovery-codes)。
    // ★受容リスク: enable/disable/regenerate の消費で、秘密照合面である confirm が
    //   429 になる構造が意図的に残る。4 本は同一画面から踏む 1 フローであり、
    //   TOTP の天井は分離しても 10 のまま変わらない（分離してもレーンが増えるだけ）。
    RateLimiter::for('two-factor-manage', fn (Request $request): Limit => Limit::perMinute(10)
        ->by(RateLimiterKeys::actorOrIp($request, 'two-factor-manage')));
}
```

### PHPStan 適合チェック

- [x] 戻り値の型が明示されている（arrow function に `: Limit`）
- [x] null 安全（S1 の helper 内で閉じている）
- [x] DTO を返している（該当なし）
- [x] Generics（該当なし）

### テスト計画

- [ ] `tests/Architecture/RateLimiterKeyConventionTest.php`（S7）に 4 レーンを追加
      — 追加しないと既存の「scan で検出した limiter 名の集合が inventory と完全一致する」が fail
- [ ] `tests/Feature/Security/AuthThrottleCoverageTest.php`（S8）で実 HTTP 挙動を固定

### リスク

- レーン名の typo（`password-verify` を route 側で `password-verifiy` と書く）は
  リクエスト時に `MissingRateLimiterException` になる。**S6 の gate が build 時に検出する**。
- `email-verification` の暫定統合により、メールクライアントのリンク先読みで
  `verification.verify` が消費されると再送が 429 になりうる（現状も同一 bucket なので後退はしない）。

---

## S3: 業務面 2 レーンの named limiter 登録

### 変更箇所

- `app/Providers/AppServiceProvider.php`
  - `boot()` の limiter 登録呼び出し列（`$this->configureApiRateLimiters();` 〜）へ 1 行追加
  - 新メソッド `configureActorScopedRateLimiters()` を追加

### 波及変更

- TypeScript 型定義: なし / API Resource・DTO: なし
- テストファイル: S7（キー検証シナリオ）/ S8（behavioral）

### 現行コード

```php
        $this->configureApiRateLimiters();
        $this->configureAuthSurfaceRateLimiters();
        $this->configureInquiryRateLimiter();
        $this->configureRenderRateLimiter();
        $this->configureWebhookRateLimiters();
        $this->attachThrottleToVendorRoutes();
```

### 変更後コード

```php
        $this->configureActorScopedRateLimiters();
        $this->configureApiRateLimiters();
        $this->configureAuthSurfaceRateLimiters();
        $this->configureInquiryRateLimiter();
        $this->configureRenderRateLimiter();
        $this->configureWebhookRateLimiters();
        $this->attachThrottleToVendorRoutes();
```

```php
/**
 * 認証済み actor 自身に閉じる業務操作のレーン（T125 で inline から移行）。
 *
 * ★`configureAuthSurfaceRateLimiters()` とは対象が違う。あちらは**未認証面の IP レーン**、
 *   こちらは**認証済み actor レーン**である（数える単位が違うので同じメソッドに混ぜない）。
 *
 * ★閾値は移行元の inline 値（どちらも 10/min）そのまま。
 */
private function configureActorScopedRateLimiters(): void
{
    // 招待受諾の確定 (POST /invitations/accept)。
    // ★未認証面の `invitation-accept`（GET・IP レーン 10/min）とは**別レーン**にする。
    //   同一 bucket にすると確認画面のリロードが受諾の枠を食い、
    //   「リンクを開き直したら受諾できない」という詰みを作る。
    //   token 総当りの天井は 10/min のままで変わらない。
    RateLimiter::for('invitation-accept-submit', fn (Request $request): Limit => Limit::perMinute(10)
        ->by(RateLimiterKeys::actorOrIp($request, 'invitation-accept-submit')));

    // パーソナルプランの有効化 (POST /onboarding/activate-personal)。
    // 一回性の操作であり、連打の実効は事前条件（既契約なら常に失敗）が抑えるが、
    // throttle は「試行の受理数」の上限として 10/min を維持する。
    RateLimiter::for('plan-activate', fn (Request $request): Limit => Limit::perMinute(10)
        ->by(RateLimiterKeys::actorOrIp($request, 'plan-activate')));
}
```

（`use App\Support\Http\RateLimiterKeys;` を追加。）

### PHPStan 適合チェック

- [x] 戻り値の型が明示されている / [x] null 安全（helper 内） / [x] DTO（該当なし） / [x] Generics（該当なし）

### テスト計画

- [ ] S7 のキー検証シナリオに 2 レーン追加
- [ ] S8 で `invitation-accept-submit` / `plan-activate` の上限とレーン独立を固定

### リスク

- `invitation-accept` と `invitation-accept-submit` の名前が似ており取り違えやすい。
  S6 の割当目録が「どの route がどちらを使うか」を機械固定する。

---

## S4: route への適用

### 変更箇所

| 貼る段 | ファイル | 対象 |
|---|---|---|
| 第 1 段（直書き） | `routes/web.php` L193 / L202 / L384 / L615 | `recent-auth.password` / `settings.password.store` / `onboarding.activate-personal` / `invitations.accept.store` |
| 第 2 段（package 設定） | `config/fortify.php` `limiters` | `verification.send` / `verification.verify` |
| 第 3 段（binder） | `app/Providers/FortifyServiceProvider.php` `throttledFortifyRoutes()` | `password.confirm.store` / `user-password.update` / `two-factor.enable` / `.confirm` / `.disable` / `.regenerate-recovery-codes` |

### 波及変更

- TypeScript 型定義: なし（URL も HTTP メソッドも変わらない）
- API Resource/DTO: なし
- テストファイル: `tests/Feature/Onboarding/ActivatePersonalTest.php` /
  `tests/Feature/Settings/PasswordSetupTest.php` /
  `tests/Feature/Auth/FortifyResponseTest.php` /
  `tests/Architecture/ControllerAuthorizationGateTest.php` /
  `tests/Architecture/ThrottleCoverageInventoryTest.php`（いずれも**文言のみ**。S9 で扱う）

### 現行コード

```php
// routes/web.php
    Route::post('/recent-auth/password', [ConfirmRecentAuthController::class, 'confirmPassword'])
        ->middleware('throttle:6,1')
        ->name('recent-auth.password');
...
    Route::post('/settings/password', [PasswordSetupController::class, 'store'])
        ->middleware(['recent-auth', 'throttle:6,1'])
        ->name('settings.password.store');
...
    Route::post('/onboarding/activate-personal', ActivatePersonalController::class)
        ->middleware('throttle:10,1')
        ->name('onboarding.activate-personal');
...
Route::post('/invitations/accept', [InvitationAcceptanceController::class, 'store'])
    ->middleware(['auth', 'throttle:10,1'])
    ->name('invitations.accept.store');
```

```php
// config/fortify.php
    'limiters' => [
        'login' => 'login',
        'two-factor' => 'two-factor',
        'passkeys' => 'passkeys',
    ],
```

```php
// app/Providers/FortifyServiceProvider.php
    private static function throttledFortifyRoutes(): array
    {
        return [
            'password.email' => ['throttle' => 'password-reset-request', 'feature' => Features::resetPasswords()],
            'password.update' => ['throttle' => 'password-reset-submit', 'feature' => Features::resetPasswords()],
            'register.store' => ['throttle' => 'account-register', 'feature' => Features::registration()],
            'password.confirm.store' => ['throttle' => '6,1', 'feature' => null],
            'user-password.update' => ['throttle' => '6,1', 'feature' => Features::updatePasswords()],
            'two-factor.enable' => ['throttle' => '10,1', 'feature' => Features::twoFactorAuthentication()],
            'two-factor.confirm' => ['throttle' => '10,1', 'feature' => Features::twoFactorAuthentication()],
            'two-factor.disable' => ['throttle' => '10,1', 'feature' => Features::twoFactorAuthentication()],
            'two-factor.regenerate-recovery-codes' => ['throttle' => '10,1', 'feature' => Features::twoFactorAuthentication()],
            'two-factor.qr-code' => ['throttle' => 'two-factor-secret-read', 'feature' => Features::twoFactorAuthentication()],
            'two-factor.secret-key' => ['throttle' => 'two-factor-secret-read', 'feature' => Features::twoFactorAuthentication()],
            'two-factor.recovery-codes' => ['throttle' => 'two-factor-secret-read', 'feature' => Features::twoFactorAuthentication()],
        ];
    }
```

### 変更後コード

```php
// routes/web.php
    // 再認証 (step-up) の password satisfier。**この route が 429 になると復帰導線が塞がる**ため、
    // 他の認証操作と bucket を共有しない named limiter を使う（T125。inline は共有される）。
    Route::post('/recent-auth/password', [ConfirmRecentAuthController::class, 'confirmPassword'])
        ->middleware('throttle:password-verify')
        ->name('recent-auth.password');
...
    // 初回設定は current_password を照合しない credential mutation のため
    // 照合面 (password-verify) とはレーンを分ける（T125）。閾値は 6/min のまま。
    Route::post('/settings/password', [PasswordSetupController::class, 'store'])
        ->middleware(['recent-auth', 'throttle:password-set'])
        ->name('settings.password.store');
...
    Route::post('/onboarding/activate-personal', ActivatePersonalController::class)
        ->middleware('throttle:plan-activate')
        ->name('onboarding.activate-personal');
...
// 招待トークンは hash 照合されるが、総当り試行そのものを有界にする（10/min は据え置き）。
// GET 側の `invitation-accept`（未認証 IP レーン）とは別レーン = 確認画面のリロードが
// 受諾の枠を食わない（T125）。
Route::post('/invitations/accept', [InvitationAcceptanceController::class, 'store'])
    ->middleware(['auth', 'throttle:invitation-accept-submit'])
    ->name('invitations.accept.store');
```

```php
// config/fortify.php
    'limiters' => [
        'login' => 'login',
        'two-factor' => 'two-factor',
        // passkey endpoint の絞り。（既存コメントはそのまま）
        'passkeys' => 'passkeys',
        // メール検証 (verification.send / verification.verify)。**未設定だと Fortify 既定の
        // inline `6,1` になり、同一 actor の全 inline route と bucket を共有する**（T125）。
        // 1 knob で 2 route に貼られるため、この 2 本は構造的に同一レーンになる。
        // limiter 本体は FortifyServiceProvider::configureStepUpAndCredentialRateLimiters()。
        'verification' => 'email-verification',
    ],
```

```php
// app/Providers/FortifyServiceProvider.php
            // ★T125: inline (`6,1` / `10,1`) から named limiter へ移行。
            //   inline のキーは user id だけで route も limiter 名も入らず、
            //   同一 actor の全 inline route が 1 bucket を共有するため
            //   （2FA 管理を連打すると再認証が 429 になる）。閾値は移行前と同値。
            'password.confirm.store' => ['throttle' => 'password-verify', 'feature' => null],
            'user-password.update' => ['throttle' => 'password-verify', 'feature' => Features::updatePasswords()],
            'two-factor.enable' => ['throttle' => 'two-factor-manage', 'feature' => Features::twoFactorAuthentication()],
            'two-factor.confirm' => ['throttle' => 'two-factor-manage', 'feature' => Features::twoFactorAuthentication()],
            'two-factor.disable' => ['throttle' => 'two-factor-manage', 'feature' => Features::twoFactorAuthentication()],
            'two-factor.regenerate-recovery-codes' => ['throttle' => 'two-factor-manage', 'feature' => Features::twoFactorAuthentication()],
```

> `RouteThrottleBinder::assertValidLimiter()` は named（`[a-z][a-z0-9-]*`）/ inline（`{max},{decay}`）
> の両形式を受理するため、値を limiter 名へ差し替えるだけで動作する（binder 側の変更は不要）。

### PHPStan 適合チェック

- [x] 戻り値の型（`throttledFortifyRoutes()` の phpdoc `array<string, array{throttle: string, feature: string|null}>` は不変）
- [x] null 安全 / [x] DTO（該当なし） / [x] Generics（該当なし）

### テスト計画

- [ ] S6 の割当目録 gate が「13 route → 6 レーン」の対応を機械固定
- [ ] 既存 `tests/Architecture/ThrottleCoverageInventoryTest.php` の
      「保護対象 route は throttle をちょうど 1 本持つ」が **無変更で green**（本数は変わらない）
- [ ] 既存 `tests/Feature/Security/RouteThrottleBinderTest.php` は無変更で green
      （binder の契約は変えていない）
- [ ] `tests/Feature/Onboarding/ActivatePersonalTest.php` の 429 テストが green のまま
      （10/min という**挙動**は不変。テスト名のみ S9 で更新）
- [ ] `tests/Feature/Settings/PasswordSetupTest.php` の 429 テストが green のまま（6/min 不変）

### リスク

- **`config/fortify.php` の `limiters.verification` を設定すると Fortify 側は
  `throttle:email-verification` を貼る**。limiter が未登録だとリクエスト時に
  `MissingRateLimiterException`。S2 と同一 PR で入れること（S6 の gate が順序ミスを検出）。
- `route:cache` 運用は不変（第 3 段の後付けは従来どおり cache 生成時に焼き込まれる）。
  デプロイ手順の追加要件は**発生しない**。
- デプロイ直後、移行対象 route の bucket は新キーになるため **一度だけカウンタがリセット**される
  （旧キーの残カウントは 1 分で自然消滅）。実害は「デプロイ直後の 1 分だけ枠が復活する」ことのみ。

---

## S5: inline 残置の目録 gate（deny-by-default）

### 変更箇所

- 新規: `app/Enums/Security/InlineThrottleBucketRationale.php`
- 新規: `tests/Architecture/InlineThrottleInventoryTest.php`

### 波及変更

- TypeScript 型定義: なし / API Resource・DTO: なし
- テストファイル: 本施策が新規テスト

### 現行コード

存在しない（inline throttle の残置に対する機械検査は無い）。

### 変更後コード

```php
<?php

declare(strict_types=1);

namespace App\Enums\Security;

/**
 * inline throttle（`throttle:{max},{decay}` / パラメータなし）を持つことが
 * 正しいと裁定された route の分類。
 *
 * `tests/Architecture/InlineThrottleInventoryTest.php` が deny-by-default で
 * 「named limiter へ移すか、本 enum + 具体的根拠付きで目録登録するか」を機械強制する
 * （テストクラスへの {@see} 参照は app → tests の import を生むため書かない）。
 *
 * ★分類は route 単位ではなく **bucket signature の性質**で定義する。
 *   inline のキーは `ThrottleRequests::resolveRequestSignature()` が決め、
 *   認証済みなら user id、未認証なら `{domain}|{ip}` になる。
 *   したがって「その route が inline のときどちらのキーになりうるか」が分類の軸である。
 *
 * ★**自前 route 向けの case は 1 つも定義しない**（意図的）。
 *   自前 route に inline を足すと「当てはまる case が無い」= 目録に登録できず必ず fail する。
 *   これが AGENTS.md ドメイン規約 5「レーンを分けたいときは inline ではなく
 *   named limiter を新設する」の機械化である。
 */
enum InlineThrottleBucketRationale: string
{
    /**
     * session を持たず、キーが常に IP へ倒れる vendor route。
     *
     * 適用条件: 実効 middleware 列に `StartSession` が無く、`$request->user()` が
     * 常に null になる。かつ vendor が throttle をハードコードしており
     * 設定でも `RouteThrottleBinder` でも置換できない
     * （置換しようとすると二重付与になり `ThrottleCoverageInventoryTest` が fail する）。
     */
    case VendorStatelessIpBucket = 'vendor_stateless_ip_bucket';

    /**
     * 認証状態によってキーが user id にも IP にもなりうる vendor route。
     *
     * 適用条件: vendor の controller middleware / package 設定が throttle を決めており、
     * 上書きに vendor 設定ファイル全体の公開が要る（浅い merge により
     * 同一セクションの他キーを巻き添えで失う）。
     * ★**この case の上限は 1**。2 本目が現れたら「認証済み actor の bucket を
     *   2 本の route が共有する」= 本 TODO が潰した障害の再来なので、
     *   named limiter 化か vendor 設定の公開かを必ず再検討すること。
     */
    case VendorMixedUserOrIpBucket = 'vendor_mixed_user_or_ip_bucket';
}
```

```php
<?php

declare(strict_types=1);

use App\Enums\Security\InlineThrottleBucketRationale;
use App\Support\Http\RouteThrottleBinder;
use Illuminate\Routing\Route as RoutingRoute;
use Illuminate\Routing\Router;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;

/*
 * inline throttle の残置 invariant（deny-by-default）。
 *
 * 「inline throttle を持つ route は目録に登録されている」を機械強制する。
 * 未登録は fail = **自前 route へ inline を足せない**（自前向けの enum case が無いため
 * 登録もできない）。これは AGENTS.md ドメイン規約 5 の機械化である。
 *
 * ★責務境界（重複検査を作らない）:
 *   - throttle が 1 本あるか            → ThrottleCoverageInventoryTest
 *   - inline の残置理由と共有上限        → **本テスト**
 *   - named limiter のキー形式と衝突     → RateLimiterKeyConventionTest
 *   - 実 HTTP での巻き添え 429 の消滅    → AuthThrottleCoverageTest
 */

/** inline 指定と判定する params（`{max},{decay}` またはパラメータなし = 既定 60,1）。 */
function inlineThrottleParamsAreInline(string $params): bool
{
    return $params === '' || preg_match('/^\d+,\d+$/', $params) === 1;
}

/** throttle entry（`{class}` or `{class}:{params}`）が inline 指定か。 */
function inlineThrottleEntryIsInline(string $entry): bool
{
    if (! RouteThrottleBinder::isThrottleEntry($entry)) {
        return false;
    }

    return inlineThrottleParamsAreInline(Str::contains($entry, ':') ? Str::after($entry, ':') : '');
}

/** throttle を 1 本以上持つ route の総数の下限（走査の空振り検出。実測 48）。 */
function inlineThrottleThrottledRouteFloor(): int
{
    return 40;
}

/** case 別上限（**現在値ちょうど** = exact fit）。 */
function inlineThrottleRationaleCapByCase(): array
{
    return [
        InlineThrottleBucketRationale::VendorStatelessIpBucket->value => 2,
        // ★1 から上げない。2 本目 = 認証済み actor の bucket 共有の再来。
        InlineThrottleBucketRationale::VendorMixedUserOrIpBucket->value => 1,
    ];
}

/** 根拠文字列の最低文字数。 */
function inlineThrottleReasonMinLength(): int
{
    return 30;
}

/**
 * inline throttle を持つことが正しいと裁定した route の目録。
 *
 * @return array<string, array{InlineThrottleBucketRationale, string}>
 */
function inlineThrottleInventory(): array
{
    $statelessIp = InlineThrottleBucketRationale::VendorStatelessIpBucket;
    $mixed = InlineThrottleBucketRationale::VendorMixedUserOrIpBucket;

    return [
        'passport.token' => [$statelessIp,
            'Laravel\Passport\RouteRegistrar::forAccessTokens() が middleware([\'throttle\']) を'
            .'ハードコードしており、設定でも RouteThrottleBinder でも置換できない'
            .'（後付けすると二重付与になり ThrottleCoverageInventoryTest が fail する）。'
            .'session を持たないため $request->user() は常に null でキーは IP に固定される。'],

        'passport.device.code' => [$statelessIp,
            '上記 passport.token と同じく Passport がハードコードした throttle（既定 60/min）。'
            .'device authorization grant の code 発行 endpoint で session を持たず、'
            .'キーは常に IP。認証済み actor の bucket とは交わらない。'],

        'livewire.upload-file' => [$mixed,
            'Livewire\Features\SupportFileUploads\FileUploadController::middleware() が'
            .'config(\'livewire.temporary_file_upload.middleware\') ?: \'throttle:60,1\' を返す。'
            .'上書きには config/livewire.php の公開が要るが mergeConfigFrom は浅い merge のため'
            .'部分定義では temporary_file_upload 配下の disk/rules/cleanup を巻き添えで失う。'
            .'T125 の移行後はこれが inline を使う唯一の認証済み actor route であり bucket を専有する。'],
    ];
}

/** route の目録キー（名前があれば名前、無ければ `{METHOD} /{uri}`）。 */
function inlineThrottleRouteLabel(RoutingRoute $route): string
{
    $name = $route->getName();
    if ($name !== null && $name !== '') {
        return $name;
    }

    return implode('|', array_values(array_diff($route->methods(), ['HEAD']))).' /'.$route->uri();
}

/** @return array{inline: list<string>, throttled: int} 母集団の走査結果。 */
function inlineThrottleScan(): array
{
    /** @var Router $router */
    $router = Route::getFacadeRoot();
    $inline = [];
    $throttled = 0;

    foreach (Route::getRoutes() as $route) {
        $entries = RouteThrottleBinder::throttleEntries($router, $route);
        if ($entries === []) {
            continue;
        }
        $throttled++;

        foreach ($entries as $entry) {
            if (inlineThrottleEntryIsInline($entry)) {
                $inline[] = inlineThrottleRouteLabel($route);

                break;
            }
        }
    }

    sort($inline);

    return ['inline' => $inline, 'throttled' => $throttled];
}

test('分類器は inline 指定と named 指定を取り違えない（負のコントロール）', function (): void {
    $throttle = 'Illuminate\Routing\Middleware\ThrottleRequests';

    // inline 側
    expect(inlineThrottleEntryIsInline($throttle.':6,1'))->toBeTrue();
    expect(inlineThrottleEntryIsInline($throttle.':60,1'))->toBeTrue();
    expect(inlineThrottleEntryIsInline($throttle))->toBeTrue('パラメータなし throttle は既定 60,1 の inline');
    expect(inlineThrottleEntryIsInline('Illuminate\Routing\Middleware\ThrottleRequestsWithRedis:10,1'))
        ->toBeTrue('redis 実装も ThrottleRequests の派生であり inline 判定の対象');

    // named 側
    expect(inlineThrottleEntryIsInline($throttle.':password-verify'))->toBeFalse();
    expect(inlineThrottleEntryIsInline($throttle.':api-read'))->toBeFalse();

    // throttle ですらない middleware
    expect(inlineThrottleEntryIsInline('Illuminate\Auth\Middleware\Authenticate:web'))->toBeFalse();
});

test('throttle を持つ route の総数が下限を下回らない（走査の空振り検出）', function (): void {
    $scan = inlineThrottleScan();

    expect($scan['throttled'])->toBeGreaterThanOrEqual(
        inlineThrottleThrottledRouteFloor(),
        "throttle を持つ route が {$scan['throttled']} 件しか検出されませんでした。"
        .'middleware 解決が壊れている可能性があります（この場合 inline 母集団も 0 件になり、'
        .'目録検査が空振りで green になってしまう）。',
    );
});

test('inline throttle を持つ route は目録に登録されている（未知は fail）', function (): void {
    $inventory = inlineThrottleInventory();
    $unknown = array_values(array_diff(inlineThrottleScan()['inline'], array_keys($inventory)));

    expect($unknown)->toBe([],
        'inline throttle（`throttle:{max},{decay}`）を持つ route が目録に未登録です。'
        .'inline のキーは actor id だけで route 名も limiter 名も入らないため、'
        .'**同一 actor の全 inline route が 1 bucket を共有します**。'
        .'named limiter を新設してレーンを分けてください'
        .'（自前 route 向けの InlineThrottleBucketRationale case は意図的に存在しません）。'
        .PHP_EOL.implode(PHP_EOL, $unknown));
});

test('目録の key は現存する inline throttle route（stale 検出 / 母集団 0 件の検出）', function (): void {
    $inline = inlineThrottleScan()['inline'];
    $stale = array_values(array_diff(array_keys(inlineThrottleInventory()), $inline));

    expect($stale)->toBe([],
        '目録にあるが inline throttle を持たない route があります（named 化済み・削除済み、'
        .'または母集団の走査が壊れている）。named 化したら目録から消してください。'
        .PHP_EOL.implode(PHP_EOL, $stale));
});

test('目録の値は enum + 実質的な根拠文字列', function (): void {
    $min = inlineThrottleReasonMinLength();
    $violations = [];

    foreach (inlineThrottleInventory() as $label => [$rationale, $reason]) {
        if (! $rationale instanceof InlineThrottleBucketRationale) {
            $violations[] = "{$label}: 第 1 要素が InlineThrottleBucketRationale ではありません";
        }
        if (mb_strlen($reason) < $min) {
            $violations[] = "{$label}: 根拠が {$min} 文字未満です";
        }
    }

    expect($violations)->toBe([], PHP_EOL.implode(PHP_EOL, $violations));
});

test('case 別件数が上限を超えない（enum 全 case を走査。上限未登録も fail）', function (): void {
    $caps = inlineThrottleRationaleCapByCase();

    $counts = [];
    foreach (InlineThrottleBucketRationale::cases() as $case) {
        $counts[$case->value] = 0;
    }
    foreach (inlineThrottleInventory() as [$rationale, $reason]) {
        $counts[$rationale->value]++;
    }

    $violations = [];
    foreach ($counts as $case => $count) {
        if (! array_key_exists($case, $caps)) {
            $violations[] = "{$case}: inlineThrottleRationaleCapByCase() に上限がありません";

            continue;
        }
        if ($count > $caps[$case]) {
            $violations[] = "{$case}: {$count} 件（上限 {$caps[$case]}）";
        }
    }
    foreach (array_keys($caps) as $case) {
        if (! array_key_exists($case, $counts)) {
            $violations[] = "{$case}: enum に存在しない case の上限が残っています";
        }
    }

    expect($violations)->toBe([],
        '上限を上げる前に、その route を named limiter へ移せないかを必ず再検討すること。'
        .PHP_EOL.implode(PHP_EOL, $violations));
});
```

### PHPStan 適合チェック

- [x] 戻り値の型が明示されている（helper 関数すべてに戻り値型 / 配列は phpdoc）
- [x] null 安全（`getName()` の null / `Str::after` の分岐）
- [x] DTO（該当なし。テストと enum）
- [x] Generics（`array<string, array{InlineThrottleBucketRationale, string}>` を phpdoc で明示）

### テスト計画（この施策自体がテスト。**赤化の確認手順**を含む）

- [x] 実装前の main では **12 件の未登録 inline route** で fail する（`recent-auth.password` 等）
      = この gate が「素の main で赤 → 移行後に緑」になる、テストファーストの証拠
- [ ] 移行後の mutation で再度赤化することを確認する（下記「赤化確認手順」§M1・§M2）
- [ ] 負のコントロール（分類器の両方向）は上記 1 本目のテストが担う
- [ ] 母集団 0 件の検出は「stale 検出」テストが担う（母集団が空なら目録 3 件すべてが stale で fail）

### リスク

- `RouteThrottleBinder::throttleEntries()` は `gatherRouteMiddleware()` 経由で
  **controller を container 解決する**。Architecture レーンで全 route に対して実行するため、
  controller の constructor injection が boot 済み container で解決できる必要がある
  （既存 `ThrottleCoverageInventoryTest` が同じ呼び出しを全 route に対して行っており実績がある）。
- vendor の更新で route 名が変われば stale 検出が fail する（意図どおり。名前の追随を強制する）。

---

## S6: レーン割当の目録 gate（相乗り禁止）

### 変更箇所

- 新規: `tests/Architecture/ThrottleLaneAssignmentTest.php`

### 波及変更

- なし（テストのみ）

### 現行コード

存在しない。

### 変更後コード

```php
<?php

declare(strict_types=1);

use App\Support\Http\RouteThrottleBinder;
use Illuminate\Cache\RateLimiter as CacheRateLimiter;
use Illuminate\Routing\Route as RoutingRoute;
use Illuminate\Routing\Router;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;

/*
 * T125 で新設したレーンへの **route 割当** invariant（deny-by-default）。
 *
 * inline から named へ移しただけでは「次に誰かが `throttle:password-verify` を
 * 別 route へ貼る」ことを止められない。描画のたびに飛ぶ GET を照合レーンへ足せば、
 * 分けたばかりのレーンがまた潰れる。**どの route がどのレーンに属するか**を目録で固定する。
 *
 * ★責務境界: 「throttle が 1 本あるか」は ThrottleCoverageInventoryTest、
 *   「キーの形式と衝突」は RateLimiterKeyConventionTest、
 *   「inline の残置」は InlineThrottleInventoryTest。本テストは**割当だけ**を見る。
 */

/**
 * 本設計が所有するレーンと、そこに属してよい route の目録。
 *
 * @return array<string, list<string>> limiter 名 => route 名（ソート済みで比較する）
 */
function throttleLaneAssignments(): array
{
    return [
        // パスワード**照合**の試行予算（1 つの秘密を 3 面で合算 6/min）
        'password-verify' => [
            'password.confirm.store',
            'recent-auth.password',
            'user-password.update',
        ],
        // パスワードの初回設定（照合を伴わない credential mutation）
        'password-set' => [
            'settings.password.store',
        ],
        // メール検証フロー（Fortify の 1 knob が 2 route に貼る）
        'email-verification' => [
            'verification.send',
            'verification.verify',
        ],
        // 2FA 設定フローの操作予算
        'two-factor-manage' => [
            'two-factor.confirm',
            'two-factor.disable',
            'two-factor.enable',
            'two-factor.regenerate-recovery-codes',
        ],
        // 招待受諾の確定（未認証 GET の invitation-accept とは別レーン）
        'invitation-accept-submit' => [
            'invitations.accept.store',
        ],
        // パーソナルプランの有効化
        'plan-activate' => [
            'onboarding.activate-personal',
        ],
    ];
}

/** route の目録キー。 */
function throttleLaneRouteLabel(RoutingRoute $route): string
{
    $name = $route->getName();
    if ($name !== null && $name !== '') {
        return $name;
    }

    return implode('|', array_values(array_diff($route->methods(), ['HEAD']))).' /'.$route->uri();
}

/**
 * 実際の route 群から「本設計が所有するレーン」への割当を収集する。
 *
 * @return array<string, list<string>>
 */
function throttleLaneActualAssignments(): array
{
    /** @var Router $router */
    $router = Route::getFacadeRoot();
    $owned = array_keys(throttleLaneAssignments());
    $actual = [];

    foreach (Route::getRoutes() as $route) {
        foreach (RouteThrottleBinder::throttleEntries($router, $route) as $entry) {
            $params = Str::contains($entry, ':') ? Str::after($entry, ':') : '';
            if (! in_array($params, $owned, true)) {
                continue;
            }
            $actual[$params][] = throttleLaneRouteLabel($route);
        }
    }

    foreach ($actual as $lane => $labels) {
        $unique = array_values(array_unique($labels));
        sort($unique);
        $actual[$lane] = $unique;
    }

    return $actual;
}

test('新レーンの route 割当が目録と完全一致する（未宣言の相乗りも stale も fail）', function (): void {
    $expected = throttleLaneAssignments();
    foreach ($expected as $lane => $labels) {
        sort($labels);
        $expected[$lane] = $labels;
    }
    ksort($expected);

    $actual = throttleLaneActualAssignments();
    ksort($actual);

    expect($actual)->toBe($expected,
        'レーンへの route 割当が宣言と食い違っています。'
        .'レーンは「何を数えるか」の単位です。新しい route を既存レーンへ相乗りさせる前に、'
        .'そのレーンの予算をその route と分け合ってよいかを必ず再検討してください'
        .'（描画のたびに飛ぶ GET を照合レーンへ足すと再認証が壊れます）。');
});

test('目録のレーンはすべて 1 本以上の route を持つ（空振り検出）', function (): void {
    $actual = throttleLaneActualAssignments();
    $empty = [];

    foreach (array_keys(throttleLaneAssignments()) as $lane) {
        if (($actual[$lane] ?? []) === []) {
            $empty[] = $lane;
        }
    }

    expect($empty)->toBe([],
        'route が 1 本も割り当てられていないレーンがあります'
        .'（limiter だけ残った / 割当が外れた / 走査が壊れた）: '.implode(', ', $empty));
});

test('目録のレーン名はすべて実在する named limiter である（typo 検出）', function (): void {
    $limiter = app(CacheRateLimiter::class);
    $missing = [];

    foreach (array_keys(throttleLaneAssignments()) as $lane) {
        if ($limiter->limiter($lane) === null) {
            $missing[] = $lane;
        }
    }

    expect($missing)->toBe([],
        'named limiter が未登録のレーン名が route に貼られています'
        .'（リクエスト時に MissingRateLimiterException になります）: '.implode(', ', $missing));
});
```

### PHPStan 適合チェック

- [x] 戻り値の型（全 helper に型 + `array<string, list<string>>` の phpdoc）
- [x] null 安全（`getName()` / `limiter()` の null 判定）
- [x] DTO（該当なし） / [x] Generics（phpdoc で明示）

### テスト計画（赤化の確認）

- [x] 実装前の main では 6 レーンとも route 0 本 → 「空振り検出」テストが fail（= 素の main で赤）
- [ ] mutation §M3（1 route を別レーンへ移す）で「完全一致」テストが赤化することを確認
- [ ] mutation §M4（レーン名を typo にする）で「typo 検出」テストが赤化することを確認

### リスク

- レーンを増やす設計変更のたびに目録更新が要る（意図どおりの摩擦）。

---

## S7: キー規約検査の拡張

### 変更箇所

- `tests/Architecture/RateLimiterKeyConventionTest.php`
  - `rateLimiterKeyInventory()` に 6 レーンを追加
  - 共有グループ目録 `rateLimiterSharedKeyGroups()` を追加
  - 「limiter 間でキーが衝突しない」テストを追加

### 波及変更

- なし（テストのみ）。ただし **S2/S3 を入れずにこのテストだけを入れると
  「scan で検出した limiter 名の集合が inventory と完全一致する」が fail する**（順序依存を明記）。

### 現行コード

```php
        'invitation-accept' => [
            'scenarios' => ['guest' => $noEmail],
            'expectedKeyPrefixes' => ['invitation-accept:ip'],
            'emailScenarios' => [],
        ],
```
（衝突検査は存在しない。`api-read` / `api-write` / `api-status` が同一キーを produce している事実は
どこにも記録されていない。）

### 変更後コード

```php
    // ── T125: inline から移行したレーン群 ──────────────────────────────
    // いずれも「認証済みは actor / 未認証は IP」の 2 分岐（passkeys と同形）。
    // throttle は route によっては auth より後に走る（現行 priority list では後）ため、
    // guest 分岐は防御的な冗長だが、closure 単体としては両分岐が実在する。
    foreach ([
        'password-verify',
        'password-set',
        'email-verification',
        'two-factor-manage',
        'invitation-accept-submit',
        'plan-activate',
    ] as $lane) {
        $inventory[$lane] = [
            'scenarios' => [
                'authenticated' => static fn (): Request => rateLimiterAuthenticatedRequest(rateLimiterProbeUser()),
                'guest' => $noEmail,
            ],
            'expectedKeyPrefixes' => [$lane.':user', $lane.':ip'],
            'emailScenarios' => [],
        ];
    }
```

> `RateLimiter::for()` の第 1 引数は**リテラル必須**だが、それは `app/` 側の登録の話であり、
> テスト側の inventory 構築はループでよい（scanner は `app/` しか走査しない）。

```php
/**
 * 意図的に同一キーを共有している limiter の組（それ以外は pairwise disjoint であること）。
 *
 * ★レーンを分ける = **bucket が実際に分かれる**ことであり、
 *   キー接頭辞の宣言が違っても produce されるキーが同じなら分かれていない。
 *   ここに載っていない組が衝突したら、それは「レーンを分けたつもりで分かれていない」バグである。
 *
 * @return array<string, array{limiters: list<string>, reason: string}>
 */
function rateLimiterSharedKeyGroups(): array
{
    return [
        'api-actor' => [
            'limiters' => ['api-read', 'api-write', 'api-status'],
            'reason' => '3 本とも apiRateKey() を返し、1 クライアントの read / write / status を'
                .'1 つの bucket で数える現行仕様（実効上限は最小の api-status = 30/min に律速する）。'
                .'分離は 1 クライアントの総量上限を実質 120/min から 210/min へ**緩める**変更であり、'
                .'API の abuse 耐性の判断を伴うため T125 では挙動を変えず、事実の記録のみ行う。',
        ],
    ];
}

/** limiter が produce するキー文字列の集合（全 scenario 合算）。 */
function rateLimiterProducedKeys(string $name, array $spec): array
{
    $keys = [];
    foreach ($spec['scenarios'] as $build) {
        foreach (rateLimiterProduceLimits($name, $build()) as $limit) {
            $keys[(string) $limit->key] = true;
        }
    }

    return array_keys($keys);
}

test('共有グループの宣言は実在する limiter を 2 本以上指す', function (): void {
    $known = array_keys(rateLimiterKeyInventory());
    $violations = [];

    foreach (rateLimiterSharedKeyGroups() as $group => $spec) {
        if (count($spec['limiters']) < 2) {
            $violations[] = "{$group}: 共有グループは 2 本以上でなければ意味がありません";
        }
        if (mb_strlen($spec['reason']) < 30) {
            $violations[] = "{$group}: 根拠が 30 文字未満です";
        }
        foreach ($spec['limiters'] as $limiter) {
            if (! in_array($limiter, $known, true)) {
                $violations[] = "{$group}: 未知の limiter [{$limiter}]";
            }
        }
    }

    expect($violations)->toBe([], PHP_EOL.implode(PHP_EOL, $violations));
});

test('宣言した共有グループは実際にキーを共有している（死んだ宣言の検出）', function (): void {
    // ★グループが実際には衝突していないなら、その宣言は「もう不要な免除」である。
    //   残すと次に読む人へ嘘を伝え、かつ本物の衝突を隠す枠になる。
    $inventory = rateLimiterKeyInventory();
    $violations = [];

    foreach (rateLimiterSharedKeyGroups() as $group => $spec) {
        $sets = [];
        foreach ($spec['limiters'] as $limiter) {
            $sets[$limiter] = rateLimiterProducedKeys($limiter, $inventory[$limiter]);
        }

        foreach ($spec['limiters'] as $limiter) {
            $others = array_merge(...array_values(array_diff_key($sets, [$limiter => true])));
            if (array_intersect($sets[$limiter], $others) === []) {
                $violations[] = "{$group}/{$limiter}: 他のメンバーとキーを共有していません（宣言が古い）";
            }
        }
    }

    expect($violations)->toBe([], PHP_EOL.implode(PHP_EOL, $violations));
});

test('共有グループ外の limiter は互いにキーを共有しない（レーン分離の実証）', function (): void {
    $inventory = rateLimiterKeyInventory();

    // 同一グループのペアだけを許可集合にする
    $allowed = [];
    foreach (rateLimiterSharedKeyGroups() as $spec) {
        foreach ($spec['limiters'] as $a) {
            foreach ($spec['limiters'] as $b) {
                $allowed[$a.'|'.$b] = true;
            }
        }
    }

    $keys = [];
    foreach ($inventory as $name => $spec) {
        $keys[$name] = rateLimiterProducedKeys($name, $spec);
    }

    $names = array_keys($inventory);
    $violations = [];
    foreach ($names as $i => $a) {
        foreach (array_slice($names, $i + 1) as $b) {
            if (isset($allowed[$a.'|'.$b])) {
                continue;
            }
            $shared = array_intersect($keys[$a], $keys[$b]);
            if ($shared !== []) {
                $violations[] = "{$a} と {$b} が同じキーを produce しています: ".implode(', ', $shared);
            }
        }
    }

    expect($violations)->toBe([],
        'レーンを分けたつもりで bucket が分かれていません。'
        .'キーの接頭辞にレーン名が入っているか確認してください'
        .'（意図的な共有なら rateLimiterSharedKeyGroups() へ根拠付きで登録すること）。'
        .PHP_EOL.implode(PHP_EOL, $violations));
});
```

### PHPStan 適合チェック

- [x] 戻り値の型（helper に型 + phpdoc）
- [x] null 安全（`$limit->key` は `string|Closure` になりうるため既存同様 `(string)` でキャスト）
- [x] DTO（該当なし） / [x] Generics（phpdoc）

### テスト計画（赤化の確認）

- [x] S2/S3 未実装の状態では「scan で検出した limiter 名の集合が inventory と完全一致する」が fail（素の main で赤）
- [ ] mutation §M5（`password-set` のキーを `password-verify:...` にする）で
      「共有グループ外の limiter は互いにキーを共有しない」が赤化
- [ ] mutation §M6（`api-write` を共有グループ宣言から外す）で同テストが赤化、
      逆に `apiRateKey()` を limiter ごとに変えると「死んだ宣言の検出」が赤化

### リスク

- 衝突検査は **inventory の scenario で produce したキーだけ**を見る。
  scenario に無い分岐（例: `api-*` の oauth-user 経路）の衝突は検出できない。
  これは既存の `expectedKeyPrefixes` と同じ制約であり、**保証範囲として誇張しない**
  （テスト冒頭のコメントに明記する）。

---

## S8: behavioral proof（レーン独立 + 上限維持 + 実効順）

### 変更箇所

- `tests/Feature/Security/AuthThrottleCoverageTest.php` の末尾に「T125」セクションを追加

### 波及変更

- なし（テストのみ）

### 現行コード

同ファイルの T121 セクション末尾に
「2FA 秘密 GET のレーンは独立している — 10 回踏んでも recent-auth / 2FA 管理 POST が 429 にならない」
がある（本施策はこれと同じ形式を横展開する）。

### 変更後コード（追加するテスト）

```php
/*
 |--------------------------------------------------------------------------
 | T125: inline throttle から移した 6 レーンの独立性（behavioral proof）
 |--------------------------------------------------------------------------
 |
 | 目録検査（InlineThrottleInventoryTest / ThrottleLaneAssignmentTest）は
 | 「どう貼られているか」しか見ない。**あるレーンを使い切ったとき別レーンが生きているか**は
 | 実挙動でしか固定できない。inline へ戻す変更を入れたらここが必ず落ちる。
 |
 | cache store はテスト実行時 array に強制されている（phpunit.xml）ため、
 | app を作り直す各テストで RateLimiter のバケットは空から始まる。
 */

test('Livewire アップロードのレーンは再認証を巻き添えにしない（max 60 が max 6 を殺さない）', function (): void {
    // ★本 TODO の中心的な回帰。inline のままだと livewire.upload-file（max 60）の
    //   6 回目で共有カウンタが 6 に達し、recent-auth.password（max 6）が 429 になる。
    $user = User::factory()->create();
    $this->actingAs($user);

    for ($i = 1; $i <= 6; $i++) {
        // 署名なしのため 401 で弾かれるが、throttle は controller より前で数える
        expect($this->post(route('livewire.upload-file'))->getStatusCode())
            ->not->toBe(429, "{$i} 回目で既に 429 になりました");
    }

    expect($this->post('/recent-auth/password', ['password' => 'wrong-password'])->getStatusCode())
        ->not->toBe(429, '再認証がファイルアップロードの巻き添えで 429 になりました');
});

test('2FA 管理レーンを使い切っても再認証・パスワード設定・メール検証は 429 にならない', function (): void {
    Notification::fake();
    $user = User::factory()->create();
    $this->actingAs($user);

    for ($i = 1; $i <= 10; $i++) {
        expect($this->post('/user/two-factor-authentication')->getStatusCode())
            ->not->toBe(429, "{$i} 回目で既に 429 になりました");
    }
    expect($this->post('/user/two-factor-authentication')->getStatusCode())
        ->toBe(429, '2FA 管理レーンの上限 10/min が維持されていません');

    expect($this->post('/recent-auth/password', ['password' => 'wrong-password'])->getStatusCode())
        ->not->toBe(429, '再認証が 2FA 管理の巻き添えで 429 になりました');
    expect($this->post('/settings/password', ['password' => 'short'])->getStatusCode())
        ->not->toBe(429, 'パスワード初回設定が 2FA 管理の巻き添えで 429 になりました');
    expect($this->post('/email/verification-notification')->getStatusCode())
        ->not->toBe(429, '認証メール再送が 2FA 管理の巻き添えで 429 になりました');
});

test('パスワード照合レーンを使い切っても初回設定・2FA 管理・メール検証は 429 にならない', function (): void {
    Notification::fake();
    $user = User::factory()->create();
    $this->actingAs($user);

    for ($i = 1; $i <= 6; $i++) {
        expect($this->post('/recent-auth/password', ['password' => 'wrong-password'])->getStatusCode())
            ->not->toBe(429, "{$i} 回目で既に 429 になりました");
    }
    expect($this->post('/recent-auth/password', ['password' => 'wrong-password'])->getStatusCode())
        ->toBe(429, 'パスワード照合レーンの上限 6/min が維持されていません');

    // ★照合と初回設定を分けた根拠そのもの（同レーンだとここが 429 になる）
    expect($this->post('/settings/password', ['password' => 'short'])->getStatusCode())
        ->not->toBe(429, 'パスワード初回設定が照合レーンの巻き添えで 429 になりました');
    expect($this->post('/user/two-factor-authentication')->getStatusCode())
        ->not->toBe(429, '2FA 管理が照合レーンの巻き添えで 429 になりました');
    expect($this->post('/email/verification-notification')->getStatusCode())
        ->not->toBe(429, '認証メール再送が照合レーンの巻き添えで 429 になりました');
});

test('パスワード照合面 3 本は 1 つのレーンを共有する（1 つの秘密の試行予算）', function (): void {
    // ★分けてはいけない結合の固定。3 面が別 bucket になると同じパスワードを 18 回/min
    //   試せることになり、総当り耐性が現状より下がる。
    $user = User::factory()->create();
    $this->actingAs($user);

    $probes = [
        fn () => $this->post('/recent-auth/password', ['password' => 'wrong-password']),
        fn () => $this->post('/user/confirm-password', ['password' => 'wrong-password']),
        fn () => $this->put('/user/password', ['current_password' => 'wrong', 'password' => 'NewPassw0rd!', 'password_confirmation' => 'NewPassw0rd!']),
    ];

    $previous = null;
    foreach ($probes as $probe) {
        $remaining = $probe()->headers->get('X-RateLimit-Remaining');
        expect($remaining)->not->toBeNull('throttle が付いていません');
        if ($previous !== null) {
            expect((int) $remaining)->toBe($previous - 1, 'パスワード照合面が別 bucket へ分かれています');
        }
        $previous = (int) $remaining;
    }
});

test('メール検証レーンは 6/min で、使い切っても再認証は 429 にならない', function (): void {
    Notification::fake();
    $user = User::factory()->unverified()->create();
    $this->actingAs($user);

    for ($i = 1; $i <= 6; $i++) {
        expect($this->post('/email/verification-notification')->getStatusCode())
            ->not->toBe(429, "{$i} 回目で既に 429 になりました");
    }
    expect($this->post('/email/verification-notification')->getStatusCode())->toBe(429);

    expect($this->post('/recent-auth/password', ['password' => 'wrong-password'])->getStatusCode())
        ->not->toBe(429, '再認証がメール再送の巻き添えで 429 になりました');
});

test('招待受諾 POST は 10/min で、確認画面 GET とは別 bucket である', function (): void {
    // GET 側 invitation-accept は未認証 IP レーン（10/min）。同一 bucket だと
    // 「リンクを開き直したら受諾できない」という詰みになる。
    $user = User::factory()->create();
    $this->actingAs($user);

    for ($i = 1; $i <= 10; $i++) {
        expect($this->get('/invitations/accept?token=invalid-token')->getStatusCode())
            ->not->toBe(429, "GET {$i} 回目で既に 429 になりました");
    }
    expect($this->get('/invitations/accept?token=invalid-token')->getStatusCode())->toBe(429);

    expect($this->post('/invitations/accept', ['token' => 'invalid-token'])->getStatusCode())
        ->not->toBe(429, '受諾 POST が確認画面 GET の巻き添えで 429 になりました');
});

test('プラン有効化レーンを使い切っても再認証は 429 にならない', function (): void {
    [, $owner] = createOrganizationWithOwner(grandfatherFreePlan: false);
    $this->actingAs($owner);

    for ($i = 1; $i <= 10; $i++) {
        expect($this->post('/onboarding/activate-personal', activatePersonalPayload())->getStatusCode())
            ->not->toBe(429, "{$i} 回目で既に 429 になりました");
    }
    expect($this->post('/onboarding/activate-personal', activatePersonalPayload())->getStatusCode())->toBe(429);

    expect($this->post('/recent-auth/password', ['password' => 'wrong-password'])->getStatusCode())
        ->not->toBe(429, '再認証がプラン有効化の巻き添えで 429 になりました');
});

test('認証は throttle より先に走る（レーンの guest 分岐が防御的冗長であることの前提固定）', function (): void {
    // ★limiter の IP 分岐は「auth を持たない route でも同じ helper が使える」ための冗長であり、
    //   auth 必須 route では通らない。この前提が変わったら（priority list を触ったら）
    //   ここが落ちて、IP 分岐が実運用に載ることに気づける。
    $resolved = throttleProbeResolvedClasses('recent-auth.password');

    $authIndex = array_search(Authenticate::class, $resolved, true);
    $throttleIndex = array_search(ThrottleRequests::class, $resolved, true);

    expect($authIndex)->not->toBeFalse('Authenticate が実効列に無い');
    expect($throttleIndex)->not->toBeFalse('ThrottleRequests が実効列に無い');
    expect($authIndex)->toBeLessThan($throttleIndex);
});
```

> 補助関数 `createOrganizationWithOwner()` / `activatePersonalPayload()` は
> `tests/Feature/Onboarding/ActivatePersonalTest.php` にローカル定義されているため、
> **プラン有効化のテストだけは同ファイル側へ置く**（Pest のグローバル関数汚染を増やさない）。
> それ以外は `AuthThrottleCoverageTest` に置く。
> `Notification::fake()` / `Authenticate` / `Notification` の `use` を同ファイルへ追加する。

### PHPStan 適合チェック

- [x] 戻り値の型（クロージャに型不要な箇所を除き既存流儀に合わせる）
- [x] null 安全（`headers->get()` の null を `not->toBeNull()` で先に潰す）
- [x] DTO（該当なし） / [x] Generics（該当なし）

### テスト計画 / 実装前に赤であること（テストファースト）

**先にこのテストを書き、現行 main で赤になることを確認してから S1〜S4 を実装する**。
現行 main での期待結果:

| テスト | 現行 main | 理由 |
|---|---|---|
| Livewire アップロード → 再認証 | **赤** | 6 回で共有カウンタ 6 → `recent-auth.password` が 429 |
| 2FA 管理 → 再認証 / 初回設定 / メール再送 | **赤** | counter 10 で max 6 の 3 本すべて 429 |
| 照合レーン → 初回設定 / メール再送 | **赤** | counter 7 で max 6 の 2 本が 429（2FA 管理は max 10 なので緑） |
| 照合面 3 本が同レーン | 緑 | 現行も同一 bucket。**将来の分割**を止めるための固定 |
| メール検証 → 再認証 | **赤** | counter 7 で `recent-auth.password` が 429 |
| 招待 GET → POST | 緑 | GET は既に named limiter |
| プラン有効化 → 再認証 | **赤** | counter 11 で `recent-auth.password` が 429 |
| 認証は throttle より先 | 緑 | framework 既定 priority list の前提固定 |

- [ ] 個別の `DatabaseTransactions` を使っていない（`RefreshDatabase` グローバル適用のまま）
- [ ] テストデータは Factory（`User::factory()` / `createOrganizationWithOwner()`）

### リスク

- `livewire.upload-file` の URI はハッシュ付き（`livewire-18f43797/upload-file`）のため
  **必ず `route('livewire.upload-file')` で解決する**（ハードコードするとハッシュ変更で壊れる）。
- `--parallel` 実行でも RateLimiter は array store かつ actor id ごとにキーが分かれるため
  テスト間干渉は起きない（既存 T121 テストと同じ前提）。

---

## S9: 既存テスト・ドキュメント・docblock の追随

### 変更箇所と内容

| ファイル | 変更 |
|---|---|
| `tests/Feature/Onboarding/ActivatePersonalTest.php` L188 | テスト名 `throttle:10,1 が効く (11 回目は 429)` → `plan-activate レーンが効く (11 回目は 429)`。挙動は不変 |
| `tests/Feature/Settings/PasswordSetupTest.php` L175 | テスト名 `throttle 超過で 429 (6/分)` → `password-set レーンの超過で 429 (6/分)`。併せて「照合レーンとは別 bucket」であることをコメントで示す |
| `tests/Feature/Auth/FortifyResponseTest.php` L44 / L66 | コメント中の `throttle:6,1` → `throttle:email-verification`（`config fortify.limiters.verification` 経由であることも正しくなる） |
| `tests/Architecture/ControllerAuthorizationGateTest.php` L102 / L107 | exemption 理由中の「総当り防御は throttle:6,1」→ `settings.password.store` は `throttle:password-set`、`recent-auth.password` は `throttle:password-verify` |
| `tests/Architecture/ThrottleCoverageInventoryTest.php` L203 / L213 | exemption 理由中の `(throttle:6,1)` → `(throttle:password-verify)` / `(throttle:email-verification)`。文字数下限 30 は維持される |
| `app/Providers/FortifyServiceProvider.php` `two-factor-secret-read` docblock | 「throttle は auth middleware より先に走る (priority list) ため未認証でも closure が評価される」を**事実に合わせて訂正**（framework 既定の priority list は `AuthenticatesRequests` → `ThrottleRequests` であり auth が先。IP 分岐は「auth を持たない route でも同じ helper を使える」ための冗長である、と書き換える）。前提は S8 の実効順テストが固定する |
| `AGENTS.md` ドメイン固有規約 5 | inline の記述を「移行済み」の状態へ更新。**新規の自前 route は named limiter 必須**であり `InlineThrottleInventoryTest` が deny-by-default で強制すること、残る inline は vendor 3 本のみであること、レーン割当は `ThrottleLaneAssignmentTest` が固定することを追記 |
| `docs/app-integration-guide.md` §7b | 同上。「inline を使ってよいのは…」の段落を「inline は vendor 由来の 3 本のみが目録登録済みで、**自前 route では使えない**」に改め、6 レーンの一覧表と「レーンの切り方の 2 基準」（credential 単位の試行予算 / feature 単位の操作予算）を追加。`AuthThrottleCoverageTest` の T125 セクションを恒久回帰として参照 |

### 波及変更

- TypeScript 型定義: なし / API Resource・DTO: なし
- ドキュメント同期テスト: `AGENTS.md` の変更は `VERIFICATION_COMMANDS` マーカー外のため
  `tests/js/architecture/verification-commands-doc-sync.test.ts` に影響しない

### PHPStan 適合チェック

- 該当なし（文言のみ。`FortifyServiceProvider` の docblock 修正はコメント）

### テスト計画

- [ ] `composer test` 全体 green（文言変更が exemption の文字数下限を割らないことを含む）
- [ ] `vendor/bin/pint --test` / `composer phpstan` green

### リスク

- ドキュメントを更新し忘れると「規約と実装が食い違う」状態になる（本 TODO が是正した状態そのものを再生産する）。
  S9 は**必須施策**として扱い、S1〜S8 と同一 PR で入れる。

---

## gate の赤化確認手順（mutation）

新設 gate のうち **S5・S6 は移行後の main では緑**になる（S7 の追加分の一部と S8 も同様）。
「素の main では赤にならない gate」をそのまま受け入れないため、
実装ブランチ上で以下の mutation を 1 つずつ当て、**期待するテストだけが赤になること**を確認し、
確認結果を `devnotes/20260807-2032-todo-T125-design/gate-mutation-log.md` に記録してから revert する。

| # | mutation | 期待する赤 | 期待するメッセージの要点 |
|---|---|---|---|
| M1 | `routes/web.php` の `recent-auth.password` を `throttle:6,1` に戻す | `InlineThrottleInventoryTest`「inline throttle を持つ route は目録に登録されている」 | `recent-auth.password` が未登録として列挙される |
| M2 | `inlineThrottleInventory()` から `livewire.upload-file` を消す | 同上（未登録 1 件） | `livewire.upload-file` が列挙される |
| M2' | `inlineThrottleInventory()` に架空の route を 1 件足す | 「目録の key は現存する inline throttle route」 | stale として列挙される |
| M2'' | `InlineThrottleBucketRationale` に case を 1 つ足して cap を登録しない | 「case 別件数が上限を超えない」 | 「上限がありません」 |
| M3 | `routes/web.php` の `settings.password.store` を `throttle:password-verify` に変える | `ThrottleLaneAssignmentTest`「割当が目録と完全一致する」 | `password-verify` に 4 本 / `password-set` に 0 本の差分 |
| M4 | 同 route を `throttle:password-sett`（typo）に変える | 同テスト「レーン名はすべて実在する named limiter」 | `password-sett` が missing |
| M5 | `password-set` limiter のキーを `RateLimiterKeys::actorOrIp($request, 'password-verify')` にする | `RateLimiterKeyConventionTest`「共有グループ外の limiter は互いにキーを共有しない」＋「expectedKeyPrefixes と完全一致」 | 2 limiter 名と共有キーが出る |
| M6 | `rateLimiterSharedKeyGroups()` から `api-write` を外す | 同「共有グループ外の limiter は互いにキーを共有しない」 | `api-read` と `api-write` の衝突 |
| M6' | `apiRateKey()` を limiter ごとに違う値にする（一時的に `api-write` だけ別キー） | 同「宣言した共有グループは実際にキーを共有している」 | 死んだ宣言として列挙 |
| M7 | `RateLimiterKeys::actorOrIp()` の user 分岐を `is_scalar()` に戻す | `RateLimiterKeysTest`「bool / float のとき user 分岐へ落ちない」 | 負のコントロールが赤 |
| M8 | `two-factor-manage` を `throttle:10,1` に戻す（binder の値） | `AuthThrottleCoverageTest`「2FA 管理レーンを使い切っても…」＋ `InlineThrottleInventoryTest` | 巻き添え 429 が復活 |

## 検査が空振りしないことの保証（まとめ）

| 保証 | 手段 |
|---|---|
| 分類器が inline / named を取り違えない | S5 の**負のコントロール**（両方向 6 ケース、`ThrottleRequestsWithRedis` を含む） |
| 母集団の走査が壊れて 0 件になっていない | S5「throttle を持つ route の総数が下限 40 を下回らない」＋「目録 key が現存する（0 件なら全 stale で fail）」 |
| レーンが宣言だけで実体が無い状態でない | S6「目録のレーンはすべて 1 本以上の route を持つ」 |
| レーン名の typo が本番まで届かない | S6「レーン名はすべて実在する named limiter」 |
| 免除枠が形骸化しない | S5 の case 別 cap が **exact fit**（`VendorMixedUserOrIpBucket` = 1 / `VendorStatelessIpBucket` = 2） |
| 「分けたつもりで分かれていない」を検出 | S7 の pairwise 衝突検査＋共有グループの**死んだ宣言**検出 |
| キー helper の移行でキーが変わっていない | S1 は既存の exact-fit `expectedKeyPrefixes` 検査を**無変更で**通す |
| 実挙動としての巻き添え消滅 | S8（実装前に赤であることを確認してから実装する） |

## 実装順序（テストファースト）

1. **S8 を先に書き、現行 main で赤になることを確認**（赤の一覧は S8 のテスト計画表）。観測結果を
   `devnotes/20260807-2032-todo-T125-design/step1-fail-observation.md` に記録する
2. S1（helper）→ 既存 `RateLimiterKeyConventionTest` が無変更で green であることを確認
3. S2・S3（limiter 登録）→ この時点で `RateLimiterKeyConventionTest` の
   「scan と inventory の完全一致」が赤になる（登録した limiter が inventory に無いため）
4. S7（キー検証シナリオ 6 レーン + 衝突検査 + 共有グループ）→ green へ戻す
5. S4（route への適用）→ S8 が green になる
6. S5・S6（目録 gate）→ 実装後の状態で green
7. S9（既存テスト・ドキュメント追随）
8. mutation 確認（M1〜M8）→ `gate-mutation-log.md` に記録して revert
9. `composer test` / `composer phpstan` / `vendor/bin/pint --test` / `pnpm lint` / `pnpm typecheck` を green に

## 実装モード

| 項目 | 内容 |
|------|------|
| 推奨モード | **standalone** |
| 判断根拠 | (1) `routes/web.php` / `AppServiceProvider` / `FortifyServiceProvider` / `config/fortify.php` という**他の TODO と衝突しやすい中心ファイル**を同時に触る。(2) テストファースト（先に赤を観測してから実装）の手順が incremental だと成立しない。(3) 既存 gate（`RateLimiterKeyConventionTest`）が S2→S7 の間で一時的に赤になるため、他施策と混ぜると原因の切り分けができない |
| 競合リスク | T124（2FA 秘密 GET の recent-auth 化）は `FortifyServiceProvider` の recent-auth 配線と `routes/web.php` を触るため**行レベルで競合しうる**。T124 と本 TODO は同時に走らせず、どちらかを先にマージしてから rebase する。`throttle` 指定そのものは T124 の対象外（T124 は middleware `recent-auth` の付与）なので、意味的な競合は無い |
| デプロイ順序の要件 | 無し（`route:cache` の毎デプロイ再生成という既存要件のみ。新しい環境変数・マイグレーション・設定の事前投入は不要） |


---

## 関連する現行コード（変更対象および前提となる実装の抜粋）

```php
### routes/web.php L186-205
    */
    Route::get('/recent-auth/confirm', [ConfirmRecentAuthController::class, 'show'])
        ->name('recent-auth.confirm');
    // クライアント主導 step-up の precheck (XHR, no-store)
    Route::get('/recent-auth/status', [ConfirmRecentAuthController::class, 'status'])
        ->name('recent-auth.status');
    Route::post('/recent-auth/password', [ConfirmRecentAuthController::class, 'confirmPassword'])
        ->middleware('throttle:6,1')
        ->name('recent-auth.password');

    Route::get('/settings', [ProfileController::class, 'index'])->name('settings');

    // パスワード**初回設定** (password 未設定ユーザー専用)。認証手段を増やす操作のため
    // step-up (recent-auth) 必須。変更 (current_password 必須) は Fortify の PUT /user/password。
    // EnsureLoginMethodRemains は付けない (手段を減らす操作の関門であり方向が逆)。
    Route::post('/settings/password', [PasswordSetupController::class, 'store'])
        ->middleware(['recent-auth', 'throttle:6,1'])
        ->name('settings.password.store');

    // 2FA / ソーシャル連携 / パスキーの管理面 (passkey 一覧の組み立てに DI が要るため Controller)

### routes/web.php L380-388
    Route::get('/onboarding/checkout', [OnboardingController::class, 'show'])
        ->name('onboarding.checkout');
    // Personal (free) の有効化 (Stripe checkout を通らない。自己申告チェック必須)
    Route::post('/onboarding/activate-personal', ActivatePersonalController::class)
        ->middleware('throttle:10,1')
        ->name('onboarding.activate-personal');
    Route::get('/billing-required', [BillingRequiredController::class, 'show'])
        ->name('onboarding.billing-required');


### routes/web.php L606-620
// GET も token を sha256 照合して DB を 1 件引き、有効/無効で応答が分岐する
// (未認証で観測できる分、姉妹の POST より攻撃面として広い)。
// POST 側の `10,1` と同値にする。未認証面のため named limiter でキーを明示する。
Route::get('/invitations/accept', [InvitationAcceptanceController::class, 'show'])
    ->middleware('throttle:invitation-accept')
    ->name('invitations.accept');
// 招待トークンは hash 照合されるが、総当り試行そのものを有界にする
// (onboarding.activate-personal と同値 = 認証済みの一回性操作)。
Route::post('/invitations/accept', [InvitationAcceptanceController::class, 'store'])
    ->middleware(['auth', 'throttle:10,1'])
    ->name('invitations.accept.store');

/*
|--------------------------------------------------------------------------
| local 専用デバッグログイン

### config/fortify.php L110-128
    |
    | By default, Fortify will throttle logins to five requests per minute for
    | every email and IP address combination. However, if you would like to
    | specify a custom rate limiter to call then you may specify it here.
    |
    */

    'limiters' => [
        'login' => 'login',
        'two-factor' => 'two-factor',
        // passkey endpoint の絞り。**未設定だと FortifyServiceProvider::passkeyThrottleMiddleware()
        // が null を返し、未認証の GET /passkeys/login/options が無制限**になる
        // (毎回 random_bytes(32) + session 書き込みが走る)。
        // limiter 本体は App\Providers\FortifyServiceProvider::configureRateLimiters()。
        'passkeys' => 'passkeys',
    ],

    /*
    |--------------------------------------------------------------------------

### app/Providers/FortifyServiceProvider.php L140-205 (throttledFortifyRoutes / attach)
     *  - `10,1` は onboarding.activate-personal と同値 (認証済みの管理操作)。
     *
     * ★inline (`6,1` / `10,1`) を使ってよいのは **認証済みかつ actor 自身に閉じる route** だけ。
     *   未認証面 / 主体が IP や email になる面は必ず named limiter を作ること。
     *   **さらに注意**: inline のキーは `sha1(user id)` だけで route も limiter 名も入らないため、
     *   **同一 actor の全 inline throttle route が 1 bucket を共有する**
     *   (ThrottleRequests::handle() の $prefix 既定 '' + resolveRequestSignature())。
     *   したがって inline は「その actor の全 inline 操作を合算して数えてよい」場合に限る。
     *   ページ描画のたびに飛ぶような高頻度レーンを inline で足すと、
     *   合算値が最小 max (recent-auth.password = 6) を先に食い潰して再認証を壊す。
     *   そういう面は named limiter でレーンを分ける (下記 two-factor-secret-read)。
     *
     * ★`feature` は Fortify の機能フラグ (config/fortify.php の `features`)。
     *   null = 常に必須 (route が無ければ起動時 fail-fast)。
     *   非 null = その機能が有効なときだけ必須 (無効なら route 自体が登録されないため skip)。
     *   **skip が穴にならない根拠**: 機能を再有効化して binder が skip したままなら、
     *   ThrottleCoverageInventoryTest が「throttle 無しの保護対象 route」として必ず fail する
     *   (binder の fail-fast と目録検査の二重の網で守る)。
     *
     * @return array<string, array{throttle: string, feature: string|null}>
     */
    private static function throttledFortifyRoutes(): array
    {
        return [
            'password.email' => ['throttle' => 'password-reset-request', 'feature' => Features::resetPasswords()],
            'password.update' => ['throttle' => 'password-reset-submit', 'feature' => Features::resetPasswords()],
            'register.store' => ['throttle' => 'account-register', 'feature' => Features::registration()],
            'password.confirm.store' => ['throttle' => '6,1', 'feature' => null],
            'user-password.update' => ['throttle' => '6,1', 'feature' => Features::updatePasswords()],
            'two-factor.enable' => ['throttle' => '10,1', 'feature' => Features::twoFactorAuthentication()],
            'two-factor.confirm' => ['throttle' => '10,1', 'feature' => Features::twoFactorAuthentication()],
            'two-factor.disable' => ['throttle' => '10,1', 'feature' => Features::twoFactorAuthentication()],
            'two-factor.regenerate-recovery-codes' => ['throttle' => '10,1', 'feature' => Features::twoFactorAuthentication()],
            // ★秘密を返す GET 3 本 (T120 事後監査の是正)。
            //   named limiter を使う理由は configureRateLimiters() の
            //   two-factor-secret-read の docblock を参照 (inline は bucket を
            //   全 inline route で共有するため、描画 GET を足すと再認証を壊す)。
            'two-factor.qr-code' => ['throttle' => 'two-factor-secret-read', 'feature' => Features::twoFactorAuthentication()],
            'two-factor.secret-key' => ['throttle' => 'two-factor-secret-read', 'feature' => Features::twoFactorAuthentication()],
            'two-factor.recovery-codes' => ['throttle' => 'two-factor-secret-read', 'feature' => Features::twoFactorAuthentication()],
        ];
    }

    /**
     * Fortify 登録 route へ throttle を後付けする (設定で貼れないものだけ)。
     *
     * route 登録は Fortify package provider の boot 内で行われるため、全 provider boot 後の
     * booted callback で名前解決する (attachRecentAuthToSensitiveRoutes と同じ流儀)。
     * 後付けは冪等で、route 名が消えていれば fail-fast する
     * (route:cache 起動時の扱いは RouteThrottleBinder::attachOnBooted の docblock を参照)。
     */
    private function attachThrottleToFortifyRoutes(): void
    {
        $routes = [];

        foreach (self::throttledFortifyRoutes() as $name => $spec) {
            if ($spec['feature'] !== null && ! Features::enabled($spec['feature'])) {
                continue; // 機能無効 = route 自体が存在しない (目録検査が二重の網)
            }

            $routes[$name] = $spec['throttle'];
        }

        RouteThrottleBinder::attachOnBooted($this->app, $routes);
    }


### app/Providers/FortifyServiceProvider.php L248-320 (configureRateLimiters)

    private function configureRateLimiters(): void
    {
        /*
         * ログイン試行の RateLimiter。閾値 5/min は据え置き (プロダクト依存の既定値)。
         *
         * ★Str::transliterate を廃止した理由:
         *   App\Support\EmailNormalizer の docblock が「legitimate な Unicode email を
         *   別 user に collapse させるリスクがあるため使わない」と明記しているのに、
         *   本 limiter だけが使っており設計意図と実装が正面から矛盾していた。
         *   実害は「無関係アカウントの巻き添えロックアウト」。
         *
         * ★email は EmailHash (HMAC-SHA256 / app.key 鍵付き) でハッシュ化してからキーに入れる。
         *   **canonical 化の正本は EmailNormalizer** (保存・検索・inquiry と同一)。
         *   limiter は validation より前に走るため email が非 string で来うる → is_string ガード必須。
         */
        RateLimiter::for('login', function (Request $request): Limit {
            return Limit::perMinute(5)->by(
                'login:email-ip:'.self::emailKey($request, Fortify::username())
                .':'.($request->ip() ?? 'unknown'),
            );
        });

        RateLimiter::for('two-factor', function (Request $request): Limit {
            $loginId = $request->session()->get('login.id');

            return is_scalar($loginId)
                ? Limit::perMinute(5)->by('two-factor:login-id:'.$loginId)
                : Limit::perMinute(5)->by('two-factor:ip:'.($request->ip() ?? 'unknown'));
        });

        // passkey (WebAuthn) endpoint。config/fortify.php の limiters.passkeys が
        // この名前を指しており、未設定だと Fortify が throttle 自体を外す
        // (= 未認証の challenge 発行 GET /passkeys/login/options が無制限になる)。
        // 未認証の login-options を含むため、認証済みは user 単位・未認証は IP 単位で絞る。
        RateLimiter::for('passkeys', function (Request $request): Limit {
            $identifier = $request->user()?->getAuthIdentifier();

            return is_scalar($identifier)
                ? Limit::perMinute(10)->by('passkeys:user:'.$identifier)
                : Limit::perMinute(10)->by('passkeys:ip:'.($request->ip() ?? 'unknown'));
        });

        /*
         * 2FA の秘密を返す GET (qr-code / secret-key / recovery-codes) の読み取りレーン。
         *
         * ★inline (`10,1`) にしない: inline のキーは sha1(user id) だけで
         *   **同一ユーザーの全 inline route が 1 bucket を共有する**
         *   (ThrottleRequests::resolveRequestSignature)。ページ描画で 2 発飛ぶ GET を
         *   そこへ足すと、リロード数回で recent-auth.password (max 6) まで 429 にしてしまう。
         *   named limiter はキーに limiter 名が入るためレーンが独立する。
         *
         * ★閾値 10/min は姉妹の 2FA 管理操作 (two-factor.enable / .confirm / .disable /
         *   .regenerate-recovery-codes の `10,1`) と同値 (新しい値を発明しない)。
         *
         * ★throttle は auth middleware より先に走る (priority list) ため未認証でも
         *   closure が評価される。passkeys limiter と同じく IP へ倒す。
         *
         * ★これは**連続取得の回数上限**であって、秘密の漏えい防止でも step-up の代替でもない。
         *   認証強度 (recent-auth 化) は aicue:T120 の後続 TODO B2 の担当。
         */
        RateLimiter::for('two-factor-secret-read', function (Request $request): Limit {
            $identifier = $request->user()?->getAuthIdentifier();

            return is_scalar($identifier)
                ? Limit::perMinute(10)->by('two-factor-secret-read:user:'.$identifier)
                : Limit::perMinute(10)->by('two-factor-secret-read:ip:'.($request->ip() ?? 'unknown'));
        });

        $this->configureAuthFormRateLimiters();
    }

    /**

### app/Providers/AppServiceProvider.php L232-300
        // MessageSending は実送信直前 (キュー worker 内含む) に発火し、全 Mailable / Fortify
        // 経路を横断して一律適用される (返り値契約は FilterSuppressedRecipients docblock 参照)。
        Event::listen(MessageSending::class, FilterSuppressedRecipients::class);

        $this->configureApiRateLimiters();
        $this->configureAuthSurfaceRateLimiters();
        $this->configureInquiryRateLimiter();
        $this->configureRenderRateLimiter();
        $this->configureWebhookRateLimiters();
        $this->attachThrottleToVendorRoutes();
    }

    /**
     * 未認証で到達する認証面 GET の RateLimiter (T120 事後監査の是正)。
     *
     * ★どちらも**未認証**面のため named limiter で数える単位を明示する。
     *   inline throttle (`10,1`) はフレームワーク既定キーに依存するため、
     *   AGENTS.md の規約どおり「認証済みかつ actor 自身に閉じる操作」以外では使わない。
     *
     * ★閾値は発明しない (AG-096 = 閾値はプロダクト依存):
     *   - social-callback  = 10/min。未認証で到達する認証面の IP レーンとして
     *     本番稼働中の `passkeys` limiter の guest 分岐 (10/min) と同値。
     *   - invitation-accept = 10/min。姉妹操作 invitations.accept.store の
     *     `throttle:10,1` と同値 (同じ token 照合を行う 2 本の非対称を解消する)。
     *
     * ★キーに route parameter / query token を混ぜない (NamedRateLimiterKeyTest)。
     *   social.callback の {provider} や invitations.accept の ?token= を key に入れると
     *   bucket が分かれ、「429 になるまでの回数」が実在オラクルになる。
     *
     * ★**無効リクエストも同じ bucket を消費する** (throttle は controller より前に走る)。
     *   intent 不在の callback / 無効 token の招待 open も枠を減らすため、
     *   同一 IP からの無効連打は正当利用者の枠を奪える (一時 DoS)。
     *   これは「未認証面を IP で数える」ことの必然であり、
     *   引き換えに得ているのは「外向き HTTP と token 照合の総量が有界になること」である。
     *
     * ★巻き添えの扱い: IP レーンである以上、同一 NAT 配下の一斉ログイン / 一斉招待受諾は
     *   巻き添え 429 になりうる。limiter は恒久ロックを作らないが到達は保証しない。
     *   運用は 429 発生率と invalid callback 比率を監視し、
     *   **初動は閾値変更ではなく TRUSTED_PROXIES / 実 client IP の解決の確認**とする
     *   (docs/trusted-proxies-runbook.md)。
     */
    private function configureAuthSurfaceRateLimiters(): void
    {
        // SSO callback。1 リクエストで IdP へ token エンドポイント POST が飛びうる
        // (state + intent が揃った場合)。未認証で外部へ HTTP を発射できる唯一の経路。
        RateLimiter::for('social-callback', fn (Request $request): Limit => Limit::perMinute(10)
            ->by('social-callback:ip:'.($request->ip() ?? 'unknown')));

        // 招待受諾の確認画面 (GET)。未認証入力の token を sha256 照合して DB を 1 件引き、
        // 有効/無効で応答が分岐する。姉妹の POST は既に throttle:10,1 で有界化されている。
        RateLimiter::for('invitation-accept', fn (Request $request): Limit => Limit::perMinute(10)
            ->by('invitation-accept:ip:'.($request->ip() ?? 'unknown')));
    }

    /**
     * 未認証 webhook (SES/SNS 通知・Stripe) の RateLimiter。
     *
     * ★固定キーの全体天井は**置かない**。throttle middleware は署名検証より前に走るため、
     *   固定キーのバケットを署名前に消費させると「無効 body の連打で正当な通知を 429 にできる」
     *   = 攻撃者が任意に業務を止められる口になる。
     *
     * ★レーンは送信元ごとに分ける。SES への攻撃で Stripe を止めない。
     *
     * ★これは**署名検証コストの上限**であり、正当通知を守る全体天井ではない。
     *   IP キーである以上、共有クラウド出口 / proxy 設定の誤りでは巻き添え 429 がありうる
     *   (運用は送信元 IP の分布と 429 発生率を監視すること)。
     *   正当通知の保護は「送信元の署名済み identity で bucket を切る」設計が要る (後続 TODO)。
     *
     * 閾値の根拠: 正常時ピークは分あたり数件〜数十件 (SES bounce/complaint、Stripe イベント)。

### app/Providers/AppServiceProvider.php L376-400 (api limiters / apiRateKey)
     */
    private function configureApiRateLimiters(): void
    {
        RateLimiter::for('api-read', fn (Request $request): Limit => Limit::perMinute(120)->by($this->apiRateKey($request)));
        RateLimiter::for('api-write', fn (Request $request): Limit => Limit::perMinute(60)->by($this->apiRateKey($request)));
        RateLimiter::for('api-status', fn (Request $request): Limit => Limit::perMinute(30)->by($this->apiRateKey($request)));
        RateLimiter::for('api-mcp', fn (Request $request): Limit => Limit::perMinute(60)->by($this->mcpRateKey($request)));

        // DCR (POST /oauth/register) 用 (WP23)。未認証で client 登録できる endpoint のため
        // IP 単位で絞る。正常 client は 1 回 / session なので 10 req/min で連打を十分弾ける。
        RateLimiter::for('oauth-register', fn (Request $request): Limit => Limit::perMinute(10)->by('oauth-register:ip:'.($request->ip() ?? 'unknown')));
    }

    private function apiRateKey(Request $request): string
    {
        $apiKey = $request->attributes->get('api_key');
        if ($apiKey instanceof ApiKey) {
            return 'api:api-key:'.$apiKey->id;
        }

        // dual guard の OAuth user-token 経路 (throttle は resolve.api-actor より前段の
        // ため guard から直接引く)。actor 単位で数える (IP 共有環境での巻き添え防止)
        $oauthUser = $request->user('api-oauth');
        if ($oauthUser instanceof User) {
            return 'api:oauth-user:'.$oauthUser->id;
        }

        return 'api:ip:'.($request->ip() ?? 'unknown');
    }


```

```php
### tests/Architecture/RateLimiterKeyConventionTest.php L1-140 (抜粋)
<?php

declare(strict_types=1);

use App\Models\ApiKey;
use App\Models\User;
use App\Support\EmailHash;
use Illuminate\Cache\RateLimiter as CacheRateLimiter;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Session\ArraySessionHandler;
use Illuminate\Session\Store;
use Tests\Support\RateLimiterRegistrationScanner;
use Webmozart\Assert\Assert;

/*
 * named limiter のキー規約 `{レーン}:{種別}:{値}` の behavioral proof。
 *
 * ★検査対象は **named limiter のみ**。inline throttle (`throttle:6,1` 等) は
 *   フレームワーク既定のキー (認証済み = user id / 未認証 = ハッシュ化 IP) を使い、
 *   これは「認証済みかつ actor 自身に閉じる操作」では正しい数える単位である。
 *   キー明示規約は「**自前でキーを組み立てるとき**」の規約であり、対象を named limiter に限る。
 *
 * ★2 層で守る:
 *   (1) 登録の網羅 — token 走査 (RateLimiterRegistrationScanner) で見つけた
 *       `RateLimiter::for()` の名前集合が inventory と完全一致すること。
 *       解析できない登録 (unresolved) は 1 件でも fail (沈黙する登録を作らせない)。
 *   (2) キーの実挙動 — 各 limiter を実際に評価し、produce されたキーが規約に合うこと。
 */

/** キー規約の正規表現 (`{レーン}:{種別}:` の接頭辞)。 */
function rateLimiterKeyConventionPattern(): string
{
    return '#^[a-z][a-z0-9-]*:[a-z][a-z0-9-]*:#';
}

/** 評価シナリオが使う固定 IP (キーに現れることを前提にしない。単に決定性のため)。 */
function rateLimiterScenarioIp(): string
{
    return '203.0.113.7';
}

/** email を扱う scenario が使う平文 email (大文字混じり = 正規化の検証を兼ねる)。 */
function rateLimiterScenarioEmail(): string
{
    return 'Throttle.Probe@Example.COM';
}

/** guest な Request (session なし / user なし)。 */
function rateLimiterGuestRequest(array $input = []): Request
{
    $request = Request::create('/probe', 'POST', $input, server: ['REMOTE_ADDR' => rateLimiterScenarioIp()]);
    $request->setUserResolver(static fn (): ?User => null);

    return $request;
}

/** 認証済み Request (指定 user を全 guard で返す)。 */
function rateLimiterAuthenticatedRequest(User $user, array $input = []): Request
{
    $request = rateLimiterGuestRequest($input);
    $request->setUserResolver(static fn (): User => $user);

    return $request;
}

/** session 付き Request (two-factor limiter は session 必須)。 */
function rateLimiterSessionRequest(?string $loginId): Request
{
    $request = rateLimiterGuestRequest();
    $session = new Store('probe-session', new ArraySessionHandler(120));
    if ($loginId !== null) {
        $session->put('login.id', $loginId);
    }
    $request->setLaravelSession($session);

    return $request;
}

/** DB に触れずに id を持つ User を組み立てる (Architecture レーンは RefreshDatabase 非適用)。 */
function rateLimiterProbeUser(?int $organizationId = null): User
{
    $user = User::factory()->make();
    Assert::isInstanceOf($user, User::class);
    $user->forceFill(['id' => 4242, 'current_organization_id' => $organizationId]);

    return $user;
}

/** DB に触れずに id を持つ ApiKey を組み立てる。 */
function rateLimiterProbeApiKey(): ApiKey
{
    $apiKey = ApiKey::factory()->make(['organization_id' => 77]);
    Assert::isInstanceOf($apiKey, ApiKey::class);
    $apiKey->forceFill(['id' => 99]);

    return $apiKey;
}

/** api-* limiter の with-api-key scenario。 */
function rateLimiterApiKeyRequest(): Request
{
    $request = rateLimiterGuestRequest();
    $request->attributes->set('api_key', rateLimiterProbeApiKey());

    return $request;
}

/**
 * limiter ごとの評価シナリオと期待されるキー接頭辞。
 *
 * @return array<string, array{
 *   scenarios: array<string, callable(): Request>,
 *   expectedKeyPrefixes: list<string>,
 *   emailScenarios: list<string>,
 * }>
 *   scenarios           = 分岐名 => Request ビルダ
 *   expectedKeyPrefixes = produce されるべき `{レーン}:{種別}` の**完全な**集合
 *   emailScenarios      = email をキーに含む scenario 名 (平文残存 / ハッシュ化の検証対象)
 */
function rateLimiterKeyInventory(): array
{
    $email = rateLimiterScenarioEmail();
    $withEmail = static fn (string $field): callable => static fn (): Request => rateLimiterGuestRequest([$field => $email]);
    $noEmail = static fn (): Request => rateLimiterGuestRequest();

    /** @var array<string, array{scenarios: array<string, callable(): Request>, expectedKeyPrefixes: list<string>, emailScenarios: list<string>}> $inventory */
    $inventory = [
        'login' => [
            'scenarios' => ['with-email' => $withEmail('email'), 'no-email' => $noEmail],
            'expectedKeyPrefixes' => ['login:email-ip'],
            'emailScenarios' => ['with-email'],
        ],
        'two-factor' => [
            'scenarios' => [
                'with-login-id' => static fn (): Request => rateLimiterSessionRequest('4242'),
                'guest' => static fn (): Request => rateLimiterSessionRequest(null),
            ],
            'expectedKeyPrefixes' => ['two-factor:login-id', 'two-factor:ip'],
            'emailScenarios' => [],

### tests/Architecture/RateLimiterKeyConventionTest.php L236-330 (produce / 検査部)
/**
 * limiter を評価して produce された Limit を返す。
 *
 * @return list<Limit>
 */
function rateLimiterProduceLimits(string $name, Request $request): array
{
    $limiter = app(CacheRateLimiter::class)->limiter($name);
    Assert::notNull($limiter, "named limiter [{$name}] が登録されていません");

    $result = $limiter($request);
    $limits = is_array($result) ? array_values($result) : [$result];
    Assert::allIsInstanceOf($limits, Limit::class);

    return $limits;
}

/** キーから `{レーン}:{種別}` の接頭辞を取り出す。 */
function rateLimiterKeyPrefix(string $key): string
{
    $segments = explode(':', $key);

    return ($segments[0] ?? '').':'.($segments[1] ?? '');
}

test('scan で検出した limiter 名の集合が inventory と完全一致する (未知 limiter は fail)', function (): void {
    $scanned = RateLimiterRegistrationScanner::scanDirectory(app_path(), 'app');

    $found = array_values(array_unique($scanned['names']));
    sort($found);
    $expected = array_keys(rateLimiterKeyInventory());
    sort($expected);

    expect($found)->toBe($expected,
        'app/ 配下の RateLimiter::for() 登録と rateLimiterKeyInventory() が食い違っています。'
        .'limiter を足したらキー規約の検証シナリオも同時に登録してください。');
});

test('scan の unresolved が 0 件である (解析できない登録を沈黙させない)', function (): void {
    $scanned = RateLimiterRegistrationScanner::scanDirectory(app_path(), 'app');

    expect($scanned['unresolved'])->toBe([],
        'RateLimiter::for() の登録で解析できないものがあります。'
        .'第 1 引数はリテラル文字列で、呼び出しは use 済み短縮名か完全修飾名で書いてください。'
        .PHP_EOL.implode(PHP_EOL, $scanned['unresolved']));
});

test('全 scenario の全 Limit キーが規約パターン {レーン}:{種別}:{値} に一致する', function (): void {
    $pattern = rateLimiterKeyConventionPattern();
    $violations = [];

    foreach (rateLimiterKeyInventory() as $name => $spec) {
        foreach ($spec['scenarios'] as $scenario => $build) {
            foreach (rateLimiterProduceLimits($name, $build()) as $limit) {
                $key = (string) $limit->key;
                if (preg_match($pattern, $key) !== 1) {
                    $violations[] = "{$name}/{$scenario}: キー [{$key}] が規約に一致しません";
                }
            }
        }
    }

    expect($violations)->toBe([], PHP_EOL.implode(PHP_EOL, $violations));
});

test('produce された {レーン}:{種別} 集合が expectedKeyPrefixes と完全一致する', function (): void {
    $violations = [];

    foreach (rateLimiterKeyInventory() as $name => $spec) {
        $produced = [];
        foreach ($spec['scenarios'] as $build) {
            foreach (rateLimiterProduceLimits($name, $build()) as $limit) {
                $produced[rateLimiterKeyPrefix((string) $limit->key)] = true;
            }
        }

        $actual = array_keys($produced);
        sort($actual);
        $expected = $spec['expectedKeyPrefixes'];
        sort($expected);

        if ($actual !== $expected) {
            $violations[] = "{$name}: 期待 [".implode(', ', $expected).'] 実際 ['.implode(', ', $actual).']';
        }
    }

    expect($violations)->toBe([],
        'limiter が produce するキー接頭辞が宣言と食い違っています。'
        .PHP_EOL.implode(PHP_EOL, $violations));
});

test('email を扱う limiter のキーに平文 email も正規化済み email も含まれない', function (): void {
    $plain = rateLimiterScenarioEmail();
    $normalized = mb_strtolower($plain);
    $violations = [];

### tests/Architecture/ThrottleCoverageInventoryTest.php L14-110 (母集団と cap の作法)
/*
 * 流量制限 (throttle) の付与漏れ invariant (deny-by-default)。
 *
 * 「保護対象群に属する route は throttle をちょうど 1 本持つ」を機械強制する。
 * 持たないものは理由付きで exemption inventory へ明示登録させる。
 *
 * ★保護対象群 (S1 ∪ S2 ∪ S3) は意図的に**過大に**取る:
 *   S1 は「未認証で本体に到達する」ことを主張しない。signed / 定数 405 スタブ /
 *   LocalOnly / 署名検証など、Authenticate 以外で本体到達を閉じる route も S1 に入る。
 *   **exemption の役割は「本体到達しない根拠を固定すること」**である
 *   (過小なセレクタはすり抜けを生むが、過大なセレクタは exemption 理由という形で
 *    根拠が文書化されるだけで済む)。
 *
 * ★実効 middleware 列は Router::gatherRouteMiddleware() で取得する
 *   (`route:list --json` は group 名 'web' が展開されず誤判定するため使わない)。
 *   throttle 判定は RouteThrottleBinder::isThrottleEntry() を唯一の判定点として共有する。
 */

/** 変更系 HTTP メソッド。 */
function throttleCoverageMutatingMethods(): array
{
    return ['POST', 'PUT', 'PATCH', 'DELETE'];
}

/** 認証面の route 名パターン (S3)。 */
function throttleCoverageAuthSurfacePattern(): string
{
    return '#^(login|logout|register|password\.|user-password\.|two-factor\.|passkey\.|verification\.'
        .'|recent-auth\.|invitations\.|settings\.password\.|social\.|filament\.admin\.auth\.)#';
}

/** 母集団件数の下限 (空振り drift ガード。実測 70 に対し余裕を持たせた値)。 */
function throttleCoverageRouteFloor(): int
{
    return 60;
}

/** exemption 件数の上限 (形骸化ガード)。**現在値ちょうど** (exact fit)。 */
function throttleCoverageExemptionCap(): int
{
    // ★余裕を 1 でも持たせると、その 1 本は「個別の根拠も再レビューも無しに
    //   免除できる枠」になる。exact fit なら次の 1 本が必ず「この数値を変える差分」
    //   として現れ、個別理由・前提テスト追加要否・そもそも貼るべきでないかの
    //   再検討を強制できる。上げる前に必ず再検討すること。
    return 25;
}

/**
 * exemption の case 別上限 (分類の偏り検出)。全体 cap とは役割が違う
 * (全体 = セレクタの広さ / case 別 = どのカテゴリが膨らんだか)。
 * ★array_sum() で全体 cap を導出しない (両方を独立に検査する)。
 *
 * @return array<string, int> ThrottleCoverageExemption::value => 上限
 */
function throttleCoverageExemptionCapByCase(): array
{
    return [
        ThrottleCoverageExemption::StaticMetadataResponse->value => 4,
        ThrottleCoverageExemption::VendorMethodNotAllowedStub->value => 2,
        ThrottleCoverageExemption::SessionTeardownOnly->value => 2,
        ThrottleCoverageExemption::LocalOnlyDebugRoute->value => 1,
        ThrottleCoverageExemption::ComponentLevelLimiter->value => 1,
        ThrottleCoverageExemption::SignatureRequiredBeforeEffect->value => 1,
        // ★ここが膨らむ = 「貼るべき route を描画系として逃がした」疑い。
        ThrottleCoverageExemption::AuthViewRenderOnly->value => 13,
        ThrottleCoverageExemption::AuthFlowInitiationWithoutOutboundCall->value => 1,
    ];
}

/** exemption 理由の最低文字数 (「同上」「N/A」を機械的に弾く)。 */
function throttleCoverageReasonMinLength(): int
{
    return 30;
}

/**
 * throttle を持たないことが正しいと裁定した route の inventory (型付き + 具体的根拠必須)。
 *
 * @return array<string, array{ThrottleCoverageExemption, string}>
 */
function throttleCoverageExemptions(): array
{
    $metadata = ThrottleCoverageExemption::StaticMetadataResponse;
    $stub = ThrottleCoverageExemption::VendorMethodNotAllowedStub;
    $teardown = ThrottleCoverageExemption::SessionTeardownOnly;
    $localOnly = ThrottleCoverageExemption::LocalOnlyDebugRoute;
    $component = ThrottleCoverageExemption::ComponentLevelLimiter;
    $signature = ThrottleCoverageExemption::SignatureRequiredBeforeEffect;
    $render = ThrottleCoverageExemption::AuthViewRenderOnly;
    $flowInit = ThrottleCoverageExemption::AuthFlowInitiationWithoutOutboundCall;

    return [
        'mcp.oauth.authorization-server' => [$metadata,
            'Laravel\Mcp\Server\Registrar::authorizationServerMetadata() が config と url() と route() だけで'
            .'組む定数 JSON を返す。DB アクセス・暗号処理・外部呼び出し・メール送信を一切伴わないため、'
            .'連打しても増幅する処理コストが存在しない。前提は ThrottleExemptionPremiseTest が固定する。'],


### app/Support/Http/RouteThrottleBinder.php L160-268 (判定 API)
    }

    /**
     * 実効 middleware 列 (controller middleware 込み) のうち throttle entry を返す。
     *
     * 目録検査 (ThrottleCoverageInventoryTest) が使う**完全な**判定点。
     * `Route::gatherMiddleware()` は controller を container から解決するため、
     * **boot 中に呼んではならない** ({@see routeThrottleEntries} を使うこと)。
     *
     * @return list<string> `{class}:{params}` 形式の entry (params なしなら class のみ)
     */
    public static function throttleEntries(Router $router, Route $route): array
    {
        return self::filterThrottleEntries($router->gatherRouteMiddleware($route));
    }

    /**
     * route 自身 (group 展開込み) の middleware のうち throttle entry を返す。
     *
     * ★controller middleware を見ない理由 (boot 中の副作用を避ける):
     *   `Route::gatherMiddleware()` は controller middleware を集めるために
     *   **controller を container から解決する**。boot 中にこれを行うと、
     *   controller が constructor injection で要求する request scope の singleton
     *   (`StatefulGuard` → `session.store` 等) が boot 時点で確定してしまい、
     *   その後の設定変更・request 生成に追随しなくなる
     *   (実測: Fortify の ConfirmablePasswordController が StatefulGuard を要求する)。
     *
     * ★見落としが穴にならない根拠:
     *   controller middleware が throttle を足していた場合、本 binder は二重付与になるが、
     *   目録検査 ({@see throttleEntries} を使う ThrottleCoverageInventoryTest) が
     *   「throttle 2 本以上」として必ず fail させる。
     *
     * @return list<string>
     */
    public static function routeThrottleEntries(Router $router, Route $route): array
    {
        return self::filterThrottleEntries(
            $router->resolveMiddleware($route->middleware(), $route->excludedMiddleware()),
        );
    }

    /**
     * 解決済み middleware 列から throttle entry だけを取り出す。
     *
     * @param  iterable<mixed>  $resolved
     * @return list<string>
     */
    private static function filterThrottleEntries(iterable $resolved): array
    {
        $entries = [];

        foreach ($resolved as $middleware) {
            // 解決後の列には Closure middleware も混ざりうる (throttle ではない)
            if (is_string($middleware) && self::isThrottleEntry($middleware)) {
                $entries[] = $middleware;
            }
        }

        return $entries;
    }

    /** entry の class 部が throttle middleware か。 */
    public static function isThrottleEntry(string $middlewareEntry): bool
    {
        $class = Str::before($middlewareEntry, ':'); // class 名に ':' は含まれない

        return is_a($class, ThrottleRequests::class, true);
    }

    /**
     * throttle entry を class 部 / params 部に分解し、params の形式まで検証する。
     *
     * @return array{class: string, params: string}
     *
     * @throws RuntimeException params が named / inline のどちらの形式にも一致しない場合
     */
    private static function parseThrottleEntry(string $entry, string $context): array
    {
        $class = Str::before($entry, ':');
        // ★`:` を含まない entry (パラメータなし throttle) は params = '' になり、
        //   assertValidLimiter が必ず例外側へ落とす (意図どおり)。
        $params = Str::contains($entry, ':') ? Str::after($entry, ':') : '';

        self::assertValidLimiter($params, $context);

        return ['class' => $class, 'params' => $params];
    }

    /**
     * limiter 指定の形式を検証する (開発時ミス / 想定外 throttle の検出)。
     *
     * @throws RuntimeException named / inline のどちらの形式にも一致しない場合
     */
    private static function assertValidLimiter(string $limiter, string $context): void
    {
        if (preg_match(self::NAMED_LIMITER_PATTERN, $limiter) === 1) {
            return;
        }
        if (preg_match(self::INLINE_LIMITER_PATTERN, $limiter) === 1) {
            return;
        }

        throw new RuntimeException(
            $context.' が throttle の許容形式に一致しません。'
            .'named limiter 名 (`[a-z][a-z0-9-]*`) か inline 形式 (`{max},{decay}`) のいずれかで指定してください。'
            .'想定外の形式を素通しすると、意図しない上限のまま公開される事故になります。',
        );
    }
}

### tests/Feature/Security/AuthThrottleCoverageTest.php L1-30 と L300-375
<?php

declare(strict_types=1);

use App\Http\Middleware\RequireRecentAuth;
use App\Http\Middleware\VerifySnsSignature;
use App\Models\User;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Routing\Router;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Route;
use Illuminate\Testing\TestResponse;
use Laravel\Socialite\Facades\Socialite;

/*
 * T120 で新設した認証系 / webhook throttle の behavioral proof。
 *
 * 目録検査 (ThrottleCoverageInventoryTest) は「throttle が付いているか」までしか見ない。
 * 実際に 429 で止まるか・どの単位で数えるか・どの middleware より先に走るかは
 * 実挙動でしか固定できないため、ここで契約として固定する。
 *
 * cache store はテスト実行時 array に強制されている (phpunit.xml) ため、
 * app を作り直す各テストで RateLimiter のバケットは空から始まる。
 */

/** 何回叩いても同じ結果になる POST helper。 */
function throttleProbePost(string $uri, array $payload = []): TestResponse
{
    return test()->post($uri, $payload);
}
    $second = $this->get('/invitations/accept?token=probe-token-b');

    expect((int) $second->headers->get('X-RateLimit-Remaining'))->toBe(
        (int) $first->headers->get('X-RateLimit-Remaining') - 1,
        'token を変えたら残数が戻った = token ごとに bucket が分かれている (総当りが有界にならない)',
    );
});

test('2FA 秘密 GET のレーンは独立している — 10 回踏んでも recent-auth / 2FA 管理 POST が 429 にならない', function (): void {
    // ★本設計で最も重要な恒久回帰。**削らないこと**。
    //   two-factor.qr-code に inline throttle (`10,1`) を貼ると、inline のキーは
    //   sha1(user id) のみで route も limiter 名も入らないため、同一 actor の
    //   **全 inline throttle route が 1 bucket を共有する**
    //   (ThrottleRequests::handle() の $prefix 既定 '' + resolveRequestSignature())。
    //   その状態で描画のたびに飛ぶ GET を足すと、共有カウンタが最小 max の
    //   recent-auth.password (6,1) を先に食い潰し **再認証できなくなる**。
    //   named limiter (two-factor-secret-read) はキーに limiter 名が入るためレーンが分かれる。
    //   inline へ戻す変更を入れたらこのテストが落ちる。
    $user = User::factory()->withTwoFactor()->create();
    $this->actingAs($user);

    for ($i = 1; $i <= 10; $i++) {
        expect($this->get('/user/two-factor-qr-code')->getStatusCode())
            ->not->toBe(429, "{$i} 回目で既に 429 になりました");
    }
    expect($this->get('/user/two-factor-qr-code')->getStatusCode())->toBe(429, '2FA 秘密 GET のレーンが使い切られていません');

    // 秘密読み取りレーンを使い切った直後でも、別レーンは 1 枠も消費していない。
    // (認証情報が誤っていてもよい。429 でないこと = throttle で止まっていないことだけ見る)
    expect($this->post('/recent-auth/password', ['password' => 'wrong-password'])->getStatusCode())
        ->not->toBe(429, '再認証 (recent-auth.password) が 2FA 秘密 GET の巻き添えで 429 になりました');

    expect($this->post('/user/confirmed-two-factor-authentication', ['code' => '000000'])->getStatusCode())
        ->not->toBe(429, '2FA 管理 POST が 2FA 秘密 GET の巻き添えで 429 になりました');
});

test('2FA 秘密 GET は 11 回目で 429 — これは連続取得の回数上限であって認証強度ではない (認証強度は後続 TODO B2)', function (): void {
    // ★誤読防止: ここで固定しているのは「回数の上限」だけである。
    //   qr-code / secret-key / recovery-codes を **step-up なしで読めること自体**の是非は
    //   aicue:T120 の後続 TODO B2 (recent-auth 化) の担当であり、本テストが green でも
    //   「秘密の保護が済んだ」ことは 1 ミリも意味しない。
    $user = User::factory()->withTwoFactor()->create();
    $this->actingAs($user);

    for ($i = 1; $i <= 10; $i++) {
        expect($this->get('/user/two-factor-secret-key')->getStatusCode())
            ->not->toBe(429, "{$i} 回目で既に 429 になりました");
    }

    expect($this->get('/user/two-factor-secret-key')->getStatusCode())->toBe(429);
});

test('2FA 秘密 GET 3 本は 1 つのレーンを共有する (描画で複数発飛ぶ GET を合算して数える)', function (): void {
    // qr-code / secret-key は 2FA 設定画面の 1 描画で 2 発飛ぶ。両者が別 bucket だと
    // 「画面を開く回数」ではなく「endpoint ごとの回数」を数えることになり、
    // 秘密の連続取得の上限としては実効が薄れる。同一 limiter 名を共有していることを示す。
    // ★3 本すべてを対象にする。1 本でも別 limiter (inline `10,1` 等) に戻ると
    //   残数が連続しなくなりここで落ちる。
    $user = User::factory()->withTwoFactor()->create();
    $this->actingAs($user);

    $uris = ['/user/two-factor-qr-code', '/user/two-factor-secret-key', '/user/two-factor-recovery-codes'];
    $previous = null;

    foreach ($uris as $uri) {
        $remaining = $this->get($uri)->headers->get('X-RateLimit-Remaining');
        expect($remaining)->not->toBeNull("{$uri} に X-RateLimit-* が付いていません (throttle が効いていない)");

        if ($previous !== null) {
            expect((int) $remaining)->toBe($previous - 1,
                "{$uri} が他の 2FA 秘密 GET と別 bucket へ分かれています");
        }
        $previous = (int) $remaining;
    }
});

### vendor: ThrottleRequests::handle / resolveRequestSignature
    public function handle($request, Closure $next, $maxAttempts = 60, $decayMinutes = 1, $prefix = '')
    {
        if (is_string($maxAttempts)
            && func_num_args() === 3
            && ! is_null($limiter = $this->limiter->limiter($maxAttempts))) {
            return $this->handleRequestUsingNamedLimiter($request, $next, $maxAttempts, $limiter);
        }

        return $this->handleRequest(
            $request,
            $next,
            [
                (object) [
                    'key' => $prefix.$this->resolveRequestSignature($request),
                    'maxAttempts' => $this->resolveMaxAttempts($request, $maxAttempts),
                    'decaySeconds' => 60 * $decayMinutes,
                    'afterCallback' => null,
                    'responseCallback' => null,
                ],
            ]
        );
    }
     * @throws \RuntimeException
     */
    protected function resolveRequestSignature($request)
    {
        if ($user = $request->user()) {
            return $this->formatIdentifier($user->getAuthIdentifier());
        } elseif ($route = $request->route()) {
            return $this->formatIdentifier($route->getDomain().'|'.$request->ip());
        }

        throw new RuntimeException('Unable to generate the request signature. Route unavailable.');
    }

    /**

### vendor: Kernel::$middlewarePriority (auth が throttle より先)
    protected $middlewarePriority = [
        \Illuminate\Foundation\Http\Middleware\HandlePrecognitiveRequests::class,
        \Illuminate\Cookie\Middleware\EncryptCookies::class,
        \Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse::class,
        \Illuminate\Session\Middleware\StartSession::class,
        \Illuminate\View\Middleware\ShareErrorsFromSession::class,
        \Illuminate\Contracts\Auth\Middleware\AuthenticatesRequests::class,
        \Illuminate\Routing\Middleware\ThrottleRequests::class,
        \Illuminate\Routing\Middleware\ThrottleRequestsWithRedis::class,
        \Illuminate\Contracts\Session\Middleware\AuthenticatesSessions::class,
        \Illuminate\Routing\Middleware\SubstituteBindings::class,
        \Illuminate\Auth\Middleware\Authorize::class,
    ];

### vendor: fortify routes (verification limiter knob)
    $twoFactorLimiter = config('fortify.limiters.two-factor');
    $passkeyLimiter = config('fortify.limiters.passkeys');
    $verificationLimiter = config('fortify.limiters.verification', '6,1');

    Route::post(RoutePath::for('login', '/login'), [AuthenticatedSessionController::class, 'store'])
        ->middleware(array_filter([
            'guest:'.config('fortify.guard'),
            $limiter ? 'throttle:'.$limiter : null,
        ]))->name('login.store');
        }

        Route::get(RoutePath::for('verification.verify', '/email/verify/{id}/{hash}'), [VerifyEmailController::class, '__invoke'])
            ->middleware([config('fortify.auth_middleware', 'auth').':'.config('fortify.guard'), 'signed', 'throttle:'.$verificationLimiter])
            ->name('verification.verify');

        Route::post(RoutePath::for('verification.send', '/email/verification-notification'), [EmailVerificationNotificationController::class, 'store'])
            ->middleware([config('fortify.auth_middleware', 'auth').':'.config('fortify.guard'), 'throttle:'.$verificationLimiter])
            ->name('verification.send');
    }

```

---

## 参考: 実測データ

`php artisan route:list` で throttle を持つ route は 48 本。うち inline 指定は 15 本:

- `6,1` (6 本, すべて auth 必須): verification.send / verification.verify / recent-auth.password / settings.password.store / password.confirm.store / user-password.update
- `10,1` (6 本, すべて auth 必須): invitations.accept.store / onboarding.activate-personal / two-factor.enable / two-factor.confirm / two-factor.disable / two-factor.regenerate-recovery-codes
- `60,1` (1 本, auth なし・署名必須): livewire.upload-file
- パラメータなし = 既定 60,1 (2 本, stateless): passport.token / passport.device.code

解決後 middleware 列の実測 (`route:list`) では、auth 必須 route において
`Illuminate\Auth\Middleware\Authenticate` が `Illuminate\Routing\Middleware\ThrottleRequests` より
**先**に並ぶ (framework 既定 priority list どおり)。
