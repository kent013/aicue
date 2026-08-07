# 詳細設計レビュー依頼 (Round 3): Round 2 指摘への対応と再判定

Round 2 の [Warning] 2 件・[Suggestion] 1 件に**すべて対応**しました（反論なし）。

## 対応マトリクス

| 指摘 | 判断 | 対応内容 |
|---|---|---|
| [Warning] S5 が自前 route を vendor case へ登録できてしまう | 対応 | (1) `inlineThrottleCaseVendorNamespaces()` を新設し、case ごとに許す **action class の名前空間**を宣言（stateless = `Laravel\\Passport\\` / mixed = `Livewire\\`）。premise で一致を必須にし、Closure action も false（由来を証明できない）。実測値は `route:list` の action 列で確認済み。(2) 判定を `Authenticate` 具象クラスから **`Illuminate\\Contracts\\Auth\\Middleware\\AuthenticatesRequests` インターフェース**に変え、stateless の premise へ「認証 middleware が無いこと」も追加。(3) **保証範囲を誇張しない**注記を追加（閉じているのは session guard と framework 認証 middleware の 2 経路だけ）。(4) enum docblock を機械保証の記述に更新し、名前空間リスト自体を書き換えればすり抜けられるという限界も明記。(5) mutation **M10**（自前 controller の route を vendor case へ登録しようとする）を追加 |
| [Warning] S8 の Livewire 消費元が空振りしうる | 対応 | ループを書き換え、各応答で `X-RateLimit-Remaining` の存在を検査したうえで **6 回目の残数が初回より 5 減っていること**を固定。空振り保証の表にも追加 |
| [Suggestion] mutation 一覧と実装手順の範囲が同期していない | 対応 | 実装順序 8 を「mutation 表の全項目」と明示して全 17 項目を列挙。M9 を提案どおり **2 段階**（M9-a: ヘッダ検査を外すと緑のままになることの観測 / M9-b: 戻すと赤になること）に分割 |

Codex の重点観点への回答（exact fit の摩擦は受容 / probe 固定値は望ましい壊れ方 /
S6 の責務逸脱なし / `--parallel` 安定）についてはいずれも同意であり、
定数への集約は「今必要なものだけ作る」に照らして見送りました。

---

## 更新後の詳細設計書（全文）

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
      **無変更で green のまま**であること（接頭辞レベルの回帰）
- [ ] **接頭辞では不十分**（Round 1 指摘）。S7 で追加する
      「actor/IP レーンの full key が宣言と完全一致する」が
      `passkeys:user:4242` / `passkeys:ip:203.0.113.7` のような **full key** を固定し、
      suffix の作り方が変わっていないこと = bucket をリセットしないことを証明する
- [ ] 新規 `tests/Unit/Support/Http/RateLimiterKeysTest.php`
  - `actorOrIp() は認証済みユーザーに {lane}:user:{id} を返す`
  - `actorOrIp() は未認証に {lane}:ip:{ip} を返す`
  - `actorOrIp() は IP が取れないとき {lane}:ip:unknown を返す（キーを空にしない）`
  - `actorOrIp() は identifier が bool / float のとき user 分岐へ落ちない（is_scalar 相当の誤受理の負のコントロール）`
    — `Authenticatable` の匿名実装で `getAuthIdentifier()` に `true` / `1.5` を返させ、`:ip:` になることを検査
- [ ] 個別の `DatabaseTransactions` を使っていないことを確認（Unit テストで DB 非依存）

### リスク

