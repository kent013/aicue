# Round 3: Round 2 指摘への対応と改訂版概念設計

Round 2 の指摘 (Critical 1 / Warning 4 / Suggestion) をすべて捌きました。反論はゼロです。
Critical については vendor 実装を実読したうえで、指摘された抜け道を構造ごと消す形にしました。

## 対応マトリクス

# 対応マトリクス: conceptual-review Round 2

## [Critical] 3. 許可された `ignoring` の**引数の出所**が固定されていない (件数 pin では偽装できる)

- 判断: **対応する** (指摘のとおりの穴があった)
- 根拠: `ignoring` の出現ファイルと件数だけを pin しても、`->ignoring([SomeUnregisteredClass::class])` と
  書けば件数は変わらないまま台帳を迂回できる。I3 (例外一覧は `ArchBaseline` にだけ在る) が
  機械では守られていなかった。
- 対応内容: Codex の提案 2 (`ignoring` を呼ぶ箇所を 1 箇所へ閉じる) と提案 3 (引数トークン列の期待形照合) を
  **両方**採る。7 本を手書きせず、`ArchBaseline::ruleIds()` の **`foreach` 1 本**から表明を生成する:

  ```php
  foreach (ArchBaseline::ruleIds() as $ruleId) {
      arch($ruleId.': '.ArchBaseline::titleOf($ruleId))
          ->expect(ArchBaseline::symbolsOf($ruleId))
          ->not->toBeUsed()
          ->ignoring(ArchBaseline::exceptionsOf($ruleId));
  }
  ```

  これで `ignoring` の呼び出し箇所は**リポジトリ全体で 1 つ**になり、S4 は
  「`tests/` 配下の追跡 PHP 全数で `ignoring` は**ちょうど 1 件**、その 1 件の直後のトークン列が
  `( ArchBaseline :: exceptionsOf ( $ruleId ) )` と**完全一致**する」を固定できる。
  対称性のため `expect` の引数も `ArchBaseline::symbolsOf($ruleId)` であることを同じ方式で照合する
  (語彙の直書きも同時に塞げる)。
  - `arch()` は `TestCall` を返す通常のテスト宣言関数なので (vendor 実装で確認:
    `pest-plugin-arch/src/Autoload.php`)、`foreach` の中から呼んでよい。テスト名は規則 ID を含むので一意になる
    (規則 ID の一意性は S3 が固定する)。
  - 「呼んで戻り値を捨てる偽装」は成立しない — `ignoring` は 1 箇所しか無く、その 1 箇所が
    表明の連鎖の末端だからである。
- 波及: この照合は Codex の助言どおり **`ArchSurfaceScanner` の責務拡張**として収める
  (7 本目の支援クラスは作らない)。走査器は「識別子の出現位置」だけでなく
  「出現位置から始まる**トークン列の切り出し**」を返す純関数にする。

## [Warning] 2. 「禁止事項 3」の引用が誤り (AGENTS.md の禁止事項 3 は dev DB への破壊操作)

- 判断: **対応する**
- 根拠: 事実誤り。AGENTS.md §禁止事項 3 は「dev DB への破壊操作」である。
  既存テストを消さない根拠は**裁定 AG-167** と **`app-design` スキルの禁止事項 3 (既存テストの削除・上書き)** の 2 つ。
- 対応内容: 引用を「裁定 AG-167 / `app-design` スキルの禁止事項 3 (既存テストの削除・上書き)」へ訂正した。

## [Warning] 4. 「2026-08-23 時点 HEAD」は未来日

- 判断: **対応する**
- 根拠: レビュー時点は 2026-08-22。JST では 2026-08-23 00:20 だが、タイムゾーンを書かずに未来日に見える表記は観測記録として不適切。
- 対応内容: 「**2026-08-22 (JST 2026-08-23 00:20) の HEAD `2dc4e2ec`**」へ訂正し、タイムゾーンとコミットを併記した。

## [Warning] 5. S2 の名前空間解決 — 素の `sha1()` を使用証明に数えてよいか

