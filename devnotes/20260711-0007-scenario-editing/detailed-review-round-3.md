**全体判定: CHANGES_REQUESTED**

- [Warning] `save()` 開始時に `genericError` をクリアしていません。通信失敗後の再保存が成功しても、以前のエラーが残る可能性があります。  
  **修正案:** `saving = true` の直後に `genericError = null` を追加し、再保存成功時に旧エラーが消えるVitestを追加してください。

- [Suggestion] 成功レスポンスも型アサーションだけでなく実行時検証すると堅牢です。  
  **修正案:** `ScenarioDocument` のtype guardを通してから `applySaved()` を呼び、JSON破損・shape不一致は汎用保存エラーへフォールバックしてください。

Round 2の必須指摘は適切に解消されています。残る必須修正は旧エラー状態のクリアのみです。