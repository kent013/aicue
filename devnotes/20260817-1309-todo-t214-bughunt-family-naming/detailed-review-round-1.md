**全体判定: CHANGES_REQUESTED**

主旨とスコープは妥当です。特に「旧名を残さない」「ファイル数統合へ踏み込まない」「fake 配線の fail-secure を守る」という線引きはよいです。ただし、施策 4 と受け入れ条件 A-6 に機械検証上の穴があり、このままでは「振る舞い完全不変」と「fake 参照走査の候補維持」を十分に担保できません。

**施策 1: REQUEST_CHANGES**

[Warning] `docs/TODO-closed.md` を丸ごと除外する設計は、将来の旧名再流入に沈黙します。設計書は穴を明記していますが、既存作法として引用している `RouteCacheExemptionPremiseTest` は件数 pin で扱っており、ここだけ粒度が粗いです。  
修正案: `docs/TODO-closed.md` は丸ごと除外ではなく、既知旧名の件数 pin にしてください。aicue:T214 のクローズ記録で増えるなら、その増分をレビュー可能な pin 更新として扱う方が deny-by-default の作法に合います。

[Suggestion] N-3 の「除外は 3 つちょうど」は、実ファイル数ではなく除外定義数を pin する意味に読めます。`devnotes/` は prefix なので「3 ファイル」と誤読されない名前にした方がよいです。

**施策 2: APPROVE**

大きな問題はありません。seeder 名、目録キー、`guardPremiseTest`、bug-hunt shard の投入列まで波及対象に含めており、S-1 / S-3 / S-4 / S-9 で漏れを止める設計も妥当です。

[Suggestion] seeder 内の warn/info 文言まで逆置換検証 A-6 の対象にするなら、メッセージ文字列変更も「振る舞い変更ではないが観測可能差分」として明記しておくとレビュー時の解釈が揃います。

**施策 3: APPROVE**

provider の登録点、登録順、ロード済み provider、`tests/Pest.php` まで波及対象に入っており、fake 配線が外れて外部サービスへ届く事故への観点も十分です。

[Suggestion] `bootstrap/cache/services.php` だけでなく `bootstrap/cache/packages.php` など cache 系生成物全般は追跡外前提、と書くと Laravel cache 周りの説明がより正確です。

**施策 4: REQUEST_CHANGES**

[Critical] `BughuntFakesServiceProvider` が `FakeClassCatalog::namedClasses()` から外れるため、`ExternalFakeWiringInvariantTest` の 3-10 が provider 自身を候補に含めなくなる点は説明されていますが、そこで「期待値は変わらない」とする根拠が弱いです。3-10 の候補集合は `implementationClasses() ∪ namedClasses()` のままなので、provider 改名後に provider クラス名そのものを検出対象から外します。自己参照を数えないから結果に出ていなかった、という説明だけでは、将来 provider が provider 系クラスを参照した場合の検出範囲が変わる可能性を閉じられていません。  
修正案: 3-10 側の候補集合も `implementationClasses() ∪ namedClasses() ∪ placementExceptions()` に統一してください。少なくとも `BughuntFakesServiceProvider::class` が 4-3 の候補集合に含まれることを専用 assertion で固定してください。

[Warning] `placementExceptions()` は名前の意味が「配置例外」なのに、改名後は「参照走査候補」の意味が主になります。設計では名前を変えない判断ですが、関数名と役割のズレが今後の削除事故を誘発します。  
修正案: 既存名を残すなら、`referenceScanInfrastructureClasses()` のような別メソッドを追加し、`placementExceptions()` はそれを含む形にするか、少なくとも 4-2 で「この 2 件は参照候補でもある」ことを明示 assertion してください。

**受け入れ条件**

[Critical] A-6 の「逆置換でバイト一致」は、改名 4 件と新規テスト 1 件を除外すると書かれていますが、実際にはテスト名、コメント、docblock、allowlist パス、provider source path など、施策 4 で意図的に意味を書き換える箇所があります。これらは単純な新名→旧名の逆置換では main と一致しない可能性が高く、機械検証条件として破綻します。  
修正案: A-6 を分解してください。  
1. 実装本体のロジック領域は逆置換で一致。  
2. テスト・目録・コメント・doc は「名前追従と検査意味の更新」として別検証。  
3. provider/seeder 本体は AST または `diff -w` ではなく、旧名/新名以外の実コード差分がないことを対象ファイル単位で確認。

[Warning] A-9 の「テスト総数が元の数 + 新規 5 test」は flaky です。Pest の dataset 展開、既存テスト追加、環境差で数が揺れやすく、改名の受け入れ条件として過剰です。  
修正案: 新規 `BughuntNamingResidualTest` の N-1〜N-5 が存在して緑、既存の指定 invariant が緑、全体 test が緑、に留める方が機械検証として堅いです。