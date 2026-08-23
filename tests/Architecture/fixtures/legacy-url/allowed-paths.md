# 誤検出してはいけない見本 (負例)

この見本は**1 件も検出されてはならない**記述だけを持つ。

- 新 URL: /organizations/acme/projects/12
- route 表の写し: organizations/{organization}/dashboard
- テンプレートリテラル: /organizations/${slug}/billing
- 山括弧の置換子: /organizations/<slug>/manage/users
- 根の下の第 2 セグメント: /organizations/acme/billing/purchase-tickets
- 正規の分岐入口: /app
- 接頭辞つき: /myapp
- 打ち消しつき: /app-old
- 接尾辞つき: /appx
- 別語: /projectsomething
- 外部サービスの絶対 URL: https://app.example.com/dashboard
