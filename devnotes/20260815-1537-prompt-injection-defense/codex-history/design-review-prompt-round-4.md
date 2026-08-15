# Round 4: Round 3 指摘への対応と再レビュー依頼

Round 3 の Warning 2 件と Suggestion 2 件、すべて対応しました。反論はありません。

## [Warning] F / J: 拒否をどう発生させるか — 対応

施策 F に「拒否をどこから注入するか (**本番コードに脱出口を作らない**)」の表を新設し、施策 J の項目 12 からも参照させました。指摘のとおり「素の経路では到達しない最後の砦」と書きながら素の fixture で到達する前提になっており、自己矛盾でした。

| 拒否 | 注入方法 |
|------|---------|
| `TooLarge` | そのテスト内でだけ `config()->set('llm-defense.max_untrusted_bytes', 50)` に下げ、`analysis_min_text_bytes` (100) を満たす通常テキストを窓口で拒否させる。Laravel はテストごとにアプリを作り直すため config は他テストへ漏れない。committed な config の大小関係は施策 H の gate が別に固定しているので保証は弱まらない |
| `InvalidEncoding` | `SopTextExtractor` を継承した test double を `$this->app->instance()` で差し込む。**`ExtractedText` の不変条件は緩めない** — この値オブジェクトはもともと UTF-8 を検査しておらず、保証は抽出器側にある (実コードを確認済み: `ExtractedText` は `string $text` / `int $byteLength` / `string $sourceKind` を持つだけの readonly 値オブジェクト)。差し込みは「抽出器の保証が失われたときに窓口が fail-closed で止める」という、この砦が守るべき状況そのものの再現です |
| 合言葉の漏洩 | 合言葉は毎回変わるので fake 応答に直書きできない。`GuardedPromptInspector` (reflection の閉じ込め先) で組み立て済み prompt から合言葉を読み、その値を含む応答を返す fake を仕込む |

## [Warning] K: 文書が実装より強い保証を書いている — 対応 (後者の案)

`docs/template-divergence.md` の不変条件 4 を
「窓口は**合言葉の変数名 `llm_canary` の上書きを拒否**し、変数名を `/\A[a-z][a-z0-9_]*\z/` に限る (**予約 namespace は作らない**。現時点で予約したい名前が他に無く、実装より強い保証を文書に書かない)」
に変更しました。

## [Suggestion] A: 追認方法 — 対応

`dev:pipeline-smoke` の fixture について**各段へ渡る文字列の実バイト数を測る**方法に変え、`llm_call_logs` の token 数は補助材料と位置づけました。本番コードに入力バイト数の常時観測は入れません。

## [Suggestion] B: 未使用 import — 対応

施策 B のコード例から `use Webmozart\Assert\Assert;` を削除しました。

---

変更したのは施策 A のリスク節 / 施策 B のコード例 / 施策 F のテスト計画 / 施策 J の項目 12 / 施策 K-3 の 5 箇所です。該当箇所を抜粋します。全体の構成は Round 3 版から変えていません。

## 変更箇所の抜粋

### 施策 A のリスク節

### リスク

- 値を env 化したくなる圧力。gate が `env(` を字句で禁止して構造的に防ぐ。
- **値の妥当性は「証明」ではなく「追認」で保つ**。実装後、bug-hunt の `dev:pipeline-smoke` の
  fixture について**各段へ渡る文字列の実バイト数を測って**上限から十分離れていることを確認する
  (`llm_call_logs` の token 数だけではバイト数を直接は確かめられないため、
  そこは補助的な材料にとどめる)。離れていなければ値ではなく段の設計を見直す
  (仕組みが機能していない段階で値を弄らない)。本番コードに入力バイト数の常時観測は入れない
  (今必要でない観測を増やさない)。

---
### リスク

### 施策 B のコード例 (先頭)

```php
```

### 施策 F のテスト計画 (全文)

  3 つの拒否それぞれについて**同じ 4 点**を固定する:

  | 拒否 | 期待 |
  |------|------|
  | 合言葉の漏洩 (`PromptResponseRejectedException`) | LLM 呼び出しは 1 回 (再試行しない) / ジョブ `failed` / `error` = `unsafeResponse()` / チケット予約が release |
  | 長さ超過 (`TooLarge`) | **LLM を 1 回も呼ばない** / ジョブ `failed` / `error` = `tooLarge()` / チケット予約が release |
  | 不正 UTF-8 (`InvalidEncoding`) | **LLM を 1 回も呼ばない** / ジョブ `failed` / `error` = `unreadableEncoding()` / チケット予約が release |

  - 呼び出し回数は `Prompt::fake()` の記録で数える (再試行の有無をここで固定する)
  - チケットの release は既存 `failJob` 経路に乗ることの確認であり、
    課金の 2 フェーズ (reserve → commit/release) を壊していないことの担保でもある

