全体判定: **CHANGES_REQUESTED**

Round 1 の Critical は概ね解消されています。ただし、「実体優先」の導入に伴い、尺ゲート側にも同じ実効素材種別の判定を適用する設計が不足しています。ここはレンダ結果と事前ゲートが再び不一致になるため、残存 Critical です。

## 1. 使命との整合性

[Suggestion] `cuts.material_type` を撮影指示、`takes.material_type` を登録実体として分離した設計は使命に整合しています。現場判断による切り替えをスコープ外にした点も、「撮影判断を肩代わりする」という North Star に沿っています。

## 2. 禁止事項違反

[Warning] `takes.material_type` は「サーバ確定値」とされていますが、確定元の予約行 `content_type` 自体はクライアント申告です。保存オブジェクトが申告と異なる形式でも、`material_type` が誤分類される可能性があります。

修正提案: finalize の検証工程で、オブジェクトストレージから得たメタデータだけでなく、実体を ffprobe 等で検査して「画像としてデコード可能／動画としてデコード可能」を確認してください。最低限、次をテストで固定します。

- `video/mp4` と申告して JPEG をアップロードした場合は登録失敗
- `image/jpeg` と申告して動画をアップロードした場合は登録失敗
- 失敗時は予約を既存の release 経路へ遷移させる
- payload の `material_type` では判定結果を上書きできない

これは新しい状態や経路を作る要求ではなく、既存 verifying 工程での実体検証強化です。

## 3. 実現可能性

[Critical] 「実体優先」の判定が `RenderPipeline` にだけ定義され、尺ゲートとの共有方法が未確定です。

C5 の `cut=video / take=still` では、レンダは `TakeStill` になります。一方、`StillDisplayDuration::secondsFor(Cut)` を呼ぶ条件が従来どおり `cut.material_type === Still` なら、尺ゲートは画像テイクの `duration_ms ?? 60_000` に落ちる可能性があります。つまり、今回解消しようとしている「ゲートとレンダの二重管理」が別の形で残ります。

修正提案: 次の実効判定を一つのドメインサービスまたは値オブジェクトへ集約してください。

```text
effective source =
  cut.material_type == still || take.material_type == still
    ? TakeStill
    : TakeVideo
```

`RenderPipeline` と `RenderJobService` は双方この判定結果を使い、`TakeStill` の場合だけ `StillDisplayDuration` を使います。C5 についても、マニフェストだけでなく「尺ゲートが既定5秒または指定秒で計算する」ことをテスト対象に含める必要があります。

[Warning] `capture.still_max_edge` と `capture.still_jpeg_quality` は PHP の設定値であり、Svelte から直接参照できません。現状の記述では、フロントへ値を渡す経路が欠けています。

修正提案: 撮影ページ用 DTO に型付き設定を追加し、Inertia props として渡してください。例えば、長辺は `int`、quality は JSON 上の `float` とし、TypeScript 側も `number` で受けます。設定値を各コンポーネントへ直接重複記述しないこともテストまたは構造で固定してください。

## 4. 期待効果の妥当性

[Suggestion] 効果を撮影負荷、アップロード時間、保存容量に限定し、レンダ時間短縮を撤回したため妥当です。

## 5. リスク

[Warning] config 異常値を `1〜60` にクランプすると、設定ミスを黙って別の値へ変換します。config テストが固定するのはリポジトリ上の既定値であり、環境変数等で上書き可能なら本番の誤設定までは検出しません。

修正提案: 外部設定可能なら起動時またはサービス解決時に範囲外を例外として fail-fast させてください。外部設定しない固定値なら、config に置く必要性を再確認し、少なくともクランプで異常を隠さない設計にします。

[Warning] ファイル選択画像について「`<img>` デコードで EXIF 向きが適用される」と一律に保証する記述は強すぎます。ブラウザ差やデコード API による差を設計上の保証にしない方が安全です。

修正提案: 対象ブラウザで向き付き JPEG のテスト fixture を使い、出力 JPEG の縦横と画素方向を確認してください。対応保証を iOS Safari など明示した対象環境に限定し、失敗時はアップロードエラーとして利用者へ表示します。

## 6. スコープの適切さ

[Suggestion] 同一ブランチ内でサーバ、PWA、表示の順に積み、通しテストまで完了扱いにしない方針は適切です。

## 7. 型安全性

[Warning] DTO の nullable 境界は明確になりましたが、`ScenarioStepData.materialType` だけ `?string` のまま残っています。同じ `cuts.material_type` を表す既存 DTO が広い型のままだと、今回追加する型付き境界と不整合です。

修正提案: 既存パターンが許すなら `MaterialType|null` に狭め、JSON 化時だけ `?->value` にしてください。互換上変更できない事情がある場合は、許容値を生成時に検証し、任意文字列がフロントへ出ないことをテストで固定してください。PHPStan エラー回避のために `string` を維持する、という判断にはしないでください。

Critical の解消条件は、**実効素材種別の判定をレンダ生成と尺ゲートで共有し、C5 の尺計算まで固定すること**です。加えて、Content-Type 申告とアップロード実体の一致検証を設計へ入れる必要があります。