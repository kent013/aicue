**全体判定: CHANGES_REQUESTED**

方向性自体は妥当です。`duplicate()` の初期状態を DB default ではなくアプリ層で固定するのは、使命整合・実現可能性・型安全性の観点で筋が良いです。一方で、この設計のままだと「不変条件を守れていることを将来壊れにくくする」ための検証が不足しています。

[Critical] 振る舞いを保証する回帰テストが不足しています。  
Architecture テストの inventory 更新だけでは、「`duplicate()` が常に `draft / 0` を明示代入する」という業務不変条件の回帰を捕まえきれません。deny-by-default 静的走査は“書き込み経路の存在”は守れますが、“複製結果の値”までは守れないためです。  
修正案:
- `VideoManualService::duplicate()` の振る舞いを検証する Feature/Service テストを追加する
- 元 manual が `draft` 以外・`scenario_version > 0` の状態でも、複製先が必ず `status = Draft` かつ `scenario_version = 0` になることを明示的に検証する
- 併せて元 manual 側の `status` / `scenario_version` が変化しないことも確認する

[Warning] 共有ロック規約との整合を、設計本文でもう一段明確化した方がよいです。  
提示文では「新規 INSERT 行だから lockForUpdate の趣旨に反しない」と整理していますが、規約本文は `cuts` / `status` / `scenario_version` の書き込み経路をかなり強く縛っています。`duplicate()` は通常、manual 作成だけでなく cuts 複製も伴うため、「複製全体が単一 transaction 内で完結する」ことを設計上の前提として明文化しておかないと、後続変更で transaction 境界が崩れたときに危険です。  
修正案:
- 実装方針に「`duplicate()` は複製 manual の INSERT と cuts 複製を同一 transaction で完結させる」ことを明記する
- `ScenarioWritePathInventoryTest` の inventory コメントでも、`duplicate` 経路が“新規行生成 + 同一 tx 内反映”であることを明記する

[Warning] `scenario_version` の allowlist 既存流用は、inventory の可読性を落とす可能性があります。  
`SCENARIO_VERSION_ALLOWED` にファイル単位で既に入っているため追加不要、という整理は実装上は通りますが、「なぜこのファイルが許可されているのか」が後から見えにくくなります。今回の変更は“read 用既存許可に write 理由が追加される”形なので、deny-by-default の監査性が少し落ちます。  
修正案:
- `ScenarioWritePathInventoryTest` 側の該当コメントに、`VideoManualService.php` が `scenario_version` で複数理由の許可対象であることを追記する
- 可能なら将来課題として、file 単位 allowlist に「許可理由」を併記する形へ寄せる

[Suggestion] 不変条件をコード上でも読み取りやすくすると保守性が上がります。  
`0` の裸リテラル自体は型安全上問題ありませんが、「複製初期版」である意味は業務概念です。  
修正案:
- すぐに定数化まで広げないとしても、docblock か日本語コメントで「複製 manual は必ず Draft / version 0 から開始」と明示する

総評として、使命整合・禁止事項整合・PHPStan L10 観点は概ね良好です。主な修正点は「静的 inventory 更新だけで終わらせず、業務不変条件のテストを追加すること」と「ロック規約との関係を transaction 単位で明文化すること」です。これが入れば APPROVED 寄りです。