- 判断: **対応する** (ただし指摘の 3 案のうち 3 案目を、vendor 実装の実読を根拠に採る)
- 根拠 (vendor 実読):
  - Pest arch の使用判定は `PHPUnit\Architecture\Asserts\Dependencies\Elements\ObjectUses::getByName()` が
    担い、その実装は **`substr($use, -strlen($name)) === $name` の接尾辞一致**である
    (`vendor/ta-tikoma/phpunit-architecture-test/src/Asserts/Dependencies/Elements/ObjectUses.php`)。
  - つまり Pest 側は、素の `sha1()` が `sha1` と記録されようと `App\Foo\sha1` と記録されようと
    **どちらも `sha1` の使用として検出する**。名前空間に同名関数があっても Pest の検出集合からは外れない。
  - したがって「素の `sha1(` を数える」ことは **Pest の検出集合の部分集合**であり、
    保証外の形が使用証明に混ざるわけではない。
  - 逆に Pest の接尾辞一致は `mysha1()` のような**別関数まで拾う**。S2 がこれを真似ると
    数えすぎ = 腐った登録の温存になるので、S2 は**綴りのトークン完全一致だけ**を数える
    (= Pest の検出集合の真部分集合。数え漏らしは赤 = 安全)。
- 対応内容:
  - S2 の契約を「**Pest が対象シンボルとして検出する使用のうち、綴りがトークン完全一致する
    素のグローバル関数呼び出しだけを数える** (Pest の検出集合の部分集合)」と定義し直し、
    上の vendor 根拠を走査器の docblock に書く。
  - 残る不確実性 (vendor が接尾辞一致をやめた場合、登録が保守的に余る) は
    **I2 が blast radius を 1 シンボルに抑えているので穴にならない**ことを明記した
    (余った例外が隠せるのは、その 1 シンボルの、その 1 クラスでの使用だけである)。
  - 負例に `mysha1` (接頭辞つき) を追加し、「Pest は拾うが S2 は数えない」形として固定する。

## [Warning] 7. `Assert` だけでは静的な配列 shape は保たれない

- 判断: **対応する**
- 根拠: 正しい。`token_get_all()` の戻り値と vendor ソースからの抽出値は PHPStan 上で型が広がりやすい。
- 対応内容: 受入条件へ次を追加した。
  - 3 走査器の公開メソッドは**戻り値を正規化してから返す** —
    `GlobalFunctionCallScanner` は `list<string>` (検出した綴り) を、
    `ArchSurfaceScanner` は小さな値オブジェクト `ArchSurfaceSite` の `list<>` を、
    `VendorArchPresetReader` は `list<string>` を返す。
  - `token_get_all()` の生の戻り値は**走査器の外へ出さない** (既存の `PhpTokenScan::normalize()` の
    正規化形で閉じる)。

## [Suggestion] 6. ファイル数の最小性

- 判断: **助言を受け入れる (支援クラスを増やさない)**
- 対応内容: Critical の引数由来検査は `ArchSurfaceScanner` の責務拡張として収め、
  成果物は 6 ファイル (+ 乖離台帳 2 ファイル) のまま据え置いた。


---

## 改訂版 概念設計 (全文)

# 概念設計: pest-arch-baseline-per-rule-adoption

> Round 1 (Critical 1 / Warning 5) と Round 2 (Critical 1 / Warning 4) の Codex レビューを反映済み。
> 対応の記録は `codex-history/conceptual-review-decisions-round-{1,2}.md`。

## 背景・課題

### 家系の正典と裁定

家系の機能台帳 lctl の feature `arch-baseline-pest` (canonical_version: v1、origin: aigenba、
gate: `laravel-claude-template:tests/Architecture/ArchBaselineTest.php`) は、
**Pest のアーキテクチャ検査 (arch API) を安全に使うための構成パターン**を定めている。

塞ぐ穴は 1 つである。Pest の既製規則セット (preset) は禁止シンボルを **1 本の表明へ束ねて**持つ:

```php
expect(['md5', 'sha1', 'uniqid', 'rand', /* … 20 語彙 */])->not->toBeUsed();
```

ここへ `->ignoring(FakeObjectStore::class)` を 1 個渡すと、**その 1 クラスが 20 語彙すべての
検査対象から外れる**。`sha1()` を使うために登録した例外が、同じクラスの中の `eval()` や
`unserialize()` まで無検査にする。**例外登録 1 件の波及半径がセット全体**になるのがこの穴で、
正典 v1 はこれを次の 3 要素で塞ぐ:

1. **規則ごとの分解**: preset へ一括で `ignoring` を渡さず、規則を 1 本ずつの `arch()` 表明に割る。
   **例外を要する対象は、その対象だけを見る規則へ分ける**(=例外つき規則の対象シンボルは 1 個)
2. **例外一覧の単一の置き場**: 全規則の禁止対象配列と例外許可リストを 1 クラス
   (`tests/Support/Architecture/ArchBaseline.php`) へ集約する
