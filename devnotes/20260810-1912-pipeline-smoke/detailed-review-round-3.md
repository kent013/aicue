## 施策別判定

### 施策 1: APPROVE

変更ありません。設計・テスト計画とも妥当です。

### 施策 2: APPROVE

変更ありません。期間境界、UTC、null コスト、DTO 契約が明確です。

### 施策 3: APPROVE

変更ありません。入力検証、終了コード、JSON shape が十分に固定されています。

### 施策 4: APPROVE

変更ありません。fixture と behavioral test の設計に問題はありません。

### 施策 5: REQUEST_CHANGES

Round 2 の直接指摘だった `StageTimeout`、JSON 契約、tenant-safe な予約再解決は適切に解消されています。ただし、追加された分類器に2つの判定不整合があります。

[Critical] LLM 判定が LLM 以外の段にも適用されます。

現在の6番目の条件は stage を限定していません。

```php
$hasLlmFailureRow || ! $hasLlmSuccessRow
```

このため、次のような誤分類が発生します。

- `fixture` や `capture` が失敗し、まだ成功行がない → `Llm`
- LLM が一度失敗してリトライ成功後、`capture` が失敗 → `Llm`
- render が `error_code=null` で失敗し、過去に retry failure 行がある → `Llm`

修正案: `Llm` 判定を LLM が原因になり得る段に限定してください。例えば以下です。

```php
if (
    in_array($stage, [SmokeStage::Analysis, SmokeStage::LlmEvidence], true)
    && ($hasLlmFailureRow || ! $hasLlmSuccessRow)
) {
    return SmokeFailureClass::Llm;
}
```

実際に `AnalysisPipeline` の失敗を観測する stage 名に合わせ、対象集合を明示してください。

[Critical] ffprobe の非0終了を `Render` に分類できません。

失敗分類表では「ffprobe が非0終了 → `Render`」ですが、分類器の引数に ffprobe の成否がありません。出力が読み出せる状態で ffprobe だけ失敗した場合、現在の判定では `Unknown` になります。

修正案: `bool $ffprobeFailed` などの観測値を追加し、次の判定を入れてください。

```php
$stage === SmokeStage::Artifact && $ffprobeFailed
    => SmokeFailureClass::Render
```

`outputReadable=false` は `Storage`、読み出し成功後の ffprobe failure は `Render` と境界を固定します。

[Warning] 「成功した段は分類しない」という負のコントロールを、現在の classifier 単体テストでは証明できません。

`classify()` は非nullableな `SmokeFailureClass` を必ず返すため、「呼び出さない」という呼び出し側の契約は classifier テストの入力では表現できません。

修正案は次のいずれかです。

- `stageSucceeded` を入力し、戻り値を `?SmokeFailureClass` にする。
- classifier は失敗専用のままとし、成功時に呼び出さない orchestration を別のテスト可能な関数で固定する。
- 負のコントロールをコマンドの Feature テストで検証する。

### 施策 6: APPROVE

fake 参照の範囲と遅延解決条件は妥当です。

### 施策 7: APPROVE

option の消費・転送境界と orchestrator gate が明確です。

### 施策 8: APPROVE

`StageTimeout` を含む失敗語彙が反映されています。施策5の修正後も、`Llm` と `Render` の意味を現在の分類表どおり維持してください。

### 施策 9: REQUEST_CHANGES

[Warning] 分類器テストに以下の回帰ケースを追加する必要があります。

修正案:

- fixture/capture failure + LLM 成功行なし → `Unknown`
- LLM retry failure 行あり + capture failure → `Unknown`
- artifact + output readable + ffprobe failure → `Render`
- artifact + output unreadable → `Storage`
- LLM retry 後の段成功 → failure class なし。これは呼び出し側のテストとして配置する

## 全体判定

**CHANGES_REQUESTED**

Round 2 の指摘事項そのものはすべて反映されています。残るブロッカーは、分類器の `Llm` 条件が全段へ漏れていることと、ffprobe failure を `Render` に写像できないことです。この2点と成功時非分類のテスト境界を修正すれば、設計は承認可能です。