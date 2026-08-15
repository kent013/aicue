# 概念設計: strict-types-baseline-gate (declare(strict_types=1) の全数強制)

## 背景・課題

PHP は既定で引数・戻り値の型を緩く解釈し、文字列 `"1"` と整数 `1` を黙って行き来させる。
ファイル冒頭に `declare(strict_types=1)` を書くとそのファイル**だけ**この暗黙変換が止まる。
宣言を欠くファイルが 1 枚紛れ込むと、そこだけ取り違えが実行時まで表に出ない。

本リポジトリの状況 (HEAD 実測):

- `AGENTS.md` 実装規約に「`declare(strict_types=1)` + 日本語コメント」と**人が守る規約**として書かれている
- しかし**機械検査は 1 件も無い** (`tests/` 配下に strict_types を検査するテストは存在しない)
- git 追跡下の `*.php` (blade を除く) は 1543 本。うち宣言を欠くのは **32 本**:

| 区分 | 本数 | 内訳 |
|------|------|------|
| `app/` | 4 | `Http/Controllers/Controller.php` / `Models/Team.php` / `Models/Role.php` / `Models/Permission.php` |
| `config/` | 18 | Laravel / 各パッケージが配布したまま宣言を持たない設定ファイル |
| `database/migrations/` | 7 | Laravel 骨組み同梱の初期 migration (自作 migration は全数宣言済み) |
| `bootstrap/` | 2 | `app.php` / `providers.php` |
| `public/` | 1 | `index.php` |

`app/` の 4 本は laravel-claude-template が同一の集合を既に是正済み (テンプレート追従の取り込み漏れ)。

裁定 AG-010 (2026-08-05) が本 feature を「テンプレートへ還流し家系の標準装備とする」と決めており、
**採否は決着済み**である。本設計は「入れるかどうか」ではなく「どの形で入れるか」を決める。

## 改善アイデア

1. 宣言を欠く 32 本すべてに `declare(strict_types=1)` を足し、**未宣言 0 件**にする
2. git 追跡下の PHP ソース全数を走査し、宣言を欠くファイルがあれば落とす Architecture テストを新設する
   (deny-by-default。**免除の登録簿を持たない** = 例外機構そのものを作らない)
3. 走査器は `tests/Support/` の純関数に置き、`tests/Unit/Architecture/` に**負の対照つきの自己検査**を置く
   (本リポジトリの既存作法: 走査器 9 本 + 自己検査 7 本)

### 家系の中での位置づけ

| リポジトリ | 形 |
|------------|-----|
| aigenba (発祥) | 未宣言一覧 (baseline) + 双方向 set 比較。判定器は vendor 正規表現の写し |
| laravel-claude-template | 同左を構造判定へ差し替え。走査域は `app/` のみ。baseline は常に空が契約 |
| spirux | baseline を持たない (違反 0 件のため不要と判断)。走査域は `app/` のみ。`app` の外に 45 件の未宣言が残る |
| **aicue (本設計)** | baseline を持たない。**走査域は追跡下の PHP 全数** (`app/` に限定しない) |

本設計が走査域を広げるのは、`app/` に限ると `config/` `database/` `bootstrap/` `public/` の
未宣言 28 本が「規約の外」として残り続けるためである。実測でこの 28 本は 1 行の追加だけで
解消でき、`config/` `database/` は PHPStan level 10 の解析対象でもあるため
**追加した宣言の副作用は静的解析で機械的に確認できる**。

### baseline (未宣言一覧) を持たない理由

正典 (aigenba / テンプレート) は「未宣言一覧を作って以後は減らす一方にする」方式だが、
本リポジトリは 32 本すべてを同一変更で是正でき、**登録簿が最初から空になる**。
空の登録簿と、それを双方向比較する仕組みは今のところ 1 件の違反も守らない。
「今必要なものだけ作る」(思考原則 2) に従い、**免除機構そのものを作らない**
(既存の `QueueDispatchAtomicityInventoryTest` が採っているのと同じ形 = allow-list を持たない)。

## 期待効果

