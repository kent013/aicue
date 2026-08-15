Round 3 の指摘に対する対応マトリクスと、修正した詳細設計 (全文) を示す。
再レビューし、各施策の判定と全体判定を出してほしい。

重要な実測結果 (php -l で確認):
- `<?php #[Echo] class A {}` → Parse error (属性名に予約語は書けない)
- `<?php #[Other, Echo] class A {}` → Parse error
- `<?php const echo = 1;` (名前空間直下) → Parse error
- `<?php define("echo", 1); switch ($x) { case echo: }` → Parse error
- `<?php #[Attr(echo: 1)] class A {}` → OK。トークンは T_ECHO prev='(' next=':' (R6 が扱う)
したがって属性のための読み飛ばし規則は「成立しない書き方のための規則」であり、
深さ追跡ではなく規則そのものを削除した。R4 の直後の `:` も同じ理由で落とした。

また走査器を試作して検体 31 本と HEAD 全体に実際に走らせ、
検体は全て期待どおり、HEAD の検出は scripts/ci/drop-test-db.php の echo 23 件のみであることを確認した。

## 対応マトリクス

# 対応マトリクス: design-review Round 3

## [Warning] S2 — 複数属性の 2 件目以降を誤検出する

- 判断: **対応する。ただし指摘された修正案 (属性の区間を角括弧の深さで追跡する) は採らず、
  規則そのものを削除する。**
- 根拠: 指摘を受けて `php -l` で実測したところ、**属性名に予約語は書けない**ことが分かった。

  | 検体 | `php -l` |
  |---|---|
  | `<?php #[Echo] class A {}` | **Parse error** (`syntax error, unexpected token "echo"`) |
  | `<?php #[Other, Echo] class A {}` | **Parse error** (`unexpected token "echo", expecting "]"`) |
  | `<?php #[MyAttr] class A {}` | OK |

  つまり R5 (直前が `T_ATTRIBUTE` なら名前位置) は、**PHP として成立しない書き方のために
  置かれた規則**だった。成立しない書き方のために規則を置くことは、
  検出力を無償で捨てることに等しい。指摘の「深さ追跡」も同じ理由で不要である。

  属性の中で対象の綴りが現れうる唯一の正しい形は**名前つき引数**であり、
  実測すると `#[Attr(echo: 1)]` は `T_ECHO prev='(' next=':'` になるので
  **R6 (直後が単独の `:`) がそのまま扱う** (`php -l` OK を確認済み)。
- 対応内容: **R5 を削除**する。`NAME_ONLY_PREDECESSORS` は `T_DOUBLE_COLON` の 1 つだけになる。
  削除の理由 (属性名に予約語を書けない実測) をコードの説明文へ残す。

## [Warning] S2 (派生) — 他の規則も「成立する書き方か」を全数で確かめた

- 判断: **自主的に追加対応する**
- 根拠: R5 が成立しない書き方のための規則だったので、**全規則を `php -l` で総当たりした**。
  その結果、もう 2 つ「成立しない書き方」が見つかった。

  | 検体 | `php -l` | 帰結 |
  |---|---|---|
  | `<?php const echo = 1;` (名前空間直下の定数) | **Parse error** | 半予約語を名前に使えるのはクラス / 列挙のメンバだけ |
  | `<?php define("echo", 1); switch ($x) { case echo: }` | **Parse error** | 場合分けに素の予約語は書けない |

- 対応内容: **R4 の直後の許容から `:` を落とす** (`['=', ';', ':']` → `['=', ';']`)。
  `case A::ECHO:` の形は R1 が扱う (`php -l` OK を確認済み)。
  読み飛ばし規則は 8 通りから **7 通り**へ減り、検出力はその分だけ上がった。
  「成立しない書き方のために規則を置かない」ことを設計へ節として明記する。

## [Suggestion] S2 — R3b の状態名の説明

- 判断: **対応する**
- 根拠: 指摘のとおり。`T_CONST` はクラス定数以外の位置にも現れる。
- 対応内容: 説明を「クラス定数宣言」から「**`T_CONST` から `;` までの定数宣言区間**」へ直す。

## [Warning] S3 — 複数属性の負の対照が不足

