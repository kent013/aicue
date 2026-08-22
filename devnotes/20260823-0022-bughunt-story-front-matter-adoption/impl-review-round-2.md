## 重点3点の判定

1. **W2（AC-06負例）: 対応済み**

   実カード7枚の母集団を維持し、S1の `surface` だけを変更しています。さらに正しい値へ戻した正の対照が一致するため、「6枚不足」ではなく「S1の面の付け替え」で落ちることを裏取りできています。共通規約 (c) を満たします。

2. **W4の禁止文字追加: 過度に狭い**

   `& * | > { }` の全面禁止はA5を守るために必要な範囲を超えています。独自パーサなので、これらは存在するだけでYAML構造にはなりません。

   例えば以下は自然な将来値ですが拒否されます。

   - `title: R&D`
   - `setup: [横幅 * 高さを確認する]`
   - `setup: [値が 10 > 5 であることを確認する]`
   - `setup: [JSON の {} を送る]`
   - `title: 入力 | 出力`

   アンカーなら値先頭の `&name`、参照なら値全体の `*name`、複数行スカラーなら値全体の `|` / `>`、フローマップなら値全体が `{...}` というように、構文位置を限定して拒否すべきです。

3. **W7の既存ドリフト解消: 妥当**

   T240の意味を消さず、生成物から正本 `notes-screens.md` へ移して再生成した判断は適切です。今回の生成器変更は段3のbyte一致を成立させる必要があり、このドリフトは安全に回避できる無関係差分ではありません。コミット説明に「既存ドリフトの正本化」を残せば十分です。

## 指摘

### [Warning] 1. A5対応がREADMEの制限文法より狭くなっています

対象: [story_front_matter.py](/workspace/.claude/worktrees/tasks/T245/.claude/skills/app-bug-hunt/stories/story_front_matter.py)

READMEのA4が禁止しているのは、提示された契約上 `#`、`:`、角括弧、引用符です。今回追加した `&*|>{}` はその値域に含まれておらず、読み取り器だけが文法を狭めています。

これは「READMEが文法の正本、読み取り器は従う読み手」というdocstringにも反します。位置依存の構文検出へ変更するか、意図的に文字そのものを禁止するならREADMEと乖離台帳へ契約変更として明記する必要があります。現時点では前者が妥当です。

### [Warning] 2. 新しいA1〜A5負例は、依然として「正しい理由」を機械固定していません

対象: [test_story_front_matter.py](/workspace/.claude/worktrees/tasks/T245/.claude/skills/app-bug-hunt/stories/test_story_front_matter.py)

新規負例の多くが引き続き次の形です。

```python
self.assertNotEqual([], synthetic_violations(raw=raw))
```

`synthetic_violations()` はパーサ違反に加えて `card_violations()` も合成します。そのため、狙ったパーサ分岐が壊れても別の違反でテストが緑になり得ます。

具体例として `title: |` を読み取り器が受理するよう後退しても、本文のH1が `# S1: 見本カード` のままなので、J1の見出し不一致によってテストは成功します。これはAC-06で修正したのと同型の問題です。

各パーサ負例は `parse_front_matter(...)[2]` を直接検査し、狙った違反メッセージまたは分類をassertしてください。手元でメッセージを実測しただけでは、将来の回帰検出には残りません。

### [Warning] 3. SKILL.mdを債務から外した結果、D40とstories READMEの説明が古くなっています

対象:

- [template-divergence.md](/workspace/.claude/worktrees/tasks/T245/docs/template-divergence.md)
- [stories/README.md](/workspace/.claude/worktrees/tasks/T245/.claude/skills/app-bug-hunt/stories/README.md)

D40には現在も次の趣旨が残っています。

> 契約の置き場は SKILL.md だが、同ファイルは採用時債務に在るため触らない

しかし今回 `SKILL.md` は採用時債務から削除され、実際に更新されています。Round 1で提示されたstories READMEの `not_applicable` 非採用理由にも同じ説明が残っています。

`not_applicable` の実走除外を今回追加しない判断自体は、該当カード0件なので妥当です。ただし理由は「採用時債務だから」ではなく「該当カードが0件で、発生時を再判定条件としているため」に直してください。D20がSKILLのうち目録生成記述だけを説明する、という範囲限定とも両立します。

### [Warning] 4. 必須の全体テスト結果が未確定です

`composer test` が「実行中」のため、禁止事項1および全greenでコミットする規約の完了条件をまだ確認できません。コード上の修正とは別に、最終結果の確認が必要です。

## ファイル別判定

| ファイル | 判定 |
|---|---|
| `stories/story_front_matter.py` | [Warning] YAML構造記号の全面禁止を狭める必要あり |
| `stories/test_story_front_matter.py` | [Warning] AC-06は修正済み。A1〜A5負例は対象分岐を直接assertする必要あり |
| `stories/README.md` | [Warning] SKILL.mdが採用時債務という説明を更新する必要あり |
| `coverage/test_correlate.py` | APPROVED。例外→exit 3と正常な複数値の両方向が固定された |
| `inventory/notes-screens.md` | APPROVED。T240の記述を正本へ移す判断は妥当 |
| `screens.md` | APPROVED。生成通知とS7追加だけに整理されている |
| `SKILL.md` | APPROVED。運用手順が新しい正本へ揃った |
| `scripts/README.md` | APPROVED |
| `BugHuntInventoryCheckInvariantTest.php` | APPROVED。docblockとsandbox入力が一致 |
| `StoryFrontMatterPins.php` | APPROVED。81件への更新は実測と一致 |
| `docs/template-divergence.md` | [Warning] D40の採用時債務に関する説明だけ要修正 |
| `LedgerPins.php` | APPROVED。171→166は削除5件と一致 |
| `adoption-debt.tsv` | APPROVED。D14/D20への移動と整合 |

Criticalはありませんが、文法の正本との不一致と負例の検出力が残っています。

**全体判定: CHANGES_REQUESTED**