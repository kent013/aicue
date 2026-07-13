# 概念設計レビュー Round 4

R3 の指摘（保存済み timestamp の大小は時間経過で不変＝「自己回復」は誤り／clock skew も順序を崩す）を
受け、**timestamp 比較を廃し、DB 権威の `scenario_version` スナップショット方式へ設計変更**しました。
確認して全体判定を返してください。

## 変更点: staleness = scenario_version スナップショット比較

- `analysis_jobs` / `render_jobs` に nullable `scenario_version_at_terminal` を追加。
- `AnalysisJobService::failJob` / `RenderJobService::failJob` が **manual を lockForUpdate した同一 tx 内で
  `manual.scenario_version` を snapshot 書込み**（両 failJob は既に manual を lock 済み。render の preview
  分岐のみ version 読取り lock を追加）。
- 判定: `isStaleFailure(manual, job)` =
  `job.status===Failed && job.scenario_version_at_terminal !== null &&
   manual.scenario_version > job.scenario_version_at_terminal`。

### なぜこれが R1–R3 の Warning を根本解消するか

1. **同一秒衝突・clock skew 無縁**: `scenario_version` は manual 行 lock 下の tx で +1 される単調整数。
   時刻を使わないため、秒精度衝突（R2/R3）も別プロセス clock skew（R3 Suggestion）も判定に無関係。
2. **terminal 後 touch 懸念の構造的消滅**: snapshot は失敗確定時に一度だけ書かれ、`isTerminal()` 早期
   return で再 fail 不可。判定は `updated_at` に一切依存しないため、R1/R2 の updated_at 依存懸念は消える。
3. **scenario_version_changed CTA 保持**: 比較軸を「作成時 version」でなく **「失敗確定時 version」** に
   したことで、当該失敗（作成 N → 編集 N+1 → 失敗）は snapshot=N+1, manual=N+1 → not stale で CTA 保持。
4. **決定的（確率的でない）**: シナリオ保存を伴う全経路で決定的に stale 判定。

### 判定行列（各ケースの帰結）

| ケース | snapshot | manual.version | 判定 |
|--------|----------|----------------|------|
| 解析失敗(cuts無,V) → 手動完成(save V+1, ready) | V | V+1 | **stale（抑制）** ← HIGH 本丸 |
| ready(V) から再解析 → 失敗(version不変) | V | V | not stale（表示） |
| render scenario_version_changed 失敗 | N+1 | N+1 | not stale（CTA 保持） |
| render/preview 失敗(M) → シナリオ編集(M+1) | M | M+1 | **stale（抑制）** |

### 受容する残存エッジ（明示）
- take 採用/解除は `scenario_version` を bump しない（`cuts.adopted_take_id` のみ）。よって「レンダ失敗 →
  シナリオ保存を全く伴わず take 採用のみ」後のレンダ失敗 alert は検出外。ただし HIGH 本丸外・fail-safe
  （隠さず表示）・「採用テイク未設定」は多くがトリガ時 422 の一過性 start error（永続 job でない）。
  version を採用起点で bump するのは scenario_version_changed 誤発火・楽観ロック競合を招くためスコープ外。
- 旧 failed job（snapshot=null）は not stale＝表示（保守的・隠さない）。

## 実装コスト・規約整合
- 変更面: migration（nullable int×2 テーブル）+ 両 failJob の snapshot 書込み + Model @property +
  `VideoManualService` の display 系メソッド + Controller 委譲。DTO/TS 型/client は不変（job:null 表現）。
- 共有ロック規約: snapshot は failJob（既 manual lock 済み書込み経路）で **job 列にのみ**書き、
  cuts/scenario_version/status は書かない → ScenarioWritePathInventoryTest への登録不要。

## 質問
version snapshot 方式で R1–R3 の全 Warning は解消と判断してよいか。残 Critical/Warning を指摘してください。
