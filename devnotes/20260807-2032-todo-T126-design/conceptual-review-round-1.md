全体判定: **CHANGES_REQUESTED**

方向性は妥当です。特に「SDK 既定値に依存した外部待ち」をアプリ側の不変条件として pin し、deny-by-default の Architecture テストで固定する判断は、このリポジトリの作法と合っています。ただし、現設計のまま実装に入るには **AWS retry / timeout の worst-case 算定** と **Stripe の大域状態テスト隔離** がまだ弱いです。

## 1. 使命との整合性

[Warning] 撮影 PWA の `TakeRegistrationService::register()` が S3 `HeadObject` で詰まる問題を主軸に置くのは、North Star に直接貢献しています。一方で、設計の後半はキュー帯の短縮に話が寄りすぎています。

修正提案: T126 の主目的を明文化して、優先順位を固定してください。

- 主: web 経路の外部 SDK 待ちを有限化し、php-fpm 枯渇を防ぐ
- 従: その結果として queue lease の過大設定を縮める

この順序をテスト名・docs・TODO 更新にも反映すると、T127 との境界がぶれにくいです。

## 2. 禁止事項違反

[Suggestion] 直接の禁止事項違反は見当たりません。

ただし実装時は以下に注意が必要です。

- テストなし完了報告は禁止なので、Architecture gate だけでなく、少なくとも `TakeObjectStorage::headObject()` の per-command `@http` が実際に乗る behavioral test が必要
- Stripe の timeout 例外分類を固定するなら、T132 の `GatewayFailureClassifier` に対するテスト追加まで含める
- `response()->json()` / Prism / prompt 直書きとは無関係なので、スコープに混ぜない

## 3. 実現可能性

[Critical] AWS SDK の `timeout = 15s` と `retries = 3` を同時に使う場合、worst-case は **15s ではなく最大 45s + backoff** です。設計本文では SES については `15s × 3 = 45s` と書いていますが、web 経路の S3 `HeadObject` / SNS 購読確認の表では `15s` として扱っており、可用性リスクの見積もりが甘く見えます。

修正提案: 制御系 AWS の web 経路について、どちらかに寄せてください。

- web 制御系は `retries = 0 or 1` にする
- もしくは「web 経路の上限は 15s × attempts + backoff」と明記し、その値でも php-fpm 保護として許容できることを判断する

撮影登録の `HeadObject` はユーザー操作の同期 API なので、ここは retries を弱める方が筋が良いです。失敗時に再送できる UI/業務設計なら、SDK 内で長く粘らせるよりアプリ側で失敗を返す方が制御しやすいです。

[Warning] Stripe の `ApiRequestor::setHttpClient()` はプロセス大域で正しい選択に見えますが、テスト間の状態漏れが設計にまだ落ちていません。

修正提案: Architecture/Feature テストでは、Stripe HTTP client を触るテストに state restore を入れるか、専用 helper で既存 client を退避・復元してください。また、AppServiceProvider boot 後に期待値へ戻ることを固定するテストを置くとよいです。

## 4. 期待効果の妥当性

[Warning] 「web 経路の外部待ち上限: S3/SNS 無制限 → 15s」と言い切るのは、AWS retry を維持するなら不正確です。

修正提案: 期待効果は attempt 数込みで書き直してください。

例:

- S3 metadata: `timeout 15s`, `connect_timeout 5s`, `max_attempts N`
- 実効上限: `timeout × attempts + SDK backoff`
- queue 外部予算: Stripe `30s × 最大呼び出し数` に加えて、必要なら backoff と cleanup 呼び出しの扱いも明記

[Suggestion] Stripe の `maxNetworkRetries = 0` を pin する判断は良いです。既定値に依存しないこと自体が価値です。ただし「0 にする理由」は docs に残した方がよいです。課金系は外部冪等キーとリコンサイルで担保する設計なので、SDK 自動 retry に寄せない、という説明が合います。

## 5. リスク

[Critical] S3 disk 全体に `timeout = 900s` を設定し、`headObject` だけ `@http` で絞る設計は方向として妥当ですが、漏れた metadata 操作が将来 web 経路に追加されると 900s の同期待ちが再発します。

修正提案: 目録 gate は「クライアント構築点」だけでなく、**web 同期経路から呼ばれる S3 metadata 操作**も inventory 化してください。最低限、`HeadObject` / `GetObjectAttributes` / `ListObjects` など本文転送ではない操作を web 経路で使う場合は per-command timeout 必須、という分類を置くべきです。

[Warning] `retry_after 600 → 360` と `worker --timeout 540 → 300` は合理的ですが、本番 supervisor がリポジトリ外なら、コード変更だけでは不変条件が成立しません。

修正提案: docs 更新に加えて、デプロイ runbook に「supervisor の `--timeout=300` 反映が同時に必要」と明記してください。CI で検知できないなら、少なくとも運用上の破壊的変更として扱うべきです。

## 6. スコープの適切さ

[Warning] 同一 PR に timeout pin と queue 帯の張り替えを含める判断は基本的に妥当です。ただし前提は「新しい worst-case 算定が正しい」ことです。現状の AWS retry 算定のままだと、同一 PR に含める根拠が弱いです。

修正提案: PR は同一でよいですが、実装順を分けてください。

1. SDK timeout / retry pin と behavioral gate
2. worst-case 表の更新
3. `retry_after` / worker timeout の変更
4. 既存 lease invariant の更新

これなら「pin だけ入って帯が古いまま」の中間状態を残さず、かつレビュー時に根拠を追えます。

[Suggestion] T127 を昇格させない判断は妥当です。今回の主問題は「無制限待ち」と「SDK 既定値依存」であり、queue connection 分割は観測された回収遅延の実害が出てからでよいです。

## 7. 型安全性

[Suggestion] `ExternalClientTimeouts` を `final class` + `public const int` にする方針は PHPStan level 10 と相性が良いです。

注意点:

- config から参照する定数は `int` として明示
- inventory exemption は既存パターンと同じく backed enum + 30文字以上 rationale
- AWS config array は shape が緩くなりやすいので、可能なら小さな factory/helper に寄せると PHPStan の見通しが良くなります

## 判断を求められた論点

1. Stripe はプロセス大域 pin でよいです。他に十分な粒度の選択肢は実質ありません。リスクはテスト隔離と「テナント別 timeout はしない」という設計判断の明文化で受け止めるべきです。

2. 制御系 / データ系で分ける判断は妥当です。`headObject` の per-command 上書きは過剰ではありません。むしろ S3 disk 全体を短くすると本体転送を壊し、全体を長くすると web 同期待ちを塞げません。

3. 帯の張り替えは同一 PR でよいですが、AWS retry 込みの worst-case を修正してからです。古い帯だけ残す中間状態は避ける方がよいです。

4. T127 を昇格させない判断は妥当です。今回の変更で回収遅延が緩和されるなら、接続分割はまだ条件未成立です。

5. 見落とし候補としては、Cashier/Stripe 以外の vendor SDK 直呼び、Socialite、Stripe SDK の webhook 検証以外の補助 API、AWS SDK を Laravel Filesystem 経由で暗黙に使う箇所です。特に「S3 disk を使うが本文転送ではない操作」は inventory 対象に含めるべきです。

結論として、設計の芯は良いですが、**AWS retry を含めた実効上限の再定義**と、**S3 metadata 操作の漏れを防ぐ inventory 設計**を入れてから承認が妥当です。