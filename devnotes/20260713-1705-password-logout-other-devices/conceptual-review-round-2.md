全体判定: **CHANGES_REQUESTED**

Round 1 の remember-me 問題は解消されています。ただし、DB セッション行削除には並行リクエストによる復活競合があり、`AuthenticateSession` を外すと「確実に締め出す」という不変条件を満たせません。

## 1. 使命との整合性

[Suggestion] テナント境界と機微データを守る基盤改善として使命に整合しています。North Star への直接的な機能貢献ではありませんが、利用前提となるセキュリティ改善として妥当です。

## 2. 禁止事項

[Warning] 概念設計にテスト方針がありません。セキュリティ不変条件なので、実装完了には Feature または Architecture テストへの登録が必要です。

修正提案: 最低限、次を設計に明記してください。

- 他セッション行が削除され、現在行だけ残る
- 古い remember cookie で再認証できない
- 現在セッションが維持される
- DB 処理失敗時のロールバック
- 並行リクエスト中の旧セッションが復活しても次回要求で拒否される

その他の禁止事項違反はありません。

## 3. 実現可能性

[Critical] **DB セッション行削除だけでは、並行処理中の旧セッションが復活できます。**

攻撃者のリクエストが削除前にセッションを読み込み、削除後にレスポンス終了処理で同じ session ID を書き戻すと、認証済み payload を持つ行が再作成され得ます。`remember_token` の rotate は、この既にロードされたセッションには効きません。

修正提案: `auth.session`（`AuthenticateSession`）を、少なくとも保護対象となる全認証 Web ルートへ配線してください。Laravel の公式手順も、`logoutOtherDevices()` と併せて同ミドルウェアを認証ルートの大部分へ適用することを前提としています。[Laravel 12 Authentication](https://laravel.com/docs/12.x/authentication#invalidating-sessions-on-other-devices)

これにより復活した行でも、保存された password hash と現在の hash の不一致により次回リクエストでログアウトされます。

[Warning] 「`queueRecallerCookie()` は現在リクエストが recaller を持つ場合のみ」という記述は再確認が必要です。`logoutOtherDevices()` は recaller の有無を条件にせず cookie を queue する実装であり、session-only ユーザーにも remember cookie を付与する可能性があります。

修正提案: Laravel 12 の固定バージョンの `SessionGuard` を確認し、無条件発行なら期待効果を「現在デバイスの remember-me を維持」ではなく「現在デバイスへ新しい recaller を発行」に修正し、プロダクトとして許容するか判断してください。

## 4. 期待効果

[Critical] 「両輪で必要十分」「確実に失効」は、並行リクエスト競合が残るため成立しません。

修正提案: `AuthenticateSession` を認証ルート群へ適用したうえで、次の三層として記述してください。

- DB 行削除: 通常の既存セッションを即時失効
- remember token rotate: 古い recaller による再認証を拒否
- password hash 照合: 並行書き戻しされた旧セッションを次回要求で拒否

## 5. リスク

[Warning] `DB::transaction()` 内で `logoutOtherDevices()` を呼ぶと、recaller cookie の queue と `OtherDeviceLogout` イベントはDBロールバック対象になりません。後続DELETEやcommitが失敗すると、DBは戻ってもレスポンスcookieや同期listenerの副作用だけが残り得ます。

修正提案: DB更新と外部副作用の境界を詳細設計で分離してください。イベントlistenerはafter-commitを要求し、cookie発行後に失敗し得る処理を置かない構成にします。

[Warning] session用DB接続が `config('session.connection')` でアプリ既定接続と異なる場合、`DB::transaction()` とsession DELETEは同一トランザクションになりません。

修正提案: session connectionを明示して同一接続でtransaction/deleteするか、本番設定が同一接続であることを設定テストで固定してください。

## 6. スコープ

[Critical] `AuthenticateSession` をスコープ外にする判断は過小です。これは多層防御だけではなく、database session の並行書き戻し競合を閉じるための整合性機構です。

修正提案: global `web` 配線ではなく、`auth` と同じ保護ルート群への `auth.session` 適用を今回のスコープに含めてください。

[Warning] 「パスワードを変える全経路」という不変条件を宣言しながら、既知の reset 経路違反をスコープ外にすると、PR完了時点で不変条件が偽のままです。

修正提案: 不変条件を今回のセルフサービス経路に限定するか、reset対応TODOを本変更の完了条件として登録してください。

## 7. 型安全性

[Suggestion] DTO/JsonResource不要、`Auth` facade利用という判断は妥当です。PHPStan L10では、`config()` の戻り値をそのままテーブル名・接続名へ渡さず、文字列であることを設定DTOまたは明示的な型検証で保証すると安全です。