# 概念設計レビュー Round 2

Round 1 の指摘への対応を報告します。対応マトリクスと概念設計の追記のみ記載します
(使命・禁止事項は Round 1 で提示済み)。

## Round 1 指摘への対応

### [Critical] 保護挙動を Feature テストで固定
→ 対応。概念設計に「テストマトリクス（必須・設計へ昇格）」節を新設。以下 6 ケースを必須スコープに固定:
1. stale + email 変更 → 遮断 (409/302)、email 未変更
2. stale + 氏名のみ変更 → 成功 (gate されない)
3. fresh + email 変更 → 成功 (更新 + 旧アドレス通知 + email_verified_at null)
4. remember-me 復元直後 (viaRemember, 未 stamp) + email 変更 → stale 扱いで遮断
5. fresh + email 欠落/非 string → gate されず Validator 422
6. fresh + email 変更 (回帰) → 旧アドレス通知 + email_verified_at null 化

### [Warning] email 同一性判定の正規化・欠落・型不正
→ 対応。「email 同一性判定契約」節を新設。
- action の early-return 条件 (`$email === $user->email`) と**完全に同一の raw 文字列比較**を
  middleware でも使用し、正規化ドリフト由来の bypass を構造的に排除。独自 trim/lowercase は入れない。
- case-only/whitespace 差は action が「変更」として扱う (旧アドレス通知を送る) ため middleware も gate。
  一貫性を担保。

### [Warning] invalid/missing email でも先に再認証を求める UX
→ 対応。契約を「submitted email が **is_string かつ** 現行値と異なる時のみ gate」に確定。
欠落/非 string は gate せず action へ流し Validator 422 に委ねる。非 string は email 変更を
起こせない (validation が弾く) ため fail-safe は維持 (bypass 不可)。

### [Warning] 型安全性 (input() の mixed)
→ 対応。submitted email は `is_string` narrowing で `?string` として取得。比較は bool を返す薄い
private メソッドに閉じ込め。応答生成は委譲先 `RequireRecentAuth` の `RecentAuthRequiredResource`
(JsonResource) に一本化 (新 middleware は独自 JSON を作らない)。

### [Suggestion] 判定ロジックを小クラスへ
→ 一部採用。private メソッドに閉じ込め middleware を薄く保つ。専用クラス新設は今回のロジック規模
(is_string + 1 比較) では過剰なため見送り (over-engineering 回避)。

## 更新後の該当節（抜粋）

### email 同一性判定契約
- 抽出: `is_string($request->input('email')) ? {文字列} : null` で `?string`。
- gate 条件 (全て満たす時のみ委譲): (1) is_string、(2) submitted `!==` `$user->email`。
- 欠落/非 string: gate せず Validator 422 に委ねる (fail-safe 維持、bypass 不可)。
- case-only/whitespace 差: `!==` で「変更」判定 → gate (action と一貫)。

### テストマトリクス（必須）
上記 6 ケースを Feature テストで固定。加えて Architecture テスト
(`RecentAuthRouteTest` allowlist に `user-profile-information.update` 追加) で付与を CI 固定。

---

以上の対応で Critical/Warning は解消できていますか。追加の懸念があれば指摘してください。
全体判定 (APPROVED / CHANGES_REQUESTED) を明示してください。
