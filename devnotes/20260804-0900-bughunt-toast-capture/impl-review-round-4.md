統合規則で Round 3 の Critical は閉じています。

- `seen` / `present_new` は和集合、`installed_now` / `errors` は全応答を通じた sticky 条件、`pending` は最終応答で評価され、陰性判断の必要条件が一意です（`.claude/skills/app-bug-hunt/SKILL.md:289`）。
- 判定不能、document 置換、arm 漏れのいずれも H7 陰性へ落ちる経路は見当たりません。
- N4 は `errors` が batch 単位で drain される前提を正しく固定し、統合規則が必要な理由とも一致しています（`tests/js/bughunt/feedback-probe.test.ts:257`）。

APPROVED