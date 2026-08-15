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
5. LLM 呼び出しの Prism 直呼び(`app/Prompts/` の factory 経由のみ。PromptGuardrailTest が検出)。
   **実行経路を持つ prompt factory は `LlmCallContextData` を必須引数で受け、
   `->withMetadata($context->toMetadata())` で帰属 (organization / subject) を付ける** — 付け忘れは
   PHPStan level 10 が落とす。帰属の対象を持たない見本 (`ExampleSummaryPrompt`) は
   `PromptUntrustedInputContractTest` の inventory へ**帰属キーを空配列で exempt 登録**する
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

---

あなたは経験豊富なWebアプリケーションアーキテクトです。Laravel + Svelte アプリケーション改善の詳細設計をレビューしてください。

【前提環境】
- PHP 8.4 + Laravel 12 + Svelte 5 + Inertia.js + TypeScript
- PHPStan level 10
- Pestテストフレームワーク
- DTO + JsonResource パターン
- Laratrust RBAC（Organization → Team → Project階層）

【本件固有の前提】
- 本件はテスト専用コード (Architecture テストと走査器) の追加であり、HTTP 境界・DB・UI を一切変更しない
- 複数リポジトリで共有される機能台帳の feature の移植で、採否は裁定済み
- 構文解析ライブラリ (nikic/php-parser) は持たず、字句 (token_get_all) ベースであることが家系の裁定で許容されている
- 既存の同種 gate として tests/Architecture/NoNonCompoundGlobalUseTest.php と
  tests/Architecture/StrayHttpEgressLaneGateTest.php があり、本設計はその作法に揃えている

【レビュー観点】
1. コードの正確性（ロジックエラー、エッジケース、null安全性）
2. 既存コードとの整合性（命名規約、パターン、API）
3. PHPStan level 10 適合性（型安全性、generics、Assert使用）
4. テスト計画の網羅性（各施策にPestテスト）
5. 副作用・後退リスク
6. 波及変更の網羅性
7. セキュリティ（AGENTS.md のセキュリティ不変条件）
8. 検査の穴（読み飛ばし規則が検出力を落としていないか、走査根の分類が fail-open にならないか）

【出力形式】
- 各施策ごとに判定: APPROVE / REQUEST_CHANGES
- 指摘は [Critical] [Warning] [Suggestion] で分類
- Critical/Warning には必ず修正案を添える
- 全体判定: APPROVED / CHANGES_REQUESTED
- 日本語で出力

---

## 詳細設計書

# 詳細設計: forbidden-statement-token-gate (禁止する文の字句走査による検出)

## 使命・制約 (絶対遵守)

### アプリの使命 (North Star)

**AI-CUE** は、現場に既にある**作業手順書(SOP)を起点に**、AI が撮るべきカットを設計した**動画シナリオ**を生成し、そのシナリオを**スマホ(PWA)でナビゲーション撮影**することで、専門知識ゼロの現場作業者でも**標準化されたマニュアル動画**を作れるようにする。

- 「思考ゼロ・編集ゼロ」— 台本作成・撮影判断・編集の 3 ハードルを AI とナビ撮影で肩代わりする。
- 競合(OJT を撮って形式化する tebiki)と異なり、**標準作業を起点に AI が教材設計し撮影を指示する**(撮影者・教える人のスキルに品質を依存させない)。
- 熟練者の暗黙知を動画マニュアルという形式知へ変換する装置(SECI)。

### 禁止事項

1. テストなしの実装完了報告(不変条件は対応する Architecture/Feature テストへの登録まで含めて「実装済み」)
2. PHPStan エラーの widen(型を緩めて黙らせる)・baseline 化
3. dev DB への破壊操作(`migrate:fresh` 等)をエージェント判断で実行すること
4. `response()->json()` の直書き(DTO / JsonResource / Inertia を使う。仕様固定 endpoint のみ例外)
5. LLM 呼び出しの Prism 直呼び(`app/Prompts/` の factory 経由のみ)
6. prompt 文字列のコード直書き(`resources/prompts/*.yaml` に置く)
7. 操作系 POST の応答での `redirect()->intended()`
8. 必須条件未充足を理由にボタンを disabled にする UI
9. Artifact の使用(成果物はリポジトリ内のファイルとして出力する)

### コーディングルール

- **PHPStan level 10** 必須(`composer phpstan`)
- **Pest** テストフレームワーク(`composer test`)
- **RefreshDatabase** + `--parallel` 並列実行(`tests/Pest.php` でグローバル適用、
  個別 `DatabaseTransactions` 使用禁止)
- `declare(strict_types=1)` + 日本語コメント
- **コードフォーマット**: `composer fix`(Pint)
- PHP 8.4 + Laravel 12

## 概念設計リファレンス

`devnotes/20260815-1537-forbidden-statement-token-gate/conceptual-design.md` (APPROVED / Round 2)

## 施策一覧

| # | 施策名 | 変更ファイル | 優先度 |
|---|--------|------------|--------|
| S1 | 禁止語彙と出現位置の型 | `tests/Support/ForbiddenStatement/ForbiddenStatementKind.php` (新規)<br>`tests/Support/ForbiddenStatement/ForbiddenStatementSite.php` (新規) | 高 |
| S2 | 字句走査器 | `tests/Support/ForbiddenStatement/ForbiddenStatementScanner.php` (新規) | 高 |
| S3 | 走査器の自己検査 (正例 / 負の対照) | `tests/Unit/Architecture/ForbiddenStatementScannerTest.php` (新規) | 高 |
| S4 | 走査根の分類と例外の型 | `tests/Support/ForbiddenStatement/ForbiddenStatementRootPolicy.php` (新規)<br>`tests/Support/ForbiddenStatement/ForbiddenStatementExemption.php` (新規) | 高 |
| S5 | gate 本体 | `tests/Architecture/ForbiddenStatementTokenInvariantTest.php` (新規) | 高 |
| S6 | 規約の成文化 | `AGENTS.md` (変更) | 中 |

**`app/` `resources/` `routes/` `database/` `config/` `bootstrap/` は 1 行も変更しない。**
実測で違反が 0 件であり、唯一の違反 (`scripts/ci/drop-test-db.php` の `echo` 23 件) は
書き換えず例外として登録するため。

---

## S1: 禁止語彙と出現位置の型

### 変更箇所

- 新規: `tests/Support/ForbiddenStatement/ForbiddenStatementKind.php`
- 新規: `tests/Support/ForbiddenStatement/ForbiddenStatementSite.php`

### 波及変更

- TypeScript 型定義: なし (テスト専用コード。HTTP 境界を一切変えない)
- API Resource/DTO: なし
- テストファイル: S3 / S5 が利用する (同一 PR 内で新規作成)

### 変更後コード