- 判断: **対応する (形を変えて)**
- 根拠: 上記のとおり複数属性の検体は PHP として成立しないので、
  **負の対照にできない** (「全検体は構文として成立する PHP である」という約束を破る)。
- 対応内容: N6 を「属性」から「**属性の名前つき引数**」(`#[Attr(echo: 1)] class Foo {}`) へ
  差し替える。あわせて取りこぼし対照へ F4b
  (`#[Attr(echo: 1)] class Foo {} global $x;` → 1 件) を足す。

## [Warning] S3 — N15 (連結検体) は有効な PHP にならない

- 判断: **対応する。指摘の 2 案のうち後者 (削除) を採る。**
- 根拠: 指摘のとおり。各断片は `<?php` を持ち、`trait T` / `class A` を重複宣言するので
  単純連結は構文・宣言が衝突する。宣言名を一意にした統合検体を別に作ることもできるが、
  各断片が個別に 0 件であること以上の保証は得られない (思考原則 2)。
- 対応内容: N15 を削除し、**作らない理由を設計へ書き残す** (次に読む人が
  「連結検体が抜けている」と誤解しないため)。

## [Suggestion] S3 — 「`php -l` を 25 回」は本数の更新で古くなる

- 判断: **対応する**
- 対応内容: 「**各検体について** `php -l` を 1 度走らせる」に書き換える (本数を書かない)。

## 追加で行ったこと (指摘ではないが設計の裏取り)

走査器と走査対象の列挙を**試作して実際に走らせた**。

- 検体 31 本 (正例 9 + 取りこぼし対照 8 + 負の対照 14): **すべて期待どおり**
- HEAD 全体 (追跡 PHP 1567 件、走査 1552 件):
  検出は `scripts/ci/drop-test-db.php` の `echo` 23 件のみ。
  家系の機能台帳の事前見積もりと完全に一致した

結果を詳細設計へ「設計時の実測」節として追記した
(ただし実装は「目録を空にして赤を実測してから登録する」手順を必ず踏むことも明記した)。

## S1 / S4 / S5 / S6 — APPROVE

- 判断: 対応不要。

## 修正後の詳細設計 (全文)

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
| `enum E { case Echo; }` | `T_ECHO` | `T_CASE` | 違反でない |
| `class Foo { const echo = 1, goto = 2; }` | `T_ECHO` / `T_GOTO` | `T_CONST` / `,` | 違反でない |
| `class A { use T { m as echo; } }` | `T_ECHO` | `T_AS` | 違反でない |
| `class A { use T { m as protected global; } }` | `T_GLOBAL` | `T_PROTECTED` | 違反でない |
| `f(global: 2, goto: 3);` | `T_GLOBAL` / `T_GOTO` | `,` (直後が `:`) | 違反でない |
| `#[Attr(echo: 1)] class A {}` | `T_ECHO` | `(` (直後が `:`) | 違反でない |
| `$o->echo(); $o?->global();` | **`T_STRING`** | — | そもそも対象外 |
| コメント / DocComment / 文字列リテラル中の綴り | (除去済み) | — | そもそも対象外 |

### 読み飛ばし規則を持たない位置 (実測で「そもそも書けない」と分かったもの)

規則を検討したが、**その書き方が PHP として成立しない**と実測で分かったため
規則を持たない位置がある。**成立しない書き方のために規則を置くと、
検出力を無償で捨てることになる。**

| 検討した書き方 | `php -l` | 判断 |
|---|---|---|
| `#[Echo] class A {}` (属性名に予約語) | **Parse error** (`unexpected token "echo"`) | 属性のための規則は**持たない**。属性の中で綴りが現れうるのは名前つき引数 (`#[Attr(echo: 1)]`) だけで、それは R6 が扱う |
| `const echo = 1;` (名前空間直下の定数) | **Parse error** | 半予約語を名前に使えるのは**クラス / 列挙のメンバだけ**である |
| `define("echo", 1); switch ($x) { case echo: }` | **Parse error** | 場合分けに素の予約語は書けない。`case A::ECHO:` は R1 が扱う |

### 読み飛ばし規則 (直前と直後の組で狭める)

