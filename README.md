# Kanboard Agent Memory

Kanboardに、AIエージェントと進めた仕事の記憶を追加します。
作業ログ・人間の判断履歴・再開用コンテキスト・詳細履歴を保存し、標準画面とJSON-RPCから利用できます。

**v0.1.0-rc.1は試用版です。** 既存のKanboard業務で評価するためのGitHub prereleaseとして配布します。公式プラグイン一覧には未掲載です。
検証対象はKanboard **1.2.55 / SQLite / PHP 8.1以上**。別バージョン・別DBは未確認です。
**MySQL/MariaDB・PostgreSQLには未対応です。** 仕事側へ導入する前にDBの種類を確認してください。

## 公開候補の範囲

- 作業ログ・判断の閲覧、手動追記・編集・削除、判断日時、変更履歴。
- 現在コンテキスト・詳細履歴と、取得・検索・更新API。
- 重複書込みと古い版による上書きの防止、過去の記録の保持。
- Pythonクライアントとエージェント向け運用例。

標準のタスク概要、説明、エディタ、ボード、属性、メニューはそのままです。
会話の自動監視は行いません。カラム・スイムレーンは標準機能を使い、固定名や自動追加を要求しません。

## 導入

[試用版ZIPとSHA256](https://github.com/S0R4Suke/kanboard-agent-memory/releases/tag/v0.1.0-rc.1)をダウンロードします。仕事用の既存Kanboardへ追加する場合は、先に[導入・Claude連携ガイド](docs/work-integration.md)を確認してください。

既存DB・添付をバックアップし、配布ZIP内の`AgentMemory`を`plugins/AgentMemory`へ配置します。
次のアクセスで追加テーブルを作成します。Kanboard本体の変更や追加の実行時ライブラリは不要です。

```sh
unzip AgentMemory-0.1.0-rc.1.zip -d /path/to/kanboard/plugins
```

Dockerで既にKanboardを使っている場合は、ホスト側へ展開した`AgentMemory`を既存サービスの`/var/www/app/plugins/AgentMemory`へマウントします。データボリュームや既存プロジェクトを作り直す必要はありません。

ソース一式では`docker compose up -d`で起動できます。既定URLは`http://127.0.0.1:8088`です。
新規環境の初期ログインはKanboard標準の`admin` / `admin`です。使用前に変更してください。
`docker compose down`は停止、`down -v`はDB・添付を含むデータ削除になります。

## 利用

タスク詳細の標準説明の下に作業ログ・判断履歴を表示します。記録はタスクと同じ閲覧権限で共有します。
「編集」「削除」は現在表示に反映し、変更前の内容は監査履歴へ残します。秘密情報の消去機能ではありません。
AI専用のコンテキストと詳細履歴はAPIから利用します。

[エージェント連携](docs/agents.md)、[保存先の固定](docs/workspace-usage.md)、[AGENTS.md例](examples/AGENTS.md.example)、[API](docs/api.md)を参照してください。

## 取り外し・再導入

プラグインを外すと標準Kanboardだけになります。専用テーブルは残し、再導入すると記録を参照できます。
タスク削除時も記録を保持しますが、通常UI/APIでは取得できません。管理者によるバックアップ等からの回収が必要です。
[バックアップと復旧](docs/operations.md)を参照してください。

## 試用と開発

安定版に進む前に、[試用手順](docs/trial.md)に沿って複数の実作業で使い、使い勝手とセッション再開を確認します。
自動テストの成功だけで試用完了とは扱いません。
[開発手順](CONTRIBUTING.md)、[設計](docs/design.md)、[検証記録](docs/verification.md)を参照してください。

MIT License。Maintainer: [S0R4Suke](https://github.com/S0R4Suke)。通常の不具合はIssuesへ、脆弱性は[非公開の報告フォーム](https://github.com/S0R4Suke/kanboard-agent-memory/security/advisories/new)へ報告してください。トークンや業務の記録は公開Issueへ貼り付けないでください。