```php
<?php

declare(strict_types=1);

namespace Tests\Support\ForbiddenStatement;

/**
 * 字句 (トークン) として禁止する文の語彙。
 *
 * ★正典 (lctl feature: forbidden-statement-token-gate) の v1 が定める 3 つ
 *   (`echo` / `goto` / `global`) に、テンプレート実装が唯一の拡張として持つ
 *   開始タグ付きの出力記法 (`<?=`) を加えた **4 つに限る**。
 * ★`print` は正典が明示的に対象外としており、**禁止語彙の拡張は台帳の議題として
 *   起こす決まり**になっている。ここで勝手に足さない。
 * ★case 名に半予約語 (`Echo` 等) を使わないのは意図的である。
 *   本 enum 自身が走査対象 (`tests/`) に置かれるため、case 名を `Echo` にすると
 *   本ファイルが `T_ECHO` を含むことになり、読み飛ばし規則に依存して緑になる。
 *   検査の正しさを検査対象自身の書き方に依存させない。
 */
enum ForbiddenStatementKind: string
{
    /** `echo "x";` — 応答の組み立て経路を迂回して直接出力へ書き出す。 */
    case EchoStatement = 'echo';

    /** `<?= $x ?>` — 開始タグ付きの出力記法。上と同じことを別の綴りで行う。 */
    case ShortEchoTag = 'short_echo_tag';

    /** `goto label;` — 任意の位置へ飛び、構造から制御フローが読めなくなる。 */
    case GotoStatement = 'goto';

    /** `global $x;` — DI コンテナ経由の依存解決を迂回し、差し替えられない結合を作る。 */
    case GlobalStatement = 'global';

    /**
     * トークン ID から語彙を引く (該当しなければ null)。
     *
     * ★**網羅 `match` で書き、到達不能な分岐を作らない**。
     *   写像が全 case を覆っていることは自己検査 (S3) が固定する。
     */
    public static function fromTokenId(?int $tokenId): ?self
    {
        return match ($tokenId) {
            T_ECHO => self::EchoStatement,
            T_OPEN_TAG_WITH_ECHO => self::ShortEchoTag,
            T_GOTO => self::GotoStatement,
            T_GLOBAL => self::GlobalStatement,
            default => null,
        };
    }

    /** 読み飛ばし規則の適用対象か (開始タグ付き出力記法は文脈を持たないので対象外)。 */
    public function needsContextCheck(): bool
    {
        return $this !== self::ShortEchoTag;
    }

    /** 失敗メッセージ用の表示名。 */
    public function label(): string
    {
        return match ($this) {
            self::EchoStatement => 'echo 文',
            self::ShortEchoTag => '開始タグ付きの出力記法 (<?=)',
            self::GotoStatement => 'goto 文',
            self::GlobalStatement => 'global 文',
        };
    }
}
```

```php
<?php

declare(strict_types=1);

namespace Tests\Support\ForbiddenStatement;

/**
 * 禁止する文が 1 つ見つかった位置 (走査器に依存しない中立表現)。
 *
 * ★既存の `Tests\Support\ReferenceSite` と同じ作法 (readonly の値オブジェクト)。
 */
final readonly class ForbiddenStatementSite
{
    public function __construct(
        /** リポジトリルートからの相対パス */
        public string $path,
        /** 1 起点の行番号 */
        public int $line,
        public ForbiddenStatementKind $kind,
    ) {}

    /** 失敗メッセージ用の 1 行表現。 */
    public function describe(): string
    {
        return "{$this->path}:{$this->line} → {$this->kind->label()}";
    }
}
```

### PHPStan 適合チェック

- [x] 戻り値の型が明示されている (`?self` / `bool` / `string`)
- [x] null 安全 (`fromTokenId(?int)` は null を受けて null を返す)
- [x] 配列返却なし (値オブジェクト)
- [x] Generics なし

### テスト計画

- [x] 新規テスト (S3 内): `ForbiddenStatementKind::fromTokenId()` が
      4 つのトークン ID を過不足なく写し、それ以外で `null` を返すこと
- [x] 新規テスト (S3 内): `ForbiddenStatementKind::cases()` が 4 件ちょうどであること
      (語彙を勝手に増やしたら赤くなる = 台帳の議題を経ずに拡張できない)
- [x] 個別の `DatabaseTransactions` を使っていないことを確認

### リスク

- 語彙が 4 件固定であるため、将来 `print` を足したくなったときに赤くなる。
  **これは意図した摩擦である** (拡張は台帳の議題が先)。

---

## S2: 字句走査器

### 変更箇所

- 新規: `tests/Support/ForbiddenStatement/ForbiddenStatementScanner.php`

### 波及変更

- TypeScript 型定義: なし / API Resource・DTO: なし
- テストファイル: S3 (自己検査) と S5 (gate) が利用する

### 設計の核: なぜ読み飛ばし規則が要るのか (実測)

PHP の字句解析器は、**文でない位置に書かれた同じ綴りにも同じトークン種別を割り当てる**。
本設計のために HEAD の PHP で実測した結果:

| 書き方 (構文として成立する完全な断片) | トークン | 直前の有意トークン | 判定 |
|---|---|---|---|
| `echo "x";` | `T_ECHO` | `T_OPEN_TAG` | **違反** |
| `global $x;` | `T_GLOBAL` | `;` | **違反** |
| `goto end; end: echo 1;` | `T_GOTO` | `T_OPEN_TAG` | **違反** |
| `<?= $x ?>` | `T_OPEN_TAG_WITH_ECHO` | (なし) | **違反** |
| `Foo::goto();` | `T_GOTO` | `T_DOUBLE_COLON` | 違反でない |
| `$f = Foo::echo(...);` | `T_ECHO` | `T_DOUBLE_COLON` | 違反でない |
| `class Foo { public function echo(): void {} }` | `T_ECHO` | `T_FUNCTION` | 違反でない |
| `class Foo { const echo = 1; const ECHO = 2; }` | `T_ECHO` | `T_CONST` | 違反でない |
| `enum E: string { case Echo = 'e'; }` | `T_ECHO` | `T_CASE` | 違反でない |
| `switch ($x) { case ECHO: break; }` | `T_ECHO` | `T_CASE` | 違反でない |
| `#[Echo] class Foo {}` | `T_ECHO` | `T_ATTRIBUTE` | 違反でない |
| `f(global: 2, goto: 3);` | `T_GLOBAL` / `T_GOTO` | `,` (直後が `:`) | 違反でない |
| `$o->echo(); $o?->global();` | **`T_STRING`** | — | そもそも対象外 |
| コメント / DocComment / 文字列リテラル中の綴り | (除去済み) | — | そもそも対象外 |

### 読み飛ばし規則が gate に穴を開けない理由

