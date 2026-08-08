【アプリの使命 (North Star) — AGENTS.md より】

**AI-CUE** は、現場に既にある**作業手順書(SOP)を起点に**、AI が撮るべきカットを設計した**動画シナリオ**を生成し、そのシナリオを**スマホ(PWA)でナビゲーション撮影**することで、専門知識ゼロの現場作業者でも**標準化されたマニュアル動画**を作れるようにする。

- 「思考ゼロ・編集ゼロ」— 台本作成・撮影判断・編集の 3 ハードルを AI とナビ撮影で肩代わりする。
- 競合(OJT を撮って形式化する tebiki)と異なり、**標準作業を起点に AI が教材設計し撮影を指示する**（撮影者・教える人のスキルに品質を依存させない）。
- 熟練者の暗黙知を動画マニュアルという形式知へ変換する装置（SECI）。

> 詳細な設計は本リポジトリの `doc/`（01〜10）に置く。ドメインをテンプレート構造へどうマップしたかは `doc/08`〜`doc/10`（特に `doc/10 §10.8` に設計レビュー反映済み）。
> **v1 スコープ**: 字幕のみ(TTS 後回し) / 撮影は PWA(同一オリジン・セッション認証) / 動画合成は自前 ffmpeg / 単一 Default Project。

【禁止事項 — AGENTS.md より】

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

なお AGENTS.md の思考原則には次も含まれる (本設計の判断根拠として頻出する):
1. フレームワークのレンジ内でやる 2. **今必要なものだけ作る (オーバーエンジニアリング禁止。「あったら便利」は作らない)** 3. 後方互換の並走を残さない 4. **別物の概念を「似ているから」で統合しない** 5. テストファースト 6. タコツボ実装を避ける

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
3. PHPStan level 10 適合性（型安全性、generics、Assert使用）
4. テスト計画の網羅性（各施策にPestテスト、RefreshDatabaseグローバル適用に従う）
5. DTO/JsonResource パターンの遵守
6. Inertia Props vs API Responseの使い分け
7. 副作用・後退リスク
8. 波及変更の網羅性（TypeScript型定義、API Resource、テストが変更対象に含まれているか）
9. セキュリティ（認可チェック、入力バリデーション、OWASP Top 10、AGENTS.md のセキュリティ不変条件）
10. DESIGN.md準拠（UI/frontend 変更を含む場合）
11. Atomic Design準拠（UI/frontend 変更を含む場合）

【本件固有の追加観点】
12. **既定拒否 gate の設計**: 走査母集団 0 件で緑にならないか。検査が空振りしないことの保証があるか。負のコントロールが「そもそも起きない状況」を検査しているだけになっていないか。
13. **走査器リファクタ (S1) の安全性**: `ExternalClientBoundaryScanner` の内部抽出が振る舞いを変えないか。変える可能性のある箇所はどこか。
14. **mutation 手順の妥当性**: 各 mutation が本当に指定のテストを赤にするか。赤にならない mutation は無いか。
15. **保証しないものの誠実さ**: 誇張・過小がないか。

【出力形式】
- 各施策ごとに判定: APPROVE / REQUEST_CHANGES
- 指摘は [Critical] [Warning] [Suggestion] で分類
- Critical/Warning には必ず修正案を添える
- 全体判定: APPROVED / CHANGES_REQUESTED
- 日本語で出力

---

## 詳細設計書

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
| S1 | 走査基盤 `PhpReferenceScanner` の抽出（振る舞い保存） | 新規 `tests/Support/PhpReferenceScanner.php` / `tests/Support/ReferenceKind.php` / `tests/Support/ReferenceSite.php`、改修 `tests/Support/ExternalClientBoundaryScanner.php` | High |
| S2 | `ExternalSeamScanner` 新設（規則 5 種 + 抑制コレクション） | 新規 `tests/Support/ExternalSeam/ExternalSeamScanner.php` / `ExternalSeamRule.php` / `ExternalSeamSite.php` / `ExternalSeamScanResult.php` | High |
| S3 | 型付き語彙と目録 | 新規 `app/Enums/Security/ExternalSeamKind.php` / `ExternalSeamClassification.php` / `ExternalSeamDimension.php`、`tests/Support/ExternalSeam/ExternalSeamEntry.php` / `ExternalSeamDelegation.php` / `ExternalSeamInventory.php` | High |
| S4 | `PrismDirectDispatchScanner` の `tests/Support/` 移設（委譲の behavioral 生存確認を可能にする） | 新規 `tests/Support/Prompts/PrismDirectDispatchScanner.php`、改修 `tests/Architecture/PromptGuardrailTest.php` | Medium |
| S5 | gate `ExternalSeamInventoryTest` | 新規 `tests/Architecture/ExternalSeamInventoryTest.php` | High |
| S6 | 走査器の unit テスト（負のコントロール） | 新規 `tests/Unit/Architecture/ExternalSeamScannerTest.php` | High |
| S7 | captcha fake の配線 + capability flag 名の是正 | 改修 `app/Providers/FakeExternalsServiceProvider.php` / `tests/Support/ExternalFakes/ExternalFakeWiringInventory.php` / `tests/Architecture/ExternalFakeWiringInvariantTest.php` / `config/testing.php` / `.env.bughunt.local.example`、新規 `tests/Feature/Captcha/RecaptchaVerifierFakeWiringTest.php` | High |
| S8 | 運用契約の記録 | 改修 `docs/architecture.md` / `AGENTS.md` | Medium |

