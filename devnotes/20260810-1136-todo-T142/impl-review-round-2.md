## ファイル別判定

### `app/Console/Commands/Account/PurgeDeletionRequestsCommand.php`

指摘なし。

`whereColumn('deletion_purge_after', '>=', 'deletion_requested_at')` により、順序異常行は due 母集団から除外されます。異常検出では `unexpected` に計上されるため、削除せず `FAILURE` になる構成です。

DTO 側との二重防御も妥当です。抽出後に状態が変化しても、ロック下の `isDue()` で再確認されます。

### `app/DataTransferObjects/Account/AccountDeletionStateDto.php`

[Suggestion] `isNormalized()` という名前と説明は、DB CHECK 制約全体とは厳密には一致していません。

DB 上の正常状態には「両列とも null」も含まれますが、現在の `isNormalized()` はその状態を `false` とします。実害はなく、実際の意味は「執行可能な正規予約」です。

誤読を避けるなら、次のいずれかが明確です。

- `isNormalizedPendingRequest()`
- `isValidPendingRequest()`
- 説明を「予約中状態として正規か」に限定する

fail-closed 修正そのものは十分です。

[Suggestion] `matches()` は `isPending()` を前提としているため、順序異常の予約でも値が一致すればメール送信対象になります。物理削除にはつながらず、メール内容も保存値の通知なので重大ではありません。ただし「非正規状態では外部通知もしない」という方針なら `isNormalized()` を使う余地があります。

同一秒内の再予約についての主張は適切に狭められています。「古い job を常に抑止」ではなく「値が変わった予約を識別」と明記され、区別不能時に期日が同じという限界も説明されています。誇張・過小とも判断しません。

### `app/Enums/Account/AccountDeletionFreezeAllowance.php`

指摘なし。

`settings.security` は GET の管理画面であり、それ自体は退会の即時執行、課金責務の生成、業務データ作成を行いません。2FA 強制ゲートより凍結が後にある以上、この画面の許可は未準拠ユーザーの脱出口として必要です。

Fortify／Passkeys の変更 route が凍結 group 外にある点は、今回の case 追加によって新しく生じた穴ではありません。認証回復面を凍結対象外とする既存の構造上の判断です。即時削除 route は引き続き凍結されるため、30 日猶予の迂回にもなりません。

### `app/Notifications/Account/AccountDeletionRequestedNotification.php`

指摘なし。

保証範囲は「予約操作からの job 生成は最大1件」「配送は重複しうる」「同一秒内の取消・再予約は識別不能」まで正確に限定されています。

### `tests/Feature/Auth/AccountDeletionFreezeTest.php`

[Warning] 2FA 未準拠ユーザーの脱出テストが、最後まで同一ユーザーの脱出を証明していません。

テスト後半では、未準拠の `$user` を準拠状態へ遷移させず、別の `$compliant` ユーザーを作成して取消しています。これで証明できるのは次の2点までです。

- 未準拠の元ユーザーが `/settings/security` を表示できる
- 最初から準拠済みの別ユーザーが取消できる

証明できていないのは、重要な次の連鎖です。

```text
元の予約中・2FA未準拠ユーザー
→ settings.security
→ 2FA設定完了
→ 同じユーザーが取消
```

実在した Critical の再発防止テストとしては、この連鎖を同一ユーザーで固定する必要があります。実際の2FA登録操作をFeatureテストで再現するのが重ければ、元の `$user` を既存Factory/state helperと同じ方法で準拠状態へ遷移させ、refresh後に取消できることを確認してください。

[Warning] `queuedJobClasses()` は「業務ジョブ」の一般的な分類ではなく、退会メール通知だけを除外する実装です。

現状のテスト目的には使えますが、コメントの「業務ジョブは1件も投入されない」は少し広すぎます。今後ほかの非業務通知が加われば失敗します。次のどちらかへ揃えるのが明確です。

- 主張を「退会通知以外のqueued classがない」に狭める
- `AutoRechargeTriggerJob` など禁止対象クラスを名指しで検査する

解析経路を実際に叩き、`AnalysisJob` と `AutoRechargeTriggerJob` の双方を確認した追加テスト自体は、Round 1 の空振りを解消しています。

### `tests/Feature/Console/PurgeDeletionRequestsCommandTest.php`

[Warning] テスト名の「report + FAILURE」のうち、`report()` は検証されていません。

現在のアサーションが固定しているのは以下です。

- `unexpected=1`
- 終了コード `FAILURE`
- ユーザーが削除されない

`report()` 呼び出しを将来削除してもテストは緑です。監視契約まで実装済みと主張するなら、例外ハンドラへの報告を spy/fake で検証してください。少なくともテスト名は、現状の保証より広くなっています。

順序異常行を削除しないテストと M30 は、Round 1 の Critical に対する有効な回帰防止になっています。

## 総括

順序異常行の fail-closed 修正は十分です。クエリ抽出とロック下再評価の両方で閉じており、物理削除へ進む経路は見当たりません。

`settings.security` の追加も必要かつ妥当で、新しい猶予迂回口や課金責務生成経路にはなっていません。PR-C の先取りとも判断しません。

一方、発見した2FAの詰みについて、回帰テストが「同じ未準拠ユーザーが準拠して脱出する」ことをまだ証明していません。Critical相当の再発防止として、この空振りは残せません。

**全体判定: CHANGES_REQUESTED**