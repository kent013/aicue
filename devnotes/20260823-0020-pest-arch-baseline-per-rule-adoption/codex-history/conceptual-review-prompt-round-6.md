# 概念設計レビュー Round 6

前回 (Round 5) の指摘 2 件への対応と、**引き継ぎ時の再測定で自分から見つけた自己訂正 2 件**を反映した。
本ラウンドで審議してほしいのは次の 3 点である。

1. Round 5 の [Warning] 3 (callable 4 関数の完全修飾名・別名取り込みの解決) への対応が十分か
2. Round 5 の [Warning] 7 (未解決を判別できる形で返す) への対応が十分か
3. **自己訂正 1** — `dynamicMemberSites()` の定義が pin した件数と矛盾していたので定義を絞った。
   「広く数える」原則を弱めていないか、共通規約 (b) に反していないかを見てほしい

なお本設計は**担当者が交代している**。引き継ぎにあたり、設計が pin しているすべての実測値を
main `2dc4e2ec` で `token_get_all()` により測り直した。その結果が自己訂正 1 と 2 である。

---

## 対応マトリクス (Round 5 の指摘 + Round 6 送付前の自己訂正)

# 対応マトリクス: conceptual-review Round 5

> ℹ **本ラウンドの対応は概念設計へ反映済み**。前任者は `app-design` スキルの合議ループ上限
> (最大 5 ラウンド) に到達したためここで一度打ち切ったが、**監督者の裁量でラウンド上限を
> +3 まで延長**して Round 6 以降を同じセッションで resume した。
> Round 6 送付前に実施した自己訂正 2 件は本ファイル末尾に記録してある。

## Round 5 の判定

- 全体判定: **CHANGES_REQUESTED**
- **[Critical] 0 件** (Round 1〜4 の Critical はすべて解消済みと Codex が明言)
- [Warning] 2 件 / [Suggestion] 8 件
- Codex の総括: 「主要な設計は承認可能な水準です。残る修正は、callable 関数 4 種について
  完全修飾名・`use function` alias まで解決対象に含めるという走査契約の補足だけです。
  実装方法は詳細設計へ送れますが、検出範囲そのものは概念設計で確定させる必要があります」

## [Warning] 3. callable 実行語彙の 0 件固定が、完全修飾名・別名 import を取りこぼす

- 判断: **対応する** (概念設計へ反映済み。**未レビュー**)
- 根拠: 正しい。`\call_user_func(...)` は PHP 8 のトークン化で `T_NAME_FULLY_QUALIFIED` として
  1 トークンに畳まれ、`call_user_func` という独立した `T_STRING` は現れない。
  `use function call_user_func as invoke;` なら呼び出し位置には `invoke` しか出ない。
  素の識別子完全一致だけでは「0 件で固定した」という主張が成立しない。
  これは AGENTS.md 共通規約 (a) (クラス参照は完全修飾名で突き合わせる。短名一致は
  別名つき取り込み 1 つで検査が黙る) が関数名についても同じく効く場面である。
- 対応内容 (概念設計の「塞ぐ (2)」の直下へ走査契約として明記):
  - 4 関数は `T_STRING` だけでなく、**完全修飾名 (`T_NAME_FULLY_QUALIFIED`) と
    修飾名 (`T_NAME_QUALIFIED`) を正規化して完全修飾関数名として照合**する。
  - **`use function` / group use / 別名つき取り込みを解いて** alias 経由の呼び出しも検出する。
  - **解決できない取り込み・呼び出し形は未解決として失敗させる** (共通規約 (b))。
  - `fromCallable` は**メソッド名**なので別契約 (メンバ名としての完全一致) で検出する。
  - 正例・負例に `\call_user_func(...)` と `use function ... as ...` を必ず置く。
  - 走査器に `resolvedFunctionCallSites()` を追加する (新規ファイルは増やさない)。
