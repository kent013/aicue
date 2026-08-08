# 詳細設計: external-seam-funnel (外部到達点の既定拒否目録 / 検知 v1)

## 使命・制約（絶対遵守）

### アプリの使命（North Star）

**AI-CUE** は、現場に既にある**作業手順書(SOP)を起点に**、AI が撮るべきカットを設計した**動画シナリオ**を生成し、そのシナリオを**スマホ(PWA)でナビゲーション撮影**することで、専門知識ゼロの現場作業者でも**標準化されたマニュアル動画**を作れるようにする。

- 「思考ゼロ・編集ゼロ」— 台本作成・撮影判断・編集の 3 ハードルを AI とナビ撮影で肩代わりする。
- 競合(OJT を撮って形式化する tebiki)と異なり、**標準作業を起点に AI が教材設計し撮影を指示する**（撮影者・教える人のスキルに品質を依存させない）。
- 熟練者の暗黙知を動画マニュアルという形式知へ変換する装置（SECI）。

> 詳細な設計は本リポジトリの `doc/`（01〜10）に置く。ドメインをテンプレート構造へどうマップしたかは `doc/08`〜`doc/10`（特に `doc/10 §10.8` に設計レビュー反映済み）。
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

- **PHPStan level 10** 必須（`composer phpstan`）
- **Pest**テストフレームワーク（`composer test`）
- **RefreshDatabase** + `--parallel` 並列実行（`tests/Pest.php` でグローバル適用、個別 `DatabaseTransactions` 使用禁止）
- **テストデータは必ず Factory で生成**（`Model::create()` 手組み禁止）
- **DTO + JsonResource** パターン
- **アーリーリターン** 推奨
- **コードフォーマット**: `composer fix`（Pint）/ `pnpm lint:fix`
- PHP 8.4 + Laravel 12 + Svelte 5 + Inertia.js + TypeScript
- Pest のファイル直下 `const` / `function` は**グローバル空間に出る**。テストファイル間で共有する値・ロジックは `tests/Support/` の static クラスへ置く（`--parallel` はファイル単位でプロセスを分けるため、他テストファイルの const は未定義になりうる）

## 概念設計リファレンス

- `devnotes/20260809-0027-external-seam-funnel/conceptual-design.md`（Codex 合議 Round 4 で APPROVED）
- レビュー履歴: `conceptual-review-round-{1..4}.md` / `codex-history/conceptual-review-{prompt,decisions}-round-*.md`

## 施策一覧

| # | 施策名 | 変更ファイル | 優先度 |
|---|--------|------------|--------|
| S1 | 走査基盤 `PhpReferenceScanner` の抽出（振る舞い保存） | 新規 `tests/Support/PhpReferenceScanner.php` / `tests/Support/ReferenceKind.php` / `tests/Support/ReferenceSite.php` / `tests/Support/ReferenceScanResult.php`、改修 `tests/Support/ExternalClientBoundaryScanner.php` | High |
| S2 | `ExternalSeamScanner` 新設（規則 5 種 + 抑制コレクション） | 新規 `tests/Support/ExternalSeam/ExternalSeamScanner.php` / `ExternalSeamRule.php` / `ExternalSeamSite.php` / `ExternalSeamScanResult.php` | High |
| S3 | 型付き語彙と目録 | 新規 `app/Enums/Security/ExternalSeamKind.php` / `ExternalSeamClassification.php` / `ExternalSeamDimension.php`、`tests/Support/ExternalSeam/ExternalSeamEntry.php` / `ExternalSeamDelegation.php` / `ExternalSeamInventory.php` | High |
| S4 | `PrismDirectDispatchScanner` の `tests/Support/` 移設（委譲の behavioral 生存確認を可能にする） | 新規 `tests/Support/Prompts/PrismDirectDispatchScanner.php`、改修 `tests/Architecture/PromptGuardrailTest.php` | Medium |
| S5 | gate `ExternalSeamInventoryTest` + 補助 scanner | 新規 `tests/Architecture/ExternalSeamInventoryTest.php` / `tests/Support/PestTestNameScanner.php` / `tests/Unit/Architecture/PestTestNameScannerTest.php` | High |
| S6 | 走査器の unit テスト（負のコントロール） | 新規 `tests/Unit/Architecture/ExternalSeamScannerTest.php` | High |
| S7 | captcha fake の配線 + capability flag 名の是正 | 改修 `app/Providers/FakeExternalsServiceProvider.php` / `tests/Support/ExternalFakes/ExternalFakeWiringInventory.php` / `tests/Architecture/ExternalFakeWiringInvariantTest.php` / `config/testing.php` / `.env.bughunt.local.example`、新規 `tests/Feature/Captcha/RecaptchaVerifierFakeWiringTest.php` | High |
| S8 | 運用契約の記録 | 改修 `docs/architecture.md` / `AGENTS.md` / `docs/app-integration-guide.md` | Medium |

---

## S1 走査基盤 `PhpReferenceScanner` の抽出

### 変更箇所

- 新規: `tests/Support/PhpReferenceScanner.php` / `tests/Support/ReferenceKind.php` / `tests/Support/ReferenceSite.php` / **`tests/Support/ReferenceScanResult.php`**
- 改修: `tests/Support/ExternalClientBoundaryScanner.php`（L105-304 の走査ループを委譲へ置換。**public API は不変**）

**維持する public API（1 つも消さない・シグネチャも変えない）** — design-review Round 2 [Warning] 反映:

| メンバ | 扱い |
|--------|------|
| `ExternalClientBoundaryScanner::boundarySites()` | 維持（内部が `PhpReferenceScanner` 経由になるだけ） |
| `ExternalClientBoundaryScanner::stripeGlobalSites()` | 維持 |
| `ExternalClientBoundaryScanner::scan()` | 維持 |
| `ExternalClientBoundaryScanner::describe()` | 維持（移設しない） |
| `ExternalClientBoundaryScanner::phpFiles()` | 維持。実体は `PhpReferenceScanner::phpFiles()` へ移し、**委譲ラッパーを残す**（下記） |
| `ExternalClientBoundaryScanner::STRIPE_GLOBAL_SYMBOLS` | 維持（`public const`） |

```php
/** ディレクトリ配下の PHP ファイルを相対パス => ソースで返す。 */
public static function phpFiles(string $absoluteRoot, string $relativeRoot): array
{
    return PhpReferenceScanner::phpFiles($absoluteRoot, $relativeRoot);
}
```

### なぜ抽出するか

`ExternalSeamScanner` が必要とするのは、`ExternalClientBoundaryScanner::scan()` のうち **namespace 解決 / `use` alias マップ / brace 深さによる scope 追跡 / callable 名の追跡 / 名前参照と呼び出しの列挙**である。ここは実測でバグ実績のある繊細な処理（`T_CURLY_OPEN` を depth に数えないと以降の site が誤って FileScope 帰属になる、という実測コメントが現行コードにある）。2 本持つと必ず割れる。`PhpTokenScan` の docblock が既に「同じ正規化を 2 本持たない」という方針を明文化しており、その延長として抽出する。

### 波及変更

- TypeScript 型定義: なし
- API Resource/DTO: なし（`ReferenceScanResult` / `ReferenceSite` は `tests/Support/` の value object であり、HTTP 応答には現れない）
- テストファイル: `tests/Unit/Architecture/ExternalClientBoundaryScannerTest.php` は **1 行も変更しない**（振る舞い保存の証拠にする）。`tests/Architecture/ExternalClientTimeoutInventoryTest.php` も変更しない
- 本番コード: なし（`tests/` 配下のみ）

### 現行コード（抜粋）

```php
// tests/Support/ExternalClientBoundaryScanner.php
final class ExternalClientBoundaryScanner
{
    private const array TARGET_PREFIXES = ['Aws\\', 'League\\Flysystem\\', 'Illuminate\\Filesystem\\'];
    private const array TARGET_EXACT = [/* Storage facade 等 3 件 */];
    public const array STRIPE_GLOBAL_SYMBOLS = ['setHttpClient', 'setMaxNetworkRetries', 'instance'];

    public static function scan(string $relativePath, string $phpSource): array
    {
        $tokens = PhpTokenScan::normalize($phpSource);
        // …約 180 行: namespace / use / class / function / brace 追跡 +
        //   R2 (FQN 参照) / R3 (alias 参照) / R4 disk() / R5 getClient() / R6 stripe 大域 setter
        return self::dropOrphanGetClientSites($sites);
    }
}
```

### 変更後コード

