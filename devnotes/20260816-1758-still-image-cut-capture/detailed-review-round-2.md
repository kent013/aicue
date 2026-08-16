## Round 2 レビュー

反論された2件は、提示された現行コードの根拠から妥当です。`starting` は取得を阻害せず、`adoptedTake` に列制限もありません。

### S1: APPROVE

Round 1 の型推論リスクは解消されています。`default => throw` により PHPStan の stub に依存しない構造になっています。

### S2: APPROVE

Content-Type、容量上限、サーバ確定値、Quota 経路の扱いに問題はありません。

### S3: APPROVE

`downloadSources()` の改名追随と拡張子なしローカルパスへの変更を確認しました。尺ゲートとレンダの判定も同じサービスへ集約されています。

### S4: APPROVE

字句 inventory と `Process::fake()` による実引数検査の役割分担は適切です。

### S5: APPROVE

Promise rejection は二重に閉じられています。`starting` に関する反論も、既存 `startRecording()` と同一順序であるという根拠から成立します。

### S6: APPROVE

実体検証を追加しない方針と、画像表示失敗時の UI fallback が整合しています。

実装時は `imageFailed` のリセットを「`{#key}` の内側に置く」という表現ではなく、`take.id` または `playbackUrl` の変更を監視して明示的に `false` に戻す実装にしてください。Svelte の script state 自体は `{#key}` によって再生成されないためです。テスト計画に切替時のリセットが含まれているので、判定を下げる指摘ではありません。

### S7: APPROVE

列制限が存在しないという反論を受理します。DTO、TypeScript 型、Inertia props の波及も揃っています。

### S8: REQUEST_CHANGES

[Warning] 「孤児の後始末」テストの失敗地点と期待する後始末経路が一致していません。

今回の題材である ffprobe の非数値出力は `compose()` 中に失敗します。この時点では `RenderPipeline` の `storage->upload()` はまだ呼ばれておらず、`$uploadedKey` も `null` です。そのため、このテストでは「S3へ部分アップロードされた成果物の削除」を検証できません。

また、現行コードは次の順序です。

```php
$this->storage->upload(...);
$uploadedKey = $manifest->outputKey;
```

`upload()` 自体が途中で例外を投げた場合も `$uploadedKey` はまだ `null` なので、`finally` の削除対象にはなりません。「部分アップロードを必ず消す」という保証も現行構造からは導けません。

修正案:

- ffprobe失敗テストでは、`upload()` が呼ばれないことと `output_path === null` を固定する。
- 孤児削除は別テストに分け、`upload()` 成功後に `finalize()` が失敗する状況を作り、アップロード済みキーが `finally` で削除されることを固定する。
- `upload()` が途中失敗した場合の部分オブジェクトは、ストレージ実装が原子的に処理する保証があるならその契約を明記する。保証がないなら「部分アップロードも既存経路で削除する」という記述を削除し、未軽減リスクとして扱う。

## 全体判定

**CHANGES_REQUESTED**

残件は S8 のテスト期待と実際の制御フローの不一致1件です。実装本体の設計を変える必要はなく、失敗地点ごとにテスト契約を分離すれば承認可能です。