# 概念設計レビュー Round 4

Round 3 の Warning 3 件と Suggestion 1 件を対応した。

# 対応マトリクス: conceptual-review Round 3

## [Warning] §A-2 の 5b (`{"a":1}}`) は走査の責務と食い違う
- 判断: 対応する
- 根拠: 指摘が正しい。走査は最初の値が完結した時点で終わるので、2 つ目の `}` は
  走査中の不整合ではなく「値の後の余剰トークン」である。
- 対応内容: 表から 5b (深さ 0 での余分な閉じ括弧) を削除し、`{"a":1}}` を #8 の例へ移した。
  5a (値の終端に到達する前の括弧不一致) は残した。走査の終わり方を注記にも明記した。
  負例一覧から `{"a":1}}` を「構造走査の負例」から外し、#8 の負例として扱う。

## [Warning] 背景の記述が「後続ブロックを採る」と読める
- 判断: 対応する
- 根拠: 期待効果の節と矛盾していた。
- 対応内容: 背景を「複数のブロックを区別せず連結した内容として復号しようとして失敗し、
  その失敗が `invalid_json` に埋もれる」に統一した。差し込みについては
  「差し込まれた事実そのものが観測できない」を主張にした。

## [Warning] `sop-extract-media` の確認が最終の完了条件に反映されていない
- 判断: 対応する
- 根拠: 判断 1 の表にはあるが末尾の完了条件に無く、チェックリストだけを見た実装者が
  media 経路を未確認のまま完了できてしまう。
- 対応内容: 完了条件を 5 項の箇条書きに書き直し、互換性確認 A (pipeline-smoke の 3 本) と
  互換性確認 B (画像 SOP による `sop-extract-media` 1 件) を別項として入れた。
  どちらも課金 + ユーザー承認が要るのでエージェント判断では実行せず、
  実行できなかった場合は**外部確認待ち**として TODO のクローズ時に明示する
  (「未確認のまま完了」にしない / 自動テストの green を実 provider 確認と書かない) と定めた。

## [Suggestion] 受理 4 / 拒否 12 の件数は概念設計で固定しない
- 判断: 採用
- 対応内容: 実装方針の表から件数を外し、ケース一覧と件数は詳細設計で確定すると書いた。

## その他の [Suggestion] (使命 / 禁止事項 / スコープ / 型安全性)
- 判断: 見送る (肯定的な確認であり変更不要)

---

## 修正後の概念設計 (全文)

# 概念設計: llm-output-single-decode-point v1 追従 (LLM 応答の復号点を厳しくし、失敗を数えられるようにする)

## 背景・課題

lctl 機能台帳 feature `llm-output-single-decode-point` は 2026-08-22 に正典 v1 が確定し
(`design.md` の不変条件 i1〜i10)、aicue セルは **implemented → update_pending (pre-v1 → v1)** に
改められた。台帳が名指しする不足は 5 点 (i1・i2・i3・i4・i5) + 切り詰めの明示 (i6) で、
本リポジトリ HEAD (b207bafa) の実読で全数を再確認した。

現行の復号点 `app/Support/Manual/LlmJson.php` は 38 行で、次の 4 段しか持たない。

1. `trim()`
2. 先頭が ``` なら `preg_replace('/^```[a-zA-Z0-9]*\s*/')` と `preg_replace('/\s*```$/')` で剥がす
3. `json_decode($t, true)`
4. `is_array()` でなければ `LlmOutputInvalidReason::InvalidJson` で例外

ここから出る具体的な欠落は次のとおりである。

- **囲みの個数を数えない (i2 欠)**。応答が `` ```json {A} ``` `` の後にもう 1 つ
  `` ```json {B} ``` `` を持っていても、先頭の `` ``` `` を剥がして末尾の `` ``` `` を剥がした
  「A + ``` + B」を JSON として読もうとする。つまり**複数のブロックを区別せず連結した内容として復号しようとして失敗し、
  その失敗が `invalid_json` に埋もれる**。依頼文には他人の書いた SOP 本文が
  `<user_input>` として入る (prompt-injection-defense の窓口経由) ので、
  後続ブロックの差し込みは**外から仕掛けられる**入力であるのに、
  差し込まれた事実そのものが観測できない。
- **境界を文字の並びで決める (i3 欠)**。`/\s*```$/` は「応答データの中に現れた ```」と
  「囲みの終端」を区別できない。逆に、閉じの印と「別言語の開始の印」も区別しない。
