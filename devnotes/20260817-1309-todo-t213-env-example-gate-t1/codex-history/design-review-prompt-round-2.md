Round 1 の指摘に対する対応を報告する。全体判定と施策別判定を再度出してほしい。

## 対応マトリクス (Round 1)

# 対応マトリクス: design-review Round 1

## [Critical] 反証表 R10 が解析器の仕様と矛盾する (`A= 1` は合法)

- 判断: 対応する (指摘が正しい)
- 根拠: 正規表現 `^([A-Z][A-Z0-9_]*)=(.*)$` は `A= 1` を受理し、値は `' 1'` になる。
  R14 が「値を trim しない」を固定している以上、**等号の後ろの空白は値の一部**である。
  R10 が同じ形を形式違反にすると、実装と反証が同時には成立しない。
- 対応内容: 指摘の修正案のうち **「等号の前の空白は禁止、等号の後ろは値の一部」** を採る
  (解析器の正規表現を変えない = 正典との差を増やさない)。
  1. R10 を `A =1` だけにした。
  2. R14 の隣に注記を足し、「等号の後ろの空白は違反ではなく値の一部である」ことを
     反証表の中で明示した。
  3. 概念設計の 2 か所 (追従の中身 3 / 保証しないもの) の
     「等号の前後に空白を置いた代入」→「**等号の前**に空白を置いた代入」へ訂正した。

## [Warning] B2 の期待結果が実装仕様と食い違う

- 判断: 対応する (指摘が正しい)
- 根拠: 解析器は先勝ちで記録するので、末尾に `SESSION_ENCRYPT=false` を足しても
  `values['SESSION_ENCRYPT']` は `'true'` のままで、値の固定 (a) は赤くならない。
  赤くなるのは重複の検査 (c-2) だけである。
- 対応内容: B2 の期待を **c-2 だけ**に直し、値の固定も赤にする破壊として
  **B2b (元の行の値を `false` に書き換える)** を別に足した。
  あわせて B2 の欄に「**この食い違いこそが重複を 1 件も許さない理由**である
  (解析器は先勝ち・dotenv は後勝ちなので、重複があると値の固定は実効値ではない値を見る)」を書いた。

## [Warning] AC5 が「変更ファイルは 1 本」の前提と衝突する

- 判断: 対応する
- 根拠: 曖昧さの指摘は正しい。本リポジトリでは **`devnotes/` はコミット対象**
  (AGENTS.md「設計・TODO・devnotes の運用」: 議論履歴をコミット対象に含める) であり、
  「変更ファイル 1 本」は **`devnotes/` を除いた実装差分**の話である。
- 対応内容: 受け入れ条件の冒頭に「AC2 の『1 本』は `devnotes/` を除いた実装差分を指す。
  `devnotes/` 配下 (設計 2 本・レビュー履歴・赤の証跡) はコミットする」という
  前置きを足し、AC5 の文言も「コミットされた証跡ファイル」と明示した。

## [Warning] AC6 の `rg` 正規表現が誤り (`\|` はリテラル)

- 判断: 対応する (指摘が正しい)
- 対応内容: `rg -n -e 'ENV_EXAMPLE_' -e 'function envExample' tests/` へ直した。
  実装方針の中の同じコマンドも直した。

## [Warning] 段 1 の fail-first が「undefined function で赤」だけでは弱い

- 判断: 対応する
- 根拠: 関数が無ければ全件赤になるのは当然で、個々の反証が実際にバグを捕まえるかを示さない。
- 対応内容: 段 1 を 2 つに割った。
  - **段 1a**: 反証の表だけを先に書き、関数が未定義で全件赤になることを確認する
    (テストが実行経路に乗っていることの確認)。
  - **段 1b (新設)**: わざと穴のある解析器を 3 通り一時的に置き、
    対応する反証の行が**個別に**赤くなることを実測する
    (コメント行を飛ばさない → R1 が赤 / 重複を無視して後勝ちで上書きする → R4 が赤 /
    形式違反を返さない → R7〜R12 が赤)。stub は記録を取ったら捨てる。
  加えて総評の助言どおり、**段 2 (見本を壊して赤を見る) を主証跡**と位置づけ直した。

## [Suggestion] AC4 の件数確認が人の目視に見える

- 判断: 対応する
- 対応内容: `--log-junit` で機械出力を取り、`testcase` 要素を数える形へ直した。
  数え上げの内訳 (台帳 5 + `${VAR}` 1 + 反証の表の行数) も書き、
  表を増やすときは受け入れ条件の数も一緒に直すと明記した。


---

## 修正後の詳細設計 (全文)

# 詳細設計: 環境見本ファイルの検査を AG-007 の統合形 (台帳の版の呼び名は t1) へ追従させる (aicue:T213)

## 使命・制約 (絶対遵守)

### アプリの使命 (North Star)

**AI-CUE** は、現場に既にある**作業手順書(SOP)を起点に**、AI が撮るべきカットを設計した
**動画シナリオ**を生成し、そのシナリオを**スマホ(PWA)でナビゲーション撮影**することで、
専門知識ゼロの現場作業者でも**標準化されたマニュアル動画**を作れるようにする。

- 「思考ゼロ・編集ゼロ」— 台本作成・撮影判断・編集の 3 ハードルを AI とナビ撮影で肩代わりする。
- 競合(OJT を撮って形式化する tebiki)と異なり、**標準作業を起点に AI が教材設計し撮影を指示する**
  (撮影者・教える人のスキルに品質を依存させない)。
- 熟練者の暗黙知を動画マニュアルという形式知へ変換する装置(SECI)。

