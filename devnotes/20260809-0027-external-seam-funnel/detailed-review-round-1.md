全体判定: **CHANGES_REQUESTED**

方向性は妥当です。特に「検知 v1 であり遮断ではない」「ObjectStorage / LLM は委譲」「免除語彙を今作らない」は、AGENTS.md の思考原則に合っています。ただし、走査器の仕様に矛盾があり、いくつかの mutation が設計どおり赤になりません。このまま実装に入ると、deny-by-default gate が空振りまたは重複検出で不安定になります。

**S1: REQUEST_CHANGES**

[Critical] `PhpReferenceScanner` の `StaticCall` と `NameReference` の責務が設計内で矛盾しています。  
現行抜粋では `Socialite::driver()` の receiver `Socialite` は「直前が `::` ではない」ため、alias 解決された `NameReference` として拾われます。一方 S2 の注記では「receiver 側は `NameReference` として emit しない」と書かれています。これは S6 の「Socialite static call adopted 1 件」や S2 の facade 判定を壊します。

修正案: どちらかに統一してください。振る舞い保存を優先するなら、receiver 側は `NameReference` として emit し、facade 判定は `NameReference` のみを canonical にするのが安全です。`StaticCall->receiver` は Payment の `Cashier::stripe()` など、メソッド呼び出し規則用に使う、という整理がよいです。

[Warning] `T_NAME_QUALIFIED` を単に `ltrim($text, '\\')` して「解決済み FQCN」と扱うのは危険です。名前空間内の相対 qualified name や first-segment import の扱いを誤る可能性があります。  
修正案: `resolveName()` の仕様に「fully qualified / qualified / unqualified / alias first segment / current namespace」を明記し、S6 に `namespace App\X; Foo\Bar` と `use Vendor\Foo; Foo\Bar` のケースを追加してください。

**S2: REQUEST_CHANGES**

[Critical] `->stripe()` の抑制解除条件が `references` だけを見ているため、`use Stripe\StripeClient;` のような import-only 情報を見られません。S1 では「`use` import は site ではない」と明記されているので、S6 #3 の「同一ファイルに `use Stripe\StripeClient;` があるケース」は設計どおり adopted になりません。  
修正案: `PhpReferenceScanner` から `imports` を site とは別の metadata として返す、または `ExternalSeamScanner` 側で `use` alias map を参照できる scan result を受け取る形にしてください。import を adopted site に混ぜない方針は維持してよいです。

[Warning] facade 判定の実装例が `NameReference` のみで、その後の注記では `StaticCall / Construction receiver` も見ると書いています。二重検出の危険があります。  
修正案: `Socialite::driver()` / `Mail::to()` / `Http::asForm()` を「1 call あたり 1 site」にする canonical rule を明記してください。

**S3: APPROVE**

[Suggestion] `ExternalSeamKind` を `app/Enums/Security` に置くのは許容できますが、本番コードから使わない test inventory 用語彙なら「なぜ app 側 enum に置くのか」を短く補足するとよいです。セキュリティ不変条件の閉じた語彙として本番側に置く、という説明なら十分です。

**S4: APPROVE**

移設方針は妥当です。`dirname(__DIR__, 3)` も `tests/Support/Prompts` から repo root を指すので整合しています。既存 test 名と本文を変えない条件もよいです。

**S5: REQUEST_CHANGES**

[Critical] mutation 計画に矛盾があります。M3 は上段では「Http import を消すと赤」と書かれていますが、詳細表では FQN に書き換えるため「赤くならないのが正解」となっています。  
修正案: M3 を mutation coverage 表から外すか、「FQN でも検出できる positive mutation」として別枠にしてください。赤化確認リストに入れるべきではありません。

[Critical] M4 `FACADE_RULES = []` は「走査母集団が空でない」テストを赤にしません。Payment 系が残るため `adopted` は非空です。  
修正案: 期待する赤を「対称差ゼロ」「S6 の facade unit tests」に変更してください。空振り防止を赤にしたいなら Payment 規則も含めて全規則を無効化する別 mutation に分ける必要があります。

[Warning] テスト 13 の「委譲済み種別の混入」は、設計末尾で自明 assert を避けると補正されていますが、実装手段が未確定です。  
修正案: private constants を Reflection で読む、または scanner に `debugRuleSymbolsForArchitectureTest()` のような test 専用公開 API を作る、のどちらかを明記してください。

**S6: REQUEST_CHANGES**

[Critical] #3 の「`use Stripe\StripeClient;` があるだけで `->stripe()` adopted」は、S1/S2 の現仕様では成立しません。  
修正案: S2 と同じく import metadata を scanner result に含めるか、合成ソース内で実際に `StripeClient` を型参照・構築して payment namespace reference を発生させる必要があります。前者の方が設計意図に合います。

[Warning] #9/#11/#12 は facade static call の二重検出問題を露呈します。  
修正案: 各テストで「adopted 1 件」を期待するなら、S1/S2 の canonical rule を先に固定してください。

**S7: APPROVE**

captcha fake 配線の方向性と負のコントロールは妥当です。特に「secret 未設定ならそもそも外へ出ない」を別テストで固定する点はよいです。

[Warning] Feature test で app environment を変更する手順は既存作法に合わせる必要があります。  
修正案: 既存 3-2 / 3-3 と同じ helper があるならそれを使い、try/finally で config と app env を確実に復元することを明記してください。

**S8: APPROVE**

保証しないものの書き方は誠実です。AGENTS.md の項番衝突も「マージ時に解消」と明記されており妥当です。

**最優先修正**

1. `PhpReferenceScanner` が static receiver を `NameReference` として出すのか出さないのかを固定する。  
2. `use` import を site ではなく metadata として `ExternalSeamScanner` が参照できるようにする。  
3. M3 / M4 の mutation 計画を、実際に赤になるテストへ書き換える。  
4. facade static call が 1 site になるよう S2/S6 の期待値を揃える。