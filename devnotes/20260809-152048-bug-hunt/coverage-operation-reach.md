# bug-hunt 操作到達カバレッジ (operation-reach) — run 20260809-152048

> 主出力 = **未カバー worklist**。絶対 % は副 (summary の `*_pct` のみ)・目標にしない。
> 分母 (in_scope 機構) = **76** 件 (区分 '外' を除く)。 分母変更時はこの値の差分を注記すること (gaming 防止)。

## ① 未実行機構 (in_scope ∧ ¬executed) — 6 件

| route | operation | story | 区分 | findings |
|---|---|---|---|---|
| organizations.api-keys.sessions.revoke | organizations/{organization:slug}/api-keys/sessions/{oauthSession} | S4 | 通常 | 2 |
| projects.members.store | projects/{project}/members | S4 | 通常 | 2 |
| debug.login-as | debug/login/{userId} | S1 | 通常 | 1 |
| passkey.login | passkeys/login | S1 | 通常 | 1 |
| passkey.store | user/passkeys | S6 | 通常 | 1 |
| invitations.accept-in-app | invitations/{invitation}/accept-in-app | S2 | 通常 | - |

## ★ cross: 未実行 ∧ finding 多 (≥2) — 2 件 [埋める優先度: 最高]

| route | operation | story | findings | severities | capability |
|---|---|---|---|---|---|
| organizations.api-keys.sessions.revoke | organizations/{organization:slug}/api-keys/sessions/{oauthSession} | S4 | 2 | needs_review×2 | ORG-04,MEM-04 (cap経由) |
| projects.members.store | projects/{project}/members | S4 | 2 | needs_review×2 | ORG-04,MEM-04 (cap経由) |

## ③ finding hotspot (finding_count ≥ 2) — 35 件

| route | findings | severities | executed | capability |
|---|---|---|---|---|
| capture.takes.adopt | 2 | medium×1, low×1 | yes | REN-04,CAP-03 (cap経由) |
| capture.takes.destroy | 2 | medium×1, low×1 | yes | REN-04,CAP-03 (cap経由) |
| capture.takes.downloaded | 2 | medium×1, low×1 | yes | REN-04,CAP-03 (cap経由) |
| capture.takes.store | 2 | medium×1, low×1 | yes | REN-04,CAP-03 (cap経由) |
| capture.takes.update | 2 | medium×1, low×1 | yes | REN-04,CAP-03 (cap経由) |
| capture.takes.upload-url | 2 | medium×1, low×1 | yes | REN-04,CAP-03 (cap経由) |
| organizations.api-keys.revoke | 2 | needs_review×2 | yes | ORG-04,MEM-04 (cap経由) |
| organizations.api-keys.sessions.revoke | 2 | needs_review×2 | NO | ORG-04,MEM-04 (cap経由) |
| organizations.api-keys.store | 2 | needs_review×2 | yes | ORG-04,MEM-04 (cap経由) |
| organizations.store | 2 | needs_review×2 | yes | ORG-04,MEM-04 (cap経由) |
| organizations.switch | 2 | needs_review×2 | yes | ORG-04,MEM-04 (cap経由) |
| organizations.transfer-ownership | 2 | needs_review×2 | yes | ORG-04,MEM-04 (cap経由) |
| organizations.two-factor-requirement.update | 2 | needs_review×2 | yes | ORG-04,MEM-04 (cap経由) |
| organizations.update | 2 | needs_review×2 | yes | ORG-04,MEM-04 (cap経由) |
| projects.categories.destroy | 2 | needs_review×2 | yes | ORG-04,MEM-04 (cap経由) |
| projects.categories.reorder | 2 | needs_review×2 | yes | ORG-04,MEM-04 (cap経由) |
| projects.categories.store | 2 | needs_review×2 | yes | ORG-04,MEM-04 (cap経由) |
| projects.categories.update | 2 | needs_review×2 | yes | ORG-04,MEM-04 (cap経由) |
| projects.destroy | 2 | needs_review×2 | yes | ORG-04,MEM-04 (cap経由) |
| projects.items.destroy | 2 | needs_review×2 | yes | ORG-04,MEM-04 (cap経由) |
| projects.items.store | 2 | needs_review×2 | yes | ORG-04,MEM-04 (cap経由) |
| projects.items.update | 2 | needs_review×2 | yes | ORG-04,MEM-04 (cap経由) |
| projects.manuals.analyze | 2 | medium×1, low×1 | yes | REN-04,CAP-03 (cap経由) |
| projects.manuals.duplicate | 2 | medium×1, low×1 | yes | REN-04,CAP-03 (cap経由) |
| projects.manuals.destroy | 2 | medium×1, low×1 | yes | REN-04,CAP-03 (cap経由) |
| projects.manuals.preview | 2 | medium×1, low×1 | yes | REN-04,CAP-03 (cap経由) |
| projects.manuals.render | 2 | medium×1, low×1 | yes | REN-04,CAP-03 (cap経由) |
| projects.manuals.scenario.update | 2 | medium×1, low×1 | yes | REN-04,CAP-03 (cap経由) |
| projects.manuals.source-documents.store | 2 | medium×1, low×1 | yes | REN-04,CAP-03 (cap経由) |
| projects.manuals.store | 2 | medium×1, low×1 | yes | REN-04,CAP-03 (cap経由) |
| projects.manuals.update | 2 | medium×1, low×1 | yes | REN-04,CAP-03 (cap経由) |
| projects.members.destroy | 2 | needs_review×2 | yes | ORG-04,MEM-04 (cap経由) |
| projects.members.store | 2 | needs_review×2 | NO | ORG-04,MEM-04 (cap経由) |
| projects.store | 2 | needs_review×2 | yes | ORG-04,MEM-04 (cap経由) |
| projects.update | 2 | needs_review×2 | yes | ORG-04,MEM-04 (cap経由) |

## ② TESTED_BY untested (TS 面のみ) — 0 件

> PHP route の TESTED_BY は graph 非対応 = unknown_graph_gap **76** 件 (件数のみ・worklist 本文に出さない)。 PHP の実テストは Pest を別途参照すること。

(なし)

## ⑤ summary (trend 用・% は副)

- unexecuted_count (主): **6** / in_scope 76
- cross_count (★主): **2**
- hotspot_count: 35
- untested_real_count (TS): 0
- unknown_graph_gap_count (PHP): 76
- skipped_blocked_count (status skip/block = 未実走扱い): 0
- executed_pct (副・目標にしない): 92%

