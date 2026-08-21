# bug-hunt 操作到達カバレッジ (operation-reach) — run 20260821-095643

> 主出力 = **未カバー worklist**。絶対 % は副 (summary の `*_pct` のみ)・目標にしない。
> 分母 (in_scope 機構) = **78** 件 (区分 '外' を除く)。 分母変更時はこの値の差分を注記すること (gaming 防止)。

## ① 未実行機構 (in_scope ∧ ¬executed) — 19 件

| route | operation | story | 区分 | findings |
|---|---|---|---|---|
| invitations.accept-in-app | invitations/{invitation}/accept-in-app | S2 | 通常 | 4 |
| capture.takes.destroy | app/projects/{project}/manuals/{manual}/cuts/{cut}/takes/{take} | S3 | 通常 | 2 |
| capture.takes.update | app/projects/{project}/manuals/{manual}/cuts/{cut}/takes/{take} | S3 | 通常 | 2 |
| projects.manuals.destroy | projects/{project}/manuals/{manual} | S3 | 通常 | 2 |
| projects.manuals.source-documents.store | projects/{project}/manuals/{manual}/source-documents | S3 | 通常 | 2 |
| projects.manuals.update | projects/{project}/manuals/{manual} | S3 | 通常 | 2 |
| billing.auto-recharge.setup | billing/auto-recharge/setup | S5 | 通常 | 1 |
| billing.portal | billing/portal | S5 | 通常 | 1 |
| billing.tickets.checkout | purchase-tickets/checkout | S5 | 通常 | 1 |
| passkey.confirm | passkeys/confirm | S6 | 通常 | 1 |
| passkey.destroy | user/passkeys/{passkey} | S6 | 通常 | 1 |
| passkey.store | user/passkeys | S6 | 通常 | 1 |
| password.confirm.store | user/confirm-password | S6 | 通常 | 1 |
| settings.password.store | settings/password | S6 | 通常 | 1 |
| debug.login-as | debug/login/{userId} | S1 | 通常 | - |
| passkey.login | passkeys/login | S1 | 通常 | - |
| organizations.api-keys.sessions.revoke | organizations/{organization}/api-keys/sessions/{oauthSession} | S4 | 通常 | - |
| organizations.transfer-ownership | organizations/{organization}/transfer-ownership | S4 | 通常 | - |
| organizations.two-factor-requirement.update | organizations/{organization}/two-factor-requirement | S4 | 通常 | - |

## ★ cross: 未実行 ∧ finding 多 (≥2) — 6 件 [埋める優先度: 最高]

| route | operation | story | findings | severities | capability |
|---|---|---|---|---|---|
| invitations.accept-in-app | invitations/{invitation}/accept-in-app | S2 | 4 | critical×2, medium×1, needs_review×1 | MEM-04,MEM-03,MEM-05 (cap経由) |
| capture.takes.destroy | app/projects/{project}/manuals/{manual}/cuts/{cut}/takes/{take} | S3 | 2 | high×1, medium×1 | SOP,CAP-01 (cap経由) |
| capture.takes.update | app/projects/{project}/manuals/{manual}/cuts/{cut}/takes/{take} | S3 | 2 | high×1, medium×1 | SOP,CAP-01 (cap経由) |
| projects.manuals.destroy | projects/{project}/manuals/{manual} | S3 | 2 | high×1, medium×1 | SOP,CAP-01 (cap経由) |
| projects.manuals.source-documents.store | projects/{project}/manuals/{manual}/source-documents | S3 | 2 | high×1, medium×1 | SOP,CAP-01 (cap経由) |
| projects.manuals.update | projects/{project}/manuals/{manual} | S3 | 2 | high×1, medium×1 | SOP,CAP-01 (cap経由) |

## ③ finding hotspot (finding_count ≥ 2) — 22 件

| route | findings | severities | executed | capability |
|---|---|---|---|---|
| invitations.accept-in-app | 4 | critical×2, medium×1, needs_review×1 | NO | MEM-04,MEM-03,MEM-05 (cap経由) |
| invitations.accept.store | 4 | critical×2, medium×1, needs_review×1 | yes | MEM-04,MEM-03,MEM-05 (cap経由) |
| organizations.invitations.revoke | 4 | critical×2, medium×1, needs_review×1 | yes | MEM-04,MEM-03,MEM-05 (cap経由) |
| organizations.invitations.store | 4 | critical×2, medium×1, needs_review×1 | yes | MEM-04,MEM-03,MEM-05 (cap経由) |
| organizations.members.destroy | 4 | critical×2, medium×1, needs_review×1 | yes | MEM-04,MEM-03,MEM-05 (cap経由) |
| organizations.members.two-factor.reset | 4 | critical×2, medium×1, needs_review×1 | yes | MEM-04,MEM-03,MEM-05 (cap経由) |
| organizations.members.update | 4 | critical×2, medium×1, needs_review×1 | yes | MEM-04,MEM-03,MEM-05 (cap経由) |
| capture.takes.adopt | 2 | high×1, medium×1 | yes | SOP,CAP-01 (cap経由) |
| capture.takes.destroy | 2 | high×1, medium×1 | NO | SOP,CAP-01 (cap経由) |
| capture.takes.downloaded | 2 | high×1, medium×1 | yes | SOP,CAP-01 (cap経由) |
| capture.takes.store | 2 | high×1, medium×1 | yes | SOP,CAP-01 (cap経由) |
| capture.takes.update | 2 | high×1, medium×1 | NO | SOP,CAP-01 (cap経由) |
| capture.takes.upload-url | 2 | high×1, medium×1 | yes | SOP,CAP-01 (cap経由) |
| projects.manuals.analyze | 2 | high×1, medium×1 | yes | SOP,CAP-01 (cap経由) |
| projects.manuals.destroy | 2 | high×1, medium×1 | NO | SOP,CAP-01 (cap経由) |
| projects.manuals.duplicate | 2 | high×1, medium×1 | yes | SOP,CAP-01 (cap経由) |
| projects.manuals.preview | 2 | high×1, medium×1 | yes | SOP,CAP-01 (cap経由) |
| projects.manuals.render | 2 | high×1, medium×1 | yes | SOP,CAP-01 (cap経由) |
| projects.manuals.scenario.update | 2 | high×1, medium×1 | yes | SOP,CAP-01 (cap経由) |
| projects.manuals.source-documents.store | 2 | high×1, medium×1 | NO | SOP,CAP-01 (cap経由) |
| projects.manuals.store | 2 | high×1, medium×1 | yes | SOP,CAP-01 (cap経由) |
| projects.manuals.update | 2 | high×1, medium×1 | NO | SOP,CAP-01 (cap経由) |

## ② TESTED_BY untested (TS 面のみ) — 0 件

> PHP route の TESTED_BY は graph 非対応 = unknown_graph_gap **79** 件 (件数のみ・worklist 本文に出さない)。 PHP の実テストは Pest を別途参照すること。

(なし)

## ⑤ summary (trend 用・% は副)

- unexecuted_count (主): **19** / in_scope 78
- cross_count (★主): **6**
- hotspot_count: 22
- untested_real_count (TS): 0
- unknown_graph_gap_count (PHP): 79
- executed_ok_count (in_scope ∧ status ok): 59
- blocked_count (status blocked のみ = 未実走扱い): 8
- executed_pct (副・目標にしない): 76%

