# 対応マトリクス: conceptual-review Round 1

Codex 判定: **CHANGES_REQUESTED** ([Critical] 0 / [Warning] 8 / [Suggestion] 6)。
8 件の Warning すべてに判断を付け、7 件は概念設計を修正、1 件は根拠を添えて反論した。

## [Warning] 「禁止事項 3 = 旧語彙を並走させない」は誤参照 (実際は思考原則 3)

- 判断: **対応する**
- 根拠: 指摘は正しい。AGENTS.md の禁止事項 3 は「dev DB への破壊操作」、
  「後方互換の並走を残さない」は**思考原則 3** である。
  一次入力の `recon-brief.md` 自体が誤って「禁止事項 3」と書いており、それを引き写していた。
- 対応内容: 概念設計の 2 箇所 (「改善アイデア > (b) の 3 箇所」「制約・前提」) を
  **思考原則 3** に修正。ブリーフ側の誤りであることも注記した。

## [Warning] 「次の 4 つ」と書いて 5 項目ある

- 判断: **対応する**
- 根拠: そのとおり。後続の TODO 化・詳細設計で成功判定を数えるため、数字の齟齬は害になる。
- 対応内容: 「次の 5 つ」に修正。

## [Warning] 分類表の具体が不足 (Stripe 13 + Cashier 8 の class-to-case を先に決めよ)

- 判断: **対応する**
- 根拠: 指摘は本質的。「すべて明示分類する」と宣言しておきながら中身が無いと、
  詳細設計で分類の難所 (`CardException` / `IdempotencyException` / `IncompletePayment`) に
  ぶつかったときに case を増やす方向へ流れる。**語彙の粒度は概念設計で確定すべき**である。
- 対応内容: 「分類語彙」節に **class-to-case 表 (vendor 21 + framework 3 = 24 entry)** を追記。
  併せて `provider_rejected` の定義を
  「決済事業者が要求を受理しなかった。**同じ要求を再送しても収束しない**
  (要求内容・認証情報・利用者操作のいずれかが要る)」へ精緻化した
  (Cashier の `IncompletePayment` = SCA 追加操作要求を無理なく収容するため。
  case を増やさずに済む)。写像の解決規則 (実クラス → 親クラス連鎖の順で最初の一致。
  グローバル SPL クラスは表に入れない) も明記した。

## [Warning] 「外部生成文字列がログ基盤に載らなくなる」は過大主張 (3 job は catch なしで伝播する)

- 判断: **対応する**
- 根拠: 指摘は正しい。伝播側 3 job の例外は Laravel の例外ハンドラ / `failed_jobs` に
  message ごと載る。無限定に書くと、T131 Round 4 で直したのと**同じ種類の嘘**
  (「アプリのどこにも残らない」を経路限定せずに書いた) を繰り返すことになる。
- 対応内容: 期待効果を
  「**`AutoRechargeService` の構造化ログ context と `report()` 文言**から外部生成文字列が消える」
  に限定。加えて「伝播側 3 job は `failed_jobs` / 例外ハンドラに vendor メッセージが載る。
  これは意図した非対称であり、redact は横断基盤の話でスコープ外」を明記し、
  **目録の免除根拠にこの非対称を書かせる**ことを設計要件に加えた
  (免除を「catch しないから安全」ではなく「catch しない結果どこに何が残るか」を書かせる)。

## [Warning] `failure_class=unknown` の監視・初動が運用に載らないと分類漏れが放置される

- 判断: **対応する**
- 根拠: そのとおり。`unknown` を「欠陥の通知」と設計上位置づけた以上、
  通知先と初動が無ければ通知になっていない (機能の名前に立ち返れ)。
- 対応内容: 「未知の扱い」節に**運用契約**を追記。
  ログ検索条件 (`failure_class=unknown` かつ `error_class` を facet)、初動
  (`error_class` を写像表へ追加 → gate の cap を更新)、責務の所在
  (`docs/architecture.md` へ記載する) を書いた。

## [Warning] `error_class` を「有界」と書くのは不正確 (未知のアプリ例外も入る)

- 判断: **対応する**
- 根拠: 正しい。有界なのは「写像表に載っている vendor 例外」だけで、
  `error_class` が取りうる値の集合はアプリの例外クラス全体である。
- 対応内容: 表現を「**外部サービスが生成する文字列ではない class-string**
  (値域はコードベース + vendor のクラス名に閉じる)」へ改め、
  「gate によって有界性が保証されるのは vendor 例外の分類表の側」と分けて書いた。

## [Warning] `AGENTS.md` への追記まで同 PR に含めるのは重いかもしれない

- 判断: **反論する (追記は行う)**
- 根拠: AGENTS.md ドメイン固有規約の既存 6 項目は、いずれも
  「deny-by-default gate で恒久的に守る不変条件」である
  (シナリオ整合の共有ロック / 容量 Quota 予約 / 履歴復元 3 枚セット / 課金ゲート route 配置 /
  throttle 付与 / ジョブの重複実行)。本設計もまさに
  **「gateway を注入されるクラスは観測か伝播のどちらかに目録登録が必須」**という
  deny-by-default gate 付きの恒久不変条件であり、Codex 自身が示した
  「AGENTS.md に書く条件」を満たしている。
  むしろ書かないと、次に gateway を注入する人は gate が赤くなるまで規約の存在を知らない。
- 対応内容: 反論のうえで**分量は 1 項目・数行に抑える**ことを制約として明記した
  (詳細は `docs/architecture.md` 側に置く)。

## [Warning] PHPStan level 10: classifier の public API と写像表の型を固定せよ

- 判断: **対応する**
- 根拠: 妥当。`is_a()` の第 3 引数や `class-string` の扱いは level 10 で必ず問題になる。
- 対応内容: 概念設計に public API を確定して記載した。
  ```php
  public static function classify(Throwable $throwable): GatewayFailureClass;
  /** @return array{failure_class: string, error_class: class-string} */
  public static function context(Throwable $throwable): array;
  /** @return array<class-string, GatewayFailureClass> */
  public static function map(): array;   // gate が参照する写像表 (正本)
  ```
  解決は `is_a()` ではなく `$e::class` から `get_parent_class()` で辿る決定的な走査にする
  (level 10 で `class-string` が保てる / 表の順序に依存しない)。

## [Suggestion] 使命への貢献の書き方 / vendor 走査条件の固定 / fixture 方針 / DTO 非該当

- 判断: **一部対応する**
- 対応内容:
  - 使命への貢献を「課金障害の一次切り分けが早くなる」に抑えた (「撮影が止まる時間」は削除)。
  - vendor 走査の除外条件 (abstract / interface / サブ名前空間) を概念設計に明記し、
    詳細設計の gate 仕様へ引き継ぐことにした。
  - fixture 方針・DTO 非該当は指摘なしのため変更なし。
