Round 2 の [Warning] 2 件（施策3・4 の serverErrors 非退行テストが `$effect` のクリア分岐を
実際に通していない）に対し、ご提示の修正案（4 ステップ操作列）を**全面採用**しました。
施策1・2 は APPROVE 継続、過剰クリア防止 2 件は解消済みです。

## 対応内容（施策3・4 共通の操作列に統一）

server error 設定時は client error が null でクリア分岐が走らない、という指摘のとおりです。
そこで「client error で server error を一度覆い、有効値復帰で `$effect` にクリア分岐を実通し
させ、背後の server error が再表示されること」を検証する操作列へ変更しました:

1. 有効候補 A を選択 → 送信 → `router.post` mock の `opts.onError({ user_id: "サーバ由来…" })`
   により実 `form.errors.user_id` に server error を設定。この時点で client error は null、
   表示は server error 由来。
2. 選択を空 (`""`) に戻して送信 → client error（transient state）がセットされ、
   `clientError ?? form.errors.user_id` の client 優先で server error を**一時的に覆う**。
3. 有効候補 B を選択 → `$effect`（`clientError !== null && isValid`）が**クリア分岐を実際に
   通り** client error を null 化。
4. 背後の **server error 文言が再表示・残存**することを明示アサート。

fixture は有効候補を 2 人以上（transfer: 自分 + 候補 A/B、add-member: `assignableUsers` に
A/B）用意する前提を明記しました。

## 改訂後の詳細設計（施策3・4 のテスト計画セクション全文）

## 施策 3: オーナー移譲の再現テスト追加

### 変更箇所
- ファイル: `tests/js/pages/OrganizationsSettings.test.ts`（既存 describe に it 追加）

### テスト前提
- 既存流儀に追随: `useForm` は実物、`router` のみ vi.mock。owner 権限 props で
  transfer-ownership フォームを描画。
- select の testId / label は「移譲先のメンバー」。送信ボタン testId は
  `transfer-ownership-button`。エラー文言は本文と同一（`移譲先のメンバーを選択してください。`）。
- select option value は `String(id)`（比較契約の固定）。
- **serverErrors 非退行テスト用 fixture**: 自分（`myId`）に加え**有効候補を 2 人以上**含む
  members を用意する（例: 自分 id:1 + `候補A` id:2 + `候補B` id:3）。候補 A→B の切替で
  `$effect` の client error クリア分岐を通すため（Codex Round 2 指摘）。

既存 it は不変更で維持する（非退行の証跡）。特に既に存在する:
- 「候補 0 人 → 押下で `移譲先にできるメンバーがいません。…` を表示」(既存 L112-116)
- 「候補あり・空選択 → 押下で `移譲先のメンバーを選択してください。` を表示」(既存 L126-128)
これらは修正後 `transferClientError` 経由の表示に変わるが、可視文言は不変のため既存
アサートはそのまま green（表示経路の内部差し替えのみ）。

### 追加テストケース
1. **stale 解消（有効値復帰）**: 候補あり props で空選択のまま送信 → エラー文言 +
   select の `aria-invalid=true` を確認 → `fireEvent.change` で有効候補 (`String(member.id)`)
   を選択 → `waitFor` でエラー文言消失 + `aria-invalid` 解除（属性が落ちる）を確認。
2. **過剰クリア防止（候補あり・無効値維持の単一条件）**: 候補あり props で空選択のまま送信し
   エラー表示 → 選択を空 (`""`) のまま保持（`isValidTransferTarget=false`）→ エラーが残留する
   ことを確認。※候補 0 人ケースとは原因を混ぜず、「候補あり・未選択維持」の単一条件に絞る
   （Codex Round 1 Warning 反映）。
