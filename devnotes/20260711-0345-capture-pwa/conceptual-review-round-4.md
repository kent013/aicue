# 全体判定: CHANGES_REQUESTED

Round 3 の指摘は適切に反映されていますが、状態追加に伴う新たな不整合が2件あります。

## 1. 使命との整合性

[Suggestion] 撮影から採用までの成功条件が明確で、North Starへ直接貢献しています。変更不要です。

## 2. 禁止事項違反

[Suggestion] DTO、JsonResource、Architectureテストへの登録方針が明示されており、明白な禁止事項違反はありません。

## 3. 実現可能性

[Critical] D4-1では、既存Takeがあれば「今回の予約をreleased化し、今回アップロードされたS3オブジェクトを削除」としています。しかしcompletedチケットの再送では、その予約の`video_path`が既存Take本体と同じです。この処理を適用すると、冪等200を返しながら登録済み動画を削除します。D2のcompleted契約とも矛盾します。

修正提案: 既存Take発見時を次のように分岐してください。

- 同じcompleted予約からの再送: 何も削除・更新せず200。
- 別のpending/verifying予約による重複: その予約をreleased化し、既存Takeと異なるキーだけ削除。
- 予約とTakeのキー・checksum等が矛盾: 削除せず409として調査可能な状態を残す。

「completed再送でS3削除が発生しない」テストも必要です。

[Warning] verifying中に同じチケットが再送された場合の応答が未定義です。現在のD4-2はclaim失敗をreleased/期限切れの422として扱っていますが、fresh verifyingはそのどちらでもありません。

修正提案: fresh verifyingは409または202として「処理中・再試行可能」を返し、stale verifying、completed、releasedとは明確に分けてください。

## 4. 期待効果の妥当性

[Critical] D3の`bytes_pending`がpending予約だけを集計しています。D4で予約をverifyingへ変更した瞬間からTake確定まで、そのサイズがQuota集計から消えます。この間に別のupload-urlを発行できるため、最終的に上限を超過します。

修正提案: 予約容量は`pending + verifying`を集計し、completedまたはreleasedになるまでQuotaを占有させてください。claim中の同時upload-url発行でも上限を超えない並行テストを追加します。

## 5. リスク

[Warning] ダウンロード完了からACKまでに別Takeが採用されると、実際にはダウンロード済みなのにACKが422となり、そのTakeを削除できてしまいます。

修正提案: 署名GET URLと同時に、take・利用者・期限へ束縛した短寿命の署名済みACKトークンを発行してください。ACKでは「現在採用中」ではなく、そのトークンを検証します。端末別台帳は不要です。

## 6. スコープの適切さ

[Suggestion] A〜Dの段階分割は妥当です。上記の予約状態・Quota不変条件はA〜Bの完了条件に含めるべきです。

## 7. 型安全性

[Suggestion] DTO境界とdecoder方針はPHPStan level 10に適合可能です。checksumは「正しいBase64かつデコード後32 bytes」を値オブジェクト生成時に保証すると安全です。

主構造は承認可能な水準に近づいています。残る必須修正は、`verifying`をQuota占有状態へ含めることと、completed再送で既存S3オブジェクトを削除しない状態分岐です。