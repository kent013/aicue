全体判定: **CHANGES_REQUESTED**

概念設計の方向性は概ね妥当です。保守項目を「偽赤・偽グリーン・運用事故の芽」として束ねる判断、gate 化を絞る判断、既存スクリプトを再利用して重複実装を避ける判断は North Star に間接的に貢献します。

ただし、**施策 4 と施策 5 の安全性説明が強すぎます**。ここは詳細設計前に修正が必要です。

## 指摘

### [Warning] 施策 4: 孤児テスト DB 回収は「禁止事項 3」に近い領域

三重 guard と既定 dry-run はよい設計ですが、`--apply` を用意する以上、これは実質的に DB DROP 経路です。AGENTS.md の「dev DB への破壊操作をエージェント判断で実行しない」に抵触しないためには、**コマンドの安全性**だけでなく、**誰がどの条件で apply できるか**を設計に入れる必要があります。

修正提案:

- `--orphans` は常に dry-run 既定で維持する。
- `--apply` は人間の明示指示がある場合のみ実行可能、と明記する。
- `--apply` 時は `--confirm-orphan-drop=<printed-token>` のような確認トークンを要求する。
- drop 対象一覧、除外理由、live worktree hash 一覧を必ず出力する。
- `app`, `app_test`, `bug_hunt`, `bug_hunt_N` など既知 DB を denylist に含める。
- worktree 作成/削除中の race に備え、可能なら既存のグローバルロックか専用 lock を使う。

この修正があれば、禁止事項 3 への抵触リスクはかなり下げられます。

### [Warning] 施策 5: 「git rm --cached は working tree を触らないから安全」は論拠として不十分

`git rm --cached` が作業ツリーの実体ファイルを直接削除しない、という説明は正しいです。ただし、それだけでは安全とは言えません。index から消した entry は、コミット後にはリポジトリ上の tracked file から消えるため、他環境の checkout や clean 操作では消えます。

見落としうる失敗モード:

- NFD 側にしか存在しない内容を index から落とす。
- NFC/NFD の path は重複しているが blob が完全一致していない。
- macOS など Unicode 正規化に敏感な filesystem で checkout collision が起きる。
- `core.precomposeunicode = true` はローカル設定であり、リポジトリ全体の恒久対策ではない。
- `git rm --cached` 後の untracked file が後続 cleanup で消される。
- pathspec の正規化ミスで想定外の entry を index から外す。

修正提案:

- 削除対象 58 件について、対応する NFC entry が存在することを必須条件にする。
- `git ls-files -s` で blob hash が一致することを検証する。
- 一致しないものは自動削除せず、詳細設計で個別判断に回す。
- 削除対象 manifest を devnotes に残す。
- 作業後に `git status --porcelain=v1 -uall` と `git ls-files` の正規化衝突 0 を受入条件にする。
- 「working tree を触らないから安全」ではなく、「対応 NFC entry が同一 blob で残ることを検証してから index entry のみを整理するため、リポジトリ内容の意味を失わない」と説明を修正する。

### [Warning] 施策 5 → 施策 4 の順序依存は概ね正しいが、表現が強すぎる

「5 を先にしないと 4 が無意味になる」という主張は運用上は理解できます。NFC/NFD 重複が残る限り teardown が失敗し、孤児 DB が再発するからです。

ただし、技術的には施策 4 の実装や dry-run は施策 5 の前でも可能です。必須なのは、**DROP の apply 前、または完了判定前に施策 5 が終わっていること**です。

修正提案:

- 「グループ C の内部順序は 5 → 4 必須」ではなく、
  「孤児 DB を 0 として完了判定する前に、5 を完了させる必要がある」
  と表現を弱める。
- 実装順は `4 の純関数・テスト追加 → 5 → 4 の apply` でもよい、と許容する。

### [Warning] gate 化条件は妥当だが、条件 (a) は例外を許すべき

`(a) 実際にドリフトが発生した実績がある` は今回の保守バッチには合っています。ただし、セキュリティ不変条件や破壊的操作の guard では、実害発生前に gate 化すべき場合があります。

修正提案:

- gate 条件に例外を追加する。
- 例: 「ただし、セキュリティ不変条件・破壊的操作・課金冪等性・cross-org 防止など、発生時の被害が大きいものは実績なしでも gate 化できる」。

### [Suggestion] 4 グループ分割は概ね適切

A/B/C/D の分割は妥当です。依存関係、変更ファイル、検証レーンが分かれており、レビューもしやすいです。

ただし C は破壊リスクが他より高いため、さらに以下のように段階を明記するとよいです。

- C1: index 正規化の検証と manifest 作成
- C2: index entry 整理
- C3: orphan DB dry-run
- C4: 人間確認後の apply

### [Suggestion] 期待効果は合理的だが、定量目標に「確認方法」を足すとよい

`孤児 DB → 0 個` や `git ls-files doc/reference | wc -l → 139` は明確です。一方で、DB DROP や index 操作は環境差が出るため、成功条件に「何をもって対象外としたか」も含めると安全です。

例:

- live worktree hash に対応する DB は残す。
- denylist DB は残す。
- allowlist 外 DB は触らない。
- NFC/NFD 衝突は 0。
- NFC 側の blob は削除前後で一致。

## 観点別評価

- **使命との整合性**: [Suggestion] 間接貢献だが妥当。機能追加ではなく、次サイクルの開発信頼性を上げる位置付けで問題ない。
- **禁止事項違反**: [Warning] 施策 4 は `--apply` の運用条件を明記しないと禁止事項 3 に近い。
- **実現可能性**: [Suggestion] Laravel / Svelte / Inertia の範囲で実現可能。既存 script / architecture test 活用も妥当。
- **期待効果の妥当性**: [Suggestion] 偽赤除去、inventory drift 防止、孤児 DB 抑制は合理的に期待できる。
- **リスク**: [Warning] 施策 5 の index 操作と施策 4 の DROP 経路が主要リスク。安全性説明を補強すべき。
- **スコープの適切さ**: [Suggestion] 7 件を扱いつつ、zod major 分裂や parser 共通化を送る判断は妥当。
- **型安全性**: [Suggestion] DTO/JsonResource に触れる変更は少ない。PHP 側は純関数化、PHPStan level 10 前提で問題ない。

結論として、設計の骨子は承認可能ですが、**DB DROP の運用条件**と**git index 正規化の安全条件**を修正しないまま詳細設計に進むのは危険です。