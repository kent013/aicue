全体判定: **CHANGES_REQUESTED**

Round 3 の Critical は解消されています。C1を非公開基盤、C2を公開と実処理の有効化に分ける構成は整合しています。ただし、C2の「同じマージ」は、既存の期限超過データが消えた状態まで保証しません。公開時点の運用順序をもう一段定義する必要があります。

## 1. 使命との整合性

[Suggestion] 猶予予約、取消、凍結、メール通知まで含むPR-Bは使命に整合しています。既定導線で誤操作を回復可能にしつつ、即時削除の存在を誇張せず説明できています。

永久凍結についても、blockerを導出値として再評価し、解消経路と取消経路を通す設計なら概念上の詰みはありません。

## 2. 禁止事項違反

禁止事項への直接的な違反は見当たりません。

C1の未使用基盤は旧実装との互換並走ではなく、C2に向けた依存順の実装なので、思考原則3への違反とも判断しません。

## 3. 実現可能性

[Warning] 凍結route集合とallowlistの「exact-fit」の説明が集合論的に矛盾しています。

凍結middlewareが付く全routeを `U`、通過を許すrouteを `A` とすると、`A` は `U` の部分集合です。`Route::getRoutes()` から抽出した `U` と `A` を完全一致させると、すべてのrouteが通過対象になり凍結が成立しません。

修正提案: gateの契約を次のように分けてください。

- `A ⊆ U` を検査する
- enumのroute名が実在し、凍結middlewareを持つことを検査する
- middlewareが実際にbypassする集合とenum `A` がexact-fitであることを検査する
- `U - A` のrouteは予約中に遮断されることをbehavioralに検査する
- 無名routeが `U` に存在する場合はfailさせるか、型付きexemptionを要求する

「未登録routeは失敗」ではなく、「未登録routeは既定で遮断」がdeny-by-defaultの正しい契約です。

## 4. 期待効果の妥当性

[Critical] C2で文面とpurge機能を同じマージに入れても、既存の期限超過行が公開直後から0件になるとは限りません。

デプロイ後、日次schedulerが最初に動くまでの間や、初回purgeが失敗・タイムアウトした場合、規約は「最長7年」と表示される一方、期限超過データが残ります。「コードが存在する」と「保持期限が実際に満たされている」は別です。

修正提案: C2に有効化runbookとpreflightを含め、公開条件を次の順序で固定してください。

1. C1のdry-runでtarget別件数と想定外失敗を確認する
2. ledger畳み込みを含むC2実装を、利用者へ公開しない状態で適用する
3. `--apply`を実行する
4. apply後のhorizon検査で期限超過件数0を確認する
5. その後にprivacy文面を利用者へ公開する
6. 日次schedulerを継続運用へ移す

デプロイ基盤が存在しない前提なので、新しい自動デプロイ機構は不要です。ただし、初回有効化の人手手順、失敗時にprivacy文面を公開しない条件、再実行方法はrunbookに必要です。既存データに期限超過行が存在しないことを事前確認できる場合も、そのdry-run結果が公開前提になります。

feature flagを使わないなら、maintenance中の初回apply、またはprivacy文面を後続PRへ分ける方法が現実的です。

## 5. リスク

[Warning] `Subscription` / `SubscriptionItem` の処理方式が「詳細設計で確定」のままC1の全target purgerに含まれています。

物理削除、匿名化、状態スナップショットのどれを採るかで、参照整合性と規約上の削除完了条件が変わります。安全境界だけでは `BillingRetentionHorizonTest` のpostconditionを定義できません。

修正提案: 概念設計では少なくとも次を確定してください。

- 期限超過した親子行を物理削除するのか
- 参照中の行をfail-closedで残した場合、horizon postconditionの「0件」からどう分類して除外するか
- fail-closedが長期継続した場合の運用上の解消手順
- `SubscriptionItem` 自身の起算点は親の `ends_at` であること

[Warning] ledger reader目録の「`git grep`による機械抽出」は、動的relation、scope、table名を介したquery、DB facadeを取りこぼす可能性があります。

修正提案: 保証範囲を明記し、少なくともモデル参照、table参照、relation名、主要列名の複数入口を走査してください。正負fixtureと空振り検知を持たせ、目録だけでなく既知の6種類の挙動テストを最終保証にします。

## 6. スコープの適切さ

[Suggestion] A → B → C1 → C2の4分割は妥当です。A、B、C1はそれぞれmainに一貫した状態を残します。

C2については、コード分割ではなく初回有効化手順が不足しています。上記のpreflightと公開順序をC2の完了条件に加えれば、追加PRは必須ではありません。

[Suggestion] §8で `inquiries / アクセスログ` が重複しています。内容上の問題はありませんが、片方を削除してください。

## 7. 型安全性

[Suggestion] `BillingRetentionPurger`、enum、固定5項目の `BillingRetentionPurgeResultDto` という構造はPHPStan level 10に適合します。

fail-closedを正常結果として表現するなら、DTOの件数だけでなくCommand側が「業務上の保留」と「想定外失敗」を取り違えないよう、判定メソッドをDTOに持たせる設計が安全です。例えば `hasUnexpectedFailures(): bool` のように終了コード判定を一箇所へ閉じます。

結論として、残る承認阻害点は、**C2のコードマージと実データの保持期限準拠を同一視していること**です。初回purgeの完了確認をprivacy公開の前提にすれば、概念設計として承認可能です。