### 禁止事項 (AGENTS.md)

1. テストなしの実装完了報告
2. PHPStan エラーの widen・baseline 化
3. dev DB への破壊操作をエージェント判断で実行すること
4. `response()->json()` の直書き
5. LLM 呼び出しの Prism 直呼び
6. prompt 文字列のコード直書き
7. 操作系 POST の応答での `redirect()->intended()`
8. 必須条件未充足を理由にボタンを disabled にする UI
9. Artifact の使用 (成果物はリポジトリ内のファイルとして出力する)

加えて設計フロー側の禁止: **既存テストの削除・上書き** / **やたらに複雑な案を提案する**。

本 TODO はアプリの実行コードを 1 行も触らないため 3〜8 は非該当。
1 (テストなしの完了報告) と「既存テストの削除・上書き」が本設計の主な制約である。

### コーディングルール

- **PHPStan level 10** 必須 (`composer phpstan`)。ただし `phpstan.neon` の paths は
  app / config / database / routes であり **`tests/` は解析対象外**。
  本設計は将来 tests を解析対象へ入れても通る書き方 (戻り値 shape の phpdoc・
  `array_map` + closure を避けて `foreach` で組む) を採る。
- **Pest** テストフレームワーク (`composer test`)。
- **RefreshDatabase** はグローバル適用済み。本設計の検査は DB を使わない。
- **コードフォーマット**: `vendor/bin/pint --test` (Laravel preset。`pint.json` は無い)。
- PHP 8.4 + Laravel 12。

## 概念設計リファレンス

`devnotes/20260817-1309-todo-t213-env-example-gate-t1/conceptual-design.md`

## 施策一覧

| # | 施策名 | 変更ファイル | 優先度 |
|---|--------|------------|--------|
| 1 | 解析器の分離 (純粋関数 + ファイル読み取りのアダプタ) と反証の検査 | `tests/Architecture/EnvExampleInvariantTest.php` | High |
| 2 | 値の固定の台帳化 (行の完全一致) | 同上 | High |
| 3 | キー網羅の台帳 (4 分類 36 件) | 同上 | High |
| 4 | 行の形式の検査 / 重複の検査 | 同上 | High |
| 5 | 台帳の誠実性の検査 | 同上 | High |
| 6 | 既存の t0 の検査 5 本の置換と、`${VAR}` 検査の温存 | 同上 | High |

**施策 1〜6 は同一ファイルの 1 回の書き換えで入る。分割してコミットしない**
(途中の状態で「値の固定が消えているのに新しい台帳がまだ無い」時間帯を作らないため)。

## 変更ファイル一覧

### 新規

なし。

### 変更

| ファイル | 変更内容 |
|---|---|
| `tests/Architecture/EnvExampleInvariantTest.php` | 全面書き換え (107 行 → 概算 250 行前後) |

### 削除

なし。

### 変更しないと決めているファイル (受け入れ条件で機械確認する)

- `.env.example` / `.env.testing` / `.env.bughunt.local.example`
- `app/` 配下すべて (`app/Support/ProductionEnvGuard.php` を含む)
- `config/` 配下すべて
- `docs/` 配下 (本 TODO は AGENTS.md / docs の記述を増やさない。理由は下記)

> **docs を触らない理由**: 本 TODO が足すのは既存の不変条件テストの強度であり、
> 新しい規約・新しい運用契約を作らない。AGENTS.md にも `docs/architecture.md` にも
> 「見本ファイルの検査」の節は現在無く、節を新設すると **同じ事実 (台帳の中身) が
> テストと文書の 2 か所に載る**。台帳の正本はテストファイルの定数だけにする。

## 施策 1: 解析器の分離と反証の検査

### 変更箇所

`tests/Architecture/EnvExampleInvariantTest.php` (全体)

### 波及変更

- TypeScript 型定義: なし
- API Resource / DTO: なし
- テストファイル: 本ファイルのみ (他のテストからこのファイルの関数・定数を参照している箇所は無い)
- 実行コード: なし

### 現行コード (抜粋)

```php
test('.env.example に SESSION_SECURE_COOKIE=true が含まれる', function (): void {
    $contents = file_get_contents(base_path('.env.example'));
    expect($contents)->toBeString();
    /** @var string $contents */
    expect($contents)->toContain('SESSION_SECURE_COOKIE=true');
});
```

### 変更後コード

```php
/**
 * 見本ファイルの本文を行単位で解析する (**純粋関数**。ファイルを読まない)。
 *
 * 行の分類:
 *   - 空白だけの行     → 実効値に影響しないので飛ばす
 *   - `^\s*#` の行     → コメント。同上
 *   - それ以外         → 素の代入行 `^[A-Z][A-Z0-9_]*=` **のみ**受理する
 *
 * ★これは dotenv の構文検査ではない。dotenv は `export FOO=1` も小文字のキーも読むが、
 *   本リポジトリの見本ファイルではそれらを許さない (存在検査・重複検査の母集合から
 *   外れたまま実効値だけを変えられる迂回になるため)。「見本に許す最小の書式」である。
 *
 * ★重複キーの値は **最初に現れた方**を記録する。dotenv は同一ファイル内の重複を
 *   **後に現れた方**で解決する (実測: vlucas/phpdotenv の immutable / mutable の双方で
 *   後勝ち)。両者は食い違うので、重複が 1 件でもあると値の固定の検査は
 *   「実効値ではない値」を見ることになる。だから重複そのものを違反にする
 *   (どちらの解決順に合わせるかを選ばない)。
 *
 * 改行は CRLF / CR / LF のいずれでも行に割る (行末に CR を残さない)。
 * 値は前後の空白を落とさない (見本に書いてあるとおりを返す)。
 *
 * @return array{
 *   values: array<string, string>,
 *   duplicateKeys: list<string>,
 *   malformedLineNumbers: list<int>,
 * }
 */
