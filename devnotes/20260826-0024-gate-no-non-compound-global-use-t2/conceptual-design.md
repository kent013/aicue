# 概念設計: gate-no-non-compound-global-use-t2

## 背景・課題

家系の機能台帳 lctl の feature `gate-no-non-compound-global-use` (グローバル名前空間での
非複合名 import を禁止する Architecture gate) の正典が t1 → t2 へ上がった
(canonical_promoted 2026-08-25。取得時の feature_revision: 26-48c9ef10b833)。
aicue は t1 の必須 4 点を aicue:T180 で充足済みであり (台帳の aicue セルが実読で確認)、
実装そのものは変わっていないが、正典の版上げにより update_pending (t1 → t2) になっている。

t2 の追加要素は 2 点で、いずれも参照実装 motivation@785ec04b の追従作業で見つかった
「正典の原本の弱点」を正典へ昇格させたものである:

1. **真値を取る子プロセスの言語環境を C に固定する**。検出器の自己検査は `php -l` の
   警告文 (英語の診断文) から名前と行を抽出するが、原本は子プロセスの言語環境を固定して
   おらず、英語以外の言語環境の開発機では真値の抽出が静かに壊れうる (自己検査が空振りする
   方向 = 「緑だが何も見ていない」)。aicue の該当部品は `tests/Support/GlobalUse/PhpLintOracle.php`。
2. **識別子位置の namespace 字句を宣言と誤読しないガード**。`namespace` は PHP の半予約語で、
   クラス定数名 (`const NAMESPACE`)・自クラス参照 (`self::NAMESPACE`)・メソッド名などの
   識別子文脈でも tokenizer は同じ字句 (T_NAMESPACE) を返す。走査器がこれを名前空間の宣言と
   誤読すると、(a) 宣言として読めず fail-closed の赤 (php -l は警告を出さないので偽の赤)、
   (b) 形によっては宣言として読めてしまい名前空間の文脈が壊れる (以降の検出を静かに落とす =
   検出力の縮小) の両方が起こる。motivation では定数名にこの綴りを使うクラス 5 本で (a) が
   実際に顕在化した。aicue の該当部品は `tests/Support/GlobalUse/NonCompoundGlobalUseScanner.php`。

aicue の現行走査器を実読した結果、**両方の弱点が aicue にも実在する**:

- `PhpLintOracle::inspect()` は Symfony Process で `php -l` を起動するが環境変数を固定して
  いない (親の言語環境をそのまま継承する)。
- 走査器の T_NAMESPACE 分岐は位置を見ずに `readNamespaceDeclaration()` へ入る。
  `const NAMESPACE = 'x';` は「宣言の形を読めない」→ unresolved (偽の赤)。
  さらに `return self::NAMESPACE;` のように**字句の直後が `;`** の形は「名前なしのセミコロン形
  宣言」として**読めてしまい**、`hasGlobalRegion` が false になり以降の非複合 import を
  静かに見逃す (検出力の縮小方向。母集団の縮退検査 (migrations / tests/Architecture の包含)
  はこの単一ファイルの見逃しを検出できない)。aicue の追跡下に該当の綴りは現在 0 件だが、
  1 ファイル入った時点で (a) は gate を偽の赤に、(b) は無音の見逃しにする。

## 改善アイデア

正典 t2 の 2 点を、aicue の既存構造 (走査器 2 クラスは `tests/Support/GlobalUse/`、
gate 本体 + 自己検査は `tests/Architecture/NoNonCompoundGlobalUseTest.php` の 1 ファイル、
検体は `tests/Architecture/fixtures/global-use/*.php.txt`) のまま追従する。
motivation の実装 (proc_open + 別クラス構成 + 真理値表 28 形) は丸写しせず、
機序だけを移す。