- 併せて反映した [Suggestion]: I3 の表の文言を
  「リポジトリ全体で 1 本」→「**S4 が解決対象とする構文の中で 1 本**」へ変更し、
  表を単独で読んだときの誤解を防いだ。

## [Warning] 7. 名前解決を足すなら、走査結果は「解決済み」と「未解決」を判別できる型で返すべき

- 判断: **対応する** (概念設計へ反映済み。**未レビュー**)
- 根拠: 正しい。未解決を生の識別子と同じ値へ混ぜると、共通規約 (b) の
  「未解決を解決済みと同じ値へ混ぜない / 無言で候補から外さない」に反する。
- 対応内容: `ArchSurfaceScanner` の内部契約として
  「**未解決は判別できる形で返し、黙って候補から外さない**」を概念設計に明記した。
  公開戻り値の array shape を具体的にどう変えるかは、Codex の助言どおり**詳細設計へ送る**。

---

## 再開手順 (Round 6 を回す場合)

Codex のセッションは永続化してあるので、文脈を保ったまま続けられる。

```bash
scripts/codex exec resume 01a02a13-0078-7800-8e23-ee5b9faba5ae --json \
  -o devnotes/20260823-0020-pest-arch-baseline-per-rule-adoption/conceptual-review-round-6.md \
  - < devnotes/20260823-0020-pest-arch-baseline-per-rule-adoption/codex-history/conceptual-review-prompt-round-6.md
```

Round 6 のプロンプトには本ファイル (対応マトリクス) と改訂版概念設計の全文を載せる
(Round 2〜5 と同じ形)。

## 収束の推移 (参考)

| Round | 判定 | Critical | Warning |
|---|---|---|---|
| 1 | CHANGES_REQUESTED | 1 (サーフェス検査の欠落) | 5 |
| 2 | CHANGES_REQUESTED | 1 (`ignoring` 引数の出所) | 4 |
| 3 | CHANGES_REQUESTED | 1 (可変メンバ構文) | 3 |
| 4 | CHANGES_REQUESTED | 1 (callable / 反射経由) | 4 |
| 5 | CHANGES_REQUESTED | **0** | 2 |

Critical は単調に潰れており、Round 5 で 0 になっている。
残りは走査契約の文言 1 点であり、Round 6 で APPROVED に到達する見込みが高い。

---

## Round 6 送付前の自己訂正 (Codex の指摘ではなく、引き継ぎ時の再測定で発見)

Round 6 のプロンプトを組む前に、本設計が pin しているすべての実測値を
main `2dc4e2ec` で**測り直した**。前任者の測定のうち 2 点が誤っていた・陳腐化していたので訂正した。

### 訂正 1: `dynamicMemberSites()` の定義が、自分が pin した件数と矛盾していた (重大)

- **症状**: 概念設計は走査契約を「`->` / `?->` / `::` の直後が `{` **または変数**である位置」と
  書きながら、目録の実測を「7 件 / 6 ファイル」と pin していた。
  この定義で `tests/` の追跡 PHP 803 本を `token_get_all()` で実走査すると **52 件 / 14 ファイル**になる。
- **原因**: `::` の直後の変数には**意味の違う 2 形**がある。
  - `A::$m()` = **可変静的メソッド呼び出し** (名前が動的。塞ぐべき)
  - `self::$violations` = **静的プロパティ参照** (名前は `violations` で確定。動的ではない)

  後者が実測 **45 件 / 8 ファイル** あり (`StrayHttpRequestGuard` / `PlainDataCacheGuard` /
  `StrayLlmCallGuard` / `TestCase` など)、定義どおり実装すると
  **arch と無関係な 45 件へ 30 文字以上の根拠を書かせる目録**が生まれる。
  これは正典が塞ごうとした「例外の膨張で検知が空洞化する」状態を自分で作る行為である。
