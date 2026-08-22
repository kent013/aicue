# Round 4: Round 3 指摘への対応と改訂版概念設計

Round 3 の指摘 (Critical 1 / Warning 3) をすべて捌きました。反論はゼロです。
Critical については助言どおり**先に母集団を実測**し (tests/ 全数 803 ファイル / 動的メンバ名 7 件・6 ファイル)、
全数を母集団にする exact-fit の目録で「未解決として落とす」側を採りました。

## 対応マトリクス

# 対応マトリクス: conceptual-review Round 3

## [Critical] 3. 可変メソッド呼び出し (`->{$method}(...)`) で `ignoring` を実行できる

- 判断: **対応する** (「保証外と書くだけ」では AGENTS.md 共通規約 (b) に反するという指摘は正しい)
- 根拠: 共通規約 (b) は
  「保証範囲の外にした構文で保護対象の操作を書ける場合、利用側 gate は
  **検出力の主張をその構文を除く形へ明示的に狭める**か、**未解決として失敗させる**かのどちらかにする」
  と定める。I3 は「リポジトリ全体で 1 箇所」と主張しているので、狭める側を選ぶと主張が空洞化する。
  **未解決として失敗させる側**を採る。
- **母集団の実測** (助言に従い先に測った。`git ls-files -- 'tests/*.php'` の 803 ファイルを
  `token_get_all()` で走査):
  - 動的メンバ名 (`->` / `?->` / `::` の直後が `{` または変数) の出現は **7 件 / 6 ファイル**。
    すべて正当な用途 (Factory の状態名・HTTP メソッド名・名前つきコンストラクタ名) である。

    | ファイル | 件数 | 用途 |
    |---|---|---|
    | `tests/Feature/Billing/BillingAccessStateTest.php` | 1 | Factory の状態名 |
    | `tests/Feature/Billing/BillingCheckoutSessionModelTest.php` | 2 | Factory の状態名 |
    | `tests/Feature/Invitations/AcceptInvitationInAppTest.php` | 1 | Factory の状態名 |
    | `tests/Feature/Invitations/PendingInvitationScopeTest.php` | 1 | Factory の状態名 |
    | `tests/Feature/Organizations/TwoFactorEnforcementTest.php` | 1 | HTTP メソッド名 |
    | `tests/Unit/Exceptions/AnalysisFailedExceptionTest.php` | 1 | 名前つきコンストラクタ名 |

  - `tests/Architecture/` には **0 件**である。
- 対応内容: S4 へ検査を 1 本足す。
  **`tests/` 配下の追跡 PHP 全数で動的メンバ名を列挙し、`ArchBaseline` が持つ目録と
  ファイル別件数まで exact-fit で一致すること** (増えても減っても赤)。
  目録の各行は **30 文字以上の根拠**を持つ (aicue の既存 deny-by-default 目録と同じ強度)。
  - **メソッド呼び出しとプロパティ参照を区別しない**。区別には波括弧の対応付けが要るところを、
    区別せず広く数える (拾いすぎる方向 = 安全。共通規約 (b))。
  - 「母集団を arch 語彙を含むファイルに絞る」案は**採らない**。
    語彙で母集団を絞ると `expect([...])->not->{$m}()` (`$m = 'toBeUsed'`) の形が
    どの語彙にも一致せず母集団から外れ、絞り込み自体が動的ディスパッチで破れるからである。
    測ってみると全数でも 7 件しか無いので、全数を母集団にする費用は小さい。

## [Warning] 3. `expect` の検査範囲が曖昧 (通常の Pest assertion と区別できない)

- 判断: **対応する** (指摘のとおり、識別子単位の pin では区別できない)
- 根拠: `expect(` は全 Feature/Unit テストに大量に現れるし、本 gate の自己検査 5 部自身も使う。
  識別子の件数を pin する形は成立しない。
- 対応内容: **識別子単位の pin をやめ、チェーン単位の完全一致照合に置き換える**。
  - `arch` 識別子の出現は `tests/` 全数で**ちょうど 1 件**、かつ `tests/Architecture/ArchBaselineTest.php` 内。
  - その 1 件から文末 `;` までの**トークン列が期待形と完全一致**する:

    ```
    arch ( ArchBaseline :: descriptionOf ( $ruleId ) )
      -> expect ( ArchBaseline :: symbolsOf ( $ruleId ) )
      -> not -> toBeUsed ( )
      -> ignoring ( ArchBaseline :: exceptionsOf ( $ruleId ) ) ;
    ```

  - `ignoring` / `toBeUsed` の識別子出現は `tests/` 全数でそれぞれ**ちょうど 1 件**
    (上のチェーンの中)、`preset` は **0 件**。
  - `expect` は**全数では数えない**。チェーン内での位置と引数が上の完全一致照合で固定される。
  - 正例・負例: 期待形どおりのチェーンが通ること / `->ignoring([Foo::class])` の直書き形が落ちること /
    チェーンを 2 本目に増やすと落ちること / `->not->toBeUsed()` を落としたチェーンが落ちること。

