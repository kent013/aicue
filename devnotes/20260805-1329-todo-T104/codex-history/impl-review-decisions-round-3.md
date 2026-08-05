# 対応マトリクス: impl-review Round 3

## [Critical] 受容不能性の証明が照合経路まで届いていない

- 判断: **対応する (指摘が正しく、私の Round 2 の主張が誤りだった)**
- 根拠: 指摘どおり実測で **exitCode=0 (偽グリーン)** を再現した。

  ```
  advisory matchKey : composer|vendor/pkg|fallback:<missing-key>
  accepted matchKey : composer|vendor/pkg|fallback:<missing-key>
  KEYS EQUAL       : true
  evaluate exitCode: 0  failures: []
  ```

  原因は `matchKey` の非対称性:
  - advisory 側: `id` が空 → fallback 経路 → `<eco>|<pkg>|fallback:<missing-key>`
  - accepted 側: `id` が非空 (`"fallback:<missing-key>"` という**文字列**) → その id をそのまま使う
    → **同じキーを合成できてしまう**

  `AcceptedAdvisorySchema.id = z.string().min(1)` は「空文字」しか弾かないため、
  fallback キーそのものを id として書けば schema を通過する。
  Round 2 で私が「構造的に不可能」と述べたのは **schema 検査しか見ていない誤り**だった。
- 対応内容: `evaluate()` の step 4 で、**id 欠損 advisory を照合前に無条件 fail** させる。
  upstream ID を持たない advisory は同定不能であり、同定できないものを
  「リスク評価済み」と宣言することは原理的にできない、という根拠を実装コメントに明記。
  正しい対処は normalizer 側での upstream ID 補完であることも併記した。
  回帰テストを 1 本追加し、
  (a) 照合キーが一致してしまうこと (= schema だけでは防げない) を明示し、
  (b) それでも `exitCode=1` かつ `unidentifiable high advisory` が出ることを固定した。

## [Suggestion] known-issue の記録と TODO 化の扱い

- 判断: **維持 (異論なしとの評価を受領)**
- 対応内容: 変更なし。最終報告で TODO 化を推奨する。
