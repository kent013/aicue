# 対応マトリクス: design-review Round 1

## 施策 4 [Critical] `NestedRouteDefenseInventory` への登録案が不整合 (nested route ではない)
- 判断: **反論する**（登録は必須であり、モードも正しい）
- 根拠（実コードを読んだ結果）:
  1. `tests/Support/Routing/NestedRouteDefenseInventory.php` の class docblock が
     「**母集団は 1 個以上の parameter を持つ named route**（旧実装は 2 個以上だった）」と明記し、
     `candidates()` も `if ($route->parameterNames() === []) { continue; }` だけで絞っている。
     つまり **nested かどうかは母集団の条件ではない**。2+param に絞ると単独 param の route が
     母集団から丸ごと外れて穴になった（audit-cycle-2 High-1）という経緯まで書かれている。
     したがって `invitations.accept-in-app`（1 param）は**登録しないと
     `NestedRouteIdorDefenseTest` が「分類漏れ」で fail する** = 登録は選択肢ではない。
  2. 先例が同形で実在する: `'notifications.open' => ['notification' => $manual]` /
     `'notifications.read' => ['notification' => $manual]`（個人スコープ・単一 param・
     controller が `$user->notifications()` から手動解決）。本件はこれと構造が同一である。
  3. `ManualOwnerScopedResolution` は「implicit binding を使わず controller が
     owner-scoped relation から手動解決する」という**解決方式の宣言**であって、
     モデル種別に紐づく分類ではない（`NestedRouteDefenseMode` の docblock）。
     したがって「対象モデルが違う」という前提は成立しない。
  4. さらに `TenantBoundaryOrderingTest` 検査 3a は
     `ManualOwnerScopedResolution` を宣言した param に対してのみ
     「action 引数が Model 型でないこと」「`RouteBindingTypes::MANUALLY_RESOLVED` に
     route identity ごと登録済みであること」「explicit binder が無いこと」を機械検証する。
     **inventory に入れないとこの 3 検査が一切走らない**（= 存在オラクル防御が無検証になる）。
     Codex の代案「nested inventory には入れず MANUALLY_RESOLVED と feature test で守る」は、
     この検査 3a を殺すため防御が弱くなる。
- 対応内容: 設計は変更しない。ただし**なぜ登録が必須なのか**（母集団が 1 param 以上であること、
  検査 3a が inventory 宣言を起点にすること、`notifications.open` が同形の先例であること）を
  詳細設計の施策 4 に明記し、次の実装者・レビュアーが同じ誤解をしないようにした。

## 施策 7 [Critical] `project_role` drop のローリングデプロイ安全境界が未定義
- 判断: **対応する**
- 根拠: 妥当な指摘。旧プロセスが `inviteMember` で
  `forceFill(['project_role' => ...])` を実行すると存在しない列への INSERT で 500 になる
  （read 側は属性が欠落するだけで null 相当に落ちるため 500 にはならない）。
  書き込み側が壊れる以上、順序の定義が要る。
- 対応内容: 施策 7 に「デプロイ手順（expand/contract の contract 側）」節を追加。
  **コードを先にデプロイし、旧プロセスが残っていないことを確認してから migration を流す**
  （列を書かなくなったコードが全プロセスに行き渡ってから drop する）。
  rollback では `down()` で列と check 制約は復元できるが**値は復元不能**であることを明記。
  値の消失影響は「pending 招待が参加後に管理画面でロール割当を要する状態になるだけで、
  参加自体は成功する」ことも書いた。

## 施策 1 [Warning] email 正規化契約との接続が弱い
- 判断: **対応する**（挙動は変えない。契約を明記する）
- 根拠（実コード確認）: `App\Support\EmailNormalizer` は inquiry / billing contact 専用で、
  **User の email は登録時に正規化していない**（`CreateNewUser` は validated 値をそのまま保存）。
  招待側 `inviteMember` も入力 email をそのまま保存する。
  したがって招待 email とログイン email の大小差は実際に起こりうる。
  ただしこれは**新しい非対称ではない** — 既存の `emailBelongsToMember` /
  `hasPendingInvitation` / `acceptInvitationIfValid` の email 一致判定も
  すべて同じ完全一致の意味論である。ここで正規化を入れると
  blind index の再計算（既存全レコード）と全 `whereBlind` 呼び出し元の同時変更が必要になり、
  本件のスコープを大きく外れる。
- 対応内容: 施策 1 のリスク欄と施策 9（`docs/architecture.md`）に、
  「User の email は正規化保存していない」「大小差はアプリ内受諾では 0 件 = 404 に倒れる
  （fail-secure）」「メール token 経路は token_hash 照合なので影響を受けず受諾できる」
  「正規化するなら blind index 再計算を伴う別作業」を明記した。

## 施策 3 [Warning] `retrieved` 回数依存のテストは壊れやすい
- 判断: **対応する**（回数依存をやめる）
- 根拠: 指摘のとおり `acceptPendingInvitation` は
  「下見 → ロック下再解決 → `joinOrganization` 内のロック取得」で取得回数が経路ごとに違い、
  回数で当てるのは実装変更に脆い。
- 対応内容: `retrieved` の**回数**ではなく **SQL の形**で当てる方式へ変更した。
  `DB::beforeExecuting()` で「`organization_invitations` を対象にした `for update` の SELECT」を
  検出し、その直前に**同一接続・同一トランザクション内で**
  `DB::table('organization_invitations')->whereKey($id)->update(['revoked_at' => now()])` を当てる
  （one-shot フラグで自分自身の UPDATE による再入を止める）。
  これで `joinOrganization` のロック下再検証が `isRevoked() === true` を見て `false` を返す状態を
  経路に依らず決定的に作れる。後始末は `try/finally` でフラグを落とす。
  テスト docblock に「目的は競合の完全再現ではなく `joinOrganization() === false` の
  消費契約の決定的検証である」と明記する方針は維持。

