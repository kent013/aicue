## 使命 (North Star)

<!-- app-codex-review スキルはこのセクションをレビュー使命として参照する。 -->

**AI-CUE** は、現場に既にある**作業手順書(SOP)を起点に**、AI が撮るべきカットを設計した**動画シナリオ**を生成し、そのシナリオを**スマホ(PWA)でナビゲーション撮影**することで、専門知識ゼロの現場作業者でも**標準化されたマニュアル動画**を作れるようにする。

- 「思考ゼロ・編集ゼロ」— 台本作成・撮影判断・編集の 3 ハードルを AI とナビ撮影で肩代わりする。
- 競合(OJT を撮って形式化する tebiki)と異なり、**標準作業を起点に AI が教材設計し撮影を指示する**（撮影者・教える人のスキルに品質を依存させない）。
- 熟練者の暗黙知を動画マニュアルという形式知へ変換する装置（SECI）。

> 詳細な設計は本リポジトリの `doc/`（01〜10）に置く。ドメインをテンプレート構造へどうマップしたかは `doc/08`〜`doc/10`（特に `doc/10 §10.8` に設計レビュー反映済み）。
> **v1 スコープ**: 字幕のみ(TTS 後回し) / 撮影は PWA(同一オリジン・セッション認証) / 動画合成は自前 ffmpeg / 単一 Default Project。

## 思考原則

1. **フレームワークのレンジ内でやる**。自前機構の前に Laravel / 同梱モジュールの公式作法を確認する
2. **今必要なものだけ作る**(オーバーエンジニアリング禁止。「あったら便利」は作らない)
3. **後方互換の並走を残さない**。書き換えると決めたら同じ PR で旧実装を消す
4. **別物の概念を「似ているから」で統合しない**
5. **テストファースト**。fail を確認してから実装に入る
6. **タコツボ実装を避ける**。各ステップで他要素との結合観点を確認する

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

（アプリの使命・禁止事項は上記に挿入済み）

【前提環境】
- PHP 8.4 + Laravel 12 + Svelte 5 + Inertia.js + TypeScript
- PHPStan level 10
- Pestテストフレームワーク
- DTO + JsonResource パターン
- Laratrust RBAC（Organization → Team → Project階層）

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
10. DESIGN.md準拠（UI/frontend 変更を含む場合）: `/DESIGN.md` が design token の canonical source。color / radius / typography を token 経由で参照する設計か、hex 直書きを増やさないか。token 変更時は `resources/css/tokens.css` との同期を設計に織り込んでいるか（運用契約は `docs/design-system.md`）
11. Atomic Design準拠（UI/frontend 変更を含む場合）: `resources/js/components/` の `atoms/molecules/organisms/templates` の責務分離に沿った配置か。atom は単機能・無状態、molecule は atom の組合せという階層を逆流していないか。アイコンは Lucide 前提で、SVG 直書きを新設していないか

【出力形式】
- 各施策ごとに判定: APPROVE / REQUEST_CHANGES
- 指摘は [Critical] [Warning] [Suggestion] で分類
- Critical/Warning には必ず修正案を添える
- 全体判定: APPROVED / CHANGES_REQUESTED
- 日本語で出力

---
## 詳細設計書

# 詳細設計: inertia-error-screen-contract

> lctl feature id: `error-response-contract` / 裁定 (b) 「画面遷移 (Inertia XHR) のサーバ側統一」限定。
> 概念設計: [`conceptual-design.md`](./conceptual-design.md) (Codex 概念レビュー Round 1〜5 反映済み)
> 実査ブリーフ: [`recon-brief.md`](./recon-brief.md)

## 使命・制約 (絶対遵守)

### アプリの使命 (North Star)

**AI-CUE** は、現場に既にある**作業手順書(SOP)を起点に**、AI が撮るべきカットを設計した**動画シナリオ**を生成し、そのシナリオを**スマホ(PWA)でナビゲーション撮影**することで、専門知識ゼロの現場作業者でも**標準化されたマニュアル動画**を作れるようにする。

- 「思考ゼロ・編集ゼロ」— 台本作成・撮影判断・編集の 3 ハードルを AI とナビ撮影で肩代わりする。
- 競合(OJT を撮って形式化する tebiki)と異なり、**標準作業を起点に AI が教材設計し撮影を指示する**（撮影者・教える人のスキルに品質を依存させない）。
- 熟練者の暗黙知を動画マニュアルという形式知へ変換する装置（SECI）。

> **v1 スコープ**: 字幕のみ(TTS 後回し) / 撮影は PWA(同一オリジン・セッション認証) / 動画合成は自前 ffmpeg / 単一 Default Project。

### 思考原則

1. **フレームワークのレンジ内でやる**。自前機構の前に Laravel / 同梱モジュールの公式作法を確認する
2. **今必要なものだけ作る**(オーバーエンジニアリング禁止。「あったら便利」は作らない)
3. **後方互換の並走を残さない**。書き換えると決めたら同じ PR で旧実装を消す
4. **別物の概念を「似ているから」で統合しない**
5. **テストファースト**。fail を確認してから実装に入る
6. **タコツボ実装を避ける**。各ステップで他要素との結合観点を確認する

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

### 本設計が特に依存するセキュリティ不変条件

- **子は親に属する / cross-org 不可**: テナント境界 404 は認可より前。差し替え後も
  「cross-org 実在」と「不在」の応答が分岐しないこと (`TenantBoundaryPrecedenceTest`)
- **層 2 は binding 直後・FormRequest より前**: 差し替えはレンダリング層のみで、middleware の
  実行順 (`bootstrap/app.php` の priority list) に一切触れない
- **ドメイン規約 3 (履歴復元 3 枚セット)**: `AuthenticationException` render callback の
  `Inertia::clearHistory()` + null 返し契約を壊さない (`InertiaHistoryGuardTest`)

### コーディングルール

- **PHPStan level 10** 必須 (`composer phpstan`)。`@phpstan-ignore` / baseline 化は禁止
- **Pest** テストフレームワーク (`composer test`)
- **RefreshDatabase** + `--parallel` 並列実行 (`tests/Pest.php` でグローバル適用、
  個別 `DatabaseTransactions` 使用禁止)
- **テストデータは必ず Factory で生成** (`Model::create()` 手組み禁止)
- **DTO + JsonResource** パターン。Inertia props は DTO の `toInertiaProps()` 経由
- **アーリーリターン**推奨
- `declare(strict_types=1)` + 日本語コメント。Controller は薄く (Service 委譲)
- フロントは Svelte 5 runes + DS token/ramp のみ (`DESIGN.md` canonical、ds-purity が検出)。
  component 階層は `atoms → molecules → organisms → features → templates → pages` の単方向 import。
  アイコンは `@lucide/svelte` のみ
- **コードフォーマット**: `composer fix` (Pint) / `pnpm lint:fix`
- PHP 8.4 + Laravel 12 + Svelte 5 + Inertia.js (inertia-laravel v3.1.0 / @inertiajs/core 3.3.1) + TypeScript
- 検証: `composer test` / `composer phpstan` / `vendor/bin/pint --test` / `pnpm lint` /
  `pnpm typecheck` / `pnpm test` / `pnpm build`

## 概念設計リファレンス

- [`devnotes/20260807-1235-inertia-error-screen-contract/conceptual-design.md`](./conceptual-design.md)
- Codex 概念レビュー: `conceptual-review-round-{1..5}.md` / 対応マトリクス:
  `codex-history/conceptual-review-decisions-round-{1..5}.md`

## 施策一覧

| # | 施策名 | 変更ファイル | 優先度 |
|---|--------|------------|--------|
| S1 | 目録の型 (差し替え対象 status / 素通し理由の enum) を新設 | `app/Enums/Http/InertiaErrorScreenStatus.php` (新) / `app/Enums/Http/InertiaErrorScreenPassthrough.php` (新) | High |
| S2 | `Retry-After` パースを共有 SoT へ一本化 | `app/Support/Http/RetryAfterSeconds.php` (新) / `app/Exceptions/ApiExceptionRenderer.php` (変更) | High |
| S3 | 戻り先のサーバ固定許可一覧と props DTO | `app/Support/Http/ErrorScreenDestination.php` (新) / `app/Support/Http/ErrorScreenDestinations.php` (新) / `app/DataTransferObjects/Http/ErrorScreenData.php` (新) | High |
| S4 | Inertia 例外差し替え本体と bootstrap 配線 | `app/Exceptions/InertiaExceptionRenderer.php` (新) / `bootstrap/app.php` (変更) | Critical |
| S5 | Error ページ (Svelte / TS 型 / resolver eager 化) | `resources/js/pages/Error.svelte` (新) / `resources/js/types/error-screen.ts` (新) / `resources/js/inertia.ts` (変更) | Critical |
| S6 | deny-by-default 目録 gate (PHP + JS) | `tests/Architecture/InertiaErrorScreenContractTest.php` (新) / `tests/js/architecture/inertia-eager-error-page.test.ts` (新) | High |

> S1 → S2 → S3 → S4 → S5 → S6 の順で実装する (S4 は S1〜S3 に依存、S6 は全部に依存)。

---

## S1: 目録の型 (差し替え対象 status / 素通し理由の enum) を新設

### 変更箇所

- 新規: `app/Enums/Http/InertiaErrorScreenStatus.php`
- 新規: `app/Enums/Http/InertiaErrorScreenPassthrough.php`

### 波及変更

- TypeScript 型定義: **なし** (status は数値として props に載る。S5 で `number` として型定義)
- API Resource/DTO: S3 の `ErrorScreenData` が `InertiaErrorScreenStatus` を保持する (S3 で定義)
- テストファイル: `tests/Architecture/InertiaErrorScreenContractTest.php` (S6 で新規)

### 現行コード

該当なし (新規)。分類 enum の作法は `app/Enums/Security/ThrottleCoverageExemption.php` を見本にする
(「汎用に見えるものほど適用条件を狭く定義する」「当てはまる case が無ければそれは対象である」)。

### 変更後コード

```php
<?php

declare(strict_types=1);

namespace App\Enums\Http;

/**
 * Inertia XHR (X-Inertia 付き) の例外応答を Error 画面へ差し替える status の目録。
 *
 * **deny-by-default**: ここに無い status は差し替えず、既存の自己完結 Blade
 * (resources/views/errors/*.blade.php) をそのまま返す。追加は
 * tests/Architecture/InertiaErrorScreenContractTest.php の inventory へ
 * 30 文字以上の根拠を書くことと同時にしか行えない (exact-fit cap がある)。
 */
enum InertiaErrorScreenStatus: int
{
    case Forbidden = 403;
    case NotFound = 404;
    case PageExpired = 419;
    case TooManyRequests = 429;
    case ServerError = 500;
    case ServiceUnavailable = 503;

    /** 画面見出し (中立文言。存在や権限の詳細を漏らさない)。 */
    public function title(): string
    {
        return match ($this) {
            self::Forbidden => 'この操作を行う権限がありません',
            self::NotFound => 'ページが見つかりません',
            self::PageExpired => 'セッションの有効期限が切れました',
            self::TooManyRequests => 'しばらく時間をおいてください',
            self::ServerError => '問題が発生しました',
            self::ServiceUnavailable => 'ただいまメンテナンス中です',
        };
    }

    /** 本文 (次に何をすればよいかだけを書く)。 */
    public function message(): string
    {
        return match ($this) {
            self::Forbidden => 'アクセス権限が必要な画面です。別の画面からお試しください。',
            self::NotFound => 'お探しのページは存在しないか、移動された可能性があります。',
            self::PageExpired => 'お手数ですが、ログインし直してから操作をやり直してください。',
            self::TooManyRequests => 'リクエストが続けて行われました。少し時間をおいてからお試しください。',
            self::ServerError => '一時的な問題が発生しました。時間をおいてもう一度お試しください。',
            self::ServiceUnavailable => 'ただいま作業中です。時間をおいてもう一度お試しください。',
        };
    }

    /** 待ち時間 (Retry-After) を画面に出す status か。 */
    public function showsRetryAfter(): bool
    {
        return $this === self::TooManyRequests || $this === self::ServiceUnavailable;
    }

    /**
     * 認証状態にかかわらず「ログイン + トップ」を戻り先にする status か (戻り先規則 D1)。
     *
     * ★419 は CSRF token 不一致でも起きるため「認証済みのまま 419」がありうる。
     *   その状態でダッシュボードへ戻しても同じ token 不一致を踏み直すだけで詰みが再生産される。
     *   セッションと token を取り直せる導線が唯一の確実な脱出路である。
     */
    public function forcesGuestDestinations(): bool
    {
        return $this === self::PageExpired;
    }

    /** 5xx (app.debug 中は差し替えない判定に使う)。 */
    public function isServerError(): bool
    {
        return $this->value >= 500;
    }
}
```

