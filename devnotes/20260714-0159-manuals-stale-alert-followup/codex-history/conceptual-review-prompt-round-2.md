# 概念設計レビュー Round 2

Round 1 は APPROVED でしたが、[Warning] 4 件に対応/整理し概念設計を更新しました。
以下の対応で Warning が解消されたか確認し、全体判定（APPROVED / CHANGES_REQUESTED）を返してください。

## Warning への対応サマリー

### W1: テスト先行運用が未記載
→ 受け入れ条件に「Feature/Unit/Vitest を先に追加し red 確認 → 実装 → green」を明記した。

### W2: `job->updated_at` を terminal 時刻の代理にする前提が脆い
→ 現行スキーマに `failed_at`/`finished_at` は無く（両 job テーブルとも `timestamps()` のみ）、
`updated_at` が唯一の terminal 代理。かつ **failed job は terminal 後に更新されない**ことがコードで担保:
`failJob` は `status->isTerminal()` で早期 return（再 fail 不可）、stale 回復（`recoverStaleJobs`）は
queued/running のみ対象。この不変条件A「failed job は updated_at を更新しない」を Feature テストで固定する。

### W3: `job: null` は alert 抑制より影響が広い
→ Panel が failed job から参照するのは error 文言と（Preview の）error_code のみ。stale な失敗では
その両方が消えるべき情報。`scenario_version_changed` 失敗は変更が失敗の前に起きるため **not stale** で
CTA は保持される（下記 W4 と連動）。よって `job: null` で失うのは stale 失敗の alert/CTA のみで過不足なし。
`isStale`/`displayable` フラグの DTO 追加は shape/TS 型/client 分岐を拡大するため見送る。回帰テストで
「succeeded job / not-stale 失敗は null 化されない」を固定する。

### W4: cuts.updated_at を唯一信号にすると scenario_version だけ進む経路で壊れる
→ `scenario_version` は成功保存で常に +1 だが `cuts.updated_at` は実変更時のみ進む。version だけ進む経路は
**no-op 保存（内容無変更）** で、この場合シナリオ内容は失敗時と同一なので直近の実失敗を出し続けても
矛盾しない（抑制対象外で妥当）。さらに **timestamp 比較は version 比較より正しい**: render の
`scenario_version_changed` 失敗は「シナリオ変更 → 検知して失敗」の順で、変更は失敗の**前** →
timestamp なら not stale で作り直し CTA を保持。version 比較だと `manual.version > job.version` で
当該失敗も stale 化し CTA が消え退行になる。不変条件B「シナリオ内容の実変更は cuts.updated_at を進める」を
Feature テストで固定する。

（Suggestion 7-2: `displayRenderJob`/`displayPreviewJob` の返す job 種別を PHPDoc に明記した。）

## 質問
上記対応で W1–W4 は解消と判断してよいか。残る Critical/Warning があれば指摘してください。
特に W2 の不変条件A、W4 の timestamp>version の主張に論理的な穴があれば指摘を歓迎します。
