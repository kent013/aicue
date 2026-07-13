全体判定: **CHANGES_REQUESTED**

**1. 使命との整合性**
- [Suggestion] 問題設定は使命に整合しています。S3 の「SOP 抽出 → 作業分解 → シナリオ生成」が bughunt で常時 401 失敗する状態は、North Star の中核である「SOP 起点で AI がシナリオを生成する」を実地検証できていない、という整理で妥当です。

**2. 禁止事項違反**
- [Warning] 概念設計上は明確な禁止事項違反は見当たりません。ただし「実装完了」の条件として、通常の Feature/Browser テスト追随だけでは不足です。今回の不変条件は「既存 Prism fake 経路を壊さないこと」と「bughunt 側で DTO 検証を通ること」の両方なので、Architecture/Feature 相当の回帰テストを設計に明記すべきです。  
  修正提案: `testing` 環境の既存 harness 非破壊を確認する回帰テストと、`bughunt.local` 相当で 3 DTO が決定論 JSON を通過する統合テストを設計の必須成果物として追加してください。

**3. 実現可能性**
- [Critical] `serve` だけを主語にしている点が弱いです。AI 解析は設計文中でも「ジョブ失敗」「withBoundedRetry」と書かれており、実際の失敗地点が queue worker 側なら、`php artisan serve` で static fake を入れても解決しません。`FakeExternalsServiceProvider::boot()` 自体は Laravel の全アプリプロセスで効くので方向性は実現可能ですが、成功条件と検証対象を `serve` に限定しているのは危険です。  
  修正提案: 成功判定を「bughunt の全アプリプロセス（少なくとも HTTP プロセス + ジョブ実行プロセス）で実 API に出ない」に修正し、queue worker 経由でも fake が有効であることを確認する検証を必須にしてください。
- [Warning] `SystemMessage` の役割文を signature に使う案は実装可能ですが、自然文の変更耐性が弱いです。drift-guard を入れても「文言変更で bughunt だけ壊れる」運用負荷は残ります。  
  修正提案: 可能なら YAML 側に機械可読な stable identifier を持たせ、その値で分岐してください。vendor 制約で無理なら、少なくとも signature 抽出ロジックを 1 箇所に閉じ、各 prompt と 1:1 対応をテストで固定してください。

**4. 期待効果の妥当性**
- [Suggestion] 「bughunt の網羅性回復」という効果主張は妥当です。特に DTO 検証を通る最小妥当 JSON を返す、という条件まで置いているので、単なる 401 回避ではなくチェーン通過を狙っている点は筋が通っています。
- [Warning] ただし「S3 全域で発見できるようになる」は言い過ぎです。設計文自身が `PromptExecutionCompleted` / `llm_call_logs` 非発火を既知の差分として認めているため、ログ依存の UI・監査・運用導線は本番同等には検証できません。  
  修正提案: 期待効果の表現を「AI 解析 3 段の主要 UX 導線を bughunt で通せる」に下げ、ログ非生成による未検証領域を明示してください。

**5. リスク**
- [Critical] LLM fake の allowlist に `local` を含めるのはスコープ過大です。今回の課題は bughunt 隔離環境の 401 失敗であり、通常の `local` 開発環境まで runtime static fake を広げると、実 API 連携を見たい手元検証まで canned 応答に潰されます。`Prompt::$fake` はプロセスグローバル static なので、影響範囲も広いです。  
  修正提案: デフォルトの LLM runtime allowlist は `bughunt.local` のみに絞ってください。`local` でも必要なら、別フラグで明示 opt-in に分離するのが妥当です。
- [Warning] 既存 Browser/Feature テスト非破壊の論点は押さえていますが、`testing` 除外だけでは不十分です。rename と resolver 変更で、Pest 側の fake 登録順序や `StrayLlmCallGuard` の前提が崩れる可能性があります。  
  修正提案: 既存 Browser lane と同じ fake install/uninstall API を維持すること、`testing` では provider が一切 `Prompt::$fake` に触らないことを明文化し、その前提を回帰テストで固定してください。

**6. スコープの適切さ**
- [Warning] 機能名是正の rename は筋が良い一方、この item の本質は「bughunt で fake を効かせること」です。rename が広がると差分レビュー面積だけ増えます。  
  修正提案: 互換 alias を残さず同 PR で消す原則自体はよいですが、rename 対象は LLM fake 配線に直接関わる 3 クラスに限定し、周辺名称の整理は広げない方が安全です。
- [Suggestion] `ffmpeg` や S3 region をスコープ外に切っている判断は適切です。

**7. 型安全性**
- [Warning] DTO/JsonResource 方針との衝突はありませんが、型安全性の要所は「canned JSON が DTO の現在の不変条件に追随し続けること」です。概念設計では drift-guard に触れているものの、`fromLlmText()` を直接通す保証をもっと前面に置くべきです。  
  修正提案: 各 canned 応答について「実 prompt を render → fake 実行 → 該当 DTO の `fromLlmText()` 成功」までを 1 本のテストで担保する方針を明記してください。これなら PHPStan L10 の外側で起きるスキーマ崩れも捕捉できます。

総評として、**provider 経由で bughunt 実行時にも Prism fake を配線する方向性自体は妥当**です。差し戻し理由は主に 2 点で、`serve` 中心の成功判定がジョブ実行モデルを十分に捉えていないこと、そして `local` まで runtime fake を広げる設計がスコープ過大で既存の開発検証をマスクしうることです。ここを締めれば、使命・禁止事項・技術制約にかなり整合する設計になります。