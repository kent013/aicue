# Round 3: Round 2 の指摘への対応

Round 2 の [Critical] 2 件・[Warning] 1 件・[Suggestion] 1 件を反映した。
Critical 3 (複数キーの change set) は「型付き change set クラスは作らない」という形で
**部分的に反論**しているので、その根拠を含めて判断してほしい。
残る争点があれば指摘し、無ければ全体判定を返してほしい。

# 対応マトリクス: conceptual-review Round 2

## [Critical] 3 複数キーを扱う口と i6 の境界が未定義 (入れ子にすると検証の段で既に触っている)
- 判断: 対応する (ただし型付きの change set クラスは作らない)
- 根拠: 失敗様式の指摘は正しい。単一キーの操作を入れ子にすると、内側のキーが拒否された時点で
  外側のキーは既に適用済みであり、i6 の「検証の段では何も触らない」が **1 回の操作の中では**
  成り立っていても、**呼び出し側の書き方で崩れる**。
  一方で 3 つ目の型 (`RawEnvChanges`) を足しても得られる保証は増えない —
  キーの妥当性は結局 `with()` の第 0 相で走る実行時検査であり、
  `array<non-empty-string, RawEnvChannels>` の phpdoc で PHPStan level 10 は既に
  値の型と非空キーを見る。増えるのは間接層だけである (AGENTS.md 思考原則 2)。
- 対応内容: 概念設計へ **口の契約 (5 段)** を明記した。
  1. change set 内の全キーを検証 (何も触らない) → 2. 全キー・全 3 面を退避 →
  3. 全キーを適用 → 4. 本体を実行 → 5. 全キーを復元。
  あわせて **「同時に触るキーは 1 回の操作で渡す (入れ子で分けない)」を口の契約として明記**し、
  契約テストへ「**拒否キーを 2 番目以降に置いても、先行キーの 3 面が 1 面も変わっていない**」を
  追加した (i11 (d) の強化)。閉包の口と後処理フック向けの口は
  **同じ検証・退避・復元の私設経路を共有する** (実装を 2 本持たない) ことも明記した。

## [Critical] 5 `putenv` の名前解決に検出漏れ (`\putenv` / `use function putenv as …`)
- 判断: 対応する
- 根拠: 指摘のとおり。どちらも**字句から解決できる**形であり、
  とくに別名つき取り込みはソース中に `putenv` という呼び出し名が現れないため、
  「分類できない出現を fail-closed」だけでは捕まらない。
  AGENTS.md 走査器共通規約 (a) が「別名つき取り込み 1 つで検査が黙る」と名指しした形そのものである。
- 対応内容: 検出契約へ **関数名の解決**を追加した。ファイルごとに
  `use function` の取り込み対応表 (別名・group use を含む) を組み立て、
  完全修飾 (`\putenv`) / 名前空間内の非修飾 (グローバルへの fallback) / 別名を解いた
  **完全修飾名が `\putenv` になる呼び出し**を検出する。
  解決できない取り込み・動的な関数名は**未解決として gate を失敗させる**。
  正例 (裸 / 完全修飾 / 別名) と負例 (同名メソッド `$x->putenv()` / `X::putenv()` /
  接頭辞・打ち消し・接尾辞つきの別識別子) を両方向で固定する。

## [Warning] 7 `sameOnAllSurfaces()` の引数型
- 判断: 対応する
- 根拠: `putenv` 面は `string` しか受けられないので、`mixed` を受ける口があると
  非文字列を渡した事故が実行時まで残る。
- 対応内容: `sameOnAllSurfaces(string $value)` に限定し、
  非文字列は `withServer()` / `withEnv()` からしか指定できない形にした
  (`putenv` 面へ非文字列が入る経路を PHPStan で到達不能にする)。

## [Suggestion] q3 の表に契約テスト (g) を書く
- 判断: 対応する
- 対応内容: 未決論点の表の q3 行へ「契約テスト (g) による実行時固定」を追記した。

---

## 修正後の概念設計 (全文)

# 概念設計: raw-env-snapshot-restore v1 追従

対象 feature: 家系機能台帳 (lctl) `raw-env-snapshot-restore`
正典の版: **v1** (design.md 2026-08-22 settle 済み / doc_sha `c4fa274ac84f` / 不変条件 i1–i12)
aicue セル: `update_pending` (現行 `pre-v1` → 目標 `v1`)

