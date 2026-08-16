全体判定: **CHANGES_REQUESTED**

**使命との整合性**
- [Warning] 改善意図は North Star に整合しています。共有端末・現場作業者・撮影中のアカウント確認という文脈は、「専門知識ゼロ」「思考ゼロ」に実質的に寄与します。
- [Suggestion] 「作らない」案も成立しますが、本設計は G1/G2 を明確に切り分けており、専用画面を作る判断は合理的です。ただし G3 をスコープ外にするなら、新画面自身の復路設計をもっと厳密にする必要があります。

**禁止事項違反**
- [Warning] 現時点の設計では禁止事項への直接抵触は見えません。`router.post("/logout")` を使う方針、`fetch/axios` を避ける方針、disabled の扱いも妥当です。
- 修正提案: `loggingOut` による disabled が既存 `AppLayout` と同じ再送防止であることを実装コメントまたはテスト名で明確にし、禁止事項 8 の回避ではなく「送信中状態」として扱ってください。

**実現可能性**
- [Critical] `/app/account` は route parameter を持たない一方で、「撮影一覧へ戻る」を `/app/projects/{project}/manuals` としています。Account 画面がどの `{project}` を使うのかが設計上未定義です。これでは成功条件 1 を満たせません。
- 修正提案: 次のいずれかに設計を固定してください。
  - `/app/projects/{project}/account` にして project context を route binding で持つ。この場合は nested route IDOR inventory 登録が必要。
  - `/app/account?return_to=...` は open redirect・権限検査の論点が増えるため避けるか、同一オリジンかつ `/app/projects/{id}/manuals` のみ許可する。
  - 「戻る」は `history.back()` 主体にし、fallback を `/app` にする。ただし standalone での期待挙動をテスト対象にする。
- [Warning] 「/app/projects/{id}/manuals から 1 タップで到達」とありますが、どの UI に導線を置くかが未定義です。
- 修正提案: `Capture/Index.svelte` と `Capture/Show.svelte` のどちらに、どの component で導線を置くかを明記してください。共有 `AppLayout` のユーザーメニューに入れるなら、案 B を退けた理由との整合を再説明する必要があります。

**期待効果の妥当性**
- [Warning] G1/G2 の効果は妥当です。一方で、G3 を本体スコープ外にしたまま `/settings` への副導線を置くと、結局「戻れない PC 設定画面へ出る」問題を残します。
- 修正提案: `/settings` への副導線は、撮影 PWA では二次的なリンクとして弱く出す、または確認ダイアログなしでも「戻り先を失う」ことを避ける設計にしてください。少なくとも成功条件には `/settings` 遷移後の復路を含めない、と明記するとよいです。

**リスク**
- [Warning] `/app/account` を `require-active-subscription` 内に置く判断は筋が通っていますが、退職・停止・未契約時に「ログアウトだけしたい」利用者が `/app/account` に入れない可能性があります。既存ドロワーからログアウトできるなら致命傷ではありませんが、専用画面の価値は課金ゲート中に限定されます。
- 修正提案: 「未契約時のログアウトは既存 AppLayout または認証画面側で担保済み」と設計に明記してください。もし遮断画面が AppLayout を使わないなら、ログアウト導線の所在を別途確認してください。

**スコープの適切さ**
- [Warning] 1 画面追加は小さく見えますが、実際には route、page、導線、logout inventory、supported-browsers 文書、bug-hunt inventory、場合によっては nested route inventory まで触ります。スコープは過大ではないものの、「1 route 足すだけ」という表現は少し軽く見積もっています。
- 修正提案: 実装スコープを「route + page + 導線 + JS architecture inventory + docs + bug-hunt inventory + feature/browser test」に更新してください。

**型安全性**
- [Suggestion] 新しい DTO を増やさず共有 Inertia props を使う判断は妥当です。JsonResource/DTO が必要な JSON endpoint ではなく Inertia page なので、禁止事項 4 には抵触しません。
- [Warning] `currentOrganization.name` を表示する前提なら、型上 nullable でないかを確認する必要があります。nullable なら Svelte 側で表示不能時の扱いを型安全に決めてください。
- 修正提案: `Account.svelte` の props は既存 `SharedProps` 型に寄せ、`auth.user.email` と `currentOrganization` の nullability を明示的に処理してください。

結論として、**作る判断自体は承認可能な方向**です。ただし、現設計は「どこから入るか」と「どう戻るか」の中核が未確定です。そこを固定しないまま実装に入ると、PWA 現場導線の改善ではなく、孤立した画面追加になります。