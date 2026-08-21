立場は **(a)** です。

監督裁定が `PasswordCredentialService::afterPersist()` と `SocialAccountService::linkToUser()` を明示的に T110 のスコープ外とした以上、その既存パターンの規約 11 適合まで今回の承認条件にするのは適切ではありません。collector に関する Critical は解消済みであり、今回の割当範囲に残るブロッカーはありません。

ただし、この2経路を「規約11準拠」と評価するわけではありません。残存する不整合は、影響する経路を明記した別 TODO として、人間の設計判断のもとで transactional outbox、呼び出し構造の再設計、または正式な適用除外を検討すべきです。

したがって、質問2は該当しません。今回その2サービスの修正や追加裁定を要求せず、T110 の diff を承認します。

**APPROVED**