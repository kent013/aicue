## 全体判定: CHANGES_REQUESTED

Round 1 の主要指摘は適切に解消されています。特に帰属なし経路の名指し pin、拒否理由 enum、YAML 構文契約、reflection の集約は妥当です。

ただし、窓口 gate の走査範囲に実際の迂回経路が残っており、現状では「factory → 窓口 → 実行単位の一本道」を保証できません。

### A. 防御設定の集約: REQUEST_CHANGES

[Warning] 「16,000 token × UTF-8 最大4バイト = 約64,000バイト」は安全側上界ではありません。1 token が複数文字・複数バイトへ復号される場合があるためです。`200,000` が不適切とまでは断定しませんが、根拠の記述が成立していません。

修正案: 値は即座に変更せず、利用モデル・tokenizerにおける1 token当たりの復号後最大バイト数、実測分布、またはproviderの応答サイズ上限を根拠に再評価してください。証明できない場合は「64,000程度に収まる」という断定を外し、既存正常系の最大実測と十分な余裕を置いた防御上限である、と正確に記載してください。

### B. 入力の無害化: APPROVE

Round 1 の問題は解消されています。拒否理由のenum化、除去数の意味の限定、不正UTF-8の専用例外化はいずれも整合しています。

### C. 応答カナリア: REQUEST_CHANGES

[Warning] 空白除去の`preg_replace('/\s+/u', ...)`が不正UTF-8で失敗した場合、`false`を返して漏洩なしと判定します。直接一致検査は先にありますが、「空白で分割されたカナリア＋不正バイト」では検査がfail-openになります。

修正案: カナリアはASCII hexなので、Unicodeモードを使わずバイト列として検査してください。

```php
$withoutSpaces = preg_replace('/[[:space:]]+/', '', $haystack);
Assert::string($withoutSpaces, '応答の空白正規化に失敗しました');

return str_contains($withoutSpaces, $needle);
```

または正規化失敗時に`PromptResponseRejectedException`として拒否してください。

### D. 窓口と実行単位: APPROVE

`load()`の必須contextと、名指しされた`loadUnattributed()`への分離は適切です。privateな`build()`内だけでnullableにする設計も問題ありません。

### E. factory・YAMLの窓口化: APPROVE

帰属あり3本と帰属なし1本の分類が構造検査・inventoryの両方に接続されており、旧経路の全廃方針とも整合しています。

### F. パイプラインの失敗写像: REQUEST_CHANGES

[Warning] `unsafeResponse()`は「手順書の内容が原因」と断定していますが、カナリアが保証するのはsystem内容の漏洩検知だけです。モデルやprovider側の異常もあり得るため、保証範囲の説明と利用者向け文言が不整合です。また、正当なSOP中の「AIへの指示のような記述」を削除させる誘導にもなります。

修正案: 原因を断定しない文言に変更してください。例えば「安全検査でAI応答を受理できませんでした。手順書を確認して再実行しても解消しない場合は管理者へ連絡してください」のように、検知事実と利用者の行動だけを示します。

[Warning] テスト計画に`InvalidEncoding`の写像・非再試行・チケットrelease確認がありません。

修正案: `InvalidEncoding → unreadableEncoding()`、LLM呼び出し0回、チケットreleaseを同一パイプラインテストで固定してください。

### G. 窓口通過の構造検査 gate: REQUEST_CHANGES

[Critical] 走査根を`app/`だけにすると、`routes/`、`database/`、`config/`、`bootstrap/`からの次の迂回を検出できません。

```php
PromptDefense::load(...);
PromptDefense::loadUnattributed(...);
new GuardedPrompt(...);
UserInput::from(...);
```

これらはPrism直呼びではないため、I-1でも検出されません。結果としてfactory経由の一本道と、帰属なし経路1件限定の保証が成立しません。

修正案: 検査ごとに母集団を分けてください。

- クラス配置・内部参照の所有権: `app/`
- `PromptDefense::load/loadUnattributed`、`new GuardedPrompt`、vendor prompt生成の呼び出しsite: 5根すべて
- `tests/`: 引き続き除外

[Critical] 検査#4の「`PromptCanary`への参照が窓口1ファイルだけ」は、`GuardedPrompt`自身がconstructor/property型として`PromptCanary`を参照するため、提示コードと両立しません。

修正案: 許可集合を責務別にします。

- `UserInput` / `UntrustedTextSanitizer`: `PromptDefense.php`のみ
- `PromptCanary`: `PromptDefense.php`と`GuardedPrompt.php`のみ

[Warning] #7/#8では`untrusted:`のキーを固定していますが、`template:`も文字列リテラルであることが明記されていません。動的template名ではYAMLとの対応検査が曖昧になります。

修正案: template名を文字列リテラルに限定し、その値が対応YAMLの`name`およびファイル名と一致することをpinしてください。

### H. 集約設定の gate: APPROVE

検査2が読み手の双方向pinを既に担っており、追加検査を作らない判断に同意します。

### I. 既存 gate 4本の更新: APPROVE

既存`PrismDirectDispatchScanner`を維持する反論は妥当です。既にtokenベースでコメント・文字列・namespaceを扱うテストがあるなら、全面移植は本件の必要範囲を超えます。

[Suggestion] 節タイトルの「既存 gate 3本」は、I-4追加後の実態に合わせて「既存 gate 4本」に修正してください。

### J. 実行時テストと攻撃コーパス: REQUEST_CHANGES

[Warning] C・Fに対応するケースが不足しています。

修正案として、次を追加してください。

- 不正UTF-8を含む応答のカナリア検査がfail-openしない
- `InvalidEncoding`ではLLMを呼び出さない
- `InvalidEncoding`は非再試行
- `InvalidEncoding`でもジョブfailed・チケットrelease・専用文言になる

Gの5根に対する合成負例も追加し、`routes/`相当のファイルから窓口を直接呼ぶ例が検出されることを確認してください。

### K. 規約文書の更新: REQUEST_CHANGES

[Warning] 現在のGの母集団では、文書に書く「factory → 窓口 → 実行単位の1本道」が実際のgate保証を上回ります。

修正案: Gの走査範囲を修正したうえで文書化してください。また、走査範囲は「窓口gateはappのみ」と一括表現せず、検査対象ごとの5根/appの違いを記載してください。

結論として、Round 1 の対応方針は概ね正しいものの、Gの走査母集団と`PromptCanary`許可集合は実装不能または迂回可能な状態です。この2点を直し、C/F/Jのfail-closedテストを補えば承認可能です。