```php
<?php

declare(strict_types=1);

namespace App\Enums\Http;

/**
 * Inertia XHR の例外応答を **Error 画面へ差し替えなかった理由**の分類。
 *
 * `App\Exceptions\InertiaExceptionRenderer::passthroughReason()` が唯一の生成点で、
 * null (= 差し替える) 以外はすべて本 enum の case になる。
 *
 * ★**未使用 case を残さない**。tests/Feature/Errors/InertiaErrorScreenPassthroughTest.php が
 *   「全 case が実際に生成されること」を behavioral に固定する (死んだ分類を作らない)。
 *   分類が当てはまらない応答は「差し替えてよい応答」である。
 */
enum InertiaErrorScreenPassthrough: string
{
    /** status が 400 未満 (2xx / 3xx)。Location を持つ遷移や成功応答を触らない。 */
    case SuccessOrRedirectStatus = 'success_or_redirect_status';

    /** api/* または expectsJson。(c) の統一エラー封筒 JSON が正しい応答形。 */
    case MachineReadableEnvelope = 'machine_readable_envelope';

    /** admin panel 配下。運営者向け中立テンプレート (errors.admin.*) が正しい応答形。 */
    case OperatorFacingSurface = 'operator_facing_surface';

    /** X-Inertia を持たないフルロード。自己完結 Blade が最後の砦として正しい。 */
    case NonInertiaRequest = 'non_inertia_request';

    /**
     * リクエストの X-Inertia-Version が現在の asset version と一致しない
     * (欠落・空文字・現 version が空も含む)。
     * 旧 bundle のタブには Error ページが存在せず、resolver が throw して SPA が無反応になる。
     */
    case StaleAssetVersion = 'stale_asset_version';

    /** Location / X-Inertia-Location を持つ応答。Inertia 手順上の遷移と外部遷移を壊さない。 */
    case InertiaProtocolRedirect = 'inertia_protocol_redirect';

    /** InertiaErrorScreenStatus に未登録の status (409 / 422 等)。deny-by-default の既定。 */
    case UnlistedStatus = 'unlisted_status';

    /** 5xx かつ app.debug=true。開発時に例外詳細ページを中立文言で潰さない。 */
    case DebugServerError = 'debug_server_error';
}
```

### PHPStan 適合チェック

- [x] 戻り値の型が明示されている (`string` / `bool`)
- [x] null 安全 (`match` は全 case 網羅。default 節を置かないので case 追加時に PHPStan が検出)
- [x] DTO を返している (配列返却なし。enum のみ)
- [x] Generics の型パラメータが正しい (該当なし)

### テスト計画

- [ ] 新規 `tests/Architecture/InertiaErrorScreenContractTest.php` (S6 で本体を書く) が
      本 enum の全 case を inventory と突き合わせる
- [ ] 新規 `tests/Unit/Http/InertiaErrorScreenStatusTest.php`
  - `it('全 case が空でない title と message を持つ')` — 全 case を `->with()` で回し、
    `title()` / `message()` が空文字でないこと (文言未定義で白画面になる退行の検出)
  - `it('待ち時間を出す status は 429 と 503 だけ')` — `showsRetryAfter()` の真偽を全 case で固定
  - `it('D1 (認証状態を問わずログインへ) が適用されるのは 419 だけ')` —
    `forcesGuestDestinations()` の真偽を全 case で固定
  - `it('isServerError は 500 以上でだけ真')`
- [ ] 個別の `DatabaseTransactions` を使っていないことを確認 (Unit テストは DB 不使用)

### リスク

- **文言の追加漏れ**: 将来 status を足したとき `match` に case を足し忘れると PHPStan が
  「網羅していない」と検出する (default 節を置かない設計がこれを担保)。
- **enum 値と HTTP status の乖離**: backed enum の値をそのまま HTTP status に使うため、
  値を書き換えると意味が変わる。Architecture gate の inventory がキーとして status を持つので
  変更は必ず差分として現れる。

---

## S2: `Retry-After` パースを共有 SoT へ一本化

### 変更箇所

- 新規: `app/Support/Http/RetryAfterSeconds.php`
- 変更: `app/Exceptions/ApiExceptionRenderer.php` (L125-143 `rateLimitDetails()`)

### 波及変更

- TypeScript 型定義: **なし** (API 封筒の `details.retry_after` は既存の型定義を持たない。
  Error 画面側の `retryAfterSeconds` は S5 で新規定義)
- API Resource/DTO: `App\Support\Api\ApiError` の `details` の**中身**が変わりうる
  (不正形式のときキー自体が出なくなる)。`ApiErrorResource` のシグネチャは不変
- テストファイル: 新規 `tests/Feature/Api/ApiRetryAfterContractTest.php` /
  新規 `tests/Unit/Http/RetryAfterSecondsTest.php`

### 現行コード

`app/Exceptions/ApiExceptionRenderer.php` L125-143:

```php
    /**
     * @return array<string, mixed>|null
     */
    private static function rateLimitDetails(HttpExceptionInterface $e): ?array
    {
        $headers = $e->getHeaders();
        if (! isset($headers['Retry-After'])) {
            return null;
        }
        $retryAfter = $headers['Retry-After'];
        if (is_string($retryAfter) && ctype_digit($retryAfter)) {
            return ['retry_after' => (int) $retryAfter];
        }
        if (is_int($retryAfter) || is_string($retryAfter)) {
            return ['retry_after' => $retryAfter];
        }

        return null;
    }
```

**問題**: 2 つ目の分岐が HTTP-date 文字列 (`"Wed, 21 Oct 2015 07:28:00 GMT"`) や負数文字列
(`"-5"`) をそのまま `details.retry_after` に載せる。裁定が要求する
「非負整数のみ採り解釈不能なら非表示」と一致していない。

### 変更後コード

```php
<?php

declare(strict_types=1);

namespace App\Support\Http;

/**
 * `Retry-After` ヘッダ値 → 待ち時間 (秒) の唯一の解釈点。
 *
 * 裁定 (error-response-contract): **非負整数のみ採り、解釈不能なら非表示**。
 * HTTP 仕様上 Retry-After は delta-seconds と HTTP-date の 2 形式を採りうるが、
 * 本アプリの発行元は Laravel の ThrottleRequests (常に delta-seconds) だけであり、
 * HTTP-date を「秒数」として画面や API 封筒に載せる意味が無い。
 *
 * 利用点は 3 つで、すべて本クラスを通る (二重解釈を作らない):
 *   1. API 封筒 JSON の details.retry_after (ApiExceptionRenderer)
 *   2. Error 画面の retryAfterSeconds prop (InertiaExceptionRenderer)
 *   3. 差し替え応答の Retry-After ヘッダ (InertiaExceptionRenderer が正規化して再設定)
 */
final class RetryAfterSeconds
{
    /**
     * @return int<0, max>|null 解釈できない値は null
     */
    public static function parse(mixed $value): ?int
    {
        if (is_int($value)) {
            return $value >= 0 ? $value : null;
        }

        if (! is_string($value) || $value === '' || ! ctype_digit($value)) {
            // 負数 ("-5") / HTTP-date / 任意文字列 / 空文字 / それ以外の型はここで落ちる
            return null;
        }

        $seconds = (int) $value;

        // ctype_digit が真なら非負だが、PHPStan に int<0, max> を認識させるため明示する
        return $seconds >= 0 ? $seconds : null;
    }
}
```

`app/Exceptions/ApiExceptionRenderer.php` (差分):

```php
use App\Support\Http\RetryAfterSeconds;

    /**
     * @return array{retry_after: int<0, max>}|null
     */
    private static function rateLimitDetails(HttpExceptionInterface $e): ?array
    {
        $seconds = RetryAfterSeconds::parse($e->getHeaders()['Retry-After'] ?? null);
        if ($seconds === null) {
            return null;
        }

        return ['retry_after' => $seconds];
    }
```

> 呼び出し側 `toApiError()` の `details: self::rateLimitDetails($e)` は
> `ApiError` の `details` が `array<string, mixed>|null` を受けるため型は互換。
> 戻り型を `array{retry_after: int<0, max>}|null` に**狭めた**ので level 10 でも通る。

### PHPStan 適合チェック

- [x] 戻り値の型が明示されている (`?int` + `@return int<0, max>|null`)
- [x] null 安全 (`mixed` を受けて型判定を先に行う。`Assert` は不要 = 例外化せず null で返す契約)
- [x] DTO を返している (配列返却は `ApiError::details` の shape に限定し、型を狭めた)
- [x] Generics の型パラメータが正しい (該当なし)
- [x] `@phpstan-ignore` を使わない

### テスト計画

- [ ] 新規 `tests/Unit/Http/RetryAfterSecondsTest.php` (DB 不使用)
  - `it('非負整数と整数文字列を秒数として採る')` — `->with([[60, 60], ['60', 60], ['0', 0], [0, 0]])`
  - `it('負数は解釈しない')` — `->with([[-5, null], ['-5', null]])`
  - `it('HTTP-date と任意文字列は解釈しない')` —
    `->with([['Wed, 21 Oct 2015 07:28:00 GMT', null], ['soon', null], ['1.5', null], ['', null]])`
  - `it('int / string 以外の型は解釈しない')` — `->with([[null, null], [[60], null], [true, null]])`
- [ ] 新規 `tests/Feature/Api/ApiRetryAfterContractTest.php` (API 封筒の回帰固定)
  - `it('429 の Retry-After が整数のとき details.retry_after に int で載る')` —
    `RateLimiter::for` を差し替えず、`Route::get` をテスト内で定義するのではなく
    **既存の api throttle 経路を叩いて 429 を作る**のが望ましいが、閾値まで連打するのは遅い。
    代わりに `$this->app->bind()` ではなく `ApiExceptionRenderer::render()` を直接呼ぶ
    **契約テスト**にする: `new TooManyHttpRequestsException(retryAfter: 60)` 相当の
    `HttpException(429, headers: ['Retry-After' => '60'])` を渡し、JSON 本文を検証
  - `it('Retry-After が HTTP-date のとき details を出さない (厳格化)')`
  - `it('Retry-After が負数のとき details を出さない (厳格化)')`
  - `it('Retry-After が未設定のとき details を出さない')`
  - いずれも `$request = Request::create('/api/v1/items', 'GET')` で `api/*` を満たす
- [ ] 既存テストの更新: `tests/Feature/Api/` 配下に `retry_after` を検証する既存テストがあれば
      期待値を確認する (実査時点では 429 の details を固定する既存テストは無い。実装時に
      `rg 'retry_after' tests/` で再確認し、あれば同一 PR で追随させる)
- [ ] 個別の `DatabaseTransactions` を使っていないことを確認

### リスク

- **(c) の契約を厳格化する**: 不正形式の `Retry-After` は `details.retry_after` に出なくなる。
  現在の発行元 (Laravel `ThrottleRequests`) は常に非負整数秒であり実挙動は変わらないが、
  外部の API 利用者が「キーが必ずある」前提で実装していると影響しうる。
  → **緩和**: 元々 `retry_after` は `details` (optional) の中にあり、
  現行でも「`Retry-After` ヘッダが無ければキーごと出ない」ため、
  「キーが必ずある」前提は元から成立していない。
- **将来 HTTP-date を発行する経路が入ったとき**: 秒数に変換されず非表示になる。
  クラスの docblock に「発行元は delta-seconds のみ」という前提を明記し、
  前提が変わったらここを見直すことを残す。

---

## S3: 戻り先のサーバ固定許可一覧と props DTO

### 変更箇所

- 新規: `app/Support/Http/ErrorScreenDestination.php`
- 新規: `app/Support/Http/ErrorScreenDestinations.php`
- 新規: `app/DataTransferObjects/Http/ErrorScreenData.php`

### 波及変更

