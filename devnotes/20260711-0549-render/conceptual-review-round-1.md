全体判定: **CHANGES_REQUESTED**

**1. 使命との整合性**
- [Suggestion] `render` を「SOP→シナリオ→撮影→完成 mp4」の終点として置く方向性は、North Star にかなり素直です。特に「字幕焼き込み」「採用漏れは完成 render で 422」「preview はチケット非消費」は、`編集ゼロ` と `標準化された成果物` の両立に寄与します。
- [Warning] `queued` 時点で `manual.status=rendering` に倒す設計は、ワーカー滞留時に編集を長時間止め、現場の反復速度を落とします。使命に対しては正方向ですが、運用劣化時に「何もできない時間」を作りやすいです。  
  修正提案: `queued` 専用の早期異常回復機構を別建てにしてください。少なくとも `queued` と `running` を同じ stale 扱いにせず、`queued` は短い SLA で fail/cancel できる仕組みを入れるべきです。

**2. 禁止事項違反**
- [Suggestion] 文面上、`response()->json()` 直書き回避、Prism 直呼びなし、disabled ボタン回避、共通抽象化しすぎない方針は守れています。
- [Warning] `DeleteTakeObjectsJob` を render/preview 出力の掃除に流用する案は、「別物の概念を似ているからで統合しない」に触れやすいです。名前から見て take 専用責務を含む可能性があります。  
  修正提案: render 出力削除は専用 job に切り出すか、少なくとも「キー配列を削除するだけ」で take 固有副作用を持たないことを設計上明記してください。

**3. 実現可能性**
- [Suggestion] Laravel 12 + Queue + `Process` facade + Svelte 5 の範囲で十分実装可能です。`AnalysisJob` 系を見本にする判断も妥当です。
- [Warning] preview を無料・ffmpeg 実行ありにする以上、`1 manual あたり in-flight 1 本` だけでは abuse 耐性が弱いです。複数 manual を跨げば同一ユーザー/同一組織で負荷を積めます。  
  修正提案: route throttle を概念ではなく契約として固定してください。少なくとも `user + org` 単位のレート制限と、`org` 単位の同時 preview 上限を仕様に入れるべきです。

**4. 期待効果の妥当性**
- [Suggestion] 完成 mp4 自動生成と preview 導線は、主張している UX 効果を合理的に期待できます。
- [Warning] preview の「トリガー後〜開始前に version 不一致なら fail」は整合性上は正しい一方、混雑時に「押したのにすぐ見られない」体験を増やします。  
  修正提案: UI 文言とジョブ結果で「編集中に内容が変わったためプレビューを作り直してください」を明示し、単なる失敗扱いにしないでください。

**5. リスク**
- [Critical] `GET .../render-jobs/{renderJob}` を `view` 権限で許しつつ、`status=succeeded` で `output_url` を返す設計は、`download`/`render` 制約の実質的な迂回です。撮影者が poll できるなら、preview の未公開内容や完成動画の署名 URL を取得できます。`published` 画面のインライン再生案も同じ問題を含みます。  
  修正提案: `polling` と `成果物アクセス` を分離してください。`RenderJobResource` では `view` に対して `output_url` を返さず、別 route で `preview` は `render` 権限、完成動画は `download` 権限を要求するのが安全です。少なくとも `kind=preview` と `kind=render` で返却条件を分け、権限チェックを入れる必要があります。
- [Warning] render 出力を Quota 対象外に置く設計は v1 として理解できますが、preview 掃除失敗や再レンダ蓄積時のストレージ肥大が運用リスクです。  
  修正提案: v1 でも「現行成果物以外は削除対象」「preview は世代 1 個のみ保持」など、保持ポリシーを仕様化してください。

**6. スコープの適切さ**
- [Suggestion] v1 としては概ね適切です。TTS、多言語、MediaConvert、push 通知を切っているのは妥当です。
- [Warning] `published` 画面でのインライン再生は、権限モデルが固まっていないまま入れると scope を広げます。  
  修正提案: v1 では「完成動画の取得は download route のみ」を正とし、インライン再生は editor 限定にするか後続へ送る方が安全です。

**7. 型安全性**
- [Warning] `RenderJobResource` 1 個に `render`/`preview` の両方を載せ、さらに `output_url` の有無を権限・状態・kind に依存させると、PHP/TS の型が弱くなります。nullable を増やすだけだと PHPStan level 10 とフロント双方で分岐漏れを招きやすいです。  
  修正提案: `kind` を discriminant にした DTO/TS union にしてください。理想は `PreviewJobResource` と `RenderJobResource` の分離、少なくとも `RenderJobData` を readonly DTO にして `kind` ごとの返却フィールドを明示することです。
- [Suggestion] enum を backed enum で統一し、`lang` も自由文字列ではなく request DTO/validator で `ja` のみを型として固定すると、設計全体が締まります。

主な差し戻し理由は 1 点です。**`view` での polling に署名 URL を混ぜているため、権限境界が破れている**。ここを直せば、残りは v1 の運用リスクとして十分コントロール可能です。