## 背景・課題

PHP では 1 つの環境変数がプロセスの中で **3 面**に現れる — `getenv()` が読む面 /
`$_ENV` の要素 / `$_SERVER` の要素。この 3 つは言語の側で別物であり、片方を書き換えても
他方は変わらない。Laravel の `env()` はこの 3 面を **`$_SERVER` → `$_ENV` → `putenv`** の順に
live で読む (実測: `Illuminate\Support\Env::getRepository()` が
`RepositoryBuilder::createWithDefaultAdapters()` = `ServerConstAdapter` → `EnvConstAdapter` を作り、
`$putenv` が真のとき末尾に `PutenvAdapter` を足す)。

したがって、テストが環境変数を差し替えたあと 1 面だけを元へ戻すと、残った面の古い値が先に
読まれ、あとから走る別のテストの入力が静かに変わる。`RefreshDatabase` はプロセスの環境変数を
守らないので、環境変数を入力に取るテストを書いた人は誰でも自分で戻す必要がある。

### aicue の現状 (実読で確定)

git 追跡下の PHP 全数 (2,114 本) に対して 3 面への**直接の書き込み**を全数走査した結果、
実在するのは **5 ファイルだけ**である (家系 6 リポジトリ中で最少)。`app/` `config/` `database/`
`routes/` `scripts/` `devnotes/` には 1 件も無い。

| ファイル | 形 | 正典 v1 に対する状態 |
|---|---|---|
| `tests/bootstrap.php` | 枠組み起動前の足場 (DB 名注入) | i12 が許す 3 か所の (c)。**変更しない** |
| `tests/Feature/Support/ProductionEnvGuardTest.php` | ファイル内関数 7 本 (退避・復元・消去・静的な入れ物・閉包) | 2 通りの結び方を両方持つが**ファイルの中に閉じている** (i1 未達) |
| `tests/Feature/Config/ConfigHardeningTest.php` | `evaluateConfigFileWithEnv()` | 別実装。`?? null` 退避で「存在するが null」を消す (i3 違反) / 退避と適用が同一ループ (i6 違反) |
| `tests/Feature/Auth/PasskeyOriginDeclarationTest.php` | `evaluateFortifyConfigWithEnv()` | 別実装。存在の保存は正しいが退避と適用が同一ループ (i6 違反) / 面ごとの値を作れない (i7 未達) |
| `tests/Unit/Ci/TestDatabaseSchemaUpdateTest.php` | 親プロセスへ `putenv` 1 面だけ | **i9 の拒否対象キーを親へ立てている** (`DB_DATABASE=app` / `DB_URL=…/app` / `APP_CONFIG_CACHE=/tmp/attacker-controlled-config.php`) |

正典 v1 に対して aicue が満たさないのは
**i1 (集約) / i2 (読み出し順の実行時固定) / i3 (一部) / i6 (3 相) / i7 (一部) / i8 (キー検査と
putenv 失敗の例外化) / i9 (拒否) / i10 (env 読み出し口の作り直し) / i11 (専用の契約テスト) /
i12 (部品の外の直接操作の検査)** の 10 点である。

### 実害は「拒否」だけでは消えない (正典 s9 と同型の実測)

`tests/Unit/Ci/TestDatabaseSchemaUpdateTest.php` は、`pgsqlTestArtisanEnv()` が親の環境を
継承しないことを示すために、**テストの実行中に親プロセスへ開発用 DB 名と攻撃者制御の
設定キャッシュパスを立てている**。`finally` で戻すのは `putenv` 面だけであり、
`finally` に到達する前にプロセスが落ちればその worker には汚染が残る。
これは AGENTS.md 禁止事項 3 (dev DB への破壊操作) の隣接ハザードであり、
正典 design.md s9 が motivation で観測した実害と**同型**である。

正典が i9 (拒否) と i12 (部品の外の直接操作の検査) を**対で**要求しているのは、
拒否だけを入れると「拒否に当たるキーを触りたい検査」が部品を使えず手書きへ逃げ、
危険が見えない場所へ移るだけになるからである。

## 改善アイデア