読み飛ばしは 2 つだけである。

1. **直前の有意トークンが `::` / `function` / `const` / `case` / `#[` のいずれか**
2. **直後の有意トークンが単独の `:`** (名前つき引数)

いずれも「**本物の文がその位置に立てない**」ことが PHP の文法から言える。
`::` / `function` / `const` / `case` / `#[` の直後に文は書けず、`echo :` という並びも
文としては成立しない。したがってこの 2 規則は誤検出だけを取り除き、
検出力を落とさない。**規則が効いていること自体は S3 の負の対照が固定する。**

なお `->` / `?->` は読み飛ばし規則に**入れない**。実測でこの位置の綴りは
`T_STRING` になり、そもそも語彙に一致しないためである
(不要な規則を置くと、なぜ置いたのか誰も説明できなくなる)。

### 変更後コード

```php
<?php

declare(strict_types=1);

namespace Tests\Support\ForbiddenStatement;

use Tests\Support\PhpTokenScan;

/**
 * PHP ソースから「禁止する文」の出現位置を列挙する純関数。
 *
 * ★走査は既存の `Tests\Support\PhpTokenScan::normalize()`
 *   (空白 / コメント / DocComment を除いた添字連番のリスト) の上で行う。
 *   **同じ正規化を 2 本持たない**。
 * ★**何を禁止と呼ぶかは `ForbiddenStatementKind` が持ち、どこを走査するかは
 *   gate が持つ**。この走査器はどちらも知らない。
 *
 * ★**保証しないもの (誇張しない)**:
 *   - 名前の解決が要る出力 (`printf` / `var_dump` / `fwrite(STDOUT, …)` /
 *     `$out = 'echo'; $out(…)`) には**沈黙する**。この検査は完全性を主張しない
 *   - Blade の `@php … @endphp` と `{{ }}` の中は `token_get_all()` からは
 *     地の文 (`T_INLINE_HTML`) に見えるため届かない。
 *     **PHP 開始タグで開いた区間 (`<?= …` / `<?php …`) は見える** (実測)
 *   - ヒアドキュメント / ナウドキュメントの本文は 1 つの
 *     `T_ENCAPSED_AND_WHITESPACE` になり、中の綴りは見えない (実測)。
 *     これは本走査器の自己検査ファイルが自分自身を違反にしない理由でもある
 */
final class ForbiddenStatementScanner
{
    /**
     * 直前の有意トークンがこれらなら、名前として使われているので違反にしない。
     *
     * ★根拠は S2 の実測表。いずれも「本物の文がその位置に立てない」位置なので、
     *   読み飛ばしても検出力は落ちない。
     */
    private const array NAME_POSITION_PREDECESSORS = [
        T_DOUBLE_COLON,   // Foo::goto() / Foo::echo(...) / トレイトの別名指定
        T_FUNCTION,       // class Foo { public function echo(): void {} }
        T_CONST,          // class Foo { const echo = 1; }
        T_CASE,           // enum E { case Echo = 'e'; } / switch ($x) { case ECHO: }
        T_ATTRIBUTE,      // #[Echo] class Foo {}
    ];

    /**
     * @return list<ForbiddenStatementSite>
     */
    public static function sites(string $relativePath, string $phpSource): array
    {
        $tokens = PhpTokenScan::normalize($phpSource);
        $count = count($tokens);

        $sites = [];
        for ($i = 0; $i < $count; $i++) {
            $kind = ForbiddenStatementKind::fromTokenId($tokens[$i]['id']);
            if ($kind === null) {
                continue;
            }

            if ($kind->needsContextCheck() && self::isNamePosition($tokens, $i)) {
                continue;
            }

            $sites[] = new ForbiddenStatementSite($relativePath, $tokens[$i]['line'], $kind);
        }

        return $sites;
    }

    /**
     * 綴りが「文」ではなく「名前」として置かれている位置か。
     *
     * @param  list<array{id: int|null, text: string, line: int}>  $tokens
     */
    private static function isNamePosition(array $tokens, int $index): bool
    {
        $previous = $tokens[$index - 1] ?? null;
        if ($previous !== null && in_array($previous['id'], self::NAME_POSITION_PREDECESSORS, true)) {
            return true;
        }

        // 名前つき引数 (`f(global: 2)`) は直後が単独の `:` になる。
        // `::` は 1 つの `T_DOUBLE_COLON` トークンなので、ここには一致しない。
        $next = $tokens[$index + 1] ?? null;

        return $next !== null && $next['id'] === null && $next['text'] === ':';
    }
}
```

### PHPStan 適合チェック

- [x] 戻り値の型が明示されている (`list<ForbiddenStatementSite>` / `bool`)
- [x] null 安全 (`?? null` + `!== null` で全アクセスを守る)
- [x] DTO を返している (配列の生返しなし)
- [x] Generics の型パラメータ (`list<...>` の phpdoc) が正しい

### テスト計画

S3 が正負両方向を固定する (下記)。

### リスク

- `PhpTokenScan::normalize()` の挙動変更に依存する。
  ただし同 class は既存 gate 2 本が依存している共有基盤で、
  変更すればそちらも同時に落ちる (単独で腐らない)。

---

## S3: 走査器の自己検査 (正例 / 負の対照)

### 変更箇所

- 新規: `tests/Unit/Architecture/ForbiddenStatementScannerTest.php`

置き場所は既存の走査器自己検査 7 本 (`tests/Unit/Architecture/`) に合わせる。
負例は見本ファイルではなく**テスト内のナウドキュメント文字列**として持つ
(既存 7 本と同じ作法。ナウドキュメント本文は走査から見えないことを実測済み)。

### 波及変更

- TypeScript 型定義 / API Resource・DTO: なし
- テストファイル: 新規のみ

### テスト一覧 (すべて Pest の `test()`)

**正例 — 検出できること (これが無いと「空振りを緑にしている」ことになる)**

| # | 名前 | 検体 | 期待 |
|---|------|------|------|
| P1 | 4 つの語彙をそれぞれ単独で検出する | `<?php echo "x";` / `<?php goto end; end: $x = 1;` / `<?php global $x;` / `<?= $x ?>` | 各 1 件、`kind` が一致 |
| P2 | 1 つの断片に複数あればすべて検出する | `echo` 2 件 + `global` 1 件 | 3 件 |
| P3 | 大文字小文字を区別しない | `<?php ECHO "x"; GLOBAL $y;` | 2 件 |
| P4 | ファイル先頭の開始タグ付き出力記法を検出する | `<?= $x ?>` (1 行目) | 1 件・行番号 1 |
| P5 | 行番号が正しい | 3 行目に `echo` | `line === 3` |
| P6 | Blade 風の断片でも開始タグ区間は検出する | `@section(...)` … `<?= $x ?>` … `<?php echo "y"; ?>` … `@endsection` | 2 件 |

