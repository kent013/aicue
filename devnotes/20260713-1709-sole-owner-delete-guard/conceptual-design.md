# 概念設計: sole-owner-delete-guard

## 背景・課題

bug-hunt finding **F-H5 (High, broken_flow)**。

組織の唯一の Owner がアカウント削除 (`DELETE /settings/account`) を実行すると、
現状 `AccountController::destroy` は無条件に `$user->delete()` するだけで、
「その組織が Owner 不在になる」ことを一切検出・警告・ブロックしない。

削除後は FK cascade で `organization_user` / laratrust の role 行が消え、組織は
**Owner が 0 人の状態で残存メンバーごと取り残される**。この状態は回復不能である:

- Owner 昇格の正規経路は `transferOwnership` のみ (`OrganizationMembershipService`)。
  しかし `transferOwnership` は「既存 Owner が別メンバーへ渡す」操作であり、
  **Owner が 0 人になると誰も新しい Owner を作れない**。
- 残存メンバー (Admin 含む) は「管理者に依頼してください」という到達不能な導線に
  取り残され、組織全体が恒久的に管理不能になる。

これは既存の不変条件と非対称なバグである。メンバー削除 (`removeMember`) と
ロール降格 (`changeRole`) は既に「最後の Owner を失わせない」ガードを持つ
(`最後のオーナーは降格できません` / `オーナーは削除できません`) のに、
**アカウント削除経路だけがこのガードを素通りしている**。

## 改善アイデア

アカウント削除を「Owner 不在組織を生む操作」の一種として、既存の Owner 保護不変条件に
組み込む。方針は **(a) サーバー側ブロック + (b) 事前の警告・移譲導線** の併用:

1. **サーバー側ブロック (権威ある防御線)**: 削除対象ユーザーが「唯一の Owner であり、
   かつ他に残存メンバーがいる」組織を 1 つでも持つ場合、`ValidationException`
   (固定キー `errors.account`) で削除を拒否し「先にオーナーを移譲してください」を返す。
   **判定と `$user->delete()` は同一トランザクション + 行ロック (`lockForUpdate`) で
   直列化**し、並行降格/移譲との間で Owner 0 人の中間状態を作らせない
   (`transferOwnership` と同方式)。UI を迂回しても不変条件が守られる = これが最終権威。

2. **事前の警告・移譲導線 (UX)**: `/settings` (プロフィール設定) 画面に、ユーザーが
   唯一 Owner となっている該当組織の一覧 (name + 各組織 settings への移譲リンク) を
   props で渡す。DangerZone に警告を表示し、各組織の
   `organizations.transfer-ownership` フロー (組織設定画面) へ誘導する。この一覧は
   表示時点のスナップショットであり、UI 文言で「削除時にサーバーが再判定する」ことを明示する
   (真実の源泉はサーバー側判定)。ブロック時に返る `errors.account` は DangerZone 内に常時描画し、
   「押下後に理由が見える」(禁止事項8 の趣旨) を満たす。

### 「削除ブロック」の判定述語 (最重要設計判断)

**すべてのユーザーは登録時に個人組織 (`is_personal=true`) の唯一 Owner になる**
(`CreateNewUser` → `provisionPersonalOrganization`)。したがって「唯一 Owner の組織を
持つなら削除ブロック」という素朴な述語は **全ユーザーのアカウント削除を不能にする**
致命的な誤りになる。

正しい述語は「孤児化するメンバーが存在するか」である:

> ブロック対象 = ユーザーが **Owner** であり、**他に Owner がいない** かつ
> **他に 1 人以上メンバーが残る** 組織。

- 個人組織のように「ユーザーが唯一メンバー」の組織 → 削除で誰も孤児化しない → **許可**
  (`is_personal` を特別扱いせず、メンバー数で一様に判定できる = 概念が単純)。
- 残存メンバーが Admin だけでも、Owner 昇格経路が断たれるため恒久的に管理不能になる
  → 役割を問わず「他メンバーが残る」なら **ブロック**。

## 期待効果