## [Warning] 5. vendor が接尾辞一致をやめたら I4 の意味上の契約が崩れる

- 判断: **対応する** (Codex の提案 1 = 最小案を採る)
- 根拠: 「Pest の検出集合の部分集合である」ことを保証し続けるには vendor の内部意味論への
  トリップワイヤが要り、スコープが増える。正典が求める逆向き証明は
  「**登録した例外クラスが対象シンボルを実使用しているか**」であって、
  「Pest がそれを検出するか」ではない。構文上の契約で正典の要求は満たせる。
- 対応内容: **I4 の契約を構文上の使用証明へ限定する** —
  「登録クラスのソースに、対象シンボルと**綴りがトークン完全一致する素の関数呼び出し**が
  1 件以上存在する」。vendor の接尾辞一致の話は**契約ではなく背景**として
  走査器の docblock に「なぜ `mysha1()` を数えないか」の理由の形で残す
  (Pest は拾うが、使用証明の偽陽性になるので数えない)。
  I4 の文言から「Pest の検出集合の部分集合」という保証の主張を落とした。

## [Warning] 6 / 7. `ArchSurfaceSite` の配置と型が未確定

- 判断: **対応する** (ファイル数を増やさない側を選ぶ)
- 根拠: 値オブジェクトを 1 本増やすほどの不変条件をコンストラクタに持たせる必要が無い。
  aicue には `ReferenceSite` のような値オブジェクトもあるが、本件の戻り値は
  走査器の内部で組み立ててすぐ照合に使うだけである。
- 対応内容: `ArchSurfaceScanner` の公開メソッドの戻り値を
  **型付き array shape の `list<>`** として明記した:
  - `identifierSites(string $relativePath, string $phpSource, string $identifier): list<array{line: int, index: int}>`
  - `statementTokens(string $phpSource, int $index): list<string>` (指定位置から文末 `;` までの綴り列)
  - `dynamicMemberSites(string $relativePath, string $phpSource): list<array{line: int}>`

  `token_get_all()` の生の戻り値は走査器の外へ出さない。成果物は **6 ファイルのまま**据え置く。


---

## 改訂版 概念設計 (全文)

# 概念設計: pest-arch-baseline-per-rule-adoption

> Round 1 (Critical 1 / Warning 5)・Round 2 (Critical 1 / Warning 4)・Round 3 (Critical 1 / Warning 3) の
> Codex レビューを反映済み。対応の記録は `codex-history/conceptual-review-decisions-round-{1,2,3}.md`。

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
| I3 | **例外一覧は `ArchBaseline` 1 クラスにだけ在る**。arch のチェーンはリポジトリ全体で **1 本**だけで、その**トークン列が期待形と完全一致**する。動的メンバ名は**未解決として落とす** | S4 (母集団は `tests/` 全数。チェーンの完全一致照合 + 動的メンバ名の exact-fit 目録) |
| I4 | **登録した例外は実在し、そのソースに対象シンボルと綴りがトークン完全一致する素の関数呼び出しが 1 件以上ある** (登録の腐敗検出。構文上の契約) | S2 (逆向き証明) |
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
そこで表明の生成を `foreach` 1 本へ閉じ、**チェーンのトークン列そのもの**を
期待形と完全一致で照合する (下記「A. 禁止表明」)。

**識別子の件数 pin だけでも、まだ足りない** — `->{$method}(...)` のような動的メンバ名を使えば
`ignoring` という綴りを 1 度も書かずに同じ操作ができる。
共通規約 (b) は「保証範囲の外にした構文で保護対象の操作を書ける場合は、
検出力の主張を狭めるか、**未解決として失敗させる**」と定めるので、後者を採る —
`tests/` 全数の動的メンバ名を exact-fit の目録で固定する (実測 7 件 / 6 ファイル。
`tests/Architecture/` には 0 件)。

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
| `tests/Support/Architecture/ArchBaseline.php` | **値の置き場**。規則 ID => `{symbols, exceptions, rationale}` と、動的メンバ名の目録 (ファイル => `{count, rationale}`)。解析・ファイル I/O・git 実行を一切持たない (`LedgerPins` と同型) |
| `tests/Support/Architecture/GlobalFunctionCallScanner.php` | S2 用。ソース中の「素のグローバル関数呼び出し」の綴りを列挙する純関数。**Pest の検出集合の部分集合だけを数える** |
| `tests/Support/Architecture/ArchSurfaceScanner.php` | S4 用。識別子出現の列挙 / 文末までのトークン列切り出し / 動的メンバ名の列挙を返す純関数。**広く数える** |
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
- アクセサは純関数 (`ruleIds()` / `descriptionOf()` / `symbolsOf()` / `exceptionsOf()` / `allSymbols()`)。
  **解析・ファイル I/O・git 実行を持たない**。