function envExampleParseContents(string $contents): array
{
    $lines = preg_split('/\r\n|\r|\n/', $contents);
    expect($lines)->toBeArray();
    /** @var list<string> $lines */
    $values = [];
    $duplicateKeys = [];
    $malformedLineNumbers = [];

    foreach ($lines as $index => $line) {
        if (trim($line) === '') {
            continue;
        }
        if (preg_match('/^\s*#/', $line) === 1) {
            continue;
        }
        if (preg_match('/^([A-Z][A-Z0-9_]*)=(.*)$/', $line, $matches) !== 1) {
            $malformedLineNumbers[] = $index + 1;

            continue;
        }
        $key = $matches[1];
        if (array_key_exists($key, $values)) {
            // 同じキーが 3 回以上でも、重複の一覧にはキー名を 1 度だけ載せる (診断の安定)。
            if (! in_array($key, $duplicateKeys, true)) {
                $duplicateKeys[] = $key;
            }

            continue;
        }
        $values[$key] = $matches[2];
    }

    return [
        'values' => $values,
        'duplicateKeys' => $duplicateKeys,
        'malformedLineNumbers' => $malformedLineNumbers,
    ];
}

/**
 * `.env.example` を読んで解析する (**入出力のアダプタ**。判定は持たない)。
 *
 * @return array{
 *   values: array<string, string>,
 *   duplicateKeys: list<string>,
 *   malformedLineNumbers: list<int>,
 * }
 */
function envExampleParse(): array
{
    $contents = file_get_contents(base_path('.env.example'));
    expect($contents)->toBeString();
    /** @var string $contents */

    return envExampleParseContents($contents);
}
```

**分離する理由**: 見本ファイルは現に適合しているため、台帳駆動の 4 本は書いた瞬間に緑になる。
それでは「壊れたら赤くなる」ことを誰も確かめていない。純粋関数にしておけば、
**壊した入力を合成して食わせる反証の検査を恒久で置ける** (見本ファイルを実際に壊さずに済む)。
これが本設計のテストファーストの土台である。

### 反証の検査 (データ駆動)

`Pest` の `->with()` で以下の表を回す。各行は「合成した本文」→「期待する解析結果」。

| # | 合成した本文 | 期待 | 何を固定するか |
|---|---|---|---|
| R1 | `# SESSION_SECURE_COOKIE=true` | `values` が空 | **コメント偽装**。t0 の部分一致はこれを通していた |
| R2 | `   # コメント` (先頭に空白) | `values` が空・形式違反 0 件 | 字下げしたコメントを違反にしない |
| R3 | `A=1` + 空行 + `B=2` | `values` = A,B / 形式違反 0 件 | 正常系の下限 |
| R4 | `A=1\nA=2` | `duplicateKeys` = `['A']`、`values['A']` = `'1'` | **重複の検出**と、解析器が**先勝ち**で記録すること |
| R5 | `A=1\nA=2\nA=3` | `duplicateKeys` = `['A']` (1 件) | 3 回以上でも一覧は 1 件 |
| R6 | `A=1\nB=2\nA=3\nB=4` | `duplicateKeys` = `['A','B']` | 複数キーの重複を取りこぼさない |
| R7 | `export A=1` | 形式違反 1 行目 | `export` つきの迂回 |
| R8 | `  A=1` (先頭に空白) | 形式違反 1 行目 | 字下げした代入の迂回 |
| R9 | `a=1` (小文字のキー) | 形式違反 1 行目 | 小文字のキーの迂回 |
| R10 | `A =1` (等号の**前**の空白) | 形式違反 1 行目 | 等号の前の空白。**等号の後ろの空白は違反にしない** — そこは値の一部であり R14 が固定する |
| R11 | `--- 区切り ---` | 形式違反 1 行目 | 素の区切り線 |
| R12 | `1A=1` (数字始まりのキー) | 形式違反 1 行目 | キーの先頭は英大文字だけ |
| R13 | `A=1\r\nB=2` (CRLF) | `values['A']` = `'1'` (CR を含まない) | 行末の CR を残さない |
| R14 | `A= 1 ` (値の前後に空白) | `values['A']` = `' 1 '` かつ **形式違反 0 件** | 値を trim しない (見本のとおりを返す)。**等号の後ろの空白が値の一部である**ことを R10 と対にして固定する |
| R15 | `A=1\nexport B=2\nc=3` | 形式違反が 2 行目と 3 行目 | **行番号が 1 始まりで正しい** |
| R16 | 空文字列 | すべて空 | 端 (空ファイル) で落ちない |

> Round 2 の助言にある 4 種は R1 (コメント偽装) / R4 (重複と解決順) / R7〜R12 (不正書式) が
> 受け持つ。「前勝ち / 後勝ち」は R4 で **解析器が先勝ち**であることを固定し、
> **dotenv が後勝ち**であることは docblock に実測として書く
> (テストで dotenv の挙動そのものを固定しにいかない — vendor の挙動は本 gate の責務ではない。
> 重複を 1 件も許さないので、どちらが勝つかに設計が依存しない)。

### PHPStan 適合チェック

- [x] 戻り値の型が明示されている (array shape を phpdoc で固定)
- [x] null 安全 (`file_get_contents` の戻り値を `expect()->toBeString()` で確定させる。
      正典と同じ形)