**負の対照 — 検出してはいけないこと (誤検出の回避)**

| # | 名前 | 検体 (構文として成立する完全な断片) |
|---|------|-----------------------------------|
| N1 | 静的呼び出し / 定数取得 / 第一級呼び出し可能 | `<?php Foo::goto(); $c = Foo::echo; $f = Foo::echo(...);` |
| N2 | メソッド宣言 | `<?php class Foo { public function echo(): void {} public function global(): void {} }` |
| N3 | クラス定数 | `<?php class Foo { const echo = 1; const ECHO = 2; }` |
| N4 | 列挙の場合分け | `<?php enum E: string { case Echo = 'e'; case Global = 'g'; }` |
| N5 | switch の場合分け | `<?php const ECHO = 1; switch ($x) { case ECHO: break; }` |
| N6 | 属性 | `<?php #[Echo] class Foo {}` |
| N7 | 名前つき引数 | `<?php f(global: 2, goto: 3, echo: 4);` |
| N8 | オブジェクトのメソッド呼び出し | `<?php $o->echo(); $o?->global();` |
| N9 | コメント / DocComment / 文字列リテラル | `<?php // echo "x";` + `/** goto */` + `$s = "echo global goto";` |
| N10 | ナウドキュメント / ヒアドキュメントの本文 | 本文に `echo "x"; global $y;` を含むナウドキュメント |
| N11 | 名前としての綴りだけの断片は 0 件 | N1〜N10 を 1 つに連結した断片 |

**写像の網羅**

| # | 名前 | 検証 |
|---|------|------|
| M1 | 語彙は 4 件ちょうどである | `ForbiddenStatementKind::cases()` が 4 件 |
| M2 | トークン ID の写像が全 case を覆う | 各 case について `fromTokenId()` が往復する。`T_STRING` / `T_PRINT` / `null` は `null` を返す |

### N10 が持つ意味 (実装時に必ず読むこと)

N10 は「検体をナウドキュメントで書いている**この自己検査ファイル自身**が、
gate (S5) の走査対象 `tests/` に置かれていても違反にならない」ことの根拠である。
実測では、ナウドキュメント本文は `T_START_HEREDOC` /
`T_ENCAPSED_AND_WHITESPACE` / `T_END_HEREDOC` になり、
本文中の `echo` に `T_ECHO` は割り当てられない。
**したがってこのファイルを例外へ登録する必要はない。**
逆に言えば、検体を「実行される PHP コード」として書くと自分が違反になるので、
検体は必ずナウドキュメント文字列で書くこと。

### PHPStan 適合チェック

- [x] `expect()` に渡す値の型が明示されている
- [x] 配列 shape に依存せず `ForbiddenStatementSite` の property を読む
- [x] Assert / null 安全

### テスト計画

本施策そのものがテストである。green の条件は上表 19 本すべて。

### リスク

- 検体が構文として不正だと `token_get_all()` の挙動が実コードと乖離する。
  **実装時は各検体を `php -l` 相当で 1 度確認してからテストへ埋めること**
  (概念設計レビュー Round 1 の指摘)。

---

## S4: 走査根の分類と例外の型

### 変更箇所

- 新規: `tests/Support/ForbiddenStatement/ForbiddenStatementRootPolicy.php`
- 新規: `tests/Support/ForbiddenStatement/ForbiddenStatementExemption.php`

### 走査根の分類 (実測に基づく)

`git ls-files '*.php'` の 1567 ファイルは、**実ディレクトリ 11 個**に分布する
(リポジトリ直下の PHP は現在 0 件だが、置かれうるので疑似 root として 1 件数える)。

| 置き場所 | 分類 | 追跡 PHP 数 | 備考 |
|---|---|---|---|
| (リポジトリ直下) | 走査する / 例外不可 | 0 | 疑似 root |
| `app` | 走査する / 例外不可 | 760 | |
| `bootstrap` | 走査する / 例外不可 | 2 | `bootstrap/cache` は git 管理外 |
| `config` | 走査する / 例外不可 | 38 | |
| `database` | 走査する / 例外不可 | 114 | |
| `lang` | 走査する / 例外不可 | 5 | |
| `public` | 走査する / 例外不可 | 1 | |
| `resources` | 走査する / 例外不可 | 24 | すべて Blade。開始タグ区間は見える |
| `routes` | 走査する / 例外不可 | 4 | |
| `scripts` | 走査する / **例外可** | 3 | |
| `tests` | 走査する / **例外可** | 601 | |
| `devnotes` | **除外** (理由必須) | 15 | |

走査対象の実数 = 1567 − 15 = **1552 ファイル**。

正典 (テンプレート) の 10 root との差は 3 点で、いずれも根拠がある:
`deploy` は本リポジトリに存在しない / `resources` を加える (Blade の開始タグ区間が
見えるため。除外すると `<?=` 禁止に穴が残る) / 分類漏れを赤にする。
**同じ不変条件をより広い範囲に適用するだけで不変条件を緩めていない**ため、
`docs/template-divergence.md` への登録は不要と判断する
(同ドキュメントの判定軸 =「同じ不変条件を同じタイミング / 抽象度で保証するか」)。

### 変更後コード

```php
<?php

declare(strict_types=1);

namespace Tests\Support\ForbiddenStatement;

/**
 * 追跡されている PHP の置き場所を、禁止する文の検査に対してどう扱うかの分類。
 *
 * ★**3 つは排他**であり、どれにも分類していない置き場所が現れたら gate は赤になる。
 *   走査根を検査ファイルへ列挙するだけにすると、新しいディレクトリを足したときに
 *   **黙って走査対象から外れる** (家系の台帳が fail-open の一種として記録している事故の型)。
 */
enum ForbiddenStatementRootPolicy: string
{
    /** 走査する。例外の登録を一切許さない (アプリの実行経路そのもの)。 */
    case ScannedNoExemption = 'scanned_no_exemption';

    /** 走査する。理由付きの例外登録を許す (別プロセスで走る CLI と検体)。 */
    case ScannedWithExemption = 'scanned_with_exemption';

    /** 走査しない。理由の記載が必須。 */
    case Excluded = 'excluded';
}
```

