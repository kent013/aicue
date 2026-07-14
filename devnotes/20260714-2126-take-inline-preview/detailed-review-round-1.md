**施策別レビュー**

- **S1: per-take プレビュー再生エンドポイント** → **REQUEST_CHANGES**
  - [Critical] `Gate::authorize('preview', $take)` 追加に伴い、`TakePolicy` が ability マッピング対象になっている前提を明示すべきです。Laravel の policy auto-discovery が外れている構成だと常時 deny/例外化リスクがあります。  
    修正案: `AuthServiceProvider`（または同等の policy 登録箇所）で `Take::class => TakePolicy::class` が既存有効であることを設計書に追記し、`preview` の認可 Feature テスト（403/200）を必須化。
  - [Warning] 302 に `Cache-Control: no-store, private` を付ける方針は良いですが、既存 `ManualRenderController::playback` と挙動差が生まれます。将来的な運用混乱の火種。  
    修正案: 「takeのみ厳格化する理由（撮影PWAの即時再生・再利用抑止）」を設計に明文化し、可能なら render 側も追随検討TODO化。
  - [Suggestion] `ready` 以外を 404 に落とすのは妥当。`failed` を 404 にする理由（内部状態秘匿）を短くコメント化すると保守しやすい。

- **S2: `TakePreviewDialog.svelte`** → **APPROVE**
  - [Warning] `teardownVideo()` を `open=false` 検知に寄せる設計は妥当だが、`take` 差し替え時（open=trueのまま別takeへ）にも teardown を入れないと端末差異で音声継続の可能性。  
    修正案: `take?.id` 変化時にも `pause/src除去/load` を実行する分岐を追加。
  - [Suggestion] 字幕 overlay は `aria-live="off"` とし、装飾テキストとしての扱いを明確化するとアクセシビリティ事故を減らせます。

- **S3: `TakeStrip.svelte` 配線** → **APPROVE**
  - [Warning] 「ready のみ再生ボタン表示」は禁止事項8に抵触しないが、ユーザーには理由が見えません。  
    修正案: processing/uploading/failed 行に「再生可能になるまで待機」等の補助文言（または tooltip）を追加。
  - [Suggestion] `adoptFromPreview()` 成功時 close は良い。失敗時フォーカス維持（ボタンへ戻す）を加えるとモバイル操作性が上がります。

- **S4: 録画排他 / 資源解放結合** → **REQUEST_CHANGES**
  - [Critical] `recording` 状態通知を `start成功=true / onstop=false` のみで扱うと、`MediaRecorder` 初期化失敗・権限剥奪・トラック ended の経路で不整合が残る恐れ。  
    修正案: `recorder.onerror`、`stream track.onended`、例外 catch で `onRecordingChange(false)` を必ず通す finally 経路を設計に追加。
  - [Warning] `resumeAfterPreview()` の多重呼び出し（連打 close/open）で `getUserMedia` 競合の可能性。  
    修正案: `resuming` フラグで再入防止し、in-flight Promise 共有で二重取得を防ぐ。
  - [Suggestion] `bind:this` の型に `InstanceType<typeof CameraRecorder>` 相当の明示方針を決め、`any` 混入を避ける記述を追加。

- **S5: テスト計画** → **REQUEST_CHANGES**
  - [Critical] セキュリティ不変条件「権限判定で `laratrust_team_id` 明示」を直接担保する観点が不足。  
    修正案: Featureで「同一ユーザーが別team文脈では403、正しいteam文脈で302」を追加（team切替の明示）。
  - [Warning] IDOR は project/manual/cut mismatch を列挙済みだが、`take` mismatch（cut配下でないtake）を明記すると抜け漏れが減ります。  
    修正案: `.../cuts/{cutA}/takes/{takeB}`（BはcutB所属）で404を追加。
  - [Suggestion] vitest に「dialog close時 `onCameraResume` が必ず1回」を追加すると S3/S4 結合回帰を拾いやすい。

**横断観点（要点）**

- [Warning] **Inertia Props vs API Response** の使い分けは妥当（表示データは既存 props、再生は route 組み立て、操作は POST/302）。ただし設計書に「playback URL を payload に戻さない理由（署名URLをサーバ都度発行）」を1行追記すると整合が明確です。
- [Suggestion] **Atomic Design** は `features/capture` 内完結で良好。`TakePreviewDialog` が atoms/molecules へ逆流 import しないことを実装時チェック項目に入れると安全。
- [Suggestion] **DESIGN.md 準拠** は「overlay色はtoken使用」を明記済みで良い。クラス名例（token/ramp由来）を設計に1つ示すと実装ぶれを減らせます。

**全体判定**

- **CHANGES_REQUESTED**

主に S4 の録画状態遷移の失敗経路と、S5 の team 文脈セキュリティ検証を補強すれば、詳細設計としてかなり堅くなります。必要なら、この指摘を反映した「修正版テストマトリクス（Feature/vitestの具体ケース一覧）」まで整理します。