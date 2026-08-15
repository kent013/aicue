全体判定: **CHANGES_REQUESTED**

**使命との整合性**
[Warning] 共有端末で bfcache 復元後に別利用者へ前利用者の画面が見える経路を塞ぐ、という問題設定は North Star に強く整合しています。撮影 PWA の現場利用では端末持ち回りが現実的なので、本質的な改善です。

ただし、設計内で「速さ」と「漏えい防止」が少し混ざっています。主目的は漏えい防止であり、同期判定は fail-closed の早期分岐に限定する、という整理を実装仕様にも明記してください。

**禁止事項違反**
[Warning] `SessionStatusController` の拡張で DTO + JsonResource を使う方針は明記されていますが、プローブが「仕様固定 endpoint の例外」扱いにならないよう、既存と同じ DTO/Resource 経路を維持する必要があります。

修正提案: 応答 DTO を `authenticated: bool` と `sessionEpochMatches: bool` のように明示し、top-level shape を JsonResource で固定する、と設計に追記してください。

**実現可能性**
[Warning] Laravel 12 + Svelte 5 + Inertia.js で実現可能です。ただし、middleware の正確な配置が未確定です。`IssueSessionEpochCookie` はセッション開始後でなければ世代を導出できず、かつ既存の priority list / tenant boundary ordering を壊してはいけません。

修正提案: `StartSession` 後、Inertia share が評価される前後、`NoStoreCacheHeadersForAuthenticatedPages` との前後関係を明文化してください。少なくとも「短絡しない middleware」として inventory に登録する前提も書くべきです。

**期待効果の妥当性**
[Warning] 「A の bfcache 画面が B に見える」経路を塞げる、という効果は妥当です。ただし成立条件は「描画時の世代」と「復元時の現セッション世代」を比較することです。設計文の「要求が運んだ印」が cookie 由来なのか Inertia prop 由来なのか曖昧です。

修正提案: プローブへ送る比較対象は **cookie ではなく、復元された文書が保持する描画世代** だと明記してください。cookie は同期の fail-closed 判定用、プローブは描画世代 header と現セッション世代の照合用、という責務分離が必要です。

**リスク**
[Critical] readable cookie を暗号化除外する設計は、値の改ざんを前提にした防御設計でなければ危険です。本文では「cookie は開示側の分岐に現れない」とありますが、プローブ照合で cookie 値を使う実装に逸れると、client 側状態が開示根拠に混入します。

修正提案: 不変条件として「サーバ側の一致判定は request header の描画世代とサーバ導出の現世代だけを見る。Cookie ヘッダの値は一致判定に使わない」を追加してください。cookie 改ざん時は reload/秘匿維持にしか倒れないことを Feature/Vitest で固定してください。

[Warning] 「未認証でも発行し、削除しない」は意図は分かりますが、ログアウト・ログイン直後の cookie 発行順序が曖昧です。ログアウト応答で古い epoch cookie が残ると、次回復元時の同期判定が不安定になります。

修正提案: logout 後も新しい未認証セッションの epoch を Set-Cookie する、または削除ではなく必ず現セッション由来の値へ上書きする、という契約を明記してください。

**スコープの適切さ**
[Warning] 実装範囲はやや広いですが、bfcache ガード、probe、Inertia shared props、middleware、検査が一体なので妥当です。一方で「文書の期限検査」は今回の安全穴を塞ぐ本体ではなく、スコープ膨張気味です。

修正提案: 文書期限検査は今回必須にするなら「実機再確認が未実施のまま忘れられることを防ぐため」と目的を明確化してください。そうでなければ別 TODO に分けてもよいです。

**型安全性**
[Warning] DTO/JsonResource 方針は適切です。ただし TS 側の shape 不正時 fail-closed を維持するには、`SessionStatus` の型ガードを更新し、未知 shape を retry 扱いにする必要があります。

修正提案: PHP 側 DTO、JsonResource、TS 側 response parser、debug trial の `GuardState` 語彙を同じ契約名から検査する architecture / vitest を追加してください。PHPStan level 10 のため、epoch 値は nullable string を曖昧に回さず value object か専用 DTO field に閉じるのが安全です。