## 施策別判定

| 施策 | 判定 |
|---|---|
| 1. render_jobs / Model / enum / Factory | APPROVE |
| 2. config / queue / 時間不変条件 | APPROVE |
| 3. Conflict Exception / Resource | APPROVE |
| 4. RenderJobService | APPROVE |
| 5. RenderManifest DTO | APPROVE |
| 6. AssSubtitleWriter | REQUEST_CHANGES |
| 7. VideoComposer / Storage | APPROVE |
| 8. RenderPipeline / RunManualRender | APPROVE |
| 9. 出力削除 / reconcile | REQUEST_CHANGES |
| 10. routes / Controller / Request | APPROVE |
| 11. Policy | APPROVE |
| 12. DTO / Resource / TS型 | APPROVE |
| 13. Architectureテスト | APPROVE |
| 14. RenderPanel / Show props | APPROVE |
| 15. テスト一式 | REQUEST_CHANGES |
| 16. ドキュメント | APPROVE |

## 指摘

### [Critical] ASS改行の正規化順に論理矛盾が残っています

施策6では次の順序です。

1. CR/LFを `\N` に変換
2. リテラル `\N` のバックスラッシュを全角化

このままでは、手順1で生成した正規のASS改行まで手順2で `＼N` に変換され、改行が失われます。Round 1対応で追加された手順4〜6では解消されていません。

**修正案:** 入力由来のバックスラッシュ制御綴りを先に無効化してから、改行をASSの `\N` に変換してください。

```text
1. 入力中の \N / \n / \h を無効化
2. CRLF / CR / LF を正規化
3. 正規化した改行を \N に変換
4. {}・不可視文字・長さを処理
```

テストには「実改行とリテラル `\N` が同じ入力に共存するケース」を追加し、実改行だけがASS改行として残ることを固定してください。

### [Warning] S3削除をDBトランザクション内で実行しています

`DeleteRenderOutputsJob` は `render_jobs` 行をロックしたまま `$storage->delete()` を呼びます。S3遅延やタイムアウト中、行ロックとDB接続を長時間保持します。さらに、S3削除成功後にDB更新が失敗すると、`output_path` が実在しないオブジェクトを指し続けます。

**修正案:** 外部I/Oをトランザクション外へ分離してください。

1. 短いtxでjobをロックし、削除対象・最新世代・prefixを検証
2. tx終了後にS3を削除
3. 再度短いtxで、`output_path` が検証時の値と一致する場合だけNULL化

CAS条件が不一致なら再評価し、最新世代を誤ってNULL化しない設計にします。削除済み・DB更新失敗は再実行時に冪等に収束できます。

### [Warning] 上記2境界のテスト追加が必要です

施策15に以下を明記してください。

- ASS: 実改行とリテラル `\N` の共存、生成した改行が維持される
- 削除job: S3削除中にDBトランザクションを保持しない
- 削除job: S3削除後のCAS不一致・DB更新失敗から再実行で収束する

### [Suggestion] 「単一真実源」の表現

`docs/architecture.md` を正本としてdocblockへ同一文面を転記する構成は、厳密には単一真実源ではなく複製です。Architectureテストで経路表または順序記述の同期を検証するか、「正本＋参考転記」と表現すると実態に合います。

## Round 1 対応評価

Round 1の9項目は意図どおり反映されています。特に、例外種別の修正、単一ポーリングscheduler、filename境界、TS enum同期拡張は妥当です。新たな問題は上記のASS処理順と外部I/O中のDBロックです。

## 全体判定

**CHANGES_REQUESTED**

ASS正規化順の修正は必須です。S3削除のトランザクション分離と対応テストまで反映後、`APPROVED` 相当です。