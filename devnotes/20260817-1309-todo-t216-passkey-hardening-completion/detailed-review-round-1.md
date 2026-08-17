**全体判定: CHANGES_REQUESTED**

設計の方向性は概ね妥当ですが、施策 B と C に実装前に直すべきリスクがあります。特に正規化器の正規表現境界と、削除原子性テストの前提が危ういです。

**施策 A: APPROVE**

[Suggestion] Fortify の lock 検査を `1.37.` 前方一致に固定する判断は、退行検出として明確です。脆弱性対応で 1.38+ に上げる時に赤くなるのも意図通りです。

**施策 B: REQUEST_CHANGES**

[Critical] `PasskeyOriginCanonicalizer` の正規表現が「origin だけ」を扱うには広すぎます。`([^/?#]+?)` は userinfo、角括弧 IPv6、余分な `:` を host 側に含め得ます。結果として `https://[::1]:443` や `https://user@app.example.com:443` のような不正値から既定 port を落とし、「不正値は修復しない」という設計説明とズレます。  
修正案: canonicalizer の対象を validator と同じ DNS host 形に寄せ、少なくとも `@`、`[`、`]`、複数 colon を含む host は正規化対象外にしてください。例: `#^([a-z][a-z0-9+.\-]*)://([a-z0-9.-]+)(?::(\d{1,5}))?/?$#`。そのうえで `userinfo + :443`、IPv6 bracket + `:443`、host 欠落、複数 colon のテストを追加してください。

[Warning] 施策 B-4 の「実効値が正規形」契約検査は、通常の config 値だけを見るなら退行検出力が弱いです。`.env` / `APP_URL` が既に正規形なら、`config/fortify.php` から canonicalizer 呼び出しを外しても緑のままになり得ます。  
修正案: 契約検査ではなく Unit で、`APP_URL=https://App.Example.com:443/` または `PASSKEYS_ALLOWED_ORIGINS=https://app.example.com:443/` 相当の環境を config 再読込して、`fortify.passkeys.raw_allowed_origins` と `passkeys.allowed_origins` が正規形になることを固定してください。config 再読込が難しい場合は、config 配線テストとして `config/fortify.php` のソースに `PasskeyOriginCanonicalizer::declaredList` が出ることを固定する方が、現行案より検出目的に合います。

[Warning] 生値非露出テストで `not->toContain($rawValue)` を違反値全体だけに掛けると、部分漏洩を見逃します。例えば `https://secret.example.com` を丸ごと出さなくても `secret.example.com` が出れば運用ログへの露出としては問題です。  
修正案: origin 全体、host 部、relying party id、秘密値系の候補を個別に `not->toContain()` してください。特に相互整合エラーは origin host と RP ID の両方を隠すことを固定してください。

[Suggestion] validator のメッセージは「trailing slash 禁止」と書き続けていますが、宣言経路では受理されるため少し誤解を招きます。「production validator receives canonical origins; declare without relying on non-canonical config injection」程度に寄せると運用説明と整合します。

**施策 C: REQUEST_CHANGES**

[Critical] `PasskeyDeletionAtomicityTest` の「HTTP 削除経路では購読側の失敗で削除ごと巻き戻る」は、イベント dispatch のタイミング次第で想定が崩れます。Laravel のイベント listener が after-commit 化されている、または対象 listener が queue / afterCommit で動く場合、削除 transaction は commit 済みになり、テストの前提と異なる結果になります。設計上は「PasskeyDeleted::dispatch が transaction 内で同期 listener を実行する」ことも固定対象に含める必要があります。  
修正案: テスト名と前提を明確化し、同期 listener が transaction 内で例外を投げた場合の rollback として固定してください。あわせて、実アプリの監査 listener が同期実行か afterCommit かを契約検査する、または「同期 listener の失敗だけを巻き戻す」と文書を限定してください。

[Warning] vendor の transaction 検出が字句 4 種類だけなのは設計内で限界を明記していますが、`DB::beginTransaction()` を検出していません。自己テストの正例にも含まれていないため、Laravel で普通にあり得る書き方を見逃します。  
修正案: token に `DB::beginTransaction` / `DB::connection()->transaction` 相当を追加するか、検査目的を「代表的な transaction wrapper の存在検知」に下げて、過信しない名前にしてください。

[Warning] `StorePasskey` の非原子性固定は本タスクの主目的から外れています。登録経路は「やらない」と決めている一方で、契約検査を増やすと vendor 更新時の赤が削除施策と無関係に発生します。  
修正案: 登録経路を文書化に留めるか、追加するなら「既知の窓」として独立テスト名・失敗時の対応方針を明確にしてください。

**施策 D: APPROVE**

[Suggestion] D25 の内容は妥当です。ただし対象パスに `config/fortify.php` を含めない判断は少し弱いです。逸脱の実体が「検査位置」でも、宣言側で正規化を行う構造は設計上の一部なので、登録簿の規約が許すなら含めた方が後続レビューで追いやすいです。

**補足**

登録済みパスキー無効化の観点では、`PASSKEYS_RELYING_PARTY_ID` と `PASSKEYS_USER_HANDLE_SECRET` を正規化・trim しない判断は適切です。現時点の最大リスクはそこではなく、「allowed origin 正規化の境界が説明より広いこと」と「削除 rollback の保証範囲を同期 listener 前提に限定できていないこと」です。