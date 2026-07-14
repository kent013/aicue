Round 1 の [Warning] 4 件（施策3・4 のテスト厳密性）に対応し、詳細設計書のテスト計画を
改訂しました。施策1・2 の実装方針は APPROVE を受けたため不変更です。以下が対応内容です。

## 対応サマリ

### 施策3 (OrganizationsSettings テスト) — REQUEST_CHANGES への対応

- **serverErrors 非退行を「操作後も消えない」まで直接検証する形へ具体化**:
  既存の確定フロー機構（`stubRecentAuthStatus(true)` + `router.post` spy。既存
  `tests/js/pages/OrganizationsSettings.test.ts` L142-201 に存在）を再利用する。
  `router.post` mock を
  `(_url, _data, opts) => opts.onError?.({ user_id: "サーバ由来: 対象は組織メンバーではありません" })`
  実装にする。Inertia `useForm.submit` 内部の `onError` は
  `form.clearErrors().setError(errors)` を呼ぶ（`node_modules/@inertiajs/svelte/dist/useForm.svelte.js`
  L64-66）ため、実 `transferForm.errors.user_id` にサーバエラーが載る。
  手順: 有効候補を選択 → 確認ダイアログ → 確定 → サーバエラー文言が表示されることを確認 →
  **別の有効候補へ `change`（client `$effect` の発火条件を満たす）** → サーバエラー文言が
  **残存**することを `waitFor`/`expect` で明示アサート。

- **過剰クリア防止テストを単一条件へ**: 「候補あり props・空選択を維持
  （`isValidTransferTarget=false`）でエラー残留」の単一条件に絞った。候補0人ケースは既存
  it（L112-116）が別途カバーしており、原因混在を排除。

### 施策4 (ProjectsShow テスト) — REQUEST_CHANGES への対応

- **serverErrors 非退行を同方式で具体化**: `router.post` mock を
  `(_url, _data, opts) => opts.onError?.({ user_id: "サーバ由来: 追加できません" })` にし、
  実 `memberForm.errors.user_id` を設定。有効候補を選択 → 追加ボタン（`project-member-submit`）
  押下 → サーバエラー文言表示 → **別の有効候補へ `change`** → サーバエラー残存を明示アサート。
  onError 経路では `onSuccess`（`memberForm.reset()` + `addMemberClientError=null`）が発火せず
  選択値が有効のまま保たれる設計整合も明記。
- 過剰クリア防止を「未選択維持」の単一条件に明確化。stale 解消は `aria-invalid` 脱落と文言消失を
  both 確認。

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
3. **serverErrors 非退行（操作後も消えないことを直接検証）**: 既存の確定フロー機構
   （`stubRecentAuthStatus(true)` + `router.post` spy）を用いる。`router.post` mock を
   `(_url, _data, opts) => opts.onError?.({ user_id: "サーバ由来: 対象は組織メンバーではありません" })`
   実装にする（`useForm.submit` 内部の `onError` が `form.clearErrors().setError(errors)` を
   呼ぶため、実 `transferForm.errors.user_id` にサーバエラーが載る: `useForm.svelte.js` L64-66）。
   有効候補を選択 → 確認ダイアログ → 確定 → サーバエラー文言が表示されることを確認 →
   さらに **別の有効候補へ `change`（client `$effect` の発火条件を満たす）** →
   サーバエラー文言が**残存**することを `waitFor`/`expect` で明示アサートする。
   これにより「client 側の自動クリアが serverErrors を消さない」を操作レベルで固定する。

### PHPStan適合チェック
- [x] 型: テストは TS。`pnpm typecheck` で担保。`String()` 比較契約を維持

### リスク
- なし（テスト追加のみ、既存 it は不変更＝禁止事項 #3「既存テスト削除・上書き」に非該当）。

---

## 施策 4: プロジェクトメンバー追加の再現テスト追加

### 変更箇所
- ファイル: `tests/js/pages/ProjectsShow.test.ts`（既存 describe に it 追加）

### テスト前提
- `assignableUsers` に候補を含む props で add-member フォームを描画。`canManageMembers=true`。
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
3. **serverErrors 非退行（操作後も消えないことを直接検証）**: `router.post` mock を
   `(_url, _data, opts) => opts.onError?.({ user_id: "サーバ由来: 追加できません" })` にする
   （`useForm.submit` 内部 `onError` が実 `memberForm.errors.user_id` を設定: `useForm.svelte.js`
   L64-66）。有効候補を選択 → 追加ボタン押下 → サーバエラー文言表示を確認 →
   **別の有効候補へ `change`（client `$effect` 発火条件を満たす）** → サーバエラー文言が
   残存することを明示アサート。onError 経路のため `onSuccess`（`memberForm.reset()` +
   `addMemberClientError=null`）は発火せず、選択値は有効のまま保たれる点も設計整合。

### PHPStan適合チェック
- [x] 型: テストは TS。`pnpm typecheck` で担保

### リスク
- なし（テスト追加のみ）。

---

上記改訂で Round 1 の [Warning] 4 件は解消したと考えます。全体判定の再評価をお願いします。
