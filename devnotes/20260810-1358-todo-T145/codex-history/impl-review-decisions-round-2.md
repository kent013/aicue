# 対応マトリクス: impl-review Round 2

Codex 判定 **CHANGES_REQUESTED** (Critical 1 / Warning 1)。両方とも指摘が正しく、
いずれも Architecture テスト内で完結するため対応した。

## [Critical] 自己参照コントロールが SoT と逆 (hit 0 件であるべきところを「必ず点灯」で固定していた)

- 判断: **対応する** (指摘のとおり。こちらの実装が共通 SoT の要件と逆だった)
- 根拠: 共通要件は「gate ファイル自身を走査して hit 0 件 (説明コメントで偽赤にならない)」である。
  「必ず点灯する」を固定すると、
  (a) SoT の求める性質を検査していない
  (b) fixture 以外の説明文でも点灯するので、対象 fixture を壊しても緑のままになりうる (循環)
  (c) そもそも gate が自分の記述で偽赤になりうる状態を**固定して**しまう
  という 3 重に悪い形だった。
- 対応内容:
  1. gate ファイルの**生ソースから年数の表記を一掃**した。
     - 検査 8 の fixture は連結で組み立てる (`$y = (string) 7; $era = '年';` → `'最長 '.$y.' '.$era.'間'`)。
     - docblock / コメント / 失敗メッセージからも生の表記を除去
       (例: 検査 2 のメッセージは `.BILLING_RETENTION_OWNER_DECIDED_YEARS.` で組み立てる)。
  2. 検査 7 の自己参照コントロールを **`->toBe([])`** に反転した。
  3. 「検出器が生きていること」の担保は**検査 8 の負のコントロール**へ役割分担した
     (自己参照コントロールと正の自己検証を同じ assert で兼ねない)。
- **mutation 実測**: gate ファイルへ `// 説明コメント: 取引関係書類等は最長 7 年間保有する。` を
  1 行足すと **検査 7 が赤**。循環していないことを確認済み (変異は戻した)。

## [Warning] `billingRetentionAliasNames()` が class import の文脈を見ていない

- 判断: **対応する**
- 根拠: `use function ... as X` はシンボル表が別であり、別 namespace の同名クラスの alias を
  SSOT の alias として登録するのは端的に誤検出である。exact-fit を名乗る以上、
  alias 解決は FQCN で厳密に行うべき。
- 対応内容: `use` 文をきちんと構文解析する形へ書き直した。
  - `use function` / `use const` は head トークンで除外。
  - closure の `use (` は head が名前トークンでないことで除外。
  - **group use** (`use App\Support\Legal\{BillingRetention as X};`) は prefix を結合して FQCN を復元。
  - **FQCN が `App\Support\Legal\BillingRetention` と厳密一致**したときだけ alias を登録。
  - **trait adaptation** (`use T { Foo as X; }`) は bare name の直後が `,`/`;` でないため entry 解析を打ち切る
    (brace 深度の追跡は不要になった)。
  - 負のコントロールを **検査 9** として独立させ、Codex が挙げた 5 形をすべて固定した
    (class import 1 件 / 完全修飾 1 件 / group 1 件 / `use function`・`use const` 0 件 /
    別 namespace 0 件 / trait adaptation 0 件 / closure 0 件 / alias 無し 0 件)。
    さらに「その alias が呼び出しとして数えられないこと」まで `ssotCall === 0` で見る。

## 併せて明示した「保証しないもの」

呼び出し検出は最終セグメント一致も併用するため、**別 namespace の同名クラスの呼び出しを
呼び出し元として数える**(過検出)。これは deny-by-default の目録として意図的に過検出側へ
倒した判断であり、docblock に明記した (alias 解決側は逆に FQCN 厳密一致)。
