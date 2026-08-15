全体判定: **CHANGES_REQUESTED**

設計の方向性は概ね妥当です。宣言の単一正本化、provider を実行者へ落とす方針、別プロセス観測、seeder 配線の deny-by-default 化はいずれも本件の穴に対応しています。ただし、家系標準 v1 の要求との対応が一部曖昧で、このまま実装に入ると「標準形を満たした」と言い切れない箇所があります。

## 1. 使命との整合性

[Suggestion] 利用者向け機能ではないが、探索的 QA の信頼性を上げるため、使命への間接貢献は成立します。特に撮影 PWA を実ブラウザで走らせる bug-hunt の偽陽性・外部汚染を防ぐ点は、品質保証基盤として合理的です。

## 2. 禁止事項違反

[Warning] 施策 2 の `cmd_provision` に `migrate:fresh --seed` が出てきます。既存 bug-hunt wrapper 経由なら許容される文脈ですが、設計書だけ読むと dev DB 破壊禁止との境界が曖昧です。

修正提案: 「本件で追加・変更する検査は `scripts/bug-hunt-shard.sh` の用途別 wrapper のみを正とし、生 artisan / 生 psql / dev DB への `migrate:fresh` は扱わない」と明記してください。別プロセス観測も DB 非接続を不変条件に置くのがよいです。

## 3. 実現可能性

[Warning] 施策 4 の別プロセス観測は有効ですが、Architecture レーンでアプリ boot、Socialite 転送先、production 起動失敗まで見ると、設定キャッシュ・env・autoload・provider 遅延の影響で壊れやすくなります。

修正提案: 観測用スクリプトの責務を「DB 非接続」「container 解決」「redirect URL 生成」「終了コード確認」に限定し、HTTP サーバ起動やブラウザ起動はしない、と明文化してください。production 対照も「ProductionEnvGuard が fake env の実値を検出して fail-fast する」一点に絞るべきです。

## 4. 期待効果の妥当性

[Suggestion] 効果の主張は妥当です。特に「テストプロセス内で provider を手動実行する検査では、遅延読み込みや設定キャッシュの退行を見られない」という問題設定は筋が通っています。

## 5. リスク

[Warning] 家系標準 v1 の (3)「差し替え処理を 1 本に集約し全レーンが共有、レーンからの個別 fake 直呼びの静的禁止」への対応が弱いです。provider の一元化は書かれていますが、レーン側からの個別 fake 直呼び禁止をどう検出するかが設計に出ていません。

修正提案: `tests/`、bug-hunt scripts、support helper を対象に、許可された集約窓口以外から `Http::fake`、Socialite fake、Storage fake、LLM fake などを直接呼べない architecture test を追加するか、既存 gate の対象範囲に本件の集約窓口を登録する、と明記してください。

## 6. スコープの適切さ

[Warning] 安全下限集合を「新設しない」判断は、現時点の aicue 固有事情としては理解できますが、家系標準 v1 の (4) が明示要求である以上、そのままだと標準未充足に見えます。既存の `BughuntEnvExampleContractTest` と `.env.bughunt.local.example` は「特定 env 例で必須フラグが true」を固定しているだけで、「安全下限集合」という名前付き概念の正本にはなっていません。

修正提案: 過剰な減算機構は作らず、宣言正本に最小限の `requiredFlagsForBughunt()` または同等の定数を置いてください。中身は既存 3 フラグのうち必須なものを返すだけでよいです。既存テストはその集合を参照する形へ寄せれば、同じ不変条件の二重管理ではなく「名前付き正本を既存 gate が読む」形になります。

[Suggestion] seeder 目録は有効ですが、全 seeder を deny-by-default にすると変更コストがやや大きいです。まずは `database/seeders` 全体の分類を持つ設計でよいものの、検査の主目的は bug-hunt 専用 seeder の混入防止なので、理由文でそこを中心に置くとスコープ過大に見えにくくなります。

## 7. 型安全性

[Suggestion] 宣言クラスを本番 autoload 配下に置く方針は妥当です。PHPStan level 10 を意識するなら、宣言 entry は配列より readonly value object / enum に寄せ、abstract / real / fake の class-string 型を明示するのがよいです。

---

まとめると、設計の核は承認可能です。ただし実装前に次の 3 点を設計へ反映してください。

1. 安全下限集合を「作らない」ではなく、宣言正本上の最小概念として表現する  
2. レーンからの個別 fake 直呼び禁止を静的 gate として明記する  
3. 別プロセス観測と bug-hunt DB 操作の安全境界を明文化する