## 施策 3 [Warning] `joinOrganization()` の戻り値消費を守る静的 gate が無い
- 判断: **対応する**（ただし文字列一致ではなく**トークナイザ**で行う）
- 根拠: 同一セッションの概念設計レビュー Round 3 で
  「`if (! $this->joinOrganization(` の完全一致は正常な実装
  （`$joined = $this->joinOrganization(...); if (! $joined)`）で壊れ、
  コメント内の同一文字列で通る。少なくともコメント除外を含む構文検査へ合わせよ」という
  指摘を受けて behavioral 固定に倒した経緯がある。両方の指摘を満たすには
  **コメントを除外できる構文レベルの検査**が必要。
- 対応内容: `MembershipWriteLockInventoryTest` に検査を 1 本追加する。
  `token_get_all()` でサービスファイルをトークン化し、`joinOrganization` 呼び出しトークンの
  **直前の有意トークン**（コメント・空白を除く）が
  `T_RETURN` / `=` / `!` / `&&` / `||` / `?` のいずれかであることを要求する
  （文の先頭 = `;` `{` `}` の直後なら戻り値を捨てているので fail）。
  コメント内の文字列はトークン種別で除外されるため誤検出しない。
  behavioral な `InvitationAcceptRaceTest` は**併存**させる
  （静的検査は「消費している形か」、behavioral は「消費した結果の契約が正しいか」を見る）。

## 施策 4 [Warning] `abort_if` 後の PHPStan narrowing
- 判断: **対応する**
- 対応内容: 実装コード例を `if ($organization === null) { abort(404); }` に統一した
  （`abort()` は `never` を返すため narrowing が確実）。

## 施策 5 [Warning] `Button` の `loading` が `disabled` を出すなら禁止事項 8 に反する
- 判断: **部分的に対応する**（テスト計画の記述を是正。実装方針は既存流儀を維持）
- 根拠（実コード確認）: `resources/js/components/atoms/Button.svelte` の `<button>` は
  `disabled={disabled || loading}` を出す。ただし**禁止事項 8 は
  「必須条件未充足を理由に disabled にする UI」を禁じるもの**であり、
  in-flight（送信中）の二重送信防止は該当しない。実際、同じ画面の招待送信ボタンが
  `loading={inviteForm.processing}` を使っており、これが既存の sanctioned な流儀である。
  ここで独自の `aria-busy` だけの wrapper を新設すると、既存 atom と二重の作法を作ることになる
  （思考原則 1・3 に反する）。
- 対応内容: 実装は `loading={acceptingId === invitation.id}` のまま。
  誤っていたのは**私のテスト計画の文言**（「参加ボタンは disabled 属性を持たない」）なので、
  「**初期描画（非 in-flight）では disabled 属性を持たない**」
  「in-flight 中の disabled は二重送信防止であり必須条件未充足による無効化ではない」
  へ是正し、その旨をコンポーネント docblock にも書く方針にした。

## 施策 5 [Warning] `class="..."` のままでは DESIGN.md 準拠をレビューできない
- 判断: **対応する**
- 対応内容: `PendingInvitationList.svelte` / `PendingInvitationsNotice.svelte` の
  クラス指定を具体化し、「既存 `Card` / `Button` / `Badge` atom を使い、
  色・radius・typography は DS token 経由（`text-body` / `text-caption` / `text-h3` /
  `border-border` / `bg-surface` / `text-text-secondary` 等）のみ」
  「hex 直書き・独自 radius・カードの入れ子を増やさない」
  「アイコンは `@lucide/svelte` の `Mail` のみで SVG 直書きを新設しない」を明記した。

## 施策 6 [Suggestion] partial reload では `pendingCount` が更新されない旨を docs へ
- 判断: **対応する**
- 対応内容: 施策 9 の `docs/architecture.md` 追記項目に
  「共有 prop は closure のため `only:` 指定の partial reload では評価されず、
  件数はフルページ遷移時に更新される（受諾直後は dashboard へフル遷移するため実害はない）」を追加。

## 施策 7 [Warning] `php artisan migrate` が dev DB だと禁止事項 3 に抵触
- 判断: **対応する**
- 対応内容: テスト計画の該当項目を
  「**worktree のテスト DB / CI DB に限定**して実行する。dev DB への適用は通常のデプロイ手順、
  またはユーザーの明示承認による」へ限定した。

## 施策 8 [Warning] 抽出結果 / floor / cap の実測値が devnotes に固定されていない
- 判断: **対応する**
- 対応内容: 完了条件に
  「`devnotes/{dir}/gate-mutation-log.md` に **(a) 初回抽出結果の全リスト**、
  **(b) 確定した floor**、**(c) `RecipientScopedPendingSet` の exact-fit cap** を記録し、
  mutation M1〜M7 の赤化結果と併せてレビュー対象にする」を追記した。

## 施策 9 [Warning] 完了条件の検証コマンドが AGENTS.md の正本と不一致
- 判断: **対応する**
- 対応内容: 完了条件を AGENTS.md の `VERIFICATION_COMMANDS` と完全一致させた
  （`pnpm typecheck:packages` / `pnpm build:packages` / `pnpm test:packages` を追加）。

## 施策 2 [Suggestion] `roleLabel` のみで value を持たない
- 判断: **見送る**
- 根拠: 現時点で front は表示しかしない。value が必要になった時点で足せばよい
  （思考原則 2「今必要なものだけ作る」）。開示最小化としては妥当という評価とも一致する。