- **既存 2 limiter のキーが変わると passkey / 2FA 秘密読み取りの bucket がリセットされる**（デプロイ直後の 1 分だけ枠が復活する）。
  キー文字列は完全に同一に保つ設計であり、S7 の **full key 検査**が差分を検出する
  （接頭辞検査だけでは suffix の変化を見逃すため、full key の固定が必須）。
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
 *   各 case は **action class の vendor 名前空間**（`Laravel\Passport\` / `Livewire\`）を
 *   premise として機械検査するため、`App\...` の自前 controller はどの case にも当てはまらない。
 *   自前 route に inline を足すと目録に登録できず必ず fail する。
 *   これが AGENTS.md ドメイン規約 5「レーンを分けたいときは inline ではなく
 *   named limiter を新設する」の機械化である
 *   （premise の名前空間リスト自体を書き換えれば当然すり抜けられるが、
 *    その差分は必ずレビューに現れる = 無言で通ることが無い）。
 */
enum InlineThrottleBucketRationale: string
{
    /**
     * session を持たず、キーが常に IP へ倒れる vendor route。
     *
     * 適用条件（すべて機械検査される）:
     *  1. action class が宣言済みの vendor 名前空間由来（`Laravel\Passport\`）
     *  2. 実効 middleware 列に `StartSession` が無い
     *  3. 実効 middleware 列に `AuthenticatesRequests` 実装が無い
     * かつ（人間の裁定として）vendor が throttle をハードコードしており
     * 設定でも `RouteThrottleBinder` でも置換できないこと
     * （置換しようとすると二重付与になり `ThrottleCoverageInventoryTest` が fail する）。
     */
    case VendorStatelessIpBucket = 'vendor_stateless_ip_bucket';

    /**
     * 認証状態によってキーが user id にも IP にもなりうる vendor route。
     *
     * 適用条件（1〜3 は機械検査される）:
     *  1. action class が宣言済みの vendor 名前空間由来（`Livewire\`）
     *  2. 実効 middleware 列に `StartSession` が有る
     *  3. 実効 middleware 列に `AuthenticatesRequests` 実装が無い
     * かつ（人間の裁定として）vendor の controller middleware / package 設定が
     * throttle を決めており、上書きに vendor 設定ファイル全体の公開が要ること
     * （浅い merge により同一セクションの他キーを巻き添えで失う）。
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

/**
 * case 別の **exact fit** 件数（`<=` ではなく `===` で照合する）。
 *
 * ★上限ではなく「ちょうどこの数」である。`<=` にすると件数が減ったときに
 *   余った枠が「個別の再検討なしに inline を足せる枠」として残ってしまう。
 *   増える方向にも減る方向にも、必ずこの数値を変える差分として現れさせる。
 */
function inlineThrottleRationaleExactCountByCase(): array
{
    return [
        InlineThrottleBucketRationale::VendorStatelessIpBucket->value => 2,
        // ★1 から動かさない。2 本目 = 認証済み actor の bucket 共有の再来。
        InlineThrottleBucketRationale::VendorMixedUserOrIpBucket->value => 1,
    ];
}

/**
 * case ごとに許す **action の由来**（vendor provenance）。
 *
 * ★「vendor だから inline を許す」という主張を機械化する（Round 2 指摘）。
 *   middleware 構成だけを見ていると、`StartSession` あり `Authenticate` なしの
 *   **自前 web route** が `VendorMixedUserOrIpBucket` として登録できてしまう。
 *   action class の名前空間を case ごとに固定することで、
 *   `App\...` の自前 controller はどの case にも当てはまらなくなる。
 *
 * ★この配列自体を書き換えれば当然すり抜けられる（`App\` を足す等）。
 *   それは目録型 gate の一般的な性質であり、**その差分がレビューに現れること**が
 *   本 gate の目的である（無言で通ることが無いこと）。
 *
 * @return array<string, list<string>> case => 許す action の名前空間接頭辞
 */
function inlineThrottleCaseVendorNamespaces(): array
{
    return [
        InlineThrottleBucketRationale::VendorStatelessIpBucket->value => ['Laravel\\Passport\\'],
        InlineThrottleBucketRationale::VendorMixedUserOrIpBucket->value => ['Livewire\\'],
    ];
}

/**
 * case ごとの適用条件を実効 middleware 列 + action の由来で機械化するための述語。
 *
 * ★分類を「作文」で終わらせないための premise 検査。vendor の更新で
 *   session の有無や controller の名前空間が変われば、根拠の文章より先にここが落ちる。
 *
 * ★**保証範囲を誇張しない**: 「`StartSession` が無い」は
 *   「`$request->user()` が絶対に null」を意味しない（独自の認証 middleware が
 *   user resolver を差し替える余地は残る）。ここで閉じているのは
 *   **session guard と framework の認証 middleware という 2 つの構造的な経路**だけである。
 *
 * @return array<string, callable(RoutingRoute): bool>
 */
function inlineThrottleCasePremises(): array
{
    $hasClass = static function (RoutingRoute $route, string $class): bool {
        /** @var Router $router */
        $router = Route::getFacadeRoot();
        foreach ($router->gatherRouteMiddleware($route) as $entry) {
            if (is_string($entry) && is_a(Str::before($entry, ':'), $class, true)) {
                return true;
            }
        }

        return false;
    };

    $fromVendor = static function (RoutingRoute $route, string $case): bool {
        $action = Str::before($route->getActionName(), '@');
        foreach (inlineThrottleCaseVendorNamespaces()[$case] ?? [] as $prefix) {
            if (str_starts_with($action, $prefix)) {
                return true;
            }
        }

        return false; // Closure action もここで false（由来を証明できない）
    };

    $stateless = InlineThrottleBucketRationale::VendorStatelessIpBucket->value;
    $mixed = InlineThrottleBucketRationale::VendorMixedUserOrIpBucket->value;

    return [
        // stateless = session guard も framework の認証 middleware も通らない
        //           → $request->user() は null → キーは IP 固定
        $stateless => static fn (RoutingRoute $route): bool => $fromVendor($route, $stateless)
            && ! $hasClass($route, StartSession::class)
            && ! $hasClass($route, AuthenticatesRequests::class),
        // mixed = session はあるが auth 必須ではない → user id にも IP にもなる
        $mixed => static fn (RoutingRoute $route): bool => $fromVendor($route, $mixed)
            && $hasClass($route, StartSession::class)
            && ! $hasClass($route, AuthenticatesRequests::class),
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

test('case 別件数が宣言値とちょうど一致する（enum 全 case を走査。未登録も fail）', function (): void {
    $expected = inlineThrottleRationaleExactCountByCase();

    $counts = [];
    foreach (InlineThrottleBucketRationale::cases() as $case) {
        $counts[$case->value] = 0;
    }
    foreach (inlineThrottleInventory() as [$rationale, $reason]) {
        $counts[$rationale->value]++;
    }

    $violations = [];
    foreach ($counts as $case => $count) {
        if (! array_key_exists($case, $expected)) {
            $violations[] = "{$case}: inlineThrottleRationaleExactCountByCase() に件数がありません";

            continue;
        }
        // ★`>` ではなく `!==`。減った方向も差分として現れさせる（余った枠を残さない）。
        if ($count !== $expected[$case]) {
            $violations[] = "{$case}: {$count} 件（宣言 {$expected[$case]} 件）";
        }
    }
    foreach (array_keys($expected) as $case) {
        if (! array_key_exists($case, $counts)) {
            $violations[] = "{$case}: enum に存在しない case の件数宣言が残っています";
        }
    }

    expect($violations)->toBe([],
        '件数を増やす前に、その route を named limiter へ移せないかを必ず再検討すること。'
        .'減った場合は宣言値を下げること（枠を残さない）。'
        .PHP_EOL.implode(PHP_EOL, $violations));
});

test('分類 case の適用条件が実効 middleware 列と一致する（premise の固定）', function (): void {
    // ★根拠の文章ではなく**実効 middleware 列**で分類の前提を固定する。
    //   vendor の更新で passport が session を張るようになれば、ここが先に落ちる。
    $premises = inlineThrottleCasePremises();
    $inventory = inlineThrottleInventory();
    $violations = [];

    foreach (Route::getRoutes() as $route) {
        $label = inlineThrottleRouteLabel($route);
        if (! array_key_exists($label, $inventory)) {
            continue;
        }
        $case = $inventory[$label][0]->value;
        if (! array_key_exists($case, $premises)) {
            $violations[] = "{$case}: premise が定義されていません";

            continue;
        }
        if (! $premises[$case]($route)) {
            $violations[] = "{$label}: {$case} の適用条件（session / auth の有無）を満たしていません";
        }
    }

    expect($violations)->toBe([], PHP_EOL.implode(PHP_EOL, $violations));
});
```

