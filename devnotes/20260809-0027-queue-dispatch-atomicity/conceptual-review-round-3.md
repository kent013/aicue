全体判定: **CHANGES_REQUESTED**

`ShouldBeUnique` 問題の解決方針は妥当で、Critical は閉じています。一方、R-10 の前提が機械固定されていないことと、§5-1 の本番ファイル母集団の完全性がまだ十分でないため、Warning が残ります。

**1. `ShouldBeUnique` 撤去**

[Suggestion] `ShouldBeUnique` 撤去 + tx内dispatchで、rollback時のunique lock残留はネスト深さに依存せず解消します。

結果の一回性が以下で担保されているなら、入口排他の撤去も整合しています。

- org行の`lockForUpdate()`
- pending存在検査
- DB partial unique
- unique violationのno-op化
- trigger job自身が課金・確定処理を行わない

詳細設計では、同一orgへのtrigger jobを並行実行し、attemptが最大1件になるbehavioral testを置けば十分です。

[Suggestion] §8末尾の一般論「`ShouldBeUnique` のunique lockはrollbackで解放されない」は正しいですが、対象jobでは撤去済みです。「本設計で残る他の`ShouldBeUnique` jobには該当する」と対象範囲を明記すると、R-6との読み違いを防げます。

**2. AG-127の整理**

[Suggestion] 「確定1の母集団をqueue dispatchに限定し、その母集団で除外0件」とする整理は成立しています。0件のexemption enumを作らない判断も思考原則2に沿っています。

ただし、低残高通知はqueue母集団外であっても、付随的副作用としての失敗分離という設計課題は残ります。現在の設計はその意味論の縮小を明記しており、概念設計としては受容可能です。

[Suggestion] 「AG-127の除外は0件」ではなく、常に「確定1のqueue dispatch母集団では0件」と修飾してください。通知まで含めた広義の付随的副作用に除外が存在しない、と誤読されるのを防げます。

**3. R-10**

[Warning] `attempts=1`なら今回のafter-commit callback例外による業務クロージャ再実行は起きない、というLaravelの分岐理解は妥当です。ただし、`grep`の0件確認だけでは不変条件になっていません。

提示された正規表現では、少なくとも次を確実には捕捉できません。

- 複数行の第2引数
- 変数による`$attempts`
- `DB::connection(...)->transaction(...)`
- 独自wrapper経由
- Closure以外を第1引数に渡す形式

修正提案: 「syncレーンで実行され得るtx内dispatchを含むトランザクションはattempts=1」というArchitecture gateを追加してください。全面的なPHPパーサーを新設する必要はなく、既存の構造解析手段または対象経路のdeny-by-default inventoryで固定できます。これを登録して初めて「再実行は起きない」と保証できます。機械固定しない場合は、§8を「現行実査では起きないが将来退行を検出しない」に弱める必要があります。

**4. 空振り防止**

[Warning] fixtureによる「列挙→読み込み→検出」と既存queue inventoryへの接続は十分です。一方、D1/D2の本番母集団について、`TicketLedgerService.php` 1件のアンカーでは「app全体」の完全性を保証できません。

例えば列挙器が誤って`app/Jobs`や`app/Actions`を除外しても、TicketLedgerのアンカーは通ります。fixture側の再帰列挙が正しくても、本番パスのフィルターや除外設定だけが壊れる可能性があります。

修正提案: D1/D2の本番母集団を、独立した単純な列挙結果とexact-fit比較してください。例えばArchitecture test側で`app/**/*.php`の正規化済み集合を作り、`appPhpFiles()`との対称差が空であることを検証します。これは検出ロジックの二重実装ではなく、母集団境界の固定です。代表アンカー方式を残すなら、少なくとも走査対象の主要トップレベルディレクトリごとにアンカーが必要です。

**5. 設計内の不整合**

[Warning] §5-2 mutation #10が旧設計のままです。

> `AutoRechargeTriggerJob` のdispatchを`reserve()`のtxの中へ入れる  
> 「除外2件はreserveのtxを抜けてから実行される」

修正後設計ではtrigger jobはすでにtx内へ移すため、この変異は赤化になりません。

修正提案: mutation #10を次のいずれかへ置換してください。

- `AutoRechargeTriggerJob`に`ShouldBeUnique`を戻す → 撤去を固定するArchitecture testが落ちる
- trigger dispatchをtx外へ戻す → 実jobs表とtx levelの原子性テストが落ちる
- downstreamのpartial uniqueを外す → 並行実行の一回性テストが落ちる

[Suggestion] R-3と§11には「既存4契約」、M8には「5契約」とあります。`JobExclusionOrderingInvariantTest`追加後の5件へ統一してください。

**6. 型安全性・スコープ**

[Suggestion] `list<QueueDispatchAtomicityViolation>`、規則ID enum、readonly DTOによる設計はPHPStan level 10に適合できます。生のconfig値をDTOへ保持する場合は`mixed`のまま公開せず、表示用に型を限定した正規化値へ変換してください。

PRを分割しない判断と、施策ごとの赤→実装→緑の順序は妥当です。上記Warningは設計の局所修正で閉じられ、スコープを分割する必要はありません。