- **対応**: 動的の定義を「**メンバ名の綴りが静的に決まらない形**」に確定させ、
  `(` を伴わない `::$var` を明示的に対象外にした。
  `->$var` / `?->$var` (動的プロパティ) と `A::$m()` (可変静的メソッド呼び出し) は**取りこぼさない**。
  この分岐は S4 で**唯一判定を狭める場所**なので、負例に `A::$m()` (拾う) と
  `self::$x` (拾わない) を隣り合わせで置いて固定することを裏取り節へ追記した。
- **再測定の結果 (定義訂正後)**: **7 件 / 6 ファイル** — 前任者の pin と一致する。
  つまり pin 自体は正しく、**文章化された定義だけが実装と食い違っていた**。

| 形 | 実測 (`tests/` 803 本) | 動的か |
|---|---|---|
| `->{expr}` / `?->{expr}` | 6 件 / 5 ファイル | ○ |
| `->$var` / `?->$var` | 0 件 | ○ |
| `::{expr}` | 1 件 / 1 ファイル | ○ |
| `::$var` + `(` (可変静的メソッド呼び出し) | 0 件 | ○ |
| `::$var` (静的プロパティ参照) | **45 件 / 8 ファイル** | **×** |

### 訂正 2: 乖離台帳の D 番号と件数 pin が、他 TODO のマージで陳腐化していた

- **症状**: 概念設計は「D37 相当 / `DIVERGENCE_ENTRY_COUNT` 36 → 37」と書いていたが、
  main には既に **D37 / D38 / D39 が登録済み**である (番号は再利用しない決まり)。
- **再測定 (main `2dc4e2ec`)**:

  | 値 | 実測 | 本設計での扱い |
  |---|---|---|
  | 登録済みの最大 D 番号 | D39 | 新番号は **D40** |
  | 実エントリ数 (`## D<n>` 見出し 37 個 − 書式節の見本 1 個) | 36 件 | **36 → 37** |
  | 冒頭の宣言行 | 「登録エントリ: 36 件」 | 「37 件」へ |
  | `LedgerPins::DIVERGENCE_ENTRY_COUNT` | 36 | **37** へ |
  | `LedgerPins::FINGERPRINT_POPULATION_COUNT` | 281 | **据え置き** (新設 6 パスは 281 キーに不在) |
  | `LedgerPins::ADOPTION_DEBT_COUNT` | 171 | **据え置き** (新設パスは債務一覧に無い) |

- **対応**: 上記を概念設計へ反映し、併せて
  「**これらの pin は実装着手時にもう一度 main で読み直す**」という注記を足した
  (本件そのものが、設計から実装までの間に他 TODO のマージで動く値であることの実例である)。

### 併せて精度を上げた実測値 (訂正ではなく更新)

- 反射経路の件数: 「Architecture テストが 40 か所」→ **`tests/` 全数で 41 件 / 25 ファイル**
  (トークン走査でコメント・文字列を除いた実数)。保証外にする判断そのものは変わらない。
- S4 の母集団: `tests/` の追跡 PHP は **803 本**。
- **`identifierSites()` がコメントと文字列リテラルを数えないことが load-bearing である**ことを判明させた。
  素の文字列検索では `preset` が 1 件 (`ForbiddenStatementTokenInvariantTest` の docblock)、
  callable 語彙が 2 件 (`CacheGuardWiringGateTest` / `JobDeferralTerminationGateTest` の docblock)
  一致してしまい、S4 の「0 件」は**初日から赤くなる**。
  トークン走査では 3 件とも `T_COMMENT` なので 0 件で、pin は成立する。
  この除外を共通規約 (b) の「未解決の黙殺」と取り違えないことを概念設計へ明記した。

---

## 改訂版 概念設計 (全文)

# 概念設計: pest-arch-baseline-per-rule-adoption

