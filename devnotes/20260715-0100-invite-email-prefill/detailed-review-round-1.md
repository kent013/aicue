以下、提示された詳細設計のみを対象にレビューします（実装・実行は未実施）。

**施策別判定**

### S1: active 招待の単一解決口を model に集約  
**判定: APPROVE**

- [Suggestion] `findActiveByPlainToken()` の責務分離は妥当で、`MatchesInvitationEmail` と `acceptInvitationIfValid()` のドリフト防止に有効。
- [Suggestion] `scopeActive` と `isExpired()` の境界差（`>` vs `isPast()`）は本設計内の説明どおり実害は低いが、将来の混乱防止のため `OrganizationInvitation` に「active定義の単一コメント」を残すとより堅い。
- [Suggestion] メソッド名は意図明確で既存命名とも整合。PHPStan L10 的にも `?self` 返却は適切。

### S2: register prefill resolver（session stale token 破棄付き）  
**判定: APPROVE**

- [Warning] `Assert::string($email)` は静的解析上は有効だが、実行時に想定外型なら 500 になる可能性がある。  
  **修正案:** fail-secureを徹底するなら `if (!is_string($email) || $email === '') { $session->forget('invitation_token'); return null; }` に寄せ、アサート依存を減らす。
- [Suggestion] セッション汚染値を GET 時点で `forget` する方針は「stale token fail-secure」として一貫しており良い。
- [Suggestion] tenantキー不信・PII要件にも抵触せず、token_hash 解決のみで完結している。

### S3: Fortify registerView props に `invitationEmail` 追加  
**判定: APPROVE**

- [Critical] `registerView` で毎回 service 解決し、PII を props で返すため、**キャッシュ層/共有ログへの混入対策**が明示されていない。bearer前提の残余リスク受容は妥当だが、運用面のfail-safe記述が不足。  
  **修正案:** 設計書に「`/register` 応答は private/no-store（少なくとも共有キャッシュ不可）」を明記し、ミドルウェアまたは既存ヘッダポリシーとの整合確認項目を追加。
- [Suggestion] Inertia props での返却は既存 register 実装と整合し、DTO/Resource の過剰導入回避として妥当。

### S4: Register.svelte に prefill + readonly 描画  
**判定: APPROVE**

- [Warning] `invitationEmail` を初期値注入後、クライアント側から devtools 改変で POST される可能性は残る（readonlyはUX制約のみ）。  
  **修正案:** 設計書に「真正性担保は既存 `MatchesInvitationEmail`（サーバ側）で行う」ことを明示し、フロントは“誘導”であると位置づける注記を追加。
- [Suggestion] DESIGN.md #8（disabled禁止）との整合は良好。readonly採用は要件適合。
- [Suggestion] Input atom の rest 透過を使う設計は Atomic Design 的に正しい。

### S5: テスト（Feature + JS）  
**判定: REQUEST_CHANGES**

- [Critical] 「active prefill 後、POST前 revoke」のケースは非常に重要だが、**現行設計だと Fortify 登録完了後の副作用（ログイン状態や組織選択）までの検証観点が不足**。  
  **修正案:** Feature テストに「招待組織へは参加していない」「個人組織作成済み」に加え、`current_organization_id` が個人組織側であることを明示アサート。
- [Warning] `socialProviders` 非退行を1ケースだけで見ると弱い。  
  **修正案:** token有無の2系統で `socialProviders` props 存在を確認し、registerView変更の副作用を抑える。
- [Suggestion] stale token forget検証は excellent。`DB不在 token` と `非文字列 token` を入れている点は特に良い。
- [Suggestion] JS側は readonly と文言だけでなく、`invitationEmail` 未指定時の初期値空文字も明示すると回帰に強い。

**全体判定**

**CHANGES_REQUESTED**

- S1〜S4 は概ね妥当で、アーキテクチャ整合・セキュリティ不変条件（特に #1/#6）にも沿っています。  
- ただし、S3のPII返却に伴うキャッシュ/漏えい運用の明文化不足、S5の副作用アサート強化不足はリリース前に埋めるべきです。  
- 上記 Critical/Warning を反映できれば、再レビュー時は **APPROVED** 相当です。