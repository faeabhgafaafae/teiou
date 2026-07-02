# 艇王

## デプロイ

`main` ブランチへの push をトリガーに、GitHub Actions (`.github/workflows/deploy.yml`) がロリポップサーバーへFTPデプロイします。

アップロード対象は `.php` / `.html` / `.js` / `.css` のみで、`.py` / `.yml` / `.md` / `.git` / `.github` および `auth.php`（認証情報を含むため）は除外されます。

### 必要なGitHub Secrets

リポジトリの `Settings > Secrets and variables > Actions` で以下を設定してください。

| Secret名 | 内容 |
| --- | --- |
| `FTP_HOST` | ロリポップのFTPホスト名 |
| `FTP_USER` | FTPユーザー名 |
| `FTP_PASS` | FTPパスワード |

`auth.php` はデプロイ対象から除外されるため、DB接続情報などを含むこのファイルは初回のみサーバー側に手動でアップロードしておく必要があります。
