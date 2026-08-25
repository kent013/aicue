# 対応マトリクス: design-review Round 2

## [Warning] 施策 A: 1c の lockForUpdate がテストで固定されていない
- 判断: 対応する
- 根拠: 指摘どおり — one-shot 注入は同一接続・同一 tx 内の自己更新なので、1c を非ロック
  読みへ退行させても自 tx の更新が見えて緑になる。ロック読みであること自体を別途固定する
  必要がある。
- 対応内容: テスト計画へ「1c のロック読みの SQL 形状固定」を追加 — 注入後に実行される
  organizations への問い合わせを記録し、対象 organization id の問い合わせが
  (organizations 対象 / deleted_at is null 相当 / for update / bindings に org id) を満たす
  ことを assert。手法は InvitationAcceptRaceTest と同じ SQL 小文字化 + bindings 照合で、
  新しい静的 scanner は増やさない。保証範囲の docblock は 3 分割 (状態注入 = 畳み込みの契約 /
  SQL 形状 = ロック読みであること / 保証外 = 別接続の MVCC スケジュール再現) に書き分けた。

## [Suggestion] 施策 A: 注入タイミングの説明の正確化
- 判断: 採用
- 対応内容: 「決定的再現」の語を落とし「最終再検証の消費契約 (状態注入)」へ改題。
  docblock 方針にも「注入時点では組織行ロック取得済みのため実際の競合順序の再現ではない —
  消費契約の決定的検証であって競合の再現と表現しない」を明記。

## [Suggestion] 施策 B: 単引用符復元器の組み合わせケース
- 判断: 採用
- 対応内容: IC-2 自己検査へ「`\\` と `\'` が隣接する入力」の 1 件を追加 (置換順の誤復元防止)。

## [Warning] 施策 C: followRedirects では「経由しない」を証明できない
- 判断: 対応する
- 対応内容: 一段ずつの検査へ差し替え — (1) 登録 POST → app.entry、(2) app.entry GET →
  招待組織 dashboard へ**直接** redirect (assertRedirectToRoute で route + slug を固定)、
  (3) dashboard GET → 200。中間に verification.notice が挟まれば 2 段目で赤になる。
  JSON 201 ケースには membership 行と email_verified_at 非 null の assert を追加し
  偽グリーン (通常登録の偶然の 201) を排除。

## 施策 B — APPROVE (指摘なし。Suggestion のみ上記で採用)
