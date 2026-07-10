# 全体判定: CHANGES_REQUESTED

Round 1 の指摘は概ね適切に反映されています。ただし、アップロード確定処理に2件の重大な未解決事項があります。

## 1. 使命との整合性

[Suggestion] 成功条件が明文化され、North Star への直接的な貢献を評価可能になっています。スコープ外との境界も妥当です。

## 2. 禁止事項違反

[Warning] D2 の「completed 済みチケットは拒否」と、D4 の「応答喪失リトライでは既存 Take を200で返す」が矛盾しています。同じチケットによる `POST takes` 再送は、初回成功後には completed として拒否され、APIとして冪等になりません。

修正提案: completed 予約について、対応する Take が同じ `cut_id / client_take_id / video_path / size_bytes` で存在する場合のみ200で返す状態遷移を定義してください。released は拒否し、completed だが Take が存在しない不整合は409等で異常扱いにします。応答喪失後の同一チケット再送テストも追加してください。

## 3. 実現可能性

[Critical] presigned PUT URL は有効期限まで再利用できます。現在の設計では、HeadObject 照合と Take 登録の完了後に同じキーへ再PUTできるため、検証済みオブジェクトを同サイズ・同Content-Typeの別内容へ差し替えられます。HeadObjectによるサイズ・Content-Type照合だけでは、登録後のオブジェクト完全性を保証できません。

修正提案: 次のいずれかを設計へ追加してください。

- `x-amz-checksum-sha256` を署名条件・チケット・予約行に含め、PUTとHeadObjectの双方で照合する。
- S3 Versioningを使用し、検証した `VersionId` をTakeに固定する。
- stagingキーへアップロード後、検証対象バージョンをサーバ側で確定キーへコピーする。

v1では署名済みSHA-256チェックサム方式が比較的局所的です。登録後の再PUTテストまたは署名条件のArchitecture/Featureテストも必要です。

[Warning] 登録処理と期限切れcronの競合制御が未定義です。登録側がHeadObjectを確認している間にcronが予約をreleased化してオブジェクトを削除すると、不整合が発生します。

修正提案: `pending → verifying → completed/released` の状態遷移、または予約行のclaim/leaseを導入してください。外部S3呼び出し中にDBロックを保持し続けない設計とし、stale verifyingの回収規則も定義してください。

## 4. 期待効果の妥当性

[Suggestion] 「課金基盤の完成」から「保存容量の上限制御」へ修正され、主張は妥当になっています。運用上の残課題も適切です。

## 5. リスク

[Warning] downloaded ACKが任意のTakeに対して可能に見えます。詳細画面では全TakeのIDが取得できるため、非採用TakeをACKして永久に削除不能にできます。

修正提案: ACK時点で対象Takeが現在の `adopted_take_id` であることをサーバ側で検証し、非採用Takeは422または409にしてください。cross-cut 404に加えて「非採用Take ACK拒否」のFeatureテストを追加します。

## 6. スコープの適切さ

[Suggestion] 設計を一体に保ちつつ、実装をA〜Dへ分割した判断は妥当です。ただしBの完了条件には、completed再送と登録・cron競合の状態遷移テストも含める必要があります。

## 7. 型安全性

[Suggestion] D12によりPHPStan level 10を目指せる具体性があります。チケット復号直後の値は未検証の動的値なので、配列から直接DTOを生成せず、各フィールドの存在・型・範囲を検証する専用decoder/factoryを型境界として置くと安全です。

結論として、Round 1 の問題は解消されていますが、presigned URLによる登録後差し替えと冪等再送の矛盾は、詳細設計へ進む前に修正が必要です。