- [x] DTO を返している — **非該当** (テストの補助関数であり、アプリの層をまたがない)
- [x] Generics の型パラメータ — `list<string>` / `list<int>` / `array<string, string>` を明示
- [x] `array_map` + closure を使わない (引数のネイティブ型が `array<mixed, mixed>` になり
      level 10 で落ちうるため。`foreach` で組む)

### テスト計画

- [x] 反証の検査 16 件を **先に書く** (関数が未定義なので必ず赤になる)
- [x] 個別の `DatabaseTransactions` を使っていない

### リスク

- 反証の表が増えるとファイルが長くなる。ただしこれは検査の実体であり、
  「壊れたら赤くなる」ことを証明する唯一の手段なので削らない。

## 施策 2: 値の固定の台帳化

### 変更後コード

```php
/**
 * 値の固定: 裁定 AG-007 が名指しする 2 件。
 * 緩めるには家系の機能台帳側の裁定変更が要る (本リポジトリ単独では動かせない)。
 *
 * ★形式はキーと値の組の**リスト**にする (キー付きの連想配列にしない)。
 *   連想配列のリテラルは同じ定数の中の重複キーをコンパイル時に後勝ちで無音に潰すため、
 *   「行を足しただけに見える差分」で既存の固定を反転できてしまう。
 *   リストなら重複がそのまま残り、下の誠実性の検査が同じ機構で捕まえられる。
 */
const ENV_EXAMPLE_VALUE_PINS_AG007_CORE = [
    ['key' => 'SESSION_SECURE_COOKIE', 'value' => 'true'],
    ['key' => 'SESSION_ENCRYPT', 'value' => 'true'],
];

/**
 * 値の固定: 本リポジトリ固有の追加 (裁定で必須とされたものではない純増。個別に理由を書く)。
 * - ADMIN_MFA_REQUIRED=true: false にすると管理画面の二要素が実質無効になる。
 *   local の値が本番へ写る事故の側が危険なので、見本は安全側で固定する。
 * - MCP_STRICT_TRANSPORT=true: false にすると Origin を送らないクライアントを受け入れる
 *   (DNS 再バインドの面が広がる)。
 */
const ENV_EXAMPLE_VALUE_PINS_AICUE = [
    ['key' => 'ADMIN_MFA_REQUIRED', 'value' => 'true'],
    ['key' => 'MCP_STRICT_TRANSPORT', 'value' => 'true'],
];

/**
 * 値の固定の台帳の合成 (重複した組を保持したまま連結する)。
 *
 * @return list<array{key: string, value: string}>
 */
function envExampleValuePinEntries(): array
{
    return array_merge(ENV_EXAMPLE_VALUE_PINS_AG007_CORE, ENV_EXAMPLE_VALUE_PINS_AICUE);
}

test('a: .env.example は安全側の既定値を行の完全一致で満たす', function (): void {
    $parsed = envExampleParse();

    // 失敗時に出すのは**キー名だけ**である (見本の実値を出力しない)。
    $violations = [];
    foreach (envExampleValuePinEntries() as $entry) {
        if (($parsed['values'][$entry['key']] ?? null) !== $entry['value']) {
            $violations[] = $entry['key'];
        }
    }

    expect($violations)->toBe([]);
});
```

### 現状との突き合わせ (実測)

| キー | `.env.example` の実値 | 判定 |
|---|---|---|
| `SESSION_SECURE_COOKIE` | `true` (35 行目) | 緑 |
| `SESSION_ENCRYPT` | `true` (32 行目) | 緑 |
| `ADMIN_MFA_REQUIRED` | `true` (223 行目) | 緑 |
| `MCP_STRICT_TRANSPORT` | `true` (122 行目) | 緑 |

### リスク

- `MCP_STRICT_TRANSPORT` は MCP 面を廃止したら見本から消えうる。そのとき赤になるが、
  それは「台帳から外す」という判断がレビューに乗る形なので意図どおりである。

## 施策 3: キー網羅の台帳 (4 分類 36 件)

### 変更後コード

