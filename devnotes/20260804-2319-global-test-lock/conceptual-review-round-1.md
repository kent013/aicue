**全体判定**  
CHANGES_REQUESTED

**1. 使命との整合性**
- [Warning] 期待効果の書き方が強すぎます。「flake がなくなる」「赤は本物の赤だけになる」は本設計の実際の守備範囲を超えています。bug-hunt 除外と clone 外競合が残るため、使命への貢献は大きいが「同一クローン内の主要テストレーン相互干渉を大幅に減らす」までに留めるべきです。修正提案: 期待効果をその表現に下げ、残余リスクを明記してください。
- [Suggestion] 方向性自体は North Star に整合しています。偽赤と相互破壊の削減は、複数 worktree 並行実装の実効速度に直結します。

**2. 禁止事項違反**
- [Warning] 禁止事項そのものへの明白な抵触は見当たりませんが、AGENTS.md の「テストファースト」は設計本文にまだ十分落ちていません。検証スイート新設だけでは足りず、「先に fail を作る」が必要です。修正提案: `verify-global-test-lock.sh` の最初の段階で、少なくとも「待機」「再入」「fd 非継承」「heartbeat」「Browser lane の掃除境界」の失敗ケースを先に確認する前提を明記してください。
- [Suggestion] `response()->json()`、Prism 直呼び、prompt 直書きなどの禁止事項とは無関係な層なので、その点は問題ありません。

**3. 実現可能性**
- [Critical] 最大の論点です。`git common dir` 起点の clone 単位ロックでは、本文が主因として挙げる H1 を構造的には消せません。`cleanup_orphan_playwright()` は `pgrep -f "playwright/cli.js run-server"` でマシン全体を走査するため、別 clone の Browser lane も巻き込めます。修正提案: 次のどちらかが必要です。  
  1. Browser lane だけは clone 単位ではなく machine 単位ロックにする。  
  2. 先に掃除ロジックを clone/worktree/session 識別可能な形へ狭める。pidfile、専用 marker、識別可能な起動引数などで自分の run-server だけを対象化してください。  
  現状のままでは「正しいロックスコープは clone 単位」という結論が H1 と矛盾しています。
- [Warning] 再入ガードの成立条件がまだ曖昧です。fd 非継承と両立させる場合、単なる env フラグだけで再入を許すと「ロックを実際には保持していない子」が通れる設計になり得ます。修正提案: 「再入が許されるのは、ロック保持中の親プロセス配下で、その親が生存している間だけ」という契約を明文化してください。背景化した子プロセスの起動を禁止する、または owner 情報を sidecar で検証する設計まで書くと安全です。
- [Suggestion] `git rev-parse --git-common-dir` を key ソースにする判断自体は、linked worktree 共有という目的に対して妥当です。

**4. 期待効果の妥当性**
- [Critical] 「H1 が構造的に消える」は現設計では成立しません。原因は上記と同じで、掃除処理の作用域が clone を超えているからです。修正提案: H1 を本当に消したいなら、Browser lane のロック境界か掃除境界のどちらかを machine-wide 実態に合わせて修正してください。
- [Warning] bug-hunt 除外の判断自体は成立し得ますが、「H3 の論拠が効かない」は言い過ぎです。bug-hunt 側が timing assertion を持たなくても、同居する `composer test` / `composer test:browser` 側には CPU/メモリ競合として効きます。修正提案: 効果を「テストレーン同士の競合削減」に限定し、bug-hunt 併走時の性能劣化と偽赤可能性は residual risk として明記してください。
- [Suggestion] ロック機構を 1 種類へ統一する効果は合理的に期待できます。

**5. リスク**
- [Critical] 無期限ブロッキングは、実装が完全であれば問題ありませんが、今回のように heartbeat・再入・trap・fd 管理が絡むと「壊れた時に永遠に待つ」系の障害が最も怖いです。修正提案: タイムアウトを設けない方針は維持してよいですが、最低限、owner PID・開始時刻・元コマンドを sidecar に書き、heartbeat でそれを出す設計にしてください。さらに手動復旧手順も runbook に入れるべきです。これがないと deadlock と hang の切り分けが困難です。
- [Warning] worktree-local flock を残さず削除する判断は原則賛成ですが、安全なのは「公式 entrypoint を全て確実に包めた場合」に限ります。修正提案: lane inventory を deny-by-default で固定し、未ラップ lane が増えたら検出する検証を入れてください。`pnpm test:packages` を package.json 経由で包むだけでなく、「公式レーン一覧」とそのラップ有無を検証対象にするのがよいです。
- [Suggestion] `CI=true` バイパスを作らない判断は健全です。同一経路をローカルと CI で踏ませる方が安全です。

**6. スコープの適切さ**
- [Warning] `pnpm test:packages` まで clone-wide mutex に入れるのは、安全性というより運用ポリシーです。妥当ではありますが、速度コストが出る可能性はあります。修正提案: 「軽量 JS レーンも直列化するのは simplicity 優先の方針であり、待ち時間が支配的になったら lock class 分離を再検討する」という成功条件と見直し条件を本文に追加してください。
- [Suggestion] `phpstan` / `lint` / `typecheck` / `build` を対象外にしているのは過剰実装を避けていて妥当です。
- [Suggestion] bug-hunt を対象外にする判断も、存在意義を守るという意味では筋が通っています。ただし上の residual risk 記載は必要です。

**7. 型安全性**
- [Suggestion] PHP 変更が主題ではないため、この観点の優先度は低いです。代わりに shell 側の不変条件として `set -euo pipefail`、厳格な quoting、trap の多重登録回避を設計に明記するとよいです。

**要点**
- 最大の差し戻し理由は 1 点です。  
  Browser lane の実害 H1 は machine-wide 掃除から来ているのに、ロック境界を clone-wide にしているため、原因の作用域と対策の作用域が一致していません。
- ここを直せば、残りは設計の明確化で収まる Warning が中心です。