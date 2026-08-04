# 対応マトリクス: impl-review Round 1

## [Warning] probe: `pending` の増減が例外安全でない (feedback-probe.js:89)

- 判断: **対応する**
- 根拠: 指摘は正しい。`raf` コールバック内で `visible()` (= `getComputedStyle` / `getClientRects`) が
  throw すると `pending -= 1` に到達せず、**その probe 状態は永久に `pending > 0`** になる。
  SKILL.md の判定表は `pending > 0` を「未検証」に倒すので、**H7 が恒常的に判定不能**になる
  = 誤検知を減らす代わりに取りこぼしへ倒れる、という本設計が最も避けたい失敗モードそのもの。
- 対応内容: `try / catch / finally` で対称性を固定した。
  ```javascript
  try {
      entry.visible = el.isConnected ? visible(el) : "gone";
  } catch (e) {
      entry.visible = false;          // 判定不能。証拠は visible:true のみなので数えられない
      entry.error = String((e && e.message) || e);
  } finally {
      state.pending -= 1;
      state.seen.push(entry);
  }
  ```
  `catch` で `visible:false` に倒したのは、**判定不能を肯定証拠にしない**ため
  (証拠集合は `visible:true` のみという既存契約をそのまま使う)。entry 自体は捨てずに
  `error` を添えて `seen` に残し、人が事情を読めるようにする (`visible:false`/`"gone"` と同じ扱い)。
  probe 全体を `try/catch` で包む案は採らない — 本体が throw したら driver 側が
  「probe が壊れた」と気づくべきで、黙って空 JSON を返すと**陰性証拠に見えてしまう**ため。

## [Suggestion] probe: 同一 mutation での二重 enqueue を dedupe (feedback-probe.js:98)

- 判断: **見送る** (design-review Round 1 の同種 Suggestion と同じ判断を維持)
- 根拠:
  1. **判定規約が件数を使わない**。SKILL.md の判定は「証拠集合が空か否か」と「本文が操作結果か」だけで
     行うので、重複は判定結果を一切変えない。
  2. 実際に二重 enqueue が起きるのは「live region の中に live region が追加された」場合に限られる
     (`collect()` は Text ノードを返さないので、テキスト差し替え経路と `addedNodes` 経路は排他)。
     AI-CUE の非単調 UI 2 件 (`ToastContainer` / `CodeSnippet`) に入れ子 live region は無い。
  3. `WeakSet` を足すと probe の状態と失敗モードが増える。得られるのは triage 時の見やすさだけで、
     思考原則 2 (今必要なものだけ作る) に反する。実 run で読みづらいと判明した時点で足す。

## [Suggestion] SKILL.md: 最初の書き込み操作前に arm が成立する条件を明文化 (SKILL.md:268)

- 判断: **対応する**
- 根拠: 指摘のとおり解釈余地があった。ただし機構としては既に閉じている
  (probe は arm と読み出しが同一コマンドで冪等なので、arm 漏れは次の読み出しで
  `installed_now:true` になり「未検証」に倒れる)。**閉じていることが文面から読めない**のが問題。
- 対応内容: 「呼ぶタイミング」に 1 項を追加し、
  「arm 漏れは黙って『フィードバック無し』にはならず必ず『未検証』に倒れる」
  「`installed_now:false` を得られていない操作について H7 陰性を主張してはならない」を明記した。
  新しい機構は足していない (既存の判定表 3 行目を参照させただけ)。

## [Suggestion] SKILL.md: `pending > 0` の再 probe 前に短待機を規約化 (SKILL.md:281)

- 判断: **見送る**
- 根拠: 再 probe は **Bash 1 往復** (プロセス起動 + 往復で数百 ms〜数秒) を挟む。
  未解決なのは rAF 1 フレーム (~16ms) 待ちなので、**待機時間は既に 1 桁以上余っている**。
  ここに追加の待機を規約として書くと、(a) 効果が無いのに全 driver のコストを増やし、
  (b) 本設計が明示的にスコープ外とした「待ち時間依存の観測」を規約に呼び戻すことになる。
  実 run で `pending > 0` 継続 (= `H7 未検証`) が実際に増えるなら、そのときは待機値ではなく
  **方式**を見直す — これは SKILL.md に既に書いてある信号設計である。

## [Suggestion] spec-ledger: `watch_globs` 欄に「registry 正本参照のみ」の定型句 (spec-ledger.md:107)

- 判断: **対応する**
- 根拠: F-1-02 の `watch_globs` 欄は設計どおり glob を書かず registry を指しているが、
  **テンプレート側にはその書き方が無い**ので、後続の人が glob を書き写して二重管理を始めうる。
  「同じ説明文を両方に重複させない」は spec-ledger.md 冒頭が定める役割分担そのものなので、
  テンプレートに書くのが正しい置き場所。
- 対応内容: 初回登録テンプレートの `watch_globs` 欄に注記 1 行を追加
  (「既に registry に登録済なら glob を書き写さず『`A-NNN` に登録済 (正本は registry)』とだけ書く」)。
  欄名文字列は変えていない (`test_spec_ledger.py` の `REQUIRED_FIELDS` との 1 対 1 関係を維持)。

## [Suggestion] test_spec_ledger.py: `#L10` 形式の根拠パスがすり抜ける (test_spec_ledger.py:56)

- 判断: **対応する**
- 根拠: 指摘のとおり。`PATH_LIKE` が `:` 位置指定しか許容していなかったため、
  `foo.php#L10` / `AGENTS.md#anchor` のような記法で書かれた根拠は
  **丸ごと検査対象外にすり抜け**、パスが消えても検知できなかった。
  これは本テストが守ろうとしている不変条件 (根拠パスの実在) の穴であり、
  「行番号を検証しない」という設計判断とは独立である。
- 対応内容: `PATH_LIKE` に名前付きグループ `path` を持たせ、位置指定サフィックスを
  `(?:[:#][\w.-]*)?` に拡張した。**位置情報は従来どおり捨て、パス部だけを実在確認する**。
  fail に倒す案 (記法を禁止する) は採らない — 台帳の記法を狭めるだけで、
  守りたい不変条件 (実在) は許容集合を広げる方が強くなるため。

## [Suggestion] feedback-probe.test.ts: 順序依存を `sequential` で固定 (feedback-probe.test.ts:108)

- 判断: **対応する**
- 根拠: 本テストは probe が `window` に持つ記録器と要素基線を跨いで積み上げる**意図的な順序依存**で、
  それをコメントだけで守っていた。vitest 設定が将来 concurrent 既定になると
  **黙って壊れる** (しかも失敗の出方が基線ずれで分かりにくい)。
- 対応内容: `describe(...)` を `describe.sequential(...)` に変え、意図をコメントに残した。