```php
/**
 * キー網羅の台帳。分類ごとに定数を分ける (平らな 1 本の配列にしない)。
 * 削るときに「どの根拠を外すのか」がレビューで見えるようにするためである。
 *
 * ★台帳は**床**であって天井ではない。`.env.example` に任意のキーを足すことは責務外で、
 *   完全一致の集合にはしない (正典と同じ判断)。
 *
 * (i) 新しい環境を立てるときに要る座標。`composer setup` と
 *     `scripts/setup-worktree.sh` の案内が `.env.example` をそのまま `.env` にするため、
 *     ここが欠けると「動かない .env」が出来上がる。
 */
const ENV_EXAMPLE_REQUIRED_KEYS_SETUP = [
    'APP_NAME',
    'APP_ENV',
    'APP_KEY',
    'APP_URL',
    'APP_LOCALE',
    'DB_CONNECTION',
    'SESSION_DRIVER',
    'QUEUE_CONNECTION',
    'CACHE_STORE',
];

/**
 * (ii) 本番の起動時に検査される座標のうち、**現在 `.env.example` に素の代入行として
 *      提示済みのもの**。正本は app/Support/ProductionEnvGuard.php で、依存は一方向である
 *      (guard が変われば本台帳が古くなる。機械では結線しない — 理由は概念設計のスコープ外 9)。
 *
 * ★これは guard の要求の**写しではない**。guard は SECURITY_HSTS_ENABLED /
 *   SECURITY_CSP_ENABLED も本番で true と要求するが、この 2 つは `.env.example` に
 *   1 行も無く、載せるには見本の書き方の判断が要るため本台帳には入れない
 *   (概念設計のスコープ外 3。**この 2 件の欠落は検出しない**)。
 *
 * ★SESSION_SECURE_COOKIE / ADMIN_MFA_REQUIRED 等は値の固定の台帳が値ごと押さえるため
 *   ここには載せない (台帳をまたぐ二重登録は下の誠実性の検査が禁じる)。
 */
const ENV_EXAMPLE_REQUIRED_KEYS_PRODUCTION_GUARD = [
    'CIPHERSWEET_KEY',
    'STRIPE_WEBHOOK_SECRET',
    'DEBUG_LOGIN_USER',
    'DEBUG_LOGIN_PASSWORD',
    'PRIMARY_HOST',
    'TRUSTED_HOSTS_ADDITIONAL',
    'TRUSTED_HOSTS_WILDCARD_SUFFIXES',
    'TRUSTED_PROXIES',
    'PASSKEYS_USER_HANDLE_SECRET',
];

/**
 * (iii) 提示が無いと環境ごとに別の名前が発明されて食い違う座標
 *       (外部との統合の秘密と、アプリ固有の座標)。
 */
const ENV_EXAMPLE_REQUIRED_KEYS_INTEGRATION = [
    'STRIPE_KEY',
    'STRIPE_SECRET',
    'OPENAI_API_KEY',
    'ANTHROPIC_API_KEY',
    'GEMINI_API_KEY',
    'GOOGLE_CLIENT_ID',
    'GOOGLE_CLIENT_SECRET',
    'RECAPTCHA_SITE_KEY',
    'RECAPTCHA_SECRET_KEY',
    'MCP_ALLOWED_ORIGINS',
    'PASSPORT_PRIVATE_KEY',
    'PASSPORT_PUBLIC_KEY',
    'TEMPLATE_APP_SLUG',
    'LEGAL_CONSENT_VERSION',
];

/**
 * (iv) 撮影テイクとレンダ成果物の保管先。本リポジトリ固有の分類である。
 *      撮影 PWA は presigned URL で直接アップロードし、合成した動画も同じ保管先へ置く。
 *      ここが欠けた環境では**撮った映像を保存できない** = 使命の中核が動かない。
 */
const ENV_EXAMPLE_REQUIRED_KEYS_OBJECT_STORAGE = [
    'AWS_ACCESS_KEY_ID',
    'AWS_SECRET_ACCESS_KEY',
    'AWS_DEFAULT_REGION',
    'AWS_BUCKET',
];

/**
 * キー網羅の台帳の合成 (4 分類の連結)。
 *
 * @return list<string>
 */
function envExampleRequiredKeys(): array
{
    return array_merge(
        ENV_EXAMPLE_REQUIRED_KEYS_SETUP,
        ENV_EXAMPLE_REQUIRED_KEYS_PRODUCTION_GUARD,
        ENV_EXAMPLE_REQUIRED_KEYS_INTEGRATION,
        ENV_EXAMPLE_REQUIRED_KEYS_OBJECT_STORAGE,
    );
}

test('b: .env.example は必須キーの台帳を網羅する', function (): void {
    $parsed = envExampleParse();

    $missing = array_values(array_diff(envExampleRequiredKeys(), array_keys($parsed['values'])));

    expect($missing)->toBe([]);
});
```

### 現状との突き合わせ (実測)

`.env.example` の素の代入キーは **81 件**。上の 36 件はすべてその中にある
(本設計の作成時に、正典と同じ解析規則で数え上げて確認した)。
**台帳化で赤くなる既存キーは 1 件も無い。**

### リスク

- キーを消すたびに赤くなる = 摩擦が増える。これは意図した摩擦である
  (消してよいかの判断をレビューに乗せる)。
- 台帳が `ProductionEnvGuard` に自動追随しない。guard に新しい必須項目が増えたとき、
  本台帳は静かに古くなる。**これは保証しないものとして明記する**
  (機械で結ぶには config ファイルの構文解析が要り、今必要な範囲を超える)。

## 施策 4: 行の形式の検査 / 重複の検査

### 変更後コード

```php
test('c-1: .env.example の非空・非コメント行は素の代入行 (KEY=) だけである', function (): void {
    $parsed = envExampleParse();

    // `export` つき・先頭に空白がある代入・小文字のキー・等号の**前**の空白は、
    // 存在検査と重複検査の母集合から外れたまま実効値だけを変えられる迂回になるので、
    // 行の形ごと禁じる。等号の**後ろ**の空白は値の一部なので違反にしない。
    // ★これは dotenv の構文検査ではない (dotenv はこれらを読む)。
    //   「本リポジトリの見本ファイルに許す最小の書式」である。
    expect($parsed['malformedLineNumbers'])->toBe([]);
});

test('c-2: .env.example の代入キーは一意である (重複で値の固定を無音で覆せなくする)', function (): void {
    $parsed = envExampleParse();

    expect($parsed['duplicateKeys'])->toBe([]);
});
```

### リスク

- 将来 `.env.example` に区切り線やアスキーアートを書きたくなると赤になる。
  `#` で始めれば通るので実害は小さい (現在の見本も分類の見出しをすべて `# ---` で書いている)。

## 施策 5: 台帳の誠実性の検査

### 変更後コード