```php
<?php

declare(strict_types=1);

namespace Tests\Support\ForbiddenStatement;

/**
 * 「禁止する文をそこに書くことが正しい」と裁定した理由の分類。
 *
 * `tests/Architecture/ForbiddenStatementTokenInvariantTest.php` が deny-by-default で
 * 「禁止する文を持つファイルは本 enum + 30 文字以上の具体的根拠 + 件数付きで
 *  inventory に登録済みであること」を機械強制する。
 *
 * ★case は「汎用に見えるものほど適用条件を狭く」定義する。
 *   当てはまる case が無ければ、それは「書いてはいけない箇所」である。
 * ★case を 1 つしか持たないのは意図的 (今必要なものだけ作る)。
 *   2 つ目が現れたときに「新しい case を足す差分」として必ず表面化し、
 *   その場で「そもそも書くべきか」を再検討させるのが狙い
 *   (`Tests\Support\Security\StrayHttpEgressExemption` と同じ作法)。
 */
enum ForbiddenStatementExemption: string
{
    /**
     * artisan を通さない素の PHP として起動される CLI の、人間向け標準出力。
     *
     * 適用条件 (すべて満たすこと):
     *  - `php <path>` として**別プロセスで直接**起動される (HTTP 応答の経路に載らない)
     *  - Laravel の Console 出力機構 (`$this->line()` 等) を持たない
     *    (持てるなら `Command` にすべきで、例外にはしない)
     *  - 標準出力への提示がそのスクリプトの機能そのものである
     */
    case StandaloneCliStdout = 'standalone_cli_stdout';
}
```

### PHPStan 適合チェック

- [x] backed enum (`string`) で値が明示されている
- [x] 網羅 `match` を書く側 (S5) が全 case を扱う

### テスト計画

- [x] 新規テスト (S5 内): 分類が実測の置き場所を過不足なく覆っていること
- [x] 新規テスト (S5 内): 例外を登録できるのは `ScannedWithExemption` の置き場所だけであること

### リスク

- 分類が exact-fit すぎると、ディレクトリの自然な増減で赤が出る。
  → **方向を非対称にする** (S5 の設計参照): 「実測 ⊆ 分類」は必須、
    「分類 ⊆ 実測」は**除外分類にだけ**課す。

---

## S5: gate 本体

### 変更箇所

- 新規: `tests/Architecture/ForbiddenStatementTokenInvariantTest.php`

**ファイル名はテンプレート実装と同じにする。** 将来テンプレートの取り込みで
テンプレート版が入ってきたときに、黙って 2 本並走するのではなく
**同じパスで衝突して人の目に触れる**ようにするため (思考原則 3)。

### 波及変更

- TypeScript 型定義 / API Resource・DTO / Inertia Props: なし
- テストファイル: 新規のみ (既存テストの変更・削除は無い)

### 走査対象の列挙 (git 追跡下に限る)

既存の `tests/Architecture/NoNonCompoundGlobalUseTest.php` と**同じ作法**を使う。

- `git ls-files -z -- '*.php'` を `Symfony\Component\Process\Process` で実行する
- git の実行に失敗したら **例外を投げて落とす** (環境不備を silent skip にしない)
- git 管理下に限ることで `vendor/` `node_modules/` `.claude/worktrees/` `storage/`
  `bootstrap/cache/` を**明示 exclude リストなしで**除外できる
- **`*.blade.php` を除外しない** (`NoNonCompoundGlobalUseTest` は除外するが、
  本 gate は Blade の開始タグ区間を見る必要があるため含める)
- 既知の限界: 未追跡 (git add 前) のファイルは走査されない。
  gate が守る境界は commit / CI であり、そこでは必ず追跡下にある

### 目録 (単一の出典)

```php
/** 例外の根拠の最低文字数 (「同上」「N/A」を機械的に弾く)。 */
const FORBIDDEN_STATEMENT_REASON_MIN_LENGTH = 30;

/**
 * 例外の件数の上限。**現在値ちょうど** (exact fit)。
 * ★余裕を 1 でも持たせると、その 1 本は「個別の根拠も再レビューも無しに書ける枠」になる。
 */
const FORBIDDEN_STATEMENT_EXEMPTION_CAP = 1;

/**
 * 走査対象ファイル数の床値。
 * ★走査が空振り (0 件) でも「違反 0 件」で緑になってしまうのを止める。
 *   実測 1552 (追跡 PHP 1567 − 除外 devnotes 15) に対し余裕を持たせて 1400 を置く。
 */
const FORBIDDEN_STATEMENT_SCANNED_FILE_FLOOR = 1400;

/**
 * 置き場所の分類 (単一の出典)。
 *
 * @return array<string, array{ForbiddenStatementRootPolicy, string}>
 *         キーは最上位ディレクトリ名 (リポジトリ直下は空文字列)。
 *         第 2 要素は理由 (ScannedNoExemption は空文字列でよい)。
 */
function forbiddenStatementRootPolicies(): array
{
    return [
        '' => [ForbiddenStatementRootPolicy::ScannedNoExemption, ''],
        'app' => [ForbiddenStatementRootPolicy::ScannedNoExemption, ''],
        'bootstrap' => [ForbiddenStatementRootPolicy::ScannedNoExemption, ''],
        'config' => [ForbiddenStatementRootPolicy::ScannedNoExemption, ''],
        'database' => [ForbiddenStatementRootPolicy::ScannedNoExemption, ''],
        'lang' => [ForbiddenStatementRootPolicy::ScannedNoExemption, ''],
        'public' => [ForbiddenStatementRootPolicy::ScannedNoExemption, ''],
        'resources' => [ForbiddenStatementRootPolicy::ScannedNoExemption, ''],
        'routes' => [ForbiddenStatementRootPolicy::ScannedNoExemption, ''],
        'scripts' => [
            ForbiddenStatementRootPolicy::ScannedWithExemption,
            'artisan を通さず別プロセスで起動される運用スクリプトが置かれる。'
            .'標準出力が人間への唯一の伝達手段になる場合がある。',
        ],
        'tests' => [
            ForbiddenStatementRootPolicy::ScannedWithExemption,
            '別プロセスで起動される検体が置かれる。親プロセスへ結果を返す手段が'
            .'標準出力しかない場合がある。',
        ],
        'devnotes' => [
            ForbiddenStatementRootPolicy::Excluded,
            '設計時の調査に使う一時スクリプトの置き場所であり (AGENTS.md「一時スクリプトは '
            .'devnotes へ」)、アプリの実行経路にも CI にも載らない。恒久化するときは '
            .'scripts/ へ移すので、そこで本 gate の対象になる。',
        ],
    ];
}

/**
 * 禁止する文を書くことが正しいと裁定したファイルの目録
 * (型付き + 具体的根拠必須 + 件数の完全一致、単一の出典)。
 *
 * @return array<string, array{
 *     exemption: ForbiddenStatementExemption,
 *     counts: array<string, int>,
 *     reason: non-empty-string,
 * }> counts のキーは ForbiddenStatementKind の値
 */
function forbiddenStatementExemptions(): array
{
    return [
        'scripts/ci/drop-test-db.php' => [
            'exemption' => ForbiddenStatementExemption::StandaloneCliStdout,
            'counts' => [ForbiddenStatementKind::EchoStatement->value => 23],
            'reason' => 'worktree のテスト DB を回収する運用スクリプト。artisan を通さない素の PHP '
                .'として `php scripts/ci/drop-test-db.php` で起動され、Laravel の Console 出力機構を'
                .'持たない。既定 dry-run の分類結果を人間へ提示することがこのスクリプトの機能そのもの'
                .'であり、HTTP 応答の組み立て経路には載らない。',
        ],
    ];
}
```

