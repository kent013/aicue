## 施策別判定

### 施策 1: APPROVE

Round 1 の指摘は解消されています。DB 名判定の SSOT、seeder からの委譲、判定表テストとも妥当です。

### 施策 2: APPROVE

半開区間、日付のみの解釈、UTC 日次集計、null コストの分離が明確になりました。DTO で未加工値を保持し、CLI 表示時だけ桁を整える方針も妥当です。

### 施策 3: APPROVE

入力エラーを `INVALID` に統一し、JSON shape を `toArray()` で固定したことで、CLI の契約が十分明確になっています。

### 施策 4: APPROVE

fixture の要件と behavioral test の方針に問題はありません。

### 施策 5: REQUEST_CHANGES

[Critical] `running` 状態でタイムアウトした場合の失敗分類が定義されていません。

worker 待ちでは「`running` なら `stage-timeout`」としていますが、`SmokeFailureClass` に対応する case がありません。さらに `Unknown` は「写像表に一致しなかった場合」であり、既知の `running` timeout を入れることはできません。

修正案: `SmokeFailureClass::StageTimeout` を追加し、次を判定表とテストに登録してください。

- `queued` のまま上限到達 → `Wiring`
- `running` のまま上限到達 → `StageTimeout`

[Warning] `SmokeRunResultData` の JSON 契約が固定されていません。

コスト DTO には `toArray()` が追加されましたが、smoke 側は依然として「`SmokeRunResultData` を `json_encode`」とだけ記載されています。Carbon、enum、子 DTO を含む場合、public property の構造がそのまま外部契約になり、リファクタリングで shape が変わります。

修正案: `SmokeRunResultData` と `SmokeStageResultData` に PHPStan shape 付きの `toArray()` を定義し、次の一経路に固定してください。

```php
json_encode(
    $result->toArray(),
    JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE,
);
```

[Warning] `TakeUploadReservation` の再解決条件を明記する必要があります。

「予約行から `video_path` を読む」だけでは、クラス起点の ID 検索や別組織の予約を誤取得する実装余地が残ります。

修正案: `organization → project → manual → cut` の確認済み relation と、サーバ側で保持した `clientTakeId` を使って予約を再解決することを明記してください。payload や presigned URL から tenant キーを復元しない条件も固定します。

### 施策 6: APPROVE

遅延解決が allowlist 登録の実装条件として明文化されました。例外範囲も必要最小限です。

### 施策 7: APPROVE

`--shard` / `--run-id` の非転送、artisan option の allowlist、既存モードフラグ制約との接続が整理されています。Round 1 のブロッカーは解消されています。

### 施策 8: REQUEST_CHANGES

[Warning] 施策 5 の timeout 分類を確定後、ドキュメントにも `StageTimeout` を反映する必要があります。

修正案: 段の合否・診断分類に「worker は起動してジョブを取得したが、処理が上限内に完了しなかった状態」を追加してください。

### 施策 9: REQUEST_CHANGES

[Warning] 「分類関数を直接呼ぶ単体テスト」の配置と可視性が未確定です。

変更ファイル一覧には対応する単体テストがなく、本文では private メソッドを含む設計になっています。private 分類関数は通常の Pest テストから直接呼べません。

修正案は次のいずれかです。

- 分類を独立した小さな classifier クラスへ切り出し、Unit テストを追加する。
- コマンドの observable な出力と終了結果を通す Feature テストとして固定する。

少なくとも以下の判定表を機械的に固定してください。

- queued timeout → `Wiring`
- running timeout → `StageTimeout`
- failed + LLM failure row → `Llm`
- render error code → `Render`
- output object 不在 → `Storage`
- 既知条件に該当しない失敗 → `Unknown`
- retry failure row があっても最終成功 → failure class なし

## 全体判定

**CHANGES_REQUESTED**

Round 1 のブロッカー4点はすべて適切に解消されています。残る主要な修正は、`running` timeout に対応する enum がないという設計内不整合です。併せて smoke JSON の `toArray()` 契約、予約行の tenant-safe な再解決、分類テストの実装可能な配置を確定すれば承認可能です。