#### 拒否をどこから注入するか (**本番コードに脱出口を作らない**)

通常の SOP 経路では `analysis_max_text_bytes` (150,000) が先に検査され、
`SopTextExtractor` が UTF-8 を保証するため、**素の fixture では窓口の 2 つの拒否に到達しない**
(設計上そう作ってある)。テストでは次の既存境界から注入する:

| 拒否 | 注入方法 |
|------|---------|
| `TooLarge` | そのテスト内でだけ `config()->set('llm-defense.max_untrusted_bytes', 50)` に下げ、`analysis_min_text_bytes` (100) を満たす通常のテキストを窓口で拒否させる。Laravel のテストはテストごとにアプリを作り直すため config は他テストへ漏れない (既存テストと同じ分離に乗る)。committed な config の大小関係は施策 H の gate が別途固定しているので、この override が gate の保証を弱めることはない |
| `InvalidEncoding` | `SopTextExtractor` の test double (同クラスを継承して `extract()` だけ差し替え) を `$this->app->instance()` で差し込み、不正バイトを含む `ExtractedText` を返す。**`ExtractedText` の不変条件は緩めない** — この値オブジェクトはもともと UTF-8 を検査しておらず、保証は抽出器側にある。差し込みは「抽出器の保証が将来失われたときに窓口が fail-closed で止める」という、この最後の砦が守るべき状況そのものの再現である |
| 合言葉の漏洩 | `Prompt::fake()` の応答に合言葉を含めることはできない (毎回変わるため)。`GuardedPromptInspector` で組み立て済み prompt から合言葉を読み、その値を含む応答を返す fake を仕込む (施策 I-3 の閉じ込め先を再利用する) |

**本番用の脱出口 (テスト専用フラグ・分岐) は 1 つも作らない。**

### リスク
### テスト計画

- 上表 10 項目がそのまま test ケース。実装前に**一時的に違反を挿入して赤を確認**する
  (テストファースト)。

### リスク

### 施策 J の項目 12

959:12. パイプライン側の 3 拒否 (合言葉漏洩 / `TooLarge` / `InvalidEncoding`) について、
960-    施策 F の表の 4 点 (再試行回数・`failed`・文言・チケット release) をそれぞれ固定する。
961-    **各拒否の注入方法は施策 F の「拒否をどこから注入するか」の表に従う**
962-    (config override はテスト内に閉じ、test double は既存の container 境界を使う。
963-    本番コードに脱出口を作らない)
964-13. 窓口 gate の合成負例に **`routes/` 相当のファイルから窓口を直接呼ぶ形**を含め、

### 施策 K-3 の不変条件

### K-3. `docs/template-divergence.md`

「trusted 変数の入口を作らない」を逸脱として登録し、**保証し続ける不変条件**を書く:

1. prompt YAML の変数はすべて untrusted か合言葉のいずれかである
2. trusted の入口は存在しない (窓口の引数に無い)
3. trusted 変数を足す PR は、入口・字句 gate (リテラル / クラス定数 / enum case 限定)・
   目録の 3 つを同時に足す
4. 窓口は**合言葉の変数名 `llm_canary` の上書きを拒否**し、変数名を
   `/\A[a-z][a-z0-9_]*\z/` に限る (**予約 namespace は作らない**。
   現時点で予約したい名前が `llm_canary` 以外に無く、実装より強い保証を文書に書かない)

---

## 実装モード

| 項目 | 内容 |
|------|------|
| 推奨モード | **standalone** |
| 判断根拠 | `app/Prompts/` 4 本・`resources/prompts/` 4 本・Architecture gate 4 本・`AGENTS.md`・`docs/architecture.md` を同時に書き換える。特に `AGENTS.md` のセキュリティ不変条件 4 と禁止事項 5、`ExternalSeamInventory` の委譲宣言は他の設計者と衝突しやすい共有ファイルであり、部分適用すると gate が赤いまま残る (旧経路の並走を残さない = 思考原則 3 とも整合しない) |

---

以上で全体判定をお願いします。CHANGES_REQUESTED の場合は残る指摘を、APPROVED の場合はその旨を明記してください。