1. **言語環境の C 固定**: `PhpLintOracle` の Process 構築へ `LC_ALL=C` を明示する
   (Symfony Process は明示 env を継承環境へ上書き合成するので、他の変数の継承は保たれる)。
   Process 構築を小さな組み立てメソッドへ切り出し、gate 側に「oracle が起動する Process の
   env に LC_ALL=C が配線されている」ことを固定する検査を 1 本足す
   (言語環境の差はこの開発機では観測できないため、挙動での偽装不能な裏取りは原理的に
   できない。配線の検査 + docblock での限界の明記で受ける)。
2. **識別子位置ガード + 検体 2 形**: 「namespace の宣言は文の先頭にしか置けない」という
   構文事実を使う。**宣言候補とみなす直前有意トークンの集合を閉じて宣言する** —
   直前の有意トークン (空白・コメント・docblock を除く) が
   `null` (ファイル先頭) / `T_OPEN_TAG` / `;` / `}` の 4 形のいずれかであるときだけ
   宣言候補として `readNamespaceDeclaration()` へ入り、それ以外の位置の T_NAMESPACE は
   識別子として読み飛ばす。**このガードは「宣言であることの確定」ではなく
   「識別子として無視しない候補抽出」である** (宣言候補で形を成さないものは従来どおり
   unresolved = fail-closed。構文不正な namespace 配置は検体の構文妥当性検査
   (php -l 終了コード) と真値照合の不一致側で捕まる)。この契約は走査器の docblock に明記する。
   検体を 2 形追加して固定する:
   - `clean-namespace-identifier` (無違反側): 名前つき名前空間の中で `const string NAMESPACE` /
     `self::NAMESPACE` 参照 / **メソッド名 `namespace` の宣言と `$this->namespace()` 呼び出し**を
     使う形。旧実装では unresolved の偽の赤になる (fail-first の負例)。
   - `detects-namespace-identifier` (検出側): 名前空間宣言の無いファイルで、
     `const NAMESPACE` と `return self::NAMESPACE;` を持つクラスの**後**に非複合
     `use RuntimeException;` を置く形。旧実装では unresolved に加えて検出の取り逃しが起こる
     (検出力の縮小を負例で裏取りする形。php -l の真値との名前・行の完全一致で固定)。

   **識別子文脈の検出力の主張は検体で裏取りした形に限る** (定数名・自クラスの定数参照・
   メソッド名の宣言と呼び出し)。それ以外の識別子文脈 (enum の case 名・trait の別名・
   名前つき引数など) も同じ位置判定で読み飛ばされるが、個別の検体では固定しないため
   docblock で「同原理だが個別には裏取りしていない」と限定して書く (誇張しない)。

   **宣言候補 4 形の正例カバレッジ**: `;` の後 (declare 文の後の `namespace Foo;`) と
   `}` の後 (名前つきブロックの後のグローバルブロック) は既存検体
   (`clean-named-namespace` / `clean-bracketed-named` / `detects-bracketed-after-named` /
   `detects-bracketed-global`) が既に固定している。`T_OPEN_TAG` 直後の宣言が既存検体に
   無ければ、追加検体のどちらかを開始タグ直後の宣言形にして 4 形すべてを検体で通す
   (実ファイルの有無は詳細設計で確認する)。既存の正しい宣言の退行が無いことは
   既存検体 12 本の照合がそのまま緑であることで固定する。

検出力と fail-closed の縮小が無いこと (家系規約 = AGENTS.md 走査器共通規約 (b)(c)) は、
既存検体 12 本の照合が全部そのまま通ること + 追加検体 2 形で裏取りする。

## 期待効果

- 家系の正典 t2 への追従が完了し、aicue セルを implemented (t2) へ戻せる
  (台帳への status_reported は実装フェーズの責務)。
- 開発機の言語環境に依らず検出器の自己検査の真値が壊れない (「緑だが何も見ていない」の予防)。
- 定数名などに `NAMESPACE` の綴りが入った瞬間に起こる偽の赤 (開発停止) と無音の検出漏れ
  (CI 全滅地雷の見逃し) を先回りで塞ぐ。使命への寄与は間接だが、この gate 自体が
  「CI だけ全テスト全滅」事故の再発防止装置であり、その検出力の維持は開発の継続性そのもの。

