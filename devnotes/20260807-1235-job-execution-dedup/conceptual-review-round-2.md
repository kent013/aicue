全体判定: **CHANGES_REQUESTED**

Round 1 から大きく改善され、期待効果・残余リスク・観測性・型安全性の整理は妥当です。ただし、中核反論の「CAS でも窓の幅は同じ」は成立しません。CAS の使い方を「送信許可の獲得」として設計すれば、少なくとも `recoverStale` との競合窓は閉じられます。

## 1. 使命との整合性

[Suggestion] 二重課金・terminal 済み成果物・無駄な LLM 呼び出しの抑止は、成果物への信頼と継続利用を支えるため North Star に整合します。

直接的な教材品質改善ではありませんが、基盤信頼性としてスコープに含める合理性があります。

## 2. 禁止事項違反

[Suggestion] 明示的な禁止事項違反はありません。

Feature テスト、Architecture テスト、mutation による赤化確認まで完了条件に含めたため、禁止事項 1 への対応も概念上は十分です。

ただし S5 で AGENTS.md に追加する規約は、S4 の目録だけでなく、その規約自体が表す不変条件をどのテストが保証するか詳細設計で対応付けてください。

## 3. 実現可能性

[Critical] 「CAS でも `CAS成功 → cronがfailed化 → 送信` が成立し、窓の幅も同じ」という反論は、CAS を導入する場合の状態遷移設計を固定せずに比較しており、成立しません。

例えば次の状態機械なら、cron との競合は DB 上で直列化されます。

```text
worker: running --CAS--> sending
cron:   running --CAS--> failed
```

両者の条件を `status = running` にすれば、成功できるのは一方だけです。worker が `sending` を獲得した後に cron が無条件で `sending → failed` を許す設計にして初めて、提示された race が再発します。それは CAS の限界ではなく、回収状態機械の設計ミスです。

もちろん、`sending` 獲得後のプロセス死による「送信済みか不明」は残ります。しかしこれは、

- terminal 化した後に旧 worker が送信する競合
- 送信開始後に結果が不明になる障害

という別の窓です。現在の文書はこの2つを「外部送信とDBを原子化できない」という理由で統合しています。

修正提案:

- 「CAS は完全な exactly-once を実現しないが、`recoverStale` と送信開始の競合は閉じられる」と訂正する。
- CAS を採らないなら「窓が縮まらないから」ではなく、既存 timeout 序列により本番での発生可能性が十分低く、状態追加のコストが便益を上回る、というリスク受容に変更する。
- 家系への還流も「CAS と同等」ではなく「限定的な terminal 検出策」と位置付ける。

[Warning] 「生きている worker は recoverStale の母集団に入らない」という表現は強すぎます。

通常の `queue:work` と正常に機能する `pcntl` timeout では合理的ですが、成立には少なくとも以下が必要です。

- timeout handler が実際に有効
- timeout を遅延させる実行形態や拡張処理がない
- `updated_at` の基準時刻と cron の時刻に許容不能なずれがない
- worker 停止・再開時に pending timeout が送信処理より先に処理される
- supervisor が timeout 超過 process を確実に排除する

特に OOM kill、deploy、ホスト死した worker は「その後復帰」しません。「圧倒的多数を占める」とする例示は不正確です。

修正提案:

- OOM kill / deploy / host death を「復帰する worker」の例から削除する。
- 対象を SIGSTOP、VM suspend、signal delivery 遅延など、同一 process が再開する経路に限定する。
- 「入ることはない」を「通常運用条件下では先に timeout する」に弱め、前提を Architecture/運用テストへ明記する。

[Warning] `JobOwnershipLostException` を Render と Analysis で共用しながら `App\Exceptions\Manual` に置くのは、責務境界が曖昧です。

修正提案: 両方が Manual ドメイン所属であることを詳細設計で示すか、実際の共有境界に合わせた namespace に置いてください。

## 4. 期待効果の妥当性

[Warning] 効果の限定表現は改善されていますが、文書冒頭の「保証は外部呼び出し直前の所有権再検証が担う」は依然として強すぎます。

本文自身が、再検証後の race と LLM の非冪等性を認めています。再読込が担うのは「既に失われた所有権の検出」であり、結果の一回性そのものではありません。

修正提案:

- 改善アイデアを「保証は永続状態遷移・外部冪等性が担い、直前再検証は既知の所有権喪失後の送信を抑止する」に変更する。
- Analysis/Render については「結果の一回性」と「外部呼び出し回数の一回性」を分離する。
- S4 の enum でも `Guarantee` と `PreflightSuppression` を同じ分類にしない。

[Suggestion] Stripe の4層整理は妥当です。ただし、invoice 作成と支払いが異なる Stripe 操作なら、同一文字列の idempotency key をそのまま再利用できるという意味に読めます。詳細設計では、各操作に安定した別キーを導出していることをテストで固定してください。

## 5. リスク

[Warning] `Log::warning` を毎回出すだけでは、頻度を測れるとは限りません。ログ基盤上で集計可能な固定 event 名が必要です。

修正提案:

- `event = job_ownership_lost` など固定識別子を含める。
- `jobType`、`stage`、`externalCall` は自由文字列でなく enum を検討する。
- 例外コンテキストに tenant PII や外部 payload を含めないことを固定する。
- ログ検証に加え、同じ異常が連続した場合に検知できる運用契約を S5 に記載する。

[Warning] AutoRecharge の2回目の再検証で停止を検出した場合、Stripe invoice は作成済みで、DBにも `stripe_invoice_id` が保存済みです。この no-op が reconcile により必ず収束することが設計本文だけでは確認できません。

修正提案: 「リコンサイル (i)/(ii)」について、どの状態を検出し、支払い・取消・terminal 遷移のどれへ収束させるかを詳細設計の前提として明記し、Feature テスト対象に含めてください。

## 6. スコープの適切さ

[Suggestion] claim token の全面移植、専用 queue 化、ffmpeg 前の追加検査を除外した判断は妥当です。

18件を deny-by-default の母集団に保つ反論も成立しています。母集団を意味的セレクタで絞る方が分類漏れを生みやすいため、配信系を型付き免除へ集約する案は適切です。

[Warning] 一方で S4 の名前が `JobExecutionDedupInventory` なのに、配信系を「domain state write がない」だけで免除するのは不十分です。メールや通知自体も重複すれば外部副作用になります。

修正提案: 免除理由には「重複配信を受容できる」「provider側冪等性がある」「ジョブの retry 契約上 at-least-once を受容する」など、その副作用に対する裁定をクラスごとに記録してください。単なる domain write の有無を免除根拠にしないでください。

## 7. 型安全性

[Suggestion] `final readonly GuaranteeEntry` / `ExemptionEntry`、typed enum、`class-string`、`non-empty-string`、`void` Reflection 検査は PHPStan level 10 と整合します。

[Warning] `jobType` / `stage` / `externalCall` を単なる string にすると、ログ分類と目録の対応が静的に保証されません。

修正提案:

- `JobExecutionStage`
- `ExternalCallKind`
- 必要なら `QueuedJobKind`

のような enum を使用し、例外・ログ・目録で同じ語彙を共有してください。ただし既存ドメインの enum と意味が異なるなら統合しないでください。

結論として、直前再検証を追加する実装方針自体は合理的です。しかし、それを CAS と同等の所有権保証と評価する反論は不成立です。**「完全保証だから採用」ではなく、「既存 timeout 序列の下で費用対効果の高い抑止策として採用」へ設計上の主張を修正すれば APPROVED に近づきます。**