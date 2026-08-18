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


あなたは経験豊富なWebアプリケーションアーキテクトです。Laravel + Svelte アプリケーション改善の詳細設計をレビューしてください。

（アプリの使命・禁止事項は app-codex-review スキルにより AGENTS.md から自動挿入済み）

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

# 詳細設計: SES/SNS 署名検証の証明書取得経路の強化 (正典 t1 追従、裁定 AG-199)

## 使命・制約 (絶対遵守)

### アプリの使命 (North Star)

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
9. Artifact の使用(成果物はリポジトリ内のファイルとして出力する)

本施策に直接効くセキュリティ不変条件:

- **不変条件 8**: 外部 URL 取得は SSRF 検査経由 (`UrlSafetyInspector` / `PinnedHttpClient`)
- **不変条件 11**: キャッシュに入れるのは素のデータだけ。読み戻しは明示的に検査し、
  失敗したら `forget` する

### コーディングルール

- **PHPStan level 10** 必須 (`composer phpstan`)
- **Pest** (`composer test`)。**RefreshDatabase** はグローバル適用、個別 `DatabaseTransactions` 禁止
- テストデータは Factory で生成 (本施策は新モデルを追加しないので Factory 追加は無い)
- `declare(strict_types=1)` + 日本語コメント。アーリーリターン推奨
- `composer fix` (Pint) / `pnpm lint:fix`
- PHP 8.4 + Laravel 12
- **テストレーンは外向き HTTP が既定拒否** (`StrayHttpRequestGuard`)。`Http::fake()` を必ず書く

## 概念設計リファレンス

`devnotes/20260818-1756-sns-certificate-fetch-hardening/conceptual-design.md` (APPROVED / Round 4)

## 参照実装 (家系の原本を実読済み)

- `motivation@d1eabfbc` の `app/Services/Mail/Sns/SnsCertificateFetcher.php` /
  `SnsCertificateUrl.php` / `app/Services/Mail/Sns/AwsSnsSignatureVerifier.php` /
  `tests/Feature/Mail/SnsCertificateFetcherTest.php` — **構造**の出所
- `spirux@eabfab1b` の `app/Services/Mail/Sns/AwsSnsSignatureVerifier.php` —
  **(2) 取得直前の URL 同一性検査 / (6b) 検証済みのみキャッシュ昇格 / (7) 通信系限定の例外写像**
  の出所

vendor 実挙動 (`aws/aws-php-sns-message-validator` 1.10.0 を aicue の `vendor/` で実読):

- `MessageValidator::isLambdaStyle()` は `isset($message['SigningCertUrl'])` を見る。
  真なら `convertLambdaMessage()` が `SigningCertUrl` の値で `SigningCertURL` を**上書き**する
- `validateUrl()` が見るのは `scheme === 'https'` / 末尾 `.pem` / host が
  `sns.<region>.amazonaws.com(.cn)` の 3 点だけ。port も query も path 形式も見ない
- `certClient` が `false` を返すと `InvalidSnsMessageException` に吸収される
  (= 一時障害が 403 に化ける)。**必ず例外を投げる実装にする**
- `Message::$requiredKeys` は `['SigningCertURL', 'SigningCertUrl']` のどちらか一方でよい
  (= lambda キー単独の封筒も構築できる)
- `getStringToSign()` は public。SignatureVersion 1 は `OPENSSL_ALGO_SHA1`

## 施策一覧

| # | 施策名 | 変更ファイル | 優先度 |
|---|--------|------------|--------|
| A | 検証済み証明書 URL の値オブジェクト新設 | `app/Services/Mail/Sns/SnsCertificateUrl.php` (新規) | 高 |
| B | 証明書取得の予算を config へ置く | `config/services.php` | 高 |
| C | 証明書取得口の新設 (SSRF / 直列化 / キャッシュ / PEM 確認) | `app/Services/Mail/Sns/SnsCertificateFetcher.php` (新規) | 高 |
| D | 署名検証器の責務を絞る (両キー拒否 / URL 同一性 / 検証後昇格) | `app/Services/Mail/Sns/AwsSnsSignatureVerifier.php` | 高 |
| E | 取得口の契約を機械固定する | `tests/Architecture/SnsCertificateFetchContractTest.php` (新規) | 高 |
| F | 取得口の振る舞いテスト | `tests/Feature/Mail/SnsCertificateFetcherTest.php` (新規) | 高 |
| G | 署名検証器のテスト改修 | `tests/Unit/Mail/AwsSnsSignatureVerifierTest.php` | 高 |
| H | middleware の end-to-end テスト追加 | `tests/Feature/Mail/SesSignatureMiddlewareTest.php` | 高 |
| I | テスト部品の拡張 (署名済み通知の生成) | `tests/Support/SnsTestData.php` | 高 |
| J | 既存目録の更新 | `tests/Architecture/CachePayloadPlainDataGateTest.php` / `ExternalClientTimeoutInventoryTest.php` / `ValidationAttributeCoverageTest.php` | 高 |
| K | 文書更新 | `docs/ses-mail-runbook.md` / `docs/architecture.md` | 中 |

---

## A. 検証済み証明書 URL の値オブジェクト新設

### 変更箇所

- 新規: `app/Services/Mail/Sns/SnsCertificateUrl.php`
- 移設元: `app/Services/Mail/Sns/AwsSnsSignatureVerifier.php` L80-112 (`isValidSnsCertUrl`)

### 波及変更

- TypeScript 型定義: なし
- API Resource/DTO: なし
- テストファイル: `tests/Unit/Mail/AwsSnsSignatureVerifierTest.php` の URL 境界テストは
  そのまま (verifier 経由で同じ判定が働くため) + credential つき URL の行を追加

### 現行コード

`AwsSnsSignatureVerifier` の private メソッド (呼び出し側の作法で守られている)。

```php
private function isValidSnsCertUrl(string $url): bool
{
    $parts = parse_url($url);
    // scheme / port / query / fragment / host / path を検査
}
```

### 変更後コード

```php
<?php

declare(strict_types=1);

namespace App\Services\Mail\Sns;

/**
 * **検証済み**の SNS 証明書 URL (値オブジェクト)。
 *
 * 「呼び出し側が検査してから渡す」という**契約ではなく型**で担保する。外部取得の防御は
 * 取得口のクラスの中にも閉じていなければ、経路が 1 本であることを検査で保証しても
 * 「経路の中で検査が抜けた」ことに気付けない。
 * `SnsCertificateFetcher` はこの型しか受け取らない。
 *
 * 検証内容 (`AwsSnsSignatureVerifier::isValidSnsCertUrl()` をここへ移設。二重実装を作らない):
 *  - scheme は https 固定
 *  - credential (user / pass) を持たない
 *  - port 未指定 or 443
 *  - query / fragment を持たない
 *  - host は `sns.{region}.amazonaws.com` (`sns.` 接頭辞必須、region の区間あり)
 *  - path は `/SimpleNotificationService-*.pem`
 *
 * **vendor (`MessageValidator::validateUrl`) より厳しい**ことがこの型の価値である
 * (vendor は `.pem` 終端 + `sns.<region>.amazonaws.com(.cn)` しか見ないため、
 * 同一 host 上の任意の `.pem` と中国パーティションを許してしまう)。
 *
 * 中国パーティション (amazonaws.com.cn) は対象外。利用予定が出たら明示的に広げる。
 */
final readonly class SnsCertificateUrl
{
    private function __construct(public string $value) {}

    /**
     * @throws SnsSignatureInvalidException 書式が SNS 証明書 URL でない (恒久 = 403)
     */
    public static function fromString(string $url): self
    {
        if (! self::isValid($url)) {
            throw new SnsSignatureInvalidException('untrusted SigningCertURL');
        }

        return new self($url);
    }

    private static function isValid(string $url): bool
    {
        $parts = parse_url($url);
        if (! is_array($parts)) {
            return false;
        }
        if (($parts['scheme'] ?? '') !== 'https') {
            return false;
        }
        if (isset($parts['user']) || isset($parts['pass'])) {
            return false;
        }
        if (($parts['port'] ?? 443) !== 443) {
            return false;
        }
        if (isset($parts['query']) || isset($parts['fragment'])) {
            return false;
        }
        $host = $parts['host'] ?? '';
        if (preg_match('/^sns\.[a-z0-9-]+\.amazonaws\.com$/', $host) !== 1) {
            return false;
        }

        return preg_match('#^/SimpleNotificationService-[A-Za-z0-9]+\.pem$#', $parts['path'] ?? '') === 1;
    }
}
```

### PHPStan 適合チェック

- [x] 戻り値の型が明示されている (`self` / `bool`)
- [x] `parse_url()` は `array<string, int|string>|false|null` なので `is_array()` で narrowing
- [x] `$parts['host'] ?? ''` は `int|string` になりうるが、`preg_match` の第 2 引数は string 型宣言。
      **`$parts['host']` が int になる形は存在しない**が PHPStan は union を見る可能性がある —
      実装時に `(string) ($parts['host'] ?? '')` へ明示 cast するか、
      `Webmozart\Assert\Assert::string()` を使う (現行 t0 は cast 無しで level 10 を通っているので、
      まずは現行と同じ書き方にし、赤くなったら cast する)
- [x] 配列を返さない (値オブジェクトを返す)

### テスト計画