- **使命への貢献 (間接)**: 本 gate は撮影体験そのものを良くするものではなく、その土台を守る基盤整備である。
  撮影テイクの採用・容量予約・課金は数値と文字列の取り違えが金額・容量の誤りとして表に出る領域で、
  宣言を欠くファイルが 1 枚混ざるとそこだけ暗黙変換が復活し、取り違えが実行時
  (= 現場作業者の撮影中) まで隠れる。全数宣言はこの穴を構造的に閉じる
- 人が守る規約 (`AGENTS.md`) と機械検査の乖離を解消する。現状は規約だけがあり検査が無い
- テンプレート追従の取り込み漏れ (`app/` の 4 本) を解消する
- 家系の裁定 AG-010 を本リポジトリで履行する

## 実装方針（概要）

| # | 施策 | 変更対象 |
|---|------|----------|
| 1 | 宣言を欠く 32 本へ `declare(strict_types=1)` を追加 | `app/` 4 / `config/` 18 / `database/migrations/` 7 / `bootstrap/` 2 / `public/` 1 |
| 2 | 走査対象 (追跡下 PHP) の列挙器を `tests/Support/` へ切り出し、自己検査を付ける | `tests/Support/` + `tests/Unit/Architecture/` + 既存 `NoNonCompoundGlobalUseTest` の付け替え |
| 3 | 宣言の有無を判定する純関数と、その負の対照つき自己検査 | `tests/Support/` + `tests/Unit/Architecture/` |
| 4 | gate 本体 (未宣言 0 件・空振り防止・母集団の床値 pin) | `tests/Architecture/` |
| 5 | 規約と逸脱の記録 | `AGENTS.md` 実装規約行 / `docs/template-divergence.md` |

### 判定器の設計方針 (実測に基づく)

正規表現ではなく `token_get_all()` による構造判定にする (既存 `Tests\Support\PhpTokenScan` を再利用)。
コメント・文字列リテラル中の `declare(strict_types=1)` という**記述**を宣言と誤認しないため。

PHP 8.4 で実測した言語の真値 (本設計の根拠データ):

| 書き方 | 実際に厳密化されるか |
|--------|---------------------|
| `declare(strict_types=1);` | される |
| `declare(strict_types=01);` / `0x1` / `0b1` | される |
| `DECLARE(STRICT_TYPES=1);` (大文字) | される |
| `declare(ticks=1, strict_types=1);` | される |
| `declare(strict_types=(1));` | される |
| `declare(strict_types=1, strict_types=0);` / 逆順 | される (1 が 1 度でもあれば実効) |
| `declare(ticks=1); declare(strict_types=1);` | される |
| `declare(strict_types=0);` | されない |
| `declare(strict_types=true);` / `"1"` / `0+1` | 致命的エラー (実行不能) |
| `declare(strict_types=1) { … }` (ブロック形) | 致命的エラー |
| `namespace A;` の後に置く / `<?php` の前に文字がある | 致命的エラー |
| `declare(strict_types=1,);` (末尾コンマ) | 構文エラー |

判定器は**この真値の下界**として作る。すなわち受理するのは正準形
(`<?php` の直後に `declare` `(` `strict_types` `=` `1` `)` `;`。大小は無視、空白とコメントは透過) だけとし、
実効ではあるが珍しい書き方 (`01` / 複合指令 / 重複指令) は**未宣言側に倒す** (安全側の乖離)。
判定器が「宣言済み」と言うのに実際は厳密化されない、という**逆向きの乖離は 1 件も許さない**。
この非対称は自己検査で機械的に固定する (下記)。

### 自己検査 (負の対照) の方針

「走査器が何も見つけられなくても緑になる」事故を防ぐため、自己検査には次を置く:

- **正の対照**: 正準形と、その空白・コメント揺れが「宣言済み」と判定されること
- **負の対照**: 宣言なし / 値 0 / ブロック形 / 後置 / コメント内の言及のみ / 文字列リテラル内の記述 /
  致命的エラーになる形が「未宣言」と判定されること