- TypeScript 型定義: `resources/js/types/error-screen.ts` (S5 で新規。本 DTO の
  `toInertiaProps()` の array shape と 1:1 対応)
- API Resource/DTO: **本施策が DTO 本体**。JsonResource は使わない
  (Inertia props は JsonResource ではなく DTO の `toInertiaProps()` が正本)
- テストファイル: 新規 `tests/Unit/Http/ErrorScreenDestinationsTest.php` /
  新規 `tests/Unit/Http/ErrorScreenDataTest.php`

### 現行コード

該当なし (新規)。**既存の `app/Support/Http/SameOriginPath.php` は使わない** —
referer / intended を正規化して**通す**ヘルパであり、裁定が要求する
「サーバ側に固定した許可一覧」ではない (リクエスト入力が混ざる)。

### 変更後コード

```php
<?php

declare(strict_types=1);

namespace App\Support\Http;

/**
 * Error 画面の戻り先 1 件。**サーバ側で固定した値しか入らない**
 * (referer / intended / query / route parameter を一切読まない = open redirect が構造的に不成立)。
 */
final readonly class ErrorScreenDestination
{
    public function __construct(
        public string $label,
        public string $href,
    ) {}

    /** @return array{label: string, href: string} */
    public function toArray(): array
    {
        return ['label' => $this->label, 'href' => $this->href];
    }
}
```

```php
<?php

declare(strict_types=1);

namespace App\Support\Http;

use App\Enums\Http\InertiaErrorScreenStatus;

/**
 * Error 画面の戻り先を決める唯一の点。裁定 (error-response-contract) の
 * 「戻り先はサーバ側に固定した許可一覧から出しリクエスト入力を混ぜない」を満たす。
 *
 * **入力は status と認証状態の 2 つだけ**。適用順序 (上が優先):
 *   D1: status が 419  → ログイン + トップ (**認証状態を問わない**)
 *   D2: 認証済み        → ダッシュボード + トップ
 *   D3: 未認証          → ログイン + トップ
 *
 * D1 が D2 より先である理由: 419 は CSRF token 不一致でも起きるため「認証済みのまま 419」が
 * ありうる。その状態でダッシュボードへ戻しても同じ token 不一致を踏み直すだけで詰みが
 * 再生産される。セッションと token を取り直せる導線が唯一の確実な脱出路である。
 *
 * href は **相対 path** で返す (route(..., absolute: false))。host を含めないことで、
 * proxy 構成に依らず同一オリジンに閉じ、応答が host によって変わらない。
 */
final class ErrorScreenDestinations
{
    /**
     * @return non-empty-list<ErrorScreenDestination>
     */
    public static function for(InertiaErrorScreenStatus $status, bool $authenticated): array
    {
        if ($status->forcesGuestDestinations()) {
            return self::guest();
        }

        if ($authenticated) {
            return [
                new ErrorScreenDestination('ダッシュボードへ', route('dashboard', absolute: false)),
                self::home(),
            ];
        }

        return self::guest();
    }

    /** @return non-empty-list<ErrorScreenDestination> */
    private static function guest(): array
    {
        return [
            new ErrorScreenDestination('ログインへ', route('login', absolute: false)),
            self::home(),
        ];
    }

    private static function home(): ErrorScreenDestination
    {
        return new ErrorScreenDestination('トップへ', '/');
    }
}
```

```php
<?php

declare(strict_types=1);

namespace App\DataTransferObjects\Http;

use App\Enums\Http\InertiaErrorScreenStatus;
use App\Support\Http\ErrorScreenDestination;
use Webmozart\Assert\Assert;

/**
 * Error ページ (resources/js/pages/Error.svelte) の props。
 *
 * **props 生成の唯一の入口**は toInertiaProps()。呼び出し側が配列を手組みしないこと
 * (TS 側 resources/js/types/error-screen.ts と 1:1 で保守する)。
 *
 * 共有 props (HandleInertiaRequests::share) には依存しない。例外はテナント guard 404 のように
 * middleware が走る前にも起きるため、Error 画面が必要とする値はすべてここに入れる。
 *
 * @phpstan-type ErrorScreenPropsShape array{
 *   status: int,
 *   title: string,
 *   message: string,
 *   retryAfterSeconds: int<0, max>|null,
 *   destinations: non-empty-list<array{label: string, href: string}>
 * }
 */
final readonly class ErrorScreenData
{
    /**
     * @param  int<0, max>|null  $retryAfterSeconds
     * @param  non-empty-list<ErrorScreenDestination>  $destinations
     */
    public function __construct(
        public InertiaErrorScreenStatus $status,
        public ?int $retryAfterSeconds,
        public array $destinations,
    ) {
        // 型 (non-empty-list) は静的な約束にすぎないため、実行時にも空を拒否する。
        // 戻り先ゼロの Error 画面は「押せる導線が無い画面」= 詰みそのもの (禁止事項 8 の精神)。
        Assert::minCount($destinations, 1, 'Error 画面の戻り先は 1 件以上必要です');
    }

    /** @return ErrorScreenPropsShape */
    public function toInertiaProps(): array
    {
        return [
            'status' => $this->status->value,
            'title' => $this->status->title(),
            'message' => $this->status->message(),
            'retryAfterSeconds' => $this->retryAfterSeconds,
            'destinations' => array_map(
                static fn (ErrorScreenDestination $destination): array => $destination->toArray(),
                $this->destinations,
            ),
        ];
    }
}
```

> `array_map` に `non-empty-list` を渡すと PHPStan は `non-empty-list` を維持するため、
> shape 側の `non-empty-list<array{...}>` は追加の Assert 無しで成立する。

### PHPStan 適合チェック

- [x] 戻り値の型が明示されている (`non-empty-list<...>` / `ErrorScreenPropsShape`)
- [x] null 安全 (`Webmozart\Assert\Assert::minCount` で実行時も空を拒否)
- [x] DTO を返している (配列は `toInertiaProps()` の 1 箇所のみ、shape 固定)
- [x] Generics の型パラメータが正しい (`non-empty-list<ErrorScreenDestination>`)
- [x] `route(..., absolute: false)` は `string` を返す (level 10 で `mixed` にならない)

### テスト計画

- [ ] 新規 `tests/Unit/Http/ErrorScreenDestinationsTest.php` (DB 不使用。`route()` は
      アプリ boot 済みで解決できる)
  - `it('419 は認証状態にかかわらずログインへ倒れる (D1 が D2 より先)')` —
    `authenticated: true` / `false` の両方で 1 件目の href が `/login` であること
  - `it('419 以外は認証済みならダッシュボードへ倒れる')` —
    403/404/429/500/503 を `->with()` で回し、1 件目の href が `/dashboard`
  - `it('419 以外は未認証ならログインへ倒れる')` — 同上で `/login`
  - `it('全 status × 認証状態で戻り先が 1 件以上ある')` —
    `InertiaErrorScreenStatus::cases()` × `[true, false]` の 12 通りで `count >= 1`
    (禁止事項 8 の「押せない CTA を作らない」の機械化)
  - `it('href が相対 path で同一オリジンに閉じている')` — 全 href が `/` 始まりで
    `//` 始まりでないこと (open redirect の構造的不成立を behavioral に固定)
- [ ] 新規 `tests/Unit/Http/ErrorScreenDataTest.php`
  - `it('戻り先が空だと構築を拒否する')` — `new ErrorScreenData(..., destinations: [])` が
    `InvalidArgumentException` を投げること
  - `it('toInertiaProps が固定の shape を返す')` — キー集合が
    `['status','title','message','retryAfterSeconds','destinations']` に一致し、
    `destinations` の各要素が `['label','href']` だけを持つこと
    (TS 型との 1:1 対応が崩れたら赤くなる)
- [ ] 個別の `DatabaseTransactions` を使っていないことを確認 (Unit テスト。Factory 不使用)

### リスク

- **route 名の消滅**: `route('dashboard')` / `route('login')` が rename されると
  `RouteNotFoundException` になる。S4 の renderer が全段 try/catch で受けるため
  「Error 画面が出ない (= Blade にフォールバック)」で済み、二次障害にはならない。
  加えて上記 Unit テストが route 名の消滅を直接検出する。
- **文言の重複**: 戻り先ラベルは `ErrorScreenDestinations` にしかなく、二重管理は無い。

---

## S4: Inertia 例外差し替え本体と bootstrap 配線

### 変更箇所

- 新規: `app/Exceptions/InertiaExceptionRenderer.php`
- 変更: `bootstrap/app.php` L356-381 (既存の**単一** `$exceptions->respond()` callback)

### 波及変更

- TypeScript 型定義: **なし** (S5 で新規作成する型を参照するだけ)
- API Resource/DTO: `ErrorScreenData` (S3) を使う。**新規の JsonResource は作らない**
  (Inertia props は JsonResource の担当ではない)
- Inertia Props インターフェース: `resources/js/pages/Error.svelte` の Props (S5)
- テストファイル:
  - 新規 `tests/Feature/Errors/InertiaErrorScreenTest.php`
  - 新規 `tests/Feature/Errors/InertiaErrorScreenPassthroughTest.php`
  - 既存 `tests/Feature/Errors/ErrorPagesTest.php` に **admin の HTTP 経路**ケースを追加
    (既存は `view()->render()` 直叩きのため respond callback の退行を検出できない)
  - 既存 `tests/Feature/Security/TenantBoundaryPrecedenceTest.php` は**変更しない**
    (stale version を送るため差し替え対象外。無改修で green)
  - 既存 `tests/Feature/Security/InertiaHistoryGuardTest.php` は**変更しない**
    (ログアウト着地は 302/200 で status < 400 = 素通し)

### 現行コード

`bootstrap/app.php` L350-381:

```php
        // REST API v1 の統一エラー envelope ({error: {code, message, status, details?}})。
        // api/* 以外 (web / Inertia) は null を返して既定レンダリングを保つ
        $exceptions->render(function (Throwable $exception, Request $request) {
            return ApiExceptionRenderer::render($exception, $request);
        });

        // /admin (Filament 運営) 配下の error は運用者向け中立テンプレートへ分離する
        // (顧客向けマーケ文言の errors/4xx.blade.php を出さない = customer-facing と
        // operator-facing の error ページを分離)。判定は AdminPanelPath::resolve() に一本化。
        // API/JSON 経路は不変 (ApiExceptionRenderer が先に JSON 化する)。
        $exceptions->respond(function (Response $response, Throwable $exception, Request $request) {
            $status = $response->getStatusCode();
            if ($status < 400 || $request->is('api/*') || $request->expectsJson()) {
                return $response;
            }

            $adminPath = AdminPanelPath::resolve();
            $isAdminPath = $request->is($adminPath) || $request->is($adminPath.'/*');
            if (! $isAdminPath) {
                return $response;
            }

            $adminView = $status >= 500 ? 'errors.admin.5xx' : 'errors.admin.4xx';
            if (! view()->exists($adminView)) {
                return $response;
            }

            return response()->view($adminView, [
                'status' => $status,
                'adminPath' => $adminPath,
            ], $status);
        });
```

**決定的な制約**: `Illuminate\Foundation\Configuration\Exceptions::respond()` は
`Handler::respondUsing()` に落ち、その実体は

```php
protected $finalizeResponseCallback;                                   // Handler.php:149
public function respondUsing($callback) { $this->finalizeResponseCallback = $callback; }  // Handler.php:751
```

= **単一スロットの last-write-wins**。2 本目の `$exceptions->respond()` を足すと
**この admin 分離が黙って無効化される**。同じ理由で inertia-laravel v3 の
`Inertia::handleExceptionsUsing()` (`ResponseFactory.php:397` で内部的に `respondUsing()` を呼ぶ)
も使わない。したがって**既存の 1 本を拡張する**。

### 変更後コード

新規 `app/Exceptions/InertiaExceptionRenderer.php`:

