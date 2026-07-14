# 概念設計レビュー Round 3

Round 2 の指摘 (必須修正) への対応を報告します。

## Round 2 指摘への対応

### [Warning/必須] ケース5を stale に変更
→ 対応。ケース5 を「**stale** + email 欠落/非 string → recent-auth 応答ではなく Validator 422」に変更。
stale で 422 が返ることで「middleware が gate をスキップした」分岐を確定できる
(誤って gate すれば 409/302 になり fail する)。

### [Warning] stale の期待値を request 種別ごとに分離
→ 対応。遮断ケースを 1a / 1b に分割:
- 1a: stale + email 変更 + **Inertia mutation** (X-Inertia + PUT) → 409 + `RecentAuthRequiredResource`
- 1b: stale + email 変更 + **通常リクエスト** (非 Inertia) → 302 → `recent-auth.confirm` + `url.intended` 保持

### [Warning] ケース3/6 の重複 → ケース6 を再認証後の再開 client テストへ置換
→ 対応。ケース6 を client テストへ置換 (stale 検出 → RecentAuthModal 再認証 → 更新再開で
編集済み name/email が失われず再送される)。旧アドレス通知・email_verified_at null 化の回帰は
ケース3が担う。

## 更新後のテストマトリクス（必須）

| # | 前提 | 送信内容 / 種別 | 期待 |
|---|------|---------|------|
| 1a | stale | email 変更 / Inertia mutation | 409 + RecentAuthRequiredResource。email 未変更 |
| 1b | stale | email 変更 / 通常リクエスト | 302 → recent-auth.confirm + intended 保持。email 未変更 |
| 2 | stale | 氏名のみ変更 | 成功 (gate されない) |
| 3 | fresh | email 変更 | 成功 + 旧アドレス通知 + email_verified_at null (回帰固定) |
| 4 | remember-me 復元直後 (viaRemember, 未 stamp) | email 変更 | stale 扱いで遮断 |
| 5 | stale | email 欠落/非 string | gate されず Validator 422 |
| 6 | stale → 再認証 (client) | email 変更 | RecentAuthModal 再認証後、編集済み name/email を失わず再送 |

加えて Architecture テスト (`RecentAuthRouteTest` allowlist に `user-profile-information.update` 追加)。

---

必須修正 2 点 (ケース5 の stale 化 / 409・302 分離) を反映しました。承認可能か判定してください。
全体判定 (APPROVED / CHANGES_REQUESTED) を明示してください。