1. **1 つの名前のついた部品へ集約する (i1)**。`Tests\Support\RawEnv` 名前空間に、
   3 面の退避・注入・復元を担う部品を新設し、既存の 3 実装 (ProductionEnvGuardTest の
   関数群 / `evaluateConfigFileWithEnv()` / `evaluateFortifyConfigWithEnv()`) を**同じ変更で消す**
   (AGENTS.md 思考原則 3: 後方互換の並走を残さない)。
2. **結び方は 2 通りとも提供する (i5)**。閉包を囲んで `finally` から戻す口と、
   退避を値として持ち回り枠組みの後処理フック (`afterEach`) から戻す口。正典は択一ではない。
3. **退避の正しさを揃える (i3 / i4 / i7)**。存在と値を別に持ち、型を絞らず、面ごとに独立して戻す。
   面ごとに違う値を入れる口を持ち、**指定しなかった面は明示的に未設定にする**。
4. **差し替えの安全 (i6 / i8 / i9)**。検証 → 退避 → 適用 + 本体 → 復元 の 3 相。
   キーの書式検査 (空 / `=` / NUL を拒否)、`putenv()` の失敗を例外化、
   aicue の**単一点の守りが前提にするキー** (`DB_` 接頭辞 / `TEST_TOKEN` / `APP_CONFIG_CACHE`) を
   検証の段で拒否する。**例外の許可一覧は持たない**。
5. **読み出し順を実行される検査で固定する (i2)**。注釈ではなく、**Laravel の `env()` を通して**
   `$_SERVER` → `$_ENV` → `putenv` を固定する (3 面すべてに違う値 / `$_SERVER` だけ未設定 /
   `putenv` 面だけ設定、の 3 通り)。上流の既定が変わったら赤くなること。
6. **env 読み出し口を捨てて作り直す口を持つ (i10)**。`Illuminate\Support\Env::enablePutenv()` の
   副作用 (`$repository = null`) に依拠するため、**依拠している副作用と監視条件を docblock に明記**し、
   **副作用が生きていること自体を契約テストで実行時に固定する**
   (口の呼び出し前後で `Env::getRepository()` のインスタンス同一性が変わること /
   その後 `env()` が 3 面へ入れた値を読むこと / 復元後は元の値へ戻ること)。
   docblock の監視条件だけだと「緑のまま保証だけ失われる」(正典の未決論点 q3 が名指しした形) を
   検出できない。
7. **部品専用の契約テストを持つ (i11)**。正典が定める (a)–(f) の 6 項目 +
   「何を保証して何を保証していないか」の明記。
8. **部品の外の直接の書き込みを検査で止める (i12)**。git 追跡下の PHP を母集団にした
   Architecture gate を新設し、許可は**部品自身 / 部品の契約テスト / `tests/bootstrap.php`** の
   3 か所だけにする。許可した 3 か所も**置き場と件数を書いた目録**へ登録し、件数を完全一致で pin する。
9. **拒否対象キーを触っていた検査を、親の環境を触らない形へ書き換える**。
   `pgsqlTestArtisanEnv()` から**純関数の組み立て**を切り出し、テストは親の環境を表す配列を
   引数で与える。これで i9 の拒否と i12 の検査が同時に成り立ち、実害そのものが消える。

## 期待効果

- **使命への貢献**: aicue の使命 (SOP → シナリオ → ナビ撮影 → 動画マニュアル) は、
  **撮影 PWA の 3 枚セット (no-store / bfcache 秘匿 / 履歴暗号化)** と
  **本番構成の起動時 fail-fast** に依存している。これらを守る検査
  (`ProductionEnvGuardTest` / `ConfigHardeningTest` / `PasskeyOriginDeclarationTest`) は
  **すべて生の環境変数を差し替えて動く**。その差し替えが取りこぼすと、守りの検査が
  「実行順によって通ったり落ちたりする」状態になり、**守りの主張そのものが信用できなくなる**。
  本改善はその土台を 1 本にする。
