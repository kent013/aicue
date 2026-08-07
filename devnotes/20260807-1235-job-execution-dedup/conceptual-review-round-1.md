全体判定: **CHANGES_REQUESTED**

**使命との整合性**
[Warning] 方向性は使命に整合します。解析・レンダ・課金の二重実行を抑えることは、動画成果物と課金の信頼性を支えるため重要です。  
ただし「最大 3 回 → 0 回」「S3 PUT 1 回 → 0 回」「Stripe 課金が構造的に不可」という期待効果は、現設計の再検証だけでは言い切れません。外部呼び出し直前の `status` 再読込と実際の送信の間にはまだ race window が残ります。

修正提案: 効果表現を「発生確率を下げる」ではなく、本当に 0 にしたい箇所は外部側の冪等性キー、送信専用状態への CAS 遷移、claim token / lease generation など、送信権の原子的な確定手段を設計に含めてください。

**禁止事項違反**
[Warning] 現時点では明確な禁止事項違反はありません。`response()->json()`、Prism 直呼び、prompt 直書き、UI disabled などには触れていません。  
ただし `AGENTS.md` へのドメイン規約追加を含むため、詳細設計では Architecture テストまで含めないと「テストなしの実装完了報告」に抵触します。

修正提案: S4 の inventory test だけでなく、再検証が外部呼び出し前に必ず呼ばれることを落とす Feature / Unit test を入れてください。

**実現可能性**
[Critical] `status === Running` の再読込を「所有権の完全な再検証」とみなす中核判断が弱いです。  
`recoverStale()` が `running → failed` に変えた直後、旧ワーカーが `status` を再読込して `running` を確認し、その直後に cron が `failed` 化する、という順序では外部呼び出しが実行されます。つまり「直前の SELECT」は排他でも CAS でもなく、構造的保証にはなっていません。

修正提案: 少なくとも以下のどれかに寄せる必要があります。

- 外部送信前に `running → sending_*` の条件付き UPDATE を行い、更新できた worker だけ送信する
- job row に `execution_token` / `lease_generation` を持たせ、送信前後で一致を CAS 検証する
- 外部 API 側の idempotency key を正式な保証層に含める
- stale recovery が実行中 worker と競合しないよう、heartbeat / lease 更新を状態機械に組み込む

[Warning] AutoRecharge の `Pending` 再確認も同じ問題があります。`Pending` 確認と `createAutoRechargeInvoice()` の間、または `payOffSessionInvoice()` の直前確認と Stripe 送信の間に race が残ります。

修正提案: Stripe については Stripe idempotency key を attempt id に固定する設計を入れるのが自然です。さらに `stripe_invoice_id` 保存後の支払いは、DB 状態と Stripe 側 idempotency の両方で一回性を担保する形にしてください。

**期待効果の妥当性**
[Critical] 期待効果が設計の保証能力を超えています。  
「stale 回復後の LLM 課金呼び出し最大 3 回 → 0 回」は、再検証後に stale 化される race を考えると成立しません。S3 PUT と Stripe も同様です。

修正提案: 概念設計の効果を次のように分けてください。

- 再読込チェックで防げるもの: 既に terminal になっている job の後続外部呼び出し
- まだ防げないもの: チェック直後に所有権を失う race
- 完全に防ぐために必要なもの: CAS / token / 外部 idempotency

**リスク**
[Critical] `JobOwnershipLostException` を `Log::info + return` にする設計は、失われた所有権と本物の実装ミスを区別しにくくするリスクがあります。特に `catch (Throwable)` より前で握る場合、誤って投げられた所有権例外がジョブ失敗として観測されません。

修正提案: 例外には `job_id`, `expected_status`, `actual_status`, `stage`, `external_call_kind` を持たせ、専用ログ・メトリクス・テストで観測可能にしてください。通知や ticket release を避ける判断自体は妥当ですが、監査可能性は必要です。

[Warning] Render の `storage->upload()` 直前だけを見ると、multipart upload やアップロード途中のプロセス死による孤児は残ります。設計文書では「発生源を 1 つ潰す」ではなく「開始前に検出済み terminal の PUT を抑止する」と表現すべきです。

**スコープの適切さ**
[Warning] 入口排他を追加しない判断、claim token を安易に移植しない判断、T127 を分離する判断は妥当です。  
一方で、S4 の横断 gate は 18 件すべてを対象にするため、今回の修正範囲に比べてやや重い可能性があります。特に Mailable / Notification まで同じ dedup inventory に入れると、免除目録が増えてノイズ化する恐れがあります。

修正提案: 母集団を「外部副作用または課金・成果物状態に触れる queued class」に絞るか、全 `ShouldQueue` を対象にするなら分類カテゴリを明確に分けてください。

**型安全性**
[Warning] enum + 30 文字以上の根拠 + Reflection 検査の方向は PHPStan level 10 と相性が良いです。  
ただし `[class-string, method]` の配列 shape は曖昧になりやすく、PHPStan で型が緩みがちです。

修正提案: `JobDedupInventoryEntry` のような value object を作り、`class-string`, `non-empty-string`, enum, rationale を constructor で受ける形にすると安全です。Reflection だけでなく、再検証メソッドの戻り型や例外型も固定してください。

**結論**
設計の問題意識とスコープ分離は良いです。  
ただし中核の「status 再読込 = 所有権保証」が race を閉じていないため、このままでは主張している一回性の保証に届きません。概念設計段階で、再読込チェックを補助層として位置づけ直すか、CAS / token / 外部 idempotency を保証層として追加する修正が必要です。