### テスト一覧

| # | 名前 | 検証内容 | 失敗の意味 |
|---|------|---------|-----------|
| G1 | 走査対象に禁止する文が存在しない (目録の登録分を除く) | 全走査ファイルの site から目録の件数を差し引き、残りが 0 件 | 新しい違反が入った |
| G2 | 走査が空振りしていない | 走査ファイル数 ≥ 1400 かつ > 0 | 列挙が壊れた / 分類で全部除外した |
| G3 | Blade も走査している | 走査対象に `.blade.php` が 1 件以上含まれる | `resources` を外す改変が入った |
| G4 | 追跡 PHP の置き場所がすべて分類済み | 実測の最上位ディレクトリ ⊆ 分類のキー | 新しいディレクトリが黙って走査外になった |
| G5 | 除外の登録が形骸化していない | `Excluded` に登録した置き場所に追跡 PHP が実在する | 除外理由が古い (登録を外すこと) |
| G6 | 除外と例外可の理由が 30 文字以上 | 文字数検査 | 「同上」で埋めた |
| G7 | 例外の登録先ファイルが実在する | `file_exists()` | ファイルを消したのに登録が残った |
| G8 | 例外の実測件数が登録件数と完全一致する | 語彙ごとに `count === 登録値` (多くても少なくても赤) | 増えた / 減った (どちらも再レビューが要る) |
| G9 | 例外の根拠が 30 文字以上 | 文字数検査 | 同上 |
| G10 | 例外の件数が上限 (exact fit = 1) を超えない | `count() === CAP` | 例外を増やすには上限の変更差分が要る |
| G11 | 例外を登録できるのは例外可の置き場所だけ | 登録キーの最上位ディレクトリの分類が `ScannedWithExemption` | `app/` 等へ例外を書こうとした |

**G4 と G5 の方向を非対称にする理由**: 「実測 ⊆ 分類」(G4) は必須である
(未分類の置き場所が黙って走査外になるのを止める)。
逆向き (「分類 ⊆ 実測」) を全分類に課すと、`lang/` の PHP が一時的に 0 件になっただけで
赤くなり、検査と関係のない摩擦を生む。**除外分類にだけ**逆向きを課す (G5) のは、
古い除外理由が残ると「見ていないのに見ているつもり」になるためである。

### 失敗メッセージの書式

```
禁止する文を検出しました。
  app/Http/Controllers/FooController.php:42 → echo 文
応答の組み立ては Inertia / JsonResource / Response で行ってください。
どうしても必要なら forbiddenStatementExemptions() へ理由付きで登録してください
(登録できるのは scripts / tests のみ)。
```

### PHPStan 適合チェック

- [x] 戻り値の型が明示されている (目録関数は phpdoc の array shape 付き)
- [x] null 安全 (`file_get_contents()` の `false` を `is_string()` で弾く)
- [x] 配列 shape を phpdoc で固定 (テスト専用の目録なので readonly class にはしない。
      既存 `strayHttpEgressOptOutExemptions()` と同じ作法)
- [x] `ForbiddenStatementRootPolicy` を扱う分岐は網羅 `match`

### テスト計画

- [x] バグ修正ではないので再現テストは不要
- [x] 既存テストの更新: **なし** (既存テストを 1 本も変更・削除しない)
- [x] 新規テスト: G1〜G11 の 11 本
- [x] 個別の `DatabaseTransactions` を使っていないことを確認
      (Architecture レーンは `tests/Pest.php` で TestCase のみ)

### 実装時に必ず踏む手順 (fail-first)

AGENTS.md 思考原則 5 (テストファースト)。

1. **目録を空 (`forbiddenStatementExemptions()` が `[]`) の状態で G1 を書き、
   赤になることを実測する** — `scripts/ci/drop-test-db.php` の 23 件が出るはず
2. その実測値をそのまま目録へ登録し、G1 が緑になることを確認する
3. `FORBIDDEN_STATEMENT_EXEMPTION_CAP` を 0 のまま G10 を走らせ、赤を実測してから 1 にする
4. 分類から `resources` を一時的に外して G3 が赤になることを実測する (実装後に戻す)
5. 分類から `app` を一時的に外して G4 が赤になることを実測する (実装後に戻す)

**この 5 手順の実測結果を実装 PR の説明に書くこと。**
「素の main では赤にならない種類のテスト」なので、
空振りしていないことは負の対照 (S3) と本手順の 2 本で担保する
(`StrayHttpEgressLaneGateTest` の docblock と同じ考え方)。

### リスク

- **git が無い環境ではテストが落ちる**。これは意図した設計である
  (既存 `NoNonCompoundGlobalUseTest` と同じ判断)。silent skip にすると
  「走査していないのに緑」になる
- 走査ファイル数 1552 に対して `token_get_all()` を全件かけるコストが増える。
  既存 `NoNonCompoundGlobalUseTest` が同じ母集団に `PhpToken::tokenize()` を
  かけており、実行時間の桁は変わらない見込み。
  **実装時に `composer test` の Architecture レーン所要時間を前後比較し、
  目立って伸びるようなら報告すること** (対策は設計しない = 先回りしない)

---

## S6: 規約の成文化

### 変更箇所

- `AGENTS.md` — 「テストレーンの外部 HTTP 出口 (既定拒否)」節の直後に
  同じ粒度の節を 1 つ足す

### 波及変更

- TypeScript 型定義 / API Resource・DTO / テストファイル: なし

### 追記する内容 (案)