- **具体的な改善見込み**:
  - 「存在するが値が null」を復元時に消す取りこぼし (`ConfigHardeningTest`) が消える。
  - **検証で拒否されたとき 1 面も書き換わっていない**ことが動的テストで固定される。
  - 複数キーの**適用の途中**で失敗したときの巻き戻りは、正典 q2 のとおり動的には作れないため
    **構造の固定** (適用のループが `try` の本体に、復元のループが `finally` の本体にある) で代え、
    「何を保証して何を保証していないか」を契約テストへ明記する。**動的に保証されたとは書かない**。
  - **親プロセスへ dev DB 名と攻撃者制御の設定キャッシュパスを立てる経路が消える**。
  - 上流 (phpdotenv / Laravel) が読み出し順を変えたら、注釈ではなく**検査が赤くなる**。
  - 新しい手書きの複製が生えたら gate が赤くなる (i1 が「入れた直後だけ成り立つ」性質から、
    維持される状態へ変わる)。
- **家系への貢献**: 正典の未決論点 q1 (i12 の母集団をどこまで広げるか) は
  「1 リポジトリで実際に数えた結果」を解消経路に指定している。aicue は家系で 3 面を触る
  ファイルが最少 (5 本) であり、**全数計数 (追跡 PHP 2,114 本 / 直接書き込み 5 ファイル /
  部品へ寄せられるもの 4・足場として残るもの 1) がそのまま q1 の実測証跡になる**。

## 実装方針 (概要)

### 新設 (`tests/Support/RawEnv/`)

| 要素 | 役割 |
|---|---|
| `RawEnvSnapshot` | 部品本体。閉包の口 (`with()`) / 持ち回りの口 (`captureAndClear()` + `restore()`) / env 読み出し口の作り直し (`forgetLaravelEnvRepository()`) / キー検証 (3 相の第 0 相) |
| `RawEnvChannels` | 面ごとの値の指定 (`$_SERVER` / `$_ENV` / `putenv`)。**指定しなかった面は未設定**を意味する |
| `RawEnvDirectWriteScanner` | i12 gate の走査器 (純関数)。字句走査で 3 面への書き込み位置を列挙する |
| `RawEnvWriteKind` / `RawEnvWriteSite` / `RawEnvDirectWriteAllowance` | 走査結果の型と、許可の登録に使う型付き列挙 |

### 状態モデル (型で「未指定」と「値が null」を分ける)

正典 i3 は値の型を絞ることを禁じ、i7 は「指定しなかった面を明示的に未設定にする」ことを求める。
aicue には **`$_SERVER` へ非文字列 (配列) を入れて fail-closed を確かめる既存ケース**があるので
値の型は絞れない。したがって「指定したか」を値と別に持つ以外に解が無い。

| 型 | 面ごとに持つもの | 備考 |
|---|---|---|
| `RawEnvChannels` (注入の指定) | `specified: bool` + `value: mixed` (`putenv` 面だけ `value: string`) | 生成は `none()` からの `withServer(mixed)` / `withEnv(mixed)` / `withProcess(string)` と `sameOnAllSurfaces(string)` に限る。**配列リテラルを受ける口は公開しない**。`sameOnAllSurfaces()` が `string` しか受けないのは、`putenv` 面へ非文字列が入る経路を PHPStan で到達不能にするためである (非文字列は `withServer()` / `withEnv()` からしか指定できない) |
| `RawEnvSnapshot` の内部状態 (退避) | `exists: bool` + `value: mixed`。`getenv()` 面だけ `string\|false` をそのまま保持 | `?? null` で潰さない。復元は面ごとに独立 |

- **キーの検証は生成の境界で行う** (3 相の第 0 相)。空 / `=` / NUL / 拒否対象キーは
  この時点で例外にし、**何も触らないうちに**止める。

#### 口の契約 (5 段。複数キーを 1 回の操作で扱う)

閉包の口は `array<non-empty-string, RawEnvChannels>` を、持ち回りの口は
`list<non-empty-string>` を受け、次の順序を**契約として**守る。

1. change set 内の**全キー**を検証する (この段では 1 面も触らない)
2. **全キー・全 3 面**を退避する (この段でも何も変えない)
3. 全キーを適用する
4. 本体 (閉包 / 後処理フックまでのテスト本体) を実行する
5. 全キーを復元する

- **同時に触るキーは 1 回の操作で渡す (単一キーの操作を入れ子にして分けない)**。
  分けると、内側のキーが拒否された時点で外側のキーは既に適用済みになり、
  「検証の段では何も触らない」が呼び出し側の書き方で崩れる。