```php
<?php

declare(strict_types=1);

namespace App\Exceptions;

use App\DataTransferObjects\Http\ErrorScreenData;
use App\Enums\Http\InertiaErrorScreenPassthrough;
use App\Enums\Http\InertiaErrorScreenStatus;
use App\Http\Middleware\HandleInertiaRequests;
use App\Support\Http\AdminPanelPath;
use App\Support\Http\ErrorScreenDestinations;
use App\Support\Http\RetryAfterSeconds;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Support\Header;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/**
 * Inertia XHR (X-Inertia 付き) の 4xx/5xx を **Error 画面 (Inertia ページ)** へ差し替える。
 *
 * これが無いと @inertiajs/core は x-inertia ヘッダの無い応答を
 * `handleNonInertiaResponse()` → `dialog_default.show()` = エラーモーダルに流し込み、
 * 利用者は SPA から出られなくなる (URL も履歴も動かないため戻り先が無い)。
 *
 * ApiExceptionRenderer と対になる位置づけ (api/* は封筒 JSON、Inertia は Error 画面)。
 * **bootstrap/app.php に直書きしない**理由は 2 つ:
 *   1. tests/Architecture/InertiaRenderPageExistsInvariantTest の走査対象が app/ と routes/ だけで、
 *      bootstrap/ に Inertia::render を書くと「ページ実在」gate が効かない
 *   2. Controller (と例外ハンドラ) は薄く保つ (AGENTS.md 実装規約)
 *
 * **deny-by-default**: 差し替えるのは passthroughReason() が null を返す応答だけ。
 */
final class InertiaExceptionRenderer
{
    /**
     * 差し替え**しない**理由。null なら差し替えてよい。
     *
     * 判定順は「壊してはいけないものから」。呼び出し側 (bootstrap) の早期 return と
     * 重複する条件も**あえて再掲する** (この関数単体で安全側に閉じる = 呼び出し位置に依存しない)。
     */
    public static function passthroughReason(Response $response, Request $request): ?InertiaErrorScreenPassthrough
    {
        $status = $response->getStatusCode();

        if ($status < 400) {
            return InertiaErrorScreenPassthrough::SuccessOrRedirectStatus;
        }

        if ($request->is('api/*') || $request->expectsJson()) {
            return InertiaErrorScreenPassthrough::MachineReadableEnvelope;
        }

        $adminPath = AdminPanelPath::resolve();
        if ($request->is($adminPath) || $request->is($adminPath.'/*')) {
            return InertiaErrorScreenPassthrough::OperatorFacingSurface;
        }

        if ($request->header(Header::INERTIA) === null) {
            return InertiaErrorScreenPassthrough::NonInertiaRequest;
        }

        if (! self::assetVersionMatches($request)) {
            return InertiaErrorScreenPassthrough::StaleAssetVersion;
        }

        if ($response->headers->has('Location') || $response->headers->has(Header::LOCATION)) {
            return InertiaErrorScreenPassthrough::InertiaProtocolRedirect;
        }

        $screenStatus = InertiaErrorScreenStatus::tryFrom($status);
        if ($screenStatus === null) {
            return InertiaErrorScreenPassthrough::UnlistedStatus;
        }

        if ($screenStatus->isServerError() && config('app.debug') === true) {
            return InertiaErrorScreenPassthrough::DebugServerError;
        }

        return null;
    }

    /**
     * 差し替え後の応答。差し替えない場合と、生成に失敗した場合は null
     * (呼び出し側が原応答をそのまま返す = 今日の挙動より悪くならない)。
     */
    public static function render(Response $response, Request $request): ?Response
    {
        try {
            if (self::passthroughReason($response, $request) !== null) {
                return null;
            }

            $status = InertiaErrorScreenStatus::from($response->getStatusCode());

            $retryAfterSeconds = $status->showsRetryAfter()
                ? RetryAfterSeconds::parse($response->headers->get('Retry-After'))
                : null;

            $data = new ErrorScreenData(
                status: $status,
                retryAfterSeconds: $retryAfterSeconds,
                destinations: ErrorScreenDestinations::for($status, $request->user() !== null),
            );

            $rendered = Inertia::render('Error', $data->toInertiaProps())
                ->toResponse($request)
                ->setStatusCode($status->value);

            // ヘッダ移植は allowlist (deny-by-default)。原値をそのまま写すのではなく、
            // RetryAfterSeconds が解釈できた値だけを正規化して再設定する
            // (本文 / API details / HTTP ヘッダの三者が同じ SoT を通る)。
            if ($retryAfterSeconds !== null) {
                $rendered->headers->set('Retry-After', (string) $retryAfterSeconds);
            }

            // HandleInertiaRequests が走る前 (テナント guard 404 等) に差し替える経路では
            // vendor middleware の Vary 付与が起きないため、ここで補う
            // (X-Inertia の有無で本文が変わる応答を共有キャッシュに載せない)。
            $rendered->setVary(Header::INERTIA, replace: false);

            return $rendered;
        } catch (Throwable) {
            // version 解決 (manifest 読み) / route 解決 / props 生成 / toResponse の
            // **どの段で失敗しても**原応答 (自己完結 Blade) を残す。
            return null;
        }
    }

    /**
     * リクエストの asset version が現在の build と一致するか (配備境界)。
     *
     * 一致 = そのタブは現在の build から asset を読み込んでいる
     *      = その bundle に resources/js/pages/Error.svelte が含まれている。
     * 不一致のタブへ component 'Error' を返すと resolvePage() が throw して SPA が無反応になる
     * (= 今日のモーダル表示より悪化する)。
     *
     * ★**両辺が非空文字列のときだけ一致とみなす** (null === null を「同じ build」と読まない)。
     * ★version の取得元は HandleInertiaRequests::version()。Inertia::getVersion() は
     *   同 middleware の handle() が走った後でないと空文字になり、テナント guard 404 のように
     *   middleware より前で例外が出る経路で誤って不一致になる。
     */
    private static function assetVersionMatches(Request $request): bool
    {
        $requested = $request->header(Header::VERSION);
        if (! is_string($requested) || $requested === '') {
            return false;
        }

        $current = app(HandleInertiaRequests::class)->version($request);

        return is_string($current) && $current !== '' && $current === $requested;
    }
}
```

`bootstrap/app.php` (respond callback を拡張。**2 本目は追加しない**):

```php
use App\Exceptions\InertiaExceptionRenderer;

        /*
         | 例外応答の最終整形。**このアプリで唯一の respond callback**。
         |
         | ⚠ Illuminate\Foundation\Exceptions\Handler::respondUsing() は
         |   $finalizeResponseCallback への**単純代入** (単一スロット・last-write-wins)。
         |   2 本目の $exceptions->respond() を足すと、この callback が黙って無効化される。
         |   同じ理由で Inertia::handleExceptionsUsing() (内部で respondUsing を呼ぶ) も使わない。
         |   分岐を増やすときは**必ずこの 1 本の中に足す**こと。
         |   (tests/Architecture/InertiaErrorScreenContractTest が登録箇所 1 件を機械強制する)
         |
         | 適用順:
         |   1. status < 400 / api/* / expectsJson … 触らない (ApiExceptionRenderer の封筒 JSON を守る)
         |   2. /admin 配下 … 運営者向け中立テンプレート (customer-facing と operator-facing の分離)
         |   3. Inertia XHR … Error 画面へ差し替え (InertiaExceptionRenderer が deny-by-default で判定)
         |   4. それ以外 … 自己完結 Blade (resources/views/errors/*.blade.php) のまま
         */
        $exceptions->respond(function (Response $response, Throwable $exception, Request $request) {
            $status = $response->getStatusCode();
            if ($status < 400 || $request->is('api/*') || $request->expectsJson()) {
                return $response;
            }

            $adminPath = AdminPanelPath::resolve();
            $isAdminPath = $request->is($adminPath) || $request->is($adminPath.'/*');
            if ($isAdminPath) {
                $adminView = $status >= 500 ? 'errors.admin.5xx' : 'errors.admin.4xx';
                if (! view()->exists($adminView)) {
                    return $response;
                }

                return response()->view($adminView, [
                    'status' => $status,
                    'adminPath' => $adminPath,
                ], $status);
            }

            return InertiaExceptionRenderer::render($response, $request) ?? $response;
        });
```

> 既存の分岐構造 (`if (! $isAdminPath) { return $response; }`) を
> `if ($isAdminPath) { … }` に反転しただけで、admin 側の挙動は 1 bit も変えていない。

### PHPStan 適合チェック

- [x] 戻り値の型が明示されている (`?InertiaErrorScreenPassthrough` / `?Response` / `bool`)
- [x] null 安全 (`$request->header()` の `?string` を `is_string` で絞る。
      `config('app.debug')` は `mixed` なので `=== true` で厳密比較)
- [x] DTO を返している (props は `ErrorScreenData::toInertiaProps()` の 1 経路のみ)
- [x] Generics の型パラメータが正しい (該当なし)
- [x] `Inertia::render(...)->toResponse($request)` は `Symfony\...\Response` を返し、
      `setStatusCode()` は `static` を返す (level 10 で型不整合にならない)
- [x] `catch (Throwable)` は変数を捕捉しない (未使用変数を作らない)

### テスト計画

- [ ] **テストファースト**: まず `InertiaErrorScreenTest` を書いて赤を確認してから実装する
- [ ] 共通ヘルパ (ファイルローカル関数。`tests/Feature/Errors/` 内に定義)
  ```php
  /** 現 build と一致する X-Inertia ヘッダ一式。asset_url を固定して version を決定的にする。 */
  function inertiaErrorScreenHeaders(): array
  {
      config(['app.asset_url' => 'https://assets.test']);
      $version = app(HandleInertiaRequests::class)->version(request());

      return ['X-Inertia' => 'true', 'X-Inertia-Version' => (string) $version];
  }
  ```
  > `config('app.asset_url')` を設定するのは、テスト環境で `public/build/manifest.json` の
  > 有無に依存させないため (`Inertia\Middleware::version()` は asset_url を最優先で見る)。

- [ ] 新規 `tests/Feature/Errors/InertiaErrorScreenTest.php` (Factory 必須・RefreshDatabase グローバル)
  - `it('403 が Inertia の Error ページになる')` — 認証済み User を Factory で作り、
    403 を投げるテスト用 route ではなく**既存の権限不足経路**
    (他組織の `organizations.settings` 等) を叩く。`assertInertia(fn ($page) =>
    $page->component('Error')->where('status', 403))` + `assertForbidden()`
  - `it('404 が Inertia の Error ページになる')` — 未知 URL を X-Inertia で叩く
  - `it('419 が Inertia の Error ページになりログイン導線を返す')` —
    `$this->withMiddleware(VerifyCsrfToken::class)` 相当で CSRF 不一致を作るのが難しいため、
    `Route::get('/__test/419', fn () => abort(419))` を**テスト内で定義**して叩く
    (route 定義はテスト内に閉じ、production route を増やさない)。
    props の `destinations.0.href` が `/login` であること
  - `it('認証済みでも 419 はログイン導線になる (D1)')` — 同上を `actingAs` で
  - `it('429 は retryAfterSeconds を props に載せ Retry-After ヘッダも保持する')` —
    `abort(429, headers: ['Retry-After' => '30'])` を返すテスト内 route。
    props の `retryAfterSeconds` が 30、`assertHeader('Retry-After', '30')`
  - `it('429 の Retry-After が解釈不能なら retryAfterSeconds は null でヘッダも載らない')` —
    `['Retry-After' => 'Wed, 21 Oct 2015 07:28:00 GMT']`
  - `it('500 は app.debug=false のとき Error ページになる')` —
    `config(['app.debug' => false])` + テスト内 route で `throw new RuntimeException`。
    `$this->withoutExceptionHandling()` は**使わない** (例外ハンドラ自体が検証対象のため)
  - `it('503 は app.debug=false のとき Error ページになる')`
  - `it('Error 応答は x-inertia ヘッダを持つ (クライアントがモーダルに落とさない条件)')` —
    `assertHeader('X-Inertia', 'true')` + `assertHeader('Vary', ...)` に `X-Inertia` を含むこと
  - `it('戻り先が全 status で 1 件以上ある')` — 6 status を `->with()` で回して
    `count($props['destinations']) >= 1`
  - `it('cross-org 実在と不在で Error 応答が分岐しない')` —
    version 一致ヘッダ付きで `/projects/{他組織の実在 id}` と `/projects/999999999` を叩き、
    **status と props (url を除く) が一致**すること。Project は Factory で作る
    (`TenantBoundaryPrecedenceTest` の契約を差し替え経路でも維持することの確認)
