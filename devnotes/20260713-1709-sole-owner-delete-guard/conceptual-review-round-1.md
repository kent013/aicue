**全体判定**  
CHANGES_REQUESTED

**1. 使命との整合性**
- [Suggestion] 方向性は妥当です。Owner 不在で組織運用が止まると、招待・権限管理・課金管理が詰まり、結果として現場でのマニュアル運用継続性を壊すため、North Star の土台を守る改善になっています。

**2. 禁止事項違反**
- [Warning] 設計文面上は禁止事項に抵触していませんが、`ValidationException` を返すだけで UI 側の表示仕様が弱いと、実質的に「押しても理由が見えない」状態になります。禁止事項8は `disabled` 禁止だけでなく、押下後に理由を理解できることが重要です。  
  修正提案: `DELETE /settings/account` の失敗メッセージを DangerZone 内に必ず表示する仕様を追加してください。エラーバッグキーを固定するか、`useForm` 相当でエラーを明示描画する前提まで書くべきです。
- [Suggestion] `response()->json()` を使わず Inertia props と通常のバリデーションエラーで閉じる方針は既存規約に合っています。

**3. 実現可能性**
- [Critical] 削除可否判定と `user->delete()` が同じロック境界で直列化されていないため、並行更新で不変条件を破れます。例えば判定時点では Owner が 2 人いて通過し、その直後にもう 1 人が降格/削除され、その後このユーザーが削除されると Owner 0 人が成立します。  
  修正提案: 判定と削除を同一 `DB::transaction()` に入れ、対象組織の membership/role 判定に使う行を `lockForUpdate()` で取得してください。`changeRole` `removeMember` `transferOwnership` と同じ直列化前提に寄せる必要があります。
- [Suggestion] `OrganizationMembershipService` に寄せる方針は良いですが、controller 側で述語を組み立てるより、service 側で「削除可否判定」または「拒否時例外送出」まで閉じた方が保守しやすいです。

**4. 期待効果の妥当性**
- [Warning] 「唯一 Owner 誤削除による組織ロックを 0 件に」は言い切りが強すぎます。この設計で直接防げるのは、少なくとも自己削除フロー `DELETE /settings/account` 起因の新規発生です。既存破損データや別削除経路までは自動では塞げません。  
  修正提案: 効果は「自己削除フロー起因の新規 Owner 不在組織を防止」に狭めて記述してください。
- [Suggestion] 「個人組織だけ特例扱いしないで、残存メンバー有無で一様判定する」という整理は合理的です。

**5. リスク**
- [Warning] `/settings` の props に出した blocker 一覧は表示時点のスナップショットに過ぎず、削除実行時には変わり得ます。事前警告を真実の源泉のように見せると、説明と実際の挙動がズレます。  
  修正提案: 文言を「現在は削除できません。削除時にも再判定されます」とし、サーバー側判定が最終権威であることを明示してください。
- [Suggestion] `name + slug` の最小表示に絞るのは良いです。余計な組織情報を props に載せない方が安全です。

**6. スコープの適切さ**
- [Suggestion] 自動移譲・自動解散・既存データ修復を外しており、スコープは概ね適切です。まず broken flow を止める、という切り方は妥当です。
- [Warning] テスト観点が実装方針に明示されていません。このリポジトリではそれが不足すると設計として弱いです。  
  修正提案: 少なくとも次をスコープ内に明記してください。  
  1. 唯一 Owner かつ他メンバーありで拒否  
  2. 唯一 Owner かつ自分のみで許可  
  3. 複数 Owner なら許可  
  4. Owner でない所属組織は blocker にならない  
  5. Inertia 画面で拒否メッセージが見える

**7. 型安全性**
- [Suggestion] Inertia props をプレーン配列で渡す方針は既存慣習に合っています。`soleOwnedOrganizations` は Svelte 側で `Array<{ name: string; slug: string }>`、PHP 側で `list<array{name:string,slug:string}>` の shape を固定すると、JsonResource を持ち込まずに型境界を安定させられます。
- [Suggestion] `Collection<Organization>` をそのまま view に漏らさず、service か controller で配列 shape へ変換する責務を明確にしてください。PHPStan L10 を通しやすくなります。

差し戻し理由は主に 2 点です。  
1. 並行更新下でまだ不変条件を破れること。  
2. UI 側の失敗表示仕様が弱く、押下後の理解可能性が設計として不足していること。  

この 2 点を設計に織り込めば、全体としては十分に承認可能な改善案です。