- 2 つの口は**同じ検証・退避・復元の私設経路を共有する** (実装を 2 本持たない)。
  違うのは復元の起こし方だけ (`finally` か、後処理フックから呼ぶ `restore()` か)。
- 契約テストで「**拒否キーを 2 番目以降に置いても、先行キーの 3 面が 1 面も変わっていない**」を固定する。
- 型付きの change set クラスは作らない。キーの妥当性は結局 5 段の第 1 段で走る実行時検査であり、
  `array<non-empty-string, RawEnvChannels>` の宣言で PHPStan level 10 は値の型と非空キーを見る。
  型を 1 つ増やしても増えるのは間接層だけである (AGENTS.md 思考原則 2)。
- `putenv()` の戻り値 `false` は必ず例外へ変換する (PHPStan level 10 に `false` を見逃させない)。
- `mixed` が現れるのは値のフィールドの中だけで、口の引数・戻り値は型が付く。

### 走査の検出契約 (i12 gate の走査器)

**検出する形** (すべて `token_get_all()` の字句列の上で判定する):

| 形 | 例 |
|---|---|
| 面の要素への代入 (通常 / 複合 / `??=` / インクリメント) | `$_SERVER['K'] = …` / `$_ENV['K'] .= …` / `$_SERVER['K'] ??= …` / `$_ENV['K']++` |
| 面の要素の削除 | `unset($_SERVER['K'], $_ENV['K'])` |
| 面そのものへの代入 | `$_SERVER = […]` |
| 面への参照の取得 | `&$_SERVER['K']` / `&$_ENV` |
| プロセス面への書き込み (両形) | `putenv('K=V')` / `putenv('K')` |
| **完全修飾・別名つきの同関数呼び出し** | `\putenv(…)` / `use function putenv as setRawEnv;` の後の `setRawEnv(…)` |

**関数名は完全修飾名で突き合わせる** (AGENTS.md 走査器共通規約 (a))。ファイルごとに
`use function` の取り込み対応表 (別名・group use を含む) を組み立て、
裸の呼び出し (名前空間内でもグローバルへ fallback する) / 完全修飾 / 別名を解いた結果が
`\putenv` になる呼び出しを検出する。**短名一致は使わない** — 別名つき取り込み 1 つで
検査が黙るからである。解決できない取り込み・実行時に決まる関数名は
**未解決として gate を失敗させる**。

**誤検出しない形** (負例で両方向を固定する。AGENTS.md 走査器共通規約 (c)(e)):
面の**読み出し** (`$_SERVER['K'] ?? null` / `foreach ($_SERVER as …)` / 引数として値渡し) /
文字列リテラルとコメントの中の綴り / ヒアドキュメント・ナウドキュメント本文 /
`putenv` を**接頭辞・打ち消し・接尾辞**で含む別識別子 (`myputenv` / `not_putenv` / `putenv_safe`) /
同名のメソッド呼び出し (`$x->putenv()` / `X::putenv()`) / 別名を `putenv` **以外**へ向けた取り込み。

**解決できない形は落とす (fail-closed)**: 上のどちらにも分類できない出現は
**無視せず「未解決」として報告し、gate を失敗させる** (AGENTS.md 走査器共通規約 (b))。

**保証しないもの (誇張しない。正本は走査器の docblock)**: 名前を実行時に解決する書き込み
(可変変数 / `extract()` / 文字列から呼び出す形)、面を**値渡しで受けた関数が内部で書く**形、
`Dotenv` のような**ライブラリ経由の間接的な書き込み**、`devnotes/` 配下。

### 走査の母集団と空振り検査

母集団は `Tests\Support\TrackedPhpSourceFiles` (git 追跡下の `*.php` から blade を除く) から
**`devnotes/` 配下だけを除いたもの**。gate は次の 4 つを持つ:

1. 走査対象数が床値以上であること (空振りの検出)
2. **走査対象数 + 除外数 = 追跡 PHP 総数** の恒等式 (どこにも分類されず黙って落ちるファイルが無い)
3. 除外集合が `devnotes/` 配下と**完全一致**すること
4. `devnotes/` に追跡 PHP が実在すること (除外の形骸化の検出)

