**レビュー結果**

- 依頼どおり、提示されたテキスト差分のみを対象に静的レビューしました（コマンド実行・書き込みなし）。
- 結論は **CHANGES_REQUESTED** です。主因は `Cache-Control: no-store` の付与条件が「`invitationEmail !== null`」のみで、空文字ケースで PII 非含有応答にも `no-store` が付く可能性がある点（設計S3の「PIIを含む応答にのみ付与」と微ズレ）です。

**ファイル別判定**

- `app/Models/OrganizationInvitation.php`
  - **判定**: OK
  - **評価**: S1実装は妥当。`findActiveByPlainToken()` が `active()` + `token_hash` 照合に限定され、列挙面を増やしていません。`expires_at > now` を `scopeActive` に委譲してドリフト抑止も良いです。
  - **Suggestion**: なし（コメントも意図明確）。

- `app/Services/Organization/OrganizationMembershipService.php`
  - **判定**: 条件付きOK
  - **評価**: S2は概ね設計一致。`Session` からの fail-secure 解決（非文字列/空/stale/invalid forget、active時は保持）も正しい。`whereBlind` 等PII検索面も増やしていません。
  - **Warning**: `if ($email === '')` で空文字を fail-secure 扱いしているが、型上は `string` で妥当でも、上位（Fortify）側の no-store 条件が `!== null` のため「空文字返却時に no-store が付く」分岐が理論上残ります（S3意図と接続不整合）。

- `app/Providers/FortifyServiceProvider.php`
  - **判定**: 要修正
  - **Critical**: S3要件「`invitationEmail !== null` 応答にのみ no-store」を形式上満たしている一方、S2側が将来変更され空文字を返すと、PII非含有でも `no-store` が付与されうる設計です。  
    - 現状S2では空文字を返さない実装ですが、層間契約が暗黙で脆いです。  
    - **推奨**: no-store条件を `is_string($invitationEmail) && $invitationEmail !== ''` にするか、`resolveRegisterPrefillEmail` の戻り契約を「非nullなら必ず有効email文字列」とPHPDoc/テストで強制してください。
  - **Suggestion**: `toResponse($request)` 明示は適切で、header操作位置も良いです。

- `app/Rules/MatchesInvitationEmail.php`
  - **判定**: OK
  - **評価**: S1の重複排除に沿っており、activeでない招待をここで弾かず後段中立処理へ渡す既存契約を維持。正確性・fail-secureともに妥当。

- `resources/js/pages/Auth/Register.svelte`
  - **判定**: OK
  - **評価**: S4実装は設計一致。`invitationEmail` prefill + `readonly` + 補足文言、かつボタンdisabled化なしで DESIGN.md #8 非抵触。FormField help 経由でDS token使用、hex追加なし、Atomic方向も問題なし。
  - **Suggestion**: なし。

- `tests/Feature/Auth/RegistrationInvitationPrefillTest.php`
  - **判定**: 良好
  - **評価**: S5として非常に網羅的。正常/失効/取消/受諾済/不在/非文字列/no-token/no-store/フォールバックまで押さえており、セキュリティ判定（列挙面維持・bearer前提）とも整合。
  - **Warning**: `invitationEmail === ''` を明示的に禁止する仕様テストがあると、S2↔S3契約がより堅牢になります。

- `tests/js/pages/Register.test.ts`
  - **判定**: OK
  - **評価**: UI回帰防止として十分（prefill時readonly・文言、通常時非readonly）。Atomic/DS観点で問題なし。

- `tests/Architecture/MembershipWriteLockInventoryTest.php`
  - **判定**: OK
  - **評価**: read-only扱いの `exempt` 追加は妥当。共有ロック規約の対象外理由も明記され、ドリフトガードとして適切。

**設計施策 S1〜S5 総評**

- S1: 達成
- S2: 達成（fail-secure良好）
- S3: **実質達成だが契約の明文化不足で修正推奨**
- S4: 達成
- S5: 達成（高網羅）

**セキュリティ判定（概念設計との整合）**

- token_hash照合成功時のみPII返却、平文email探索なし、列挙面非拡大は守られています。
- bearer tokenモデルの残余リスク受容（リンク転送/誤送信）は実装と整合。
- readonlyを境界にしていない点も正しい（サーバ側 `MatchesInvitationEmail` で担保）。

**全体判定**

- **CHANGES_REQUESTED**

**最小修正提案（1点）**
- `app/Providers/FortifyServiceProvider.php` の no-store 条件を、PII実在時に限定する明示条件へ変更（例: `is_string($invitationEmail) && $invitationEmail !== ''`）。  
  併せて `RegistrationInvitationPrefillTest` に「空文字は返さない/空文字時no-storeを付けない」いずれかの契約テストを1件追加すると、S2-S3の層間契約が固定できます。