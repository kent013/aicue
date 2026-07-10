以下、**施策ごとに判定**します（提示文面ベースの設計レビュー）。  
結論から言うと、完成度は高いですが、P0 に **Critical が複数**あるため全体は `CHANGES_REQUESTED` です。

## 施策別判定

- 施策1: `APPROVE`
- 施策2: `APPROVE`
- 施策3: `APPROVE`
- 施策4: `REQUEST_CHANGES`
- 施策5: `APPROVE`
- 施策6: `REQUEST_CHANGES`
- 施策7: `REQUEST_CHANGES`
- 施策8: `REQUEST_CHANGES`
- 施策9: `APPROVE`
- 施策10: `REQUEST_CHANGES`
- 施策11: `APPROVE`
- 施策12: `APPROVE`
- 施策13: `APPROVE`
- 施策14: `APPROVE`
- 施策15: `APPROVE`
- 施策16: `APPROVE`

---

## 主要指摘（Critical/Warning/Suggestion）

- [Critical] **ロック順の記述と実装想定が不整合（施策4/8）**  
  `triggerPreview()` で `video_manuals -> organizations`、一方 `RenderPipeline::startJob()`（render）で `render_jobs -> organizations`。将来 `render_jobs -> video_manuals` を同一txで触る箇所と組み合わさると順序逸脱の温床。  
  **修正案**: render系のグローバル順を「`render_jobs -> video_manuals -> ticket_reservations -> organizations`」に固定し、`triggerPreview()` も必要なら `video_manual` ロック後に `organizations` へ行く実装を Architecture テストコメントと完全一致させる（順序を docs/architecture とテストで単一真実源化）。

- [Critical] **`RunManualRender::failed()` の error_code が Timeout 固定になりうる（施策8）**  
  timeout 以外の失敗（例: deploy中断、worker停止）でも `Timeout` が付くと運用判断を誤る。  
  **修正案**: `failed()` は `RenderErrorCode::Internal` を既定にし、`TimeoutExceededException` 相当を判別できる場合のみ `Timeout` を設定。timeout分類は pipeline/catch と整合させる。

- [Critical] **字幕境界の設計は良いが、ASS特有のエスケープ仕様が不足（施策6/7）**  
  `{}`・`\N`系対策はあるが、ASSの行末制御/Unicode制御文字や極端長テキストで ffmpeg 側失敗が起きうる。  
  **修正案**: `AssSubtitleWriter` に「1行最大長・総文字数上限」「不可視制御文字の包括除去」「BOMなしUTF-8固定」を明記し、超過時は切り詰め + ログ。Unit で長文・ゼロ幅文字を追加。

- [Warning] **`download` filename の安全化責務が曖昧（施策10）**  
  `temporaryDownloadUrl()` 側helper依存とあるが、責務境界が文書のみ。  
  **修正案**: `RenderObjectStorage::temporaryDownloadUrl()` の契約に「RFC5987 + ASCII fallback + CRLF除去」を明文化し、Unitテスト追加。

- [Warning] **`RenderPanel` のポーリング2系統で重複更新競合の可能性（施策14）**  
  render/preview を別タイマーで追うと、`router.reload()` のタイミング衝突でチラつく。  
  **修正案**: 単一ポーリングループで両jobを管理し、終端条件だけ kind 別分岐。共通化しない方針は維持しつつ、1コンポーネント内で scheduler は1本化。

- [Warning] **`reconcileOutputs` の再投入条件に可観測性不足（施策9）**  
  冪等は良いが、再投入が継続した際の検知指標がない。  
  **修正案**: 件数・失敗理由（prefix mismatch等）をログ構造化し、command出力に `dispatched/skipped/report` を出す。

- [Suggestion] `RenderConflictType::StatusNotRenderable` は render/preview で文脈差があるため、messageを状態別により具体化するとUI分岐不要でUX向上。  
- [Suggestion] `RenderTimeBudgetInvariantTest` に「`render_max_total_source_ms` 変更時の注記」を入れると運用変更レビューで事故が減る。  
- [Suggestion] `ManualEnumTsSyncInvariantTest` は非常に良い。`JobStatus` 共用部分も将来 drift 検知対象に含めるとさらに堅い。

---

## レビュー観点別サマリ

- 正確性: 状態機械/冪等/2フェーズは妥当。上記 Critical を解消すれば堅牢。
- 既存整合: `AnalysisJob*` 踏襲が一貫しており良い。
- PHPStan Lv10: 型注釈方針は適合見込み高い。
- テスト計画: 非常に網羅的（並行実証含む）で良い。
- DTO/Resource: 準拠。
- Inertia/API分離: 適切。
- セキュリティ: IDOR・payload不信任・課金冪等は良い。字幕境界は追加ハードニング推奨。
- DESIGN/Atomic: disabled禁止・配置方針ともに適合。

---

## 全体判定

`CHANGES_REQUESTED`

上記 **Critical 3点（ロック順の単一化、failed時error_code分類、ASS境界の追加仕様）** を反映すれば、再レビューで `APPROVED` 相当です。