追跡 PHP の**総数そのものは pin しない** — 無関係な PHP を 1 本足すだけで赤くなり、
守りたい性質 (黙って走査から落ちない) は恒等式のほうが強く固定できるため。

### 新設 (テスト)

| ファイル | 役割 |
|---|---|
| `tests/Unit/Support/RawEnv/RawEnvSnapshotContractTest.php` | i11 の契約テスト (正典の (a)–(f) 6 項目 + i10 の実行時固定 (g) + 保証範囲の明記) |
| `tests/Unit/Architecture/RawEnvDirectWriteScannerTest.php` | 走査器の自己検査 (正例 / 負例の両方向。AGENTS.md 走査器共通規約 (c))。**負例は fixture ファイルを置かず、ナウドキュメントのソース文字列を走査器へ直接渡す** (fixture を置くと母集団に入り、許可箇所を増やすことになる。ナウドキュメント本文は `token_get_all()` では 1 トークンになり中の綴りが見えないので、自己検査ファイル自身は gate に対して違反にならない) |
| `tests/Architecture/RawEnvDirectWriteGateTest.php` | i12 gate 本体 (母集団・許可 3 か所・件数の完全一致・空振り検査) |

### 書き換え (同じ変更で旧実装を消す)

| ファイル | 変更 |
|---|---|
| `tests/Feature/Support/ProductionEnvGuardTest.php` | ファイル内の退避・復元・消去・閉包の 7 関数を削除し、部品へ寄せる |
| `tests/Feature/Config/ConfigHardeningTest.php` | `evaluateConfigFileWithEnv()` の退避・復元を部品へ委譲 (`?? null` 退避の取りこぼしが消える) |
| `tests/Feature/Auth/PasskeyOriginDeclarationTest.php` | `evaluateFortifyConfigWithEnv()` の退避・復元を部品へ委譲 |
| `tests/Unit/Ci/TestDatabaseSchemaUpdateTest.php` | 親の環境を触る形をやめ、純関数へ引数で与える形に書き換える |
| `scripts/ci/pgsql_test_conn.php` | `pgsqlTestArtisanEnv()` から純関数の組み立てを切り出す (**挙動は変えない**) |

#### 書き換えで検証を失わないこと (不変条件の対応表を詳細設計に置く)

4 ファイルの書き換えは**検証している不変条件を 1 つも減らさない**。とくに
`TestDatabaseSchemaUpdateTest` は「継承しない」「固定キーが常に勝つ」「`DB_URL` は空で固定」を
**純関数への引数**で確かめる形へ移すが、これは検出力が上がる方向である —
現行の `pgsqlTestArtisanEnv()` は親の `DB_DATABASE` / `DB_URL` / `APP_CONFIG_CACHE` を
**そもそも読んでいない**ので、親へ立てて確かめる現行のケースは空振りである。
引数で与える形にすると「組み立ての入力にそれが載っていても固定値が勝つ」ことを実際に検査できる。

結線 (組み立て結果が子プロセス起動へ渡ること) は**既存の別ケース**が押さえており
(「`$runArtisan` へ渡る引数列がちょうど 2 通り・この順序・それ以外は 1 度も渡らない」)、
今回は `pgsqlTestArtisanEnv()` の呼び出し位置も戻り値の形も変えないため、そのケースは無変更で残る。
加えて `pgsqlTestArtisanEnv()` が「実際の親環境の読み出し + 接続値」から組み立てる**結線そのもの**を
薄い 1 ケースで固定する。子プロセス起動の検証まで広げることはしない (T249 の領域)。

### 台帳・索引

| ファイル | 変更 |
|---|---|
| `docs/template-divergence.md` | 新規登録 **D50** (テンプレートに無い上積み: 部品 + 契約テスト + gate) |
| `tests/Support/TemplateDivergence/LedgerPins.php` | `DIVERGENCE_ENTRY_COUNT` 46 → 47 |
| `docs/app-integration-guide.md` | §2「条件付きで発火するゲート」表へ 1 行追加 |
| `tests/Architecture/IntegrationGuideGateTableSyncTest.php` | 同表の件数 pin 13 → 14 |

## 制約・前提