```php
test('台帳の誠実性: 値の固定とキー網羅の二重登録・台帳の中の重複が無い', function (): void {
    // 値の固定は存在の検査を含むので、キー網羅への二重登録は台帳の腐敗になる
    // (どちらを緩めたのか追えなくなる)。機械的に禁じる。
    $required = envExampleRequiredKeys();

    $pinKeys = [];
    foreach (envExampleValuePinEntries() as $entry) {
        $pinKeys[] = $entry['key'];
    }

    // 組のリスト形式は重複を保持するので、この一意性の検査 1 本で
    // 台帳の中 (同じ定数の中) と台帳の間 (2 つの定数にまたがる重複) の両方を捕まえられる。
    expect(array_values(array_unique($pinKeys)))->toBe($pinKeys);
    expect(array_values(array_intersect($required, $pinKeys)))->toBe([]);
    expect(array_values(array_unique($required)))->toBe($required);
});
```

### 現状との突き合わせ

値の固定 4 件と必須キー 36 件に重複・交差は無い (本設計作成時に手で突き合わせて確認した)。

## 施策 6: 既存の t0 の検査の置換と `${VAR}` 検査の温存

### 置換の対応表 (検査能力が落ちないことの証明)

| 現行 (t0) の検査 | 置換後 | 強度 |
|---|---|---|
| `toContain('SESSION_SECURE_COOKIE=true')` | 値の固定台帳 (行の完全一致) | **強くなる** (コメント偽装・部分一致・重複を封鎖) |
| `toContain('SESSION_ENCRYPT=true')` | 同上 | **強くなる** |
| `toContain('TRUSTED_PROXIES=')` | キー網羅台帳 (ii) | **強くなる** (コメント行を通さない) |
| `toMatch('/^PASSKEYS_USER_HANDLE_SECRET=/m')` | キー網羅台帳 (ii) | **同等以上** (行頭一致に加え、行の形式と重複も見る) |
| `toContain('TEMPLATE_APP_SLUG=')` | キー網羅台帳 (iii) | **強くなる** |

**5 本すべてが同一以上の強度で吸収される。テストの削除ではなく台帳への置換である**
(禁止事項「既存テストの削除・上書き」に対する説明はこの表が正本)。

### `${VAR}` 検査は無改変で残す

```php
/** @var array<string, array<string, string>> */
const ENV_EXTERNAL_REF_ALLOWLIST = [ /* 現行のまま */ ];

function collectUnresolvedEnvRefs(string $relativePath): array { /* 現行のまま */ }

test('コミット対象 env ファイルに自己参照・前方参照の ${VAR} が無い', function (): void {
    /* 現行のまま。.env.example / .env.bughunt.local.example / .env.testing の 3 枚 */
});
```

**2 つの解析規則が同じファイルに同居することを、ファイル冒頭のコメントで明記する。**

| | 新しい解析器 (`envExampleParseContents`) | `${VAR}` 検査 (`collectUnresolvedEnvRefs`) |
|---|---|---|
| 対象 | `.env.example` の 1 枚だけ | 見本 3 枚 |
| `export` つきの行 | **違反にする** | 意図的に許容する |
| 先頭に空白のある代入 | **違反にする** | 意図的に許容する |
| 見るもの | キーと値・重複・行の形 | 値の中の `${VAR}` の解決可能性 |

受理の規則が逆向きなので **統合しない**。統合すると片方の意図が壊れる
(`.env.bughunt.local.example` 側で将来 `export` つきの行が混ざりうるという前提を、
`.env.example` の厳しさで潰してしまう)。緑のまま動いている検査を書き換える利得も無い。
`.env.example` については厳しい方 (行の形式の検査) が先に赤くなるので、
緩い側の許容は残り 2 枚にしか意味を持たない — この関係もコメントに書く。

### 名前の衝突 (プロセス大域)

Pest は全テストファイルを同じプロセスに読み込むため、file スコープの関数名・定数名は
リポジトリ全体で一意でなければならない (既に `LegalConsentVersionSingleSourceTest.php` が
`LEGAL_CONSENT_ENV_NAME` 等を file スコープに置いている)。本設計が新設する名前:

| 種別 | 名前 | 既存との衝突 |
|---|---|---|
| 定数 | `ENV_EXAMPLE_VALUE_PINS_AG007_CORE` | 無し |
| 定数 | `ENV_EXAMPLE_VALUE_PINS_AICUE` | 無し |
| 定数 | `ENV_EXAMPLE_REQUIRED_KEYS_SETUP` | 無し |
| 定数 | `ENV_EXAMPLE_REQUIRED_KEYS_PRODUCTION_GUARD` | 無し |
| 定数 | `ENV_EXAMPLE_REQUIRED_KEYS_INTEGRATION` | 無し |
| 定数 | `ENV_EXAMPLE_REQUIRED_KEYS_OBJECT_STORAGE` | 無し |
| 関数 | `envExampleParseContents` | 無し |
| 関数 | `envExampleParse` | 無し |
| 関数 | `envExampleValuePinEntries` | 無し |
| 関数 | `envExampleRequiredKeys` | 無し |

実装時に `rg -n -e 'ENV_EXAMPLE_' -e 'function envExample' tests/` で 0 件を確認してから書き始める
(`rg` に `\|` を書くとリテラルの縦棒として扱われて偽の 0 件になるので、`-e` を 2 つ渡す)。

## テストファースト計画 (どのテストを先に赤にするか)

見本ファイルは既に適合しているため、**台帳駆動の 5 本は書いた瞬間に緑になる**。
これでは「fail を確認してから実装に入る」(思考原則 5) を満たさない。
そこで **2 段構え**にする。

### 段 1a: 反証の検査を先に書く (実行経路に乗っていることの確認)

