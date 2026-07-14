# 概念設計レビュー Round 3

Round 2 で唯一残った Warning（期待効果の文言矛盾）を修正しました。

## [Warning] 4. 期待効果の文言矛盾 → 修正
「使命への貢献」を以下に変更しました:

> self-disable が許可される**非 enforced 組織**において、セッション侵害から認証境界と現場作業者の標準化マニュアル資産を守る。2FA 必須組織については既存の 422 拒否（`BlockTwoFactorDisableForEnforcedOrganizations`、recent-auth より先に走る）を**維持し後退させない**（本変更による改善ではなく、既存防御と衝突しないことを保証する）。

これで enforced org を「本変更の改善効果」と誤記していた矛盾は解消され、効果の射程が正確になったと考えます。

残る Critical/Warning があれば指摘してください。なければ APPROVED をお願いします。

## 該当箇所（修正後の「期待効果」節全文）

- **セキュリティ不変条件の回復**: password 再入力または再SSO を伴わない**単独セッション侵害だけでは 2FA を無効化できなくなる**（step-up を強制）。姉妹操作 `organizations.members.two-factor.reset`（他人の 2FA 解除）と同一基準に揃い、「自分の 2FA 解除だけ無防備」という非対称を解消。
- **使命への貢献**: self-disable が許可される**非 enforced 組織**において、セッション侵害から認証境界と現場作業者の標準化マニュアル資産を守る。2FA 必須組織については既存の 422 拒否（`BlockTwoFactorDisableForEnforcedOrganizations`、recent-auth より先に走る）を**維持し後退させない**（本変更による改善ではなく、既存防御と衝突しないことを保証する）。
- **UX 一貫性**: disable も regenerate/API キー失効と同じ再認証モーダル導線になり、SSO-only ユーザーも fail-closed で再SSO に誘導され詰まない。
