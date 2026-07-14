**総評**
- 実装意図（生成結果の前後に決定的な導入/総括を付与、LLM 入出力不変、再掲元を今回生成に限定）は概ね設計どおりです。
- ロック規約も `AnalysisPipeline::finalize` の terminal tx 内で `wrap`→`materializeIntoLockedManual` しており、方向性は適合です。
- ただし **1点 Critical**（手動更新 API 側で bookend の存在を保証しない仕様ズレ）があるため、判定は **CHANGES_REQUESTED** です。

**ファイル別レビュー**

- `app/Services/Manual/AnalysisPipeline.php`
  - **Suggestion**: `wrap` 呼び出し位置は適切。`lockForUpdate()` 済み manual を使って terminal tx 内 materialize しており、共有ロック規約に整合。
  - **Suggestion**: コメントで「DB 既存 cuts 不参照」を明示しており、再解析時の誤再掲防止の意図が明確。

- `app/Services/Manual/ScenarioBookendBuilder.php`
  - **Suggestion**: 純関数的責務（DB/tx非依存）が守られており設計に一致。
  - **Suggestion**: `line()` の typed accessor + `Lang::has` + `LogicException` は PHPStan L10 と fail-fast 両立で良い。
  - **Suggestion**: `CONTENT_LOCALE='ja'` 固定は、今回の「動画ドメイン文面を決定的に保存」「APP_LOCALE=en テスト環境でも非揺らぎ」の観点で妥当。
  - **Warning**: `clamp()` が単純切り詰めのため、将来表示上の禁則（例: 文末記号、絵文字結合）を要求された場合は仕様化が必要。現状 v1 要件内では問題なし。

- `app/Http/Requests/Projects/UpdateScenarioRequest.php`
  - **Critical**: `steps max` を `MAX_TOP_LEVEL_CUTS(102)` に緩和しただけで、**導入/総括の存在・位置・一意性をバリデーションしていない**。  
    設計要点は「server-side で決定的に導入/総括付与」。現状だと手動保存で 102 件の任意 step を送れて bookend なし状態を永続化可能で、round-trip 許容のための上限変更が不変条件の希薄化になっています。  
    少なくとも更新経路で「先頭/末尾が bookend であることをサーバで再構成または検証」方針を明確化すべきです（再構成推奨）。
  - **Suggestion**: 「MAX_STEPS=生成上限」「MAX_TOP_LEVEL_CUTS=保存上限」の分離自体は設計整合。

- `app/Support/Manual/ScenarioLimits.php`
  - **Suggestion**: 定数分離は明確で、境界意図（100+2）が読み取りやすい。

- `config/manual.php`
  - **Suggestion**: `summary_recap_max_points` / `scenario_bookend_title_max_chars` は妥当な可変点。
  - **Warning**: 運用時に 0/負値が入る想定をコード側で補正しているため、設定バリデーション（起動時チェック）を将来的に検討余地あり。

- `lang/ja/manual.php`
  - **Suggestion**: 文面外出しは禁止事項（prompt 直書き）にも抵触せず、設計どおり。

- `tests/Unit/Manual/ScenarioBookendBuilderTest.php`
  - **Suggestion**: 抽出優先順位、長さ制御、全角空白 normalize、config 境界、lang キー存在まで押さえており網羅性は高い。

- `tests/Feature/Projects/ScenarioBookendMaterializeTest.php`
  - **Suggestion**: 初回生成/再解析全置換/旧再掲混入防止/100→102/round-trip を一通り固定していて良い。
  - **Warning**: round-trip テストは「102件保存できる」ことは示すが、「bookend 不変条件を保持する」ことは示していない。Critical 論点を再現する逆テスト追加が望ましい。

- `tests/Feature/Llm/CannedAnalysisPipelineTest.php`, `tests/Feature/Projects/AnalysisPipelineTest.php`, `tests/Feature/Projects/ScenarioUpdateTest.php`
  - **Suggestion**: 既存期待値の更新は妥当。
  - **Warning**: `ScenarioUpdateTest` は上限値更新のみで、bookend 構造の保全検証が不足。

**観点別判定**
- 設計一致性: **一部未達**（手動更新経路の不変条件）
- 正確性（順序・全置換・再掲元・長さ制御）: **概ね良好**
- PHPStan L10: **適合**
- DTO/JsonResource: **問題なし**（新規 `response()->json()` 直書きなし）
- テスト網羅: **高いが不変条件の穴あり**
- セキュリティ/共有ロック: **materialize 経路は適合**
- 複雑性: **過剰ではない**

**最終判定**
- **CHANGES_REQUESTED**

**最小修正提案（次ラウンド）**
- `PUT /scenario` 経路で以下のいずれかを実装:
  - サーバ側で受信 `steps` から常に bookend を再付与（決定的再構成）  
  - もしくは先頭/末尾の bookend 構造を厳密検証し不一致は 422
- その上で Feature テスト追加:
  - bookend なし/位置違い payload が拒否または自動補正されること
  - 更新後も top-level 先頭=導入、末尾=総括が維持されること