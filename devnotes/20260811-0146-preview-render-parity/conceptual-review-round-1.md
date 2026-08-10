全体判定: **CHANGES_REQUESTED**

設計の方向性は North Star と finding によく合っています。特に「preview は止めずに知らせる」「render と preview の判定基準だけ揃える」「事後表示は現在状態で再計算せず生成物に紐づける」は妥当です。ただし、判定単一化の設計がまだ少し弱く、このままだと再び判定が分岐する余地があります。

## 1. 使命との整合性

[Suggestion]  
プレビューを 422 で塞がず、途中確認の体験を残す判断は AI-CUE の「思考ゼロ・編集ゼロ」に合っています。黒画面生成を「壊れた」と誤解させないための事前・事後説明も、中核体験の信頼回復に直結します。

## 2. 禁止事項違反

[Warning]  
禁止事項 8 との整合を根拠にしている点はよいですが、「押下時の確認ダイアログは足さない」は UI 方針として明文化しておくべきです。今後の詳細設計で「未撮影がある場合はボタン disabled」へ戻ると禁止事項に抵触します。

修正提案:  
詳細設計に「プレビューボタンは disabled にしない。未撮影時は warning 表示のみ」と明記してください。

## 3. 実現可能性

[Warning]  
`TakeCoverageData` の出力が `totalCuts` / `missingLabels` / `missingCount` だけだと、`RenderPipeline::clipSpecFor()` の Placeholder 分岐を同じ判定へ完全委譲できません。manifest 側ではカット単位で「この cut は placeholder か」を判断する必要があるため、結局 `adoptedTake === null || status !== Ready` を再実装する危険があります。

修正提案:  
`TakeCoverageData` に `missingCutIds` を持たせる、または `AdoptedTakeCoverage` に `isMissing(Cut $cut): bool` / `coverageByCutId()` のようなカット単位 API を持たせてください。manifest の placeholder 判定もそこだけを参照する形にするべきです。

## 4. 期待効果の妥当性

[Suggestion]  
期待効果は合理的です。特に 66 カット x 3 秒が観測された 201 秒と整合しており、原因仮説は十分強いです。

[Warning]  
「押す前に未撮影件数が出る」だけでは、別タブ・別ユーザー更新で古い可能性があります。スコープ外として書かれていますが、テスト上の成功条件に「事前表示の件数」と「実際の placeholder 件数」が常に一致するように読める箇所があります。

修正提案:  
成功条件を分けてください。  
事前表示は「ページ描画時点の coverage」。事後表示は「生成された manifest に含まれた placeholder 数」。両者の一致は同一操作直後の通常ケースでのみ期待し、常時保証しない、と明記すると安全です。

## 5. リスク

[Warning]  
`render_jobs.placeholder_cut_count` を nullable 追加する方針は妥当ですが、既存ジョブ・失敗ジョブ・running ジョブ・render ジョブでの値の意味が曖昧です。

修正提案:  
値の契約を明記してください。例:

- historical job: `null`
- running job: `null`
- failed before manifest/finalize: `null`
- completed preview: 実際に含んだ placeholder 数
- completed render: `0`

これを `RenderJobData` と TS `RenderJobProps` の nullable 型に反映してください。

[Warning]  
finalize で記録する方針はロック順序上よいですが、finalize 時に現在の manual 状態から再計算すると設計の意図に反します。

修正提案:  
`RenderManifest` に `placeholderCutCount` を持たせ、finalize は manifest 由来の値を書くだけにしてください。これは設計本文に近いですが、詳細設計で必須条件として固定するべきです。

## 6. スコープの適切さ

[Suggestion]  
スコープは概ね適切です。黒背景プレースホルダ自体を消さない、テロップ焼き込みを見送る、撮影待ちカウント統合を別 TODO にする判断は過剰実装を避けています。

[Warning]  
Architecture gate の検出対象が広すぎると、撮影ナビやダッシュボードなど「ready 状態を見ない別基準」まで巻き込んでノイズが増えます。

修正提案:  
gate は「render/preview の採用済み ready 判定」を対象に限定し、別基準の経路は inventory に理由付きで登録してください。名前も `AdoptedReadyTakeCoverage` のようにすると、単なる adopted 有無カウントと混同しにくくなります。

## 7. 型安全性

[Warning]  
DTO 方針はよいですが、`TakeCoverageData` の配列要素型と `RenderJobData` の追加フィールド契約を明示しないと PHPStan level 10 で弱くなりやすいです。

修正提案:  
`missingLabels` は `list<string>`、`missingCutIds` を持つなら `list<int>` または `list<string>` として明示してください。`placeholder_cut_count` は DB nullable に合わせて PHP/TS とも `?int` / `number | null` にし、Resource で暗黙 mixed を返さないようにしてください。

結論として、設計思想は承認可能な水準ですが、**カット単位の判定 API が不足している点**と、**placeholder_count の値契約**を直してから詳細設計へ進むのがよいです。