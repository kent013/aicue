**全体判定: CHANGES_REQUESTED**

**1. 使命との整合性**
- [Suggestion] 1〜4 を v1 に入れ、5 を将来送りにする切り分けは概ね妥当です。特に `pause/resume`・グリッド・タイマーは「迷わず、分断せず、最低限の品質で撮れる」に直結しており、North Star との整合は高いです。
- [Suggestion] ただし価値の強さには差があります。使命への直接寄与は `pause/resume` とグリッドが最も強く、カメラ反転は補助的です。実装順の優先度まで設計に明記すると、v1 の芯がさらにぶれません。

**2. 禁止事項違反**
- [Suggestion] 提示された範囲では禁止事項への明確な抵触は見当たりません。フロント完結で、API 契約も `onCaptured(blob, mimeType, durationMs)` を維持する前提になっている点は妥当です。
- [Suggestion] 「録画中は disabled ではなく非表示で扱う」という整理も、今回の文脈では許容範囲です。ただし UX 的には“なぜ今出ていないか”が分かりにくいので、将来的には説明文言か状態表示を検討してよいです。

**3. 実現可能性**
- [Warning] `MediaRecorder.pause()/resume()` を「高容易性」と置いているのはやや楽観的です。PWA/モバイル系では `MediaRecorder` 自体の存在と `pause/resume` の安定性は別問題で、既存の `supportsMediaRecorder()` だけでは足りません。  
  修正提案: `pause/resume` は別能力として明示的に feature detect し、未対応時はボタンを出さないか、v1 ではその端末だけ従来の start/stop のままにしてください。`InvalidStateError` も握りつぶさず UI 状態へ戻す設計が必要です。
- [Warning] カメラ反転の「release → 新 facingMode で再取得」は失敗時に既存の動作を壊しやすいです。特に前面カメラ非搭載・権限再確認・ブラウザの `facingMode` 無視時に、今のプレビューまで失う可能性があります。  
  修正提案: 旧 stream を先に破棄せず、「新 stream 取得成功後に差し替え、失敗時は旧 stream を維持」が必要です。単なる切替失敗を `onCameraUnavailable(reason)` に流して F-03 へ倒すのも避けるべきです。
- [Suggestion] タイマー自体の実装は十分現実的です。UI 表示用なら `setInterval` で足りますが、内部の累積計測は `Date.now()` より単調増加時計ベースの方が安全です。

**4. 期待効果の妥当性**
- [Suggestion] 主張している効果は概ね合理的です。グリッドで構図、タイマーで尺、pause/resume で取り直しコスト削減、という因果は自然です。
- [Suggestion] ただし「素材の質を底上げする」は支援効果であって保証ではありません。設計書上は「再撮影率低下」「途中離脱低下」「1 カットあたりの分断減少」など、より観測可能な効果に言い換えると強いです。

**5. リスク**
- [Warning] `durationMs` を wall-clock から「累積録画時間」へ変えるのは、契約の“型”は同じでも“意味”は変わります。後方互換と言い切るには根拠が不足しています。  
  修正提案: `onCaptured` の全消費側が「実録画尺」を期待していることを先に棚卸ししてください。未確認なら、少なくとも設計上は「意味変更あり」と明記し、影響確認を実装前提に格上げすべきです。
- [Suggestion] グリッド overlay と字幕 overlay の同居は成立しますが、可読性の衝突は起こりえます。z-index、線の濃さ、字幕帯との重なり方は先に規約化した方が安全です。
- [Suggestion] `paused` で `active=true` を維持する判断は正しいです。preview 排他を壊さない点はこの設計の良いところです。

**6. スコープの適切さ**
- [Suggestion] v1 判定は全体として妥当です。5 を out-of-scope にしたのは正しく、これは補助機能追加ではなく撮影フロー全体の再設計に近いので、今入れると過大です。
- [Warning] ただし 1〜4 をひとまとめで「軽量」とみなすのは少し粗いです。中でもカメラ反転だけは端末差分と失敗時の退行リスクが高く、他 3 件より重いです。  
  修正提案: v1 内でも優先度を分け、`1/2/4 を core`、`3 は guarded v1` もしくは別タスク扱いにしてください。これなら doc/05 への準拠を進めつつ、既存 MediaRecorder/preview/fallback を守りやすいです。

**7. 型安全性**
- [Warning] `phase` に `paused` を追加するなら、状態分岐の網羅性を崩さない設計が必要です。Svelte 5 + TypeScript では、`Phase` を union 化して UI 文言・ボタン表示・副作用条件を全て exhaust させないと、見落としが出やすいです。  
  修正提案: `type Phase = "idle" | "recording" | "paused" | "stopping"` を単一ソース化し、表示条件・ハンドラ・`active` 算出をその型に従属させてください。
- [Warning] タイマーの interval 型と facingMode 型は雑に `string`/`number` に落とさない方がよいです。  
  修正提案: `type FacingMode = "environment" | "user"` を共通化し、timer handle はブラウザ実装に合わせた型で閉じてください。`formatElapsed(ms)` を pure function に切り出す方針自体は良いです。
- [Suggestion] `camera.ts` に toggle helper を入れるなら、「純粋関数としてテスト価値があるもの」だけに絞るのがよいです。単なる `environment <-> user` 反転だけなら、helper 化の利益は小さめです。

修正の主眼は 3 点です。`pause/resume` の能力差分を前提化すること、カメラ反転を失敗時 rollback 付きにすること、`durationMs` の意味変更を後方互換扱いしないこと。この 3 点が詰まれば、v1 スコープ判断自体はかなり筋が通っています。