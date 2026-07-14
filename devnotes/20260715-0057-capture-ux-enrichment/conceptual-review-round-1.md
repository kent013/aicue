**全体判定: CHANGES_REQUESTED**

**1. 使命との整合性**
- [Suggestion] 1〜4 を v1 採用し、5 を除外する判断は妥当です。とくに `pause/resume`・グリッド・前後カメラ・タイマーは「撮り直しを減らす」「判断負荷を減らす」に直結しており、North Star への寄与が明確です。
- [Suggestion] 期待効果の書き方は「撮影品質向上」よりも「撮り直し率低下・詰み回避・テイク継続性維持」に寄せると、使命との接続がさらに強くなります。

**2. 禁止事項違反**
- [Suggestion] `disabled` を使わず、phase ごとにコントロール構成を切り替える方針は規約 #8 に整合しています。
- [Suggestion] ただし「録画中/一時停止中は反転ボタンを描画しない」だと、ユーザーには消えた理由が伝わりにくいです。修正提案: ボタンを消すだけでなく、「録画停止後に切替可能」の短い補助文言か、同位置の非操作ラベルを出して意図を明示してください。

**3. 実現可能性**
- [Critical] `facingMode` 切替失敗時の扱いが未定義です。ここで既存の `onCameraUnavailable(reason)` に流すと、「前面カメラが使えないだけ」で恒久フォールバックへ落ち、現行の正常な背面撮影まで壊します。これは「非破壊で拡張する」という前提に反します。  
  修正提案: `facingMode` 切替失敗は `camera unavailable` と分離し、`recoverable camera switch error` として扱ってください。具体的には「旧 stream を保持したまま再取得失敗時は facingMode をロールバックし、現行カメラで撮影継続 + inline エラー表示」にしてください。
- [Warning] `pause/resume` と前後カメラ切替のサポート判定が概念設計に不足しています。`MediaRecorder` が動いても `pause/resume` が不安定な端末、`facingMode` 指定が実質効かない端末はありえます。  
  修正提案: 機能ごとに capability detection を明記してください。`pause/resume` 非対応時はその UI 自体を出さない、前後切替非対応時は反転 UI を出さない、という degrade 方針を設計に入れてください。

**4. 期待効果の妥当性**
- [Warning] `durationMs` を正確化する効果は妥当ですが、その根拠は `setInterval` ではなく state 遷移時の累積計測であるべきです。`setInterval` はバックグラウンドや端末負荷で容易にずれます。  
  修正提案: 概念設計に「`onCaptured.durationMs` の source of truth は `performance.now()` ベースのセグメント累積、タイマー表示はその派生値」と明記してください。
- [Suggestion] グリッドとタイマーは補助効果として妥当ですが、主効果は `pause/resume` と前後カメラです。優先順位を明示すると詳細設計で UI 密度を抑えやすくなります。

**5. 既存録画ロジックへの後退リスク**
- [Warning] `paused` を `active=true` に含める判断は正しい一方、既存の preview 排他・離脱防止・親側 UI 抑止が `active` をどう使っているかまで含めて検証対象に入れる必要があります。設計文だとそこが少し薄いです。  
  修正提案: 回帰観点として「paused 中は preview を開けない」「paused 中も capture active 扱い」「stop 後 only idle 遷移」の 3 点を明示し、既存 `TakeStrip` 連携の非回帰項目に入れてください。
- [Suggestion] `safeStop` を `recording | paused` に広げるなら、`pause -> stop` と `pause -> resume -> stop` の両系統を phase テストの最重要ケースとして先に固定すべきです。

**6. スコープの適切さ**
- [Suggestion] 1〜4 採用、5 除外は適切です。5 は別イテレーションに切り出すべき規模です。
- [Suggestion] 逆に 1〜4 でも、v1 の中核は `pause/resume` と `facingMode(idle限定)` です。グリッドとタイマーは後ろに倒しても価値毀損が小さいので、実装順序まで分けておくと安全です。

**7. 型安全性**
- [Warning] phase が `idle | recording | stopping | paused` に増える以上、文字列比較の散在は事故要因です。概念設計段階で「phase 遷移表を単一箇所に寄せる」方針まで書いた方がよいです。  
  修正提案: `CapturePhase` / `FacingMode` を union 型で固定し、`canPause/canResume/canStop/canSwitchCamera` の判定を純関数化する方針を追加してください。詳細設計では TypeScript strict 前提で exhaustiveness を必須にしてください。
- [Suggestion] `onCameraUnavailable` と「recoverable enrichment error」は型を分けるべきです。同じコールバックや同じ error shape に混ぜると設計意図が崩れます。

**修正して再提示してほしい要点**
1. `facingMode` 切替失敗を恒久フォールバックに流さない設計へ修正すること。  
2. `pause/resume`・前後切替の capability detection と degrade 方針を明文化すること。  
3. `durationMs` の source of truth を state 遷移ベースの累積計測として明記すること。  
4. `paused` 追加による既存 preview 排他・active 連携の非回帰観点を設計に追記すること。  

この4点が入れば、概念設計としてはかなり堅くなります。