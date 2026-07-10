# 対応マトリクス: conceptual-review Round 1

## [Critical] 実現可能性: cuts/scenario_version/status を触る全経路の共有ロック規約が未固定
- 判断: 対応する
- 根拠: 状態 guard（409）だけでは job 側書き込みとの交差を構造的に防げない。指摘どおり
  共有不変条件として固定しないと後続フェーズで clobber が再発する。
- 対応内容: 概念設計 §3 冒頭に「シナリオ整合の共有不変条件」を新設。
  「cuts / scenario_version / status を書き込む全経路は対象 VideoManual 行を lockForUpdate()
  で取得した同一トランザクション内で反映する」を設計契約として明文化し、
  `docs/architecture.md`（+ AGENTS.md ドメイン固有規約）への記録を成果物に追加。
  本フィーチャの ScenarioService::save() を最初の準拠実装と位置づけた。

## [Critical] リスク: 既存 cut の type をネスト位置から再導出すると暗黙の階層/型変換を許す
- 判断: 対応する
- 根拠: 2 階層構造で step→point 降格は配下 point の暗黙削除・構造破壊に直結する。
  v1 の UI も階層移動を提供しないため、禁止しても UX 損失はない。
- 対応内容: §2 に「既存 cut の階層/型変更は v1 禁止」を追加。既存 id は既存 type と一致する
  位置にのみ出現可（不一致は 422）。type のサーバ導出は新規 cut の materialize 用として維持し、
  既存 cut では位置と既存 type の一致検証を行う。テスト計画に「階層変更 422」を追加。

## [Warning] 実現可能性: update→create→delete だけでは新規 step 配下の新規 point の親 id 払い出し順序が曖昧
- 判断: 対応する
- 根拠: 正当。id=null の step に id=null の point がぶら下がるケースは段階化しないと書けない。
- 対応内容: §3 の reconcile を「段階 1: step 群確定（id 払い出し）→ 段階 2: point 群確定
  （親 id を forceFill）→ 段階 3: 削除」の 2 段階 + 削除に書き直した。

## [Warning] 期待効果: 409 時「再読み込みしてください」だけではローカル編集内容を失う
- 判断: 対応する
- 根拠: 競合時の編集内容喪失は編集基盤としての期待効果を直接損なう。
- 対応内容: §5 を修正。409 時はローカル作業コピーを破棄せず conflict バナー表示、
  「サーバの最新を取得」は確認ダイアログで破棄を明示同意させてから reload する。
  成功指標に「競合による編集内容喪失ゼロ」を追加。

## [Warning] リスク: published→ready の「実変更」判定が未定義
- 判断: 対応する
- 根拠: no-op 保存や正規化差分で published を巻き戻すのは後退。
- 対応内容: §3-5 で実変更を「create/delete の発生・既存 cut の isDirty()・sort_order/parent_cut_id
  の変更」（サーバ導出値込みの Eloquent dirty 検査 = 意味差分）と定義。実変更なしの保存は
  published を維持。scenario_version は §10.8-2 の確定契約（成功時 +1）に従い常に +1 とする
  （版の単調増加はクライアント側の一貫性を単純化し、内容同一の並行保存が 409 になっても
  実害はリロード 1 回のため）。

## [Warning] リスク: NestedRouteIdorDefenseTest 登録が PUT だけで GET edit が未確認
- 判断: 反論する（確認済み・登録済み）
- 根拠: `tests/Architecture/NestedRouteIdorDefenseTest.php` の inventory に
  `projects.manuals.edit => ScopeBindings` が既に登録済み（フェーズ1）であることを確認した。
- 対応内容: 設計のテスト行に「GET projects.manuals.edit は登録済みを確認」と明記し、
  今回の追加は `projects.manuals.scenario.update` のみとした。

## [Warning] スコープ: メタ編集 PATCH と scenario PUT の 2 保存系統が同一画面で混乱しやすい
- 判断: 対応する
- 根拠: 保存単位の混同は未保存喪失事故につながる。
- 対応内容: §5 で「基本情報を保存」「シナリオを更新」を独立セクション・独立ボタン・
  独立 dirty 判定で完全分離することを明記。

## [Warning] 型安全性: typed array + TS interface だけでは props/Resource の shape 乖離が残る
- 判断: 対応する
- 対応内容: `ScenarioDocumentData` DTO を導入し、edit の Inertia props と保存成功応答を
  同一 DTO から生成して shape を 1 箇所で固定（§4 に追記）。

## [Warning] 型安全性: 409 の conflict 種別が文字列ベタ書きになる
- 判断: 対応する
- 対応内容: PHP backed enum `ScenarioConflictType` + TS discriminated union で判別を固定
  （既存 RecentAuthRequiredDto::CODE の 409 契約と同じ「code 厳格一致」方式）（§4 に追記）。

## [Warning] 使命: 「思考ゼロ」への貢献を強く言い過ぎ
- 判断: 対応する
- 対応内容: 期待効果を「AI 生成シナリオを業務投入可能な品質まで短時間で整える編集基盤」に
  表現を下げ、成功指標（保存成功率・競合による編集内容喪失ゼロ）を追加。

## [Suggestion] 自作シナリオの空状態から最初の step を作る導線
- 判断: 対応する
- 対応内容: §5 に EmptyState + 「最初の手順を追加」ボタンを明記。

## [Suggestion] テストファーストの明文化
- 判断: 対応する
- 対応内容: 実装方針テーブルのテスト行に「テストファースト（fail を確認してから実装）」を明記。