## 実装方針（概要）

- `tests/Support/GlobalUse/PhpLintOracle.php`: Process 組み立てを `buildProcess()` へ切り出し、
  env に `['LC_ALL' => 'C']` を明示。docblock に「抽出は英語の診断文に依存するため子プロセスの
  言語環境を C に固定する」旨と限界を追記。
- `tests/Support/GlobalUse/NonCompoundGlobalUseScanner.php`: T_NAMESPACE 分岐の先頭に
  直前有意トークンの statement-start 判定を追加 (`previousSignificant()` を新設)。
  docblock に半予約語の扱いを追記。
- `tests/Architecture/fixtures/global-use/`: 検体 2 本を追加 (`.php.txt`)。
- `tests/Architecture/NoNonCompoundGlobalUseTest.php`: 検体一覧へ 2 行追加、件数 pin を
  検出 7→8 / 無違反 5→6 へ更新、oracle の env 配線検査を 1 本追加。
- テストファースト: 検体 2 本と一覧更新を先に入れて赤 (unresolved / 照合不一致) を確認して
  から走査器を直す。oracle 検査も env 未配線の状態で赤を確認する。
- **採用時債務の決着**: `tests/Architecture/NoNonCompoundGlobalUseTest.php` は
  指紋台帳のキーかつ採用時債務 (adoption-debt.tsv) に載っており、「変更したまま債務に残す」は
  選べない。aicue は走査器の置き場と走査母集団 (追跡下 PHP 全数の単一出典
  `TrackedPhpSourceFiles`) をテンプレートと意図的に違えているので、**意図的逸脱として
  `docs/template-divergence.md` へ D 登録を書き、債務から削る** (LedgerPins の
  DIVERGENCE_ENTRY_COUNT +1 / ADOPTION_DEBT_COUNT -1)。登録は件数操作だけで済ませない —
  対象パス・テンプレートとの差異 (走査器の置き場 / 走査母集団の単一出典 / 自己検査の同居構成)・
  既存構造を維持する理由 (原本形へ戻すと走査域が縮む退行)・再判定の条件 (テンプレート側が
  同等の母集団定義を採ったとき等) を 9 行メタ表 + 本文で書き、**登録・債務 1 行削除・
  pin 更新を同一変更で行う**。登録の全文は詳細設計で起草する。

## 制約・前提

- PHP 8.4 / Pest。変更はすべて `tests/` 配下 + docs (アプリ実行コードに触れない)。
- PHPStan level 10 / Pint / `declare(strict_types=1)` を満たす。
- AGENTS.md 走査器共通規約: (b) fail-closed の維持 (unresolved 経路は変えない)、
  (c) 負例による検出力の裏取り (検体 2 形 + 既存 12 本)、
  「同じ PR で揃える 4 点」(負例 fail-first / 落とす分岐 / 空振り検査 / docblock)。
- 走査母集団の単一出典 `TrackedPhpSourceFiles` は触らない。
- 検体の拡張子は既存どおり `.php.txt` (`.php` にすると他 gate の母集団に入る)。
- 台帳の規律: 本設計ノートに引いた照会は get_feature("gate-no-non-compound-global-use")
  1 件 (revision 26-48c9ef10b833) と motivation@785ec04b の get_source 2 件。
  search_features は不要 (対象 feature が特定済みのため実施せず)。

## スコープ外

- gate 本体の構成変更 (自己検査の別ファイル化・真理値表形式への移行など motivation の
  構成の模倣)。正典は能力を要求するのであって構成を要求しない。
- 走査域の拡大 (devnotes / スクリプト置き場)。正典の必須外で、aicue は既に家系最広
  (追跡下 PHP 全数)。
- 文字列補間 `${x}` の閉じ波括弧の深さ計数など、t2 と無関係な既知の限界の手当て。
- TODO 登録・実装・lctl への status_reported (実装フェーズの責務)。
