# 概念設計レビュー Round 2 — Warning 4 件への対応報告

Round 1 の指摘への対応を報告する。全 Warning に対応した。再判定を求める。

## 対応マトリクス

1. **[Warning] 宣言位置判定の明文化** → 対応した。宣言候補とみなす直前有意トークン
   (空白・コメント・docblock を除く) の集合を `null` (ファイル先頭) / `T_OPEN_TAG` /
   `;` / `}` の 4 形に**閉じて**宣言し、走査器 docblock へ明記する契約にした。
   正例カバレッジ: `;` の後の宣言 (declare 文の後の `namespace Foo;`) と `}` の後の宣言
   (名前つきブロック後のグローバルブロック) は既存検体 `clean-named-namespace` /
   `clean-bracketed-named` / `detects-bracketed-after-named` / `detects-bracketed-global` が
   既に固定している。`T_OPEN_TAG` 直後の宣言形が既存検体に無ければ追加検体のどちらかを
   開始タグ直後の宣言形にして 4 形すべてを検体で通す (実ファイル確認は詳細設計で行う)。
   既存の正しい宣言 (`namespace Foo;` / `namespace Foo { ... }` / 複数ブロック) の退行が
   無いことは既存検体 12 本の照合がそのまま緑であることで固定する。

2. **[Warning] メソッド名等の識別子文脈の裏取り不足** → 対応した。
   `clean-namespace-identifier` 検体へ**メソッド名 `namespace` の宣言と `$this->namespace()`
   呼び出し**を追加する (`namespace` は半予約語でメソッド名として合法。検体は php -l の
   構文妥当性検査で固定される)。検出力の主張は裏取りした識別子文脈
   (定数名 / 自クラスの定数参照 / メソッド名の宣言と呼び出し) に限定し、それ以外
   (enum case 名・trait 別名・名前つき引数など) は「同じ位置判定で読み飛ばされるが個別には
   裏取りしていない」と docblock で限定して書く (誇張しない)。

3. **[Warning] `}` 直後は宣言位置の確定にならない** → 対応した。概念設計と docblock 契約に
   「このガードは宣言であることの確定ではなく、識別子として無視しない**候補抽出**である」と
   明記した。宣言候補で形を成さないもの (`namespace` の後が名前/`;`/`{` 以外) は従来どおり
   unresolved (fail-closed) を維持する。構文不正な namespace 配置の検体は構文妥当性検査
   (php -l 終了コード) が弾き、追跡下の実ファイルは実行可能な PHP である前提
   (壊れていればアプリもテストも起動しない) に立つ。

4. **[Warning] 採用時債務→逸脱登録の具体化** → 対応した。D 登録には対象パス
   (`tests/Architecture/NoNonCompoundGlobalUseTest.php`)・テンプレートとの差異
   (走査器の置き場 `tests/Support/GlobalUse/` / 走査母集団が追跡下 PHP 全数の単一出典
   `TrackedPhpSourceFiles` / 自己検査を gate 本体へ同居させる構成)・既存構造を維持する理由
   (テンプレートの 6 root ファイルシステム走査へ戻すと走査域が縮む退行 + 単一出典の規約)・
   再判定の条件 (テンプレートが同等の母集団定義・t2 相当を採ったとき) を 9 行メタ表 + 本文で
   書き、登録・adoption-debt の 1 行削除・LedgerPins の 2 定数更新を同一変更で行う。
   登録全文は詳細設計で起草する。

5. **[Suggestion] 各件** → 方針として受け入れた。`previousSignificant()` は nullable を返し
   呼び出し側で明示分岐する (詳細設計に反映)。oracle の配線検査は Process の env を観測できる
   既存 API (`Symfony\Component\Process\Process::getEnv()`) を使い、実行結果の偶然に依存しない。

## 修正後の概念設計の変更点 (差分要約)

- 「改善アイデア 2」: 宣言候補トークン集合の閉じた列挙 / 候補抽出であることの明示 /
  検体へのメソッド名形の追加 / 識別子文脈の主張の限定 / 宣言候補 4 形の正例カバレッジ方針を追記。
- 「実装方針」: D 登録の記載内容 (対象パス・差異・理由・再判定条件) と同一変更での
  登録+債務削除+pin 更新を明記。

全体判定を APPROVED にできるか、残る Critical/Warning があれば指摘されたい。