- [ ] 新規 `tests/Feature/Errors/InertiaErrorScreenPassthroughTest.php`
  - `it('X-Inertia なしのフルロードは Blade のまま')` — 404 に `<style>` が含まれること
  - `it('stale version の X-Inertia は差し替えない')` — `X-Inertia-Version: stale-version`
  - `it('version ヘッダ欠落の X-Inertia は差し替えない')`
  - `it('version ヘッダが空文字の X-Inertia は差し替えない')`
  - `it('現 version が空 (asset_url 未設定 + manifest 不在) なら差し替えない')` —
    `config(['app.asset_url' => null])` + manifest 不在を前提にした条件付き skip ではなく、
    `HandleInertiaRequests` を `version()` が null を返す anonymous subclass に
    `$this->app->instance()` で差し替えて固定する
  - `it('409 + X-Inertia-Location は差し替えない')` — `Inertia::location('https://…')` を返す
    テスト内 route を X-Inertia で叩き、`assertStatus(409)` + `X-Inertia-Location` 保持
  - `it('302 + Location は差し替えない')`
  - `it('4xx + Location ヘッダを持つ応答は差し替えない')` —
    `abort(404, headers: ['Location' => '/somewhere'])` 相当のテスト内 route
  - `it('422 (バリデーション) は差し替えない')` — FormRequest 失敗を X-Inertia で叩き、
    302 + errors の既定挙動になること
  - `it('api/* は封筒 JSON のまま')`
  - `it('expectsJson は封筒 JSON / 既定 JSON のまま')`
  - `it('admin 配下は運営者向け中立テンプレートのまま')` — X-Inertia を付けても
    `errors.admin.4xx` の内容 (`管理パネルに戻る`) が返ること
  - `it('5xx は app.debug=true のとき差し替えない')`
  - `it('version resolver が throw しても原応答が完全一致で残る')` —
    `HandleInertiaRequests` を `version()` が例外を投げる差し替え実装にし、
    差し替え無しの応答と `ResponseSignature::of()` が一致すること
    (`Tests\Support\ResponseSignature` は**読むだけ**で変更しない)
  - `it('素通し理由 enum の全 case が実際に生成される (死んだ分類を作らない)')` —
    上記各ケースで `InertiaExceptionRenderer::passthroughReason()` を直接評価し、
    観測された case 集合が `InertiaErrorScreenPassthrough::cases()` と**一致**すること
- [ ] 既存テストの更新: `tests/Feature/Errors/ErrorPagesTest.php`
  - 追加 `it('admin 配下の 404 は HTTP 経路でも運営者向けテンプレートを返す')` —
    既存 3 ケースは `view()->render()` 直叩きで respond callback を通らないため、
    **単一スロットの退行 (respond が 2 本目に奪われる) を検出できない**。
    HTTP 経路のケースを 1 本足してこの穴を塞ぐ (S4 の最大の事故モードの behavioral 固定)
- [ ] 既存テストの非変更確認: `tests/Feature/Security/TenantBoundaryPrecedenceTest.php` /
      `InertiaHistoryGuardTest.php` / `PasskeyRouteAccessTest.php` / `RecentAuthTest.php` /
      `PasswordSetupTest.php` ほか X-Inertia を送る 11 ファイルは
      **version ヘッダを送っていないため差し替え対象外**。無改修で green であることを
      `composer test` で確認する (1 本でも赤なら設計の前提が崩れているので実装を止める)
- [ ] 個別の `DatabaseTransactions` を使っていないことを確認 (全 Feature テストで grep)

### リスク

| # | リスク | 緩和 |
|---|--------|------|
| 1 | respond callback が単一スロットであることを知らずに 2 本目を足され、admin 分離が黙って死ぬ | bootstrap のコメントで明示 + S6 の Architecture gate で登録 1 件を強制 + `ErrorPagesTest` に HTTP 経路ケースを追加 (三重) |
| 2 | Inertia 手順上の 409 / Location 応答を巻き込む | `passthroughReason()` の P4/P5 + 素通し Feature テスト 4 ケース |
| 3 | `AuthenticationException` の `Inertia::clearHistory()` 契機が消える | render callback は respond より**前**に走る (`Handler::render()` → `finalizeRenderedResponse()`) ため構造的に保証。加えて既定処理は 302 = status < 400 で素通し。`InertiaHistoryGuardTest` が既存で固定 |
| 4 | Error 画面の生成中に二次例外が出て 500 が二重に壊れる | 全段 try/catch → null → 原応答 (自己完結 Blade)。専用 Feature テストで固定 |
| 5 | 旧 asset のタブで SPA が無反応になる | 配備境界 (version 一致) 判定。stale/欠落/空文字/現 version 空の 4 ケースをテスト |
| 6 | 差し替え応答が共有キャッシュに載る | `Vary: X-Inertia` を明示付与。`NoStoreCacheHeaders...` は middleware で従来どおり適用 |
| 7 | `$request->user()` がセッション不整合時に throw する | try/catch 内。かつ 419 は D1 で認証状態を見ない |
| 8 | 実行時間の増加 | 差し替えは 4xx/5xx のみ。正常系に分岐は 1 つも増えない (respond callback は例外時のみ実行) |

---

## S5: Error ページ (Svelte / TS 型 / resolver eager 化)

### 変更箇所

- 新規: `resources/js/pages/Error.svelte`
- 新規: `resources/js/types/error-screen.ts`
- 変更: `resources/js/inertia.ts` (全体)

### 波及変更

- TypeScript 型定義: `resources/js/types/error-screen.ts` (新規。S3 の
  `ErrorScreenPropsShape` と 1:1)
- API Resource/DTO: **なし** (PHP 側は S3 で完結)
- Inertia Props インターフェース: `Error.svelte` の `Props`
- テストファイル:
  - 新規 `tests/js/pages/Error.test.ts`
  - 新規 `tests/js/architecture/inertia-eager-error-page.test.ts` (S6 と一体で書く)
  - 既存 `tests/js/lib/` に `inertia.ts` の既存テストがあれば追随
    (実装時に `rg 'resolvePage' tests/js` で確認)

### 現行コード

`resources/js/inertia.ts` (全文):

```ts
import type { ResolvedComponent } from "@inertiajs/svelte";

const pages = import.meta.glob<ResolvedComponent>("./pages/**/*.svelte");

/**
 * Inertia のページ名を ./pages 配下の Svelte component に解決する。
 * 未解決時は throw して「真っ白画面で原因不明」を防ぐ。
 */
export async function resolvePage(name: string): Promise<ResolvedComponent> {
    const loader = pages[`./pages/${name}.svelte`];
    if (!loader) {
        throw new Error(`Inertia page not found: ${name}`);
    }
    return loader();
}
```

**問題**: 非 eager な glob のため全ページが遅延 chunk。500 経路やデプロイ直後に
Error ページの chunk 取得が失敗すると `resolvePage` が throw し、SPA が無反応になる
(= 今日のモーダル表示より悪化する)。

### 変更後コード

`resources/js/inertia.ts`:

```ts
import type { ResolvedComponent } from "@inertiajs/svelte";

/**
 * **Error ページだけは初期 bundle に同梱する** (eager glob)。
 *
 * 他のページと違い、Error ページが必要になるのは「サーバが 4xx/5xx を返している」
 * = ネットワークやサーバが不調な瞬間である。そこで追加の chunk 取得に出ると、
 * 取得失敗 → resolvePage が throw → SPA が無反応、という**今日より悪い**状態になる。
 * 初期 bundle の増分は 1 ページ分にすぎない。
 *
 * テストが検査するため export する (tests/js/architecture/inertia-eager-error-page.test.ts)。
 * ここを増やすと全ページ eager 化に近づくため、同テストがキー集合を exact-fit で固定する。
 */
export const EAGER_PAGES = import.meta.glob<ResolvedComponent>("./pages/Error.svelte", {
    eager: true,
});

/** 遅延解決されるページ (Error 以外はすべてこちら)。 */
export const LAZY_PAGES = import.meta.glob<ResolvedComponent>("./pages/**/*.svelte");

/**
 * ページ名 → component の解決 (純関数)。eager map を先に引き、無ければ遅延 loader へ。
 * 未解決時は throw して「真っ白画面で原因不明」を防ぐ。
 *
 * map を引数で受けるのはテスト可能性のため
 * (「Error のとき遅延 loader を 1 度も呼ばない」を spy で固定する)。
 */
export async function resolvePageFrom(
    name: string,
    eager: Record<string, ResolvedComponent>,
    lazy: Record<string, () => Promise<ResolvedComponent>>,
): Promise<ResolvedComponent> {
    const key = `./pages/${name}.svelte`;

    const eagerComponent = eager[key];
    if (eagerComponent) {
        return eagerComponent;
    }

    const loader = lazy[key];
    if (!loader) {
        throw new Error(`Inertia page not found: ${name}`);
    }
    return loader();
}

/** Inertia のページ名を ./pages 配下の Svelte component に解決する。 */
export async function resolvePage(name: string): Promise<ResolvedComponent> {
    return resolvePageFrom(name, EAGER_PAGES, LAZY_PAGES);
}
```

`resources/js/types/error-screen.ts`:

```ts
/**
 * Error ページ (pages/Error.svelte) の props。
 * PHP 側 App\DataTransferObjects\Http\ErrorScreenData::toInertiaProps() と 1:1 で保守する。
 *
 * 共有 props (appName / auth / flash 等) には依存しない:
 * 例外はテナント境界 404 のように HandleInertiaRequests が走る前にも起きるため、
 * Error ページが必要とする値はすべてサーバが明示 props で渡す。
 */
export interface ErrorScreenDestination {
    readonly label: string;
    /** サーバ側で固定した同一オリジンの相対 path (リクエスト入力は混ざらない)。 */
    readonly href: string;
}

export interface ErrorScreenProps {
    readonly status: number;
    readonly title: string;
    readonly message: string;
    /** 待ち時間 (秒)。Retry-After が非負整数のときだけ入る。 */
    readonly retryAfterSeconds: number | null;
    readonly destinations: readonly ErrorScreenDestination[];
}
```

`resources/js/pages/Error.svelte`:

```svelte
<script lang="ts">
    import { CircleAlert } from "@lucide/svelte";
    import Button from "@/components/atoms/Button.svelte";
    import Card from "@/components/atoms/Card.svelte";
    import type { ErrorScreenProps } from "@/types/error-screen";

    /**
     * Inertia XHR の 4xx/5xx 着地画面 (サーバの InertiaExceptionRenderer が描画する)。
     *
     * 契約:
     *  - **layout を import しない**。AppLayout/AuthLayout は page-shell-structure の
     *    構造契約が掛かり、GuestLayout は共有 prop appName を要求するが、
     *    例外は HandleInertiaRequests が走る前にも起きるため共有 props が無い場合がある
     *  - **戻り先は通常の <a> (Button の anchor モード、inertia prop なし)**。
     *    Link / router.visit は同じ document を保つため、419 の原因が古い CSRF token だと
     *    遷移後の POST で同じ 419 を踏み直す。document を作り直して初めて復旧する
     *  - **disabled な CTA を作らない** (禁止事項 8)。destinations は常に 1 件以上
     *  - title は svelte:head に書かない (サーバ SEO が唯一の SoT)
     */
    let { status, title, message, retryAfterSeconds, destinations }: ErrorScreenProps = $props();
</script>

<div class="flex min-h-screen items-center justify-center bg-surface-muted p-6">
    <Card padding="lg" class="w-full max-w-md text-center" testId="error-screen">
        <CircleAlert class="mx-auto h-12 w-12 text-text-secondary" />
        <p class="mt-4 text-caption text-text-secondary" data-testid="error-status">{status}</p>
        <h1 class="mt-1 text-h1">{title}</h1>
        <p class="mt-3 text-caption text-text-secondary">{message}</p>
        {#if retryAfterSeconds !== null}
            <p class="mt-2 text-caption text-text-secondary" data-testid="error-retry-after">
                約 {retryAfterSeconds} 秒後にもう一度お試しください。
            </p>
        {/if}
        <div class="mt-6 flex flex-col gap-2">
            {#each destinations as destination (destination.href)}
                <Button href={destination.href}>{destination.label}</Button>
            {/each}
        </div>
    </Card>
</div>
```