- [x] 既存 `tests/Unit/Mail/AwsSnsSignatureVerifierTest.php` の「cert URL 境界」データセットは
      そのまま通ること (振る舞い保存)
- [x] 新規データ行: `https://user:pass@sns.us-east-1.amazonaws.com/SimpleNotificationService-x.pem`
      (credential) を拒否
- [x] 新規データ行: `https://sns.us-east-1.amazonaws.com/SimpleNotificationService-x.pem#frag`
      (fragment) を拒否

### リスク

- 判定式を移設するだけなので振る舞いは変わらない。credential 拒否だけが**新たに拒否する形**で、
  これは SSRF 検査 (`SsrfDenyReason::CredentialInUrl`) とも整合する

---

## B. 証明書取得の予算を config へ置く

### 変更箇所

- `config/services.php` の `'ses' => [ ... ]` (L29-54)

### 波及変更

- TypeScript 型定義: なし / API Resource/DTO: なし
- テストファイル: `tests/Architecture/SnsCertificateFetchContractTest.php` が値を読む

### 変更後コード

`'ses'` 配列の中、`...ExternalClientTimeouts::awsControlClientOptions()` の**前**に足す。

```php
    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        // ...(既存のまま)...

        // SNS 署名検証で使う証明書取得の予算。**env にしない** — 環境ごとに変えてよい
        // 運用値ではなく、大小関係そのものが契約だからである (冪等キーの保持期間と同じ理由)。
        //
        // 大小関係の契約 (裁定 AG-199):
        //   待ち上限 < 接続 + 読み取り + 後処理余裕 <= ロック寿命
        //   0        < 2    + 3        + 1          = 6  <= 8
        // 待ち上限が 0 なのは「取れなければ待たずに 503 へ倒す」実装だからで、
        // これは値ではなく**実装の形**として tests/Architecture/SnsCertificateFetchContractTest.php
        // が固定する (`->block(` を持たないこと)。右の不等号は同テストが算術で固定する。
        //
        // ★この配列は Laravel の MailManager が SesV2Client の構築引数へ素通しする
        //   (上のコメント参照)。AWS が知らないキーは無視される — その前提は
        //   ExternalClientTimeoutInventoryTest の「vendor 契約」テストが behavioral に固定する
        //   (既存の options / sns_topic_arns と同じ扱い)。
        'webhook' => [
            'cert_connect_timeout_seconds' => 2,
            'cert_read_timeout_seconds' => 3,
            // ロックを保持したまま行う後処理 (キャッシュ再確認・PEM 解析) の余裕。
            'cert_post_fetch_budget_seconds' => 1,
            // ロックの寿命。処理中に切れると「前の worker が通信中なのに次が取れる」= permit 1 が壊れる。
            // 逆に無期限にすると worker の異常終了で恒久的な 503 になるので有限にする。
            'cert_lock_ttl_seconds' => 8,
            // 証明書名が変われば cache キーも変わるので、差し替え事故の窓を短く保つ意味で 1 時間。
            'cert_cache_ttl_seconds' => 3600,
            // PEM は数 KB。上限を超えた応答は検証もキャッシュもしない
            // (**メモリの上界ではない**。詳細は SnsCertificateFetcher の docblock)。
            'cert_max_bytes' => 16384,
        ],

        ...ExternalClientTimeouts::awsControlClientOptions(),
    ],
```

### PHPStan 適合チェック

- [x] 読み出しは `Config::integer('services.ses.webhook.…')` で int に確定させる
      (`config()` の `mixed` を持ち回らない)

### テスト計画

- [x] 施策 E が値の大小関係を検査する
- [x] 既存 `ExternalClientTimeoutInventoryTest` の
      「vendor 契約: MailManager は services.ses を SesV2Client の構築引数へ素通しする」が
      新キー追加後も緑であること (= AWS が未知キーを無視することの実測)

### リスク

- `services.ses` は `SesV2Client` の構築引数へ素通しされる。既に `options` /
  `sns_topic_arns` という app 専用キーが同居しており、上の behavioral テストが
  「未知キーで壊れない」ことを毎回確かめている。壊れる版が来たら**そのテストが赤くなる**

---

## C. 証明書取得口の新設

### 変更箇所

- 新規: `app/Services/Mail/Sns/SnsCertificateFetcher.php`

### 波及変更

- TypeScript 型定義: なし / API Resource/DTO: なし
- container: `AppServiceProvider` の `bind(SnsSignatureVerifier::class, AwsSnsSignatureVerifier::class)`
  はそのままでよい (コンストラクタ依存は自動解決される。`UrlSafetyInspector` は
  `SsrfPinServiceProvider` が singleton 登録済み)
- テストファイル: 施策 F (新規) / 施策 J (キャッシュ目録)

### 変更後コード

```php
<?php

declare(strict_types=1);

namespace App\Services\Mail\Sns;

use Illuminate\Contracts\Cache\Lock;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Log;
use Kent013\SsrfPin\Enums\SsrfDenyReason;
use Kent013\SsrfPin\UrlSafetyInspector;
use Throwable;

/**
 * SNS 署名検証用の証明書 (PEM) の取得口。**外部 HTTP を行うのはこのクラスだけ**である
 * (`tests/Architecture/SnsCertificateFetchContractTest.php` が固定する)。
 *
 * 責務を 1 クラスへ閉じる理由: 「無認証のリクエストが外部取得を誘発する」経路であり、
 * 防御が散ると必ずどれかが抜ける。ここが持つ防御は 7 点:
 *
 *  1. **取得先の限定** — 引数の型 `SnsCertificateUrl` が SNS 証明書 URL の厳格な書式を
 *     保証する (呼び出し側の作法ではなく**型**で担保する)
 *  2. **SSRF 検査** — `UrlSafetyInspector::inspect()` (セキュリティ不変条件 8)。
 *     `PinnedHttpClient` は本文を返さないので証明書取得には使えず、inspect → fetch の形にする
 *  3. **redirect 禁止** (`withoutRedirecting`)
 *  4. **時間予算** — 接続と読み取りを短く取る
 *  5. **応答サイズ上限** — PEM は数 KB。超えた応答は**検証もキャッシュもしない**
 *  6. **PEM 確認** — `openssl_x509_read()` が通ったものだけを扱う
 *  7. **キャッシュと同時取得の抑止** — 下記
 *
 * ## 同時取得の抑止 (ロックの契約)
 *
 * ロックキーは `CERT_FETCH_LOCK_KEY` **1 本だけ**で、`CERT_FETCH_PERMITS = 1` と
 * 1 対 1 に対応する。2 以上へ増やすならロックキーを permit 数へ分割する実装が同時に要る
 * (定数だけ書き換えても実効挙動は変わらない = 検査が偽の安心を与える) ため、
 * 契約テストが `=== 1` と「`Cache::lock()` の site がちょうど 1 つ」を要求する。
 *
 * **URL ごとにロックキーを分けない**。厳格な書式検証を通る URL の末尾は攻撃者が
 * 自由に変えられるため、分けると「存在しない証明書名を並べるだけで同時取得数を増やせる」。
 *
 * **取れなければ待たずに一時障害 (503) へ倒す**。待つと待ち時間ぶん worker を占有し、
 * このクラスが作ろうとしている上界の議論が成立しなくなる。503 は SNS の再送対象なので、
 * 即時の恒久ドロップにはならない (ただし再送期間を超えて競合が続けば配送断念はありうる)。
 *
 * 時間の大小関係 (`config/services.php` の `services.ses.webhook`):
 *
 *   待ち上限 (0) < 接続 (2) + 読み取り (3) + 後処理余裕 (1) = 6 <= ロック寿命 (8)
 *
 * 右の不等号は「取得中にロックが失効して 2 人目が取り始めない」ためである。
 *
 * ## キャッシュの規律
 *
 * - キーは `CACHE_PREFIX` + URL の sha256 (**キーに URL の平文を残さない**)
 * - 載せるのは**署名検証が通った PEM だけ**である。昇格は `remember()` で、
 *   呼ぶのは `AwsSnsSignatureVerifier` が `validate()` を通したあとだけである
 *   (未検証の応答を載せると、壊れた証明書を寿命のあいだ配り続けて正当な通知を
 *    403 にし続ける = 自作の fail-closed になる)
 * - **キャッシュの読み書きで署名検証を失敗させない**。読みの失敗は miss 扱い、
 *   書きの失敗はログのみで続行する (キャッシュは最適化であり、壊れた日に webhook が
 *   全滅すると抑止漏れに直結する)
 * - 読み戻しは「文字列 + 空でない + PEM として読める」を検査し、失敗したら `forget` して
 *   miss 扱いにする (セキュリティ不変条件 11)
 *
 * ## 例外の写像 (出所で境界を分ける)
 *
 * | 出所 | 意味 | 扱い |
 * |---|---|---|
 * | `Cache::lock()` の `InvalidArgumentException` | ロック非対応 store 等の**設定・実装の誤り** | **fail-fast** (握り潰さない) |
 * | `Lock::get()` の `Throwable` | ロック基盤の一時障害 | **503** (排他できない状態で取りに行かない) |
 * | 取得できなかった (競合) | 正常な競合 | **503** |
 * | `ConnectionException` / `RequestException` | 接続 / DNS / TLS / timeout / HTTP エラー応答 | **503** |
 * | それ以外の `Throwable` (TypeError 等) | **プログラム不具合** | **写像せず伝播** (503 で隠さない) |
 * | SSRF 判定の DNS 解決失敗 | 一時障害 | **503** |
 * | SSRF 判定のその他の拒否 / サイズ超過 / PEM 不正 | 恒久 | **403** |
 * | cache の `get` / `put` / `forget` / `release` の `Throwable` | 最適化の障害 | **best-effort** |
 *
 * ## 保証しないもの (誇張しない)
 *
 * - **DNS rebinding は解消しない**。検査時と接続時で名前解決が変わる TOCTOU は残り、
 *   private IP への TCP 接続と TLS ClientHello そのものは発生する。HTTP 層での取得を
 *   制限するのは「通常の CA 検証が有効であること」を前提とした TLS であり、
 *   取得先の host が型で `sns.<region>.amazonaws.com` に固定されていることに依存する
 * - **応答サイズ上限も時間予算もメモリ使用量の上界ではない**。Laravel の HTTP client は
 *   既定で非 stream なので本文は先に全部メモリへ載り、長さを測る位置を変えても上界にならない。
 *   時間の上限も、帯域が大きければ受信バイト数を制限しない。上限の役割は
 *   「**期待と違う応答を検証・キャッシュに固定しない**」ことだけである
 * - **ロックが与える worker 占有の上界は条件付き**である。1 要求のロック保持が寿命を超えた
 *   場合 (worker 停止・キャッシュ基盤の長時間停止) は取得が重なりうる。所有者つきの解放で
 *   誤解放は防ぐが、重なり自体は防がない
 * - **キャッシュ store が共有されない構成 (file 等) ではホストごとに 1 回取りに行く**
 *   (既定 `database` は共有される)
 */
final readonly class SnsCertificateFetcher
{
    /** キャッシュキーの接頭辞 (URL は sha256 にする = キーに平文を残さない) */
    public const string CACHE_PREFIX = 'sns:cert:';

    /**
     * 同時取得数。**単一ロックキーと 1 対 1 に対応する** (上の docblock 参照)。
     * 2 以上へ増やすならロックキーの分割が同時に要る。
     */
    public const int CERT_FETCH_PERMITS = 1;

    /** 取得ロックのキー (1 本だけ持つ) */
    private const string CERT_FETCH_LOCK_KEY = 'sns:cert:fetch';

    public function __construct(
        private HttpFactory $http,
        private UrlSafetyInspector $inspector,
    ) {}

    /**
     * キャッシュ済みの PEM。無いとき / キャッシュ障害のとき / 読み戻せない値だったときは null。
     */
    public function cached(SnsCertificateUrl $url): ?string
    {
        $key = self::cacheKey($url);

        try {
            /** @var mixed $value */
            $value = Cache::get($key);
        } catch (Throwable) {
            // キャッシュは最適化である。障害で署名検証を止めない (miss 扱い)。
            Log::warning('mail.sns.cert_cache_read_failed');

            return null;
        }

        if ($value === null) {
            return null;
        }

        if (is_string($value) && $value !== '' && self::isReadablePem($value)) {
            return $value;
        }

        // 読み戻せない値が残っていたら消して miss 扱いにする (不変条件 11)。
        $this->forgetQuietly($key);

        return null;
    }

    /**
     * 同時 1 本に直列化した実取得。
     *
     * 手順: 非ブロッキングでロック → **ロック保持中にキャッシュ再確認** → 取得 →
     * finally で所有者つき解放。
     *
     * @throws SnsSignatureInvalidException SSRF 判定 / サイズ / PEM 不正 (恒久 = 403)
     * @throws SnsVerificationUnavailableException 競合 / ロック基盤障害 / 取得失敗 / DNS 解決失敗 (503)
     */
    public function fetchSerialized(SnsCertificateUrl $url): string
    {
        // ★ここで投げるのは「ロック非対応 store」等の設定・実装の誤りだけなので**捕まえない**
        //   (可用性の退避に飲み込ませない = fail-fast)。I/O は起きない。
        $lock = Cache::lock(
            self::CERT_FETCH_LOCK_KEY,
            Config::integer('services.ses.webhook.cert_lock_ttl_seconds'),
        );

        try {
            $acquired = $lock->get();
        } catch (Throwable $e) {
            // ロック基盤の一時障害。排他できない状態では取りに行かない
            // (同時取得数の上界を黙って壊すより、再送に任せるほうが安全である)。
            throw new SnsVerificationUnavailableException('certificate lock unavailable', 0, $e);
        }

        if ($acquired !== true) {
            // 待たない (上の docblock 参照)。
            throw new SnsVerificationUnavailableException('certificate fetch is busy');
        }

        try {
            // ロックを取るまでの間に別の要求が埋めているかもしれない。
            return $this->cached($url) ?? $this->fetchRemoteAndValidate($url);
        } finally {
            // 取得しても hit で返しても**必ず**解放する (所有者つきの比較削除なので
            // 他所有者の鍵は消さない)。解放の失敗は飲む (finally で投げると元の例外を壊す)。
            $this->releaseQuietly($lock);
        }
    }

    /**
     * **署名検証が通った** PEM をキャッシュへ昇格させる (best-effort)。
     *
     * 保存に失敗しても署名検証は済んでいる。次回また取りに行くだけなので落とさない。
     */
    public function remember(SnsCertificateUrl $url, string $pem): void
    {
        try {
            Cache::put(
                self::cacheKey($url),
                $pem,
                Config::integer('services.ses.webhook.cert_cache_ttl_seconds'),
            );
        } catch (Throwable) {
            Log::warning('mail.sns.cert_cache_write_failed');
        }
    }

    /**
     * キャッシュに一切触らない実取得 (SSRF 検査 → HTTP → サイズ → PEM 確認)。
     *
     * @throws SnsSignatureInvalidException
     * @throws SnsVerificationUnavailableException
     */
    private function fetchRemoteAndValidate(SnsCertificateUrl $url): string
    {
        $decision = $this->inspector->inspect($url->value);
        if (! $decision->allowed) {
            // DNS 解決失敗だけが一時障害である。書式検証を通った host が private IP へ
            // 解決される状態は DNS rebinding か split-horizon DNS であり、再送では直らない。
            if ($decision->reason === SsrfDenyReason::DnsResolutionFailed) {
                throw new SnsVerificationUnavailableException('certificate host is not resolvable');
            }

            throw new SnsSignatureInvalidException('certificate URL rejected by SSRF inspection');
        }

        try {
            $body = $this->http
                ->connectTimeout(Config::integer('services.ses.webhook.cert_connect_timeout_seconds'))
                ->timeout(Config::integer('services.ses.webhook.cert_read_timeout_seconds'))
                ->withoutRedirecting()
                ->get($url->value)
                ->throw()
                ->body();
        } catch (ConnectionException|RequestException $e) {
            // 接続 / DNS / TLS / timeout / HTTP エラー応答**だけ**を一時障害へ写像する。
            // TypeError や LogicException は写像しない (プログラム不具合を 503 で隠さない)。
            throw new SnsVerificationUnavailableException('certificate fetch failed', 0, $e);
        }

        if (strlen($body) > Config::integer('services.ses.webhook.cert_max_bytes')) {
            // 証明書としてあり得ない大きさ = 取得先が期待と違う。恒久扱いにする。
            throw new SnsSignatureInvalidException('certificate response is too large');
        }

        if (! self::isReadablePem($body)) {
            throw new SnsSignatureInvalidException('certificate response is not a valid PEM');
        }

        return $body;
    }

    /**
     * PEM として読めるか。
     *
     * `openssl_x509_read()` は失敗時に false を返しつつ warning も出す。warning は
     * Laravel のエラーハンドラが `ErrorException` へ昇格させるため、**戻り値と例外の両方**を
     * 「読めなかった」に畳む (エラー抑制演算子は使わない)。
     * 戻り値の `OpenSSLCertificate` は**ここから外へ出さない** (キャッシュ境界は常に string)。
     */
    private static function isReadablePem(string $pem): bool
    {
        try {
            return openssl_x509_read($pem) !== false;
        } catch (Throwable) {
            return false;
        }
    }

    private static function cacheKey(SnsCertificateUrl $url): string
    {
        return self::CACHE_PREFIX.hash('sha256', $url->value);
    }

    private function forgetQuietly(string $key): void
    {
        try {
            Cache::forget($key);
        } catch (Throwable) {
            Log::warning('mail.sns.cert_cache_forget_failed');
        }
    }

    private function releaseQuietly(Lock $lock): void
    {
        try {
            $lock->release();
        } catch (Throwable) {
            // 解放できなくても寿命の失効で回復する。**その間は後続が 503 になる**ことを
            // 観測できるようにするためのログである (平文は出さない)。
            Log::warning('mail.sns.cert_lock_release_failed');
        }
    }
}
```

### PHPStan 適合チェック

- [x] 戻り値の型がすべて明示されている
- [x] `Cache::get()` の `mixed` は `is_string()` で narrowing してから返す
- [x] `Lock::get()` の戻り値は `mixed` なので `!== true` で判定する
- [x] `Config::integer()` で int を確定させる (`config()` の mixed を持ち回らない)
- [x] `openssl_x509_read()` の `OpenSSLCertificate|false` は `!== false` の比較にしか使わず、
      外へ出さない
- [x] 配列を返さない

### テスト計画

施策 F 参照。

### リスク

- **ロック基盤の障害で 503 になる**。motivation は「排他なしの取得へ退避」を選んでいるが、
  裁定は「退避してよい」(任意) としている。aicue は退避しない —
  退避するとこの施策が作ろうとしている上界がその瞬間に消えるためで、
  かつキャッシュ / ロック基盤 (`database` store) が落ちている状況ではアプリ全体が
  すでに機能していない。この判断は本設計に明記する
- `Cache::lock()` は `array` store (テストレーン) / `database` store (既定) の
  どちらも `LockProvider` を実装しているので fail-fast は起きない

---

## D. 署名検証器の責務を絞る

### 変更箇所

- `app/Services/Mail/Sns/AwsSnsSignatureVerifier.php` (全面書き換え。113 行 → 約 90 行)

### 波及変更

- TypeScript 型定義: なし / API Resource/DTO: なし
- **コンストラクタ引数が変わる** (`HttpFactory` → `SnsCertificateFetcher`)。
  `AppServiceProvider::bind()` は自動解決なので変更不要だが、
  `tests/Unit/Mail/AwsSnsSignatureVerifierTest.php` の `makeSnsVerifier()` は変更が要る (施策 G)
- `tests/Architecture/ExternalClientTimeoutInventoryTest.php` の rationale 文言 (施策 J)
- `tests/Architecture/ValidationAttributeCoverageTest.php` の**行番号キー** (施策 J)

### 現行コード

`app/Services/Mail/Sns/AwsSnsSignatureVerifier.php` L27-113 (`certClient()` /
`certUrl()` / `isValidSnsCertUrl()` を自分で持つ)。

### 変更後コード

```php
<?php

declare(strict_types=1);

namespace App\Services\Mail\Sns;

use Aws\Sns\Exception\InvalidSnsMessageException;
use Aws\Sns\Message;
use Aws\Sns\MessageValidator;

/**
 * AWS SDK ベースの SNS 署名検証実装。
 *
 * 署名の暗号検証 (canonical string / SignatureVersion / 証明書検証) は AWS SDK の
 * `MessageValidator` に委譲し、自前で再実装しない。wrapper の責務は 4 点:
 *  1. **SDK が実際に取りに行く URL** を特定し、値オブジェクトで書式検証する
 *     (両キー同時送信は拒否する。下の `effectiveCertUrl()` の docblock 参照)
 *  2. 証明書取得を `SnsCertificateFetcher` へ委譲する (SSRF 検査 / 直列化 / キャッシュ / PEM 確認)
 *  3. SDK が閉じ込めた URL 以外を要求してきたら取りに行かない (fail-closed)
 *  4. **署名検証が通った証明書だけ**をキャッシュへ昇格させる
 *
 * `MessageValidator` は証明書取得を `certClient` へ委譲できる。これを使い
 * **取得失敗 (一時障害) と署名不一致 (恒久) を確実に分ける**: certClient が投げた
 * `SnsVerificationUnavailableException` は `validate()` を素通りして伝播し、
 * `validate()` が投げる `InvalidSnsMessageException` は取得済みの証明書での検証失敗
 * = 署名不一致だけになる。SDK 既定の `file_get_contents` 再取得にも例外メッセージ判定にも
 * 依存しない。
 *
 * ★**certClient は決して false を返さない**。vendor は false を
 *   `InvalidSnsMessageException` に吸収するため、返すと一時障害が 403 に化ける。
 *
 * ★**解放から昇格までの窓**: ロックが包むのは外向き通信ちょうどで、署名検証はその後に走る。
 *   この間に届いた別の要求は同じ証明書をもう一度取りに行きうる。窓の長さは署名検証 1 回ぶん
 *   (取得に比べて無視できる) で、起きても取得が 1 回余分に走るだけである。
 *   ロックを署名検証まで伸ばしても同時外向き通信数の上界は改善せず、
 *   ロック寿命を伸ばす必要が出る (= 障害時に後続が 503 になる時間が延びる) ので伸ばさない。
 */
final class AwsSnsSignatureVerifier implements SnsSignatureVerifier
{
    public function __construct(private readonly SnsCertificateFetcher $certificates) {}

    public function verify(Message $message): void
    {
        // 1) SDK が実際に取りに行く URL を特定し、型で書式検証する
        //    (不正は SnsCertificateUrl::fromString が SnsSignatureInvalidException を投げる = 403)。
        $url = SnsCertificateUrl::fromString($this->effectiveCertUrl($message));

        // 新しく取得した PEM だけをここに載せる。**署名検証が通ってから**昇格させる。
        /** @var string|null $fresh */
        $fresh = null;

        $validator = new MessageValidator(
            function (string $requested) use ($url, &$fresh): string {
                // SDK が検証済みの URL 以外を要求したら取りに行かない (最後の砦)。
                if ($requested !== $url->value) {
                    throw new SnsSignatureInvalidException('unexpected SigningCertURL requested');
                }

                $cached = $this->certificates->cached($url);
                if ($cached !== null) {
                    return $cached; // 正常時はここ。ロックも外向き通信も無い。
                }

                $fresh = $this->certificates->fetchSerialized($url);

                return $fresh;
            }
        );

        try {
            $validator->validate($message);
        } catch (InvalidSnsMessageException $e) {
            throw new SnsSignatureInvalidException('signature mismatch', 0, $e);
        }

        if (is_string($fresh)) {
            $this->certificates->remember($url, $fresh);
        }
    }

    /**
     * SDK (`MessageValidator`) が**実際に取得する** 証明書 URL。
     *
     * vendor の `MessageValidator::isLambdaStyle()` は `isset($message['SigningCertUrl'])` を
     * 先に判定し、真なら `convertLambdaMessage()` が `SigningCertUrl` の値で
     * `SigningCertURL` を**上書き**する。したがって `SigningCertURL` を先に読む実装だと、
     * **両キーを同時に送られたときに「検査した URL」と「取得する URL」が食い違い、
     * アプリ側の追加検証 (port 443 固定 / query 禁止 / path 形式 / 中国パーティション排除) を
     * 回避できる** (vendor 自身の検査は host 形式と `.pem` 終端しか見ない)。
     * この上書き順序は aws/aws-php-sns-message-validator 1.10.0 を実読して確認した。
     *
     * 対策は 2 段:
     *  1. **両キー同時存在は拒否する** (正当な SNS 通知はどちらか一方しか持たない。
     *     両方あるのは検査を食い違わせる意図しか無い)
     *  2. 単独のときは SDK と同じ実効キー (Lambda キー優先) を返す
     *
     * @throws SnsSignatureInvalidException
     */
    private function effectiveCertUrl(Message $message): string
    {
        $canonical = $message['SigningCertURL'] ?? null;
        $lambda = $message['SigningCertUrl'] ?? null;

        if ($canonical !== null && $lambda !== null) {
            throw new SnsSignatureInvalidException('conflicting SigningCertURL / SigningCertUrl');
        }

        // SDK は Lambda キーがあればそれで上書きするため、同じ優先順を採る。
        $url = $lambda ?? $canonical ?? '';

        return is_string($url) ? $url : '';
    }
}
```

### PHPStan 適合チェック

- [x] `Message::offsetGet()` は `mixed` を返すので `is_string()` で narrowing
- [x] 参照渡しの `$fresh` は `@var string|null` を宣言し、`is_string()` で narrowing
- [x] closure の戻り値型 `: string` を明示
- [x] 配列を返さない

### テスト計画

施策 G / H 参照。

### リスク

- **「SDK が別 URL を要求する」分岐は現行 vendor (1.10.0) では到達しない**。
  lambda キー単独の封筒は `convertLambdaMessage()` で同じ値が `SigningCertURL` に入るため、
  certClient が受け取る文字列は必ず検証済み URL と一致する。
  この分岐は**将来の vendor 変更に対する砦**であり behavioral テストを持てない。
  「保証しないもの」として docblock と `docs/architecture.md` に明記する
  (到達可能な半分 = 一致するとき取得へ進むこと、はテストで固定する)
- `certUrl()` が `SigningCertURL` を先に読む t0 の挙動は消える。
  **後方互換の並走を残さない** (思考原則 3) ので、旧メソッドは同じ変更で削除する

---

## E. 取得口の契約を機械固定する

### 変更箇所

- 新規: `tests/Architecture/SnsCertificateFetchContractTest.php`

### 波及変更

- なし (テストのみ)

### 設計

AGENTS.md「静的検査 (gate) と走査器の共通規約」に従う。
**汎用の走査器は新設しない** — 守る対象が名指しの 1 クラスなので、
既存の中立走査器 `Tests\Support\PhpReferenceScanner` (namespace / use / group use を解いて
完全修飾名で返す) を使い、判定条件だけを本テストに持つ。

#### 走査根 (docblock に明記する)

```
app/Services/Mail/Sns/            (ディレクトリ全体)
app/Http/Middleware/VerifySnsSignature.php
app/Http/Controllers/Webhooks/SesNotificationController.php
```

根が実在しないときは **fail-fast** (無言で空集合にしない)。

#### 検査

| # | 検査 | 内容 |
|---|------|------|
| C1 | 取得口の唯一性 | 走査根の中で `Illuminate\Http\Client\Factory` または `Illuminate\Support\Facades\Http` を参照するファイルが `app/Services/Mail/Sns/SnsCertificateFetcher.php` **ちょうど 1 件** (exact-fit。未登録も残骸も赤) |
| C2 | 時間の大小関係 | `0 < connect`、`0 < read`、`0 < post`、`connect + read + post <= lock_ttl`、`0 < cache_ttl`、`0 < max_bytes` |
| C3 | 単一ロックキー = permit 1 | `SnsCertificateFetcher::CERT_FETCH_PERMITS === 1`。取得口の字句走査で `Cache::lock(` の site が **1 件**、その第 1 引数が `self::CERT_FETCH_LOCK_KEY` **ちょうど**、クラスが持つロックキー定数が **1 本** |
| C4 | 待ち上限は構造的に 0 | 取得口に `->block(` の site が **0 件** (= 待たない実装であることの機械固定。AG-199 の左の不等号はこれで満たす) |
| C5 | TLS 検証を無効化していない | 走査根に `withoutVerifying` の T_STRING、`->verify(` のメソッド呼び出し、`'verify' =>` / `"verify" =>` の配列キーが **0 件** |
| C6 | 空振り検知 | 走査根が 3 つとも解決でき、走査ファイル数 > 0、C1 の母集団 (参照 site を持つファイル) が空でない、C3 の token 走査で `Cache::lock(` を **1 件以上**検出している |
| C7 | 解決できない形を落とす | 走査根のファイルが読めない / `PhpToken::tokenize()` が空を返す場合は**未解決として失敗**させる (無言で候補から外さない) |
| C8 | 検出力の裏取り (負例) | 合成入力 (nowdoc) に対し、C1 / C3 / C4 / C5 の各判定器が**違反を検出する**こと |
| C9 | 誤検出しないこと (正例) | 合成入力に対し、規定どおりの書き方を**違反と判定しない**こと。C5 の語彙は接頭辞つき (`myWithoutVerifying`) ・打ち消しつき (`notWithoutVerifying`) ・接尾辞つき (`withoutVerifyingSomething`) の 3 形を置き、いずれも一致しないこと |

#### 判定の作り方 (規約 (a)(b)(e) への適合)

- **(a) 完全修飾名で突き合わせる**: C1 は `PhpReferenceScanner::references()` が返す
  完全修飾名で照合する (短名一致にしない)。
  同走査器が「部分修飾名 (`T_NAME_QUALIFIED`) は解決しないため検出力を主張しない」と
  docblock で宣言しているので、**本テストも部分修飾名について検出力を主張しない**ことを
  docblock に明記する
- **(b) 解決できない形は落とす**: C7。読めないファイル・トークン化できないファイルは
  例外にして gate を失敗させる
- **(e) 語彙一致はトークンの完全一致**: C5 は `PhpToken` の `T_STRING` / 文字列リテラルの
  **値の完全一致**で判定する (部分文字列一致も正規表現の語境界も使わない)。
  区切りは PHP の字句解析そのものなので、「何を区切りとするか」は
  「`PhpToken::tokenize()` が返すトークン境界」と docblock で宣言する
- **(d) 集めた結果は必ず判定に使う**: 走査結果はすべて assertion の入力になる
  (数えるだけの目録を作らない)

#### 保証しないもの (docblock に書く)

- 部分修飾名で書かれた参照 (走査器の既知の穴)
- 変数経由の指定 (`$m = 'withoutVerifying'; $req->{$m}()`)、
  オプション配列を動的に組み立てる書き方、`Http::withOptions([...])` に
  実行時に組み立てた配列を渡す形
- 走査根の**外**にある証明書取得 (根を 3 つに限定しているため。
  app/ 全体の `Http::` facade は `ExternalSeamInventory` の担当で、
  **injected な `HttpFactory` は同目録の母集団に入らない**という非対称は
  `docs/architecture.md` に書く)
- リポジトリの外にある設定 (php.ini の `openssl.cafile` など)

### PHPStan 適合チェック

- [x] Architecture レーンは DB を使わない (`tests/Pest.php` の宣言どおり)。
      本テストは config とファイル走査だけを見る
- [x] `config()` は `config()->integer(...)` で int を確定させる
- [x] `PhpToken::tokenize()` の戻り値は `list<PhpToken>` として扱う

### テスト計画

本施策そのものがテストである。**先に赤くしてから本体を書く** (思考原則 5):
C8 の負例が既存実装 (施策 C 実装前) で赤くなることを確認してから施策 C を書く。

### リスク

- 走査根を 3 つに絞っているので、SNS 証明書取得を**別のディレクトリ**に新設されたら
  沈黙する。これは docblock に明記し、`ExternalSeamInventory` (`Http::` facade) と
  `ExternalClientTimeoutInventoryTest` (AWS / Flysystem) の 2 目録との**役割分担**として
  `docs/architecture.md` に書く

---

## F. 取得口の振る舞いテスト

### 変更箇所

- 新規: `tests/Feature/Mail/SnsCertificateFetcherTest.php`

### 波及変更

- `tests/Architecture/CachePayloadPlainDataGateTest.php` の面目録 (施策 J)

### 設計

`UrlSafetyInspector` は `ExternalFakeDeclaration::neverSwapped()` により偽物にできない。
差し替えるのは**その依存**である `Kent013\SsrfPin\Contracts\DnsResolverInterface` で、
出荷 fake の `Kent013\SsrfPin\Testing\FakeDnsResolver` を bind する。
`UrlSafetyInspector` は singleton なので、bind 後に `forgetInstance()` して作り直す。

```php
/** @param list<string> $ips */
function bindSnsDnsResolver(array $ips): void
{
    app()->bind(
        DnsResolverInterface::class,
        fn (): DnsResolverInterface => new FakeDnsResolver(['sns.us-east-1.amazonaws.com' => $ips]),
    );
    app()->forgetInstance(UrlSafetyInspector::class);
}

beforeEach(function (): void {
    bindSnsDnsResolver(['203.0.113.10']); // 公開 IP (TEST-NET-3)
});
```

### テスト一覧

| # | テスト名 | 検証内容 |
|---|---------|---------|
| F1 | 昇格した証明書は次回キャッシュから返る | `fetchSerialized` → `remember` → `cached` が同じ PEM を返し、HTTP は 1 回 |
| F2 | 昇格しなければキャッシュに載らない | `fetchSerialized` を 2 回呼ぶと HTTP は 2 回 (**負例。要件 6 の核**) |
| F3 | private IP に解決される host は 403 系 | `SnsSignatureInvalidException` + `Http::assertNothingSent()` |
| F4 | DNS 解決失敗は 503 系 | `SnsVerificationUnavailableException` + `Http::assertNothingSent()` |
| F5 | PEM でない応答は 403 系でキャッシュしない | 2 回とも取りに行く |
| F6 | サイズ超過は 403 系でキャッシュしない | `config(['services.ses.webhook.cert_max_bytes' => 16])` |
| F7 | HTTP エラー応答は 503 系 | `Http::response('nope', 500)` |
| F8 | 接続失敗は 503 系 | `Http::fake(fn () => throw new ConnectionException('boom'))` |
| F9 | **プログラム不具合は写像しない** | `Http::fake(fn () => throw new LogicException('boom'))` → `LogicException` が伝播する (**要件 7 の核**) |
| F10 | キャッシュ読みの例外は miss 扱い | 読みが投げる store を差し込んでも PEM が返る |
| F11 | キャッシュ書きの例外は握る | `remember()` が投げない |
| F12 | 読み戻せない値は forget して miss 扱い | 事前に `Cache::put(key, 'not a pem')` → HTTP へ行き、キーが消えている |
| F13 | ロック保持中は 503 で自分では取りに行かない | 先に `Cache::lock('sns:cert:fetch', 10)->get()` |
| F14 | ロック取得後の再確認で hit したら解放して返す | 1 回目の read は miss、2 回目は hit を返す store |
| F15 | ロック非対応 store は fail-fast | `LockProvider` を実装しない store → `InvalidArgumentException` |
| F16 | ロック基盤の例外は 503 (退避しない) | `get()` が投げる Lock を返す store → `Http::assertNothingSent()` |

F10 / F12 / F14 / F15 / F16 のために、テストファイル内に無名クラスの `Store` 実装を置く
(motivation の準拠テストと同型)。

### PHPStan 適合チェック

- [x] 無名 `Store` 実装は `Illuminate\Contracts\Cache\Store` の全メソッドを実装する
- [x] `expect(...)->toThrow(...)` で例外型を固定する

### リスク

- **F9 は `Http::fake()` のコールバックが投げた例外が包まれないことに依存する**。
  実装時に実測し、包まれる場合は「`RequestException` / `ConnectionException` 以外は
  写像しない」ことを別の注入点 (`HttpFactory` の差し替え) で確かめる形に変える
- F13 のロックキーは実装の private 定数と同じ文字列をテストに書く。
  文字列の二重管理になるが、施策 E の C3 が実装側を 1 本に固定しているので
  「テストだけが古い」状態はテストの失敗として現れる

---

## G. 署名検証器のテスト改修

### 変更箇所

- `tests/Unit/Mail/AwsSnsSignatureVerifierTest.php`

### 波及変更

- `makeSnsVerifier()` の構築引数が変わる

### 現行コード

```php
function makeSnsVerifier(): AwsSnsSignatureVerifier
{
    return new AwsSnsSignatureVerifier(app(HttpFactory::class));
}
```

### 変更後コード

```php
function makeSnsVerifier(): AwsSnsSignatureVerifier
{
    return new AwsSnsSignatureVerifier(app(SnsCertificateFetcher::class));
}
```

`beforeEach` で `bindSnsDnsResolver(['203.0.113.10'])` 相当を呼ぶ
(ヘルパは施策 F と共用するため `tests/Pest.php` へ置く。
AGENTS.md の実装規約どおり「複数ファイルから使うテストヘルパは `tests/Pest.php`」)。

### テスト一覧 (既存 + 追加)

| # | テスト名 | 状態 |
|---|---------|------|
| G1 | cert ホストが不正なら 403 系で HTTP 取得すらしない | 既存 (維持) |
| G2 | cert URL 境界: http / port / host / path / query を拒否 | 既存 + **credential / fragment の 2 行を追加** |
| G3 | cert 取得失敗は 503 系 | 既存 (維持) |
| G4 | cert 到達後に署名検証が落ちれば 403 系 | 既存 (維持) |
| G5 | 正当な cert URL は HTTP 取得まで進む | 既存 (維持) |
| G6 | **両キー同時送信は 403 系で HTTP 取得すらしない** | **新規 (要件 1)** |
| G7 | lambda キー単独でも同じ URL を取りに行く | **新規** (実効キーの優先順が SDK と揃っていること) |
| G8 | **署名検証が落ちたらキャッシュに載らない** | **新規 (要件 6)**。同じ通知を 2 回検証すると HTTP が 2 回 |
| G9 | **署名検証が通ったらキャッシュに載る** | **新規 (要件 6)**。署名済み通知を 2 回検証すると HTTP は 1 回 |

G9 は施策 I の署名済み通知ビルダーを使う。

### リスク

- G9 が動くには本物の鍵と証明書が要る。施策 I で実行時生成する
  (この環境で `openssl_pkey_new` / `openssl_csr_sign` / `openssl_sign` が動くことは実測済み)

---

## H. middleware の end-to-end テスト追加

### 変更箇所

- `tests/Feature/Mail/SesSignatureMiddlewareTest.php`

### 波及変更

- なし (既存 4 テストは `FakeSnsSignatureVerifier` 依存なのでそのまま通る)

### 追加するテスト

| # | テスト名 | 検証内容 |
|---|---------|---------|
| H1 | 実 verifier: 両キー同時送信は 403 で外向き通信をしない | 要件 1 が HTTP ステータスまで通ること |
| H2 | 実 verifier: 証明書取得の HTTP 失敗は 503 | 要件 7 の 503 側が middleware の写像まで通ること |
| H3 | 実 verifier: SSRF 拒否は 403 | 要件 4 の 403 側が middleware の写像まで通ること |

`app()->instance(SnsSignatureVerifier::class, ...)` を**呼ばない**ことで実 verifier を通す。
`bindSnsDnsResolver()` + `Http::fake()` が要る。

### リスク

- 受け口 route には `throttle:webhook-ses` が付いている。1 テストあたりの POST は
  1〜2 回なので 300/分の上限には当たらない

---

## I. テスト部品の拡張 (署名済み通知の生成)

### 変更箇所

- `tests/Support/SnsTestData.php`

### 波及変更

- なし (追加のみ。既存の `notification()` / `bounceMessageJson()` は変えない)

### 変更後コード (追加分)

```php
    /**
     * 署名検証が**通る**通知と、それに対応する証明書 PEM を作る。
     *
     * 鍵と自己署名証明書はテストプロセス内で 1 度だけ作り、静的に持ち回す
     * (鍵生成は数百 ms かかるため)。署名対象の文字列は vendor の
     * `MessageValidator::getStringToSign()` から得る (署名仕様を自前で再実装しない)。
     * SignatureVersion 1 は SHA1 が仕様である。
     *
     * @param  array<string, mixed>  $overrides
     * @return array{payload: array<string, mixed>, pem: string}
     */
    public static function signedNotification(string $sesMessageJson, array $overrides = []): array
    {
        // 1) 鍵と証明書 (静的キャッシュ)
        // 2) $payload = self::notification($sesMessageJson, $overrides)
        // 3) $stringToSign = (new MessageValidator)->getStringToSign(new Message($payload))
        // 4) openssl_sign($stringToSign, $signature, $privateKey, OPENSSL_ALGO_SHA1)
        // 5) $payload['Signature'] = base64_encode($signature)
    }

    /** 署名検証は通らないが PEM としては読める証明書 (取得口のテスト用) */
    public static function certificatePem(): string
    {
        // signedNotification と同じ生成器を使う (二重実装を作らない)
    }
```

### PHPStan 適合チェック

- [x] `openssl_pkey_new()` の `OpenSSLAsymmetricKey|false` を `Assert::notFalse()` で narrowing
- [x] `openssl_csr_sign()` の `OpenSSLCertificate|false` も同様
- [x] 戻り値の shape を `@return array{payload: array<string, mixed>, pem: string}` で宣言

### リスク

- `openssl_csr_new()` は openssl の設定ファイルを必要とする実装がある。
  この環境では動くことを実測済みだが、CI で失敗した場合は
  **固定の PEM 文字列 + 固定の秘密鍵**をテスト部品に埋め込む形へ切り替える
  (`openssl_x509_read()` は有効期限を見ないので、期限切れの固定証明書でも支障は無い)

---

## J. 既存目録の更新

### J-1. `tests/Architecture/CachePayloadPlainDataGateTest.php`

L2 (書き込み経路) へ 1 件追加:

```php
    'app/Services/Mail/Sns/SnsCertificateFetcher.php::put' => [
        'count' => 1,
        'payload' => 'SNS 署名検証用の証明書 (PEM) の素の文字列。オブジェクトは渡さない',
        'proof' => 'tests/Feature/Mail/SnsCertificateFetcherTest.php',
        'rationale' => '署名検証が通った証明書だけを URL の sha256 をキーにして寿命つきで保存する。読み戻しは is_string + 非空 + PEM として読めることを検査し、失敗したら Cache::forget して miss 扱いにする',
    ],
```

L3 (面) へ追加:

```php
    'app/Services/Mail/Sns/SnsCertificateFetcher.php' => [
        'role' => 'write',
        'rationale' => 'SNS 証明書の取得口。get / put / forget と Cache::lock を持つ唯一のファイルで、payload は PEM の素の文字列だけである',
    ],
    'tests/Feature/Mail/SnsCertificateFetcherTest.php' => [
        'role' => 'write',
        'rationale' => 'キャッシュ障害・ロック競合・読み戻し不能を再現するため cache store を差し替えて put / forget / lock を直接使う',
    ],
```

`tests/Unit/Mail/AwsSnsSignatureVerifierTest.php` と
`tests/Feature/Mail/SesSignatureMiddlewareTest.php` が**キャッシュ記号に触れる場合**も
面目録への登録が要る。触れない書き方 (取得口経由でしか cache を触らない) にできるなら
登録しない — **実装時に gate を回して実測で決める** (目録は exact-fit なので、
不要な登録は「残骸」として赤くなる)。

### J-2. `tests/Architecture/ExternalClientTimeoutInventoryTest.php`

`AwsSnsSignatureVerifier` の rationale を実態へ合わせる (免除理由 `AwsValueObjectOnly` は変えない):

```php
    AwsSnsSignatureVerifier::class => [
        'surface' => 'exempt',
        'reason' => ExternalClientBoundaryExemption::AwsValueObjectOnly,
        'rationale' => 'MessageValidator は署名検証のみで送信しない。証明書取得は SnsCertificateFetcher へ委譲する',
    ],
```

**`SnsCertificateFetcher` / `SnsCertificateUrl` は本目録へ登録しない**。
同目録の母集団は `Aws\` / `League\Flysystem\` / `Illuminate\Filesystem\` / `Storage` を
参照するファイルであり、新設 2 クラスはどれも参照しないため。
登録すると「実在しない登録 = 残骸」として赤くなる。

### J-3. `tests/Architecture/ValidationAttributeCoverageTest.php`

`UNPARSEABLE_CALL_INVENTORY` のキーは `{相対パス}@{行番号}#validate` である。
`AwsSnsSignatureVerifier` の `$validator->validate($message)` は行が変わるので、
**実装後の実際の行番号に合わせて更新する** (この目録は行番号ずれで fail する = 再確認を強制する設計)。

```php
const UNPARSEABLE_CALL_INVENTORY = [
    'app/Services/Mail/Sns/AwsSnsSignatureVerifier.php@{実装後の行}#validate' => 'AWS SNS MessageValidator::validate (Laravel validation ではなくルール配列を持たない)',
];
```

### J-4. `tests/Support/StrayHttpRequestGuard.php` の説明コメント

L32-36 の「`AwsSnsSignatureVerifier::certClient` は `catch (\Throwable)` で例外を握り潰す」
という記述は**事実でなくなる** (施策 C で `ConnectionException|RequestException` に絞る)。
握り潰しの実例としては `FxRateService::fetchFromFrankfurter` が残るので、
SNS 側の記述を「証明書取得の失敗を `SnsVerificationUnavailableException` へ写像するため、
取りに行った事実が 503 に化ける」へ書き換える (guard の存在意義の説明としては成立する)。

---

## K. 文書更新

### K-1. `docs/ses-mail-runbook.md`

- 「構成概要」に証明書取得口 (`SnsCertificateFetcher`) を足す
- 「障害一次切り分け」に新しいログキーを足す:
  `mail.sns.cert_cache_read_failed` / `mail.sns.cert_cache_write_failed` /
  `mail.sns.cert_cache_forget_failed` / `mail.sns.cert_lock_release_failed`
- 「注意点」に「証明書取得が競合すると 503 を返す (SNS が再送する)。
  403 と混ぜない」を足す

### K-2. `docs/architecture.md`

新しい節「SNS 署名検証の証明書取得」を足し、**保証しないものの正本**にする:

- 8 要件と aicue での実装形 (待たない / 単一ロックキー / ロック基盤障害は 503)
- 3 つの目録の役割分担
  (`SnsCertificateFetchContractTest` = SNS 証明書取得の唯一性と時間の契約 /
   `ExternalSeamInventory` = `Http::` facade の到達点 /
   `ExternalClientTimeoutInventoryTest` = AWS / Flysystem の到達境界)。
  **injected な `HttpFactory` は `ExternalSeamInventory` の母集団に入らない**という
  非対称をここに書く
- 保証しないもの (DNS rebinding / サイズ上限はメモリ上界ではない /
  ロックの上界は条件付き / 非共有 cache store / 「SDK が別 URL を要求する」分岐は
  現行 vendor では到達しない)

---

## 実装順序 (テストファースト)

1. 施策 E の負例を書き、**赤いこと**を確認する (施策 C 実装前なので当然赤い)
2. 施策 I (テスト部品) → 施策 F / G / H のテストを書き、**赤いこと**を確認する
3. 施策 A / B / C / D を実装して緑にする
4. 施策 J (目録) を回して exact-fit のずれを潰す
5. 施策 K (文書)
6. `composer test` / `composer phpstan` / `vendor/bin/pint --test` を全 green にする

## 実装モード

| 項目 | 内容 |
|------|------|
| 推奨モード | standalone |
| 判断根拠 | `app/Services/Mail/Sns/` と 3 つの Architecture 目録に閉じた変更で、他ドメインと接触しない。ただし目録 3 本 (キャッシュ / 到達境界 / validation 行番号) が exact-fit なので、他タスクが同じ目録へ足すと必ず競合する。単独ブランチで一気に緑にするのが安全 |
| 競合リスク | `tests/Architecture/CachePayloadPlainDataGateTest.php` の目録 (件数 exact-fit) / `config/services.php` / `tests/Pest.php` (共用ヘルパの追加) |


## 関連する現行コード

### app/Services/Mail/Sns/AwsSnsSignatureVerifier.php

```php
<?php

declare(strict_types=1);

namespace App\Services\Mail\Sns;

use Aws\Sns\Exception\InvalidSnsMessageException;
use Aws\Sns\Message;
use Aws\Sns\MessageValidator;
use Illuminate\Http\Client\Factory as HttpFactory;

/**
 * AWS SDK ベースの SNS 署名検証実装。
 *
 * 署名の暗号検証 (canonical string / SignatureVersion / 証明書検証) は AWS SDK の
 * `MessageValidator` に委譲し、自前再実装しない。wrapper の責務は 2 点:
 *  1. 証明書 URL を SNS 証明書 URL の厳格パターンに限定 (不正 = 恒久 → Invalid、SSRF 遮断)
 *  2. 証明書取得を自前 HTTP client に差し込み (`certClient`)、取得失敗を
 *     `SnsVerificationUnavailableException` (一時障害 → 503) に正規化する
 *
 * `MessageValidator` は cert 取得を `certClient` callable に委譲できる。これを使い
 * **取得失敗 (一時障害) と署名不一致 (恒久) を確実に分離**する: certClient が投げた
 * Unavailable は validate() を素通りして伝播し、validate() が投げる
 * `InvalidSnsMessageException` は cert 取得後の検証失敗 = 署名不一致のみとなる。
 * これにより SDK 既定の `file_get_contents` 再取得や例外メッセージ判定に依存しない。
 */
final class AwsSnsSignatureVerifier implements SnsSignatureVerifier
{
    public function __construct(private readonly HttpFactory $http) {}

    public function verify(Message $message): void
    {
        // 1) 証明書 URL を SNS 証明書 URL に限定。不正 = 恒久 → 403。
        if (! $this->isValidSnsCertUrl($this->certUrl($message))) {
            throw new SnsSignatureInvalidException('untrusted SigningCertURL');
        }

        // 2) cert 取得は certClient に差し込む。取得失敗は certClient 内で Unavailable に
        //    正規化され validate() を伝播 → 503。validate() の InvalidSnsMessageException は
        //    cert 取得済の検証失敗 = 署名不一致 = 恒久 → 403。
        $validator = new MessageValidator($this->certClient());
        try {
            $validator->validate($message);
        } catch (InvalidSnsMessageException $e) {
            throw new SnsSignatureInvalidException('signature mismatch', 0, $e);
        }
    }

    /**
     * MessageValidator に渡す証明書取得 callable。
     * 取得失敗 (ネットワーク / HTTP エラー) は一時障害として SnsVerificationUnavailableException に。
     *
     * @return callable(string): string
     */
    private function certClient(): callable
    {
        return function (string $url): string {
            try {
                return $this->http
                    ->connectTimeout(5)
                    ->timeout(10)
                    ->withoutRedirecting()
                    ->get($url)
                    ->throw()
                    ->body();
            } catch (\Throwable $e) {
                throw new SnsVerificationUnavailableException('certificate fetch failed', 0, $e);
            }
        };
    }

    private function certUrl(Message $message): string
    {
        // SDK バージョン差で大文字小文字が揺れるため両対応。
        $url = $message['SigningCertURL'] ?? $message['SigningCertUrl'] ?? '';

        return is_string($url) ? $url : '';
    }

    /**
     * SNS 証明書 URL の厳格検証:
     *  - scheme は https 固定
     *  - port 未指定 or 443
     *  - query / fragment を持たない
     *  - host は `sns.{region}.amazonaws.com` (`sns.` prefix 必須、region セグメントあり)
     *  - path は `/SimpleNotificationService-*.pem`
     *
     * China partition (amazonaws.com.cn) は対象外。利用予定が出たら allowlist を明示拡張する。
     */
    private function isValidSnsCertUrl(string $url): bool
    {
        $parts = parse_url($url);
        if (! is_array($parts)) {
            return false;
        }
        if (($parts['scheme'] ?? '') !== 'https') {
            return false;
        }
        if (($parts['port'] ?? 443) !== 443) {
            return false;
        }
        if (isset($parts['query']) || isset($parts['fragment'])) {
            return false;
        }
        $host = $parts['host'] ?? '';
        if (preg_match('/^sns\.[a-z0-9-]+\.amazonaws\.com$/', $host) !== 1) {
            return false;
        }
        $path = $parts['path'] ?? '';

        return preg_match('#^/SimpleNotificationService-[A-Za-z0-9]+\.pem$#', $path) === 1;
    }
}

```

### app/Http/Middleware/VerifySnsSignature.php

```php
<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Services\Mail\Sns\SnsSignatureInvalidException;
use App\Services\Mail\Sns\SnsSignatureVerifier;
use App\Services\Mail\Sns\SnsVerificationUnavailableException;
use Aws\Sns\Message;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

/**
 * SNS 通知の署名検証 middleware。
 *
 * Stripe webhook と同じく無認証 + 署名検証 + CSRF 外で扱う。
 * 暗号検証は SnsSignatureVerifier に委譲し、結果に応じて HTTP ステータスを出し分ける:
 *  - 外側 JSON 不正 / 非 object / list 配列 / envelope 構造不備 → 400
 *  - 署名不正 / 証明書 URL 不正 → 403 (恒久。再送しても直らない)
 *  - 証明書取得の一時障害 → 503 (SNS が再試行する)
 *
 * 検証済 Aws\Sns\Message を request attribute (`sns_message`) に載せ、Controller の二重 decode を避ける。
 */
final class VerifySnsSignature
{
    public function __construct(private readonly SnsSignatureVerifier $verifier) {}

    public function handle(Request $request, Closure $next): Response
    {
        // SNS は Content-Type: text/plain で配信するため raw body を decode する。
        $decoded = json_decode($request->getContent(), true);
        // 外側 JSON 不正 / 非 object / list 配列は不正リクエスト → 400 (アプリ例外化=500 しない)。
        if (! is_array($decoded) || array_is_list($decoded)) {
            return response('invalid payload', Response::HTTP_BAD_REQUEST);
        }
        /** @var array<string, mixed> $decoded */

        // SNS envelope の構造不備 (必須キー欠落・型不正) は AWS 由来でない不正 body → 400。
        // Message constructor が必須キー欠落で InvalidArgumentException を投げる。
        try {
            $message = new Message($decoded);
        } catch (\InvalidArgumentException) {
            return response('invalid payload', Response::HTTP_BAD_REQUEST);
        }

        try {
            $this->verifier->verify($message);
        } catch (SnsSignatureInvalidException) {
            Log::warning('mail.sns.invalid_signature'); // 平文は出さない

            return response('invalid signature', Response::HTTP_FORBIDDEN);
        } catch (SnsVerificationUnavailableException) {
            // 一時障害は 503 (Service Unavailable) → SNS が再試行する。
            // 403 (恒久) に混ぜると正当通知を恒久ドロップ (= 抑止漏れ) するため必ず分離する。
            Log::warning('mail.sns.verification_unavailable');

            return response('verification unavailable', Response::HTTP_SERVICE_UNAVAILABLE);
        }

        // 検証済 Message を Controller に引き渡す (二重 decode 回避)。
        $request->attributes->set('sns_message', $message);

        /** @var Response $response */
        $response = $next($request);

        return $response;
    }
}

```

### tests/Unit/Mail/AwsSnsSignatureVerifierTest.php

```php
<?php

declare(strict_types=1);

use App\Services\Mail\Sns\AwsSnsSignatureVerifier;
use App\Services\Mail\Sns\SnsSignatureInvalidException;
use App\Services\Mail\Sns\SnsVerificationUnavailableException;
use Aws\Sns\Message;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Support\Facades\Http;
use Tests\Support\SnsTestData;

function makeSnsVerifier(): AwsSnsSignatureVerifier
{
    return new AwsSnsSignatureVerifier(app(HttpFactory::class));
}

test('cert ホストが不正なら署名不正 (恒久) で HTTP 取得すらしない', function (): void {
    Http::fake();
    $message = new Message(SnsTestData::notification('{}', [
        'SigningCertURL' => 'https://s3.amazonaws.com/SimpleNotificationService-x.pem',
    ]));

    expect(fn () => makeSnsVerifier()->verify($message))
        ->toThrow(SnsSignatureInvalidException::class);

    Http::assertNothingSent();
});

test('cert URL 境界: http / port / host / path / query を拒否', function (string $certUrl): void {
    Http::fake();
    $message = new Message(SnsTestData::notification('{}', ['SigningCertURL' => $certUrl]));

    expect(fn () => makeSnsVerifier()->verify($message))
        ->toThrow(SnsSignatureInvalidException::class);

    Http::assertNothingSent();
})->with([
    'http scheme' => ['http://sns.us-east-1.amazonaws.com/SimpleNotificationService-x.pem'],
    'non-443 port' => ['https://sns.us-east-1.amazonaws.com:8443/SimpleNotificationService-x.pem'],
    'wrong host' => ['https://sns.us-east-1.amazonaws.com.evil.com/SimpleNotificationService-x.pem'],
    'wrong path' => ['https://sns.us-east-1.amazonaws.com/evil.pem'],
    'with query' => ['https://sns.us-east-1.amazonaws.com/SimpleNotificationService-x.pem?a=1'],
]);

test('cert 取得失敗は一時障害 (Unavailable → 503 再試行)', function (): void {
    Http::fake([SnsTestData::CERT_URL => Http::response('', 500)]);
    $message = new Message(SnsTestData::notification('{}'));

    expect(fn () => makeSnsVerifier()->verify($message))
        ->toThrow(SnsVerificationUnavailableException::class);
});

test('cert 到達後に署名検証が落ちれば署名不正 (恒久)', function (): void {
    // cert URL は到達するが、中身は本物の証明書ではないため署名検証で落ちる。
    Http::fake([SnsTestData::CERT_URL => Http::response('-----BEGIN CERTIFICATE-----\nnot-a-real-cert\n-----END CERTIFICATE-----', 200)]);
    $message = new Message(SnsTestData::notification('{}'));

    expect(fn () => makeSnsVerifier()->verify($message))
        ->toThrow(SnsSignatureInvalidException::class);
});

test('正当な cert URL は HTTP 取得まで進む (Unavailable にならず署名段に到達)', function (): void {
    Http::fake([SnsTestData::CERT_URL => Http::response('cert-body', 200)]);
    $message = new Message(SnsTestData::notification('{}'));

    // 署名段で Invalid になる (到達はした = Unavailable ではない) ことで分類を確認。
    expect(fn () => makeSnsVerifier()->verify($message))
        ->toThrow(SnsSignatureInvalidException::class);

    Http::assertSent(fn ($request): bool => $request->url() === SnsTestData::CERT_URL);
});

```

### tests/Feature/Mail/SesSignatureMiddlewareTest.php

```php
<?php

declare(strict_types=1);

use App\Models\EmailSuppression;
use App\Services\Mail\Sns\SnsSignatureInvalidException;
use App\Services\Mail\Sns\SnsSignatureVerifier;
use App\Services\Mail\Sns\SnsVerificationUnavailableException;
use Illuminate\Testing\TestResponse;
use Tests\Support\FakeSnsSignatureVerifier;
use Tests\Support\SnsTestData;

/**
 * VerifySnsSignature middleware のステータス出し分け (400/403/503)。
 */
beforeEach(function (): void {
    config(['services.ses.sns_topic_arns' => [SnsTestData::TOPIC_ARN]]);
});

/** @return array<string, mixed> */
function snsBouncePayload(string $email = 'bounce@example.com'): array
{
    return SnsTestData::notification(SnsTestData::bounceMessageJson('Permanent', [$email]));
}

/** @param array<string, mixed> $payload */
function postSns(array $payload): TestResponse
{
    return test()->call('POST', '/ses/notification', [], [], [], [], (string) json_encode($payload));
}

test('署名不正は 403 で抑止記録なし', function (): void {
    app()->instance(SnsSignatureVerifier::class, new FakeSnsSignatureVerifier(
        new SnsSignatureInvalidException('bad'),
    ));

    $response = postSns(snsBouncePayload());

    $response->assertStatus(403);
    expect(EmailSuppression::query()->count())->toBe(0);
});

test('一時障害は 503 (再試行可能、403 にしない) で抑止記録なし', function (): void {
    app()->instance(SnsSignatureVerifier::class, new FakeSnsSignatureVerifier(
        new SnsVerificationUnavailableException('cert fetch failed'),
    ));

    postSns(snsBouncePayload())->assertStatus(503);
    expect(EmailSuppression::query()->count())->toBe(0);
});

test('外側 JSON 不正 / list 配列は 400', function (): void {
    app()->instance(SnsSignatureVerifier::class, new FakeSnsSignatureVerifier);

    test()->call('POST', '/ses/notification', [], [], [], [], 'not-json')->assertStatus(400);
    test()->call('POST', '/ses/notification', [], [], [], [], '[1,2,3]')->assertStatus(400);
});

test('SNS envelope 構造不備 (必須キー欠落) は 400', function (): void {
    app()->instance(SnsSignatureVerifier::class, new FakeSnsSignatureVerifier);

    // Type だけで他必須キーがない → Message constructor が InvalidArgumentException。
    postSns(['Type' => 'Notification', 'TopicArn' => SnsTestData::TOPIC_ARN])->assertStatus(400);
});

```

### tests/Support/SnsTestData.php

```php
<?php

declare(strict_types=1);

namespace Tests\Support;

/**
 * SES/SNS 通知テスト用の envelope / SES message ビルダー。
 *
 * Aws\Sns\Message の必須キー (Message/MessageId/Timestamp/TopicArn/Type/Signature/
 * SigningCertURL/SignatureVersion、確認系は SubscribeURL/Token) を満たす最小データを作る。
 */
final class SnsTestData
{
    public const TOPIC_ARN = 'arn:aws:sns:us-east-1:123456789012:ses-events';

    public const CERT_URL = 'https://sns.us-east-1.amazonaws.com/SimpleNotificationService-abc123.pem';

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    public static function notification(string $sesMessageJson, array $overrides = []): array
    {
        return array_merge([
            'Type' => 'Notification',
            'MessageId' => 'msg-'.bin2hex(random_bytes(6)),
            'TopicArn' => self::TOPIC_ARN,
            'Message' => $sesMessageJson,
            'Timestamp' => '2026-07-02T00:00:00.000Z',
            'SignatureVersion' => '1',
            'Signature' => 'ZmFrZQ==',
            'SigningCertURL' => self::CERT_URL,
        ], $overrides);
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    public static function subscriptionConfirmation(array $overrides = []): array
    {
        return array_merge([
            'Type' => 'SubscriptionConfirmation',
            'MessageId' => 'msg-'.bin2hex(random_bytes(6)),
            'TopicArn' => self::TOPIC_ARN,
            'Message' => 'You have chosen to subscribe.',
            'Timestamp' => '2026-07-02T00:00:00.000Z',
            'SignatureVersion' => '1',
            'Signature' => 'ZmFrZQ==',
            'SigningCertURL' => self::CERT_URL,
            'SubscribeURL' => 'https://sns.us-east-1.amazonaws.com/?Action=ConfirmSubscription',
            'Token' => 'token-'.bin2hex(random_bytes(8)),
        ], $overrides);
    }

    /**
     * @param  list<string>  $emails
     */
    public static function bounceMessageJson(string $bounceType, array $emails, ?string $timestamp = '2026-07-02T00:00:00.000Z'): string
    {
        $recipients = array_map(static fn (string $e): array => ['emailAddress' => $e], $emails);

        return (string) json_encode([
            'notificationType' => 'Bounce',
            'bounce' => [
                'bounceType' => $bounceType,
                'bouncedRecipients' => $recipients,
                'timestamp' => $timestamp,
            ],
            'mail' => ['messageId' => 'ses-'.bin2hex(random_bytes(4))],
        ]);
    }

    /**
     * @param  list<string>  $emails
     */
    public static function complaintMessageJson(array $emails): string
    {
        $recipients = array_map(static fn (string $e): array => ['emailAddress' => $e], $emails);

        return (string) json_encode([
            'notificationType' => 'Complaint',
            'complaint' => [
                'complainedRecipients' => $recipients,
                'timestamp' => '2026-07-02T00:00:00.000Z',
            ],
            'mail' => ['messageId' => 'ses-'.bin2hex(random_bytes(4))],
        ]);
    }
}

```
