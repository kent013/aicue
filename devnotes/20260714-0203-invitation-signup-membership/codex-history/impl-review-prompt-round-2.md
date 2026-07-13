## Round 2: Round 1 指摘への対応

Round 1 の全体判定 CHANGES_REQUESTED は「acceptInvitationIfValid() の register 専用前提がコード上で暗黙で、将来再利用時の current 上書きリスクを明示化してからのマージが安全」という点でした (Critical なし)。

対応方針: 命名変更 (acceptInvitationForRegistrationIfValid 等) ではなく **DocBlock 明示化** を選択しました。
理由: 詳細設計が「新規メソッド追加はしない (今必要なものだけ作る)」「docblock に register 専用を明記して防御」を既に方針化しており、Codex も命名 or DocBlock のいずれかで可としているため、設計の意思決定 (DocBlock) に従いました。

### 追加した DocBlock 差分

```diff
     * (登録そのものは成功させ、呼び出し側が個人組織へ fallback するため)。register 経路は
     * 招待 email と登録 email の一致を要求する (MatchesInvitationEmail rule と対で二重防御)。
     *
+     * **register 経路専用 (再利用禁止)**: join 成立時、参加した招待組織を
+     * current_organization_id へ **無条件で確定する副作用** を持つ (登録直後の user は
+     * current 未設定のため「招待成立 ⇒ current = 招待先」を強制できる)。この副作用は
+     * 「呼び出し元の user が登録直後で current 未確定」であることに依存するため、
+     * **ログイン中経路 (既存 current を持つ user) から再利用してはならない**
+     * (既存 current を無条件上書きしてしまう)。POST 受諾は current を切り替えない
+     * acceptInvitation を使い、共通コア joinOrganization は current を触らない
+     * (InvitationTest が POST 受諾の current 非変更を固定する)。
+     *
     * @return Organization|null 参加した組織 / ...
```

その他の指摘 (既メンバー null return 経路 / トランザクション境界 / テスト網羅 / セキュリティ) はいずれも Suggestion もしくは「現時点で意図通り」判定だったため、コード変更なし。既メンバー fallback で current を触らない点は個人組織パス (provision) が current を据えるため未設定にはならず、POST 受諾非変更テスト (2-6) で固定済みです。

### 再検証結果
- composer phpstan (level 10): No errors
- vendor/bin/pint --test: passed
- (DocBlock のみの変更のため実行時挙動・テスト結果は Round 1 から不変: 1610 passed / 2 skipped)

この対応で register 専用前提の暗黙性は解消されたと考えます。全体判定 (APPROVED / CHANGES_REQUESTED) を再度お願いします。
