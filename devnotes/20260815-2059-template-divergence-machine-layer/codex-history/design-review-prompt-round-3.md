Round 2 の Critical 1 件・Warning 2 件すべてに対応した。再レビューして各施策の判定と全体判定を出してほしい。

## 対応マトリクス

# 対応マトリクス: design-review Round 2

## [Critical] `createFromFormat()` の失敗値の型締めが無い (施策 4)
- 判断: 対応する
- 根拠: 指摘のとおり `false` を返しうる。そのまま `format()` を呼べば PHPStan level 10 が落ち、
  実行時にも壊れる。しかも「解析失敗を違反として返す」設計になっていなかった
- 対応内容: `parseDate()` の骨子を設計へ書き、戻り値を `?CarbonImmutable` にして
  失敗は **null → 違反一覧** へ倒す形に固定した (例外に倒さない)。
  負例に「空文字 / `not-a-date` / `2026-02-30` / 未来日」の 4 ケースを TD6・TD8 の両方へ追加

## [Warning] 囲みコード区画の解析文法が Markdown より緩い (施策 4)
- 判断: 対応する (提案 2 案のうち「書式を 1 種類に絞る」を採る)
- 根拠: 4 個以上の囲みや `~~~` を黙って読み飛ばすと、それで登録を隠せる回避口になる。
  一方で Markdown の囲み文法を全部実装するのは本作業の目的に対して過剰である
- 対応内容: 台帳で使ってよい囲みを**行頭のバッククォート 3 個ちょうど**だけに定め、
  4 個以上と `~~~` は**明示的に違反**にした。負例 P3 を追加

## [Warning] メタ表のセル内の `|` の扱いが未定義 (施策 4)
- 判断: 対応する (全面禁止を採る)
- 根拠: エスケープを解釈する解析器を持つと、解析器の複雑さだけが増えて検出力は上がらない
- 対応内容: セル内の `|` は `\|` も含めて禁止。分割後の要素数がちょうど 4 個でなければ違反にする、
  と Parser 仕様と規約節の両方へ書いた。負例 P4 を追加

## 修正後の施策 4 (全文)

## 施策 4: 形式検査の新設

### 変更箇所 (新規 8 ファイル = Support 6 + テスト 2)

| ファイル | 役割 |
|---|---|
| `tests/Support/TemplateDivergence/EntryMetadata.php` | メタ表 9 行の値を持つ readonly class |
| `tests/Support/TemplateDivergence/ParsedEntry.php` | 見出し + メタ表 + 出現行 |
| `tests/Support/TemplateDivergence/ParsedLedger.php` | 登録一覧 + 明示件数 + 解析時点の違反 |
| `tests/Support/TemplateDivergence/LedgerContext.php` | 基準日 / 固定件数 / パス実在判定 / 根拠実在判定 |
| `tests/Support/TemplateDivergence/DivergenceLedgerParser.php` | Markdown → `ParsedLedger` (純関数) |
| `tests/Support/TemplateDivergence/DivergenceLedgerRules.php` | `ParsedLedger` + `LedgerContext` → 違反一覧 (純関数) |
| `tests/Architecture/TemplateDivergenceLedgerFormatTest.php` | 薄い検査層 (実ファイルを読み、文脈を組み立て、違反が空であることを見る) |
| `tests/Unit/Architecture/DivergenceLedgerRulesTest.php` | 正例 1 + 負例 (規則ごとに 1 本以上) |

### 波及変更

- TypeScript 型定義: なし / API Resource・DTO: なし
- `app/` は 1 行も触らない (検査はテスト層に閉じる)

### 解析の仕様 (`DivergenceLedgerParser`)