- **既定が緩い (i4 欠)**。入口が 1 つで囲みは任意なので、「囲み無しでも読む」緩い契約が
  唯一の入口 = 誰でも呼べる既定になっている。
- **失敗の区分が粗い (i5 欠)**。`invalid_json` の 1 case に「囲みが無い / 囲みが複数 /
  構文が壊れている / 最上位が入れ物でない / 切り詰め / 閉じの囲みが無い」が全部入る。
  結果として**作り直せば直るもの**と**何度やっても直らないもの**が集計で同じ値になる。
- **切り詰めの推定が存在しない (i6 欠)**。判定そのものが無い。
- **迂回の機械検査が無い (i1 欠)**。`tests/Architecture/` に `LlmJson` を見る検査は
  grep 一致 0 件である。現在 3 か所 (`ExtractedSopData` / `WorkDecompositionResponseData` /
  `GeneratedScenarioData`) が唯一の復号点を通っているのは**慣行のみ**で、
  新しい依頼文が自前で `json_decode` を書き始めても赤くならない。

一方、正典が aicue の実装から採った側 (i7 の違反位置 + 再試行への合流 / i8 の利用者向け文言の
分離 / i9 の応答本文を残さない / i10 の回数上限 + deadline) は HEAD で充足している。
本作業はそこを壊さずに、欠けている受理契約・失敗語彙・迂回検査を足す。

## 改善アイデア

**「厳しい入口 1 つ」に作り替え、失敗を 6 区分 + 直交 1 区分で数えられるようにし、
その入口の外で応答を自前で読めないことを機械で固定する。**

### A. 受理契約を「囲みちょうど 1 つ」へ (i2 / i3)

復号点の唯一の入口は、次の形の応答だけを受け取る。

```
PRE    := 囲みの印を含まない任意の文字列 (通常は空。説明文の前置きを許す)
OPEN   := 逆引用符 3 個以上の並び + 任意の言語札 [A-Za-z0-9_+.-]* 
VALUE  := 最上位が入れ物 (object / list) の JSON 値ちょうど 1 つ
GAP    := 空白のみ
CLOSE  := 逆引用符 3 個以上の並び (直後に言語札を持たない = 閉じの印)
POST   := 囲みの印を含まない任意の文字列 (後書きを許す)

応答 = PRE OPEN VALUE GAP CLOSE POST
```

