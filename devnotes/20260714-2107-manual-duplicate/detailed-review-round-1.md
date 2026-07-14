以下、提示された**詳細設計のみ**を対象にしたレビュー結果です（実コード未確認のため、設計上の妥当性レビュー）。

## 施策別判定

- 施策1 `route 追加`：**APPROVE**
- 施策2 `DuplicateVideoManualRequest`：**APPROVE**
- 施策3 `Policy duplicate`：**APPROVE**
- 施策4 `VideoManualService::duplicate()`：**REQUEST_CHANGES**
- 施策5 `Controller duplicate + show props`：**APPROVE**
- 施策6 `frontend 複製ダイアログ`：**APPROVE**
- 施策7 `IDOR inventory`：**APPROVE**
- 施策8 `共有ロック inventory/doc`：**APPROVE**
- 施策9 `Feature テスト`：**REQUEST_CHANGES**
- 施策10 `vitest`：**APPROVE**

## 指摘（重要度別）

- [Critical] **施策4**: `duplicate()` 内でカテゴリ再解決に `firstOrFail()` を使うと、検証後競合（カテゴリ削除/移動）が **404** になりうる設計です。これは「入力妥当性エラー」の性質に近く、利用者体験と既存契約（422系）を壊す後退リスクがあります。  
  修正案: `first()` + `if ($category === null) { ValidationException::withMessages(['category' => ...]); }` で **422** に寄せる、または controller 側で例外を 422 にマップする方針を明文化してください（既存 `store` 契約と合わせる）。

- [Warning] **施策4**: `copyCuts()` が source を `sort_order,id` で全件取得後に `where('type', ...)` するため、point の順序は「全体順序」依存です。`CutSequencer` と同順を意図するなら、point 側も `sort_order,id` を明示した抽出にしておく方が将来差分に強いです。  
  修正案: step/point を別クエリでそれぞれ `orderBy('sort_order')->orderBy('id')`。

- [Warning] **施策4/9**: 「孤児 point は skip」は防御的ですが、データ破損を黙殺します。運用上の異常検知がないと不整合を見逃します。  
  修正案: skip 時に `warning` ログ（manual id/cut id）を必須化し、Feature テストで「孤児 point が複製されない + ログ出力」を1ケース追加。

- [Warning] **施策9**: テスト計画に「共有ロック規約の新経路」が機械的に守られることの担保が弱いです（inventory更新はあるが、実挙動の退行検知が薄い）。  
  修正案: 少なくとも `duplicate` 実行後に `scenario_version/status` が DB default（0/draft）であることを明示 assertion（既に記載あり）に加え、`adopted_take_id` 非複製を step/point 両方で検証してください。

- [Suggestion] **施策2**: `categoryId()` の `nullOrIntegerish` は十分ですが、`numeric string` 許容方針をコメントで明示済みならより良いです（現状でも問題なし）。

- [Suggestion] **施策6/10**: UIで「必須未充足でも押下可」は規約準拠で良いです。`useForm.transform()` の `Number(...)` 変換に対し、vitestで `"abc"` 等は発生しない前提（Select固定）を1行コメントしておくと保守性が上がります。

## 重点質問への回答

- (a) 共有ロック literal準拠: **概ね妥当**。新manualを `lockForUpdate` 再取得して relation 経由で cut 作成は、規約に忠実です。  
- (b) fill/forceFill とリセット: **妥当**。`adopted_take_id` 非コピー・`cut_length_ms=null` 明示は正しい。  
- (c) FormRequest 型付きアクセサ + PHPStan L10: **妥当**。`Assert` による narrow も良い。  
- (d) Modal footer submit + Atomic: **妥当**。`form` 属性 submit も問題なし。  
- (e) 波及網羅: **概ね良好**。ただし施策9の異常系（孤児point/契約ステータス）をもう一段強化推奨。

## 全体判定

**CHANGES_REQUESTED**

主理由は1点：**カテゴリ競合時の 404 化リスク（契約後退）**。ここを 422 契約に揃える修正方針を明文化できれば、全体は APPROVE に近い品質です。