3. **自己検査 5 部**: 規則ごとの期待シンボル数の pin / 登録済み例外クラスが対象シンボルを
   **実使用していることの逆向き証明** / 構造契約 / 例外の形式検査とサーフェスの pin /
   vendor preset との集合一致

オーナー裁定 **AG-167 (2026-08-13)** は「spirux と aicue も本機構へ追従させ、家系 6/6 で機構を揃える。
**既存の自作 Architecture テスト群は維持したまま併存させる**」と定めた。
キュレーターは「両アプリは arch API 未使用なので前提が無い」として条件付き対象外を推奨したが、
オーナーは機構の統一を選んでいる (「導入により今後 arch API を使い始めた際の一括除外の穴も
最初から塞がれる」)。

### aicue の現状 (本設計での実測。**2026-08-22 / JST 2026-08-23 00:20 の HEAD `2dc4e2ec`**)

| 観測 | 値 | 確認方法 |
|---|---|---|
| Pest arch API の利用 | **0 件** | `tests/` 全体で `arch(` に一致する行はすべて `array_search(` の一部。`Pest\Arch` の取り込みも 0 件 |
| `tests/Support/Architecture/` | **不在** | ディレクトリごと存在しない |
| `ArchBaseline` を含むファイル | **0 件** | `git ls-files \| grep -i archbaseline` が空 |
| `tests/Architecture/*.php` | **131 本** | 全て自作のファイル走査 / リフレクション型 deny-by-default 目録 |
| `pestphp/pest` | `^4.7` (arch plugin 同梱) | `vendor/pestphp/pest-plugin-arch/` が実在 |
| `tests/Pest.php` の arch 記述 | **無し** | Architecture レーンは `->in('Architecture')` で TestCase だけを束ねている |

つまり aicue には**「穴の前提となる API 利用」自体がまだ無い**。
これは「入れる必要が無い」ではなく「**入れるなら最初から穴の無い形で入れる**」という状況である。
今 preset を素直に使い始めると、最初の例外登録の時点で正典が塞いだ穴をそのまま作ることになる。

### 禁止シンボルの実使用 (母集団の実測)