- **実測との突き合わせ**: 上表の各検体について、別プロセスで PHP を実行して
  「実際に型の不一致が起きるか」を測り、判定器の答えと突き合わせる。
  乖離は「実効なのに判定器は未宣言」の向きだけを許し、逆向きが 1 件でもあれば赤にする
- **母集団列挙器の負の対照**: 一時的な git リポジトリを作り、追跡下 / 未追跡 / blade /
  索引に残った削除済みファイルを置いて、列挙結果が期待どおりであることを固定する

### gate 本体の方針

- 母集団: `git ls-files '*.php'` から `*.blade.php` を除いたもの
  (blade はテンプレートであり先頭が PHP コードではないため、規則の段階で母集団に入れない)
- 空振り防止: 母集団が 0 件なら赤。さらに**ファイル数の床値**と、
  `app/` `tests/` `config/` `database/` `routes/` から各 1 本以上が母集団に含まれることを pin する
  (走査域が黙って狭まる事故を検出する)
- 失敗メッセージは未宣言ファイルの相対パスと、足すべき 1 行を出す。あわせて
  「実効ではあるが本 gate が受理しない書き方 (`01` / 複合指令など) がある。正準形だけを許す」ことと、
  「`vendor:publish` 直後に骨組みファイルが宣言を失った場合も同じ手順で足す」ことを書く
- **免除機構を後から足すときは設計レビューを通す**。どうしても宣言できないファイルが将来出た場合に、
  なし崩しで allow-list を作らないための前提として gate の説明文に書き残す

## 制約・前提

- **既存の作法に揃える**: 走査器は `tests/Support/`、自己検査は `tests/Unit/Architecture/`、
  gate は `tests/Architecture/` (現状 走査器 9 本 / 自己検査 7 本 / gate 多数)
- **同じ列挙を 2 本持たない**: 追跡下 PHP の列挙は既存 `NoNonCompoundGlobalUseTest` が
  ファイルスコープ関数として持っている。本設計はこれを `tests/Support/` へ切り出して共用する。
  ただし「共用したせいで一方の都合で走査域が黙って変わる」ことを防ぐため、
  **利用側の gate はそれぞれ自分の母集団の床値と代表パスを pin する**
- **副作用の確認手段と、その保証範囲**。`declare(strict_types=1)` はそのファイルの中から出る
  関数・メソッド呼び出しの引数と戻り値に効く。確認手段は 4 つあるが、いずれも
  **主要経路を覆うだけで完全ではない** (動的呼び出し・container 解決・env 由来の値による分岐は
  静的解析の外にある):

  | 対象 | 手段 | 覆えるもの |
  |------|------|-----------|
  | `app` / `config` / `database` / `routes` (29 本) | `composer phpstan` (level 10) | 静的に決まる呼び出しの型不一致 |
  | `bootstrap/` (2 本) | `composer test` / `php artisan route:list` / `php artisan config:clear` | 起動経路の実行 |
  | `public/index.php` (1 本) | `composer test:browser` (実サーバ経由で front controller を通る) + `php -l` | 実 HTTP 起動と構文 |
  | 全体 | `composer test` (全数) | テストが通る実行経路 |

- **PHPStan エラーが出たときの直し方**: 明示 cast / 値の正規化 / DTO 化で直す。
  型を緩めて黙らせること (widen) も baseline 化も**しない** (禁止事項 2)
- テンプレートとの逸脱 (テスト名と走査域) は `docs/template-divergence.md` へ記録する。
  骨組み由来ファイルも本リポジトリでは対象であること、および
  **`vendor:publish` した直後は gate が赤くなるので宣言を足してから commit する**運用も併記する

## スコープ外

- `artisan` (拡張子を持たないため母集団に入らない)。実測では shebang 行があっても宣言は有効だが、
  1 本のために拡張子以外の規則を作らない。**この限界は gate の説明文に明記する**
- 未追跡 (git add 前) のファイル。gate が守る境界は commit / CI であり、そこでは必ず追跡下にある
- vendor / node_modules (git 追跡下に無いため自動的に外れる)
- 型の緩さそのものの是正 (PHPStan level 10 の担当)。本 gate は宣言の有無だけを見る
- テンプレート側への還流 (本リポジトリは追従側)
