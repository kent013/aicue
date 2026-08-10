# 対応マトリクス: design-review Round 2

## 施策 5
- [Critical] `running` timeout の分類 case 欠落 → **対応する** (設計内の不整合。指摘どおり)。
  `SmokeFailureClass::StageTimeout` を追加し、判定順を確定した:
  Preflight → (timedOut ∧ queued)=Wiring → (timedOut ∧ running)=StageTimeout →
  Render → Storage → Llm → Unknown。
- [Warning] smoke JSON の契約 → **対応する**。`SmokeRunResultData` / `SmokeStageResultData` にも
  array shape 付き `toArray()` を実装し、`json_encode($result->toArray(), ...)` の 1 経路に固定。
  コスト部は `LlmCostReportData::toArray()` を埋め込み二重定義しない。
- [Warning] 予約行の tenant-safe な再解決 → **対応する**。
  `$cut->uploadReservations()->where('client_take_id', $clientTakeId)->latest('id')->firstOrFail()` に固定。
  クラス起点の主キー同一性クエリを書かない (`ModelDirectFetchInvariantTest` の deny-by-default に
  触れる形を作らない)、presigned URL を parse しない、payload から tenant キーを復元しない、を明記。

## 施策 8
- [Warning] docs に `StageTimeout` を反映 → **対応する**。失敗分類の語彙を docs 追記対象に明記。

## 施策 9
- [Warning] 分類関数の配置と可視性 → **対応する** (private では Pest から呼べないという指摘は正しい)。
  `App\Support\Smoke\SmokeFailureClassifier` として **public static な純関数**へ切り出す
  (`app/Support/Billing/GatewayFailureClassifier.php` と同じ配置・同じ流儀)。
  `tests/Unit/Support/Smoke/SmokeFailureClassifierTest.php` で判定表 9 行を機械固定
  (「リトライ failure 行があっても最終成功なら分類しない」の負のコントロールを含む)。
  施策一覧の変更ファイルにも追加した。