1. `envExampleParseContents` を **まだ書かず**、施策 1 の反証の表 16 件だけを書く。
2. `composer test -- --filter=EnvExampleInvariant` を走らせ、
   **`Call to undefined function envExampleParseContents()` で 16 件が赤**であることを確認する。
3. 出力を `devnotes/20260817-1309-todo-t213-env-example-gate-t1/red-first-evidence.md` に貼る。

これは「テストが実行される」ことの確認にすぎない (全件が同じ理由で赤くなるだけで、
個々の反証がバグを捕まえる証明にはならない)。そこで段 1b を続ける。

### 段 1b: わざと穴のある解析器で、反証が**個別に**赤くなることを見る

解析器を 3 通りの「穴あき版」で一時的に実装し、対応する行だけが赤くなることを実測する
(記録を取ったら捨てる。**コミットしない**)。

| 穴あき版 | 赤くなるはずの行 |
|---|---|
| コメント行を飛ばさない (`^\s*#` の分岐を消す) | R1 (コメント偽装)。R2 も赤になる |
| 重複を無視して後勝ちで上書きする (`array_key_exists` の分岐を消す) | R4 / R5 / R6 |
| 形式違反を返さない (`malformedLineNumbers` を常に空にする) | R7 / R8 / R9 / R10 / R11 / R12 / R15 |

4. 3 通りとも記録したら、正しい解析器を実装して 16 件を緑にする。
5. 台帳の 5 本を書き足す (ここは最初から緑)。

### 段 2 (**主証跡**): 見本を壊して赤を実測する

段 1 は解析器の挙動を固定するが、「台帳駆動の 5 本が `.env.example` の破壊に反応すること」
までは示さない。**fail-first の主証跡はこちらである。**
実装セッション中に **`.env.example` を 6 通りに壊し、対応するテストが実際に赤くなること**を
実測して記録する (壊した内容は必ず元へ戻す)。

| # | 壊し方 | 赤くなるはずの検査 |
|---|---|---|
| B1 | `SESSION_SECURE_COOKIE=true` → `# SESSION_SECURE_COOKIE=true` | a (値の固定) |
| B2 | `SESSION_ENCRYPT=false` の行を**末尾に足す** | **c-2 (重複) だけ**。a は緑のまま |
| B2b | 元の `SESSION_ENCRYPT=true` を `SESSION_ENCRYPT=false` に**書き換える** | a (値の固定) |
| B3 | `TRUSTED_PROXIES=` の行を消す | b (キー網羅) |
| B4 | `AWS_BUCKET=` を `export AWS_BUCKET=` に変える | c-1 (行の形式) と b |
| B5 | `MCP_STRICT_TRANSPORT=true` を `MCP_STRICT_TRANSPORT=false` に変える | a |
| B6 | 台帳側で `TRUSTED_PROXIES` を値の固定とキー網羅の両方に登録する | 台帳の誠実性 |

> **B2 で a が緑のままなのは仕様である。** 解析器は先勝ちで記録するので
> `values['SESSION_ENCRYPT']` は `'true'` のままになる。一方 dotenv は後勝ちで解決するので
> **実効値は `false`** である。この食い違いこそが「重複を 1 件も許さない」理由であり、
> 重複を許すと値の固定は実効値ではない値を見ることになる。
> B2 と B2b を並べて記録することで、この関係を証跡に残す。

記録は `red-first-evidence.md` に「壊し方 → 赤くなった検査名 → 復元の確認 (`git diff --exit-code`)」の
表で残す。**B1 と B2b は現行の t0 の 5 本では緑のまま通る**ことも同じ表に併記する
(塞いだ穴の実体を示す)。

> B6 だけは台帳側を壊すので、`.env.example` には触らない。

## 受け入れ条件 (機械検証可能な形)

> **前置き**: 本リポジトリでは **`devnotes/` はコミット対象**である
> (AGENTS.md「設計・TODO・devnotes の運用」— 議論履歴をコミットに含める)。
> AC2 の「1 本」は **`devnotes/` を除いた実装差分**の話であり、
> `devnotes/20260817-1309-todo-t213-env-example-gate-t1/` 配下
> (概念設計 / 詳細設計 / レビュー履歴 / 赤の証跡) は**コミットする**。

| # | 条件 | 検証コマンド |
|---|---|---|
| AC1 | 見本ファイル 3 枚が 1 バイトも変わっていない | `git diff --exit-code main -- .env.example .env.testing .env.bughunt.local.example` が exit 0 |
| AC2 | `devnotes/` を除いた実装差分が `tests/Architecture/EnvExampleInvariantTest.php` のちょうど 1 本 | `git diff --name-only main -- . ':!devnotes'` の出力がその 1 行だけ |
| AC3 | 対象テストが全件緑 | `composer test -- --filter=EnvExampleInvariant` が exit 0 |
| AC4 | 対象テストの件数が **22** である (台帳駆動 5 + `${VAR}` 1 + 反証の表の行数 16) | 下記の junit カウント。**反証の表を増減させたらこの数も一緒に直す** |
| AC5 | コミットされた `red-first-evidence.md` に、段 1a の赤・段 1b の 3 通り・段 2 の 7 行 (B1 / B2 / B2b / B3 / B4 / B5 / B6) の記録がある | ファイルの存在と表の行数 |
| AC6 | 新設した名前が 10 件ちょうどで、他ファイルと衝突していない | `rg -n -e 'ENV_EXAMPLE_' -e 'function envExample' tests/` が当該ファイルだけを返す |
| AC7 | 全検証コマンドが green | 下記「全検証コマンド」 |