```markdown
## 禁止する文 (echo / goto / global / 開始タグ付きの出力記法)

PHP の `echo` / `goto` / `global` の 3 文と、開始タグ付きの出力記法 `<?=` は
**書かない**。字句 (トークン) 単位の走査で検出する
(`tests/Architecture/ForbiddenStatementTokenInvariantTest.php`。
設計は `devnotes/20260815-1537-forbidden-statement-token-gate/`)。

- 理由: `echo` / `<?=` は Laravel の応答制御 (Inertia / JsonResource / Response) を
  迂回して直接出力へ書き出すため、ヘッダ確定前に本文を流し得る。
  撮影 PWA が依存する 3 枚セット (no-store baseline / bfcache 秘匿 /
  Inertia 履歴暗号化。ドメイン規約 3) を壊し得る経路になる。
  `goto` は制御フローを構造から読めなくし、`global` は DI コンテナ経由の
  依存解決を迂回して差し替えられない結合を作る
- 走査対象は **git 追跡下の `*.php` 全件** (`.blade.php` を含む)。
  置き場所は「走査する / 例外の登録を許す (`scripts` `tests`) / 除外する
  (`devnotes`。理由必須)」の 3 つへ**排他的に分類**し、
  **どれにも分類していない置き場所が現れたら赤になる**
- 例外は `ForbiddenStatementExemption` + 30 文字以上の根拠 + **件数**付きで
  目録へ登録する (deny-by-default)。件数は完全一致で、増えても減っても赤になる。
  現在の登録は `scripts/ci/drop-test-db.php` の `echo` 23 件の 1 本だけ
- **語彙を勝手に増やさない**。`print` は正典が対象外と定めており、
  拡張は家系の機能台帳の議題として起こす決まりである
- **保証範囲を誇張しない**: 効くのは字句として現れる 4 語彙だけである。
  `printf` / `var_dump` / `fwrite(STDOUT, …)` のような名前の解決が要る出力、
  Blade の `@php … @endphp` と `{{ }}` の中、ヒアドキュメント本文には
  **無言で効かない** (PHP 開始タグで開いた区間は見える)。
  「この検査があれば直接出力は 1 つも無い」とは読めない
```

### PHPStan 適合チェック

該当なし (ドキュメント)。

### テスト計画

- [x] 新規テスト: なし。AGENTS.md の記述と実装の同期を機械検査する仕組みは
      本リポジトリに無く、**1 本のためにその仕組みを新設しない** (思考原則 2)。
      代わりに、記述が指す gate ファイル名と例外の件数を**上の G7 / G8 / G10 が
      機械で固定する**ので、記述が古くなるのは「gate を消したとき」だけである
- [x] 既存テストの更新: なし

### リスク

- 6 件の設計が並走しているため、`AGENTS.md` は**競合しやすい**。
  実装時は追記位置を「テストレーンの外部 HTTP 出口」節の直後に固定し、
  既存行を 1 行も書き換えないこと (追記のみなら 3-way merge が通る)

---

## 実装モード

| 項目 | 内容 |
|------|------|
| 推奨モード | **incremental** |
| 判断根拠 | 変更は「新規テストファイル 7 本 + AGENTS.md への追記」だけで、`app/` `resources/` `routes/` `database/` `config/` に 1 行も触れない。複数コンポーネントの協調変更ではなく、他施策と並行できる |
| 競合リスク | `AGENTS.md` のみ (追記位置を固定して緩和)。テストファイルはすべて新規パスなので競合しない |

## 申し送り (次に触る人へ)

- **走査根の定義を共有する共通ヘルパは今回作らない**。現時点で利用者が本 gate 1 本しかない
  (思考原則 2)。**2 本目の gate が同じ置き場所の集合を必要とした時点で**、
  テンプレートが `tests/Pest.php` の `firstPartyPhpRoots()` へ寄せたのと同じく
  単一の出典へ寄せること。そのとき本 gate の `forbiddenStatementRootPolicies()` は
  分類 (走査する / 例外可 / 除外) を持ち続け、置き場所の集合だけを共有ヘルパから取る
- **テンプレートの取り込みでテンプレート版 gate が入ってきたら、並走させずに 1 本へ寄せる**
  (思考原則 3)。同名ファイルにしてあるので衝突として必ず表面化する。
  そのとき本実装が持っていて正典が持たないもの (`resources` の走査 / 分類漏れの検出 /
  例外の件数の完全一致) を落とさないこと
- **本設計は lctl 台帳の feature `forbidden-statement-token-gate` の移植である**。
  実装完了後の台帳への報告 (`status_reported`) は監督セッションの責務であり、
  実装セッションは台帳へ書き込まない

## 関連する現行コード (抜粋)

### tests/Support/PhpTokenScan.php (走査の共有正規化。全文)

<?php

declare(strict_types=1);

namespace Tests\Support;

/**
 * PHP ソースの静的走査で共有する `token_get_all()` の正規化 (純関数)。
 *
 * ★同じ正規化を 2 本持たない。`QueuedJobLeaseInventoryTest` (既存) と
 *   `ExternalClientBoundaryScanner` (T126) の両方がここを使う。
 * ★Pest のファイルスコープ関数はテストファイル間で衝突しうるため、
 *   `Tests\Support\QueueLeaseConfig` と同じくクラスの static メソッドへ集約する。
 */
