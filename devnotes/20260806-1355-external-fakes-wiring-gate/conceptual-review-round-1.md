全体判定: **CHANGES_REQUESTED**

設計の方向性は妥当ですが、同一 Laravel Application 上で provider を再実行して「fake → real に戻る」と見る前提が危険です。ここを直さないと、検査自体が不安定になるか、実装時に偽グリーン/偽レッドを生みます。

**1. 使命との整合性**

[Suggestion] 外部 fake 配線の検査は、North Star への直接機能ではありませんが、撮影データ保存・課金・bug-hunt の安全性を支える基盤として十分に貢献しています。特に「緑のテストが実 S3 / 実 Stripe を叩く」事故を防ぐ目的は、AI-CUE の現場利用に対して本質的です。

**2. 禁止事項違反**

[Warning] 「既存テストは削除も改変もしない（禁止事項 3）」の参照がずれています。禁止事項 3 は dev DB 破壊操作であり、既存テスト改変禁止ではありません。

修正提案: 理由は「既存 Feature テストは振る舞い回帰として残し、Architecture 側を不変条件の正本にする」と書き換える。

[Warning] テストファーストの成功判定がやや曖昧です。このタスクは「本番コードを直して既存失敗を通す」類ではなく、「新規 gate が mutation を捕まえる」類なので、実装前に新テストが素で赤になるとは限りません。

修正提案: 「mutation test によって現行検査の穴を先に確認し、新 gate 追加後に同じ mutation が赤になることを確認する」と明記する。

**3. 実現可能性**

[Critical] 同一 container 上で `flag on → provider 実行 → fake 解決 → flag off → provider 再実行 → real に戻る` は成立しない可能性が高いです。Fake provider が flag off 時に early return するだけなら、既に上書きされた binding は real に戻りません。Laravel の provider lifecycle は「後勝ち bind」には向きますが、「provider 再実行による巻き戻し」には向きません。

修正提案: 往復検査は同一 Application の再実行でなく、ケースごとに fresh Application を boot して検証する設計に変える。どうしても同一プロセスで見るなら、`refreshApplication()` 相当で app を作り直すか、real provider の再登録と container instance 解決済み状態の破棄まで明示する。

[Warning] `production` env への一時差し替えは、`ProductionEnvGuard`、boot 済み provider、config cache 前提と絡むため、通常の testing app 内で雑に `$app['env']` を変えるだけだと意味が揺れます。

修正提案: allowlist 外の検査は「FakeExternalsServiceProvider 単体の条件分岐検査」と「bootstrap 登録検査」を分ける。production 相当の app 全体 boot を再現しないなら、その限界をテスト名に出す。

**4. 検査の有効性**

[Warning] `bind(A::class, B::class)` だけの token 走査では、将来 `singleton` / `scoped` / `instance` / `extend` / contextual binding に変わった場合に網羅性 gate から漏れます。

修正提案: provider 内で許可する container 差し替え API を明文化し、走査対象もその API 全体に広げる。逆に `bind` のみを規約にするなら、`FakeExternalsServiceProvider` では fake 差し替えに `bind` 以外を使えない Architecture テストを追加する。

[Warning] storage fake が real のサブクラスである点を厳密クラス一致で見る判断は正しいです。ただし、interface 系も同じく厳密一致に統一しないと、将来 fake が real interface の装飾実装になった時に検査意図がぼやけます。

修正提案: inventory の全 entry で `resolved::class === expected::class` を唯一の判定にする。

**5. リスク**

[Critical] `Prompt::$fake` の static 状態、config/env 書き換え、route 登録、container binding はすべて同一プロセス内リークの温床です。特に Architecture lane が `RefreshDatabase` なしで軽量に走るなら、後続テスト汚染が起きても原因追跡が難しくなります。

修正提案: 各 test case を fresh app 前提にし、`finally` で `Prompt::stopFaking()`、config/env 復元、解決済み instance 破棄を必ず行う。storage signed route の boot 検査を入れるなら route collection への重複登録も確認するか、別 app で隔離する。

[Warning] `--parallel` 自体はプロセス分離されるので主リスクではありません。主リスクは同一 worker プロセス内の test order 依存です。

修正提案: mutation だけでなく、Architecture suite 単体の複数回実行で順序依存が出ないことを詳細設計の検証に入れる。

**6. スコープの適切さ**

[Suggestion] 柱 2 の別プロセス probe を今回外す判断は妥当です。aicue に外部ログイン driver fake が無いなら、今入れると過剰です。

[Warning] 柱 3b の実 env 二重判定を外す判断も概ね妥当ですが、「config cache が古いシナリオを踏んでいない」だけだと弱いです。production fake 混入は高リスクなので、後続 TODO の発火条件を明確にした方がよいです。

修正提案: 「production で fake flag が incident / near-miss になった」「config cache 前提の deploy 手順を変更した」「外部 fake flag が増えた」のいずれかで再検討、と条件を書く。

**7. 検査設計の妥当性**

[Warning] inventory + 実証 + 網羅性走査の三層構成は良いですが、inventory が「一覧を満たすだけ」の台帳になるリスクがあります。特に provider ソース走査が弱いと、三層のうち網羅性だけが形骸化します。

修正提案: inventory entry に `abstract / real / fake / flag / allowed env / phase` だけでなく、「なぜ外部副作用として危険か」「削除 mutation がどの test を赤にするか」を短く持たせる。詳細設計では mutation 手順を検証項目ではなく受入条件に格上げする。

結論として、方針自体は採用可能です。ただし **container/provider の巻き戻しを同一 app 再実行で検証する設計を捨て、fresh Application 単位の実証に直すこと** が承認条件です。