- **AGENTS.md 思考原則 3**: 旧実装は同じ変更で消す。移行期間・別名の並走を残さない。
- **AGENTS.md 思考原則 5 / 走査器共通規約の「同じ PR で揃える 4 点」**:
  gate と走査器は先に赤くしてから書く。負例と正例・解決できない形を落とす分岐・
  走査が空振りしていないことの検査・docblock への走査対象と保証しないものの明記を同じ変更で揃える。
- **走査根の単一出典**: 追跡 PHP 全数の列挙は `Tests\Support\TrackedPhpSourceFiles` を使う
  (同じ列挙を 2 本持たない)。
- **`tests/bootstrap.php` は変更しない**。同ファイルはテンプレートとの指紋台帳のキーであり、
  現在テンプレートと一致している。i12 は同ファイルを許可する側なので変更の必要が無い。
- **`scripts/ci/pgsql_test_conn.php` は既に D30 の対象パス**であり、
  今回の変更は同登録が説明する不変条件を変えない (純関数の切り出しのみ)。
  新規登録も記述の更新も行わない。
- **PHPStan level 10**: `$_SERVER` / `$_ENV` の要素は `mixed`。型を絞らない要求 (i3) と
  level 10 を両立するため、部品は `mixed` を受け取り `mixed` のまま戻す。
- **禁止事項**: 既存テストの削除・上書きは行わない。書き換える 4 ファイルは
  **検証している不変条件を 1 つも減らさない** (むしろ i9 の書き換えでは検出力が上がる)。

## スコープ外

- 環境変数を**読む側**の守り (`ProductionEnvGuard` / `ExternalFakeDeclaration` の二重判定)。
  本 feature の boundary が明示的に除外する (`external-fakes-wiring-gate` の担当)。
- テストレーンの環境の組み立て (`.env.testing` / `phpunit.xml` / 起動前の足場が
  どの値を挿すか — `pest-lane-wiring` / `php-test-pgsql-lane` の担当)。
- **子プロセスへ渡す環境変数の統制** (`subprocess-boot-probe-harness` の担当 = 未着手 TODO **T249**)。
  本設計が `pgsqlTestArtisanEnv()` に触れるのは「**親プロセスの環境を元へ戻す**」ためであり、
  子プロセスへ渡す配列の作り方を正典 v1 へ寄せる作業ではない。**T249 とは同時進行しない**
  (どちらも `tests/Support` へ部品を足すため、着手が重なるとレビューが交錯する)。
- テストレーンの直列化 (`global-test-lock`)。
- `ConfigHardeningTest::evaluateFortifyPasskeysWithEnv()` と
  `PasskeyOriginDeclarationTest::evaluateFortifyConfigWithEnv()` の**config 評価としての重複**の統合。
  これは env 退避の話ではないので本 feature では触らない。
- 本番経路での `putenv()` 利用に関する指針 (`putenv()` はスレッド安全でないため、
  本部品は**テスト専用**である)。

## 正典の未決論点に対する本設計の立場

| 論点 | aicue の決定 |
|---|---|
| **q1** i12 の母集団をどこまで広げるか | **git 追跡下の PHP 全数から `devnotes/` だけを除く** (2,114 − 22 = 2,092 本)。除外は 1 つだけで、理由 (一時スクリプトの置き場であり実行経路にも CI にも載らない) と「除外が形骸化していないこと」を機械で見る。**足場として残る直接操作は `tests/bootstrap.php` の 1 ファイルだけ**であり、部品へ寄せられるのは 4 ファイル — この比 (4:1) が q1 の解消経路が求める実測証跡になる |
| **q2** 適用途中の失敗の巻き戻りを動的に確かめる手段 | 動的には作らない (`putenv()` を失敗させる状況をテストから作れず、失敗注入の口を新設すると「本番では誰も使わない差し替え口」が増える)。**構造の固定**で代え、契約テストの「保証しないもの」へ明記する |
| **q3** i10 が枠組みの内部実装の副作用に寄りかかる件 | 依拠している副作用 (`Env::enablePutenv()` が `$repository = null` を伴う) を**実測して docblock に明記**し、監視条件 (上流の版を上げてこの副作用が消えたら i10 の手段を再評価する) を残す。あわせて**契約テスト (g) で副作用が生きていることを実行時に固定する** (口の前後で `Env::getRepository()` のインスタンス同一性が変わること)。docblock の監視条件だけでは「緑のまま保証だけ失われる」を検出できないため |
