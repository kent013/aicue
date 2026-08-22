# ヘルプ機構 (置き場と規約)

`docs/help/` の置き場・宣言・生成物の運用契約の**正本**である。
機構の実装は `app/Services/Help/` と `app/Console/Commands/Help/HelpBuildCommand.php`。

## これは何か

「ヘルプ本文を置く場所」と「実装から自動生成する節」を 1 つの宣言 (manifest) で扱い、
**生成物が実装からずれたまま気付かれない形を作らない**ための機構である。

- **取り込み基盤**: `HelpRepository` が `docs/help/` を読み書きする唯一の層。
- **生成器の台帳**: `HelpGeneratorRegistry::GENERATORS` が生成器の全数申告。
- **唯一の入口**: `php artisan help:build` (生成) / `php artisan help:build --check` (鮮度検査)。
- **鮮度ゲート**: `tests/Feature/Help/HelpBuildFreshnessTest.php` が `composer test` (= CI) で
  `--check` を走らせる。生成物が古いと**赤くなる**。

## 置き場の規約

- `docs/help/manifest.json` が**宣言の正本**である。ここに無い節は存在しない。
- `schema_version` は `1` で**厳密一致**する (文字列 `"1"` も未知の `2` も読まずに落ちる)。
- `path` の値域は `_generated/<name>.md` または `pages/<name>.md` の **2 通りだけ**。
  `<name>` は `[A-Za-z0-9][A-Za-z0-9._-]*`。**どちらも直下のみで階層を許さない**
  (階層を許すと孤児の検査に再帰走査が要る)。
- `generator` キーを**持つ節が生成物**、持たない節が**手書きページ**である。
  `generator` の値は `HelpGeneratorRegistry::GENERATORS` のキーと**完全一致**する
  (両方向。片側にしか無ければ `help:build` も `--check` も止まる = deny-by-default)。
  **1 つの生成器を参照できる節は 1 つだけ**である。
- 生成物は `php artisan help:build` が書き、**手で編集しない**
  (生成物の先頭にその旨のコメントが入る)。
- **手書きページは 0 件でよい**。0 件でも `help:build --check` は成功する
  (ヘルプ本文の未整備を赤字扱いしない)。
- `docs/help/_generated/` 直下に manifest 未宣言の `.md` があれば **Orphan** として報告する。
  **`help:build` は孤児を削除しない** — 人が消すか manifest へ宣言する。
- **信頼する起点 (リポジトリルート) から置き場までの経路に symlink があってはならない**。
  置き場は canonical path として渡す契約で、`realpath()` の結果が渡された文字列と
  一致しなければ例外で止まる。最終要素 (`help`) だけでなく**途中の要素 (`docs`) も**含む
  — どこか 1 つでも symlink だと `realpath()` が外側を canonical root として返し、
  「置き場の内側か」の検査が意味を失うためである。
  同じ契約が MCP ツールの走査根 (`app/Mcp/Tools/`) にも適用される。
  **作業ツリー全体が symlink の先にある形は拒まない** — 配線 (`AppServiceProvider`) が
  起点そのものを `realpath()` で正規化してから組み立てるためである。
- 生成物ディレクトリと生成物も symlink であってはならない (見つけたら例外で止まる)。
- 生成物ディレクトリにディレクトリ・`.md` 以外・通常ファイルでない実体があれば
  **例外で止まる** (字句の規約を実体でも守る)。

## 報告の種別と終了コード

| 種別 | 意味 | 対処 |
|---|---|---|
| `up_to_date` | 生成物が実装と一致している | — |
| `stale` | 生成物が古い | `php artisan help:build` を実行して差分をコミットする |
| `missing` | 宣言された生成物が無い | `php artisan help:build` を実行する |
| `orphan` | manifest に無い生成物が残っている | 削除するか manifest へ宣言する |