```php
<?php

declare(strict_types=1);

namespace Tests\Support;

/** 参照 site の種別（何として現れたか）。 */
enum ReferenceKind
{
    /** 型・クラス名としての参照（型宣言 / `::class` / `instanceof` / 引数型 等）。 */
    case NameReference;

    /** `new X(...)` の構築点。 */
    case Construction;

    /** `X::method(` の静的呼び出し。 */
    case StaticCall;

    /** `$x->method(` / `$x?->method(` のメソッド呼び出し。 */
    case MethodCall;
}
```

```php
<?php

declare(strict_types=1);

namespace Tests\Support;

/**
 * PHP ソースから抽出した 1 つの参照 site（走査器に依存しない中立表現）。
 *
 * ★`tokenIndex` を持たせるのは、呼び出し引数の分類（`ExternalClientBoundaryScanner` の
 *   disk 名判定）のように「site の直後のトークン列」を見たい利用者があるため。
 *   走査器の内部表現を漏らさずに済ませる唯一の実用的な逃げ道である。
 */
final readonly class ReferenceSite
{
    public function __construct(
        public string $path,
        public int $line,
        public int $tokenIndex,
        public ReferenceKind $kind,
        /** 名前参照 / 構築なら解決済み FQCN、呼び出しならメソッド名 */
        public string $name,
        /** 呼び出しの receiver を解決できた場合の FQCN（できなければ null） */
        public ?string $receiver,
        /** 名前が完全修飾 / 修飾名として書かれていたか（alias 経由なら false） */
        public bool $qualified,
        public ScanScopeKind $scopeKind,
        public ?string $class,
        public ?string $callable,
    ) {}
}
```

```php
<?php

declare(strict_types=1);

namespace Tests\Support;

/**
 * 走査結果。**site（実行位置）と import（ファイルスコープの alias 宣言）を分けて返す**。
 *
 * ★`use` import は site ではない（PHP の `use` はクラス本体の外に書かれるため、site 扱いすると
 *   正規の import を持つ全ファイルが違反になる）。一方で「このファイルが決済名前空間を
 *   知っているか」のような**ファイル単位の文脈判定**には import が要る
 *   (design-review Round 1 [Critical] 反映)。よって捨てずに metadata として返す。
 */
final readonly class ReferenceScanResult
{
    /**
     * @param  list<ReferenceSite>  $sites
     * @param  array<string, string>  $imports  小文字 short name => FQCN（`use` 宣言の全件）
     */
    public function __construct(
        public array $sites,
        public array $imports,
    ) {}
}
```

```php
<?php

declare(strict_types=1);

namespace Tests\Support;

/**
 * PHP ソースの「名前参照 / 構築 / 呼び出し」を列挙する中立走査器（純関数）。
 *
 * ★走査は `PhpTokenScan::normalize()`（空白 / コメント / DocComment 除去）の結果に対して行う。
 *   `T_CONSTANT_ENCAPSED_STRING` の中身は名前解決の対象にしない。
 * ★**何を「外部到達」とみなすかは一切知らない**。判定は利用側（`ExternalClientBoundaryScanner` /
 *   `ExternalSeamScanner`）が行う。ここに TARGET を持ち込むと 2 目録の責務が混ざる。
 * ★**`use` import は site ではない**。alias マップの構築にのみ使い、母集団へは登録しない
 *   （PHP の `use` はクラス本体の外に書かれるため、site 扱いすると正規の import を持つ
 *    全ファイルが違反になる）。
 * ★`{` の数え漏れに注意: `T_CURLY_OPEN` / `T_DOLLAR_OPEN_CURLY_BRACES`（文字列補間）の
 *   閉じ `}` は単一文字トークンで現れるため、開き側を depth に数えないと brace が片側だけ減り
 *   以降の site が誤って FileScope 帰属になる（T126 の実測で発覚した罠）。
 */
final class PhpReferenceScanner
{
    /** 正規化済みトークン列（呼び出し引数の追加解析用に利用側へ渡す）。 */
    public static function tokens(string $phpSource): array { /* PhpTokenScan::normalize() */ }

    /**
     * 参照 site と import を列挙する。
     *
     * ★**emission 契約（design-review Round 1 [Critical] で確定。実測で検証済み）**:
     *   `Socialite::driver('g')` の正規化トークン列は
     *   `T_STRING(Socialite)` / `T_DOUBLE_COLON` / `T_STRING(driver)` / `(` である。
     *   receiver の `Socialite` は「直前が `::` ではない」ため **`NameReference` として emit される**
     *   （現行 `ExternalClientBoundaryScanner` の R3 と同じ経路）。加えて `driver` が
     *   `StaticCall(receiver: 'Laravel\Socialite\Facades\Socialite')` として emit される。
     *   すなわち **1 つの静的呼び出しは NameReference と StaticCall の 2 site を生む**。
     *   利用側はどちらか一方だけを canonical にすること（両方を見ると二重検出になる）。
     *
     * ★**名前解決の限界（現行 `ExternalClientBoundaryScanner` の挙動をそのまま保存する）**:
     *   `T_NAME_QUALIFIED`（`Foo\Bar` のような部分修飾名）は `ltrim($text, '\\')` するだけで、
     *   「現在の namespace への相対解決」も「先頭 segment の alias 解決」も**行わない**。
     *   したがって `use Illuminate\Support\Facades; … Facades\Http::get()` は解決できない。
     *   これは既存 gate と同じ非対称であり、S1 は**振る舞い保存**が目的なのでここを直さない
     *   （直すと T126 の母集団が変わる）。限界は docs の「保証しないもの」に明記する。
     */
    public static function references(string $relativePath, string $phpSource): ReferenceScanResult
    {
        // 現行 ExternalClientBoundaryScanner::scan() のループをそのまま移設する。
        // 差分は「TARGET 判定を行わず、すべての名前参照 / 構築 / 呼び出しを ReferenceSite として emit する」点のみ。
        //
        //  - T_NAME_FULLY_QUALIFIED / T_NAME_QUALIFIED
        //        → 直前が T_NEW なら Construction、そうでなければ NameReference（qualified: true）
        //  - T_STRING で alias 解決できる & 直前が -> / ?-> / :: でない & 宣言名でない
        //        → 直前が T_NEW なら Construction、そうでなければ NameReference（qualified: false）
        //  - T_STRING かつ直前が :: かつ直後が '(' → StaticCall（receiver は直前々トークンを解決）
        //  - T_STRING かつ直前が -> / ?-> かつ直後が '(' → MethodCall（receiver は解決しない = null）
        //  - `Foo::CONST`（'(' が続かない）は emit しない
        //  - `Foo::class` は T_CLASS 分岐で従来どおり skip
    }

    /** ディレクトリ配下の PHP ファイルを相対パス => ソースで返す（現行 phpFiles() を移設）。 */
    public static function phpFiles(string $absoluteRoot, string $relativeRoot): array { /* 現行実装 */ }
}
```

`ExternalClientBoundaryScanner` は薄い filter になる（**public API と出力 shape は完全に不変**）:

```php
public static function scan(string $relativePath, string $phpSource): array
{
    $tokens = PhpReferenceScanner::tokens($phpSource);
    $sites = [];

    foreach (PhpReferenceScanner::references($relativePath, $phpSource)->sites as $reference) {
        $site = match (true) {
            // R4: disk() 呼び出し（receiver を問わない）
            $reference->name === 'disk'
                && in_array($reference->kind, [ReferenceKind::MethodCall, ReferenceKind::StaticCall], true)
                => self::fromReference($reference, 'disk_call', 'disk', self::classifyCallArgument($tokens, $reference->tokenIndex + 1)),

            // R5: getClient() 呼び出し（receiver を問わない）
            $reference->name === 'getClient'
                && in_array($reference->kind, [ReferenceKind::MethodCall, ReferenceKind::StaticCall], true)
                => self::fromReference($reference, 'get_client_call', 'getClient', null),

            // R6: Stripe のプロセス大域 setter
            $reference->kind === ReferenceKind::StaticCall
                && in_array($reference->name, self::STRIPE_GLOBAL_SYMBOLS, true)
                && $reference->receiver !== null
                && str_starts_with($reference->receiver, 'Stripe\\')
                => self::fromReference($reference, 'stripe_global_setter', $reference->name, null),

            // R2 / R3 / R7: 到達境界の名前参照・構築
            $reference->kind === ReferenceKind::Construction && self::isTargetName($reference->name)
                => self::fromReference($reference, 'new_external_object', $reference->name, null),
            $reference->kind === ReferenceKind::NameReference && self::isTargetName($reference->name)
                => self::fromReference(
                    $reference,
                    $reference->qualified ? 'fqn_reference' : 'imported_name_reference',
                    $reference->name,
                    null,
                ),

            default => null,
        };

        if ($site !== null) {
            $sites[] = $site;
        }
    }

    return self::dropOrphanGetClientSites($sites);
}
```

> **注意（実装時に必ず確認すること）**: 現行の `disk` / `getClient` 判定は `$isMemberAccess || $isStaticAccess` を条件にしている。したがって `Storage::disk('s3')` のような**静的呼び出しも disk_call になる**。上の `match` はこれを保存している（`StaticCall` を含めている）。`ReferenceKind::StaticCall` の `name` はメソッド名なので、Stripe 大域 setter 判定と衝突しない（シンボル名が異なる）。

### PHPStan 適合チェック

- [x] 戻り値の型が明示されている（**`references()` は `ReferenceScanResult` を返す**。`ReferenceSite` / `ReferenceScanResult` はいずれも `readonly` value object。配列 shape は `PhpReferenceScanner::tokens()` の `list<array{id: int|null, text: string, line: int}>` のみで、`PhpTokenScan` の既存 PHPDoc をそのまま継承する）
- [x] null 安全（`receiver` / `class` / `callable` は `?string` で宣言し、利用側で `!== null` 判定を通す）
- [x] DTO を返している（`ReferenceScanResult`。`mixed` / 未指定 array を残さない）
- [x] Generics の型パラメータが正しい（`ReferenceScanResult::$sites` = `list<ReferenceSite>` / `$imports` = `array<string, string>`（alias マップ））
- [x] `ExternalClientBoundaryScanner` の既存 PHPDoc（`list<array{path: …, rule: …, diskArgument: 'none'|'static'|'dynamic'|null}>`）は変えない

### テスト計画

- [x] **既存テストの削除・上書きをしない**: `tests/Unit/Architecture/ExternalClientBoundaryScannerTest.php`（268 行）と `tests/Architecture/ExternalClientTimeoutInventoryTest.php` は**無変更で緑**であること。これが振る舞い保存の主証拠
- [ ] 新規 `tests/Unit/Architecture/ExternalSeamScannerTest.php` の `走査器: 匿名クラス・ファイルスコープの site を scopeKind で区別する` が、抽出後の scope 追跡を新規種別で再確認する（S6）
- [ ] 個別の `DatabaseTransactions` を使っていないことを確認（Unit / Architecture レーンは DB 不要）

### リスク

- **最大リスク**: 抽出で scope 追跡の挙動が変わり、T126 gate が偽陰性になる。緩和策は「既存テストを 1 行も変えないこと」を実装条件に置くこと。既存テストを変える必要が出た時点で、それは振る舞いが変わった証拠なので**抽出をやめて複製に切り替える**（判断の分岐点を実装者に明示する）
- `disk` / `getClient` の receiver 非依存判定を `match` の順序で壊すと、`dropOrphanGetClientSites` の入力が変わる。`match(true)` の分岐順は上記のとおり固定する（disk / getClient / stripe を名前参照より**先**に評価する = 現行の `continue` 順と同じ）

---

## S2 `ExternalSeamScanner` 新設

### 変更箇所

- 新規: `tests/Support/ExternalSeam/ExternalSeamScanner.php` / `ExternalSeamRule.php` / `ExternalSeamSite.php` / `ExternalSeamScanResult.php`

### 波及変更

- TypeScript 型定義: なし / API Resource・DTO: なし / 既存テスト: なし（新規のみ）

### 現行コード

該当なし（新規）。現行の外部到達点は §「実測母集団」のとおり 12 クラス。

### 変更後コード

```php
<?php

declare(strict_types=1);

namespace Tests\Support\ExternalSeam;

/** 外部到達点の検出規則（何を見て母集団に入れたか）。 */
enum ExternalSeamRule: string
{
    /** `Cashier::stripe()` / `$x->stripe()` — Stripe API client の取得 */
    case PaymentClientCall = 'payment_client_call';

    /** `new Stripe\StripeClient` — Stripe API client の構築 */
    case PaymentClientConstruction = 'payment_client_construction';

    /** `Laravel\Socialite\Facades\Socialite` の参照 */
    case SocialiteFacadeReference = 'socialite_facade_reference';

    /** `Illuminate\Support\Facades\Http` の参照 */
    case HttpFacadeReference = 'http_facade_reference';

    /** `Illuminate\Support\Facades\Mail` / `Illuminate\Support\Facades\Notification` の参照 */
    case MailFacadeReference = 'mail_facade_reference';
}
```

```php
<?php

declare(strict_types=1);

namespace Tests\Support\ExternalSeam;

use Tests\Support\ScanScopeKind;

/** 外部到達点の 1 site。 */
final readonly class ExternalSeamSite
{
    public function __construct(
        public string $path,
        public int $line,
        public ExternalSeamRule $rule,
        /** 検出の根拠になった名前（FQCN またはメソッド名） */
        public string $symbol,
        public ScanScopeKind $scopeKind,
        public ?string $class,
        public ?string $callable,
    ) {}

    /** 失敗メッセージ用の 1 行（「なぜ母集団に入ったのか」が読める形）。 */
    public function describe(): string
    {
        return "{$this->path}:{$this->line} [{$this->rule->value}] {$this->symbol} "
            .'('.($this->callable ?? '(file scope)').')';
    }
}
```

```php
<?php

declare(strict_types=1);

namespace Tests\Support\ExternalSeam;

/**
 * 走査結果。**採用 site と抑制 site を別コレクションで保持する**
 * （design-review 前段の合議で確定: 抑制後に情報を復元する実装にしない）。
 *
 * `suppressed` は「規則には一致したが、同一ファイルに決済名前空間の参照が無いため
 * 落とした `->stripe()` の site」。これが 1 件でもあれば抑制規則が実際に働いている =
 * 偽陰性の口が開いているので gate が赤くなる。
 */
final readonly class ExternalSeamScanResult
{
    /**
     * @param  list<ExternalSeamSite>  $adopted
     * @param  list<ExternalSeamSite>  $suppressed
     */
    public function __construct(
        public array $adopted,
        public array $suppressed,
    ) {}
}
```

```php
<?php

declare(strict_types=1);

namespace Tests\Support\ExternalSeam;

use Tests\Support\PhpReferenceScanner;
use Tests\Support\ReferenceKind;
use Tests\Support\ReferenceScanResult;
use Tests\Support\ReferenceSite;

/**
 * 「決済 / 外部ログイン / captcha・市場データ / メール送信」の外部到達点を静的に走査する純関数群。
 *
 * ★**接頭辞走査をしない**。`Stripe\` を素の接頭辞で走査すると
 *   `App\Support\Billing\GatewayFailureClassifier` が import する Stripe 例外 14 クラスと
 *   `App\DataTransferObjects\Billing\StripePriceCatalogEntry` の値オブジェクト参照を拾い、
 *   目録が肥大して信号が死ぬ。規則は **client の取得・構築**に限定する。
 *
 * ★**ファイル保存 (AWS / Flysystem) と LLM (Prism) は本走査器の対象外**。
 *   前者は `Tests\Support\ExternalClientBoundaryScanner` + T126 目録、
 *   後者は `Tests\Support\Prompts\PrismDirectDispatchScanner` + PromptGuardrailTest が正本。
 *   ここで重ねて走査すると**同じ到達事実が 2 箇所で宣言される**（本設計が最も避けたい形）。
 *
 * ★`Stripe\HttpClient\CurlClient` の `new`（`ExternalClientTimeoutServiceProvider` の大域 pin）は
 *   `PAYMENT_CLIENT_CONSTRUCTION_EXACT` の完全一致に当たらないため検出しない。
 *   大域 setter は T126 の `stripe_global_setter` 規則が正本であり続ける（責務が交わらない）。
 *
 * ★**保証範囲を誇張しない**: 検出できるのは上記 5 規則の**静的な出現**だけである。
 *   文字列キーの container 解決だけで型名も呼び出しも出さない経路は検出できない。
 *   走査根は `app/` のみで、`routes/` / `config/` は見ない。
 */
final class ExternalSeamScanner
{
    /** Stripe client を取り出す static 呼び出しの receiver（完全一致）。 */
    private const string CASHIER_FACADE = 'Laravel\\Cashier\\Cashier';

    /** client 取得のメソッド名（receiver 非依存で拾い、後段で抑制する）。 */
    private const string CLIENT_ACCESSOR = 'stripe';

    /** client 構築とみなす FQCN（**完全一致**。接頭辞にしない）。 */
    private const array PAYMENT_CLIENT_CONSTRUCTION_EXACT = ['Stripe\\StripeClient'];

    /** `->stripe()` の抑制解除条件になる名前空間接頭辞。 */
    private const array PAYMENT_NAMESPACES = ['Laravel\\Cashier\\', 'Stripe\\'];

    /** facade 参照規則（FQCN 完全一致 => 規則）。 */
    private const array FACADE_RULES = [
        'Laravel\\Socialite\\Facades\\Socialite' => ExternalSeamRule::SocialiteFacadeReference,
        'Illuminate\\Support\\Facades\\Http' => ExternalSeamRule::HttpFacadeReference,
        'Illuminate\\Support\\Facades\\Mail' => ExternalSeamRule::MailFacadeReference,
        'Illuminate\\Support\\Facades\\Notification' => ExternalSeamRule::MailFacadeReference,
    ];

    /**
     * 規則ごとの走査対象シンボル（**gate が委譲済み名前空間の混入を検査するための test 専用 API**）。
     *
     * ★private const を Reflection で覗く形にしない（実装詳細への依存を作らない）。
     *
     * @return array<string, list<string>> 規則の value => シンボル一覧
     */
    public static function ruleSymbols(): array
    {
        $facades = [];
        foreach (self::FACADE_RULES as $fqcn => $rule) {
            $facades[$rule->value][] = $fqcn;
        }

        return $facades + [
            ExternalSeamRule::PaymentClientCall->value => [self::CASHIER_FACADE, self::CLIENT_ACCESSOR],
            ExternalSeamRule::PaymentClientConstruction->value => self::PAYMENT_CLIENT_CONSTRUCTION_EXACT,
        ];
    }

    public static function scan(string $relativePath, string $phpSource): ExternalSeamScanResult
    {
        $result = PhpReferenceScanner::references($relativePath, $phpSource);

        // 抑制解除の判定は **site の名前 ∪ import の FQCN** で行う
        // (design-review Round 1 [Critical]: `use Stripe\StripeClient;` だけを持つファイルは
        //  site を 1 つも出さないため、site だけを見ると判定を落とす)。
        $hasPaymentNamespace = self::hasPaymentNamespace($result);

        $adopted = [];
        $suppressed = [];

        foreach ($result->sites as $reference) {
            $site = self::classify($reference);
            if ($site === null) {
                continue;
            }

            // `->stripe()` は receiver 非依存の名前一致なので、同一ファイルに決済名前空間の
            // 参照が無ければ「同名の無関係な API」とみなして落とす。
            // ★落とした件数は捨てずに `suppressed` へ積む（gate が 0 件を固定する）。
            if ($site->rule === ExternalSeamRule::PaymentClientCall
                && $reference->kind === ReferenceKind::MethodCall
                && ! $hasPaymentNamespace
            ) {
                $suppressed[] = $site;

                continue;
            }

            $adopted[] = $site;
        }

        return new ExternalSeamScanResult($adopted, $suppressed);
    }

    private static function classify(ReferenceSite $reference): ?ExternalSeamSite
    {
        // 決済: client の取得（static / method の両方）
        if ($reference->name === self::CLIENT_ACCESSOR
            && (($reference->kind === ReferenceKind::StaticCall && $reference->receiver === self::CASHIER_FACADE)
                || $reference->kind === ReferenceKind::MethodCall)
        ) {
            return self::site($reference, ExternalSeamRule::PaymentClientCall, self::CLIENT_ACCESSOR);
        }

        // 決済: client の構築（完全一致）
        if ($reference->kind === ReferenceKind::Construction
            && in_array($reference->name, self::PAYMENT_CLIENT_CONSTRUCTION_EXACT, true)
        ) {
            return self::site($reference, ExternalSeamRule::PaymentClientConstruction, $reference->name);
        }

        // facade 参照。
        // ★**canonical は `NameReference` のみ** (design-review Round 1 [Critical] で確定)。
        //   `Socialite::driver()` は receiver が NameReference、メソッドが StaticCall として
        //   **2 site 出る**ため、両方を見ると 1 つの呼び出しが 2 件に数えられる。
        //   receiver は alias 経由でも完全修飾でも必ず NameReference として現れるので、
        //   NameReference だけで取りこぼしは発生しない（S6 #9/#11/#12 が両形を固定する）。
        if ($reference->kind === ReferenceKind::NameReference
            && array_key_exists($reference->name, self::FACADE_RULES)
        ) {
            return self::site($reference, self::FACADE_RULES[$reference->name], $reference->name);
        }

        return null;
    }

    /** ファイルが決済名前空間を知っているか（site の名前 ∪ import の FQCN で判定）。 */
    private static function hasPaymentNamespace(ReferenceScanResult $result): bool
    {
        $names = array_map(static fn (ReferenceSite $site): string => $site->name, $result->sites);
        foreach (array_merge($names, array_values($result->imports)) as $name) {
            foreach (self::PAYMENT_NAMESPACES as $prefix) {
                if (str_starts_with($name, $prefix)) {
                    return true;
                }
            }
        }

        return false;
    }

    /** ディレクトリ配下を走査して 1 つの結果へ畳む。 */
    public static function scanDirectory(string $absoluteRoot, string $relativeRoot): ExternalSeamScanResult
    {
        $adopted = [];
        $suppressed = [];
        foreach (PhpReferenceScanner::phpFiles($absoluteRoot, $relativeRoot) as $relative => $source) {
            $result = self::scan($relative, $source);
            array_push($adopted, ...$result->adopted);
            array_push($suppressed, ...$result->suppressed);
        }

        return new ExternalSeamScanResult($adopted, $suppressed);
    }
}
```

> **`Socialite::driver()` が 1 site になる理由（実測で検証済み）**: `Socialite::driver('g')` の正規化トークン列は `T_STRING('Socialite')` / `T_DOUBLE_COLON` / `T_STRING('driver')` / `(` である。receiver の `Socialite` は**直前が `::` ではない**ため、`PhpReferenceScanner` は `NameReference('Laravel\Socialite\Facades\Socialite')` として emit する（現行 `ExternalClientBoundaryScanner` の R3 と同じ経路）。加えて `driver` が `StaticCall(receiver: 'Laravel\Socialite\Facades\Socialite')` として emit される。
> したがって **1 つの静的呼び出しから NameReference と StaticCall の 2 site が出る**。`FACADE_RULES` を両方に適用すると 1 呼び出しが 2 件に数えられるため、**facade の canonical は `NameReference` のみ**に固定する。完全修飾（`\Illuminate\Support\Facades\Http::asForm()`）でも receiver は `T_NAME_FULLY_QUALIFIED` = `NameReference` として現れるので、取りこぼしは発生しない。
> `StaticCall->receiver` を使うのは決済規則（`Cashier::stripe()`）だけである — `Laravel\Cashier\Cashier` は `FACADE_RULES` に無いので二重検出にならない。

### PHPStan 適合チェック

- [x] 戻り値の型が明示されている（`ExternalSeamScanResult` / `?ExternalSeamSite`）
- [x] null 安全（`classify()` は `?ExternalSeamSite`。`receiver` は `!== null` を通してから比較）
- [x] DTO を返している（配列返却なし。`array{...}` shape をコレクション要素に使わない）
- [x] Generics の型パラメータが正しい（`list<ExternalSeamSite>` を PHPDoc で明示）

### テスト計画

S6（`tests/Unit/Architecture/ExternalSeamScannerTest.php`）に一括。

### リスク

- **canonical 契約（facade は `NameReference` のみ）を実装で守らないと、1 呼び出しが 2 site に数えられる**。S6 #9 / #11 / #12 が「ちょうど 1 件」を固定しているため無言では通らない
- `->stripe()` の receiver 非依存判定が、将来 `stripe()` という名前の無関係なメソッドを拾う。抑制コレクション 0 件検査が赤くなって気づける（抑制された時点で赤 = 「静かに効く」ことがない）
- `http_facade_reference` が名乗れる種別が 2 つ（`Captcha` / `MarketData`）あるため、**同一クラスが両方の entry を持つと site の帰属が曖昧になる**。これは gate のテスト 1 が「site ごとに一致する entry がちょうど 1 件」を要求することで赤くなる（現在そのようなクラスは無い）

---

## S3 型付き語彙と目録

### 変更箇所

- 新規: `app/Enums/Security/ExternalSeamKind.php` / `ExternalSeamClassification.php` / `ExternalSeamDimension.php`
- 新規: `tests/Support/ExternalSeam/ExternalSeamEntry.php` / `ExternalSeamDelegation.php` / `ExternalSeamInventory.php`

### 波及変更

- TypeScript 型定義: なし / API Resource・DTO: なし / 既存テスト: なし

### 設計判断: 語彙 enum を `app/Enums/Security/` に置く理由

`ExternalSeamKind` / `ExternalSeamClassification` / `ExternalSeamDimension` は本番コードから参照されない（目録も gate も `tests/` にある）。それでも `app/Enums/Security/` に置くのは、**セキュリティ不変条件の語彙を本番側の型として持つ**という repo の既存作法に揃えるためである（`App\Enums\Security\GatewayFailureObservationExemption` / `App\Enums\Storage\ExternalClientBoundaryExemption` / `App\Enums\Security\TwoFactorStepUpExemption` がいずれも同じ位置づけ）。逆に**目録そのもの**（`ExternalSeamInventory` / `ExternalSeamEntry` / `ExternalSeamDelegation`）は `tests/Support/` に置く（`S3SurfaceInventory` と同じ）。語彙は本番の型、目録は検査の宣言、という分担を既存と揃える。

### 設計判断: 免除語彙 (`ExternalSeamExemption`) を**今は作らない**

概念設計 §2-1 論点 3 で規則を「client の取得・構築」と「外向き facade の参照」に絞った結果、**実測 12 クラスのすべてが実際に外部へ到達する**（偽陽性が 0 件）。したがって「身元検査不要」側の母集団は現時点で 0 である。

母集団 0 の免除語彙を今作ると、

- `ExternalSeamExemption` の全 case が未使用
- 「免除には 30 文字以上の根拠がある」検査が**1 件も検査せずに緑**
- 「免除前提が走査結果と矛盾しない」検査が**1 件も検査せずに緑**

となり、`BillingGatewayFailureTaxonomyInventoryTest` が冒頭で戒めている「空虚に green」な gate を 3 本増やすことになる（AGENTS.md 思考原則 2）。

そこで **`ExternalSeamClassification` は `Guarded` / `Exempt` の 2 値を宣言しつつ、`Exempt` の使用を gate が明示的に拒否する**。免除が本当に必要になった時点で、免除語彙 enum・前提表・30 文字根拠検査・空振り防止をセットで作らせる**意図的な摩擦**にする（`FakeClassReferenceInvariantTest` の 4-2 / 4-4 と同じ作法）。失敗メッセージに「何を作る必要があるか」を書く。

### 変更後コード

```php
<?php

declare(strict_types=1);

namespace App\Enums\Security;

/**
 * 外部到達点の種別（標準形 v1 の 6 種 + aicue 固有の 1 種）。
 *
 * ★閉じた語彙にする。新しい `Http::` 直呼びが増えたとき、既存 case のどれにも当てはまらなければ
 *   **case を足す判断**を通す（新しい外向きの種類が黙って増えないようにするための摩擦）。
 * ★`ObjectStorage` / `Llm` は**委譲専用**。本目録の母集団には現れない
 *   （`ExternalSeamInventoryTest` が機械で固定する）。
 */
enum ExternalSeamKind: string
{
    case Payment = 'payment';
    case SocialLogin = 'social_login';
    case Captcha = 'captcha';
    case Mail = 'mail';

    /**
     * 市場データ取得（標準形 v1 の 6 種に無い aicue 固有の外向き経路）。
     *
     * `App\Services\FxRateService` が為替 API を叩く。captcha と同じ `Http` facade 規則で
     * 検出されるため、除外する方が不自然な規則になる（概念設計 §2-1 論点 2）。
     */
    case MarketData = 'market_data';

    /** 委譲専用: `ExternalClientTimeoutInventoryTest` の到達境界目録が正本。 */
    case ObjectStorage = 'object_storage';

    /** 委譲専用: `PromptGuardrailTest` の Prism 直呼び禁止が正本。 */
    case Llm = 'llm';
}
```

```php
<?php

declare(strict_types=1);

namespace App\Enums\Security;

/** 外部到達点の分類（標準形 v1 (2) が要求する 2 分類）。 */
enum ExternalSeamClassification: string
{
    /** 守る対象（差し替え・監視の設計に含める到達点）。 */
    case Guarded = 'guarded';

    /**
     * 身元検査不要（外向きの目印は出すが実際には外部へ出ない）。
     *
     * ★**現時点で使用できない**。検出規則を「client の取得・構築」と
     *   「外向き facade の参照」に絞った結果、検出 = 実到達となり母集団が 0 件のため、
     *   免除語彙 (`ExternalSeamExemption`) / 免除前提表 / 30 文字根拠検査を作っていない。
     *   使用する必要が出たら、それらをセットで新設すること
     *   (`ExternalSeamInventoryTest` が失敗メッセージで案内する)。
     */
    case Exempt = 'exempt';
}
```

```php
<?php

declare(strict_types=1);

namespace App\Enums\Security;

/**
 * 外部到達を数える「次元」。
 *
 * ★**次元そのものの数え落としは検出できない**（定義は人手）。未知の設定面や新しい SDK 表面が
 *   第 3 の次元を作った場合、gate は沈黙する。保証は登録済みの種別 × 次元の網羅に限る。
 */
enum ExternalSeamDimension: string
{
    /** どのクラスが外へ出るか（app/ の静的走査で数える）。 */
    case CodeReachPoint = 'code_reach_point';

    /** どこへ出るか（設定で増える宛先集合）。 */
    case DestinationSet = 'destination_set';
}
```

```php
<?php

declare(strict_types=1);

namespace Tests\Support\ExternalSeam;

use App\Enums\Security\ExternalSeamClassification;
use App\Enums\Security\ExternalSeamKind;

/** 目録の 1 entry（値の器。判定ロジックを持たない）。 */
final readonly class ExternalSeamEntry
{
    /**
     * @param  class-string  $class
     * @param  string  $rationale  なぜこの到達が正当か（30 文字以上。gate が検査する）
     */
    public function __construct(
        public string $class,
        public ExternalSeamKind $kind,
        public ExternalSeamClassification $classification,
        public string $rationale,
    ) {}
}
```

```php
<?php

declare(strict_types=1);

namespace Tests\Support\ExternalSeam;

use App\Enums\Security\ExternalSeamDimension;
use App\Enums\Security\ExternalSeamKind;
use Closure;

/**
 * 「この種別 × 次元は別 gate が既に deny-by-default で見ている」という委譲の宣言。
 *
 * ★委譲の結線は 2 層（概念設計 §2-1）:
 *   1. **母集団の生存確認（behavioral・主要保証）**: `livenessProbe` を実行して空でないことを確認する
 *   2. **委譲先 gate の同定（主要保証）**: `gateFile` の実在 + `gateTestName` の完全一致
 * ★**保証しないもの**: 委譲先の assert の中身を弱める改変（必須宣言のうち 1 つを検査しなくする等）は
 *   本 gate では検出できない。
 */
final readonly class ExternalSeamDelegation
{
    /**
     * @param  string  $gateFile  repo ルート相対
     * @param  string  $gateTestName  委譲先の test 名（完全一致）
     * @param  Closure(): array<mixed>  $livenessProbe  委譲先が見ている母集団の導出（空なら fail）
     * @param  string  $rationale  30 文字以上
     */
    public function __construct(
        public ExternalSeamKind $kind,
        public ExternalSeamDimension $dimension,
        public string $gateFile,
        public string $gateTestName,
        public Closure $livenessProbe,
        public string $rationale,
    ) {}
}
```

```php
<?php

declare(strict_types=1);

namespace Tests\Support\ExternalSeam;

use App\Console\Commands\Billing\EnsurePortalConfiguration;
use App\Actions\Fortify\UpdateUserProfileInformation;
use App\Actions\Inquiry\CreateInquiryAction;
use App\Enums\Security\ExternalSeamClassification;
use App\Enums\Security\ExternalSeamDimension;
use App\Enums\Security\ExternalSeamKind;
use App\Http\Controllers\Auth\SocialAuthController;
use App\Providers\AppServiceProvider;
use App\Services\Billing\CashierAutoRechargeGateway;
use App\Services\Billing\CashierStripeGateway;
use App\Services\Billing\CashierTicketCheckoutGateway;
use App\Services\Billing\StripeScheduleGateway;
use App\Services\Captcha\RecaptchaVerifier;
use App\Services\FxRateService;
use App\Services\Organization\OrganizationMembershipService;
use Tests\Support\ExternalClientBoundaryScanner;
use Tests\Support\Prompts\PrismDirectDispatchScanner;

/**
 * 外部到達点の目録の**正本**（deny-by-default）。
 *
 * ★グローバル定数ではなく **static メソッド**に置く（`S3SurfaceInventory` と同じ `--parallel` 規律。
 *   Pest のファイル直下 const は他テストファイルから見えない）。
 * ★目録の実体は **「app/ のコード到達点 + 明示的に委譲した宛先集合」**である。
 *   `routes/` / `config/` に書かれた到達コードは見ない（`docs/architecture.md` に明記）。
 */
final class ExternalSeamInventory
{
    /**
     * 種別ごとに検知が必要な次元（exact-fit。enum 全 case を覆うことを gate が検査する）。
     *
     * ★`Payment` に `DestinationSet` を要求しない: Stripe の宛先は API キーが指す account であり、
     *   設定面の走査対象にしていない（`docs/architecture.md` の「保証しないもの」に明記）。
     *
     * @return array<string, list<ExternalSeamDimension>> kind->value => 必要な次元
     */
    public static function requiredDimensions(): array
    {
        return [
            ExternalSeamKind::Payment->value => [ExternalSeamDimension::CodeReachPoint],
            ExternalSeamKind::SocialLogin->value => [
                ExternalSeamDimension::CodeReachPoint,
                ExternalSeamDimension::DestinationSet,
            ],
            ExternalSeamKind::Captcha->value => [ExternalSeamDimension::CodeReachPoint],
            ExternalSeamKind::Mail->value => [ExternalSeamDimension::CodeReachPoint],
            ExternalSeamKind::MarketData->value => [ExternalSeamDimension::CodeReachPoint],
            ExternalSeamKind::ObjectStorage->value => [ExternalSeamDimension::CodeReachPoint],
            ExternalSeamKind::Llm->value => [ExternalSeamDimension::CodeReachPoint],
        ];
    }

    /**
     * `SocialLogin` の正規経路（**名指し固定**）。
     *
     * ★標準形 v1 (1)「正規経路へ集約し直呼びを構文解析で禁止」の機械化。
     *   この 1 クラス以外は `Guarded` でも `Exempt` でも登録できない
     *   （`TwoFactorStepUpInventoryTest` の「exemption にできない 6 本」と同型の作法）。
     *   集約先を別クラスへ切り出さないのは、差し替え先（SSO fake）を今作らないため
     *   （差し替え先の無い中間層は思考原則 2 に反する）。
     *
     * @return class-string
     */
    public static function socialLoginFunnel(): string
    {
        return SocialAuthController::class;
    }

    /** @return list<ExternalSeamEntry> */
    public static function entries(): array
    {
        return [
            // --- payment（6 クラス。fake 配線の有無は問わず「守る対象」として登録する） ---
            new ExternalSeamEntry(
                class: CashierStripeGateway::class,
                kind: ExternalSeamKind::Payment,
                classification: ExternalSeamClassification::Guarded,
                rationale: 'サブスク Checkout / Customer Portal の Stripe 到達点。StripeGatewayInterface 経由で fake へ差し替わる',
            ),
            new ExternalSeamEntry(
                class: CashierTicketCheckoutGateway::class,
                kind: ExternalSeamKind::Payment,
                classification: ExternalSeamClassification::Guarded,
                rationale: 'チケットスポット購入の Stripe Checkout 到達点。TicketCheckoutGateway 経由で fake へ差し替わる',
            ),
            new ExternalSeamEntry(
                class: CashierAutoRechargeGateway::class,
                kind: ExternalSeamKind::Payment,
                classification: ExternalSeamClassification::Guarded,
                rationale: 'オートリチャージの off-session invoice 到達点。AutoRechargeGatewayInterface 経由で fake へ差し替わる',
            ),
            new ExternalSeamEntry(
                class: StripeScheduleGateway::class,
                kind: ExternalSeamKind::Payment,
                classification: ExternalSeamClassification::Guarded,
                rationale: 'subscription schedule の Stripe 到達点。消費点は artisan コマンドのみで bug-hunt からは到達しない',
            ),
            new ExternalSeamEntry(
                class: EnsurePortalConfiguration::class,
                kind: ExternalSeamKind::Payment,
                classification: ExternalSeamClassification::Guarded,
                rationale: 'Customer Portal 設定を投入する保守コマンドの Stripe 到達点。人手実行のみで web 経路から呼ばれない',
            ),
            new ExternalSeamEntry(
                class: AppServiceProvider::class,
                kind: ExternalSeamKind::Payment,
                classification: ExternalSeamClassification::Guarded,
                rationale: 'PriceService を Cashier::stripe()->prices で束ねる container 配線点。差し替えはこの bind を経由する',
            ),

            // --- social_login（1 クラス。名指し固定） ---
            new ExternalSeamEntry(
                class: SocialAuthController::class,
                kind: ExternalSeamKind::SocialLogin,
                classification: ExternalSeamClassification::Guarded,
                rationale: 'SSO の唯一の正規経路。他クラスからの Socialite::driver() は本目録に登録できず必ず赤くなる',
            ),

            // --- captcha（1 クラス） ---
            new ExternalSeamEntry(
                class: RecaptchaVerifier::class,
                kind: ExternalSeamKind::Captcha,
                classification: ExternalSeamClassification::Guarded,
                rationale: 'Google siteverify の到達点。非本番は RecaptchaVerifierTestFake へ container bind で差し替わる',
            ),

            // --- mail（3 クラス） ---
            new ExternalSeamEntry(
                class: CreateInquiryAction::class,
                kind: ExternalSeamKind::Mail,
                classification: ExternalSeamClassification::Guarded,
                rationale: '問い合わせ受付の通知メール送信点。外部到達の有無は mailer driver 設定が決める（testing=array / bughunt=log）',
            ),
            new ExternalSeamEntry(
                class: OrganizationMembershipService::class,
                kind: ExternalSeamKind::Mail,
                classification: ExternalSeamClassification::Guarded,
                rationale: '組織招待メールの on-demand 送信点。外部到達の有無は mailer driver 設定が決める',
            ),
            new ExternalSeamEntry(
                class: UpdateUserProfileInformation::class,
                kind: ExternalSeamKind::Mail,
                classification: ExternalSeamClassification::Guarded,
                rationale: 'メールアドレス変更の旧アドレス宛て通知の送信点。外部到達の有無は mailer driver 設定が決める',
            ),

            // --- market_data（1 クラス） ---
            new ExternalSeamEntry(
                class: FxRateService::class,
                kind: ExternalSeamKind::MarketData,
                classification: ExternalSeamClassification::Guarded,
                rationale: '為替レート取得の到達点。標準形 v1 の 6 種に無い aicue 固有の外向き経路で cache 前段に置かれる',
            ),
        ];
    }

    /** @return list<ExternalSeamDelegation> */
    public static function delegations(): array
    {
        $repoRoot = dirname(__DIR__, 3);

        return [
            new ExternalSeamDelegation(
                kind: ExternalSeamKind::ObjectStorage,
                dimension: ExternalSeamDimension::CodeReachPoint,
                gateFile: 'tests/Architecture/ExternalClientTimeoutInventoryTest.php',
                gateTestName: '到達境界: AWS / Flysystem へ到達するクラスは目録と対称差ゼロ',
                livenessProbe: static function () use ($repoRoot): array {
                    $sites = [];
                    foreach (ExternalClientBoundaryScanner::phpFiles($repoRoot.'/app', 'app') as $relative => $source) {
                        array_push($sites, ...ExternalClientBoundaryScanner::boundarySites($relative, $source));
                    }

                    return $sites;
                },
                rationale: 'AWS / Flysystem 到達クラスの既定拒否目録は T126 が正本。同じ到達事実を本目録で再宣言しない',
            ),
            new ExternalSeamDelegation(
                kind: ExternalSeamKind::Llm,
                dimension: ExternalSeamDimension::CodeReachPoint,
                gateFile: 'tests/Architecture/PromptGuardrailTest.php',
                gateTestName: 'app/ で Prism Facade の LLM 系メソッドを直接呼んでいない (Prompt 経由のみ)',
                livenessProbe: static fn (): array => PrismDirectDispatchScanner::scannedFiles(),
                rationale: 'Prism 直呼び禁止は ALLOWED_FILES 空の完全禁止で PromptGuardrailTest が正本。目録より強い形で閉じている',
            ),
            new ExternalSeamDelegation(
                kind: ExternalSeamKind::SocialLogin,
                dimension: ExternalSeamDimension::DestinationSet,
                gateFile: 'tests/Architecture/SocialProviderTrustPolicyTest.php',
                gateTestName: '全 SSO provider が capability / email_trust を明示宣言している',
                livenessProbe: static fn (): array => config()->array('template.social_providers'),
                rationale: 'SSO の宛先集合は config の social_providers が正本で、provider 追加時の宣言必須を既存 gate が強制する',
            ),
        ];
    }
}
```

### PHPStan 適合チェック

- [x] 戻り値の型が明示されている（`list<ExternalSeamEntry>` / `list<ExternalSeamDelegation>` / `array<string, list<ExternalSeamDimension>>`）
- [x] null 安全（`ExternalSeamEntry` は全 property 必須。null 許容なし）
- [x] DTO を返している（配列 shape をコレクション要素に使わない）
- [x] Generics の型パラメータが正しい（`Closure(): array<mixed>` を PHPDoc で明示。`livenessProbe` の戻りは件数のみ見るので `array<mixed>` で足りる）
- [x] `class-string` を使う（`ExternalSeamEntry::$class` / `socialLoginFunnel()`）

### テスト計画

S5 の gate が全項目を検査する。目録単体のテストは持たない（値の器であり判定ロジックを持たないため）。

### リスク

- `ExternalSeamKind` に `MarketData` を足したことで、台帳の標準形 v1 の 6 種と語彙がずれる。**上位互換**（6 種はすべて存在する）であり、`docs/architecture.md` と lctl への `status_reported` で明示する
- `Exempt` を封じた設計は、最初の偽陽性が出たときに追加設計を要求する。これは意図的な摩擦であり、gate の失敗メッセージに「何を作るべきか」を書くことで回収する

---

## S4 `PrismDirectDispatchScanner` の `tests/Support/` 移設

### 変更箇所

- 新規: `tests/Support/Prompts/PrismDirectDispatchScanner.php`
- 改修: `tests/Architecture/PromptGuardrailTest.php`（クラス定義の削除 + `use` の追加。テストの中身は変えない）

### なぜ必要か

`PrismDirectDispatchScanner` は現在 `tests/Architecture/PromptGuardrailTest.php` の**中**でグローバル名前空間に定義されている。Pest の `--parallel` はファイル単位でプロセスを分けるため、他テストファイルからは参照できない（参照すると当該テストファイルを読み込んで実行することになる）。委譲の **behavioral な生存確認**（概念設計 §2-1）を実装するには、`tests/Support/` の名前空間付きクラスである必要がある。

### 波及変更

- TypeScript 型定義: なし / API Resource・DTO: なし
- テストファイル: `tests/Architecture/PromptGuardrailTest.php`（クラス定義の移動のみ。**test 本体は変更しない**。`gateTestName` で固定する test 名も変えない）

### 現行コード

```php
// tests/Architecture/PromptGuardrailTest.php（グローバル名前空間）
final class PrismDirectDispatchScanner
{
    private const TARGET_METHODS = ['text', 'structured', 'stream', 'embeddings', 'image', 'audio'];
    private const ALLOWED_FILES = [];

    public static function findViolations(): array
    {
        $appDir = realpath(__DIR__.'/../../app');
        if (! is_string($appDir)) {
            throw new RuntimeException('app/ ディレクトリを解決できません');
        }
        // …
    }
}
```

### 変更後コード

```php
<?php

declare(strict_types=1);

namespace Tests\Support\Prompts;

use FilesystemIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RuntimeException;
use SplFileInfo;

/**
 * app/ 配下で Prism Facade の LLM 系メソッドを直接呼び出すコードを token ベースで検出する scanner。
 *
 * ★`tests/Architecture/PromptGuardrailTest.php` から**移設**した（振る舞い不変）。
 *   Pest の `--parallel` はファイル単位でプロセスを分けるため、テストファイル内の
 *   グローバルクラスは他 gate から参照できない。委譲の生存確認
 *   (`ExternalSeamInventoryTest`) が本クラスを呼ぶため `tests/Support/` へ置く
 *   (`S3SurfaceInventory` / `QueueLeaseConfig` と同じ規律)。
 */
final class PrismDirectDispatchScanner
{
    private const array TARGET_METHODS = ['text', 'structured', 'stream', 'embeddings', 'image', 'audio'];

    /** @var list<string> app/ からの相対パス。テンプレートは allowlist 不要のため空。 */
    private const array ALLOWED_FILES = [];

    /** repo ルート（tests/Support/Prompts から 3 段上）。 */
    private static function appDir(): string
    {
        $appDir = realpath(dirname(__DIR__, 3).'/app');
        if (! is_string($appDir)) {
            throw new RuntimeException('app/ ディレクトリを解決できません');
        }

        return $appDir;
    }

    /**
     * 走査対象ファイル（**空振り防止 / 委譲の生存確認に使う**）。
     *
     * @return list<string> 絶対パス
     */
    public static function scannedFiles(): array
    {
        $files = [];
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator(self::appDir(), FilesystemIterator::SKIP_DOTS),
        );
        /** @var SplFileInfo $file */
        foreach ($iterator as $file) {
            if ($file->isFile() && $file->getExtension() === 'php') {
                $files[] = $file->getPathname();
            }
        }
        sort($files);

        return $files;
    }

    /** @return list<string> 違反ファイル (app/ 相対パス) */
    public static function findViolations(): array { /* 現行実装（appDir() 経由へ差し替えるのみ） */ }

    public static function containsPrismDirectCall(string $source): bool { /* 現行実装のまま */ }
}
```

`tests/Architecture/PromptGuardrailTest.php` は先頭で `use Tests\Support\Prompts\PrismDirectDispatchScanner;` を足し、クラス定義を削除する。**test 名・assert・メッセージは 1 文字も変えない**。

### PHPStan 適合チェック

- [x] 戻り値の型が明示されている（`list<string>` / `bool`）
- [x] null 安全（`realpath()` の `false` を `is_string()` で narrowing してから使う。現行と同じ）
- [x] DTO を返している（該当なし。走査器は文字列リストを返す純関数）
- [x] Generics の型パラメータが正しい（`list<string>`）
- [x] `const` に `array` 型を付ける（PHP 8.3+ の typed class constants。repo の他 scanner と揃える）

### テスト計画

- [x] **既存テスト `tests/Architecture/PromptGuardrailTest.php` の test 名・本文を変更しない**（`gateTestName` の固定対象であり、変えると S5 が赤くなる = 意図した結線）
- [ ] 新規テストは追加しない（移設のみ。既存 10 数本の scanner テストがそのまま回帰になる）
- [ ] 個別の `DatabaseTransactions` を使っていないことを確認

### リスク

- `__DIR__` の相対段数を間違えると `app/` が解決できず `RuntimeException`。既存の「scanner の自己検証 (app dir が解決できる)」テストが即座に赤くなる
- 移設によって `PromptGuardrailTest` 内の `RuntimeException` / `RecursiveIteratorIterator` の import が不要になる。Pint / PHPStan の未使用 import 検出で気づける

---

## S5 gate `ExternalSeamInventoryTest`

### 変更箇所

- 新規: `tests/Architecture/ExternalSeamInventoryTest.php`
- 新規: **`tests/Support/PestTestNameScanner.php`**（テスト 12 が使う補助 scanner）
- 新規: **`tests/Unit/Architecture/PestTestNameScannerTest.php`**（補助 scanner の正負両方向。負のコントロールを含む）

### 波及変更

- TypeScript 型定義: なし / API Resource・DTO: なし / 既存テスト: なし

### 現行コード

該当なし（新規）。

### 変更後コード（骨子）

```php
<?php

declare(strict_types=1);

use App\Enums\Security\ExternalSeamClassification;
use App\Enums\Security\ExternalSeamDimension;
use App\Enums\Security\ExternalSeamKind;
use Tests\Support\ExternalSeam\ExternalSeamInventory;
use Tests\Support\ExternalSeam\ExternalSeamRule;
use Tests\Support\ExternalSeam\ExternalSeamScanner;
use Tests\Support\ExternalSeam\ExternalSeamScanResult;
use Tests\Support\ScanScopeKind;

/*
 * 外部到達点の既定拒否目録（標準形 v1 / 検知 v1）。
 *
 * ★この gate が保証するもの:
 *   - app/ の**コード到達点**（決済 client 取得・構築 / Socialite / Http / Mail・Notification facade）が
 *     全件、目録と対称差ゼロで登録されている（未登録は赤・残骸も赤）
 *   - 走査母集団が 0 件なら赤（走査条件を壊したまま緑にならない）
 *   - site が名前付きクラス本体へ帰属している（匿名クラス / ファイルスコープの抜け道を作らせない）
 *   - 規則ごとに名乗ってよい種別が固定されている（種別が登録者の言い値にならない）
 *   - `->stripe()` の同一ファイル抑制が**1 件も働いていない**（抑制による偽陰性の可視化）
 *   - `SocialLogin` は `SocialAuthController` 1 クラスに固定され、他クラスは登録も免除もできない
 *     （標準形 v1 (1) の集約・直呼び禁止の機械化）
 *   - 種別 × 次元の必須表が enum 全 case を exact-fit で覆い、各対が「目録」か「委譲」で塞がれている
 *   - 委譲先の**母集団が生きている**（probe を実行して空でないことを確認する behavioral 検査）
 *   - 委譲先 gate のファイルと test 名が実在する
 *   - 委譲した種別が本目録の母集団に現れない（同じ到達事実の二重宣言を構造的に禁じる）
 *
 * ★この gate が保証しないもの（誇張しない）:
 *   - **出口を塞がない**。目録は新経路の検知であり、実行時の外部通信は止めない。
 *     bug-hunt のブラウザが SSO で accounts.google.com へ出る現状は本 gate では変わらない
 *   - **委譲先の assert の中身**。委譲先のテストが弱められた（必須宣言のうち 1 つを検査しなくなった等）
 *     場合は検出できない。結線は「母集団の生存」と「test 名の同定」までである
 *   - **`app/` の外**。`routes/` / `config/` に書かれた到達コードは走査しない
 *     （SSO の宛先集合だけは委譲で押さえるが、これは SSO 固有の措置である）
 *   - **次元そのものの数え落とし**。次元の定義は人手であり、未知の設定面や新 SDK 表面が
 *     第 3 の次元を作った場合は沈黙する
 *   - **文字列キーの container 解決だけの経路**（型名も呼び出しも出さない形）
 *   - **vendor 内部から出る通信**（Cashier / Socialite の内部実装）
 *   - **他種別の宛先集合**（Stripe の API キーが指す account / SES の region / 為替 API の URL）
 *
 * ★同一クラスが本目録と T126 目録の**両方**に載ることは正当である。
 *   `AppServiceProvider` は AWS SNS クライアント構築（T126）と Cashier::stripe()->prices（本目録）の
 *   **別々の到達事実**で登録されている。禁じているのは「同じ到達事実の二重宣言」であり、
 *   規則が分離しているので構造的に起きない。
 *
 * 運用契約は docs/architecture.md §外部到達点の目録 (標準形 v1)。
 */

const EXTERNAL_SEAM_MUTATION_COVERAGE = [
    'M1' => '目録から entry を 1 つ消すと対称差ゼロ（missing 側）が赤くなる',
    'M2' => '目録に走査で出ないクラスを足すと対称差ゼロ（残骸側）が赤くなる',
    'M3' => 'FACADE_RULES を空にすると対称差ゼロ（missing 側）が赤くなる',
    'M4' => '全規則を無効化すると空振り防止が赤くなる',
    'M5' => 'SocialAuthController 以外のクラスに Socialite::driver() を書くと名指し固定が赤くなる',
    'M6' => 'Cashier / Stripe を知らないクラスに ->stripe() を書くと抑制 0 件が赤くなる',
    'M7' => '規則→種別表の 1 行を書き換えると種別突合が赤くなる',
    'M8' => 'requiredDimensions から kind を 1 つ消すと exact-fit が赤くなる',
    'M9' => '委譲の gateTestName を 1 文字変えると同定が赤くなる',
    'M10' => 'config/template.php の social_providers を空にすると委譲の生存確認が赤くなる',
    'M11' => 'ruleSymbols に委譲済み名前空間を足すと混入検査が赤くなる',
    'M12' => 'entry の classification を Exempt にすると免除語彙未整備で赤くなる',
    'M13' => '同じ (class, kind) を重複登録すると双方向照合が赤くなる',
    'M14' => '委譲先 test をコメント化して同名をコメントに残すと test 名同定が赤くなる',
    'M15' => '対応する規則 site の無い kind を既存クラスへ足すと双方向照合（残骸側）が赤くなる',
    'M16' => '目録が覆う対へ委譲を足すと二重被覆で赤くなる',
    'M17' => '同じ (kind, dimension) の委譲を重複登録すると排他的被覆が赤くなる',
    'M18' => '必須表に無い余剰委譲を足すと逆方向検査が赤くなる',
];

const EXTERNAL_SEAM_MUTATION_IDS = [
    'M1','M2','M3','M4','M5','M6','M7','M8','M9','M10','M11','M12','M13','M14','M15','M16','M17','M18',
];

/**
 * 規則ごとに名乗ってよい種別（種別が登録者の言い値にならないようにする突合表）。
 *
 * ★`http_facade_reference` が名乗れるのは `{Captcha, MarketData}` だけである。
 *   新しい `Http::` 直呼びは、このどちらでもなければ **enum に case を足す判断**を通る
 *   = 新しい外向きの種類が黙って増えない。
 *
 * @var array<string, list<ExternalSeamKind>>
 */
const EXTERNAL_SEAM_RULE_KINDS = [
    'payment_client_call' => [ExternalSeamKind::Payment],
    'payment_client_construction' => [ExternalSeamKind::Payment],
    'socialite_facade_reference' => [ExternalSeamKind::SocialLogin],
    'http_facade_reference' => [ExternalSeamKind::Captcha, ExternalSeamKind::MarketData],
    'mail_facade_reference' => [ExternalSeamKind::Mail],
];

function externalSeamScan(): ExternalSeamScanResult
{
    $root = dirname(__DIR__, 2);

    return ExternalSeamScanner::scanDirectory($root.'/app', 'app');
}
```

テストケース（すべて `tests/Architecture/ExternalSeamInventoryTest.php`）:

> **識別単位は `(クラス, 種別)`**（design-review Round 2 [Critical] 反映）。クラス集合だけを比較すると、同一クラスに複数種類の外部到達がある場合・同じ `(class, kind)` を重複登録した場合・走査と対応しない entry が既存クラス名を借りて stale 判定をすり抜ける場合を捨ててしまう。テスト 1 は**分類済み到達集合の双方向照合**にする。

| # | テスト名 | 検証内容 |
|---|---------|---------|
| 1 | `外部到達: 走査 site と目録は (クラス, 種別) で双方向に一致する` | (a) 各 site について、`class` が一致し `kind ∈ EXTERNAL_SEAM_RULE_KINDS[site.rule]` を満たす entry が**ちょうど 1 件**（0 件 = 未登録 / 2 件以上 = 帰属が曖昧）。(b) 各 entry について、`class` が一致し `entry.kind ∈ ruleKinds[site.rule]` を満たす site が**1 件以上**（残骸検出）。(c) `(class, kind)` の重複登録が 0 件。失敗診断には `describe()` を並べる。**同一クラスが別々の到達事実で複数 kind を持つことは許可する**（今は該当なし） |
| 2 | `外部到達: 走査母集団が空でない` | `adopted` が非空 かつ `entries()` が非空（走査条件破壊で緑にならない） |
| 3 | `外部到達: site は名前付きクラス本体へ帰属する` | `scopeKind !== NamedClass` または `class === null` の site が 0 件（匿名クラス / ファイルスコープの抜け道封じ） |
| 4 | `外部到達: 規則→種別表は規則 enum を exact-fit で覆う` | `EXTERNAL_SEAM_RULE_KINDS` のキー集合が `ExternalSeamRule::cases()` の value 集合と完全一致し、各値が非空（表の書き忘れを落とす。site と entry の突合そのものはテスト 1 が担う） |
| 5 | `外部到達: 各 entry の根拠は 30 文字以上` | `mb_strlen($entry->rationale) >= 30` |
| 6 | `外部到達: 決済の抑制 site は 0 件` | `suppressed === []`。失敗時は `describe()` でパス・行・呼び出し位置を出す |
| 7 | `外部到達: SocialLogin は SocialAuthController 1 クラスに固定される` | `kind === SocialLogin` の entry が `[socialLoginFunnel()]` と完全一致。かつ走査で `socialite_facade_reference` を出すクラス集合も同一 |
| 8 | `外部到達: 免除分類は語彙が未整備のため使用できない` | `classification === Exempt` の entry が 0 件。失敗メッセージに「ExternalSeamExemption enum + 免除前提表 + 30 文字根拠検査 + 空振り防止をセットで新設すること」を書く |
| 9 | `外部到達: 種別 × 次元の必須表は enum 全 case を覆う` | `requiredDimensions()` のキー集合が `ExternalSeamKind::cases()` の value 集合と完全一致。各値が非空 |
| 10 | `外部到達: 種別 × 次元は目録か委譲の**ちょうど一方**で覆われる` | 必須表の全 (kind, dimension) 対について **coverage source がちょうど 1 つ**であることを検査する（design-review Round 3 [Critical]）。(a) 目録は `CodeReachPoint` の source を 1 つ提供する（その kind の entry が 1 件以上あるとき）。(b) 委譲は `(kind, dimension)` ごとに**高々 1 件**。(c) 目録と委譲の**両方**が同じ対を覆っていたら赤（二重宣言の禁止 = 本設計の主目的）。(d) **逆方向**: `delegations()` の全件が `requiredDimensions()` の必須対に含まれる（余剰委譲を拒否）。(e) 覆う source が 0 の必須対も赤 |
| 11 | `外部到達: 委譲先の母集団が生きている` | 各 `delegation->livenessProbe` を**実行**して非空。加えて合成ソースの positive control（下記）で検出器が生きていることを確認 |
| 12 | `外部到達: 委譲先 gate のファイルと test 名が実在する` | `base_path($gateFile)` が存在し、**`PestTestNameScanner::names()` が抽出した test 名集合**に `$gateTestName` が**完全一致**で含まれる。単なる文字列包含にしないのは、改名後も旧名がコメントや別のリテラルに残れば緑になってしまうため（design-review Round 2 [Critical]） |
| 13 | `外部到達: 委譲した種別は本目録の母集団に現れない` | `entries()` に `ObjectStorage` / `Llm` の kind が 0 件。かつ **`ExternalSeamScanner::ruleSymbols()`**（test 専用の公開 API。private const を Reflection で覗かない）の全シンボルが `Aws\` / `League\Flysystem\` / `Illuminate\Filesystem\` / `Prism\` のいずれの接頭辞も持たない。加えて `ruleSymbols()` のキー集合が `ExternalSeamRule::cases()` の value 集合と exact-fit（規則を足して表に載せ忘れると赤） |
| 14 | `外部到達: 委譲の根拠は 30 文字以上` | `mb_strlen($delegation->rationale) >= 30` |
| 15 | `外部到達: mutation 被覆表のキー集合が想定 mutation ID と一致する` | `array_keys(EXTERNAL_SEAM_MUTATION_COVERAGE)` と `EXTERNAL_SEAM_MUTATION_IDS` が一致 |

#### テスト 10 の実装（排他的被覆）

```php
test('外部到達: 種別 × 次元は目録か委譲のちょうど一方で覆われる', function (): void {
    $entriesByKind = [];
    foreach (ExternalSeamInventory::entries() as $entry) {
        $entriesByKind[$entry->kind->value] = true;
    }

    /** @var array<string, int> $delegationCount "kind|dimension" => 件数 */
    $delegationCount = [];
    foreach (ExternalSeamInventory::delegations() as $delegation) {
        $key = $delegation->kind->value.'|'.$delegation->dimension->value;
        $delegationCount[$key] = ($delegationCount[$key] ?? 0) + 1;
    }

    $violations = [];

    // (a)(b)(c)(e): 必須対ごとに coverage source をちょうど 1 つに固定する
    foreach (ExternalSeamInventory::requiredDimensions() as $kind => $dimensions) {
        foreach ($dimensions as $dimension) {
            $key = $kind.'|'.$dimension->value;
            $sources = [];
            if ($dimension === ExternalSeamDimension::CodeReachPoint && ($entriesByKind[$kind] ?? false)) {
                $sources[] = '目録 (ExternalSeamInventory::entries)';
            }
            for ($i = 0; $i < ($delegationCount[$key] ?? 0); $i++) {
                $sources[] = '委譲 (ExternalSeamInventory::delegations)';
            }

            if (count($sources) !== 1) {
                $violations[] = "{$key}: coverage source が ".count($sources).' 件 ('
                    .(count($sources) === 0 ? '覆われていない' : '二重宣言: '.implode(' / ', $sources)).')';
            }
        }
    }

    // (d): 逆方向 — 必須表に無い委譲を拒否する
    foreach (ExternalSeamInventory::delegations() as $delegation) {
        $dimensions = ExternalSeamInventory::requiredDimensions()[$delegation->kind->value] ?? [];
        if (! in_array($delegation->dimension, $dimensions, true)) {
            $violations[] = "{$delegation->kind->value}|{$delegation->dimension->value}: "
                .'必須表に無い余剰委譲です';
        }
    }

    expect($violations)->toBe([], implode(PHP_EOL, $violations));
});
```

> **この形が要求する目録の性質**: `Payment` / `Captcha` / `Mail` / `MarketData` は「目録に entry があり、委譲は 0 件」。`SocialLogin` は `CodeReachPoint` を目録が、`DestinationSet` を委譲が覆う。`ObjectStorage` / `Llm` は「目録に entry が 0 件、委譲が 1 件」。テスト 13（委譲した種別が本目録に現れない）と合わせて、**同じ到達事実が 2 箇所で宣言されないことが双方向に固定される**。

#### テスト 12 の実装: `PestTestNameScanner`（新規 `tests/Support/PestTestNameScanner.php`）

```php
<?php

declare(strict_types=1);

namespace Tests\Support;

/**
 * Pest テストファイルから **`test(...)` / `it(...)` の第 1 引数の文字列リテラル**を抽出する純関数。
 *
 * ★単純な `str_contains()` にしない（design-review Round 2 [Critical]）。
 *   test を改名しても旧名がコメントや別のリテラルに残れば「含む」は成立してしまい、
 *   「その名前の test が実在する」という保証にならない。
 * ★走査は `PhpTokenScan::normalize()`（コメント除去済み）に対して行い、
 *   `T_STRING(test|it)` + `(` + `T_CONSTANT_ENCAPSED_STRING` の並びだけを採る。
 * ★**対象は Pest のグローバル関数 `test()` / `it()` だけ**である
 *   (design-review Round 3 [Warning])。直前トークンが
 *   `T_OBJECT_OPERATOR` / `T_NULLSAFE_OBJECT_OPERATOR` / `T_DOUBLE_COLON` のものは
 *   メソッド呼び出し (`$object->test('x')` / `SomeClass::test('x')`) なので**除外する**。
 *   直前が `T_FUNCTION`（`function test(` の宣言）も除外する。
 * ★**保証しないもの**: 変数・ヒアドキュメント・連結で組み立てた test 名は抽出できない
 *   （本 repo の Architecture テストはすべて単一リテラルで書かれている）。
 *
 * @return list<string> 抽出した test 名（クォート除去済み）
 */
final class PestTestNameScanner
{
    public static function names(string $phpSource): array { /* 上記の 3 トークン並びを走査 */ }
}
```

`tests/Unit/Architecture/PestTestNameScannerTest.php`（負のコントロールを含む）:

| # | テストケース名 | 検証内容 |
|---|---------------|---------|
| 1 | `test 名スキャナ: test() / it() の第 1 引数を抽出する` | 両方の形式で抽出できる |
| 2 | `test 名スキャナ: コメントにだけある名前は抽出しない` | `// test('消えた名前', …)` → 抽出 0 件（**テスト 12 の意味を担保する負のコントロール**） |
| 3 | `test 名スキャナ: 文字列リテラル中の test(' は抽出しない` | `$x = "test('偽物')";` → 抽出 0 件 |
| 4 | `test 名スキャナ: メソッド呼び出しの test() は抽出しない` | `$object->test('偽物')` / `$object?->test('偽物')` / `SomeClass::test('偽物')` の 3 形 → 抽出 0 件（design-review Round 3 [Warning]） |
| 5 | `test 名スキャナ: function test() の宣言は抽出しない` | `function test(string $name): void {}` → 抽出 0 件 |
| 6 | `test 名スキャナ: 実ファイル (SocialProviderTrustPolicyTest) から委譲先 test 名を抽出できる` | 実ファイルを読み、`全 SSO provider が capability / email_trust を明示宣言している` が含まれる |

#### テスト 11 の positive control（委譲の生存確認を「実行」で担保する）

```php
test('外部到達: 委譲先の母集団が生きている', function (): void {
    foreach (ExternalSeamInventory::delegations() as $delegation) {
        $population = ($delegation->livenessProbe)();

        expect($population)->not->toBeEmpty(
            "委譲: {$delegation->kind->value} × {$delegation->dimension->value} の母集団が空です "
            ."(委譲先 {$delegation->gateFile} の走査条件 / config が壊れている疑い)",
        );
    }

    // 負のコントロール: 委譲先の検出器そのものが生きていることを合成ソースで確認する。
    // 「母集団が非空」だけだと、検出器が何も検出しなくなっても緑になる。
    $awsSource = <<<'PHP'
    <?php
    namespace App\X;
    use Aws\S3\S3Client;
    class Probe { public function make(): S3Client { return new S3Client([]); } }
    PHP;
    expect(ExternalClientBoundaryScanner::boundarySites('probe.php', $awsSource))->not->toBeEmpty();

    $prismSource = <<<'PHP'
    <?php
    namespace App\X;
    use Prism\Prism\Facades\Prism;
    class Probe { public function run(): void { Prism::text(); } }
    PHP;
    expect(PrismDirectDispatchScanner::containsPrismDirectCall($prismSource))->toBeTrue();
});
```

### PHPStan 適合チェック

- [x] 戻り値の型が明示されている（`externalSeamScan(): ExternalSeamScanResult`）
- [x] null 安全（`$site->class` は `!== null` を確認してから集合へ入れる。テスト 3 が構造的に保証する）
- [x] DTO を返している（走査結果は `ExternalSeamScanResult`。array shape を返さない）
- [x] Generics の型パラメータが正しい（`array<string, list<ExternalSeamKind>>` を PHPDoc で明示。`config()->array()` を使い `mixed` を残さない）
- [x] Pest ファイル直下の `const` / `function` はグローバル空間に出るため **`EXTERNAL_SEAM_` 接頭辞**と `externalSeam` 接頭辞で他 Architecture テストと衝突させない

### テスト計画

- [x] バグ修正ではないため再現テストは不要。**先に gate を書いて赤を確認**してから目録を埋める（AGENTS.md 思考原則 5 テストファースト）。具体的には「`entries()` を空にした状態で `外部到達: 走査 site と目録は (クラス, 種別) で双方向に一致する` が **12 個の分類済み到達 `(class, kind)`** を『site に一致する entry が 0 件』として列挙して赤くなる」ことを実測してから登録する。あわせて `外部到達: 種別 × 次元は目録か委譲のちょうど一方で覆われる` が `payment|code_reach_point` 等を『覆われていない』で赤にすることも確認する
- [x] 既存テスト `tests/Architecture/ExternalClientTimeoutInventoryTest.php` / `PromptGuardrailTest.php` / `SocialProviderTrustPolicyTest.php` の更新: **なし**（S4 のクラス移設に伴う `use` 追加を除く）
- [x] 新規テスト: 上表の 15 本
- [x] 個別の `DatabaseTransactions` を使っていない（Architecture レーンは DB 不要）
- [x] Factory: 新モデルを追加しないため不要

#### mutation で赤化を確認する手順（実装時に必ず実施し、結果を worktree のコミットメッセージに残す）

各 mutation は**一時的にコードを壊して赤を確認し、必ず戻す**。`git stash` ではなく手編集 → `git checkout --` で戻す（誤って混入させないため）。

| ID | 壊し方 | 期待する赤 |
|----|--------|-----------|
| M1 | `ExternalSeamInventory::entries()` から `FxRateService` の entry を削除 | テスト 1(a)（site に一致する entry が 0 件）+ テスト 10（`market_data` が目録で覆われない） |
| M2 | `entries()` に走査へ出ないクラス（`App\Models\User`）を追加 | テスト 1(b)（entry に対応する site が 0 件） |
| M3 | `ExternalSeamScanner::FACADE_RULES` を `[]` にする | テスト 1(b)（**残骸側**: 目録の captcha / social_login / mail / market_data の 5 entry に対応する site が消える）+ テスト 7（SocialLogin の走査側集合が空）。**テスト 2 は赤にならない**（payment 6 クラスが残るため `adopted` は非空）。**テスト 10 も赤にならない**（`entries()` は残るので kind × dimension の被覆は成立し続ける。design-review Round 2 [Warning]） |
| M4 | `FACADE_RULES` を `[]` にし、さらに `classify()` の決済分岐を `return null;` にする（**全規則の無効化**） | テスト 2（空振り防止）。M3 と分けるのは「一部規則の欠落」と「走査そのものの死」を別々に固定するため |
| M5 | 適当な Service に `Socialite::driver('google')` を書く | テスト 7（名指し固定）+ テスト 1(a)（新 site に一致する entry が 0 件） |
| M6 | Cashier / Stripe を **import も参照もしない** Service に `$organization->stripe()` を書く | テスト 6（抑制 0 件） |
| M7 | `EXTERNAL_SEAM_RULE_KINDS['http_facade_reference']` から `MarketData` を削る | テスト 1(a)（`FxRateService` の Http site に一致する entry が 0 件）+ テスト 1(b)（`MarketData` entry に対応する site が 0 件）。**テスト 4 は赤にならない**（キー集合の exact-fit と非空は保たれるため。design-review Round 3 [Warning]） |
| M8 | `requiredDimensions()` から `Llm` を削る | テスト 9（exact-fit） |
| M9 | 委譲の `gateTestName` の末尾を 1 文字変える | テスト 12 |
| M10 | `config/template.php` の `social_providers` を `[]` にする | テスト 11（生存確認）+ 既存 `SocialProviderTrustPolicyTest` |
| M11 | `ExternalSeamScanner::FACADE_RULES` に `Aws\S3\S3Client` を足す（`ruleSymbols()` に現れる） | テスト 13（委譲済み名前空間の混入） |
| M12 | 任意の entry の `classification` を `Exempt` にする | テスト 8 |
| M13 | `entries()` に既存 entry と同じ `(class, kind)` をもう 1 件足す | テスト 1(c)（重複禁止） |
| M14 | 委譲先の test をコメントアウトし、同名の文字列をコメントとして残す | テスト 12（`PestTestNameScanner` が抽出しないので赤。単純な `str_contains` なら緑になってしまう箇所） |
| M15 | `FxRateService` に `kind: Mail` の entry を追加（`(class, kind)` は重複しない） | テスト 1(b)（`mail_facade_reference` の site が無いため残骸判定） |
| M16 | `delegations()` に `Payment × CodeReachPoint` の委譲を追加（目録が既に覆っている対） | テスト 10(c)（二重被覆） |
| M17 | `delegations()` の `ObjectStorage × CodeReachPoint` を **2 件へ重複**させる | テスト 10(b)（委譲の重複） |
| M18 | `delegations()` に `Payment × DestinationSet`（**必須表に無い対**）の委譲を足す | テスト 10(d)（余剰委譲） |

**等価変形（赤にならないことを確認する。coverage 表には載せない）**

| ID | 壊し方 | 期待 |
|----|--------|------|
| P1 (等価変形) | `RecaptchaVerifier` の `use Illuminate\Support\Facades\Http;` を消し `\Illuminate\Support\Facades\Http::asForm()` へ書き換える | **全テスト緑のまま**（完全修飾でも `NameReference` として検出する）。alias 経由に依存していないことの確認 |
| P2 (等価変形) | `SocialAuthController` の `Socialite::driver()` を 3 箇所へ増やす | **全テスト緑のまま**（クラス単位の目録なので site 数は問わない） |
（P3 は「赤になる」mutation なので本枠から外し、下の**規則強化の負のコントロール**へ移した。design-review Round 2 [Warning]）

**規則強化の負のコントロール（規則を緩めると赤くなることを確認する。coverage 表には載せない = gate の対象は規則の値ではなく走査結果であるため）**

| ID | 壊し方 | 期待する赤 |
|----|--------|-----------|
| N1 | `ExternalSeamScanner::PAYMENT_CLIENT_CONSTRUCTION_EXACT` を `['Stripe\\']` の**接頭辞判定**へ変える | S6 #6（`Stripe\HttpClient\CurlClient` の new を検出しない）が赤 = 完全一致が偽陽性分離に効いていることの確認 |
| N2 | `ExternalSeamScanner::classify()` の facade 判定に `StaticCall->receiver` 分岐を足す（二重検出させる） | S6 #9 / #11 / #12（「ちょうど 1 件」）が赤 = canonical 契約が守られていることの確認 |

### リスク

- テスト 12（test 名の文字列一致）は、委譲先の test 名を整形・改名しただけで赤くなる。これは**意図した摩擦**だが、実装者が理由を理解できるよう失敗メッセージに「委譲先の test 名を変えたら `ExternalSeamInventory::delegations()` の `gateTestName` も同時に更新する」と書く
- テスト 13 を「走査結果に Aws 由来の site が 0 件」で書くと、`ExternalSeamScanner` が Aws を走査しない以上つねに 0 件で**自明な緑**になる。そこで検査対象は**走査結果ではなく規則そのもの**（`ruleSymbols()` の値）にする。M11 で赤化することを実測で確認する

---

## S6 走査器の unit テスト（負のコントロール）

### 変更箇所

- 新規: `tests/Unit/Architecture/ExternalSeamScannerTest.php`

### 波及変更

なし。

### テスト計画（ファイル名 + テストケース名）

`tests/Unit/Architecture/ExternalSeamScannerTest.php`:

| # | テストケース名 | 検証内容（合成ソース） |
|---|---------------|---------------------|
| 1 | `走査器: Cashier::stripe() を payment_client_call として検出する` | `use Laravel\Cashier\Cashier;` + `Cashier::stripe()` → adopted 1 件 |
| 2 | `走査器: 完全修飾の \Laravel\Cashier\Cashier::stripe() も検出する` | alias 無し FQN でも検出 |
| 3 | `走査器: import だけで決済名前空間を知るファイルの ->stripe() を検出する` | `use Stripe\StripeClient;` があるだけ（**型参照も構築もしない**）で `$organization->stripe()` → adopted 1 件。`use` は site ではないため、`ReferenceScanResult::$imports` を見なければ**必ず落ちる**ケース（design-review Round 1 [Critical] の回帰） |
| 4 | `走査器: 決済名前空間をまったく知らないファイルの ->stripe() は抑制コレクションへ入る` | import も参照も無い → `suppressed` 1 件 / `adopted` 0 件（**抑制が捨てられないことの証明**） |
| 5 | `走査器: new Stripe\StripeClient を payment_client_construction として検出する` | adopted 1 件 |
| 6 | `走査器: Stripe\HttpClient\CurlClient の new は検出しない` | adopted 0 件（T126 の `stripe_global_setter` が正本。責務が交わらないことの証明） |
| 7 | `走査器: Stripe 例外クラスの import だけでは検出しない` | `GatewayFailureClassifier` を模した 14 個の `use Stripe\Exception\*;` → adopted 0 件（**偽陽性分離の主証拠**） |
| 8 | `走査器: Stripe 値オブジェクト (Price / StripeObject) の参照だけでは検出しない` | `StripePriceCatalogEntry` を模した型参照 → adopted 0 件 |
| 9 | `走査器: Socialite facade の静的呼び出しは 1 site として検出する` | `Socialite::driver('google')` → `socialite_facade_reference` **ちょうど 1 件**（receiver の `NameReference` のみを canonical にしている = 二重検出しないことの証明） |
| 10 | `走査器: Socialite Contracts の型参照は検出しない` | `use Laravel\Socialite\Contracts\User as SocialiteUser;` + 引数型 → adopted 0 件（`SocialAccountService` / `EmailTrustPolicy` 系 4 クラスが母集団に入らないことの証明） |
| 11 | `走査器: Http facade を alias / 完全修飾の両形で 1 site ずつ検出する` | `use …\Http;` + `Http::asForm()` と `\Illuminate\Support\Facades\Http::connectTimeout()` が**それぞれ 1 件**（合計 2 件） |
| 12 | `走査器: Mail / Notification facade を検出する` | `Mail::to()` / `Notification::route()` とも `mail_facade_reference` で**各 1 件** |
| 13 | `走査器: コメント・文字列リテラル中の目印を検出しない` | コメントの `Cashier::stripe()` と文字列の `'Socialite::driver'` → adopted 0 件 |
| 14 | `走査器: グループ use と alias を解決する` | `use Illuminate\Support\Facades\{Http, Mail};` / `use ... as Alias;` |
| 15 | `走査器: 同名別 namespace の facade を誤検出しない` | `App\Support\Http::get()` → adopted 0 件 |
| 16 | `走査器: 匿名クラス・ファイルスコープの site を scopeKind で区別する` | 匿名クラス内 / クラス外の `Http::get()` が `AnonymousClass` / `FileScope` として出る（S1 抽出後の scope 追跡の回帰） |
| 17 | `走査器: 文字列補間を含むメソッド本体でも scope 追跡が壊れない` | `"{$x}"` を含むメソッドの後の site が `NamedClass` 帰属（T126 実測バグの回帰） |
| 18 | `走査器: 部分修飾名は解決しない (既存 gate と同じ限界を固定する)` | `namespace App\X; use Illuminate\Support\Facades; Facades\Http::get();` → adopted **0 件**。S1 が振る舞い保存であること（`T_NAME_QUALIFIED` を相対解決しない）を**限界として明示的に固定**し、将来直すときに差分が出るようにする |
| 19 | `走査器: 同名 alias (use ... as Http) を解決する` | `use App\Support\Client as Http;` + `Http::get()` → adopted **0 件**（alias 先が facade でないため）。alias マップが名前ではなく解決先で判定していることの証明 |
| 20 | `走査器: 同一クラスに Http と Mail がある場合は 2 種類の site を返す` | 1 クラス内に `Http::get()` と `Mail::to()` → `http_facade_reference` 1 件 + `mail_facade_reference` 1 件（識別単位が `(class, kind)` であることの前提。design-review Round 2 [Warning]） |

**gate 側（`tests/Architecture/ExternalSeamInventoryTest.php`）に追加する分類単位のテスト**

上表 20 は走査器の性質を固定する。目録側の分類単位はテスト 1 の (a)(b)(c) が担うが、実装時に以下を**合成データ（走査を通さない目録断片）ではなく mutation で**確認する:

- M13（同じ `(class, kind)` の重複登録）→ テスト 1(c) が赤
- M1（entry 削除）→ テスト 1(a) が赤
- M2（対応 site の無い entry 追加）→ テスト 1(b) が赤
- 同一クラスに異なる kind を登録した場合は、**その kind を許す規則の site が別途必要**になる。現在の母集団には該当クラスが無いため、M1 の逆操作（`FxRateService` に `kind: Mail` の entry を足す）で テスト 1(b) が赤になることを確認する（**M15**）

- [x] 個別の `DatabaseTransactions` を使っていない（Unit レーン・DB 不要）
- [x] テストデータは合成ソース文字列（Factory 不要 = モデルを使わない）

### リスク

- 合成ソースが実コードと乖離すると、negative control が「実際には起きない形」を検査するだけになる。テスト 7 / 8 / 10 は**実ファイル（`GatewayFailureClassifier` / `StripePriceCatalogEntry` / `SocialAccountService`）の import 節を写して**作る

---

## S7 captcha fake の配線 + capability flag 名の是正

### 変更箇所

- `app/Providers/FakeExternalsServiceProvider.php`（L45-46 定数 / L54 呼び出し / L64-84 メソッド）
- `tests/Support/ExternalFakes/ExternalFakeWiringInventory.php`（L39 / L47-48 / L86-131）
- `tests/Architecture/ExternalFakeWiringInvariantTest.php`（3-4 の定数参照 3 箇所 + test 名）
- `config/testing.php`（`fake_externals` の docblock）
- `.env.bughunt.local.example`（L60-66 のコメント）
- 新規 `tests/Feature/Captcha/RecaptchaVerifierFakeWiringTest.php`

### 波及変更（実測で確認済み）

- TypeScript 型定義: **なし**
- API Resource/DTO: **なし**
- `app/Support/ProductionEnvGuard.php`: **変更不要**。`config('testing.fake_externals')` を直接読んでおり、定数名に依存していない（実読確認済み）
- `tests/Architecture/FakeClassReferenceInvariantTest.php`: **変更不要**。4-2 は `placementExceptions()`（2 件）、4-4 は `FAKE_REFERENCE_ALLOWED`（4 ファイル）を固定しており、`FakeExternalsServiceProvider.php` は既に allowlist に入っている。`RecaptchaVerifierTestFake` は `app/Services/Captcha/Testing/` にあるため `FakeClassCatalog::implementationClasses()` に既に含まれ、4-1（配置規約）も既に緑（**recon-brief の申し送り (h) は誤り**）
- `tests/Architecture/ExternalFakeWiringInvariantTest.php` の 3-1 / 3-2 / 3-3: dataset 駆動のため **entry 追加で検査が自動的に増える**（1 entry × 3 環境 = 対照 1 + 実証 3 + 拒否 2 の 6 ケースが増える）
- 同 3-8 / 3-10: 集合一致のため provider と inventory を**同時に**更新すれば緑のまま
- `tests/Unit/Services/Captcha/RecaptchaVerifierTest.php`: **変更不要**（`new RecaptchaVerifierTestFake` を直接生成しており container を経由しない）
- `tests/Feature/**` の問い合わせフォーム系: **変更不要**（`testing` 環境は `TESTING_FAKE_EXTERNALS` 未設定 = 既定 false のため解決結果が変わらない）

### 現行コード

```php
// app/Providers/FakeExternalsServiceProvider.php
    /** Stripe 課金 gateway fake を許可する環境 allowlist (container bind。per-test 隔離が効くため testing 可) */
    private const array PAYMENT_FAKE_ENVIRONMENTS = ['local', 'testing', 'bughunt.local'];

    public function register(): void
    {
        $this->registerPaymentFakes(); // Stripe: fake_externals 依存 (挙動不変)
        $this->registerStorageFakes(); // storage: fake_storage (FakeStorageGate) 依存 — 独立
    }

    /** Stripe 課金 gateway fake (fake_externals + PAYMENT_FAKE_ENVIRONMENTS。挙動不変) */
    private function registerPaymentFakes(): void
    {
        if (config('testing.fake_externals') !== true) {
            return;
        }

        $environment = $this->app->environment();
        if (! in_array($environment, self::PAYMENT_FAKE_ENVIRONMENTS, true)) {
            Log::warning('TESTING_FAKE_EXTERNALS=true ですが allowlist 外の環境のため fake を bind しません。', [
                'environment' => $environment,
            ]);

            return;
        }

        // Stripe 到達点を fake へ rebind (課金状態の正本は BughuntBillingSeeder)
        $this->app->bind(TicketCheckoutGateway::class, FakeTicketCheckoutGateway::class);
        $this->app->bind(StripeGatewayInterface::class, FakeStripeGateway::class);
        $this->app->bind(AutoRechargeGatewayInterface::class, FakeAutoRechargeGateway::class);
    }
```

```php
// tests/Support/ExternalFakes/ExternalFakeWiringInventory.php
    /** 課金 fake の capability flag */
    public const string PAYMENT_FLAG = 'testing.fake_externals';

    /** 課金 fake の env allowlist (FakeExternalsServiceProvider::PAYMENT_FAKE_ENVIRONMENTS と対) */
    private const array PAYMENT_ENVIRONMENTS = ['local', 'testing', 'bughunt.local'];
```

### 変更後コード

```php
// app/Providers/FakeExternalsServiceProvider.php
    /**
     * 外部サービス fake を許可する環境 allowlist (container bind。per-test 隔離が効くため testing 可)。
     *
     * ★対象は **Stripe 課金 gateway と captcha 検証器**。SSO (Socialite) は fake しない
     *   (差し替え先を作っていない。docs/architecture.md §外部到達点の目録)。
     */
    private const array EXTERNAL_FAKE_ENVIRONMENTS = ['local', 'testing', 'bughunt.local'];

    public function register(): void
    {
        $this->registerExternalServiceFakes(); // Stripe + captcha: fake_externals 依存 (挙動不変)
        $this->registerStorageFakes();         // storage: fake_storage (FakeStorageGate) 依存 — 独立
    }

    /** 外部サービス fake (fake_externals + EXTERNAL_FAKE_ENVIRONMENTS。挙動不変) */
    private function registerExternalServiceFakes(): void
    {
        if (config('testing.fake_externals') !== true) {
            return;
        }

        $environment = $this->app->environment();
        if (! in_array($environment, self::EXTERNAL_FAKE_ENVIRONMENTS, true)) {
            Log::warning('TESTING_FAKE_EXTERNALS=true ですが allowlist 外の環境のため fake を bind しません。', [
                'environment' => $environment,
            ]);

            return;
        }

        // Stripe 到達点を fake へ rebind (課金状態の正本は BughuntBillingSeeder)
        $this->app->bind(TicketCheckoutGateway::class, FakeTicketCheckoutGateway::class);
        $this->app->bind(StripeGatewayInterface::class, FakeStripeGateway::class);
        $this->app->bind(AutoRechargeGatewayInterface::class, FakeAutoRechargeGateway::class);

        // captcha 到達点を fake へ rebind。
        // ★abstract が具象クラスのため、bind を消しても Laravel が本物を自動組み立てし、
        //   RECAPTCHA_SECRET_KEY が設定された瞬間に**無言で** Google siteverify を叩く。
        //   StrayHttpRequestGuard は bug-hunt の別プロセス実行には効かない (AGENTS.md)。
        $this->app->bind(RecaptchaVerifier::class, RecaptchaVerifierTestFake::class);
    }
```

```php
// tests/Support/ExternalFakes/ExternalFakeWiringInventory.php
    /** 外部サービス fake (Stripe 課金 + captcha) の capability flag */
    public const string EXTERNALS_FLAG = 'testing.fake_externals';

    /** 外部サービス fake の env allowlist (FakeExternalsServiceProvider::EXTERNAL_FAKE_ENVIRONMENTS と対) */
    private const array EXTERNAL_ENVIRONMENTS = ['local', 'testing', 'bughunt.local'];

    // …bindings() の末尾に 6 本目を追加
            new ExternalFakeBinding(
                abstract: RecaptchaVerifier::class,
                real: RecaptchaVerifier::class,
                fake: RecaptchaVerifierTestFake::class,
                flag: self::EXTERNALS_FLAG,
                allowedEnvironments: self::EXTERNAL_ENVIRONMENTS,
                risk: 'Google reCAPTCHA siteverify への外向き POST。abstract が具象クラスのため、'
                    .'bind を消しても Laravel が本物を自動組み立てし、RECAPTCHA_SECRET_KEY が'
                    .'設定された環境では無言で実 Google を叩く (bug-hunt の別プロセスには '
                    .'StrayHttpRequestGuard が効かない)。',
            ),
```

`tests/Architecture/ExternalFakeWiringInvariantTest.php` の 3-4 は定数名の置換のみ（test 名も「課金 flag」→「外部サービス fake flag」へ）:

```php
test('3-4 provider 単体: 外部サービス fake flag on + allowlist 外 env は warning を出す', function (): void {
    $originalFlag = config(ExternalFakeWiringInventory::EXTERNALS_FLAG);
    // …以下同様に EXTERNALS_FLAG へ置換
});
```

`config/testing.php` の docblock:

```php
    /*
    | fake_externals: **外部サービス fake の capability flag** (既定 false = no-op)。
    | true のとき FakeExternalsServiceProvider::register() が以下を fake 実装へ bind する:
    |   - Stripe 課金 gateway (checkout / portal / auto-recharge)
    |   - captcha 検証器 (RecaptchaVerifier → RecaptchaVerifierTestFake)
    | **SSO (Socialite) は fake しない** (差し替え先を作っていない。
    |  bug-hunt のブラウザは SSO ボタンから実 IdP へ遷移する。
    |  docs/architecture.md §外部到達点の目録 (標準形 v1) を参照)。
    | 有効化は allowlist 環境 (local / testing / bughunt.local) に限定され、
    | production では ProductionEnvGuard が true を deploy 時 fail-fast で拒否する。
    */
```

`.env.bughunt.local.example` も同趣旨（「Stripe 課金 fake」→「外部サービス fake (Stripe 課金 gateway + captcha 検証器)。SSO は fake しない」）へ是正する。

> **完了条件から外すもの**: `.env.bughunt.local`（git 管理外）の記述是正は本 PR の完了条件に含めない。追跡対象は `.env.bughunt.local.example` と `config/testing.php` だけである。

### PHPStan 適合チェック

- [x] 戻り値の型が明示されている（`register(): void` / `registerExternalServiceFakes(): void`）
- [x] null 安全（`config('testing.fake_externals') !== true` の厳密比較を維持）
- [x] DTO を返している（`ExternalFakeBinding` は既存の readonly value object）
- [x] Generics の型パラメータが正しい（`bindings(): list<ExternalFakeBinding>` の PHPDoc は既存のまま）
- [x] `ExternalFakeBinding::$abstract` / `$real` / `$fake` は `class-string`。`RecaptchaVerifier` / `RecaptchaVerifierTestFake` はいずれも実在クラス

### テスト計画

**既存テストの更新（削除・上書きはしない）**

- [x] `tests/Architecture/ExternalFakeWiringInvariantTest.php`: 3-4 の定数参照を `EXTERNALS_FLAG` へ置換し test 名を是正。**3-1 / 3-2 / 3-3 / 3-8 / 3-10 は本文無変更**（dataset と集合一致が自動追随する）
- [x] `tests/Architecture/FakeClassReferenceInvariantTest.php`: **変更なし**（4-2 / 4-4 の件数は動かない）

**新規テスト `tests/Feature/Captcha/RecaptchaVerifierFakeWiringTest.php`**

| # | テストケース名 | 検証内容 |
|---|---------------|---------|
| 1 | `fake 配線時は secret があっても Google siteverify を叩かずに true を返す` | `Http::fake()` → env を `bughunt.local` に、`testing.fake_externals=true`、`services.recaptcha.secret_key` にダミーを設定 → provider を `register()` → `app(RecaptchaVerifier::class)->verify('token', '203.0.113.1')` が `true` → `Http::assertNothingSent()` |
| 2 | `flag off では secret がある限り siteverify へ 1 回だけ出る（負のコントロール）` | `Http::fake([...siteverify => success])` → flag off のまま同じ呼び出し → `Http::assertSentCount(1)` かつ URL が `https://www.google.com/recaptcha/api/siteverify`。**これが無いとテスト 1 は「そもそも出ない状況」を検査しているだけになる** |
| 3 | `secret 未設定なら fake の有無に関わらず外部へ出ない（現状の追認）` | secret を空に → `Http::assertNothingSent()`。recon-brief の「bug-hunt から実 Google へ出る」が**secret 設定時に限る**という実査結果を回帰として固定する |

- **環境の復元手順は既存作法に揃える**（design-review Round 1 [Warning]）。repo に共通 helper は無く、`ExternalFakeWiringInvariantTest` の 3-2 / 3-3 が `$originalFlag = config($flag); $originalEnvironment = $this->app['env'];` を退避し、`try { … } finally { config([$flag => $originalFlag]); $this->app['env'] = $originalEnvironment; }` で復元する形を採っている。**同じ形をそのまま使う**（新しい helper を作らない = 思考原則 2）
- `Http::fake()` は Laravel が test ごとに破棄するため明示復元は不要。ただし `StrayHttpRequestGuard` の accumulator と併存するので、テスト 2 では `Http::fake([...])` で siteverify を模擬応答させ、実通信が発生しないようにする
- [x] 個別の `DatabaseTransactions` を使っていない（`RefreshDatabase` はグローバル適用。本テストは DB を使わないが Feature レーンの既定に従う）
- [x] テストデータは Factory（本テストはモデルを使わないため該当なし）

### リスク

- **定数リネームの取りこぼし**: `PAYMENT_FLAG` / `PAYMENT_ENVIRONMENTS` / `PAYMENT_FAKE_ENVIRONMENTS` / `registerPaymentFakes` の参照は実測で 3 ファイルのみ（`FakeExternalsServiceProvider` / `ExternalFakeWiringInventory` / `ExternalFakeWiringInvariantTest`）。取りこぼしは PHPStan が未定義定数として落とす
- **`testing` レーンでの挙動変化**: `TESTING_FAKE_EXTERNALS` は `.env.testing` に無く config 既定 false のため、既存 Feature テストの `app(RecaptchaVerifier::class)` の解決結果は不変。ただし 3-2 の dataset が `testing` 環境で fake を bind するケースを増やすため、**Architecture レーンで container が汚れないこと**（各 test case ごとに app が再構築される既存前提）に依存する。既存 5 binding と同じ構造なので新しい前提は増えない
- **`RecaptchaVerifierTestFake` は `final`**。`ExternalFakeBinding` は厳密クラス一致で判定するため継承の問題は起きない。ただし `RecaptchaVerifier` は `final` ではない（継承されている）ため、bind の abstract に具象クラスを使う既存パターン（`TakeObjectStorage`）と同型

---

## S8 運用契約の記録

### 変更箇所

- `docs/architecture.md`: 新設「## 外部到達点の目録 (標準形 v1)」（既存「### S3 到達境界と面分類」= 1021 行付近 の直後に置き、相互参照する）。**「保証しないもの」の完全一覧はここが正本**（項目数は今後増減するため本設計に件数を書かない）
- `AGENTS.md`: 「ドメイン固有規約」に 9 項目目を追加（現行 8 項）
- `docs/app-integration-guide.md`: §7 のセキュリティ不変条件の解説に相互参照を **1 行だけ**追加（**§7 の不変条件番号は動かさない**。AGENTS.md の採番注意に従い、番号ではなく項目名で参照する）

### 波及変更

- `tests/js/architecture/verification-commands-doc-sync.test.ts`: **影響なし**（`VERIFICATION_COMMANDS` マーカーを触らない）
- `docs/TODO.md`: 本設計は登録対象。登録は `app-todo-add` スキルの責務（本 PR の変更ファイルには含めない）

### 変更後コード（`AGENTS.md` に追加する 9 項目目）

```markdown
9. **外部到達点の目録 (標準形 v1 / 検知 v1)**: app/ から外部へ出るコード到達点は、
   種別 (`ExternalSeamKind`) を宣言して `ExternalSeamInventory::entries()` へ登録する
   (`ExternalSeamInventoryTest` が deny-by-default で強制)。対象規則は
   **決済 client の取得・構築** (`Cashier::stripe()` / `->stripe()` / `new Stripe\StripeClient`) /
   **Socialite facade** / **Http facade** / **Mail・Notification facade** の 5 種で、
   Stripe 例外クラスや値オブジェクトの参照は**規則の段階で母集団に入れない** (偽陽性を作らない)。
   - **ファイル保存 (AWS / Flysystem) と LLM (Prism) は本目録に載せない**。前者は
     `ExternalClientTimeoutInventoryTest` の到達境界目録、後者は `PromptGuardrailTest` の
     Prism 直呼び禁止が正本で、`ExternalSeamInventory::delegations()` が機械的に結線する
     (同じ到達事実を 2 箇所で宣言しない)。
   - **SSO は `SocialAuthController` 1 クラスに名指し固定**され、他クラスからの
     `Socialite::driver()` は登録も免除もできない (集約と直呼び禁止の機械化)。
     宛先集合 (`config/template.php` の `social_providers`) の増加は
     `SocialProviderTrustPolicyTest` へ委譲する。
   - **保証範囲を誇張しない**: これは**検知**であって**遮断ではない**。bug-hunt のブラウザは
     SSO ボタンから実 IdP へ遷移する。走査根は `app/` のみで `routes/` / `config/` は見ない。
     委譲先の assert の中身を弱める改変、次元そのものの数え落とし、部分修飾名、
     文字列キーの container 解決だけの経路、vendor 内部から出る通信、他種別の宛先集合、
     決済の別 API 表面、git 管理外の `.env.bughunt.local` は検出・固定できない。
     **保証しないものの完全な一覧は `docs/architecture.md` §外部到達点の目録 (標準形 v1) が正本**
     (ここは要約であり、増減はそちらで管理する)。
   - 非本番の captcha は `testing.fake_externals` で `RecaptchaVerifierTestFake` へ bind される
     (`ExternalFakeWiringInventory`)。**SSO は fake しない**。
   - 詳細は `docs/architecture.md` §外部到達点の目録 (標準形 v1)。
```

### PHPStan 適合チェック

該当なし（Markdown のみ）。

### テスト計画

- [ ] `composer test` / `pnpm test` が緑（ドキュメント変更で壊れる gate が無いことの確認）
- [x] ドキュメント同期を強制する既存 gate（`verification-commands-doc-sync.test.ts`）の対象マーカーに触れない

### リスク

- `docs/app-integration-guide.md` §7 の不変条件番号を動かすと既存参照が壊れる（AGENTS.md の採番注意）。**番号ではなく項目名で参照する**

---

## 実測母集団（実装前に固定しておく期待値）

実装者は最初にこの 12 クラス（+ 抑制 0 件）が走査結果と一致することを確認する。ずれていたら**規則の実装が違う**。

| 種別 | クラス | 検出規則 |
|------|--------|---------|
| payment | `App\Services\Billing\CashierStripeGateway` | `payment_client_call` |
| payment | `App\Services\Billing\CashierTicketCheckoutGateway` | `payment_client_call` |
| payment | `App\Services\Billing\CashierAutoRechargeGateway` | `payment_client_call` |
| payment | `App\Services\Billing\StripeScheduleGateway` | `payment_client_call` |
| payment | `App\Console\Commands\Billing\EnsurePortalConfiguration` | `payment_client_call` |
| payment | `App\Providers\AppServiceProvider` | `payment_client_call` |
| social_login | `App\Http\Controllers\Auth\SocialAuthController` | `socialite_facade_reference` |
| captcha | `App\Services\Captcha\RecaptchaVerifier` | `http_facade_reference` |
| mail | `App\Actions\Inquiry\CreateInquiryAction` | `mail_facade_reference` |
| mail | `App\Services\Organization\OrganizationMembershipService` | `mail_facade_reference` |
| mail | `App\Actions\Fortify\UpdateUserProfileInformation` | `mail_facade_reference` |
| market_data | `App\Services\FxRateService` | `http_facade_reference` |

**母集団に入らないことを確認するクラス（偽陽性分離の実測）**: `App\Support\Billing\GatewayFailureClassifier`（Stripe 例外 14 クラスの import）/ `App\DataTransferObjects\Billing\StripePriceCatalogEntry`（`Stripe\Price` / `StripeObject`）/ `App\Services\Billing\StripeWebhookProcessor` / `App\Models\Organization` / `App\Models\Billing\Subscription` / `App\Jobs\Billing\SyncBillingCustomerDetails` / `App\Services\Billing\StripePriceCatalogClient`（`PriceService` を DI で受けるのみ）/ `App\Providers\ExternalClientTimeoutServiceProvider`（`new Stripe\HttpClient\CurlClient` = T126 の担当）/ `App\Services\Auth\SocialAccountService` と `App\Services\Auth\EmailTrust\*`（`Socialite\Contracts\User` の型参照のみ）

---

## 実装順序（テストファースト）

1. **S4**（`PrismDirectDispatchScanner` 移設）→ `composer test -- --filter=PromptGuardrail` 緑
2. **S1**（`PhpReferenceScanner` 抽出）→ `ExternalClientBoundaryScannerTest` と `ExternalClientTimeoutInventoryTest` を**無変更で**緑
3. **S6** の合成ソーステストを先に書く（`ExternalSeamScanner` は未実装 = 赤）→ **S2** を実装して緑
4. **S5** の gate と `PestTestNameScanner` を先に書く（`entries()` は空 = 12 個の `(class, kind)` を列挙して赤）→ **S3** の目録を埋めて緑
5. **S7**（captcha 配線）→ 新規 Feature テストの負のコントロール（テスト 2）が先に緑であることを確認してからテスト 1 を通す
6. **S8**（ドキュメント）
7. 全 mutation（**M1〜M18**）を実施して赤化を確認し、必ず戻す。**1 mutation = 1 操作**とし、結果は mutation ID ごとに個別に記録する（design-review Round 5 [Suggestion]。1 項目に 2 操作を束ねると「どちらで赤くなったか」が残らない）。続けて**等価変形 P1・P2**（緑のまま）と**規則強化の負のコントロール N1・N2**（赤）も実施する

## 検証コマンド

`composer test` / `composer phpstan` / `vendor/bin/pint --test` / `pnpm lint` / `pnpm typecheck` / `pnpm test` / `pnpm build`（フロント変更は無いが CI と同じ全 green を確認する）

## 実装モード

| 項目 | 内容 |
|------|------|
| 推奨モード | **standalone** |
| 判断根拠 | (1) `tests/Support/ExternalClientBoundaryScanner.php` の内部を抽出する S1 は、T126 gate という**既存の緑テストを回帰の証拠にする**必要があり、他施策の変更が混ざると「壊したのはどれか」が切り分けられない。(2) `FakeExternalsServiceProvider` / `ExternalFakeWiringInventory` は複数の Architecture gate（3-8 / 3-10 の集合一致）が同時に見ており、他タスクが同ファイルを触ると衝突が gate 赤として現れる。(3) `AGENTS.md` のドメイン固有規約への追記は項番を動かすため、並行タスクとの競合が起きやすい |
| 競合リスク | **中**。同時に走っている他 2 設計（`idempotency-concurrent-claim` / `queue-dispatch-atomicity`）が `AGENTS.md` ドメイン固有規約と `docs/architecture.md` を触る可能性がある。項番の衝突は**マージ時に手で解消**する（本設計は「9 項目目」を前提にするが、先にマージされた側があれば繰り下げる）。テストファイルは重ならない見込み |

## 保証しないもの（設計としての明記）

本設計が作るのは **検知 v1** であり、以下は**保証しない**。

> **記載場所の契約**（design-review Round 2 [Warning] 反映）: 同文を 3 箇所へ複製するとドリフトする。
> **`docs/architecture.md` §外部到達点の目録 (標準形 v1) を詳細の正本**とし、
> `tests/Architecture/ExternalSeamInventoryTest.php` の冒頭コメントと `AGENTS.md` の規約 9 には
> **要約と正本への参照**だけを書く（gate 冒頭には「この gate が保証するもの / しないもの」の
> 実務上重要な項目 = 1・2・3・4 を残し、残りは正本へ委ねる）。

1. **出口の遮断**。bug-hunt のブラウザが SSO ボタンから `accounts.google.com` へ遷移する現状は変わらない（独立 TODO `bughunt-sso-egress` へ分離）
2. **委譲先の assert の中身**。母集団の生存と test 名の同定までが結線の保証範囲
3. **`app/` の外**。`routes/` / `config/` に書かれた到達コードは走査しない（SSO の宛先集合のみ委譲で押さえる = SSO 固有の措置）
4. **次元そのものの数え落とし**。次元の定義は人手であり、第 3 の次元が生まれても沈黙する
5. **文字列キーの container 解決だけの経路**（型名も呼び出しも出さない形）
6. **vendor 内部から出る通信**（Cashier / Socialite の内部実装）
7. **他種別の宛先集合**（Stripe の API キーが指す account / SES の region / 為替 API の URL）
8. **`.env.bughunt.local`（git 管理外）の内容**。pin できるのは `.env.bughunt.local.example` まで
9. **決済の別 API 表面**。検出は「client の取得・構築」に限り、新しい静的 helper が増えたときは規則の追加が要る
10. **部分修飾名の解決**。`T_NAME_QUALIFIED`（`Facades\Http::get()` のような書き方）は現在の namespace への相対解決も先頭 segment の alias 解決も行わない（既存 `ExternalClientBoundaryScanner` と同じ限界。S1 は振る舞い保存が目的なので直さない）。この限界は S6 #18 が**テストとして明示的に固定**しており、将来直すときは必ず差分が出る
