全体として設計の方向性は非常に良く、North Star・禁止事項・不変条件をほぼ一貫して満たしています。  
ただし、**本番事故につながる可能性がある論点が複数あるため現時点は `CHANGES_REQUESTED`** です。

**全体判定**
- `CHANGES_REQUESTED`

**施策別判定**
- 施策1: `APPROVE`
- 施策2: `REQUEST_CHANGES`
- 施策3: `APPROVE`
- 施策4: `REQUEST_CHANGES`
- 施策5: `REQUEST_CHANGES`
- 施策6: `REQUEST_CHANGES`
- 施策7: `REQUEST_CHANGES`
- 施策8: `APPROVE`
- 施策9: `REQUEST_CHANGES`
- 施策10: `APPROVE`
- 施策11: `REQUEST_CHANGES`
- 施策12: `REQUEST_CHANGES`
- 施策13: `APPROVE`

**主な指摘（Critical / Warning / Suggestion）**
- [Critical] 施策2 token budget算術が成立していません。`analysis_max_text_bytes=150000` と `16000+4000` を足すと 170000 で、`MODEL_CONTEXT_TOKENS=200000` は通る一方、説明文の「180000 token」と不整合。さらに「byte>=token」は言語依存で常に安全上界とは言えません。  
  修正案: `doc/10` と `config/manual.php` の根拠を統一し、テスト名を「保守的近似の不変条件」に変更。上界保証を主張するなら実測係数（例: 日本語 worst-case係数）を導入し `max_bytes * k + reserve + overhead <= context` にする。

- [Critical] 施策4/5/6で `manual.status` 遷移責務が分散し、競合時に意図しない復帰が起こる余地。`trigger` が `Analyzing` にし、`failJob` が cuts 有無で戻し、`finalize` が `Ready` 化。並行実行で「後勝ち」が見えにくい。  
  修正案: 状態遷移を `AnalysisJobService` に集約（`markAnalyzing/markSucceeded/markFailed`）し、`ScenarioService` は cuts 更新専用に限定。遷移表を `doc/10 §10.2` とテストで固定。

- [Critical] 施策6 `finalize` 内で `ScenarioService::materializeFromAnalysis()` が内側 transaction を張る設計は、実装者依存でロック順逆転リスク。  
  修正案: `materializeFromAnalysisLocked(VideoManual $lockedManual, ...)` のように「ロック済み前提メソッド」を追加し、transaction/lock は最外層だけに統一。

- [Warning] 施策5 `show` で `{analysisJob}` が `{manual}` 配下であることを明示再検証していない（route scope前提）。  
  修正案: 念のため `if ($analysisJob->video_manual_id !== $manual->id) abort(404);` の防御を追加（多層防御）。

- [Warning] 施策4 Storage先行書き込みは孤児ファイルを許容していますが、運用負債が増えます。  
  修正案: `afterCommit` で保存するか、孤児クリーンアップコマンド（path規約で未参照検出）を最初から用意。

- [Warning] 施策7 抽出器の MIME/拡張子判定が曖昧だと polyglot file 取り込みリスク。  
  修正案: `mimes` バリデーションに加えてサーバ側で MIME 再判定し、判定不一致を拒否。PDF/Excel パーサ例外は利用者向けメッセージに正規化。

- [Warning] 施策11 ポーリング `setInterval(2500ms)` はタブ非表示時にも走る設計。  
  修正案: `document.visibilityState` 連動で停止/再開、または指数バックオフ（running時のみ短間隔）。

- [Warning] 施策12 テスト計画は充実していますが、**RefreshDatabaseグローバル適用下の並列実行**で queue/clock 競合を起こしやすい。  
  修正案: 時刻依存ケースに `travelTo` を使い、Queue fake/sync をテストごとに明示。`analysis:recover-stale-jobs` は並列でも独立 DB を前提にID衝突しないFactory生成を固定。

- [Suggestion] 施策1 `analysis_jobs.status` を string保存にしている点は拡張性あり。`check constraint`（許可値）をDBに追加すると事故耐性が上がります。  
- [Suggestion] 施策8 DTO検証は良いです。`LlmJson::decode` の共通エラーコード体系（invalid_json / schema_violation）を持つと運用分析が楽になります。  
- [Suggestion] 施策13 に「NestedRouteIdorDefenseTest inventory更新済み」を明記すると監査性がさらに上がります。

必要なら次に、上記 Critical を潰すための**最小差分リデザイン案（状態遷移責務の再配置 + ロック順固定 + テスト追加3本）**を具体的に提示します。