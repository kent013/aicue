全体判定: **CHANGES_REQUESTED**

**1. 使命との整合性**
- [Warning] F-01/F-02 ともに「初見で壊れて見えない」「内部用語を露出しない」という基礎品質の回復であり、North Star の前提には沿っています。ただし F-02 の再発防止が `FormRequest` のみで、今回実害が出ている `inline validate` を機械的ガードから外す設計だと、「専門知識ゼロの現場作業者に常に分かりやすい文言を返す」という体験保証は不十分です。  
  修正提案: `inline validate` を使う画面は `FormRequest` へ寄せるか、少なくとも `inline validate` 対象フィールドの inventory テストを追加して deny-by-default にしてください。
- [Suggestion] F-02 は public/contact・認証周り・撮影 PWA など、初回接触面から優先順位を明示すると、使命への寄与がより説明しやすくなります。

**2. 禁止事項違反**
- [Suggestion] 禁止事項への明確な抵触は見当たりません。テスト先行・`response()->json()` 非依存・DTO/JsonResource 非変更という方針も整合しています。

**3. 実現可能性**
- [Critical] `ContactSubmissionTest` の「生キー `message` 単独露出がないことを検証」は、その検証面を誤ると成立しません。Laravel の validation error bag は通常フィールドキーを `message` のまま保持するため、レスポンス payload や session errors を見れば内部キーは残ります。問題は「表示文言」であって「内部キーの存在」ではありません。  
  修正提案: テスト対象を「UI に表示されるエラーメッセージ」へ限定し、`お問い合わせ内容は必須項目です。` が表示されること、かつ画面文言として `messageは必須項目です。` が出ないことを検証してください。`reason` も同様です。
- [Warning] `ValidationAttributeCoverageTest` で「FormRequest をコンテナ経由で全件 instantiate して `rules()` 実行」は、route parameter / auth user / request input に依存する `rules()` があると壊れやすく、ALLOWLIST が肥大化しやすいです。  
  修正提案: 依存クラスは `safeToCallRules()` 相当の明示 inventory にするか、属性名カバレッジだけを別の静的契約へ切り出してください。
- [Warning] 「トップレベルキー収集」では `steps.*.title` のようなネスト規則を取りこぼす可能性があります。  
  修正提案: 収集対象は“トップレベル”ではなく “rules 配列の全キー” にし、数値セグメント正規化後に `steps.*.title` のような dotted / wildcard key をそのまま照合してください。
- [Warning] env invariant を「`${VAR}` は同一ファイル内の先行定義必須」と強く置くと、将来意図的な外部注入参照まで禁止する恐れがあります。  
  修正提案: テスト名どおり「自己参照・前方参照の禁止」に目的を絞り、許容する外部参照があるなら allowlist を持たせてください。

**4. 期待効果の妥当性**
- [Warning] `.env.bughunt.local.example` 修正だけでは、既に各開発環境に存在する `.env.bughunt.local` は直りません。設計文でも手作業修正と書いていますが、そこが運用依存のままだと再現確認の取りこぼしが出ます。  
  修正提案: `bug-hunt` の provision/setup フローで `APP_NAME` の妥当性チェックを入れるか、少なくとも「example と実ファイルの差分検知」を自動化してください。
- [Suggestion] F-01 の効果は「本番品質向上」より「bug-hunt 環境の信頼回復」と表現した方が、期待効果の射程が正確です。

**5. リスク**
- [Warning] `attributes` へ大量追加すると、UI ラベルと翻訳ラベルが徐々にズレて別語彙になるリスクがあります。  
  修正提案: 既存 UI ラベルを正とする対応表を作るか、少なくとも user-facing フィールド名の命名規約を短く残してください。
- [Suggestion] `attempt_token` や `checksum_sha256` のような内部寄り項目は、将来ユーザーに出るなら技術用語のままでは理解しづらいので、露出経路があるものだけ優先して自然な日本語に寄せるとよいです。

**6. スコープの適切さ**
- [Warning] F-02 を “systemic” と位置づけるなら、再発防止の対象を `FormRequest` 限定にしたまま終えるのはやや過小です。  
  修正提案: 今回は `FormRequest` + 現在存在する `inline validate` の inventory までを最小完了条件にしてください。そこまでやれば「仕組みが機能していない段階で値を弄るな」という原則にも合います。
- [Suggestion] 逆に F-01 と F-02 を同一 PR で扱うこと自体は妥当です。どちらも「コピー品質の恒久化」という一貫したテーマに収まっています。

**7. 型安全性**
- [Warning] 実装対象自体は DTO/JsonResource パターンに触れず、PHPStan level 10 への直接的な悪影響も小さいです。ただし `ValidationAttributeCoverageTest` の ALLOWLIST を「クラス名+キー」の文字列連結で持つと stringly-typed になり、保守時に壊れやすいです。  
  修正提案: `array<class-string, array<string, string>>` のような構造化マップにして、対象クラスと除外理由を型付きで表現してください。
- [Suggestion] attributes 棚卸し用の補助関数を作るなら、戻り値を `array<string, string>` に固定しておくと PHPStan 上も扱いやすくなります。

この設計は方向性自体は正しいですが、**F-02 の再発防止設計がまだ半端**です。特に「表示文言」と「内部 error key」を分離して扱うこと、`inline validate` を deny-by-default から外したまま “systemic fix” と言い切らないこと、この2点は設計段階で詰めてから進めるべきです。