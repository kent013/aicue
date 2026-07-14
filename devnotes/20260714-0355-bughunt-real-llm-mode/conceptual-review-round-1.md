**全体判定: CHANGES_REQUESTED**

**主要な指摘**
- [Critical] `fake_storage` の既定値 `true` は、「本番挙動は不変」と `ProductionEnvGuard` の追加方針に整合していません。設計文では production に `fake_llm` / `fake_storage` の fail-secure guard を追加するとありますが、`fake_storage` がグローバル既定で `true` のままだと、本番で未設定でも guard に抵触するか、逆に guard を実効性のない条件に弱めるしかなくなります。どちらも設計主張と衝突します。  
  修正提案: `fake_storage` の既定値は `false` にし、bughunt だけ `.env.bughunt.local` または `bug-hunt-shard.sh` から明示的に `true` を注入してください。少なくとも「本番未設定時の評価値」と「guard の判定条件」を設計書に明記する必要があります。

- [Warning] `fake_externals` を「Stripe fake の capability flag」として再定義する前に、現行の全 consumer inventory が必要です。設計文は `FakeExternalsServiceProvider` の LLM 条件差し替えには触れていますが、Captcha / SSO / mail / storage 側が `fake_externals` に暗黙依存していない保証が本文だけでは不足しています。ここが漏れると「LLM だけ real、他は fake」の主張が崩れます。  
  修正提案: 設計に「`fake_externals` 利用箇所の棚卸しを先に行い、Stripe 以外に依存箇所があれば系統別 flag へ移管する」と追記してください。

- [Warning] 実キー注入の安全境界がまだ粗いです。設計文は「echo/manifest に残さない」とありますが、shell 実装では `set -x`、エラーメッセージ、プロセス起動ログ、self-test 出力で漏れる事故が起きやすいです。特に bughunt は運用スクリプト主体なので、ここは概念設計時点で禁止事項として固定した方がよいです。  
  修正提案: 設計に「`ANTHROPIC_API_KEY` は xtrace 無効化区間でのみ読み取り、ログ・stderr・manifest・self-test 出力に決して出さない。欠落時メッセージもキー名のみで値は出さない」を明記してください。

- [Warning] `--real-storage` を CLI に先出しするのはスコープ誤認を招きます。本文では real storage 実配線はスコープ外なのに、フラグだけ公開すると「使える機能」に見えます。  
  修正提案: 今回は `--real-storage` を設計から外すか、「指定されたら明示的に fail-fast して未実装と伝える」運用まで含めて定義してください。

**観点別レビュー**

**1. 使命との整合性**
- [Suggestion] 方向性は妥当です。bughunt の価値は「AI 中核チェーンの実挙動で UX 破綻を見つけること」なので、`real-llm` 既定化は North Star に直接寄与します。
- [Suggestion] 成功条件をもう一段具体化すると良いです。たとえば「SOP 取込→シナリオ生成→解析待ち→失敗時リカバリの 4 導線で、fake では見えない待機/失敗 UX を検出できること」のように、検証対象 journey を固定すると設計の焦点がぶれません。

**2. 禁止事項違反**
- [Suggestion] 設計段階では明確な違反は見当たりません。Prism 直呼び回避、prompt 文字列直書き回避とも整合しています。
- [Warning] ただし「テスト込みで実装完了」の原則に対し、テスト観点がやや薄いです。`real-llm` 既定化は config/provider/script の境界変更なので、単なる self-test だけでなく、`ProductionEnvGuard` と fake install 条件のアーキテクチャ固定が必要です。  
  修正提案: テスト欄に「production で fake flags が fail-fast すること」「bughunt.local かつ `fake_llm=true` のときのみ fake install されること」「`fake_llm=false` では install されないこと」を明示してください。

**3. 実現可能性**
- [Suggestion] Laravel 12 + Inertia/Svelte 構成としては十分実現可能です。変更の主軸が config / provider / shell script に寄っている点も妥当です。
- [Warning] ただし queue worker へのキー注入は設計上の最重要点なので、`serve` と `worker` を別々に起動する現行 bughunt orchestration 前提を本文に固定した方がよいです。ここが曖昧だと「ブラウザでは通るがジョブで 401」の再発余地があります。  
  修正提案: 「real-llm の成立条件は worker 注入まで完了して初めて満たす」と明記してください。

**4. 期待効果の妥当性**
- [Suggestion] 主張している効果は合理的です。特に Q1 の「空キーで 401」を provision fail-fast で潰す設計は筋が通っています。
- [Warning] 一方で、real-llm 化は再現性低下・待ち時間増加・利用コスト増を伴います。これは bughunt の探索価値とトレードオフなので、`--fake-llm` の用途を「再現性重視の切り分け用」として位置づけた方がよいです。  
  修正提案: モード表に「real-llm=発見重視、fake-llm=再現/切り分け重視」を追加してください。

**5. リスク**
- [Critical] `fake_storage` 既定値の問題は上記の通りです。ここは本番安全性に直結します。  
  修正提案: 既定値見直し、または bughunt 専用 env に完全閉域化してください。
- [Warning] parallel shard と real-llm の組み合わせで、レート制限・同時実行コスト・Anthropic 側失敗が UX バグと混線する恐れがあります。  
  修正提案: 設計に「real-llm は既定でも shard 数上限または story 単位の逐次実行を推奨する」運用注記を入れてください。
- [Warning] 「変更は bughunt 基盤/スキルに閉じる」という表現はやや強すぎます。実際には app-wide な `config/testing.php` と provider/guard を触るため、影響は限定的でもゼロではありません。  
  修正提案: 表現を「変更点は app-wide だが、実効は bughunt.local と script 注入 flag に限定する」に修正してください。

**6. スコープの適切さ**
- [Suggestion] real-storage を骨子止まりにする判断自体は妥当です。LLM 実挙動の回復を先にやる方が North Star への寄与が大きいです。
- [Warning] ただし、未配線の機能を flag だけ先に出すのは過大スコープ気味です。  
  修正提案: 今回は `fake_llm` に集中し、storage は別 item に分離する方が設計として締まります。

**7. 型安全性**
- [Suggestion] 今回の主戦場は config/provider/script なので、DTO/JsonResource 観点の懸念は小さいです。
- [Suggestion] PHPStan level 10 を意識するなら、config key 追加時に accessor 側の真偽値前提を固定する設計メモを入れておくと良いです。`mixed` のまま分岐するより、bool として扱う前提をテストで固定した方が安全です。

設計の芯は良いですが、`fake_storage` の既定値と production guard の整合は先に解消すべきです。そこを直せば、real-llm 既定化の設計としてかなり通しやすくなります。