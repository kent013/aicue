全体判定: **CHANGES_REQUESTED**

設計方向は AG-113 / AG-079 の裁定、North Star、Laravel + Inertia の実装レンジに概ね合っています。ただし、受諾サービスの戻り値・再検証責務が本文内で揺れており、ここは実装前に固定が必要です。

**使命との整合性**
[Suggestion] アプリ内通知から受諾まで進める導線は、現場ユーザーのオンボーディング摩擦を下げるため North Star に実質的に貢献します。特に PWA 中心の利用では「メールを探す」依存を減らす効果は合理的です。

**禁止事項違反**
[Warning] 変更系 POST に対する認可の扱いが少し曖昧です。`ControllerAuthorizationGateTest` に `SelfScopedResource` 登録するとありますが、実装で `Gate::authorize()` を通さないなら「なぜ self-scoped として exemption できるのか」を 30 字以上の理由だけでなく、controller の解決が `activePendingForEmail(auth user email)` に閉じていることまで明記してください。

修正提案: 実装方針に「`accept-in-app` は Gate ではなく self-scoped exemption。自己スコープ性は `auth user` + `verified` + `activePendingForEmail()` + 一律 404 で担保」と明記する。

**実現可能性**
[Critical] `joinOrganization()` の戻り値が本文内で矛盾しています。概要では「`bool` を返すよう変更」、核心では「例外を投げず `?Organization` を返す」とあります。controller の redirect 先や flash、Feature テストの期待値に直結するため、概念設計として未確定です。

修正提案: `acceptInApp(User $user, string $invitationId): ?Organization` のように新 public メソッドを切り、既存 `joinOrganization()` は可能なら責務を「ロック下で変換する内部処理」に寄せる。呼び出し側が参加先 organization を必要とするなら `?Organization`、不要なら `bool` に統一してください。

[Warning] 「受諾解決と件数・一覧で同一 scope を再利用」は正しいですが、受諾時は一覧で見えた invitation をそのまま信用せず、**transaction + lock 下で同じ条件を再評価**する必要があります。

修正提案: `acceptInApp()` の仕様に「transaction 内で `activePendingForEmail($user->email)->whereKey($id)->lockForUpdate()->first()` し、見つからなければ `null`」を明記する。

**期待効果の妥当性**
[Warning] 「期限切れなら第 2 経路が保険になる」という表現は過大です。新経路も `activePending` 前提なので、期限切れ招待は受諾できません。改善できるのは「メールが見つからない」「メール URL を踏みにくい」「転送 URL より本人性が強い」点です。

修正提案: 期待効果から「期限切れでも受諾できる」ように読める表現を外し、「期限切れなら一覧から消え、再招待導線へ誘導できる」に直す。

**リスク**
[Warning] 通知 payload に invitation id を持たせない判断は妥当ですが、通知クリック時の flash 文言に注意が必要です。id が無い以上、「この招待は現在有効ではありません」と断定すると、別の有効招待がある場合に意味がずれます。

修正提案: `open()` は active count が 0 なら「現在有効な招待はありません」、1 件以上なら「有効な招待を確認してください」程度の集合表現にする。

[Warning] email blind index が大小文字完全一致である点は fail-secure ですが、UX 上は「同じメールに見えるのに一覧に出ない」ケースが残ります。今回はスコープ外でよいものの、既存 token 経路へ自然に逃がす説明が必要です。

修正提案: docs に「大小文字差分ではアプリ内受諾は 404/空一覧、メール token 経路は既存仕様に従う」と明記する。

**スコープの適切さ**
[Suggestion] `project_role` 撤去を同一作業単位に含める判断は妥当です。同じ model/service にまたがるため、二重変更よりリスクが低いです。一方で Default Project 全撤去をスコープ外にした判断も適切です。

**型安全性**
[Warning] DTO 方針は良いですが、`PendingInvitationForUserDto` の開示項目を概念設計で固定してください。`OrganizationInvitation` model をそのまま Inertia props に流すと、CipherSweet email や token hash、状態列の開示面が広がります。

修正提案: DTO は最低限 `id`, `organizationName`, `roleLabel`, `expiresAt`, `acceptUrl` 程度に限定し、`email`, `token_hash`, 内部 status は出さない契約を明記する。

結論として、方向性は承認可能に近いです。実装前に `joinOrganization()` / 新受諾メソッドの戻り値と、ロック下再解決の仕様を一本化してください。そこが固まれば APPROVED 相当です。