読み飛ばしは **7 通りだけ**で、すべて実測した書き方に 1 対 1 で対応する。
直前だけで判定せず、**可能なものは直後の条件も課して狭める**
(字句走査は構文の妥当性を保証しないので、規則は狭いほどよい)。
**下表の「実在の書き方」はすべて `php -l` が通ることを実測済みである**
(通らなかったものは前節のとおり規則そのものを持たない)。

| # | 直前の有意トークン | 直後の有意トークンの条件 | 対応する実在の書き方 |
|---|---|---|---|
| R1 | `T_DOUBLE_COLON` (`::`) | (条件なし) | `Foo::goto();` / `$c = Foo::echo;` / `Foo::echo(...)` / トレイト取り込みの元メソッド指定 `T::echo as m;` / `case A::ECHO:` |
| R2 | `T_FUNCTION` | `(` | `class A { public function echo(): void {} }` / `interface I { public function goto(): void; }` |
| R3 | `T_CONST` | `=` | `class A { const echo = 1; }` |
| R3b | `,` (**定数宣言の区間に限る**) | `=` | `class A { const echo = 1, goto = 2; }` |
| R4 | `T_CASE` | `=` / `;` | `enum E: string { case Echo = 'e'; }` / `enum E { case Echo; }` |
| R6 | (条件なし) | 単独の `:` | 名前つき引数 `f(global: 2, goto: 3)` / 属性の名前つき引数 `#[Attr(echo: 1)]` |
| R7 | `T_AS` / `T_PUBLIC` / `T_PROTECTED` / `T_PRIVATE` | `;` | トレイト取り込みの別名 `class A { use T { m as echo; } }` / `class A { use T { m as protected global; } }` |