- **使命への貢献**: AI-CUE は組織 (現場) 単位で SOP→動画マニュアルを運用する。組織が
  Owner 不在で管理不能に陥ると、メンバー招待・課金・2FA 方針・権限管理といった運用の
  根幹が停止し、現場のマニュアル運用そのものが破綻する。本修正は組織運用の可用性を守り、
  使命の前提となる「組織が使い続けられる」状態を保証する。
- **具体的改善**:
  - **自己削除フロー (`DELETE /settings/account`) 起因の新規 Owner 不在組織の発生を防止**する
    (既存の破損組織や別経路までは対象外。本 finding は再発防止が主眼)。
  - 既存の Owner 保護不変条件 (降格・メンバー削除) とアカウント削除の挙動を対称化する。
  - ユーザーは削除前に「何をすべきか (= オーナー移譲)」を提示され、詰みを回避できる。

## 実装方針（概要）

| 層 | 変更 |
|----|------|
| Service (判定) | `OrganizationMembershipService` に公開メソッド `organizationsBlockingDeletion(User): Collection<Organization>` を追加 (UI props 用の読み取り専用判定)。既存 private `hasAnotherOwner` を再利用し、「Owner かつ 他 Owner 無し かつ 他メンバー有り」の組織を返す。Owner 保護不変条件の唯一の窓口である本サービスに集約する。 |
| Service (削除) | 権威ある削除を `deleteAccount(User): void` として service に閉じる。同一 `DB::transaction` 内で対象組織の `organization_user` 行を `lockForUpdate` してから述語を再評価し、非空なら `ValidationException(errors.account)`、空なら `$user->delete()`。完全な直列化のため `changeRole`/`removeMember` の最終 Owner 再チェックも共有 private helper で同じ行ロック下に置く。 |
| Controller | `AccountController::destroy` にサービスを注入し `deleteAccount` を呼ぶ (ブロック時は例外が伝播し `Auth::logout()` 等に到達しない)。監査記録 (`AccountDeleted`) はトランザクション内・削除直前に行う。 |
| Route/Props | `GET /settings` (現状 props 無しの closure) にサービスを注入し、`soleOwnedOrganizations`(name+slug) を `Settings/Index` に渡す。 |
| UI | `Settings/Index.svelte` の DangerZone に警告 + 各組織の移譲導線 (`/organizations/{slug}/settings` への `TextLink`) を表示。**削除ボタンは disabled にしない** (AGENTS.md 禁止事項8。押下時にサーバーがエラーを返し表示する)。 |

## 並行性・不変条件の強制 (共通ロック境界)

不変条件「組織は常に Owner を 1 人以上持つ / メンバーがいる組織は Owner を持つ」を
並行操作下でも守るため、**2 段の共通ロック境界**を採る。pivot 行 (`organization_user`)
ロックだけでは phantom (同時 INSERT / 新規所属 / 未列挙組織への Owner 付与) を止められないため。

- **canonical ロック順序 = `users` 行 (id 昇順) → `organizations` 行 (id 昇順)**。
  `users` 行を先に含めるのは、`deleteAccount` が「対象組織を列挙 → ロック」する間に、別 txn が
  **列挙時点で未知の組織 B のオーナーをその削除対象ユーザーへ移譲**して B を孤児化させる race を
  塞ぐため (actor/target の User 行を先にロックすれば移譲側と削除側が直列化する)。
- `OrganizationMembershipService` は**メンバーシップ書き込みの唯一の窓口**なので、
  共有 private helper `lockForMembershipWrite(array $userIds, array $organizationIds)`
  (両者を id 昇順で `lockForUpdate`、デッドロック回避) を 1 ファイル内に導入すれば全経路を統一できる。
- owner 数 / メンバー数を変える全メソッド (`joinOrganization`, `changeRole`(+`applyConsoleRole`/
  `normalizeOrganizationRole`), `removeMember`, `transferOwnership`, 新規 `deleteAccount`) は
  トランザクション冒頭で自身が触れる user/org を同 helper に渡してロックし、**同一基点で直列化**する。
  `transferOwnership` は関与する 2 ユーザー行 + 組織行をロックする (既存 pivot ロックを本基点へ寄せる。
  挙動不変・既存テスト緑のまま)。`deleteAccount` は対象 User 行を先にロック → 所属組織を列挙 →
  組織行を昇順ロック → ロック内で述語を再評価する。