> **Codex 合議の状態**: Round 1〜5 を回し、Critical は Round 5 で 0 件になった
> (全体判定は CHANGES_REQUESTED)。Round 5 の指摘 (走査契約の補足) を反映し、
> **Round 6 を審議中**である。判定の推移と各ラウンドの対応は
> `codex-history/conceptual-review-decisions-round-{1..5}.md`。
>
> **実測値の基準コミット**: 本文の実測値はすべて **main `2dc4e2ec` (2026-08-22 / JST 2026-08-23)**
> で再測定した値である。Round 5 と Round 6 の間に、前任者の測定のうち 2 点を訂正した
> (`dynamicMemberSites()` の定義と実測件数の不整合 / 乖離台帳の D 番号と件数 pin の陳腐化)。
> 訂正の詳細は `codex-history/conceptual-review-decisions-round-5.md` の「Round 6 送付前の自己訂正」。

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
| `tests/` 配下の追跡 PHP (S4 の母集団) | **803 本** | `git ls-files 'tests/**/*.php'` |
| `arch` / `ignoring` / `toBeUsed` / `preset` の**識別子トークン** | 各 **0 件** | `token_get_all()` で `tests/` 803 本を走査。散文中の出現 (`preset` 1 件 / callable 系 2 件) はすべて `T_COMMENT` で識別子ではない |
| callable 実行語彙 5 種の識別子・完全修飾名 | **0 件** | 同上。`T_NAME_FULLY_QUALIFIED` / `T_NAME_QUALIFIED` でも 0 件 |
| 名前が動的に決まるメンバ参照 | **7 件 / 6 ファイル** | 同上 (定義は「塞ぐ (1)」。`tests/Architecture/` は 0 件) |

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
| I3 | **例外一覧は `ArchBaseline` 1 クラスにだけ在る**。arch のチェーンは **S4 が解決対象とする構文の中で 1 本**だけで、その**トークン列が期待形と完全一致**する。動的メンバ名は**未解決として落とし**、callable 経由の実行語彙は 0 件で固定する | S4 (母集団は `tests/` 全数。チェーンの完全一致照合 + 動的メンバ名の exact-fit 目録 + callable 実行語彙の deny-by-default) |
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
検出力の主張を狭めるか、**未解決として失敗させる**」と定める。
本設計は**費用ゼロで塞げる分は塞ぎ、残りは主張を狭める**という 2 段構えを採る:

- **塞ぐ (1)**: `tests/` 全数の**名前が動的に決まるメンバ参照**を exact-fit の目録で固定する
  (実測 **7 件 / 6 ファイル**。`tests/Architecture/` には 0 件)

  > **「動的」の定義 (概念設計で確定させる)**: 塞ぎたいのは
  > **メンバ名の綴りが静的に決まらない形**である。`->` / `?->` / `::` の直後を見て、
  > 次の 4 形を動的とする —
  > (i) `->{expr}` / (ii) `?->{expr}` / (iii) `::{expr}` / (iv) `->$var` / `?->$var` /
  > (v) `::$var` が**直後に `(` を伴う**形 (PHP の可変静的メソッド呼び出し `A::$m()`)。
  > **`::$var` が `(` を伴わない形は動的ではない** — それは `self::$violations` のような
  > **静的プロパティ参照**で、メンバ名 (`violations`) は綴りとして確定している。
  > この 1 形を混ぜると `tests/` 全数の実測が **7 件 / 6 ファイル → 52 件 / 14 ファイル**へ膨らみ、
  > 増えた 45 件はすべて arch と無関係な静的プロパティ参照になる。
  > 30 文字以上の根拠を 45 件書かせる目録は、正典が塞ごうとした「例外の膨張で
  > 検知が空洞化する」状態を自分で作る行為なので採らない。
  > **「広く数える」は無差別に数えることではなく、`->$var` (動的プロパティ) と
  > 可変静的メソッド呼び出しを取りこぼさないという意味である**
  > (メソッド呼び出しとプロパティ参照の区別は、`->` 側では**しない** = 広く数える)。
