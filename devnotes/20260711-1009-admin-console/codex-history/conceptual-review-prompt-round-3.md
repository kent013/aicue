# Round 3: Round 2 指摘（Warning 5 件）への対応

全 Warning に対応した。変更点のみ示す（設計全体の構造は Round 2 提示版から不変）。APPROVED 可否の判定を依頼する。

## 対応内容

1. **[Warning] スライス A 先行での契約並走/破壊** → 実装方針冒頭に「**A + B は不可分（同一 PR・同一リリース単位）**。C のみ独立」と理由（旧 Settings UI が旧契約値を送信し続ける並走/破壊状態の回避）を明記した。

2. **[Warning] 422 と Inertia フォーム契約の不一致** → D2 のエラー契約を「`ValidationException` として Inertia error bag へ返す（redirect + errors = 押下時エラー表示）。Feature テストは `assertSessionHasErrors()` 基準、422 断定は JSON 要求時のみ」に書き換えた。テスト要件の「422」表記も同基準に修正した。

3. **[Warning] FormRequest での Default Project 存在確認は TOCTOU** → 「FormRequest は静的入力検証（enum 等）に限定。Default Project の最終存在確認 + resolver 呼び出しは **Service トランザクション内**で行い、不在は ValidationException（error bag）へ変換」と D2 に明記した。

4. **[Warning] removeMember の pivot 掃除の relation 境界** → 「detach 対象は `$organization->projects()` から解決した project id 集合に限定（cross-org 不変条件）。別 org の pivot が維持されることを Feature テストで固定」と明記した。

5. **[Warning] Rule::enum だけでは validated() の型が固定されない** → FormRequest に型付きアクセサ `role(): AdminConsoleRole`（`$this->enum()` の結果を Assert で narrow）を設け Service へ enum を渡す、ページ props は行 DTO + トップレベルの明示 array shape（docblock）で PHP 側契約を固定、と修正した。

## 改訂後の該当箇所（抜粋）

### D2 エラー契約・掃除規則（改訂後）

> - **編集者/撮影者は Default Project 存在が必須条件**（招待・ロール変更とも）。管理者コマンドは project 不要。エラー契約: FormRequest は静的入力検証（enum 等）に限定し、Default Project の最終存在確認 + resolver 呼び出しは **Service トランザクション内**で行う（TOCTOU 封じ）。不在は `ValidationException` として **Inertia error bag** へ返し（redirect + errors = 押下時エラー表示、禁止事項 8 と整合）、Feature テストは `assertSessionHasErrors()` を基準にする（422 ステータス断定は JSON 要求時のみ）。
> - **メンバー削除の掃除規則**: `removeMember` を拡張し、org detach と同一トランザクションで project pivot も detach する（現状は pivot が残り、members 一覧に stale 表示される穴がある）。detach 対象は **`$organization->projects()` から解決した project id 集合に限定**する（cross-org 不変条件。別 org の pivot が維持されることを Feature テストで固定）。

### 実装方針冒頭（改訂後）

> 実装は 3 スライスに分割する。**A + B は不可分（同一 PR・同一リリース単位）**: `members.update` / `invitations.store` の 3 値コマンド契約への書き換え（A）と、その唯一の caller UI（B の Admin/Users + Settings スリム化）を分離すると、旧 Settings UI が旧契約値を送信し続ける並走/破壊状態が生じるため。**C のみ独立に実装・レビュー可能**。

### スライス A Controller/Request 行（改訂後）

> FormRequest は `Rule::enum(AdminConsoleRole)` + 型付きアクセサ `role(): AdminConsoleRole`（`$this->enum()` の結果を Assert で narrow）で Service へ enum を渡す。Default Project 存在確認は Service tx 内（不在は error bag）

### スライス B Controller/DTO 行（改訂後）

> props は行 DTO（`App\DataTransferObjects\Admin\MemberRowData` / `InvitationRowData`。Capture ドメインの DTO パターン踏襲）+ トップレベルの明示 array shape（docblock）で PHP 側契約を固定