> `use Illuminate\Contracts\Auth\Middleware\AuthenticatesRequests;` と
> `use Illuminate\Session\Middleware\StartSession;` を同ファイルへ追加する。
> `Authenticate` の具象クラスではなく **`AuthenticatesRequests` インターフェース**で判定するのは、
> vendor / Filament が独自の Authenticate 実装を使う場合も拾うため
> （framework の priority list もこのインターフェースで並べている）。

> **premise の実測値**（設計時点。`route:list` の action 列）:
> `passport.token` = `Laravel\Passport\Http\Controllers\AccessTokenController@issueToken`、
> `passport.device.code` = `Laravel\Passport\Http\Controllers\DeviceCodeController`（invokable）、
> `livewire.upload-file` = `Livewire\Features\SupportFileUploads\FileUploadController@handle`。
> `Str::before($route->getActionName(), '@')` は invokable でも正しくクラス名を返す。

### PHPStan 適合チェック

- [x] 戻り値の型が明示されている（helper 関数すべてに戻り値型 / 配列は phpdoc）
- [x] null 安全（`getName()` の null / `Str::after` の分岐）
- [x] DTO（該当なし。テストと enum）
- [x] Generics（`array<string, array{InlineThrottleBucketRationale, string}>` を phpdoc で明示）

