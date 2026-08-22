## 総評

Round 4の停止条件2点は十分に解消されています。

正規入口 `/app` は文脈付きのexact-fit許可へ分離され、自己検査語彙もrule IDと専用母集団により再帰せず閉じられています。施策2も同じ方式へ統一され、変更順序・transaction境界・施策7の最終docblockも整合しました。

## 各施策の判定

- 施策1: **APPROVE**
- 施策2: **APPROVE**
- 施策3: **APPROVE**
- 施策4: **APPROVE**
- 施策5: **APPROVE**
- 施策6: **APPROVE**
- 施策7: **APPROVE**
- 施策8: **APPROVE**
- 施策9: **APPROVE**
- 施策10: **APPROVE**
- 施策11: **APPROVE**

## 非ブロッキングの補足

- [Suggestion] 施策10の「母集団と4分類」表には、現状3行しかありません。後段で定義した「自己検査専用（名指し＋件数）」を第4行として表にも追記してください。

- [Suggestion] `capture-sw.js` の説明に「該当がなければ登録しない」と「件数0で明示登録」が併記されています。0件登録は目録を膨らませるだけなので、該当がなければ登録しない方へ統一するのが明快です。

- [Suggestion] `route('capture.entry')` はURL文字列ではなくroute名なので、抽出器が検出結果を発行しないなら許可目録へ載せる必要はありません。許可目録は実際に検出された正規入口だけにexact-fitさせてください。

- [Suggestion] `/app` の許可rule IDは、単なる `legacy-path` ではなく、`manifest-start-url`、`capture-entry-route-definition` のように構文文脈まで識別する安定IDにしてください。同じファイル内で別の裸の `/app` と置き換わっても件数だけで通らない形になります。

これらは実装時に目録の実数・rule IDを確定する際の明確化であり、設計の境界や採否を変えるものではありません。

## 全体判定

**APPROVED**

I1〜I18への追従範囲、原子的変更単位、DB・型・認可・テナント境界、PHPStan、Pest、DTO/Inertia、PWA、機械検査の保証範囲まで、詳細設計として実装へ進める水準に達しています。