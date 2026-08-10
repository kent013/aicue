# 実査ブリーフ: 遮断時のメッセージに元操作の文脈を持たせる (F-4-01)

> bug-hunt run `20260811-003230` の finding F-4-01 (**High**) に対応する。
> 統合レポート: `devnotes/20260811-003230-bug-hunt/report.md`
> シャード詳細: `devnotes/20260811-003230-bug-hunt/shard-4/shard-report.md#F-4-01`

## 実ブラウザで確認された症状 (再現済み)

2FA 必須組織の**未準拠**メンバーが猶予期間中に「**退会を取り消す**」を押すと、
`DELETE /settings/account/deletion-request` が `RequireTwoFactorForEnforcedOrganizations` に捕まり
(`ALLOWED_ROUTE_NAMES` に無い)、`/settings/security` へ
**汎用の「2 要素認証の設定が必要です」だけ**でリダイレクトされる。

**退会にも取り消しにも一言も触れない**ため、ユーザーは取り消せたのか判断できない。**実際には取り消せていない**
(予約は生きたまま)。

- species: `ux_dead_end:account_deletion_request:delete:self` / oracle: `H1` (説明なしリダイレクト)
- **永久の詰みではない**: 2FA を完了すれば取消できることをシャードが実機で確認済み。

## 阻害されているユーザージョブ

**誤って予約した退会を取り消す**。これは猶予期間 (30 日) を設けた目的そのものである。
「取り消したつもりで取り消せていない」状態は、猶予期間の価値を損なう。

## 既知情報との関係 (重要)

`docs/architecture.md` §退会の猶予期間つき削除「2FA 必須組織との相互作用」が、
**この遮断自体は設計として記述している** (取消 DELETE は 2FA ゲートが `settings.security` へ倒す)。

ただし **aicue:T142 で修正されたのは「`settings.security` 自体への到達性」**であり、
本 finding は**その一段先の「遮断されたことがユーザーに伝わらない」**という別論点である。
文書は「メッセージが元操作を伝えない」ことについては何も言っていない。

## 設計で決めるべきこと

1. **メッセージに文脈を持たせるのか、allowlist に入れるのか**。
   - (a) 遮断時のメッセージに元操作を含める (「退会の取り消しには 2 要素認証の設定が必要です」)
   - (b) 取消 DELETE を `ALLOWED_ROUTE_NAMES` に入れて通す
   (b) は 2FA 必須の趣旨 (未準拠者に業務をさせない) との整合を要判断。
   **取消は業務ではなく救済**なので通してよいとも言えるが、**設計者が根拠を示して決めること**。
2. **一般化するか、この 1 route だけ直すか**。同じ「遮断されたが理由が元操作と結びつかない」問題は
   **他の middleware でも起きうる** (凍結 middleware / 課金ゲート / recent-auth)。
   **今必要なものだけ作る** (思考原則 2) を守りつつ、一般化する価値があるかを判断する。
3. **再発防止を機械で守れるか**。「遮断する middleware は元操作の文脈を持つ」ことを検査できるか。
   自然言語のメッセージなので難しい可能性が高い。**できないなら「保証しないもの」に正直に書く**。

## 読むべき現行コード

- `app/Http/Middleware/RequireTwoFactorForEnforcedOrganizations.php` (`ALLOWED_ROUTE_NAMES` と遮断時の redirect)
- `app/Http/Middleware/EnsureAccountNotPendingDeletion.php` と `App\Enums\...\AccountDeletionFreezeAllowance`
  (凍結側は取消を通している = ゲート同士で判断が食い違っている)
- `bootstrap/app.php` の priority list (2FA ゲートが凍結より先に走る)
- `docs/architecture.md` §退会の猶予期間つき削除 (凍結方式・30 日)

## やらないこと

- **2FA 必須化の仕組みそのものを緩めない**。
- 凍結方式 (users 行の生死を変えない) の設計は変えない。
