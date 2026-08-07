## Round 5 指摘の解消判定
### S4 [Warning] 「既存の Cache-Control directive を落とさない」テストが実装構造と一致していない → 解消
`ErrorScreenCachePolicy::apply(Response $response)` にキャッシュポリシーを独立させ、Unit テストで `must-revalidate` を持つ **適用対象そのものの応答** に直接適用する設計になっています。

これにより、Round 5 の問題だった「原応答に積んだ directive が、Inertia が新規生成した `$rendered` に移植されない」という混同は解消されています。Feature 側から当該テストを外し、削除理由も明記されているため、テストが検出する契約と実装構造が一致しています。

## 新規 [Critical] (対応が持ち込んだもの。無ければ「なし」)
なし

## 全体判定
APPROVED