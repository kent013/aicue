全体判定: **CHANGES_REQUESTED**

Round 1 の主要論点はほぼ適切に取り込まれています。ただし、H-3 の「dev DB に一切触れない」という保証と、受入条件 7 の dropdb 抑止経路には、まだ安全上のすり抜けがあります。

## 1. 使命との整合性

[Suggestion] 改善目的と North Star の整合性は妥当です。

既知の harness 障害を除去し、UX 探索へ使える時間を回復するという間接貢献が明確です。「bug-hunt が完走する」ではなく「同じ4件では停止しない」へ保証範囲を狭めた点も適切です。

## 2. 禁止事項違反・dev DB 防御

[Critical] H-3 の `env -i` は、Laravel がプロジェクトの `.env` をロードすることまでは防ぎません。

`env -i` が遮断するのは親シェル由来の環境変数です。Artisan 起動後に Laravel が通常の `.env` を読み、そこに dev DB credential があれば、ServiceProvider が追加した clear commandから dev DBへ接続できる余地があります。

さらに、設計自身が `ServiceProvider::$optimizeClearCommands` を実行対象として維持しています。そのため、次の記述は証明できていません。

> このうち DB に触るのは `cache` だけ  
> provision は dev DB の状態に一切依存しなくなる

修正提案:

- `ServiceProvider::$optimizeClearCommands` の現在の登録集合を調査し、DB非接続をArchitectureテストでdeny-by-defaultに固定する。
- または、bughunt専用の明示的な環境値を注入し、Laravelが通常のdev `.env` のDB設定を採用しないことを実挙動テストで固定する。
- それができない場合は、個別clearへ分解する。パッケージ登録分の網羅性より、dev DBを触らない保証を優先すべきです。

`--except=cache` の事実認定自体、つまりキー名またはコマンド名との一致、および標準の `cache:clear` を除外するという理解は妥当です。しかし、それだけで拡張タスクを含むコマンド全体のDB非接続は保証されません。

[Warning] 受入条件12は検証対象が曖昧です。

「database cache storeを使わない」だけでは、別のclear commandがDBへ接続した場合を検出できません。

修正提案: 条件12を次の契約へ強めてください。

> 親環境とプロジェクト `.env` の双方にdev DB設定が存在しても、`optimize:clear` 実行プロセスからdev DBへの接続が発生しない。ServiceProvider追加タスクを含めて検証する。

## 3. 実現可能性

[Suggestion] `/proc/<pid>/stat` の「最後の `) ` より後ろ」を解析する方針は妥当です。

`comm` 内に空白や `)` が含まれても、それらは実際の閉じ括弧より前にあるため、最後の `) ` を境界とすれば `state / ppid / pgrp` を確定できます。`state`、`ppid`、`pgrp` の順序も正しいです。

[Warning] procfs走査は一時点の完全なスナップショットではありません。

走査済みPIDの後で同じprocess groupへプロセスが追加される、読み取り後に状態が変化する、といったraceは残ります。停止処理後なので発生可能性は低いものの、dropdb直前判定として「PID消滅raceだけ」を定義するのは不足です。

修正提案:

- `group_live_members` を短い間隔で2回評価し、両方で非zombieがゼロの場合だけ停止成功とする。
- 2回目はdropdb分岐の直前に置く。
- その間に非zombieが観測された場合はfail-closedでpidfileを保持する。
- 「対象メンバーなし」と「zombieのみ」を区別し、zombie警告は後者だけに出す。

[Suggestion] H-2 は実現可能です。ただし `seq` よりbash算術ループの方が依存が少なく堅牢です。

```bash
for ((shard = 0; shard <= BUGHUNT_SHARD_CAP; shard++)); do
```

これは必須変更ではありません。

## 4. 期待効果

[Suggestion] 期待効果の表現は適正化されています。

既知4件の再発防止に限定し、Playwrightやpcovなどの残存要因を明示しているため、効果を誇張していません。

ただしH-3については、上記の`.env`ロードと追加clear commandを閉じるまで「dev DBの状態に一切依存しない」は期待効果から外すべきです。

## 5. リスク

[Warning] H-4 のmode契約が二義的です。

「親と同等のmode」と「`chmod 600`」は同じ契約ではありません。また、親が`0644`なら`cp -p`は新しいworld-readableな秘密ファイルを作ります。

修正提案: 次のどちらかに固定してください。

- コピー先を常に`0600`にする。
- 親がgroup/world-readableならコピーを拒否し、`0600`または許容した厳格なmodeだけを保存する。

秘密ファイルでは、単純な`cp -p`よりコピー先`0600`固定の方が明確です。

## 6. スコープの適切さ

[Suggestion] スコープは適切です。

bash基盤の既知障害4件に限定され、アプリコード、pcov、Playwright設定、コンテナPID 1の変更を分離しています。H-4のコピー処理を関数化することもテスト容易性のための局所的変更に収まっています。

## 7. 受入条件の十分性

[Critical] 受入条件7は、最重要の「worker停止失敗時にdropdbへ到達しない」を固定できていません。

現在の条件7が確認するのはDB名regexとadmin roleです。これらが維持されていても、H-1が誤って停止成功を返せば、対象bughunt DBは正規条件を満たしたままdropされます。Round 1で問題にしたblast radiusの中心は、この制御フローです。

修正提案: 条件7を分割してください。

- `group_live_members` が非zombieを返した場合、dropdb wrapperは一度も呼ばれない。
- worker停止失敗時はpidfileが保持され、当該shardのteardownが失敗扱いになる。
- all-zombieまたはメンバー消滅時だけdropdb候補へ進める。
- dropdb候補へ進んだ後も、DB名guardとadmin role guardを必ず通る。
- raw `dropdb` 呼び出しが新設されていないことをArchitectureテストで固定する。

[Warning] H-1には「対象pgrpのメンバーが0件」の明示条件がありません。

全zombieと空集合は処理上どちらも停止成功でよい可能性がありますが、警告とzombie件数の扱いが異なります。

修正提案: 「0件なら通常の停止成功、zombie警告は出さない」を受入条件へ追加してください。

[Warning] H-2の条件9は、実装の形によってはテキスト検査だけになりやすいです。

修正提案: self-test内でテスト用capとDB名生成結果を実評価し、`0..cap`が全てallow、`cap+1`がdenyになることを検証してください。なお本番定数を外部環境変数で上書き可能にしてはいけません。

結論として、H-1のprocfsパース方式とH-3の`--except=cache`のLaravel上の解釈は妥当です。承認を妨げているのは、H-3で`.env`および追加clear commandからのDB接続が未封鎖である点と、受入条件7がdropdbへの到達制御そのものを検証していない点です。