R1 だけ直後の条件を付けないのは、直後に来られるトークンの種類が多く
(`(` `;` `,` `)` `=` `\` …)、列挙するほうがかえって穴を作るためである。
`::` は「**直後に名前しか置けない**」ことが PHP の文法から言えるので、
直前だけで十分に狭い。R6 の `:` は単独の `:` に限る
(`::` は 1 つの `T_DOUBLE_COLON` トークンなので一致しない)。

R4 の直後に `:` を許さないのは、素の予約語を場合分けの値に書けないためである
(実測: `define("echo", 1); switch ($x) { case echo: }` は Parse error)。
`case A::ECHO:` の形は R1 が扱う。

R3b だけ状態を持つ。**`T_CONST` から `;` までの区間**を真偽値 1 つで覚え、
その区間の中で「直前が `,` かつ直後が `=`」の綴りを名前位置とする。
定数の初期化式に文は書けない (PHP の定数式の制限) ので、
この区間を名前位置扱いしても本物の文を取りこぼさない。
配列リテラルの読点 (`const X = [1, 2], Y = 3;`) は直後が `=` にならないため一致しない
(実測で確認済み)。

R7 の可視性修飾子は**トレイト取り込みの別名指定でしか現れない**
(通常の宣言では `public function echo` のように間に `function` が入り R2 になる)。
直後を `;` に限ることで、修飾子の直後に文が立つ余地を消している。

**この 7 規則が取りこぼしを作らないこと**は、規則の近傍に本物の違反を置いた
検体 (S3 の F1〜F7 + F4b の 8 本) が検出されることで固定する。実測では、規則の近傍にある
本物の `echo` の直前トークンは `{` / `}` / `:` / `;` のいずれかになり、
どの規則にも一致しない。

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
     * 直前が `::` なら無条件に名前位置とみなす (R1)。
     *
     * ★`::` は「直後に名前しか置けない」ことが PHP の文法から言えるので、
     *   直後の条件を課さなくても十分に狭い。逆に直後に来られるトークンの種類が
     *   多い (`(` `;` `,` `)` `=` `\` …) ため、列挙するとかえって穴を作る。
     * ★**属性 (`#[`) のための規則は持たない**。属性名に予約語は書けず
     *   (実測: `#[Echo] class A {}` は Parse error)、属性の中で綴りが現れうるのは
     *   名前つき引数 (`#[Attr(echo: 1)]`) だけで、それは R6 が扱うためである。
     *   成立しない書き方のために規則を置くと検出力を無償で捨てることになる。
     */
    private const array NAME_ONLY_PREDECESSORS = [
        T_DOUBLE_COLON,   // Foo::goto() / $c = Foo::echo; / Foo::echo(...) / T::echo as m; / case A::ECHO:
    ];

    /**
     * 直前がこれらなら、**直後が指定のトークンのときに限り**名前位置とみなす
     * (R2 / R3 / R4 / R7)。
     *
     * ★字句走査は構文の妥当性を保証しないので、規則は狭いほどよい。
     *   直前だけで判定すると「構文として成立しない断片」でも黙ることになる。
     * ★可視性修飾子が現れるのは**トレイト取り込みの別名指定だけ**である
     *   (通常の宣言では間に `function` が入るので R2 になる)。
     * ★`T_CASE` の直後に `:` を許さない。素の予約語は場合分けの値に書けず
     *   (実測: `define("echo", 1); switch ($x) { case echo: }` は Parse error)、
     *   `case A::ECHO:` の形は R1 が扱うためである。
     *
     * @var array<int, list<string>> トークン ID => 直後に許す単一文字トークン
     */
    private const array NAME_POSITION_PREDECESSORS = [
        T_FUNCTION => ['('],      // class A { public function echo(): void {} }
        T_CONST => ['='],         // class A { const echo = 1; }
        T_CASE => ['=', ';'],     // enum E: string { case Echo = 'e'; } / enum E { case Echo; }
        T_AS => [';'],            // class A { use T { m as echo; } }
        T_PUBLIC => [';'],        // class A { use T { m as public echo; } }
        T_PROTECTED => [';'],     // class A { use T { m as protected global; } }
        T_PRIVATE => [';'],       // 同上
    ];

    /**
     * @return list<ForbiddenStatementSite>
     */
    public static function sites(string $relativePath, string $phpSource): array
    {
        $tokens = PhpTokenScan::normalize($phpSource);
        $count = count($tokens);

        // R3b 用。`T_CONST` から `;` までの定数宣言区間だけ、
        // 読点区切りの 2 つ目以降を名前位置とみなす。
        $inConstDeclaration = false;

        $sites = [];
        for ($i = 0; $i < $count; $i++) {
            if ($tokens[$i]['id'] === T_CONST) {
                $inConstDeclaration = true;
            } elseif ($tokens[$i]['id'] === null && $tokens[$i]['text'] === ';') {
                $inConstDeclaration = false;
            }

            $kind = ForbiddenStatementKind::fromTokenId($tokens[$i]['id']);
            if ($kind === null) {
                continue;
            }

            if ($kind->needsContextCheck() && self::isNamePosition($tokens, $i, $inConstDeclaration)) {
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
    private static function isNamePosition(array $tokens, int $index, bool $inConstDeclaration): bool
    {
        $previous = $tokens[$index - 1] ?? null;
        $previousId = $previous['id'] ?? null;
        $next = $tokens[$index + 1] ?? null;
        // 単一文字トークンは `id === null` で表現される (PhpTokenScan::normalize の契約)
        $nextChar = $next !== null && $next['id'] === null ? $next['text'] : null;

        // R1: 直後に名前しか置けない位置
        if ($previousId !== null && in_array($previousId, self::NAME_ONLY_PREDECESSORS, true)) {
            return true;
        }

        // R2 / R3 / R4 / R7: 直前と直後の組で狭める
        $allowedNext = $previousId === null ? null : (self::NAME_POSITION_PREDECESSORS[$previousId] ?? null);
        if ($allowedNext !== null && $nextChar !== null && in_array($nextChar, $allowedNext, true)) {
            return true;
        }

        // R3b: `const echo = 1, goto = 2;` の 2 つ目以降。
        //      定数の初期化式に文は書けないので、この区間を名前位置扱いしても取りこぼさない。
        //      配列リテラルの読点は直後が `=` にならないため一致しない。
        if ($inConstDeclaration && $previousId === null && ($previous['text'] ?? null) === ',' && $nextChar === '=') {
            return true;
        }

        // R6: 名前つき引数 (`f(global: 2)`) は直後が単独の `:` になる。
        //     `::` は 1 つの `T_DOUBLE_COLON` トークンなので、ここには一致しない。
        return $nextChar === ':';
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
| N5 | switch の場合分け (クラス定数経由) | `<?php class A { const ECHO = 1; } switch ($x) { case A::ECHO: break; }` |
| N6 | 属性の名前つき引数 | `<?php #[Attr(echo: 1)] class Foo {}` (R6 が扱う。属性名そのものに予約語は書けない) |
| N7 | 名前つき引数 | `<?php function f(int $global, int $goto) {} f(global: 2, goto: 3);` |
| N8 | オブジェクトのメソッド呼び出し | `<?php class A { public function echo(): void {} } $o = new A(); $o->echo(); $o?->global();` |
| N9 | コメント / DocComment / 文字列リテラル | `<?php // echo "x";` + `/** goto */` + `$s = "echo global goto";` |
| N10 | ナウドキュメント / ヒアドキュメントの本文 | 本文に `echo "x"; global $y;` を含むナウドキュメント |
| N11 | 複数の定数を読点で繋いだ宣言 | `<?php class A { const echo = 1, goto = 2, global = 3; }` |
| N12 | トレイト取り込みの別名指定 | `<?php trait T { public function m(): void {} } class A { use T { m as echo; } }` |
| N13 | トレイト取り込みの別名指定 (可視性つき) | `<?php trait T { public function m(): void {} } class A { use T { m as protected global; } }` |
| N14 | 定数の初期化式の配列の読点は名前位置にならない | `<?php class A { const X = [1, 2], Y = 3; }` (0 件。R3b が広がりすぎていないことの確認) |

N1〜N14 を 1 つに連結した検体は**作らない**。各断片は `<?php` を持ち、
`trait T` / `class A` を重複して宣言するため、単純に繋ぐと構文・宣言が衝突して
「全検体は構文として成立する PHP である」という約束を破る。
連結しても各断片が個別に 0 件であること以上の保証は得られない。

**取りこぼし対照 — 読み飛ばし規則の近傍でも検出できること**

本設計の最大のリスクは誤検出ではなく**読み飛ばしによる取りこぼし**である。
各規則の直後に本物の違反を置いた検体で、規則が広がりすぎていないことを固定する。
実測では、これらの本物の `echo` / `global` の直前トークンは
`{` / `}` / `:` / `;` のいずれかになり、どの規則にも一致しない。

| # | 名前 | 検体 | 期待 |
|---|------|------|------|
| F1 | 無名関数の中の違反を検出する | `<?php $fn = function () { echo "x"; };` | 1 件 |
| F2 | 定数宣言の直後の違反を検出する | `<?php class Foo { const A = 1; } echo "x";` | 1 件 |
| F3 | switch の場合分けの本体の違反を検出する | `<?php switch ($x) { case 1: echo "x"; }` | 1 件 |
| F4 | 属性付き宣言の直後の違反を検出する | `<?php #[Attr] class Foo {} echo "x";` | 1 件 |
| F4b | 属性の名前つき引数の直後の違反を検出する | `<?php #[Attr(echo: 1)] class Foo {} global $x;` | 1 件 |
| F5 | 静的呼び出しの直後の違反を検出する | `<?php Foo::bar(); echo "x";` | 1 件 |
| F6 | 名前つき引数の直後の違反を検出する | `<?php f(a: 1); global $x;` | 1 件 |
| F7 | 括弧付きの `echo` も検出する | `<?php echo("x");` | 1 件 |

**写像の網羅**

| # | 名前 | 検証 |
|---|------|------|
| M1 | 語彙は 4 件ちょうどである | `ForbiddenStatementKind::cases()` が 4 件 |
| M2 | トークン ID の写像が全 case を覆う | 各 case について `fromTokenId()` が往復する。`T_STRING` / `T_PRINT` / `null` は `null` を返す |

### 検体の書き方 (実装時の約束)

- **全検体は構文として成立する PHP である**。半予約語をメンバ名に使えるのは
  クラス / 列挙の中だけなので、文脈を切り落とした断片
  (`function echo(): void {}` を単体で置く等) は**実在しない書き方**になり、
  「その規則が現実の誤検出を防いでいる」ことの証明にならない
- 検体はすべて**ナウドキュメント文字列**で書く (理由は次節)
- 実装時に**各検体について** `php -l` を 1 度走らせ、通ることを確認してからテストへ埋める
  (テスト内から検体ごとに `php -l` を起動する自動検査は作らない。
   本題である走査器の検出力と関係のないコストになるため)
- **`php -l` が通らなかった書き方は、負の対照ではなく「そもそも書けない位置」である。**
  その位置のための読み飛ばし規則は持たない (実測で 3 つ見つかった。S2 の表を参照)

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

本施策そのものがテストである。green の条件は上表 **30 本** (P 6 本 + N 14 本 + F 8 本 + M 2 本) すべて。

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
 * 例外の登録件数。**現在値ちょうど** (exact fit。`<=` ではなく `===` で照合する)。
 * ★余裕を 1 でも持たせると、その 1 本は「個別の根拠も再レビューも無しに書ける枠」になる。
 * ★減った場合も赤にする (登録を消したなら、この値を変える差分が要る)。
 */
const FORBIDDEN_STATEMENT_EXEMPTION_COUNT = 1;

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
| G1 | 走査対象に禁止する文が存在しない (目録の登録分を除く) | 全走査ファイルの site から目録の `(パス, 語彙)` 分だけを差し引き、残りが 0 件 | 新しい違反が入った |
| G2 | 走査が空振りしていない | 走査ファイル数 ≥ 1400 かつ > 0 | 列挙が壊れた / 分類で全部除外した |
| G3 | Blade も走査している | 走査対象に `.blade.php` が 1 件以上含まれる | `resources` を外す改変が入った |
| G4 | 追跡 PHP の置き場所がすべて分類済み | 実測の最上位ディレクトリ ⊆ 分類のキー | 新しいディレクトリが黙って走査外になった |
| G5 | 除外の登録が形骸化していない | `Excluded` に登録した置き場所に追跡 PHP が実在する | 除外理由が古い (登録を外すこと) |
| G6 | 除外と例外可の理由が 30 文字以上 | 文字数検査 | 「同上」で埋めた |
| G7 | 例外の登録先ファイルが実在する | `file_exists()` | ファイルを消したのに登録が残った |
| G8 | 例外の実測件数が登録件数と完全一致する | 語彙ごとに `count === 登録値` (多くても少なくても赤) | 増えた / 減った (どちらも再レビューが要る) |
| G9 | 例外の根拠が 30 文字以上 | 文字数検査 | 同上 |
| G10 | 例外の登録件数が現在値と完全一致する | `count(forbiddenStatementExemptions()) === FORBIDDEN_STATEMENT_EXEMPTION_COUNT` | 例外を増やす / 減らすには、この値を変える差分が要る (再レビューの強制) |
| G11 | 例外を登録できるのは例外可の置き場所だけ | 登録キーの最上位ディレクトリの分類が `ScannedWithExemption` | `app/` 等へ例外を書こうとした |
| G12 | 例外の登録内容そのものが正しい | `counts` が空でない / 全キーが `ForbiddenStatementKind::cases()` の値に含まれる / 全ての値が **1 以上の整数** | 綴り間違いのキーや `0` 件登録が黙って通るのを止める |

**G12 が要る理由**: `counts` の型は `array<string, int>` なので、
未知の語彙キー (`'ehco' => 23`) や `0` / 負数を PHPStan は止められない。
未知のキーは「差し引かれない = G1 が落ちる」ので静かには壊れないが、
失敗の理由が「登録したのに効いていない」形になり読み解きにくい。
`0` 件の登録は「かつてあったが消えた」痕跡であり、G8 の完全一致で落ちるものの、
目録の側で先に落としたほうが直し方が自明になる。

### G1 の仕様 (実装者が読み違えないように明記する)

**例外に登録されたファイルを丸ごと走査対象から外してはならない。**

- (a) 例外に登録されたファイルも**全語彙を走査する** (skip しない)
- (b) 差し引けるのは目録に登録された **`(パス, 語彙)` の組だけ**である。
      同じファイルに登録の無い語彙 (`goto` / `global` / `<?=`) が現れたら
      **1 件残らず違反になる**
- (c) 実測件数が登録件数と食い違う `(パス, 語彙)` は G8 が落とす。
      G1 は G8 が緑であることを前提に差し引く
      (差し引きで負にならないよう、実測件数と登録件数の小さいほうを引く)

この形にしないと、`scripts/ci/drop-test-db.php` に後から `global` が 1 行入っても
「登録済みファイルだから」で素通りしてしまう。

### G2 の失敗メッセージ (床値割れの原因を判別できるようにする)

床値を割った理由が「分類漏れで除外が増えた」のか「単にファイルが減った」のかを
メッセージだけで判別できるようにする。

```
走査対象が床値 (1400) を下回りました: 走査 812 件
  追跡 PHP 総数: 1567 件
  除外された数: 755 件
  置き場所ごとの内訳: app=760(走査) tests=601(除外!) devnotes=15(除外) …
分類 (forbiddenStatementRootPolicies) が意図せず除外側へ倒れていないか確認してください。
```

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
- [x] 新規テスト: G1〜G12 の 12 本
- [x] 個別の `DatabaseTransactions` を使っていないことを確認
      (Architecture レーンは `tests/Pest.php` で TestCase のみ)

### 実装時に必ず踏む手順 (fail-first)

AGENTS.md 思考原則 5 (テストファースト)。

1. **目録を空 (`forbiddenStatementExemptions()` が `[]`) の状態で G1 を書き、
   赤になることを実測する** — `scripts/ci/drop-test-db.php` の 23 件が出るはず
2. その実測値をそのまま目録へ登録し、G1 が緑になることを確認する
3. `FORBIDDEN_STATEMENT_EXEMPTION_COUNT` を 0 のまま G10 を走らせ、赤を実測してから 1 にする
   (`===` で照合するので、多くても少なくても赤になることを両方向で確認する)
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
  **登録の正本は目録 (`forbiddenStatementExemptions()`) だけ**で、本書には件数を写さない
  (2 か所に書くと必ず食い違う)。登録できるのは `scripts` / `tests` に限る
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
      代わりに **AGENTS.md へ変動する数値を 1 つも書かない**
      (例外の件数・語彙別の件数・走査ファイル数はすべて目録と gate が正本)。
      書くのは変動しないもの — 禁止する 4 語彙・置き場所の 3 分類・
      例外を登録できる置き場所・保証しない範囲 — だけにする。
      これで「文書だけが古くなる」経路を構造的に消す
- [x] 既存テストの更新: なし

### リスク

- 6 件の設計が並走しているため、`AGENTS.md` は**競合しやすい**。
  実装時は追記位置を「テストレーンの外部 HTTP 出口」節の直後に固定し、
  既存行を 1 行も書き換えないこと (追記のみなら 3-way merge が通る)

---

## 設計時の実測 (実装前に走査器を試作して確かめた結果)

設計を確定させる前に、S2 の走査器と S5 の走査対象の列挙を試作し、
S3 の全検体と本リポジトリの HEAD に対して走らせた。
試作は `devnotes/20260815-1537-forbidden-statement-token-gate/` の外に置いた
一時スクリプトで行い、リポジトリには残していない
(AGENTS.md「一時スクリプトは devnotes へ、恒久スクリプトのみ scripts/ へ」)。

### 検体 31 本の結果

正例 (P1a〜P6 の 9 通り) と取りこぼし対照 (F1〜F7 + F4b の 8 本) は
**すべて期待どおりの件数を検出**し、負の対照 (N1〜N14 の 14 本) は
**すべて 0 件**だった。**31 本すべて期待どおり。**

### HEAD 全体の結果

```
走査ファイル数 = 1552
置き場所ごとの追跡 PHP 数:
  app: 760 / bootstrap: 2 / config: 38 / database: 114 / devnotes: 15 (除外)
  lang: 5 / public: 1 / resources: 24 / routes: 4 / scripts: 3 / tests: 601
検出:
  scripts/ci/drop-test-db.php の echo × 23 (最初の行 361)
blade ファイル数 = 24
```

すなわち **例外の登録は 1 件で足り、本体コードの書き換えは要らない**。
これは家系の機能台帳が事前に見積もっていた値
(「echo 23 件が `scripts/ci/drop-test-db.php` の 1 ファイルへ集中。
`goto` / `global` / 開始タグ付き echo は 0 件。導入は例外 1 件で足りる」) と**完全に一致した**。

**ただし実装は「目録を空にして赤を実測してから登録する」手順を必ず踏むこと**
(下の fail-first 手順)。設計時の試作は実装の代わりにならない。

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
