**全体判定: CHANGES_REQUESTED**

設計の方向性は概ね妥当ですが、S2/S3/S4/S6 に実装時にそのまま入れると不変条件が破れる可能性がある指摘があります。

## S1 所有権喪失の共通語彙

判定: APPROVE

[Suggestion] `JobOwnershipLostException` は Manual namespace ですが Render も Manual ドメイン内なので許容できます。将来 Billing でも例外型を共有したくなった場合だけ `App\Exceptions\Security` 等への移動を検討すれば十分です。

## S2 解析パイプライン preflight

判定: REQUEST_CHANGES

[Critical] LLM 呼び出し後、次の preflight までに `runExtractStep()` が `SourceDocument` 保存と `updateProgress()` を実行します。テスト案の「extract 1 回目直後に cron が failed 化」は、まさに **terminal 化された後に旧ワーカーが自前 DB 書き込みを行う** 経路です。`updateProgress()` が status guard なしなら failed 状態を running/progress で汚染する恐れがあります。

修正案: 各 LLM 成功後、DB 書き込みの直前にも ownership/status guard を置くか、`updateProgress()` と `extracted_json` 保存を `where status=running` / locked status guard で条件付きにしてください。少なくともテスト期待に「cron failed 後に progress/step/error が上書きされない」を追加してください。

[Warning] `Log::spy()` は facade の呼び出し順や既存 warning と混ざると壊れやすいです。

修正案: `ExternalCallKind::LOG_EVENT` を含む context の有無を抽出して検査し、メッセージ文字列依存を避ける設計にしてください。

## S3 レンダパイプライン preflight

判定: REQUEST_CHANGES

[Warning] `updateProgress()` の後に preflight を置く判断は S3 PUT 抑止としては正しいですが、compose 中に cron が failed 化した後、`onClipComposed()` や `updateProgress()` が旧ワーカーから進捗を書き戻す可能性が残ります。

修正案: S2 同様、進捗更新系を status guard 付きにする、または stale 先勝ち後に progress/step/error_code が cron の値から変わらない Feature テストを追加してください。

[Suggestion] `DeleteRenderOutputsJob が dispatch されない` は、どのタイミングで dispatch される実装かに依存します。`$uploadedKey === null` と S3 missing の検査が主契約で、Queue assert は補助に留めるのがよいです。

## S4 auto-recharge preflight

判定: REQUEST_CHANGES

[Critical] `preflight 2` で canceled を検出した後に `tryTerminateInvoice($attempt)` を呼ぶ設計ですが、`terminateAndCancel()` が `stripe_invoice_id === null` の状態で先に canceled へ遷移し、その後旧ワーカーが invoice_id を保存したケースでは、attempt はすでに terminal です。この後始末は必要ですが、terminal 行へ invoice_id を後から保存すること自体が状態機械の例外になります。

修正案: invoice_id 永続化を単純な `$attempt->save()` ではなく `whereKey()->where(status=pending)->update(['stripe_invoice_id' => ...])` にし、0 行なら「保存できなかった invoice」として invoiceId 引数で終端してください。`terminateInvoiceAfterOwnershipLost(TicketAutoRechargeAttempt $attempt, string $invoiceId)` のように、DB に保存済みであることへ依存しない形が安全です。

[Critical] `recordSuccessfulCharge()` は `grantAutoRecharge()` を実行してから attempt の conditional update を行っています。もし attempt がすでに canceled/failed/paid の場合でも、先に台帳付与が走る可能性があります。これは「結果の一回性」を条件付き UPDATE が担うという説明と矛盾します。

修正案: transaction 内で attempt を `pending -> paid` に条件付き UPDATE して、`$updated === 1` の場合だけ `grantAutoRecharge()` を実行する順序へ変えるか、`grantAutoRecharge()` 側の unique key が「attempt 単位で」二重付与を拒否することを S4/S6 に明記し、テストで固定してください。

[Warning] `stillPending()` のログ context に `attempt_ulid` を入れています。PII ではない想定ですが、S1 の `JobOwnershipLostException::logContext()` とキー集合が揃わず、集計語彙が割れます。

修正案: 共通 event に載せる最小キーを統一し、追加キーは billing 専用として許容するならテストで「PII なし」を billing 側にも追加してください。

## S5 排他 TTL / uniqueFor 序列

判定: APPROVE

[Warning] `AutoRechargeTriggerJob` は既定接続なので `database.retry_after` 比較でよいですが、将来接続 pin が入るとテストが嘘になります。

修正案: テスト名かコメントに「既定接続であることも固定する」assert を追加すると、T127 系の変更時に明確に赤くできます。

## S6 横断 gate

判定: REQUEST_CHANGES

[Critical] `PreflightCheckpoint` が private method `AnalysisPipeline::assertStillOwned` / `RenderPipeline::assertStillOwned` を指しています。Reflection で存在と戻り型は確認できますが、deny-by-default gate としては「外部呼び出し直前に呼ばれていること」を検査できません。名前だけ存在する空メソッドでも green になります。

修正案: Architecture gate は「再検証点の存在」までに限定する、と明記してください。実際の配置保証は Feature テストで、所有権喪失時に LLM/S3/Stripe fake が呼ばれないことを契約にするのが現実的です。設計文の「機械検査できる形」は言い過ぎです。

[Warning] `QueuedJobPopulation::shouldQueueClasses()` は `class_exists()` により autoload 副作用を起こします。既存テスト踏襲なら許容ですが、Architecture test としては重いです。

修正案: 既存方針を踏襲する旨を残すか、可能なら Composer classmap / token parser ベースに寄せてください。今回は委譲だけなら現状維持で構いません。

[Warning] `NoExternalCall` / `GuaranteeEntry` の constructor に `mb_strlen()` を使うため、mbstring 前提です。Laravel では通常ありますが、PHPStan/CI 環境前提として明記した方がよいです。

修正案: `strlen()` で十分なら ASCII 根拠文に寄せる、または mbstring 前提を CI 環境要件として明記してください。

## S7 文書化

判定: APPROVE

[Suggestion] 「文書の存在自体を検査しない」は妥当です。ただし AGENTS.md 追記は運用規約なので、既存の VERIFICATION_COMMANDS marker や numbering に触れないことを実装差分レビューで必ず確認してください。