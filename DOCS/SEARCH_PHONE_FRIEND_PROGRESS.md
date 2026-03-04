# 携帯番号検索・友達申請 進捗

## 完了
- [x] api/users.php: 携帯番号検索の分岐を追加（10桁以上で電話検索）
  - 検索拒否（exclude_from_search=1）のユーザーは除外
  - 表示名が登録されているユーザーのみ表示
  - ブロック・友達ブロック済みは除外
- [x] api/messages.php: 上パネル検索（action=search）に携帯番号ユーザー検索をマージ
  - キーワードが10桁以上の数字のとき、電話一致・検索拒否でない・表示名ありのユーザーを結果に追加
  - is_friend / is_pending を付与して表示。友達でなければ「友達申請」ボタンで送信可能（既存UI）

## 実装済みサマリ
- **api/users.php** (action=search): クエリが10桁以上の数字のとき「携帯番号検索」を実行。検索拒否でない・表示名あり・ブロック除外。友達申請用にユーザー一覧を返す。
- **api/messages.php** (action=search): 上パネル検索で同じ条件の携帯番号検索結果をユーザー結果にマージ。既存の is_friend / is_pending 付与により「友達申請」「DM」「申請中」が表示される。
- **フロント**: 既存の検索結果UI（renderSearchResultsList）で type=user かつ action=friend-request のとき openFriendRequestModal が呼ばれ、相手は承諾・保留・拒否可能。