1. **囲みコード区画を先に落とす**。台帳で使ってよい囲みは
   **行頭のバッククォート 3 個ちょうど** (` ``` `) だけとし、これでトグルする。
   閉じずに終端したら `P1: 囲みコード区画が閉じていない (解析不能)` を返して
   **それ以上解析しない** (fail-closed)。
   **バッククォート 4 個以上で始まる行と `~~~` で始まる行は違反にする**
   (Markdown の囲み文法を全部は実装しない。実装しないものを黙って読み飛ばすと、
   4 個の囲みで登録を隠せる回避口になるため、書式そのものを 1 種類に絞って明示的に拒否する)
2. **明示行**: 区画外で `^登録エントリ: (\d+) 件$` に一致する行。**ちょうど 1 本**でなければ違反
3. **登録エントリ領域**: 区画外の最初の `^## D` 行から文書末尾まで
4. **見出し**: 領域内の `^## ` 行は次の 2 つを**両方**満たさなければ違反。id の重複も違反
   - `/^## D([1-9]\d*) (\S.*)$/u` に**行全体一致**する
   - 要約に**印と解消を表す語を含まない** — Unicode の「その他の記号」(`\p{So}`。`✅` はここに入る) と
     矢印 `→`、および `解消` / `済み` の 2 語を禁じる。
     **`\p{S}` 全体は禁じない** (見出しに `+` を使っている登録が実在し、正当な書き方だからである)。
     `保留` のような語は禁じない (D7 が正当に使っている)
   - この 2 段にする理由: 行全体一致だけでは `## D1 ✅ Tier B …` が
     「要約が `✅ Tier B …` である正準形」として**通ってしまう** (Codex Round 1 の Critical 指摘。
     設計時点で実際に見落としていた)
5. **メタ表**: 見出しの次の非空行から始まり、`| 行 | 内容 |` のヘッダ・`|---|---|` の区切り・
   **9 本のデータ行**で構成される。ラベル列が規定の 9 語と**順序ごと一致**しなければ違反
   (過不足・順序違いは「台帳を解釈できない」= 不合格)
6. セル値は `|` で分割して trim する。**セルの中に `|` を書いてはならない** (エスケープした
   `\|` も含めて禁止) — 分割後の要素数がちょうど 4 個 (先頭の空・ラベル・値・末尾の空) で
   なければ違反にする。エスケープを解釈する解析器を持たないことを規約側にも書く
   (自由記述で表の区切りを使いたくなったら、その内容は本文の節へ書く)

### 判定の仕様 (`DivergenceLedgerRules`)

| 記号 | 規則 | 出典 |
|---|---|---|
| TD1 | 見出しは正準形・id は一意 | i4 |
| TD2 | メタ表は 9 行ちょうど・ラベルは規定の順序 | i2 |
| TD3 | 対象パスは 1 件以上・リポジトリ相対・glob/絶対/`..` 不可・**`is_file()` で実在**。セルはバッククォート囲みのパスを ` / ` でつないだ形だけを許し、バッククォートの外に空白以外の文字があれば違反 | i5 |
| TD4 | 全登録の対象パスの和集合で重複しない | i5 |
| TD5 | 状態は `恒久` / `監視中` の 2 語 | i3 |
| TD6 | `監視中` は見直し期限必須・実在する日付・**基準日から 400 日以内**・**期限切れは不合格** | i8 |
| TD7 | `恒久` の見直し期限は `—` ちょうど | i8 |
| TD8 | 決めた日は実在する日付で**未来日不可** | i7 |
| TD9 | 決めた人は `オーナー` / `開発者` の 2 語 | i10 |
| TD10 | 根拠は `T\d{3,}` (TODO 台帳の表のセルとして実在) か `devnotes/<dir>/` (`is_dir` で実在)。プレースホルダ不可 | i7 |
| TD11 | 業務要件起因の説明 / 揃え続ける不変条件と保証機構 / 再判定の条件が空でない・プレースホルダでない | i9 |
| TD12 | 明示件数・解析件数・固定件数の 3 点一致 | i6 |

**解析時の違反は必ず伝播させる**。`DivergenceLedgerRules::violations()` は先頭で
`ParsedLedger::$parseViolations` を取り込み、**解析不能 (囲みコード区画が閉じていない /
登録エントリ領域が見つからない) のときはそこで打ち切って返す** (以降の規則は評価できないため、
評価しないことを違反として返す = fail-closed)。握り潰す経路を作らない。

- **プレースホルダの語彙**: `...` / `…` / `TBD` / `未定` / `-` / `—` / 空文字。
  過剰検出寄りに倒す (正典 i15)。**適用先は TD10 (根拠) と TD11 (自由記述 3 欄) だけ**である —
  見直し期限の `—` は `恒久` の正値なので、期限欄にはプレースホルダ検査を掛けない (TD6/TD7 だけを掛ける)
- **根拠の実在判定は境界付きで行う**。`T\d{3,}` は `docs/TODO.md` / `docs/TODO-closed.md` の
  **表のセルとして** (`/^\|\s*T0*\d+\s*\|/m` に `preg_quote` した値を埋めた形で) 照合する。
  素の `str_contains` は `T1` が `T10` に一致して通るので使わない。
  `devnotes/<dir>/` は **`is_dir()`** で別に判定する (ファイルの実在判定と混ぜない)
- **日付は往復で検証し、解析失敗を型で締める**。`createFromFormat()` は失敗時に `false` を返しうるので、
  戻り値を型で確かめてから往復比較する (`Carbon::parse()` は `2026-02-30` を 3/2 へ正規化して通す)。
  例外に倒さず**違反一覧へ入れる**:

  ```php
  /** `YYYY-MM-DD` として実在する日付だけを受け取る (失敗は null を返し、呼び出し側が違反にする)。 */
  private static function parseDate(string $value): ?CarbonImmutable
  {
      $date = CarbonImmutable::createFromFormat('!Y-m-d', $value);

      if (! $date instanceof CarbonImmutable || $date->format('Y-m-d') !== $value) {
          return null;
      }

      return $date;
  }
  ```
- **許可一覧を持たない**。個別の D 番号を名指しして規則を免除する仕組みは作らない

### 変更後コード (骨子)

```php
<?php

declare(strict_types=1);

namespace Tests\Support\TemplateDivergence;

use Closure;
use Carbon\CarbonImmutable;

/**
 * 形式検査の文脈 (基準日と実在判定の注入点)。
 *
 * 基準日を引数で受け取るのは、期限判定を純関数に保ち単体テストが実行日で揺れないようにするため。
 */
final readonly class LedgerContext
{
    /**
     * @param  Closure(string): bool  $pathExists  リポジトリ相対の**ファイル**の実在判定 (is_file)
     * @param  Closure(string): bool  $directoryExists  リポジトリ相対の**ディレクトリ**の実在判定 (is_dir)
     * @param  Closure(string): bool  $rationaleExists  根拠 (T 番号) が TODO 台帳の表に実在するか
     */
    public function __construct(
        public CarbonImmutable $baseDate,
        public int $pinnedEntryCount,
        public Closure $pathExists,
        public Closure $directoryExists,
        public Closure $rationaleExists,
    ) {}
}
```

```php
/** 登録メタ表 9 行の値 (生文字列のまま持ち、妥当性は Rules が見る)。 */
final readonly class EntryMetadata
{
    /** @param list<string> $targetPaths */
    public function __construct(
        public array $targetPaths,
        public string $rawTargetPathCell,
        public string $domainReason,
        public string $invariantAndGuard,
        public string $reevaluationCondition,
        public string $decidedOn,
        public string $decidedBy,
        public string $rationale,
        public string $state,
        public string $reviewDeadline,
    ) {}
}
```

```php
/**
 * 逸脱の登録簿の形式違反を列挙する (純関数)。
 *
 * **保証しない範囲** (誇張しない):
 *  - 実ファイルがテンプレートから逸脱したのに登録が無いことは検出しない
 *    (実体との突合は台帳リポジトリの巡回が持つ。家系の裁定 AG-159 / 正典 i13)
 *  - 内容がテンプレート準拠へ戻った登録の残置も検出しない (対象パスは実在し続けるため)
 *  - 登録の中身が正しいことは見ない (空でないことだけを見る)
 *  - 登録エントリ領域より前の節と、エントリの中の `###` 見出しは見ない
 *
 * 固定件数 (`LedgerContext::$pinnedEntryCount`) は**明示件数との同期検査**であって、
 * 例外を許す一覧ではない。個別の D 番号を名指しする許可一覧は持たない。
 */
final class DivergenceLedgerRules
{
    /** @return list<string> 違反一覧 (空 = 合格) */
    public static function violations(ParsedLedger $ledger, LedgerContext $context): array { /* … */ }
}
```

```php
// tests/Architecture/TemplateDivergenceLedgerFormatTest.php (薄い検査層)

/** 登録件数の固定値。明示件数との同期検査であって例外の許可一覧ではない。 */
const TEMPLATE_DIVERGENCE_ENTRY_COUNT = 17;

test('TD0: 登録簿を読んで解析できること (実行不能は不合格)', function (): void {
    $markdown = file_get_contents(base_path('docs/template-divergence.md'));
    Assert::string($markdown, 'docs/template-divergence.md を読めない');
    expect(trim($markdown))->not->toBe('');
});

test('TD1〜TD12: 登録簿が統一形式を満たすこと', function (): void {
    $ledger = DivergenceLedgerParser::parse(templateDivergenceMarkdown());
    $violations = DivergenceLedgerRules::violations($ledger, new LedgerContext(
        baseDate: CarbonImmutable::today(),
        pinnedEntryCount: TEMPLATE_DIVERGENCE_ENTRY_COUNT,
        pathExists: fn (string $path): bool => is_file(base_path($path)),
        directoryExists: fn (string $path): bool => is_dir(base_path($path)),
        // T 番号は TODO 台帳の表のセルとして境界付きで照合する (T1 が T10 に一致しないように)
        rationaleExists: fn (string $ref): bool => preg_match(
            '/^\|\s*'.preg_quote($ref, '/').'\s*\|/mu',
            templateDivergenceTodoSources(),
        ) === 1,
    ));

    expect($violations)->toBe([], "逸脱の登録簿の形式違反:\n".implode("\n", $violations));
});
```

### PHPStan適合チェック

- [x] 戻り値の型が明示されている (`list<string>` / `ParsedLedger`)
- [x] null 安全 — `file_get_contents` の戻り値は `Webmozart\Assert\Assert::string()` で締める
- [x] 解析失敗を空配列へ落とさない (違反として返すか例外に倒す)
- [x] Generics — `Closure(string): bool` を phpdoc で明示
- [x] DTO を返している (配列の裸返しをしない。`ParsedEntry` / `EntryMetadata` は readonly class)

### テスト計画

**先に検査を書き、赤を見てから移行する** (テストファースト)。

- [ ] 正例: 統一形式を満たす最小の fixture で違反が 0 件になること
- [ ] 負例 TD1a: 見出しに `✅` が付いていると落ちること
- [ ] 負例 TD1b: 見出しの階層を 1 段下げる (`### D1 …`) と登録として数えられず件数が合わずに落ちること
- [ ] 負例 TD1c: id が重複すると落ちること
- [ ] 負例 TD2a: メタ表が 8 行 / 10 行だと落ちること
- [ ] 負例 TD2b: ラベルの順序を入れ替えると落ちること
- [ ] 負例 TD3a: 対象パスが 0 件だと落ちること
- [ ] 負例 TD3b: glob (`app/Models/*.php`) / 絶対パス / `..` が落ちること
- [ ] 負例 TD3c: 実在しないパスが落ちること
- [ ] 負例 TD4: 2 つの登録が同じパスを挙げると落ちること
- [ ] 負例 TD5: 状態が `解消済み` だと落ちること
- [ ] 負例 TD6a: `監視中` に見直し期限が無いと落ちること
- [ ] 負例 TD6b: 見直し期限が基準日から 401 日先だと落ちること
- [ ] 負例 TD6c: 見直し期限が基準日より前 (期限切れ) だと落ちること
- [ ] 負例 TD7: `恒久` に日付の見直し期限が書いてあると落ちること
- [ ] 負例 TD8: 決めた日が未来日 / 実在しない日付 (`2026-02-30`) / 空文字 / `not-a-date` だと
      **例外ではなく違反一覧に入って**落ちること (TD6 の見直し期限も同じ 4 ケースを固定する)
- [ ] 負例 TD9: 決めた人が `チーム` だと落ちること
- [ ] 負例 TD10a: 根拠が実在しない `T9999` だと落ちること
- [ ] 負例 TD10b: 根拠が `TBD` だと落ちること
- [ ] 負例 TD11: 再判定の条件が空 / `...` だと落ちること
- [ ] 負例 TD12a: 明示件数と解析件数が食い違うと落ちること
- [ ] 負例 TD12b: 固定件数と食い違うと落ちること (増えても減っても)
- [ ] 負例 TD10c: `T1` が `T10` の登録に一致して通らないこと (境界付き照合の正のコントロール)
- [ ] 負例 TD10d: 根拠に実在しない `devnotes/9999-nope/` を書くと落ちること
- [ ] 負例 TD3d: 対象パスにディレクトリを書くと落ちること (`is_file` であることの確認)
- [ ] 負例 TD3e: 対象パスのセルにバッククォート外の説明文を添えると落ちること
- [ ] 負例 P1: 囲みコード区画が閉じていない fixture が「解析不能」で落ち、**以降の規則を評価せずに返すこと**
- [ ] 負例 P2: 登録エントリ領域が見つからない (見出しが 1 件も無い) fixture が落ちること
- [ ] 負例 P3: バッククォート 4 個の囲み / `~~~` の囲みが**明示的に拒否**されること
      (黙って読み飛ばさないこと)
- [ ] 負例 P4: メタ表のセルに `|` や `\|` を書くと違反になること
- [ ] 負のコントロール: **囲みコード区画の中の記入例 (`## D1 <要約>`) を登録として数えないこと**
- [ ] 負のコントロール: 登録エントリ領域より**前**の `## 記録の原則` 等の節は違反にならないこと
- [ ] 実挙動: 現物の `docs/template-divergence.md` が違反 0 件であること (Architecture レーン)
- [ ] 期限判定は固定日を渡して検証する (実行日で揺れないこと)

### リスク

- 検査が厳しすぎて正当な書き方を弾く。→ 負例と**正例**の両方を単体テストで固定し、
  正例が緑であることで書ける形を明示する
- 期限切れが将来 CI を赤にする。→ 仕様。直し方は登録簿の規約節の 4 通り。検査は緩めない

