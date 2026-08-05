**全体判定: CHANGES_REQUESTED**

設計の方向性は概ね妥当ですが、C2 は「comment を分類材料にする」前提と `COMMENT ON DATABASE` の実装位置に安全上の穴があります。C1 もロールバック手順に禁止操作が混じっています。ここは修正必須です。

**A1: APPROVE**

[Suggestion] `pcrePatternLiterals()` の「同じデリミタの最後の出現位置」を終端にする方式は、escaped delimiter や文字クラス内 delimiter で雑に見えます。ただし本ゲートは `\R` の `/u` 欠落検出に限定され、正コントロールも十分なので許容範囲です。実装時は「完全な PCRE parser ではない」ことをコメントに残すとよいです。

[Suggestion] double-quoted 文字列の扱いは明記不足です。PHP の文字列 token から raw body を取るなら、`\\R` と `\R` のどちらを検出対象にするかをテストに足してください。

**A2: APPROVE**

[Suggestion] `pgid=""` の初期化 + `|| pgid=""` は狙った `set -euo pipefail` 偽赤を閉じます。C25 は 20 回反復で妥当です。

[Suggestion] `_gtl_probe_process_group()` 側は「ps 失敗を握る」ことで、本当に `ps` が使えない環境の検出が弱くなります。既存の `HAVE_PS`/skip 方針と整合するよう、`ps` 不在時は C25 だけでなく probe 全体の期待挙動を明文化してください。

**B1: APPROVE**

[Warning] `screens.md` に JSON GET options endpoint を載せるのは既存先例があるため許容ですが、「GET×inertia」という見出しと実態がずれています。修正案: 表名または説明を「GET×web inventory」に寄せ、Inertia 画面と JSON GET を区別する列か注記を追加してください。

[Suggestion] W16 は `runScript(...).toContain()` だけだとコメント内でも green になり得ます。既存テストの粒度に合わせるなら許容ですが、形骸化を避けるなら `runLines()` で実行行だけを見る方が堅いです。

**B2: REQUEST_CHANGES**

[Warning] G2 が AGENTS.md 全体を検索する設計は形骸化しやすいです。設計内でもリスクを認めていますが、ここは新規ゲートなので最初からマーカー範囲に限定すべきです。修正案: `<!-- VERIFICATION_COMMANDS:BEGIN -->` / `END` を AGENTS.md と app-implement SKILL に入れ、その範囲だけを照合してください。

[Warning] AGENTS.md の不変条件 9/10 は長く、既存の「セキュリティ不変条件」欄が運用 runbook 化しています。修正案: AGENTS.md は短い不変条件と参照先に留め、詳細な順序・middleware 名は `docs/app-integration-guide.md` / `docs/architecture.md` を正本にしてください。番号非対応の注記は妥当です。

**C1: REQUEST_CHANGES**

[Critical] ロールバック R2 に `git reset --hard <BASE_SHA>` が入っています。AGENTS.md の破壊的コマンド禁止と衝突します。task branch 内でも、ユーザー変更を消し得るため設計として不可です。修正案: 未マージ rollback は `git revert <commit>` を原則にし、未コミット状態は R1 の index 復元で戻す。どうしても reset が必要な場合は「人間の明示承認が必要」と書いてください。

[Warning] `git config core.precomposeunicode true` はローカル設定で、受入条件や rollback に含めると実装者の環境差を増やします。修正案: 恒久対策は index 正規化 + ゲートに限定し、local config は任意の補助手順として分離してください。

[Warning] V-C4 の「NFC 側 blob 集合が一致」は blob 集合だけだと、同一 blob の別 path 消失を検出できません。修正案: NFC 正規化後 path → blob の map を前後で比較してください。

**C2: REQUEST_CHANGES**

[Critical] `ensure-test-db.php` が既存 DB なら `COMMENT` を付けずに exit します。既に作成済みの生存 base DB は unlabeled のまま残り、後で `--include-unlabeled` の対象になり得ます。修正案: DB が既存でも、接続できた場合は best-effort で `COMMENT ON DATABASE` を更新してから exit してください。

[Critical] `COMMENT ON DATABASE` 失敗を warning で握る方針と、unlabeled を `--include-unlabeled` で drop 可能にする方針の組み合わせが危険です。権限不足や一時失敗で provenance が付かない現役 DB が、将来の掃除対象に混じります。修正案: `ensure-test-db.php` では COMMENT 失敗を原則 fail-closed にするか、少なくとも「このプロセスで作成した base DB の COMMENT 失敗時は DB を削除して失敗」にしてください。既存 DB 更新失敗だけ warning にするなら、その hash を protected/dry-run で強調表示する必要があります。

[Warning] canonical token に `include_unlabeled` が含まれていません。DROP 対象リストが同じなら実害は限定的ですが、承認文脈の一部です。修正案: canonical JSON に `include_unlabeled` と分類バージョンを含めてください。

[Warning] `--protect-hash` は token に含まれますが、hash だけでは cross-clone の人間確認に弱いです。修正案: dry-run に hash → provenance/live path を必ず併記し、protected hash の形式検証 `[0-9a-f]{8}` をテストに追加してください。

[Warning] AGENTS.md 禁止事項 3 との関係は「人間の明示指示がある場合のみ apply」で整理されていますが、エージェントが dry-run token を読んで `--apply` する余地を文章だけで塞いでいます。修正案: `--apply` 実行手順に「Codex/LLM は実行禁止。ユーザーがコマンドを実行するか、明示承認した場合のみ」と明記してください。

**D1: APPROVE**

[Warning] `eslint-plugin-better-tailwindcss` の minor upgrade で lint 差分が大きい場合の代替案が `overrides` になっていますが、overrides は supply-chain 追跡を複雑にします。修正案: lint 差分が大きい場合は D1 を分割し、`undici` だけ先に入れる判断基準を追加してください。

**新設ゲート 4 本の評価**

[Warning] A1/G1/C1 は deny-by-default と正負コントロールがあり、形骸化しにくいです。G2 と W16 はコメント・文書全体検索で green になる余地があります。修正案: 実行行・マーカー範囲に解析対象を絞ってください。

[Warning] 下限ガードは概ね妥当です。ただし P2「PCRE 100 件以上」と N2「index 500 件以上」はリポジトリ規模変化で将来の偽赤になり得ます。修正案: 現在値から大きく下げた固定値にするか、「前提ディレクトリが存在し、代表ファイルを含む」検査を併用してください。