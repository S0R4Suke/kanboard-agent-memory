# JSON-RPC API

エンドポイントはKanboard標準の `/jsonrpc.php`。HTTP Basicのユーザー名とユーザーAPIトークンで認証します。標準が許可するユーザーパスワード認証も利用できますが、エージェントにはユーザーAPIトークンを使用してください。追加APIは `jsonrpc` アプリケーショントークンを拒否します。

タスク検索・取得・作成・更新は標準 `searchTasks`、`getTask`、`createTask`、`updateTask` を使います。追加APIは既存タスクの標準API権限を確認します。閲覧者は読取りのみです。ユーザーAPIトークンはユーザーごとの設定画面で発行してください。

## appendAgentEntry

```json
{"jsonrpc":"2.0","id":1,"method":"appendAgentEntry","params":{"task_id":1,"kind":"work_log","data":{"title":"調査完了","body":"公式のテンプレートフックで実装できることを確認。"},"request_id":"dc84e1dd-99f6-492a-86fa-f584ed6597eb","actor_type":"ai","agent":"Codex"}}
```

| 引数 | 型・要件 |
| --- | --- |
| task_id | 正の整数。数値文字列は不可 |
| kind | `work_log` / `decision` / `history` |
| data | 下記フィールドを持つオブジェクト |
| request_id | 必須。1〜128バイト、英数字・`_ . : -`。新しい記録ごとにUUID等で発行し、再試行では同じ値を使う |
| actor_type | `human` / `ai`、既定 `ai`。自己申告の区分 |
| agent | 任意のエージェント名。200バイト以内 |

`work_log` / `history` の `data`: `body`（必須）、`title`（任意、200バイト以内）。

作業ログ・判断履歴の編集は `updateAgentEntry` を使います。`appendAgentEntry` の新規 `action=correction` は版番号の検査を迂回するため拒否します。旧バージョンで記録済みの同じ要求キーの再送は、元の応答を返します。

`decision` の `data`:

| フィールド | 内容 |
| --- | --- |
| decision | 判断内容、必須 |
| judge | 人間の判断者、必須、200バイト以内 |
| reason | 判断理由、任意 |
| options | 検討した選択肢、任意。改行を含む文字列 |
| tradeoffs | トレードオフ、任意 |
| failure_conditions | 失敗条件、任意 |
| decided_at | 実際の判断日時、任意。UTC `2026-10-25T01:00:00Z`。省略時は記録日時を使用 |
| action | `decision`（既定）/ `withdrawal` |
| supersedes_id | 同じタスク内の判断記録ID。撤回時は必須 |

本文フィールドは各65,536バイト以内、JSON全体は128 KiB以内。未知のフィールド、NUL、無効なUTF-8、空の必須項目は拒否します。返り値は記録オブジェクト。`id`、`created_at`（Unix秒、サーバー時刻）、`recorder_id` / `recorder_name`（認証ユーザー）、`actor_type`、`agent`、`data`、タスクの識別情報を含みます。記録日時や記録者IDをクライアントから指定することはできません。

## getAgentEntries

このAPIは追記元データのアーカイブを返します。編集前の原文や旧形式の訂正記録、削除したメッセージの原文も含みます。現在のタイムラインと同じ内容は `getAgentMessages`、編集・削除の監査履歴は `getAgentEntryChanges` から取得してください。

引数: `task_id`（必須）、`kind`（省略時は全種、`context`も指定可能）、`after_id`（既定0）、`limit`（1〜100、既定50）、`query`（任意、500バイト以内）。

記録IDの昇順で返します。`query` はJSON本文のリテラル部分一致。日本語検索に対応し、ASCII英字のみ大文字小文字を無視します。全文検索エンジンや複数タスク横断検索はありません。

```json
{"entries":[],"has_more":false,"next_after_id":0}
```

`has_more=true` の間、返された `next_after_id` を次の `after_id` に指定します。同時に新しい記録が追加されても、既存記録のページ境界は変わりません。

## getAgentMessages

引数は `task_id`、`kind`（空/`work_log`/`decision`）、`after_id`、`limit`、`query`。既定値・ページング形式は `getAgentEntries` と同じです。削除されていない現在のメッセージを返し、編集しても `id` と並び順は変わりません。各メッセージに `revision`、`updated_at`、`editor_name` を含みます。旧訂正記録は元メッセージIDへ統合して最新内容を返します。

## updateAgentEntry / removeAgentEntry

共通の必須引数は `task_id`、`entry_id`（メッセージID）、`expected_revision`（1以上）、`request_id`。任意の `actor_type`、`agent` は追記APIと同じです。

`updateAgentEntry` は `data` も必須で、同じ種別の本文フィールドを全文置換します。更新は同じメッセージIDへ反映され、タイムラインに行を追加しません。`removeAgentEntry` は本文を受け取らず、現在表示から削除します。

```json
{"task_id":1,"entry_id":11,"data":{"title":"調査結果","body":"更新した内容"},"expected_revision":1,"request_id":"edit-11-001"}
```

成功すると `revision` が1増えたメッセージを返します。削除時は `deleted_at` に日時（Unix秒）が入ります。古い版、削除済みメッセージへの新しい編集、別タスクのIDは拒否します。同一キー・同一内容の再送は元の応答を返し、その後の編集内容を巻き戻しません。

## getAgentEntryChanges

引数は `task_id`、`entry_id`、`after_id`（既定0）、`limit`（1〜100、既定50）。削除後も、タスクが存在して参照権限があれば取得できます。

返り値は `{changes, has_more, next_after_id}`。各変更には `operation`（edit/delete）、操作日時・記録者・エージェント情報、`before` / `after` のメッセージ全文を含みます。変更前後の保存と現在表示の更新は同じトランザクションです。旧バージョンの訂正原文は `getAgentEntries` の `message_id` で元メッセージに対応付けられます。

## getAgentContext

引数は `task_id` のみ。未作成なら `revision=0`、`context={}`、日時と記録IDはnullです。

```json
{"revision":2,"context":{"status":"実装済み","next_actions":"UI確認"},"updated_at":1792886400,"entry_id":10}
```

## updateAgentContext

必須引数: `task_id`、`context`、`expected_revision`、`request_id`。任意: `actor_type`、`agent`。

`context` は `status`（必須）、`confirmed`、`unresolved`、`next_actions`、`constraints` の文字列。各16,384バイト以内です。更新は全文置換なので、残す項目も含めて送信してください。

初回は `expected_revision=0`。成功時は1増え、`kind=context` の履歴記録を返します。履歴の `data` は `{revision, context}`。同じトランザクションで最新状態も更新します。

## エラーと再試行

| エラーコード | 対応 |
| --- | --- |
| -32602 | 引数不正。`error.data` を確認して修正 |
| 401 / 403 | 認証または権限を確認 |
| 409 | 版番号または再試行キーの競合。コンテキストを再取得・統合して新しいキーで送信。削除済みタスクのキーは再利用不可 |
| DBロック・接続切断等 | 書込み結果が不明なら、同じキー・同じ内容で再送。待機時間を延ばし、回数を制限する |

同じタスクとキーに対し、内容・記録者ID・区分・エージェント名・期待版番号が同じなら元の記録を返します。ユーザー名の後日の変更は同一性に影響しません。タスクを削除してもキーを予約し続けます。DBの直接削除、DB復元、別インスタンスをまたぐ一意性は保証しません。

Kanboardは削除済みタスクの数値IDを再利用することがあります。長期間中断した仕事やタスク削除後は、標準 `getTask` でプロジェクト・タイトルを確認してください。対応関係が不明な場合は書き込まずユーザーに確認します。
