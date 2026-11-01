# Contributing

公開候補は記憶機能と標準画面への記録欄追加に範囲を限定します。
本体・標準タスク操作の再実装や、共通テーマ・エディタの変更は含めません。
PHP 8.1と互換性のある構文を使ってください。

## 検証

`php tests/store.php`、`php tests/decision_time.php`、`php tests/migration.php`、`python3 tests/workspace.py`を実行します。

別ボリュームの8091で`docker compose -p agentmemory-verification -f tests/compose.yaml up -d`を実行し、
`python3 tests/api.py`、`python3 tests/messages.py`、`python3 tests/manual.py`を実行します。
Node.js 20以上で`npm ci --ignore-scripts`、`npx playwright install chromium`を実行した後、
`node tests/public_ui.cjs`、`node tests/edit.cjs`、`python3 tests/lifecycle.py`を実行します。
テストは実利用先で実行しないでください。ライフサイクル検証は合成データの8092も一時使用します。

DB・認証情報・実際の業務記録・生成された試験fixtureをコミットしないでください。
試用版はGitHub prereleaseとして配布します。安定版化や公式一覧への掲載は、試用結果と最終ZIPを確認して人間の承認を得てから行います。