> `bg-surface-muted` などの token 名は実装時に `resources/css/tokens.css` /
> `DESIGN.md` の定義と突き合わせて確定する (存在しない token を書くと ds-purity が赤)。
> 構成は既に全 gate を通っている `resources/js/pages/Contact/Thanks.svelte` に倣う。

### PHPStan 適合チェック

PHP 変更なし (フロントのみ)。代わりに **TypeScript / Svelte 側の gate** を確認する:

- [x] `pnpm typecheck` — props が `ErrorScreenProps` で型付けされている
- [x] `atomic-import-graph` — pages → atoms の単方向 import のみ
- [x] `ds-purity` — hex / raw palette / inline style を使わない (token のみ)
- [x] `typography-invariant` — `text-h1` / `text-caption` の named ramp のみ
- [x] `svg-inline-allowlist` — アイコンは `@lucide/svelte` (SVG 直書きなし)
- [x] `svelte-head-no-title` — `<svelte:head>` を持たない
- [x] `page-shell-structure` — AppLayout / AuthLayout を import しないため契約対象外
- [x] `pages-path-case-invariant` — `Error.svelte` は PascalCase 単一ファイル

### テスト計画

- [ ] 新規 `tests/js/pages/Error.test.ts` (vitest + @testing-library/svelte)
  - `it('status / title / message / 戻り先を描画する')`
  - `it('retryAfterSeconds が null なら待ち時間を描画しない')`
  - `it('retryAfterSeconds があれば秒数を描画する')`
  - `it('戻り先が通常の <a href> で描画される (Inertia Link ではない)')` —
    描画された anchor に Inertia Link が付ける属性が無いこと + `getAttribute('href')` が
    props の値であること
  - `it('disabled な CTA を作らない')` — anchor に `aria-disabled` / `tabindex="-1"` が無いこと
- [ ] 新規 `tests/js/architecture/inertia-eager-error-page.test.ts` (S6 と一体)
  - `it('eager 解決の対象は Error ページちょうど 1 件')` —
    `Object.keys(EAGER_PAGES)` が `["./pages/Error.svelte"]` に**完全一致**
    (glob が広がって全ページ eager 化する退行も、Error が外れる退行も両方検出)
  - `it('Error は遅延 loader を 1 度も呼ばずに解決される')` —
    `resolvePageFrom("Error", EAGER_PAGES, spyLazyMap)` で spy が呼ばれないこと
  - `it('Error 以外は遅延 loader 経由で解決される')` — `resolvePageFrom("Dashboard", …)` で
    spy が 1 回呼ばれること (正のコントロール)
  - `it('未解決ページは throw する (既存契約の維持)')`
  - docblock に **保証範囲の限界**を明記:
    「本 gate は resolver が遅延 loader を呼ばないところまでしか保証しない。
    `pnpm build` 生成物の chunk 分割は検査しない (vitest を build 生成物に従属させると
    build 未実行の環境で恒常的に赤くなるため)」
- [ ] `Error.svelte` を `Inertia::render('Error')` が参照するので、既存の
      `tests/Architecture/InertiaRenderPageExistsInvariantTest.php` が**自動で**
      ページ実在を検査する (S4 で renderer を `app/` 配下に置いたことの効果)

### リスク

- **token 名の不一致**: `bg-surface-muted` 等が DESIGN.md に無いと ds-purity が赤。
  → 実装時に `resources/css/tokens.css` と `Thanks.svelte` の実使用 token を確認して確定する。
- **eager 化による初期 bundle 増**: 1 ページ分 (Card / Button / Lucide 1 アイコンは
  既に他ページで共有済み)。実測は `pnpm build` の出力で確認する。
- **`{#each}` の key**: `destination.href` を key にしている。同一 href が 2 件並ぶと
  重複 key で警告が出るが、`ErrorScreenDestinations` は必ず異なる 2 件を返す
  (Unit テストが href の重複が無いことも併せて固定する)。

---

## S6: deny-by-default 目録 gate (PHP + JS)

### 変更箇所

- 新規: `tests/Architecture/InertiaErrorScreenContractTest.php`
- 新規: `tests/js/architecture/inertia-eager-error-page.test.ts` (S5 に記載。ここでは PHP 側を書く)

### 波及変更

- TypeScript 型定義: **なし**
- API Resource/DTO: **なし**
- テストファイル: 本施策がテストそのもの。`scripts/test-inventory-config.ts` の
  include (`tests/js/**/*.test.ts`) は既存パターンで新規 JS テストを拾うため**変更不要**

### 現行コード

該当なし (新規)。作法の見本は `tests/Architecture/ThrottleCoverageInventoryTest.php`
(母集団下限 / exact-fit cap / 型付き enum + 30 文字以上の根拠 / stale 検出 / 死んだ免除の検出)
と `tests/Architecture/InertiaRenderPageExistsInvariantTest.php` (負のコントロール)。

### 変更後コード

```php
<?php

declare(strict_types=1);

use App\Enums\Http\InertiaErrorScreenPassthrough;
use App\Enums\Http\InertiaErrorScreenStatus;

/*
 * Inertia XHR の Error 画面差し替え契約 (deny-by-default) の Architecture invariant。
 *
 * 守るもの:
 *   1. 差し替え対象 status は目録に列挙され、型付き enum + 30 文字以上の根拠を持つ (exact-fit)
 *   2. 素通し理由も型付き enum + 30 文字以上の根拠を持ち、死んだ分類を残さない
 *   3. 目録の各 status に**自己完結 Blade が併存**する (非 Inertia 経路の最後の砦を消していない)
 *   4. 例外応答の最終整形 (respondUsing の単一スロット) を奪う登録が 1 箇所しかない
 *   5. Inertia::render が bootstrap/ に直書きされていない
 *      (InertiaRenderPageExistsInvariantTest の走査対象が app/ + routes/ だけのため)
 *
 * ★保証範囲の限界 (誇張しない): 4 と 5 は**文字列走査**である。変数経由の動的呼び出し・
 *   vendor 内での別名再エクスポート・将来 Laravel が別 API を生やした場合は検出できない。
 *   だからこそ tests/Feature/Errors/ErrorPagesTest.php に **HTTP 経路の admin ケース**を
 *   置き、振る舞い側からも単一スロットの退行を捕まえる。
 *
 * ★本 gate は新設契約なので実装後の main では必ず green になる (空振りと区別が付かない)。
 *   よって負のコントロールを併置し、検出器が実際に点灯することを fixture で固定する。
 *
 * DB 不使用の静的検査 (既存 Architecture テストと同じ作法)。
 */

/** 目録の下限 (空振り drift ガード)。 */
function inertiaErrorScreenStatusFloor(): int
{
    return 6;
}

/**
 * 目録の上限。**現在値ちょうど** (exact fit)。
 *
 * ★余裕を 1 でも持たせると、その 1 status は「個別の根拠も再レビューも無しに
 *   Error 画面へ差し替えてよい枠」になる。exact fit なら次の 1 件が必ず
 *   「この数値を変える差分」として現れ、素通しすべきでないかの再検討を強制できる。
 */
function inertiaErrorScreenStatusCap(): int
{
    return 6;
}

/** 根拠の最低文字数 (「同上」「N/A」を機械的に弾く)。 */
function inertiaErrorScreenReasonMinLength(): int
{
    return 30;
}

/**
 * 差し替え対象 status の目録 (enum case => 具体的根拠)。
 *
 * @return array<int, array{InertiaErrorScreenStatus, string}> status 値をキーにする
 */
function inertiaErrorScreenStatusInventory(): array
{
    return [
        403 => [InertiaErrorScreenStatus::Forbidden,
            '権限不足は利用者が別画面へ移動すれば作業を継続できる種類の失敗であり、素の HTML を'
            .'モーダルに流し込んで画面から出られなくする理由が無い。文言は権限の詳細を漏らさない中立形。'],

        404 => [InertiaErrorScreenStatus::NotFound,
            'cross-org / 削除済みリソースへの遷移で日常的に発生する。テナント境界 404 と不在 404 は'
            .'同一の固定文言・固定 props になるため、差し替えても存在オラクルを作らない。'],

        419 => [InertiaErrorScreenStatus::PageExpired,
            'セッション切れは撮影 PWA で最も踏まれる。ログイン導線が無いと現場作業者が確実に詰むため、'
            .'認証状態を問わずログインへ倒す (戻り先規則 D1)。復旧には document 再生成が要る。'],

        429 => [InertiaErrorScreenStatus::TooManyRequests,
            'throttle 到達時の待ち時間を本文へ出す ((c) の details.retry_after と対称)。'
            .'ヘッダだけでは利用者に伝わらず、いつ再試行してよいか分からないまま放置される。'],

        500 => [InertiaErrorScreenStatus::ServerError,
            '障害時も SPA から出られる導線を残す。app.debug=true では差し替えないため、'
            .'開発時の例外詳細ページを中立文言で潰すことは無い (DebugServerError で素通し)。'],

        503 => [InertiaErrorScreenStatus::ServiceUnavailable,
            'メンテナンス中の待ち時間を本文へ出す。500 と同じく app.debug=true では差し替えず、'
            .'非 Inertia のフルロードには自己完結 Blade がそのまま出る。'],
    ];
}

/**
 * 素通し理由の目録 (enum case value => 具体的根拠)。
 *
 * @return array<string, string>
 */
function inertiaErrorScreenPassthroughInventory(): array
{
    return [
        InertiaErrorScreenPassthrough::SuccessOrRedirectStatus->value =>
            '2xx / 3xx は Fortify の各 Response・back()->with(error)・redirect()->intended() など'
            .'アプリのフロー本体そのもの。差し替えると遷移が消えてフロー全体が壊れる。',

        InertiaErrorScreenPassthrough::MachineReadableEnvelope->value =>
            'api/* と expectsJson は裁定 (c) の統一エラー封筒 JSON が正しい応答形であり、'
            .'ApiExceptionRenderer が既に責務を持っている。画面へ差し替えると機械側の契約が壊れる。',

        InertiaErrorScreenPassthrough::OperatorFacingSurface->value =>
            'admin panel 配下は運営者向けの中立テンプレート (errors.admin.*) に分離済みで、'
            .'顧客向け文言を出さないことが既存契約 (ErrorPagesTest が固定)。',

        InertiaErrorScreenPassthrough::NonInertiaRequest->value =>
            'X-Inertia を持たないフルロードには自己完結 Blade を返す。Vite / Inertia / DB に'
            .'依存しない最後の砦であり、500 経路で白画面にしないための併存が契約。',

        InertiaErrorScreenPassthrough::StaleAssetVersion->value =>
            '旧 build の bundle には Error ページが存在せず、resolvePage が throw して SPA が'
            .'無反応になる (今日のモーダル表示より悪化する)。両辺が非空文字列で一致する場合のみ差し替える。',

        InertiaErrorScreenPassthrough::InertiaProtocolRedirect->value =>
            'Location / X-Inertia-Location を持つ応答は Inertia 手順上の遷移 (version mismatch の'
            .'409 や Inertia::location) と外部遷移そのもの。差し替えると資産再読込と決済導線が壊れる。',

        InertiaErrorScreenPassthrough::UnlistedStatus->value =>
            '目録に無い status (409 / 422 / 401 等) は deny-by-default で触らない。特に 422 を'
            .'差し替えるとバリデーションの field errors が消え、利用者の入力が失われる。',

        InertiaErrorScreenPassthrough::DebugServerError->value =>
            'app.debug=true の 5xx を中立文言で潰すと開発時に原因調査の手段を失う。'
            .'Inertia 公式レシピが local/testing を除外しているのと同じ理由 (本番では差し替える)。',
    ];
}

/**
 * 例外応答の最終整形スロット (Handler::$finalizeResponseCallback) を奪う呼び出しの検出パターン。
 * **単一スロット・last-write-wins** のため、2 箇所目が現れたら黙って先勝ちが無効化される。
 *
 * @return list<string>
 */
function inertiaErrorScreenRespondSlotPatterns(): array
{
    return ['->respond(', '->respondUsing(', 'handleExceptionsUsing('];
}

/** 走査対象ファイル (app/ + bootstrap/ + routes/ + config/ の PHP)。 */
function inertiaErrorScreenScanFiles(): array { /* RecursiveIteratorIterator で収集 */ }

test('差し替え対象 status の目録が下限を下回らない (空振り検出)', ...);
test('差し替え対象 status の目録が上限を超えない (exact fit)', ...);
test('目録と enum の case 集合が一致する (stale 検出)', ...);
test('目録の根拠が 30 文字以上ある', ...);
test('素通し理由 enum の全 case が目録に 30 文字以上の根拠を持つ', ...);
test('目録の各 status に自己完結 Blade が併存する', function (): void {
    // errors/{status}.blade.php か errors/{4xx,5xx}.blade.php のどちらかが実在すること
});
test('例外応答の最終整形スロットを奪う登録は bootstrap/app.php の 1 箇所だけ', ...);
test('bootstrap/ に Inertia::render を直書きしない (ページ実在 gate の網から外れるため)', ...);
test('負のコントロール: respond スロット検出器が fixture ソースで点灯する', function (): void {
    $fixture = <<<'PHP'
    <?php
    $exceptions->respond(fn ($r) => $r);
    Inertia::handleExceptionsUsing(fn ($e) => $e);
    PHP;
    // 検出関数が 2 件返すことを表明する (空振り green を防ぐ)
});
test('負のコントロール: Inertia::render 直書き検出器が fixture ソースで点灯する', ...);
```

