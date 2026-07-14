# 対応マトリクス: design-review Round 1

## [Critical] (c)/(d) 多デバイス再現の同一クライアント汚染（施策3）
- 判断: **対応（テスト設計を deterministic に再設計）**
- 根拠: `flushSession` + `withCookie` だけでは cookie jar / guard 状態の分離が不十分で偽陽性/偽陰性の
  恐れ。指摘は妥当。
- 対応内容: **device A の変更を「out-of-band の DB ハッシュ変更（guard を立てない）」**に変え、
  device B を「実ログインで得た session / recaller cookie の明示 replay」に限定することで、両デバイスの
  guard 状態を完全分離する。(c)/(d) は**施策1（AuthenticateSession の password_hash 照合 = correctness の
  要）を検証するテスト**と位置づけ、(a)+(b) が施策2 のオーケストレーションを検証する。end-to-end
  （実 PUT が別デバイスを失効させる）は「施策2 が hash を変える」→「施策1 が失効させる」の合成として
  (a)+(b)+(c) でカバーされる。
  - (c): device B を `/login` で実ログイン→ session cookie 値を login 応答から capture → user の
    password を **out-of-band で `forceFill` 変更**（guard に触れない）→ capture した session cookie
    のみを `->withCookie(config('session.cookie'), <value>)` で replay して `/dashboard` → `assertRedirect('/login')`。
  - (d): 同様に `remember: true` でログイン→ recaller cookie（`Auth::guard()->getRecallerName()`）を
    capture → out-of-band hash 変更 → **recaller cookie のみ** replay → `assertRedirect('/login')`。

## [Warning] (d) 暗号化 cookie 取り回しの不安定性 → backup を正式手順化（施策3）
- 判断: 対応（backup を暫定注記から第一級の代替手順へ昇格）
- 対応内容: recaller 実 cookie 経由が不安定な場合の代替として「**旧 `password_hash_web` を持つ session を
  明示投入し、`auth.session` が logout することを確認する統合テスト**」を具体手順として設計書に記載
  （session 行を実ログインで用意 → out-of-band hash 変更 → 同 session cookie replay で logout 確認）。

## [Warning] (a) の命名と意図の逆転（施策3）
- 判断: 対応
- 対応内容: `victim-current` を廃し、**実際の `session()->getId()` と一致させた現在行（残存検証）**と、
  別 id の `attacker-session`（削除検証）、別ユーザーの `other-user-session`（残存検証）に整理。
  現在行の残存を検証するため、現在 session id を取得して行を用意する手順を明記。

## [Warning] `session()->getId()` 依存に isStarted ガードを（施策2）
- 判断: 対応
- 対応内容: `deleteOtherSessionRecords` 先頭に `if (! session()->isStarted()) { return; }` を追加。
  session 未初期化文脈（console/queue 等）での誤削除条件を防ぐ。driver ガードと合わせ二重に安全側。

## [Warning] 施策1 の Fortify 全経路の実影響検証観点が不足
- 判断: 対応（DoD に個別回帰チェックリストを追加）
- 対応内容: DoD に `login` / `two-factor-challenge` / `user/confirm-password` / SSO callback 後の保護
  ページ到達 / password reset / `actingAs` 系テストの個別 green 確認を明示チェックリスト化。

## [Suggestion] 施策2 の順序・PHPStan・best-effort は妥当 / DTO 非該当妥当 / UI 非該当妥当
- 判断: 反映（変更不要、設計の正しさを Codex が追認）