- **塞ぐ (2)**: `call_user_func` / `call_user_func_array` / `forward_static_call` /
  `forward_static_call_array` の 4 **関数**と、`fromCallable` という**メソッド名**の出現を
  **`tests/` 全数で 0 件**に固定する
  (実測でいずれも 0 件。**目録すら要らず既存テストへの影響もゼロ**)。

  > **走査契約 (概念設計で確定させる。実装方法は詳細設計へ送る)**:
  > 4 関数は素の識別子 (`T_STRING`) だけを見ない。
  > **完全修飾名 (`\call_user_func` = `T_NAME_FULLY_QUALIFIED`) と修飾名 (`T_NAME_QUALIFIED`) を
  > 正規化して完全修飾関数名として照合し、`use function` / group use / 別名つき取り込みを解いて
  > alias 経由の呼び出し (`use function call_user_func as invoke; invoke(...)`) も検出する**
  > (共通規約 (a))。
  > **解決できない取り込み・呼び出し形は未解決として失敗させる** (共通規約 (b))。
  > 走査結果は「解決済み関数名」と「未解決」を**判別できる形**で返し、
  > **未解決を黙って候補から外さない**。
  > `fromCallable` はメソッド名なので別契約 (メンバ名としての完全一致) で検出する。
  > 正例・負例に `\call_user_func(...)` と `use function ... as ...` を必ず置く。

> **I3 の保証範囲 (誇張しない)**: I3 が保証するのは、**識別子・完全修飾名・別名取り込みを解いて
> 解決できる静的なチェーンと、可変メンバ構文、および上記 callable 実行語彙まで**である。
> `ReflectionMethod` / `ReflectionFunction` 経由の反射呼び出し (既存テストが
> **`tests/` 全数で 41 件 / 25 ファイル**、うち `tests/Architecture/` で正当に使っており、
> 目録にすると本 gate と無関係な 41 件を握ることになる) と、
> それ以外の未知の間接実行経路からは同じ操作を書ける。
> **この構文について検出力を主張しない** (共通規約 (b) の「検出力の主張をその構文を除く形へ
> 明示的に狭める」側)。正典 v1 が塞ぐと定めたのは「preset へ一括 ignoring を渡す」という
> **人が普通に書く形**の穴であり、反射で arch の内部 API を叩く形は正典の想定外である。

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
| `tests/Support/Architecture/GlobalFunctionCallScanner.php` | S2 用。**対象名と綴りがトークン完全一致する素の関数呼び出しだけを狭く数える**純関数 |
| `tests/Support/Architecture/ArchSurfaceScanner.php` | S4 用。識別子出現の列挙 / 文末までのトークン列切り出し / 動的メンバ名の列挙を返す純関数。**広く数える** |
| `tests/Support/Architecture/VendorArchPresetReader.php` | S5 用。vendor preset ソースから禁止語彙集合を抽出。fail-closed |
| `tests/Architecture/ArchBaselineTest.php` | gate。`foreach` 1 本からの `arch()` 表明 7 本 + 自己検査 5 部 |
| `tests/Unit/Architecture/ArchBaselineScannerTest.php` | 3 走査器の**負例と正例** |
| `docs/template-divergence.md` (追記) | 逸脱の登録 1 件 = **D40**。併せて冒頭の宣言行「登録エントリ: 36 件」→「37 件」 |
| `tests/Support/TemplateDivergence/LedgerPins.php` (1 定数) | `DIVERGENCE_ENTRY_COUNT` **36 → 37** |

> **採番の再確認 (main `2dc4e2ec` 実測)**: 登録済みの最大番号は **D39** で、実エントリは **36 件**
> (`## D<n>` 見出しは 37 個あるが、うち 1 個は書式節の見本 `## D1 <逸脱の要約>` である)。
> **番号は再利用しない**決まり (欠番は D9 / D29 / D36 で正常) なので新番号は **D40** になる。
> `LedgerPins::FINGERPRINT_POPULATION_COUNT` は **281 のまま変えない**
> (新設 6 パスは指紋台帳 `docs/template-fingerprints.json` の 281 キーに**不在**であることを実測確認済み)。
> `ADOPTION_DEBT_COUNT` (171) も変えない (新設パスは債務一覧に無い)。
> **これらの pin は実装着手時にもう一度 main で読み直す** — 他 TODO のマージで動きうる値である。

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

