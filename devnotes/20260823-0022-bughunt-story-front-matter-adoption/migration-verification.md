# 移行の検算

`devnotes/20260823-0022-bughunt-story-front-matter-adoption/migrate_story_assignment.py verify`
の出力である (手で書かない)。

- 変換前の観測点: `3c9f32d4cdf2b200b60e8e623c0108d05704b0fb` の `.claude/skills/app-bug-hunt/inventory/annotations.toml`
- 判定: **成功**

## 全差分 (欄 / route / 変換前 / 変換後)

| 欄 | route | 変換前 | 変換後 | 判定 |
|---|---|---|---|---|
| screens | capture.manuals.show | S3 | S3 S7 | 変換後のみ (S7 の追加分) |
| screens | capture.takes.playback | S3 | S3 S7 | 変換後のみ (S7 の追加分) |
| screens | projects.categories.index | S4 | S4 S7 | 変換後のみ (S7 の追加分) |
| screens | projects.edit | S4 | S4 S7 | 変換後のみ (S7 の追加分) |
| screens | projects.manuals.download | S3 | S3 S7 | 変換後のみ (S7 の追加分) |
| screens | projects.manuals.edit | S3 | S3 S7 | 変換後のみ (S7 の追加分) |
| screens | projects.manuals.jobs.show | S3 | S3 S7 | 変換後のみ (S7 の追加分) |
| screens | projects.manuals.render-jobs.playback | S3 | S3 S7 | 変換後のみ (S7 の追加分) |
| screens | projects.manuals.render-jobs.show | S3 | S3 S7 | 変換後のみ (S7 の追加分) |
| screens | projects.manuals.show | S3 | S3 S7 | 変換後のみ (S7 の追加分) |
| screens | projects.show | S3 | S3 S7 | 変換後のみ (S7 の追加分) |
| operations | capture.takes.adopt | S3 | S3 S7 | 変換後のみ (S7 の追加分) |
| operations | capture.takes.destroy | S3 | S3 S7 | 変換後のみ (S7 の追加分) |
| operations | projects.categories.destroy | S4 | S4 S7 | 変換後のみ (S7 の追加分) |
| operations | projects.categories.reorder | S4 | S4 S7 | 変換後のみ (S7 の追加分) |
| operations | projects.categories.update | S4 | S4 S7 | 変換後のみ (S7 の追加分) |
| operations | projects.manuals.destroy | S3 | S3 S7 | 変換後のみ (S7 の追加分) |
| operations | projects.manuals.duplicate | S3 | S3 S7 | 変換後のみ (S7 の追加分) |
| operations | projects.manuals.scenario.update | S3 | S3 S7 | 変換後のみ (S7 の追加分) |
| operations | projects.manuals.update | S3 | S3 S7 | 変換後のみ (S7 の追加分) |

## 集計

| 欄 | 一致 | 変換前のみ (落ちた) | 変換後のみ (S7 の追加分) |
|---|---|---|---|
| screens | 60 | 0 | 11 |
| operations | 70 | 0 | 9 |

## 期待する S7 追加分との完全一致

| 欄 | 期待 | 実測 | 判定 |
|---|---|---|---|
| screens | 11 件 (capture.manuals.show, capture.takes.playback, projects.categories.index, projects.edit, projects.manuals.download, projects.manuals.edit, projects.manuals.jobs.show, projects.manuals.render-jobs.playback, projects.manuals.render-jobs.show, projects.manuals.show, projects.show) | 11 件 | 一致 |
| operations | 9 件 (capture.takes.adopt, capture.takes.destroy, projects.categories.destroy, projects.categories.reorder, projects.categories.update, projects.manuals.destroy, projects.manuals.duplicate, projects.manuals.scenario.update, projects.manuals.update) | 9 件 | 一致 |

## 対象外 route (両側とも空集合であること)

| route | 変換前 | 変換後 |
|---|---|---|
| debug.bfcache-trial | (空) | (空) |
| debug.bfcache-trial.away | (空) | (空) |
| debug.login | (空) | (空) |
| password.confirmation | (空) | (空) |
| seo.ai | (空) | (空) |
| seo.llms | (空) | (空) |
| seo.robots | (空) | (空) |
| seo.sitemap | (空) | (空) |
| social.callback | (空) | (空) |
| social.redirect | (空) | (空) |
| two-factor.qr-code | (空) | (空) |
| two-factor.recovery-codes | (空) | (空) |
| two-factor.secret-key | (空) | (空) |
| webhooks.ses | (空) | (空) |

## S7 が踏み直す 11 画面の選定根拠

| 境界の種別 | route |
|---|---|
| project 自身の current-org 境界 | `projects.show` / `projects.edit` |
| project 配下 manual の親子境界 | `projects.manuals.show` / `projects.manuals.edit` / `projects.manuals.download` |
| manual 配下の take / render / job の親子境界 | `projects.manuals.jobs.show` / `projects.manuals.render-jobs.show` / `projects.manuals.render-jobs.playback` |
| project 配下 category の親子境界 | `projects.categories.index` |
| capture 経由で manual / take へ到達する境界 | `capture.manuals.show` / `capture.takes.playback` |

## `## 手順` 節の不変 (移行前後の sha256。先頭 16 文字)

| カード | 移行前 | 移行後 | 判定 |
|---|---|---|---|
| S1 | `be9a3b695a3b8592` | `be9a3b695a3b8592` | 一致 |
| S2 | `b6a4ba3f9daaaf32` | `b6a4ba3f9daaaf32` | 一致 |
| S3 | `80ea3ae1b00418a2` | `80ea3ae1b00418a2` | 一致 |
| S4 | `75b0f67d66c270c4` | `75b0f67d66c270c4` | 一致 |
| S5 | `a3057bd74bc0d83a` | `a3057bd74bc0d83a` | 一致 |
| S6 | `e33bc4030adba9be` | `e33bc4030adba9be` | 一致 |
| S7 | `ebd4526f51f01afb` | `ebd4526f51f01afb` | 一致 |