**囲みの印は「行」ではなく「連続 3 個以上の逆引用符の並び」と定義する** (行頭条件を付けない)。
データ中の印を終端に数えない保証は、行頭条件ではなく「**構造の走査で決まった値の区間の外側だけを
数える**」ことから来るので、行頭条件は保証に何も足さず、`{...}``` ` のような同一行の閉じを
不当に落とすだけである。

判定は正規表現ではなく**構造の走査**で行う。開きの印の直後から、文字列リテラルとその中の
打ち消しを解釈しながら括弧の対応を追い、深さが 0 に戻った位置を値の終端とする。

**走査器の責務は「最初の JSON 値の終端候補を特定する」ことだけ**である。値が JSON として
妥当かは判定せず、それは `json_decode($value, true, flags: JSON_THROW_ON_ERROR)` に委譲する
(自前パーサへ膨らませて `json_decode()` と判定が食い違う状態を作らない)。

### A-2. 区分の決定順序 (単一パスの到達順 = 複合不正の優先順位)

複合不正 (囲みも壊れ、値も切れている等) の区分は**単一パスの到達順**で一意に決まる。
上から順に評価し、最初に当たった行で確定する。

| # | 判定 | 区分 |
|---|---|---|
| 1 | 囲みの印が 1 つも無い | `fence_absent` |
| 2 | 最初の印 (= OPEN) の後、言語札を読み飛ばした先が空白のみで終端 | `value_incomplete_inferred` |
| 3 | その先の最初の非空白が囲みの印 (= 空のブロック) | `top_level_not_container` |
| 4 | その先の最初の非空白が `{` でも `[` でもない (scalar / null) | `top_level_not_container` |
| 5a | 構造の走査が**期待と異なる閉じ括弧**に遭遇した (`{"a":[}` / `{"a":]}`) | `syntax_broken` |
| 5b | 構造の走査が深さ 0 に戻らずに終端に達した (文字列の途中で終端も含む) | `value_incomplete_inferred` |
| 6 | 値の終端の後、空白を飛ばした先が終端 (印が無い) | `closing_fence_absent` |
| 7 | 値の終端の後、空白を飛ばした先が**囲みの印だが直後に言語札を持つ** (= 別ブロックの開き) | `fence_multiple` |
| 8 | 値の終端の後、空白を飛ばした先が印でもなく非空白 (ブロック内の余剰トークン。`{"a":1}}` の 2 つ目の `}` もここに落ちる — 走査は最初の値が完結した時点で終わるため) | `syntax_broken` |
| 9 | 閉じの印より後に、さらに囲みの印がある | `fence_multiple` |
| 10 | 切り出した値の `json_decode` が `JsonException` (深さ超過を含む) | `syntax_broken` |
| 11 | `json_decode` の結果が配列でない (4 で落ちるので到達不能。多重防御) | `top_level_not_container` |

- **7 を `closing_fence_absent` ではなく `fence_multiple` に倒す**のは、正典 i2 の
  「囲みの外にもう 1 つ囲みがあれば受け取らない」が守りの本体であり、閉じ忘れより優先して
  数えたいからである。
- 構造の走査は**期待する閉じ括弧のスタック**を持つ (深さの数だけでは `{"a":[}` を
  終端候補まで通してしまう)。最初の不整合で確定し、走査は継続しない。
  走査は**最初の値が完結した時点で終わる**ので、`{"a":1}}` の 2 つ目の `}` は
  走査中の不整合ではなく「値の後の余剰トークン」= #8 である。
- 開きの印の直後の言語札は `[A-Za-z0-9_+.-]*` を**貪欲に**読む。札としてこの字種以外が
  続く場合は札が空 (長さ 0) と解釈され、値の開始はその文字になる。
- **scalar の厳密な識別は行わない**。分類は「値の開始文字が `{` / `[` か」だけで決める。
  したがって札の形をした scalar (`` ```null `` / `` ```42 ``) は言語札として消費され、
  #2 / #3 に落ちる (設計上区別しない。区別する必要のある呼び出し元が無く、
  どちらも「入れ物ではない」= 拒否である)。
- **逆引用符の個数の対応 (開き 4 個なら閉じも 4 個以上) は見ない**。3 個以上の並びは
  すべて印として扱う (docblock に保証しない旨を書く)。

### B. 失敗を 6 区分にする (i5) + 切り詰めは推定と明示する (i6)

`LlmOutputInvalidReason` を作り替える。既存 `invalid_json` は 6 区分へ分解して**消す**
(AGENTS.md 思考原則 3: 後方互換の並走を残さない)。`schema_violation` は直交軸なので残す。

| 新しい区分 | 意味 |
|---|---|
| `fence_absent` | 囲みの開きの印が 1 つも無い (素の JSON もここに落ちる) |
| `fence_multiple` | 採った囲みの外にもう 1 つ囲みの印がある |
| `syntax_broken` | 囲みの中身が JSON として読めない |
| `top_level_not_container` | 最上位が入れ物 (object / list) ではない |
| `value_incomplete_inferred` | 値が完結しないまま終端に達した = **切り詰めの推定** |
| `closing_fence_absent` | 値は完結したが閉じの印が無い |
| `schema_violation` | 読めたが形が違う (既存。違反位置 `path` を持つ) |

`value_incomplete_inferred` は **値そのものに "inferred" を入れる**。記録に出る文字列
(`llm_output_invalid_value_incomplete_inferred`) を読んだ人が、これが断定ではないと
値だけで分かるようにするためである (i6)。提供元の停止の理由は現在復号点へ渡していないので
上書きの余地は無い (正典 q2 が未決なので引き回しは作らない = 思考原則 2)。
さらに実測で、**提供元の停止の理由は既に別の記録に正本として残っている**ことを確認した
(`llm_call_logs.finish_reason` = `Prism\Prism\Enums\FinishReason` の値。失敗系は sentinel
`'failed'`)。推定はこの列に触らないので、i6 の「推定が正本を上書きしない」は構造的に成り立ち、
切り詰めの疑いは事後に `finish_reason` と突合できる (突合は運用の集計。復号点への引き回しは
作らない)。

区分を `match` している箇所は無い (実測: `AnalysisPipeline` は
`'llm_output_invalid_'.$reason->value` の**連結**で分類文字列を作るので、case を足すと
語彙が自動で広がる)。よって case の追加で網羅性の穴は生まれない。

**例外へ渡す detail は区分ごとの固定文にする**。応答の断片も `json_last_error_msg()` /
`JsonException::getMessage()` も入れない (i9)。区分名だけで診断に足り、
`getMessage()` を記録へ流す経路が将来生まれても本文が漏れない。

### C. 依頼文の出力指示を「囲みちょうど 1 つ」へ揃える (受理契約との整合)

現行の依頼文 4 本 (`sop-extract` / `sop-extract-media` / `work-decomposition` /
`scenario-generation`) は「出力は JSON のみ (前後に説明文・コードフェンスを付けない)」と
**囲みを禁じている**。A の受理契約はこれと正面から衝突するので、依頼文の側を
「``` の囲みちょうど 1 つに入れて出す」へ揃える。詳細は「§設計判断 1」。

### D. 緩い入口は作らない (i4)

正典 i4 は緩い入口を「持ってよい (MAY)」としか言っていない。C により**緩い受け取りを
必要とする呼び出しが 1 つも無くなる**ので、緩い入口は作らない (思考原則 2)。
代わりに、復号点の**公開面をちょうど 1 つに機械で pin** する
(`PromptDefenseWindowGateTest` が窓口の公開面を完全一致で pin しているのと同じ形)。
将来緩い入口が要るときは、この pin が赤くなるので登録制を同じ変更で作らざるを得ない。

### E. 迂回の機械検査を新設する (i1)

`tests/Architecture/LlmResponseDecodePointGateTest.php` を新設し、次の 6 つを
deny-by-default で固定する。**LLM 応答が app/ に入れる唯一の入口は
`GuardedPrompt::executeSync()` である**という事実 (窓口方式 T169 / `PromptGuardrailTest` /
`PromptDefenseWindowGateTest` が既に固定している) を土台に、その入口を全数分類する形にする。

1. **依頼文の全数分類**: `app/Prompts/` を**再帰**で全数走査し、1 本ずつ「復号点を通す /
   提供元が形を保証する経路 (枠のみ・現在 0 件) / 応答を構造化データとして読まない (自由文)」の
   どれかに分類された目録に**完全一致**で載っていること。依頼文を足したら赤くなる
   (= 正典 i1 の「依頼文が増えたときに黙って抜けない形」)。根の不在は fail-fast、
   母集団の非空を検査する。
2. **応答の受け取り口の全数分類 (3 分類 + 未解決は失敗)**: `app/` 全体の `->executeSync(`
   呼び出し点を全数走査し、走査器は各呼び出し点を
   **「`GuardedPrompt` と解決済み」/「別型と解決済み」/「未解決」**の 3 つに分ける。
   解決できる形は (i) 登録済みの依頼文 factory の `::make(...)` の直後の呼び出し、
   (ii) 同一関数内で `::make(...)` を代入した変数への呼び出し の 2 つだけで、
   **それ以外は未解決として gate を失敗させる** (共通規約 (b): 未解決を解決済みへ混ぜない)。
   「`GuardedPrompt` と解決済み」は目録に完全一致で載っていること、
   「別型と解決済み」は理由つきの別目録 (現在 0 件) に載っていること。
   メソッド名で母集団を採る形は**拾いすぎる方向にだけ倒れる**が、それは母集団の話であって
   解決の話ではない (解決できない形は上のとおり落とす)。
3. **応答の流れの構造的封じ**: 「復号点を通す」分類の呼び出し点は、`executeSync()` の
   呼び出しが**登録済みの受け取り関数の引数として**現れなければならない。
   変数へ束縛する形 (`$text = ...->executeSync();`) は登録済みの形に一致しないので赤くなる。
   これで「応答を変数に受けて別サービスへ渡す」経路がデータフロー解析なしに構造で塞がる。
4. **`GuardedPrompt` の参照者の分類**: `App\Support\Llm\GuardedPrompt` を**完全修飾名で解決して**
   (use / group use / 別名を解く。共通規約 (a)) 参照する `app/` のファイルは、
   登録済みの依頼文 factory か登録済みの受け取り側のどちらかであること。
5. **自前の読み方の不在**: 登録済みの受け取り側のファイル群 + LLM 応答が触る走査根
   (`app/DataTransferObjects/Manual/Analysis/` / `app/Services/Manual/` / `app/Prompts/` /
   `app/Support/Manual/`) に `json_decode` と囲みの印の文字列リテラルが現れないこと
   (復号点自身の 1 ファイルだけを名指しで除外する)。
6. **依頼文と受理契約の同期**: 「復号点を通す」分類の依頼文 YAML は、囲みちょうど 1 つを
   指示する所定の文を持つこと。依頼文を足して出力指示を書き忘れると赤くなる
   (受理契約と依頼文が黙って食い違う状態を作らない)。

`app/` 全体の `json_decode` を対象にはしない。実測 17 か所のうち 16 か所は OIDC メタデータ・
webhook 署名・冪等キー等の**LLM と無関係な経路**で、全部を目録に載せると
「LLM 応答の復号点」という不変条件と関係のない登録が 16 件混ざり、目録が意味を失う。
その代わりに 2〜4 で**応答が app/ に入る点そのものを全数分類**しているので、
「別ディレクトリの `json_decode` が LLM 応答を読む」形は、応答をそこへ運ぶ経路の側で赤くなる。

保証しない範囲 (反射・動的に組み立てたクラス名・文字列キーだけの container 解決・vendor 内・
tests 配下・宣言した走査根の外の `json_decode`) は gate の docblock に明記する
(AGENTS.md 静的検査の共通規約 (b))。(c) 負例と正例、母集団の非空、走査根の実在、
未解決の形の fail-closed も同じ変更で揃える。

### F. 区分の拡張を観測へ反映する

`AnalysisPipeline::observabilityCategoryFor()` は `'llm_output_invalid_'.$reason->value` を
組み立てているので、語彙は**自動で広がる**。DB 列には入っていないので移行は不要
(実測: `invalid_json` の文字列は enum 定義の 1 か所にしか無い)。

**再試行の可否は区分ごとに分けない** — `LlmOutputInvalidException` は今と同じく丸ごと
retryable に置く。理由は §設計判断 2。

## 設計判断

### 判断 1: 依頼文を「囲みちょうど 1 つ」へ替える (依頼文を据え置いて緩い入口へ寄せる案は採らない)

正典 i2 の厳しい入口は**囲みが 1 つあること**を要求する。現行の依頼文は逆に囲みを禁じている
ので、二択になる。

- 案 (a) 依頼文を「囲みちょうど 1 つ」へ替え、厳しい入口を主経路にする
- 案 (b) 依頼文を据え置き、3 (実際は 4) 本の呼び出しを登録済みの**緩い入口**へ寄せる

**(a) を採る。** 根拠は 3 つ。

1. **(b) は既定が緩い形をそのまま残す**。全呼び出しが緩い側に並ぶので登録制が形骸化し、
   厳しい入口は呼び出し元 0 の死んだコードになる (思考原則 2 に反する)。正典が
   「緩い方が既定で、誰でも呼べる形は採らない」と書いた状態そのものが温存される。
2. **(a) はモデルの地の振る舞いに逆らわない**。現行の依頼文が「コードフェンスを付けない」と
   **わざわざ禁じている**こと自体が、モデルの既定が囲みを付ける側だという実証である
   (禁じてもなお付けてくるので現行の復号点に剥がす処理が要り、
   `tests/Feature/Projects/AnalysisPipelineTest.php:418` が「コードフェンス付きも受理する」を
   固定している)。囲みを要求する方が非準拠の確率は下がる。
3. **失敗しても止まらない**。囲み無しの応答は `fence_absent` で有界リトライに乗る
   (回数上限 `manual.analysis_llm_max_retries` = 2 → 最大 3 試行、実時間 deadline 1,080 秒)。
   再試行は再サンプリングなので、書式の取りこぼしは高い確率で次試行で直る。

**リスクと緩和 (「再試行があるから大丈夫」では済まさない)**: 本番のモデルが囲みを
付けない側に偏ると、これまで成功していた解析が `fence_absent` の連続で失敗しうる (回帰)。
上の根拠 2 は**仮説**であり (既存テストと剥がし処理の存在は過去の観測であって、
現在の本番モデルの出力分布の証拠ではない)、次の 5 つで扱う。

1. **出荷前の互換性確認 (準拠率ではない)**: 既存の `dev:pipeline-smoke` を充てる。
   `--check` (費用ゼロの preflight) は実装完了条件に入れる。実走 (課金あり・
   `BUGHUNT_ORCHESTRATOR=1` の親のみ実行可) は**ユーザー承認のうえ 1 回**行う。
   これで言えるのは「その 1 サンプルで対象経路が囲みつきで返った」ことだけであり、
   **準拠率の測定ではない** (反復実走は課金なので採らない = この判断を明記する)。
   被覆は次のとおりで、埋まらない分は「未確認」と書いて隠さない。

   | 依頼文 | 互換性確認の手段 |
   |---|---|
   | `sop-extract` | pipeline-smoke (`REQUIRED_TEMPLATES` に含む。投入は `text/plain`) |
   | `work-decomposition` | pipeline-smoke (同上) |
   | `scenario-generation` | pipeline-smoke (同上) |
   | `sop-extract-media` | **pipeline-smoke では 1 度も通らない** (実測: `REQUIRED_TEMPLATES` は 3 本、SOP は `text/plain`)。dev 環境で画像 SOP の解析を 1 件流して抽出段の成功を確認する (ユーザー承認のうえ) |

2. **自動テストは実 provider を使わない**。canned / fixture を囲みつきに固定し、
   囲み無しを渡したら赤くなる形で受理契約をテストに固定する。
3. **依頼文と受理契約の食い違いを機械で塞ぐ** (E の検査 6)。
4. **出荷後の観測**: `llm_output_invalid_fence_absent` / `_fence_multiple` の**件数と
   最終失敗数**を既存の失敗分類と並べて見る (率は現行ログから出せない — 再試行ログは
   失敗時にだけ出るので分母が無い。分母が要るなら `llm_call_logs` の
   `prompt_template` 別の行数と突合するが、その表は llm-cost-monitoring の持ち分であり
   本設計で新しい観測点は作らない)。
5. **巻き戻し手順**: 一手目は**依頼文の出力指示の修正**。それで回復しない場合は
   受理契約を緩める並走を作らず (思考原則 3)、**変更一式を revert する**
   (TODO 1 本 = ブランチ 1 本なのでマージコミットの revert で戻る)。
   発火条件と手順は `docs/architecture.md` の新節に書く。

受容の根拠には、失敗が**区分つきで観測できる**ようになること自体も含む
(現行は同じ事象が `invalid_json` に埋もれて、囲みが無かった事実自体が数えられない)。

### 判断 2: 再試行の可否を区分ごとに分けない

区分を 6 つに割ると「`fence_multiple` は決定論的だから再試行しない」といった分岐を作りたく
なるが、**作らない**。理由:

- 復号の失敗はすべて**モデルの出力の書式**の問題で、次試行は再サンプリングなので出力が変わる。
  「決定論的」なのは復号の判定であって、応答の生成ではない。
- 非 retryable を増やすと、これまで再試行で救われていた事象が即失敗に変わる
  = 利用者から見た可用性の後退である。正典 i10 が要求するのは「上限があること」だけで、
  区分ごとの可否は要求していない。
- `isTransient()` は「retryable を先・deny を後」の順で書く既存規約 (deny を先に置くと
  将来の型変更で黙って再試行が止まる) を保つ。

**区分別の費用の観測は現行配線で足りる** — 再試行 1 回ごとに
`Log::warning('AI 解析の LLM 呼び出しを再試行します', ['failure_category' => …])` が出て、
最終失敗は `observabilityCategoryFor()` の分類が終端ログに出る。したがって
「区分ごとの試行回数」と「区分ごとの最終失敗数」は後から数えられる (依頼文の恒常的な問題と
単発のモデルの揺らぎを事後に切り分けられる)。新しい観測点は足さない。

したがって `isTransient()` / `userMessageFor()` は**無変更**である。

### 判断 3: 最上位に「並び」を許すかは据え置く

正典 q3 は未決で、現行 aicue は object でも list でも受ける。呼び出し側 3 か所はすべて
object 前提だが、狭める要求は正典に無いので**現行の寛容さを据え置く**
(`top_level_not_container` は「入れ物ではない」= scalar / null だけを落とす)。

### 判断 4: 型と例外の境界

- 復号点の公開戻り値は `array<array-key, mixed>` を維持する (DTO 側が Assert で narrow する
  現行の形を変えない)。
- 妥当性判定は `json_decode(..., flags: JSON_THROW_ON_ERROR)` に委譲し、`JsonException` を
  `syntax_broken` へ写す。`is_array()` は**多重防御としてだけ**残す
  (構造の走査が `{` / `[` を確認済みなので到達不能である旨を docblock に書く)。
- `LlmOutputInvalidException` / `userMessage()` / `path` の設計は無変更 (正典 i7 / i8 を
  すでに満たしている)。detail が固定文になることで、`getMessage()` にも本文が入らなくなる。

### 判断 5: 診断の材料は増やさない

正典 i9 (応答本文を残さない / 辿れる鍵を 1 つ持つ) は HEAD で充足している
(再試行ログは `failure_category` と `failure_path` だけ、鍵は `analysis_job_id` + `step`)。
spirux 形の診断 DTO (長さ・要約値・囲みの個数) は本リポジトリに需要が無いので作らない
(思考原則 2)。代わりに **sentinel 文字列を含む応答**を 6 区分すべてに流し、
`getMessage()` / `userMessage()` / 再試行ログの context / `analysis_jobs.error` の
どこにも sentinel が現れないことを**テストで固定**する
(区分が増えたときに本文を混ぜ込む改変が入らないようにする)。

## 期待効果

- **使命への貢献**: 「思考ゼロ・編集ゼロ」の入口である AI 解析 3 段は、LLM 応答の解釈に
  全面的に依存している。壊れた応答が黙って業務データ (統一 JSON / 作業分解表 / cuts) へ
  流れる余地を、受理契約の厳しさと区分つきの失敗で閉じる。
- **守りの効果 (誇張しない)**: 現行実装は、差し込まれた後続ブロックを**採用する**わけではない
  (先頭と末尾の印を剥がした連結を読もうとして壊れて落ちる)。したがって新たに得るのは
  「採用の防止」ではなく **(i) 曖昧な復号を決定論的に拒否すること** と
  **(ii) その拒否が `fence_multiple` として数えられること** である。現行は同じ事象が
  `invalid_json` に埋もれて、囲みが 2 つ来た事実自体が観測できない。
  新たに防げる受理ケースは拒否テストの一覧 (§実装方針の 7) で示す。
- **運用の効果**: 「なぜ解析が失敗するか」が 6 区分で数えられる。とくに
  `value_incomplete_inferred` は **max_tokens 不足の疑いを分離**する
  (断定はできない — 網の断・提供元側の生成停止・モデルの不具合も同じ観測になりうる。
  提供元の停止の理由は本設計では受け取らないので、予算を変える判断には失敗率・応答長・
  提供元の情報という追加の観測が要る)。
- **回帰の予防**: 依頼文を足したとき・新しい受け取り口を書いたときに、機械検査が赤くなる。

## 実装方針（概要）

| # | 変更 | 主なファイル |
|---|---|---|
| 1 | 失敗区分の作り替え (6 + 直交 1) | `app/Enums/Manual/LlmOutputInvalidReason.php` |
| 2 | 復号点を構造の走査へ作り替え (旧正規表現経路は同じ変更で削除) | `app/Support/Manual/LlmJson.php` |
| 3 | 依頼文 4 本の出力指示を「囲みちょうど 1 つ」へ | `resources/prompts/{sop-extract,sop-extract-media,work-decomposition,scenario-generation}.yaml` |
| 4 | canned 応答 4 本を囲みつきへ | `app/Services/AI/Testing/CannedPromptResponses.php` |
| 5 | 迂回検査の新設 (依頼文の全数分類 + 受け取り口の分類 + 自前の読み方の不在) | `tests/Architecture/LlmResponseDecodePointGateTest.php` + `tests/Support/Llm/` の走査器 |
| 6 | テストの受理契約の更新 (囲み前提へ) | `tests/Unit/Manual/*` / `tests/Feature/Projects/AnalysisPipelineTest.php` / `tests/Feature/Manual/Analysis/AnalysisPipelineOcrTest.php` / `tests/Feature/Llm/CannedPromptResponsesTest.php` / `tests/Feature/Projects/ScenarioBookendMaterializeTest.php` / `tests/Feature/Notifications/ManualAnalysisNotificationTest.php` |
| 7 | 復号点の新しい契約の単体テスト (受理・拒否の境界ケースと sentinel 非漏洩。ケース一覧と件数は詳細設計で確定する) | `tests/Unit/Manual/LlmJsonTest.php` (新設) |
| 8 | 文書 (規約 1 項 + アーキテクチャ 1 節 + 出荷後の観測と一手) | `AGENTS.md` ドメイン固有規約 / `docs/architecture.md` |

**実装の完了条件に入れるもの**:

1. 検証コマンド全 green (`composer test` / `composer phpstan` / `vendor/bin/pint --test` /
   `pnpm lint` / `pnpm typecheck` / `pnpm test` / `pnpm build` ほか AGENTS.md の一覧)。
2. `scripts/bug-hunt-shard.sh pipeline-smoke --check` (費用ゼロの preflight) の通過。
3. **互換性確認 A**: pipeline-smoke の実走 1 回 (課金あり。ユーザー承認のうえ)。
   `sop-extract` / `work-decomposition` / `scenario-generation` の 3 本が囲みつきで返ること。
4. **互換性確認 B**: 画像 SOP の解析 1 件 (dev 環境。課金あり。ユーザー承認のうえ)。
   `sop-extract-media` が囲みつきで返ること。
5. 3 と 4 は**課金とユーザー承認を要するため、エージェントの判断では実行しない**。
   実行できなかった場合は「未確認のまま完了」にはせず、**外部確認待ち**として
   TODO のクローズ時に明示する (どちらが未実施かを書く)。自動テストの green だけで
   「実 provider で確認済み」とは書かない。

## 制約・前提

- **テストファースト**: 先に赤くするのは (i) 新設 `tests/Unit/Manual/LlmJsonTest.php` の
  6 区分、(ii) 既存 `tests/Unit/Manual/AnalysisDtoTest.php:20-23` (囲み付きと素の JSON の
  両方を受理する、を固定している)、(iii) `tests/Feature/Projects/AnalysisPipelineTest.php:418`
  (「コードフェンス付き JSON も受理する」)。(ii) と (iii) は**削除ではなく契約の書き換え**
  (禁止事項「既存テストの削除・上書き」は、不変条件が変わったときの意図的な書き換えを
  禁じるものではない。旧契約を新契約の名前で書き直し、削った振る舞いを新しいテストで受ける)。
- **後方互換の並走を残さない**: `invalid_json` case と正規表現による剥がしは同じ変更で消す。
- **移行不要**: `invalid_json` は DB 列に入らない (Log context のみ。実測で文字列の出現は
  enum 定義 1 か所)。migration は書かない。
- **テンプレート乖離台帳**: 変更対象は `docs/template-fingerprints.json` の 281 キーに
  1 つも無く、採用時債務一覧にも無い (実測)。よって `docs/template-divergence.md` と
  `LedgerPins` の変更は不要。新設する gate は aicue のドメイン固有 gate であり、
  同種の先例 (`AnalysisTokenBudgetInvariantTest` 等) も台帳に登録されていない。
- **PHP 列挙 ⇔ TypeScript の同期**: `LlmOutputInvalidReason` は
  `tests/js/architecture/enum-ts-sync-discovery.test.ts` の `PHP_ENUM_EXEMPTIONS` に
  理由付きで登録済み (画面へは値が渡らない) なので、case を増やしても TS 側の同期は不要。
- **既存の不変条件を壊さない**: プロンプト YAML の `max_tokens` / `client_options.timeout` の
  pin (`AnalysisTokenBudgetInvariantTest` / `AnalysisTimeBudgetInvariantTest`)、
  窓口の 1 本道 (`PromptGuardrailTest` / `PromptDefenseWindowGateTest`)、
  `strict_types` 全数宣言、`declare` + 日本語コメント、`echo`/`goto`/`global` 禁止、
  静的検査の共通規約 5 条 ((a) 完全修飾名 / (b) fail-closed / (c) 負例 /
  (d) 使わない収集をしない / (e) 語彙一致はトークン完全一致)。

## スコープ外

- 提供元の停止の理由 (`finish_reason` 等) を復号点へ渡すこと (正典 q2 が未決)。
- 提供元が形を保証する経路 (structured output) への移行。i1 の二択の片方だが、
  本リポジトリは復号点側を選んでおり、移行の要求は正典に無い。
  gate の分類語彙には枠だけ用意し、実体は作らない。
- 診断 DTO (応答長・要約値・囲みの個数) の新設 (判断 4)。
- 最上位に list を要求/禁止する狭め (判断 3)。
- 応答の記録・費用 (llm-cost-monitoring)、待ち時間の予算 (llm-prompt-wait-budget)、
  入口側の防御 (prompt-injection-defense) — いずれも別 feature の持ち分。
- `ScenarioRuleCheck` (読み取り後の決定的な規約検査) — 正典が範囲外と明記。

---

残る指摘があれば挙げてほしい。無ければ全体判定を明示してほしい。