- 第 2 の定数として**動的メンバ名の目録** (`array<string, array{count: int, rationale: string}>`) を持つ。
  これは arch の例外ではなく「**走査器が解決できない形の在庫**」だが、
  正典の「値の置き場は 1 つ」に従い同じクラスへ置き、docblock で役割を分ける。
  各行は 30 文字以上の根拠を持つ。

### `tests/Architecture/ArchBaselineTest.php` — gate

**A. 禁止表明 (規則ごとに独立した `arch()` を、単一の生成点から作る)**

7 本を手書きせず、`ArchBaseline::ruleIds()` の **`foreach` 1 本**から生成する:

```php
foreach (ArchBaseline::ruleIds() as $ruleId) {
    arch(ArchBaseline::descriptionOf($ruleId))
        ->expect(ArchBaseline::symbolsOf($ruleId))
        ->not->toBeUsed()
        ->ignoring(ArchBaseline::exceptionsOf($ruleId));
}
```

- **`preset(` は 1 度も呼ばない**。規則は `ArchBaseline` から 1 本ずつ展開される。
- **`ignoring` の呼び出し箇所はリポジトリ全体で 1 つ**になる。
  これにより S4 は「`arch` 識別子の出現は `tests/` 全数でちょうど 1 件」に加えて
  「**その 1 件から文末 `;` までのトークン列が期待形と完全一致する**」まで固定できる:

  ```
  arch ( ArchBaseline :: descriptionOf ( $ruleId ) )
    -> expect ( ArchBaseline :: symbolsOf ( $ruleId ) )
    -> not -> toBeUsed ( )
    -> ignoring ( ArchBaseline :: exceptionsOf ( $ruleId ) ) ;
  ```

  件数 pin だけでは防げない「許可された口へ生のクラス名を直書きする」迂回が塞がる。
- 照合は**識別子単位ではなくチェーン単位**で行う。`expect(` は全テストに大量に現れるので
  件数 pin は成立しない — チェーン内での位置と引数が完全一致照合で固定されることで
  **語彙の直書き**も同時に塞がる。
- `arch()` は `TestCall` を返す通常のテスト宣言関数なので (vendor 実装
  `pest-plugin-arch/src/Autoload.php` で確認)、`foreach` の中から呼んでよい。
  テスト名は規則 ID を含むので一意になる (規則 ID の一意性は S3 が固定)。

**B. 自己検査 5 部**

| 部 | 検査 | 落ちる条件 |
|---|---|---|
| S1 期待値の pin | 規則ごとの対象シンボル数を定数で pin | 語彙が無断で増減した |
| S2 逆向き証明 | 各例外クラスのソースを `GlobalFunctionCallScanner` で走査し、対象シンボルの**素の関数呼び出し**が 1 件以上あること | 登録が腐った (使用をやめた / 改名した / そもそも使っていない) |
| S3 構造契約 | 例外を持つ規則の対象シンボルはちょうど 1 個 / 規則 ID は一意 / 語彙は全規則を通じて重複しない / 例外クラスは実在し PSR-4 走査域内 / `rationale` は 30 文字以上 | 分解の規約が壊れた |
| S4 サーフェスの pin | **`tests/` 配下の git 追跡 PHP 全数**を母集団に、(1) `preset` の識別子出現 0 件 / (2) `arch` `ignoring` `toBeUsed` の識別子出現が各ちょうど 1 件 / (3) `arch` の出現から文末までの**トークン列が期待形と完全一致** / (4) 動的メンバ名が**目録とファイル別件数まで exact-fit** | 例外の置き場が二重化した / preset 一括使用が復活した / 生のクラス名を直書きした / 動的ディスパッチで綴りを回避した |
| S5 vendor preset との集合一致 | 7 規則の和集合 == php ∪ security ∪ laravel preset の禁止語彙集合 | vendor 更新で語彙が増減した / 移植漏れ |

### 3 つの走査器の設計方針

**`GlobalFunctionCallScanner` (S2 用) — 構文上の使用証明。狭く数える**

