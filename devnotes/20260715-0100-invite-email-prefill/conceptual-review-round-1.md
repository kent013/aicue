全体判定: **CHANGES_REQUESTED**

**1. 使命との整合性**
- [Suggestion] 招待経由登録の摩擦除去は、組織参加までの歩留まり改善として North Star に整合しています。特に「現場チームを標準化された撮影運用に乗せる導線」を改善する点は妥当です。
- [Suggestion] ただし効果の射程は「オンボーディング摩擦の低減」であり、動画シナリオ生成や PWA 撮影の本丸に直接効く改善ではありません。設計書の効果記述はその粒度に留めるのが正確です。

**2. 禁止事項違反**
- [Warning] テスト追加の粒度が不足しています。`tests/js/pages/Register.test.ts` だけでは「実装済み」と言えません。  
  修正提案: 少なくとも Feature テストで `active token -> invitationEmail props あり`、`expired/revoked/accepted token -> props なし or session clear`、`通常登録非退行`、`SSO 表示非退行` を固定してください。

**3. 実現可能性**
- [Suggestion] Laravel 12 + Fortify + Inertia + Svelte 5 での実装自体は十分可能です。`registerView` で session から resolver を呼び、props を渡す構成は自然です。
- [Warning] `readonly` 採用は、実際に使っている `Input` コンポーネントが native input に `readonly` を透過していることが前提です。ここが崩れると設計意図が成立しません。  
  修正提案: `Input` atom の prop 透過有無を前提条件として明記し、未対応なら atom 側の対応もスコープに含めてください。

**4. 期待効果の妥当性**
- [Warning] 「email 不一致 422 を構造的に排除」は言い過ぎです。手入力起因のミスは減らせますが、SSO 側のメール不一致や token の stale 化では依然として失敗し得ます。  
  修正提案: 効果表現を「主経路の手入力ミスを削減」に下げ、SSO/stale token の失敗ハンドリングを別途設計に入れてください。

**5. リスク**
- [Critical] GET 時の `invitationEmail` 判定と POST 時の `invitation_token` の扱いが一致していません。設計では GET で active 再判定して `null` を返せますが、session 内 token を残したままだと、UI は通常登録に見えるのにサーバは招待フロー扱いする不整合が起こり得ます。  
  修正提案: active でないと判定した時点で `invitation_token` を session から破棄する契約を明示してください。あわせて stale token の Feature テストを追加してください。
- [Warning] `readonly` の根拠として「別 email で登録したければ通常 `/register` を開けばよい」は、現行の session モデルと整合していません。招待リンク経由で token が session に残るなら、同じブラウザで `/register` を開いても再度 lock されるはずです。  
  修正提案: `invitation_token` を明示的に捨てる「通常登録へ切り替える」導線を設けるか、「招待リンク経由では別メール登録不可」を明文化してください。

**6. スコープの適切さ**
- [Suggestion] 機構の再設計に踏み込まず、register view と UI に絞っている点は適切です。
- [Warning] ただし実質的には session token の破棄規約と stale 時の UX まで決めないと閉じません。そこを「スコープ外」にすると中途半端です。  
  修正提案: stale token 処理だけは本スコープ内に含めてください。

**7. 型安全性**
- [Suggestion] Inertia props を plain array で返す方針自体は妥当です。ここで DTO を強制する必要はありません。
- [Warning] ただし resolver を「薄い read-model」として別実装すると、`MatchesInvitationEmail` / `acceptInvitationIfValid()` と判定条件がドリフトする危険があります。  
  修正提案: `active invitation を token から解決する単一の問い合わせ口` を共有化し、戻り値を `?OrganizationInvitation` か `?string` で厳密に型付けしてください。

**8. セキュリティ判定の妥当性**
- [Critical] 「新たな漏洩なし」という判定は妥当ではありません。現状は token 保持者に招待先 email の**平文そのもの**は返していませんが、この設計は Inertia props で exact email を返します。`token 保持者 = 招待相手本人` という前提は、転送リンク・共有端末・メール誤送信・覗き見で崩れます。これはメール列挙というより、**有効 token 所持者への PII 開示面の追加**です。  
  修正提案: 「漏洩なし」という主張は撤回し、`有効 token 所持者には招待先 email を表示する` というリスク受容を明文化してください。もしその受容を避けるなら、exact email を返さない別 UX に設計変更が必要です。
- [Warning] token 検証の方針は概ね妥当ですが、view 側だけ独自に active 判定を持つのは危険です。  
  修正提案: `sha256(token)` + active 判定 + fail-secure を既存登録処理と同じ解決器に寄せてください。`whereBlind()` や平文 email 検索を追加しないことも明記すべきです。
- [Warning] `readonly` 採用自体はサーバ契約と整合しており妥当です。ただし「ユーザーの選択肢を減らしても問題ない」という結論は、token 破棄導線が無い限り強すぎます。  
  修正提案: `readonly` は維持してよいですが、招待フロー解除手段を設計に含めてください。

この設計は方向性自体は良いですが、現状のままでは **セキュリティ判定が楽観的すぎる** のと、**stale token 時の契約が閉じていない** のが承認不可の理由です。そこを補正すれば再レビュー可能です。