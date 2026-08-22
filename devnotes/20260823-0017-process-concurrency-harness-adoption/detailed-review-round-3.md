# 全体判定: CHANGES_REQUESTED

Round 2 の主要4点と signal 名の入口統一は解消されています。正典 v1 の構成も過不足ありません。

ただし、異常回収を2子へ適用する方法に未確定部分があり、現在の説明どおり逐次処理すると2番目の子を回収できない可能性があります。ほかは局所的な整合修正です。

## 施策1: REQUEST_CHANGES

[Warning] `SignalName::make()` が public で、child ID は `/\A[a-z]\z/` です。そのため `ready-c` なども生成でき、「唯一の生成口は `ProcessBarrier::name()`」「生成可能なのは8通り」という説明は成立しません。

修正案:

- child ID を `['a', 'b']` へ限定する。
- `SignalName::make()` を唯一の公開生成口と位置づけるか、生成責務の説明を修正する。
- `ProcessBarrier::name()` と `SignalName::make()` の二重入口を残すなら、両方が同じ8通りだけを生成するテストを置く。

[Warning] 同じ signal 名への2回目の `rename()` はPOSIX上で既存ファイルを上書きできます。ready/out の二重送信を隠す可能性があります。

修正案: target が既に存在した場合は `duplicateSignal()` で失敗させてください。厳密な競合防止が必要なら、単純な `is_file()` → `rename()` のTOCTOUではなく、排他的な配置方法を採用します。

## 施策2: APPROVE

外部 transaction での生成、FK安全な削除、soft delete の迂回、残留ゼロ検査、接続回収まで具体化されています。

[Suggestion] 「偽の削除で `assertNoResidue()` を落とす」テストを行うなら、現在の公開APIでは注入点が示されていません。残留検査を小さな検査器へ分離するか、実DB上に合成した残留を直接検査できる入口を用意してください。

## 施策3: APPROVE

409の種類、go token、認証後のactor、親の期待hash、型付きDB座標まで一次観測へ含まれており、fail-closed の境界は妥当です。

[Suggestion] `ProbeDatabaseCoordinates::fromParentConfig()` では port が数値文字列であることと、1〜65535の範囲を明示的に検証してからint化してください。外部JSONのキャスト禁止と、信頼済み設定の正規化を区別して記述すると明確です。

## 施策4: REQUEST_CHANGES

[Critical] 2秒の回収予算を複数の子へどう配分するかが未定義です。子を順番に

```text
TERM → wait 1秒 → KILL → wait 残り
```

と処理すると、最初の子が2秒を使い切った時点で、2番目の子には回収時間が残りません。「全子の回収を最大2秒で完遂する」という契約を満たせません。

修正案: 回収を子単位ではなくフェーズ単位で行ってください。

1. 生存する全子へTERMを送る。
2. 単一の reap deadline のうち最大1秒、全子をまとめてpollする。
3. 生存する全子へKILLを送る。
4. reap deadline まで全子をまとめてpollする。
5. 終了できなかった子があれば、対象child IDを含む回収失敗例外にする。

これなら子数にかかわらず最大2秒です。施策7では、一方がTERMで終了し、他方がKILLまで必要なケースを固定してください。

[Warning] KILL後も `waitFor()` が `null` を返した場合、一時ディレクトリを削除してよいかが未定義です。まだ動いている子が削除済みパスへ書き込む可能性があります。

修正案: 全子の停止を確認できた場合だけworkspaceを削除してください。停止確認不能ならKILL要求済みであることを記録して例外にし、workspaceを診断用に残すか、安全な退避方針を定めてください。「実OS上の終了は保証外」であれば「必ず回収完遂」という表現も「boundedな回収操作を必ず要求する」へ狭めます。

[Warning] envキー検査は「許可外がない」だけでなく、必須キー集合との完全一致が必要です。必須DBキーが欠落しても親側で止められる方が安全です。

修正案: `$values` のキー集合を `ALLOWED_ENV_FILE_KEYS` とソート後に完全一致で比較してください。

## 施策5: REQUEST_CHANGES

[Warning] 独自parserとの round-trip だけでは、phpdotenvが同じ値として読むことを固定できません。段9の実プロセス検査では正常な現在値しか通らず、`$`、`${NAME}`、引用符を含む値のLaravel側解釈は個別に裏取りされません。

修正案: `encodeLine()` の正例を、実際にプロジェクトで利用しているphpdotenv parserにも通し、次の値が一致することを同一プロセスのUnitテストで固定してください。

- `$`
- `${NAME}`
- `\`
- `"`
- `#`
- 空文字
- 前後空白

自前parserとphpdotenvの双方が同じ結果を返すことが必要です。

[Warning] `fopen('x')` の直後から `chmod()` まで、ファイル自体はumask依存のmodeで存在します。秘密はまだ書いていないため情報漏洩ではありませんが、「作成時点から0600」という表現は正確ではありません。

修正案: 「秘密を書き込む前に0600へ変更する」と記述を直すか、一時的にumaskを制限して作成時modeも0600にしてください。umaskを変更する場合はfinallyで必ず復元します。

## 施策6: REQUEST_CHANGES

[Warning] 主張文は狭められていますが、テスト冒頭のdocblockには旧表現が残っています。

```text
プロセス間で共有されるアプリ側ロックが 1 つも無い
```

これは観測範囲を超えます。

修正案: docblockも主張文と同じく、次の範囲へ統一してください。

```text
Laravel の既定 cache を使うプロセス間共有ロックが利用できない状態
```

unique制約だけを使うことはP2の実読根拠として分離します。

[Warning] winnerについて `enteredHandler === true`、loserについて `enteredHandler === false` をコード上で直接assertしていません。`partition()` が保証すると読めますが、見本テストの主張との対応が見えにくい状態です。

修正案: `partition()` の契約で1:1を保証したうえで、見本テストでも両booleanを明示的に検査してください。

## 施策7: REQUEST_CHANGES

[Critical] 回収テスト #34・#35 だけでは、2子への予算配分問題を捕まえられるとは限りません。

修正案: 次のケースを追加してください。

- aはTERM後すぐ終了する。
- bはTERMでは終了せずKILLが必要。
- TERMはa/bの両方へ、KILLはbだけへ送られる。
- 全体の回収時間は2秒以内で、bにもKILL後のwaitが要求される。

[Warning] signal の二重配置を拒否する場合、その負例も `ProcessBarrier` 群へ追加してください。

## 施策8: APPROVE

実プロセステストが証明する2点に限定され、依存順も明確です。

## 施策9: APPROVE

新規20本、変更3本、合計23パスで整合しています。D7を据え置く判断と、`phpstan.neon` を変更しない理由も明確です。

## 承認に必要な最終修正

必須なのは次の2点です。

1. 2子をフェーズ単位でTERM/KILL/waitする回収アルゴリズムを確定する。
2. テストdocblockに残った「アプリ側ロックが1つも無い」という過大な主張を修正する。

併せて SignalName の実際の生成可能集合と、phpdotenvとのround-trip検査を設計どおりに揃えれば、全体をAPPROVEDにできます。