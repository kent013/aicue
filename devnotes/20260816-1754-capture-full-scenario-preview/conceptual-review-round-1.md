全体判定: **CHANGES_REQUESTED**

大筋の方向性は North Star に合っています。撮影者が現場で「撮り漏れ」「採用漏れ」「流れ」を確認できる導線は、AI-CUE の撮影ハードル低減に本質的に効きます。ただし、設計上の主張を少し締めないと、実装時に判定式の二重化・費用説明の過大表現・再生失敗時の UX で崩れます。

**1. 使命との整合性**
[Suggestion] 案 B の採用は妥当です。完成品質ではなく「撮り終わったか」の判断を撮影者に返す設計なので、現場再訪の削減に直結します。

[Warning] 「撮影者がもう帰ってよいかを判断できる」と言い切るのは少し強いです。通し再生は完成レンダ可能性を保証しない、と後段で書いているため、UI 文言も「この場で確認すべき不足を見つける」程度に寄せるべきです。  
修正提案: 期待効果の表現を「帰ってよい判断」から「採用済み ready テイクの不足と流れの確認」に弱める。

**2. 禁止事項違反**
[Warning] 必須条件未充足時にボタンを disabled にしない方針は正しいですが、`captureActive` 中は「押下してエラー」と書いている一方で「開かずに」とも書いています。禁止事項 8 との整合はありますが、UI の責務を明確にすべきです。  
修正提案: ボタンは常に押せる。押下時に `captureActive` ならインライン/トーストでエラー表示し、ダイアログは開かない、と明記する。

[Suggestion] `response()->json()` を使わず Inertia props で済ませる方針は適合しています。

**3. 実現可能性**
[Warning] `adopted_ready_take_id` をどう算出するかが曖昧です。正本が `AdoptedReadyTakeCoverage::isMissing(Cut)` だけだと、boolean は得られても「ready な採用テイク ID」は得られません。ここで DTO 側が `adopted_take_id && status === Ready` を再実装すると、設計が避けたい判定式の二重化になります。  
修正提案: 正本サービスに `readyTakeId(Cut): ?int` などを追加し、`isMissing()` もそれを使う形にする。判定ロジックの実体は 1 箇所に集約する。

[Warning] props 生成時点では ready でも、再生時にテイク状態やファイル状態が変わり `capture.takes.playback` が 404 になる可能性があります。  
修正提案: ダイアログ側で 404/再生エラーを「このカットは現在再生できません」としてプレースホルダ扱いにし、通し再生全体は停止させない。

**4. 期待効果の妥当性**
[Warning] 「追加費用ゼロ」は過大です。ffmpeg worker と成果物保存は増えませんが、S3 署名 URL 発行、S3/ストレージ egress、モバイル通信量、既存 playback endpoint へのアクセスは増えます。  
修正提案: 「追加のレンダ費用・成果物保存費用は発生しない。ただし再生通信量は増える」と書き換える。

[Suggestion] 編集者の preview 枠を消費しない、という効果は合理的です。案 A を避ける理由として強いです。

**5. リスク**
[Warning] 通し再生が既存の個別再生 URL を連続で叩くため、短時間に多数の 302/S3 取得が走ります。大量カットのマニュアルでは端末負荷・通信量・署名 URL 発行回数が増えます。  
修正提案: 初期実装ではプリロードを控えめにし、現在クリップ + 次クリップ程度に限定する。長いシナリオでは件数と通信注意を表示する。

[Warning] 「新たに露出する情報は 1 バイトも無い」は厳密には強すぎます。個別に見える情報を一括で見やすくするため、情報量ではなくアクセス容易性は上がります。  
修正提案: 「権限境界は広げない。既に同一画面で個別再生可能なテイクに限る」と表現する。

**6. スコープの適切さ**
[Suggestion] サーバ生成 preview、採用操作、並べ替え、IndexedDB 保存を外しているのは適切です。v1 として小さく閉じています。

[Warning] docs 更新に `doc/05` と `docs/architecture.md` はありますが、権限表や T154 に「変更しない」ことの確認観点が必要です。  
修正提案: 実装タスクに「`render` / `download` ability と PC preview 権限が変わっていないことをテストまたは既存テストで確認」を追加する。

**7. 型安全性**
[Warning] `CaptureCutData::toArray()` の array shape、`resources/js/types/capture.ts`、Feature test の props 完全一致を更新する方針は正しいですが、`previewPlaceholderSeconds` の型と単位も固定すべきです。  
修正提案: Inertia props 側は `preview_placeholder_seconds: int` のように snake_case で既存流儀に合わせ、PHP array shape と TS 型で `number`、Feature test でキーと値型を固定する。

[Suggestion] `adopted_ready_take_id` は nullable int で妥当です。TS 側で ready 判定を再構築しない制約もよいです。