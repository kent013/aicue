**全体判定: APPROVED**

使命に対する寄与は明確で、原因仮説も現行仕様と整合しています。特に「`plan_code !== null` は有償契約を意味する」というドメイン規約に立ち返り、`BillingAccess` をいじらず seed 側の不整合を直す方針は妥当です。大きな設計破綻はありません。

**1. 使命との整合性**
- [Suggestion] Free 組織が課金ゲートで中核導線から脱落する不具合の修正なので、North Star への寄与は本質的です。`/projects` だけでなく、実際に「撮影・作成フロー再開」が確認できる代表導線も回帰対象に含めると、使命との接続がさらに明確になります。  
修正提案: `/projects` に加えて、実際に `RequireActiveSubscription` 配下にある主要画面を 1 本追加で確認する。

**2. 禁止事項違反**
- [Suggestion] 提案内容の範囲では禁止事項への抵触は見当たりません。`BillingAccess` を安易に緩めず、seed/test の整合で直すのは「仕組みが機能していない段階で値を弄るな」に沿っています。

**3. 実現可能性**
- [Warning] 「fake active Cashier subscription を投入する」の粒度がまだ粗く、Cashier 側の最小整合条件を外すと seed 自体が別の壊れ方をする可能性があります。  
修正提案: 設計書に「`organization->subscription('default')` が `active/trialing` を返すために必要な最小カラムを満たす seed を行う」と明記し、可能なら既存の paid 契約 helper と同じ生成経路へ寄せる。
- [Suggestion] `currentPrice(PlanPriceKind::Base) !== null` を有償判定に使う方針は、コード名分岐を避けるという規約に合致しており実装可能です。

**4. 期待効果の妥当性**
- [Warning] 記載された期待効果は合理的ですが、回帰テスト概要が Free 側中心で、有償側の seed 整合まで十分に固定できていません。今回の設計は「Free は `plan_code=null`」「有償は `plan_code=code` かつ subscription あり」の両側を不変条件として扱うべきです。  
修正提案: `ManualTestSeederTest` で Free と有償の両方について、`plan_code` と subscription 行の組を明示的に検証する。

**5. リスク**
- [Warning] 有償/Free 判定を `currentPrice(Base)` に寄せる以上、「current base price が欠落した paid plan」は seed 上 Free 扱いになります。ドメイン規約上それでよいなら問題ありませんが、seed データ破損の早期検知という観点では弱いです。  
修正提案: seed 対象の paid plan については「base Price が無いなら seed を失敗させる」か、少なくともテストで `standard` 側の前提を固定する。
- [Suggestion] `BillingAccess` 非変更は正しい判断です。ここに free 例外を足すと、本番 webhook 経路と seed/test 経路で契約概念が二重化します。

**6. スコープの適切さ**
- [Suggestion] スコープは適切です。seed と回帰テストに閉じ、`BillingAccess` や本番 Stripe 経路へ波及させないのは過不足がありません。
- [Suggestion] 追加でやるなら「契約状態を作る test helper との整合を明文化する」程度に留めるべきで、ミドルウェア改修まで広げる必要はありません。

**7. 型安全性**
- [Suggestion] API 変更ではないため DTO/JsonResource 観点の懸念はありません。`PlanPriceKind::Base` を使った判定、`Plan`/`Organization`/subscription の関係を明示した実装であれば PHPStan level 10 とも整合しやすいです。
- [Suggestion] 設計書に「`createOrganization()` は `Plan` の値からのみ状態を導出し、文字列 code 比較は行わない」と書いておくと、レビュー観点としても型・規約の両面で明快になります。

総じて、方針は正しいです。修正前に詰めるべき点は「有償 subscription の seed をどの整合条件で作るか」と「テストで Free/有償の両側不変条件を固定するか」の 2 点です。