- 防げる race: (a) 別 Owner の並行降格による Owner 0 人化、(b) 「他メンバー無し→削除許可」判定後の
  並行メンバー追加による孤児化、(c) 列挙時点で未知の組織への並行 Owner 移譲による孤児化。
- **Architecture テスト (drift-guard)**: `OrganizationMembershipService` の public メソッドを
  reflection で列挙し、mutating メソッドがロック対象 inventory に未登録なら fail させ、
  将来の書き込みメソッド追加時のロック忘れを検出する (本リポジトリの inventory テスト慣習に準拠)。
- **並行性の検証方針**: テストは本番同等 PostgreSQL で走るが、`RefreshDatabase` がテストを
  単一トランザクションで包むため、複数コネクションによる真の race を決定的に再現するのは
  現行ハーネスの範囲外 (既存 `transferOwnership` のロックも race テストでなく構造で担保されている)。
  よって並行正当性は **lockForUpdate による構造 (canonical 順序)** で担保し、drift-guard
  Architecture テストがロック規約の適用漏れを検出する。Feature テストは論理述語 (下記観点) を検証する。

### 監査記録とトランザクション

`SecurityEventRecorder::record` は `security_audit_events` への純 DB insert のみ (Laravel event /
外部副作用の dispatch を持たない best-effort)。よって `AccountDeleted` 記録は削除と**同一
トランザクション内・削除直前**に行う。ロールバック時は監査行も巻き戻り「削除していないのに
deleted 記録が残る」を防げる。削除成功後、Controller で `Auth::logout()` →
`session()->invalidate()` → `session()->regenerateToken()` → `redirect()->route('home')` の順で
後処理する (現行 destroy の順序を保持。ブロック時は例外伝播でここに到達しない)。

## 制約・前提

- **禁止事項遵守**: `response()->json()` 直書きなし (Inertia render + `back()`/redirect と
  `ValidationException`)。PHPStan L10 (Collection generics / `Assert` narrowing)。テスト必須。
- Inertia props は本アプリの既存慣習どおり **プレーン配列** で渡す (JsonResource は REST API 用。
  `HandleInertiaRequests`/`OrganizationController@settings` と同じスタイル)。
- Owner 判定は必ず組織の `laratrust_team_id` を明示する既存ヘルパ (`organizationRole`) 経由。
- `transferOwnership` は `recent-auth` (step-up) 必須の既存フローをそのまま利用 (新設しない)。
- 後方互換の並走を残さない: 削除ロジックは新経路一本にまとめる (旧無条件削除は消す)。

## テスト観点 (スコープ内・Feature/JS)

1. 唯一 Owner かつ他メンバーありのユーザーは削除が **拒否** され (`errors.account`)、ユーザーは残存する。
2. 唯一 Owner だが自分のみメンバー (個人組織含む) のユーザーは削除 **許可**。
3. 複数 Owner がいる組織のみの Owner は削除 **許可**。
4. Owner でない (Admin/Member として所属する) 組織は blocker に含まれない。
5. Inertia 画面: `soleOwnedOrganizations` が非空のとき DangerZone に警告 + 移譲リンクが出る。削除ボタンは disabled にならない。拒否時に `errors.account` が表示される。

## スコープ外

- オーナー移譲 UI そのものの改修 (既存 `organizations.transfer-ownership` を再利用)。
- 「削除時に組織を自動解散/他者へ自動移譲」する自動化 (今必要なものだけ = 明示移譲を要求)。
- 組織そのものの削除フロー (別 finding)。
- Owner が 0 人になった既存の破損組織のデータ修復 (本 finding は再発防止が主眼)。
- 招待中 (未受諾 invitation) しかいない組織の扱いの厳密化 (メンバー実体 = pivot 行で判定)。