### PHPStan 適合チェック

- [x] 戻り値の型が明示されている (各ヘルパ関数に `@return`)
- [x] null 安全 (`file_get_contents` の `string|false` を `is_string` で絞る)
- [x] DTO を返している (テストヘルパのため該当なし。目録は shape 付き array)
- [x] Generics の型パラメータが正しい
      (`array<int, array{InertiaErrorScreenStatus, string}>` / `array<string, string>`)

### テスト計画

本施策自体がテストだが、**「素の main では赤にならない gate」をどう受け入れるか**を
以下の 2 段で担保する。

#### (1) 負のコントロール (テスト内 fixture / 恒久)

- [ ] respond スロット検出器が fixture ソースで 2 件検出すること
- [ ] `Inertia::render` 直書き検出器が fixture ソースで 1 件検出すること
- [ ] Blade 併存検査が「存在しない status」に対して欠落を報告すること

#### (2) mutation による赤化確認 (実装時に手で 1 度だけ実施し、結果を PR 説明に残す)

| # | mutation | 期待して赤くなるテスト |
|---|---------|---------------------|
| M1 | `inertiaErrorScreenStatusInventory()` から 404 を削る | 目録下限 / exact-fit cap / stale 検出 |
| M2 | `InertiaErrorScreenStatus` に `case Gone = 410;` を足す (目録は据え置き) | stale 検出 / exact-fit cap |
| M3 | 目録の根拠を「同上」に置換 | 根拠 30 文字検査 |
| M4 | `passthroughReason()` の `StaleAssetVersion` 分岐を削除 | `InertiaErrorScreenPassthroughTest` の stale/欠落/空文字ケース + 素通し理由 enum の全 case 生成テスト |
| M5 | `bootstrap/app.php` に 2 本目の `$exceptions->respond()` を追加 | 単一スロット gate + `ErrorPagesTest` の admin HTTP 経路ケース |
| M6 | `bootstrap/app.php` に `Inertia::render('Error')` を直書き | 直書き禁止 gate |
| M7 | `resources/js/pages/Error.svelte` を削除 | `InertiaRenderPageExistsInvariantTest` + `inertia-eager-error-page` の eager キー検査 |
| M8 | `resources/js/inertia.ts` の `{ eager: true }` を外す | eager キー検査 (`Object.keys(EAGER_PAGES)` が空になる) |
| M9 | `render()` の try/catch を外す | version resolver throw の fail-safe テスト |
| M10 | `Retry-After` ヘッダ再設定を削除 | 429 のヘッダ保持テスト |
| M11 | `ErrorScreenDestinations::for()` の D1 分岐を削除 | 「認証済みでも 419 はログイン」テスト |
| M12 | `RetryAfterSeconds::parse()` の負数判定を削除 | `RetryAfterSecondsTest` + API contract テスト |

- [ ] 個別の `DatabaseTransactions` を使っていないことを確認 (Architecture テストは DB 不使用)

### リスク

- **exact-fit cap の運用負荷**: status を 1 つ足すたびに 2 箇所 (enum + 目録 + cap) を触る。
  これは意図した摩擦であり、`ThrottleCoverageInventoryTest` と同じ設計判断。
- **文字列走査の限界**: 上述のとおり docblock に明記し、振る舞い側テストで二重化する。
- **gate の誤検知**: `->respond(` は他の意味 (例: 自作クラスの `respond()` メソッド) と
  衝突しうる。実装時に `rg -n -- '->respond\(' app bootstrap routes config` で
  現状 1 件だけであることを確認し、増えたら検出パターンを FQCN 前提へ絞る。

---

## 実装モード

| 項目 | 内容 |
|------|------|
| 推奨モード | **incremental** |
| 判断根拠 | 変更の中心は `bootstrap/app.php` の**既存 respond callback 1 本**と新規ファイル群であり、既存コードの書き換えは (a) respond callback の分岐反転 (b) `ApiExceptionRenderer::rateLimitDetails()` の実装差し替え (c) `resources/js/inertia.ts` の resolver 拡張 の 3 点に限られる。いずれも他機能のファイルに触れないため main との衝突面が小さい。また S1→S6 が明確な依存順を持ち、途中で止めても壊れない (S4 を入れるまで挙動は不変) |
| 競合リスク | **低**。ただし `bootstrap/app.php` は他の改善でも触られる中心ファイルであり、`withExceptions` に**別の PR が 2 本目の `$exceptions->respond()` を足していた場合は衝突ではなく論理的破壊**になる。マージ前に `rg -- '->respond\(' bootstrap/` で 1 件であることを確認する (S6 の gate がこれを恒久化する)。`resources/js/inertia.ts` は resolver の単一ファイルで他 PR が触る頻度は低い |
| 前提 | worktree (`scripts/setup-worktree.sh <task-id>`) で実装。`composer test` / `composer phpstan` / `vendor/bin/pint --test` / `pnpm lint` / `pnpm typecheck` / `pnpm test` / `pnpm build` が全 green |


## 関連する現行コード

### bootstrap/app.php (withExceptions 部分 / L272-382)
```php
    ->withExceptions(function (Exceptions $exceptions): void {
        // api/* は Accept ヘッダに依らず常に JSON envelope を返す。加えて、XHR / fetch など
        // JSON を期待するリクエスト (expectsJson) では Laravel 既定どおり JSON でレンダリングする
        // (例: /recent-auth/password の postJson バリデーションエラーは 302 ではなく 422 JSON)。
        // ここで既定を api/* だけに狭めると web 外の JSON クライアントが redirect を受け取り破綻する。
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );

        /*
         | セッション終了を検知した契機で Inertia の履歴暗号鍵を捨てさせる (経路 C の拡張)。
         |
         | ログアウト (App\Http\Responses\Fortify\LogoutResponse) は「利用者が明示的に
         | 終わらせた」契機しか拾えない。セッション期限切れと、パスワード変更による
         | 他デバイスの強制ログアウト (Auth::logoutOtherDevices → web グループの
         | AuthenticateSession) は、どちらも AuthenticationException として現れる。
         | ここでフラグを積むと、着地の /login (Inertia 応答) が
         | session()->pull で消費し、そのタブの sessionStorage の履歴鍵が消える。
         | = **認証失敗を契機に、以後の「戻る」による復元を無効化する**
         |   (過去に遡って無効化するのではない。docs/supported-browsers.md が正本)。
         |
         | 応答自体は既定の unauthenticated() 処理に委ねる (**null を返して素通し**)。
         | Handler::render() は renderViaCallbacks() を AuthenticationException の既定分岐より
         | 先に呼び、callback が null を返せば既定処理へ進む (Laravel 12 実装)。
         | この「null で素通し」に依存するため、**Laravel の major 更新時に再確認する**
         | (壊れた場合は InertiaHistoryGuardTest が落ちる)。
         |
         | 積まない条件は 2 つだけ:
         |   - expectsJson(): Inertia 応答が返らないためフラグが宙に浮く
         |   - session 不在: そもそもフラグを置けない
         | `api/*` の明示判定は**置かない**。api グループ (withRouting の api:) は
         | StartSession を含まないため hasSession() が偽で既に抑止され、到達不能条件になる。
         | guards() では面を判別しない (web の auth は [null]、AuthenticateSession は ['web']、
         | Filament の Authenticate は override により [] になり、実装詳細に依存するため)。
         | その結果 /admin の認証失敗でもフラグは積まれるが、**安全側の偽陽性として許容**する
         | (影響は Inertia 面の履歴が 1 度だけ再キーされることだけ)。この偽陽性は
         | InertiaHistoryGuardTest が仕様として固定しており、Filament の認証失敗の実装が
         | 変わったら本コメントとテストを**一緒に**更新する。
         */
        $exceptions->render(function (AuthenticationException $exception, Request $request): ?Response {
            if ($request->expectsJson() || ! $request->hasSession()) {
                return null;
            }

            Inertia::clearHistory();

            return null;
        });

        // 課金系のドメイン例外は web では back + error flash に変換する
        // (API 経路では null を返して下の ApiExceptionRenderer に委ねる)
        $exceptions->render(function (QuotaExceededException $exception, Request $request) {
            if ($request->is('api/*')) {
                return null; // ApiExceptionRenderer に委譲
            }
            if ($request->expectsJson()) {
                // 撮影 PWA の XHR (upload-url 等) は 422 + JsonResource (back() の 302 を返さない)
                return QuotaExceededResource::make($exception)
                    ->response($request)
                    ->setStatusCode(422);
            }

            return back()->with('error', $exception->getMessage()); // 既存の web 挙動を維持
        });
        $exceptions->render(function (InsufficientTicketsException $exception, Request $request) {
            if ($request->is('api/*')) {
                return null; // ApiExceptionRenderer に委譲 (既存)
            }
            if ($request->expectsJson()) {
                // XHR (analyze 等) は 402 + JsonResource (response()->json() 直書きはしない)
                return InsufficientTicketsResource::make($exception)
                    ->response($request)
                    ->setStatusCode(402);
            }

            return back()->with('error', $exception->getMessage()); // 既存の web 挙動を維持
        });

        // REST API v1 の統一エラー envelope ({error: {code, message, status, details?}})。
        // api/* 以外 (web / Inertia) は null を返して既定レンダリングを保つ
        $exceptions->render(function (Throwable $exception, Request $request) {
            return ApiExceptionRenderer::render($exception, $request);
        });

        // /admin (Filament 運営) 配下の error は運用者向け中立テンプレートへ分離する
        // (顧客向けマーケ文言の errors/4xx.blade.php を出さない = customer-facing と
        // operator-facing の error ページを分離)。判定は AdminPanelPath::resolve() に一本化。
        // API/JSON 経路は不変 (ApiExceptionRenderer が先に JSON 化する)。
        $exceptions->respond(function (Response $response, Throwable $exception, Request $request) {
            $status = $response->getStatusCode();
            if ($status < 400 || $request->is('api/*') || $request->expectsJson()) {
                return $response;
            }

            $adminPath = AdminPanelPath::resolve();
            $isAdminPath = $request->is($adminPath) || $request->is($adminPath.'/*');
            if (! $isAdminPath) {
                return $response;
            }

            $adminView = $status >= 500 ? 'errors.admin.5xx' : 'errors.admin.4xx';
            if (! view()->exists($adminView)) {
                return $response;
            }

            return response()->view($adminView, [
                'status' => $status,
                'adminPath' => $adminPath,
            ], $status);
        });
    })->create();
```

### app/Exceptions/ApiExceptionRenderer.php (L1-60 と L125-163)
```php
<?php

declare(strict_types=1);

namespace App\Exceptions;

use App\Enums\ApiErrorCode;
use App\Http\Resources\ApiErrorResource;
use App\Support\Api\ApiError;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Throwable;

/**
 * framework 例外 → 統一 REST API v1 エラー envelope ({error: {code, message, status, details?}})。
 *
 * `api/*` パスのリクエストのみ書き換える。Inertia / web リクエストは既存の
 * レンダリング経路 (Error ページ / redirect) を保つため対象外。
 * bootstrap/app.php の withExceptions で render に配線する。
 */
