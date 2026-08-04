**レビュー総括（仮説ベース）**
- 仮説: 本設計は「誤検知低減」には有効だが、現状のままだと一部ケースで「偽陰性（取りこぼし）」に寄る可能性がある。
- 重大度判断: **Critical はなし**、ただし **Warning 2 件は修正推奨**。

**施策別判定**
- **施策1 `feedback-probe.js`**: **REQUEST_CHANGES**
  - [Warning] `MutationObserver` の `childList` で「既存 live-region 内のテキストノード差し替え」を `seen` に取り込めない可能性があります（`addedNodes` が Text ノードだと `collect()` で落ちる設計）。短命メッセージで偽陰性化し得ます。  
    修正案: `.claude/skills/app-bug-hunt/probes/feedback-probe.js` の observer で `r.type === "childList"` 時に `r.target` 側の `closest(LIVE)` も `enqueue()` 対象に含める。
  - [Suggestion] 同一メッセージの多重 push を抑えるため、`seen` への投入前に `role+testid+text` の短時間重複除去を入れると triage が安定します。

- **施策2 `SKILL.md` へのプロトコル組み込み**: **REQUEST_CHANGES**
  - [Warning] `installed_now:true` を一律「未検証」にすると、full-document 遷移が混ざる導線で H7 が恒常的に判定不能になり、取りこぼしに倒れるリスクがあります。  
    修正案: `.claude/skills/app-bug-hunt/SKILL.md` に「`installed_now:true` 発生時の補助判定」を追記（例: 直後再probe＋`snapshot` 併用で“肯定証拠のみ採用”、陰性は未検証のまま）し、`shard-report.md` に `H7-unverified` 件数を必須集計化する。
  - [Suggestion] 「操作結果を伝える文言」の判定語彙（成功/失敗/更新/削除など）を最小辞書として明文化すると、運用ぶれを減らせます。

- **施策3 `bughunt-shard.md` 1行追加**: **APPROVE**
  - 指摘なし（正本を `SKILL.md` に寄せる方針は一貫しており、二重管理回避も妥当）。

- **施策4 `spec-ledger.md` 拡張+申し送り**: **APPROVE**
  - [Suggestion] `verdict` 語彙対応の説明に `wont_fix` の扱いだけ補足しておくと、将来の記法揺れを防げます（節追加でなく注記でも可）。

- **施策5 `feedback-probe.test.ts`**: **APPROVE**
  - [Suggestion] 施策1修正後、`childList`（Text差し替え）専用ケースを1本追加すると回帰防止が完成します。

- **施策6 `test_spec_ledger.py`**: **APPROVE**
  - [Suggestion] 必須欄チェックを「キー文字列の存在」だけでなく `- **項目名**:` 形式まで見ると、誤検知耐性が上がります。

**全体判定**
- **CHANGES_REQUESTED**（施策1・施策2の Warning 修正後に再レビューで APPROVED 相当）