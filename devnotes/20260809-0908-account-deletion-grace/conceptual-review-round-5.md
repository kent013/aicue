全体判定: **CHANGES_REQUESTED**

Round 4 の Critical に対する C2/C3 分離は妥当です。ただし、C3 公開条件が `failClosed` を除外しているため、「最長7年」と実データの不整合がまだ残り得ます。

## 1. 使命との整合性

[Suggestion] PR-BはNorth Starに整合しています。予約中の解消経路と取消経路がbehavioral testで固定されるため、「予約維持」による永久凍結も設計上は回避できています。

## 2. 禁止事項違反

禁止事項への直接的な違反は見当たりません。

テスト、Architecture gate、DTO、PHPStan level 10の条件も設計へ組み込まれています。

## 3. 実現可能性

[Warning] C2で日次scheduleを登録する一方、runbookの手順6で「日次schedulerを継続運用へ移す」としており、制御点が不明です。

C2を通常デプロイすると、登録済みscheduleは初回手動applyやhorizon確認より先に実行され得ます。データ削除自体は目的に沿いますが、runbookに書かれた有効化順序とは一致しません。

修正提案: 次のいずれかに統一してください。

- C2デプロイ時点からscheduleは有効とし、手順6を「継続監視へ移す」に変更する
- schedule登録をC3へ移し、C2では手動applyだけを可能にする

前者の方が追加機構を必要とせず単純です。

## 4. 期待効果の妥当性

[Critical] C3の公開条件が「期限超過0件。ただし `failClosed` を除く」では、規約の「最長7年」を満たしません。

`failClosed` は安全のため削除できなかった期限超過データであり、保持期限上は依然として超過しています。分類してreportしても、データが残っている事実は変わりません。Round 2で問題になった「除外目録は可視化するが解決しない」と同じ構造です。

修正提案:

- **C3の公開条件を、`failClosed` を含む期限超過件数が0件であることに変更する**
- `failClosed > 0` ならC3を出さず、参照元を解消して再実行する
- 初回有効化用のapplyは、`failClosed > 0` でも非0終了にする
- 日次運用でも `failClosed` の継続・増加を正常成功として扱わず、監視対象として明確に通知する

安全上データを残す判断と、規約を公開できる判断は分離してください。削除不能なら残すのが正しいですが、その状態ではC3へ進めません。

## 5. リスク

[Warning] `Subscription` / `SubscriptionItem` の表では方式が「詳細設計で確定」のままですが、直後の説明では「物理削除」と確定しています。

修正提案: 表の方式を「物理削除」に修正し、起算点も次のように明示してください。

- `Subscription`: 自身の `ends_at`
- `SubscriptionItem`: 親Subscriptionの `ends_at`

[Suggestion] ledgerの繰越設計は、reader目録、6種類の挙動比較、個別取引情報の非復元性まで含めており、概念設計として十分です。詳細設計では、0合計の繰越行を作らないことと、再畳み込み後も `carried_forward_through` が単調に進むことを固定するとよいです。

## 6. スコープの適切さ

[Warning] 台帳への `implemented (v1)` 報告が「C2マージ後」となっていますが、v1の構成要素である規約文面はC3まで入りません。

修正提案: 台帳報告を次の全条件成立後へ変更してください。

- C2デプロイ済み
- 初回apply完走
- `failClosed` を含む期限超過件数が0
- C3マージ・デプロイ済み
- 三者一致gateがgreen

つまり報告時点は **C3完了後** です。

A → B → C1 → C2 → C3の分割と依存順自体は妥当です。各PRも、上記の公開条件を直せばmainへ一貫した状態を残せます。

## 7. 型安全性

[Suggestion] enum、target別purger、固定フィールドDTO、`hasUnexpectedFailures()`という構成はPHPStan level 10に適合します。

ただし `failClosed` と想定外失敗は性質が異なるため、DTOには次の判定を分離して持たせるのが安全です。

- `hasFailClosedRecords(): bool`
- `hasUnexpectedFailures(): bool`
- `isPublicationReady(): bool`

これにより、Commandの終了コードとC3公開判定で件数の解釈が分岐することを防げます。

結論として、設計はほぼ承認可能です。残る阻害点は、**期限超過した `failClosed` 行を残したままC3を公開できる条件**です。C3の前提を「分類を問わず期限超過0件」に変更し、台帳報告をC3完了後へ移せば承認できます。