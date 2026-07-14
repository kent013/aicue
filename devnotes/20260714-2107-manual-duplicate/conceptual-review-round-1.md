**全体判定: CHANGES_REQUESTED**

**1. 使命との整合性**
- [Critical] この案は `doc/04` が要求する「別名保存」の意味を満たしていません。現案は「保存済みマニュアルの複製」であり、編集画面上の現在編集中内容を別名で保存する導線ではありません。特に `Show` 画面配置・確認ダイアログのみ・タイトル/カテゴリは後編集、という設計だと、未保存の編集内容を新規マニュアルへ退避する用途を満たせません。  
  修正提案: 2択です。  
  1. 本当にギャップ #4 を閉じるなら、`Edit` 画面の保存群から起動し、`title` / `category_id` を受け取って「現在の編集内容を新規 manual として保存する」Save As に寄せる。  
  2. そこまでやらないなら、機能名を「複製」に下げ、`doc/04` の「別名保存」ギャップは未解消として扱う。
- [Suggestion] 現案の価値自体はあります。シナリオ再利用で量産しやすくなるため、North Star への寄与はある。ただし「別名保存」ではなく「既存シナリオ複製」と表現した方が正確です。

**2. 禁止事項違反**
- [Warning] 現案自体は `response()->json()` 直書きや保護キーの payload 受領を避けており、方向性は規約順守です。ただし、もし `title` / `category` 入力付きに修正するなら、Controller 直受けではなく専用 `FormRequest` でバリデーションし、応答は Inertia redirect のまま維持すべきです。  
  修正提案: `DuplicateVideoManualRequest` を切り、`title|max:200` と `category_id` の project 内存在確認を request 層に寄せる。

**3. 実現可能性**
- [Warning] 技術的には十分実装可能ですが、共有ロック規約への適合条件をもう一段明文化した方が安全です。現案は「元 manual を `lockForUpdate()` して cuts を読む」点は良い一方、実際に cuts を書く先は新規 manual です。規約文面上は「対象 VideoManual 行を lockForUpdate した同一 tx 内で反映」が要求されるため、新規 row 作成ケースをどう扱うかを設計で明示しておくべきです。  
  修正提案: `docs/architecture.md` に「新規 manual を同一 tx 内で insert 後、その tx 内で cuts を materialize する duplication path は準拠」と明記する。必要なら新規 manual を再読込して `lockForUpdate()` する実装方針まで固定する。

**4. 期待効果の妥当性**
- [Warning] 「ユースケース・カバレッジのギャップ #4 を閉じる」という主張は強すぎます。現案で解消できるのは「既存シナリオを雛形に別 manual を起こせない」問題までで、`doc/04` の Save As UX 要件までは満たしていません。  
  修正提案: 効果の記述を「複製ギャップの縮小」に下げるか、前述の通り Edit 画面の Save As に設計を寄せる。

**5. リスク**
- [Warning] `source document` を複製しない判断は妥当です。ただしユーザーは「元 manual を複製したのだから SOP も付いてくる」と誤認しやすいです。複製後に `hasDocument=false` になる点が無説明だと UX 事故になります。  
  修正提案: 成功フラッシュか詳細画面上で「SOP は引き継がれていません」と明示する。
- [Warning] ルート/認可/再解決方針は IDOR・cross-org 観点で筋が良いですが、Architecture/Feature テストの追加方針が本文で弱いです。この repo ではテスト登録までが設計完了条件に近いです。  
  修正提案: 少なくとも以下を設計に明記する。  
  1. `NestedRouteIdorDefenseTest` inventory 追加  
  2. 権限別 duplicate 可否の Feature test  
  3. `adopted_take_id` / `cut_length_ms` / `status` / `scenario_version` のリセット確認  
  4. source_documents / takes / jobs が複製されないことの確認

**6. スコープの適切さ**
- [Warning] v1 スコープとの整合は概ね良いですが、`doc/04` 由来の要件を削ってまで最小差分に寄せている点は再判断が必要です。「詳細画面に複製ボタン」は実装最小ですが、要求最小ではありません。  
  修正提案: v1 の最小実装を守るなら、`Edit` 画面から title/category だけを取る軽量ダイアログに留めるのが妥協点です。新状態機械は不要で、仕様充足度はかなり上がります。
- [Suggestion] 同一 project 限定、takes/render/jobs 非複製、TTS 対象外は妥当です。ここは広げない方がいいです。

**7. 型安全性**
- [Warning] 現案の redirect-only 応答は DTO/JsonResource 規約に反しません。ただし title/category 入力を追加する場合、型安全性は Service の素朴な引数追加ではなく Request バリデーションと明示的な service interface で担保すべきです。  
  修正提案: `duplicate(Project $project, VideoManual $source, UserId $actor, DuplicateManualData $data)` のように入力境界を固定し、`category` 再解決も service 内で完結させる。

設計の芯は悪くありません。問題は「複製」と「別名保存」を同一視している点です。そこを分けて、`doc/04` を本当に満たすのか、今回は簡易複製として切るのかを先に決めるべきです。