# 対応マトリクス: design-review Round 1 (item2)

## [施策1 Warning] status を `VideoManualStatus::Draft->value` に揃える
- 判断: **反論**
- 根拠: コードベースの status 書き込みは**全て enum インスタンス**を渡す流儀
  (ScenarioService L141/268/273 `VideoManualStatus::Ready`、RenderJobService L105/197/252、
  AnalysisJobService L94/136。`->value` を使う箇所は 0 件)。`'status' => VideoManualStatus::Class::class`
  cast 済みで enum を forceFill に渡すのが canonical。`->value` に揃える方がむしろ既存流儀から
  逸脱する。よって `VideoManualStatus::Draft` (enum インスタンス) を維持する。

## [施策1 Suggestion] docblock に source lockForUpdate を補強
- 判断: 対応
- 対応内容: docblock に「source manual は lockForUpdate 済みで read/copy 一貫性を確保」の 1 文を追記。

## [施策2 Critical] file 単位 allowlist は粒度が粗い (method 単位へ)
- 判断: **反論**
- 根拠: このテストの status_write allowlist は**設計上 file 単位**。既存の許可 3 ファイルは
  いずれも複数の status 書き込みメソッドを持つ (ScenarioService 3・AnalysisJobService 2・
  RenderJobService 3)。file 単位で「そのファイルはロック済み経路として status を書いてよい」を
  表現するのが本テストの確立した粒度であり、VideoManualService.php を file 単位で追加するのは
  完全に整合的。さらに本テストの**既存コメント (L21-22)** が「将来 duplicate が status を書くよう
  変わったら STATUS_WRITE_ALLOWED への追加が必要」と本変更を明示的に想定している = 正規の
  inventory メンテナンス。1 ファイルだけ method 単位検出を導入するのは scanner の大規模改修で
  あり、既存 3 ファイルとの非対称・オーバーエンジニアリング (禁止事項#6)。監査性は
  docblock の write 理由明記 + 振る舞い回帰テスト (施策3) + MassAssignment 保護で担保する。
  (scanner 全体の method 粒度化を望むなら 4 ファイル横断の別 refactor として扱うべきで、
  Low priority の本件スコープ外。)

## [施策2 Warning] scenario_version の read/write allowlist を分離
- 判断: **反論**
- 根拠: 検出 1 は 'scenario_version' トークンの**出現 (read/write 問わず) = touch 粒度**で設計
  されている。既存 SCENARIO_VERSION_ALLOWED は read 専用エントリ (CaptureTakeService=409 の
  current_version read、ScenarioDocumentData=直列化 read) と write エントリ (ScenarioService) を
  すでに混在許可しており、VideoManualService に write 理由を追記するのは既存設計と整合。
  read/write allowlist 分離は検出 1 の粒度変更 (scanner refactor) で本件スコープ外。
  コメントで read/write 両理由を明記して監査性を保つ。

## [施策3 Suggestion] created_by が複製実行者由来であることも検証
- 判断: 対応
- 対応内容: 新規回帰テストに `expect($copy->created_by)->toBe($owner->id)` を追加。