final class PhpTokenScan
{
    /**
     * `token_get_all()` を「空白・コメントを除いた添字連番のリスト」へ正規化する。
     *
     * 単一文字トークン (`{` / `}` / `;` など) は `id => null` で表現し、
     * 行番号は直前トークンの行を引き継ぐ (単一文字トークンは行情報を持たないため)。
     *
     * @return list<array{id: int|null, text: string, line: int}>
     */
    public static function normalize(string $phpSource): array
    {
        $normalized = [];
        foreach (token_get_all($phpSource) as $token) {
            if (is_array($token)) {
                if (in_array($token[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
                    continue;
                }
                $normalized[] = ['id' => $token[0], 'text' => $token[1], 'line' => $token[2]];

                continue;
            }

            $line = $normalized === [] ? 0 : $normalized[count($normalized) - 1]['line'];
            $normalized[] = ['id' => null, 'text' => $token, 'line' => $line];
        }

        return $normalized;
    }
}

### tests/Architecture/NoNonCompoundGlobalUseTest.php (同種 gate。走査対象の列挙と負のコントロールの作法)

/**
 * git 追跡下の PHP ソースファイル一覧 (blade を除く)。
 *
 * @return list<array{absolute: string, relative: string}>
 */
function nonCompoundUseScanTargets(): array
{
    $root = base_path();
    $process = new Process(['git', 'ls-files', '-z', '*.php'], $root);
    $process->run();

    if (! $process->isSuccessful()) {
        throw new RuntimeException(
            'git ls-files の実行に失敗しました (git worktree 前提の architecture invariant): '
            .$process->getErrorOutput()
        );
    }

    $files = [];
    foreach (explode("\0", $process->getOutput()) as $relative) {
        if ($relative === '' || str_ends_with($relative, '.blade.php')) {
            continue;
        }
        $absolute = $root.'/'.$relative;
        if (! is_file($absolute)) {
            continue; // 削除済みだが index に残っている等
        }
        $files[] = ['absolute' => $absolute, 'relative' => $relative];
    }

    return $files;
}

/**
 * index 以降で最初の significant token の index。
 *
test('namespace 無しファイルに非複合 global use が存在しない', function (): void {
    $result = nonCompoundUseCollectAll();

    expect($result['violations'])->toBe([],
        '非複合 global use を検出しました。PHP は「has no effect」warning を出し import は無効です。'
        .'use 文を削除して参照側を \\FQCN (例: \\RuntimeException) にしてください。'
        .PHP_EOL.implode(PHP_EOL, $result['violations']));
});

test('走査が空振りしていない (git 追跡 PHP > 0 かつ namespace 無しファイル > 0)', function (): void {
    $result = nonCompoundUseCollectAll();

    expect($result['totalFiles'])->toBeGreaterThan(0);
    // database/migrations (60 本) や tests/Architecture など namespace 無しファイルは
    // 構造的に必ず存在する。0 なら namespace 判定が壊れている。
    expect($result['namespacelessFiles'])->toBeGreaterThan(0);
});

/*
 * 負のコントロール: 3 形態すべて (class / function / const) が実際に同じ warning を出すため、
 * 3 形態すべてを検出できることを fixture で固定する。
 */
test('負のコントロール: class / function / const の非複合 use を検出する', function (): void {
    $fixture = <<<'PHP'
    <?php
    declare(strict_types=1);
    use RuntimeException;
    use function strlen;
    use const PHP_VERSION;
    return new class {};
    PHP;

    $result = nonCompoundUseCollectFromSource($fixture, 'fixture.php');
    expect($result['scanned'])->toBeTrue();
    expect($result['violations'])->toHaveCount(3);
});

test('負のコントロール: カンマ区切り / as 別名の非複合 use も検出する', function (): void {
    $fixture = <<<'PHP'
    <?php
    use RuntimeException, LogicException;
    use InvalidArgumentException as Bad;
    PHP;

### tests/Architecture/StrayHttpEgressLaneGateTest.php (目録の作法。抜粋)

/** 既定配線が必須のレーン。 */
const STRAY_HTTP_EGRESS_REQUIRED_LANES = ['Feature', 'Unit', 'Architecture', 'Browser'];

/** opt-out 根拠の最低文字数 (「同上」「N/A」を機械的に弾く)。 */
const STRAY_HTTP_EGRESS_REASON_MIN_LENGTH = 30;

/**
 * exemption 件数の上限。**現在値ちょうど** (exact fit)。
 * ★余裕を 1 でも持たせると、その 1 本は「個別の根拠も再レビューも無しに opt-out できる枠」
 *   になる。exact fit なら次の 1 本が必ずこの数値を変える差分として現れる。
 */
const STRAY_HTTP_EGRESS_EXEMPTION_CAP = 1;

/**
 * 走査対象から外すファイル (走査器自身)。
 * ★本 gate は検査語 (`allowStrayRequests` 等) をパターン文字列として持つため、
 *   自分を走査すると必ず自己一致する。GlobalTestLockInventoryTest が
 *   「ライブラリ本体は対象外」としたのと同じ扱い。
 */
const STRAY_HTTP_EGRESS_SCANNER_SELF = 'tests/Architecture/StrayHttpEgressLaneGateTest.php';

/**
 * userinfo 詐称で loopback を騙る URL (実測で許可パターンに glob 一致するもの)。
 * ★`http://127.0.0.1:80@api.frankfurter.dev/` は userinfo が `127.0.0.1:80` で
 *   **実ホストは `api.frankfurter.dev`**。guard の第 2 層がこれを stray に落とす契約。
 */
const STRAY_HTTP_EGRESS_SMUGGLED_URLS = [
    'http://127.0.0.1:80@api.frankfurter.dev/',
    'https://127.0.0.1:443@api.frankfurter.dev/v1/latest',
    'http://localhost:9@evil.example/x',
    'https://localhost:1@169.254.169.254/latest/meta-data/',
    // ★`http://[::1]:1@evil.example/` は**そもそも URI としてパースできない**ため入れない
    //   (Guzzle Uri が "Unable to parse URI" を投げる = リクエストを組み立てられない)。
    //   すなわち `[::1]:*` パターン経由の userinfo 詐称は到達不能である。
];

/**
 * opt-out 呼び出しを持つことが正しいと裁定したファイルの inventory
 * (型付き + 具体的根拠必須、単一 source of truth)。
 *
 * @return array<string, array{StrayHttpEgressExemption, non-empty-string}>
 */
function strayHttpEgressOptOutExemptions(): array
{
    return [
        'tests/Support/StrayHttpRequestGuard.php' => [
            StrayHttpEgressExemption::GuardDefinitionSite,
            'レーン既定 guard 本体。Http::allowStrayRequests() を呼ぶのは ALLOWED_URL_PATTERNS '
            .'(loopback リテラルのみ) を設定するためであり、allowStrayRequests(null) や '
            .'preventStrayRequests(false) は呼ばない = 既定拒否そのものは外していない。',
        ],
    ];
}

/**
});

test('opt-out 呼び出しを持つファイルが全て exemption inventory に登録済みであること (deny-by-default)', function (): void {
    $registered = array_keys(strayHttpEgressOptOutExemptions());
    $unregistered = array_values(array_diff(strayHttpEgressOptOutSites(), $registered));

    expect($unregistered)->toBe([], implode(PHP_EOL, array_map(
        static fn (string $path): string => "opt-out 呼び出しが inventory 未登録: {$path} "
            .'(Http::fake([...]) で解くか、strayHttpEgressOptOutExemptions() へ理由付きで登録する)',
        $unregistered,
    )));
});

test('exemption inventory に実在しないファイルが残っていないこと (形骸化ガード)', function (): void {
    $sites = strayHttpEgressOptOutSites();

    foreach (strayHttpEgressOptOutExemptions() as $path => $entry) {
        expect(file_exists(base_path($path)))->toBeTrue("inventory のファイルが実在しない: {$path}");
        expect(in_array($path, $sites, true))
            ->toBeTrue("inventory に登録されているが opt-out 呼び出しを持たない (登録を外すこと): {$path}");
    }
});

test('exemption の根拠が 30 文字以上であること', function (): void {
    foreach (strayHttpEgressOptOutExemptions() as $path => [$kind, $reason]) {
        expect($kind)->toBeInstanceOf(StrayHttpEgressExemption::class);
        expect(mb_strlen($reason))->toBeGreaterThanOrEqual(
            STRAY_HTTP_EGRESS_REASON_MIN_LENGTH,
            "exemption の根拠が短すぎる ({$path}): {$reason}",
        );
    }
});

test('exemption 件数が上限 (exact fit) を超えていないこと', function (): void {
    expect(count(strayHttpEgressOptOutExemptions()))
        ->toBeLessThanOrEqual(
            STRAY_HTTP_EGRESS_EXEMPTION_CAP,
            'exemption を増やすには cap を明示的に引き上げる差分が必要 (再レビューの強制)',
        );
});