S2 は「違反の検出」ではなく「**使用の証明**」なので、**倒す向きが他の走査と逆**である。
数えすぎ = 腐った登録を見逃す (危険) / 数え漏らし = 赤 (安全)。

**契約は構文上のものに限定する** —
「登録クラスのソースに、対象シンボルと**綴りがトークン完全一致する素の関数呼び出し**が
1 件以上存在する」。**「Pest がその使用を検出する」ことは保証しない** (vendor の内部意味論に
契約をぶら下げないため)。

- 数える: `sha1(` / `\sha1(`
- 数えない: `->sha1(` / `?->sha1(` / `::sha1(` / `function sha1(` / `new sha1(` / 直前が識別子 /
  `mysha1(`
- 保証外 (数えない = 赤へ倒す): 可変関数・文字列経由の呼び出し
- ファイルが読めない / トークン化できない場合は**無言で 0 件にせず例外**
- 背景 (契約ではない): Pest 側の使用判定は
  `PHPUnit\Architecture\Asserts\Dependencies\Elements\ObjectUses::getByName()` の
  **接尾辞一致** (`substr($use, -strlen($name)) === $name`) である
  (`vendor/ta-tikoma/phpunit-architecture-test/` を実読)。
  Pest は `mysha1()` まで拾うが、S2 がそれを真似ると使用証明の偽陽性になるので数えない。
  この差で登録が保守的に余ることがあっても**穴にはならない** —
  I2 が blast radius を 1 シンボルに抑えているので、余った例外が隠せるのは
  「その 1 シンボルの、その 1 クラスでの使用」だけだからである

**`ArchSurfaceScanner` (S4 用) — 広く数え、チェーンの形まで照合する**

こちらは「違反の検出」なので拾いすぎる方向へ倒す。

- `identifierSites()`: 識別子トークンの**完全一致**で出現位置を返す
  (部分文字列一致・正規表現の語境界に頼らない)
- `statementTokens()`: 指定位置から文末 `;` までの**綴り列**を返す (チェーンの完全一致照合用)
- `dynamicMemberSites()`: `->` / `?->` / `::` の直後が `{` または変数である位置を返す。
  **メソッド呼び出しとプロパティ参照を区別しない** (区別には波括弧の対応付けが要るところを、
  区別せず広く数える = 拾いすぎる方向 = 安全)

戻り値は**型付き array shape の `list<>`** で返し (`list<array{line: int, index: int}>` /
`list<string>`)、`token_get_all()` の生の戻り値を走査器の外へ出さない。
値オブジェクトのファイルは増やさない。
保証しないもの (文字列経由の呼び出し・`.blade.php`・`tests/js/`) を docblock に明記する。

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
- 負例 (引数の出所): `->ignoring([Foo::class])` のような直書き形 / チェーンを 2 本へ増やした形 /
  `->not->toBeUsed()` を落とした形が、S4 の期待形照合で落ちる (Round 2 Critical の裏取り)
- 負例 (動的ディスパッチ): `->{$method}([Foo::class])` / `::{$m}()` / `->$m()` を
  `dynamicMemberSites()` が拾い、目録に無いので S4 が落ちる (Round 3 Critical の裏取り)
- 負例 (fail-closed): 読めないファイル / 期待する配列が見つからない preset ソースで例外になる

### 母集団が空でないことの検査 (共通規約 (b) の 3 番目)

- `ArchBaseline::RULES` が空でない / 各規則の `symbols` が空でない
- vendor preset から抽出した語彙集合が 3 つとも空でない
- S4 の走査根 (`tests/` 配下の追跡 PHP) が空でない (床値 + 代表パスを pin)
- 動的メンバ名の目録が空でない (実測 7 件 / 6 ファイル。0 件になったら走査の故障を疑う)
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

同じ 7 観点で再判定してください。特に:

1. Critical (動的ディスパッチ) — 「tests/ 全数の動的メンバ名を exact-fit の目録で固定する」で、共通規約 (b) の「未解決として失敗させる」を満たしているか。母集団を語彙で絞る案を退けた論拠 (絞り込み自体が動的ディスパッチで破れる) が成立しているか。
2. Warning (`expect` の範囲) — 識別子件数 pin をやめてチェーン単位の完全一致照合に置き換えたことで、通常の `expect()` との混同が消えているか。
3. Warning (S2 の契約) — I4 を構文上の契約へ限定した判断が、正典の「逆向き証明」の要求を満たしているか。
4. スコープが最小に保たれているか (6 ファイル据え置き / 値オブジェクトを増やさない)。

残る指摘が無ければ APPROVED を、あるなら具体的な迂回路とともに指摘してください。
出力形式は同じ (全体判定 + 観点ごとの [Critical]/[Warning]/[Suggestion])。