**終了コードは 0 と 1 の 2 値だけ**である (例外も 1 へ畳む)。
`up_to_date` 以外が 1 件でもあれば 1 になる。

## 生成器を足すとき

1. `App\Services\Help\Generators\HelpGenerator` を実装する (`key()` と `generate()`)。
2. `HelpGeneratorRegistry::GENERATORS` へ 1 行足す。
3. `docs/help/manifest.json` へ節を 1 つ足す (`generator` に同じキー)。
4. `php artisan help:build` を実行し、生成物を**同じコミットに含める**。

2 と 3 のどちらかを忘れると `help:build` 自体が止まる (意図した fail-closed である)。

## 現在の生成器

| キー | 実装 | 生成物 | 入力 |
|---|---|---|---|
| `mcp-tools` | `App\Services\Help\Generators\McpToolReferenceGenerator` | `docs/help/_generated/mcp-tools.md` | `app/Mcp/Tools/` の具象ツール (`McpToolScanner` が走査) |

`McpToolScanner` の走査根は `app/Mcp/Tools/` **直下だけ**で、基底
`App\Mcp\Tools\AppMcpTool` を継承しない具象クラスを見つけたら**例外で止まる**
(補助クラスは別の namespace へ置くこと)。母集団が 0 件になることも
「違反 0 件」ではなく走査の破損として例外にする。

vendor (`laravel/mcp` / `illuminate/json-schema`) が返すメタデータの形は、
**最上位を pin し、値は閉じた集合で弾かずに表示用へ正規化する**。

- 最上位は `type === 'object'` であることと、キーが `type` / `properties` / `required` の
  3 つに限られることを要求する。**vendor が `properties` を別のキー名へ変えたら止まる**
  (これを見ないと「パラメータ 0 件」として静かに緑で通り、生成物から全パラメータが消える)。
  vendor 更新で無害なキーが増えても止まるが、**止まるのが正しい側**である。
- パラメータの `type` は文字列でも文字列の配列 (union / nullable) でも受け、
  `|` 連結の表示用文字列へ正規化する。未宣言は `(未宣言)`。
- **説明文は無害化し、パラメータ名は無害化しない**。説明の縦棒と改行は表を壊さないよう
  潰すが、名前が表を壊す文字 (`|` / backtick / 改行) を含むときは**例外で止める** —
  名前は実装の識別子であり、静かに別名へ書き換えると生成物と実装がずれる。
- 想定外の形はすべて**静かに欠けずに止まる**
  (例外に対象クラス・不正だった箇所・直し方が入る)。

## 保証しないもの (誇張しない)

- **表示面を持たない**。HTTP でヘルプを配る route も画面も無く、Markdown を HTML へ
  変換もしない (変換先が無い)。
- **ヘルプ本文の中身の品質・網羅性は検査しない**。機構が見るのは置き場の規約と
  生成物の鮮度だけである。
- **`pages/` 配下の未宣言ファイルは孤児として扱わない** (手書きの下書きを赤にしないため)。
  孤児検査の母集団は生成物ディレクトリの直下だけである。
- 実体の検査は **POSIX 前提** (`is_link()` / `realpath()`) である。Windows は対象外。
- **検査と入出力の間の差し替え (TOCTOU) は防げない**。PHP に `openat(2)` / `O_NOFOLLOW`
  相当の API が無いため、「実体を検査してから開く」以外の書き方が存在しない。
  保証するのは**静止状態での封じ込め** (起点から置き場・走査根までの経路、生成物ディレクトリ、
  生成物のいずれも symlink でないこと) までで、書き込みの最中にファイルや親ディレクトリを
  symlink へ差し替える攻撃者は脅威モデルに含めない (これは開発者の作業ツリーで走る生成器である)。
  書き込み後の検査は**取り消しではなく検出**である。
- **保証しないものの網羅的な正本は各クラスの docblock** であり、本書はその要約である
  (2 か所に同じ一覧を書くと必ず食い違う)。