---

## S1 走査基盤 `PhpReferenceScanner` の抽出

### 変更箇所

- 新規: `tests/Support/PhpReferenceScanner.php` / `tests/Support/ReferenceKind.php` / `tests/Support/ReferenceSite.php`
- 改修: `tests/Support/ExternalClientBoundaryScanner.php`（L105-304 の走査ループを委譲へ置換。**public API は不変**）

### なぜ抽出するか

`ExternalSeamScanner` が必要とするのは、`ExternalClientBoundaryScanner::scan()` のうち **namespace 解決 / `use` alias マップ / brace 深さによる scope 追跡 / callable 名の追跡 / 名前参照と呼び出しの列挙**である。ここは実測でバグ実績のある繊細な処理（`T_CURLY_OPEN` を depth に数えないと以降の site が誤って FileScope 帰属になる、という実測コメントが現行コードにある）。2 本持つと必ず割れる。`PhpTokenScan` の docblock が既に「同じ正規化を 2 本持たない」という方針を明文化しており、その延長として抽出する。

### 波及変更

- TypeScript 型定義: なし
- API Resource/DTO: なし
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
     * 参照 site を列挙する。
     *
     * @return list<ReferenceSite>
     */
    public static function references(string $relativePath, string $phpSource): array
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

    foreach (PhpReferenceScanner::references($relativePath, $phpSource) as $reference) {
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

- [x] 戻り値の型が明示されている（`ReferenceSite` は `readonly` value object。配列 shape は `PhpReferenceScanner::tokens()` の `list<array{id: int|null, text: string, line: int}>` のみで、`PhpTokenScan` の既存 PHPDoc をそのまま継承する）
- [x] null 安全（`receiver` / `class` / `callable` は `?string` で宣言し、利用側で `!== null` 判定を通す）
- [x] DTO を返している（`list<ReferenceSite>`。`mixed` / 未指定 array を残さない）
- [x] Generics の型パラメータが正しい（`list<ReferenceSite>` / `array<string, string>`（alias マップ））
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

    public static function scan(string $relativePath, string $phpSource): ExternalSeamScanResult
    {
        $references = PhpReferenceScanner::references($relativePath, $phpSource);
        $hasPaymentNamespace = self::hasPaymentNamespaceReference($references);

        $adopted = [];
        $suppressed = [];

        foreach ($references as $reference) {
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

        // facade 参照（型参照 / 静的呼び出しの receiver いずれも `NameReference` として現れる）
        if ($reference->kind === ReferenceKind::NameReference
            && array_key_exists($reference->name, self::FACADE_RULES)
        ) {
            return self::site($reference, self::FACADE_RULES[$reference->name], $reference->name);
        }

        return null;
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

> **`Socialite::driver()` が拾える理由**: `Socialite::driver(...)` は正規化トークン列では `T_STRING('Socialite')` + `T_DOUBLE_COLON` + `T_STRING('driver')` + `(` となる。`PhpReferenceScanner` はこれを `StaticCall(name: 'driver', receiver: 'Laravel\Socialite\Facades\Socialite')` として emit し、**receiver 側の `Socialite` は `NameReference` としては emit しない**（現行 `ExternalClientBoundaryScanner` の R3 も `$isStaticAccess` で `continue` している）。
> したがって facade 参照規則は `NameReference` だけでは**取りこぼす**。実装では `FACADE_RULES` の判定を **`NameReference` の `name`** と **`StaticCall` / `Construction` の `receiver`** の両方に対して行うこと。上記コードの `classify()` は `$reference->receiver` 分岐を追加する（実装時の必須事項として明記する）。
> 同じ理由で `Mail::to(...)` / `Notification::route(...)` / `Http::asForm()` も `StaticCall` の receiver 経由で拾う。**この 1 点を落とすと母集団が 0 件になり、空振り防止テストが即座に赤くなる**（= 設計どおり検知される）。

### PHPStan 適合チェック

- [x] 戻り値の型が明示されている（`ExternalSeamScanResult` / `?ExternalSeamSite`）
- [x] null 安全（`classify()` は `?ExternalSeamSite`。`receiver` は `!== null` を通してから比較）
- [x] DTO を返している（配列返却なし。`array{...}` shape をコレクション要素に使わない）
- [x] Generics の型パラメータが正しい（`list<ExternalSeamSite>` を PHPDoc で明示）

### テスト計画

S6（`tests/Unit/Architecture/ExternalSeamScannerTest.php`）に一括。

### リスク

- 上記「facade は `StaticCall` の receiver 経由でも拾う」を落とすと母集団が激減する。空振り防止テスト（`外部到達: 走査母集団が空でない`）と対称差ゼロテストの両方が赤くなるため、無言では通らない
- `->stripe()` の receiver 非依存判定が、将来 `stripe()` という名前の無関係なメソッドを拾う。抑制コレクション 0 件検査が赤くなって気づける（抑制された時点で赤 = 「静かに効く」ことがない）

---

## S3 型付き語彙と目録

### 変更箇所

- 新規: `app/Enums/Security/ExternalSeamKind.php` / `ExternalSeamClassification.php` / `ExternalSeamDimension.php`
- 新規: `tests/Support/ExternalSeam/ExternalSeamEntry.php` / `ExternalSeamDelegation.php` / `ExternalSeamInventory.php`

### 波及変更

- TypeScript 型定義: なし / API Resource・DTO: なし / 既存テスト: なし

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
 *   - 規則ごとに名乗ってよる種別が固定されている（種別が登録者の言い値にならない）
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
    'M1' => '目録から entry を 1 つ消すと対称差ゼロが赤くなる',
    'M2' => '目録に実在しないクラスを足すと対称差ゼロ（残骸側）が赤くなる',
    'M3' => 'RecaptchaVerifier の Http import を消すと母集団が減り対称差ゼロが赤くなる',
    'M4' => 'ExternalSeamScanner の FACADE_RULES を空にすると空振り防止が赤くなる',
    'M5' => 'SocialAuthController 以外のクラスに Socialite::driver() を書くと名指し固定が赤くなる',
    'M6' => 'Cashier / Stripe を import しないクラスに ->stripe() を書くと抑制 0 件が赤くなる',
    'M7' => '規則→種別表の 1 行を書き換えると種別突合が赤くなる',
    'M8' => 'requiredDimensions から kind を 1 つ消すと exact-fit が赤くなる',
    'M9' => '委譲の gateTestName を 1 文字変えると同定が赤くなる',
    'M10' => 'config/template.php の social_providers を空にすると委譲の生存確認が赤くなる',
    'M11' => 'ExternalSeamScanner に Aws\\ を足すと委譲済み種別の混入検査が赤くなる',
    'M12' => 'entry の classification を Exempt にすると免除語彙未整備で赤くなる',
];

const EXTERNAL_SEAM_MUTATION_IDS = ['M1','M2','M3','M4','M5','M6','M7','M8','M9','M10','M11','M12'];

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

| # | テスト名 | 検証内容 |
|---|---------|---------|
| 1 | `外部到達: 走査で検出したクラスは目録と対称差ゼロ` | 走査で得たクラス集合と `entries()` のクラス集合が完全一致（missing / stale の両方向を落とす。診断に `describe()` を並べる） |
| 2 | `外部到達: 走査母集団が空でない` | `adopted` が非空 かつ `entries()` が非空（走査条件破壊で緑にならない） |
| 3 | `外部到達: site は名前付きクラス本体へ帰属する` | `scopeKind !== NamedClass` または `class === null` の site が 0 件（匿名クラス / ファイルスコープの抜け道封じ） |
| 4 | `外部到達: 規則ごとに名乗ってよい種別が固定される` | 各 site の規則に対し、そのクラスの登録 `kind` が `EXTERNAL_SEAM_RULE_KINDS` に含まれる。加えて表のキー集合が `ExternalSeamRule::cases()` と exact-fit |
| 5 | `外部到達: 各 entry の根拠は 30 文字以上` | `mb_strlen($entry->rationale) >= 30` |
| 6 | `外部到達: 決済の抑制 site は 0 件` | `suppressed === []`。失敗時は `describe()` でパス・行・呼び出し位置を出す |
| 7 | `外部到達: SocialLogin は SocialAuthController 1 クラスに固定される` | `kind === SocialLogin` の entry が `[socialLoginFunnel()]` と完全一致。かつ走査で `socialite_facade_reference` を出すクラス集合も同一 |
| 8 | `外部到達: 免除分類は語彙が未整備のため使用できない` | `classification === Exempt` の entry が 0 件。失敗メッセージに「ExternalSeamExemption enum + 免除前提表 + 30 文字根拠検査 + 空振り防止をセットで新設すること」を書く |
| 9 | `外部到達: 種別 × 次元の必須表は enum 全 case を覆う` | `requiredDimensions()` のキー集合が `ExternalSeamKind::cases()` の value 集合と完全一致。各値が非空 |
| 10 | `外部到達: 種別 × 次元は目録か委譲のどちらかで覆われる` | 必須表の全 (kind, dimension) 対が、目録に 1 件以上（`CodeReachPoint` のみ）か `delegations()` に載る |
| 11 | `外部到達: 委譲先の母集団が生きている` | 各 `delegation->livenessProbe` を**実行**して非空。加えて合成ソースの positive control（下記）で検出器が生きていることを確認 |
| 12 | `外部到達: 委譲先 gate のファイルと test 名が実在する` | `base_path($gateFile)` が存在し、そのソースが `$gateTestName` を**完全一致文字列**として含む |
| 13 | `外部到達: 委譲した種別は本目録の母集団に現れない` | `entries()` に `ObjectStorage` / `Llm` の kind が 0 件。かつ走査結果に `Aws\` / `League\Flysystem\` / Prism 由来の site が 0 件（規則の混入検知） |
| 14 | `外部到達: 委譲の根拠は 30 文字以上` | `mb_strlen($delegation->rationale) >= 30` |
| 15 | `外部到達: mutation 被覆表のキー集合が想定 mutation ID と一致する` | `array_keys(EXTERNAL_SEAM_MUTATION_COVERAGE)` と `EXTERNAL_SEAM_MUTATION_IDS` が一致 |

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

- [x] バグ修正ではないため再現テストは不要。**先に gate を書いて赤を確認**してから目録を埋める（AGENTS.md 思考原則 5 テストファースト）。具体的には「目録を空にした状態で `外部到達: 走査で検出したクラスは目録と対称差ゼロ` が 12 クラスを列挙して赤くなる」ことを実測してから登録する
- [x] 既存テスト `tests/Architecture/ExternalClientTimeoutInventoryTest.php` / `PromptGuardrailTest.php` / `SocialProviderTrustPolicyTest.php` の更新: **なし**（S4 のクラス移設に伴う `use` 追加を除く）
- [x] 新規テスト: 上表の 15 本
- [x] 個別の `DatabaseTransactions` を使っていない（Architecture レーンは DB 不要）
- [x] Factory: 新モデルを追加しないため不要

#### mutation で赤化を確認する手順（実装時に必ず実施し、結果を worktree のコミットメッセージに残す）

各 mutation は**一時的にコードを壊して赤を確認し、必ず戻す**。`git stash` ではなく手編集 → `git checkout --` で戻す（誤って混入させないため）。

| ID | 壊し方 | 期待する赤 |
|----|--------|-----------|
| M1 | `ExternalSeamInventory::entries()` から `FxRateService` の entry を削除 | テスト 1（missing 側）+ テスト 10（`market_data` が覆われない） |
| M2 | `entries()` に実在しないクラス（例: `CashierStripeGateway` を 2 回）ではなく、走査に出ない `App\Models\User` を追加 | テスト 1（stale 側） |
| M3 | `app/Services/Captcha/RecaptchaVerifier.php` の `use Illuminate\Support\Facades\Http;` を消し `\Illuminate\Support\Facades\Http::asForm()` へ書き換え | **赤くならないのが正解**（FQN でも検出する）。検出漏れが無いことの確認 mutation |
| M4 | `ExternalSeamScanner::FACADE_RULES` を `[]` にする | テスト 2（母集団が激減して非空判定は残るが）+ テスト 1（missing/stale 両方向） |
| M5 | 適当な Service に `Socialite::driver('google')` を書く | テスト 7（名指し固定） |
| M6 | Cashier / Stripe を import しない Service に `$organization->stripe()` を書く | テスト 6（抑制 0 件） |
| M7 | `EXTERNAL_SEAM_RULE_KINDS['http_facade_reference']` から `MarketData` を削る | テスト 4 |
| M8 | `requiredDimensions()` から `Llm` を削る | テスト 9（exact-fit） |
| M9 | 委譲の `gateTestName` の末尾を 1 文字変える | テスト 12 |
| M10 | `config/template.php` の `social_providers` を `[]` にする | テスト 11（生存確認）+ 既存 `SocialProviderTrustPolicyTest` |
| M11 | `ExternalSeamScanner::FACADE_RULES` に `Aws\S3\S3Client` を足す | テスト 13（委譲済み種別の混入） |
| M12 | 任意の entry の `classification` を `Exempt` にする | テスト 8 |
| M13 | `ExternalSeamScanner::PAYMENT_CLIENT_CONSTRUCTION_EXACT` を `['Stripe\\']` の接頭辞判定へ変える | S6 の「`Stripe\HttpClient\CurlClient` の new を検出しない」が赤 |

### リスク

- テスト 12（test 名の文字列一致）は、委譲先の test 名を整形・改名しただけで赤くなる。これは**意図した摩擦**だが、実装者が理由を理解できるよう失敗メッセージに「委譲先の test 名を変えたら `ExternalSeamInventory::delegations()` の `gateTestName` も同時に更新する」と書く
- テスト 13 の「Prism 由来の site が 0 件」は、`ExternalSeamScanner` が Prism を走査しない以上つねに 0 件で自明。**自明な assert は空虚な緑**なので、実装では「`FACADE_RULES` / `PAYMENT_*` の定数に委譲済み名前空間（`Aws\` / `League\Flysystem\` / `Prism\`）の文字列が含まれないこと」を**定数の値**に対して検査する形にする（M11 で赤化することを確認する）

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
| 3 | `走査器: $organization->stripe() を payment_client_call として検出する` | 同一ファイルに `use Stripe\StripeClient;` があるケース → adopted |
| 4 | `走査器: 決済名前空間を持たないファイルの ->stripe() は抑制コレクションへ入る` | `suppressed` 1 件 / `adopted` 0 件（**抑制が捨てられないことの証明**） |
| 5 | `走査器: new Stripe\StripeClient を payment_client_construction として検出する` | adopted 1 件 |
| 6 | `走査器: Stripe\HttpClient\CurlClient の new は検出しない` | adopted 0 件（T126 の `stripe_global_setter` が正本。責務が交わらないことの証明） |
| 7 | `走査器: Stripe 例外クラスの import だけでは検出しない` | `GatewayFailureClassifier` を模した 14 個の `use Stripe\Exception\*;` → adopted 0 件（**偽陽性分離の主証拠**） |
| 8 | `走査器: Stripe 値オブジェクト (Price / StripeObject) の参照だけでは検出しない` | `StripePriceCatalogEntry` を模した型参照 → adopted 0 件 |
| 9 | `走査器: Socialite facade の静的呼び出しを検出する` | `Socialite::driver('google')` → `socialite_facade_reference` 1 件 |
| 10 | `走査器: Socialite Contracts の型参照は検出しない` | `use Laravel\Socialite\Contracts\User as SocialiteUser;` + 引数型 → adopted 0 件（`SocialAccountService` / `EmailTrustPolicy` 系 4 クラスが母集団に入らないことの証明） |
| 11 | `走査器: Http facade を検出する` | `Http::asForm()` / `Http::connectTimeout()` の 2 形とも検出 |
| 12 | `走査器: Mail / Notification facade を検出する` | `Mail::to()` / `Notification::route()` とも `mail_facade_reference` |
| 13 | `走査器: コメント・文字列リテラル中の目印を検出しない` | コメントの `Cashier::stripe()` と文字列の `'Socialite::driver'` → adopted 0 件 |
| 14 | `走査器: グループ use と alias を解決する` | `use Illuminate\Support\Facades\{Http, Mail};` / `use ... as Alias;` |
| 15 | `走査器: 同名別 namespace の facade を誤検出しない` | `App\Support\Http::get()` → adopted 0 件 |
| 16 | `走査器: 匿名クラス・ファイルスコープの site を scopeKind で区別する` | 匿名クラス内 / クラス外の `Http::get()` が `AnonymousClass` / `FileScope` として出る（S1 抽出後の scope 追跡の回帰） |
| 17 | `走査器: 文字列補間を含むメソッド本体でも scope 追跡が壊れない` | `"{$x}"` を含むメソッドの後の site が `NamedClass` 帰属（T126 実測バグの回帰） |

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

- try/finally で `config` と `$this->app['env']` を原値復元する（既存 3-2 / 3-3 と同じ作法）
- [x] 個別の `DatabaseTransactions` を使っていない（`RefreshDatabase` はグローバル適用。本テストは DB を使わないが Feature レーンの既定に従う）
- [x] テストデータは Factory（本テストはモデルを使わないため該当なし）

### リスク

- **定数リネームの取りこぼし**: `PAYMENT_FLAG` / `PAYMENT_ENVIRONMENTS` / `PAYMENT_FAKE_ENVIRONMENTS` / `registerPaymentFakes` の参照は実測で 3 ファイルのみ（`FakeExternalsServiceProvider` / `ExternalFakeWiringInventory` / `ExternalFakeWiringInvariantTest`）。取りこぼしは PHPStan が未定義定数として落とす
- **`testing` レーンでの挙動変化**: `TESTING_FAKE_EXTERNALS` は `.env.testing` に無く config 既定 false のため、既存 Feature テストの `app(RecaptchaVerifier::class)` の解決結果は不変。ただし 3-2 の dataset が `testing` 環境で fake を bind するケースを増やすため、**Architecture レーンで container が汚れないこと**（各 test case ごとに app が再構築される既存前提）に依存する。既存 5 binding と同じ構造なので新しい前提は増えない
- **`RecaptchaVerifierTestFake` は `final`**。`ExternalFakeBinding` は厳密クラス一致で判定するため継承の問題は起きない。ただし `RecaptchaVerifier` は `final` ではない（継承されている）ため、bind の abstract に具象クラスを使う既存パターン（`TakeObjectStorage`）と同型

---

## S8 運用契約の記録

### 変更箇所

- `docs/architecture.md`: 新設「## 外部到達点の目録 (標準形 v1)」（既存「### S3 到達境界と面分類」= 1021 行付近 の直後に置き、相互参照する）
- `AGENTS.md`: 「ドメイン固有規約」に 9 項目目を追加（現行 8 項）

### 波及変更

- `tests/js/architecture/verification-commands-doc-sync.test.ts`: **影響なし**（`VERIFICATION_COMMANDS` マーカーを触らない）
- `docs/app-integration-guide.md`: 相互参照を 1 行だけ追加（§7 の不変条件番号は**動かさない**。AGENTS.md の採番注意に従う）

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
     委譲先の assert の中身を弱める改変、次元そのものの数え落とし、文字列キーの
     container 解決だけの経路、vendor 内部から出る通信は検出できない。
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
4. **S5** の gate を先に書く（目録は空 = 12 クラスを列挙して赤）→ **S3** の目録を埋めて緑
5. **S7**（captcha 配線）→ 新規 Feature テストの負のコントロール（テスト 2）が先に緑であることを確認してからテスト 1 を通す
6. **S8**（ドキュメント）
7. 全 mutation（M1〜M13）を実施し、赤化を確認して戻す

## 検証コマンド

`composer test` / `composer phpstan` / `vendor/bin/pint --test` / `pnpm lint` / `pnpm typecheck` / `pnpm test` / `pnpm build`（フロント変更は無いが CI と同じ全 green を確認する）

## 実装モード

| 項目 | 内容 |
|------|------|
| 推奨モード | **standalone** |
| 判断根拠 | (1) `tests/Support/ExternalClientBoundaryScanner.php` の内部を抽出する S1 は、T126 gate という**既存の緑テストを回帰の証拠にする**必要があり、他施策の変更が混ざると「壊したのはどれか」が切り分けられない。(2) `FakeExternalsServiceProvider` / `ExternalFakeWiringInventory` は複数の Architecture gate（3-8 / 3-10 の集合一致）が同時に見ており、他タスクが同ファイルを触ると衝突が gate 赤として現れる。(3) `AGENTS.md` のドメイン固有規約への追記は項番を動かすため、並行タスクとの競合が起きやすい |
| 競合リスク | **中**。同時に走っている他 2 設計（`idempotency-concurrent-claim` / `queue-dispatch-atomicity`）が `AGENTS.md` ドメイン固有規約と `docs/architecture.md` を触る可能性がある。項番の衝突は**マージ時に手で解消**する（本設計は「9 項目目」を前提にするが、先にマージされた側があれば繰り下げる）。テストファイルは重ならない見込み |

## 保証しないもの（設計としての明記）

本設計が作るのは **検知 v1** であり、以下は**保証しない**（gate の冒頭・`docs/architecture.md`・`AGENTS.md` の 3 箇所に同じ内容を書く）。

1. **出口の遮断**。bug-hunt のブラウザが SSO ボタンから `accounts.google.com` へ遷移する現状は変わらない（独立 TODO `bughunt-sso-egress` へ分離）
2. **委譲先の assert の中身**。母集団の生存と test 名の同定までが結線の保証範囲
3. **`app/` の外**。`routes/` / `config/` に書かれた到達コードは走査しない（SSO の宛先集合のみ委譲で押さえる = SSO 固有の措置）
4. **次元そのものの数え落とし**。次元の定義は人手であり、第 3 の次元が生まれても沈黙する
5. **文字列キーの container 解決だけの経路**（型名も呼び出しも出さない形）
6. **vendor 内部から出る通信**（Cashier / Socialite の内部実装）
7. **他種別の宛先集合**（Stripe の API キーが指す account / SES の region / 為替 API の URL）
8. **`.env.bughunt.local`（git 管理外）の内容**。pin できるのは `.env.bughunt.local.example` まで
9. **決済の別 API 表面**。検出は「client の取得・構築」に限り、新しい静的 helper が増えたときは規則の追加が要る

---

## 関連する現行コード（抜粋・実読済み）

### tests/Support/ExternalClientBoundaryScanner.php （走査ループの核。S1 で抽出する箇所）

```php
public static function scan(string $relativePath, string $phpSource): array
{
    $tokens = PhpTokenScan::normalize($phpSource);
    $count = count($tokens);
    $namespace = '';
    /** @var array<string, string> $aliases short name (小文字) => FQCN */
    $aliases = [];
    $braceDepth = 0;
    $scopes = [];
    $pendingScope = null;
    $callables = [];
    $pendingCallable = null;
    $sites = [];

    for ($i = 0; $i < $count; $i++) {
        $token = $tokens[$i]; $id = $token['id']; $text = $token['text'];

        if ($id === T_NAMESPACE) { /* namespace 名を採る */ continue; }
        if ($id === T_USE) {
            $next = $tokens[$i + 1] ?? null;
            if ($next !== null && $next['text'] === '(') { continue; } // closure の use
            $i = self::collectUseStatement($tokens, $i, $aliases);
            continue;
        }
        if ($id === T_CLASS || $id === T_TRAIT || $id === T_INTERFACE || $id === T_ENUM) {
            $previous = $tokens[$i - 1] ?? null;
            if ($previous !== null && $previous['id'] === T_DOUBLE_COLON) { continue; } // Foo::class
            $next = $tokens[$i + 1] ?? null;
            $isNamed = $next !== null && $next['id'] === T_STRING;
            $pendingScope = ['kind' => $isNamed ? ScanScopeKind::NamedClass : ScanScopeKind::AnonymousClass,
                             'class' => $isNamed && $next !== null ? ($namespace === '' ? $next['text'] : $namespace.'\\'.$next['text']) : null];
            continue;
        }
        if ($id === T_FUNCTION) { /* pendingCallable を採る */ continue; }
        if ($id === T_CURLY_OPEN || $id === T_DOLLAR_OPEN_CURLY_BRACES) { $braceDepth++; continue; }
        if ($id === null && $text === '{') { $braceDepth++; /* pendingScope / pendingCallable を push */ continue; }
        if ($id === null && $text === '}') { /* bodyDepth 一致で pop */ $braceDepth--; continue; }
        if ($id === null && $text === ';') { $pendingCallable = null; $pendingScope = null; continue; }

        $scopeKind = $scopes === [] ? ScanScopeKind::FileScope : $scopes[count($scopes) - 1]['kind'];
        $scopeClass = $scopes === [] ? null : $scopes[count($scopes) - 1]['class'];
        $callableName = $callables === [] ? null : $callables[count($callables) - 1]['name'];

        // R2: 完全修飾 / 修飾名による参照
        if ($id === T_NAME_FULLY_QUALIFIED || $id === T_NAME_QUALIFIED) {
            if (self::isTargetName($text)) {
                $rule = ($tokens[$i - 1]['id'] ?? null) === T_NEW ? 'new_external_object' : 'fqn_reference';
                $sites[] = self::site($relativePath, $token['line'], $rule, ltrim($text, '\\'), $scopeKind, $scopeClass, $callableName, null);
            }
            continue;
        }
        if ($id !== T_STRING) { continue; }

        $previous = $tokens[$i - 1] ?? null;
        $previousId = $previous['id'] ?? null;
        $isMemberAccess = $previousId === T_OBJECT_OPERATOR || $previousId === T_NULLSAFE_OBJECT_OPERATOR;
        $isStaticAccess = $previousId === T_DOUBLE_COLON;
        $next = $tokens[$i + 1] ?? null;
        $isCall = $next !== null && $next['id'] === null && $next['text'] === '(';

        // R4: disk() 呼び出し (receiver を問わない)
        if ($text === 'disk' && ($isMemberAccess || $isStaticAccess) && $isCall) {
            $sites[] = self::site(..., 'disk_call', 'disk', ..., self::classifyCallArgument($tokens, $i + 1));
            continue;
        }
        // R5: getClient()
        if ($text === 'getClient' && ($isMemberAccess || $isStaticAccess) && $isCall) {
            $sites[] = self::site(..., 'get_client_call', 'getClient', ..., null);
            continue;
        }
        // R6: Stripe のプロセス大域 setter
        if (in_array($text, self::STRIPE_GLOBAL_SYMBOLS, true) && $isStaticAccess && $isCall) {
            $receiver = $tokens[$i - 2] ?? null;
            $receiverName = $receiver === null ? null : self::resolveName($receiver, $aliases);
            if ($receiverName !== null && str_starts_with($receiverName, 'Stripe\\')) {
                $sites[] = self::site(..., 'stripe_global_setter', $text, ..., null);
                continue;
            }
        }
        // R3: import 済み short name による参照
        if ($isMemberAccess || $isStaticAccess) { continue; }
        if ($previousId === T_FUNCTION || $previousId === T_CONST || $previousId === T_CLASS
            || $previousId === T_INTERFACE || $previousId === T_TRAIT || $previousId === T_ENUM
            || $previousId === T_AS || $previousId === T_GOTO) { continue; }
        $resolved = $aliases[mb_strtolower($text)] ?? null;
        if ($resolved !== null && self::isTargetName($resolved)) {
            $rule = $previousId === T_NEW ? 'new_external_object' : 'imported_name_reference';
            $sites[] = self::site(..., $rule, $resolved, ..., null);
        }
    }

    return self::dropOrphanGetClientSites($sites);
}
```

### app/Services/Captcha/RecaptchaVerifier.php （抜粋）

```php
class RecaptchaVerifier
{
    private const VERIFY_URL = 'https://www.google.com/recaptcha/api/siteverify';

    public function verify(?string $token, ?string $ip): bool
    {
        if ($token === null || $token === '') { return false; }

        $secret = config('services.recaptcha.secret_key');
        if (! is_string($secret) || $secret === '') {
            $allowed = ! app()->environment('production');
            $this->reportUnavailable('missing_secret', $allowed);
            return $allowed;                       // ← secret 未設定なら HTTP を出さずに返る
        }

        try {
            $response = Http::asForm()->timeout(5)->post(self::VERIFY_URL, array_filter([...]));
        } catch (ConnectionException) { $this->reportUnavailable('transport', allowed: true); return true; }
        // …
    }
}
```

### app/Http/Controllers/Auth/SocialAuthController.php （抜粋。Socialite 直呼び 2 箇所）

```php
use Laravel\Socialite\Facades\Socialite;

public function redirect(Request $request, string $provider, string $intent): RedirectResponse|SymfonyRedirectResponse
{
    // …
    $driver = Socialite::driver($provider);                    // L65
    if ($intent === 'step-up' && method_exists($driver, 'with')) { $driver->with(['prompt' => 'login']); }
    return $driver->redirect();
}

public function callback(Request $request, string $provider, SocialAccountService $service, RecentAuthState $recentAuthState): RedirectResponse
{
    // …
    $socialiteUser = Socialite::driver($provider)->user();     // L88
    // …
}
```

### tests/Architecture/SocialProviderTrustPolicyTest.php （委譲先。deny-by-default の実体）

```php
function socialProvidersConfig(): array { return config()->array('template.social_providers'); }

test('全 SSO provider が capability / email_trust を明示宣言している', function (): void {
    $providers = socialProvidersConfig();
    expect($providers)->not->toBeEmpty('social_providers が空 (config の読み込み経路が壊れている?)');
    foreach ($providers as $provider => $definition) {
        expect($definition)->toBeArray(...);
        expect(array_key_exists('capability', $definition))->toBeTrue(...);
        expect(is_string($capability) ? ProviderCapability::tryFrom($capability) : null)->not->toBeNull(...);
        expect(array_key_exists('email_trust', $definition))->toBeTrue(...);
        expect(is_string($emailTrust) ? EmailTrustLevel::tryFrom($emailTrust) : null)->not->toBeNull(...);
    }
});
```

### tests/Architecture/ExternalFakeWiringInvariantTest.php （3-8 / 3-10 = 集合一致。dataset は inventory 駆動）

```php
dataset('external fake bindings', function (): Generator {
    foreach (ExternalFakeWiringInventory::bindings() as $binding) { yield $binding->label() => [$binding]; }
});

test('3-1 対照: flag off では real 実装が厳密一致で解決される', function (ExternalFakeBinding $binding): void {
    expect(config($binding->flag))->toBeFalse();
    expect(app($binding->abstract)::class)->toBe($binding->real);
})->with('external fake bindings');

test('3-8 網羅性: provider の bind 組が inventory と集合一致する', function (): void {
    $pairs = FakeWiringSourceScanner::bindPairs(externalFakeWiringProviderSource());
    expect(array_filter($pairs, static fn (array $pair): bool => $pair['concrete'] === null))->toBe([]);
    $actual = array_map(static fn (array $p): string => $p['abstract'].' => '.$p['concrete'], $pairs);
    $expected = array_map(static fn (ExternalFakeBinding $b): string => $b->abstract.' => '.$b->fake, ExternalFakeWiringInventory::bindings());
    sort($actual); sort($expected);
    expect($actual)->toBe($expected);
});

test('3-10 網羅性: provider が参照する fake 系クラスは inventory + 明示例外に一致する', function (): void { /* 集合一致 */ });
```

### app/Http/Requests/StoreInquiryRequest.php （captcha の container 解決点）

```php
'g-recaptcha-response' => ['required', 'string', new Recaptcha(app(RecaptchaVerifier::class), $this->ip())],
```
