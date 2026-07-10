## 施策別判定

### 施策1: ルート追加 + IDOR inventory

**APPROVE**

認可前404、`scopeBindings`、current-org guard、deny-by-default inventory の組み合わせは適切です。

### 施策2: Scenario ドメイン型

**REQUEST_CHANGES**

- [Warning] `groupBy()` 後の型が骨子のままでは PHPStan level 10 で `mixed` に崩れる可能性があります。特に `get(0, collect())` のデフォルト値が型推論を汚染します。  
  **修正案:** `$grouped` に `Collection<int, Collection<int, Cut>>` 相当のPHPDocを付け、空コレクションも型を明示するか、`$grouped->get(0) ?? new Collection()` を型付き変数に受けてください。
- [Suggestion] PHP 8.3以降では型付きclass定数が正式対応しており、`public const string CODE` へのRound 1指摘は誤りでした。既存規約どおり維持する対応で問題ありません。

### 施策3: UpdateScenarioRequest

**APPROVE**

- [Suggestion] `prepareForValidation()` は、キー欠落を `''` で補完すると `present` を無効化します。実装時は `array_key_exists()` で「存在し、値がnullの場合だけ」正規化し、未知キーや保護キーを含む元配列を落とさないようにしてください。

### 施策4: ScenarioService::save()

**APPROVE**

成功保存ごとのversion増加は確定仕様とクライアント復旧戦略まで一貫しており、Round 1の懸念は解消されています。manual行を直列化点とする設計、異物IDの404、階層変更の422も妥当です。

- [Suggestion] `upsertCut()` のコメントに残る「null→`''`正規化」は施策3へ移動済みなので削除してください。責務の記述が再び分散して見えます。
- [Suggestion] 波及先の `ManualServiceBoundaryTest` 表記を、新設した `ScenarioServiceTest` に更新してください。

### 施策5: Controller

**APPROVE**

DTO/JsonResource、Inertia Props、認可前のURL整合確認、既存Policy再利用のいずれも整合しています。

### 施策6: フロントエンド

**REQUEST_CHANGES**

- [Warning] `fetch()` がネットワークエラーでrejectした場合、現在の `save()` はエラー表示を設定せず例外を外へ漏らします。オフラインや接続断で未処理Promiseとなります。  
  **修正案:** `try` 内に `catch` を追加し、作業コピーを保持したまま `genericError` を設定してください。419再取得用GETと再試行PUTのrejectも同じ経路で処理します。
- [Warning] 422処理の契約がコメントだけで、JSON破損や期待外shapeへの防御が未確定です。  
  **修正案:** Laravelの `{ errors: Record<string, string[]> }` を実行時に判別し、shape不一致なら汎用エラーへフォールバックする方針とVitestを追加してください。
- [Suggestion] `payloadSteps()` に新しい配列を毎回生成させ、snapshotも同じ正規形のcloneとして保持すれば、Svelteの参照共有によるdirty判定漏れを防げます。

### 施策7: テスト

**REQUEST_CHANGES**

- [Warning] 施策6で残る通信失敗経路が未網羅です。  
  **修正案:** Vitestに「PUTのreject」「419回復GETのreject」「422 body不正」の各ケースを追加し、作業コピー保持・汎用エラー表示・多重retryなしを検証してください。
- [Suggestion] Service直テストS1〜S5は適切で、境界防御の不足は解消されています。

### 施策8: ドキュメント

**APPROVE**

divergence、共有ロック規約、将来のArchitectureテスト昇格条件、`draft → ready` の確定仕様反映まで揃っています。

## 全体判定

**CHANGES_REQUESTED**

バックエンド設計とセキュリティ不変条件は承認可能な状態です。残る必須修正は、フロントのネットワーク例外・不正422レスポンス処理、および対応するVitestです。PHPStan向けの`groupBy()`型明示も実装前に設計へ反映してください。