final class ApiExceptionRenderer
{
    public static function shouldHandle(Request $request): bool
    {
        return $request->is('api/*');
    }

    public static function render(Throwable $e, Request $request): ?JsonResponse
    {
        if (! self::shouldHandle($request)) {
            return null;
        }

        // HttpResponseException は自前の response を持つ (FormRequest 由来等) ため尊重する
        if ($e instanceof HttpResponseException) {
            return null;
        }

        $error = self::toApiError($e);

        return ApiErrorResource::make($error)
            ->response()
            ->setStatusCode($error->status)
            ->withHeaders(self::extraHeaders($e));
    }

    private static function toApiError(Throwable $e): ApiError
    {
        if ($e instanceof AuthenticationException) {
            return ApiError::fromCode(
                ApiErrorCode::Unauthenticated,
                message: $e->getMessage() !== '' ? $e->getMessage() : null,
...
    /**
     * @return array<string, mixed>|null
     */
    private static function rateLimitDetails(HttpExceptionInterface $e): ?array
    {
        $headers = $e->getHeaders();
        if (! isset($headers['Retry-After'])) {
            return null;
        }
        $retryAfter = $headers['Retry-After'];
        if (is_string($retryAfter) && ctype_digit($retryAfter)) {
            return ['retry_after' => (int) $retryAfter];
        }
        if (is_int($retryAfter) || is_string($retryAfter)) {
            return ['retry_after' => $retryAfter];
        }

        return null;
    }

    /**
     * @return array<string, string>
     */
    private static function extraHeaders(Throwable $e): array
    {
        if (! $e instanceof HttpExceptionInterface) {
            return [];
        }

        $headers = [];
        foreach ($e->getHeaders() as $name => $value) {
            if (is_string($name) && (is_string($value) || is_int($value))) {
                $headers[$name] = (string) $value;
            }
        }

        return $headers;
    }
}
```

### Illuminate/Foundation/Exceptions/Handler.php (respond の実体)
```php
protected $finalizeResponseCallback;                                   // L149

protected function finalizeRenderedResponse($request, $response, Throwable $e)   // L738
{
    return $this->finalizeResponseCallback
        ? call_user_func($this->finalizeResponseCallback, $response, $e, $request)
        : $response;
}

public function respondUsing($callback)                                // L751
{
    $this->finalizeResponseCallback = $callback;

    return $this;
}
```

### vendor/inertiajs/inertia-laravel/src/Middleware.php (handle の後半 + version)
```php
        $response = $next($request);
        $response->headers->set('Vary', Header::INERTIA);

        if ($isRedirect = $response->isRedirect()) { $this->reflash($request); }

        if (! $request->header(Header::INERTIA)) { return $response; }

        if ($request->method() === 'GET' && $request->header(Header::VERSION, '') !== Inertia::getVersion()) {
            $response = $this->onVersionChange($request, $response);
        }
        // ...

    public function version(Request $request)
    {
        if (config('app.asset_url')) { return hash('xxh128', config('app.asset_url')); }
        if (file_exists($manifest = public_path('build/manifest.json'))) { return hash_file('xxh128', $manifest); }
        if (file_exists($manifest = public_path('mix-manifest.json'))) { return hash_file('xxh128', $manifest); }
        return null;
    }
```

### resources/js/inertia.ts (全文)
```ts
import type { ResolvedComponent } from "@inertiajs/svelte";

const pages = import.meta.glob<ResolvedComponent>("./pages/**/*.svelte");

/**
 * Inertia のページ名を ./pages 配下の Svelte component に解決する。
 * 未解決時は throw して「真っ白画面で原因不明」を防ぐ。
 */
export async function resolvePage(name: string): Promise<ResolvedComponent> {
    const loader = pages[`./pages/${name}.svelte`];
    if (!loader) {
        throw new Error(`Inertia page not found: ${name}`);
    }
    return loader();
}

```

### resources/js/pages/Contact/Thanks.svelte (全 gate を通っている参考ページ)
```svelte
<script lang="ts">
    import { CircleCheckBig } from "@lucide/svelte";
    import Button from "@/components/atoms/Button.svelte";
    import Card from "@/components/atoms/Card.svelte";
    import GuestLayout from "@/components/templates/GuestLayout.svelte";

    interface Props {
        appName: string;
    }

    let { appName }: Props = $props();
</script>

<GuestLayout {appName}>
    <div class="mx-auto w-full max-w-xl">
        <Card padding="lg" class="text-center" testId="contact-thanks">
            <CircleCheckBig class="mx-auto h-12 w-12 text-primary" />
            <h1 class="mt-4 text-h1">お問い合わせを受け付けました</h1>
            <p class="mt-3 text-caption text-text-secondary">
                内容を確認のうえ、担当者より折り返しご連絡いたします。<br />
                受付確認メールをお送りしましたのでご確認ください。
            </p>
            <div class="mt-6">
                <Button href="/" inertia>トップへ戻る</Button>
            </div>
        </Card>
    </div>
</GuestLayout>

```

### resources/js/components/atoms/Button.types.ts (anchor モードの型 / L60-100)
```ts

/** iconOnly のとき ariaLabel を型レベルで必須化する (a11y) */
type IconOnlyProps =
    | { iconOnly: true; ariaLabel: string }
    | { iconOnly?: false; ariaLabel?: string };

/**
 * button モードと anchor モードの discriminated union。
 * anchor モードでは button のセマンティクス (type/disabled) を型レベルで禁止する。
 * loading 中の anchor は aria-disabled + tabindex=-1 + click 抑止で操作を止める。
 */
type ModeProps =
    | {
          href?: never;
          inertia?: never;
          target?: never;
          rel?: never;
          type?: "button" | "submit" | "reset";
          disabled?: boolean;
          onclick?: (event: MouseEvent) => void;
          /** disclosure ボタン用。トグルの開閉状態を aria-expanded で公開する */
          ariaExpanded?: boolean;
          /** disclosure ボタンが制御する要素の id (aria-controls) */
          ariaControls?: string;
          /** フォーカス制御用の DOM 参照 (bindable, button モード限定・具体型を維持) */
          element?: HTMLButtonElement;
      }
    | {
          href: string;
          /** true で Inertia Link (SPA 内部遷移)。既定はネイティブ <a> (外部リンク・OAuth 開始等) */
          inertia?: boolean;
          target?: "_blank" | "_self";
          rel?: string;
          type?: never;
          disabled?: never;
          onclick?: (event: MouseEvent) => void;
          /** anchor モードでは disclosure props を型で禁止しつつ分割代入を可能にする */
          ariaExpanded?: never;
          ariaControls?: never;
          element?: never;
      };
```

### tests/Feature/Errors/ErrorPagesTest.php (全文)
```php
<?php

declare(strict_types=1);

// 自己完結エラーページ: Vite/Inertia/DB が壊れた 500 経路でも確実に描画できるよう、
// error blade は @vite / build asset / Inertia に依存せず inline CSS で自己完結すること。

it('renders every customer error view self-contained (no build/vite/inertia deps)', function (string $view): void {
    $html = view($view)->render();

    expect($html)->toContain('<style>')            // inline CSS で自己完結
        ->toContain('name="robots" content="noindex"')
        ->not->toContain('/build/')                // ビルド済み asset に依存しない
        ->not->toContain('@vite')
        ->not->toContain('data-page');             // Inertia マウントに依存しない
})->with([
    'errors.401',
    'errors.403',
    'errors.404',
    'errors.419',
    'errors.429',
    'errors.500',
    'errors.503',
]);

it('serves the custom 404 page for unknown routes', function (): void {
    $response = $this->get('/definitely-not-a-real-route-xyz');

    $response->assertNotFound();
    expect((string) $response->getContent())
        ->toContain('ページが見つかりません')
        ->toContain('name="robots" content="noindex"')
        ->not->toContain('/build/');
});

it('renders the admin error layout with a neutral operator tone (no customer branding)', function (): void {
    $html = view('errors.admin.4xx', ['status' => 404, 'adminPath' => 'admin'])->render();

    expect($html)->toContain('<style>')
        ->toContain('管理パネルに戻る')
        ->toContain('name="robots" content="noindex"')
        ->toContain('href="/admin"');
});

```

### tests/Feature/Security/TenantBoundaryPrecedenceTest.php (比較ヘルパ / L30-60)
```php

/** 不在の {project} id (18 桁 pattern 内・実在しない)。 */
const TBP_MISSING_PROJECT_ID = '999999999';

/**
 * cross-org の実在 project と 不在 id で応答が完全一致することを表明する。
 *
 * @param  callable(string): TestResponse  $request
 */
function tbpAssertNoOracle(callable $request, Project $crossOrgProject, int $expectedStatus): void
{
    $crossOrg = $request((string) $crossOrgProject->id);
    $missing = $request(TBP_MISSING_PROJECT_ID);

    expect($crossOrg->getStatusCode())->toBe(
        $expectedStatus,
        'cross-org の実在 project が期待した status で閉じていない',
    );
    expect(ResponseSignature::of($crossOrg))->toBe(
        ResponseSignature::of($missing),
        'cross-org 実在 project と 不在 project id の応答が一致しない (存在オラクル)',
    );
}

/** 他組織に実在する project を作る。 */
function tbpForeignProject(): Project
{
    [$otherOrg] = createOrganizationWithOwner('他組織');

    return Project::factory()->forOrganization($otherOrg)->create();
}
```

### tests/Architecture/ThrottleCoverageInventoryTest.php (作法の見本 / L44-108)
```php

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
```

### tests/Pest.php (グローバル適用 / L36-70)
```php
pest()->extend(TestCase::class)
    ->use(RefreshDatabase::class)
    ->beforeEach(function (): void {
        // Vite manifest 不在でも view が描画できるよう test では Vite をスタブする
        $this->withoutVite();

        // 未 fake の LLM 呼び出しを fail-fast させる guard。
        // (1) accumulator clear → (2) Prompt::stopFaking() → (3) PrismManager 差し替え
        // の 3 段で前テスト残留状態を一掃しつつ install する。テスト本体で
        // Prism::fake([...]) / Prompt::fake([...]) を呼ぶと guard は透過される。
        // Prism 基盤を直接テストする稀な Unit テストのみ
        // StrayLlmCallGuard::uninstallForTest($this->app) で opt-out できる。
        StrayLlmCallGuard::install($this->app);
    })
    ->afterEach(function (): void {
        try {
            // stray call が記録されていれば test を fail させる (Service 層の
            // try/catch fallback で guard 例外が握り潰されてもここで必ず赤くなる)
            StrayLlmCallGuard::flushAndFailIfStray();
        } finally {
            // flush が throw しても次テストへ accumulator / Prompt::$fake を漏らさない
            if (Prompt::isFaking()) {
                Prompt::stopFaking();
            }
            StrayLlmCallGuard::reset();
        }
    })
    ->in('Feature', 'Unit');

pest()->extend(TestCase::class)
    ->beforeEach(function (): void {
        $this->withoutVite();
    })
    ->in('Architecture');

```

## 補足 (実装で確認済みの事実)

- `@inertiajs/core` 3.3.1: `isInertiaResponse()` は `x-inertia` ヘッダの有無のみ。無ければ
  `handleNonInertiaResponse()` -> `dialog_default.show(response.data)` (エラーモーダル)。
  あれば status>=400 でも `isHttpException()` イベント発火後に `setPage()` される。
- `tests/Architecture/InertiaRenderPageExistsInvariantTest.php` の走査対象は `app/` と `routes/` のみ。
- aicue の pages 配下に Error.svelte は存在しない (新規)。Dashboard.svelte は存在する。
- `route('dashboard')` と `route('login')` は実在する (login は Fortify 登録)。
- app/ 配下に HTTP-date 形式の Retry-After を発行する箇所は 0 件 (grep 確認済み)。
- resources/views/errors/ には 401 / 403 / 404 / 419 / 429 / 4xx / 500 / 503 / 5xx と admin 配下が実在する。
- テストは `--parallel` 実行。tests/Pest.php で RefreshDatabase と withoutVite() がグローバル適用。
- `resources/js/pages/` 配下のページには ds-purity / typography-invariant / contrast-invariant /
  svelte-head-no-title / atomic-import-graph / page-shell-structure / svg-inline-allowlist の
  JS Architecture テストが掛かる。