**保証範囲の異なる 2 つを混ぜない**。(a) は「禁止語彙の網の限界」、(b) は
「ベースライン構造を守る仕組みの迂回路」であり、読み手にとって意味がまったく違う。

**(a) Pest Arch の禁止語彙検出が保証しないもの**

- 効くのは **Pest Arch が静的に解決できるシンボル使用**だけである。
  可変関数 (`$f = 'sha1'; $f()`)、`call_user_func('sha1')` のような文字列経由の呼び出し、
  外部プロセス、eval 内の綴りには**無言で効かない**。
- 走査域は **`App\` / `Database\Factories\` / `Database\Seeders\` の 3 根**だけである。
  `Tests\` は Pest arch 自身が除外するので**テスト側の禁止関数は 1 件も見ない**。
  `.blade.php` / `resources/js/` も対象外。
- **既存の token gate (`ForbiddenStatementTokenInvariantTest`) / SSRF 検査 / LLM 防御の代替ではない**。
  対象語彙も走査域も方式も別で、どちらか一方があれば他方が要らないという関係にはならない。

**(b) ベースライン構造を守る S4 が保証しないもの**

- `ReflectionMethod` / `ReflectionFunction` 経由の反射呼び出しからは、
  識別子として `ignoring` を書かずに同じ操作ができる。
  既存テストが `tests/` 全数で 41 件 / 25 ファイル正当に使っているため目録にはしない。
  **この構文について検出力を主張しない**。
- **静的プロパティ参照 (`self::$x`) は動的メンバとして数えない**。
  メンバ名が確定しているので迂回口ではないが、`::` の直後の変数を一律に動的と見なす
  実装にすると 45 件の無関係な登録を要求することになるため、
  **`(` を伴わない `::$var` は意図的に対象外**にしてある (詳細は「塞ぐ (1)」)。
- それ以外の未知の間接実行経路も同様である。
  塞いであるのは「識別子として書かれた静的なチェーン」「可変メンバ構文」
  「callable 実行語彙 5 種」の 3 つまでである。
- 動的メンバ名の目録は「**受容した未解決箇所**」であって安全の証明ではなく、
  **同一ファイル内での置換は検出しない**。
- 母集団は `tests/` 配下の git 追跡 PHP に限る。`tests/js/` と `.blade.php` は見ない。

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
  **意味を誇張しない** — この目録は「**人手で用途を確認して受容した未解決箇所**」であって
  安全であることの証明ではない。**同一ファイル内での置換は検出しない**
  (件数が変わらないため)。目録は **0 件を許容する** (全件が正当に除去された状態は望ましい状態であり、
  それを赤にすると不要な動的構文を残す圧力になる)。

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
| S4 サーフェスの pin | **`tests/` 配下の git 追跡 PHP 全数**を母集団に、(1) `preset` の識別子出現 0 件 / (2) `arch` `ignoring` `toBeUsed` の識別子出現が各ちょうど 1 件 / (3) `arch` の出現から文末までの**トークン列が期待形と完全一致** / (4) 動的メンバ名が**目録とファイル別件数まで exact-fit** / (5) callable 実行語彙 5 種の識別子出現 0 件 | 例外の置き場が二重化した / preset 一括使用が復活した / 生のクラス名を直書きした / 動的ディスパッチで綴りを回避した |
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
  (部分文字列一致・正規表現の語境界に頼らない)。
  **コメント (`T_COMMENT` / `T_DOC_COMMENT`) と文字列リテラルの中身は識別子ではないので数えない**。
  これは形式的な注記ではなく**現に効いている分岐**である — 実測で `preset` は
  `ForbiddenStatementTokenInvariantTest` の docblock に 1 件、callable 語彙は
  `CacheGuardWiringGateTest` / `JobDeferralTerminationGateTest` の docblock に 2 件現れており、
  素の文字列検索で数えると S4 の「0 件」は初日から赤くなる。
  逆に**この除外を「解決できない形の黙殺」と取り違えない** — 語彙を説明する散文は
  実行経路ではないので、共通規約 (b) の未解決とは別物である
- `statementTokens()`: 指定位置から文末 `;` までの**綴り列**を返す (チェーンの完全一致照合用)
- `dynamicMemberSites()`: **メンバ名の綴りが静的に決まらない位置**を返す (定義は
  「塞ぐ (1)」の枠内に確定させた 5 形)。`->` / `?->` 側は
  **メソッド呼び出しとプロパティ参照を区別しない** (区別には波括弧の対応付けが要るところを、
  区別せず広く数える = 拾いすぎる方向 = 安全)。
  `::` 側だけは `(` の有無で**可変静的メソッド呼び出し (`A::$m()`) と
  静的プロパティ参照 (`self::$x`) を分ける** — 後者はメンバ名が確定しているので動的ではなく、
  混ぜると目録が 45 件の無関係な行で膨らむ。
  この分岐は**判定を狭める唯一の場所**なので、負例に `A::$m()` (拾う) と
  `self::$x` (拾わない) の**両方**を置いて固定する

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
- 負例 (動的ディスパッチ): `->{$method}([Foo::class])` / `::{$m}()` / `->$m()` /
  `A::$m()` (可変静的メソッド呼び出し) を `dynamicMemberSites()` が拾い、
  目録に無いので S4 が落ちる (Round 3 Critical の裏取り)
- 正例 (動的ディスパッチの取り違え防止): `self::$x` / `A::$prop` のような
  **静的プロパティ参照を動的扱いしない**。`tests/` 実測 45 件がこの形で、
  拾うと目録が無関係な行で膨らむ (Round 6 前の自己訂正の裏取り。
  `A::$m()` と `A::$m` を隣り合わせに置いて `(` の有無だけで分かれることを固定する)
- 負例 (fail-closed): 読めないファイル / 期待する配列が見つからない preset ソースで例外になる

### 母集団が空でないことの検査 (共通規約 (b) の 3 番目)

- `ArchBaseline::RULES` が空でない / 各規則の `symbols` が空でない
- vendor preset から抽出した語彙集合が 3 つとも空でない
- S4 の走査根 (`tests/` 配下の追跡 PHP の一覧) が空でない (床値 + 代表パスを pin)
- **動的メンバ名の目録には非空を要求しない** (0 件は望ましい状態。
  走査器の検出力は**合成負例**で固定し、実コードの件数に依存させない)
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
  「登録するか迷ったら登録する」に従い `docs/template-divergence.md` へ **D40** を 1 件登録し、
  冒頭の宣言行 (36 件 → 37 件) と `LedgerPins::DIVERGENCE_ENTRY_COUNT` (36 → 37) を
  **同じ変更で**揃える (形式検査が宣言行・見出しの実数・定数の 3 点一致を強制する)。
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

## 本ラウンドで確認してほしいこと (再掲)

- [Warning] 3 / 7 への対応で、走査契約として**概念設計で確定させるべき範囲**は埋まったか。
  実装方法 (トークン列の具体的な扱い・array shape) は詳細設計へ送る前提でよいか。
- 自己訂正 1 で `(` を伴わない `::$var` を対象外にした判断は妥当か。
  これは S4 で**唯一判定を狭める場所**である。共通規約 (b) は
  「保証範囲の外にした構文で保護対象の操作を書ける場合は、検出力の主張を狭めるか未解決として失敗させる」
  と定めるが、`self::$violations` は**メンバ名が綴りとして確定している**ので
  「保護対象の操作を書ける構文」には当たらない、というのが本設計の立論である。
  この立論に穴があるなら指摘してほしい。
- 他に概念設計の段階で確定させておくべき契約が残っているか。
  残っていないなら **APPROVED** を、残っているなら具体的な文言の修正案を添えて指摘してほしい。
