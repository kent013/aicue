# 対応マトリクス: conceptual-review Round 1

## [Critical] 保護挙動を Feature テストで固定（allowlist だけでは分岐破壊を検出できない）
- 判断: 対応する
- 根拠: finding が authz_bypass。条件付き middleware の分岐が壊れても Architecture テスト
  (付与有無のみ) では検出できないという指摘は正当。
- 対応内容: 概念設計に「テストマトリクス（必須）」節を新設し、最小マトリクスを設計へ昇格:
  - stale + email 変更 → 遮断 (409/302)
  - stale + 氏名のみ変更 → 成功 (gate されない)
  - fresh + email 変更 → 成功
  - remember-me 復元直後 (viaRemember) + email 変更 → stale 扱いで遮断
  - 旧アドレス通知 + email_verified_at null 化の回帰固定

## [Warning] email 同一性判定の正規化・欠落・型不正の扱いが実装者依存
- 判断: 対応する
- 根拠: 生値比較の曖昧さ指摘は正当。ただし正規化を独自導入すると action 側 (raw `===`) と
  ドリフトし、middleware=同一/action=変更 の bypass を生む恐れがある。
- 対応内容: 「email 同一性判定契約」節を新設。**action の early-return 条件
  (`$email === $user->email`) と完全に同一の raw 文字列比較**を middleware でも使うことで
  ドリフトを構造的に排除する、と明文化。case-only/whitespace 差は「action が変更として扱う」ため
  middleware も gate する（一貫）。独自の trim/lowercase 正規化は導入しない。

## [Warning] invalid/missing email でも先に再認証を求める UX
- 判断: 対応する（契約に反映）
- 根拠: 欠落・型不正 email は validation が弾き email 変更は発生し得ない → gate する
  セキュリティ価値が無く、UX は「再認証」より「入力エラー」を先に見せる方が良い。
- 対応内容: 契約を「submitted email が **is_string かつ** 現行値と異なる時のみ gate。
  欠落/非 string は gate せず action へ流し Validator の 422 に委ねる」に確定。
  非 string は email 変更を起こせないため fail-safe は維持される（bypass 不可）。

## [Warning] 型安全性: input() ベースは mixed を持ち込む（PHPStan L10）
- 判断: 対応する
- 対応内容: submitted email は `?string` を返す専用抽出（`is_string` narrowing）で取得し、
  比較は bool を返す薄い private メソッドに閉じ込める。応答生成は委譲先 `RequireRecentAuth` の
  `RecentAuthRequiredResource`(JsonResource) に一本化（新 middleware は独自 JSON を作らない）。

## [Suggestion] 判定ロジックを小クラスへ / middleware を薄く
- 判断: 一部採用
- 対応内容: 判定は private メソッドに閉じ込め middleware を薄く保つ。専用クラス新設までは
  今回のロジック規模（is_string + 1 比較）では過剰なため見送り（over-engineering 回避）。
