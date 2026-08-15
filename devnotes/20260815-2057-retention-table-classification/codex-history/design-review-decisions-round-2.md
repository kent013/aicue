# 対応マトリクス: design-review Round 2

## [Warning] RC-7 が外部キーの存在だけを見ると `on delete set null` を誤検出する
- 判断: **対応する** (指摘が正しい。設計の誤りだった)
- 根拠: 実物で裏が取れる。`llm_call_logs` は `organization_id` / `user_id` を
  `nullOnDelete()` で、`security_audit_events` は `user_id` を `nullOnDelete()` で持っており、
  **組織削除・退会の後も行は残る**。ここを一律違反にすると「残る表を親と一緒に消えると
  偽って分類する」方向へ検査が働き、事実と逆になる。
- 対応内容: RC-7 を `on delete` の動作別に定義し直した。
  - `cascade` = 子も消える → 矛盾 (違反)
  - `restrict` / `no action` = 親を消せなくする = 親の期限の執行を止める → 違反
  - `set null` = 子は残る → **違反にしない**
  - `set default` = 意味が既定値の指す先に依存する。本リポジトリに 1 本も無いため、
    現れたら分類の見直しが要るものとして保守的に違反へ倒す (根拠を設計に明記)
  - 取得できない (`null`) = 未知 → 保守的に違反へ倒す
  純関数の docblock に上の 5 行をそのまま書く。

## [Suggestion] NC-4 を cascade / restrict にし、`set null` の正のコントロールを足す
- 判断: 対応する
- 根拠: 「外部キーがあれば全部赤」に退化しても、負のコントロールだけでは気付けない。
  境界の両側を固定して初めて検査の意味が定まる。
- 対応内容: NC-4 を `cascade` (と `restrict`) で点灯させる形にし、
  **同じ参照を `set null` にすると点灯しない**正のコントロールをテスト計画へ追加した。

## [Warning] 施策 5 (運用文書) に `on delete` の扱いを反映する
- 判断: 対応する
- 対応内容: docs へ書く内容に「RC-7 は一律禁止ではなく、親が消えたときに子がどうなるかで
  判断する」ことと動作別の一覧を追加し、`llm_call_logs` / `security_audit_events` の
  実例をそのまま書く指示にした。

## [Suggestion] AGENTS.md では RC-7 の条件を書かず architecture へ委譲する
- 判断: 対応する
- 根拠: 条件を 2 か所に書けば必ず食い違う (本リポジトリが既に採っている方針)。
- 対応内容: 規約の骨子に「外部キーをどう読むかは規約本文に書かず正本へ委譲する」と明記した。

## [Suggestion] `hasHorizon()` の docblock に「削除期限の実在を保証する述語ではない」
- 判断: 対応する
- 対応内容: enum の docblock に 1 段落追加した。

## [Suggestion] `entries()` の PHPDoc を `list<RetentionTableEntry>` に固定
- 判断: 対応済み (Round 1 で反映済み)

## [Suggestion] `retentionForeignKeyMap()` の「必要な区分だけ照会する」旧記述の削除
- 判断: 対応済み
- 根拠: Round 1 の修正時点で「1 度だけ組み立てて使い回す」に書き換えており、
  旧記述は設計書に残っていないことを確認した (`grep` で 0 件)。

## [Suggestion] RC-5 の失敗理由をクラスとコマンドで分けて集める
- 判断: 対応済み (実質)
- 根拠: 骨格の収集は既に `'… => class '.$ownerClass` / `'… => command '.$ownerCommand` と
  接頭辞を分けており、失敗メッセージから欠けている側が読める。
  2 本のテストに割るのは失敗の粒度が細かくなるだけで、直す作業は同じである。
