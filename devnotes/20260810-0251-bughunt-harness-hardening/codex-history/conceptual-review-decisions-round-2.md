# 対応マトリクス: conceptual-review (harness) Round 2

Critical 2 件 + Warning 4 件 + Suggestion 3 件。**全件対応**した（反論なし）。

## [Critical] H-3 の `env -i` は Laravel が `.env` を読むことまでは防げない
- 判断: **対応する（主張そのものを下げた）**
- 根拠: 指摘のとおり。`env -i` が遮断するのは**親シェル由来の env だけ**で、
  Artisan 起動後に Laravel は通常どおり `.env` を読む。そこに dev DB credential があれば、
  `ServiceProvider::$optimizeClearCommands` の拡張タスクから dev DB へ到達する余地が残る。
  「DB に触るのは cache だけ」「dev DB の状態に一切依存しない」は**証明できていなかった**。
- 対応内容: 3 段構えに直し、**主張できる範囲を明示的に狭めた**。
  1. **実登録を調査**した。本リポジトリの `$optimizeClearCommands` は 2 件だけ:
     - `filament:optimize-clear` (`vendor/filament/support/src/SupportServiceProvider.php` L442-445)
     - `icons:clear` (`vendor/blade-ui-kit/blade-icons/src/BladeIconsServiceProvider.php` L111-114)
     どちらもファイルキャッシュの破棄で DB を触らない。
     ただし**依存を足せば増える**ので「今は安全」を根拠にはしない。
  2. **Architecture テストで登録集合を allowlist に pin** する (deny-by-default)。
     新しい依存が clear コマンドを登録したら赤くなり、
     「その clear は DB を触らないか」を人が判断してから allowlist に足す運用にする。
     これは**証明ではなく検出**であると設計に明記した。
  3. H-3 が主張できるのは 3 点だけ、と設計に列挙した:
     (a) 既知の DB 接触タスク `cache:clear` を構造的に外した、
     (b) ambient env 由来の `DB_*`/`PG*` は渡らない、
     (c) 拡張タスクの集合が増えたら検出される。
  期待効果からも「dev DB の状態に一切依存しなくなる」を**削除**し、
  「今回踏んだ失敗経路 (`cache:clear` → dev DB の `cache` 表) を構造的に閉じた」に置き換えた。

## [Critical] 受入条件 7 が「worker 停止失敗時に dropdb へ到達しない」を固定していない
- 判断: **対応する**
- 根拠: 指摘のとおり。DB 名 regex と admin role が維持されていても、
  **H-1 が誤って停止成功を返せば**、対象 bughunt DB は正規条件を満たしたまま drop される。
  blast radius の中心は**制御フロー**であって guard の存在ではなかった。
- 対応内容: 受入条件を 15 → **20 件**に拡張し、H-1 の中核として 9〜12 を新設した:
  - 9: `group_live_members` が非 zombie を返したら **dropdb wrapper が一度も呼ばれない**
  - 10: 停止失敗時は pidfile 保持 + 当該 shard の teardown が失敗扱い
  - 11: dropdb 候補へ進んだ後も DB 名 guard と admin role guard を必ず通る
  - 12: **raw `dropdb` 呼び出しが新設されていない** (Architecture テスト)
  受入条件表の直後に「危険なのは guard の存在ではなく**到達制御**である」旨の注記も置いた。

## [Warning] procfs 走査は一時点のスナップショットではない (PID 消滅 race だけでは不足)
- 判断: **対応する**
- 根拠: 走査済み PID の後に同じ group へプロセスが増える / 読み取り後に状態が変わる race は残る。
  dropdb 直前の判定としては不足。
- 対応内容: 仕様に 2 項追加した ——
  「判定を**短い間隔で 2 回**行い、**2 回とも**非 zombie ゼロのときだけ成功」
  「**2 回目は dropdb 分岐の直前**に置く (判定と dropdb の間に窓を作らない)」。
  受入条件 7 に落とした。

## [Warning] 「メンバー 0 件」と「zombie のみ」の区別が無い
- 判断: **対応する**
- 対応内容: 「0 件は通常の停止成功 (**警告なし**)、zombie のみのときだけ警告する」を仕様に明記し、
  受入条件 2 (0 件 → 成功・警告なし) と 8 (zombie のみ → stderr 出力) に分けた。

## [Warning] H-4 の mode 契約が二義的 (「親と同等」と `chmod 600` は別物)
- 判断: **対応する**
- 根拠: 指摘のとおり。親が `0644` なら `cp -p` は**world-readable な秘密ファイルを新たに作る**。
  「親と同等」は契約として弱い。
- 対応内容: **コピー先 mode を `0600` 固定**に決めた。「親と同等 (`cp -p`)」を採らない理由も明記。
  受入条件 20 を「親が `0644` でも world-readable にしない」に強化した。

## [Warning] H-2 の条件はテキスト検査だけになりやすい
- 判断: **対応する**
- 対応内容: 受入条件 14 を
  「**テスト用 cap で実評価**し、`0..cap` が全て allow・`cap+1` が deny になる
  (テキスト検査で済ませない)。本番定数は env で上書き可能にしない」に強化した。

## [Suggestion] H-2 は `seq` より bash 算術ループのほうが依存が少ない
- 判断: **対応する**
- 対応内容: `for ((shard = 0; shard <= BUGHUNT_SHARD_CAP; shard++))` に変更した。

## [Suggestion] procfs の「最後の `) ` より後ろ」方式は妥当 / スコープは適切 / 期待効果の表現は適正
- 追加対応なし（評価を受領）。ただし期待効果は上記 Critical 対応でさらに 1 段弱めた。
