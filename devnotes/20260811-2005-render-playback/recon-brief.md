# 実査ブリーフ: 完成動画をアプリ内で観られるようにする

> 要件と実装の突き合わせ (2026-08-10 実査) で **中核体験の最後の一歩が片道で切れている**と判定した項目。
> 統合評価: `devnotes/` の requirements-gap 調査 (biggest_gaps の 1 番目)。

## 実コードで確認した事実

`app/Http/Controllers/Projects/ManualRenderController.php` L106-110:

```php
if ($renderJob->kind !== RenderKind::Preview
    || …
    || ! $this->isLatestSucceededPreview($manual, $renderJob)) {
    abort(404);
}
```

**`kind=Render` (完成動画) の playback は 404 になる。** アプリ内で観られるのは
プレビューだけで、**完成動画の受け取り口は `projects.manuals.download` の 1 本しかない**。

route も実在を確認済み:
- `GET projects/{project}/manuals/{manual}/render-jobs/{renderJob}/playback` (= プレビュー専用)
- `GET projects/{project}/manuals/{manual}/download` (= 完成動画の唯一の受け取り)

## 阻害されているユーザージョブ

**撮った人が結果に到達する**。制作フロー (doc/02 §2.2) の第 7 段は「プレビュー / DL」だが、
**完成物の視聴が欠けている**。ダウンロードして外部プレイヤーで開くしかない。

これは「思考ゼロ・編集ゼロ」を掲げる使命 (AGENTS.md North Star) に対して、
**最後に一番手間のかかる操作を要求している**ことになる。

## 設計で決めるべきこと

1. **どの render job を再生可能にするか**。既存は
   `isLatestSucceededPreview()` で「最新の成功したプレビュー」に限っている。
   完成動画も同じ考え方 (最新の成功した Render) でよいか。
   **published なマニュアルの現行版と、過去バージョンの扱い**を決めること
   (`scenario_version` のスナップショットが固定されている点に注意)。
2. **既存の 404 の意図を壊さないこと**。現在 404 にしているのは**存在秘匿**の意味もありうる。
   実コードとテストを読み、**何を守るための 404 か**を確認してから緩めること。
   他組織・他プロジェクトの job が見えてはならない (AGENTS.md セキュリティ不変条件 2/3)。
3. **UI の導線**。`RenderPanel.svelte` に完成動画の再生を足すのか、別の場所か。
   aicue:T148 で `playbackJobId` → `playbackJob: RenderJobProps` に変わっており、
   **動画 URL と注記を同一オブジェクトから出す**形になっている。この形に乗せられるか。
4. **撮影 PWA からの戻り導線**。統合評価は
   「撮影 PWA (`Capture/Show` のヘッダーは『一覧へ戻る』だけ) から PC 側マニュアル詳細への戻り導線が無い」
   とも指摘している。**本 TODO に含めるかは設計者が判断**してよい
   (含めるなら往復が閉じる。含めないなら別 TODO として明記する)。
5. **T148 の告知契約との整合**。完成動画には `placeholder_cut_count` が記録されている
   (aicue:T148)。完成動画を再生できるようにするなら、**プレビューと同じく注記を出すか**を決める。
   なお完成動画は未撮影があると 422 でブロックされるので `placeholder_cut_count=0` のはずである
   (値契約は aicue:T148 の設計を参照)。

## 読むべき現行コード

- `app/Http/Controllers/Projects/ManualRenderController.php` (L84-130 付近。playback と 404 の条件)
- `app/Http/Controllers/Projects/VideoManualController.php` (L100-130 付近。props の組み立て)
- `resources/js/components/features/manual/RenderPanel.svelte`
- `app/Enums/Manual/RenderKind.php` / `app/Models/RenderJob.php`
- `app/Services/Manual/RenderPipeline.php` (成果物の保存先と署名 URL)
- `tests/Feature/Manual/` の playback / download 関連テスト
- aicue:T148 の設計 (`devnotes/20260811-0146-preview-render-parity/detailed-design.md`)

## やらないこと

- **ダウンロード経路を消さない** (mp4 を手元に落とす需要は別にある)。
- **認可・テナント境界を緩めない**。他組織の job が見えるようにしてはならない。
- 多言語 (`?lang=`) の扱いは本 TODO では触らない (v1 の扱いが未確定)。
