## Round 2: Round 1 指摘への対応

Round 1 の指摘はすべて受け入れ、概念設計を改訂した。対応マトリクスの要点:

### [Critical] 4. 検査が語彙集合しか見ておらず visitKey / prop 名の改名を検出できない → 対応した

検査対象を 3 つに明記した。

| 検査対象 | 何が壊れるのを止めるか |
|---|---|
| (a) 通知種別の集合が PHP ⇔ TS で一致 | キー追加の片側忘れ = そのメッセージだけ無音 |
| (b) 共有 prop 名 (`flash`) が PHP ⇔ TS で一致 | prop 名改名で画面側が prop を見失う |
| (c) de-dup キー名 (`visitKey`) が PHP ⇔ TS で一致 | 全通知が丸ごと無音になる最悪ケース |

TS 側は `visitKey` を直書きのまま残さず、`FLASH_VISIT_KEY` という 1 つの定数から
`FlashPayload` のキーと `consumeFlash` の参照を導出する。両レーンの検査はこの定数値と
PHP 側の定数値を照合する。`TsUnionValues` は (a) にのみ再利用し、(b)(c) は
「リテラル定数を 1 つ抜き出す小さな抽出」を各検査に置く (抽出不能は degenerate PASS として fail)。

### [Warning] 3. Session::get() は mixed → 対応した

中継クラス内で**文字列以外は null へ正規化**し、戻り値は array shape で固定、
de-dup キーの値は必ず string とする方針を制約節に追加した。
PHPStan level 10 対策と、画面側 `string | null` 契約の保護の両方を兼ねる。

### [Warning] 4. 中継が実際に共有 props へ繋がっていることは静的検査で見えない → 対応した

実装方針に Feature テスト 1 本の追加を明記した (共有 `flash` の shape / 4 種別が session から
運ばれること / visit ごとに de-dup キーの値が変わること)。
「既存テスト更新は不要」という断定は撤回した。

### [Warning] 5. 正典未確認のまま「正典準拠」と称するリスク → 対応した

冒頭に但し書きを置き、本書を「正典準拠」ではなく
「HEAD にある無音故障を解く設計 + **正典との後追い照合を完了条件に含む**」ものとして位置づけた。
照合の最小 3 点 (中継クラスの公開 API / 2 検査の検査対象 / 画面側の型・定数名) を明記し、
照合前に「追従済み」と報告しないことを完了条件に入れた。

### [Warning] 7. FlashPayload を手書きのまま残すと二重管理 → 対応した

`FlashNotificationKind` を唯一の通知語彙とし、
`FLASH_KEYS: readonly FlashNotificationKind[]`、
`FlashPayload = Partial<Record<FlashNotificationKind, string | null>> & { [de-dup キー]?: string | null }`
として導出する方針に改めた。`addToast(flashKey, message)` の型検査は維持される。

### [Suggestion] 3. get() と UUID 発行の意味論を変えない旨の明記 → 追記した

「読み出し元 (session) / 発行時点 (share 評価時) / 毎 visit 新値、のいずれも変えない」と明記した。

---

以上の改訂で概念設計として APPROVED できるか判定してほしい。
残る [Critical] / [Warning] があれば修正提案付きで指摘すること。
