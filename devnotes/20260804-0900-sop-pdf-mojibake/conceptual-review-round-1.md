全体判定: CHANGES_REQUESTED

**1. 使命との整合性**
- [Suggestion] 問題設定は North Star に強く整合しています。SOP 抽出の入口で壊れた本文を止めるのは、「SOP を起点に AI がカット設計する」という前提の修復そのものです。
- [Suggestion] 特に「AS を救う」と「AW/0 byte を LLM に渡さない」を分けて考えている点は妥当です。復元と遮断を独立させた設計は、使命に対して筋が良いです。

**2. 禁止事項違反**
- [Suggestion] 提案内容の範囲では、提示された禁止事項への直接抵触は見当たりません。`response()->json()` 直書き、Prism 直呼び、prompt 直書き、UI の disabled 制御なども含まれていません。
- [Suggestion] テスト追加を実装項目に含めている点も妥当です。実装時は記載どおり `tests/Unit/Manual/SopTextExtractorTest.php` だけでなく、不変条件として残すべき期待値表を十分に固定してください。

**3. 実現可能性**
- [Warning] `config('manual.analysis_min_japanese_ratio')` をそのまま比較に使うと、Laravel の `config()` が `mixed` を返すため、PHPStan level 10 で型が荒れやすいです。  
  修正提案: `SopTextExtractor` 側に `private function minJapaneseRatio(): float` のような専用アクセサを置き、`is_float`/`is_int` を通して `0.0 <= x && x <= 1.0` を明示検証したうえで `float` を返す形にしてください。異常値は即 fail させる方が安全です。
- [Suggestion] アプリ側後段修復で閉じる、という判断は実装可能性の観点で妥当です。vendor 差し替え点がない、という前提の置き方も筋が通っています。

**4. 期待効果の妥当性**
- [Warning] 可読性ゲートが「壊れた PDF」と「正当だが日本語でない PDF」を同じ `unreadable()` に畳む設計だと、ユーザー向け説明が事実とずれる可能性があります。英語/独語/仏語の searchable PDF は埋め込み不良ではなく、単に v1 の言語スコープ外です。  
  修正提案: `unreadable()` を「文字化け/抽出不良」と「日本語本文を確認できない」のどちらにも耐える文言へ修正するか、例外を `unsupportedLanguage()` と分けてください。少なくとも文言で「日本語の手順書のみ対応」を明示した方がよいです。
- [Suggestion] 「負の指標」ではなく positive gate に寄せた判断は妥当です。AW を拾えない設計を避けている点は評価できます。

**5. リスク**
- [Warning] 閾値 0.10 の根拠は現時点の実測では納得できますが、設計上は日本語比率の低い正当 SOP を将来誤拒否するリスクが残ります。特に型番・設備コード・チェックリスト主体の帳票系 SOP は、現状の対照コーパスより低く出る可能性があります。  
  修正提案: テスト期待値表に「有効だが日本語比率が低い」合成 fixture を最低 1 つ追加し、0.10 が壊す境界を明示してください。加えてログには gate fail の reason code を含め、運用で誤拒否を観測できるようにしてください。
- [Suggestion] 本文をログに出さない方針は適切です。`source_document_id` と比率だけで十分です。

**6. スコープの適切さ**
- [Suggestion] スコープは概ね適切です。OCR、pdfparser 差し替え、T091 の値変更を明確に外しており、今回の Critical に対して過不足が少ないです。
- [Suggestion] ただし別 TODO 提案まで含めるなら、「本タスクでは起票のみで実装しない」を実装計画上も明示しておくと、PR スコープが膨らみにくいです。

**7. 型安全性**
- [Warning] `AnalysisFailedException::unreadable()` を増やすだけだと、呼び出し側で例外種別に応じた扱いが曖昧なまま残る可能性があります。文字化け系とスコープ外言語系を同一例外・同一メッセージに寄せると、後続の分岐やテスト名も不正確になります。  
  修正提案: 例外 API を役割ベースで切るか、少なくとも factory メソッド名とメッセージが一致するよう再設計してください。`unreadable()` が担う意味は「抽出失敗」なのか「日本語本文不在」なのかを一義にしてください。
- [Suggestion] DTO/JsonResource パターンには直接触れない変更なので、主な型安全論点は `config()` の `mixed` と例外契約の明確化です。そこを締めれば PHPStan level 10 は十分狙えます。

変更要求の要点は 2 つです。  
1. 日本語 gate で落ちる「非日本語 PDF」を、文字化けと同じ理由・同じ文言で扱わないこと。  
2. `analysis_min_japanese_ratio` の取得と検証を明示的に型付けすること。

この 2 点が入れば、設計全体はかなり堅く、North Star への貢献も明確です。