### テスト計画（この施策自体がテスト。**赤化の確認手順**を含む）

- [x] 実装前の main では **12 件の未登録 inline route** で fail する（`recent-auth.password` 等）
      = この gate が「素の main で赤 → 移行後に緑」になる、テストファーストの証拠
- [ ] 移行後の mutation で再度赤化することを確認する（下記「赤化確認手順」M1 / M2 / M2' / M2'' / M2''' / M2''''）
- [ ] 負のコントロール（分類器の両方向）は 1 本目のテストが担う
- [ ] 母集団 0 件の検出は「stale 検出」テストが担う（母集団が空なら目録 3 件すべてが stale で fail）
- [ ] **減少方向**（inline が減ったのに宣言値を下げ忘れる）は exact fit（`!==`）が担う（M2'''）
- [ ] 分類の premise（session / auth の有無）は「適用条件が実効 middleware 列と一致する」が担う（M2''''）

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

test('route に貼られた named limiter はすべて実在する（typo 検出。母集団は全 route）', function (): void {
    // ★対象は本設計のレーンだけではなく **route に貼られた全 named throttle**。
    //   目録側の lane だけを見ると、route に `throttle:password-sett` と書かれた
    //   「目録に存在しない未知の名前」を列挙できない
    //   （割当の完全一致テストは「route が消えた」としか言わず、原因が typo だと分からない）。
    //   未登録 limiter はリクエスト時に MissingRateLimiterException になるため、
    //   ここで build 時に落とす。
    /** @var Router $router */
    $router = Route::getFacadeRoot();
    $limiters = app(CacheRateLimiter::class);
    $missing = [];

    foreach (Route::getRoutes() as $route) {
        foreach (RouteThrottleBinder::throttleEntries($router, $route) as $entry) {
            $params = Str::contains($entry, ':') ? Str::after($entry, ':') : '';
            // inline（`{max},{decay}` / パラメータなし）は named ではないので対象外
            if ($params === '' || preg_match('/^\d+,\d+$/', $params) === 1) {
                continue;
            }
            if ($limiters->limiter($params) === null) {
                $missing[] = throttleLaneRouteLabel($route).' → '.$params;
            }
        }
    }

    expect($missing)->toBe([],
        '登録されていない named limiter が route に貼られています'
        .'（リクエスト時に MissingRateLimiterException になります）。'
        .PHP_EOL.implode(PHP_EOL, array_unique($missing)));
});

test('named limiter を貼った route が 1 本以上ある（走査の空振り検出）', function (): void {
    // ★上のテストは「未登録が無いこと」を見るため、母集団が 0 件でも green になる。
    //   走査自体が生きていることを別に固定する（実測 33 本）。
    /** @var Router $router */
    $router = Route::getFacadeRoot();
    $named = 0;

    foreach (Route::getRoutes() as $route) {
        foreach (RouteThrottleBinder::throttleEntries($router, $route) as $entry) {
            $params = Str::contains($entry, ':') ? Str::after($entry, ':') : '';
            if ($params !== '' && preg_match('/^\d+,\d+$/', $params) !== 1) {
                $named++;
            }
        }
    }

    expect($named)->toBeGreaterThanOrEqual(25,
        "named throttle を貼った route が {$named} 件しか検出されませんでした（走査が壊れています）。");
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

/**
 * helper (`RateLimiterKeys::actorOrIp`) を使う limiter の **full key** 期待値。
 *
 * ★`expectedKeyPrefixes` は接頭辞しか見ないため、suffix（actor id / IP）の作り方が
 *   変わっても検出できない。S1 の helper 移行が **bucket をリセットしない**ことを
 *   主張するには full key の同一性が要る（prefix 一致では不十分）。
 *
 * probe の固定値: user id = 4242（rateLimiterProbeUser）/ IP = 203.0.113.7。
 *
 * @return array<string, array{authenticated: string, guest: string}>
 */
function rateLimiterActorOrIpFullKeys(): array
{
    $ip = rateLimiterScenarioIp();
    $lanes = [
        'passkeys',
        'two-factor-secret-read',
        'password-verify',
        'password-set',
        'email-verification',
        'two-factor-manage',
        'invitation-accept-submit',
        'plan-activate',
    ];

    $expected = [];
    foreach ($lanes as $lane) {
        $expected[$lane] = [
            'authenticated' => $lane.':user:4242',
            'guest' => $lane.':ip:'.$ip,
        ];
    }

    return $expected;
}

test('actor/IP レーンの full key が宣言と完全一致する（helper 移行で bucket をリセットしない）', function (): void {
    $inventory = rateLimiterKeyInventory();
    $violations = [];

    foreach (rateLimiterActorOrIpFullKeys() as $lane => $expected) {
        foreach ($expected as $scenario => $key) {
            $limits = rateLimiterProduceLimits($lane, $inventory[$lane]['scenarios'][$scenario]());
            $actual = array_map(static fn (Limit $limit): string => (string) $limit->key, $limits);
            if ($actual !== [$key]) {
                $violations[] = "{$lane}/{$scenario}: 期待 [{$key}] 実際 [".implode(', ', $actual).']';
            }
        }
    }

    expect($violations)->toBe([],
        'キー文字列が変わると既存 bucket がリセットされ、デプロイ直後に枠が復活します。'
        .PHP_EOL.implode(PHP_EOL, $violations));
});

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
 |
 | ★**「429 でないこと」だけを見ない**（false green の防止）。
 |   前段 middleware の短絡や throttle の付け外しでも「429 でない」は成立するため、
 |   独立性を主張する probe では必ず `X-RateLimit-Remaining` の存在も確認し、
 |   「throttle が実際に走ったうえで通った」ことを示す。
 */

/** 429 ではなく、かつ throttle が実際に走った（残数ヘッダがある）ことを検査する。 */
function expectNotThrottled(TestResponse $response, string $message): void
{
    expect($response->headers->get('X-RateLimit-Remaining'))->not->toBeNull(
        $message.'（X-RateLimit-* が無い = throttle が走っていない。false green の疑い）',
    );
    expect($response->getStatusCode())->not->toBe(429, $message);
}

test('Livewire アップロードのレーンは再認証を巻き添えにしない（max 60 が max 6 を殺さない）', function (): void {
    // ★本 TODO の中心的な回帰。inline のままだと livewire.upload-file（max 60）の
    //   6 回目で共有カウンタが 6 に達し、recent-auth.password（max 6）が 429 になる。
    $user = User::factory()->create();
    $this->actingAs($user);

    // ★消費元の空振り防止（Round 2 指摘）。他のレーンは「N+1 回目が 429」で消費を証明できるが、
    //   Livewire だけは上限 60 のためループ内で 429 に到達せず、
    //   「署名検査や middleware 順の変更で 1 枠も消費しなくなった」状態でも
    //   probe 側が緑になってしまう。**残数が 1 ずつ減っていること**まで固定する。
    $remainings = [];
    for ($i = 1; $i <= 6; $i++) {
        // 署名なしのため 401 で弾かれるが、throttle は controller より前で数える
        $response = $this->post(route('livewire.upload-file'));
        $remaining = $response->headers->get('X-RateLimit-Remaining');
        expect($remaining)->not->toBeNull("{$i} 回目に X-RateLimit-* がありません（throttle が走っていない）");
        expect($response->getStatusCode())->not->toBe(429, "{$i} 回目で既に 429 になりました");
        $remainings[] = (int) $remaining;
    }
    expect($remainings[5])->toBe($remainings[0] - 5,
        'Livewire アップロードが bucket を消費していません（消費していないなら独立性の主張が空振りする）');

    expectNotThrottled(
        $this->post('/recent-auth/password', ['password' => 'wrong-password']),
        '再認証がファイルアップロードの巻き添えで 429 になりました',
    );
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

    expectNotThrottled(
        $this->post('/recent-auth/password', ['password' => 'wrong-password']),
        '再認証が 2FA 管理の巻き添えで 429 になりました',
    );
    expectNotThrottled(
        $this->post('/settings/password', ['password' => 'short']),
        'パスワード初回設定が 2FA 管理の巻き添えで 429 になりました',
    );
    expectNotThrottled(
        $this->post('/email/verification-notification'),
        '認証メール再送が 2FA 管理の巻き添えで 429 になりました',
    );
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
    expectNotThrottled(
        $this->post('/settings/password', ['password' => 'short']),
        'パスワード初回設定が照合レーンの巻き添えで 429 になりました',
    );
    expectNotThrottled(
        $this->post('/user/two-factor-authentication'),
        '2FA 管理が照合レーンの巻き添えで 429 になりました',
    );
    expectNotThrottled(
        $this->post('/email/verification-notification'),
        '認証メール再送が照合レーンの巻き添えで 429 になりました',
    );
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

    expectNotThrottled(
        $this->post('/recent-auth/password', ['password' => 'wrong-password']),
        '再認証がメール再送の巻き添えで 429 になりました',
    );
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

    expectNotThrottled(
        $this->post('/invitations/accept', ['token' => 'invalid-token']),
        '受諾 POST が確認画面 GET の巻き添えで 429 になりました',
    );
});

test('プラン有効化レーンを使い切っても再認証は 429 にならない', function (): void {
    [, $owner] = createOrganizationWithOwner(grandfatherFreePlan: false);
    $this->actingAs($owner);

    for ($i = 1; $i <= 10; $i++) {
        expect($this->post('/onboarding/activate-personal', activatePersonalPayload())->getStatusCode())
            ->not->toBe(429, "{$i} 回目で既に 429 になりました");
    }
    expect($this->post('/onboarding/activate-personal', activatePersonalPayload())->getStatusCode())->toBe(429);

    expectNotThrottled(
        $this->post('/recent-auth/password', ['password' => 'wrong-password']),
        '再認証がプラン有効化の巻き添えで 429 になりました',
    );
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

> **`settings.password.store` を probe に使うときの注意**: この route の実効 middleware 順は
> `Authenticate` → `ThrottleRequests` → `EnsureEmailIsVerified` → `RequireRecentAuth` であり
> （`route:list` 実測。既存テスト「2FA 管理 route は throttle が recent-auth より先に走る」と同じ形）、
> **recent-auth の短絡より前に throttle が数える**。したがって recent-auth 済みセッションを
> 作らなくても probe として成立するが、`expectNotThrottled()` が
> `X-RateLimit-Remaining` の存在を必ず確認するため、順序が将来変わって
> throttle が走らなくなった場合も false green にはならない。
>
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
実装ブランチ上で以下の mutation を 1 つずつ当て、**検出したい gate（primary）が確かに赤になること**を
確認し、結果を `devnotes/20260807-2032-todo-T125-design/gate-mutation-log.md` に記録してから revert する。

> **「そのテストだけが赤になる」とは書かない**（Round 1 指摘）。
> route の指定を 1 か所変えると複数の gate が同時に反応するのが正常であり、
> それを「意図しない赤」と読み違えないよう **primary（この mutation で検証したい gate）と
> collateral（同時に赤くなるのが正しい gate）を分けて記録する**。
> 記録手順は **gate ごとにファイル単位で実行**する:
> `composer test -- --filter=InlineThrottleInventoryTest` のように 1 ファイルずつ走らせ、
> primary が赤・その理由メッセージが期待どおりであることを確認する。

| # | mutation | primary（検証対象の gate と検査名） | collateral（同時に赤くなるのが正しい） | primary の期待メッセージ |
|---|---|---|---|---|
| M1 | `routes/web.php` の `recent-auth.password` を `throttle:6,1` に戻す | `InlineThrottleInventoryTest`「inline throttle を持つ route は目録に登録されている」 | `ThrottleLaneAssignmentTest`（`password-verify` から 1 本消える）/ `AuthThrottleCoverageTest`（巻き添えが復活） | `recent-auth.password` が未登録として列挙される |
| M2 | `inlineThrottleInventory()` から `livewire.upload-file` を消す | 同上（未登録 1 件） | `InlineThrottleInventoryTest`「case 別件数が宣言値とちょうど一致」（1 → 0） | `livewire.upload-file` が列挙される |
| M2' | `inlineThrottleInventory()` に架空の route を 1 件足す | 「目録の key は現存する inline throttle route」 | 同「case 別件数がちょうど一致」 | stale として列挙される |
| M2'' | `InlineThrottleBucketRationale` に case を 1 つ足す（件数宣言はしない） | 「case 別件数が宣言値とちょうど一致」 | なし | 「件数がありません」 |
| M2''' | `inlineThrottleInventory()` から `passport.device.code` を消す | 「case 別件数が宣言値とちょうど一致」（`<=` では検出できない**減少方向**の検証） | 「inline throttle を持つ route は目録に登録されている」 | `vendor_stateless_ip_bucket: 1 件（宣言 2 件）` |
| M2'''' | `config/livewire.php` を置いて `livewire.upload-file` に session を張らせない（または `passport.token` を web group へ入れる） | 「分類 case の適用条件が実効 middleware 列と一致する」 | なし | 適用条件を満たさない旨 |
| M3 | `routes/web.php` の `settings.password.store` を `throttle:password-verify` に変える | `ThrottleLaneAssignmentTest`「割当が目録と完全一致する」 | `AuthThrottleCoverageTest`「パスワード照合レーンを使い切っても初回設定は…」 | `password-verify` に 4 本 / `password-set` に 0 本の差分 |
| M4 | 同 route を `throttle:password-sett`（typo）に変える | `ThrottleLaneAssignmentTest`「route に貼られた named limiter はすべて実在する」 | 同ファイル「割当が目録と完全一致」「レーンはすべて 1 本以上」/ 実 HTTP 系（`MissingRateLimiterException`） | `settings.password.store → password-sett` が列挙される |
| M5 | `password-set` limiter のキーを `RateLimiterKeys::actorOrIp($request, 'password-verify')` にする | `RateLimiterKeyConventionTest`「共有グループ外の limiter は互いにキーを共有しない」 | 同ファイル「expectedKeyPrefixes と完全一致」「actor/IP レーンの full key が宣言と完全一致」 | 2 limiter 名と共有キーが出る |
| M5' | `RateLimiterKeys::actorOrIp()` の種別を `:user:` → `:actor:` に変える | 同「actor/IP レーンの full key が宣言と完全一致」 | 同「expectedKeyPrefixes と完全一致」 | 8 レーンすべてで期待値と差分 |
| M6 | `rateLimiterSharedKeyGroups()` から `api-write` を外す | 同「共有グループ外の limiter は互いにキーを共有しない」 | なし | `api-read` と `api-write` の衝突 |
| M6' | `apiRateKey()` を `api-write` だけ別キーにする | 同「宣言した共有グループは実際にキーを共有している」 | 「expectedKeyPrefixes と完全一致」 | 死んだ宣言として列挙 |
| M7 | `RateLimiterKeys::actorOrIp()` の user 分岐を `is_scalar()` に戻す | `RateLimiterKeysTest`「bool / float のとき user 分岐へ落ちない」 | なし | 負のコントロールが赤 |
| M8 | `two-factor-manage` を `throttle:10,1` に戻す（binder の値） | `AuthThrottleCoverageTest`「2FA 管理レーンを使い切っても再認証・パスワード設定・メール検証は 429 にならない」 | `InlineThrottleInventoryTest`（未登録 4 本）/ `ThrottleLaneAssignmentTest`（レーンが空） | 再認証が巻き添えで 429 |
| M9-a | `recent-auth.password` から throttle を剥がし、**かつ** `expectNotThrottled()` の残数ヘッダ検査を外す | cross-lane probe が**緑のままになること**を観測（= ヘッダ検査が無いと false green になる証明） | `ThrottleCoverageInventoryTest`（throttle 0 本）/ `ThrottleLaneAssignmentTest` | 緑（これが問題の再現） |
| M9-b | M9-a の状態から**ヘッダ検査だけを戻す** | `AuthThrottleCoverageTest` の各 cross-lane probe | 同上 | 「X-RateLimit-* が無い = throttle が走っていない」 |
| M10 | 自前 controller（`App\Http\Controllers\...`）の web route に `throttle:9,1` を付け、`inlineThrottleInventory()` へ `VendorMixedUserOrIpBucket` で登録し件数宣言も 2 に上げる | `InlineThrottleInventoryTest`「分類 case の適用条件が実効 middleware 列と一致する」 | なし（登録済みなので「未登録」検査は通る） | vendor 名前空間由来でないため適用条件を満たさない旨。**「自前 route は vendor case へ登録できない」の証明** |

## 検査が空振りしないことの保証（まとめ）

| 保証 | 手段 |
|---|---|
| 分類器が inline / named を取り違えない | S5 の**負のコントロール**（両方向 6 ケース、`ThrottleRequestsWithRedis` を含む） |
| 母集団の走査が壊れて 0 件になっていない | S5「throttle を持つ route の総数が下限 40 を下回らない」＋「目録 key が現存する（0 件なら全 stale で fail）」 |
| 分類が作文で終わっていない | S5「分類 case の適用条件が実効 middleware 列と一致する」（premise 検査。**action の vendor 名前空間**も含む） |
| 「vendor だから許す」が主張だけになっていない | 同 premise が action class の名前空間（`Laravel\Passport\` / `Livewire\`）を検査。自前 controller はどの case にも当てはまらない（mutation M10 で確認） |
| Livewire の消費元が空振りしていない | S8「残数が 6 回で 5 減ること」（上限 60 のため N+1 → 429 では証明できない分を補う） |
| レーンが宣言だけで実体が無い状態でない | S6「目録のレーンはすべて 1 本以上の route を持つ」 |
| レーン名の typo が本番まで届かない | S6「route に貼られた named limiter はすべて実在する」（母集団は**全 route の named throttle**） |
| その typo 検査自体が空振りしていない | S6「named limiter を貼った route が 1 本以上ある」（下限 25） |
| 免除枠が形骸化しない | S5 の case 別件数が **exact fit（`!==` で照合）**。増加方向だけでなく**減少方向**も差分として現れる |
| 「分けたつもりで分かれていない」を検出 | S7 の pairwise 衝突検査＋共有グループの**死んだ宣言**検出 |
| キー helper の移行でキーが変わっていない | S7「actor/IP レーンの full key が宣言と完全一致する」（接頭辞ではなく **full key**）＋ 既存 `expectedKeyPrefixes` 検査を無変更で通すこと |
| 実挙動の probe が false green になっていない | S8 の `expectNotThrottled()` が **`X-RateLimit-Remaining` の存在**を必ず検査（throttle が走ったうえで通ったことを示す）。有効性は mutation M9 で確認 |
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
8. mutation 確認（**mutation 表の全項目** = M1 / M2 / M2' / M2'' / M2''' / M2'''' / M3 / M4 /
   M5 / M5' / M6 / M6' / M7 / M8 / M9-a / M9-b / M10）→ `gate-mutation-log.md` に primary / collateral を
   分けて記録し、すべて revert する
9. `composer test` / `composer phpstan` / `vendor/bin/pint --test` / `pnpm lint` / `pnpm typecheck` を green に

## 実装モード

| 項目 | 内容 |
|------|------|
| 推奨モード | **standalone** |
| 判断根拠 | (1) `routes/web.php` / `AppServiceProvider` / `FortifyServiceProvider` / `config/fortify.php` という**他の TODO と衝突しやすい中心ファイル**を同時に触る。(2) テストファースト（先に赤を観測してから実装）の手順が incremental だと成立しない。(3) 既存 gate（`RateLimiterKeyConventionTest`）が S2→S7 の間で一時的に赤になるため、他施策と混ぜると原因の切り分けができない |
| 競合リスク | T124（2FA 秘密 GET の recent-auth 化）は `FortifyServiceProvider` の recent-auth 配線と `routes/web.php` を触るため**行レベルで競合しうる**。T124 と本 TODO は同時に走らせず、どちらかを先にマージしてから rebase する。`throttle` 指定そのものは T124 の対象外（T124 は middleware `recent-auth` の付与）なので、意味的な競合は無い |
| デプロイ順序の要件 | 無し（`route:cache` の毎デプロイ再生成という既存要件のみ。新しい環境変数・マイグレーション・設定の事前投入は不要） |


---

## 最終判定のお願い

Round 2 の [Warning] が解消されているかを確認し、施策別判定と全体判定
（APPROVED / CHANGES_REQUESTED）を出してください。特に以下を見てください。

1. vendor provenance の premise（action 名前空間 + `AuthenticatesRequests` 不在 / `StartSession` の有無）で
   「vendor 限定」が十分に機械化されたか。残るすり抜けがあるとすれば何か。
2. Livewire 消費元の「6 回で残数が 5 減る」検査で空振りが閉じたか。
3. mutation 表（17 項目）に、**書いた gate のうち mutation で確認されないもの**が残っていないか。
4. 実装順序（S8 を先に書いて赤を観測 → S1 → S2/S3 → S7 → S4 → S5/S6 → S9 → mutation）に
   手戻りを生む順序ミスがないか。