Pest の arch は **composer の PSR-4 名前空間**を走査根にし、`Composer::userNamespaces()` が
`<root>/tests` 配下のディレクトリを除外する (vendor 実装で確認)。
したがって aicue での走査域は **`App\` (app/) / `Database\Factories\` / `Database\Seeders\`** の 3 根であり、
`Tests\` は入らない。

この 3 根を `token_get_all()` ベースで走査した結果、
**php / security / laravel の 3 preset が禁止する全 97 語彙のうち、実使用があるのは 3 語彙・4 クラスだけ**だった:

| シンボル | 使用クラス | 用途 |
|---|---|---|
| `sha1` | `App\Services\Storage\Fakes\FakeObjectStore` | ローカル fake のロックファイル名生成 (暗号用途ではない) |
| `tempnam` | `App\Services\Manual\SopTextExtractor` | SOP 取込の一時ファイル |
| `var_export` | `App\Support\ProductionEnvGuard` / `App\Support\QueueDispatchAtomicityGuard` | 診断メッセージの値の可視化 |

**例外の母集団が極小である**ことが本設計の最大の追い風である。
「例外を要するシンボルは単独規則へ切り出す」という正典の規約を、
**実際に 3 本の単独規則を作るだけ**で完全に満たせる。

---

## 改善アイデア

**Pest arch API のベースラインを、正典 v1 の per-rule 形で新設する。**
既存 131 本には一切触れない (裁定どおり併存)。

### 中核となる不変条件 (これを機械で守る)

| # | 不変条件 | 守る機構 |
|---|---|---|
| I1 | **preset へ一括 `ignoring` を渡さない**。`tests/` 配下の追跡 PHP 全数で `preset` の識別子出現が 0 件 | S4 (サーフェスの pin。母集団は `tests/` 全数) |
| I2 | **例外を持つ規則の対象シンボルはちょうど 1 個** (= どの規則も他の規則の対象を隠さない) | S3 (構造契約) |
| I3 | **例外一覧は `ArchBaseline` 1 クラスにだけ在る**。`ignoring` の呼び出しはリポジトリ全体で **1 箇所**だけで、その**引数式が `ArchBaseline::exceptionsOf($ruleId)` である**こと | S4 (母集団は `tests/` 全数。出現数 pin + **引数トークン列の完全一致照合**) |
| I4 | **登録した例外は実在し、かつ対象シンボルを実使用している** (登録の腐敗検出) | S2 (逆向き証明。**Pest の検出集合の部分集合だけを数える**走査器) |
| I5 | **規則ごとの対象シンボル数を pin する** (無断の増減で赤) | S1 (期待値の pin) |
| I6 | **vendor preset の語彙集合と、本ベースラインの語彙の和集合が一致する** | S5 (vendor preset との集合一致) |
| I7 | **アプリコード (`app/` `routes/` `config/` `database/` `resources/`) と既存 131 本の Architecture テストを 1 行も変更しない** | 変更対象を新設 6 ファイル + 乖離台帳 2 ファイルに限る |

I2 が正典の核心である。**例外を要する語彙を単独規則へ隔離すれば、`ignoring` の波及半径は
定義上ゼロになる** — 束ねられた他の語彙が存在しないからである。
I2 を機械で固定することで、将来「例外を足したいから既存の束へ ignoring を付ける」という
一番起きやすい退行が構造的に落ちる。

I1 / I3 は**自ファイルの検査では足りない**。別のテストファイルで `preset()->ignoring(...)` を
書けば同じ穴が復活するので、母集団は **`tests/` 配下の git 追跡 PHP 全数**にする。
さらに**件数の pin だけでも足りない** — 許可された 1 箇所の `ignoring` へ
`[SomeUnregisteredClass::class]` を直書きすれば件数は変わらないまま台帳を迂回できる。
そこで表明の生成を `foreach` 1 本へ閉じ、**`ignoring` の引数トークン列そのもの**を
期待形と完全一致で照合する (下記「A. 禁止表明」)。

### 規則の構成 (aicue の母集団に合わせた per-rule 分解)

例外の要否で 2 群に割る。**例外を持たない規則だけが複数語彙を束ねてよい** (束ねても
`ignoring` が無いので穴が生まれない)。

| 規則 ID | 対象 | 例外 |
|---|---|---|
| AB-1 | php preset のデバッグ / 出力 / 実行制御系の語彙 (`dump` `var_dump` `phpinfo` `debug_backtrace` `echo` `print` `goto` `global` `die` `trap` `ray` `ds` 等) | 無し |
| AB-2 | php preset の旧 `mysql_*` 手続き API 14 語彙 + `ereg` / `eregi` | 無し |
| AB-3 | laravel preset の開発補助語彙 (`dd` `ddd` `env` `exit`。php preset と重なる `dump` / `ray` は AB-1 が持つ) | 無し |
| AB-4 | security preset のうち例外不要な 17 語彙 (`md5` `uniqid` `rand` `mt_rand` `eval` `exec` `shell_exec` `system` `passthru` `unserialize` `extract` `dl` `assert` 等) | 無し |
| AB-5 | `sha1` **のみ** | `FakeObjectStore` |
| AB-6 | `tempnam` **のみ** | `SopTextExtractor` |
| AB-7 | `var_export` **のみ** | `ProductionEnvGuard` / `QueueDispatchAtomicityGuard` |

- **正典の「9 規則 102 シンボル」をそのまま写さない**。正典の 9 という数はテンプレートの母集団
  (テンプレート側の例外クラス構成) から出た数であり、aicue の母集団に対する正しい分解は 7 本である。
  正典が求めているのは**分解の規約**であって規則の本数ではない (「例外を要する対象は、
  その対象だけを見る規則へ分ける決まりにしてある」)。
  語彙の側は I6 (vendor preset との集合一致) で**取りこぼしゼロを機械で証明する**ので、
  「本数が違う = 移植漏れ」にはならない。
- 語彙集合の正本は **vendor preset の配列**である。ArchBaseline は語彙を 7 規則へ**分割して**持ち、
  自己検査が「7 規則の和集合 == php ∪ security ∪ laravel の禁止語彙」を突き合わせる。
  **preset の語彙が vendor 更新で増えたら、どの規則にも属さない語彙として赤になる**。

### 成果物 (新設 6 ファイル + 乖離台帳 2 ファイル)

走査ロジックは値の置き場から分離し、aicue の既存作法
(`tests/Support/` の純関数 + `tests/Unit/Architecture/` の自己検査) に揃える。

| ファイル | 役割 |
|---|---|
| `tests/Support/Architecture/ArchBaseline.php` | **値の置き場**。規則 ID => `{symbols, exceptions, rationale}`。解析・ファイル I/O・git 実行を一切持たない (`LedgerPins` と同型) |
| `tests/Support/Architecture/GlobalFunctionCallScanner.php` | S2 用。ソース中の「素のグローバル関数呼び出し」の綴りを列挙する純関数。**Pest の検出集合の部分集合だけを数える** |
| `tests/Support/Architecture/ArchSurfaceScanner.php` | S4 用。`preset` / `ignoring` / `expect` の識別子出現を列挙し、**出現位置からのトークン列切り出し**まで返す純関数。**広く数える** |
| `tests/Support/Architecture/VendorArchPresetReader.php` | S5 用。vendor preset ソースから禁止語彙集合を抽出。fail-closed |
| `tests/Architecture/ArchBaselineTest.php` | gate。`foreach` 1 本からの `arch()` 表明 7 本 + 自己検査 5 部 |
| `tests/Unit/Architecture/ArchBaselineScannerTest.php` | 3 走査器の**負例と正例** |
| `docs/template-divergence.md` (追記) | 逸脱の登録 1 件 (D37 相当) |
| `tests/Support/TemplateDivergence/LedgerPins.php` (1 定数) | `DIVERGENCE_ENTRY_COUNT` 36 → 37 |

---

## 期待効果

### 使命への貢献

aicue の使命は「専門知識ゼロの現場作業者が標準化されたマニュアル動画を作れるようにする」ことであり、
本改善は直接には UI にも撮影フローにも触れない。**寄与は間接的だが構造的**である:

- aicue のセキュリティ不変条件 (AGENTS.md §セキュリティ不変条件 1〜11) は
  **131 本の deny-by-default 目録**という一点に依存している。
  「禁止したはずの書き方が検査を素通りする」形の穴は、この依存を静かに空洞化させる。
  撮影 PWA が依存する 3 枚セット (no-store / bfcache 秘匿 / Inertia 履歴暗号化) のように
  **壊れても画面上は何も起きない**保護ほど、機械の網の健全性そのものが品質になる。
- 正典が塞ぐのは「**検査は緑なのに穴が開いていた**」型の事故であり、これは AGENTS.md
  §静的検査 (gate) と走査器の共通規約が 5 条とも実測事故から出ていると明記している型と同じである。
  今 arch API を穴の無い形で入れておけば、将来 arch を使い始める時点で穴が生まれない。

### 具体的な改善見込み

- **Pest Arch が静的に解決できるシンボル使用に対する網が新設される** (禁止語彙 97)。
  既存 131 本には「禁止関数の網に相当する gate」が無い (lctl の観測どおり)。
  既存の `ForbiddenStatementTokenInvariantTest` は **`echo` / `goto` / `global` / 開始タグ付き出力記法の
  4 語彙だけ**を字句で見るもので、対象も方式も別物である (正典側も
  `forbidden-statement-token-gate` との関係を `distinct_from` として「統合しない」と宣言済み)。
- **例外登録の腐敗が検出できる**。`sha1` の使用をやめたのに例外登録が残る、
  クラスを改名したのに登録が古いまま、といった状態が赤になる (I4)。
  aicue の既存目録群と同じ「登録の腐りを落とす」思想を arch 側にも持ち込む。
- **家系 6/6 で機構が揃う** (裁定 AG-167 の達成)。

### 保証しないもの (誇張しない)

- 効くのは **Pest Arch が静的に解決できるシンボル使用**だけである。
  可変関数 (`$f = 'sha1'; $f()`)、`call_user_func('sha1')` のような文字列経由の呼び出し、
  外部プロセス、eval 内の綴りには**無言で効かない**。
- 走査域は **`App\` / `Database\Factories\` / `Database\Seeders\` の 3 根**だけである。
  `Tests\` は Pest arch 自身が除外するので**テスト側の禁止関数は 1 件も見ない**。
  `.blade.php` / `resources/js/` も対象外。
- **既存の token gate (`ForbiddenStatementTokenInvariantTest`) / SSRF 検査 / LLM 防御の代替ではない**。
  対象語彙も走査域も方式も別で、どちらか一方があれば他方が要らないという関係にはならない。

---

## 実装方針（概要）

### `tests/Support/Architecture/ArchBaseline.php` — 値の置き場

- `final class`、インスタンス化しない (private コンストラクタ)。
- 規則の正本は `RULES` 定数 1 本。各規則は
  `{symbols: list<string>, exceptions: list<class-string>, rationale: string}`。
- `rationale` は **30 文字以上**を要求する (aicue の目録規約と同じ強度。例外の登録操作が
  レビューで必ず見えるようにする)。例外を持たない規則の `rationale` は「なぜこの束が
  例外を要しないか」を書く。
- アクセサは純関数 (`ruleIds()` / `symbolsOf()` / `exceptionsOf()` / `allSymbols()`)。
  **解析・ファイル I/O・git 実行を持たない**。

### `tests/Architecture/ArchBaselineTest.php` — gate

**A. 禁止表明 (規則ごとに独立した `arch()` を、単一の生成点から作る)**

7 本を手書きせず、`ArchBaseline::ruleIds()` の **`foreach` 1 本**から生成する:

```php
foreach (ArchBaseline::ruleIds() as $ruleId) {
    arch($ruleId.': '.ArchBaseline::titleOf($ruleId))
        ->expect(ArchBaseline::symbolsOf($ruleId))
        ->not->toBeUsed()
        ->ignoring(ArchBaseline::exceptionsOf($ruleId));
}
```

- **`preset(` は 1 度も呼ばない**。規則は `ArchBaseline` から 1 本ずつ展開される。
- **`ignoring` の呼び出し箇所はリポジトリ全体で 1 つ**になる。
  これにより S4 は「出現はちょうど 1 件」に加えて
  「**その 1 件の引数トークン列が `( ArchBaseline :: exceptionsOf ( $ruleId ) )` と完全一致する**」
  まで固定できる。件数 pin だけでは防げない「許可された口へ生のクラス名を直書きする」迂回が塞がる。
- 対称性のため `expect` の引数も `ArchBaseline::symbolsOf($ruleId)` であることを同じ方式で照合し、
  **語彙の直書き**も同時に塞ぐ。
- `arch()` は `TestCall` を返す通常のテスト宣言関数なので (vendor 実装
  `pest-plugin-arch/src/Autoload.php` で確認)、`foreach` の中から呼んでよい。
  テスト名は規則 ID を含むので一意になる (規則 ID の一意性は S3 が固定)。

**B. 自己検査 5 部**

| 部 | 検査 | 落ちる条件 |
|---|---|---|
| S1 期待値の pin | 規則ごとの対象シンボル数を定数で pin | 語彙が無断で増減した |
| S2 逆向き証明 | 各例外クラスのソースを `GlobalFunctionCallScanner` で走査し、対象シンボルの**素の関数呼び出し**が 1 件以上あること | 登録が腐った (使用をやめた / 改名した / そもそも使っていない) |
| S3 構造契約 | 例外を持つ規則の対象シンボルはちょうど 1 個 / 規則 ID は一意 / 語彙は全規則を通じて重複しない / 例外クラスは実在し PSR-4 走査域内 / `rationale` は 30 文字以上 | 分解の規約が壊れた |
| S4 サーフェスの pin | **`tests/` 配下の git 追跡 PHP 全数**を母集団に、`preset` の識別子出現が 0 件 / `ignoring` の出現は `ArchBaselineTest.php` 内の**ちょうど 1 件**で、その**引数トークン列が期待形と完全一致** / `expect` の引数も同様 | 例外の置き場が二重化した / preset 一括使用が復活した / 許可された口へ生のクラス名を直書きした |
| S5 vendor preset との集合一致 | 7 規則の和集合 == php ∪ security ∪ laravel preset の禁止語彙集合 | vendor 更新で語彙が増減した / 移植漏れ |

### 3 つの走査器の設計方針

**`GlobalFunctionCallScanner` (S2 用) — Pest の検出集合の部分集合だけを数える**

S2 は「違反の検出」ではなく「**使用の証明**」なので、**倒す向きが他の走査と逆**である。
数えすぎ = 腐った登録を見逃す (危険) / 数え漏らし = 赤 (安全)。

vendor 実読による意味論の裏取り: Pest arch の使用判定は
`PHPUnit\Architecture\Asserts\Dependencies\Elements\ObjectUses::getByName()` が担い、
実装は **`substr($use, -strlen($name)) === $name` の接尾辞一致**である
(`vendor/ta-tikoma/phpunit-architecture-test/`)。つまり Pest 側は、素の `sha1()` が
`sha1` と記録されようと `App\Foo\sha1` と記録されようと**どちらも `sha1` の使用として検出する**。
名前空間に同名関数があっても Pest の検出集合からは外れない。
一方 Pest の接尾辞一致は `mysha1()` のような**別関数まで拾う**。

したがって S2 の契約は「**Pest が検出する使用のうち、綴りがトークン完全一致する
素のグローバル関数呼び出しだけを数える** (= Pest の検出集合の真部分集合)」とする。

- 数える: `sha1(` / `\sha1(`
- 数えない: `->sha1(` / `?->sha1(` / `::sha1(` / `function sha1(` / `new sha1(` / 直前が識別子 /
  `mysha1(` (Pest は拾うが S2 は数えない)
- 保証外 (数えない = 赤へ倒す): 可変関数・文字列経由の呼び出し
- ファイルが読めない / トークン化できない場合は**無言で 0 件にせず例外**
- 残る不確実性 (vendor が接尾辞一致をやめたら、登録が保守的に余りうる) は**穴にならない** —
  I2 が blast radius を 1 シンボルに抑えているので、余った例外が隠せるのは
  「その 1 シンボルの、その 1 クラスでの使用」だけだからである

**`ArchSurfaceScanner` (S4 用) — 広く数え、引数の形まで照合する**

こちらは「違反の検出」なので拾いすぎる方向へ倒す。
識別子トークン `preset` / `ignoring` / `expect` の**完全一致**で数える
(部分文字列一致・正規表現の語境界に頼らない)。
加えて、出現位置から**トークン列を切り出して期待形と完全一致で照合する**責務を持つ
(Critical 対応。専用クラスを増やさずここに収める)。
戻り値は小さな値オブジェクト `ArchSurfaceSite` の `list<>` で返し、
`token_get_all()` の生の戻り値を走査器の外へ出さない。
保証しないもの (可変メソッド名・文字列経由・`.blade.php`・`tests/js/`) を docblock に明記する。

**`VendorArchPresetReader` (S5 用) — fail-closed**

- 入力元は `Pest\ArchPresets\{Php,Security,Laravel}` の**ソース**
  (`class_exists()` で実在を確認 → `ReflectionClass::getFileName()` で解決。パス直書きしない)。
- 抽出定義: `expect(` の直後に始まる配列リテラルのうち、閉じ括弧の後に `->not->toBeUsed()` が
  続くものの文字列要素。
- **期待する配列の個数を pin** (Php:1 / Security:1 / Laravel:1)。0 個でも 2 個でも赤。
- docblock に「**vendor の公開 API ではなくソース表現に依存する。`composer update` で赤くなり得るのは
  仕様であり、そのときはベースラインを更新する**」と明記する。

### 検出力の裏取り (AGENTS.md §静的検査の共通規約 (c))

`tests/Unit/Architecture/ArchBaselineScannerTest.php` が 3 走査器の**負例と正例**を持つ:

- 正例: `FakeObjectStore` の `sha1` を検出できる / preset ソースから語彙集合を取り出せる
- 負例 (取り違え): メソッド宣言・interface のメソッド宣言・メソッド呼び出し・静的呼び出しを
  関数呼び出しと取り違えない。**現実の分岐**として
  `App\Services\Manual\SopTextExtractor::extract()` と
  `App\Services\Capture\TakeThumbnailExtractor::extract()` を使う
  (security preset の `extract` と綴りが一致するため)
- 負例 (語彙): **接頭辞つき (`getenv` / `mysha1`) / 接尾辞つき (`sha1_file`) / 打ち消しつき**の 3 形が
  トークン完全一致で弾かれる (共通規約 (e))。`mysha1` は「Pest は接尾辞一致で拾うが
  S2 は数えない」ことを固定する負のコントロールでもある
- 負例 (引数の出所): `->ignoring([Foo::class])` のような直書き形が S4 の期待形照合で落ちる
  (Critical 対応の裏取り)
- 負例 (fail-closed): 読めないファイル / 期待する配列が見つからない preset ソースで例外になる

### 母集団が空でないことの検査 (共通規約 (b) の 3 番目)

- `ArchBaseline::RULES` が空でない / 各規則の `symbols` が空でない
- vendor preset から抽出した語彙集合が 3 つとも空でない
- S4 の走査根 (`tests/` 配下の追跡 PHP) が空でない (床値 + 代表パスを pin)
- 例外クラスのソースファイルが解決できること (解決できなければ**無言で外さず**赤)

---

## 制約・前提

- **既存 131 本は 1 本も削除・置換しない** (裁定 AG-167 / `app-design` スキルの禁止事項 3 「既存テストの削除・上書き」)。
  アプリコード (`app/` `routes/` `config/` `database/` `resources/`) も 1 行も変更しない。
- **走査域は `App\` / `Database\Factories\` / `Database\Seeders\` の 3 根**。
  `Tests\` は Pest arch の `Composer::userNamespaces()` が除外するため入らない。
- **`phpstan.neon` は触らない**。aicue の PHPStan 対象は `app / config / database / routes` で
  **`tests/` を含まない**のが既存の方針であり、本設計はそれを変えない。
  加えて `phpstan.neon` は **採用時債務一覧 (`adoption-debt.tsv`) に凍結済み**のパスなので、
  触ると債務の扱い (戻す / 同期する / 逸脱登録する) の判断を巻き込む。**スコープ外**とする。
  代わりに型の受入条件を持つ (下記)。
- **型の受入条件** (「PHPStan level 10 を通せる」とは主張しない):
  - `mixed` や曖昧な配列へ widen しない
  - `RULES` の shape を PHPDoc で固定し、アクセサの戻り値まで型を一貫させる
  - 境界 (Reflection・token API・ファイル読み込み) は `Webmozart\Assert\Assert` で runtime に閉じる
  - 3 走査器の公開メソッドは**戻り値を正規化してから返す** (`list<string>` / 値オブジェクトの `list<>`)。
    `token_get_all()` の生の戻り値は走査器の外へ出さない
  - 実装時に `vendor/bin/phpstan analyse` へ新設パスを**コマンドライン引数で**渡して 1 度確認する
    (設定ファイルは変更しない)
- **`tests/Pest.php` は触らない**。arch 表明は Architecture レーンの通常のテストファイルとして走る。
- **乖離台帳**: 新設パスは `docs/template-fingerprints.json` のキーに**無い** (母集合 281 件に不在) ため
  突合 gate は現時点で沈黙する。ただし正典側には同名パスが実在し**内容は一致しない**ので、
  「登録するか迷ったら登録する」に従い `docs/template-divergence.md` へ 1 件登録し
  `LedgerPins::DIVERGENCE_ENTRY_COUNT` を 36 → 37 にする。
  突合の等式は `{全登録の対象パス} ∩ {母集合}` を取るので、母集合外の登録は 3b (一致へ戻ったのに
  登録が残っている) で落ちない = 先回りの登録をしても安全である。

---

## スコープ外 (明示)

1. **層分離規則 (`toOnlyBeUsedIn` / `toOnlyUse` / `toBeUsedIn`) の導入**。
   実測で `App\Http\*` は `app/` 内の **12 ファイル以上**の他名前空間 (Exceptions / Enums /
   DataTransferObjects / Models / Auth) から使われており、Laravel preset の
   `expect('App\Http')->toOnlyBeUsedIn(['App\Http','App\Providers'])` を今入れると
   **巨大な allowlist を新設する**ことになる。それは正典が塞ごうとした「例外の膨張で
   検知が空洞化する」状態を自分で作る行為である。
   機構が入れば `RULES` へ 1 エントリ足すだけで後日 1 本ずつ追加できるので、
   **機構の導入と規則の拡張を分ける** (思考原則 2: 今必要なものだけ作る)。
2. **Laravel preset の構造契約 (`toHaveSuffix` / `toExtend` / `toImplement` / `toBeEnums` 等)**。
   これらは「禁止関数・層分離」のどちらでもなく、集合一致で健全性を証明できない
   (S5 の対象にならない) ため、同じ機構では守れない。
3. **既存 131 本の統廃合・移植**。裁定は併存を明示している。
4. **`docs/TODO.md` の変更** (本スキルの責務外)。
5. **CI ワークフロー・`composer.json` / `phpstan.neon` の変更**。
   新規テストは既存の Architecture レーンで走る。
6. **`AGENTS.md` §禁止事項への追記**。S4 が機械で固定するので文書への二重管理は避ける
   (詳細設計で最終判断する)。
7. **spirux 側の追従**。本設計は aicue のみを扱う。


---

## 再レビュー依頼

Round 2 と同じ 7 観点で再判定してください。特に:

1. Critical (`ignoring` の引数の出所) — 「表明の生成を `foreach` 1 本へ閉じ、`ignoring` の呼び出しをリポジトリ全体で 1 箇所にしたうえで、その引数トークン列を期待形と完全一致で照合する」で穴が塞がっているか。まだ迂回路が残るなら具体的に示してください。
2. Warning 5 (S2 の名前空間解決) — vendor 実装 (`ObjectUses::getByName()` の接尾辞一致) を根拠に「S2 は Pest の検出集合の真部分集合だけを数える」と定義し直した論法が成立しているか。
3. スコープが最小に保たれているか。

出力形式は同じ (全体判定 + 観点ごとの [Critical]/[Warning]/[Suggestion])。
