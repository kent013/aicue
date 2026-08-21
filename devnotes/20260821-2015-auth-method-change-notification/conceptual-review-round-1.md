## 全体判定: CHANGES_REQUESTED

方針自体（認証手段の変更を本人へ通知し、vendor event 購読と最小限の直接呼び出しで集約する）は妥当です。ただし、パスキー削除時の確定性、通知投入失敗時の挙動、初回パスワード設定の除外には、設計目的を損ね得る未解決点があります。

### 1. 使命との整合性

- [Suggestion] 本改善は North Star への直接機能ではありませんが、動画マニュアル等の業務データを守るアカウント保護の基盤として十分に正当化できます。「ほぼリアルタイム」はキュー滞留・メール配送遅延を含むため、「できる限り早く」に表現を弱めると期待値が正確です。

### 2. 禁止事項・設計原則

- [Suggestion] 既存の `EmailChangedSecurityNotification` と、本人向け認証手段変更通知を実装上まで統合しない判断は適切です。送付先（旧メール／現登録メール）と操作文脈が異なります。

- [Suggestion] 新たな通知窓口サービスを現時点で必ずしも作らない判断も合理的です。ただし後述の「投入失敗を元操作に波及させない」を本当に保証するなら、例外処理と記録を一箇所に置く小さな dispatch service には明確な責務が生じます。

### 3. 実現可能性

- [Critical] `app()->terminating()` を「commit 確定後の通知投入」とみなす設計は、そのままでは安全境界になりません。終了コールバックは DB transaction と結び付いておらず、`PasskeyDeleted` 後に例外が起きて transaction がロールバックしても、登録済みコールバックを自動取消しする保証が設計にありません。すると「削除されていないのに削除通知だけ送る」不整合が起こり得ます。

  修正案: `EnsureLoginMethodRemains` が `DB::transaction()` から正常復帰した後にだけ、transaction 内で収集した「通知予定」を明示的に flush してください。失敗・ロールバック時には予定を破棄します。subscriber はパスキー削除時だけ即時投入せず、リクエストスコープの intent collector へ記録する形が考えられます。少なくとも成功、ロールバック、レスポンス生成失敗の各ケースで「queue job が 1 件／0 件」を固定する Feature テストが必要です。

  Laravel でも `after_commit=false` のキュー投入は、親 transaction の commit 前に処理され得るため注意が必要と明示されています。[Laravel Queues](https://laravel.com/docs/12.x/queues)

- [Critical] 「送信失敗が元の操作を失敗させない」は、`ShouldQueue` だけでは達成されません。メール配送の失敗は後続 worker に隔離されますが、database queue へのジョブ投入自体が失敗すれば、同期 listener または `linkToUser()` の `$user->notify()` は例外を送出し、元操作を失敗させ得ます。

  修正案: 通知投入を例外隔離し、失敗を `report()` 等で必ず観測可能にしたうえで元操作には再送出しない、という共通方針を明記してください。この要件を担う最小の notifier / dispatcher を置くことは過剰抽象化ではありません。queue 接続異常時にもパスワード変更・2FA 操作・SSO 連携が成功すること、かつ失敗が記録されることをテストしてください。

- [Warning] 「他イベントは transaction 制約下にない」という前提は、各 vendor event と自前 Service の実際の発火位置で確認・テストする必要があります。Laravel は queued notification も `after_commit` 設定の影響を受けるため、将来の実装変更で同種の競合が再発し得ます。[Laravel Queues](https://laravel.com/docs/12.x/queues)

  修正案: イベントごとに「発火元・transaction の有無・投入方法」を設計表として固定し、transaction 内で起こり得るイベントは明示的に commit 後経路へ寄せてください。

### 4. 期待効果の妥当性

- [Warning] 2FA 有効化と回復コード生成が同一操作列で連続発火する場合、利用者に複数メールが届く可能性があります。通知対象をイベント単位で列挙するだけでは、「1 操作につき何通か」が曖昧です。

  修正案: 操作と通知の対応表を追加し、2FA 初期設定中の回復コード生成を独立通知にするか、再発行時だけ通知するかを決めてください。少なくとも重複送信の有無を統合テストで固定すべきです。

- [Suggestion] 「新しい認証手段が増えても漏れにくい」は合理的ですが、vendor event でカバーできない新経路を検出する仕組みまでは本設計にありません。`RecordSecurityEvent` と同様、対象イベントの inventory テストを追加すると主張を実装上も支えられます。

### 5. リスク

- [Critical] `PasswordCredentialService::setInitial()` の除外理由は、パスキー追加・SSO 連携を対象にする根拠と整合しません。既存アカウントに初めてパスワードを設定することは、新しいログイン手段の追加であり、SSO セッションを奪われた攻撃者が永続的な認証手段を加える経路にもなり得ます。

  修正案: 原則として初回パスワード設定も通知対象にしてください。どうしても除外するなら、当該経路が新規登録時のオンボーディングに限定され、既存アカウント・既存セッションから到達できないことをコードと Feature テストで証明する必要があります。現提示情報だけではその証明がありません。

- [Warning] queued notification が `User` を再解決して配信先を求める場合、操作時点と実行時点の間にメールアドレスが変わると、意図した「登録メールアドレス」への送信先が変わります。

  修正案: 配信先を「操作時点のアドレス」に固定するか「送信時点の現アドレス」にするかを明文化してください。前者なら、キューに積む通知へ検証済みの宛先スナップショットを安全に渡す設計が必要です。

### 6. スコープの適切さ

- [Suggestion] 未実装の SSO 解除機能を先回りして実装しない判断は適切です。将来の解除実装時に、この enum と通知ポリシーへ追加することを受入条件に含めれば十分です。

- [Suggestion] 新規登録時の `register()` では SSO 連携通知を出さず、既存利用者の `linkToUser()` のみ通知する区別も妥当です。監査ログと通知の対象範囲を意図的に一致させない点を、実装時のテスト名にも反映すると誤変更を防げます。

### 7. 型安全性

- [Suggestion] enum ベースの設計は PHPStan level 10 と相性がよく、実現可能です。`AuthMethodChangeEvent` を enum として通知コンストラクタに厳密に型付けし、各 vendor event 用の listener メソッドを具体的なイベント型で分け、`match` を網羅的にすれば `mixed` や型の緩和は不要です。

- [Warning] subscriber の文字列イベント登録は、イベント名・メソッド名の取り違えを PHPStan が完全には検出できません。

  修正案: `subscribe(Dispatcher $events): void` の登録内容を検証する Architecture/Feature テストと、各イベントから正しい enum の通知が 1 件積まれるテストを追加してください。Laravel の queued notification は `ShouldQueue` でキュー投入されるため、この検証は `Notification::fake()` と queue の双方で行うと確実です。[Laravel Notifications API](https://api.laravel.com/docs/12.x/Illuminate/Notifications/SendQueuedNotifications.html)

上記 Critical を解消し、特に「commit 済みの削除だけ通知する」ことと「通知投入不能でも認証操作は成功する」ことをテストで固定できれば、設計は承認可能です。