以下、提示 diff の**静的レビュー**です（実行・書き込みなし）。

**`.claude/skills/app-bug-hunt/probes/feedback-probe.js` — 判定: 要修正**
- [Warning] `pending` の増減が例外安全ではありません。`raf` コールバック内で `visible()`/`getComputedStyle()` 起因の例外が出ると `pending--` されず、以後ずっと `pending>0` 扱いになり H7 判定が恒常的に「未検証」へ倒れるリスクがあります。`try/finally` で対称性を固定すべきです（`.claude/skills/app-bug-hunt/probes/feedback-probe.js:89`）。
- [Suggestion] `childList` の `addedNodes` 経由と `target.closest(LIVE)` 経由で同一 live region を同一 mutation で二重 enqueue し得ます。件数ノイズ抑制のため、1 callback 内 dedupe（`WeakSet` 等）を検討してください（`.claude/skills/app-bug-hunt/probes/feedback-probe.js:98`）。

**`.claude/skills/app-bug-hunt/SKILL.md` — 判定: 概ね良好**
- [Suggestion] 「最初の書き込み操作」前に arm が必ず成立する条件を明文化すると運用が一意になります（現記述は `open/goto/...` 直後 or 書き込み直後のため、開始状況依存の解釈余地あり）（`.claude/skills/app-bug-hunt/SKILL.md:268`）。
- [Suggestion] `pending > 0` 時の再 probe 前に短待機（1 tick 相当）を規約化すると、不要な `H7 未検証` 増加を減らせます（`.claude/skills/app-bug-hunt/SKILL.md:281`）。

**`.claude/agents/bughunt-shard.md` — 判定: OK**
- 指摘なし。SKILL 正本参照の一本化方針と整合しています（`.claude/agents/bughunt-shard.md:73`）。

**`.claude/skills/app-bug-hunt/spec-ledger.md` — 判定: 概ね良好**
- [Suggestion] `watch_globs` 欄が説明文寄りになっているため、将来の人手運用で誤解しないよう「watch_globs は registry 正本参照のみ」の定型句をテンプレ側にも明示するとより安全です（`.claude/skills/app-bug-hunt/spec-ledger.md:107`）。

**`.claude/skills/app-bug-hunt/ledger/test_spec_ledger.py` — 判定: 良好**
- [Suggestion] 根拠パス抽出が `#L10` 形式等を拾わないため、その記法が混入した際に存在チェックをすり抜けます。許容記法を増やすか、明示的に禁止して fail に倒すと堅いです（`.claude/skills/app-bug-hunt/ledger/test_spec_ledger.py:56`）。

**`tests/js/bughunt/feedback-probe.test.ts` — 判定: 良好**
- [Suggestion] 順序依存をコメントでなく実行属性（`sequential`）でも固定しておくと、将来設定変更時の非意図並列化を防げます（`tests/js/bughunt/feedback-probe.test.ts:108`）。

**CHANGES_REQUESTED**