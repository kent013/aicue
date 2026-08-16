## 各施策判定

| 施策 | 判定 |
|---|---|
| S1 | APPROVE |
| S2 | APPROVE |
| S3 | REQUEST_CHANGES |
| S4 | APPROVE |
| S5 | APPROVE |
| S6 | APPROVE |
| S7 | APPROVE |
| S8 | APPROVE |
| S9 | APPROVE |
| S10 | REQUEST_CHANGES |
| S11 | REQUEST_CHANGES |

## 指摘事項

### [Warning] S3: 再試行時に1回目の出力ファイルが残り、空出力を成功と誤判定しうる

`-y` は既存ファイルへの上書きを許可するだけで、ffmpeg 実行前に出力を削除する保証ではありません。

次の順序では誤判定が成立します。

1. 1回目が非ゼロ終了だが、非空の `thumbnail.jpg` を残す
2. seek=0 の2回目が終了コード0だが、新しいフレームを出力しない
3. `filesize($destination)` が1回目の残骸を検査して成功扱いになる

修正案: 各 `attempt()` の実行直前に出力先を削除し、削除に失敗した場合も抽出失敗として扱ってください。

```php
if (is_file($destination) && ! unlink($destination)) {
    return 'failed to remove stale thumbnail output';
}
```

Laravel側へ寄せるなら `File::delete($destination)` 後に `is_file()` で削除結果を確認します。テスト計画にも「1回目が残骸を作り、2回目が新規出力を作らない場合は例外」を追加する必要があります。

### [Warning] S10: `resumeUploads()` で登録されたテイクが監視集合に追加される設計が明示されていない

現行コードは `queue.resume()` の結果を `some()` で集約しています。

```ts
if (outcomes.some((outcome) => outcome.status === "uploaded")) {
    reloadManual();
}
```

このまま再取得だけを Promise 化しても、オフラインキューから登録されたテイクの `clientTakeId` は `watch()` されません。その結果、最初のreload時点でサムネイルが未生成なら、それ以降は自動反映されません。

修正案: uploaded outcomeをすべて監視へ追加してから、reloadを1回だけ実行してください。

```ts
const uploaded = outcomes.filter(
    (outcome): outcome is Extract<UploadOutcome, { status: "uploaded" }> =>
        outcome.status === "uploaded",
);

for (const outcome of uploaded) {
    thumbnails.watch(outcome.clientTakeId);
}

if (uploaded.length > 0) {
    void reloadManual();
}
```

テストには、複数件のresume結果について以下を追加してください。

- uploadedだけがすべて監視される
- queued / quota_exceededは監視されない
- reloadはuploaded件数によらず1回だけ
- resume直後のreloadで未生成でも、その後の有界再取得で反映される

### [Warning] S11: 「最後に登録したテイク」は実装の保証単位と一致しない

スケジューラが扱うのは「最後に`watch()`へ新規追加されたID」です。重複IDの`watch()`は早期returnし、`queue.resume()`では登録完了順と配列順が必ずしも同じ意味になりません。

修正案: ドキュメントを実装語彙に合わせてください。

> 最後に監視集合へ追加されたテイクを起点に最大4回・約29秒

また、S10の修正によりresumeで複数件を連続追加した場合、最後に追加されたIDを起点として集合全体の予算が更新されることも明記すると正確です。

## Round 1 対応の評価

Round 1の8件は適切に解消されています。特に以下は設計とテストの契約が一致しました。

- S4の競合を「preflight前」と「preflight後」に分離
- S4のキー生成をpreflight前へ移動し、PUT直前区間から遅延ロードを排除
- S5の同一DB接続・`after_commit=false`前提とrollback検証を明文化
- S8の公開述語をendpointと一致
- S10でin-flight Promiseを共有
- 自動反映の有界性を集合全体の予算として正確に限定

S4について、後着PUTによる実体上書きとDB記録サイズの不一致は設計上明示的に非保証とされており、v1のサムネイル用途として受容可能です。

## 全体判定

**CHANGES_REQUESTED**

ブロッカーは、S3の再試行時の残骸誤認と、S10のオフラインキュー再開経路における監視漏れです。いずれも局所修正で、パイプラインや認可境界など全体方針の変更は不要です。