3. **serverErrors 非退行（`$effect` クリア分岐を実際に通して検証）**: 既存の確定フロー機構
   （`stubRecentAuthStatus(true)` + `router.post` spy）を用いる。`router.post` mock を
   `(_url, _data, opts) => opts.onError?.({ user_id: "サーバ由来: 対象は組織メンバーではありません" })`
   実装にする（`useForm.submit` 内部の `onError` が `form.clearErrors().setError(errors)` を
   呼ぶため、実 `transferForm.errors.user_id` にサーバエラーが載る: `useForm.svelte.js` L64-66）。
   操作列（Codex Round 2 修正案を採用）:
   1. 有効候補 A を選択 → 確認ダイアログ → 確定 → サーバエラー文言が表示されることを確認
      （このとき `transferClientError` は null で、表示は `transferForm.errors.user_id` 由来）。
   2. 選択を空 (`""`) に戻して送信（`openTransferDialog`）→ `transferClientError` が
      「移譲先のメンバーを選択してください。」でセットされ、**client error が server error を
      一時的に覆う**（表示は `transferClientError ?? …` の client 優先で切替）ことを確認。
   3. 有効候補 B を選択 → `$effect`（`transferClientError !== null && isValidTransferTarget`）が
      **実際にクリア分岐を通り** `transferClientError = null` になる。
   4. 背後の **サーバエラー文言が再表示・残存**することを `waitFor`/`expect` で明示アサート。
   これで「client の自動クリアは自分の transient state のみを消し、serverErrors は破壊しない
   （下層に温存され再表示される）」を、クリア分岐を実通ししたうえで固定する。

### PHPStan適合チェック
- [x] 型: テストは TS。`pnpm typecheck` で担保。`String()` 比較契約を維持

### リスク
- なし（テスト追加のみ、既存 it は不変更＝禁止事項 #3「既存テスト削除・上書き」に非該当）。

---

## 施策 4: プロジェクトメンバー追加の再現テスト追加

### 変更箇所
- ファイル: `tests/js/pages/ProjectsShow.test.ts`（既存 describe に it 追加）

### テスト前提
- `assignableUsers` に**有効候補を 2 人以上**含む props で add-member フォームを描画
  （候補 A→B の切替で `$effect` の client error クリア分岐を通すため。Codex Round 2 指摘）。
  `canManageMembers=true`。
- add-member select は `label="メンバー"` / `id="project-member-user"`、submit ボタン
  testId=`project-member-submit`。エラー文言は `追加するメンバーを選択してください。`。
- option value は `String(candidate.id)`（比較契約の固定）。

既存 it「候補未選択で送信すると router.post は呼ばれず field error を表示する」(既存 L242) は
不変更で維持（表示は `addMemberClientError` 経由に差し替わるが可視文言は不変 = green）。

### 追加テストケース
1. **stale 解消（有効値復帰）**: 未選択で追加ボタン (`project-member-submit`) 押下 → エラー +
   select `aria-invalid=true` を確認 → assignableUsers の候補 (`String(candidate.id)`) を
   `fireEvent.change` で選択 → `waitFor` でエラー消失 + `aria-invalid` 解除を確認。
2. **過剰クリア防止（無効のまま・単一条件）**: 未選択で押下しエラー表示 → 選択を空のまま保持
   （`isAddMemberSelected=false`）→ エラー残留を確認。
3. **serverErrors 非退行（`$effect` クリア分岐を実際に通して検証）**: `router.post` mock を
   `(_url, _data, opts) => opts.onError?.({ user_id: "サーバ由来: 追加できません" })` にする
   （`useForm.submit` 内部 `onError` が実 `memberForm.errors.user_id` を設定: `useForm.svelte.js`
   L64-66）。操作列（Codex Round 2 修正案を採用）:
   1. 有効候補 A を選択 → 追加ボタン押下 → サーバエラー文言が表示されることを確認
      （`addMemberClientError` は null、表示は `memberForm.errors.user_id` 由来。onError 経路の
      ため `onSuccess` = `memberForm.reset()` + `addMemberClientError=null` は発火せず選択値は残る）。
   2. 選択を空 (`""`) に戻して送信 → `addMemberClientError` がセットされ client error が
      server error を一時的に覆うことを確認。
   3. 有効候補 B を選択 → `$effect`（`addMemberClientError !== null && isAddMemberSelected`）が
      クリア分岐を通り `addMemberClientError = null` になる。
   4. 背後の **サーバエラー文言が再表示・残存**することを明示アサート。

### PHPStan適合チェック
- [x] 型: テストは TS。`pnpm typecheck` で担保

### リスク
- なし（テスト追加のみ）。

---

Round 2 の [Warning] 2 件はこれで解消したと考えます。全体判定の再評価をお願いします。
