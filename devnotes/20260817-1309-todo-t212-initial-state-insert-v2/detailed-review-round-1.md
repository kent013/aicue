**全体判定: CHANGES_REQUESTED**

設計の方向性は AG-191 の意図にかなり合っています。特に「実スキーマ起点の母集団」「台帳との両方向一致」「登録済み列に default を足すと phantom になる」という pin の仕組みは妥当です。

ただし、実装前に詰めるべき Warning が残っています。

---

## 施策 1: 分類の型

判定: APPROVE

指摘なし。3 区分に絞る設計は明確で、`Undecided` を明示区分にする点も deny-by-default の運用と整合しています。

---

## 施策 2: 台帳の 1 行

判定: REQUEST_CHANGES

[Warning] `mb_strlen()` を使うならテスト環境で `mbstring` 前提を明記するか、既存先例と揃えるべきです。  
修正案: `RetentionTableEntry` 側にも同じ根拠長チェックを入れる方針で揃えるか、本施策では `strlen()` に寄せるかを決めてください。日本語根拠を文字数で見るなら `mb_strlen()` でよいですが、その前提を設計に明記してください。

[Suggestion] `table` / `column` の空文字検査も constructor に入れると、台帳の typo が失敗メッセージとして読みやすくなります。

---

## 施策 3: 全数台帳

判定: REQUEST_CHANGES

[Warning] 初期案の分類に疑わしい列があります。特に `subscriptions.ends_at` は生成時に決まりうる「キャンセル予定終了日 / 期限」系である可能性が高く、`InitialStateMarker` 固定は危険です。  
修正案: 実装時に Laravel Cashier 相当の意味、subscription 作成・更新経路、`ends_at` の読み取り分岐を確認し、生成時に非 NULL が合法なら `SetAtCreation` に移してください。

[Warning] `ticket_reservations.consume_expires_at` は名前上「consume の期限」であり、生成時に決まる期限列の可能性があります。`InitialStateMarker` とする根拠が弱いです。  
修正案: reserve 作成時に期限を入れる列なのか、consume 開始後だけ入る列なのかを生成点で確認し、前者なら `SetAtCreation` にしてください。

[Warning] `analysis_jobs.step` / `render_jobs.step` を nullable enum の `InitialStateMarker` とするなら、「NULL が初期状態」なのか「未開始 step が未設定」なのかを明確にする必要があります。ジョブ生成時に step 初期値を入れうる設計なら AG-191 の対象とは別です。  
修正案: ジョブ作成時の step 代入と state machine の読み取り側を確認し、NULL を分岐条件として扱っている場合だけ `InitialStateMarker` にしてください。

---

## 施策 4: 検査

判定: REQUEST_CHANGES

[Critical] `app/Models` の具象 Eloquent クラスを単純にインスタンス化する設計は、将来副作用付き constructor / boot / trait が入ると検査が壊れるだけでなく、失敗理由が分類 gate から逸れます。設計では「落ちるので沈黙しない」としていますが、これは不変条件の検査としては粗いです。  
修正案: `new ReflectionClass($fqcn)` で `isSubclassOf(Model::class)` と `isAbstract()` を確認し、`newInstanceWithoutConstructor()` で `getTable()` / `getCasts()` を読む設計に寄せてください。Laravel Model の通常利用と違うため、できないモデルが出た場合はその FQCN を明示して fail する補助検査を置くのがよいです。

[Warning] BackedEnum cast の検出仕様が不足しています。Laravel の cast は `EnumClass::class` だけでなく、nullable enum 配列やカスタム cast、`AsEnumCollection` 系、引数付き cast 文字列などがありえます。  
修正案: 本 gate が対象にする cast 形式を明文化してください。最低限、`enum_exists($cast)` が真の素直な cast のみ対象なら、保証しない範囲に「Laravel の enum collection / custom cast 経由の状態語彙は見ない」と書くべきです。

[Warning] `generation === null` / `auto_increment === false` は Schema API の返却 shape に依存します。DB や Laravel の返却でキー未定義になると PHPStan 以前に runtime notice/failure になります。  
修正案: `getColumns()` の shape を実読した先例に合わせ、キー存在を正規化する関数を用意してください。未知 shape は fail-closed で列名と raw keys を出す設計にするとよいです。

[Warning] NC-3 は「登録済み列に default が付いたら phantom」という AG-191 の核心ですが、合成入力だけでは実 Schema API の default 表現ゆれを検証できません。  
修正案: 合成入力の NC-3 は維持しつつ、`default` 値として `'now()'`, `'CURRENT_TIMESTAMP'`, `'pending'`, `"'pending'::character varying"` のような代表値を複数入れる負のコントロールにしてください。

[Warning] NI-7 の「モデルを持たない表で外した作成・更新時刻の件数 pin」はよいですが、モデルを持つ表で lifecycle 除外が広がる経路の pin がありません。  
修正案: `excludedLifecycle` 全体の exact-fit 一覧、または model-backed / model-less の両方の件数 pin を置いてください。

---

## 施策 5: AGENTS.md

判定: APPROVE

文面方針は妥当です。保証しない範囲を検査 docblock に寄せる判断も、i6 と整合しています。

---

## 施策 6: docs/architecture.md

判定: APPROVE

運用説明に留め、保証しない範囲を複写しない方針は妥当です。

---

## AG-191 適合性

REQUEST_CHANGES です。

「登録済み列に DB default を後付けすると赤くなる」構造自体は満たしています。ただし、BackedEnum cast 検出の対象形式、Schema API の default/generation shape、lifecycle 除外の pin 範囲が未確定なため、抜け道が残ります。

最小修正は次の 3 点です。

1. モデル探索を `ReflectionClass` + `newInstanceWithoutConstructor()` 前提にする  
2. enum cast の対象形式と非対象形式を明文化する  
3. lifecycle 除外の全体 pin、または model-backed/model-less 双方の pin を追加する