AC4 の機械カウント (Pest の表示を人が読む形にしない):

```bash
composer test -- --filter=EnvExampleInvariant \
  --log-junit="${TMPDIR:-/tmp}/env-example-invariant-junit.xml"
python3 -c "import xml.etree.ElementTree as E, os; \
p=os.environ.get('TMPDIR','/tmp')+'/env-example-invariant-junit.xml'; \
print(sum(1 for _ in E.parse(p).iter('testcase')))"
# → 22 が出ること
```

> junit の出力先はリポジトリの外に置く (機械出力をコミットしない)。

### 全検証コマンド (すべて green であること)

```bash
composer test
composer phpstan
vendor/bin/pint --test
pnpm lint
pnpm typecheck
pnpm test
pnpm build
pnpm typecheck:packages
pnpm build:packages
pnpm test:packages
```

- `composer phpstan` は `tests/` を解析対象に含まないため本変更で結果は動かない。
  それでも **回して green を確認する** (回さないと「動かないはず」が確認されない)。
- フロントエンド側の 7 本は本変更と無関係だが、AGENTS.md の検証コマンド一覧が
  「全 green でコミット」と定めているため全部回す。

## 保証しないもの / やらないと決めたこと

| # | 保証しない / やらない | 理由 |
|---|---|---|
| N1 | 実行中の `.env`・プロセスの環境変数・設定キャッシュは見ない | 見るのは `.env.example` の中身だけ。見本の検査であって環境の検査ではない |
| N2 | `SECURITY_HSTS_ENABLED` / `SECURITY_CSP_ENABLED` の欠落を検出しない | 見本に 1 行も無く、載せ方 (値つきかコメントか) を決める別の判断が要る。本 TODO の分界は「検査だけを増やす」 |
| N3 | キー網羅は存在だけを見る (空の値も通る) | 「提示されている」ことの検査。正しい値が入っているかは各設定の検査と本番起動時の検査の担当 |
| N4 | 値の固定は台帳の 4 件だけ。他の値の変化には沈黙する | 固定を増やすほど見本の自由度が下がる。安全側に倒す価値がある値だけ選ぶ |
| N5 | キー網羅を完全一致の集合にしない (床であって天井ではない) | 任意のキーの追加を禁じる理由が無い (正典と同じ判断) |
| N6 | `.env.testing` / `.env.bughunt.local.example` に値の固定とキー網羅を広げない | テストレーンの正しい値は本番の安全側と逆向き (`SESSION_ENCRYPT=false` が正) |
| N7 | 行の形式の検査は dotenv の構文検査ではない | dotenv が読める書式のうち見本に許さないものを違反にするだけ。「通れば dotenv が読める」は保証しない |
| N8 | 制御文字・不可視文字を見ない | 正典の 4 部品に無い。必要になったら家系の議題として起こす |
| N9 | 禁止キーの台帳を持たない (偽の外部サービスのフラグが見本に載らないことの検査) | 正典の 4 部品ではない。3 つのフラグは現在見本に無く、仮に載っても本番起動時の検査が止めるので被害が閉じている |
| N10 | 台帳の件数を完全一致で固定しない | 台帳が検査と同じファイルのリテラル定数なので、削除は差分に直接現れる。件数を別に書くと同じ事実が 2 か所になる |
| N11 | `ProductionEnvGuard` と台帳を機械で結線しない | guard が読むのは config のキーであって環境変数名ではない。結ぶには config の構文解析が要る (思考原則 2) |
| N12 | `${VAR}` 検査と新しい解析器を統合しない | 受理の規則が逆向き。統合すると片方の意図が壊れる |
| N13 | AGENTS.md / `docs/` に節を新設しない | 台帳の正本をテストの定数 1 か所に置く。文書へ写すと必ず食い違う |
| N14 | 見本と config の既定値が一致していることは検査しない | これは「提示の検査」であって「同期の検査」ではない。名前 (`.env.example と config の同期強制`) に対して**実際に保証するのは提示まで**である |

## 実装モード

| 項目 | 内容 |
|------|------|
| 推奨モード | incremental |
| 判断根拠 | 変更は `tests/Architecture/EnvExampleInvariantTest.php` の 1 ファイルに閉じ、他の施策と競合しない。アプリの実行コード・見本ファイル・設定に触らないため、並行して走る他の TODO の差分と重ならない |
| 競合リスク | 同ファイルを触る他の TODO が無いこと (現在の Open な TODO に env 見本を触るものは無い)。ただし `.env.example` にキーを足す TODO が並行すると、キー網羅の台帳ではなく `.env.example` 側が動くだけなので競合にはならない (台帳は床であり、追加は自由) |

## 実装後の申し送り (家系の台帳への報告に使う材料)

実装が終わったら家系の機能台帳へ実装の報告を出す。報告に必要な事実:

- 4 部品 (値の固定 4 / キー網羅 36 (4 分類) / 行の形式 / 重複) と台帳の誠実性の検査がそろったこと
- 見本ファイルとアプリの実行コードを 1 行も変えていないこと (増えたのは検査だけ)
- 本リポジトリだけが持つ `${VAR}` の検査を無改変で残したこと (還流候補として印が付いている)
- 正典に対する差分: 分類が 3 つではなく **4 つ**である (撮影・レンダ成果物の保管先を
  独立した分類として足した)。必須キーの集合はリポジトリごとに違うため、
  逐語の移植ではなく本リポジトリの座標から導出した等価の実装である
- 採らなかった純増 2 つ (禁止キーの台帳 / 台帳の件数の固定) と、その理由

